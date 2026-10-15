<?php

use AsyncAws\DynamoDb\DynamoDbClient;

App::uses('AsyncAwsComponent', 'AsyncAws.Controller/Component');
App::uses('ComponentCollection', 'Controller');
App::uses('DynamoDbSession', 'AsyncAws.Model/Datasource/Session');
App::uses('SessionHandlerAdapter', 'Model/Datasource/Session');

/**
 * Runs the DynamoDB session handler against DynamoDB Local.
 *
 * Skipped unless the `DYNAMODB_ENDPOINT` environment variable is set, e.g. `http://localhost:8000`.
 */
class DynamoDbSessionIntegrationTest extends CakeTestCase
{
    private array $sessionConfig;

    private string $table;

    private DynamoDbClient $client;

    public function setUp(): void
    {
        parent::setUp();
        $endpoint = getenv('DYNAMODB_ENDPOINT');
        if (!$endpoint) {
            $this->markTestSkipped('Set DYNAMODB_ENDPOINT to run the tests against DynamoDB Local.');
        }

        Configure::write('AsyncAws', [
            'region' => 'us-east-1',
            'endpoint' => $endpoint,
            'accessKeyId' => 'local',
            'accessKeySecret' => 'local',
        ]);
        $this->sessionConfig = (array)Configure::read('Session');
        $this->table = 'sessions_' . bin2hex(random_bytes(4));
        Configure::write('Session.timeout', 60);
        Configure::write('Session.handler', [
            'engine' => 'AsyncAws.DynamoDbSession',
            'table_name' => $this->table,
        ]);

        $this->client = (new AsyncAwsComponent(new ComponentCollection()))->createDynamoDb();
        $this->client->createTable([
            'TableName' => $this->table,
            'BillingMode' => 'PAY_PER_REQUEST',
            'AttributeDefinitions' => [['AttributeName' => 'id', 'AttributeType' => 'S']],
            'KeySchema' => [['AttributeName' => 'id', 'KeyType' => 'HASH']],
        ])->resolve();
        $this->client->tableExists(['TableName' => $this->table])->wait();
    }

    public function tearDown(): void
    {
        if (isset($this->client)) {
            $this->client->deleteTable(['TableName' => $this->table])->resolve();
            Configure::write('Session', $this->sessionConfig);
        }
        Configure::delete('AsyncAws');
        parent::tearDown();
    }

    private function openSession(array $options = []): DynamoDbSession
    {
        if ($options) {
            Configure::write('Session.handler', $options + (array)Configure::read('Session.handler'));
        }
        $session = new DynamoDbSession();
        $this->assertTrue($session->open());

        return $session;
    }

    public function testWriteReadDestroy(): void
    {
        $session = $this->openSession();
        $this->assertSame('', $session->read('abc'));
        $this->assertTrue($session->write('abc', 'Auth|a:1:{s:2:"id";i:1;}'));

        $this->assertSame('Auth|a:1:{s:2:"id";i:1;}', $this->openSession(['locking' => false])->read('abc'));

        $item = $this->client->getItem([
            'TableName' => $this->table,
            'Key' => ['id' => ['S' => session_name() . '_abc']],
            'ConsistentRead' => true,
        ])->getItem();
        $this->assertSame('Auth|a:1:{s:2:"id";i:1;}', $item['data']->getS());
        $this->assertArrayNotHasKey('lock', $item);
        $this->assertEqualsWithDelta(time() + 3600, (int)$item['expires']->getN(), 5);

        $this->assertTrue($session->destroy('abc'));
        $this->assertSame('', $this->openSession()->read('abc'));
    }

    public function testExpiredSessionIsNotRead(): void
    {
        $this->client->putItem([
            'TableName' => $this->table,
            'Item' => [
                'id' => ['S' => session_name() . '_old'],
                'data' => ['S' => 'stale'],
                'expires' => ['N' => (string)(time() - 10)],
            ],
        ])->resolve();

        $this->assertSame('', $this->openSession()->read('old'));
        $this->assertSame([], $this->client->getItem([
            'TableName' => $this->table,
            'Key' => ['id' => ['S' => session_name() . '_old']],
            'ConsistentRead' => true,
        ])->getItem());
    }

    public function testExpiredSessionIsNotRenewedWithItsData(): void
    {
        $this->client->putItem([
            'TableName' => $this->table,
            'Item' => [
                'id' => ['S' => session_name() . '_old'],
                'data' => ['S' => 'stale'],
                'expires' => ['N' => (string)(time() - 10)],
            ],
        ])->resolve();

        $session = $this->openSession();
        $this->assertSame('', $session->read('old'));
        $this->assertTrue($session->write('old', ''));

        $item = $this->client->getItem([
            'TableName' => $this->table,
            'Key' => ['id' => ['S' => session_name() . '_old']],
            'ConsistentRead' => true,
        ])->getItem();
        $this->assertArrayNotHasKey('data', $item);
        $this->assertSame('', $this->openSession()->read('old'));
    }

    public function testRegeneratedIdKeepsData(): void
    {
        $session = $this->openSession();
        $session->write('old', 'data');
        $session->read('old');

        // session_regenerate_id(true): the old session is destroyed and the same data is written under the new ID.
        $session->destroy('old');
        $session->write('new', 'data');

        $this->assertSame('data', $this->openSession(['locking' => false])->read('new'));
        $this->assertSame('', $this->openSession(['locking' => false])->read('old'));
    }

    public function testSessionRegenerateIdKeepsData(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $this->markTestSkipped('A session is already active.');
        }
        $ini = [
            'session.use_cookies' => '0',
            'session.use_only_cookies' => '0',
            'session.use_strict_mode' => '0',
            'session.cache_limiter' => '',
        ];
        $before = [];
        foreach ($ini as $key => $value) {
            $before[$key] = (string)ini_get($key);
            ini_set($key, $value);
        }
        $name = session_name();

        try {
            session_set_save_handler(new SessionHandlerAdapter(new DynamoDbSession()), false);
            session_name('DYNAMODB_TEST');
            session_id('regenerate0test0session0id00001');
            $this->assertTrue(session_start());
            $_SESSION['Auth'] = ['id' => 1];
            session_write_close();

            $this->assertTrue(session_start());
            $this->assertSame(['id' => 1], $_SESSION['Auth']);
            $this->assertTrue(session_regenerate_id(true));
            $newId = session_id();
            session_write_close();

            $this->assertNotSame('regenerate0test0session0id00001', $newId);
            $this->assertSame([], $this->client->getItem([
                'TableName' => $this->table,
                'Key' => ['id' => ['S' => 'DYNAMODB_TEST_regenerate0test0session0id00001']],
                'ConsistentRead' => true,
            ])->getItem());

            $_SESSION = [];
            session_id($newId);
            $this->assertTrue(session_start());
            $this->assertSame(['id' => 1], $_SESSION['Auth']);
            session_write_close();
        } finally {
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_abort();
            }
            $_SESSION = [];
            session_name($name);
            foreach ($before as $key => $value) {
                ini_set($key, $value);
            }
        }
    }

    public function testLockIsHeldUntilWrite(): void
    {
        $first = $this->openSession();
        $first->write('abc', 'one');
        $this->assertSame('one', $first->read('abc'));

        $second = $this->openSession(['max_lock_wait_time' => 1, 'min_lock_retry_microtime' => 50000, 'max_lock_retry_microtime' => 50000]);
        $this->assertSame('', $second->read('abc'));

        $first->write('abc', 'two');
        $this->assertSame('two', $second->read('abc'));
    }
}

<?php

use AsyncAws\DynamoDb\DynamoDbClient;

/**
 * Counts the calls of gc().
 */
class DynamoDbSessionIntegrationTestSession extends DynamoDbSession
{
    public static int $gcCalls = 0;

    public function gc($expires = null): bool
    {
        static::$gcCalls++;

        return parent::gc($expires);
    }
}

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

    private function getItem(string $id, ?string $sessionName = null): array
    {
        return $this->client->getItem([
            'TableName' => $this->table,
            'Key' => ['id' => ['S' => ($sessionName ?? session_name()) . '_' . $id]],
            'ConsistentRead' => true,
        ])->getItem();
    }

    private function putItem(string $id, string $data, int $expires, ?string $sessionName = null): void
    {
        $this->client->putItem([
            'TableName' => $this->table,
            'Item' => [
                'id' => ['S' => ($sessionName ?? session_name()) . '_' . $id],
                'data' => ['S' => $data],
                'expires' => ['N' => (string)$expires],
            ],
        ])->resolve();
    }

    /**
     * Runs `$callback` with PHP sessions that use the handler and no cookies.
     *
     * @param callable $callback Callback
     * @param array<string, string> $ini Other session settings
     * @return void
     */
    private function withPhpSession(callable $callback, array $ini = []): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $this->markTestSkipped('A session is already active.');
        }
        $ini += [
            'session.use_cookies' => '0',
            'session.use_only_cookies' => '0',
            'session.use_strict_mode' => '0',
            'session.cache_limiter' => '',
            'session.gc_divisor' => '1',
        ];
        $before = [];
        foreach (array_unique([...array_keys($ini), 'session.gc_probability']) as $key) {
            $before[$key] = (string)ini_get($key);
        }
        foreach ($ini as $key => $value) {
            ini_set($key, $value);
        }
        $name = session_name();
        DynamoDbSessionIntegrationTestSession::$gcCalls = 0;

        try {
            // Like CakeSession: the ini settings are applied first, then the handler is created.
            session_set_save_handler(new SessionHandlerAdapter(new DynamoDbSessionIntegrationTestSession()), false);
            session_name('DYNAMODB_TEST');
            $callback();
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
        // Time to Live removes the item, not the read.
        $this->assertSame('stale', $this->getItem('old')['data']->getS());
        $this->assertSame('', $this->openSession(['locking' => false])->read('old'));
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
        $this->withPhpSession(function () {
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
            $this->assertSame([], $this->getItem('regenerate0test0session0id00001', 'DYNAMODB_TEST'));

            $_SESSION = [];
            session_id($newId);
            $this->assertTrue(session_start());
            $this->assertSame(['id' => 1], $_SESSION['Auth']);
            session_write_close();
        });
    }

    public function testGcDeletesOnlyExpiredSessions(): void
    {
        for ($i = 1; $i <= 30; $i++) {
            $this->putItem('old' . $i, 'stale', time() - $i);
        }
        $this->putItem('now', 'valid', time() + 60);
        $this->putItem('later', 'valid', time() + 3600);

        $this->assertTrue($this->openSession()->gc());

        $ids = [];
        foreach ($this->client->scan(['TableName' => $this->table, 'ConsistentRead' => true])->getItems() as $item) {
            $ids[] = $item['id']->getS();
        }
        sort($ids);
        $this->assertSame([session_name() . '_later', session_name() . '_now'], $ids);
    }

    public function testPhpDoesNotCallGcWithTheDocumentedConfiguration(): void
    {
        // CakeSession applies Session.ini before it creates the handler.
        Configure::write('Session.ini', ['session.gc_probability' => 0]);
        $this->putItem('old', 'stale', time() - 10, 'DYNAMODB_TEST');

        // gc_divisor is 1, so PHP would call gc() on every start if gc_probability were 1.
        $this->withPhpSession(function () {
            session_id('gc0test0session0id0000000000001');
            $this->assertTrue(session_start());
            session_write_close();
        }, ['session.gc_probability' => '0']);

        $this->assertSame(0, DynamoDbSessionIntegrationTestSession::$gcCalls);
        $this->assertSame('stale', $this->getItem('old', 'DYNAMODB_TEST')['data']->getS());
    }

    public function testPhpDoesNotCallGcWithoutConfiguration(): void
    {
        $this->putItem('old', 'stale', time() - 10, 'DYNAMODB_TEST');

        // PHP's default gc_probability is 1; the handler sets it to 0.
        $this->withPhpSession(function () {
            session_id('gc0test0session0id0000000000001');
            $this->assertTrue(session_start());
            session_write_close();
        }, ['session.gc_probability' => '1']);

        $this->assertSame(0, DynamoDbSessionIntegrationTestSession::$gcCalls);
        $this->assertSame('stale', $this->getItem('old', 'DYNAMODB_TEST')['data']->getS());
    }

    public function testPhpCallsGcWhenEnabled(): void
    {
        Configure::write('Session.ini', ['session.gc_probability' => 1, 'session.gc_divisor' => 1]);
        $this->putItem('old', 'stale', time() - 10, 'DYNAMODB_TEST');

        $this->withPhpSession(function () {
            session_id('gc0test0session0id0000000000001');
            $this->assertTrue(session_start());
            session_write_close();
        }, ['session.gc_probability' => '1']);

        $this->assertSame(1, DynamoDbSessionIntegrationTestSession::$gcCalls);
        $this->assertSame([], $this->getItem('old', 'DYNAMODB_TEST'));
        $this->assertNotSame([], $this->getItem('gc0test0session0id0000000000001', 'DYNAMODB_TEST'));
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

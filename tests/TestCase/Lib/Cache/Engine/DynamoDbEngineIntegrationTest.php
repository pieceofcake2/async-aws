<?php

/**
 * Runs the DynamoDB cache engine against DynamoDB Local.
 *
 * Skipped unless the `DYNAMODB_ENDPOINT` environment variable is set, e.g. `http://localhost:8000`.
 */
class DynamoDbEngineIntegrationTest extends CakeTestCase
{
    private string $table;

    private DynamoDbEngine $engine;

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
        Configure::write('Cache.disable', false);

        $this->table = 'cache_' . bin2hex(random_bytes(4));
        $this->engine = $this->engine();
        $this->engine->createTable();
    }

    public function tearDown(): void
    {
        if (isset($this->engine)) {
            $this->engine->getClient()->deleteTable(['TableName' => $this->table])->resolve();
        }
        Cache::drop('dynamodb_local');
        Configure::delete('AsyncAws');
        parent::tearDown();
    }

    private function engine(array $settings = []): DynamoDbEngine
    {
        $engine = new DynamoDbEngine();
        $this->assertTrue($engine->init($settings + ['table' => $this->table, 'prefix' => 'test_', 'consistentRead' => true]));

        return $engine;
    }

    private function writeExpired(string $key, $value): void
    {
        $this->engine->getClient()->putItem([
            'TableName' => $this->table,
            'Item' => [
                'id' => ['S' => $key],
                'value' => is_int($value) ? ['N' => (string)$value] : ['B' => serialize($value)],
                'expires' => ['N' => (string)(time() - 10)],
            ],
        ])->resolve();
    }

    public function testWriteReadDelete(): void
    {
        $engine = $this->engine;

        $this->assertFalse($engine->read('test_key'));
        $this->assertTrue($engine->write('test_key', ['a' => 1, 'b' => "binary\0data"], 60));
        $this->assertSame(['a' => 1, 'b' => "binary\0data"], $engine->read('test_key'));
        $this->assertTrue($engine->write('test_false', false, 60));
        $this->assertFalse($engine->read('test_false'));
        $this->assertTrue($engine->write('test_null', null, 60));
        $this->assertNull($engine->read('test_null'));

        $this->assertTrue($engine->delete('test_key'));
        $this->assertFalse($engine->delete('test_key'));
        $this->assertFalse($engine->read('test_key'));
    }

    public function testExpiredItemsAreNotRead(): void
    {
        $engine = $this->engine;

        $this->writeExpired('test_key', 'value');
        $this->assertFalse($engine->read('test_key'));
        $this->writeExpired('test_count', 1);
        $this->assertFalse($engine->increment('test_count'));
        $this->assertTrue($engine->write('test_forever', 'value', 0));
        $this->assertSame('value', $engine->read('test_forever'));
    }

    public function testAdd(): void
    {
        $engine = $this->engine;

        $this->assertTrue($engine->add('test_key', 'first', 60));
        $this->assertFalse($engine->add('test_key', 'second', 60));
        $this->assertSame('first', $engine->read('test_key'));

        $this->writeExpired('test_expired', 'old');
        $this->assertTrue($engine->add('test_expired', 'new', 60));
        $this->assertSame('new', $engine->read('test_expired'));
    }

    public function testIncrementDecrement(): void
    {
        $engine = $this->engine;

        $this->assertFalse($engine->increment('test_count'));
        $this->assertTrue($engine->write('test_count', 5, 60));
        $this->assertSame(7, $engine->increment('test_count', 2));
        $this->assertSame(4, $engine->decrement('test_count', 3));
        $this->assertSame(4, $engine->read('test_count'));

        $engine->write('test_string', 'value', 60);
        $this->assertFalse($engine->increment('test_string'));
    }

    public function testClear(): void
    {
        $engine = $this->engine;
        $other = $this->engine(['prefix' => 'other_']);
        for ($i = 0; $i < 30; $i++) {
            if ($i < 5) {
                $this->writeExpired('test_' . $i, $i);
            } else {
                $engine->write('test_' . $i, $i, 60);
            }
        }
        $other->write('other_key', 'value', 60);

        $this->assertTrue($engine->clear(true));
        $this->assertFalse($engine->read('test_0'));
        $this->assertSame(5, $engine->read('test_5'));

        $this->assertTrue($engine->clear(false));
        $this->assertFalse($engine->read('test_29'));
        $this->assertSame('value', $other->read('other_key'));
    }

    public function testGroups(): void
    {
        Cache::config('dynamodb_local', [
            'engine' => 'AsyncAws.DynamoDb',
            'table' => $this->table,
            'prefix' => 'test_',
            'groups' => ['posts'],
            'consistentRead' => true,
        ]);

        $this->assertTrue(Cache::write('key', 'value', 'dynamodb_local'));
        $this->assertSame('value', Cache::read('key', 'dynamodb_local'));

        $this->assertTrue(Cache::clearGroup('posts', 'dynamodb_local'));
        $this->assertFalse(Cache::read('key', 'dynamodb_local'));
    }
}

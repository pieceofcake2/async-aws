<?php

use AsyncAws\Core\Credentials\NullProvider;
use AsyncAws\DynamoDb\DynamoDbClient;

/**
 * Collects the log messages written during a test.
 */
class DynamoDbEngineTestLog implements CakeLogInterface
{
    public static array $messages = [];

    public function __construct($config = [])
    {
    }

    public function write($type, $message): void
    {
        static::$messages[] = $message;
    }
}

class DynamoDbEngineTest extends CakeTestCase
{
    use MockAsyncAwsTrait;

    private array $disabledLogs = [];

    public function setUp(): void
    {
        parent::setUp();
        $this->mockAsyncAws();
        Configure::write('Cache.disable', false);

        foreach (CakeLog::configured() as $stream) {
            if (CakeLog::enabled($stream)) {
                CakeLog::disable($stream);
                $this->disabledLogs[] = $stream;
            }
        }
        DynamoDbEngineTestLog::$messages = [];
        CakeLog::config('dynamodb_engine_test', ['engine' => 'DynamoDbEngineTestLog']);
    }

    public function tearDown(): void
    {
        Cache::drop('dynamodb');
        CakeLog::drop('dynamodb_engine_test');
        foreach ($this->disabledLogs as $stream) {
            CakeLog::enable($stream);
        }
        Configure::delete('AsyncAws');
        parent::tearDown();
    }

    private function config(array $settings = []): DynamoDbEngine
    {
        Cache::config('dynamodb', $settings + [
            'engine' => 'AsyncAws.DynamoDb',
            'table' => 'cache',
            'prefix' => 'test_',
            'duration' => 3600,
        ]);
        $engine = Cache::engine('dynamodb');
        $this->assertInstanceOf(DynamoDbEngine::class, $engine);

        return $engine;
    }

    private function engine(array $settings = []): DynamoDbEngine
    {
        $engine = new DynamoDbEngine();
        $this->assertTrue($engine->init($settings + ['table' => 'cache', 'prefix' => 'test_']));

        return $engine;
    }

    private function item(string $key, array $value, ?int $expires = null): array
    {
        $item = ['id' => ['S' => $key], 'value' => $value];
        if ($expires !== null) {
            $item['expires'] = ['N' => (string)$expires];
        }

        return ['Item' => $item];
    }

    /**
     * @return array<string>
     */
    private function operations(): array
    {
        return array_map(fn(array $request) => $this->operation($request), $this->requests);
    }

    public function testInitWithoutTable(): void
    {
        $engine = new DynamoDbEngine();

        $this->assertFalse($engine->init([]));
    }

    public function testConfigWithoutTableThrows(): void
    {
        $this->expectException(CacheException::class);
        Cache::config('dynamodb', ['engine' => 'AsyncAws.DynamoDb']);
    }

    public function testDefaultSettings(): void
    {
        $settings = $this->engine()->settings();

        $this->assertSame('AsyncAws.DynamoDb', $settings['engine']);
        $this->assertSame('id', $settings['keyAttribute']);
        $this->assertSame('value', $settings['valueAttribute']);
        $this->assertSame('expires', $settings['expiresAttribute']);
        $this->assertFalse($settings['consistentRead']);
        $this->assertSame(0, $settings['probability']);
    }

    public function testClientUsesAsyncAwsConfiguration(): void
    {
        $this->queueJson([]);

        $client = $this->engine()->getClient();
        $client->listTables()->resolve();

        $this->assertInstanceOf(DynamoDbClient::class, $client);
        $this->assertSame('https://dynamodb.ap-northeast-1.amazonaws.com/', $this->lastRequest()['url']);
    }

    public function testClientConfigOverridesAsyncAwsConfiguration(): void
    {
        $this->queueJson([]);

        $engine = $this->engine([
            'clientConfig' => ['region' => 'us-west-2', 'endpoint' => 'http://localhost:8000'],
        ]);
        $this->assertFalse($engine->read('test_key'));

        $this->assertSame('http://localhost:8000/', $this->lastRequest()['url']);
        $this->assertStringContainsString('/us-west-2/dynamodb/', $this->lastRequest()['headers']['authorization']);
    }

    public function testInjectedClient(): void
    {
        $client = new DynamoDbClient(['region' => 'eu-west-1'], new NullProvider(), $this->mockHttpClient());
        $engine = $this->engine(['client' => $client]);
        $this->queueJson([]);

        $this->assertSame($client, $engine->getClient());
        $this->assertFalse($engine->read('key'));
        $this->assertSame('GetItem', $this->operation());
        $this->assertSame('https://dynamodb.eu-west-1.amazonaws.com/', $this->lastRequest()['url']);
    }

    public function testWriteString(): void
    {
        $this->config();
        $this->queueJson([]);

        $this->assertTrue(Cache::write('Some.Key', 'value', 'dynamodb'));

        $this->assertSame('PutItem', $this->operation());
        $payload = $this->payload();
        $this->assertSame('cache', $payload['TableName']);
        $this->assertSame(['S' => 'test_some_key'], $payload['Item']['id']);
        $this->assertSame(['B' => base64_encode(serialize('value'))], $payload['Item']['value']);
        $this->assertEqualsWithDelta(time() + 3600, (int)$payload['Item']['expires']['N'], 5);
        $this->assertArrayNotHasKey('ConditionExpression', $payload);
    }

    public function testWriteInteger(): void
    {
        $this->queueJson([]);

        $this->assertTrue($this->engine()->write('test_count', 5, 60));

        $this->assertSame(['N' => '5'], $this->payload()['Item']['value']);
    }

    public function testWriteWithoutExpiration(): void
    {
        $this->queueJson([]);

        $this->assertTrue($this->engine()->write('test_key', 'value', 0));

        $this->assertArrayNotHasKey('expires', $this->payload()['Item']);
    }

    public function testWriteCustomAttributeNames(): void
    {
        $this->queueJson([]);
        $engine = $this->engine(['keyAttribute' => 'pk', 'valueAttribute' => 'data', 'expiresAttribute' => 'ttl']);

        $this->assertTrue($engine->write('test_key', ['a' => 1], 60));

        $item = $this->payload()['Item'];
        $this->assertSame(['pk', 'data', 'ttl'], array_keys($item));
        $this->assertSame(serialize(['a' => 1]), base64_decode($item['data']['B']));
    }

    public function testWriteTooLargeItem(): void
    {
        $engine = $this->engine();

        $this->assertFalse($engine->write('test_large', str_repeat('x', DynamoDbEngine::MAX_ITEM_SIZE), 60));
        $this->assertSame([], $this->requests);
        $this->assertCount(1, DynamoDbEngineTestLog::$messages);
        $this->assertStringContainsString('larger than the DynamoDB item size limit', DynamoDbEngineTestLog::$messages[0]);
    }

    public function testWriteFailure(): void
    {
        $this->queueError('ValidationException');

        $this->assertFalse($this->engine()->write('test_key', 'value', 60));
        $this->assertCount(1, DynamoDbEngineTestLog::$messages);
        $this->assertStringContainsString('DynamoDB cache write of "test_key" in table "cache" failed: ValidationException', DynamoDbEngineTestLog::$messages[0]);
    }

    public function testRead(): void
    {
        $this->config(['consistentRead' => true]);
        $this->queueJson($this->item('test_key', ['B' => base64_encode(serialize(['a' => 1]))], time() + 60));

        $this->assertSame(['a' => 1], Cache::read('key', 'dynamodb'));

        $this->assertSame('GetItem', $this->operation());
        $payload = $this->payload();
        $this->assertSame(['id' => ['S' => 'test_key']], $payload['Key']);
        $this->assertTrue($payload['ConsistentRead']);
    }

    public function testReadInteger(): void
    {
        $this->queueJson($this->item('test_count', ['N' => '42']));

        $this->assertSame(42, $this->engine()->read('test_count'));
    }

    public function testReadMissing(): void
    {
        $this->queueJson([]);

        $this->assertFalse($this->engine()->read('test_key'));
        $this->assertFalse($this->payload()['ConsistentRead']);
    }

    public function testReadExpired(): void
    {
        $this->queueJson($this->item('test_key', ['B' => base64_encode(serialize('value'))], time() - 1));

        $this->assertFalse($this->engine()->read('test_key'));
    }

    public function testReadFailure(): void
    {
        $this->queueError('ResourceNotFoundException');

        $this->assertFalse($this->engine()->read('test_key'));
        $this->assertCount(1, DynamoDbEngineTestLog::$messages);
    }

    public function testAdd(): void
    {
        $this->config();
        $this->queueJson([]);

        $this->assertTrue(Cache::add('key', 'value', 'dynamodb'));

        $this->assertSame('PutItem', $this->operation());
        $payload = $this->payload();
        $this->assertSame('attribute_not_exists(#k) OR #e <= :now', $payload['ConditionExpression']);
        $this->assertSame(['#k' => 'id', '#e' => 'expires'], $payload['ExpressionAttributeNames']);
    }

    public function testAddExisting(): void
    {
        $this->queueError('ConditionalCheckFailedException');

        $this->assertFalse($this->engine()->add('test_key', 'value', 60));
        $this->assertSame([], DynamoDbEngineTestLog::$messages);
    }

    public function testAddFailure(): void
    {
        $this->queueError('ValidationException');

        $this->assertFalse($this->engine()->add('test_key', 'value', 60));
        $this->assertCount(1, DynamoDbEngineTestLog::$messages);
    }

    public function testIncrement(): void
    {
        $this->config();
        $this->queueJson(['Attributes' => ['value' => ['N' => '3']]]);

        $this->assertSame(3, Cache::increment('count', 2, 'dynamodb'));

        $this->assertSame('UpdateItem', $this->operation());
        $payload = $this->payload();
        $this->assertSame('ADD #v :offset', $payload['UpdateExpression']);
        $this->assertSame('attribute_exists(#k) AND (attribute_not_exists(#e) OR #e > :now)', $payload['ConditionExpression']);
        $this->assertSame(['N' => '2'], $payload['ExpressionAttributeValues'][':offset']);
        $this->assertSame('UPDATED_NEW', $payload['ReturnValues']);
    }

    public function testDecrement(): void
    {
        $this->queueJson(['Attributes' => ['value' => ['N' => '-1']]]);

        $this->assertSame(-1, $this->engine()->decrement('test_count', 3));
        $this->assertSame(['N' => '-3'], $this->payload()['ExpressionAttributeValues'][':offset']);
    }

    public function testIncrementMissing(): void
    {
        $this->queueError('ConditionalCheckFailedException');

        $this->assertFalse($this->engine()->increment('test_count'));
        $this->assertSame([], DynamoDbEngineTestLog::$messages);
    }

    public function testIncrementNonNumeric(): void
    {
        $this->queueError('ValidationException');

        $this->assertFalse($this->engine()->increment('test_key'));
        $this->assertCount(1, DynamoDbEngineTestLog::$messages);
    }

    public function testDelete(): void
    {
        $this->config();
        $this->queueJson(['Attributes' => ['id' => ['S' => 'test_key']]]);

        $this->assertTrue(Cache::delete('key', 'dynamodb'));

        $this->assertSame('DeleteItem', $this->operation());
        $payload = $this->payload();
        $this->assertSame(['id' => ['S' => 'test_key']], $payload['Key']);
        $this->assertSame('ALL_OLD', $payload['ReturnValues']);
    }

    public function testDeleteMissing(): void
    {
        $this->queueJson([]);

        $this->assertFalse($this->engine()->delete('test_key'));
    }

    public function testDeleteFailure(): void
    {
        $this->queueError('ResourceNotFoundException');

        $this->assertFalse($this->engine()->delete('test_key'));
        $this->assertCount(1, DynamoDbEngineTestLog::$messages);
    }

    public function testClear(): void
    {
        $this->config();
        $keys = [];
        for ($i = 0; $i < 30; $i++) {
            $keys[] = ['id' => ['S' => 'test_' . $i]];
        }
        $this->queueJson(
            ['Items' => array_slice($keys, 0, 20), 'LastEvaluatedKey' => ['id' => ['S' => 'test_19']]],
            ['Items' => array_slice($keys, 20)],
            [],
            [],
        );

        $this->assertTrue(Cache::clear(false, 'dynamodb'));

        $this->assertSame(['Scan', 'Scan', 'BatchWriteItem', 'BatchWriteItem'], $this->operations());
        $scan = $this->payload($this->requests[0]);
        $this->assertSame('begins_with(#k, :prefix)', $scan['FilterExpression']);
        $this->assertSame([':prefix' => ['S' => 'test_']], $scan['ExpressionAttributeValues']);
        $this->assertSame('#k', $scan['ProjectionExpression']);
        $this->assertSame(['id' => ['S' => 'test_19']], $this->payload($this->requests[1])['ExclusiveStartKey']);
        $batch = $this->payload($this->requests[2])['RequestItems']['cache'];
        $this->assertCount(25, $batch);
        $this->assertSame(['DeleteRequest' => ['Key' => ['id' => ['S' => 'test_0']]]], $batch[0]);
        $this->assertCount(5, $this->payload($this->requests[3])['RequestItems']['cache']);
    }

    public function testClearWithCheckDeletesExpiredItemsOnly(): void
    {
        $this->queueJson(['Items' => []]);

        $this->assertTrue($this->engine()->clear(true));

        $this->assertSame('Scan', $this->operation());
        $payload = $this->payload();
        $this->assertSame('begins_with(#k, :prefix) AND #e <= :now', $payload['FilterExpression']);
        $this->assertSame(['#k' => 'id', '#e' => 'expires'], $payload['ExpressionAttributeNames']);
        $this->assertEqualsWithDelta(time(), (int)$payload['ExpressionAttributeValues'][':now']['N'], 5);
    }

    public function testClearWithoutPrefixScansWholeTable(): void
    {
        $this->queueJson(['Items' => []]);

        $this->assertTrue($this->engine(['prefix' => ''])->clear(false));

        $this->assertArrayNotHasKey('FilterExpression', $this->payload());
    }

    public function testClearRetriesUnprocessedItems(): void
    {
        $key = ['id' => ['S' => 'test_a']];
        $this->queueJson(
            ['Items' => [$key]],
            ['UnprocessedItems' => ['cache' => [['DeleteRequest' => ['Key' => $key]]]]],
            ['UnprocessedItems' => []],
        );

        $this->assertTrue($this->engine()->clear(false));
        $this->assertSame(['Scan', 'BatchWriteItem', 'BatchWriteItem'], $this->operations());
        $this->assertSame([['DeleteRequest' => ['Key' => $key]]], $this->payload()['RequestItems']['cache']);
    }

    public function testClearFailsWhenItemsStayUnprocessed(): void
    {
        $key = ['id' => ['S' => 'test_a']];
        $unprocessed = ['UnprocessedItems' => ['cache' => [['DeleteRequest' => ['Key' => $key]]]]];
        $this->queueJson(['Items' => [$key]], $unprocessed, $unprocessed, $unprocessed, $unprocessed, $unprocessed);

        $this->assertFalse($this->engine()->clear(false));
        $this->assertStringContainsString('could not delete 1 items', DynamoDbEngineTestLog::$messages[0]);
    }

    public function testClearFailure(): void
    {
        $this->queueError('ResourceNotFoundException');

        $this->assertFalse($this->engine()->clear(false));
        $this->assertCount(1, DynamoDbEngineTestLog::$messages);
    }

    public function testGcDoesNothing(): void
    {
        $this->engine()->gc();

        $this->assertSame([], $this->requests);
    }

    public function testGroups(): void
    {
        $engine = $this->engine(['groups' => ['posts', 'comments']]);
        $this->queueJson(
            $this->item('test_comments', ['N' => '3']),
            [],
            ['Attributes' => ['value' => ['N' => '1']]],
        );

        $this->assertSame(['comments3', 'posts1'], $engine->groups());

        $this->assertSame('UpdateItem', $this->operation());
        $payload = $this->payload();
        $this->assertSame(['id' => ['S' => 'test_posts']], $payload['Key']);
        $this->assertSame('SET #v = if_not_exists(#v, :one) REMOVE #e', $payload['UpdateExpression']);
    }

    public function testGroupsAreUsedInKeys(): void
    {
        $this->config(['groups' => ['posts']]);
        $this->queueJson($this->item('test_posts', ['N' => '2']), []);

        $this->assertTrue(Cache::write('key', 'value', 'dynamodb'));

        $this->assertSame(['S' => 'test_' . md5('posts2') . 'key'], $this->payload()['Item']['id']);
    }

    public function testClearGroup(): void
    {
        $this->config(['groups' => ['posts']]);
        $this->queueJson([]);

        $this->assertTrue(Cache::clearGroup('posts', 'dynamodb'));

        $this->assertSame('UpdateItem', $this->operation());
        $payload = $this->payload();
        $this->assertSame(['id' => ['S' => 'test_posts']], $payload['Key']);
        $this->assertSame('SET #v = if_not_exists(#v, :one) + :one REMOVE #e', $payload['UpdateExpression']);
    }

    public function testClearGroupFailure(): void
    {
        $this->queueError('ResourceNotFoundException');

        $this->assertFalse($this->engine()->clearGroup('posts'));
        $this->assertCount(1, DynamoDbEngineTestLog::$messages);
    }

    public function testCreateTable(): void
    {
        $engine = $this->engine(['keyAttribute' => 'pk', 'expiresAttribute' => 'ttl']);
        $this->queueJson(
            [],
            ['Table' => ['TableStatus' => 'ACTIVE']],
            [],
        );

        $engine->createTable(['Tags' => [['Key' => 'app', 'Value' => 'test']]]);

        $this->assertSame(['CreateTable', 'DescribeTable', 'UpdateTimeToLive'], $this->operations());
        $create = $this->payload($this->requests[0]);
        $this->assertSame('cache', $create['TableName']);
        $this->assertSame([['AttributeName' => 'pk', 'AttributeType' => 'S']], $create['AttributeDefinitions']);
        $this->assertSame([['AttributeName' => 'pk', 'KeyType' => 'HASH']], $create['KeySchema']);
        $this->assertSame('PAY_PER_REQUEST', $create['BillingMode']);
        $this->assertSame([['Key' => 'app', 'Value' => 'test']], $create['Tags']);
        $this->assertSame(['Enabled' => true, 'AttributeName' => 'ttl'], $this->payload()['TimeToLiveSpecification']);
    }
}

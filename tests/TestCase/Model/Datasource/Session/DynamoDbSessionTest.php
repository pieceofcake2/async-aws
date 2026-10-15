<?php

use AsyncAws\Core\Credentials\NullProvider;
use AsyncAws\DynamoDb\DynamoDbClient;

App::uses('CakeLog', 'Log');
App::uses('CakeLogInterface', 'Log');
App::uses('DynamoDbSession', 'AsyncAws.Model/Datasource/Session');

/**
 * Collects the log messages written during a test.
 */
class DynamoDbSessionTestLog implements CakeLogInterface
{
    public static array $messages = [];

    public function __construct($config = [])
    {
    }

    public function write($type, $message): void
    {
        static::$messages[] = $type . ': ' . $message;
    }
}

class DynamoDbSessionTest extends CakeTestCase
{
    use MockAsyncAwsTrait;

    private array $sessionConfig;

    private array $disabledLogs = [];

    public function setUp(): void
    {
        parent::setUp();
        $this->sessionConfig = (array)Configure::read('Session');
        $this->mockAsyncAws();
        Configure::write('Session.handler', ['engine' => 'AsyncAws.DynamoDbSession', 'table_name' => 'sessions']);

        foreach (CakeLog::configured() as $stream) {
            if (CakeLog::enabled($stream)) {
                CakeLog::disable($stream);
                $this->disabledLogs[] = $stream;
            }
        }
        DynamoDbSessionTestLog::$messages = [];
        CakeLog::config('dynamodb_session_test', ['engine' => 'DynamoDbSessionTestLog', 'scopes' => ['session']]);
    }

    public function tearDown(): void
    {
        CakeLog::drop('dynamodb_session_test');
        foreach ($this->disabledLogs as $stream) {
            CakeLog::enable($stream);
        }
        Configure::delete('AsyncAws');
        Configure::write('Session', $this->sessionConfig);
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

    private function key(string $id): array
    {
        return ['id' => ['S' => session_name() . '_' . $id]];
    }

    private function item(string $data, int $expires): array
    {
        return ['data' => ['S' => $data], 'expires' => ['N' => (string)$expires]];
    }

    /**
     * @return array<string> Messages of the E_USER_WARNING errors triggered by `$callback`.
     */
    private function captureWarnings(callable $callback): array
    {
        $warnings = [];
        set_error_handler(function (int $errno, string $message) use (&$warnings) {
            $warnings[] = $message;

            return true;
        }, E_USER_WARNING);
        try {
            $callback();
        } finally {
            restore_error_handler();
        }

        return $warnings;
    }

    public function testConstructWithoutTableName(): void
    {
        Configure::delete('Session.handler.table_name');

        $this->expectException(CakeException::class);
        $this->expectExceptionMessage('DynamoDB session handler "table_name" is not configured.');
        new DynamoDbSession();
    }

    public function testConstructDoesNotReplaceSaveHandler(): void
    {
        $before = ini_get('session.save_handler');
        new DynamoDbSession();

        $this->assertSame($before, ini_get('session.save_handler'));
    }

    public function testConstructTurnsOffPhpGarbageCollection(): void
    {
        $before = ini_get('session.gc_probability');
        ini_set('session.gc_probability', '1');
        try {
            new DynamoDbSession();

            $this->assertSame('0', ini_get('session.gc_probability'));
        } finally {
            ini_set('session.gc_probability', (string)$before);
        }
    }

    public function testClientUsesAsyncAwsConfiguration(): void
    {
        $this->queueJson(['Attributes' => []]);

        $this->openSession()->read('abc');

        $this->assertSame('https://dynamodb.ap-northeast-1.amazonaws.com/', $this->lastRequest()['url']);
    }

    public function testInjectedClient(): void
    {
        $client = new DynamoDbClient(['region' => 'eu-west-1'], new NullProvider(), $this->mockHttpClient());
        $session = new DynamoDbSession($client);
        $session->open();

        $this->assertSame($client, $session->getClient());
        $session->read('abc');
        $this->assertSame('https://dynamodb.eu-west-1.amazonaws.com/', $this->lastRequest()['url']);
    }

    public function testReadLocksTheSession(): void
    {
        $this->queueJson(['Attributes' => $this->key('abc') + $this->item('serialized', time() + 60) + ['lock' => ['N' => '1']]]);

        $this->assertSame('serialized', $this->openSession()->read('abc'));

        $this->assertSame('UpdateItem', $this->operation());
        $payload = $this->payload();
        $this->assertSame('sessions', $payload['TableName']);
        $this->assertSame($this->key('abc'), $payload['Key']);
        $this->assertSame(['lock' => ['Exists' => false]], $payload['Expected']);
        $this->assertSame(['lock' => ['Value' => ['N' => '1'], 'Action' => 'PUT']], $payload['AttributeUpdates']);
        $this->assertSame('ALL_NEW', $payload['ReturnValues']);
    }

    public function testReadWaitsForTheLock(): void
    {
        $this->queueError('ConditionalCheckFailedException');
        $this->queueJson(['Attributes' => $this->item('serialized', time() + 60)]);

        $session = $this->openSession(['min_lock_retry_microtime' => 1000, 'max_lock_retry_microtime' => 1000]);

        $this->assertSame('serialized', $session->read('abc'));
        $this->assertCount(2, $this->requests);
    }

    public function testReadGivesUpWaitingForTheLock(): void
    {
        $this->queueError('ConditionalCheckFailedException');

        $session = $this->openSession(['max_lock_wait_time' => 0]);

        $this->assertSame('', $session->read('abc'));
        $this->assertCount(1, $this->requests);
    }

    public function testReadWithoutLocking(): void
    {
        $this->queueJson(['Item' => $this->item('serialized', time() + 60)]);

        $this->assertSame('serialized', $this->openSession(['locking' => false])->read('abc'));

        $this->assertSame('GetItem', $this->operation());
        $payload = $this->payload();
        $this->assertSame($this->key('abc'), $payload['Key']);
        $this->assertTrue($payload['ConsistentRead']);
    }

    public function testReadWithoutConsistentRead(): void
    {
        $this->queueJson([]);

        $this->assertSame('', $this->openSession(['locking' => false, 'consistent_read' => false])->read('abc'));

        $this->assertFalse($this->payload()['ConsistentRead']);
    }

    public function testReadMissing(): void
    {
        $this->queueJson(['Attributes' => $this->key('abc') + ['lock' => ['N' => '1']]]);

        $this->assertSame('', $this->openSession()->read('abc'));
    }

    public function testReadExpired(): void
    {
        $expires = time() - 60;
        $this->queueJson(['Attributes' => $this->item('serialized', $expires)], []);

        $this->assertSame('', $this->openSession()->read('abc'));
        $this->assertSame('DeleteItem', $this->operation());
        $payload = $this->payload();
        $this->assertSame($this->key('abc'), $payload['Key']);
        $this->assertSame('#expires = :expires', $payload['ConditionExpression']);
        $this->assertSame(['#expires' => 'expires'], $payload['ExpressionAttributeNames']);
        $this->assertSame([':expires' => ['N' => (string)$expires]], $payload['ExpressionAttributeValues']);
        $this->assertSame([], DynamoDbSessionTestLog::$messages);
    }

    public function testReadExpiresWhenTheExpirationTimeIsReached(): void
    {
        $this->queueJson(['Attributes' => $this->item('serialized', time())], []);

        $this->assertSame('', $this->openSession()->read('abc'));
        $this->assertSame('DeleteItem', $this->operation());
    }

    public function testReadExpiredWithoutData(): void
    {
        $this->queueJson(['Attributes' => ['expires' => ['N' => (string)(time() - 60)]]], []);

        $this->assertSame('', $this->openSession()->read('abc'));
        $this->assertSame('DeleteItem', $this->operation());
    }

    public function testReadExpiredDeleteFailureIsLogged(): void
    {
        $this->queueJson(['Attributes' => $this->item('serialized', time() - 60)]);
        $this->queueError('AccessDeniedException');
        $session = $this->openSession();

        $warnings = $this->captureWarnings(fn() => $this->assertSame('', $session->read('abc')));

        $this->assertSame([], $warnings);
        $this->assertCount(1, DynamoDbSessionTestLog::$messages);
        $this->assertStringContainsString('warning: DynamoDB session could not delete expired session "' . session_name() . '_abc" from table "sessions"', DynamoDbSessionTestLog::$messages[0]);
        $this->assertStringContainsString('AccessDeniedException message', DynamoDbSessionTestLog::$messages[0]);
    }

    public function testReadExpiredRenewedByAnotherRequestIsNotLogged(): void
    {
        $this->queueJson(['Attributes' => $this->item('serialized', time() - 60)]);
        $this->queueError('ConditionalCheckFailedException');

        $this->assertSame('', $this->openSession()->read('abc'));
        $this->assertSame([], DynamoDbSessionTestLog::$messages);
    }

    public function testWriteAfterExpiredReadReplacesTheData(): void
    {
        $this->queueJson(['Attributes' => $this->item('serialized', time() - 60)]);
        $this->queueError('AccessDeniedException');
        $this->queueJson([]);
        $session = $this->openSession();
        $this->assertSame('', $session->read('abc'));

        // PHP writes the empty session; the old data must not be kept with a new expiration time.
        $this->assertTrue($session->write('abc', ''));

        $payload = $this->payload();
        $this->assertSame('UpdateItem', $this->operation());
        $this->assertSame(['Action' => 'DELETE'], $payload['AttributeUpdates']['data']);
        $this->assertArrayHasKey('expires', $payload['AttributeUpdates']);
    }

    public function testCloseAfterExpiredReadReplacesTheData(): void
    {
        $this->queueJson(['Attributes' => $this->item('serialized', time() - 60)], [], []);
        $session = $this->openSession();
        $this->assertSame('', $session->read((string)session_id()));

        $this->assertTrue($session->close());

        $this->assertSame('UpdateItem', $this->operation());
        $this->assertSame(['Action' => 'DELETE'], $this->payload()['AttributeUpdates']['data']);
    }

    public function testReadFailure(): void
    {
        $this->queueError('ResourceNotFoundException');

        $this->assertSame('', $this->openSession()->read('abc'));
    }

    public function testWrite(): void
    {
        $this->queueJson([]);

        $this->assertTrue($this->openSession()->write('abc', 'serialized'));

        $this->assertSame('UpdateItem', $this->operation());
        $payload = $this->payload();
        $this->assertSame('sessions', $payload['TableName']);
        $this->assertSame($this->key('abc'), $payload['Key']);
        $this->assertSame(['Value' => ['S' => 'serialized'], 'Action' => 'PUT'], $payload['AttributeUpdates']['data']);
        $this->assertSame(['Action' => 'DELETE'], $payload['AttributeUpdates']['lock']);
    }

    public function testWriteSetsExpiresFromSessionTimeout(): void
    {
        Configure::write('Session.timeout', 30);
        $this->queueJson([]);

        $this->assertTrue($this->openSession()->write('abc', 'serialized'));

        $expires = (int)$this->payload()['AttributeUpdates']['expires']['Value']['N'];
        $this->assertEqualsWithDelta(time() + 1800, $expires, 5);
    }

    public function testWriteSetsExpiresFromGcMaxlifetimeWithoutSessionTimeout(): void
    {
        Configure::delete('Session.timeout');
        $before = ini_get('session.gc_maxlifetime');
        ini_set('session.gc_maxlifetime', '600');
        try {
            $session = $this->openSession();
        } finally {
            ini_set('session.gc_maxlifetime', (string)$before);
        }
        $this->queueJson([]);

        $this->assertTrue($session->write('abc', 'serialized'));

        $expires = (int)$this->payload()['AttributeUpdates']['expires']['Value']['N'];
        $this->assertEqualsWithDelta(time() + 600, $expires, 5);
    }

    public function testWriteWithSessionLifetimeAndAttributeNames(): void
    {
        $this->queueJson([]);
        Configure::write('Session.timeout', 30);
        $session = $this->openSession([
            'session_lifetime' => 259200,
            'hash_key' => 'pk',
            'data_attribute' => 'payload',
            'session_lifetime_attribute' => 'ttl',
        ]);

        $session->write('abc', 'serialized');

        $payload = $this->payload();
        $this->assertSame(['pk' => ['S' => session_name() . '_abc']], $payload['Key']);
        $this->assertSame('serialized', $payload['AttributeUpdates']['payload']['Value']['S']);
        $this->assertEqualsWithDelta(time() + 259200, (int)$payload['AttributeUpdates']['ttl']['Value']['N'], 5);
    }

    public function testWriteUnchangedDataOnlyUpdatesExpiration(): void
    {
        $this->queueJson(['Attributes' => $this->item('serialized', time() + 60)], []);
        $session = $this->openSession();
        $session->read('abc');

        $this->assertTrue($session->write('abc', 'serialized'));

        $this->assertSame(['expires', 'lock'], array_keys($this->payload()['AttributeUpdates']));
    }

    public function testWriteUnchangedDataWithNewIdWritesData(): void
    {
        // session_regenerate_id() writes the data that was read under the new ID.
        $this->queueJson(['Attributes' => $this->item('serialized', time() + 60)], []);
        $session = $this->openSession();
        $session->read('old');

        $this->assertTrue($session->write('new', 'serialized'));

        $payload = $this->payload();
        $this->assertSame($this->key('new'), $payload['Key']);
        $this->assertSame(['Value' => ['S' => 'serialized'], 'Action' => 'PUT'], $payload['AttributeUpdates']['data']);
    }

    public function testWriteEmptyDataDeletesData(): void
    {
        $this->queueJson(['Attributes' => $this->item('serialized', time() + 60)], []);
        $session = $this->openSession();
        $session->read('abc');

        $session->write('abc', '');

        $this->assertSame(['Action' => 'DELETE'], $this->payload()['AttributeUpdates']['data']);
    }

    public function testWriteFailureTriggersWarning(): void
    {
        $this->queueError('ResourceNotFoundException');
        $session = $this->openSession();

        $warnings = $this->captureWarnings(fn() => $this->assertFalse($session->write('abc', 'serialized')));

        $this->assertCount(1, $warnings);
        $this->assertStringStartsWith('Error writing session abc: ', $warnings[0]);
    }

    public function testDestroy(): void
    {
        $this->queueJson([]);

        $this->assertTrue($this->openSession()->destroy('abc'));

        $this->assertSame('DeleteItem', $this->operation());
        $this->assertSame($this->key('abc'), $this->payload()['Key']);
    }

    public function testDestroyFailureTriggersWarning(): void
    {
        $this->queueError('ResourceNotFoundException');
        $session = $this->openSession();

        $warnings = $this->captureWarnings(fn() => $this->assertFalse($session->destroy('abc')));

        $this->assertCount(1, $warnings);
        $this->assertStringStartsWith('Error deleting session abc: ', $warnings[0]);
    }

    public function testCloseReleasesTheLock(): void
    {
        $this->queueJson([]);

        $this->assertTrue($this->openSession()->close());

        $this->assertSame('UpdateItem', $this->operation());
        $payload = $this->payload();
        $this->assertSame(['Action' => 'DELETE'], $payload['AttributeUpdates']['lock']);
        $this->assertArrayNotHasKey('data', $payload['AttributeUpdates']);
    }

    public function testGc(): void
    {
        $this->assertTrue($this->openSession()->gc(time()));
        $this->assertSame([], $this->requests);
    }
}

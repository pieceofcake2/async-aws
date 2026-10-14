<?php

use AsyncAws\Core\Exception\Exception as AsyncAwsException;
use AsyncAws\DynamoDb\DynamoDbClient;
use AsyncAws\DynamoDb\Exception\ConditionalCheckFailedException;

App::uses('CakeSessionHandlerInterface', 'Model/Datasource/Session');
App::uses('ComponentCollection', 'Controller');
App::uses('AsyncAwsComponent', 'AsyncAws.Controller/Component');
App::uses('CakeException', 'Error');

/**
 * DynamoDB session handler
 *
 * Stores sessions in DynamoDB with async-aws/dynamo-db. The items are the same as those of the session handler
 * of the AWS SDK for PHP (used by pieceofcake2/aws), so both plugins can share a table:
 * the key is `<session name>_<session id>` (`hash_key`, default `id`), the data is a string (`data_attribute`,
 * default `data`), the expiration time is a Unix timestamp (`session_lifetime_attribute`, default `expires`),
 * and the `lock` attribute marks a locked session.
 *
 * Configure the table with `Session.handler.table_name`. The other keys of `Session.handler` are:
 *
 *  - `locking` = Lock the session while a request uses it (default: true)
 *  - `consistent_read` = Use strongly consistent reads when not locking (default: true)
 *  - `session_lifetime` = Seconds until a session expires (default: `session.gc_maxlifetime`)
 *  - `hash_key`, `data_attribute`, `session_lifetime_attribute` = Attribute names
 *  - `max_lock_wait_time` = Seconds to wait for a lock (default: 10)
 *  - `min_lock_retry_microtime`, `max_lock_retry_microtime` = Microseconds between attempts to get a lock (default: 10000 and 50000)
 *
 * Expired sessions are not removed by PHP's garbage collection; enable Time to Live on the table with the
 * `expires` attribute so that DynamoDB removes them. This handler turns off PHP's garbage collection
 * (`session.gc_probability = 0`) because it would never remove anything.
 */
class DynamoDbSession implements CakeSessionHandlerInterface
{
    /**
     * Name of the attribute that marks a locked session (the same as the AWS SDK's).
     *
     * @var string
     */
    public const LOCK_ATTRIBUTE = 'lock';

    private DynamoDbClient $client;

    /**
     * Options of the handler.
     *
     * @var array<string, mixed>
     */
    private array $options;

    private string $sessionName = '';

    private ?string $openSessionId = null;

    private string $dataRead = '';

    private bool $sessionWritten = false;

    /**
     * Constructor
     *
     * @param \AsyncAws\DynamoDb\DynamoDbClient|null $client DynamoDB client. Default: a client created with the plugin configuration.
     * @throws \CakeException When `Session.handler.table_name` is not configured.
     */
    public function __construct(?DynamoDbClient $client = null)
    {
        $options = (array)Configure::read('Session.handler');
        if (empty($options['table_name'])) {
            throw new CakeException('DynamoDB session handler "table_name" is not configured.');
        }

        $this->options = $options + [
            'hash_key' => 'id',
            'data_attribute' => 'data',
            'session_lifetime' => null,
            'session_lifetime_attribute' => 'expires',
            'consistent_read' => true,
            'locking' => true,
            'max_lock_wait_time' => 10,
            'min_lock_retry_microtime' => 10000,
            'max_lock_retry_microtime' => 50000,
        ];

        $this->client = $client ?? (new AsyncAwsComponent(new ComponentCollection()))->createDynamoDb();

        // Expired sessions are removed by the table's Time to Live (`expires`), not by PHP's garbage collection.
        if (session_status() !== PHP_SESSION_ACTIVE) {
            ini_set('session.gc_probability', '0');
        }
    }

    /**
     * Returns the DynamoDB client.
     *
     * @return \AsyncAws\DynamoDb\DynamoDbClient
     */
    public function getClient(): DynamoDbClient
    {
        return $this->client;
    }

    /**
     * Method called on open of a session.
     *
     * @return bool Success
     */
    public function open(): bool
    {
        $this->sessionName = (string)session_name();

        return true;
    }

    /**
     * Method called on close of a session.
     *
     * Updates the expiration time (and releases the lock) even if the session was not written.
     *
     * @return bool Success
     */
    public function close(): bool
    {
        $id = (string)session_id();
        if ($this->openSessionId !== $id || !$this->sessionWritten) {
            $this->sessionWritten = $this->update($id, '', false);
        }

        return $this->sessionWritten;
    }

    /**
     * Method used to read from a session.
     *
     * @param string $id The key of the value to read
     * @return string The session data, or an empty string if it does not exist or has expired
     */
    public function read($id)
    {
        $id = (string)$id;
        $this->openSessionId = $id;
        $this->dataRead = '';

        $item = $this->options['locking'] ? $this->lockAndGet($id) : $this->get($id);

        $data = $item[$this->options['data_attribute']] ?? null;
        $expires = $item[$this->options['session_lifetime_attribute']] ?? null;
        if ($data !== null && $expires !== null) {
            $this->dataRead = (string)($data->getS() ?? $data->getB());
            if ((int)$expires->getN() <= time()) {
                $this->dataRead = '';
                $this->destroy($id);
            }
        }

        return $this->dataRead;
    }

    /**
     * Helper function called on write for sessions.
     *
     * @param string|int $id ID that uniquely identifies session in database
     * @param mixed $data The value of the data to be saved
     * @return bool true for successful write, false otherwise
     */
    public function write($id, $data): bool
    {
        $id = (string)$id;
        $data = (string)$data;
        $changed = $id !== $this->openSessionId || $data !== $this->dataRead;
        $this->openSessionId = $id;

        return $this->sessionWritten = $this->update($id, $data, $changed);
    }

    /**
     * Method called on the destruction of a session.
     *
     * @param string|int $id ID that uniquely identifies session in database
     * @return bool true for successful delete, false otherwise
     */
    public function destroy($id): bool
    {
        $id = (string)$id;
        $this->openSessionId = $id;

        try {
            $this->client->deleteItem([
                'TableName' => $this->options['table_name'],
                'Key' => $this->key($id),
            ])->resolve();
        } catch (AsyncAwsException $e) {
            return $this->sessionWritten = $this->error(sprintf('Error deleting session %s: %s', $id, $e->getMessage()));
        }

        return $this->sessionWritten = true;
    }

    /**
     * Run the Garbage collection on the session storage.
     *
     * Does nothing. Expired sessions are removed by the table's Time to Live (`expires`).
     *
     * @param int $expires Timestamp (defaults to current time)
     * @return bool Success
     */
    public function gc($expires = null): bool
    {
        return true;
    }

    /**
     * Reads the item without locking it.
     *
     * @param string $id Session ID
     * @return array<string, \AsyncAws\DynamoDb\ValueObject\AttributeValue>
     */
    private function get(string $id): array
    {
        try {
            return $this->client->getItem([
                'TableName' => $this->options['table_name'],
                'Key' => $this->key($id),
                'ConsistentRead' => (bool)$this->options['consistent_read'],
            ])->getItem();
        } catch (AsyncAwsException) {
            return [];
        }
    }

    /**
     * Locks the item and reads it in one atomic UpdateItem call, waiting while another request holds the lock.
     *
     * @param string $id Session ID
     * @return array<string, \AsyncAws\DynamoDb\ValueObject\AttributeValue>
     */
    private function lockAndGet(string $id): array
    {
        $params = [
            'TableName' => $this->options['table_name'],
            'Key' => $this->key($id),
            'Expected' => [self::LOCK_ATTRIBUTE => ['Exists' => false]],
            'AttributeUpdates' => [self::LOCK_ATTRIBUTE => ['Value' => ['N' => '1'], 'Action' => 'PUT']],
            'ReturnValues' => 'ALL_NEW',
        ];

        $timeout = time() + (int)$this->options['max_lock_wait_time'];
        while (true) {
            try {
                return $this->client->updateItem($params)->getAttributes();
            } catch (ConditionalCheckFailedException) {
                if (time() >= $timeout) {
                    return [];
                }
                usleep(random_int((int)$this->options['min_lock_retry_microtime'], (int)$this->options['max_lock_retry_microtime']));
            } catch (AsyncAwsException) {
                return [];
            }
        }
    }

    /**
     * Updates the expiration time, releases the lock and, when `$changed`, writes the data.
     *
     * @param string $id Session ID
     * @param string $data Session data
     * @param bool $changed Whether the data must be written
     * @return bool
     */
    private function update(string $id, string $data, bool $changed): bool
    {
        $lifetime = $this->options['session_lifetime'] ?? (int)ini_get('session.gc_maxlifetime');
        $attributes = [
            $this->options['session_lifetime_attribute'] => ['Value' => ['N' => (string)(time() + (int)$lifetime)], 'Action' => 'PUT'],
            self::LOCK_ATTRIBUTE => ['Action' => 'DELETE'],
        ];
        if ($changed) {
            $attributes[$this->options['data_attribute']] = $data !== ''
                ? ['Value' => ['S' => $data], 'Action' => 'PUT']
                : ['Action' => 'DELETE'];
        }

        try {
            $this->client->updateItem([
                'TableName' => $this->options['table_name'],
                'Key' => $this->key($id),
                'AttributeUpdates' => $attributes,
            ])->resolve();
        } catch (AsyncAwsException $e) {
            return $this->error(sprintf('Error writing session %s: %s', $id, $e->getMessage()));
        }

        if ($changed) {
            $this->dataRead = $data;
        }

        return true;
    }

    /**
     * Returns the primary key of the item for a session ID.
     *
     * @param string $id Session ID
     * @return array<string, array{S: string}>
     */
    private function key(string $id): array
    {
        return [$this->options['hash_key'] => ['S' => trim($this->sessionName . '_' . $id, '_')]];
    }

    /**
     * Triggers a warning, like the session handler of the AWS SDK.
     *
     * @param string $message Message
     * @return false
     */
    private function error(string $message): bool
    {
        trigger_error($message, E_USER_WARNING);

        return false;
    }
}

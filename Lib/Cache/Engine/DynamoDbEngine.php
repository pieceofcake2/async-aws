<?php

use AsyncAws\Core\Exception\Exception as AsyncAwsException;
use AsyncAws\Core\Exception\Http\HttpException;
use AsyncAws\DynamoDb\DynamoDbClient;
use AsyncAws\DynamoDb\Exception\ConditionalCheckFailedException;
use AsyncAws\DynamoDb\ValueObject\AttributeValue;

App::uses('CacheEngine', 'Cache');
App::uses('ComponentCollection', 'Controller');
App::uses('AsyncAwsComponent', 'AsyncAws.Controller/Component');
App::uses('CakeLog', 'Log');
App::uses('Inflector', 'Utility');
App::uses('MissingPackageException', 'AsyncAws.Error');

/**
 * DynamoDB storage engine for cache
 *
 * Stores cache entries as items of a DynamoDB table with async-aws/dynamo-db:
 *
 * ```
 * Cache::config('default', [
 *     'engine' => 'AsyncAws.DynamoDb',
 *     'table' => 'cache',
 * ]);
 * ```
 *
 * The client is created with `AsyncAwsComponent::createDynamoDb()`, so it uses the `AsyncAws` configuration.
 * `clientConfig` overrides it for this cache configuration, and `client` sets the client itself.
 * The engine requires `async-aws/dynamo-db`; without it, `Cache::config()` throws a `CacheException` that names
 * the package.
 *
 * Each item has the cache key (`keyAttribute`, string partition key), the value (`valueAttribute`, a number for
 * integers and the serialized value otherwise) and the expiration time (`expiresAttribute`, Unix epoch seconds).
 * The items are the same as those of the `Aws.DynamoDb` engine of pieceofcake2/aws, so both engines can share a table.
 * Expired items are never returned. Enable Time to Live on the table with the expiration attribute so that
 * DynamoDB removes them; `gc()` does nothing.
 */
class DynamoDbEngine extends CacheEngine
{
    /**
     * Maximum size of a DynamoDB item, in bytes.
     *
     * @var int
     */
    public const MAX_ITEM_SIZE = 409600;

    /**
     * Maximum number of requests in a BatchWriteItem call.
     *
     * @var int
     */
    private const BATCH_SIZE = 25;

    /**
     * Maximum number of attempts to send the unprocessed items of a BatchWriteItem call.
     *
     * @var int
     */
    private const BATCH_ATTEMPTS = 5;

    /**
     * Settings
     *
     *  - table = Name of the DynamoDB table (required)
     *  - keyAttribute = Name of the string partition key (default: `id`)
     *  - valueAttribute = Name of the attribute storing the value (default: `value`)
     *  - expiresAttribute = Name of the attribute storing the expiration time, used by Time to Live (default: `expires`)
     *  - consistentRead = Use strongly consistent reads (default: false)
     *  - client = An `AsyncAws\DynamoDb\DynamoDbClient` to use instead of creating one
     *  - clientConfig = Client options merged into the `AsyncAws` configuration (default: [])
     *
     * @var array
     */
    public $settings = [];

    /**
     * DynamoDB client
     *
     * @var \AsyncAws\DynamoDb\DynamoDbClient|null
     */
    protected ?DynamoDbClient $_client = null;

    /**
     * Initialize the Cache Engine
     *
     * @param array $settings array of setting for the engine
     * @return bool True if the engine has been successfully initialized, false if not
     * @throws \CacheException When async-aws/dynamo-db is not installed.
     */
    public function init($settings = [])
    {
        if (!class_exists(static::clientClass())) {
            // Thrown rather than returning false, like MemcachedEngine does for a missing feature, so that the
            // message names the package instead of Cache's generic "not properly configured".
            throw new CacheException(MissingPackageException::message('async-aws/dynamo-db', 'The DynamoDB cache engine'));
        }

        parent::init(array_merge([
            'engine' => 'AsyncAws.DynamoDb',
            'prefix' => Inflector::slug(APP_DIR) . '_',
            'probability' => 0,
            'table' => null,
            'keyAttribute' => 'id',
            'valueAttribute' => 'value',
            'expiresAttribute' => 'expires',
            'consistentRead' => false,
            'client' => null,
            'clientConfig' => [],
        ], $settings));

        if (empty($this->settings['table'])) {
            return false;
        }

        if ($this->settings['client'] instanceof DynamoDbClient) {
            $this->_client = $this->settings['client'];
        } else {
            $AsyncAws = new AsyncAwsComponent(new ComponentCollection());
            $this->_client = $AsyncAws->createDynamoDb((array)$this->settings['clientConfig']);
        }

        return true;
    }

    /**
     * Returns the class of the DynamoDB client, which is checked to exist before the client is created.
     *
     * @return string
     */
    protected static function clientClass(): string
    {
        return DynamoDbClient::class;
    }

    /**
     * Returns the DynamoDB client.
     *
     * @return \AsyncAws\DynamoDb\DynamoDbClient
     */
    public function getClient()
    {
        return $this->_client;
    }

    /**
     * Write data for key into cache.
     *
     * @param string $key Identifier for the data
     * @param mixed $value Data to be cached
     * @param int $duration How long to cache the data, in seconds. 0 means it never expires.
     * @return bool True if the data was successfully cached, false on failure
     */
    public function write($key, $value, $duration)
    {
        $item = $this->_item($key, $value, $duration);
        if ($item === null) {
            return false;
        }

        try {
            $this->_client->putItem([
                'TableName' => $this->settings['table'],
                'Item' => $item,
            ])->resolve();
        } catch (AsyncAwsException $e) {
            $this->_logError('write', $key, $e);

            return false;
        }

        return true;
    }

    /**
     * Write data for key into cache if it doesn't exist already, or has expired.
     *
     * @param string $key Identifier for the data
     * @param mixed $value Data to be cached
     * @param int $duration How long to cache the data, in seconds. 0 means it never expires.
     * @return bool True if the data was successfully cached, false on failure or if the key exists
     */
    public function add($key, $value, $duration)
    {
        $item = $this->_item($key, $value, $duration);
        if ($item === null) {
            return false;
        }

        try {
            $this->_client->putItem([
                'TableName' => $this->settings['table'],
                'Item' => $item,
                'ConditionExpression' => 'attribute_not_exists(#k) OR #e <= :now',
                'ExpressionAttributeNames' => [
                    '#k' => $this->settings['keyAttribute'],
                    '#e' => $this->settings['expiresAttribute'],
                ],
                'ExpressionAttributeValues' => [':now' => ['N' => (string)time()]],
            ])->resolve();
        } catch (ConditionalCheckFailedException $e) {
            return false;
        } catch (AsyncAwsException $e) {
            $this->_logError('add', $key, $e);

            return false;
        }

        return true;
    }

    /**
     * Read a key from the cache
     *
     * @param string $key Identifier for the data
     * @return mixed The cached data, or false if the data doesn't exist, has expired, or if there was an error fetching it
     */
    public function read($key)
    {
        try {
            $item = $this->_client->getItem([
                'TableName' => $this->settings['table'],
                'Key' => $this->_key($key),
                'ConsistentRead' => (bool)$this->settings['consistentRead'],
            ])->getItem();
        } catch (AsyncAwsException $e) {
            $this->_logError('read', $key, $e);

            return false;
        }

        if (!$item || $this->_isExpired($item)) {
            return false;
        }

        return $this->_value($item[$this->settings['valueAttribute']] ?? null);
    }

    /**
     * Increments the value of an integer cached key
     *
     * Like `MemcachedEngine`, fails when the key does not exist or has expired.
     *
     * @param string $key Identifier for the data
     * @param int $offset How much to increment
     * @return int|false New incremented value, false otherwise
     */
    public function increment($key, $offset = 1)
    {
        return $this->_add($key, (int)$offset, 'increment');
    }

    /**
     * Decrements the value of an integer cached key
     *
     * Like `MemcachedEngine`, fails when the key does not exist or has expired.
     *
     * @param string $key Identifier for the data
     * @param int $offset How much to subtract
     * @return int|false New decremented value, false otherwise
     */
    public function decrement($key, $offset = 1)
    {
        return $this->_add($key, -(int)$offset, 'decrement');
    }

    /**
     * Delete a key from the cache
     *
     * @param string $key Identifier for the data
     * @return bool True if the value was successfully deleted, false if it didn't exist or couldn't be removed
     */
    public function delete($key)
    {
        try {
            $attributes = $this->_client->deleteItem([
                'TableName' => $this->settings['table'],
                'Key' => $this->_key($key),
                'ReturnValues' => 'ALL_OLD',
            ])->getAttributes();
        } catch (AsyncAwsException $e) {
            $this->_logError('delete', $key, $e);

            return false;
        }

        return !empty($attributes);
    }

    /**
     * Delete all keys starting with the prefix of this configuration from the cache
     *
     * This scans the whole table, which reads (and is charged for) every item of the table.
     *
     * @param bool $check If true, only the expired items are deleted
     * @return bool True if the cache was successfully cleared, false otherwise
     */
    public function clear($check)
    {
        $names = ['#k' => $this->settings['keyAttribute']];
        $values = [];
        $filters = [];
        if ($this->settings['prefix'] !== '') {
            $filters[] = 'begins_with(#k, :prefix)';
            $values[':prefix'] = ['S' => (string)$this->settings['prefix']];
        }
        if ($check) {
            $filters[] = '#e <= :now';
            $names['#e'] = $this->settings['expiresAttribute'];
            $values[':now'] = ['N' => (string)time()];
        }

        $params = [
            'TableName' => $this->settings['table'],
            'ProjectionExpression' => '#k',
            'ExpressionAttributeNames' => $names,
            'ConsistentRead' => (bool)$this->settings['consistentRead'],
        ];
        if ($filters) {
            $params['FilterExpression'] = implode(' AND ', $filters);
            $params['ExpressionAttributeValues'] = $values;
        }

        try {
            $keys = [];
            // Iterating the items of a Scan result fetches the following pages.
            foreach ($this->_client->scan($params)->getItems() as $item) {
                $keys[] = $item;
                if (count($keys) === self::BATCH_SIZE) {
                    $this->_batchDelete($keys);
                    $keys = [];
                }
            }
            if ($keys) {
                $this->_batchDelete($keys);
            }
        } catch (AsyncAwsException | CacheException $e) {
            $this->_logError('clear', (string)$this->settings['prefix'], $e);

            return false;
        }

        return true;
    }

    /**
     * Returns the `group value` for each of the configured groups
     * If the group initial value was not found, then it initializes
     * the group accordingly.
     *
     * @return array
     */
    public function groups()
    {
        $result = [];
        foreach ($this->settings['groups'] as $group) {
            $key = $this->settings['prefix'] . $group;
            $value = $this->read($key);
            if (!$value) {
                $value = $this->_initializeGroup($key);
            }
            $result[] = $group . $value;
        }

        return $result;
    }

    /**
     * Increments the group value to simulate deletion of all keys under a group
     * old values will remain in storage until they expire.
     *
     * @param string $group The group name to clear.
     * @return bool success
     */
    public function clearGroup($group)
    {
        $key = $this->settings['prefix'] . $group;
        try {
            // A missing group value becomes 2, so the keys written with the initial value 1 are not read.
            $this->_client->updateItem([
                'TableName' => $this->settings['table'],
                'Key' => $this->_key($key),
                'UpdateExpression' => 'SET #v = if_not_exists(#v, :one) + :one REMOVE #e',
                'ExpressionAttributeNames' => [
                    '#v' => $this->settings['valueAttribute'],
                    '#e' => $this->settings['expiresAttribute'],
                ],
                'ExpressionAttributeValues' => [':one' => ['N' => '1']],
            ])->resolve();
        } catch (AsyncAwsException $e) {
            $this->_logError('clearGroup', $key, $e);

            return false;
        }

        return true;
    }

    /**
     * Creates the cache table and enables Time to Live on the expiration attribute.
     *
     * The table uses on-demand capacity unless `$options` sets another `BillingMode`.
     *
     * @param array $options Parameters merged into the CreateTable request.
     * @return void
     * @throws \CacheException When the table does not become active.
     */
    public function createTable(array $options = [])
    {
        $table = $this->settings['table'];
        $this->_client->createTable($options + [
            'TableName' => $table,
            'AttributeDefinitions' => [
                ['AttributeName' => $this->settings['keyAttribute'], 'AttributeType' => 'S'],
            ],
            'KeySchema' => [
                ['AttributeName' => $this->settings['keyAttribute'], 'KeyType' => 'HASH'],
            ],
            'BillingMode' => 'PAY_PER_REQUEST',
        ])->resolve();

        $waiter = $this->_client->tableExists(['TableName' => $table]);
        if (!$waiter->wait() || !$waiter->isSuccess()) {
            throw new CacheException(sprintf('DynamoDB cache table "%s" did not become active.', $table));
        }

        $this->_client->updateTimeToLive([
            'TableName' => $table,
            'TimeToLiveSpecification' => [
                'Enabled' => true,
                'AttributeName' => $this->settings['expiresAttribute'],
            ],
        ])->resolve();
    }

    /**
     * Adds `$offset` to the number stored for the key.
     *
     * @param string $key Identifier for the data
     * @param int $offset Number to add
     * @param string $operation Operation name for the log
     * @return int|false New value, false otherwise
     */
    protected function _add($key, $offset, $operation)
    {
        try {
            $attributes = $this->_client->updateItem([
                'TableName' => $this->settings['table'],
                'Key' => $this->_key($key),
                'UpdateExpression' => 'ADD #v :offset',
                'ConditionExpression' => 'attribute_exists(#k) AND (attribute_not_exists(#e) OR #e > :now)',
                'ExpressionAttributeNames' => [
                    '#k' => $this->settings['keyAttribute'],
                    '#v' => $this->settings['valueAttribute'],
                    '#e' => $this->settings['expiresAttribute'],
                ],
                'ExpressionAttributeValues' => [
                    ':offset' => ['N' => (string)$offset],
                    ':now' => ['N' => (string)time()],
                ],
                'ReturnValues' => 'UPDATED_NEW',
            ])->getAttributes();
        } catch (ConditionalCheckFailedException $e) {
            return false;
        } catch (AsyncAwsException $e) {
            $this->_logError($operation, $key, $e);

            return false;
        }

        $value = isset($attributes[$this->settings['valueAttribute']]) ? $attributes[$this->settings['valueAttribute']]->getN() : null;

        return $value === null ? false : (int)$value;
    }

    /**
     * Initializes the value of a group to 1 unless another process has already done so.
     *
     * @param string $key Key of the group value
     * @return int The group value
     */
    protected function _initializeGroup($key)
    {
        try {
            $attributes = $this->_client->updateItem([
                'TableName' => $this->settings['table'],
                'Key' => $this->_key($key),
                'UpdateExpression' => 'SET #v = if_not_exists(#v, :one) REMOVE #e',
                'ExpressionAttributeNames' => [
                    '#v' => $this->settings['valueAttribute'],
                    '#e' => $this->settings['expiresAttribute'],
                ],
                'ExpressionAttributeValues' => [':one' => ['N' => '1']],
                'ReturnValues' => 'UPDATED_NEW',
            ])->getAttributes();
        } catch (AsyncAwsException $e) {
            $this->_logError('groups', $key, $e);

            return 1;
        }

        $value = isset($attributes[$this->settings['valueAttribute']]) ? $attributes[$this->settings['valueAttribute']]->getN() : null;

        return (int)($value ?? 1);
    }

    /**
     * Deletes items with BatchWriteItem, retrying the unprocessed items.
     *
     * @param array<array<string, \AsyncAws\DynamoDb\ValueObject\AttributeValue>> $keys Keys of the items to delete
     * @return void
     * @throws \CacheException When some items are still unprocessed after the retries
     */
    protected function _batchDelete(array $keys)
    {
        $requests = [];
        foreach ($keys as $key) {
            $requests[] = ['DeleteRequest' => ['Key' => $key]];
        }

        $table = $this->settings['table'];
        for ($attempt = 1; $requests; $attempt++) {
            $unprocessed = $this->_client->batchWriteItem(['RequestItems' => [$table => $requests]])->getUnprocessedItems();
            $requests = $unprocessed[$table] ?? [];
            if (!$requests) {
                break;
            }
            if ($attempt >= self::BATCH_ATTEMPTS) {
                throw new CacheException(sprintf('DynamoDB cache could not delete %d items of table "%s".', count($requests), $table));
            }
            usleep(50000 * 2 ** $attempt);
        }
    }

    /**
     * Builds the item to put.
     *
     * @param string $key Identifier for the data
     * @param mixed $value Data to be cached
     * @param int $duration How long to cache the data, in seconds. 0 means it never expires.
     * @return array<string, array<string, string>>|null The item, or null when it is too large
     */
    protected function _item($key, $value, $duration)
    {
        $data = is_int($value) ? ['N' => (string)$value] : ['B' => serialize($value)];
        $item = $this->_key($key) + [$this->settings['valueAttribute'] => $data];
        if ($duration > 0) {
            $item[$this->settings['expiresAttribute']] = ['N' => (string)(time() + $duration)];
        }

        $size = 0;
        foreach ($item as $name => $attribute) {
            $size += strlen((string)$name) + strlen((string)current($attribute));
        }
        if ($size > self::MAX_ITEM_SIZE) {
            CakeLog::write(LOG_ERR, sprintf(
                'DynamoDB cache could not write "%s" to table "%s": the item is %d bytes, larger than the DynamoDB item size limit of %d bytes.',
                $key,
                $this->settings['table'],
                $size,
                self::MAX_ITEM_SIZE,
            ), ['cache']);

            return null;
        }

        return $item;
    }

    /**
     * Returns the primary key of the item for a cache key.
     *
     * @param string $key Identifier for the data
     * @return array<string, array<string, string>>
     */
    protected function _key($key)
    {
        return [$this->settings['keyAttribute'] => ['S' => (string)$key]];
    }

    /**
     * Returns whether an item has expired.
     *
     * @param array<string, \AsyncAws\DynamoDb\ValueObject\AttributeValue> $item The item
     * @return bool
     */
    protected function _isExpired(array $item)
    {
        $expires = isset($item[$this->settings['expiresAttribute']]) ? $item[$this->settings['expiresAttribute']]->getN() : null;

        return $expires !== null && (int)$expires <= time();
    }

    /**
     * Converts a stored attribute value to the cached value.
     *
     * @param \AsyncAws\DynamoDb\ValueObject\AttributeValue|null $attribute The stored value
     * @return mixed
     */
    protected function _value(?AttributeValue $attribute)
    {
        if ($attribute === null) {
            return false;
        }
        if ($attribute->getN() !== null) {
            return (int)$attribute->getN();
        }
        if ($attribute->getB() !== null) {
            return unserialize($attribute->getB());
        }

        return false;
    }

    /**
     * Logs a failed request.
     *
     * @param string $operation Name of the operation
     * @param string $key Identifier for the data
     * @param \Throwable $e The exception
     * @return void
     */
    protected function _logError($operation, $key, Throwable $e)
    {
        $message = $e instanceof HttpException
            ? trim($e->getAwsCode() . ' ' . $e->getAwsMessage())
            : $e->getMessage();

        CakeLog::write(LOG_ERR, sprintf(
            'DynamoDB cache %s of "%s" in table "%s" failed: %s',
            $operation,
            $key,
            $this->settings['table'],
            $message,
        ), ['cache']);
    }
}

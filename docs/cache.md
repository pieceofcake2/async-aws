# DynamoDB Cache Engine

`AsyncAws.DynamoDb` stores cache entries in a DynamoDB table. Configure it in
`config/bootstrap.php` (legacy layout: `app/Config/bootstrap.php`) or `config/core.php`:

```php
Cache::config('default', [
    'engine' => 'AsyncAws.DynamoDb',
    'table' => 'DYNAMODB_TABLE_NAME',
    'prefix' => 'myapp_',
    'duration' => '+1 hours',
]);
```

The engine has the settings of every cache engine (`prefix`, `duration`, `groups`, ...)
and these:

| Setting | Default | Description |
| --- | --- | --- |
| `table` | (required) | Name of the table. `Cache::config()` throws a `CacheException` when it is missing. |
| `keyAttribute` | `id` | Name of the string partition key. |
| `valueAttribute` | `value` | Name of the attribute storing the value. |
| `expiresAttribute` | `expires` | Name of the attribute storing the expiration time (Unix timestamp), used by Time to Live. |
| `consistentRead` | `false` | Use strongly consistent reads. |
| `clientConfig` | `[]` | Options merged into the `AsyncAws` configuration for this cache, e.g. `['region' => 'us-east-1']`. |
| `client` | `null` | An `AsyncAws\DynamoDb\DynamoDbClient` to use instead of creating one. |

- Integers are stored as numbers, so `Cache::increment()` and `Cache::decrement()` are atomic.
  Like `MemcachedEngine`, they fail (return `false`) when the key does not exist or has expired.
  Other values are serialized and stored as binary.
- A `duration` of `0` writes items that never expire.
- Expired items are never returned. Enable Time to Live on the table with the `expires`
  attribute so that DynamoDB removes them; `gc()` does nothing.
- `Cache::clear()` scans the whole table and deletes the items whose key starts with the
  `prefix` of the configuration (only the expired ones with `Cache::clear(true)`). A scan reads,
  and is charged for, every item of the table, so give each application its own `prefix`, or
  its own table, and do not call it often.
- Groups work like those of `RedisEngine`: each group has a version number stored in the
  table, and `Cache::clearGroup()` increments it. The old entries stay in the table until they
  expire.
- An item is limited to 400 KB. A larger value is not written: `Cache::write()` returns
  `false` and an error is logged.
- Failed requests are logged to the `cache` scope and the operation returns `false`.

The items are the same as those of the `Aws.DynamoDb` engine of pieceofcake2/aws, so both
engines can use the same table.

## Creating the Table

The table needs a string partition key named `id` (`keyAttribute`). `createTable()` creates it
with on-demand capacity and enables Time to Live:

```php
Cache::engine('default')->createTable();
```

Or with the AWS CLI:

```bash
aws dynamodb create-table --table-name DYNAMODB_TABLE_NAME \
    --attribute-definitions AttributeName=id,AttributeType=S \
    --key-schema AttributeName=id,KeyType=HASH \
    --billing-mode PAY_PER_REQUEST
aws dynamodb update-time-to-live --table-name DYNAMODB_TABLE_NAME \
    --time-to-live-specification "Enabled=true,AttributeName=expires"
```

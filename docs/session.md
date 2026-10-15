# DynamoDB Session Handler

`AsyncAws.DynamoDbSession` stores the sessions in a DynamoDB table. Configure it in
`config/core.php` (legacy layout: `app/Config/core.php`):

```php
Configure::write('Session', [
    'defaults' => 'php',
    'cookie' => 'MY_APP',
    'handler' => [
        'engine' => 'AsyncAws.DynamoDbSession',
        'table_name' => 'DYNAMODB_TABLE_NAME',
        // Optional (default: 'locking' => true)
        // 'locking' => true,
    ],
    // Minutes; also the lifetime written to the "expires" attribute of each item
    'timeout' => 1440,
    'ini' => [
        'session.cookie_lifetime' => 0,
        // Expired sessions are removed by DynamoDB's Time to Live, not by PHP's garbage collection
        'session.gc_probability' => 0,
    ],
]);
```

- `handler.table_name` is required; a `CakeException` is thrown when it is missing.
- The table must exist, with a string partition key named `id`, and Time to Live must be
  enabled on the `expires` attribute (see [Creating the Session Table](#creating-the-session-table)).
- The DynamoDB client is created with the `AsyncAws` configuration, so the plugin must be
  loaded and `AsyncAws` configured before the session starts.
- If several applications share a domain and a table, give each application its own session
  cookie name (`Session.cookie`) so that they do not read each other's sessions.

These keys of `handler` are supported. They have the same names and defaults as the options of
the session handler of the AWS SDK for PHP, which pieceofcake2/aws uses:

| Key | Default | Description |
| --- | --- | --- |
| `locking` | `true` | Lock the session while a request uses it, so that concurrent requests do not overwrite each other's changes. |
| `consistent_read` | `true` | Use strongly consistent reads when `locking` is off. |
| `session_lifetime` | `Session.timeout` minutes | Seconds until a session expires. The default is the timeout that `CakeSession` checks, so a session item expires together with the session; without `Session.timeout`, it is `session.gc_maxlifetime`. |
| `hash_key` | `id` | Name of the partition key. |
| `data_attribute` | `data` | Name of the attribute storing the session data. |
| `session_lifetime_attribute` | `expires` | Name of the attribute storing the expiration time. |
| `max_lock_wait_time` | `10` | Seconds to wait for a lock held by another request. |
| `min_lock_retry_microtime`, `max_lock_retry_microtime` | `10000`, `50000` | Microseconds between two attempts to get a lock. |

The items are the same as those of the AWS SDK's session handler: the key is
`<session name>_<session id>`, the data is a string, the expiration time is a Unix timestamp,
and a `lock` attribute marks a locked session. pieceofcake2/aws and this plugin can use the
same table, so users stay logged in when you switch plugins.

The handler does not use `async-aws/dynamo-db-session`, because that handler does not lock
sessions, does not write the data under a new ID after `session_regenerate_id()` (which
`CakeSession::renew()` and `AuthComponent::login()` call) unless the data changes, and relies
on a default of the DynamoDB API that DynamoDB Local does not apply.

## Creating the Session Table

Create the table and enable Time to Live on the `expires` attribute with the AWS CLI:

```bash
aws dynamodb create-table --table-name DYNAMODB_TABLE_NAME \
    --attribute-definitions AttributeName=id,AttributeType=S \
    --key-schema AttributeName=id,KeyType=HASH \
    --billing-mode PAY_PER_REQUEST
aws dynamodb update-time-to-live --table-name DYNAMODB_TABLE_NAME \
    --time-to-live-specification "Enabled=true,AttributeName=expires"
```

## How Sessions Expire

DynamoDB removes the expired sessions itself:

- Each write and close of a session stores its expiration time, the current time plus
  `session_lifetime`, in the `expires` attribute.
- DynamoDB's [Time to Live](https://docs.aws.amazon.com/amazondynamodb/latest/developerguide/TTL.html)
  deletes the items whose `expires` time has passed. It runs in the background at no cost but
  lazily: an expired item can stay in the table for up to about 48 hours, sometimes longer.
- Until then, the handler reads an expired session as an empty session without deleting it,
  and the next write of that session replaces the old data. An expired session never comes back.

PHP's session garbage collection is off: the example sets `session.gc_probability` to `0`, and
`DynamoDbSession` also sets it to `0` when `Session.ini` does not contain
`session.gc_probability`.

## Garbage Collection

`DynamoDbSession::gc()` can still delete the expired sessions, for tables without Time to Live.
It scans the whole table for items whose `expires` time has passed and deletes them with
`BatchWriteItem` (25 items per request, retrying unprocessed items a few times). Errors are
logged with the `session` scope and never interrupt the request.

A scan reads, and is charged for, every item of the table, and runs during the request that
PHP picks for garbage collection, so keep it off when Time to Live is enabled. To turn it on,
set the probability in `Session.ini`, for example once every 1000 session starts:

```php
Configure::write('Session', [
    // ...
    'ini' => [
        'session.cookie_lifetime' => 0,
        'session.gc_probability' => 1,
        'session.gc_divisor' => 1000,
    ],
]);
```

## DynamoDB Local

[DynamoDB Local](https://docs.aws.amazon.com/amazondynamodb/latest/developerguide/DynamoDBLocal.html)
accepts the Time to Live setting, but whether it deletes expired items depends on its version
(the current `amazon/dynamodb-local` image does). Expired sessions are always read as empty;
if they stay in the table, delete them by hand or turn on garbage collection in local
development as shown above.

`DynamoDbSession` sends every `UpdateItem` action explicitly, so sessions are stored in
DynamoDB Local as well.

# AsyncAws Documentation

The AsyncAws plugin integrates [AsyncAws](https://async-aws.com) with CakePHP 2 applications
running on [pieceofcake2/cakephp](https://github.com/pieceofcake2/cakephp). It provides an
`AsyncAws` component that creates AsyncAws clients, a session handler and a cache engine that
store data in Amazon DynamoDB, an email transport that sends email with the Amazon SES API v2,
and a small helper for S3 objects.

It is the AsyncAws counterpart of [pieceofcake2/aws](https://github.com/pieceofcake2/aws),
which uses the full [AWS SDK for PHP](https://github.com/aws/aws-sdk-php). AsyncAws has one
small package per service, so an application installs only what it uses. This keeps
deployments small, for example on AWS Lambda with [Bref](https://bref.sh).

- [Installation](#installation)
- [AsyncAws Configuration](#asyncaws-configuration)
- [AsyncAws Component](#asyncaws-component)
- [DynamoDB Session Handler](#dynamodb-session-handler)
- [DynamoDB Cache Engine](#dynamodb-cache-engine)
- [Amazon SES Email Transport](#amazon-ses-email-transport)
- [S3 Storage](#s3-storage)
- [Migrating from pieceofcake2/aws](#migrating-from-pieceofcake2-aws)
- [Testing](#testing)

## Installation

Install the plugin with [Composer](https://getcomposer.org/):

```bash
composer require pieceofcake2/async-aws
```

The plugin requires only `async-aws/core`. Install the AsyncAws package of each feature you use:

| Feature | Package |
| --- | --- |
| [DynamoDB session handler](#dynamodb-session-handler) | `async-aws/dynamo-db` |
| [DynamoDB cache engine](#dynamodb-cache-engine) | `async-aws/dynamo-db` |
| [Amazon SES email transport](#amazon-ses-email-transport) | `async-aws/ses` |
| [S3 storage](#s3-storage) | `async-aws/simple-s3` (installs `async-aws/s3`) |
| `$this->AsyncAws->S3`, `createS3()` | `async-aws/s3` |
| `$this->AsyncAws->SimpleS3`, `createSimpleS3()` | `async-aws/simple-s3` |
| `$this->AsyncAws->DynamoDb`, `Ses`, ... | `async-aws/dynamo-db`, `async-aws/ses`, ... |

For example:

```bash
# Sessions (and cache) in DynamoDB only
composer require pieceofcake2/async-aws async-aws/dynamo-db

# Email with Amazon SES only
composer require pieceofcake2/async-aws async-aws/ses

# Sessions in DynamoDB, email with SES and files in S3
composer require pieceofcake2/async-aws async-aws/dynamo-db async-aws/ses async-aws/simple-s3
```

When a feature is used without its package, a `MissingPackageException` (a `CacheException` for
the cache engine) is thrown with the package and the command to install it, for example:
"The DynamoDB session handler requires async-aws/dynamo-db. Install it with: composer require
async-aws/dynamo-db". To use another service with the component, install its package, for example
`composer require async-aws/sqs`.

The plugin has the `cakephp-plugin` type, so it is installed into your plugins directory
by `composer/installers`. Set `installer-paths` in your application's `composer.json` so it
is installed into the right place:

| Layout | `installer-paths` |
| --- | --- |
| Modern (`config/`, `src/`, `templates/`, `plugins/`) | `"plugins/{$name}/": ["type:cakephp-plugin"]` |
| Legacy (`app/Config/`, `app/Plugin/`) | `"app/Plugin/{$name}/": ["type:cakephp-plugin"]` |

Load the plugin in `config/bootstrap.php` (legacy layout: `app/Config/bootstrap.php`).
If you already use `CakePlugin::loadAll()`, this is not necessary:

```php
CakePlugin::load('AsyncAws');
```

The plugin has no bootstrap file.

## AsyncAws Configuration

All clients are created from the `AsyncAws` configuration. Set it in `config/core.php`
(legacy layout: `app/Config/core.php`), or in `config/bootstrap.php` before the plugin is
loaded:

```php
Configure::write('AsyncAws', [
    'region' => 'ap-northeast-1',
]);
```

When `AsyncAws` is not configured, the `Aws` configuration of pieceofcake2/aws is used, so an
application that switches plugins can keep its configuration.

The configuration accepts the options of AsyncAws (see
[Configuration](https://async-aws.com/configuration.html)), such as `region`, `endpoint`,
`profile`, `accessKeyId`, `accessKeySecret`, `sessionToken`, `pathStyleEndpoint` and `debug`.
These keys of the AWS SDK for PHP are converted:

| AWS SDK key | AsyncAws option |
| --- | --- |
| `credentials.key` | `accessKeyId` |
| `credentials.secret` | `accessKeySecret` |
| `credentials.token` | `sessionToken` |
| `use_path_style_endpoint` | `pathStyleEndpoint` |

Other keys that AsyncAws does not know, such as `version`, are ignored. Three keys take
objects and are passed to the clients' constructors:

| Key | Description |
| --- | --- |
| `httpClient` | A `Symfony\Contracts\HttpClient\HttpClientInterface`, e.g. a `MockHttpClient` in tests. |
| `credentialProvider` | An `AsyncAws\Core\Credentials\CredentialProvider`. |
| `logger` | A `Psr\Log\LoggerInterface`. |

### Credentials

When no credentials are configured, AsyncAws uses its default credential chain:
environment variables (`AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_SESSION_TOKEN`), a
web identity token (EKS), the shared configuration and credentials files (`~/.aws/config`,
`~/.aws/credentials`), the ECS container credentials, and the EC2 instance metadata service.
On AWS Lambda (and with Bref) the credentials of the function's role are in the environment
variables, so no configuration is needed. The region is also read from `AWS_REGION` or
`AWS_DEFAULT_REGION` when `region` is not configured.

## AsyncAws Component

The `AsyncAws.AsyncAws` component creates AsyncAws clients with the `AsyncAws`
configuration:

```php
class FilesController extends AppController
{
    public $components = ['AsyncAws.AsyncAws'];

    /**
     * Lists the objects of a bucket.
     *
     * @return void
     */
    public function index()
    {
        // An AsyncAws\S3\S3Client
        $s3 = $this->AsyncAws->S3;

        // The same, with options that override the AsyncAws configuration
        $s3 = $this->AsyncAws->createS3(['region' => 'us-east-1']);

        $objects = [];
        foreach ($s3->listObjectsV2(['Bucket' => 'my-bucket'])->getContents() as $object) {
            $objects[] = $object->getKey();
        }
        $this->set('objects', $objects);
    }
}
```

- Reading a property (`$this->AsyncAws->DynamoDb`) returns the client of
  `AsyncAws\Core\AwsClientFactory` (`dynamoDb()`), which is created once per component.
- `create<Service>($args)` creates a new client with `$args` merged into the configuration.
- `SesV2` is an alias of `Ses`, because AsyncAws's SES client uses the SES API v2.
- `SimpleS3` and `createSimpleS3($args)` return an `AsyncAws\SimpleS3\SimpleS3Client`.
- Any other method call is passed to the factory, for example `$this->AsyncAws->sqs()`.
  Install the service's package first (`async-aws/sqs`).
- `factory()` returns the `AwsClientFactory`.

AsyncAws clients send requests asynchronously: a request is sent when you call a method, and
the result is waited for when you read it (or when the result object is destroyed). Call
`resolve()` on a result to wait for it and throw on errors without reading anything. See
[Async](https://async-aws.com/features/async.html).

## DynamoDB Session Handler

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
    'timeout' => 1440,
    'ini' => [
        'session.cookie_lifetime' => 0,
        // Written to the "expires" attribute of each item
        'session.gc_maxlifetime' => 259200,
    ],
]);
```

- `handler.table_name` is required; a `CakeException` is thrown when it is missing.
- The table must exist, with a string partition key named `id`.
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
| `session_lifetime` | `session.gc_maxlifetime` | Seconds until a session expires. |
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

### Removing Expired Sessions

PHP's garbage collection does not remove sessions from DynamoDB, so `DynamoDbSession` turns it
off (`session.gc_probability = 0`). Enable
[Time to Live](https://docs.aws.amazon.com/amazondynamodb/latest/developerguide/TTL.html) on
the table with the `expires` attribute, and DynamoDB removes the expired sessions:

```bash
aws dynamodb update-time-to-live --table-name DYNAMODB_TABLE_NAME \
    --time-to-live-specification "Enabled=true,AttributeName=expires"
```

## DynamoDB Cache Engine

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

### Creating the Table

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

## Amazon SES Email Transport

`AsyncAws.AmazonSesApi` sends email with the `SendEmail` operation of the Amazon SES API v2.
Set it as the transport of an email profile in `config/email.php` (legacy layout:
`app/Config/email.php`):

```php
class EmailConfig
{
    /**
     * Default email profile values.
     *
     * @var array
     */
    public $default = [
        'transport' => 'AsyncAws.AmazonSesApi',
        'from' => 'example@example.com',
        'charset' => 'utf-8',
        'headerCharset' => 'utf-8',
    ];
}
```

The SES client is created with the `AsyncAws` configuration. These keys of the email profile
override it:

| Key | Description |
| --- | --- |
| `region` | AWS region of SES. |
| `key`, `secret` | Access key ID and secret access key. When they are not set, the credentials of the `AsyncAws` configuration or the default credential chain are used. |
| `token` | Session token, for temporary `key` and `secret`. |

The transport sends the text and HTML parts, the `To`, `Cc`, `Bcc` and `Reply-To`
addresses, and the attachments. `CakeEmail::send()` returns the SES message ID as `id`:

```php
App::uses('CakeEmail', 'Network/Email');

$email = new CakeEmail('default');
$result = $email->to('user@example.com')
    ->subject('Welcome')
    ->send('Hello!');

CakeLog::write(LOG_INFO, 'SES message ID: ' . $result['id']);
```

The sender address (`from`) must be verified in SES. When SES rejects the email, an
exception of AsyncAws is thrown, for example `AsyncAws\Ses\Exception\MessageRejectedException`.

## S3 Storage

AsyncAws has no S3 stream wrapper, so `s3://` paths cannot be used with PHP's file functions
(`file_get_contents()`, `fopen()`, ...) as with pieceofcake2/aws. The plugin does not provide
one: a stream wrapper has to emulate seeking, appending, directories and `stat()` on top of
S3, which is fragile. Use `S3Storage` or the AsyncAws S3 clients instead.

`S3Storage` takes the same `s3://bucket/key` paths and provides the common file operations
with [`async-aws/simple-s3`](https://async-aws.com/integration/simple-s3.html):

```php
App::uses('S3Storage', 'AsyncAws.Lib');

$storage = new S3Storage();

$storage->write('s3://my-bucket/reports/today.csv', $csv, ['ContentType' => 'text/csv']);
file_put_contents(TMP . 'today.csv', $storage->read('s3://my-bucket/reports/today.csv'));
```

| Method | Description |
| --- | --- |
| `write($path, $contents, $options = [])` | Uploads a string or a stream. Large objects are uploaded in parts. `$options` are those of `SimpleS3Client::upload()`, e.g. `ContentType`, `CacheControl`, `ACL`, `Metadata`. |
| `read($path)` | Returns the contents. Throws `AsyncAws\S3\Exception\NoSuchKeyException` when the object does not exist. |
| `readStream($path)` | Returns a stream (`resource`) of the contents. |
| `exists($path)` | Returns whether the object exists. |
| `delete($path)` | Deletes the object. |
| `url($path)` | Returns the URL of the object. |
| `presignedUrl($path, $expires = null)` | Returns a presigned download URL. `$expires` is a `DateTimeImmutable`. |
| `client()` | Returns the `SimpleS3Client`, for anything else. |

`new S3Storage()` creates the client with the `AsyncAws` configuration. Pass a
`SimpleS3Client` to use another one: `new S3Storage($this->AsyncAws->createSimpleS3($args))`.

## Migrating from pieceofcake2/aws

| pieceofcake2/aws | pieceofcake2/async-aws |
| --- | --- |
| `composer require pieceofcake2/aws` | `composer require pieceofcake2/async-aws` and the packages of the services you use, e.g. `async-aws/dynamo-db` (see [Installation](#installation)). |
| `CakePlugin::load('Aws')` | `CakePlugin::load('AsyncAws')` |
| `Configure::write('Aws', [...])` | `Configure::write('AsyncAws', [...])`, or keep `Aws` (used when `AsyncAws` is not set). `version` is ignored. |
| `'credentials' => ['key' => ..., 'secret' => ...]` | The same, or `accessKeyId` and `accessKeySecret`. |
| `public $components = ['Aws.Aws']` | `public $components = ['AsyncAws.AsyncAws']` |
| `$this->Aws->S3`, `$this->Aws->createS3($args)` | `$this->AsyncAws->S3`, `$this->AsyncAws->createS3($args)`. The clients are AsyncAws clients: their methods take the same parameters but return result objects with getters (`$result->getContents()`) instead of arrays (`$result['Contents']`). |
| `$this->Aws->createSesV2()` | `$this->AsyncAws->createSesV2()` or `createSes()` |
| `'engine' => 'Aws.DynamoDbSession'` | `'engine' => 'AsyncAws.DynamoDbSession'`. Same options and table. |
| `'engine' => 'Aws.DynamoDb'` (cache) | `'engine' => 'AsyncAws.DynamoDb'`. Same settings and table; `client` takes an AsyncAws client. |
| `'transport' => 'Aws.AmazonSesApi'` | `'transport' => 'AsyncAws.AmazonSesApi'`. Same keys, plus `token`. `version` is ignored. |
| S3 stream wrapper (`'bootstrap' => true`, `s3://` with file functions) | Not available. Use `S3Storage` (`file_get_contents('s3://b/k')` becomes `$storage->read('s3://b/k')`). |
| Errors: `Aws\Exception\AwsException` | Errors: `AsyncAws\Core\Exception\Exception` and the service exceptions, e.g. `AsyncAws\Ses\Exception\MessageRejectedException`. |

Services that AsyncAws does not support are not available. See the
[list of supported services](https://async-aws.com/clients/).

## Testing

AsyncAws clients send their requests with a [Symfony HTTP client](https://symfony.com/doc/current/http_client.html#testing),
so tests can replace it with a `MockHttpClient`. Put it in the `httpClient` key of the
configuration, and every client of the plugin uses it:

```php
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

Configure::write('AsyncAws', [
    'region' => 'ap-northeast-1',
    'accessKeyId' => 'test',
    'accessKeySecret' => 'test',
    'httpClient' => new MockHttpClient([
        new MockResponse('{"MessageId": "message-id"}'),
    ]),
]);
```

To test your own code that calls an AsyncAws client, you can also mock the client and create
its results with `AsyncAws\Core\Test\ResultMockFactory`; see
[Writing tests and mocking](https://async-aws.com/features/tests.html).

# AsyncAws Documentation

The AsyncAws plugin integrates [AsyncAws](https://async-aws.com) with CakePHP 2 applications
running on [pieceofcake2/cakephp](https://github.com/pieceofcake2/cakephp). It provides an
`AsyncAws` component that creates AsyncAws clients, a session handler and a cache engine that
store data in Amazon DynamoDB, an email transport that sends email with the Amazon SES API v2,
an S3 stream wrapper (`s3://` paths) and a small helper for S3 objects.

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
- [S3 Stream Wrapper](#s3-stream-wrapper)
- [S3 Storage](#s3-storage)
- [Migrating from pieceofcake2/aws](#migrating-from-pieceofcake2aws)
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
| [S3 stream wrapper](#s3-stream-wrapper) | `async-aws/simple-s3` (installs `async-aws/s3`) |
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

To also register the [S3 stream wrapper](#s3-stream-wrapper), load the plugin with its
bootstrap:

```php
CakePlugin::load('AsyncAws', ['bootstrap' => true]);
```

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

### Creating the Session Table

Create the table and enable Time to Live on the `expires` attribute with the AWS CLI:

```bash
aws dynamodb create-table --table-name DYNAMODB_TABLE_NAME \
    --attribute-definitions AttributeName=id,AttributeType=S \
    --key-schema AttributeName=id,KeyType=HASH \
    --billing-mode PAY_PER_REQUEST
aws dynamodb update-time-to-live --table-name DYNAMODB_TABLE_NAME \
    --time-to-live-specification "Enabled=true,AttributeName=expires"
```

### How Sessions Expire

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

### Garbage Collection

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

### DynamoDB Local

[DynamoDB Local](https://docs.aws.amazon.com/amazondynamodb/latest/developerguide/DynamoDBLocal.html)
accepts the Time to Live setting, but whether it deletes expired items depends on its version
(the current `amazon/dynamodb-local` image does). Expired sessions are always read as empty;
if they stay in the table, delete them by hand or turn on garbage collection in local
development as shown above.

`DynamoDbSession` sends every `UpdateItem` action explicitly, so sessions are stored in
DynamoDB Local as well.

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

## S3 Stream Wrapper

The S3 stream wrapper lets you use `s3://bucket/key` paths with PHP's file functions
(`file_get_contents()`, `file_put_contents()`, `fopen()`, `is_file()`, `scandir()`, ...). It is a
port of the stream wrapper of the AWS SDK for PHP (`Aws\S3\StreamWrapper`), which
pieceofcake2/aws registers, to AsyncAws, with the same scope and limits. It needs
`async-aws/simple-s3`.

Load the plugin with `'bootstrap' => true` to register it with the `AsyncAws` configuration (or
the `Aws` configuration, as the other features), or register it yourself:

```php
App::uses('S3StreamWrapper', 'AsyncAws.Lib');

// With the AsyncAws configuration
S3StreamWrapper::bootstrap();

// Or with a client
S3StreamWrapper::register($this->AsyncAws->createSimpleS3(['region' => 'us-east-1']));
```

When `async-aws/simple-s3` is not installed, the bootstrap logs a warning and does not register
the wrapper. Then:

```php
file_put_contents('s3://my-bucket/reports/today.csv', $csv);
echo file_get_contents('s3://my-bucket/reports/today.csv');

if (is_file('s3://my-bucket/reports/today.csv')) {
    echo filesize('s3://my-bucket/reports/today.csv');
}

// "today.csv", ...
foreach (scandir('s3://my-bucket/reports') as $name) {
    echo $name;
}
```

Options of the S3 operations can be passed with a stream context:

```php
$context = stream_context_create([
    's3' => [
        'ACL' => 'private',
        'ContentType' => 'text/csv',
        'CacheControl' => 'max-age=3600',
        'Metadata' => ['source' => 'reports'],
        'ServerSideEncryption' => 'AES256',
    ],
]);
file_put_contents('s3://my-bucket/reports/today.csv', $csv, 0, $context);
```

The options are passed to the AsyncAws operation (PutObject, GetObject, CopyObject, ...), which
uses those it knows: for example `VersionId` or `Range` for reading, and `VersionId` for
`unlink()`. These options are used by the wrapper itself:

| Option | Description |
| --- | --- |
| `seekable` | `true` makes a read stream seekable (see below). |
| `delimiter` | Delimiter of `opendir()`/`scandir()`. Default: `/`. An empty string lists all the keys under the prefix. |
| `listFilter` | A callable for `opendir()` that receives each entry (`Key`, `Size`, `LastModified`, ... or `Prefix`) and returns whether to list it. |
| `PartSize` | Part size of multipart uploads, in MB (default: 64). With `async-aws/simple-s3` 3.x, bodies larger than one part are uploaded in parts; 2.x (installed on PHP 8.1) uploads bodies up to 64 MB with PutObject whatever the part size. |

These functions are supported:

| Function | How |
| --- | --- |
| `fopen()` with `r` | GetObject. The body is streamed. |
| `fopen()` with `w`, `x` | The data is written to a `php://temp` buffer and uploaded when the stream is flushed (`fflush()`) or closed. Bodies larger than 64 MB are uploaded in parts. `x` fails when the object exists (HeadObject). |
| `fopen()` with `a` | The object is downloaded into the buffer (or the buffer starts empty when the object does not exist), and the result is uploaded. |
| `fread()`, `fwrite()`, `feof()`, `ftell()`, `fseek()`, `fflush()`, `fclose()`, `fstat()` | On the stream or its buffer. |
| `file_exists()`, `is_file()`, `is_dir()`, `filesize()`, `filemtime()`, `stat()`, ... | HeadObject. When the key is not an object, a prefix with objects (ListObjectsV2) is a directory. Buckets and `s3://` are directories. Files and directories have the mode `0777`. |
| `unlink()` | DeleteObject. |
| `rename()` | CopyObject (with the metadata) and DeleteObject, in a bucket or between buckets. |
| `copy()` | Reads and writes the object. |
| `mkdir()` | `s3://bucket` creates the bucket in the region of the client. `s3://bucket/dir` creates an empty `dir/` object. |
| `rmdir()` | `s3://bucket` deletes the bucket. `s3://bucket/dir` deletes the `dir/` object when nothing else has the prefix. |
| `opendir()`, `readdir()`, `rewinddir()`, `closedir()`, `scandir()` | ListObjectsV2 with the delimiter, page by page. `dir/` objects are skipped. |

### Limitations

These are the limitations of the stream wrapper of the AWS SDK for PHP, which this wrapper
keeps:

- Only the modes `r`, `w`, `a` and `x` are supported (`r+`, `w+`, `c`, ... are not), so a
  stream either reads or writes.
- A read stream (`r`) cannot seek, unless the `seekable` context option is `true`. Then the
  bytes read are kept in a `php://temp` buffer, so that the stream can seek backward and forward.
- Writes are uploaded when the stream is flushed or closed, not while you write. Each
  `fflush()` uploads the whole buffer again. An upload error triggers a warning, but
  `fclose()` and `file_put_contents()` cannot report it, so call `fflush()` and check its
  result when it matters.
- `a` downloads the whole object, and uploads it again when the stream is closed. Two processes
  that append at the same time overwrite each other's changes.
- `touch()`, `chmod()`, `chown()`, `flock()` (and `file_put_contents()` with `LOCK_EX`) and
  `ftruncate()` are not supported.
- Directories are emulated with key prefixes. `mkdir()` ignores `recursive`. `rmdir()` of a
  prefix deletes only its `dir/` object, and fails when the prefix has other objects; it
  returns `true` when the prefix has no objects at all.
- `mkdir()` without an `ACL` context option sends the ACL of the mode, as the AWS SDK does:
  `public-read` for `07xx` (including the default `0777`), `authenticated-read` for `06xx`, and
  `private` otherwise. Buckets with the "Bucket owner enforced" object ownership setting (the
  default for new buckets) accept only the `bucket-owner-full-control` ACL, so pass it as the
  `ACL` context option there.
- `rename()` copies the object with one CopyObject request, so it is limited to objects up to
  5 GB (the AWS SDK switches to a multipart copy above 5 GB). It is not atomic: the source is
  deleted after the copy.
- The results of `url_stat()` and the entries listed by `readdir()` are cached in the process
  (up to 1000 paths), and the wrapper clears them when it changes a path. Call
  `S3StreamWrapper::clearCache()` when other processes change the objects. PHP's `clearstatcache()`
  does not clear this cache.
- A read stream receives the body through AsyncAws, which keeps a copy of the bytes received in a
  `php://temp` stream (in memory up to 2 MB, then in a temporary file).
- Errors trigger an `E_USER_WARNING` and the function returns `false`. `file_exists()`,
  `is_file()` and the like report missing paths without a warning.
- The wrapper is registered with `STREAM_IS_URL`, so it needs `allow_url_fopen`, and paths
  cannot be included (`include 's3://...'`).

These differ from the AWS SDK:

- `opendir()` sends the first ListObjectsV2 request when the directory is opened, so a missing
  bucket makes `opendir()` and `scandir()` fail with a warning. An error on a later page makes
  `readdir()` return `false` with a warning instead of throwing an exception.
- `rmdir()` of a prefix also fails when the prefix has a `dir/` object and other objects (the
  AWS SDK deletes the `dir/` object then).
- `rename()` sends an ACL only when the `ACL` (or `acl`) context option is set. The AWS SDK
  always sends one (`private` by default), and buckets with ACLs disabled accept only
  `bucket-owner-full-control`.
- `a` treats only a missing object (404) as a new object; other errors make `fopen()` fail.
- `fstat()` returns the file mode `0100777`.
- Uploads with an `async-aws/s3` client (`S3StreamWrapper::register(new S3Client(...))`) use
  PutObject, up to 5 GB, as the AWS SDK. With an `async-aws/simple-s3` client (the default),
  bodies larger than 64 MB (`PartSize`) are uploaded in parts. The content type of a key is
  guessed from its extension with CakePHP's list of MIME types, when `ContentType` is not set.

### CakePHP's File and Folder Classes

CakePHP 2's `File` and `Folder` classes do not work with `s3://` paths, with this wrapper or with
the AWS SDK's: `Folder::isAbsolute()` recognizes the protocol of a stream wrapper only when it
has letters only, so `s3://bucket/dir` is handled as a relative path. With a protocol of letters
only, for example `S3StreamWrapper::register($client, 'awss')`, `Folder::read()`, `find()` and
`new Folder($path, true)` work, and `File` reads, writes, appends to and deletes existing objects.
`File::write()` and `File::create()` cannot create an object, because `File::create()` calls
`touch()`. Use `file_put_contents()` to create objects.

## S3 Storage

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
| S3 stream wrapper (`CakePlugin::load('Aws', ['bootstrap' => true])`, `s3://` with file functions) | `CakePlugin::load('AsyncAws', ['bootstrap' => true])` and `composer require async-aws/simple-s3`. The same functions, context options and limits; see [S3 Stream Wrapper](#s3-stream-wrapper). Or use `S3Storage`. |
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

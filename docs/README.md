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
- [AsyncAws Component](component.md)
- [DynamoDB Session Handler](session.md)
- [DynamoDB Cache Engine](cache.md)
- [Amazon SES Email Transport](email.md)
- [S3](s3.md): the S3 stream wrapper and S3 storage
- [Migrating from pieceofcake2/aws](migrating-from-aws.md)
- [Testing](testing.md)

## Installation

Install the plugin with [Composer](https://getcomposer.org/):

```bash
composer require pieceofcake2/async-aws
```

The plugin requires only `async-aws/core`. Install the AsyncAws package of each feature you use:

| Feature | Package |
| --- | --- |
| [DynamoDB session handler](session.md) | `async-aws/dynamo-db` |
| [DynamoDB cache engine](cache.md) | `async-aws/dynamo-db` |
| [Amazon SES email transport](email.md) | `async-aws/ses` |
| [S3 stream wrapper](s3.md#s3-stream-wrapper) | `async-aws/simple-s3` (installs `async-aws/s3`) |
| [S3 storage](s3.md#s3-storage) | `async-aws/simple-s3` (installs `async-aws/s3`) |
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

To also register the [S3 stream wrapper](s3.md#s3-stream-wrapper) and the
[Amazon SES mailer DSNs](email.md#mailer-dsn), load the plugin with its bootstrap:

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

## Origin

This package was created by [pieceofcake2](https://github.com/pieceofcake2), which also maintains
it. It is not a fork of another project and is not based on one.

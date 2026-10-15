# Migrating from pieceofcake2/aws

| pieceofcake2/aws | pieceofcake2/async-aws |
| --- | --- |
| `composer require pieceofcake2/aws` | `composer require pieceofcake2/async-aws` and the packages of the services you use, e.g. `async-aws/dynamo-db` (see [Installation](README.md#installation)). |
| `CakePlugin::load('Aws')` | `CakePlugin::load('AsyncAws')` |
| `Configure::write('Aws', [...])` | `Configure::write('AsyncAws', [...])`, or keep `Aws` (used when `AsyncAws` is not set). `version` is ignored. |
| `'credentials' => ['key' => ..., 'secret' => ...]` | The same, or `accessKeyId` and `accessKeySecret`. |
| `public $components = ['Aws.Aws']` | `public $components = ['AsyncAws.AsyncAws']` |
| `$this->Aws->S3`, `$this->Aws->createS3($args)` | `$this->AsyncAws->S3`, `$this->AsyncAws->createS3($args)`. The clients are AsyncAws clients: their methods take the same parameters but return result objects with getters (`$result->getContents()`) instead of arrays (`$result['Contents']`). |
| `$this->Aws->createSesV2()` | `$this->AsyncAws->createSesV2()` or `createSes()` |
| `'engine' => 'Aws.DynamoDbSession'` | `'engine' => 'AsyncAws.DynamoDbSession'`. Same options and table. |
| `'engine' => 'Aws.DynamoDb'` (cache) | `'engine' => 'AsyncAws.DynamoDb'`. Same settings and table; `client` takes an AsyncAws client. |
| `'transport' => 'Aws.AmazonSesApi'` | `'transport' => 'AsyncAws.AmazonSesApi'`. Same keys, plus `token`. `version` is ignored. |
| S3 stream wrapper (`CakePlugin::load('Aws', ['bootstrap' => true])`, `s3://` with file functions) | `CakePlugin::load('AsyncAws', ['bootstrap' => true])` and `composer require async-aws/simple-s3`. The same functions, context options and limits; see [S3 Stream Wrapper](s3.md#s3-stream-wrapper). Or use `S3Storage`. |
| Errors: `Aws\Exception\AwsException` | Errors: `AsyncAws\Core\Exception\Exception` and the service exceptions, e.g. `AsyncAws\Ses\Exception\MessageRejectedException`. |

Services that AsyncAws does not support are not available. See the
[list of supported services](https://async-aws.com/clients/).

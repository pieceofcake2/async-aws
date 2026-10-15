# pieceofcake2/aws からの移行

| pieceofcake2/aws | pieceofcake2/async-aws |
| --- | --- |
| `composer require pieceofcake2/aws` | `composer require pieceofcake2/async-aws` と、使用するサービスのパッケージ。例: `async-aws/dynamo-db` ([インストール](README.md#installation) を参照)。 |
| `CakePlugin::load('Aws')` | `CakePlugin::load('AsyncAws')` |
| `Configure::write('Aws', [...])` | `Configure::write('AsyncAws', [...])`。または `Aws` のままにします (`AsyncAws` が設定されていない場合に使われます)。`version` は無視されます。 |
| `'credentials' => ['key' => ..., 'secret' => ...]` | 同じです。または `accessKeyId` と `accessKeySecret`。 |
| `public $components = ['Aws.Aws']` | `public $components = ['AsyncAws.AsyncAws']` |
| `$this->Aws->S3`、`$this->Aws->createS3($args)` | `$this->AsyncAws->S3`、`$this->AsyncAws->createS3($args)`。クライアントは AsyncAws のクライアントです。メソッドは同じパラメータを受け取りますが、配列 (`$result['Contents']`) ではなくゲッターを持つ結果オブジェクト (`$result->getContents()`) を返します。 |
| `$this->Aws->createSesV2()` | `$this->AsyncAws->createSesV2()` または `createSes()` |
| `'engine' => 'Aws.DynamoDbSession'` | `'engine' => 'AsyncAws.DynamoDbSession'`。オプションとテーブルは同じです。 |
| `'engine' => 'Aws.DynamoDb'` (キャッシュ) | `'engine' => 'AsyncAws.DynamoDb'`。設定とテーブルは同じです。`client` には AsyncAws のクライアントを渡します。 |
| `'transport' => 'Aws.AmazonSesApi'` | `'transport' => 'AsyncAws.AmazonSesApi'`。キーは同じで、`token` が加わります。`version` は無視されます。 |
| S3 ストリームラッパー (`CakePlugin::load('Aws', ['bootstrap' => true])`、ファイル関数での `s3://`) | `CakePlugin::load('AsyncAws', ['bootstrap' => true])` と `composer require async-aws/simple-s3`。関数、コンテキストオプション、制限は同じです。[S3 ストリームラッパー](s3.md#s3-stream-wrapper) を参照してください。または `S3Storage` を使います。 |
| エラー: `Aws\Exception\AwsException` | エラー: `AsyncAws\Core\Exception\Exception` と各サービスの例外。例: `AsyncAws\Ses\Exception\MessageRejectedException`。 |

AsyncAws がサポートしていないサービスは使えません。
[サポートされているサービスの一覧](https://async-aws.com/clients/) を参照してください。

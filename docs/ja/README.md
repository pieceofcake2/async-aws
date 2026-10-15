# AsyncAws ドキュメント

AsyncAws プラグインは、[AsyncAws](https://async-aws.com) を
[pieceofcake2/cakephp](https://github.com/pieceofcake2/cakephp) で動作する CakePHP 2 アプリケーションに統合します。
AsyncAws クライアントを作成する `AsyncAws` コンポーネント、Amazon DynamoDB にデータを保存するセッションハンドラーとキャッシュエンジン、
Amazon SES API v2 でメールを送信するメールトランスポート、S3 ストリームラッパー (`s3://` パス)、
そして S3 オブジェクト用の小さなヘルパーを提供します。

このプラグインは、完全な [AWS SDK for PHP](https://github.com/aws/aws-sdk-php) を使う
[pieceofcake2/aws](https://github.com/pieceofcake2/aws) の AsyncAws 版です。
AsyncAws はサービスごとに小さなパッケージに分かれているため、アプリケーションは使うものだけをインストールできます。
これにより、たとえば [Bref](https://bref.sh) を使った AWS Lambda などで、デプロイするサイズを小さく保てます。

- [インストール](#installation)
- [AsyncAws の設定](#asyncaws-configuration)
- [AsyncAws コンポーネント](component.md)
- [DynamoDB セッションハンドラー](session.md)
- [DynamoDB キャッシュエンジン](cache.md)
- [Amazon SES メールトランスポート](email.md)
- [S3](s3.md): S3 ストリームラッパーと S3 ストレージ
- [pieceofcake2/aws からの移行](migrating-from-aws.md)
- [テスト](testing.md)

<a id="installation"></a>

## インストール

[Composer](https://getcomposer.org/) でプラグインをインストールします。

```bash
composer require pieceofcake2/async-aws
```

このプラグインが必要とするのは `async-aws/core` だけです。使用する機能ごとに、その AsyncAws パッケージをインストールしてください。

| 機能 | パッケージ |
| --- | --- |
| [DynamoDB セッションハンドラー](session.md) | `async-aws/dynamo-db` |
| [DynamoDB キャッシュエンジン](cache.md) | `async-aws/dynamo-db` |
| [Amazon SES メールトランスポート](email.md) | `async-aws/ses` |
| [S3 ストリームラッパー](s3.md#s3-stream-wrapper) | `async-aws/simple-s3` (`async-aws/s3` もインストールされます) |
| [S3 ストレージ](s3.md#s3-storage) | `async-aws/simple-s3` (`async-aws/s3` もインストールされます) |
| `$this->AsyncAws->S3`、`createS3()` | `async-aws/s3` |
| `$this->AsyncAws->SimpleS3`、`createSimpleS3()` | `async-aws/simple-s3` |
| `$this->AsyncAws->DynamoDb`、`Ses`、... | `async-aws/dynamo-db`、`async-aws/ses`、... |

例:

```bash
# Sessions (and cache) in DynamoDB only
composer require pieceofcake2/async-aws async-aws/dynamo-db

# Email with Amazon SES only
composer require pieceofcake2/async-aws async-aws/ses

# Sessions in DynamoDB, email with SES and files in S3
composer require pieceofcake2/async-aws async-aws/dynamo-db async-aws/ses async-aws/simple-s3
```

パッケージをインストールせずに機能を使うと、`MissingPackageException` (キャッシュエンジンでは `CacheException`) が投げられます。
そのメッセージには、必要なパッケージとインストールするコマンドが示されます。例:
"The DynamoDB session handler requires async-aws/dynamo-db. Install it with: composer require
async-aws/dynamo-db"。
コンポーネントで別のサービスを使うには、そのパッケージをインストールしてください。例: `composer require async-aws/sqs`。

このプラグインは `cakephp-plugin` タイプなので、`composer/installers` によってプラグインディレクトリにインストールされます。
正しい場所にインストールされるよう、アプリケーションの `composer.json` で `installer-paths` を設定してください。

| レイアウト | `installer-paths` |
| --- | --- |
| モダン (`config/`、`src/`、`templates/`、`plugins/`) | `"plugins/{$name}/": ["type:cakephp-plugin"]` |
| レガシー (`app/Config/`、`app/Plugin/`) | `"app/Plugin/{$name}/": ["type:cakephp-plugin"]` |

`config/bootstrap.php` (レガシーレイアウトでは `app/Config/bootstrap.php`) でプラグインを読み込みます。
すでに `CakePlugin::loadAll()` を使っている場合、これは不要です。

```php
CakePlugin::load('AsyncAws');
```

[S3 ストリームラッパー](s3.md#s3-stream-wrapper) も登録するには、プラグインをブートストラップ付きで読み込みます。

```php
CakePlugin::load('AsyncAws', ['bootstrap' => true]);
```

<a id="asyncaws-configuration"></a>

## AsyncAws の設定

すべてのクライアントは `AsyncAws` 設定から作成されます。
`config/core.php` (レガシーレイアウトでは `app/Config/core.php`) で設定するか、
プラグインを読み込む前に `config/bootstrap.php` で設定してください。

```php
Configure::write('AsyncAws', [
    'region' => 'ap-northeast-1',
]);
```

`AsyncAws` が設定されていない場合は pieceofcake2/aws の `Aws` 設定が使われるため、
プラグインを切り替えるアプリケーションは設定をそのまま使えます。

設定には、`region`、`endpoint`、`profile`、`accessKeyId`、`accessKeySecret`、`sessionToken`、
`pathStyleEndpoint`、`debug` などの AsyncAws のオプションを指定できます
([Configuration](https://async-aws.com/configuration.html) を参照)。
AWS SDK for PHP の次のキーは変換されます。

| AWS SDK のキー | AsyncAws のオプション |
| --- | --- |
| `credentials.key` | `accessKeyId` |
| `credentials.secret` | `accessKeySecret` |
| `credentials.token` | `sessionToken` |
| `use_path_style_endpoint` | `pathStyleEndpoint` |

`version` など AsyncAws が知らないその他のキーは無視されます。
次の 3 つのキーはオブジェクトを受け取り、クライアントのコンストラクターに渡されます。

| キー | 説明 |
| --- | --- |
| `httpClient` | `Symfony\Contracts\HttpClient\HttpClientInterface`。たとえばテストでの `MockHttpClient`。 |
| `credentialProvider` | `AsyncAws\Core\Credentials\CredentialProvider`。 |
| `logger` | `Psr\Log\LoggerInterface`。 |

<a id="credentials"></a>

### 認証情報

認証情報が設定されていない場合、AsyncAws はデフォルトの認証情報チェーンを使います。
環境変数 (`AWS_ACCESS_KEY_ID`、`AWS_SECRET_ACCESS_KEY`、`AWS_SESSION_TOKEN`)、
Web ID トークン (EKS)、共有の設定ファイルと認証情報ファイル (`~/.aws/config`、`~/.aws/credentials`)、
ECS コンテナの認証情報、EC2 インスタンスメタデータサービスの順です。
AWS Lambda (および Bref) では関数のロールの認証情報が環境変数にあるため、設定は不要です。
`region` が設定されていない場合、リージョンも `AWS_REGION` または `AWS_DEFAULT_REGION` から読み込まれます。

<a id="origin"></a>

## 由来

このパッケージは [pieceofcake2](https://github.com/pieceofcake2) が作成し、メンテナンスしています。
他のプロジェクトのフォークではなく、他のプロジェクトをもとにしたものでもありません。

# Amazon SES メールトランスポート

`AsyncAws.AmazonSesApi` は、Amazon SES API v2 の `SendEmail` オペレーションでメールを送信します。
`config/email.php` (レガシーレイアウトでは `app/Config/email.php`) で、メールプロファイルのトランスポートとして設定します。

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

SES クライアントは `AsyncAws` 設定から作成されます。メールプロファイルの次のキーで、その設定を上書きできます。

| キー | 説明 |
| --- | --- |
| `region` | SES の AWS リージョン。 |
| `key`、`secret` | アクセスキー ID とシークレットアクセスキー。設定されていない場合は、`AsyncAws` 設定の認証情報、またはデフォルトの認証情報チェーンが使われます。 |
| `token` | 一時的な `key` と `secret` のためのセッショントークン。 |
| `endpoint` | SES のエンドポイント URL (ローカルのエミュレーター用など)。 |

このトランスポートは、テキストと HTML のパート、`To`、`Cc`、`Bcc`、`Reply-To` のアドレス、添付ファイルを送信します。
`CakeEmail::send()` は SES のメッセージ ID を `id` として返します。

```php
App::uses('CakeEmail', 'Network/Email');

$email = new CakeEmail('default');
$result = $email->to('user@example.com')
    ->subject('Welcome')
    ->send('Hello!');

CakeLog::write(LOG_INFO, 'SES message ID: ' . $result['id']);
```

送信元アドレス (`from`) は SES で検証済みである必要があります。SES がメールを拒否すると、
AsyncAws の例外が投げられます。たとえば `AsyncAws\Ses\Exception\MessageRejectedException` です。

<a id="mailer-dsn"></a>

## メーラーの DSN

メールプロファイルの `'dsn'` キーに対応した pieceofcake2/cakephp のリリース (v2.12.6 の次のリリース) では、
[Symfony の Amazon Mailer ブリッジ](https://symfony.com/doc/current/mailer.html#using-a-3rd-party-transport)
の形式の DSN で、たとえば環境変数 `MAILER_DSN` からトランスポートを設定できます。プラグインを
ブートストラップ付きで読み込むと、`CakeEmail::setDsnClassMap()` で `ses` のスキームが登録されます
(async-aws/simple-s3 がインストールされていれば [S3 ストリームラッパー](s3.md) も登録されます)。

```php
// config/bootstrap.php
CakePlugin::load('AsyncAws', ['bootstrap' => true]);
```

```php
class EmailConfig
{
    /**
     * Default email profile values.
     *
     * @var array
     */
    public $default = [
        'transport' => 'Mail',
        'from' => 'example@example.com',
        'dsn' => null,
    ];

    /**
     * Reads the transport from MAILER_DSN.
     */
    public function __construct()
    {
        $this->default['dsn'] = env('MAILER_DSN');
    }
}
```

| DSN | 設定 |
| --- | --- |
| `ses+api://ACCESS_KEY:SECRET_KEY@default?region=eu-west-1` | `AsyncAws.AmazonSesApi`、`key`、`secret`、`region` |
| `ses+https://ACCESS_KEY:SECRET_KEY@default`、`ses://...` | 同じ (`AsyncAws.AmazonSesApi`) |
| `ses+api://default?region=eu-west-1` | `AsyncAws` 設定の認証情報か、デフォルトの認証情報チェーンを使う `AsyncAws.AmazonSesApi` |
| `ses+smtp://USERNAME:PASSWORD@default?region=eu-west-1`、`ses+smtps://...` | SES の SMTP 認証情報で `ssl://email-smtp.eu-west-1.amazonaws.com` のポート 465 に接続するコアの `Smtp` トランスポート |

- `?session_token=...` は、一時的な認証情報のセッショントークン `token` を設定します。
- `default` 以外のホストはエンドポイントになります (`ses+api://KEY:SECRET@localhost:4566` は
  `https://localhost:4566` を使います)。`ses+smtp` では SMTP のホストになります。
- DSN に `region` がない場合は、プロファイルの `region`、`AsyncAws` 設定 (または `Aws` 設定) の `region`、`AWS_REGION`、
  `AWS_DEFAULT_REGION` の順に使われ、どれも設定されていなければ `eu-west-1` (Symfony のデフォルト) になります。
- AWS のシークレットキーにある `/` と `+` はそのままでかまいません。`%` は `%25`、`?` は `%3F` とエンコードしてください。
- `ses+api` と `ses+https` はどちらも、このトランスポートの SES API v2 の `SendEmail` オペレーションを使います。
  Symfony での区別 (シンプルなメッセージと raw メッセージ) はありません。`ses+smtp` の `ping_threshold` は無視されます。
- DSN から読み取ったキーはプロファイルの同じキーを置き換え、ほかのキー (`from`、`charset` など) はそのまま残ります。
  `MAILER_DSN` が設定されていなければ無視されます。
- DSN に対応していない pieceofcake2/cakephp のリリースでは、ブートストラップは登録を行わず、`'dsn'` キーは無視されます。

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

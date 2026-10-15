# Amazon SES Email Transport

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

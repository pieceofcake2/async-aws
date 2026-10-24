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
| `endpoint` | Endpoint URL of SES, e.g. for a local emulator. |

The transport sends the text and HTML parts, the `To`, `Cc`, `Bcc` and `Reply-To`
addresses, and the attachments. `CakeEmail::send()` returns the SES message ID as `id`:

```php
$email = new CakeEmail('default');
$result = $email->to('user@example.com')
    ->subject('Welcome')
    ->send('Hello!');

CakeLog::write(LOG_INFO, 'SES message ID: ' . $result['id']);
```

The sender address (`from`) must be verified in SES. When SES rejects the email, an
exception of AsyncAws is thrown, for example `AsyncAws\Ses\Exception\MessageRejectedException`.

## Mailer DSN

With pieceofcake2/cakephp 2.12.7 or later, which supports the `'dsn'` key of email profiles,
the transport can be set with a DSN in the format of
[Symfony's Amazon Mailer bridge](https://symfony.com/doc/current/mailer.html#using-a-3rd-party-transport),
for example from the `MAILER_DSN` environment variable. Load the plugin with its bootstrap,
which registers the `ses` schemes with `CakeEmail::setDsnClassMap()` (and also the
[S3 stream wrapper](s3.md) when async-aws/simple-s3 is installed):

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

| DSN | Configuration |
| --- | --- |
| `ses+api://ACCESS_KEY:SECRET_KEY@default?region=eu-west-1` | `AsyncAws.AmazonSesApi`, `key`, `secret`, `region` |
| `ses+https://ACCESS_KEY:SECRET_KEY@default`, `ses://...` | the same (`AsyncAws.AmazonSesApi`) |
| `ses+api://default?region=eu-west-1` | `AsyncAws.AmazonSesApi` with the credentials of the `AsyncAws` configuration or the default credential chain |
| `ses+smtp://USERNAME:PASSWORD@default?region=eu-west-1`, `ses+smtps://...` | the core `Smtp` transport to `ssl://email-smtp.eu-west-1.amazonaws.com`, port 465, with the SES SMTP credentials |

- `?session_token=...` sets `token`, the session token of temporary credentials.
- A host other than `default` is the endpoint (`ses+api://KEY:SECRET@localhost:4566` uses
  `https://localhost:4566`), or the SMTP host for `ses+smtp`.
- When the DSN has no `region`, the `region` of the profile, the `region` of the `AsyncAws`
  configuration (or `Aws`), `AWS_REGION` or `AWS_DEFAULT_REGION` is used, and `eu-west-1` (the default of
  Symfony) when none is set.
- The `/` and `+` of AWS secret keys may stay as they are; encode `%` as `%25` and `?` as `%3F`.
- `ses+api` and `ses+https` both use the SES API v2 `SendEmail` operation of this transport;
  Symfony's distinction (simple vs. raw message) does not apply. `ping_threshold` of
  `ses+smtp` is ignored.
- The keys parsed from the DSN replace the same keys of the profile; the other keys (`from`,
  `charset`, ...) are kept. An unset `MAILER_DSN` is ignored.
- With a pieceofcake2/cakephp release without DSN support, the bootstrap skips the
  registration and the `'dsn'` key is ignored.

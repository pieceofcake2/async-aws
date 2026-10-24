<?php

/**
 * Mailer DSN schemes of Amazon SES, in the format of Symfony's Amazon Mailer bridge.
 *
 * `register()` (called by the plugin bootstrap) adds them to `CakeEmail::setDsnClassMap()`, so an
 * email profile can set its transport with `'dsn' => env('MAILER_DSN')`:
 *
 * | DSN | Configuration |
 * | --- | --- |
 * | `ses+api://ACCESS_KEY:SECRET_KEY@default?region=eu-west-1` | `AsyncAws.AmazonSesApi` transport |
 * | `ses+https://ACCESS_KEY:SECRET_KEY@default`, `ses://...` | `AsyncAws.AmazonSesApi` transport |
 * | `ses+smtp://USERNAME:PASSWORD@default?region=eu-west-1`, `ses+smtps://...` | `Smtp` transport to `ssl://email-smtp.<region>.amazonaws.com:465` |
 */
class AsyncAwsSesDsn
{
    /**
     * The transport of the API schemes.
     *
     * @var string
     */
    public const API_TRANSPORT = 'AsyncAws.AmazonSesApi';

    /**
     * The region of Symfony's Amazon Mailer bridge when no region is configured.
     *
     * @var string
     */
    public const DEFAULT_REGION = 'eu-west-1';

    /**
     * Registers the `ses`, `ses+api`, `ses+https`, `ses+smtp` and `ses+smtps` schemes with
     * `CakeEmail::setDsnClassMap()`.
     *
     * @param callable|null $setDsnClassMap The function that registers the map. Default: `CakeEmail::setDsnClassMap()`.
     * @return bool Always `true`.
     */
    public static function register(?callable $setDsnClassMap = null): bool
    {
        $setDsnClassMap = $setDsnClassMap ?? [CakeEmail::class, 'setDsnClassMap'];
        $setDsnClassMap(static::classMap());

        return true;
    }

    /**
     * Returns the schemes and their callables.
     *
     * @return array<string, callable>
     */
    public static function classMap(): array
    {
        $api = [static::class, 'api'];
        $smtp = [static::class, 'smtp'];

        return [
            'ses' => $api,
            'ses+api' => $api,
            'ses+https' => $api,
            'ses+smtp' => $smtp,
            'ses+smtps' => $smtp,
        ];
    }

    /**
     * Maps an API DSN (`ses+api://ACCESS_KEY:SECRET_KEY@default?region=...&session_token=...`) to the keys
     * of the `AsyncAws.AmazonSesApi` transport.
     *
     * - The user and password are the access key ID and secret access key (`key`, `secret`). Without them,
     *   the credentials of the `AsyncAws` configuration or the default credential chain are used.
     * - `region` and `session_token` become `region` and `token`. Other query parameters are kept.
     * - A host other than `default` is the endpoint (`https://host[:port]`).
     *
     * @param array $parts The parts of the DSN (`CakeDsn::parse()`).
     * @param array $config The email configuration without the `dsn` key.
     * @return array The email configuration keys.
     */
    public static function api(array $parts, array $config): array
    {
        $query = $parts['query'] ?? [];
        $result = ['transport' => static::API_TRANSPORT];
        if (isset($parts['username']) && $parts['username'] !== '') {
            $result['key'] = $parts['username'];
            $result['secret'] = $parts['password'] ?? '';
        }
        if (!empty($query['session_token'])) {
            $result['token'] = $query['session_token'];
        }
        $result['region'] = static::region($query, $config);
        if (isset($parts['host']) && $parts['host'] !== 'default') {
            $result['endpoint'] = 'https://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
        }
        unset($query['region'], $query['session_token']);

        return $query + $result;
    }

    /**
     * Maps an SMTP DSN (`ses+smtp://USERNAME:PASSWORD@default?region=...`) to the keys of the `Smtp`
     * transport: the SMTP interface of SES over implicit TLS, `ssl://email-smtp.<region>.amazonaws.com`
     * on port 465. A host other than `default` replaces the SES host, and a port the port. The user and
     * password are the SMTP credentials of SES. `ping_threshold` (Symfony only) is ignored; other query
     * parameters are kept.
     *
     * @param array $parts The parts of the DSN (`CakeDsn::parse()`).
     * @param array $config The email configuration without the `dsn` key.
     * @return array The email configuration keys.
     */
    public static function smtp(array $parts, array $config): array
    {
        $query = $parts['query'] ?? [];
        $host = isset($parts['host']) && $parts['host'] !== 'default'
            ? $parts['host']
            : sprintf('email-smtp.%s.amazonaws.com', static::region($query, $config));
        $result = [
            'transport' => 'Smtp',
            'host' => 'ssl://' . $host,
            'port' => $parts['port'] ?? 465,
            'tls' => false,
        ];
        if (isset($parts['username']) && $parts['username'] !== '') {
            $result['username'] = $parts['username'];
            $result['password'] = $parts['password'] ?? '';
        }
        unset($query['region'], $query['ping_threshold'], $query['session_token']);

        return $query + $result;
    }

    /**
     * Returns the region: the `region` query parameter, the `region` of the email profile, the `region` of the
     * plugin configuration (`AsyncAws`, or `Aws` when it is not set), the `AWS_REGION` or `AWS_DEFAULT_REGION`
     * environment variable, or `eu-west-1` (the default of Symfony's Amazon Mailer bridge).
     *
     * @param array $query The query parameters of the DSN.
     * @param array $config The email configuration.
     * @return string
     */
    protected static function region(array $query, array $config): string
    {
        $candidates = [
            $query['region'] ?? null,
            $config['region'] ?? null,
            AsyncAwsComponent::config()['region'] ?? null,
            env('AWS_REGION'),
            env('AWS_DEFAULT_REGION'),
        ];
        foreach ($candidates as $region) {
            if (is_string($region) && $region !== '') {
                return $region;
            }
        }

        return static::DEFAULT_REGION;
    }
}

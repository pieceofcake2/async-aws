<?php

use AsyncAws\Core\AwsClientFactory;
use AsyncAws\Core\Configuration;
use AsyncAws\Core\Credentials\CredentialProvider;
use AsyncAws\SimpleS3\SimpleS3Client;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

App::uses('Component', 'Controller');
App::uses('Configure', 'Core');

/**
 * AsyncAws Component
 *
 * Creates AsyncAws clients from an `AsyncAws\Core\AwsClientFactory` configured with `Configure::read('AsyncAws')`.
 * When `AsyncAws` is not configured, the `Aws` configuration of the pieceofcake2/aws plugin is used instead.
 *
 * `$this->AsyncAws->S3` and `$this->AsyncAws->createS3($args)` both return an `AsyncAws\S3\S3Client`.
 *
 * The configuration accepts the options of `AsyncAws\Core\Configuration` (`region`, `endpoint`, `accessKeyId`, ...)
 * and the AWS SDK style keys `credentials` (`key`, `secret`, `token`) and `use_path_style_endpoint`.
 * Keys that AsyncAws does not know, such as `version`, are ignored.
 * The special keys `httpClient`, `credentialProvider` and `logger` are passed to the factory as objects.
 *
 * When no credentials are configured, AsyncAws uses its default credential chain: environment variables,
 * web identity token, shared configuration files, ECS container credentials and EC2 instance metadata.
 *
 * @method \AsyncAws\DynamoDb\DynamoDbClient dynamoDb()
 * @method \AsyncAws\S3\S3Client s3()
 * @method \AsyncAws\Ses\SesClient ses()
 * @method \AsyncAws\DynamoDb\DynamoDbClient createDynamoDb(array $args = [])
 * @method \AsyncAws\S3\S3Client createS3(array $args = [])
 * @method \AsyncAws\Ses\SesClient createSes(array $args = [])
 * @method \AsyncAws\Ses\SesClient createSesV2(array $args = [])
 * @property-read \AsyncAws\DynamoDb\DynamoDbClient $DynamoDb
 * @property-read \AsyncAws\S3\S3Client $S3
 * @property-read \AsyncAws\SimpleS3\SimpleS3Client $SimpleS3
 * @property-read \AsyncAws\Ses\SesClient $Ses
 * @property-read \AsyncAws\Ses\SesClient $SesV2
 */
class AsyncAwsComponent extends Component
{
    /**
     * Service names of the AWS SDK that have a different name in `AwsClientFactory`.
     *
     * @var array<string, string>
     */
    private const ALIASES = [
        'sesv2' => 'ses',
    ];

    /**
     * AWS SDK style options and their AsyncAws names.
     *
     * @var array<string, string>
     */
    private const OPTION_NAMES = [
        'use_path_style_endpoint' => Configuration::OPTION_PATH_STYLE_ENDPOINT,
    ];

    private AwsClientFactory $factory;

    /**
     * Constructor
     *
     * @param ComponentCollection $collection A ComponentCollection this component can use to lazy load its components
     * @param array $settings Array of configuration settings.
     */
    public function __construct(ComponentCollection $collection, $settings = [])
    {
        parent::__construct($collection, $settings);
        $this->factory = static::createFactory();
    }

    /**
     * Returns the configuration of the plugin: `Configure::read('AsyncAws')`, or `Configure::read('Aws')` when it is not set.
     *
     * @return array<string, mixed>
     */
    public static function config(): array
    {
        $config = Configure::read('AsyncAws');
        if ($config === null) {
            $config = Configure::read('Aws');
        }

        return (array)$config;
    }

    /**
     * Creates a client factory from the plugin configuration merged with `$args`.
     *
     * @param array<string, mixed> $args Options that override the plugin configuration.
     * @return \AsyncAws\Core\AwsClientFactory
     */
    public static function createFactory(array $args = []): AwsClientFactory
    {
        return new AwsClientFactory(...static::clientArguments($args));
    }

    /**
     * Returns the constructor arguments of an AsyncAws client (or factory) for the plugin configuration merged with `$args`:
     * the configuration, the credential provider, the HTTP client and the logger.
     *
     * @param array<string, mixed> $args Options that override the plugin configuration.
     * @return array{0: array<string, string|null>, 1: \AsyncAws\Core\Credentials\CredentialProvider|null, 2: \Symfony\Contracts\HttpClient\HttpClientInterface|null, 3: \Psr\Log\LoggerInterface|null}
     */
    public static function clientArguments(array $args = []): array
    {
        $config = array_merge(static::config(), $args);
        if (isset($args['credentials'])) {
            // Credentials given in $args replace the configured ones as a whole.
            unset($config[Configuration::OPTION_ACCESS_KEY_ID], $config[Configuration::OPTION_SECRET_ACCESS_KEY], $config[Configuration::OPTION_SESSION_TOKEN]);
        }

        $credentialProvider = $config['credentialProvider'] ?? null;
        $httpClient = $config['httpClient'] ?? null;
        $logger = $config['logger'] ?? null;

        return [
            static::configuration($config),
            $credentialProvider instanceof CredentialProvider ? $credentialProvider : null,
            $httpClient instanceof HttpClientInterface ? $httpClient : null,
            $logger instanceof LoggerInterface ? $logger : null,
        ];
    }

    /**
     * Converts the plugin configuration to the options of `AsyncAws\Core\Configuration`.
     *
     * @param array<string, mixed> $config Plugin configuration.
     * @return array<string, string|null>
     */
    public static function configuration(array $config): array
    {
        $credentials = $config['credentials'] ?? null;
        if (is_array($credentials)) {
            $config += [
                Configuration::OPTION_ACCESS_KEY_ID => $credentials['key'] ?? null,
                Configuration::OPTION_SECRET_ACCESS_KEY => $credentials['secret'] ?? null,
                Configuration::OPTION_SESSION_TOKEN => $credentials['token'] ?? null,
            ];
        }

        $options = [];
        foreach ($config as $name => $value) {
            $name = self::OPTION_NAMES[$name] ?? $name;
            if (!Configuration::optionExists($name) || $value === null || is_array($value) || is_object($value)) {
                continue;
            }
            if (is_bool($value)) {
                $value = $value ? 'true' : 'false';
            }
            $options[$name] = (string)$value;
        }

        return $options;
    }

    /**
     * Returns the client factory.
     *
     * @return \AsyncAws\Core\AwsClientFactory
     */
    public function factory(): AwsClientFactory
    {
        return $this->factory;
    }

    /**
     * Creates an S3 client with the helper methods of `async-aws/simple-s3`.
     *
     * @param array<string, mixed> $args Options that override the plugin configuration.
     * @return \AsyncAws\SimpleS3\SimpleS3Client
     */
    public function createSimpleS3(array $args = []): SimpleS3Client
    {
        return new SimpleS3Client(...static::clientArguments($args));
    }

    /**
     * Returns a client by service name, e.g. `$this->AsyncAws->S3`.
     *
     * @param string $name Service name.
     * @return \AsyncAws\Core\AbstractApi
     */
    public function __get($name)
    {
        if ($name === 'SimpleS3') {
            return $this->createSimpleS3();
        }

        return $this->factory->{static::serviceMethod($name)}();
    }

    /**
     * Creates a client with `create<Service>($args)`, e.g. `$this->AsyncAws->createS3(['region' => 'us-east-1'])`,
     * or calls a method of the factory, e.g. `$this->AsyncAws->sqs()`.
     *
     * @param string $name Method name.
     * @param array $arguments Method arguments.
     * @return mixed
     */
    public function __call($name, $arguments)
    {
        if (str_starts_with($name, 'create') && strlen($name) > 6) {
            $method = static::serviceMethod(substr($name, 6));

            return static::createFactory($arguments[0] ?? [])->$method();
        }

        return $this->factory->$name(...$arguments);
    }

    /**
     * Returns the method of `AwsClientFactory` for a service name: `S3` => `s3`, `DynamoDb` => `dynamoDb`, `SesV2` => `ses`.
     *
     * @param string $name Service name.
     * @return string
     * @throws \BadMethodCallException When AsyncAws has no client for the service.
     */
    protected static function serviceMethod(string $name): string
    {
        $method = self::ALIASES[strtolower($name)] ?? lcfirst($name);
        if (!method_exists(AwsClientFactory::class, $method)) {
            throw new BadMethodCallException(sprintf('AsyncAws has no client for the "%s" service.', $name));
        }

        return $method;
    }
}

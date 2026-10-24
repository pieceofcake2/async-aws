<?php

class AsyncAwsSesDsnTest extends CakeTestCase
{
    use MockAsyncAwsTrait;

    /**
     * @var array<string, mixed>
     */
    private array $dsnClassMap = [];

    private ?string $awsRegion = null;

    private ?string $awsDefaultRegion = null;

    public function setUp(): void
    {
        parent::setUp();
        Configure::delete('AsyncAws');
        Configure::delete('Aws');
        $this->awsRegion = getenv('AWS_REGION') === false ? null : getenv('AWS_REGION');
        $this->awsDefaultRegion = getenv('AWS_DEFAULT_REGION') === false ? null : getenv('AWS_DEFAULT_REGION');
        putenv('AWS_REGION');
        putenv('AWS_DEFAULT_REGION');
        $this->dsnClassMap = CakeEmail::getDsnClassMap();
    }

    public function tearDown(): void
    {
        Configure::delete('AsyncAws');
        Configure::delete('Aws');
        putenv($this->awsRegion === null ? 'AWS_REGION' : 'AWS_REGION=' . $this->awsRegion);
        putenv($this->awsDefaultRegion === null ? 'AWS_DEFAULT_REGION' : 'AWS_DEFAULT_REGION=' . $this->awsDefaultRegion);
        $property = new ReflectionProperty('CakeEmail', '_dsnClassMap');
        $property->setAccessible(true);
        $property->setValue(null, $this->dsnClassMap);
        parent::tearDown();
    }

    /**
     * @param string $scheme Scheme.
     * @param array $query Query parameters.
     * @param array $parts Other parts.
     * @return array
     */
    private function parts(string $scheme, array $query = [], array $parts = []): array
    {
        return ['scheme' => $scheme] + $parts + ['host' => 'default', 'query' => $query];
    }

    public function testApi(): void
    {
        $this->assertSame(
            ['transport' => 'AsyncAws.AmazonSesApi', 'key' => 'ACCESS', 'secret' => 'SE/CRET', 'token' => 'TOKEN', 'region' => 'eu-central-1'],
            AsyncAwsSesDsn::api($this->parts('ses+api', ['region' => 'eu-central-1', 'session_token' => 'TOKEN'], ['username' => 'ACCESS', 'password' => 'SE/CRET']), []),
        );
    }

    public function testApiWithoutCredentialsUsesTheRegionFallbacks(): void
    {
        $this->assertSame(['transport' => 'AsyncAws.AmazonSesApi', 'region' => 'eu-west-1'], AsyncAwsSesDsn::api($this->parts('ses+api'), []));
        $this->assertSame(['transport' => 'AsyncAws.AmazonSesApi', 'region' => 'us-east-2'], AsyncAwsSesDsn::api($this->parts('ses+api'), ['region' => 'us-east-2']));

        putenv('AWS_DEFAULT_REGION=ap-southeast-1');
        $this->assertSame('ap-southeast-1', AsyncAwsSesDsn::api($this->parts('ses+api'), [])['region']);
        putenv('AWS_REGION=ap-south-1');
        $this->assertSame('ap-south-1', AsyncAwsSesDsn::api($this->parts('ses+api'), [])['region']);
        Configure::write('Aws.region', 'ap-northeast-1');
        $this->assertSame('ap-northeast-1', AsyncAwsSesDsn::api($this->parts('ses+api'), [])['region']);
        Configure::write('AsyncAws.region', 'ap-northeast-3');
        $this->assertSame('ap-northeast-3', AsyncAwsSesDsn::api($this->parts('ses+api'), [])['region']);
    }

    public function testApiWithEndpointAndOtherOptions(): void
    {
        $this->assertSame(
            ['debug' => 'true', 'transport' => 'AsyncAws.AmazonSesApi', 'region' => 'eu-west-1', 'endpoint' => 'https://localhost:4566'],
            AsyncAwsSesDsn::api($this->parts('ses+https', ['debug' => 'true'], ['host' => 'localhost', 'port' => 4566]), []),
        );
    }

    public function testSmtp(): void
    {
        $this->assertSame(
            [
                'transport' => 'Smtp',
                'host' => 'ssl://email-smtp.us-east-1.amazonaws.com',
                'port' => 465,
                'tls' => false,
                'username' => 'SMTP_USER',
                'password' => 'SMTP+PASS',
            ],
            AsyncAwsSesDsn::smtp($this->parts('ses+smtp', ['region' => 'us-east-1', 'ping_threshold' => '10'], ['username' => 'SMTP_USER', 'password' => 'SMTP+PASS']), []),
        );
        $this->assertSame(
            ['timeout' => '10', 'transport' => 'Smtp', 'host' => 'ssl://smtp.example.com', 'port' => 2465, 'tls' => false],
            AsyncAwsSesDsn::smtp($this->parts('ses+smtps', ['timeout' => '10'], ['host' => 'smtp.example.com', 'port' => 2465]), []),
        );
        $this->assertSame('ssl://email-smtp.eu-west-1.amazonaws.com', AsyncAwsSesDsn::smtp($this->parts('ses+smtp'), [])['host']);
    }

    public function testRegisterWithCallable(): void
    {
        $registered = null;
        $this->assertTrue(AsyncAwsSesDsn::register(function (array $map) use (&$registered): void {
            $registered = $map;
        }));
        $this->assertSame(AsyncAwsSesDsn::classMap(), $registered);
        $this->assertSame(['ses', 'ses+api', 'ses+https', 'ses+smtp', 'ses+smtps'], array_keys($registered));
        $this->assertSame([AsyncAwsSesDsn::class, 'api'], $registered['ses+api']);
        $this->assertSame([AsyncAwsSesDsn::class, 'smtp'], $registered['ses+smtp']);
    }

    public function testRegister(): void
    {
        $this->assertTrue(AsyncAwsSesDsn::register());
        $map = CakeEmail::getDsnClassMap();
        foreach (['ses', 'ses+api', 'ses+https', 'ses+smtp', 'ses+smtps'] as $scheme) {
            $this->assertArrayHasKey($scheme, $map);
        }

        $this->assertSame(
            ['transport' => 'AsyncAws.AmazonSesApi', 'key' => 'ACCESS', 'secret' => 'SECRET', 'region' => 'eu-west-1'],
            CakeEmail::parseDsn('ses+api://ACCESS:SECRET@default?region=eu-west-1'),
        );
        $smtp = CakeEmail::parseDsn('ses+smtp://USER:P%40SS@default?region=ap-northeast-1');
        $this->assertSame('ssl://email-smtp.ap-northeast-1.amazonaws.com', $smtp['host']);
        $this->assertSame('P@SS', $smtp['password']);
    }

    public function testSendWithMailerDsn(): void
    {
        $this->mockAsyncAws(['region' => 'ap-northeast-1']);
        $this->queueJson(['MessageId' => 'dsn-message-id']);
        AsyncAwsSesDsn::register();

        $email = new CakeEmail([
            'dsn' => 'ses+api://dsn-key:dsn-secret@default?region=eu-central-1&session_token=dsn-token',
            'transport' => 'Mail',
        ]);
        $result = $email->from('from@example.com')
            ->to('to@example.com')
            ->subject('Hello')
            ->send('Body');

        $request = $this->lastRequest();
        $this->assertInstanceOf('AmazonSesApiTransport', $email->transportClass());
        $this->assertSame('dsn-message-id', $result['id']);
        $this->assertSame('https://email.eu-central-1.amazonaws.com/v2/email/outbound-emails', $request['url']);
        $this->assertStringContainsString('Credential=dsn-key/', $request['headers']['authorization']);
        $this->assertSame('dsn-token', $request['headers']['x-amz-security-token']);
    }
}

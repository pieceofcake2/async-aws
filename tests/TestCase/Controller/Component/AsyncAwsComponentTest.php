<?php

use AsyncAws\Core\AwsClientFactory;
use AsyncAws\Core\Credentials\NullProvider;
use AsyncAws\DynamoDb\DynamoDbClient;
use AsyncAws\S3\S3Client;
use AsyncAws\Ses\SesClient;
use AsyncAws\SimpleS3\SimpleS3Client;

class AsyncAwsComponentTest extends CakeTestCase
{
    use MockAsyncAwsTrait;

    public function setUp(): void
    {
        parent::setUp();
        $this->mockAsyncAws();
    }

    public function tearDown(): void
    {
        Configure::delete('AsyncAws');
        Configure::delete('Aws');
        parent::tearDown();
    }

    private function component(): AsyncAwsComponent
    {
        return new AsyncAwsComponent(new ComponentCollection());
    }

    public function testGetReturnsClients(): void
    {
        $component = $this->component();

        $this->assertInstanceOf(S3Client::class, $component->S3);
        $this->assertInstanceOf(DynamoDbClient::class, $component->DynamoDb);
        $this->assertInstanceOf(SesClient::class, $component->Ses);
        $this->assertInstanceOf(SesClient::class, $component->SesV2);
        $this->assertInstanceOf(SimpleS3Client::class, $component->SimpleS3);
    }

    public function testGetReturnsTheSameClientOfTheFactory(): void
    {
        $component = $this->component();

        $this->assertSame($component->DynamoDb, $component->DynamoDb);
        $this->assertSame($component->factory()->dynamoDb(), $component->DynamoDb);
    }

    public function testGetUnknownServiceThrows(): void
    {
        $this->expectException(BadMethodCallException::class);
        $this->expectExceptionMessage('AsyncAws has no client for the "Unknown" service.');
        $this->component()->Unknown;
    }

    public function testClientUsesConfiguredRegionAndCredentials(): void
    {
        $this->queueJson(['TableNames' => []]);

        $this->component()->DynamoDb->listTables()->resolve();

        $request = $this->lastRequest();
        $this->assertSame('https://dynamodb.ap-northeast-1.amazonaws.com/', $request['url']);
        $this->assertStringContainsString('Credential=test-key/', $request['headers']['authorization']);
        $this->assertSame('DynamoDB_20120810.ListTables', $request['headers']['x-amz-target']);
    }

    public function testCreateMethodOverridesConfiguration(): void
    {
        $this->queueJson(['TableNames' => []]);

        $client = $this->component()->createDynamoDb(['region' => 'us-west-2']);
        $client->listTables()->resolve();

        $this->assertInstanceOf(DynamoDbClient::class, $client);
        $this->assertSame('https://dynamodb.us-west-2.amazonaws.com/', $this->lastRequest()['url']);
    }

    public function testCreateSesV2ReturnsSesClient(): void
    {
        $this->assertInstanceOf(SesClient::class, $this->component()->createSesV2());
    }

    public function testCredentialsInArgsReplaceConfiguredCredentials(): void
    {
        $this->queueJson(['TableNames' => []]);

        $this->component()
            ->createDynamoDb(['credentials' => ['key' => 'other-key', 'secret' => 'other-secret']])
            ->listTables()
            ->resolve();

        $this->assertStringContainsString('Credential=other-key/', $this->lastRequest()['headers']['authorization']);
    }

    public function testCredentialProviderIsUsed(): void
    {
        $this->mockAsyncAws(['accessKeyId' => null, 'accessKeySecret' => null, 'credentialProvider' => new NullProvider()]);
        $this->queueJson(['TableNames' => []]);

        $this->component()->DynamoDb->listTables()->resolve();

        $this->assertArrayNotHasKey('authorization', $this->lastRequest()['headers']);
    }

    public function testCallIsProxiedToFactory(): void
    {
        $component = $this->component();

        $this->assertInstanceOf(AwsClientFactory::class, $component->factory());
        $this->assertInstanceOf(S3Client::class, $component->s3());
    }

    public function testCreateSimpleS3UsesConfiguration(): void
    {
        $client = $this->component()->createSimpleS3(['endpoint' => 'http://localhost:4566', 'use_path_style_endpoint' => true]);

        $this->assertSame('http://localhost:4566/my-bucket/a/b%20c.txt', $client->getUrl('my-bucket', 'a/b c.txt'));
    }

    public function testConfigurationConvertsAwsSdkOptions(): void
    {
        $options = AsyncAwsComponent::configuration([
            'region' => 'eu-west-1',
            'version' => 'latest',
            'credentials' => ['key' => 'key', 'secret' => 'secret', 'token' => 'token'],
            'endpoint' => 'http://localhost:8000',
            'use_path_style_endpoint' => true,
            'debug' => false,
            'handler' => static function () {
            },
            'profile' => null,
        ]);

        $this->assertSame([
            'region' => 'eu-west-1',
            'endpoint' => 'http://localhost:8000',
            'pathStyleEndpoint' => 'true',
            'debug' => 'false',
            'accessKeyId' => 'key',
            'accessKeySecret' => 'secret',
            'sessionToken' => 'token',
        ], $options);
    }

    public function testConfigurationPrefersAsyncAwsOptionNames(): void
    {
        $options = AsyncAwsComponent::configuration([
            'accessKeyId' => 'async-key',
            'credentials' => ['key' => 'sdk-key', 'secret' => 'sdk-secret'],
        ]);

        $this->assertSame('async-key', $options['accessKeyId']);
        $this->assertSame('sdk-secret', $options['accessKeySecret']);
    }

    public function testConfigFallsBackToAwsConfiguration(): void
    {
        Configure::delete('AsyncAws');
        Configure::write('Aws', ['region' => 'us-east-2', 'version' => 'latest']);

        $this->assertSame(['region' => 'us-east-2', 'version' => 'latest'], AsyncAwsComponent::config());
    }

    public function testConfigPrefersAsyncAwsConfiguration(): void
    {
        Configure::write('Aws', ['region' => 'us-east-2']);

        $this->assertSame('ap-northeast-1', AsyncAwsComponent::config()['region']);
    }

    public function testLoadFromComponentCollection(): void
    {
        $AsyncAws = (new ComponentCollection())->load('AsyncAws.AsyncAws');

        $this->assertInstanceOf(AsyncAwsComponent::class, $AsyncAws);
    }
}

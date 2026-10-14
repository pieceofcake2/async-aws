<?php

use AsyncAws\Core\AwsClientFactory;
use AsyncAws\Core\Sts\StsClient;

App::uses('AsyncAwsComponent', 'AsyncAws.Controller/Component');
App::uses('ComponentCollection', 'Controller');
App::uses('DynamoDbEngine', 'AsyncAws.Cache/Engine');
App::uses('DynamoDbSession', 'AsyncAws.Model/Datasource/Session');
App::uses('AmazonSesApiTransport', 'AsyncAws.Network/Email');
App::uses('MissingPackageException', 'AsyncAws.Error');
App::uses('S3Storage', 'AsyncAws.Lib');

/**
 * Component that behaves as if no service package was installed.
 */
class MissingPackageAsyncAwsComponent extends AsyncAwsComponent
{
    protected static function packages(): array
    {
        $packages = [];
        foreach (parent::packages() as $method => [$class, $package]) {
            $packages[$method] = ['Missing\\' . $class, $package];
        }

        return $packages;
    }
}

class MissingPackageDynamoDbSession extends DynamoDbSession
{
    protected static function clientClass(): string
    {
        return 'Missing\\' . parent::clientClass();
    }
}

class MissingPackageDynamoDbEngine extends DynamoDbEngine
{
    protected static function clientClass(): string
    {
        return 'Missing\\' . parent::clientClass();
    }
}

class MissingPackageAmazonSesApiTransport extends AmazonSesApiTransport
{
    protected static function clientClass(): string
    {
        return 'Missing\\' . parent::clientClass();
    }
}

class MissingPackageS3Storage extends S3Storage
{
    protected static function clientClass(): string
    {
        return 'Missing\\' . parent::clientClass();
    }
}

/**
 * Tests the errors thrown when a feature is used without the AsyncAws package of its service.
 * The packages are installed in development, so the test classes above pretend that the client classes do not exist.
 */
class MissingPackageExceptionTest extends CakeTestCase
{
    use MockAsyncAwsTrait;

    private array $sessionConfig;

    public function setUp(): void
    {
        parent::setUp();
        $this->sessionConfig = (array)Configure::read('Session');
        $this->mockAsyncAws();
    }

    public function tearDown(): void
    {
        Configure::delete('AsyncAws');
        Configure::write('Session', $this->sessionConfig);
        parent::tearDown();
    }

    public function testCheck(): void
    {
        MissingPackageException::check(CakeException::class, 'vendor/package', 'Something');

        $this->expectException(MissingPackageException::class);
        $this->expectExceptionMessage('Something requires vendor/package. Install it with: composer require vendor/package');
        MissingPackageException::check('Missing\\Client', 'vendor/package', 'Something');
    }

    public static function componentProvider(): array
    {
        return [
            'S3 property' => [fn(AsyncAwsComponent $c) => $c->S3, 'The S3 client requires async-aws/s3.'],
            'S3 factory method' => [fn(AsyncAwsComponent $c) => $c->s3(), 'The S3 client requires async-aws/s3.'],
            'createS3' => [fn(AsyncAwsComponent $c) => $c->createS3(), 'The S3 client requires async-aws/s3.'],
            'SimpleS3 property' => [fn(AsyncAwsComponent $c) => $c->SimpleS3, 'The SimpleS3 client requires async-aws/simple-s3.'],
            'createSimpleS3' => [fn(AsyncAwsComponent $c) => $c->createSimpleS3(), 'The SimpleS3 client requires async-aws/simple-s3.'],
            'DynamoDb property' => [fn(AsyncAwsComponent $c) => $c->DynamoDb, 'The DynamoDb client requires async-aws/dynamo-db.'],
            'createDynamoDb' => [fn(AsyncAwsComponent $c) => $c->createDynamoDb(), 'The DynamoDb client requires async-aws/dynamo-db.'],
            'Ses property' => [fn(AsyncAwsComponent $c) => $c->Ses, 'The Ses client requires async-aws/ses.'],
            'SesV2 property' => [fn(AsyncAwsComponent $c) => $c->SesV2, 'The Ses client requires async-aws/ses.'],
            'createSesV2' => [fn(AsyncAwsComponent $c) => $c->createSesV2(), 'The Ses client requires async-aws/ses.'],
        ];
    }

    /**
     * @dataProvider componentProvider
     */
    public function testComponent(callable $get, string $message): void
    {
        $this->expectException(MissingPackageException::class);
        $this->expectExceptionMessage($message);
        $get(new MissingPackageAsyncAwsComponent(new ComponentCollection()));
    }

    public function testComponentMessageHasTheCommand(): void
    {
        try {
            (new MissingPackageAsyncAwsComponent(new ComponentCollection()))->createDynamoDb();
            $this->fail('No exception was thrown.');
        } catch (MissingPackageException $e) {
            $this->assertSame('The DynamoDb client requires async-aws/dynamo-db. Install it with: composer require async-aws/dynamo-db', $e->getMessage());
        }
    }

    public function testComponentOtherServicesAreLeftToTheFactory(): void
    {
        // The factory of async-aws/core reports the other services (here, an installed one).
        $component = new MissingPackageAsyncAwsComponent(new ComponentCollection());
        $this->assertInstanceOf(StsClient::class, $component->sts());
        $this->assertInstanceOf(AwsClientFactory::class, $component->factory());
    }

    public function testDynamoDbSession(): void
    {
        Configure::write('Session.handler', ['engine' => 'AsyncAws.DynamoDbSession', 'table_name' => 'sessions']);

        $this->expectException(MissingPackageException::class);
        $this->expectExceptionMessage('The DynamoDB session handler requires async-aws/dynamo-db. Install it with: composer require async-aws/dynamo-db');
        new MissingPackageDynamoDbSession();
    }

    public function testDynamoDbEngine(): void
    {
        $this->expectException(CacheException::class);
        $this->expectExceptionMessage('The DynamoDB cache engine requires async-aws/dynamo-db. Install it with: composer require async-aws/dynamo-db');
        (new MissingPackageDynamoDbEngine())->init(['table' => 'cache']);
    }

    public function testAmazonSesApiTransport(): void
    {
        $this->expectException(MissingPackageException::class);
        $this->expectExceptionMessage('The Amazon SES email transport requires async-aws/ses. Install it with: composer require async-aws/ses');
        new MissingPackageAmazonSesApiTransport();
    }

    public function testS3Storage(): void
    {
        $this->expectException(MissingPackageException::class);
        $this->expectExceptionMessage('S3Storage requires async-aws/simple-s3. Install it with: composer require async-aws/simple-s3');
        new MissingPackageS3Storage();
    }
}

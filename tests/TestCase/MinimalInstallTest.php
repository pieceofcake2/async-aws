<?php

/**
 * Tests an installation with async-aws/dynamo-db as the only service package, as the "Minimal install" CI job does:
 *
 * ```
 * composer remove --dev async-aws/ses async-aws/simple-s3 async-aws/s3
 * vendor/bin/phpunit tests/TestCase/MinimalInstallTest.php
 * ```
 *
 * It is skipped when the other service packages are installed.
 */
class MinimalInstallTest extends CakeTestCase
{
    use MockAsyncAwsTrait;

    private array $sessionConfig;

    public function setUp(): void
    {
        parent::setUp();
        if (class_exists('AsyncAws\\Ses\\SesClient') || class_exists('AsyncAws\\S3\\S3Client')) {
            $this->markTestSkipped('async-aws/ses or async-aws/s3 is installed.');
        }
        $this->sessionConfig = (array)Configure::read('Session');
        $this->mockAsyncAws();
    }

    public function tearDown(): void
    {
        Configure::delete('AsyncAws');
        if (isset($this->sessionConfig)) {
            Configure::write('Session', $this->sessionConfig);
        }
        parent::tearDown();
    }

    public function testInstalledPackageWorks(): void
    {
        Configure::write('Session.handler', ['engine' => 'AsyncAws.DynamoDbSession', 'table_name' => 'sessions']);
        $this->assertInstanceOf('AsyncAws\\DynamoDb\\DynamoDbClient', (new DynamoDbSession())->getClient());
        $this->assertInstanceOf('AsyncAws\\DynamoDb\\DynamoDbClient', (new AsyncAwsComponent(new ComponentCollection()))->DynamoDb);
    }

    public function testMissingS3(): void
    {
        $this->expectException(MissingPackageException::class);
        $this->expectExceptionMessage('The S3 client requires async-aws/s3. Install it with: composer require async-aws/s3');
        (new AsyncAwsComponent(new ComponentCollection()))->S3;
    }

    public function testMissingSimpleS3(): void
    {
        $this->expectException(MissingPackageException::class);
        $this->expectExceptionMessage('S3Storage requires async-aws/simple-s3. Install it with: composer require async-aws/simple-s3');
        new S3Storage();
    }

    public function testMissingSes(): void
    {
        $this->expectException(MissingPackageException::class);
        $this->expectExceptionMessage('The Amazon SES email transport requires async-aws/ses. Install it with: composer require async-aws/ses');
        new AmazonSesApiTransport();
    }

    public function testStreamWrapperIsNotRegistered(): void
    {
        $this->assertFalse(S3StreamWrapper::bootstrap());
        $this->assertNotContains('s3', stream_get_wrappers());
    }
}

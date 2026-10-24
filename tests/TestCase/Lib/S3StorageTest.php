<?php

use AsyncAws\S3\Exception\NoSuchKeyException;
use AsyncAws\SimpleS3\SimpleS3Client;
use Symfony\Component\HttpClient\Response\MockResponse;

class S3StorageTest extends CakeTestCase
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
        parent::tearDown();
    }

    public function testParsePath(): void
    {
        $this->assertSame(['my-bucket', 'a/b.txt'], S3Storage::parsePath('s3://my-bucket/a/b.txt'));
        $this->assertSame(['my-bucket', 'a/b.txt'], S3Storage::parsePath('my-bucket/a/b.txt'));
    }

    /**
     * @return array<array{string}>
     */
    public static function invalidPaths(): array
    {
        return [['s3://my-bucket'], ['s3://my-bucket/'], ['s3:///key'], ['']];
    }

    /**
     * @dataProvider invalidPaths
     */
    public function testParseInvalidPath(string $path): void
    {
        $this->expectException(InvalidArgumentException::class);
        S3Storage::parsePath($path);
    }

    public function testClientIsCreatedFromConfiguration(): void
    {
        $storage = new S3Storage();

        $this->assertInstanceOf(SimpleS3Client::class, $storage->client());
        $this->assertSame('https://my-bucket.s3.ap-northeast-1.amazonaws.com/a/b.txt', $storage->url('s3://my-bucket/a/b.txt'));
    }

    public function testInjectedClient(): void
    {
        $client = (new AsyncAwsComponent(new ComponentCollection()))->createSimpleS3(['region' => 'us-east-2']);
        $storage = new S3Storage($client);

        $this->assertSame($client, $storage->client());
    }

    public function testWrite(): void
    {
        $this->responses[] = new MockResponse('', ['response_headers' => ['ETag' => '"etag"']]);

        (new S3Storage())->write('s3://my-bucket/reports/today.csv', "a,b\n1,2\n", ['ContentType' => 'text/csv']);

        $request = $this->lastRequest();
        $this->assertSame('PUT', $request['method']);
        $this->assertSame('https://my-bucket.s3.ap-northeast-1.amazonaws.com/reports/today.csv', $request['url']);
        $this->assertSame('text/csv', $request['headers']['content-type']);
        $this->assertSame("a,b\n1,2\n", $request['body']);
    }

    public function testRead(): void
    {
        $this->responses[] = new MockResponse('contents');

        $this->assertSame('contents', (new S3Storage())->read('s3://my-bucket/a.txt'));

        $request = $this->lastRequest();
        $this->assertSame('GET', $request['method']);
        $this->assertSame('https://my-bucket.s3.ap-northeast-1.amazonaws.com/a.txt', $request['url']);
    }

    public function testReadStream(): void
    {
        $this->responses[] = new MockResponse('contents');

        $stream = (new S3Storage())->readStream('s3://my-bucket/a.txt');

        $this->assertIsResource($stream);
        $this->assertSame('contents', stream_get_contents($stream));
    }

    public function testReadMissing(): void
    {
        $this->responses[] = new MockResponse(
            '<?xml version="1.0" encoding="UTF-8"?><Error><Code>NoSuchKey</Code><Message>The specified key does not exist.</Message></Error>',
            ['http_code' => 404],
        );

        $this->expectException(NoSuchKeyException::class);
        (new S3Storage())->read('s3://my-bucket/missing.txt');
    }

    public function testExists(): void
    {
        $this->responses[] = new MockResponse('');
        $this->responses[] = new MockResponse('', ['http_code' => 404]);
        $storage = new S3Storage();

        $this->assertTrue($storage->exists('s3://my-bucket/a.txt'));
        $this->assertSame('HEAD', $this->lastRequest()['method']);
        $this->assertFalse($storage->exists('s3://my-bucket/missing.txt'));
    }

    public function testDelete(): void
    {
        $this->responses[] = new MockResponse('', ['http_code' => 204]);

        (new S3Storage())->delete('s3://my-bucket/a.txt');

        $request = $this->lastRequest();
        $this->assertSame('DELETE', $request['method']);
        $this->assertSame('https://my-bucket.s3.ap-northeast-1.amazonaws.com/a.txt', $request['url']);
    }

    public function testUrlWithPathStyleEndpoint(): void
    {
        Configure::write('AsyncAws.endpoint', 'http://localhost:4566');
        Configure::write('AsyncAws.use_path_style_endpoint', true);

        $this->assertSame('http://localhost:4566/my-bucket/a%20b.txt', (new S3Storage())->url('s3://my-bucket/a b.txt'));
    }

    public function testPresignedUrl(): void
    {
        $url = (new S3Storage())->presignedUrl('s3://my-bucket/a.txt', new DateTimeImmutable('+10 minutes'));

        $this->assertStringStartsWith('https://my-bucket.s3.ap-northeast-1.amazonaws.com/a.txt?', $url);
        $this->assertStringContainsString('X-Amz-Credential=test-key%2F', $url);
        $this->assertMatchesRegularExpression('/X-Amz-Expires=(599|600)(&|$)/', $url);
        $this->assertStringContainsString('X-Amz-Signature=', $url);
        $this->assertSame([], $this->requests);
    }
}

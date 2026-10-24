<?php

use AsyncAws\S3\S3Client;
use AsyncAws\SimpleS3\SimpleS3Client;
use Composer\InstalledVersions;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Collects the log messages written during a test.
 */
class S3StreamWrapperTestLog implements CakeLogInterface
{
    public static array $messages = [];

    public function __construct($config = [])
    {
    }

    public function write($type, $message): void
    {
        static::$messages[] = $type . ': ' . $message;
    }
}

/**
 * Stream wrapper that behaves as if async-aws/simple-s3 was not installed.
 */
class MissingPackageS3StreamWrapper extends S3StreamWrapper
{
    protected static function clientClass(): string
    {
        return 'Missing\\' . parent::clientClass();
    }
}

class S3StreamWrapperTest extends CakeTestCase
{
    use MockAsyncAwsTrait;

    private const ENDPOINT = 'https://s3.ap-northeast-1.amazonaws.com';

    /**
     * Warnings triggered by the wrapper.
     *
     * @var array<string>
     */
    private array $warnings = [];

    public function setUp(): void
    {
        parent::setUp();
        $this->mockAsyncAws(['pathStyleEndpoint' => true]);
        $this->assertTrue(S3StreamWrapper::bootstrap());
        $this->warnings = [];
    }

    public function tearDown(): void
    {
        if (in_array('s3', stream_get_wrappers(), true)) {
            stream_wrapper_unregister('s3');
        }
        S3StreamWrapper::clearCache();
        Configure::delete('AsyncAws');
        CakeLog::drop('s3_stream_wrapper_test');
        parent::tearDown();
    }

    /**
     * Calls a function and collects the warnings it triggers instead of failing.
     *
     * @param callable $fn Function.
     * @return mixed
     */
    private function quietly(callable $fn): mixed
    {
        set_error_handler(function (int $errno, string $message): bool {
            $this->warnings[] = $message;

            return true;
        }, E_WARNING | E_USER_WARNING);
        try {
            return $fn();
        } finally {
            restore_error_handler();
        }
    }

    private function queue(MockResponse ...$responses): void
    {
        foreach ($responses as $response) {
            $this->responses[] = $response;
        }
    }

    private function ok(string $body = '', array $headers = []): MockResponse
    {
        return new MockResponse($body, ['response_headers' => $headers]);
    }

    private function error(int $status, string $code = ''): MockResponse
    {
        $body = $code === '' ? '' : '<?xml version="1.0" encoding="UTF-8"?><Error><Code>' . $code . '</Code><Message>' . $code . ' message</Message></Error>';

        return new MockResponse($body, ['http_code' => $status, 'response_headers' => ['content-type' => 'application/xml']]);
    }

    private function head(int $size, string $lastModified = 'Fri, 02 Jan 2026 03:04:05 GMT'): MockResponse
    {
        return $this->ok('', ['content-length' => (string)$size, 'last-modified' => $lastModified]);
    }

    /**
     * Returns a ListObjectsV2 response.
     *
     * @param array<string> $keys Keys.
     * @param array<string> $prefixes Common prefixes.
     * @param string|null $next Next continuation token.
     * @return \Symfony\Component\HttpClient\Response\MockResponse
     */
    private function listing(array $keys, array $prefixes = [], ?string $next = null): MockResponse
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?><ListBucketResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/">';
        $xml .= '<Name>bucket</Name><KeyCount>' . (count($keys) + count($prefixes)) . '</KeyCount><MaxKeys>1000</MaxKeys>';
        $xml .= '<IsTruncated>' . ($next ? 'true' : 'false') . '</IsTruncated>';
        if ($next) {
            $xml .= '<NextContinuationToken>' . $next . '</NextContinuationToken>';
        }
        foreach ($keys as $key) {
            $xml .= '<Contents><Key>' . $key . '</Key><LastModified>2026-01-02T03:04:05.000Z</LastModified><ETag>"etag"</ETag><Size>3</Size><StorageClass>STANDARD</StorageClass></Contents>';
        }
        foreach ($prefixes as $prefix) {
            $xml .= '<CommonPrefixes><Prefix>' . $prefix . '</Prefix></CommonPrefixes>';
        }
        $xml .= '</ListBucketResult>';

        return $this->ok($xml, ['content-type' => 'application/xml']);
    }

    private function request(int $index): array
    {
        $this->assertArrayHasKey($index, $this->requests, 'The request was not sent.');

        return $this->requests[$index];
    }

    private function assertRequest(int $index, string $method, string $path): array
    {
        $request = $this->request($index);
        $this->assertSame($method, $request['method']);
        $this->assertSame(self::ENDPOINT . $path, $request['url']);

        return $request;
    }

    public function testBootstrapRegistersTheWrapper(): void
    {
        $this->assertContains('s3', stream_get_wrappers());
        $options = stream_context_get_options(stream_context_get_default());
        $this->assertInstanceOf(SimpleS3Client::class, $options['s3']['client']);
    }

    public function testBootstrapWithoutSimpleS3(): void
    {
        stream_wrapper_unregister('s3');
        CakeLog::config('s3_stream_wrapper_test', ['engine' => 'S3StreamWrapperTestLog']);
        S3StreamWrapperTestLog::$messages = [];

        $this->assertFalse(MissingPackageS3StreamWrapper::bootstrap());
        $this->assertNotContains('s3', stream_get_wrappers());
        $this->assertSame(
            ['warning: The S3 stream wrapper requires async-aws/simple-s3. Install it with: composer require async-aws/simple-s3. The s3:// stream wrapper is not registered.'],
            S3StreamWrapperTestLog::$messages,
        );
    }

    public function testPluginBootstrapFile(): void
    {
        stream_wrapper_unregister('s3');
        include dirname(__DIR__, 3) . DS . 'Config' . DS . 'bootstrap.php';
        $this->assertContains('s3', stream_get_wrappers());
    }

    public function testRegisterReplacesTheWrapper(): void
    {
        S3StreamWrapper::register(new S3Client(...AsyncAwsComponent::clientArguments()), 's3');
        $this->assertContains('s3', stream_get_wrappers());
        $options = stream_context_get_options(stream_context_get_default());
        $this->assertSame(S3Client::class, get_class($options['s3']['client']));
    }

    public function testFilePutContents(): void
    {
        $this->assertSame(9, file_put_contents('s3://bucket/dir/file.csv', 'a,b,c,d,e'));

        $this->assertCount(1, $this->requests);
        $request = $this->assertRequest(0, 'PUT', '/bucket/dir/file.csv');
        $this->assertSame('a,b,c,d,e', $request['body']);
        $this->assertSame('text/csv', $request['headers']['content-type']);
        $this->assertSame('9', $request['headers']['content-length']);
    }

    public function testWriteWithContextOptions(): void
    {
        $context = stream_context_create(['s3' => [
            'ACL' => 'public-read',
            'ContentType' => 'application/x-custom',
            'CacheControl' => 'max-age=60',
            'ServerSideEncryption' => 'AES256',
            'Metadata' => ['owner' => 'me'],
        ]]);
        $handle = fopen('s3://bucket/file.txt', 'w', false, $context);
        $this->assertIsResource($handle);
        fwrite($handle, 'hello ');
        fwrite($handle, 'world');
        $this->assertSame(11, ftell($handle));
        $this->assertSame(11, fstat($handle)['size']);
        $this->assertSame(0100777, fstat($handle)['mode']);
        $this->assertEmpty($this->requests, 'Nothing is uploaded before the stream is flushed.');
        $this->assertTrue(fclose($handle));

        $request = $this->assertRequest(0, 'PUT', '/bucket/file.txt');
        $this->assertSame('hello world', $request['body']);
        $this->assertSame('public-read', $request['headers']['x-amz-acl']);
        $this->assertSame('application/x-custom', $request['headers']['content-type']);
        $this->assertSame('max-age=60', $request['headers']['cache-control']);
        $this->assertSame('AES256', $request['headers']['x-amz-server-side-encryption']);
        $this->assertSame('me', $request['headers']['x-amz-meta-owner']);
    }

    public function testWriteWithS3Client(): void
    {
        S3StreamWrapper::register(new S3Client(...AsyncAwsComponent::clientArguments()));
        $this->assertSame(5, file_put_contents('s3://bucket/file', 'hello'));

        $request = $this->assertRequest(0, 'PUT', '/bucket/file');
        $this->assertSame('hello', $request['body']);
        $this->assertArrayNotHasKey('content-type', $request['headers']);
    }

    public function testWriteEmptyFile(): void
    {
        $handle = fopen('s3://bucket/empty.txt', 'w');
        $this->assertTrue(fclose($handle));

        $request = $this->assertRequest(0, 'PUT', '/bucket/empty.txt');
        $this->assertSame('', $request['body']);
        $this->assertSame('text/plain', $request['headers']['content-type']);
    }

    public function testFlushUploadsAndCloseUploadsAgainOnlyAfterWrites(): void
    {
        $handle = fopen('s3://bucket/file.txt', 'w');
        fwrite($handle, 'one');
        $this->assertTrue(fflush($handle));
        $this->assertCount(1, $this->requests);
        $this->assertSame('one', $this->request(0)['body']);

        fwrite($handle, 'two');
        fclose($handle);
        $this->assertCount(2, $this->requests);
        $this->assertSame('onetwo', $this->request(1)['body']);
    }

    public function testFlushWithoutWritesAfterwardDoesNotUploadOnClose(): void
    {
        $handle = fopen('s3://bucket/file.txt', 'w');
        fwrite($handle, 'one');
        fflush($handle);
        fclose($handle);
        $this->assertCount(1, $this->requests);
    }

    public function testWriteStreamCanSeek(): void
    {
        $handle = fopen('s3://bucket/file.txt', 'w');
        fwrite($handle, 'hello world');
        $this->assertSame(0, fseek($handle, 0));
        fwrite($handle, 'J');
        $this->assertSame(1, ftell($handle));
        fclose($handle);

        $this->assertSame('Jello world', $this->request(0)['body']);
    }

    public function testUploadErrorTriggersWarning(): void
    {
        $this->queue($this->error(403, 'AccessDenied'));

        $result = $this->quietly(fn() => file_put_contents('s3://bucket/file.txt', 'data'));

        // file_put_contents() does not see the result of the upload when the stream is closed.
        $this->assertSame(4, $result);
        $this->assertCount(1, $this->warnings);
        $this->assertStringContainsString('AccessDenied', $this->warnings[0]);
        $this->assertStringNotContainsString('test-secret', $this->warnings[0]);
    }

    public function testFflushReturnsFalseOnUploadError(): void
    {
        $this->queue($this->error(500, 'InternalError'));
        $handle = fopen('s3://bucket/file.txt', 'w');
        fwrite($handle, 'data');

        $this->assertFalse($this->quietly(fn() => fflush($handle)));
        $this->assertStringContainsString('InternalError', $this->warnings[0]);
    }

    public function testMultipartUpload(): void
    {
        if (version_compare((string)InstalledVersions::getVersion('async-aws/simple-s3'), '3.0.0', '<')) {
            $this->markTestSkipped('async-aws/simple-s3 2.x (PHP 8.1) uploads bodies up to 64 MB with PutObject, whatever the PartSize.');
        }
        $this->queue(
            $this->ok('<?xml version="1.0" encoding="UTF-8"?><InitiateMultipartUploadResult><Bucket>bucket</Bucket><Key>big.bin</Key><UploadId>upload-id</UploadId></InitiateMultipartUploadResult>'),
            $this->ok('', ['etag' => '"etag1"']),
            $this->ok('', ['etag' => '"etag2"']),
            $this->ok('', ['etag' => '"etag3"']),
            $this->ok('<?xml version="1.0" encoding="UTF-8"?><CompleteMultipartUploadResult><Bucket>bucket</Bucket><Key>big.bin</Key><ETag>"etag"</ETag></CompleteMultipartUploadResult>'),
        );
        $context = stream_context_create(['s3' => ['PartSize' => 1, 'ACL' => 'private']]);
        $data = str_repeat('x', 2 * 1024 * 1024 + 100);

        $this->assertSame(strlen($data), file_put_contents('s3://bucket/big.bin', $data, 0, $context));

        $this->assertCount(5, $this->requests);
        $create = $this->assertRequest(0, 'POST', '/bucket/big.bin?uploads=');
        $this->assertSame('private', $create['headers']['x-amz-acl']);
        $this->assertSame('application/octet-stream', $create['headers']['content-type']);
        $this->assertRequest(1, 'PUT', '/bucket/big.bin?partNumber=1&uploadId=upload-id');
        $this->assertSame(1024 * 1024, strlen($this->request(1)['body']));
        $this->assertSame(100, strlen($this->request(3)['body']));
        $complete = $this->assertRequest(4, 'POST', '/bucket/big.bin?uploadId=upload-id');
        $this->assertStringContainsString('<PartNumber>3</PartNumber>', $complete['body']);
    }

    public function testFileGetContents(): void
    {
        $this->queue(new MockResponse(['hello ', 'world'], ['response_headers' => ['content-length' => '11']]));

        $this->assertSame('hello world', file_get_contents('s3://bucket/file.txt'));
        $this->assertRequest(0, 'GET', '/bucket/file.txt');
    }

    public function testReadStream(): void
    {
        $this->queue(new MockResponse(['abc', 'def', 'gh'], ['response_headers' => ['content-length' => '8']]));

        $handle = fopen('s3://bucket/file.txt', 'rb');
        $this->assertSame(8, fstat($handle)['size']);
        $this->assertSame('abcdefgh', fread($handle, 100));
        $this->assertSame(8, ftell($handle));
        $this->assertSame('', fread($handle, 100));
        $this->assertTrue(feof($handle));
        $this->assertFalse(fwrite($handle, 'x') > 0);
        fclose($handle);
        $this->assertCount(1, $this->requests, 'A read stream is not uploaded.');
    }

    public function testReadStreamIsNotSeekable(): void
    {
        $this->queue(new MockResponse(str_repeat('a', 20000), ['response_headers' => ['content-length' => '20000']]));

        $handle = fopen('s3://bucket/file.txt', 'r');
        fread($handle, 10);
        $this->assertSame(-1, fseek($handle, 0));
        $this->assertFalse(fflush($handle));
        fclose($handle);
    }

    public function testSeekableReadStream(): void
    {
        $this->queue(new MockResponse(['abc', 'def', 'gh'], ['response_headers' => ['content-length' => '8']]));
        $context = stream_context_create(['s3' => ['seekable' => true]]);

        $handle = fopen('s3://bucket/file.txt', 'r', false, $context);
        $this->assertSame('ab', stream_get_contents($handle, 2));
        $this->assertSame(0, fseek($handle, 0));
        $this->assertSame('abcd', stream_get_contents($handle, 4));
        $this->assertSame(0, fseek($handle, 6));
        $this->assertSame('gh', stream_get_contents($handle, 10));
        $this->assertTrue(feof($handle));
        $this->assertSame(0, fseek($handle, -3, SEEK_END));
        $this->assertSame('fgh', stream_get_contents($handle));
        $this->assertSame(0, fseek($handle, 1));
        $this->assertSame(0, fseek($handle, 2, SEEK_CUR));
        $this->assertSame('def', stream_get_contents($handle, 3));

        $this->assertSame(-1, $this->quietly(fn() => fseek($handle, 100)));
        $this->assertStringContainsString('Cannot seek to byte 100', $this->warnings[0]);
        $this->assertSame(-1, fseek($handle, -100, SEEK_CUR));
        fclose($handle);
        $this->assertCount(1, $this->requests);
    }

    public function testSeekableReadWithContextGetObjectOptions(): void
    {
        $this->queue(new MockResponse('bc', ['http_code' => 206, 'response_headers' => ['content-length' => '2']]));
        $context = stream_context_create(['s3' => ['Range' => 'bytes=1-2', 'VersionId' => 'v1']]);

        $this->assertSame('bc', file_get_contents('s3://bucket/file.txt', false, $context));
        $request = $this->assertRequest(0, 'GET', '/bucket/file.txt?versionId=v1');
        $this->assertSame('bytes=1-2', $request['headers']['range']);
    }

    public function testReadMissingObject(): void
    {
        $this->queue($this->error(404, 'NoSuchKey'));

        $this->assertFalse($this->quietly(fn() => fopen('s3://bucket/missing.txt', 'r')));
        $this->assertStringContainsString('NoSuchKey', $this->warnings[0]);
    }

    public function testAppendToExistingObject(): void
    {
        $this->queue(new MockResponse(['abc', 'def'], ['response_headers' => ['content-length' => '6']]));

        $handle = fopen('s3://bucket/log.txt', 'a');
        $this->assertSame(6, ftell($handle));
        fwrite($handle, 'ghi');
        fclose($handle);

        $this->assertRequest(0, 'GET', '/bucket/log.txt');
        $request = $this->assertRequest(1, 'PUT', '/bucket/log.txt');
        $this->assertSame('abcdefghi', $request['body']);
    }

    public function testAppendWithoutWritesDoesNotUpload(): void
    {
        $this->queue(new MockResponse('abc', ['response_headers' => ['content-length' => '3']]));

        fclose(fopen('s3://bucket/log.txt', 'a'));
        $this->assertCount(1, $this->requests);
    }

    public function testAppendToMissingObject(): void
    {
        $this->queue($this->error(404, 'NoSuchKey'));

        $this->assertSame(3, file_put_contents('s3://bucket/log.txt', 'abc', FILE_APPEND));
        $this->assertSame('abc', $this->assertRequest(1, 'PUT', '/bucket/log.txt')['body']);
    }

    public function testAppendFailsOnOtherErrors(): void
    {
        $this->queue($this->error(403, 'AccessDenied'));

        $this->assertFalse($this->quietly(fn() => fopen('s3://bucket/log.txt', 'a')));
        $this->assertStringContainsString('AccessDenied', $this->warnings[0]);
        $this->assertCount(1, $this->requests);
    }

    public function testExclusiveCreate(): void
    {
        $this->queue($this->error(404));

        $handle = fopen('s3://bucket/new.txt', 'x');
        fwrite($handle, 'new');
        fclose($handle);

        $this->assertRequest(0, 'HEAD', '/bucket/new.txt');
        $this->assertSame('new', $this->assertRequest(1, 'PUT', '/bucket/new.txt')['body']);
    }

    public function testExclusiveCreateFailsWhenTheObjectExists(): void
    {
        $this->queue($this->head(3));

        $this->assertFalse($this->quietly(fn() => fopen('s3://bucket/new.txt', 'x')));
        $this->assertStringContainsString('s3://bucket/new.txt already exists on Amazon S3', $this->warnings[0]);
    }

    public function testUnsupportedMode(): void
    {
        $this->assertFalse($this->quietly(fn() => fopen('s3://bucket/file.txt', 'r+')));
        $this->assertStringContainsString("Mode not supported: r+. Use one 'r', 'w', 'a', or 'x'.", $this->warnings[0]);
        $this->assertEmpty($this->requests);
    }

    public function testOpenBucket(): void
    {
        $this->assertFalse($this->quietly(fn() => fopen('s3://bucket', 'r')));
        $this->assertStringContainsString('Cannot open a bucket. You must specify a path in the form of s3://bucket/key', $this->warnings[0]);
    }

    public function testStatFile(): void
    {
        $this->queue($this->head(5));

        $this->assertTrue(is_file('s3://bucket/file.txt'));
        $this->assertFalse(is_dir('s3://bucket/file.txt'));
        $this->assertTrue(file_exists('s3://bucket/file.txt'));
        $this->assertSame(5, filesize('s3://bucket/file.txt'));
        $this->assertSame(strtotime('2026-01-02 03:04:05 UTC'), filemtime('s3://bucket/file.txt'));
        $stat = stat('s3://bucket/file.txt');
        $this->assertSame(0100777, $stat['mode']);
        $this->assertSame(5, $stat[7]);
        $this->assertTrue(is_writable('s3://bucket/file.txt'));

        $this->assertCount(1, $this->requests, 'The stat is cached.');
        $this->assertRequest(0, 'HEAD', '/bucket/file.txt');
    }

    public function testStatCacheIsClearedByWrites(): void
    {
        $this->queue($this->head(5), $this->ok(), $this->head(3));

        $this->assertSame(5, filesize('s3://bucket/file.txt'));
        file_put_contents('s3://bucket/file.txt', 'abc');
        $this->assertSame(3, filesize('s3://bucket/file.txt'));
        $this->assertCount(3, $this->requests);
    }

    public function testClearCache(): void
    {
        $this->queue($this->head(5), $this->head(6));

        $this->assertSame(5, filesize('s3://bucket/file.txt'));
        S3StreamWrapper::clearCache();
        clearstatcache();
        $this->assertSame(6, filesize('s3://bucket/file.txt'));
    }

    public function testStatPrefix(): void
    {
        $this->queue($this->error(404), $this->listing(['dir/a.txt']));

        $this->assertTrue(is_dir('s3://bucket/dir'));
        $this->assertFalse(is_file('s3://bucket/dir'));
        $this->assertSame(0040777, stat('s3://bucket/dir')['mode']);

        $this->assertRequest(0, 'HEAD', '/bucket/dir');
        $this->assertRequest(1, 'GET', '/bucket?list-type=2&max-keys=1&prefix=dir%2F');
    }

    public function testStatPrefixWithCommonPrefixOnly(): void
    {
        $this->queue($this->error(404), $this->listing([], ['dir/sub/']));

        $this->assertTrue(is_dir('s3://bucket/dir/'));
    }

    public function testStatDirectoryObject(): void
    {
        $this->queue($this->head(0));

        $this->assertTrue(is_dir('s3://bucket/dir/'));
        $this->assertCount(1, $this->requests);
    }

    public function testStatMissing(): void
    {
        $this->queue($this->error(404), $this->listing([]));

        $this->assertFalse(file_exists('s3://bucket/missing.txt'));
        $this->assertEmpty($this->warnings);
    }

    public function testStatMissingWarns(): void
    {
        $this->queue($this->error(404), $this->listing([]));

        $this->assertFalse($this->quietly(fn() => stat('s3://bucket/missing.txt')));
        $this->assertStringContainsString('File or directory not found: s3://bucket/missing.txt', implode("\n", $this->warnings));
    }

    public function testStatMissingLink(): void
    {
        $this->queue($this->error(404), $this->listing([]));

        $this->assertFalse(is_link('s3://bucket/missing.txt'));
    }

    public function testStatNetworkErrorIsQuietForFileExists(): void
    {
        $this->queue(new MockResponse('', ['error' => 'Connection refused']));

        $this->assertFalse($this->quietly(fn() => file_exists('s3://bucket/file.txt')));
        $this->assertEmpty($this->warnings);
    }

    public function testStatRoot(): void
    {
        $this->assertTrue(is_dir('s3://'));
        $this->assertEmpty($this->requests);
    }

    public function testStatBucket(): void
    {
        $this->queue($this->ok(), $this->error(404), $this->error(403));

        $this->assertTrue(is_dir('s3://bucket'));
        $this->assertFalse(is_dir('s3://missing'));
        $this->assertTrue(is_dir('s3://forbidden'));
        $this->assertRequest(0, 'HEAD', '/bucket');
    }

    public function testStatBucketServerError(): void
    {
        $this->queue($this->error(500));

        $this->assertFalse($this->quietly(fn() => stat('s3://bucket')));
        $this->assertStringContainsString('Unable to check whether the bucket bucket exists', implode("\n", $this->warnings));
    }

    public function testStatUpperCaseProtocol(): void
    {
        $this->queue($this->head(5));

        $wrapper = new S3StreamWrapper();
        $this->assertSame(5, $wrapper->url_stat('S3://bucket/file.txt', 0)['size']);
        $this->assertSame(5, filesize('s3://bucket/file.txt'));
        $this->assertCount(1, $this->requests);
    }

    public function testUnlink(): void
    {
                $this->assertTrue(unlink('s3://bucket/file.txt'));
        $this->assertRequest(0, 'DELETE', '/bucket/file.txt');
    }

    public function testUnlinkWithVersion(): void
    {
        $context = stream_context_create(['s3' => ['VersionId' => 'v1']]);

        $this->assertTrue(unlink('s3://bucket/file.txt', $context));
        $this->assertRequest(0, 'DELETE', '/bucket/file.txt?versionId=v1');
    }

    public function testUnlinkError(): void
    {
        $this->queue($this->error(403, 'AccessDenied'));

        $this->assertFalse($this->quietly(fn() => unlink('s3://bucket/file.txt')));
        $this->assertStringContainsString('AccessDenied', $this->warnings[0]);
    }

    public function testRename(): void
    {
        $this->queue($this->ok('<?xml version="1.0" encoding="UTF-8"?><CopyObjectResult><ETag>"etag"</ETag></CopyObjectResult>'), $this->ok());

        $this->assertTrue(rename('s3://bucket/a b/old.txt', 's3://other/new.txt'));

        $copy = $this->assertRequest(0, 'PUT', '/other/new.txt');
        $this->assertSame('bucket/a%20b/old.txt', $copy['headers']['x-amz-copy-source']);
        $this->assertSame('COPY', $copy['headers']['x-amz-metadata-directive']);
        $this->assertArrayNotHasKey('x-amz-acl', $copy['headers']);
        $this->assertRequest(1, 'DELETE', '/bucket/a%20b/old.txt');
    }

    public function testRenameWithAcl(): void
    {
        $context = stream_context_create(['s3' => ['acl' => 'public-read']]);

        $this->assertTrue(rename('s3://bucket/old.txt', 's3://bucket/new.txt', $context));
        $this->assertSame('public-read', $this->request(0)['headers']['x-amz-acl']);
    }

    public function testRenameBucket(): void
    {
        $this->assertFalse($this->quietly(fn() => rename('s3://bucket', 's3://other')));
        $this->assertStringContainsString('The Amazon S3 stream wrapper only supports copying objects', $this->warnings[0]);
        $this->assertEmpty($this->requests);
    }

    public function testRenameCopyErrorKeepsTheSource(): void
    {
        $this->queue($this->error(404, 'NoSuchKey'));

        $this->assertFalse($this->quietly(fn() => rename('s3://bucket/old.txt', 's3://bucket/new.txt')));
        $this->assertCount(1, $this->requests);
    }

    public function testCopy(): void
    {
        $this->queue(
            $this->head(4),
            $this->error(404),
            $this->listing([]),
            new MockResponse('data', ['response_headers' => ['content-length' => '4']]),
            $this->ok(),
        );

        $this->assertTrue(copy('s3://bucket/a.txt', 's3://bucket/b.txt'));

        // copy() checks the source and the destination with url_stat(), then reads and writes.
        $this->assertRequest(0, 'HEAD', '/bucket/a.txt');
        $this->assertRequest(1, 'HEAD', '/bucket/b.txt');
        $this->assertRequest(3, 'GET', '/bucket/a.txt');
        $this->assertSame('data', $this->assertRequest(4, 'PUT', '/bucket/b.txt')['body']);
    }

    public function testMkdirBucket(): void
    {
        $this->queue($this->error(404), $this->ok());

        $this->assertTrue(mkdir('s3://new-bucket'));

        $this->assertRequest(0, 'HEAD', '/new-bucket');
        $request = $this->assertRequest(1, 'PUT', '/new-bucket');
        $this->assertStringContainsString('<LocationConstraint>ap-northeast-1</LocationConstraint>', $request['body']);
        $this->assertArrayNotHasKey('x-amz-acl', $request['headers']);
    }

    public function testMkdirExistingBucket(): void
    {
        $this->queue($this->ok());

        $this->assertFalse($this->quietly(fn() => mkdir('s3://bucket')));
        $this->assertStringContainsString('Bucket already exists: s3://bucket', $this->warnings[0]);
    }

    public function testMkdirWithoutBucket(): void
    {
        $this->assertFalse(mkdir('s3://'));
        $this->assertEmpty($this->requests);
    }

    public static function mkdirAclProvider(): array
    {
        return [
            [0777, 'public-read'],
            [0755, 'public-read'],
            [0644, 'authenticated-read'],
            [0500, 'private'],
        ];
    }

    /**
     * @dataProvider mkdirAclProvider
     */
    public function testMkdirSubfolder(int $mode, string $acl): void
    {
        $this->queue($this->error(404), $this->ok());

        $this->assertTrue(mkdir('s3://bucket/dir', $mode));

        $this->assertRequest(0, 'HEAD', '/bucket/dir/');
        $request = $this->assertRequest(1, 'PUT', '/bucket/dir/');
        $this->assertSame('', $request['body']);
        $this->assertSame($acl, $request['headers']['x-amz-acl']);
    }

    public function testMkdirSubfolderWithAclOption(): void
    {
        $this->queue($this->error(404), $this->ok());
        $context = stream_context_create(['s3' => ['ACL' => 'bucket-owner-full-control']]);

        $this->assertTrue(mkdir('s3://bucket/dir/', 0777, false, $context));
        $this->assertSame('bucket-owner-full-control', $this->request(1)['headers']['x-amz-acl']);
    }

    public function testMkdirExistingSubfolder(): void
    {
        $this->queue($this->head(0));

        $this->assertFalse($this->quietly(fn() => mkdir('s3://bucket/dir')));
        $this->assertStringContainsString('Subfolder already exists: s3://bucket/dir', $this->warnings[0]);
    }

    public function testRmdirBucket(): void
    {
        $this->assertTrue(rmdir('s3://bucket'));
        $this->assertRequest(0, 'DELETE', '/bucket');
    }

    public function testRmdirWithoutBucket(): void
    {
        $this->assertFalse($this->quietly(fn() => rmdir('s3://')));
        $this->assertStringContainsString('You must specify a bucket', $this->warnings[0]);
    }

    public function testRmdirSubfolder(): void
    {
        $this->queue($this->listing(['dir/']), $this->ok());

        $this->assertTrue(rmdir('s3://bucket/dir'));
        $this->assertRequest(0, 'GET', '/bucket?list-type=2&max-keys=2&prefix=dir%2F');
        $this->assertRequest(1, 'DELETE', '/bucket/dir/');
    }

    public function testRmdirEmptyPrefix(): void
    {
        $this->queue($this->listing([]));

        $this->assertTrue(rmdir('s3://bucket/dir'));
        $this->assertCount(1, $this->requests);
    }

    public function testRmdirNotEmpty(): void
    {
        $this->queue($this->listing(['dir/', 'dir/a.txt']));

        $this->assertFalse($this->quietly(fn() => rmdir('s3://bucket/dir')));
        $this->assertStringContainsString('Subfolder is not empty', $this->warnings[0]);
        $this->assertCount(1, $this->requests);
    }

    public function testRmdirNotEmptyWithoutDirectoryObject(): void
    {
        $this->queue($this->listing(['dir/a.txt']));

        $this->assertFalse($this->quietly(fn() => rmdir('s3://bucket/dir/')));
        $this->assertStringContainsString('Subfolder is not empty', $this->warnings[0]);
    }

    public function testRmdirBucketError(): void
    {
        $this->queue($this->error(409, 'BucketNotEmpty'));

        $this->assertFalse($this->quietly(fn() => rmdir('s3://bucket')));
        $this->assertStringContainsString('BucketNotEmpty', $this->warnings[0]);
    }

    public function testScandirWithPagination(): void
    {
        $this->queue(
            $this->listing(['dir/', 'dir/a.txt', 'dir/b.txt'], ['dir/sub/'], 'token-1'),
            $this->listing(['dir/c.txt'], [], 'token-2'),
            $this->listing(['dir/d.txt']),
        );

        $this->assertSame(['a.txt', 'b.txt', 'c.txt', 'd.txt', 'sub'], scandir('s3://bucket/dir'));

        $this->assertRequest(0, 'GET', '/bucket?list-type=2&delimiter=%2F&prefix=dir%2F');
        $this->assertRequest(1, 'GET', '/bucket?list-type=2&delimiter=%2F&prefix=dir%2F&continuation-token=token-1');
        $this->assertRequest(2, 'GET', '/bucket?list-type=2&delimiter=%2F&prefix=dir%2F&continuation-token=token-2');

        // readdir() caches the stats.
        $this->assertSame(3, filesize('s3://bucket/dir/a.txt'));
        $this->assertTrue(is_dir('s3://bucket/dir/sub'));
        $this->assertCount(3, $this->requests);
    }

    public function testReaddirBucket(): void
    {
        $this->queue($this->listing(['a.txt'], ['dir/']), $this->listing(['a.txt'], ['dir/']));

        $handle = opendir('s3://bucket');
        $this->assertSame('a.txt', readdir($handle));
        $this->assertSame('dir', readdir($handle));
        $this->assertFalse(readdir($handle));
        rewinddir($handle);
        $this->assertSame('a.txt', readdir($handle));
        closedir($handle);

        $this->assertRequest(0, 'GET', '/bucket?list-type=2&delimiter=%2F');
        $this->assertCount(2, $this->requests);
    }

    public function testOpendirWithoutDelimiterAndWithFilter(): void
    {
        $this->queue($this->listing(['a.txt', 'dir/b.txt', 'dir/c.csv']));
        $entries = [];
        $context = stream_context_create(['s3' => [
            'delimiter' => '',
            'listFilter' => function (array $entry) use (&$entries): bool {
                $entries[] = $entry;

                return str_ends_with($entry['Key'], '.txt');
            },
        ]]);

        $handle = opendir('s3://bucket', $context);
        $names = [];
        while (($name = readdir($handle)) !== false) {
            $names[] = $name;
        }

        $this->assertSame(['a.txt', 'dir/b.txt'], $names);
        $this->assertRequest(0, 'GET', '/bucket?list-type=2');
        $this->assertSame(['Key' => 'a.txt', 'LastModified' => '2026-01-02T03:04:05+00:00', 'ETag' => '"etag"', 'Size' => 3, 'StorageClass' => 'STANDARD'], $entries[0]);
    }

    public function testOpendirError(): void
    {
        $this->queue($this->error(404, 'NoSuchBucket'));

        $this->assertFalse($this->quietly(fn() => opendir('s3://missing')));
        $this->assertStringContainsString('NoSuchBucket', implode("\n", $this->warnings));
    }

    public function testReaddirErrorOnNextPage(): void
    {
        $this->queue($this->listing(['a.txt'], [], 'token'), $this->error(500, 'InternalError'));

        $handle = opendir('s3://bucket');
        $this->assertSame('a.txt', readdir($handle));
        $this->assertFalse($this->quietly(fn() => readdir($handle)));
        $this->assertStringContainsString('InternalError', $this->warnings[0]);
    }

    public function testUnsupportedOperations(): void
    {
        $this->assertFalse($this->quietly(fn() => touch('s3://bucket/file.txt')));
        $this->assertFalse($this->quietly(fn() => chmod('s3://bucket/file.txt', 0644)));

        $handle = fopen('s3://bucket/file.txt', 'w');
        $this->assertFalse($this->quietly(fn() => flock($handle, LOCK_EX)));
        $this->assertStringContainsString('stream_lock() is not supported by the Amazon S3 stream wrapper', implode("\n", $this->warnings));
        $this->assertFalse(ftruncate($handle, 0));
        $this->assertFalse(stream_set_blocking($handle, true));
        fclose($handle);
    }

    public function testNoClient(): void
    {
        stream_context_set_default(['s3' => ['client' => null]]);

        $this->assertFalse($this->quietly(fn() => unlink('s3://bucket/file.txt')));
        $this->assertStringContainsString('No client in stream context', $this->warnings[0]);
    }

    public function testWarningsDoNotContainSignatures(): void
    {
        $wrapper = new class extends S3StreamWrapper {
            public static function clean(string $message): string
            {
                return static::sanitize($message);
            }
        };

        $this->assertSame(
            'HTTP 403 returned for "https://b.s3.amazonaws.com/k?X-Amz-Credential=***&X-Amz-Signature=***&X-Amz-Security-Token=***".',
            $wrapper::clean('HTTP 403 returned for "https://b.s3.amazonaws.com/k?X-Amz-Credential=AKIA%2F20260101&X-Amz-Signature=abc&X-Amz-Security-Token=tok".'),
        );
    }

    public function testWriteStreamEofAndRead(): void
    {
        $handle = fopen('s3://bucket/file.txt', 'w');
        fwrite($handle, 'abc');
        $this->assertFalse(feof($handle));
        fseek($handle, 0);
        $this->assertSame('abc', fread($handle, 10));
        fclose($handle);
    }

    public function testSeekableReadWithoutContentLength(): void
    {
        $this->queue(new MockResponse((function () {
            yield 'abc';
            yield 'def';
        })()));
        $wrapper = new S3StreamWrapper();
        $wrapper->context = stream_context_create(['s3' => ['seekable' => true]]);
        $openedPath = null;

        $this->assertTrue($wrapper->stream_open('s3://bucket/file.txt', 'r', 0, $openedPath));
        $this->assertTrue($wrapper->stream_seek(-2, SEEK_END));
        $this->assertSame('ef', $wrapper->stream_read(10));
        $this->assertTrue($wrapper->stream_seek(-4, SEEK_CUR));
        $this->assertSame(2, $wrapper->stream_tell());
        $this->assertSame('cd', $wrapper->stream_read(2));
        $this->assertFalse($wrapper->stream_cast(STREAM_CAST_AS_STREAM));
        $wrapper->stream_close();
    }

    public function testReaddirWithoutOpendir(): void
    {
        $this->assertFalse((new S3StreamWrapper())->dir_readdir());
    }

    public function testExclusiveCreateWhenAccessIsDenied(): void
    {
        // As the AWS SDK, a forbidden object is treated as existing.
        $this->queue($this->error(403));

        $this->assertFalse($this->quietly(fn() => fopen('s3://bucket/new.txt', 'x')));
        $this->assertStringContainsString('already exists on Amazon S3', $this->warnings[0]);
    }

    public function testExclusiveCreateServerError(): void
    {
        $this->queue($this->error(500));

        $this->assertFalse($this->quietly(fn() => fopen('s3://bucket/new.txt', 'x')));
        $this->assertStringContainsString('HTTP 500', $this->warnings[0]);
    }

    public function testStatCacheIsLimited(): void
    {
        $keys = [];
        for ($i = 0; $i <= S3StreamWrapper::CACHE_SIZE; $i++) {
            $keys[] = sprintf('%04d.txt', $i);
        }
        $this->queue($this->listing($keys), $this->head(7));

        $this->assertCount(S3StreamWrapper::CACHE_SIZE + 1, scandir('s3://bucket'));

        // The first entry was removed from the cache, the last one is still there.
        $this->assertSame(3, filesize('s3://bucket/1000.txt'));
        $this->assertCount(1, $this->requests);
        $this->assertSame(7, filesize('s3://bucket/0000.txt'));
        $this->assertCount(2, $this->requests);
    }
}

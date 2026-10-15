<?php

use AsyncAws\SimpleS3\SimpleS3Client;
use Composer\InstalledVersions;

App::uses('File', 'Utility');
App::uses('Folder', 'Utility');
App::uses('S3StreamWrapper', 'AsyncAws.Lib');

/**
 * Runs the S3 stream wrapper against an S3 compatible server, e.g. MinIO.
 *
 * Skipped unless the `S3_ENDPOINT` environment variable is set, e.g. `http://localhost:9000`.
 * `S3_ACCESS_KEY` and `S3_SECRET_KEY` set the credentials (default: `minioadmin`).
 */
class S3StreamWrapperIntegrationTest extends CakeTestCase
{
    private SimpleS3Client $client;

    /**
     * Buckets created by the test, deleted by `tearDown()`.
     *
     * @var array<string>
     */
    private array $buckets = [];

    private string $bucket;

    public function setUp(): void
    {
        parent::setUp();
        $endpoint = getenv('S3_ENDPOINT');
        if (!$endpoint) {
            $this->markTestSkipped('Set S3_ENDPOINT to run the tests against an S3 compatible server.');
        }

        Configure::write('AsyncAws', [
            'region' => 'us-east-1',
            'endpoint' => $endpoint,
            'pathStyleEndpoint' => true,
            'accessKeyId' => getenv('S3_ACCESS_KEY') ?: 'minioadmin',
            'accessKeySecret' => getenv('S3_SECRET_KEY') ?: 'minioadmin',
        ]);
        $this->assertTrue(S3StreamWrapper::bootstrap());
        $this->client = new SimpleS3Client(...AsyncAwsComponent::clientArguments());
        $this->bucket = $this->createBucket();
    }

    public function tearDown(): void
    {
        foreach ($this->buckets as $bucket) {
            $this->emptyBucket($bucket);
            $this->client->deleteBucket(['Bucket' => $bucket])->resolve();
        }
        if (in_array('s3', stream_get_wrappers(), true)) {
            stream_wrapper_unregister('s3');
        }
        S3StreamWrapper::clearCache();
        Configure::delete('AsyncAws');
        parent::tearDown();
    }

    /**
     * Calls a function and returns its result and the warnings it triggered.
     *
     * @param callable $fn Function.
     * @return array{0: mixed, 1: array<string>}
     */
    private function quietly(callable $fn): array
    {
        $warnings = [];
        set_error_handler(function (int $errno, string $message) use (&$warnings): bool {
            $warnings[] = $message;

            return true;
        });
        try {
            return [$fn(), $warnings];
        } finally {
            restore_error_handler();
        }
    }

    private function createBucket(): string
    {
        $bucket = 'wrapper-' . bin2hex(random_bytes(6));
        $this->client->createBucket(['Bucket' => $bucket])->resolve();
        $this->buckets[] = $bucket;

        return $bucket;
    }

    private function emptyBucket(string $bucket): void
    {
        $keys = [];
        foreach ($this->client->listObjectsV2(['Bucket' => $bucket])->getContents() as $object) {
            $keys[] = ['Key' => $object->getKey()];
        }
        foreach (array_chunk($keys, 1000) as $chunk) {
            $this->client->deleteObjects(['Bucket' => $bucket, 'Delete' => ['Objects' => $chunk, 'Quiet' => true]])->resolve();
        }
    }

    private function path(string $key = ''): string
    {
        return 's3://' . $this->bucket . ($key === '' ? '' : '/' . $key);
    }

    public function testRoundTrip(): void
    {
        $handle = fopen($this->path('round/trip.txt'), 'w');
        $this->assertSame(6, fwrite($handle, 'hello '));
        $this->assertSame(5, fwrite($handle, 'world'));
        $this->assertTrue(fclose($handle));

        $handle = fopen($this->path('round/trip.txt'), 'r');
        $contents = '';
        while (!feof($handle)) {
            $contents .= fread($handle, 4);
        }
        fclose($handle);
        $this->assertSame('hello world', $contents);

        $head = $this->client->headObject(['Bucket' => $this->bucket, 'Key' => 'round/trip.txt']);
        $this->assertSame('text/plain', $head->getContentType());
    }

    public function testFilePutAndGetContents(): void
    {
        $data = random_bytes(100000);
        $this->assertSame(100000, file_put_contents($this->path('data.bin'), $data));
        $this->assertSame($data, file_get_contents($this->path('data.bin')));
        $this->assertSame('', file_get_contents($this->path('data.bin'), false, null, 0, 0));
    }

    public function testEmptyFile(): void
    {
        fclose(fopen($this->path('empty.txt'), 'w'));

        $this->assertTrue(is_file($this->path('empty.txt')));
        $this->assertSame(0, filesize($this->path('empty.txt')));
        $this->assertSame('', file_get_contents($this->path('empty.txt')));
    }

    public function testContextOptions(): void
    {
        $context = stream_context_create(['s3' => [
            'ContentType' => 'application/json',
            'CacheControl' => 'max-age=60',
            'Metadata' => ['owner' => 'tests'],
        ]]);
        file_put_contents($this->path('data'), '{}', 0, $context);

        $head = $this->client->headObject(['Bucket' => $this->bucket, 'Key' => 'data']);
        $this->assertSame('application/json', $head->getContentType());
        $this->assertSame('max-age=60', $head->getCacheControl());
        $this->assertSame(['owner' => 'tests'], $head->getMetadata());
    }

    public function testAppend(): void
    {
        $this->assertSame(4, file_put_contents($this->path('log.txt'), "one\n", FILE_APPEND));
        $this->assertSame(4, file_put_contents($this->path('log.txt'), "two\n", FILE_APPEND));
        $handle = fopen($this->path('log.txt'), 'a');
        $this->assertSame(8, ftell($handle));
        fwrite($handle, "three\n");
        fclose($handle);

        $this->assertSame("one\ntwo\nthree\n", file_get_contents($this->path('log.txt')));
    }

    public function testExclusiveCreate(): void
    {
        $handle = fopen($this->path('x.txt'), 'x');
        fwrite($handle, 'first');
        fclose($handle);

        [$result, $warnings] = $this->quietly(fn() => fopen($this->path('x.txt'), 'x'));
        $this->assertFalse($result);
        $this->assertStringContainsString('already exists on Amazon S3', $warnings[0]);
        $this->assertSame('first', file_get_contents($this->path('x.txt')));
    }

    public function testSeekableRead(): void
    {
        file_put_contents($this->path('seek.txt'), 'abcdefghij');

        $handle = fopen($this->path('seek.txt'), 'r');
        $this->assertSame('abc', fread($handle, 3));
        $this->assertSame(-1, fseek($handle, 0));
        fclose($handle);

        $context = stream_context_create(['s3' => ['seekable' => true]]);
        $handle = fopen($this->path('seek.txt'), 'r', false, $context);
        $this->assertSame('abc', fread($handle, 3));
        $this->assertSame(0, fseek($handle, 0));
        $this->assertSame('abcdef', fread($handle, 6));
        $this->assertSame(0, fseek($handle, -2, SEEK_END));
        $this->assertSame('ij', fread($handle, 10));
        fclose($handle);
    }

    public function testStat(): void
    {
        $before = time();
        file_put_contents($this->path('dir/sub/file.txt'), '12345');

        $this->assertTrue(file_exists($this->path('dir/sub/file.txt')));
        $this->assertTrue(is_file($this->path('dir/sub/file.txt')));
        $this->assertFalse(is_dir($this->path('dir/sub/file.txt')));
        $this->assertSame(5, filesize($this->path('dir/sub/file.txt')));
        $this->assertEqualsWithDelta($before, filemtime($this->path('dir/sub/file.txt')), 60);

        $this->assertTrue(is_dir($this->path('dir')));
        $this->assertTrue(is_dir($this->path('dir/sub/')));
        $this->assertFalse(is_file($this->path('dir')));
        $this->assertTrue(is_dir($this->path()));
        $this->assertTrue(is_dir('s3://'));

        $this->assertFalse(file_exists($this->path('missing.txt')));
        $this->assertFalse(is_dir($this->path('missing')));
        $this->assertFalse(is_dir('s3://missing-' . bin2hex(random_bytes(6))));
        $this->assertFalse($this->quietly(fn() => filesize($this->path('missing.txt')))[0]);
    }

    public function testUnlink(): void
    {
        file_put_contents($this->path('delete.txt'), 'x');
        $this->assertTrue(file_exists($this->path('delete.txt')));

        $this->assertTrue(unlink($this->path('delete.txt')));
        $this->assertFalse(file_exists($this->path('delete.txt')));
    }

    public function testRename(): void
    {
        $context = stream_context_create(['s3' => ['ContentType' => 'text/x-custom', 'Metadata' => ['a' => 'b']]]);
        file_put_contents($this->path('old name.txt'), 'renamed', 0, $context);

        $this->assertTrue(rename($this->path('old name.txt'), $this->path('new/name.txt')));
        $this->assertFalse(file_exists($this->path('old name.txt')));
        $this->assertSame('renamed', file_get_contents($this->path('new/name.txt')));
        $head = $this->client->headObject(['Bucket' => $this->bucket, 'Key' => 'new/name.txt']);
        $this->assertSame('text/x-custom', $head->getContentType());
        $this->assertSame(['a' => 'b'], $head->getMetadata());

        $other = $this->createBucket();
        $this->assertTrue(rename($this->path('new/name.txt'), "s3://{$other}/moved.txt"));
        $this->assertFalse(file_exists($this->path('new/name.txt')));
        $this->assertSame('renamed', file_get_contents("s3://{$other}/moved.txt"));
    }

    public function testCopy(): void
    {
        file_put_contents($this->path('source.txt'), 'copied');
        $this->assertTrue(copy($this->path('source.txt'), $this->path('target.txt')));
        $this->assertSame('copied', file_get_contents($this->path('target.txt')));

        $local = TMP . 'tests' . DS . 's3wrapper-' . bin2hex(random_bytes(4)) . '.txt';
        $this->assertTrue(copy($this->path('source.txt'), $local));
        $this->assertSame('copied', file_get_contents($local));
        $this->assertTrue(copy($local, $this->path('from-local.txt')));
        unlink($local);
        $this->assertSame('copied', file_get_contents($this->path('from-local.txt')));
    }

    public function testMkdirAndRmdir(): void
    {
        $context = stream_context_create(['s3' => ['ACL' => 'private']]);
        $this->assertTrue(mkdir($this->path('folder'), 0777, false, $context));
        $this->assertTrue(is_dir($this->path('folder')));
        $this->assertFalse($this->quietly(fn() => mkdir($this->path('folder'), 0777, false, $context))[0]);
        $this->assertSame([], scandir($this->path('folder')));

        file_put_contents($this->path('folder/file.txt'), 'x');
        [$result, $warnings] = $this->quietly(fn() => rmdir($this->path('folder')));
        $this->assertFalse($result);
        $this->assertStringContainsString('Subfolder is not empty', $warnings[0]);
        unlink($this->path('folder/file.txt'));

        $this->assertTrue(rmdir($this->path('folder')));
        $this->assertFalse(is_dir($this->path('folder')));
    }

    public function testMkdirWithDefaultMode(): void
    {
        // 0777 sends the public-read ACL, as the AWS SDK does.
        $this->assertTrue(mkdir($this->path('public')));
        $this->assertTrue(is_dir($this->path('public')));
    }

    public function testMkdirAndRmdirBucket(): void
    {
        $bucket = 'wrapper-' . bin2hex(random_bytes(6));
        $this->assertTrue(mkdir("s3://{$bucket}"));
        $this->assertTrue(is_dir("s3://{$bucket}"));
        $this->assertFalse($this->quietly(fn() => mkdir("s3://{$bucket}"))[0]);
        $this->assertTrue(rmdir("s3://{$bucket}"));
        S3StreamWrapper::clearCache();
        $this->assertFalse(is_dir("s3://{$bucket}"));
    }

    public function testScandirWithPagination(): void
    {
        $results = [];
        for ($i = 0; $i < 1005; $i++) {
            $results[] = $this->client->putObject(['Bucket' => $this->bucket, 'Key' => sprintf('many/%04d.txt', $i), 'Body' => (string)$i]);
        }
        $results[] = $this->client->putObject(['Bucket' => $this->bucket, 'Key' => 'many/sub/nested.txt', 'Body' => 'x']);
        foreach ($results as $result) {
            $result->resolve();
        }

        $entries = scandir($this->path('many'));
        $this->assertCount(1006, $entries);
        $this->assertSame('0000.txt', $entries[0]);
        $this->assertSame('1004.txt', $entries[1004]);
        $this->assertSame('sub', $entries[1005]);

        $this->assertSame(['many'], scandir($this->path()));

        $files = 0;
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->path('many'), FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            $files++;
        }
        $this->assertSame(1006, $files);
    }

    public function testLargeFileMultipart(): void
    {
        $size = 20 * 1024 * 1024 + 123;
        $source = fopen('php://temp', 'w+');
        for ($written = 0; $written < $size; $written += 1024 * 1024) {
            fwrite($source, random_bytes(min(1024 * 1024, $size - $written)));
        }
        rewind($source);
        $hash = hash_init('sha256');
        hash_update_stream($hash, $source);
        $expected = hash_final($hash);
        rewind($source);

        $context = stream_context_create(['s3' => ['PartSize' => 8]]);
        $target = fopen($this->path('large.bin'), 'w', false, $context);
        $this->assertSame($size, stream_copy_to_stream($source, $target));
        $this->assertTrue(fclose($target));

        $head = $this->client->headObject(['Bucket' => $this->bucket, 'Key' => 'large.bin']);
        $this->assertSame($size, $head->getContentLength());
        if (version_compare((string)InstalledVersions::getVersion('async-aws/simple-s3'), '3.0.0', '>=')) {
            // async-aws/simple-s3 2.x uploads bodies up to 64 MB with PutObject, whatever the PartSize.
            $this->assertStringEndsWith('-3"', (string)$head->getETag(), 'The object was uploaded in 3 parts.');
        }

        $hash = hash_init('sha256');
        $handle = fopen($this->path('large.bin'), 'r');
        hash_update_stream($hash, $handle);
        fclose($handle);
        $this->assertSame($expected, hash_final($hash));
    }

    public function testCakeFileAndFolderWithS3Protocol(): void
    {
        // Folder::isAbsolute() accepts only letters in the protocol of a stream wrapper, so File and Folder
        // treat s3:// paths as relative paths (as with the AWS SDK's stream wrapper).
        $this->assertFalse(Folder::isAbsolute($this->path('cake')));
        $this->assertNull((new Folder($this->path('cake')))->pwd());
    }

    public function testCakeFileAndFolder(): void
    {
        // With a protocol of letters only, File and Folder work.
        S3StreamWrapper::register($this->client, 'cakes');
        try {
            $path = 'cakes://' . $this->bucket . '/cake';

            // File::write() cannot create an object: File::create() needs touch(), which the stream wrapper does
            // not support (as the AWS SDK's).
            $file = new File($path . '/new.txt');
            $this->assertFalse($this->quietly(fn() => $file->write('new'))[0]);
            $this->assertFalse($file->exists());

            // Objects that exist can be read, overwritten, appended to and deleted.
            file_put_contents($path . '/file.txt', '');
            $file = new File($path . '/file.txt');
            $this->assertTrue($file->exists());
            $this->assertTrue($file->write('written by File'));
            $file->close();
            $this->assertSame('written by File', $file->read());
            $this->assertSame(15, $file->size());
            $this->assertTrue($file->append(' and appended'));
            $file->close();
            $this->assertSame('written by File and appended', file_get_contents($path . '/file.txt'));

            $folder = new Folder($path);
            $this->assertSame([[], ['file.txt']], $folder->read());
            $this->assertSame(['file.txt'], $folder->find('.*\.txt'));
            $this->assertTrue($file->delete());

            $folder = new Folder($path . '/made', true, 0700);
            $this->assertSame($path . '/made', $folder->pwd());
            $this->assertTrue(is_dir($path . '/made'));
        } finally {
            stream_wrapper_unregister('cakes');
        }
    }
}

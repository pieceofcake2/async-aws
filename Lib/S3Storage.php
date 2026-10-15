<?php

use AsyncAws\SimpleS3\SimpleS3Client;

App::uses('ComponentCollection', 'Controller');
App::uses('AsyncAwsComponent', 'AsyncAws.Controller/Component');
App::uses('MissingPackageException', 'AsyncAws.Error');

/**
 * S3 storage helper
 *
 * A few file-like operations on S3 objects with `async-aws/simple-s3`, with the same `s3://bucket/key` paths
 * as the stream wrapper (`S3StreamWrapper`), but with methods that throw exceptions on errors.
 * Requires `async-aws/simple-s3`.
 *
 * ```
 * $storage = new S3Storage();
 * $storage->write('s3://my-bucket/reports/today.csv', $csv, ['ContentType' => 'text/csv']);
 * $csv = $storage->read('s3://my-bucket/reports/today.csv');
 * ```
 */
class S3Storage
{
    private SimpleS3Client $client;

    /**
     * Constructor
     *
     * @param \AsyncAws\SimpleS3\SimpleS3Client|null $client S3 client. Default: a client created with the plugin configuration.
     * @throws \MissingPackageException When async-aws/simple-s3 is not installed.
     */
    public function __construct(?SimpleS3Client $client = null)
    {
        MissingPackageException::check(static::clientClass(), 'async-aws/simple-s3', 'S3Storage');
        $this->client = $client ?? (new AsyncAwsComponent(new ComponentCollection()))->createSimpleS3();
    }

    /**
     * Returns the class of the SimpleS3 client, which is checked to exist before the client is created.
     *
     * @return string
     */
    protected static function clientClass(): string
    {
        return SimpleS3Client::class;
    }

    /**
     * Returns the S3 client.
     *
     * @return \AsyncAws\SimpleS3\SimpleS3Client
     */
    public function client(): SimpleS3Client
    {
        return $this->client;
    }

    /**
     * Uploads an object. Large objects are uploaded in parts.
     *
     * @param string $path `s3://bucket/key`
     * @param resource|string $contents Contents, or a stream to read them from.
     * @param array $options Options of `SimpleS3Client::upload()`, e.g. `ContentType`, `CacheControl`, `ACL`, `Metadata`.
     * @return void
     */
    public function write(string $path, $contents, array $options = []): void
    {
        [$bucket, $key] = static::parsePath($path);
        $this->client->upload($bucket, $key, $contents, $options);
    }

    /**
     * Downloads an object and returns its contents.
     *
     * @param string $path `s3://bucket/key`
     * @return string
     * @throws \AsyncAws\S3\Exception\NoSuchKeyException When the object does not exist.
     */
    public function read(string $path): string
    {
        [$bucket, $key] = static::parsePath($path);

        return $this->client->download($bucket, $key)->getContentAsString();
    }

    /**
     * Downloads an object and returns a stream of its contents.
     *
     * @param string $path `s3://bucket/key`
     * @return resource
     * @throws \AsyncAws\S3\Exception\NoSuchKeyException When the object does not exist.
     */
    public function readStream(string $path)
    {
        [$bucket, $key] = static::parsePath($path);

        return $this->client->download($bucket, $key)->getContentAsResource();
    }

    /**
     * Checks whether an object exists.
     *
     * @param string $path `s3://bucket/key`
     * @return bool
     */
    public function exists(string $path): bool
    {
        [$bucket, $key] = static::parsePath($path);

        return $this->client->has($bucket, $key);
    }

    /**
     * Deletes an object. Deleting an object that does not exist is not an error.
     *
     * @param string $path `s3://bucket/key`
     * @return void
     */
    public function delete(string $path): void
    {
        [$bucket, $key] = static::parsePath($path);
        $this->client->deleteObject(['Bucket' => $bucket, 'Key' => $key])->resolve();
    }

    /**
     * Returns the URL of an object (not signed).
     *
     * @param string $path `s3://bucket/key`
     * @return string
     */
    public function url(string $path): string
    {
        [$bucket, $key] = static::parsePath($path);

        return $this->client->getUrl($bucket, $key);
    }

    /**
     * Returns a presigned URL to download an object.
     *
     * @param string $path `s3://bucket/key`
     * @param \DateTimeImmutable|null $expires Expiration time. Default: AsyncAws's default (60 minutes).
     * @return string
     */
    public function presignedUrl(string $path, ?DateTimeImmutable $expires = null): string
    {
        [$bucket, $key] = static::parsePath($path);

        return $this->client->getPresignedUrl($bucket, $key, $expires);
    }

    /**
     * Splits `s3://bucket/key` (or `bucket/key`) into the bucket and the key.
     *
     * @param string $path `s3://bucket/key`
     * @return array{0: string, 1: string}
     * @throws \InvalidArgumentException When the path has no bucket or no key.
     */
    public static function parsePath(string $path): array
    {
        if (str_starts_with($path, 's3://')) {
            $path = substr($path, 5);
        }
        $parts = explode('/', $path, 2);
        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            throw new InvalidArgumentException(sprintf('"%s" is not an S3 path like "s3://bucket/key".', $path));
        }

        return [$parts[0], $parts[1]];
    }
}

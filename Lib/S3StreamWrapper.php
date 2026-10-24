<?php

use AsyncAws\Core\Exception\Http\HttpException;
use AsyncAws\Core\Waiter;
use AsyncAws\S3\Result\HeadObjectOutput;
use AsyncAws\S3\S3Client;
use AsyncAws\S3\ValueObject\CommonPrefix;
use AsyncAws\SimpleS3\SimpleS3Client;

/**
 * Amazon S3 stream wrapper
 *
 * Lets PHP's file functions use `s3://bucket/key` paths with an AsyncAws S3 client. It is a port of
 * `Aws\S3\StreamWrapper` of the AWS SDK for PHP (used by pieceofcake2/aws) and has the same scope and limits:
 *
 *  - `fopen()` modes `r`, `w`, `a` and `x` (with `b` or `t`). `r+`, `w+`, `c`, ... are not supported.
 *  - `r` streams the object with GetObject. The stream is not seekable unless the `seekable` context option is
 *    `true`; then the bytes read are kept in a `php://temp` buffer so that the stream can seek backward (and
 *    forward, by reading ahead).
 *  - `w` and `x` buffer the written data in `php://temp` and upload it when the stream is flushed or closed.
 *    `x` fails when the object exists. `a` downloads the object, appends to it, and uploads the result.
 *    With a `SimpleS3Client`, bodies larger than 64 MB are uploaded in parts; with an `S3Client`, PutObject is
 *    used (up to 5 GB).
 *  - `url_stat()` (`file_exists()`, `is_file()`, `is_dir()`, `filesize()`, `filemtime()`, ...) uses HeadObject,
 *    and a ListObjectsV2 prefix check for "directories". Buckets and `s3://` are directories. Results are cached
 *    in the process; see `clearCache()`.
 *  - `unlink()`, `rename()` (CopyObject and DeleteObject, up to 5 GB), `mkdir()` (a bucket, or a `dir/` object),
 *    `rmdir()`, `opendir()`/`readdir()`/`scandir()` (ListObjectsV2 with a delimiter and pagination).
 *  - `touch()`, `chmod()`, `flock()` and `ftruncate()` are not supported.
 *
 * Other context options (`stream_context_create(['s3' => [...]])`) are passed to the S3 operations, e.g.
 * `ACL`, `ContentType`, `CacheControl`, `Metadata`, `ServerSideEncryption` or `VersionId`. Errors trigger an
 * `E_USER_WARNING` and the operation returns `false`.
 *
 * Requires `async-aws/s3`; `bootstrap()` requires `async-aws/simple-s3`.
 */
class S3StreamWrapper
{
    /**
     * Maximum number of `url_stat()` results kept in the cache.
     *
     * @var int
     */
    public const CACHE_SIZE = 1000;

    /**
     * Stream context, set by PHP.
     *
     * @var resource|null
     */
    public $context;

    /**
     * Cached `url_stat()` results, by path.
     *
     * @var array<string, array<int|string, int>>
     */
    private static array $cache = [];

    /**
     * Buffer of a write stream, or of a seekable read stream.
     *
     * @var resource|null
     */
    private $body = null;

    /**
     * Chunks of the body of a read stream.
     *
     * @var \Iterator<mixed, string>|null
     */
    private ?Iterator $chunks = null;

    /**
     * Bytes of a non-seekable read stream received but not read yet.
     *
     * @var string
     */
    private string $buffer = '';

    private int $position = 0;

    private bool $remoteEof = false;

    private bool $seekable = false;

    private ?int $size = null;

    /**
     * Bucket and key of the opened stream.
     *
     * @var array<string, mixed>
     */
    private array $params = [];

    private string $mode = '';

    private string $protocol = 's3';

    private bool $isFlushed = false;

    /**
     * Entries of the opened directory.
     *
     * @var \Iterator<mixed, array<string, mixed>>|null
     */
    private ?Iterator $objectIterator = null;

    private string $openedBucket = '';

    private ?string $openedBucketPrefix = null;

    private string $openedPath = '';

    /**
     * Whether `dir_readdir()` must move to the next entry first. The iterator moves lazily, so that an error on
     * the next page is reported by the `readdir()` call that needs it.
     *
     * @var bool
     */
    private bool $advance = false;

    /**
     * Registers the stream wrapper with a client created from the plugin configuration.
     *
     * Called by the plugin's bootstrap (`CakePlugin::load('AsyncAws', ['bootstrap' => true])`). When
     * async-aws/simple-s3 is not installed, a warning is logged and the wrapper is not registered.
     *
     * @param string $protocol Protocol to register.
     * @return bool Whether the wrapper was registered.
     */
    public static function bootstrap(string $protocol = 's3'): bool
    {
        if (!class_exists(static::clientClass())) {
            CakeLog::warning(MissingPackageException::message('async-aws/simple-s3', 'The S3 stream wrapper') . '. The s3:// stream wrapper is not registered.');

            return false;
        }
        static::register(new SimpleS3Client(...AsyncAwsComponent::clientArguments()), $protocol);

        return true;
    }

    /**
     * Returns the class of the client created by `bootstrap()`, which is checked to exist first.
     *
     * @return string
     */
    protected static function clientClass(): string
    {
        return SimpleS3Client::class;
    }

    /**
     * Registers the stream wrapper, replacing any wrapper of the protocol.
     *
     * @param \AsyncAws\S3\S3Client $client Client used by the wrapper. A `SimpleS3Client` uploads large bodies in parts.
     * @param string $protocol Protocol to register.
     * @return void
     */
    public static function register(S3Client $client, string $protocol = 's3'): void
    {
        if (in_array($protocol, stream_get_wrappers(), true)) {
            stream_wrapper_unregister($protocol);
        }
        stream_wrapper_register($protocol, static::class, STREAM_IS_URL);

        $default = stream_context_get_options(stream_context_get_default());
        $default[$protocol]['client'] = $client;
        stream_context_set_default($default);
        self::$cache = [];
    }

    /**
     * Clears the cache of `url_stat()` results, e.g. after another process changed the objects.
     *
     * @return void
     */
    public static function clearCache(): void
    {
        self::$cache = [];
    }

    /**
     * Closes the stream, uploading an empty body that was not flushed.
     *
     * @return void
     */
    public function stream_close(): void
    {
        if (!$this->isFlushed && empty($this->getSize()) && $this->mode !== 'r') {
            $this->stream_flush();
        }
        if (is_resource($this->body)) {
            fclose($this->body);
        }
        $this->body = null;
        $this->chunks = null;
    }

    /**
     * Opens an object.
     *
     * @param string $path `s3://bucket/key`
     * @param string $mode `r`, `w`, `a` or `x`, with `b` or `t`.
     * @param int $options Flags set by PHP.
     * @param string|null $opened_path Not used.
     * @return bool
     */
    public function stream_open($path, $mode, $options, &$opened_path): bool
    {
        $this->initProtocol($path);
        $this->isFlushed = false;
        $this->params = $this->getBucketKey($path);
        $this->mode = rtrim($mode, 'bt');

        return $this->boolCall(function () use ($path) {
            $errors = $this->validate($path, $this->mode);
            if ($errors) {
                return $this->triggerError($errors);
            }

            switch ($this->mode) {
                case 'r':
                    return $this->openReadStream();
                case 'a':
                    return $this->openAppendStream();
                default:
                    return $this->openWriteStream();
            }
        });
    }

    /**
     * Returns whether the end of the stream is reached.
     *
     * @return bool
     */
    public function stream_eof(): bool
    {
        if ($this->mode !== 'r') {
            return feof($this->body);
        }
        if ($this->seekable) {
            return $this->remoteEof && $this->position >= $this->bufferedSize();
        }

        return $this->remoteEof && $this->buffer === '';
    }

    /**
     * Uploads the buffered data of a write stream.
     *
     * @return bool
     */
    public function stream_flush(): bool
    {
        $this->isFlushed = true;
        if ($this->mode === 'r') {
            return false;
        }

        $params = $this->getOptions(true);
        if (!isset($params['ContentType'])) {
            $type = static::mimeType((string)$params['Key']);
            if ($type !== null) {
                $params['ContentType'] = $type;
            }
        }
        $this->clearCacheKey("{$this->protocol}://{$params['Bucket']}/{$params['Key']}");

        return $this->boolCall(function () use ($params) {
            $position = (int)ftell($this->body);
            rewind($this->body);
            try {
                $client = $this->getClient();
                if ($client instanceof SimpleS3Client) {
                    $bucket = (string)$params['Bucket'];
                    $key = (string)$params['Key'];
                    unset($params['Bucket'], $params['Key'], $params['Body']);
                    $client->upload($bucket, $key, $this->body, ['ContentLength' => $this->getSize()] + $params);
                } else {
                    $client->putObject(['Body' => $this->body, 'ContentLength' => $this->getSize()] + $params)->resolve();
                }
            } finally {
                fseek($this->body, $position);
            }

            return true;
        });
    }

    /**
     * Reads from the stream.
     *
     * @param int $count Number of bytes to read.
     * @return string|false
     */
    public function stream_read($count)
    {
        if ($this->mode !== 'r') {
            return fread($this->body, $count);
        }

        return $this->boolCall(function () use ($count) {
            if ($this->seekable) {
                $this->fill($this->position + $count);
                fseek($this->body, $this->position);
                $data = (string)fread($this->body, $count);
            } else {
                // Receive until $count bytes are buffered or the body ends.
                $buffered = strlen($this->buffer);
                while ($buffered < $count && $this->nextChunk()) {
                    $buffered = strlen($this->buffer);
                }
                $data = substr($this->buffer, 0, $count);
                $this->buffer = (string)substr($this->buffer, strlen($data));
            }
            $this->position += strlen($data);

            return $data;
        });
    }

    /**
     * Seeks to a position. Read streams can seek only with the `seekable` context option.
     *
     * @param int $offset Offset.
     * @param int $whence `SEEK_SET`, `SEEK_CUR` or `SEEK_END`.
     * @return bool
     */
    public function stream_seek($offset, $whence = SEEK_SET): bool
    {
        if ($this->mode !== 'r') {
            return fseek($this->body, $offset, $whence) === 0;
        }
        if (!$this->seekable) {
            return false;
        }

        return $this->boolCall(function () use ($offset, $whence) {
            switch ($whence) {
                case SEEK_CUR:
                    $target = $this->position + $offset;
                    break;
                case SEEK_END:
                    if ($this->size === null) {
                        $this->fill(PHP_INT_MAX);
                    }
                    $target = ($this->size ?? $this->bufferedSize()) + $offset;
                    break;
                default:
                    $target = $offset;
            }
            if ($target < 0) {
                return false;
            }
            $this->fill($target);
            if ($target > $this->bufferedSize()) {
                throw new RuntimeException(sprintf('Cannot seek to byte %d when the buffered stream only contains %d bytes', $target, $this->bufferedSize()));
            }
            $this->position = $target;

            return true;
        });
    }

    /**
     * Returns the position in the stream.
     *
     * @return int
     */
    public function stream_tell(): int
    {
        if ($this->mode !== 'r') {
            return (int)ftell($this->body);
        }

        return $this->position;
    }

    /**
     * Writes to the buffer of a write stream.
     *
     * @param string $data Data.
     * @return int Number of bytes written.
     */
    public function stream_write($data): int
    {
        if ($this->mode === 'r') {
            return 0;
        }

        return (int)fwrite($this->body, $data);
    }

    /**
     * Deletes an object. Context options are passed to DeleteObject (e.g. `VersionId`).
     *
     * @param string $path `s3://bucket/key`
     * @return bool
     */
    public function unlink($path): bool
    {
        $this->initProtocol($path);

        return $this->boolCall(function () use ($path) {
            $this->clearCacheKey($path);
            $this->getClient()->deleteObject($this->withPath($path))->resolve();

            return true;
        });
    }

    /**
     * Returns information about the opened stream.
     *
     * @return array<int|string, int>
     */
    public function stream_stat(): array
    {
        $stat = $this->getStatTemplate();
        $stat[7] = $stat['size'] = (int)$this->getSize();
        $stat[2] = $stat['mode'] = 0100777;

        return $stat;
    }

    /**
     * Provides the information for `is_dir()`, `is_file()`, `filesize()`, `filemtime()`, ... of buckets, objects and prefixes.
     *
     * @param string $path `s3://bucket/key`
     * @param int $flags `STREAM_URL_STAT_QUIET` and `STREAM_URL_STAT_LINK`.
     * @return array<int|string, int>|false
     */
    public function url_stat($path, $flags)
    {
        $this->initProtocol($path);

        // Some paths come through as S3:// for some reason.
        $split = explode('://', $path, 2);
        $path = strtolower($split[0]) . '://' . ($split[1] ?? '');

        if (isset(self::$cache[$path])) {
            return self::$cache[$path];
        }

        $stat = $this->createStat($path, $flags);
        if (is_array($stat)) {
            $this->setCache($path, $stat);
        }

        return $stat;
    }

    /**
     * Creates a bucket (`s3://bucket`) or a "directory" object (`s3://bucket/dir/`).
     *
     * Unless the `ACL` context option is set, the ACL is chosen from `$mode` as the AWS SDK does: `07xx` is
     * `public-read`, `06xx` is `authenticated-read`, others are `private`. `STREAM_MKDIR_RECURSIVE` is ignored.
     *
     * @param string $path `s3://bucket` or `s3://bucket/dir`
     * @param int $mode Permissions.
     * @param int $options Flags set by PHP.
     * @return bool
     */
    public function mkdir($path, $mode, $options): bool
    {
        $this->initProtocol($path);
        $params = $this->withPath($path);
        $this->clearCacheKey($path);
        if (!$params['Bucket']) {
            return false;
        }

        if (!isset($params['ACL'])) {
            $params['ACL'] = $this->determineAcl($mode);
        }

        return $this->boolCall(function () use ($path, $params) {
            return empty($params['Key'])
                ? $this->createBucket($path, $params)
                : $this->createSubfolder($path, $params);
        });
    }

    /**
     * Deletes a bucket, or the "directory" object of an empty prefix.
     *
     * @param string $path `s3://bucket` or `s3://bucket/dir`
     * @param int $options Flags set by PHP.
     * @return bool
     */
    public function rmdir($path, $options): bool
    {
        $this->initProtocol($path);
        $this->clearCacheKey($path);
        $params = $this->withPath($path);

        if (!$params['Bucket']) {
            return $this->triggerError('You must specify a bucket');
        }

        return $this->boolCall(function () use ($params, $path) {
            if (!$params['Key']) {
                $this->getClient()->deleteBucket(['Bucket' => $params['Bucket']])->resolve();

                return true;
            }

            return $this->deleteSubfolder($path, $params);
        });
    }

    /**
     * Opens a "directory": a bucket or a prefix.
     *
     * The `delimiter` context option sets the delimiter (default: `/`; an empty string lists all keys), and
     * `listFilter` a callable that receives each entry (`Key`, `Size`, `LastModified`, ... or `Prefix`) and
     * returns whether to include it.
     *
     * @param string $path `s3://bucket` or `s3://bucket/prefix`
     * @param int $options Flags set by PHP.
     * @return bool
     */
    public function dir_opendir($path, $options): bool
    {
        $this->initProtocol($path);
        $this->openedPath = $path;
        $params = $this->withPath($path);
        $delimiter = $this->getOption('delimiter');
        $filterFn = $this->getOption('listFilter');
        $op = ['Bucket' => $params['Bucket']];
        $this->openedBucket = (string)$params['Bucket'];

        if ($delimiter === null) {
            $delimiter = '/';
        }
        if ($delimiter) {
            $op['Delimiter'] = $delimiter;
        }
        if ($params['Key']) {
            $params['Key'] = rtrim($params['Key'], $delimiter) . $delimiter;
            $op['Prefix'] = $params['Key'];
        }
        $this->openedBucketPrefix = $params['Key'];

        return $this->boolCall(function () use ($op, $filterFn) {
            $result = $this->getClient()->listObjectsV2($op);
            $result->resolve();
            $this->objectIterator = $this->listEntries($result, is_callable($filterFn) ? $filterFn : null);
            $this->advance = false;

            return true;
        });
    }

    /**
     * Closes the directory.
     *
     * @return bool
     */
    public function dir_closedir(): bool
    {
        $this->objectIterator = null;

        return true;
    }

    /**
     * Lists the directory again.
     *
     * @return bool
     */
    public function dir_rewinddir(): bool
    {
        $this->objectIterator = null;

        return $this->dir_opendir($this->openedPath, 0);
    }

    /**
     * Returns the next entry of the directory, without the directory's prefix.
     *
     * @return string|false
     */
    public function dir_readdir()
    {
        return $this->boolCall(function () {
            if ($this->objectIterator === null) {
                return false;
            }
            if ($this->advance) {
                $this->advance = false;
                $this->objectIterator->next();
            }
            if (!$this->objectIterator->valid()) {
                return false;
            }

            $cur = $this->objectIterator->current();
            if (isset($cur['Prefix'])) {
                $result = rtrim($cur['Prefix'], '/');
                $stat = $this->formatUrlStat();
            } else {
                $result = $cur['Key'];
                $stat = $this->formatUrlStat($cur['Size'] ?? 0, $cur['LastModified'] ?? null);
            }

            // Cache the stat for quick url_stat() calls, e.g. by RecursiveDirectoryIterator.
            $this->setCache($this->formatKey($result), $stat);
            $this->advance = true;

            return $this->openedBucketPrefix
                ? substr($result, strlen($this->openedBucketPrefix))
                : $result;
        });
    }

    /**
     * Renames an object with CopyObject and DeleteObject, in a bucket or between buckets.
     *
     * Context options are passed to CopyObject and DeleteObject. The metadata is copied.
     *
     * @param string $path_from `s3://bucket/key`
     * @param string $path_to `s3://bucket/key`
     * @return bool
     */
    public function rename($path_from, $path_to): bool
    {
        // PHP does not allow a rename across wrappers, so both paths have the same protocol.
        $this->initProtocol($path_from);
        $partsFrom = $this->withPath($path_from);
        $partsTo = $this->withPath($path_to);
        $this->clearCacheKey($path_from);
        $this->clearCacheKey($path_to);

        if (!$partsFrom['Key'] || !$partsTo['Key']) {
            return $this->triggerError('The Amazon S3 stream wrapper only supports copying objects');
        }

        return $this->boolCall(function () use ($partsFrom, $partsTo) {
            $options = $this->getOptions(true);
            if (isset($options['acl']) && !isset($options['ACL'])) {
                $options['ACL'] = $options['acl'];
            }
            $this->getClient()->copyObject([
                'Bucket' => $partsTo['Bucket'],
                'Key' => $partsTo['Key'],
                'CopySource' => $partsFrom['Bucket'] . '/' . implode('/', array_map('rawurlencode', explode('/', (string)$partsFrom['Key']))),
                'MetadataDirective' => 'COPY',
            ] + $options)->resolve();
            $this->getClient()->deleteObject([
                'Bucket' => $partsFrom['Bucket'],
                'Key' => $partsFrom['Key'],
            ] + $options)->resolve();

            return true;
        });
    }

    /**
     * Not supported.
     *
     * @param int $cast_as Cast type.
     * @return false
     */
    public function stream_cast($cast_as)
    {
        return false;
    }

    /**
     * Not supported.
     *
     * @param int $option Option.
     * @param int $arg1 Argument.
     * @param int|null $arg2 Argument.
     * @return bool
     */
    public function stream_set_option($option, $arg1, $arg2): bool
    {
        return false;
    }

    /**
     * Not supported (`touch()`, `chmod()`, `chown()`, ...), as in the AWS SDK.
     *
     * @param string $path Path.
     * @param int $option Option.
     * @param mixed $value Value.
     * @return bool
     */
    public function stream_metadata($path, $option, $value): bool
    {
        return false;
    }

    /**
     * Not supported.
     *
     * @param int $operation Lock operation.
     * @return bool
     */
    public function stream_lock($operation): bool
    {
        trigger_error('stream_lock() is not supported by the Amazon S3 stream wrapper', E_USER_WARNING);

        return false;
    }

    /**
     * Not supported.
     *
     * @param int $new_size Size.
     * @return bool
     */
    public function stream_truncate($new_size): bool
    {
        return false;
    }

    /**
     * Returns the MIME type of a key from its extension, or null.
     *
     * @param string $key Object key.
     * @return string|null
     */
    protected static function mimeType(string $key): ?string
    {
        $extension = strtolower(pathinfo($key, PATHINFO_EXTENSION));
        if ($extension === '') {
            return null;
        }
        $type = (new CakeResponse())->getMimeType($extension);
        if (is_array($type)) {
            $type = reset($type);
        }

        return is_string($type) ? $type : null;
    }

    /**
     * Validates the arguments of `fopen()` and returns the errors.
     *
     * @param string $path Path.
     * @param string $mode Mode without `b` and `t`.
     * @return array<string>
     */
    private function validate(string $path, string $mode): array
    {
        $errors = [];

        if (!$this->getOption('Key')) {
            $errors[] = 'Cannot open a bucket. You must specify a path in the form of s3://bucket/key';
        }

        if (!in_array($mode, ['r', 'w', 'a', 'x'], true)) {
            $errors[] = "Mode not supported: {$mode}. Use one 'r', 'w', 'a', or 'x'.";
        }

        if ($mode === 'x' && !$errors && $this->doesObjectExist($this->getOptions(true))) {
            $errors[] = "{$path} already exists on Amazon S3";
        }

        return $errors;
    }

    /**
     * Returns the options of the stream context, merged with the bucket and the key of the opened stream.
     *
     * @param bool $removeContextData Whether to remove the options of the wrapper (`client`, `seekable`, ...).
     * @return array<string, mixed>
     */
    private function getOptions(bool $removeContextData = false): array
    {
        // The context is not set for url_stat() and the like.
        $options = [];
        if ($this->context !== null) {
            $options = stream_context_get_options($this->context)[$this->protocol] ?? [];
        }
        $default = stream_context_get_options(stream_context_get_default())[$this->protocol] ?? [];
        $result = $this->params + $options + $default;

        if ($removeContextData) {
            unset($result['client'], $result['seekable'], $result['cache'], $result['delimiter'], $result['listFilter']);
        }

        return $result;
    }

    /**
     * Returns an option of the stream context.
     *
     * @param string $name Option name.
     * @return mixed
     */
    private function getOption(string $name)
    {
        return $this->getOptions()[$name] ?? null;
    }

    /**
     * Returns the client of the stream context.
     *
     * @return \AsyncAws\S3\S3Client
     * @throws \RuntimeException When the context has no client.
     */
    private function getClient(): S3Client
    {
        $client = $this->getOption('client');
        if (!$client instanceof S3Client) {
            throw new RuntimeException('No client in stream context');
        }

        return $client;
    }

    /**
     * Splits a path into its bucket and key.
     *
     * @param string $path `s3://bucket/key`
     * @return array{Bucket: string, Key: string|null}
     */
    private function getBucketKey(string $path): array
    {
        $parts = explode('://', $path, 2);
        $parts = explode('/', $parts[1] ?? '', 2);

        return [
            'Bucket' => $parts[0],
            'Key' => $parts[1] ?? null,
        ];
    }

    /**
     * Returns the bucket and the key of a path with the options of the stream context.
     *
     * @param string $path `s3://bucket/key`
     * @return array<string, mixed>
     */
    private function withPath(string $path): array
    {
        return $this->getBucketKey($path) + $this->getOptions(true);
    }

    /**
     * Sets the protocol of the opened path.
     *
     * @param string $path Path.
     * @return void
     */
    private function initProtocol(string $path): void
    {
        $parts = explode('://', $path, 2);
        $this->protocol = $parts[0] ?: 's3';
    }

    /**
     * Opens a read stream with GetObject.
     *
     * @return bool
     */
    private function openReadStream(): bool
    {
        $result = $this->getClient()->getObject($this->getOptions(true));
        $result->resolve();
        $this->size = $result->getContentLength();
        $this->chunks = $this->iterate($result->getBody()->getChunks());
        $this->seekable = (bool)$this->getOption('seekable');
        if ($this->seekable) {
            $this->body = $this->tempStream();
        }

        return true;
    }

    /**
     * Opens a write stream that buffers the data in `php://temp`.
     *
     * @return bool
     */
    private function openWriteStream(): bool
    {
        $this->body = $this->tempStream();

        return true;
    }

    /**
     * Opens an append stream: downloads the object and seeks to its end, or opens a write stream when it does not exist.
     *
     * @return bool
     */
    private function openAppendStream(): bool
    {
        try {
            $result = $this->getClient()->getObject($this->getOptions(true));
            $result->resolve();
        } catch (HttpException $e) {
            if ($e->getCode() !== 404) {
                throw $e;
            }

            // The object does not exist, so use a simple write stream.
            return $this->openWriteStream();
        }

        $this->body = $this->tempStream();
        foreach ($result->getBody()->getChunks() as $chunk) {
            fwrite($this->body, $chunk);
        }
        fseek($this->body, 0, SEEK_END);

        return true;
    }

    /**
     * Returns a `php://temp` stream.
     *
     * @return resource
     * @throws \RuntimeException When the stream cannot be opened.
     */
    private function tempStream()
    {
        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            throw new RuntimeException('Unable to open a php://temp stream.');
        }

        return $stream;
    }

    /**
     * Returns an iterator of the non-empty chunks.
     *
     * @param iterable<string> $chunks Chunks.
     * @return \Generator<int, string>
     */
    private function iterate(iterable $chunks): Generator
    {
        foreach ($chunks as $chunk) {
            if ($chunk !== '') {
                yield $chunk;
            }
        }
    }

    /**
     * Receives the next chunk of a read stream into the buffer.
     *
     * @return bool False when the body ended.
     */
    private function nextChunk(): bool
    {
        if ($this->remoteEof || $this->chunks === null) {
            $this->remoteEof = true;

            return false;
        }
        if (!$this->chunks->valid()) {
            $this->remoteEof = true;

            return false;
        }
        $chunk = $this->chunks->current();
        $this->chunks->next();

        if ($this->seekable) {
            fseek($this->body, 0, SEEK_END);
            fwrite($this->body, $chunk);
        } else {
            $this->buffer .= $chunk;
        }

        return true;
    }

    /**
     * Receives chunks of a seekable read stream until `$size` bytes are buffered or the body ends.
     *
     * @param int $size Size.
     * @return void
     */
    private function fill(int $size): void
    {
        while ($this->bufferedSize() < $size && $this->nextChunk()) {
            // Receive more.
        }
    }

    /**
     * Returns the number of bytes in the buffer of a seekable read stream.
     *
     * @return int
     */
    private function bufferedSize(): int
    {
        return (int)(fstat($this->body)['size'] ?? 0);
    }

    /**
     * Returns the size of the opened object: the size of the buffer, or the `ContentLength` of the object.
     *
     * @return int|null
     */
    private function getSize(): ?int
    {
        if ($this->mode !== 'r' && is_resource($this->body)) {
            $size = (int)(fstat($this->body)['size'] ?? 0);
            if ($size) {
                return $size;
            }
        }

        return $this->size;
    }

    /**
     * Returns the stat of a path.
     *
     * @param string $path Path.
     * @param int $flags `url_stat()` flags.
     * @return array<int|string, int>|false
     */
    private function createStat(string $path, int $flags)
    {
        $this->initProtocol($path);
        $parts = $this->withPath($path);

        if (!$parts['Key']) {
            return $this->statDirectory($parts, $path, $flags);
        }

        return $this->boolCall(function () use ($parts, $path) {
            try {
                $result = $this->getClient()->headObject($parts);
                $result->resolve();
                if (substr($parts['Key'], -1, 1) === '/' && (int)$result->getContentLength() === 0) {
                    // A zero-byte "dir/" object, e.g. created by the console, is a directory.
                    return $this->formatUrlStat();
                }

                return $this->formatObjectStat($result);
            } catch (HttpException $e) {
                // Maybe this is not a key but a prefix. List the objects of the prefix to find out.
                $result = $this->getClient()->listObjectsV2([
                    'Bucket' => $parts['Bucket'],
                    'Prefix' => rtrim($parts['Key'], '/') . '/',
                    'MaxKeys' => 1,
                ]);
                if (!iterator_to_array($result->getContents(true)) && !iterator_to_array($result->getCommonPrefixes(true))) {
                    throw new RuntimeException("File or directory not found: {$path}");
                }

                return $this->formatUrlStat();
            }
        }, $flags);
    }

    /**
     * Returns the stat of `s3://` or of a bucket.
     *
     * @param array<string, mixed> $parts Bucket and key.
     * @param string $path Path.
     * @param int $flags `url_stat()` flags.
     * @return array<int|string, int>|false
     */
    private function statDirectory(array $parts, string $path, int $flags)
    {
        return $this->boolCall(function () use ($parts, $path, $flags) {
            if (!$parts['Bucket'] || $this->doesBucketExist($parts['Bucket'])) {
                return $this->formatUrlStat();
            }

            return $this->triggerError("File or directory not found: {$path}", $flags);
        }, $flags);
    }

    /**
     * Returns whether a bucket exists, as `S3Client::doesBucketExist()` of the AWS SDK: access denied means it exists.
     *
     * @param string $bucket Bucket.
     * @return bool
     * @throws \RuntimeException On other errors than 403 and 404.
     */
    private function doesBucketExist(string $bucket): bool
    {
        // HeadBucket: 200, 301 and 403 are "success", 404 is "pending", other errors are "failure".
        $state = $this->getClient()->bucketExists(['Bucket' => $bucket])->getState();
        if ($state === Waiter::STATE_FAILURE) {
            throw new RuntimeException("Unable to check whether the bucket {$bucket} exists");
        }

        return $state === Waiter::STATE_SUCCESS;
    }

    /**
     * Returns whether an object exists, as `S3Client::doesObjectExist()` of the AWS SDK: access denied means it exists.
     *
     * @param array<string, mixed> $params HeadObject parameters.
     * @return bool
     */
    private function doesObjectExist(array $params): bool
    {
        try {
            $this->getClient()->headObject($params)->resolve();

            return true;
        } catch (HttpException $e) {
            return $this->existsAfterError($e);
        }
    }

    /**
     * Returns whether a bucket or an object exists after its HEAD request failed.
     *
     * @param \AsyncAws\Core\Exception\Http\HttpException $e Error.
     * @return bool
     * @throws \AsyncAws\Core\Exception\Http\HttpException On server errors.
     */
    private function existsAfterError(HttpException $e): bool
    {
        $status = $e->getResponse()->getStatusCode();
        if ($status === 403) {
            return true;
        }
        if ($status >= 500) {
            throw $e;
        }

        return false;
    }

    /**
     * Lists the entries of a directory, page by page.
     *
     * @param \AsyncAws\S3\Result\ListObjectsV2Output $result First page.
     * @param callable|null $filterFn Filter.
     * @return \Generator<int, array<string, mixed>>
     */
    private function listEntries($result, ?callable $filterFn): Generator
    {
        foreach ($result as $item) {
            if ($item instanceof CommonPrefix) {
                $entry = ['Prefix' => (string)$item->getPrefix()];
            } else {
                $entry = [
                    'Key' => (string)$item->getKey(),
                    'LastModified' => $item->getLastModified()?->format(DATE_ATOM),
                    'ETag' => $item->getETag(),
                    'Size' => (int)$item->getSize(),
                    'StorageClass' => $item->getStorageClass(),
                ];
                // Skip the "dir/" objects, e.g. created by the console.
                if (str_ends_with($entry['Key'], '/')) {
                    continue;
                }
            }
            if ($filterFn && !$filterFn($entry)) {
                continue;
            }

            yield $entry;
        }
    }

    /**
     * Returns `protocol://bucket/key` of the opened directory.
     *
     * @param string $key Key.
     * @return string
     */
    private function formatKey(string $key): string
    {
        $protocol = explode('://', $this->openedPath)[0];

        return "{$protocol}://{$this->openedBucket}/{$key}";
    }

    /**
     * Triggers an error (a warning) and returns false, or returns quietly for `STREAM_URL_STAT_QUIET`.
     *
     * @param array<string>|string $errors Errors.
     * @param int|null $flags `url_stat()` flags.
     * @return array<int|string, int>|false
     */
    private function triggerError($errors, ?int $flags = null)
    {
        // file_exists() and the like
        if ($flags & STREAM_URL_STAT_QUIET) {
            // is_link() and the like
            return $flags & STREAM_URL_STAT_LINK ? $this->getStatTemplate() : false;
        }

        // stat(), fopen() and the like
        trigger_error(static::sanitize(implode("\n", (array)$errors)), E_USER_WARNING);

        return false;
    }

    /**
     * Removes signatures and credentials from a message (e.g. from the URL of a presigned request).
     *
     * @param string $message Message.
     * @return string
     */
    protected static function sanitize(string $message): string
    {
        return (string)preg_replace('/(X-Amz-(?:Credential|Signature|Security-Token)=)[^&"\s]+/i', '$1***', $message);
    }

    /**
     * Returns the stat of a directory (no arguments) or of a file.
     *
     * @param int|null $size Size of the file.
     * @param string|null $lastModified Last modification time of the file.
     * @return array<int|string, int>
     */
    private function formatUrlStat(?int $size = null, ?string $lastModified = null): array
    {
        $stat = $this->getStatTemplate();
        if (func_num_args() === 0) {
            // Directory with 0777 access - see "man 2 stat".
            $stat['mode'] = $stat[2] = 0040777;

            return $stat;
        }

        // Regular file with 0777 access - see "man 2 stat".
        $stat['mode'] = $stat[2] = 0100777;
        $stat['size'] = $stat[7] = (int)$size;
        if ($lastModified !== null) {
            $stat['mtime'] = $stat[9] = $stat['ctime'] = $stat[10] = (int)strtotime($lastModified);
        }

        return $stat;
    }

    /**
     * Returns the stat of an object from its HeadObject result.
     *
     * @param \AsyncAws\S3\Result\HeadObjectOutput $result HeadObject result.
     * @return array<int|string, int>
     */
    private function formatObjectStat(HeadObjectOutput $result): array
    {
        return $this->formatUrlStat((int)$result->getContentLength(), $result->getLastModified()?->format(DATE_ATOM));
    }

    /**
     * Creates a bucket. Fails when it exists.
     *
     * @param string $path Path.
     * @param array<string, mixed> $params Result of `withPath()`.
     * @return bool
     */
    private function createBucket(string $path, array $params): bool
    {
        if ($this->doesBucketExist($params['Bucket'])) {
            return $this->triggerError("Bucket already exists: {$path}");
        }

        unset($params['ACL'], $params['Key']);
        if (!isset($params['CreateBucketConfiguration'])) {
            // As the AWS SDK, create the bucket in the region of the client.
            $region = $this->getClient()->getConfiguration()->get('region');
            if ($region && $region !== 'us-east-1') {
                $params['CreateBucketConfiguration'] = ['LocationConstraint' => $region];
            }
        }
        $this->getClient()->createBucket($params)->resolve();
        $this->clearCacheKey($path);

        return true;
    }

    /**
     * Creates a "directory" object: an empty object whose key ends with `/`. Fails when it exists.
     *
     * @param string $path Path.
     * @param array<string, mixed> $params Result of `withPath()`.
     * @return bool
     */
    private function createSubfolder(string $path, array $params): bool
    {
        $params['Key'] = rtrim($params['Key'], '/') . '/';
        $params['Body'] = '';

        if ($this->doesObjectExist(['Bucket' => $params['Bucket'], 'Key' => $params['Key']])) {
            return $this->triggerError("Subfolder already exists: {$path}");
        }

        $this->getClient()->putObject($params)->resolve();
        $this->clearCacheKey($path);

        return true;
    }

    /**
     * Deletes the "directory" object of a prefix that contains nothing else.
     *
     * @param string $path Path.
     * @param array<string, mixed> $params Result of `withPath()`.
     * @return bool
     */
    private function deleteSubfolder(string $path, array $params): bool
    {
        $prefix = rtrim($params['Key'], '/') . '/';
        // Two keys tell whether the prefix has an object besides its "dir/" object.
        $result = $this->getClient()->listObjectsV2([
            'Bucket' => $params['Bucket'],
            'Prefix' => $prefix,
            'MaxKeys' => 2,
        ]);
        $contents = iterator_to_array($result->getContents(true), false);

        if ($contents) {
            return count($contents) > 1 || $contents[0]->getKey() !== $prefix
                ? $this->triggerError('Subfolder is not empty')
                : $this->unlink(rtrim($path, '/') . '/');
        }

        return true;
    }

    /**
     * Returns the ACL for a `mkdir()` mode, as the AWS SDK does.
     *
     * @param int $mode Permissions.
     * @return string
     */
    private function determineAcl(int $mode): string
    {
        switch (substr(decoct($mode), 0, 1)) {
            case '7':
                return 'public-read';
            case '6':
                return 'authenticated-read';
            default:
                return 'private';
        }
    }

    /**
     * Returns a stat with the default values.
     *
     * @return array<int|string, int>
     */
    private function getStatTemplate(): array
    {
        return [
            0 => 0, 'dev' => 0,
            1 => 0, 'ino' => 0,
            2 => 0, 'mode' => 0,
            3 => 0, 'nlink' => 0,
            4 => 0, 'uid' => 0,
            5 => 0, 'gid' => 0,
            6 => -1, 'rdev' => -1,
            7 => 0, 'size' => 0,
            8 => 0, 'atime' => 0,
            9 => 0, 'mtime' => 0,
            10 => 0, 'ctime' => 0,
            11 => -1, 'blksize' => -1,
            12 => -1, 'blocks' => -1,
        ];
    }

    /**
     * Calls a function and triggers an error when it throws.
     *
     * @param callable $fn Function.
     * @param int|null $flags `url_stat()` flags.
     * @return mixed
     */
    private function boolCall(callable $fn, ?int $flags = null)
    {
        try {
            return $fn();
        } catch (Exception $e) {
            return $this->triggerError($e->getMessage(), $flags);
        }
    }

    /**
     * Caches a stat.
     *
     * @param string $path Path.
     * @param array<int|string, int> $stat Stat.
     * @return void
     */
    private function setCache(string $path, array $stat): void
    {
        unset(self::$cache[$path]);
        self::$cache[$path] = $stat;
        if (count(self::$cache) > static::CACHE_SIZE) {
            unset(self::$cache[array_key_first(self::$cache)]);
        }
    }

    /**
     * Removes a path from the stat caches.
     *
     * @param string $path Path.
     * @return void
     */
    private function clearCacheKey(string $path): void
    {
        clearstatcache(true, $path);
        unset(self::$cache[$path]);
    }
}

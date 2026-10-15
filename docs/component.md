# AsyncAws Component

The `AsyncAws.AsyncAws` component creates AsyncAws clients with the `AsyncAws`
configuration:

```php
class FilesController extends AppController
{
    public $components = ['AsyncAws.AsyncAws'];

    /**
     * Lists the objects of a bucket.
     *
     * @return void
     */
    public function index()
    {
        // An AsyncAws\S3\S3Client
        $s3 = $this->AsyncAws->S3;

        // The same, with options that override the AsyncAws configuration
        $s3 = $this->AsyncAws->createS3(['region' => 'us-east-1']);

        $objects = [];
        foreach ($s3->listObjectsV2(['Bucket' => 'my-bucket'])->getContents() as $object) {
            $objects[] = $object->getKey();
        }
        $this->set('objects', $objects);
    }
}
```

- Reading a property (`$this->AsyncAws->DynamoDb`) returns the client of
  `AsyncAws\Core\AwsClientFactory` (`dynamoDb()`), which is created once per component.
- `create<Service>($args)` creates a new client with `$args` merged into the configuration.
- `SesV2` is an alias of `Ses`, because AsyncAws's SES client uses the SES API v2.
- `SimpleS3` and `createSimpleS3($args)` return an `AsyncAws\SimpleS3\SimpleS3Client`.
- Any other method call is passed to the factory, for example `$this->AsyncAws->sqs()`.
  Install the service's package first (`async-aws/sqs`).
- `factory()` returns the `AwsClientFactory`.

AsyncAws clients send requests asynchronously: a request is sent when you call a method, and
the result is waited for when you read it (or when the result object is destroyed). Call
`resolve()` on a result to wait for it and throw on errors without reading anything. See
[Async](https://async-aws.com/features/async.html).

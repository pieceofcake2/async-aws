# AsyncAws コンポーネント

`AsyncAws.AsyncAws` コンポーネントは、`AsyncAws` 設定を使って AsyncAws クライアントを作成します。

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

- プロパティ (`$this->AsyncAws->DynamoDb`) を読むと、`AsyncAws\Core\AwsClientFactory` のクライアント
  (`dynamoDb()`) が返されます。このクライアントはコンポーネントごとに 1 回だけ作成されます。
- `create<Service>($args)` は、`$args` を設定にマージした新しいクライアントを作成します。
- AsyncAws の SES クライアントは SES API v2 を使うため、`SesV2` は `Ses` のエイリアスです。
- `SimpleS3` と `createSimpleS3($args)` は `AsyncAws\SimpleS3\SimpleS3Client` を返します。
- その他のメソッド呼び出しはファクトリーに渡されます。たとえば `$this->AsyncAws->sqs()` です。
  先にそのサービスのパッケージ (`async-aws/sqs`) をインストールしてください。
- `factory()` は `AwsClientFactory` を返します。

AsyncAws クライアントはリクエストを非同期に送信します。リクエストはメソッドを呼び出したときに送信され、
結果を読むとき (または結果オブジェクトが破棄されるとき) にその完了を待ちます。
何も読まずに結果を待ち、エラー時に例外を投げるには、結果に対して `resolve()` を呼び出します。
[Async](https://async-aws.com/features/async.html) をご覧ください。

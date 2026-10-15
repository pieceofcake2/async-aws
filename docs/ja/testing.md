# テスト

AsyncAws クライアントは [Symfony HTTP クライアント](https://symfony.com/doc/current/http_client.html#testing) で
リクエストを送信するため、テストではそれを `MockHttpClient` に置き換えられます。
設定の `httpClient` キーに入れると、プラグインのすべてのクライアントがそれを使います。

```php
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

Configure::write('AsyncAws', [
    'region' => 'ap-northeast-1',
    'accessKeyId' => 'test',
    'accessKeySecret' => 'test',
    'httpClient' => new MockHttpClient([
        new MockResponse('{"MessageId": "message-id"}'),
    ]),
]);
```

AsyncAws クライアントを呼び出す自分のコードをテストするには、クライアントをモックし、
`AsyncAws\Core\Test\ResultMockFactory` でその結果を作成することもできます。
[Writing tests and mocking](https://async-aws.com/features/tests.html) をご覧ください。

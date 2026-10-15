# Testing

AsyncAws clients send their requests with a [Symfony HTTP client](https://symfony.com/doc/current/http_client.html#testing),
so tests can replace it with a `MockHttpClient`. Put it in the `httpClient` key of the
configuration, and every client of the plugin uses it:

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

To test your own code that calls an AsyncAws client, you can also mock the client and create
its results with `AsyncAws\Core\Test\ResultMockFactory`; see
[Writing tests and mocking](https://async-aws.com/features/tests.html).

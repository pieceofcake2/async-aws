<?php

use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Configures the plugin with a Symfony `MockHttpClient`, as described in the AsyncAws testing documentation,
 * and records the requests sent by the AsyncAws clients.
 */
trait MockAsyncAwsTrait
{
    /**
     * Responses returned in order. When none is left, an empty JSON object is returned.
     *
     * @var array<\Symfony\Component\HttpClient\Response\MockResponse>
     */
    protected array $responses = [];

    /**
     * Requests sent, each with `method`, `url`, `headers` (lower-case names) and `body`.
     *
     * @var array<array{method: string, url: string, headers: array<string, string>, body: string}>
     */
    protected array $requests = [];

    /**
     * Writes the `AsyncAws` configuration with static credentials and the mock HTTP client.
     *
     * @param array $config Configuration merged into the defaults.
     * @return \Symfony\Component\HttpClient\MockHttpClient
     */
    protected function mockAsyncAws(array $config = []): MockHttpClient
    {
        $this->responses = [];
        $this->requests = [];
        $httpClient = $this->mockHttpClient();
        Configure::write('AsyncAws', $config + [
            'region' => 'ap-northeast-1',
            'accessKeyId' => 'test-key',
            'accessKeySecret' => 'test-secret',
            'httpClient' => $httpClient,
        ]);

        return $httpClient;
    }

    /**
     * Returns a mock HTTP client that records requests and returns the queued responses.
     *
     * @return \Symfony\Component\HttpClient\MockHttpClient
     */
    protected function mockHttpClient(): MockHttpClient
    {
        return new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            $headers = [];
            foreach ($options['normalized_headers'] ?? [] as $name => $values) {
                $headers[$name] = substr((string)$values[0], strlen($name) + 2);
            }
            $body = $options['body'] ?? '';
            if (is_callable($body)) {
                $chunks = '';
                while (($chunk = $body(16384)) !== '') {
                    $chunks .= $chunk;
                }
                $body = $chunks;
            } elseif (is_iterable($body)) {
                $body = implode('', iterator_to_array($body, false));
            }
            $this->requests[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => (string)$body];

            return array_shift($this->responses) ?? new MockResponse('{}');
        });
    }

    /**
     * Queues JSON responses.
     *
     * @param array ...$data Response bodies.
     * @return void
     */
    protected function queueJson(array ...$data): void
    {
        foreach ($data as $body) {
            $this->responses[] = new MockResponse(json_encode((object)$body), [
                'response_headers' => ['content-type' => 'application/x-amz-json-1.0'],
            ]);
        }
    }

    /**
     * Queues an AWS JSON error response.
     *
     * @param string $code Error code, e.g. `ConditionalCheckFailedException`.
     * @param int $status HTTP status.
     * @return void
     */
    protected function queueError(string $code, int $status = 400): void
    {
        $this->responses[] = new MockResponse(json_encode(['__type' => 'com.amazonaws.dynamodb.v20120810#' . $code, 'message' => $code . ' message']), [
            'http_code' => $status,
            'response_headers' => ['content-type' => 'application/x-amz-json-1.0'],
        ]);
    }

    /**
     * Returns the last request.
     *
     * @return array{method: string, url: string, headers: array<string, string>, body: string}
     */
    protected function lastRequest(): array
    {
        $this->assertNotEmpty($this->requests, 'No request was sent.');

        return end($this->requests);
    }

    /**
     * Returns the operation of a DynamoDB request (`X-Amz-Target` without the API version).
     *
     * @param array|null $request Request. Default: the last request.
     * @return string
     */
    protected function operation(?array $request = null): string
    {
        $request = $request ?? $this->lastRequest();
        $target = $request['headers']['x-amz-target'] ?? '';

        return substr($target, strpos($target, '.') + 1);
    }

    /**
     * Returns the decoded JSON body of a request.
     *
     * @param array|null $request Request. Default: the last request.
     * @return array
     */
    protected function payload(?array $request = null): array
    {
        $request = $request ?? $this->lastRequest();

        return (array)json_decode($request['body'], true);
    }
}

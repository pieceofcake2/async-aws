<?php

use AsyncAws\Ses\Exception\MessageRejectedException;
use Symfony\Component\HttpClient\Response\MockResponse;

class AmazonSesApiTransportTest extends CakeTestCase
{
    use MockAsyncAwsTrait;

    public function setUp(): void
    {
        parent::setUp();
        $this->mockAsyncAws(['accessKeyId' => 'global-key', 'accessKeySecret' => 'global-secret']);
    }

    public function tearDown(): void
    {
        Configure::delete('AsyncAws');
        parent::tearDown();
    }

    private function email(array $config = []): CakeEmail
    {
        return new CakeEmail(['transport' => 'AsyncAws.AmazonSesApi'] + $config);
    }

    public function testSendText(): void
    {
        $this->queueJson(['MessageId' => 'message-id']);

        $result = $this->email()
            ->from('from@example.com')
            ->to('to@example.com')
            ->subject('Hello')
            ->send('Body');

        $this->assertSame('message-id', $result['id']);

        $request = $this->lastRequest();
        $this->assertSame('POST', $request['method']);
        $this->assertSame('https://email.ap-northeast-1.amazonaws.com/v2/email/outbound-emails', $request['url']);
        $this->assertStringContainsString('Credential=global-key/', $request['headers']['authorization']);

        $payload = $this->payload();
        $this->assertSame('from@example.com', $payload['FromEmailAddress']);
        $this->assertSame(['ToAddresses' => ['to@example.com']], $payload['Destination']);
        $this->assertSame(['Data' => 'Hello', 'Charset' => 'UTF-8'], $payload['Content']['Simple']['Subject']);
        $this->assertStringContainsString('Body', $payload['Content']['Simple']['Body']['Text']['Data']);
        $this->assertArrayNotHasKey('Html', $payload['Content']['Simple']['Body']);
        $this->assertArrayNotHasKey('Attachments', $payload['Content']['Simple']);
        $this->assertArrayNotHasKey('ReplyToAddresses', $payload);
    }

    public function testSendWithAllRecipientsAndBothFormats(): void
    {
        $this->queueJson(['MessageId' => 'message-id']);

        $this->email()
            ->emailFormat('both')
            ->from('from@example.com', 'Sender "Name"')
            ->to(['to1@example.com' => 'To One', 'to2@example.com'])
            ->cc('cc@example.com')
            ->bcc('bcc@example.com')
            ->replyTo('reply@example.com', 'Reply')
            ->subject('こんにちは')
            ->send('Body');

        $payload = $this->payload();
        $this->assertSame('"Sender \"Name\"" <from@example.com>', $payload['FromEmailAddress']);
        $this->assertSame([
            'ToAddresses' => ['"To One" <to1@example.com>', 'to2@example.com'],
            'CcAddresses' => ['cc@example.com'],
            'BccAddresses' => ['bcc@example.com'],
        ], $payload['Destination']);
        $this->assertSame(['"Reply" <reply@example.com>'], $payload['ReplyToAddresses']);
        $this->assertSame('こんにちは', $payload['Content']['Simple']['Subject']['Data']);
        $this->assertStringContainsString('Body', $payload['Content']['Simple']['Body']['Text']['Data']);
        $this->assertStringContainsString('Body', $payload['Content']['Simple']['Body']['Html']['Data']);
    }

    public function testSendWithAttachments(): void
    {
        $this->queueJson(['MessageId' => 'message-id']);
        $license = dirname(__DIR__, 4) . DS . 'LICENSE';

        $this->email()
            ->from('from@example.com')
            ->to('to@example.com')
            ->subject('Attachments')
            ->attachments([
                'license.txt' => $license,
                'data.bin' => ['data' => 'binary data', 'mimetype' => 'application/octet-stream'],
            ])
            ->send('Body');

        $attachments = $this->payload()['Content']['Simple']['Attachments'];
        $this->assertCount(2, $attachments);

        $this->assertSame('license.txt', $attachments[0]['FileName']);
        $this->assertSame(file_get_contents($license), base64_decode($attachments[0]['RawContent']));
        $this->assertSame('text/plain', $attachments[0]['ContentType']);
        $this->assertSame('ATTACHMENT', $attachments[0]['ContentDisposition']);
        $this->assertSame('BASE64', $attachments[0]['ContentTransferEncoding']);

        $this->assertSame('data.bin', $attachments[1]['FileName']);
        $this->assertSame('binary data', base64_decode($attachments[1]['RawContent']));
        $this->assertSame('application/octet-stream', $attachments[1]['ContentType']);
    }

    public function testSendWithTransportConfig(): void
    {
        $this->queueJson(['MessageId' => 'message-id']);

        $this->email([
            'region' => 'us-west-2',
            'key' => 'transport-key',
            'secret' => 'transport-secret',
        ])
            ->from('from@example.com')
            ->to('to@example.com')
            ->subject('Hello')
            ->send('Body');

        $request = $this->lastRequest();
        $this->assertSame('https://email.us-west-2.amazonaws.com/v2/email/outbound-emails', $request['url']);
        $this->assertStringContainsString('Credential=transport-key/', $request['headers']['authorization']);
    }

    public function testSendWithSessionToken(): void
    {
        $this->queueJson(['MessageId' => 'message-id']);

        $this->email(['key' => 'transport-key', 'secret' => 'transport-secret', 'token' => 'transport-token'])
            ->from('from@example.com')
            ->to('to@example.com')
            ->subject('Hello')
            ->send('Body');

        $this->assertSame('transport-token', $this->lastRequest()['headers']['x-amz-security-token']);
    }

    public function testSendWithEndpoint(): void
    {
        $this->queueJson(['MessageId' => 'message-id']);

        $this->email(['endpoint' => 'https://ses.example.com:8443'])
            ->from('from@example.com')
            ->to('to@example.com')
            ->subject('Hello')
            ->send('Body');

        $this->assertSame('https://ses.example.com:8443/v2/email/outbound-emails', $this->lastRequest()['url']);
    }

    public function testSendFailure(): void
    {
        $this->responses[] = new MockResponse(
            json_encode(['message' => 'Email address is not verified.']),
            ['http_code' => 400, 'response_headers' => ['x-amzn-errortype' => 'MessageRejected']],
        );

        $this->expectException(MessageRejectedException::class);
        $this->email()
            ->from('from@example.com')
            ->to('to@example.com')
            ->subject('Hello')
            ->send('Body');
    }
}

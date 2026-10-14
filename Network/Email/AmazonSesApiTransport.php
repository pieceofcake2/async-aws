<?php

use AsyncAws\Ses\SesClient;

App::uses('AbstractTransport', 'Network/Email');
App::uses('CakeEmail', 'Network/Email');
App::uses('ComponentCollection', 'Controller');
App::uses('AsyncAwsComponent', 'AsyncAws.Controller/Component');
App::uses('MissingPackageException', 'AsyncAws.Error');

/**
 * Amazon SES Email Transport for CakePHP 2.x.
 *
 * Uses async-aws/ses to send emails with the `SendEmail` operation of the Amazon SES API v2.
 * Uses the AsyncAws default credential chain (environment, shared files, web identity, ECS, EC2 instance metadata)
 * when key/secret are not configured.
 * Configure AWS settings under Configure::write('AsyncAws'). Requires `async-aws/ses`.
 */
class AmazonSesApiTransport extends AbstractTransport
{
    /**
     * Default AWS/SES configuration.
     *
     * @var array
     */
    protected $_config = [
        'region' => null,
        'key' => null,
        'secret' => null,
        'token' => null,
    ];

    private AsyncAwsComponent $AsyncAws;

    /**
     * Merge the transport config with the defaults. The client is created with Configure::read('AsyncAws').
     *
     * @param array $config Transport configuration.
     * @throws \MissingPackageException When async-aws/ses is not installed.
     */
    public function __construct(array $config = [])
    {
        MissingPackageException::check(static::clientClass(), 'async-aws/ses', 'The Amazon SES email transport');

        // Merge: defaults -> global -> instance config
        $this->_config = array_merge(
            $this->_config,
            $config,
        );

        $this->AsyncAws = new AsyncAwsComponent(new ComponentCollection());
    }

    /**
     * Returns the class of the SES client, which is checked to exist before the client is created.
     *
     * @return string
     */
    protected static function clientClass(): string
    {
        return SesClient::class;
    }

    /**
     * Send mail via Amazon SES API v2.
     *
     * @param \CakeEmail $email CakeEmail
     * @return array{id: string|null} The SES message ID.
     */
    public function send(CakeEmail $email)
    {
        // Initialize the SES (API v2) client
        $clientConfig = [];
        if (!empty($this->_config['region'])) {
            $clientConfig['region'] = $this->_config['region'];
        }
        if (!empty($this->_config['key']) && !empty($this->_config['secret'])) {
            $clientConfig['credentials'] = [
                'key' => $this->_config['key'],
                'secret' => $this->_config['secret'],
                'token' => $this->_config['token'],
            ];
        }

        $sesClient = $this->AsyncAws->createSes($clientConfig);

        // Extract all email properties
        $from = $this->getAddresses($email->from());
        $to = $this->getAddresses($email->to());
        $cc = $this->getAddresses($email->cc());
        $bcc = $this->getAddresses($email->bcc());
        $replyTo = $this->getAddresses($email->replyTo());
        $subject = mb_decode_mimeheader($email->subject());
        $text = $email->message('text');
        $html = $email->message('html');
        $attachments = $email->attachments();

        // Destination
        $destination = ['ToAddresses' => $to];
        if ($cc) {
            $destination['CcAddresses'] = $cc;
        }
        if ($bcc) {
            $destination['BccAddresses'] = $bcc;
        }

        // Content
        $content = ['Simple' => [
            'Subject' => ['Data' => $subject, 'Charset' => 'UTF-8'],
            'Body' => [],
        ]];
        if (!empty($text)) {
            $content['Simple']['Body']['Text'] = [
                'Data' => $text,
                'Charset' => 'UTF-8',
            ];
        }
        if (!empty($html)) {
            $content['Simple']['Body']['Html'] = [
                'Data' => $html,
                'Charset' => 'UTF-8',
            ];
        }

        // Attachments
        if ($attachments) {
            $content['Simple']['Attachments'] = [];
            foreach ($attachments as $filename => $fileInfo) {
                if (isset($fileInfo['file'])) {
                    // Use raw binary content directly
                    $rawContent = file_get_contents($fileInfo['file']);
                    $contentType = $fileInfo['mimetype'] ?? mime_content_type($fileInfo['file']);
                } else {
                    // Decode CakeEmail's chunk-split Base64 back to binary
                    $encoded = $fileInfo['data'];
                    $rawContent = base64_decode($encoded, true);
                    $contentType = $fileInfo['mimetype'] ?? 'application/octet-stream';
                }
                $content['Simple']['Attachments'][] = [
                    'FileName' => $filename,
                    'RawContent' => $rawContent,
                    'ContentType' => $contentType,
                    'ContentDisposition' => 'ATTACHMENT',
                    'ContentTransferEncoding' => 'BASE64',
                ];
            }
        }

        // Assemble params
        $params = [
            'FromEmailAddress' => current($from),
            'Destination' => $destination,
            'Content' => $content,
        ];
        if ($replyTo) {
            $params['ReplyToAddresses'] = $replyTo;
        }

        // Send email via SES API v2
        $result = $sesClient->sendEmail($params);

        return ['id' => $result->getMessageId()];
    }

    /**
     * Flatten various CakeEmail address formats into a simple array of email strings.
     *
     * @param array $addresses Associative array (email => name) or list of emails
     * @return array<string>
     */
    private function getAddresses(array $addresses): array
    {
        $list = [];
        // Normalize various CakeEmail address formats into email => name pairs
        foreach ((array)$addresses as $email => $name) {
            // Handle numeric keys (list of emails)
            if (is_int($email)) {
                $email = $name;
            }

            // If name is provided and differs, include it; otherwise use email only
            if ($name && $name !== $email) {
                // Escape double quotes in name
                $escapedName = str_replace('"', '\"', $name);
                $list[] = sprintf('"%s" <%s>', $escapedName, $email);
            } else {
                $list[] = $email;
            }
        }

        return $list;
    }
}

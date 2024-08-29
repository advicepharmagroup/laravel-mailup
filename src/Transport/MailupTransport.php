<?php

namespace Advicepharmagroup\Mailup\Transport;

use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\MessageConverter;

class MailupTransport extends AbstractTransport
{

    public function __construct(
        private string $user,
        #[\SensitiveParameter] private string $secret,
        private string $host,
        private Client $client
    ) {
        parent::__construct();
    }

    /**
     * {@inheritDoc}
     */
    protected function doSend(SentMessage $message): void
    {
        $email = MessageConverter::toEmail($message->getOriginalMessage());

        try {
            $response = $this->client->post("https://{$this->host}", [
                'headers' => [
                    'Authorization' => $this->getAuthorization(),
                    'Content-Type'  => 'application/json',
                ],
                'json'    => $this->getPayload($email),
            ]);

            if ($response->getStatusCode() !== 200) {
                Log::error('Failed to send email');
                Log::error("Status Code: {$response->getStatusCode()}");
                Log::error("Body: {$response->getBody()}");
            }

        } catch (\Throwable $th) {
            // error log
            Log::error($th);
        }

    }

    /**
     * Return the authorization token
     *
     * @return string
     */
    private function getAuthorization(): string
    {
        return 'SMTP+ ' . \base64_encode("{$this->user}:{$this->secret}");
    }

    /**
     * Return From
     *
     * @param Email $email
     * @return array
     */
    private function getFrom(Email $email): array
    {
        $address = $email->getFrom()[0];

        return ['Email' => $address->getAddress(), 'Name' => $address->getName()];
    }

    /**
     * Return recipients
     *
     * @param Email $email
     * @return array
     */
    private function getRecipients(Email $email): array
    {
        $result = array_map(
            fn($address) => (['Email' => $address->getAddress(), 'Name' => $address->getName()]),
            $email->getTo()
        );

        return $result;
    }

    /**
     * Return attachments
     *
     * @param Email $email
     * @return array
     */
    private function getAttachments(Email $email): array
    {
        $attachments = [];

        foreach ($email->getAttachments() as $attachment) {
            $attachments[] = [
                'Filename' => $attachment->getFilename(),
                'Body'     => base64_encode($attachment->getBody()),
            ];
        }

        return $attachments;
    }

    /**
     * Return attachment placeholder
     *
     * @param integer $n
     * @param string $type
     * @return string
     */
    private function makeTagAttachment(int $n, string $type): string
    {
        $tag = '';

        if ($n == 0) {return '';}

        if ($type == 'html') {
            for ($i = 1; $i < $n + 1; $i++) {
                $tag .= "<p>[attach{$i}]</p>";
            }
        }

        if ($type == 'text') {
            for ($i = 1; $i < $n + 1; $i++) {
                $tag .= "\r\n[attach{$i}]";
            }
        }

        return $tag;
    }

    /**
     * Return body
     *
     * @param Email $email
     * @return string
     */
    private function getBody(Email $email): string
    {
        $n_attachments = count($email->getAttachments());

        $body = $email->getHtmlBody();
        if ($body) {
            return $body . $this->makeTagAttachment($n_attachments, 'html');
        }

        $body = $email->getTextBody();
        if ($body) {
            return $body . $this->makeTagAttachment($n_attachments, 'text');
        }

        return '';
    }

    /**
     * Return payload
     *
     * @param Email $email
     * @return array
     */
    private function getPayload(Email $email): array
    {
        return [
            'User'        => [
                'Username' => $this->user,
                'Secret'   => $this->secret,
            ],
            'Subject'     => $email->getSubject(),
            'Html'        => [
                'Body' => $this->getBody($email),
            ],
            'From'        => $this->getFrom($email),
            'To'          => $this->getRecipients($email),
            'Attachments' => $this->getAttachments($email),
        ];
    }

    public function __toString(): string
    {
        return 'mailup';
    }
}

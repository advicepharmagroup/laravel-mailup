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

    private function getAuthorization()
    {
        return 'SMTP+ ' . \base64_encode("{$this->user}:{$this->secret}");
    }

    private function getFrom(Email $email): array
    {
        $address = $email->getFrom()[0];

        return ['Email' => $address->getAddress(), 'Name' => $address->getName()];
    }

    private function getRecipients(Email $email): array
    {
        $result = array_map(
            fn($address) => (['Email' => $address->getAddress(), 'Name' => $address->getName()]),
            $email->getTo()
        );

        return $result;
    }

    public function getAttachments(Email $email): array
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

    private function getPayload(Email $email): array
    {
        return [
            'User'        => [
                'Username' => $this->user,
                'Secret'   => $this->secret,
            ],
            'Subject'     => $email->getSubject(),
            'Html'        => [
                'Body' => $email->getHtmlBody() ?: $email->getTextBody(),
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

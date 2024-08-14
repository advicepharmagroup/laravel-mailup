<?php

namespace Advicepharmagroup\Mailup\Transport;

use Illuminate\Support\Facades\Log;
use Psr\Http\Client\ClientInterface;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\MessageConverter;

class MailupTransport extends AbstractTransport
{

    public function __construct(
        private string $user,
        #[\SensitiveParameter] private string $secret,
        private string $host,
        private ClientInterface $client
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

    private function formatAddresses(array $addresses, bool $first = false): array
    {
        $result = array_map([$this, 'formatAddress'], $addresses);
        if ($first) {
            return \array_shift($result);
        }
        return $result;
    }

    private function formatAddress(Address $address): array
    {
        return [
            'Email' => $address->getAddress(),
            'Name'  => $address->getName(),
        ];
    }

    private function getPayload(Email $email): array
    {
        return [
            'User'    => [
                'Username' => $this->user,
                'Secret'   => $this->secret,
            ],
            'Subject' => $email->getSubject(),
            'Html'    => [
                'Body' => $email->getHtmlBody() ?: $email->getTextBody(),
            ],
            'From'    => $this->formatAddresses($email->getFrom(), true),
            'To'      => $this->formatAddresses($email->getTo()),
        ];
    }

    public function __toString(): string
    {
        return 'mailup';
    }
}

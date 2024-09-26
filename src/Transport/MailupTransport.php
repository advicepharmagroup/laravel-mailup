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

    private bool $force_html;

    public function __construct(
        private string $user,
        #[\SensitiveParameter] private string $secret,
        private string $host,
        private Client $client
    ) {
        parent::__construct();
    }

    /**
     * Set the value of force_html
     *
     * @return  self
     */
    public function setForceHtml(bool $force_html)
    {
        $this->force_html = $force_html;

        return $this;
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
    private function getBody(Email $email, bool $force_html = false): string
    {
        $n_attachments = count($email->getAttachments());

        $body = $email->getHtmlBody();
        if ($body) {
            $body .= $this->makeTagAttachment($n_attachments, 'html');
            return str_replace(["\r\n", "\r", "\n"], '', $body);
        }

        $body = $email->getTextBody();
        if ($body) {
            $body .= $this->makeTagAttachment($n_attachments, 'text');
            if ($force_html) {
                return str_replace(["\r\n", "\r", "\n"], '</br>', $body);
            }
            return $body;
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
                'Body' => $this->getBody($email, $this->force_html),
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

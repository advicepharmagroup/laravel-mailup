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
    private bool $force_html = false;
    private ?string $proxy   = null;
    private Client $client;

    /**
     * Il costruttore accetta ora un client Guzzle opzionale per favorire il testing
     * e mantenere la piena retrocompatibilità.
     */
    public function __construct(
        private string $user,
        #[\SensitiveParameter] private string $secret,
        private string $host,
        ?Client $client = null
    ) {
        parent::__construct();
        // Se il client non viene passato, ne viene creato uno nuovo (default)
        $this->client = $client ?? new Client(['http_errors' => false]);
    }

    /**
     * Imposta se forzare il contenuto in formato HTML.
     */
    public function setForceHtml(bool $force_html): self
    {
        $this->force_html = $force_html;
        return $this;
    }

    /**
     * Imposta un eventuale proxy per le chiamate API.
     */
    public function setProxy(string $proxy): self
    {
        $this->proxy = $proxy;
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
                'proxy'   => $this->proxy,
            ]);

            if ($response->getStatusCode() !== 200) {
                Log::error('Mailup: Invio email fallito');
                Log::error("Status Code: {$response->getStatusCode()}");
                Log::error("Body: {$response->getBody()}");
            }

        } catch (\Throwable $th) {
            Log::error("Mailup: Errore durante l'invio");
            Log::error($th->getMessage());
        }
    }

    /**
     * Restituisce il token di autorizzazione per MailUp.
     */
    private function getAuthorization(): string
    {
        return 'SMTP+ ' . \base64_encode("{$this->user}:{$this->secret}");
    }

    /**
     * Estrae l'indirizzo mittente.
     */
    private function getFrom(Email $email): array
    {
        $address = $email->getFrom()[0];
        return ['Email' => $address->getAddress(), 'Name' => $address->getName()];
    }

    /**
     * Estrae i destinatari.
     */
    private function getRecipients(Email $email): array
    {
        return array_map(
            fn($address) => (['Email' => $address->getAddress(), 'Name' => $address->getName()]),
            $email->getTo()
        );
    }

    /**
     * Estrae gli allegati trasformandoli nel formato richiesto dalle API.
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
     * Genera i tag per i placeholder degli allegati nel corpo del messaggio.
     */
    private function makeTagAttachment(int $n, string $type): string
    {
        if ($n === 0) {
            return '';
        }

        $tag = '';

        for ($i = 1; $i <= $n; $i++) {
            $tag .= ($type === 'html') ? "<p>[attach{$i}]</p>" : "\r\n[attach{$i}]";
        }

        return $tag;
    }

    /**
     * Costruisce il corpo del messaggio.
     */
    private function getBody(Email $email, bool $force_html = false): string
    {
        $n_attachments = count($email->getAttachments());

        if ($body = $email->getHtmlBody()) {
            $body .= $this->makeTagAttachment($n_attachments, 'html');
            return str_replace(["\r\n", "\r", "\n"], '', $body);
        }

        if ($body = $email->getTextBody()) {
            $body .= $this->makeTagAttachment($n_attachments, 'text');
            if ($force_html) {
                return str_replace(["\r\n", "\r", "\n"], '</br>', $body);
            }
            return $body;
        }

        return '';
    }

    /**
     * Prepara il payload JSON per le API MailUp.
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

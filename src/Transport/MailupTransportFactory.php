<?php

namespace Advicepharmagroup\Mailup\Transport;

use Advicepharmagroup\Mailup\Transport\MailupTransport;
use GuzzleHttp\Client;
use Symfony\Component\Mailer\Exception\UnsupportedSchemeException;
use Symfony\Component\Mailer\Transport\AbstractTransportFactory;
use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Component\Mailer\Transport\TransportInterface;

class MailupTransportFactory extends AbstractTransportFactory
{
    public function create(Dsn $dsn): TransportInterface
    {

        if (!\in_array($dsn->getScheme(), $this->getSupportedSchemes(), true)) {
            throw new UnsupportedSchemeException($dsn, 'mailup', $this->getSupportedSchemes());
        }

        return new MailupTransport(
            $this->getUser($dsn),
            $this->getPassword($dsn),
            'default' === $dsn->getHost() ? 'localhost' : $dsn->getHost(),
            new Client(['http_errors' => false])
        );

    }

    protected function getSupportedSchemes(): array
    {
        return ['mailup', 'mailup+api'];
    }
}

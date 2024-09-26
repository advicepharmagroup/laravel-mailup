<?php

namespace Advicepharmagroup\Mailup\Provider;

use Advicepharmagroup\Mailup\Transport\MailupTransportFactory;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\ServiceProvider;
use Symfony\Component\Mailer\Transport\Dsn;

class MailupServiceProvider extends ServiceProvider
{
    public function register()
    {
        //
    }

    public function boot()
    {
        Mail::extend('mailup', function () {
            $config           = config('services.mailup');
            $mailup_transport = (new MailupTransportFactory)->create(
                new Dsn(
                    'mailup+api',
                    $config['host'],
                    $config['user'],
                    $config['secret']
                ));

            $mailup_transport->setForceHtml($config['force_html']);

            return $mailup_transport;
        });

    }
}

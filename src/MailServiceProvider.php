<?php
namespace Lopes\LaravelPostalDriver;

class MailServiceProvider extends \Illuminate\Mail\MailServiceProvider
{
    public function register()
    {
        parent::register();

        $this->app->register(PostalTransportServiceProvider::class);
    }
}

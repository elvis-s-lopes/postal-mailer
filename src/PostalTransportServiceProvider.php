<?php

namespace Lopes\LaravelPostalDriver;

use GuzzleHttp\Client as HttpClient;
use Illuminate\Mail\MailManager;
use Illuminate\Support\Arr;
use Illuminate\Support\ServiceProvider;
use Lopes\LaravelPostalDriver\Transport\PostalTransport;

class PostalTransportServiceProvider extends ServiceProvider
{
    /**
     * Register the Postal Transport instance.
     *
     * @return void
     */
    public function register()
    {
        $this->app->afterResolving(MailManager::class, function (MailManager $mail_manager) {
            $mail_manager->extend("postal", function ($config) {
                if (! isset($config['api_key'])) {
                    $config = $this->app['config']->get('services.postal', []);
                }
                $client = new HttpClient(Arr::get($config, 'guzzle', []));
                $endpoint = $config['endpoint'] ?? null;

                return new PostalTransport($client, $config['api_key'], $endpoint);
            });

        });
    }
}

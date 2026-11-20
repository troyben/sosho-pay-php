<?php

namespace SoshoPay\Laravel;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Psr7\HttpFactory;
use Illuminate\Support\ServiceProvider;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use SoshoPay\Client;

final class SoshoPayServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/sosho-pay.php', 'sosho-pay');
        $this->app->singleton(Client::class, fn ($app) => new Client(
            (string) $app['config']->get('sosho-pay.base_url'),
            (string) $app['config']->get('sosho-pay.secret_key'),
            $app->bound(ClientInterface::class)
                ? $app->make(ClientInterface::class)
                : new GuzzleClient(['timeout' => 15, 'connect_timeout' => 5, 'allow_redirects' => false]),
            $app->bound(RequestFactoryInterface::class) ? $app->make(RequestFactoryInterface::class) : new HttpFactory,
            $app->bound(StreamFactoryInterface::class) ? $app->make(StreamFactoryInterface::class) : new HttpFactory,
            (int) $app['config']->get('sosho-pay.max_retries', 2),
        ));
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__.'/../../config/sosho-pay.php' => $this->app->configPath('sosho-pay.php')], 'sosho-pay-config');
        }
    }
}

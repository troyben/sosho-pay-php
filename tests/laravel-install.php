<?php

// Run in a fresh consuming Laravel application, never the gateway runtime.
require getcwd().'/vendor/autoload.php';

use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use Illuminate\Config\Repository;
use Illuminate\Foundation\Application;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use SoshoPay\Client;
use SoshoPay\Laravel\SoshoPayServiceProvider;
use SoshoPay\Testing\FakeHttpClient;

$app = new Application(getcwd());
$app->instance('config', new Repository([
    'app' => ['providers' => []],
    'sosho-pay' => ['base_url' => 'https://gateway.example.com', 'secret_key' => 'sk_test_install'],
]));
$app->registerConfiguredProviders();
if (! $app->getProvider(SoshoPayServiceProvider::class)) {
    throw new RuntimeException('SDK Laravel provider was not auto-discovered');
}
$default = $app->make(Client::class);
if (! $default instanceof Client) {
    throw new RuntimeException('SDK must resolve after installation without manual HTTP bindings');
}
$transport = (new ReflectionProperty(Client::class, 'http'))->getValue($default);
if (! $transport instanceof GuzzleHttp\Client || $transport->getConfig('allow_redirects') !== false || $transport->getConfig('timeout') !== 15 || $transport->getConfig('connect_timeout') !== 5) {
    throw new RuntimeException('Default transport must have bounded timeouts and refuse redirects');
}
foreach ([ClientInterface::class, RequestFactoryInterface::class, StreamFactoryInterface::class] as $contract) {
    if ($app->bound($contract)) {
        throw new RuntimeException('SDK must not replace application-wide HTTP bindings');
    }
}

// Application-supplied transport wins and the request stays entirely offline.
$app->forgetInstance(Client::class);
$http = new FakeHttpClient([new Response(200, ['Content-Type' => 'application/json'], '{"data":{"id":"pay_installed"},"meta":{}}')]);
$factory = new HttpFactory;
$app->instance(ClientInterface::class, $http);
$app->instance(RequestFactoryInterface::class, $factory);
$app->instance(StreamFactoryInterface::class, $factory);
if ($app->make(Client::class)->getPayment('pay_installed')['data']['id'] !== 'pay_installed' || count($http->requests) !== 1) {
    throw new RuntimeException('Application HTTP binding was not preserved');
}
echo "PASS Laravel auto-discovery, default resolution and custom transport\n";

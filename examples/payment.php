<?php

use GuzzleHttp\Psr7\HttpFactory;
use SoshoPay\Client;

// Composer autoload must be loaded by the application.
function paymentExample(Client $client, ?string $idempotencyKey = null): array
{
    return $client->createPayment([
        'amount' => 100, 'currency' => 'USD', 'rail' => 'ecocash',
        'customer' => ['msisdn' => '+263770000001'], 'reference' => 'SDK-QUICKSTART',
    ], $idempotencyKey);
}

// On your server:
// $factory = new GuzzleHttp\Psr7\HttpFactory;
// $client = new Client(getenv('SOSHO_BASE_URL'), getenv('SOSHO_SECRET_KEY'),
//     new GuzzleHttp\Client(['timeout' => 15, 'connect_timeout' => 5, 'allow_redirects' => false]),
//     $factory, $factory);
// Persist a unique key on the order before sending:
// $result = paymentExample($client, $order->payment_attempt_key);
// $current = $client->getPayment($result['data']['id']); // pending is not success

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $autoload = is_file(__DIR__.'/../../../autoload.php') ? __DIR__.'/../../../autoload.php' : __DIR__.'/../vendor/autoload.php';
    require $autoload;
    $base = getenv('SOSHO_BASE_URL');
    $secret = getenv('SOSHO_SECRET_KEY');
    $key = getenv('SOSHO_IDEMPOTENCY_KEY');
    if (! $base || ! str_starts_with($secret ?: '', 'sk_test_') || ! $key) {
        throw new RuntimeException('Set SOSHO_BASE_URL, a Sandbox SOSHO_SECRET_KEY and a durable SOSHO_IDEMPOTENCY_KEY on your server.');
    }
    $factory = new HttpFactory;
    $client = new Client($base, $secret, new GuzzleHttp\Client(['timeout' => 15, 'connect_timeout' => 5, 'allow_redirects' => false]), $factory, $factory);
    $current = paymentExample($client, $key);
    $id = $current['data']['id'];
    for ($attempt = 0; $attempt < 30 && in_array($current['data']['status'], ['created', 'pending'], true); $attempt++) {
        sleep(1);
        $current = $client->getPayment($id);
    }
    echo json_encode($current, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)."\n";
    exit($current['data']['status'] === 'succeeded' ? 0 : 1);
}

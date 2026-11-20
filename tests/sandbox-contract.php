<?php

require __DIR__.'/../vendor/autoload.php';
require __DIR__.'/../examples/payment.php';

use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Psr7\HttpFactory;
use SoshoPay\ApiException;
use SoshoPay\Client;

$base = getenv('SOSHO_BASE_URL');
$key = getenv('SOSHO_SECRET_KEY');
if ($base !== 'http://localhost:18080' || ! preg_match('/^sk_test_[A-Za-z0-9]+$/D', $key ?: '')) {
    throw new RuntimeException('SDK contract tests only target the isolated CI Sandbox.');
}
$factory = new HttpFactory;
$client = new Client($base, $key, new Guzzle(['timeout' => 15, 'connect_timeout' => 5, 'allow_redirects' => false]), $factory, $factory);
$attemptKey = bin2hex(random_bytes(24));
$payment = paymentExample($client, $attemptKey);
$replay = paymentExample($client, $attemptKey);
if ($payment['data']['id'] !== $replay['data']['id']) {
    throw new RuntimeException('Idempotent replay created another payment.');
}
for ($attempt = 0; $attempt < 30; $attempt++) {
    $current = $client->getPayment($payment['data']['id']);
    if (! in_array($current['data']['status'], ['created', 'pending'], true)) {
        break;
    }
    sleep(1);
}
if ($current['data']['status'] !== 'succeeded' || $current['data']['amount'] !== 100 || $current['meta']['mode'] !== 'sandbox') {
    throw new RuntimeException('Sandbox payment did not succeed.');
}
$refund = $client->createRefund($payment['data']['id'], ['amount' => 1, 'reason' => 'SDK CI contract'], bin2hex(random_bytes(24)));
$events = $client->request('listEvents');
if (empty($events['data']) || $client->request('getEvent', ['path' => ['id' => $events['data'][0]['id']]])['data']['id'] !== $events['data'][0]['id'] || ! str_contains($client->request('streamEvents'), 'data: ')) {
    throw new RuntimeException('Event replay/stream contract failed.');
}
if ($refund['data']['status'] !== 'succeeded' || $refund['data']['amount'] !== 1) {
    throw new RuntimeException('Sandbox refund did not succeed.');
}
try {
    $client->getPayment('pay_missing');
    throw new RuntimeException('Expected a typed 404 error.');
} catch (ApiException $error) {
    if ($error->status !== 404 || $error->errorCode !== 'resource_not_found') {
        throw $error;
    }
}
echo "PASS PHP SDK → isolated Sandbox: documented example, replay, final outcome, refund and typed error\n";

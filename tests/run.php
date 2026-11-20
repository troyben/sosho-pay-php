<?php

require __DIR__.'/../vendor/autoload.php';
require __DIR__.'/../examples/payment.php';

use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use SoshoPay\ApiException;
use SoshoPay\Client;
use SoshoPay\ProtocolException;
use SoshoPay\Testing\FakeHttpClient;
use SoshoPay\Testing\WebhookPayload;
use SoshoPay\TransportException;
use SoshoPay\Webhook;

final class NetworkFailure extends RuntimeException implements NetworkExceptionInterface
{
    public function getRequest(): RequestInterface
    {
        return (new HttpFactory)->createRequest('POST', 'https://gateway.example.com');
    }
}
final class FakeHttp implements ClientInterface
{
    public array $calls = [];

    public function __construct(public array $results) {}

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->calls[] = $request;
        $next = array_shift($this->results);
        // Consume request streams as a real client does; retries must rebuild them.
        $request->getBody()->getContents();
        if ($next instanceof Throwable) {
            throw $next;
        }

        return $next;
    }
}
$checks = 0;
function check(bool $result, string $name): void
{
    global $checks;
    $checks++;
    if (! $result) {
        throw new RuntimeException('FAIL: '.$name);
    }
}
function rejects(callable $fn, string $class, ?callable $inspect = null): void
{
    try {
        $fn();
    } catch (Throwable $e) {
        check($e instanceof $class && ($inspect === null || $inspect($e)), $class);

        return;
    }
    throw new RuntimeException('Expected '.$class);
}
function client(FakeHttp $http, int $retries = 2): Client
{
    $factory = new HttpFactory;

    return new Client('https://gateway.example.com', 'sk_test_contract', $http, $factory, $factory, $retries);
}
$envelope = ['data' => ['id' => 'pay_contract', 'status' => 'pending', 'amount' => 100], 'meta' => ['request_id' => 'req_contract', 'mode' => 'sandbox']];
$ok = fn () => new Response(200, ['Content-Type' => 'application/json'], json_encode($envelope));
$http = new FakeHttp([new NetworkFailure, new Response(503), $ok()]);
$result = paymentExample(client($http), 'order-attempt');
check($result === $envelope, 'documented example response');
check(count($http->calls) === 3, 'network and 5xx retries bounded');
foreach ($http->calls as $request) {
    check($request->getHeaderLine('Idempotency-Key') === 'order-attempt', 'same key');
    $request->getBody()->rewind();
    check(json_decode($request->getBody()->getContents(), true) === json_decode(file_get_contents(__DIR__.'/fixtures/payment.json'), true), 'same tested example body after stream consumption');
}
foreach ([400, 401, 403, 409, 422, 429] as $status) {
    $http = new FakeHttp([new Response($status, [], json_encode(['error' => ['code' => 'in_flight', 'message' => 'Wait', 'request_id' => 'req_error']]))]);
    rejects(fn () => paymentExample(client($http), 'recover-key'), ApiException::class, fn ($e) => $e->status === $status && $e->errorCode === 'in_flight' && $e->requestId === 'req_error' && $e->idempotencyKey === 'recover-key');
    check(count($http->calls) === 1, 'no retry on '.$status);
}
foreach (['testWebhookEndpoint', 'approveSandboxPayment'] as $operation) {
    $http = new FakeHttp([new Response(503, [], '{"error":{"code":"unavailable","message":"Unavailable"}}')]);
    rejects(fn () => client($http)->request($operation, ['path' => ['id' => 'obj_contract']]), ApiException::class);
    check(count($http->calls) === 1 && ! $http->calls[0]->hasHeader('Idempotency-Key'), 'no unsafe write retry');
}
$http = new FakeHttp([new NetworkFailure, new NetworkFailure, new NetworkFailure]);
rejects(fn () => paymentExample(client($http), 'recover-key'), TransportException::class, fn ($e) => $e->idempotencyKey === 'recover-key');
$http = new FakeHttp([$ok()]);
paymentExample(client($http), null);
check(strlen($http->calls[0]->getHeaderLine('Idempotency-Key')) === 48, 'automatic random key');
$http = new FakeHttp([new Response(200, [], '{malformed')]);
rejects(fn () => client($http)->getPayment('pay_contract'), ProtocolException::class);
rejects(fn () => client($http)->getPayment('../payments'), InvalidArgumentException::class);
rejects(fn () => client($http)->createPayment(['amount' => 1.25]), InvalidArgumentException::class);
$factory = new HttpFactory;
foreach (['http://remote.example.com', 'https://user:pass@host', 'https://host/path', 'https://host?x=y', 'https://host/#fragment'] as $base) {
    rejects(fn () => new Client($base, 'sk_test_contract', $http, $factory, $factory), InvalidArgumentException::class);
}
ob_start();
var_dump(client($http));
$debug = ob_get_clean();
check(! str_contains($debug, 'sk_test_contract'), 'debug redacts key');
$raw = '{"id":"evt_1", "amount":100}';
$secret = 'whsec_contract';
$time = 1800000000;
$header = 't='.$time.',v1='.hash_hmac('sha256', $time.'.'.$raw, $secret);
check(Webhook::verify($secret, $raw, $header, $time), 'raw signature');
check(Webhook::verify($secret, $raw, $header, $time + 300), 'tolerance boundary');
foreach ([$time - 301, $time + 301] as $now) {
    check(! Webhook::verify($secret, $raw, $header, $now), 'future or stale');
}
check(! Webhook::verify($secret, $raw.' ', $header, $time), 'tamper');
check(! Webhook::verify($secret, json_encode(json_decode($raw)), $header, $time), 'no reserialization');
check(! Webhook::verify($secret, $raw, $header, $time, 0), 'cannot disable replay window');
check(! Webhook::verify('whsec_wrong', $raw, $header, $time), 'wrong secret');
$offline = new FakeHttpClient([$ok()]);
$offlineClient = new Client('https://offline.example', 'sk_test_offline', $offline, $factory, $factory, 0);
check($offlineClient->getPayment('pay_contract') === $envelope && count($offline->requests) === 1, 'packaged offline fake uses real SDK serialization');
$fixture = WebhookPayload::build(['id' => 'evt_test', 'type' => 'payment.succeeded', 'created_at' => '2026-11-19T00:00:00Z', 'data' => ['amount' => 100]], $secret, $time);
check(Webhook::verify($secret, $fixture['body'], $fixture['headers']['Soshopay-Signature'], $time), 'packaged signed webhook fixture verifies');
echo "PASS $checks PHP SDK assertions\n";

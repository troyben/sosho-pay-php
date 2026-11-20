# Soshopay PHP SDK 1.0.0

PHP 8.2+, 64-bit runtime, JSON, PSR-18 client and PSR-17 request/stream factories. No business
logic or Laravel dependency in the core.

Install in your application after the first public Packagist release:

```sh
composer require soshopay/sdk guzzlehttp/guzzle
```

Laravel 12 already includes Guzzle, so use `composer require soshopay/sdk` there.
The first public release is pending; these commands become available when version 1.0.0
is published. `composer require` adds a dependency; `composer install` restores declared ones.
The public package requires no custom Composer repositories.

Until publication, install from the provided source distribution using a Composer path repository in your application:

```json
{
  "repositories": [{"type": "path", "url": "../sosho-gateway/sdks/php"}],
  "require": {"soshopay/sdk": "@dev", "guzzlehttp/guzzle": "^7.10"}
}
```

Run `composer update soshopay/sdk guzzlehttp/guzzle` in that application. For SDK development,
run `composer install --no-interaction` and `composer test` inside `sdks/php`.

Alternatively download the PHP source archive from Developers, extract into an empty directory,
and run `composer install` there. Set `SOSHO_BASE_URL`, a Sandbox `SOSHO_SECRET_KEY` and a durable
`SOSHO_IDEMPOTENCY_KEY`; `php examples/payment.php` then runs the example and polls for success.
The executable example refuses Live keys and never reports pending as a successful payment.

```php
use SoshoPay\Client;
require __DIR__.'/vendor/autoload.php';
$factory = new GuzzleHttp\Psr7\HttpFactory;
$client = new Client(getenv('SOSHO_BASE_URL'), getenv('SOSHO_SECRET_KEY'),
    new GuzzleHttp\Client(['timeout' => 15, 'connect_timeout' => 5, 'allow_redirects' => false]),
    $factory, $factory);
// Persist this unique key with the order BEFORE sending.
$result = $client->createPayment([
    'amount' => 100, 'currency' => 'USD', 'rail' => 'ecocash',
    'customer' => ['msisdn' => '+263770000001'], 'reference' => 'ORDER-1',
], $order->payment_attempt_key);
$current = $client->getPayment($result['data']['id']);
// pending is not success; fulfill only when succeeded.
```

`request($operationId, ['path' => [...], 'query' => [...], 'body' => [...]], $idempotencyKey)`
covers all generated operations. Payment/refund helpers are included. Responses retain the
`data`/`meta` envelope, including `next_cursor`; statement downloads return raw PDF bytes and `streamEvents` returns finite SSE text.
Use integer minor-unit amounts. Never recalculate fees in the SDK.

An injected PSR-18 client must not follow redirects carrying credentials. Configure its network
timeouts; PSR-18 does not define timeout settings. Origins require HTTPS except loopback local
development. Only GET and server-idempotent payment/refund writes retry network failures or
5xx, with at most two additional attempts. Every retry rebuilds the same body stream/key.
4xx and non-idempotent webhook/Sandbox writes are not automatically retried. Pass `maxRetries: 0`
to disable retries. Automatic keys are convenient for experiments, but persist caller keys for
order recovery. Transport/API/protocol exceptions expose `idempotencyKey` for ambiguous outcomes.

`ApiException` exposes HTTP `status`, string `errorCode`, `requestId`, `param`, `idempotencyKey`.
Handle known codes and unknown future codes. `TransportException` and `ProtocolException` are
separate; neither proves the provider did not accept a charge. Never log client objects, keys or
full customer payloads. `__debugInfo()` redacts the key, and constructor secrets are sensitive parameters.

`Webhook::verify($secret, $rawBody, $signatureHeader)` uses raw bytes, constant-time HMAC and
a five-minute past/future timestamp window. Parse after verification, persist an inbox and
deduplicate by event ID; signatures alone do not stop duplicates in the permitted window.

## Laravel

The optional provider is auto-discovered only by Laravel. Configure `SOSHO_BASE_URL` and
`SOSHO_SECRET_KEY` in your application's environment, then inject `SoshoPay\Client`.
The provider reads configuration through Laravel config caching; it never reads env in request code.
Without application HTTP bindings, it uses Laravel's Guzzle dependencies with redirects disabled,
a 15-second total timeout and 5-second connection timeout. Existing bindings for
`Psr\Http\Client\ClientInterface`, `RequestFactoryInterface` and `StreamFactoryInterface`
take precedence; the SDK does not replace application-wide bindings. Laravel 12 is tested.
Optionally run `php artisan vendor:publish --tag=sosho-pay-config` to publish configuration.
Refresh the application's configuration cache after changing its environment settings.

In an existing Composer application, run the bundled Sandbox example with
`php vendor/soshopay/sdk/examples/payment.php` after setting the three server environment
variables above. Keep its idempotency key stable for the same payment attempt.

`examples/payment.php` is the tested source embedded in the guides. The CI contract script
refuses Live/non-CI targets. Generated `Operations.php` must match OpenAPI; regeneration is a CI gate.

## Offline tests

Inject `SoshoPay\Testing\FakeHttpClient` (queue of PSR-7 response objects) and your PSR-17 factories
into `Client` to exercise the real SDK without HTTP. Inspect its `requests` array. Build signed
handler fixtures with `SoshoPay\Testing\WebhookPayload::build($event, $localSecret, $timestamp)`;
it returns raw `body` and `headers` using the production webhook shape/HMAC algorithm. Use separate
local secrets. The TypeScript tarball includes the Sandbox-only `soshopay` local forwarding CLI.

## License

MIT. See [LICENSE](./LICENSE). This license covers this SDK, not the gateway application.

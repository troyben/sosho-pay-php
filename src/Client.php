<?php

namespace SoshoPay;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/** Server only. PSR-18 implementations MUST NOT follow redirects with credentials.
 * Configure network timeouts on the injected client; PSR-18 has no timeout option.
 */
final class Client
{
    private readonly string $baseUrl;

    private readonly string $apiKey;

    public function __construct(
        string $baseUrl,
        #[\SensitiveParameter] string $apiKey,
        private readonly ClientInterface $http,
        private readonly RequestFactoryInterface $requests,
        private readonly StreamFactoryInterface $streams,
        private readonly int $maxRetries = 2,
    ) {
        if (PHP_INT_SIZE < 8) {
            throw new \RuntimeException('The SDK requires 64-bit PHP for integer minor-unit amounts.');
        }
        $url = parse_url($baseUrl);
        if (! is_array($url) || ! isset($url['scheme'], $url['host']) ||
            ($url['scheme'] !== 'https' && ! ($url['scheme'] === 'http' && in_array($url['host'], ['localhost', '127.0.0.1', '[::1]'], true))) ||
            isset($url['user'], $url['pass']) || isset($url['user']) || isset($url['query']) || isset($url['fragment']) ||
            ! in_array($url['path'] ?? '', ['', '/'], true)) {
            throw new \InvalidArgumentException('Use an HTTPS gateway origin without credentials, path, query or fragment (HTTP is allowed only for loopback development).');
        }
        if (! preg_match('/^sk_(test|live)_[A-Za-z0-9]+$/D', $apiKey)) {
            throw new \InvalidArgumentException('A secret API key is required.');
        }
        if ($maxRetries < 0 || $maxRetries > 2) {
            throw new \InvalidArgumentException('maxRetries must be between zero and two.');
        }
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->apiKey = $apiKey;
    }

    /** Input: ['path' => ['id' => '...'], 'query' => [...], 'body' => [...]].
     * Returns the full data/meta envelope, or raw PDF bytes for a statement.
     */
    public function request(string $operation, array $input = [], ?string $idempotencyKey = null): array|string
    {
        $route = Operations::ROUTES[$operation] ?? throw new \InvalidArgumentException('Unknown API operation.');
        $path = preg_replace_callback('/\{([^}]+)\}/', function ($match) use ($input) {
            $id = $input['path'][$match[1]] ?? '';
            if (! is_string($id) || ! preg_match('/^[A-Za-z0-9_-]+$/D', $id)) {
                throw new \InvalidArgumentException('An API object id is required; paths and URLs are not accepted.');
            }

            return rawurlencode($id);
        }, $route['path']);
        $url = $this->baseUrl.$path;
        if (! empty($input['query'])) {
            $url .= '?'.http_build_query($input['query'], '', '&', PHP_QUERY_RFC3986);
        }
        if (isset($input['body']['amount']) && ! is_int($input['body']['amount'])) {
            throw new \InvalidArgumentException('amount must be integer minor units.');
        }
        $body = array_key_exists('body', $input) ? json_encode($input['body'] ?: new \stdClass, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) : null;
        $key = $route['idempotent'] ? ($idempotencyKey ?? bin2hex(random_bytes(24))) : null;
        if ($key !== null && (! preg_match('/^[\x21-\x7e]{1,255}$/D', $key))) {
            throw new \InvalidArgumentException('Use an ASCII idempotency key with 1–255 visible characters.');
        }
        $retries = $route['method'] === 'GET' || $route['idempotent'] ? $this->maxRetries : 0;
        for ($attempt = 0; $attempt <= $retries; $attempt++) {
            if ($attempt > 0) {
                usleep(100000 * 2 ** ($attempt - 1));
            }
            // Rebuild the stream for each attempt: a transport may have consumed it on failure.
            $request = $this->requests->createRequest($route['method'], $url)
                ->withHeader('Authorization', 'Bearer '.$this->apiKey)->withHeader('Accept', 'application/json');
            if ($key !== null) {
                $request = $request->withHeader('Idempotency-Key', $key);
            }
            if ($body !== null) {
                $request = $request->withHeader('Content-Type', 'application/json')->withBody($this->streams->createStream($body));
            }
            try {
                $response = $this->http->sendRequest($request);
            } catch (NetworkExceptionInterface) {
                if ($attempt < $retries) {
                    continue;
                }
                throw new TransportException($key);
            } catch (ClientExceptionInterface) {
                // Invalid requests are not network failures. Never retry or retain a credential-bearing request in an exception.
                throw new ProtocolException($key);
            }
            $status = $response->getStatusCode();
            if ($status >= 500 && $status <= 599 && $attempt < $retries) {
                $response->getBody()->close();

                continue;
            }
            if ($status >= 200 && $status < 300 && in_array($operation, ['downloadSettlementStatement', 'streamEvents'], true)) {
                return (string) $response->getBody();
            }
            try {
                $decoded = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
            } catch (\JsonException) {
                throw new ProtocolException($key);
            }
            if ($status < 200 || $status >= 300) {
                $error = is_array($decoded) && is_array($decoded['error'] ?? null) ? $decoded['error'] : [];
                throw new ApiException($status, $error['code'] ?? 'unknown_error', $error['message'] ?? 'The gateway refused the request.', $error['request_id'] ?? ($response->getHeaderLine('X-Request-Id') ?: null), $error['param'] ?? null, $key);
            }
            if (! is_array($decoded) || ! array_key_exists('data', $decoded) || ! isset($decoded['meta'])) {
                throw new ProtocolException($key);
            }

            return $decoded;
        }
        throw new TransportException($key);
    }

    public function createPayment(array $body, ?string $idempotencyKey = null): array
    {
        return $this->request('createPayment', ['body' => $body], $idempotencyKey);
    }

    public function getPayment(string $id): array
    {
        return $this->request('getPayment', ['path' => ['id' => $id]]);
    }

    public function createRefund(string $paymentId, array $body = [], ?string $idempotencyKey = null): array
    {
        return $this->request('createRefund', ['path' => ['id' => $paymentId], 'body' => $body], $idempotencyKey);
    }

    public function __debugInfo(): array
    {
        return ['baseUrl' => $this->baseUrl, 'apiKey' => '[redacted]', 'maxRetries' => $this->maxRetries];
    }
}

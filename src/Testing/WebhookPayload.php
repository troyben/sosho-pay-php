<?php

namespace SoshoPay\Testing;

/** Signed offline fixture using the production webhook body shape and HMAC algorithm. */
final class WebhookPayload
{
    public static function build(array $event, #[\SensitiveParameter] string $secret = 'whsec_offline_fixture', ?int $timestamp = null): array
    {
        if (! str_starts_with($secret, 'whsec_')) {
            throw new \InvalidArgumentException('A local webhook signing secret is required.');
        }
        $timestamp ??= time();
        $body = json_encode(['id' => $event['id'], 'type' => $event['type'], 'created_at' => $event['created_at'], 'data' => $event['data']], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return ['body' => $body, 'headers' => [
            'Content-Type' => 'application/json',
            'Soshopay-Signature' => 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$body, $secret),
            'Soshopay-Event-Id' => $event['id'],
            'Soshopay-Delivery-Id' => 'local_'.bin2hex(random_bytes(12)),
            'Soshopay-Attempt' => '1',
        ]];
    }
}

<?php

namespace SoshoPay;

final class Webhook
{
    public static function verify(#[\SensitiveParameter] string $secret, string $rawBody, ?string $header, ?int $now = null, int $tolerance = 300): bool
    {
        if (! str_starts_with($secret, 'whsec_') || $tolerance < 1 || $tolerance > 300 || ! preg_match('/^t=(\d{1,16}),v1=([a-f0-9]{64})$/D', $header ?? '', $m)) {
            return false;
        }
        $timestamp = (int) $m[1];
        if (abs(($now ?? time()) - $timestamp) > $tolerance) {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $m[1].'.'.$rawBody, $secret), $m[2]);
    }
}

<?php

namespace SoshoPay;

final class TransportException extends \RuntimeException
{
    public function __construct(public readonly ?string $idempotencyKey = null)
    {
        parent::__construct('No gateway response was obtained. Recover with the same idempotency key before creating another payment.');
    }
}

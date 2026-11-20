<?php

namespace SoshoPay;

final class ProtocolException extends \RuntimeException
{
    public function __construct(public readonly ?string $idempotencyKey = null)
    {
        parent::__construct('The gateway returned an invalid response.');
    }
}

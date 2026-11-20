<?php

namespace SoshoPay;

final class ApiException extends \RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
        public readonly ?string $requestId = null,
        public readonly ?string $param = null,
        public readonly ?string $idempotencyKey = null,
    ) {
        parent::__construct($message);
    }
}

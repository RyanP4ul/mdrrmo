<?php

namespace App\Services;

use RuntimeException;

class SmsGatewayException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $statusCode = 503,
        public readonly ?string $field = null,
        public readonly array $extra = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $statusCode, $previous);
    }
}

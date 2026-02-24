<?php

namespace Funnelchat\WapiGateway\Exceptions;

use RuntimeException;
use Throwable;

class WapiException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $provider,
        public readonly ?string $errorCode = null,
        public readonly mixed $rawError = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}

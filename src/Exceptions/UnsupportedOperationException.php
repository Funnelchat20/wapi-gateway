<?php

namespace Funnelchat\WapiGateway\Exceptions;

use RuntimeException;

class UnsupportedOperationException extends RuntimeException
{
    public function __construct(string $operation = '', string $provider = '')
    {
        $message = $provider
            ? "Operation '{$operation}' is not supported by the {$provider} provider."
            : "Operation '{$operation}' is not supported.";

        parent::__construct($message);
    }
}

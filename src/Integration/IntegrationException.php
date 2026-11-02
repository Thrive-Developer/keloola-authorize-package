<?php

namespace Keloola\KeloolaSsoAuthorize\Integration;

class IntegrationException extends \RuntimeException
{
    public function __construct(public string $errorCode, string $message, public int $status = 409)
    {
        parent::__construct($message);
    }
}

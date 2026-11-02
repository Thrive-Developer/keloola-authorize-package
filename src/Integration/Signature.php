<?php

namespace Keloola\KeloolaSsoAuthorize\Integration;

class Signature
{
    public static function sign(string $secret, string $timestamp, string $method, string $path, string $query, string $body, string $appId, string $nonce): string
    {
        return 'sha256='.hash_hmac('sha256', implode("\n", [$timestamp, strtoupper($method), $path, $query, $body, $appId, $nonce]), $secret);
    }
}

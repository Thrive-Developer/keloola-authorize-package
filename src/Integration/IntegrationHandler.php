<?php

namespace Keloola\KeloolaSsoAuthorize\Integration;

/** Implement this interface when provisioning native application models. */
interface IntegrationHandler
{
    /** Return response data, or throw IntegrationException for a business error.
     * Operations and validated input are documented in docs/integration.md.
     * Implementations must enforce tenant isolation and idempotency.
     */
    public function handle(string $operation, array $context, array $payload): array;
}

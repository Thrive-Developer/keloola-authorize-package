<?php

namespace Keloola\KeloolaSsoAuthorize\Tests;

use Keloola\KeloolaSsoAuthorize\KeloolaSsoAuthorizeServiceProvider;
use Orchestra\Testbench\TestCase;

class IntegrationDisabledTest extends TestCase
{
    protected function getPackageProviders($app) { return [KeloolaSsoAuthorizeServiceProvider::class]; }

    public function test_no_integration_routes_are_registered_by_default(): void
    {
        $this->assertFalse(config('keloolauthorize.integration.enabled'));
        $this->assertFalse($this->app['router']->has('keloola.integration.organizations.provision'));
        $this->assertSame(\Keloola\KeloolaSsoAuthorize\Http\Middleware\KeloolaScopeMiddleware::class, $this->app['router']->getMiddleware()['keloola.scope']);
    }
}

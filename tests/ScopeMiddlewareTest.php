<?php

namespace Keloola\KeloolaSsoAuthorize\Tests;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Keloola\KeloolaSsoAuthorize\Http\Middleware\KeloolaScopeMiddleware;
use Orchestra\Testbench\TestCase;

require_once __DIR__.'/../src/Http/Middleware/KeloolaScopeMiddleware.php';
require_once __DIR__.'/../src/KeloolaSsoAuthorizeServiceProvider.php';

class ScopeMiddlewareTest extends TestCase
{
    protected function getPackageProviders($app)
    {
        return [\Keloola\KeloolaSsoAuthorize\KeloolaSsoAuthorizeServiceProvider::class];
    }

    public function test_middleware_alias_is_registered(): void
    {
        $this->assertSame(KeloolaScopeMiddleware::class, $this->app['router']->getMiddleware()['keloola.scope']);
    }

    protected function setUp(): void
    {
        parent::setUp();
        config(['keloolauthorize.keloola_auth_sso_host' => 'https://sso.example', 'keloolauthorize.keloola_auth_app_id' => 5]);
        Http::preventStrayRequests();
    }

    private function runMiddleware(array $input = [], bool $authenticated = true)
    {
        $request = Request::create('/sales-orders', 'POST', $input);
        if ($authenticated) $request->headers->set('Authorization', 'Bearer example-token');
        return (new KeloolaScopeMiddleware)->handle($request, fn ($request) => response()->json($request->attributes->get('oauth_access')), 'accounting:sales-orders:write', 'sales_order.create');
    }

    private function access(): array
    {
        return ['data' => ['user_id' => 'user', 'app_id' => 5, 'organization_id' => 'org', 'scope' => 'accounting:sales-orders:write', 'permission' => 'sales_order.create']];
    }

    public function test_validates_online_each_time_and_ignores_forged_request_payloads(): void
    {
        Http::fake(['sso.example/*' => Http::sequence()->push($this->access())->push(['message' => 'Revoked'], 403)]);
        $this->assertSame(200, $this->runMiddleware(['sso_user' => ['role' => 'admin'], 'oauth_access' => ['organization_id' => 'attacker']])->getStatusCode());
        $this->assertSame(403, $this->runMiddleware()->getStatusCode());
        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer example-token') && $request['app_id'] === 5 && $request['permission'] === 'sales_order.create');
    }

    public function test_missing_invalid_and_insufficient_tokens_fail_closed(): void
    {
        $this->assertSame(401, $this->runMiddleware([], false)->getStatusCode());
        Http::assertNothingSent();
        Http::fake(['sso.example/*' => Http::sequence()->push([], 401)->push([], 403)->push([], 500)->push([])]);
        foreach ([401, 403, 503, 503] as $status) $this->assertSame($status, $this->runMiddleware()->getStatusCode());
    }

    public function test_wrong_organization_and_resource_app_are_denied(): void
    {
        $payload = $this->access();
        $payload['data']['app_id'] = 6;
        Http::fake(['sso.example/*' => Http::sequence()->push($this->access())->push($payload)]);
        $this->assertSame(403, $this->runMiddleware(['organization_id' => 'other'])->getStatusCode());
        $this->assertSame(503, $this->runMiddleware()->getStatusCode());
    }

    public function test_network_failure_and_missing_configuration_never_allow_access(): void
    {
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('Offline'));
        $this->assertSame(503, $this->runMiddleware()->getStatusCode());
        config(['keloolauthorize.keloola_auth_app_id' => null]);
        $this->assertSame(503, $this->runMiddleware()->getStatusCode());
    }
}

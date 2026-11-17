<?php

namespace Keloola\KeloolaSsoAuthorize\Tests;

use Illuminate\Http\Request;
use Keloola\KeloolaSsoAuthorize\Http\Middleware\KeloolaAuthMiddleware;
use Keloola\KeloolaSsoAuthorize\Services\KeloolaSsoService;
use Orchestra\Testbench\TestCase;

class AuthMiddlewareTest extends TestCase
{
    protected function getPackageProviders($app)
    {
        return [\Keloola\KeloolaSsoAuthorize\KeloolaSsoAuthorizeServiceProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'keloolauthorize.keloola_auth_sso_host' => 'https://sso.example/',
            'keloolauthorize.keloola_auth_app_id' => 5,
        ]);
    }

    private function runMiddleware($payload = null, bool $authenticated = true)
    {
        $service = $this->createMock(KeloolaSsoService::class);
        $service->expects($authenticated ? $this->once() : $this->never())
            ->method('user')->willReturn($payload);
        $request = Request::create('/orders');
        if ($authenticated) $request->headers->set('Authorization', 'Bearer example-token');

        return (new KeloolaAuthMiddleware($service))->handle($request, fn () => response()->json(['ok' => true]));
    }

    private function userWithApplications(array $applications): object
    {
        return json_decode(json_encode(['status' => 200, 'data' => ['applications' => $applications]]));
    }

    private function application(array $subscriptions): array
    {
        return [
            'id' => 5,
            'default_organization' => 'org',
            'organizations' => [['id' => 'org', 'subscriptions' => $subscriptions]],
        ];
    }

    public function test_expired_subscription_redirects_to_renewal_and_active_subscription_passes(): void
    {
        $response = $this->runMiddleware($this->userWithApplications([
            $this->application([['id' => 'sub/123', 'status' => 'expired']]),
        ]));
        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame(__('keloolauthorize::message.expired_subscribtion'), $response->getData(true)['message']);
        $this->assertSame('https://sso.example/subscription?renew=sub%2F123', $response->getData(true)['redirect']);

        $response = $this->runMiddleware($this->userWithApplications([
            $this->application([['id' => 'sub123', 'status' => 'active']]),
        ]));
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['ok' => true], $response->getData(true));
    }

    public function test_other_unauthorized_responses_redirect_to_dashboard(): void
    {
        $responses = [
            $this->runMiddleware(null, false),
            $this->runMiddleware((object) ['status' => 401, 'message' => 'Invalid token']),
            $this->runMiddleware($this->userWithApplications([])),
            $this->runMiddleware($this->userWithApplications([['id' => 6]])),
            $this->runMiddleware($this->userWithApplications([['id' => 5, 'organizations' => []]])),
            $this->runMiddleware($this->userWithApplications([$this->application([])])),
        ];
        foreach ($responses as $response) {
            $this->assertSame(401, $response->getStatusCode());
            $this->assertSame('https://sso.example/dashboard', $response->getData(true)['redirect']);
        }
    }

    public function test_other_sso_errors_keep_their_status_and_response(): void
    {
        $response = $this->runMiddleware((object) ['status' => 403, 'message' => 'Forbidden']);
        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame(['message' => 'Forbidden'], $response->getData(true));
    }
}

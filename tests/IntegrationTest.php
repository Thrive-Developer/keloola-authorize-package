<?php

namespace Keloola\KeloolaSsoAuthorize\Tests;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Keloola\KeloolaSsoAuthorize\Integration\IntegrationHandler;
use Keloola\KeloolaSsoAuthorize\Integration\Signature;
use Keloola\KeloolaSsoAuthorize\KeloolaSsoAuthorizeServiceProvider;
use Orchestra\Testbench\TestCase;

class IntegrationTest extends TestCase
{
    private const ORG = '11111111-1111-4111-8111-111111111111';
    private const OTHER = '99999999-9999-4999-8999-999999999999';
    private const OWNER = '22222222-2222-4222-8222-222222222222';
    private const MEMBER = '33333333-3333-4333-8333-333333333333';
    private const SECRET = 'test-secret-with-at-least-thirty-two-characters';
    private string $prefix = '/api/integrations/sso';

    protected function getPackageProviders($app) { return [KeloolaSsoAuthorizeServiceProvider::class]; }

    protected function defineEnvironment($app)
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]);
        $app['config']->set('cache.default', 'array');
        $app['config']->set('keloolauthorize.keloola_auth_app_id', 123);
        $app['config']->set('keloolauthorize.integration.enabled', true);
        $app['config']->set('keloolauthorize.integration.secret', self::SECRET);
        $app['config']->set('keloolauthorize.integration.permissions', [
            ['id' => 101, 'name' => 'inventory.read', 'guard_name' => 'web', 'group' => 'inventory'],
            ['id' => 102, 'name' => 'inventory.update', 'guard_name' => 'web', 'group' => 'inventory'],
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $migration = require __DIR__.'/../database/migrations/2026_11_02_000001_create_keloola_sso_integration_tables.php';
        $migration->up();
    }

    private function signed(string $method, string $path, ?array $body = null, array $headers = [])
    {
        $raw = $body === null ? '' : json_encode($body, JSON_UNESCAPED_SLASHES);
        $uri = $this->prefix.$path;
        $timestamp = (string) time();
        $nonce = bin2hex(random_bytes(16));
        $signature = Signature::sign(self::SECRET, $timestamp, $method, explode('?', $uri, 2)[0], explode('?', $uri, 2)[1] ?? '', $raw, '123', $nonce);
        $server = array_merge([
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_KELOOLA_TIMESTAMP' => $timestamp, 'HTTP_X_KELOOLA_NONCE' => $nonce,
            'HTTP_X_KELOOLA_APP_ID' => '123', 'HTTP_X_KELOOLA_SIGNATURE' => $signature,
        ], $headers);
        return $this->call($method, $uri, [], [], [], $server, $raw);
    }

    private function orgPayload(string $org = self::ORG): array
    {
        return ['sso_organization_id' => $org, 'organization_name' => 'Example', 'organization_email' => 'office@example.com',
            'owner' => ['sso_id' => self::OWNER, 'name' => 'Owner', 'email' => 'owner@example.com']];
    }

    private function provision(string $org = self::ORG): int
    {
        return $this->signed('POST', '/organizations', $this->orgPayload($org))->assertOk()->assertJsonPath('data.status', 'ready')->json('data.owner_role_id');
    }

    private function createRole(string $org = self::ORG, string $source = 'sso-role-1', array $permissions = [101]): int
    {
        return $this->signed('POST', '/organizations/'.$org.'/roles', ['sso_role_id' => $source, 'name' => 'Viewer', 'permissions' => $permissions])
            ->assertOk()->json('data.id');
    }

    private function member(int $role): array
    {
        return ['sso_id' => self::MEMBER, 'name' => 'Member', 'email' => 'member@example.com', 'role_id' => $role];
    }

    public function test_hmac_has_a_stable_independent_test_vector(): void
    {
        $canonical = "1700000000\nPOST\n/api/test\nb=2&a=1\n{}\n123\n0123456789abcdef";
        $expected = 'sha256='.hash_hmac('sha256', $canonical, self::SECRET);
        $this->assertSame($expected, Signature::sign(self::SECRET, '1700000000', 'post', '/api/test', 'b=2&a=1', '{}', '123', '0123456789abcdef'));
    }

    public function test_missing_invalid_stale_and_wrong_app_auth_never_mutates_data(): void
    {
        $this->postJson($this->prefix.'/organizations', $this->orgPayload())->assertUnauthorized();
        foreach ([['HTTP_X_KELOOLA_SIGNATURE' => 'sha256=bad'], ['HTTP_X_KELOOLA_TIMESTAMP' => (string) (time() - 301)], ['HTTP_X_KELOOLA_APP_ID' => '999']] as $headers) {
            $this->signed('POST', '/organizations', $this->orgPayload(), $headers)->assertUnauthorized();
        }
        $this->assertDatabaseCount('keloola_sso_organizations', 0);
    }

    public function test_exact_nonce_replay_is_rejected_but_business_retry_with_new_nonce_is_safe(): void
    {
        $uri = $this->prefix.'/permissions'; $ts = (string) time(); $nonce = '0123456789abcdef';
        $server = ['HTTP_ACCEPT'=>'application/json', 'HTTP_X_KELOOLA_TIMESTAMP'=>$ts, 'HTTP_X_KELOOLA_NONCE'=>$nonce, 'HTTP_X_KELOOLA_APP_ID'=>'123',
            'HTTP_X_KELOOLA_SIGNATURE'=>Signature::sign(self::SECRET,$ts,'GET',$uri,'','','123',$nonce)];
        $this->call('GET', $uri, [], [], [], $server)->assertOk();
        $this->call('GET', $uri, [], [], [], $server)->assertStatus(409)->assertJsonPath('error','replayed_request');
        $first = $this->provision();
        $this->assertSame($first, $this->provision());
        $this->assertDatabaseCount('keloola_sso_organizations', 1);
        $this->assertDatabaseCount('keloola_sso_memberships', 1);
        $this->assertDatabaseCount('keloola_sso_roles', 1);
    }

    public function test_disabled_and_unconfigured_integrations_fail_closed(): void
    {
        config(['keloolauthorize.integration.enabled' => false]);
        $this->signed('GET', '/permissions')->assertNotFound();
        config(['keloolauthorize.integration.enabled' => true, 'keloolauthorize.integration.secret' => 'short']);
        $this->signed('GET', '/permissions')->assertStatus(503);
    }

    public function test_cache_outage_fails_closed(): void
    {
        Cache::shouldReceive('store')->once()->andThrow(new \RuntimeException('Unavailable'));
        $this->signed('POST', '/organizations', $this->orgPayload())->assertStatus(503);
        $this->assertDatabaseCount('keloola_sso_organizations', 0);
    }

    public function test_raw_query_order_and_body_are_bound_to_signature(): void
    {
        $this->signed('GET', '/permissions?b=2&a=1')->assertOk();
        $ts=(string)time();$nonce=bin2hex(random_bytes(16));$uri=$this->prefix.'/organizations';
        $sig=Signature::sign(self::SECRET,$ts,'POST',$uri,'','{}','123',$nonce);
        $this->signed('POST','/organizations',$this->orgPayload(),['HTTP_X_KELOOLA_TIMESTAMP'=>$ts,'HTTP_X_KELOOLA_NONCE'=>$nonce,'HTTP_X_KELOOLA_SIGNATURE'=>$sig])->assertUnauthorized();
    }

    public function test_validation_and_untrusted_extra_fields_are_handled_before_handler(): void
    {
        $this->signed('POST','/organizations',['organization_name'=>'Incomplete'])->assertUnprocessable()->assertJsonValidationErrors('sso_organization_id');
        $this->signed('PATCH','/organizations/not-a-uuid',['name'=>'X'])->assertUnprocessable();
        $this->signed('PATCH','/organizations/'.self::ORG,[])->assertUnprocessable();
        $this->app->instance(IntegrationHandler::class, new class implements IntegrationHandler {
            public function handle(string $operation, array $context, array $payload): array { return $payload; }
        });
        $p=$this->orgPayload();$p['sso_user']=['admin'=>true];$p['owner']['is_admin']=true;
        $response=$this->signed('POST','/organizations',$p)->assertOk();
        $this->assertArrayNotHasKey('sso_user',$response->json('data'));
        $this->assertArrayNotHasKey('is_admin',$response->json('data.owner'));
    }

    public function test_owner_cannot_be_replaced_removed_or_demoted(): void
    {
        $this->provision();$role=$this->createRole();
        $p=$this->orgPayload();$p['owner']['sso_id']=self::MEMBER;
        $this->signed('POST','/organizations',$p)->assertStatus(409);
        $this->signed('DELETE','/organizations/'.self::ORG.'/members/'.self::OWNER)->assertStatus(409);
        $this->signed('PUT','/organizations/'.self::ORG.'/members/'.self::OWNER.'/role',['role_id'=>$role])->assertStatus(409);
        $this->assertDatabaseCount('keloola_sso_users',1);
    }

    public function test_membership_lifecycle_is_tenant_scoped_and_idempotent(): void
    {
        $this->provision();$this->provision(self::OTHER);
        $role=$this->createRole();$otherRole=$this->createRole(self::OTHER);$editor=$this->createRole(self::ORG,'editor',[101,102]);
        $path='/organizations/'.self::ORG.'/members';
        $this->signed('POST',$path,$this->member($otherRole))->assertNotFound();
        $this->signed('POST',$path,$this->member($role))->assertOk();
        $this->signed('POST',$path,$this->member($role))->assertOk();
        $this->signed('POST','/organizations/'.self::OTHER.'/members',$this->member($otherRole))->assertOk();
        $this->signed('POST',$path,$this->member($editor))->assertStatus(409);
        $this->signed('PUT',$path.'/'.self::MEMBER.'/role',['role_id'=>$editor])->assertOk()->assertJsonPath('data.role_id',$editor);
        $access = new \Keloola\KeloolaSsoAuthorize\Integration\DirectoryAccess;
        $this->assertTrue($access->allows(self::ORG, self::MEMBER, 'inventory.update'));
        $this->signed('DELETE',$path.'/'.self::MEMBER)->assertOk();
        $this->assertFalse($access->allows(self::ORG, self::MEMBER, 'inventory.read'));
        $this->assertTrue($access->allows(self::OTHER, self::MEMBER, 'inventory.read'));
        $this->signed('DELETE',$path.'/'.self::MEMBER)->assertOk();
        $this->assertDatabaseHas('keloola_sso_memberships',['organization_id'=>self::OTHER,'user_id'=>self::MEMBER]);
        $this->assertDatabaseMissing('keloola_sso_memberships',['organization_id'=>self::ORG,'user_id'=>self::MEMBER]);
    }

    public function test_roles_reject_unknown_permissions_and_conflicting_retries(): void
    {
        $ownerRole=$this->provision();$role=$this->createRole();
        $this->assertSame($role,$this->createRole());
        $path='/organizations/'.self::ORG.'/roles';
        $this->signed('POST',$path,['sso_role_id'=>'sso-role-1','name'=>'Changed','permissions'=>[101]])->assertStatus(409);
        $this->signed('PUT',$path.'/'.$role,['name'=>'Bad','permissions'=>[999]])->assertUnprocessable();
        $this->signed('PUT',$path.'/'.$role,['name'=>'Editor','permissions'=>[102,101]])->assertOk()->assertJsonPath('data.permissions',[101,102]);
        $this->signed('DELETE',$path.'/'.$ownerRole)->assertStatus(409);
        $this->signed('POST','/organizations/'.self::ORG.'/members',$this->member($role))->assertOk();
        $this->signed('DELETE',$path.'/'.$role)->assertStatus(409);
        $this->signed('DELETE','/organizations/'.self::ORG.'/members/'.self::MEMBER)->assertOk();
        $this->signed('DELETE',$path.'/'.$role)->assertOk();
        $this->signed('DELETE',$path.'/'.$role)->assertOk();
        $this->signed('GET','/permissions')->assertOk()->assertJsonCount(2,'data');
        $this->signed('GET',$path)->assertOk()->assertJsonCount(1,'data');
    }

    public function test_role_updates_cannot_cross_tenants(): void
    {
        $this->provision();$this->provision(self::OTHER);$role=$this->createRole(self::OTHER);
        $this->signed('PUT','/organizations/'.self::ORG.'/roles/'.$role,['name'=>'Wrong tenant','permissions'=>[]])->assertNotFound();
        $this->signed('DELETE','/organizations/'.self::ORG.'/roles/'.$role)->assertOk();
        $this->assertDatabaseHas('keloola_sso_roles',['id'=>$role,'organization_id'=>self::OTHER,'name'=>'Viewer']);
    }

    public function test_patch_preserves_absent_fields_and_clears_nullable_fields(): void
    {
        $this->provision();
        $this->signed('PATCH','/organizations/'.self::ORG,['name'=>'New'])->assertOk()->assertJsonPath('data.email','office@example.com');
        $this->signed('PATCH','/organizations/'.self::ORG,['email'=>null])->assertOk()->assertJsonPath('data.email',null)->assertJsonPath('data.name','New');
        // A delayed installation retry must not overwrite the updated name.
        $this->provision();
        $this->assertDatabaseHas('keloola_sso_organizations',['sso_id'=>self::ORG,'name'=>'New','email'=>null]);
    }

    public function test_cancel_before_invite_leaves_a_tombstone_and_acceptance_does_not_grant_from_mirror(): void
    {
        $this->provision();$role=$this->createRole();
        $p=['sso_invitation_id'=>'invite-one','sso_organization_id'=>self::ORG,'email'=>'member@example.com','role_id'=>$role,'expires_at'=>gmdate('Y-m-d\TH:i:s\Z',time()+3600)];
        $this->signed('DELETE','/invitations/invite-one')->assertOk();
        $this->signed('POST','/invitations',$p)->assertOk()->assertJsonPath('data.status','removed');
        $p['sso_invitation_id']='invite-two';
        $this->signed('POST','/invitations',$p)->assertOk()->assertJsonPath('data.status','pending');
        $this->assertDatabaseCount('keloola_sso_memberships',1);
        $this->signed('POST','/organizations/'.self::ORG.'/members',$this->member($role))->assertOk();
        $this->signed('POST','/invitations',$p)->assertOk()->assertJsonPath('data.status','accepted');
        $this->signed('DELETE','/invitations/invite-two')->assertOk()->assertJsonPath('data.status','accepted');
        $this->assertDatabaseHas('keloola_sso_memberships',['organization_id'=>self::ORG,'user_id'=>self::MEMBER]);
    }

    public function test_database_failure_rolls_back_partial_provisioning(): void
    {
        config(['keloolauthorize.integration.permissions'=>[['id'=>1,'name'=>'one','guard_name'=>'web'],['id'=>1,'name'=>'duplicate','guard_name'=>'web']]]);
        $this->signed('POST','/organizations',$this->orgPayload())->assertStatus(503);
        $this->assertDatabaseCount('keloola_sso_organizations',0);
        $this->assertDatabaseCount('keloola_sso_users',0);
    }
    public function test_client_serialization_and_signature_are_accepted_by_real_route_handler(): void
    {
        // In-process transport: the package client sends its actual serialized
        // request to the real Laravel kernel, middleware, controller and DB.
        // This establishes the wire contract, not network/proxy behavior.
        \Illuminate\Support\Facades\Http::fake(function ($request) {
            $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
            foreach ($request->headers() as $key => $values) {
                $server['HTTP_'.strtoupper(str_replace('-', '_', $key))] = implode(', ', $values);
            }
            $path = parse_url($request->url(), PHP_URL_PATH);
            $response = $this->call($request->method(), $path, [], [], [], $server, $request->body());
            return \Illuminate\Support\Facades\Http::response($response->getContent(), $response->getStatusCode(), ['Content-Type'=>'application/json']);
        });
        $client = new \Keloola\KeloolaSsoAuthorize\Integration\IntegrationClient('https://app.example.com', self::SECRET, '123');
        $result = $client->send('POST', 'organizations', $this->orgPayload());
        $this->assertSame('ready', $result['status']);
        $this->assertSame(self::ORG, $result['organization_id']);
        $permissions = $client->send('GET', 'permissions');
        $this->assertCount(2, $permissions);
        try {
            $client->send('POST', 'organizations', []);
            $this->fail('Expected a validation error');
        } catch (\Keloola\KeloolaSsoAuthorize\Integration\IntegrationException $e) {
            $this->assertSame(422, $e->status);
        }
        $this->assertDatabaseCount('keloola_sso_organizations', 1);
    }

    public function test_client_rejects_malformed_success_and_does_not_follow_redirects(): void
    {
        \Illuminate\Support\Facades\Http::fake(['*'=>\Illuminate\Support\Facades\Http::sequence()
            ->push(['is_success'=>false,'data'=>[]])
            ->push(['is_success'=>true])
            ->push([],302,['Location'=>'https://other.example.com'])]);
        $client = new \Keloola\KeloolaSsoAuthorize\Integration\IntegrationClient('https://app.example.com', self::SECRET, '123');
        for ($i=0;$i<3;$i++) {
            try { $client->send('GET','permissions'); $this->fail('Expected malformed response rejection'); }
            catch (\Keloola\KeloolaSsoAuthorize\Integration\IntegrationException $e) { $this->assertSame(502,$e->status); }
        }
        \Illuminate\Support\Facades\Http::assertSentCount(3);
    }

    public function test_native_handler_class_is_resolved_from_configuration(): void
    {
        config(['keloolauthorize.integration.handler'=>TestNativeHandler::class]);
        $this->signed('POST','/organizations',$this->orgPayload())->assertOk()->assertJsonPath('data.native',true);
        $this->assertDatabaseCount('keloola_sso_organizations',0);
    }

}


class TestNativeHandler implements IntegrationHandler
{
    public function handle(string $operation, array $context, array $payload): array
    {
        return ['native'=>true,'operation'=>$operation];
    }
}

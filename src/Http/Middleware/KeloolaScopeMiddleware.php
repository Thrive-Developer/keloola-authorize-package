<?php

namespace Keloola\KeloolaSsoAuthorize\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class KeloolaScopeMiddleware
{
    /**
     * Checks BOTH scope and the user's current permission with SSO, without cache.
     * Resource controllers must still constrain queries to oauth_access.organization_id.
     */
    public function handle(Request $request, Closure $next, string $scope, string $permission)
    {
        // Request input such as sso_user, token_value, or oauth_access is never trusted.
        $request->attributes->remove('oauth_access');
        $token = $request->bearerToken() ?? $request->cookie('token');
        if (! $token) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }
        $host = config('keloolauthorize.keloola_auth_sso_host');
        $appId = config('keloolauthorize.keloola_auth_app_id');
        if (! $host || ! $appId || ! $scope || ! $permission) {
            return response()->json(['message' => 'OAuth scope validation is not configured.'], 503);
        }
        try {
            $response = Http::acceptJson()->withToken($token)->connectTimeout(3)->timeout(10)
                ->post(rtrim($host, '/').'/api/oauth/access/check', [
                    'app_id' => $appId, 'scope' => $scope, 'permission' => $permission,
                ]);
        } catch (\Throwable $exception) {
            return response()->json(['message' => 'Authorization service unavailable.'], 503);
        }
        if (! $response->successful()) {
            $status = in_array($response->status(), [401, 403], true) ? $response->status() : 503;

            return response()->json(['message' => $status === 401 ? 'Unauthenticated.' : ($status === 403 ? 'Access denied.' : 'Authorization service unavailable.')], $status);
        }
        $access = $response->json('data');
        if (! is_array($access) || empty($access['user_id']) || empty($access['organization_id'])
            || (string) ($access['app_id'] ?? '') !== (string) $appId
            || ($access['scope'] ?? null) !== $scope || ($access['permission'] ?? null) !== $permission) {
            return response()->json(['message' => 'Invalid authorization response.'], 503);
        }
        // Also reject explicit organization selectors. Never let a caller switch the
        // organization validated by SSO using a body, query, or route parameter.
        foreach ([$request->input('organization_id'), $request->route('organization_id')] as $organizationId) {
            if ($organizationId !== null && (! is_string($organizationId) || $organizationId !== $access['organization_id'])) {
                return response()->json(['message' => 'Organization access denied.'], 403);
            }
        }
        $request->attributes->set('oauth_access', $access);

        return $next($request);
    }
}

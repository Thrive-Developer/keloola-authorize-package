<?php

namespace Keloola\KeloolaSsoAuthorize\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Keloola\KeloolaSsoAuthorize\Integration\Signature;

class AuthenticateSsoIntegration
{
    public function handle(Request $request, Closure $next)
    {
        $config = config('keloolauthorize.integration', []);
        if (!($config['enabled'] ?? false)) {
            return $this->error('integration_disabled', 'Integration is disabled.', 404);
        }
        $secret = (string) ($config['secret'] ?? '');
        $appId = (string) config('keloolauthorize.keloola_auth_app_id');
        $skew = (int) ($config['clock_skew_seconds'] ?? 300);
        if (strlen($secret) < 32 || $appId === '' || $skew < 1 || $skew > 900) {
            return $this->error('integration_unavailable', 'Integration authentication is not configured.', 503);
        }
        $timestamp = (string) $request->header('X-Keloola-Timestamp', '');
        $nonce = (string) $request->header('X-Keloola-Nonce', '');
        $target = (string) $request->header('X-Keloola-App-Id', '');
        $signature = (string) $request->header('X-Keloola-Signature', '');
        if (!preg_match('/^[0-9]{1,12}$/D', $timestamp)
            || abs(time() - (int) $timestamp) > $skew
            || !preg_match('/^[a-zA-Z0-9_-]{16,128}$/D', $nonce)
            || !hash_equals($appId, $target)) {
            return $this->error('invalid_signature', 'Invalid integration credentials.', 401);
        }
        // Sign the raw URI path/query and body. Do not use Laravel's sorted query.
        $uri = $request->getRequestUri();
        $path = explode('?', $uri, 2)[0];
        $query = explode('?', $uri, 2)[1] ?? '';
        $expected = Signature::sign($secret, $timestamp, $request->method(), $path, $query, $request->getContent(), $appId, $nonce);
        if (!hash_equals($expected, $signature)) {
            return $this->error('invalid_signature', 'Invalid integration credentials.', 401);
        }
        $store = $config['cache_store'] ?? config('cache.default');
        if (!app()->environment('testing') && in_array(config('cache.stores.'.$store.'.driver'), ['array', 'null'], true)) {
            return $this->error('integration_unavailable', 'A persistent replay-protection cache is required.', 503);
        }
        try {
            $key = 'keloola:sso:nonce:'.hash('sha256', $appId.':'.$nonce);
            // Cover the complete acceptance window, including future timestamps.
            $fresh = Cache::store($config['cache_store'] ?? null)->add($key, true, 2 * $skew + 1);
        } catch (\Throwable $e) {
            return $this->error('integration_unavailable', 'Replay protection is unavailable.', 503);
        }
        if (!$fresh) {
            return $this->error('replayed_request', 'Request nonce has already been used.', 409);
        }
        return $next($request);
    }

    private function error(string $code, string $message, int $status)
    {
        return response()->json(['is_success' => false, 'error' => $code, 'message' => $message], $status)
            ->header('Cache-Control', 'no-store');
    }
}

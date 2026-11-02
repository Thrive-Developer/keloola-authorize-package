<?php

namespace Keloola\KeloolaSsoAuthorize\Integration;

use Illuminate\Support\Facades\Http;

/** Server-side client. Keep the per-app secret out of frontend configuration. */
class IntegrationClient
{
    public function __construct(private string $baseUrl, private string $secret, private string $appId, private string $prefix = '/api/integrations/sso')
    {
        $parts = parse_url($baseUrl);
        if (!$parts || !isset($parts['host']) || !in_array($parts['scheme'] ?? '', ['http', 'https'], true)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || strlen($secret) < 32 || $appId === '') {
            throw new \InvalidArgumentException('A valid base URL, app ID and secret of at least 32 characters are required.');
        }
    }

    /** Send a relative operation path, e.g. organizations/{uuid}/members.
     * No automatic retries: callers must reconcile business state and send a
     * fresh nonce when retrying an idempotent operation.
     */
    public function send(string $method, string $path, array $payload = []): array
    {
        $method = strtoupper($method);
        if (!in_array($method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], true)
            || preg_match('/[?#\\\\]/', $path) || str_contains($path, '..') || str_contains($path, '://')) {
            throw new \InvalidArgumentException('Invalid integration method or relative path.');
        }
        if (in_array($method, ['GET', 'DELETE'], true) && $payload !== []) {
            throw new \InvalidArgumentException('GET and DELETE operations do not accept a body.');
        }
        $url = rtrim($this->baseUrl, '/').'/'.trim($this->prefix, '/').'/'.ltrim($path, '/');
        $body = in_array($method, ['GET', 'DELETE'], true) ? '' : json_encode((object) $payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $timestamp = (string) time();
        $nonce = bin2hex(random_bytes(16));
        $signature = Signature::sign($this->secret, $timestamp, $method, parse_url($url, PHP_URL_PATH), '', $body, $this->appId, $nonce);
        $response = Http::acceptJson()->timeout(15)->connectTimeout(5)->withoutRedirecting()->withHeaders([
            'X-Keloola-Timestamp' => $timestamp, 'X-Keloola-App-Id' => $this->appId,
            'X-Keloola-Nonce' => $nonce, 'X-Keloola-Signature' => $signature,
        ])->withBody($body, 'application/json')->send($method, $url);
        if (!$response->successful() || $response->json('is_success') !== true || !is_array($response->json('data'))) {
            throw new IntegrationException('remote_integration_failed', 'Application integration returned an unsuccessful or malformed response.', $response->status() >= 400 && $response->status() <= 599 ? $response->status() : 502);
        }
        return $response->json('data');
    }
}

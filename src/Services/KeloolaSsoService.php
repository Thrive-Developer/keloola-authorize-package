<?php

namespace Keloola\KeloolaSsoAuthorize\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Response;
use Keloola\KeloolaSsoAuthorize\Support\PayloadNormalizer;

class KeloolaSsoService 
{
    public function user(string $token)
    {   
        if(empty(config('keloolauthorize.keloola_auth_sso_host'))) return (object) [
            'message' => __('keloolauthorize::message.config_host_require'),
            'status'  => Response::HTTP_FORBIDDEN,
            'data'    => []
        ];

        $cache_key = 'keloolauhtorize.'.$token;
        if(Cache::has($cache_key)) {
            $cached = Cache::get($cache_key);

            if(!PayloadNormalizer::containsIncompleteObject($cached)) {
                return PayloadNormalizer::toObject($cached);
            }

            Cache::forget($cache_key);
        }


        $response = Http::withToken($token)->post(config('keloolauthorize.keloola_auth_sso_host').'/api/jwt/user',[
            'token' => $token,
        ]);

        if($response->clientError()) {
            Cache::forget($cache_key);
            $message = $response->json('message');
            Log::error('Failed get user sso '.$message);

            return (object) [
                'message' => $message,
                'status'  => $response->status(),
                'data'    => []
            ];
        }

        if($response->serverError()) {
            Cache::forget($cache_key);
            Log::error('Failed get user sso '.$response->throw());
            return (object) [
                'message' => __('keloolauthorize::message.error_500'),
                'status'  => $response->status(),
                'data'    => []
            ];
        }

        $expired = config('keloolauthorize.keloola_auth_cache_expired') ?? 3600;

        $payload = [
            'status' => $response->status(),
            'message' => $response->json('message'),
            'data'    => PayloadNormalizer::toArray($response->json('data', []))
        ];

        Cache::put($cache_key, $payload, $expired);

        return PayloadNormalizer::toObject($payload);
    }
}

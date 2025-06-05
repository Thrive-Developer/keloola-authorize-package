<?php

namespace Keloola\KeloolaSsoAuthorize\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class KeloolaAccountingService
{
    public function user(string $token, string $sso_id)
    {   
        if(empty(config('keloolauthorize.keloola_auth_accounting_host'))) return (object) [
            'message' => __('keloolauthorize::message.config_host_accunting_require'),
            'status'  => Response::HTTP_FORBIDDEN,
            'data'    => []
        ];

        if((config('keloolauthorize.keloola_auth_accounting_encrypt') ?? false) == true) return (object) [
            'message' => __('keloolauthorize::message.config_host_accunting_app_key'),
            'status'  => Response::HTTP_FORBIDDEN,
            'data'    => []
        ];

        if(Cache::has($sso_id)) {
            return Cache::get($sso_id);
        }


        $response = Http::withToken($token)->post(config('keloolauthorize.keloola_auth_accounting_host').'/api/sso/accounting/user',[
            'sso_id' => $sso_id
        ]);

        if($response->clientError()) {
            Cache::forget($sso_id);
            Log::error('Failed get user accounting '.$response->object()?->message);
            return (object) [
                'message' => $response->object()?->message,
                'status'  => $response->status(),
                'data'    => []
            ];
        }

        if($response->serverError()) {
            Cache::forget($sso_id);
            Log::error('Failed get user accounting '.$response->throw());
            return (object) [
                'message' => __('keloolauthorize::message.error_500'),
                'status'  => $response->status(),
                'data'    => []
            ];
        }


        $expired = config('keloolauthorize.keloola_auth_cache_expired') ?? 3600;

        
        return Cache::remember($sso_id, $expired, function () use ($response) {
            return (object) [
                'status' => $response->status(),
                'message' => $response->object()?->message,
                'data'    => $response->object()?->data
            ];
        });
    }
}
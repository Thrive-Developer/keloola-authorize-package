<?php

namespace Keloola\KeloolaSsoAuthorize\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Encryption\Encrypter;
use Symfony\Component\HttpFoundation\Response;
use Keloola\KeloolaSsoAuthorize\Services\KeloolaSsoService;

class KeloolaAccountingDecryptMiddleware 
{
    public function handle(Request $request, Closure $next)
    {
        
        if (in_array($request->method(), ['GET', 'HEAD'])) {
            return $next($request);
        }

        $content_type = $request->header('Content-Type');
        $include_type = ['text/plain', 'application/json'];

        $convertToArray = function ($values) use (&$convertToArray) {
            $result = [];
            foreach ($values as $key => $value) {
                if (is_object($value)) {
                    $result[$key] = $convertToArray((array) $value);
                } else {
                    $result[$key] = $value;
                }
            }

            return $result;
        };

        if (in_array($content_type, $include_type) && config('keloolauthorize.keloola_auth_accounting_encrypt') === true) {
            try {
                $key = base64_decode(config('keloolauthorize.keloola_auth_accounting_app_key'));
                $encrypter = new Encrypter($key, 'AES-256-CBC');
                $content = (array) json_decode($encrypter->decryptString($request->getContent()));
                $content = $convertToArray($content);
                $request->merge($content);
            } catch (\Illuminate\Contracts\Encryption\DecryptException $e) {
                return response()->json(['message' => __('message.payload_error')], 422);
            }
        }
    }
}
<?php

namespace Keloola\KeloolaSsoAuthorize\Http\Middleware;

use Psr\Http\Message\ResponseInterface;
use Illuminate\Encryption\Encrypter;
use GuzzleHttp\Psr7\Utils;

class KeloolaAccountingResponse 
{
    public static function handle(): callable
    {
        $base64Key  = config('keloolauthorize.keloola_auth_accounting_app_key');
        $key        = base64_decode($base64Key);
        $encrypter  = new Encrypter($key, 'AES-256-CBC');

        return function (ResponseInterface $response) use ($encrypter) {
            if(config('keloolauthorize.keloola_auth_accounting_encrypt') == true) {
                $original = json_decode((string) $response->getBody(), false);
              
                $newJson =  [
                    'message' =>  $original->message,
                    'data'    => json_decode($encrypter->decryptString($original->data))
                ];

                // Encode back to JSON
                $newBody = json_encode($newJson);
                return $response->withBody(Utils::streamFor($newBody));
            };
            
            return $response;
        };
    }
}
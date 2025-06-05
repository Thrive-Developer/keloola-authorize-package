<?php

namespace App\Http\Middleware;
use Psr\Http\Message\RequestInterface;
use Illuminate\Encryption\Encrypter;
use GuzzleHttp\Psr7\Utils;

class AccountingRequest
{
    public static function handle(): callable
    {
        $base64Key  = config('keloolauthorize.keloola_auth_accounting_app_key');
        $key        = base64_decode($base64Key);
        $encrypter  = new Encrypter($key, 'AES-256-CBC');

        return function (RequestInterface $request) use ($encrypter) {
            if(config('keloolauthorize.keloola_auth_accounting_encrypt') == true) {
               
                $body   = (string) $request->getBody();
                $parsed = $encrypter->encryptString($body);
                
                return $request->withBody(Utils::streamFor($parsed));
            };
            
            return $request;
        };
    }
}
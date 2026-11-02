<?php

/*
 * You can place your custom package configuration in here.
 */
return [
    // Disabled by default: existing installations expose no new routes.
    'integration' => [
        'enabled' => env('KELOOLA_SSO_INTEGRATION_ENABLED', false),
        'prefix' => 'api/integrations/sso',
        'secret' => env('KELOOLA_SSO_INTEGRATION_SECRET'),
        'clock_skew_seconds' => 300,
        'cache_store' => env('KELOOLA_SSO_INTEGRATION_CACHE_STORE'),
        'handler' => \Keloola\KeloolaSsoAuthorize\Integration\DatabaseIntegrationHandler::class,
        // Stable numeric IDs. Add the app's actual permission catalog here.
        'permissions' => [],
    ],


    'keloola_auth_app_id'               => env('KELOOLA_AUTH_APP_ID',''),
    'keloola_auth_sso_host'             => env('KELOOLA_AUTH_SSO_HOST','https://accounts.keloola.xyz'),
    'keloola_auth_cache_expired'        => env('KELOOLA_AUTH_CACHE_EXPIRED',3600), // Per secound
    'keloola_auth_accounting_host'      => env('KELOOLA_AUTH_ACCOUNTING_HOST','https://api-accounting.keloola.xyz'),
    'keloola_auth_accounting_encrypt'   => env('KELOOLA_AUTH_ACCOUNTING_ENCRYPT',false),
    'keloola_auth_accounting_app_key'   => env('KELOOLA_AUTH_ACCOUNTING_APP_KEY',''),
];
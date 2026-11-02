# Package Keloola SSO Authorize

[![Latest Version on Packagist](https://img.shields.io/packagist/v/keloola/keloola-service-auth.svg?style=flat-square)](https://packagist.org/packages/keloola/keloola-sso-authorize)
[![Total Downloads](https://img.shields.io/packagist/dt/keloola/keloola-service-auth.svg?style=flat-square)](https://packagist.org/packages/keloola/keloola-sso-authorize)
![GitHub Actions](https://github.com/Thrive-Developer/keloola-authorize-package/actions/workflows/main.yml/badge.svg)

## Installation

You can install the package via composer:

```bash
composer require keloola/keloola-sso-authorize
```

## Usage

Publish config :

```php
php artisan vendor:publish --tag=keloola-auth-config
```

```php
KELOOLA_AUTH_APP_ID=xxx //app id
KELOOLA_AUTH_SSO_HOST=https://accounts.keloola.xyz
KELOOLA_AUTH_CACHE_EXPIRED=60
KELOOLA_AUTH_ACCOUNTING_HOST=host api accounting
KELOOLA_AUTH_ACCOUNTING_ENCRYPT=false //jika accounting tidak di encrypt response dan request nya
KELOOLA_AUTH_ACCOUNTING_APP_KEY=xxx //jika encrypt true ini require dengan app key accounting
```

```php

Setup Middleware Sso and Accounting

You can use on global middlware 

Location : project-laravel/bootstrap/app.php

use Keloola\KeloolaSsoAuthorize\Http\Middleware\KeloolaAuthMiddleware;

->withMiddleware(function (Middleware $middleware) {
       $middleware->api(append: [
            KeloolaAuthMiddleware::class,
        ]);
})

If you want to connect the keloola accounting , you must be use KeloolaAuthAccountingMiddleware

use Keloola\KeloolaSsoAuthorize\Http\Middleware\KeloolaAuthAccountingMiddleware;

Add before KeloolaAuthMiddleware::class


->withMiddleware(function (Middleware $middleware) {
       $middleware->api(append: [
            KeloolaAuthMiddleware::class,
            KeloolaAuthAccountingMiddleware::class, <----- disini
        ]);
})

or via routes

Route::middleware([KeloolaAuthMiddleware::class, KeloolaAuthAccountingMiddleware::class])
->group(function () {
    Route::get('/example', function () {
        return 'OK';
    });
});


```

## Consume Data

```php

Sso User Data 
$request->sso_user;

Accunting Data
$request->accounting_user

```

### Testing

```bash
composer test
```

### Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information what has changed recently.

## Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

### Security

If you discover any security related issues, please email roni.jakarianto@thrive.co.id instead of using the issue tracker.

## Credits

-   [Roni Jakarianto](https://github.com/keloola)
-   [All Contributors](../../contributors)
## OAuth scope and permission middleware

For delegated integration endpoints, use the new `keloola.scope` middleware:

```php
Route::post('/integrations/sales-orders', [SalesOrderController::class, 'store'])
    ->middleware('keloola.scope:accounting:sales-orders:write,sales_order.create');
```

Configure `KELOOLA_AUTH_SSO_HOST` and `KELOOLA_AUTH_APP_ID` for the resource app.
The middleware calls SSO `POST /api/oauth/access/check` on every request (no cache).
SSO verifies the token, current consent, active scope assignment, resource app,
organization membership, and the user's permission for that operation.

Use verified context from `$request->attributes->get('oauth_access')` for `user_id`
and `organization_id`. Always filter resource queries by that organization and
apply your local data policies/business/subscription checks. Request input named
`oauth_access` or `sso_user` is not verified context. Explicit organization_id in
input or route must match the token organization.

This middleware does not log in a local Laravel user. Use it on dedicated
integration routes; do not combine with legacy `KeloolaAuthMiddleware`, which
serves browser sessions and rejects scoped OAuth profile responses. Missing or
invalid credentials return 401, insufficient access 403, and unavailable or
malformed SSO responses 503. No handler executes on failure.

Requires the SSO scope-catalog migration and `/api/oauth/access/check` endpoint.
Legacy tokens without recorded scope consent cannot access these routes.


## Internal application provisioning

Modul integrasi opt-in menyediakan route organisasi, anggota, role, permission,
dan mirror undangan dengan autentikasi HMAC v1 dan penolakan replay.
Gunakan direktori database bawaan atau implementasikan `IntegrationHandler`
untuk model bisnis aplikasi. Modul tidak otomatis menulis tabel native aplikasi.

Lihat [panduan konfigurasi dan integrasi](docs/integration.md) dan
[kontrak OpenAPI](docs/integration.openapi.json). Instalasi lama tetap tidak
mengekspos route baru sampai `KELOOLA_SSO_INTEGRATION_ENABLED=true`.

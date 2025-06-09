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
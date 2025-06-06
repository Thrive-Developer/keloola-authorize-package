# Very short description of the package

[![Latest Version on Packagist](https://img.shields.io/packagist/v/keloola/keloola-service-auth.svg?style=flat-square)](https://packagist.org/packages/keloola/keloola-service-auth)
[![Total Downloads](https://img.shields.io/packagist/dt/keloola/keloola-service-auth.svg?style=flat-square)](https://packagist.org/packages/keloola/keloola-service-auth)
![GitHub Actions](https://github.com/keloola/keloola-service-auth/actions/workflows/main.yml/badge.svg)

This is where your description should go. Try and limit it to a paragraph or two, and maybe throw in a mention of what PSRs you support to avoid any confusion with users and contributors.

## Installation

You can install the package via composer:

```bash
composer require keloola/keloola-sso-authorize
```

## Usage

```php
php artisan vendor:publish --tag=keloola-auth-config
php artisan vendor:publish --tag=keloola-auth-lang
```

```env
KELOOLA_AUTH_APP_ID=xxx //app id
KELOOLA_AUTH_SSO_HOST=https://accounts.keloola.xyz
KELOOLA_AUTH_CACHE_EXPIRED=60
KELOOLA_AUTH_ACCOUNTING_HOST=host api accounting
KELOOLA_AUTH_ACCOUNTING_ENCRYPT=false //jika accounting tidak di encrypt response dan request nya
KELOOLA_AUTH_ACCOUNTING_APP_KEY=xxx //jika encrypt true ini require dengan app key accounting
```

```php
Tambahkan middleware di bootstrap app, sebagai berikut khususnya di routes/api.php

project-laravel/bootstrap/app.php

use Keloola\KeloolaSsoAuthorize\Http\Middleware\KeloolaAuthMiddleware;

->withMiddleware(function (Middleware $middleware) {
       $middleware->api(append: [
            KeloolaAuthMiddleware::class,
        ]);
})

Tambahkan middleware accounting jika app konek dengan accounting
use Keloola\KeloolaSsoAuthorize\Http\Middleware\KeloolaAuthAccountingMiddleware;

Tambahkan ke middleware sebelum KeloolaAuthMiddleware::class


->withMiddleware(function (Middleware $middleware) {
       $middleware->api(append: [
            KeloolaAuthMiddleware::class,
            KeloolaAuthAccountingMiddleware::class, <----- disini
        ]);
})

```

```Consume Data

Sso User Data 
$request->user;

Accunting Data
$request->user_accoounting

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
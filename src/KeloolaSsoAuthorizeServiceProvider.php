<?php

namespace Keloola\KeloolaSsoAuthorize;

use Illuminate\Support\ServiceProvider;

class KeloolaSsoAuthorizeServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap the application services.
     */
    public function boot()
    {
        if (config('keloolauthorize.integration.enabled', false)) {
            $this->loadRoutesFrom(__DIR__.'/../routes/integration.php');
        }

        $this->app['router']->aliasMiddleware('keloola.scope', \Keloola\KeloolaSsoAuthorize\Http\Middleware\KeloolaScopeMiddleware::class);
        /*
         * Optional methods to load your package assets
         */
        $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'keloolauthorize');
        // $this->loadViewsFrom(__DIR__.'/../resources/views', 'keloola-service-auth');
        // $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        // $this->loadRoutesFrom(__DIR__.'/routes.php');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'keloola-integration-migrations');
            $this->publishes([
                __DIR__.'/../config/config.php' => config_path('keloolauthorize.php'),
            ], 'keloola-auth-config');
            // Publishing the views.
            /*$this->publishes([
                __DIR__.'/../resources/views' => resource_path('views/vendor/keloola-service-auth'),
            ], 'views');*/

            // Publishing assets.
            /*$this->publishes([
                __DIR__.'/../resources/assets' => public_path('vendor/keloola-service-auth'),
            ], 'assets');*/

            // Publishing the translation files.
            // $this->publishes([
            //     __DIR__.'/../resources/lang' => resource_path('lang/vendor/keloolauthorize'),
            // ], 'keloola-auth-lang');

            // Registering package commands.
            // $this->commands([]);
        }
    }

    /**
     * Register the application services.
     */
    public function register()
    {
        // Automatically apply the package configuration
        $this->mergeConfigFrom(__DIR__.'/../config/config.php', 'keloolauthorize');

        $this->app->bind(\Keloola\KeloolaSsoAuthorize\Integration\IntegrationHandler::class, function ($app) {
            $class = config('keloolauthorize.integration.handler', \Keloola\KeloolaSsoAuthorize\Integration\DatabaseIntegrationHandler::class);
            $handler = $app->make($class);
            if (!$handler instanceof \Keloola\KeloolaSsoAuthorize\Integration\IntegrationHandler) {
                throw new \LogicException('Configured integration handler must implement IntegrationHandler.');
            }
            return $handler;
        });

        // Register the main class to use with the facade
        $this->app->singleton('keloolauthorize', function () {
            return new KeloolaSsoAuthorize;
        });
    }
}

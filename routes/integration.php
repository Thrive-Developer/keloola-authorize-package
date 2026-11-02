<?php

use Illuminate\Support\Facades\Route;
use Keloola\KeloolaSsoAuthorize\Http\Controllers\SsoIntegrationController;
use Keloola\KeloolaSsoAuthorize\Http\Middleware\AuthenticateSsoIntegration;

Route::prefix(trim(config('keloolauthorize.integration.prefix', 'api/integrations/sso'), '/'))
    ->middleware([AuthenticateSsoIntegration::class])
    ->group(function () {
        $routes = [
            ['POST', 'organizations', 'organizations.provision'],
            ['PATCH', 'organizations/{organizationId}', 'organizations.update'],
            ['POST', 'organizations/{organizationId}/members', 'members.store'],
            ['PUT', 'organizations/{organizationId}/members/{userId}/role', 'members.role'],
            ['DELETE', 'organizations/{organizationId}/members/{userId}', 'members.destroy'],
            ['GET', 'permissions', 'permissions.index'],
            ['GET', 'organizations/{organizationId}/roles', 'roles.index'],
            ['POST', 'organizations/{organizationId}/roles', 'roles.store'],
            ['PUT', 'organizations/{organizationId}/roles/{roleId}', 'roles.update'],
            ['DELETE', 'organizations/{organizationId}/roles/{roleId}', 'roles.destroy'],
            ['POST', 'invitations', 'invitations.store'],
            ['DELETE', 'invitations/{invitationId}', 'invitations.destroy'],
        ];
        foreach ($routes as [$method, $uri, $operation]) {
            Route::match([$method], $uri, SsoIntegrationController::class)
                ->defaults('operation', $operation)->name('keloola.integration.'.$operation);
        }
    });

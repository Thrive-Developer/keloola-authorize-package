<?php

namespace Keloola\KeloolaSsoAuthorize\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Keloola\KeloolaSsoAuthorize\Services\KeloolaSsoService;

class KeloolaAuthMiddleware 
{

    protected $ssoService;

    public function __construct(KeloolaSsoService $ssoService)
    {
        $this->ssoService = $ssoService;
    }

    public function handle(Request $request, Closure $next)
    {
        $token = $request->cookie('token');
        
        if(!empty($request->header('Authorization'))) {
            $token = trim(str_replace('Bearer','',$request->header('Authorization')));
        }
        
        if(empty($token)) return $this->unauthorizedResponse(__('keloolauthorize::message.unauthorized'));

        $sso_data          = (object) $this->ssoService->user($token);
        if($sso_data->status != 200) {
            if($sso_data->status == 401) return $this->unauthorizedResponse($sso_data->message);
            return response()->json(['message' => $sso_data->message], $sso_data->status);
        }

        // Delegated OAuth tokens use explicit per-operation scope + permission
        // checks, never the unrestricted browser-session middleware.
        if (isset($sso_data->data->scope)) {
            return response()->json(['message' => 'Use a scope-protected integration endpoint.'], 403);
        }

        $application      = null;

        if(is_array($sso_data->data->applications)) {
            $applications = $sso_data->data->applications;
            $application   = current(array_filter($applications, fn ($filter) => $filter->id == config('keloolauthorize.keloola_auth_app_id')));
        
            //Check Null Companies
            if(empty($application)) return $this->unauthorizedResponse(__('keloolauthorize::message.empty_application'));
            if(empty($application->organizations)) return $this->unauthorizedResponse(__('keloolauthorize::message.empty_organization'));

            $sso_company = current(array_filter($application->organizations, fn ($filter) => $filter->id == $application->default_organization));

            //Check Subscription Actived
            $subscribtion = current($sso_company->subscriptions) ?? $sso_company?->subscriptions;
            if(empty($subscribtion)) return $this->unauthorizedResponse(__('keloolauthorize::message.empty_subscribtion'));
            if($subscribtion->status != 'active') return $this->unauthorizedResponse(__('keloolauthorize::message.expired_subscribtion'), '/dashboard?renew='.rawurlencode((string) $subscribtion?->sub_id));
        } 

        if(empty($sso_data->data->applications)) return $this->unauthorizedResponse(__('keloolauthorize::message.empty_applications'));

        $request->merge(['token'=> $token, 'sso_user' => (array) $sso_data->data, 'application' => (array) $application]);

        return $next($request);
    }
    protected function unauthorizedResponse($message, string $path = '/dashboard')
    {
        return response()->json([
            'message' => $message,
            'redirect' => rtrim(config('keloolauthorize.keloola_auth_sso_host'), '/').$path,
        ], 401);
    }
}

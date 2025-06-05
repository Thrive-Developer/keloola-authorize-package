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
        
        if(empty($token)) return response()->json(['message' => __('keloolauthorize::message.unauthorized')], 401);

        $sso_data          = (object) $this->ssoService->user($token);
        if($sso_data->status != 200) return response()->json(['message' => $sso_data->message], $sso_data->status);

        if(is_array($sso_data->data->applications)) {
            $applications = $sso_data->data->applications;
            $application   = current(array_filter($applications, fn ($filter) => $filter->id == config('keloolauthorize.keloola_auth_app_id')));
        
            //Check Null Companies
            if(empty($application)) return response()->json(['message' => __('keloolauthorize::message.empty_application')], 401);
            if(empty($application->organizations)) return response()->json(['message' => __('keloolauthorize::message.empty_organization')], 401);

            $sso_company = current(array_filter($application->organizations, fn ($filter) => $filter->id == $application->default_organization));

            //Check Subscription Actived
            $subscribtion = current($sso_company->subscriptions) ?? $sso_company?->subscriptions;
            if(empty($subscribtion)) return response()->json(['message' => __('keloolauthorize::message.empty_subscribtion')], 401);
            if($subscribtion->status != 'active') return response()->json(['message' => __('keloolauthorize::message.expired_subscribtion')], 401);
        } 

        if(empty($sso_data->data->applications)) return response()->json(['message' => __('keloolauthorize::message.empty_applications')], 401);

        $request->merge(['token'=> $token, 'user' => (array) $sso_data->data]);

        return $next($request);
    }
}
<?php

namespace Keloola\KeloolaSsoAuthorize\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Keloola\KeloolaSsoAuthorize\Services\KeloolaAccountingService;

class KeloolaAuthAccountingMiddleware 
{

    protected $service;

    public function __construct(KeloolaAccountingService $service)
    {
        $this->service = $service;
    }

    public function handle(Request $request, Closure $next)
    {
        if(empty($request->user))  return response()->json(['message' => __('keloolauthorize::message.unauthorized')], 401);

        $user            = (object) $request->user;
        $accounting_data = $this->service->user($request->token, $user->email);
        if($accounting_data->status != 200) return response()->json(['message' => $accounting_data->message], $accounting_data->status);

        $request->merge([
            'user_accoounting'  => (array) $accounting_data->data,
        ]);

        return $next($request);
    }
}
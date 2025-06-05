<?php

namespace Keloola\KeloolaSsoAuthorize;

use Illuminate\Support\Facades\Facade;

/**
 * @see \Keloola\KeloolaServiceAuth\Skeleton\SkeletonClass
 */
class KeloolaSsoAuthorizeFacade extends Facade
{
    /**
     * Get the registered name of the component.
     *
     * @return string
     */
    protected static function getFacadeAccessor()
    {
        return 'keloola-service-auth';
    }
}

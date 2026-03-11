<?php

use App\Services\UtilityService;
use App\Services\CacheService;

if (!function_exists('SE_utility')) {
    /**
     * Helper global pour accéder au UtilityService
     */
    function SE_utility(): UtilityService
    {
        return app(UtilityService::class);
    }
}

if (!function_exists('SE_cache')) {
    /**
     * Helper global pour accéder au CacheService
     */
    function SE_cache(): CacheService
    {
        return app(CacheService::class);
    }
}

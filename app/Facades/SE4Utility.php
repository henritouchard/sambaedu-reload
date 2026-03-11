<?php

namespace App\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * Façade pour le UtilityService SE4
 * 
 * @method static bool openSession(array $config, string $login, string $passwd, string $newpasswd = "")
 * @method static void closeSession()
 * @method static bool isValidSession(array $config)
 * @method static string getRemoteIp()
 * @method static string renderMenu(array $config, string $module = "")
 * @method static void debugLog(string $message, array $context = [])
 * @method static string generateCsrfToken()
 * @method static bool verifyCsrfToken(string $token)
 * @method static string sanitizeInput(string $input)
 * @method static bool checkPermission(array $config, string $permission)
 */
class SE4Utility extends Facade
{
    protected static function getFacadeAccessor()
    {
        return 'se4.utility';
    }
}

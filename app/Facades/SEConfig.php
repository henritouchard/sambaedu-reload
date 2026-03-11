<?php

namespace App\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * Facade pour accéder à SambaEduConfig
 * 
 * @method static \App\Config\LdapConfig ldap()
 * @method static \App\Config\EstablishmentConfig establishment(?string $sessionEtab = null)
 * @method static \App\Config\NetworkConfig network()
 * @method static \App\Config\SecurityConfig security()
 * @method static \App\Config\ProxyConfig proxy()
 * @method static \App\Config\PasswordPolicyConfig passwordPolicy()
 * @method static \App\Config\CredentialsConfig credentials()
 * @method static \App\Config\LegacyConfigBridge legacy()
 * @method static mixed get(string $key, mixed $default = null)
 * @method static bool has(string $key)
 * @method static array all()
 * @method static void reload()
 * 
 * @see \App\Config\SambaEduConfig
 */
class SEConfig extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \App\Config\SambaEduConfig::class;
    }
}

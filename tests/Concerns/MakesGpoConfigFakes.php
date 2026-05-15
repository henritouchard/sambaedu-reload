<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Config\LdapConfig;
use App\Config\PasswordPolicyConfig;
use App\Config\SambaEduConfig;
use Mockery;

/**
 * Helpers pour les tests Feature/Unit qui ont besoin d'un faux
 * {@see SambaEduConfig} avec un {@see LdapConfig} / {@see PasswordPolicyConfig}
 * minimal.
 *
 * Pourquoi un trait : `LdapConfig` et `PasswordPolicyConfig` sont
 * `final readonly` — Mockery ne peut pas les mocker (impossible de générer
 * une sous-classe d'une `final`). On instancie donc des objets réels avec
 * des valeurs par défaut surchargeables via $overrides.
 *
 * Ne mocke que `SambaEduConfig` (non-final) qui expose `ldap()` /
 * `passwordPolicy()` retournant les vraies instances.
 */
trait MakesGpoConfigFakes
{
    /**
     * Instancie un {@see LdapConfig} réel avec des valeurs par défaut adaptées
     * aux tests. Toutes les propriétés sont surchargeables via $overrides
     * (named arguments du constructeur).
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function makeLdapConfig(array $overrides = []): LdapConfig
    {
        $defaults = [
            'url' => 'ldaps://test.local',
            'port' => 636,
            'baseDn' => 'dc=example,dc=local',
            'adminName' => 'admin',
            'adminPassword' => 'pwd',
            'domain' => 'example.local',
            'sambaDomain' => 'EXAMPLE',
            'peopleRdn' => 'ou=Utilisateurs',
            'groupsRdn' => 'ou=Groupes',
            'computersRdn' => 'ou=Computers',
            'parcsRdn' => 'ou=Parcs',
            'classesRdn' => 'ou=Classes',
            'equipesRdn' => 'ou=Equipes',
            'matieresRdn' => 'ou=Matieres',
            'coursRdn' => 'ou=Cours',
            'projetsRdn' => 'ou=Projets',
            'otherGroupsRdn' => 'ou=AutresGroupes',
            'delegationsRdn' => 'ou=Delegations',
            'equipementsRdn' => 'ou=Equipements',
            'rightsRdn' => 'ou=Droits',
            'trashRdn' => 'ou=Trash',
            'etablissementsRdn' => 'ou=Etablissements',
            'adminRdn' => 'cn=Administrator',
            'serverIp' => null,
            'etabServerIp' => '127.0.0.1',
            'strictLocalAd' => false,
        ];

        return new LdapConfig(...array_merge($defaults, $overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function makePasswordPolicyConfig(array $overrides = []): PasswordPolicyConfig
    {
        $defaults = [
            'minLength' => 8,
        ];

        return new PasswordPolicyConfig(...array_merge($defaults, $overrides));
    }

    /**
     * Bind dans le container un {@see SambaEduConfig} mocké qui renvoie
     * les valeurs de $kv via `get()`/`has()`/`all()`, et qui expose un
     * {@see LdapConfig} et un {@see PasswordPolicyConfig} par défaut.
     *
     * Surcharge possible via $ldapOverrides / $policyOverrides. La clé
     * `ldap_base_dn` de $kv alimente automatiquement `baseDn` du LdapConfig
     * sauf si overridée explicitement.
     *
     * @param  array<string, mixed>  $kv
     * @param  array<string, mixed>  $ldapOverrides
     * @param  array<string, mixed>  $policyOverrides
     */
    protected function bindFakeSambaEduConfig(
        array $kv = [],
        array $ldapOverrides = [],
        array $policyOverrides = [],
    ): SambaEduConfig {
        /** @var SambaEduConfig&\Mockery\MockInterface $mock */
        $mock = Mockery::mock(SambaEduConfig::class);
        $mock->shouldReceive('get')
            ->andReturnUsing(fn(string $key, mixed $default = null): mixed => $kv[$key] ?? $default);
        $mock->shouldReceive('has')
            ->andReturnUsing(fn(string $key) => array_key_exists($key, $kv));
        $mock->shouldReceive('all')->andReturn($kv);
        $mock->shouldReceive('reload')->andReturnNull();

        if (! array_key_exists('baseDn', $ldapOverrides) && isset($kv['ldap_base_dn'])) {
            $ldapOverrides['baseDn'] = $kv['ldap_base_dn'];
        }
        $mock->shouldReceive('ldap')->andReturn($this->makeLdapConfig($ldapOverrides));
        $mock->shouldReceive('passwordPolicy')->andReturn($this->makePasswordPolicyConfig($policyOverrides));

        $this->app->instance(SambaEduConfig::class, $mock);

        return $mock;
    }
}

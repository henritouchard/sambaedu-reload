<?php

declare(strict_types=1);

namespace App\Services\Gpo;

use LdapRecord\Container;
use RuntimeException;

/**
 * Écriture directe des attributs LDAP d'un Group Policy Container (GPC).
 *
 * Story 38.4 (AC1/AC2) — port natif de `modify_ad($config, $cn, 'gpo', $attrs)`
 * (legacy `gpo.inc.php:1032` / `ldap.inc.php` `_shim_gpo_modify_replace`).
 * Utilisé par {@see NativeGpoPublisher} (pose `versionNumber` +
 * `gPCMachineExtensionNames`, SANS quoi le startup.cmd ne s'exécute jamais) et
 * par {@see SysvolPolicyService} (bump `versionNumber` côté roaming).
 *
 * Écrit via LdapRecord (autorisé hors `app/Gpo/`). Le GPC est identifié par le
 * GUID `{...}` : son DN canonique est
 * `CN=<guid>,CN=Policies,CN=System,<ldap_base_dn>`.
 */
class GpcDirectory
{
    /**
     * DN canonique d'un GPC à partir de son GUID.
     *
     * @throws RuntimeException Si `ldap_base_dn` n'est pas configuré.
     */
    public function dnForGuid(string $guid): string
    {
        $baseDn = (string) config('sambaedu.ldap_base_dn', '');
        if ($baseDn === '') {
            throw new RuntimeException('ldap_base_dn non configuré — DN du GPC indéterminable.');
        }

        return sprintf('CN=%s,CN=Policies,CN=System,%s', $guid, $baseDn);
    }

    /**
     * Remplace (`mod_replace`) les attributs LDAP d'un GPC.
     *
     * @param  string  $guid   GUID `{...}` de la GPO.
     * @param  array<string,int|string>  $attributes  Ex.
     *         `['versionnumber' => 65537, 'gPCMachineExtensionNames' => '[...]']`.
     *
     * @throws RuntimeException Si l'écriture LDAP échoue.
     */
    public function setAttributes(string $guid, array $attributes): void
    {
        if ($attributes === []) {
            return;
        }

        $dn = $this->dnForGuid($guid);

        $connection = Container::getDefaultConnection();
        $ldap = $connection->getLdapConnection();

        // Normaliser en chaînes (LDAP est textuel). versionNumber est un entier
        // côté métier mais stocké en chaîne décimale dans l'AD.
        $payload = [];
        foreach ($attributes as $attr => $value) {
            $payload[$attr] = [(string) $value];
        }

        $ok = @$ldap->modReplace($dn, $payload);
        if ($ok === false) {
            throw new RuntimeException(sprintf(
                'Écriture LDAP des attributs GPC échouée pour %s : %s',
                $dn,
                (string) $ldap->getLastError(),
            ));
        }
    }
}

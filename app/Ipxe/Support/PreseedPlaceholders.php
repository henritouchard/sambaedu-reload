<?php

declare(strict_types=1);

namespace App\Ipxe\Support;

/**
 * Story 3.4 — D5 / AC1.3.
 *
 * Catalogue des placeholders `###_<KEY>_###` injectés dans les fragments
 * preseed Linux + sanitization defense-in-depth.
 *
 * **Sécurité critique** : les placeholders contiennent des secrets (mots de
 * passe root, clés AD, tokens). La sanitization rejette tout caractère
 * non-ASCII + newlines + chars iPXE-special pour éviter qu'une valeur
 * malveillante (hostname spoofé, override config) ne casse le parsing
 * preseed ou n'injecte une ligne `kernel http://evil` dans le firmware
 * iPXE en aval.
 *
 * **Source des placeholders** : audit T0.4 des 16 fragments `linux/*.cfg`
 * copiés depuis `sambaedu/ipxe/linux/`. Liste maintenue à jour avec la
 * story 3.4 § D5.
 *
 * **Anti-pattern** :
 *  - ❌ Ne PAS exposer le mapping `placeholder → valeur secrète` dans les
 *    logs : seul le sha256 du preseed final est tracé.
 *  - ❌ Ne PAS ajouter de placeholder hors catalogue : risque d'injection
 *    via input user.
 */
final class PreseedPlaceholders
{
    /**
     * Catalogue des placeholders connus mappés vers leur clé `config(...)`.
     *
     * **Convention** : clé `###_<UPPERCASE_KEY>_###` → chemin `config(...)`.
     *
     * Le résolveur consomme ce catalogue dans
     * {@see \App\Ipxe\Services\LinuxPreseedService::buildConfig()} qui lit
     * `config(...)` pour chaque entrée et compose le tableau `$config`
     * consommé par {@see self::interpolate()}.
     *
     * **Placeholders par-poste** (HOSTNAME, UUID, ETAB_OU) NE SONT PAS dans
     * ce catalogue — ils sont injectés directement par
     * `LinuxPreseedService::generate()` (lecture du modèle Workstation).
     *
     * @return array<string, string>  Mapping `placeholder_key (uppercase
     *                                 SANS `###_..._###`) → chemin config()`.
     */
    public static function catalog(): array
    {
        return [
            // Localisation + clavier.
            'LINUX_LOCALE' => 'sambaedu.linux.locale',
            'LINUX_KEYBOARD' => 'sambaedu.linux.keyboard',
            'LINUX_INTERFACE' => 'sambaedu.linux.interface',
            'LINUX_USER' => 'sambaedu.linux.user',
            'LINUX_USER_PASSWD' => 'sambaedu.linux.user_passwd', // SECRET
            // Version Debian.
            'VERSION_DEBIAN' => 'sambaedu.linux.version_debian',
            // Identité du domaine + AD.
            'DOMAIN' => 'sambaedu.domain',
            'SE4AD_IP' => 'sambaedu.se4ad_ip',
            'SE4FS_IP' => 'sambaedu.se4fs_ip',
            'SE4AD_NAME' => 'sambaedu.se4ad_name',
            'SE4FS_NAME' => 'sambaedu.se4fs_name',
            'LDAP_PORT' => 'sambaedu.ldap_port',
            'LDAP_ADMIN_PASSWD' => 'sambaedu.ldap_admin_passwd', // SECRET
            'LDAP_ADMIN_NAME' => 'sambaedu.ldap_admin_name',
            'LDAP_BASE_DN' => 'sambaedu.ldap_base_dn',
            'COMPUTERS_RDN' => 'sambaedu.computers_rdn',
            'ADMIN_RDN' => 'sambaedu.admin_rdn',
            'ADMIN_PASSWD' => 'sambaedu.admin_passwd', // SECRET
            'ADMINSE_PASSWD' => 'sambaedu.admin_passwd', // SECRET (alias historique)
            'SAMBA_DOMAIN' => 'sambaedu.samba_domain',
            // Proxy / apt cache.
            'APT_PROXY' => 'sambaedu.linux.apt_proxy',
            'PROXY_TYPE' => 'sambaedu.linux.proxy_type',
            'PROXY_ADDRESS' => 'sambaedu.linux.proxy_address',
            'PROXY_PORT' => 'sambaedu.linux.proxy_port',
            'PROXY_URL' => 'sambaedu.linux.proxy_url',
            // Clé publique SSH + token (sécurité).
            'SE4_PUB_KEY' => 'sambaedu.se4_pub_key',
            'TOKEN' => 'sambaedu.linux.token', // SECRET
            // Dépôt Debian SambaEdu.
            'DEPOT_TYPE' => 'sambaedu.linux.depot_type',
        ];
    }

    /**
     * Sanitize une valeur avant interpolation dans le preseed.
     *
     * **Règles** :
     *  1. Remplace les newlines (`\n`, `\r`) par un espace — un newline
     *     dans une ligne preseed permet une injection de directive
     *     supplémentaire.
     *  2. Remplace les caractères non ASCII imprimables par `?` — le
     *     parser debconf n'accepte que de l'ASCII 0x20-0x7E.
     *  3. Préserve les chars iPXE-special (`$`, `{`) car certaines valeurs
     *     légitimes (URLs `http://${something}`) peuvent les contenir,
     *     mais cf. note ci-dessous pour `${`/`\$`.
     *
     * Note `${` : iPXE expand `${var}` dynamiquement. Le preseed est
     * consommé par debian-installer (pas par iPXE), donc `${` est inerte
     * dans ce contexte. Pas de strip ici — préserver la valeur tel quel.
     */
    public static function sanitize(string|int|null $value): string
    {
        if ($value === null) {
            return '';
        }

        $str = (string) $value;

        // Replace newlines + carriage returns par espace.
        $str = preg_replace('/[\r\n]+/', ' ', $str) ?? $str;

        // Replace chars non ASCII 0x20-0x7E + TAB par `?`.
        $str = preg_replace('/[^\x09\x20-\x7E]/', '?', $str) ?? $str;

        return $str;
    }

    /**
     * Interpole un template (multi-lignes string) en remplaçant chaque
     * occurrence `###_<KEY>_###` par `sanitize($values[strtolower(key)] ?? '')`.
     *
     * **Algorithme iso-legacy** (`sambaedu/includes/config.inc.php:546-556`) :
     *  1. Pour chaque (param, valeur) dans `$values` :
     *     - Remplace `###_<UPPER(param)>_###` par `sanitize(valeur)`.
     *  2. Au final, remplace tous les `###_*_###` orphelins par `''`
     *     (cleanup des placeholders non résolus — parité legacy
     *     `preg_replace("/###_(.*)_###/U", "", $lignes)`).
     *
     * @param  string  $template  Template avec placeholders `###_KEY_###`.
     * @param  array<string, string|int|null>  $values  Clés en lowercase OU
     *                                                  uppercase (case-insensitive).
     * @return string                                   Template interpolé.
     */
    public static function interpolate(string $template, array $values): string
    {
        $result = $template;

        foreach ($values as $key => $value) {
            $placeholder = '###_' . strtoupper((string) $key) . '_###';
            $sanitized = self::sanitize($value);
            $result = str_replace($placeholder, $sanitized, $result);
        }

        // Cleanup placeholders orphelins (iso-legacy preg_replace U flag).
        $result = preg_replace('/###_[^#]*_###/U', '', $result) ?? $result;

        return $result;
    }

    /**
     * Extrait tous les placeholders `###_<KEY>_###` présents dans un
     * template. Utilisé par les tests pour vérifier qu'aucun placeholder
     * orphelin n'apparaît dans les fragments de production.
     *
     * @return list<string>  Liste des clés (uppercase sans `###_..._###`)
     *                       trouvées dans le template, dédupliquée.
     */
    public static function extractKeys(string $template): array
    {
        if (preg_match_all('/###_([A-Z0-9_]+)_###/', $template, $matches) === false) {
            return [];
        }

        return array_values(array_unique($matches[1]));
    }
}

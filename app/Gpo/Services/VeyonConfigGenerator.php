<?php

declare(strict_types=1);

namespace App\Gpo\Services;

use App\Config\SambaEduConfig;
use App\Dto\AppCustomization\AppContext;
use Illuminate\Support\Facades\Log;

/**
 * Génère la config JSON Veyon iso-legacy consommée par le client Veyon des
 * postes (binding LDAP, ACL, groupes autorisés).
 *
 * Story 16.3b — porte la logique de `sambaedu/gpo/veyon_out.php:1-141` en
 * service natif testable. Iso-bytes obligatoire (parser C++ Veyon strict
 * sur la casse et les types).
 *
 * **Encodage `BindPassword`** : `bin2hex(openssl_public_encrypt(read_ldap_password,
 *  default-pubkey.pem, PKCS1_OAEP))` — non-déterministe (padding aléatoire).
 *  Tests via `openssl_private_decrypt` (cf. AC5.4).
 *
 * **Side effect AD** : la résolution du `read_ldap_password` (création compte
 *  AD si absent) est déléguée à `ReadUserManager` (cf. AC2.5-2.7).
 *
 * @legacy-port path="sambaedu/gpo/veyon_out.php"
 * @todo Story 16.4 : remplacer la lecture `veyon.json` + `local.json` par un
 *       template stocké en base ou config.
 */
class VeyonConfigGenerator
{
    private const DEFAULT_TEMPLATE_PATH = '/usr/share/sambaedu/applications/veyon/veyon.json';
    private const DEFAULT_LOCAL_PATH = '/etc/sambaedu/applications/veyon/local.json';
    private const DEFAULT_PUBKEY_PATH = '/usr/share/sambaedu/applications/veyon/default-pubkey.pem';

    public function __construct(
        private readonly SambaEduConfig $config,
    ) {}

    /**
     * Génère la config Veyon complète prête à être encodée en JSON.
     *
     * @param  string  $readLdapPassword  Mot de passe du compte AD `read.user{suffix}` en clair.
     * @return array<string,mixed>
     */
    public function generate(AppContext $context, string $readLdapPassword): array
    {
        $json = $this->loadTemplate();

        $publicKey = $this->loadPublicKey();
        $bindPassword = $this->encryptBindPassword($readLdapPassword, $publicKey);

        $suffix = (string) $this->config->get('suffix', '');
        $peopleRdn = (string) $this->config->get('people_rdn', '');
        $groupsRdn = (string) $this->config->get('groups_rdn', '');
        $parcsRdn = (string) $this->config->get('parcs_rdn', '');
        $computersRdn = (string) $this->config->get('computers_rdn', '');
        $baseDn = (string) $this->config->ldap()->baseDn;

        $parcFilter = $this->resolveParcFilter($context);

        $pdn = self::cleandn($parcsRdn);
        $cdn = self::cleandn($computersRdn);

        $json['LDAP'] = [
            'BaseDN' => self::cleandn($baseDn),
            'BindDN' => 'CN=read.user' . $suffix . ',' . $peopleRdn . ',' . $baseDn,
            'BindPassword' => $bindPassword,
            'ComputerContainersFilter' => '',
            'ComputerDisplayNameAttribute' => 'cn',
            'ComputerGroupTree' => $pdn,
            'ComputerGroupsFilter' => $parcFilter,
            'ComputerHostNameAsFQDN' => false,
            'ComputerHostNameAttribute' => 'name',
            'ComputerLocationAttribute' => 'dn',
            'ComputerLocationsByAttribute' => false,
            'ComputerLocationsByContainer' => false,
            'ComputerMacAddressAttribute' => 'networkAddress',
            'ComputerRoomAttribute' => 'dn',
            'ComputerRoomMembersByAttribute' => false,
            'ComputerRoomMembersByContainer' => true,
            'ComputerRoomNameAttribute' => 'cn',
            'ComputerTree' => $cdn,
            'ComputersFilter' => '(objectClass=computer)',
            'ConnectionSecurity' => 1,
            'GroupMemberAttribute' => 'member',
            'IdentifyGroupMembersByNameAttribute' => false,
            'LocationNameAttribute' => 'cn',
            'QueryNamingContext' => false,
            'RecursiveSearchOperations' => true,
            'ServerHost' => $this->resolveServerHost(),
            'ServerPort' => 389,
            'TLSVerifyMode' => 1,
            'UseBindCredentials' => true,
            'UserGroupsFilter' => '(|(CN=Eleves)(CN=Profs)(CN=Administratifs))',
            'UserLoginAttribute' => 'cn',
            'UserLoginNameAttribute' => 'cn',
            'UsersFilter' => '(objectClass=person)',
        ];

        // Iso-legacy : `$config = get_config($config, true, true)` recharge le
        // contexte global (au-delà du contexte établissement) pour résoudre
        // les `groups_rdn` / `people_rdn` du domaine entier. Côté natif :
        // SambaEduConfig est déjà global → utilisation directe.
        $json['LDAP']['UserTree'] = self::cleandn($peopleRdn);
        $json['LDAP']['GroupTree'] = self::cleandn($groupsRdn);

        if (! isset($json['AccessControl']) || ! is_array($json['AccessControl'])) {
            $json['AccessControl'] = [];
        }
        if (! isset($json['AccessControl']['AuthorizedUserGroups']) || ! is_array($json['AccessControl']['AuthorizedUserGroups'])) {
            $json['AccessControl']['AuthorizedUserGroups'] = [];
        }
        $json['AccessControl']['AuthorizedUserGroups'][0] = 'CN=Admins,' . self::cleandn($groupsRdn);
        $json['AccessControl']['AuthorizedUserGroups'][1] = 'CN=Profs,' . self::cleandn($groupsRdn);
        $json['AccessControl']['AuthorizedUserGroups'][2] = 'CN=Administratifs,' . self::cleandn($groupsRdn);

        $openentUri = $this->config->get('openent_uri');
        if (is_string($openentUri) && $openentUri !== '') {
            if (! isset($json['DesktopServices']) || ! is_array($json['DesktopServices'])) {
                $json['DesktopServices'] = [];
            }
            if (! isset($json['DesktopServices']['PredefinedWebsites']) || ! is_array($json['DesktopServices']['PredefinedWebsites'])) {
                $json['DesktopServices']['PredefinedWebsites'] = [];
            }
            if (! isset($json['DesktopServices']['PredefinedWebsites']['JsonStoreArray']) || ! is_array($json['DesktopServices']['PredefinedWebsites']['JsonStoreArray'])) {
                $json['DesktopServices']['PredefinedWebsites']['JsonStoreArray'] = [];
            }
            $json['DesktopServices']['PredefinedWebsites']['JsonStoreArray'][0]['Name'] = 'ENT';
            $json['DesktopServices']['PredefinedWebsites']['JsonStoreArray'][0]['Path'] = $openentUri;
        }

        return $json;
    }

    /**
     * Helper iso-legacy : normalise un DN en majuscules sur les RDN.
     *
     * `ou=foo,dc=bar` → `OU=foo,DC=bar`.
     *
     * @legacy-port path="sambaedu/gpo/veyon_out.php:6-11"
     */
    public static function cleandn(string $dn): string
    {
        $dn = preg_replace('/ou=/', 'OU=', $dn) ?? $dn;
        $dn = preg_replace('/cn=/', 'CN=', $dn) ?? $dn;
        return preg_replace('/dc=/', 'DC=', $dn) ?? $dn;
    }

    /**
     * Chiffre `$password` avec la clé publique RSA `$publicKey` en
     * PKCS1_OAEP padding, retourne le cipher hex.
     *
     * @legacy-port path="sambaedu/gpo/veyon_out.php:75"
     */
    public function encryptBindPassword(string $password, string $publicKey): string
    {
        if ($publicKey === '') {
            return '';
        }
        $crypted = '';
        $ok = openssl_public_encrypt($password, $crypted, $publicKey, OPENSSL_PKCS1_OAEP_PADDING);
        if (! $ok) {
            return '';
        }
        return bin2hex($crypted);
    }

    /**
     * Charge le template `veyon.json` + override `local.json` (array_replace_recursive).
     *
     * Path configurable via `config('sambaedu.gpo.veyon.template_path')` et
     * `config('sambaedu.gpo.veyon.local_path')` (pour les tests).
     *
     * @return array<string,mixed>
     */
    private function loadTemplate(): array
    {
        $templatePath = (string) config('sambaedu.gpo.veyon.template_path', self::DEFAULT_TEMPLATE_PATH);
        $localPath = (string) config('sambaedu.gpo.veyon.local_path', self::DEFAULT_LOCAL_PATH);

        $json = [];
        if (is_file($templatePath) && is_readable($templatePath)) {
            $contents = file_get_contents($templatePath);
            if ($contents !== false) {
                $decoded = json_decode($contents, true);
                if (is_array($decoded)) {
                    $json = $decoded;
                }
            }
        }
        if (is_file($localPath) && is_readable($localPath)) {
            $contents = file_get_contents($localPath);
            if ($contents !== false) {
                $decoded = json_decode($contents, true);
                if (is_array($decoded)) {
                    $json = array_replace_recursive($json, $decoded);
                }
            }
        }

        return $json;
    }

    /**
     * Charge la clé publique RSA pour le chiffrement OAEP.
     *
     * Path configurable via `config('sambaedu.gpo.veyon.pubkey_path')`.
     */
    private function loadPublicKey(): string
    {
        $path = (string) config('sambaedu.gpo.veyon.pubkey_path', self::DEFAULT_PUBKEY_PATH);
        if (! is_file($path) || ! is_readable($path)) {
            Log::warning('[VeyonConfigGenerator] public key not readable', ['path' => $path]);
            return '';
        }
        $contents = file_get_contents($path);
        return $contents === false ? '' : $contents;
    }

    /**
     * Résout `ServerHost` iso-legacy via shim `ad_url($config, 'dns')`.
     *
     * Fallback : `se4ad_name.domain`.
     *
     * @legacy-port path="sambaedu/gpo/veyon_out.php:104"
     */
    private function resolveServerHost(): string
    {
        $this->ensureLegacyBootstrap();

        if (function_exists('ad_url')) {
            try {
                /** @var string $url */
                $url = ad_url($this->config->all(), 'dns');
                if (is_string($url) && $url !== '') {
                    return $url;
                }
            } catch (\Throwable $e) {
                Log::warning('[VeyonConfigGenerator] ad_url threw', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $se4adName = (string) $this->config->get('se4ad_name', '');
        $domain = (string) $this->config->get('domain', '');
        if ($se4adName !== '' && $domain !== '') {
            return $se4adName . '.' . $domain;
        }
        return $domain;
    }

    /**
     * Calcule le `ComputerGroupsFilter` selon la salle d'origine de la machine.
     *
     * Iso-legacy `gpo/veyon_out.php:53-65` :
     *   - `search_ad($config, $salleName, 'salle')` → si trouvé, lister tous les
     *     parcs enfants → `(|(cn=$parc.ou)...)`.
     *   - sinon `(cn=$salleName)`.
     *
     * @legacy-port path="sambaedu/gpo/veyon_out.php:53-65"
     */
    private function resolveParcFilter(AppContext $context): string
    {
        $salleName = $context->salleName;
        if ($salleName === '') {
            return '(cn=)';
        }

        $this->ensureLegacyBootstrap();

        if (! function_exists('search_ad')) {
            return '(cn=' . $salleName . ')';
        }

        try {
            /** @var array<int,array<string,mixed>>|array<string,mixed> $salles */
            $salles = search_ad($this->config->all(), $salleName, 'salle');
            $first = is_array($salles) && isset($salles[0]) && is_array($salles[0]) ? $salles[0] : null;

            if ($first !== null && isset($first['dn'])) {
                /** @var array<int,array<string,mixed>>|array<string,mixed> $childrens */
                $childrens = search_ad($this->config->all(), '*', 'salle', (string) $first['dn']);
                $filter = '(|';
                if (is_array($childrens)) {
                    foreach ($childrens as $key => $parc) {
                        if (! is_array($parc) || ! isset($parc['ou'])) {
                            continue;
                        }
                        if (is_string($key)) {
                            continue; // skip 'count' / 'dn' keys
                        }
                        $filter .= '(cn=' . $parc['ou'] . ')';
                    }
                }
                $filter .= ')';
                return $filter;
            }
        } catch (\Throwable $e) {
            Log::warning('[VeyonConfigGenerator] search_ad threw', [
                'salle' => $salleName,
                'error' => $e->getMessage(),
            ]);
        }

        return '(cn=' . $salleName . ')';
    }

    private function ensureLegacyBootstrap(): void
    {
        if (defined('LEGACY_BOOTSTRAP_LOADED')) {
            return;
        }
        $bootstrap = base_path('legacy/bootstrap.php');
        if (is_file($bootstrap)) {
            require_once $bootstrap;
        }
    }
}

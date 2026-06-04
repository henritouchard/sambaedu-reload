<?php

declare(strict_types=1);

namespace App\Ipxe\Services;

use App\Ipxe\Enums\IpxeAdminAction;
use App\Models\Workstation;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Http\Request;

/**
 * Story 3.2 — D9 / D10 / AC1.2.
 *
 * Résout et rend les templates Blade des actions iPXE whitelistées
 * (`resources/views/ipxe/actions/{rescuecd,winpe,factory_reset}.blade.php`).
 *
 * **Décisions de design (cf. story 3.2 §Dev Notes)** :
 *
 *  - Service séparé de {@see IpxeMenuRenderer} — la sémantique diffère
 *    (menu interactif vs script kernel/initrd directement exécuté par le
 *    firmware iPXE).
 *  - Lecture de `config('ipxe.actions.*')` pour `$osUrl`, `$scriptUrl` et
 *    la clé qui héberge `se4install_passwd` — pas de hardcode.
 *  - Pré-construction de l'URL `$autorunUrl` (rescuecd) côté service pour
 *    permettre le test unit (snapshot d'interpolation).
 *  - Sanitize ASCII des inputs sensibles (`mac`, `uuid`, `workstationName`)
 *    iso 3.1 — un firmware iPXE rejette l'ASCII étendu.
 *  - **Pas de side effect** : aucun log direct ici (porté par
 *    {@see IpxeService}), aucune insertion DB.
 *
 * **Charset ASCII strict** : iso 3.1 D9 — les templates `ipxe.actions.*` ne
 * contiennent que des caractères ASCII (0x20-0x7E). Tout input non ASCII est
 * remplacé par `?` via {@see sanitizeAscii()}.
 */
final class IpxeActionResolver
{
    /**
     * Iso 3.1 DO-13 — le shebang `#!ipxe` est injecté comme variable Blade
     * pour contourner le strip PHP automatique des shebangs CLI.
     */
    private const IPXE_SHEBANG = '#!ipxe';

    /**
     * Iso-legacy `actions/winpe.php:7` — version par défaut Windows.
     */
    private const DEFAULT_WIN_VERSION = 'Win11';

    /**
     * Iso-legacy `actions/winpe.php:6` — debug par défaut activé.
     */
    private const DEFAULT_WIN_DEBUG = 1;

    public function __construct(
        private readonly ViewFactory $viewFactory,
        private readonly \App\Services\ServiceCredentials $credentials,
    ) {
    }

    /**
     * Rend le script iPXE d'une action whitelistée.
     *
     * @param  IpxeAdminAction  $action   Case enum (déjà validé par le
     *                                    controller via `tryFrom()`).
     * @param  Workstation|null  $ws      Poste résolu (null = poste inconnu
     *                                    autorisé en parité legacy
     *                                    `action.php:28` — un poste neuf en
     *                                    factory_reset n'a pas d'enrollment).
     * @param  Request  $request           Pour reconstruire les URLs si la
     *                                    config est vide.
     * @return string                     Body iPXE complet
     *                                    (`#!ipxe\nkernel ...\nboot\n`).
     */
    public function resolve(IpxeAdminAction $action, ?Workstation $ws, Request $request): string
    {
        $mac = $this->sanitizeAscii((string) $request->input('mac', ''));
        $uuid = $this->sanitizeAscii(strtolower((string) $request->input('uuid', '')));
        $workstationName = $this->sanitizeAscii((string) ($ws->name ?? ''));

        $osUrl = $this->resolveOsUrl($request);
        $scriptUrl = $this->resolveScriptUrl($request);
        $serverBaseUrl = $this->resolveServerBaseUrl($request);

        $autorunUrl = $scriptUrl . '/sysrescuecd/autorun.php?mac=' . rawurlencode($mac)
            . '&uuid=' . rawurlencode($uuid);

        // Mot de passe effectif (base+code si TOTP actif) avec repli sur la clé
        // de config indirecte historique. Voir [[project_se4install_credential_totp]].
        $se4installPasswd = $this->credentials->effectivePassword('se4install')
            ?? (string) config(
                (string) config('ipxe.actions.se4install_passwd_config_key', 'sambaedu.se4install_passwd'),
                '',
            );

        // Variables spécifiques `winpe` — iso-legacy `actions/winpe.php`.
        // Fix review #2 / Q2 Henri — whitelist stricte de `$version`. Défense
        // en profondeur : on revalide côté resolver pour le cas où la
        // FormRequest serait court-circuitée (tests, instanciation directe du
        // service). Hors whitelist → fallback DEFAULT_WIN_VERSION sans
        // exception (l'event de log winpe sortira avec une version connue,
        // pas de body cassé).
        $rawVersion = (string) $request->input('version', self::DEFAULT_WIN_VERSION);
        $allowedVersions = (array) config(
            'ipxe.actions.winpe.allowed_versions',
            ['Win10', 'Win11'],
        );
        $version = in_array($rawVersion, $allowedVersions, true)
            ? $rawVersion
            : self::DEFAULT_WIN_VERSION;
        $debug = (int) $request->input('debug', self::DEFAULT_WIN_DEBUG);
        $disk = (int) $request->input('disk', 0);
        $perso = (int) $request->input('perso', 0);

        // Story 3.4 — AC6.2 / AC7.1 — variables Linux install pour les
        // templates `ipxe.actions.install_*`. linuxMeta() retourne null pour
        // les 3 actions historiques 3.2 (rescuecd, winpe, factory_reset).
        $linuxVariables = $this->resolveLinuxVariables($action, $mac, $uuid, $scriptUrl, $perso);

        // Story 3.5 — AC6.2 / AC7.1 — variables Windows install pour les
        // templates `ipxe.actions.install_win*`. windowsMeta() retourne null
        // pour les 12 cases hors install_win*.
        $windowsVariables = $this->resolveWindowsVariables($action, $scriptUrl);

        // Story 3.7 — post-review #12 — variables config-driven pour les
        // templates « tools » (gparted/hdt/memtest86plus). Injectées ici
        // (resolver) plutôt qu'appelées via `config()` dans le Blade — pattern
        // Epic 3 standard, facilite les tests + override sans toucher la
        // config globale.
        $toolsVariables = $this->resolveToolsVariables();

        return $this->viewFactory->make($action->template(), array_merge([
            'shebang' => self::IPXE_SHEBANG,
            'mac' => $mac,
            'uuid' => $uuid,
            'workstationName' => $workstationName,
            'action' => $action->value,
            'osUrl' => $osUrl,
            'scriptUrl' => $scriptUrl,
            'serverBaseUrl' => $serverBaseUrl,
            'autorunUrl' => $autorunUrl,
            'se4installPasswd' => $se4installPasswd,
            'version' => $version,
            'debug' => $debug,
            'disk' => $disk,
            'perso' => $perso,
        ], $linuxVariables, $windowsVariables, $toolsVariables))->render();
    }

    /**
     * Story 3.5 — AC6.2 / AC7.1 — Construit les variables Blade pour les
     * templates `ipxe.actions.install_win*`.
     *
     * Pour les actions hors install_win* : retourne un tableau vide (les
     * templates correspondants n'utilisent pas ces variables).
     *
     * Pour les actions install_win* :
     *  - `$windowsVersion`        : `'Win10'` ou `'Win11'`.
     *  - `$winAction`             : `'wimboot10'` ou `'wimboot11'` (param
     *    iso-legacy).
     *  - `$winDebug` / `$winDisk` / `$winPerso` : flags 0/1.
     *  - `$installBatUrl`         : URL `/ipxe/windows/install.bat`.
     *  - `$unattendXmlUrl`        : URL `/ipxe/windows/unattend.xml`.
     *  - `$winAssetsBase`         : URL absolue `<scriptUrl>/Win10` (legacy
     *    wimboot/winpeshl partagés Win10/Win11 — parité `wimboot11.php:7`
     *    `kernel Win10/wimboot`, résolu relatif à `/ipxe/` côté legacy).
     *  - `$winVersionBase`        : URL absolue `<scriptUrl>/<version>` pour
     *    BCD/boot.sdi/boot.wim.
     *
     * @return array<string, mixed>
     */
    private function resolveWindowsVariables(
        IpxeAdminAction $action,
        string $scriptUrl,
    ): array {
        $meta = $action->windowsMeta();
        if ($meta === null) {
            return [];
        }

        // `$scriptUrl` est déjà la base `/ipxe` (cf. resolveScriptUrl() —
        // parité rescuecd/autorun). Ne PAS re-suffixer `/ipxe` ici (sinon
        // `/ipxe/ipxe/windows/...` quand l'URL est reconstruite depuis la
        // Request).
        $installBatUrl = $scriptUrl . '/windows/install.bat';
        $unattendXmlUrl = $scriptUrl . '/windows/unattend.xml';

        // `winAssetsBase` = chemin des assets wimboot/winpeshl statiques
        // (iso-legacy `actions/wimboot10.php:6` + `wimboot11.php:6` qui
        // pointent tous les deux sur `Win10/wimboot` + `Win10/winpeshl.ini`).
        // Override-able via config si Win11 a son propre wimboot.
        //
        // Fix 2026-06-04 — URLs ABSOLUES obligatoires : le legacy servait le
        // script depuis `/ipxe/action.php`, donc `Win10/wimboot` relatif se
        // résolvait en `/ipxe/Win10/wimboot`. En natif le script est servi
        // depuis `/ipxe/action/<enum>` → le même chemin relatif partait sur
        // `/ipxe/action/Win10/wimboot` → 410 → abort iPXE → exit firmware.
        $winAssetsBase = $scriptUrl . '/'
            . trim((string) config('ipxe.windows.assets_paths.wimboot_base', 'Win10'), '/');

        // Base absolue des assets versionnés BCD/boot.sdi/boot.wim
        // (`/ipxe/Win10/...` ou `/ipxe/Win11/...` — Alias Apache vers le
        // legacy `/var/www/sambaedu/ipxe`). Même raison que `winAssetsBase`.
        $winVersionBase = $scriptUrl . '/' . $meta['version'];

        return [
            'windowsVersion' => $meta['version'],
            'winAction' => $meta['action'],
            'winDebug' => $meta['debug'],
            'winDisk' => $meta['disk'],
            'winPerso' => $meta['perso'],
            'installBatUrl' => $installBatUrl,
            'unattendXmlUrl' => $unattendXmlUrl,
            'winAssetsBase' => $winAssetsBase,
            'winVersionBase' => $winVersionBase,
        ];
    }

    /**
     * Story 3.4 — AC6.2 / AC7.1 — Construit les variables Blade pour les
     * templates `ipxe.actions.install_*`.
     *
     * Pour les actions hors install_* (rescuecd, winpe, factory_reset) :
     * retourne un tableau vide (les templates correspondants n'utilisent pas
     * ces variables).
     *
     * Pour les actions install_* :
     *  - `$osVersion`   : version Debian (`trixie`) ou `'ubuntu'` selon
     *    distribution.
     *  - `$installType` : variante desktop (`gnome`, `lxde`, ..., `base`).
     *  - `$preseedUrl`  : URL preseed avec params (`mac`, `uuid`, `os`,
     *    `type`, `perso` pour Nird).
     *  - `$kernelPath`  : chemin kernel selon distribution (Debian/Ubuntu/Nird).
     *  - `$initrdPath`  : chemin initrd selon distribution.
     *  - `$nfsRoot`     : root NFS pour Nird uniquement (parité legacy).
     *
     * @return array<string, mixed>
     */
    private function resolveLinuxVariables(
        IpxeAdminAction $action,
        string $mac,
        string $uuid,
        string $scriptUrl,
        int $perso,
    ): array {
        $meta = $action->linuxMeta();
        if ($meta === null) {
            return [];
        }

        $distribution = $meta['distribution'];
        $variant = $meta['variant'];

        // Détermine la version OS selon la distribution.
        if ($distribution === 'ubuntu') {
            $osVersion = 'ubuntu';
        } elseif ($distribution === 'nird') {
            // Nird = installé via preseed Debian dérivé avec perso=1 — la
            // valeur `os=debian` passe à l'URL preseed (parité legacy
            // `nird.php:5` `$os = "debian"`).
            $osVersion = 'debian';
        } else {
            $osVersion = (string) config('sambaedu.linux.version_debian', 'trixie');
        }

        // Build preseed URL — parité legacy `actions/deb_*.php:6`.
        // Le `perso=1` est forcé pour Nird (parité `nird.php:5`).
        $persoFlag = ($distribution === 'nird' || $perso === 1) ? 1 : 0;
        $preseedUrl = $scriptUrl . '/linux/preseed?mac=' . rawurlencode($mac)
            . '&uuid=' . rawurlencode($uuid)
            . '&os=' . rawurlencode($osVersion)
            . '&type=' . rawurlencode($variant);
        if ($persoFlag === 1) {
            $preseedUrl .= '&perso=1';
        }

        // Résolution kernel/initrd paths selon distribution.
        $kernelPaths = (array) config('ipxe.linux.kernel_paths', []);
        if ($distribution === 'ubuntu') {
            $kernelPath = (string) ($kernelPaths['ubuntu'] ?? '/ubuntu-installer/amd64/linux');
            $initrdPath = (string) ($kernelPaths['ubuntu_initrd'] ?? '/ubuntu-installer/amd64/initrd.gz');
        } elseif ($distribution === 'nird') {
            $kernelPath = (string) ($kernelPaths['nird'] ?? '/nird/casper/vmlinuz');
            $initrdPath = (string) ($kernelPaths['nird_initrd'] ?? '/nird/casper/initrd.gz');
        } else {
            $kernelPath = (string) ($kernelPaths['debian'] ?? '/debian-installer/amd64/linux');
            $initrdPath = (string) ($kernelPaths['debian_initrd'] ?? '/debian-installer/amd64/initrd.gz');
        }

        // NFS root pour Nird uniquement (parité legacy `nird.php:11`).
        $se4fsIp = (string) config('sambaedu.se4fs_ip', '');
        $nfsRoot = $se4fsIp !== ''
            ? $se4fsIp . ':/var/sambaedu/unattended/install/os/nird root'
            : 'se4fs:/var/sambaedu/unattended/install/os/nird root';

        return [
            'osVersion' => $osVersion,
            'installType' => $variant,
            'preseedUrl' => $preseedUrl,
            'kernelPath' => $kernelPath,
            'initrdPath' => $initrdPath,
            'nfsRoot' => $nfsRoot,
            'distribution' => $distribution,
        ];
    }

    /**
     * Résout l'URL de base des assets OS (sysresccd, clonezilla, Win10...).
     *
     * Priorité : `config('ipxe.actions.os_url')` → `Request` scheme+host suffixé
     * `/ipxe` → fallback `http://se4fs/ipxe`.
     */
    private function resolveOsUrl(Request $request): string
    {
        $configured = (string) config('ipxe.actions.os_url', '');
        if ($configured !== '') {
            return rtrim($configured, '/');
        }

        // Assets OS (debian-installer, sysresccd, clonezilla, Win10...) servis
        // par la route Laravel `/ipxe/os/{path}` (cf. IpxeOsAssetController) —
        // chemins versionnes en config, plus d'Alias Apache par-emplacement.
        // NE PAS confondre avec `/ipxe` nu (script_url, preseed/autorun).
        $schemeAndHost = (string) ($request->getSchemeAndHttpHost() ?? '');
        if ($schemeAndHost !== '') {
            return rtrim($schemeAndHost, '/') . '/ipxe/os';
        }

        return 'http://se4fs/ipxe/os';
    }

    /**
     * Résout l'URL de base des scripts dynamiques (`/ipxe`).
     *
     * Parité legacy `ipxe_functions.inc.php:80` (`$script_url =
     * http://{se4fs_ip}/ipxe`). Distinct de `resolveOsUrl()` (`/os`) : les
     * scripts (preseed, autorun...) vivent sous `/ipxe`, les assets OS sous
     * `/os`. Override via `config('ipxe.actions.script_url')`.
     */
    private function resolveScriptUrl(Request $request): string
    {
        $configured = (string) config('ipxe.actions.script_url', '');
        if ($configured !== '') {
            return rtrim($configured, '/');
        }

        $schemeAndHost = (string) ($request->getSchemeAndHttpHost() ?? '');
        if ($schemeAndHost !== '') {
            return rtrim($schemeAndHost, '/') . '/ipxe';
        }

        return 'http://se4fs/ipxe';
    }

    /**
     * Story 3.7 — D8 — Résout l'URL de base du serveur (scheme + host, sans
     * le suffixe `/ipxe`). Utilisée par les templates des outils de diagnostic
     * (gparted, hdt, memtest86plus) dont les assets sont servis depuis la
     * racine du serveur Apache (`/bin/gparted/`, `/bin/hdt/`, etc.) et non
     * depuis `/ipxe/`.
     *
     * Post-review #5 (2026-05-22) — **clé config canonique unifiée** avec
     * {@see IpxeService::resolveServerBaseUrl()} et
     * {@see \App\Ipxe\Services\IpxeEnrollmentOrchestrator} : on lit
     * `ipxe.se4fs_url` (override env `IPXE_SE4FS_URL`) — auparavant on lisait
     * `ipxe.actions.server_base_url` (clé orpheline), ce qui ignorait
     * silencieusement les overrides ops. La clé legacy reste tolérée en
     * fallback secondaire pour compatibilité descendante (deprecated, sera
     * retirée Phase 3).
     *
     * Priorité : `config('ipxe.se4fs_url')` (override env canonique) →
     * `config('ipxe.actions.server_base_url')` (fallback deprecated) →
     * `Request` scheme+host → `http://se4fs`.
     */
    private function resolveServerBaseUrl(Request $request): string
    {
        $configured = (string) config('ipxe.se4fs_url', '');
        if ($configured === '') {
            // Compat descendante — clé orpheline livrée 3.7. Fallback secondaire
            // pour ne pas casser un override prod éventuel posé avant le fix.
            $configured = (string) config('ipxe.actions.server_base_url', '');
        }
        if ($configured !== '') {
            return rtrim($configured, '/');
        }

        $schemeAndHost = (string) ($request->getSchemeAndHttpHost() ?? '');
        if ($schemeAndHost !== '') {
            return rtrim($schemeAndHost, '/');
        }

        return 'http://se4fs';
    }

    /**
     * Story 3.7 — post-review #12 (2026-05-22).
     *
     * Construit les variables Blade `$gpartedKernelPath`, `$gpartedInitrdPath`,
     * `$gpartedFilesystemPath`, `$hdtPxelinux0Path`, `$hdtPxelinuxCfg`,
     * `$memtestPxelinux0Path`, `$memtestPxelinuxCfg` lues par les 3 templates
     * « tools » (`gparted.blade.php`, `hdt.blade.php`, `memtest86plus.blade.php`).
     *
     * Avant le fix, ces templates appelaient `config('ipxe.tools.*.kernel_path')`
     * directement — couplage caché, non testable sans modif config globale, et
     * divergence du pattern Epic 3 « resolver injecte, Blade consomme ».
     *
     * Tableau retourné systématiquement (pas de short-circuit) — les 3 templates
     * ont leurs variables, mais celles non utilisées sont simplement ignorées
     * par le Blade.
     *
     * @return array<string, string>
     */
    private function resolveToolsVariables(): array
    {
        return [
            'gpartedKernelPath' => (string) config('ipxe.tools.gparted.kernel_path', '/bin/gparted/vmlinuz'),
            'gpartedInitrdPath' => (string) config('ipxe.tools.gparted.initrd_path', '/bin/gparted/initrd.img'),
            'gpartedFilesystemPath' => (string) config('ipxe.tools.gparted.filesystem_path', '/bin/gparted/filesystem.squashfs'),
            'hdtPxelinux0Path' => (string) config('ipxe.tools.hdt.pxelinux0_path', '/bin/pxelinux.0'),
            'hdtPxelinuxCfg' => (string) config('ipxe.tools.hdt.pxelinux_cfg', '/bin/pxelinux.cfg/hdt.cfg'),
            'memtestPxelinux0Path' => (string) config('ipxe.tools.memtest86plus.pxelinux0_path', '/bin/pxelinux.0'),
            'memtestPxelinuxCfg' => (string) config('ipxe.tools.memtest86plus.pxelinux_cfg', '/bin/pxelinux.cfg/memtest86plus.cfg'),
        ];
    }

    /**
     * Délègue à l'implémentation canonique {@see IpxeHostnameSanitizer::sanitizeForIpxeOutput()}
     * — Unicode-aware + fail-closed sur UTF-8 invalide (cf. F15 review).
     */
    private function sanitizeAscii(string $value): string
    {
        return IpxeHostnameSanitizer::sanitizeForIpxeOutput($value);
    }
}

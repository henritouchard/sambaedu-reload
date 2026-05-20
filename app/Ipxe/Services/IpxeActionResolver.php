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
        $scriptUrl = $this->resolveScriptUrl($osUrl);

        $autorunUrl = $scriptUrl . '/sysrescuecd/autorun.php?mac=' . rawurlencode($mac)
            . '&uuid=' . rawurlencode($uuid);

        $se4installPasswd = (string) config(
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

        return $this->viewFactory->make($action->template(), [
            'shebang' => self::IPXE_SHEBANG,
            'mac' => $mac,
            'uuid' => $uuid,
            'workstationName' => $workstationName,
            'action' => $action->value,
            'osUrl' => $osUrl,
            'scriptUrl' => $scriptUrl,
            'autorunUrl' => $autorunUrl,
            'se4installPasswd' => $se4installPasswd,
            'version' => $version,
            'debug' => $debug,
            'disk' => $disk,
            'perso' => $perso,
        ])->render();
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

        $schemeAndHost = (string) ($request->getSchemeAndHttpHost() ?? '');
        if ($schemeAndHost !== '') {
            return rtrim($schemeAndHost, '/') . '/ipxe';
        }

        return 'http://se4fs/ipxe';
    }

    /**
     * Résout l'URL de base des scripts dynamiques.
     *
     * Si `config('ipxe.actions.script_url')` non défini → fallback sur
     * l'`$osUrl` déjà résolu (parité legacy `admin.php:12` qui utilise une
     * URL unique pour les deux).
     */
    private function resolveScriptUrl(string $osUrl): string
    {
        $configured = (string) config('ipxe.actions.script_url', '');
        if ($configured !== '') {
            return rtrim($configured, '/');
        }

        return $osUrl;
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

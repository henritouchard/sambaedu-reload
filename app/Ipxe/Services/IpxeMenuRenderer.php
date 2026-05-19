<?php

declare(strict_types=1);

namespace App\Ipxe\Services;

use App\Models\Workstation;
use Illuminate\Contracts\View\Factory as ViewFactory;

/**
 * Story 3.1 — D9 / AC3.1 / AC3.2.
 *
 * Rendu des 3 templates Blade `resources/views/ipxe/menu/{handshake,default,known}.blade.php`
 * vers la chaîne `#!ipxe ...` injectée dans la réponse HTTP `text/plain`.
 *
 * **Architecture** :
 *
 *  - 100% rendu Blade — aucune string concat in-line (DO-5).
 *  - Variables injectées via le contexte Blade `compact(...)` — pas de
 *    placeholder string manuel (anti-pattern legacy `boot.php` qui mélange
 *    PHP + iPXE).
 *  - Méthode publique {@see renderBootDiskFallback()} pour permettre le test
 *    isolé (DO-10).
 *  - **Charset ASCII pur** : pas d'accent français dans les templates (le
 *    firmware iPXE est strict — l'ASCII étendu casse le rendu menu).
 */
final class IpxeMenuRenderer
{
    /**
     * Décision DO-13 : le shebang `#!ipxe` est injecté **comme variable
     * Blade** (`{!! $shebang !!}`) plutôt qu'écrit en clair dans le template.
     *
     * Raison : PHP strip systématiquement la première ligne d'un fichier
     * inclus quand elle commence par `#!` (interprété comme shebang CLI —
     * documenté php.net). Sans cette indirection, `view('ipxe.menu.handshake')->render()`
     * retournerait un body sans `#!ipxe` en première ligne, et le firmware
     * iPXE rejetterait la réponse.
     */
    private const IPXE_SHEBANG = '#!ipxe';

    public function __construct(
        private readonly ViewFactory $viewFactory,
    ) {
    }

    /**
     * Rend le préambule iPXE de premier appel (handshake — sans paramètres).
     *
     * Iso-legacy `boot.php:26-35`.
     *
     * Story 3.2 — D5 / AC4.1 — extension rétrocompat avec un paramètre
     * optionnel `$chainTarget` :
     *
     *  - `null` (défaut)      → rendu iso-3.1 `chain --replace --autofree boot##params`.
     *  - `'admin'`            → `chain ... admin##params` (handshake `/ipxe/admin`).
     *  - `'maintenance'`      → `chain ... maintenance##params` (handshake `/ipxe/maintenance`).
     *  - `'action/rescuecd'`  → `chain ... action/rescuecd##params` (handshake
     *     d'une action spécifique).
     *
     * Pas de logique côté renderer — la substitution est entièrement déléguée
     * au template Blade qui lit `$chainTarget ?? 'boot'`.
     */
    public function renderHandshake(?string $chainTarget = null): string
    {
        return $this->viewFactory->make('ipxe.menu.handshake', [
            'shebang' => self::IPXE_SHEBANG,
            'chainTarget' => $chainTarget,
        ])->render();
    }

    /**
     * Rend le menu pour un poste inconnu (résolution null).
     *
     * Menu minimal : boot disk only. Pas d'item enrollment/admin (= scope
     * 3.3 / 3.2).
     *
     * @param  string  $ip  Adresse IP du poste appelant (pour `menu` title).
     */
    public function renderUnknown(string $ip): string
    {
        return $this->viewFactory->make('ipxe.menu.default', [
            'shebang' => self::IPXE_SHEBANG,
            'ip' => $ip,
            'resolutionX' => (int) config('ipxe.menu.resolution_x', 1024),
            'resolutionY' => (int) config('ipxe.menu.resolution_y', 768),
            'resolutionPng' => $this->resolveBackgroundPng(),
            'menuTimeoutMs' => (int) config('ipxe.menu.unknown_timeout_ms', 10000),
            'bootDiskFallback' => $this->renderBootDiskFallback(),
        ])->render();
    }

    /**
     * Rend le menu pour un poste connu (résolu via {@see WorkstationLocator}).
     *
     * Items : login (placeholder 3.2 — chain vers /ipxe/admin.php legacy),
     * default (boot disk), action (conditionnel — null en 3.1).
     *
     * @param  Workstation  $ws             Modèle Eloquent (relations
     *                                       eager-loaded par le locator).
     * @param  array<string,mixed>|null  $action  Action programmée
     *                                            (null en 3.1, sera enrichi 3.2+).
     * @param  string  $serverBaseUrl       URL du SE4FS (ex.
     *                                       `http://192.168.122.50`) — utilisé
     *                                       pour les chain iPXE.
     */
    public function renderKnown(Workstation $ws, ?array $action, string $serverBaseUrl): string
    {
        // Fix review #8 / Q5 Henri — sanitisation préventive des champs `label`
        // et `name` de l'action. En 3.1 `$action === null` donc code mort,
        // mais en 3.2+ un dev ajoutant un label fr (`"Réinstaller Debian"`)
        // briserait le rendu iPXE (firmware corrompu sur bytes UTF-8 > 127).
        // On blinde ici plutôt que de propager le piège.
        $sanitizedAction = null;
        if (is_array($action)) {
            $sanitizedAction = $action;
            if (isset($sanitizedAction['label'])) {
                $sanitizedAction['label'] = $this->sanitizeAscii((string) $sanitizedAction['label']);
            }
            if (isset($sanitizedAction['name'])) {
                $sanitizedAction['name'] = $this->sanitizeAscii((string) $sanitizedAction['name']);
            }
        }

        return $this->viewFactory->make('ipxe.menu.known', [
            'shebang' => self::IPXE_SHEBANG,
            'workstationName' => $this->sanitizeAscii((string) ($ws->name ?? 'unknown')),
            'ip' => (string) ($ws->ip ?? ''),
            'uuid' => $this->sanitizeAscii((string) ($ws->uuid ?? '')),
            'mac' => (string) ($ws->mac ?? ''),
            'action' => $sanitizedAction,
            'serverBaseUrl' => rtrim($serverBaseUrl, '/'),
            'resolutionX' => (int) config('ipxe.menu.resolution_x', 1024),
            'resolutionY' => (int) config('ipxe.menu.resolution_y', 768),
            'resolutionPng' => $this->resolveBackgroundPng(),
            'menuTimeoutMs' => (int) config('ipxe.menu.default_timeout_ms', 5000),
            'menuDefault' => $sanitizedAction !== null ? 'action' : 'default',
            'bootDiskFallback' => $this->renderBootDiskFallback(),
        ])->render();
    }

    /**
     * Story 3.2 — AC4.2 — Rend le menu admin natif
     * (`resources/views/ipxe/menu/admin.blade.php`).
     *
     * Port simplifié du legacy `sambaedu/ipxe/admin.php` :
     *
     *  - **Items connus** ($ws non null) : maintenance + retour boot + shell
     *    + exit.
     *  - **Items inconnus** ($ws null — D7) : message neutre + exit + retour
     *    boot (pas d'item maintenance, pas d'enrollment — déféré 3.3).
     *
     * **Anti-pattern** : pas de login AD ici (parité D3/D8 de 3.1 — un
     * firmware iPXE n'a pas de notion de session).
     *
     * @param  Workstation|null  $ws            Poste résolu via {@see WorkstationLocator}.
     * @param  string  $ip                      Adresse IP du poste.
     * @param  string  $serverBaseUrl           URL de base du SE4FS pour les
     *                                          chains iPXE.
     */
    public function renderAdminMenu(?Workstation $ws, string $ip, string $serverBaseUrl): string
    {
        $isKnown = $ws !== null;

        return $this->viewFactory->make('ipxe.menu.admin', [
            'shebang' => self::IPXE_SHEBANG,
            'workstationName' => $isKnown
                ? $this->sanitizeAscii((string) ($ws->name ?? 'unknown'))
                : 'unknown',
            'ip' => $ip,
            'mac' => $isKnown ? (string) ($ws->mac ?? '') : '',
            'uuid' => $isKnown ? $this->sanitizeAscii((string) ($ws->uuid ?? '')) : '',
            'serverBaseUrl' => rtrim($serverBaseUrl, '/'),
            'resolutionX' => (int) config('ipxe.menu.resolution_x', 1024),
            'resolutionY' => (int) config('ipxe.menu.resolution_y', 768),
            'resolutionPng' => $this->resolveBackgroundPng(),
            'menuTimeoutMs' => (int) config('ipxe.admin.menu_timeout_ms', 30000),
            'bootDiskFallback' => $this->renderBootDiskFallback(),
            'isKnown' => $isKnown,
        ])->render();
    }

    /**
     * Story 3.2 — AC4.3 — Rend le menu maintenance natif
     * (`resources/views/ipxe/menu/maintenance.blade.php`).
     *
     * Port du legacy `sambaedu/ipxe/maintenance.php` :
     *
     *  - Items rendus identiques quelle que soit la résolution Workstation
     *    (parité legacy `maintenance.php:15` qui ne bloque pas un poste
     *    inconnu — un poste neuf en factory_reset n'a pas d'enrollment).
     *  - 6 items : rescuecd, winpe, factory_reset, shell, retour /ipxe/admin,
     *    exit.
     */
    public function renderMaintenanceMenu(?Workstation $ws, string $ip, string $serverBaseUrl): string
    {
        return $this->viewFactory->make('ipxe.menu.maintenance', [
            'shebang' => self::IPXE_SHEBANG,
            'workstationName' => $ws !== null
                ? $this->sanitizeAscii((string) ($ws->name ?? 'unknown'))
                : 'unknown',
            'ip' => $ip,
            'mac' => $ws !== null ? (string) ($ws->mac ?? '') : '',
            'uuid' => $ws !== null ? $this->sanitizeAscii((string) ($ws->uuid ?? '')) : '',
            'serverBaseUrl' => rtrim($serverBaseUrl, '/'),
            'resolutionX' => (int) config('ipxe.menu.resolution_x', 1024),
            'resolutionY' => (int) config('ipxe.menu.resolution_y', 768),
            'resolutionPng' => (string) config('ipxe.maintenance.background_png', 'png/sysrescuecd.png'),
            'menuTimeoutMs' => (int) config('ipxe.maintenance.menu_timeout_ms', 10000),
            'bootDiskFallback' => $this->renderBootDiskFallback(),
        ])->render();
    }

    /**
     * Rend la chaîne `iseq ${platform} efi && goto uefi || goto legacy ...`
     * iso-legacy `boot_disk()` (`sambaedu/includes/ipxe_functions.inc.php:14-46`).
     *
     * **Méthode publique** pour permettre le test unitaire isolé (DO-10).
     *
     * Lit `config('ipxe.boot_disk.force_uefi_products')` pour la liste des
     * modèles qui doivent forcer le branchement UEFI même en `${platform} != efi`.
     */
    public function renderBootDiskFallback(): string
    {
        $products = (array) config('ipxe.boot_disk.force_uefi_products', []);

        $lines = [];
        $lines[] = 'iseq ${platform} efi && goto uefi || goto legacy';
        $lines[] = ':uefi';
        $lines[] = 'exit 1 || sleep 100';
        $lines[] = ':legacy';
        // Set sp = espace (iPXE n'accepte pas les espaces inline dans iseq).
        $lines[] = 'set sp:hex 20 && set sp ${sp:string}';

        foreach (array_values($products) as $i => $product) {
            $escaped = (string) preg_replace('/ /', '${sp}', (string) $product);
            $lines[] = "iseq \${product} {$escaped} && goto uefi || goto suite{$i}";
            $lines[] = ":suite{$i}";
        }

        $lines[] = 'sanboot --no-describe --drive 0x80 || sleep 100';

        return implode("\n", $lines) . "\n";
    }

    /**
     * Résout le chemin du background PNG (relatif à l'URL serveur).
     */
    private function resolveBackgroundPng(): string
    {
        return (string) config('ipxe.menu.background_png', 'png/ipxe-se4.png');
    }

    /**
     * Strip les caractères non ASCII (le firmware iPXE rend mal l'ASCII
     * étendu — accents fr cassent le menu).
     */
    private function sanitizeAscii(string $value): string
    {
        // Convertit en ASCII en supprimant les chars > 0x7E (et < 0x20 sauf
        // tab/newline pour éviter d'éventuels artefacts).
        $clean = preg_replace('/[^\x20-\x7E\t]/', '?', $value);

        return $clean ?? $value;
    }
}

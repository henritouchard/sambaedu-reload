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
        private readonly LinuxInstallMenuBuilder $linuxMenuBuilder,
        private readonly WindowsInstallMenuBuilder $windowsMenuBuilder,
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
    public function renderHandshake(?string $chainTarget = null, string $serverBaseUrl = ''): string
    {
        return $this->viewFactory->make('ipxe.menu.handshake', [
            'shebang' => self::IPXE_SHEBANG,
            'chainTarget' => $chainTarget,
            'serverBaseUrl' => $serverBaseUrl,
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
            // Story 4.10 kill-switch — désactive l'item login admin tant que
            // l'auth iPXE (validatePassword + droit computer.install) n'est pas
            // restaurée côté `IpxeService::handleAdmin()`. Default false =
            // sûr par défaut.
            'isAdminActive' => (bool) config('ipxe.admin.enabled', false),
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
        $base = rtrim($serverBaseUrl, '/');

        return $this->viewFactory->make('ipxe.menu.admin', [
            'shebang' => self::IPXE_SHEBANG,
            'workstationName' => $isKnown
                ? $this->sanitizeAscii((string) ($ws->name ?? 'unknown'))
                : 'unknown',
            'ip' => $ip,
            'mac' => $isKnown ? (string) ($ws->mac ?? '') : '',
            'uuid' => $isKnown ? $this->sanitizeAscii((string) ($ws->uuid ?? '')) : '',
            'serverBaseUrl' => $base,
            // Story 3.3 — D11 / AC5.2 — variables enrollment.
            'enrollmentBaseUrl' => $base . '/ipxe/enrollment',
            'isEnrollmentActive' => (bool) config('ipxe.enrollment.enabled', true),
            // Story 3.4 — D11 / AC7.3 — variables installation Linux.
            'installLinuxBaseUrl' => $base . '/ipxe/installation-linux',
            'isInstallLinuxActive' => (bool) config('ipxe.linux.enabled', true),
            // Story 3.5 — D11 / AC7.3 — variables installation Windows.
            'installWindowsBaseUrl' => $base . '/ipxe/installation-windows',
            'isInstallWindowsActive' => (bool) config('ipxe.windows.enabled', true),
            'resolutionX' => (int) config('ipxe.menu.resolution_x', 1024),
            'resolutionY' => (int) config('ipxe.menu.resolution_y', 768),
            'resolutionPng' => $this->resolveBackgroundPng(),
            'menuTimeoutMs' => (int) config('ipxe.admin.menu_timeout_ms', 30000),
            'bootDiskFallback' => $this->renderBootDiskFallback(),
            'isKnown' => $isKnown,
        ])->render();
    }

    /**
     * Story 3.4 — D10 / AC6.1 — Rend le menu interactif d'installation
     * Linux (`resources/views/ipxe/menu/installation-linux.blade.php`).
     *
     * Délègue la construction du payload de variables à
     * {@see LinuxInstallMenuBuilder::build()} pour permettre le test unit
     * isolé.
     *
     * **Modes** :
     *  - poste connu (`$ws !== null`)   : menu complet avec 9 items
     *    `install_*` (D11 — config-driven liste).
     *  - poste inconnu (`$ws === null`) : menu erreur D7 + chain
     *    `/ipxe/admin`.
     */
    public function renderInstallationLinuxMenu(
        ?Workstation $ws,
        string $ip,
        string $serverBaseUrl,
    ): string {
        // Post-review #5 — DI directe via constructeur (plus de service locator).
        $variables = $this->linuxMenuBuilder->build($ws, $serverBaseUrl, $ip);

        return $this->viewFactory->make('ipxe.menu.installation-linux', array_merge(
            $variables,
            [
                'shebang' => self::IPXE_SHEBANG,
                'bootDiskFallback' => $this->renderBootDiskFallback(),
            ],
        ))->render();
    }

    /**
     * Story 3.5 — D10 / AC6.1 — Rend le menu interactif d'installation
     * Windows (`resources/views/ipxe/menu/installation-windows.blade.php`).
     *
     * Délègue la construction du payload de variables à
     * {@see WindowsInstallMenuBuilder::build()} pour permettre le test unit
     * isolé.
     *
     * **Modes** :
     *  - poste connu (`$ws !== null`)   : menu complet avec 7 items
     *    `install_win*` (D11 — config-driven liste).
     *  - poste inconnu (`$ws === null`) : menu erreur D7 + chain
     *    `/ipxe/admin`.
     */
    public function renderInstallationWindowsMenu(
        ?Workstation $ws,
        string $ip,
        string $serverBaseUrl,
    ): string {
        $variables = $this->windowsMenuBuilder->build($ws, $serverBaseUrl, $ip);

        return $this->viewFactory->make('ipxe.menu.installation-windows', array_merge(
            $variables,
            [
                'shebang' => self::IPXE_SHEBANG,
                'bootDiskFallback' => $this->renderBootDiskFallback(),
            ],
        ))->render();
    }

    /**
     * Story 3.3 — AC5.1 — Rend le menu d'enrollment "name"
     * (`resources/views/ipxe/enrollment/name.blade.php`).
     *
     * Le menu a 3 modes (déterminés côté template) :
     *
     *  - saisie initiale (`$result === null`) : prompt `read name` iPXE.
     *  - confirmation (`$result->status === SameName`) : echo "deja
     *    enregistree" + chain admin.
     *  - succès création/rename (`Created`/`Renamed`) : echo OK + chain admin.
     *  - erreur (`NameTaken`/`DbError`/`AdError`) : echo ERREUR + chain admin.
     *
     * @param  array<string,mixed>  $variables  Construit via
     *           {@see \App\Ipxe\Services\IpxeEnrollmentMenuBuilder::buildNameMenuVariables()}.
     * @param  \App\Ipxe\Support\EnrollNameResult|null  $result  Résultat
     *           du service (null = première saisie).
     */
    public function renderEnrollmentNameMenu(
        array $variables,
        ?\App\Ipxe\Support\EnrollNameResult $result = null,
    ): string {
        return $this->viewFactory->make('ipxe.enrollment.name', array_merge(
            $variables,
            [
                'shebang' => self::IPXE_SHEBANG,
                'result' => $result,
                'newName' => $result?->sanitizedName ?? '',
            ],
        ))->render();
    }

    /**
     * Story 3.3 — AC5.1 — Rend le menu d'enrollment "byod" (variant simplifié
     * de `name` — pas de gestion `NAME_TAKEN`).
     *
     * @param  array<string,mixed>  $variables
     */
    public function renderEnrollmentByodMenu(
        array $variables,
        bool $logged = false,
        string $sanitizedName = '',
        bool $denied = false,
    ): string {
        return $this->viewFactory->make('ipxe.enrollment.byod', array_merge(
            $variables,
            [
                'shebang' => self::IPXE_SHEBANG,
                'logged' => $logged,
                'sanitizedName' => $sanitizedName,
                'denied' => $denied,
            ],
        ))->render();
    }

    /**
     * Story 3.3 — AC5.1 — Rend le menu interactif d'affectation à une salle
     * physique.
     *
     * 3 modes (déterminés par les paramètres) :
     *  - menu listing (`$assignedRoomName === null` + `$failed === false`).
     *  - succès assignation (`$assignedRoomName !== null`).
     *  - échec (`$failed === true`).
     *
     * @param  array<string,mixed>  $variables
     */
    public function renderEnrollmentRoomMenu(
        array $variables,
        ?string $assignedRoomName = null,
        bool $failed = false,
    ): string {
        return $this->viewFactory->make('ipxe.enrollment.room', array_merge(
            $variables,
            [
                'shebang' => self::IPXE_SHEBANG,
                'assignedRoomName' => $assignedRoomName,
                'failed' => $failed,
            ],
        ))->render();
    }

    /**
     * Story 3.3 — AC5.1 — Rend le menu interactif d'ajout à un parc logique.
     *
     * @param  array<string,mixed>  $variables
     */
    public function renderEnrollmentParcAddMenu(
        array $variables,
        ?string $attachedParcName = null,
        bool $failed = false,
    ): string {
        return $this->viewFactory->make('ipxe.enrollment.parc-add', array_merge(
            $variables,
            [
                'shebang' => self::IPXE_SHEBANG,
                'attachedParcName' => $attachedParcName,
                'failed' => $failed,
            ],
        ))->render();
    }

    /**
     * Story 3.3 — AC5.1 — Rend le menu interactif de retrait d'un parc logique.
     *
     * @param  array<string,mixed>  $variables
     */
    public function renderEnrollmentParcRemoveMenu(
        array $variables,
        ?string $detachedParcName = null,
        bool $failed = false,
    ): string {
        return $this->viewFactory->make('ipxe.enrollment.parc-remove', array_merge(
            $variables,
            [
                'shebang' => self::IPXE_SHEBANG,
                'detachedParcName' => $detachedParcName,
                'failed' => $failed,
            ],
        ))->render();
    }

    /**
     * Story 3.3 — Helper : rend un menu d'erreur générique pour les flows
     * room/parc-add/parc-remove quand le poste est inconnu (D7 —
     * « Erreur poste non enregistre »).
     *
     * Permet aux controllers de garder un code uniforme sans dupliquer le
     * fallback texte.
     */
    public function renderEnrollmentUnknownWorkstation(string $serverBaseUrl): string
    {
        $base = rtrim($serverBaseUrl, '/');
        $body = self::IPXE_SHEBANG . "\n"
            . "params\n"
            . "echo Erreur - poste non encore enregistre.\n"
            . "echo Utilisez (n) Nommer le poste avant d'affecter une salle ou un parc.\n"
            . "sleep 5\n"
            . "chain --replace --autofree {$base}/ipxe/admin##params\n";

        return $body;
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
     * Story 3.7 — AC4.2 — Rend le sous-menu Clonezilla natif
     * (`resources/views/ipxe/menu/clonezilla.blade.php`).
     *
     * Port du legacy `sambaedu/ipxe/clonezilla_menu.php` (80 LOC) — iso
     * `renderMaintenanceMenu` (3.2) :
     *
     *  - 5 items : clonezilla_live, clonezilla_save_sda1_sda2,
     *    clonezilla_restore_sda2_sda1, retour /ipxe/maintenance, exit.
     *  - AC4.5 : poste inconnu (ws=null) → items identiques (parité legacy
     *    `clonezilla_menu.php` qui n'authentifie pas le poste — le menu est
     *    fonctionnel même sans enrollment).
     *  - Timeout = config('ipxe.clonezilla.menu_timeout_ms', 10000) (AC4.3).
     *  - Background PNG = config('ipxe.clonezilla.background_png') (AC4.4).
     *
     * @param  array{workstationName?:string, ip:string, mac?:string, uuid?:string, serverBaseUrl:string}  $context
     */
    public function renderClonezillaMenu(array $context): string
    {
        $serverBaseUrl = rtrim((string) ($context['serverBaseUrl'] ?? ''), '/');

        return $this->viewFactory->make('ipxe.menu.clonezilla', [
            'shebang' => self::IPXE_SHEBANG,
            'workstationName' => isset($context['workstationName'])
                ? $this->sanitizeAscii($context['workstationName'])
                : 'unknown',
            'ip' => (string) ($context['ip'] ?? ''),
            'mac' => isset($context['mac']) ? $this->sanitizeAscii($context['mac']) : '',
            'uuid' => isset($context['uuid']) ? $this->sanitizeAscii($context['uuid']) : '',
            'serverBaseUrl' => $serverBaseUrl,
            'resolutionX' => (int) config('ipxe.menu.resolution_x', 1024),
            'resolutionY' => (int) config('ipxe.menu.resolution_y', 768),
            'resolutionPng' => (string) config('ipxe.clonezilla.background_png', 'png/clonezilla.png'),
            'menuTimeoutMs' => (int) config('ipxe.clonezilla.menu_timeout_ms', 10000),
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
     * Délègue à l'implémentation canonique {@see IpxeHostnameSanitizer::sanitizeForIpxeOutput()}
     * — Unicode-aware + fail-closed sur UTF-8 invalide (cf. F15 review).
     */
    private function sanitizeAscii(string $value): string
    {
        return IpxeHostnameSanitizer::sanitizeForIpxeOutput($value);
    }
}

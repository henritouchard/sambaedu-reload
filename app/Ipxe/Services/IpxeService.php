<?php

declare(strict_types=1);

namespace App\Ipxe\Services;

use App\Ipxe\Contracts\IpxeAuthorizes;
use App\Ipxe\Enums\IpxeAdminAction;
use App\Ipxe\Enums\IpxeMenuKind;
use App\Ipxe\Support\MacAddressNormalizer;
use App\Models\MachineBootLog;
use App\Models\Workstation;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Story 3.1 — D5 / D7 / D10 / AC4.1 / AC4.2 / AC7.3.
 *
 * Orchestrateur de l'endpoint `GET|POST /ipxe/boot` :
 *
 *  1. Extrait `mac`/`uuid`/`product`/`ip` du `Request`.
 *  2. Détecte le **handshake** (mac et uuid vides) → préambule iPXE.
 *  3. Sinon → résolution via {@see WorkstationLocator}.
 *  4. Log structuré sur le channel `ipxe` (D7) avec **préfixes** sur les
 *     valeurs sensibles (6-8 chars max — D7 + AC7.3).
 *  5. Insert `MachineBootLog` avec `action='ipxe_boot'` (D5 — pas de nouvelle
 *     table). Audit T0.6 : `action` est varchar(20) sans CHECK constraint,
 *     `'ipxe_boot'` (9 chars) passe sans escalation.
 *  6. Rendu Blade via {@see IpxeMenuRenderer} + headers D10 (`text/plain`,
 *     `no-store`, `noindex`).
 *
 * **`resolveProgrammedAction()` placeholder** (AC4.2) : retourne toujours
 * `null` en 3.1. Sera enrichi par Story 3.2 qui lira les actions programmées
 * (install, clonage, etc.) depuis une table dédiée ou via les relations
 * `Workstation::appProfiles`/`Workstation::groups`.
 */
final class IpxeService
{
    public function __construct(
        private readonly WorkstationLocator $locator,
        private readonly IpxeMenuRenderer $renderer,
        private readonly IpxeActionResolver $actionResolver,
        private readonly IpxeAuthorizes $authService,
    ) {
    }

    /**
     * Story 4.10 — Helper d'autorisation iPXE.
     *
     * Cas d'usage : tous les endpoints sensibles (admin, maintenance,
     * action/*, installation-linux/windows, clonezilla-menu — et côté
     * enrollment via {@see IpxeEnrollmentOrchestrator}).
     *
     * Si l'auth échoue, on rend l'écran iPXE `auth_failed` (wrap safeRender,
     * headers D10 préservés) et on retourne la Response prête à servir au
     * caller. Sinon retourne `null` et le caller continue son flow normal.
     */
    public function guard(Request $request, string $context): ?Response
    {
        $outcome = $this->authService->authorize($request, $context);
        if ($outcome->status->isAllowed()) {
            return null;
        }

        $ip = (string) ($request->ip() ?? '');
        $mac = (string) $request->input('mac', '');
        $uuid = (string) $request->input('uuid', '');
        $baseUrl = $this->resolveServerBaseUrl($request);

        return $this->safeRender(
            fn (): string => $this->renderer->renderAuthFailed($outcome->status, $baseUrl),
            $ip,
            $mac,
            $uuid,
            IpxeMenuKind::AdminMenu, // tag log générique — kind=auth_failed implicite via context
        );
    }

    /**
     * Point d'entrée principal — appelé par {@see \App\Ipxe\Http\Controllers\IpxeBootController}.
     */
    public function handleBoot(Request $request): Response
    {
        $mac = (string) $request->input('mac', '');
        $uuid = (string) $request->input('uuid', '');
        $product = (string) $request->input('product', '');
        $ip = (string) ($request->ip() ?? '');

        // Cas handshake : MAC ou UUID manquant → préambule de re-paramétrage.
        // Fix review #1 / Q1 Henri — parité iso-legacy stricte `boot.php:26` qui
        // teste `empty($mac) || empty($uuid)`. La condition `||` (au lieu de
        // `&&`) garantit :
        //  - compatibilité firmware iPXE ancien qui ne pose pas toujours
        //    `${uuid}` (BIOS Lenovo M-series, OptiPlex 7010/9010 vieux, ROM
        //    custom). Le serveur force le re-paramétrage au lieu d'accepter
        //    un identifiant partiel ;
        //  - mitigation MAC dupliquée en base (clone disque, VM clonée) qui
        //    sinon sert un menu known du mauvais poste ;
        //  - mitigation usurpation MAC (spoof → menu known) — avec `||` il
        //    faut aussi connaître l'UUID exact ;
        //  - conformité D4 « iso-legacy stricte ».
        if ($mac === '' || $uuid === '') {
            Log::channel($this->channel())->info('ipxe.boot.handshake', [
                'action_type' => 'ipxe.boot.handshake',
                'ip' => $ip,
                'user_agent' => substr((string) $request->userAgent(), 0, 200),
            ]);

            return $this->safeRender(
                fn (): string => $this->renderer->renderHandshake(),
                $ip,
                $mac,
                $uuid,
                IpxeMenuKind::Handshake,
            );
        }

        // D7 — détection input malformé : MAC fournie mais format invalide
        // après normalisation, ou UUID fourni mais vide après trim. Le caller
        // continue quand même (parité legacy tolérante D4) — l'event sert
        // l'observabilité pour distinguer "poste inconnu" de "input corrompu".
        if ($mac !== '' && MacAddressNormalizer::normalize($mac) === null) {
            Log::channel($this->channel())->warning('ipxe.boot.invalid_input', [
                'action_type' => 'ipxe.boot.invalid_input',
                'field' => 'mac',
                'mac_prefix' => substr($mac, 0, 6),
                'ip' => $ip,
            ]);
        }
        if ($uuid !== '' && trim($uuid) === '') {
            Log::channel($this->channel())->warning('ipxe.boot.invalid_input', [
                'action_type' => 'ipxe.boot.invalid_input',
                'field' => 'uuid',
                'uuid_prefix' => substr($uuid, 0, 8),
                'ip' => $ip,
            ]);
        }

        // Cas locate : résolution + log + insert MachineBootLog.
        $workstation = $this->locator->locate($mac, $uuid, $product);

        $this->logBootAttempt($workstation, $mac, $uuid, $product, $ip);
        $this->persistMachineBootLog($workstation, $ip);

        if ($workstation === null) {
            return $this->safeRender(
                fn (): string => $this->renderer->renderUnknown($ip),
                $ip,
                $mac,
                $uuid,
                IpxeMenuKind::Unknown,
            );
        }

        $action = $this->resolveProgrammedAction($workstation);
        $baseUrl = $this->resolveServerBaseUrl($request);

        return $this->safeRender(
            fn (): string => $this->renderer->renderKnown($workstation, $action, $baseUrl),
            $ip,
            $mac,
            $uuid,
            IpxeMenuKind::Known,
        );
    }

    /**
     * Story 3.1 — D7 / D10 — fix review #2/#3.
     *
     * Wrap un appel de rendu Blade dans un try/catch pour garantir que la
     * réponse HTTP reste **toujours** `text/plain` même en cas d'exception
     * (template manquant, variable mal typée, etc.). Sans cette protection,
     * une exception remonte au handler Laravel qui renvoie du HTML/JSON et
     * bloque le firmware iPXE (poste figé au boot, pas de menu).
     *
     * En cas d'erreur : log structuré `ipxe.boot.render_error` (channel
     * `ipxe`, niveau error, préfixes 6/8 chars D7) + fallback minimal iPXE
     * qui affiche un message générique et exit (le firmware retentera au
     * prochain DHCP).
     *
     * @param  callable():string  $render  Closure renvoyant le corps iPXE rendu.
     * @param  IpxeMenuKind  $kind  Type de menu rendu (utilisé comme libellé log).
     *                              Fix review #B3 / Q4 Henri — l'ancien
     *                              paramètre `string` ouvrait la porte aux typos
     *                              `'admin_handsahke'`. L'enum {@see IpxeMenuKind}
     *                              est désormais source de vérité unique.
     */
    private function safeRender(
        callable $render,
        string $ip,
        string $mac,
        string $uuid,
        IpxeMenuKind $kind,
    ): Response {
        try {
            return $this->respond($render());
        } catch (Throwable $e) {
            Log::channel($this->channel())->error('ipxe.boot.render_error', [
                'action_type' => 'ipxe.boot.render_error',
                'kind' => $kind->value,
                'exception_class' => $e::class,
                'message' => substr($e->getMessage(), 0, 200),
                'ip' => $ip,
                'mac_prefix' => $mac !== '' ? substr($mac, 0, 6) : '',
                'uuid_prefix' => $uuid !== '' ? substr($uuid, 0, 8) : '',
            ]);

            return $this->respond("#!ipxe\necho Erreur serveur SE4FS - boot disque dans 10s\nsleep 10\nexit 0\n");
        }
    }

    /**
     * Story 3.2 — AC3.1 — Orchestre la route native `GET|POST /ipxe/admin`.
     *
     * Flow :
     *
     *  1. Extrait `mac`/`uuid`/`product`/`ip`.
     *  2. Handshake si MAC ou UUID manquant (chainTarget=`'admin'`).
     *  3. Sinon résolution `WorkstationLocator` → menu admin (connu ou
     *     dégradé D7 si poste inconnu).
     *  4. Log structuré `ipxe.admin.menu_rendered` + insert `MachineBootLog`
     *     (`action='ipxe_admin'`).
     *  5. Headers iso D10 (`text/plain`, `no-store`, `noindex`).
     *  6. safeRender wrap — fallback minimal iPXE en cas d'exception
     *     template.
     */
    public function handleAdmin(Request $request): Response
    {
        $mac = (string) $request->input('mac', '');
        $uuid = (string) $request->input('uuid', '');
        $product = (string) $request->input('product', '');
        $ip = (string) ($request->ip() ?? '');

        if ($mac === '' || $uuid === '') {
            Log::channel($this->channel())->info('ipxe.admin.handshake', [
                'action_type' => 'ipxe.admin.handshake',
                'ip' => $ip,
                'user_agent' => substr((string) $request->userAgent(), 0, 200),
            ]);

            return $this->safeRender(
                fn (): string => $this->renderer->renderHandshake('admin'),
                $ip,
                $mac,
                $uuid,
                IpxeMenuKind::AdminHandshake,
            );
        }

        // Story 4.10 — Auth iso-legacy (validatePassword AD + permission
        // Spatie `computer.install`). Refus → écran `auth_failed` + chain
        // back boot. Aucun leak de password (cf. IpxeAuthService).
        if (($denied = $this->guard($request, 'admin')) !== null) {
            return $denied;
        }

        $workstation = $this->locator->locate($mac, $uuid, $product);
        $this->logMenuRendered('ipxe.admin.menu_rendered', $workstation, $mac, $uuid, $ip);
        $this->persistEndpointLog($workstation, $ip, 'ipxe_admin', 'ipxe');

        $baseUrl = $this->resolveServerBaseUrl($request);

        return $this->safeRender(
            fn (): string => $this->renderer->renderAdminMenu($workstation, $ip, $baseUrl),
            $ip,
            $mac,
            $uuid,
            IpxeMenuKind::AdminMenu,
        );
    }

    /**
     * Story 3.2 — AC3.2 — Orchestre la route native `GET|POST /ipxe/maintenance`.
     *
     * Flow identique à {@see handleAdmin()} mais :
     *
     *  - Résolution Workstation **non-bloquante** (parité legacy
     *    `maintenance.php:15` — un poste inconnu peut consulter le menu
     *    maintenance, notamment pour factory_reset).
     *  - Menu identique connu/inconnu (pas de variant).
     *  - Log `ipxe.maintenance.menu_rendered` + `MachineBootLog
     *    action='ipxe_maintenance'`.
     */
    public function handleMaintenance(Request $request): Response
    {
        $mac = (string) $request->input('mac', '');
        $uuid = (string) $request->input('uuid', '');
        $product = (string) $request->input('product', '');
        $ip = (string) ($request->ip() ?? '');

        if ($mac === '' || $uuid === '') {
            Log::channel($this->channel())->info('ipxe.maintenance.handshake', [
                'action_type' => 'ipxe.maintenance.handshake',
                'ip' => $ip,
                'user_agent' => substr((string) $request->userAgent(), 0, 200),
            ]);

            return $this->safeRender(
                fn (): string => $this->renderer->renderHandshake('maintenance'),
                $ip,
                $mac,
                $uuid,
                IpxeMenuKind::MaintenanceHandshake,
            );
        }

        // Story 4.10 — Auth obligatoire (cf. handleAdmin).
        if (($denied = $this->guard($request, 'maintenance')) !== null) {
            return $denied;
        }

        $workstation = $this->locator->locate($mac, $uuid, $product);
        $this->logMenuRendered('ipxe.maintenance.menu_rendered', $workstation, $mac, $uuid, $ip);
        $this->persistEndpointLog($workstation, $ip, 'ipxe_maintenance', 'ipxe');

        $baseUrl = $this->resolveServerBaseUrl($request);

        return $this->safeRender(
            fn (): string => $this->renderer->renderMaintenanceMenu($workstation, $ip, $baseUrl),
            $ip,
            $mac,
            $uuid,
            IpxeMenuKind::MaintenanceMenu,
        );
    }

    /**
     * Story 3.7 — AC4.1 — Orchestre la route native
     * `GET|POST /ipxe/clonezilla-menu`.
     *
     * Flow iso `handleMaintenance` (3.2) :
     *
     *  1. Handshake si MAC/UUID manquant (chainTarget='clonezilla-menu').
     *  2. Résolution Workstation via {@see WorkstationLocator}.
     *  3. Log handshake `ipxe.clonezilla.handshake` (D9 — pattern aligné
     *     Epic 3 post-review #8 : `ipxe.<domain>.handshake`).
     *  4. Rendu via {@see IpxeMenuRenderer::renderClonezillaMenu()} (AC4.2).
     *  5. Log render `ipxe.clonezilla.menu_rendered` (pattern aligné Epic 3
     *     post-review #8 : `ipxe.<domain>.menu_rendered`).
     *  6. safeRender wrap.
     */
    public function handleClonezillaMenu(Request $request): Response
    {
        $mac = (string) $request->input('mac', '');
        $uuid = (string) $request->input('uuid', '');
        $product = (string) $request->input('product', '');
        $ip = (string) ($request->ip() ?? '');

        if ($mac === '' || $uuid === '') {
            // Post-review #8 — pattern Epic 3 aligné : `ipxe.<domain>.handshake`.
            Log::channel($this->channel())->info('ipxe.clonezilla.handshake', [
                'action_type' => 'ipxe.clonezilla.handshake',
                'ip' => $ip,
                'user_agent' => substr((string) $request->userAgent(), 0, 200),
            ]);

            return $this->safeRender(
                fn (): string => $this->renderer->renderHandshake('clonezilla-menu'),
                $ip,
                $mac,
                $uuid,
                IpxeMenuKind::ClonezillaMenuHandshake,
            );
        }

        // Story 4.10 — Auth obligatoire (cf. handleAdmin).
        if (($denied = $this->guard($request, 'clonezilla')) !== null) {
            return $denied;
        }

        $workstation = $this->locator->locate($mac, $uuid, $product);
        // Post-review #8 — pattern Epic 3 aligné : `ipxe.<domain>.menu_rendered`
        // (iso `ipxe.admin.menu_rendered`, `ipxe.maintenance.menu_rendered`,
        // `ipxe.install_linux.menu_rendered`, `ipxe.install_windows.menu_rendered`).
        $this->logMenuRendered('ipxe.clonezilla.menu_rendered', $workstation, $mac, $uuid, $ip);
        $this->persistEndpointLog($workstation, $ip, 'ipxe_clonezilla_menu', 'ipxe');

        $baseUrl = $this->resolveServerBaseUrl($request);

        return $this->safeRender(
            fn (): string => $this->renderer->renderClonezillaMenu([
                'workstationName' => (string) ($workstation?->name ?? 'unknown'),
                'ip' => $ip,
                'mac' => $mac,
                'uuid' => $uuid,
                'serverBaseUrl' => $baseUrl,
            ]),
            $ip,
            $mac,
            $uuid,
            IpxeMenuKind::ClonezillaMenu,
        );
    }

    /**
     * Story 3.2 — AC3.3 — Orchestre la route native
     * `GET|POST /ipxe/action/{action}`.
     *
     * Flow :
     *
     *  1. Validation enum whitelist `IpxeAdminAction::tryFrom($action)`
     *     (D9) :
     *     - `null` → log warning `ipxe.action.unknown_action` + `abort(404)`.
     *     - case  → continue.
     *  2. Handshake si MAC/UUID manquant (chainTarget=`'action/<value>'`).
     *  3. Résolution Workstation **non-bloquante** (parité legacy
     *     `action.php:28`).
     *  4. Log `ipxe.action.dispatched` + insert `MachineBootLog`
     *     (`action='ipxe_action'`, `initiated_by='ipxe:<value>'`).
     *  5. Rendu via {@see IpxeActionResolver}.
     *  6. safeRender wrap + log `ipxe.action.render_error` en cas
     *     d'exception.
     */
    public function handleAction(Request $request, string $action): Response
    {
        $mac = (string) $request->input('mac', '');
        $uuid = (string) $request->input('uuid', '');
        $product = (string) $request->input('product', '');
        $ip = (string) ($request->ip() ?? '');

        // D9 — whitelist enum stricte.
        $adminAction = IpxeAdminAction::tryFrom($action);
        if ($adminAction === null) {
            Log::channel($this->channel())->warning('ipxe.action.unknown_action', [
                'action_type' => 'ipxe.action.unknown_action',
                'ip' => $ip,
                'mac_prefix' => $mac !== '' ? substr($mac, 0, 6) : '',
                'uuid_prefix' => $uuid !== '' ? substr($uuid, 0, 8) : '',
                // Sanitize ASCII + tronque 32 chars : un attaquant peut poser
                // n'importe quel input. On veut tracer sans casser le log.
                'action_requested' => $this->sanitizeActionRequested($action),
            ]);

            throw new NotFoundHttpException('Unknown iPXE action.');
        }

        if ($mac === '' || $uuid === '') {
            Log::channel($this->channel())->info('ipxe.action.handshake', [
                'action_type' => 'ipxe.action.handshake',
                'ip' => $ip,
                'action' => $adminAction->value,
                'user_agent' => substr((string) $request->userAgent(), 0, 200),
            ]);

            return $this->safeRender(
                // URL absolue (pas un chain relatif) : `/ipxe/action/{x}` est a
                // 2 niveaux, donc un relatif `action/{x}` depuis l'URI courante
                // doublerait le chemin (`/ipxe/action/action/{x}`) -> 404 -> reboot.
                fn (): string => $this->renderer->renderHandshake(
                    $this->resolveServerBaseUrl($request) . '/ipxe/action/' . $adminAction->value,
                ),
                $ip,
                $mac,
                $uuid,
                IpxeMenuKind::ActionHandshake,
            );
        }

        // Story 4.10 — Auth obligatoire pour TOUTES les actions whitelistées
        // (rescuecd, winpe, factory_reset, clonezilla_*, gparted, hdt,
        // memtest86plus). Inclut les actions destructrices (factory_reset
        // écrase sda1). Cf. handleAdmin.
        if (($denied = $this->guard($request, 'action')) !== null) {
            return $denied;
        }

        $workstation = $this->locator->locate($mac, $uuid, $product);

        Log::channel($this->channel())->info('ipxe.action.dispatched', [
            'action_type' => 'ipxe.action.dispatched',
            'ip' => $ip,
            'mac_prefix' => $mac !== '' ? substr($mac, 0, 6) : '',
            'uuid_prefix' => $uuid !== '' ? substr($uuid, 0, 8) : '',
            'workstation_id' => $workstation?->id,
            'action' => $adminAction->value,
        ]);

        // Fix review #3 / Q1 Henri — log warning dédié pour `factory_reset`.
        // Cette action écrase `sda1` via Clonezilla sans confirmation iPXE
        // (parité legacy `clz_rest_sda2_sur_sda1.php`). Un event warning
        // séparé permet à SIEM/observabilité de filtrer/alerter sur cette
        // action destructive sans relire chaque `ipxe.action.dispatched`.
        if ($adminAction === IpxeAdminAction::FactoryReset) {
            Log::channel($this->channel())->warning('ipxe.action.factory_reset_dispatched', [
                'action_type' => 'ipxe.action.factory_reset_dispatched',
                'ip' => $ip,
                'mac_prefix' => $mac !== '' ? substr($mac, 0, 6) : '',
                'uuid_prefix' => $uuid !== '' ? substr($uuid, 0, 8) : '',
                'workstation_id' => $workstation?->id,
            ]);
        }

        // Story 3.7 — D11 / AC8.1-8.4 — utiliser bootLogAction() pour les actions
        // 3.7 (clonezilla/gparted/hdt/memtest) afin d'obtenir des valeurs
        // distinctes dans machine_boot_logs.action. Les actions 3.2-3.5 conservent
        // 'ipxe_action' (pattern historique — pas de migration).
        $this->persistEndpointLog(
            $workstation,
            $ip,
            $adminAction->bootLogAction(),
            'ipxe:' . $adminAction->value,
        );

        return $this->safeActionRender(
            fn (): string => $this->actionResolver->resolve($adminAction, $workstation, $request),
            $ip,
            $mac,
            $uuid,
            $adminAction,
        );
    }

    /**
     * Story 3.4 — D2 / AC4.1 — Orchestre la route native
     * `GET|POST /ipxe/installation-linux`.
     *
     * Flow iso 3.2/3.3 :
     *
     *  1. Extrait `mac`/`uuid`/`product`/`ip`.
     *  2. Handshake si MAC/UUID manquant (chainTarget=`'installation-linux'`).
     *  3. Résolution `WorkstationLocator`. Poste inconnu = menu erreur D7
     *     (délégué au renderer `renderInstallationLinuxMenu` qui rend l'écran
     *     d'erreur si `$ws === null`).
     *  4. Log structuré `ipxe.install_linux.menu_rendered` + insert
     *     `MachineBootLog` (`action='ipxe_install_linux'`).
     *  5. Headers iso D10 (`text/plain`, `no-store`, `noindex`).
     *  6. safeRender wrap — fallback minimal iPXE en cas d'exception template.
     */
    public function handleInstallationLinuxMenu(Request $request): Response
    {
        $mac = (string) $request->input('mac', '');
        $uuid = (string) $request->input('uuid', '');
        $product = (string) $request->input('product', '');
        $ip = (string) ($request->ip() ?? '');

        if ($mac === '' || $uuid === '') {
            Log::channel($this->channel())->info('ipxe.install_linux.handshake', [
                'action_type' => 'ipxe.install_linux.handshake',
                'ip' => $ip,
                'user_agent' => substr((string) $request->userAgent(), 0, 200),
            ]);

            return $this->safeRender(
                fn (): string => $this->renderer->renderHandshake('installation-linux'),
                $ip,
                $mac,
                $uuid,
                IpxeMenuKind::InstallationLinuxHandshake,
            );
        }

        // Story 4.10 — Auth obligatoire (cf. handleAdmin).
        if (($denied = $this->guard($request, 'install_linux')) !== null) {
            return $denied;
        }

        $workstation = $this->locator->locate($mac, $uuid, $product);
        $this->logMenuRendered('ipxe.install_linux.menu_rendered', $workstation, $mac, $uuid, $ip);
        $this->persistEndpointLog($workstation, $ip, 'ipxe_install_linux', 'ipxe');

        $baseUrl = $this->resolveServerBaseUrl($request);

        return $this->safeRender(
            fn (): string => $this->renderer->renderInstallationLinuxMenu($workstation, $ip, $baseUrl),
            $ip,
            $mac,
            $uuid,
            IpxeMenuKind::InstallationLinuxMenu,
        );
    }

    /**
     * Story 3.5 — D2 / AC4.1 — Orchestre la route native
     * `GET|POST /ipxe/installation-windows`.
     *
     * Flow iso 3.4 `handleInstallationLinuxMenu()` :
     *
     *  1. Extrait `mac`/`uuid`/`product`/`ip`.
     *  2. Handshake si MAC/UUID manquant (chainTarget=`'installation-windows'`).
     *  3. Résolution `WorkstationLocator`. Poste inconnu = menu erreur D7
     *     (délégué au renderer qui rend l'écran d'erreur si `$ws === null`).
     *  4. Log structuré `ipxe.install_windows.menu_rendered` + insert
     *     `MachineBootLog` (`action='ipxe_install_win'`).
     *  5. Headers iso D10 (`text/plain`, `no-store`, `noindex`).
     *  6. safeRender wrap — fallback minimal iPXE en cas d'exception template.
     */
    public function handleInstallationWindowsMenu(Request $request): Response
    {
        $mac = (string) $request->input('mac', '');
        $uuid = (string) $request->input('uuid', '');
        $product = (string) $request->input('product', '');
        $ip = (string) ($request->ip() ?? '');

        if ($mac === '' || $uuid === '') {
            Log::channel($this->channel())->info('ipxe.install_windows.handshake', [
                'action_type' => 'ipxe.install_windows.handshake',
                'ip' => $ip,
                'user_agent' => substr((string) $request->userAgent(), 0, 200),
            ]);

            return $this->safeRender(
                fn (): string => $this->renderer->renderHandshake('installation-windows'),
                $ip,
                $mac,
                $uuid,
                IpxeMenuKind::InstallationWindowsHandshake,
            );
        }

        // Story 4.10 — Auth obligatoire (cf. handleAdmin).
        if (($denied = $this->guard($request, 'install_windows')) !== null) {
            return $denied;
        }

        $workstation = $this->locator->locate($mac, $uuid, $product);
        $this->logMenuRendered('ipxe.install_windows.menu_rendered', $workstation, $mac, $uuid, $ip);
        $this->persistEndpointLog($workstation, $ip, 'ipxe_install_win', 'ipxe');

        $baseUrl = $this->resolveServerBaseUrl($request);

        return $this->safeRender(
            fn (): string => $this->renderer->renderInstallationWindowsMenu($workstation, $ip, $baseUrl),
            $ip,
            $mac,
            $uuid,
            IpxeMenuKind::InstallationWindowsMenu,
        );
    }

    /**
     * Story 3.1 — AC4.2.
     *
     * **Placeholder** historique pour le mécanisme `action` programmée
     * (install Linux/Windows, clonezilla, factory reset) qui sera implémenté
     * par les stories 3.4-3.7. Retourne TOUJOURS `null` en 3.1/3.2.
     *
     * Note 3.2 : le mécanisme « action programmée DB-driven » est distinct
     * des actions admin whitelistées (handleAction + IpxeAdminAction). Celles-ci
     * sont déclenchées explicitement depuis le menu admin/maintenance, pas
     * pré-programmées en base.
     *
     * @param  Workstation  $ws  Modèle Eloquent (avec relations eager-loaded
     *                           par le locator).
     * @return array<string,mixed>|null  Toujours `null` en 3.1/3.2.
     */
    public function resolveProgrammedAction(Workstation $ws): ?array
    {
        // Stories 3.4-3.7 surchargeront/enrichiront cette méthode.
        return null;
    }

    /**
     * Insère une row `MachineBootLog` avec `action='ipxe_boot'` (D5).
     *
     * Encapsule l'écriture en `try/catch` — un échec d'insert log ne doit
     * jamais bloquer la réponse iPXE (un poste qui boot ne doit pas être
     * laissé sans menu parce qu'on a un souci DB transitoire).
     */
    private function persistMachineBootLog(?Workstation $workstation, string $ip): void
    {
        try {
            $now = Carbon::now();
            MachineBootLog::query()->create([
                'workstation_id' => $workstation?->id,
                'machine_name' => $workstation !== null
                    ? strtolower((string) $workstation->name)
                    : 'unknown:' . $ip,
                'action' => 'ipxe_boot',
                'initiated_by' => 'ipxe',
                'success' => true,
                'started_at' => $now,
                'stopped_at' => $now,
            ]);
        } catch (Throwable $e) {
            // Best-effort : on log l'échec mais on continue (le menu doit
            // être servi même si la DB log est en panne transitoire).
            Log::channel($this->channel())->warning('ipxe.boot.machine_boot_log_failure', [
                'action_type' => 'ipxe.boot.machine_boot_log_failure',
                'exception_class' => $e::class,
                'message' => substr($e->getMessage(), 0, 200),
                'ip' => $ip,
            ]);
        }
    }

    /**
     * Story 3.2 — D11 / AC3.1 / AC3.2 / AC3.3.
     *
     * Insère une row `MachineBootLog` pour les endpoints 3.2 avec une
     * `action` extensible (`'ipxe_admin'`, `'ipxe_maintenance'`,
     * `'ipxe_action'`) et un `initiated_by` configurable (`'ipxe'` ou
     * `'ipxe:<action_value>'`).
     *
     * Audit T0.6 : `action` est `varchar(20)` sans CHECK ; les 3 valeurs 3.2
     * (10/16/11 chars) passent. `initiated_by` est `varchar(100)` ; la valeur
     * la plus longue (`ipxe:factory_reset`, 19 chars) passe sans risque.
     *
     * Failover : un échec d'insert n'interrompt pas le rendu iPXE (best-effort).
     */
    private function persistEndpointLog(
        ?Workstation $workstation,
        string $ip,
        string $action,
        string $initiatedBy,
    ): void {
        try {
            $now = Carbon::now();
            MachineBootLog::query()->create([
                'workstation_id' => $workstation?->id,
                'machine_name' => $workstation !== null
                    ? strtolower((string) $workstation->name)
                    : 'unknown:' . $ip,
                'action' => $action,
                'initiated_by' => $initiatedBy,
                'success' => true,
                'started_at' => $now,
                'stopped_at' => $now,
            ]);
        } catch (Throwable $e) {
            Log::channel($this->channel())->warning('ipxe.machine_boot_log_failure', [
                'action_type' => 'ipxe.machine_boot_log_failure',
                'endpoint_action' => $action,
                'exception_class' => $e::class,
                'message' => substr($e->getMessage(), 0, 200),
                'ip' => $ip,
            ]);
        }
    }

    /**
     * Story 3.2 — D8 — Émet le log info `ipxe.admin.menu_rendered` ou
     * `ipxe.maintenance.menu_rendered` selon `$event`.
     *
     * Préfixes obligatoires iso 3.1 AC7.3 (MAC 6 chars, UUID 8 chars, name
     * 6 chars). Variant `known|unknown` selon résolution Workstation.
     */
    private function logMenuRendered(
        string $event,
        ?Workstation $workstation,
        string $mac,
        string $uuid,
        string $ip,
    ): void {
        Log::channel($this->channel())->info($event, [
            'action_type' => $event,
            'ip' => $ip,
            'mac_prefix' => $mac !== '' ? substr($mac, 0, 6) : '',
            'uuid_prefix' => $uuid !== '' ? substr($uuid, 0, 8) : '',
            'workstation_id' => $workstation?->id,
            'workstation_name_prefix' => $workstation !== null
                ? substr((string) ($workstation->name ?? ''), 0, 6)
                : '',
            'menu_variant' => $workstation !== null ? 'known' : 'unknown',
        ]);
    }

    /**
     * Story 3.2 — D8 — Wrap le rendu d'une action whitelistée dans un
     * try/catch dédié qui émet l'event `ipxe.action.render_error` (au lieu
     * de `ipxe.boot.render_error` du wrap générique).
     */
    private function safeActionRender(
        callable $render,
        string $ip,
        string $mac,
        string $uuid,
        IpxeAdminAction $action,
    ): Response {
        try {
            return $this->respond($render());
        } catch (Throwable $e) {
            Log::channel($this->channel())->error('ipxe.action.render_error', [
                'action_type' => 'ipxe.action.render_error',
                'kind' => 'action_resolver',
                'action' => $action->value,
                'exception_class' => $e::class,
                'message' => substr($e->getMessage(), 0, 200),
                'ip' => $ip,
                'mac_prefix' => $mac !== '' ? substr($mac, 0, 6) : '',
                'uuid_prefix' => $uuid !== '' ? substr($uuid, 0, 8) : '',
            ]);

            return $this->respond("#!ipxe\necho Erreur serveur SE4FS - boot disque dans 10s\nsleep 10\nexit 0\n");
        }
    }

    /**
     * Story 3.2 — D8 — Sanitize une action requested hors whitelist pour le
     * log warning `ipxe.action.unknown_action`. Tronque 32 chars + remplace
     * tout char non ASCII par `?` (parité {@see IpxeMenuRenderer::sanitizeAscii()}).
     */
    private function sanitizeActionRequested(string $action): string
    {
        $truncated = substr($action, 0, 32);
        $clean = preg_replace('/[^\x20-\x7E]/', '?', $truncated);

        return $clean ?? $truncated;
    }

    /**
     * Émet un log info `ipxe.boot.known_workstation` ou
     * `ipxe.boot.unknown_workstation` selon le résultat de la résolution.
     *
     * **Préfixes obligatoires** sur les valeurs sensibles (AC7.3) :
     *
     *  - MAC : 6 premiers chars (`xx:xx:`)
     *  - UUID : 8 premiers chars
     *  - Product : 8 premiers chars
     *  - Workstation name : 6 premiers chars
     *
     * IP loggée en clair (LAN scolaire, parité 16.10 D13).
     */
    private function logBootAttempt(
        ?Workstation $workstation,
        string $mac,
        string $uuid,
        string $product,
        string $ip,
    ): void {
        $context = [
            'ip' => $ip,
            'mac_prefix' => $mac !== '' ? substr($mac, 0, 6) : '',
            'uuid_prefix' => $uuid !== '' ? substr($uuid, 0, 8) : '',
            'product_prefix' => $product !== '' ? substr($product, 0, 8) : '',
        ];

        if ($workstation === null) {
            Log::channel($this->channel())->info('ipxe.boot.unknown_workstation', array_merge([
                'action_type' => 'ipxe.boot.unknown_workstation',
            ], $context));

            return;
        }

        Log::channel($this->channel())->info('ipxe.boot.known_workstation', array_merge([
            'action_type' => 'ipxe.boot.known_workstation',
            'workstation_id' => (int) $workstation->id,
            'workstation_name_prefix' => substr((string) ($workstation->name ?? ''), 0, 6),
            'menu_kind' => 'known',
        ], $context));
    }

    /**
     * Wrap la chaîne iPXE dans une Response HTTP avec les headers D10 :
     *
     *  - `Content-Type: text/plain; charset=utf-8`
     *  - `Cache-Control: no-store`
     *  - `X-Robots-Tag: noindex`
     */
    private function respond(string $body): Response
    {
        return (new Response($body, 200))
            ->header('Content-Type', 'text/plain; charset=utf-8')
            ->header('Cache-Control', 'no-store')
            ->header('X-Robots-Tag', 'noindex');
    }

    /**
     * Résout l'URL de base du SE4FS pour la construction des chains iPXE
     * du menu `known`.
     *
     * Priorité : `config('ipxe.se4fs_url')` (override env) → schema+host du
     * `Request` → fallback `http://se4fs`.
     */
    private function resolveServerBaseUrl(Request $request): string
    {
        $configured = (string) config('ipxe.se4fs_url', '');
        if ($configured !== '') {
            return rtrim($configured, '/');
        }

        $schemeAndHost = (string) ($request->getSchemeAndHttpHost() ?? '');
        if ($schemeAndHost !== '') {
            return $schemeAndHost;
        }

        return 'http://se4fs';
    }

    /**
     * Channel Monolog dédié (D7).
     */
    private function channel(): string
    {
        return (string) config('ipxe.log.channel', 'ipxe');
    }
}

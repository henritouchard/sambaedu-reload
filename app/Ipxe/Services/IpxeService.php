<?php

declare(strict_types=1);

namespace App\Ipxe\Services;

use App\Ipxe\Support\MacAddressNormalizer;
use App\Models\MachineBootLog;
use App\Models\Workstation;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
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
    ) {
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
                'handshake',
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
                'unknown',
            );
        }

        $action = $this->resolveProgrammedAction($workstation);
        $baseUrl = $this->resolveServerBaseUrl($request);

        return $this->safeRender(
            fn (): string => $this->renderer->renderKnown($workstation, $action, $baseUrl),
            $ip,
            $mac,
            $uuid,
            'known',
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
     * @param  string  $kind  `handshake`|`unknown`|`known` — pour le log.
     */
    private function safeRender(
        callable $render,
        string $ip,
        string $mac,
        string $uuid,
        string $kind,
    ): Response {
        try {
            return $this->respond($render());
        } catch (Throwable $e) {
            Log::channel($this->channel())->error('ipxe.boot.render_error', [
                'action_type' => 'ipxe.boot.render_error',
                'kind' => $kind,
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
     * Story 3.1 — AC4.2.
     *
     * **Placeholder** pour Story 3.2. En 3.1, retourne TOUJOURS `null` — le
     * mécanisme `action` programmée (install Linux/Windows, clonezilla,
     * factory reset) sera implémenté par 3.2 qui enrichira cette méthode
     * (lecture d'une table dédiée ou des relations `appProfiles`).
     *
     * @param  Workstation  $ws  Modèle Eloquent (avec relations eager-loaded
     *                           par le locator).
     * @return array<string,mixed>|null  Toujours `null` en 3.1.
     */
    public function resolveProgrammedAction(Workstation $ws): ?array
    {
        // Story 3.2 surchargera/enrichira cette méthode.
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

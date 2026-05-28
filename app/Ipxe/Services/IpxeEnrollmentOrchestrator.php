<?php

declare(strict_types=1);

namespace App\Ipxe\Services;

use App\Ipxe\Enums\IpxeEnrollmentFlow;
use App\Ipxe\Enums\IpxeMenuKind;
use App\Ipxe\Support\MacAddressNormalizer;
use App\Models\Workstation;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Story 3.3 — D5 / AC7.1.
 *
 * Orchestre les 5 endpoints `/ipxe/enrollment/*` :
 *
 *  1. Extrait `mac`/`uuid`/`product`/`ip`/`platform` du `Request`.
 *  2. Gère le handshake (MAC ou UUID vide → render préambule).
 *  3. Délègue au {@see WorkstationEnrollmentService} + au
 *     {@see IpxeEnrollmentMenuBuilder} + au {@see IpxeMenuRenderer}.
 *  4. Wrap try/catch + headers iso D10 (text/plain, no-store, noindex)
 *     iso 3.1/3.2.
 *
 * **Séparation** : ne touche pas à `IpxeService` (3.1/3.2 — menus
 * boot/admin/maintenance/action). Le cycle de vie enrollment est distinct.
 */
final class IpxeEnrollmentOrchestrator
{
    public function __construct(
        private readonly WorkstationLocator $locator,
        private readonly WorkstationEnrollmentService $enrollmentService,
        private readonly IpxeEnrollmentMenuBuilder $menuBuilder,
        private readonly IpxeMenuRenderer $renderer,
        private readonly IpxeHostnameSanitizer $hostnameSanitizer,
    ) {
    }

    /**
     * Flow `/ipxe/enrollment/name`.
     */
    public function handleName(Request $request): Response
    {
        [$mac, $uuid, $product, $ip] = $this->extractCommonParams($request);
        $platform = (string) $request->input('platform', 'legacy');

        if ($mac === '' || $uuid === '') {
            $this->logHandshake($request, IpxeEnrollmentFlow::Name);

            return $this->safeRender(
                fn (): string => $this->renderer->renderHandshake('name'),
                IpxeMenuKind::Handshake,
                $ip,
                $mac,
                $uuid,
            );
        }

        $newName = (string) $request->input('new_name', '');
        $existing = $this->locator->locate($mac, $uuid, $product);
        $serverBaseUrl = $this->resolveServerBaseUrl($request);

        // Cas saisie initiale (sans new_name) → render menu de saisie.
        if ($newName === '') {
            $variables = $this->menuBuilder->buildNameMenuVariables(
                $existing,
                $mac,
                $uuid,
                $platform,
                $ip,
                $serverBaseUrl,
            );

            $this->log('ipxe.enrollment.name.handshake', [
                'action_type' => 'ipxe.enrollment.name.handshake',
                'ip' => $ip,
                'mac_prefix' => substr($mac, 0, 6),
                'uuid_prefix' => substr($uuid, 0, 8),
                'workstation_id' => $existing?->id,
            ]);

            return $this->safeRender(
                fn (): string => $this->renderer->renderEnrollmentNameMenu($variables, null),
                IpxeMenuKind::Handshake,
                $ip,
                $mac,
                $uuid,
            );
        }

        // Cas saisie posée → orchestre l'enrollment + rend résultat.
        $result = $this->enrollmentService->enrollName(
            $newName,
            $mac,
            $uuid,
            $platform,
            $ip,
            $existing,
        );

        $variables = $this->menuBuilder->buildNameMenuVariables(
            $result->workstation ?? $existing,
            $mac,
            $uuid,
            $platform,
            $ip,
            $serverBaseUrl,
        );

        return $this->safeRender(
            fn (): string => $this->renderer->renderEnrollmentNameMenu($variables, $result),
            IpxeMenuKind::Default_,
            $ip,
            $mac,
            $uuid,
        );
    }

    /**
     * Flow `/ipxe/enrollment/byod` (stub 3.3 — extension 3.4).
     */
    public function handleByod(Request $request): Response
    {
        [$mac, $uuid, $product, $ip] = $this->extractCommonParams($request);
        $platform = (string) $request->input('platform', 'legacy');

        if ($mac === '' || $uuid === '') {
            $this->logHandshake($request, IpxeEnrollmentFlow::Byod);

            return $this->safeRender(
                fn (): string => $this->renderer->renderHandshake('byod'),
                IpxeMenuKind::Handshake,
                $ip,
                $mac,
                $uuid,
            );
        }

        $newName = (string) $request->input('new_name', '');
        $existing = $this->locator->locate($mac, $uuid, $product);
        $serverBaseUrl = $this->resolveServerBaseUrl($request);
        $variables = $this->menuBuilder->buildByodMenuVariables(
            $existing,
            $mac,
            $uuid,
            $platform,
            $ip,
            $serverBaseUrl,
        );

        // Q1 (review 3.3) : iso-legacy `enregistrement_byod.php:72-81` — un poste
        // déjà connu en AD ne doit pas pouvoir BYOD. Rejet "acces refuse" + chain
        // boot. Log audit dédié pour suivre les tentatives.
        if ($existing !== null) {
            $this->enrollmentService->logByodDenied($mac, $uuid, $ip);

            return $this->safeRender(
                fn (): string => $this->renderer->renderEnrollmentByodMenu($variables, false, '', true),
                IpxeMenuKind::Default_,
                $ip,
                $mac,
                $uuid,
            );
        }

        if ($newName === '') {
            return $this->safeRender(
                fn (): string => $this->renderer->renderEnrollmentByodMenu($variables, false, ''),
                IpxeMenuKind::Handshake,
                $ip,
                $mac,
                $uuid,
            );
        }

        $this->enrollmentService->logByodEnrollment($newName, $mac, $uuid, $ip);

        // Opus-1 / Q2 (review 3.3) : sanitize + validation isValidHostname pour bloquer
        // toute injection iPXE (newline → kernel http://evil) côté affichage Blade.
        $sanitized = $this->hostnameSanitizer->sanitize($newName);
        if (! $this->hostnameSanitizer->isValidHostname($sanitized)) {
            // Cas dégradé : on retourne le menu de saisie initial sans `logged=true`
            // (le service a déjà loggé `byod.rejected_invalid` côté audit).
            return $this->safeRender(
                fn (): string => $this->renderer->renderEnrollmentByodMenu($variables, false, ''),
                IpxeMenuKind::Default_,
                $ip,
                $mac,
                $uuid,
            );
        }

        return $this->safeRender(
            fn (): string => $this->renderer->renderEnrollmentByodMenu($variables, true, $sanitized),
            IpxeMenuKind::Default_,
            $ip,
            $mac,
            $uuid,
        );
    }

    /**
     * Flow `/ipxe/enrollment/room`.
     */
    public function handleRoom(Request $request): Response
    {
        [$mac, $uuid, $product, $ip] = $this->extractCommonParams($request);
        $serverBaseUrl = $this->resolveServerBaseUrl($request);

        if ($mac === '' || $uuid === '') {
            $this->logHandshake($request, IpxeEnrollmentFlow::Room);

            return $this->safeRender(
                fn (): string => $this->renderer->renderHandshake('room'),
                IpxeMenuKind::Handshake,
                $ip,
                $mac,
                $uuid,
            );
        }

        $workstation = $this->locator->locate($mac, $uuid, $product);
        if ($workstation === null) {
            return $this->safeRender(
                fn (): string => $this->renderer->renderEnrollmentUnknownWorkstation($serverBaseUrl),
                IpxeMenuKind::Unknown,
                $ip,
                $mac,
                $uuid,
            );
        }

        $variables = $this->menuBuilder->buildRoomMenuVariables($workstation, $serverBaseUrl);
        $roomId = $request->input('room');

        if ($roomId === null || $roomId === '') {
            return $this->safeRender(
                fn (): string => $this->renderer->renderEnrollmentRoomMenu($variables, null, false),
                IpxeMenuKind::Default_,
                $ip,
                $mac,
                $uuid,
            );
        }

        $ok = $this->enrollmentService->assignRoom($workstation, (int) $roomId, $ip);
        $roomName = $this->lookupRoomName((int) $roomId, $variables['availableRooms']);

        return $this->safeRender(
            fn (): string => $this->renderer->renderEnrollmentRoomMenu(
                $variables,
                $ok ? ($roomName ?? '') : null,
                ! $ok,
            ),
            IpxeMenuKind::Default_,
            $ip,
            $mac,
            $uuid,
        );
    }

    /**
     * Flow `/ipxe/enrollment/parc-add`.
     */
    public function handleParcAdd(Request $request): Response
    {
        return $this->handleParcCommon($request, attach: true);
    }

    /**
     * Flow `/ipxe/enrollment/parc-remove`.
     */
    public function handleParcRemove(Request $request): Response
    {
        return $this->handleParcCommon($request, attach: false);
    }

    /**
     * Factorise la logique commune `parc-add` / `parc-remove`.
     */
    private function handleParcCommon(Request $request, bool $attach): Response
    {
        [$mac, $uuid, $product, $ip] = $this->extractCommonParams($request);
        $serverBaseUrl = $this->resolveServerBaseUrl($request);
        $flow = $attach ? IpxeEnrollmentFlow::ParcAdd : IpxeEnrollmentFlow::ParcRemove;
        $endpoint = $attach ? 'parc-add' : 'parc-remove';

        if ($mac === '' || $uuid === '') {
            $this->logHandshake($request, $flow);

            return $this->safeRender(
                fn (): string => $this->renderer->renderHandshake($endpoint),
                IpxeMenuKind::Handshake,
                $ip,
                $mac,
                $uuid,
            );
        }

        $workstation = $this->locator->locate($mac, $uuid, $product);
        if ($workstation === null) {
            return $this->safeRender(
                fn (): string => $this->renderer->renderEnrollmentUnknownWorkstation($serverBaseUrl),
                IpxeMenuKind::Unknown,
                $ip,
                $mac,
                $uuid,
            );
        }

        $variables = $attach
            ? $this->menuBuilder->buildParcAddMenuVariables($workstation, $serverBaseUrl)
            : $this->menuBuilder->buildParcRemoveMenuVariables($workstation, $serverBaseUrl);

        $parcId = $request->input('parc');

        if ($parcId === null || $parcId === '') {
            return $this->safeRender(
                fn (): string => $attach
                    ? $this->renderer->renderEnrollmentParcAddMenu($variables, null, false)
                    : $this->renderer->renderEnrollmentParcRemoveMenu($variables, null, false),
                IpxeMenuKind::Default_,
                $ip,
                $mac,
                $uuid,
            );
        }

        $ok = $attach
            ? $this->enrollmentService->attachGroup($workstation, (int) $parcId, $ip)
            : $this->enrollmentService->detachGroup($workstation, (int) $parcId, $ip);

        $parcName = $this->lookupParcName(
            (int) $parcId,
            $attach ? $variables['availableParcs'] : $variables['currentParcs'],
        );

        return $this->safeRender(
            fn (): string => $attach
                ? $this->renderer->renderEnrollmentParcAddMenu($variables, $ok ? ($parcName ?? '') : null, ! $ok)
                : $this->renderer->renderEnrollmentParcRemoveMenu($variables, $ok ? ($parcName ?? '') : null, ! $ok),
            IpxeMenuKind::Default_,
            $ip,
            $mac,
            $uuid,
        );
    }

    /**
     * @return array{0:string,1:string,2:string,3:string}
     */
    private function extractCommonParams(Request $request): array
    {
        // F10 (review 3.3) : normalisation MAC iso 3.1/3.2 (cohérence + défense en profondeur).
        $rawMac = (string) $request->input('mac', '');
        $mac = $rawMac !== '' ? (MacAddressNormalizer::normalize($rawMac) ?? $rawMac) : '';

        return [
            $mac,
            (string) $request->input('uuid', ''),
            (string) $request->input('product', ''),
            (string) ($request->ip() ?? ''),
        ];
    }

    private function logHandshake(Request $request, IpxeEnrollmentFlow $flow): void
    {
        $ip = (string) ($request->ip() ?? '');
        $this->log('ipxe.enrollment.' . $flow->value . '.handshake', [
            'action_type' => 'ipxe.enrollment.' . $flow->value . '.handshake',
            'ip' => $ip,
            'user_agent' => substr((string) $request->userAgent(), 0, 200),
        ]);
    }

    /**
     * Cherche le nom d'une salle dans la liste disponible (utilisé pour
     * l'echo Blade après succès).
     *
     * @param  list<array{id:int,name:string,display_name:string,is_current:bool}>  $rooms
     */
    private function lookupRoomName(int $id, array $rooms): ?string
    {
        foreach ($rooms as $room) {
            if ((int) $room['id'] === $id) {
                return (string) $room['name'];
            }
        }

        return null;
    }

    /**
     * @param  list<array{id:int,name:string,display_name:string,is_current:bool}>  $parcs
     */
    private function lookupParcName(int $id, array $parcs): ?string
    {
        foreach ($parcs as $parc) {
            if ((int) $parc['id'] === $id) {
                return (string) $parc['name'];
            }
        }

        return null;
    }

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
     * Wrap un rendu Blade dans un try/catch — un firmware iPXE doit toujours
     * recevoir text/plain (parité 3.1/3.2 `safeRender`).
     *
     * @param  callable():string  $render
     */
    private function safeRender(
        callable $render,
        IpxeMenuKind $kind,
        string $ip,
        string $mac,
        string $uuid,
    ): Response {
        try {
            return $this->respond($render());
        } catch (Throwable $e) {
            $this->log('ipxe.enrollment.render_error', [
                'action_type' => 'ipxe.enrollment.render_error',
                'kind' => $kind->value,
                'exception_class' => $e::class,
                'message' => substr($e->getMessage(), 0, 200),
                'ip' => $ip,
                'mac_prefix' => $mac !== '' ? substr($mac, 0, 6) : '',
                'uuid_prefix' => $uuid !== '' ? substr($uuid, 0, 8) : '',
            ], 'error');

            return $this->respond("#!ipxe\necho Erreur serveur SE4FS - boot disque dans 10s\nsleep 10\nexit 0\n");
        }
    }

    private function respond(string $body): Response
    {
        return (new Response($body, 200))
            ->header('Content-Type', 'text/plain; charset=utf-8')
            ->header('Cache-Control', 'no-store')
            ->header('X-Robots-Tag', 'noindex');
    }

    /**
     * @param  array<string,mixed>  $context
     * @param  'info'|'warning'|'error'  $level
     */
    private function log(string $event, array $context, string $level = 'info'): void
    {
        $channel = (string) config('ipxe.log.channel', 'ipxe');
        $logger = Log::channel($channel);

        match ($level) {
            'warning' => $logger->warning($event, $context),
            'error' => $logger->error($event, $context),
            default => $logger->info($event, $context),
        };
    }
}

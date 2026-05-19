<?php

declare(strict_types=1);

namespace App\Http\Controllers\Gpo;

use App\Gpo\Services\NetworkScriptGenerator;
use App\Gpo\Services\WorkstationConfigContextResolver;
use App\Http\Controllers\Controller;
use App\Services\AppCustomization\Contracts\AppContextRepository;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Endpoint legacy iso-contrat `/gpo/network_out.php` — script bash de
 * configuration réseau (proxy + 802.1x + WPA) servi aux postes Linux au
 * startup/logon par la GPO `se4_applications`.
 *
 * Story 16.3b. Pattern iso 4.7/4.8 (`WallpaperController::legacyOut`,
 * `AppPolicyController::legacyFirefoxOut`).
 *
 * Pas d'authentification (postes clients sans cookie Laravel). La garde
 * effective est l'`id` md5 32 hex présent dans APCu (clé `apps.$id` posée
 * par `applications.php`, TTL 1800s — entropie effective 64 bits). Throttle
 * `300,1` côté route.
 *
 * Sortie iso-bytes :
 * - Status `200`, `Content-Type: text/plain; charset=utf-8`
 * - Body = script bash terminé par `\n` (pas `\r\n`)
 * - `id` invalide / contexte APCu expiré / OS≠linux → `200 body=""`
 *   (parité legacy stricte ligne 28 `if (! empty($action) && ! empty($os) && $os == "linux")` —
 *   le legacy laisse PHP retomber sur la sortie HTTP par défaut = 200 body vide,
 *   décision Henri 2026-05-12 post-review : aligner strict iso-legacy).
 *
 * @legacy-port path="sambaedu/gpo/network_out.php"
 */
class NetworkOutController extends Controller
{
    public function __construct(
        private readonly AppContextRepository $contextRepository,
        private readonly NetworkScriptGenerator $generator,
    ) {}

    public function legacyOut(Request $request): Response
    {
        $action = (string) $request->input('action', '');
        $os = (string) $request->input('os', 'linux');
        $id = (string) $request->input('id', '');

        // AC4.3 — Validation md5 strict AVANT tout accès APCu/AD/exec.
        if (! preg_match('/^[a-f0-9]{32}$/i', $id)) {
            return $this->emptyOk();
        }

        // AC1.6 — action ∉ {startup, logon} ou os ∉ {linux} → body vide iso-legacy.
        if (! in_array($action, ['startup', 'logon'], true) || $os !== 'linux') {
            return $this->emptyOk();
        }

        $context = $this->contextRepository->findById($id);
        if ($context === null) {
            // #M4 (review fixes) : debug, pas info (300 logs/min possible
            // en rentrée scolaire / boot de masse parc).
            Log::debug('[NetworkOutController] context expired', ['id' => $id]);
            return $this->emptyOk();
        }

        try {
            $body = match ($action) {
                'startup' => $this->generator->buildStartup($context, $os),
                'logon' => $this->generator->buildLogon($context, $os),
                default => '',
            };
        } catch (\Throwable $e) {
            Log::error('[NetworkOutController] failed', [
                'action' => $action,
                'id' => $id,
                'os' => $os,
                'error' => $e->getMessage(),
            ]);
            // Iso-legacy : un bug ici ne doit pas planter le boot du poste.
            return $this->emptyOk();
        }

        // AC1.7 — write debug /tmp parité legacy. Skip en testing pour éviter
        // pollution / permissions FS. @legacy-port path="sambaedu/gpo/network_out.php:40,51"
        // @todo Story 16.4 : supprimer ce write debug (legacy debt).
        if (! app()->environment('testing')) {
            @file_put_contents('/tmp/network-' . $action . '-' . $id . '.log', $body);
        }

        return response($body, 200, [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
            'Pragma' => 'no-cache',
        ]);
    }

    /**
     * Story 16.13 — endpoint natif `GET /api/v1/workstation-config/network`.
     *
     * Pattern iso 16.12 strict : `workstation_uuid` extrait EXCLUSIVEMENT
     * du JWT via `$request->attributes->get('auth_v1.workstation_uuid')`.
     * Aucun lookup APCu md5 — résolution serveur DB via
     * `WorkstationConfigContextResolver`.
     *
     * Iso-fonctionnel avec `legacyOut()` : mêmes Content-Type
     * (`text/plain; charset=utf-8`), mêmes status (200 + body=""), mêmes
     * actions (startup/logon), même restriction OS=linux. Déviation D5 :
     * 404 explicite si `workstation_uuid` JWT inconnu en DB (vs 200 vide
     * legacy).
     */
    public function apiV1(Request $request, WorkstationConfigContextResolver $resolver): Response
    {
        $workstationUuid = (string) $request->attributes->get('auth_v1.workstation_uuid', '');
        $action = (string) $request->input('action', '');
        $os = (string) $request->input('os', 'linux');
        $userLogin = (string) $request->input('user', '');
        $userProfile = (string) $request->input('userprofile', '');

        // Iso-legacy : action ∉ {startup, logon} ou os ≠ linux → body vide.
        if (! in_array($action, ['startup', 'logon'], true) || $os !== 'linux') {
            return $this->emptyOk();
        }

        $context = $resolver->toAppContext($workstationUuid, $os, $userLogin, $userProfile);
        if ($context === null) {
            // Déviation D5 — observabilité admin.
            Log::channel('auth-v1')->warning('[NetworkOutController] workstation not found', [
                'action_type' => 'agent.v1.config.workstation_not_found',
                'workstation_uuid_prefix' => substr($workstationUuid, 0, 8),
                'endpoint' => '/api/v1/workstation-config/network',
            ]);
            // Format JSON unifié post-review (Henri Q2).
            return response()->json(['error' => 'workstation_not_found'], 404);
        }

        try {
            $body = match ($action) {
                'startup' => $this->generator->buildStartup($context, $os),
                'logon' => $this->generator->buildLogon($context, $os),
                default => '',
            };
        } catch (\Throwable $e) {
            Log::error('[NetworkOutController] apiV1 failed', [
                'action' => $action,
                'workstation_uuid_prefix' => substr($workstationUuid, 0, 8),
                'os' => $os,
                'error' => $e->getMessage(),
            ]);
            return $this->emptyOk();
        }

        return response($body, 200, [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
            'Pragma' => 'no-cache',
        ]);
    }

    /**
     * Réponse vide iso-legacy : `200 body=""` (pas 204) — décision Henri
     * 2026-05-12 post-review (#4). Le legacy PHP procédural laisse `exit()` /
     * fallthrough retomber sur le status 200 par défaut.
     *
     * Content-Type `text/plain` préservé (le script de boot poste fait
     * `bash -c "$(curl ...)"` → body vide = `bash -c ""` = noop OK).
     */
    private function emptyOk(): Response
    {
        return response('', 200, [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
        ]);
    }
}

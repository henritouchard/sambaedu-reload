<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\AppKind;
use App\Gpo\Services\WorkstationConfigContextResolver;
use App\Models\User;
use App\Models\WorkstationGroup;
use App\Services\AppCustomization\AppCustomizationService;
use App\Services\AppCustomization\Contracts\AppContextRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Endpoints legacy iso-contrat + canonique pour la résolution de policies.
 *
 * Story 4.8 — AC 9, 10.
 *
 * Routes servies :
 *  - `GET|POST /gpo/firefox_out.php`       → `legacyFirefoxOut`      (iso-contrat)
 *  - `GET|POST /gpo/thunderbird_out.php`   → `legacyThunderbirdOut`  (iso-contrat)
 *  - `GET      /api/policies/{kind}/{id}`  → `canonical`             (nouvelle surface)
 *
 * Pas d'authentification (design legacy — postes clients sans cookie Laravel).
 * Rate limiting via middleware `throttle:60,1`. Garde effective : `$id` md5
 * stocké dans APCu avec TTL 1800s (entropie effective 64 bits).
 */
class AppPolicyController extends Controller
{
    public function __construct(
        private readonly AppContextRepository $contextRepository,
        private readonly AppCustomizationService $service,
    ) {}

    /**
     * `/gpo/firefox_out.php` — iso-contrat legacy.
     */
    public function legacyFirefoxOut(Request $request): Response|JsonResponse
    {
        return $this->resolve($request, AppKind::Firefox, (string) $request->input('os', 'linux'));
    }

    /**
     * `/gpo/thunderbird_out.php` — iso-contrat legacy (pas de paramètre `os`).
     */
    public function legacyThunderbirdOut(Request $request): Response|JsonResponse
    {
        return $this->resolve($request, AppKind::Thunderbird, 'linux');
    }

    /**
     * `/api/policies/{kind}/{id}` — route canonique nouvelle surface.
     */
    public function canonical(string $kind, string $id, Request $request): Response|JsonResponse
    {
        $appKind = AppKind::tryFrom(strtolower($kind));
        if ($appKind === null) {
            return response()->json(['error' => 'unknown_kind'], 404);
        }

        $os = (string) $request->input('os', 'linux');

        $request->merge(['id' => $id]);
        return $this->resolve($request, $appKind, $os);
    }

    /**
     * Story 16.13 — endpoint natif `GET /api/v1/workstation-config/firefox`.
     *
     * Pattern iso 16.12 strict : `workstation_uuid` extrait EXCLUSIVEMENT
     * du JWT via `$request->attributes->get('auth_v1.workstation_uuid')`.
     * Aucun lookup APCu — résolution serveur DB via
     * `WorkstationConfigContextResolver`.
     *
     * Iso-fonctionnel avec `legacyFirefoxOut()` : mêmes Content-Type
     * (`application/json;charset=utf-8`), mêmes status codes (200/404),
     * même body JSON.
     */
    public function apiV1Firefox(Request $request, WorkstationConfigContextResolver $resolver): Response|JsonResponse
    {
        $os = (string) $request->input('os', 'linux');
        return $this->resolveNative($request, AppKind::Firefox, $os, $resolver);
    }

    /**
     * Story 16.13 — endpoint natif `GET /api/v1/workstation-config/thunderbird`.
     * Iso-fonctionnel avec `legacyThunderbirdOut()`. OS toujours linux
     * (parité legacy).
     */
    public function apiV1Thunderbird(Request $request, WorkstationConfigContextResolver $resolver): Response|JsonResponse
    {
        return $this->resolveNative($request, AppKind::Thunderbird, 'linux', $resolver);
    }

    /**
     * Résolution native /api/v1/* — chaîne identique à `resolve()` legacy
     * mais sans lookup APCu : le contexte est reconstruit via le resolver
     * à partir du JWT `workstation_uuid` + query params (os, user).
     *
     * Déviation D5 : 404 explicite si `workstation_uuid` JWT inconnu en DB
     * (vs 200 vide legacy id='' ou 404 « context_expired » legacy).
     */
    private function resolveNative(
        Request $request,
        AppKind $kind,
        string $os,
        WorkstationConfigContextResolver $resolver,
    ): Response|JsonResponse {
        $workstationUuid = (string) $request->attributes->get('auth_v1.workstation_uuid', '');
        $userLogin = (string) $request->input('user', '');

        // F2 (review post-merge) — unique lookup via resolver. Si le poste
        // est introuvable en base, `resolve()` retourne null ; on évite le
        // double lookup `resolveAppPolicyScope` + `Workstation::where()` qui
        // ouvrait une race condition + double query.
        $ctx = $resolver->resolve($workstationUuid, $os, $userLogin);
        if ($ctx === null) {
            // Déviation D5 — observabilité admin.
            Log::channel('auth-v1')->warning('[AppPolicyController] workstation not found', [
                'action_type' => 'agent.v1.config.workstation_not_found',
                'workstation_uuid_prefix' => substr($workstationUuid, 0, 8),
                'endpoint' => '/api/v1/workstation-config/' . strtolower($kind->value),
            ]);
            return response()->json(['error' => 'workstation_not_found'], 404, $this->baseHeaders());
        }

        $scope = $resolver->resolveAppPolicyScope($workstationUuid, $userLogin);

        try {
            $policies = $this->service->resolvePoliciesForMachine($scope['wg'], $scope['user'], $kind, $os);

            return response()->json(
                $policies,
                200,
                $this->baseHeaders(),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            );
        } catch (\Throwable $e) {
            Log::error('[AppPolicyController] resolveNative failed', [
                'kind' => $kind->value,
                'workstation_uuid_prefix' => substr($workstationUuid, 0, 8),
                'os' => $os,
                'error' => $e->getMessage(),
            ]);
            return response()->json(['error' => 'internal_error'], 500, $this->baseHeaders());
        }
    }

    /**
     * Logique partagée : validation id → lookup contexte → résolution → JSON.
     */
    private function resolve(Request $request, AppKind $kind, string $os): Response|JsonResponse
    {
        $id = (string) $request->input('id', '');

        // Fidèle legacy `firefox_out.php` L9-10 : id vide → exit() = body vide.
        if ($id === '') {
            return response('', 200, $this->baseHeaders());
        }

        if (! preg_match('/^[a-f0-9]{32}$/i', $id)) {
            return response()->json(['error' => 'invalid_id'], 400, $this->baseHeaders());
        }

        $context = $this->contextRepository->findById($id);
        if ($context === null) {
            return response()->json(['error' => 'context_expired'], 404, $this->baseHeaders());
        }

        try {
            $wg = $context->salleName !== ''
                ? WorkstationGroup::query()->where('name', $context->salleName)->first()
                : null;
            $user = $context->userLogin !== ''
                ? User::query()->where('login', $context->userLogin)->first()
                : null;

            $policies = $this->service->resolvePoliciesForMachine($wg, $user, $kind, $os);

            return response()->json(
                $policies,
                200,
                $this->baseHeaders(),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            );
        } catch (\Throwable $e) {
            Log::error('[AppPolicyController] resolve failed', [
                'kind' => $kind->value,
                'id' => $id,
                'os' => $os,
                'error' => $e->getMessage(),
            ]);
            return response()->json(['error' => 'internal_error'], 500, $this->baseHeaders());
        }
    }

    /**
     * @return array<string,string>
     */
    private function baseHeaders(): array
    {
        return [
            'Content-Type' => 'application/json;charset=utf-8',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
            'Pragma' => 'no-cache',
        ];
    }
}

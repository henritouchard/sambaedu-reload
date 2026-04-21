<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\AppKind;
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

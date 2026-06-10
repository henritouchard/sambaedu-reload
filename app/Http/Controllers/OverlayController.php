<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Gpo\Services\WorkstationConfigContextResolver;
use App\Services\Overlay\OverlayService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Endpoint natif overlay poste — `GET /api/v1/workstation-config/overlay`.
 *
 * Renvoie le contrat JSON consommé par l'overlay client (Rainmeter / Conky)
 * pour afficher identité + alertes en surcouche d'un fond statique, en
 * remplacement du bandeau cuit par `WallpaperComposer`. Cf. spike
 * `_bmad-output/planning-artifacts/spike-wallpaper-overlay-tools-2026-06-09.md`.
 *
 * Auth iso `WallpaperController::apiV1` (Story 16.13) : `$workstationUuid`
 * extrait EXCLUSIVEMENT du JWT via
 * `$request->attributes->get('auth_v1.workstation_uuid')` (middleware
 * `auth.v1.workstation`). Résolution serveur DB (pas d'APCu md5). 404 si le
 * `workstation_uuid` est inconnu (parité D5). Cache-Control délégué au
 * middleware `auth.v1.secure-headers`.
 */
class OverlayController extends Controller
{
    public function apiV1(
        Request $request,
        WorkstationConfigContextResolver $resolver,
        OverlayService $overlay,
    ): JsonResponse {
        $workstationUuid = (string) $request->attributes->get('auth_v1.workstation_uuid', '');

        $os = (string) $request->input('os', 'linux');
        $userLogin = (string) $request->input('user', '');
        $userProfile = (string) $request->input('userprofile', '');

        $context = $resolver->toWallpaperContext($workstationUuid, $os, $userLogin, $userProfile);
        if ($context === null) {
            Log::channel('auth-v1')->warning('[OverlayController] workstation not found', [
                'action_type' => 'agent.v1.config.workstation_not_found',
                'workstation_uuid_prefix' => substr($workstationUuid, 0, 8),
                'endpoint' => '/api/v1/workstation-config/overlay',
            ]);

            return response()->json(['error' => 'workstation_not_found'], 404);
        }

        return response()->json($overlay->pollPayload($workstationUuid, $context)->toArray());
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Agent;

use App\Http\Controllers\Controller;
use App\Models\WallpaperAsset;
use App\Models\Workstation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Story 24.4 — `GET /api/v1/agent/assets/wallpaper/{filename}`
 * (route `agent.v1.assets.wallpaper`).
 *
 * Serving binaire des assets de la bibliothèque wallpaper pour le canal
 * agent desired-state : le payload `wallpaper` de `GET /state` ne porte que
 * `{asset, checksum}` (figé 23.4 — décision 24.4 n° 2 : PAS de champ `url`,
 * l'agent construit l'URL depuis `server_url` + ce chemin documenté, comme
 * pour /state et /report). Le téléchargement est fait côté SYSTEM (seul
 * détenteur du token) qui vérifie le SHA-256 = `checksum` à l'arrivée.
 *
 * Controller mince : aucun service métier requis — validation stricte du
 * filename content-addressed (`^[0-9a-f]{64}\.[a-z0-9]{2,5}$`, le format
 * produit par WallpaperUploadService/Backfiller), lookup {@see WallpaperAsset}
 * par filename, BinaryFileResponse depuis `absolutePath` (la défense
 * anti-traversal du modèle — review F7 — reste la seconde ligne).
 *
 * 404 pour TOUT échec (filename malformé, asset inconnu, fichier absent) :
 * aucun oracle de présence au-delà du nécessaire. Middlewares
 * (routes/api.php) : `auth.v1.secure-headers` + `throttle:60,1` +
 * `agent.token` — chaîne iso state/report, X-Agent-New-Token survit (D5,
 * le middleware pose le header sur toute réponse).
 */
class AssetController extends Controller
{
    /**
     * Format content-addressed de la bibliothèque : `<sha256-hex>.<ext>`.
     * Toute autre forme (traversal, nom legacy, casse) = 404 immédiat,
     * AVANT tout accès disque ou DB.
     */
    private const FILENAME_PATTERN = '/^[0-9a-f]{64}\.[a-z0-9]{2,5}$/';

    public function show(Request $request, string $filename): BinaryFileResponse|JsonResponse
    {
        /** @var Workstation $workstation */
        $workstation = $request->attributes->get('agent.workstation');

        if (preg_match(self::FILENAME_PATTERN, $filename) !== 1) {
            return $this->notFound($workstation, $filename);
        }

        $asset = WallpaperAsset::query()->where('filename', $filename)->first();
        if ($asset === null) {
            return $this->notFound($workstation, $filename);
        }

        $path = $asset->absolutePath;
        if ($path === '' || ! is_file($path)) {
            return $this->notFound($workstation, $filename);
        }

        Log::channel('agent')->info('[AssetController] agent.asset.served', [
            'action_type' => 'agent.asset.served',
            'workstation_id' => $workstation->id,
            'filename' => $filename,
        ]);

        // Contenu immuable par construction (content-addressed : un autre
        // contenu = un autre filename) — le no-store des secure-headers
        // s'applique quand même (réponse authentifiée, hygiène du canal).
        return response()->file($path);
    }

    private function notFound(Workstation $workstation, string $filename): JsonResponse
    {
        Log::channel('agent')->info('[AssetController] agent.asset.not_found', [
            'action_type' => 'agent.asset.not_found',
            'workstation_id' => $workstation->id,
            // Input client non authentifié en forme : borné avant log (P5 23.2).
            'filename' => Str::limit($filename, 128),
        ]);

        return response()->json([
            'error' => 'not_found',
            'message' => 'Unknown wallpaper asset',
        ], 404);
    }
}

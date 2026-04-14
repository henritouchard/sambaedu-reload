<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Workstation;
use App\Services\Windows\IngestionResult;
use App\Services\Windows\WpkgReportIngestionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Endpoint API d'ingestion des rapports WPKG.
 *
 * POST /api/wpkg/reports/{hostname}
 *   Content-Type: text/plain
 *   Body: contenu brut du rapport WPKG
 *
 * Réponses :
 *   200  { status: 'processed', packages_count: N }
 *   200  { status: 'unchanged', packages_count: 0 }   (rapport identique — SHA inchangé)
 *   404  { message: '...' }
 *   422  { message: '...' }
 */
class WpkgReportController extends Controller
{
    public function __construct(
        private readonly WpkgReportIngestionService $ingestionService
    ) {}

    /**
     * Ingestion d'un rapport WPKG pour un hostname donné.
     */
    public function store(Request $request, string $hostname): JsonResponse|Response
    {
        // Vérifie Content-Type : text/plain obligatoire
        if (!str_starts_with($request->header('Content-Type', ''), 'text/plain')) {
            return response()->json(['error' => 'unsupported_media_type'], 415);
        }

        // Vérifie la taille du payload : max 2 MiB (anti-DoS)
        $contentLength = (int) $request->header('Content-Length', 0);
        if ($contentLength > 2_000_000) {
            return response()->json(['error' => 'payload_too_large'], 413);
        }

        // Vérifie l'existence du poste avant de déléguer au service
        if (!Workstation::where('name', $hostname)->exists()) {
            return response()->json(['message' => "Poste '{$hostname}' introuvable."], 404);
        }

        $raw = $request->getContent();

        if (empty($raw)) {
            return response()->json(['message' => 'Le rapport ne peut pas être vide.'], 422);
        }

        $result = $this->ingestionService->ingest($hostname, $raw);

        return match (true) {
            $result->isProcessed()  => response()->json([
                'status'          => 'processed',
                'packages_count'  => $result->packagesCount,
            ], 200),

            $result->isUnchanged()  => response()->json([
                'status'         => 'unchanged',
                'packages_count' => 0,
            ], 200),

            $result->isNotFound()   => response()->json([
                'message' => $result->error,
            ], 404),

            $result->isParseFailed() => response()->json([
                'message' => $result->error ?? 'Impossible de parser le rapport.',
            ], 422),

            default => response()->json(['message' => 'Erreur interne.'], 500),
        };
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\BulkResetListingService;
use App\Services\PasswordResetExportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Contrôleur de téléchargement des exports PDF/CSV post-bulk-reset.
 *
 * Le token (UUID v4) est embarqué dans une URL signée Laravel
 * ({@see BulkResetListingService::buildSignedUrl()}) — le middleware `signed`
 * valide la signature; on vérifie en plus la présence du listing en cache
 * (TTL 20 min max, purgé automatiquement à expiration).
 *
 * Les deux formats (PDF + CSV) sont autorisés avant expiration — on ne
 * purge PAS le listing après un téléchargement (AC 9).
 */
class PasswordResetExportController extends Controller
{
    public function __construct(
        private BulkResetListingService $listingService,
        private PasswordResetExportService $exportService,
    ) {
    }

    public function downloadPdf(Request $request, string $token): Response
    {
        return $this->download($request, $token, 'pdf');
    }

    public function downloadCsv(Request $request, string $token): Response
    {
        return $this->download($request, $token, 'csv');
    }

    private function download(Request $request, string $token, string $format): Response
    {
        $payload = $this->listingService->fetchListing($token);

        if ($payload === null) {
            Log::info('audit.user.password.reset.export.expired', [
                'token' => $token,
                'format' => $format,
                'operator_login' => auth()->user()?->login ?? 'anonymous',
            ]);

            // 410 Gone : la ressource a existé mais n'est plus disponible (TTL).
            return response()->view('errors.password-reset-expired', [], 410);
        }

        // Vérification propriété du token — seul l'opérateur qui a déclenché le reset peut télécharger.
        // Le middleware `signed` protège contre la forge mais pas contre le partage de token (#1 review 2.6).
        if ((int) ($payload['operator_id'] ?? 0) !== (int) auth()->id()) {
            abort(403, 'Token non autorisé');
        }

        $listing = $payload['listing'] ?? [];
        $operatorLogin = auth()->user()?->login ?? 'system';
        $forceChange = (bool) ($payload['meta']['force_change'] ?? true);

        Log::info('audit.user.password.reset.export.download', [
            'token' => $token,
            'format' => $format,
            'operator_login' => $operatorLogin,
            'count' => count($listing),
        ]);

        return $this->exportService->generateExport($listing, $format, [
            'operator_login' => $operatorLogin,
            'force_change' => $forceChange,
        ]);
    }
}

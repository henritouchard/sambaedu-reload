<?php

declare(strict_types=1);

namespace App\Http\Controllers\E2e;

use App\Http\Controllers\Controller;
use App\Models\E2e\AdWriteLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;

/**
 * Endpoint e2e READ-ONLY d'inspection du journal des écritures AD (Story 21.2,
 * DP-LOG / T3).
 *
 * Sert le contenu de `e2e_ad_writes` en JSON pour qu'un test Playwright vérifie
 * qu'une écriture attendue a bien été capturée par le fake (AC6). Read-only :
 * aucune mutation.
 *
 * Garde-fou défensif : la route n'est de toute façon DÉCLARÉE qu'en `e2e`
 * (`routes/web.php`), mais on revérifie l'environnement ici (defense-in-depth,
 * doctrine 21.1 « garde-fou = code ») — un appel hors e2e renvoie 404.
 */
class AdWriteLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        abort_unless(App::environment('e2e'), 404);

        $query = AdWriteLog::query()->orderBy('id');

        // Filtres optionnels pour faciliter l'assertion ciblée côté Playwright.
        if (($actionType = $request->query('action_type')) !== null) {
            $query->where('action_type', $actionType);
        }
        if (($target = $request->query('target')) !== null) {
            $query->where('target', $target);
        }

        $writes = $query->get([
            'id',
            'action_type',
            'target',
            'fake_guid',
            'payload',
            'channel',
            'created_at',
        ]);

        return response()->json([
            'count' => $writes->count(),
            'writes' => $writes,
        ]);
    }
}

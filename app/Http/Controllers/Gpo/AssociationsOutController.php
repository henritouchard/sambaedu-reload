<?php

declare(strict_types=1);

namespace App\Http\Controllers\Gpo;

use App\Gpo\Services\AssociationsResolver;
use App\Http\Controllers\Controller;
use App\Services\AppCustomization\Contracts\AppContextRepository;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Endpoint legacy iso-contrat `/gpo/associations_out.php` — sert le JSON des
 * associations d'extensions de fichiers et de protocoles à appliquer côté
 * poste Windows au logon (consommé par la GPO `se4_applications`).
 *
 * Story 16.3c — Pattern iso 4.7/4.8/16.3b. Endpoint runtime poste client :
 * - **Pas d'auth web** (postes sans cookie Laravel)
 * - Garde effective : `id` md5 32 hex présent dans APCu (`apps.$id` posée par
 *   `applications.php` shim — TTL 1800s)
 * - Throttle `300,1` côté route
 *
 * Sortie iso-bytes :
 * - Status `200`, `Content-Type: text/json` (header non-standard iso-legacy
 *   `associations_out.php:170` — conservé pour parité bytes-strict)
 * - Body = `json_encode(['result' => $result], JSON_PRETTY_PRINT)`
 *
 * Sortie 400 Bad Request body vide :
 * - `id` invalide ou absent (regex `^[a-f0-9]{32}$`)
 * - `list` POST absent / non décodable / > 10 Ko
 * - Contexte APCu expiré / inexistant
 * (Iso-legacy strict ligne 23-27 — `400 Bad request` sans body, **différent**
 * de 16.3b/network_out/veyon_out où l'iso-legacy est `200 body=""`. Ici
 * `associations_out.php` retourne `header("HTTP/1.1 400 Bad request"); exit()`,
 * pas de fallback PHP par défaut → on aligne strict 400.)
 *
 * Side effect debug `/tmp/assoc_result.json` : conservé parité partielle (D5).
 * Les 3 autres writes legacy (`assoc_local.json`, `assoc_app.json`,
 * `assoc_wpkg.json`) sont skippés (debug intermédiaire inutile).
 *
 * @legacy-port path="sambaedu/gpo/associations_out.php"
 */
class AssociationsOutController extends Controller
{
    /** Taille max du body POST `list` (10 Ko). */
    private const LIST_MAX_BYTES = 10 * 1024;

    /** Path debug iso-legacy ligne 168 — `assoc_result.json` (uniquement). */
    private const DEBUG_RESULT_PATH = '/tmp/assoc_result.json';

    public function __construct(
        private readonly AppContextRepository $contextRepository,
        private readonly AssociationsResolver $resolver,
    ) {}

    public function legacyOut(Request $request): Response
    {
        $id = (string) $request->input('id', '');
        $listRaw = (string) $request->input('list', '');

        // AC3.2 / AC5.4 — Validation md5 strict AVANT tout accès APCu/Eloquent/FS.
        if (! preg_match('/^[a-f0-9]{32}$/i', $id)) {
            return $this->badRequest();
        }

        // AC3.3 — Validation list présent + taille ≤ 10 Ko (avant json_decode
        // coûteux + APCu).
        if ($listRaw === '' || strlen($listRaw) > self::LIST_MAX_BYTES) {
            return $this->badRequest();
        }

        // Validation list = JSON décodable structure attendue.
        $listDecoded = json_decode($listRaw, true);
        if (! is_array($listDecoded)) {
            return $this->badRequest();
        }

        // AC3.4 — Contexte APCu présent.
        $context = $this->contextRepository->findById($id);
        if ($context === null) {
            // Cohérence 16.3b #M4 : debug (pas info) pour éviter 300 logs/min
            // boot de masse.
            Log::debug('[AssociationsOutController] context expired', ['id' => $id]);
            return $this->badRequest();
        }

        try {
            $localAssocs = $this->resolver->parseLocalAssocs($listDecoded);
            $result = $this->resolver->resolve($context, $localAssocs);
        } catch (\Throwable $e) {
            Log::error('[AssociationsOutController] resolve failed', [
                'id' => $id,
                'machine' => $context->machineName,
                'error' => $e->getMessage(),
            ]);
            // Parité legacy : si le calcul plante, on ne casse pas le logon
            // poste. Retour `{"result": {}}` 200 iso-bytes (= delta vide).
            return $this->jsonOk([]);
        }

        // AC3.7 — Debug write /tmp/assoc_result.json (parité partielle D5).
        // Skippé en testing pour éviter pollution FS / permission CI.
        if (! app()->environment('testing')) {
            @file_put_contents(
                self::DEBUG_RESULT_PATH,
                json_encode($result, JSON_PRETTY_PRINT),
            );
        }

        return $this->jsonOk($result);
    }

    /**
     * Réponse iso-bytes legacy : `200 text/json` + body
     * `{"result": {...}}` encodé en JSON_PRETTY_PRINT.
     *
     * @param array<string, array{ProgId: string, type: string}> $result
     */
    private function jsonOk(array $result): Response
    {
        $body = json_encode(['result' => $result], JSON_PRETTY_PRINT);
        if ($body === false) {
            // Cas théorique (UTF-8 invalide) — fallback delta vide pour
            // ne pas casser le client.
            $body = json_encode(['result' => []], JSON_PRETTY_PRINT) ?: '{"result":{}}';
        }

        return response($body, 200, [
            // @legacy-port iso ligne 170 — `text/json` non-standard conservé.
            // Les clients postes ne sont pas affectés (ils lisent le body).
            'Content-Type' => 'text/json',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
            'Pragma' => 'no-cache',
        ]);
    }

    /**
     * Réponse iso-legacy `400 Bad request` body vide. **Différent** de
     * network_out / veyon_out (`200 body=""`) : `associations_out.php` legacy
     * fait `header("HTTP/1.1 400 Bad request"); exit()` ligne 25-26.
     */
    private function badRequest(): Response
    {
        return response('', 400, [
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
        ]);
    }
}

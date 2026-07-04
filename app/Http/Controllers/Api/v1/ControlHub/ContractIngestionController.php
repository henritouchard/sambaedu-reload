<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\v1\ControlHub;

use App\Exceptions\ControlHub\InvalidUpstreamContractException;
use App\Exceptions\ControlHub\UnsupportedSchemaVersionException;
use App\Http\Controllers\Controller;
use App\Services\ControlHub\ControlHubContractIngestionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Story 39.1 (FR-A1 + NFR-A1..A3) — Endpoint HTTP de RÉCEPTION du contrat amont
 * (canal ① du lien managé), authentifié par le middleware `controlhub.auth`.
 *
 * Contrôleur **mince** : câblage pur. Toute l'ingestion idempotente (négociation
 * de version, normalisation/validation pure, transaction, événement 1× sur
 * mutation) vit dans {@see ControlHubContractIngestionService::ingest()} (Epics
 * 28/33) — ce contrôleur ne fait AUCUNE validation métier, AUCUNE normalisation,
 * AUCUNE transaction : il lit le payload, délègue, mappe les deux exceptions de
 * domaine en 422, et construit la réponse JSON.
 *
 * L'auth précède structurellement la lecture du corps : le middleware
 * `controlhub.auth` ne lit que le header `Authorization` et répond **403**
 * (jamais 401) sans jamais accéder au payload ; ce contrôleur — seul lecteur du
 * corps — n'est atteint qu'après authentification réussie (NFR-A3).
 *
 * ⚠️ GARDE-FOU R3 : aucun mot « central ». Vocabulaire « amont » / `upstream` /
 * `ControlHub*`. [Source: prd-contrat-manage-se5.md#R3]
 */
class ContractIngestionController extends Controller
{
    /**
     * Clés d'enveloppe reconnues du schéma `se5-contract/v1`. `schema_version`
     * reste OPTIONNELLE (rétro-compat 28.2 : absente ⇒ version courante) ; la
     * présence de l'une quelconque de ces clés suffit à qualifier le corps de
     * « contrat ».
     */
    private const CONTRACT_ENVELOPE_KEYS = ['schema_version', 'items', 'labels', 'imposed_groups', 'catalog_apps'];

    public function __invoke(
        Request $request,
        ControlHubContractIngestionService $ingestionService,
    ): JsonResponse {
        $payload = $request->json()->all();

        // NFR-A3 — durcissement de la frontière HTTP (review opus 39.1, #1). On
        // DISTINGUE un « contrat vide explicite » (agrégats déclarés, fussent-ils
        // vides — purge légitime post-release) d'un corps illisible/tronqué/non-
        // contrat (`{}`, scalaire, JSON invalide → décodé en tableau SANS aucune
        // clé d'enveloppe). Sans cette garde, un tel corps AUTHENTIFIÉ serait
        // coercé par `ingest()` en désir d'état VIDE → prune destructif des groupes
        // imposés + ré-activation SILENCIEUSE d'un lien rompu (32.1), le tout en 200.
        // On EXIGE au moins une clé d'enveloppe reconnue ; on NE valide RIEN du
        // contenu (ça reste le rôle pur, testé, de `ingest()`).
        if (! array_intersect(self::CONTRACT_ENVELOPE_KEYS, array_keys($payload))) {
            return response()->json([
                'success' => false,
                'error' => 'invalid_upstream_contract',
                'message' => 'Corps amont illisible ou vide : au moins une clé de contrat ('
                    .implode(', ', self::CONTRACT_ENVELOPE_KEYS).') est requise.',
            ], 422);
        }

        try {
            $result = $ingestionService->ingest($payload);
        } catch (UnsupportedSchemaVersionException $e) {
            return response()->json([
                'success' => false,
                'error' => 'unsupported_schema_version',
                'message' => $e->getMessage(),
            ], 422);
        } catch (InvalidUpstreamContractException $e) {
            return response()->json([
                'success' => false,
                'error' => 'invalid_upstream_contract',
                'message' => $e->getMessage(),
            ], 422);
        }

        return response()->json(['success' => true] + $result->toArray());
    }
}

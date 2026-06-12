<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Agent;

use App\Http\Controllers\Controller;
use App\Models\AgentRelease;
use App\Models\Workstation;
use App\Services\Agent\Releases\ReleaseManifestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Story 25.1 — Distribution des releases agent (D6, FR24). Nom figé par
 * l'architecture. Deux endpoints, chaîne middleware iso state/report
 * (`auth.v1.secure-headers` + `throttle:60,1` + `agent.token`) :
 *
 *  - `GET /api/v1/agent/release` (route `agent.v1.release`) — le MANIFEST
 *    `{version, hash, url}` résolu selon le ring du poste authentifié
 *    ({@see ReleaseManifestService} : ring → récence → stable → 404
 *    `no_release`). Wrapper SE5 `{success, …}` (seul /state sert le contrat
 *    brut) ; `url` ABSOLUE (piège n° 6). Aucune release applicable → 404
 *    `no_release` — jamais un 200 vide ambigu (l'agent 25.2 traite 404 =
 *    « rien à faire »).
 *  - `GET /api/v1/agent/releases/{filename}` (route
 *    `agent.v1.release.download`) — serving binaire iso
 *    {@see AssetController} (24.4) : pattern strict AVANT tout accès disque
 *    ou DB, lookup `agent_releases` d'abord (seul un filename publié est
 *    servi), realpath confiné sous `releases_path`, 404 INDISTINCT
 *    `{error, message}` pour TOUT échec (malformé, inconnu DB, fichier
 *    absent) — aucun oracle de présence. Le binaire ne porte PAS le wrapper
 *    SE5 (piège n° 10). L'agent 25.2 vérifiera SHA-256 + signature
 *    Authenticode AVANT exécution (décision n° 8 — le serveur garantit
 *    l'intégrité à la CRÉATION, pas à l'exécution).
 *
 * Controller mince : la résolution vit dans le service, l'auth et les
 * écritures `workstations` (check-in, rotation X-Agent-New-Token — D5,
 * survit aux deux réponses) dans le middleware 23.2. Identité = le token,
 * jamais un identifiant en entrée.
 */
class ReleaseController extends Controller
{
    /**
     * Forme produite par le build 24.5 : `sambaedu-agent-<version>.exe`.
     * Toute autre forme (traversal, casse, extension) = 404 immédiat,
     * AVANT tout accès disque ou DB.
     */
    private const FILENAME_PATTERN = '/^sambaedu-agent-[0-9A-Za-z.+~-]+\.exe$/';

    public function __construct(
        private readonly ReleaseManifestService $manifests,
    ) {
    }

    public function manifest(Request $request): JsonResponse
    {
        /** @var Workstation $workstation */
        $workstation = $request->attributes->get('agent.workstation');

        $manifest = $this->manifests->manifestFor($workstation);
        if ($manifest === null) {
            // AC3/décision n° 7 : ni ring ni stable → 404 explicite, jamais
            // un 200 vide (et jamais une canari par accident).
            Log::channel('agent')->debug('[ReleaseController] agent.release.no_release', [
                'action_type' => 'agent.release.no_release',
                'workstation_id' => $workstation->id,
            ]);

            return response()->json([
                'error' => 'no_release',
                'message' => 'No applicable release for this workstation',
            ], 404);
        }

        // Debug : un par check-in (volume NFR4) — jamais en info.
        Log::channel('agent')->debug('[ReleaseController] agent.release.manifest_served', [
            'action_type' => 'agent.release.manifest_served',
            'workstation_id' => $workstation->id,
            'version' => $manifest['version'],
        ]);

        return response()->json(array_merge(['success' => true], $manifest));
    }

    public function download(Request $request, string $filename): BinaryFileResponse|JsonResponse
    {
        /** @var Workstation $workstation */
        $workstation = $request->attributes->get('agent.workstation');

        if (strlen($filename) > 255 || preg_match(self::FILENAME_PATTERN, $filename) !== 1) {
            return $this->notFound($workstation, $filename);
        }

        // Lookup DB d'abord (AC4) : seul un filename publié est servi —
        // un binaire orphelin déposé dans le répertoire n'est jamais servi.
        $release = AgentRelease::query()->where('filename', $filename)->first();
        if ($release === null) {
            return $this->notFound($workstation, $filename);
        }

        // realpath confiné : le pattern exclut déjà tout séparateur de
        // chemin — seconde ligne de défense anti-traversal (iso 24.4).
        $base = realpath((string) config('agent.releases_path'));
        if ($base === false) {
            // Signal ops distinct (review 25.1 #8) : répertoire de releases
            // absent/illisible ≠ release inconnue — un parc entier en 404
            // doit pointer la config, pas la DB. Réponse client inchangée
            // (404 indistinct, zéro oracle).
            Log::channel('agent')->warning('[ReleaseController] agent.release.releases_path_missing', [
                'action_type' => 'agent.release.releases_path_missing',
                'releases_path' => (string) config('agent.releases_path'),
            ]);

            return $this->notFound($workstation, $filename);
        }
        $path = realpath($base . DIRECTORY_SEPARATOR . $filename);
        if ($path === false
            || ! str_starts_with($path, $base . DIRECTORY_SEPARATOR)
            || ! is_file($path)) {
            return $this->notFound($workstation, $filename);
        }

        // Debug (décision review 25.1 #4) : un téléchargement n'est qu'un
        // préalable sans garantie — la trace de déploiement qui FAIT FOI est
        // la version rapportée par l'agent au check-in (25.2 : version dans
        // chaque rapport, échec d'update rapporté). Pas de pic info au
        // rollout d'un ring.
        Log::channel('agent')->debug('[ReleaseController] agent.release.download_served', [
            'action_type' => 'agent.release.download_served',
            'workstation_id' => $workstation->id,
            'version' => $release->version,
            'filename' => $filename,
        ]);

        return response()->file($path);
    }

    /**
     * 404 INDISTINCT (piège n° 10, iso `AssetController::notFound()`) :
     * malformé, inconnu en DB ou absent du disque répondent à l'identique.
     */
    private function notFound(Workstation $workstation, string $filename): JsonResponse
    {
        Log::channel('agent')->info('[ReleaseController] agent.release.download_not_found', [
            'action_type' => 'agent.release.download_not_found',
            'workstation_id' => $workstation->id,
            // Input client non authentifié en forme : borné avant log (P5 23.2).
            'filename' => Str::limit($filename, 128),
        ]);

        return response()->json([
            'error' => 'not_found',
            'message' => 'Unknown release',
        ], 404);
    }
}

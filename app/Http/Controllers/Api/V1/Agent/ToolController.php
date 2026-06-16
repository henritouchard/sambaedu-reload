<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Agent;

use App\Http\Controllers\Controller;
use App\Models\Workstation;
use App\Services\Agent\Tools\AgentToolManifestService;
use App\Services\Agent\Tools\OverlaySkinProvisioner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Story 27.1bis — `GET /api/v1/agent/tools/{filename}`
 * (route `agent.v1.tools.download`).
 *
 * Serving binaire des artefacts d'OUTILS DE RENDU posés par l'agent au
 * bootstrap (décision D8) — aujourd'hui le seul : l'archive PORTABLE de
 * Rainmeter (zéro registre, GPLv2). DÉLIBÉRÉMENT séparé de
 * {@see ReleaseController} : `agent_releases` est mono-artefact réservé au
 * BINAIRE agent + auto-update (Story 25.2, pattern filename
 * `^sambaedu-agent-…\.exe$`) — un outil tiers (`.zip` au nom différent) y
 * serait rejeté. La même séparation vaut côté storage : `storage/agent/tools/`
 * (≠ `storage/agent/releases/`). PAS de rings/versioning d'Epic 25 (Q3 =
 * mono-version : Rainmeter ne bouge quasi jamais).
 *
 * Controller mince, iso `AssetController`/`ReleaseController` :
 *   - pattern de filename STRICT (`sambaedu-rainmeter-…\.zip`) AVANT tout
 *     accès disque — anti-traversal (le pattern exclut tout séparateur de
 *     chemin) ;
 *   - realpath confiné sous `agent.tools_path` (seconde ligne de défense) ;
 *   - 404 INDISTINCT `{error, message}` pour TOUT échec (malformé, absent,
 *     répertoire illisible) — aucun oracle de présence ;
 *   - L'INTÉGRITÉ est garantie CÔTÉ AGENT : il vérifie le SHA-256 attendu
 *     (constante bakée dans le binaire Go, pattern `SyncWallpaperAssets`)
 *     AVANT extraction. Le serveur garantit le confinement, pas le contenu.
 *
 * Middlewares (routes/api.php) : `auth.v1.secure-headers` + `throttle:60,1`
 * + `agent.token` — chaîne iso state/report/asset, X-Agent-New-Token survit
 * (le middleware pose le header sur toute réponse 2xx, BinaryFileResponse
 * compris).
 */
class ToolController extends Controller
{
    /**
     * Forme d'un artefact d'outil de rendu : `sambaedu-rainmeter-<version>.zip`.
     * Toute autre forme (traversal, casse, extension) = 404 immédiat, AVANT
     * tout accès disque.
     */
    private const FILENAME_PATTERN = '/^sambaedu-rainmeter-[0-9A-Za-z.+~-]+\.zip$/';

    public function download(Request $request, string $filename): BinaryFileResponse|JsonResponse
    {
        /** @var Workstation $workstation */
        $workstation = $request->attributes->get('agent.workstation');

        if (strlen($filename) > 255 || preg_match(self::FILENAME_PATTERN, $filename) !== 1) {
            return $this->notFound($workstation, $filename);
        }

        $base = realpath((string) config('agent.tools_path'));
        // Normalise un éventuel séparateur final (#1) : sur certaines configs
        // (ou la racine `/` en tests Linux), realpath peut conserver un
        // séparateur terminal — le retirer garantit que la comparaison
        // `str_starts_with($path, $base . DIRECTORY_SEPARATOR)` ci-dessous ne
        // se transforme jamais en un `//` qui fausserait le confinement.
        if ($base !== false) {
            $base = rtrim($base, DIRECTORY_SEPARATOR);
        }
        if ($base === false || $base === '') {
            // Signal ops distinct (iso review 25.1 #8) : répertoire d'outils
            // absent/illisible ≠ artefact inconnu — un parc entier en 404 doit
            // pointer la config, pas un fichier manquant. Réponse client
            // inchangée (404 indistinct, zéro oracle).
            Log::channel('agent')->warning('[ToolController] agent.tool.tools_path_missing', [
                'action_type' => 'agent.tool.tools_path_missing',
                'tools_path' => (string) config('agent.tools_path'),
            ]);

            return $this->notFound($workstation, $filename);
        }

        $path = realpath($base . DIRECTORY_SEPARATOR . $filename);
        if ($path === false
            || ! str_starts_with($path, $base . DIRECTORY_SEPARATOR)
            || ! is_file($path)) {
            return $this->notFound($workstation, $filename);
        }

        // Debug (iso ReleaseController : un download n'est qu'un préalable — la
        // trace de déploiement qui FAIT FOI est l'overlay rendu au logon,
        // validé en lab). Pas de pic info au provisioning d'un parc.
        Log::channel('agent')->debug('[ToolController] agent.tool.download_served', [
            'action_type' => 'agent.tool.download_served',
            'workstation_id' => $workstation->id,
            'filename' => $filename,
        ]);

        return response()->file($path);
    }

    /**
     * Story 25.6 (D8(b)) — MANIFEST tool/skin DÉDIÉ
     * (route `agent.v1.tools.manifest`). Iso `ReleaseController::manifest()` :
     * wrapper SE5 `{success, …}`, JAMAIS un golden item desired-state (un outil
     * de rendu n'est pas une ressource StateItem — le golden overlay/state
     * reste INCHANGÉ). Expose l'outil ACTIF `{key, filename, sha256, size}` (le
     * SHA-256 du portable que l'agent vérifie AVANT extraction — D6, remplace
     * la constante Go figée) et la skin `{filename, sha256}`. Outil absent ou
     * désactivé → `tool: null` (no-op gracieux côté agent — D4) ; skin
     * introuvable → `skin: null`.
     */
    public function manifest(Request $request, AgentToolManifestService $manifests): JsonResponse
    {
        /** @var Workstation $workstation */
        $workstation = $request->attributes->get('agent.workstation');

        $manifest = $manifests->manifest();

        // Debug : un par check-in (volume NFR4) — jamais en info.
        Log::channel('agent')->debug('[ToolController] agent.tool.manifest_served', [
            'action_type' => 'agent.tool.manifest_served',
            'workstation_id' => $workstation->id,
            'tool_enabled' => $manifest['tool'] !== null,
            'skin_available' => $manifest['skin'] !== null,
        ]);

        return response()->json(array_merge(['success' => true], $manifest));
    }

    /**
     * Story 25.6 (D7) — SERVING de la skin d'overlay Rainmeter
     * (route `agent.v1.tools.skin`). PAS d'alias Apache public : la skin n'est
     * pas client-facing comme SYSVOL/wpkg — elle est consommée par l'agent
     * authentifié token (chaîne middleware iso `download()`). Filename FIXE
     * (dérivé serveur, AUCUN input client → anti-traversal par construction) ;
     * le fichier servi est résolu/provisionné depuis la canonique versionnée
     * par {@see OverlaySkinProvisioner}. 404 INDISTINCT si introuvable/illisible
     * (chown www-admin requis en prod sinon serving silencieusement KO).
     * L'INTÉGRITÉ SHA-256 est exposée au manifest et vérifiée CÔTÉ AGENT avant
     * écriture (iso le portable).
     */
    public function skin(Request $request, OverlaySkinProvisioner $skin): BinaryFileResponse|JsonResponse
    {
        /** @var Workstation $workstation */
        $workstation = $request->attributes->get('agent.workstation');

        $path = $skin->resolveServedPath();
        if ($path === null || ! is_file($path)) {
            return $this->skinNotFound($workstation);
        }

        Log::channel('agent')->debug('[ToolController] agent.tool.skin_served', [
            'action_type' => 'agent.tool.skin_served',
            'workstation_id' => $workstation->id,
        ]);

        return response()->file($path);
    }

    /**
     * 404 INDISTINCT pour la skin (iso `notFound()`) : absente/illisible
     * répondent à l'identique — aucun oracle.
     */
    private function skinNotFound(Workstation $workstation): JsonResponse
    {
        Log::channel('agent')->info('[ToolController] agent.tool.skin_not_found', [
            'action_type' => 'agent.tool.skin_not_found',
            'workstation_id' => $workstation->id,
        ]);

        return response()->json([
            'error' => 'not_found',
            'message' => 'Unknown overlay skin',
        ], 404);
    }

    /**
     * 404 INDISTINCT (iso `ReleaseController::notFound()`) : malformé, absent
     * du disque ou répertoire illisible répondent à l'identique.
     */
    private function notFound(Workstation $workstation, string $filename): JsonResponse
    {
        Log::channel('agent')->info('[ToolController] agent.tool.download_not_found', [
            'action_type' => 'agent.tool.download_not_found',
            'workstation_id' => $workstation->id,
            // Input client non authentifié en forme : borné avant log (P5 23.2).
            'filename' => Str::limit($filename, 128),
        ]);

        return response()->json([
            'error' => 'not_found',
            'message' => 'Unknown tool artifact',
        ], 404);
    }
}

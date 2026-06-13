<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Agent;

use App\Auth\V1\Pki\CaInitializer;
use App\Http\Controllers\Controller;
use App\Models\AgentRelease;
use App\Services\Agent\Releases\ReleaseManifestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Story 25.4 — Endpoints d'amorçage LAN NON authentifiés (FR25 + porte 1 de
 * FR16). Les deux chemins d'installation de l'agent (GPO-dispatcher figée pour
 * les postes migrés, unattend iPXE pour les postes neufs) tournent AVANT que
 * l'agent ait un token : ils doivent récupérer le binaire stable et la racine
 * CA sans bearer.
 *
 * Trois endpoints, chaîne middleware iso `/v1/agent/enrollment`
 * (`local.request` + `auth.v1.secure-headers` + `throttle`), HORS du groupe
 * `agent.token` (piège n° 6) :
 *
 *  - `GET /v1/agent/stable` (route `agent.v1.stable`) — le MANIFEST de la
 *    version STABLE `{success, version, hash, url}` ({@see ReleaseManifestService::stableManifest()}),
 *    `url` ABSOLUE. Permet au script d'amorçage de vérifier le hash avant
 *    install (cohérent avec la double-porte 25.2). 404 `no_release` si aucune
 *    stable publiée — jamais un 200 vide.
 *  - `GET /v1/agent/stable/download` (route `agent.v1.stable.download`) —
 *    serving du binaire stable, MÊME confinement realpath que
 *    {@see ReleaseController::download()} (piège n° 8) : pattern strict →
 *    lookup `agent_releases` (résolu sur la stable) → realpath confiné sous
 *    `releases_path` → 404 INDISTINCT pour tout échec. Aucun oracle de
 *    présence.
 *  - `GET /v1/agent/ca` (route `agent.v1.ca`) — racine CA en PEM
 *    (`text/plain`) via {@see CaInitializer::getCaCertPem()} ; **503** si la CA
 *    n'est pas initialisée côté serveur (piège n° 9 — config incomplète, pas
 *    une erreur client).
 *
 * Frontière `agent_*` + zéro AD (NFR7, piège n° 15) : ces endpoints LISENT
 * `agent_releases` (stable) et le `.crt` PKI sur disque ; ils n'écrivent rien
 * et n'appellent AUCUN LdapRecord/Kerberos/samba-tool. Identité néant : le
 * périmètre réseau est porté par `local.request` (LAN/VPN de confiance).
 */
class BootstrapController extends Controller
{
    /**
     * Forme produite par le build 24.5 : `sambaedu-agent-<version>.exe`.
     * Iso {@see ReleaseController::FILENAME_PATTERN} — toute autre forme
     * (traversal, casse, extension) = 404 immédiat, AVANT tout accès disque
     * ou DB.
     */
    private const FILENAME_PATTERN = '/^sambaedu-agent-[0-9A-Za-z.+~-]+\.exe$/';

    public function __construct(
        private readonly ReleaseManifestService $manifests,
        private readonly CaInitializer $ca,
    ) {
    }

    /**
     * Manifest de la version stable (amorçage). Jamais de résolution par ring :
     * l'appelant n'a pas de token, donc aucun poste résolu.
     */
    public function stable(): JsonResponse
    {
        $manifest = $this->manifests->stableManifest();
        if ($manifest === null) {
            Log::channel('agent')->debug('[BootstrapController] agent.release.no_release', [
                'action_type' => 'agent.release.no_release',
                'scope' => 'bootstrap',
            ]);

            return response()->json([
                'error' => 'no_release',
                'message' => 'No stable release published',
            ], 404);
        }

        Log::channel('agent')->info('[BootstrapController] agent.release.stable_served', [
            'action_type' => 'agent.release.stable_served',
            'version' => $manifest['version'],
        ]);

        return response()->json(array_merge(['success' => true], $manifest));
    }

    /**
     * Download du binaire stable (amorçage). URL FIXE : la résolution du
     * filename est interne (la STABLE publiée), JAMAIS un input client — le
     * script d'amorçage télécharge « le » binaire stable sans le nommer (et
     * l'écrit à son emplacement définitif `agent.exe`, piège n° 10).
     *
     * Confinement realpath iso {@see ReleaseController::download()} (piège n° 8) :
     * le filename résolu en DB est re-validé par le pattern strict puis confiné
     * sous `releases_path` (seconde ligne de défense, et garde-fou si une ligne
     * `agent_releases` portait un filename pathologique). 404 INDISTINCT pour
     * tout échec (pas de stable, fichier absent, config manquante), zéro
     * oracle.
     */
    public function download(): BinaryFileResponse|JsonResponse
    {
        // Lookup DB d'abord (piège n° 8) : seule la STABLE publiée est servie —
        // un binaire orphelin ou une canari ne fuit jamais par cet endpoint
        // d'amorçage. Tie-break id desc iso `stableManifest()`.
        $release = AgentRelease::query()
            ->where('is_stable', true)
            ->orderByDesc('id')
            ->first();
        if ($release === null) {
            return $this->notFound('<stable>');
        }

        $filename = (string) $release->filename;
        // Re-validation stricte du filename résolu en DB (défense en profondeur :
        // une ligne au filename pathologique ne doit jamais sortir du confinement).
        if (strlen($filename) > 255 || preg_match(self::FILENAME_PATTERN, $filename) !== 1) {
            return $this->notFound($filename);
        }

        $base = realpath((string) config('agent.releases_path'));
        if ($base === false) {
            Log::channel('agent')->warning('[BootstrapController] agent.release.releases_path_missing', [
                'action_type' => 'agent.release.releases_path_missing',
                'releases_path' => (string) config('agent.releases_path'),
            ]);

            return $this->notFound($filename);
        }
        $path = realpath($base . DIRECTORY_SEPARATOR . $filename);
        if ($path === false
            || ! str_starts_with($path, $base . DIRECTORY_SEPARATOR)
            || ! is_file($path)) {
            return $this->notFound($filename);
        }

        // Niveau `debug` + action_type distinct du manifest (iso 25.1
        // `download_served`) : le téléchargement est un préalable sans garantie
        // d'install — la trace qui fait foi reste la version rapportée au
        // check-in (contrat 25.2). Distinguer manifest vs binaire évite
        // d'agréger « interrogé » et « téléchargé » en observabilité parc.
        Log::channel('agent')->debug('[BootstrapController] agent.release.stable_download_served', [
            'action_type' => 'agent.release.stable_download_served',
            'version' => $release->version,
            'filename' => $filename,
            'binary' => true,
        ]);

        return response()->file($path);
    }

    /**
     * Racine CA en PEM (amorçage). 503 si la CA n'est pas initialisée
     * côté serveur (piège n° 9 — `php artisan auth:ca:init` est le prérequis),
     * jamais 500.
     */
    public function ca(): Response
    {
        try {
            $pem = $this->ca->getCaCertPem();
        } catch (RuntimeException $e) {
            Log::channel('agent')->warning('[BootstrapController] agent.ca.unavailable', [
                'action_type' => 'agent.ca.unavailable',
                'error' => $e->getMessage(),
            ]);

            return response(
                'CA root not initialized — run php artisan auth:ca:init',
                503,
                ['Content-Type' => 'text/plain'],
            );
        }

        Log::channel('agent')->info('[BootstrapController] agent.ca.served', [
            'action_type' => 'agent.ca.served',
        ]);

        return response($pem, 200, ['Content-Type' => 'text/plain']);
    }

    /**
     * 404 INDISTINCT (iso {@see ReleaseController::notFound()}) : malformé,
     * inconnu en DB ou absent du disque répondent à l'identique — aucun oracle.
     */
    private function notFound(string $filename): JsonResponse
    {
        Log::channel('agent')->info('[BootstrapController] agent.release.stable_not_found', [
            'action_type' => 'agent.release.stable_not_found',
            'filename' => Str::limit($filename, 128),
        ]);

        return response()->json([
            'error' => 'not_found',
            'message' => 'Unknown release',
        ], 404);
    }
}

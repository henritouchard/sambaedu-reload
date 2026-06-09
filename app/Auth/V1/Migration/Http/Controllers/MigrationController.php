<?php

declare(strict_types=1);

namespace App\Auth\V1\Migration\Http\Controllers;

use App\Auth\V1\Migration\Exceptions\CaUnavailableException;
use App\Auth\V1\Migration\Services\MigrationFragmentRenderer;
use App\Auth\V1\Migration\Services\MigrationStatusChecker;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Module de migration SE4 → SE5.
 *
 * Ce code pourra être supprimé lorsqu'il n'existera plus de nécessité de
 * migrer un déploiement SE4 vers SE5 (typiquement : quand aucun collège
 * actif n'utilise plus SE4 = sambaedu legacy PHP-only).
 *
 * Sprint Change Proposal 2026-05-19. Story 16.13bis.
 *
 * Controller unique qui transforme les 8 routes legacy
 * `/sambaedu/gpo/{shortcuts,wallpaper,firefox,thunderbird,network,veyon,
 * associations,applications}*.php` en **fragment+reboot** stateless :
 *
 *  - **Poste non-migré** : renvoie un script `text/plain` cmd|sh qui
 *    télécharge le CA root, s'enrôle auprès de `/api/v1/agent/enroll`
 *    (16.10), écrit le registre / `/etc/sambaedu/endpoints.conf` pointant
 *    vers `/api/v1/workstation-config/*` (16.13), puis `shutdown /r /t 30`.
 *  - **Poste déjà migré** : renvoie un script no-op qui s'arrête
 *    immédiatement (idempotence — pas de reboot intempestif).
 *
 * Le paramètre `$endpoint` (`shortcuts`, `wallpaper`, …) est figé par la
 * définition de route (closure) — il n'est pas dérivé d'input
 * user-controlled.
 *
 * **Sécurité** :
 *  - Pas d'auth (le poste non-migré n'a pas encore de JWT — c'est ce que
 *    le fragment va lui poser).
 *  - Throttle `300,1` conservé (parité rentrée scolaire).
 *  - Réponse `Cache-Control: no-store` (TLS strict 16.12 Q5 — pas de
 *    caching du fragment qui embarque le CA root inline).
 *  - Content-Type `text/plain; charset=utf-8`. Le `chcp 65001` Windows
 *    assure le rendu correct des accents côté console poste.
 *
 * @see \App\Auth\V1\Migration\Services\MigrationFragmentRenderer
 * @see \App\Auth\V1\Migration\Services\MigrationStatusChecker
 */
final class MigrationController extends Controller
{
    /** Endpoints legacy supportés (validation défensive). */
    private const SUPPORTED_ENDPOINTS = [
        'shortcuts',
        'wallpaper',
        'firefox',
        'thunderbird',
        'network',
        'veyon',
        'associations',
        'applications',
    ];

    /** Content-Type renvoyé (parité iso-legacy `applications.php`). */
    private const CONTENT_TYPE = 'text/plain; charset=utf-8';

    /**
     * Correctif 2026-06-05 (gap post-migration, décision Henri) : handlers
     * fonctionnels iso-legacy par endpoint. Tous portés/testés (Epics 16-17)
     * mais jamais routés — la story 16.13bis a donné les 8 routes au fragment
     * de migration en supposant un « client natif » consommant
     * `Endpoints\*Url` qui n'existe pas encore. Sans passthrough, un poste
     * migré ne reçoit plus AUCUNE conf applicative (wallpaper, shortcuts, …)
     * et les sous-appels `*_out.php` du script assemblé enregistreraient un
     * fragment cmd en guise d'image/conf.
     *
     * @var array<string, array{class-string, string}>
     */
    private const PASSTHROUGH_HANDLERS = [
        'applications' => [\App\Http\Controllers\Gpo\ApplicationsScriptsController::class, 'generate'],
        'wallpaper' => [\App\Http\Controllers\WallpaperController::class, 'legacyOut'],
        'network' => [\App\Http\Controllers\Gpo\NetworkOutController::class, 'legacyOut'],
        'veyon' => [\App\Http\Controllers\Gpo\VeyonOutController::class, 'legacyOut'],
        'associations' => [\App\Http\Controllers\Gpo\AssociationsOutController::class, 'legacyOut'],
        'shortcuts' => [\App\Http\Controllers\Api\v1\ShortcutExportController::class, 'legacyDispatch'],
        'firefox' => [\App\Http\Controllers\AppPolicyController::class, 'legacyFirefoxOut'],
        'thunderbird' => [\App\Http\Controllers\AppPolicyController::class, 'legacyThunderbirdOut'],
    ];

    public function __construct(
        private readonly MigrationFragmentRenderer $renderer,
        private readonly MigrationStatusChecker $statusChecker,
    ) {
    }

    /**
     * Sert le fragment de migration pour l'`$endpoint` legacy invoqué.
     *
     * @param  string  $endpoint  un des 8 endpoints supportés
     */
    public function serveFragment(Request $request, string $endpoint): Response
    {
        // Validation défensive : si la route appelle ce controller avec un
        // endpoint inconnu (cas d'erreur de routing), on log et on renvoie
        // un noop générique (un poste qui reçoit un script no-op exit /b 0
        // ne fait pas de dégâts).
        if (! in_array($endpoint, self::SUPPORTED_ENDPOINTS, true)) {
            Log::channel('auth-v1')->warning(
                '[MigrationController] migration.endpoint.unsupported',
                [
                    'action_type' => 'migration.endpoint.unsupported',
                    'endpoint' => $endpoint,
                    'ip' => $request->ip(),
                ],
            );
            $endpoint = 'unknown';
        }

        $os = $this->renderer->detectOs($request);
        $declaredUuid = $this->statusChecker->extractDeclaredUuid($request);

        // Correctif 2026-06-05 : les scripts GPO legacy n'envoient pas de
        // `uuid` (seulement `machine`) — sans résolution serveur, le token
        // minté non lié condamne l'enroll au 401 uuid_mismatch (16.11
        // fail-closed). Cf. MigrationStatusChecker::resolveUuidFromMachineName.
        if ($declaredUuid === null) {
            $declaredUuid = $this->statusChecker->resolveUuidFromMachineName($request);
            if ($declaredUuid !== null) {
                Log::channel('auth-v1')->info(
                    '[MigrationController] migration.uuid.resolved_from_machine',
                    [
                        'action_type' => 'migration.uuid.resolved_from_machine',
                        'uuid_prefix' => substr(hash('sha256', $declaredUuid), 0, 8),
                        'ip' => $request->ip(),
                    ],
                );
            }
        }

        // Trace systématique (status='started') — best-effort.
        $this->statusChecker->logAttempt($request, $os, $declaredUuid);

        $alreadyMigrated = $this->statusChecker->isMigrated($declaredUuid);

        // Correctif 2026-06-05 (gap post-migration, décision Henri) — voir
        // PASSTHROUGH_HANDLERS. Deux déclencheurs :
        //  - poste migré (uuid résolu) : `applications.php` sert le script
        //    assemblé iso-legacy (confiance LAN, parité décision wpkg
        //    2026-05-04 #3) au lieu du no-op ;
        //  - requête porteuse d'un `id` de contexte 32-hex : c'est un
        //    sous-appel du script assemblé (seul `generate()` pose ces
        //    contextes) — les appels `*_out` n'envoient ni uuid ni machine,
        //    l'id est leur seul marqueur. Contexte expiré → le handler
        //    répond 404 « Context expired » (iso-legacy), bien moins nocif
        //    qu'un fragment cmd enregistré en wallpaper.jpg.
        $handler = self::PASSTHROUGH_HANDLERS[$endpoint] ?? null;
        $hasContextId = preg_match('/^[a-f0-9]{32}$/i', (string) $request->input('id', '')) === 1;
        $shouldPassthrough = $endpoint === 'applications'
            ? ($alreadyMigrated || $hasContextId)               // poste migré OU sous-appel porteur d'un id de contexte (ex. fragment powershell `interpreter=powershell` + id, sans machine) ; sinon il reçoit à tort le fragment de migration CMD
            : $hasContextId;                                    // sous-appel du script assemblé (seul marqueur) ; uuid sans id → noop (iso 16.13bis)
        if ($handler !== null && $shouldPassthrough) {
            Log::channel('auth-v1')->info(
                '[MigrationController] migration.passthrough',
                [
                    'action_type' => 'migration.passthrough',
                    'endpoint' => $endpoint,
                    'trigger' => $alreadyMigrated ? 'migrated' : 'context_id',
                    'uuid_prefix' => $declaredUuid !== null ? substr(hash('sha256', $declaredUuid), 0, 8) : null,
                    'ip' => $request->ip(),
                ],
            );

            return app($handler[0])->{$handler[1]}($request);
        }

        if ($alreadyMigrated) {
            Log::channel('auth-v1')->info(
                '[MigrationController] migration.fragment.noop',
                [
                    'action_type' => 'migration.fragment.noop',
                    'endpoint' => $endpoint,
                    'os' => $os,
                    'uuid_prefix' => $declaredUuid !== null ? substr(hash('sha256', $declaredUuid), 0, 8) : null,
                    'ip' => $request->ip(),
                ],
            );
            $body = $this->renderer->renderNoopFragment($os);
        } else {
            Log::channel('auth-v1')->info(
                '[MigrationController] migration.fragment.served',
                [
                    'action_type' => 'migration.fragment.served',
                    'endpoint' => $endpoint,
                    'os' => $os,
                    'uuid_prefix' => $declaredUuid !== null ? substr(hash('sha256', $declaredUuid), 0, 8) : null,
                    'ip' => $request->ip(),
                    'user_agent' => mb_substr((string) $request->userAgent(), 0, 200),
                ],
            );
            try {
                // Correction Q1 Option A (2026-05-20) : le renderer mint un
                // bootstrap_token APCu lié à l'UUID et l'injecte comme variable
                // Blade `bootstrap_token` dans le fragment.
                $body = $this->renderer->renderFullFragment($os, $declaredUuid);
            } catch (CaUnavailableException $e) {
                // Story 16.13bis — Correction Opus-B (2026-05-20) :
                // CA absent en production → 503 explicite avec body
                // text/plain user-friendly (pas de fragment CA-vide silencieux).
                Log::channel('auth-v1')->error(
                    '[MigrationController] migration.fragment.ca_missing',
                    [
                        'action_type' => 'migration.fragment.ca_missing',
                        'endpoint' => $endpoint,
                        'os' => $os,
                        'ip' => $request->ip(),
                        'error' => $e->getMessage(),
                    ],
                );

                return response(
                    "SambaEdu : CA root indisponible cote serveur. Contactez l'administrateur.\n",
                    503,
                )
                    ->header('Content-Type', self::CONTENT_TYPE)
                    ->header('Cache-Control', 'no-store, no-cache, must-revalidate, private')
                    ->header('X-Migration-Fragment', 'error-ca-missing');
            }
        }

        return response($body, 200)
            ->header('Content-Type', self::CONTENT_TYPE)
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, private')
            ->header('X-Migration-Fragment', $alreadyMigrated ? 'noop' : 'full');
    }
}

<?php

declare(strict_types=1);

namespace App\Auth\V1\Migration\Services;

use App\Auth\V1\Migration\Exceptions\CaUnavailableException;
use App\Auth\V1\Migration\Support\MigrationMessages;
use App\Auth\V1\Pki\CaInitializer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\View;
use RuntimeException;
use Throwable;

/**
 * Module de migration SE4 → SE5.
 *
 * Ce code pourra être supprimé lorsqu'il n'existera plus de nécessité de
 * migrer un déploiement SE4 vers SE5 (typiquement : quand aucun collège
 * actif n'utilise plus SE4 = sambaedu legacy PHP-only).
 *
 * Sprint Change Proposal 2026-05-19. Story 16.13bis.
 *
 * Orchestrateur du rendu Blade des fragments cmd / sh / noop :
 *  - `detectOs(Request)` : priorité query `?os=`, fallback User-Agent.
 *  - `render($os, $alreadyMigrated)` : choisit le template Blade selon
 *    OS + état migration, substitue les variables (`server_base_url`,
 *    `ca_cert_pem_b64`, `enroll_endpoint`, `workstation_config_base`,
 *    `migration_message_fr`).
 *  - Cache 5 min du template "statique" complet (cf. D5 — le fragment
 *    cmd/sh n'a pas de partie dépendante du UUID poste car le UUID est
 *    collecté côté poste runtime).
 */
final class MigrationFragmentRenderer
{
    /** TTL cache process-local des templates rendus (~5 min). */
    private const CACHE_TTL_SECONDS = 300;

    /**
     * TTL APCu pour le bootstrap_token minté côté serveur.
     * 1800s = parité 16.11 (`ApcuAppContextWriter::write`).
     */
    private const BOOTSTRAP_TOKEN_TTL_SECONDS = 1800;

    /**
     * Cache process-local des templates rendus.
     *
     * @var array<string, array{rendered_at: int, body: string}>
     */
    private static array $cache = [];

    public function __construct(
        private readonly CaInitializer $caInitializer,
    ) {
    }

    /**
     * Détecte l'OS cible du fragment.
     *
     * Priorité 1 : query/body `os=windows|linux`.
     * Priorité 2 : User-Agent heuristic.
     * Default  : `windows` (parité legacy — majorité du parc).
     */
    public function detectOs(Request $request): string
    {
        $osParam = strtolower((string) $request->input('os', ''));
        if ($osParam === 'windows' || $osParam === 'linux') {
            return $osParam;
        }

        $ua = strtolower((string) $request->userAgent());
        if ($ua === '') {
            return 'windows';
        }

        if (str_contains($ua, 'windows')
            || str_contains($ua, 'cmd.exe')
            || str_contains($ua, 'win32')
            || str_contains($ua, 'winhttp')
            || str_contains($ua, 'powershell')) {
            return 'windows';
        }

        if (str_contains($ua, 'linux')
            || str_contains($ua, 'x11')
            || str_contains($ua, 'wget')) {
            return 'linux';
        }

        return 'windows';
    }

    /**
     * Rend le fragment complet (cmd ou sh) pour un poste **non-migré**.
     * Retourne le body `text/plain` prêt à servir au poste.
     *
     * Story 16.13bis — Correction Q1 Option A (2026-05-20) :
     *  - `$declaredUuid` (optionnel) : UUID v4 du poste pour lier le
     *    bootstrap_token minté au poste (parité couple token↔UUID 16.11).
     *  - Le fragment intègre un `BOOTSTRAP_TOKEN` minté côté serveur via
     *    `mintBootstrapToken()` (clé APCu `apps.<token>` TTL 1800s — parité
     *    `LegacyBootstrapTokenValidator`). Le poste utilise ce token dans
     *    l'enroll POST → `RequireBootstrapToken` valide.
     *
     * ⚠️ Le cache des fragments est **désactivé** dès qu'un token est minté
     * (chaque poste reçoit son propre token éphémère). Le cache process-local
     * historique n'est utilisé que pour le cas dégradé `$declaredUuid === null`
     * (rare : query sans uuid → token non lié, validation 16.10 standard).
     */
    public function renderFullFragment(string $os, ?string $declaredUuid = null): string
    {
        // Cache process-local désactivé dès qu'un uuid est connu : chaque
        // poste doit recevoir un token unique éphémère.
        $useCache = ($declaredUuid === null);
        $cacheKey = 'full:' . $os;
        if ($useCache) {
            $cached = $this->readCache($cacheKey);
            if ($cached !== null) {
                return $cached;
            }
        }

        $template = $os === 'linux' ? 'auth.v1.migration.fragment-sh' : 'auth.v1.migration.fragment-cmd';

        try {
            $vars = $this->resolveTemplateVariables($declaredUuid);
            $body = View::make($template, $vars)->render();
            // Story 16.13bis : PHP strip la shebang `#!/...` initiale d'un fichier
            // compilé Blade — on la préfixe ici pour les fragments Linux.
            if ($os === 'linux') {
                $body = "#!/bin/bash\n" . $body;
            } else {
                // Correctif 2026-06-05 (bug terrain windaube) : le template
                // Blade est stocké en fins de ligne Unix (LF) — or cmd.exe
                // exige CRLF. Un .cmd en LF-only désaligne le parseur batch
                // qui exécute des fragments de lignes au milieu des mots
                // ("'gration' n'est pas reconnu…") : le fragment n'a jamais
                // été exécutable. Normalisation systématique en CRLF.
                $body = $this->toCrlf($body);
            }
        } catch (CaUnavailableException $e) {
            // Story 16.13bis — Correction Opus-B : en production, CA absent
            // → on remonte au controller pour 503 explicite. Pas de fallback
            // noop silencieux qui masquerait le problème opérationnel.
            throw $e;
        } catch (Throwable $e) {
            Log::channel('auth-v1')->error(
                '[MigrationFragmentRenderer] migration.fragment.render_failed',
                [
                    'action_type' => 'migration.fragment.render_failed',
                    'os' => $os,
                    'template' => $template,
                    'error' => $e->getMessage(),
                ],
            );

            // En cas d'erreur de rendu, on renvoie le no-op (le poste exit
            // sans rien faire — défense en profondeur, mieux que crasher).
            return $this->renderNoopFragment($os);
        }

        if ($useCache) {
            $this->writeCache($cacheKey, $body);
        }

        return $body;
    }

    /**
     * Rend le fragment court "no-op" (poste déjà migré).
     */
    public function renderNoopFragment(string $os): string
    {
        $cacheKey = 'noop:' . $os;
        $cached = $this->readCache($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $template = $os === 'linux' ? 'auth.v1.migration.fragment-noop-sh' : 'auth.v1.migration.fragment-noop-cmd';

        try {
            $body = View::make($template, [
                'noop_message' => $os === 'linux' ? MigrationMessages::NOOP_FR_LINUX : MigrationMessages::NOOP_FR_WIN,
            ])->render();
            // Story 16.13bis : voir renderFullFragment — shebang préfixée en PHP.
            if ($os === 'linux') {
                $body = "#!/bin/bash\n" . $body;
            } else {
                // Correctif 2026-06-05 : CRLF obligatoire pour cmd.exe — voir
                // renderFullFragment (le fallback inline ci-dessous l'avait déjà).
                $body = $this->toCrlf($body);
            }
        } catch (Throwable $e) {
            Log::channel('auth-v1')->error(
                '[MigrationFragmentRenderer] migration.fragment.noop_render_failed',
                [
                    'action_type' => 'migration.fragment.noop_render_failed',
                    'os' => $os,
                    'template' => $template,
                    'error' => $e->getMessage(),
                ],
            );

            // Ultime fallback inline minimal.
            return $os === 'linux'
                ? "#!/bin/bash\necho \"SambaEdu: déjà migré, no-op.\"\nexit 0\n"
                : "@echo off\r\necho SambaEdu : poste deja migre, no-op.\r\nexit /b 0\r\n";
        }

        $this->writeCache($cacheKey, $body);

        return $body;
    }

    /**
     * Normalise les fins de ligne en CRLF (exigence cmd.exe pour les
     * fragments Windows). Idempotent : les CRLF déjà présents ne sont pas
     * doublés.
     */
    private function toCrlf(string $body): string
    {
        return preg_replace('/\r?\n/', "\r\n", $body) ?? $body;
    }

    /**
     * Construit le tableau de variables Blade pour le fragment complet.
     *
     * Story 16.13bis — Correction Q1 Option A : mint d'un `bootstrap_token`
     * lié au poste (clé APCu `apps.<token>` TTL 1800s parité 16.11). Si
     * `$declaredUuid === null` (cas dégradé), le token est minté avec un UUID
     * placeholder (validation 16.10 standard sans couple token↔UUID).
     *
     * @return array<string, string>
     */
    private function resolveTemplateVariables(?string $declaredUuid = null): array
    {
        $serverBaseUrl = $this->resolveServerBaseUrl();
        $caCertPemB64 = $this->resolveCaCertB64();
        $bootstrapToken = $this->mintBootstrapToken($declaredUuid);

        return [
            'server_base_url' => $serverBaseUrl,
            'ca_cert_pem_b64' => $caCertPemB64,
            'enroll_endpoint' => $this->safeRoute('agent.v1.enroll', $serverBaseUrl . '/api/v1/agent/enroll'),
            'refresh_endpoint' => $this->safeRoute('agent.v1.refresh', $serverBaseUrl . '/api/v1/agent/refresh'),
            'workstation_config_base' => $this->safeUrl('/api/v1/workstation-config', $serverBaseUrl . '/api/v1/workstation-config'),
            'migration_message_fr' => MigrationMessages::REBOOT_FR,
            'migration_message_fr_noaccents' => MigrationMessages::REBOOT_FR_NOACCENTS,
            'bootstrap_token' => $bootstrapToken,
        ];
    }

    /**
     * Story 16.13bis — Correction Q1 Option A (2026-05-20).
     *
     * Génère un bootstrap_token éphémère (32 chars hex, parité regex
     * `auth_v1.bootstrap_token.token_regex`) et le stocke en APCu sous la
     * clé `apps.<token>` avec le payload `['uuid' => $declaredUuid, 'time' => time()]`
     * (parité `ApcuAppContextWriter::write` 16.7). TTL 1800s (= 30 min,
     * parité `LegacyBootstrapTokenValidator`).
     *
     * Si `$declaredUuid` est `null` (poste sans uuid déclaré dans la query),
     * on stocke `uuid => ''` : la validation passera en mode 16.10 (token
     * APCu présent suffit, pas de check couple).
     *
     * Si APCu indisponible (CLI sans extension, ou tests sans APCu) : on
     * retourne un token "best-effort" non posé en cache. Le `RequireBootstrapToken`
     * échouera côté enroll → le poste retentera au prochain boot. C'est
     * cohérent avec la dégradation gracieuse `LegacyBootstrapTokenValidator::isValid`
     * qui retourne `false` si APCu absent (fail-closed).
     *
     * Visibilité `public` pour permettre aux tests d'asserter le pattern et
     * la pose APCu de manière isolée.
     */
    public function mintBootstrapToken(?string $declaredUuid = null): string
    {
        try {
            $token = bin2hex(random_bytes(16)); // 32 chars hex.
        } catch (Throwable $e) {
            // Cas extrême : pas d'entropie système. On fallback sur un hash
            // sha256 tronqué (32 chars hex) — pas crypto-safe mais > rien.
            $token = substr(hash('sha256', uniqid('', true) . microtime(true)), 0, 32);

            Log::channel('auth-v1')->warning(
                '[MigrationFragmentRenderer] migration.bootstrap_token.entropy_fallback',
                [
                    'action_type' => 'migration.bootstrap_token.entropy_fallback',
                    'error' => $e->getMessage(),
                ],
            );
        }

        $payload = [
            'uuid' => $declaredUuid ?? '',
            'time' => time(),
        ];

        $prefix = (string) config('auth_v1.bootstrap_token.apcu_prefix', 'apps.');
        $cacheKey = $prefix . $token;

        if (function_exists('apcu_store') && function_exists('apcu_enabled') && apcu_enabled()) {
            $stored = @apcu_store($cacheKey, $payload, self::BOOTSTRAP_TOKEN_TTL_SECONDS);
            if ($stored === false) {
                Log::channel('auth-v1')->warning(
                    '[MigrationFragmentRenderer] migration.bootstrap_token.apcu_store_failed',
                    [
                        'action_type' => 'migration.bootstrap_token.apcu_store_failed',
                        'token_hash_prefix' => substr(hash('sha256', $token), 0, 8),
                    ],
                );
            }
        } else {
            Log::channel('auth-v1')->info(
                '[MigrationFragmentRenderer] migration.bootstrap_token.apcu_unavailable',
                [
                    'action_type' => 'migration.bootstrap_token.apcu_unavailable',
                    'token_hash_prefix' => substr(hash('sha256', $token), 0, 8),
                ],
            );
        }

        return $token;
    }

    /**
     * Récupère le CA root local en base64 — gracieux en testing/local
     * (placeholder vide si PKI non initialisée).
     *
     * Story 16.13bis — Correction Opus-B (2026-05-20) :
     * En production, si le CA est absent, on **lève** une `RuntimeException`
     * (capturée plus haut par `MigrationController` → 503 explicite). Le
     * fragment Windows continuait silencieusement avec CA vide → certutil
     * échouait mais après installation tronquée → defense-in-depth.
     *
     * En dev/test, on retourne `''` pour ne pas casser les tests Feature
     * qui tournent sans PKI initialisée (comportement DO-7 historique).
     *
     * @throws RuntimeException si CA absent en environment `production`.
     */
    private function resolveCaCertB64(): string
    {
        try {
            $pem = $this->caInitializer->getCaCertPem();

            return base64_encode($pem);
        } catch (RuntimeException $e) {
            Log::channel('auth-v1')->warning(
                '[MigrationFragmentRenderer] migration.fragment.ca_missing',
                [
                    'action_type' => 'migration.fragment.ca_missing',
                    'error' => $e->getMessage(),
                    'environment' => app()->environment(),
                ],
            );

            if (app()->environment('production')) {
                // En production : fail-closed serveur. Le caller (controller)
                // doit transformer ça en 503 explicite — pas de fragment
                // CA-vide silencieux qui partirait au poste.
                throw new CaUnavailableException(
                    'SambaEdu : CA root indisponible côté serveur. '
                    . 'Contactez l\'administrateur (php artisan auth:ca:init).',
                    0,
                    $e,
                );
            }

            // Dev/test : placeholder vide (le fragment côté poste fail
            // explicitement au step "decode CA" — fail fermé client).
            return '';
        }
    }

    /**
     * Résout l'URL HTTPS du serveur (iso 16.10 / 16.11 EnrollController).
     */
    private function resolveServerBaseUrl(): string
    {
        $configured = (string) config('auth_v1.server.base_url', '');
        if ($configured !== '') {
            return rtrim($configured, '/');
        }

        $se4fs = (string) config('sambaedu.se4fs_name', '');
        $suffix = (string) config('auth_v1.server.host_suffix', '');
        if ($se4fs !== '') {
            $host = $suffix === '' ? $se4fs : ($se4fs . '.' . ltrim($suffix, '.'));

            return 'https://' . $host;
        }

        $appUrl = (string) config('app.url', '');
        if ($appUrl !== '') {
            return rtrim(preg_replace('/^http:/i', 'https:', $appUrl) ?? $appUrl, '/');
        }

        return 'https://localhost';
    }

    /**
     * `route($name)` avec fallback (la route peut ne pas exister en tests
     * isolés — on tolère).
     */
    private function safeRoute(string $name, string $fallback): string
    {
        try {
            return route($name);
        } catch (Throwable) {
            return $fallback;
        }
    }

    /**
     * `url($path)` avec fallback (cas test sans baseUrl Laravel résolu).
     */
    private function safeUrl(string $path, string $fallback): string
    {
        try {
            $resolved = url($path);
            if (is_string($resolved) && $resolved !== '') {
                return rtrim($resolved, '/');
            }
        } catch (Throwable) {
            // fallthrough
        }

        return $fallback;
    }

    private function readCache(string $key): ?string
    {
        if (! isset(self::$cache[$key])) {
            return null;
        }
        $entry = self::$cache[$key];
        if (time() - $entry['rendered_at'] > self::CACHE_TTL_SECONDS) {
            unset(self::$cache[$key]);

            return null;
        }

        return $entry['body'];
    }

    private function writeCache(string $key, string $body): void
    {
        self::$cache[$key] = [
            'rendered_at' => time(),
            'body' => $body,
        ];
    }

    /**
     * Vide le cache template (utile en tests).
     */
    public static function clearCache(): void
    {
        self::$cache = [];
    }
}

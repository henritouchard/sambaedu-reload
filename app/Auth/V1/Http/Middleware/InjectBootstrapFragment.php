<?php

declare(strict_types=1);

namespace App\Auth\V1\Http\Middleware;

use App\Auth\V1\Models\WorkstationMigrationStatus;
use App\Services\AppCustomization\Contracts\AppContextWriter;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Story 16.11 — AC5.1.
 *
 * Middleware post-response qui injecte un **fragment de bootstrap** en
 * **préfixe** des réponses des 8 endpoints legacy whitelistés, **uniquement
 * si** le poste n'est pas encore migré.
 *
 * Effet : le prochain logon/startup d'un poste non-migré exécute le
 * fragment cmd/sh injecté qui curl pipe vers `/api/v1/agent/bootstrap.{cmd,sh}`
 * et déclenche la chaîne de bascule.
 *
 * **Idempotence** :
 *
 *  1. Lookup `WorkstationMigrationStatus` par uuid → skip si déjà migré.
 *  2. Côté poste, le fragment est short-circuit par un check local
 *     (`auth.json` Linux / registry Win) avant toute action.
 *  3. Côté serveur, `EnrollController` est déjà idempotent (16.10 AC5.1).
 *
 * **Garde-fous skip** (no-op silencieux dans tous ces cas) :
 *
 *  - Content-Type response != `text/plain` (cas `associations_out.php` JSON → D6).
 *  - Status 4xx/5xx (pas d'injection sur erreur — le poste va retry).
 *  - Pas de `uuid` dans la requête (poste pré-bootstrap qui ne sait pas
 *    encore poser uuid — sera migré au prochain cycle).
 *  - Poste déjà dans `workstations_migration_status`.
 *  - StreamedResponse (improbable sur nos endpoints, défense en profondeur).
 *
 * **Détection OS** (pour choisir le fragment cmd vs sh) :
 *
 *  - Priorité 1 : query/body `os` (déjà présent sur 6/8 endpoints — convention
 *    iso `ApplicationsScriptsController:86`).
 *  - Priorité 2 : User-Agent heuristic (Windows/Win32/cmd.exe → windows ; Linux/X11 → linux).
 *  - Default : windows (parité legacy — majorité du parc).
 *
 * **Story 16.11 Q1.b — BOOTSTRAP_TOKEN transmission au script complet** :
 *
 *  - Le fragment de migration doit transmettre un `BOOTSTRAP_TOKEN` (md5
 *    32 hex) au script complet `bootstrap.{cmd,sh}` qui l'utilise comme
 *    `X-Bootstrap-Token` lors du POST `/api/v1/agent/enroll`.
 *  - Le middleware **génère un token md5 frais**, pose un contexte APCu
 *    minimal `apps.<token>` avec la clé `uuid` matching, et injecte ce
 *    token dans les substitutions Blade `bootstrap_token`.
 *  - Pourquoi générer vs réutiliser l'id de la requête : robustesse
 *    (certaines routes legacy n'ont pas d'`id` exploitable ; certains
 *    payloads pré-Q1.a n'ont pas d'`uuid` dans APCu) + isolation (un
 *    token dédié au bootstrap, jeté après usage côté serveur).
 *  - TTL APCu : 1800s (parité iso legacy `apps.<id>` ApcuAppContextWriter).
 *
 * **Position dans la chaîne** : `handle()` POST-`$next()` strict (D10).
 *
 * @see App\Auth\V1\Models\WorkstationMigrationStatus
 * @see App\Services\AppCustomization\ApcuAppContextWriter
 * @see resources/views/auth/v1/bootstrap-fragment-cmd.blade.php
 * @see resources/views/auth/v1/bootstrap-fragment-sh.blade.php
 */
class InjectBootstrapFragment
{
    /**
     * Cache local statique des fragments « statiques » (server_base_url + se4fs_name
     * + domain) rendus — la partie dynamique `bootstrap_token` est injectée par
     * substitution `###_BOOTSTRAP_TOKEN_###` après rendu pour conserver
     * l'optimisation perf sur boot de masse.
     */
    private static array $fragmentTemplateCache = [];

    /** Marqueur placeholder remplacé par le token md5 frais à chaque injection. */
    private const TOKEN_PLACEHOLDER = '###_BOOTSTRAP_TOKEN_###';

    /** TTL APCu du contexte `apps.<token>` posé par le middleware (parité iso legacy). */
    private const BOOTSTRAP_TOKEN_TTL = 1800;

    public function __construct(
        private readonly AppContextWriter $contextWriter,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        // Le middleware est post-response : on laisse d'abord le controller bosser.
        $response = $next($request);

        // Garde-fous skip silencieux.
        if (! $this->shouldInject($request, $response)) {
            return $response;
        }

        $uuid = $this->extractUuid($request);
        if ($uuid === null) {
            // Pas d'uuid dans la requête → poste pré-bootstrap. Skip (no-op).
            return $response;
        }

        // Lookup status migration — si déjà migré, skip silencieusement.
        try {
            $alreadyMigrated = WorkstationMigrationStatus::query()
                ->where('workstation_uuid', $uuid)
                ->exists();
        } catch (\Throwable $e) {
            // Best-effort : si DB indispo, on évite d'injecter (mieux vaut ne
            // pas perturber la réponse legacy qu'injecter en aveugle).
            Log::channel('auth-v1')->warning(
                '[InjectBootstrapFragment] auth.bootstrap.fragment.db_error',
                [
                    'action_type' => 'auth.bootstrap.fragment.db_error',
                    'error' => $e->getMessage(),
                ],
            );

            return $response;
        }

        if ($alreadyMigrated) {
            return $response;
        }

        // OS detection + chargement template.
        $os = $this->detectOs($request);
        $fragmentTemplate = $this->loadFragmentTemplate($os);
        if ($fragmentTemplate === '') {
            return $response;
        }

        // Story 16.11 Q1.b — générer un token md5 frais et poser le contexte
        // APCu `apps.<token>` minimal pour que `RequireBootstrapToken` +
        // `LegacyBootstrapTokenValidator` valide le couple token↔uuid lors
        // de l'enroll auto-bootstrap. Best-effort : si APCu indispo, on log
        // et on skip l'injection (sinon le poste tomberait sur 401 invalid).
        $bootstrapToken = $this->mintBootstrapToken($uuid);
        if ($bootstrapToken === null) {
            Log::channel('auth-v1')->warning(
                '[InjectBootstrapFragment] auth.bootstrap.fragment.mint_token_failed',
                [
                    'action_type' => 'auth.bootstrap.fragment.mint_token_failed',
                    'os' => $os,
                    'uuid_prefix' => substr(hash('sha256', $uuid), 0, 8),
                ],
            );

            return $response;
        }

        // Substitution placeholder → token md5 frais.
        $fragment = str_replace(self::TOKEN_PLACEHOLDER, $bootstrapToken, $fragmentTemplate);

        // Injection : prefix fragment + body legacy actuel.
        $existing = (string) $response->getContent();
        $response->setContent($fragment . $existing);

        Log::channel('auth-v1')->info(
            '[InjectBootstrapFragment] auth.bootstrap.fragment.injected',
            [
                'action_type' => 'auth.bootstrap.fragment.injected',
                'os' => $os,
                'uuid_prefix' => substr(hash('sha256', $uuid), 0, 8),
                'token_hash_prefix' => substr(hash('sha256', $bootstrapToken), 0, 8),
                'route_name' => optional($request->route())->getName(),
                'response_bytes_before' => strlen($existing),
                'response_bytes_after' => strlen((string) $response->getContent()),
            ],
        );

        return $response;
    }

    /**
     * Story 16.11 Q1.b — génère un token md5 frais et pose un contexte
     * APCu minimal `apps.<token>` avec la clé `uuid` matching, TTL 1800s.
     *
     * Le contexte minimal contient :
     *  - `uuid` : l'uuid déclaré dans la requête legacy (validé par regex)
     *  - `time` : timestamp pose (pour audit/debug)
     *  - `source` : marqueur `'inject.bootstrap-fragment'` (forensique)
     *
     * Le `EnrollController` 16.10/16.11 ne lit pas ces autres clés — seul
     * `LegacyBootstrapTokenValidator::payloadMatchesUuid()` consomme `uuid`.
     *
     * Retourne `null` si l'écriture APCu échoue (writer absent / corrompu).
     */
    private function mintBootstrapToken(string $uuid): ?string
    {
        // Token md5 frais — random_bytes pour entropie (vs md5(uuid+time) qui
        // serait deterministe et permettrait à un attaquant LAN de pré-calculer).
        $token = md5(random_bytes(32));

        try {
            $this->contextWriter->write(
                $token,
                [
                    'uuid' => $uuid,
                    'time' => time(),
                    'source' => 'inject.bootstrap-fragment',
                ],
                self::BOOTSTRAP_TOKEN_TTL,
            );
        } catch (\Throwable $e) {
            Log::channel('auth-v1')->warning(
                '[InjectBootstrapFragment] auth.bootstrap.fragment.context_write_failed',
                [
                    'action_type' => 'auth.bootstrap.fragment.context_write_failed',
                    'error' => $e->getMessage(),
                ],
            );

            return null;
        }

        return $token;
    }

    /**
     * Décide si l'injection doit être tentée (Content-Type, status, type response).
     */
    private function shouldInject(Request $request, Response $response): bool
    {
        // StreamedResponse → on ne peut pas réécrire le body sans casser le streaming.
        if ($response instanceof StreamedResponse) {
            return false;
        }

        // Erreur applicative → poste va probablement retry, pas la peine d'injecter.
        if ($response->getStatusCode() >= 400) {
            return false;
        }

        // Content-Type : on accepte uniquement text/plain (D6 — JSON et autres
        // formats casseraient le parser côté poste).
        $contentType = (string) ($response->headers->get('Content-Type') ?? '');
        if ($contentType === '') {
            // Pas de Content-Type → on ne prend pas le risque.
            return false;
        }

        // Match case-insensitive prefix sur `text/plain`.
        if (stripos($contentType, 'text/plain') !== 0) {
            return false;
        }

        return true;
    }

    /**
     * Extrait un UUID v4 strict de la requête (query, body, json).
     */
    private function extractUuid(Request $request): ?string
    {
        $candidate = trim((string) $request->input('uuid', ''));

        if ($candidate === '') {
            return null;
        }

        // Regex UUID v4 strict (iso ApplicationsScriptsController:50 mais
        // un poil plus restrictive — on accepte le format avec ou sans tirets
        // pour parité legacy).
        if (preg_match('/^[0-9a-f]{8}-?[0-9a-f]{4}-?[0-9a-f]{4}-?[0-9a-f]{4}-?[0-9a-f]{12}$/i', $candidate) !== 1) {
            return null;
        }

        return strtolower($candidate);
    }

    /**
     * Détermine l'OS du poste (priorité ?os= puis User-Agent fallback).
     */
    private function detectOs(Request $request): string
    {
        $osParam = strtolower((string) $request->input('os', ''));
        if ($osParam === 'windows' || $osParam === 'linux') {
            return $osParam;
        }

        $ua = strtolower((string) $request->userAgent());

        if ($ua === '') {
            return 'windows';
        }

        // Heuristique Windows (priorité) — couvre Win32/cmd.exe/PowerShell/WinHTTP.
        if (str_contains($ua, 'windows')
            || str_contains($ua, 'cmd.exe')
            || str_contains($ua, 'win32')
            || str_contains($ua, 'winhttp')
            || str_contains($ua, 'powershell')) {
            return 'windows';
        }

        // Linux explicite.
        if (str_contains($ua, 'linux')
            || str_contains($ua, 'x11')
            || str_contains($ua, 'wget')) {
            return 'linux';
        }

        // Default fallback Windows (majorité du parc Sambaedu).
        return 'windows';
    }

    /**
     * Charge le template fragment Blade pour l'OS donné — cache statique.
     *
     * Le template contient le placeholder `###_BOOTSTRAP_TOKEN_###` qui sera
     * remplacé par un token md5 frais à chaque injection (cf. `mintBootstrapToken`).
     * Seules les parties statiques (server_base_url, se4fs_name, domain) sont
     * rendues par Blade et cachées process-wide.
     */
    private function loadFragmentTemplate(string $os): string
    {
        if (isset(self::$fragmentTemplateCache[$os])) {
            return self::$fragmentTemplateCache[$os];
        }

        $template = $os === 'linux'
            ? 'auth.v1.bootstrap-fragment-sh'
            : 'auth.v1.bootstrap-fragment-cmd';

        try {
            $fragment = View::make($template, [
                'server_base_url' => $this->resolveServerBaseUrl(),
                'se4fs_name' => (string) config('sambaedu.se4fs_name', ''),
                'domain' => (string) config('sambaedu.domain', ''),
                'bootstrap_token_placeholder' => self::TOKEN_PLACEHOLDER,
            ])->render();
        } catch (\Throwable $e) {
            Log::channel('auth-v1')->warning(
                '[InjectBootstrapFragment] auth.bootstrap.fragment.template_error',
                [
                    'action_type' => 'auth.bootstrap.fragment.template_error',
                    'os' => $os,
                    'error' => $e->getMessage(),
                ],
            );

            return '';
        }

        self::$fragmentTemplateCache[$os] = $fragment;

        return $fragment;
    }

    /**
     * Résout l'URL HTTPS du serveur local (iso EnrollController/BootstrapScriptController).
     */
    private function resolveServerBaseUrl(): string
    {
        $configured = (string) config('auth_v1.server.base_url', '');
        if ($configured !== '') {
            return $configured;
        }

        $se4fs = (string) config('sambaedu.se4fs_name', '');
        $suffix = (string) config('auth_v1.server.host_suffix', '');
        if ($se4fs !== '') {
            $host = $suffix === '' ? $se4fs : ($se4fs . '.' . ltrim($suffix, '.'));

            return 'https://' . $host;
        }

        $appUrl = (string) config('app.url', '');
        if ($appUrl !== '') {
            return preg_replace('/^http:/i', 'https:', $appUrl) ?? $appUrl;
        }

        return 'https://localhost';
    }

    /**
     * Utile pour les tests — vide le cache statique du template fragment.
     */
    public static function clearFragmentCache(): void
    {
        self::$fragmentTemplateCache = [];
    }
}

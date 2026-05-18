<?php

declare(strict_types=1);

namespace App\Auth\V1\Http\Controllers;

use App\Auth\V1\Models\WorkstationMigrationAttempt;
use App\Auth\V1\Pki\CaInitializer;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\View;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Story 16.11 — AC4.1 / AC4.2 / T4.1.
 *
 * Endpoints publics (non-auth) `GET /api/v1/agent/bootstrap.{cmd,sh}` qui
 * servent le script de bootstrap OS-spécifique :
 *
 *  - `cmd()` → script Windows batch + PowerShell (DPAPI + Task Scheduler).
 *  - `sh()`  → script Linux bash (CA install + auth.json + systemd timer).
 *
 * Le script contient :
 *
 *  - Vérification idempotence locale (`auth.json` Linux / registry HKLM Win).
 *  - Téléchargement + installation CA root local.
 *  - Récupération UUID/MAC/hostname locaux.
 *  - POST `/api/v1/agent/enroll` avec `(X-Bootstrap-Token, uuid, mac, hostname, os)`.
 *  - Stockage tokens reçus (DPAPI Win / fichier 0600 root Linux).
 *  - Configuration du job de refresh périodique (25j avant expiration 30j).
 *
 * **Sécurité** :
 *
 *  - Protégé par `EnsureLanIp` (D1/D4) — pas de bootstrap depuis hors LAN.
 *  - Protégé par `EnsureSecureApiHeaders` (HSTS + nosniff + Cache-Control
 *    no-store — le script contient le CA root + URLs serveur dynamiques).
 *  - PAS de JWT requis (le poste n'en a pas encore — c'est le démarrage du flot).
 *  - Pas de secret leak : le CA root est public, les tokens ne sont pas dans
 *    le script (ils viennent en réponse de l'enroll, fait par le script
 *    côté poste).
 *
 * **Traçabilité** : à chaque GET, une row `workstation_migration_attempts`
 * (`status='started'`) est insérée. workstation_uuid encore inconnu (le poste
 * ne l'a pas posté), donc `null` — on accepte des attempts orphelins (la
 * correlation par session HTTP n'est pas portable côté bash/PowerShell).
 */
class BootstrapScriptController extends Controller
{
    /** Content-Type renvoyé (parité iso-legacy `applications.php` text/plain). */
    private const CONTENT_TYPE = 'text/plain; charset=utf-8';

    public function __construct(
        private readonly CaInitializer $caInitializer,
    ) {
    }

    public function cmd(Request $request): Response
    {
        return $this->render($request, 'windows');
    }

    public function sh(Request $request): Response
    {
        return $this->render($request, 'linux');
    }

    /**
     * Rend le script de bootstrap pour l'OS donné, log + insère un attempt.
     */
    private function render(Request $request, string $os): Response
    {
        // 1. Trace l'attempt (status=started) — workstation_uuid encore inconnu.
        $this->recordStartedAttempt($request, $os);

        // 2. Récupère le CA root PEM (gracieux : si PKI non initialisée en
        //    testing/local, on continue avec un placeholder pour permettre
        //    aux tests Feature de fonctionner sans PKI).
        $caCertPemBase64 = '';
        try {
            $caCertPem = $this->caInitializer->getCaCertPem();
            $caCertPemBase64 = base64_encode($caCertPem);
        } catch (RuntimeException $e) {
            Log::channel('auth-v1')->warning('[BootstrapScriptController] CA not initialized', [
                'action_type' => 'auth.bootstrap.script.ca_missing',
                'os' => $os,
                'error' => $e->getMessage(),
            ]);
            if (! app()->environment(['testing', 'local'])) {
                // En prod, un bootstrap sans CA disponible n'a pas de sens — 503.
                return response()->json([
                    'success' => false,
                    'error' => 'service_unavailable',
                    'message' => 'PKI not initialized — run php artisan auth:ca:init',
                    'code' => 'pki.not_initialized',
                ], 503);
            }
        }

        // 3. Résout les URLs.
        $serverBaseUrl = $this->resolveServerBaseUrl();
        $enrollEndpoint = $this->safeRoute('agent.v1.enroll', $serverBaseUrl . '/api/v1/agent/enroll');
        $refreshEndpoint = $this->safeRoute('agent.v1.refresh', $serverBaseUrl . '/api/v1/agent/refresh');
        $pingEndpoint = $this->safeRoute('agent.v1.ping', $serverBaseUrl . '/api/v1/agent/ping');

        // 4. Rend la vue Blade selon OS.
        $template = $os === 'linux' ? 'auth.v1.bootstrap-sh' : 'auth.v1.bootstrap-cmd';
        $body = View::make($template, [
            'server_base_url' => $serverBaseUrl,
            'ca_cert_pem_b64' => $caCertPemBase64,
            'enroll_endpoint' => $enrollEndpoint,
            'refresh_endpoint' => $refreshEndpoint,
            'ping_endpoint' => $pingEndpoint,
        ])->render();

        // 5. Log + réponse text/plain.
        Log::channel('auth-v1')->info('[BootstrapScriptController] auth.bootstrap.script.served', [
            'action_type' => 'auth.bootstrap.script.served',
            'os' => $os,
            'ip' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 200),
        ]);

        return response($body, 200)
            ->header('Content-Type', self::CONTENT_TYPE)
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, private');
    }

    /**
     * Insère une row `workstation_migration_attempts` status=started.
     */
    private function recordStartedAttempt(Request $request, string $os): void
    {
        try {
            WorkstationMigrationAttempt::create([
                'workstation_uuid' => null,
                'started_at' => now(),
                'status' => WorkstationMigrationAttempt::STATUS_STARTED,
                'client_ip' => (string) ($request->server('REMOTE_ADDR', '') ?? '127.0.0.1'),
                'user_agent' => substr((string) $request->userAgent(), 0, 1024),
                'os' => $os,
            ]);
        } catch (\Throwable $e) {
            // Best-effort : si l'insertion attempt échoue (DB indispo), on
            // continue à servir le script — le bootstrap reste fonctionnel.
            Log::channel('auth-v1')->warning(
                '[BootstrapScriptController] auth.bootstrap.attempt.persist_failed',
                [
                    'action_type' => 'auth.bootstrap.attempt.persist_failed',
                    'os' => $os,
                    'error' => $e->getMessage(),
                ],
            );
        }
    }

    /**
     * Résout l'URL HTTPS du serveur local (iso 16.10 EnrollController).
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
     * Tente `route($name)` ; si la route n'existe pas, retourne le fallback.
     */
    private function safeRoute(string $name, string $fallback): string
    {
        try {
            return route($name);
        } catch (\Throwable) {
            return $fallback;
        }
    }
}

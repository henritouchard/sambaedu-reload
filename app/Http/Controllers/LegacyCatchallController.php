<?php

namespace App\Http\Controllers;

use App\Models\LegacyCatchallLog;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class LegacyCatchallController extends Controller
{
    /**
     * Gère toutes les requêtes non matchées par les routes Laravel.
     *
     * Logique :
     * 1. Blocage des dossiers sensibles
     * 2. Si le path matche une route bloquée ET n'est PAS explicitement autorisé
     *    ET block_migrated_routes=true → redirect vers l'équivalent SER
     * 3. Sinon → proxy vers le vhost legacy (port 80) pour isoler le process PHP
     * 4. Logger dans legacy_catchall_logs (uniquement les requêtes vers le legacy)
     */
    public function handle(Request $request, string $path = ''): mixed
    {
        $path = $request->path();

        // 0. Stripper le préfixe UAI (etab_ou) du path s'il est présent
        // Le legacy génère des URLs avec le préfixe UAI (ex: /0991229y/blank.php)
        // mais les fichiers sont à la racine du legacy (ex: /var/www/sambaedu/blank.php)
        $uai = config('sambaedu.etab_ou', '');
        if (! empty($uai) && str_starts_with($path, $uai . '/')) {
            $path = substr($path, strlen($uai) + 1);
        } elseif ($path === $uai) {
            $path = '';
        }

        // 1. Dossiers sensibles interdits
        $forbidden = ['laravel', 'vendor', 'node_modules', '.git', '.env'];
        foreach ($forbidden as $dir) {
            if (str_starts_with($path, $dir)) {
                abort(403, 'Accès interdit');
            }
        }

        // 2. Vérification blocage routes migrées
        if (config('sambaedu.block_migrated_routes', true)) {
            $redirect = $this->findBlockedRouteRedirect($path);
            if ($redirect !== null) {
                // Pas de log pour les redirections SER
                return redirect($redirect);
            }
        }

        // 3. Résolution legacy via proxy HTTP vers le vhost legacy (port 80)
        $legacyBasePath = config('sambaedu.legacy_path');

        if (empty($legacyBasePath) || ! is_dir($legacyBasePath)) {
            abort(500, 'SAMBAEDU_LEGACY_PATH est absent ou invalide. Vérifiez la configuration.');
        }

        $legacyPath = rtrim($legacyBasePath, '/') . '/' . $path;

        // Vérifier si le path correspond à quelque chose dans le legacy
        $isPhp = file_exists($legacyPath) && pathinfo($legacyPath, PATHINFO_EXTENSION) === 'php';
        $isDirWithIndex = is_dir($legacyPath) && (file_exists($legacyPath . '/index.php') || file_exists($legacyPath . '/index.html'));
        $isFile = file_exists($legacyPath) && ! is_dir($legacyPath);

        if ($isPhp || $isDirWithIndex || $isFile) {
            // Logger uniquement les pages PHP/index, pas les assets statiques
            if ($isPhp || $isDirWithIndex) {
                $this->logLegacyAccess($request, $path);
            }
            return $this->proxyToLegacy($request, $path);
        }

        if (config('sambaedu.log_404', true)) {
            $this->logLegacyAccess($request, $path);
        }

        abort(404, 'Page non trouvée');
    }

    /**
     * Retourne l'URL de redirection SER si le path est bloqué, null sinon.
     * Les routes explicitement autorisées prennent la priorité.
     */
    private function findBlockedRouteRedirect(string $path): ?string
    {
        $allowedPatterns = config('sambaedu.allowed_legacy_routes', []);
        foreach ($allowedPatterns as $pattern) {
            if (preg_match('~' . $pattern . '~', $path)) {
                return null; // Autorisé explicitement → pas de blocage
            }
        }

        $blockedRoutes = config('sambaedu.blocked_legacy_routes', []);
        foreach ($blockedRoutes as $pattern => $serUrl) {
            if (preg_match('~' . $pattern . '~', $path)) {
                return $serUrl;
            }
        }

        return null;
    }

    /**
     * Proxy la requête vers le vhost legacy (port 80) pour isolation complète.
     * Le legacy s'exécute dans son propre process PHP-FPM, sans collision avec Laravel.
     */
    private function proxyToLegacy(Request $request, string $path): Response
    {
        $legacyBaseUrl = config('sambaedu.legacy_base_url', 'http://127.0.0.1:80');
        $url = rtrim($legacyBaseUrl, '/') . '/' . $path;

        $queryString = $request->getQueryString();
        if ($queryString) {
            $url .= '?' . $queryString;
        }

        try {
            $proxyRequest = Http::withoutVerifying()
                ->withoutRedirecting()
                ->timeout(30)
                ->withHeaders([
                    'X-Forwarded-For' => $request->ip(),
                    'Cookie' => $request->header('Cookie', ''),
                ]);

            $legacyResponse = match ($request->method()) {
                'POST' => $proxyRequest->asForm()->post($url, $request->all()),
                'PUT' => $proxyRequest->put($url, $request->all()),
                'PATCH' => $proxyRequest->patch($url, $request->all()),
                'DELETE' => $proxyRequest->delete($url),
                default => $proxyRequest->get($url),
            };

            $body = $legacyResponse->body();

            // Réécrire les URLs absolues dans le body HTML pour inclure le base path
            // Tout passe par /0991229y/ (le reverse proxy utilise ce préfixe pour router)
            // donc les chemins absolus du legacy (/auth.php, /elements/...) doivent être préfixés
            $basePath = parse_url(config('app.url', ''), PHP_URL_PATH) ?: '';
            if (! empty($basePath) && $basePath !== '/') {
                $bp = rtrim($basePath, '/');
                $bpQ = preg_quote(ltrim($bp, '/'), '#');
                $contentType = $legacyResponse->header('Content-Type', '');
                if (str_contains($contentType, 'text/html') || empty($contentType)) {
                    $body = preg_replace(
                        '#((?:href|src|action)\s*=\s*["\']|window\.location(?:\.href)?\s*=\s*["\']|URL=)(/(?!' . $bpQ . '/))#i',
                        '$1' . $bp . '$2',
                        $body
                    );
                }
            }

            $response = response($body, $legacyResponse->status());

            // Transmettre les headers pertinents de la réponse legacy
            foreach (['Content-Type', 'Set-Cookie'] as $header) {
                if ($legacyResponse->header($header)) {
                    $response->header($header, $legacyResponse->header($header));
                }
            }

            // Transmettre la redirection directement au client sans la ré-intercepter
            if ($legacyResponse->redirect()) {
                $location = $legacyResponse->header('Location');
                $legacyBaseUrl = config('sambaedu.legacy_base_url', 'http://127.0.0.1:80');

                // Si la Location pointe vers l'URL interne du legacy, extraire le chemin relatif
                if (str_starts_with($location, $legacyBaseUrl)) {
                    $location = substr($location, strlen(rtrim($legacyBaseUrl, '/')));
                    if (empty($location)) {
                        $location = '/';
                    }
                }

                // Réécrire les redirections pour inclure le base path du reverse proxy
                // Le legacy génère des chemins absolus (ex: /user/index.php) qui doivent
                // être préfixés par le base path (ex: /0991229y) pour rester dans le SER
                if (str_starts_with($location, '/')) {
                    $basePath = parse_url(config('app.url', ''), PHP_URL_PATH) ?: '';
                    if (! empty($basePath) && $basePath !== '/' && ! str_starts_with($location, $basePath)) {
                        $location = rtrim($basePath, '/') . $location;
                    }
                }

                return response('', $legacyResponse->status())
                    ->header('Location', $location);
            }

            return $response;
        } catch (\Exception $e) {
            Log::channel('legacylog')->error('Legacy proxy error', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);
            abort(502, 'Erreur de communication avec le legacy.');
        }
    }

    /**
     * Détermine le type MIME d'un fichier statique.
     */
    private function resolveMimeType(string $filePath): string
    {
        $mimeTypes = [
            'css'   => 'text/css',
            'js'    => 'application/javascript',
            'png'   => 'image/png',
            'jpg'   => 'image/jpeg',
            'jpeg'  => 'image/jpeg',
            'gif'   => 'image/gif',
            'svg'   => 'image/svg+xml',
            'ico'   => 'image/x-icon',
            'woff'  => 'font/woff',
            'woff2' => 'font/woff2',
            'ttf'   => 'font/ttf',
            'eot'   => 'application/vnd.ms-fontobject',
        ];

        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        return $mimeTypes[$extension]
            ?? (function_exists('mime_content_type') ? mime_content_type($filePath) : 'application/octet-stream');
    }

    /**
     * Enregistre l'accès legacy en DB et dans le channel legacylog.
     */
    private function logLegacyAccess(Request $request, string $path): void
    {
        $data = [
            'method'       => $request->method(),
            'path'         => $path,
            'ip'           => $request->ip(),
            'query_string' => $request->getQueryString() ?: null,
            'referer'      => $request->header('referer') ?: null,
            'created_at'   => now(),
        ];

        try {
            LegacyCatchallLog::create($data);
        } catch (\Exception $e) {
            Log::channel('legacylog')->error('Impossible d\'enregistrer le log legacy en DB : ' . $e->getMessage());
        }

        Log::channel('legacylog')->info('Legacy access', $data);
    }
}

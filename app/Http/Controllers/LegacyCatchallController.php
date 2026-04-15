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

        // 3. Routes legacy en mode direct → proxy vers le vhost legacy original
        //    (pas de bootstrap Laravel, pas de shims, le vrai code legacy gère tout)
        foreach (config('sambaedu.direct_legacy_routes', []) as $pattern) {
            if (preg_match('#' . $pattern . '#', '/' . $path)) {
                $this->logLegacyAccess($request, $path);
                return $this->proxyToLegacy($request, $path);
            }
        }

        // 4. Vérifier si le path cible un module dans legacy/modules/ (bootstrap direct)
        $localLegacyPath = base_path('legacy/modules');
        if (is_dir($localLegacyPath)) {
            $localModulePath = $localLegacyPath . '/' . $path;
            $resolvedPath = realpath($localModulePath);
            $resolvedBase = realpath($localLegacyPath);

            // Containment check : le path résolu doit rester dans legacy/modules/
            if ($resolvedPath && $resolvedBase && str_starts_with($resolvedPath, $resolvedBase . DIRECTORY_SEPARATOR)) {
                $isLocalPhp = is_file($resolvedPath) && pathinfo($resolvedPath, PATHINFO_EXTENSION) === 'php';
                $isLocalDirWithIndex = is_dir($resolvedPath) && file_exists($resolvedPath . '/index.php');

                if ($isLocalPhp || $isLocalDirWithIndex) {
                    $this->logLegacyAccess($request, $path);
                    return $this->executeViaBootstrap($request, $resolvedPath, $isLocalDirWithIndex);
                }
            }
        }

        // 4. Résolution legacy via proxy HTTP vers le vhost legacy (port 80)
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
                    'X-Forwarded-Host' => $request->getHost(),
                    'X-Forwarded-Port' => (string) $request->getPort(),
                    'X-Forwarded-Proto' => $request->getScheme(),
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
            $contentType = $legacyResponse->header('Content-Type', '');
            $isTextContent = str_contains($contentType, 'text/') || str_contains($contentType, 'application/json') || str_contains($contentType, 'application/xml');

            // Réécriture des URLs uniquement pour les réponses texte (HTML, scripts iPXE, etc.)
            // Les fichiers binaires (.wim, .sdi, wimboot, etc.) passent tels quels
            if ($isTextContent || empty($contentType)) {
                // Réécrire les URLs legacy (port interne) vers l'URL publique (Laravel)
                $legacyPort = parse_url($legacyBaseUrl, PHP_URL_PORT) ?: 80;
                $publicHost = $request->getHost();
                $publicPort = $request->getPort();
                $publicScheme = $request->getScheme();
                $publicOrigin = ($publicPort == 80 || $publicPort == 443)
                    ? "{$publicScheme}://{$publicHost}"
                    : "{$publicScheme}://{$publicHost}:{$publicPort}";

                $body = preg_replace(
                    '#http://[^/\s]+:' . preg_quote((string) $legacyPort, '#') . '#',
                    $publicOrigin,
                    $body
                );

                // Réécrire les URLs absolues dans le body HTML pour inclure le base path
                $basePath = parse_url(config('app.url', ''), PHP_URL_PATH) ?: '';
                if (! empty($basePath) && $basePath !== '/') {
                    $bp = rtrim($basePath, '/');
                    $bpQ = preg_quote(ltrim($bp, '/'), '#');
                    if (str_contains($contentType, 'text/html') || empty($contentType)) {
                        $body = preg_replace(
                            '#((?:href|src|action)\s*=\s*["\']|window\.location(?:\.href)?\s*=\s*["\']|URL=)(/(?!' . $bpQ . '/))#i',
                            '$1' . $bp . '$2',
                            $body
                        );
                    }
                }
            }

            // Détection page web HTML → embed dans le layout SER
            $contentType = $legacyResponse->header('Content-Type', '');
            if (!$legacyResponse->redirect() && $this->isHtmlWebPage($contentType, $body)) {
                $cleanedHtml = $this->cleanLegacyHtml($body);

                return response(
                    view('legacy-embed', [
                        'legacyHtml' => $cleanedHtml,
                        'title' => $path,
                    ])->render(),
                    $legacyResponse->status()
                )->header('Content-Type', 'text/html; charset=UTF-8');
            }

            // Déterminer le Content-Type : legacy > extension > défaut
            $legacyContentType = $legacyResponse->header('Content-Type');
            if (empty($legacyContentType) || $legacyContentType === 'text/html') {
                // Le legacy n'a pas renvoyé de Content-Type fiable → déduire de l'extension
                $legacyContentType = $this->resolveMimeType($path) ?: 'application/octet-stream';
            }

            $response = response($body, $legacyResponse->status())
                ->header('Content-Type', $legacyContentType);

            // Transmettre les autres headers pertinents de la réponse legacy
            if ($legacyResponse->header('Set-Cookie')) {
                $response->header('Set-Cookie', $legacyResponse->header('Set-Cookie'));
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
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            Log::channel('legacylog')->error('Legacy proxy TIMEOUT', [
                'path' => $path,
                'url' => $url,
                'timeout' => 30,
                'error' => $e->getMessage(),
            ]);
            abort(504, 'Timeout lors de la communication avec le legacy (path: ' . $path . ')');
        } catch (\Exception $e) {
            Log::channel('legacylog')->error('Legacy proxy error', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);
            abort(502, 'Erreur de communication avec le legacy.');
        }
    }

    /**
     * Exécute un module legacy via le bootstrap Laravel (legacy/bootstrap.php).
     *
     * Utilisé pour les modules copiés dans legacy/modules/ — exécution
     * directe dans le process Laravel, sans proxy HTTP.
     */
    private function executeViaBootstrap(Request $request, string $modulePath, bool $isDirWithIndex): Response
    {
        $targetFile = $isDirWithIndex ? $modulePath . '/index.php' : $modulePath;

        $originalCwd = getcwd() ?: base_path();

        try {
            // Charger le bootstrap legacy (idempotent)
            require_once base_path('legacy/bootstrap.php');

            // Bridge session Laravel → $_SESSION legacy
            $this->bridgeLegacySession();

            // Changer le CWD vers le dossier du module — les includes relatifs
            // (ex: require "../vendor/autoload.php") en dépendent.
            $moduleDir = dirname($targetFile);
            chdir($moduleDir);

            // Simuler PHP_SELF pour les forms legacy
            $originalPhpSelf = $_SERVER['PHP_SELF'] ?? '';
            $_SERVER['PHP_SELF'] = $request->getPathInfo();

            // Capturer la sortie du module legacy
            $initialObLevel = ob_get_level();
            ob_start();
            require $targetFile;
            $output = ob_get_clean() ?: '';

            // Récupérer les headers envoyés par le module legacy (status, content-type, redirects)
            $statusCode = http_response_code() ?: 200;
            $contentType = 'text/html; charset=UTF-8';

            foreach (headers_list() as $header) {
                if (preg_match('/^([^:]+):\s*(.+)$/i', $header, $m)) {
                    $headerName = strtolower($m[1]);
                    if ($headerName === 'content-type') {
                        $contentType = $m[2];
                    }
                }
            }

            // Restaurer PHP_SELF
            $_SERVER['PHP_SELF'] = $originalPhpSelf;

            // Détection : page web HTML → embed dans le layout SER
            if ($this->isHtmlWebPage($contentType, $output)) {
                $cleanedHtml = $this->cleanLegacyHtml($output);
                $moduleName = basename(dirname($targetFile));

                return response(
                    view('legacy-embed', [
                        'legacyHtml' => $cleanedHtml,
                        'title' => $moduleName,
                    ])->render(),
                    $statusCode
                )->header('Content-Type', 'text/html; charset=UTF-8');
            }

            // Sinon : retourner brut (scripts, API, iPXE, etc.)
            $response = response($output, $statusCode);

            foreach (headers_list() as $header) {
                if (preg_match('/^([^:]+):\s*(.+)$/i', $header, $m)) {
                    $headerName = strtolower($m[1]);
                    if ($headerName !== 'content-type' && $headerName !== 'set-cookie') {
                        $response->header($m[1], $m[2]);
                    }
                }
            }

            return $response->header('Content-Type', $contentType);
        } catch (\Throwable $e) {
            // Nettoyer tous les buffers ouverts par le module
            while (ob_get_level() > ($initialObLevel ?? 0)) {
                ob_end_clean();
            }

            $route = $request->path();

            $errorDetail = "Route: /{$route}\n"
                . "Module: {$modulePath}\n"
                . "Fichier: {$e->getFile()}:{$e->getLine()}\n"
                . "Erreur: {$e->getMessage()}";

            Log::channel('legacylog')->error('Legacy bootstrap execution error', [
                'route' => $route,
                'path' => $modulePath,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            if (app()->bound(\App\Services\ErrorLoggerService::class)) {
                app(\App\Services\ErrorLoggerService::class)->log('legacy', $errorDetail);
            }

            abort(500, 'Erreur module legacy [' . basename(dirname($modulePath)) . ']: ' . $e->getMessage());
        } finally {
            // Restaurer le CWD original
            if (is_dir($originalCwd)) {
                chdir($originalCwd);
            }
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
            'wim'   => 'application/octet-stream',
            'sdi'   => 'application/octet-stream',
            'iso'   => 'application/octet-stream',
            'img'   => 'application/octet-stream',
            'efi'   => 'application/octet-stream',
            'exe'   => 'application/octet-stream',
            'xml'   => 'application/xml',
            'ini'   => 'text/plain',
            'cfg'   => 'text/plain',
            'ipxe'  => 'text/plain',
            'woff'  => 'font/woff',
            'woff2' => 'font/woff2',
            'ttf'   => 'font/ttf',
            'eot'   => 'application/vnd.ms-fontobject',
        ];

        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        if (isset($mimeTypes[$extension])) {
            return $mimeTypes[$extension];
        }

        // Pas d'extension (ex: wimboot) → octet-stream
        if (empty($extension)) {
            return 'application/octet-stream';
        }

        // Fallback : détection via le filesystem (path absolu requis)
        $absolutePath = rtrim(config('sambaedu.legacy_path', ''), '/') . '/' . $filePath;
        if (function_exists('mime_content_type') && file_exists($absolutePath)) {
            return mime_content_type($absolutePath);
        }

        return 'application/octet-stream';
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

    /**
     * Détermine si l'output est une page web HTML destinée à l'affichage navigateur.
     * Retourne false pour les scripts (text/plain, JSON, XML), les redirections, etc.
     */
    private function isHtmlWebPage(string $contentType, string $output): bool
    {
        // Seul le text/html est candidat à l'embed
        if (!str_contains(strtolower($contentType), 'text/html')) {
            return false;
        }

        // Output vide ou très court → pas une page web
        if (strlen(trim($output)) < 20) {
            return false;
        }

        // Présence de marqueurs HTML typiques d'une page web
        $htmlMarkers = ['<form', '<table', '<div', '<input', '<select', '<h1', '<h2', '<h3', '<p ', '<ul', '<ol'];
        foreach ($htmlMarkers as $marker) {
            if (stripos($output, $marker) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Nettoie le HTML legacy : retire le chrome (doctype, html/head/body),
     * réécrit les form actions, injecte le CSRF token.
     */
    private function cleanLegacyHtml(string $html): string
    {
        // Retirer doctype, <html>, <head>...</head>, <body>, </body>, </html>
        $html = preg_replace('/<!DOCTYPE[^>]*>/i', '', $html);
        $html = preg_replace('/<html[^>]*>/i', '', $html);
        $html = preg_replace('/<\/html>/i', '', $html);
        $html = preg_replace('/<head[^>]*>.*?<\/head>/is', '', $html);
        $html = preg_replace('/<body[^>]*>/i', '', $html);
        $html = preg_replace('/<\/body>/i', '', $html);

        // Retirer les <link> et <style> globaux qui auraient échappé au nettoyage du <head>
        $html = preg_replace('/<link[^>]*rel=["\']stylesheet["\'][^>]*>/is', '', $html);
        $html = preg_replace('/<style[^>]*>.*?<\/style>/is', '', $html);

        // Retirer le header legacy entier (contient nav, sidebar menu, bonjour, etc.)
        $html = preg_replace('/<header[^>]*class="page-header"[^>]*>.*?<\/header>/is', '', $html);

        // Retirer la topbar Bootstrap legacy
        $html = preg_replace('/<nav[^>]*class="navbar[^"]*topbar[^"]*"[^>]*>.*?<\/nav>/is', '', $html);

        // Retirer le menu sidebar legacy (div#menu avec position:absolute)
        $html = preg_replace('/<div[^>]*id="menu"[^>]*>.*?<\/div>\s*<\/div>/is', '', $html);

        // Retirer le "Bonjour xxx" legacy
        $html = preg_replace('/<h3[^>]*align[^>]*>Bonjour.*?<\/h3>/is', '', $html);

        // Retirer les scripts legacy (jQuery, sambaedu.js, user.interface.js)
        $html = preg_replace('/<script[^>]*src="[^"]*(?:jquery|sambaedu|user\.interface)[^"]*"[^>]*><\/script>/is', '', $html);

        // Neutraliser le position:absolute sur les divs de contenu legacy
        $html = preg_replace('/style="[^"]*position:\s*absolute[^"]*"/i', 'style="position:relative; width:100%; padding:0;"', $html);

        // Réécrire les actions de formulaire pour le routage via le catchall
        // Actions absolues (commençant par /) : injecter le préfixe UAI
        // Flag /i : le legacy mélange <form> et <FORM> (ex: gpo-maj.php)
        $uai = config('sambaedu.etab_ou', '');
        if (!empty($uai)) {
            $html = preg_replace(
                '/(<form[^>]*\s)action\s*=\s*["\']\/([^"\']*\.php)["\']/i',
                '$1action="/' . e($uai) . '/$2"',
                $html
            );
        }
        // Actions relatives : résoudre vers l'URL courante (le navigateur
        // ne saurait pas les résoudre correctement via le proxy)
        $currentUrl = url()->current();
        $html = preg_replace(
            '/(<form[^>]*\s)action\s*=\s*["\'](?!\/|https?:)([^"\']*\.php)["\']/i',
            '$1action="' . e($currentUrl) . '"',
            $html
        );

        // Injecter le token CSRF dans chaque formulaire POST
        $csrfField = '<input type="hidden" name="_token" value="' . e(csrf_token()) . '">';
        $html = preg_replace(
            '/(<form[^>]*method\s*=\s*["\']post["\'][^>]*>)/i',
            '$1' . $csrfField,
            $html
        );

        return trim($html);
    }

    /**
     * Bridge la session Laravel vers les variables $_SESSION attendues par le legacy.
     */
    private function bridgeLegacySession(): void
    {
        if (!session_id()) {
            @session_start();
        }

        if (function_exists('auth') && auth()->check()) {
            $user = auth()->user();
            $_SESSION['login'] = $user->login ?? '';
            $_SESSION['level'] = 0;
            $_SESSION['etab'] = config('sambaedu.etab_ou', '');
            $_SESSION['etab_ou'] = config('sambaedu.etab_ou', '');
        }

        // Libérer le lock session immédiatement — le module legacy peut encore
        // lire $_SESSION (le tableau reste en mémoire) mais ne bloque plus
        // les requêtes concurrentes du même utilisateur.
        session_write_close();
    }

}

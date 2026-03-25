<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * Service d'encapsulation des modules legacy dans le layout SER.
 *
 * Exécute un module PHP legacy (depuis le legacy_path) en mode embed :
 * - Charge le bootstrap Laravel (config bridge + shim LDAP)
 * - Prépend les stubs UI dans l'include path (remplace le chrome legacy par du vide)
 * - Bridge la session Laravel → $_SESSION legacy
 * - Capture la sortie HTML du module
 *
 * Usage :
 *   $html = app(LegacyEmbedService::class)->render('annu2/add_group.php');
 */
class LegacyEmbedService
{
    /**
     * Exécute un module legacy et retourne son HTML (sans le chrome legacy).
     *
     * @param string $modulePath Chemin relatif depuis legacy_path (ex: 'annu2/add_group.php')
     * @return string HTML brut du module
     *
     * @throws \RuntimeException Si le module n'existe pas ou échoue
     */
    public function render(string $modulePath): string
    {
        $legacyBasePath = config('sambaedu.legacy_path', '/var/www/sambaedu');
        $fullPath = rtrim($legacyBasePath, '/') . '/' . ltrim($modulePath, '/');

        if (!file_exists($fullPath)) {
            throw new \RuntimeException("Module legacy introuvable : {$fullPath}");
        }

        // Sauvegarder l'include path original
        $originalIncludePath = get_include_path();

        try {
            // 1. Charger le bootstrap (idempotent)
            require_once base_path('legacy/bootstrap.php');

            // 2. Prépend les stubs UI AVANT les includes legacy
            //    Ordre : stubs → legacy includes → reste
            $stubsPath = base_path('legacy/stubs');
            $legacyIncludesPath = $legacyBasePath . '/includes';
            set_include_path(
                $stubsPath
                . PATH_SEPARATOR . $legacyIncludesPath
                . PATH_SEPARATOR . $originalIncludePath
            );

            // 3. Bridge session Laravel → $_SESSION legacy
            $this->bridgeSession();

            // 4. Préparer le contexte legacy ($config global, CWD)
            global $config;
            $config = $config ?? [];
            $config = get_config($config);

            // Changer le CWD vers le dossier du module (les includes relatifs du legacy le requièrent)
            $moduleDir = dirname($fullPath);
            $originalCwd = getcwd();
            chdir($moduleDir);

            // Simuler $_SERVER['PHP_SELF'] pour que les forms legacy pointent vers la bonne URL
            $originalPhpSelf = $_SERVER['PHP_SELF'] ?? '';
            $_SERVER['PHP_SELF'] = request()->getPathInfo();

            // 5. Capturer la sortie
            ob_start();
            require $fullPath;
            $output = ob_get_clean() ?: '';

            // 6. Nettoyer le HTML (retirer les reliquats de structure HTML si présents)
            $output = $this->cleanHtml($output);

            return $output;
        } catch (\Throwable $e) {
            // Nettoyer les buffers en cas d'erreur
            while (ob_get_level() > 0) {
                ob_end_clean();
            }

            Log::channel('legacylog')->error('LegacyEmbedService error', [
                'module' => $modulePath,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw new \RuntimeException(
                "Erreur lors de l'exécution du module legacy [{$modulePath}] : {$e->getMessage()}",
                500,
                $e
            );
        } finally {
            // Restaurer l'état original
            set_include_path($originalIncludePath);
            if (isset($originalCwd) && is_dir($originalCwd)) {
                chdir($originalCwd);
            }
            if (isset($originalPhpSelf)) {
                $_SERVER['PHP_SELF'] = $originalPhpSelf;
            }
        }
    }

    /**
     * Bridge la session Laravel vers les variables $_SESSION attendues par le legacy.
     */
    private function bridgeSession(): void
    {
        if (!session_id()) {
            session_start();
        }

        if (function_exists('auth') && auth()->check()) {
            $user = auth()->user();
            $_SESSION['login'] = $user->login ?? '';
            $_SESSION['passwd'] = ''; // Ne pas exposer le mot de passe
            $_SESSION['level'] = 0;   // Niveau admin
            $_SESSION['etab'] = config('sambaedu.etab_ou', '');
            $_SESSION['etab_ou'] = config('sambaedu.etab_ou', '');
        }
    }

    /**
     * Nettoie le HTML legacy des reliquats de structure (doctype, html, head, body).
     * Les stubs devraient empêcher leur génération, mais par sécurité on les retire.
     */
    private function cleanHtml(string $html): string
    {
        // Retirer doctype, <html>, <head>...</head>, <body>, </body>, </html>
        $html = preg_replace('/<!DOCTYPE[^>]*>/i', '', $html);
        $html = preg_replace('/<html[^>]*>/i', '', $html);
        $html = preg_replace('/<\/html>/i', '', $html);
        $html = preg_replace('/<head>.*?<\/head>/is', '', $html);
        $html = preg_replace('/<body[^>]*>/i', '', $html);
        $html = preg_replace('/<\/body>/i', '', $html);

        // Réécrire les actions de formulaire pour rester dans l'embed
        $currentUrl = request()->getRequestUri();
        $html = preg_replace(
            '/(<form[^>]*\s)action\s*=\s*["\'][^"\']*\.php["\']/',
            '$1action="' . e($currentUrl) . '"',
            $html
        );

        return trim($html);
    }
}

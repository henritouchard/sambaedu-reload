<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Exception;

class ClearAllCaches extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sambaedu:clear-cache
                            {--config : Nettoyer uniquement le cache de configuration}
                            {--routes : Nettoyer uniquement le cache des routes}
                            {--views : Nettoyer uniquement le cache des vues}
                            {--app : Nettoyer uniquement le cache applicatif}
                            {--all : Nettoyer tous les caches (par défaut)}
                            {--optimize : Reconstruire les caches après nettoyage}
                            {--quiet : Exécution silencieuse}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Nettoyer intelligemment tous les caches Laravel de SambaEdu';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        if (!$this->option('quiet')) {
            $this->info('🧹 Nettoyage des caches SambaEdu...');
            $this->newLine();
        }

        $cleared = [];
        $errors = [];

        // Déterminer quels caches nettoyer
        $clearConfig = $this->option('config') || $this->option('all') || $this->shouldClearAll();
        $clearRoutes = $this->option('routes') || $this->option('all') || $this->shouldClearAll();
        $clearViews = $this->option('views') || $this->option('all') || $this->shouldClearAll();
        $clearApp = $this->option('app') || $this->option('all') || $this->shouldClearAll();

        // Nettoyer le cache de configuration
        if ($clearConfig) {
            $result = $this->clearConfigCache();
            if ($result['success']) {
                $cleared[] = $result['message'];
            } else {
                $errors[] = $result['message'];
            }
        }

        // Nettoyer le cache des routes
        if ($clearRoutes) {
            $result = $this->clearRouteCache();
            if ($result['success']) {
                $cleared[] = $result['message'];
            } else {
                $errors[] = $result['message'];
            }
        }

        // Nettoyer le cache des vues
        if ($clearViews) {
            $result = $this->clearViewCache();
            if ($result['success']) {
                $cleared[] = $result['message'];
            } else {
                $errors[] = $result['message'];
            }
        }

        // Nettoyer le cache applicatif
        if ($clearApp) {
            $result = $this->clearApplicationCache();
            if ($result['success']) {
                $cleared[] = $result['message'];
            } else {
                $errors[] = $result['message'];
            }
        }

        // Nettoyer les fichiers temporaires
        $tempResult = $this->clearTemporaryFiles();
        if ($tempResult['success']) {
            $cleared[] = $tempResult['message'];
        } else {
            $errors[] = $tempResult['message'];
        }

        // Afficher les résultats
        if (!$this->option('quiet')) {
            $this->displayResults($cleared, $errors);
        }

        // Optimisation optionnelle
        if ($this->option('optimize')) {
            $this->optimizeApplication();
        }

        return empty($errors) ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * Déterminer si tous les caches doivent être nettoyés (par défaut)
     */
    private function shouldClearAll(): bool
    {
        return !$this->option('config') && 
               !$this->option('routes') && 
               !$this->option('views') && 
               !$this->option('app');
    }

    /**
     * Nettoyer le cache de configuration
     */
    private function clearConfigCache(): array
    {
        try {
            Artisan::call('config:clear');
            return [
                'success' => true,
                'message' => '✅ Cache de configuration vidé'
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => '❌ Erreur cache configuration : ' . $e->getMessage()
            ];
        }
    }

    /**
     * Nettoyer le cache des routes
     */
    private function clearRouteCache(): array
    {
        try {
            Artisan::call('route:clear');
            return [
                'success' => true,
                'message' => '✅ Cache des routes vidé'
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => '❌ Erreur cache routes : ' . $e->getMessage()
            ];
        }
    }

    /**
     * Nettoyer le cache des vues
     */
    private function clearViewCache(): array
    {
        try {
            Artisan::call('view:clear');
            return [
                'success' => true,
                'message' => '✅ Cache des vues vidé'
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => '❌ Erreur cache vues : ' . $e->getMessage()
            ];
        }
    }

    /**
     * Nettoyer le cache applicatif
     */
    private function clearApplicationCache(): array
    {
        try {
            Cache::flush();
            Artisan::call('cache:clear');
            return [
                'success' => true,
                'message' => '✅ Cache applicatif vidé'
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => '❌ Erreur cache applicatif : ' . $e->getMessage()
            ];
        }
    }

    /**
     * Nettoyer les fichiers temporaires
     */
    private function clearTemporaryFiles(): array
    {
        try {
            $cleaned = 0;
            
            // Nettoyer les logs anciens (> 7 jours)
            $logPath = storage_path('logs');
            if (File::exists($logPath)) {
                $logs = File::glob($logPath . '/laravel-*.log');
                $weekAgo = time() - (7 * 24 * 60 * 60);
                
                foreach ($logs as $log) {
                    if (filemtime($log) < $weekAgo) {
                        File::delete($log);
                        $cleaned++;
                    }
                }
            }

            // Nettoyer les fichiers de session expirés
            $sessionPath = storage_path('framework/sessions');
            if (File::exists($sessionPath)) {
                $sessions = File::files($sessionPath);
                $hourAgo = time() - (1 * 60 * 60);
                
                foreach ($sessions as $session) {
                    if ($session->getMTime() < $hourAgo) {
                        File::delete($session->getPathname());
                        $cleaned++;
                    }
                }
            }

            return [
                'success' => true,
                'message' => "✅ Fichiers temporaires nettoyés ({$cleaned} fichiers)"
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => '❌ Erreur nettoyage fichiers : ' . $e->getMessage()
            ];
        }
    }

    /**
     * Optimiser l'application après nettoyage
     */
    private function optimizeApplication(): void
    {
        if (!$this->option('quiet')) {
            $this->newLine();
            $this->info('⚡ Optimisation de l\'application...');
        }

        try {
            // Reconstruire les caches optimisés
            Artisan::call('config:cache');
            Artisan::call('route:cache');
            Artisan::call('view:cache');

            if (!$this->option('quiet')) {
                $this->comment('✅ Caches optimisés reconstruits');
            }
        } catch (Exception $e) {
            if (!$this->option('quiet')) {
                $this->warn('⚠️  Erreur lors de l\'optimisation : ' . $e->getMessage());
            }
        }
    }

    /**
     * Afficher les résultats du nettoyage
     */
    private function displayResults(array $cleared, array $errors): void
    {
        if (!empty($cleared)) {
            $this->newLine();
            $this->info('🎉 Nettoyage terminé avec succès :');
            foreach ($cleared as $message) {
                $this->comment('   ' . $message);
            }
        }

        if (!empty($errors)) {
            $this->newLine();
            $this->error('❌ Erreurs rencontrées :');
            foreach ($errors as $error) {
                $this->comment('   ' . $error);
            }
        }

        $this->newLine();
        $this->displayCacheStatus();
        
        $this->newLine();
        $this->comment('💡 Astuce : Utilisez --optimize pour reconstruire les caches optimisés');
        $this->comment('💡 Astuce : Utilisez --config, --routes, --views ou --app pour un nettoyage ciblé');
    }

    /**
     * Afficher le statut des caches
     */
    private function displayCacheStatus(): void
    {
        $this->info('📊 Statut des caches :');
        
        // Vérifier le cache de configuration
        $configCached = File::exists(base_path('bootstrap/cache/config.php'));
        $this->comment('   • Configuration : ' . ($configCached ? '🟢 Mis en cache' : '🔴 Non mis en cache'));
        
        // Vérifier le cache des routes
        $routesCached = File::exists(base_path('bootstrap/cache/routes-v7.php'));
        $this->comment('   • Routes : ' . ($routesCached ? '🟢 Mis en cache' : '🔴 Non mis en cache'));
        
        // Vérifier le cache des vues
        $viewsPath = storage_path('framework/views');
        $viewsCached = File::exists($viewsPath) && count(File::files($viewsPath)) > 0;
        $this->comment('   • Vues : ' . ($viewsCached ? '🟢 Fichiers en cache' : '🔴 Cache vide'));
    }
} 
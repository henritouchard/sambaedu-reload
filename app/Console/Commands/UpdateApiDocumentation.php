<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Exception;

class UpdateApiDocumentation extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sambaedu:update-docs
                            {--force : Force la régénération même si la documentation existe}
                            {--check : Vérifier uniquement si la documentation est à jour}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Mettre à jour la documentation API Swagger de SambaEdu';

    protected $help = <<<'HELP'
    Régénère la documentation d'API au format Swagger à partir des annotations du
    code.

      <info>php artisan sambaedu:update-docs</info>
      <info>php artisan sambaedu:update-docs --check</info>   vérifie sans régénérer
      <info>php artisan sambaedu:update-docs --force</info>   régénère même si déjà présente

    <comment>--check</comment> est la forme à utiliser en intégration continue : elle dit si la
    documentation publiée est en retard sur le code, sans rien écrire.

    Ne concerne QUE la documentation d'API destinée aux intégrateurs — pas la
    documentation utilisateur, ni les fiches techniques du dépôt.
    HELP;

    /**
     * Execute the console command.
     */
    public function handle()
    {
        if (!$this->option('quiet')) {
            $this->info('🔄 Mise à jour de la documentation API SambaEdu...');
            $this->newLine();
        }

        // Mode vérification uniquement
        if ($this->option('check')) {
            return $this->checkDocumentationStatus();
        }

        try {
            // Vérifier la configuration L5-Swagger
            if (!$this->checkL5SwaggerConfiguration()) {
                return Command::FAILURE;
            }

            // Générer la documentation
            if (!$this->option('quiet')) {
                $this->info('📝 Génération de la documentation Swagger...');
            }

            $result = Artisan::call('l5-swagger:generate');
            
            if ($result === 0) {
                if (!$this->option('quiet')) {
                    $this->newLine();
                    $this->info('✅ Documentation générée avec succès !');
                    $this->newLine();
                    $this->displayAccessInfo();
                }
                
                return Command::SUCCESS;
            } else {
                $this->error('❌ Erreur lors de la génération de la documentation');
                return Command::FAILURE;
            }

        } catch (Exception $e) {
            $this->error('❌ Exception lors de la génération : ' . $e->getMessage());
            
            if (!$this->option('quiet')) {
                $this->newLine();
                $this->comment('💡 Vérifiez que les annotations Swagger sont correctes dans vos contrôleurs.');
                $this->comment('💡 Consultez les logs Laravel pour plus de détails.');
            }
            
            return Command::FAILURE;
        }
    }

    /**
     * Vérifier la configuration L5-Swagger
     */
    private function checkL5SwaggerConfiguration(): bool
    {
        if (!config('l5-swagger')) {
            $this->error('❌ Configuration L5-Swagger non trouvée');
            $this->comment('💡 Exécutez : php artisan vendor:publish --provider="L5Swagger\L5SwaggerServiceProvider"');
            return false;
        }

        $docsPath = config('l5-swagger.defaults.paths.docs');
        if (!$docsPath) {
            $this->error('❌ Chemin de documentation non configuré');
            return false;
        }

        if (!$this->option('quiet')) {
            $this->comment('✓ Configuration L5-Swagger OK');
        }

        return true;
    }

    /**
     * Vérifier le statut de la documentation
     */
    private function checkDocumentationStatus(): int
    {
        $this->info('🔍 Vérification du statut de la documentation...');
        
        $docsPath = config('l5-swagger.defaults.paths.docs');
        $fullPath = storage_path('api-docs/api-docs.json');
        
        if (!file_exists($fullPath)) {
            $this->warn('⚠️  Documentation non générée');
            $this->comment('💡 Exécutez : php artisan sambaedu:update-docs');
            return Command::FAILURE;
        }

        $lastModified = filemtime($fullPath);
        $age = time() - $lastModified;
        
        $this->info('✅ Documentation présente');
        $this->comment('📅 Dernière génération : ' . date('Y-m-d H:i:s', $lastModified));
        $this->comment('⏰ Âge : ' . $this->formatAge($age));
        
        // Vérifier si des contrôleurs ont été modifiés plus récemment
        $controllerPath = app_path('Http/Controllers');
        $newerControllers = $this->findNewerFiles($controllerPath, $lastModified);
        
        if (!empty($newerControllers)) {
            $this->warn('⚠️  Contrôleurs modifiés depuis la dernière génération :');
            foreach ($newerControllers as $file) {
                $this->comment('   • ' . $file);
            }
            $this->comment('💡 Recommandation : php artisan sambaedu:update-docs');
        } else {
            $this->info('✅ Documentation à jour');
        }

        return Command::SUCCESS;
    }

    /**
     * Trouver les fichiers plus récents qu'une date donnée
     */
    private function findNewerFiles(string $directory, int $timestamp): array
    {
        $newerFiles = [];
        
        if (!is_dir($directory)) {
            return $newerFiles;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                if ($file->getMTime() > $timestamp) {
                    $newerFiles[] = str_replace(app_path(), 'app', $file->getPathname());
                }
            }
        }

        return $newerFiles;
    }

    /**
     * Formater l'âge d'un fichier
     */
    private function formatAge(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds . ' seconde(s)';
        } elseif ($seconds < 3600) {
            return round($seconds / 60) . ' minute(s)';
        } elseif ($seconds < 86400) {
            return round($seconds / 3600) . ' heure(s)';
        } else {
            return round($seconds / 86400) . ' jour(s)';
        }
    }

    /**
     * Afficher les informations d'accès
     */
    private function displayAccessInfo(): void
    {
        $appUrl = config('app.url');
        $route = config('l5-swagger.documentations.default.routes.api');
        
        $this->info('📍 Accès à la documentation :');
        $this->comment("   • Interface Swagger UI : {$appUrl}/api/documentation");
        $this->comment("   • Fichier JSON OpenAPI : {$appUrl}/api/documentation.json");
        
        $this->newLine();
        $this->info('🎯 Endpoints documentés :');
        $this->comment('   • GET /api/v1/discovery - Découverte SE4FS');
        $this->comment('   • POST /api/v1/handshake - Handshake sécurisé'); 
        $this->comment('   • POST /api/v1/webhook - Réception webhooks');
        $this->comment('   • GET /api/v1/users - Liste utilisateurs SE4FS');
        $this->comment('   • GET /api/v1/stats - Statistiques système');
        $this->comment('   • GET /api/v1/public/health - Vérification de santé');
        $this->comment('   • GET /api/v1/user - Informations utilisateur connecté');
        $this->comment('   • GET /api/v1/admin/users - Liste utilisateurs (admin)');
        $this->comment('   • GET /api/v1/ecowatt/status - Statut électrique Ecowatt');
        
        $this->newLine();
        $this->comment('💡 Astuce : Utilisez --check pour vérifier si la documentation est à jour');
        $this->comment('💡 Astuce : Utilisez --quiet pour une exécution silencieuse');
    }
} 
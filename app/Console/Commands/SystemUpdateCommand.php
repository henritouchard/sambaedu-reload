<?php

namespace App\Console\Commands;

use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

class SystemUpdateCommand extends Command
{
    protected $signature = 'sambaedu:app:update
                            {--skip-migrate : Ne pas exécuter les migrations}
                            {--skip-seed : Ne pas exécuter les seeders idempotents (permissions/rôles Spatie)}
                            {--resync-seeded-roles : Re-synchroniser les permissions des rôles seedés sur leur définition canonique (écrase les customs UI)}
                            {--skip-livewire : Ne pas republier les assets Livewire}
                            {--skip-optimize : Ne pas reconstruire les caches}';

    protected $description = 'Exécute les étapes applicatives de mise à jour (artisan)';

    protected $help = <<<'HELP'
    Enchaîne les gestes applicatifs d'une mise à jour du serveur, dans l'ordre :

      1. vidage des caches de configuration, de routes, de vues et applicatif ;
      2. migrations de la base ;
      3. peuplement des permissions et rôles, puis remise à zéro de leur cache ;
      4. republication des ressources de l'interface ;
      5. reconstruction des caches.

      <info>php artisan sambaedu:app:update</info>
      <info>php artisan sambaedu:app:update --skip-migrate --skip-optimize</info>

    Appelée par le script de mise à jour du serveur ; chaque étape peut être écartée
    si vous rejouez une séquence à la main.

    ⚠️ <comment>--resync-seeded-roles</comment> ré-aligne les permissions des rôles fournis sur leur
    définition d'origine, et ÉCRASE donc les ajustements faits depuis l'interface.
    Ne l'utilisez que pour rattraper des rôles abîmés.
    HELP;

    public function handle(): int
    {
        try {
            $this->info('Mise à jour applicative SambaEdu...');

            $this->runArtisan('config:clear');
            $this->runArtisan('route:clear');
            $this->runArtisan('view:clear');
            $this->runArtisan('cache:clear');

            if (!$this->option('skip-migrate')) {
                $this->runArtisan('migrate --force');
            }

            if (!$this->option('skip-seed')) {
                if ($this->option('resync-seeded-roles')) {
                    $this->line('> PermissionSeeder::run(force: true)');
                    $stats = (new \Database\Seeders\PermissionSeeder())->run(force: true);
                    $this->line(json_encode($stats, JSON_UNESCAPED_SLASHES));
                } else {
                    $this->runArtisan('db:seed --class=PermissionSeeder --force');
                }
                $this->runArtisan('permission:cache-reset');
            }

            if (!$this->option('skip-livewire')) {
                $this->runArtisan('vendor:publish --tag=livewire:assets --force');
            }

            if (!$this->option('skip-optimize')) {
                $this->runArtisan('config:cache');
                $this->runArtisan('route:cache');
                $this->runArtisan('view:cache');
                $this->runArtisan('event:cache');
            }

            $this->info('Mise à jour applicative terminée.');

            return self::SUCCESS;
        } catch (Exception $exception) {
            $this->error('Erreur de mise à jour applicative: '.$exception->getMessage());

            return self::FAILURE;
        }
    }

    private function runArtisan(string $command): void
    {
        $this->line('> php artisan '.$command);
        Artisan::call($command);
        $output = trim(Artisan::output());

        if ($output !== '') {
            $this->line($output);
        }
    }
}

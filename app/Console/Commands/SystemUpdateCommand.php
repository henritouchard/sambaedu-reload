<?php

namespace App\Console\Commands;

use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

class SystemUpdateCommand extends Command
{
    protected $signature = 'sambaedu:app:update
                            {--skip-migrate : Ne pas exécuter les migrations}
                            {--skip-livewire : Ne pas republier les assets Livewire}
                            {--skip-optimize : Ne pas reconstruire les caches}';

    protected $description = 'Exécute les étapes applicatives de mise à jour (artisan)';

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

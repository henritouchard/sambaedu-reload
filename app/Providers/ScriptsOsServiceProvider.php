<?php

declare(strict_types=1);

namespace App\Providers;

use App\ScriptsOs\Console\Commands\ArchiveScriptExecutionLogsCommand;
use Illuminate\Support\ServiceProvider;

final class ScriptsOsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Story 16.12 — la commande `script-logs:archive:rotate` vit dans
        // `app/ScriptsOs/Console/Commands` (et non `app/Console/Commands`),
        // donc l'auto-load `Kernel::commands()` ne la voit pas. On
        // l'enregistre ici, iso pattern WpkgDeploymentServiceProvider.
        if ($this->app->runningInConsole()) {
            $this->commands([
                ArchiveScriptExecutionLogsCommand::class,
            ]);
        }
    }
}

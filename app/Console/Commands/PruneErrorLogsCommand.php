<?php

namespace App\Console\Commands;

use App\Models\ErrorLog;
use Illuminate\Console\Command;

class PruneErrorLogsCommand extends Command
{
    protected $signature = 'error-logs:prune {--days=30 : Nombre de jours de rétention}';

    protected $description = 'Supprime les error_logs plus anciens que le nombre de jours spécifié';

    public function handle(): int
    {
        $days = (int) $this->option('days');
        $cutoff = now()->subDays($days);

        $count = ErrorLog::where('created_at', '<', $cutoff)->delete();

        $this->info("$count error log(s) supprimé(s) (antérieurs au {$cutoff->format('d/m/Y')}).");

        return self::SUCCESS;
    }
}

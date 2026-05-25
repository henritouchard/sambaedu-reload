<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ManagesScriptLoggingFlag;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Story 17.5 / AC1.1 — Active l'enveloppe de logging centralisé des scripts
 * d'applications (wrapper opt-in livré par 17.2).
 *
 * Positionne `SAMBAEDU_SCRIPTS_LOGGING_ENABLED=true` dans le `.env` (D1) de
 * façon non destructive (D2), puis invalide le cache config (D3/D4).
 */
final class WinscriptLogsEnableCommand extends Command
{
    use ManagesScriptLoggingFlag;

    protected $signature = 'winscript-logs:enable';

    protected $description = 'Active le logging centralisé des scripts d\'applications (wrapper opt-in 17.2).';

    public function handle(): int
    {
        $alreadyEnabled = $this->loggingFlagEnabled();

        if (! $this->writeLoggingFlag(true)) {
            $this->error(sprintf(
                'Fichier .env introuvable (%s) — impossible d\'activer le logging des scripts.',
                $this->envPath(),
            ));

            return self::FAILURE;
        }

        $cacheWasPresent = $this->clearConfigCacheBestEffort();

        if ($alreadyEnabled) {
            $this->info('Le logging des scripts était déjà activé — flag réécrit (idempotent).');
        } else {
            $this->info('Logging des scripts d\'applications ACTIVÉ.');
        }

        $this->newLine();
        $this->line('Les scripts assemblés (interpréteurs cmd / bash) seront désormais wrappés :');
        $this->line(sprintf('  → POST des résultats d\'exécution vers %s', $this->resolveIngestUrl()));

        if ($cacheWasPresent) {
            $this->newLine();
            $this->warn('Un cache de configuration était présent et a été vidé (config:clear).');
            $this->warn('En production, relancez `php artisan config:cache` pour figer la config.');
        }

        Log::channel('scriptsos')->info('winscript-logs.enabled', [
            'env_path'         => $this->envPath(),
            'already_enabled'  => $alreadyEnabled,
            'config_cache_was' => $cacheWasPresent,
        ]);

        return self::SUCCESS;
    }
}

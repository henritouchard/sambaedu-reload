<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ManagesScriptLoggingFlag;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Story 17.5 / AC1.2 — Désactive l'enveloppe de logging centralisé des scripts
 * d'applications. Retour au comportement iso-legacy (scripts non wrappés,
 * parité bytes).
 *
 * Positionne `SAMBAEDU_SCRIPTS_LOGGING_ENABLED=false` dans le `.env` (D1) de
 * façon non destructive (D2), puis invalide le cache config (D3/D4).
 */
final class WinscriptLogsDisableCommand extends Command
{
    use ManagesScriptLoggingFlag;

    protected $signature = 'winscript-logs:disable';

    protected $description = 'Désactive le logging centralisé des scripts d\'applications (retour iso-legacy).';

    protected $help = <<<'HELP'
    Désactive la journalisation centralisée des scripts d'applications : les scripts
    retrouvent leur forme d'origine, à l'identique du serveur SE4.

      <info>php artisan winscript-logs:disable</info>

    L'indicateur est écrit dans le fichier d'environnement sans en abîmer le reste,
    puis le cache de configuration est invalidé — la bascule est effective
    immédiatement.

    Pour vérifier l'état courant : <info>winscript-logs:status</info>.
    HELP;

    public function handle(): int
    {
        $alreadyDisabled = ! $this->loggingFlagEnabled();

        if (! $this->writeLoggingFlag(false)) {
            $this->error(sprintf(
                'Fichier .env introuvable (%s) — impossible de désactiver le logging des scripts.',
                $this->envPath(),
            ));

            return self::FAILURE;
        }

        $cacheWasPresent = $this->clearConfigCacheBestEffort();

        if ($alreadyDisabled) {
            $this->info('Le logging des scripts était déjà désactivé — flag réécrit (idempotent).');
        } else {
            $this->info('Logging des scripts d\'applications DÉSACTIVÉ.');
        }

        $this->newLine();
        $this->line('Retour au comportement iso-legacy : les scripts assemblés ne sont plus wrappés (parité bytes).');

        if ($cacheWasPresent) {
            $this->newLine();
            $this->warn('Un cache de configuration était présent et a été vidé (config:clear).');
            $this->warn('En production, relancez `php artisan config:cache` pour figer la config.');
        }

        Log::channel('scriptsos')->info('winscript-logs.disabled', [
            'env_path'          => $this->envPath(),
            'already_disabled'  => $alreadyDisabled,
            'config_cache_was'  => $cacheWasPresent,
        ]);

        return self::SUCCESS;
    }
}

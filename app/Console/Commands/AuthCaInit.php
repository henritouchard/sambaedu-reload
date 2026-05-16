<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Auth\V1\Pki\CaInitializer;
use Illuminate\Console\Command;
use Throwable;

/**
 * Story 16.10 — AC1.1 / AC1.2 / T2.2.
 *
 * Commande Artisan `auth:ca:init` qui initialise (ou régénère) la PKI
 * locale + la paire JWT RS256.
 *
 * Options :
 *
 *  - `--force` : régénère tout (CA + serveur + JWT). Nécessite
 *    confirmation interactive sauf `--no-interaction`.
 *  - `--regenerate-server-only` : régénère uniquement le cert serveur HTTPS
 *    (utile rotation cert sans toucher CA / JWT).
 *  - `--no-interaction` : pour scripts d'automation (refuse les prompts).
 *
 * Sortie standard :
 *
 *  - Liste les 6 fichiers gérés
 *  - Affiche le bloc Apache + nginx à intégrer manuellement (AC1.2 — l'op
 *    choisit selon son serveur web)
 *
 * Code retour : 0 succès, 1 erreur, 2 conflit d'options.
 */
class AuthCaInit extends Command
{
    /** @var string */
    protected $signature = 'auth:ca:init
        {--force : Régénère tout (CA + serveur + JWT). Demande confirmation.}
        {--regenerate-server-only : Régénère uniquement le cert serveur.}
        {--no-interaction : Refuse les prompts (mode script).}';

    /** @var string */
    protected $description = 'Initialise la PKI locale (CA root + cert serveur HTTPS) + paire JWT RS256.';

    public function handle(CaInitializer $initializer): int
    {
        $force = (bool) $this->option('force');
        $serverOnly = (bool) $this->option('regenerate-server-only');
        $noInteraction = (bool) $this->option('no-interaction');

        if ($force && $serverOnly) {
            $this->error('--force and --regenerate-server-only are mutually exclusive.');

            return 2;
        }

        try {
            if ($serverOnly) {
                $report = $initializer->regenerateServerOnly();
            } elseif ($force) {
                if (! $noInteraction && ! $this->confirm(
                    '⚠ This will REGENERATE the entire PKI (CA + server + JWT). '
                    .'Existing files will be backed up but all current JWTs become invalid. Continue?',
                    false,
                )) {
                    $this->warn('Aborted by user.');

                    return 0;
                }
                $report = $initializer->forceRegen();
            } else {
                $report = $initializer->initIfMissing();
            }
        } catch (Throwable $e) {
            $this->error('auth:ca:init failed : '.$e->getMessage());

            return 1;
        }

        $this->renderReport($report);

        return 0;
    }

    /**
     * Affiche un rapport humainement lisible.
     *
     * @param array<string,mixed> $report
     */
    private function renderReport(array $report): void
    {
        $status = (string) ($report['status'] ?? 'unknown');
        $regenerated = (array) ($report['regenerated'] ?? []);
        $files = (array) ($report['files'] ?? []);
        $blocks = (array) ($report['server_url_block'] ?? []);

        $this->line('');
        $this->info('============================================================');
        $this->info('  auth:ca:init → '.$status);
        $this->info('============================================================');

        if ($status === 'already_initialized') {
            $this->line('PKI already initialized. Use --force to regenerate everything or --regenerate-server-only for the HTTPS cert.');
        } else {
            $this->line('Regenerated : '.($regenerated === [] ? '(none)' : implode(', ', $regenerated)));
        }

        $this->line('');
        $this->info('Managed files :');
        foreach ($files as $label => $path) {
            $exists = is_file((string) $path) ? 'OK' : 'MISSING';
            $this->line(sprintf('  %-14s  %-50s  [%s]', (string) $label, (string) $path, $exists));
        }

        $this->line('');
        $this->info('Web server configuration to integrate manually :');
        $this->line('');
        $this->line('--- Apache vhost block ------------------------------------');
        $this->line((string) ($blocks['apache'] ?? '(empty)'));
        $this->line('');
        $this->line('--- nginx server block ------------------------------------');
        $this->line((string) ($blocks['nginx'] ?? '(empty)'));
        $this->line('');
        $this->info('Then reload your web server :');
        $this->line('  • systemctl reload apache2  # if Apache');
        $this->line('  • systemctl reload nginx    # if nginx');
        $this->line('');
        $this->info('Smoke test :');
        $this->line('  curl -kv https://<host>/api/v1/agent/ping  → expect 401 jwt.missing');
        $this->line('');
        $this->info('Documentation : docs/qa/domains/auth.md');
    }
}

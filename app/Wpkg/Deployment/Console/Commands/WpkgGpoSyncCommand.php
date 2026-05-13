<?php

declare(strict_types=1);

namespace App\Wpkg\Deployment\Console\Commands;

use App\Gpo\Dto\WpkgGpoSyncReport;
use App\Gpo\Services\WpkgGpoSynchronizer;
use Illuminate\Console\Command;

/**
 * Commande artisan `wpkg:gpo:sync` — Story 16.6 (AC3.4).
 *
 * Double accès au synchronizer (UI Livewire + commande artisan) pour permettre :
 *  - cron monitoring (`--audit-only` puis exit code mappé sur sévérité).
 *  - déploiement initial Ansible/playbook (`--force`).
 *  - debugging serveur (`--json` pour pipe machine-readable).
 *
 * Exit codes :
 *  - 0 : severity `ok` ou `info`
 *  - 1 : severity `warning`
 *  - 2 : severity `error`
 *  - 3 : exception (template introuvable, lock, etc.)
 */
final class WpkgGpoSyncCommand extends Command
{
    protected $signature = 'wpkg:gpo:sync
                            {--audit-only : Audit lecture seule (cron-friendly, jamais d\'écriture SYSVOL).}
                            {--force : Force la ré-publication même si la GPO est à jour.}
                            {--json : Sortie JSON sérialisée du rapport.}';

    protected $description = 'Audit + (re-)publication de la GPO `se4_wpkg` qui déclenche `wpkg.js` côté postes (Story 16.6).';

    public function handle(WpkgGpoSynchronizer $sync): int
    {
        $auditOnly = (bool) $this->option('audit-only');
        $force = (bool) $this->option('force');
        $asJson = (bool) $this->option('json');

        if ($auditOnly && $force) {
            $this->error('Options incompatibles : `--audit-only` exclut `--force`.');
            return 3;
        }

        try {
            $report = $force
                ? $sync->publish(true)
                : ($auditOnly ? $sync->audit() : $this->autoPublish($sync));
        } catch (\Throwable $e) {
            if ($asJson) {
                $this->line(json_encode([
                    'error' => $e->getMessage(),
                    'exception' => $e::class,
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            } else {
                $this->error('Échec wpkg:gpo:sync : ' . $e->getMessage());
            }
            return 3;
        }

        if ($asJson) {
            $this->line(json_encode($report->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->renderHuman($report);
        }

        return $report->severity->exitCode();
    }

    /**
     * Mode par défaut (ni `--audit-only` ni `--force`) : on tente une `publish(false)`
     * qui no-op si tout est déjà à jour. Comportement idempotent par construction.
     */
    private function autoPublish(WpkgGpoSynchronizer $sync): WpkgGpoSyncReport
    {
        // Décision : par défaut on fait un audit pur (sans side effect). `publish`
        // demande une confirmation explicite (`--force`) iso UI modale D5.
        return $sync->audit();
    }

    private function renderHuman(WpkgGpoSyncReport $r): void
    {
        $this->info('--- wpkg:gpo:sync ---');
        $this->line(sprintf('Severity      : <fg=%s>%s</>', $this->severityColor($r), strtoupper($r->severity->value)));
        $this->line('Operation ID  : ' . ($r->operationId ?? '(none)'));

        $this->table(
            ['Champ', 'Valeur'],
            [
                ['gpoExists', $r->gpoExists ? 'true' : 'false'],
                ['gpoGuid', (string) $r->gpoGuid],
                ['gpoDisplayName', (string) $r->gpoDisplayName],
                ['linkedOus', $r->linkedOus === [] ? '(aucune)' : implode(', ', $r->linkedOus)],
                ['templatePath', $r->templatePath],
                ['templateExists', $r->templateExists ? 'true' : 'false'],
                ['templateLastModified', $r->templateLastModified?->format(\DateTimeInterface::ATOM) ?? '(n/a)'],
                ['expectedHostsXmlUrl', $r->expectedHostsXmlUrl],
                ['expectedProfilesXmlUrl', $r->expectedProfilesXmlUrl],
                ['detectedPlaceholders', $r->detectedPlaceholders === [] ? '(none)' : implode(', ', $r->detectedPlaceholders)],
                ['unknownPlaceholders', $r->unknownPlaceholders === [] ? '(none)' : implode(', ', $r->unknownPlaceholders)],
                ['bearerTableAvailable', $r->bearerTableAvailable ? 'true' : 'false'],
                ['bearerCoverage', sprintf('%d poste(s) couvert(s) / %d', count(array_filter($r->bearerCoverage)), count($r->bearerCoverage))],
            ],
        );

        if ($r->messages !== []) {
            $this->line('');
            $this->line('Messages :');
            foreach ($r->messages as $m) {
                $this->line('  - ' . $m);
            }
        }
    }

    private function severityColor(WpkgGpoSyncReport $r): string
    {
        return match ($r->severity->value) {
            'ok' => 'green',
            'info' => 'cyan',
            'warning' => 'yellow',
            'error' => 'red',
            default => 'default',
        };
    }
}

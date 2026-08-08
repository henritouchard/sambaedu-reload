<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Auth\V1\Models\WorkstationMigrationAttempt;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Story 16.11 — AC7.1 / T7.1.
 *
 * Commande `migration:health-check` — vérifie la santé de la migration
 * auto-bootstrap (`workstation_migration_attempts`) sur une fenêtre
 * glissante.
 *
 * Calcul :
 *  - $total = attempts(recent($days))
 *  - $failed = attempts(recent($days)->failed())
 *  - $ratio = $failed / $total (0 si total = 0)
 *  - Si $ratio > $threshold (default 5%) → log critical `auth.migration.health.alert`
 *    avec context complet : total, failures, ratio, top_errors (5 error_codes).
 *
 * **La commande NE FAIL PAS** (exit 0 même en cas d'alerte) — sa réussite ne
 * dit rien sur le système. Un monitoring externe (mail/webhook Phase 3) peut
 * s'abonner aux logs critical du channel `auth-v1`.
 *
 * Exemples :
 *
 *  - `php artisan migration:health-check`
 *  - `php artisan migration:health-check --days=14`
 *  - `php artisan migration:health-check --threshold=0.10`
 *
 * Schedule : daily via `app/Console/Kernel.php`.
 */
class MigrationHealthCheck extends Command
{
    /** @var string */
    protected $signature = 'migration:health-check
        {--days=7 : Fenetre glissante en jours.}
        {--threshold=0.05 : Seuil de ratio echecs declenchant une alerte critical.}';

    /** @var string */
    protected $description = 'Vérifie la santé de la migration auto-bootstrap. Alerte critical si ratio échecs > seuil.';

    /** @var string */
    protected $help = <<<'HELP'
    Mesure la santé de la migration automatique des postes sur une fenêtre glissante :
    combien de tentatives, combien d'échecs, et dans quelle proportion.

    Si le taux d'échec dépasse le seuil, une alerte de niveau critique est écrite au
    journal, avec le détail des causes les plus fréquentes.

      <info>php artisan migration:health-check</info>
      <info>php artisan migration:health-check --days=30 --threshold=0.10</info>

    <comment>La commande NE tombe JAMAIS en erreur</comment>, même quand elle alerte : son code de
    retour ne dit rien de l'état du système. C'est délibéré — c'est le journal qu'il
    faut surveiller, pas le code de sortie de la commande.
    HELP;

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $threshold = (float) $this->option('threshold');
        if ($threshold < 0.0 || $threshold > 1.0) {
            $this->warn('Threshold should be in [0,1]. Clamping.');
            $threshold = min(1.0, max(0.0, $threshold));
        }

        $total = WorkstationMigrationAttempt::query()->recent($days)->count();
        $failed = WorkstationMigrationAttempt::query()->recent($days)->failed()->count();

        if ($total === 0) {
            $this->info(sprintf('[OK] No attempts in last %d days', $days));

            return self::SUCCESS;
        }

        $ratio = $failed / $total;

        if ($ratio <= $threshold) {
            $this->info(sprintf(
                '[OK] Failure ratio %.2f%% under threshold %.2f%% (failed=%d/total=%d, days=%d)',
                $ratio * 100,
                $threshold * 100,
                $failed,
                $total,
                $days,
            ));

            return self::SUCCESS;
        }

        // Au-dessus du seuil → alerte critical.
        $topErrors = $this->topErrorCodes($days);

        Log::channel('auth-v1')->critical('auth.migration.health.alert', [
            'action_type' => 'auth.migration.health.alert',
            'total' => $total,
            'failed' => $failed,
            'ratio' => $ratio,
            'threshold' => $threshold,
            'days' => $days,
            'top_errors' => $topErrors,
        ]);

        $this->error(sprintf(
            '[CRITICAL] Failure ratio %.2f%% exceeds threshold %.2f%% (failed=%d/total=%d, days=%d)',
            $ratio * 100,
            $threshold * 100,
            $failed,
            $total,
            $days,
        ));

        if ($topErrors !== []) {
            $this->line('Top errors:');
            foreach ($topErrors as $entry) {
                $this->line(sprintf('  - %s (%d)', $entry['code'], $entry['count']));
            }
        }

        // Commande informative — exit 0 même en cas d'alerte (anti-pattern D8).
        return self::SUCCESS;
    }

    /**
     * Renvoie la liste des 5 error_code les plus fréquents sur la fenêtre.
     *
     * @return list<array{code: string, count: int}>
     */
    private function topErrorCodes(int $days): array
    {
        $rows = DB::table('workstation_migration_attempts')
            ->select('error_code', DB::raw('COUNT(*) as cnt'))
            ->where('status', WorkstationMigrationAttempt::STATUS_FAILED)
            ->where('started_at', '>', now()->subDays($days))
            ->whereNotNull('error_code')
            ->groupBy('error_code')
            ->orderByDesc('cnt')
            ->limit(5)
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'code' => (string) $row->error_code,
                'count' => (int) $row->cnt,
            ];
        }

        return $out;
    }
}

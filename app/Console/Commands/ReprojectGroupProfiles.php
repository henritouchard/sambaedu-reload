<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\GroupRightsProfileService;
use Illuminate\Console\Command;

/**
 * Story 49.1 (AC4) — re-projection idempotente des profils de droits portés par
 * les groupes sur l'ensemble du parc.
 *
 * Trois usages :
 *  - **backfill au déploiement** : matérialise les rôles Spatie de tous les
 *    utilisateurs `source='ad'` à partir de leurs appartenances ;
 *  - **filet** des chemins qui n'émettent pas d'events pivot (writes bruts
 *    `DB::table('user_group_user')` des migrations 42.1 / `MergeLegacyUserGroups`,
 *    suppression en masse de groupes par `whereNotIn(...)->delete()`) ;
 *  - **réparation** après incident.
 *
 * L'opération multi-instance est une COMMANDE, jamais une procédure manuelle à
 * rejouer (doctrine projet) : les gardes vivent dans le code.
 *
 * Re-run immédiat = no-op (aucune écriture). Une erreur sur un utilisateur
 * n'arrête pas la boucle : elle est comptée, loggée, et la commande sort en
 * FAILURE pour que l'orchestration de déploiement la voie.
 *
 * **Aucune planification ici** (D10) : le fil de l'eau est événementiel
 * (observer pivot) et le `--mode=full` nocturne appartient à la Story 49.3.
 */
class ReprojectGroupProfiles extends Command
{
    protected $signature = 'users:reproject-group-profiles {--dry-run : Compte les écritures sans rien modifier}';

    protected $description = 'Re-projette les profils de droits portés par les groupes sur tous les utilisateurs AD (idempotent)';

    public function handle(GroupRightsProfileService $service): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->warn('Mode --dry-run : aucune écriture ne sera effectuée.');
        }

        $stats = $service->reprojectAll(
            logger: function (string $level, string $message): void {
                match ($level) {
                    'error' => $this->error($message),
                    'warning' => $this->warn($message),
                    default => $this->line($message),
                };
            },
            dryRun: $dryRun,
        );

        $this->table(
            ['Utilisateurs traités', 'Profils posés', 'Profils retirés', 'Hors périmètre', 'Erreurs'],
            [[
                $stats['users'],
                $stats['assigned'],
                $stats['removed'],
                $stats['skipped'],
                $stats['errors'],
            ]],
        );

        if ($stats['errors'] > 0) {
            $this->error("{$stats['errors']} utilisateur(s) en erreur — voir les logs.");
            return self::FAILURE;
        }

        $this->info($dryRun ? 'Simulation terminée.' : 'Re-projection terminée.');

        return self::SUCCESS;
    }
}

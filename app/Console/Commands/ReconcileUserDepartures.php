<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\UserDepartureReconciliationService;
use App\Services\UserSyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Story 49.3 — passe nocturne de réconciliation des DÉPARTS.
 *
 * Un balayage AD complet, puis : tout compte actif `source='ad'` absent de ce
 * balayage est désactivé et détaché de ses groupes (le volet rôles suit, porté
 * par 49.1). Sans elle, Postgres ne serait un miroir fidèle que des ENTRÉES —
 * et la bascule runtime 49.2 exposerait « le compte parti garde ses droits ».
 *
 * **Commande DÉDIÉE, et non une option de `users:sync-from-ad` (D1).** La
 * sémantique de `--mode=full` reste ainsi INCHANGÉE : un full manuel (QA,
 * `import:sync-from-ad`) n'a JAMAIS d'effet de désactivation. La garde
 * anti-masse devient au passage explicite et testable, et l'opération
 * multi-instance est une commande — jamais une procédure manuelle à rejouer.
 *
 * **Un seul balayage, partagé (D2).** Hors dry-run, la commande appelle
 * `UserSyncService::importFromAd()` : la même passe traite les entrées et les
 * retours, et rend les identifiants présents dans ses stats. En `--dry-run`,
 * elle passe par `fetchPresence()` — même chemin interne, zéro écriture.
 *
 * **Codes de sortie (D10)** :
 *  - `0` : passe exécutée (y compris « rien à faire ») ;
 *  - `1` : erreurs par-utilisateur — la passe a tourné, une partie a échoué ;
 *  - `2` : GARDE DÉCLENCHÉE, no-op total, intervention humaine requise.
 *
 * Le balayage en échec relève du `2` et non du `1` : AC3 le range explicitement
 * parmi les conditions d'abandon de la garde, et c'est le code qui porte le
 * bon message pour l'orchestration — « rien n'a été fait, va voir l'annuaire ».
 */
class ReconcileUserDepartures extends Command
{
    /** Garde déclenchée : no-op total, intervention humaine requise. */
    public const EXIT_GUARD_ABORTED = 2;

    protected $signature = 'users:reconcile-departures
        {--dry-run : Affiche le plan complet sans écrire quoi que ce soit}
        {--force : Bypasse le seuil anti-masse (JAMAIS les gardes de santé du balayage)}
        {--scope=all : Scope établissement (all|tree|memberOf)}';

    protected $description = 'Désactive les utilisateurs absents d\'un balayage AD complet (garde anti-désactivation en masse)';

    public function handle(
        UserSyncService $userSyncService,
        UserDepartureReconciliationService $reconciliation
    ): int {
        $scope = (string) $this->option('scope');
        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');

        if (! in_array($scope, ['all', 'tree', 'memberOf'], true)) {
            $this->error('Option --scope invalide. Valeurs acceptées: all, tree, memberOf');

            return self::FAILURE;
        }

        $logger = function (string $level, string $message): void {
            match ($level) {
                'error', 'critical' => $this->error($message),
                'warning' => $this->warn($message),
                default => $this->line($message),
            };
        };

        if ($dryRun) {
            $this->warn('Mode --dry-run : aucune écriture (ni import, ni désactivation).');
        }

        if ($force) {
            $this->warn('Option --force : le SEUL seuil anti-masse est levé — les gardes de santé du balayage restent actives.');
        }

        $presence = ['present_guids' => [], 'present_logins' => []];
        $health = ['fetch_failed' => false, 'fetch_groups_failed' => 0, 'main_groups_found' => 0];
        $importStats = null;

        try {
            if ($dryRun) {
                $fetch = $userSyncService->fetchPresence($logger, $scope);
            } else {
                // La passe départs court APRÈS le commit de l'import (D9) :
                // `importUsersFromAd` enveloppe tous ses upserts dans UNE
                // transaction ; y imbriquer les désactivations ferait qu'une
                // erreur d'upsert avorterait des départs déjà décidés.
                $fetch = $importStats = $userSyncService->importFromAd($logger, $scope);
            }

            $presence = [
                'present_guids' => $fetch['present_guids'] ?? [],
                'present_logins' => $fetch['present_logins'] ?? [],
            ];
            $health = [
                'fetch_failed' => false,
                'fetch_groups_failed' => (int) ($fetch['fetch_groups_failed'] ?? 0),
                'main_groups_found' => (int) ($fetch['main_groups_found'] ?? 0),
            ];
        } catch (\Throwable $e) {
            // On n'interrompt PAS : on laisse la garde prononcer l'abandon, pour
            // que le compte-rendu et le log critique disent la même chose que
            // dans tous les autres cas d'abandon.
            $health['fetch_failed'] = true;

            // Correction de review — le log AVANT la console, et pas l'inverse.
            // Cette commande tourne de nuit sous cron, sans `appendOutputTo` :
            // la sortie console part dans le vide. Le `Log::critical` de la
            // garde dira « abandonnée (balayage en échec) » sans jamais dire
            // POURQUOI. Or c'est ici, et seulement ici, qu'on tient encore la
            // cause — bind refusé, timeout réseau, contrôle paged-results
            // rejeté… Sans cette trace, l'admin ne peut pas distinguer une
            // panne réelle d'un abandon prudent, ce qui est précisément le
            // reproche que mérite l'exit code partagé.
            Log::error('[ReconcileUserDepartures] balayage AD en échec — réconciliation abandonnée', [
                'exception' => $e->getMessage(),
                'class' => $e::class,
            ]);

            $this->error('Échec du balayage AD : ' . $e->getMessage());
        }

        $stats = $reconciliation->run(
            presence: $presence,
            health: $health,
            dryRun: $dryRun,
            force: $force,
            logger: $logger,
        );

        $this->table(
            ['Indicateur', 'Valeur'],
            [
                ['présents AD', (string) $stats['present_ad']],
                ['groupes principaux trouvés', (string) $stats['main_groups_found']],
                ['groupes en échec', (string) $stats['fetch_groups_failed']],
                ['actifs base (périmètre)', (string) $stats['active_base']],
                ['hors périmètre (actifs)', (string) $stats['skipped']],
                ['absents détectés', (string) $stats['candidates']],
                ['désactivés', (string) $stats['disabled']],
                ['réactivés (import)', (string) ($importStats['reactivated'] ?? 0)],
                ['créés (import)', (string) ($importStats['created'] ?? 0)],
                ['seuil anti-masse', (string) $stats['threshold']],
                ['erreurs', (string) $stats['errors']],
                ['garde', $stats['guard_aborted']
                    ? 'DÉCLENCHÉE — ' . $stats['guard_reason']
                    : 'passée'],
            ]
        );

        if ($stats['guard_aborted']) {
            $this->error(
                'Aucune désactivation effectuée. Vérifiez l\'annuaire, puis relancez ; '
                . 'si la vague de départs est légitime (rentrée scolaire), relancez avec --force.'
            );

            return self::EXIT_GUARD_ABORTED;
        }

        if ($stats['errors'] > 0) {
            $this->error("{$stats['errors']} utilisateur(s) en erreur — voir les logs.");

            return self::FAILURE;
        }

        $this->info($dryRun ? 'Simulation terminée.' : 'Réconciliation des départs terminée.');

        return self::SUCCESS;
    }
}

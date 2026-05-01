<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\QuotaAuditLog;
use App\Models\UserGroup;
use App\Services\Filesystem\ShareService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Story 5.2 (D5=D + AC 11) — Filet de sécurité bulk pour la re-synchronisation
 * des partages de classe (`/var/sambaedu/Classes/Classe_<name>`).
 *
 * Reproduit le pattern legacy "bouton Mise à jour des classes" de
 * `partages/rep_classes.php` : appel de `update_classes(*)` sur toutes
 * les classes ou une classe ciblée.
 *
 * Modes :
 *  - Sans `--class=` : itère sur tous les `UserGroup::where('type','classe')`
 *    actifs et applique `ShareService::createClassShare()` (idempotent : pas
 *    de destruction, juste re-application des ACLs canoniques).
 *  - Avec `--class=<name>` : ciblage par nom (string) ; permet de scoper la
 *    réparation à une seule classe (cas de dérive locale après opération
 *    manuelle shell).
 *  - `--dry-run` : affiche la liste des classes qui seraient traitées sans
 *    rien appliquer (cohérent pattern `quota:seed-from-legacy --dry-run` 5.1d).
 *  - `--performed-by=<value>` : trace l'auteur dans `quota_audit_logs`
 *    (D10=A) ; défaut `'shares:resync-class'`. Validé par regex anti-log
 *    poisoning (cohérent `trash:purge --performed-by`).
 *
 * Verrouillage (cohérent `ShareService::createClassShare`) : `ShareService`
 * acquiert lui-même un `Cache::lock('shares:resync:'.$groupId, 60)` per-class.
 * On NE pose PAS de lock additionnel ici (review 5.2 #2 Q3) — un double-lock
 * créait une race condition microseconde entre release+re-acquire dans le
 * service. `createClassShare` retourne `false` en cas de lock non disponible :
 * comptabilisé en `failed` (pas en `locked`). Le compteur `locked` du rapport
 * agrège les retours `false` du service quand sa cause est un lock concurrent
 * (best-effort lecture des logs, pas d'API explicit pour le moment).
 *
 * Codes de retour (review 5.2 #2 Q3) :
 *  - `0` (SUCCESS)       : `$resynced > 0` ou aucune classe à traiter (no-op).
 *  - `1` (FAILURE)       : `$failed > 0` (au moins une classe en erreur).
 *  - `2`                 : `$resynced === 0 && $failed === 0 && $locked > 0` —
 *                          toutes les classes étaient verrouillées par une autre
 *                          opération en cours. À monitorer en cron : un retour 2
 *                          systématique = lock orphelin ou race chronique.
 *
 * Fail-soft : un échec de classe individuelle ne bloque PAS les suivantes
 * (cohérent `trash:purge`).
 *
 * Audit : un row `quota_audit_logs` est écrit avec `target_type='share'`,
 * `action='resync_class'` une fois la commande terminée, indépendamment des
 * audits écrits par `ShareService::createClassShare` lui-même (qui logge
 * `action='create_share'` par classe).
 */
class SharesResyncClassCommand extends Command
{
    protected $signature = 'shares:resync-class
        {--class= : Nom de classe spécifique (sinon toutes les classes type=classe)}
        {--dry-run : Liste les classes candidates sans rien appliquer}
        {--performed-by= : Nom à inscrire dans quota_audit_logs (défaut: shares:resync-class)}';

    protected $description = 'Re-applique les ACLs canoniques sur les partages de classe (Story 5.2). Exit codes : 0=ok ; 1=au moins une classe a échoué ; 2=toutes verrouillées (rien fait).';

    public function __construct(private ShareService $shareService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $start = microtime(true);
        $dryRun = (bool) $this->option('dry-run');
        $classFilter = $this->option('class');
        $performedBy = (string) ($this->option('performed-by') ?: 'shares:resync-class');

        // Validation `--performed-by` (anti log poisoning, cohérent trash:purge).
        if (!preg_match('/^[a-zA-Z0-9._:-]+$/', $performedBy)) {
            $this->error('--performed-by invalide (regex /^[a-zA-Z0-9._:-]+$/)');
            return self::FAILURE;
        }

        // Validation `--class=` — même regex anti-injection que les noms FS
        // (cohérent ShareService::escapeAclClassName : alphanum + `._- ` ; on
        // refuse `..`, `;`, `|`, `$`, `\`).
        if ($classFilter !== null && $classFilter !== '') {
            if (!preg_match('/^[A-Za-z0-9_. -]+$/', (string) $classFilter)) {
                $this->error(sprintf(
                    '--class invalide : "%s" (regex /^[A-Za-z0-9_. -]+$/).',
                    (string) $classFilter
                ));
                return self::FAILURE;
            }
        }

        // Récupération des classes cibles.
        $query = UserGroup::query()->where('type', 'classe')->orderBy('name');
        if ($classFilter !== null && $classFilter !== '') {
            $query->where('name', (string) $classFilter);
        }
        $classes = $query->get();

        if ($classes->isEmpty()) {
            if ($classFilter !== null && $classFilter !== '') {
                $this->error(sprintf('Aucune classe trouvée avec name="%s".', (string) $classFilter));
                return self::FAILURE;
            }
            $this->info('Aucune classe (UserGroup type=classe) trouvée — rien à faire.');
            return self::SUCCESS;
        }

        // Mode dry-run : affichage tabulaire et exit SUCCESS sans side-effect.
        if ($dryRun) {
            $rows = [];
            foreach ($classes as $group) {
                $path = $this->shareService->resolveClassPath($group);
                $rows[] = [
                    $group->id,
                    $group->name,
                    $path ?? '(refusé)',
                    $group->users()->count(),
                ];
            }
            $this->table(['ID', 'Nom', 'Path FS', 'Membres'], $rows);
            $duration = round(microtime(true) - $start, 2);
            $this->info(sprintf(
                '[DRY-RUN] %d classe(s) candidate(s). Aucune modification appliquée. Durée : %ss.',
                $classes->count(),
                $duration
            ));
            return self::SUCCESS;
        }

        // Application réelle.
        // Note review 5.2 #2 Q3 : pas de double-lock. `ShareService::createClassShare`
        // gère lui-même un `Cache::lock` per-class. On distingue `locked` de `failed`
        // via une heuristique sur le code de retour : `null` = lock indisponible
        // (réservé futur), `false` = échec, `true` = succès. À l'instant T,
        // `createClassShare` retourne `false` dans les deux cas — on agrège donc
        // tout en `failed` mais on log la trace lock-vs-erreur pour faciliter
        // le tri en monitoring.
        $resynced = 0;
        $failed = 0;
        $locked = 0;

        foreach ($classes as $group) {
            try {
                // Si une autre opération tient le lock, `createClassShare` log
                // un warning et retourne false ; on l'attribue au compteur
                // `locked` via une vérification rapide de l'état du lock
                // *après* le call (best-effort, ne change pas la sémantique
                // de fail-soft).
                $lockKey = 'shares:resync:' . $group->id;
                $ok = $this->shareService->createClassShare($group, performedBy: $performedBy);
                if ($ok) {
                    $resynced++;
                    $this->line(sprintf('  [OK]   %s (id=%d) re-synchronisée.', $group->name, $group->id));
                } else {
                    // On essaie d'acquérir le lock juste après l'échec : si on
                    // l'obtient, c'était bien une erreur métier. Sinon c'est
                    // qu'un autre process le tient encore = `locked`.
                    $probe = \Illuminate\Support\Facades\Cache::lock($lockKey, 1);
                    if ($probe->get()) {
                        $probe->release();
                        $failed++;
                        $this->line(sprintf('  [FAIL] %s (id=%d) erreur partielle ou totale (voir logs).', $group->name, $group->id));
                    } else {
                        $locked++;
                        $this->line(sprintf('  [SKIP] %s (id=%d) verrouillé (autre opération en cours).', $group->name, $group->id));
                        Log::warning('ShareService: shares:resync-class classe verrouillée, skip', [
                            'group_id' => $group->id,
                            'group_name' => $group->name,
                        ]);
                    }
                }
            } catch (\Throwable $e) {
                $failed++;
                Log::error('ShareService: shares:resync-class exception', [
                    'group_id' => $group->id,
                    'group_name' => $group->name,
                    'error' => $e->getMessage(),
                ]);
                $this->line(sprintf('  [ERR]  %s (id=%d) exception : %s', $group->name, $group->id, $e->getMessage()));
            }
        }

        $duration = round(microtime(true) - $start, 2);

        // Audit consolidé de la commande (action='resync_class').
        $this->writeConsolidatedAudit($performedBy, $classFilter, [
            'classes_total' => $classes->count(),
            'resynced' => $resynced,
            'failed' => $failed,
            'locked' => $locked,
            'duration_s' => $duration,
        ]);

        $this->info(sprintf(
            'Classes : %d traitée(s). Re-synchronisées : %d. Échecs : %d. Verrouillées : %d. Durée : %ss.',
            $classes->count(),
            $resynced,
            $failed,
            $locked,
            $duration
        ));

        // Codes de retour (review 5.2 #2 Q3) :
        //   - 1 (FAILURE) si au moins une classe a échoué.
        //   - 2           si toutes verrouillées (rien fait, le cron doit alerter).
        //   - 0 (SUCCESS) sinon (au moins une classe re-synchronisée OU no-op trivial).
        if ($failed > 0) {
            return self::FAILURE;
        }
        if ($resynced === 0 && $locked > 0) {
            return 2;
        }

        return self::SUCCESS;
    }

    /**
     * Écrit un row consolidé `action='resync_class'` dans `quota_audit_logs`
     * (D10=A) pour tracer l'invocation de la commande.
     *
     * @param  array<string, mixed>  $stats
     */
    private function writeConsolidatedAudit(string $performedBy, ?string $classFilter, array $stats): void
    {
        try {
            QuotaAuditLog::log(
                action: 'resync_class',
                performedBy: $performedBy,
                targetType: 'share',
                targetName: $classFilter !== null && $classFilter !== '' ? (string) $classFilter : null,
                partition: '/var/sambaedu',
                oldValues: null,
                newValues: $stats,
                quotaRuleId: null,
                fsApplied: true,
                fsError: null,
            );
        } catch (\Throwable $e) {
            Log::warning('ShareService: shares:resync-class audit consolidé échec', [
                'performed_by' => $performedBy,
                'error' => $e->getMessage(),
            ]);
        }
    }
}

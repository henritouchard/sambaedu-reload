<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\QuotaAuditLog;
use App\Models\SystemSetting;
use App\Services\Filesystem\HomeDirService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Story 5.1d — Purge des dossiers `/home/trash/*` plus vieux que `quota.trash.ttl_days`.
 *
 * Lit la configuration `SystemSetting::get('quota.trash')` (TTL + toggle auto)
 * persistée par l'onglet `/admin/settings → Quotas & FS` (5.1c). Si TTL <= 0
 * ou clé absente, no-op safe (D2=A : warning + exit SUCCESS, jamais d'erreur
 * bloquante).
 *
 * Pour chaque sous-dossier `/home/trash/<login>` dont le `mtime` dépasse N jours :
 *  - délègue la suppression à `HomeDirService::deleteHomeDirectoryPermanently($login)`
 *    (réutilisation maximale du service éprouvé 5.1a — 41 tests anti-injection).
 *  - trace via `Log::info` ET ligne `QuotaAuditLog` (D6=A — `target_type='trash'`,
 *    `action='purge'`, `performed_by` = nom de la commande ou login admin si
 *    appelée depuis le bouton "Purger maintenant").
 *
 * Fail-soft (cohérent 5.1b D3) : un échec sur un dossier ne bloque pas les
 * suivants. `Command::FAILURE` n'est retourné QUE si TOUTES les suppressions
 * candidates ont échoué (cas dégradé sudoers cassé / FS read-only).
 *
 * Mode `--dry-run` : énumère et affiche les candidats sans rien supprimer.
 * Mode `--force` : ignore le garde-fou TTL <= 0 (utilisé par le bouton
 * "Purger maintenant" si l'admin veut purger d'urgence) — réservé à un usage
 * conscient.
 *
 * Décision Henri (2026-04-29 — Q2) :
 *   - TTL <= 0 sans `--force` => exit FAILURE + message clair
 *     "TTL non configuré — configure-le dans /admin/settings avant de lancer la purge".
 *   - TTL <= 0 avec `--force` => bypass garde-fou et purge TOUS les dossiers de
 *     plus d'1 jour (`ageDays > 0`). Comportement INTENTIONNEL : permet à un
 *     admin conscient de vider la corbeille sans config TTL préalable.
 *
 * Verrouillage (décision Q3) : un `Cache::lock('trash:action:'.$login, 60)` est
 * pris par dossier dans la boucle de suppression. Si le lock est indisponible,
 * le dossier est skipped (compteur `locked`) — cohérent avec
 * `HomeDirService::archiveHomeDirectory` / `restoreHomeDirectory` qui posent le
 * même lock. Évite la race "admin restore pendant cron purge".
 *
 * Planifié dans `Console\Kernel::schedule()` à 02h00 quotidiennement, avec
 * `->when(closure)` qui lit `SystemSetting::get('quota.trash.purge_auto')`
 * pour une prise d'effet immédiate du toggle UI sans redéploiement.
 *
 * Logs : préfixe historique `QuotaService:` conservé (décision SM 5.1a).
 */
class TrashPurgeCommand extends Command
{
    protected $signature = 'trash:purge
        {--dry-run : Liste les dossiers candidats sans supprimer}
        {--force : Ignore le garde-fou TTL <= 0 (purge même si pas configurée)}
        {--performed-by= : Nom à inscrire dans QuotaAuditLog (défaut: trash:purge)}';

    protected $description = 'Purge les dossiers /home/trash/* plus vieux que quota.trash.ttl_days (Story 5.1d)';

    /**
     * Répertoire racine de la corbeille. Surchargeable en test via
     * `TrashPurgeCommand::$trashDir = sys_get_temp_dir() . '/trash-test-...';`.
     * Permet de tester la commande sans toucher au vrai filesystem `/home/trash`.
     */
    public static string $trashDir = '/home/trash';

    public function __construct(private HomeDirService $homeDirService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $start = microtime(true);
        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');
        $performedBy = (string) ($this->option('performed-by') ?: 'trash:purge');

        // 0. Validation `--performed-by` (review #M5 — anti log poisoning).
        // Format autorisé : alphanumérique + . _ - : (le ':' permet le préfixe
        // 'ui:<login>' utilisé par le bouton "Purger maintenant").
        if (!preg_match('/^[a-zA-Z0-9._:-]+$/', $performedBy)) {
            $this->error('--performed-by invalide (regex /^[a-zA-Z0-9._:-]+$/)');
            return self::FAILURE;
        }

        // 1. Lecture configuration TTL.
        $config = SystemSetting::get('quota.trash', null);
        $ttlDays = is_array($config) ? (int) ($config['ttl_days'] ?? 0) : 0;

        // 2. Garde-fou TTL invalide (D2=A) — décision Henri 2026-04-29 (Q2).
        // TTL <= 0 sans --force => erreur explicite (exit FAILURE).
        // TTL <= 0 avec --force => bypass + purge tous les dossiers > 0j (intentionnel).
        if ($ttlDays <= 0 && !$force) {
            Log::warning('QuotaService: trash:purge TTL non configuré, abort', [
                'ttl_days' => $ttlDays,
                'config_present' => $config !== null,
            ]);
            $this->error('TTL non configuré — configure-le dans /admin/settings avant de lancer la purge.');
            return self::FAILURE;
        }
        if ($ttlDays <= 0 && $force) {
            Log::info('QuotaService: trash:purge mode --force avec TTL=0, purge tous les dossiers > 0j', [
                'ttl_days' => $ttlDays,
            ]);
            $this->warn('Mode --force : TTL=0, purge de tous les dossiers éligibles (>0j).');
        }

        // 3. Vérifier que le répertoire racine existe.
        if (!is_dir(static::$trashDir)) {
            Log::info('QuotaService: trash:purge répertoire racine absent', [
                'trash_dir' => static::$trashDir,
            ]);
            $this->info(sprintf('Répertoire %s inexistant — rien à purger.', static::$trashDir));
            $this->info('Purgé : 0 dossier(s). Conservé : 0 dossier(s). Erreurs : 0.');
            return self::SUCCESS;
        }

        // 4. Énumération + filtrage.
        // Si --force + ttl=0 (Q2) → on passe ttlDays=0 ET force=true à collectCandidates
        // pour qu'il considère tout dossier d'âge > 0j comme expiré.
        $candidates = $this->collectCandidates($ttlDays > 0 ? $ttlDays : 0, $force && $ttlDays <= 0);

        if ($dryRun) {
            return $this->renderDryRun($candidates, $start);
        }

        // 5. Suppression effective + audit.
        // Décision Q3 (2026-04-29) : verrou per-login pour éviter la race
        // restoreHomeDirectory ↔ trash:purge. Si le lock est indisponible,
        // skip + log warning + compteur `locked`.
        $purged = 0;
        $failed = 0;
        $locked = 0;

        foreach ($candidates['expired'] as $entry) {
            $login = $entry['login'];

            $lock = Cache::lock('trash:action:' . $login, 60);
            if (!$lock->get()) {
                $locked++;
                $this->line(sprintf('  [SKIP] %s — opération en cours (verrouillé).', $login));
                Log::warning('QuotaService: trash:purge dossier verrouillé, skip', [
                    'login' => $login,
                    'age_days' => $entry['age_days'],
                ]);
                continue;
            }

            try {
                $ok = $this->homeDirService->deleteHomeDirectoryPermanently($login);

                if ($ok) {
                    $purged++;
                    Log::info('QuotaService: trash:purge supprimé', [
                        'login' => $login,
                        'age_days' => $entry['age_days'],
                        'mtime' => $entry['mtime'],
                    ]);
                    $this->traceAudit($login, $entry, $performedBy);
                } else {
                    $failed++;
                    Log::error('QuotaService: trash:purge échec', [
                        'login' => $login,
                        'age_days' => $entry['age_days'],
                    ]);
                }
            } finally {
                $lock->release();
            }
        }

        $kept = count($candidates['kept']);
        $duration = round(microtime(true) - $start, 2);

        $this->info(sprintf(
            'Purgé : %d dossier(s). Conservé : %d dossier(s). Erreurs : %d. Verrouillés : %d (opération en cours). Durée : %ss.',
            $purged,
            $kept,
            $failed,
            $locked,
            $duration
        ));

        // Cohérent fail-soft 5.1b D3 : FAILURE uniquement si TOUTES les suppressions
        // candidates ont échoué (cas dégradé). Si rien à supprimer ou succès partiel
        // → SUCCESS.
        if (count($candidates['expired']) > 0 && $purged === 0) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * Énumère les sous-dossiers de `static::$trashDir`, calcule leur âge et
     * sépare les expirés des conservés.
     *
     * @param  bool  $forcePurgeAll  Si true (mode --force + TTL=0), considère
     *                               tout dossier d'âge > 0j comme expiré.
     * @return array{expired: list<array{login:string,mtime:int,age_days:int}>, kept: list<array{login:string,mtime:int,age_days:int}>}
     */
    private function collectCandidates(int $ttlDays, bool $forcePurgeAll = false): array
    {
        $expired = [];
        $kept = [];
        $nowTs = time();

        $entries = @scandir(static::$trashDir) ?: [];

        foreach ($entries as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }

            $path = static::$trashDir . DIRECTORY_SEPARATOR . $name;

            // Story 5.1d code review #M10 — defensive : un symlink dans
            // /home/trash/ pointant vers /home/<autre_user> ferait que
            // `sudo rm -rf` suivrait le lien et purgerait le home actif.
            // On les ignore TOUJOURS (cohérent HomeDirService 5.1a).
            if (is_link($path)) {
                Log::warning('QuotaService: trash:purge symlink détecté, skip', [
                    'path' => $path,
                ]);
                continue;
            }

            if (!is_dir($path)) {
                continue;
            }

            // Anti-injection — cohérent HomeDirService::deleteHomeDirectoryPermanently.
            // Tout dossier au nom non conforme est skipped (Log::warning) sans suppression.
            if (!preg_match('/^[a-zA-Z0-9._-]+$/', $name)) {
                Log::warning('QuotaService: trash:purge nom de dossier invalide ignoré', [
                    'name' => $name,
                ]);
                continue;
            }

            $mtime = @filemtime($path);
            if ($mtime === false) {
                Log::warning('QuotaService: trash:purge filemtime échec', [
                    'login' => $name,
                    'path' => $path,
                ]);
                continue;
            }

            $ageDays = (int) floor(($nowTs - $mtime) / 86400);

            $entry = [
                'login' => $name,
                'mtime' => $mtime,
                'age_days' => $ageDays,
            ];

            if ($ttlDays > 0 && $ageDays > $ttlDays) {
                $expired[] = $entry;
            } elseif ($forcePurgeAll && $ageDays > 0) {
                // Mode --force + TTL=0 : on purge tout dossier > 0j.
                $expired[] = $entry;
            } else {
                $kept[] = $entry;
            }
        }

        return ['expired' => $expired, 'kept' => $kept];
    }

    /**
     * Mode --dry-run : affichage tabulaire sans toucher BDD ni FS.
     *
     * @param  array{expired: list<array{login:string,mtime:int,age_days:int}>, kept: list<array{login:string,mtime:int,age_days:int}>}  $candidates
     */
    private function renderDryRun(array $candidates, float $start): int
    {
        $rows = [];
        foreach ($candidates['expired'] as $e) {
            $rows[] = [$e['login'], date('Y-m-d H:i', $e['mtime']), $e['age_days'], 'À PURGER'];
        }
        foreach ($candidates['kept'] as $e) {
            $rows[] = [$e['login'], date('Y-m-d H:i', $e['mtime']), $e['age_days'], 'conservé'];
        }

        if (!empty($rows)) {
            $this->table(['Login', 'mtime', 'Âge (jours)', 'Statut'], $rows);
        }

        $duration = round(microtime(true) - $start, 2);
        $this->info(sprintf(
            '[DRY-RUN] Candidats à purger : %d. Conservés : %d. Aucune modification effectuée. Durée : %ss.',
            count($candidates['expired']),
            count($candidates['kept']),
            $duration
        ));

        return self::SUCCESS;
    }

    /**
     * Trace une suppression réussie dans `quota_audit_logs` (D6=A).
     *
     * @param  array{login:string,mtime:int,age_days:int}  $entry
     */
    private function traceAudit(string $login, array $entry, string $performedBy): void
    {
        try {
            QuotaAuditLog::log(
                action: QuotaAuditLog::ACTION_DELETE,
                performedBy: $performedBy,
                targetType: 'trash',
                targetName: $login,
                partition: '/home/trash',
                oldValues: [
                    'path' => static::$trashDir . '/' . $login,
                    'mtime' => $entry['mtime'],
                    'age_days' => $entry['age_days'],
                ],
                newValues: null,
                quotaRuleId: null,
                fsApplied: true,
                fsError: null,
            );
        } catch (\Throwable $e) {
            // Audit best-effort : on log mais on ne casse pas la commande pour autant.
            Log::warning('QuotaService: trash:purge audit échec', [
                'login' => $login,
                'error' => $e->getMessage(),
            ]);
        }
    }
}

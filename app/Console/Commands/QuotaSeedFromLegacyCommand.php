<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Exceptions\Filesystem\QuotaPartitionUnavailableException;
use App\Models\QuotaAuditLog;
use App\Models\QuotaRule;
use App\Models\UserGroup;
use App\Services\Filesystem\XfsQuotaService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Story 5.1d — Importation idempotente des règles `quotas` du legacy.
 *
 * Lit la table MySQL legacy `quotas` (schéma confirmé via investigation
 * `sambaedu/includes/quotas.inc.php` : `nom string, quotasoft int (KB),
 * quotahard int (KB), partition string`) via la connexion `legacy_mysql`
 * configurée en 5.1d (cf. config/database.php + .env.example).
 *
 * Comportement (D1=A, AC 11-15) :
 *  - Pour chaque row legacy :
 *    - discrimination user/group via `UserGroup::where('name')->exists()` (D12, AC 12) ;
 *    - conversion KB → MB (`round($x / 1024)`) ;
 *    - `firstOrCreate` (sans `--force`) ou `updateOrCreate` (avec `--force`) ;
 *    - audit `QuotaAuditLog` avec `performed_by='quota:seed-from-legacy'`.
 *  - Init du plafond par défaut de l'instance si absent (une ligne par
 *    partition) — skip si déjà présent, sauf `--force`.
 *  - `--dry-run` : preview sans I/O ;
 *  - `--force` : `updateOrCreate` au lieu de `firstOrCreate` (réécrit) ;
 *  - Si la connexion `legacy_mysql` n'est pas configurée OU PDO échoue
 *    (AC 14) : `Log::error` + message stdout explicite + return FAILURE.
 *
 * Logs : préfixe historique `QuotaService:` conservé (décision SM 5.1a).
 */
class QuotaSeedFromLegacyCommand extends Command
{
    protected $signature = 'quota:seed-from-legacy
        {--dry-run : Aperçu sans modification BDD}
        {--force : Écrase les règles existantes (plafond par défaut + règles par utilisateur et par groupe)}';

    protected $description = 'Importe les règles de quotas du serveur SE4 et initialise le plafond par défaut de l\'instance.';

    protected $help = <<<'HELP'
    Importe les règles de quotas du serveur SE4 : les quotas par utilisateur et par
    groupe, ainsi que le plafond par défaut de l'instance — celui qui s'applique à
    tout compte qu'aucune de ces deux règles ne couvre.

      <info>php artisan quota:seed-from-legacy --dry-run</info>   aperçu, sans écrire
      <info>php artisan quota:seed-from-legacy</info>
      <info>php artisan quota:seed-from-legacy --force</info>     écrase les règles existantes

    Sans <comment>--force</comment>, une règle déjà présente en base est CONSERVÉE : l'import ne
    défait pas ce que vous avez réglé depuis. Avec, il l'écrase — y compris le
    plafond par défaut.

    Chaque écriture est tracée au journal d'audit des quotas.

    Import de migration, à jouer au moment de basculer un établissement. Il lit
    directement la base du serveur SE4, qui doit donc être accessible.
    HELP;

    /**
     * LE PLAFOND PAR DÉFAUT DE L'INSTANCE (Mo), une ligne par partition.
     *
     * ---------------------------------------------------------------------------
     * **Il y en avait QUATRE** — un par « profil » (élève 500/600, enseignant
     * 1000/1200, administrateur 2000/2400, itinérant 200/240) — et le profil retenu
     * pour un compte se DEVINAIT par comparaison de sous-chaîne sur des noms de
     * groupes. La story 63.4 les a remplacés par un défaut unique, d'instance ; un
     * budget plus large pour une population donnée se pose en RÈGLE DE GROUPE, qui
     * est explicite et se voit.
     *
     * **La valeur retenue est celle de l'ex-défaut le plus courant** : c'est la seule
     * qui ait jamais couvert la majorité des comptes.
     *
     * ⚠️ **Ce n'est pas la même règle que celle de la migration de bascule**, et
     * c'est voulu : la migration reprend une instance QUI PORTE DÉJÀ des plafonds, et
     * s'interdit d'en rétrécir un — elle retient donc la valeur la plus large. Cette
     * commande, elle, POSE un premier plafond sur une instance qui n'en avait pas :
     * il n'y a rien à rétrécir, et une valeur de départ raisonnable vaut mieux qu'une
     * valeur maximale que personne n'a demandée. Dans les deux cas, l'écran la change
     * en un geste.
     * ---------------------------------------------------------------------------
     *
     * Mêmes valeurs sur les deux partitions (les partages classes/docs sont gros,
     * pas de raison de les diviser).
     *
     * @var array{soft_mb:int, hard_mb:int}
     */
    private const INSTANCE_DEFAULT = ['soft_mb' => 500, 'hard_mb' => 600];

    /**
     * Partitions valides côté legacy. Une row dont la `partition` n'est pas
     * dans cette liste est comptabilisée comme "errors" (rapport stdout).
     *
     * @var array<int, string>
     */
    private const VALID_PARTITIONS = [
        QuotaRule::PARTITION_HOME,
        QuotaRule::PARTITION_SAMBAEDU,
    ];

    public function handle(): int
    {
        $start = microtime(true);
        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');

        // 1. Test connexion legacy_mysql.
        $hasLegacyConnection = $this->canConnectLegacy();
        if (!$hasLegacyConnection['ok']) {
            // AC 14 — message stdout explicite + Log::error + FAILURE.
            Log::error('QuotaService: connexion legacy_mysql non configurée', [
                'error' => $hasLegacyConnection['error'],
            ]);
            $this->error('Connexion legacy non configurée — ajouter LEGACY_DB_HOST/DATABASE/USERNAME/PASSWORD dans .env');
            $this->error('Erreur PDO : ' . $hasLegacyConnection['error']);
            return self::FAILURE;
        }

        // 2. Lecture table legacy.
        $rows = $this->readLegacyQuotas();
        if ($rows === null) {
            // L'erreur a déjà été loggée. Le message stdout suit.
            $this->error('Lecture de la table legacy `quotas` impossible. Voir les logs.');
            return self::FAILURE;
        }

        // 3. Importation rules user/group.
        $stats = $this->importRules($rows, $dryRun, $force);

        // 4. Init du plafond par défaut de l'instance.
        $stats = array_merge($stats, $this->seedDefaults($dryRun, $force));

        $duration = round(microtime(true) - $start, 2);

        // 5. Rapport stdout.
        $this->renderReport($stats, $duration, $dryRun);

        return self::SUCCESS;
    }

    /**
     * @return array{ok:bool, error:string|null}
     */
    private function canConnectLegacy(): array
    {
        $host = (string) config('database.connections.legacy_mysql.host');
        $database = (string) config('database.connections.legacy_mysql.database');
        $username = (string) config('database.connections.legacy_mysql.username');

        // Garde-fou config : si database OU username vide → considéré comme non
        // configuré (en testing on peut réécrire ce config pour pointer sqlite).
        $driver = (string) config('database.connections.legacy_mysql.driver');
        if ($driver === 'mysql' && ($database === '' || $username === '')) {
            return ['ok' => false, 'error' => sprintf(
                'LEGACY_DB_DATABASE ou LEGACY_DB_USERNAME vide (host=%s).',
                $host !== '' ? $host : '?',
            )];
        }

        try {
            DB::connection('legacy_mysql')->getPdo();
            return ['ok' => true, 'error' => null];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Lit la table legacy `quotas`. En cas d'erreur (table absente, schéma
     * différent), on log + retourne null pour signaler l'échec à `handle`.
     *
     * @return list<array<string, mixed>>|null
     */
    private function readLegacyQuotas(): ?array
    {
        try {
            $rows = DB::connection('legacy_mysql')
                ->table('quotas')
                ->select(['nom', 'quotasoft', 'quotahard', 'partition'])
                ->get()
                ->map(fn ($r) => (array) $r)
                ->all();

            return $rows;
        } catch (\Throwable $e) {
            Log::error('QuotaService: lecture table legacy `quotas` échouée', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array{
     *   imported_user:int,
     *   imported_group:int,
     *   skipped:int,
     *   updated:int,
     *   errors:int,
     *   total_legacy_rows:int,
     * }
     */
    private function importRules(array $rows, bool $dryRun, bool $force): array
    {
        $stats = [
            'imported_user' => 0,
            'imported_group' => 0,
            'skipped' => 0,
            'updated' => 0,
            'errors' => 0,
            'total_legacy_rows' => count($rows),
        ];

        // Charset legacy attendu : `utf8mb4` (cf. config/database.php
        // connexion `legacy_mysql`). Convention métier : 0 = illimité, MAIS
        // uniquement après validation explicite (review #M7) — un NULL
        // silencieux ne doit PAS être interprété comme illimité car cela
        // masquerait une corruption de schéma legacy.
        foreach ($rows as $row) {
            $nom = isset($row['nom']) ? trim((string) $row['nom']) : '';
            $partition = isset($row['partition']) ? (string) $row['partition'] : '';

            // Story 5.1d code review #M7 — détection NULL avant cast (int).
            // `(int) null === 0` ce qui ferait passer un NULL pour illimité.
            $softRaw = $row['quotasoft'] ?? null;
            $hardRaw = $row['quotahard'] ?? null;

            if ($softRaw === null || $hardRaw === null) {
                $stats['errors']++;
                Log::warning('QuotaService: row legacy avec quotasoft/quotahard NULL, ignorée', [
                    'nom' => $nom,
                    'partition' => $partition,
                    'soft_kb' => $softRaw,
                    'hard_kb' => $hardRaw,
                ]);
                continue;
            }

            $softKb = (int) $softRaw;
            $hardKb = (int) $hardRaw;

            if ($softKb < 0 || $hardKb < 0) {
                $stats['errors']++;
                Log::warning('QuotaService: row legacy avec quotasoft/quotahard négatif, ignorée', [
                    'nom' => $nom,
                    'partition' => $partition,
                    'soft_kb' => $softKb,
                    'hard_kb' => $hardKb,
                ]);
                continue;
            }

            // Validation (rows malformées → comptabilisées en erreurs, skipped).
            if ($nom === '' || !in_array($partition, self::VALID_PARTITIONS, true)) {
                $stats['errors']++;
                Log::warning('QuotaService: row legacy malformée ignorée', [
                    'nom' => $nom,
                    'partition' => $partition,
                    'soft_kb' => $softKb,
                    'hard_kb' => $hardKb,
                ]);
                continue;
            }

            // Discrimination user/group (D12, AC 12) :
            // - groupe en priorité (si nom apparaît à la fois en user et en group,
            //   c'est extrêmement rare en SE — on privilégie group pour l'effet
            //   maximal, l'admin ajustera manuellement post-seed si besoin).
            $type = UserGroup::query()->where('name', $nom)->exists()
                ? QuotaRule::TYPE_GROUP
                : QuotaRule::TYPE_USER;

            $softMb = (int) round($softKb / 1024);
            $hardMb = (int) round($hardKb / 1024);

            $existing = QuotaRule::query()
                ->where('type', $type)
                ->where('target', $nom)
                ->where('partition', $partition)
                ->first();

            if ($existing && !$force) {
                $stats['skipped']++;
                Log::info('QuotaService: seed skipped (existante)', [
                    'type' => $type,
                    'target' => $nom,
                    'partition' => $partition,
                ]);
                continue;
            }

            if ($dryRun) {
                if ($type === QuotaRule::TYPE_USER) {
                    $stats['imported_user']++;
                } else {
                    $stats['imported_group']++;
                }
                continue;
            }

            try {
                if ($existing) {
                    // --force : update.
                    $oldValues = $existing->toArray();
                    $existing->update([
                        'quota_soft_mb' => $softMb,
                        'quota_hard_mb' => $hardMb,
                        'is_active' => true,
                    ]);
                    $stats['updated']++;
                    QuotaAuditLog::log(
                        action: QuotaAuditLog::ACTION_UPDATE,
                        performedBy: 'quota:seed-from-legacy',
                        targetType: $type,
                        targetName: $nom,
                        partition: $partition,
                        oldValues: $oldValues,
                        newValues: $existing->fresh()->toArray(),
                        quotaRuleId: $existing->id,
                    );
                } else {
                    $rule = QuotaRule::create([
                        'type' => $type,
                        'target' => $nom,
                        'partition' => $partition,
                        'quota_soft_mb' => $softMb,
                        'quota_hard_mb' => $hardMb,
                        'is_active' => true,
                    ]);
                    if ($type === QuotaRule::TYPE_USER) {
                        $stats['imported_user']++;
                    } else {
                        $stats['imported_group']++;
                    }
                    QuotaAuditLog::log(
                        action: QuotaAuditLog::ACTION_CREATE,
                        performedBy: 'quota:seed-from-legacy',
                        targetType: $type,
                        targetName: $nom,
                        partition: $partition,
                        oldValues: null,
                        newValues: $rule->toArray(),
                        quotaRuleId: $rule->id,
                    );
                }
            } catch (\Throwable $e) {
                $stats['errors']++;
                Log::error('QuotaService: seed insert échoué', [
                    'nom' => $nom,
                    'partition' => $partition,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $stats;
    }

    /**
     * Init du plafond par défaut de l'instance : UNE règle par partition.
     *
     * Elles étaient huit (4 « profils » × 2 partitions) jusqu'à la story 63.4 —
     * voir {@see self::INSTANCE_DEFAULT}.
     *
     * ---------------------------------------------------------------------------
     * **L'ÉCRITURE PASSE PAR LE SERVICE** (correction de revue 63.4). Elle se faisait
     * ici en direct sur le modèle : le défaut d'instance avait donc DEUX chemins
     * d'écriture, dont un qui échappait à la garde de disponibilité de la partition —
     * et l'audit y était réécrit à la main, à côté de celui du service. Deux chemins
     * pour une même décision, c'est la classe de défaut que cette story ferme.
     *
     * ⚠️ `applyImmediately: false`, et ce n'est pas un détail : cette commande est un
     * import de bascule. Appliquer d'un coup un plafond à tout un établissement au
     * moment d'une migration mettrait des comptes en dépassement sans que personne
     * n'ait cliqué. Le geste qui applique est celui de l'écran, et il s'annonce.
     * ---------------------------------------------------------------------------
     *
     * @return array{defaults_created:int, defaults_skipped:int, defaults_updated:int, defaults_refused:int}
     */
    private function seedDefaults(bool $dryRun, bool $force): array
    {
        $stats = [
            'defaults_created' => 0,
            'defaults_skipped' => 0,
            'defaults_updated' => 0,
            'defaults_refused' => 0,
        ];

        $partitions = [QuotaRule::PARTITION_HOME, QuotaRule::PARTITION_SAMBAEDU];
        $quotas = app(XfsQuotaService::class);

        foreach ($partitions as $partition) {
            $existing = QuotaRule::query()
                ->where('type', QuotaRule::TYPE_DEFAULT)
                ->whereNull('target')
                ->where('partition', $partition)
                ->first();

            if ($existing && !$force) {
                $stats['defaults_skipped']++;
                continue;
            }

            if ($dryRun) {
                if ($existing) {
                    $stats['defaults_updated']++;
                } else {
                    $stats['defaults_created']++;
                }
                continue;
            }

            try {
                $quotas->setQuotaRule(
                    QuotaRule::TYPE_DEFAULT,
                    null,
                    $partition,
                    self::INSTANCE_DEFAULT['soft_mb'],
                    self::INSTANCE_DEFAULT['hard_mb'],
                    'quota:seed-from-legacy',
                    applyImmediately: false,
                );

                if ($existing) {
                    $stats['defaults_updated']++;
                } else {
                    $stats['defaults_created']++;
                }
            } catch (QuotaPartitionUnavailableException $e) {
                // La partition ne porte pas de plafond exploitable : le service refuse
                // en nommant, et il a raison. On le DIT plutôt que de l'avaler — un
                // import silencieusement incomplet est pire qu'un import qui refuse.
                $stats['defaults_refused']++;
                $this->warn(sprintf('Plafond par défaut non posé sur %s — %s', $partition, $e->getMessage()));
                Log::warning('QuotaService: plafond par défaut refusé au seed', [
                    'partition' => $partition,
                    'reason' => $e->getMessage(),
                ]);
            } catch (\Throwable $e) {
                Log::error('QuotaService: seed du plafond par défaut échoué', [
                    'partition' => $partition,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $stats;
    }

    /**
     * @param array<string, int> $stats
     */
    private function renderReport(array $stats, float $duration, bool $dryRun): void
    {
        $prefix = $dryRun ? '[DRY-RUN] ' : '';

        $this->newLine();
        $this->info($prefix . 'Seed quotas legacy → SambaEdu Reload');
        $this->info('─────────────────────────────────────');
        $this->info(sprintf('Source : legacy_mysql.quotas (%d rows)', $stats['total_legacy_rows']));
        $this->info(sprintf(
            'Importées : %d user / %d group',
            $stats['imported_user'],
            $stats['imported_group'],
        ));
        $this->info(sprintf('Mises à jour (--force) : %d', $stats['updated']));
        $this->info(sprintf('Skipped (déjà présentes) : %d', $stats['skipped']));
        $this->info(sprintf('Erreurs (rows malformées) : %d', $stats['errors']));
        $this->info(sprintf(
            'Plafond par défaut de l\'instance : %d créé(s) / %d mis à jour / %d conservé(s) / %d refusé(s)',
            $stats['defaults_created'],
            $stats['defaults_updated'],
            $stats['defaults_skipped'],
            $stats['defaults_refused'] ?? 0,
        ));
        $this->info('─────────────────────────────────────');
        $this->info(sprintf('Durée : %ss', $duration));

        if ($dryRun) {
            $this->warn('Mode dry-run actif — aucune modification BDD effectuée.');
        }
    }
}

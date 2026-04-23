<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\QuotaRule;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

/**
 * Story 5.1b — Snapshot quotidien des quotas XFS.
 *
 * Parcourt les partitions XFS supportées (`/home` et `/var/sambaedu` par défaut)
 * et exécute une seule commande `sudo xfs_quota -x -c 'report -a -N' {partition}`
 * par partition. Le résultat est parsé et stocké en JSON dans la colonne
 * `users.quota_snapshot` (ajoutée par la migration
 * `2026_04_23_120000_add_quota_snapshot_to_users_table`).
 *
 * Cette approche remplace les lectures live (1 shellout par user / par render)
 * utilisées avant 5.1b. Le listing `/users` lit désormais directement la colonne
 * JSON — zéro shellout par ligne rendue.
 *
 * Fail-soft (décisions produit D2, D3) :
 * - Si une partition est non-XFS / xfs_quota échoue → log `Log::error` et
 *   passage à la partition suivante. Le snapshot existant est conservé.
 * - Si un user est en BDD mais absent du rapport XFS → log `Log::info` et
 *   son snapshot précédent est conservé.
 * - Exit code `FAILURE` uniquement si TOUTES les partitions échouent.
 *
 * Les logs conservent le préfixe `QuotaService:` pour ne pas casser les greps
 * opérateurs historiques (décision SM 5.1a).
 *
 * Planifiée dans `Console\Kernel::schedule()` à 03h00 quotidiennement.
 */
class QuotaSnapshotCommand extends Command
{
    protected $signature = 'quota:snapshot
        {--partition= : Limite le snapshot à une seule partition (ex: /home)}';

    protected $description = 'Snapshot quotidien des quotas XFS — alimente users.quota_snapshot';

    /**
     * Partitions gérées par défaut (ordre d'exécution déterministe).
     *
     * @var list<string>
     */
    private const PARTITIONS = [
        QuotaRule::PARTITION_HOME,
        QuotaRule::PARTITION_SAMBAEDU,
    ];

    public function handle(): int
    {
        $start = microtime(true);

        $partitions = $this->resolvePartitions();

        $perPartitionParsed = [];
        $partitionErrors = 0;

        foreach ($partitions as $partition) {
            $parsed = $this->parseReport($partition);

            if ($parsed === null) {
                // Parsing échoué (partition non-XFS, xfs_quota absent, etc.)
                // — on ne met pas à jour la clé pour cette partition.
                $partitionErrors++;
                continue;
            }

            $perPartitionParsed[$partition] = $parsed;
        }

        // Decision D3 : exit code FAILURE uniquement si toutes les partitions
        // ont échoué ; sinon on considère la run partielle acceptable.
        if ($partitionErrors === count($partitions)) {
            $this->error('QuotaSnapshot: toutes les partitions ont échoué, aucun snapshot mis à jour.');
            return self::FAILURE;
        }

        $stats = $this->updateSnapshots($perPartitionParsed);

        $this->logDbUsersAbsentFromReport($perPartitionParsed, $stats);

        $duration = round(microtime(true) - $start, 2);

        $this->info(sprintf(
            'QuotaSnapshot terminé — partitions traitées : %d/%d | users mis à jour : %d | logins XFS sans correspondance BDD : %d | users BDD absents du rapport XFS : %d | durée : %ss',
            count($perPartitionParsed),
            count($partitions),
            $stats['updated'],
            $stats['missing'],
            $stats['db_absent_from_xfs'],
            $duration
        ));

        return self::SUCCESS;
    }

    /**
     * Deuxième passe (décision D2) : les users BDD qui ont déjà un snapshot
     * mais qui n'apparaissent dans AUCUN des rapports XFS cette run. On logge
     * pour audit (home archivé, compte déactivé…). Le snapshot n'est PAS
     * effacé — il reflète simplement le dernier état connu.
     *
     * @param  array<string, array<string, array>>  $perPartitionParsed
     * @param  array{updated:int, missing:int, db_absent_from_xfs:int}  $stats  mut.
     */
    private function logDbUsersAbsentFromReport(array $perPartitionParsed, array &$stats): void
    {
        $allXfsLogins = [];
        foreach ($perPartitionParsed as $byLogin) {
            foreach (array_keys($byLogin) as $login) {
                $allXfsLogins[$login] = true;
            }
        }

        $partitionsChecked = array_keys($perPartitionParsed);

        $dbUsersAbsent = User::query()
            ->whereNotNull('quota_snapshot')
            ->when(!empty($allXfsLogins), function ($q) use ($allXfsLogins) {
                $q->whereNotIn('login', array_keys($allXfsLogins));
            })
            ->get(['id', 'login', 'quota_snapshot']);

        foreach ($dbUsersAbsent as $user) {
            Log::info('QuotaService: user absent du rapport XFS', [
                'login' => $user->login,
                'partitions_checked' => $partitionsChecked,
            ]);
        }

        $stats['db_absent_from_xfs'] = $dbUsersAbsent->count();
    }

    /**
     * @return list<string>
     */
    private function resolvePartitions(): array
    {
        $only = $this->option('partition');

        if (!empty($only)) {
            return [(string) $only];
        }

        return self::PARTITIONS;
    }

    /**
     * Parse le rapport `xfs_quota report -a -N` pour une partition donnée.
     *
     * Format legacy (cf. sambaedu/includes/quotas.inc.php:110-131) :
     *   alice    12345    500000    600000    00 [--------]
     *   bob      700000*  500000    600000    01 [6 days]
     *
     * - Colonne 1 : login (peut contenir des caractères mais pas d'espace)
     * - Colonne 2 : used KB (suffixe `*` si over-soft)
     * - Colonne 3 : soft KB (0 = illimité)
     * - Colonne 4 : hard KB (0 = illimité)
     * - Colonne 5 : # fichiers (ignoré pour les blocs)
     * - Grâce entre crochets : `[--------]` (aucune) ou `[N days]`.
     *
     * @return array<string, array{used_kb:int,soft_kb:int,hard_kb:int,is_over_soft:bool,grace_days:?int}>|null
     */
    public function parseReport(string $partition): ?array
    {
        $safePartition = escapeshellarg($partition);

        // `Process::fromShellCommandline` accepte le pipe 2>&1 et se prête
        // bien à `Process::fake()` en test.
        try {
            $result = Process::run("sudo xfs_quota -x -c 'report -a -N' {$safePartition} 2>&1");
        } catch (\Throwable $e) {
            Log::error('QuotaService: échec report xfs_quota (exception)', [
                'partition' => $partition,
                'error' => $e->getMessage(),
            ]);
            return null;
        }

        if (!$result->successful()) {
            Log::error('QuotaService: échec report xfs_quota', [
                'partition' => $partition,
                'output' => $result->output(),
                'code' => $result->exitCode(),
            ]);
            return null;
        }

        $out = [];
        foreach (preg_split("/\r?\n/", (string) $result->output()) as $line) {
            $parsed = $this->parseLine($line);
            if ($parsed === null) {
                continue;
            }
            [$login, $data] = $parsed;
            $out[$login] = $data;
        }

        return $out;
    }

    /**
     * Parse une ligne unique du rapport. Retourne [login, data] ou null si la
     * ligne est malformée / un header / vide.
     *
     * @return array{0:string,1:array{used_kb:int,soft_kb:int,hard_kb:int,is_over_soft:bool,grace_days:?int}}|null
     */
    private function parseLine(string $line): ?array
    {
        $trimmed = trim($line);
        if ($trimmed === '') {
            return null;
        }

        // Le legacy utilise `/^(.*)\[(.*)\]$/` pour séparer la partie data
        // (colonnes) du bloc grace entre crochets.
        if (!preg_match('/^(.*)\[(.*)\]$/', $trimmed, $m)) {
            return null;
        }

        $dataPart = trim($m[1]);
        $gracePart = trim($m[2]);

        $columns = preg_split('/\s+/', $dataPart);
        if ($columns === false || count($columns) < 4) {
            return null;
        }

        $login = $columns[0];
        $usedRaw = $columns[1];

        // Header ou ligne comportant un token non numérique pour used_kb.
        if (!preg_match('/^\d+\*?$/', $usedRaw)) {
            return null;
        }

        $isOverSoft = str_ends_with($usedRaw, '*');
        $usedKb = (int) rtrim($usedRaw, '*');
        $softKb = (int) $columns[2];
        $hardKb = (int) $columns[3];

        $graceDays = null;
        if ($gracePart !== '' && preg_match('/^(\d+)\s*days?/', $gracePart, $gm)) {
            $graceDays = (int) $gm[1];
        }

        return [
            $login,
            [
                'used_kb' => $usedKb,
                'soft_kb' => $softKb,
                'hard_kb' => $hardKb,
                'is_over_soft' => $isOverSoft,
                'grace_days' => $graceDays,
            ],
        ];
    }

    /**
     * Met à jour `users.quota_snapshot` pour chaque user mentionné dans au
     * moins un rapport partition. Les users BDD absents sont loggés et leur
     * ancien snapshot est conservé (décision D2).
     *
     * Post code review 5.1b : un user absent d'un rapport partition-spécifique
     * (ex: `/home` contient alice mais `/var/sambaedu` renvoie vide) voit son
     * snapshot pour cette partition PRÉSERVÉ — pas effacé. Un rapport vide
     * (succès pipeline, 0 ligne) ne doit jamais supprimer silencieusement
     * les snapshots précédents.
     *
     * @param  array<string, array<string, array>>  $perPartitionParsed  [partition => [login => data]]
     * @return array{updated:int, missing:int, db_absent_from_xfs:int}
     */
    public function updateSnapshots(array $perPartitionParsed): array
    {
        // Union des logins vus dans tous les rapports.
        $allLogins = [];
        foreach ($perPartitionParsed as $byLogin) {
            foreach (array_keys($byLogin) as $login) {
                $allLogins[$login] = true;
            }
        }
        $allLogins = array_keys($allLogins);

        if ($allLogins === []) {
            return ['updated' => 0, 'missing' => 0, 'db_absent_from_xfs' => 0];
        }

        $users = User::query()
            ->whereIn('login', $allLogins)
            ->get(['id', 'login', 'quota_snapshot']);

        $capturedAt = Carbon::now()->toIso8601String();

        $updated = 0;
        $foundLogins = [];

        foreach ($users as $user) {
            $foundLogins[$user->login] = true;

            // Preserve l'existant si la clé n'est pas remise à jour cette run
            // (cas d'une run `--partition=/home` unique, ou rapport partition
            // vide).
            $existing = is_array($user->quota_snapshot) ? $user->quota_snapshot : [];
            $snapshot = $existing;

            foreach ($perPartitionParsed as $partition => $byLogin) {
                $raw = $byLogin[$user->login] ?? null;
                if ($raw === null) {
                    // User absent pour cette partition. On PRÉSERVE la clé
                    // existante — un rapport vide / user non listé ne doit
                    // PAS effacer le snapshot (cohérent D2 : conservation).
                    continue;
                }

                $snapshot[$this->partitionKey($partition)] = $this->buildPartitionSnapshot($raw);
            }

            $snapshot['captured_at'] = $capturedAt;

            $user->forceFill(['quota_snapshot' => $snapshot])->save();
            $updated++;
        }

        // Users présents dans le rapport XFS mais PAS en BDD : probablement
        // des comptes système non-synchronisés — on les logge séparément
        // pour audit (vs D2 qui traite les users BDD absents du rapport).
        $missingFromDb = array_diff($allLogins, array_keys($foundLogins));
        foreach ($missingFromDb as $login) {
            Log::info('QuotaService: login XFS sans correspondance BDD', [
                'login' => $login,
            ]);
        }

        return [
            'updated' => $updated,
            'missing' => count($missingFromDb),
            // Rempli par logDbUsersAbsentFromReport() après cette méthode.
            'db_absent_from_xfs' => 0,
        ];
    }

    /**
     * Mappe une partition brute vers la clé JSON utilisée dans le snapshot.
     * - `/home` → `home`
     * - `/var/sambaedu` → `sambaedu`
     */
    public function partitionKey(string $partition): string
    {
        return match ($partition) {
            QuotaRule::PARTITION_HOME => 'home',
            QuotaRule::PARTITION_SAMBAEDU => 'sambaedu',
            default => trim(str_replace('/', '_', $partition), '_'),
        };
    }

    /**
     * Construit le sous-document snapshot pour une partition donnée.
     * D5 : bruts (kb) + pré-convertis (mb) + percent pré-calculé.
     *
     * @param  array{used_kb:int,soft_kb:int,hard_kb:int,is_over_soft:bool,grace_days:?int}  $raw
     * @return array<string, mixed>
     */
    public function buildPartitionSnapshot(array $raw): array
    {
        $usedMb = (int) round($raw['used_kb'] / 1024);
        $softMb = (int) round($raw['soft_kb'] / 1024);
        $hardMb = (int) round($raw['hard_kb'] / 1024);

        // Recalcul depuis kb bruts — évite la perte de précision du double
        // arrondi used_mb/soft_mb (ex: used=1000kb, soft=1023kb → 98% et
        // non 100% qui résulterait du calcul en mb arrondis).
        $percent = $raw['soft_kb'] > 0
            ? min(100, (int) round($raw['used_kb'] / $raw['soft_kb'] * 100))
            : 0;

        $isOverHard = $raw['hard_kb'] > 0 && $raw['used_kb'] > $raw['hard_kb'];

        return [
            'used_kb' => $raw['used_kb'],
            'soft_kb' => $raw['soft_kb'],
            'hard_kb' => $raw['hard_kb'],
            'used_mb' => $usedMb,
            'soft_mb' => $softMb,
            'hard_mb' => $hardMb,
            'percent' => $percent,
            'is_over_soft' => (bool) $raw['is_over_soft'],
            'is_over_hard' => $isOverHard,
            'grace_days' => $raw['grace_days'],
        ];
    }
}

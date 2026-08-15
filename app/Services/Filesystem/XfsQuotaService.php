<?php

namespace App\Services\Filesystem;

use App\Exceptions\Filesystem\QuotaPartitionUnavailableException;
use App\Models\QuotaRule;
use App\Models\QuotaAuditLog;
use App\Models\QuotaSetting;
use App\Models\User;
use App\Jobs\ApplyQuotaJob;
use App\Services\Filesystem\Backend\Posix\PosixDiagnostic;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Collection;

/**
 * Service de gestion des quotas disque XFS
 *
 * Encapsule :
 * - Le calcul du quota effectif (règle utilisateur > groupe > défaut d'instance)
 * - Les appels système XFS (lecture/écriture)
 * - La disponibilité du quota d'une partition, à trois issues distinctes
 * - L'audit des modifications
 *
 * Déplacé depuis App\Services\QuotaService (Story 5.1a).
 * Le cache 5 min (Cache::remember) a été supprimé — les méthodes getDiskUsage
 * et getPartitionInfo lisent directement XFS. Un snapshot BDD quotidien
 * remplacera cette optimisation en Story 5.1b.
 *
 * Note : les préfixes de log « QuotaService: » sont conservés volontairement
 * pour ne pas perturber les grep opérateurs sur /var/log/ (décision SM 5.1a).
 */
class XfsQuotaService
{
    private ?array $config = null;

    /**
     * Mémoïsation d'UN SEUL utilisateur — le dernier résolu.
     * Voir {@see resolveDirectoryIdentity()} pour le pourquoi de cette borne.
     */
    private ?string $identityMemoFor = null;

    /** @var array{groups: list<string>}|null */
    private ?array $identityMemo = null;

    /**
     * Le quota est APPLIQUÉ sur la partition, mais l'application est éteinte.
     * Littéral figé — l'écran ferme le champ avec ce motif à côté.
     */
    public const REASON_ENFORCEMENT_OFF = 'Les quotas ne sont pas appliqués sur cette partition. '
        .'Activez-les sur le serveur avant d\'y poser un plafond.';

    /**
     * La commande d'état a ÉCHOUÉ. **CE N'EST PAS UN CONSTAT, C'EST UNE ABSENCE DE
     * MESURE** (correction de revue) : un code de retour non nul recouvre deux
     * réalités disjointes — « cette partition ne porte réellement pas de quota » et
     * « je n'ai pas pu le mesurer » (élévation refusée, outil absent, chemin
     * d'exécution du serveur d'application). Trancher la première serait affirmer un
     * fait qu'on n'a pas observé, et une élévation cassée VERROUILLERAIT alors
     * l'écriture du plafond en annonçant une contre-vérité.
     *
     * C'est exactement la distinction que le contrat de backend a apprise :
     * « conforme » et « non mesurable » ne se confondent jamais.
     *
     * Le `%s` porte la sortie système NEUTRALISÉE.
     */
    public const REASON_NOT_MEASURABLE = 'Impossible de déterminer si cette partition porte un quota : %s. '
        .'Vérifiez l\'outil et l\'élévation sur le serveur.';

    public function __construct()
    {
        $this->loadLegacyConfig();
    }

    /**
     * Charge la configuration legacy si disponible
     */
    private function loadLegacyConfig(): void
    {
        try {
            $configPath = base_path('../includes/config.inc.php');
            if (file_exists($configPath) && function_exists('get_config')) {
                $this->config = get_config();
            }
        } catch (\Throwable $e) {
            Log::warning('QuotaService: Impossible de charger la config legacy', ['error' => $e->getMessage()]);
        }
    }

    // =========================================================================
    // LECTURE DES QUOTAS EFFECTIFS
    // =========================================================================

    /**
     * Calcule le quota effectif pour un utilisateur sur une partition.
     *
     * **TROIS ÉTAGES, ET AUCUNE DEVINETTE** (story 63.4) :
     *  1. la règle NOMINATIVE (`TYPE_USER`) ;
     *  2. la PLUS GRANDE règle parmi les groupes d'appartenance (`TYPE_GROUP`) ;
     *  3. le DÉFAUT D'INSTANCE (`TYPE_DEFAULT`, une ligne par partition) ;
     *  — et « illimité » si aucune règle n'existe.
     *
     * ---------------------------------------------------------------------------
     * **CE QUI A DISPARU, ET POURQUOI.** Cette méthode prenait un quatrième
     * paramètre `$userProfile` qui choisissait, en dernier étage, l'une de quatre
     * lignes de défaut. Ce profil n'était attaché à rien : il se DEVINAIT par des
     * comparaisons de sous-chaîne sur des noms de groupes, différentes selon
     * l'appelant. Un même compte pouvait donc recevoir deux plafonds distincts
     * selon l'écran qui posait la question.
     *
     * Un étage supplémentaire traitait à part les comptes rattachés à un autre
     * établissement, avec une lecture de table interne à cette méthode. Il tombe
     * avec le reste : un compte externe reçoit le défaut d'instance comme tout le
     * monde, et un budget particulier se pose en RÈGLE DE GROUPE, qui se voit.
     * ---------------------------------------------------------------------------
     *
     * @param string $username Nom d'utilisateur
     * @param string $partition /home ou /var/sambaedu
     * @param array $userGroups Groupes d'annuaire de l'utilisateur
     * @return array{source: string, source_name: string|null, quota_soft_mb: int, quota_hard_mb: int, is_unlimited: bool}
     */
    public function getEffectiveQuota(
        string $username,
        string $partition,
        array $userGroups = []
    ): array {
        // 1. Chercher un quota utilisateur explicite
        $userRule = QuotaRule::active()
            ->forPartition($partition)
            ->where('type', QuotaRule::TYPE_USER)
            ->where('target', $username)
            ->first();

        if ($userRule) {
            return [
                'source' => 'user',
                'source_name' => $username,
                'quota_soft_mb' => $userRule->quota_soft_mb,
                'quota_hard_mb' => $userRule->quota_hard_mb,
                'is_unlimited' => $userRule->isUnlimited(),
            ];
        }

        // 2. Chercher le plus grand quota parmi les groupes
        if (!empty($userGroups)) {
            $groupRule = QuotaRule::active()
                ->forPartition($partition)
                ->where('type', QuotaRule::TYPE_GROUP)
                ->whereIn('target', $userGroups)
                ->orderByDesc('quota_hard_mb')
                ->first();

            if ($groupRule) {
                // quota_hard_mb = 0 signifie illimité (prioritaire)
                if ($groupRule->quota_hard_mb === 0) {
                    return [
                        'source' => 'group',
                        'source_name' => $groupRule->target,
                        'quota_soft_mb' => 0,
                        'quota_hard_mb' => 0,
                        'is_unlimited' => true,
                    ];
                }

                return [
                    'source' => 'group',
                    'source_name' => $groupRule->target,
                    'quota_soft_mb' => $groupRule->quota_soft_mb,
                    'quota_hard_mb' => $groupRule->quota_hard_mb,
                    'is_unlimited' => false,
                ];
            }
        }

        // 3. Le DÉFAUT D'INSTANCE — une seule ligne par partition, la même pour
        //    tout compte qu'aucune règle nominative ni règle de groupe ne couvre.
        $defaultRule = QuotaRule::active()
            ->forPartition($partition)
            ->where('type', QuotaRule::TYPE_DEFAULT)
            ->first();

        if ($defaultRule) {
            return [
                'source' => 'default',
                'source_name' => $defaultRule->getTypeLabel(),
                'quota_soft_mb' => $defaultRule->quota_soft_mb,
                'quota_hard_mb' => $defaultRule->quota_hard_mb,
                'is_unlimited' => $defaultRule->isUnlimited(),
            ];
        }

        // Aucune règle trouvée = illimité
        return [
            'source' => 'none',
            'source_name' => null,
            'quota_soft_mb' => 0,
            'quota_hard_mb' => 0,
            'is_unlimited' => true,
        ];
    }

    /**
     * Lit l'utilisation disque actuelle d'un utilisateur via XFS
     *
     * Lecture directe XFS (sans cache — Story 5.1a).
     * Un snapshot BDD quotidien remplacera cette lecture directe en Story 5.1b.
     *
     * @param string $username
     * @return array{home: array, sambaedu: array}
     */
    public function getDiskUsage(string $username): array
    {
        return [
            'home' => $this->readXfsQuota($username, QuotaRule::PARTITION_HOME),
            'sambaedu' => $this->readXfsQuota($username, QuotaRule::PARTITION_SAMBAEDU),
        ];
    }

    /**
     * Lit les quotas XFS pour un utilisateur sur une partition
     */
    private function readXfsQuota(string $username, string $partition): array
    {
        $default = [
            'used_mb' => 0,
            'quota_soft_mb' => 0,
            'quota_hard_mb' => 0,
            'grace_days' => null,
            'is_over_soft' => false,
            'is_over_hard' => false,
            'error' => null,
        ];

        $safeUsername = escapeshellarg($username);
        $safePartition = escapeshellarg($partition);

        $command = "sudo quota -u {$safeUsername} -F xfs -p -v --show-mntpoint --hide-device 2>&1";

        try {
            $output = [];
            $returnCode = 0;
            exec($command, $output, $returnCode);

            if ($returnCode !== 0 || count($output) < 3) {
                return $default;
            }

            foreach ($output as $line) {
                if (strpos($line, $partition) === false) {
                    continue;
                }

                $parts = preg_split('/\s+/', trim($line));
                if (count($parts) < 5) {
                    continue;
                }

                $usedKb = (int) preg_replace('/\*$/', '', $parts[1]);
                $softKb = (int) $parts[2];
                $hardKb = (int) $parts[3];
                $grace = $parts[4] ?? null;

                $isOverSoft = str_ends_with($parts[1], '*');
                $graceDays = null;

                if ($isOverSoft && $grace && preg_match('/^(\d+)/', $grace, $m)) {
                    $graceDays = (int) $m[1];
                }

                return [
                    'used_mb' => (int) round($usedKb / 1024),
                    'quota_soft_mb' => (int) round($softKb / 1024),
                    'quota_hard_mb' => (int) round($hardKb / 1024),
                    'grace_days' => $graceDays,
                    'is_over_soft' => $isOverSoft,
                    'is_over_hard' => $usedKb > $hardKb && $hardKb > 0,
                    'error' => null,
                ];
            }

            return $default;
        } catch (\Throwable $e) {
            Log::error('QuotaService: Erreur lecture XFS', [
                'username' => $username,
                'partition' => $partition,
                'error' => $e->getMessage(),
            ]);
            $default['error'] = $e->getMessage();
            return $default;
        }
    }

    /**
     * Lit les informations de quota d'une partition (état, période de grâce)
     *
     * Lecture directe XFS (sans cache — Story 5.1a).
     */
    public function getPartitionInfo(string $partition): array
    {
        $probe = $this->probePartitionQuotaState($partition);

        $info = [
            'partition' => $partition,
            'enabled' => false,
            'grace_days' => 0,
        ];

        foreach ($probe['output'] as $line) {
            if (preg_match('/^Blocks grace time: \[(\d+) days\]$/', $line, $m)) {
                $info['grace_days'] = (int) $m[1];
            } elseif (preg_match('/^\s*Enforcement: (.*)$/', $line, $m)) {
                $info['enabled'] = trim($m[1]) === 'ON';
            }
        }

        return $info;
    }

    /**
     * Story 63.4 — **UN PLAFOND NON POSABLE SE DIT, IL NE SE DEVINE PAS.**
     *
     * ---------------------------------------------------------------------------
     * **CE QUE LE CODE SAVAIT, ET CE QU'IL EN FAISAIT.** {@see getPartitionInfo()}
     * lance la même commande d'état et AVALE son code de retour : sur une partition
     * qui ne porte pas de quota de projet, la commande échoue, aucune ligne ne
     * correspond aux deux motifs, et la méthode rend `enabled: false` — c'est-à-dire
     * EXACTEMENT ce qu'elle rend sur une partition parfaitement saine dont
     * l'application est simplement éteinte, et exactement ce qu'elle rend quand
     * l'élévation de privilège est cassée. Trois états effondrés en un booléen.
     *
     * **Cette méthode les sépare, et ne fait que ça.** Elle N'AJOUTE AUCUNE
     * détection de type de système de fichiers : elle lit le CODE DE RETOUR que
     * l'autre jetait. Ajouter une seconde lecture (montages, statistiques de
     * système de fichiers) ouvrirait un second chemin de vérité qui divergerait du
     * premier — le dépôt tient une seule résolution par décision.
     *
     * « NON APPLICABLE » NE SE CONFOND JAMAIS AVEC « NON MESURABLE » : les deux
     * ferment le champ, mais avec un motif différent, et c'est le motif qui dit à
     * l'exploitant si le geste à faire est sur le serveur ou nulle part.
     * ---------------------------------------------------------------------------
     *
     * @return array{available: bool, reason: string|null}
     */
    public function partitionQuotaAvailability(string $partition): array
    {
        $probe = $this->probePartitionQuotaState($partition);

        if ($probe['exit_code'] !== 0) {
            return [
                'available' => false,
                'reason' => sprintf(
                    self::REASON_NOT_MEASURABLE,
                    // Le point final de la cause est retiré : le motif en pose un.
                    rtrim(PosixDiagnostic::neutralize(implode("\n", $probe['output'])), '.'),
                ),
            ];
        }

        $enforced = false;

        foreach ($probe['output'] as $line) {
            if (preg_match('/^\s*Enforcement: (.*)$/', $line, $m)) {
                $enforced = trim($m[1]) === 'ON';
            }
        }

        return $enforced
            ? ['available' => true, 'reason' => null]
            : ['available' => false, 'reason' => self::REASON_ENFORCEMENT_OFF];
    }

    /**
     * Story 63.4, correction de revue — **LA GARDE NE VAUT QUE SUR UN ESPACE SERVI
     * PAR LE SERVEUR DE FICHIERS.**
     *
     * ---------------------------------------------------------------------------
     * La garde d'écriture du défaut d'instance protège d'un plafond qu'on croirait
     * posé sur un système de fichiers qui ne peut pas le porter. Mais quand l'espace
     * concerné ne vit PLUS sur le serveur de fichiers, ce plafond ne s'adresse plus
     * à lui : il gouverne le plafond du compte sur l'instance cloud, que le
     * provisionnement lit dans la même règle. Laisser un système de fichiers local
     * hors sujet fermer cet écran-là fermerait le SEUL endroit où se règle le
     * plafond du cloud — un refus exact, appliqué à la mauvaise question.
     *
     * Lecture TOLÉRANTE : une décision d'emplacement illisible ou absente rend
     * `true`, c'est-à-dire la garde. On ne relâche une protection que sur une
     * information qu'on a réellement lue.
     * ---------------------------------------------------------------------------
     */
    public function partitionIsServedOverSmb(string $partition): bool
    {
        try {
            $locations = FileLocationService::current();
        } catch (\Throwable) {
            return true;
        }

        return match ($partition) {
            QuotaRule::PARTITION_HOME => $locations->espacePersoSurSmb(),
            QuotaRule::PARTITION_SAMBAEDU => $locations->espacePartageSurSmb(),
            default => true,
        };
    }

    /**
     * L'appel système d'ÉTAT lui-même, **protégé** : c'est la couture par laquelle un
     * test substitue une partition, exactement le motif retenu pour
     * {@see readDirectoryIdentity()}. Sans elle, aucune assertion n'est possible sur
     * l'hôte, où l'outil n'existe pas.
     *
     * Le code de retour est RENDU, jamais interprété ici : c'est l'appelant qui
     * décide ce qu'il en fait, et il y en a deux qui n'en font pas la même chose.
     *
     * @return array{output: list<string>, exit_code: int}
     */
    protected function probePartitionQuotaState(string $partition): array
    {
        $safePartition = escapeshellarg($partition);
        $command = "sudo xfs_quota -x -c 'state -u' {$safePartition} 2>&1";

        $output = [];
        $returnCode = 0;
        exec($command, $output, $returnCode);

        return ['output' => array_values($output), 'exit_code' => (int) $returnCode];
    }

    /**
     * L'appel système de RELEVÉ D'OCCUPATION, seconde couture de test.
     *
     * **UNE seule lecture pour toute la partition** : annoncer « combien de comptes
     * basculeraient en dépassement » compte par compte coûterait un appel système par
     * personne, ce qui est irrecevable dans le rendu d'un écran. Le relevé donne
     * l'occupation de tout le monde d'un coup ; on ne garde ensuite que les lignes qui
     * concernent des comptes du produit.
     *
     * @return array{output: list<string>, exit_code: int}
     */
    protected function probePartitionUsageReport(string $partition): array
    {
        $safePartition = escapeshellarg($partition);
        $command = "sudo xfs_quota -x -c 'report -u -N' {$safePartition} 2>&1";

        $output = [];
        $returnCode = 0;
        exec($command, $output, $returnCode);

        return ['output' => array_values($output), 'exit_code' => (int) $returnCode];
    }

    /**
     * Story 63.4, correction de revue — **LES COMPTES QUE LE DÉFAUT D'INSTANCE
     * COUVRE, ET CE QUE LEUR APPLIQUER COÛTERAIT.**
     *
     * ---------------------------------------------------------------------------
     * **POURQUOI CE DÉNOMBREMENT EXISTE.** Écrire le défaut d'instance en base ne
     * l'écrit sur AUCUN compte : les plafonds ne bougent qu'au geste suivant. Le
     * porter à tout le monde est donc un geste SÉPARÉ et EXPLICITE — mais un geste
     * qu'on ne fait pas à l'aveugle : il peut mettre en dépassement immédiat des
     * comptes qui, jusque-là, écrivaient sans contrainte. L'écran annonce donc les
     * deux nombres AVANT le clic.
     *
     * **`mesure` DIT SI LE SECOND NOMBRE VEUT DIRE QUELQUE CHOSE.** Quand le relevé
     * d'occupation ne répond pas, `depassements` vaut zéro — et zéro constaté n'est
     * pas zéro mesuré. Les confondre annoncerait « personne ne bascule » sur la foi
     * d'une commande qui n'a pas tourné.
     * ---------------------------------------------------------------------------
     *
     * @return array{couverts: int, depassements: int, mesure: bool}
     */
    public function instanceDefaultCoverage(string $partition): array
    {
        $logins = $this->loginsCoveredByInstanceDefault($partition);

        $coverage = ['couverts' => count($logins), 'depassements' => 0, 'mesure' => false];

        $rule = QuotaRule::active()
            ->forPartition($partition)
            ->where('type', QuotaRule::TYPE_DEFAULT)
            ->first();

        if ($rule === null || $rule->quota_hard_mb <= 0 || $logins === []) {
            return $coverage;
        }

        $usage = $this->readPartitionUsage($partition);

        if ($usage === null) {
            return $coverage;
        }

        $coverage['mesure'] = true;

        foreach ($logins as $login) {
            if (($usage[$login] ?? 0) > $rule->quota_hard_mb) {
                $coverage['depassements']++;
            }
        }

        return $coverage;
    }

    /**
     * **LE GESTE EXPLICITE** : porter le défaut d'instance à tout compte qu'aucune
     * règle nominative ne couvre. Rend le nombre de comptes mis en file.
     *
     * ⚠️ Les règles de GROUPE restent respectées : on ne met pas le défaut en file,
     * on met le quota EFFECTIF de chaque compte — sans quoi ce geste rétrécirait le
     * plafond de tout compte couvert par une règle de groupe plus large, exactement
     * le rétrécissement silencieux que le reste de cette classe s'interdit.
     *
     * **L'annuaire n'est interrogé que s'il existe une règle de groupe sur la
     * partition.** Sans elles, le quota effectif est le défaut pour tout le monde, et
     * un balayage d'établissement coûte ZÉRO aller-retour d'annuaire.
     */
    public function applyInstanceDefault(string $partition, string $performedBy): int
    {
        $logins = $this->loginsCoveredByInstanceDefault($partition);

        if ($logins === []) {
            return 0;
        }

        $hasGroupRules = QuotaRule::active()
            ->forPartition($partition)
            ->where('type', QuotaRule::TYPE_GROUP)
            ->exists();

        $dispatched = 0;

        foreach ($logins as $login) {
            $groups = $hasGroupRules ? $this->getUserGroups($login) : [];
            $effective = $this->getEffectiveQuota($login, $partition, $groups);

            ApplyQuotaJob::dispatch(
                $login,
                $partition,
                $effective['quota_soft_mb'],
                $effective['quota_hard_mb'],
                $performedBy,
                null
            );

            $dispatched++;
        }

        Log::info('QuotaService: application du défaut d\'instance', [
            'partition' => $partition,
            'comptes' => $dispatched,
            'performed_by' => $performedBy,
        ]);

        return $dispatched;
    }

    /**
     * Les comptes actifs du produit qu'AUCUNE règle nominative ne couvre — ceux, donc,
     * dont le plafond dépend d'une règle de groupe ou du défaut d'instance.
     *
     * Les identités FÉDÉRÉES en sont exclues : elles n'ont pas de répertoire sur ce
     * serveur de fichiers, et leur poser un plafond n'aurait pas de sujet.
     *
     * @return list<string>
     */
    private function loginsCoveredByInstanceDefault(string $partition): array
    {
        try {
            $query = User::query()->where('is_active', true);

            if (Schema::hasColumn('users', 'source')) {
                $query->where('source', 'ad');
            }

            $logins = $query->pluck('login')
                ->filter(fn ($login) => is_string($login) && $login !== '')
                ->values()
                ->all();

            $nominative = QuotaRule::active()
                ->forPartition($partition)
                ->where('type', QuotaRule::TYPE_USER)
                ->pluck('target')
                ->all();

            return array_values(array_diff($logins, $nominative));
        } catch (\Throwable $e) {
            Log::warning('QuotaService: dénombrement des comptes couverts impossible', [
                'partition' => $partition,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * L'occupation relevée, `login => méga-octets`, ou `null` si le relevé n'a pas
     * répondu — **`null` n'est pas un relevé vide**, même doctrine que la résolution
     * d'annuaire.
     *
     * @return array<string, int>|null
     */
    private function readPartitionUsage(string $partition): ?array
    {
        $probe = $this->probePartitionUsageReport($partition);

        if ($probe['exit_code'] !== 0) {
            return null;
        }

        $usage = [];

        foreach ($probe['output'] as $line) {
            $parts = preg_split('/\s+/', trim((string) $line));

            if (! is_array($parts) || count($parts) < 2 || $parts[0] === '') {
                continue;
            }

            $usedKb = (int) preg_replace('/\D/', '', $parts[1]);
            $usage[$parts[0]] = (int) round($usedKb / 1024);
        }

        return $usage;
    }

    // =========================================================================
    // GESTION DES RÈGLES DE QUOTAS
    // =========================================================================

    /**
     * Crée ou met à jour une règle de quota
     *
     * **La garde de disponibilité est REJOUÉE ICI, et pour le seul défaut
     * d'instance** (story 63.4) : une garde qui ne vit que dans l'écran protège
     * l'étourderie, pas la requête forgée. Refusée, elle n'écrit RIEN — ni règle,
     * ni ligne d'audit.
     *
     * ⚠️ Elle ne porte volontairement PAS sur les règles nominatives et de groupe :
     * elles s'écrivent depuis la fiche d'un compte, celle d'un groupe et le
     * contrôleur de quotas, et les refuser serait une régression hors périmètre.
     *
     * ⚠️ Elle ne porte pas non plus sur un espace qui n'est plus servi par le
     * serveur de fichiers — voir {@see self::partitionIsServedOverSmb()}.
     *
     * @param string $type user, group, default
     * @param string|null $target Nom utilisateur ou groupe (null pour le défaut)
     * @param string $partition
     * @param int $quotaSoftMb
     * @param int $quotaHardMb
     * @param string $performedBy Utilisateur effectuant l'action
     * @param bool $applyImmediately Appliquer immédiatement sur le filesystem
     * @return QuotaRule
     *
     * @throws QuotaPartitionUnavailableException si le défaut est posé sur une
     *                                           partition qui ne porte pas de quota
     */
    public function setQuotaRule(
        string $type,
        ?string $target,
        string $partition,
        int $quotaSoftMb,
        int $quotaHardMb,
        string $performedBy,
        bool $applyImmediately = true
    ): QuotaRule {
        if ($type === QuotaRule::TYPE_DEFAULT && $this->partitionIsServedOverSmb($partition)) {
            $availability = $this->partitionQuotaAvailability($partition);

            if ($availability['available'] !== true) {
                throw QuotaPartitionUnavailableException::forPartition(
                    $partition,
                    (string) $availability['reason'],
                );
            }
        }

        $existing = QuotaRule::where('type', $type)
            ->where('target', $target)
            ->where('partition', $partition)
            ->first();

        $oldValues = $existing ? $existing->toArray() : null;
        $action = $existing ? QuotaAuditLog::ACTION_UPDATE : QuotaAuditLog::ACTION_CREATE;

        $rule = QuotaRule::updateOrCreate(
            [
                'type' => $type,
                'target' => $target,
                'partition' => $partition,
            ],
            [
                'quota_soft_mb' => $quotaSoftMb,
                'quota_hard_mb' => $quotaHardMb,
                'is_active' => true,
            ]
        );

        // Log d'audit
        QuotaAuditLog::log(
            $action,
            $performedBy,
            $type,
            $target,
            $partition,
            $oldValues,
            $rule->toArray(),
            $rule->id
        );

        // Appliquer sur le filesystem
        if ($applyImmediately) {
            $this->dispatchApplyJob($rule, $performedBy);
        }

        return $rule;
    }

    /**
     * Supprime une règle de quota (retour à l'héritage)
     */
    public function deleteQuotaRule(QuotaRule $rule, string $performedBy): bool
    {
        $oldValues = $rule->toArray();

        QuotaAuditLog::log(
            QuotaAuditLog::ACTION_DELETE,
            $performedBy,
            $rule->type,
            $rule->target,
            $rule->partition,
            $oldValues,
            null,
            $rule->id
        );

        // Si c'est un groupe, recalculer les quotas des membres
        if ($rule->type === QuotaRule::TYPE_GROUP && $rule->target) {
            $this->dispatchRecalculateGroupJob($rule->target, $rule->partition, $performedBy);
        }

        return $rule->delete();
    }

    // =========================================================================
    // APPLICATION SUR LE FILESYSTEM
    // =========================================================================

    /**
     * Applique un quota sur le filesystem XFS
     *
     * @param string $username
     * @param string $partition
     * @param int $quotaSoftMb
     * @param int $quotaHardMb
     * @return array{success: bool, error: string|null}
     */
    public function applyQuotaToFilesystem(
        string $username,
        string $partition,
        int $quotaSoftMb,
        int $quotaHardMb
    ): array {
        $safeUsername = escapeshellarg($username);
        $safePartition = escapeshellarg($partition);

        $command = sprintf(
            "sudo xfs_quota -x -c 'limit -u bsoft=%dm bhard=%dm %s' %s 2>&1",
            $quotaSoftMb,
            $quotaHardMb,
            $safeUsername,
            $safePartition
        );

        $output = [];
        $returnCode = 0;
        exec($command, $output, $returnCode);

        $success = $returnCode === 0;
        $error = $success ? null : implode("\n", $output);

        Log::info('QuotaService: Application quota XFS', [
            'username' => $username,
            'partition' => $partition,
            'soft_mb' => $quotaSoftMb,
            'hard_mb' => $quotaHardMb,
            'success' => $success,
            'error' => $error,
        ]);

        return [
            'success' => $success,
            'error' => $error,
        ];
    }

    /**
     * Définit la période de grâce pour une partition
     */
    public function setGracePeriod(string $partition, int $days, string $performedBy): array
    {
        $safePartition = escapeshellarg($partition);

        $command = sprintf(
            "sudo xfs_quota -x -c 'timer -u %dd' %s 2>&1",
            $days,
            $safePartition
        );

        $output = [];
        $returnCode = 0;
        exec($command, $output, $returnCode);

        $success = $returnCode === 0;

        if ($success) {
            // Mettre à jour les paramètres en base
            $setting = QuotaSetting::forPartition($partition);
            $setting->grace_period_days = $days;
            $setting->save();
        }

        Log::info('QuotaService: Modification période de grâce', [
            'partition' => $partition,
            'days' => $days,
            'performed_by' => $performedBy,
            'success' => $success,
        ]);

        return [
            'success' => $success,
            'error' => $success ? null : implode("\n", $output),
        ];
    }

    // =========================================================================
    // JOBS
    // =========================================================================

    /**
     * Dispatch un job pour appliquer le quota.
     *
     * ⚠️ **LA TROISIÈME BRANCHE EST NOUVELLE** (correction de revue 63.4) : sans
     * elle, un défaut d'instance traversait cette méthode SANS RIEN FAIRE, et le
     * plafond saisi à l'écran n'atteignait jamais le système de fichiers — le seul
     * chemin par lequel il y arrivait était la suppression d'une règle de groupe.
     * La story aurait alors remplacé « un formulaire qui n'applique rien » par « une
     * ligne en base qui n'atteint personne ».
     *
     * ⚠️ **Elle n'est empruntée que sur une demande EXPLICITE** : l'écran enregistre
     * le défaut avec `applyImmediately: false`, et l'application à tous les comptes
     * couverts est un second geste, annoncé et confirmé
     * ({@see self::applyInstanceDefault()}).
     */
    private function dispatchApplyJob(QuotaRule $rule, string $performedBy): void
    {
        if ($rule->type === QuotaRule::TYPE_USER && $rule->target) {
            // Quota utilisateur : appliquer directement
            ApplyQuotaJob::dispatch(
                $rule->target,
                $rule->partition,
                $rule->quota_soft_mb,
                $rule->quota_hard_mb,
                $performedBy,
                $rule->id
            );
        } elseif ($rule->type === QuotaRule::TYPE_GROUP && $rule->target) {
            // Quota groupe : appliquer à tous les membres
            $this->dispatchRecalculateGroupJob($rule->target, $rule->partition, $performedBy);
        } elseif ($rule->type === QuotaRule::TYPE_DEFAULT) {
            // Défaut d'instance : recalculer tout compte qu'aucune règle nominative
            // ne couvre — les règles de groupe restent respectées.
            $this->applyInstanceDefault($rule->partition, $performedBy);
        }
    }

    /**
     * Dispatch des jobs pour recalculer les quotas des membres d'un groupe
     */
    private function dispatchRecalculateGroupJob(string $groupName, string $partition, string $performedBy): void
    {
        // Récupérer les membres du groupe via LDAP
        $members = $this->getGroupMembers($groupName);

        foreach ($members as $username) {
            // Recalculer le quota effectif pour chaque membre
            $userGroups = $this->getUserGroups($username);
            $effective = $this->getEffectiveQuota($username, $partition, $userGroups);

            ApplyQuotaJob::dispatch(
                $username,
                $partition,
                $effective['quota_soft_mb'],
                $effective['quota_hard_mb'],
                $performedBy,
                null
            );
        }
    }

    /**
     * Récupère les membres d'un groupe AD
     */
    private function getGroupMembers(string $groupName): array
    {
        try {
            if (function_exists('search_people_group') && $this->config) {
                $members = search_people_group($this->config, $groupName);
                return array_column($members, 'cn');
            }
        } catch (\Throwable $e) {
            Log::warning('QuotaService: Erreur récupération membres groupe', [
                'group' => $groupName,
                'error' => $e->getMessage(),
            ]);
        }
        return [];
    }

    /**
     * Récupère les groupes d'un utilisateur.
     *
     * Chemin TOLÉRANT, conservé tel quel pour les appelants internes : un annuaire
     * muet rend une liste vide, et le calcul se poursuit. Les appelants qui ne
     * PEUVENT PAS se permettre cette tolérance passent par
     * {@see resolveDirectoryIdentity()}, qui distingue « aucun groupe » de
     * « on ne sait pas ».
     */
    private function getUserGroups(string $username): array
    {
        return $this->resolveDirectoryIdentity($username)['groups'] ?? [];
    }

    /**
     * Correction de revue 61.3 #1, **amputée de sa moitié « profil » par la story
     * 63.4** — la résolution d'annuaire, et elle sait dire « je ne sais pas ».
     *
     * ---------------------------------------------------------------------------
     * **CE QUI A DISPARU.** Elle rendait AUSSI un profil (élève / prof / admin),
     * déterminé par comparaison de sous-chaîne sur les appartenances d'annuaire.
     * Cette détermination-là est morte avec les quatre défauts par profil : le
     * plafond par défaut est un réglage d'INSTANCE, et un budget particulier se
     * pose en règle de groupe.
     *
     * **CE QUI RESTE EST PORTANT.** Les GROUPES alimentent l'étage 2 de
     * {@see getEffectiveQuota()} — c'est par eux qu'une règle de groupe atteint un
     * compte, y compris sur le chemin de provisionnement d'un cloud. Supprimer la
     * méthode entière aurait éteint cet étage sans qu'aucun test ne le voie.
     * ---------------------------------------------------------------------------
     *
     * **`null` N'EST PAS UNE LISTE VIDE, et la doctrine survit transposée.**
     * Annuaire indisponible, compte introuvable, entrée sans appartenances : ce
     * sont des états où l'on ne sait RIEN des groupes du compte. Les confondre avec
     * « ce compte n'est dans aucun groupe » ferait retomber sur le défaut
     * d'instance un compte couvert par une règle de groupe plus large — un
     * rétrécissement silencieux de plafond. Les appelants tolérants se replient (le
     * calcul interne), les appelants qui ÉCRIVENT doivent s'abstenir et le
     * rapporter.
     *
     * **UN SEUL ALLER-RETOUR par utilisateur, et il se mémoïse.** L'appel
     * d'annuaire est le coût dominant de cette classe. La mémoïsation porte sur le
     * dernier utilisateur résolu seulement — les appels pour un même compte sont
     * adjacents, et une carte complète ferait grossir la mémoire à proportion d'un
     * balayage d'établissement sans rien économiser de plus.
     *
     * @return array{groups: list<string>}|null `null` = indéterminable
     */
    public function resolveDirectoryIdentity(string $username): ?array
    {
        if ($this->identityMemoFor === $username) {
            return $this->identityMemo;
        }

        $identity = $this->readDirectoryIdentity($username);

        $this->identityMemoFor = $username;
        $this->identityMemo = $identity;

        return $identity;
    }

    /**
     * L'aller-retour d'annuaire lui-même. **Protégé** : c'est la couture par
     * laquelle un test substitue un annuaire, sans quoi aucune assertion ne peut
     * porter sur des groupes RÉSOLUS (les fonctions legacy ne sont pas chargées
     * hors du runtime SE4).
     *
     * @return array{groups: list<string>}|null
     */
    protected function readDirectoryIdentity(string $username): ?array
    {
        try {
            if (!function_exists('search_user') || !$this->config) {
                return null;
            }

            $entry = search_user($this->config, $username);

            if (!is_array($entry) || $entry === []) {
                // L'annuaire a répondu qu'il ne connaît pas ce compte : on ne sait
                // rien de ses groupes, ce qui n'est pas la même chose que « aucun ».
                return null;
            }

            if (!array_key_exists('memberof', $entry) || !is_array($entry['memberof'])) {
                // L'attribut n'est pas là. Il peut être absent parce que le compte
                // n'appartient à rien, ou parce que la lecture ne l'a pas ramené —
                // on ne sait pas lequel, donc on ne tranche pas.
                return null;
            }

            $groups = [];

            foreach ($entry['memberof'] as $dn) {
                $dn = (string) $dn;

                // Extraire le CN du DN
                $groups[] = preg_match('/^CN=([^,]+)/i', $dn, $m) === 1 ? $m[1] : $dn;
            }

            return ['groups' => array_values($groups)];
        } catch (\Throwable $e) {
            Log::warning('QuotaService: Erreur résolution identité annuaire', [
                'username' => $username,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    // =========================================================================
    // MÉTHODES UTILITAIRES
    // =========================================================================

    /**
     * Liste toutes les règles de quotas
     */
    public function listRules(?string $partition = null): Collection
    {
        $query = QuotaRule::query()->orderBy('type')->orderBy('target');

        if ($partition) {
            $query->forPartition($partition);
        }

        return $query->get();
    }

    /**
     * Liste les règles personnalisées (non-défaut)
     */
    public function listCustomRules(?string $partition = null): Collection
    {
        $query = QuotaRule::query()
            ->whereIn('type', [QuotaRule::TYPE_USER, QuotaRule::TYPE_GROUP])
            ->orderBy('type')
            ->orderBy('target');

        if ($partition) {
            $query->forPartition($partition);
        }

        return $query->get();
    }

    /**
     * Liste les politiques par défaut
     */
    public function listDefaultPolicies(?string $partition = null): Collection
    {
        $query = QuotaRule::defaults();

        if ($partition) {
            $query->forPartition($partition);
        }

        return $query->get();
    }

    /**
     * Récupère les paramètres d'une partition
     */
    public function getSettings(string $partition): QuotaSetting
    {
        return QuotaSetting::forPartition($partition);
    }

    /**
     * Retourne les partitions supportées
     */
    public function getSupportedPartitions(): array
    {
        return [
            QuotaRule::PARTITION_HOME => 'Espace personnel (K:)',
            QuotaRule::PARTITION_SAMBAEDU => 'Partages Classes/Docs (H:/I:)',
        ];
    }

    /**
     * Story 4.7 — true si l'utilisateur est en over-hard sur home OU sambaedu
     * (blocage effectif). Utilisé par OverlaySignalBuilder pour le signal quota
     * « Stockage saturé » (ex-cartouche du compositing WallpaperComposer retiré).
     */
    public function isUserOverQuota(string $username): bool
    {
        $usage = $this->getDiskUsage($username);
        return ($usage['home']['is_over_hard'] ?? false)
            || ($usage['sambaedu']['is_over_hard'] ?? false);
    }

    /**
     * Story 4.7 — retourne la liste des partitions en over-quota (hard OU soft)
     * avec label humain, valeurs Mo et grace_days pour affichage UI overlay.
     *
     * @return array<int,array{label:string,used_mb:int,soft_mb:int,grace_days:int|null}>
     */
    public function getOverQuotaPartitionsFormatted(string $username): array
    {
        $usage = $this->getDiskUsage($username);
        $labels = ['home' => 'Espace perso', 'sambaedu' => 'Espace Classe'];
        $result = [];
        foreach ($usage as $partition => $info) {
            if (($info['is_over_hard'] ?? false) || ($info['is_over_soft'] ?? false)) {
                $result[] = [
                    'label' => $labels[$partition] ?? $partition,
                    'used_mb' => (int) ($info['used_mb'] ?? 0),
                    'soft_mb' => (int) ($info['quota_soft_mb'] ?? 0),
                    'grace_days' => $info['grace_days'] ?? null,
                ];
            }
        }
        return $result;
    }
}

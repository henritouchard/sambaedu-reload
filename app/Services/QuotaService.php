<?php

namespace App\Services;

use App\Models\QuotaRule;
use App\Models\QuotaAuditLog;
use App\Models\QuotaSetting;
use App\Jobs\ApplyQuotaJob;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Collection;

/**
 * Service de gestion des quotas disque
 * 
 * Encapsule :
 * - Le calcul du quota effectif (héritage utilisateur > groupe > défaut)
 * - Les appels système XFS (lecture/écriture)
 * - La gestion des politiques par défaut
 * - L'audit des modifications
 */
class QuotaService
{
    private const CACHE_PREFIX = 'quota_';
    private const CACHE_TTL = 300; // 5 minutes

    private ?array $config = null;

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
            $configPath = dirname(__DIR__, 3) . '/includes/config.inc.php';
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
     * Calcule le quota effectif pour un utilisateur sur une partition
     * 
     * Ordre de priorité :
     * 1. Quota utilisateur explicite
     * 2. Plus grand quota parmi les groupes d'appartenance
     * 3. Politique par défaut selon le profil (élève/prof/admin)
     * 
     * @param string $username Nom d'utilisateur
     * @param string $partition /home ou /var/sambaedu
     * @param array $userGroups Groupes AD de l'utilisateur (memberof)
     * @param string $userProfile Profil : eleve, prof, admin
     * @return array{source: string, source_name: string|null, quota_soft_mb: int, quota_hard_mb: int, is_unlimited: bool}
     */
    public function getEffectiveQuota(
        string $username,
        string $partition,
        array $userGroups = [],
        string $userProfile = 'eleve'
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

        // 3. Appliquer la politique par défaut selon le profil
        $defaultType = match ($userProfile) {
            'prof', 'professeur', 'teacher' => QuotaRule::TYPE_DEFAULT_PROF,
            'admin', 'administrator' => QuotaRule::TYPE_DEFAULT_ADMIN,
            default => QuotaRule::TYPE_DEFAULT_ELEVE,
        };

        $defaultRule = QuotaRule::active()
            ->forPartition($partition)
            ->where('type', $defaultType)
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
     * @param string $username
     * @return array{home: array, sambaedu: array}
     */
    public function getDiskUsage(string $username): array
    {
        $cacheKey = self::CACHE_PREFIX . 'usage_' . $username;

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($username) {
            $result = [
                'home' => $this->readXfsQuota($username, QuotaRule::PARTITION_HOME),
                'sambaedu' => $this->readXfsQuota($username, QuotaRule::PARTITION_SAMBAEDU),
            ];
            return $result;
        });
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
     */
    public function getPartitionInfo(string $partition): array
    {
        $cacheKey = self::CACHE_PREFIX . 'partition_info_' . md5($partition);

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($partition) {
            $safePartition = escapeshellarg($partition);
            $command = "sudo xfs_quota -x -c 'state -u' {$safePartition} 2>&1";

            $output = [];
            $returnCode = 0;
            exec($command, $output, $returnCode);

            $info = [
                'partition' => $partition,
                'enabled' => false,
                'grace_days' => 0,
            ];

            foreach ($output as $line) {
                if (preg_match('/^Blocks grace time: \[(\d+) days\]$/', $line, $m)) {
                    $info['grace_days'] = (int) $m[1];
                } elseif (preg_match('/^\s*Enforcement: (.*)$/', $line, $m)) {
                    $info['enabled'] = trim($m[1]) === 'ON';
                }
            }

            return $info;
        });
    }

    // =========================================================================
    // GESTION DES RÈGLES DE QUOTAS
    // =========================================================================

    /**
     * Crée ou met à jour une règle de quota
     * 
     * @param string $type user, group, default_eleve, default_prof, default_admin
     * @param string|null $target Nom utilisateur ou groupe (null pour défaut)
     * @param string $partition
     * @param int $quotaSoftMb
     * @param int $quotaHardMb
     * @param string $performedBy Utilisateur effectuant l'action
     * @param bool $applyImmediately Appliquer immédiatement sur le filesystem
     * @return QuotaRule
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

        // Invalider le cache
        $this->invalidateCache($target);

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

        $this->invalidateCache($rule->target);

        return $rule->delete();
    }

    /**
     * Définit la politique par défaut pour un profil
     */
    public function setDefaultPolicy(
        string $profile,
        string $partition,
        int $quotaSoftMb,
        int $quotaHardMb,
        string $performedBy
    ): QuotaRule {
        $type = match ($profile) {
            'eleve', 'student' => QuotaRule::TYPE_DEFAULT_ELEVE,
            'prof', 'professeur', 'teacher' => QuotaRule::TYPE_DEFAULT_PROF,
            'admin', 'administrator' => QuotaRule::TYPE_DEFAULT_ADMIN,
            default => throw new \InvalidArgumentException("Profil invalide: {$profile}"),
        };

        return $this->setQuotaRule(
            $type,
            null,
            $partition,
            $quotaSoftMb,
            $quotaHardMb,
            $performedBy,
            false // Les politiques par défaut ne s'appliquent pas directement
        );
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

            // Invalider le cache
            Cache::forget(self::CACHE_PREFIX . 'partition_info_' . md5($partition));
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
    // JOBS ET CACHE
    // =========================================================================

    /**
     * Dispatch un job pour appliquer le quota
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
            $userProfile = $this->getUserProfile($username);
            $effective = $this->getEffectiveQuota($username, $partition, $userGroups, $userProfile);

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
     * Récupère les groupes d'un utilisateur
     */
    private function getUserGroups(string $username): array
    {
        try {
            if (function_exists('search_user') && $this->config) {
                $user = search_user($this->config, $username);
                if (!empty($user['memberof'])) {
                    return array_map(function ($dn) {
                        // Extraire le CN du DN
                        if (preg_match('/^CN=([^,]+)/i', $dn, $m)) {
                            return $m[1];
                        }
                        return $dn;
                    }, $user['memberof']);
                }
            }
        } catch (\Throwable $e) {
            Log::warning('QuotaService: Erreur récupération groupes utilisateur', [
                'username' => $username,
                'error' => $e->getMessage(),
            ]);
        }
        return [];
    }

    /**
     * Détermine le profil d'un utilisateur (élève, prof, admin)
     */
    private function getUserProfile(string $username): string
    {
        try {
            if (function_exists('search_user') && $this->config) {
                $user = search_user($this->config, $username);
                $memberOf = $user['memberof'] ?? [];

                foreach ($memberOf as $dn) {
                    $dnLower = strtolower($dn);
                    if (str_contains($dnLower, 'cn=admins') || str_contains($dnLower, 'cn=domain admins')) {
                        return 'admin';
                    }
                    if (str_contains($dnLower, 'cn=profs') || str_contains($dnLower, 'cn=professeurs')) {
                        return 'prof';
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::warning('QuotaService: Erreur détermination profil', [
                'username' => $username,
                'error' => $e->getMessage(),
            ]);
        }
        return 'eleve';
    }

    /**
     * Invalide le cache pour un utilisateur ou globalement
     */
    private function invalidateCache(?string $target = null): void
    {
        if ($target) {
            Cache::forget(self::CACHE_PREFIX . 'usage_' . $target);
        }
        // On pourrait aussi invalider le cache global si nécessaire
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
}

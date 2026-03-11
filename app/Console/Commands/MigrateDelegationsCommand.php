<?php

namespace App\Console\Commands;

use App\Models\Delegation;
use App\Models\User;
use App\Models\WorkstationGroup;
use App\Services\PermissionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Permission;

/**
 * Commande de migration des délégations legacy AD → table delegations SQL
 * 
 * Lit les délégations existantes depuis l'AD (via les fonctions legacy)
 * et les insère dans la table delegations avec FK vers workstation_groups.
 */
class MigrateDelegationsCommand extends Command
{
    protected $signature = 'sambaedu:migrate-delegations
                            {--dry-run : Affiche les changements sans les appliquer}';

    protected $description = 'Migre les délégations legacy depuis l\'AD vers la table SQL delegations';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');

        $this->info('Migration des délégations legacy AD → SQL');

        // Vérifier que les fonctions legacy sont disponibles
        if (!function_exists('list_delegations')) {
            $this->error('Les fonctions legacy ne sont pas disponibles (list_delegations introuvable).');
            $this->error('Assurez-vous que les includes legacy sont chargés.');
            return self::FAILURE;
        }

        if (!function_exists('get_config')) {
            $this->error('La fonction get_config() n\'est pas disponible.');
            return self::FAILURE;
        }

        $config = get_config();
        $allDelegations = list_delegations($config, '*');

        if (empty($allDelegations)) {
            $this->info('Aucune délégation trouvée dans l\'AD.');
            return self::SUCCESS;
        }

        $this->info(count($allDelegations) . ' délégations trouvées dans l\'AD');

        $bitmaskMapping = PermissionService::getBitmaskMapping();
        $flippedMapping = array_flip($bitmaskMapping);

        $stats = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 0];

        foreach ($allDelegations as $delegation) {
            $userLogin = $delegation['user'] ?? null;
            $parcDn = $delegation['parcdn'] ?? null;
            $profile = $delegation['profile'] ?? null;
            $isNegate = $delegation['negate'] ?? false;

            if (empty($userLogin) || empty($parcDn) || empty($profile)) {
                $stats['skipped']++;
                continue;
            }

            // Trouver l'utilisateur SQL
            $user = User::findByLogin($userLogin);
            if (!$user) {
                $this->warn("  Utilisateur '{$userLogin}' non trouvé en SQL (pas encore synchronisé ?)");
                $stats['skipped']++;
                continue;
            }

            // Convertir le profil legacy en permission Spatie
            $permissionName = $this->profileToPermission($profile, $bitmaskMapping);
            if (!$permissionName) {
                $this->warn("  Profil '{$profile}' non mappable vers une permission Spatie");
                $stats['skipped']++;
                continue;
            }

            $permission = Permission::where('name', $permissionName)->where('guard_name', 'web')->first();
            if (!$permission) {
                $this->warn("  Permission '{$permissionName}' non trouvée en base");
                $stats['skipped']++;
                continue;
            }

            // Trouver le WorkstationGroup physique correspondant au parc legacy
            $parcName = function_exists('ldap_dn2cn') ? ldap_dn2cn($parcDn) : $this->extractCnFromDn($parcDn);
            $wkGroup = WorkstationGroup::where('name', $parcName)
                ->where('is_physical', true)
                ->first();

            if (!$wkGroup) {
                $this->warn("  WorkstationGroup physique '{$parcName}' non trouvé");
                $stats['skipped']++;
                continue;
            }

            if ($dryRun) {
                $action = $isNegate ? 'NEGATE' : 'GRANT';
                $this->info("  [DRY-RUN] {$action} {$permissionName} → {$userLogin} sur {$parcName}");
                $stats['created']++;
                continue;
            }

            try {
                $result = Delegation::updateOrCreate(
                    [
                        'user_id' => $user->id,
                        'workstation_group_id' => $wkGroup->id,
                        'permission_id' => $permission->id,
                        'is_negative' => $isNegate,
                    ],
                    [
                        'granted_by' => null,
                    ]
                );

                if ($result->wasRecentlyCreated) {
                    $stats['created']++;
                } else {
                    $stats['updated']++;
                }
            } catch (\Exception $e) {
                $stats['errors']++;
                Log::warning("[MigrateDelegations] Erreur: " . $e->getMessage(), [
                    'user' => $userLogin,
                    'parc' => $parcName,
                    'permission' => $permissionName,
                ]);
            }
        }

        $prefix = $dryRun ? '[DRY-RUN] ' : '';
        $this->newLine();
        $this->info("{$prefix}Migration terminée :");
        $this->info("  → Créées : {$stats['created']}");
        $this->info("  → Mises à jour : {$stats['updated']}");
        $this->info("  → Ignorées : {$stats['skipped']}");
        $this->info("  → Erreurs : {$stats['errors']}");

        return self::SUCCESS;
    }

    /**
     * Convertit un nom de profil legacy en nom de permission Spatie
     */
    private function profileToPermission(string $profile, array $bitmaskMapping): ?string
    {
        // Le profil legacy est un nom de groupe dans OU=Rights
        // On essaie de le mapper via le RightsService
        $rightsService = app(\App\Services\RightsService::class);
        $bitmask = $rightsService->calculateRights([$profile]);

        if ($bitmask === 0) {
            return null;
        }

        // Trouver la permission correspondant au bit le plus significatif
        foreach ($bitmaskMapping as $bit => $permName) {
            if (($bitmask & $bit) !== 0) {
                return $permName;
            }
        }

        return null;
    }

    /**
     * Extrait le CN d'un DN LDAP (fallback si ldap_dn2cn n'est pas disponible)
     */
    private function extractCnFromDn(string $dn): string
    {
        if (preg_match('/^(?:CN|OU)=([^,]+),/i', $dn, $matches)) {
            return $matches[1];
        }
        return $dn;
    }
}

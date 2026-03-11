<?php

declare(strict_types=1);

namespace App\Services\AdSync;

use App\Config\LdapDnHelper;
use App\Config\SambaEduConfig;
use App\LdapModels\DeviceGroupTagModel;
use App\Models\AppProfile;
use Illuminate\Support\Facades\Log;
use LdapRecord\Models\ActiveDirectory\Group;

/**
 * Service de synchronisation SQL → AD pour les AppProfiles
 * 
 * Un AppProfile correspond à un groupe CN dans OU=Parcs de l'AD.
 * Ce service gère la création, le renommage et la suppression de ces groupes.
 */
class AppProfileAdSyncService
{
    public function __construct(
        private SambaEduConfig $config,
        private LdapDnHelper $dnHelper
    ) {
    }

    /**
     * Crée un AppProfile dans l'AD (groupe CN dans OU=Parcs)
     */
    public function createAppProfile(AppProfile $appProfile): array
    {
        $name = $appProfile->name;
        $description = $appProfile->description ?? "Profil applicatif: {$name}";

        Log::info('[AppProfileAdSyncService] Création AppProfile dans AD', [
            'id' => $appProfile->id,
            'name' => $name
        ]);

        try {
            // Vérifier si le groupe existe déjà
            $existingGroup = $this->findGroupCn($name);
            
            if ($existingGroup) {
                $guid = $existingGroup->getConvertedGuid();
                Log::debug('[AppProfileAdSyncService] Groupe CN existe déjà', [
                    'name' => $name,
                    'guid' => $guid
                ]);
                return [
                    'success' => true,
                    'guid' => $guid,
                    'error' => null
                ];
            }

            // Créer le groupe CN
            $result = $this->createGroupCn($name, $description);
            return $result;

        } catch (\Exception $e) {
            Log::error('[AppProfileAdSyncService] Erreur création AppProfile AD', [
                'name' => $name,
                'error' => $e->getMessage()
            ]);
            return [
                'success' => false,
                'guid' => null,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Renomme un AppProfile dans l'AD
     */
    public function renameAppProfile(string $oldName, string $newName): array
    {
        Log::info('[AppProfileAdSyncService] Renommage AppProfile dans AD', [
            'old_name' => $oldName,
            'new_name' => $newName
        ]);

        try {
            return $this->renameGroupCn($oldName, $newName);
        } catch (\Exception $e) {
            Log::error('[AppProfileAdSyncService] Erreur renommage AppProfile AD', [
                'old_name' => $oldName,
                'new_name' => $newName,
                'error' => $e->getMessage()
            ]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Supprime un AppProfile de l'AD
     */
    public function deleteAppProfile(string $name): array
    {
        Log::info('[AppProfileAdSyncService] Suppression AppProfile de AD', [
            'name' => $name
        ]);

        try {
            return $this->deleteGroupCn($name);
        } catch (\Exception $e) {
            Log::error('[AppProfileAdSyncService] Erreur suppression AppProfile AD', [
                'name' => $name,
                'error' => $e->getMessage()
            ]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    // ========================================================================
    // MÉTHODES PRIVÉES - OPÉRATIONS LDAP DE BAS NIVEAU
    // ========================================================================

    private function createGroupCn(string $name, string $description): array
    {
        $parcsDn = $this->dnHelper->parcs();
        $suffix = $this->config->establishment()->suffix ?? '';
        $samAccountName = $name . $suffix;

        $group = new Group();
        $group->setDn("CN={$name},{$parcsDn}");
        $group->cn = $name;
        $group->samaccountname = $samAccountName;
        $group->description = $description;
        $group->grouptype = -2147483646; // Domain Local Security Group

        $group->save();

        $group = Group::find($group->getDn());
        $guid = $group?->getConvertedGuid();

        Log::debug('[AppProfileAdSyncService] Groupe CN créé', [
            'name' => $name,
            'dn' => "CN={$name},{$parcsDn}",
            'guid' => $guid
        ]);

        return ['success' => true, 'guid' => $guid, 'error' => null];
    }

    private function renameGroupCn(string $oldName, string $newName): array
    {
        $group = $this->findGroupCn($oldName);
        if (!$group) {
            Log::warning('[AppProfileAdSyncService] Groupe CN non trouvé pour renommage', [
                'old_name' => $oldName
            ]);
            // Pas une erreur fatale - le groupe n'existe peut-être pas encore dans l'AD
            return ['success' => true, 'error' => null];
        }

        $suffix = $this->config->establishment()->suffix ?? '';
        $group->rename("CN={$newName}");
        $group->samaccountname = $newName . $suffix;
        $group->save();

        Log::debug('[AppProfileAdSyncService] Groupe CN renommé', [
            'old_name' => $oldName,
            'new_name' => $newName
        ]);

        return ['success' => true, 'error' => null];
    }

    private function deleteGroupCn(string $name): array
    {
        $group = $this->findGroupCn($name);
        if ($group) {
            $group->delete();
            Log::debug('[AppProfileAdSyncService] Groupe CN supprimé', ['name' => $name]);
        } else {
            Log::debug('[AppProfileAdSyncService] Groupe CN non trouvé pour suppression', ['name' => $name]);
        }
        return ['success' => true, 'error' => null];
    }

    private function findGroupCn(string $name): ?DeviceGroupTagModel
    {
        $parcsDn = $this->dnHelper->parcs();
        return DeviceGroupTagModel::in($parcsDn)
            ->where('cn', '=', $name)
            ->first();
    }
}

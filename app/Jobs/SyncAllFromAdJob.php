<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\AppProfile;
use App\Models\WorkstationGroup;
use App\Models\Workstation;
use App\LdapModels\DeviceGroupTagModel;
use App\LdapModels\DeviceGroupModel;
use App\LdapModels\MachineModel;
use App\Observers\WorkstationGroupObserver;
use App\Observers\WorkstationObserver;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Job unifié de synchronisation depuis Active Directory
 * 
 * Ce job importe en une seule opération :
 * 1. Les WorkstationGroups depuis OU=Computers (salles physiques)
 * 2. Les AppProfiles depuis OU=Parcs (profils applicatifs)
 * 3. Les liens entre AppProfiles et WorkstationGroups (même nom)
 * 4. Les Workstations (machines) depuis OU=Computers
 * 5. Les liens entre Workstations et leurs groupes/profils
 * 
 * Peut être appelé depuis :
 * - La page parc-settings (bouton "Importer depuis l'AD")
 * - La page profiles (bouton "Importer depuis l'AD")
 * - Artisan: php artisan sync:all-from-ad
 */
class SyncAllFromAdJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 300;

    private array $stats = [
        'workstation_groups' => ['created' => 0, 'updated' => 0, 'skipped' => 0],
        'app_profiles' => ['created' => 0, 'updated' => 0, 'skipped' => 0],
        'profile_group_links' => ['created' => 0, 'skipped' => 0],
        'workstations' => ['created' => 0, 'updated' => 0, 'skipped' => 0],
        'workstation_links' => ['group' => 0, 'profile' => 0],
    ];

    /**
     * Execute the job.
     */
    public function handle(): array
    {
        Log::info('[SyncAllFromAd] Démarrage de la synchronisation complète AD -> MySQL');

        try {
            // 1. Récupérer les données depuis AD (parcs et groupes seulement)
            Log::info('[SyncAllFromAd] Étape 1: Récupération des données AD');
            $parcsAd = $this->fetchParcsFromAd();
            $groupesAd = $this->fetchGroupesFromAd();

            Log::info('[SyncAllFromAd] Données AD récupérées', [
                'parcs' => count($parcsAd),
                'groupes' => count($groupesAd),
            ]);

            // Note: Ne pas déconnecter LDAP ici car on en a besoin pour les machines

            DB::beginTransaction();

            // Désactiver la sync AD -> SQL pendant l'import (on importe DEPUIS l'AD)
            WorkstationGroupObserver::disableSync();
            WorkstationObserver::disableSync();

            try {
                // 2. Importer les WorkstationGroups (OU=Computers qui ne sont pas des machines)
                Log::info('[SyncAllFromAd] Étape 2: Import des WorkstationGroups');
                $this->syncWorkstationGroups($groupesAd);

                // 3. Importer les AppProfiles (OU=Parcs)
                Log::info('[SyncAllFromAd] Étape 3: Import des AppProfiles');
                $this->syncAppProfiles($parcsAd);

                // 4. Créer les liens AppProfile <-> WorkstationGroup (même nom)
                Log::info('[SyncAllFromAd] Étape 4: Création des liens Profile-Group');
                $this->syncProfileGroupLinks();

                // 5-6. Import des Workstations désactivé temporairement
                // Le serveur manque de RAM - à investiguer
                // $machinesAd = $this->fetchMachinesFromAd();
                // $this->syncWorkstations($machinesAd);
                // $this->syncWorkstationLinks($machinesAd);

                DB::commit();

                Log::info('[SyncAllFromAd] Synchronisation terminée avec succès', $this->stats);

                return $this->stats;

            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            } finally {
                // Toujours réactiver la sync AD
                WorkstationGroupObserver::enableSync();
                WorkstationObserver::enableSync();
            }

        } catch (\Exception $e) {
            Log::error('[SyncAllFromAd] Erreur: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    // ========================================================================
    // ÉTAPE 1: RÉCUPÉRATION DES DONNÉES AD
    // ========================================================================

    /**
     * Récupère les parcs (groupes de sécurité) depuis OU=Parcs
     */
    private function fetchParcsFromAd(): array
    {
        $parcs = [];

        try {
            // Rechercher directement dans OU=Parcs pour éviter de charger tous les groupes AD
            $dnHelper = app(\App\Config\LdapDnHelper::class);
            $parcsDn = $dnHelper->parcsDn();
            $results = DeviceGroupTagModel::in($parcsDn)->get();

            foreach ($results as $parc) {
                $name = $parc->getParcName();
                if (empty($name)) {
                    continue;
                }

                $rawGuid = $parc->getFirstAttribute('objectguid');

                $parcs[] = [
                    'name' => $name,
                    'description' => $parc->getDescription(),
                    'dn' => $parc->getDn(),
                    'uuid' => $rawGuid ? $this->convertGuidToString($rawGuid) : null,
                ];
            }
        } catch (\Exception $e) {
            Log::error('[SyncAllFromAd] Erreur récupération parcs: ' . $e->getMessage());
            throw $e;
        }

        return $parcs;
    }

    /**
     * Récupère les groupes (OU) depuis OU=Computers
     */
    private function fetchGroupesFromAd(): array
    {
        $groupes = [];

        try {
            // Rechercher directement dans OU=Computers pour éviter de charger toutes les OU
            $dnHelper = app(\App\Config\LdapDnHelper::class);
            $computersDn = $dnHelper->computers();
            $results = DeviceGroupModel::in($computersDn)->get();

            foreach ($results as $groupe) {
                $name = $groupe->getGroupName();
                if (empty($name) || strtolower($name) === 'computers') {
                    continue;
                }

                $rawGuid = $groupe->getFirstAttribute('objectguid');

                $groupes[] = [
                    'name' => $name,
                    'description' => $groupe->getGroupDescription(),
                    'dn' => $groupe->getDn(),
                    'uuid' => $rawGuid ? $this->convertGuidToString($rawGuid) : null,
                ];
            }
        } catch (\Exception $e) {
            Log::error('[SyncAllFromAd] Erreur récupération groupes: ' . $e->getMessage());
            throw $e;
        }

        return $groupes;
    }

    /**
     * Récupère les machines depuis OU=Computers
     */
    private function fetchMachinesFromAd(): array
    {
        $machines = [];

        try {
            Log::info('[SyncAllFromAd] Début fetch machines - Memory: ' . round(memory_get_usage()/1024/1024, 2) . 'MB');
            
            // Utiliser une requête LDAP directe avec seulement les attributs nécessaires
            $results = MachineModel::select([
                'cn', 'objectguid', 'iphostnumber', 'networkaddress', 'operatingsystem', 'description'
            ])->get();
            
            Log::info('[SyncAllFromAd] Après fetch - ' . $results->count() . ' machines - Memory: ' . round(memory_get_usage()/1024/1024, 2) . 'MB');

            foreach ($results as $machine) {
                $name = $machine->getFirstAttribute('cn');
                if (empty($name)) {
                    continue;
                }

                $dn = $machine->getDn();
                $rawGuid = $machine->getFirstAttribute('objectguid');

                // Extraire le groupe parent depuis le DN
                $parentGroup = $this->extractParentGroupFromDn($dn);

                $machines[] = [
                    'name' => $name,
                    'dn' => $dn,
                    'uuid' => $rawGuid ? $this->convertGuidToString($rawGuid) : null,
                    'parent_group' => $parentGroup,
                    'ip' => $machine->getFirstAttribute('iphostnumber'),
                    'mac' => $machine->getFirstAttribute('networkaddress'),
                    'os' => $machine->getFirstAttribute('operatingsystem'),
                    'description' => $machine->getFirstAttribute('description'),
                ];
            }
        } catch (\Exception $e) {
            Log::warning('[SyncAllFromAd] Erreur récupération machines: ' . $e->getMessage());
        }

        return $machines;
    }

    // ========================================================================
    // ÉTAPE 2: IMPORT DES WORKSTATION GROUPS
    // ========================================================================

    /**
     * Synchronise les WorkstationGroups depuis les OU=Computers
     */
    private function syncWorkstationGroups(array $groupesAd): void
    {
        foreach ($groupesAd as $groupeAd) {
            $name = $groupeAd['name'];

            // Utiliser updateOrCreate pour éviter les violations de contrainte unique
            $group = WorkstationGroup::where('name', $name)->first();

            if ($group) {
                // Mise à jour si nécessaire
                $updated = false;

                if (empty($group->ad_guid) && !empty($groupeAd['uuid'])) {
                    $group->ad_guid = $groupeAd['uuid'];
                    $updated = true;
                }
                if (empty($group->ad_dn) && !empty($groupeAd['dn'])) {
                    $group->ad_dn = $groupeAd['dn'];
                    $updated = true;
                }

                if ($updated) {
                    $group->save();
                    $this->stats['workstation_groups']['updated']++;
                } else {
                    $this->stats['workstation_groups']['skipped']++;
                }
            } else {
                // Création
                WorkstationGroup::create([
                    'name' => $name,
                    'display_name' => $groupeAd['description'] ?? $name,
                    'description' => $groupeAd['description'],
                    'is_physical' => true,
                    'ad_dn' => $groupeAd['dn'],
                    'ad_guid' => $groupeAd['uuid'],
                    'is_active' => true,
                ]);
                $this->stats['workstation_groups']['created']++;
                Log::debug('[SyncAllFromAd] WorkstationGroup créé: ' . $name);
            }
        }
    }

    // ========================================================================
    // ÉTAPE 3: IMPORT DES APP PROFILES
    // ========================================================================

    /**
     * Synchronise les AppProfiles depuis les OU=Parcs
     */
    private function syncAppProfiles(array $parcsAd): void
    {
        $existingProfiles = AppProfile::all()->keyBy(fn($p) => strtolower($p->name));

        foreach ($parcsAd as $parcAd) {
            $name = $parcAd['name'];
            $nameLower = strtolower($name);

            if ($existingProfiles->has($nameLower)) {
                // Mise à jour si nécessaire
                $profile = $existingProfiles->get($nameLower);
                $updated = false;

                if (empty($profile->ad_guid) && !empty($parcAd['uuid'])) {
                    $profile->ad_guid = $parcAd['uuid'];
                    $updated = true;
                }

                if ($updated) {
                    $profile->save();
                    $this->stats['app_profiles']['updated']++;
                } else {
                    $this->stats['app_profiles']['skipped']++;
                }
            } else {
                // Création
                AppProfile::create([
                    'name' => $name,
                    'display_name' => $parcAd['description'] ?? $name,
                    'description' => $parcAd['description'],
                    'ad_guid' => $parcAd['uuid'],
                    'is_active' => true,
                ]);
                $this->stats['app_profiles']['created']++;
                Log::debug('[SyncAllFromAd] AppProfile créé: ' . $name);
            }
        }
    }

    // ========================================================================
    // ÉTAPE 4: LIENS PROFILE <-> GROUP
    // ========================================================================

    /**
     * Crée les liens entre AppProfiles et WorkstationGroups ayant le même nom
     */
    private function syncProfileGroupLinks(): void
    {
        $profiles = AppProfile::all();
        $groups = WorkstationGroup::all()->keyBy(fn($g) => strtolower($g->name));

        foreach ($profiles as $profile) {
            $nameLower = strtolower($profile->name);

            if ($groups->has($nameLower)) {
                $group = $groups->get($nameLower);

                // Vérifier si le lien existe déjà
                if (!$profile->workstationGroups()->where('workstation_group_id', $group->id)->exists()) {
                    $profile->workstationGroups()->attach($group->id);
                    $this->stats['profile_group_links']['created']++;
                    Log::debug('[SyncAllFromAd] Lien créé: ' . $profile->name . ' <-> ' . $group->name);
                } else {
                    $this->stats['profile_group_links']['skipped']++;
                }
            }
        }
    }

    // ========================================================================
    // ÉTAPE 5: IMPORT DES WORKSTATIONS
    // ========================================================================

    /**
     * Synchronise les Workstations depuis les machines AD
     */
    private function syncWorkstations(array $machinesAd): void
    {
        $existingWorkstations = Workstation::all()->keyBy(fn($w) => strtolower($w->name));

        foreach ($machinesAd as $machineAd) {
            $name = $machineAd['name'];
            $nameLower = strtolower($name);

            if ($existingWorkstations->has($nameLower)) {
                // Mise à jour si nécessaire
                $workstation = $existingWorkstations->get($nameLower);
                $updated = false;

                if (empty($workstation->ad_guid) && !empty($machineAd['uuid'])) {
                    $workstation->ad_guid = $machineAd['uuid'];
                    $updated = true;
                }
                if (empty($workstation->ad_dn) && !empty($machineAd['dn'])) {
                    $workstation->ad_dn = $machineAd['dn'];
                    $updated = true;
                }
                if (empty($workstation->ip) && !empty($machineAd['ip'])) {
                    $workstation->ip = $machineAd['ip'];
                    $updated = true;
                }
                if (empty($workstation->mac) && !empty($machineAd['mac'])) {
                    $workstation->mac = $machineAd['mac'];
                    $updated = true;
                }
                if (empty($workstation->os) && !empty($machineAd['os'])) {
                    $workstation->os = $machineAd['os'];
                    $updated = true;
                }

                if ($updated) {
                    $workstation->save();
                    $this->stats['workstations']['updated']++;
                } else {
                    $this->stats['workstations']['skipped']++;
                }
            } else {
                // Création
                Workstation::create([
                    'name' => $name,
                    'ad_dn' => $machineAd['dn'],
                    'ad_guid' => $machineAd['uuid'],
                    'ip' => $machineAd['ip'],
                    'mac' => $machineAd['mac'],
                    'os' => $machineAd['os'],
                    'status' => 'active',
                ]);
                $this->stats['workstations']['created']++;
                Log::debug('[SyncAllFromAd] Workstation créée: ' . $name);
            }
        }
    }

    // ========================================================================
    // ÉTAPE 6: LIENS WORKSTATIONS
    // ========================================================================

    /**
     * Relie les Workstations aux WorkstationGroups et AppProfiles
     */
    private function syncWorkstationLinks(array $machinesAd): void
    {
        $workstations = Workstation::all()->keyBy(fn($w) => strtolower($w->name));
        $groups = WorkstationGroup::all()->keyBy(fn($g) => strtolower($g->name));
        $profiles = AppProfile::all()->keyBy(fn($p) => strtolower($p->name));

        foreach ($machinesAd as $machineAd) {
            $nameLower = strtolower($machineAd['name']);
            $parentGroup = $machineAd['parent_group'] ?? null;

            if (!$workstations->has($nameLower) || empty($parentGroup)) {
                continue;
            }

            $workstation = $workstations->get($nameLower);
            $parentGroupLower = strtolower($parentGroup);

            // Lier au WorkstationGroup (salle physique)
            if ($groups->has($parentGroupLower)) {
                $group = $groups->get($parentGroupLower);

                // Assigner la salle physique
                if ($workstation->physical_room_id !== $group->id) {
                    $workstation->physical_room_id = $group->id;
                    $workstation->save();
                    $this->stats['workstation_links']['group']++;
                }
            }

            // Lier à l'AppProfile (si même nom que le groupe parent)
            if ($profiles->has($parentGroupLower)) {
                $profile = $profiles->get($parentGroupLower);

                // Vérifier si le lien existe déjà
                if (!$profile->workstations()->where('workstation_id', $workstation->id)->exists()) {
                    $profile->workstations()->attach($workstation->id);
                    $this->stats['workstation_links']['profile']++;
                }
            }
        }
    }

    // ========================================================================
    // HELPERS
    // ========================================================================

    /**
     * Extrait le nom du groupe parent depuis le DN
     * Ex: CN=PC01,OU=info1,OU=Computers,DC=... -> info1
     */
    private function extractParentGroupFromDn(string $dn): ?string
    {
        // Pattern: CN=xxx,OU=parent,OU=Computers,...
        if (preg_match('/^CN=[^,]+,OU=([^,]+),/i', $dn, $matches)) {
            $parent = $matches[1];
            // Ignorer si c'est "Computers" directement
            if (strtolower($parent) !== 'computers') {
                return $parent;
            }
        }
        return null;
    }

    /**
     * Convertit un GUID binaire AD en format string lisible
     */
    private function convertGuidToString(string $binaryGuid): string
    {
        $hex = bin2hex($binaryGuid);

        if (strlen($hex) !== 32) {
            return $hex;
        }

        return sprintf(
            '%s%s%s%s-%s%s-%s%s-%s-%s',
            substr($hex, 6, 2),
            substr($hex, 4, 2),
            substr($hex, 2, 2),
            substr($hex, 0, 2),
            substr($hex, 10, 2),
            substr($hex, 8, 2),
            substr($hex, 14, 2),
            substr($hex, 12, 2),
            substr($hex, 16, 4),
            substr($hex, 20, 12)
        );
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('[SyncAllFromAd] Job échoué définitivement: ' . $exception->getMessage());
    }
}

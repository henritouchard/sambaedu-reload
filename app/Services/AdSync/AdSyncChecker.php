<?php

declare(strict_types=1);

namespace App\Services\AdSync;

use App\Config\LdapDnHelper;
use App\LdapModels\MachineModel;
use App\Models\Workstation;
use App\Models\WorkstationGroup;
use App\Models\AppProfile;
use App\Repositories\WorkstationGroupRepository;
use App\Repositories\AppProfileRepository;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Service de vérification de la cohérence AD/SQL
 */
class AdSyncChecker
{
    public function __construct(
        private WorkstationGroupRepository $workstationGroupRepository,
        private AppProfileRepository $appProfileRepository,
        private LdapDnHelper $dnHelper
    ) {
    }

    /**
     * Vérifie la cohérence des WorkstationGroups entre AD et SQL
     */
    public function checkWorkstationGroups(): array
    {
        try {
            $sallesAd = $this->workstationGroupRepository->getAllFromAd();
            $adData = collect($sallesAd)->map(fn($s) => [
                'name' => strtolower($s['name'] ?? ''),
                'original_name' => $s['name'] ?? '',
                'dn' => $s['dn'] ?? '',
                'uuid' => $s['guid'] ?? null,
                'description' => $s['description'] ?? null,
            ])->filter(fn($s) => !empty($s['name']));
            $adNames = $adData->pluck('name')->unique();

            $sqlGroups = WorkstationGroup::where('is_active', true)->where('is_physical', true)->get();
            $sqlData = $sqlGroups->map(fn($g) => [
                'id' => $g->id,
                'name' => strtolower($g->name),
                'original_name' => $g->name,
                'is_physical' => $g->is_physical,
                'uuid' => $g->ad_guid,
            ]);
            $sqlNames = $sqlData->pluck('name')->unique();

            $missingInAdRaw = $sqlData->filter(fn($g) => !$adNames->contains($g['name']));
            $missingInSqlRaw = $adData->filter(fn($s) => !$sqlNames->contains($s['name']));

            $recommendations = collect();

            foreach ($missingInAdRaw as $sqlGroup) {
                if (!empty($sqlGroup['uuid'])) {
                    $adMatch = $adData->first(fn($ad) => $ad['uuid'] === $sqlGroup['uuid']);
                    if ($adMatch) {
                        $recommendations->push([
                            'type' => 'rename_detected',
                            'source' => 'sql',
                            'sql_name' => $sqlGroup['original_name'],
                            'sql_id' => $sqlGroup['id'],
                            'ad_name' => $adMatch['original_name'],
                            'uuid' => $sqlGroup['uuid'],
                            'suggestion' => "Le groupe '{$sqlGroup['original_name']}' en SQL semble avoir été renommé '{$adMatch['original_name']}' dans l'AD",
                            'action' => 'Mettre à jour le nom en SQL ou renommer dans l\'AD',
                        ]);
                    }
                }
            }

            foreach ($missingInSqlRaw as $adGroup) {
                if (!empty($adGroup['uuid'])) {
                    $sqlMatch = $sqlData->first(fn($sql) => $sql['uuid'] === $adGroup['uuid']);
                    if ($sqlMatch) {
                        $alreadyDetected = $recommendations->contains(fn($r) => $r['uuid'] === $adGroup['uuid']);
                        if (!$alreadyDetected) {
                            $recommendations->push([
                                'type' => 'rename_detected',
                                'source' => 'ad',
                                'sql_name' => $sqlMatch['original_name'],
                                'sql_id' => $sqlMatch['id'],
                                'ad_name' => $adGroup['original_name'],
                                'uuid' => $adGroup['uuid'],
                                'suggestion' => "Le groupe '{$adGroup['original_name']}' dans l'AD correspond à '{$sqlMatch['original_name']}' en SQL",
                                'action' => 'Mettre à jour le nom en SQL ou renommer dans l\'AD',
                            ]);
                        }
                    }
                }
            }

            $detectedUuids = $recommendations->pluck('uuid')->filter()->unique();

            $missingInAd = $missingInAdRaw->map(fn($g) => [
                'id' => $g['id'],
                'name' => $g['original_name'],
                'is_physical' => $g['is_physical'] ?? false,
                'uuid' => $g['uuid'],
                'has_recommendation' => !empty($g['uuid']) && $detectedUuids->contains($g['uuid']),
            ])->values();

            $missingInSql = $missingInSqlRaw->map(fn($s) => [
                'name' => $s['original_name'],
                'dn' => $s['dn'],
                'uuid' => $s['uuid'],
                'has_recommendation' => !empty($s['uuid']) && $detectedUuids->contains($s['uuid']),
            ])->values();

            $nameMismatches = collect();
            foreach ($sallesAd as $salleAd) {
                $adName = $salleAd['name'] ?? null;
                if (!$adName) continue;

                $sqlGroup = $sqlGroups->first(fn($g) => strtolower($g->name) === strtolower($adName));
                if ($sqlGroup && $sqlGroup->name !== $adName) {
                    $nameMismatches->push([
                        'ad_name' => $adName,
                        'sql_name' => $sqlGroup->name,
                        'id' => $sqlGroup->id,
                    ]);
                }
            }

            $synced = $missingInAd->isEmpty() && $missingInSql->isEmpty() && $nameMismatches->isEmpty() && $recommendations->isEmpty();

            return [
                'synced' => $synced,
                'ad_count' => $adNames->count(),
                'sql_count' => $sqlGroups->count(),
                'missing_in_ad' => $missingInAd,
                'missing_in_sql' => $missingInSql,
                'name_mismatches' => $nameMismatches,
                'recommendations' => $recommendations->values(),
                'last_check' => now()->toIso8601String(),
                'error' => null,
            ];

        } catch (\Exception $e) {
            Log::error('[AdSyncChecker] Erreur vérification WorkstationGroups: ' . $e->getMessage());
            return [
                'synced' => false,
                'ad_count' => 0,
                'sql_count' => 0,
                'missing_in_ad' => collect(),
                'missing_in_sql' => collect(),
                'name_mismatches' => collect(),
                'recommendations' => collect(),
                'last_check' => now()->toIso8601String(),
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Vérifie la cohérence des AppProfiles entre AD et SQL
     */
    public function checkAppProfiles(): array
    {
        try {
            $systemGroups = ['computers'];

            $profilesAd = $this->appProfileRepository->getAllFromAd();
            $adData = collect($profilesAd)->map(fn($p) => [
                'name' => strtolower($p['name'] ?? ''),
                'original_name' => $p['name'] ?? '',
                'dn' => $p['dn'] ?? '',
                'uuid' => $p['guid'] ?? null,
                'description' => $p['description'] ?? null,
            ])->filter(fn($p) => !empty($p['name']) && !in_array($p['name'], $systemGroups));
            $adNames = $adData->pluck('name')->unique();

            $sqlProfiles = AppProfile::whereNotIn('name', $systemGroups)
                ->get();
            $sqlData = $sqlProfiles->map(fn($p) => [
                'id' => $p->id,
                'name' => strtolower($p->name),
                'original_name' => $p->name,
                'description' => $p->description,
                'uuid' => $p->ad_guid,
            ]);
            $sqlNames = $sqlData->pluck('name')->unique();

            $missingInAdRaw = $sqlData->filter(fn($p) => !$adNames->contains($p['name']));
            $missingInSqlRaw = $adData->filter(fn($p) => !$sqlNames->contains($p['name']));

            $recommendations = collect();

            foreach ($missingInAdRaw as $sqlProfile) {
                if (!empty($sqlProfile['uuid'])) {
                    $adMatch = $adData->first(fn($ad) => $ad['uuid'] === $sqlProfile['uuid']);
                    if ($adMatch) {
                        $recommendations->push([
                            'type' => 'rename_detected',
                            'source' => 'sql',
                            'sql_name' => $sqlProfile['original_name'],
                            'sql_id' => $sqlProfile['id'],
                            'ad_name' => $adMatch['original_name'],
                            'uuid' => $sqlProfile['uuid'],
                            'suggestion' => "Le profil '{$sqlProfile['original_name']}' en SQL semble avoir été renommé '{$adMatch['original_name']}' dans l'AD",
                            'action' => 'Mettre à jour le nom en SQL ou renommer dans l\'AD',
                        ]);
                    }
                }
            }

            foreach ($missingInSqlRaw as $adProfile) {
                if (!empty($adProfile['uuid'])) {
                    $sqlMatch = $sqlData->first(fn($sql) => $sql['uuid'] === $adProfile['uuid']);
                    if ($sqlMatch) {
                        $alreadyDetected = $recommendations->contains(fn($r) => $r['uuid'] === $adProfile['uuid']);
                        if (!$alreadyDetected) {
                            $recommendations->push([
                                'type' => 'rename_detected',
                                'source' => 'ad',
                                'sql_name' => $sqlMatch['original_name'],
                                'sql_id' => $sqlMatch['id'],
                                'ad_name' => $adProfile['original_name'],
                                'uuid' => $adProfile['uuid'],
                                'suggestion' => "Le profil '{$adProfile['original_name']}' dans l'AD correspond à '{$sqlMatch['original_name']}' en SQL",
                                'action' => 'Mettre à jour le nom en SQL ou renommer dans l\'AD',
                            ]);
                        }
                    }
                }
            }

            $detectedUuids = $recommendations->pluck('uuid')->filter()->unique();

            $missingInAd = $missingInAdRaw->map(fn($p) => [
                'id' => $p['id'],
                'name' => $p['original_name'],
                'description' => $p['description'],
                'uuid' => $p['uuid'],
                'has_recommendation' => !empty($p['uuid']) && $detectedUuids->contains($p['uuid']),
            ])->values();

            $missingInSql = $missingInSqlRaw->map(fn($p) => [
                'name' => $p['original_name'],
                'dn' => $p['dn'],
                'uuid' => $p['uuid'],
                'has_recommendation' => !empty($p['uuid']) && $detectedUuids->contains($p['uuid']),
            ])->values();

            $synced = $missingInAd->isEmpty() && $missingInSql->isEmpty() && $recommendations->isEmpty();

            return [
                'synced' => $synced,
                'ad_count' => $adNames->count(),
                'sql_count' => $sqlProfiles->count(),
                'missing_in_ad' => $missingInAd,
                'missing_in_sql' => $missingInSql,
                'recommendations' => $recommendations->values(),
                'last_check' => now()->toIso8601String(),
                'error' => null,
            ];

        } catch (\Exception $e) {
            Log::error('[AdSyncChecker] Erreur vérification AppProfiles: ' . $e->getMessage());
            return [
                'synced' => false,
                'ad_count' => 0,
                'sql_count' => 0,
                'missing_in_ad' => collect(),
                'missing_in_sql' => collect(),
                'recommendations' => collect(),
                'last_check' => now()->toIso8601String(),
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Vérifie la cohérence des Workstations entre AD et SQL
     */
    public function checkWorkstations(): array
    {
        try {
            $computersDn = $this->dnHelper->computers();

            $machinesAd = MachineModel::in($computersDn)->get();
            $adData = collect();

            foreach ($machinesAd as $machine) {
                $name = $machine->getFirstAttribute('cn');
                if (empty($name)) continue;

                $memberOf = $machine->getFirstAttribute('memberof');
                $groups = [];
                if ($memberOf) {
                    $memberOfArray = is_array($memberOf) ? $memberOf : [$memberOf];
                    foreach ($memberOfArray as $dn) {
                        if (preg_match('/^CN=([^,]+),/i', $dn, $matches)) {
                            $groups[] = $matches[1];
                        }
                    }
                }

                $dn = $machine->getDn();
                $salle = null;
                if (preg_match('/^CN=[^,]+,OU=([^,]+),/i', $dn, $matches)) {
                    $salle = $matches[1];
                }

                $uuid = $machine->getConvertedGuid();

                $adData->push([
                    'name' => strtolower($name),
                    'original_name' => $name,
                    'dn' => $dn,
                    'salle' => $salle,
                    'groups' => $groups,
                    'ip' => $machine->getFirstAttribute('iphostnumber'),
                    'mac' => $machine->getFirstAttribute('networkaddress'),
                    'uuid' => $uuid,
                ]);
            }

            $adNames = $adData->pluck('name')->unique();

            // Eager-load : `groups` (lecture des noms ci-dessous) et
            // `physicalRooms` (l'accessor `physicalRoom` réutilise la
            // relation chargée) — sinon 2 SELECT par machine dans la boucle.
            $sqlMachines = Workstation::with(['groups', 'physicalRooms'])->get();
            $sqlData = collect();

            foreach ($sqlMachines as $machine) {
                $groups = $machine->groups->pluck('name')->toArray();

                $sqlData->push([
                    'name' => strtolower($machine->name),
                    'original_name' => $machine->name,
                    'id' => $machine->id,
                    'groups' => $groups,
                    'ip' => $machine->ip,
                    'mac' => $machine->mac,
                    // Story 4.11 — la salle vit dans le pivot global ; lecture
                    // via l'accessor `physicalRoom` (plus de FK dédiée).
                    'physical_room_id' => $machine->physicalRoom?->id,
                    'uuid' => $machine->ad_guid,
                ]);
            }

            $sqlNames = $sqlData->pluck('name')->unique();

            $missingInAdRaw = $sqlData->filter(fn($m) => !$adNames->contains($m['name']));
            $missingInSqlRaw = $adData->filter(fn($m) => !$sqlNames->contains($m['name']));

            $recommendations = collect();

            foreach ($missingInAdRaw as $sqlMachine) {
                if (!empty($sqlMachine['uuid'])) {
                    $adMatch = $adData->first(fn($ad) => $ad['uuid'] === $sqlMachine['uuid']);
                    if ($adMatch) {
                        $recommendations->push([
                            'type' => 'rename_detected',
                            'source' => 'sql',
                            'sql_name' => $sqlMachine['original_name'],
                            'sql_id' => $sqlMachine['id'],
                            'ad_name' => $adMatch['original_name'],
                            'uuid' => $sqlMachine['uuid'],
                            'suggestion' => "Le poste '{$sqlMachine['original_name']}' en SQL semble avoir été renommé '{$adMatch['original_name']}' dans l'AD",
                            'action' => 'Mettre à jour le nom en SQL ou renommer dans l\'AD',
                        ]);
                    }
                }
            }

            foreach ($missingInSqlRaw as $adMachine) {
                if (!empty($adMachine['uuid'])) {
                    $sqlMatch = $sqlData->first(fn($sql) => $sql['uuid'] === $adMachine['uuid']);
                    if ($sqlMatch) {
                        $alreadyDetected = $recommendations->contains(fn($r) => $r['uuid'] === $adMachine['uuid']);
                        if (!$alreadyDetected) {
                            $recommendations->push([
                                'type' => 'rename_detected',
                                'source' => 'ad',
                                'sql_name' => $sqlMatch['original_name'],
                                'sql_id' => $sqlMatch['id'],
                                'ad_name' => $adMachine['original_name'],
                                'uuid' => $adMachine['uuid'],
                                'suggestion' => "Le poste '{$adMachine['original_name']}' dans l'AD correspond à '{$sqlMatch['original_name']}' en SQL",
                                'action' => 'Mettre à jour le nom en SQL ou renommer dans l\'AD',
                            ]);
                        }
                    }
                }
            }

            $detectedUuids = $recommendations->pluck('uuid')->filter()->unique();

            $missingInAd = $missingInAdRaw->map(fn($m) => [
                'id' => $m['id'],
                'name' => $m['original_name'],
                'ip' => $m['ip'],
                'mac' => $m['mac'],
                'groups' => $m['groups'],
                'uuid' => $m['uuid'],
                'has_recommendation' => !empty($m['uuid']) && $detectedUuids->contains($m['uuid']),
            ])->values();

            $missingInSql = $missingInSqlRaw->map(fn($m) => [
                'name' => $m['original_name'],
                'dn' => $m['dn'],
                'salle' => $m['salle'],
                'ip' => $m['ip'],
                'mac' => $m['mac'],
                'groups' => $m['groups'],
                'uuid' => $m['uuid'],
                'has_recommendation' => !empty($m['uuid']) && $detectedUuids->contains($m['uuid']),
            ])->values();

            $synced = $missingInAd->isEmpty() && $missingInSql->isEmpty() && $recommendations->isEmpty();

            return [
                'synced' => $synced,
                'ad_count' => $adNames->count(),
                'sql_count' => $sqlNames->count(),
                'missing_in_ad' => $missingInAd,
                'missing_in_sql' => $missingInSql,
                'recommendations' => $recommendations->values(),
                'last_check' => now()->toIso8601String(),
                'error' => null,
            ];

        } catch (\Exception $e) {
            Log::error('[AdSyncChecker] Erreur vérification Workstations: ' . $e->getMessage());
            return [
                'synced' => false,
                'ad_count' => 0,
                'sql_count' => 0,
                'missing_in_ad' => collect(),
                'missing_in_sql' => collect(),
                'recommendations' => collect(),
                'last_check' => now()->toIso8601String(),
                'error' => $e->getMessage(),
            ];
        }
    }
}

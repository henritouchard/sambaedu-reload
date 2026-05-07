<?php

declare(strict_types=1);

namespace App\Services\AppProfile;

use App\Config\LdapDnHelper;
use App\LdapModels\DeviceGroupTagModel;
use App\Models\AppProfile;
use App\Models\WorkstationGroup;
use App\Observers\AppProfileObserver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Importeur AD → SQL pour les AppProfile.
 *
 * Extrait de AppProfileService dans le cadre de la story 15.4 (review #7) pour
 * isoler les dépendances LDAP du service métier 15.4 (qui doit rester Eloquent-only).
 *
 * À utiliser uniquement pour la migration initiale AD → SQL. Une fois l'import
 * effectué, SQL est la source de vérité et les modifications passent par
 * AppProfileService + observers Eloquent qui synchronisent vers l'AD.
 */
final class AppProfileAdImporter
{
    public function __construct(private readonly LdapDnHelper $dnHelper)
    {
    }

    /**
     * Import initial AD → SQL des AppProfile (parcs).
     *
     * @param  callable|null  $logCallback  fn(string $level, string $message): void
     * @return array{created:int, updated:int, skipped:int, linked_groups:int, errors:array<int,string>}
     */
    public function importFromAd(?callable $logCallback = null): array
    {
        Log::warning('AppProfileAdImporter::importFromAd() appelé - Cette méthode ne devrait être utilisée que pour l\'initialisation initiale. SQL est la source de vérité.');

        $log = $logCallback ?? fn (string $level, string $message) => Log::log($level, $message);

        $stats = [
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
            'linked_groups' => 0,
            'errors' => [],
        ];

        try {
            $parcsDn = $this->dnHelper->parcsDn();
            $log('info', "Recherche dans: {$parcsDn}");

            $parcsAd = DeviceGroupTagModel::in($parcsDn)->get();
            $log('info', count($parcsAd).' profils trouvés dans l\'AD');

            AppProfileObserver::disableSync();

            try {
                DB::beginTransaction();

                $groups = WorkstationGroup::all()->keyBy(fn ($g) => strtolower($g->name));

                foreach ($parcsAd as $parc) {
                    try {
                        $name = $parc->getParcName();
                        if (empty($name)) {
                            continue;
                        }

                        $rawGuid = $parc->getFirstAttribute('objectguid');
                        $uuid = $rawGuid ? $this->convertGuidToString($rawGuid) : null;
                        $description = $parc->getDescription();

                        $existing = AppProfile::where('name', $name)->first();

                        if ($existing) {
                            $updated = false;
                            if (empty($existing->ad_guid) && ! empty($uuid)) {
                                $existing->ad_guid = $uuid;
                                $updated = true;
                            }
                            if ($updated) {
                                $existing->save();
                                $stats['updated']++;
                                $log('info', "Mis à jour: {$name}");
                            } else {
                                $stats['skipped']++;
                            }

                            if ($groups->has(strtolower($name))) {
                                $group = $groups->get(strtolower($name));
                                if (! $existing->workstationGroups()->where('workstation_group_id', $group->id)->exists()) {
                                    $existing->workstationGroups()->attach($group->id);
                                    $stats['linked_groups']++;
                                }
                            }
                        } else {
                            $profile = AppProfile::create([
                                'name' => $name,
                                'display_name' => $description ?? $name,
                                'description' => $description,
                                'ad_guid' => $uuid,
                                'is_active' => true,
                            ]);

                            if ($groups->has(strtolower($name))) {
                                $group = $groups->get(strtolower($name));
                                $profile->workstationGroups()->attach($group->id);
                                $stats['linked_groups']++;
                            }

                            $stats['created']++;
                            $log('success', "Créé: {$name}");
                        }
                    } catch (\Exception $e) {
                        $parcName = $parc->getParcName() ?? 'inconnu';
                        $stats['errors'][] = "Erreur pour {$parcName}: ".$e->getMessage();
                        $log('error', "Erreur pour {$parcName}: ".$e->getMessage());
                    }
                }

                DB::commit();
            } finally {
                AppProfileObserver::enableSync();
            }

            $log('info', "Résultat: {$stats['created']} créés, {$stats['updated']} mis à jour, {$stats['skipped']} ignorés, {$stats['linked_groups']} liés");
        } catch (\Exception $e) {
            DB::rollBack();
            $stats['errors'][] = 'Erreur globale: '.$e->getMessage();
            $log('error', 'Erreur lors de l\'import: '.$e->getMessage());
            Log::error('AppProfileAdImporter::importFromAd erreur', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }

        return $stats;
    }

    private function convertGuidToString(string $binaryGuid): string
    {
        $hex = bin2hex($binaryGuid);
        if (strlen($hex) !== 32) {
            return $hex;
        }

        return sprintf(
            '%s%s%s%s-%s%s-%s%s-%s-%s',
            substr($hex, 6, 2), substr($hex, 4, 2), substr($hex, 2, 2), substr($hex, 0, 2),
            substr($hex, 10, 2), substr($hex, 8, 2),
            substr($hex, 14, 2), substr($hex, 12, 2),
            substr($hex, 16, 4),
            substr($hex, 20, 12)
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Services\AppProfile;

use App\Config\LdapDnHelper;
use App\Facades\SEConfig;
use App\LdapModels\DeviceGroupTagModel;
use App\Models\AppProfile;
use App\Models\Application;
use App\Models\WorkstationGroup;
use App\Observers\AppProfileObserver;
use App\Services\Ldap\EstablishmentWorkstationScope;
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
    /**
     * Parc réservé SE4 (nom codé en dur — jamais configurable). Story 38.7 /
     * AC10 : ce n'est pas un parc mais le socle « tous les postes ». Il ne
     * produit PAS d'AppProfile ; ses applications sont promues en défaut
     * d'établissement (`applications.is_parc_default = true`).
     */
    private const ALL_WORKSTATIONS_PARC = '_TousLesPostes';

    /**
     * Seam de test (HÔTE, sans AD) : entrées `OU=Parcs`. Chaque objet doit
     * exposer `getParcName()`, `getDescription()`, `getFirstAttribute()`,
     * `getAttribute()`, `getDn()`. Null = requête LDAP réelle.
     *
     * @var iterable<object>|null
     */
    public static ?iterable $parcsEntriesSeam = null;

    public function __construct(
        private readonly LdapDnHelper $dnHelper,
        private readonly EstablishmentWorkstationScope $scope,
        private readonly LegacyParcApplicationReader $legacyReader = new LegacyParcApplicationReader(),
    ) {
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

        // 'success' est un niveau d'affichage (callback Livewire), pas un niveau
        // PSR-3 : le ramener à 'info' pour le logger de repli, sinon Log::log()
        // lève et fait rollback tout l'import.
        $log = $logCallback ?? fn (string $level, string $message) => Log::log($level === 'success' ? 'info' : $level, $message);

        $stats = [
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
            'skipped_no_apps' => 0,
            'linked_groups' => 0,
            'etab_excluded' => 0,
            'parc_defaults_promoted' => 0,
            'parc_defaults_missing' => 0,
            'legacy_unavailable' => false,
            'errors' => [],
        ];

        try {
            // AC9.1/9.2 + AC10 — un seul point de lecture legacy, partagé avec le
            // linker (étape 7) et l'import logique (étape 5). Source indisponible
            // ⇒ on NE crée AUCUN profil (créer « au cas où » est précisément la
            // pollution qu'on supprime) et on le signale bruyamment : l'import est
            // idempotent (`syncWithoutDetaching`), un rejeu une fois la connexion
            // rétablie produira le bon résultat.
            $legacy = $this->legacyReader->read($log);
            if ($legacy === null) {
                $stats['legacy_unavailable'] = true;
                $log('warning', 'Source d\'assignation legacy indisponible — étape 7 incomplète (aucun profil créé, aucune app promue), à rejouer.');

                return $stats;
            }

            [$parcAppNames, $parcByName] = $legacy;

            $parcsDn = $this->dnHelper->parcsDn();
            $log('info', "Recherche dans: {$parcsDn}");

            $establishmentCode = SEConfig::getCurrentEstablishmentCode();
            $establishmentDn = null;
            $scopedWorkstationDns = null;
            if (! empty($establishmentCode) && $establishmentCode !== '0') {
                $establishmentDn = SEConfig::ldap()->etablissementDn($establishmentCode);
                $scopedWorkstationDns = array_flip($this->scope->workstationDns($establishmentDn));
                $log('info', sprintf(
                    'Filtre établissement actif: %s (%d postes scopés)',
                    $establishmentCode,
                    count($scopedWorkstationDns)
                ));
            } else {
                $log('info', 'Aucun établissement sélectionné — import en mode domaine entier');
            }

            $parcsAd = static::$parcsEntriesSeam ?? DeviceGroupTagModel::in($parcsDn)->get();
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

                        // AC10 — `_TousLesPostes` n'est pas un parc : ni AppProfile,
                        // ni ligne dans la liste des parcs. Ses applications sont
                        // promues en défaut d'établissement APRÈS la boucle.
                        if ($name === self::ALL_WORKSTATIONS_PARC) {
                            $log('info', "Parc réservé « {$name} » : ignoré (socle → défaut d'établissement, pas de profil).");
                            continue;
                        }

                        if ($scopedWorkstationDns !== null && ! $this->parcHasScopedMember($parc, $scopedWorkstationDns)) {
                            $stats['etab_excluded']++;
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
                            // AC9.1 — ne réifier un AppProfile QUE si le parc legacy
                            // homonyme porte au moins une application (appariement
                            // sur la même clé que le linker, cf. LegacyParcApplicationReader).
                            // Un parc de rangement sans application produisait jusqu'ici
                            // un profil fantôme : c'est la pollution qu'on supprime.
                            if (! $this->legacyReader->parcHasApplications($parcAppNames, $parcByName, $name)) {
                                $stats['skipped_no_apps']++;
                                $log('info', "Profil « {$name} » non créé : parc legacy sans application assignée.");
                                continue;
                            }

                            $profile = AppProfile::create([
                                'name' => $name,
                                'description' => $description,
                                'ad_guid' => $uuid,
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

                // AC10 — promotion des applications de `_TousLesPostes` en défaut
                // d'établissement (couche Broadcast). Deux moitiés d'une même règle
                // avec l'exclusion du parc ci-dessus.
                $this->promoteAllWorkstationsDefaults($parcAppNames, $parcByName, $stats, $log);

                DB::commit();
            } finally {
                AppProfileObserver::enableSync();
            }

            $log('info', "Résultat: {$stats['created']} créés, {$stats['updated']} mis à jour, {$stats['skipped']} ignorés, {$stats['skipped_no_apps']} sans app, {$stats['linked_groups']} liés, {$stats['parc_defaults_promoted']} défaut(s) parc");
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

    /**
     * AC10 — promeut les applications du parc réservé `_TousLesPostes` en défaut
     * d'établissement (`applications.is_parc_default = true`, couche Broadcast).
     *
     * Idempotent : ne marque que ce qui ne l'est pas encore, ne DÉ-marque JAMAIS
     * (un défaut posé à la main dans /admin/settings/parc-defaults survit au rejeu).
     * Une app assignée à `_TousLesPostes` mais absente du catalogue SE5 produit un
     * warning nominatif (même traitement que `applications_missing` du linker).
     * `_TousLesPostes` absent du legacy ⇒ liste vide ⇒ no-op silencieux.
     *
     * @param  array<int, list<string>>  $parcAppNames
     * @param  array<string, int>  $parcByName
     * @param  array<string, mixed>  $stats
     */
    private function promoteAllWorkstationsDefaults(array $parcAppNames, array $parcByName, array &$stats, callable $log): void
    {
        $appIds = $this->legacyReader->applicationsForParc($parcAppNames, $parcByName, self::ALL_WORKSTATIONS_PARC);
        if ($appIds === []) {
            return;
        }

        $promoted = [];
        foreach ($appIds as $idNomApp) {
            $application = Application::query()->where('app_id', $idNomApp)->first();
            if ($application === null) {
                $stats['parc_defaults_missing']++;
                $log('warning', "Application « {$idNomApp} » de `_TousLesPostes` absente du catalogue SE5 — non promue en défaut parc (étape « Importer les applications WPKG » incomplète ?).");
                continue;
            }

            if (! $application->is_parc_default) {
                $application->is_parc_default = true;
                $application->save();
            }

            $promoted[$application->id] = $application->name ?? (string) $idNomApp;
            $stats['parc_defaults_promoted']++;
        }

        if ($promoted !== []) {
            $log('success', sprintf(
                '%d application(s) de `_TousLesPostes` promue(s) en défaut parc : %s.',
                count($promoted),
                implode(', ', $promoted),
            ));
        }
    }

    /**
     * @param  array<string,int>  $scopedWorkstationDns
     */
    private function parcHasScopedMember(DeviceGroupTagModel $parc, array $scopedWorkstationDns): bool
    {
        $members = $parc->getAttribute('member') ?? [];
        if (is_array($members) && isset($members['count'])) {
            unset($members['count']);
            $members = array_values($members);
        }
        if (! is_array($members)) {
            $members = [$members];
        }

        foreach ($members as $memberDn) {
            if (! is_string($memberDn) || $memberDn === '') {
                continue;
            }
            if (isset($scopedWorkstationDns[strtolower(trim($memberDn))])) {
                return true;
            }
        }

        return false;
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

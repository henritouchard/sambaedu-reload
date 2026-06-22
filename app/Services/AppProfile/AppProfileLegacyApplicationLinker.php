<?php

declare(strict_types=1);

namespace App\Services\AppProfile;

use App\Models\AppProfile;
use App\Models\Application;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Peuple le pivot `app_profile_application` à partir des assignations parc du
 * legacy SE4 (table MySQL `applications_profile`, `type_entite = 'parc'`).
 *
 * Appelé pendant l'étape « Importer les profils applicatifs » de
 * /admin/sync-from-ad, APRÈS {@see AppProfileAdImporter::importFromAd} (qui crée
 * les coquilles {@see AppProfile} depuis l'AD) et APRÈS
 * {@see \App\Services\AppStore\LegacyWpkgImporter} (qui importe les
 * {@see Application}). Sans ces deux préalables, le linker ne trouve rien à relier.
 *
 * Mapping :
 *   AppProfile.name  ==(insensible casse)==  legacy `parc.nom_parc`
 *   legacy `applications_profile`(type='parc', id_entite=parc) → id_appli
 *   legacy `applications`.id_app → id_nom_app
 *   id_nom_app  ==  SE5 `applications`.app_id
 *
 * Non-destructif (`syncWithoutDetaching`) : ne supprime jamais un lien
 * existant — les assignations posées manuellement côté SE5 sont préservées.
 * Idempotent : un rejeu n'attache que les liens encore absents.
 *
 * Source legacy = connexion `legacy_mysql` (Story 5.1d). Si elle n'est pas
 * configurée / injoignable, le linker ne fait rien (flag `legacy_unavailable`)
 * et n'interrompt PAS l'import des profils.
 */
final class AppProfileLegacyApplicationLinker
{
    /**
     * @param  callable|null  $logCallback  fn(string $level, string $message): void
     * @return array{
     *   profiles_processed:int, profiles_linked:int, applications_linked:int,
     *   profiles_without_legacy_parc:int, applications_missing:int,
     *   legacy_unavailable:bool, errors:array<int,string>
     * }
     */
    public function linkFromLegacy(?callable $logCallback = null): array
    {
        // Fallback sans UI : 'success' est un niveau d'affichage (callback
        // Livewire), pas un niveau PSR-3 → on le ramène à 'info' pour le logger.
        $log = $logCallback ?? function (string $level, string $message): void {
            Log::log($level === 'success' ? 'info' : $level, $message);
        };

        $stats = [
            'profiles_processed' => 0,
            'profiles_linked' => 0,
            'applications_linked' => 0,
            'profiles_without_legacy_parc' => 0,
            'applications_missing' => 0,
            'legacy_unavailable' => false,
            'errors' => [],
        ];

        if (! $this->canConnectLegacy()) {
            $stats['legacy_unavailable'] = true;
            $log('warning', 'Connexion legacy_mysql non configurée/injoignable — liaison des applications aux profils ignorée.');

            return $stats;
        }

        $legacy = $this->readLegacyAssignments($log);
        if ($legacy === null) {
            $stats['legacy_unavailable'] = true;

            return $stats;
        }

        [$parcAppNames, $parcByName] = $legacy;

        // Catalogue SE5 : app_id (= id_nom_app legacy) → id Application.
        $appIdToId = Application::query()->pluck('id', 'app_id');

        $profiles = AppProfile::query()->whereNull('archived_at')->get();
        $log('info', $profiles->count() . ' profil(s) applicatif(s) à relier.');

        foreach ($profiles as $profile) {
            $stats['profiles_processed']++;

            $parcId = $parcByName[mb_strtolower($profile->name)] ?? null;
            if ($parcId === null || ! isset($parcAppNames[$parcId])) {
                $stats['profiles_without_legacy_parc']++;
                // F8 : rendre tout désalignement de nom (casse/accent/slug)
                // diagnosticable plutôt que silencieux.
                $log('info', "Profil « {$profile->name} » : aucun parc legacy correspondant (ou parc sans application) — non relié.");
                continue;
            }

            $applicationIds = [];
            foreach ($parcAppNames[$parcId] as $idNomApp) {
                if (isset($appIdToId[$idNomApp])) {
                    $applicationIds[] = $appIdToId[$idNomApp];
                } else {
                    $stats['applications_missing']++;
                    $log('warning', "Application legacy « {$idNomApp} » assignée au parc « {$profile->name} » absente du catalogue SE5 (étape « Importer les applications WPKG » incomplète ?).");
                }
            }

            if ($applicationIds === []) {
                continue;
            }

            $result = $profile->applications()->syncWithoutDetaching($applicationIds);
            $attached = count($result['attached'] ?? []);
            if ($attached > 0) {
                $stats['applications_linked'] += $attached;
                $stats['profiles_linked']++;
                $log('success', "Profil « {$profile->name} » : {$attached} application(s) reliée(s).");
            }
        }

        $log('info', sprintf(
            'Bilan liaisons : %d profil(s) relié(s), %d lien(s) appli créé(s), %d profil(s) sans parc legacy, %d appli(s) manquante(s).',
            $stats['profiles_linked'],
            $stats['applications_linked'],
            $stats['profiles_without_legacy_parc'],
            $stats['applications_missing'],
        ));

        return $stats;
    }

    /**
     * Garde-fou de connexion (parité {@see \App\Console\Commands\QuotaSeedFromLegacyCommand}).
     */
    private function canConnectLegacy(): bool
    {
        $driver = (string) config('database.connections.legacy_mysql.driver');
        $database = (string) config('database.connections.legacy_mysql.database');
        $username = (string) config('database.connections.legacy_mysql.username');

        if ($driver === 'mysql' && ($database === '' || $username === '')) {
            return false;
        }

        try {
            DB::connection('legacy_mysql')->getPdo();

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Lit les assignations parc → applications du legacy.
     *
     * @return array{0: array<int, list<string>>, 1: array<string, int>}|null
     *   [parcId => [id_nom_app, …], lower(nom_parc) => parcId] ; null si lecture échouée.
     */
    private function readLegacyAssignments(callable $log): ?array
    {
        try {
            // id_app → id_nom_app (clé métier stable, = app_id côté SE5).
            // F2 : on ne retient que les applis ACTIVES — le catalogue SE5 (issu
            // du packages.xml legacy) ne contient que les actives, donc une appli
            // inactive encore assignée à un parc ne doit PAS être comptée comme
            // « manquante » (faux positif anxiogène). Ses assignations sont
            // simplement ignorées (id_appli non résolu plus bas).
            $appNameById = DB::connection('legacy_mysql')
                ->table('applications')
                ->where('active_app', 1)
                ->pluck('id_nom_app', 'id_app');

            // lower(nom_parc) → id_parc.
            $parcByName = [];
            DB::connection('legacy_mysql')
                ->table('parc')
                ->select(['id_parc', 'nom_parc'])
                ->orderBy('id_parc')
                ->each(function ($row) use (&$parcByName): void {
                    $parcByName[mb_strtolower((string) $row->nom_parc)] = (int) $row->id_parc;
                });

            // id_parc → [id_nom_app, …] depuis applications_profile (type 'parc').
            $parcAppNames = [];
            DB::connection('legacy_mysql')
                ->table('applications_profile')
                ->where('type_entite', 'parc')
                ->select(['id_entite', 'id_appli'])
                ->orderBy('id_applications_profile')
                ->each(function ($row) use (&$parcAppNames, $appNameById): void {
                    $idNomApp = $appNameById[$row->id_appli] ?? null;
                    if ($idNomApp === null) {
                        return;
                    }
                    $parcAppNames[(int) $row->id_entite][] = (string) $idNomApp;
                });

            return [$parcAppNames, $parcByName];
        } catch (\Throwable $e) {
            $log('error', 'Lecture des assignations legacy (applications_profile/parc) échouée : ' . $e->getMessage());
            Log::error('AppProfileLegacyApplicationLinker: lecture legacy échouée', ['error' => $e->getMessage()]);

            return null;
        }
    }
}

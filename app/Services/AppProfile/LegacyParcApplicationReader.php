<?php

declare(strict_types=1);

namespace App\Services\AppProfile;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Lecteur mutualisé des assignations parc → applications du legacy SE4
 * (table MySQL `applications_profile`, `type_entite = 'parc'`).
 *
 * Story 38.7 — extrait de {@see AppProfileLegacyApplicationLinker} pour être
 * partagé par les trois consommateurs de l'import de migration qui ont besoin
 * de savoir « ce parc legacy porte-t-il des applications ? » :
 *   - {@see AppProfileLegacyApplicationLinker} (étape 7, liaison appli ↔ profil) ;
 *   - {@see AppProfileAdImporter} (étape 7, ne réifier un AppProfile QUE si le
 *     parc porte au moins une application — AC9.1 — et promotion des apps de
 *     `_TousLesPostes` en défaut d'établissement — AC10) ;
 *   - {@see \App\Services\Parc\WorkstationGroupService::importLogicalGroupsFromAd()}
 *     (étape 5, ne créer un groupe logique QUE si le parc porte au moins une
 *     application — AC9.3).
 *
 * Un seul point de lecture ⇒ les trois apparient sur exactement la même clé
 * (`mb_strtolower(nom_parc)`), sans risque de désalignement de casse.
 *
 * F2 : seules les applications ACTIVES (`active_app = 1`) sont retenues — le
 * catalogue SE5 ne contient que les actives, une appli inactive encore
 * assignée à un parc ne doit pas être comptée comme « manquante ».
 */
final class LegacyParcApplicationReader
{
    /**
     * Garde-fou de connexion (parité {@see \App\Console\Commands\QuotaSeedFromLegacyCommand}).
     */
    public function isConfiguredAndReachable(): bool
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
     * @param  callable|null  $log  fn(string $level, string $message): void
     * @return array{0: array<int, list<string>>, 1: array<string, int>}|null
     *   [parcId => [id_nom_app, …], lower(nom_parc) => parcId] ; null si la
     *   source est indisponible OU si la lecture a échoué (dans les deux cas,
     *   l'appelant doit traiter cela comme « legacy indisponible » — pas de
     *   repli silencieux).
     */
    public function read(?callable $log = null): ?array
    {
        $log ??= static fn (string $level, string $message) => Log::log($level === 'success' ? 'info' : $level, $message);

        if (! $this->isConfiguredAndReachable()) {
            $log('warning', 'Connexion legacy_mysql non configurée/injoignable — assignations parc → applications illisibles.');

            return null;
        }

        try {
            // id_app → id_nom_app (clé métier stable, = app_id côté SE5).
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
            Log::error('LegacyParcApplicationReader: lecture legacy échouée', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Le parc legacy homonyme porte-t-il au moins une application (active) ?
     *
     * @param  array<int, list<string>>  $parcAppNames
     * @param  array<string, int>  $parcByName
     */
    public function parcHasApplications(array $parcAppNames, array $parcByName, string $name): bool
    {
        $parcId = $parcByName[mb_strtolower($name)] ?? null;

        return $parcId !== null && ! empty($parcAppNames[$parcId]);
    }

    /**
     * Liste les id_nom_app (= app_id SE5) assignés à un parc legacy homonyme.
     *
     * @param  array<int, list<string>>  $parcAppNames
     * @param  array<string, int>  $parcByName
     * @return list<string>
     */
    public function applicationsForParc(array $parcAppNames, array $parcByName, string $name): array
    {
        $parcId = $parcByName[mb_strtolower($name)] ?? null;

        return $parcId !== null ? ($parcAppNames[$parcId] ?? []) : [];
    }
}

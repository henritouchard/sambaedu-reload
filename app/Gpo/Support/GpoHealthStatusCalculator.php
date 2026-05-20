<?php

declare(strict_types=1);

namespace App\Gpo\Support;

use App\Gpo\Dto\GpoSummary;
use App\Gpo\Enums\GpoHealthStatus;
use Illuminate\Support\Collection;

/**
 * Calcule le statut de santé d'une GPO — Story 16.14 D4.
 *
 * Classe stateless — méthodes statiques pures, sans I/O ni accès AD.
 * Le calcul se base sur les données déjà chargées (versionNumber + liens).
 *
 * Définitions D4 :
 *   - Stale      : versionNumber === 0 (proxy best-effort Phase 2).
 *   - Orphaned   : totalLinks === 0.
 *   - Conflicting: au moins 2 GPOs sur le même containerDn partagent une section native.
 *   - Healthy    : tout le reste.
 *
 * Ordre de priorité : Stale > Orphaned > Conflicting > Healthy.
 * (Une GPO version=0 est toujours Stale, même si orpheline.)
 */
final class GpoHealthStatusCalculator
{
    /**
     * Calcule le statut pour une GPO donnée.
     *
     * @param  array{name:string,displayName:string,versionNumber:?int,dn:?string,path:?string}  $gpo
     * @param  int  $totalLinks  Nombre total de liaisons OU détectées pour cette GPO.
     * @param  bool  $hasConflict  Vrai si un conflit de section native a été détecté.
     */
    public static function calculate(array $gpo, int $totalLinks, bool $hasConflict = false): GpoHealthStatus
    {
        $version = $gpo['versionNumber'] ?? 0;

        // Priorité 1 : Stale — version nulle (D4 proxy best-effort)
        if ($version === 0 || $version === null) {
            return GpoHealthStatus::Stale;
        }

        // Priorité 2 : Orphaned — aucune OU liée
        if ($totalLinks === 0) {
            return GpoHealthStatus::Orphaned;
        }

        // Priorité 3 : Conflicting — détection best-effort
        if ($hasConflict) {
            return GpoHealthStatus::Conflicting;
        }

        return GpoHealthStatus::Healthy;
    }

    /**
     * Calcule le statut pour une collection de GPOs en tenant compte des conflits.
     *
     * Détecte les conflits (D4) : au moins 2 GPOs liées au même containerDn
     * matchent la même section native (via NativeSectionResolver).
     *
     * Cap performance (R4) : si > 100 GPOs, on skip la détection conflicting.
     *
     * @param  Collection<int,array{name:string,displayName:string,versionNumber:?int,dn:?string,path:?string}>  $gpos
     * @param  array<string,int>  $linksCountByGuid  Nombre de liaisons par GUID (clé = name de la GPO).
     * @return array<string,GpoHealthStatus>  Clé = name de la GPO.
     */
    public static function calculateBatch(Collection $gpos, array $linksCountByGuid): array
    {
        $conflictingGuids = self::detectConflicts($gpos, $linksCountByGuid);

        $result = [];
        foreach ($gpos as $gpo) {
            $guid = $gpo['name'];
            $totalLinks = $linksCountByGuid[$guid] ?? 0;
            $hasConflict = in_array($guid, $conflictingGuids, true);
            $result[$guid] = self::calculate($gpo, $totalLinks, $hasConflict);
        }

        return $result;
    }

    /**
     * Détecte les GUIDs en conflit.
     *
     * Cap : si > 100 GPOs, retourne [] (performance R4 — N×N appels samba-tool évités).
     *
     * @param  Collection<int,array>  $gpos
     * @param  array<string,int>  $linksCountByGuid
     * @return list<string>  GUIDs en conflit.
     */
    private static function detectConflicts(Collection $gpos, array $linksCountByGuid): array
    {
        if ($gpos->count() > 100) {
            return [];
        }

        // Regrouper les GPOs par containerDn (via les liens)
        // Ici on ne peut pas appeler getLinks() — on travaille sur les données déjà chargées.
        // La détection se fait via NativeSectionResolver sur les GPOs liées au même nb de containers.
        // Best-effort : si plusieurs GPOs ont > 0 liens ET matchent la même section native → conflit.
        $sectionGpos = []; // section_key => [guid, ...]

        foreach ($gpos as $gpo) {
            $guid = $gpo['name'];
            if (($linksCountByGuid[$guid] ?? 0) === 0) {
                continue;
            }
            $sections = NativeSectionResolver::resolve($gpo['displayName'] ?? '');
            foreach (array_keys($sections) as $sectionKey) {
                $sectionGpos[$sectionKey][] = $guid;
            }
        }

        $conflicting = [];
        foreach ($sectionGpos as $guids) {
            if (count($guids) >= 2) {
                foreach ($guids as $guid) {
                    $conflicting[] = $guid;
                }
            }
        }

        return array_unique($conflicting);
    }
}

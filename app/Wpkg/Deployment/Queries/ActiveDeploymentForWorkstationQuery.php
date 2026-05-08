<?php

declare(strict_types=1);

namespace App\Wpkg\Deployment\Queries;

use App\Models\Workstation;
use App\Wpkg\Deployment\Models\WpkgDeployment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Story 15.5 / AC1.5 — Cherche le déploiement WPKG actif (status pending|running)
 * applicable à une workstation donnée, sur les 3 axes du `target_scope` :
 *
 *   - `workstation_ids` : corrélation directe.
 *   - `group_ids` (ou `workstation_group_ids` legacy 15.4) : un parc dont
 *     le poste est membre.
 *   - `profile_ids` : un AppProfile auquel le poste est rattaché (héritage
 *     groupe ou direct).
 *
 * Si plusieurs déploiements actifs matchent → retourne le plus récent
 * (`triggered_at DESC`) et logge un warning `wpkg_deployment_correlation_ambiguous`.
 *
 * Pas de match → retourne null (le rapport est ingéré « spontané » dans
 * `workstation_application_status` uniquement).
 *
 * Note compatibilité : le code 15.4 actuel insère `target_scope` avec la
 * clé `workstation_group_ids` (sans `s` initial), alors que la story 15.5
 * spécifie `group_ids`. La query gère les deux pour rester robuste pendant
 * la transition (15.7 unifiera).
 */
final class ActiveDeploymentForWorkstationQuery
{
    private const ACTIVE_STATUSES = [
        WpkgDeployment::STATUS_PENDING,
        WpkgDeployment::STATUS_RUNNING,
    ];

    public function find(int $workstationId): ?WpkgDeployment
    {
        $workstation = Workstation::query()
            ->whereKey($workstationId)
            ->with(['groups:id', 'appProfiles:id', 'groups.appProfiles:id'])
            ->first();

        if ($workstation === null) {
            return null;
        }

        $groupIds = $workstation->groups->pluck('id')->map('intval')->all();

        $profileIds = $workstation->appProfiles->pluck('id')->all();
        foreach ($workstation->groups as $group) {
            foreach ($group->appProfiles as $profile) {
                $profileIds[] = $profile->id;
            }
        }
        $profileIds = array_values(array_unique(array_map('intval', $profileIds)));

        $candidates = WpkgDeployment::query()
            ->whereIn('status', self::ACTIVE_STATUSES)
            ->orderByDesc('triggered_at')
            ->get()
            ->filter(function (WpkgDeployment $deployment) use ($workstationId, $groupIds, $profileIds): bool {
                return $this->scopeMatches(
                    $deployment->target_scope,
                    $workstationId,
                    $groupIds,
                    $profileIds,
                );
            })
            ->values();

        if ($candidates->isEmpty()) {
            return null;
        }

        if ($candidates->count() > 1) {
            Log::channel('wpkg-deploy')->warning(
                '[ActiveDeploymentForWorkstationQuery] corrélation ambiguë',
                [
                    'event' => 'wpkg_deployment_correlation_ambiguous',
                    'workstation_id' => $workstationId,
                    'matched_count' => $candidates->count(),
                    'selected_deployment_id' => $candidates->first()->id,
                    'all_deployment_ids' => $candidates->pluck('id')->all(),
                ],
            );
        }

        return $candidates->first();
    }

    /**
     * @param  array<string,mixed>|null  $scope
     * @param  list<int>  $groupIds
     * @param  list<int>  $profileIds
     */
    private function scopeMatches(?array $scope, int $workstationId, array $groupIds, array $profileIds): bool
    {
        if ($scope === null) {
            return false;
        }

        // Axe 1 : workstation_ids
        $wIds = $this->normalizeIds($scope['workstation_ids'] ?? []);
        if (in_array($workstationId, $wIds, true)) {
            return true;
        }

        // Axe 2 : group_ids OU workstation_group_ids (compat 15.4)
        $gIdsFromScope = $this->normalizeIds(
            $scope['group_ids'] ?? $scope['workstation_group_ids'] ?? []
        );
        if (! empty(array_intersect($gIdsFromScope, $groupIds))) {
            return true;
        }

        // Axe 3 : profile_ids
        $pIdsFromScope = $this->normalizeIds(
            $scope['profile_ids'] ?? $scope['app_profile_ids'] ?? []
        );
        if (! empty(array_intersect($pIdsFromScope, $profileIds))) {
            return true;
        }

        return false;
    }

    /**
     * @param  mixed  $value
     * @return list<int>
     */
    private function normalizeIds(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $v) {
            if (is_int($v) || ctype_digit((string) $v)) {
                $out[] = (int) $v;
            }
        }

        return $out;
    }
}

<?php

declare(strict_types=1);

namespace App\Wpkg\Deployment\Listeners;

use App\Models\AppProfile;
use App\Models\Workstation;
use App\Models\WorkstationGroup;
use App\Wpkg\Deployment\Events\AppProfileApplicationChanged;
use App\Wpkg\Deployment\Events\AppProfileWorkstationChanged;
use App\Wpkg\Deployment\Events\AppProfileWorkstationGroupChanged;
use App\Wpkg\Deployment\Events\WorkstationActivated;
use App\Wpkg\Deployment\Events\WorkstationArchived;
use App\Wpkg\Deployment\Events\WorkstationGroupMembershipChanged;
use App\Wpkg\Deployment\Services\WorkstationPackagesResolver;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Story 15.2 / AC4.2 — Listener générique d'invalidation du cache packages.
 *
 * Routing par `instanceof` du payload event. Toujours **ciblé par hostname**
 * (jamais de flush global — décision conception 15.2).
 */
final class InvalidateWorkstationPackagesCache
{
    public function handle(object $event): void
    {
        $hostnames = match (true) {
            $event instanceof WorkstationGroupMembershipChanged
                => $this->hostnamesForWorkstation($event->workstationId),
            $event instanceof WorkstationActivated
                => $this->hostnamesForWorkstation($event->workstationId),
            $event instanceof WorkstationArchived
                => $this->hostnamesForWorkstation($event->workstationId),
            $event instanceof AppProfileWorkstationChanged
                => $this->hostnamesForWorkstation($event->workstationId),
            $event instanceof AppProfileWorkstationGroupChanged
                => $this->hostnamesForWorkstationGroup($event->workstationGroupId),
            $event instanceof AppProfileApplicationChanged
                => $this->hostnamesForAppProfile($event->appProfileId),
            default => [],
        };

        if ($hostnames === []) {
            return;
        }

        foreach ($hostnames as $hostname) {
            Cache::forget(WorkstationPackagesResolver::cacheKey($hostname));
        }

        Log::channel('wpkg-deploy')->info('[InvalidateWorkstationPackagesCache] cache invalidé', [
            'event' => $event::class,
            'count' => count($hostnames),
        ]);
    }

    /**
     * @return list<string>
     */
    private function hostnamesForWorkstation(int $workstationId): array
    {
        $name = Workstation::query()->whereKey($workstationId)->value('name');

        return is_string($name) && $name !== '' ? [$name] : [];
    }

    /**
     * @return list<string>
     */
    private function hostnamesForWorkstationGroup(int $workstationGroupId): array
    {
        $group = WorkstationGroup::query()
            ->whereKey($workstationGroupId)
            ->with('workstations:id,name')
            ->first();

        if ($group === null) {
            return [];
        }

        return $group->workstations
            ->pluck('name')
            ->filter(fn ($v): bool => is_string($v) && $v !== '')
            ->values()
            ->all();
    }

    /**
     * Postes impactés par un AppProfile : union des postes directs + postes des
     * parcs liés au profile.
     *
     * @return list<string>
     */
    private function hostnamesForAppProfile(int $appProfileId): array
    {
        $profile = AppProfile::query()
            ->whereKey($appProfileId)
            ->with([
                'workstations:id,name',
                'workstationGroups.workstations:id,name',
            ])
            ->first();

        if ($profile === null) {
            return [];
        }

        $names = $profile->workstations->pluck('name');

        foreach ($profile->workstationGroups as $group) {
            $names = $names->concat($group->workstations->pluck('name'));
        }

        return $names
            ->filter(fn ($v): bool => is_string($v) && $v !== '')
            ->unique()
            ->values()
            ->all();
    }
}

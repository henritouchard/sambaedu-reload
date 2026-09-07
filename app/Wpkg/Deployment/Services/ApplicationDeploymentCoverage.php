<?php

declare(strict_types=1);

namespace App\Wpkg\Deployment\Services;

use App\Models\Application;
use App\Models\Workstation;
use App\Models\WorkstationApplicationStatus;
use Illuminate\Support\Collection;

/**
 * Écart entre l'état cible et l'état constaté d'une application sur le parc :
 * combien de postes DOIVENT l'avoir, combien l'ont vraiment.
 *
 * Le décompte des rapports ne répond pas à cette question. Un poste qui doit
 * l'installer mais n'a encore rien rapporté n'y figure pas, et un poste qui l'a
 * reçue hors de toute assignation y compte comme un succès. On part donc de
 * l'état cible — {@see WorkstationPackagesResolver}, seule source de vérité sur
 * ce qu'un poste demande — et on n'y confronte que les installations réelles.
 *
 * Le calcul est global au parc : une seule passe sert toute une page de
 * catalogue, au lieu d'une requête par application.
 */
class ApplicationDeploymentCoverage
{
    public function __construct(
        private readonly WorkstationPackagesResolver $resolver,
    ) {}

    /**
     * @param  Collection<int, Application>|iterable<Application>  $applications
     * @return array<int, array{target: int, installed: int}> indexé par PK d'application
     */
    public function forApplications(iterable $applications): array
    {
        $pkByAppId = [];
        foreach ($applications as $application) {
            $appId = (string) $application->app_id;
            if ($appId !== '') {
                $pkByAppId[$appId] = (int) $application->id;
            }
        }

        if ($pkByAppId === []) {
            return [];
        }

        $coverage = array_fill_keys(array_values($pkByAppId), ['target' => 0, 'installed' => 0]);

        $installed = WorkstationApplicationStatus::query()
            ->where('status', 'installed')
            ->whereIn('application_id', array_values($pkByAppId))
            ->get(['workstation_id', 'application_id'])
            ->groupBy('workstation_id')
            ->map(fn (Collection $rows): array => array_flip($rows->pluck('application_id')->all()));

        Workstation::query()
            ->whereNull('archived_at')
            ->select(['id', 'name'])
            ->chunkById(500, function (Collection $workstations) use ($pkByAppId, $installed, &$coverage): void {
                foreach ($workstations as $workstation) {
                    $installedHere = $installed[$workstation->id] ?? [];

                    foreach ($this->resolver->resolve($workstation->name) as $appId) {
                        $pk = $pkByAppId[$appId] ?? null;
                        if ($pk === null) {
                            continue;
                        }

                        $coverage[$pk]['target']++;
                        if (isset($installedHere[$pk])) {
                            $coverage[$pk]['installed']++;
                        }
                    }
                }
            });

        return $coverage;
    }
}

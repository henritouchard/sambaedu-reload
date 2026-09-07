<?php

declare(strict_types=1);

use App\Models\WorkstationApplicationStatus;
use App\Wpkg\Deployment\Services\WorkstationPackagesResolver;
use Illuminate\Database\Migrations\Migration;

/**
 * Les rapports déjà ingérés ont peuplé `workstation_application_status` avec les
 * apps du catalogue que le poste ne demande pas — `wpkg-client.vbs` inventorie
 * tout `packages.xml`, pas l'état cible. L'ingestion ne les enregistre plus ;
 * restent les lignes héritées, qui se lisent comme des échecs de déploiement.
 *
 * On les retire avec la règle exacte de l'ingestion plutôt qu'en devinant : le
 * resolver est la seule source de vérité sur ce qu'un poste demande.
 */
return new class extends Migration
{
    public function up(): void
    {
        $resolver = app(WorkstationPackagesResolver::class);
        $doomed = [];

        WorkstationApplicationStatus::query()
            ->with(['workstation:id,name', 'application:id,app_id'])
            ->where('status', 'not-installed')
            ->chunkById(500, function ($statuses) use ($resolver, &$doomed): void {
                foreach ($statuses as $status) {
                    $hostname = $status->workstation?->name;
                    $appId = $status->application?->app_id;

                    if ($hostname === null || $appId === null) {
                        continue;
                    }

                    if (! $resolver->resolve($hostname)->contains($appId)) {
                        $doomed[] = $status->id;
                    }
                }
            });

        foreach (array_chunk($doomed, 500) as $chunk) {
            WorkstationApplicationStatus::whereIn('id', $chunk)->delete();
        }
    }

    public function down(): void
    {
        // Le prochain rapport de chaque poste reconstruit la table : rien à restaurer.
    }
};

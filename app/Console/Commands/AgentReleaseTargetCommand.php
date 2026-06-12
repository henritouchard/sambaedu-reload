<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\WorkstationGroup;
use App\Services\Agent\Releases\ReleaseCreationService;
use App\Services\Agent\Releases\ReleaseOperationException;
use Illuminate\Console\Command;

/**
 * Story 25.1 — Ciblage d'un ring sur une version (AC1, décision n° 6).
 *
 * Un ring = UN WorkstationGroup existant (salle physique OU parc logique),
 * lookup par `name`. `updateOrCreate` + touch côté service : un re-ciblage
 * (même de la même version — cas rollback) rafraîchit `updated_at`, la
 * donnée de récence de la résolution multi-rings (décision n° 4).
 *
 * Outillage lab provisoire — l'UI 25.5 écrira les mêmes lignes via le même
 * service. Commande à la demande (pas d'entrée Kernel).
 */
class AgentReleaseTargetCommand extends Command
{
    protected $signature = 'agent:release:target
        {version : Version cible (doit exister dans agent_releases)}
        {group : Nom du WorkstationGroup (le ring) — salle ou parc}';

    protected $description = 'Cible un ring (= un WorkstationGroup) sur une version de release agent';

    public function handle(ReleaseCreationService $releases): int
    {
        $groupName = (string) $this->argument('group');
        $group = WorkstationGroup::query()->where('name', $groupName)->first();
        if ($group === null) {
            $this->error(sprintf(
                'WorkstationGroup "%s" introuvable — le ring DOIT être un groupe existant (vérifier le nom exact, colonne `name`).',
                $groupName,
            ));

            return self::FAILURE;
        }

        try {
            $ring = $releases->target((string) $this->argument('version'), $group);
        } catch (ReleaseOperationException $e) {
            $this->error(sprintf('Ciblage refusé (%s) : %s', $e->reason, $e->getMessage()));

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Ring "%s" ciblé sur la version %s (updated_at %s — la récence fait foi en cas de multi-rings).',
            $group->name,
            (string) $this->argument('version'),
            (string) $ring->updated_at,
        ));

        return self::SUCCESS;
    }
}

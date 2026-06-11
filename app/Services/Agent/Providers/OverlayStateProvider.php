<?php

declare(strict_types=1);

namespace App\Services\Agent\Providers;

use App\Enums\ResourceSemantics;
use App\Enums\StateMaille;
use App\Enums\StateMode;
use App\Enums\StateScope;
use App\Models\OverlaySignal;
use App\Services\Agent\Contracts\StateProvider;
use App\Services\Agent\StateCandidate;
use App\Services\Agent\TargetContext;
use Illuminate\Support\Collection;

/**
 * Type `overlay` (contrat §7) — projection en lecture seule des signaux
 * **postés** (`overlay_signals`, POC f9b3ad9) vers des candidats d'état
 * (Story 23.4, AC4).
 *
 * Un item PAR signal actif (aggregate = union, décision n° 7). Les alertes
 * dérivées (quota, multi-session — `OverlaySignalBuilder`) et le bloc
 * identité/machine restent HORS desired-state v1 : volatiles à chaque poll,
 * elles détruiraient l'ETag — l'arbitrage final (qui compose `overlay.json`
 * côté poste) appartient à la story 24.4. Le POC overlay reste le fetch en
 * prod d'ici là.
 *
 * Signal expiré (`expires_at` ≤ now) = exclu à la compilation : l'état
 * change réellement, l'ETag aussi — correct (piège n° 4 préservé, le hash ne
 * varie que quand l'état varie).
 */
final class OverlayStateProvider implements StateProvider
{
    public function type(): string
    {
        return 'overlay';
    }

    public function semantics(): ResourceSemantics
    {
        return ResourceSemantics::Aggregate;
    }

    public function mode(): StateMode
    {
        return StateMode::Strict;
    }

    public function scope(): StateScope
    {
        return StateScope::Session;
    }

    /**
     * Réutilise le scope de ciblage du POC (`activeFor` : jokers null +
     * expiration) — lecture seule. User null → login vide → seuls les signaux
     * sans `user_login` matchent (garde anti-fuite du scope, review POC).
     *
     * @return Collection<int, StateCandidate>
     */
    public function itemsFor(TargetContext $ctx): Collection
    {
        $signals = OverlaySignal::query()
            ->activeFor(
                (string) ($ctx->workstation->uuid ?? ''),
                (string) ($ctx->user?->login ?? ''),
                $ctx->workstationGroupIds(),
            )
            ->get();

        return $signals->map(fn (OverlaySignal $signal): StateCandidate => new StateCandidate(
            maille: $this->mailleFor($signal, $ctx),
            payload: [
                'kind' => (string) $signal->kind,
                'severity' => (string) $signal->severity,
                'title' => (string) $signal->title,
                'text' => (string) $signal->text,
                // ISO 8601 UTC ou null — jamais de timestamp float (§4.1).
                'expires_at' => $signal->expires_at?->copy()->utc()->toIso8601String(),
            ],
            updatedAt: $signal->updated_at,
            sourceId: (int) $signal->id,
        ));
    }

    /**
     * Étiquette maille d'un signal (décision n° 8) : un signal multi-critères
     * est rangé dans sa maille la plus spécifique. Sans incidence de
     * précédence (aggregate = union) mais l'étiquette doit être cohérente
     * pour les logs et les tests.
     */
    private function mailleFor(OverlaySignal $signal, TargetContext $ctx): StateMaille
    {
        if ($signal->user_login !== null) {
            return StateMaille::User;
        }

        if ($signal->workstation_uuid !== null) {
            return StateMaille::Workstation;
        }

        if ($signal->workstation_group_id !== null) {
            return in_array((int) $signal->workstation_group_id, $ctx->physicalGroupIds, true)
                ? StateMaille::PhysicalGroup
                : StateMaille::LogicalGroup;
        }

        return StateMaille::Broadcast;
    }
}

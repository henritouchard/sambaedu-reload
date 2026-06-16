<?php

declare(strict_types=1);

namespace App\Services\Agent\Providers;

use App\Enums\ResourceSemantics;
use App\Enums\StateMaille;
use App\Enums\StateScope;
use App\Models\WorkstationGroup;
use App\Services\Agent\Contracts\StateProvider;
use App\Services\Agent\StateCandidate;
use App\Services\Agent\TargetContext;
use App\Services\Overlay\OverlayService;
use Illuminate\Support\Collection;

/**
 * Volet MACHINE de l'overlay (Story 27.10) — émet l'item synthétique
 * `{kind:"machine", room}` en portée **machine** (cache persistant, survit aux
 * reboots, rempli par le cycle service + réveil-logon 27.9).
 *
 * La salle (`room`) est une propriété STABLE du POSTE (invariant 1-salle-max :
 * `workstation.physicalRooms[0].name`), pas du user (décision D1). En la
 * basculant de la portée session (ancien item `identity`,
 * {@see OverlayStateProvider}) vers la portée machine — source UNIQUE, plus de
 * redondance — l'agent compose un overlay avec **poste + salle dès le logon**
 * depuis le cache machine, sans attendre le fetch per-user (login/fullname, qui
 * peut tarder ou échouer au tout début de session).
 *
 * Émis MÊME en machine-only (`GET /state` sans user) : la salle ne dépend
 * d'aucune session. Item à `type:"overlay"` (type déjà figé §7), `semantics`
 * aggregate (iso {@see OverlayStateProvider}), portée machine (SYSTEM seul — le
 * compagnon en droits user ne converge JAMAIS sur la portée machine ;
 * l'invariant de partition est préservé, le compose au logon tourne côté SYSTEM
 * et lit les deux caches).
 *
 * `room` = nom du premier WG **physique** du poste (lookup par les ids déjà
 * résolus du contexte — lecture seule, aucune re-résolution d'appartenance) ;
 * pas de WG physique → l'item porte `room: null` (valeur nulle EXPLICITE, iso la
 * convention `wallpaper asset: null`). Côté AGENT le document overlay.json
 * retombe alors sur `room: ""` — c'est `ComposeOverlayDocument` (Go) qui garantit
 * la présence de la clé (la regex du render l'exige), PAS l'item d'état serveur.
 * Aucun float (§4.1).
 */
final class OverlayMachineStateProvider implements StateProvider
{
    public function type(): string
    {
        return 'overlay';
    }

    public function semantics(): ResourceSemantics
    {
        return ResourceSemantics::Aggregate;
    }

    public function scope(): StateScope
    {
        return StateScope::Machine;
    }

    /**
     * Un unique candidat `machine` (la salle) — toujours émis (machine-only
     * compris). `sourceId` 0 : ordre aggregate stable (décision 23.4 n° 9).
     *
     * @return Collection<int, StateCandidate>
     */
    public function itemsFor(TargetContext $ctx): Collection
    {
        $room = $ctx->physicalGroupIds === []
            ? null
            : WorkstationGroup::query()->find($ctx->physicalGroupIds[0])?->name;

        return collect([
            new StateCandidate(
                maille: StateMaille::Workstation,
                payload: [
                    // Kind réservé — postSignal() reclasse tout signal posté qui
                    // le revendiquerait (iso identity, review 24.4 #2).
                    'kind' => OverlayService::KIND_RESERVED_MACHINE,
                    'room' => $room !== null && $room !== '' ? (string) $room : null,
                ],
                updatedAt: $ctx->workstation->updated_at,
                sourceId: 0,
            ),
        ]);
    }
}

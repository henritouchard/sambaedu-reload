<?php

declare(strict_types=1);

namespace App\Services\Agent\Providers;

use App\Enums\ResourceSemantics;
use App\Enums\StateMaille;
use App\Enums\StateScope;
use App\Models\OverlaySignal;
use App\Models\WorkstationGroup;
use App\Services\Agent\Contracts\StateProvider;
use App\Services\Agent\StateCandidate;
use App\Services\Agent\TargetContext;
use App\Services\Overlay\OverlayService;
use Illuminate\Support\Collection;

/**
 * Type `overlay` (contrat §7) — projection en lecture seule des signaux
 * **postés** (`overlay_signals`, POC f9b3ad9) vers des candidats d'état
 * (Story 23.4, AC4).
 *
 * Un item PAR signal actif (aggregate = union, décision n° 7), PLUS — depuis
 * la story 24.4 (décision n° 4) — un candidat synthétique `kind: "identity"`
 * quand la compilation a un user : `{kind, login, fullname, room}`. C'est
 * l'enrichissement serveur qui permet au handler overlay de composer
 * « identité user + parc » sans aucun appel AD côté poste (critère
 * Keycloak) : le compagnon ne connaît localement ni le fullname ni la salle.
 * Données STABLES (l'ETag ne bouge que si elles bougent — correct). Champ de
 * payload owné par la story provider (contrat §3.2) : PAS une évolution
 * d'enveloppe.
 *
 * Les alertes dérivées (quota, multi-session — `OverlaySignalBuilder`)
 * restent HORS desired-state v1 : volatiles à chaque poll, elles
 * détruiraient l'ETag — la composition finale d'`overlay.json` est locale
 * (handler 24.4, cf. docs/agent/handlers-wallpaper-overlay.md).
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

        $candidates = $signals->map(fn (OverlaySignal $signal): StateCandidate => new StateCandidate(
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

        $identity = $this->identityCandidate($ctx);
        if ($identity !== null) {
            $candidates->prepend($identity);
        }

        return $candidates;
    }

    /**
     * Candidat synthétique `identity` (Story 24.4, décision n° 4) — maille
     * User, émis UNIQUEMENT en contexte user (jamais en machine-only : pas
     * d'identité à afficher sans session).
     *
     * `room` = nom du premier WG **physique** du poste (invariant
     * 1-salle-max : il y en a 0 ou 1), null sans salle — lookup par les ids
     * déjà résolus du contexte (lecture seule, pas de re-résolution
     * d'appartenance). `fullname` retombe sur le login si vide (iso
     * `OverlayService::pollPayload`). Aucun float (§4.1).
     *
     * `sourceId` 0 : ordre aggregate stable par `sourceId` asc (décision
     * 23.4 n° 9) — l'identité sort TOUJOURS en tête, avant tout signal
     * (ids DB ≥ 1), quel que soit l'instant de compilation.
     */
    private function identityCandidate(TargetContext $ctx): ?StateCandidate
    {
        if ($ctx->user === null) {
            return null;
        }

        $fullname = (string) ($ctx->user->fullname ?? '');
        $room = $ctx->physicalGroupIds === []
            ? null
            : WorkstationGroup::query()->find($ctx->physicalGroupIds[0])?->name;

        return new StateCandidate(
            maille: StateMaille::User,
            payload: [
                // Kind réservé — postSignal() reclasse tout signal posté qui
                // le revendiquerait (review 24.4 #2).
                'kind' => OverlayService::KIND_RESERVED_IDENTITY,
                'login' => (string) $ctx->user->login,
                'fullname' => $fullname !== '' ? $fullname : (string) $ctx->user->login,
                'room' => $room !== null && $room !== '' ? (string) $room : null,
            ],
            updatedAt: $ctx->user->updated_at,
            sourceId: 0,
        );
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

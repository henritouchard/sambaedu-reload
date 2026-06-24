<?php

declare(strict_types=1);

namespace App\Services\Agent\Providers;

use App\Enums\ResourceSemantics;
use App\Enums\StateMaille;
use App\Enums\StateScope;
use App\Models\Wallpaper;
use App\Models\WorkstationGroup;
use App\Services\Agent\Contracts\StateProvider;
use App\Services\Agent\StateCandidate;
use App\Services\Agent\TargetContext;
use Illuminate\Support\Collection;

/**
 * Type `lockscreen` (contrat §7) — fond de l'écran de VERROUILLAGE, projection
 * en lecture seule de la bibliothèque (`wallpapers` × `wallpaper_assets`) pour
 * les règles `type = 'lockscreen'`.
 *
 * Pendant **machine** du {@see WallpaperStateProvider} (qui, lui, ne couvre que
 * le fond de BUREAU per-user, portée session) : l'écran de verrouillage est
 * PRÉ-login (LogonUI tourne en SYSTEM, aucune session ouverte), il se pose
 * machine-wide (`HKLM\…\PersonalizationCSP`, côté agent — droits SYSTEM). On ne
 * remonte donc QUE les owners indépendants de l'utilisateur :
 *   - défaut d'établissement (`owner_id` null + `is_default`) → Broadcast ;
 *   - salle / parc (`WorkstationGroup`) → Physical/Logical.
 * Aucun owner User/UserGroup (il n'y a pas d'utilisateur au verrouillage) —
 * c'est exactement la restriction « niveaux 1-3 » qu'applique
 * {@see \App\Services\Wallpaper\WallpaperResolver} au lockscreen pour le canal
 * legacy. Ici : zéro précédence (D2 = compilateur), simple étiquetage de maille.
 *
 * Payload v1 (iso wallpaper) : `{asset, checksum}` — `asset` = filename
 * content-addressed de la biblio, `checksum` = SHA-256 du fichier (servi par la
 * même route agnostique au type `agent.v1.assets.wallpaper`). Règle sans asset
 * → `{asset: null, checksum: null}` = « pas de fond de verrouillage imposé »
 * EXPLICITE (contrat §8 — le handler ne touche à rien et rapporte compliant).
 */
final class LockscreenStateProvider implements StateProvider
{
    public function type(): string
    {
        return Wallpaper::TYPE_LOCKSCREEN;
    }

    public function semantics(): ResourceSemantics
    {
        return ResourceSemantics::Exclusive;
    }

    public function scope(): StateScope
    {
        return StateScope::Machine;
    }

    /**
     * Une assignation lockscreen applicable au poste = un candidat. LEFT JOIN
     * sur les assets : une règle sans asset reste une règle (payload null
     * explicite). Aucun filtre user/usergroup (portée machine, pré-login).
     *
     * @return Collection<int, StateCandidate>
     */
    public function itemsFor(TargetContext $ctx): Collection
    {
        $wgIds = $ctx->workstationGroupIds();

        $rows = Wallpaper::query()
            ->ofType(Wallpaper::TYPE_LOCKSCREEN)
            ->leftJoin('wallpaper_assets', 'wallpapers.asset_id', '=', 'wallpaper_assets.id')
            ->where(function ($q) use ($wgIds): void {
                // Broadcast : défaut d'établissement (owner null + is_default).
                $q->orWhere(fn ($qq) => $qq
                    ->whereNull('wallpapers.owner_id')
                    ->where('wallpapers.is_default', true));

                if ($wgIds !== []) {
                    $q->orWhere(fn ($qq) => $qq
                        ->where('wallpapers.owner_type', WorkstationGroup::class)
                        ->whereIn('wallpapers.owner_id', $wgIds));
                }
            })
            ->get([
                'wallpapers.id',
                'wallpapers.owner_type',
                'wallpapers.owner_id',
                'wallpapers.is_default',
                'wallpapers.updated_at',
                'wallpaper_assets.filename as asset_filename',
                'wallpaper_assets.checksum as asset_checksum',
            ]);

        return $rows->map(fn (Wallpaper $row): StateCandidate => new StateCandidate(
            maille: $this->mailleFor($row, $ctx),
            payload: [
                'asset' => $row->asset_filename !== null ? (string) $row->asset_filename : null,
                'checksum' => $row->asset_checksum !== null ? (string) $row->asset_checksum : null,
            ],
            updatedAt: $row->updated_at,
            sourceId: (int) $row->id,
        ));
    }

    /**
     * Étiquetage owner → maille. La distinction physique/logique d'un
     * WorkstationGroup se fait par les listes du contexte (la requête a déjà
     * restreint aux groupes du poste) — étiquetage, pas précédence.
     */
    private function mailleFor(Wallpaper $row, TargetContext $ctx): StateMaille
    {
        if ($row->owner_id === null) {
            return StateMaille::Broadcast;
        }

        return match ($row->owner_type) {
            WorkstationGroup::class => in_array((int) $row->owner_id, $ctx->physicalGroupIds, true)
                ? StateMaille::PhysicalGroup
                : StateMaille::LogicalGroup,
            // Inatteignable via itemsFor() (le WHERE ne ramène que défaut +
            // WorkstationGroup) — garde-fou explicite si une donnée corrompue
            // ou une morph map aliasée arrive un jour (iso WallpaperStateProvider).
            default => throw new \LogicException(
                "owner_type inattendu pour lockscreen #{$row->id} : {$row->owner_type}",
            ),
        };
    }
}

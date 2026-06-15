<?php

declare(strict_types=1);

namespace App\Services\Agent\Providers;

use App\Enums\ResourceSemantics;
use App\Enums\StateMaille;
use App\Enums\StateMode;
use App\Enums\StateScope;
use App\Models\User;
use App\Models\UserGroup;
use App\Models\Wallpaper;
use App\Models\WorkstationGroup;
use App\Services\Agent\Contracts\StateProvider;
use App\Services\Agent\StateCandidate;
use App\Services\Agent\TargetContext;
use Illuminate\Support\Collection;

/**
 * Type `wallpaper` (contrat §7) — projection en lecture seule de la
 * bibliothèque (`wallpapers` × `wallpaper_assets`) vers des candidats d'état
 * (Story 23.4, AC4).
 *
 * La requête s'inspire de `WallpaperResolver::fetchAssignments()` mais la
 * classe legacy n'est ni réutilisée ni modifiée (piège n° 1) : elle applique
 * SA précédence 7 niveaux et dépend d'APCu — elle reste le résolveur du canal
 * legacy jusqu'à extinction (Epic 27). Ici : zéro précédence, zéro tri par
 * maille — étiquetage des candidats et c'est tout (D2 = compilateur).
 *
 * Payload v1 (décision n° 5) : `{asset, checksum}` — `asset` = filename
 * content-addressed de la biblio, `checksum` = SHA-256 du fichier (ce que le
 * handler comparera en `test`). Règle sans asset → `{asset: null,
 * checksum: null}` = « pas de fond imposé » EXPLICITE (contrat §8 — distinct
 * du type absent). L'URL de téléchargement viendra avec la route de serving
 * (champ mineur futur, 24.4). `lockscreen` = futur type séparé, hors scope.
 */
final class WallpaperStateProvider implements StateProvider
{
    public function type(): string
    {
        return Wallpaper::TYPE_WALLPAPER;
    }

    public function semantics(): ResourceSemantics
    {
        return ResourceSemantics::Exclusive;
    }

    public function mode(): StateMode
    {
        return StateMode::Default;
    }

    public function scope(): StateScope
    {
        return StateScope::Session;
    }

    /**
     * Une assignation applicable au contexte = un candidat. LEFT JOIN sur les
     * assets : une règle sans asset reste une règle (payload null explicite).
     *
     * @return Collection<int, StateCandidate>
     */
    public function itemsFor(TargetContext $ctx): Collection
    {
        $wgIds = $ctx->workstationGroupIds();

        $rows = Wallpaper::query()
            ->ofType(Wallpaper::TYPE_WALLPAPER)
            ->leftJoin('wallpaper_assets', 'wallpapers.asset_id', '=', 'wallpaper_assets.id')
            ->where(function ($q) use ($ctx, $wgIds): void {
                // Broadcast : défaut d'établissement (owner null + is_default).
                $q->orWhere(fn ($qq) => $qq
                    ->whereNull('wallpapers.owner_id')
                    ->where('wallpapers.is_default', true));

                if ($wgIds !== []) {
                    $q->orWhere(fn ($qq) => $qq
                        ->where('wallpapers.owner_type', WorkstationGroup::class)
                        ->whereIn('wallpapers.owner_id', $wgIds));
                }

                if ($ctx->userGroupIds !== []) {
                    $q->orWhere(fn ($qq) => $qq
                        ->where('wallpapers.owner_type', UserGroup::class)
                        ->whereIn('wallpapers.owner_id', $ctx->userGroupIds));
                }

                if ($ctx->user !== null) {
                    $q->orWhere(fn ($qq) => $qq
                        ->where('wallpapers.owner_type', User::class)
                        ->where('wallpapers.owner_id', $ctx->user->id));
                }
            })
            ->get([
                'wallpapers.id',
                'wallpapers.owner_type',
                'wallpapers.owner_id',
                'wallpapers.is_default',
                'wallpapers.mode',
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
            // Mode par règle (Story 27.1, décision n° 2) — null en base = pas
            // déclaré → le compilateur retombe sur mode() (défaut `default`,
            // comportement 23.4 préservé).
            mode: $row->mode,
        ));
    }

    /**
     * Étiquetage owner → maille. La distinction physique/logique d'un
     * WorkstationGroup se fait par `is_physical` via les listes du contexte
     * (la requête a déjà restreint aux groupes du poste) — c'est de
     * l'étiquetage, pas de la précédence.
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
            UserGroup::class => StateMaille::UserGroup,
            User::class => StateMaille::User,
            // Inatteignable via itemsFor() (le WHERE ne ramène que ces
            // owners) — garde-fou explicite si une morph map aliasée ou une
            // donnée corrompue arrive un jour (review 23.4).
            default => throw new \LogicException(
                "owner_type inattendu pour wallpaper #{$row->id} : {$row->owner_type}",
            ),
        };
    }
}

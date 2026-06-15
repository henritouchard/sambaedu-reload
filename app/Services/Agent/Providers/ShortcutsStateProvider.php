<?php

declare(strict_types=1);

namespace App\Services\Agent\Providers;

use App\Enums\ResourceSemantics;
use App\Enums\StateMaille;
use App\Enums\StateMode;
use App\Enums\StateScope;
use App\Enums\WorkstationEnvironment;
use App\Models\Shortcut;
use App\Models\User;
use App\Models\UserGroup;
use App\Models\Workstation;
use App\Models\WorkstationGroup;
use App\Services\Agent\Contracts\StateProvider;
use App\Services\Agent\StateCandidate;
use App\Services\Agent\TargetContext;
use App\Services\Agent\WorkstationEnvironmentResolver;
use Illuminate\Support\Collection;

/**
 * Type `shortcuts` (contrat §7, identifiant DÉJÀ figé — NFR12) — projection en
 * lecture seule des règles de raccourcis (`shortcuts` × `shortcut_assignables`)
 * vers des candidats d'état (Story 27.1, AC1/AC2/AC3).
 *
 * **Le fix définitif du Bug C** : le chemin du bureau cible n'est plus une
 * branche figée dans un `.cmd` legacy (pansement réseau `4e5a152`) mais une
 * donnée du domaine — résolue CÔTÉ SERVEUR (décision n° 3) via le
 * {@see WorkstationEnvironmentResolver} (26.1) : bureau RÉSEAU si le parc est
 * `shared_local`, bureau LOCAL si `personal_local`/`nomade`. L'agent reste
 * bête : il pose le `.lnk` au `desktop_path` reçu (tokens `<se4fs>`/`<user>`
 * substitués localement).
 *
 * **Lecture Postgres PURE** (NFR7, critère Keycloak) : le legacy
 * `ShortcutCompilerService::resolveForMachine()` lit `ad_users`/`ad_user_groups`
 * (CN AD) via `whereJsonContains` + cache APCu — INTERDIT ici. Ce provider ne
 * touche JAMAIS l'AD : il lit le pivot polymorphe `shortcut_assignables`
 * (WorkstationGroup + Workstation + UserGroup + User — ciblage MVP pivot SQL,
 * décision n° 8) restreint aux ids déjà résolus du {@see TargetContext}. Les
 * colonnes `ad_users`/`ad_user_groups` ne sont PAS lues (hors-scope, NFR7).
 *
 * **Sémantique `aggregate`** (union, décision n° 4) : un poste reçoit l'union
 * des raccourcis de toutes ses mailles. Le provider étiquette ses candidats
 * BRUTS (peut produire des doublons quand un même raccourci est assigné au parc
 * ET au poste) — zéro précédence, zéro tri, zéro dédup : la dédup par contenu
 * vit dans le `StateCompiler` SEUL (D2).
 *
 * **Scope `machine_user`** (décision n° 1) : le set de raccourcis dépend du
 * user, mais le CHEMIN du bureau dépend du POSTE (`WorkstationEnvironment`) —
 * le calcul est un croisement (poste, user), compilé par couple.
 *
 * Payload v1 (décision n° 6) : `{name, target, args, icon, place, desktop_path}`
 * — `desktop_path` présent uniquement si `place=desktop`. Pas de float (§4.1).
 */
final class ShortcutsStateProvider implements StateProvider
{
    public function __construct(
        private readonly WorkstationEnvironmentResolver $environmentResolver,
    ) {}

    public function type(): string
    {
        return Shortcut::TYPE_SHORTCUTS;
    }

    public function semantics(): ResourceSemantics
    {
        return ResourceSemantics::Aggregate;
    }

    public function mode(): StateMode
    {
        // Défaut du type (décision n° 2) — un raccourci sans `mode` en base est
        // strict : la cible fait loi (comportement attendu côté admin). Le
        // toggle par règle (`StateCandidate::$mode`) le surcharge.
        return StateMode::Strict;
    }

    public function scope(): StateScope
    {
        return StateScope::MachineUser;
    }

    /**
     * Un couple (raccourci actif × assignation applicable au contexte) = un
     * candidat. Le `desktop_path` est résolu UNE fois pour le poste (donnée
     * machine) puis injecté dans chaque candidat `place=desktop` (le set
     * dépend du user, le chemin du poste — décision n° 1/n° 3).
     *
     * @return Collection<int, StateCandidate>
     */
    public function itemsFor(TargetContext $ctx): Collection
    {
        $wgIds = $ctx->workstationGroupIds();
        $desktopPath = $this->desktopPathFor($ctx);

        $rows = Shortcut::query()
            ->where('shortcuts.is_active', true)
            ->join('shortcut_assignables', 'shortcuts.id', '=', 'shortcut_assignables.shortcut_id')
            ->where(function ($q) use ($ctx, $wgIds): void {
                if ($wgIds !== []) {
                    $q->orWhere(fn ($qq) => $qq
                        ->where('shortcut_assignables.assignable_type', WorkstationGroup::class)
                        ->whereIn('shortcut_assignables.assignable_id', $wgIds));
                }

                $q->orWhere(fn ($qq) => $qq
                    ->where('shortcut_assignables.assignable_type', Workstation::class)
                    ->where('shortcut_assignables.assignable_id', $ctx->workstation->id));

                if ($ctx->userGroupIds !== []) {
                    $q->orWhere(fn ($qq) => $qq
                        ->where('shortcut_assignables.assignable_type', UserGroup::class)
                        ->whereIn('shortcut_assignables.assignable_id', $ctx->userGroupIds));
                }

                if ($ctx->user !== null) {
                    $q->orWhere(fn ($qq) => $qq
                        ->where('shortcut_assignables.assignable_type', User::class)
                        ->where('shortcut_assignables.assignable_id', $ctx->user->id));
                }
            })
            ->get([
                'shortcuts.id',
                'shortcuts.name',
                'shortcuts.place',
                'shortcuts.mode',
                'shortcuts.windows_link',
                'shortcuts.windows_args',
                'shortcuts.windows_icon',
                'shortcuts.icon_path',
                'shortcuts.updated_at',
                'shortcut_assignables.assignable_type',
                'shortcut_assignables.assignable_id',
            ]);

        return $rows->map(fn (Shortcut $row): StateCandidate => new StateCandidate(
            maille: $this->mailleFor($row, $ctx),
            payload: $this->payloadFor($row, $desktopPath),
            updatedAt: $row->updated_at,
            sourceId: (int) $row->id,
            // Mode par règle (décision n° 2) — null en base = pas déclaré → le
            // compilateur retombe sur mode() (défaut `strict`).
            mode: $row->mode,
        ));
    }

    /**
     * Chemin du bureau résolu côté SERVEUR (décision n° 3, fix Bug C). Tokens
     * `<se4fs>` (nom serveur de fichiers) et `<user>` (login courant) laissés
     * au payload : l'agent les substitue LOCALEMENT (aucune fuite de secret,
     * aucune dépendance réseau au calcul). Backslash final = convention legacy.
     *
     * Le mapping environnement→chemin vit ici (pas dans l'enum, pas dans
     * l'agent) — l'agent reste bête.
     */
    private function desktopPathFor(TargetContext $ctx): string
    {
        $environment = $this->environmentResolver->resolveForGroupIds($ctx->workstationGroupIds());

        return match ($environment) {
            // Poste partagé : bureau redirigé RÉSEAU (le défaut du pansement Bug
            // C, mais désormais PARAMÉTRABLE par parc et non figé).
            WorkstationEnvironment::SharedLocal => '\\\\<se4fs>\\users\\<user>\\Bureau\\',
            // Perdir / nomade : bureau LOCAL du profil — le `.lnk` est posé
            // dans le profil de l'utilisateur, pas sur le partage réseau.
            WorkstationEnvironment::PersonalLocal,
            WorkstationEnvironment::Nomade => '%USERPROFILE%\\Desktop\\',
        };
    }

    /**
     * Payload v1 (décision n° 6). `desktop_path` présent UNIQUEMENT pour
     * `place=desktop` (startup/taskbar ont leurs propres chemins standards,
     * résolus par l'agent). `target` = la cible (exe/URL), `args` = arguments,
     * `icon` = chemin d'icône (windows_icon prioritaire, fallback icon_path).
     * Toujours des strings (jamais de float, §4.1).
     *
     * @return array<string,mixed>
     */
    private function payloadFor(Shortcut $row, string $desktopPath): array
    {
        $payload = [
            'name' => (string) $row->name,
            'target' => (string) ($row->windows_link ?? ''),
            'args' => (string) ($row->windows_args ?? ''),
            'icon' => (string) ($row->windows_icon ?? $row->icon_path ?? ''),
            'place' => (string) $row->place,
        ];

        if ($row->place === Shortcut::PLACE_DESKTOP) {
            $payload['desktop_path'] = $desktopPath;
        }

        return $payload;
    }

    /**
     * Étiquetage assignable → maille (décision n° 8). La distinction
     * physique/logique d'un WorkstationGroup se fait par les listes du contexte
     * (la requête a déjà restreint aux groupes du poste) — étiquetage, pas
     * précédence (D2 = compilateur).
     */
    private function mailleFor(Shortcut $row, TargetContext $ctx): StateMaille
    {
        return match ($row->assignable_type) {
            WorkstationGroup::class => in_array((int) $row->assignable_id, $ctx->physicalGroupIds, true)
                ? StateMaille::PhysicalGroup
                : StateMaille::LogicalGroup,
            Workstation::class => StateMaille::Workstation,
            UserGroup::class => StateMaille::UserGroup,
            User::class => StateMaille::User,
            // Inatteignable via itemsFor() (le WHERE ne ramène que ces types) —
            // garde-fou explicite si une morph aliasée ou une donnée corrompue
            // arrive un jour (iso WallpaperStateProvider).
            default => throw new \LogicException(
                "assignable_type inattendu pour shortcut #{$row->id} : {$row->assignable_type}",
            ),
        };
    }
}

<?php

use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Story 62.1 — /admin/settings/groups : « Groupes & droits » (page HÔTE à
 * onglets).
 *
 * Elle réunit le VOCABULAIRE du modèle groupes/rôles/droits (Epic 62) : ce qui se
 * déclare une fois et se réutilise partout ailleurs. Deux onglets :
 *
 *   - « Rôles » (62.1) : le catalogue des rôles d'appartenance — une clé IMMUABLE,
 *     un libellé modifiable, un ordre d'affichage. C'est la valeur portée par
 *     `user_group_user.role` et visée par les recettes de répertoire.
 *   - « Types de groupes » (62.2) : le catalogue des types — une clé IMMUABLE, un
 *     libellé, une icône. C'est la valeur portée par `user_groups.type`, celle
 *     que les recettes de répertoire visent par `attached_group_type`, et celle
 *     que le code métier compare en littéral.
 *
 * ⚠️ **Pas d'onglet fantôme.** La règle tient toujours, et « Arborescences »
 * (62.6) n'apparaît PAS tant qu'elle n'existe pas : un onglet grisé annonce une
 * fonction qu'on ne peut pas atteindre, et un onglet retiré ne doit laisser
 * aucune UI orpheline (correctif ef55abe3). `TABS` liste ce qui est réellement
 * rendu, et la barre n'affiche que lui.
 *
 * Sécurité : `can:server.admin` sur la route + garde `mount()` — `server.admin`
 * SEUL pour toute l'administration de l'Epic 62 (Q4 = A, décision Henri
 * 2026-08-08). Aucune permission Spatie nouvelle.
 */
new #[Title('Groupes & droits')] class extends Component {
    #[Url(keep: true)]
    public string $tab = 'roles';

    private const TABS = ['roles', 'types'];

    public function mount(): void
    {
        if (! Gate::allows('server.admin')) {
            abort(403);
        }

        if (! in_array($this->tab, self::TABS, true)) {
            $this->tab = 'roles';
        }
    }

    public function setTab(string $tab): void
    {
        if (in_array($tab, self::TABS, true)) {
            $this->tab = $tab;
        }
    }
};
?>

<x-organisms.page title="Groupes & droits"
    icon="fa-solid fa-user-tag"
    description="Le vocabulaire commun des groupes d'utilisateurs : rôles d'appartenance et types de groupes — clés immuables, libellés et ordre d'affichage."
    back="{{ route('admin.settings') }}">

    <div class="flex flex-col gap-6 pt-4">

        @php
            $groupsTabs = [
                'roles' => ['label' => 'Rôles', 'icon' => 'fa-solid fa-user-tag'],
                'types' => ['label' => 'Types de groupes', 'icon' => 'fa-solid fa-layer-group'],
            ];
        @endphp
        <x-molecules.tabs :tabs="$groupsTabs" :active="$tab" class="bg-base-200 w-fit" />

        <div class="flex flex-col">
            @if ($tab === 'roles')
                <livewire:pages::admin.settings.groups._partials.roles-tab />
            @endif
            @if ($tab === 'types')
                <livewire:pages::admin.settings.groups._partials.types-tab />
            @endif
        </div>

    </div>
</x-organisms.page>

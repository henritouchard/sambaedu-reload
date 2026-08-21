<?php

use App\Models\DirectoryTemplate;
use App\Models\UserGroup;
use App\Support\GroupTypeCatalog;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

/**
 * L'onglet « Arborescences » de /admin/settings/groups : LA LISTE.
 *
 * Son unité est le TYPE de groupe, pas la recette : l'invariant « un type = un
 * arbre » est tenu par le modèle ({@see DirectoryTemplate::assertSingleTreeAttachment()}),
 * et lister les recettes ferait de cet invariant une propriété qu'on
 * découvrirait par une exception. Les recettes PLATES n'ont rien à faire ici —
 * leur matérialisation reste un geste manuel, sur l'écran des partages.
 *
 * L'ÉDITION vit sur sa propre page ({@see route('admin.settings.groups.tree')}) :
 * une arborescence se conçoit en voyant sa hiérarchie, et une modale ne sait pas
 * porter un arbre, ses fiches et son aperçu à la fois.
 *
 * Sécurité : `server.admin` au `mount()`, doublant la garde de route.
 */
new class extends Component {
    /** @var array<int, array<string, mixed>> */
    public array $rows = [];

    public function mount(): void
    {
        abort_unless(Gate::allows('server.admin'), 403);
        $this->loadRows();
    }

    /** Un type par ligne, avec SA recette d'arbre ou l'état « aucune ». */
    public function loadRows(): void
    {
        $this->rows = [];

        foreach (GroupTypeCatalog::rows() as $key => $row) {
            $template = DirectoryTemplate::attachedTo((string) $key);

            $this->rows[] = [
                'key' => (string) $key,
                'label' => (string) $row['label'],
                'icon' => GroupTypeCatalog::icon((string) $key),
                'template_key' => $template?->key === null ? null : (string) $template->key,
                'template_label' => $template?->label === null ? null : (string) $template->label,
                'nodes' => $template === null ? 0 : count($template->nodes()),
                'groups' => UserGroup::query()->whereRaw('LOWER(type) = ?', [mb_strtolower((string) $key)])->count(),
            ];
        }
    }
};
?>

<div class="flex flex-col gap-6">

    <div class="alert alert-info shadow-sm">
        <i class="fa-solid fa-circle-info"></i>
        <div>
            <p class="font-medium">Ce que porte une arborescence</p>
            <p class="text-sm opacity-80">
                Un type de groupe porte au plus <strong>une</strong> arborescence : la liste des dossiers créés
                pour chaque groupe de ce type, et ce que chaque audience y peut faire. Les
                <strong>répertoires réseau nommés</strong>, eux, ne sont pas gouvernés ici — ils vivent sur
                leur propre écran.
            </p>
        </div>
    </div>

    <x-organisms.data-table
        colgroup="<colgroup><col style='width: 26%'><col style='width: 14%'><col style='width: 30%'><col style='width: 14%'><col style='width: 16%'></colgroup>">
        <x-slot:header>
            <th>Type de groupe</th>
            <th>Groupes</th>
            <th>Arborescence</th>
            <th>Dossiers</th>
            <th class="text-right">Actions</th>
        </x-slot:header>
        @foreach ($rows as $row)
            <tr wire:key="tree-type-{{ $row['key'] }}" data-testid="tree-row-{{ $row['key'] }}">
                <td class="font-bold">
                    <i class="{{ $row['icon'] }} mr-2 opacity-70" aria-hidden="true"></i>
                    {{ $row['label'] }}
                    <code class="text-xs opacity-50 ml-2">{{ $row['key'] }}</code>
                </td>
                <td class="text-sm">
                    <span class="badge badge-sm badge-outline">{{ $row['groups'] }}</span>
                </td>
                <td class="text-sm" data-testid="tree-attachment-{{ $row['key'] }}">
                    @if ($row['template_key'] !== null)
                        <span class="badge badge-sm badge-info"><code>{{ $row['template_key'] }}</code></span>
                        <span class="opacity-70 ml-1">{{ $row['template_label'] }}</span>
                    @else
                        <span class="opacity-50">Aucune arborescence</span>
                    @endif
                </td>
                <td class="text-sm">
                    @if ($row['template_key'] !== null)
                        {{ $row['nodes'] }}
                    @else
                        <span class="opacity-50">—</span>
                    @endif
                </td>
                <td class="text-right whitespace-nowrap">
                    <a class="btn btn-ghost btn-xs"
                        href="{{ route('admin.settings.groups.tree', [
                            'type' => $row['key'],
                            'from' => route('admin.settings.groups', ['tab' => 'trees'], false),
                        ]) }}"
                        data-testid="open-tree-{{ $row['key'] }}">
                        @if ($row['template_key'] !== null)
                            <i class="fa-solid fa-pen"></i> Modifier l'arborescence
                        @else
                            <i class="fa-solid fa-plus"></i> Créer l'arborescence
                        @endif
                    </a>
                </td>
            </tr>
        @endforeach
    </x-organisms.data-table>

    <p class="text-xs text-base-content/60" data-testid="flat-recipes-note">
        Seules les arborescences apparaissent ici. Les <strong>recettes plates</strong> (un dossier unique, sans
        sous-dossiers) se matérialisent à la demande depuis l'écran des répertoires réseau.
    </p>
</div>

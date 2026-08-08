<?php

use App\Components\Traits\WithToasts;
use App\Models\GroupType;
use App\Support\GroupTypeCatalog;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Story 62.2 — onglet « Types de groupes » de /admin/settings/groups : LE
 * CATALOGUE.
 *
 * Un type = une CLÉ immuable ⇔ un LIBELLÉ modifiable, plus une icône et un rang
 * d'affichage. La clé est ce qui est stocké dans `user_groups.type`, ce que les
 * arborescences visent par `attached_group_type`, et ce que le code métier compare
 * en littéral (« ce groupe est-il une `classe` ? »). Le libellé est ce qui se lit à
 * l'écran. Renommer ne touche donc jamais une donnée dérivée.
 *
 * **La clé est dérivée du libellé à la CRÉATION, et figée là.** Prévisualisée avant
 * validation (patron de l'onglet « Rôles »), elle n'est plus modifiable ensuite —
 * ni ici, ni par le modèle, qui lève.
 *
 * **L'écran DIT l'invariant d'accrochage, il ne le devine pas.** Un type ne porte
 * qu'une recette d'ARBRE (garde applicative `assertSingleTreeAttachment()`, story
 * 60.5) ; les recettes PLATES, elles, peuvent être plusieurs sur le même type — le
 * type `classe` en porte deux dans le catalogue livré. La colonne
 * « Arborescence » montre l'arbre accroché et compte les plates, et la note sous la
 * liste énonce la règle : l'admin l'apprend AVANT de rencontrer l'exception, qui
 * reste la garde de dernier recours.
 *
 * **Lecture SEULE sur l'accrochage.** Aucune UI ne l'attribue ici : c'est de la
 * donnée seedée jusqu'à la story 62.6, qui apportera l'éditeur d'arborescences.
 *
 * **La suppression REFUSE, elle ne cascade jamais.** Un type porté par des groupes
 * ou visé par une recette n'est pas supprimable, et le refus NOMME le décompte. Les
 * neuf types recensés ne sont pas supprimables du tout : leurs clés sont écrites en
 * littéral dans le code de SE5, et le prochain balayage d'annuaire les réécrirait.
 *
 * Sécurité : `server.admin` au `mount()` ET à chaque écriture (double garde,
 * patron des pages settings). Q4 = A — aucune permission Spatie nouvelle.
 */
new class extends Component {
    use WithToasts;

    /** @var array<int, array<string, mixed>> */
    public array $rows = [];

    // --- Modale création / édition ------------------------------------------
    public bool $isModalOpen = false;

    public bool $isEditing = false;

    /**
     * Identité du type édité (`null` = création).
     *
     * `#[Locked]` : sans ça, un payload forgé ferait porter l'enregistrement sur
     * un AUTRE type que celui ouvert.
     */
    #[Locked]
    public ?int $editId = null;

    public string $label = '';

    public string $icon = '';

    // --- Modale de suppression ----------------------------------------------
    public bool $isDeleteOpen = false;

    #[Locked]
    public ?int $deleteId = null;

    public string $deleteLabel = '';

    public string $deleteWarning = '';

    public function mount(): void
    {
        abort_unless(Gate::allows('server.admin'), 403);
        $this->loadRows();
    }

    /** Le catalogue, trié par rang d'affichage, avec ses usages et ses accrochages. */
    public function loadRows(): void
    {
        $this->rows = GroupType::orderBy('sort_order')->orderBy('id')->get()
            ->map(fn (GroupType $type): array => [
                'id' => (int) $type->id,
                'key' => (string) $type->key,
                'label' => (string) $type->label,
                // L'icône passe par le catalogue : une ligne sans icône déclarée
                // (les types DÉCOUVERTS en base n'en ont pas) rend l'icône
                // générique plutôt qu'un trou dans la colonne.
                'icon' => GroupTypeCatalog::icon((string) $type->key),
                'sort_order' => (int) $type->sort_order,
                'protected' => $type->isProtected(),
                'usage' => $type->usage(),
                'attachment' => $type->attachment(),
            ])
            ->all();
    }

    /** Clé prévisualisée dans la modale de création (jamais en édition). */
    public function getPreviewKeyProperty(): string
    {
        return GroupType::slugify($this->label);
    }

    /** Icône prévisualisée : ce que l'écran rendra, repli générique compris. */
    public function getPreviewIconProperty(): string
    {
        $icon = trim($this->icon);

        return $icon !== '' ? $icon : GroupTypeCatalog::DEFAULT_ICON;
    }

    public function openCreate(): void
    {
        abort_unless(Gate::allows('server.admin'), 403);

        $this->resetForm();
        $this->isEditing = false;
        $this->isModalOpen = true;
    }

    public function openEdit(int $id): void
    {
        abort_unless(Gate::allows('server.admin'), 403);

        $type = GroupType::find($id);
        if ($type === null) {
            $this->toastError('Type introuvable — la page a peut-être changé, rechargez.');

            return;
        }

        $this->resetForm();
        $this->isEditing = true;
        $this->editId = (int) $type->id;
        $this->label = (string) $type->label;
        $this->icon = (string) ($type->icon ?? '');
        $this->isModalOpen = true;
    }

    public function close(): void
    {
        $this->isModalOpen = false;
        $this->resetForm();
    }

    private function resetForm(): void
    {
        $this->editId = null;
        $this->label = '';
        $this->icon = '';
        $this->resetErrorBag();
    }

    public function save(): void
    {
        abort_unless(Gate::allows('server.admin'), 403);

        $this->validate(
            [
                'label' => ['required', 'string', 'max:100'],
                'icon' => ['nullable', 'string', 'max:100'],
            ],
            ['label.required' => 'Le libellé est requis.'],
        );

        $icon = trim($this->icon);
        $icon = $icon === '' ? null : $icon;

        if ($this->isEditing) {
            $type = GroupType::find($this->editId);
            if ($type === null) {
                $this->toastError('Type introuvable — rechargez la page.');

                return;
            }

            // La CLÉ n'est jamais touchée ici : seules `label` et `icon`
            // changent, et c'est exactement ce que l'AC « un renommage ne touche
            // aucune donnée dérivée » exige.
            $type->label = trim($this->label);
            $type->icon = $icon;

            try {
                $type->save();
            } catch (\Throwable $e) {
                // Review 62.2 #1 — la branche création interceptait déjà les gardes
                // du modèle, pas celle-ci : une garde qui refusait ce type rendait
                // un 500 au lieu d'un message. Le défaut de fond est corrigé sur le
                // modèle ; ce filet reste, par symétrie avec la création, pour toute
                // garde future.
                $this->addError('label', $e->getMessage());

                return;
            }

            $this->toastSuccess(sprintf('Le type se lit désormais « %s ».', (string) $type->label));
            $this->close();
            $this->loadRows();

            return;
        }

        $key = GroupType::slugify($this->label);

        if ($key === '') {
            $this->addError('label', 'Ce libellé ne produit aucune clé utilisable : ajoutez au moins une lettre.');

            return;
        }

        if (GroupType::where('key', $key)->exists()) {
            $this->addError('label', sprintf(
                'La clé « %s » est déjà prise par un type du catalogue. Choisissez un autre libellé.',
                $key,
            ));

            return;
        }

        try {
            $type = GroupType::create([
                'key' => $key,
                'label' => trim($this->label),
                'icon' => $icon,
                'sort_order' => ((int) GroupType::max('sort_order')) + 1,
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            // Le contrôle d'unicité ci-dessus est un check-then-act : deux
            // soumissions concurrentes du même libellé (double-clic, deux
            // onglets) le passent toutes les deux et se disputent la contrainte
            // unique en base. La perdante reçoit le message métier, pas un
            // SQLSTATE brut (leçon de la review 62.1).
            $this->addError('label', sprintf(
                'La clé « %s » est déjà prise par un type du catalogue. Choisissez un autre libellé.',
                $key,
            ));

            return;
        } catch (\Throwable $e) {
            // Garde du modèle (format, longueur) : message métier, jamais un 500.
            $this->addError('label', $e->getMessage());

            return;
        }

        $this->toastSuccess(sprintf('Le type « %s » a été créé (clé « %s »).', (string) $type->label, $key));
        $this->close();
        $this->loadRows();
    }

    // --- Ordre d'affichage ---------------------------------------------------

    public function moveUp(int $id): void
    {
        $this->swapWithNeighbour($id, -1);
    }

    public function moveDown(int $id): void
    {
        $this->swapWithNeighbour($id, +1);
    }

    /**
     * Échange le rang de ce type avec celui de son voisin.
     *
     * Monter/descendre suffit : un tri par glisser-déposer coûterait une
     * dépendance et un état client pour un catalogue qui tient sur un écran.
     */
    private function swapWithNeighbour(int $id, int $direction): void
    {
        abort_unless(Gate::allows('server.admin'), 403);

        $ordered = GroupType::orderBy('sort_order')->orderBy('id')->get()->values();
        $index = $ordered->search(fn (GroupType $type): bool => (int) $type->id === $id);

        if ($index === false) {
            $this->toastError('Type introuvable — rechargez la page.');

            return;
        }

        $target = $index + $direction;
        if ($target < 0 || $target >= $ordered->count()) {
            return; // Déjà en bout de liste : rien à faire, rien à dire.
        }

        // Les rangs stockés peuvent être égaux (import, seed partiel) : on
        // RÉÉCRIT toute la séquence plutôt que d'échanger deux valeurs, sinon un
        // ex æquo rendrait le déplacement invisible.
        $reordered = $ordered->all();
        [$reordered[$index], $reordered[$target]] = [$reordered[$target], $reordered[$index]];

        foreach ($reordered as $position => $type) {
            $type->sort_order = $position + 1;
            $type->save();
        }

        GroupTypeCatalog::flush();
        $this->loadRows();
    }

    // --- Suppression ---------------------------------------------------------

    public function confirmDelete(int $id): void
    {
        abort_unless(Gate::allows('server.admin'), 403);

        $type = GroupType::find($id);
        if ($type === null) {
            $this->toastError('Type introuvable — rechargez la page.');

            return;
        }

        $refusal = $type->deletionRefusal();
        if ($refusal !== null) {
            // Refus NOMMÉ, et AUCUNE écriture : ni sur le type, ni sur les
            // groupes, ni sur les recettes. Jamais de cascade.
            $this->toastError($refusal);

            return;
        }

        $this->deleteId = (int) $type->id;
        $this->deleteLabel = (string) $type->label;
        $this->deleteWarning = sprintf(
            'Le type « %s » (clé « %s ») n\'est porté par aucun groupe et visé par aucune arborescence.',
            (string) $type->label,
            (string) $type->key,
        );
        $this->isDeleteOpen = true;
    }

    public function closeDelete(): void
    {
        $this->isDeleteOpen = false;
        $this->deleteId = null;
        $this->deleteLabel = '';
        $this->deleteWarning = '';
    }

    public function delete(): void
    {
        abort_unless(Gate::allows('server.admin'), 403);

        $type = GroupType::find($this->deleteId);
        if ($type === null) {
            $this->toastError('Type introuvable — rechargez la page.');
            $this->closeDelete();

            return;
        }

        // Re-vérification côté serveur : entre l'ouverture de la confirmation et
        // le clic, un groupe a pu naître.
        $refusal = $type->deletionRefusal();
        if ($refusal !== null) {
            $this->toastError($refusal);
            $this->closeDelete();

            return;
        }

        $label = (string) $type->label;
        $type->delete();

        $this->toastSuccess(sprintf('Le type « %s » a été supprimé du catalogue.', $label));
        $this->closeDelete();
        $this->loadRows();
    }
};
?>

<div class="flex flex-col gap-6">

    <div class="flex items-start justify-between gap-4 flex-wrap">
        <div class="alert alert-info shadow-sm flex-1 min-w-72">
            <i class="fa-solid fa-circle-info"></i>
            <div>
                <p class="font-medium">Ce que porte un type de groupe</p>
                <p class="text-sm opacity-80">
                    Le type dit ce qu'un groupe <strong>est</strong> (une classe, un projet, une équipe…).
                    Sa <strong>clé</strong> est stockée sur chaque groupe, visée par les arborescences de
                    fichiers et comparée par le code : elle ne change jamais. Son <strong>libellé</strong> et
                    son <strong>icône</strong>, eux, se modifient librement.
                </p>
            </div>
        </div>

        <button type="button" class="btn highlight btn-primary" wire:click="openCreate" data-testid="open-create-type">
            <i class="fa-solid fa-plus"></i> Ajouter un type
        </button>
    </div>

    <x-organisms.data-table
        colgroup="<colgroup><col style='width: 24%'><col style='width: 18%'><col style='width: 16%'><col style='width: 22%'><col style='width: 20%'></colgroup>">
        <x-slot:header>
            <th>Libellé</th>
            <th>Clé</th>
            <th>Groupes</th>
            <th>Arborescence</th>
            <th class="text-right">Actions</th>
        </x-slot:header>
        @foreach ($rows as $index => $row)
            <tr wire:key="group-type-{{ $row['id'] }}" data-testid="type-row-{{ $row['key'] }}">
                <td class="font-bold">
                    <i class="{{ $row['icon'] }} mr-2 opacity-70" aria-hidden="true"></i>
                    {{ $row['label'] }}
                    @if ($row['protected'])
                        <span class="badge badge-sm badge-ghost ml-2">structurel</span>
                    @endif
                </td>
                <td><code class="text-xs opacity-70">{{ $row['key'] }}</code></td>
                <td class="text-sm">
                    <span class="badge badge-sm badge-outline" data-testid="groups-count-{{ $row['key'] }}">
                        {{ $row['usage']['groups'] }} groupe{{ $row['usage']['groups'] > 1 ? 's' : '' }}
                    </span>
                </td>
                <td class="text-sm" data-testid="attachment-{{ $row['key'] }}">
                    @if ($row['attachment']['tree'] !== null)
                        <span class="badge badge-sm badge-info"><code>{{ $row['attachment']['tree'] }}</code></span>
                    @else
                        <span class="opacity-50">—</span>
                    @endif
                    @if ($row['attachment']['flat'] > 0)
                        <span class="badge badge-sm badge-ghost">
                            + {{ $row['attachment']['flat'] }} plate{{ $row['attachment']['flat'] > 1 ? 's' : '' }}
                        </span>
                    @endif
                </td>
                <td class="text-right whitespace-nowrap">
                    <button type="button" class="btn btn-ghost btn-xs" wire:click="moveUp({{ $row['id'] }})"
                        @disabled($index === 0) data-testid="move-up-{{ $row['key'] }}" aria-label="Monter">
                        <i class="fa-solid fa-arrow-up"></i>
                    </button>
                    <button type="button" class="btn btn-ghost btn-xs" wire:click="moveDown({{ $row['id'] }})"
                        @disabled($index === count($rows) - 1) data-testid="move-down-{{ $row['key'] }}" aria-label="Descendre">
                        <i class="fa-solid fa-arrow-down"></i>
                    </button>
                    <button type="button" class="btn btn-ghost btn-xs" wire:click="openEdit({{ $row['id'] }})"
                        data-testid="edit-type-{{ $row['key'] }}">
                        <i class="fa-solid fa-pen"></i> Modifier
                    </button>
                    <button type="button" class="btn btn-ghost btn-xs text-error"
                        wire:click="confirmDelete({{ $row['id'] }})" data-testid="delete-type-{{ $row['key'] }}">
                        <i class="fa-solid fa-trash"></i> Supprimer
                    </button>
                </td>
            </tr>
        @endforeach
    </x-organisms.data-table>

    {{-- La règle, énoncée AVANT qu'on ne la rencontre en exception. --}}
    <p class="text-xs text-base-content/60" data-testid="tree-attachment-note">
        Un type de groupe ne porte qu'une seule <strong>recette d'arborescence</strong>, matérialisée à la
        création d'un groupe ; les <strong>recettes plates</strong>, elles, peuvent être plusieurs. Les
        accrochages se lisent ici, ils ne s'y modifient pas encore.
    </p>

    {{-- Modale création / édition (composant maison réutilisable). --}}
    <x-molecules.modal wire:model="isModalOpen" size="max-w-xl" height="h-auto max-h-[90vh]"
        title="{{ $isEditing ? 'Modifier un type de groupe' : 'Ajouter un type de groupe' }}"
        icon="fa-layer-group text-primary" closeMethod="close">

        <x-molecules.modal.section title="Identité" icon="fa-circle-info text-primary" dense>
            <div class="flex flex-col gap-1 w-full">
                <label class="label" for="group-type-label">
                    <span class="label-text font-medium">
                        Libellé <span class="text-error" aria-hidden="true">*</span>
                    </span>
                </label>
                <input id="group-type-label" type="text" wire:model.live="label"
                    class="input input-bordered w-full" placeholder="Club" data-testid="field-type-label" />
                @error('label')
                    <span class="text-error text-xs">{{ $message }}</span>
                @enderror
            </div>

            <div class="flex flex-col gap-1 w-full mt-3">
                <label class="label" for="group-type-icon">
                    <span class="label-text font-medium">Icône</span>
                </label>
                <input id="group-type-icon" type="text" wire:model.live="icon" class="input input-bordered w-full"
                    placeholder="fa-solid fa-users" data-testid="field-type-icon" />
                <span class="text-xs opacity-60">
                    Classe <strong>Font Awesome</strong>, saisie librement. Laissée vide, l'icône générique
                    <code>{{ \App\Support\GroupTypeCatalog::DEFAULT_ICON }}</code> est utilisée.
                </span>
                <span class="text-sm mt-1">
                    Aperçu : <i class="{{ $this->previewIcon }}" data-testid="preview-type-icon" aria-hidden="true"></i>
                </span>
            </div>

            @if ($isEditing)
                <p class="text-xs text-base-content/60 mt-3">
                    La clé de ce type est <strong>immuable</strong> : les groupes qui la portent et les
                    arborescences qui s'y accrochent la référencent par cette valeur. Seuls le libellé et
                    l'icône changent.
                </p>
            @else
                <div class="flex flex-col gap-1 w-full mt-3">
                    <span class="label-text font-medium">Clé dérivée</span>
                    <code class="text-sm" data-testid="preview-type-key">{{ $this->previewKey ?: '—' }}</code>
                    <span class="text-xs opacity-60">
                        Générée depuis le libellé et <strong>figée à la création</strong> : c'est elle qui est
                        stockée sur chaque groupe. 50 caractères au maximum.
                    </span>
                </div>
            @endif
        </x-molecules.modal.section>

        <x-slot:footer>
            <button type="button" class="btn btn-ghost" wire:click="close">Annuler</button>
            <button type="button" class="btn btn-primary" wire:click="save" wire:loading.attr="disabled"
                wire:target="save" data-testid="save-type">
                <span wire:loading.remove wire:target="save"><i class="fa-solid fa-floppy-disk"></i> Enregistrer</span>
                <span wire:loading wire:target="save"><span class="loading loading-spinner loading-xs"></span> Enregistrement…</span>
            </button>
        </x-slot:footer>
    </x-molecules.modal>

    {{-- Confirmation de suppression : elle n'est proposée QUE si le type est
         réellement supprimable — le refus, lui, est un toast, jamais une modale
         qu'on pourrait valider. --}}
    <x-molecules.modal wire:model="isDeleteOpen" size="max-w-lg" height="h-auto max-h-[80vh]"
        title="Supprimer un type de groupe" icon="fa-triangle-exclamation text-error" closeMethod="closeDelete">

        <x-molecules.modal.section title="Confirmation" icon="fa-triangle-exclamation text-error" dense>
            <p class="text-sm">{{ $deleteWarning }}</p>
            <p class="text-sm mt-2">Confirmez-vous sa suppression du catalogue ?</p>
        </x-molecules.modal.section>

        <x-slot:footer>
            <button type="button" class="btn btn-ghost" wire:click="closeDelete">Annuler</button>
            <button type="button" class="btn btn-error" wire:click="delete" data-testid="confirm-delete-type">
                <i class="fa-solid fa-trash"></i> Supprimer
            </button>
        </x-slot:footer>
    </x-molecules.modal>
</div>

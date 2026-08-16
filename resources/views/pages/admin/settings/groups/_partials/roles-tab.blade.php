<?php

use App\Components\Traits\WithToasts;
use App\Models\GroupRole;
use App\Support\RoleCatalog;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Story 62.1 — onglet « Rôles » de /admin/settings/groups : LE CATALOGUE.
 *
 * Un rôle = une CLÉ immuable ⇔ un LIBELLÉ modifiable, plus un rang d'affichage.
 * La clé est ce qui est stocké sur l'arête d'appartenance et visé par les
 * recettes ; le libellé est ce qui se lit à l'écran. Renommer ne touche donc
 * jamais une donnée dérivée — c'est tout l'intérêt de la séparation.
 *
 * **La clé est dérivée du libellé à la CRÉATION, et figée là.** Prévisualisée
 * avant validation (patron des groupes de postes : slug figé, libellé saisi), elle
 * n'est plus modifiable ensuite — ni ici, ni par le modèle, qui lève.
 *
 * **La suppression REFUSE, elle ne cascade jamais.** Un rôle porté par des arêtes
 * ou visé par une recette n'est pas supprimable, et le refus NOMME le décompte.
 * Les trois rôles historiques ne sont pas supprimables du tout : leurs clés sont
 * écrites en littéral dans le code de SE5.
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
     * Identité du rôle édité (`null` = création).
     *
     * `#[Locked]` : sans ça, un payload forgé ferait porter l'enregistrement sur
     * un AUTRE rôle que celui ouvert.
     */
    #[Locked]
    public ?int $editId = null;

    public string $label = '';

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

    /** Le catalogue, trié par rang d'affichage, avec ses usages comptés. */
    public function loadRows(): void
    {
        $this->rows = GroupRole::orderBy('sort_order')->orderBy('id')->get()
            ->map(fn (GroupRole $role): array => [
                'id' => (int) $role->id,
                'key' => (string) $role->key,
                'label' => (string) $role->label,
                'sort_order' => (int) $role->sort_order,
                'protected' => $role->isProtected(),
                'usage' => $role->usage(),
            ])
            ->all();
    }

    /** Clé prévisualisée dans la modale de création (jamais en édition). */
    public function getPreviewKeyProperty(): string
    {
        return GroupRole::slugify($this->label);
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

        $role = GroupRole::find($id);
        if ($role === null) {
            $this->toastError('Rôle introuvable — la page a peut-être changé, rechargez.');

            return;
        }

        $this->resetForm();
        $this->isEditing = true;
        $this->editId = (int) $role->id;
        $this->label = (string) $role->label;
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
        $this->resetErrorBag();
    }

    public function save(): void
    {
        abort_unless(Gate::allows('server.admin'), 403);

        $this->validate(
            ['label' => ['required', 'string', 'max:100']],
            ['label.required' => 'Le libellé est requis.'],
        );

        if ($this->isEditing) {
            $role = GroupRole::find($this->editId);
            if ($role === null) {
                $this->toastError('Rôle introuvable — rechargez la page.');

                return;
            }

            // La CLÉ n'est jamais touchée ici : seule la colonne `label` change,
            // et c'est exactement ce que l'AC « un renommage ne touche aucune
            // donnée dérivée » exige.
            $role->label = trim($this->label);
            $role->save();

            $this->toastSuccess(sprintf('Le rôle se lit désormais « %s ».', (string) $role->label));
            $this->close();
            $this->loadRows();

            return;
        }

        $key = GroupRole::slugify($this->label);

        if ($key === '') {
            $this->addError('label', 'Ce libellé ne produit aucune clé utilisable : ajoutez au moins une lettre.');

            return;
        }

        if (GroupRole::where('key', $key)->exists()) {
            $this->addError('label', sprintf(
                'La clé « %s » est déjà prise par un rôle du catalogue. Choisissez un autre libellé.',
                $key,
            ));

            return;
        }

        try {
            $role = GroupRole::create([
                'key' => $key,
                'label' => trim($this->label),
                'sort_order' => ((int) GroupRole::max('sort_order')) + 1,
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            // Review 62.1 #3 — le contrôle d'unicité ci-dessus est un
            // check-then-act : deux soumissions concurrentes du même libellé
            // (double-clic, deux onglets) le passent toutes les deux et se
            // disputent la contrainte unique en base. Sans ce catch dédié, la
            // perdante affichait le SQLSTATE brut dans le champ « libellé ». Elle
            // reçoit le même message métier que le contrôle préalable.
            $this->addError('label', sprintf(
                'La clé « %s » est déjà prise par un rôle du catalogue. Choisissez un autre libellé.',
                $key,
            ));

            return;
        } catch (\Throwable $e) {
            // Garde du modèle (format, longueur) : message métier, jamais un 500.
            $this->addError('label', $e->getMessage());

            return;
        }

        $this->toastSuccess(sprintf('Le rôle « %s » a été créé (clé « %s »).', (string) $role->label, $key));
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
     * Échange le rang de ce rôle avec celui de son voisin.
     *
     * Monter/descendre suffit : un tri par glisser-déposer coûterait une
     * dépendance et un état client pour un catalogue qui tient sur un écran.
     */
    private function swapWithNeighbour(int $id, int $direction): void
    {
        abort_unless(Gate::allows('server.admin'), 403);

        $ordered = GroupRole::orderBy('sort_order')->orderBy('id')->get()->values();
        $index = $ordered->search(fn (GroupRole $role): bool => (int) $role->id === $id);

        if ($index === false) {
            $this->toastError('Rôle introuvable — rechargez la page.');

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

        foreach ($reordered as $position => $role) {
            $role->sort_order = $position + 1;
            $role->save();
        }

        RoleCatalog::flush();
        $this->loadRows();
    }

    // --- Suppression ---------------------------------------------------------

    public function confirmDelete(int $id): void
    {
        abort_unless(Gate::allows('server.admin'), 403);

        $role = GroupRole::find($id);
        if ($role === null) {
            $this->toastError('Rôle introuvable — rechargez la page.');

            return;
        }

        $refusal = $role->deletionRefusal();
        if ($refusal !== null) {
            // Refus NOMMÉ, et AUCUNE écriture : ni sur le rôle, ni sur les
            // arêtes, ni sur les recettes. Jamais de cascade.
            $this->toastError($refusal);

            return;
        }

        $this->deleteId = (int) $role->id;
        $this->deleteLabel = (string) $role->label;
        $this->deleteWarning = sprintf(
            'Le rôle « %s » (clé « %s ») n\'est porté par aucune appartenance et visé par aucune recette.',
            (string) $role->label,
            (string) $role->key,
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

        $role = GroupRole::find($this->deleteId);
        if ($role === null) {
            $this->toastError('Rôle introuvable — rechargez la page.');
            $this->closeDelete();

            return;
        }

        // Re-vérification côté serveur : entre l'ouverture de la confirmation et
        // le clic, une arête a pu naître.
        $refusal = $role->deletionRefusal();
        if ($refusal !== null) {
            $this->toastError($refusal);
            $this->closeDelete();

            return;
        }

        $label = (string) $role->label;
        $role->delete();

        $this->toastSuccess(sprintf('Le rôle « %s » a été supprimé du catalogue.', $label));
        $this->closeDelete();
        $this->loadRows();
    }
};
?>

<div class="flex flex-col gap-6">

    <div class="flex items-start justify-end gap-4 flex-wrap">
        <button type="button" class="btn highlight btn-primary" wire:click="openCreate" data-testid="open-create-role">
            <i class="fa-solid fa-plus"></i> Ajouter un rôle
        </button>
    </div>

    <x-organisms.data-table
        colgroup="<colgroup><col style='width: 22%'><col style='width: 20%'><col style='width: 34%'><col style='width: 24%'></colgroup>">
        <x-slot:header>
            <th>Libellé</th>
            <th>Clé</th>
            <th>Usages</th>
            <th class="text-right">Actions</th>
        </x-slot:header>
        @foreach ($rows as $index => $row)
            <tr wire:key="group-role-{{ $row['id'] }}" data-testid="role-row-{{ $row['key'] }}">
                <td class="font-bold">
                    {{ $row['label'] }}
                    @if ($row['protected'])
                        <span class="badge badge-sm badge-ghost ml-2">structurel</span>
                    @endif
                </td>
                <td><code class="text-xs opacity-70">{{ $row['key'] }}</code></td>
                <td class="text-sm">
                    <span class="badge badge-sm badge-outline">{{ $row['usage']['edges'] }} appartenance{{ $row['usage']['edges'] > 1 ? 's' : '' }}</span>
                    <span class="badge badge-sm badge-outline">{{ $row['usage']['templates'] }} recette{{ $row['usage']['templates'] > 1 ? 's' : '' }}</span>
                    {{-- Story 62.3 — plus un usage observé sur les arêtes, mais le
                         nombre de types qui DÉCLARENT ce rôle. --}}
                    <span class="badge badge-sm badge-outline">déclaré par {{ $row['usage']['group_types'] }} type{{ $row['usage']['group_types'] > 1 ? 's' : '' }}</span>
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
                        data-testid="edit-{{ $row['key'] }}">
                        <i class="fa-solid fa-pen"></i> Modifier
                    </button>
                    <button type="button" class="btn btn-ghost btn-xs text-error"
                        wire:click="confirmDelete({{ $row['id'] }})" data-testid="delete-{{ $row['key'] }}">
                        <i class="fa-solid fa-trash"></i> Supprimer
                    </button>
                </td>
            </tr>
        @endforeach
    </x-organisms.data-table>

    {{-- Modale création / édition (composant maison réutilisable). --}}
    <x-molecules.modal wire:model="isModalOpen" size="max-w-xl" height="h-auto max-h-[90vh]"
        title="{{ $isEditing ? 'Modifier un rôle' : 'Ajouter un rôle' }}" icon="fa-user-tag text-primary"
        closeMethod="close">

        <x-molecules.modal.section title="Identité" icon="fa-circle-info text-primary" dense>
            <div class="flex flex-col gap-1 w-full">
                <label class="label" for="group-role-label">
                    <span class="label-text font-medium">
                        Libellé <span class="text-error" aria-hidden="true">*</span>
                    </span>
                </label>
                <input id="group-role-label" type="text" wire:model.live="label"
                    class="input input-bordered w-full" placeholder="Tuteur" data-testid="field-role-label" />
                @error('label')
                    <span class="text-error text-xs">{{ $message }}</span>
                @enderror
                {{-- Ce libellé n'est qu'un DÉFAUT : un type de groupe peut le
                     surcharger. Sans cet avertissement, on renomme ici en croyant
                     avoir renommé partout — l'écran ne montre aucune surcharge. --}}
                <p class="text-xs text-base-content/60" data-testid="hint-role-label-translated">
                    Ce libellé peut être <strong>traduit par type de groupe</strong> : un
                    « Gestionnaire » se lit « Enseignant » dans une classe, « Porteur » dans un
                    projet. Ces traductions s'administrent dans l'onglet
                    <strong>« Types de groupes »</strong>.
                </p>
            </div>

            @if ($isEditing)
                <p class="text-xs text-base-content/60 mt-3">
                    La clé de ce rôle est <strong>immuable</strong> : les appartenances et les arborescences qui
                    la portent la référencent par cette valeur. Seul le libellé change.
                </p>
            @else
                <div class="flex flex-col gap-1 w-full mt-3">
                    <span class="label-text font-medium">Clé dérivée</span>
                    <code class="text-sm" data-testid="preview-role-key">{{ $this->previewKey ?: '—' }}</code>
                    <span class="text-xs opacity-60">
                        Générée depuis le libellé et <strong>figée à la création</strong> : c'est elle qui est
                        stockée sur chaque appartenance. 20 caractères au maximum.
                    </span>
                </div>
            @endif
        </x-molecules.modal.section>

        <x-slot:footer>
            <button type="button" class="btn btn-ghost" wire:click="close">Annuler</button>
            <button type="button" class="btn btn-primary" wire:click="save" wire:loading.attr="disabled"
                wire:target="save" data-testid="save-role">
                <span wire:loading.remove wire:target="save"><i class="fa-solid fa-floppy-disk"></i> Enregistrer</span>
                <span wire:loading wire:target="save"><span class="loading loading-spinner loading-xs"></span> Enregistrement…</span>
            </button>
        </x-slot:footer>
    </x-molecules.modal>

    {{-- Confirmation de suppression : elle n'est proposée QUE si le rôle est
         réellement supprimable — le refus, lui, est un toast, jamais une modale
         qu'on pourrait valider. --}}
    <x-molecules.modal wire:model="isDeleteOpen" size="max-w-lg" height="h-auto max-h-[80vh]"
        title="Supprimer un rôle" icon="fa-triangle-exclamation text-error" closeMethod="closeDelete">

        <x-molecules.modal.section title="Confirmation" icon="fa-triangle-exclamation text-error" dense>
            <p class="text-sm">{{ $deleteWarning }}</p>
            <p class="text-sm mt-2">Confirmez-vous sa suppression du catalogue ?</p>
        </x-molecules.modal.section>

        <x-slot:footer>
            <button type="button" class="btn btn-ghost" wire:click="closeDelete">Annuler</button>
            <button type="button" class="btn btn-error" wire:click="delete" data-testid="confirm-delete-role">
                <i class="fa-solid fa-trash"></i> Supprimer
            </button>
        </x-slot:footer>
    </x-molecules.modal>
</div>

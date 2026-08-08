<?php

use App\Components\Traits\WithToasts;
use App\Models\GroupType;
use App\Models\GroupTypeRole;
use App\Support\GroupTypeCatalog;
use App\Support\RoleCatalog;
use Illuminate\Support\Facades\DB;
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

    // --- Rôles déclarés par le type (story 62.3) ------------------------------

    /**
     * Clés de rôle COCHÉES dans la modale d'édition.
     *
     * Propriété publique de tableau, pilotée par `wire:model` sur les cases et par
     * `x-molecules.select-all-checkbox` en tête — jamais `@checked` + `wire:click`
     * (convention maison : le composant de sélection globale écrit les PROPRIÉTÉS
     * DOM par `x-effect`, et le morph Livewire ne resynchronise que l'attribut).
     *
     * @var array<int, string>
     */
    public array $selectedRoleKeys = [];

    /**
     * Libellés LOCAUX saisis, `clé de rôle => texte` (vide = pas de surcharge).
     *
     * @var array<string, string>
     */
    public array $roleLabels = [];

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
                // Story 62.3 — ce que le type DÉCLARE, dans l'ordre du catalogue
                // de rôles. Vide = régime de repli (tous les rôles disponibles),
                // rendu « — » à l'écran.
                'declared_roles' => $this->declaredRolesOf((string) $type->key),
            ])
            ->all();
    }

    /**
     * Les rôles déclarés par un type, prêts à afficher.
     *
     * Le libellé montré est le LOCAL s'il existe, sinon celui du catalogue : c'est
     * exactement ce que la résolution rendra à l'écran d'un groupe de ce type.
     *
     * @return list<array{key: string, label: string, local: bool}>
     */
    private function declaredRolesOf(string $typeKey): array
    {
        $declared = GroupTypeRole::declaredFor($typeKey);
        $catalog = RoleCatalog::rows();

        $rows = [];
        foreach (array_keys($catalog) as $roleKey) {
            if (! array_key_exists($roleKey, $declared)) {
                continue;
            }

            $local = $declared[$roleKey];
            $rows[] = [
                'key' => $roleKey,
                'label' => $local ?? ($catalog[$roleKey] ?? $roleKey),
                'local' => $local !== null,
            ];
        }

        return $rows;
    }

    /**
     * Le catalogue de rôles proposé dans la modale, `clé => libellé du catalogue`.
     *
     * C'est le libellé GÉNÉRIQUE qui sert de placeholder au champ « Libellé dans
     * ce type » : l'admin voit ce qui s'appliquera s'il ne saisit rien.
     *
     * @return array<string, string>
     */
    public function getRoleCatalogProperty(): array
    {
        return RoleCatalog::rows();
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

        // Story 62.3 — l'état des déclarations, lu sur la clé EXACTE de la ligne
        // éditée : on édite CETTE ligne du catalogue, pas ce que la résolution
        // apparierait (une ligne héritée `Custom` ne se voit pas attribuer les
        // déclarations de `custom`).
        $declared = GroupTypeRole::declaredFor((string) $type->key);
        $this->selectedRoleKeys = array_values(array_filter(
            RoleCatalog::keys(),
            static fn (string $roleKey): bool => array_key_exists($roleKey, $declared),
        ));
        $this->roleLabels = [];
        foreach (RoleCatalog::keys() as $roleKey) {
            $this->roleLabels[$roleKey] = (string) ($declared[$roleKey] ?? '');
        }

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
        $this->selectedRoleKeys = [];
        $this->roleLabels = [];
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

            // Story 62.3 — les REFUS de retrait sont évalués AVANT toute écriture.
            // Ce n'est pas une optimisation : l'AC exige le tout-ou-rien sur la
            // soumission ENTIÈRE — ni les retraits, ni les ajouts, ni les libellés
            // locaux, ni même le renommage du type ne doivent passer si l'un des
            // retraits est refusé. Un refus tardif, après écriture partielle,
            // serait pire que pas de refus du tout.
            $refusal = $this->declarationRemovalRefusal($type);
            if ($refusal !== null) {
                $this->toastError($refusal);

                return;
            }

            // La CLÉ n'est jamais touchée ici : seules `label` et `icon`
            // changent, et c'est exactement ce que l'AC « un renommage ne touche
            // aucune donnée dérivée » exige.
            $type->label = trim($this->label);
            $type->icon = $icon;

            try {
                DB::transaction(function () use ($type): void {
                    $type->save();
                    $this->applyRoleDeclarations($type);
                });
            } catch (\Illuminate\Database\QueryException $e) {
                // Le contrôle d'existence de `applyRoleDeclarations()` est un
                // check-then-act : deux soumissions concurrentes (double-clic,
                // deux onglets) déclarant la même paire se disputent l'index
                // unique composite. La perdante reçoit un message métier, jamais
                // un SQLSTATE brut (leçon de la review 62.1 #3). La transaction a
                // déjà tout annulé.
                $this->addError('label', sprintf(
                    'Les rôles de ce type viennent d\'être modifiés ailleurs (%s). Rouvrez la fenêtre pour '
                    . 'repartir de l\'état courant.',
                    (string) $type->key,
                ));

                return;
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

    // --- Déclarations de rôles (story 62.3) -----------------------------------

    /**
     * Les clés de rôle retenues par la soumission, dans l'ordre du CATALOGUE.
     *
     * Le tableau qui arrive du client est filtré contre le catalogue : un payload
     * forgé ne peut donc déclarer qu'un rôle qui existe, et l'ordre stocké ne
     * dépend pas de l'ordre de cochage.
     *
     * @return list<string>
     */
    private function submittedRoleKeys(): array
    {
        $submitted = array_map('strval', $this->selectedRoleKeys);

        return array_values(array_filter(
            RoleCatalog::keys(),
            static fn (string $roleKey): bool => in_array($roleKey, $submitted, true),
        ));
    }

    /**
     * Le premier refus de retrait de cette soumission, ou `null`.
     *
     * Évalué AVANT toute écriture (voir `save()`) : c'est ce qui rend le
     * tout-ou-rien vrai, et pas seulement annoncé.
     */
    private function declarationRemovalRefusal(GroupType $type): ?string
    {
        $kept = $this->submittedRoleKeys();

        $existing = GroupTypeRole::where('group_type_key', (string) $type->key)->get();

        foreach ($existing as $declaration) {
            if (in_array((string) $declaration->group_role_key, $kept, true)) {
                continue;
            }

            $refusal = $declaration->removalRefusal();
            if ($refusal !== null) {
                return $refusal;
            }
        }

        return null;
    }

    /**
     * Applique le delta (retraits, ajouts, libellés locaux) — sous transaction.
     *
     * Les retraits refusés ont déjà été écartés par
     * {@see self::declarationRemovalRefusal()} ; ceux qui restent ne portent
     * aucune appartenance.
     */
    private function applyRoleDeclarations(GroupType $type): void
    {
        $typeKey = (string) $type->key;
        $kept = $this->submittedRoleKeys();

        $existing = GroupTypeRole::where('group_type_key', $typeKey)->get()
            ->keyBy(fn (GroupTypeRole $declaration): string => (string) $declaration->group_role_key);

        foreach ($existing as $roleKey => $declaration) {
            if (! in_array((string) $roleKey, $kept, true)) {
                $declaration->delete();
            }
        }

        foreach ($kept as $roleKey) {
            $local = trim((string) ($this->roleLabels[$roleKey] ?? ''));

            $declaration = $existing[$roleKey] ?? new GroupTypeRole([
                'group_type_key' => $typeKey,
                'group_role_key' => $roleKey,
            ]);

            // Le modèle normalise déjà `''` → `null` ; on le passe tel quel pour
            // que la règle vive à UN seul endroit.
            $declaration->label = $local;
            $declaration->save();
        }

        RoleCatalog::flush();
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
        colgroup="<colgroup><col style='width: 20%'><col style='width: 13%'><col style='width: 12%'><col style='width: 22%'><col style='width: 16%'><col style='width: 17%'></colgroup>">
        <x-slot:header>
            <th>Libellé</th>
            <th>Clé</th>
            <th>Groupes</th>
            <th>Rôles disponibles</th>
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
                {{-- Story 62.3 — le vocabulaire déclaré, lu tel qu'il s'affichera
                     sur un groupe de ce type (libellé local sinon catalogue). Un
                     type sans déclaration montre « — » : tout le catalogue lui est
                     disponible, il n'a rien restreint. --}}
                <td class="text-sm" data-testid="declared-roles-{{ $row['key'] }}">
                    @forelse ($row['declared_roles'] as $declaredRole)
                        <span class="badge badge-sm {{ $declaredRole['local'] ? 'badge-primary badge-outline' : 'badge-ghost' }}">
                            {{ $declaredRole['label'] }}
                        </span>
                    @empty
                        <span class="opacity-50">—</span>
                    @endforelse
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

        {{-- Story 62.3 — LES RÔLES DISPONIBLES DANS CE TYPE.

             La section n'existe qu'à l'ÉDITION : un type neuf naît sans
             déclaration, donc en régime de repli, et proposer de déclarer avant
             que la clé n'existe demanderait d'écrire des lignes sur un type qui
             pourrait ne jamais être créé. La modale de création reste intouchée.

             Tout y est OPTIONNEL — aucune étoile d'obligatoire : ne rien cocher
             est un état légitime et documenté juste sous la liste. --}}
        @if ($isEditing)
            <x-molecules.modal.section title="Rôles disponibles" icon="fa-user-tag text-primary" dense>
                <p class="text-xs text-base-content/70">
                    Cochez les rôles qui ont un sens dans ce type de groupe. Pour chacun, vous pouvez donner
                    un <strong>libellé local</strong> — c'est ainsi que le rôle se lira sur les groupes de ce
                    type (un « Gestionnaire » se dit « Enseignant » dans une classe).
                </p>

                <div class="flex items-center gap-2 mt-3 pb-2 border-b border-base-300">
                    <x-molecules.select-all-checkbox :ids="array_keys($this->roleCatalog)" model="selectedRoleKeys"
                        class="checkbox-sm" data-testid="select-all-roles" aria-label="Tous les rôles" />
                    <span class="text-xs font-medium opacity-70">Tous les rôles du catalogue</span>
                </div>

                <div class="flex flex-col gap-3 mt-3">
                    @foreach ($this->roleCatalog as $roleKey => $roleLabel)
                        <div class="flex flex-col gap-1" wire:key="declare-role-{{ $roleKey }}">
                            <label class="label cursor-pointer justify-start gap-2 p-0">
                                <input type="checkbox" class="checkbox checkbox-sm checkbox-primary"
                                    value="{{ $roleKey }}" wire:model.live="selectedRoleKeys"
                                    data-testid="declare-role-{{ $roleKey }}" />
                                <span class="label-text font-medium">{{ $roleLabel }}</span>
                                <code class="text-xs opacity-50">{{ $roleKey }}</code>
                            </label>

                            @if (in_array((string) $roleKey, array_map('strval', $selectedRoleKeys), true))
                                <div class="flex flex-col gap-1 w-full pl-7">
                                    <label class="label" for="role-local-label-{{ $roleKey }}">
                                        <span class="label-text text-xs opacity-70">Libellé dans ce type</span>
                                    </label>
                                    <input id="role-local-label-{{ $roleKey }}" type="text"
                                        wire:model.live="roleLabels.{{ $roleKey }}"
                                        class="input input-bordered input-sm w-full"
                                        placeholder="{{ $roleLabel }}"
                                        data-testid="role-local-label-{{ $roleKey }}" />
                                    <span class="text-xs opacity-60">
                                        Laissé vide, le libellé du catalogue (« {{ $roleLabel }} ») s'applique.
                                    </span>
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>

                @if ($selectedRoleKeys === [])
                    {{-- Le régime de repli est DIT avant d'enregistrer, pas
                         découvert après : cocher un seul rôle fait basculer le
                         type d'« ouvert à tout » à « restreint à ce qui est
                         déclaré ». --}}
                    <p class="text-xs text-warning mt-3" data-testid="no-declaration-notice">
                        <i class="fa-solid fa-circle-info" aria-hidden="true"></i>
                        Sans déclaration, tous les rôles du catalogue sont disponibles avec leur libellé
                        générique. Cocher au moins un rôle <strong>restreint</strong> ce type aux rôles cochés.
                    </p>
                @endif

                <p class="text-xs text-base-content/60 mt-3">
                    Retirer un rôle porté par des appartenances existantes est <strong>refusé</strong> : le
                    message vous dira combien. Rien n'est enregistré tant que ce refus tient — libellés
                    compris.
                </p>
            </x-molecules.modal.section>
        @endif

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

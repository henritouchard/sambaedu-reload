{{--
    Story 62.6 — l'IDENTITÉ de la recette d'arbre.

    En création : la clé (slug figé, patron des catalogues 62.1/62.2), le libellé,
    le motif de chemin et la zone. En édition : la clé est immuable et ne se
    propose pas. L'accrochage au type vient du CONTEXTE d'ouverture — il n'est
    jamais re-saisi.
--}}
<x-molecules.modal.section title="Identité de l'arborescence" icon="fa-circle-info text-primary" dense>

    @error('tree')
        <div class="alert alert-error text-sm mb-3" data-testid="tree-error">
            <i class="fa-solid fa-triangle-exclamation"></i>
            <span>{{ $message }}</span>
        </div>
    @enderror

    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <div class="flex flex-col gap-1 w-full">
            <label class="label" for="tree-label">
                <span class="label-text font-medium">
                    Libellé <span class="text-error" aria-hidden="true">*</span>
                </span>
            </label>
            <input id="tree-label" type="text" wire:model.live="label" class="input input-bordered w-full"
                placeholder="Classe (arbre de partage)" data-testid="field-tree-label" />
            @error('label')
                <span class="text-error text-xs">{{ $message }}</span>
            @enderror
        </div>

        <div class="flex flex-col gap-1 w-full">
            @if ($editId === null)
                <label class="label" for="tree-key">
                    <span class="label-text font-medium">Clé</span>
                </label>
                <input id="tree-key" type="text" wire:model.live="newKey" class="input input-bordered w-full"
                    placeholder="dérivée du libellé" data-testid="field-tree-key" />
                <span class="text-xs opacity-60">
                    Clé retenue : <code data-testid="preview-tree-key">{{ $this->previewKey ?: '—' }}</code> —
                    <strong>figée à la création</strong>.
                </span>
                @error('newKey')
                    <span class="text-error text-xs">{{ $message }}</span>
                @enderror
            @else
                <span class="label-text font-medium">Clé</span>
                <p class="text-sm opacity-70">
                    La clé de cette recette est <strong>immuable</strong> : elle est référencée par les répertoires
                    déjà matérialisés.
                </p>
            @endif
        </div>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-4">
        <div class="flex flex-col gap-1 w-full">
            <label class="label" for="tree-pattern">
                <span class="label-text font-medium">
                    Motif de chemin <span class="text-error" aria-hidden="true">*</span>
                </span>
            </label>
            <input id="tree-pattern" type="text" wire:model.live="pathPattern" class="input input-bordered w-full"
                placeholder="Classe_{group.bare_name}" data-testid="field-tree-pattern" />
            <div class="flex flex-wrap gap-1 mt-1">
                @foreach (\App\Models\DirectoryTemplate::TREE_PLACEHOLDERS as $token)
                    <button type="button" class="btn btn-ghost btn-xs font-mono"
                        wire:click="insertPatternPlaceholder('{{ $token }}')"
                        data-testid="pattern-placeholder-{{ $token }}">
                        &#123;{{ $token }}&#125;
                    </button>
                @endforeach
            </div>
            @error('pathPattern')
                <span class="text-error text-xs">{{ $message }}</span>
            @enderror
        </div>

        <div class="flex flex-col gap-1 w-full">
            <label class="label" for="tree-anchor">
                <span class="label-text font-medium">Zone</span>
            </label>
            <select id="tree-anchor" wire:model.live="rootAnchor" class="select select-bordered w-full"
                data-testid="field-tree-anchor">
                @foreach ($this->anchorOptions as $value => $anchorLabel)
                    <option value="{{ $value }}">{{ $anchorLabel }}</option>
                @endforeach
            </select>
            <span class="text-xs opacity-60">
                La zone logique dans laquelle cette arborescence vit. Ce n'est pas un chemin : l'emplacement réel
                est un savoir du serveur.
            </span>
        </div>
    </div>
</x-molecules.modal.section>

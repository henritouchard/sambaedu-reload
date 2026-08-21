{{--
    L'IDENTITÉ de la recette et sa place.

    En création : la clé (slug figé), le libellé, le motif de chemin et la zone.
    En édition : la clé est immuable et ne se propose pas. L'accrochage au type
    vient de l'URL — il n'est jamais re-saisi.
--}}
<div class="card bg-base-100 shadow-sm border border-base-300">
    <div class="card-body p-5 gap-4">

        <h2 class="font-semibold flex items-center gap-2">
            <i class="fa-solid fa-circle-info text-primary"></i> Identité et emplacement
        </h2>

        <div class="grid grid-cols-1 lg:grid-cols-4 gap-4">

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
                    <span class="input input-bordered w-full bg-base-200 font-mono text-sm items-center"
                        data-testid="frozen-tree-key">{{ $this->previewKey }}</span>
                    <span class="text-xs opacity-60">
                        <strong>Immuable</strong> : référencée par les répertoires déjà matérialisés.
                    </span>
                @endif
            </div>

            <div class="flex flex-col gap-1 w-full">
                <label class="label" for="tree-pattern">
                    <span class="label-text font-medium">
                        Motif de chemin <span class="text-error" aria-hidden="true">*</span>
                    </span>
                </label>
                <input id="tree-pattern" type="text" wire:model.live="pathPattern" class="input input-bordered w-full font-mono"
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
    </div>
</div>

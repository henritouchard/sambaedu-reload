{{--
    Story 15.4 / AC3 — Bulk catégorie : sélecteur catégorie + cible profil +
    preview avant confirmation. Hosté dans la page parc (target_type='group').
--}}
@php
    $previewCount = count($bulkPreviewAppIds);
@endphp
<div class="modal modal-open">
    <div class="modal-box max-w-3xl">
        <h3 class="font-bold text-lg mb-4">
            <i class="fa-solid fa-tags mr-2"></i>
            Assignation par catégorie
        </h3>

        <div class="space-y-4">
            <div class="form-control">
                <label class="label">
                    <span class="label-text">Catégorie</span>
                </label>
                <select class="select select-bordered" wire:model.live="bulkCategory"
                    wire:change="previewBulkCategory">
                    <option value="">— Sélectionnez une catégorie —</option>
                    @foreach ($this->wpkgCategories as $cat)
                        <option value="{{ $cat }}">{{ $cat }}</option>
                    @endforeach
                </select>
            </div>

            @if ($bulkCategory !== '')
                <div class="alert alert-info">
                    <i class="fa-solid fa-circle-info"></i>
                    <span>{{ $previewCount }} application(s) trouvée(s) dans la catégorie « {{ $bulkCategory }} ».</span>
                </div>
            @endif

            <div class="form-control">
                <label class="label">
                    <span class="label-text">Profil cible</span>
                </label>
                <div class="flex gap-3 items-center">
                    <label class="cursor-pointer flex items-center gap-2">
                        <input type="radio" class="radio radio-primary radio-sm"
                            wire:model.live="bulkProfileMode" value="create" />
                        <span>Créer un nouveau profil</span>
                    </label>
                    <label class="cursor-pointer flex items-center gap-2">
                        <input type="radio" class="radio radio-primary radio-sm"
                            wire:model.live="bulkProfileMode" value="existing" />
                        <span>Ajouter à un profil existant</span>
                    </label>
                </div>
            </div>

            @if ($bulkProfileMode === 'create')
                <div class="form-control">
                    <label class="label">
                        <span class="label-text">Nom du nouveau profil</span>
                    </label>
                    <input type="text" wire:model="bulkNewProfileName" class="input input-bordered"
                        placeholder="Categorie-{{ $bulkCategory ?: 'Nom' }}" />
                </div>
            @else
                <div class="form-control">
                    <label class="label">
                        <span class="label-text">Profil existant</span>
                    </label>
                    <select class="select select-bordered" wire:model="bulkExistingProfileId">
                        <option value="">— Sélectionnez —</option>
                        {{-- Story 15.4 / Correction post-review #M1 : computed
                             property du composant Livewire parent (via @include
                             $this reste accessible) — supprime la query
                             Eloquent inline anti-pattern. --}}
                        @foreach ($this->bulkProfileOptions as $p)
                            <option value="{{ $p->id }}">{{ $p->name }}</option>
                        @endforeach
                    </select>
                </div>
            @endif
        </div>

        <div class="modal-action">
            <button type="button" class="btn" wire:click="closeBulkCategoryModal">Annuler</button>
            <button type="button" class="btn btn-primary"
                wire:click="executeBulkCategory"
                @disabled($bulkCategory === '' || $previewCount === 0)>
                <i class="fa-solid fa-check mr-1"></i>
                Confirmer
            </button>
        </div>
    </div>
    <div class="modal-backdrop bg-black/50" wire:click="closeBulkCategoryModal"></div>
</div>

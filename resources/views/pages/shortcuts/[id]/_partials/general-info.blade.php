<!-- Informations générales -->
<div class="card bg-base-100 shadow-sm border border-base-300">
    <div class="card-body">
        <h3 class="card-title mb-4 flex gap-4">
            <i class="fa-solid fa-gear"></i>
            Informations générales
        </h3>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <!-- Nom du raccourci -->
            <div class="form-control flex flex-col">
                <label class="label">
                    <span class="label-text font-semibold">Nom du raccourci <span class="text-error">*</span></span>
                </label>
                <input type="text" wire:model="name" placeholder="Ex: Firefox, LibreOffice..."
                    class="input input-bordered @error('name') input-error @enderror" required>
                @error('name')
                    <label class="label">
                        <span class="label-text-alt text-error">{{ $message }}</span>
                    </label>
                @enderror
            </div>

            <!-- Emplacement -->
            <div class="form-control flex flex-col">
                <label class="label">
                    <span class="label-text font-semibold">Emplacement <span class="text-error">*</span></span>
                </label>
                <select wire:model="place" class="select select-bordered @error('place') select-error @enderror"
                    required>
                    @foreach ($filters['place'] as $value => $label)
                        @if ($value !== 'all')
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endif
                    @endforeach
                </select>
                @error('place')
                    <label class="label">
                        <span class="label-text-alt text-error">{{ $message }}</span>
                    </label>
                @enderror
            </div>


            <!-- Icône personnalisée -->
            <div class="form-control md:col-span-2">
                <x-atoms.tooltip color="" position="top" icon="true">
                    <x-slot name="label">
                        <span class="label-text font-semibold">Icône personnalisée</span>
                    </x-slot>
                    Formats autorisés : PNG, JPG, GIF
                    <br>Recommandations :
                    <br>• Image carrée
                    <br>• Fond transparent
                </x-atoms.tooltip>

                <!-- Zone de prévisualisation -->
                <div class="flex items-center gap-4">
                    <div class="w-16 h-16 rounded-lg bg-base-200 flex items-center justify-center">
                        @if ($icon_file)
                            <img src="{{ $icon_file->temporaryUrl() }}" alt="Aperçu icône"
                                class="w-12 h-12 object-contain">
                        @else
                            <img src="{{ $this->getShortcutIconUrl() }}" alt="Aperçu icône"
                                class="w-12 h-12 object-contain" onerror="this.src='/elements/images/system-run.png'">
                        @endif
                    </div>
                    <div class="flex-1">
                        <x-atoms.fileInput wire-model="icon_file"
                            accept="image/png,image/jpeg,image/gif,image/x-icon,image/svg+xml" label="Choisir une icône"
                            icon="fa-solid fa-image" />
                    </div>
                </div>

                @error('icon_file')
                    <label class="label">
                        <span class="label-text-alt text-error">{{ $message }}</span>
                    </label>
                @enderror
            </div>
        </div>
    </div>
</div>

@php $creating = $creating ?? false; @endphp

<div class="card bg-base-100 shadow-sm border border-base-200">
    <div class="card-body">
        <!-- Header avec icône, nom et boutons édition -->
        <div class="flex items-center justify-between mb-4">
            <div class="flex items-center gap-4">
                <!-- Icône -->
                <div class="w-14 h-14 rounded-lg bg-base-200 flex items-center justify-center shrink-0">
                    @if ($icon_file)
                        <img src="{{ $icon_file->temporaryUrl() }}" alt="Aperçu" class="w-10 h-10 object-contain">
                    @elseif ($creating)
                        <i class="fa-solid fa-plus text-2xl text-base-content/30"></i>
                    @else
                        <img src="{{ $this->getShortcutIconUrl() }}" alt="{{ $name }}"
                            class="w-10 h-10 object-contain" onerror="this.src='/elements/images/system-run.png'">
                    @endif
                </div>
                <div>
                    @if ($creating)
                        <h2 class="text-xl font-bold">Nouveau raccourci</h2>
                    @else
                        <h2 class="text-xl font-bold">{{ $name }}</h2>
                        <div class="flex items-center gap-2 mt-1">
                            @if ($this->isUrlShortcut())
                                <span class="badge badge-info badge-sm">Site web</span>
                            @else
                                <span class="badge badge-success badge-sm">Application</span>
                            @endif
                            <span class="badge badge-outline badge-sm">{{ $placeLabels[$place] ?? 'Bureau' }}</span>
                            <span class="font-mono text-xs text-base-content/40">{{ $key }}</span>
                        </div>
                    @endif
                </div>
            </div>

            <!-- Boutons -->
            @if ($creating)
                <div class="flex gap-2">
                    <a href="{{ route('app.shortcuts') }}" class="btn btn-ghost btn-sm">
                        <i class="fa-solid fa-xmark"></i>
                        Annuler
                    </a>
                    <button type="submit" class="btn btn-primary btn-sm">
                        <i class="fa-solid fa-plus"></i>
                        Créer
                    </button>
                </div>
            @elseif (!$isGlobal)
                <div class="flex gap-2">
                    @if ($editing || $creating)
                        <button type="button" wire:click="cancelEdit" class="btn btn-ghost btn-sm">
                            <i class="fa-solid fa-xmark"></i>
                            Annuler
                        </button>
                        <button type="submit" class="btn btn-primary btn-sm">
                            <i class="fa-solid fa-check"></i>
                            Enregistrer
                        </button>
                    @else
                        <button type="button" wire:click="startEdit" class="btn btn-ghost btn-sm">
                            <i class="fa-solid fa-pen"></i>
                            Modifier
                        </button>
                    @endif
                </div>
            @endif
        </div>

        @if (!$creating && $isGlobal)
            <div class="alert alert-warning alert-sm mb-4">
                <i class="fa-solid fa-lock"></i>
                <span>Ce raccourci est géré par le ControlHub et ne peut pas être modifié ici.</span>
            </div>
        @endif

        <!-- Informations générales -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-x-6 gap-y-4 mb-6">
            <!-- Nom -->
            <div class="form-control flex flex-col">
                <label class="label py-1">
                    <span class="label-text font-semibold">Nom du raccourci</span>
                </label>
                @if ($editing || $creating)
                    <input type="text" wire:model="name" placeholder="Ex: Firefox, LibreOffice..."
                        class="input input-bordered input-sm @error('name') input-error @enderror" required>
                    @error('name')
                        <span class="text-xs text-error mt-1">{{ $message }}</span>
                    @enderror
                @else
                    <span class="text-sm py-1">{{ $name ?: '—' }}</span>
                @endif
            </div>

            <!-- Emplacement -->
            <div class="form-control flex flex-col">
                <label class="label py-1">
                    <span class="label-text font-semibold">Emplacement</span>
                </label>
                @if ($editing || $creating)
                    <select wire:model="place" class="select select-bordered select-sm" required>
                        @foreach ($filters['place'] as $value => $label)
                            @if ($value !== 'all')
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endif
                        @endforeach
                    </select>
                @else
                    <span class="text-sm py-1">{{ $placeLabels[$place] ?? $place }}</span>
                @endif
            </div>

            <!-- Icône (seulement en édition) -->
            @if ($editing || $creating)
                <div class="form-control md:col-span-2">
                    <x-atoms.tooltip color="" position="top" icon="true">
                        <x-slot name="label">Icône personnalisée</x-slot>
                        Formats : PNG, JPG, GIF — Image carrée, fond transparent recommandé
                    </x-atoms.tooltip>
                    <x-atoms.fileInput wire-model="icon_file"
                        accept="image/png,image/jpeg,image/gif,image/x-icon,image/svg+xml" label="Choisir une icône"
                        icon="fa-solid fa-image" />
                    @error('icon_file')
                        <span class="text-xs text-error mt-1">{{ $message }}</span>
                    @enderror
                </div>
            @endif
        </div>

        <!-- Séparateur -->
        <div class="divider my-2"></div>

        <!-- Configuration Windows & Linux côte à côte -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

            {{-- ===== WINDOWS ===== --}}
            <div>
                <h4 class="text-2xl flex items-center gap-2 font-semibold mb-3">
                    <svg width="30" height="30" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 128 128">
                        <path fill="#0078d4"
                            d="M67.328 67.331h60.669V128H67.328zm-67.325 0h60.669V128H.003zM67.328 0h60.669v60.669H67.328zM.003 0h60.669v60.669H.003z" />
                    </svg>
                    Windows
                </h4>
                <div class="space-y-3">
                    <!-- Exécutable -->
                    <div class="form-control flex flex-col">
                        <x-atoms.tooltip position="top" icon="true">
                            <x-slot name="label">Exécutable</x-slot>
                            Chemin UNC ou URL<br>Variables : <code>$user</code>, <code>$userprofile</code>
                        </x-atoms.tooltip>
                        @if ($editing || $creating)
                            <input type="text" wire:model="windows_link"
                                placeholder="Ex: C:\Program Files\Firefox\firefox.exe"
                                class="input input-bordered input-sm">
                        @else
                            <span class="text-sm py-1 font-mono truncate">{{ $windows_link ?: '—' }}</span>
                        @endif
                    </div>
                    <!-- Arguments -->
                    <div class="form-control flex flex-col">
                        <x-atoms.tooltip position="top" icon="true">
                            <x-slot name="label">Arguments</x-slot>
                            Paramètres de lancement
                        </x-atoms.tooltip>
                        @if ($editing || $creating)
                            <input type="text" wire:model="windows_args" placeholder="Ex: --private-window"
                                class="input input-bordered input-sm">
                        @else
                            <span class="text-sm py-1 font-mono truncate">{{ $windows_args ?: '—' }}</span>
                        @endif
                    </div>
                    <!-- Dossier de travail -->
                    <div class="form-control flex flex-col">
                        <x-atoms.tooltip position="top" icon="true">
                            <x-slot name="label">Dossier de travail</x-slot>
                            Ex : <code>C:\Users\$user\Documents</code>
                        </x-atoms.tooltip>
                        @if ($editing || $creating)
                            <input type="text" wire:model="windows_path" placeholder="Ex: C:\Users\$user\Documents"
                                class="input input-bordered input-sm">
                        @else
                            <span class="text-sm py-1 font-mono truncate">{{ $windows_path ?: '—' }}</span>
                        @endif
                    </div>
                </div>
            </div>

            {{-- ===== LINUX ===== --}}
            <div>
                <h4 class="flex text-2xl items-center gap-2 font-semibold mb-3">
                    <i class="fa-brands fa-linux text-2xl"></i>
                    Linux
                </h4>
                <div class="space-y-3">
                    <!-- Exécutable -->
                    <div class="form-control flex flex-col">
                        <x-atoms.tooltip position="top" icon="true">
                            <x-slot name="label">Exécutable</x-slot>
                            Chemin absolu ou commande<br>Variable : <code>$user</code>
                        </x-atoms.tooltip>
                        @if ($editing || $creating)
                            <input type="text" wire:model="linux_link" placeholder="Ex: /usr/bin/firefox"
                                class="input input-bordered input-sm">
                        @else
                            <span class="text-sm py-1 font-mono truncate">{{ $linux_link ?: '—' }}</span>
                        @endif
                    </div>
                    <!-- Arguments -->
                    <div class="form-control flex flex-col">
                        <x-atoms.tooltip position="top" icon="true">
                            <x-slot name="label">Arguments</x-slot>
                            Paramètres de lancement
                        </x-atoms.tooltip>
                        @if ($editing || $creating)
                            <input type="text" wire:model="linux_args" placeholder="Ex: --private-window"
                                class="input input-bordered input-sm">
                        @else
                            <span class="text-sm py-1 font-mono truncate">{{ $linux_args ?: '—' }}</span>
                        @endif
                    </div>
                    <!-- Dossier de travail -->
                    <div class="form-control flex flex-col">
                        <x-atoms.tooltip position="top" icon="true">
                            <x-slot name="label">Dossier de travail</x-slot>
                            Ex : <code>/home/$user/Documents</code>
                        </x-atoms.tooltip>
                        @if ($editing || $creating)
                            <input type="text" wire:model="linux_path" placeholder="Ex: /home/$user/Documents"
                                class="input input-bordered input-sm">
                        @else
                            <span class="text-sm py-1 font-mono truncate">{{ $linux_path ?: '—' }}</span>
                        @endif
                    </div>
                    <!-- StartupWMClass -->
                    <div class="form-control flex flex-col">
                        <x-atoms.tooltip position="top" icon="true">
                            <x-slot name="label">StartupWMClass</x-slot>
                            Classe de fenêtre X11 (optionnel)<br>Permet le regroupement dans la barre des tâches
                        </x-atoms.tooltip>
                        @if ($editing || $creating)
                            <input type="text" wire:model="linux_startupwmclass" placeholder="Ex: Firefox"
                                class="input input-bordered input-sm">
                        @else
                            <span class="text-sm py-1 font-mono truncate">{{ $linux_startupwmclass ?: '—' }}</span>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

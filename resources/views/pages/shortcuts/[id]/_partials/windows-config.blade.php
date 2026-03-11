<!-- Configuration Windows -->
<div class="card bg-base-100 shadow-sm border border-base-200">
    <div class="card-body">
        <h3 class="card-title mb-4 flex items-center gap-4">
            <svg class="size-8" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 128 128">
                <path fill="#0078d4"
                    d="M67.328 67.331h60.669V128H67.328zm-67.325 0h60.669V128H.003zM67.328 0h60.669v60.669H67.328zM.003 0h60.669v60.669H.003z" />
            </svg>
            Configuration Windows
        </h3>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <!-- Chemin de l'exécutable -->
            <div class="form-control flex flex-col">
                <x-atoms.tooltip position="top" icon="true">
                    <x-slot name="label">Chemin de l'exécutable</x-slot>
                    Chemin UNC ou URL<br>Variables : <code>$user</code>, <code>$userprofile</code>
                </x-atoms.tooltip>
                <input type="text" wire:model="windows_link"
                    placeholder="Ex: C:\Program Files\Firefox\firefox.exe ou https://example.com"
                    class="input input-bordered @error('windows_link') input-error @enderror">
                @error('windows_link')
                    <label class="label">
                        <span class="label-text-alt text-error">{{ $message }}</span>
                    </label>
                @enderror
            </div>

            <!-- Arguments -->
            <div class="form-control flex flex-col">
                <x-atoms.tooltip position="top" icon="true">
                    <x-slot name="label">Arguments</x-slot>
                    Paramètres de lancement
                </x-atoms.tooltip>
                <input type="text" wire:model="windows_args" placeholder="Ex: --private-window"
                    class="input input-bordered @error('windows_args') input-error @enderror">
                @error('windows_args')
                    <label class="label">
                        <span class="label-text-alt text-error">{{ $message }}</span>
                    </label>
                @enderror
            </div>

            <!-- Dossier de travail -->
            <div class="form-control flex flex-col">
                <x-atoms.tooltip position="top" icon="true">
                    <x-slot name="label">Dossier de travail</x-slot>
                    Ex : <code>C:\Users\$user\Documents</code><br>Variables : <code>$user</code>, <code>$userprofile</code>
                </x-atoms.tooltip>
                <input type="text" wire:model="windows_path" placeholder="Ex: C:\Users\$user\Documents"
                    class="input input-bordered @error('windows_path') input-error @enderror">
                @error('windows_path')
                    <label class="label">
                        <span class="label-text-alt text-error">{{ $message }}</span>
                    </label>
                @enderror
            </div>
        </div>
    </div>
</div>

<!-- Configuration Linux -->

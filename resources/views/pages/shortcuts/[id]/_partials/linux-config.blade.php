<!-- Configuration Linux -->
<div class="card bg-base-100 shadow-sm border border-base-300">
    <div class="card-body">
        <h3 class="text-2xl mb-4 flex items-center gap-2 ">
            <i class="fa-brands fa-linux text-2xl"></i>
            Configuration Linux
        </h3>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <!-- Chemin de l'exécutable -->
            <div class="form-control flex flex-col">
                <x-atoms.tooltip position="top" icon="true">
                    <x-slot name="label">Chemin de l'exécutable</x-slot>
                    Chemin absolu ou commande<br>Variables : <code>$user</code>
                </x-atoms.tooltip>
                <input type="text" wire:model="linux_link" placeholder="Ex: /usr/bin/firefox ou firefox"
                    class="input input-bordered @error('linux_link') input-error @enderror">
                @error('linux_link')
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
                <input type="text" wire:model="linux_args" placeholder="Ex: --private-window"
                    class="input input-bordered @error('linux_args') input-error @enderror">
                @error('linux_args')
                    <label class="label">
                        <span class="label-text-alt text-error">{{ $message }}</span>
                    </label>
                @enderror
            </div>

            <!-- Dossier de travail -->
            <div class="form-control flex flex-col">
                <x-atoms.tooltip position="top" icon="true">
                    <x-slot name="label">Dossier de travail</x-slot>
                    Ex : <code>/home/$user/Documents</code><br>Variable : <code>$user</code>
                </x-atoms.tooltip>
                <input type="text" wire:model="linux_path" placeholder="Ex: /home/$user/Documents"
                    class="input input-bordered @error('linux_path') input-error @enderror">
                @error('linux_path')
                    <label class="label">
                        <span class="label-text-alt text-error">{{ $message }}</span>
                    </label>
                @enderror
            </div>

            <!-- StartupWMClass -->
            <div class="form-control flex flex-col">
                <x-atoms.tooltip position="top" icon="true">
                    <x-slot name="label">StartupWMClass</x-slot>
                    Classe de fenêtre X11 (optionnel)<br>Permet le regroupement dans la barre des tâches
                </x-atoms.tooltip>
                <input type="text" wire:model="linux_startupwmclass" placeholder="Ex: Firefox"
                    class="input input-bordered @error('linux_startupwmclass') input-error @enderror">
                @error('linux_startupwmclass')
                    <label class="label">
                        <span class="label-text-alt text-error">{{ $message }}</span>
                    </label>
                @enderror
            </div>
        </div>
    </div>
</div>

{{--
    Story 6.2 — Modale dédiée upload driver depuis un poste W10 pivot.

    Inclus dans `printers-tab.blade.php`. Partage les propriétés / méthodes
    Livewire du SFC parent : `$newDriverPivot`, `$newDriverName`,
    `$newDriverDisplayName`, `$availableDriversOnPivot`, `$showUploadDriverModal`,
    `$editingCupsName`. Méthodes : `listDriversOnPivot`, `uploadDriver`,
    `closeUploadDriverModal`.

    Le workflow se déroule en 2 étapes côté UX :
      1. Saisie hostname pivot + clic « Lister les drivers » → `listDriversOnPivot`.
      2. Sélection driver (radio) + nom interne / notes → « Téléverser et associer ».
--}}

@teleport('body')
    <x-molecules.modal wire:model="showUploadDriverModal" title="Téléverser un driver Windows"
        closeMethod="closeUploadDriverModal" size="max-w-3xl" height="h-auto max-h-[90vh]">

        @if ($editingCupsName)
            <x-molecules.modal.section title="Imprimante cible">
                <p class="text-sm">
                    Cible : <span class="font-mono font-semibold">{{ $editingCupsName }}</span>
                    — le driver sera téléversé sur le serveur SE4FS et associé à cette imprimante CUPS.
                </p>
            </x-molecules.modal.section>
        @endif

        <x-molecules.modal.section title="1. Poste pivot Windows 10">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-3 items-end">
                <div class="form-control w-full md:col-span-2">
                    <label class="label py-1">
                        <span class="label-text font-medium">Hostname du poste W10 (15 caractères max)</span>
                    </label>
                    <input type="text" wire:model="newDriverPivot"
                        class="input input-bordered input-sm w-full font-mono" placeholder="ex: w10-salle-a" maxlength="15" />
                    @error('newDriverPivot')
                        <span class="text-xs text-error mt-1">{{ $message }}</span>
                    @enderror
                    <span class="text-xs text-base-content/60 mt-1">
                        Pré-requis : le driver doit être installé localement sur ce poste, partagé sur une
                        imprimante locale et accessible via Kerberos depuis SE4FS.
                    </span>
                </div>
                <div class="form-control">
                    <button type="button" class="btn btn-sm btn-outline" wire:click="listDriversOnPivot">
                        <i class="fa-solid fa-magnifying-glass"></i>
                        Lister les drivers
                    </button>
                </div>
            </div>
        </x-molecules.modal.section>

        @if (!empty($availableDriversOnPivot))
            <x-molecules.modal.section title="2. Choix du driver">
                <div class="overflow-x-auto max-h-64">
                    <table class="table table-sm table-zebra">
                        <thead>
                            <tr>
                                <th></th>
                                <th>Imprimante partagée</th>
                                <th>Driver Windows</th>
                                <th>Commentaire</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($availableDriversOnPivot as $drv)
                                <tr wire:key="pivot-drv-{{ $drv['smb_name'] }}">
                                    <td>
                                        <input type="radio" wire:model="newDriverName"
                                            value="{{ $drv['smb_driver'] }}" class="radio radio-sm" />
                                    </td>
                                    <td class="font-mono text-xs">{{ $drv['smb_name'] }}</td>
                                    <td class="text-xs">{{ $drv['smb_driver'] }}</td>
                                    <td class="text-xs text-base-content/60">{{ $drv['smb_comment'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @error('newDriverName')
                    <span class="text-xs text-error mt-1">{{ $message }}</span>
                @enderror
            </x-molecules.modal.section>

            <x-molecules.modal.section title="3. Métadonnées (optionnel)">
                <div class="form-control w-full">
                    <label class="label py-1">
                        <span class="label-text font-medium">Nom interne / notes</span>
                    </label>
                    <input type="text" wire:model="newDriverDisplayName"
                        class="input input-bordered input-sm w-full" placeholder="ex: Driver imprimante salle A (PostScript)" />
                    @error('newDriverDisplayName')
                        <span class="text-xs text-error mt-1">{{ $message }}</span>
                    @enderror
                </div>
            </x-molecules.modal.section>
        @endif

        <x-slot:footer>
            <button type="button" class="btn btn-ghost" wire:click="closeUploadDriverModal">Annuler</button>
            <button type="button" class="btn btn-primary" wire:click="uploadDriver"
                @if (empty($newDriverName)) disabled @endif>
                <i class="fa-solid fa-upload"></i>
                Téléverser et associer
            </button>
        </x-slot:footer>
    </x-molecules.modal>
@endteleport

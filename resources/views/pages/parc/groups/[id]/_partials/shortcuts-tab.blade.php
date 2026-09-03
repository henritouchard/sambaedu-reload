{{--
    Onglet Raccourcis du groupe de postes.
    Miroir de l'assignation raccourci ↔ groupe gérée côté page raccourci.
    Réutilise méthodes Livewire et computed du composant parent.
--}}
@php
    $attachedShortcuts = $this->attachedShortcuts;
@endphp

<div class="space-y-4">
    <div class="card bg-base-100 shadow-sm border border-base-300">
        <div class="card-body">
            <div class="flex items-center justify-between mb-3">
                <h3 class="card-title text-base">
                    <i class="fa-solid fa-link text-primary"></i>
                    Raccourcis attribués
                    <span class="badge badge-ghost">{{ $attachedShortcuts->count() }}</span>
                </h3>
                @can('update-workstationGroup', $group)
                    <button type="button" class="btn btn-primary btn-sm gap-2"
                        wire:click="openAttachShortcutModal">
                        <i class="fa-solid fa-plus"></i>
                        Ajouter
                    </button>
                @endcan
            </div>

            @if ($attachedShortcuts->isEmpty())
                <div class="text-center py-8 text-base-content/60">
                    <i class="fa-solid fa-link text-3xl mb-2 opacity-30"></i>
                    <p class="text-sm">Aucun raccourci attribué à ce groupe de postes.</p>
                </div>
            @else
                <ul class="divide-y divide-base-200">
                    @foreach ($attachedShortcuts as $shortcut)
                        <li wire:key="grp-attached-shortcut-{{ $shortcut->id }}"
                            class="flex items-center justify-between py-2">
                            <div class="flex items-center gap-3 min-w-0">
                                <img src="{{ route('shortcuts.icon', ['name' => $shortcut->name]) }}"
                                    alt="{{ $shortcut->name }}" class="w-8 h-8 object-contain rounded shrink-0"
                                    onerror="this.src='/elements/images/system-run.png'" />
                                <div class="min-w-0">
                                    <div class="font-medium truncate">
                                        {{ $shortcut->name }}
                                        @if ($shortcut->isUpstreamLocked())
                                            <span class="badge badge-warning badge-sm ml-1"
                                                title="Imposé et verrouillé par l'autorité amont">
                                                <i class="fa-solid fa-lock text-xs mr-1"></i>Imposé
                                            </span>
                                        @elseif ($shortcut->is_global)
                                            <span class="badge badge-warning badge-sm ml-1" title="Géré par le ControlHub">
                                                <i class="fa-solid fa-lock text-xs mr-1"></i>ControlHub
                                            </span>
                                        @elseif (!$shortcut->is_active)
                                            <span class="badge badge-ghost badge-sm ml-1">inactif</span>
                                        @endif
                                    </div>
                                    <div class="text-xs text-base-content/60 truncate">
                                        <code>{{ $shortcut->key }}</code>
                                        <span class="mx-1">•</span>
                                        {{ $shortcut->getPlaceLabel() }}
                                        @if ($shortcut->category)
                                            <span class="mx-1">•</span>{{ $shortcut->category }}
                                        @endif
                                    </div>
                                </div>
                            </div>
                            @can('update-workstationGroup', $group)
                                @unless ($shortcut->isUpstreamLocked())
                                    <button type="button" class="btn btn-ghost btn-xs btn-square text-error"
                                        wire:click="detachShortcut({{ $shortcut->id }})"
                                        wire:confirm="Retirer le raccourci « {{ $shortcut->name }} » de ce groupe ?">
                                        <i class="fa-solid fa-xmark"></i>
                                    </button>
                                @endunless
                            @endcan
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </div>
</div>

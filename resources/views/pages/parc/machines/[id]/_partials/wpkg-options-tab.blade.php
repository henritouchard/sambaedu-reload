{{--
    Story 15.4 / AC5 — Onglet « Options .ini » du poste.
    8 options legacy via WorkstationIniGenerator::LEGACY_OPTIONS, toggles
    pilotés par $this->wpkgOptionsState (array<string,bool>).
--}}
<div class="card bg-base-100 shadow-sm border border-base-200">
    <div class="card-body">
        <div class="flex items-center justify-between mb-3">
            <h3 class="card-title text-base">
                <i class="fa-solid fa-sliders text-primary"></i>
                Options .ini WPKG
            </h3>
            <div class="flex gap-2">
                @can('wpkg.assign')
                    <button type="button" class="btn btn-ghost btn-sm gap-2"
                        wire:click="resetWpkgOptions"
                        wire:confirm="Réinitialiser toutes les options aux valeurs par défaut ?">
                        <i class="fa-solid fa-rotate-left"></i>
                        Réinitialiser
                    </button>
                    <button type="button" class="btn btn-primary btn-sm gap-2"
                        wire:click="saveWpkgOptions">
                        <i class="fa-solid fa-floppy-disk"></i>
                        Enregistrer
                    </button>
                @endcan
            </div>
        </div>

        <p class="text-sm text-base-content/60 mb-4">
            Ces options pilotent le comportement WPKG sur le poste. Une option à
            <code>false</code> n'est pas stockée en base (défaut implicite).
        </p>

        <div class="space-y-2">
            @foreach (\App\Wpkg\Deployment\Generators\WorkstationIniGenerator::LEGACY_OPTIONS as $opt)
                @php
                    $key = $opt['name'];
                    $isOn = $wpkgOptionsState[$key] ?? false;
                    $isOverridden = $isOn; // false = défaut, true = override en BDD
                @endphp
                <div wire:key="wpkg-option-{{ $key }}"
                    class="flex items-start gap-3 p-3 rounded-lg border {{ $isOverridden ? 'border-primary/30 bg-primary/5' : 'border-base-200' }}">
                    <label class="flex items-start gap-3 cursor-pointer flex-1">
                        <input type="checkbox" class="toggle toggle-primary toggle-sm mt-0.5"
                            wire:click="toggleWpkgOption('{{ $key }}')"
                            @checked($isOn)
                            @cannot('wpkg.assign') disabled @endcannot />
                        <div class="flex-1">
                            <div class="font-medium">
                                <code>{{ $key }}</code>
                                @if ($isOverridden)
                                    <span class="badge badge-primary badge-xs ml-2">surchargé</span>
                                @endif
                            </div>
                            <div class="text-xs text-base-content/70 mt-0.5">
                                {{ $opt['description'] }}
                            </div>
                        </div>
                    </label>
                </div>
            @endforeach
        </div>
    </div>
</div>

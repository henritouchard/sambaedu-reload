<!-- Classes (conditionnelle) -->
@if ($categorie === 'Eleves' || $categorie === 'Profs')
    <div
        class="bg-gradient-to-br from-secondary/10 via-accent/5 to-primary/10 rounded-3xl border border-base-300 shadow-xl backdrop-blur-sm overflow-hidden">
        <div class="p-8">
            <div class="flex items-center gap-4 mb-8">
                <div
                    class="w-12 h-12 bg-gradient-to-br from-secondary to-secondary/80 rounded-2xl flex items-center justify-center shadow-lg ring-4 ring-secondary/20">
                    <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4">
                        </path>
                    </svg>
                </div>
                <div>
                    <h2 class="text-2xl font-black text-base-content">
                        {{ $categorie === 'Eleves' ? 'Classe' : 'Classes' }}
                    </h2>
                    <p class="text-sm text-base-content/60 font-medium">
                        {{ $categorie === 'Eleves' ? 'Classe de rattachement de l\'élève' : 'Classes du professeur' }}
                    </p>
                </div>
            </div>

            @if ($categorie === 'Eleves')
                <!-- Select avec recherche pour les élèves (Livewire réactif) -->
                <div>
                    <label class="label">
                        <span class="label-text font-medium text-base-content/70">Classe <span
                                class="text-error">*</span></span>
                    </label>
                    <!-- Classe sélectionnée -->
                    @if (!empty($classes[0]))
                        <div class="mb-2">
                            <span class="badge badge-primary badge-lg gap-2">
                                {{ $classes[0] }}
                                <button type="button" wire:click="$set('classes', [])" class="hover:text-error">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                            d="M6 18L18 6M6 6l12 12"></path>
                                    </svg>
                                </button>
                            </span>
                        </div>
                    @endif
                    <!-- Champ de recherche -->
                    <input type="text" wire:model.live.debounce.200ms="classSearch"
                        placeholder="Rechercher une classe..." class="input input-bordered w-full mb-2">
                    <!-- Liste scrollable -->
                    <div
                        class="border border-base-300 rounded-xl max-h-48 overflow-y-auto bg-base-100 @error('classes') border-error @enderror">
                        @forelse ($this->filteredClasses as $classe)
                            <button type="button" wire:click="selectClass('{{ $classe }}')"
                                class="flex items-center gap-3 px-4 py-2 hover:bg-base-200 cursor-pointer border-b border-base-300 last:border-b-0 w-full text-left {{ in_array($classe, $classes) ? 'bg-primary/10' : '' }}">
                                <span
                                    class="w-4 h-4 rounded-full border-2 {{ in_array($classe, $classes) ? 'border-primary bg-primary' : 'border-base-300' }} flex items-center justify-center">
                                    @if (in_array($classe, $classes))
                                        <svg class="w-2.5 h-2.5 text-white" fill="currentColor" viewBox="0 0 20 20">
                                            <circle cx="10" cy="10" r="5" />
                                        </svg>
                                    @endif
                                </span>
                                <span class="text-sm">{{ $classe }}</span>
                            </button>
                        @empty
                            <div class="px-4 py-3 text-sm text-base-content/50 text-center">
                                Aucune classe trouvée
                            </div>
                        @endforelse
                    </div>
                    @error('classes')
                        <span class="text-error text-sm">{{ $message }}</span>
                    @enderror
                </div>
            @else
                <!-- Multiselect avec recherche pour les profs (Livewire réactif) -->
                <div>
                    <label class="label">
                        <span class="label-text font-medium text-base-content/70">Classes</span>
                    </label>
                    <!-- Classes sélectionnées -->
                    @if (count($classes) > 0)
                        <div class="flex flex-wrap gap-2 mb-3">
                            @foreach ($classes as $selectedClass)
                                <span class="badge badge-primary gap-1">
                                    {{ $selectedClass }}
                                    <button type="button" wire:click="removeClass('{{ $selectedClass }}')"
                                        class="hover:text-error">
                                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                d="M6 18L18 6M6 6l12 12"></path>
                                        </svg>
                                    </button>
                                </span>
                            @endforeach
                        </div>
                    @endif
                    <!-- Champ de recherche -->
                    <input type="text" wire:model.live.debounce.200ms="classSearch"
                        placeholder="Rechercher une classe..." class="input input-bordered w-full mb-2">
                    <!-- Liste scrollable avec checkboxes -->
                    <div class="border border-base-300 rounded-xl max-h-48 overflow-y-auto bg-base-100">
                        @forelse ($this->filteredClasses as $classe)
                            <button type="button" wire:click="toggleClass('{{ $classe }}')"
                                class="flex items-center gap-3 px-4 py-2 hover:bg-base-200 cursor-pointer border-b border-base-300 last:border-b-0 w-full text-left {{ in_array($classe, $classes) ? 'bg-primary/10' : '' }}">
                                <span
                                    class="w-4 h-4 rounded border {{ in_array($classe, $classes) ? 'border-primary bg-primary' : 'border-base-300' }} flex items-center justify-center">
                                    @if (in_array($classe, $classes))
                                        <svg class="w-3 h-3 text-white" fill="none" stroke="currentColor"
                                            viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3"
                                                d="M5 13l4 4L19 7"></path>
                                        </svg>
                                    @endif
                                </span>
                                <span class="text-sm">{{ $classe }}</span>
                            </button>
                        @empty
                            <div class="px-4 py-3 text-sm text-base-content/50 text-center">
                                Aucune classe trouvée
                            </div>
                        @endforelse
                    </div>
                    <p class="text-xs text-base-content/50 mt-2">{{ count($classes) }} classe(s) sélectionnée(s)</p>
                </div>
            @endif
        </div>
    </div>
@endif

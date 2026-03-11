<!-- En-tête du raccourci -->
<div class="card bg-base-100 shadow-sm border border-base-200 mb-6">
    <div class="card-body">
        <div class="flex items-center gap-6">
            <!-- Icône du raccourci -->
            <div class="w-20 h-20 rounded-lg bg-primary/20 flex items-center justify-center">
                <img src="{{ $this->getShortcutIconUrl() }}" alt="{{ $name }}" class="w-16 h-16 object-contain"
                    onerror="this.src='/elements/images/system-run.png'">
            </div>

            <!-- Informations principales -->
            <div class="flex-1">
                <h2 class="text-2xl font-bold mb-2">{{ $name }}</h2>
                <div class="flex flex-wrap gap-3">
                    <!-- Type -->
                    @if ($this->isUrlShortcut())
                        <div class="badge badge-info badge-lg">
                            <i class="fa-solid fa-globe mr-2"></i>
                            Site web
                        </div>
                    @else
                        <div class="badge badge-success badge-lg">
                            <i class="fa-solid fa-desktop mr-2"></i>
                            Application
                        </div>
                    @endif

                    <!-- Emplacement -->
                    <div class="badge badge-outline badge-lg">
                        <i class="fa-solid fa-location-dot mr-2"></i>
                        {{ $placeLabels[$place] ?? 'Bureau' }}
                    </div>

                    <!-- Propriétaire -->
                    @if (!empty($owner))
                        <div class="badge badge-outline badge-lg">
                            <i class="fa-solid fa-user mr-2"></i>
                            {{ $owner }}
                        </div>
                    @endif
                </div>
            </div>

            <!-- Clé technique -->
            <div class="text-right">
                <span class="text-xs text-base-content/50">Clé technique</span>
                <div class="font-mono text-sm bg-base-200 px-3 py-1 rounded">{{ $key }}</div>
            </div>
        </div>
    </div>
</div>

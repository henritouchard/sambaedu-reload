<!-- Informations de la machine -->
<div class="lg:col-span-1 space-y-6">
    <!-- Carte d'information principale -->
    <div class="card bg-base-100 shadow-sm">
        <div class="card-body">
            <div class="flex items-center gap-4 mb-4">
                <div class="w-16 h-16 rounded-xl bg-primary/10 flex items-center justify-center">
                    <i class="fa-solid fa-computer text-primary text-2xl"></i>
                </div>
                <div>
                    <h2 class="text-xl font-bold">{{ $workstation->name }}</h2>
                    @php
                        $statusClass = match ($workstation->status) {
                            1 => 'badge-success',
                            2 => 'badge-warning',
                            default => 'badge-error',
                        };
                    @endphp
                    <span class="badge {{ $statusClass }}">
                        {{ $workstation->getStatusLabel() }}
                    </span>
                </div>
            </div>

            <div class="divider my-2"></div>

            <div class="space-y-3 text-sm">
                <div class="flex justify-between">
                    <span class="text-base-content/60">Système</span>
                    <span class="badge badge-ghost">{{ $workstation->os }}</span>
                </div>
                <div class="flex justify-between">
                    <span class="text-base-content/60">Adresse IP</span>
                    <span class="font-mono">{{ $workstation->ip }}</span>
                </div>
                <div class="flex justify-between">
                    <span class="text-base-content/60">Adresse MAC</span>
                    <span class="font-mono text-xs">{{ $workstation->mac }}</span>
                </div>
                @if ($workstation->ad_guid)
                    <div class="flex justify-between">
                        <span class="text-base-content/60">AD GUID</span>
                        <span class="font-mono text-xs" title="{{ $workstation->ad_guid }}">
                            {{ Str::limit($workstation->ad_guid, 12) }}...
                        </span>
                    </div>
                @endif
            </div>
        </div>
    </div>

    <!-- Rapports -->
    <div class="card bg-base-100 shadow-sm">
        <div class="card-body">
            <h3 class="card-title text-base mb-4">
                <i class="fa-solid fa-file-lines text-primary"></i>
                Rapports
            </h3>
            <div class="space-y-3 text-sm">
                <div class="flex justify-between">
                    <span class="text-base-content/60">Dernier rapport</span>
                    <span>
                        @if ($workstation->date_rapport_poste)
                            {{ $workstation->date_rapport_poste->format('d/m/Y H:i') }}
                        @else
                            <span class="text-base-content/50">Aucun</span>
                        @endif
                    </span>
                </div>
                <div class="flex justify-between">
                    <span class="text-base-content/60">Dernière modification</span>
                    <span>
                        @if ($workstation->date_modification_poste)
                            {{ $workstation->date_modification_poste->format('d/m/Y H:i') }}
                        @else
                            <span class="text-base-content/50">-</span>
                        @endif
                    </span>
                </div>
                @if ($workstation->file_log_poste)
                    <div>
                        <span class="text-base-content/60 block mb-1">Fichier log</span>
                        <span class="font-mono text-xs break-all">{{ $workstation->file_log_poste }}</span>
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>

<!-- Cartes de statistiques -->
{{-- Story 16.13bis : 5e card "Postes migrés" ajoutée — grid passe à 5 cols sur md+ --}}
<div class="grid grid-cols-2 md:grid-cols-5 gap-4">
    @if (!$statsLoaded)
        @for ($i = 0; $i < 5; $i++)
            <div class="card bg-base-100 shadow-sm">
                <div class="card-body py-4">
                    <div class="skeleton h-4 w-20 mb-2"></div>
                    <div class="skeleton h-8 w-16"></div>
                </div>
            </div>
        @endfor
    @else
        <!-- Machines actives -->
        <div class="card bg-base-100 shadow-sm">
            <div class="card-body py-4">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-lg bg-success/10 flex items-center justify-center">
                        <i class="fa-solid fa-computer text-success"></i>
                    </div>
                    <div>
                        <div class="text-sm text-base-content/60">Postes actifs</div>
                        <div class="text-2xl font-bold">{{ $machineStats['active'] ?? 0 }}</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Postes sans groupe -->
        <div class="card bg-base-100 shadow-sm">
            <div class="card-body py-4">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-lg bg-warning/10 flex items-center justify-center">
                        <i class="fa-solid fa-triangle-exclamation text-warning"></i>
                    </div>
                    <div>
                        <div class="text-sm text-base-content/60">Sans groupe</div>
                        <div class="text-2xl font-bold">{{ $machineStats['without_group'] ?? 0 }}</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Salles -->
        <div class="card bg-base-100 shadow-sm">
            <div class="card-body py-4">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-lg bg-primary/10 flex items-center justify-center">
                        <i class="fa-solid fa-door-open text-primary"></i>
                    </div>
                    <div>
                        <div class="text-sm text-base-content/60">Salles</div>
                        <div class="text-2xl font-bold">{{ $groupStats['rooms'] ?? 0 }}</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Parcs logiques -->
        <div class="card bg-base-100 shadow-sm">
            <div class="card-body py-4">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-lg bg-secondary/10 flex items-center justify-center">
                        <i class="fa-solid fa-layer-group text-secondary"></i>
                    </div>
                    <div>
                        <div class="text-sm text-base-content/60">Parcs logiques</div>
                        <div class="text-2xl font-bold">{{ $groupStats['parcs'] ?? 0 }}</div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Story 16.13bis — compteur X/Y postes migrés SE4 → SE5
             Correction Q2 / Opus-A (2026-05-20) : X et Y sont scoped aux
             filtres actifs (OS / groupe / migration) — cohérent avec le
             listing Livewire ci-dessous. --}}
        <div class="card bg-base-100 shadow-sm">
            <div class="card-body py-4">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-lg bg-info/10 flex items-center justify-center">
                        <i class="fa-solid fa-arrows-rotate text-info"></i>
                    </div>
                    <div>
                        <div class="text-sm text-base-content/60">Postes migrés</div>
                        <div class="text-2xl font-bold" title="Postes ayant basculé SE4 → SE5 (scope = filtres actifs)">
                            {{ $machineStats['migrated'] ?? 0 }}<span class="text-base-content/40 text-base font-normal">/{{ $machineStats['total'] ?? 0 }}</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>

{{-- Story 24.7 — Compteurs de conformité agent (périmètre = postes ENRÔLÉS du
     parc, requêtes agrégées). « En écart » = drift+error ; la dérive tolérée
     est distincte (jamais comptée comme écart). Rendu uniquement quand au
     moins un poste est enrôlé (sinon section masquée — pas de bruit visuel). --}}
@if ($statsLoaded && ($conformityStats['enrolled'] ?? 0) > 0)
    <div class="grid grid-cols-2 md:grid-cols-5 gap-4 mt-2">
        <div class="card bg-base-100 shadow-sm">
            <div class="card-body py-4">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-lg bg-error/10 flex items-center justify-center">
                        <i class="fa-solid fa-triangle-exclamation text-error"></i>
                    </div>
                    <div>
                        <div class="text-sm text-base-content/60">En écart</div>
                        <div class="text-2xl font-bold" title="Postes en drift ou erreur (worst-status)">{{ $conformityStats['exceptions'] ?? 0 }}</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card bg-base-100 shadow-sm">
            <div class="card-body py-4">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-lg bg-info/10 flex items-center justify-center">
                        <i class="fa-solid fa-circle-info text-info"></i>
                    </div>
                    <div>
                        <div class="text-sm text-base-content/60">Dérive tolérée</div>
                        <div class="text-2xl font-bold" title="Dérive humaine tolérée (mode default) — pas un écart">{{ $conformityStats['drifted_allowed'] ?? 0 }}</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card bg-base-100 shadow-sm">
            <div class="card-body py-4">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-lg bg-warning/10 flex items-center justify-center">
                        <i class="fa-solid fa-volume-xmark text-warning"></i>
                    </div>
                    <div>
                        <div class="text-sm text-base-content/60">Muets / jamais rapporté</div>
                        <div class="text-2xl font-bold" title="Postes enrôlés sans check-in récent (muet) ou n'ayant jamais rapporté">{{ ($conformityStats['silent'] ?? 0) + ($conformityStats['never_reported'] ?? 0) }}</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card bg-base-100 shadow-sm">
            <div class="card-body py-4">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-lg bg-success/10 flex items-center justify-center">
                        <i class="fa-solid fa-circle-check text-success"></i>
                    </div>
                    <div>
                        <div class="text-sm text-base-content/60">Conformes</div>
                        <div class="text-2xl font-bold" title="Postes conformes sur toutes leurs ressources">{{ $conformityStats['compliant'] ?? 0 }}</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card bg-base-100 shadow-sm">
            <div class="card-body py-4">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-lg bg-primary/10 flex items-center justify-center">
                        <i class="fa-solid fa-tower-broadcast text-primary"></i>
                    </div>
                    <div>
                        <div class="text-sm text-base-content/60">Postes enrôlés</div>
                        <div class="text-2xl font-bold" title="Postes disposant d'un agent (canal desired-state)">{{ $conformityStats['enrolled'] ?? 0 }}</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endif

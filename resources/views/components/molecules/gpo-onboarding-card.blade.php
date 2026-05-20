{{--
    Composant card hero d'onboarding GPO — Story 16.14 AC1.x.

    Props :
      - $dismissed (bool) : si true, la card est masquée (état session).
    Le bouton "Masquer" déclenche l'action Livewire dismissHero().
    Le bouton "Afficher l'aide" déclenche showHero().
--}}
@props(['dismissed' => false])

@if ($dismissed)
    {{-- Bouton compact pour réafficher --}}
    <div class="flex justify-end mb-2">
        <button type="button"
            class="btn btn-ghost btn-xs gap-1 text-base-content/50 hover:text-base-content"
            wire:click="showHero">
            <i class="fa-solid fa-circle-question text-xs"></i>
            Afficher l'aide
        </button>
    </div>
@else
    {{-- Card hero principale --}}
    <div class="card bg-base-200 border border-base-300 shadow-sm mb-4" data-testid="gpo-hero-card">
        <div class="card-body py-4 px-5">
            <div class="flex items-start justify-between gap-4">
                <div class="flex-1">
                    <h2 class="text-lg font-bold flex items-center gap-2 mb-1">
                        <i class="fa-solid fa-file-code text-primary"></i>
                        Gestion des GPO Active Directory
                    </h2>
                    <p class="text-sm text-base-content/70 mb-4">
                        Les GPO (Group Policy Objects) définissent les paramètres appliqués aux postes et utilisateurs du domaine.
                        SE5 permet de consulter, lier et gérer nativement les sections reconnues.
                    </p>

                    {{-- 3 parcours guidés --}}
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                        {{-- Card 1 : Consulter --}}
                        <a href="#listing-gpos"
                            class="card bg-base-100 border border-base-300 hover:border-primary hover:shadow-md transition-all duration-200 cursor-pointer"
                            data-testid="hero-card-consult">
                            <div class="card-body py-3 px-4">
                                <div class="flex items-center gap-3">
                                    <div class="p-2 bg-primary/10 rounded-lg">
                                        <i class="fa-solid fa-magnifying-glass text-primary text-lg"></i>
                                    </div>
                                    <div>
                                        <p class="font-semibold text-sm">Consulter / Inspecter</p>
                                        <p class="text-xs text-base-content/60">Parcourir et filtrer les GPOs du domaine</p>
                                    </div>
                                </div>
                            </div>
                        </a>

                        {{-- Card 2 : Vue inverse OU --}}
                        <a href="{{ route('admin.gpo.by-ou') }}"
                            class="card bg-base-100 border border-base-300 hover:border-secondary hover:shadow-md transition-all duration-200 cursor-pointer"
                            data-testid="hero-card-by-ou">
                            <div class="card-body py-3 px-4">
                                <div class="flex items-center gap-3">
                                    <div class="p-2 bg-secondary/10 rounded-lg">
                                        <i class="fa-solid fa-sitemap text-secondary text-lg"></i>
                                    </div>
                                    <div>
                                        <p class="font-semibold text-sm">Lier une GPO à une OU</p>
                                        <p class="text-xs text-base-content/60">Vue inverse : GPOs par OU + héritage</p>
                                    </div>
                                </div>
                            </div>
                        </a>

                        {{-- Card 3 : Sections natives --}}
                        <a href="{{ route('admin.gpo.sections') }}"
                            class="card bg-base-100 border border-base-300 hover:border-accent hover:shadow-md transition-all duration-200 cursor-pointer"
                            data-testid="hero-card-sections">
                            <div class="card-body py-3 px-4">
                                <div class="flex items-center gap-3">
                                    <div class="p-2 bg-accent/10 rounded-lg">
                                        <i class="fa-solid fa-puzzle-piece text-accent text-lg"></i>
                                    </div>
                                    <div>
                                        <p class="font-semibold text-sm">Éditer une section native</p>
                                        <p class="text-xs text-base-content/60">Wallpapers, Firefox, Shortcuts, Wine…</p>
                                    </div>
                                </div>
                            </div>
                        </a>
                    </div>
                </div>

                {{-- Bouton masquer --}}
                <button type="button"
                    class="btn btn-ghost btn-xs btn-circle mt-1 flex-shrink-0"
                    wire:click="dismissHero"
                    title="Masquer">
                    <i class="fa-solid fa-xmark text-base-content/50"></i>
                </button>
            </div>
        </div>
    </div>
@endif

{{-- Encart "Création GPO non exposée" (AC1.3 / mémoire project_no_native_gpo_creation) --}}
{{-- Toujours visible, indépendamment de l'état dismissed de la card hero (finding #14) --}}
<div class="alert alert-info alert-sm mb-2 py-2 text-sm" data-testid="gpo-creation-info">
    <i class="fa-solid fa-circle-info text-xs"></i>
    <span>
        La création de GPO native est volontairement non exposée — utilisez
        <code class="font-mono text-xs bg-base-300 px-1 rounded">samba-tool gpo create</code>
        en SSH ou contactez votre support si besoin.
    </span>
</div>

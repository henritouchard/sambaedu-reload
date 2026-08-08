<!-- Sidebar -->
{{-- z-50 : DaisyUI met .drawer-side en z-1, sous le <main> du layout (relative z-10).
     Sans ça l'overlay de fermeture est inatteignable au clic sur mobile.
     Reste sous les tiroirs secondaires (z-[60]) et les modales (z-100). --}}

{{-- RÈGLE : un composant de navigation rendu sur CHAQUE page ne doit jamais être
     ce qui met l'application à terre.

     `route()` lève une RouteNotFoundException si le nom n'existe pas. Dans une
     vue rendue partout, ça ne dégrade pas un lien : ça renvoie une 500 sur
     l'intégralité de l'application, y compris les pages qui n'ont rien à voir —
     et sans sidebar accessible, plus aucun écran ne permet de diagnostiquer.

     Incident du 2026-08-07 : un `bootstrap/cache/routes-v7.php` figé au 11
     juillet, donc antérieur aux routes d'extensions (Epic 54, fin juillet),
     a rendu `admin.extensions` introuvable → tableau de bord inaccessible.
     Le code était bon ; seul le cache mentait.

     `Route::has()` est un simple lookup dans la table des routes (coût nul). Un
     lien dont la route manque DISPARAÎT du menu au lieu de tout casser.
     Le garde vaut pour TOUS les liens, sans exception : c'est ce qui le rend
     auto-entretenu — personne n'a à juger quel lien serait « à risque », et
     un lien ajouté demain hérite de la règle en la recopiant.

     Ne pas retirer ces gardes au motif que « la route existe forcément » :
     c'était vrai aussi le 11 juillet. --}}
@php
    $navDashboard = Route::has('app.dashboard');
    $navUsers = Route::has('app.users');
    $navRights = Route::has('app.rights-management');
    $navParc = Route::has('app.parc.index');
    $navParcSettings = Route::has('app.parc-settings.index');
    $navSettings = Route::has('admin.settings');
    $navExtensions = Route::has('admin.extensions');
@endphp
<div class="drawer-side z-50">
    <label for="drawer-toggle" aria-label="close sidebar" class="drawer-overlay"></label>
    <aside class="min-h-full w-80 border-r border-base-300 bg-base-100 shadow-xl">
        <!-- Sidebar header -->
        <div class="relative p-6 backdrop-blur-sm">
            <h2 class="text-xl font-bold text-base-content flex items-center justify-center">
                <x-atoms.logo class="w-72 h-auto object-contain" />
            </h2>
            {{-- Repli manuel : uniquement sous `lg`, où le drawer n'est pas en `drawer-open`. --}}
            <label for="drawer-toggle" aria-label="Replier le menu"
                class="btn btn-sm btn-circle btn-ghost absolute right-2 top-2 lg:hidden">
                <i class="fa-solid fa-xmark"></i>
            </label>
        </div>

        <!-- Sidebar content -->
        <div class="p-6 space-y-4">
            <ul class="menu w-full space-y-1">
                {{-- Les intitulés de section sont gardés par l'OR de leurs liens :
                     sans ça, une section dont tous les liens ont disparu laisserait
                     un titre orphelin. --}}
                @if ($navDashboard || $navUsers || $navRights)
                    <li class="menu-title px-4 pt-0 pb-1 text-xs font-semibold uppercase tracking-[0.09em] text-base-content/60">
                        Pilotage
                    </li>
                @endif
                @if ($navDashboard)
                    <li>
                        <a href="{{ route('app.dashboard') }}"
                            class="flex items-center gap-4 px-4 py-3 text-base font-medium {{ request()->is('app/dashboard*') ? 'active bg-primary/20 text-primary shadow-lg' : 'hover:bg-base-200/70' }} rounded-xl transition-all duration-200 hover:shadow-md hover:scale-[1.02]">
                            <x-icons.dashboard></x-icons.dashboard>
                            Tableau de bord
                        </a>
                    </li>
                @endif
                @if ($navUsers)
                    <li>
                        <a href="{{ route('app.users') }}"
                            class="flex items-center gap-4 px-4 py-3 text-base font-medium {{ request()->is('app/users*') ? 'active bg-primary/20 text-primary shadow-lg' : 'hover:bg-base-200/70' }} rounded-xl transition-all duration-200 hover:shadow-md hover:scale-[1.02]">
                            <i class="fa-solid fa-users text-xl"></i>
                            Utilisateurs
                        </a>
                    </li>
                @endif
                @if ($navRights)
                    <li>
                        <a href="{{ route('app.rights-management') }}"
                            class="flex items-center gap-4 px-4 py-3 text-base font-medium {{ request()->is('app/rights-management*') ? 'active bg-primary/20 text-primary shadow-lg' : 'hover:bg-base-200/70' }} rounded-xl transition-all duration-200 hover:shadow-md hover:scale-[1.02]">
                            <i class="fa-solid fa-shield-halved text-xl"></i>
                            Gestion des droits
                        </a>
                    </li>
                @endif
                @if ($navParc || $navParcSettings)
                    <li class="menu-title px-4 pt-4 pb-1 text-xs font-semibold uppercase tracking-[0.09em] text-base-content/60">
                        Parc &amp; postes
                    </li>
                @endif
                @if ($navParc)
                    <li>
                        <a href="{{ route('app.parc.index') }}"
                            class="flex items-center gap-4 px-4 py-3 text-base font-medium {{ request()->is('app/parc') || request()->is('app/parc/*') ? 'active bg-primary/20 text-primary shadow-lg' : 'hover:bg-base-200/70' }} rounded-xl transition-all duration-200 hover:shadow-md hover:scale-[1.02]">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4">
                                </path>
                            </svg>
                            Gestion du parc
                        </a>
                    </li>
                @endif
                @if ($navParcSettings)
                    <li>
                        <a href="{{ route('app.parc-settings.index') }}"
                            class="flex items-center gap-4 px-4 py-3 text-base font-medium {{ request()->is('app/parc-settings') || request()->is('app/parc-settings/profiles*') || request()->is('app/parc-settings/applications*') ? 'active bg-primary/20 text-primary shadow-lg' : 'hover:bg-base-200/70' }} rounded-xl transition-all duration-200 hover:shadow-md hover:scale-[1.02]">
                            <x-icons.apps />
                            Applications
                        </a>
                    </li>
                @endif
                @can('server.admin')
                    {{-- Réglages — landing cards regroupant Système / GPO / Migration / Réseau.
                         Visible uniquement server.admin (action critique). L'intitulé de groupe
                         est dans le @can : sans lui, un non-admin verrait une section vide. --}}
                    @if ($navSettings || $navExtensions)
                        <li class="menu-title px-4 pt-4 pb-1 text-xs font-semibold uppercase tracking-[0.09em] text-base-content/60">
                            Serveur
                        </li>
                    @endif
                    @if ($navSettings)
                        <li>
                            <a href="{{ route('admin.settings') }}"
                                class="flex items-center gap-4 px-4 py-3 text-base font-medium {{ request()->is('admin/settings*') || request()->is('admin/quotas*') || request()->is('admin/controlHub/*') || request()->is('app/network/dhcp*') ? 'active bg-primary/20 text-primary shadow-lg' : 'hover:bg-base-200/70' }} rounded-xl transition-all duration-200 hover:shadow-md hover:scale-[1.02]">
                                <i class="fa-solid fa-cog text-xl"></i>
                                Réglages
                            </a>
                        </li>
                    @endif
                    {{-- Extensions (Story 54.1) — bibliothèque des extensions
                         disponibles et intégrées. Même garde `server.admin` que
                         Réglages : c'est une fonction d'administration serveur. --}}
                    @if ($navExtensions)
                        <li>
                            <a href="{{ route('admin.extensions') }}"
                                class="flex items-center gap-4 px-4 py-3 text-base font-medium {{ request()->is('admin/extensions*') ? 'active bg-primary/20 text-primary shadow-lg' : 'hover:bg-base-200/70' }} rounded-xl transition-all duration-200 hover:shadow-md hover:scale-[1.02]">
                                <i class="fa-solid fa-puzzle-piece text-xl"></i>
                                Extensions
                            </a>
                        </li>
                    @endif
                @endcan
            </ul>
        </div>
    </aside>
</div>

<!-- Sidebar -->
<div class="drawer-side">
    <label for="drawer-toggle" aria-label="close sidebar" class="drawer-overlay"></label>
    <aside class="min-h-full w-80 border-r border-gray-200 bg-base-100 shadow-xl">
        <!-- Sidebar header -->
        <div class="p-6 backdrop-blur-sm">
            <h2 class="text-xl font-bold text-base-content flex items-center justify-center">
                <img src="{{ asset('img/LogoSambaEdu.png') }}" alt="Logo SambaEdu" class="w-72 h-auto object-contain" />
            </h2>
        </div>

        <!-- Sidebar content -->
        <div class="p-6 space-y-4">
            <ul class="menu w-full space-y-6">
                <li>
                    <a href="{{ route('app.dashboard') }}"
                        class="flex items-center gap-4 px-4 py-3 text-base font-medium {{ request()->is('app/dashboard*') ? 'active bg-primary/20 text-primary shadow-lg' : 'hover:bg-base-200/70' }} rounded-xl transition-all duration-200 hover:shadow-md hover:scale-[1.02]">
                        <x-icons.dashboard></x-icons.dashboard>
                        Tableau de bord
                    </a>
                </li>
                <li>
                    <a href="{{ route('app.users') }}"
                        class="flex items-center gap-4 px-4 py-3 text-base font-medium {{ request()->is('app/users*') ? 'active bg-primary/20 text-primary shadow-lg' : 'hover:bg-base-200/70' }} rounded-xl transition-all duration-200 hover:shadow-md hover:scale-[1.02]">
                        <i class="fa-solid fa-users text-xl"></i>
                        Utilisateurs
                    </a>
                </li>
                <li>
                    <a href="{{ route('app.rights-management') }}"
                        class="flex items-center gap-4 px-4 py-3 text-base font-medium {{ request()->is('app/rights-management*') ? 'active bg-primary/20 text-primary shadow-lg' : 'hover:bg-base-200/70' }} rounded-xl transition-all duration-200 hover:shadow-md hover:scale-[1.02]">
                        <i class="fa-solid fa-shield-halved text-xl"></i>
                        Gestion des droits
                    </a>
                </li>
                <li>
                    <a href="{{ route('app.parc.index') }}"
                        class="flex items-center gap-4 py-3 text-base font-medium {{ request()->is('app/parc') || request()->is('app/parc/*') ? 'active bg-primary/20 text-primary shadow-lg' : 'hover:bg-base-200/70' }} rounded-xl transition-all duration-200 hover:shadow-md hover:scale-[1.02]">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4">
                            </path>
                        </svg>
                        Gestion du parc
                    </a>
                </li>
                <li>
                    <a href="{{ route('app.parc-settings.index') }}"
                        class="flex items-center gap-4 px-4 py-3 text-base font-medium {{ request()->is('app/parc-settings') || request()->is('app/parc-settings/profiles*') || request()->is('app/parc-settings/applications*') ? 'active bg-primary/20 text-primary shadow-lg' : 'hover:bg-base-200/70' }} rounded-xl transition-all duration-200 hover:shadow-md hover:scale-[1.02]">
                        <x-icons.apps />
                        Applications
                    </a>
                </li>
                @can('server.admin')
                    {{-- Réglages — landing cards regroupant Système / GPO / Migration / Réseau.
                         Visible uniquement server.admin (action critique). --}}
                    <li>
                        <a href="{{ route('admin.settings') }}"
                            class="flex items-center gap-4 px-4 py-3 text-base font-medium {{ request()->is('admin/settings*') || request()->is('admin/quotas*') || request()->is('admin/controlHub/*') || request()->is('app/network/dhcp*') ? 'active bg-primary/20 text-primary shadow-lg' : 'hover:bg-base-200/70' }} rounded-xl transition-all duration-200 hover:shadow-md hover:scale-[1.02]">
                            <i class="fa-solid fa-cog text-xl"></i>
                            Réglages
                        </a>
                    </li>
                @endcan
            </ul>
        </div>
    </aside>
</div>

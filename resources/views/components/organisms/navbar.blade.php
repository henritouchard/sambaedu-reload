<!-- Navbar -->
<div class="top-2 p-2 border-b border-zinc-200 flex items-center justify-between shadow-none bg-base-100">
    <!-- Mobile menu button - visible only on small screens -->
    <label for="drawer-toggle" class="btn btn-ghost btn-circle lg:hidden">
        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path>
        </svg>
    </label>

    <livewire:organisms.search-modal />
    <div class="gap-y-2">
        <!-- Theme toggle button -->
        <a href="/blank.php" target="_blank" title="Accès à l'ancienne interface">
            <i class="fa-solid fa-clock-rotate-left"></i>
        </a>

        {{-- <x-atoms.theme-toggle position="relative" size="md" /> --}}

        <!-- Notifications -->
        <div class="dropdown dropdown-end">
            <div tabindex="0" role="button" class="btn btn-ghost btn-circle">
                <div class="indicator">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9">
                        </path>
                    </svg>
                    <span class="badge badge-xs badge-primary indicator-item"></span>
                </div>
            </div>
            <div tabindex="0"
                class="dropdown-content menu bg-base-100 rounded-box z-[1] w-80 p-2 shadow-lg border border-base-200">
                <div class="p-3 border-b border-base-200">
                    <h3 class="font-semibold">Notifications</h3>
                </div>
                <div class="p-2">
                    <div class="alert alert-info text-sm">
                        <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd"
                                d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z"
                                clip-rule="evenodd"></path>
                        </svg>
                        <span>Nouvelle mise à jour disponible</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- User menu -->
        <div class="dropdown dropdown-end">
            @php
                $currentLogin = $_SESSION['login'] ?? (session('login') ?? 'admin');
                $userInitials = strtoupper(substr($currentLogin, 0, 2));
            @endphp
            <div tabindex="0" role="button">
                <x-atoms.avatar-placeholder :initials="$userInitials" :color="'primary'" size="w-8" />
            </div>
            <ul tabindex="0"
                class="dropdown-content menu bg-base-100 rounded-box z-[1] w-52 p-2 shadow-lg border border-base-200">
                <li><a class="flex items-center gap-2" href="{{ route('app.user.show', $currentLogin) }}">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z">
                            </path>
                        </svg>
                        Profil
                    </a></li>
                <li><a class="flex items-center gap-2" href="/annu/mod_entry.php">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z">
                            </path>
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                        </svg>
                        Paramètres
                    </a></li>

                <div class="divider my-1"></div>
                <li><a class="flex items-center gap-2 text-error" href="{{ route('auth.logout') }}">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1">
                            </path>
                        </svg>
                        Déconnexion
                    </a></li>
            </ul>
        </div>
    </div>
</div>

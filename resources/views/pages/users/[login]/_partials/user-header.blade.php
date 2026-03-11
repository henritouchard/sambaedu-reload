<!-- En-tête avec actions principales -->
<div class="flex flex-col mb-6 w-full">
    <div class="card-body p-0">
        <div class="flex items-start gap-6 pb-4">
            <!-- Avatar -->
            <div class="card w-full">
                <div class="card-body">

                    <div class="flex items-start justify-between mb-8">
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-6 mb-4">
                                <x-atoms.avatar-placeholder :initials="substr($user->firstname ?? 'U', 0, 1) . substr($user->lastname ?? 'U', 0, 1)" size="size-20" textSize="text-3xl" />
                                <div class="space-y-2">
                                    <h1
                                        class="text-3xl flex items-start gap-2 font-black bg-gradient-to-r from-base-content to-base-content/80 bg-clip-text text-transparent leading-tight">
                                        {{ $user->fullname }}
                                        <span title="utilisateur connecté" class="inline-grid *:[grid-area:1/1] p-2">
                                            <div class="status status-success animate-ping"></div>
                                            <div class="status status-success"></div>
                                        </span>
                                    </h1>
                                    <div class="flex items-center gap-3 flex-wrap">
                                        <!-- Informations principales -->
                                        <div class="flex-1 min-w-0">
                                            <!-- Badges de statut -->
                                            <div class="flex flex-wrap gap-2">
                                                @if ($user->isEleve())
                                                    <div
                                                        class="badge badge-info badge-lg gap-2 px-4 py-2 shadow-md hover:shadow-lg transition-all duration-300">
                                                        <svg class="w-4 h-4" fill="none" stroke="currentColor"
                                                            viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round"
                                                                stroke-width="2"
                                                                d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.746 0 3.332.477 4.5 1.253v13C19.832 18.477 18.246 18 16.5 18c-1.746 0-3.332.477-4.5 1.253">
                                                            </path>
                                                        </svg>
                                                        Élève
                                                    </div>
                                                @endif
                                                @if ($user->isProf())
                                                    <div
                                                        class="badge badge-success badge-lg gap-2 px-4 py-2 shadow-md hover:shadow-lg transition-all duration-300">
                                                        <svg class="w-4 h-4" fill="none" stroke="currentColor"
                                                            viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round"
                                                                stroke-width="2"
                                                                d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4">
                                                            </path>
                                                        </svg>
                                                        Professeur
                                                    </div>
                                                @endif
                                                @if ($user->isDisabled())
                                                    <div
                                                        class="badge badge-error badge-outline badge-lg px-4 py-2 shadow-md">
                                                        <svg class="w-4 h-4" fill="none" stroke="currentColor"
                                                            viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round"
                                                                stroke-width="2"
                                                                d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636">
                                                            </path>
                                                        </svg>
                                                        Inactif
                                                    </div>
                                                @else
                                                    <div
                                                        class="badge badge-success badge-outline badge-lg px-4 py-2 shadow-md">
                                                        <svg class="w-4 h-4" fill="none" stroke="currentColor"
                                                            viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round"
                                                                stroke-width="2"
                                                                d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z">
                                                            </path>
                                                        </svg>
                                                        Actif
                                                    </div>
                                                @endif
                                            </div>
                                        </div>
                                        @if ($user->isDisabled())
                                            <span
                                                class="badge badge-error badge-lg gap-2 px-4 py-2 shadow-md animate-pulse">
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor"
                                                    viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round"
                                                        stroke-width="2"
                                                        d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636">
                                                    </path>
                                                </svg>
                                                Compte désactivé
                                            </span>
                                        @endif
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-6">
                        <!-- Login -->
                        <div
                            class="stat bg-gradient-to-br from-base-100 to-base-50 rounded-2xl shadow-lg hover:shadow-xl transition-all duration-500 border border-base-200/30 hover:border-primary/20 group">
                            <div
                                class="stat-figure text-primary group-hover:scale-110 transition-transform duration-300">
                                <div class="bg-primary/10 p-3 rounded-xl">
                                    <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                            d="M16 12a4 4 0 10-8 0 4 4 0 008 0zm0 0v1.5a2.5 2.5 0 005 0V12a9 9 0 10-9 9m4.5-1.206a8.959 8.959 0 01-4.5 1.207">
                                        </path>
                                    </svg>
                                </div>
                            </div>
                            <div class="stat-title font-medium">Login d'utilisateur</div>
                            <div class="stat-value text-lg font-bold text-primary">{{ $user->login ?? '' }}</div>
                            <div class="stat-desc">Identifiant unique</div>
                        </div>

                        <!-- Rôle -->
                        @php
                            $roleLabel = match (strtolower((string) $user->role)) {
                                'eleves', 'eleve' => 'Élève',
                                'profs', 'prof' => 'Professeur',
                                'administratifs', 'admin', 'administratif' => 'Administratif',
                                default => ucfirst((string) ($user->role ?? 'Non défini')),
                            };
                        @endphp
                        <div
                            class="stat bg-gradient-to-br from-base-100 to-base-50 rounded-2xl shadow-lg hover:shadow-xl transition-all duration-500 border border-base-200/30 hover:border-info/20 group">
                            <div class="stat-figure text-info group-hover:scale-110 transition-transform duration-300">
                                <div class="bg-info/10 p-3 rounded-xl">
                                    <i class="fa-solid fa-user-tag text-2xl"></i>
                                </div>
                            </div>
                            <div class="stat-title font-medium">Rôle</div>
                            <div class="stat-value text-lg font-bold text-info">{{ $roleLabel }}</div>
                            <div class="stat-desc">Profil principal</div>
                        </div>

                        <!-- Email -->
                        @if (!empty($user->email))
                            <div
                                class="stat rounded-2xl shadow-lg hover:shadow-xl transition-all duration-500 border border-base-200/30 hover:border-secondary/20 group overflow-hidden">
                                <div
                                    class="stat-figure text-secondary group-hover:scale-110 transition-transform duration-300">
                                    <div class="bg-secondary/10 p-3 rounded-xl">
                                        <i class="text-2xl fa-regular fa-envelope"></i>
                                    </div>
                                </div>
                                <div class="stat-title  font-medium">Adresse email</div>
                                <div class="stat-value text-lg font-bold">
                                    <a href="mailto:{{ $user->email }}"
                                        class="link hover:link-primary transition-all duration-300 hover:scale-105 inline-block max-w-full truncate"
                                        title="{{ $user->email }}">
                                        {{ $user->email }}
                                    </a>
                                </div>
                            </div>
                        @else
                            <div class="stat rounded-2xl shadow-lg border border-base-200/30">
                                <div class="stat-figure ">
                                    <div class="bg-base-200 p-3 rounded-xl">
                                        <i class="text-2xl fa-regular fa-envelope"></i>
                                    </div>
                                </div>
                                <div class="stat-title font-medium">Adresse email</div>
                                <div class="stat-value text-lg ">Non définie</div>
                                <div class="stat-desc ">Aucune adresse configurée</div>
                            </div>
                        @endif

                        <!-- Établissement -->
                        @if ($user->etabName || $user->etabCode)
                            <div
                                class="stat rounded-2xl shadow-lg hover:shadow-xl transition-all duration-500 border border-base-200/30 hover:border-accent/20 group">
                                <div
                                    class="stat-figure text-accent group-hover:scale-110 transition-transform duration-300">
                                    <div class="bg-accent/10 p-3 rounded-xl">
                                        <svg class="w-8 h-8" fill="none" stroke="currentColor"
                                            viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4">
                                            </path>
                                        </svg>
                                    </div>
                                </div>
                                <div class="stat-title  font-medium">Établissement</div>
                                <div class="stat-value text-lg font-bold text-accent">
                                    {{ $user->etabName ?? $user->etabCode }}</div>
                                <div class="stat-desc ">Institution de rattachement</div>
                            </div>
                        @else
                            <div
                                class="stat bg-gradient-to-br from-base-100 to-base-50 rounded-2xl shadow-lg border border-base-200/30">
                                <div class="stat-figure">
                                    <div class="p-3 rounded-xl bg-emerald-200">
                                        <i class="fa-solid fa-school text-emerald-500 text-2xl"></i>
                                    </div>
                                </div>
                                <div class="stat-title  font-medium">Établissement</div>
                                <div class="stat-value text-lg ">Non défini</div>
                                <div class="stat-desc ">Aucun établissement assigné</div>
                            </div>
                        @endif
                    </div>

                    <!-- Informations personnelles -->
                    @livewire('pages::users.[login]._partials.personal-info-form', ['user' => $user], key('personal-info-' . $user->login))
                    <!-- Card de debug -->
                    {{-- <div class="card bg-base-200 border border-warning shadow-lg mt-6">
                        <div class="card-body">
                            <h2 class="card-title text-warning flex items-center gap-2">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z">
                                    </path>
                                </svg>
                                Debug - Données utilisateur (JSON)
                            </h2>
                            <div class="collapse collapse-arrow bg-base-100 border border-base-300">
                                <input type="checkbox" class="peer" />
                                <div class="collapse-title text-sm font-medium">
                                    Cliquer pour afficher/masquer le JSON complet
                                </div>
                                <div class="collapse-content">
                                    <pre class="text-xs bg-base-200 p-4 rounded-lg overflow-x-auto whitespace-pre-wrap">{{ json_encode($user, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
                                </div>
                            </div>
                        </div>
                    </div> --}}
                </div>
            </div>
        </div>
    </div>

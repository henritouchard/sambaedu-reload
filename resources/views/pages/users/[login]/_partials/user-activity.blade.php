<!-- Activité de l'utilisateur -->
<div
    class="bg-gradient-to-br from-primary/10 via-secondary/5 to-accent/10 rounded-3xl border border-base-300 shadow-xl backdrop-blur-sm h-full overflow-hidden">
    <div class="p-8">
        <div class="flex items-center gap-4 mb-8">
            <div
                class="w-12 h-12 bg-gradient-to-br from-success to-success/80 rounded-2xl flex items-center justify-center shadow-lg ring-4 ring-success/20 group-hover:scale-110 transition-transform duration-300">
                <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                </svg>
            </div>
            <div>
                <h2
                    class="text-2xl font-black text-base-content bg-gradient-to-r from-base-content to-base-content/80 bg-clip-text">
                    Activité récente</h2>
                <p class="text-sm text-base-content/60 font-medium">Connexions et actions récentes</p>
            </div>
        </div>

        @php
            $rawUserActivity = $userActivity ?? null;
            $activity = is_array($rawUserActivity)
                ? json_decode(json_encode($rawUserActivity), false)
                : $rawUserActivity;
        @endphp

        @if (!empty($activity))
            <div class="space-y-4">
                <!-- Dernière connexion -->
                @if (isset($activity->last_login))
                    <div class="flex items-start gap-3 p-3 rounded-lg bg-base-50">
                        <div
                            class="w-8 h-8 bg-success/10 rounded-full flex items-center justify-center flex-shrink-0 mt-0.5">
                            <svg class="w-4 h-4 text-success" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                            </svg>
                        </div>
                        <div class="flex-1">
                            <div class="font-medium text-base-content">Dernière connexion</div>
                            <div class="text-sm text-base-content/70">{{ $activity->last_login->date ?? '' }}</div>
                            @if (isset($activity->last_login->location))
                                <div class="text-sm text-base-content/50">{{ $activity->last_login->location }}
                                </div>
                            @endif
                        </div>
                    </div>
                @endif

                <!-- Sessions actives -->
                @if (!empty($activity->active_sessions ?? null))
                    <div class="space-y-2">
                        <div class="font-medium text-base-content/70">Sessions actives</div>
                        @foreach ($activity->active_sessions as $session)
                            <div class="flex items-center gap-3 p-2 rounded-lg bg-base-50">
                                <div class="w-6 h-6 bg-info/10 rounded-full flex items-center justify-center">
                                    <svg class="w-3 h-3 text-info" fill="none" stroke="currentColor"
                                        viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                            d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z">
                                        </path>
                                    </svg>
                                </div>
                                <div class="flex-1">
                                    <div class="text-sm font-medium text-base-content">
                                        {{ $session->machine ?? 'Poste inconnu' }}</div>
                                    <div class="text-xs text-base-content/50">{{ $session->start_time ?? '' }}</div>
                                </div>
                                <div class="w-2 h-2 bg-success rounded-full"></div>
                            </div>
                        @endforeach
                    </div>
                @endif

                <!-- Activité des dernières 24h -->
                @if (isset($activity->daily_stats))
                    <div class="p-3 rounded-lg bg-base-50">
                        <div class="font-medium text-base-content mb-2">Dernières 24 heures</div>
                        <div class="grid grid-cols-2 gap-4 text-sm">
                            <div>
                                <div class="text-base-content/70">Connexions</div>
                                <div class="font-semibold text-base-content">
                                    {{ $activity->daily_stats->connections ?? 0 }}</div>
                            </div>
                            <div>
                                <div class="text-base-content/70">Temps total</div>
                                <div class="font-semibold text-base-content">
                                    {{ $activity->daily_stats->total_time ?? '0h' }}</div>
                            </div>
                        </div>
                    </div>
                @endif

                <!-- Actions récentes -->
                @if (!empty($activity->recent_actions ?? null))
                    <div class="space-y-2">
                        <div class="font-medium text-base-content/70">Actions récentes</div>
                        @foreach ($activity->recent_actions as $action)
                            <div class="flex items-start gap-3 p-2 rounded-lg hover:bg-base-200 transition-colors">
                                <div
                                    class="w-6 h-6 bg-warning/10 rounded-full flex items-center justify-center flex-shrink-0 mt-0.5">
                                    @php
                                        $icon = match ($action->type ?? '') {
                                            'login'
                                                => 'M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z',
                                            'file'
                                                => 'M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z',
                                            'print'
                                                => 'M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z',
                                            default => 'M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z',
                                        };
                                    @endphp
                                    <svg class="w-3 h-3 text-warning" fill="none" stroke="currentColor"
                                        viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                            d="{{ $icon }}"></path>
                                    </svg>
                                </div>
                                <div class="flex-1">
                                    <div class="text-sm text-base-content">
                                        {{ $action->description ?? 'Action inconnue' }}</div>
                                    <div class="text-xs text-base-content/50">{{ $action->time ?? '' }}</div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        @else
            <!-- Aucune activité -->
            <div class="text-center py-8">
                <svg class="w-12 h-12 mx-auto mb-3 text-base-content/30" fill="none" stroke="currentColor"
                    viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                </svg>
                <p class="text-base-content/50">Aucune activité récente</p>
                <p class="text-sm text-base-content/30 mt-1">L'utilisateur ne s'est pas connecté récemment</p>
            </div>
        @endif

        <!-- Lien vers l'historique complet -->
        @if ($isOwnProfile)
            <div class="mt-6 pt-4 border-t border-base-300">
                <a href="/parcs/show_histo.php?selectionne=3&user={{ $user->login }}"
                    class="btn btn-sm btn-ghost btn-block justify-center">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                    </svg>
                    Voir l'historique complet
                </a>
            </div>
        @elsecan('view-user')
            <div class="mt-6 pt-4 border-t border-base-300">
                <a href="/parcs/show_histo.php?selectionne=3&user={{ $user->login }}"
                    class="btn btn-sm btn-ghost btn-block justify-center">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                    </svg>
                    Voir l'historique complet
                </a>
            </div>
        @endif
    </div>
</div>

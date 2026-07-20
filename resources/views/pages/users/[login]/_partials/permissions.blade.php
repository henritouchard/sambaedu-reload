{{--
    Story 7.x — Card "Permissions" : remplace l'ancienne vue bitmask/LDAP par
    l'état Spatie complet (rôles, permissions directes, permissions via rôles)
    + délégations scopées (par salle).

    Variables Livewire injectées par le SFC parent (pages/users/[login]/index) :
      - $spatieRoles        : array<array{id:int,name:string}>  rôles Spatie
      - $directPermissions  : array<string>  permissions directes
      - $rolePermissions    : array<string>  permissions via rôles
      - $delegations        : array<array{id,workstation_group,workstation_group_id,permission,is_negative,expires_at,expires_at_iso}>
--}}

<div
    class="bg-gradient-to-br from-warning/10 via-warning/5 to-error/10 rounded-3xl border border-base-300 shadow-xl backdrop-blur-sm overflow-hidden flex flex-col h-full">
    <div class="p-6 flex flex-col flex-1 min-h-0">
        <div class="flex items-center gap-4 mb-6">
            <div
                class="w-10 h-10 bg-gradient-to-br from-warning to-warning/80 rounded-xl flex items-center justify-center shadow-lg ring-4 ring-warning/20">
                <i class="fa-solid fa-shield-halved text-white"></i>
            </div>
            <div>
                <h2 class="text-xl font-bold text-base-content">Permissions</h2>
                <p class="text-sm text-base-content/60">Rôles, permissions et délégations</p>
            </div>
        </div>

        <div class="space-y-4 flex-1 overflow-y-auto pr-1 min-h-0">
            {{-- Rôles Spatie --}}
            <div>
                <h3 class="text-xs font-bold uppercase tracking-wider text-base-content/60 mb-2">
                    <i class="fa-solid fa-user-tag mr-1 text-primary"></i>
                    Rôles
                </h3>
                @if (!empty($spatieRoles))
                    <div class="flex flex-wrap gap-1">
                        @foreach ($spatieRoles as $role)
                            <a href="{{ route('app.rights-management.profiles.show', ['id' => $role['id']]) }}"
                                class="badge badge-primary hover:badge-primary/80" title="Voir le profil">
                                {{ $role['name'] }}
                            </a>
                        @endforeach
                    </div>
                @else
                    <p class="pl-6 text-xs text-base-content/40">Aucun rôle assigné.</p>
                @endif
            </div>

            {{-- Permissions directes --}}
            <div>
                <h3 class="text-xs font-bold uppercase tracking-wider text-base-content/60 mb-2">
                    <i class="fa-solid fa-key mr-1 text-secondary"></i>
                    Permissions directes
                    <span class="ml-1 text-base-content/40 normal-case font-normal">
                        (accordées hors rôle)
                    </span>
                </h3>
                @if (!empty($directPermissions))
                    <div class="flex flex-wrap gap-1">
                        @foreach ($directPermissions as $perm)
                            <span class="badge badge-secondary badge-sm font-mono">{{ $perm }}</span>
                        @endforeach
                    </div>
                @else
                    <p class="pl-6 text-xs text-base-content/40">Aucune permission directe.</p>
                @endif
            </div>

            {{-- Permissions via rôles --}}
            <div>
                @if (!empty($rolePermissions))
                    <h3 class="text-xs font-bold uppercase tracking-wider text-base-content/60 mb-2">
                        <i class="fa-solid fa-key mr-1 text-accent"></i>
                        Permissions via rôles
                    </h3>
                    <div class="flex flex-wrap gap-1">
                        @foreach ($rolePermissions as $perm)
                            <span class="badge badge-accent badge-outline badge-sm font-mono">{{ $perm }}</span>
                        @endforeach
                    </div>
                @endif
            </div>

            {{-- Délégations scopées --}}
            <div>
                <h3 class="text-xs font-bold uppercase tracking-wider text-base-content/60 mb-2">
                    <i class="fa-solid fa-building mr-1 text-warning"></i>
                    Délégations sur salles
                </h3>
                @if (!empty($delegations))
                    <div class="space-y-1">
                        @foreach ($delegations as $d)
                            <button type="button"
                                @click="Livewire.dispatch('open-delegation-modal', {
                                    users: ['{{ $user->login }}'],
                                    workstationGroupId: {{ $d['workstation_group_id'] }},
                                    permission: '{{ $d['permission'] }}',
                                    expiresAt: {{ $d['expires_at_iso'] ? "'" . $d['expires_at_iso'] . "'" : 'null' }}
                                })"
                                class="w-full flex items-center justify-between gap-2 p-2 rounded-lg border border-base-300 bg-base-100 hover:bg-warning/5 text-left transition-colors">
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-center gap-2">
                                        <span class="font-medium text-sm">{{ $d['workstation_group'] }}</span>
                                        @if ($d['is_negative'])
                                            <span class="badge badge-error badge-xs">Exclusion</span>
                                        @else
                                            <span class="badge badge-success badge-xs">Accordée</span>
                                        @endif
                                    </div>
                                    <div class="text-xs text-base-content/60 font-mono">{{ $d['permission'] }}</div>
                                </div>
                                <div class="text-xs text-base-content/50 shrink-0">
                                    {{ $d['expires_at'] ?? 'Permanente' }}
                                </div>
                            </button>
                        @endforeach
                    </div>
                @else
                    <p class="text-xs text-base-content/40">Aucune délégation active.</p>
                @endif
            </div>
        </div>

        @can('user.assign.right')
            <div class="divider my-3"></div>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                <button type="button" @click="Livewire.dispatch('open-rights-drawer', { users: ['{{ $user->login }}'] })"
                    class="btn btn-sm btn-outline btn-primary">
                    <i class="fa-solid fa-shield-halved"></i>
                    Rôles & permissions
                </button>
                <button type="button"
                    @click="Livewire.dispatch('open-delegation-modal', { users: ['{{ $user->login }}'] })"
                    class="btn btn-sm btn-outline btn-warning">
                    <i class="fa-solid fa-building"></i>
                    Délégation sur salle
                </button>
            </div>
        @endcan
    </div>
</div>

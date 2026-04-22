@php
    use App\Services\Filesystem\XfsQuotaService;
    use App\Models\QuotaRule;

    $quotaService = app(XfsQuotaService::class);

    // Récupérer les groupes de l'utilisateur (noms simples)
    $userGroups = collect($user->groups ?? [])->map(function ($group) {
        if (is_array($group)) {
            return $group['cn'] ?? $group['name'] ?? '';
        }
        // Extraire le CN du DN si nécessaire
        if (preg_match('/^CN=([^,]+)/i', $group, $m)) {
            return $m[1];
        }
        return $group;
    })->filter()->toArray();

    // Déterminer le profil
    $userProfile = 'eleve';
    foreach ($userGroups as $group) {
        $groupLower = strtolower($group);
        if (str_contains($groupLower, 'admin') || str_contains($groupLower, 'domain admins')) {
            $userProfile = 'admin';
            break;
        }
        if (str_contains($groupLower, 'prof') || str_contains($groupLower, 'enseignant')) {
            $userProfile = 'prof';
        }
    }

    // Récupérer les quotas effectifs
    $quotaHome = $quotaService->getEffectiveQuota($user->login, QuotaRule::PARTITION_HOME, $userGroups, $userProfile);
    $quotaSambaedu = $quotaService->getEffectiveQuota($user->login, QuotaRule::PARTITION_SAMBAEDU, $userGroups, $userProfile);

    // Récupérer l'utilisation disque
    $diskUsage = $quotaService->getDiskUsage($user->login);

    // Fonction helper pour formater
    $formatQuota = function ($mb) {
        if ($mb === 0) {
            return 'Illimité';
        }
        return $mb >= 1024 ? round($mb / 1024, 1) . ' Go' : $mb . ' Mo';
    };

    $formatUsage = function ($mb) {
        return $mb >= 1024 ? round($mb / 1024, 1) . ' Go' : $mb . ' Mo';
    };

    $getProgressClass = function ($percent) {
        if ($percent >= 100) {
            return 'progress-error';
        }
        if ($percent >= 80) {
            return 'progress-warning';
        }
        return 'progress-success';
    };
@endphp

<div class="card bg-base-100 shadow-sm border border-base-300">
    <div class="card-body">
        <h3 class="card-title text-lg mb-4">
            <i class="fa-solid fa-hard-drive mr-2"></i>
            Quotas disque
        </h3>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            {{-- Partition /home --}}
            <div class="space-y-3">
                <div class="flex items-center justify-between">
                    <span class="font-medium">Espace personnel (K:)</span>
                    <code class="text-xs opacity-70">/home</code>
                </div>

                @php
                    $homeUsage = $diskUsage['home'] ?? [];
                    $homeUsedMb = $homeUsage['used_mb'] ?? 0;
                    $homeSoftMb = $quotaHome['quota_soft_mb'];
                    $homePercent = $homeSoftMb > 0 ? min(100, round($homeUsedMb / $homeSoftMb * 100)) : 0;
                @endphp

                @if ($quotaHome['is_unlimited'])
                    <div class="text-center py-4 bg-base-200 rounded-lg">
                        <span class="text-2xl font-bold text-success">Illimité</span>
                        <p class="text-sm opacity-70 mt-1">
                            Utilisé : {{ $formatUsage($homeUsedMb) }}
                        </p>
                    </div>
                @else
                    <div class="space-y-2">
                        <div class="flex justify-between text-sm">
                            <span>{{ $formatUsage($homeUsedMb) }} / {{ $formatQuota($homeSoftMb) }}</span>
                            <span class="{{ $homePercent >= 80 ? 'text-warning' : '' }} {{ $homePercent >= 100 ? 'text-error' : '' }}">
                                {{ $homePercent }}%
                            </span>
                        </div>
                        <progress class="progress {{ $getProgressClass($homePercent) }} w-full"
                            value="{{ $homePercent }}" max="100"></progress>

                        @if ($homeUsage['is_over_soft'] ?? false)
                            <div class="alert alert-warning py-2 text-sm">
                                <i class="fa-solid fa-exclamation-triangle"></i>
                                <span>
                                    Quota dépassé !
                                    @if (($homeUsage['grace_days'] ?? 0) > 0)
                                        Grâce : {{ $homeUsage['grace_days'] }} jour(s)
                                    @else
                                        Écriture bloquée
                                    @endif
                                </span>
                            </div>
                        @endif
                    </div>
                @endif

                <div class="text-xs opacity-70">
                    Source : 
                    @if ($quotaHome['source'] === 'user')
                        <span class="badge badge-xs badge-info">Quota personnel</span>
                    @elseif ($quotaHome['source'] === 'group')
                        <span class="badge badge-xs badge-secondary">{{ $quotaHome['source_name'] }}</span>
                    @elseif ($quotaHome['source'] === 'default')
                        <span class="badge badge-xs">{{ $quotaHome['source_name'] }}</span>
                    @else
                        <span class="badge badge-xs badge-ghost">Aucune règle</span>
                    @endif
                </div>
            </div>

            {{-- Partition /var/sambaedu --}}
            <div class="space-y-3">
                <div class="flex items-center justify-between">
                    <span class="font-medium">Partages (Classes/Docs)</span>
                    <code class="text-xs opacity-70">/var/sambaedu</code>
                </div>

                @php
                    $seUsage = $diskUsage['sambaedu'] ?? [];
                    $seUsedMb = $seUsage['used_mb'] ?? 0;
                    $seSoftMb = $quotaSambaedu['quota_soft_mb'];
                    $sePercent = $seSoftMb > 0 ? min(100, round($seUsedMb / $seSoftMb * 100)) : 0;
                @endphp

                @if ($quotaSambaedu['is_unlimited'])
                    <div class="text-center py-4 bg-base-200 rounded-lg">
                        <span class="text-2xl font-bold text-success">Illimité</span>
                        <p class="text-sm opacity-70 mt-1">
                            Utilisé : {{ $formatUsage($seUsedMb) }}
                        </p>
                    </div>
                @else
                    <div class="space-y-2">
                        <div class="flex justify-between text-sm">
                            <span>{{ $formatUsage($seUsedMb) }} / {{ $formatQuota($seSoftMb) }}</span>
                            <span class="{{ $sePercent >= 80 ? 'text-warning' : '' }} {{ $sePercent >= 100 ? 'text-error' : '' }}">
                                {{ $sePercent }}%
                            </span>
                        </div>
                        <progress class="progress {{ $getProgressClass($sePercent) }} w-full"
                            value="{{ $sePercent }}" max="100"></progress>

                        @if ($seUsage['is_over_soft'] ?? false)
                            <div class="alert alert-warning py-2 text-sm">
                                <i class="fa-solid fa-exclamation-triangle"></i>
                                <span>
                                    Quota dépassé !
                                    @if (($seUsage['grace_days'] ?? 0) > 0)
                                        Grâce : {{ $seUsage['grace_days'] }} jour(s)
                                    @else
                                        Écriture bloquée
                                    @endif
                                </span>
                            </div>
                        @endif
                    </div>
                @endif

                <div class="text-xs opacity-70">
                    Source : 
                    @if ($quotaSambaedu['source'] === 'user')
                        <span class="badge badge-xs badge-info">Quota personnel</span>
                    @elseif ($quotaSambaedu['source'] === 'group')
                        <span class="badge badge-xs badge-secondary">{{ $quotaSambaedu['source_name'] }}</span>
                    @elseif ($quotaSambaedu['source'] === 'default')
                        <span class="badge badge-xs">{{ $quotaSambaedu['source_name'] }}</span>
                    @else
                        <span class="badge badge-xs badge-ghost">Aucune règle</span>
                    @endif
                </div>
            </div>
        </div>

        {{-- Lien vers la gestion des quotas --}}
        @can('manage-quotas')
            <div class="mt-4 pt-4 border-t border-base-300">
                <a href="{{ route('app.users', ['tab' => 'quotas']) }}" class="btn btn-sm btn-ghost gap-2">
                    <i class="fa-solid fa-sliders"></i>
                    Gérer les quotas
                </a>
            </div>
        @endcan
    </div>
</div>

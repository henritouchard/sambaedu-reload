{{--
    Story 15.5 / AC3.2 — KPIs globaux du dashboard `/app/wpkg/deployments`.
    Réutilise le pattern `pages/parc/_partials/stats-cards.blade.php`.

    Variables attendues :
      $kpis : array (cf. WpkgDashboardQueryService::kpis())
      $kpisLoaded : bool
--}}
<div class="grid grid-cols-2 md:grid-cols-4 gap-4">
    @if (! $kpisLoaded)
        @for ($i = 0; $i < 4; $i++)
            <div class="card bg-base-100 shadow-sm">
                <div class="card-body py-4">
                    <div class="skeleton h-4 w-20 mb-2"></div>
                    <div class="skeleton h-8 w-16"></div>
                </div>
            </div>
        @endfor
    @else
        @php
            $total = max(1, (int) ($kpis['total'] ?? 0));
            $successPct = round(100 * (int) ($kpis['success'] ?? 0) / $total, 1);
            $partialPct = round(100 * (int) ($kpis['partial'] ?? 0) / $total, 1);
            $failedPct = round(100 * (int) ($kpis['failed'] ?? 0) / $total, 1);
            $silentPct = round(100 * (int) ($kpis['silent'] ?? 0) / $total, 1);
        @endphp

        {{-- Postes au total + Sains --}}
        <div class="card bg-base-100 shadow-sm" data-test="kpi-success">
            <div class="card-body py-4">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-lg bg-success/10 flex items-center justify-center">
                        <i class="fa-solid fa-circle-check text-success"></i>
                    </div>
                    <div>
                        <div class="text-sm text-base-content/60">Sains</div>
                        <div class="text-2xl font-bold">{{ $kpis['success'] ?? 0 }}
                            <span class="text-sm font-normal text-base-content/60">({{ $successPct }}%)</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Partiels --}}
        <div class="card bg-base-100 shadow-sm" data-test="kpi-partial">
            <div class="card-body py-4">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-lg bg-warning/10 flex items-center justify-center">
                        <i class="fa-solid fa-triangle-exclamation text-warning"></i>
                    </div>
                    <div>
                        <div class="text-sm text-base-content/60">Partiels</div>
                        <div class="text-2xl font-bold">{{ $kpis['partial'] ?? 0 }}
                            <span class="text-sm font-normal text-base-content/60">({{ $partialPct }}%)</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- En échec --}}
        <div class="card bg-base-100 shadow-sm" data-test="kpi-failed">
            <div class="card-body py-4">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-lg bg-error/10 flex items-center justify-center">
                        <i class="fa-solid fa-circle-xmark text-error"></i>
                    </div>
                    <div>
                        <div class="text-sm text-base-content/60">En échec</div>
                        <div class="text-2xl font-bold">{{ $kpis['failed'] ?? 0 }}
                            <span class="text-sm font-normal text-base-content/60">({{ $failedPct }}%)</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Silencieux + dernière sync --}}
        <div class="card bg-base-100 shadow-sm" data-test="kpi-silent">
            <div class="card-body py-4">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-lg bg-base-300 flex items-center justify-center">
                        <i class="fa-solid fa-bell-slash text-base-content/60"></i>
                    </div>
                    <div>
                        <div class="text-sm text-base-content/60">Silencieux >7j</div>
                        <div class="text-2xl font-bold">{{ $kpis['silent'] ?? 0 }}
                            <span class="text-sm font-normal text-base-content/60">({{ $silentPct }}%)</span>
                        </div>
                        @if (! empty($kpis['last_sync']))
                            <div class="text-xs text-base-content/50 mt-1">
                                Dernière sync : {{ \Illuminate\Support\Carbon::parse($kpis['last_sync'])->diffForHumans() }}
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>

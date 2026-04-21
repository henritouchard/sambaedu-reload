{{--
    Encart résumé de batch (story 4-3, AC4).

    Affiché au-dessus du tableau des machines dès qu'un batch a été lancé.
    Contenu :
      - compteurs live (X succès / Y échecs / Z en cours)
      - libellé de l'action humanisé
      - liste nominative des échecs ({machine_name} — {error_message})
      - bouton "Effacer" (clearBatchSummary) : referme l'encart sans toucher
        aux rows machine_power_action_tasks (audit trail conservé).

    Le résumé est recalculé via $this->batchSummary (propriété computed
    Livewire, un SELECT unique par rendu).
--}}
@if ($batchSummaryVisible)
    @php
        $summary = $this->batchSummary;
        $progress = $summary['total'] > 0
            ? (int) round((($summary['success'] + $summary['failed']) / $summary['total']) * 100)
            : 0;
        $hasFailures = $summary['failed'] > 0;
        $allDone = $summary['running'] === 0;
    @endphp

    <div class="card bg-base-100 shadow-sm border {{ $hasFailures ? 'border-error/30' : ($allDone ? 'border-success/30' : 'border-info/30') }} mb-4">
        <div class="card-body py-4">
            <div class="flex flex-wrap items-center justify-between gap-3 mb-3">
                <div class="flex items-center gap-3">
                    @if ($batchRunning)
                        <span class="loading loading-spinner loading-sm text-info"></span>
                    @elseif ($hasFailures)
                        <i class="fa-solid fa-triangle-exclamation text-error text-xl"></i>
                    @else
                        <i class="fa-solid fa-circle-check text-success text-xl"></i>
                    @endif
                    <div>
                        <h4 class="font-semibold text-base">
                            Résumé du batch : {{ ucfirst($summary['action'] ?? $batchAction ?? 'action') }}
                        </h4>
                        <p class="text-xs text-base-content/60">
                            {{ $summary['success'] }} succès · {{ $summary['failed'] }} échecs · {{ $summary['running'] }} en cours
                        </p>
                    </div>
                </div>

                <button type="button" class="btn btn-ghost btn-sm"
                    wire:click="clearBatchSummary"
                    title="Effacer l'encart résumé (les logs restent en base pour l'audit)">
                    <i class="fa-solid fa-xmark"></i>
                    Effacer
                </button>
            </div>

            @if ($summary['total'] > 0)
                <progress
                    class="progress {{ $hasFailures ? 'progress-warning' : 'progress-success' }} w-full"
                    value="{{ $progress }}" max="100"></progress>
            @endif

            @if ($hasFailures)
                <div class="mt-3">
                    <p class="text-sm font-semibold text-error mb-2">
                        <i class="fa-solid fa-circle-xmark"></i>
                        Machines en échec ({{ count($summary['failures']) }})
                    </p>
                    <ul class="list-disc pl-6 space-y-1 text-sm">
                        @foreach ($summary['failures'] as $failure)
                            <li>
                                <span class="font-mono font-medium">{{ $failure['machine_name'] }}</span>
                                <span class="text-base-content/70">— {{ $failure['error_message'] }}</span>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </div>
    </div>
@endif

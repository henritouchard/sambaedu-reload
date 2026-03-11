@props([
    'paginator' => null,
    'currentPage' => 1,
    'lastPage' => 1,
    'total' => 0,
    'from' => 0,
    'to' => 0,
    'perPage' => 20,
    'allowedPerPage' => [10, 20, 50, 100],
    'showPerPage' => true,
    'showInfo' => true,
    'onPageChange' => 'setPage',
    'onPerPageChange' => null,
    'perPageModel' => 'perPage',
    'itemLabel' => 'élément',
    'itemLabelPlural' => 'éléments',
    'maxVisiblePages' => 5,
])

@php
    // Support pour LengthAwarePaginator de Laravel
    if ($paginator && $paginator instanceof \Illuminate\Pagination\LengthAwarePaginator) {
        $currentPage = $paginator->currentPage();
        $lastPage = $paginator->lastPage();
        $total = $paginator->total();
        $from = $paginator->firstItem() ?? 0;
        $to = $paginator->lastItem() ?? 0;
        $perPage = $paginator->perPage();
    }

    // Calcul des pages à afficher (limité à maxVisiblePages)
    $halfVisible = floor($maxVisiblePages / 2);
    $start = max(1, $currentPage - $halfVisible);
    $end = min($lastPage, $start + $maxVisiblePages - 1);

    // Ajuster le début si on est proche de la fin
    if ($end - $start + 1 < $maxVisiblePages) {
        $start = max(1, $end - $maxVisiblePages + 1);
    }

    $showStartEllipsis = $start > 2;
    $showEndEllipsis = $end < $lastPage - 1;
@endphp

@if ($lastPage > 1 || $showPerPage)
    <div class="flex flex-wrap justify-between items-center gap-4 py-3 px-4 border-t border-base-200 bg-base-100">
        {{-- Sélecteur nombre par page --}}
        @if ($showPerPage)
            <div class="flex items-center gap-2">
                <span class="text-sm text-base-content/60">Afficher</span>
                <select class="select select-bordered select-sm"
                    @if ($onPerPageChange) wire:change="{{ $onPerPageChange }}($event.target.value)"
                    @else
                        wire:model.live="{{ $perPageModel }}" @endif>
                    @foreach ($allowedPerPage as $value)
                        <option value="{{ $value }}" @selected($perPage == $value)>{{ $value }}</option>
                    @endforeach
                </select>
            </div>
        @endif

        {{-- Informations de pagination --}}
        @if ($showInfo && $total > 0)
            <div class="text-sm text-base-content/60">
                {{ $from }} - {{ $to }} sur {{ $total }}
                {{ $total > 1 ? $itemLabelPlural : $itemLabel }}
            </div>
        @endif

        {{-- Boutons de pagination --}}
        @if ($lastPage > 1)
            <div class="join">
                {{-- Bouton Précédent --}}
                @if ($currentPage > 1)
                    <button type="button" class="join-item btn btn-sm"
                        wire:click="{{ $onPageChange }}({{ $currentPage - 1 }})">
                        <i class="fa-solid fa-chevron-left text-xs"></i>
                    </button>
                @else
                    <button type="button" class="join-item btn btn-sm btn-disabled" disabled>
                        <i class="fa-solid fa-chevron-left text-xs"></i>
                    </button>
                @endif

                {{-- Première page --}}
                @if ($start > 1)
                    <button type="button" class="join-item btn btn-sm" wire:click="{{ $onPageChange }}(1)">
                        1
                    </button>
                    @if ($showStartEllipsis)
                        <span class="join-item btn btn-sm btn-disabled">...</span>
                    @endif
                @endif

                {{-- Pages visibles --}}
                @for ($i = $start; $i <= $end; $i++)
                    <button type="button" class="join-item btn btn-sm {{ $i == $currentPage ? 'btn-primary' : '' }}"
                        wire:click="{{ $onPageChange }}({{ $i }})">
                        {{ $i }}
                    </button>
                @endfor

                {{-- Dernière page --}}
                @if ($end < $lastPage)
                    @if ($showEndEllipsis)
                        <span class="join-item btn btn-sm btn-disabled">...</span>
                    @endif
                    <button type="button" class="join-item btn btn-sm"
                        wire:click="{{ $onPageChange }}({{ $lastPage }})">
                        {{ $lastPage }}
                    </button>
                @endif

                {{-- Bouton Suivant --}}
                @if ($currentPage < $lastPage)
                    <button type="button" class="join-item btn btn-sm"
                        wire:click="{{ $onPageChange }}({{ $currentPage + 1 }})">
                        <i class="fa-solid fa-chevron-right text-xs"></i>
                    </button>
                @else
                    <button type="button" class="join-item btn btn-sm btn-disabled" disabled>
                        <i class="fa-solid fa-chevron-right text-xs"></i>
                    </button>
                @endif
            </div>
        @endif
    </div>
@endif

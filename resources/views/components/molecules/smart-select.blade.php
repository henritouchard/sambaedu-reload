<?php

use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Modelable;
use Livewire\Component;

new class extends Component {
    #[Modelable]
    public mixed $value = '';

    /** @var array<int, mixed> */
    public array $options = [];

    public string $label = '';
    public string $placeholder = 'Selectionner...';
    public string $emptyText = 'Aucune option disponible';
    public bool $filterable = false;
    public bool $clearable = false;
    public bool $multiple = false;
    public bool $disabled = false;
    public bool $inline = false;
    public bool $showTrigger = true;
    public string $search = '';
    public string $wrapperClass = '';
    public string $labelClass = '';
    public string $triggerClass = '';
    public string $panelClass = '';
    public string $optionClass = '';
    public string $searchInputClass = '';
    public string $listClass = '';
    public string $emptyStateClass = '';

    public function mount(): void
    {
        $this->options = $this->normalizeOptions($this->options);

        if ($this->multiple) {
            if (!is_array($this->value)) {
                $this->value = $this->value === '' || $this->value === null ? [] : [$this->value];
            }
        } elseif (is_array($this->value)) {
            $this->value = $this->value[0] ?? '';
        }
    }

    public function updatedOptions(): void
    {
        $this->options = $this->normalizeOptions($this->options);
    }

    public function selectOption(mixed $selectedValue): void
    {
        if ($this->multiple) {
            $this->toggleOption($selectedValue);
            return;
        }

        $this->value = $selectedValue;
        $this->search = '';
    }

    public function clearSelection(): void
    {
        $this->value = $this->multiple ? [] : '';
        $this->search = '';
    }

    public function selectFilteredOption(int $index): void
    {
        $option = $this->filteredOptions->get($index);

        if (!$option || (bool) $option['disabled']) {
            return;
        }

        $this->selectOption($option['value']);
    }

    public function toggleFilteredOption(int $index): void
    {
        $option = $this->filteredOptions->get($index);

        if (!$option || (bool) $option['disabled']) {
            return;
        }

        $this->toggleOption($option['value']);
    }

    public function isOptionSelected(mixed $optionValue): bool
    {
        if (!$this->multiple || !is_array($this->value)) {
            return false;
        }

        foreach ($this->value as $selectedValue) {
            if ($this->valuesMatch($selectedValue, $optionValue)) {
                return true;
            }
        }

        return false;
    }

    #[Computed]
    public function filteredOptions(): Collection
    {
        $options = collect($this->options);

        if (!$this->filterable || $this->search === '') {
            return $options;
        }

        $needle = mb_strtolower($this->search);

        return $options
            ->filter(function (array $option) use ($needle): bool {
                return str_contains(mb_strtolower($option['label']), $needle) || str_contains(mb_strtolower((string) $option['value']), $needle) || str_contains(mb_strtolower($option['hint']), $needle);
            })
            ->values();
    }

    #[Computed]
    public function selectedOption(): ?array
    {
        if ($this->multiple || $this->value === '' || $this->value === null) {
            return null;
        }

        return collect($this->options)->first(fn(array $option): bool => $this->valuesMatch($option['value'], $this->value));
    }

    #[Computed]
    public function selectedOptions(): Collection
    {
        if (!$this->multiple || !is_array($this->value) || $this->value === []) {
            return collect();
        }

        return collect($this->options)->filter(fn(array $option): bool => $this->isOptionSelected($option['value']))->values();
    }

    /**
     * @param array<int, mixed> $rawOptions
     * @return array<int, array{value: mixed, label: string, hint: string, disabled: bool}>
     */
    private function normalizeOptions(array $rawOptions): array
    {
        $normalized = [];

        foreach ($rawOptions as $key => $option) {
            if (is_array($option)) {
                $value = $option['value'] ?? $key;
                $label = (string) ($option['label'] ?? $value);
                $hint = (string) ($option['hint'] ?? '');
                $disabled = (bool) ($option['disabled'] ?? false);

                $normalized[] = [
                    'value' => $value,
                    'label' => $label,
                    'hint' => $hint,
                    'disabled' => $disabled,
                ];
                continue;
            }

            if (is_string($key) && !is_array($option)) {
                $normalized[] = [
                    'value' => $key,
                    'label' => (string) $option,
                    'hint' => '',
                    'disabled' => false,
                ];
                continue;
            }

            $normalized[] = [
                'value' => $option,
                'label' => (string) $option,
                'hint' => '',
                'disabled' => false,
            ];
        }

        return $normalized;
    }

    private function toggleOption(mixed $optionValue): void
    {
        $currentValues = is_array($this->value) ? $this->value : [];

        foreach ($currentValues as $index => $selectedValue) {
            if ($this->valuesMatch($selectedValue, $optionValue)) {
                unset($currentValues[$index]);
                $this->value = array_values($currentValues);
                return;
            }
        }

        $currentValues[] = $optionValue;
        $this->value = array_values($currentValues);
    }

    private function valuesMatch(mixed $left, mixed $right): bool
    {
        return (string) $left === (string) $right;
    }
}; ?>

<div class="form-control w-full {{ $wrapperClass }}" x-data="{ open: @js($inline), inline: @js($inline) }"
    @keydown.escape.window="if (!inline) open = false">
    @if ($label !== '')
        <label class="label {{ $labelClass }}">
            <span class="label-text">{{ $label }}</span>
        </label>
    @endif

    <div class="relative" :class="{ 'z-[90]': open && !inline }" @click.outside="if (!inline) open = false">
        @if ($showTrigger)
            <div role="button" tabindex="0" class="ui-select-trigger w-full {{ $triggerClass }}"
                :class="{ 'ui-select-open': open && !inline }" @click="if (!inline) open = !open"
                @keydown.enter.prevent="if (!inline) open = !open" @keydown.space.prevent="if (!inline) open = !open"
                @if ($disabled) aria-disabled="true" @endif>
                <span
                    class="truncate text-left {{ ($multiple ? $this->selectedOptions->isNotEmpty() : $this->selectedOption) ? 'text-base-content' : 'text-base-content/50' }}">
                    @if ($multiple)
                        @if ($this->selectedOptions->isNotEmpty())
                            {{ $this->selectedOptions->count() }}
                            sélection{{ $this->selectedOptions->count() > 1 ? 's' : '' }}
                        @else
                            {{ $placeholder }}
                        @endif
                    @else
                        {{ $this->selectedOption['label'] ?? $placeholder }}
                    @endif
                </span>

                <span class="flex items-center gap-2 pl-3 text-base-content/65">
                    @if ($clearable && ($multiple ? is_array($value) && count($value) > 0 : $value !== '' && $value !== null))
                        <span wire:click.stop="clearSelection" @click.stop
                            class="inline-flex h-6 w-6 items-center justify-center rounded hover:bg-base-300/60"
                            aria-label="Effacer la selection">
                            <i class="fa-solid fa-xmark"></i>
                        </span>
                    @endif
                    @if (!$inline)
                        <i class="fa-solid fa-chevron-down text-xs transition-transform"
                            :class="{ 'rotate-180': open }"></i>
                    @endif
                </span>
            </div>
        @endif

        <div x-show="open" x-transition.opacity.duration.120ms
            class="ui-select-panel {{ $inline ? 'w-full border border-base-300 rounded-xl bg-base-100' : 'absolute z-[100] mt-2 w-full' }} {{ $panelClass }}"
            style="display: none;">
            @if ($filterable)
                <div class="border-b border-base-300/70 p-2">
                    <input type="text" wire:model.live.debounce.200ms="search"
                        class="input input-sm ui-field w-full {{ $searchInputClass }}"
                        placeholder="Filtrer les options...">
                </div>
            @endif

            <ul class="max-h-60 overflow-y-auto p-1.5 {{ $listClass }}">
                @forelse($this->filteredOptions as $index => $option)
                    <li>
                        <button type="button" wire:key="smart-select-option-{{ md5((string) $option['value']) }}"
                            wire:click="{{ $multiple ? 'toggleFilteredOption' : 'selectFilteredOption' }}({{ $index }})"
                            @if (!$multiple && !$inline) @click="open = false" @endif
                            class="ui-select-option w-full {{ $optionClass }}"
                            @if ($option['disabled']) disabled @endif>
                            @if ($multiple)
                                <span class="flex min-w-0 items-center gap-2">
                                    <input type="checkbox"
                                        class="checkbox checkbox-sm checkbox-primary pointer-events-none"
                                        @checked($this->isOptionSelected($option['value']))>
                                    <span class="truncate">{{ $option['label'] }}</span>
                                </span>
                                @if ($option['hint'] !== '')
                                    <span class="text-xs text-base-content/55">{{ $option['hint'] }}</span>
                                @endif
                            @else
                                <span class="truncate">{{ $option['label'] }}</span>
                                @if ($option['hint'] !== '')
                                    <span class="text-xs text-base-content/55">{{ $option['hint'] }}</span>
                                @endif
                            @endif
                        </button>
                    </li>
                @empty
                    <li class="px-3 py-2 text-sm text-base-content/60 {{ $emptyStateClass }}">{{ $emptyText }}</li>
                @endforelse
            </ul>
        </div>
    </div>
</div>

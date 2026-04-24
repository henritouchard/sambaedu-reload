{{--
    Composant date/heure minimaliste (Story 7.1.bis).

    Usage :
        <x-molecules.date-time-picker wire:model.live="delegationExpiresAt" :with-time="true" />
        <x-molecules.date-time-picker wire:model.live="birthdate" />

    Comportement :
      - Input text en jj/mm/aaaa — saisie manuelle OU clic sur l'icône calendrier
        ouvre le picker natif OS (via `showPicker()`) et auto-fill le texte.
      - Si `:with-time="true"` : deux dropdowns heure (00–23) + minute (00–59)
        affichés après le champ date.
      - Valeur Livewire renvoyée :
          · sans time → 'YYYY-MM-DD'
          · avec time → 'YYYY-MM-DDTHH:MM'
        (directement parsable par `new \DateTimeImmutable($value)`).

    Nécessite que le parent soit un composant Livewire exposant la propriété
    ciblée par wire:model (sinon `@entangle` échoue à la compilation).
--}}
@props([
    'withTime' => false,
    'placeholder' => 'jj/mm/aaaa',
])

@php
    $wireModel = $attributes->wire('model')->value();
@endphp

<div x-data="{
    value: @entangle($wireModel).live,
    withTime: @js((bool) $withTime),
    textDate: '',
    hour: '00',
    minute: '00',

    init() {
        this.syncFromValue(this.value);
        this.$watch('textDate', () => this.updateValue());
        this.$watch('hour', () => this.updateValue());
        this.$watch('minute', () => this.updateValue());
        this.$watch('value', (v) => {
            if (v !== this.buildCurrent()) this.syncFromValue(v);
        });
    },

    syncFromValue(v) {
        if (!v) {
            this.textDate = '';
            this.hour = '00';
            this.minute = '00';
            return;
        }
        const m = String(v).match(/^(\d{4})-(\d{2})-(\d{2})(?:[T ](\d{2}):(\d{2}))?/);
        if (!m) return;
        this.textDate = m[3] + '/' + m[2] + '/' + m[1];
        if (this.withTime && m[4] !== undefined) {
            this.hour = m[4];
            this.minute = m[5];
        }
    },

    buildCurrent() {
        const m = this.textDate.match(/^(\d{2})\/(\d{2})\/(\d{4})$/);
        if (!m) return '';
        const iso = m[3] + '-' + m[2] + '-' + m[1];
        return this.withTime ? iso + 'T' + this.hour + ':' + this.minute : iso;
    },

    updateValue() {
        const built = this.buildCurrent();
        // Texte incomplet → on remet la valeur à null sans effacer le texte
        // (l'utilisateur est peut-être en train de taper).
        if (built === '' && this.textDate !== '') {
            if (this.value) this.value = null;
            return;
        }
        if (built !== this.value) this.value = built || null;
    },

    openPicker() {
        const p = this.$refs.nativePicker;
        if (typeof p.showPicker === 'function') {
            try { p.showPicker(); return; } catch (e) { /* fallback */ }
        }
        p.click();
    },

    onPick() {
        const v = this.$refs.nativePicker.value;
        if (!v) return;
        const parts = v.split('-');
        this.textDate = parts[2] + '/' + parts[1] + '/' + parts[0];
    },
}" class="flex  w-full items-center gap-2">

    <div class="relative">
        {{-- Champ date (texte + icône calendrier + picker natif caché) --}}
        <label class="input input-sm input-bordered flex items-center gap-2 relative">
            <button type="button" @click="openPicker"
                class="cursor-pointer text-base-content/60 hover:text-primary shrink-0" title="Ouvrir le calendrier">
                <i class="fa-solid fa-calendar"></i>
            </button>
            <input type="text" x-model="textDate" placeholder="{{ $placeholder }}" class="w-24 text-sm"
                inputmode="numeric" pattern="\d{2}/\d{2}/\d{4}" />
            {{-- Picker natif déclenché en arrière-plan via showPicker() — jamais focusable. --}}
        </label>
        <input type="date" x-ref="nativePicker" @change="onPick" tabindex="-1" aria-hidden="true"
            class="absolute opacity-0 pointer-events-none w-px h-px right-0 top-0" />
    </div>

    @if ($withTime)
        <div class="flex items-center gap-1">
            {{-- Heures (0–23) — pattern dropdown DaisyUI --}}
            <details class="dropdown" x-data @click.outside="$el.removeAttribute('open')">
                <summary class="btn btn-sm btn-outline w-16 font-mono" x-text="hour"></summary>
                <ul
                    class="menu dropdown-content bg-base-100 rounded-box z-20 mt-1 p-1 shadow max-h-60 overflow-y-auto grid grid-cols-4 gap-0.5 w-48">
                    @for ($h = 0; $h < 24; $h++)
                        @php $hh = sprintf('%02d', $h); @endphp
                        <li>
                            <a class="font-mono text-xs justify-center px-2 py-1"
                                :class="hour === '{{ $hh }}' ? 'active' : ''"
                                @click="hour = '{{ $hh }}'; $root.removeAttribute('open')">
                                {{ $hh }}
                            </a>
                        </li>
                    @endfor
                </ul>
            </details>
            <span class="text-base-content/60 text-xs">h</span>

            {{-- Minutes (0–59) — grid 6 colonnes pour compacité --}}
            <details class="dropdown" x-data @click.outside="$el.removeAttribute('open')">
                <summary class="btn btn-sm btn-outline w-16 font-mono" x-text="minute"></summary>
                <ul
                    class="menu dropdown-content bg-base-100 rounded-box z-20 mt-1 p-1 shadow max-h-60 overflow-y-auto grid grid-cols-6 gap-0.5 w-64">
                    @for ($m = 0; $m < 60; $m++)
                        @php $mm = sprintf('%02d', $m); @endphp
                        <li>
                            <a class="font-mono text-xs justify-center px-2 py-1"
                                :class="minute === '{{ $mm }}' ? 'active' : ''"
                                @click="minute = '{{ $mm }}'; $root.removeAttribute('open')">
                                {{ $mm }}
                            </a>
                        </li>
                    @endfor
                </ul>
            </details>
            <span class="text-base-content/60 text-xs">min</span>
        </div>
    @endif
</div>

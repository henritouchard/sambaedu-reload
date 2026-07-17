{{--
    Story 3.11 — Modale réutilisable « Réinstaller » (poste unique / salle /
    multi-sélection). Le composant hôte (SFC Livewire) doit exposer :
      - bool   $reinstallModalOpen
      - string $reinstallTarget       (valeur enum IpxeAdminAction)
      - string $reinstallWhen         ('now' | 'schedule')
      - ?string $reinstallScheduledAt (datetime-local)
      - méthodes closeReinstallModal() + armReinstall()
      - computed reinstallOsCatalog (list<{enum,label,os}>)

    Paramètres @include :
      - $confirmTitle     : titre de la confirmation destructive
      - $confirmMessage   : message destructif poste unique (fallback si pas de count)
      - $confirmCountExpr : (fan-out, fix review #5) expression JS évaluée au clic
                            donnant le nombre EXACT de postes impactés. Ex.
                            '$wire.selectedMachines.length' (inventaire réactif) ou
                            un littéral numérique server-side (salle : membres non
                            protégés). Présent ⇒ message « Vous allez EFFACER N
                            poste(s) et réinstaller {OS}. Irréversible. ».
--}}
@php
    $catalog = $this->reinstallOsCatalog;
    $labelMap = collect($catalog)->mapWithKeys(fn ($i) => [$i['enum'] => $i['label']])->toArray();
    $confirmCountExpr = $confirmCountExpr ?? null;
@endphp
<x-molecules.modal wire:model="reinstallModalOpen"
    title="{{ $reinstallTitle ?? 'Réinstaller le poste' }}"
    subtitle="Choisissez l'OS à installer et le moment du déclenchement"
    icon="fa-solid fa-arrows-rotate text-error"
    size="max-w-2xl"
    height="h-auto"
    closeMethod="closeReinstallModal">

    <x-molecules.modal.section title="Système à installer">
        <div class="flex flex-col w-full gap-1">
            <label class="label justify-start" for="reinstall-os">
                <span class="label-text font-medium">OS <span class="text-error">*</span></span>
            </label>
            <select id="reinstall-os" class="select select-bordered w-full" wire:model="reinstallTarget">
                <optgroup label="Windows">
                    @foreach ($catalog as $item)
                        @if ($item['os'] === 'windows')
                            <option value="{{ $item['enum'] }}">{{ $item['label'] }}</option>
                        @endif
                    @endforeach
                </optgroup>
                <optgroup label="Linux">
                    @foreach ($catalog as $item)
                        @if ($item['os'] === 'linux')
                            <option value="{{ $item['enum'] }}">{{ $item['label'] }}</option>
                        @endif
                    @endforeach
                </optgroup>
            </select>
        </div>
    </x-molecules.modal.section>

    <x-molecules.modal.section title="Moment du déclenchement">
        <div class="flex flex-col w-full gap-3">
            <div class="flex gap-4">
                <label class="label cursor-pointer justify-start gap-2">
                    <input type="radio" class="radio radio-primary" value="now" wire:model.live="reinstallWhen" />
                    <span class="label-text">Maintenant</span>
                </label>
                <label class="label cursor-pointer justify-start gap-2">
                    <input type="radio" class="radio radio-primary" value="schedule" wire:model.live="reinstallWhen" />
                    <span class="label-text">Planifier</span>
                </label>
            </div>

            @if ($reinstallWhen === 'schedule')
                <div class="flex flex-col w-full gap-1">
                    <label class="label justify-start" for="reinstall-when">
                        <span class="label-text font-medium">Date et heure <span class="text-error">*</span></span>
                    </label>
                    <input id="reinstall-when" type="datetime-local"
                        class="input input-bordered w-full"
                        wire:model="reinstallScheduledAt" />
                </div>
            @else
                <p class="text-sm text-base-content/60">
                    Le poste sera forcé à redémarrer au prochain tick (≤ 60 s), dans la limite du
                    plafond de charge configuré.
                </p>
            @endif
        </div>
    </x-molecules.modal.section>

    <x-slot:footerNote>
        <span class="text-error">
            <i class="fa-solid fa-triangle-exclamation"></i>
            Cette opération EFFACE le disque et réinstalle l'OS choisi. Irréversible.
        </span>
    </x-slot:footerNote>

    <x-slot:footer>
        <button type="button" class="btn btn-ghost" wire:click="closeReinstallModal">Annuler</button>
        <button type="button" class="btn btn-error"
            x-data="{ labels: @js($labelMap) }"
            @click="$dispatch('open-confirm-modal', {
                title: @js($confirmTitle ?? 'Confirmer la réinstallation'),
                @if (!empty($confirmCountExpr))
                message: 'Vous allez EFFACER ' + ({{ $confirmCountExpr }}) + ' poste(s) et réinstaller ' + (labels[$wire.reinstallTarget] ?? $wire.reinstallTarget) + '. Irréversible.',
                @else
                message: (@js($confirmMessage ?? 'Cette opération EFFACE le disque du poste et réinstalle')) + ' ' + (labels[$wire.reinstallTarget] ?? $wire.reinstallTarget) + '. Irréversible.',
                @endif
                confirmText: 'Réinstaller',
                cancelText: 'Annuler',
                variant: 'error',
                method: 'armReinstall',
                params: [],
                wireId: @js($this->getId()),
            })">
            <i class="fa-solid fa-arrows-rotate"></i>
            Réinstaller
        </button>
    </x-slot:footer>
</x-molecules.modal>

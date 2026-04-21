<?php

use App\Components\Traits\WithToasts;
use App\Enums\AppKind;
use App\Services\AppCustomization\AppPolicyRegistry;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Modale générique de personnalisation applicative.
 *
 * Story 4.8 — Task 4.4 (AC 7, 8). Écoute `open-app-customize-modal` depuis
 * les `app-customization-card` puis délègue au composant form spécifique
 * retourné par `adapter->renderFormComponent()`.
 */
new class extends Component {
    use WithToasts;

    public bool $open = false;
    public string $appKind = '';
    public ?string $scopeType = null;
    public ?int $scopeId = null;

    #[On('open-app-customize-modal')]
    public function openModal(string $appKind, ?string $scopeType = null, ?int $scopeId = null): void
    {
        if (! Gate::allows('app.customize')) {
            $this->toastAccessDenied();
            return;
        }

        $kind = AppKind::tryFrom($appKind);
        if ($kind === null) {
            $this->toastError('AppKind inconnu.');
            return;
        }

        $this->appKind = $appKind;
        $this->scopeType = $scopeType;
        $this->scopeId = $scopeId;
        $this->open = true;
    }

    #[On('customization-saved')]
    public function closeOnSave(): void
    {
        $this->open = false;
    }

    public function close(): void
    {
        $this->open = false;
    }

    public function formComponent(): ?string
    {
        if ($this->appKind === '') {
            return null;
        }
        $kind = AppKind::tryFrom($this->appKind);
        if ($kind === null) {
            return null;
        }
        return app(AppPolicyRegistry::class)->resolve($kind)->renderFormComponent();
    }
};
?>

<div>
    @if ($open)
    <div class="modal modal-open" wire:key="app-customize-modal-{{ $appKind }}-{{ $scopeType }}-{{ $scopeId }}">
        <div class="modal-box max-w-3xl">
            <div class="flex items-start justify-between gap-4 mb-4">
                <h2 class="text-lg font-bold">
                    Personnaliser {{ $appKind !== '' ? \App\Enums\AppKind::from($appKind)->label() : '' }}
                </h2>
                <button type="button" class="btn btn-sm btn-ghost btn-circle" wire:click="close">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>

            @if ($this->formComponent())
                @livewire(
                    $this->formComponent(),
                    [
                        'appKind' => $appKind,
                        'scopeType' => $scopeType,
                        'scopeId' => $scopeId,
                    ],
                    key($this->formComponent() . '-' . $appKind . '-' . $scopeType . '-' . $scopeId)
                )
            @else
                <p class="text-error">Impossible de charger le formulaire pour cet AppKind.</p>
            @endif
        </div>
        <div class="modal-backdrop" wire:click="close"></div>
    </div>
    @endif
</div>

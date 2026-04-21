<?php

use App\Components\Traits\WithToasts;
use App\Enums\AppKind;
use App\Models\AppCustomization;
use App\Services\AppCustomization\AppCustomizationService;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Molecule Livewire SFC — carte de personnalisation par AppKind.
 *
 * Story 4.8 — Task 4.3 (AC 7).
 *
 * Props :
 *   - `appKind`  : AppKind enum value ('firefox', 'thunderbird')
 *   - `scopeType` : null (défaut étab) | FQN (User / UserGroup / WorkstationGroup)
 *   - `scopeId`   : null | int
 *   - `title`    : string (ex: « Firefox »)
 *   - `description` : string (ex: « Personnalisation au niveau WorkstationGroup »)
 *
 * Usage :
 *   <livewire:components::molecules.app-customization-card
 *     :appKind="'firefox'"
 *     :scopeType="\App\Models\WorkstationGroup::class"
 *     :scopeId="$group->id" />
 */
new class extends Component {
    use WithToasts;

    private const ALLOWED_SCOPE_TYPES = [
        \App\Models\User::class,
        \App\Models\UserGroup::class,
        \App\Models\WorkstationGroup::class,
    ];

    public string $appKind = '';
    public ?string $scopeType = null;
    public ?int $scopeId = null;
    public string $title = '';
    public string $description = '';
    public int $refreshToken = 0;

    public function mount(
        string $appKind,
        ?string $scopeType = null,
        ?int $scopeId = null,
        string $title = '',
        string $description = '',
    ): void {
        $this->appKind = $appKind;
        $this->scopeType = $scopeType;
        $this->scopeId = $scopeId;
        $this->title = $title !== '' ? $title : AppKind::from($appKind)->label();
        $this->description = $description;
    }

    #[Computed(persist: false)]
    public function customization(): ?AppCustomization
    {
        $query = AppCustomization::query()->ofKind($this->appKind);
        if ($this->scopeType === null) {
            $query->whereNull('customizable_id')->where('is_default', true);
        } else {
            $query->where('customizable_type', $this->scopeType)
                ->where('customizable_id', $this->scopeId);
        }
        return $query->first();
    }

    public function openCustomize(): void
    {
        if (! Gate::allows('app.customize')) {
            $this->toastAccessDenied();
            return;
        }

        $this->dispatch(
            'open-app-customize-modal',
            appKind: $this->appKind,
            scopeType: $this->scopeType,
            scopeId: $this->scopeId,
        );
    }

    public function reset_to_default(): void
    {
        if (! Gate::allows('app.customize')) {
            $this->toastAccessDenied();
            return;
        }

        $customization = $this->customization;
        if ($customization === null) {
            return;
        }

        try {
            $kind = AppKind::from($this->appKind);
            $scope = null;
            if ($this->scopeType !== null && $this->scopeId !== null) {
                if (! in_array($this->scopeType, self::ALLOWED_SCOPE_TYPES, true)) {
                    throw new \InvalidArgumentException('Type de scope non autorisé.');
                }
                /** @var class-string<\Illuminate\Database\Eloquent\Model> $cls */
                $cls = $this->scopeType;
                $scope = $cls::query()->find($this->scopeId);
            }
            app(AppCustomizationService::class)->deleteCustomization($kind, $scope);
            unset($this->customization);
            $this->refreshToken++;
            $this->toastSuccess('Personnalisation supprimée.');
            $this->dispatch('customization-saved', appKind: $this->appKind);
        } catch (\Throwable $e) {
            $this->toastError('Erreur suppression : ' . $e->getMessage());
        }
    }

    #[On('customization-saved')]
    public function onCustomizationSaved(string $appKind = ''): void
    {
        if ($appKind === '' || $appKind === $this->appKind) {
            unset($this->customization);
            $this->refreshToken++;
        }
    }
};
?>

<div class="card bg-base-100 shadow-sm border border-base-300/60">
    <div class="card-body p-5">
        <div class="flex items-start justify-between gap-4">
            <div class="min-w-0">
                <h3 class="font-semibold text-base">{{ $title }}</h3>
                @if ($description)
                    <p class="text-sm text-base-content/60 mt-1">{{ $description }}</p>
                @endif
            </div>
            @if ($this->customization)
                <span class="badge badge-success badge-outline">
                    <i class="fa-solid fa-check mr-1"></i>
                    Personnalisé
                </span>
            @else
                <span class="badge badge-ghost badge-outline">
                    <i class="fa-solid fa-circle-minus mr-1"></i>
                    Hérité
                </span>
            @endif
        </div>

        @can('app.customize')
        <div class="mt-4 space-y-2">
            <button type="button"
                class="btn btn-primary btn-sm w-full"
                wire:click="openCustomize">
                <i class="fa-solid fa-sliders"></i>
                Personnaliser
            </button>

            @if ($this->customization)
                <button type="button"
                    class="btn btn-ghost btn-sm text-error w-full"
                    wire:click="reset_to_default"
                    wire:confirm="Supprimer cette personnalisation ?">
                    <i class="fa-solid fa-rotate-left"></i>
                    Réinitialiser au défaut
                </button>
            @endif
        </div>
        @endcan
    </div>
</div>

<?php

use App\Components\Traits\WithToasts;
use App\Models\Wallpaper;
use App\Services\Wallpaper\WallpaperDeleteService;
use App\Services\Wallpaper\WallpaperUploadService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Molecule Livewire SFC — carte d'upload / remplacement wallpaper.
 *
 * Story 4.7 — Task 4.2 (AC 8, 9, 10, 11).
 *
 * Props :
 *   - `type` : 'wallpaper' | 'lockscreen'
 *   - `ownerType` : null (défaut étab) | FQN de la classe Eloquent (User, UserGroup, WorkstationGroup)
 *   - `ownerId` : null | int
 *   - `isDefault` : bool — seule la carte « défaut étab » doit passer true
 *   - `title` : string (ex: « Fond d'écran par défaut »)
 *   - `description` : string (ex: « Affiché si aucun autre niveau ne matche »)
 *
 * Usage :
 *   <livewire:components::molecules.wallpaper-card
 *     type="wallpaper"
 *     :ownerType="App\Models\WorkstationGroup::class"
 *     :ownerId="$group->id"
 *     title="Fond d'écran de salle"
 *     description="..." />
 */
new class extends Component {
    use WithToasts, WithFileUploads;

    public string $type = Wallpaper::TYPE_WALLPAPER;
    public ?string $ownerType = null;
    public ?int $ownerId = null;
    public bool $isDefault = false;
    public string $title = '';
    public string $description = '';

    public $upload = null;
    public int $refreshToken = 0;

    public function mount(
        string $type,
        ?string $ownerType = null,
        ?int $ownerId = null,
        bool $isDefault = false,
        string $title = '',
        string $description = '',
    ): void {
        $this->type = $type;
        $this->ownerType = $ownerType;
        $this->ownerId = $ownerId;
        $this->isDefault = $isDefault;
        $this->title = $title;
        $this->description = $description;
    }

    #[Computed(persist: false)]
    public function wallpaper(): ?Wallpaper
    {
        $query = Wallpaper::query()->where('type', $this->type);
        if ($this->ownerType === null) {
            $query->whereNull('owner_id')->where('is_default', true);
        } else {
            $query->where('owner_type', $this->ownerType)->where('owner_id', $this->ownerId);
        }
        return $query->first();
    }

    public function save(): void
    {
        if (! Gate::allows('wallpaper.manage')) {
            $this->toastAccessDenied();
            return;
        }

        $this->validate([
            'upload' => [
                'required',
                'file',
                'mimes:' . implode(',', config('wallpapers.allowed_extensions', ['jpg', 'jpeg', 'png'])),
                'max:' . (int) (((int) config('wallpapers.max_upload_size', 10_485_760)) / 1024),
            ],
        ]);

        try {
            $owner = $this->resolveOwner();
            app(WallpaperUploadService::class)->store(
                $this->upload,
                $this->type,
                $owner,
                $this->ownerType === null && $this->isDefault,
            );
            $this->upload = null;
            $this->refreshToken++;
            unset($this->wallpaper);
            $this->toastSuccess('Fond d\'écran mis à jour.');
            $this->dispatch('wallpaper-updated', type: $this->type);
        } catch (\Throwable $e) {
            Log::error('[WallpaperCard] upload failed', [
                'type' => $this->type,
                'owner' => $this->ownerType . ':' . $this->ownerId,
                'error' => $e->getMessage(),
            ]);
            $this->toastError('Erreur : ' . $e->getMessage());
        }
    }

    public function remove(): void
    {
        if (! Gate::allows('wallpaper.manage')) {
            $this->toastAccessDenied();
            return;
        }

        $wallpaper = $this->wallpaper;
        if ($wallpaper === null) {
            return;
        }

        try {
            app(WallpaperDeleteService::class)->delete($wallpaper);
            unset($this->wallpaper);
            $this->refreshToken++;
            $this->toastSuccess('Fond d\'écran supprimé.');
            $this->dispatch('wallpaper-updated', type: $this->type);
        } catch (\Throwable $e) {
            Log::error('[WallpaperCard] delete failed', [
                'type' => $this->type,
                'error' => $e->getMessage(),
            ]);
            $this->toastError('Erreur suppression : ' . $e->getMessage());
        }
    }

    private function resolveOwner(): ?Model
    {
        if ($this->ownerType === null || $this->ownerId === null) {
            return null;
        }
        /** @var class-string<Model> $cls */
        $cls = $this->ownerType;
        return $cls::query()->find($this->ownerId);
    }
};
?>

<div class="card bg-base-100 shadow-sm border border-base-300/60">
    <div class="card-body p-5">
        <div class="flex items-start justify-between gap-4">
            <div class="min-w-0">
                <h3 class="font-semibold text-base">{{ $title ?: ucfirst($type) }}</h3>
                @if ($description)
                    <p class="text-sm text-base-content/60 mt-1">{{ $description }}</p>
                @endif
            </div>
            <span class="badge {{ $type === 'lockscreen' ? 'badge-info' : 'badge-primary' }} badge-outline">
                <i class="fa-solid {{ $type === 'lockscreen' ? 'fa-lock' : 'fa-image' }} mr-1"></i>
                {{ $type === 'lockscreen' ? 'Verrouillage' : 'Bureau' }}
            </span>
        </div>

        <div class="mt-3 aspect-video bg-base-200 rounded-lg overflow-hidden flex items-center justify-center">
            @if ($this->wallpaper)
                <img
                    src="{{ route('app.wallpapers.thumbnail', ['wallpaper' => $this->wallpaper->id]) }}?v={{ $refreshToken }}"
                    alt="Aperçu {{ $type }}"
                    class="w-full h-full object-cover" />
            @else
                <div class="text-center text-base-content/50 py-6">
                    <i class="fa-solid fa-image text-4xl mb-2 opacity-50"></i>
                    <p class="text-sm">Aucun fond personnalisé</p>
                </div>
            @endif
        </div>

        @can('wallpaper.manage')
        <div class="mt-4 space-y-2">
            <form wire:submit.prevent="save" class="flex flex-col sm:flex-row gap-2">
                <input
                    type="file"
                    wire:model="upload"
                    accept="image/jpeg,image/png,image/gif,image/bmp,image/webp"
                    class="file-input file-input-bordered file-input-sm flex-1 text-xs" />
                <button type="submit"
                    class="btn btn-primary btn-sm"
                    @disabled(empty($upload))
                    wire:loading.attr="disabled">
                    <i class="fa-solid fa-upload" wire:loading.class="hidden"></i>
                    <i class="fa-solid fa-spinner fa-spin hidden" wire:loading.class.remove="hidden"></i>
                    Remplacer
                </button>
            </form>

            @error('upload')
                <p class="text-xs text-error">{{ $message }}</p>
            @enderror

            @if ($this->wallpaper)
                <button type="button"
                    class="btn btn-ghost btn-sm text-error w-full sm:w-auto"
                    wire:click="remove"
                    wire:confirm="Supprimer ce fond d'écran ?">
                    <i class="fa-solid fa-trash"></i>
                    Supprimer
                </button>
            @endif
        </div>
        @endcan
    </div>
</div>

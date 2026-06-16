<?php

use App\Components\Traits\WithToasts;
use App\Models\Wallpaper;
use App\Models\WallpaperAsset;
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
    public bool $showLibrary = false;

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

    public function updatedUpload(): void
    {
        if ($this->upload !== null) {
            $this->save();
        }
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

    /**
     * Assets disponibles dans la bibliothèque (sélecteur). Triés du plus
     * récent au plus ancien.
     *
     * @return \Illuminate\Support\Collection<int, WallpaperAsset>
     */
    #[Computed(persist: false)]
    public function libraryAssets()
    {
        return WallpaperAsset::query()->latest('id')->limit(60)->get();
    }

    public function toggleLibrary(): void
    {
        $this->showLibrary = ! $this->showLibrary;
    }

    public function assignFromLibrary(int $assetId): void
    {
        if (! Gate::allows('wallpaper.manage')) {
            $this->toastAccessDenied();
            return;
        }

        $asset = WallpaperAsset::find($assetId);
        if ($asset === null) {
            $this->toastError('Asset introuvable.');
            return;
        }

        try {
            app(WallpaperUploadService::class)->assignExisting(
                $asset,
                $this->type,
                $this->resolveOwner(),
                $this->ownerType === null && $this->isDefault,
            );
            $this->showLibrary = false;
            $this->refreshToken++;
            unset($this->wallpaper);
            $this->toastSuccess('Fond d\'écran assigné depuis la bibliothèque.');
            $this->dispatch('wallpaper-updated', type: $this->type);
        } catch (\Throwable $e) {
            Log::error('[WallpaperCard] assign from library failed', [
                'type' => $this->type,
                'asset_id' => $assetId,
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

@php $inputId = 'wp-' . $type . '-' . ($ownerId ?? 'default'); @endphp

<div class="card bg-base-100 shadow-sm border border-base-300/60">
    <div class="card-body p-5">
        <h3 class="font-semibold text-base mb-3">{{ $title ?: ucfirst($type) }}</h3>

        @can('wallpaper.manage')
            <form>
                <input type="file" id="{{ $inputId }}" wire:model="upload"
                    accept="image/jpeg,image/png,image/gif,image/bmp,image/webp"
                    class="hidden" />

                <div class="group relative aspect-video bg-base-200 rounded-lg overflow-hidden flex items-center justify-center cursor-pointer">
                    @if ($this->wallpaper)
                        <img src="{{ route('app.wallpapers.thumbnail', ['wallpaper' => $this->wallpaper->id]) }}?v={{ $refreshToken }}"
                            alt="Aperçu {{ $type }}" class="w-full h-full object-cover" />
                    @else
                        <div class="text-center text-base-content/50 py-6">
                            <i class="fa-solid fa-image text-4xl mb-2 opacity-50"></i>
                            <p class="text-sm">Aucun fond personnalisé</p>
                        </div>
                    @endif

                    <div class="absolute inset-0 bg-black/50 opacity-0 group-hover:opacity-100 transition-opacity duration-150 flex items-center justify-center gap-2">
                        <label for="{{ $inputId }}" class="btn btn-sm btn-neutral text-white cursor-pointer" @click.stop>
                            <i class="fa-solid fa-upload"></i>
                            {{ $this->wallpaper ? 'Remplacer' : 'Ajouter' }}
                        </label>
                        @if ($this->wallpaper)
                            <button type="button" class="btn btn-sm btn-error" @click.stop
                                wire:click="remove" wire:confirm="Supprimer ce fond d'écran ?">
                                <i class="fa-solid fa-trash"></i>
                            </button>
                        @endif
                    </div>

                    <div wire:loading wire:target="save"
                        class="absolute inset-0 bg-black/60 flex items-center justify-center">
                        <span class="loading loading-spinner loading-md text-white"></span>
                    </div>
                </div>

                @error('upload') <p class="text-xs text-error mt-2">{{ $message }}</p> @enderror

                <button type="button" class="btn btn-ghost btn-xs mt-3 gap-1" wire:click="toggleLibrary">
                    <i class="fa-solid fa-images"></i>
                    {{ $showLibrary ? 'Masquer la bibliothèque' : 'Choisir dans la bibliothèque' }}
                </button>

                @if ($showLibrary)
                    <div class="mt-2 border border-base-300/60 rounded-lg p-2 bg-base-200/40">
                        @if ($this->libraryAssets->isEmpty())
                            <p class="text-xs text-base-content/50 py-3 text-center">Bibliothèque vide — uploadez un premier fond.</p>
                        @else
                            <div class="grid grid-cols-3 sm:grid-cols-4 gap-2 max-h-56 overflow-y-auto">
                                @foreach ($this->libraryAssets as $asset)
                                    <button type="button" @click.stop
                                        wire:click="assignFromLibrary({{ $asset->id }})"
                                        wire:key="lib-asset-{{ $asset->id }}"
                                        class="group/asset relative aspect-video bg-base-300 rounded overflow-hidden ring-offset-1 hover:ring-2 hover:ring-primary"
                                        title="{{ $asset->original_name ?? $asset->filename }}">
                                        <img src="{{ route('app.wallpaper-assets.thumbnail', ['asset' => $asset->id]) }}"
                                            alt="{{ $asset->original_name ?? $asset->filename }}"
                                            class="w-full h-full object-cover" loading="lazy" />
                                    </button>
                                @endforeach
                            </div>
                        @endif
                    </div>
                @endif
            </form>
        @else
            <div class="aspect-video bg-base-200 rounded-lg overflow-hidden flex items-center justify-center">
                @if ($this->wallpaper)
                    <img src="{{ route('app.wallpapers.thumbnail', ['wallpaper' => $this->wallpaper->id]) }}?v={{ $refreshToken }}"
                        alt="Aperçu {{ $type }}" class="w-full h-full object-cover" />
                @else
                    <div class="text-center text-base-content/50 py-6">
                        <i class="fa-solid fa-image text-4xl mb-2 opacity-50"></i>
                        <p class="text-sm">Aucun fond personnalisé</p>
                    </div>
                @endif
            </div>
        @endcan
    </div>
</div>

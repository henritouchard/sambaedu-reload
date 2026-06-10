<?php

use App\Components\Traits\WithToasts;
use App\Models\Wallpaper;
use App\Models\WallpaperAsset;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Sélecteur de fond d'écran en modale large (refonte UX 2026-06).
 *
 * Bibliothèque en grandes vignettes (≥ 350px, largeur relative à l'écran).
 * La SÉLECTION est gérée côté client (Alpine) pour rester fluide : aucun
 * aller-retour serveur au clic (sinon le re-render de la modale téléportée
 * la fait clignoter). Le serveur n'est sollicité qu'à Valider / Importer /
 * Retirer. L'asset courant est exposé via `$currentAssetId` et présélectionné
 * à l'ouverture (anneau bleu ciel).
 *
 * Une instance par type ; ouverture pilotée par l'event `open-wp-picker`.
 */
new class extends Component {
    use WithToasts, WithFileUploads;

    public string $type = Wallpaper::TYPE_WALLPAPER;
    public ?string $ownerType = null;
    public ?int $ownerId = null;
    public bool $isDefault = false;
    public string $title = '';

    public bool $show = false;
    public ?int $currentAssetId = null;
    public $upload = null;
    public int $refreshToken = 0;

    public function mount(
        string $type,
        ?string $ownerType = null,
        ?int $ownerId = null,
        bool $isDefault = false,
        string $title = '',
    ): void {
        $this->type = $type;
        $this->ownerType = $ownerType;
        $this->ownerId = $ownerId;
        $this->isDefault = $isDefault;
        $this->title = $title;
        $this->refreshCurrent();
    }

    #[On('open-wp-picker')]
    public function maybeOpen(string $type, ?int $ownerId = null): void
    {
        if ($type !== $this->type) {
            return;
        }
        if ($ownerId !== null && $ownerId !== $this->ownerId) {
            return;
        }
        $this->refreshCurrent();
        $this->show = true;
    }

    public function close(): void
    {
        $this->show = false;
        $this->upload = null;
    }

    /** Recalcule l'asset actuellement assigné à (owner, type). */
    private function refreshCurrent(): void
    {
        $query = Wallpaper::query()->where('type', $this->type);
        if ($this->ownerType === null) {
            $query->whereNull('owner_id')->where('is_default', true);
        } else {
            $query->where('owner_type', $this->ownerType)->where('owner_id', $this->ownerId);
        }
        $id = $query->value('asset_id');
        $this->currentAssetId = $id !== null ? (int) $id : null;
    }

    /**
     * @return \Illuminate\Support\Collection<int, WallpaperAsset>
     */
    #[Computed(persist: false)]
    public function assets()
    {
        return WallpaperAsset::query()->latest('id')->limit(120)->get();
    }

    public function updatedUpload(): void
    {
        if ($this->upload === null) {
            return;
        }
        if (! Gate::allows('wallpaper.manage')) {
            $this->toastAccessDenied();
            $this->upload = null;
            return;
        }

        $this->validate([
            'upload' => [
                'required', 'file',
                'mimes:' . implode(',', config('wallpapers.allowed_extensions', ['jpg', 'jpeg', 'png'])),
                'max:' . (int) (((int) config('wallpapers.max_upload_size', 10_485_760)) / 1024),
            ],
        ]);

        try {
            // Ajout en bibliothèque SANS assignation : on présélectionne le
            // nouvel asset côté client (event), assignation à la validation.
            $asset = app(\App\Services\Wallpaper\WallpaperUploadService::class)->ingest($this->upload);
            $this->upload = null;
            $this->refreshToken++;
            unset($this->assets);
            $this->dispatch('wp-asset-uploaded', id: $asset->id);
            $this->toastSuccess('Image importée dans la bibliothèque.');
        } catch (\Throwable $e) {
            Log::error('[WallpaperPicker] ingest failed', [
                'type' => $this->type,
                'error' => $e->getMessage(),
            ]);
            $this->toastError('Erreur import : ' . $e->getMessage());
        }
    }

    public function validateSelection(?int $assetId = null): void
    {
        if (! Gate::allows('wallpaper.manage')) {
            $this->toastAccessDenied();
            return;
        }

        $current = $this->currentAssetId;

        // Aucune sélection alors qu'un fond existait → retrait.
        if ($assetId === null) {
            if ($current !== null) {
                $this->removeCurrent();
            }
            $this->close();
            return;
        }

        if ($assetId === $current) {
            $this->close(); // pas de changement
            return;
        }

        $asset = WallpaperAsset::find($assetId);
        if ($asset === null) {
            $this->toastError('Image introuvable.');
            return;
        }

        try {
            app(\App\Services\Wallpaper\WallpaperUploadService::class)->assignExisting(
                $asset,
                $this->type,
                $this->resolveOwner(),
                $this->ownerType === null && $this->isDefault,
            );
            $this->refreshCurrent();
            $this->close();
            $this->toastSuccess('Fond d\'écran appliqué.');
            $this->dispatch('wallpaper-updated', type: $this->type);
        } catch (\Throwable $e) {
            Log::error('[WallpaperPicker] assign failed', [
                'type' => $this->type,
                'asset_id' => $assetId,
                'error' => $e->getMessage(),
            ]);
            $this->toastError('Erreur : ' . $e->getMessage());
        }
    }

    public function removeCurrent(): void
    {
        if (! Gate::allows('wallpaper.manage')) {
            $this->toastAccessDenied();
            return;
        }

        $query = Wallpaper::query()->where('type', $this->type);
        if ($this->ownerType === null) {
            $query->whereNull('owner_id')->where('is_default', true);
        } else {
            $query->where('owner_type', $this->ownerType)->where('owner_id', $this->ownerId);
        }
        $assignment = $query->first();
        if ($assignment === null) {
            return;
        }

        try {
            app(\App\Services\Wallpaper\WallpaperDeleteService::class)->delete($assignment);
            $this->refreshCurrent();
            $this->toastSuccess('Fond d\'écran retiré.');
            $this->dispatch('wallpaper-updated', type: $this->type);
        } catch (\Throwable $e) {
            Log::error('[WallpaperPicker] remove failed', [
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

@php $inputId = 'wp-picker-' . $type . '-' . ($ownerId ?? 'default'); @endphp

<div>
    @can('wallpaper.manage')
        @teleport('body')
            <dialog class="modal"
                x-data="{
                    open: @entangle('show'),
                    selected: null,
                    init() {
                        // Présélectionne l'asset courant à chaque ouverture.
                        this.$watch('open', v => { if (v) this.selected = $wire.currentAssetId });
                    },
                }"
                @wp-asset-uploaded.window="selected = $event.detail.id"
                :class="{ 'modal-open': open }" x-cloak>
                <div class="modal-box w-[92vw] max-w-[1600px] max-h-[80vh] flex flex-col">
                    <div class="flex items-center justify-between mb-1 shrink-0">
                        <h3 class="text-lg font-bold flex items-center gap-2">
                            <i class="fa-solid fa-image text-primary"></i>
                            {{ $title ?: ($type === 'lockscreen' ? 'Écran de verrouillage' : 'Fond d\'écran') }}
                        </h3>
                        <button type="button" class="btn btn-ghost btn-sm btn-circle" wire:click="close">
                            <i class="fa-solid fa-xmark"></i>
                        </button>
                    </div>
                    <p class="text-sm text-base-content/60 mb-4 shrink-0">
                        Sélectionnez une image (entourée de bleu), puis validez.
                    </p>

                    <input type="file" id="{{ $inputId }}" wire:model="upload"
                        accept="image/jpeg,image/png,image/gif,image/bmp,image/webp" class="hidden" />

                    <div class="grid grid-cols-2 gap-4 overflow-y-auto p-1 flex-1 min-h-0">
                        @forelse ($this->assets as $asset)
                            <button type="button" wire:key="picker-asset-{{ $asset->id }}"
                                @click="selected = (selected === {{ $asset->id }} ? null : {{ $asset->id }})"
                                class="group relative rounded-xl overflow-hidden border bg-base-200 transition-all"
                                :class="selected === {{ $asset->id }}
                                    ? 'ring-4 ring-sky-400 ring-offset-2 border-sky-400'
                                    : 'border-base-300 hover:border-primary'">
                                <img src="{{ route('app.wallpaper-assets.thumbnail', ['asset' => $asset->id]) }}?v={{ $refreshToken }}"
                                    alt="{{ $asset->original_name ?? $asset->filename }}"
                                    class="w-full aspect-video object-cover" loading="lazy" />
                                <span x-show="selected === {{ $asset->id }}" x-cloak
                                    class="absolute top-2 right-2 bg-sky-400 text-white rounded-full w-8 h-8 flex items-center justify-center shadow">
                                    <i class="fa-solid fa-check"></i>
                                </span>
                            </button>
                        @empty
                            <div class="col-span-full text-center text-base-content/50 py-10">
                                <i class="fa-solid fa-images text-4xl mb-2 opacity-50"></i>
                                <p>Bibliothèque vide — importez une première image.</p>
                            </div>
                        @endforelse
                    </div>

                    <div wire:loading wire:target="upload" class="mt-3 text-sm text-base-content/60 shrink-0">
                        <span class="loading loading-spinner loading-sm"></span> Import en cours…
                    </div>
                    @error('upload') <p class="text-xs text-error mt-2 shrink-0">{{ $message }}</p> @enderror

                    <div class="modal-action justify-between mt-5 shrink-0">
                        <label for="{{ $inputId }}" class="btn btn-outline btn-sm cursor-pointer gap-1">
                            <i class="fa-solid fa-upload"></i> Importer une image
                        </label>
                        <div class="flex items-center gap-2">
                            @if ($currentAssetId !== null)
                                <button type="button" class="btn btn-ghost btn-sm text-error"
                                    wire:click="removeCurrent" wire:confirm="Retirer le fond d'écran personnalisé ?">
                                    <i class="fa-solid fa-trash"></i> Retirer
                                </button>
                            @endif
                            <button type="button" class="btn btn-sm" wire:click="close">Annuler</button>
                            <button type="button" class="btn btn-primary btn-sm"
                                :disabled="selected === $wire.currentAssetId"
                                @click="$wire.validateSelection(selected)">
                                Valider
                            </button>
                        </div>
                    </div>
                </div>
                <form method="dialog" class="modal-backdrop">
                    <button wire:click="close">fermer</button>
                </form>
            </dialog>
        @endteleport
    @endcan
</div>

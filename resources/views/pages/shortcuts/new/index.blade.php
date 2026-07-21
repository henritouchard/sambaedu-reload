<?php

use Livewire\Component;
use Livewire\Attributes\Title;
use Livewire\WithFileUploads;
use App\Models\Shortcut;
use App\Services\ShortcutsService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use App\Components\Traits\WithToasts;

new #[Title('Nouveau raccourci - Instance SE4FS')] class extends Component {
    use WithFileUploads, WithToasts;

    public bool $creating = true;
    public bool $editing = false;
    public bool $isGlobal = false;
    public array $filters = [];

    // Champs du formulaire
    public string $name = '';
    public string $place = 'desktop';
    public string $windows_link = '';
    public string $windows_args = '';
    public string $windows_path = '';
    public string $windows_icon = '';
    public string $linux_link = '';
    public string $linux_args = '';
    public string $linux_path = '';
    public string $linux_startupwmclass = '';

    // Upload d'icône
    public $icon_file = null;

    public function mount(): void
    {
        $this->filters = [
            'place' => [
                'desktop' => 'Bureau',
                'startup' => 'Démarrage automatique',
                'taskbar' => 'Barre des tâches (seulement Linux)',
            ],
        ];
    }

    public function save()
    {
        if (Gate::denies('create-shortcut')) {
            $this->toast('error', 'Accès refusé', 'Vous n\'avez pas les droits pour créer un raccourci');
            return;
        }

        $this->validate([
            'name' => 'required|string|max:255',
            'place' => 'required|in:desktop,startup,taskbar',
            'windows_link' => 'nullable|string|max:500',
            'windows_args' => 'nullable|string|max:500',
            'windows_path' => 'nullable|string|max:500',
            'linux_link' => 'nullable|string|max:500',
            'linux_args' => 'nullable|string|max:500',
            'linux_path' => 'nullable|string|max:500',
            'linux_startupwmclass' => 'nullable|string|max:255',
            'icon_file' => 'nullable|image|max:2048',
        ]);

        try {
            $key = Str::slug($this->name) . '_' . time();

            $shortcut = Shortcut::create([
                'key' => $key,
                'name' => $this->name,
                'place' => $this->place,
                'is_global' => false,
                'windows_link' => $this->windows_link,
                'windows_args' => $this->windows_args,
                'windows_path' => $this->windows_path,
                'linux_link' => $this->linux_link,
                'linux_args' => $this->linux_args,
                'linux_path' => $this->linux_path,
                'linux_startupwmclass' => $this->linux_startupwmclass,
            ]);

            if ($this->icon_file) {
                $shortcutsService = app(ShortcutsService::class);
                $iconPath = $shortcutsService->handleIconUpload($this->icon_file, $this->name);
                if ($iconPath) {
                    $shortcut->update([
                        'windows_icon' => $iconPath,
                        'icon_path' => $iconPath,
                    ]);
                    // Story 27.7 : content-adresser l'icône uploadée (`<name>.ico` →
                    // `<sha>.ico` servi par Apache) + persister `icon_asset`/`icon_checksum`.
                    // Sans cet appel, l'agent n'a aucun asset à télécharger → icône
                    // « feuille blanche » et colonnes nulles (gap 27.7 / handleIconUpload seul).
                    $shortcutsService->persistIconAsset($this->name);
                }
            }

            $this->toast('success', 'Création réussie', 'Raccourci créé avec succès');
            return $this->redirect(route('app.shortcuts.show', ['id' => $shortcut->key]));

        } catch (\Exception $e) {
            Log::error('NewShortcutPage save error: ' . $e->getMessage());
            $this->toast('error', 'Erreur', 'Erreur lors de la création du raccourci');
        }
    }
};
?>

@php
    $placeLabels = [
        'desktop' => 'Bureau',
        'startup' => 'Démarrage auto',
        'taskbar' => 'Barre des tâches',
    ];
@endphp

<x-organisms.page :backUrl="route('app.parc-settings.index', ['tab' => 'shortcuts'])" title="Nouveau raccourci" backText="Retour à la liste">
    <form wire:submit="save" class="space-y-6">
        @include('pages.shortcuts.[id]._partials.shortcut-form', ['creating' => true])
    </form>
</x-organisms.page>

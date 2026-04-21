<?php

use App\Components\Traits\WithToasts;
use App\Enums\AppKind;
use App\Services\AppCustomization\AppCustomizationService;
use App\Services\AppCustomization\AppPolicyRegistry;
use App\Services\AppCustomization\Firefox\FirefoxAddonDiscovery;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

/**
 * Form Livewire SFC — personnalisation Firefox.
 *
 * Story 4.8 — Task 4.5 (AC 7, 14). Reprend la structure `ff_form_policy`
 * legacy L89-194 mais en composant Livewire réactif + stripNonWhitelisted
 * côté Service.
 */
new class extends Component {
    use WithToasts;

    private const ALLOWED_SCOPE_TYPES = [
        \App\Models\User::class,
        \App\Models\UserGroup::class,
        \App\Models\WorkstationGroup::class,
    ];

    public string $appKind = 'firefox';
    public ?string $scopeType = null;
    public ?int $scopeId = null;

    /** @var array<int, array{Title: string, URL: string, Folder: string}> */
    public array $bookmarks = [];
    public string $homepageUrl = '';
    /** @var array<int, array{id: string, installation_mode: string, install_url: string}> */
    public array $extensions = [];
    public string $extensionAutoDetectUrl = '';

    public function mount(string $appKind, ?string $scopeType = null, ?int $scopeId = null): void
    {
        $this->appKind = $appKind;
        $this->scopeType = $scopeType;
        $this->scopeId = $scopeId;
        $this->loadExistingOverride();
    }

    private function loadExistingOverride(): void
    {
        $customization = \App\Models\AppCustomization::query()
            ->ofKind($this->appKind)
            ->when($this->scopeType === null, fn($q) => $q->whereNull('customizable_id')->where('is_default', true))
            ->when($this->scopeType !== null, fn($q) => $q->where('customizable_type', $this->scopeType)->where('customizable_id', $this->scopeId))
            ->first();

        if ($customization === null) {
            return;
        }

        $policies = (array) ($customization->policies_json['policies'] ?? []);
        $this->bookmarks = array_values(array_map(
            fn($b) => [
                'Title' => (string) ($b['Title'] ?? ''),
                'URL' => (string) ($b['URL'] ?? ''),
                'Folder' => (string) ($b['Folder'] ?? ''),
            ],
            (array) ($policies['Bookmarks'] ?? []),
        ));
        $this->homepageUrl = (string) ($policies['Homepage']['URL'] ?? '');

        $extensionsFlat = [];
        foreach ((array) ($policies['ExtensionSettings'] ?? []) as $extId => $settings) {
            if (! is_string($extId) || $extId === '*') {
                continue;
            }
            $extensionsFlat[] = [
                'id' => $extId,
                'installation_mode' => (string) ($settings['installation_mode'] ?? 'blocked'),
                'install_url' => (string) ($settings['install_url'] ?? ''),
            ];
        }
        $this->extensions = $extensionsFlat;
    }

    public function addBookmark(): void
    {
        $this->bookmarks[] = ['Title' => '', 'URL' => '', 'Folder' => ''];
    }

    public function removeBookmark(int $index): void
    {
        unset($this->bookmarks[$index]);
        $this->bookmarks = array_values($this->bookmarks);
    }

    public function addExtension(): void
    {
        $this->extensions[] = ['id' => '', 'installation_mode' => 'blocked', 'install_url' => ''];
    }

    public function removeExtension(int $index): void
    {
        unset($this->extensions[$index]);
        $this->extensions = array_values($this->extensions);
    }

    public function autoDetectExtensionId(): void
    {
        if (! Gate::allows('app.customize')) {
            $this->toastAccessDenied();
            return;
        }

        $url = trim($this->extensionAutoDetectUrl);
        if ($url === '') {
            return;
        }

        try {
            $result = app(FirefoxAddonDiscovery::class)->resolveFromUrl($url);
            if ($result === null || ($result['gecko_id'] ?? '') === '') {
                $this->toastWarning('Aucun ID Gecko trouvé pour cette URL.');
                return;
            }

            $this->extensions[] = [
                'id' => $result['gecko_id'],
                'installation_mode' => 'force_installed',
                'install_url' => $result['install_url'] ?? $url,
            ];
            $this->extensionAutoDetectUrl = '';

            // Feedback enrichi selon la source (API AMO ou XPI custom)
            if (($result['source'] ?? '') === 'amo' && ! empty($result['name'])) {
                $label = $result['name'] . (isset($result['version']) ? ' ' . $result['version'] : '');
                $this->toastSuccess($label . ' — ajouté (API AMO)');
            } else {
                $this->toastSuccess('Extension détectée : ' . $result['gecko_id']);
            }
        } catch (\InvalidArgumentException $e) {
            // URL invalide / hors allowlist / DNS privé / slug introuvable
            $this->toastError($e->getMessage());
        } catch (\RuntimeException $e) {
            // Erreur réseau/TLS — admin voit le détail pour diagnostic
            $this->toastError($e->getMessage());
        } catch (\Throwable $e) {
            $this->toastError('Erreur : ' . $e->getMessage());
        }
    }

    public function save(): void
    {
        if (! Gate::allows('app.customize')) {
            $this->toastAccessDenied();
            return;
        }

        $policies = ['policies' => []];

        if ($this->homepageUrl !== '') {
            $policies['policies']['Homepage'] = [
                'URL' => $this->homepageUrl,
                'Locked' => true,
                'StartPage' => 'homepage',
            ];
        }

        $bookmarks = [];
        foreach ($this->bookmarks as $b) {
            if (($b['URL'] ?? '') === '') {
                continue;
            }
            $bookmarks[] = [
                'Title' => $b['Title'] ?? '',
                'URL' => $b['URL'],
                'Folder' => $b['Folder'] ?? '',
                'Placement' => 'toolbar',
            ];
        }
        if ($bookmarks !== []) {
            $policies['policies']['Bookmarks'] = $bookmarks;
        }

        $extensions = [];
        foreach ($this->extensions as $ext) {
            if (($ext['id'] ?? '') === '') {
                continue;
            }
            $extensions[$ext['id']] = [
                'installation_mode' => $ext['installation_mode'] ?? 'blocked',
                'install_url' => $ext['install_url'] ?? '',
            ];
        }
        if ($extensions !== []) {
            $policies['policies']['ExtensionSettings'] = $extensions;
        }

        try {
            $scope = $this->resolveScope();
            $user = Auth::user();
            $author = $user instanceof \App\Models\User ? $user : null;

            app(AppCustomizationService::class)->savePolicies(
                AppKind::from($this->appKind),
                $scope,
                $policies,
                $author,
            );

            $this->toastSuccess('Personnalisation Firefox enregistrée.');
            $this->dispatch('customization-saved', appKind: $this->appKind);
        } catch (\InvalidArgumentException $e) {
            $this->toastError('Validation : ' . $e->getMessage());
        } catch (\Throwable $e) {
            $this->toastError('Erreur : ' . $e->getMessage());
        }
    }

    private function resolveScope(): ?Model
    {
        if ($this->scopeType === null || $this->scopeId === null) {
            return null;
        }
        if (! in_array($this->scopeType, self::ALLOWED_SCOPE_TYPES, true)) {
            throw new \InvalidArgumentException('Type de scope non autorisé.');
        }
        /** @var class-string<Model> $cls */
        $cls = $this->scopeType;
        return $cls::query()->find($this->scopeId);
    }
};
?>

<div class="space-y-6">
    <section>
        <h3 class="font-semibold mb-2">Page d'accueil</h3>
        <input type="url"
            wire:model="homepageUrl"
            placeholder="https://exemple.fr/"
            class="input input-bordered input-sm w-full" />
    </section>

    <section>
        <div class="flex items-center justify-between mb-2">
            <h3 class="font-semibold">Marque-pages</h3>
            <button type="button" class="btn btn-xs btn-ghost" wire:click="addBookmark">
                <i class="fa-solid fa-plus"></i> Ajouter
            </button>
        </div>
        <div class="space-y-2">
            @foreach ($bookmarks as $i => $b)
                <div class="grid grid-cols-[1fr_2fr_1fr_auto] gap-2 items-center" wire:key="bookmark-{{ $i }}">
                    <input type="text" wire:model="bookmarks.{{ $i }}.Title" placeholder="Titre" class="input input-bordered input-xs" />
                    <input type="url" wire:model="bookmarks.{{ $i }}.URL" placeholder="https://..." class="input input-bordered input-xs" />
                    <input type="text" wire:model="bookmarks.{{ $i }}.Folder" placeholder="Dossier" class="input input-bordered input-xs" />
                    <button type="button" class="btn btn-xs btn-ghost text-error" wire:click="removeBookmark({{ $i }})">
                        <i class="fa-solid fa-trash"></i>
                    </button>
                </div>
            @endforeach
        </div>
    </section>

    <section>
        <div class="flex items-center justify-between mb-2">
            <h3 class="font-semibold">Extensions Firefox</h3>
            <button type="button" class="btn btn-xs btn-ghost" wire:click="addExtension">
                <i class="fa-solid fa-plus"></i> Ajouter
            </button>
        </div>
        <p class="text-xs text-base-content/60 mb-2">
            Saisir manuellement l'identifiant Gecko, ou utilisez la détection automatique :
            collez l'URL d'une page addon AMO (<code>addons.mozilla.org/…/addon/…</code>)
            ou directement une URL XPI pour les extensions maison.
        </p>
        <div class="space-y-2">
            @foreach ($extensions as $i => $ext)
                <div class="grid grid-cols-[2fr_1fr_2fr_auto] gap-2 items-center" wire:key="ext-{{ $i }}">
                    <input type="text" wire:model="extensions.{{ $i }}.id" placeholder="ID Gecko" class="input input-bordered input-xs" />
                    <select wire:model="extensions.{{ $i }}.installation_mode" class="select select-bordered select-xs">
                        <option value="blocked">Bloquée</option>
                        <option value="force_installed">Installée (forcée)</option>
                    </select>
                    <input type="url" wire:model="extensions.{{ $i }}.install_url" placeholder="URL XPI (si installation)" class="input input-bordered input-xs" />
                    <button type="button" class="btn btn-xs btn-ghost text-error" wire:click="removeExtension({{ $i }})">
                        <i class="fa-solid fa-trash"></i>
                    </button>
                </div>
            @endforeach
        </div>
        <div class="mt-3 flex gap-2">
            <input type="url"
                wire:model="extensionAutoDetectUrl"
                placeholder="URL page AMO ou URL XPI"
                class="input input-bordered input-xs flex-1" />
            <button type="button" class="btn btn-xs" wire:click="autoDetectExtensionId">
                <i class="fa-solid fa-magic-wand-sparkles"></i>
                Auto-détecter
            </button>
        </div>
    </section>

    <div class="modal-action">
        <button type="button" class="btn btn-primary btn-sm" wire:click="save">
            <i class="fa-solid fa-save"></i>
            Enregistrer
        </button>
    </div>
</div>

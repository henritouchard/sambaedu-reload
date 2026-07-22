<?php

use App\Components\Traits\WithToasts;
use App\Exceptions\AppProfileAuthoringException;
use App\Models\Capability;
use App\Models\CapabilityProjection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Story 36.7 (AC1) — /admin/settings/app-profiles : catalogue des profils
 * applicatifs itinérants (Firefox, Thunderbird…) redirigés vers le home réseau.
 *
 * La donnée vit dans le `spec` de LA projection `app_profile` de la capacité
 * `roaming_app_profile` (catalogue-first, pas de table dédiée). L'admin AJOUTE,
 * MODIFIE et ACTIVE/DÉSACTIVE des entrées ; chaque écriture passe par un `save()`
 * Eloquent sur la projection → l'observer {@see \App\Observers\CapabilityProjectionObserver}
 * exécute l'{@see \App\Services\Agent\Providers\AppProfileAuthoringGuard} existant
 * (doublons, radical `sambaedu`, chemins absolus, profile_name incohérent,
 * install_hash, `enabled` booléen) — les violations remontent en TOASTS lisibles,
 * jamais en 500.
 *
 * « OFF RÉEL » (AC1/AC2) : la SUPPRESSION est remplacée par la DÉSACTIVATION
 * (`enabled=false`) — supprimer une entrée dont des profils existent déjà sur les
 * homes orphelinerait les données de l'utilisateur.
 *
 * L'ACTIVATION PAR UTILISATEUR (AC4) ne se fait PAS ici : c'est la section
 * « Capacités » des pages GROUPES D'UTILISATEURS (assignation on/off de la
 * capacité `roaming_app_profile`). Cette page pilote le CATALOGUE, pas le CIBLAGE.
 *
 * Sécurité : `can:server.admin` sur la route + garde `mount()` (cohérent avec les
 * autres /admin/settings/*).
 */
new #[Title('Profils applicatifs itinérants')] class extends Component {
    use WithToasts;

    /** Clé de la capacité qui porte le catalogue (seedée en 36.5). */
    public const CAPABILITY_KEY = 'roaming_app_profile';

    /** @var array<int, array<string,mixed>> */
    public array $entries = [];

    /** Le catalogue (capacité + projection) existe-t-il ? (migration jouée) */
    public bool $catalogExists = false;

    // --- Modale ajout / édition --------------------------------------------
    public bool $isModalOpen = false;
    public bool $isEditing = false;

    /**
     * Index de l'entrée éditée dans le spec.apps courant (null = ajout).
     * `#[Locked]` : index positionnel — un tamper client le ferait pointer une
     * AUTRE entrée (review 36.7 #3). L'identité de l'app capturée à l'ouverture
     * ({@see $editApp}) verrouille en plus contre une édition concurrente.
     */
    #[Locked]
    public ?int $editIndex = null;

    /** App capturée à l'ouverture de la modale d'édition (garde anti-concurrence). */
    #[Locked]
    public ?string $editApp = null;

    public string $app = '';
    public string $link = '';
    public string $server = '';
    public string $profileName = '';
    public string $installHash = '';
    public string $cacheLocal = '';
    public bool $enabled = true;

    public function mount(): void
    {
        abort_unless(Gate::allows('server.admin'), 403);
        $this->loadEntries();
    }

    /** LA projection `app_profile` windows de la capacité de catalogue (ou null). */
    private function projection(): ?CapabilityProjection
    {
        $capability = Capability::query()->where('key', self::CAPABILITY_KEY)->first();
        if ($capability === null) {
            return null;
        }

        return $capability->projections()
            ->where('os', Capability::OS_WINDOWS)
            ->where('mechanism', CapabilityProjection::MECHANISM_APP_PROFILE)
            ->first();
    }

    public function loadEntries(): void
    {
        $projection = $this->projection();
        $this->catalogExists = $projection !== null;

        $apps = ($projection !== null && is_array($projection->spec) && isset($projection->spec['apps'])
            && is_array($projection->spec['apps']))
            ? $projection->spec['apps']
            : [];

        $this->entries = [];
        foreach ($apps as $index => $app) {
            if (! is_array($app)) {
                continue;
            }
            $this->entries[] = [
                'index' => (int) $index,
                'app' => (string) ($app['app'] ?? ''),
                'link' => (string) ($app['link'] ?? ''),
                'server' => (string) ($app['server'] ?? ''),
                'profile_name' => (string) ($app['profile_name'] ?? ''),
                'install_hash' => (string) ($app['install_hash'] ?? ''),
                'cache_local' => (string) ($app['cache_local'] ?? ''),
                // ABSENT = actif (défaut `true`, iso provider/guard) ; seul `false`
                // strict désactive.
                'enabled' => ($app['enabled'] ?? true) !== false,
            ];
        }
    }

    public function openCreate(): void
    {
        abort_unless(Gate::allows('server.admin'), 403);
        $this->resetForm();
        $this->isEditing = false;
        $this->editIndex = null;
        $this->isModalOpen = true;
    }

    public function openEdit(int $index): void
    {
        abort_unless(Gate::allows('server.admin'), 403);

        $entry = collect($this->entries)->firstWhere('index', $index);
        if ($entry === null) {
            $this->toastError('Entrée introuvable — la page a peut-être changé, rechargez.');
            return;
        }

        $this->resetForm();
        $this->isEditing = true;
        $this->editIndex = $index;
        $this->editApp = $entry['app'];
        $this->app = $entry['app'];
        $this->link = $entry['link'];
        $this->server = $entry['server'];
        $this->profileName = $entry['profile_name'];
        $this->installHash = $entry['install_hash'];
        $this->cacheLocal = $entry['cache_local'];
        $this->enabled = $entry['enabled'];
        $this->isModalOpen = true;
    }

    public function close(): void
    {
        $this->isModalOpen = false;
        $this->resetForm();
    }

    private function resetForm(): void
    {
        $this->editIndex = null;
        $this->editApp = null;
        $this->app = '';
        $this->link = '';
        $this->server = '';
        $this->profileName = '';
        $this->installHash = '';
        $this->cacheLocal = '';
        $this->enabled = true;
        $this->resetErrorBag();
    }

    protected function formRules(): array
    {
        return [
            'app' => ['required', 'string', 'max:64'],
            'link' => ['required', 'string', 'max:255'],
            'server' => ['required', 'string', 'max:255'],
            'profileName' => ['required', 'string', 'max:255'],
            'installHash' => ['nullable', 'string', 'max:16'],
            'cacheLocal' => ['nullable', 'string', 'max:64'],
        ];
    }

    protected function formMessages(): array
    {
        return [
            'app.required' => "L'identifiant de l'application est requis.",
            'link.required' => 'Le chemin `link` (relatif au profil Windows) est requis.',
            'server.required' => 'Le chemin `server` (relatif au home réseau) est requis.',
            'profileName.required' => 'Le nom de profil est requis.',
        ];
    }

    /**
     * Persiste l'entrée dans le `spec` de la projection via Eloquent : l'observer
     * exécute l'AppProfileAuthoringGuard. Violation ⇒ toasts lisibles (jamais 500).
     */
    public function save()
    {
        abort_unless(Gate::allows('server.admin'), 403);

        $this->validate($this->formRules(), $this->formMessages());

        $projection = $this->projection();
        if ($projection === null) {
            $this->toastError('Catalogue introuvable : la migration de seed n\'est pas jouée sur cette instance.');
            return;
        }

        $spec = is_array($projection->spec) ? $projection->spec : [];
        $apps = (isset($spec['apps']) && is_array($spec['apps'])) ? array_values($spec['apps']) : [];

        // Entrée CONCRÈTE : champs minimaux verbatim + optionnels seulement si non
        // vides (absence = feature désactivée, iso payloadFor du provider).
        $entry = [
            'app' => trim($this->app),
            'link' => trim($this->link),
            'server' => trim($this->server),
            'profile_name' => trim($this->profileName),
            'enabled' => $this->enabled, // booléen STRICT (guard AC2).
        ];
        if (trim($this->installHash) !== '') {
            $entry['install_hash'] = trim($this->installHash);
        }
        if (trim($this->cacheLocal) !== '') {
            $entry['cache_local'] = trim($this->cacheLocal);
        }

        if ($this->isEditing) {
            if ($this->editIndex === null || ! array_key_exists($this->editIndex, $apps)) {
                $this->toastError('Entrée introuvable — rechargez la page.');
                return;
            }
            // Garde anti-concurrence (review 36.7 #3) : le spec a été RECHARGÉ ;
            // si l'entrée à cet index ne porte plus l'app capturée à l'ouverture
            // (autre admin/onglet a inséré/supprimé/réordonné entre-temps), refuser
            // plutôt qu'écraser silencieusement la mauvaise entrée.
            if (($apps[$this->editIndex]['app'] ?? null) !== $this->editApp) {
                $this->toastError('Le catalogue a changé depuis l\'ouverture — rechargez la page avant de réenregistrer.');
                return;
            }
            $apps[$this->editIndex] = $entry;
        } else {
            $apps[] = $entry;
        }

        $spec['apps'] = array_values($apps);

        try {
            $projection->spec = $spec;
            $projection->save();
        } catch (AppProfileAuthoringException $e) {
            // Le garde-fou d'authoring a refusé : messages FR explicites (doublons,
            // radical sambaedu, chemins absolus, profile_name incohérent…).
            foreach ($e->violations as $violation) {
                $this->toastError($violation);
            }
            return;
        }

        $this->toastSuccess($this->isEditing
            ? "L'entrée « {$entry['app']} » a été mise à jour."
            : "L'entrée « {$entry['app']} » a été ajoutée au catalogue.");
        $this->close();
        $this->loadEntries();
    }

    /** Active / désactive une entrée (« off réel » — jamais de suppression). */
    public function toggleEnabled(int $index)
    {
        abort_unless(Gate::allows('server.admin'), 403);

        $projection = $this->projection();
        if ($projection === null) {
            $this->toastError('Catalogue introuvable.');
            return;
        }

        $spec = is_array($projection->spec) ? $projection->spec : [];
        $apps = (isset($spec['apps']) && is_array($spec['apps'])) ? array_values($spec['apps']) : [];

        if (! array_key_exists($index, $apps) || ! is_array($apps[$index])) {
            $this->toastError('Entrée introuvable — rechargez la page.');
            return;
        }

        $wasEnabled = ($apps[$index]['enabled'] ?? true) !== false;
        $apps[$index]['enabled'] = ! $wasEnabled; // booléen strict.
        $spec['apps'] = array_values($apps);

        try {
            $projection->spec = $spec;
            $projection->save();
        } catch (AppProfileAuthoringException $e) {
            foreach ($e->violations as $violation) {
                $this->toastError($violation);
            }
            return;
        }

        $appId = (string) ($apps[$index]['app'] ?? 'entrée');
        $this->toastSuccess($wasEnabled
            ? "« {$appId} » désactivée : plus aucun nouveau profil redirigé (les profils déjà posés restent en place)."
            : "« {$appId} » activée : la redirection reprend au prochain état compilé.");
        $this->loadEntries();
    }
};
?>

<x-organisms.page title="Profils applicatifs itinérants"
    icon="fa-solid fa-compass"
    description="Catalogue des applications dont le profil (signets, préférences) suit l'utilisateur d'un poste à l'autre via son home réseau."
    back="{{ route('admin.settings') }}">

    <x-slot:actions>
        @if ($catalogExists)
            <button type="button" class="btn highlight btn-primary" wire:click="openCreate" data-testid="open-create">
                <i class="fa-solid fa-plus"></i> Ajouter une application
            </button>
        @endif
    </x-slot:actions>

    <div class="flex flex-col gap-6 pt-4">

        <div class="alert alert-info shadow-sm">
            <i class="fa-solid fa-circle-info"></i>
            <div>
                <p class="font-medium">Comment ça marche</p>
                <p class="text-sm opacity-80">
                    Chaque entrée redirige le profil d'une application vers le home réseau de l'utilisateur —
                    ses signets et préférences le suivent d'un poste à l'autre. La redirection est
                    <strong>indépendante du lecteur K:</strong> : elle fonctionne même si le home est masqué dans
                    l'Explorateur. L'<strong>activation par public</strong> (élèves, enseignants…) se règle dans la
                    section « Capacités » des pages <strong>groupes d'utilisateurs</strong>.
                </p>
            </div>
        </div>

        @if (! $catalogExists)
            <div class="card bg-base-100 border border-base-300">
                <div class="card-body items-center text-center py-16">
                    <i class="fa-solid fa-triangle-exclamation text-4xl text-warning opacity-60 mb-3"></i>
                    <h3 class="text-lg font-semibold mb-1">Catalogue indisponible</h3>
                    <p class="text-base-content/60 max-w-lg">
                        La capacité « {{ self::CAPABILITY_KEY }} » n'est pas présente sur cette instance
                        (migration de seed non jouée). Jouez les migrations pour éditer le catalogue.
                    </p>
                </div>
            </div>
        @elseif (count($entries) === 0)
            <div class="card bg-base-100 border border-base-300">
                <div class="card-body items-center text-center py-16">
                    <i class="fa-solid fa-compass text-4xl opacity-30 mb-3"></i>
                    <h3 class="text-lg font-semibold mb-1">Catalogue vide</h3>
                    <p class="text-base-content/60 mb-6">Aucune application n'est encore redirigée.</p>
                    <button type="button" class="btn btn-primary" wire:click="openCreate">
                        <i class="fa-solid fa-plus"></i> Ajouter une application
                    </button>
                </div>
            </div>
        @else
            <x-organisms.data-table
                colgroup="<colgroup><col style='width: 14%'><col style='width: 26%'><col style='width: 24%'><col style='width: 12%'><col style='width: 8%'><col style='width: 16%'></colgroup>">
                <x-slot:header>
                    <th>Application</th>
                    <th>Chemin local (link)</th>
                    <th>Chemin home (server)</th>
                    <th>Profil</th>
                    <th>État</th>
                    <th class="text-right">Actions</th>
                </x-slot:header>
                @foreach ($entries as $entry)
                    <tr wire:key="entry-{{ $entry['index'] }}">
                        <td class="font-bold">{{ $entry['app'] }}</td>
                        <td><span class="font-mono text-xs">{{ $entry['link'] }}</span></td>
                        <td><span class="font-mono text-xs">{{ $entry['server'] }}</span></td>
                        <td class="text-sm">{{ $entry['profile_name'] }}</td>
                        <td>
                            @if ($entry['enabled'])
                                <span class="badge badge-sm badge-success">Active</span>
                            @else
                                <span class="badge badge-sm badge-ghost">Désactivée</span>
                            @endif
                        </td>
                        <td class="text-right whitespace-nowrap">
                            <button type="button" class="btn btn-ghost btn-xs"
                                wire:click="openEdit({{ $entry['index'] }})"
                                data-testid="edit-{{ $entry['index'] }}">
                                <i class="fa-solid fa-pen"></i> Modifier
                            </button>
                            @if ($entry['enabled'])
                                <button type="button" class="btn btn-ghost btn-xs text-warning"
                                    wire:click="toggleEnabled({{ $entry['index'] }})"
                                    data-testid="toggle-{{ $entry['index'] }}">
                                    <i class="fa-solid fa-ban"></i> Désactiver
                                </button>
                            @else
                                <button type="button" class="btn btn-ghost btn-xs text-success"
                                    wire:click="toggleEnabled({{ $entry['index'] }})"
                                    data-testid="toggle-{{ $entry['index'] }}">
                                    <i class="fa-solid fa-check"></i> Réactiver
                                </button>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </x-organisms.data-table>
        @endif
    </div>

    {{-- Modale ajout / édition (réutilisable x-molecules.modal). --}}
    <x-molecules.modal wire:model="isModalOpen" size="max-w-2xl" height="h-auto max-h-[90vh]"
        title="{{ $isEditing ? 'Modifier une application' : 'Ajouter une application' }}"
        icon="fa-compass text-primary" closeMethod="close">

        <x-molecules.modal.section title="Identité" icon="fa-circle-info text-primary" dense>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="flex flex-col gap-1 md:col-span-2">
                    <label class="label-text font-medium">Identifiant de l'application <span class="text-error" aria-hidden="true">*</span></label>
                    <input type="text" wire:model="app" class="input input-bordered w-full font-mono"
                        placeholder="firefox" data-testid="field-app" />
                    @error('app') <span class="text-error text-xs">{{ $message }}</span> @enderror
                </div>

                <div class="flex flex-col gap-1 md:col-span-2">
                    <label class="label-text font-medium">Chemin local — link <span class="text-error" aria-hidden="true">*</span></label>
                    <input type="text" wire:model="link" class="input input-bordered w-full font-mono text-sm"
                        placeholder="AppData\Roaming\Mozilla\Firefox\managed.default" data-testid="field-link" />
                    <span class="text-xs opacity-60">Relatif au profil Windows de l'utilisateur (jamais un chemin absolu ou UNC).</span>
                    @error('link') <span class="text-error text-xs">{{ $message }}</span> @enderror
                </div>

                <div class="flex flex-col gap-1 md:col-span-2">
                    <label class="label-text font-medium">Chemin home — server <span class="text-error" aria-hidden="true">*</span></label>
                    <input type="text" wire:model="server" class="input input-bordered w-full font-mono text-sm"
                        placeholder=".mozilla\firefox\managed.default" data-testid="field-server" />
                    <span class="text-xs opacity-60">Relatif au home réseau. Le serveur préfixe automatiquement <span class="font-mono">\\&lt;se4fs&gt;\users\&lt;user&gt;\</span>.</span>
                    @error('server') <span class="text-error text-xs">{{ $message }}</span> @enderror
                </div>

                <div class="flex flex-col gap-1 md:col-span-2">
                    <label class="label-text font-medium">Nom de profil <span class="text-error" aria-hidden="true">*</span></label>
                    <input type="text" wire:model="profileName" class="input input-bordered w-full font-mono"
                        placeholder="managed.default" data-testid="field-profile" />
                    <span class="text-xs opacity-60">Doit être le dernier segment de « link ». Neuf, stable, sans le radical « sambaedu ».</span>
                    @error('profileName') <span class="text-error text-xs">{{ $message }}</span> @enderror
                </div>
            </div>
        </x-molecules.modal.section>

        <x-molecules.modal.section title="Options avancées" icon="fa-sliders text-primary" dense>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="flex flex-col gap-1">
                    <label class="label-text font-medium">install_hash</label>
                    <input type="text" wire:model="installHash" class="input input-bordered w-full font-mono"
                        placeholder="308046B0AF4A39CB" data-testid="field-install-hash" />
                    <span class="text-xs opacity-60">Hexadécimal (8–16). Vide = pas de section Install (Mozilla).</span>
                    @error('installHash') <span class="text-error text-xs">{{ $message }}</span> @enderror
                </div>

                <div class="flex flex-col gap-1">
                    <label class="label-text font-medium">cache_local</label>
                    <input type="text" wire:model="cacheLocal" class="input input-bordered w-full font-mono"
                        placeholder="cacheFirefox" data-testid="field-cache-local" />
                    <span class="text-xs opacity-60">Dossier de cache épinglé local sous %LOCALAPPDATA%. Vide = pas d'épinglage.</span>
                    @error('cacheLocal') <span class="text-error text-xs">{{ $message }}</span> @enderror
                </div>
            </div>

            <label class="label cursor-pointer justify-start gap-2 mt-3">
                <input type="checkbox" wire:model="enabled" class="checkbox checkbox-sm checkbox-primary"
                    data-testid="field-enabled" />
                <span class="label-text">Entrée active (la redirection s'applique).</span>
            </label>
        </x-molecules.modal.section>

        @if ($isEditing)
            <x-molecules.modal.section title="Attention" icon="fa-triangle-exclamation text-warning" dense>
                <div class="alert alert-warning text-sm">
                    <i class="fa-solid fa-triangle-exclamation"></i>
                    <span>
                        Modifier « server » ou le nom de profil après déploiement <strong>orpheline les
                        données déjà posées</strong> sur les homes (l'ancien profil reste sur le réseau, un
                        nouveau vide est créé). Ne le changez que sur une entrée pas encore utilisée.
                    </span>
                </div>
            </x-molecules.modal.section>
        @endif

        <x-slot:footer>
            <button type="button" class="btn btn-ghost" wire:click="close">Annuler</button>
            <button type="button" class="btn btn-primary" wire:click="save" wire:loading.attr="disabled" wire:target="save"
                data-testid="save">
                <span wire:loading.remove wire:target="save"><i class="fa-solid fa-floppy-disk"></i> Enregistrer</span>
                <span wire:loading wire:target="save"><span class="loading loading-spinner loading-xs"></span> Enregistrement…</span>
            </button>
        </x-slot:footer>
    </x-molecules.modal>
</x-organisms.page>

<?php

use App\Components\Traits\WithToasts;
use App\Exceptions\Filesystem\NetworkShareLetterCollisionException;
use App\Models\DirectoryTemplate;
use App\Models\NetworkShare;
use App\Models\User;
use App\Models\UserGroup;
use App\Services\Filesystem\DirectoryTemplateService;
use App\Services\Filesystem\NetworkShareService;
use App\Services\Filesystem\NetworkShareValidator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Story 34.2 — Page liste des lecteurs réseau gérés + modale de création.
 *
 * SFC Volt (iso `pages/shortcuts/index.blade.php`). Pivot SQL pur (zéro CN AD).
 * Gardée par la policy dédiée `networkshare.*` (Q5) : la route impose
 * `can:networkshare.view`, les actions de gestion vérifient `manage-networkshare`.
 */
new #[Title('Lecteurs réseau gérés - Instance SE4FS')] class extends Component {
    use WithToasts;

    #[Url]
    public string $search = '';
    #[Url]
    public int $perPage = 20;
    #[Url]
    public int $currentPage = 1;

    public array $allowedPerPage = [10, 20, 50, 100];

    /** @var array<int, array<string, mixed>> */
    public array $shares = [];
    public int $totalShares = 0;
    public ?array $pagination = null;

    // --- Modale de création -------------------------------------------------
    public bool $isCreateOpen = false;
    public string $name = '';
    public string $directoryName = '';
    public string $label = '';
    public string $letter = '';

    // --- Modale « Créer depuis un template » (Story 34.3) -------------------
    public bool $isTemplateOpen = false;
    public string $selectedTemplateKey = '';
    public string $templateName = '';
    public string $templateDirectoryName = '';
    public string $templateLabel = '';
    public string $templateLetter = '';

    /**
     * Sélections de cibles par rôle de la recette : `[roleKey => id|null]`
     * (cardinalité `one`) ou `[roleKey => [id, …]]` (cardinalité `many`).
     *
     * @var array<string, mixed>
     */
    public array $roleSelections = [];

    // --- Sélection multiple + suppression groupée ---------------------------
    /** @var array<int, string> ids (string, cf. x-molecules.select-all-checkbox) sélectionnés */
    public array $selectedShares = [];
    public bool $isBulkDeleteOpen = false;
    public string $deleteConfirmation = '';

    public function mount(): void
    {
        // Defense-in-depth + cohérence avec la page détail (mount `view`) : ferme
        // l'éventuel vecteur d'hydratation directe du composant (finding review #5).
        abort_unless(Gate::allows('view-networkshare'), 403);
        $this->loadShares();
    }

    public function updatedSearch(): void
    {
        $this->currentPage = 1;
        $this->loadShares();
    }

    public function updatedPerPage(): void
    {
        if (! in_array($this->perPage, $this->allowedPerPage, true)) {
            $this->perPage = 20;
        }
        $this->currentPage = 1;
        $this->loadShares();
    }

    public function loadShares(): void
    {
        try {
            $query = NetworkShare::query();

            if ($this->search !== '') {
                $needle = '%' . strtolower($this->search) . '%';
                $query->where(function ($q) use ($needle): void {
                    $q->whereRaw('LOWER(name) LIKE ?', [$needle])
                        ->orWhereRaw('LOWER(directory_name) LIKE ?', [$needle]);
                });
            }

            $this->totalShares = $query->count();

            $lastPage = max(1, (int) ceil($this->totalShares / $this->perPage));
            $this->currentPage = min(max(1, $this->currentPage), $lastPage);
            $offset = ($this->currentPage - 1) * $this->perPage;

            $rows = $query
                ->withCount(['users', 'userGroups', 'workstationGroups'])
                ->orderBy('name')
                ->skip($offset)
                ->take($this->perPage)
                ->get();

            $this->shares = $rows->map(fn (NetworkShare $s): array => [
                'id' => $s->id,
                'name' => $s->name,
                'directory_name' => $s->directory_name,
                'description' => $s->description,
                'letter' => $s->letter,
                'users_count' => $s->users_count,
                'user_groups_count' => $s->user_groups_count,
                'workstation_groups_count' => $s->workstation_groups_count,
                'total_assignments' => $s->users_count + $s->user_groups_count + $s->workstation_groups_count,
            ])->all();

            $this->pagination = [
                'current_page' => $this->currentPage,
                'per_page' => $this->perPage,
                'total' => $this->totalShares,
                'last_page' => $lastPage,
                'from' => $this->totalShares > 0 ? $offset + 1 : 0,
                'to' => min($offset + $this->perPage, $this->totalShares),
                'has_more_pages' => $this->currentPage < $lastPage,
            ];
        } catch (\Throwable $e) {
            Log::error('SharesPage loadShares error: ' . $e->getMessage());
            $this->shares = [];
            $this->pagination = null;
            $this->totalShares = 0;
        }
    }

    public function goToPage($page): void
    {
        $this->currentPage = max(1, min((int) $page, $this->pagination['last_page'] ?? 1));
        $this->loadShares();
    }

    public function resetFilters(): void
    {
        $this->search = '';
        $this->currentPage = 1;
        $this->loadShares();
    }

    // --- Suppression groupée ------------------------------------------------

    /** @return list<int> ids sélectionnés dédupliqués (castés int). */
    private function selectedShareIds(): array
    {
        return array_values(array_unique(array_map('intval', $this->selectedShares)));
    }

    /**
     * Chaîne EXACTE à saisir pour confirmer : le nom du lecteur si UN SEUL est
     * sélectionné, sinon le mot-clé `SUPPRIMER` (typer N noms serait impraticable).
     */
    public function getBulkConfirmTargetProperty(): string
    {
        $ids = $this->selectedShareIds();
        if (count($ids) === 1) {
            return (string) (NetworkShare::whereKey($ids[0])->value('name') ?? '');
        }

        return 'SUPPRIMER';
    }

    /** @return list<array{id:int,name:string,letter:?string}> lignes sélectionnées (affichage modale). */
    public function getSelectedShareRowsProperty(): array
    {
        $ids = $this->selectedShareIds();
        if ($ids === []) {
            return [];
        }

        return NetworkShare::whereIn('id', $ids)->orderBy('name')
            ->get(['id', 'name', 'letter'])
            ->map(fn (NetworkShare $s): array => ['id' => $s->id, 'name' => $s->name, 'letter' => $s->letter])
            ->all();
    }

    public function openBulkDelete(): void
    {
        abort_unless(Gate::allows('manage-networkshare'), 403);

        if ($this->selectedShareIds() === []) {
            $this->toastError('Aucun lecteur réseau sélectionné.');

            return;
        }

        $this->deleteConfirmation = '';
        $this->isBulkDeleteOpen = true;
    }

    public function bulkDelete(): void
    {
        abort_unless(Gate::allows('manage-networkshare'), 403);

        $ids = $this->selectedShareIds();
        if ($ids === []) {
            $this->isBulkDeleteOpen = false;

            return;
        }

        // Garde-fou destructif : saisie EXACTE requise (nom si un seul, sinon
        // « SUPPRIMER »). Comparaison trimée, sensible à la casse.
        if (trim($this->deleteConfirmation) !== $this->bulkConfirmTarget) {
            $this->toastError('La confirmation saisie ne correspond pas.');

            return;
        }

        $shares = NetworkShare::whereIn('id', $ids)->get();
        $service = app(NetworkShareService::class);
        $deprovFailures = 0;

        foreach ($shares as $share) {
            // Révoque les ACL + archive le dossier (mv en poubelle, jamais rm -rf)
            // AVANT la suppression SQL (iso page détail) ; le pivot cascade.
            if (! $service->deprovision($share)) {
                $deprovFailures++;
            }
            NetworkShare::whereKey($share->id)->delete();
        }

        $count = $shares->count();
        $this->isBulkDeleteOpen = false;
        $this->deleteConfirmation = '';
        $this->selectedShares = [];
        $this->currentPage = 1;
        $this->loadShares();

        if ($deprovFailures > 0) {
            $this->toastWarning("{$count} lecteur(s) supprimé(s), mais la révocation serveur a échoué pour {$deprovFailures}. Consultez les journaux.");
        } else {
            $this->toastSuccess("{$count} lecteur(s) réseau supprimé(s) : accès révoqués et dossiers archivés (fichiers conservés côté serveur).");
        }
    }

    // --- Création -----------------------------------------------------------

    public function openCreate(): void
    {
        abort_unless(Gate::allows('manage-networkshare'), 403);

        $this->resetCreateForm();
        // Q2 — pré-remplir la prochaine lettre sûre libre (encourager l'explicite,
        // modifiable / effaçable → retombe sur l'auto-assignation du provider).
        $this->letter = app(NetworkShareValidator::class)->suggestNextFreeLetter() ?? '';
        $this->isCreateOpen = true;
    }

    public function close(): void
    {
        $this->isCreateOpen = false;
        $this->resetCreateForm();
    }

    private function resetCreateForm(): void
    {
        $this->name = '';
        $this->directoryName = '';
        $this->label = '';
        $this->letter = '';
        $this->resetErrorBag();
    }

    protected function createRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'directoryName' => [
                'required',
                'string',
                'max:255',
                'regex:' . NetworkShareService::DIRECTORY_NAME_PATTERN,
                'unique:network_shares,directory_name',
            ],
            'label' => ['nullable', 'string', 'max:255'],
            'letter' => [
                'nullable',
                'string',
                'max:8',
                function (string $attribute, $value, $fail): void {
                    if (app(NetworkShareValidator::class)->isReservedLetter($value)) {
                        $fail('Cette lettre est réservée par le système (A-D, H, I, K, L). '
                            . 'Choisissez une autre lettre ou laissez le champ vide (attribution automatique).');
                    }
                },
            ],
        ];
    }

    protected function createMessages(): array
    {
        return [
            'name.required' => 'Le nom est requis.',
            'directoryName.required' => 'Le nom de répertoire est requis.',
            'directoryName.regex' => 'Le nom de répertoire ne peut contenir que des lettres, chiffres, '
                . '« . », « _ » et « - » (sans espace), et ne peut pas commencer par « . ».',
            'directoryName.unique' => 'Ce nom de répertoire est déjà utilisé.',
        ];
    }

    public function createShare(): void
    {
        abort_unless(Gate::allows('manage-networkshare'), 403);

        $validated = $this->validate($this->createRules(), $this->createMessages());

        $share = new NetworkShare([
            'name' => $validated['name'],
            'directory_name' => $validated['directoryName'],
            'label' => $validated['label'] !== '' ? $validated['label'] : null,
            'letter' => $this->normalizedLetter($validated['letter'] ?? null),
            'created_by_user_id' => $this->currentUserId(),
        ]);

        // Validation prédictive (defense-in-depth) — un répertoire neuf n'a pas
        // encore d'audience, donc pas de collision possible ici, mais on garde
        // l'appel pour homogénéité avec le chemin d'édition.
        try {
            app(NetworkShareValidator::class)->assertNoLetterCollision($share);
        } catch (NetworkShareLetterCollisionException $e) {
            $this->toastError($e->getMessage());
            return;
        }

        $share->save();

        $ok = app(NetworkShareService::class)->provision($share);
        if ($ok) {
            $this->toastSuccess("Le répertoire « {$share->name} » a été créé et provisionné.");
        } else {
            $this->toastWarning("Le répertoire « {$share->name} » a été créé, mais son provisioning a échoué. "
                . 'Consultez les journaux serveur.');
        }

        $this->isCreateOpen = false;
        $this->resetCreateForm();
        $this->loadShares();
    }

    // --- Matérialisation depuis un template (Story 34.3) --------------------

    /**
     * Catalogue des recettes (lu depuis la table `directory_templates`, Q3 option B).
     *
     * @return array<int, array<string, mixed>>
     */
    public function templates(): array
    {
        return DirectoryTemplate::orderBy('id')->get()
            ->map(fn (DirectoryTemplate $t): array => [
                'key' => $t->key,
                'label' => $t->label,
                'description' => $t->description,
                'roles' => $t->roles(),
            ])->all();
    }

    /** Recette sélectionnée (modèle), ou null. */
    public function selectedTemplate(): ?DirectoryTemplate
    {
        if ($this->selectedTemplateKey === '') {
            return null;
        }

        return DirectoryTemplate::where('key', $this->selectedTemplateKey)->first();
    }

    /**
     * Candidats par rôle de la recette sélectionnée (pickers SQL `User`/`UserGroup`,
     * zéro CN AD ; `UserGroup` filtré par `group_type` quand la recette le contraint).
     *
     * @return array<string, array<int, array{id:int,label:string}>>
     */
    public function roleCandidates(): array
    {
        $template = $this->selectedTemplate();
        if ($template === null) {
            return [];
        }

        $out = [];
        foreach ($template->roles() as $role) {
            $roleKey = (string) ($role['key'] ?? '');
            $maille = (string) ($role['maille'] ?? '');
            $groupType = $role['group_type'] ?? null;

            if ($maille === User::class) {
                $out[$roleKey] = User::query()
                    ->orderBy('login')
                    ->limit(100)
                    ->get(['id', 'login'])
                    ->map(fn (User $u): array => ['id' => $u->id, 'label' => (string) $u->login])
                    ->all();
            } elseif ($maille === UserGroup::class) {
                $out[$roleKey] = UserGroup::query()
                    ->when($groupType !== null, fn ($q) => $q->where('type', $groupType))
                    ->orderBy('name')
                    ->limit(100)
                    ->get(['id', 'name', 'display_name'])
                    ->map(fn (UserGroup $g): array => ['id' => $g->id, 'label' => (string) ($g->display_name ?: $g->name)])
                    ->all();
            } else {
                $out[$roleKey] = [];
            }
        }

        return $out;
    }

    /**
     * Aperçu des assignations qui seront créées (cible → maille → access), AVANT
     * matérialisation.
     *
     * @return array<int, array{label:string,maille:string,access:string}>
     */
    public function templatePreview(): array
    {
        $template = $this->selectedTemplate();
        if ($template === null) {
            return [];
        }

        $candidates = $this->roleCandidates();
        $preview = [];

        foreach ($template->roles() as $role) {
            $roleKey = (string) ($role['key'] ?? '');
            $maille = (string) ($role['maille'] ?? '');
            $access = \App\Models\NetworkShareAssignable::accessLabel((string) ($role['access'] ?? 'ro'));
            $mailleLabel = $maille === User::class ? 'Utilisateur' : "Groupe d'utilisateurs";

            $byId = collect($candidates[$roleKey] ?? [])->keyBy('id');

            foreach ($this->normalizedRoleIds($roleKey) as $id) {
                $preview[] = [
                    'label' => (string) ($byId[$id]['label'] ?? "#{$id}"),
                    'maille' => $mailleLabel,
                    'access' => $access,
                ];
            }
        }

        return $preview;
    }

    /**
     * IDs sélectionnés pour un rôle, normalisés en liste d'entiers (gère les deux
     * formes de binding : scalaire pour `one`, tableau pour `many`).
     *
     * @return list<int>
     */
    private function normalizedRoleIds(string $roleKey): array
    {
        $raw = $this->roleSelections[$roleKey] ?? null;
        if ($raw === null || $raw === '') {
            return [];
        }
        $list = is_array($raw) ? $raw : [$raw];

        return array_values(array_filter(array_map(
            static fn ($v): int => (int) $v,
            $list,
        ), static fn (int $id): bool => $id > 0));
    }

    public function openTemplate(): void
    {
        abort_unless(Gate::allows('manage-networkshare'), 403);

        $this->resetTemplateForm();
        // Pré-remplir la prochaine lettre sûre libre (encourager l'explicite — Q5
        // : le NOM, lui, reste manuel, pas d'auto-dérivation slug).
        $this->templateLetter = app(NetworkShareValidator::class)->suggestNextFreeLetter() ?? '';
        $this->isTemplateOpen = true;
    }

    public function closeTemplate(): void
    {
        $this->isTemplateOpen = false;
        $this->resetTemplateForm();
    }

    public function updatedSelectedTemplateKey(): void
    {
        // Changement de recette → réinitialise les sélections de cibles (les rôles
        // exposés changent dynamiquement).
        $this->roleSelections = [];
        $this->resetErrorBag();
    }

    private function resetTemplateForm(): void
    {
        $this->selectedTemplateKey = '';
        $this->templateName = '';
        $this->templateDirectoryName = '';
        $this->templateLabel = '';
        $this->templateLetter = '';
        $this->roleSelections = [];
        $this->resetErrorBag();
    }

    protected function templateRules(): array
    {
        return [
            'selectedTemplateKey' => ['required', 'string', 'exists:directory_templates,key'],
            'templateName' => ['required', 'string', 'max:255'],
            'templateDirectoryName' => [
                'required',
                'string',
                'max:255',
                'regex:' . NetworkShareService::DIRECTORY_NAME_PATTERN,
                'unique:network_shares,directory_name',
            ],
            'templateLabel' => ['nullable', 'string', 'max:255'],
            'templateLetter' => [
                'nullable',
                'string',
                'max:8',
                function (string $attribute, $value, $fail): void {
                    if (app(NetworkShareValidator::class)->isReservedLetter($value)) {
                        $fail('Cette lettre est réservée par le système (A-D, H, I, K, L). '
                            . 'Choisissez une autre lettre ou laissez le champ vide (attribution automatique).');
                    }
                },
            ],
        ];
    }

    protected function templateMessages(): array
    {
        return [
            'selectedTemplateKey.required' => 'Choisissez un template.',
            'templateName.required' => 'Le nom est requis.',
            'templateDirectoryName.required' => 'Le nom de répertoire est requis.',
            'templateDirectoryName.regex' => 'Le nom de répertoire ne peut contenir que des lettres, chiffres, '
                . '« . », « _ » et « - » (sans espace), et ne peut pas commencer par « . ».',
            'templateDirectoryName.unique' => 'Ce nom de répertoire est déjà utilisé. Éditez le répertoire existant depuis sa page.',
        ];
    }

    public function createFromTemplate(): void
    {
        abort_unless(Gate::allows('manage-networkshare'), 403);

        $validated = $this->validate($this->templateRules(), $this->templateMessages());

        $template = $this->selectedTemplate();
        if ($template === null) {
            $this->toastError('Template introuvable.');
            return;
        }

        // Construit le mapping rôle → liste d'IDs sélectionnés (normalisé).
        $roles = [];
        foreach ($template->roles() as $role) {
            $roleKey = (string) ($role['key'] ?? '');
            $roles[$roleKey] = $this->normalizedRoleIds($roleKey);
        }

        try {
            $result = app(DirectoryTemplateService::class)->materialize($template, [
                'name' => $validated['templateName'],
                'directory_name' => $validated['templateDirectoryName'],
                'label' => $validated['templateLabel'] ?? null,
                'letter' => $validated['templateLetter'] ?? null,
                'roles' => $roles,
            ]);
        } catch (NetworkShareLetterCollisionException $e) {
            // Collision de lettre : rollback transactionnel déjà effectué (aucune
            // écriture partielle). On surface le message en toast (pas de création).
            $this->toastError($e->getMessage());
            return;
        } catch (\InvalidArgumentException $e) {
            // Format / lettre réservée / rôles invalides (cardinalité, cible
            // introuvable, typage de groupe) — refus AVANT écriture.
            $this->toastError($e->getMessage());
            return;
        }

        $message = $result->provisioned
            ? "Le répertoire « {$result->share->name} » a été créé depuis le template et provisionné."
            : "Le répertoire « {$result->share->name} » a été créé, mais son provisioning a échoué. Consultez les journaux serveur.";

        // Surfaçage des avertissements prédictifs non bloquants (WG-montage-seul,
        // AC2). Inerte pour les 4 recettes seedées (aucune n'assigne de parc),
        // mais on honore le contrat et on défend les recettes futures : un warning
        // bascule le toast en statut « warning » et l'annexe au message.
        $warnings = $result->warnings;
        if ($warnings !== []) {
            $message .= ' ⚠ ' . implode(' ', $warnings);
        }

        session()->flash('toast', [
            'status' => ($result->provisioned && $warnings === []) ? 'success' : 'warning',
            'title' => $result->provisioned
                ? ($warnings === [] ? 'Répertoire créé' : 'Répertoire créé (avec avertissements)')
                : 'Provisioning incomplet',
            'message' => $message,
        ]);

        $this->isTemplateOpen = false;
        $this->resetTemplateForm();

        // Retour vers la page détail du share créé (édition fine ensuite, 34.2).
        $this->redirect(route('admin.shares.show', $result->share->id), navigate: true);
    }

    private function normalizedLetter(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }
        $trimmed = trim($raw);
        if ($trimmed === '') {
            return null;
        }
        $bare = strtoupper($trimmed[0]);

        return $bare . ':';
    }

    private function currentUserId(): ?int
    {
        $user = auth()->user();
        return ($user instanceof \App\Models\User) ? $user->id : null;
    }
}; ?>

@php
    $hasFilters = $search !== '';
@endphp

{{-- Rendu comme ONGLET de /admin/settings/files (« Lecteurs réseaux »), pas comme
     page autonome : plus de wrapper <x-organisms.page> (le host fournit le chrome).
     Les actions passent de <x-slot:actions> à un en-tête interne. --}}
<div>
    <div class="flex items-start justify-between gap-4 mb-4">
        <div>
            <h3 class="text-lg font-semibold">Lecteurs réseau gérés</h3>
            <p class="text-sm opacity-70">
                Créez et assignez des répertoires réseau (lecteurs) par utilisateur, groupe ou parc.
            </p>
        </div>
        @can('manage-networkshare')
            <div class="flex gap-2 shrink-0">
                <button type="button" class="btn btn-outline" wire:click="openTemplate">
                    <i class="fa-solid fa-wand-magic-sparkles"></i>
                    Créer depuis un template
                </button>
                <button type="button" class="btn highlight btn-primary" wire:click="openCreate">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
                    </svg>
                    Nouveau répertoire
                </button>
            </div>
        @endcan
    </div>

    <div class="flex flex-col gap-4">
        <div class="min-w-48">
            <x-atoms.searchInput wire:model.live.debounce.500ms="search" id="searchInput"
                placeholder="Rechercher (par nom, répertoire...)" icon="fa-magnifying-glass" class="w-full" />
        </div>

        @if (count($shares) > 0)
            {{-- Barre d'actions groupées — visible dès qu'au moins un lecteur est coché. --}}
            @can('manage-networkshare')
                @if (count($selectedShares) > 0)
                    <div class="flex items-center justify-between gap-3 rounded-lg border border-base-300 bg-base-200 px-4 py-2">
                        <span class="text-sm font-medium">{{ count($selectedShares) }} lecteur(s) sélectionné(s)</span>
                        <button type="button" class="btn btn-error btn-sm" wire:click="openBulkDelete">
                            <i class="fa-solid fa-trash"></i>
                            Supprimer la sélection
                        </button>
                    </div>
                @endif
            @endcan

            {{-- Tableau aligné sur le style de l'app (cf. /app/users) : carte + table
                 zebra dans un conteneur scrollable horizontal, en-tête décompte. --}}
            <div class="card bg-base-100 shadow-sm overflow-hidden">
                <div class="px-4 py-3 border-b border-base-300 text-sm text-base-content/70">
                    {{ $totalShares }} répertoire(s) trouvé(s)
                </div>

                <div class="overflow-x-auto">
                    <table class="table table-zebra">
                        <thead>
                            <tr>
                                @can('manage-networkshare')
                                    <th class="w-12">
                                        <x-molecules.select-all-checkbox :ids="collect($shares)->pluck('id')" model="selectedShares" />
                                    </th>
                                @endcan
                                <th>Nom</th>
                                <th>Répertoire</th>
                                <th>Description</th>
                                <th>Lettre</th>
                                <th>Assignations</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($shares as $share)
                                <tr wire:key="share-row-{{ $share['id'] }}" class="hover:bg-sky-50 cursor-pointer"
                                    onclick="if (!event.target.closest('.checkbox-cell')) window.location.href='{{ route('admin.shares.show', $share['id']) }}'">
                                    @can('manage-networkshare')
                                        <td class="checkbox-cell p-0">
                                            <label class="flex items-center justify-center w-full h-full p-3 cursor-pointer">
                                                <input type="checkbox" class="checkbox"
                                                    wire:model.live="selectedShares" value="{{ $share['id'] }}">
                                            </label>
                                        </td>
                                    @endcan
                                    <td class="font-bold">{{ $share['name'] }}</td>
                                    <td><span class="font-mono text-sm">{{ $share['directory_name'] }}</span></td>
                                    <td>
                                        @if (!empty($share['description']))
                                            <span class="text-sm text-base-content/70 line-clamp-2" title="{{ $share['description'] }}">{{ $share['description'] }}</span>
                                        @else
                                            <span class="text-sm text-base-content/30">—</span>
                                        @endif
                                    </td>
                                    <td>
                                        @if ($share['letter'])
                                            <span class="badge badge-primary">{{ $share['letter'] }}</span>
                                        @else
                                            <span class="badge badge-ghost" title="Lettre attribuée automatiquement">auto</span>
                                        @endif
                                    </td>
                                    <td>
                                        @if ($share['total_assignments'] === 0)
                                            <span class="text-sm text-base-content/40">Aucune</span>
                                        @else
                                            <div class="flex flex-wrap gap-1 text-xs">
                                                @if ($share['users_count'] > 0)
                                                    <span class="badge badge-sm badge-accent">
                                                        <i class="fa-solid fa-user text-xs mr-1"></i>{{ $share['users_count'] }}
                                                    </span>
                                                @endif
                                                @if ($share['user_groups_count'] > 0)
                                                    <span class="badge badge-sm badge-secondary">
                                                        <i class="fa-solid fa-users text-xs mr-1"></i>{{ $share['user_groups_count'] }}
                                                    </span>
                                                @endif
                                                @if ($share['workstation_groups_count'] > 0)
                                                    <span class="badge badge-sm badge-warning" title="Parc — montage seul">
                                                        <i class="fa-solid fa-layer-group text-xs mr-1"></i>{{ $share['workstation_groups_count'] }}
                                                    </span>
                                                @endif
                                            </div>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

            @if ($pagination)
                <x-molecules.pagination :currentPage="$pagination['current_page']" :lastPage="$pagination['last_page']" :total="$pagination['total']" :from="$pagination['from']"
                    :to="$pagination['to']" :perPage="$perPage" :allowedPerPage="$allowedPerPage" onPageChange="goToPage"
                    perPageModel="perPage" itemLabel="répertoire" itemLabelPlural="répertoires" />
            @endif
        @else
            <div class="card bg-base-100 shadow-sm flex flex-col justify-center items-center">
                <div class="card-body flex-col justify-center items-center flex-0 py-16">
                    <i class="fa-solid fa-network-wired text-5xl opacity-30 mb-4"></i>
                    <h3 class="text-lg font-semibold mb-2">Aucun lecteur réseau</h3>
                    <p class="text-base-content/60 text-base mb-6">
                        {{ $hasFilters ? 'Aucun répertoire ne correspond à la recherche.' : "Aucun répertoire réseau n'est défini." }}
                    </p>
                    <div class="text-center">
                        @if ($hasFilters)
                            <button type="button" class="btn btn-outline" wire:click="resetFilters">Effacer la recherche</button>
                        @endif
                        @can('manage-networkshare')
                            <button type="button" class="btn highlight btn-primary ml-2" wire:click="openCreate">Nouveau répertoire</button>
                        @endcan
                    </div>
                </div>
            </div>
        @endif
    </div>

    {{-- Modale de création (réutilisable x-molecules.modal) --}}
    <x-molecules.modal wire:model="isCreateOpen" size="max-w-2xl" height="h-auto"
        title="Nouveau répertoire réseau" icon="fa-network-wired text-primary">
        <x-molecules.modal.section title="Informations" icon="fa-circle-info text-primary" dense>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="form-control">
                    <label class="label"><span class="label-text font-medium">Nom <span class="text-error">*</span></span></label>
                    <input type="text" wire:model="name" class="input input-bordered" placeholder="Échange Direction" />
                    @error('name') <span class="text-error text-xs mt-1">{{ $message }}</span> @enderror
                </div>
                <div class="form-control">
                    <label class="label"><span class="label-text font-medium">Nom de répertoire (FS) <span class="text-error">*</span></span></label>
                    <input type="text" wire:model="directoryName" class="input input-bordered font-mono" placeholder="echange_direction" />
                    @error('directoryName') <span class="text-error text-xs mt-1">{{ $message }}</span> @enderror
                </div>
                <div class="form-control">
                    <label class="label"><span class="label-text font-medium">Libellé du lecteur</span></label>
                    <input type="text" wire:model="label" class="input input-bordered" placeholder="(par défaut : le nom)" />
                    @error('label') <span class="text-error text-xs mt-1">{{ $message }}</span> @enderror
                </div>
                <div class="form-control">
                    <label class="label">
                        <span class="label-text font-medium">
                            Lettre
                            <span class="tooltip align-middle" data-tip="Laisser vide pour une attribution automatique (pool M..Z).">
                                <i class="fa-solid fa-circle-info text-base-content/40 ml-0.5"></i>
                            </span>
                        </span>
                    </label>
                    <input type="text" wire:model="letter" class="input input-bordered" maxlength="8" placeholder="P:" />
                    @error('letter') <span class="text-error text-xs mt-1">{{ $message }}</span> @enderror
                    <span class="text-xs text-base-content/50 mt-1">
                        Lettres réservées (indisponibles) : A-D, <strong>H</strong> (classes), I,
                        <strong>K</strong> (home), L. Laissez vide pour une attribution automatique (M..Z).
                    </span>
                </div>
            </div>
        </x-molecules.modal.section>

        <x-slot:footerNote>
            Le répertoire sera créé puis provisionné (FS + ACL). Les assignations (utilisateurs, groupes, parcs) se gèrent ensuite sur la page du répertoire.
        </x-slot:footerNote>
        <x-slot:footer>
            <button type="button" class="btn btn-ghost" wire:click="close">Annuler</button>
            <button type="button" class="btn btn-primary" wire:click="createShare" wire:loading.attr="disabled" wire:target="createShare">
                <span wire:loading.remove wire:target="createShare"><i class="fa-solid fa-plus"></i> Créer</span>
                <span wire:loading wire:target="createShare"><span class="loading loading-spinner loading-xs"></span> Création...</span>
            </button>
        </x-slot:footer>
    </x-molecules.modal>

    {{-- Modale « Créer depuis un template » (Story 34.3) --}}
    @php($selectedTpl = $this->selectedTemplate())
    @php($roleCandidates = $selectedTpl ? $this->roleCandidates() : [])
    @php($preview = $selectedTpl ? $this->templatePreview() : [])
    <x-molecules.modal wire:model="isTemplateOpen" size="max-w-3xl" height="h-auto"
        title="Créer un répertoire depuis un template" icon="fa-wand-magic-sparkles text-primary">

        <x-molecules.modal.section title="Template d'échange" icon="fa-layer-group text-primary" dense>
            <div class="form-control">
                <label class="label"><span class="label-text font-medium">Type d'échange <span class="text-error">*</span></span></label>
                <select wire:model.live="selectedTemplateKey" class="select select-bordered">
                    <option value="">— choisir un template —</option>
                    @foreach ($this->templates() as $tpl)
                        <option value="{{ $tpl['key'] }}">{{ $tpl['label'] }}</option>
                    @endforeach
                </select>
                @error('selectedTemplateKey') <span class="text-error text-xs mt-1">{{ $message }}</span> @enderror
            </div>
            @if ($selectedTpl && $selectedTpl->description)
                <div class="alert alert-info mt-3 text-sm">
                    <i class="fa-solid fa-circle-info"></i>
                    <span>{{ $selectedTpl->description }}</span>
                </div>
            @endif
        </x-molecules.modal.section>

        @if ($selectedTpl)
            <x-molecules.modal.section title="Informations" icon="fa-circle-info text-primary" dense>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="form-control">
                        <label class="label"><span class="label-text font-medium">Nom <span class="text-error">*</span></span></label>
                        <input type="text" wire:model="templateName" class="input input-bordered" placeholder="Devoirs 6eB" />
                        @error('templateName') <span class="text-error text-xs mt-1">{{ $message }}</span> @enderror
                    </div>
                    <div class="form-control">
                        <label class="label"><span class="label-text font-medium">Nom de répertoire (FS) <span class="text-error">*</span></span></label>
                        <input type="text" wire:model="templateDirectoryName" class="input input-bordered font-mono" placeholder="devoirs_6eb" />
                        @error('templateDirectoryName') <span class="text-error text-xs mt-1">{{ $message }}</span> @enderror
                    </div>
                    <div class="form-control">
                        <label class="label"><span class="label-text font-medium">Libellé du lecteur</span></label>
                        <input type="text" wire:model="templateLabel" class="input input-bordered" placeholder="(par défaut : le nom)" />
                        @error('templateLabel') <span class="text-error text-xs mt-1">{{ $message }}</span> @enderror
                    </div>
                    <div class="form-control">
                        <label class="label">
                            <span class="label-text font-medium">
                                Lettre
                                <span class="tooltip align-middle" data-tip="Laisser vide pour une attribution automatique (pool M..Z).">
                                    <i class="fa-solid fa-circle-info text-base-content/40 ml-0.5"></i>
                                </span>
                            </span>
                        </label>
                        <input type="text" wire:model="templateLetter" class="input input-bordered" maxlength="8" placeholder="P:" />
                        @error('templateLetter') <span class="text-error text-xs mt-1">{{ $message }}</span> @enderror
                    </div>
                </div>
            </x-molecules.modal.section>

            <x-molecules.modal.section title="Cibles du template" icon="fa-users text-primary" dense>
                <div class="space-y-3">
                    @foreach ($selectedTpl->roles() as $role)
                        @php($roleKey = $role['key'])
                        @php($isMany = ($role['cardinality'] ?? 'one') === 'many')
                        <div class="form-control">
                            <label class="label py-1">
                                <span class="label-text font-medium">
                                    {{ $role['label'] }}
                                    @if ($isMany)
                                        <span class="tooltip align-middle" data-tip="Maintenez Ctrl (ou Cmd) pour sélectionner plusieurs groupes.">
                                            <i class="fa-solid fa-circle-info text-base-content/40 ml-0.5"></i>
                                        </span>
                                    @endif
                                </span>
                                <span class="label-text-alt badge badge-sm {{ ($role['access'] ?? 'ro') === 'rw' ? 'badge-success' : 'badge-info' }}">
                                    {{ \App\Models\NetworkShareAssignable::accessLabel((string) ($role['access'] ?? 'ro')) }}
                                </span>
                            </label>
                            <select wire:model.live="roleSelections.{{ $roleKey }}" class="select select-bordered select-sm"
                                @if($isMany) multiple size="4" @endif>
                                @unless($isMany)
                                    <option value="">— choisir —</option>
                                @endunless
                                @foreach ($roleCandidates[$roleKey] ?? [] as $cand)
                                    <option value="{{ $cand['id'] }}">{{ $cand['label'] }}</option>
                                @endforeach
                            </select>
                        </div>
                    @endforeach
                </div>
            </x-molecules.modal.section>

            <x-molecules.modal.section title="Aperçu des assignations" icon="fa-list-check text-primary" dense>
                @if (count($preview) === 0)
                    <div class="text-sm text-base-content/50 py-2">Sélectionnez les cibles ci-dessus pour prévisualiser les assignations.</div>
                @else
                    <table class="table table-sm">
                        <thead>
                            <tr class="text-xs uppercase"><th>Cible</th><th>Maille</th><th>Accès</th></tr>
                        </thead>
                        <tbody>
                            @foreach ($preview as $row)
                                <tr>
                                    <td class="font-medium">{{ $row['label'] }}</td>
                                    <td class="text-xs text-base-content/60">{{ $row['maille'] }}</td>
                                    <td>
                                        <span class="badge badge-sm {{ $row['access'] === 'Lecture/écriture' ? 'badge-success' : 'badge-info' }}">{{ $row['access'] }}</span>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
                <div class="alert alert-warning mt-3 text-xs">
                    <i class="fa-solid fa-triangle-exclamation"></i>
                    <span>Les accès portent sur des utilisateurs et groupes d'utilisateurs (jamais sur un parc — un parc ne donnerait que la visibilité, sans accès réel). Le répertoire est un dépôt partagé : il n'y a pas de cloisonnement par utilisateur (casiers individuels = à venir).</span>
                </div>
            </x-molecules.modal.section>
        @endif

        <x-slot:footerNote>
            Le répertoire et toutes ses assignations seront créés puis provisionnés (FS + ACL). Vous pourrez ensuite l'éditer depuis sa page.
        </x-slot:footerNote>
        <x-slot:footer>
            <button type="button" class="btn btn-ghost" wire:click="closeTemplate">Annuler</button>
            <button type="button" class="btn btn-primary" wire:click="createFromTemplate" wire:loading.attr="disabled" wire:target="createFromTemplate"
                @disabled(! $selectedTpl)>
                <span wire:loading.remove wire:target="createFromTemplate"><i class="fa-solid fa-wand-magic-sparkles"></i> Matérialiser</span>
                <span wire:loading wire:target="createFromTemplate"><span class="loading loading-spinner loading-xs"></span> Création...</span>
            </button>
        </x-slot:footer>
    </x-molecules.modal>

    {{-- Modale de confirmation — suppression groupée (garde-fou destructif :
         saisie exacte requise ; informe que les fichiers sont archivés, pas détruits). --}}
    <x-molecules.modal wire:model="isBulkDeleteOpen" size="max-w-lg" height="h-auto"
        title="Supprimer des lecteurs réseau" icon="fa-triangle-exclamation text-error">
        <div class="space-y-4">
            <div class="alert alert-warning">
                <i class="fa-solid fa-triangle-exclamation"></i>
                <div class="text-sm">
                    <p class="font-semibold">Révoque l'accès et archive les dossiers.</p>
                    <p>
                        Les fichiers <strong>ne sont pas détruits</strong> : chaque dossier est déplacé dans une
                        corbeille serveur (<code>Partages/.trash/</code>) et n'est plus accessible aux utilisateurs.
                        Les assignations sont supprimées.
                    </p>
                </div>
            </div>

            <div>
                <p class="text-sm font-medium mb-2">{{ count($this->selectedShareRows) }} lecteur(s) à supprimer :</p>
                <ul class="max-h-40 overflow-y-auto rounded-lg border border-base-300 divide-y divide-base-200">
                    @foreach ($this->selectedShareRows as $row)
                        <li class="flex items-center justify-between px-3 py-2 text-sm">
                            <span class="font-medium">{{ $row['name'] }}</span>
                            @if ($row['letter'])
                                <span class="badge badge-sm badge-primary">{{ $row['letter'] }}</span>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </div>

            <div class="form-control">
                <label class="label">
                    <span class="label-text text-sm">
                        Pour confirmer, saisissez
                        <code class="px-1 font-semibold text-error">{{ $this->bulkConfirmTarget }}</code>
                        @if (count($this->selectedShareRows) === 1)<span class="opacity-60">(le nom du lecteur)</span>@endif
                    </span>
                </label>
                <input type="text" wire:model.live="deleteConfirmation" class="input input-bordered"
                    autocomplete="off" placeholder="{{ $this->bulkConfirmTarget }}" />
            </div>
        </div>

        <x-slot:footer>
            <button type="button" class="btn btn-ghost" wire:click="$set('isBulkDeleteOpen', false)">Annuler</button>
            <button type="button" class="btn btn-error"
                wire:click="bulkDelete" wire:loading.attr="disabled" wire:target="bulkDelete"
                @disabled(trim($deleteConfirmation) !== $this->bulkConfirmTarget)>
                <span wire:loading.remove wire:target="bulkDelete"><i class="fa-solid fa-trash"></i> Supprimer les lecteurs</span>
                <span wire:loading wire:target="bulkDelete"><span class="loading loading-spinner loading-xs"></span> Suppression...</span>
            </button>
        </x-slot:footer>
    </x-molecules.modal>
</div>

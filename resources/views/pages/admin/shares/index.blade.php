<?php

use App\Components\Traits\WithToasts;
use App\Enums\RoleResolutionStrategy;
use App\Exceptions\Filesystem\NetworkShareLetterCollisionException;
use App\Models\DirectoryTemplate;
use App\Models\NetworkShare;
use App\Models\User;
use App\Models\UserGroup;
use App\Services\Filesystem\DirectoryTemplateService;
use App\Services\Filesystem\NetworkShareService;
use App\Services\Filesystem\NetworkShareValidator;
use App\Services\Filesystem\Plan\PlanGrant;
use App\Support\RoleCatalog;
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
    /**
     * Story 61.3 — L'AUTORITÉ D'ÉCRITURE du répertoire créé.
     *
     * Elle n'est proposée que parmi les backends POSABLES ({@see FileBackendSelection}) :
     * une case dont la capacité est éteinte est ABSENTE, avec son motif dit sous le
     * champ — pas grisée sans explication. Sa valeur d'ouverture suit la décision
     * d'instance ({@see self::defaultBackendValue()}) : le cas ordinaire est de
     * créer là où l'instance a décidé d'écrire.
     */
    public string $createBackend = 'posix';
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

    /**
     * Story 60.5 — le GROUPE DE MATÉRIALISATION d'une recette auto-résolvable :
     * l'unique chose qu'elle demande, puisqu'elle déduit tout le reste.
     */
    public ?int $materializationGroupId = null;

    /** Clé du sélecteur de groupe de matérialisation dans les messages d'aide. */
    public const MATERIALIZATION_PICKER = '@groupe';

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
                // Story 60.3 — l'autorité d'écriture des droits, en libellé (jamais
                // la valeur technique brute à l'écran). Lecture SANCTIONNÉE de la
                // colonne : une valeur hors vocabulaire échoue explicitement plutôt
                // que de s'afficher au hasard.
                'backend_label' => $s->backendName()->label(),
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
        $this->createBackend = $this->defaultBackendValue();
        $this->resetErrorBag();
    }

    /**
     * Le défaut proposé : l'autorité décidée par l'instance pour l'espace partagé.
     *
     * Repli sur `posix` si cette autorité est devenue non posable — proposer une
     * case que la validation refusera ferait tourner l'exploitant en rond.
     */
    private function defaultBackendValue(): string
    {
        $authority = \App\Services\Filesystem\FileLocationService::current()->espacePartage;

        return app(\App\Services\Filesystem\Backend\FileBackendSelection::class)->refusalFor($authority) === null
            ? $authority->value
            : \App\Enums\FileBackendName::Posix->value;
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

        // Story 61.3 — l'autorité d'écriture est refusée AVANT écriture si elle
        // n'est pas posable. La garde est rejouée ici même si l'écran a déjà filtré
        // la liste : une garde qui ne vit que dans la liste affichée protège
        // l'étourderie, pas la requête forgée.
        try {
            $backend = app(\App\Services\Filesystem\Backend\FileBackendSelection::class)->resolve($this->createBackend);
        } catch (\InvalidArgumentException $e) {
            $this->toastError($e->getMessage());

            return;
        }

        $share = new NetworkShare([
            'name' => $validated['name'],
            'directory_name' => $validated['directoryName'],
            'label' => $validated['label'] !== '' ? $validated['label'] : null,
            'letter' => $this->normalizedLetter($validated['letter'] ?? null),
            'created_by_user_id' => $this->currentUserId(),
        ]);

        // Hors `$fillable` à dessein : ce geste de création est le SEUL écrivain de
        // la colonne, et c'est ce qui rend vraie la promesse « un répertoire
        // provisionné ne bascule jamais ».
        $share->backend = $backend;

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

        // Story 60.4 — la mise en place des droits est ENFILÉE (elle est quadratique
        // en nombre d'entrées nominatives et n'a rien à faire dans le cycle d'une
        // requête). L'écran dit « engagée », jamais « accomplie ».
        if (app(NetworkShareService::class)->queueReconciliation($share)) {
            $this->toastSuccess("Le répertoire « {$share->name} » a été créé. La mise en place des droits est engagée.");
        } else {
            $this->toastWarning("Le répertoire « {$share->name} » a été créé, mais la réconciliation des droits n'a pas pu être engagée. "
                . 'Consultez les journaux serveur.');
        }

        $this->isCreateOpen = false;
        $this->resetCreateForm();
        $this->loadShares();
    }

    /**
     * Story 61.3 — LES AUTORITÉS D'ÉCRITURE POSABLES, et le motif de celles qui ne
     * le sont pas.
     *
     * **Une case non posable est ABSENTE de la liste**, jamais grisée sans mot :
     * proposer puis refuser à l'application est le défaut du signal accepté sans
     * destinataire, que tout cet epic combat. Le motif, lui, est DIT sous le champ —
     * l'administrateur doit savoir quoi activer, pas seulement que c'est indisponible.
     *
     * ---------------------------------------------------------------------------
     * **CE BLOC ÉNUMÉRAIT UNE CASE ; IL ITÈRE DÉSORMAIS, et c'est un CONSTAT.**
     *
     * La liste des motifs de refus nommait `Nextcloud` en dur — la seule autorité
     * refusable au moment où elle a été écrite. Le contrat de backend, lui, a tenu
     * sans bouger à l'arrivée d'un troisième produit ; **cet écran, non** : sa case
     * serait restée absente avec son motif TU, c'est-à-dire exactement le défaut
     * que le paragraphe ci-dessus décrit.
     *
     * La liste est donc dérivée du VOCABULAIRE, comme la liste des options l'était
     * déjà. Aucune case n'est nommée ici, et la prochaine n'aura rien à y ajouter.
     * Le backend d'aperçu est écarté : son refus (« il n'écrit aucun droit ») n'est
     * pas un réglage à activer, c'est sa nature — l'afficher enverrait
     * l'administrateur chercher un interrupteur qui n'existe pas.
     * ---------------------------------------------------------------------------
     *
     * @return array{options: list<array{value:string,label:string,description:string}>, refusals: list<string>}
     */
    public function backendChoice(): array
    {
        $selection = app(\App\Services\Filesystem\Backend\FileBackendSelection::class);

        $options = [];
        foreach ($selection->selectable() as $name) {
            $options[] = [
                'value' => $name->value,
                'label' => $name->label(),
                'description' => $name->description(),
            ];
        }

        $refusals = [];
        foreach (\App\Enums\FileBackendName::cases() as $candidate) {
            if ($candidate === \App\Enums\FileBackendName::Preview) {
                continue;
            }
            $refusal = $selection->refusalFor($candidate);
            if ($refusal !== null) {
                $refusals[] = $refusal;
            }
        }

        return ['options' => $options, 'refusals' => $refusals];
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
            // Story 60.5 — LES RECETTES QUI SE MATÉRIALISENT SEULES NE SONT PAS
            // PROPOSÉES ICI. Cet écran fait naître un partage à partir d'un nom
            // choisi à la main ; une recette d'arbre, elle, tient son nom et son
            // emplacement de son groupe. Les mélanger produisait deux issues,
            // toutes deux fausses : l'arbre existe déjà et l'unicité de la ligne
            // casse en erreur non rattrapée (une page 500 au lieu d'un message),
            // ou il n'existe pas encore et l'écran fabrique un partage au nom
            // arbitraire ET AVEC UNE LETTRE, alors qu'un arbre naît sans lettre.
            // Le sélecteur ne propose donc que ce qu'il sait faire naître.
            ->reject(fn (DirectoryTemplate $t): bool => $t->materializesOnGroupCreation())
            ->map(fn (DirectoryTemplate $t): array => [
                'key' => $t->key,
                'label' => $t->label,
                'description' => $t->description,
                'roles' => $t->roles(),
            ])->values()->all();
    }

    /**
     * Recette sélectionnée (modèle), ou null.
     *
     * La clé arrive du navigateur : filtrer le catalogue rendu ne suffit pas, il
     * faut refuser la recette ici aussi. Une garde qui ne vit que dans la liste
     * affichée protège l'étourderie, pas la requête forgée.
     */
    public function selectedTemplate(): ?DirectoryTemplate
    {
        if ($this->selectedTemplateKey === '') {
            return null;
        }

        $template = DirectoryTemplate::where('key', $this->selectedTemplateKey)->first();

        return $template?->materializesOnGroupCreation() ? null : $template;
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

        // Story 60.5 — une recette AUTO-RÉSOLVABLE ne demande aucune cible par
        // rôle : elle les déduit toutes d'un seul groupe. Lui présenter des
        // sélecteurs de rôle serait demander une saisie qui ne sert à rien — et,
        // pour un rôle porté par une arête, une saisie IMPOSSIBLE.
        if ($this->isAutoResolvable()) {
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
     * Story 62.4 — LE LIBELLÉ D'UN RÔLE DE RECETTE, désormais dérivé de ses VERBES.
     *
     * La recette dit quatre verbes ; l'assignation qui en naîtra n'en connaît que
     * deux niveaux. L'aperçu montre donc ce que l'administrateur obtiendra
     * VRAIMENT — le niveau d'assignation — dérivé par exactement la même règle que
     * la matérialisation ({@see \App\Services\Filesystem\DirectoryTemplateService}) :
     * est « Modifier » tout ce qui MUTE. Ce n'est pas une interface nouvelle,
     * c'est le même badge qu'hier, lu dans le vocabulaire d'aujourd'hui.
     *
     * @param  array<string, mixed>  $role
     */
    private static function roleAccessLabel(array $role): string
    {
        $verbs = is_array($role['verbs'] ?? null) ? $role['verbs'] : [];
        $access = array_intersect(PlanGrant::MUTATION_VERBS, $verbs) !== []
            ? \App\Models\NetworkShareAssignable::ACCESS_RW
            : \App\Models\NetworkShareAssignable::ACCESS_RO;

        return \App\Models\NetworkShareAssignable::accessLabel($access);
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

        if ($this->isAutoResolvable()) {
            return $this->autoResolvedPreview($template);
        }

        $candidates = $this->roleCandidates();
        $preview = [];

        foreach ($template->roles() as $role) {
            $roleKey = (string) ($role['key'] ?? '');
            $maille = (string) ($role['maille'] ?? '');
            $access = self::roleAccessLabel($role);
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

    // =========================================================================
    // Story 60.5 — le flux à UN SEUL sélecteur, et le picker qui PARLE
    // =========================================================================

    /**
     * `true` si la recette choisie sait trouver toutes ses cibles à partir d'un
     * seul groupe.
     *
     * C'est ce qui répare « profs → élèves » : son rôle enseignant désigne « les
     * membres de la classe qui portent le rôle d'encadrement », audience qu'AUCUN
     * sélecteur de groupe ne pourra jamais désigner — elle n'est pas un groupe.
     */
    public function isAutoResolvable(): bool
    {
        $template = $this->selectedTemplate();

        if ($template === null || $template->attachedGroupType() === null) {
            return false;
        }

        try {
            return $template->isAutoResolvable();
        } catch (\Throwable) {
            // Une recette dont la règle de résolution est illisible ne doit pas
            // faire tomber l'écran : elle retombe sur le flux à cibles saisies,
            // qui échouera explicitement à la matérialisation.
            return false;
        }
    }

    /**
     * Groupes éligibles comme GROUPE DE MATÉRIALISATION de la recette choisie.
     *
     * @return array<int, array{id:int,label:string}>
     */
    public function materializationCandidates(): array
    {
        $template = $this->selectedTemplate();
        $type = $template?->attachedGroupType();

        if ($type === null || ! $this->isAutoResolvable()) {
            return [];
        }

        return UserGroup::query()
            ->whereRaw('LOWER(type) = ?', [mb_strtolower($type)])
            ->orderBy('name')
            ->limit(500)
            ->get(['id', 'name', 'display_name'])
            ->map(fn (UserGroup $g): array => ['id' => (int) $g->id, 'label' => (string) ($g->display_name ?: $g->name)])
            ->all();
    }

    /**
     * **UN SÉLECTEUR VIDE DOIT LE DIRE.**
     *
     * C'est le silence qui a rendu « profs → élèves » inutilisable pendant cinq
     * semaines : la recette contraignait un type de groupe que l'import d'annuaire
     * ne produit plus, le sélecteur s'affichait vide, et rien — ni message, ni
     * journal, ni bouton désactivé — ne disait pourquoi. La garde vaut pour TOUTES
     * les recettes, pas seulement pour celle qui a révélé le défaut.
     *
     * @return array<string, string> clé de rôle (ou clé du groupe de matérialisation) => message
     */
    public function emptyPickerNotices(): array
    {
        $template = $this->selectedTemplate();
        if ($template === null) {
            return [];
        }

        if ($this->isAutoResolvable()) {
            if ($this->materializationCandidates() !== []) {
                return [];
            }

            return [self::MATERIALIZATION_PICKER => sprintf(
                'Aucun groupe éligible : cette recette se matérialise à partir d\'un groupe de type '
                . '« %s », et aucun n\'existe sur cette instance.',
                (string) $template->attachedGroupType(),
            )];
        }

        $notices = [];
        foreach ($this->roleCandidates() as $roleKey => $candidates) {
            if ($candidates !== []) {
                continue;
            }

            $role = $template->role((string) $roleKey);
            $groupType = $role['group_type'] ?? null;

            $notices[(string) $roleKey] = is_string($groupType) && $groupType !== ''
                ? sprintf(
                    'Aucun groupe éligible pour ce rôle : il attend un groupe de type « %s », et aucun '
                    . 'n\'existe sur cette instance.',
                    $groupType,
                )
                : 'Aucun candidat éligible pour ce rôle sur cette instance.';
        }

        return $notices;
    }

    /**
     * `true` si la matérialisation est IMPOSSIBLE faute de candidats — le bouton
     * est alors désactivé, plutôt que d'offrir un geste qui échouera.
     */
    public function materializationBlocked(): bool
    {
        return $this->selectedTemplate() !== null && $this->emptyPickerNotices() !== [];
    }

    /**
     * Aperçu d'une recette auto-résolvable : les AUDIENCES telles qu'elles seront
     * résolues, à partir du groupe choisi.
     *
     * Il dit ce que la recette va faire, pas ce que l'administrateur a saisi — et
     * c'est précisément ce qu'on veut voir avant de matérialiser une recette dont
     * les cibles se déduisent.
     *
     * @return array<int, array{label:string,maille:string,access:string}>
     */
    private function autoResolvedPreview(DirectoryTemplate $template): array
    {
        $groupId = (int) $this->materializationGroupId;
        if ($groupId <= 0) {
            return [];
        }

        $group = UserGroup::find($groupId);
        if ($group === null) {
            return [];
        }

        $groupLabel = (string) ($group->display_name ?: $group->name);
        $preview = [];

        foreach ($template->roles() as $role) {
            $roleKey = (string) ($role['key'] ?? '');
            $access = self::roleAccessLabel($role);

            try {
                $resolution = $template->resolutionOf($role);
            } catch (\Throwable) {
                continue;
            }

            $audience = match ($resolution['strategy']) {
                RoleResolutionStrategy::Itself => $groupLabel,
                RoleResolutionStrategy::EdgeRole => sprintf(
                    '%s — %s',
                    $groupLabel,
                    // Story 62.3 — l'aperçu d'audience lit le VOCABULAIRE DÉCLARÉ
                    // du type de groupe, plus un `match` local.
                    //
                    // Ce `match` était le dernier survivant du dépôt, et il
                    // mentait deux fois : « membres » était son `default`, donc un
                    // rôle personnalisé (`tuteur`, créé au catalogue de 62.1) y
                    // était rendu « membres » comme n'importe quoi d'autre ; et il
                    // ignorait le type du groupe alors que `$group` est juste
                    // au-dessus. Une recette accrochée à une classe annonçait
                    // « encadrants » là où tous les autres écrans disaient
                    // « Enseignant ».
                    //
                    // DIVERGENCE NOMMÉE ET ASSUMÉE : l'aperçu affiche désormais
                    // « 3A — Enseignant » au lieu de « 3A — encadrants ». C'est le
                    // libellé que l'administrateur voit partout ailleurs, et celui
                    // qu'il peut renommer.
                    implode(', ', array_map(
                        fn (string $edgeRole): string => RoleCatalog::label($group->type, $edgeRole),
                        $resolution['edge_roles'],
                    )),
                ),
                default => $groupLabel,
            };

            $preview[] = [
                'label' => $audience,
                'maille' => (string) ($role['label'] ?? $roleKey),
                'access' => $access,
            ];
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
        // exposés changent dynamiquement) ET le groupe de matérialisation, dont le
        // type éligible change avec la recette.
        $this->roleSelections = [];
        $this->materializationGroupId = null;
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
        $this->materializationGroupId = null;
        $this->createBackend = $this->defaultBackendValue();
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

        // Story 60.5 — un sélecteur SANS candidat ne doit jamais laisser lancer un
        // geste qui échouera : le bouton est déjà désactivé à l'écran, la garde est
        // rejouée ici parce qu'un composant peut être appelé sans passer par lui.
        if ($this->materializationBlocked()) {
            $this->toastError(implode(' ', $this->emptyPickerNotices()));

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
                'group_id' => $this->materializationGroupId,
                'backend' => $this->createBackend,
            ], deferProvisioning: true);
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
        } catch (\Illuminate\Database\QueryException $e) {
            // Un partage EXISTE DÉJÀ pour ce couple recette/groupe : l'unicité de
            // la ligne le dit, et l'administrateur doit l'apprendre par un message,
            // pas par une page d'erreur. La cause reste au journal — l'écran ne
            // rend jamais un message de base de données.
            Log::warning('Shares: matérialisation refusée par la base', [
                'template' => $this->selectedTemplateKey,
                'group_id' => $this->materializationGroupId,
                'error' => $e->getMessage(),
            ]);
            $this->toastError(
                'Ce répertoire existe déjà pour cette recette et ce groupe : aucune création n\'a eu lieu.'
            );

            return;
        }

        // Story 60.4 — la pose des droits est ENFILÉE : le message dit « engagée »,
        // pas « accomplie ». Affirmer l'accompli serait le mensonge que la ligne de
        // contrat combat un cran plus bas.
        $message = $result->isFailure()
            ? "Le répertoire « {$result->share->name} » a été créé, mais la réconciliation des droits n'a pas pu être engagée. Consultez les journaux serveur."
            : "Le répertoire « {$result->share->name} » a été créé depuis le template. La mise en place des droits est engagée : l'état sera à jour au prochain rafraîchissement.";

        // Surfaçage des avertissements prédictifs non bloquants (WG-montage-seul,
        // AC2). Inerte pour les 4 recettes seedées (aucune n'assigne de parc),
        // mais on honore le contrat et on défend les recettes futures : un warning
        // bascule le toast en statut « warning » et l'annexe au message.
        $warnings = $result->warnings;
        if ($warnings !== []) {
            $message .= ' ⚠ ' . implode(' ', $warnings);
        }

        session()->flash('toast', [
            'status' => (! $result->isFailure() && $warnings === []) ? 'success' : 'warning',
            'title' => $result->isFailure()
                ? 'Réconciliation non engagée'
                : ($warnings === [] ? 'Répertoire créé' : 'Répertoire créé (avec avertissements)'),
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
                                <th>Backend</th>
                                <th>Assignations</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($shares as $share)
                                <tr wire:key="share-row-{{ $share['id'] }}" class="cursor-pointer"
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
                                    {{-- Story 60.3 — backend VISIBLE, jamais éditable ici. --}}
                                    <td>
                                        <span class="badge badge-outline badge-sm">{{ $share['backend_label'] }}</span>
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
    @php($backendChoice = $this->backendChoice())
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

                {{-- Story 61.3 — L'AUTORITÉ D'ÉCRITURE : choisie ICI, jamais après (D9). --}}
                <div class="flex flex-col w-full md:col-span-2">
                    <label class="label w-full justify-start" for="create-backend">
                        <span class="label-text font-medium">
                            Autorité d'écriture des droits <span class="text-error">*</span>
                            <span class="tooltip align-middle" data-tip="Ce choix est définitif : un répertoire déjà créé ne change pas d'autorité.">
                                <i class="fa-solid fa-circle-info text-base-content/40 ml-0.5"></i>
                            </span>
                        </span>
                    </label>
                    <select id="create-backend" wire:model.live="createBackend" class="select select-bordered w-full">
                        @foreach ($backendChoice['options'] as $option)
                            <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                        @endforeach
                    </select>
                    @foreach ($backendChoice['options'] as $option)
                        @if ($option['value'] === $createBackend)
                            <span class="text-xs text-base-content/60 mt-1">{{ $option['description'] }}</span>
                        @endif
                    @endforeach
                    @foreach ($backendChoice['refusals'] as $refusal)
                        <span class="text-xs text-warning mt-1"><i class="fa-solid fa-triangle-exclamation"></i> {{ $refusal }}</span>
                    @endforeach
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
    @php($emptyPickers = $selectedTpl ? $this->emptyPickerNotices() : [])
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

                    {{-- Story 61.3 — même choix, même garde, même finalité (D9). --}}
                    <div class="flex flex-col w-full md:col-span-2">
                        <label class="label w-full justify-start" for="template-backend">
                            <span class="label-text font-medium">
                                Autorité d'écriture des droits <span class="text-error">*</span>
                                <span class="tooltip align-middle" data-tip="Ce choix est définitif : un répertoire déjà créé ne change pas d'autorité.">
                                    <i class="fa-solid fa-circle-info text-base-content/40 ml-0.5"></i>
                                </span>
                            </span>
                        </label>
                        <select id="template-backend" wire:model.live="createBackend" class="select select-bordered w-full">
                            @foreach ($backendChoice['options'] as $option)
                                <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                            @endforeach
                        </select>
                        @foreach ($backendChoice['options'] as $option)
                            @if ($option['value'] === $createBackend)
                                <span class="text-xs text-base-content/60 mt-1">{{ $option['description'] }}</span>
                            @endif
                        @endforeach
                        @foreach ($backendChoice['refusals'] as $refusal)
                            <span class="text-xs text-warning mt-1"><i class="fa-solid fa-triangle-exclamation"></i> {{ $refusal }}</span>
                        @endforeach
                    </div>
                </div>
            </x-molecules.modal.section>

            {{-- Story 60.5 — le flux à UN SEUL sélecteur : une recette auto-résolvable
                 déduit toutes ses cibles du groupe choisi. C'est ce qui répare
                 « profs → élèves », dont le rôle enseignant désigne une audience
                 qu'aucun sélecteur de groupe ne pourrait jamais nommer. --}}
            @if ($this->isAutoResolvable())
                <x-molecules.modal.section title="Groupe de matérialisation" icon="fa-users text-primary" dense>
                    <div class="form-control">
                        <label class="label py-1">
                            <span class="label-text font-medium">
                                Groupe <span class="text-error">*</span>
                            </span>
                        </label>
                        <select wire:model.live="materializationGroupId" class="select select-bordered select-sm"
                            @disabled($emptyPickers !== [])>
                            <option value="">— choisir —</option>
                            @foreach ($this->materializationCandidates() as $cand)
                                <option value="{{ $cand['id'] }}">{{ $cand['label'] }}</option>
                            @endforeach
                        </select>
                        @if (isset($emptyPickers['@groupe']))
                            <div class="alert alert-warning mt-2 text-xs" data-empty-picker="@groupe">
                                <i class="fa-solid fa-triangle-exclamation"></i>
                                <span>{{ $emptyPickers['@groupe'] }}</span>
                            </div>
                        @endif
                    </div>
                </x-molecules.modal.section>
            @else
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
                                @php($roleMutates = array_intersect(\App\Services\Filesystem\Plan\PlanGrant::MUTATION_VERBS, (array) ($role['verbs'] ?? [])) !== [])
                                <span class="label-text-alt badge badge-sm {{ $roleMutates ? 'badge-success' : 'badge-info' }}">
                                    {{ \App\Models\NetworkShareAssignable::accessLabel($roleMutates ? 'rw' : 'ro') }}
                                </span>
                            </label>
                            <select wire:model.live="roleSelections.{{ $roleKey }}" class="select select-bordered select-sm"
                                @if($isMany) multiple size="4" @endif
                                @disabled(isset($emptyPickers[$roleKey]))>
                                @unless($isMany)
                                    <option value="">— choisir —</option>
                                @endunless
                                @foreach ($roleCandidates[$roleKey] ?? [] as $cand)
                                    <option value="{{ $cand['id'] }}">{{ $cand['label'] }}</option>
                                @endforeach
                            </select>
                            {{-- **UN SÉLECTEUR VIDE DOIT LE DIRE.** C'est le silence qui a
                                 rendu une recette inutilisable pendant cinq semaines. --}}
                            @if (isset($emptyPickers[$roleKey]))
                                <div class="alert alert-warning mt-2 text-xs" data-empty-picker="{{ $roleKey }}">
                                    <i class="fa-solid fa-triangle-exclamation"></i>
                                    <span>{{ $emptyPickers[$roleKey] }}</span>
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
            </x-molecules.modal.section>
            @endif

            <x-molecules.modal.section title="Aperçu des accès" icon="fa-list-check text-primary" dense>
                @if (count($preview) === 0)
                    <div class="text-sm text-base-content/50 py-2">
                        {{ $this->isAutoResolvable()
                            ? 'Choisissez le groupe ci-dessus pour voir les accès qui en seront déduits.'
                            : 'Sélectionnez les cibles ci-dessus pour prévisualiser les assignations.' }}
                    </div>
                @else
                    <table class="table table-sm" data-template-preview>
                        <thead>
                            <tr class="text-xs uppercase"><th>Destinataires</th><th>Rôle</th><th>Accès</th></tr>
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
            </x-molecules.modal.section>
        @endif

        <x-slot:footerNote>
            Le répertoire et toutes ses assignations seront créés puis provisionnés (FS + ACL). Vous pourrez ensuite l'éditer depuis sa page.
        </x-slot:footerNote>
        <x-slot:footer>
            <button type="button" class="btn btn-ghost" wire:click="closeTemplate">Annuler</button>
            {{-- Story 60.5 — un sélecteur sans candidat DÉSACTIVE la matérialisation :
                 offrir un geste dont on sait qu'il échouera est une autre forme du
                 silence qu'on répare ici. --}}
            <button type="button" class="btn btn-primary" wire:click="createFromTemplate" wire:loading.attr="disabled" wire:target="createFromTemplate"
                @disabled(! $selectedTpl || $emptyPickers !== [])>
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

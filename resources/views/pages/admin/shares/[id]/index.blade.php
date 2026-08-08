<?php

use App\Components\Traits\WithToasts;
use App\Exceptions\Filesystem\NetworkShareLetterCollisionException;
use App\Models\NetworkShare;
use App\Models\NetworkShareAssignable;
use App\Models\User;
use App\Models\UserGroup;
use App\Models\WorkstationGroup;
use App\Enums\FileBackendName;
use App\Enums\FileBackendOutcome;
use App\Enums\PlanNodeNature;
use App\Services\Filesystem\Backend\FileBackendRegistry;
use App\Services\Filesystem\Backend\ReconciliationReport;
use App\Services\Filesystem\NetworkShareService;
use App\Services\Filesystem\NetworkShareValidator;
use App\Services\Filesystem\PlanStateComparator;
use App\Services\Filesystem\Plan\FilePlan;
use App\Services\Filesystem\Plan\PlanNode;
use App\Services\Filesystem\Plan\PlanSubject;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Story 34.2 — Détail d'un lecteur réseau géré : édition des champs + assignation
 * par maille (pivot SQL pur) + suppression. Validation prédictive (collision de
 * lettre, WG-montage-seul) calquée sur 30.5.
 */
new #[Title('Lecteur réseau - Instance SE4FS')] class extends Component {
    use WithToasts;

    public int $id;
    public ?NetworkShare $share = null;

    // Édition des champs.
    public string $name = '';
    public string $directoryName = '';
    public string $label = '';
    public string $description = '';
    public string $letter = '';

    // Formulaire d'ajout d'assignation.
    public string $assignType = User::class;
    public string $assignSearch = '';
    public ?int $assignTargetId = null;
    public string $assignAccess = 'ro';

    // Modale d'ajout d'assignation (recherche dynamique de la cible).
    public bool $isAssignOpen = false;

    // Édition de l'identité : header en lecture seule par défaut, formulaire à la demande.
    public bool $editingDetails = false;

    // Story 60.4 — écart entre le PLAN (base autoritaire) et l'état RELU, dit en
    // vocabulaire de plan : par nœud, par sujet, l'attendu et le constaté. Plus
    // aucune ligne de permission brute n'arrive ici. Rafraîchi au montage et à la
    // demande — pas un calcul à chaque rendu (il relit le serveur).
    // `null` = non calculé.
    public ?array $drift = null;

    // Story 60.4 — la réconciliation déclenchée depuis cet écran est ENFILÉE.
    // L'écran le DIT, et s'arrête là : pas d'interrogation périodique, pas de
    // diffusion d'événement, pas d'indicateur de progression. Un seul geste passe
    // par ici et la boucle de rétroaction existe déjà — l'encart de conformité
    // relit l'état à la demande. Une machinerie de suivi serait de
    // l'infrastructure construite avant son besoin.
    public bool $reconciliationEngaged = false;

    /**
     * Raison du dernier échec de préparation d'une réconciliation enfilée, ou
     * `null`. Survit au rafraîchissement (elle vient du serveur), contrairement
     * à l'état « engagé » ci-dessus qui n'est vrai que dans le cycle du clic.
     */
    public ?string $reconciliationFailure = null;

    // Story 60.3 — le backend est une propriété VISIBLE du partage (il détermine
    // le chemin d'accès de l'utilisateur), et volontairement NON ÉDITABLE : tant
    // qu'aucun flux ne route par la colonne, un sélecteur serait une propriété qui
    // ment. Éditabilité et routage arrivent ensemble en 60.4.
    public string $backendLabel = '';
    public string $backendDescription = '';

    // Aperçu du plan AVANT application. `null` tant qu'il n'a pas été demandé —
    // on ne projette pas un plan à chaque rendu de page.
    public bool $isPlanPreviewOpen = false;
    public ?array $planPreview = null;

    /**
     * Story 60.5 — l'ORIGINE du partage : la recette dont il est la
     * matérialisation, le groupe qu'il cloisonne, et l'emplacement RÉEL de sa
     * racine côté serveur. `null` pour un partage ordinaire, qui n'a pas d'origine.
     *
     * L'emplacement vient du BACKEND, par le contrat : l'orchestrateur ne connaît
     * qu'une zone logique et un chemin relatif, et c'est ce qui le rend portable.
     * C'est aussi le chemin que la liste blanche du système doit couvrir — la
     * première chose qu'on vérifie quand toute une matérialisation décline.
     *
     * @var array{template:string,group:string,location:?string}|null
     */
    public ?array $treeOrigin = null;

    /**
     * Story 60.5 — les nœuds ACTIVABLES du plan, avec leur état.
     *
     * Une entrée ABSENTE de la donnée d'activation vaut ACTIF : c'est la décision
     * de l'espace d'échange historique, créé actif. Suspendre VIDE l'octroi
     * suspendable ; le dossier et les données restent — ce n'est jamais une
     * suppression, et rien à l'écran ne doit laisser croire le contraire.
     *
     * @var list<array{path:string,label:string,active:bool}>
     */
    public array $activableNodes = [];

    /**
     * Story 60.5 — le DERNIER RAPPORT de réconciliation, par nœud.
     *
     * Un partage plat a un nœud : « ça a marché ou pas » suffisait. Un arbre en a
     * quatre plus un par élève, et la question de l'administrateur devient
     * « LEQUEL a échoué ». C'est l'arbre qui donne enfin son audience à ce rapport,
     * écrit et testé depuis la story 60.4 mais rendu nulle part.
     *
     * Il arrive en TABLEAU : un rapport ne se reconstruit pas sans repasser par sa
     * fabrique et son plan, et pour l'AFFICHER le tableau suffit.
     *
     * @var array{backend:string,nodes:list<array<string,mixed>>}|null
     */
    public ?array $lastReport = null;

    public function mount(string $id): void
    {
        abort_unless(Gate::allows('view-networkshare'), 403);
        $this->id = (int) $id;
        $this->loadShare();
        $this->refreshDrift();
    }

    public function loadShare(): void
    {
        $share = NetworkShare::find($this->id);
        if ($share === null) {
            abort(404);
        }
        $this->share = $share;
        $this->name = (string) $share->name;
        $this->directoryName = (string) $share->directory_name;
        $this->label = (string) ($share->label ?? '');
        $this->description = (string) ($share->description ?? '');
        $this->letter = (string) ($share->letter ?? '');

        $backend = $share->backendName();
        $this->backendLabel = $backend->label();
        $this->backendDescription = $backend->description();

        $this->loadTreeOrigin($share);
        $this->loadLastReport($share);
    }

    /**
     * Story 60.5 — origine et nœuds activables. Silencieux et sans effet pour un
     * partage ordinaire : il n'a pas d'origine, et il n'a rien à activer.
     */
    private function loadTreeOrigin(NetworkShare $share): void
    {
        $this->treeOrigin = null;
        $this->activableNodes = [];

        if (! $share->hasRecipeOrigin()) {
            return;
        }

        $share->loadMissing(['directoryTemplate', 'userGroup']);

        try {
            $plan = app(NetworkShareService::class)->planFor($share);
            $location = app(FileBackendRegistry::class)->forShare($share)->location($plan);
        } catch (\Throwable $e) {
            // Un lien cassé (recette ou groupe supprimé) ne doit pas rendre la
            // fiche illisible : on montre ce qu'on sait, on tait ce qu'on ignore.
            $this->treeOrigin = [
                'template' => (string) ($share->directoryTemplate?->label ?? 'recette introuvable'),
                'group' => (string) ($share->userGroup?->display_name ?: $share->userGroup?->name ?: 'groupe introuvable'),
                'location' => null,
            ];

            return;
        }

        $this->treeOrigin = [
            'template' => (string) ($share->directoryTemplate?->label ?? ''),
            'group' => (string) ($share->userGroup?->display_name ?: $share->userGroup?->name ?: ''),
            'location' => $location,
        ];

        $activation = $share->nodeActivation();

        foreach ($share->directoryTemplate?->nodes() ?? [] as $spec) {
            if (! is_array($spec) || ($spec['nature'] ?? null) !== PlanNodeNature::Activable->value) {
                continue;
            }
            $path = (string) ($spec['path'] ?? '');
            if ($path === '') {
                continue;
            }

            $this->activableNodes[] = [
                'path' => $path,
                'label' => (string) ($spec['label'] ?? $path),
                'active' => $activation[$path] ?? true,
            ];
        }
    }

    /** Story 60.5 — le dernier rapport connu, en tableau, pour affichage. */
    private function loadLastReport(NetworkShare $share): void
    {
        $report = app(NetworkShareService::class)->lastReport($share);

        if ($report === null) {
            $this->lastReport = null;

            return;
        }

        $nodes = [];
        foreach ((array) ($report['nodes'] ?? []) as $node) {
            if (! is_array($node)) {
                continue;
            }
            $path = (string) ($node['path'] ?? '');
            $outcome = FileBackendOutcome::tryFrom((string) ($node['outcome'] ?? ''));

            $nodes[] = [
                // La racine se dit « (racine) » — jamais son jeton brut.
                'display_path' => $path === PlanNode::ROOT_PATH ? '(racine)' : $path,
                'outcome' => $outcome?->value ?? FileBackendOutcome::Echec->value,
                'label' => $outcome?->label() ?? 'Inconnu',
                'detail' => isset($node['detail']) && is_string($node['detail']) && $node['detail'] !== ''
                    ? $node['detail']
                    : null,
            ];
        }

        $this->lastReport = ['backend' => (string) ($report['backend'] ?? ''), 'nodes' => $nodes];
    }

    /**
     * Story 60.5 — bascule un nœud ACTIVABLE de l'arbre.
     *
     * Persiste l'état PUIS enfile la réconciliation : la pose est quadratique, et
     * le cycle d'une requête n'est pas le bon endroit pour l'attendre.
     *
     * **La bascule de l'arbre historique est une AUTRE bascule**, sur un autre
     * arbre, avec son propre écran. Rien ici ne la pilote, et rien ici ne doit
     * laisser croire le contraire.
     */
    public function toggleNode(string $path): void
    {
        abort_unless(Gate::allows('manage-networkshare'), 403);

        $share = $this->share?->fresh();
        if ($share === null || ! $share->hasRecipeOrigin()) {
            return;
        }

        $known = array_column($this->activableNodes, 'path');
        if (! in_array($path, $known, true)) {
            // Un chemin qui n'est pas un nœud activable de la recette : refus net
            // plutôt qu'une entrée orpheline dans la donnée d'activation.
            $this->toastError('Ce dossier n\'est pas activable dans cette recette.');

            return;
        }

        $activation = $share->nodeActivation();
        $next = ! ($activation[$path] ?? true);
        $activation[$path] = $next;

        $share->node_activation = $activation;
        $share->save();

        $this->share = $share;
        $this->loadShare();

        $queued = app(NetworkShareService::class)->queueReconciliation($share);
        $this->reconciliationEngaged = $queued;
        $this->reconciliationFailure = app(NetworkShareService::class)->lastFailure($share);

        if ($queued) {
            $this->toastSuccess($next
                ? 'Dossier réactivé. La mise en place des droits est engagée.'
                : 'Dossier suspendu : les accès seront vidés, le dossier et son contenu sont conservés. '
                    . 'La mise en place des droits est engagée.');
        } else {
            $this->toastError('L\'état a été enregistré, mais la mise en place des droits n\'a pas pu être engagée.');
        }
    }

    // --- Story 60.3 : aperçu du plan avant application ----------------------

    /**
     * Projette le partage en plan NEUTRE, le soumet au backend d'aperçu obtenu
     * VIA LE REGISTRE (le chemin du contrat, jamais un raccourci vers la classe)
     * et prépare le rendu.
     */
    public function openPlanPreview(): void
    {
        abort_unless(Gate::allows('view-networkshare'), 403);

        $share = $this->share?->fresh();
        if ($share === null) {
            return;
        }

        try {
            // Story 60.5 — la projection passe par l'ORCHESTRATEUR, qui route selon
            // l'origine du partage. Court-circuiter vers la projection plate
            // montrerait, pour un partage d'arbre, un aperçu à un seul nœud sans
            // ses audiences : un aperçu FAUX est pire qu'une absence d'aperçu.
            $plan = app(NetworkShareService::class)->planFor($share);
            // Le backend d'APERÇU, jamais celui du partage : on montre ce que le
            // plan dit sans rien écrire. Résolu par le REGISTRE — le chemin du
            // contrat, pas un raccourci vers la classe : le jour où l'aperçu
            // pourra s'appuyer sur l'autorité réelle du partage (60.4), c'est
            // cette ligne qui changera, et elle seule.
            $backend = app(FileBackendRegistry::class)->get(FileBackendName::Preview);
            $report = $backend->provision($plan);
        } catch (\Throwable $e) {
            // Un plan non projetable (nom de répertoire hérité illisible) ou un
            // backend sans implémentation : on le DIT, on ne montre pas un aperçu
            // partiel qui laisserait croire à une intention comprise.
            $this->toastError("Aperçu impossible : {$e->getMessage()}");

            return;
        }

        $this->planPreview = $this->presentPlan($plan, $report, $backend->name());
        $this->isPlanPreviewOpen = true;
    }

    public function closePlanPreview(): void
    {
        $this->isPlanPreviewOpen = false;
    }

    /**
     * Met le plan et le rapport en forme d'affichage.
     *
     * Les sujets sont résolus PAR IDENTITÉ INTERNE, en lot (deux requêtes au
     * plus) : le plan ne porte que des identités, et c'est au-dessus de la ligne
     * de contrat qu'on leur redonne un nom SE5 — jamais un nom système.
     */
    private function presentPlan(FilePlan $plan, ReconciliationReport $report, FileBackendName $backendName): array
    {
        $userIds = [];
        $groupIds = [];
        foreach ($plan->nodes as $node) {
            foreach ($node->grants as $grant) {
                if ($grant->subject->type === PlanSubject::TYPE_USER) {
                    $userIds[$grant->subject->id] = true;
                } else {
                    $groupIds[$grant->subject->id] = true;
                }
            }
        }

        $userLabels = $userIds === []
            ? []
            : User::whereIn('id', array_keys($userIds))->pluck('login', 'id')->all();
        $groupRows = $groupIds === []
            ? collect()
            : UserGroup::whereIn('id', array_keys($groupIds))->get(['id', 'name', 'display_name']);
        $groupLabels = [];
        foreach ($groupRows as $g) {
            $groupLabels[(int) $g->id] = (string) ($g->display_name ?: $g->name);
        }

        $nodes = [];
        foreach ($plan->nodes as $node) {
            $entry = $report->for($node->path);

            $grants = [];
            foreach ($node->grants as $grant) {
                $subject = $grant->subject;
                if ($subject->type === PlanSubject::TYPE_USER) {
                    $label = ($userLabels[$subject->id] ?? "#{$subject->id}") . ' (utilisateur)';
                } else {
                    $label = ($groupLabels[$subject->id] ?? "#{$subject->id}") . " (groupe d'utilisateurs)";
                }

                $grants[] = [
                    'label' => $label,
                    // Story 62.4 — l'octroi porte des VERBES : on rend la liste
                    // telle quelle, avec le même libellé que l'encart de dérive.
                    'access_label' => PlanStateComparator::accessLabel($grant->verbs),
                    'suspended' => ! $grant->isActive(),
                ];
            }

            $nodes[] = [
                'path' => $node->path,
                // La racine se dit « (racine) » — jamais son jeton brut, qui est un
                // détail de représentation et ne veut rien dire pour un administrateur.
                'display_path' => $node->path === PlanNode::ROOT_PATH ? '(racine)' : $node->path,
                'label' => $node->label,
                'nature' => $node->nature->label(),
                'plafond' => $node->plafond,
                'closure' => $node->closure,
                'grants' => $grants,
                'outcome' => $entry?->outcome->value ?? FileBackendOutcome::Echec->value,
                'detail' => $entry?->detail,
            ];
        }

        return [
            'backend' => [
                'label' => $backendName->label(),
                'description' => $backendName->description(),
            ],
            'root' => $plan->rootPath,
            'template' => $plan->templateKey,
            'nodes' => $nodes,
        ];
    }

    // --- Assignations actuelles --------------------------------------------

    #[Computed]
    public function assignments(): array
    {
        $rows = NetworkShareAssignable::where('network_share_id', $this->id)
            ->orderBy('assignable_type')
            ->get();

        return $rows->map(function (NetworkShareAssignable $a): array {
            [$label, $typeLabel, $icon, $mountOnly] = $this->describeAssignable($a->assignable_type, (int) $a->assignable_id);

            return [
                'id' => $a->id,
                'type' => $a->assignable_type,
                'assignable_id' => $a->assignable_id,
                'access' => $a->access,
                'label' => $label,
                'type_label' => $typeLabel,
                'icon' => $icon,
                'mount_only' => $mountOnly,
            ];
        })->all();
    }

    /**
     * @return array{0:string,1:string,2:string,3:bool}  [label, typeLabel, icon, mountOnly]
     */
    private function describeAssignable(string $type, int $id): array
    {
        return match ($type) {
            User::class => [
                (string) (User::find($id)?->login ?? "#{$id}"),
                'Utilisateur', 'fa-user', false,
            ],
            UserGroup::class => [
                (string) (UserGroup::find($id)?->getDisplayNameOrNameAttribute() ?? "#{$id}"),
                "Groupe d'utilisateurs", 'fa-users', false,
            ],
            WorkstationGroup::class => (function () use ($id): array {
                $wg = WorkstationGroup::find($id);

                return [
                    (string) ($wg?->display_name ?: ($wg?->name ?? "#{$id}")),
                    'Parc (montage seul)', 'fa-layer-group', true,
                ];
            })(),
            default => ["#{$id}", 'Inconnu', 'fa-question', false],
        };
    }

    /** Avertissements prédictifs (WG-montage-seul). */
    #[Computed]
    public function warnings(): array
    {
        if ($this->share === null) {
            return [];
        }
        return app(NetworkShareValidator::class)->warnings($this->share);
    }

    // --- Pickers SQL (zéro CN AD) ------------------------------------------

    #[Computed]
    public function candidates(): array
    {
        $assignedIds = collect($this->assignments)
            ->where('type', $this->assignType)
            ->pluck('assignable_id')
            ->map(fn ($v) => (int) $v)
            ->all();

        $needle = '%' . strtolower(trim($this->assignSearch)) . '%';

        return match ($this->assignType) {
            User::class => User::query()
                ->when($this->assignSearch !== '', fn ($q) => $q->whereRaw('LOWER(login) LIKE ?', [$needle]))
                ->whereNotIn('id', $assignedIds)
                ->orderBy('login')
                ->limit(50)
                ->get(['id', 'login'])
                ->map(fn (User $u): array => ['id' => $u->id, 'label' => $u->login])
                ->all(),
            UserGroup::class => UserGroup::query()
                ->when($this->assignSearch !== '', fn ($q) => $q->whereRaw('LOWER(name) LIKE ?', [$needle]))
                ->whereNotIn('id', $assignedIds)
                ->orderBy('name')
                ->limit(50)
                ->get(['id', 'name', 'display_name'])
                ->map(fn (UserGroup $g): array => ['id' => $g->id, 'label' => $g->display_name ?: $g->name])
                ->all(),
            WorkstationGroup::class => WorkstationGroup::query()
                ->when($this->assignSearch !== '', fn ($q) => $q->whereRaw('LOWER(name) LIKE ?', [$needle]))
                ->whereNotIn('id', $assignedIds)
                ->orderBy('name')
                ->limit(50)
                ->get(['id', 'name', 'display_name'])
                ->map(fn (WorkstationGroup $w): array => ['id' => $w->id, 'label' => $w->display_name ?: $w->name])
                ->all(),
            default => [],
        };
    }

    public function updatedAssignType(): void
    {
        $this->assignTargetId = null;
        $this->assignSearch = '';
    }

    // --- Édition des champs -------------------------------------------------

    public function editDetails(): void
    {
        abort_unless(Gate::allows('manage-networkshare'), 403);
        $this->editingDetails = true;
    }

    public function cancelEditDetails(): void
    {
        $this->editingDetails = false;
        $this->resetErrorBag();
        $this->loadShare(); // restaure les valeurs d'origine si modifiées sans enregistrer
    }

    public function saveDetails(): void
    {
        abort_unless(Gate::allows('manage-networkshare'), 403);

        $validator = app(NetworkShareValidator::class);

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'directoryName' => [
                'required', 'string', 'max:255',
                'regex:' . NetworkShareService::DIRECTORY_NAME_PATTERN,
                'unique:network_shares,directory_name,' . $this->id,
            ],
            'label' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'letter' => [
                'nullable', 'string', 'max:8',
                function (string $attr, $value, $fail) use ($validator): void {
                    if ($validator->isReservedLetter($value)) {
                        $fail('Cette lettre est réservée par le système (A-D, H, I, K, L).');
                    }
                },
            ],
        ], [
            'directoryName.regex' => 'Le nom de répertoire ne peut contenir que des lettres, chiffres, '
                . '« . », « _ », « - » (sans espace) et ne peut pas commencer par « . ».',
            'directoryName.unique' => 'Ce nom de répertoire est déjà utilisé.',
        ]);

        $share = $this->share;
        $newLetter = $this->normalizedLetter($validated['letter'] ?? null);

        // Validation prédictive AVANT écriture (collision de lettre).
        $share->letter = $newLetter;
        try {
            $validator->assertNoLetterCollision($share);
        } catch (NetworkShareLetterCollisionException $e) {
            $share->letter = $share->getOriginal('letter'); // revert en mémoire
            $this->toastError($e->getMessage());
            return;
        }

        $share->name = $validated['name'];
        $share->directory_name = $validated['directoryName'];
        $share->label = $validated['label'] !== '' ? $validated['label'] : null;
        $share->description = ($validated['description'] ?? '') !== '' ? $validated['description'] : null;
        $share->save();

        $this->editingDetails = false;
        $this->reprovision('Répertoire mis à jour');
        $this->loadShare();
    }

    // --- Assignations -------------------------------------------------------

    public function openAssign(): void
    {
        abort_unless(Gate::allows('manage-networkshare'), 403);
        $this->assignType = User::class;
        $this->assignSearch = '';
        $this->assignTargetId = null;
        $this->assignAccess = NetworkShareAssignable::ACCESS_RO;
        unset($this->candidates);
        $this->isAssignOpen = true;
    }

    public function closeAssign(): void
    {
        $this->isAssignOpen = false;
        $this->assignSearch = '';
        $this->assignTargetId = null;
        unset($this->candidates);
    }

    public function addAssignment(): void
    {
        abort_unless(Gate::allows('manage-networkshare'), 403);

        $validator = app(NetworkShareValidator::class);
        if (! $validator->isAllowedAssignableType($this->assignType)) {
            $this->toastError("Type d'assignation non autorisé.");
            return;
        }
        if ($this->assignTargetId === null) {
            $this->toastWarning('Sélectionnez une cible à assigner.');
            return;
        }
        if (! in_array($this->assignAccess, ['ro', 'rw'], true)) {
            $this->assignAccess = 'ro';
        }

        // Vérifie l'existence réelle de la cible (intégrité — pas de FK polymorphe).
        if (! $this->targetExists($this->assignType, $this->assignTargetId)) {
            $this->toastError('Cible introuvable.');
            return;
        }

        // L'ajout d'une cible ÉLARGIT l'audience : ce répertoire peut entrer en
        // collision de lettre avec un AUTRE répertoire de même lettre explicite
        // dont l'audience recouvre désormais la cible ajoutée (piège #3, finding
        // review #1 — `saveDetails`/`createShare` validaient déjà, `addAssignment`
        // était le vecteur non couvert). On valide DANS la transaction : si
        // collision, l'assignation est rollback (aucune écriture partielle).
        try {
            DB::transaction(function (): void {
                NetworkShareAssignable::updateOrCreate(
                    [
                        'network_share_id' => $this->id,
                        'assignable_type' => $this->assignType,
                        'assignable_id' => $this->assignTargetId,
                    ],
                    ['access' => $this->assignAccess],
                );

                app(NetworkShareValidator::class)->assertNoLetterCollision($this->share);
            });
        } catch (NetworkShareLetterCollisionException $e) {
            $this->toastError($e->getMessage());
            return;
        }

        $this->assignTargetId = null;
        $this->assignSearch = '';
        $this->isAssignOpen = false;
        unset($this->assignments, $this->candidates, $this->warnings);

        $this->reprovision('Assignation ajoutée');
        $this->surfaceWarnings();
    }

    public function changeAccess(int $assignmentId, string $access): void
    {
        abort_unless(Gate::allows('manage-networkshare'), 403);
        if (! in_array($access, ['ro', 'rw'], true)) {
            return;
        }
        $row = NetworkShareAssignable::where('network_share_id', $this->id)->find($assignmentId);
        if ($row === null) {
            return;
        }
        $row->access = $access;
        $row->save();
        unset($this->assignments);
        $this->reprovision('Accès mis à jour');
    }

    public function removeAssignment(int $assignmentId): void
    {
        abort_unless(Gate::allows('manage-networkshare'), 403);
        $row = NetworkShareAssignable::where('network_share_id', $this->id)->find($assignmentId);
        if ($row === null) {
            return;
        }
        $row->delete();
        unset($this->assignments, $this->candidates, $this->warnings);
        $this->reprovision('Assignation retirée');
        $this->surfaceWarnings();
    }

    // --- Suppression --------------------------------------------------------

    public function deleteShare()
    {
        abort_unless(Gate::allows('manage-networkshare'), 403);
        $share = $this->share;
        $name = $share?->name ?? '';

        // Déprovisionne AVANT de perdre la ligne SQL : révoque les ACL POSIX et
        // sort le dossier de l'espace exposé par le share SMB [partages] (sinon
        // un dossier « supprimé » reste atteignable en UNC avec ses grants). Le
        // contenu est archivé (mv en poubelle), pas détruit.
        // Story 60.4 — le déprovisionnement reste SYNCHRONE : la ligne disparaît
        // juste après, et un répertoire encore exposé pendant ce temps reste
        // atteignable par tous ceux qui y avaient accès.
        $deprovisioned = $share !== null && app(NetworkShareService::class)->deprovision($share);

        // La suppression cascade le pivot (onDelete cascade, 34.1).
        NetworkShare::where('id', $this->id)->delete();

        session()->flash('toast', [
            'status' => $deprovisioned ? 'success' : 'warning',
            'title' => 'Suppression réussie',
            'message' => $deprovisioned
                ? "Le répertoire « {$name} » a été supprimé : accès révoqués et dossier archivé."
                : "Le répertoire « {$name} » a été supprimé, mais la révocation des accès serveur a échoué. Consultez les journaux.",
        ]);

        return redirect()->route('admin.shares');
    }

    // --- Helpers ------------------------------------------------------------

    /**
     * ENFILE la réconciliation et le DIT. Aucune écriture n'a lieu dans le cycle
     * de cette requête (la pose des droits est quadratique en nombre d'entrées
     * nominatives) ; l'écran affiche l'état « engagé », et l'administrateur voit
     * le résultat au rafraîchissement suivant ou en relançant l'audit.
     */
    private function reprovision(string $okPrefix): void
    {
        $share = $this->share?->fresh();
        if ($share === null) {
            return;
        }
        if (app(NetworkShareService::class)->queueReconciliation($share)) {
            $this->reconciliationEngaged = true;
            $this->toastSuccess($okPrefix . '. La mise en place des droits est engagée.');
        } else {
            $this->toastWarning($okPrefix . ", mais la réconciliation n'a pas pu être engagée. Consultez les journaux serveur.");
        }
    }

    /**
     * Relit l'état et le compare au plan. Appelé au montage et à la demande —
     * jamais pendant la saisie : il interroge le serveur.
     */
    private function refreshDrift(): void
    {
        $share = $this->share?->fresh();
        $this->drift = $share === null ? null : $this->presentDrift(app(NetworkShareService::class)->computeDrift($share));
        // Une réconciliation enfilée qui échoue ne lève RIEN au-dessus d'elle :
        // le service absorbe l'erreur, la file ne réessaie pas et ne consigne
        // rien. Sans cette relecture, l'échec n'aurait aucun destinataire.
        $this->reconciliationFailure = $share === null
            ? null
            : app(NetworkShareService::class)->lastFailure($share);
    }

    /**
     * Met l'écart en forme d'affichage : les sujets sont résolus PAR IDENTITÉ
     * INTERNE, en lot (deux requêtes au plus), et rendus par leur nom SE5. Aucun
     * nom système, aucun mode de permission, aucun chemin n'entre ici — c'est ce
     * que la story 60.4 assainit sur cet écran.
     *
     * @param  array{status:string,nodes:list<array<string,mixed>>,detail?:string}  $drift
     */
    private function presentDrift(array $drift): array
    {
        $userIds = [];
        $groupIds = [];
        foreach ($drift['nodes'] as $node) {
            foreach ($node['differences'] as $difference) {
                if ($difference['subject']['type'] === PlanSubject::TYPE_USER) {
                    $userIds[$difference['subject']['id']] = true;
                } else {
                    $groupIds[$difference['subject']['id']] = true;
                }
            }
        }

        $userLabels = $userIds === []
            ? []
            : User::whereIn('id', array_keys($userIds))->pluck('login', 'id')->all();
        $groupLabels = [];
        if ($groupIds !== []) {
            foreach (UserGroup::whereIn('id', array_keys($groupIds))->get(['id', 'name', 'display_name']) as $g) {
                $groupLabels[(int) $g->id] = (string) ($g->display_name ?: $g->name);
            }
        }

        $nodes = [];
        foreach ($drift['nodes'] as $node) {
            $differences = [];
            foreach ($node['differences'] as $difference) {
                $subject = $difference['subject'];
                $label = $subject['type'] === PlanSubject::TYPE_USER
                    ? ($userLabels[$subject['id']] ?? "#{$subject['id']}") . ' (utilisateur)'
                    : ($groupLabels[$subject['id']] ?? "#{$subject['id']}") . " (groupe d'utilisateurs)";

                $differences[] = [
                    'label' => $label,
                    'expected' => PlanStateComparator::accessLabel($difference['expected']),
                    'observed' => PlanStateComparator::accessLabel($difference['observed']),
                ];
            }

            $nodes[] = [
                // La racine se dit « (racine) » — jamais son jeton brut.
                'display_path' => $node['path'] === PlanNode::ROOT_PATH ? '(racine)' : $node['path'],
                'status' => $node['status'],
                'detail' => $node['detail'],
                'differences' => $differences,
            ];
        }

        return ['status' => $drift['status'], 'nodes' => $nodes];
    }

    /**
     * Reconvergence manuelle depuis l'écran — enfilée comme les autres.
     */
    public function resync(): void
    {
        abort_unless(Gate::allows('manage-networkshare'), 403);
        $this->reprovision('Réconciliation demandée');
    }

    /** Relit l'état à la demande — le bouton d'audit de conformité. */
    public function refreshConformity(): void
    {
        abort_unless(Gate::allows('view-networkshare'), 403);
        $this->reconciliationEngaged = false;
        $this->refreshDrift();
    }

    private function surfaceWarnings(): void
    {
        foreach ($this->warnings() as $warning) {
            $this->toastWarning($warning);
        }
    }

    private function targetExists(string $type, int $id): bool
    {
        return match ($type) {
            User::class => User::whereKey($id)->exists(),
            UserGroup::class => UserGroup::whereKey($id)->exists(),
            WorkstationGroup::class => WorkstationGroup::whereKey($id)->exists(),
            default => false,
        };
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
        return strtoupper($trimmed[0]) . ':';
    }

    public function typeOptions(): array
    {
        return [
            User::class => 'Utilisateur',
            UserGroup::class => "Groupe d'utilisateurs",
            WorkstationGroup::class => 'Parc (montage seul)',
        ];
    }
}; ?>

<x-organisms.page title="Lecteur réseau géré" :scrollable="true" :back="route('admin.shares')"
    back-text="Retour aux lecteurs réseau">
    <x-slot:actions>
        {{-- Story 60.3 — l'aperçu est READ-ONLY : il n'écrit rien, il montre. --}}
        <button type="button" class="btn btn-outline btn-sm" wire:click="openPlanPreview"
            wire:loading.attr="disabled" wire:target="openPlanPreview">
            <span wire:loading.remove wire:target="openPlanPreview"><i class="fa-solid fa-eye"></i> Aperçu du plan</span>
            <span wire:loading wire:target="openPlanPreview"><span class="loading loading-spinner loading-xs"></span> …</span>
        </button>
        @can('manage-networkshare')
            <button type="button" class="btn btn-error btn-sm" wire:click="deleteShare"
                wire:confirm="Supprimer ce répertoire ? Les assignations seront retirées (le dossier serveur est conservé).">
                <i class="fa-regular fa-trash-can"></i> Supprimer
            </button>
        @endcan
    </x-slot:actions>

    @if (count($this->warnings()) > 0)
        @foreach ($this->warnings() as $warning)
            <div class="alert alert-warning mb-4">
                <i class="fa-solid fa-triangle-exclamation"></i>
                <span class="text-sm">{{ $warning }}</span>
            </div>
        @endforeach
    @endif

    {{-- ===================== Header : identité du lecteur ===================== --}}
    <div class="card bg-base-100 shadow mb-6">
        <div class="card-body">
            <div class="flex items-start gap-4">
                <div class="hidden sm:flex h-12 w-12 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary">
                    <i class="fa-solid fa-hard-drive text-xl"></i>
                </div>
                <div class="flex-1 min-w-0">
                    @if ($editingDetails)
                        {{-- Mode édition : formulaire --}}
                        <h2 class="card-title text-base">
                            <i class="fa-solid fa-pen text-primary"></i> Modifier le lecteur
                        </h2>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-x-4 gap-y-1 mt-1">
                            <div class="form-control">
                                <label class="label">
                                    <span class="label-text font-medium">Nom <span class="text-error">*</span></span>
                                </label>
                                <input type="text" wire:model="name" class="input input-bordered" />
                                @error('name') <span class="text-error text-xs mt-1">{{ $message }}</span> @enderror
                            </div>
                            <div class="form-control">
                                <label class="label">
                                    <span class="label-text font-medium">
                                        Nom de répertoire (FS) <span class="text-error">*</span>
                                        <span class="tooltip align-middle" data-tip="Lettres, chiffres, « . » « _ » « - » — sans espace, ne commence pas par « . ».">
                                            <i class="fa-solid fa-circle-info text-base-content/40 ml-0.5"></i>
                                        </span>
                                    </span>
                                </label>
                                <input type="text" wire:model="directoryName" class="input input-bordered font-mono" />
                                @error('directoryName') <span class="text-error text-xs mt-1">{{ $message }}</span> @enderror
                            </div>
                            <div class="form-control">
                                <label class="label"><span class="label-text font-medium">Libellé du lecteur</span></label>
                                <input type="text" wire:model="label" class="input input-bordered" placeholder="(par défaut : le nom)" />
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
                            </div>
                        </div>
                        <div class="form-control mt-1">
                            <label class="label"><span class="label-text font-medium">Description</span></label>
                            <textarea wire:model="description" rows="2" class="textarea textarea-bordered"
                                placeholder="À quoi sert ce lecteur ?"></textarea>
                            @error('description') <span class="text-error text-xs mt-1">{{ $message }}</span> @enderror
                        </div>
                        <div class="card-actions justify-end mt-3 gap-2">
                            <button type="button" class="btn btn-ghost btn-sm" wire:click="cancelEditDetails">Annuler</button>
                            <button type="button" class="btn btn-primary btn-sm" wire:click="saveDetails">
                                <i class="fa-solid fa-floppy-disk"></i> Enregistrer
                            </button>
                        </div>
                    @else
                        {{-- Mode lecture seule (défaut) --}}
                        <div class="flex items-start justify-between gap-2">
                            <div class="min-w-0">
                                <h2 class="card-title text-lg flex items-center gap-2 flex-wrap">
                                    <span class="truncate">{{ $name }}</span>
                                    @if ($letter !== '')
                                        <span class="badge badge-neutral badge-sm font-mono">{{ $letter }}</span>
                                    @else
                                        <span class="badge badge-ghost badge-sm">Lettre auto</span>
                                    @endif
                                    {{-- Story 60.3 — autorité d'écriture des droits. VISIBLE (elle
                                         détermine le chemin d'accès de l'utilisateur), NON ÉDITABLE
                                         tant qu'aucun flux ne route par elle. --}}
                                    <span class="badge badge-outline badge-sm gap-1 tooltip" data-tip="{{ $backendDescription }}">
                                        <i class="fa-solid fa-server text-[10px]"></i>
                                        {{ $backendLabel }}
                                    </span>
                                </h2>
                            </div>
                            @can('manage-networkshare')
                                <button type="button" class="btn btn-ghost btn-sm shrink-0" wire:click="editDetails">
                                    <i class="fa-solid fa-pen"></i> Modifier
                                </button>
                            @endcan
                        </div>
                        <dl class="mt-3 space-y-2 text-sm">
                            <div class="flex items-center gap-2 text-base-content/70">
                                <i class="fa-solid fa-folder w-4 text-center opacity-50"></i>
                                <span class="font-mono">{{ $directoryName }}</span>
                                <span class="text-base-content/40 text-xs">(nom de répertoire serveur)</span>
                            </div>
                            <div class="flex items-center gap-2 text-base-content/70">
                                <i class="fa-solid fa-tag w-4 text-center opacity-50"></i>
                                <span>{{ $label !== '' ? $label : $name }}</span>
                                <span class="text-base-content/40 text-xs">(libellé affiché côté poste)</span>
                            </div>
                            @if ($description !== '')
                                <div class="flex items-start gap-2 text-base-content/70">
                                    <i class="fa-solid fa-align-left w-4 text-center opacity-50 mt-0.5"></i>
                                    <span class="whitespace-pre-line">{{ $description }}</span>
                                </div>
                            @endif
                            {{-- Story 60.5 — l'ORIGINE : de quelle recette ce partage est la
                                 matérialisation, quel groupe il cloisonne, et OÙ il vit
                                 réellement. Ce dernier point est ce qu'on va vérifier à la
                                 main, et ce que la liste blanche du système doit couvrir. --}}
                            @if ($treeOrigin !== null)
                                <div class="flex items-center gap-2 text-base-content/70" data-share-origin>
                                    <i class="fa-solid fa-diagram-project w-4 text-center opacity-50"></i>
                                    <span>{{ $treeOrigin['template'] }}</span>
                                    <span class="text-base-content/40 text-xs">·</span>
                                    <span>{{ $treeOrigin['group'] }}</span>
                                </div>
                                @if ($treeOrigin['location'] !== null)
                                    <div class="flex items-center gap-2 text-base-content/70">
                                        <i class="fa-solid fa-hard-drive w-4 text-center opacity-50"></i>
                                        <span class="font-mono break-all">{{ $treeOrigin['location'] }}</span>
                                        <span class="text-base-content/40 text-xs">(emplacement serveur)</span>
                                    </div>
                                @endif
                            @endif
                        </dl>
                    @endif
                </div>
            </div>
        </div>
    </div>

    {{-- ===================== Conformité des droits (Story 60.4) =====================
         L'encart parle le VOCABULAIRE DU PLAN : un nœud, un sujet par son nom SE5,
         un accès attendu et un accès constaté. Plus aucune ligne de permission
         brute — c'est l'assainissement porté par la story 60.4, et un test de
         neutralité borné sur le marqueur `data-share-drift` le vérifie. --}}
    @if ($reconciliationEngaged)
        <div class="alert alert-info mb-4 flex items-start justify-between gap-3">
            <div class="flex items-start gap-2 min-w-0">
                <i class="fa-solid fa-hourglass-half mt-0.5"></i>
                <div class="min-w-0">
                    <div class="text-sm font-medium">Réconciliation engagée</div>
                    <div class="text-xs mt-0.5">
                        La mise en place des droits se poursuit côté serveur. Relancez l'audit ci-dessous pour voir l'état à jour.
                    </div>
                </div>
            </div>
        </div>
    @endif

    {{-- L'échec d'une réconciliation enfilée n'a AUCUN autre destinataire : le
         service l'absorbe, la file ne réessaie pas. Sans cet encart, l'écran
         resterait muet sur un geste qui n'a pas eu lieu. Le motif technique n'y
         figure pas — il est au journal ; ici on dit ce qui n'a pas eu lieu. --}}
    @if ($reconciliationFailure !== null)
        <div class="alert alert-error mb-4 flex items-start gap-2" data-share-reconciliation-failure>
            <i class="fa-solid fa-circle-xmark mt-0.5"></i>
            <div class="min-w-0">
                <div class="text-sm font-medium">La dernière mise en place des droits n'a pas eu lieu</div>
                <div class="text-xs mt-0.5">
                    Le paramétrage enregistré n'a pas pu être préparé pour le serveur : les droits en place
                    sont restés tels quels. Consultez les journaux serveur, puis relancez la mise en place.
                </div>
            </div>
        </div>
    @endif

    @if ($drift !== null)
        @php
            $driftMeta = match ($drift['status']) {
                'conforme' => ['alert-success', 'fa-circle-check', 'Les droits en place correspondent au paramétrage.'],
                'drifted' => ['alert-warning', 'fa-triangle-exclamation', 'Écart détecté : les droits en place ne correspondent plus au paramétrage.'],
                'absent' => ['alert-info', 'fa-folder-plus', 'Répertoire pas encore créé sur le serveur.'],
                default => ['alert-error', 'fa-circle-xmark', 'Impossible de relire l\'état du serveur (voir journaux).'],
            };
        @endphp
        <div class="alert {{ $driftMeta[0] }} mb-6 flex items-start justify-between gap-3" data-share-drift>
            <div class="flex items-start gap-2 min-w-0">
                <i class="fa-solid {{ $driftMeta[1] }} mt-0.5"></i>
                <div class="min-w-0">
                    <div class="text-sm font-medium">Conformité des droits — {{ $driftMeta[2] }}</div>
                    @foreach ($drift['nodes'] as $node)
                        @if (count($node['differences']) > 0 || $node['detail'] !== null)
                            <div class="text-xs mt-2">
                                <div class="font-semibold">{{ $node['display_path'] }}</div>
                                @if (count($node['differences']) > 0)
                                    <div class="overflow-x-auto mt-1">
                                        <table class="table table-xs">
                                            <thead>
                                                <tr>
                                                    <th>Destinataire</th>
                                                    <th>Attendu</th>
                                                    <th>Constaté</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                @foreach (array_slice($node['differences'], 0, 8) as $difference)
                                                    <tr>
                                                        <td>{{ $difference['label'] }}</td>
                                                        <td>{{ $difference['expected'] }}</td>
                                                        <td>{{ $difference['observed'] }}</td>
                                                    </tr>
                                                @endforeach
                                            </tbody>
                                        </table>
                                    </div>
                                    @if (count($node['differences']) > 8)
                                        <div class="mt-1">… et {{ count($node['differences']) - 8 }} autre(s).</div>
                                    @endif
                                @endif
                                @if ($node['detail'] !== null)
                                    <div class="mt-1">{{ $node['detail'] }}</div>
                                @endif
                            </div>
                        @endif
                    @endforeach
                </div>
            </div>
            <div class="flex flex-col gap-2 shrink-0">
                <button type="button" class="btn btn-sm btn-ghost" wire:click="refreshConformity"
                    wire:loading.attr="disabled" wire:target="refreshConformity">
                    <span wire:loading.remove wire:target="refreshConformity"><i class="fa-solid fa-magnifying-glass"></i> Vérifier</span>
                    <span wire:loading wire:target="refreshConformity"><span class="loading loading-spinner loading-xs"></span> …</span>
                </button>
                @can('manage-networkshare')
                    @if (in_array($drift['status'], ['drifted', 'absent', 'error'], true))
                        <button type="button" class="btn btn-sm" wire:click="resync"
                            wire:loading.attr="disabled" wire:target="resync">
                            <span wire:loading.remove wire:target="resync"><i class="fa-solid fa-rotate"></i> Resynchroniser</span>
                            <span wire:loading wire:target="resync"><span class="loading loading-spinner loading-xs"></span> …</span>
                        </button>
                    @endif
                @endcan
            </div>
        </div>
    @endif

    {{-- ===================== Dossiers activables (Story 60.5) =====================
         Suspendre VIDE les accès du dossier ; le dossier et son contenu RESTENT.
         Le libellé le dit en toutes lettres — c'est la distinction que tout le
         modèle a passé un critère entier à établir, et la confondre avec une
         suppression serait la seule erreur irréparable de cet écran. --}}
    @if ($activableNodes !== [])
        <div class="card bg-base-100 shadow mb-6" data-share-activable>
            <div class="card-body">
                <h2 class="card-title text-base">
                    <i class="fa-solid fa-toggle-on text-primary"></i> Dossiers activables
                    <span class="badge badge-neutral badge-sm">{{ count($activableNodes) }}</span>
                </h2>
                <p class="text-xs text-base-content/60">
                    Suspendre un dossier retire l'accès des élèves : le dossier et les documents qu'il
                    contient sont conservés, et un rétablissement rend l'accès tel qu'il était.
                </p>
                <div class="overflow-x-auto mt-2">
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th>Dossier</th>
                                <th>État</th>
                                @can('manage-networkshare')
                                    <th class="text-right">Action</th>
                                @endcan
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($activableNodes as $node)
                                <tr>
                                    <td>
                                        <div>{{ $node['label'] }}</div>
                                        <div class="text-xs text-base-content/50 font-mono">{{ $node['path'] }}</div>
                                    </td>
                                    <td>
                                        @if ($node['active'])
                                            <span class="badge badge-success badge-sm">Actif</span>
                                        @else
                                            <span class="badge badge-warning badge-sm">Suspendu</span>
                                        @endif
                                    </td>
                                    @can('manage-networkshare')
                                        <td class="text-right">
                                            <button type="button" class="btn btn-xs btn-outline"
                                                wire:click="toggleNode(@js($node['path']))"
                                                wire:loading.attr="disabled" wire:target="toggleNode">
                                                {{ $node['active'] ? 'Suspendre' : 'Réactiver' }}
                                            </button>
                                        </td>
                                    @endcan
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    @endif

    {{-- ===================== Dernier passage, par nœud (Story 60.5) =====================
         Un partage plat a un nœud : « ça a marché ou pas » suffisait. Un arbre en a
         quatre plus un par élève, et la question devient « LEQUEL a échoué ». Le
         rapport est lu depuis un TABLEAU : un rapport ne se reconstruit pas sans
         repasser par sa fabrique et son plan, et pour l'afficher le tableau suffit. --}}
    @if ($lastReport !== null && $lastReport['nodes'] !== [])
        <div class="card bg-base-100 shadow mb-6" data-share-last-report>
            <div class="card-body">
                <h2 class="card-title text-base">
                    <i class="fa-solid fa-clipboard-check text-primary"></i> Dernier passage sur les droits
                </h2>
                <div class="overflow-x-auto mt-2">
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th>Dossier</th>
                                <th>Issue</th>
                                <th>Détail</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($lastReport['nodes'] as $node)
                                @php
                                    $badge = match ($node['outcome']) {
                                        'conforme' => 'badge-success',
                                        'applique' => 'badge-info',
                                        'en_attente' => 'badge-ghost',
                                        'non_implemente', 'non_exprimable' => 'badge-warning',
                                        default => 'badge-error',
                                    };
                                @endphp
                                <tr>
                                    <td class="font-mono text-xs">{{ $node['display_path'] }}</td>
                                    <td><span class="badge {{ $badge }} badge-sm">{{ $node['label'] }}</span></td>
                                    <td class="text-xs text-base-content/70">{{ $node['detail'] ?? '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    @endif

    {{-- ===================== Assignations : tableau + bouton « Ajouter » ===================== --}}
            <div class="flex items-center justify-between gap-2">
                <h2 class="card-title text-base">
                    <i class="fa-solid fa-share-nodes text-primary"></i> Assignations
                    <span class="badge badge-neutral badge-sm">{{ count($this->assignments()) }}</span>
                </h2>
                @can('manage-networkshare')
                    <button type="button" class="btn btn-primary btn-sm" wire:click="openAssign">
                        <i class="fa-solid fa-plus"></i> Ajouter
                    </button>
                @endcan
            </div>

            @if (count($this->assignments()) === 0)
                <div class="text-sm text-base-content/50 text-center py-10">
                    <i class="fa-regular fa-folder-open text-2xl mb-2 block opacity-40"></i>
                    Aucune assignation. Cliquez sur « Ajouter » pour donner accès à un utilisateur, un groupe ou un parc.
                </div>
            @else
                <div class="card bg-base-100 shadow-sm overflow-hidden border border-base-300 mt-3">
                    <div class="px-4 py-3 border-b border-base-300 text-sm text-base-content/70">
                        {{ count($this->assignments()) }} assignation(s) sur ce lecteur
                    </div>
                    <div class="overflow-x-auto">
                        <table class="table table-zebra">
                            <thead>
                                <tr>
                                    <th>Cible</th>
                                    <th>Type</th>
                                    <th>Accès</th>
                                    <th class="text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($this->assignments() as $a)
                                    <tr wire:key="assign-{{ $a['id'] }}">
                                        <td class="font-medium">
                                            <i class="fa-solid {{ $a['icon'] }} mr-1.5 opacity-60"></i>{{ $a['label'] }}
                                        </td>
                                        <td>
                                            <span class="badge badge-ghost badge-sm gap-1">
                                                <i class="fa-solid {{ $a['icon'] }} text-[10px]"></i>
                                                {{ $a['type_label'] }}
                                            </span>
                                        </td>
                                        <td>
                                            @if ($a['mount_only'])
                                                <span class="badge badge-ghost badge-sm" title="Parc = aucune ACL POSIX">—</span>
                                            @else
                                                @can('manage-networkshare')
                                                    <select class="select select-bordered select-xs"
                                                        wire:change="changeAccess({{ $a['id'] }}, $event.target.value)">
                                                        @foreach (\App\Models\NetworkShareAssignable::ACCESS_LABELS as $val => $label)
                                                            <option value="{{ $val }}" @selected($a['access'] === $val)>{{ $label }}</option>
                                                        @endforeach
                                                    </select>
                                                @else
                                                    <span class="badge badge-sm {{ $a['access'] === 'rw' ? 'badge-success' : 'badge-info' }}">{{ \App\Models\NetworkShareAssignable::accessLabel($a['access']) }}</span>
                                                @endcan
                                            @endif
                                        </td>
                                        <td class="text-right">
                                            @can('manage-networkshare')
                                                <button type="button" class="btn btn-ghost btn-xs text-error"
                                                    wire:click="removeAssignment({{ $a['id'] }})"
                                                    wire:confirm="Retirer cette assignation ?">
                                                    <i class="fa-regular fa-trash-can"></i>
                                                </button>
                                            @endcan
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif

    {{-- ===================== Modale : aperçu du plan (Story 60.3) ===================== --}}
    <x-molecules.modal wire:model="isPlanPreviewOpen" size="max-w-4xl" height="h-auto"
        close-method="closePlanPreview"
        title="Aperçu du plan" icon="fa-eye text-primary"
        subtitle="Ce que ce partage dit, avant toute application. Rien n'est écrit.">
        @if ($planPreview !== null)
            @include('pages::admin.shares._partials.plan-preview', ['preview' => $planPreview])
        @endif
        <x-slot:footer>
            <button type="button" class="btn btn-ghost" wire:click="closePlanPreview">Fermer</button>
        </x-slot:footer>
    </x-molecules.modal>

    {{-- ===================== Modale : ajouter une assignation (recherche dynamique) ===================== --}}
    @can('manage-networkshare')
        <x-molecules.modal wire:model="isAssignOpen" size="max-w-2xl" height="h-auto"
            close-method="closeAssign"
            title="Ajouter une assignation" icon="fa-user-plus text-primary">

            <x-molecules.modal.section title="Type de cible" icon="fa-filter text-primary" dense>
                <select wire:model.live="assignType" class="select select-bordered w-full">
                    @foreach ($this->typeOptions() as $value => $lbl)
                        <option value="{{ $value }}">{{ $lbl }}</option>
                    @endforeach
                </select>
            </x-molecules.modal.section>

            @php
                $typeIcon = match ($assignType) {
                    \App\Models\User::class => 'fa-user',
                    \App\Models\UserGroup::class => 'fa-users',
                    \App\Models\WorkstationGroup::class => 'fa-layer-group',
                    default => 'fa-question',
                };
            @endphp
            <x-molecules.modal.section title="Rechercher la cible" icon="fa-magnifying-glass text-primary" dense>
                <label class="input input-bordered flex items-center gap-2">
                    <i class="fa-solid fa-magnifying-glass opacity-50"></i>
                    <input type="search" wire:model.live.debounce.300ms="assignSearch" class="grow"
                        placeholder="Nom d'utilisateur, groupe, parc..." />
                    <span wire:loading wire:target="assignSearch" class="loading loading-spinner loading-xs"></span>
                </label>

                <div class="mt-2 max-h-64 overflow-y-auto rounded-lg border border-base-300 divide-y divide-base-200">
                    @forelse ($this->candidates() as $cand)
                        <button type="button" wire:key="cand-{{ $cand['id'] }}"
                            class="w-full text-left px-3 py-2 hover:bg-base-200 flex items-center gap-2 transition-colors {{ $assignTargetId === $cand['id'] ? 'bg-primary/10' : '' }}"
                            wire:click="$set('assignTargetId', {{ $cand['id'] }})">
                            <i class="fa-solid {{ $typeIcon }} opacity-50 shrink-0 w-4 text-center"></i>
                            <span class="text-sm truncate grow">{{ $cand['label'] }}</span>
                            @if ($assignTargetId === $cand['id'])
                                <i class="fa-solid fa-check text-primary shrink-0"></i>
                            @endif
                        </button>
                    @empty
                        <div class="px-3 py-6 text-center text-sm text-base-content/50">
                            {{ trim($assignSearch) === '' ? 'Aucune cible disponible.' : 'Aucun résultat pour « ' . $assignSearch . ' ».' }}
                        </div>
                    @endforelse
                </div>
                <p class="text-xs text-base-content/40 mt-1">50 premiers résultats — affinez la recherche si besoin.</p>
            </x-molecules.modal.section>

            <x-molecules.modal.section title="Niveau d'accès" icon="fa-shield-halved text-primary" dense>
                @if ($assignType === \App\Models\WorkstationGroup::class)
                    <div class="alert alert-info py-2">
                        <i class="fa-solid fa-circle-info"></i>
                        <span class="text-sm">Un parc est un <strong>montage seul</strong> (aucune ACL POSIX) : le niveau d'accès ne s'applique pas.</span>
                    </div>
                @else
                    @php
                        $accessMeta = [
                            \App\Models\NetworkShareAssignable::ACCESS_RO => ['icon' => 'fa-eye', 'desc' => 'Consultation seule.'],
                            \App\Models\NetworkShareAssignable::ACCESS_RW => ['icon' => 'fa-pen', 'desc' => 'Lecture et écriture des fichiers.'],
                        ];
                    @endphp
                    <div class="grid grid-cols-2 gap-3">
                        @foreach (\App\Models\NetworkShareAssignable::ACCESS_LABELS as $val => $label)
                            @php($meta = $accessMeta[$val])
                            <button type="button" wire:click="$set('assignAccess', '{{ $val }}')"
                                class="flex items-start gap-3 rounded-lg border-2 p-3 text-left transition-all {{ $assignAccess === $val ? 'border-primary bg-primary/5 ring-1 ring-primary' : 'border-base-300 hover:border-base-content/30 hover:bg-base-200/50' }}">
                                <i class="fa-solid {{ $meta['icon'] }} text-lg mt-0.5 {{ $assignAccess === $val ? 'text-primary' : 'text-base-content/40' }}"></i>
                                <div class="min-w-0">
                                    <div class="font-medium text-sm flex items-center gap-1.5">
                                        {{ $label }}
                                        @if ($assignAccess === $val)
                                            <i class="fa-solid fa-circle-check text-primary text-xs"></i>
                                        @endif
                                    </div>
                                    <div class="text-xs text-base-content/60">{{ $meta['desc'] }}</div>
                                </div>
                            </button>
                        @endforeach
                    </div>
                @endif
            </x-molecules.modal.section>

            <x-slot:footer>
                <button type="button" class="btn btn-ghost" wire:click="closeAssign">Annuler</button>
                <button type="button" class="btn btn-primary" wire:click="addAssignment"
                    wire:loading.attr="disabled" wire:target="addAssignment" @disabled($assignTargetId === null)>
                    <span wire:loading.remove wire:target="addAssignment"><i class="fa-solid fa-plus"></i> Ajouter</span>
                    <span wire:loading wire:target="addAssignment"><span class="loading loading-spinner loading-xs"></span> Ajout...</span>
                </button>
            </x-slot:footer>
        </x-molecules.modal>
    @endcan
</x-organisms.page>

<?php

use App\Components\Traits\WithToasts;
use App\Models\Capability;
use App\Models\CapabilityOverrideAuditLog;
use App\Models\CapabilityProjection;
use App\Models\User;
use App\Models\UserGroup;
use App\Services\ControlHub\UpstreamLockResolver;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Story 35.4 — Section « Capacités » de la page d'un GROUPE D'UTILISATEURS.
 *
 * Transposition fidèle de l'onglet « Options / Capacités » du parc
 * ({@see resources/views/pages/parc/groups/_partials/capabilities-tab.blade.php},
 * 27.12→29.8) à la maille UserGroup : le référent numérique arme une CAPACITÉ pour
 * un groupe d'utilisateurs (élèves, direction, vie scolaire) en POSANT un override
 * de VALEUR de capacité (pivot polymorphe `capability_assignments`, assignable =
 * UserGroup). La maille UserGroup existe déjà de bout en bout côté données/moteur
 * (`resolveOverrides()`/`mailleFor()`/`specificity()`) : cette story n'expose QUE le
 * geste UI — StateCompiler, providers, agent et golden restent INCHANGÉS.
 *
 * DIFFÉRENCES ASSUMÉES avec le parc :
 *  - LISTING = TOUTES les capacités actives ASSIGNABLES (≥ 1 clé HKCU) avec, par
 *    groupe, la valeur d'override sinon « Suit le défaut » (exigence AC epic) — le
 *    parc ne liste QUE les overrides ;
 *  - FILTRE d'assignabilité (piège #6) : un override UserGroup ne mord qu'à travers
 *    le provider Session (ruche HKCU) — proposer une capacité machine-only (HKLM)
 *    poserait un override INERTE. On ne garde donc que les capacités dont la
 *    projection registry porte ≥ 1 clé `hive = HKCU` ;
 *  - GARDE de droit : gate INSTANCE-WIDE `customize-userGroup` ({@see App\Policies\GroupPolicy::customize()},
 *    droit global `app.customize`) — PAS de délégation par-UserGroup (le délégué
 *    par-salle est refusé, anti-piège 29.1) ;
 *  - Badges tri-état 29.4 = HORS scope (surface parc uniquement).
 *
 * À conserver À L'IDENTIQUE du parc (leçons 29.x) : `#[Locked]` + garde
 * serveur-autoritatif (mount ET chaque mutation), re-validation serveur `is_active`
 * + gel dérivé de l'EXISTENCE en base, `validatedValue()`, warning confirmé,
 * `old_value` lue AVANT la mutation, transaction acte↔trace, closure `updateOrInsert`
 * (created_at préservé), pas de trace fantôme au retrait, `resolveActor()`,
 * `upstream_status` via `UpstreamLockResolver`.
 */
new class extends Component {
    use WithToasts;

    /**
     * Groupe d'utilisateurs édité — passé par la page parente.
     *
     * Story 29.6 (transposé) — `#[Locked]` : le périmètre est SERVEUR-AUTORITATIF.
     * L'hydratation initiale via le paramètre du `mount` reste autorisée, mais toute
     * mutation côté client (`$set('groupId', …)` / payload falsifié) lève
     * `CannotUpdateLockedPropertyException`. Sans ce verrou, un rejeu re-ciblerait un
     * autre groupe (le gate `customize-userGroup` est instance-wide, mais l'ÉCRITURE
     * du pivot vise `$this->groupId` — le figer évite d'armer un groupe non voulu).
     */
    #[Locked]
    public int $groupId;

    /** Modale poser/éditer un override. */
    public bool $showOverrideModal = false;

    /** Capacité en cours de pose/édition (id) ; null = fermé. */
    public ?int $editingCapabilityId = null;

    /** Édite-t-on un override EXISTANT (true) ou en pose-t-on un (false) ? */
    public bool $isEditing = false;

    /** Valeur de capacité saisie (string côté formulaire). */
    public string $formValue = '';

    /** Confirmation explicite quand la capacité porte un `warning`. */
    public bool $warningAcknowledged = false;

    public function mount(int $groupId): void
    {
        // Story 29.6 (transposé) — assigner le périmètre AVANT le garde.
        $this->groupId = $groupId;
        $this->guardCustomize();
    }

    /**
     * TOUTES les capacités actives ASSIGNABLES par groupe d'utilisateurs (filtre
     * HKCU du piège #6), jointes aux overrides de CE groupe. Chaque ligne porte :
     * label/description/catégorie, `has_override` + `override_display` (libellé
     * d'option de la valeur effective), `default_display` (libellé d'option du
     * défaut), `has_warning`, `overrides_locked`, `is_upstream_locked`.
     *
     * @return array<int,array<string,mixed>>
     */
    #[Computed]
    public function capabilities(): array
    {
        // Capacités actives + leurs projections registry/registry_list Windows
        // (eager-load) pour le filtre HKCU calculé en PHP (pas de JSON query
        // SQLite-hostile). registry_list inclus (review 43.2 #1) : refreshHint()
        // agrège les hints des DEUX mécanismes — sans lui, une bi-projection dont
        // le hint ne vivrait que côté registry_list afficherait une temporalité
        // divergente des deux autres surfaces.
        $capabilities = Capability::query()
            ->where('is_active', true)
            ->with(['projections' => function ($q): void {
                $q->where('os', Capability::OS_WINDOWS)
                    ->whereIn('mechanism', [
                        CapabilityProjection::MECHANISM_REGISTRY,
                        CapabilityProjection::MECHANISM_REGISTRY_LIST,
                    ]);
            }])
            ->orderBy('label')
            ->get()
            ->filter(fn (Capability $c): bool => $this->isAssignableByUserGroup($c));

        if ($capabilities->isEmpty()) {
            return [];
        }

        // Overrides de CE groupe : capability_id → value (présence = override
        // existant, la value peut être null → repli sur le défaut). `array_key_exists`
        // distingue « pas d'override » d'« override à value null ».
        $overrides = [];
        foreach (
            DB::table('capability_assignments')
                ->where('assignable_type', UserGroup::class)
                ->where('assignable_id', $this->groupId)
                ->get(['capability_id', 'value']) as $row
        ) {
            $overrides[(int) $row->capability_id] = $row->value;
        }

        // Stories 29.2/29.4 — statut amont pré-calculé UNE fois (court-circuit NFR3),
        // resolver mémoïsé pré-instancié hors boucle (pas de N+1).
        $lock = app(UpstreamLockResolver::class);

        return $capabilities->map(function (Capability $c) use ($overrides, $lock): array {
            $hasOverride = array_key_exists((int) $c->id, $overrides);
            $overrideValue = $hasOverride ? $overrides[(int) $c->id] : null;
            $effective = $overrideValue ?? (string) $c->default_value;

            return [
                'id' => (int) $c->id,
                'label' => (string) $c->label,
                'description' => (string) ($c->description ?? ''),
                'category' => (string) ($c->category ?? ''),
                'has_override' => $hasOverride,
                'override_display' => $c->optionLabel((string) $effective),
                'default_display' => $c->optionLabel((string) $c->default_value),
                'has_warning' => $c->hasWarning(),
                'overrides_locked' => (bool) $c->overrides_locked,
                // Une capacité verrouillée amont ne peut être ni déviée ni éditée ici
                // (l'override serait défait au compilé — `Upstream` rang -1 — ET refusé
                // au serveur par `authorizeUpstream()`). En standalone, toujours false
                // (court-circuit NFR3).
                'is_upstream_locked' => $lock->isCapabilityLocked($c),
                // Story 43.2 (D5/D6) — temporalité d'effet ; null = aucun badge.
                // Dérivé sur la relation `projections` DÉJÀ eager-loaded (mécanisme
                // registry, filtre HKCU piège #6) — zéro requête ajoutée.
                'effect_timing' => $c->effectTiming(),
            ];
        })->values()->all();
    }

    /** Capacité en cours d'édition dans la modale (ou null). */
    #[Computed]
    public function editingCapability(): ?Capability
    {
        // Story 43.2 — `with('projections')` (filtre registry Windows, piège #6
        // déjà appliqué par isAssignableByUserGroup côté eager-load) : la
        // modale affiche AUSSI le badge de temporalité d'effet sans requête
        // ajoutée.
        return $this->editingCapabilityId !== null
            ? Capability::query()
                ->with(['projections' => function ($q): void {
                    $q->where('os', Capability::OS_WINDOWS)
                        ->whereIn('mechanism', [
                            CapabilityProjection::MECHANISM_REGISTRY,
                            CapabilityProjection::MECHANISM_REGISTRY_LIST,
                        ]);
                }])
                ->find($this->editingCapabilityId)
            : null;
    }

    /**
     * Ouvre la modale en mode POSE (« Dévier ») : pré-remplit avec le défaut de la
     * capacité. Refuse une capacité inactive / gelée / verrouillée amont.
     */
    public function openAdd(int $capabilityId): void
    {
        $this->guardCustomize();

        $capability = Capability::query()
            ->where('is_active', true)
            ->where('overrides_locked', false)
            ->findOrFail($capabilityId);

        // Review 35.4 #1 (piège #6) : l'assignabilité HKCU ne peut pas vivre
        // seulement dans le listing — un rejeu Livewire direct poserait un
        // override machine-only qui ne mordra JAMAIS (silencieusement inerte).
        if (! $this->isAssignableByUserGroup($capability)) {
            $this->toastError('Cette capacité ne peut pas être ciblée par groupe d\'utilisateurs (aucune clé de portée session).');
            return;
        }

        if (! $this->authorizeUpstream($capability)) {
            return;
        }

        $this->resetForm();
        $this->editingCapabilityId = (int) $capability->id;
        $this->isEditing = false;
        $this->formValue = (string) $capability->default_value;
        $this->showOverrideModal = true;
    }

    /** Ouvre la modale en mode ÉDITION : pré-remplit avec l'override actuel. */
    public function openEdit(int $capabilityId): void
    {
        $this->guardCustomize();

        $capability = Capability::query()->findOrFail($capabilityId);

        if (! $this->authorizeUpstream($capability)) {
            return;
        }

        $current = DB::table('capability_assignments')
            ->where('assignable_type', UserGroup::class)
            ->where('assignable_id', $this->groupId)
            ->where('capability_id', $capabilityId)
            ->value('value');

        $this->resetForm();
        $this->editingCapabilityId = (int) $capability->id;
        $this->isEditing = true;
        $this->formValue = (string) ($current ?? $capability->default_value);
        $this->showOverrideModal = true;
    }

    public function closeModal(): void
    {
        $this->resetForm();
        $this->showOverrideModal = false;
    }

    /**
     * Persiste l'override : valide la valeur saisie contre `value_type`/`options`,
     * exige la confirmation du `warning`, puis upsert la colonne `value` du pivot
     * (assignable = UserGroup). Audit dans la MÊME transaction.
     */
    public function saveOverride(): void
    {
        $this->guardCustomize();

        // Re-validation SERVEUR (piège #3) : `is_active`/`overrides_locked` ne peuvent
        // vivre seulement en front. On recharge la capacité filtrée `is_active` et on
        // dérive « nouvel override » de l'EXISTENCE EN BASE (pas du flag client) :
        // une capacité gelée DÉJÀ overridée reste éditable, mais aucune gelée ne peut
        // RECEVOIR un nouvel override.
        if ($this->editingCapabilityId === null) {
            $this->toastError('Capacité introuvable.');
            return;
        }

        $capability = Capability::query()
            ->where('is_active', true)
            ->find($this->editingCapabilityId);

        if ($capability === null) {
            $this->toastError('Capacité introuvable ou inactive.');
            return;
        }

        // Review 35.4 #1 (piège #6) — defense-in-depth : même garde qu'openAdd,
        // un override UserGroup sur une capacité sans clé HKCU est inerte.
        if (! $this->isAssignableByUserGroup($capability)) {
            $this->toastError('Cette capacité ne peut pas être ciblée par groupe d\'utilisateurs (aucune clé de portée session).');
            return;
        }

        // Story 29.2 (transposé) — verrou amont (defense-in-depth), refus SERVEUR même
        // si l'UI est contournée (rejeu Livewire).
        if (! $this->authorizeUpstream($capability)) {
            return;
        }

        $hasExistingOverride = DB::table('capability_assignments')
            ->where('assignable_type', UserGroup::class)
            ->where('assignable_id', $this->groupId)
            ->where('capability_id', $capability->id)
            ->exists();

        if ($capability->overrides_locked && ! $hasExistingOverride) {
            $this->toastError('Cette capacité est gelée : aucun nouvel override par groupe n\'est autorisé.');
            return;
        }

        if ($capability->hasWarning() && ! $this->warningAcknowledged) {
            $this->addError('warningAcknowledged', 'Vous devez confirmer avoir lu les implications de cette capacité.');
            return;
        }

        $value = $this->validatedValue($capability);

        $group = UserGroup::query()->findOrFail($this->groupId);

        // Story 29.5 (NFR5) — old_value lue AVANT la mutation (sinon perdue).
        $oldValue = DB::table('capability_assignments')
            ->where('assignable_type', UserGroup::class)
            ->where('assignable_id', $group->id)
            ->where('capability_id', $capability->id)
            ->value('value');

        // Statut amont au moment de l'acte (un `locked` n'arrive jamais ici — refusé
        // par authorizeUpstream). Resolver mémoïsé : court-circuit NFR3 préservé.
        $upstreamStatus = app(UpstreamLockResolver::class)->isCapabilityPermissive($capability)
            ? CapabilityOverrideAuditLog::UPSTREAM_PERMISSIVE
            : CapabilityOverrideAuditLog::UPSTREAM_LOCAL;

        [$actorId, $actorLogin] = $this->resolveActor();

        // Story 29.5 (NFR5) — atomicité acte ↔ trace : mutation du pivot ET écriture
        // d'audit dans une MÊME transaction. `action` dérivée de l'EXISTENCE EN BASE.
        DB::transaction(function () use ($capability, $group, $value, $oldValue, $hasExistingOverride, $upstreamStatus, $actorId, $actorLogin): void {
            // Story 29.7 — closure INSERT vs UPDATE : sur UPDATE, `created_at` du pivot
            // n'est PAS réécrit ; sur INSERT, il est posé à now().
            DB::table('capability_assignments')->updateOrInsert(
                [
                    'capability_id' => $capability->id,
                    'assignable_type' => UserGroup::class,
                    'assignable_id' => $group->id,
                ],
                fn (bool $exists) => $exists
                    ? ['value' => $value, 'updated_at' => now()]
                    : ['value' => $value, 'created_at' => now(), 'updated_at' => now()],
            );

            CapabilityOverrideAuditLog::log(
                action: $hasExistingOverride
                    ? CapabilityOverrideAuditLog::ACTION_UPDATE
                    : CapabilityOverrideAuditLog::ACTION_CREATE,
                actorUserId: $actorId,
                actorLogin: $actorLogin,
                capabilityId: (int) $capability->id,
                capabilityLabel: (string) $capability->label,
                assignableType: UserGroup::class,
                assignableId: (int) $group->id,
                scopeLabel: (string) ($group->display_name ?? $group->name),
                oldValue: $oldValue !== null ? (string) $oldValue : null,
                newValue: $value,
                upstreamStatus: $upstreamStatus,
            );
        });

        $this->toastSuccess($this->isEditing
            ? 'Override mis à jour pour ce groupe.'
            : 'Override ajouté pour ce groupe.');

        $this->closeModal();
        unset($this->capabilities);
    }

    /** Retire l'override = REVENIR AU DÉFAUT (re-convergence au cycle suivant). */
    public function removeOverride(int $capabilityId): void
    {
        $this->guardCustomize();

        // Story 29.2 (transposé) — bloquer AUSSI le retrait d'un item verrouillé amont
        // (UX « refus explicite » cohérente ; le retrait serait de toute façon inerte).
        $capability = Capability::query()->find($capabilityId);
        if ($capability !== null && ! $this->authorizeUpstream($capability)) {
            return;
        }

        $group = UserGroup::query()->find($this->groupId);

        // Story 29.5 (NFR5) — pas de TRACE FANTÔME : si aucun override n'existe pour ce
        // périmètre (rejeu / appel direct), aucun acte → aucune trace. `first()`
        // distingue l'absence de ligne d'une ligne à `value` null (colonne nullable).
        $existing = DB::table('capability_assignments')
            ->where('assignable_type', UserGroup::class)
            ->where('assignable_id', $this->groupId)
            ->where('capability_id', $capabilityId)
            ->first(['value']);

        if ($existing === null) {
            return;
        }

        // Ancienne valeur lue AVANT le delete.
        $oldValue = $existing->value;

        // Statut amont (court-circuit NFR3 préservé). `null` capability → local.
        $upstreamStatus = ($capability !== null
            && app(UpstreamLockResolver::class)->isCapabilityPermissive($capability))
            ? CapabilityOverrideAuditLog::UPSTREAM_PERMISSIVE
            : CapabilityOverrideAuditLog::UPSTREAM_LOCAL;

        [$actorId, $actorLogin] = $this->resolveActor();

        // Story 29.5 (NFR5) — atomicité acte ↔ trace : delete + audit `delete`
        // (new_value = null) dans une MÊME transaction.
        DB::transaction(function () use ($capability, $group, $capabilityId, $oldValue, $upstreamStatus, $actorId, $actorLogin): void {
            DB::table('capability_assignments')
                ->where('assignable_type', UserGroup::class)
                ->where('assignable_id', $this->groupId)
                ->where('capability_id', $capabilityId)
                ->delete();

            CapabilityOverrideAuditLog::log(
                action: CapabilityOverrideAuditLog::ACTION_DELETE,
                actorUserId: $actorId,
                actorLogin: $actorLogin,
                capabilityId: $capability?->id,
                capabilityLabel: (string) ($capability?->label ?? ''),
                assignableType: UserGroup::class,
                assignableId: (int) $this->groupId,
                scopeLabel: $group !== null ? (string) ($group->display_name ?? $group->name) : null,
                oldValue: $oldValue !== null ? (string) $oldValue : null,
                newValue: null,
                upstreamStatus: $upstreamStatus,
            );
        });

        $this->toastSuccess('Override retiré — le groupe revient à la valeur par défaut (réappliquée au cycle suivant).');
        unset($this->capabilities);
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    /**
     * Piège #6 — « assignable par groupe d'utilisateurs » = la projection registry
     * Windows de la capacité porte ≥ 1 clé `hive = HKCU` (insensible à la casse).
     * Un override UserGroup ne mord qu'à travers le provider Session (ruche HKCU) :
     * une capacité 100 % HKLM (machine-only) poserait un override INERTE. Calcul en
     * PHP sur la projection eager-loadée (pas de JSON query SQLite-hostile).
     */
    private function isAssignableByUserGroup(Capability $capability): bool
    {
        // Seul le mécanisme registry définit l'assignabilité par groupe-user
        // (inchangé) — l'eager-load charge aussi registry_list pour le badge de
        // temporalité, d'où le filtre explicite ici (review 43.2 #1).
        foreach ($capability->projections as $projection) {
            if ($projection->mechanism !== CapabilityProjection::MECHANISM_REGISTRY) {
                continue;
            }

            $spec = $projection->spec;
            $keys = is_array($spec) && isset($spec['keys']) && is_array($spec['keys'])
                ? $spec['keys']
                : [];

            foreach ($keys as $key) {
                if (is_array($key)
                    && strcasecmp((string) ($key['hive'] ?? ''), CapabilityProjection::HIVE_USER) === 0) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Story 29.5 (NFR5) — acteur de l'audit : id (FK) + login DÉNORMALISÉ. Guard
     * `instanceof User` pour l'intégrité de la FK `actor_user_id`.
     *
     * @return array{0:int|null,1:string|null}
     */
    private function resolveActor(): array
    {
        $user = Auth::user();

        if ($user instanceof User) {
            return [(int) $user->getKey(), (string) $user->login];
        }

        return [null, null];
    }

    /**
     * Valide la valeur saisie contre `value_type`/`options` (SQLite n'applique aucune
     * contrainte — validation serveur obligatoire). Lève une ValidationException
     * propre (jamais d'exception au render).
     */
    private function validatedValue(Capability $capability): string
    {
        $value = trim($this->formValue);

        // Choix fermé (toggle/enum) : la valeur doit appartenir aux options.
        if ($capability->hasOptions()) {
            if (! in_array($value, $capability->allowedOptionValues(), true)) {
                throw ValidationException::withMessages([
                    'formValue' => 'Choisissez une valeur parmi les options proposées.',
                ]);
            }

            return $value;
        }

        // Scalaire : non vide.
        if ($value === '') {
            throw ValidationException::withMessages([
                'formValue' => 'La valeur ne peut pas être vide.',
            ]);
        }

        return $value;
    }

    private function resetForm(): void
    {
        $this->editingCapabilityId = null;
        $this->isEditing = false;
        $this->formValue = '';
        $this->warningAcknowledged = false;
        $this->resetErrorBag();
    }

    /**
     * Story 35.4 — garde d'autorisation SERVEUR-AUTORITATIF. Gate INSTANCE-WIDE
     * `customize-userGroup` ({@see App\Policies\GroupPolicy::customize()}) exigeant le
     * droit GLOBAL `app.customize`. PAS de délégation par-UserGroup dans le modèle :
     * un délégué par-salle (droit scopé seulement) est REFUSÉ ici (anti-piège 29.1).
     * Voir le docblock du gate pour le raisonnement « instance = établissement » et le
     * point d'extension unique.
     */
    private function guardCustomize(): void
    {
        abort_unless(
            auth()->check() && Gate::allows('customize-userGroup'),
            403,
            'Permission app.customize requise pour armer une capacité par groupe d\'utilisateurs.',
        );
    }

    /**
     * Story 29.2 (transposé) — garde de VERROU AMONT (defense-in-depth). `app.customize`
     * est DÉJÀ vérifié par guardCustomize() ; ici le seul motif de refus du gate
     * `modify-capability` est le verrou amont. Refus = toast explicite + arrêt de la
     * mutation (retourne false).
     */
    private function authorizeUpstream(Capability $capability): bool
    {
        try {
            Gate::authorize('modify-capability', $capability);

            return true;
        } catch (AuthorizationException) {
            if (app(UpstreamLockResolver::class)->isCapabilityLocked($capability)) {
                $this->toastError('Cette capacité est verrouillée par un contrat amont et ne peut pas être modifiée localement.');
            } else {
                $this->toastError("Vous n'avez pas le droit de modifier cette capacité.");
            }

            return false;
        }
    }
};
?>

<div class="space-y-6 mt-4">
    <div class="alert alert-info shadow-sm">
        <i class="fa-solid fa-circle-info"></i>
        <div>
            <p class="font-medium">Capacités du groupe d'utilisateurs</p>
            <p class="text-sm opacity-80">
                Chaque capacité a une <strong>valeur par défaut</strong>. Ici vous <strong>déviez</strong> certaines
                capacités pour les membres de ce groupe uniquement (élèves, direction, vie scolaire…). Les capacités
                non déviées <strong>suivent le défaut</strong>.
                <strong>Retirer un override = revenir à la valeur par défaut</strong> (réappliquée au cycle suivant,
                PAS « cesser de gérer »).
            </p>
        </div>
    </div>

    <div class="card bg-base-100 shadow-sm border border-base-300">
        <div class="card-body">
            <h2 class="card-title text-base">Capacités assignables à ce groupe</h2>

            <div class="overflow-x-auto">
                <table class="table table-sm">
                    <thead>
                        <tr>
                            <th>Capacité</th>
                            <th>Catégorie</th>
                            <th>Valeur pour ce groupe</th>
                            <th>Défaut</th>
                            <th class="text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($this->capabilities as $capability)
                            <tr>
                                <td>
                                    <div class="font-medium flex items-center gap-1 flex-wrap">
                                        {{ $capability['label'] }}
                                        @if ($capability['has_warning'])
                                            <i class="fa-solid fa-triangle-exclamation text-warning text-xs"
                                                aria-label="Capacité sensible"></i>
                                        @endif
                                        {{-- Story 43.2 (D5/D6) — badge de temporalité d'effet. --}}
                                        @if ($capability['effect_timing'] !== null)
                                            <span class="badge badge-sm badge-outline gap-1"
                                                data-testid="effect-timing-{{ $capability['id'] }}"
                                                title="{{ $capability['effect_timing']['tooltip'] }}">
                                                <i class="fa-solid fa-clock text-xs"></i>
                                                {{ $capability['effect_timing']['label'] }}
                                            </span>
                                        @endif
                                    </div>
                                    @if ($capability['description'] !== '')
                                        <div class="text-sm opacity-70">{{ $capability['description'] }}</div>
                                    @endif
                                </td>
                                <td class="text-xs opacity-60">{{ $capability['category'] }}</td>
                                <td class="font-medium">
                                    @if ($capability['has_override'])
                                        {{ $capability['override_display'] }}
                                    @else
                                        <span class="opacity-60 italic">Suit le défaut
                                            ({{ $capability['default_display'] }})</span>
                                    @endif
                                </td>
                                <td class="text-xs opacity-60">{{ $capability['default_display'] }}</td>
                                <td class="text-right whitespace-nowrap">
                                    @if ($capability['is_upstream_locked'])
                                        <span class="text-xs opacity-60 italic"
                                            data-testid="upstream-locked-{{ $capability['id'] }}">Imposé par contrat amont</span>
                                    @elseif ($capability['has_override'])
                                        <button type="button" class="btn btn-ghost btn-xs"
                                            wire:click="openEdit({{ $capability['id'] }})"
                                            data-testid="edit-override-{{ $capability['id'] }}">
                                            <i class="fa-solid fa-pen"></i> Éditer
                                        </button>
                                        <button type="button" class="btn btn-ghost btn-xs text-error"
                                            wire:click="removeOverride({{ $capability['id'] }})"
                                            data-testid="remove-override-{{ $capability['id'] }}">
                                            <i class="fa-solid fa-rotate-left"></i> Retirer
                                        </button>
                                    @elseif ($capability['overrides_locked'])
                                        <span class="text-xs opacity-60 italic"
                                            data-testid="frozen-{{ $capability['id'] }}">Gelée — lecture seule</span>
                                    @else
                                        <button type="button" class="btn btn-ghost btn-xs text-primary"
                                            wire:click="openAdd({{ $capability['id'] }})"
                                            data-testid="open-add-{{ $capability['id'] }}">
                                            <i class="fa-solid fa-plus"></i> Dévier
                                        </button>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="text-center opacity-60 py-6">
                                    Aucune capacité assignable par groupe d'utilisateurs.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    {{-- Modale réutilisable : poser / éditer un override (ouverte pré-remplie). --}}
    <x-molecules.modal wire:model="showOverrideModal"
        title="{{ $isEditing ? 'Éditer l\'override' : 'Dévier une capacité' }}"
        icon="fa-pen-to-square text-primary"
        size="max-w-2xl" height="h-auto max-h-[85vh]"
        closeMethod="closeModal">

        @if ($this->editingCapability !== null)
            @php($capability = $this->editingCapability)
            {{-- Saisie de la valeur d'override (contrôle adapté au value_type). --}}
            <x-molecules.modal.section title="{{ $capability->label }}">
                @if ($capability->description)
                    <p class="text-sm opacity-70 mb-2">{{ $capability->description }}</p>
                @endif
                {{-- Story 43.2 (D5/D6) — badge de temporalité d'effet. --}}
                @php($timing = $capability->effectTiming())
                @if ($timing !== null)
                    <span class="badge badge-sm badge-outline gap-1 mb-2" title="{{ $timing['tooltip'] }}">
                        <i class="fa-solid fa-clock text-xs"></i> {{ $timing['label'] }}
                    </span>
                @endif

                <label class="form-control w-full">
                    {{-- UX forms (piège #11) : label AU-DESSUS, étoile sur l'obligatoire. --}}
                    <span class="label-text mb-1">
                        Valeur pour ce groupe <span class="text-error" aria-hidden="true">*</span>
                    </span>

                    @if ($capability->hasOptions())
                        {{-- Toggle / enum : sélecteur. --}}
                        <select class="select select-bordered w-full" wire:model="formValue"
                            data-testid="override-select" required>
                            <option value="" disabled>— Choisir —</option>
                            @foreach ($capability->options as $opt)
                                <option value="{{ $opt['value'] }}">{{ $opt['label'] }}</option>
                            @endforeach
                        </select>
                    @else
                        {{-- Scalaire : texte. --}}
                        <input type="text" class="input input-bordered w-full"
                            wire:model="formValue" data-testid="override-text" required />
                    @endif

                    @error('formValue')
                        <span class="text-error text-sm mt-1">{{ $message }}</span>
                    @enderror
                </label>
            </x-molecules.modal.section>

            {{-- Encart de warning : confirmation explicite avant persistance. --}}
            @if ($capability->hasWarning())
                <x-molecules.modal.section>
                    <div class="alert alert-warning">
                        <i class="fa-solid fa-triangle-exclamation"></i>
                        <div class="text-sm">{{ $capability->warning }}</div>
                    </div>
                    <label class="label cursor-pointer justify-start gap-2 mt-2">
                        <input type="checkbox" class="checkbox checkbox-warning checkbox-sm"
                            wire:model="warningAcknowledged" data-testid="ack-warning" />
                        <span class="label-text">J'ai lu et compris les implications de cette capacité.</span>
                    </label>
                    @error('warningAcknowledged')
                        <span class="text-error text-sm">{{ $message }}</span>
                    @enderror
                </x-molecules.modal.section>
            @endif
        @endif

        <x-slot:footer>
            <button type="button" class="btn btn-ghost" wire:click="closeModal">Annuler</button>
            @if ($this->editingCapability !== null)
                <button type="button" class="btn btn-primary" wire:click="saveOverride" data-testid="save-override">
                    Enregistrer
                </button>
            @endif
        </x-slot:footer>
    </x-molecules.modal>
</div>

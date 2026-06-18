<?php

use App\Components\Traits\WithToasts;
use App\Models\Application;
use App\Models\FileAssociation;
use App\Models\NativeApplication;
use App\Models\WorkstationGroup;
use App\Services\Agent\Resolvers\AssociationResolver;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Story 27.11 — Onglet « Associations par défaut » de la page d'un WorkstationGroup,
 * V2 COMPOSER (refonte de l'onglet 27.3bis, qui n'offrait que des toggles d'un
 * catalogue figé).
 *
 * L'admin SAISIT une extension/protocole (`.clclcc`, `mailto`…) et CHOISIT une
 * application PAR SON NOM dans un dropdown (apps WPKG installées + natives Win32
 * curées). Le {@see AssociationResolver} (serveur) traduit ce choix en cible
 * technique *(progid, source, wpkg_package)* — ProgId RICHE si le paquet le déclare
 * pour l'extension, sinon GÉNÉRIQUE `Applications\<exe>` — et upsert une ligne
 * `file_associations` attachée au parc (provider/handler/hash de 27.3bis réutilisés
 * tels quels). L'admin ne touche JAMAIS un ProgId ni un hash.
 *
 * **Garde-fou exe manquant (piège n°4).** Une (extension, app) sans ProgId riche ET
 * sans exécutable connu est REFUSÉE (toast d'erreur) — pas de générique sans exe.
 *
 * **Validation PRÉDICTIVE par parc (D-Henri n°4/n°7, AC5)** : chaque ligne porte une
 * `source` ; `native` → toujours applicable ; `wpkg` & paquet déployé → applicable ;
 * `wpkg` & paquet NON déployé → `unavailable` (badge + tooltip nommant le paquet +
 * toast). Le calcul des paquets déployés du parc est une requête **group-level
 * Eloquent, PG-pure, SANS le cache APCu** de `WorkstationPackagesResolver` (NFR7) —
 * jamais dans le `AssociationsStateProvider` (qui émet toujours).
 *
 * Gate `app.customize`. Granularité PAR PARC (page WorkstationGroup) inchangée.
 */
new class extends Component {
    use WithToasts;

    /** WorkstationGroup (parc/salle) édité — passé par la page parente. */
    public int $groupId;

    /** Saisie composer : extension (`.xxx`) ou protocole (ex. `mailto`). */
    public string $newIdentifier = '';

    /** Choix composer : référence d'app du dropdown (`wpkg:<id>` | `native:<id>`). */
    public string $newAppRef = '';

    public function mount(int $groupId): void
    {
        abort_unless(
            auth()->check() && auth()->user()->can('app.customize'),
            403,
            'Permission app.customize requise.',
        );

        $this->groupId = $groupId;
    }

    /**
     * Applications proposées au dropdown, PAR NOM, avec icône :
     *   - Source 1 : apps WPKG (`Application` : `name`, `icon_url`) ;
     *   - Source 2 : natives Win32 curées (`NativeApplication` : `label`).
     * Réf opaque `wpkg:<id>` / `native:<id>` pour la résolution serveur.
     *
     * @return array<int,array<string,mixed>>
     */
    #[Computed]
    public function appOptions(): array
    {
        $wpkg = Application::query()
            ->orderBy('name')
            ->get(['id', 'name', 'icon_url', 'executable'])
            ->map(fn (Application $a): array => [
                'ref' => 'wpkg:' . $a->id,
                'name' => (string) $a->name,
                'icon_url' => $a->icon_url !== null ? (string) $a->icon_url : null,
                'kind' => 'wpkg',
            ])
            ->all();

        $native = NativeApplication::query()
            ->orderBy('label')
            ->get(['id', 'label', 'icon_url'])
            ->map(fn (NativeApplication $n): array => [
                'ref' => 'native:' . $n->id,
                'name' => (string) $n->label,
                'icon_url' => $n->icon_url !== null ? (string) $n->icon_url : null,
                'kind' => 'native',
            ])
            ->all();

        return [...$native, ...$wpkg];
    }

    /**
     * Associations ASSIGNÉES au parc courant (lignes éditables/désactivables) +
     * statut PRÉDICTIF d'applicabilité (`applicable` | `unavailable`). Les défauts
     * legacy seedés (27.3bis) y figurent dès qu'ils sont attachés au parc.
     *
     * @return array<int,array<string,mixed>>
     */
    #[Computed]
    public function associations(): array
    {
        $assignedIds = $this->assignedAssociationIds();
        if ($assignedIds === []) {
            return [];
        }
        $deployedPackages = $this->deployedPackagesForParc($this->groupId);

        return FileAssociation::query()
            ->whereIn('id', $assignedIds)
            ->orderBy('identifier')
            ->orderBy('label')
            ->get()
            ->map(function (FileAssociation $a) use ($deployedPackages): array {
                return [
                    'id' => (int) $a->id,
                    'label' => (string) $a->label,
                    'description' => (string) ($a->description ?? ''),
                    'identifier' => (string) $a->identifier,
                    'assoc_type' => (string) $a->assoc_type,
                    'progid' => (string) $a->progid,
                    'source' => (string) $a->source,
                    'wpkg_package' => $a->wpkg_package !== null ? (string) $a->wpkg_package : null,
                    'availability' => $this->availabilityFor($a, $deployedPackages),
                    'generic' => $a->isGeneric(),
                ];
            })
            ->all();
    }

    /**
     * Statut PRÉDICTIF d'une association sur le parc courant (AC5) :
     *   - GÉNÉRIQUE (`Applications\<exe>`, quelle que soit la `source`) →
     *     `best-effort` : l'association sera tentée mais dépend de l'app réellement
     *     installée/résoluble sur le poste (le serveur ne peut pas le garantir) ;
     *   - native NON générique (ProgId canonique, ex. `txtfile`) → `applicable` ;
     *   - wpkg riche : paquet déployé → `applicable`, sinon → `unavailable`.
     *
     * @param  list<string>  $deployedPackages
     */
    private function availabilityFor(FileAssociation $a, array $deployedPackages): string
    {
        if ($a->isGeneric()) {
            return 'best-effort';
        }

        $available = $a->isNative()
            || ($a->wpkg_package !== null && in_array($a->wpkg_package, $deployedPackages, true));

        return $available ? 'applicable' : 'unavailable';
    }

    /**
     * Compose une nouvelle association : valide la saisie, résout via
     * {@see AssociationResolver}, upsert la ligne `file_associations` et l'attache
     * au parc. Garde-fou exe manquant (piège n°4) → toast d'erreur, rien créé.
     */
    public function compose(AssociationResolver $resolver): void
    {
        $this->validate([
            'newIdentifier' => ['required', 'string', 'max:64', 'regex:/^(\.[A-Za-z0-9][A-Za-z0-9._-]*|[A-Za-z][A-Za-z0-9+.-]*)$/'],
            'newAppRef' => ['required', 'string', 'regex:/^(wpkg|native):\d+$/'],
        ], [
            'newIdentifier.regex' => 'Saisissez une extension (ex. .pdf) ou un protocole (ex. mailto).',
            'newAppRef.required' => 'Choisissez une application.',
            'newAppRef.regex' => 'Application invalide.',
        ]);

        $identifier = trim($this->newIdentifier);
        $assocType = str_starts_with($identifier, '.')
            ? FileAssociation::ASSOC_TYPE_FILE
            : FileAssociation::ASSOC_TYPE_PROTOCOL;

        $app = $this->resolveAppFromRef($this->newAppRef);
        if ($app === null) {
            $this->toastError('Application introuvable.');

            return;
        }

        $parc = WorkstationGroup::query()->findOrFail($this->groupId);

        try {
            $association = $resolver->compose($identifier, $assocType, $app, $parc);
        } catch (\InvalidArgumentException $e) {
            // Garde-fou n°4 : générique requis (aucun ProgId riche déclaré) mais
            // l'app n'a pas d'exécutable connu → composition refusée.
            $this->toastError(
                'Cette application n\'a pas d\'exécutable connu et ne déclare pas cette extension : '
                . 'impossible de composer l\'association (renseignez l\'exécutable de l\'application).',
                'Association impossible',
            );

            return;
        }

        // Statut prédictif EXACT à la création (iso 27.3bis) : si le ProgId vient
        // d'un paquet WPKG NON déployé sur ce parc, l'association échouera ici. Un
        // ProgId GÉNÉRIQUE est « best-effort » (dépend de l'app installée — AC5).
        $deployedPackages = $this->deployedPackagesForParc($parc->id);
        $availability = $this->availabilityFor($association, $deployedPackages);

        if ($availability === 'unavailable') {
            $this->toastWarning(
                '« ' . ($association->wpkg_package ?? '?') . ' » n\'est pas déployé sur ce parc → cette '
                . 'association échouera ici (l\'agent rapportera une erreur, le choix utilisateur reste préservé).',
                'Association ajoutée mais indisponible',
            );
        } elseif ($availability === 'best-effort') {
            $this->toastInfo(
                'Association « ' . $association->identifier . ' → ' . $association->progid . ' » ajoutée au parc '
                . '(best-effort : ProgId générique, l\'association sera tentée mais dépend de l\'application réellement '
                . 'installée sur le poste).',
                'Association ajoutée (best-effort)',
            );
        } else {
            $this->toastSuccess('Association « ' . $association->identifier . ' → ' . $association->progid . ' » ajoutée au parc.');
        }

        // Sémantique EXCLUSIVE de l'agent (piège n°7) : le compilateur
        // (`StateCompiler::selectExclusive`, clé = `strtolower(identifier)`) n'applique
        // qu'UNE association par identifier. Décision Henri (2026-06-18, Q2) :
        // REMPLACEMENT AUTOMATIQUE — on détache du parc les associations concurrentes
        // (même identifier, ProgId DIFFÉRENT) pour que la nouvelle prenne effet
        // immédiatement (le choix déjà appliqué côté poste reste, iso piège n°5).
        $replacedIds = array_values(array_filter(
            $this->attachedAssociationIdsForIdentifier($identifier),
            fn (int $id): bool => $id !== (int) $association->id,
        ));
        if ($replacedIds !== []) {
            foreach (FileAssociation::query()->whereIn('id', $replacedIds)->get() as $old) {
                $old->workstationGroups()->detach($parc->id);
            }
            $this->toastInfo(
                count($replacedIds) === 1
                    ? 'L\'association précédente pour ' . $identifier . ' a été remplacée sur ce parc (règle exclusive).'
                    : count($replacedIds) . ' associations précédentes pour ' . $identifier . ' ont été remplacées sur ce parc (règle exclusive).',
                'Association précédente remplacée',
            );
        }

        $this->reset('newIdentifier', 'newAppRef');
        unset($this->associations);
    }

    /**
     * Désactive une association pour le parc = la détacher = cesser de la gérer
     * (iso 27.3bis : le choix déjà appliqué reste, PAS de reset OFF — piège n°5).
     */
    public function disable(int $associationId): void
    {
        // L'asso DOIT être réellement attachée au parc courant avant de détacher
        // (piège n°4 : sinon `detach` est un no-op et le toast « retirée » ment).
        if (! in_array($associationId, $this->assignedAssociationIds(), true)) {
            $this->toastError('Association introuvable sur ce parc.');

            return;
        }

        $association = FileAssociation::query()->findOrFail($associationId);
        $parc = WorkstationGroup::query()->findOrFail($this->groupId);

        $association->workstationGroups()->detach($parc->id);
        $this->toastSuccess('Association retirée du parc (le choix déjà appliqué reste en place).');

        unset($this->associations);
    }

    /** Résout la réf de dropdown en modèle d'app (WPKG ou native). */
    private function resolveAppFromRef(string $ref): Application|NativeApplication|null
    {
        [$kind, $id] = array_pad(explode(':', $ref, 2), 2, '');
        $id = (int) $id;

        return match ($kind) {
            'wpkg' => Application::query()->find($id),
            'native' => NativeApplication::query()->find($id),
            default => null,
        };
    }

    /**
     * Ids des associations DÉJÀ attachées au parc courant pour un `identifier` donné
     * (comparaison insensible à la casse, comme Windows). Sert le remplacement
     * automatique des associations concurrentes (sémantique exclusive de l'agent,
     * piège n°7 ; décision Henri Q2).
     *
     * @return list<int>
     */
    private function attachedAssociationIdsForIdentifier(string $identifier): array
    {
        $assignedIds = $this->assignedAssociationIds();
        if ($assignedIds === []) {
            return [];
        }

        return FileAssociation::query()
            ->whereIn('id', $assignedIds)
            ->whereRaw('LOWER(identifier) = ?', [strtolower($identifier)])
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * Ids des associations assignées au groupe courant (une requête pivot).
     *
     * @return list<int>
     */
    private function assignedAssociationIds(): array
    {
        return DB::table('file_association_assignables')
            ->where('assignable_type', WorkstationGroup::class)
            ->where('assignable_id', $this->groupId)
            ->pluck('file_association_id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * Ensemble des `app_id` (= `<package id>` WPKG, clé de jointure) déployés sur UN
     * parc — requête **group-level Eloquent, PG-pure, SANS cache APCu** (NFR7).
     * Réutilise la logique de validation prédictive de 27.3bis. Sources : AppProfiles
     * du parc → leurs Applications ; Applications directes du parc ; dépendances
     * applicatives transitives (BFS sur `application_dependencies`).
     *
     * @return list<string>  app_id déployés, dédupliqués.
     */
    private function deployedPackagesForParc(int $parcId): array
    {
        /** @var WorkstationGroup|null $group */
        $group = WorkstationGroup::query()
            ->whereNull('archived_at')
            ->with([
                'appProfiles' => fn ($q) => $q->whereNull('archived_at'),
                'appProfiles.applications:id,app_id',
                'applications:id,app_id',
            ])
            ->find($parcId);

        if ($group === null) {
            return [];
        }

        $appIds = collect();
        $applicationIds = collect();

        foreach ($group->appProfiles as $profile) {
            foreach ($profile->applications as $app) {
                $appIds->push($app->app_id);
                $applicationIds->push($app->id);
            }
        }

        foreach ($group->applications as $app) {
            $appIds->push($app->app_id);
            $applicationIds->push($app->id);
        }

        $allIds = $this->collectDependenciesTransitive($applicationIds->unique()->all());
        $rootIds = $applicationIds->unique()->all();
        $depIds = array_values(array_diff($allIds, $rootIds));
        if ($depIds !== []) {
            $appIdsFromDeps = Application::query()
                ->whereIn('id', $depIds)
                ->pluck('app_id');
            $appIds = $appIds->concat($appIdsFromDeps);
        }

        return $appIds
            ->filter(fn ($v): bool => is_string($v) && $v !== '')
            ->map(fn ($v): string => (string) $v)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * BFS des dépendances applicatives transitives (PG-pur, query builder).
     *
     * @param  list<int>  $rootIds
     * @return list<int>
     */
    private function collectDependenciesTransitive(array $rootIds): array
    {
        if ($rootIds === []) {
            return [];
        }

        $visited = array_flip($rootIds);
        $queue = $rootIds;

        while ($queue !== []) {
            $batch = $queue;
            $queue = [];

            $children = DB::table('application_dependencies')
                ->whereIn('application_id', $batch)
                ->pluck('required_application_id')
                ->all();

            foreach ($children as $childId) {
                $childId = (int) $childId;
                if (! array_key_exists($childId, $visited)) {
                    $visited[$childId] = true;
                    $queue[] = $childId;
                }
            }
        }

        return array_keys($visited);
    }
};
?>

<div class="space-y-6 mt-4">
    <div class="alert alert-info shadow-sm">
        <i class="fa-solid fa-circle-info"></i>
        <div>
            <p class="font-medium">Composer une association par défaut</p>
            <p class="text-sm opacity-80">
                Saisissez une <strong>extension</strong> (ex. <code>.pdf</code>) ou un
                <strong>protocole</strong> (ex. <code>mailto</code>), puis choisissez l'application qui
                l'ouvrira. Le serveur traduit votre choix en cible technique : l'agent l'impose au logon.
                <strong>Retirer une association = cesser de la gérer</strong> : le choix déjà appliqué reste
                en place. Une association dont le programme cible vient d'un paquet
                <strong>non déployé sur ce parc</strong> est signalée comme indisponible.
            </p>
        </div>
    </div>

    {{-- Bloc « ajouter une association » (composer) --}}
    <div class="card bg-base-100 shadow-sm border border-base-200">
        <div class="card-body">
            <h2 class="card-title text-base">Ajouter une association</h2>

            <div class="flex flex-col md:flex-row gap-3 md:items-end">
                <div class="form-control md:w-1/3">
                    <label class="label" for="newIdentifier">
                        <span class="label-text">Extension ou protocole</span>
                    </label>
                    <input type="text" id="newIdentifier" wire:model="newIdentifier"
                        placeholder=".pdf ou mailto"
                        class="input input-bordered input-sm font-mono"
                        @error('newIdentifier') aria-invalid="true" @enderror />
                    @error('newIdentifier')
                        <span class="text-error text-xs mt-1">{{ $message }}</span>
                    @enderror
                </div>

                <div class="form-control md:flex-1">
                    <label class="label" for="newAppRef">
                        <span class="label-text">Application</span>
                    </label>
                    <select id="newAppRef" wire:model="newAppRef" class="select select-bordered select-sm"
                        @error('newAppRef') aria-invalid="true" @enderror>
                        <option value="">— Choisir une application —</option>
                        @foreach ($this->appOptions as $opt)
                            <option value="{{ $opt['ref'] }}">
                                {{ $opt['name'] }}{{ $opt['kind'] === 'native' ? ' (Windows)' : '' }}
                            </option>
                        @endforeach
                    </select>
                    @error('newAppRef')
                        <span class="text-error text-xs mt-1">{{ $message }}</span>
                    @enderror
                </div>

                <div class="form-control">
                    <button type="button" wire:click="compose" class="btn btn-primary btn-sm"
                        wire:loading.attr="disabled">
                        <i class="fa-solid fa-plus"></i> Ajouter
                    </button>
                </div>
            </div>

            {{-- Aperçu icône de l'app sélectionnée (dropdown à icône). --}}
            @php($selected = collect($this->appOptions)->firstWhere('ref', $newAppRef))
            @if ($selected && $selected['icon_url'])
                <div class="flex items-center gap-2 mt-2 text-sm opacity-80">
                    <img src="{{ $selected['icon_url'] }}" alt="" class="w-5 h-5" />
                    <span>{{ $selected['name'] }}</span>
                </div>
            @endif
        </div>
    </div>

    {{-- Liste des associations du parc (éditable / désactivable) --}}
    <div class="card bg-base-100 shadow-sm border border-base-200">
        <div class="card-body">
            <h2 class="card-title text-base">Associations par défaut du parc</h2>

            <div class="overflow-x-auto">
                <table class="table table-sm">
                    <thead>
                        <tr>
                            <th>Association</th>
                            <th>Type</th>
                            <th>Cible</th>
                            <th class="text-right">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($this->associations as $association)
                            <tr>
                                <td>
                                    <div class="font-medium flex items-center gap-1">
                                        {{ $association['label'] }}
                                        @if ($association['availability'] === 'unavailable')
                                            @php($missingPkg = $association['wpkg_package'] ?? null)
                                            <span class="tooltip tooltip-error before:max-w-xs before:whitespace-normal"
                                                data-tip="{{ $missingPkg
                                                    ? '« '.$missingPkg.' » n\'est pas déployé sur ce parc → cette association échouera ici (l\'agent rapportera une erreur, le choix utilisateur reste préservé).'
                                                    : 'Association mal configurée (paquet source manquant) → elle échouera sur les postes ; le choix utilisateur reste préservé.' }}">
                                                <i class="fa-solid fa-triangle-exclamation text-error text-xs"
                                                    aria-label="{{ $missingPkg ? 'Paquet '.$missingPkg.' non déployé sur ce parc' : 'Association mal configurée (paquet source manquant)' }}"></i>
                                            </span>
                                        @elseif ($association['availability'] === 'best-effort')
                                            <span class="tooltip before:max-w-xs before:whitespace-normal"
                                                data-tip="ProgId générique : l'association sera tentée mais dépend de l'application réellement installée et résoluble sur le poste (best-effort).">
                                                <i class="fa-solid fa-circle-info text-warning text-xs"
                                                    aria-label="Best-effort : dépend de l'application installée sur le poste"></i>
                                            </span>
                                        @endif
                                    </div>
                                    @if ($association['description'] !== '')
                                        <div class="text-sm opacity-70">{{ $association['description'] }}</div>
                                    @endif
                                </td>
                                <td>
                                    <span class="badge badge-sm @class([
                                        'badge-info' => $association['assoc_type'] === 'protocol',
                                        'badge-ghost' => $association['assoc_type'] === 'file',
                                    ])">{{ $association['assoc_type'] === 'protocol' ? 'protocole' : 'fichier' }}</span>
                                    @if ($association['generic'])
                                        <span class="badge badge-sm badge-ghost badge-outline ml-1" title="ProgId générique fabriqué (ouverture via l'exécutable)">générique</span>
                                    @endif
                                    @if ($association['availability'] === 'unavailable')
                                        <span class="badge badge-sm badge-error badge-outline ml-1">indisponible</span>
                                    @elseif ($association['availability'] === 'best-effort')
                                        <span class="badge badge-sm badge-warning badge-outline ml-1"
                                            title="Best-effort : l'association sera tentée mais dépend de l'application installée sur le poste">best-effort</span>
                                    @endif
                                </td>
                                <td class="text-xs opacity-70 font-mono">
                                    {{ $association['identifier'] }} → {{ $association['progid'] }}
                                </td>
                                <td class="text-right">
                                    <button type="button" wire:click="disable({{ $association['id'] }})"
                                        class="btn btn-ghost btn-xs text-error"
                                        title="Retirer du parc (cesser de gérer)">
                                        <i class="fa-solid fa-xmark"></i> Retirer
                                    </button>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="text-center opacity-60 py-6">
                                    Aucune association configurée pour ce parc. Ajoutez-en une ci-dessus.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

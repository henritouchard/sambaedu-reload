<?php

use App\Components\Traits\WithToasts;
use App\Models\FileAssociation;
use App\Models\WorkstationGroup;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Story 27.3bis — Onglet « Associations par défaut » de la page d'un WorkstationGroup.
 *
 * L'association par défaut s'applique PAR workstationGroup (parc logique OU salle) :
 * le geste vit donc dans la page de gestion du groupe (onglet), scopé à `$groupId`,
 * et non dans une page globale à sélecteur de parc.
 *
 * L'admin ACTIVE des associations PRÉDÉTERMINÉES (`.pdf` → Acrobat, `http` → Firefox…)
 * issues du catalogue `file_associations`. Il ne touche jamais à un hash : la
 * complexité UserChoice est confinée dans le handler agent (calculée per-user au
 * logon, HKCU). Persistance sur le pivot polymorphe `file_association_assignables`
 * (assignable = WorkstationGroup) via attach/detach. « Désactiver » = retirer
 * l'assignation = l'agent CESSE de gérer l'association (le choix courant reste en
 * place ; PAS de reset OFF — piège n° 5).
 *
 * **Validation PRÉDICTIVE par parc (D-Henri n°7, AC11).** Chaque association porte
 * une `source` : `native` (built-in Windows → toujours applicable) ou `wpkg`
 * (fournie par un paquet → applicable seulement si `wpkg_package` est DÉPLOYÉ sur
 * le parc). L'UI calcule, pour CE groupe, un statut EXACT :
 *   - `native` → `applicable` ;
 *   - `wpkg` & paquet déployé → `applicable` ;
 *   - `wpkg` & paquet NON déployé → `unavailable` → warning rouge + tooltip nommant
 *     le paquet, AVANT déploiement (l'agent reste le dernier rempart sur le poste).
 * Le calcul des paquets déployés du parc est une requête **group-level Eloquent,
 * PG-pure, SANS le cache APCu** de `WorkstationPackagesResolver` (NFR7) — le
 * croisement WPKG vit ICI, JAMAIS dans `AssociationsStateProvider` (qui émet
 * toujours, D-Henri n°3).
 *
 * Gate `app.customize` (iso autres réglages parc).
 */
new class extends Component {
    use WithToasts;

    /** WorkstationGroup (parc/salle) édité — passé par la page parente. */
    public int $groupId;

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
     * Catalogue des associations actives + drapeau « assignée au groupe courant » +
     * statut PRÉDICTIF d'applicabilité (`applicable` | `unavailable`) sur ce parc.
     *
     * @return array<int,array<string,mixed>>
     */
    #[Computed]
    public function associations(): array
    {
        $assignedIds = $this->assignedAssociationIds();
        $deployedPackages = $this->deployedPackagesForParc($this->groupId);

        return FileAssociation::query()
            ->where('is_active', true)
            ->orderBy('label')
            ->get()
            ->map(function (FileAssociation $a) use ($assignedIds, $deployedPackages): array {
                $available = $a->isNative()
                    || ($a->wpkg_package !== null && in_array($a->wpkg_package, $deployedPackages, true));

                return [
                    'id' => (int) $a->id,
                    'label' => (string) $a->label,
                    'description' => (string) ($a->description ?? ''),
                    'identifier' => (string) $a->identifier,
                    'assoc_type' => (string) $a->assoc_type,
                    'progid' => (string) $a->progid,
                    'source' => (string) $a->source,
                    'wpkg_package' => $a->wpkg_package !== null ? (string) $a->wpkg_package : null,
                    'assigned' => in_array((int) $a->id, $assignedIds, true),
                    'availability' => $available ? 'applicable' : 'unavailable',
                ];
            })
            ->all();
    }

    /**
     * Active/désactive une association pour le groupe courant (pivot attach/detach).
     * Toast EXACT : à l'activation d'une association `unavailable` (paquet WPKG non
     * déployé sur le parc), avertit en nommant le paquet ; sinon succès simple.
     */
    public function toggle(int $associationId): void
    {
        $association = FileAssociation::query()->findOrFail($associationId);
        $parc = WorkstationGroup::query()->findOrFail($this->groupId);

        if (in_array($associationId, $this->assignedAssociationIds(), true)) {
            // Désassigner = cesser de gérer (l'item disparaît, le choix courant
            // reste — PAS de reset OFF, piège n° 5).
            $association->workstationGroups()->detach($parc->id);
            $this->toastSuccess('Association retirée du parc (le choix déjà appliqué reste en place).');
        } else {
            // syncWithoutDetaching : idempotent, ne touche pas les autres
            // assignations de cette association.
            $association->workstationGroups()->syncWithoutDetaching([$parc->id]);

            // Statut PRÉDICTIF EXACT (D-Henri n°7) : si le ProgId vient d'un paquet
            // WPKG NON déployé sur ce parc, l'association échouera ici → avertir en
            // nommant le paquet. Sinon (native ou paquet déployé), succès simple.
            $deployedPackages = $this->deployedPackagesForParc($parc->id);
            $available = $association->isNative()
                || ($association->wpkg_package !== null && in_array($association->wpkg_package, $deployedPackages, true));

            if ($available) {
                $this->toastSuccess('Association activée pour le parc.');
            } elseif ($association->wpkg_package !== null && $association->wpkg_package !== '') {
                $this->toastWarning(
                    '« ' . $association->wpkg_package . ' » n\'est pas déployé sur ce parc → cette '
                    . 'association échouera ici (l\'agent rapportera une erreur, le choix utilisateur '
                    . 'reste préservé).',
                    'Association activée mais indisponible',
                );
            } else {
                // Garde-fou donnée incohérente (source=wpkg sans wpkg_package).
                $this->toastWarning(
                    'Cette association est mal configurée (paquet source manquant) → elle échouera '
                    . 'sur les postes ; le choix de l\'utilisateur reste préservé.',
                    'Association activée mais indisponible',
                );
            }
        }

        unset($this->associations);
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
     * parc — requête **group-level Eloquent, PG-pure, SANS cache APCu** (NFR7,
     * AC11). Inspiré de {@see \App\Wpkg\Deployment\Services\WorkstationPackagesResolver::computePackages()}
     * (branche « via les parcs ») mais ciblé sur le `WorkstationGroup` du parc :
     *   1. AppProfiles du parc → leurs Applications ;
     *   2. Applications rattachées directement au parc ;
     *   3. Dépendances applicatives transitives (BFS sur `application_dependencies`).
     *
     * **Clé de jointure (vérifiée).** `Application::$app_id` = le `<package id>` de
     * `packages.xml` : `PackagesXmlService::regenerate()` émet le `$app->xml` dont
     * la racine `<package id>` vaut `app_id`, et `PackagesXmlAssociationsReader`
     * indexe les associations par ce même `<package id>`. Donc comparer
     * `FileAssociation::$wpkg_package` (= packageId du reader) à cet ensemble est
     * correct.
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

        // Sources 1 — AppProfiles du parc.
        foreach ($group->appProfiles as $profile) {
            foreach ($profile->applications as $app) {
                $appIds->push($app->app_id);
                $applicationIds->push($app->id);
            }
        }

        // Source 2 — Applications directes du parc.
        foreach ($group->applications as $app) {
            $appIds->push($app->app_id);
            $applicationIds->push($app->id);
        }

        // Source 3 — Dépendances transitives (BFS), iso resolver. PG-pur (DB query
        // builder, jamais le cache APCu de WorkstationPackagesResolver).
        $allIds = $this->collectDependenciesTransitive($applicationIds->unique()->all());
        $rootIds = $applicationIds->unique()->all();
        $depIds = array_values(array_diff($allIds, $rootIds));
        if ($depIds !== []) {
            $appIdsFromDeps = \App\Models\Application::query()
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
     * BFS des dépendances applicatives transitives (PG-pur, query builder). Iso
     * {@see \App\Wpkg\Deployment\Services\WorkstationPackagesResolver::collectDependenciesTransitive()}.
     *
     * @param  list<int>  $rootIds
     * @return list<int>  IDs union (racines + transitives), dédupliqué.
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
            <p class="font-medium">Catalogue d'associations</p>
            <p class="text-sm opacity-80">
                Cochez les associations à appliquer pour ce parc. Elles sont réimposées par l'agent
                au logon (une association changée manuellement sur un poste est corrigée au cycle suivant).
                <strong>Désactiver une association = cesser de la gérer</strong> : le choix déjà
                appliqué reste en place. Une association dont le programme cible vient d'un paquet
                <strong>non déployé sur ce parc</strong> est signalée comme indisponible (elle
                échouera sur les postes ; le choix de l'utilisateur reste préservé).
            </p>
        </div>
    </div>

    <div class="card bg-base-100 shadow-sm border border-base-200">
        <div class="card-body">
            <h2 class="card-title text-base">Associations par défaut disponibles</h2>

            <div class="overflow-x-auto">
                <table class="table table-sm">
                    <thead>
                        <tr>
                            <th>Activée</th>
                            <th>Association</th>
                            <th>Type</th>
                            <th>Cible</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($this->associations as $association)
                            <tr>
                                <td>
                                    <input type="checkbox" class="toggle toggle-primary toggle-sm"
                                        @checked($association['assigned'])
                                        wire:click="toggle({{ $association['id'] }})" />
                                </td>
                                <td>
                                    <div class="font-medium flex items-center gap-1">
                                        {{ $association['label'] }}
                                        @if ($association['availability'] === 'unavailable')
                                            {{-- Validation prédictive EXACTE (D-Henri n°7) : le paquet
                                                 WPKG cible n'est PAS déployé sur ce parc → l'association
                                                 échouera ici. Nomme le paquet ; garde-fou si la donnée est
                                                 incohérente (wpkg sans wpkg_package). --}}
                                            @php($missingPkg = $association['wpkg_package'] ?? null)
                                            <span class="tooltip tooltip-error before:max-w-xs before:whitespace-normal"
                                                data-tip="{{ $missingPkg
                                                    ? '« '.$missingPkg.' » n\'est pas déployé sur ce parc → cette association échouera ici (l\'agent rapportera une erreur, le choix utilisateur reste préservé).'
                                                    : 'Association mal configurée (paquet source manquant) → elle échouera sur les postes ; le choix utilisateur reste préservé.' }}">
                                                <i class="fa-solid fa-triangle-exclamation text-error text-xs"
                                                    aria-label="{{ $missingPkg ? 'Paquet '.$missingPkg.' non déployé sur ce parc' : 'Association mal configurée (paquet source manquant)' }}"></i>
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
                                    @if ($association['availability'] === 'unavailable')
                                        <span class="badge badge-sm badge-error badge-outline ml-1">indisponible</span>
                                    @endif
                                </td>
                                <td class="text-xs opacity-70 font-mono">
                                    {{ $association['identifier'] }} → {{ $association['progid'] }}
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="text-center opacity-60 py-6">
                                    Aucune association dans le catalogue.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

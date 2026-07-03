<?php

declare(strict_types=1);

namespace App\Services\Agent\Reporting;

use App\Enums\StateMaille;
use App\Models\Application;
use App\Models\Shortcut;
use App\Models\Workstation;
use App\Models\WorkstationGroup;
use App\Services\Agent\TargetContext;
use App\Services\ControlHub\Resolution\UpstreamContractSource;
use App\Wpkg\Deployment\Services\WorkstationPackagesResolver;

/**
 * Story 37.1 — Projection en LECTURE SEULE de l'ÉTAT CIBLE (raccourcis +
 * applications) d'un poste / d'un parc, AVEC l'ORIGINE de chaque item, pour
 * l'onglet « État cible » des fiches poste et parc.
 *
 * **Chemin de CONSULTATION parallèle — pipeline agent SANCTUARISÉ (décision D1).**
 * Ce service N'appelle JAMAIS {@see \App\Services\Agent\StateCompiler::compile()}
 * ni ne modifie {@see \App\Services\Agent\StateCandidate} / les providers :
 * l'enveloppe 4-clés `{type, semantics, payload, hash}` (dont dépend l'ETag agent)
 * n'apporte rien à l'UI. Il RÉUTILISE les MÊMES sources que les providers :
 *  - applications : {@see WorkstationPackagesResolver::explainPackages()} (union
 *    4 sources + BFS de dépendances, NON CACHÉE — provenance par `app_id`) ∪
 *    `Application::is_parc_default` (socle commun 27.17) ∪
 *    {@see UpstreamContractSource::orderedApplicationAppIds()} (ordres d'install
 *    amont 31.2) — exactement l'union de l'`ApplicationsStateProvider` ;
 *  - raccourcis : pivot polymorphe `shortcut_assignables` restreint au périmètre
 *    machine DIRECT ({@see TargetContext::workstationGroupIds()}, filtre
 *    `is_active`), étiquetage salle/parc iso `ShortcutsStateProvider::mailleFor()`
 *    ∪ items amont ({@see UpstreamContractSource::candidatesFor()}).
 *
 * **PG-pur (NFR7)** : aucun `Cache::`/APCu/LDAP/`samba-tool`. Aucune écriture.
 *
 * **Ciblage User/UserGroup EXCLU de la fiche poste (décision D3)** : ces
 * raccourcis dépendent de la session (ils s'appliqueraient sur n'importe quel
 * poste) — l'UI les signale par une note et renvoie à la fiche du raccourci. On
 * construit donc le contexte avec `user = null` : les mailles user sont vides.
 *
 * **Multi-origines (piège #5)** : un même raccourci / une même app peut porter
 * PLUSIEURS origines (poste ET parc, direct ET dépendance…). On les AGRÈGE par
 * item (le compilateur aggregate déduplique par contenu et perd l'origine) ; le
 * badge PRINCIPAL est l'origine la plus spécifique selon {@see self::RANKS}
 * (miroir d'affichage de {@see \App\Services\Agent\StateCompiler::specificity()},
 * décision D7 — on ne recode PAS la précédence).
 */
class DesiredStateOriginService
{
    /**
     * Ordre de spécificité pour le badge PRINCIPAL (plus PETIT = plus spécifique).
     * Miroir d'affichage de `StateCompiler::specificity()` (décision D7) : on ne
     * recode PAS la précédence (types aggregate = union), c'est un simple ordre
     * d'affichage. `Contrat amont verrouillé` > `Ce poste`/`Ce parc` > `Parc
     * logique` > `Salle` > `Dépendance` > `Socle commun` > `Contrat amont`
     * (ordre d'install / permissif).
     */
    private const RANKS = [
        'upstream_locked' => 0,     // raccourci amont verrouillé (Upstream, rang -1)
        'workstation' => 1,         // Ce poste (fiche poste)
        'group_self' => 1,          // Ce parc (fiche parc logique)
        'room_self' => 1,           // Cette salle (fiche salle physique — review #5)
        'group_profile' => 1,       // via profil X (fiche parc)
        'logical_group' => 2,       // parc logique
        'physical_group' => 3,      // salle physique
        'dependency' => 4,          // dépendance WPKG
        'parc_default' => 5,        // socle commun (is_parc_default, Broadcast)
        'upstream' => 6,            // ordre d'install amont (aggregate, présence)
        'upstream_permissive' => 7, // raccourci amont permissif (UpstreamPermissive, rang 6)
    ];

    public function __construct(
        private readonly WorkstationPackagesResolver $resolver,
        // Singleton mémoïsé partagé (≤ 1 requête « contrat actif ? », court-circuit
        // NFR3 sans contrat). JAMAIS de requête directe aux tables controlhub_*.
        private readonly UpstreamContractSource $source,
    ) {}

    /**
     * Raccourcis résolus pour la MACHINE (fiche poste) : ciblages `Workstation` +
     * `WorkstationGroup` (salle directe / parc logique) — périmètre EXACT des
     * candidats machine du provider avec `user = null` (D3, ciblages User/UserGroup
     * exclus) — filtre `is_active`, agrégés par raccourci, + items amont.
     *
     * @return list<array<string,mixed>>
     */
    public function shortcutsFor(Workstation $workstation): array
    {
        $ctx = TargetContext::for($workstation, null);
        $wgIds = $ctx->workstationGroupIds();

        $rows = Shortcut::query()
            ->where('shortcuts.is_active', true)
            ->join('shortcut_assignables', 'shortcuts.id', '=', 'shortcut_assignables.shortcut_id')
            ->where(function ($q) use ($ctx, $wgIds): void {
                if ($wgIds !== []) {
                    $q->orWhere(fn ($qq) => $qq
                        ->where('shortcut_assignables.assignable_type', WorkstationGroup::class)
                        ->whereIn('shortcut_assignables.assignable_id', $wgIds));
                }
                // D3 — User / UserGroup EXCLUS (session-dépendants).
                $q->orWhere(fn ($qq) => $qq
                    ->where('shortcut_assignables.assignable_type', Workstation::class)
                    ->where('shortcut_assignables.assignable_id', $ctx->workstation->id));
            })
            ->get([
                'shortcuts.id',
                'shortcuts.name',
                'shortcuts.place',
                'shortcuts.windows_link',
                'shortcut_assignables.assignable_type',
                'shortcut_assignables.assignable_id',
            ]);

        // Agrégation par raccourci + collecte des group_id à hydrater (une requête).
        $byShortcut = [];
        $groupIds = [];
        foreach ($rows as $row) {
            $sid = (int) $row->id;
            $byShortcut[$sid] ??= [
                'shortcut' => $row,
                'origins' => [],
            ];

            if ($row->assignable_type === WorkstationGroup::class) {
                $gid = (int) $row->assignable_id;
                $groupIds[$gid] = true;
                $kind = in_array($gid, $ctx->physicalGroupIds, true) ? 'physical_group' : 'logical_group';
                $byShortcut[$sid]['origins'][] = ['kind' => $kind, 'group_id' => $gid];
            } elseif ($row->assignable_type === Workstation::class) {
                $byShortcut[$sid]['origins'][] = ['kind' => 'workstation'];
            }
        }

        $groups = $this->hydrateGroups(array_keys($groupIds));

        $result = [];
        foreach ($byShortcut as $entry) {
            $shortcut = $entry['shortcut'];
            $result[] = $this->shortcutRow(
                key: 'sc-'.(int) $shortcut->id,
                label: (string) $shortcut->name,
                target: (string) ($shortcut->windows_link ?? ''),
                place: (string) $shortcut->place,
                origins: $this->decorateGroups($entry['origins'], $groups),
            );
        }

        // Raccourcis AMONT (items contrat) — via UpstreamContractSource
        // (court-circuit NFR3 : sans contrat / sans adaptateur `shortcuts` câblé →
        // []). Keyés à part (pas d'id de raccourci local) → jamais de collision.
        foreach ($this->upstreamShortcutRows($ctx) as $row) {
            $result[] = $row;
        }

        return $this->sortRows($result);
    }

    /**
     * Applications de l'ENSEMBLE CIBLE de la MACHINE (fiche poste) : union résolue
     * WPKG (poste + parcs + profils + dépendances) ∪ socle commun ∪ ordres d'install
     * amont — mêmes gardes d'hydratation que l'`ApplicationsStateProvider`.
     *
     * @return list<array<string,mixed>>
     */
    public function applicationsFor(Workstation $workstation): array
    {
        $ctx = TargetContext::for($workstation, null);

        /** @var array<string, list<array<string,mixed>>> $originsByAppId */
        $originsByAppId = [];
        $groupIds = [];

        // Résolution WPKG NON CACHÉE avec provenance (workstation | group | dependency).
        foreach ($this->resolver->explainPackages($workstation->name) as $appId => $origins) {
            $appId = (string) $appId;
            foreach ($origins as $origin) {
                if ($origin['source'] === 'group' && isset($origin['group_id'])) {
                    $groupIds[(int) $origin['group_id']] = true;
                }
            }
            $originsByAppId[$appId] = array_merge($originsByAppId[$appId] ?? [], $origins);
        }

        // Socle commun (27.17) — apps is_parc_default (Broadcast).
        foreach ($this->parcDefaultAppIds() as $appId) {
            $originsByAppId[$appId][] = ['source' => 'parc_default'];
        }

        // Ordres d'install amont (31.2) — court-circuit NFR3 sans contrat.
        foreach ($this->source->orderedApplicationAppIds($ctx) as $appId) {
            $originsByAppId[(string) $appId][] = ['source' => 'upstream'];
        }

        $groups = $this->hydrateGroups(array_keys($groupIds));

        return $this->buildApplicationRows($originsByAppId, $groups);
    }

    /**
     * Raccourcis assignés à CE groupe (fiche parc, décision D4) : ciblages
     * `shortcut_assignables` dont l'assignable EST ce groupe, `is_active`. Badge
     * « Ce parc » — ou « Cette salle » si `is_physical` (review #5, cohérent D6 :
     * la distinction salle/parc vaut aussi pour la contribution propre). (Les
     * réglages propres des postes membres sont sur leur fiche.)
     *
     * @return list<array<string,mixed>>
     */
    public function shortcutsForGroup(WorkstationGroup $group): array
    {
        $selfKind = $group->is_physical ? 'room_self' : 'group_self';
        $rows = Shortcut::query()
            ->where('shortcuts.is_active', true)
            ->join('shortcut_assignables', 'shortcuts.id', '=', 'shortcut_assignables.shortcut_id')
            ->where('shortcut_assignables.assignable_type', WorkstationGroup::class)
            ->where('shortcut_assignables.assignable_id', $group->id)
            ->get([
                'shortcuts.id',
                'shortcuts.name',
                'shortcuts.place',
                'shortcuts.windows_link',
            ]);

        $result = [];
        foreach ($rows as $row) {
            $result[] = $this->shortcutRow(
                key: 'sc-'.(int) $row->id,
                label: (string) $row->name,
                target: (string) ($row->windows_link ?? ''),
                place: (string) $row->place,
                origins: [['kind' => $selfKind]],
            );
        }

        return $this->sortRows($result);
    }

    /**
     * Applications qu'apporte CE parc (fiche parc, décision D4) : apps directes
     * (« Ce parc » — ou « Cette salle » si `is_physical`, review #5) + apps de ses
     * profils (« via profil X ») + planchers socle commun (`is_parc_default`) +
     * ordres d'install amont fleet-wide.
     *
     * @return list<array<string,mixed>>
     */
    public function applicationsForGroup(WorkstationGroup $group): array
    {
        $selfSource = $group->is_physical ? 'room_self' : 'group_self';
        $group->loadMissing([
            'applications:id,app_id,name',
            'appProfiles' => fn ($q) => $q->whereNull('archived_at'),
            'appProfiles.applications:id,app_id,name',
        ]);

        /** @var array<string, list<array<string,mixed>>> $originsByAppId */
        $originsByAppId = [];
        /** @var array<string, Application> $appByAppId */
        $appByAppId = [];

        $remember = function (Application $app) use (&$appByAppId): string {
            $appId = (string) $app->app_id;
            if ($appId !== '') {
                $appByAppId[$appId] ??= $app;
            }

            return $appId;
        };

        // Apps directes du groupe → « Ce parc » / « Cette salle » (review #5).
        foreach ($group->applications as $app) {
            $appId = $remember($app);
            if ($appId !== '') {
                $originsByAppId[$appId][] = ['source' => $selfSource];
            }
        }

        // Apps via profils du parc → « via profil X ».
        foreach ($group->appProfiles as $profile) {
            $profileLabel = (string) ($profile->display_name ?? $profile->name);
            foreach ($profile->applications as $app) {
                $appId = $remember($app);
                if ($appId !== '') {
                    $originsByAppId[$appId][] = ['source' => 'group_profile', 'via' => $profileLabel];
                }
            }
        }

        // Socle commun (plancher fleet-wide).
        foreach ($this->parcDefaultAppIds() as $appId) {
            $originsByAppId[$appId][] = ['source' => 'parc_default'];
        }

        // Ordres d'install amont fleet-wide (`instance`) — via UpstreamContractSource
        // avec un contexte SANS appartenance de parc (workstationGroupIds() vide) :
        // seuls les ordres `instance` (toute la flotte) remontent. Court-circuit
        // NFR3 sans contrat. Les ordres ciblés par label restent visibles sur les
        // fiches des postes qui portent ce label (D3/D4 — le parc n'est pas un poste).
        foreach ($this->source->orderedApplicationAppIds($this->fleetContext()) as $appId) {
            $originsByAppId[(string) $appId][] = ['source' => 'upstream'];
        }

        return $this->buildApplicationRows($originsByAppId, [], $appByAppId);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Construit les lignes « application » : hydrate les libellés manquants
     * (`Application::whereIn('app_id', …)`, garde iso provider — un app_id sans
     * ligne Application est SKIPPÉ silencieusement), résout les groupes des origines
     * `group`/`dependency`, agrège les origines et calcule le badge principal.
     *
     * @param  array<string, list<array<string,mixed>>>  $originsByAppId
     * @param  array<int, WorkstationGroup>  $groups  déjà hydratés (fiche poste)
     * @param  array<string, Application>  $preloaded  apps déjà en mémoire (fiche parc)
     * @return list<array<string,mixed>>
     */
    private function buildApplicationRows(array $originsByAppId, array $groups, array $preloaded = []): array
    {
        if ($originsByAppId === []) {
            return [];
        }

        // Hydratation PG-pure des libellés manquants (mêmes gardes que le provider).
        $missing = array_values(array_filter(
            array_keys($originsByAppId),
            fn ($appId): bool => ! isset($preloaded[(string) $appId]),
        ));

        $byAppId = $preloaded;
        if ($missing !== []) {
            $apps = Application::query()
                ->whereIn('app_id', $missing)
                ->orderBy('id')
                ->get(['id', 'app_id', 'name']);
            foreach ($apps as $app) {
                $byAppId[(string) $app->app_id] ??= $app;
            }
        }

        $result = [];
        foreach ($originsByAppId as $appId => $origins) {
            $appId = (string) $appId;
            $app = $byAppId[$appId] ?? null;
            if ($app === null) {
                // app_id résolu sans ligne Application (incohérence/archivage) —
                // SKIPPÉ silencieusement côté UI (le provider logue déjà).
                continue;
            }

            $decorated = [];
            foreach ($origins as $origin) {
                $decorated[] = $this->decorateAppOrigin($origin, $groups, $byAppId);
            }
            $ordered = $this->orderOrigins($decorated);

            $result[] = [
                'key' => 'app-'.$appId,
                'label' => (string) $app->name,
                'detail' => $appId,
                'origins' => $ordered,
                'primary' => $ordered[0],
            ];
        }

        return $this->sortRows($result);
    }

    /**
     * Traduit une origine d'`explainPackages()` / socle / amont en descripteur UI
     * `{kind, group_id?, group_name?, group_physical?, via?}`.
     *
     * Review M1 — pour une `dependency`, `via` porte le NOM D'AFFICHAGE
     * (`Application::name`) de l'app parente (« Dépendance de Mozilla Firefox »),
     * résolu via la map `app_id → Application` DÉJÀ chargée pour l'hydratation
     * (zéro requête de plus) ; fallback = `app_id` brut si nom introuvable/vide.
     *
     * @param  array<string,mixed>  $origin
     * @param  array<int, WorkstationGroup>  $groups
     * @param  array<string, Application>  $byAppId
     * @return array<string,mixed>
     */
    private function decorateAppOrigin(array $origin, array $groups, array $byAppId): array
    {
        return match ($origin['source']) {
            'workstation' => ['kind' => 'workstation'],
            'group' => $this->decorateGroup((int) ($origin['group_id'] ?? 0), $groups),
            'dependency' => ['kind' => 'dependency', 'via' => $this->dependencyViaLabel($origin['via_app_id'] ?? null, $byAppId)],
            'group_self' => ['kind' => 'group_self'],
            'room_self' => ['kind' => 'room_self'],
            'group_profile' => ['kind' => 'group_profile', 'via' => $origin['via'] ?? null],
            'parc_default' => ['kind' => 'parc_default'],
            'upstream' => ['kind' => 'upstream'],
            default => ['kind' => 'parc_default'],
        };
    }

    /**
     * Review M1 — libellé « Dépendance de X » : nom d'affichage de l'app parente
     * (fallback `app_id` brut si la ligne Application manque ou si `name` est vide).
     *
     * @param  array<string, Application>  $byAppId
     */
    private function dependencyViaLabel(?string $viaAppId, array $byAppId): ?string
    {
        if ($viaAppId === null || $viaAppId === '') {
            return null;
        }

        $name = $byAppId[$viaAppId]->name ?? null;

        return ($name !== null && $name !== '') ? (string) $name : $viaAppId;
    }

    /**
     * Étiquette un group_id en salle (physique) ou parc logique + nom + lien.
     * Règle iso `ShortcutsStateProvider::mailleFor()`.
     *
     * @param  array<int, WorkstationGroup>  $groups
     * @return array<string,mixed>
     */
    private function decorateGroup(int $groupId, array $groups): array
    {
        $group = $groups[$groupId] ?? null;
        $physical = $group?->is_physical === true;

        return [
            'kind' => $physical ? 'physical_group' : 'logical_group',
            'group_id' => $groupId,
            'group_name' => $group?->display_name ?? $group?->name ?? '#'.$groupId,
            'group_physical' => $physical,
        ];
    }

    /**
     * Décore les origines de raccourci (kind + group_id déjà posés) avec le nom du
     * groupe source, pour les liens de la vue.
     *
     * @param  list<array<string,mixed>>  $origins
     * @param  array<int, WorkstationGroup>  $groups
     * @return list<array<string,mixed>>
     */
    private function decorateGroups(array $origins, array $groups): array
    {
        return array_map(function (array $origin) use ($groups): array {
            if (in_array($origin['kind'], ['physical_group', 'logical_group'], true) && isset($origin['group_id'])) {
                $group = $groups[(int) $origin['group_id']] ?? null;
                $origin['group_name'] = $group?->display_name ?? $group?->name ?? '#'.$origin['group_id'];
                $origin['group_physical'] = $group?->is_physical === true;
            }

            return $origin;
        }, $origins);
    }

    /**
     * Candidats raccourcis AMONT projetés en lignes UI. Court-circuit NFR3 :
     * `candidatesFor()` renvoie `[]` sans contrat actif (ou sans adaptateur
     * `shortcuts` câblé — cas prod). Maille → verrouillé/permissif (D6).
     *
     * @return list<array<string,mixed>>
     */
    private function upstreamShortcutRows(TargetContext $ctx): array
    {
        $candidates = $this->source->candidatesFor(
            Shortcut::TYPE_SHORTCUTS,
            \App\Enums\StateScope::MachineUser,
            $ctx,
        );

        $rows = [];
        foreach ($candidates as $candidate) {
            $payload = $candidate->payload;
            $kind = $candidate->maille === StateMaille::UpstreamPermissive
                ? 'upstream_permissive'
                : 'upstream_locked';

            $rows[] = $this->shortcutRow(
                key: 'sc-upstream-'.$candidate->sourceId,
                label: (string) ($payload['name'] ?? ''),
                target: (string) ($payload['target'] ?? ''),
                place: (string) ($payload['place'] ?? ''),
                origins: [['kind' => $kind]],
            );
        }

        return $rows;
    }

    /**
     * Ligne « raccourci » normalisée (badge principal calculé).
     *
     * @param  list<array<string,mixed>>  $origins
     * @return array<string,mixed>
     */
    private function shortcutRow(string $key, string $label, string $target, string $place, array $origins): array
    {
        $ordered = $this->orderOrigins($origins);

        return [
            'key' => $key,
            'label' => $label,
            'detail' => $target,
            'place' => $place,
            'place_label' => $this->placeLabel($place),
            'origins' => $ordered,
            'primary' => $ordered[0],
        ];
    }

    /**
     * Trie les origines par spécificité (badge principal = index 0), en dédupliquant
     * les origines strictement identiques (même kind + même group).
     *
     * @param  list<array<string,mixed>>  $origins
     * @return list<array<string,mixed>>
     */
    private function orderOrigins(array $origins): array
    {
        $unique = [];
        foreach ($origins as $origin) {
            $sig = ($origin['kind'] ?? '').'|'.($origin['group_id'] ?? '').'|'.($origin['via'] ?? '');
            $unique[$sig] ??= $origin;
        }

        $ordered = array_values($unique);
        usort($ordered, fn (array $a, array $b): int => $this->rank($a['kind']) <=> $this->rank($b['kind']));

        return $ordered;
    }

    private function rank(string $kind): int
    {
        return self::RANKS[$kind] ?? 99;
    }

    /**
     * Tri d'affichage stable : par libellé (insensible casse) puis clé.
     *
     * @param  list<array<string,mixed>>  $rows
     * @return list<array<string,mixed>>
     */
    private function sortRows(array $rows): array
    {
        usort($rows, function (array $a, array $b): int {
            return strcasecmp((string) $a['label'], (string) $b['label'])
                ?: strcmp((string) $a['key'], (string) $b['key']);
        });

        return $rows;
    }

    /**
     * @return list<string>
     */
    private function parcDefaultAppIds(): array
    {
        return Application::query()
            ->parcDefault()
            ->whereNotNull('app_id')
            ->where('app_id', '!=', '')
            ->pluck('app_id')
            ->map(fn ($v): string => (string) $v)
            ->all();
    }

    /**
     * Hydrate les WorkstationGroups sources en UNE requête (anti N+1).
     *
     * @param  list<int>  $ids
     * @return array<int, WorkstationGroup>
     */
    private function hydrateGroups(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return WorkstationGroup::query()
            ->whereIn('id', $ids)
            ->get(['id', 'name', 'display_name', 'is_physical'])
            ->keyBy('id')
            ->all();
    }

    /**
     * Contexte « flotte » (sans appartenance de parc) pour ne remonter QUE les
     * ordres d'install amont `instance` (fiche parc). L'appartenance vide fait
     * court-circuiter la résolution des labels portés (aucune requête).
     */
    private function fleetContext(): TargetContext
    {
        return TargetContext::for(new Workstation(), null);
    }

    private function placeLabel(string $place): string
    {
        return match ($place) {
            Shortcut::PLACE_DESKTOP => 'Bureau',
            Shortcut::PLACE_STARTUP => 'Démarrage automatique',
            Shortcut::PLACE_TASKBAR => 'Barre des tâches',
            default => $place !== '' ? $place : 'Inconnu',
        };
    }
}

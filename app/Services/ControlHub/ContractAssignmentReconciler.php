<?php

declare(strict_types=1);

namespace App\Services\ControlHub;

use App\Enums\ControlHubContractApplyStatus;
use App\Enums\ControlHubContractTarget;
use App\Enums\ControlHubEnforcementState;
use App\Models\Application;
use App\Models\Capability;
use App\Models\ControlHubContract;
use App\Models\ControlHubContractItem;
use App\Models\Shortcut;
use App\Models\Wallpaper;
use App\Models\WallpaperAsset;
use App\Models\WorkstationGroup;
use App\Services\ControlHub\Data\ContractAssignmentResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Pose sur les parcs ce que le contrat amont leur destine.
 *
 * Un item de contrat désigne sa cible par un LABEL, jamais par un parc : c'est ici
 * que le label devient une assignation réelle, sur chaque parc qui le porte. Sans
 * cette traduction, un item `label` restait dans les tables du contrat sans que ni
 * l'administrateur ni le poste n'en voient jamais la trace.
 *
 * Les quatre supports sont ceux de SE5, inchangés — le contrat écrit là où l'admin
 * écrit, pas dans un canal parallèle :
 *
 * | item           | cible `label`                    | cible `instance`                |
 * | -------------- | -------------------------------- | ------------------------------- |
 * | `applications` | `application_workstation_group`  | `applications.is_parc_default`  |
 * | `shortcuts`    | `shortcut_assignables`           | `shortcuts.is_parc_default`     |
 * | `capabilities` | `capability_assignments`         | `capabilities.default_value`    |
 * | `wallpapers`   | `wallpapers` (owner = parc)      | `wallpapers.is_default`         |
 * | `lockscreens`  | idem, `type = 'lockscreen'`      | idem, `type = 'lockscreen'`     |
 *
 * **Le prune ne déborde jamais.** Seules les lignes marquées `managed_by_control_hub`
 * sont candidates au retrait : une assignation posée à la main survit à une réception
 * qui ne la mentionne pas. C'est l'invariant que protègent en priorité les tests.
 *
 * Un item qu'on ne peut pas résoudre — application hors inventaire, label que nul
 * parc ne porte, capacité inconnue — est COMPTÉ et TRACÉ, jamais silencieux : le
 * contrat le réclame, SE5 ne sait pas encore le servir, et l'écart doit se voir.
 *
 * Chaque item revendiqué repart de cette passe avec un verdict écrit dans
 * `apply_status` / `apply_detail`. C'est la SEULE source du canal ③ pour ces quatre
 * types : sans lui le rapport de conformité déduisait « appliqué » du seul
 * `enforcement_state`, et affirmait à l'amont ce qu'il n'avait pas vérifié.
 */
class ContractAssignmentReconciler
{
    /**
     * Verdicts de la passe en cours, indexés par identifiant d'item.
     *
     * Accumulés pendant la résolution, écrits dans la même transaction que les
     * assignations : un échec d'écriture ne laisse jamais un « appliqué » derrière lui.
     *
     * @var array<int, array{0: ControlHubContractApplyStatus, 1: string|null}>
     */
    private array $verdicts = [];

    /**
     * Aligne les assignations locales sur le contrat amont actif.
     *
     * Sans contrat actif, no-op total : aucune table d'assignation n'est même lue.
     */
    public function reconcile(): ContractAssignmentResult
    {
        $result = new ContractAssignmentResult();
        $this->verdicts = [];

        $contract = ControlHubContract::active();
        if ($contract === null) {
            return $result;
        }

        $items = $contract->items()
            ->where('enforcement_state', '!=', ControlHubEnforcementState::Absent->value)
            ->get();

        // Parcs porteurs de label, indexés par nom de label (rattachement PAR NOM,
        // pas de FK — cf. la colonne `workstation_groups.controlhub_label`).
        $groupsByLabel = WorkstationGroup::query()
            ->whereNotNull('controlhub_label')
            ->where('controlhub_label', '!=', '')
            ->whereNull('archived_at')
            ->get(['id', 'controlhub_label'])
            ->groupBy('controlhub_label');

        // Ce que le contrat veut, accumulé avant écriture : le prune a besoin de
        // l'ensemble COMPLET pour distinguer « plus voulu » de « pas encore vu ».
        $desired = [
            'applications' => [],   // [applicationId => [groupId, …]]
            'shortcuts' => [],      // [shortcutId => [groupId, …]]
            'capabilities' => [],   // [capabilityId => [groupId => [value, locked]]]
            'wallpapers' => [],     // [groupId => assetId]
            'lockscreens' => [],    // [groupId => assetId]
            'defaults' => [
                'applications' => [],
                'shortcuts' => [],
                'wallpapers' => null,
                'lockscreens' => null,
            ],
        ];

        foreach ($items as $item) {
            try {
                $this->collect($item, $groupsByLabel, $desired, $result);
            } catch (Throwable $e) {
                $result->errors[] = "Item '{$item->type}/{$item->key}': ".$e->getMessage();
                $this->recordVerdict($item, ControlHubContractApplyStatus::Error, $e->getMessage());
                Log::error('[ContractAssignmentReconciler] Échec de résolution d\'un item', [
                    'type' => $item->type,
                    'key' => $item->key,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        DB::transaction(function () use ($desired, $result): void {
            $this->applyApplications($desired['applications'], $result);
            $this->applyShortcuts($desired['shortcuts'], $result);
            $this->applyCapabilities($desired['capabilities'], $result);
            $this->applyWallpapers(Wallpaper::TYPE_WALLPAPER, $desired['wallpapers'], $result);
            $this->applyWallpapers(Wallpaper::TYPE_LOCKSCREEN, $desired['lockscreens'], $result);
            $this->applyDefaults($desired['defaults'], $result);
            $this->flushVerdicts();
        });

        Log::info('[ContractAssignmentReconciler] Assignations du contrat appliquées', [
            'contract_id' => $contract->id,
            'result' => $result->toArray(),
        ]);

        return $result;
    }

    /**
     * Traduit un item en intentions d'écriture, sans rien écrire.
     *
     * @param  \Illuminate\Support\Collection<string, \Illuminate\Support\Collection<int, WorkstationGroup>>  $groupsByLabel
     * @param  array<string, mixed>  $desired
     */
    private function collect(
        ControlHubContractItem $item,
        $groupsByLabel,
        array &$desired,
        ContractAssignmentResult $result,
    ): void {
        $targetsInstance = $item->target_type === ControlHubContractTarget::Instance;

        $groupIds = [];
        if (! $targetsInstance) {
            $groupIds = ($groupsByLabel[(string) $item->target_label] ?? collect())
                ->pluck('id')
                ->all();

            if ($groupIds === []) {
                // Le label est déclaré au contrat mais aucun parc ne le porte : rien
                // à assigner aujourd'hui, et l'item deviendra effectif dès qu'un parc
                // le portera. C'est un écart à voir, pas une erreur.
                $result->unresolved++;
                $this->recordVerdict(
                    $item,
                    ControlHubContractApplyStatus::Pending,
                    "Aucun parc ne porte le label « {$item->target_label} »",
                );
                Log::info('[ContractAssignmentReconciler] Label sans parc porteur — item sans effet', [
                    'type' => $item->type,
                    'key' => $item->key,
                    'label' => $item->target_label,
                ]);

                return;
            }
        }

        match ($item->type) {
            Application::TYPE_APPLICATIONS => $this->collectApplication($item, $groupIds, $targetsInstance, $desired, $result),
            Shortcut::TYPE_SHORTCUTS => $this->collectShortcut($item, $groupIds, $targetsInstance, $desired, $result),
            Capability::TYPE_CAPABILITIES => $this->collectCapability($item, $groupIds, $targetsInstance, $desired, $result),
            'wallpapers' => $this->collectWallpaper('wallpapers', $item, $groupIds, $targetsInstance, $desired, $result),
            'lockscreens' => $this->collectWallpaper('lockscreens', $item, $groupIds, $targetsInstance, $desired, $result),
            default => null, // type sans support d'assignation local : ignoré proprement
        };
    }

    /**
     * @param  array<int, int>  $groupIds
     * @param  array<string, mixed>  $desired
     */
    private function collectApplication(
        ControlHubContractItem $item,
        array $groupIds,
        bool $targetsInstance,
        array &$desired,
        ContractAssignmentResult $result,
    ): void {
        $application = Application::query()->where('app_id', $item->key)->first(['id']);

        if ($application === null) {
            $result->unresolved++;
            $this->recordVerdict(
                $item,
                ControlHubContractApplyStatus::Pending,
                "Application « {$item->key} » absente de l'inventaire local",
            );
            Log::warning('[ContractAssignmentReconciler] Application ordonnée absente de l\'inventaire', [
                'app_id' => $item->key,
            ]);

            return;
        }

        $this->recordVerdict($item, ControlHubContractApplyStatus::Applied);

        if ($targetsInstance) {
            $desired['defaults']['applications'][] = $application->id;

            return;
        }

        foreach ($groupIds as $groupId) {
            $desired['applications'][$application->id][] = $groupId;
        }
    }

    /**
     * @param  array<int, int>  $groupIds
     * @param  array<string, mixed>  $desired
     */
    private function collectShortcut(
        ControlHubContractItem $item,
        array $groupIds,
        bool $targetsInstance,
        array &$desired,
        ContractAssignmentResult $result,
    ): void {
        // Le raccourci a été matérialisé par ShortcutContractReconciler, qui tourne
        // avant nous ; s'il manque, c'est que l'item n'avait pas de cible.
        $shortcut = Shortcut::query()->where('controlhub_contract_key', $item->key)->first(['id']);

        if ($shortcut === null) {
            $result->unresolved++;
            $this->recordVerdict($item, ControlHubContractApplyStatus::Error, $this->shortcutFailureDetail($item));

            return;
        }

        $this->recordVerdict($item, ControlHubContractApplyStatus::Applied);

        if ($targetsInstance) {
            $desired['defaults']['shortcuts'][] = $shortcut->id;

            return;
        }

        foreach ($groupIds as $groupId) {
            $desired['shortcuts'][$shortcut->id][] = $groupId;
        }
    }

    /**
     * @param  array<int, int>  $groupIds
     * @param  array<string, mixed>  $desired
     */
    private function collectCapability(
        ControlHubContractItem $item,
        array $groupIds,
        bool $targetsInstance,
        array &$desired,
        ContractAssignmentResult $result,
    ): void {
        $capability = Capability::query()->where('key', $item->key)->first(['id']);

        if ($capability === null) {
            $result->unresolved++;
            $this->recordVerdict(
                $item,
                ControlHubContractApplyStatus::Error,
                "Capacité « {$item->key} » inconnue du catalogue SE5",
            );
            Log::warning('[ContractAssignmentReconciler] Capacité imposée inconnue du catalogue local', [
                'capability_key' => $item->key,
            ]);

            return;
        }

        // Une capacité d'instance se règle par son défaut diffusé, comme le fait
        // l'onglet « Registre / capacités » — il n'existe pas d'assignable « toute
        // la flotte », et en inventer un dédoublerait la source de vérité.
        $this->recordVerdict($item, ControlHubContractApplyStatus::Applied);

        if ($targetsInstance) {
            $this->applyCapabilityDefault($capability->id, (string) $item->value, $result);

            return;
        }

        $locked = $item->enforcement_state === ControlHubEnforcementState::Locked;

        foreach ($groupIds as $groupId) {
            $desired['capabilities'][$capability->id][$groupId] = [
                'value' => $item->value,
                'locked' => $locked,
            ];
        }
    }

    /**
     * @param  array<int, int>  $groupIds
     * @param  array<string, mixed>  $desired
     */
    private function collectWallpaper(
        string $bucket,
        ControlHubContractItem $item,
        array $groupIds,
        bool $targetsInstance,
        array &$desired,
        ContractAssignmentResult $result,
    ): void {
        $checksum = $item->artifact_checksum;

        if ($checksum === null || $checksum === '') {
            $result->unresolved++;
            $this->recordVerdict(
                $item,
                ControlHubContractApplyStatus::Error,
                'Aucun bloc `artifact` : le contrat impose un fond sans dire quelle image',
            );
            Log::warning('[ContractAssignmentReconciler] Fond imposé sans descripteur d\'artefact', [
                'type' => $item->type,
                'key' => $item->key,
            ]);

            return;
        }

        $asset = WallpaperAsset::query()->where('checksum', $checksum)->first(['id']);

        if ($asset === null) {
            // L'image n'est pas encore tirée : le pull est asynchrone et peut aboutir
            // après nous. La prochaine réception, ou la commande de reprise, posera
            // l'assignation.
            $result->unresolved++;
            $this->recordVerdict(
                $item,
                ControlHubContractApplyStatus::Pending,
                'Image pas encore tirée localement (canal ④ en cours)',
            );

            return;
        }

        $this->recordVerdict($item, ControlHubContractApplyStatus::Applied);

        if ($targetsInstance) {
            $desired['defaults'][$bucket] = $asset->id;

            return;
        }

        foreach ($groupIds as $groupId) {
            $desired[$bucket][$groupId] = $asset->id;
        }
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Écritures — chaque support pose ce qui manque puis retire ce qui n'est
    // plus voulu, en ne touchant QUE les lignes d'origine amont.
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * @param  array<int, array<int, int>>  $desired
     */
    private function applyApplications(array $desired, ContractAssignmentResult $result): void
    {
        $this->syncPivot(
            'application_workstation_group',
            'application_id',
            'workstation_group_id',
            $desired,
            $result,
        );
    }

    /**
     * @param  array<int, array<int, int>>  $desired
     */
    private function applyShortcuts(array $desired, ContractAssignmentResult $result): void
    {
        $wanted = [];
        foreach ($desired as $shortcutId => $groupIds) {
            foreach (array_unique($groupIds) as $groupId) {
                $wanted[] = ['shortcut_id' => $shortcutId, 'assignable_id' => $groupId];
            }
        }

        $existing = DB::table('shortcut_assignables')
            ->where('assignable_type', WorkstationGroup::class)
            ->where('managed_by_control_hub', true)
            ->get(['id', 'shortcut_id', 'assignable_id']);

        $wantedKeys = array_flip(array_map(
            fn (array $row): string => $row['shortcut_id'].':'.$row['assignable_id'],
            $wanted,
        ));

        foreach ($existing as $row) {
            if (! isset($wantedKeys[$row->shortcut_id.':'.$row->assignable_id])) {
                DB::table('shortcut_assignables')->where('id', $row->id)->delete();
                $result->detached++;
            }
        }

        $existingKeys = $existing
            ->mapWithKeys(fn ($row): array => [$row->shortcut_id.':'.$row->assignable_id => true])
            ->all();

        foreach ($wanted as $row) {
            $key = $row['shortcut_id'].':'.$row['assignable_id'];
            if (isset($existingKeys[$key])) {
                continue;
            }

            // Une assignation posée à la main pour le même couple existe peut-être :
            // on ne la duplique pas, on l'adopte (elle devient d'origine amont).
            $adopted = DB::table('shortcut_assignables')
                ->where('shortcut_id', $row['shortcut_id'])
                ->where('assignable_type', WorkstationGroup::class)
                ->where('assignable_id', $row['assignable_id'])
                ->update(['managed_by_control_hub' => true, 'updated_at' => now()]);

            if ($adopted === 0) {
                DB::table('shortcut_assignables')->insert([
                    'shortcut_id' => $row['shortcut_id'],
                    'assignable_type' => WorkstationGroup::class,
                    'assignable_id' => $row['assignable_id'],
                    'managed_by_control_hub' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $result->attached++;
        }
    }

    /**
     * Un item `permissive` ne reprend jamais la main sur un parc que l'administrateur
     * a réglé lui-même : `managed_by_control_hub = false` dit que la ligne lui
     * appartient désormais, et la réception passe son chemin. Sans cette lecture,
     * « permissif » ne voudrait rien dire pour ce canal — l'amont écrit dans la même
     * ligne que l'administrateur, et sa valeur reviendrait à chaque réception.
     *
     * Un item `locked`, lui, écrit toujours et reprend la ligne : c'est par ce
     * chemin qu'un contrat qui durcit un item récupère un parc parti en surcharge.
     *
     * @param  array<int, array<int, array{value: string|null, locked: bool}>>  $desired
     */
    private function applyCapabilities(array $desired, ContractAssignmentResult $result): void
    {
        $existing = DB::table('capability_assignments')
            ->where('assignable_type', WorkstationGroup::class)
            ->where('managed_by_control_hub', true)
            ->get(['id', 'capability_id', 'assignable_id']);

        $wantedKeys = [];
        foreach ($desired as $capabilityId => $byGroup) {
            foreach ($byGroup as $groupId => $directive) {
                $wantedKeys[$capabilityId.':'.$groupId] = true;
            }
        }

        foreach ($existing as $row) {
            if (! isset($wantedKeys[$row->capability_id.':'.$row->assignable_id])) {
                DB::table('capability_assignments')->where('id', $row->id)->delete();
                $result->detached++;
            }
        }

        foreach ($desired as $capabilityId => $byGroup) {
            foreach ($byGroup as $groupId => $directive) {
                $value = $directive['value'];
                $match = [
                    'capability_id' => $capabilityId,
                    'assignable_type' => WorkstationGroup::class,
                    'assignable_id' => $groupId,
                ];

                $current = DB::table('capability_assignments')
                    ->where($match)
                    ->first(['id', 'value', 'managed_by_control_hub']);

                if ($current === null) {
                    DB::table('capability_assignments')->insert($match + [
                        'value' => $value,
                        'managed_by_control_hub' => true,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    $result->attached++;

                    continue;
                }

                if (! $directive['locked'] && ! (bool) $current->managed_by_control_hub) {
                    continue;
                }

                // Rien à écrire quand la valeur ET l'origine sont déjà les bonnes :
                // sans cette comparaison la passe compterait une pose à chaque
                // réception et remonterait un mouvement là où rien n'a bougé.
                if ((string) $current->value === (string) $value && (bool) $current->managed_by_control_hub) {
                    continue;
                }

                DB::table('capability_assignments')->where('id', $current->id)->update([
                    'value' => $value,
                    'managed_by_control_hub' => true,
                    'updated_at' => now(),
                ]);
                $result->attached++;
            }
        }
    }

    /**
     * Fond de bureau et fond de verrouillage partagent la table et la bibliothèque
     * d'assets : seule la colonne `type` les distingue, et chaque passe ne prune que
     * les lignes de SON type — un parc peut porter les deux.
     *
     * @param  string  $wallpaperType  {@see Wallpaper::TYPE_WALLPAPER} ou {@see Wallpaper::TYPE_LOCKSCREEN}
     * @param  array<int, int>  $desired  [groupId => assetId]
     */
    private function applyWallpapers(string $wallpaperType, array $desired, ContractAssignmentResult $result): void
    {
        $existing = Wallpaper::query()
            ->where('owner_type', WorkstationGroup::class)
            ->where('type', $wallpaperType)
            ->where('managed_by_control_hub', true)
            ->get(['id', 'owner_id']);

        foreach ($existing as $wallpaper) {
            if (! isset($desired[$wallpaper->owner_id])) {
                $wallpaper->delete();
                $result->detached++;
            }
        }

        // `wallpapers.name` est NOT NULL et porte, par convention de l'interface, le
        // nom du parc propriétaire.
        $groupNames = WorkstationGroup::query()
            ->whereIn('id', array_keys($desired))
            ->pluck('name', 'id');

        foreach ($desired as $groupId => $assetId) {
            Wallpaper::query()->updateOrCreate(
                [
                    'owner_type' => WorkstationGroup::class,
                    'owner_id' => $groupId,
                    'type' => $wallpaperType,
                ],
                [
                    'asset_id' => $assetId,
                    'managed_by_control_hub' => true,
                    'is_default' => false,
                    'name' => (string) ($groupNames[$groupId] ?? 'parc'),
                ],
            );
            $result->attached++;
        }
    }

    /**
     * Défauts d'établissement (cible `instance`) : posés sur ce que le contrat
     * demande, retirés de ce qu'il ne demande plus — sans jamais toucher un défaut
     * que l'administrateur a posé lui-même.
     *
     * @param  array<string, mixed>  $defaults
     */
    private function applyDefaults(array $defaults, ContractAssignmentResult $result): void
    {
        $this->syncParcDefault(Application::query(), $defaults['applications'], $result);
        $this->syncParcDefault(Shortcut::query(), $defaults['shortcuts'], $result);

        $etabDefaults = [
            Wallpaper::TYPE_WALLPAPER => $defaults['wallpapers'],
            Wallpaper::TYPE_LOCKSCREEN => $defaults['lockscreens'],
        ];

        foreach ($etabDefaults as $wallpaperType => $assetId) {
            if ($assetId === null) {
                continue;
            }

            Wallpaper::query()->updateOrCreate(
                ['owner_type' => null, 'owner_id' => null, 'type' => $wallpaperType],
                [
                    'asset_id' => $assetId,
                    'is_default' => true,
                    'managed_by_control_hub' => true,
                    'name' => 'défaut étab',
                ],
            );
            $result->defaults++;
        }
    }

    /**
     * Bascule `is_parc_default` sur les seules lignes que le contrat gouverne.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<*>  $query
     * @param  array<int, int>  $wantedIds
     */
    private function syncParcDefault($query, array $wantedIds, ContractAssignmentResult $result): void
    {
        $wantedIds = array_values(array_unique($wantedIds));
        $table = $query->getModel()->getTable();

        // Le contrat ne retire QUE ce qu'il a lui-même posé. Sur ces deux tables le
        // marqueur d'origine est porté par la ligne elle-même : une application est
        // dite amont par `managed_by_control_hub`, un raccourci par sa clé de contrat.
        //
        // ⚠️ ASYMÉTRIE ASSUMÉE : la pose, elle, est inconditionnelle — le contrat peut
        // ordonner en défaut de parc un objet d'origine LOCALE. Le retrait ne le
        // touchera pas quand l'ordre disparaîtra : ce défaut de parc survivra à l'ordre
        // qui l'a créé, visible dans l'interface et retirable à la main. Le sens de
        // l'asymétrie est délibéré — mieux vaut un défaut de trop, que l'administrateur
        // voit et corrige, qu'un objet local dépouillé sans que personne l'ait demandé.
        $ownedByContract = $table === 'shortcuts'
            ? (clone $query)->whereNotNull('controlhub_contract_key')
            : (clone $query)->where('managed_by_control_hub', true);

        (clone $ownedByContract)
            ->where('is_parc_default', true)
            ->when($wantedIds !== [], fn ($q) => $q->whereNotIn('id', $wantedIds))
            ->update(['is_parc_default' => false]);

        if ($wantedIds !== []) {
            $applied = (clone $query)
                ->whereIn('id', $wantedIds)
                ->where('is_parc_default', false)
                ->update(['is_parc_default' => true]);

            $result->defaults += $applied;
        }
    }

    /**
     * Aligne le défaut diffusé d'une capacité sur la valeur imposée.
     */
    private function applyCapabilityDefault(int $capabilityId, string $value, ContractAssignmentResult $result): void
    {
        $capability = Capability::query()->find($capabilityId);

        if ($capability === null || (string) $capability->default_value === $value) {
            return;
        }

        $capability->default_value = $value;
        $capability->save();
        $result->defaults++;
    }

    /**
     * Réconcilie un pivot simple (deux colonnes d'identifiants) sur les seules
     * lignes d'origine amont.
     *
     * @param  array<int, array<int, int>>  $desired  [ownerId => [groupId, …]]
     */
    private function syncPivot(
        string $table,
        string $ownerColumn,
        string $groupColumn,
        array $desired,
        ContractAssignmentResult $result,
    ): void {
        $wantedKeys = [];
        foreach ($desired as $ownerId => $groupIds) {
            foreach (array_unique($groupIds) as $groupId) {
                $wantedKeys[$ownerId.':'.$groupId] = ['owner' => $ownerId, 'group' => $groupId];
            }
        }

        $existing = DB::table($table)
            ->where('managed_by_control_hub', true)
            ->get(['id', $ownerColumn, $groupColumn]);

        foreach ($existing as $row) {
            if (! isset($wantedKeys[$row->{$ownerColumn}.':'.$row->{$groupColumn}])) {
                DB::table($table)->where('id', $row->id)->delete();
                $result->detached++;
            }
        }

        $existingKeys = $existing
            ->mapWithKeys(fn ($row): array => [$row->{$ownerColumn}.':'.$row->{$groupColumn} => true])
            ->all();

        foreach ($wantedKeys as $key => $pair) {
            if (isset($existingKeys[$key])) {
                continue;
            }

            $adopted = DB::table($table)
                ->where($ownerColumn, $pair['owner'])
                ->where($groupColumn, $pair['group'])
                ->update(['managed_by_control_hub' => true, 'updated_at' => now()]);

            if ($adopted === 0) {
                DB::table($table)->insert([
                    $ownerColumn => $pair['owner'],
                    $groupColumn => $pair['group'],
                    'managed_by_control_hub' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $result->attached++;
        }
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Verdicts — ce que le canal ③ rapportera de chaque item.
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Retient le verdict d'un item jusqu'à l'écriture.
     *
     * Le dernier appel gagne : un item traversé plusieurs fois par la passe ne
     * garde que sa conclusion.
     */
    private function recordVerdict(
        ControlHubContractItem $item,
        ControlHubContractApplyStatus $status,
        ?string $detail = null,
    ): void {
        $this->verdicts[$item->id] = [$status, $detail];
    }

    /**
     * Écrit les verdicts de la passe, un UPDATE par valeur distincte.
     *
     * Les items d'un type que ce réconciliateur ne revendique pas ne sont pas
     * touchés : leur `apply_status` reste `null` et le canal ③ retombe sur sa
     * politique d'origine.
     */
    private function flushVerdicts(): void
    {
        foreach ($this->verdicts as $itemId => [$status, $detail]) {
            ControlHubContractItem::query()
                ->whereKey($itemId)
                ->update(['apply_status' => $status->value, 'apply_detail' => $detail]);
        }
    }

    /**
     * Pourquoi un raccourci imposé n'a pas de ligne en bibliothèque.
     *
     * ShortcutContractReconciler passe avant nous et ne matérialise rien quand le
     * payload ne dit pas vers quoi le raccourci pointe. On rejoue la même règle de
     * cible pour distinguer ce cas — actionnable côté amont — d'un échec de
     * matérialisation, qui lui demande d'aller lire le journal.
     */
    private function shortcutFailureDetail(ControlHubContractItem $item): string
    {
        $spec = is_array($item->spec) ? $item->spec : [];
        $target = trim((string) ($spec['windows_link'] ?? $item->value ?? ''));

        return $target === ''
            ? 'Raccourci sans cible : ni `spec.windows_link` ni `value` ne désignent quoi ouvrir'
            : 'Raccourci non matérialisé en bibliothèque';
    }
}

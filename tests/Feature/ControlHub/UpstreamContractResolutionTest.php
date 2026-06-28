<?php

declare(strict_types=1);

namespace Tests\Feature\ControlHub;

use App\Enums\ControlHubContractTarget;
use App\Enums\ControlHubEnforcementState;
use App\Enums\ResourceSemantics;
use App\Enums\StateMaille;
use App\Enums\StateScope;
use App\Models\ControlHubContract;
use App\Models\ControlHubContractItem;
use App\Models\Workstation;
use App\Models\WorkstationGroup;
use App\Observers\WorkstationGroupObserver;
use App\Services\Agent\Contracts\KeyedExclusiveProvider;
use App\Services\Agent\Contracts\StateProvider;
use App\Services\Agent\StateCandidate;
use App\Services\Agent\StateCompiler;
use App\Services\Agent\StateContract;
use App\Services\Agent\StateHasher;
use App\Services\Agent\TargetContext;
use App\Services\ControlHub\Resolution\KeyedUpstreamAwareProvider;
use App\Services\ControlHub\Resolution\RegistryUpstreamAdapter;
use App\Services\ControlHub\Resolution\ShortcutsUpstreamAdapter;
use App\Services\ControlHub\Resolution\UpstreamAwareProvider;
use App\Services\ControlHub\Resolution\UpstreamContractSource;
use App\Services\ControlHub\Resolution\UpstreamPayloadAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 28.3 — Résolution AMONT > local dans `StateCompiler` (end-to-end).
 *
 * Contrat persisté (factories 28.1) → {@see UpstreamContractSource} → providers
 * décorés ({@see UpstreamAwareProvider}) → compilé. Couvre : amont prime sur
 * local (même clé), empilement local sans équivalent amont, standalone
 * byte-identique + comptage de requêtes (NFR3), déterminisme du hash (NFR4),
 * `absent` non injecté / `locked`+`permissive` priment (AC #6), contrat severed
 * inerte (Epic 32), R3 (aucun « central »).
 *
 * **Story 30.4** — ciblage par label : un item `target_type = label` s'applique
 * AUX postes portant le label (`WorkstationGroup.controlhub_label`), reste inerte
 * pour les autres ; cumul verrou/permissif SANS spécificité inter-parcs ;
 * collision de deux verrous → warning `agent.state.conflict` + tiebreak
 * déterministe ; standalone/sans-item-label byte-identique + zéro requête WG.
 *
 * Tests HÔTE (php8.4 + pdo_sqlite), `RefreshDatabase`.
 */
class UpstreamContractResolutionTest extends TestCase
{
    use RefreshDatabase;

    private StateHasher $hasher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hasher = new StateHasher();
        // Neutralise la sync AD au WorkstationGroup::factory()->create() (pas de
        // LDAP en HÔTE) — Story 30.4 attache des parcs porteurs de label.
        WorkstationGroupObserver::disableSync();
    }

    protected function tearDown(): void
    {
        WorkstationGroupObserver::enableSync();
        parent::tearDown();
    }

    // ── AC #1 — amont prime sur local pour la même clé (registry) ─────────

    #[Test]
    public function upstream_beats_local_same_key(): void
    {
        $contract = ControlHubContract::factory()->create(); // link_state = active
        ControlHubContractItem::factory()->create([
            'controlhub_contract_id' => $contract->id,
            'type' => 'registry',
            'key' => 'HKCU|Software\\Foo|Bar|REG_DWORD',
            'value' => '1',
            'enforcement_state' => ControlHubEnforcementState::Locked,
            'target_type' => ControlHubContractTarget::Instance,
            'target_label' => '',
        ]);

        // Réglage LOCAL sur la MÊME clé {hive,path,name}, valeur différente.
        $local = $this->keyedExclusiveProvider('registry', StateScope::Session, [
            new StateCandidate(
                StateMaille::LogicalGroup,
                $this->regPayload('HKCU', 'Software\\Foo', 'Bar', 0),
                now(),
                1,
            ),
        ]);

        $items = $this->compileDecorated([$local], [new RegistryUpstreamAdapter()])[StateContract::SCOPE_SESSION];

        self::assertCount(1, $items, 'une seule valeur par clé');
        self::assertSame(1, $items[0]['payload']['value'], 'la valeur AMONT gagne (maille Upstream la plus spécifique)');
    }

    #[Test]
    public function upstream_hklm_item_routes_to_machine_scope(): void
    {
        // Routage de portée : hive=HKLM → portée MACHINE (service SYSTEM), pas
        // SESSION. Le couple (providerType, scope) discrimine les deux providers
        // registry — un item HKLM ne doit atteindre QUE le scope machine.
        $contract = ControlHubContract::factory()->create();
        ControlHubContractItem::factory()->create([
            'controlhub_contract_id' => $contract->id,
            'type' => 'registry',
            'key' => 'HKLM|Software\\Policies\\Foo|Bar|REG_DWORD',
            'value' => '1',
            'enforcement_state' => ControlHubEnforcementState::Locked,
            'target_type' => ControlHubContractTarget::Instance,
            'target_label' => '',
        ]);

        // Provider registry de portée MACHINE (équivalent RegistryMachineCapabilityProvider).
        $local = $this->keyedExclusiveProvider('registry', StateScope::Machine, []);
        $compiled = $this->compileDecorated([$local], [new RegistryUpstreamAdapter()]);

        $machine = $compiled[StateContract::SCOPE_MACHINE];
        self::assertCount(1, $machine, 'l\'item HKLM est injecté dans la portée MACHINE');
        self::assertSame('HKLM', $machine[0]['payload']['hive']);
        self::assertSame(1, $machine[0]['payload']['value'], 'la valeur amont HKLM est servie en scope machine');
        self::assertSame([], $compiled[StateContract::SCOPE_SESSION], 'aucun item HKLM ne fuit en portée SESSION');
    }

    #[Test]
    public function upstream_reg_multi_sz_value_is_coerced_to_list(): void
    {
        // REG_MULTI_SZ : la value amont (chaîne JSON array) doit être coercée en
        // list<string>, iso AbstractCapabilityStateProvider::typedValue() — sinon
        // l'agent reçoit une string là où il attend un tableau (#2).
        $contract = ControlHubContract::factory()->create();
        ControlHubContractItem::factory()->create([
            'controlhub_contract_id' => $contract->id,
            'type' => 'registry',
            'key' => 'HKCU|Software\\Foo|Multi|REG_MULTI_SZ',
            'value' => '["alpha","beta"]',
            'enforcement_state' => ControlHubEnforcementState::Locked,
            'target_type' => ControlHubContractTarget::Instance,
            'target_label' => '',
        ]);

        $local = $this->keyedExclusiveProvider('registry', StateScope::Session, []);
        $items = $this->compileDecorated([$local], [new RegistryUpstreamAdapter()])[StateContract::SCOPE_SESSION];

        self::assertCount(1, $items);
        self::assertSame(['alpha', 'beta'], $items[0]['payload']['value'], 'REG_MULTI_SZ → list<string>');
    }

    // ── AC #2 — empilement : le local sans équivalent amont survit ────────

    #[Test]
    public function local_without_upstream_equivalent_survives_aggregate(): void
    {
        $contract = ControlHubContract::factory()->create();
        ControlHubContractItem::factory()->create([
            'controlhub_contract_id' => $contract->id,
            'type' => 'shortcuts',
            'key' => 'AmontApp',
            'value' => '\\\\srv\\amont.exe',
            'enforcement_state' => ControlHubEnforcementState::Locked,
            'target_type' => ControlHubContractTarget::Instance,
            'target_label' => '',
        ]);

        // Raccourcis LOCAUX distincts (aucun équivalent amont).
        $local = $this->fakeProvider('shortcuts', ResourceSemantics::Aggregate, StateScope::MachineUser, [
            new StateCandidate(StateMaille::User, ['name' => 'LocalA', 'target' => 'a'], now(), 10),
            new StateCandidate(StateMaille::LogicalGroup, ['name' => 'LocalB', 'target' => 'b'], now(), 20),
        ]);

        $items = $this->compileDecorated([$local], [new ShortcutsUpstreamAdapter()])[StateContract::SCOPE_MACHINE_USER];

        $names = collect($items)->pluck('payload.name')->sort()->values()->all();
        self::assertSame(['AmontApp', 'LocalA', 'LocalB'], $names, 'l\'item amont s\'AJOUTE à l\'union, les locaux survivent');
    }

    #[Test]
    public function local_exclusive_key_not_imposed_by_upstream_survives(): void
    {
        // Type exclusif par clé : l'amont impose la clé Beta ; la clé Alpha (non
        // imposée) conserve sa valeur locale gagnante. Empilement par clé (AC #2).
        $contract = ControlHubContract::factory()->create();
        ControlHubContractItem::factory()->create([
            'controlhub_contract_id' => $contract->id,
            'type' => 'registry',
            'key' => 'HKCU|P|Beta|REG_DWORD',
            'value' => '9',
            'enforcement_state' => ControlHubEnforcementState::Locked,
            'target_type' => ControlHubContractTarget::Instance,
            'target_label' => '',
        ]);

        $local = $this->keyedExclusiveProvider('registry', StateScope::Session, [
            new StateCandidate(StateMaille::LogicalGroup, $this->regPayload('HKCU', 'P', 'Alpha', 5), now(), 1),
        ]);

        $items = $this->compileDecorated([$local], [new RegistryUpstreamAdapter()])[StateContract::SCOPE_SESSION];
        $byName = collect($items)->keyBy('payload.name');

        self::assertCount(2, $items, 'clé locale Alpha + clé amont Beta s\'accumulent');
        self::assertSame(5, $byName['Alpha']['payload']['value'], 'la clé locale non imposée survit');
        self::assertSame(9, $byName['Beta']['payload']['value'], 'la clé imposée par l\'amont est présente');
    }

    // ── AC #3 / #5c — standalone byte-identique (test révélateur NFR3) ────

    #[Test]
    public function no_active_contract_output_is_byte_identical_and_single_cheap_query(): void
    {
        // Aucun contrat actif : le compilé via providers DÉCORÉS doit être
        // STRICTEMENT identique (items + ordre + hashState) au compilé via les
        // MÊMES providers NON décorés.
        $providers = [
            $this->fakeProvider('agg', ResourceSemantics::Aggregate, StateScope::MachineUser, [
                new StateCandidate(StateMaille::Broadcast, ['k' => 'a'], now(), 1),
                new StateCandidate(StateMaille::User, ['k' => 'b'], now(), 2),
            ]),
            $this->keyedExclusiveProvider('registry', StateScope::Session, [
                new StateCandidate(StateMaille::LogicalGroup, $this->regPayload('HKCU', 'P', 'X', 7), now(), 3),
            ]),
        ];
        $ctx = $this->machineOnlyContext();

        $source = new UpstreamContractSource([new RegistryUpstreamAdapter(), new ShortcutsUpstreamAdapter()]);
        $decorated = array_map(fn (StateProvider $p): StateProvider => UpstreamAwareProvider::wrap($p, $source), $providers);

        $bare = (new StateCompiler($this->hasher, $providers))->compile($ctx);

        // Comptage de requêtes pendant la compilation décorée.
        DB::flushQueryLog();
        DB::enableQueryLog();
        $deco = (new StateCompiler($this->hasher, $decorated))->compile($ctx);
        $log = DB::getQueryLog();
        DB::disableQueryLog();

        // Byte-identique : items par portée + hash global.
        foreach (StateContract::scopes() as $scope) {
            self::assertSame($bare[$scope], $deco[$scope], "portée {$scope} : décoré == non décoré");
        }
        // hashState() exclut déjà `generated_at` (VOLATILE_STATE_KEYS) : pas de
        // neutralisation à faire ici — l'égalité prouve la byte-identité du reste.
        self::assertSame(
            $this->hasher->hashState($bare),
            $this->hasher->hashState($deco),
            'hashState identique sans contrat (NFR3)',
        );

        // Court-circuit : EXACTEMENT 1 requête « contrat actif ? », ZÉRO sur items
        // (pas de N+1 par provider — la résolution est partagée et mémoïsée).
        $contractQueries = $this->countQueries($log, '"controlhub_contracts"');
        $itemQueries = $this->countQueries($log, '"controlhub_contract_items"');
        self::assertSame(1, $contractQueries, 'au plus 1 requête « contrat actif ? » partagée');
        self::assertSame(0, $itemQueries, 'aucune requête items quand aucun contrat actif (court-circuit)');
        // AC #5 — sans contrat, la dimension label n'est jamais explorée : ZÉRO
        // requête « labels portés » (le court-circuit `$groupedByLabel === []`
        // précède toute lecture de `workstation_groups`).
        self::assertSame(0, $this->countQueries($log, '"workstation_groups"'), 'aucune requête « labels portés » sans contrat actif (court-circuit NFR3)');
    }

    // ── AC #4 — déterminisme du hash avec contrat actif ───────────────────

    #[Test]
    public function deterministic_hash_with_active_contract(): void
    {
        $contract = ControlHubContract::factory()->create();
        ControlHubContractItem::factory()->create([
            'controlhub_contract_id' => $contract->id,
            'type' => 'registry',
            'key' => 'HKCU|P|Det|REG_DWORD',
            'value' => '3',
            'enforcement_state' => ControlHubEnforcementState::Locked,
            'target_type' => ControlHubContractTarget::Instance,
            'target_label' => '',
        ]);

        $local = $this->keyedExclusiveProvider('registry', StateScope::Session, []);
        $ctx = $this->machineOnlyContext();

        // Source FRAÎCHE par compilation (en prod : conteneur par-requête).
        $first = $this->compileDecoratedFull([$local], [new RegistryUpstreamAdapter()], $ctx);
        $this->travel(3)->hours();
        $second = $this->compileDecoratedFull([$local], [new RegistryUpstreamAdapter()], $ctx);

        self::assertNotSame($first['generated_at'], $second['generated_at']);
        self::assertSame(
            $this->hasher->hashState($first),
            $this->hasher->hashState($second),
            'même contrat + même contexte → même hash (ETag 23.5)',
        );
        // L'item amont est bien servi (sourceId = id de l'item contrat, stable).
        self::assertSame(3, $first[StateContract::SCOPE_SESSION][0]['payload']['value']);
    }

    // ── AC #6 — absent non injecté ; locked + permissive priment ──────────

    #[Test]
    public function absent_item_is_not_injected(): void
    {
        $contract = ControlHubContract::factory()->create();
        ControlHubContractItem::factory()->absent()->create([
            'controlhub_contract_id' => $contract->id,
            'type' => 'registry',
            'key' => 'HKCU|P|Foo|REG_DWORD',
            'target_type' => ControlHubContractTarget::Instance,
            'target_label' => '',
        ]);

        // Réglage local sur la clé que l'amont déclare « absente ».
        $local = $this->keyedExclusiveProvider('registry', StateScope::Session, [
            new StateCandidate(StateMaille::LogicalGroup, $this->regPayload('HKCU', 'P', 'Foo', 7), now(), 1),
        ]);

        $items = $this->compileDecorated([$local], [new RegistryUpstreamAdapter()])[StateContract::SCOPE_SESSION];

        self::assertCount(1, $items);
        self::assertSame(7, $items[0]['payload']['value'], 'l\'item absent n\'est pas injecté → le local survit');
    }

    #[Test]
    public function locked_wins_over_local_but_permissive_is_overridden_by_local(): void
    {
        // Story 29.3 (relaxation permissive livrée) — la maille diverge selon
        // l'enforcement : `locked` → `Upstream` (rang -1, INBATTABLE) ;
        // `permissive` → `UpstreamPermissive` (rang 6, PLANCHER battable). Un
        // override local (maille `LogicalGroup`) surcharge donc le `permissive`
        // mais JAMAIS le `locked`. (Remplace l'ancien comportement 28.3 où
        // `permissive` se comportait comme `locked` — couture Epic 29 fermée.)
        $contract = ControlHubContract::factory()->create();
        ControlHubContractItem::factory()->permissive()->create([
            'controlhub_contract_id' => $contract->id,
            'type' => 'registry',
            'key' => 'HKCU|P|Perm|REG_DWORD',
            'value' => '1',
            'target_type' => ControlHubContractTarget::Instance,
            'target_label' => '',
        ]);
        ControlHubContractItem::factory()->create([
            'controlhub_contract_id' => $contract->id,
            'type' => 'registry',
            'key' => 'HKCU|P|Lock|REG_DWORD',
            'value' => '2',
            'enforcement_state' => ControlHubEnforcementState::Locked,
            'target_type' => ControlHubContractTarget::Instance,
            'target_label' => '',
        ]);

        $local = $this->keyedExclusiveProvider('registry', StateScope::Session, [
            new StateCandidate(StateMaille::LogicalGroup, $this->regPayload('HKCU', 'P', 'Perm', 0), now(), 1),
            new StateCandidate(StateMaille::LogicalGroup, $this->regPayload('HKCU', 'P', 'Lock', 0), now(), 2),
        ]);

        $items = $this->compileDecorated([$local], [new RegistryUpstreamAdapter()])[StateContract::SCOPE_SESSION];
        $byName = collect($items)->keyBy('payload.name');

        self::assertSame(0, $byName['Perm']['payload']['value'], 'permissive est un plancher : l\'override local le surcharge (FR4 — Story 29.3)');
        self::assertSame(2, $byName['Lock']['payload']['value'], 'locked reste inbattable (Upstream rang -1) — l\'amont prime sur le local');
    }

    // ── Story 30.4 — ciblage par label ────────────────────────────────────

    #[Test]
    public function label_item_applies_to_workstation_carrying_the_label(): void
    {
        // AC #1 — un item `locked` ciblant `label:salle-info` gagne sur le local
        // pour un poste membre d'un parc portant `controlhub_label = salle-info`.
        $contract = ControlHubContract::factory()->create();
        ControlHubContractItem::factory()->forLabel('salle-info')->create([
            'controlhub_contract_id' => $contract->id,
            'type' => 'registry',
            'key' => 'HKCU|P|Foo|REG_DWORD',
            'value' => '1',
            'enforcement_state' => ControlHubEnforcementState::Locked,
        ]);

        $local = $this->keyedExclusiveProvider('registry', StateScope::Session, [
            new StateCandidate(StateMaille::LogicalGroup, $this->regPayload('HKCU', 'P', 'Foo', 0), now(), 1),
        ]);
        $ctx = $this->contextCarryingLabels(['salle-info']);

        $items = $this->compileDecoratedFull([$local], [new RegistryUpstreamAdapter()], $ctx)[StateContract::SCOPE_SESSION];

        self::assertCount(1, $items, 'une seule valeur par clé');
        self::assertSame(1, $items[0]['payload']['value'], 'la valeur AMONT (label porté) gagne — maille Upstream');
    }

    #[Test]
    public function label_item_does_not_apply_when_workstation_lacks_the_label(): void
    {
        // AC #2 — même item `label:salle-info`, mais le poste ne porte PAS ce label
        // (il porte un autre label) → l'item amont n'est pas injecté, le local survit.
        $contract = ControlHubContract::factory()->create();
        ControlHubContractItem::factory()->forLabel('salle-info')->create([
            'controlhub_contract_id' => $contract->id,
            'type' => 'registry',
            'key' => 'HKCU|P|Foo|REG_DWORD',
            'value' => '1',
            'enforcement_state' => ControlHubEnforcementState::Locked,
        ]);

        $local = $this->keyedExclusiveProvider('registry', StateScope::Session, [
            new StateCandidate(StateMaille::LogicalGroup, $this->regPayload('HKCU', 'P', 'Foo', 7), now(), 1),
        ]);
        // Poste membre d'un parc, mais portant un AUTRE label.
        $ctx = $this->contextCarryingLabels(['autre-salle']);

        $items = $this->compileDecoratedFull([$local], [new RegistryUpstreamAdapter()], $ctx)[StateContract::SCOPE_SESSION];

        self::assertCount(1, $items);
        self::assertSame(7, $items[0]['payload']['value'], 'le poste ne porte pas le label → la valeur locale survit');
    }

    #[Test]
    public function permissive_label_is_overridden_by_any_local(): void
    {
        // AC #3 (volet permissif) — un item `permissive` ciblant un label porté est
        // un PLANCHER (maille UpstreamPermissive rang 6) : tout candidat local le
        // surcharge. (Le volet verrou `locked > local` est couvert par AC #1.)
        $contract = ControlHubContract::factory()->create();
        ControlHubContractItem::factory()->forLabel('salle-info')->permissive()->create([
            'controlhub_contract_id' => $contract->id,
            'type' => 'registry',
            'key' => 'HKCU|P|Perm|REG_DWORD',
            'value' => '1',
        ]);

        $local = $this->keyedExclusiveProvider('registry', StateScope::Session, [
            new StateCandidate(StateMaille::LogicalGroup, $this->regPayload('HKCU', 'P', 'Perm', 0), now(), 1),
        ]);
        $ctx = $this->contextCarryingLabels(['salle-info']);

        $items = $this->compileDecoratedFull([$local], [new RegistryUpstreamAdapter()], $ctx)[StateContract::SCOPE_SESSION];

        self::assertCount(1, $items);
        self::assertSame(0, $items[0]['payload']['value'], 'permissif via label = plancher : l\'override local le surcharge (FR4)');
    }

    #[Test]
    public function two_locked_labels_same_key_emit_conflict_and_pick_deterministically(): void
    {
        // AC #4 — collision insoluble : deux items `locked` sur la MÊME exclusiveKey,
        // l'un via label:A l'autre via label:B, tous deux portés par le poste. Les
        // deux sont à la maille Upstream (rang -1) ⇒ même rang ⇒ aucun arbitrage par
        // parc (pas de spécificité inter-parcs) : tiebreak déterministe + warning
        // `agent.state.conflict`. 30.4 ne RÉSOUT pas la collision (→ 30.5).
        $contract = ControlHubContract::factory()->create();
        // `updated_at` IDENTIQUES sur les deux items (timestamp figé) : on neutralise
        // la dimension récence du tiebreak pour que le test prouve réellement le
        // critère SECONDAIRE `sourceId` desc, sans s'appuyer sur l'ordre de création.
        $ts = now();
        $itemA = ControlHubContractItem::factory()->forLabel('parc-a')->create([
            'controlhub_contract_id' => $contract->id,
            'type' => 'registry',
            'key' => 'HKCU|P|Foo|REG_DWORD',
            'value' => '1',
            'enforcement_state' => ControlHubEnforcementState::Locked,
            'updated_at' => $ts,
        ]);
        $itemB = ControlHubContractItem::factory()->forLabel('parc-b')->create([
            'controlhub_contract_id' => $contract->id,
            'type' => 'registry',
            'key' => 'HKCU|P|Foo|REG_DWORD',
            'value' => '2',
            'enforcement_state' => ControlHubEnforcementState::Locked,
            'updated_at' => $ts,
        ]);

        $local = $this->keyedExclusiveProvider('registry', StateScope::Session, []);
        $ctx = $this->contextCarryingLabels(['parc-a', 'parc-b']);

        $warnings = $this->captureAgentWarnings();
        $items = $this->compileDecoratedFull([$local], [new RegistryUpstreamAdapter()], $ctx)[StateContract::SCOPE_SESSION];

        // État DÉTERMINISTE (non vide) : `updated_at` ÉGAUX ⇒ la récence ne tranche
        // pas ⇒ le tiebreak retombe sur `sourceId` desc ⇒ l'item de plus grand id
        // gagne (itemB). C'est le critère secondaire que le test vise à PROUVER.
        self::assertCount(1, $items, 'un seul état servi malgré la collision (pas d\'état vide)');
        self::assertSame(2, $items[0]['payload']['value'], 'tiebreak déterministe (sourceId desc) — jamais par parc');

        // Warning de collision émis, maille `upstream`, les deux items en conflit.
        self::assertCount(1, $warnings);
        [$message, $context] = $warnings[0];
        self::assertStringContainsString('agent.state.conflict', $message);
        self::assertSame(StateMaille::Upstream->value, $context['maille']);
        self::assertEqualsCanonicalizing([$itemA->id, $itemB->id], $context['rule_ids']);
    }

    #[Test]
    public function active_contract_without_label_items_is_byte_identical_and_emits_no_label_query(): void
    {
        // AC #5/#6 — contrat ACTIF mais SANS aucun item `target_type=label` : le
        // compilé est byte-identique au standalone ET aucune requête « labels
        // portés » n'est émise (court-circuit `$groupedByLabel === []`), MÊME si le
        // poste appartient à un parc porteur de label (preuve forte du court-circuit).
        $contract = ControlHubContract::factory()->create();
        ControlHubContractItem::factory()->create([
            'controlhub_contract_id' => $contract->id,
            'type' => 'registry',
            'key' => 'HKCU|P|X|REG_DWORD',
            'value' => '5',
            'enforcement_state' => ControlHubEnforcementState::Locked,
            'target_type' => ControlHubContractTarget::Instance,
            'target_label' => '',
        ]);

        // Référence : le MÊME contrat (item instance) compilé sur un poste SANS
        // aucun parc. Sans item label, l'appartenance aux parcs doit être SANS
        // effet sur le compilé (la dimension label est inerte).
        $providers = [$this->keyedExclusiveProvider('registry', StateScope::Session, [])];
        $reference = $this->compileDecoratedFull(
            [$this->keyedExclusiveProvider('registry', StateScope::Session, [])],
            [new RegistryUpstreamAdapter()],
            $this->machineOnlyContext(),
        );

        // Poste rattaché à un parc PORTANT un label : si le court-circuit manquait,
        // `labelsCarriedBy()` requêterait `workstation_groups`.
        $ctx = $this->contextCarryingLabels(['un-label']);
        $source = new UpstreamContractSource([new RegistryUpstreamAdapter()]);
        $decorated = array_map(fn (StateProvider $p): StateProvider => UpstreamAwareProvider::wrap($p, $source), $providers);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $deco = (new StateCompiler($this->hasher, $decorated))->compile($ctx);
        $log = DB::getQueryLog();
        DB::disableQueryLog();

        foreach (StateContract::scopes() as $scope) {
            self::assertSame($reference[$scope], $deco[$scope], "portée {$scope} : l'appartenance aux parcs n'a aucun effet sans item label");
        }
        self::assertSame(5, $deco[StateContract::SCOPE_SESSION][0]['payload']['value'], 'l\'item instance est toujours servi (contrat actif)');
        self::assertSame(0, $this->countQueries($log, '"workstation_groups"'), 'aucune requête « labels portés » sans item label (court-circuit NFR3)');
    }

    #[Test]
    public function label_injection_is_deterministic_across_compilations(): void
    {
        // AC #6 — déterminisme : même contrat (item label) + même contexte, à deux
        // instants → même hashState (l'injection label est stable).
        $contract = ControlHubContract::factory()->create();
        ControlHubContractItem::factory()->forLabel('salle-info')->create([
            'controlhub_contract_id' => $contract->id,
            'type' => 'registry',
            'key' => 'HKCU|P|Det|REG_DWORD',
            'value' => '3',
            'enforcement_state' => ControlHubEnforcementState::Locked,
        ]);

        $local = $this->keyedExclusiveProvider('registry', StateScope::Session, []);
        $ctx = $this->contextCarryingLabels(['salle-info']);

        $first = $this->compileDecoratedFull([$local], [new RegistryUpstreamAdapter()], $ctx);
        $this->travel(3)->hours();
        $second = $this->compileDecoratedFull([$local], [new RegistryUpstreamAdapter()], $ctx);

        self::assertNotSame($first['generated_at'], $second['generated_at']);
        self::assertSame(
            $this->hasher->hashState($first),
            $this->hasher->hashState($second),
            'même contrat label + même contexte → même hash (ETag 23.5)',
        );
        self::assertSame(3, $first[StateContract::SCOPE_SESSION][0]['payload']['value']);
    }

    #[Test]
    public function label_item_applies_to_workstation_in_physical_group_carrying_the_label(): void
    {
        // AC #1 (volet salle physique) — `workstationGroupIds()` réunit
        // `physical ∪ logical` : un item `label:salle-info` doit s'appliquer aussi
        // quand c'est une SALLE PHYSIQUE (et non un parc logique) qui porte le
        // label. La maille reste `Upstream` (rang -1) — elle NE dépend PAS du type
        // de parc (FR12, pas de spécificité inter-parcs).
        $contract = ControlHubContract::factory()->create();
        ControlHubContractItem::factory()->forLabel('salle-info')->create([
            'controlhub_contract_id' => $contract->id,
            'type' => 'registry',
            'key' => 'HKCU|P|Foo|REG_DWORD',
            'value' => '1',
            'enforcement_state' => ControlHubEnforcementState::Locked,
        ]);

        $local = $this->keyedExclusiveProvider('registry', StateScope::Session, [
            new StateCandidate(StateMaille::LogicalGroup, $this->regPayload('HKCU', 'P', 'Foo', 0), now(), 1),
        ]);
        // Le label est porté par une SALLE PHYSIQUE (chemin physicalGroupIds).
        $ctx = $this->contextCarryingLabels(['salle-info'], physical: true);

        $items = $this->compileDecoratedFull([$local], [new RegistryUpstreamAdapter()], $ctx)[StateContract::SCOPE_SESSION];

        self::assertCount(1, $items, 'une seule valeur par clé');
        self::assertSame(1, $items[0]['payload']['value'], 'la valeur AMONT gagne via une salle physique portant le label');
    }

    #[Test]
    public function label_item_does_not_apply_when_workstation_has_no_group(): void
    {
        // AC #2 (volet poste sans aucun parc) — contrat actif AVEC item label, mais
        // le poste n'appartient à AUCUN groupe : `workstationGroupIds()` est vide,
        // le court-circuit `groupIds === []` de `labelsCarriedBy()` s'exerce, l'item
        // label n'est jamais injecté → le réglage local survit.
        $contract = ControlHubContract::factory()->create();
        ControlHubContractItem::factory()->forLabel('salle-info')->create([
            'controlhub_contract_id' => $contract->id,
            'type' => 'registry',
            'key' => 'HKCU|P|Foo|REG_DWORD',
            'value' => '1',
            'enforcement_state' => ControlHubEnforcementState::Locked,
        ]);

        $local = $this->keyedExclusiveProvider('registry', StateScope::Session, [
            new StateCandidate(StateMaille::LogicalGroup, $this->regPayload('HKCU', 'P', 'Foo', 7), now(), 1),
        ]);
        // Poste SANS aucun groupe (ni physique ni logique).
        $ctx = $this->machineOnlyContext();

        $items = $this->compileDecoratedFull([$local], [new RegistryUpstreamAdapter()], $ctx)[StateContract::SCOPE_SESSION];

        self::assertCount(1, $items);
        self::assertSame(7, $items[0]['payload']['value'], 'poste sans aucun parc → l\'item label n\'est pas injecté, le local survit');
    }

    #[Test]
    public function instance_and_label_items_are_both_served_to_a_carrying_workstation(): void
    {
        // M4 — `candidatesFor()` = `instance ∪ labels portés`. Un item INSTANCE sur
        // la clé K1 + un item LABEL sur la clé K2 (label porté par le poste) : les
        // DEUX items amont sont servis (preuve de la concaténation, pas un OU).
        $contract = ControlHubContract::factory()->create();
        ControlHubContractItem::factory()->create([
            'controlhub_contract_id' => $contract->id,
            'type' => 'registry',
            'key' => 'HKCU|P|Inst|REG_DWORD',
            'value' => '11',
            'enforcement_state' => ControlHubEnforcementState::Locked,
            'target_type' => ControlHubContractTarget::Instance,
            'target_label' => '',
        ]);
        ControlHubContractItem::factory()->forLabel('salle-info')->create([
            'controlhub_contract_id' => $contract->id,
            'type' => 'registry',
            'key' => 'HKCU|P|Lab|REG_DWORD',
            'value' => '22',
            'enforcement_state' => ControlHubEnforcementState::Locked,
        ]);

        $local = $this->keyedExclusiveProvider('registry', StateScope::Session, []);
        $ctx = $this->contextCarryingLabels(['salle-info']);

        $items = $this->compileDecoratedFull([$local], [new RegistryUpstreamAdapter()], $ctx)[StateContract::SCOPE_SESSION];
        $byName = collect($items)->keyBy('payload.name');

        self::assertCount(2, $items, 'item instance (K1) ET item label (K2) servis — union, pas exclusion');
        self::assertSame(11, $byName['Inst']['payload']['value'], 'l\'item instance est servi');
        self::assertSame(22, $byName['Lab']['payload']['value'], 'l\'item label porté est servi en plus');
    }

    // ── Bornage : severed inerte (Epic 32) ────────────────────────────────

    #[Test]
    public function severed_contract_injects_nothing(): void
    {
        $contract = ControlHubContract::factory()->severed()->create();
        ControlHubContractItem::factory()->create([
            'controlhub_contract_id' => $contract->id,
            'type' => 'registry',
            'key' => 'HKCU|P|Foo|REG_DWORD',
            'value' => '1',
            'target_type' => ControlHubContractTarget::Instance,
            'target_label' => '',
        ]);

        $local = $this->keyedExclusiveProvider('registry', StateScope::Session, [
            new StateCandidate(StateMaille::LogicalGroup, $this->regPayload('HKCU', 'P', 'Foo', 0), now(), 1),
        ]);

        $items = $this->compileDecorated([$local], [new RegistryUpstreamAdapter()])[StateContract::SCOPE_SESSION];

        self::assertCount(1, $items);
        self::assertSame(0, $items[0]['payload']['value'], 'un contrat severed (non actif) n\'injecte aucun candidat');
    }

    // ── Câblage conteneur : tous les providers sont enrobés ───────────────

    #[Test]
    public function container_wires_every_provider_through_the_decorator(): void
    {
        $compiler = $this->app->make(StateCompiler::class);

        $prop = new \ReflectionProperty(StateCompiler::class, 'providers');
        $prop->setAccessible(true);
        /** @var array<int, StateProvider> $providers */
        $providers = $prop->getValue($compiler);

        self::assertNotEmpty($providers);
        foreach ($providers as $provider) {
            self::assertInstanceOf(
                UpstreamAwareProvider::class,
                $provider,
                'chaque provider du registry est enrobé par le décorateur amont',
            );
        }
        // Au moins un provider registry (KeyedExclusiveProvider) conserve son
        // marqueur au travers du décorateur.
        $keyed = array_filter($providers, fn ($p): bool => $p instanceof KeyedUpstreamAwareProvider);
        self::assertNotEmpty($keyed, 'le marqueur KeyedExclusiveProvider est relayé (registry)');
        foreach ($keyed as $p) {
            self::assertInstanceOf(KeyedExclusiveProvider::class, $p);
        }
    }

    // ── AC #5b — R3 : aucun identifiant « central » ───────────────────────

    #[Test]
    public function r3_no_central_identifier(): void
    {
        $deliveredFqcns = [
            UpstreamContractSource::class,
            UpstreamAwareProvider::class,
            KeyedUpstreamAwareProvider::class,
            UpstreamPayloadAdapter::class,
            RegistryUpstreamAdapter::class,
            ShortcutsUpstreamAdapter::class,
            StateMaille::class,
        ];

        foreach ($deliveredFqcns as $fqcn) {
            $this->assertStringNotContainsStringIgnoringCase('central', $fqcn, "FQCN « {$fqcn} » ne doit pas contenir « central ».");

            $reflection = new \ReflectionClass($fqcn);
            foreach ($reflection->getMethods() as $method) {
                $this->assertStringNotContainsStringIgnoringCase('central', $method->getName(), "Méthode {$fqcn}::{$method->getName()}");
            }
            foreach ($reflection->getProperties() as $property) {
                $this->assertStringNotContainsStringIgnoringCase('central', $property->getName());
            }
            foreach (array_keys($reflection->getConstants()) as $constName) {
                $this->assertStringNotContainsStringIgnoringCase('central', (string) $constName);
            }
        }

        // La nouvelle maille est bien `Upstream` (valeur 'upstream'), jamais central.
        self::assertSame('upstream', StateMaille::Upstream->value);

        // Story 30.4 — les nouveaux IDENTIFIANTS de `UpstreamContractSource`
        // (méthodes/propriétés `labelsCarriedBy`, `groupedByLabel`,
        // `labelsCarriedByWorkstation`) sont déjà couverts par la boucle reflection
        // ci-dessus (FQCN dans $deliveredFqcns). On tokenise en complément le source
        // des fichiers de résolution livrés/modifiés : le scan vérifie à la fois les
        // LITTÉRAUX de chaîne (T_CONSTANT_ENCAPSED_STRING / T_ENCAPSED_AND_WHITESPACE,
        // ex. un message de log) ET les IDENTIFIANTS bareword (T_STRING : noms de
        // classes/méthodes/constantes/fonctions dans le code). C'est donc PLUS
        // protecteur qu'un `file_get_contents()` brut — et, contrairement à lui, ça
        // n'inspecte JAMAIS les commentaires (où « central » apparaît légitimement
        // dans les garde-fous R3 « aucun central »).
        foreach ([UpstreamContractSource::class, UpstreamAwareProvider::class, KeyedUpstreamAwareProvider::class] as $fqcn) {
            $tokens = \PhpToken::tokenize((string) file_get_contents((new \ReflectionClass($fqcn))->getFileName()));
            foreach ($tokens as $token) {
                if ($token->is([T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE, T_STRING])) {
                    self::assertStringNotContainsStringIgnoringCase('central', $token->text, "Littéral « central » dans {$fqcn}");
                }
            }
        }
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    /**
     * Compile un jeu de providers ENROBÉS par le décorateur amont (source fraîche
     * lisant le contrat actif persisté) sur un contexte machine-only.
     *
     * @param  list<StateProvider>  $providers
     * @param  list<UpstreamPayloadAdapter>  $adapters
     * @return array<string,mixed>
     */
    private function compileDecorated(array $providers, array $adapters): array
    {
        return $this->compileDecoratedFull($providers, $adapters, $this->machineOnlyContext());
    }

    /**
     * @param  list<StateProvider>  $providers
     * @param  list<UpstreamPayloadAdapter>  $adapters
     * @return array<string,mixed>
     */
    private function compileDecoratedFull(array $providers, array $adapters, TargetContext $ctx): array
    {
        $source = new UpstreamContractSource($adapters);
        $decorated = array_map(
            fn (StateProvider $p): StateProvider => UpstreamAwareProvider::wrap($p, $source),
            $providers,
        );

        return (new StateCompiler($this->hasher, $decorated))->compile($ctx);
    }

    private function machineOnlyContext(): TargetContext
    {
        return TargetContext::for(Workstation::factory()->create(), null);
    }

    /**
     * Poste rattaché à un parc par label fourni (un `WorkstationGroup`
     * `controlhub_label = <label>` par entrée). Sert à tester l'expansion
     * `target_type = label` → postes (Story 30.4). Par défaut les parcs sont
     * LOGIQUES ; `$physical = true` les crée PHYSIQUES (salle physique portant le
     * label) — `workstationGroupIds()` réunit `physical ∪ logical`, l'expansion
     * doit donc valoir pour les deux types (et la maille NE dépend PAS du type —
     * FR12, pas de spécificité inter-parcs).
     *
     * @param  list<string>  $labels
     */
    private function contextCarryingLabels(array $labels, bool $physical = false): TargetContext
    {
        $workstation = Workstation::factory()->create();
        foreach ($labels as $label) {
            $factory = $physical
                ? WorkstationGroup::factory()->physical()
                : WorkstationGroup::factory()->logical();
            $group = $factory->create(['controlhub_label' => $label]);
            $workstation->attachGroups($group->id);
        }

        return TargetContext::for($workstation, null);
    }

    /**
     * Mocke le facade Log (channel `agent`) et capture les warnings émis — patron
     * de `StateCompilerTest`, réutilisé pour asserter `agent.state.conflict`.
     *
     * @return \ArrayObject<int, array{0:string,1:array<string,mixed>}>
     */
    private function captureAgentWarnings(): \ArrayObject
    {
        $warnings = new \ArrayObject();
        Log::shouldReceive('channel')->with('agent')->andReturnSelf();
        Log::shouldReceive('debug')->andReturnNull();
        Log::shouldReceive('warning')->andReturnUsing(
            function (string $message, array $context = []) use ($warnings): void {
                $warnings->append([$message, $context]);
            },
        );

        return $warnings;
    }

    /**
     * @param  list<array{query:string,bindings:array<mixed>,time:float}>  $log
     */
    private function countQueries(array $log, string $needle): int
    {
        return count(array_filter($log, static fn (array $q): bool => str_contains($q['query'], $needle)));
    }

    /**
     * @return array<string,mixed>
     */
    private function regPayload(string $hive, string $path, string $name, int $value): array
    {
        return [
            'hive' => $hive,
            'path' => $path,
            'name' => $name,
            'type' => 'REG_DWORD',
            'value' => $value,
        ];
    }

    /**
     * @param  list<StateCandidate>  $candidates
     */
    private function fakeProvider(
        string $type,
        ResourceSemantics $semantics,
        StateScope $scope,
        array $candidates,
    ): StateProvider {
        return new class($type, $semantics, $scope, $candidates) implements StateProvider
        {
            /** @param list<StateCandidate> $candidates */
            public function __construct(
                private readonly string $type,
                private readonly ResourceSemantics $semantics,
                private readonly StateScope $scope,
                private readonly array $candidates,
            ) {}

            public function type(): string
            {
                return $this->type;
            }

            public function semantics(): ResourceSemantics
            {
                return $this->semantics;
            }

            public function scope(): StateScope
            {
                return $this->scope;
            }

            public function itemsFor(TargetContext $ctx): Collection
            {
                return collect($this->candidates);
            }
        };
    }

    /**
     * @param  list<StateCandidate>  $candidates
     */
    private function keyedExclusiveProvider(
        string $type,
        StateScope $scope,
        array $candidates,
    ): StateProvider {
        return new class($type, $scope, $candidates) implements KeyedExclusiveProvider, StateProvider
        {
            /** @param list<StateCandidate> $candidates */
            public function __construct(
                private readonly string $type,
                private readonly StateScope $scope,
                private readonly array $candidates,
            ) {}

            public function type(): string
            {
                return $this->type;
            }

            public function semantics(): ResourceSemantics
            {
                return ResourceSemantics::Exclusive;
            }

            public function scope(): StateScope
            {
                return $this->scope;
            }

            public function exclusiveKey(array $payload): string
            {
                return strtolower(($payload['hive'] ?? '').'|'.($payload['path'] ?? '').'|'.($payload['name'] ?? ''));
            }

            public function itemsFor(TargetContext $ctx): Collection
            {
                return collect($this->candidates);
            }
        };
    }
}

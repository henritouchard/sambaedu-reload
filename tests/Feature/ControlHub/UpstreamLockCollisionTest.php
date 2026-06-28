<?php

declare(strict_types=1);

namespace Tests\Feature\ControlHub;

use App\Enums\ControlHubEnforcementState;
use App\Enums\ControlHubLabelMode;
use App\Enums\ControlHubLinkState;
use App\Enums\ResourceSemantics;
use App\Enums\StateScope;
use App\Exceptions\ControlHub\UpstreamLockCollisionException;
use App\Models\ControlHubContract;
use App\Models\ControlHubContractItem;
use App\Models\ControlHubContractLabel;
use App\Models\Workstation;
use App\Models\WorkstationGroup;
use App\Observers\WorkstationGroupObserver;
use App\Services\Agent\Contracts\KeyedExclusiveProvider;
use App\Services\Agent\Contracts\StateProvider;
use App\Services\Agent\TargetContext;
use App\Services\ControlHub\Resolution\RegistryUpstreamAdapter;
use App\Services\ControlHub\Resolution\UpstreamContractSource;
use App\Services\ControlHub\Resolution\UpstreamLockCollision;
use App\Services\ControlHub\Resolution\UpstreamLockCollisionDetector;
use App\Services\ControlHub\WorkstationGroupLabelService;
use App\Services\Parc\WorkstationGroupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 30.5 — Validation prédictive à l'assignation (FR13).
 *
 * Un détecteur de collision verrou/verrou ({@see UpstreamLockCollisionDetector})
 * réutilise le socle 30.4 ({@see UpstreamContractSource::lockedLabelCandidates()})
 * pour PRÉDIRE, AVANT toute écriture, qu'une assignation de label / un rattachement
 * de poste introduirait deux items amont `locked` contradictoires sur la MÊME
 * `exclusiveKey` d'un même poste — et REFUSE l'opération.
 *
 * Couvre AC #1–#8 : refus à l'assignation (#1) + DB inchangée ; pas-de-collision /
 * valeurs égales OK (#2) ; permissif/absent jamais bloquant (#3) ; refus au
 * rattachement + pivot inchangé (#4) ; réutilisation stricte + R3 (#5) ; standalone
 * & court-circuit NFR3 + comptage de requêtes (#6) ; déterminisme (#7) ; collision
 * pré-existante non aggravée non bloquée (#8).
 *
 * ⚠️ Tests HÔTE (php8.4 + pdo_sqlite), `RefreshDatabase`. Invariants par
 *    COMPORTEMENT (refus + état DB inchangé) + comptage de requêtes — jamais par
 *    contrainte varchar/unicité NULL (pièges SQLite).
 */
class UpstreamLockCollisionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Neutralise la sync AD au WorkstationGroup::factory()->create() / attach.
        WorkstationGroupObserver::disableSync();
        Queue::fake();
    }

    protected function tearDown(): void
    {
        WorkstationGroupObserver::enableSync();
        parent::tearDown();
    }

    // ── AC #1 — assignation introduisant une collision verrou/verrou : refus ──

    #[Test]
    public function assigning_label_introducing_locked_conflict_is_refused(): void
    {
        $contract = $this->activeContract();
        $this->freeLabel($contract, 'parc-b');
        $itemA = $this->lockedLabelItem($contract, 'parc-a', 'HKCU|P|Foo|REG_DWORD', '1');
        $itemB = $this->lockedLabelItem($contract, 'parc-b', 'HKCU|P|Foo|REG_DWORD', '2');

        $groupA = $this->labeledGroup('parc-a');
        $groupB = $this->unlabeledGroup();
        $workstation = Workstation::factory()->create();
        $workstation->attachGroups([$groupA->id, $groupB->id]);

        try {
            $this->labelService()->assignLabel($groupB, 'parc-b');
            self::fail('Une collision verrou/verrou aurait dû être refusée.');
        } catch (UpstreamLockCollisionException $e) {
            // Le message nomme la clé, les deux sources amont et les deux valeurs.
            self::assertStringContainsString('hkcu|p|foo', $e->getMessage());
            self::assertStringContainsString('#'.$itemA->id, $e->getMessage());
            self::assertStringContainsString('#'.$itemB->id, $e->getMessage());
            self::assertStringContainsString('parc-a', $e->getMessage());
            self::assertStringContainsString('parc-b', $e->getMessage());
        }

        // Aucune écriture : le label de G_B reste null (aucune résolution silencieuse).
        self::assertNull($groupB->fresh()->controlhub_label);
    }

    #[Test]
    public function collision_dto_reports_structured_sides(): void
    {
        $contract = $this->activeContract();
        $itemA = $this->lockedLabelItem($contract, 'parc-a', 'HKCU|P|Foo|REG_DWORD', '1');
        $itemB = $this->lockedLabelItem($contract, 'parc-b', 'HKCU|P|Foo|REG_DWORD', '2');

        $workstation = Workstation::factory()->create();
        $collisions = $this->detector()->collisionsFromLabelGainedBy(
            collect([$workstation]),
            'parc-b',
            fn (): array => ['parc-a'],
        );

        self::assertCount(1, $collisions);
        $collision = $collisions[0];
        self::assertInstanceOf(UpstreamLockCollision::class, $collision);
        self::assertSame('hkcu|p|foo', $collision->exclusiveKey);
        self::assertSame('registry', $collision->providerType);
        // Côtés ordonnés par sourceId croissant : A = item de plus petit id.
        self::assertSame($itemA->id, $collision->sourceIdA);
        self::assertSame($itemB->id, $collision->sourceIdB);
        self::assertSame(1, $collision->valueA);
        self::assertSame(2, $collision->valueB);
        self::assertSame([(int) $workstation->id], $collision->workstationIds);
    }

    // ── AC #2 — pas de collision : assignation réussit (30.2 préservé) ────────

    #[Test]
    public function assigning_label_without_conflict_succeeds(): void
    {
        $contract = $this->activeContract();
        $this->freeLabel($contract, 'parc-b');
        // Clés DISJOINTES : aucune collision possible.
        $this->lockedLabelItem($contract, 'parc-a', 'HKCU|P|Foo|REG_DWORD', '1');
        $this->lockedLabelItem($contract, 'parc-b', 'HKCU|P|Bar|REG_DWORD', '2');

        $groupA = $this->labeledGroup('parc-a');
        $groupB = $this->unlabeledGroup();
        $workstation = Workstation::factory()->create();
        $workstation->attachGroups([$groupA->id, $groupB->id]);

        $this->labelService()->assignLabel($groupB, 'parc-b');

        self::assertSame('parc-b', $groupB->fresh()->controlhub_label);
    }

    #[Test]
    public function same_key_same_value_is_not_a_collision(): void
    {
        $contract = $this->activeContract();
        $this->freeLabel($contract, 'parc-b');
        // MÊME clé, MÊME valeur (X=1 des deux côtés) → rien à trancher (AC #2).
        $this->lockedLabelItem($contract, 'parc-a', 'HKCU|P|Foo|REG_DWORD', '1');
        $this->lockedLabelItem($contract, 'parc-b', 'HKCU|P|Foo|REG_DWORD', '1');

        $groupA = $this->labeledGroup('parc-a');
        $groupB = $this->unlabeledGroup();
        $workstation = Workstation::factory()->create();
        $workstation->attachGroups([$groupA->id, $groupB->id]);

        $this->labelService()->assignLabel($groupB, 'parc-b');

        self::assertSame('parc-b', $groupB->fresh()->controlhub_label);
    }

    // ── AC #3 — permissif / absent jamais bloquant ───────────────────────────

    #[Test]
    public function permissive_overlap_does_not_block(): void
    {
        $contract = $this->activeContract();
        $this->freeLabel($contract, 'parc-b');
        // A locked / B PERMISSIVE sur la même clé, valeurs ≠ : pas de collision.
        $this->lockedLabelItem($contract, 'parc-a', 'HKCU|P|Foo|REG_DWORD', '1');
        ControlHubContractItem::factory()->forLabel('parc-b')->permissive()->create([
            'controlhub_contract_id' => $contract->id,
            'type' => 'registry',
            'key' => 'HKCU|P|Foo|REG_DWORD',
            'value' => '2',
        ]);

        $groupA = $this->labeledGroup('parc-a');
        $groupB = $this->unlabeledGroup();
        $workstation = Workstation::factory()->create();
        $workstation->attachGroups([$groupA->id, $groupB->id]);

        $this->labelService()->assignLabel($groupB, 'parc-b');

        self::assertSame('parc-b', $groupB->fresh()->controlhub_label);
    }

    #[Test]
    public function absent_item_is_ignored_and_does_not_block(): void
    {
        $contract = $this->activeContract();
        $this->freeLabel($contract, 'parc-b');
        $this->lockedLabelItem($contract, 'parc-a', 'HKCU|P|Foo|REG_DWORD', '1');
        ControlHubContractItem::factory()->forLabel('parc-b')->absent()->create([
            'controlhub_contract_id' => $contract->id,
            'type' => 'registry',
            'key' => 'HKCU|P|Foo|REG_DWORD',
        ]);

        $groupA = $this->labeledGroup('parc-a');
        $groupB = $this->unlabeledGroup();
        $workstation = Workstation::factory()->create();
        $workstation->attachGroups([$groupA->id, $groupB->id]);

        $this->labelService()->assignLabel($groupB, 'parc-b');

        self::assertSame('parc-b', $groupB->fresh()->controlhub_label);
    }

    // ── AC #4 — rattachement d'un poste à un parc labellisé ───────────────────

    #[Test]
    public function attaching_workstation_to_labeled_parc_introducing_conflict_is_refused(): void
    {
        $contract = $this->activeContract();
        $this->lockedLabelItem($contract, 'parc-a', 'HKCU|P|Foo|REG_DWORD', '1');
        $this->lockedLabelItem($contract, 'parc-b', 'HKCU|P|Foo|REG_DWORD', '2');

        $groupA = $this->labeledGroup('parc-a');
        $groupB = $this->labeledGroup('parc-b');
        $workstation = Workstation::factory()->create();
        $workstation->attachGroups([$groupA->id]); // porte déjà parc-a (locked X=1)

        try {
            $this->parcService()->addMachineToGroup((int) $workstation->id, (int) $groupB->id);
            self::fail('Le rattachement introduisant une collision aurait dû être refusé.');
        } catch (UpstreamLockCollisionException $e) {
            self::assertStringContainsString('hkcu|p|foo', $e->getMessage());
        }

        // Aucune ligne pivot ajoutée pour G_B.
        self::assertDatabaseMissing('workstation_group_workstation', [
            'workstation_id' => $workstation->id,
            'workstation_group_id' => $groupB->id,
        ]);
    }

    #[Test]
    public function bulk_attaching_to_labeled_parc_introducing_conflict_is_refused(): void
    {
        $contract = $this->activeContract();
        $this->lockedLabelItem($contract, 'parc-a', 'HKCU|P|Foo|REG_DWORD', '1');
        $this->lockedLabelItem($contract, 'parc-b', 'HKCU|P|Foo|REG_DWORD', '2');

        $groupA = $this->labeledGroup('parc-a');
        $groupB = $this->labeledGroup('parc-b');
        $workstation = Workstation::factory()->create();
        $workstation->attachGroups([$groupA->id]);

        $this->expectException(UpstreamLockCollisionException::class);
        try {
            $this->parcService()->bulkAddMachinesToGroup([(int) $workstation->id], (int) $groupB->id);
        } finally {
            self::assertDatabaseMissing('workstation_group_workstation', [
                'workstation_id' => $workstation->id,
                'workstation_group_id' => $groupB->id,
            ]);
        }
    }

    #[Test]
    public function attaching_to_unlabeled_parc_succeeds_unchanged(): void
    {
        $contract = $this->activeContract();
        $this->lockedLabelItem($contract, 'parc-a', 'HKCU|P|Foo|REG_DWORD', '1');
        $this->lockedLabelItem($contract, 'parc-b', 'HKCU|P|Foo|REG_DWORD', '2');

        $groupA = $this->labeledGroup('parc-a');
        $plainGroup = $this->unlabeledGroup(); // aucun label → aucun risque
        $workstation = Workstation::factory()->create();
        $workstation->attachGroups([$groupA->id]);

        $this->parcService()->addMachineToGroup((int) $workstation->id, (int) $plainGroup->id);

        self::assertDatabaseHas('workstation_group_workstation', [
            'workstation_id' => $workstation->id,
            'workstation_group_id' => $plainGroup->id,
        ]);
    }

    // ── AC #6 — standalone & court-circuit NFR3 (comptage de requêtes) ────────

    #[Test]
    public function detector_short_circuits_with_no_active_contract_and_no_parc_query(): void
    {
        // (a) aucun contrat actif : hasLockedLabelItems() = false, ZÉRO requête parc.
        DB::flushQueryLog();
        DB::enableQueryLog();
        $hasLocked = $this->detector()->hasLockedLabelItems();
        $log = DB::getQueryLog();
        DB::disableQueryLog();

        self::assertFalse($hasLocked);
        self::assertSame(0, $this->countQueries($log, '"workstation_group_workstation"'), 'aucune requête pivot (court-circuit)');
        self::assertSame(0, $this->countQueries($log, '"workstation_groups"'), 'aucune requête parc (court-circuit)');
    }

    #[Test]
    public function detector_short_circuits_with_active_contract_without_locked_label_item(): void
    {
        // (b) contrat actif SANS item label locked (item instance) : court-circuit.
        $contract = $this->activeContract();
        ControlHubContractItem::factory()->create([
            'controlhub_contract_id' => $contract->id,
            'type' => 'registry',
            'key' => 'HKCU|P|X|REG_DWORD',
            'value' => '5',
            'enforcement_state' => ControlHubEnforcementState::Locked,
            'target_label' => '',
        ]);

        $detector = $this->detector();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $hasLocked = $detector->hasLockedLabelItems();
        $log = DB::getQueryLog();
        DB::disableQueryLog();

        self::assertFalse($hasLocked, 'aucun item label locked → court-circuit');
        self::assertSame(0, $this->countQueries($log, '"workstation_group_workstation"'), 'aucune requête pivot');
        self::assertSame(0, $this->countQueries($log, '"workstation_groups"'), 'aucune requête parc');
    }

    #[Test]
    public function attaching_in_standalone_succeeds_unchanged(): void
    {
        // Sans contrat actif, le rattachement parc historique est strictement
        // préservé (la garde court-circuite, hot-path intact).
        $group = $this->unlabeledGroup();
        $workstation = Workstation::factory()->create();

        $this->parcService()->addMachineToGroup((int) $workstation->id, (int) $group->id);

        self::assertDatabaseHas('workstation_group_workstation', [
            'workstation_id' => $workstation->id,
            'workstation_group_id' => $group->id,
        ]);
    }

    // ── AC #7 — déterminisme du rapport ──────────────────────────────────────

    #[Test]
    public function detection_is_deterministic_across_runs_and_time(): void
    {
        $contract = $this->activeContract();
        $this->lockedLabelItem($contract, 'parc-a', 'HKCU|P|Foo|REG_DWORD', '1');
        $this->lockedLabelItem($contract, 'parc-b', 'HKCU|P|Foo|REG_DWORD', '2');

        // Collision touchant DEUX postes.
        $ws1 = Workstation::factory()->create();
        $ws2 = Workstation::factory()->create();

        $first = UpstreamLockCollisionException::fromCollisions(
            $this->detector()->collisionsFromLabelGainedBy(
                collect([$ws1, $ws2]),
                'parc-b',
                fn (): array => ['parc-a'],
            ),
        )->getMessage();

        $this->travel(3)->hours();

        $second = UpstreamLockCollisionException::fromCollisions(
            $this->detector()->collisionsFromLabelGainedBy(
                collect([$ws2, $ws1]), // ordre des postes inversé
                'parc-b',
                fn (): array => ['parc-a'],
            ),
        )->getMessage();

        self::assertSame($first, $second, 'message identique (déterminisme NFR4)');
        // Le périmètre énumère les DEUX postes, triés.
        self::assertStringContainsString((string) min($ws1->id, $ws2->id), $first);
        self::assertStringContainsString((string) max($ws1->id, $ws2->id), $first);
    }

    // ── AC #8 — collision pré-existante non aggravée : non bloquée ────────────

    #[Test]
    public function preexisting_conflict_not_aggravated_is_not_blocked(): void
    {
        $contract = $this->activeContract();
        $this->freeLabel($contract, 'parc-c');
        // Collision PRÉ-EXISTANTE entre parc-a et parc-b sur la clé Foo.
        $this->lockedLabelItem($contract, 'parc-a', 'HKCU|P|Foo|REG_DWORD', '1');
        $this->lockedLabelItem($contract, 'parc-b', 'HKCU|P|Foo|REG_DWORD', '2');
        // parc-c : aucun item locked sur la clé en conflit (orthogonal).
        $this->lockedLabelItem($contract, 'parc-c', 'HKCU|P|Other|REG_DWORD', '9');

        $groupA = $this->labeledGroup('parc-a');
        $groupB = $this->labeledGroup('parc-b');
        $groupC = $this->unlabeledGroup();
        $workstation = Workstation::factory()->create();
        // Cumule DÉJÀ parc-a + parc-b (collision pré-existante).
        $workstation->attachGroups([$groupA->id, $groupB->id, $groupC->id]);

        // Assigner parc-c (orthogonal) ne doit PAS être bloqué par la collision
        // pré-existante a/b (la garde ne refuse que les collisions introduites).
        $this->labelService()->assignLabel($groupC, 'parc-c');

        self::assertSame('parc-c', $groupC->fresh()->controlhub_label);
    }

    // ── AC #5c — R3 : aucun identifiant / littéral « central » ────────────────

    #[Test]
    public function r3_no_central_identifier(): void
    {
        $deliveredFqcns = [
            UpstreamLockCollisionDetector::class,
            UpstreamLockCollision::class,
            UpstreamLockCollisionException::class,
        ];

        foreach ($deliveredFqcns as $fqcn) {
            $this->assertStringNotContainsStringIgnoringCase('central', $fqcn);

            $reflection = new \ReflectionClass($fqcn);
            foreach ($reflection->getMethods() as $method) {
                $this->assertStringNotContainsStringIgnoringCase('central', $method->getName());
            }
            foreach ($reflection->getProperties() as $property) {
                $this->assertStringNotContainsStringIgnoringCase('central', $property->getName());
            }

            // Tokenisation : littéraux de chaîne + identifiants bareword (jamais les
            // commentaires, où « central » figure légitimement dans les garde-fous R3).
            $tokens = \PhpToken::tokenize((string) file_get_contents($reflection->getFileName()));
            foreach ($tokens as $token) {
                if ($token->is([T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE, T_STRING])) {
                    self::assertStringNotContainsStringIgnoringCase('central', $token->text, "Littéral « central » dans {$fqcn}");
                }
            }
        }
    }

    // ── AC #5b — anti-régression D2 : le moteur n'est pas touché ──────────────

    #[Test]
    public function d2_engine_files_do_not_reference_30_5_collision_logic(): void
    {
        // 30.5 vit dans un service d'ASSIGNATION, jamais dans le moteur. Preuve :
        // ni StateCompiler ni StateMaille ne référencent la logique de collision.
        $compiler = (string) file_get_contents(app_path('Services/Agent/StateCompiler.php'));
        $maille = (string) file_get_contents(app_path('Enums/StateMaille.php'));

        self::assertStringNotContainsString('UpstreamLockCollision', $compiler, 'StateCompiler ne doit PAS connaître 30.5');
        self::assertStringNotContainsString('UpstreamLockCollision', $maille, 'StateMaille ne doit PAS connaître 30.5');
        // La maille interne reste à 8 cas (rien ajouté par 30.5).
        self::assertCount(8, \App\Enums\StateMaille::cases());
    }

    // ── Post-review 30-5 — surfaces sync/swap (modèle pré-set/post-set) ───────

    // #1 — setMachineGroups (REMPLACEMENT) : rescaper un poste HORS d'un setup
    // conflictuel vers un parc non conflictuel ne doit PAS être bloqué (les
    // appartenances actuelles sont supprimées par `groups()->sync()`).
    #[Test]
    public function set_machine_groups_replacing_conflicting_with_non_conflicting_is_not_blocked(): void
    {
        $contract = $this->activeContract();
        $this->lockedLabelItem($contract, 'parc-a', 'HKCU|P|Foo|REG_DWORD', '1');
        $this->lockedLabelItem($contract, 'parc-c', 'HKCU|P|Foo|REG_DWORD', '2');

        $groupA = $this->labeledGroup('parc-a');
        $groupB = $this->unlabeledGroup();
        $groupC = $this->labeledGroup('parc-c');
        $workstation = Workstation::factory()->create();
        $workstation->attachGroups([$groupA->id, $groupB->id]); // porte parc-a (locked X=1)

        // Remplace toutes les appartenances par C (parc-c, locked X=2). Post-op le
        // poste n'est QUE dans C → aucun conflit → doit RÉUSSIR.
        $this->parcService()->setMachineGroups((int) $workstation->id, [(int) $groupC->id]);

        self::assertDatabaseHas('workstation_group_workstation', [
            'workstation_id' => $workstation->id,
            'workstation_group_id' => $groupC->id,
        ]);
        self::assertDatabaseMissing('workstation_group_workstation', [
            'workstation_id' => $workstation->id,
            'workstation_group_id' => $groupA->id,
        ]);
    }

    #[Test]
    public function set_machine_groups_introducing_two_conflicting_targets_is_refused(): void
    {
        $contract = $this->activeContract();
        $this->lockedLabelItem($contract, 'parc-a', 'HKCU|P|Foo|REG_DWORD', '1');
        $this->lockedLabelItem($contract, 'parc-c', 'HKCU|P|Foo|REG_DWORD', '2');

        $groupA = $this->labeledGroup('parc-a');
        $groupC = $this->labeledGroup('parc-c');
        $workstation = Workstation::factory()->create();

        try {
            // Deux labels cibles contradictoires dans le MÊME set → vraie collision
            // cible↔cible (les deux gagnés) → REFUS.
            $this->parcService()->setMachineGroups((int) $workstation->id, [(int) $groupA->id, (int) $groupC->id]);
            self::fail('La pose de deux cibles contradictoires aurait dû être refusée.');
        } catch (UpstreamLockCollisionException $e) {
            self::assertStringContainsString('hkcu|p|foo', $e->getMessage());
        }

        // Pivot inchangé : aucune appartenance écrite.
        self::assertDatabaseMissing('workstation_group_workstation', [
            'workstation_id' => $workstation->id,
            'workstation_group_id' => $groupA->id,
        ]);
        self::assertDatabaseMissing('workstation_group_workstation', [
            'workstation_id' => $workstation->id,
            'workstation_group_id' => $groupC->id,
        ]);
    }

    // #2 — setGroupMachines : un poste DÉJÀ membre (et déjà en conflit runtime) ne
    // « gagne » pas le label de G → ne doit pas faire échouer le sync.
    #[Test]
    public function set_group_machines_preexisting_member_conflict_is_not_blocked(): void
    {
        $contract = $this->activeContract();
        $this->lockedLabelItem($contract, 'parc-g', 'HKCU|P|Foo|REG_DWORD', '2');
        $this->lockedLabelItem($contract, 'parc-h', 'HKCU|P|Foo|REG_DWORD', '1');

        $groupG = $this->labeledGroup('parc-g');
        $groupH = $this->labeledGroup('parc-h');
        $m1 = Workstation::factory()->create();
        $m1->attachGroups([$groupG->id, $groupH->id]); // collision PRÉ-EXISTANTE g/h
        $m2 = Workstation::factory()->create();

        // m1 déjà membre de G (label parc-g déjà porté) → non « gagné » → la
        // collision pré-existante ne doit PAS bloquer le sync (fix #2).
        $this->parcService()->setGroupMachines((int) $groupG->id, [(int) $m1->id, (int) $m2->id]);

        self::assertDatabaseHas('workstation_group_workstation', [
            'workstation_id' => $m1->id,
            'workstation_group_id' => $groupG->id,
        ]);
        self::assertDatabaseHas('workstation_group_workstation', [
            'workstation_id' => $m2->id,
            'workstation_group_id' => $groupG->id,
        ]);
    }

    #[Test]
    public function set_group_machines_newly_added_machine_introducing_conflict_is_refused(): void
    {
        $contract = $this->activeContract();
        $this->lockedLabelItem($contract, 'parc-g', 'HKCU|P|Foo|REG_DWORD', '2');
        $this->lockedLabelItem($contract, 'parc-h', 'HKCU|P|Foo|REG_DWORD', '1');

        $groupG = $this->labeledGroup('parc-g');
        $groupH = $this->labeledGroup('parc-h');
        $m2 = Workstation::factory()->create();
        $m2->attachGroups([$groupH->id]); // porte déjà parc-h (locked X=1)

        try {
            // m2 NOUVELLEMENT ajouté à G gagne parc-g (X=2), contradictoire avec
            // parc-h (X=1) déjà porté → REFUS.
            $this->parcService()->setGroupMachines((int) $groupG->id, [(int) $m2->id]);
            self::fail('Le poste nouvellement ajouté introduisant une collision aurait dû être refusé.');
        } catch (UpstreamLockCollisionException $e) {
            self::assertStringContainsString('hkcu|p|foo', $e->getMessage());
        }

        self::assertDatabaseMissing('workstation_group_workstation', [
            'workstation_id' => $m2->id,
            'workstation_group_id' => $groupG->id,
        ]);
    }

    // M1 — assignMachineToPhysicalRoom (SWAP) ─────────────────────────────────
    #[Test]
    public function assigning_to_physical_room_with_label_introducing_conflict_is_refused(): void
    {
        $contract = $this->activeContract();
        $this->lockedLabelItem($contract, 'parc-a', 'HKCU|P|Foo|REG_DWORD', '1');
        $this->lockedLabelItem($contract, 'parc-room', 'HKCU|P|Foo|REG_DWORD', '2');

        $groupA = $this->labeledGroup('parc-a'); // parc logique
        $room = $this->labeledPhysicalRoom('parc-room'); // salle physique labellisée
        $workstation = Workstation::factory()->create();
        $workstation->attachGroups([$groupA->id]); // porte parc-a (locked X=1)

        try {
            $this->parcService()->assignMachineToPhysicalRoom((int) $workstation->id, (int) $room->id);
            self::fail('L\'assignation à une salle au label contradictoire aurait dû être refusée.');
        } catch (UpstreamLockCollisionException $e) {
            self::assertStringContainsString('hkcu|p|foo', $e->getMessage());
        }

        self::assertDatabaseMissing('workstation_group_workstation', [
            'workstation_id' => $workstation->id,
            'workstation_group_id' => $room->id,
        ]);
    }

    #[Test]
    public function reassigning_physical_room_away_from_conflicting_room_is_not_blocked(): void
    {
        $contract = $this->activeContract();
        $this->lockedLabelItem($contract, 'parc-old', 'HKCU|P|Foo|REG_DWORD', '1');
        $this->lockedLabelItem($contract, 'parc-new', 'HKCU|P|Foo|REG_DWORD', '2');

        $rOld = $this->labeledPhysicalRoom('parc-old');
        $rNew = $this->labeledPhysicalRoom('parc-new');
        $workstation = Workstation::factory()->create();

        // Place d'abord le poste dans R_old (parc-old seul → pas de conflit).
        $this->parcService()->assignMachineToPhysicalRoom((int) $workstation->id, (int) $rOld->id);

        // Réassigne vers R_new : le swap détache R_old, donc post = { parc-new }
        // → aucun conflit → doit RÉUSSIR (fix M1 faux refus).
        $this->parcService()->assignMachineToPhysicalRoom((int) $workstation->id, (int) $rNew->id);

        self::assertDatabaseHas('workstation_group_workstation', [
            'workstation_id' => $workstation->id,
            'workstation_group_id' => $rNew->id,
        ]);
        self::assertDatabaseMissing('workstation_group_workstation', [
            'workstation_id' => $workstation->id,
            'workstation_group_id' => $rOld->id,
        ]);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function activeContract(): ControlHubContract
    {
        return ControlHubContract::factory()->create([
            'link_state' => ControlHubLinkState::Active,
        ]);
    }

    private function freeLabel(ControlHubContract $contract, string $name): ControlHubContractLabel
    {
        return ControlHubContractLabel::factory()->create([
            'controlhub_contract_id' => $contract->id,
            'name' => $name,
            'mode' => ControlHubLabelMode::Free,
        ]);
    }

    private function lockedLabelItem(ControlHubContract $contract, string $label, string $key, string $value): ControlHubContractItem
    {
        return ControlHubContractItem::factory()->forLabel($label)->create([
            'controlhub_contract_id' => $contract->id,
            'type' => 'registry',
            'key' => $key,
            'value' => $value,
            'enforcement_state' => ControlHubEnforcementState::Locked,
        ]);
    }

    private function labeledGroup(string $label): WorkstationGroup
    {
        return WorkstationGroup::factory()->logical()->create(['controlhub_label' => $label]);
    }

    private function unlabeledGroup(): WorkstationGroup
    {
        return WorkstationGroup::factory()->logical()->create(['controlhub_label' => null]);
    }

    private function labeledPhysicalRoom(string $label): WorkstationGroup
    {
        return WorkstationGroup::factory()->physical()->create(['controlhub_label' => $label]);
    }

    private function labelService(): WorkstationGroupLabelService
    {
        // Détecteur résolu paresseusement via le conteneur (binding réel 30.5).
        return new WorkstationGroupLabelService();
    }

    private function parcService(): WorkstationGroupService
    {
        return app(WorkstationGroupService::class);
    }

    /**
     * Détecteur sur une source FRAÎCHE (contrat actif persisté) + provider registry
     * exclusif (relais d'`exclusiveKey()` iso providers de prod).
     */
    private function detector(): UpstreamLockCollisionDetector
    {
        $source = new UpstreamContractSource([new RegistryUpstreamAdapter()]);

        return new UpstreamLockCollisionDetector($source, [$this->registryKeyedProvider()]);
    }

    private function registryKeyedProvider(): StateProvider
    {
        return new class implements KeyedExclusiveProvider, StateProvider
        {
            public function type(): string
            {
                return 'registry';
            }

            public function semantics(): ResourceSemantics
            {
                return ResourceSemantics::Exclusive;
            }

            public function scope(): StateScope
            {
                return StateScope::Session;
            }

            public function exclusiveKey(array $payload): string
            {
                return strtolower(($payload['hive'] ?? '').'|'.($payload['path'] ?? '').'|'.($payload['name'] ?? ''));
            }

            public function itemsFor(TargetContext $ctx): Collection
            {
                return collect();
            }
        };
    }

    /**
     * @param  list<array{query:string,bindings:array<mixed>,time:float}>  $log
     */
    private function countQueries(array $log, string $needle): int
    {
        return count(array_filter($log, static fn (array $q): bool => str_contains($q['query'], $needle)));
    }
}

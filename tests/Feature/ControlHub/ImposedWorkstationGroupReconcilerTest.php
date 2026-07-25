<?php

declare(strict_types=1);

namespace Tests\Feature\ControlHub;

use App\Enums\ControlHubLabelMode;
use App\Enums\LockReason;
use App\Jobs\AdSync\WorkstationGroupAdSyncJob;
use App\Models\ControlHubContract;
use App\Models\ControlHubContractImposedGroup;
use App\Models\ControlHubContractLabel;
use App\Models\WorkstationGroup;
use App\Services\ControlHub\ImposedWorkstationGroupReconciler;
use App\Services\Parc\WorkstationGroupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 30.3 — Garantie d'existence des groupes imposés par le contrat amont (controlHub).
 *
 * Couvre les AC :
 * - #1 création d'un groupe imposé absent (chemin parc → AD réutilisé)
 * - #2 confirmation idempotente d'un groupe existant (sans doublon) + adopt ROOT
 * - #3 idempotence sur 2 passes
 * - #4 verrou de suppression (deleteGroup throw + groupe persiste)
 * - #6 levée du verrou des groupes non-imposés (sans suppression)
 * - #7 standalone (no-op total)
 * - #8 R3 (introspection — aucun « central »)
 *
 * ⚠️ Tests sur HÔTE (php8.4 + pdo_sqlite) — JAMAIS sur la VM.
 * ⚠️ Idempotence & verrou testés PAR COMPORTEMENT (compteurs / état), pas par
 *    contrainte DB (pièges SQLite : varchar non appliqué, NULL ≠ NULL).
 */
class ImposedWorkstationGroupReconcilerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        // L'observer WorkstationGroup dispatche un job de sync AD à chaque write :
        // on le neutralise (pas de LDAP en test HÔTE) et on l'assertera côté création.
        Queue::fake();
    }

    private function reconciler(): ImposedWorkstationGroupReconciler
    {
        return app(ImposedWorkstationGroupReconciler::class);
    }

    /**
     * Crée un contrat amont actif avec les groupes imposés fournis (nom => label_name|null).
     *
     * @param  array<string, string|null>  $groups
     */
    private function activeContractWithImposedGroups(array $groups): ControlHubContract
    {
        $contract = ControlHubContract::factory()->create();

        foreach ($groups as $name => $labelName) {
            $factory = ControlHubContractImposedGroup::factory();
            if ($labelName !== null) {
                $factory = $factory->withLabel($labelName);
                ControlHubContractLabel::factory()->reserved()->create([
                    'controlhub_contract_id' => $contract->id,
                    'name' => $labelName,
                ]);
            }
            $factory->create([
                'controlhub_contract_id' => $contract->id,
                'name' => $name,
            ]);
        }

        return $contract;
    }

    // ── AC #1 — Création d'un groupe imposé absent ───────────────────────────

    #[Test]
    public function creates_an_absent_imposed_group_via_the_parc_path(): void
    {
        $this->activeContractWithImposedGroups(['bureau_direction' => 'direction']);

        $result = $this->reconciler()->reconcile();

        $group = WorkstationGroup::findByName('bureau_direction');
        self::assertNotNull($group);
        self::assertFalse($group->is_physical);
        self::assertTrue($group->is_active);
        self::assertTrue($group->managed_by_control_hub);
        self::assertSame(LockReason::CONTROL_HUB->value, $group->locked);
        self::assertSame('direction', $group->controlhub_label);

        self::assertSame(1, $result->created);
        // Story 38.7 — un groupe imposé est LOGIQUE (is_physical = false) : purement
        // SQL, l'observer ne dispatche plus aucun job AD (OU=Parcs en lecture seule).
        Queue::assertNotPushed(WorkstationGroupAdSyncJob::class);
    }

    #[Test]
    public function creates_an_absent_imposed_group_without_label(): void
    {
        $this->activeContractWithImposedGroups(['compta_x' => null]);

        $this->reconciler()->reconcile();

        $group = WorkstationGroup::findByName('compta_x');
        self::assertNotNull($group);
        self::assertNull($group->controlhub_label);
        self::assertTrue($group->managed_by_control_hub);
        self::assertSame(LockReason::CONTROL_HUB->value, $group->locked);
    }

    // ── AC #2 — Confirmation idempotente d'un groupe existant ─────────────────

    #[Test]
    public function confirms_an_existing_group_without_duplication(): void
    {
        $this->activeContractWithImposedGroups(['bureau_direction' => 'direction']);
        WorkstationGroup::factory()->logical()->create([
            'name' => 'bureau_direction',
            'controlhub_label' => null,
            'managed_by_control_hub' => false,
            'locked' => null,
        ]);

        $result = $this->reconciler()->reconcile();

        self::assertSame(0, $result->created);
        self::assertSame(1, $result->confirmed);
        self::assertSame(1, WorkstationGroup::where('name', 'bureau_direction')->count());

        $group = WorkstationGroup::findByName('bureau_direction');
        self::assertSame('direction', $group->controlhub_label);
        self::assertTrue($group->managed_by_control_hub);
        self::assertSame(LockReason::CONTROL_HUB->value, $group->locked);
    }

    #[Test]
    public function confirming_a_root_locked_group_does_not_override_the_lock(): void
    {
        $this->activeContractWithImposedGroups(['bureau_direction' => 'direction']);
        WorkstationGroup::factory()->logical()->create([
            'name' => 'bureau_direction',
            'locked' => LockReason::ROOT->value,
            'managed_by_control_hub' => false,
        ]);

        $result = $this->reconciler()->reconcile();

        $group = WorkstationGroup::findByName('bureau_direction');
        // Le verrou root est PRÉSERVÉ (jamais écrasé par control_hub).
        self::assertSame(LockReason::ROOT->value, $group->locked);
        self::assertTrue($group->managed_by_control_hub);
        self::assertSame('direction', $group->controlhub_label);
        self::assertSame(1, $result->adopted);
        // Sémantique compteurs : une adoption EST une confirmation (write effectif
        // qui préserve le verrou root) — pas une création, pas une levée.
        self::assertSame(0, $result->created);
        self::assertSame(1, $result->confirmed);
        self::assertSame(0, $result->released);
    }

    // ── AC #3 — Idempotence sur 2 passes ─────────────────────────────────────

    #[Test]
    public function second_pass_is_a_functional_no_op(): void
    {
        $this->activeContractWithImposedGroups(['bureau_direction' => 'direction']);

        $this->reconciler()->reconcile();
        $second = $this->reconciler()->reconcile();

        self::assertSame(0, $second->created);
        self::assertSame(0, $second->confirmed);
        self::assertSame(0, $second->released);
        self::assertSame([], $second->errors);
        self::assertSame(1, WorkstationGroup::where('name', 'bureau_direction')->count());
    }

    // ── AC #4 — Verrou de suppression sous contrat ───────────────────────────

    #[Test]
    public function a_reconciled_imposed_group_cannot_be_deleted(): void
    {
        $this->activeContractWithImposedGroups(['bureau_direction' => 'direction']);
        $this->reconciler()->reconcile();

        $group = WorkstationGroup::findByName('bureau_direction');

        try {
            app(WorkstationGroupService::class)->deleteGroup($group->id);
            self::fail('RuntimeException attendue : un groupe imposé verrouillé n\'est pas supprimable.');
        } catch (\RuntimeException $e) {
            // attendu
        }

        self::assertNotNull(WorkstationGroup::findByName('bureau_direction'));
    }

    // ── AC #6 — Levée du verrou des groupes non-imposés ──────────────────────

    #[Test]
    public function releases_the_lock_of_a_no_longer_imposed_group_without_deleting_it(): void
    {
        // Le contrat actif n'impose QUE bureau_direction ; ancien_parc ne l'est plus.
        $this->activeContractWithImposedGroups(['bureau_direction' => 'direction']);
        WorkstationGroup::factory()->logical()->create([
            'name' => 'ancien_parc',
            'managed_by_control_hub' => true,
            'locked' => LockReason::CONTROL_HUB->value,
            'controlhub_label' => 'ancien-label',
        ]);

        $result = $this->reconciler()->reconcile();

        $released = WorkstationGroup::findByName('ancien_parc');
        self::assertNotNull($released, 'Le groupe non-imposé ne doit PAS être supprimé.');
        self::assertNull($released->locked);
        self::assertFalse($released->managed_by_control_hub);
        // Label « dangling » laissé tel quel (sans effet — cf. 30.4).
        self::assertSame('ancien-label', $released->controlhub_label);
        self::assertSame(1, $result->released);
    }

    #[Test]
    public function release_does_not_touch_a_root_locked_non_imposed_group(): void
    {
        $this->activeContractWithImposedGroups(['bureau_direction' => null]);
        WorkstationGroup::factory()->logical()->create([
            'name' => 'computers',
            'managed_by_control_hub' => true,
            'locked' => LockReason::ROOT->value,
        ]);

        $this->reconciler()->reconcile();

        $group = WorkstationGroup::findByName('computers');
        // Le verrou root est préservé même si managed_by_control_hub est levé.
        self::assertSame(LockReason::ROOT->value, $group->locked);
        self::assertFalse($group->managed_by_control_hub);
    }

    // ── AC #7 — Standalone (no-op total) ─────────────────────────────────────

    #[Test]
    public function standalone_without_active_contract_is_a_total_no_op(): void
    {
        self::assertNull(ControlHubContract::active());

        // Un groupe quelconque pré-existant ne doit pas bouger.
        $group = WorkstationGroup::factory()->logical()->create([
            'name' => 'parc_libre',
            'managed_by_control_hub' => false,
            'locked' => null,
        ]);

        // Réinitialise le fake pour n'observer que les jobs poussés par reconcile()
        // (la création du fixture ci-dessus a déjà déclenché l'observer).
        Queue::fake();

        $result = $this->reconciler()->reconcile();

        self::assertSame(0, $result->created);
        self::assertSame(0, $result->confirmed);
        self::assertSame(0, $result->released);

        $group->refresh();
        self::assertFalse($group->managed_by_control_hub);
        self::assertNull($group->locked);

        // Aucune synchro AD déclenchée par ce service en standalone.
        Queue::assertNothingPushed();
    }

    #[Test]
    public function a_severed_contract_is_not_active_and_yields_a_no_op(): void
    {
        ControlHubContract::factory()->severed()->create();

        $result = $this->reconciler()->reconcile();

        self::assertSame(0, $result->created);
        Queue::assertNothingPushed();
    }

    // ── AC #8 — R3 (introspection) ───────────────────────────────────────────

    #[Test]
    public function r3_no_delivered_identifier_contains_central(): void
    {
        $deliveredFqcns = [
            ImposedWorkstationGroupReconciler::class,
            \App\Services\ControlHub\Data\ImposedGroupReconciliationResult::class,
            \App\Listeners\ReconcileImposedWorkstationGroups::class,
            \App\Console\Commands\ReconcileImposedWorkstationGroups::class,
        ];

        foreach ($deliveredFqcns as $fqcn) {
            self::assertStringNotContainsStringIgnoringCase('central', $fqcn);

            $reflection = new \ReflectionClass($fqcn);

            // R3 sur les LITTÉRAUX : le fichier source livré ne doit contenir aucun
            // « central » (commentaires, messages FR, logs, identifiants compris).
            $path = $reflection->getFileName();
            self::assertIsString($path, "Chemin source introuvable pour {$fqcn}");
            self::assertStringNotContainsStringIgnoringCase(
                'central',
                (string) file_get_contents($path),
                "Le fichier source de {$fqcn} contient le mot interdit « central » (R3).",
            );

            foreach ($reflection->getMethods() as $method) {
                if ($method->getDeclaringClass()->getName() !== $fqcn) {
                    continue;
                }
                self::assertStringNotContainsStringIgnoringCase('central', $method->getName(), "Méthode {$fqcn}::{$method->getName()}");
            }
            foreach ($reflection->getProperties() as $property) {
                self::assertStringNotContainsStringIgnoringCase('central', $property->getName());
            }
        }
    }
}

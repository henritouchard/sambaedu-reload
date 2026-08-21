<?php

declare(strict_types=1);

namespace Tests\Feature\ControlHub;

use App\Enums\ControlHubLabelMode;
use App\Events\ControlHubContractChanged;
use App\Exceptions\ControlHub\InvalidUpstreamContractException;
use App\Models\ControlHubContractImposedGroup;
use App\Models\ControlHubContractLabel;
use App\Services\ControlHub\ControlHubContractIngestionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Story 30.1 — Réception des labels et des groupes imposés (FR9).
 *
 * Story à DEUX moitiés de nature opposée (patron 29.5) :
 *
 * 1. PREUVE FR9 (non-régression du vocabulaire de ciblage amont) — AC #1, #2, #3 :
 *    les labels (nom + `mode` libre/réservé casté `ControlHubLabelMode`) et les groupes imposés
 *    (nom + `label_name` associé/null) sont DÉJÀ reçus et persistés idempotemment par la chaîne
 *    28.1 (schéma/modèles/enum) + 28.2 (`ControlHubContractIngestionService`). Ce test VERROUILLE
 *    cette chaîne en relisant via le modèle (mode casté), pas seulement `assertDatabaseHas`.
 *    Aucune table/modèle/enum/migration n'est introduite par 30.1.
 *
 * 2. DURCISSEMENT réception (intégrité référentielle) — AC #4, #5, #6, #7 :
 *    la SEULE construction de 30.1. Un `imposed_groups[].label_name` non-nul orphelin (label non
 *    déclaré dans le même contrat) est refusé par `InvalidUpstreamContractException` levée AVANT
 *    la transaction (rollback total). Un `label_name` cohérent ou nul reste légitime.
 *
 * ⚠️ Tests sur HÔTE (php8.4 + pdo_sqlite) — JAMAIS sur la VM (sans pdo_sqlite).
 * ⚠️ GARDE-FOU R3 : aucun mot « central » (vocabulaire « amont » / « label » / « groupe imposé »).
 */
class UpstreamLabelsImposedGroupsReceptionTest extends TestCase
{
    use RefreshDatabase;

    private function service(): ControlHubContractIngestionService
    {
        return new ControlHubContractIngestionService();
    }

    // ──────────────────────────────────────────────────────────────────────────
    // PREUVE FR9 — réception/persistance labels (mode casté) + groupes imposés (AC #1)
    // ──────────────────────────────────────────────────────────────────────────

    public function test_labels_and_imposed_groups_are_received_with_casted_mode_and_label_name(): void
    {
        $result = $this->service()->ingest([
            'labels' => [
                ['name' => 'salle-info', 'mode' => 'reserved'],
                ['name' => 'nomade', 'mode' => 'free'],
            ],
            'imposed_groups' => [
                ['name' => 'parc-terminales', 'label_name' => 'salle-info'], // avec label associé
                ['name' => 'parc-libre'], // sans label associé (label_name absent → null)
            ],
        ]);

        $this->assertTrue($result->mutated);
        $this->assertDatabaseCount('controlhub_contract_labels', 2);
        $this->assertDatabaseCount('controlhub_contract_imposed_groups', 2);

        // Relecture VIA LE MODÈLE : prouve que `mode` est correctement casté en ControlHubLabelMode
        // (libre/réservé), pas seulement présent en base sous forme de chaîne.
        $reserved = ControlHubContractLabel::where('name', 'salle-info')->firstOrFail();
        $this->assertInstanceOf(ControlHubLabelMode::class, $reserved->mode);
        $this->assertSame(ControlHubLabelMode::Reserved, $reserved->mode);

        $free = ControlHubContractLabel::where('name', 'nomade')->firstOrFail();
        $this->assertSame(ControlHubLabelMode::Free, $free->mode);

        // Groupes imposés : label associé requêtable / null pour le groupe sans label.
        $withLabel = ControlHubContractImposedGroup::where('name', 'parc-terminales')->firstOrFail();
        $this->assertSame('salle-info', $withLabel->label_name);

        $withoutLabel = ControlHubContractImposedGroup::where('name', 'parc-libre')->firstOrFail();
        $this->assertNull($withoutLabel->label_name);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // PREUVE FR9 — idempotence : 2e réception identique = no-op (AC #2)
    // ──────────────────────────────────────────────────────────────────────────

    public function test_identical_labels_and_imposed_groups_reception_is_noop(): void
    {
        $payload = [
            'labels' => [
                ['name' => 'salle-info', 'mode' => 'reserved'],
                ['name' => 'nomade', 'mode' => 'free'],
            ],
            'imposed_groups' => [
                ['name' => 'parc-terminales', 'label_name' => 'salle-info'],
            ],
        ];

        $this->service()->ingest($payload);

        Event::fake();
        $result = $this->service()->ingest($payload);

        $this->assertFalse($result->mutated, 'Une réception identique doit être un no-op (NFR4).');
        Event::assertNotDispatched(ControlHubContractChanged::class);

        $this->assertDatabaseCount('controlhub_contract_labels', 2);
        $this->assertDatabaseCount('controlhub_contract_imposed_groups', 1);
        $this->assertSame(0, $result->labels['created']);
        $this->assertSame(0, $result->labels['updated']);
        $this->assertSame(0, $result->labels['deleted']);
        $this->assertSame(0, $result->imposedGroups['created']);
        $this->assertSame(0, $result->imposedGroups['updated']);
        $this->assertSame(0, $result->imposedGroups['deleted']);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // PREUVE FR9 — upsert/prune réconcilie le vocabulaire avec compteurs exacts (AC #3)
    // ──────────────────────────────────────────────────────────────────────────

    public function test_labels_and_imposed_groups_are_reconciled_with_exact_counters(): void
    {
        $this->service()->ingest([
            'labels' => [
                ['name' => 'salle-info', 'mode' => 'reserved'],
                ['name' => 'nomade', 'mode' => 'free'],
            ],
            'imposed_groups' => [
                ['name' => 'parc-terminales', 'label_name' => 'salle-info'],
                ['name' => 'parc-anciens', 'label_name' => 'salle-info'], // sera PRUNE
            ],
        ]);

        $result = $this->service()->ingest([
            'labels' => [
                ['name' => 'salle-info', 'mode' => 'free'], // UPDATE mode reserved → free
                ['name' => 'labo', 'mode' => 'free'],        // CREATE ; 'nomade' PRUNE
            ],
            'imposed_groups' => [
                ['name' => 'parc-terminales', 'label_name' => 'labo'], // UPDATE label_name
                ['name' => 'parc-secondes', 'label_name' => 'labo'],   // CREATE
            ],
        ]);

        $this->assertTrue($result->mutated);

        $this->assertSame(1, $result->labels['created']);  // 'labo'
        $this->assertSame(1, $result->labels['updated']);  // 'salle-info' mode changé
        $this->assertSame(1, $result->labels['deleted']);  // 'nomade'
        $this->assertSame(1, $result->imposedGroups['created']); // 'parc-secondes'
        $this->assertSame(1, $result->imposedGroups['updated']); // 'parc-terminales' label_name changé
        $this->assertSame(1, $result->imposedGroups['deleted']); // 'parc-anciens' pruné

        $this->assertSame(
            ControlHubLabelMode::Free,
            ControlHubContractLabel::where('name', 'salle-info')->firstOrFail()->mode,
        );
        $this->assertDatabaseMissing('controlhub_contract_labels', ['name' => 'nomade']);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // DURCISSEMENT — label_name cohérent → succès (AC #4)
    // ──────────────────────────────────────────────────────────────────────────

    public function test_imposed_group_with_declared_label_is_accepted(): void
    {
        $result = $this->service()->ingest([
            'labels' => [
                ['name' => 'salle-info', 'mode' => 'reserved'],
            ],
            'imposed_groups' => [
                ['name' => 'parc-terminales', 'label_name' => 'salle-info'],
            ],
        ]);

        $this->assertTrue($result->mutated);
        $this->assertDatabaseHas('controlhub_contract_imposed_groups', [
            'name' => 'parc-terminales',
            'label_name' => 'salle-info',
        ]);
    }

    public function test_imposed_group_referencing_a_free_label_is_accepted(): void
    {
        // Le durcissement n'exige PAS le mode `reserved` : un label déclaré `free` suffit.
        $result = $this->service()->ingest([
            'labels' => [
                ['name' => 'nomade', 'mode' => 'free'],
            ],
            'imposed_groups' => [
                ['name' => 'parc-nomades', 'label_name' => 'nomade'],
            ],
        ]);

        $this->assertTrue($result->mutated);
        $this->assertDatabaseHas('controlhub_contract_imposed_groups', [
            'name' => 'parc-nomades',
            'label_name' => 'nomade',
        ]);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // DURCISSEMENT — label_name orphelin → rejet + rollback total (AC #5)
    // ──────────────────────────────────────────────────────────────────────────

    public function test_imposed_group_with_orphan_label_is_rejected_without_partial_write(): void
    {
        $threw = false;
        $message = '';
        try {
            $this->service()->ingest([
                'labels' => [
                    ['name' => 'salle-info', 'mode' => 'reserved'],
                ],
                'imposed_groups' => [
                    ['name' => 'parc-terminales', 'label_name' => 'introuvable'],
                ],
            ]);
        } catch (InvalidUpstreamContractException $e) {
            $threw = true;
            $message = $e->getMessage();
        }

        $this->assertTrue($threw, 'Un label_name orphelin doit être rejeté.');
        $this->assertStringContainsString('imposed_groups.label_name', $message);
        $this->assertStringContainsString('parc-terminales', $message);
        $this->assertStringContainsString('introuvable', $message);

        // Rollback total : la levée survient AVANT la transaction → rien n'est persisté.
        $this->assertDatabaseCount('controlhub_contracts', 0);
        $this->assertDatabaseCount('controlhub_contract_labels', 0);
        $this->assertDatabaseCount('controlhub_contract_imposed_groups', 0);
    }

    public function test_orphan_label_leaves_existing_state_unchanged(): void
    {
        // Un contrat valide préexiste.
        $this->service()->ingest([
            'labels' => [['name' => 'salle-info', 'mode' => 'reserved']],
            'imposed_groups' => [['name' => 'parc-terminales', 'label_name' => 'salle-info']],
        ]);
        $this->assertDatabaseCount('controlhub_contract_labels', 1);
        $this->assertDatabaseCount('controlhub_contract_imposed_groups', 1);

        // Réception incohérente → rollback total, état préexistant intact.
        try {
            $this->service()->ingest([
                'labels' => [['name' => 'salle-info', 'mode' => 'reserved']],
                'imposed_groups' => [['name' => 'parc-terminales', 'label_name' => 'orphelin']],
            ]);
            $this->fail('Une InvalidUpstreamContractException était attendue.');
        } catch (InvalidUpstreamContractException $e) {
            // attendu
        }

        $this->assertDatabaseCount('controlhub_contract_labels', 1);
        $this->assertDatabaseCount('controlhub_contract_imposed_groups', 1);
        $this->assertDatabaseHas('controlhub_contract_imposed_groups', [
            'name' => 'parc-terminales',
            'label_name' => 'salle-info',
        ]);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // DURCISSEMENT — label_name nul/absent/'' → succès (AC #6)
    // ──────────────────────────────────────────────────────────────────────────

    public function test_imposed_group_without_label_is_accepted(): void
    {
        $result = $this->service()->ingest([
            'labels' => [
                ['name' => 'salle-info', 'mode' => 'reserved'],
            ],
            'imposed_groups' => [
                ['name' => 'parc-explicit-null', 'label_name' => null],
                ['name' => 'parc-empty-string', 'label_name' => ''],
                ['name' => 'parc-absent'], // label_name absent
            ],
        ]);

        $this->assertTrue($result->mutated);
        $this->assertDatabaseCount('controlhub_contract_imposed_groups', 3);

        // Les trois groupes sans label associé persistent avec label_name = null (jamais '').
        foreach (['parc-explicit-null', 'parc-empty-string', 'parc-absent'] as $groupName) {
            $group = ControlHubContractImposedGroup::where('name', $groupName)->firstOrFail();
            $this->assertNull($group->label_name, "« {$groupName} » doit avoir label_name = null.");
        }
    }

    // ──────────────────────────────────────────────────────────────────────────
    // GARDE-FOU TRANSVERSE — standalone / sans groupes imposés → durcissement inerte (AC #7)
    // ──────────────────────────────────────────────────────────────────────────

    public function test_guard_is_inert_when_no_imposed_groups(): void
    {
        // Des labels sans aucun groupe imposé : le cross-check n'a rien à vérifier.
        $result = $this->service()->ingest([
            'labels' => [
                ['name' => 'salle-info', 'mode' => 'reserved'],
            ],
        ]);

        $this->assertTrue($result->mutated);
        $this->assertDatabaseCount('controlhub_contract_labels', 1);
        $this->assertDatabaseCount('controlhub_contract_imposed_groups', 0);
    }

    public function test_guard_is_inert_on_empty_payload(): void
    {
        // Payload sans labels ni groupes imposés (standalone) : aucune erreur, tables vides.
        $result = $this->service()->ingest([]);

        $this->assertTrue($result->mutated); // contrat créé
        $this->assertDatabaseCount('controlhub_contract_labels', 0);
        $this->assertDatabaseCount('controlhub_contract_imposed_groups', 0);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Nature du parc réclamée (`is_physical`)
    // ──────────────────────────────────────────────────────────────────────────

    public function test_imposed_group_nature_is_received_in_its_three_states(): void
    {
        $this->service()->ingest([
            'imposed_groups' => [
                ['name' => 'salle-physique', 'is_physical' => true],
                ['name' => 'parc-logique', 'is_physical' => false],
                ['name' => 'parc-muet'],
            ],
        ]);

        $this->assertTrue(ControlHubContractImposedGroup::where('name', 'salle-physique')->firstOrFail()->is_physical);
        $this->assertFalse(ControlHubContractImposedGroup::where('name', 'parc-logique')->firstOrFail()->is_physical);
        $this->assertNull(ControlHubContractImposedGroup::where('name', 'parc-muet')->firstOrFail()->is_physical);
    }

    public function test_imposed_group_nature_accepts_string_and_integer_forms(): void
    {
        $this->service()->ingest([
            'imposed_groups' => [
                ['name' => 'en-chaine', 'is_physical' => 'true'],
                ['name' => 'en-entier', 'is_physical' => 1],
                ['name' => 'chaine-vide', 'is_physical' => ''],
            ],
        ]);

        $this->assertTrue(ControlHubContractImposedGroup::where('name', 'en-chaine')->firstOrFail()->is_physical);
        $this->assertTrue(ControlHubContractImposedGroup::where('name', 'en-entier')->firstOrFail()->is_physical);
        $this->assertNull(ControlHubContractImposedGroup::where('name', 'chaine-vide')->firstOrFail()->is_physical);
    }

    public function test_out_of_domain_nature_is_rejected_before_any_write(): void
    {
        try {
            $this->service()->ingest([
                'imposed_groups' => [['name' => 'parc-x', 'is_physical' => 'peut-etre']],
            ]);
            $this->fail('Une InvalidUpstreamContractException était attendue.');
        } catch (InvalidUpstreamContractException $e) {
            $this->assertStringContainsString('is_physical', $e->getMessage());
        }

        $this->assertDatabaseCount('controlhub_contract_imposed_groups', 0);
        $this->assertDatabaseCount('controlhub_contracts', 0);
    }
}

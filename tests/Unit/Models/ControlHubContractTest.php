<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Enums\ControlHubContractTarget;
use App\Enums\ControlHubEnforcementState;
use App\Enums\ControlHubLabelMode;
use App\Enums\ControlHubLinkState;
use App\Models\ControlHubContract;
use App\Models\ControlHubContractCatalogApp;
use App\Models\ControlHubContractImposedGroup;
use App\Models\ControlHubContractItem;
use App\Models\ControlHubContractLabel;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Story 28.1 — Tests du modèle de persistance du contrat amont (controlHub).
 *
 * Couverture :
 * - AC#1 / AC#2 : présence des 5 tables et colonnes attendues après migration ; rollback via RefreshDatabase.
 * - AC#3 : garde-fou R3 — aucun nom de table/colonne ne contient « central ».
 * - AC#4 : casts d'enum effectifs (lecture renvoie une instance d'enum, pas un string).
 * - AC#4 : relations hasMany/belongsTo chargent les enregistrements liés.
 * - AC#5 : contraintes d'unicité sur les clés naturelles (item, label, groupe imposé, app).
 * - AC#6 : NFR3 — aucune ligne par défaut dans les 5 tables.
 *
 * ⚠️ Tests sur HÔTE (php8.4 + pdo_sqlite) — JAMAIS sur la VM (sans pdo_sqlite).
 * ⚠️ Pas de test de longueur varchar (non appliquée en SQLite).
 */
class ControlHubContractTest extends TestCase
{
    use RefreshDatabase;

    // ──────────────────────────────────────────────────────────────────────────
    // AC#1 — Migration : présence des 5 tables
    // ──────────────────────────────────────────────────────────────────────────

    public function test_migration_creates_controlhub_contracts_table(): void
    {
        $this->assertTrue(Schema::hasTable('controlhub_contracts'));
    }

    public function test_migration_creates_controlhub_contract_items_table(): void
    {
        $this->assertTrue(Schema::hasTable('controlhub_contract_items'));
    }

    public function test_migration_creates_controlhub_contract_labels_table(): void
    {
        $this->assertTrue(Schema::hasTable('controlhub_contract_labels'));
    }

    public function test_migration_creates_controlhub_contract_imposed_groups_table(): void
    {
        $this->assertTrue(Schema::hasTable('controlhub_contract_imposed_groups'));
    }

    public function test_migration_creates_controlhub_contract_catalog_apps_table(): void
    {
        $this->assertTrue(Schema::hasTable('controlhub_contract_catalog_apps'));
    }

    // ──────────────────────────────────────────────────────────────────────────
    // AC#1 — Colonnes attendues dans chaque table
    // ──────────────────────────────────────────────────────────────────────────

    public function test_controlhub_contracts_has_expected_columns(): void
    {
        foreach (['id', 'link_state', 'received_at', 'created_at', 'updated_at'] as $col) {
            $this->assertTrue(
                Schema::hasColumn('controlhub_contracts', $col),
                "Colonne manquante dans controlhub_contracts : {$col}",
            );
        }
    }

    public function test_controlhub_contract_items_has_expected_columns(): void
    {
        $cols = ['id', 'controlhub_contract_id', 'type', 'key', 'value', 'enforcement_state', 'target_type', 'target_label', 'created_at', 'updated_at'];
        foreach ($cols as $col) {
            $this->assertTrue(
                Schema::hasColumn('controlhub_contract_items', $col),
                "Colonne manquante dans controlhub_contract_items : {$col}",
            );
        }
    }

    public function test_controlhub_contract_labels_has_expected_columns(): void
    {
        foreach (['id', 'controlhub_contract_id', 'name', 'mode', 'created_at', 'updated_at'] as $col) {
            $this->assertTrue(
                Schema::hasColumn('controlhub_contract_labels', $col),
                "Colonne manquante dans controlhub_contract_labels : {$col}",
            );
        }
    }

    public function test_controlhub_contract_imposed_groups_has_expected_columns(): void
    {
        foreach (['id', 'controlhub_contract_id', 'name', 'label_name', 'created_at', 'updated_at'] as $col) {
            $this->assertTrue(
                Schema::hasColumn('controlhub_contract_imposed_groups', $col),
                "Colonne manquante dans controlhub_contract_imposed_groups : {$col}",
            );
        }
    }

    public function test_controlhub_contract_catalog_apps_has_expected_columns(): void
    {
        foreach (['id', 'controlhub_contract_id', 'app_key', 'display_name', 'created_at', 'updated_at'] as $col) {
            $this->assertTrue(
                Schema::hasColumn('controlhub_contract_catalog_apps', $col),
                "Colonne manquante dans controlhub_contract_catalog_apps : {$col}",
            );
        }
    }

    // ──────────────────────────────────────────────────────────────────────────
    // AC#3 — Garde-fou R3 : aucun mot « central » dans tables/colonnes
    // ──────────────────────────────────────────────────────────────────────────

    public function test_r3_no_table_name_contains_central(): void
    {
        $tables = [
            'controlhub_contracts',
            'controlhub_contract_items',
            'controlhub_contract_labels',
            'controlhub_contract_imposed_groups',
            'controlhub_contract_catalog_apps',
        ];

        foreach ($tables as $table) {
            $this->assertStringNotContainsStringIgnoringCase(
                'central',
                $table,
                "Le nom de table « {$table} » contient « central » (violation R3)",
            );
        }
    }

    public function test_r3_no_column_name_contains_central(): void
    {
        $tables = [
            'controlhub_contracts',
            'controlhub_contract_items',
            'controlhub_contract_labels',
            'controlhub_contract_imposed_groups',
            'controlhub_contract_catalog_apps',
        ];

        foreach ($tables as $table) {
            $columns = Schema::getColumnListing($table);
            foreach ($columns as $col) {
                $this->assertStringNotContainsStringIgnoringCase(
                    'central',
                    $col,
                    "La colonne « {$col} » de « {$table} » contient « central » (violation R3)",
                );
            }
        }
    }

    // ──────────────────────────────────────────────────────────────────────────
    // AC#4 — Casts d'enum effectifs
    // ──────────────────────────────────────────────────────────────────────────

    public function test_link_state_is_cast_to_enum(): void
    {
        $contract = ControlHubContract::factory()->create([
            'link_state' => 'active',
        ]);

        $loaded = ControlHubContract::find($contract->id);

        $this->assertInstanceOf(ControlHubLinkState::class, $loaded->link_state);
        $this->assertSame(ControlHubLinkState::Active, $loaded->link_state);
    }

    public function test_link_state_severed_is_cast_to_enum(): void
    {
        $contract = ControlHubContract::factory()->severed()->create();
        $loaded = ControlHubContract::find($contract->id);

        $this->assertInstanceOf(ControlHubLinkState::class, $loaded->link_state);
        $this->assertSame(ControlHubLinkState::Severed, $loaded->link_state);
    }

    public function test_item_enforcement_state_is_cast_to_enum(): void
    {
        $item = ControlHubContractItem::factory()->create([
            'enforcement_state' => 'locked',
        ]);

        $loaded = ControlHubContractItem::find($item->id);

        $this->assertInstanceOf(ControlHubEnforcementState::class, $loaded->enforcement_state);
        $this->assertSame(ControlHubEnforcementState::Locked, $loaded->enforcement_state);
    }

    public function test_item_enforcement_state_permissive_is_cast_to_enum(): void
    {
        $item = ControlHubContractItem::factory()->permissive()->create();
        $loaded = ControlHubContractItem::find($item->id);

        $this->assertInstanceOf(ControlHubEnforcementState::class, $loaded->enforcement_state);
        $this->assertSame(ControlHubEnforcementState::Permissive, $loaded->enforcement_state);
    }

    public function test_item_enforcement_state_absent_is_cast_to_enum(): void
    {
        $item = ControlHubContractItem::factory()->absent()->create();
        $loaded = ControlHubContractItem::find($item->id);

        $this->assertInstanceOf(ControlHubEnforcementState::class, $loaded->enforcement_state);
        $this->assertSame(ControlHubEnforcementState::Absent, $loaded->enforcement_state);
    }

    public function test_item_target_type_is_cast_to_enum(): void
    {
        $item = ControlHubContractItem::factory()->create([
            'target_type' => 'instance',
        ]);

        $loaded = ControlHubContractItem::find($item->id);

        $this->assertInstanceOf(ControlHubContractTarget::class, $loaded->target_type);
        $this->assertSame(ControlHubContractTarget::Instance, $loaded->target_type);
    }

    public function test_item_target_type_label_is_cast_to_enum(): void
    {
        $item = ControlHubContractItem::factory()->forLabel('salle-info')->create();
        $loaded = ControlHubContractItem::find($item->id);

        $this->assertInstanceOf(ControlHubContractTarget::class, $loaded->target_type);
        $this->assertSame(ControlHubContractTarget::Label, $loaded->target_type);
        $this->assertSame('salle-info', $loaded->target_label);
    }

    public function test_label_mode_is_cast_to_enum(): void
    {
        $label = ControlHubContractLabel::factory()->create([
            'mode' => 'free',
        ]);

        $loaded = ControlHubContractLabel::find($label->id);

        $this->assertInstanceOf(ControlHubLabelMode::class, $loaded->mode);
        $this->assertSame(ControlHubLabelMode::Free, $loaded->mode);
    }

    public function test_label_mode_reserved_is_cast_to_enum(): void
    {
        $label = ControlHubContractLabel::factory()->reserved()->create();
        $loaded = ControlHubContractLabel::find($label->id);

        $this->assertInstanceOf(ControlHubLabelMode::class, $loaded->mode);
        $this->assertSame(ControlHubLabelMode::Reserved, $loaded->mode);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // AC#4 — Relations hasMany / belongsTo
    // ──────────────────────────────────────────────────────────────────────────

    public function test_contract_has_many_items(): void
    {
        $contract = ControlHubContract::factory()->create();

        ControlHubContractItem::factory()->count(3)->create([
            'controlhub_contract_id' => $contract->id,
        ]);

        $loaded = ControlHubContract::with('items')->find($contract->id);

        $this->assertCount(3, $loaded->items);
        $this->assertInstanceOf(ControlHubContractItem::class, $loaded->items->first());
    }

    public function test_contract_has_many_labels(): void
    {
        $contract = ControlHubContract::factory()->create();

        ControlHubContractLabel::factory()->count(2)->create([
            'controlhub_contract_id' => $contract->id,
        ]);

        $loaded = ControlHubContract::with('labels')->find($contract->id);

        $this->assertCount(2, $loaded->labels);
        $this->assertInstanceOf(ControlHubContractLabel::class, $loaded->labels->first());
    }

    public function test_contract_has_many_imposed_groups(): void
    {
        $contract = ControlHubContract::factory()->create();

        ControlHubContractImposedGroup::factory()->count(2)->create([
            'controlhub_contract_id' => $contract->id,
        ]);

        $loaded = ControlHubContract::with('imposedGroups')->find($contract->id);

        $this->assertCount(2, $loaded->imposedGroups);
        $this->assertInstanceOf(ControlHubContractImposedGroup::class, $loaded->imposedGroups->first());
    }

    public function test_contract_has_many_catalog_apps(): void
    {
        $contract = ControlHubContract::factory()->create();

        ControlHubContractCatalogApp::factory()->count(4)->create([
            'controlhub_contract_id' => $contract->id,
        ]);

        $loaded = ControlHubContract::with('catalogApps')->find($contract->id);

        $this->assertCount(4, $loaded->catalogApps);
        $this->assertInstanceOf(ControlHubContractCatalogApp::class, $loaded->catalogApps->first());
    }

    public function test_item_belongs_to_contract(): void
    {
        $contract = ControlHubContract::factory()->create();
        $item = ControlHubContractItem::factory()->create(['controlhub_contract_id' => $contract->id]);

        $loaded = ControlHubContractItem::with('contract')->find($item->id);

        $this->assertInstanceOf(ControlHubContract::class, $loaded->contract);
        $this->assertSame($contract->id, $loaded->contract->id);
    }

    public function test_label_belongs_to_contract(): void
    {
        $contract = ControlHubContract::factory()->create();
        $label = ControlHubContractLabel::factory()->create(['controlhub_contract_id' => $contract->id]);

        $loaded = ControlHubContractLabel::with('contract')->find($label->id);

        $this->assertInstanceOf(ControlHubContract::class, $loaded->contract);
        $this->assertSame($contract->id, $loaded->contract->id);
    }

    public function test_imposed_group_belongs_to_contract(): void
    {
        $contract = ControlHubContract::factory()->create();
        $group = ControlHubContractImposedGroup::factory()->create(['controlhub_contract_id' => $contract->id]);

        $loaded = ControlHubContractImposedGroup::with('contract')->find($group->id);

        $this->assertInstanceOf(ControlHubContract::class, $loaded->contract);
        $this->assertSame($contract->id, $loaded->contract->id);
    }

    public function test_catalog_app_belongs_to_contract(): void
    {
        $contract = ControlHubContract::factory()->create();
        $app = ControlHubContractCatalogApp::factory()->create(['controlhub_contract_id' => $contract->id]);

        $loaded = ControlHubContractCatalogApp::with('contract')->find($app->id);

        $this->assertInstanceOf(ControlHubContract::class, $loaded->contract);
        $this->assertSame($contract->id, $loaded->contract->id);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // AC#5 — Contraintes d'unicité sur les clés naturelles (NFR4)
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * NFR4 — cas DOMINANT : deux items 'instance' identiques sur le MÊME contrat doivent
     * collisionner. C'est le cas que la clé naturelle DOIT protéger (target_label='' NOT NULL).
     * Avant le correctif #1 (target_label nullable), ce test échouait : NULL != NULL en PG/SQLite
     * → aucune collision → trou d'idempotence sur le cas le plus courant. [Review 28.1 #1/#2]
     */
    public function test_item_natural_key_unique_constraint_instance(): void
    {
        $this->expectException(QueryException::class);

        $contract = ControlHubContract::factory()->create();

        // target_label par défaut = '' (cible instance), PAS null.
        $data = [
            'controlhub_contract_id' => $contract->id,
            'type' => 'capabilities',
            'key' => 'cap_show_ext',
            'value' => 'on',
            'enforcement_state' => ControlHubEnforcementState::Locked,
            'target_type' => ControlHubContractTarget::Instance,
            'target_label' => '',
        ];

        ControlHubContractItem::create($data);
        ControlHubContractItem::create($data); // doit lever une QueryException
    }

    /** NFR4 — cas label : deux items ciblant le même label sur le même contrat collisionnent. */
    public function test_item_natural_key_unique_constraint_label(): void
    {
        $this->expectException(QueryException::class);

        $contract = ControlHubContract::factory()->create();
        $data = [
            'controlhub_contract_id' => $contract->id,
            'type' => 'capabilities',
            'key' => 'cap_show_ext',
            'value' => 'on',
            'enforcement_state' => ControlHubEnforcementState::Locked,
            'target_type' => ControlHubContractTarget::Label,
            'target_label' => 'salle-info',
        ];

        ControlHubContractItem::create($data);
        ControlHubContractItem::create($data); // doit lever une QueryException
    }

    public function test_label_natural_key_unique_constraint(): void
    {
        $this->expectException(QueryException::class);

        $contract = ControlHubContract::factory()->create();
        $data = [
            'controlhub_contract_id' => $contract->id,
            'name' => 'salle-info',
            'mode' => ControlHubLabelMode::Free,
        ];

        ControlHubContractLabel::create($data);
        ControlHubContractLabel::create($data); // doit lever une QueryException
    }

    public function test_imposed_group_natural_key_unique_constraint(): void
    {
        $this->expectException(QueryException::class);

        $contract = ControlHubContract::factory()->create();
        $data = [
            'controlhub_contract_id' => $contract->id,
            'name' => 'parc-terminales',
            'label_name' => null,
        ];

        ControlHubContractImposedGroup::create($data);
        ControlHubContractImposedGroup::create($data); // doit lever une QueryException
    }

    public function test_catalog_app_natural_key_unique_constraint(): void
    {
        $this->expectException(QueryException::class);

        $contract = ControlHubContract::factory()->create();
        $data = [
            'controlhub_contract_id' => $contract->id,
            'app_key' => 'firefox',
            'display_name' => 'Firefox',
        ];

        ControlHubContractCatalogApp::create($data);
        ControlHubContractCatalogApp::create($data); // doit lever une QueryException
    }

    /**
     * Vérifie que le même triplet (type, key, target_type, target_label) peut coexister
     * sur des CONTRATS différents (la contrainte est scopée au contrat).
     */
    public function test_item_uniqueness_is_scoped_to_contract(): void
    {
        $contract1 = ControlHubContract::factory()->create();
        $contract2 = ControlHubContract::factory()->create();

        $attrs = ['type' => 'capabilities', 'key' => 'cap_x', 'value' => 'on',
            'enforcement_state' => ControlHubEnforcementState::Locked,
            'target_type' => ControlHubContractTarget::Instance, 'target_label' => ''];

        ControlHubContractItem::create(['controlhub_contract_id' => $contract1->id] + $attrs);
        ControlHubContractItem::create(['controlhub_contract_id' => $contract2->id] + $attrs);

        $this->assertDatabaseCount('controlhub_contract_items', 2);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // AC#6 — NFR3 : aucune ligne par défaut dans les 5 tables (standalone préservé)
    // ──────────────────────────────────────────────────────────────────────────

    public function test_nfr3_no_default_rows_in_any_table(): void
    {
        $this->assertDatabaseCount('controlhub_contracts', 0);
        $this->assertDatabaseCount('controlhub_contract_items', 0);
        $this->assertDatabaseCount('controlhub_contract_labels', 0);
        $this->assertDatabaseCount('controlhub_contract_imposed_groups', 0);
        $this->assertDatabaseCount('controlhub_contract_catalog_apps', 0);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // AC#4 — Cast received_at en datetime
    // ──────────────────────────────────────────────────────────────────────────

    public function test_received_at_is_cast_to_datetime(): void
    {
        $contract = ControlHubContract::factory()->create([
            'received_at' => '2026-06-26 10:00:00',
        ]);

        $loaded = ControlHubContract::find($contract->id);

        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $loaded->received_at);
    }

    public function test_received_at_nullable(): void
    {
        $contract = ControlHubContract::factory()->notYetReceived()->create();
        $loaded = ControlHubContract::find($contract->id);

        $this->assertNull($loaded->received_at);
    }
}

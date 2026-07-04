<?php

declare(strict_types=1);

namespace Tests\Feature\Migrations;

use App\Models\FolderAccessRule;
use App\Models\FolderAccessRuleAssignable;
use App\Models\UserGroup;
use App\Models\WorkstationGroup;
use App\Observers\UserGroupObserver;
use App\Observers\WorkstationGroupObserver;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 36.4 (AC1) — schéma `folder_access_rules` + pivot polymorphe (calque
 * Epic 34). Domaines validés APPLICATIVEMENT (constantes du guard).
 */
class FolderAccessRulesSchemaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        WorkstationGroupObserver::disableSync();
        UserGroupObserver::disableSync();
    }

    protected function tearDown(): void
    {
        WorkstationGroupObserver::enableSync();
        UserGroupObserver::enableSync();
        parent::tearDown();
    }

    #[Test]
    public function tables_have_the_expected_columns(): void
    {
        foreach (['path', 'user_group_id', 'ace_type', 'rights', 'applies_to', 'label', 'is_active', 'created_by_user_id', 'created_at', 'updated_at'] as $col) {
            self::assertTrue(Schema::hasColumn('folder_access_rules', $col), "colonne {$col}");
        }
        foreach (['folder_access_rule_id', 'assignable_id', 'assignable_type'] as $col) {
            self::assertTrue(Schema::hasColumn('folder_access_rule_assignables', $col), "pivot {$col}");
        }
        // Pas de colonne `access` (calque SANS le niveau POSIX — le niveau est `rights`).
        self::assertFalse(Schema::hasColumn('folder_access_rule_assignables', 'access'));
    }

    #[Test]
    public function is_active_defaults_to_true(): void
    {
        $rule = FolderAccessRule::factory()->create();
        self::assertTrue((bool) $rule->fresh()->is_active);
    }

    #[Test]
    public function pivot_is_unique_per_rule_and_target(): void
    {
        $rule = FolderAccessRule::factory()->create();
        $wg = WorkstationGroup::factory()->logical()->create();

        FolderAccessRuleAssignable::create([
            'folder_access_rule_id' => $rule->id,
            'assignable_type' => WorkstationGroup::class,
            'assignable_id' => $wg->id,
        ]);

        $this->expectException(QueryException::class);
        FolderAccessRuleAssignable::create([
            'folder_access_rule_id' => $rule->id,
            'assignable_type' => WorkstationGroup::class,
            'assignable_id' => $wg->id,
        ]);
    }

    #[Test]
    public function deleting_a_rule_cascades_the_pivot(): void
    {
        $rule = FolderAccessRule::factory()->create();
        $wg = WorkstationGroup::factory()->logical()->create();
        FolderAccessRuleAssignable::create([
            'folder_access_rule_id' => $rule->id,
            'assignable_type' => WorkstationGroup::class,
            'assignable_id' => $wg->id,
        ]);

        $rule->delete();

        self::assertSame(0, FolderAccessRuleAssignable::where('folder_access_rule_id', $rule->id)->count());
    }

    #[Test]
    public function deleting_a_group_cascades_its_rules(): void
    {
        $group = UserGroup::factory()->create();
        $rule = FolderAccessRule::factory()->create(['user_group_id' => $group->id]);

        $group->delete();

        self::assertNull(FolderAccessRule::find($rule->id), 'cascadeOnDelete du groupe (fenêtre d\'orphelin documentée)');
    }

    #[Test]
    public function allowed_assignable_types_is_workstation_group_only_v1(): void
    {
        self::assertSame([WorkstationGroup::class], FolderAccessRule::ALLOWED_ASSIGNABLE_TYPES);
    }
}

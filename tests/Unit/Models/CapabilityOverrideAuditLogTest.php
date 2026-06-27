<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\Capability;
use App\Models\CapabilityOverrideAuditLog;
use App\Models\User;
use App\Models\WorkstationGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 29.5 (NFR5) — Modèle d'audit append-only des overrides de capacité.
 *
 * Couvre : la fabrique `log()` écrit tous les champs + un `created_at` ;
 * append-only (UPDATE → LogicException, calque DelegationHistory) ; FKs
 * `nullOnDelete` (suppression user/capability → ligne conservée, dénormalisés
 * intacts).
 *
 * Tests HÔTE (php8.4 + pdo_sqlite), `RefreshDatabase`.
 */
class CapabilityOverrideAuditLogTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function log_creates_a_row_with_every_field_and_a_timestamp(): void
    {
        $user = User::factory()->create(['login' => 'refnum01']);
        $cap = Capability::factory()->create(['label' => 'Bureau à distance']);

        $entry = CapabilityOverrideAuditLog::log(
            action: CapabilityOverrideAuditLog::ACTION_CREATE,
            actorUserId: $user->id,
            actorLogin: $user->login,
            capabilityId: $cap->id,
            capabilityLabel: $cap->label,
            assignableType: WorkstationGroup::class,
            assignableId: 42,
            scopeLabel: 'Salle Info 1',
            oldValue: null,
            newValue: 'on',
            upstreamStatus: CapabilityOverrideAuditLog::UPSTREAM_LOCAL,
        );

        $this->assertDatabaseHas('capability_override_audit_logs', [
            'id' => $entry->id,
            'action' => 'create',
            'actor_user_id' => $user->id,
            'actor_login' => 'refnum01',
            'capability_id' => $cap->id,
            'capability_label' => 'Bureau à distance',
            'assignable_type' => WorkstationGroup::class,
            'assignable_id' => 42,
            'scope_label' => 'Salle Info 1',
            'old_value' => null,
            'new_value' => 'on',
            'upstream_status' => 'local',
        ]);

        self::assertNotNull($entry->created_at, 'created_at est posé à l\'insertion');
    }

    #[Test]
    public function table_is_append_only_update_throws(): void
    {
        $entry = CapabilityOverrideAuditLog::log(
            action: CapabilityOverrideAuditLog::ACTION_CREATE,
            actorUserId: null,
            actorLogin: 'x',
            capabilityId: null,
            capabilityLabel: 'C',
            assignableType: WorkstationGroup::class,
            assignableId: 1,
            scopeLabel: null,
            oldValue: null,
            newValue: 'on',
            upstreamStatus: CapabilityOverrideAuditLog::UPSTREAM_LOCAL,
        );

        $this->expectException(LogicException::class);

        $entry->action = CapabilityOverrideAuditLog::ACTION_UPDATE;
        $entry->save();
    }

    #[Test]
    public function foreign_keys_are_null_on_delete_and_denormalized_columns_survive(): void
    {
        $user = User::factory()->create(['login' => 'refnum02']);
        $cap = Capability::factory()->create(['label' => 'Extensions visibles']);

        $entry = CapabilityOverrideAuditLog::log(
            action: CapabilityOverrideAuditLog::ACTION_UPDATE,
            actorUserId: $user->id,
            actorLogin: $user->login,
            capabilityId: $cap->id,
            capabilityLabel: $cap->label,
            assignableType: WorkstationGroup::class,
            assignableId: 7,
            scopeLabel: 'Parc B',
            oldValue: 'on',
            newValue: 'off',
            upstreamStatus: CapabilityOverrideAuditLog::UPSTREAM_PERMISSIVE,
        );

        // Suppression des entités référencées → FKs mises à null, ligne conservée.
        $user->delete();
        $cap->delete();

        $fresh = $entry->fresh();

        self::assertNotNull($fresh, 'la ligne d\'audit survit à la suppression des entités');
        self::assertNull($fresh->actor_user_id, 'actor_user_id mis à null (nullOnDelete)');
        self::assertNull($fresh->capability_id, 'capability_id mis à null (nullOnDelete)');
        // Les colonnes dénormalisées préservent la lisibilité.
        self::assertSame('refnum02', $fresh->actor_login);
        self::assertSame('Extensions visibles', $fresh->capability_label);
        self::assertSame('Parc B', $fresh->scope_label);
        self::assertSame('permissive', $fresh->upstream_status);
    }
}

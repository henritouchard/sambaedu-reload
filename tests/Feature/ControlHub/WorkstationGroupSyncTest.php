<?php

namespace Tests\Feature\ControlHub;

use App\Enums\LockReason;
use App\Jobs\DeleteWorkstationGroupJob;
use App\Jobs\SyncWorkstationGroupJob;
use App\Models\ControlHubTask;
use App\Models\WorkstationGroup;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * API ControlHub : /api/v1/workstation-groups/sync (upsert flat), /sync-tree
 * (upsert arborescent), /delete.
 *
 * L'API historique visait 3 endpoints séparés (create/update/delete) mais a
 * été simplifiée en sync/sync-tree/delete avant implémentation. Ce test cible
 * les routes réellement exposées dans routes/api.php.
 */
class WorkstationGroupSyncTest extends TestCase
{
    private string $apiToken = 'test_api_key_for_wsgroup_sync_test_1234567890';

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTables();
        config(['controlHub.se4fs.instance_api_key' => $this->apiToken]);
        Queue::fake();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('workstation_groups');
        Schema::dropIfExists('controlhub_tasks');
        Schema::dropIfExists('controlhub_connection');
        parent::tearDown();
    }

    private function createTables(): void
    {
        Schema::create('workstation_groups', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('display_name')->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_physical')->default(false);
            $table->string('app_profile_name')->nullable();
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->string('ad_dn')->nullable();
            $table->string('ad_guid')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('locked')->nullable();
            $table->boolean('managed_by_control_hub')->default(false);
            $table->uuid('controlhub_id')->nullable()->unique();
            $table->timestamp('controlhub_version')->nullable();
            $table->timestamps();
        });

        Schema::create('controlhub_tasks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('controlhub_task_id')->unique();
            $table->string('name', 255);
            $table->string('type', 100);
            $table->json('payload')->nullable();
            $table->string('status', 20)->default('received');
            $table->json('result')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->boolean('callback_sent')->default(false);
            $table->timestamp('callback_sent_at')->nullable();
            $table->json('callback_response')->nullable();
            $table->text('callback_error')->nullable();
            $table->timestamps();
        });

        // Depuis la couture E10, le middleware ControlHubAuth interroge
        // `controlhub_connection` (ControlHubConnection::current()) sur chaque
        // requête entrante authentifiée. Table présente mais VIDE : current()
        // renvoie null → repli legacy `instance_api_key` (le credential utilisé
        // par authHeaders() de cette suite). Sans elle : « no such table ».
        Schema::create('controlhub_connection', function (Blueprint $table) {
            $table->id();
            $table->string('base_url', 512)->nullable();
            $table->text('api_token')->nullable();
            $table->string('se4fs_api_token', 64)->nullable();
            $table->boolean('is_active')->default(false);
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }

    private function authHeaders(): array
    {
        return ['Authorization' => 'Bearer '.$this->apiToken];
    }

    private function syncPayload(array $overrides = []): array
    {
        return array_merge([
            'task_id' => (string) Str::uuid(),
            'task_name' => 'Sync groupe salle-A',
            'task_type' => 'sync_workstation_group',
            'payload' => array_merge([
                'controlhub_id' => (string) Str::uuid(),
                'name' => 'salle-A',
                'display_name' => 'Salle A',
                'is_physical' => true,
            ], $overrides['payload'] ?? []),
        ], array_diff_key($overrides, ['payload' => true]));
    }

    private function deletePayload(string $controlhubId): array
    {
        return [
            'task_id' => (string) Str::uuid(),
            'task_name' => 'Suppression groupe',
            'task_type' => 'delete_workstation_group',
            'payload' => ['controlhub_id' => $controlhubId],
        ];
    }

    // ─── /sync ───────────────────────────────────────────────────────────────

    #[Test]
    public function sync_requires_auth(): void
    {
        $this->postJson('/api/v1/workstation-groups/sync', $this->syncPayload())
            ->assertStatus(403);
    }

    #[Test]
    public function sync_rejects_empty_payload(): void
    {
        $this->postJson('/api/v1/workstation-groups/sync', [
            'task_id' => (string) Str::uuid(),
            'task_name' => 'Test',
            'task_type' => 'sync_workstation_group',
            'payload' => [],
        ], $this->authHeaders())->assertStatus(422);
    }

    #[Test]
    public function sync_requires_controlhub_id(): void
    {
        $payload = $this->syncPayload();
        unset($payload['payload']['controlhub_id']);

        $this->postJson('/api/v1/workstation-groups/sync', $payload, $this->authHeaders())
            ->assertStatus(422);
    }

    #[Test]
    public function sync_requires_is_physical(): void
    {
        $payload = $this->syncPayload();
        unset($payload['payload']['is_physical']);

        $this->postJson('/api/v1/workstation-groups/sync', $payload, $this->authHeaders())
            ->assertStatus(422);
    }

    #[Test]
    public function sync_rejects_wrong_task_type(): void
    {
        $this->postJson(
            '/api/v1/workstation-groups/sync',
            $this->syncPayload(['task_type' => 'delete_workstation_group']),
            $this->authHeaders()
        )->assertStatus(422);
    }

    #[Test]
    public function sync_creates_task_and_dispatches_job(): void
    {
        $payload = $this->syncPayload();

        $this->postJson('/api/v1/workstation-groups/sync', $payload, $this->authHeaders())
            ->assertStatus(200)
            ->assertJson(['success' => true, 'message' => 'Task received and queued']);

        Queue::assertPushed(SyncWorkstationGroupJob::class);

        $this->assertDatabaseHas('controlhub_tasks', [
            'controlhub_task_id' => $payload['task_id'],
            'type' => 'sync_workstation_group',
        ]);
    }

    #[Test]
    public function sync_is_idempotent_on_task_id(): void
    {
        $payload = $this->syncPayload();

        $this->postJson('/api/v1/workstation-groups/sync', $payload, $this->authHeaders())->assertStatus(200);
        $this->postJson('/api/v1/workstation-groups/sync', $payload, $this->authHeaders())
            ->assertStatus(200)
            ->assertJson(['message' => 'Task already received']);

        $this->assertEquals(
            1,
            ControlHubTask::where('controlhub_task_id', $payload['task_id'])->count()
        );
    }

    // ─── /sync-tree ──────────────────────────────────────────────────────────

    #[Test]
    public function sync_tree_requires_auth(): void
    {
        $payload = [
            'task_id' => (string) Str::uuid(),
            'task_name' => 'Sync arbre',
            'task_type' => 'sync_workstation_group_tree',
            'payload' => [
                'tree' => [
                    'controlhub_id' => (string) Str::uuid(),
                    'name' => 'root',
                    'is_physical' => true,
                    'children' => [],
                ],
            ],
        ];

        $this->postJson('/api/v1/workstation-groups/sync-tree', $payload)
            ->assertStatus(403);
    }

    #[Test]
    public function sync_tree_creates_task_and_dispatches_job(): void
    {
        $payload = [
            'task_id' => (string) Str::uuid(),
            'task_name' => 'Sync arbre',
            'task_type' => 'sync_workstation_group_tree',
            'payload' => [
                'tree' => [
                    'controlhub_id' => (string) Str::uuid(),
                    'name' => 'root',
                    'is_physical' => true,
                    'children' => [],
                ],
            ],
        ];

        $this->postJson('/api/v1/workstation-groups/sync-tree', $payload, $this->authHeaders())
            ->assertStatus(200);

        $this->assertDatabaseHas('controlhub_tasks', [
            'controlhub_task_id' => $payload['task_id'],
            'type' => 'sync_workstation_group_tree',
        ]);
    }

    // ─── /delete ─────────────────────────────────────────────────────────────

    #[Test]
    public function delete_requires_auth(): void
    {
        $this->postJson('/api/v1/workstation-groups/delete', $this->deletePayload((string) Str::uuid()))
            ->assertStatus(403);
    }

    #[Test]
    public function delete_returns_404_for_unknown_group(): void
    {
        $this->postJson(
            '/api/v1/workstation-groups/delete',
            $this->deletePayload((string) Str::uuid()),
            $this->authHeaders()
        )->assertStatus(404);
    }

    #[Test]
    public function delete_returns_403_for_group_not_managed_by_controlhub(): void
    {
        $group = WorkstationGroup::create([
            'controlhub_id' => (string) Str::uuid(),
            'name' => 'local-group',
            'is_physical' => true,
            'locked' => null,
        ]);

        $this->postJson(
            '/api/v1/workstation-groups/delete',
            $this->deletePayload($group->controlhub_id),
            $this->authHeaders()
        )->assertStatus(403);

        Queue::assertNotPushed(DeleteWorkstationGroupJob::class);
    }

    #[Test]
    public function delete_dispatches_job_for_controlhub_managed_group(): void
    {
        $group = WorkstationGroup::create([
            'controlhub_id' => (string) Str::uuid(),
            'name' => 'ch-group',
            'is_physical' => true,
            'locked' => LockReason::CONTROL_HUB->value,
        ]);

        $this->postJson(
            '/api/v1/workstation-groups/delete',
            $this->deletePayload($group->controlhub_id),
            $this->authHeaders()
        )->assertStatus(200);

        Queue::assertPushed(DeleteWorkstationGroupJob::class);
    }

    #[Test]
    public function delete_is_idempotent_on_task_id(): void
    {
        $group = WorkstationGroup::create([
            'controlhub_id' => (string) Str::uuid(),
            'name' => 'ch-group',
            'is_physical' => true,
            'locked' => LockReason::CONTROL_HUB->value,
        ]);
        $payload = $this->deletePayload($group->controlhub_id);

        $this->postJson('/api/v1/workstation-groups/delete', $payload, $this->authHeaders())->assertStatus(200);
        $this->postJson('/api/v1/workstation-groups/delete', $payload, $this->authHeaders())
            ->assertStatus(200)
            ->assertJson(['message' => 'Task already received']);

        $this->assertEquals(
            1,
            ControlHubTask::where('controlhub_task_id', $payload['task_id'])->count()
        );
    }
}

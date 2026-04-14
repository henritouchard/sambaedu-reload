<?php

namespace Tests\Feature\ControlHub;

use App\Jobs\DeleteShortcutJob;
use App\Jobs\SyncShortcutJob;
use App\Models\ControlHubTask;
use App\Models\Shortcut;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * API ControlHub : /api/v1/shortcuts/sync (upsert) et /delete.
 *
 * L'API historique était à 3 endpoints (create/update/delete) mais le design
 * a été simplifié en sync (upsert) + delete avant implémentation. Ce test
 * cible l'API réellement exposée dans routes/api.php.
 */
class ShortcutSyncTest extends TestCase
{
    private string $apiToken = 'test_api_key_for_shortcut_sync_test_1234567890';

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTables();
        config(['controlHub.se4fs.instance_api_key' => $this->apiToken]);
        Queue::fake();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('shortcut_assignables');
        Schema::dropIfExists('shortcuts');
        Schema::dropIfExists('workstation_groups');
        Schema::dropIfExists('controlhub_tasks');
        parent::tearDown();
    }

    private function createTables(): void
    {
        Schema::create('shortcuts', function (Blueprint $table) {
            $table->id();
            $table->uuid('controlhub_id')->nullable()->unique();
            $table->string('key', 100)->unique();
            $table->string('name', 255);
            $table->string('owner', 512)->nullable();
            $table->string('place', 20)->default('desktop');
            $table->boolean('is_global')->default(false);
            $table->string('windows_link', 512)->nullable();
            $table->text('windows_args')->nullable();
            $table->string('windows_path', 512)->nullable();
            $table->string('windows_icon', 512)->nullable();
            $table->string('linux_link', 512)->nullable();
            $table->text('linux_args')->nullable();
            $table->string('linux_path', 512)->nullable();
            $table->string('linux_icon', 512)->nullable();
            $table->string('linux_startupwmclass', 255)->nullable();
            $table->string('icon_path', 512)->nullable();
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
    }

    private function authHeaders(): array
    {
        return ['Authorization' => 'Bearer '.$this->apiToken];
    }

    private function syncPayload(array $overrides = []): array
    {
        return array_merge([
            'task_id' => (string) Str::uuid(),
            'task_name' => 'Sync raccourci Firefox',
            'task_type' => 'sync_shortcut',
            'payload' => array_merge([
                'controlhub_id' => (string) Str::uuid(),
                'name' => 'Firefox',
                'owner' => 'Profs',
                'place' => 'desktop',
                'windows' => ['link' => 'C:\\Program Files\\Firefox\\firefox.exe'],
            ], $overrides['payload'] ?? []),
        ], array_diff_key($overrides, ['payload' => true]));
    }

    private function deletePayload(string $controlhubId): array
    {
        return [
            'task_id' => (string) Str::uuid(),
            'task_name' => 'Suppression raccourci',
            'task_type' => 'delete_shortcut',
            'payload' => ['controlhub_id' => $controlhubId],
        ];
    }

    // ─── /sync ───────────────────────────────────────────────────────────────

    #[Test]
    public function sync_requires_auth(): void
    {
        $this->postJson('/api/v1/shortcuts/sync', $this->syncPayload())
            ->assertStatus(403);
    }

    #[Test]
    public function sync_rejects_empty_payload(): void
    {
        $this->postJson('/api/v1/shortcuts/sync', [
            'task_id' => (string) Str::uuid(),
            'task_name' => 'Test',
            'task_type' => 'sync_shortcut',
            'payload' => [],
        ], $this->authHeaders())->assertStatus(422);
    }

    #[Test]
    public function sync_requires_controlhub_id(): void
    {
        $payload = $this->syncPayload();
        unset($payload['payload']['controlhub_id']);

        $this->postJson('/api/v1/shortcuts/sync', $payload, $this->authHeaders())
            ->assertStatus(422);
    }

    #[Test]
    public function sync_rejects_wrong_task_type(): void
    {
        $this->postJson(
            '/api/v1/shortcuts/sync',
            $this->syncPayload(['task_type' => 'delete_shortcut']),
            $this->authHeaders()
        )->assertStatus(422);
    }

    #[Test]
    public function sync_creates_task_and_dispatches_job(): void
    {
        $payload = $this->syncPayload();

        $this->postJson('/api/v1/shortcuts/sync', $payload, $this->authHeaders())
            ->assertStatus(200)
            ->assertJson(['success' => true, 'message' => 'Task received and queued']);

        Queue::assertPushed(SyncShortcutJob::class);

        $this->assertDatabaseHas('controlhub_tasks', [
            'controlhub_task_id' => $payload['task_id'],
            'type' => 'sync_shortcut',
        ]);
    }

    #[Test]
    public function sync_is_idempotent_on_task_id(): void
    {
        $payload = $this->syncPayload();

        $this->postJson('/api/v1/shortcuts/sync', $payload, $this->authHeaders())->assertStatus(200);
        $this->postJson('/api/v1/shortcuts/sync', $payload, $this->authHeaders())
            ->assertStatus(200)
            ->assertJson(['message' => 'Task already received']);

        $this->assertEquals(
            1,
            ControlHubTask::where('controlhub_task_id', $payload['task_id'])->count()
        );
    }

    #[Test]
    public function sync_normalizes_nested_windows_and_linux_blocks_to_flat_columns(): void
    {
        $payload = $this->syncPayload(['payload' => [
            'windows' => ['link' => 'C:\\App\\app.exe', 'args' => '--foo'],
            'linux' => ['link' => '/usr/bin/app', 'startupwmclass' => 'App'],
        ]]);

        $this->postJson('/api/v1/shortcuts/sync', $payload, $this->authHeaders())
            ->assertStatus(200);

        $task = ControlHubTask::where('controlhub_task_id', $payload['task_id'])->first();
        $this->assertSame('C:\\App\\app.exe', $task->payload['windows_link']);
        $this->assertSame('--foo', $task->payload['windows_args']);
        $this->assertSame('/usr/bin/app', $task->payload['linux_link']);
        $this->assertSame('App', $task->payload['linux_startupwmclass']);
        $this->assertArrayNotHasKey('windows', $task->payload);
        $this->assertArrayNotHasKey('linux', $task->payload);
    }

    // ─── /delete ─────────────────────────────────────────────────────────────

    #[Test]
    public function delete_requires_auth(): void
    {
        $this->postJson('/api/v1/shortcuts/delete', $this->deletePayload((string) Str::uuid()))
            ->assertStatus(403);
    }

    #[Test]
    public function delete_returns_404_for_unknown_shortcut(): void
    {
        $this->postJson(
            '/api/v1/shortcuts/delete',
            $this->deletePayload((string) Str::uuid()),
            $this->authHeaders()
        )->assertStatus(404);
    }

    #[Test]
    public function delete_returns_403_for_non_global_shortcut(): void
    {
        $shortcut = Shortcut::create([
            'controlhub_id' => (string) Str::uuid(),
            'key' => 'local-shortcut',
            'name' => 'Local',
            'is_global' => false,
        ]);

        $this->postJson(
            '/api/v1/shortcuts/delete',
            $this->deletePayload($shortcut->controlhub_id),
            $this->authHeaders()
        )->assertStatus(403);

        Queue::assertNotPushed(DeleteShortcutJob::class);
    }

    #[Test]
    public function delete_dispatches_job_for_global_shortcut(): void
    {
        $shortcut = Shortcut::create([
            'controlhub_id' => (string) Str::uuid(),
            'key' => 'global-firefox',
            'name' => 'Firefox',
            'is_global' => true,
        ]);

        $this->postJson(
            '/api/v1/shortcuts/delete',
            $this->deletePayload($shortcut->controlhub_id),
            $this->authHeaders()
        )->assertStatus(200);

        Queue::assertPushed(DeleteShortcutJob::class);
    }

    #[Test]
    public function delete_is_idempotent_on_task_id(): void
    {
        $shortcut = Shortcut::create([
            'controlhub_id' => (string) Str::uuid(),
            'key' => 'global-firefox',
            'name' => 'Firefox',
            'is_global' => true,
        ]);
        $payload = $this->deletePayload($shortcut->controlhub_id);

        $this->postJson('/api/v1/shortcuts/delete', $payload, $this->authHeaders())->assertStatus(200);
        $this->postJson('/api/v1/shortcuts/delete', $payload, $this->authHeaders())
            ->assertStatus(200)
            ->assertJson(['message' => 'Task already received']);

        $this->assertEquals(
            1,
            ControlHubTask::where('controlhub_task_id', $payload['task_id'])->count()
        );
    }
}

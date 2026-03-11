<?php

namespace Tests\Feature\ControlHub;

use Tests\TestCase;
use App\Jobs\CreateShortcutJob;
use App\Jobs\UpdateShortcutJob;
use App\Jobs\DeleteShortcutJob;
use App\Models\ControlHubTask;
use App\Models\Shortcut;
use App\Models\WorkstationGroup;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Str;

/**
 * Test CRUD complet des Shortcuts via l'API ControlHub.
 *
 * Utilise SQLite in-memory (phpunit.xml) sans migrations (tables creees manuellement).
 *
 * Couverture :
 * - Endpoints API : authentification, validation payload, idempotence, controlhub_id
 * - Jobs : execution directe de create/update/delete via Eloquent Shortcut
 * - Cycle de vie complet : create -> update -> delete
 *
 * Regles metier verifiees :
 * - Seuls les raccourcis avec is_global=true peuvent etre modifies/supprimes via l'API
 * - Les raccourcis crees via ControlHub ont automatiquement is_global=true
 * - Le controlhub_id est requis pour create et update
 * - L'update fait un merge partiel (seuls les champs fournis sont modifies)
 */
class ShortcutCrudTest extends TestCase
{
    private string $apiToken;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createTables();

        $this->apiToken = 'test_api_key_for_shortcut_crud_test_1234567890';
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
        if (!Schema::hasTable('shortcuts')) {
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
        }

        if (!Schema::hasTable('controlhub_tasks')) {
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

        if (!Schema::hasTable('workstation_groups')) {
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
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('shortcut_assignables')) {
            Schema::create('shortcut_assignables', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('shortcut_id');
                $table->unsignedBigInteger('assignable_id');
                $table->string('assignable_type');
                $table->timestamps();

                $table->unique(['shortcut_id', 'assignable_id', 'assignable_type'], 'shortcut_assignable_unique');
            });
        }
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    /**
     * Invoque la methode protegee execute() d'un job via Reflection.
     * Permet de tester la logique metier du job sans passer par la queue.
     */
    private function invokeExecute(object $job): array
    {
        $reflection = new \ReflectionMethod($job, 'execute');
        $reflection->setAccessible(true);
        return $reflection->invoke($job);
    }

    private function authHeaders(): array
    {
        return ['Authorization' => 'Bearer ' . $this->apiToken];
    }

    private function createShortcutPayload(string $name, array $overrides = []): array
    {
        return array_merge([
            'task_id' => (string) Str::uuid(),
            'task_name' => "Creation raccourci {$name}",
            'task_type' => 'create_shortcut',
            'payload' => array_merge([
                'controlhub_id' => (string) Str::uuid(),
                'name' => $name,
                'owner' => 'Profs',
                'place' => 'desktop',
                'windows_link' => 'C:\\Program Files\\App\\app.exe',
                'windows_args' => 'https://example.com',
            ], $overrides['payload'] ?? []),
        ], array_diff_key($overrides, ['payload' => true]));
    }

    private function updateShortcutPayload(string $controlhubId, string $name, array $overrides = []): array
    {
        return array_merge([
            'task_id' => (string) Str::uuid(),
            'task_name' => "Mise a jour raccourci {$name}",
            'task_type' => 'update_shortcut',
            'payload' => array_merge([
                'controlhub_id' => $controlhubId,
                'name' => $name,
            ], $overrides['payload'] ?? []),
        ], array_diff_key($overrides, ['payload' => true]));
    }

    private function deleteShortcutPayload(string $name, ?string $controlhubId = null): array
    {
        $payload = ['name' => $name];
        if ($controlhubId) {
            $payload['controlhub_id'] = $controlhubId;
        }

        return [
            'task_id' => (string) Str::uuid(),
            'task_name' => "Suppression raccourci {$name}",
            'task_type' => 'delete_shortcut',
            'payload' => $payload,
        ];
    }

    private function createWorkstationGroupInDb(string $name, array $overrides = []): WorkstationGroup
    {
        return WorkstationGroup::create(array_merge([
            'name' => $name,
            'display_name' => ucfirst($name),
            'is_physical' => true,
            'is_active' => true,
            'managed_by_control_hub' => true,
            'controlhub_id' => (string) Str::uuid(),
        ], $overrides));
    }

    private function createShortcutInDb(string $name, array $overrides = []): Shortcut
    {
        return Shortcut::create(array_merge([
            'controlhub_id' => (string) Str::uuid(),
            'key' => uniqid(),
            'name' => $name,
            'owner' => 'Profs',
            'place' => 'desktop',
            'is_global' => true,
            'windows_link' => 'C:\\Program Files\\App\\app.exe',
            'windows_args' => 'https://example.com',
        ], $overrides));
    }

    // =========================================================================
    // API ENDPOINT TESTS - CREATE
    // =========================================================================

    /** @test */
    public function create_requires_auth(): void
    {
        $response = $this->postJson('/api/v1/shortcuts/create', $this->createShortcutPayload('Test'));
        $response->assertStatus(403);
    }

    /** @test */
    public function create_validates_payload(): void
    {
        $response = $this->postJson('/api/v1/shortcuts/create', [
            'task_id' => (string) Str::uuid(),
            'task_name' => 'Test',
            'task_type' => 'create_shortcut',
            'payload' => [],
        ], $this->authHeaders());

        $response->assertStatus(422);
    }

    /** @test */
    public function create_requires_controlhub_id(): void
    {
        $payload = $this->createShortcutPayload('Test');
        unset($payload['payload']['controlhub_id']);

        $response = $this->postJson('/api/v1/shortcuts/create', $payload, $this->authHeaders());
        $response->assertStatus(422);
    }

    /** @test */
    public function create_dispatches_job(): void
    {
        $payload = $this->createShortcutPayload('Firefox');

        $response = $this->postJson('/api/v1/shortcuts/create', $payload, $this->authHeaders());

        $response->assertStatus(200);
        $response->assertJson(['success' => true, 'message' => 'Task received and queued']);

        Queue::assertPushed(CreateShortcutJob::class);

        $this->assertDatabaseHas('controlhub_tasks', [
            'controlhub_task_id' => $payload['task_id'],
            'type' => 'create_shortcut',
        ]);
    }

    /** @test */
    public function create_is_idempotent(): void
    {
        $payload = $this->createShortcutPayload('Firefox');

        $this->postJson('/api/v1/shortcuts/create', $payload, $this->authHeaders());
        $response = $this->postJson('/api/v1/shortcuts/create', $payload, $this->authHeaders());

        $response->assertStatus(200);
        $response->assertJson(['message' => 'Task already received']);

        // Only one task created
        $this->assertEquals(1, ControlHubTask::where('controlhub_task_id', $payload['task_id'])->count());
    }

    /** @test */
    public function create_rejects_wrong_task_type(): void
    {
        $payload = $this->createShortcutPayload('Firefox', ['task_type' => 'delete_shortcut']);

        $response = $this->postJson('/api/v1/shortcuts/create', $payload, $this->authHeaders());
        $response->assertStatus(422);
    }

    // =========================================================================
    // API ENDPOINT TESTS - UPDATE
    // =========================================================================

    /** @test */
    public function update_requires_auth(): void
    {
        $shortcut = $this->createShortcutInDb('Firefox');
        $payload = $this->updateShortcutPayload($shortcut->controlhub_id, 'Firefox');

        $response = $this->postJson('/api/v1/shortcuts/update', $payload);
        $response->assertStatus(403);
    }

    /** @test */
    public function update_requires_controlhub_id(): void
    {
        $payload = $this->updateShortcutPayload('', 'Firefox');
        $payload['payload']['controlhub_id'] = ''; // empty

        $response = $this->postJson('/api/v1/shortcuts/update', $payload, $this->authHeaders());
        $response->assertStatus(422);
    }

    /** @test */
    public function update_returns_404_for_unknown_controlhub_id(): void
    {
        $payload = $this->updateShortcutPayload((string) Str::uuid(), 'Firefox');

        $response = $this->postJson('/api/v1/shortcuts/update', $payload, $this->authHeaders());
        $response->assertStatus(404);
    }

    /** @test */
    public function update_returns_403_for_non_global_shortcut(): void
    {
        $shortcut = $this->createShortcutInDb('Firefox', ['is_global' => false]);
        $payload = $this->updateShortcutPayload($shortcut->controlhub_id, 'Firefox');

        $response = $this->postJson('/api/v1/shortcuts/update', $payload, $this->authHeaders());
        $response->assertStatus(403);
    }

    /** @test */
    public function update_dispatches_job(): void
    {
        $shortcut = $this->createShortcutInDb('Firefox');
        $payload = $this->updateShortcutPayload($shortcut->controlhub_id, 'Firefox Updated', [
            'payload' => ['owner' => 'Admins'],
        ]);

        $response = $this->postJson('/api/v1/shortcuts/update', $payload, $this->authHeaders());

        $response->assertStatus(200);
        $response->assertJson(['success' => true, 'message' => 'Task received and queued']);

        Queue::assertPushed(UpdateShortcutJob::class);
    }

    /** @test */
    public function update_is_idempotent(): void
    {
        $shortcut = $this->createShortcutInDb('Firefox');
        $payload = $this->updateShortcutPayload($shortcut->controlhub_id, 'Firefox');

        $this->postJson('/api/v1/shortcuts/update', $payload, $this->authHeaders());
        $response = $this->postJson('/api/v1/shortcuts/update', $payload, $this->authHeaders());

        $response->assertStatus(200);
        $response->assertJson(['message' => 'Task already received']);
    }

    // =========================================================================
    // API ENDPOINT TESTS - DELETE
    // =========================================================================

    /** @test */
    public function delete_requires_auth(): void
    {
        $payload = $this->deleteShortcutPayload('Firefox');
        $response = $this->postJson('/api/v1/shortcuts/delete', $payload);
        $response->assertStatus(403);
    }

    /** @test */
    public function delete_returns_404_for_unknown_shortcut(): void
    {
        $payload = $this->deleteShortcutPayload('NonExistent', (string) Str::uuid());

        $response = $this->postJson('/api/v1/shortcuts/delete', $payload, $this->authHeaders());
        $response->assertStatus(404);
    }

    /** @test */
    public function delete_returns_403_for_non_global_shortcut(): void
    {
        $shortcut = $this->createShortcutInDb('Firefox', ['is_global' => false]);
        $payload = $this->deleteShortcutPayload('Firefox', $shortcut->controlhub_id);

        $response = $this->postJson('/api/v1/shortcuts/delete', $payload, $this->authHeaders());
        $response->assertStatus(403);
    }

    /** @test */
    public function delete_dispatches_job(): void
    {
        $shortcut = $this->createShortcutInDb('Firefox');
        $payload = $this->deleteShortcutPayload('Firefox', $shortcut->controlhub_id);

        $response = $this->postJson('/api/v1/shortcuts/delete', $payload, $this->authHeaders());

        $response->assertStatus(200);
        $response->assertJson(['success' => true, 'message' => 'Task received and queued']);

        Queue::assertPushed(DeleteShortcutJob::class);
    }

    /** @test */
    public function delete_is_idempotent(): void
    {
        $shortcut = $this->createShortcutInDb('Firefox');
        $payload = $this->deleteShortcutPayload('Firefox', $shortcut->controlhub_id);

        $this->postJson('/api/v1/shortcuts/delete', $payload, $this->authHeaders());
        $response = $this->postJson('/api/v1/shortcuts/delete', $payload, $this->authHeaders());

        $response->assertStatus(200);
        $response->assertJson(['message' => 'Task already received']);
    }

    /** @test */
    public function delete_finds_by_name_when_no_controlhub_id(): void
    {
        $shortcut = $this->createShortcutInDb('Firefox');
        $payload = $this->deleteShortcutPayload('Firefox'); // no controlhub_id

        $response = $this->postJson('/api/v1/shortcuts/delete', $payload, $this->authHeaders());

        $response->assertStatus(200);
        Queue::assertPushed(DeleteShortcutJob::class);
    }

    // =========================================================================
    // JOB EXECUTION TESTS - CREATE
    // =========================================================================

    /** @test */
    public function job_create_persists_shortcut_in_db(): void
    {
        Queue::fake([]);

        $controlhubId = (string) Str::uuid();
        $task = ControlHubTask::create([
            'controlhub_task_id' => (string) Str::uuid(),
            'name' => 'Create Firefox',
            'type' => 'create_shortcut',
            'payload' => [
                'controlhub_id' => $controlhubId,
                'name' => 'Firefox',
                'owner' => 'Profs',
                'place' => 'desktop',
                'windows_link' => 'C:\\Program Files\\Mozilla\\firefox.exe',
                'windows_args' => 'https://mozilla.org',
            ],
            'status' => ControlHubTask::STATUS_QUEUED,
        ]);

        $result = $this->invokeExecute(new CreateShortcutJob($task));

        $shortcut = Shortcut::where('controlhub_id', $controlhubId)->first();
        $this->assertNotNull($shortcut);
        $this->assertEquals('Firefox', $shortcut->name);
        $this->assertEquals('Profs', $shortcut->owner);
        $this->assertEquals('desktop', $shortcut->place);
        $this->assertTrue($shortcut->is_global);
        $this->assertEquals('C:\\Program Files\\Mozilla\\firefox.exe', $shortcut->windows_link);
        $this->assertEquals('https://mozilla.org', $shortcut->windows_args);
        $this->assertEquals($controlhubId, $shortcut->controlhub_id);
        $this->assertEquals($controlhubId, $result['controlhub_id']);
    }

    /** @test */
    public function job_create_is_idempotent_on_controlhub_id(): void
    {
        Queue::fake([]);

        $controlhubId = (string) Str::uuid();

        // Pre-create the shortcut
        $this->createShortcutInDb('Firefox', ['controlhub_id' => $controlhubId]);

        $task = ControlHubTask::create([
            'controlhub_task_id' => (string) Str::uuid(),
            'name' => 'Create Firefox again',
            'type' => 'create_shortcut',
            'payload' => [
                'controlhub_id' => $controlhubId,
                'name' => 'Firefox',
            ],
            'status' => ControlHubTask::STATUS_QUEUED,
        ]);

        $result = $this->invokeExecute(new CreateShortcutJob($task));

        // Should still be only 1 shortcut with this controlhub_id
        $this->assertEquals(1, Shortcut::where('controlhub_id', $controlhubId)->count());
        $this->assertStringContainsString('idempotence', $result['message']);
    }

    /** @test */
    public function job_create_fails_without_name(): void
    {
        Queue::fake([]);

        $task = ControlHubTask::create([
            'controlhub_task_id' => (string) Str::uuid(),
            'name' => 'Create no name',
            'type' => 'create_shortcut',
            'payload' => [
                'controlhub_id' => (string) Str::uuid(),
            ],
            'status' => ControlHubTask::STATUS_QUEUED,
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->invokeExecute(new CreateShortcutJob($task));
    }

    // =========================================================================
    // JOB EXECUTION TESTS - UPDATE
    // =========================================================================

    /** @test */
    public function job_update_modifies_shortcut_in_db(): void
    {
        Queue::fake([]);

        $shortcut = $this->createShortcutInDb('Firefox', [
            'owner' => 'Profs',
            'windows_args' => 'https://mozilla.org',
        ]);

        $task = ControlHubTask::create([
            'controlhub_task_id' => (string) Str::uuid(),
            'name' => 'Update Firefox',
            'type' => 'update_shortcut',
            'payload' => [
                'controlhub_id' => $shortcut->controlhub_id,
                'name' => 'Firefox Updated',
                'owner' => 'Admins',
            ],
            'status' => ControlHubTask::STATUS_QUEUED,
        ]);

        $result = $this->invokeExecute(new UpdateShortcutJob($task));

        $shortcut->refresh();
        $this->assertEquals('Firefox Updated', $shortcut->name);
        $this->assertEquals('Admins', $shortcut->owner);
        // windows_args should remain unchanged (partial merge)
        $this->assertEquals('https://mozilla.org', $shortcut->windows_args);
        $this->assertEquals($shortcut->controlhub_id, $result['controlhub_id']);
    }

    /** @test */
    public function job_update_partial_merge_only_updates_provided_fields(): void
    {
        Queue::fake([]);

        $shortcut = $this->createShortcutInDb('Chrome', [
            'owner' => 'Eleves',
            'place' => 'taskbar',
            'windows_link' => 'C:\\chrome.exe',
            'windows_args' => '--incognito',
            'linux_link' => '/usr/bin/chromium',
        ]);

        $task = ControlHubTask::create([
            'controlhub_task_id' => (string) Str::uuid(),
            'name' => 'Update Chrome place only',
            'type' => 'update_shortcut',
            'payload' => [
                'controlhub_id' => $shortcut->controlhub_id,
                'name' => 'Chrome',
                'place' => 'desktop',
            ],
            'status' => ControlHubTask::STATUS_QUEUED,
        ]);

        $this->invokeExecute(new UpdateShortcutJob($task));

        $shortcut->refresh();
        $this->assertEquals('desktop', $shortcut->place);
        // All other fields unchanged
        $this->assertEquals('Eleves', $shortcut->owner);
        $this->assertEquals('C:\\chrome.exe', $shortcut->windows_link);
        $this->assertEquals('--incognito', $shortcut->windows_args);
        $this->assertEquals('/usr/bin/chromium', $shortcut->linux_link);
    }

    /** @test */
    public function job_update_fails_for_unknown_controlhub_id(): void
    {
        Queue::fake([]);

        $task = ControlHubTask::create([
            'controlhub_task_id' => (string) Str::uuid(),
            'name' => 'Update unknown',
            'type' => 'update_shortcut',
            'payload' => [
                'controlhub_id' => (string) Str::uuid(),
                'name' => 'Unknown',
            ],
            'status' => ControlHubTask::STATUS_QUEUED,
        ]);

        $this->expectException(\RuntimeException::class);
        $this->invokeExecute(new UpdateShortcutJob($task));
    }

    /** @test */
    public function job_update_fails_for_non_global_shortcut(): void
    {
        Queue::fake([]);

        $shortcut = $this->createShortcutInDb('Local App', ['is_global' => false]);

        $task = ControlHubTask::create([
            'controlhub_task_id' => (string) Str::uuid(),
            'name' => 'Update local',
            'type' => 'update_shortcut',
            'payload' => [
                'controlhub_id' => $shortcut->controlhub_id,
                'name' => 'Local App Updated',
            ],
            'status' => ControlHubTask::STATUS_QUEUED,
        ]);

        $this->expectException(\RuntimeException::class);
        $this->invokeExecute(new UpdateShortcutJob($task));
    }

    /** @test */
    public function job_update_non_global_does_not_change_name(): void
    {
        Queue::fake([]);

        $shortcut = $this->createShortcutInDb('Local App', ['is_global' => false]);

        $task = ControlHubTask::create([
            'controlhub_task_id' => (string) Str::uuid(),
            'name' => 'Update local',
            'type' => 'update_shortcut',
            'payload' => [
                'controlhub_id' => $shortcut->controlhub_id,
                'name' => 'Local App Updated',
            ],
            'status' => ControlHubTask::STATUS_QUEUED,
        ]);

        try {
            $this->invokeExecute(new UpdateShortcutJob($task));
        } catch (\RuntimeException $e) {
            // expected
        }

        $shortcut->refresh();
        $this->assertEquals('Local App', $shortcut->name);
    }

    // =========================================================================
    // JOB EXECUTION TESTS - DELETE
    // =========================================================================

    /** @test */
    public function job_delete_removes_shortcut_from_db(): void
    {
        Queue::fake([]);

        $shortcut = $this->createShortcutInDb('Firefox');
        $controlhubId = $shortcut->controlhub_id;

        $task = ControlHubTask::create([
            'controlhub_task_id' => (string) Str::uuid(),
            'name' => 'Delete Firefox',
            'type' => 'delete_shortcut',
            'payload' => [
                'name' => 'Firefox',
                'controlhub_id' => $controlhubId,
            ],
            'status' => ControlHubTask::STATUS_QUEUED,
        ]);

        $result = $this->invokeExecute(new DeleteShortcutJob($task));

        $this->assertNull(Shortcut::where('controlhub_id', $controlhubId)->first());
        $this->assertTrue($result['deleted']);
    }

    /** @test */
    public function job_delete_by_name_when_no_controlhub_id(): void
    {
        Queue::fake([]);

        $shortcut = $this->createShortcutInDb('Firefox');

        $task = ControlHubTask::create([
            'controlhub_task_id' => (string) Str::uuid(),
            'name' => 'Delete Firefox by name',
            'type' => 'delete_shortcut',
            'payload' => [
                'name' => 'Firefox',
            ],
            'status' => ControlHubTask::STATUS_QUEUED,
        ]);

        $result = $this->invokeExecute(new DeleteShortcutJob($task));

        $this->assertEquals(0, Shortcut::where('name', 'Firefox')->count());
        $this->assertTrue($result['deleted']);
    }

    /** @test */
    public function job_delete_fails_for_non_global_shortcut(): void
    {
        Queue::fake([]);

        $shortcut = $this->createShortcutInDb('Local App', ['is_global' => false]);

        $task = ControlHubTask::create([
            'controlhub_task_id' => (string) Str::uuid(),
            'name' => 'Delete local',
            'type' => 'delete_shortcut',
            'payload' => [
                'name' => 'Local App',
                'controlhub_id' => $shortcut->controlhub_id,
            ],
            'status' => ControlHubTask::STATUS_QUEUED,
        ]);

        $this->expectException(\RuntimeException::class);
        $this->invokeExecute(new DeleteShortcutJob($task));
    }

    /** @test */
    public function job_delete_non_global_does_not_remove(): void
    {
        Queue::fake([]);

        $shortcut = $this->createShortcutInDb('Local App', ['is_global' => false]);

        $task = ControlHubTask::create([
            'controlhub_task_id' => (string) Str::uuid(),
            'name' => 'Delete local',
            'type' => 'delete_shortcut',
            'payload' => [
                'name' => 'Local App',
                'controlhub_id' => $shortcut->controlhub_id,
            ],
            'status' => ControlHubTask::STATUS_QUEUED,
        ]);

        try {
            $this->invokeExecute(new DeleteShortcutJob($task));
        } catch (\RuntimeException $e) {
            // expected
        }

        // Shortcut should still exist
        $this->assertNotNull(Shortcut::find($shortcut->id));
    }

    // =========================================================================
    // NESTED PAYLOAD FORMAT TESTS (ControlHub format)
    // =========================================================================

    /** @test */
    public function create_accepts_nested_payload_format(): void
    {
        $payload = [
            'task_id' => (string) Str::uuid(),
            'task_name' => 'Creation raccourci Firefox',
            'task_type' => 'create_shortcut',
            'payload' => [
                'controlhub_id' => (string) Str::uuid(),
                'name' => 'Firefox',
                'owner' => 'Profs',
                'place' => 'desktop',
                'windows' => [
                    'link' => 'firefox.exe',
                    'args' => 'https://example.com',
                    'path' => 'C:\\Program Files\\Mozilla Firefox',
                ],
                'linux' => [
                    'link' => 'firefox',
                ],
            ],
        ];

        $response = $this->postJson('/api/v1/shortcuts/create', $payload, $this->authHeaders());

        $response->assertStatus(200);
        $response->assertJson(['success' => true]);
        Queue::assertPushed(CreateShortcutJob::class);

        // Verify payload was normalized to flat format in the task
        $task = ControlHubTask::where('controlhub_task_id', $payload['task_id'])->first();
        $this->assertNotNull($task);
        $this->assertEquals('firefox.exe', $task->payload['windows_link']);
        $this->assertEquals('https://example.com', $task->payload['windows_args']);
        $this->assertEquals('C:\\Program Files\\Mozilla Firefox', $task->payload['windows_path']);
        $this->assertEquals('firefox', $task->payload['linux_link']);
        // Nested keys should be removed
        $this->assertArrayNotHasKey('windows', $task->payload);
        $this->assertArrayNotHasKey('linux', $task->payload);
    }

    /** @test */
    public function create_nested_format_with_icons_passes_validation(): void
    {
        $payload = [
            'task_id' => (string) Str::uuid(),
            'task_name' => 'Creation raccourci Firefox',
            'task_type' => 'create_shortcut',
            'payload' => [
                'controlhub_id' => (string) Str::uuid(),
                'name' => 'Firefox',
                'owner' => 'Profs',
                'place' => 'desktop',
                'windows' => [
                    'link' => 'firefox.exe',
                    'icon' => [
                        'data' => base64_encode('fake-png-data'),
                        'mime' => 'image/png',
                        'filename' => 'firefox.png',
                    ],
                ],
                'linux' => [
                    'link' => 'firefox',
                    'icon' => [
                        'data' => base64_encode('fake-png-data-linux'),
                        'mime' => 'image/png',
                        'filename' => 'firefox.png',
                    ],
                ],
            ],
        ];

        $response = $this->postJson('/api/v1/shortcuts/create', $payload, $this->authHeaders());

        $response->assertStatus(200);

        // Verify icon data was normalized
        $task = ControlHubTask::where('controlhub_task_id', $payload['task_id'])->first();
        $this->assertNotNull($task);
        $this->assertIsArray($task->payload['windows_icon']);
        $this->assertEquals('image/png', $task->payload['windows_icon']['mime']);
        $this->assertIsArray($task->payload['linux_icon']);
        $this->assertEquals('image/png', $task->payload['linux_icon']['mime']);
    }

    /** @test */
    public function update_accepts_nested_payload_format(): void
    {
        $shortcut = $this->createShortcutInDb('Firefox');

        $payload = [
            'task_id' => (string) Str::uuid(),
            'task_name' => 'Mise a jour raccourci Firefox',
            'task_type' => 'update_shortcut',
            'payload' => [
                'controlhub_id' => $shortcut->controlhub_id,
                'name' => 'Firefox Updated',
                'windows' => [
                    'link' => 'firefox-new.exe',
                    'args' => '--safe-mode',
                ],
                'linux' => [
                    'link' => '/usr/bin/firefox-esr',
                    'startupwmclass' => 'Firefox-esr',
                ],
            ],
        ];

        $response = $this->postJson('/api/v1/shortcuts/update', $payload, $this->authHeaders());

        $response->assertStatus(200);

        // Verify normalization
        $task = ControlHubTask::where('controlhub_task_id', $payload['task_id'])->first();
        $this->assertEquals('firefox-new.exe', $task->payload['windows_link']);
        $this->assertEquals('--safe-mode', $task->payload['windows_args']);
        $this->assertEquals('/usr/bin/firefox-esr', $task->payload['linux_link']);
        $this->assertEquals('Firefox-esr', $task->payload['linux_startupwmclass']);
        $this->assertArrayNotHasKey('windows', $task->payload);
        $this->assertArrayNotHasKey('linux', $task->payload);
    }

    /** @test */
    public function create_flat_format_still_works(): void
    {
        $payload = $this->createShortcutPayload('Firefox');

        $response = $this->postJson('/api/v1/shortcuts/create', $payload, $this->authHeaders());

        $response->assertStatus(200);

        $task = ControlHubTask::where('controlhub_task_id', $payload['task_id'])->first();
        $this->assertEquals('C:\\Program Files\\App\\app.exe', $task->payload['windows_link']);
    }

    /** @test */
    public function normalize_does_not_overwrite_flat_with_nested(): void
    {
        // If both flat and nested are provided, flat takes precedence
        $payload = [
            'task_id' => (string) Str::uuid(),
            'task_name' => 'Creation raccourci Firefox',
            'task_type' => 'create_shortcut',
            'payload' => [
                'controlhub_id' => (string) Str::uuid(),
                'name' => 'Firefox',
                'windows_link' => 'flat-value.exe',
                'windows' => [
                    'link' => 'nested-value.exe',
                ],
            ],
        ];

        $response = $this->postJson('/api/v1/shortcuts/create', $payload, $this->authHeaders());
        $response->assertStatus(200);

        $task = ControlHubTask::where('controlhub_task_id', $payload['task_id'])->first();
        // Flat value should be preserved, not overwritten by nested
        $this->assertEquals('flat-value.exe', $task->payload['windows_link']);
    }

    // =========================================================================
    // JOB EXECUTION TESTS - NESTED FORMAT WITH ICONS
    // =========================================================================

    /** @test */
    public function job_create_with_nested_format_persists_correctly(): void
    {
        Queue::fake([]);

        $controlhubId = (string) Str::uuid();
        $task = ControlHubTask::create([
            'controlhub_task_id' => (string) Str::uuid(),
            'name' => 'Create Firefox nested',
            'type' => 'create_shortcut',
            'payload' => [
                'controlhub_id' => $controlhubId,
                'name' => 'Firefox',
                'owner' => 'Profs',
                'place' => 'desktop',
                // Already normalized (controller does this before storing)
                'windows_link' => 'firefox.exe',
                'windows_args' => 'https://example.com',
                'windows_path' => 'C:\\Program Files\\Mozilla Firefox',
                'linux_link' => 'firefox',
                'linux_startupwmclass' => 'Firefox',
            ],
            'status' => ControlHubTask::STATUS_QUEUED,
        ]);

        $this->invokeExecute(new CreateShortcutJob($task));

        $shortcut = Shortcut::where('controlhub_id', $controlhubId)->first();
        $this->assertNotNull($shortcut);
        $this->assertEquals('firefox.exe', $shortcut->windows_link);
        $this->assertEquals('https://example.com', $shortcut->windows_args);
        $this->assertEquals('C:\\Program Files\\Mozilla Firefox', $shortcut->windows_path);
        $this->assertEquals('firefox', $shortcut->linux_link);
        $this->assertEquals('Firefox', $shortcut->linux_startupwmclass);
    }

    // =========================================================================
    // FULL LIFECYCLE TEST
    // =========================================================================

    /** @test */
    public function full_crud_lifecycle(): void
    {
        Queue::fake([]);

        $controlhubId = (string) Str::uuid();

        // 1. CREATE
        $createTask = ControlHubTask::create([
            'controlhub_task_id' => (string) Str::uuid(),
            'name' => 'Create Firefox',
            'type' => 'create_shortcut',
            'payload' => [
                'controlhub_id' => $controlhubId,
                'name' => 'Firefox',
                'owner' => 'Profs',
                'place' => 'desktop',
                'windows_link' => 'C:\\firefox.exe',
                'windows_args' => 'https://mozilla.org',
                'linux_link' => '/usr/bin/firefox',
            ],
            'status' => ControlHubTask::STATUS_QUEUED,
        ]);

        $this->invokeExecute(new CreateShortcutJob($createTask));

        $shortcut = Shortcut::where('controlhub_id', $controlhubId)->first();
        $this->assertNotNull($shortcut);
        $this->assertEquals('Firefox', $shortcut->name);
        $this->assertEquals('Profs', $shortcut->owner);
        $this->assertTrue($shortcut->is_global);

        // 2. UPDATE - rename + change owner
        $updateTask = ControlHubTask::create([
            'controlhub_task_id' => (string) Str::uuid(),
            'name' => 'Update Firefox',
            'type' => 'update_shortcut',
            'payload' => [
                'controlhub_id' => $controlhubId,
                'name' => 'Firefox ESR',
                'owner' => 'Admins,Profs',
            ],
            'status' => ControlHubTask::STATUS_QUEUED,
        ]);

        $this->invokeExecute(new UpdateShortcutJob($updateTask));

        $shortcut->refresh();
        $this->assertEquals('Firefox ESR', $shortcut->name);
        $this->assertEquals('Admins,Profs', $shortcut->owner);
        // Unchanged fields
        $this->assertEquals('desktop', $shortcut->place);
        $this->assertEquals('C:\\firefox.exe', $shortcut->windows_link);
        $this->assertEquals('/usr/bin/firefox', $shortcut->linux_link);

        // 3. DELETE
        $deleteTask = ControlHubTask::create([
            'controlhub_task_id' => (string) Str::uuid(),
            'name' => 'Delete Firefox',
            'type' => 'delete_shortcut',
            'payload' => [
                'name' => 'Firefox ESR',
                'controlhub_id' => $controlhubId,
            ],
            'status' => ControlHubTask::STATUS_QUEUED,
        ]);

        $this->invokeExecute(new DeleteShortcutJob($deleteTask));

        $this->assertNull(Shortcut::where('controlhub_id', $controlhubId)->first());
        $this->assertEquals(0, Shortcut::count());
    }

    // =========================================================================
    // API ENDPOINT TESTS - WORKSTATION GROUPS ASSOCIATION
    // =========================================================================

    /** @test */
    public function create_with_valid_workstation_groups_dispatches_job(): void
    {
        $group1 = $this->createWorkstationGroupInDb('salle-info-101');
        $group2 = $this->createWorkstationGroupInDb('salle-info-102');

        $payload = $this->createShortcutPayload('Firefox', [
            'payload' => [
                'workstation_groups' => [
                    ['controlhub_id' => $group1->controlhub_id],
                    ['controlhub_id' => $group2->controlhub_id],
                ],
            ],
        ]);

        $response = $this->postJson('/api/v1/shortcuts/create', $payload, $this->authHeaders());

        $response->assertStatus(200);
        $response->assertJson(['success' => true]);
        Queue::assertPushed(CreateShortcutJob::class);

        // Verify resolved IDs are stored in the task payload
        $task = ControlHubTask::where('controlhub_task_id', $payload['task_id'])->first();
        $this->assertNotNull($task);
        $this->assertArrayHasKey('resolved_workstation_group_ids', $task->payload);
        $this->assertCount(2, $task->payload['resolved_workstation_group_ids']);
        $this->assertContains($group1->id, $task->payload['resolved_workstation_group_ids']);
        $this->assertContains($group2->id, $task->payload['resolved_workstation_group_ids']);
    }

    /** @test */
    public function create_rejects_unknown_workstation_group(): void
    {
        $group1 = $this->createWorkstationGroupInDb('salle-info-101');
        $fakeControlhubId = (string) Str::uuid();

        $payload = $this->createShortcutPayload('Firefox', [
            'payload' => [
                'workstation_groups' => [
                    ['controlhub_id' => $group1->controlhub_id],
                    ['controlhub_id' => $fakeControlhubId],
                ],
            ],
        ]);

        $response = $this->postJson('/api/v1/shortcuts/create', $payload, $this->authHeaders());

        $response->assertStatus(422);
        $response->assertJson([
            'success' => false,
            'missing_groups' => [$fakeControlhubId],
        ]);

        // No task should be created, no shortcut job dispatched
        $this->assertEquals(0, ControlHubTask::count());
        Queue::assertNotPushed(CreateShortcutJob::class);
    }

    /** @test */
    public function create_without_workstation_groups_still_works(): void
    {
        $payload = $this->createShortcutPayload('Firefox');

        $response = $this->postJson('/api/v1/shortcuts/create', $payload, $this->authHeaders());

        $response->assertStatus(200);

        $task = ControlHubTask::where('controlhub_task_id', $payload['task_id'])->first();
        $this->assertArrayNotHasKey('resolved_workstation_group_ids', $task->payload);
    }

    /** @test */
    public function create_rejects_workstation_group_without_controlhub_id(): void
    {
        $payload = $this->createShortcutPayload('Firefox', [
            'payload' => [
                'workstation_groups' => [
                    ['name' => 'salle-info-101'],
                ],
            ],
        ]);

        $response = $this->postJson('/api/v1/shortcuts/create', $payload, $this->authHeaders());

        $response->assertStatus(422);
    }

    /** @test */
    public function update_with_valid_workstation_groups_dispatches_job(): void
    {
        $shortcut = $this->createShortcutInDb('Firefox');
        $group1 = $this->createWorkstationGroupInDb('salle-info-201');

        $payload = $this->updateShortcutPayload($shortcut->controlhub_id, 'Firefox', [
            'payload' => [
                'workstation_groups' => [
                    ['controlhub_id' => $group1->controlhub_id],
                ],
            ],
        ]);

        $response = $this->postJson('/api/v1/shortcuts/update', $payload, $this->authHeaders());

        $response->assertStatus(200);
        $response->assertJson(['success' => true]);
        Queue::assertPushed(UpdateShortcutJob::class);

        $task = ControlHubTask::where('controlhub_task_id', $payload['task_id'])->first();
        $this->assertArrayHasKey('resolved_workstation_group_ids', $task->payload);
        $this->assertCount(1, $task->payload['resolved_workstation_group_ids']);
        $this->assertContains($group1->id, $task->payload['resolved_workstation_group_ids']);
    }

    /** @test */
    public function update_rejects_unknown_workstation_group(): void
    {
        $shortcut = $this->createShortcutInDb('Firefox');
        $fakeControlhubId = (string) Str::uuid();

        $payload = $this->updateShortcutPayload($shortcut->controlhub_id, 'Firefox', [
            'payload' => [
                'workstation_groups' => [
                    ['controlhub_id' => $fakeControlhubId],
                ],
            ],
        ]);

        $response = $this->postJson('/api/v1/shortcuts/update', $payload, $this->authHeaders());

        $response->assertStatus(422);
        $response->assertJson([
            'success' => false,
            'missing_groups' => [$fakeControlhubId],
        ]);

        Queue::assertNothingPushed();
    }

    // =========================================================================
    // JOB EXECUTION TESTS - WORKSTATION GROUPS ASSOCIATION
    // =========================================================================

    /** @test */
    public function job_create_syncs_workstation_groups(): void
    {
        Queue::fake([]);

        $group1 = $this->createWorkstationGroupInDb('salle-info-301');
        $group2 = $this->createWorkstationGroupInDb('salle-info-302');
        $controlhubId = (string) Str::uuid();

        $task = ControlHubTask::create([
            'controlhub_task_id' => (string) Str::uuid(),
            'name' => 'Create Firefox with groups',
            'type' => 'create_shortcut',
            'payload' => [
                'controlhub_id' => $controlhubId,
                'name' => 'Firefox',
                'owner' => 'Profs',
                'place' => 'desktop',
                'windows_link' => 'C:\\firefox.exe',
                'resolved_workstation_group_ids' => [$group1->id, $group2->id],
            ],
            'status' => ControlHubTask::STATUS_QUEUED,
        ]);

        $result = $this->invokeExecute(new CreateShortcutJob($task));

        $shortcut = Shortcut::where('controlhub_id', $controlhubId)->first();
        $this->assertNotNull($shortcut);
        $this->assertEquals(2, $shortcut->workstationGroups()->count());
        $this->assertTrue($shortcut->workstationGroups->contains('id', $group1->id));
        $this->assertTrue($shortcut->workstationGroups->contains('id', $group2->id));
        $this->assertEquals(2, $result['workstation_groups_count']);
    }

    /** @test */
    public function job_create_without_groups_has_no_associations(): void
    {
        Queue::fake([]);

        $controlhubId = (string) Str::uuid();

        $task = ControlHubTask::create([
            'controlhub_task_id' => (string) Str::uuid(),
            'name' => 'Create Firefox no groups',
            'type' => 'create_shortcut',
            'payload' => [
                'controlhub_id' => $controlhubId,
                'name' => 'Firefox',
                'owner' => 'Profs',
                'place' => 'desktop',
                'windows_link' => 'C:\\firefox.exe',
            ],
            'status' => ControlHubTask::STATUS_QUEUED,
        ]);

        $this->invokeExecute(new CreateShortcutJob($task));

        $shortcut = Shortcut::where('controlhub_id', $controlhubId)->first();
        $this->assertNotNull($shortcut);
        $this->assertEquals(0, $shortcut->workstationGroups()->count());
    }

    /** @test */
    public function job_update_syncs_workstation_groups(): void
    {
        Queue::fake([]);

        $group1 = $this->createWorkstationGroupInDb('salle-info-401');
        $group2 = $this->createWorkstationGroupInDb('salle-info-402');
        $shortcut = $this->createShortcutInDb('Firefox');

        // Pre-attach group1
        $shortcut->workstationGroups()->attach($group1->id);
        $this->assertEquals(1, $shortcut->workstationGroups()->count());

        // Update: replace group1 with group2
        $task = ControlHubTask::create([
            'controlhub_task_id' => (string) Str::uuid(),
            'name' => 'Update Firefox groups',
            'type' => 'update_shortcut',
            'payload' => [
                'controlhub_id' => $shortcut->controlhub_id,
                'name' => 'Firefox',
                'resolved_workstation_group_ids' => [$group2->id],
            ],
            'status' => ControlHubTask::STATUS_QUEUED,
        ]);

        $result = $this->invokeExecute(new UpdateShortcutJob($task));

        $shortcut->refresh();
        $this->assertEquals(1, $shortcut->workstationGroups()->count());
        $this->assertTrue($shortcut->workstationGroups->contains('id', $group2->id));
        $this->assertFalse($shortcut->workstationGroups->contains('id', $group1->id));
        $this->assertEquals(1, $result['workstation_groups_count']);
    }

    /** @test */
    public function job_update_with_empty_groups_detaches_all(): void
    {
        Queue::fake([]);

        $group1 = $this->createWorkstationGroupInDb('salle-info-501');
        $shortcut = $this->createShortcutInDb('Firefox');

        // Pre-attach group1
        $shortcut->workstationGroups()->attach($group1->id);
        $this->assertEquals(1, $shortcut->workstationGroups()->count());

        // Update: send empty array to detach all
        $task = ControlHubTask::create([
            'controlhub_task_id' => (string) Str::uuid(),
            'name' => 'Update Firefox remove groups',
            'type' => 'update_shortcut',
            'payload' => [
                'controlhub_id' => $shortcut->controlhub_id,
                'name' => 'Firefox',
                'resolved_workstation_group_ids' => [],
            ],
            'status' => ControlHubTask::STATUS_QUEUED,
        ]);

        $result = $this->invokeExecute(new UpdateShortcutJob($task));

        $shortcut->refresh();
        $this->assertEquals(0, $shortcut->workstationGroups()->count());
        $this->assertEquals(0, $result['workstation_groups_count']);
    }

    /** @test */
    public function job_update_without_groups_key_preserves_existing(): void
    {
        Queue::fake([]);

        $group1 = $this->createWorkstationGroupInDb('salle-info-601');
        $shortcut = $this->createShortcutInDb('Firefox');

        // Pre-attach group1
        $shortcut->workstationGroups()->attach($group1->id);
        $this->assertEquals(1, $shortcut->workstationGroups()->count());

        // Update: no resolved_workstation_group_ids key at all
        $task = ControlHubTask::create([
            'controlhub_task_id' => (string) Str::uuid(),
            'name' => 'Update Firefox name only',
            'type' => 'update_shortcut',
            'payload' => [
                'controlhub_id' => $shortcut->controlhub_id,
                'name' => 'Firefox Updated',
            ],
            'status' => ControlHubTask::STATUS_QUEUED,
        ]);

        $this->invokeExecute(new UpdateShortcutJob($task));

        $shortcut->refresh();
        $this->assertEquals('Firefox Updated', $shortcut->name);
        // Groups should be preserved
        $this->assertEquals(1, $shortcut->workstationGroups()->count());
        $this->assertTrue($shortcut->workstationGroups->contains('id', $group1->id));
    }

    // =========================================================================
    // FULL LIFECYCLE WITH WORKSTATION GROUPS
    // =========================================================================

    /** @test */
    public function full_lifecycle_with_workstation_groups(): void
    {
        Queue::fake([]);

        $group1 = $this->createWorkstationGroupInDb('salle-info-701');
        $group2 = $this->createWorkstationGroupInDb('salle-info-702');
        $controlhubId = (string) Str::uuid();

        // 1. CREATE with 2 groups
        $createTask = ControlHubTask::create([
            'controlhub_task_id' => (string) Str::uuid(),
            'name' => 'Create Firefox',
            'type' => 'create_shortcut',
            'payload' => [
                'controlhub_id' => $controlhubId,
                'name' => 'Firefox',
                'owner' => 'Profs',
                'place' => 'desktop',
                'windows_link' => 'C:\\firefox.exe',
                'resolved_workstation_group_ids' => [$group1->id, $group2->id],
            ],
            'status' => ControlHubTask::STATUS_QUEUED,
        ]);

        $this->invokeExecute(new CreateShortcutJob($createTask));

        $shortcut = Shortcut::where('controlhub_id', $controlhubId)->first();
        $this->assertNotNull($shortcut);
        $this->assertEquals(2, $shortcut->workstationGroups()->count());

        // 2. UPDATE: reduce to 1 group
        $updateTask = ControlHubTask::create([
            'controlhub_task_id' => (string) Str::uuid(),
            'name' => 'Update Firefox groups',
            'type' => 'update_shortcut',
            'payload' => [
                'controlhub_id' => $controlhubId,
                'name' => 'Firefox ESR',
                'resolved_workstation_group_ids' => [$group1->id],
            ],
            'status' => ControlHubTask::STATUS_QUEUED,
        ]);

        $this->invokeExecute(new UpdateShortcutJob($updateTask));

        $shortcut->refresh();
        $this->assertEquals('Firefox ESR', $shortcut->name);
        $this->assertEquals(1, $shortcut->workstationGroups()->count());
        $this->assertTrue($shortcut->workstationGroups->contains('id', $group1->id));

        // 3. DELETE: groups should be cleaned up
        $deleteTask = ControlHubTask::create([
            'controlhub_task_id' => (string) Str::uuid(),
            'name' => 'Delete Firefox',
            'type' => 'delete_shortcut',
            'payload' => [
                'name' => 'Firefox ESR',
                'controlhub_id' => $controlhubId,
            ],
            'status' => ControlHubTask::STATUS_QUEUED,
        ]);

        $this->invokeExecute(new DeleteShortcutJob($deleteTask));

        $this->assertNull(Shortcut::where('controlhub_id', $controlhubId)->first());
        $this->assertEquals(0, \DB::table('shortcut_assignables')->where('shortcut_id', $shortcut->id)->count());
    }
}

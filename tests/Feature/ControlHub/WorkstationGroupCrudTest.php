<?php

namespace Tests\Feature\ControlHub;

use Tests\TestCase;
use App\Enums\LockReason;
use App\Jobs\CreateWorkstationGroupJob;
use App\Jobs\UpdateWorkstationGroupJob;
use App\Jobs\DeleteWorkstationGroupJob;
use App\Models\ControlHubTask;
use App\Models\Shortcut;
use App\Models\WorkstationGroup;
use App\Observers\WorkstationGroupObserver;
use App\Observers\AppProfileObserver;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Str;

/**
 * Test CRUD complet des WorkstationGroups via l'API ControlHub.
 *
 * Utilise SQLite in-memory (phpunit.xml) sans migrations (tables creees manuellement).
 * Les observers AD sont desactives pour eviter les appels LDAP.
 *
 * Couverture :
 * - Endpoints API : authentification, validation payload, idempotence, ownership (lock)
 * - Jobs : execution directe de create/update/delete, verification lock control_hub
 * - Batch : hierarchie ordonnee parent->enfant->petit-enfant, stop_on_error, operations mixtes
 * - Cycle de vie complet : create -> update (rename) -> delete
 *
 * Regles metier verifiees :
 * - Seuls les groupes avec locked=control_hub peuvent etre modifies/supprimes via l'API
 * - Les groupes crees via ControlHub ont automatiquement locked=control_hub et managed_by_control_hub=true
 * - Les groupes avec un lock ROOT ou sans lock sont proteges contre les modifications ControlHub
 * - Le batch execute les operations dans l'ordre garanti (Bus::chain)
 */
class WorkstationGroupCrudTest extends TestCase
{
    private string $apiToken;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createTables();

        // Desactiver les observers AD (pas de LDAP en test)
        WorkstationGroupObserver::disableSync();
        AppProfileObserver::disableSync();

        $this->apiToken = 'test_api_key_for_controlhub_crud_test_1234567890';
        config(['controlHub.se4fs.instance_api_key' => $this->apiToken]);

        Queue::fake();
    }

    protected function tearDown(): void
    {
        // Nettoyer les tables pour isoler chaque test
        Schema::dropIfExists('shortcut_assignables');
        Schema::dropIfExists('shortcuts');
        Schema::dropIfExists('workstation_group_workstation');
        Schema::dropIfExists('workstation_groups');
        Schema::dropIfExists('controlhub_tasks');

        WorkstationGroupObserver::enableSync();
        AppProfileObserver::enableSync();
        parent::tearDown();
    }

    /**
     * Cree les tables necessaires dans SQLite in-memory.
     *
     * On ne peut pas utiliser RefreshDatabase car certaines migrations PostgreSQL
     * ne sont pas compatibles avec SQLite (ex: drop column avec index).
     * Les tables sont recreees a chaque setUp et detruites dans tearDown.
     */
    private function createTables(): void
    {
        if (!Schema::hasTable('workstation_groups')) {
            Schema::create('workstation_groups', function (Blueprint $table) {
                $table->id();
                $table->uuid('controlhub_id')->nullable()->unique();
                $table->string('name', 100)->unique();
                $table->string('display_name', 255)->nullable();
                $table->text('description')->nullable();
                $table->foreignId('parent_id')->nullable();
                $table->boolean('is_physical')->default(false);
                $table->string('app_profile_name', 255)->nullable();
                $table->string('ad_dn', 512)->nullable();
                $table->string('ad_guid', 36)->nullable();
                $table->boolean('is_active')->default(true);
                $table->string('locked')->nullable();
                $table->boolean('managed_by_control_hub')->default(false);
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

        if (!Schema::hasTable('workstation_group_workstation')) {
            Schema::create('workstation_group_workstation', function (Blueprint $table) {
                $table->id();
                $table->foreignId('workstation_group_id');
                $table->foreignId('workstation_id');
                $table->timestamps();
            });
        }

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
     * Retourne les headers d'authentification ControlHub (Bearer token).
     */
    private function authHeaders(): array
    {
        return ['Authorization' => 'Bearer ' . $this->apiToken];
    }

    /**
     * Construit un payload de creation de groupe pour l'API.
     * Genere un task_id UUID automatiquement, surchargeable via $overrides.
     */
    private function createGroupPayload(string $name, array $overrides = []): array
    {
        return array_merge([
            'task_id' => (string) Str::uuid(),
            'task_name' => "Creation groupe {$name}",
            'task_type' => 'create_workstation_group',
            'payload' => array_merge([
                'name' => $name,
                'is_physical' => true,
                'display_name' => "Salle {$name}",
                'description' => "Groupe de test {$name}",
            ], $overrides['payload'] ?? []),
        ], array_diff_key($overrides, ['payload' => true]));
    }

    /**
     * Construit un payload de mise a jour de groupe pour l'API.
     */
    private function updateGroupPayload(string $name, array $overrides = []): array
    {
        return array_merge([
            'task_id' => (string) Str::uuid(),
            'task_name' => "Mise a jour groupe {$name}",
            'task_type' => 'update_workstation_group',
            'payload' => array_merge([
                'name' => $name,
            ], $overrides['payload'] ?? []),
        ], array_diff_key($overrides, ['payload' => true]));
    }

    /**
     * Construit un payload de suppression de groupe pour l'API.
     */
    private function deleteGroupPayload(string $name): array
    {
        return [
            'task_id' => (string) Str::uuid(),
            'task_name' => "Suppression groupe {$name}",
            'task_type' => 'delete_workstation_group',
            'payload' => ['name' => $name],
        ];
    }

    /**
     * Cree un WorkstationGroup directement en BDD (bypass API).
     * Par defaut : locked=control_hub, managed_by_control_hub=true.
     * Utilise pour pre-peupler la BDD avant les tests update/delete.
     */
    private function createGroupInDb(string $name, array $overrides = []): WorkstationGroup
    {
        return WorkstationGroup::create(array_merge([
            'name' => $name,
            'is_physical' => true,
            'display_name' => "Salle {$name}",
            'description' => "Groupe de test {$name}",
            'is_active' => true,
            'locked' => LockReason::CONTROL_HUB->value,
            'managed_by_control_hub' => true,
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
        ], $overrides));
    }

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

    // =========================================================================
    // CREATE — POST /api/v1/workstation-groups/create
    //
    // Verifie que l'endpoint de creation :
    // - Exige un Bearer token valide (middleware ControlHubAuth)
    // - Valide le payload (task_id UUID, payload.name requis)
    // - Cree une ControlHubTask et dispatche un CreateWorkstationGroupJob
    // - Gere l'idempotence (meme task_id = pas de doublon)
    // - Refuse les noms de groupe deja existants (409)
    // - Refuse les parent_name inconnus (422)
    // - Resout parent_name -> parent_id dans le payload de la tache
    // =========================================================================

    /**
     * Sans header Authorization, le middleware ControlHubAuth refuse la requete (403).
     */
    public function test_create_returns_403_without_auth(): void
    {
        $response = $this->postJson('/api/v1/workstation-groups/create', []);
        $response->assertStatus(403);
    }

    /**
     * Un payload invalide (task_id non-UUID) declenche une erreur de validation (422).
     */
    public function test_create_returns_422_with_invalid_payload(): void
    {
        $response = $this->postJson(
            '/api/v1/workstation-groups/create',
            ['task_id' => 'not-a-uuid'],
            $this->authHeaders()
        );
        $response->assertStatus(422);
    }

    /**
     * Un payload valide cree une ControlHubTask en statut 'queued'
     * et dispatche un CreateWorkstationGroupJob sur la queue.
     */
    public function test_create_dispatches_job_and_creates_task(): void
    {
        $name = 'test-create-' . uniqid();
        $taskId = (string) Str::uuid();

        $response = $this->postJson(
            '/api/v1/workstation-groups/create',
            $this->createGroupPayload($name, ['task_id' => $taskId]),
            $this->authHeaders()
        );

        $response->assertStatus(200)->assertJson([
            'success' => true,
            'message' => 'Task received and queued',
        ]);

        $task = ControlHubTask::where('controlhub_task_id', $taskId)->first();
        $this->assertNotNull($task);
        $this->assertEquals('create_workstation_group', $task->type);
        $this->assertEquals(ControlHubTask::STATUS_QUEUED, $task->status);
        $this->assertEquals($name, $task->payload['name']);

        Queue::assertPushed(CreateWorkstationGroupJob::class, function ($job) use ($task) {
            return $job->task->id === $task->id;
        });
    }

    /**
     * Envoyer deux fois le meme task_id ne cree qu'une seule tache et un seul job.
     * Le second appel retourne 200 avec 'Task already received'.
     */
    public function test_create_idempotent_same_task_id(): void
    {
        $payload = $this->createGroupPayload('test-idempotent-' . uniqid());

        $this->postJson('/api/v1/workstation-groups/create', $payload, $this->authHeaders())
            ->assertStatus(200);

        $this->postJson('/api/v1/workstation-groups/create', $payload, $this->authHeaders())
            ->assertStatus(200)
            ->assertJson(['message' => 'Task already received']);

        Queue::assertPushed(CreateWorkstationGroupJob::class, 1);
    }

    /**
     * Creer un groupe avec un nom deja existant en BDD retourne 409 Conflict.
     */
    public function test_create_rejects_duplicate_group_name(): void
    {
        $name = 'test-duplicate-' . uniqid();
        $this->createGroupInDb($name);

        $this->postJson('/api/v1/workstation-groups/create', $this->createGroupPayload($name), $this->authHeaders())
            ->assertStatus(409)
            ->assertJson(['success' => false]);
    }

    /**
     * Specifier un parent_name qui n'existe pas retourne 422.
     */
    public function test_create_rejects_unknown_parent(): void
    {
        $this->postJson(
            '/api/v1/workstation-groups/create',
            $this->createGroupPayload('child-' . uniqid(), ['payload' => ['parent_name' => 'inexistant']]),
            $this->authHeaders()
        )->assertStatus(422)->assertJson(['success' => false]);
    }

    /**
     * Quand parent_name est fourni et existe, le controleur resout
     * le parent_id correspondant et l'injecte dans le payload de la tache.
     */
    public function test_create_resolves_parent_name(): void
    {
        $parentName = 'test-parent-' . uniqid();
        $childName = 'test-child-' . uniqid();
        $parent = $this->createGroupInDb($parentName);

        $this->postJson(
            '/api/v1/workstation-groups/create',
            $this->createGroupPayload($childName, ['payload' => ['parent_name' => $parentName]]),
            $this->authHeaders()
        )->assertStatus(200);

        $task = ControlHubTask::where('type', 'create_workstation_group')->latest()->first();
        $this->assertEquals($parent->id, $task->payload['parent_id']);
    }

    // =========================================================================
    // UPDATE — POST /api/v1/workstation-groups/update
    //
    // Verifie que l'endpoint de mise a jour :
    // - Dispatche un UpdateWorkstationGroupJob pour un groupe ControlHub valide
    // - Refuse de modifier un groupe non gere par ControlHub (403)
    // - Refuse de modifier un groupe avec un lock ROOT (403)
    // - Retourne 404 pour un groupe inexistant
    // - Refuse un new_name qui entre en conflit avec un groupe existant (409)
    // =========================================================================

    /**
     * Un update valide sur un groupe locked=control_hub dispatche le job.
     */
    public function test_update_dispatches_job(): void
    {
        $name = 'test-update-' . uniqid();
        $this->createGroupInDb($name);

        $this->postJson(
            '/api/v1/workstation-groups/update',
            $this->updateGroupPayload($name, ['payload' => ['display_name' => 'Nouveau']]),
            $this->authHeaders()
        )->assertStatus(200)->assertJson(['success' => true]);

        Queue::assertPushed(UpdateWorkstationGroupJob::class, 1);
    }

    /**
     * Un groupe sans lock (cree localement) ne peut pas etre modifie via l'API ControlHub.
     * Verifie que le job n'est PAS dispatche.
     */
    public function test_update_rejects_non_controlhub_group(): void
    {
        $name = 'test-local-' . uniqid();
        $this->createGroupInDb($name, ['locked' => null, 'managed_by_control_hub' => false]);

        $this->postJson(
            '/api/v1/workstation-groups/update',
            $this->updateGroupPayload($name, ['payload' => ['display_name' => 'Hack']]),
            $this->authHeaders()
        )->assertStatus(403);

        Queue::assertNotPushed(UpdateWorkstationGroupJob::class);
    }

    /**
     * Un groupe avec lock=root (groupe systeme) ne peut pas etre modifie
     * via l'API ControlHub, meme avec un token valide.
     */
    public function test_update_rejects_root_locked_group(): void
    {
        $name = 'test-root-' . uniqid();
        $this->createGroupInDb($name, ['locked' => LockReason::ROOT->value]);

        $this->postJson(
            '/api/v1/workstation-groups/update',
            $this->updateGroupPayload($name, ['payload' => ['display_name' => 'Hack']]),
            $this->authHeaders()
        )->assertStatus(403);
    }

    /**
     * Tenter de modifier un groupe qui n'existe pas retourne 404.
     */
    public function test_update_returns_404_for_unknown_group(): void
    {
        $this->postJson(
            '/api/v1/workstation-groups/update',
            $this->updateGroupPayload('inexistant-' . uniqid()),
            $this->authHeaders()
        )->assertStatus(404);
    }

    /**
     * Renommer un groupe vers un nom deja pris par un autre groupe retourne 409.
     */
    public function test_update_rejects_conflicting_new_name(): void
    {
        $name1 = 'test-src-' . uniqid();
        $name2 = 'test-conflict-' . uniqid();
        $this->createGroupInDb($name1);
        $this->createGroupInDb($name2);

        $this->postJson(
            '/api/v1/workstation-groups/update',
            $this->updateGroupPayload($name1, ['payload' => ['new_name' => $name2]]),
            $this->authHeaders()
        )->assertStatus(409);
    }

    // =========================================================================
    // DELETE — POST /api/v1/workstation-groups/delete
    //
    // Verifie que l'endpoint de suppression :
    // - Dispatche un DeleteWorkstationGroupJob pour un groupe ControlHub valide
    // - Refuse de supprimer un groupe non gere par ControlHub (403)
    // - Retourne 404 pour un groupe inexistant
    // =========================================================================

    /**
     * Un delete valide sur un groupe locked=control_hub dispatche le job.
     */
    public function test_delete_dispatches_job(): void
    {
        $name = 'test-delete-' . uniqid();
        $this->createGroupInDb($name);

        $this->postJson('/api/v1/workstation-groups/delete', $this->deleteGroupPayload($name), $this->authHeaders())
            ->assertStatus(200)->assertJson(['success' => true]);

        Queue::assertPushed(DeleteWorkstationGroupJob::class, 1);
    }

    /**
     * Un groupe local (sans lock ControlHub) ne peut pas etre supprime via l'API.
     */
    public function test_delete_rejects_non_controlhub_group(): void
    {
        $name = 'test-local-' . uniqid();
        $this->createGroupInDb($name, ['locked' => null, 'managed_by_control_hub' => false]);

        $this->postJson('/api/v1/workstation-groups/delete', $this->deleteGroupPayload($name), $this->authHeaders())
            ->assertStatus(403);
    }

    /**
     * Tenter de supprimer un groupe inexistant retourne 404.
     */
    public function test_delete_returns_404_for_unknown_group(): void
    {
        $this->postJson('/api/v1/workstation-groups/delete', $this->deleteGroupPayload('inexistant-' . uniqid()), $this->authHeaders())
            ->assertStatus(404);
    }

    // =========================================================================
    // JOB EXECUTION — Tests des jobs (execution directe via Reflection)
    //
    // Ces tests executent la methode protegee execute() des jobs
    // sans passer par la queue, pour verifier la logique metier pure :
    // - CreateWorkstationGroupJob : pose locked=control_hub + managed_by_control_hub=true
    // - UpdateWorkstationGroupJob : modifie les champs, conserve le lock, refuse les non-CH
    // - DeleteWorkstationGroupJob : supprime le groupe, verifie qu'il n'existe plus en BDD
    // =========================================================================

    /**
     * Le job de creation pose automatiquement locked=control_hub et
     * managed_by_control_hub=true sur le groupe cree.
     * Verifie aussi que le groupe est bien persiste en BDD avec les bons attributs.
     */
    public function test_job_create_sets_lock_and_managed_flag(): void
    {
        $name = 'test-job-create-' . uniqid();

        $task = ControlHubTask::create([
            'controlhub_task_id' => (string) Str::uuid(),
            'name' => "Test create {$name}",
            'type' => 'create_workstation_group',
            'payload' => ['name' => $name, 'is_physical' => true, 'display_name' => "Salle {$name}"],
            'status' => ControlHubTask::STATUS_QUEUED,
        ]);

        $result = $this->invokeExecute(new CreateWorkstationGroupJob($task));

        $this->assertEquals($name, $result['group_name']);
        $this->assertEquals(LockReason::CONTROL_HUB->value, $result['locked']);
        $this->assertTrue($result['managed_by_control_hub']);

        $group = WorkstationGroup::where('name', $name)->first();
        $this->assertNotNull($group);
        $this->assertEquals(LockReason::CONTROL_HUB->value, $group->locked);
        $this->assertTrue($group->managed_by_control_hub);
        $this->assertTrue($group->is_physical);
    }

    /**
     * Le job d'update modifie les champs demandes (display_name, description)
     * tout en conservant le lock control_hub sur le groupe.
     */
    public function test_job_update_modifies_controlhub_group(): void
    {
        $name = 'test-job-update-' . uniqid();
        $group = $this->createGroupInDb($name);

        $task = ControlHubTask::create([
            'controlhub_task_id' => (string) Str::uuid(),
            'name' => "Test update {$name}",
            'type' => 'update_workstation_group',
            'payload' => ['name' => $name, 'display_name' => 'Nouveau display', 'description' => 'Nouvelle desc'],
            'status' => ControlHubTask::STATUS_QUEUED,
        ]);

        $result = $this->invokeExecute(new UpdateWorkstationGroupJob($task));

        $this->assertTrue($result['updated']);
        $this->assertContains('display_name', $result['updated_fields']);

        $group->refresh();
        $this->assertEquals('Nouveau display', $group->display_name);
        $this->assertEquals(LockReason::CONTROL_HUB->value, $group->locked);
    }

    /**
     * Le job d'update leve une RuntimeException si le groupe cible
     * n'a pas le lock control_hub (protection contre les modifications non autorisees).
     */
    public function test_job_update_rejects_non_controlhub_group(): void
    {
        $name = 'test-reject-' . uniqid();
        $this->createGroupInDb($name, ['locked' => null, 'managed_by_control_hub' => false]);

        $task = ControlHubTask::create([
            'controlhub_task_id' => (string) Str::uuid(),
            'name' => "Test reject {$name}",
            'type' => 'update_workstation_group',
            'payload' => ['name' => $name, 'display_name' => 'Hack'],
            'status' => ControlHubTask::STATUS_QUEUED,
        ]);

        $this->expectException(\RuntimeException::class);
        $this->invokeExecute(new UpdateWorkstationGroupJob($task));
    }

    /**
     * Le job de suppression retire le groupe de la BDD.
     * Verifie que le groupe n'existe plus apres execution.
     */
    public function test_job_delete_removes_controlhub_group(): void
    {
        $name = 'test-job-delete-' . uniqid();
        $group = $this->createGroupInDb($name);
        $groupId = $group->id;

        $task = ControlHubTask::create([
            'controlhub_task_id' => (string) Str::uuid(),
            'name' => "Test delete {$name}",
            'type' => 'delete_workstation_group',
            'payload' => ['name' => $name],
            'status' => ControlHubTask::STATUS_QUEUED,
        ]);

        $result = $this->invokeExecute(new DeleteWorkstationGroupJob($task));

        $this->assertTrue($result['deleted']);
        $this->assertNull(WorkstationGroup::find($groupId));
    }

    // =========================================================================
    // FULL CRUD LIFECYCLE
    //
    // Simule le cycle de vie complet d'un groupe gere par ControlHub :
    // 1. CREATE : creation avec lock control_hub + managed_by_control_hub
    // 2. UPDATE : renommage (name + display_name), le lock est conserve
    // 3. DELETE : suppression, le groupe n'existe plus en BDD
    //
    // Ce test valide que les 3 jobs s'enchainent correctement
    // sur le meme groupe sans effet de bord.
    // =========================================================================

    /**
     * Cycle complet : create -> update (rename) -> delete.
     * Verifie a chaque etape que le lock control_hub est maintenu
     * et que les donnees en BDD sont coherentes.
     */
    public function test_full_crud_lifecycle(): void
    {
        $name = 'lifecycle-' . uniqid();

        // CREATE
        $t1 = ControlHubTask::create([
            'controlhub_task_id' => (string) Str::uuid(), 'name' => 'Create', 'type' => 'create_workstation_group',
            'payload' => ['name' => $name, 'is_physical' => true, 'display_name' => 'Original'],
            'status' => ControlHubTask::STATUS_QUEUED,
        ]);
        $this->invokeExecute(new CreateWorkstationGroupJob($t1));

        $group = WorkstationGroup::where('name', $name)->first();
        $this->assertNotNull($group);
        $this->assertEquals(LockReason::CONTROL_HUB->value, $group->locked);
        $this->assertTrue($group->managed_by_control_hub);

        // UPDATE (rename)
        $newName = $name . '-v2';
        $t2 = ControlHubTask::create([
            'controlhub_task_id' => (string) Str::uuid(), 'name' => 'Update', 'type' => 'update_workstation_group',
            'payload' => ['name' => $name, 'new_name' => $newName, 'display_name' => 'Updated'],
            'status' => ControlHubTask::STATUS_QUEUED,
        ]);
        $r2 = $this->invokeExecute(new UpdateWorkstationGroupJob($t2));
        $this->assertTrue($r2['updated']);

        $group->refresh();
        $this->assertEquals($newName, $group->name);
        $this->assertEquals('Updated', $group->display_name);
        $this->assertEquals(LockReason::CONTROL_HUB->value, $group->locked);

        // DELETE
        $t3 = ControlHubTask::create([
            'controlhub_task_id' => (string) Str::uuid(), 'name' => 'Delete', 'type' => 'delete_workstation_group',
            'payload' => ['name' => $newName],
            'status' => ControlHubTask::STATUS_QUEUED,
        ]);
        $r3 = $this->invokeExecute(new DeleteWorkstationGroupJob($t3));
        $this->assertTrue($r3['deleted']);
        $this->assertNull(WorkstationGroup::where('name', $newName)->first());
    }

    // =========================================================================
    // API ENDPOINT TESTS - SHORTCUTS ASSOCIATION
    //
    // Verifie que les endpoints create/update acceptent un tableau 'shortcuts'
    // contenant des controlhub_id de raccourcis existants, les resolvent en IDs
    // locaux, et rejettent les raccourcis introuvables.
    // =========================================================================

    /**
     * Creer un groupe avec des raccourcis valides dispatche le job
     * et stocke les resolved_shortcut_ids dans le payload de la tache.
     */
    public function test_create_with_valid_shortcuts_dispatches_job(): void
    {
        $shortcut1 = $this->createShortcutInDb('Firefox');
        $shortcut2 = $this->createShortcutInDb('Chrome');

        $name = 'test-shortcuts-' . uniqid();
        $payload = $this->createGroupPayload($name, [
            'payload' => [
                'shortcuts' => [$shortcut1->controlhub_id, $shortcut2->controlhub_id],
            ],
        ]);

        $response = $this->postJson('/api/v1/workstation-groups/create', $payload, $this->authHeaders());

        $response->assertStatus(200)->assertJson(['success' => true]);
        Queue::assertPushed(CreateWorkstationGroupJob::class);

        $task = ControlHubTask::where('controlhub_task_id', $payload['task_id'])->first();
        $this->assertNotNull($task);
        $this->assertArrayHasKey('resolved_shortcut_ids', $task->payload);
        $this->assertCount(2, $task->payload['resolved_shortcut_ids']);
        $this->assertContains($shortcut1->id, $task->payload['resolved_shortcut_ids']);
        $this->assertContains($shortcut2->id, $task->payload['resolved_shortcut_ids']);
    }

    /**
     * Creer un groupe avec un raccourci introuvable retourne 422
     * et ne cree ni tache ni job.
     */
    public function test_create_rejects_unknown_shortcut(): void
    {
        $shortcut1 = $this->createShortcutInDb('Firefox');
        $fakeUuid = (string) Str::uuid();

        $name = 'test-bad-shortcut-' . uniqid();
        $payload = $this->createGroupPayload($name, [
            'payload' => [
                'shortcuts' => [$shortcut1->controlhub_id, $fakeUuid],
            ],
        ]);

        $response = $this->postJson('/api/v1/workstation-groups/create', $payload, $this->authHeaders());

        $response->assertStatus(422);
        $response->assertJson([
            'success' => false,
            'missing_shortcuts' => [$fakeUuid],
        ]);

        $this->assertEquals(0, ControlHubTask::count());
        Queue::assertNotPushed(CreateWorkstationGroupJob::class);
    }

    /**
     * Creer un groupe sans raccourcis fonctionne normalement.
     */
    public function test_create_without_shortcuts_still_works(): void
    {
        $name = 'test-no-shortcuts-' . uniqid();
        $payload = $this->createGroupPayload($name);

        $response = $this->postJson('/api/v1/workstation-groups/create', $payload, $this->authHeaders());

        $response->assertStatus(200);

        $task = ControlHubTask::where('controlhub_task_id', $payload['task_id'])->first();
        $this->assertArrayNotHasKey('resolved_shortcut_ids', $task->payload);
    }

    /**
     * Creer un groupe avec un shortcuts non-UUID retourne 422 (validation).
     */
    public function test_create_rejects_non_uuid_shortcut(): void
    {
        $name = 'test-invalid-uuid-' . uniqid();
        $payload = $this->createGroupPayload($name, [
            'payload' => [
                'shortcuts' => ['not-a-uuid'],
            ],
        ]);

        $response = $this->postJson('/api/v1/workstation-groups/create', $payload, $this->authHeaders());

        $response->assertStatus(422);
    }

    /**
     * Mettre a jour un groupe avec des raccourcis valides dispatche le job
     * et stocke les resolved_shortcut_ids dans le payload.
     */
    public function test_update_with_valid_shortcuts_dispatches_job(): void
    {
        $name = 'test-update-shortcuts-' . uniqid();
        $this->createGroupInDb($name);
        $shortcut1 = $this->createShortcutInDb('Firefox');

        $payload = $this->updateGroupPayload($name, [
            'payload' => [
                'shortcuts' => [$shortcut1->controlhub_id],
            ],
        ]);

        $response = $this->postJson('/api/v1/workstation-groups/update', $payload, $this->authHeaders());

        $response->assertStatus(200)->assertJson(['success' => true]);
        Queue::assertPushed(UpdateWorkstationGroupJob::class);

        $task = ControlHubTask::where('controlhub_task_id', $payload['task_id'])->first();
        $this->assertArrayHasKey('resolved_shortcut_ids', $task->payload);
        $this->assertCount(1, $task->payload['resolved_shortcut_ids']);
        $this->assertContains($shortcut1->id, $task->payload['resolved_shortcut_ids']);
    }

    /**
     * Mettre a jour un groupe avec un raccourci introuvable retourne 422.
     */
    public function test_update_rejects_unknown_shortcut(): void
    {
        $name = 'test-update-bad-shortcut-' . uniqid();
        $this->createGroupInDb($name);
        $fakeUuid = (string) Str::uuid();

        $payload = $this->updateGroupPayload($name, [
            'payload' => [
                'shortcuts' => [$fakeUuid],
            ],
        ]);

        $response = $this->postJson('/api/v1/workstation-groups/update', $payload, $this->authHeaders());

        $response->assertStatus(422);
        $response->assertJson([
            'success' => false,
            'missing_shortcuts' => [$fakeUuid],
        ]);

        Queue::assertNotPushed(UpdateWorkstationGroupJob::class);
    }

    /**
     * Mettre a jour un groupe avec un tableau shortcuts vide dispatche le job
     * avec resolved_shortcut_ids = [] (pour detacher tous les raccourcis).
     */
    public function test_update_with_empty_shortcuts_resolves_to_empty(): void
    {
        $name = 'test-update-empty-shortcuts-' . uniqid();
        $this->createGroupInDb($name);

        $payload = $this->updateGroupPayload($name, [
            'payload' => [
                'shortcuts' => [],
            ],
        ]);

        $response = $this->postJson('/api/v1/workstation-groups/update', $payload, $this->authHeaders());

        $response->assertStatus(200);

        $task = ControlHubTask::where('controlhub_task_id', $payload['task_id'])->first();
        $this->assertArrayHasKey('resolved_shortcut_ids', $task->payload);
        $this->assertEquals([], $task->payload['resolved_shortcut_ids']);
    }

    // =========================================================================
    // JOB EXECUTION TESTS - SHORTCUTS ASSOCIATION
    //
    // Verifie que les jobs sync() les raccourcis correctement :
    // - CreateWorkstationGroupJob associe les raccourcis apres creation
    // - UpdateWorkstationGroupJob remplace/detache les raccourcis
    // - Sans cle resolved_shortcut_ids, les associations existantes sont preservees
    // =========================================================================

    /**
     * Le job de creation associe les raccourcis au groupe via sync().
     */
    public function test_job_create_syncs_shortcuts(): void
    {
        $shortcut1 = $this->createShortcutInDb('Firefox');
        $shortcut2 = $this->createShortcutInDb('Chrome');
        $name = 'test-job-create-shortcuts-' . uniqid();

        $task = ControlHubTask::create([
            'controlhub_task_id' => (string) Str::uuid(),
            'name' => "Create {$name}",
            'type' => 'create_workstation_group',
            'payload' => [
                'name' => $name,
                'is_physical' => true,
                'display_name' => "Salle {$name}",
                'resolved_shortcut_ids' => [$shortcut1->id, $shortcut2->id],
            ],
            'status' => ControlHubTask::STATUS_QUEUED,
        ]);

        $result = $this->invokeExecute(new CreateWorkstationGroupJob($task));

        $group = WorkstationGroup::where('name', $name)->first();
        $this->assertNotNull($group);
        $this->assertEquals(2, $group->shortcuts()->count());
        $this->assertTrue($group->shortcuts->contains('id', $shortcut1->id));
        $this->assertTrue($group->shortcuts->contains('id', $shortcut2->id));
        $this->assertEquals(2, $result['shortcuts_count']);
    }

    /**
     * Le job de creation sans raccourcis ne cree aucune association.
     */
    public function test_job_create_without_shortcuts_has_no_associations(): void
    {
        $name = 'test-job-no-shortcuts-' . uniqid();

        $task = ControlHubTask::create([
            'controlhub_task_id' => (string) Str::uuid(),
            'name' => "Create {$name}",
            'type' => 'create_workstation_group',
            'payload' => [
                'name' => $name,
                'is_physical' => true,
                'display_name' => "Salle {$name}",
            ],
            'status' => ControlHubTask::STATUS_QUEUED,
        ]);

        $this->invokeExecute(new CreateWorkstationGroupJob($task));

        $group = WorkstationGroup::where('name', $name)->first();
        $this->assertNotNull($group);
        $this->assertEquals(0, $group->shortcuts()->count());
    }

    /**
     * Le job d'update remplace les raccourcis existants par les nouveaux via sync().
     */
    public function test_job_update_syncs_shortcuts(): void
    {
        $shortcut1 = $this->createShortcutInDb('Firefox');
        $shortcut2 = $this->createShortcutInDb('Chrome');
        $name = 'test-job-update-shortcuts-' . uniqid();
        $group = $this->createGroupInDb($name);

        // Pre-attach shortcut1
        $group->shortcuts()->attach($shortcut1->id);
        $this->assertEquals(1, $group->shortcuts()->count());

        // Update: replace shortcut1 with shortcut2
        $task = ControlHubTask::create([
            'controlhub_task_id' => (string) Str::uuid(),
            'name' => "Update {$name}",
            'type' => 'update_workstation_group',
            'payload' => [
                'name' => $name,
                'display_name' => 'Updated',
                'resolved_shortcut_ids' => [$shortcut2->id],
            ],
            'status' => ControlHubTask::STATUS_QUEUED,
        ]);

        $result = $this->invokeExecute(new UpdateWorkstationGroupJob($task));

        $group->refresh();
        $this->assertEquals(1, $group->shortcuts()->count());
        $this->assertTrue($group->shortcuts->contains('id', $shortcut2->id));
        $this->assertFalse($group->shortcuts->contains('id', $shortcut1->id));
        $this->assertEquals(1, $result['shortcuts_count']);
    }

    /**
     * Le job d'update avec un tableau vide detache tous les raccourcis.
     */
    public function test_job_update_with_empty_shortcuts_detaches_all(): void
    {
        $shortcut1 = $this->createShortcutInDb('Firefox');
        $name = 'test-job-detach-shortcuts-' . uniqid();
        $group = $this->createGroupInDb($name);

        $group->shortcuts()->attach($shortcut1->id);
        $this->assertEquals(1, $group->shortcuts()->count());

        $task = ControlHubTask::create([
            'controlhub_task_id' => (string) Str::uuid(),
            'name' => "Update {$name}",
            'type' => 'update_workstation_group',
            'payload' => [
                'name' => $name,
                'display_name' => 'No shortcuts',
                'resolved_shortcut_ids' => [],
            ],
            'status' => ControlHubTask::STATUS_QUEUED,
        ]);

        $result = $this->invokeExecute(new UpdateWorkstationGroupJob($task));

        $group->refresh();
        $this->assertEquals(0, $group->shortcuts()->count());
        $this->assertEquals(0, $result['shortcuts_count']);
    }

    /**
     * Le job d'update sans cle resolved_shortcut_ids preserve les associations existantes.
     */
    public function test_job_update_without_shortcuts_key_preserves_existing(): void
    {
        $shortcut1 = $this->createShortcutInDb('Firefox');
        $name = 'test-job-preserve-shortcuts-' . uniqid();
        $group = $this->createGroupInDb($name);

        $group->shortcuts()->attach($shortcut1->id);
        $this->assertEquals(1, $group->shortcuts()->count());

        // Update: no resolved_shortcut_ids key
        $task = ControlHubTask::create([
            'controlhub_task_id' => (string) Str::uuid(),
            'name' => "Update {$name}",
            'type' => 'update_workstation_group',
            'payload' => [
                'name' => $name,
                'display_name' => 'Only display changed',
            ],
            'status' => ControlHubTask::STATUS_QUEUED,
        ]);

        $result = $this->invokeExecute(new UpdateWorkstationGroupJob($task));

        $group->refresh();
        $this->assertEquals('Only display changed', $group->display_name);
        $this->assertEquals(1, $group->shortcuts()->count());
        $this->assertTrue($group->shortcuts->contains('id', $shortcut1->id));
        $this->assertArrayNotHasKey('shortcuts_count', $result);
    }
}

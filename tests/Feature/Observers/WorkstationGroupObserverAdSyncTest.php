<?php

declare(strict_types=1);

namespace Tests\Feature\Observers;

use App\Jobs\AdSync\WorkstationGroupAdSyncJob;
use App\Models\AppProfile;
use App\Models\WorkstationGroup;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 38.7 — l'observer ne synchronise plus que les groupes PHYSIQUES (OU dans
 * OU=Computers). Les groupes LOGIQUES sont purement SQL : aucun WorkstationGroupAdSyncJob.
 * La création automatique d'AppProfile a été retirée : un groupe avec
 * `app_profile_name` rempli ne crée AUCUN profil ni lien pivot.
 */
class WorkstationGroupObserverAdSyncTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->createSchema();
    }

    private function createSchema(): void
    {
        if (! Schema::hasTable('workstation_groups')) {
            Schema::create('workstation_groups', function (Blueprint $table) {
                $table->id();
                $table->string('controlhub_id')->nullable();
                $table->string('name');
                $table->boolean('is_physical')->default(true);
                $table->string('display_name')->nullable();
                $table->text('description')->nullable();
                $table->string('app_profile_name')->nullable();
                $table->unsignedBigInteger('parent_id')->nullable();
                $table->string('ad_dn')->nullable();
                $table->string('ad_guid')->nullable();
                $table->boolean('is_active')->default(true);
                $table->string('locked')->nullable();
                $table->boolean('managed_by_control_hub')->default(false);
                $table->timestamp('controlhub_version')->nullable();
                $table->string('controlhub_label')->nullable();
                $table->string('environment')->nullable();
                $table->timestamp('archived_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('app_profiles')) {
            Schema::create('app_profiles', function (Blueprint $table) {
                $table->id();
                $table->string('name', 100)->unique();
                $table->text('description')->nullable();
                $table->string('ad_guid', 36)->nullable();
                $table->string('ad_dn', 512)->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamp('archived_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('app_profile_workstation_group')) {
            Schema::create('app_profile_workstation_group', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('app_profile_id');
                $table->unsignedBigInteger('workstation_group_id');
                $table->timestamps();
            });
        }
    }

    // ── AC1 : aucune écriture AD pour les groupes logiques ──────────────────

    #[Test]
    public function creating_a_logical_group_dispatches_no_ad_sync_job(): void
    {
        Bus::fake();

        WorkstationGroup::create(['name' => 'parc-logique', 'is_physical' => false, 'is_active' => true]);

        Bus::assertNotDispatched(WorkstationGroupAdSyncJob::class);
    }

    #[Test]
    public function renaming_moving_deleting_a_logical_group_dispatches_no_ad_sync_job(): void
    {
        $parent = WorkstationGroup::create(['name' => 'autre-logique', 'is_physical' => false, 'is_active' => true]);
        $group = WorkstationGroup::create(['name' => 'parc-logique', 'is_physical' => false, 'is_active' => true]);

        Bus::fake();

        $group->update(['name' => 'parc-logique-renomme']);
        $group->update(['parent_id' => $parent->id]);
        $group->delete();

        Bus::assertNotDispatched(WorkstationGroupAdSyncJob::class);
    }

    // ── AC2/AC7 : les groupes physiques restent synchronisés (non-régression) ─

    #[Test]
    public function creating_a_physical_group_dispatches_a_create_job(): void
    {
        Bus::fake();

        $group = WorkstationGroup::create(['name' => 'salle-101', 'is_physical' => true, 'is_active' => true]);

        Bus::assertDispatched(
            WorkstationGroupAdSyncJob::class,
            fn (WorkstationGroupAdSyncJob $job) => $job->workstationGroupId === $group->id && $job->action === 'create',
        );
    }

    #[Test]
    public function renaming_and_moving_a_physical_group_dispatches_rename_and_move_jobs(): void
    {
        Bus::fake();

        $parent = WorkstationGroup::create(['name' => 'batiment-a', 'is_physical' => true, 'is_active' => true, 'ad_guid' => 'guid-parent']);
        $group = WorkstationGroup::create(['name' => 'salle-101', 'is_physical' => true, 'is_active' => true, 'ad_guid' => 'guid-101']);

        $group->update(['name' => 'salle-102']);
        $group->update(['parent_id' => $parent->id]);

        Bus::assertDispatched(WorkstationGroupAdSyncJob::class, fn ($job) => $job->action === 'rename');
        Bus::assertDispatched(WorkstationGroupAdSyncJob::class, fn ($job) => $job->action === 'move');
    }

    #[Test]
    public function deleting_a_physical_group_dispatches_a_delete_job(): void
    {
        Bus::fake();

        $group = WorkstationGroup::create(['name' => 'salle-101', 'is_physical' => true, 'is_active' => true, 'ad_guid' => 'guid-101']);

        $group->delete();

        Bus::assertDispatched(WorkstationGroupAdSyncJob::class, fn ($job) => $job->action === 'delete');
    }

    // ── AC8 : plus de création automatique d'AppProfile ─────────────────────

    #[Test]
    public function creating_a_group_with_app_profile_name_creates_no_profile_and_no_pivot(): void
    {
        Bus::fake();

        $group = WorkstationGroup::create([
            'name' => 'salle-avec-profil',
            'is_physical' => true,
            'is_active' => true,
            'app_profile_name' => 'salle-avec-profil',
        ]);

        $this->assertSame(0, AppProfile::count(), 'Aucun AppProfile ne doit être créé automatiquement (38.7).');
        $this->assertFalse($group->appProfiles()->exists(), 'Aucun lien pivot ne doit être posé.');
        // Le champ reste stocké (inerte) — la couture controlHub le lit/écrit.
        $this->assertSame('salle-avec-profil', $group->fresh()->app_profile_name);
    }

    #[Test]
    public function creating_a_logical_group_with_app_profile_name_creates_no_profile(): void
    {
        Bus::fake();

        $group = WorkstationGroup::create([
            'name' => 'parc-avec-profil',
            'is_physical' => false,
            'is_active' => true,
            'app_profile_name' => 'parc-avec-profil',
        ]);

        $this->assertSame(0, AppProfile::count());
        $this->assertFalse($group->appProfiles()->exists());
    }

    #[Test]
    public function renaming_a_group_never_touches_a_homonymous_profile(): void
    {
        Bus::fake();

        $group = WorkstationGroup::create(['name' => 'salle-x', 'is_physical' => true, 'is_active' => true, 'ad_guid' => 'g']);
        // Profil homonyme, jamais lié au groupe : il ne doit PAS être renommé.
        $profile = AppProfile::create(['name' => 'salle-x', 'is_active' => true]);

        $group->update(['name' => 'salle-y']);

        $this->assertNotNull(AppProfile::find($profile->id));
        $this->assertSame('salle-x', $profile->fresh()->name, 'Le profil homonyme ne doit pas être renommé.');
        $this->assertNull(AppProfile::where('name', 'salle-y')->first());
    }
}

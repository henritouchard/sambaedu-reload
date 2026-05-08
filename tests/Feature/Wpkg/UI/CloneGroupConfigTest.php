<?php

declare(strict_types=1);

namespace Tests\Feature\Wpkg\UI;

use App\Models\AppProfile;
use App\Models\Application;
use App\Models\WorkstationGroup;
use App\Services\AppProfile\AppProfileService;
use App\Wpkg\Deployment\Events\AppProfileWorkstationGroupChanged;
use App\Wpkg\Deployment\Events\WorkstationGroupApplicationsChanged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\WpkgSchemaBootstrapper;
use Tests\TestCase;

/**
 * Story 15.4 / AC4, AC7.1 — Clone parc → parc.
 *
 * Vérifie : (a) BD reflète source, (b) ligne wpkg_deployments créée avec UUID
 * + status completed, (c) events ciblés dispatchés selon le diff.
 */
class CloneGroupConfigTest extends TestCase
{
    private WorkstationGroup $source;
    private WorkstationGroup $target;
    private AppProfile $profile;
    private Application $application;

    protected function setUp(): void
    {
        parent::setUp();
        WpkgSchemaBootstrapper::bootstrap();
        $this->ensureDeploymentsTable();

        $this->source = WorkstationGroup::create(['name' => 'src']);
        $this->target = WorkstationGroup::create(['name' => 'tgt']);
        $this->profile = AppProfile::create(['name' => 'p-1', 'is_active' => true]);
        $this->application = Application::create(['app_id' => 'firefox', 'name' => 'Firefox']);

        $this->source->appProfiles()->attach([$this->profile->id]);
        $this->source->applications()->attach([$this->application->id]);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('wpkg_deployments');
        WpkgSchemaBootstrapper::tearDown();
        parent::tearDown();
    }

    private function ensureDeploymentsTable(): void
    {
        if (Schema::hasTable('wpkg_deployments')) {
            return;
        }
        Schema::create('wpkg_deployments', function ($t) {
            $t->uuid('id')->primary();
            $t->unsignedBigInteger('triggered_by')->nullable();
            $t->timestamp('triggered_at')->nullable();
            $t->json('target_scope')->nullable();
            $t->string('status', 20)->default('pending');
            $t->json('summary')->nullable();
            $t->timestamps();
        });
    }

    #[Test]
    public function clone_reflects_source_in_target_and_creates_deployment_row(): void
    {
        Event::fake([
            AppProfileWorkstationGroupChanged::class,
            WorkstationGroupApplicationsChanged::class,
        ]);

        $svc = new AppProfileService();
        $result = $svc->cloneConfiguration($this->source->id, $this->target->id);

        // BD : la cible reflète la source.
        self::assertSame(
            $this->source->fresh()->appProfiles->pluck('id')->sort()->values()->all(),
            $this->target->fresh()->appProfiles->pluck('id')->sort()->values()->all(),
        );
        self::assertSame(
            $this->source->fresh()->applications->pluck('id')->sort()->values()->all(),
            $this->target->fresh()->applications->pluck('id')->sort()->values()->all(),
        );

        // Diff retourné : 1 profil ajouté, 1 app ajoutée.
        self::assertSame([$this->profile->id], $result['profiles']['added']);
        self::assertSame([], $result['profiles']['removed']);
        self::assertSame([$this->application->id], $result['applications']['added']);
        self::assertSame([], $result['applications']['removed']);

        // wpkg_deployments : 1 ligne avec status completed + UUID partagé.
        $row = DB::table('wpkg_deployments')->first();
        self::assertNotNull($row);
        self::assertSame('completed', $row->status);
        self::assertSame($result['deployment_id'], $row->id);

        // Events ciblés : 1 par profil + 1 pluriel par direction d'apps.
        Event::assertDispatched(AppProfileWorkstationGroupChanged::class, 1);
        Event::assertDispatched(WorkstationGroupApplicationsChanged::class, 1);
    }

    #[Test]
    public function preview_clone_does_not_mutate_db(): void
    {
        $svc = new AppProfileService();
        $preview = $svc->previewCloneConfiguration($this->source->id, $this->target->id);

        self::assertSame([$this->profile->id], $preview['profiles']['added']);
        // Cible inchangée.
        self::assertSame(0, $this->target->fresh()->appProfiles()->count());
        self::assertSame(0, $this->target->fresh()->applications()->count());
        self::assertSame(0, DB::table('wpkg_deployments')->count());
    }

    #[Test]
    public function clone_with_diff_remove_dispatches_detached_events(): void
    {
        // Cible avec un profil supplémentaire absent de la source → doit être retiré.
        $extra = AppProfile::create(['name' => 'p-extra', 'is_active' => true]);
        $this->target->appProfiles()->attach([$extra->id]);

        Event::fake([AppProfileWorkstationGroupChanged::class]);

        $svc = new AppProfileService();
        $result = $svc->cloneConfiguration($this->source->id, $this->target->id);

        self::assertSame([$this->profile->id], $result['profiles']['added']);
        self::assertSame([$extra->id], $result['profiles']['removed']);

        // 1 attached (profil source) + 1 detached (extra) = 2 events.
        Event::assertDispatched(AppProfileWorkstationGroupChanged::class, 2);
    }
}

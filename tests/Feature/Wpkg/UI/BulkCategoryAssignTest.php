<?php

declare(strict_types=1);

namespace Tests\Feature\Wpkg\UI;

use App\Models\AppProfile;
use App\Models\Application;
use App\Models\WorkstationGroup;
use App\Services\AppProfile\AppProfileService;
use App\Wpkg\Deployment\Events\AppProfileApplicationsChanged;
use App\Wpkg\Deployment\Events\AppProfileWorkstationGroupChanged;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\WpkgSchemaBootstrapper;
use Tests\TestCase;

/**
 * Story 15.4 / AC3 + Décision C — Bulk catégorie : N apps de même catégorie
 * assignées en 1 mutation, 1 event pluriel `AppProfileApplicationsChanged`
 * (vs N events) pour minimiser les invalidations cache redondantes.
 */
class BulkCategoryAssignTest extends TestCase
{
    private WorkstationGroup $group;

    protected function setUp(): void
    {
        parent::setUp();
        WpkgSchemaBootstrapper::bootstrap();

        // Le bootstrapper crée applications avec colonnes minimales — on étend
        // ici pour le champ `category` requis par le bulk.
        if (! Schema::hasColumn('applications', 'category')) {
            Schema::table('applications', function (Blueprint $t) {
                $t->string('category', 100)->nullable();
            });
        }

        $this->group = WorkstationGroup::create(['name' => 'parc-1']);
    }

    protected function tearDown(): void
    {
        WpkgSchemaBootstrapper::tearDown();
        parent::tearDown();
    }

    #[Test]
    public function bulk_assignment_dispatches_a_single_plural_event_for_apps(): void
    {
        // 3 apps dans la même catégorie.
        $apps = collect();
        for ($i = 1; $i <= 3; $i++) {
            $apps->push(Application::create([
                'app_id' => "app-{$i}",
                'name' => "App {$i}",
                'category' => 'browsers',
            ]));
        }
        $appIds = $apps->pluck('id')->map('intval')->all();

        $profile = AppProfile::create(['name' => 'browsers-bulk', 'is_active' => true]);

        Event::fake([
            AppProfileApplicationsChanged::class,
            AppProfileWorkstationGroupChanged::class,
        ]);

        $svc = new AppProfileService();
        $svc->addApplications($profile->id, $appIds);
        $svc->addWorkstationGroups($profile->id, [$this->group->id]);

        // Décision C : 1 event pluriel pour les apps (pas N).
        Event::assertDispatched(AppProfileApplicationsChanged::class, 1);
        Event::assertDispatched(AppProfileApplicationsChanged::class, function ($e) use ($appIds) {
            return $e->applicationIds === $appIds && $e->direction === 'attached';
        });

        // 1 event group pour la liaison parc.
        Event::assertDispatched(AppProfileWorkstationGroupChanged::class, 1);

        self::assertSame(3, $profile->applications()->count());
        self::assertSame(1, $profile->workstationGroups()->count());
    }

    #[Test]
    public function bulk_assignment_creates_profile_when_using_create_mode(): void
    {
        Application::create(['app_id' => 'a1', 'name' => 'A1', 'category' => 'tools']);
        Application::create(['app_id' => 'a2', 'name' => 'A2', 'category' => 'tools']);

        $svc = new AppProfileService();
        $newProfile = $svc->createProfile([
            'name' => 'Categorie-tools',
            'display_name' => 'Categorie-tools',
            'is_active' => true,
        ]);

        self::assertNotNull($newProfile);
        self::assertSame('Categorie-tools', $newProfile->name);
    }
}

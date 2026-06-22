<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\ParcSettings;

use App\Enums\ApplicationStatus;
use App\Enums\InstallationStatus;
use App\Jobs\InstallApplicationJob;
use App\Models\Application;
use App\Models\Depot;
use App\Models\DepotApplication;
use App\Models\InstallationLog;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Traits\CreatesAppStoreSchema;

/**
 * Story 8.2.7 (AC1, AC2, AC3, AC6, AC9) — Dispatch non-bloquant du tab Dépôt.
 *
 * Vérifie que `installFromDepot()` DISPATCHE un InstallApplicationJob par app
 * (et n'exécute PAS l'installation en synchrone : l'Application ne passe pas
 * à Installed, aucun HTTP n'est tapé), que le toast indique « arrière-plan »,
 * et que le panneau de progression liste les installations actives du login
 * courant.
 *
 * ⚠ phpunit.xml force QUEUE_CONNECTION=sync : sans Queue::fake(), un dispatch
 * s'exécuterait synchroniquement et téléchargerait pour de vrai. Tous les
 * tests de dispatch fakent donc la queue AVANT le call().
 */
class DepotTabDispatchTest extends TestCase
{
    use CreatesAppStoreSchema;

    private const COMPONENT = 'pages::parc-settings._partials.depot-tab';

    private Depot $depot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createAppStoreSchema();

        $this->depot = Depot::create([
            'name' => 'Dépôt principal',
            'url' => 'http://test.example.com/wpkg',
            'is_primary' => true,
            'is_active' => true,
        ]);
    }

    protected function tearDown(): void
    {
        $this->dropAppStoreSchema();
        parent::tearDown();
    }

    private function makeDepotApp(string $appId): DepotApplication
    {
        return DepotApplication::create([
            'depot_id' => $this->depot->id,
            'app_id' => $appId,
            'name' => 'App ' . $appId,
            'version' => '1.0.0',
            'category' => 'Test',
            'branch' => 'stable',
            'xml_url' => "http://test.example.com/wpkg/stable/{$appId}.xml",
        ]);
    }

    /**
     * User NON persisté : `auth()->user()?->login` suffit au composant, et on
     * évite de dépendre de la table `users` (absente du schéma minimal
     * AppStore). `actingAs()` accepte un Authenticatable non sauvegardé.
     */
    private function actingAsManager(string $login = 'gestionnaire'): User
    {
        $user = new User();
        $user->login = $login;
        $user->role = 'admin';
        $user->is_active = true;
        $this->actingAs($user);

        return $user;
    }

    /* =================================================================
     * AC1, AC9 — dispatch non-bloquant
     * ================================================================= */

    #[Test]
    public function it_dispatches_one_job_per_selected_application(): void
    {
        Queue::fake();
        $this->actingAsManager();

        $app1 = $this->makeDepotApp('seven-zip');
        $app2 = $this->makeDepotApp('firefox');

        Livewire::test(self::COMPONENT)
            ->set('selectedDepotInstallApps', [$app1->id, $app2->id])
            ->call('installFromDepot')
            ->assertHasNoErrors();

        Queue::assertPushed(InstallApplicationJob::class, 2);
    }

    #[Test]
    public function it_does_not_install_synchronously(): void
    {
        Queue::fake();
        $this->actingAsManager();

        $app = $this->makeDepotApp('seven-zip');

        Livewire::test(self::COMPONENT)
            ->set('selectedDepotInstallApps', [$app->id])
            ->call('installFromDepot');

        // Preuve de non-synchronisme : aucune Application créée/installée,
        // aucun InstallationLog (le worker fake ne tourne pas).
        self::assertSame(0, Application::count());
        self::assertSame(0, InstallationLog::count());
        self::assertNull(Application::where('app_id', 'seven-zip')->first());
    }

    /* =================================================================
     * AC3 — toast « arrière-plan » + reset sélection
     * ================================================================= */

    #[Test]
    public function it_shows_background_toast_and_resets_selection(): void
    {
        Queue::fake();
        $this->actingAsManager();

        $app1 = $this->makeDepotApp('seven-zip');
        $app2 = $this->makeDepotApp('firefox');

        Livewire::test(self::COMPONENT)
            ->set('selectedDepotInstallApps', [$app1->id, $app2->id])
            ->call('installFromDepot')
            ->assertDispatched(
                'toastMagic',
                status: 'success',
                message: '2 installation(s) lancée(s) en arrière-plan',
            )
            ->assertSet('selectedDepotInstallApps', []);
    }

    /* =================================================================
     * AC2 — initiated_by passé au Job = login courant
     * ================================================================= */

    #[Test]
    public function it_dispatches_job_with_current_user_login_as_initiated_by(): void
    {
        Queue::fake();
        $this->actingAsManager('alice');

        $app = $this->makeDepotApp('seven-zip');

        Livewire::test(self::COMPONENT)
            ->set('selectedDepotInstallApps', [$app->id])
            ->call('installFromDepot');

        Queue::assertPushed(InstallApplicationJob::class, function (InstallApplicationJob $job) use ($app): bool {
            return $job->depotApplicationId === $app->id
                && $job->initiatedBy === 'alice';
        });
    }

    #[Test]
    public function it_warns_when_nothing_selected(): void
    {
        Queue::fake();
        $this->actingAsManager();

        Livewire::test(self::COMPONENT)
            ->set('selectedDepotInstallApps', [])
            ->call('installFromDepot')
            ->assertDispatched('toastMagic', status: 'warning');

        Queue::assertNothingPushed();
    }

    /* =================================================================
     * AC6 — panneau de progression (activeInstallations)
     * ================================================================= */

    #[Test]
    public function active_installations_lists_in_progress_logs_of_current_user(): void
    {
        $this->actingAsManager('alice');

        $app = Application::create([
            'depot_id' => $this->depot->id,
            'app_id' => 'seven-zip',
            'name' => '7-Zip',
            'version' => '1.0.0',
            'status' => ApplicationStatus::Downloading,
        ]);

        // Log non-terminal de alice → doit apparaître.
        InstallationLog::create([
            'application_id' => $app->id,
            'status' => InstallationStatus::Downloading,
            'initiated_by' => 'alice',
            'progress' => 40,
            'started_at' => now(),
        ]);
        // Log terminal de alice → ne doit PAS apparaître.
        InstallationLog::create([
            'application_id' => $app->id,
            'status' => InstallationStatus::Success,
            'initiated_by' => 'alice',
            'progress' => 100,
            'started_at' => now(),
            'completed_at' => now(),
        ]);
        // Log non-terminal d'un autre user → ne doit PAS apparaître.
        InstallationLog::create([
            'application_id' => $app->id,
            'status' => InstallationStatus::Installing,
            'initiated_by' => 'bob',
            'progress' => 80,
            'started_at' => now(),
        ]);

        $component = Livewire::test(self::COMPONENT);
        $active = $component->instance()->activeInstallations();

        self::assertCount(1, $active);
        self::assertSame(InstallationStatus::Downloading, $active->first()->status);
        self::assertSame('alice', $active->first()->initiated_by);
    }
}

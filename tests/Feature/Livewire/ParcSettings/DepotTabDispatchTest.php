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
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
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

        // Story 51.1 — le SFC depot-tab lit ControlHubContract::active() (computed
        // isManaged) à chaque rendu : la table doit exister. Vide par défaut ⇒
        // standalone (comportement inchangé).
        Schema::create('controlhub_contracts', function (Blueprint $table): void {
            $table->id();
            $table->string('link_state')->default('active');
            $table->timestamp('received_at')->nullable();
            $table->string('schema_version')->nullable();
            $table->timestamps();
        });

        $this->depot = Depot::create([
            'name' => 'Dépôt principal',
            'url' => 'http://test.example.com/wpkg',
            'is_primary' => true,
            'is_active' => true,
        ]);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('controlhub_contracts');
        $this->dropAppStoreSchema();
        parent::tearDown();
    }

    /**
     * Story 51.1 — Table minimale `controlhub_contracts` + contrat actif (le schéma
     * AppStore minimal ne la crée pas). Suffit à `ControlHubContract::active()`.
     */
    private function activateUpstreamContract(): void
    {
        \App\Models\ControlHubContract::create(['link_state' => 'active']);
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

    /* =================================================================
     * Story 51.1 (AC8) — verrouillage sous contrat amont actif
     * ================================================================= */

    #[Test]
    public function it_refuses_depot_creation_under_an_active_upstream_contract(): void
    {
        $this->actingAsManager();
        $this->activateUpstreamContract();

        $before = Depot::count();

        Livewire::test(self::COMPONENT)
            ->set('newDepotName', 'Dépôt pirate')
            ->set('newDepotUrl', 'https://pirate.example.com/packages.xml')
            ->call('createDepot')
            ->assertDispatched('toastMagic', status: 'error');

        // Garde SERVEUR : aucun dépôt créé (la vraie barrière, pas seulement l'UI).
        self::assertSame($before, Depot::count());
        self::assertNull(Depot::where('name', 'Dépôt pirate')->first());
    }

    #[Test]
    public function it_refuses_deleting_the_imposed_depot(): void
    {
        $this->actingAsManager();
        $this->activateUpstreamContract();

        $imposed = Depot::create([
            'name' => 'ControlHub',
            'url' => 'controlhub://managed',
            'is_primary' => true,
            'is_active' => true,
            'is_imposed' => true,
        ]);

        Livewire::test(self::COMPONENT)
            ->set('deleteDepotId', $imposed->id)
            ->call('deleteDepot')
            ->assertDispatched('toastMagic', status: 'error');

        // Le dépôt imposé n'est ni supprimé ni désactivé/dépriorisé.
        $imposed->refresh();
        self::assertTrue($imposed->is_active);
        self::assertTrue($imposed->is_primary);
        self::assertTrue($imposed->is_imposed);
    }

    #[Test]
    public function after_severance_the_imposed_depot_becomes_manageable_again(): void
    {
        // Review 51.1 #2 (AC10) — À la rupture du lien (release passif), le dépôt imposé
        // « redevient gérable » : la garde de refus suit `isManaged()` (le LIEN), pas le
        // seul flag `is_imposed` (qui reste true à jamais, l'état étant figé). SANS contrat
        // actif, l'admin doit pouvoir le désactiver.
        $this->actingAsManager();
        // PAS de activateUpstreamContract() → lien rompu / absent (standalone).

        $imposed = Depot::create([
            'name' => 'ControlHub',
            'url' => 'controlhub://managed',
            'is_primary' => true,
            'is_active' => true,
            'is_imposed' => true,
        ]);

        Livewire::test(self::COMPONENT)
            ->set('deleteDepotId', $imposed->id)
            ->call('deleteDepot')
            ->assertDispatched('toastMagic', status: 'success');

        // Le dépôt imposé orphelin est désactivé (soft-disable UI) et conservé en base.
        $imposed->refresh();
        self::assertFalse($imposed->is_active);
        self::assertTrue($imposed->is_imposed, 'le flag is_imposed reste (état figé, AC10)');
    }

    #[Test]
    public function it_allows_depot_creation_in_standalone(): void
    {
        // Non-régression : sans contrat amont, la création de dépôt reste fonctionnelle.
        $this->actingAsManager();

        Http::fake([
            '*' => Http::response('<?xml version="1.0"?><packages></packages>', 200),
        ]);

        $before = Depot::count();

        Livewire::test(self::COMPONENT)
            ->set('newDepotName', 'Nouveau dépôt local')
            ->set('newDepotUrl', 'https://local.example.com/packages.xml')
            ->call('createDepot')
            ->assertDispatched('toastMagic', status: 'success');

        self::assertSame($before + 1, Depot::count());
        self::assertNotNull(Depot::where('name', 'Nouveau dépôt local')->first());
    }

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

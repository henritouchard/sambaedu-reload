<?php

declare(strict_types=1);

namespace Tests\Feature\ControlHub;

use App\Exceptions\ControlHub\ApplicationNotInUpstreamCatalogException;
use App\Models\AppProfile;
use App\Models\Application;
use App\Models\ControlHubContract;
use App\Models\ControlHubContractCatalogApp;
use App\Models\User;
use App\Models\Workstation;
use App\Models\WorkstationGroup;
use App\Observers\WorkstationGroupObserver;
use App\Services\AppProfile\AppProfileService;
use App\Services\ControlHub\UpstreamCatalogResolver;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 31.1 — Bornage du canal d'install refnum au catalogue applicatif amont (FR5).
 *
 * Couvre AC1–AC7 : consultation filtrée, refus install hors catalogue sans
 * écriture pivot, install en catalogue OK, standalone byte-identique + court-circuit
 * NFR3 zéro requête, catalogue vide = pas de bornage (D1), appelant non authentifié
 * non bloqué (AC #6), + résolveur unitaire sur les 3 états.
 *
 * Tests HÔTE (php8.4 + pdo_sqlite), `RefreshDatabase`. Piège SQLite : on teste des
 * DÉCISIONS (présence/absence dans la liste, exceptions, count pivot), jamais des
 * bornes varchar PG. Match sur `app_id` (string), pas `id` (D2).
 *
 * ⚠️ GARDE-FOU R3 : aucun « central ». [prd#R3]
 */
class UpstreamCatalogBoundaryTest extends TestCase
{
    use RefreshDatabase;

    private AppProfileService $service;

    protected function setUp(): void
    {
        parent::setUp();
        // Pas de LDAP/AD en HÔTE : neutralise la sync au create() des parcs/postes.
        WorkstationGroupObserver::disableSync();
        // Isole du listener WPKG (cache/regen .ini) : seuls le garde + l'écriture
        // pivot sont exercés.
        Event::fake();

        (new PermissionSeeder())->run();

        $this->service = app(AppProfileService::class);
    }

    protected function tearDown(): void
    {
        WorkstationGroupObserver::enableSync();
        parent::tearDown();
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    private function makeRefnum(): User
    {
        // Droit GLOBAL wpkg.assign ⇒ le Gate 29.1 passe sur n'importe quel parc/poste
        // (canOnWorkstationGroup step 2). Isole le bornage catalogue du gate WPKG.
        $user = User::create(['login' => 'refnum', 'role' => 'autre', 'is_active' => true]);
        $user->givePermissionTo('wpkg.assign');

        return $user;
    }

    private function makeApp(string $appId): Application
    {
        return Application::create(['app_id' => $appId, 'name' => ucfirst($appId)]);
    }

    private function makeGroup(string $name): WorkstationGroup
    {
        return WorkstationGroup::create([
            'name' => $name,
            'is_physical' => true,
            'is_active' => true,
        ]);
    }

    /** Crée un contrat amont actif portant le catalogue `$appKeys`. */
    private function activeContractWithCatalog(array $appKeys): ControlHubContract
    {
        $contract = ControlHubContract::factory()->create();
        foreach ($appKeys as $key) {
            ControlHubContractCatalogApp::factory()->create([
                'controlhub_contract_id' => $contract->id,
                'app_key' => $key,
            ]);
        }

        return $contract;
    }

    // ---------------------------------------------------------------------
    // AC1 — consultation filtrée
    // ---------------------------------------------------------------------

    #[Test]
    public function ac1_scope_only_returns_apps_in_catalog(): void
    {
        $this->activeContractWithCatalog(['firefox']);
        $inCatalog = $this->makeApp('firefox');
        $outOfCatalog = $this->makeApp('chrome');

        $ids = Application::query()->inUpstreamCatalog()->pluck('id')->all();

        $this->assertContains($inCatalog->id, $ids, 'AC1 : app du catalogue proposée');
        $this->assertNotContains($outOfCatalog->id, $ids, 'AC1 : app hors catalogue absente');
    }

    #[Test]
    public function ac1_list_applications_for_select_is_filtered(): void
    {
        $this->activeContractWithCatalog(['firefox']);
        $this->makeApp('firefox');
        $this->makeApp('chrome');

        $appIds = $this->service->listApplicationsForSelect()->pluck('app_id')->all();

        $this->assertSame(['firefox'], $appIds, 'AC1 : sélecteur de profil borné au catalogue');
    }

    // ---------------------------------------------------------------------
    // AC2 — refus install hors catalogue, sans écriture pivot
    // ---------------------------------------------------------------------

    #[Test]
    public function ac2_group_install_out_of_catalog_is_refused_without_pivot_write(): void
    {
        $this->activeContractWithCatalog(['firefox']);
        $chrome = $this->makeApp('chrome');
        $group = $this->makeGroup('salle_a');

        $this->actingAs($this->makeRefnum());

        try {
            $this->service->addApplicationsToWorkstationGroup($group->id, [$chrome->id]);
            $this->fail('AC2 : une app hors catalogue aurait dû être refusée');
        } catch (ApplicationNotInUpstreamCatalogException $e) {
            $this->assertStringContainsString('hors catalogue amont', $e->getMessage());
        } finally {
            $this->assertDatabaseMissing('application_workstation_group', [
                'application_id' => $chrome->id,
                'workstation_group_id' => $group->id,
            ]);
        }
    }

    #[Test]
    public function ac2_workstation_install_out_of_catalog_is_refused_without_pivot_write(): void
    {
        $this->activeContractWithCatalog(['firefox']);
        $chrome = $this->makeApp('chrome');
        $group = $this->makeGroup('salle_a');
        $ws = Workstation::factory()->create();
        $ws->groups()->attach($group->id); // physicalRoom = salle_a

        $this->actingAs($this->makeRefnum());

        try {
            $this->service->addApplicationsToWorkstation($ws->id, [$chrome->id]);
            $this->fail('AC2 : une app hors catalogue aurait dû être refusée');
        } catch (ApplicationNotInUpstreamCatalogException) {
            // attendu
        } finally {
            $this->assertDatabaseMissing('application_workstation', [
                'application_id' => $chrome->id,
                'workstation_id' => $ws->id,
            ]);
        }
    }

    #[Test]
    public function ac2_profile_composition_out_of_catalog_is_refused_without_pivot_write(): void
    {
        $this->activeContractWithCatalog(['firefox']);
        $chrome = $this->makeApp('chrome');
        $profile = AppProfile::create(['name' => 'profil_test', 'is_active' => true]);

        $this->actingAs($this->makeRefnum());

        try {
            $this->service->addApplications($profile->id, [$chrome->id]);
            $this->fail('AC2 : composer un profil avec une app hors catalogue aurait dû être refusé');
        } catch (ApplicationNotInUpstreamCatalogException) {
            // attendu
        } finally {
            $this->assertDatabaseMissing('app_profile_application', [
                'application_id' => $chrome->id,
                'app_profile_id' => $profile->id,
            ]);
        }
    }

    #[Test]
    public function ac2_mixed_batch_with_one_out_of_catalog_is_fully_refused(): void
    {
        $this->activeContractWithCatalog(['firefox']);
        $firefox = $this->makeApp('firefox');
        $chrome = $this->makeApp('chrome');
        $group = $this->makeGroup('salle_a');

        $this->actingAs($this->makeRefnum());

        try {
            $this->service->addApplicationsToWorkstationGroup($group->id, [$firefox->id, $chrome->id]);
            $this->fail('AC2 : un lot contenant une app hors catalogue aurait dû être refusé en bloc');
        } catch (ApplicationNotInUpstreamCatalogException) {
            // attendu
        } finally {
            // Refus AVANT écriture ⇒ même l'app en catalogue n'est pas écrite.
            $this->assertDatabaseMissing('application_workstation_group', [
                'application_id' => $firefox->id,
                'workstation_group_id' => $group->id,
            ]);
        }
    }

    // ---------------------------------------------------------------------
    // AC3 — install en catalogue OK
    // ---------------------------------------------------------------------

    #[Test]
    public function ac3_group_install_in_catalog_succeeds(): void
    {
        $this->activeContractWithCatalog(['firefox']);
        $firefox = $this->makeApp('firefox');
        $group = $this->makeGroup('salle_a');

        $this->actingAs($this->makeRefnum());

        $attached = $this->service->addApplicationsToWorkstationGroup($group->id, [$firefox->id]);

        $this->assertSame([$firefox->id], $attached, 'AC3 : app du catalogue installée');
        $this->assertDatabaseHas('application_workstation_group', [
            'application_id' => $firefox->id,
            'workstation_group_id' => $group->id,
        ]);
    }

    // ---------------------------------------------------------------------
    // AC4 — standalone strictement inchangé + court-circuit NFR3
    // ---------------------------------------------------------------------

    #[Test]
    public function ac4_standalone_proposes_all_apps_and_install_succeeds(): void
    {
        // Aucun ControlHubContract : standalone.
        $firefox = $this->makeApp('firefox');
        $chrome = $this->makeApp('chrome');
        $group = $this->makeGroup('salle_a');

        $ids = Application::query()->inUpstreamCatalog()->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$firefox->id, $chrome->id], $ids, 'AC4 : toutes les apps proposées');

        $this->actingAs($this->makeRefnum());
        $attached = $this->service->addApplicationsToWorkstationGroup($group->id, [$chrome->id]);
        $this->assertSame([$chrome->id], $attached, 'AC4 : install standalone inchangée');
    }

    #[Test]
    public function ac4_standalone_short_circuits_without_touching_catalog_table(): void
    {
        $resolver = app(UpstreamCatalogResolver::class);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $bounded = $resolver->isBounded();
        $log = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertFalse($bounded, 'NFR3 : standalone non borné');

        $catalogQueries = array_filter(
            $log,
            static fn (array $q): bool => str_contains($q['query'], 'controlhub_contract_catalog_apps'),
        );
        $this->assertSame([], $catalogQueries, 'NFR3 : zéro requête sur la table catalogue sans contrat actif');
    }

    // ---------------------------------------------------------------------
    // AC5 — catalogue vide = pas de bornage (D1)
    // ---------------------------------------------------------------------

    #[Test]
    public function ac5_active_contract_with_empty_catalog_does_not_bind(): void
    {
        $this->activeContractWithCatalog([]); // contrat actif, catalogue vide
        $firefox = $this->makeApp('firefox');
        $chrome = $this->makeApp('chrome');
        $group = $this->makeGroup('salle_a');

        $resolver = app(UpstreamCatalogResolver::class);
        $this->assertFalse($resolver->isBounded(), 'AC5/D1 : catalogue vide ⇒ non borné');

        $ids = Application::query()->inUpstreamCatalog()->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$firefox->id, $chrome->id], $ids, 'AC5 : toutes les apps proposées');

        $this->actingAs($this->makeRefnum());
        $attached = $this->service->addApplicationsToWorkstationGroup($group->id, [$chrome->id]);
        $this->assertSame([$chrome->id], $attached, 'AC5 : install non bornée');
    }

    // ---------------------------------------------------------------------
    // AC6 — appelant non authentifié non bloqué par le catalogue
    // ---------------------------------------------------------------------

    #[Test]
    public function ac6_unauthenticated_caller_is_not_blocked_by_catalog(): void
    {
        $this->activeContractWithCatalog(['firefox']);
        $chrome = $this->makeApp('chrome'); // hors catalogue
        $group = $this->makeGroup('salle_a');

        $this->assertFalse(auth()->check(), 'pré-condition : aucun utilisateur authentifié');

        // Auth::check()===false ⇒ garde catalogue inerte (et garde WPKG 29.1 inerte).
        $attached = $this->service->addApplicationsToWorkstationGroup($group->id, [$chrome->id]);

        $this->assertSame([$chrome->id], $attached, 'AC6 : appelant non-web non bloqué (console/agent/seed)');
        $this->assertDatabaseHas('application_workstation_group', [
            'application_id' => $chrome->id,
            'workstation_group_id' => $group->id,
        ]);
    }

    // ---------------------------------------------------------------------
    // Corrections review 31.1 — chemins d'ajout additionnels gardés
    // ---------------------------------------------------------------------

    #[Test]
    public function clone_copying_out_of_catalog_app_is_refused_without_pivot_write(): void
    {
        // Review #1 : le clone AJOUTE le delta d'apps au parc cible ⇒ borné (D4).
        $this->activeContractWithCatalog(['firefox']);
        $chrome = $this->makeApp('chrome');
        $source = $this->makeGroup('salle_source');
        $target = $this->makeGroup('salle_cible');
        // Attache chrome à la source EN DIRECT (hors service) : simule une app
        // présente avant que le contrat ne devienne borné.
        $source->applications()->attach($chrome->id);

        $this->actingAs($this->makeRefnum());

        try {
            $this->service->cloneConfiguration($source->id, $target->id);
            $this->fail('Review #1 : cloner une app hors catalogue aurait dû être refusé');
        } catch (ApplicationNotInUpstreamCatalogException) {
            // attendu
        } finally {
            $this->assertDatabaseMissing('application_workstation_group', [
                'application_id' => $chrome->id,
                'workstation_group_id' => $target->id,
            ]);
        }
    }

    #[Test]
    public function create_profile_with_out_of_catalog_app_is_refused(): void
    {
        // Review #M3 : createProfile($data['application_ids']) est un canal d'install.
        $this->activeContractWithCatalog(['firefox']);
        $chrome = $this->makeApp('chrome');

        $this->actingAs($this->makeRefnum());

        try {
            $this->service->createProfile(['name' => 'profil_hors_catalogue', 'application_ids' => [$chrome->id]]);
            $this->fail('Review #M3 : composer un profil neuf avec une app hors catalogue aurait dû être refusé');
        } catch (ApplicationNotInUpstreamCatalogException) {
            // attendu
        } finally {
            $this->assertDatabaseMissing('app_profiles', ['name' => 'profil_hors_catalogue']);
        }
    }

    #[Test]
    public function update_profile_syncing_out_of_catalog_app_is_refused(): void
    {
        // Review #M3 : updateProfile($data['application_ids']) est un canal d'install.
        $this->activeContractWithCatalog(['firefox']);
        $chrome = $this->makeApp('chrome');
        $profile = AppProfile::create(['name' => 'profil_test', 'is_active' => true]);

        $this->actingAs($this->makeRefnum());

        try {
            $this->service->updateProfile($profile->id, ['application_ids' => [$chrome->id]]);
            $this->fail('Review #M3 : sync d\'une app hors catalogue sur un profil aurait dû être refusé');
        } catch (ApplicationNotInUpstreamCatalogException) {
            // attendu
        } finally {
            $this->assertDatabaseMissing('app_profile_application', [
                'application_id' => $chrome->id,
                'app_profile_id' => $profile->id,
            ]);
        }
    }

    // ---------------------------------------------------------------------
    // Résolveur unitaire — 3 états
    // ---------------------------------------------------------------------

    #[Test]
    public function resolver_standalone_state(): void
    {
        $resolver = app(UpstreamCatalogResolver::class);

        $this->assertFalse($resolver->isBounded());
        $this->assertSame([], $resolver->allowedAppIds());
        $this->assertTrue($resolver->permits('anything'), 'standalone : tout permis (pass-through)');
    }

    #[Test]
    public function resolver_empty_catalog_state(): void
    {
        $this->activeContractWithCatalog([]);
        $resolver = app(UpstreamCatalogResolver::class);

        $this->assertFalse($resolver->isBounded());
        $this->assertSame([], $resolver->allowedAppIds());
        $this->assertTrue($resolver->permits('anything'), 'catalogue vide : tout permis (D1)');
    }

    #[Test]
    public function resolver_non_empty_catalog_state(): void
    {
        $this->activeContractWithCatalog(['firefox', 'libreoffice']);
        $resolver = app(UpstreamCatalogResolver::class);

        $this->assertTrue($resolver->isBounded());
        $this->assertEqualsCanonicalizing(['firefox', 'libreoffice'], $resolver->allowedAppIds());
        $this->assertTrue($resolver->permits('firefox'));
        $this->assertFalse($resolver->permits('chrome'), 'app hors catalogue refusée');
    }

    #[Test]
    public function resolver_severed_contract_is_not_bounded(): void
    {
        // Lien rompu (severed) ⇒ active() null ⇒ bornage levé automatiquement (Epic 32).
        $contract = ControlHubContract::factory()->severed()->create();
        ControlHubContractCatalogApp::factory()->create([
            'controlhub_contract_id' => $contract->id,
            'app_key' => 'firefox',
        ]);

        $resolver = app(UpstreamCatalogResolver::class);

        $this->assertFalse($resolver->isBounded(), 'severed ⇒ non borné (32.1 gratuit)');
        $this->assertTrue($resolver->permits('chrome'));
    }
}

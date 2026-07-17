<?php

declare(strict_types=1);

namespace Tests\Feature\ControlHub;

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
 * Catalogue applicatif AMONT (controlHub) — machinerie de résolution + scope.
 *
 * Le bornage au catalogue amont ne concerne QUE l'administration des applications :
 * une fois connecté à Irundoo, la table `applications` ne contient plus que les apps
 * du contrat (filtrage au niveau de la SYNC, pas d'une clause de requête).
 *
 * L'ASSIGNATION d'apps à une entité (profil applicatif, parc/groupe, poste, défaut
 * parc) n'est JAMAIS bornée : elle propose toute app du catalogue local. Ce test
 * couvre les deux faces : (1) le resolver + le scope `inUpstreamCatalog` fonctionnent
 * toujours (outillage app-admin), (2) les chemins d'assignation ne filtrent plus.
 *
 * Tests HÔTE (php8.4 + pdo_sqlite), `RefreshDatabase`. Match sur `app_id` (string).
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
        // Isole du listener WPKG (cache/regen .ini) : seule l'écriture pivot est exercée.
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
        // Droit GLOBAL wpkg.assign ⇒ le Gate 29.1 passe sur n'importe quel parc/poste.
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
    // Machinerie app-admin : le scope `inUpstreamCatalog` filtre toujours
    // ---------------------------------------------------------------------

    #[Test]
    public function scope_only_returns_apps_in_catalog_when_bounded(): void
    {
        $this->activeContractWithCatalog(['firefox']);
        $inCatalog = $this->makeApp('firefox');
        $outOfCatalog = $this->makeApp('chrome');

        $ids = Application::query()->inUpstreamCatalog()->pluck('id')->all();

        $this->assertContains($inCatalog->id, $ids, 'app du catalogue proposée');
        $this->assertNotContains($outOfCatalog->id, $ids, 'app hors catalogue absente');
    }

    #[Test]
    public function scope_is_pass_through_when_standalone(): void
    {
        $firefox = $this->makeApp('firefox');
        $chrome = $this->makeApp('chrome');

        $ids = Application::query()->inUpstreamCatalog()->pluck('id')->all();

        $this->assertEqualsCanonicalizing([$firefox->id, $chrome->id], $ids, 'standalone : toutes les apps');
    }

    #[Test]
    public function standalone_short_circuits_without_touching_catalog_table(): void
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
    // Assignation NON bornée : une app hors catalogue peut être assignée à
    // une entité même sous un contrat amont actif (le bornage n'a lieu qu'à
    // l'échelle de l'administration des applications).
    // ---------------------------------------------------------------------

    #[Test]
    public function group_assignment_is_not_bounded_by_catalog(): void
    {
        $this->activeContractWithCatalog(['firefox']);
        $chrome = $this->makeApp('chrome'); // hors catalogue
        $group = $this->makeGroup('salle_a');

        $this->actingAs($this->makeRefnum());

        $attached = $this->service->addApplicationsToWorkstationGroup($group->id, [$chrome->id]);

        $this->assertSame([$chrome->id], $attached, 'app hors catalogue assignable au parc');
        $this->assertDatabaseHas('application_workstation_group', [
            'application_id' => $chrome->id,
            'workstation_group_id' => $group->id,
        ]);
    }

    #[Test]
    public function workstation_assignment_is_not_bounded_by_catalog(): void
    {
        $this->activeContractWithCatalog(['firefox']);
        $chrome = $this->makeApp('chrome'); // hors catalogue
        $group = $this->makeGroup('salle_a');
        $ws = Workstation::factory()->create();
        $ws->groups()->attach($group->id);

        $this->actingAs($this->makeRefnum());

        $attached = $this->service->addApplicationsToWorkstation($ws->id, [$chrome->id]);

        $this->assertSame([$chrome->id], $attached, 'app hors catalogue assignable au poste');
        $this->assertDatabaseHas('application_workstation', [
            'application_id' => $chrome->id,
            'workstation_id' => $ws->id,
        ]);
    }

    #[Test]
    public function profile_composition_is_not_bounded_by_catalog(): void
    {
        $this->activeContractWithCatalog(['firefox']);
        $chrome = $this->makeApp('chrome'); // hors catalogue
        $profile = AppProfile::create(['name' => 'profil_test', 'is_active' => true]);

        $this->actingAs($this->makeRefnum());

        $this->service->addApplications($profile->id, [$chrome->id]);

        $this->assertDatabaseHas('app_profile_application', [
            'application_id' => $chrome->id,
            'app_profile_id' => $profile->id,
        ]);
    }

    #[Test]
    public function list_applications_for_select_is_not_filtered(): void
    {
        $this->activeContractWithCatalog(['firefox']);
        $this->makeApp('firefox');
        $this->makeApp('chrome'); // hors catalogue

        $appIds = $this->service->listApplicationsForSelect()->pluck('app_id')->all();

        $this->assertEqualsCanonicalizing(['firefox', 'chrome'], $appIds, 'sélecteur de profil non borné');
    }

    // ---------------------------------------------------------------------
    // Résolveur unitaire — 3 états + lien rompu
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

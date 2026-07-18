<?php

declare(strict_types=1);

namespace Tests\Feature\ControlHub;

use App\Enums\ApplicationStatus;
use App\Models\AppProfile;
use App\Models\Application;
use App\Models\ControlHubContract;
use App\Models\ControlHubContractCatalogApp;
use App\Models\Depot;
use App\Models\DepotApplication;
use App\Models\InstallationLog;
use App\Models\Workstation;
use App\Models\WorkstationApplicationStatus;
use App\Models\WorkstationGroup;
use App\Observers\WorkstationGroupObserver;
use App\Services\AppStore\AppStoreService;
use App\Services\AppStore\PackagesXmlService;
use App\Services\ControlHub\Data\ImposedDepotReconciliationResult;
use App\Services\ControlHub\ImposedDepotReconciler;
use App\Wpkg\Deployment\Services\WorkstationPackagesResolver;
use App\Wpkg\Deployment\Services\WpkgBundleGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 51.1 — Réconciliation du dépôt IMPOSÉ par le contrat amont (controlHub) :
 * bascule EXCLUSIVE du canal dépôts (D2).
 *
 * Couvre : matérialisation depuis catalogue (projection JSON table→table, purge, champs
 * d'affichage AC1) ; transfert des communes (status/pivots intacts, depot_id re-pointé) ;
 * désinstallation en cascade du hors-catalogue (pivots, ligne supprimée, apps depot_id NULL
 * INTOUCHÉES) ; ordre transfert→désinstall→suppression (une commune n'est PAS détruite par
 * la cascade FK) ; invalidation du cache par-poste (piège #2) ; catalogue vide = pas de
 * bascule (AC9) ; standalone no-op (NFR3) ; idempotence re-jeu ; échec isolé → dépôt
 * conservé (AC11) ; R3.
 *
 * Tests HÔTE (php8.4 + pdo_sqlite, `RefreshDatabase`, `CACHE_DRIVER=array`). On mocke la
 * régénération fichier (`PackagesXmlService`/`WpkgBundleGenerator`) pour isoler du FS ;
 * la cascade Eloquent de `deleteApplication()` reste RÉELLE. SQLite n'applique pas
 * varchar/enum PG → on teste des DÉCISIONS (présence/absence/count/valeur), jamais des
 * bornes de colonne ; on matche sur `app_id`/`app_key` (string).
 *
 * ⚠️ GARDE-FOU R3 : aucun « central » ; vocabulaire « imposé » / « amont » / `Imposed` / `Upstream`.
 */
class ImposedDepotReconciliationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        WorkstationGroupObserver::disableSync();
        // Neutralise les jobs de synchro AD (observers AppProfile/Workstation/Group) —
        // environnement de test offline (patron ReconcileImposedGroupsListenerTest).
        Queue::fake();

        // Isolation FS : la cascade deleteApplication() régénère packages.xml + bundle.
        // On neutralise ces écritures disque (hors périmètre — la cascade Eloquent reste réelle).
        $this->mock(PackagesXmlService::class, fn ($m) => $m->shouldReceive('regenerate')->andReturnNull());
        $this->mock(WpkgBundleGenerator::class, fn ($m) => $m->shouldReceive('generate')->andReturnNull());

        Cache::flush();
    }

    protected function tearDown(): void
    {
        WorkstationGroupObserver::enableSync();
        parent::tearDown();
    }

    private function reconciler(): ImposedDepotReconciler
    {
        return app(ImposedDepotReconciler::class);
    }

    private function catalogApp(ControlHubContract $contract, string $appKey, array $attrs = []): ControlHubContractCatalogApp
    {
        return ControlHubContractCatalogApp::create(array_merge([
            'controlhub_contract_id' => $contract->id,
            'app_key' => $appKey,
        ], $attrs));
    }

    private function classicDepot(string $name = 'Dépôt SambaEdu'): Depot
    {
        return Depot::create([
            'name' => $name,
            'url' => 'https://deb.sambaedu.org/packages.xml',
            'is_primary' => true,
            'is_active' => true,
        ]);
    }

    // ── AC4 — matérialisation (projection + purge + champs AC1) ───────────────

    #[Test]
    public function it_materializes_a_single_imposed_depot_and_projects_the_catalog(): void
    {
        $contract = ControlHubContract::factory()->create();
        $this->catalogApp($contract, 'firefox', [
            'display_name' => 'Mozilla Firefox',
            'source_xml_url' => 'https://depot/firefox.xml',
            'source_xml_sha' => 'sha-ff',
            'version' => '128.0',
            'category' => 'Internet',
            'icon_url' => 'https://depot/firefox.png',
        ]);
        // Champs d'affichage ABSENTS (dégradation propre) : name = app_key, colonnes null.
        $this->catalogApp($contract, 'vlc');

        $result = $this->reconciler()->reconcile();

        // UN dépôt imposé unique, is_imposed + is_primary.
        $imposed = Depot::where('is_imposed', true)->get();
        self::assertCount(1, $imposed);
        self::assertSame(ImposedDepotReconciler::IMPOSED_DEPOT_NAME, $imposed->first()->name);
        self::assertTrue($imposed->first()->is_primary);
        self::assertSame(ImposedDepotReconciler::IMPOSED_DEPOT_URL, $imposed->first()->url);

        // Projection : 2 depot_applications avec champs AC1 présents/absents.
        $ff = DepotApplication::where('depot_id', $imposed->first()->id)->where('app_id', 'firefox')->first();
        self::assertNotNull($ff);
        self::assertSame('Mozilla Firefox', $ff->name);
        self::assertSame('https://depot/firefox.xml', $ff->xml_url);
        self::assertSame('sha-ff', $ff->xml_sha);
        self::assertSame('128.0', $ff->version);
        self::assertSame('Internet', $ff->category);
        self::assertSame('https://depot/firefox.png', $ff->icon_url);

        $vlc = DepotApplication::where('depot_id', $imposed->first()->id)->where('app_id', 'vlc')->first();
        self::assertNotNull($vlc);
        self::assertSame('vlc', $vlc->name, 'name = app_key quand display_name absent');
        self::assertNull($vlc->version);
        self::assertNull($vlc->category);

        self::assertSame(2, $result->materialized);
    }

    #[Test]
    public function it_purges_imposed_depot_applications_absent_from_the_catalog(): void
    {
        $contract = ControlHubContract::factory()->create();
        $this->catalogApp($contract, 'firefox', ['display_name' => 'Firefox']);

        // Pré-existant sur le dépôt imposé mais ABSENT du catalogue → doit être purgé.
        $imposed = ImposedDepotReconciler::getOrCreateImposedDepot();
        DepotApplication::create([
            'depot_id' => $imposed->id, 'app_id' => 'obsolete', 'name' => 'Obsolete', 'branch' => 'stable',
        ]);

        $result = $this->reconciler()->reconcile();

        self::assertNull(DepotApplication::where('depot_id', $imposed->id)->where('app_id', 'obsolete')->first());
        self::assertNotNull(DepotApplication::where('depot_id', $imposed->id)->where('app_id', 'firefox')->first());
        self::assertSame(1, $result->purged);
    }

    // ── AC5 — transfert des communes (jamais désinstall/réinstall) ────────────

    #[Test]
    public function a_common_application_is_transferred_without_touching_status_or_pivots(): void
    {
        $contract = ControlHubContract::factory()->create();
        $this->catalogApp($contract, 'firefox', ['display_name' => 'Firefox']);

        $classic = $this->classicDepot();
        $app = Application::create([
            'depot_id' => $classic->id,
            'app_id' => 'firefox',
            'name' => 'Firefox LOCAL',
            'version' => '120.0',
            'installed_version' => '120.0',
            'status' => ApplicationStatus::Installed,
        ]);

        // Pivots à préserver.
        $profile = AppProfile::create(['name' => 'profil-a', 'is_active' => true]);
        $app->appProfiles()->attach($profile->id);
        $ws = Workstation::factory()->create(['name' => 'PC-1']);
        $app->workstations()->attach($ws->id);

        $result = $this->reconciler()->reconcile();

        $app->refresh();
        $imposed = Depot::where('is_imposed', true)->first();
        self::assertSame($imposed->id, $app->depot_id, 'depot_id re-pointé vers le dépôt imposé');
        // RIEN d'autre ne change.
        self::assertSame(ApplicationStatus::Installed, $app->status);
        self::assertSame('120.0', $app->installed_version);
        self::assertSame('Firefox LOCAL', $app->name);
        self::assertSame(1, $app->appProfiles()->count());
        self::assertSame(1, $app->workstations()->count());
        self::assertSame(1, $result->transferred);
    }

    // ── AC6 — désinstallation en cascade du hors-catalogue ────────────────────

    #[Test]
    public function an_out_of_catalog_application_is_deleted_with_full_cascade(): void
    {
        $contract = ControlHubContract::factory()->create();
        $this->catalogApp($contract, 'firefox', ['display_name' => 'Firefox']);

        $classic = $this->classicDepot();
        $out = Application::create([
            'depot_id' => $classic->id, 'app_id' => 'malware', 'name' => 'Hors Catalogue',
            'status' => ApplicationStatus::Installed,
        ]);

        // Toutes les familles de pivots/enregistrements.
        $profile = AppProfile::create(['name' => 'profil-b', 'is_active' => true]);
        $out->appProfiles()->attach($profile->id);
        $ws = Workstation::factory()->create(['name' => 'PC-OUT']);
        $out->workstations()->attach($ws->id);
        $group = WorkstationGroup::factory()->logical()->create(['name' => 'Salle X']);
        $out->workstationGroups()->attach($group->id);
        InstallationLog::create(['application_id' => $out->id, 'status' => 'success', 'initiated_by' => 'sys']);
        WorkstationApplicationStatus::create([
            'application_id' => $out->id, 'workstation_id' => $ws->id, 'status' => 'installed',
        ]);

        $result = $this->reconciler()->reconcile();

        // Ligne + tous les pivots/enregistrements supprimés.
        self::assertNull(Application::find($out->id));
        self::assertSame(0, DB::table('app_profile_application')->where('application_id', $out->id)->count());
        self::assertSame(0, DB::table('application_workstation')->where('application_id', $out->id)->count());
        self::assertSame(0, DB::table('application_workstation_group')->where('application_id', $out->id)->count());
        self::assertSame(0, InstallationLog::where('application_id', $out->id)->count());
        self::assertSame(0, WorkstationApplicationStatus::where('application_id', $out->id)->count());
        self::assertSame(1, $result->uninstalled);
    }

    #[Test]
    public function applications_with_null_depot_id_are_never_touched(): void
    {
        $contract = ControlHubContract::factory()->create();
        $this->catalogApp($contract, 'firefox', ['display_name' => 'Firefox']);

        // App matérialisée amont (depot_id NULL, managed_by_control_hub) hors catalogue.
        $materialized = Application::create([
            'depot_id' => null, 'app_id' => 'gimp', 'name' => 'GIMP',
            'status' => ApplicationStatus::Available, 'managed_by_control_hub' => true,
        ]);
        // App locale sans dépôt (depot_id NULL) hors catalogue.
        $local = Application::create([
            'depot_id' => null, 'app_id' => 'maison', 'name' => 'App Maison',
            'status' => ApplicationStatus::Available,
        ]);

        $this->reconciler()->reconcile();

        self::assertNotNull(Application::find($materialized->id), 'app matérialisée amont INTOUCHÉE');
        self::assertNotNull(Application::find($local->id), 'app locale sans dépôt INTOUCHÉE');
        self::assertNull($materialized->fresh()->depot_id);
        self::assertNull($local->fresh()->depot_id);
    }

    #[Test]
    public function an_app_on_the_imposed_depot_that_left_the_catalog_is_uninstalled(): void
    {
        // Review 51.1 #4 (convergence stricte D2) — une app transférée sur le dépôt imposé
        // à une version ANTÉRIEURE du catalogue, puis RETIRÉE du catalogue, doit être
        // désinstallée (pas seulement voir sa depot_application purgée).
        $contract = ControlHubContract::factory()->create();
        $this->catalogApp($contract, 'firefox', ['display_name' => 'Firefox']);

        // Dépôt imposé pré-existant (bascule d'une version précédente) portant 'vlc',
        // désormais ABSENTE du catalogue {firefox}.
        $imposed = ImposedDepotReconciler::getOrCreateImposedDepot(promote: true);
        $leftover = Application::create([
            'depot_id' => $imposed->id, 'app_id' => 'vlc', 'name' => 'VLC',
            'status' => ApplicationStatus::Installed,
        ]);
        // Une app du catalogue déjà sur le dépôt imposé (transférée) : NON touchée.
        $kept = Application::create([
            'depot_id' => $imposed->id, 'app_id' => 'firefox', 'name' => 'Firefox',
            'status' => ApplicationStatus::Installed,
        ]);

        $result = $this->reconciler()->reconcile();

        self::assertNull(Application::find($leftover->id), 'app hors-catalogue du dépôt imposé désinstallée (D2 strict)');
        self::assertNotNull(Application::find($kept->id), 'app du catalogue sur le dépôt imposé conservée');
        self::assertSame(1, $result->uninstalled);
    }

    #[Test]
    public function a_duplicate_app_id_on_a_second_depot_is_destroyed_freeing_its_depot(): void
    {
        // Review 51.1 #5 (décision Henri) — deux lignes Application du MÊME app_id sur deux
        // dépôts classiques (miroir). La 1ʳᵉ est transférée ; la 2ᵉ, redondante, est
        // DÉTRUITE (unicité) → son dépôt d'origine devient supprimable (AC7).
        $contract = ControlHubContract::factory()->create();
        $this->catalogApp($contract, 'firefox', ['display_name' => 'Firefox']);

        $depot1 = $this->classicDepot('Dépôt A');
        $depot2 = $this->classicDepot('Dépôt B (miroir)');
        $a = Application::create([
            'depot_id' => $depot1->id, 'app_id' => 'firefox', 'name' => 'Firefox',
            'status' => ApplicationStatus::Installed,
        ]);
        $b = Application::create([
            'depot_id' => $depot2->id, 'app_id' => 'firefox', 'name' => 'Firefox (miroir)',
            'status' => ApplicationStatus::Installed,
        ]);

        $result = $this->reconciler()->reconcile();

        // Une seule ligne firefox survit (sur le dépôt imposé) ; le doublon est détruit.
        self::assertSame(1, Application::where('app_id', 'firefox')->count());
        self::assertSame(Depot::where('is_imposed', true)->first()->id, Application::where('app_id', 'firefox')->first()->depot_id);
        self::assertSame(1, $result->transferred);
        self::assertSame(1, $result->duplicatesRemoved);
        // Les deux dépôts classiques, désormais non référencés, sont supprimés (AC7).
        self::assertNull(Depot::find($depot1->id));
        self::assertNull(Depot::find($depot2->id));
        self::assertSame(2, $result->depotsDeleted);
    }

    #[Test]
    public function uninstalling_out_of_catalog_invalidates_affected_workstation_cache(): void
    {
        $contract = ControlHubContract::factory()->create();
        $this->catalogApp($contract, 'firefox', ['display_name' => 'Firefox']);

        $classic = $this->classicDepot();
        $out = Application::create([
            'depot_id' => $classic->id, 'app_id' => 'zombie', 'name' => 'Zombie',
            'status' => ApplicationStatus::Installed,
        ]);
        $wsDirect = Workstation::factory()->create(['name' => 'PC-DIRECT']);
        $out->workstations()->attach($wsDirect->id);
        $group = WorkstationGroup::factory()->logical()->create(['name' => 'Salle Cache']);
        $wsGroup = Workstation::factory()->create(['name' => 'PC-GROUP']);
        $wsGroup->groups()->attach($group->id);
        $out->workstationGroups()->attach($group->id);

        // Cache resolver pré-chauffé pour les 2 hostnames affectés.
        Cache::put(WorkstationPackagesResolver::cacheKey('PC-DIRECT'), 'stale', 3600);
        Cache::put(WorkstationPackagesResolver::cacheKey('PC-GROUP'), 'stale', 3600);

        $this->reconciler()->reconcile();

        // Piège #2 : le detach Eloquent n'émet pas l'événement → le réconciliateur
        // invalide explicitement les entrées AVANT le detach.
        self::assertFalse(Cache::has(WorkstationPackagesResolver::cacheKey('PC-DIRECT')));
        self::assertFalse(Cache::has(WorkstationPackagesResolver::cacheKey('PC-GROUP')));
    }

    #[Test]
    public function uninstalling_invalidates_cache_of_workstations_reached_only_via_an_app_profile(): void
    {
        // Review 51.1 #1 — le resolver agrège aussi `appProfiles.applications` et
        // `groups.appProfiles.applications` ; un poste servi UNIQUEMENT via un profil
        // (aucune assignation directe) doit voir son cache invalidé, sinon il sert un
        // profiles.xml périmé jusqu'à expiration du cache.
        $contract = ControlHubContract::factory()->create();
        $this->catalogApp($contract, 'firefox', ['display_name' => 'Firefox']);

        $classic = $this->classicDepot();
        $out = Application::create([
            'depot_id' => $classic->id, 'app_id' => 'ghost', 'name' => 'Ghost',
            'status' => ApplicationStatus::Installed,
        ]);

        // App rattachée UNIQUEMENT via un profil (pas de workstations()/workstationGroups() directs).
        $profile = AppProfile::create(['name' => 'profil-cache', 'is_active' => true]);
        $out->appProfiles()->attach($profile->id);

        // Le profil pointe un poste direct ET un parc contenant un poste.
        $wsProfile = Workstation::factory()->create(['name' => 'PC-PROFILE']);
        $profile->workstations()->attach($wsProfile->id);
        $group = WorkstationGroup::factory()->logical()->create(['name' => 'Salle Profil']);
        $wsProfileGroup = Workstation::factory()->create(['name' => 'PC-PROFILE-GROUP']);
        $wsProfileGroup->groups()->attach($group->id);
        $profile->workstationGroups()->attach($group->id);

        Cache::put(WorkstationPackagesResolver::cacheKey('PC-PROFILE'), 'stale', 3600);
        Cache::put(WorkstationPackagesResolver::cacheKey('PC-PROFILE-GROUP'), 'stale', 3600);

        $this->reconciler()->reconcile();

        self::assertFalse(Cache::has(WorkstationPackagesResolver::cacheKey('PC-PROFILE')));
        self::assertFalse(Cache::has(WorkstationPackagesResolver::cacheKey('PC-PROFILE-GROUP')));
    }

    // ── AC7 + piège #1 — ordre transfert → désinstall → suppression ───────────

    #[Test]
    public function order_invariant_a_common_app_is_not_destroyed_by_the_fk_cascade_of_depot_deletion(): void
    {
        $contract = ControlHubContract::factory()->create();
        $this->catalogApp($contract, 'firefox', ['display_name' => 'Firefox']);

        $classic = $this->classicDepot();
        // Une app COMMUNE (dans le catalogue) et une app HORS catalogue, sur le MÊME dépôt.
        $common = Application::create([
            'depot_id' => $classic->id, 'app_id' => 'firefox', 'name' => 'Firefox',
            'status' => ApplicationStatus::Installed,
        ]);
        $out = Application::create([
            'depot_id' => $classic->id, 'app_id' => 'other', 'name' => 'Other',
            'status' => ApplicationStatus::Installed,
        ]);

        $result = $this->reconciler()->reconcile();

        // La commune SURVIT (transférée AVANT la suppression du dépôt → pas de cascade FK).
        $common->refresh();
        self::assertNotNull(Application::find($common->id));
        self::assertSame(Depot::where('is_imposed', true)->first()->id, $common->depot_id);
        // La hors-catalogue est détruite ; l'ancien dépôt (plus référencé) est supprimé.
        self::assertNull(Application::find($out->id));
        self::assertNull(Depot::find($classic->id));
        self::assertSame(1, $result->transferred);
        self::assertSame(1, $result->uninstalled);
        self::assertSame(1, $result->depotsDeleted);
    }

    #[Test]
    public function old_non_imposed_depots_are_really_deleted_not_soft_disabled(): void
    {
        $contract = ControlHubContract::factory()->create();
        $this->catalogApp($contract, 'firefox', ['display_name' => 'Firefox']);
        $classic = $this->classicDepot();
        $empty = Depot::create(['name' => 'Vide', 'url' => 'https://x/packages.xml', 'is_active' => true]);

        $result = $this->reconciler()->reconcile();

        self::assertNull(Depot::find($classic->id));
        self::assertNull(Depot::find($empty->id));
        self::assertSame(2, $result->depotsDeleted);
        // Le dépôt imposé subsiste.
        self::assertSame(1, Depot::where('is_imposed', true)->count());
    }

    // ── AC9 — catalogue vide = verrou sans bascule ────────────────────────────

    #[Test]
    public function an_empty_catalog_triggers_no_switchover_at_all(): void
    {
        $contract = ControlHubContract::factory()->create(); // actif, catalogue VIDE
        $classic = $this->classicDepot();
        $app = Application::create([
            'depot_id' => $classic->id, 'app_id' => 'firefox', 'name' => 'Firefox',
            'status' => ApplicationStatus::Installed,
        ]);

        $result = $this->reconciler()->reconcile();

        // AUCUNE matérialisation, AUCUN transfert, AUCUNE désinstall, AUCUNE suppression.
        self::assertSame(0, Depot::where('is_imposed', true)->count());
        self::assertNotNull(Application::find($app->id));
        self::assertSame($classic->id, $app->fresh()->depot_id);
        self::assertNotNull(Depot::find($classic->id));
        self::assertSame(0, $result->materialized);
        self::assertSame(0, $result->transferred);
        self::assertSame(0, $result->uninstalled);
        self::assertSame(0, $result->depotsDeleted);
    }

    // ── NFR3 — standalone no-op total ─────────────────────────────────────────

    #[Test]
    public function without_an_active_contract_reconcile_is_a_total_noop(): void
    {
        self::assertNull(ControlHubContract::active());

        $classic = $this->classicDepot();
        $app = Application::create([
            'depot_id' => $classic->id, 'app_id' => 'firefox', 'name' => 'Firefox',
            'status' => ApplicationStatus::Installed,
        ]);

        $result = $this->reconciler()->reconcile();

        self::assertSame(0, Depot::where('is_imposed', true)->count());
        self::assertNotNull(Application::find($app->id));
        self::assertNotNull(Depot::find($classic->id));
        self::assertInstanceOf(ImposedDepotReconciliationResult::class, $result);
        self::assertSame(0, $result->materialized);
    }

    // ── AC11 — idempotence re-jeu ─────────────────────────────────────────────

    #[Test]
    public function replaying_reconcile_on_a_converged_state_is_a_zero_op(): void
    {
        $contract = ControlHubContract::factory()->create();
        $this->catalogApp($contract, 'firefox', ['display_name' => 'Firefox']);
        $classic = $this->classicDepot();
        Application::create([
            'depot_id' => $classic->id, 'app_id' => 'firefox', 'name' => 'Firefox',
            'status' => ApplicationStatus::Installed,
        ]);

        $first = $this->reconciler()->reconcile();
        self::assertSame(1, $first->transferred);

        $second = $this->reconciler()->reconcile();

        // AC11 — re-jeu convergé = ZÉRO-OP STRICT : aucune écriture, TOUS les compteurs à
        // zéro, y compris `materialized` (la matérialisation ne compte QUE sur diff réel,
        // pas sur le simple rafraîchissement de `last_checked_at` — review 51.1 #3).
        self::assertSame(0, $second->materialized);
        self::assertSame(0, $second->transferred);
        self::assertSame(0, $second->uninstalled);
        self::assertSame(0, $second->depotsDeleted);
        self::assertSame(0, $second->purged);
        self::assertSame(0, $second->duplicatesRemoved);
        // Idempotence des lignes : upsert sans doublon.
        self::assertSame(1, DepotApplication::where('app_id', 'firefox')->count());
        self::assertSame(1, Application::where('app_id', 'firefox')->count());
    }

    #[Test]
    public function the_shared_entry_point_does_not_steal_primary_without_promotion(): void
    {
        // Review 51.1 #6 — le chemin WGSync (SyncWorkstationGroupJob embarquant des apps)
        // consomme getOrCreateImposedDepot() SANS promotion : dans la fenêtre « contrat
        // actif + catalogue VIDE » (AC9), le dépôt imposé ne doit PAS voler is_primary au
        // dépôt classique encore vivant (sinon getDefaultDepot() bascule sur un dépôt vide).
        $classic = $this->classicDepot(); // is_primary => true (helper)

        // Voie WGSync (promote défaut = false).
        $imposed = ImposedDepotReconciler::getOrCreateImposedDepot();
        self::assertFalse($imposed->is_primary, 'le dépôt imposé ne se promeut PAS hors bascule');
        self::assertTrue($classic->fresh()->is_primary, 'le dépôt classique garde is_primary');
        self::assertTrue($imposed->is_imposed);

        // Voie bascule (promote = true) : promotion effective.
        $promoted = ImposedDepotReconciler::getOrCreateImposedDepot(promote: true);
        self::assertTrue($promoted->fresh()->is_primary);
        self::assertSame($imposed->id, $promoted->id, 'toujours le MÊME dépôt (point d\'entrée unique)');
    }

    // ── AC11 — échec de désinstall isolé → dépôt d'origine conservé ───────────

    #[Test]
    public function a_failed_uninstall_isolates_and_keeps_its_origin_depot(): void
    {
        $contract = ControlHubContract::factory()->create();
        $this->catalogApp($contract, 'firefox', ['display_name' => 'Firefox']);

        $classic = $this->classicDepot();
        $boom = Application::create([
            'depot_id' => $classic->id, 'app_id' => 'boom', 'name' => 'Boom',
            'status' => ApplicationStatus::Installed,
        ]);
        $other = Application::create([
            'depot_id' => $classic->id, 'app_id' => 'ok', 'name' => 'Ok',
            'status' => ApplicationStatus::Installed,
        ]);

        // deleteApplication throw pour 'boom', succès pour 'ok'.
        $this->mock(AppStoreService::class, function ($mock) use ($boom): void {
            $mock->shouldReceive('deleteApplication')
                ->andReturnUsing(function (Application $app) use ($boom): void {
                    if ($app->id === $boom->id) {
                        throw new \RuntimeException('échec de désinstallation simulé');
                    }
                    // Cascade minimale pour les autres : suppression de la ligne.
                    $app->delete();
                });
        });

        $result = $this->reconciler()->reconcile();

        // 'ok' désinstallée, 'boom' en échec → dépôt d'origine CONSERVÉ (encore référencé).
        self::assertSame(1, $result->uninstalled);
        self::assertSame(1, $result->failed);
        self::assertNotEmpty($result->errors);
        self::assertNull(Application::find($other->id));
        self::assertNotNull(Application::find($boom->id));
        self::assertNotNull(Depot::find($classic->id), 'dépôt conservé car app en échec encore référencée');
        self::assertSame(0, $result->depotsDeleted);
    }

    // ── AC3 — ordre des listeners (invariant) ─────────────────────────────────

    #[Test]
    public function the_imposed_depot_listener_is_registered_after_ordered_provisioning(): void
    {
        $provider = new \App\Providers\EventServiceProvider(app());
        $reflection = new \ReflectionClass($provider);
        $property = $reflection->getProperty('listen');
        $property->setAccessible(true);
        /** @var array<class-string, list<class-string>> $listen */
        $listen = $property->getValue($provider);

        $chain = $listen[\App\Events\ControlHubContractChanged::class] ?? [];

        $posProvision = array_search(\App\Listeners\ProvisionOrderedApplications::class, $chain, true);
        $posDepot = array_search(\App\Listeners\ReconcileImposedDepot::class, $chain, true);

        self::assertNotFalse($posDepot, 'ReconcileImposedDepot doit être abonné à ControlHubContractChanged');
        self::assertNotFalse($posProvision, 'ProvisionOrderedApplications doit être abonné');
        self::assertGreaterThan(
            $posProvision,
            $posDepot,
            'ReconcileImposedDepot doit être enregistré APRÈS ProvisionOrderedApplications (ordre invariant).',
        );
    }

    #[Test]
    public function the_listener_materializes_the_imposed_depot_on_contract_change(): void
    {
        $contract = ControlHubContract::factory()->create();
        $this->catalogApp($contract, 'firefox', ['display_name' => 'Firefox']);

        self::assertSame(0, Depot::where('is_imposed', true)->count());

        \App\Events\ControlHubContractChanged::dispatch($contract);

        self::assertSame(1, Depot::where('is_imposed', true)->count());
        self::assertNotNull(
            DepotApplication::where('app_id', 'firefox')->first(),
            'le listener ReconcileImposedDepot a projeté le catalogue',
        );
    }

    // ── R3 — aucun identifiant « central » ────────────────────────────────────

    #[Test]
    public function r3_no_central_identifier_in_delivered_classes(): void
    {
        $fqcns = [
            ImposedDepotReconciler::class,
            ImposedDepotReconciliationResult::class,
            \App\Listeners\ReconcileImposedDepot::class,
            \App\Console\Commands\ReconcileImposedDepot::class,
        ];

        foreach ($fqcns as $fqcn) {
            $this->assertStringNotContainsStringIgnoringCase('central', $fqcn);

            $reflection = new \ReflectionClass($fqcn);
            foreach ($reflection->getMethods() as $method) {
                $this->assertStringNotContainsStringIgnoringCase('central', $method->getName(), "Méthode {$fqcn}::{$method->getName()}");
            }
            foreach (array_keys($reflection->getConstants()) as $constName) {
                $this->assertStringNotContainsStringIgnoringCase('central', (string) $constName, "Constante {$fqcn}::{$constName}");
            }
        }
    }
}

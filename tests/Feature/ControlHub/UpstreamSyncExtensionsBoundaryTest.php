<?php

declare(strict_types=1);

namespace Tests\Feature\ControlHub;

use App\Enums\ApplicationStatus;
use App\Models\AppProfile;
use App\Models\Application;
use App\Models\ControlHubContract;
use App\Models\ControlHubLinkAuditLog;
use App\Models\Depot;
use App\Models\DepotApplication;
use App\Models\Extension;
use App\Models\ExtensionSource;
use App\Observers\WorkstationGroupObserver;
use App\Services\AppStore\PackagesXmlService;
use App\Services\ControlHub\ControlHubContractIngestionService;
use App\Services\ControlHub\ControlHubContractSeveranceService;
use App\Services\ControlHub\SyncManifestService;
use App\Wpkg\Deployment\Services\WpkgBundleGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 54.1 (AC3 / NFR14) — **FRONTIÈRE** : la sync amont (controlHub) ne
 * touche JAMAIS le registre d'extensions.
 *
 * Pourquoi ce test existe : le catalogue applicatif LOCAL a déjà été effacé par
 * la sync amont dans ce projet. La chaîne destructive réelle est :
 *
 *  - {@see ControlHubContractIngestionService::ingest()} — seul écrivain des 5
 *    tables `controlhub_contract*` — émet `ControlHubContractChanged` ;
 *  - `EventServiceProvider` y attache **3 listeners SYNCHRONES** :
 *    `ReconcileImposedWorkstationGroups`, `ProvisionOrderedApplications`,
 *    `ReconcileImposedDepot` ;
 *  - `ImposedDepotReconciler` est le destructeur : purge `depot_applications`,
 *    désinstalle les `Application` hors catalogue amont, supprime les dépôts
 *    non imposés ;
 *  - autres chemins d'écriture : {@see ControlHubContractSeveranceService}
 *    (rupture) et {@see SyncManifestService::apply()} + `pass3Cleanup()`
 *    (suppression des entités ControlHub absentes du manifeste).
 *
 * L'isolement du registre d'extensions est **par construction** (aucune FK,
 * aucun listener, aucun service commun). Ce test le **prouve** et le
 * **verrouille** : il échouera si un futur listener de la sync déborde sur
 * `extensions` / `extension_sources`.
 *
 * ⚠️ **PAS de `Event::fake()`** : on veut la cascade RÉELLE de listeners.
 * `Queue::fake()` + `WorkstationGroupObserver::disableSync()` neutralisent la
 * seule dépendance externe (synchro AD), et les écritures disque WPKG sont
 * mockées — la cascade Eloquent, elle, reste réelle.
 *
 * ⚠️ Needles **QUOTÉS** (`'"extensions"'`) : `extensions` nu matcherait
 * `extension_sources` (faux positif), et un filtre non quoté raterait le vrai
 * cas de figure.
 *
 * ⚠️ GARDE-FOU R3 : aucun « central » — vocabulaire « amont » / `Upstream`.
 */
class UpstreamSyncExtensionsBoundaryTest extends TestCase
{
    use RefreshDatabase;

    /** Identifiants QUOTÉS des deux tables du registre. */
    private const REGISTRY_NEEDLES = ['"extensions"', '"extension_sources"'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        // Environnement offline : pas d'AD, pas d'écriture disque WPKG.
        WorkstationGroupObserver::disableSync();
        Queue::fake();
        $this->mock(PackagesXmlService::class, fn ($m) => $m->shouldReceive('regenerate')->andReturnNull());
        $this->mock(WpkgBundleGenerator::class, fn ($m) => $m->shouldReceive('generate')->andReturnNull());
    }

    protected function tearDown(): void
    {
        WorkstationGroupObserver::enableSync();
        parent::tearDown();
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    /** Pose la source embarquée + l'extension Documentation. */
    private function seedRegistry(): void
    {
        $source = ExtensionSource::factory()->bundled()->create();
        Extension::factory()->create([
            'extension_source_id' => $source->id,
            'key' => 'doc',
            'name' => 'Documentation',
        ]);
    }

    /**
     * Snapshot brut du registre (lignes + `updated_at`), pris HORS de la
     * fenêtre de query-log.
     *
     * @return array{sources: array<int, array<string, mixed>>, extensions: array<int, array<string, mixed>>}
     */
    private function registrySnapshot(): array
    {
        return [
            'sources' => DB::table('extension_sources')->orderBy('id')->get()
                ->map(fn ($row): array => (array) $row)->all(),
            'extensions' => DB::table('extensions')->orderBy('id')->get()
                ->map(fn ($row): array => (array) $row)->all(),
        ];
    }

    /**
     * Exécute `$callback` en enregistrant les requêtes SQL, et renvoie celles
     * qui visent le registre d'extensions.
     *
     * @return list<string>
     */
    private function registryQueriesDuring(callable $callback): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        try {
            $callback();
        } finally {
            $log = DB::getQueryLog();
            DB::disableQueryLog();
        }

        $hits = [];
        foreach ($log as $entry) {
            $sql = (string) ($entry['query'] ?? '');
            foreach (self::REGISTRY_NEEDLES as $needle) {
                if (str_contains($sql, $needle)) {
                    $hits[] = $sql;
                    break;
                }
            }
        }

        return $hits;
    }

    /** Un payload de contrat amont complet (items, labels, groupes, catalogue). */
    private function contractPayload(array $catalogAppKeys): array
    {
        return [
            'items' => [
                [
                    'type' => 'capabilities',
                    'key' => 'show_file_extensions',
                    'value' => 'on',
                    'enforcement_state' => 'locked',
                    'target_type' => 'instance',
                    'target_label' => null,
                ],
            ],
            'labels' => [
                ['name' => 'salle-info', 'mode' => 'reserved'],
            ],
            'imposed_groups' => [
                ['name' => 'parc-terminales', 'label_name' => 'salle-info'],
            ],
            'catalog_apps' => array_map(
                static fn (string $key): array => ['app_key' => $key, 'display_name' => ucfirst($key)],
                $catalogAppKeys,
            ),
        ];
    }

    // ── AC3 — le méta-test : le filtre ne ment pas ────────────────────────

    #[Test]
    public function the_query_filter_actually_detects_registry_queries(): void
    {
        $this->seedRegistry();

        // Contrôle NÉGATIF : sans ce méta-test, un filtre trop étroit rendrait
        // le test principal vert par construction (fausse garantie).
        $hits = $this->registryQueriesDuring(function (): void {
            Extension::query()->count();
            ExtensionSource::query()->count();
        });

        self::assertNotSame([], $hits, 'le filtre doit détecter une VRAIE requête sur le registre');
    }

    #[Test]
    public function the_quoted_needles_do_not_confuse_the_two_tables(): void
    {
        $this->seedRegistry();

        $onlySources = $this->registryQueriesDuring(function (): void {
            ExtensionSource::query()->count();
        });

        self::assertNotSame([], $onlySources);
        foreach ($onlySources as $sql) {
            // `extension_sources` ne doit JAMAIS être compté comme `extensions` :
            // l'identifiant quoté `"extensions"` n'y apparaît pas.
            self::assertStringNotContainsString('"extensions"', $sql);
        }
    }

    // ── AC3 — la sync amont ne franchit pas la frontière ──────────────────

    #[Test]
    public function contract_ingestion_and_its_listener_cascade_never_touch_the_registry(): void
    {
        $this->seedRegistry();

        // Inventaire local hors catalogue amont : c'est exactement le cas qui a
        // déjà provoqué l'effacement du catalogue applicatif local.
        $depot = Depot::create(['name' => 'depot-local', 'url' => 'https://depot.example.test']);
        $localApp = Application::create([
            'app_id' => 'firefox',
            'name' => 'Firefox',
            'status' => ApplicationStatus::Available,
            'depot_id' => $depot->id,
        ]);
        DepotApplication::create([
            'depot_id' => $depot->id,
            'app_id' => 'firefox',
            'name' => 'Firefox',
        ]);

        $before = $this->registrySnapshot();

        $hits = $this->registryQueriesDuring(function (): void {
            $ingestion = app(ControlHubContractIngestionService::class);

            // 1. Catalogue amont NE CONTENANT PAS les clés locales → la cascade
            //    (imposed groups + provisioning + ImposedDepotReconciler) mord.
            $ingestion->ingest($this->contractPayload(['libreoffice', 'gimp']));

            // 2. Catalogue VIDE → autre branche du réconciliateur.
            $ingestion->ingest($this->contractPayload([]));
        });

        self::assertSame([], $hits, 'NFR14 : zéro requête sur "extensions" / "extension_sources"');
        self::assertEquals($before, $this->registrySnapshot(), 'registre identique (lignes + updated_at)');

        // Sanity : la cascade a bien tourné POUR DE VRAI — sans ça, l'absence
        // de requête sur le registre ne prouverait rien.
        self::assertDatabaseHas('workstation_groups', ['name' => 'parc-terminales']);
        self::assertNotNull(
            ControlHubContract::active(),
            'un contrat amont actif a bien été ingéré',
        );
        self::assertSame(
            0,
            DB::table('controlhub_contract_catalog_apps')->count(),
            'la 2e ingestion (catalogue vide) a bien pruné les apps du catalogue amont',
        );
        // ⚠️ PREUVE LA PLUS FORTE : le réconciliateur destructeur a réellement
        // MORDU — l'application locale hors catalogue amont a été retirée de
        // l'inventaire (c'est exactement le sinistre déjà vécu sur le catalogue
        // applicatif). Le registre d'extensions, lui, est resté intact.
        self::assertNull(
            $localApp->fresh(),
            'la cascade destructive a bien tourné sur le catalogue applicatif',
        );
    }

    #[Test]
    public function link_severance_never_touches_the_registry(): void
    {
        $this->seedRegistry();

        app(ControlHubContractIngestionService::class)->ingest($this->contractPayload(['libreoffice']));

        $before = $this->registrySnapshot();

        $hits = $this->registryQueriesDuring(function (): void {
            app(ControlHubContractSeveranceService::class)->sever(
                ControlHubLinkAuditLog::ORIGIN_COMMAND,
                'qa-boundary',
                'test de frontière 54.1',
            );
        });

        self::assertSame([], $hits, 'NFR14 : la rupture de lien ne lit ni n\'écrit le registre');
        self::assertEquals($before, $this->registrySnapshot());

        // Sanity : la rupture a réellement eu lieu (pas un no-op silencieux).
        self::assertNull(ControlHubContract::active(), 'le lien amont est rompu');
        self::assertSame(1, ControlHubLinkAuditLog::query()->count(), 'la transition est tracée');
    }

    #[Test]
    public function sync_manifest_apply_and_cleanup_never_touch_the_registry(): void
    {
        $this->seedRegistry();

        $before = $this->registrySnapshot();

        $hits = $this->registryQueriesDuring(function (): void {
            $service = app(SyncManifestService::class);

            // Manifeste NON vide (les 3 passes tournent) …
            $service->apply([
                'shortcuts' => [],
                'app_profiles' => [
                    [
                        'controlhub_id' => 4242,
                        'name' => 'profil-amont',
                        'applications' => [
                            ['app_id' => 'libreoffice', 'name' => 'LibreOffice'],
                        ],
                    ],
                ],
                'workstation_groups' => [],
            ], 'v1');

            // … puis manifeste VIDE : `pass3Cleanup` supprime les entités
            // ControlHub absentes — la branche la plus destructive.
            $service->apply([], 'v1');
        });

        self::assertSame([], $hits, 'NFR14 : ni apply() ni pass3Cleanup() ne visent le registre');
        self::assertEquals($before, $this->registrySnapshot());

        // Sanity : le manifeste a réellement créé PUIS détruit une entité
        // ControlHub — c'est bien la branche destructive qui a été exercée.
        self::assertSame(
            0,
            AppProfile::query()->whereNotNull('controlhub_id')->count(),
            'le profil amont créé par la 1re passe a été supprimé par pass3Cleanup',
        );
        self::assertDatabaseHas('applications', ['app_id' => 'libreoffice']);
    }

    #[Test]
    public function the_whole_upstream_sync_sequence_leaves_the_registry_byte_identical(): void
    {
        $this->seedRegistry();

        $before = $this->registrySnapshot();

        $hits = $this->registryQueriesDuring(function (): void {
            $ingestion = app(ControlHubContractIngestionService::class);
            $ingestion->ingest($this->contractPayload(['libreoffice']));
            $ingestion->ingest($this->contractPayload([]));

            app(SyncManifestService::class)->apply([], 'v1');

            app(ControlHubContractSeveranceService::class)->sever(
                ControlHubLinkAuditLog::ORIGIN_COMMAND,
                'qa-boundary',
            );
        });

        self::assertSame([], $hits);
        self::assertEquals($before, $this->registrySnapshot());
        self::assertSame(1, ExtensionSource::query()->count());
        self::assertSame(1, Extension::query()->count());
        self::assertNotNull(Extension::where('key', 'doc')->first());
    }
}

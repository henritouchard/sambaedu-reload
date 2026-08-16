<?php

declare(strict_types=1);

namespace Tests\Feature\ControlHub;

use App\Enums\ApplicationStatus;
use App\Enums\ControlHubContractTarget;
use App\Enums\ControlHubEnforcementState;
use App\Models\Application;
use App\Models\ControlHubContract;
use App\Models\ControlHubContractCatalogApp;
use App\Models\ControlHubContractItem;
use App\Models\Workstation;
use App\Observers\WorkstationGroupObserver;
use App\Services\Agent\CloudSyncClient;
use App\Services\Agent\Providers\ApplicationsStateProvider;
use App\Services\Agent\StateCandidate;
use App\Services\Agent\TargetContext;
use App\Services\AppStore\AppStoreService;
use App\Services\AppStore\DepotSyncService;
use App\Services\ControlHub\OrderedApplicationProvisioner;
use App\Services\ControlHub\Resolution\UpstreamContractSource;
use App\Wpkg\Deployment\Services\WorkstationPackagesResolver;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\WpkgSchemaBootstrapper;
use Tests\TestCase;

/**
 * Story 31.3 — Approvisionnement d'une app ordonnée depuis le dépôt SambaEdu (FR6, gap D4).
 *
 * Un ORDRE d'install amont (`controlhub_contract_items` `type='applications'`, non-`absent`)
 * vers un `app_id` ABSENT de l'inventaire local est MATÉRIALISÉ en `Application` (status
 * `Available`, recette WPKG `xml_url`/`xml_sha`) depuis la RÉFÉRENCE DE SOURCE par-app
 * portée par le catalogue amont (`controlhub_contract_catalog_apps`, « Option B », D1) —
 * SANS install serveur, SANS `Depot`/`DepotApplication`/`DepotSyncService`.
 *
 * Couvre : matérialisation (AC1), honneur via 31.2 (AC2), idempotence/non-écrasement (AC3),
 * standalone + DepotSyncService jamais appelé (AC4), ordre sans source = log sans crash +
 * résilience (AC6), déclenchement par le listener `ControlHubContractChanged`.
 * (AC5 — ingestion source idempotente — vit dans {@see ControlHubContractIngestionTest}.
 *  AC7 — contrat agent figé — vit dans sa propre suite : aucun contrat dans ses fixtures.)
 *
 * Tests HÔTE (php8.4 + pdo_sqlite). Harnais {@see WpkgSchemaBootstrapper} (sans
 * `RefreshDatabase` — offline, aucune synchro AD). SQLite n'applique pas varchar/enum PG →
 * on teste des DÉCISIONS (présence/absence/status/count), jamais des bornes de colonne ; on
 * matche sur `app_id` (string).
 *
 * ⚠️ GARDE-FOU R3 : aucun « central » ; vocabulaire « amont » / `Upstream` / `ControlHub*`.
 */
class UpstreamOrderProvisioningTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        WorkstationGroupObserver::disableSync();
        WpkgSchemaBootstrapper::bootstrap();
        Cache::flush();
    }

    protected function tearDown(): void
    {
        WpkgSchemaBootstrapper::tearDown();
        WorkstationGroupObserver::enableSync();
        parent::tearDown();
    }

    // ── AC1 — matérialisation depuis la source ───────────────────────────────

    #[Test]
    public function an_ordered_app_absent_locally_is_materialized_from_its_source(): void
    {
        $contract = ControlHubContract::factory()->create(); // link_state = active
        $this->orderInstance($contract, 'firefox');
        $this->catalogWithSource($contract, 'firefox', 'Mozilla Firefox', 'https://depot.example/firefox.xml', 'sha-firefox');

        // « Option B » (D1) — la matérialisation est DIRECTE : même dans un chemin où une
        // app est RÉELLEMENT matérialisée, aucun pipeline de dépôt n'est sollicité.
        $depotSpy = $this->spy(DepotSyncService::class);

        self::assertFalse(Application::query()->where('app_id', 'firefox')->exists());

        $result = $this->provisioner()->provision();

        $app = Application::query()->where('app_id', 'firefox')->first();
        self::assertNotNull($app, 'l\'app ordonnée est matérialisée depuis la source');
        self::assertSame('Mozilla Firefox', $app->name);
        self::assertSame('https://depot.example/firefox.xml', $app->xml_url);
        self::assertSame('sha-firefox', $app->xml_sha);
        // Status Available (PAS Downloading) : aucune install serveur déclenchée.
        self::assertSame(ApplicationStatus::Available, $app->status);
        self::assertSame(1, $result->provisioned);

        // AC4 (garde-fou Option B) — prouvé DANS le chemin de matérialisation réel :
        // DepotSyncService n'est jamais appelé même quand une app est effectivement créée.
        $depotSpy->shouldNotHaveReceived('syncDepot');
        $depotSpy->shouldNotHaveReceived('syncAllDepots');
    }

    #[Test]
    public function a_label_targeted_order_is_also_materialized_instance_wide(): void
    {
        // L'inventaire est instance-wide : la matérialisation ne dépend pas du ciblage
        // par poste (c'est 31.2 qui projette par cible).
        $contract = ControlHubContract::factory()->create();
        $this->orderLabel($contract, 'gimp', 'salle-arts');
        $this->catalogWithSource($contract, 'gimp', 'GIMP', 'https://depot.example/gimp.xml', 'sha-gimp');

        $this->provisioner()->provision();

        self::assertTrue(Application::query()->where('app_id', 'gimp')->exists());
    }

    // ── AC2 — l'ordre est pleinement honoré via 31.2 (plus de skip+warn) ─────

    #[Test]
    public function once_materialized_the_ordered_app_is_emitted_in_the_desired_state(): void
    {
        $contract = ControlHubContract::factory()->create();
        $this->orderInstance($contract, 'firefox');
        $this->catalogWithSource($contract, 'firefox', 'Mozilla Firefox', 'https://depot.example/firefox.xml', 'sha-firefox');

        $ws = Workstation::create(['name' => 'PC31-3-A', 'status' => 'active']);

        // Avant matérialisation : l'ordre 31.2 retombe sur skip+warn (rien à hydrater).
        self::assertSame([], $this->appIdsOf($this->provider()->itemsFor($this->ctx($ws))));

        // Approvisionnement → la ligne Application existe désormais.
        $this->provisioner()->provision();

        // L'hydratation 27.5 trouve la ligne : l'app figure dans le désiré (machine).
        self::assertSame(['firefox'], $this->appIdsOf($this->provider()->itemsFor($this->ctx($ws))));
    }

    // ── AC3 — idempotence / non-écrasement ───────────────────────────────────

    #[Test]
    public function a_preexisting_local_application_is_never_overwritten(): void
    {
        $contract = ControlHubContract::factory()->create();
        $this->orderInstance($contract, 'firefox');
        $this->catalogWithSource($contract, 'firefox', 'Mozilla Firefox', 'https://depot.example/firefox.xml', 'sha-firefox');

        // App locale préexistante avec statut + métadonnées CUSTOM.
        $existing = Application::create([
            'app_id' => 'firefox',
            'name' => 'Firefox MAISON',
            'status' => ApplicationStatus::Installed,
            'xml_url' => 'https://local/custom.xml',
        ]);

        $result = $this->provisioner()->provision();

        $existing->refresh();
        // Aucun écrasement : status + métadonnées préservés.
        self::assertSame('Firefox MAISON', $existing->name);
        self::assertSame(ApplicationStatus::Installed, $existing->status);
        self::assertSame('https://local/custom.xml', $existing->xml_url);
        self::assertSame(0, $result->provisioned);
        self::assertSame(1, $result->alreadyPresent);
        // Une seule ligne firefox (aucun doublon).
        self::assertSame(1, Application::query()->where('app_id', 'firefox')->count());
    }

    #[Test]
    public function replaying_the_provisioning_is_idempotent(): void
    {
        $contract = ControlHubContract::factory()->create();
        $this->orderInstance($contract, 'firefox');
        $this->catalogWithSource($contract, 'firefox', 'Mozilla Firefox', 'https://depot.example/firefox.xml', 'sha-firefox');

        $first = $this->provisioner()->provision();
        $second = $this->provisioner()->provision();

        self::assertSame(1, $first->provisioned);
        self::assertSame(0, $second->provisioned);
        self::assertSame(1, $second->alreadyPresent);
        self::assertSame(1, Application::query()->where('app_id', 'firefox')->count());
    }

    // ── AC4 — standalone + DepotSyncService JAMAIS appelé ────────────────────

    #[Test]
    public function without_an_active_contract_nothing_is_provisioned_and_depot_sync_is_never_called(): void
    {
        $spy = $this->spy(DepotSyncService::class);

        self::assertNull(ControlHubContract::active());

        $result = $this->provisioner()->provision();

        self::assertSame(0, $result->provisioned);
        self::assertSame(0, Application::query()->count());
        $spy->shouldNotHaveReceived('syncDepot');
        $spy->shouldNotHaveReceived('syncAllDepots');
    }

    #[Test]
    public function a_contract_without_application_orders_provisions_nothing_and_never_syncs_a_depot(): void
    {
        $spy = $this->spy(DepotSyncService::class);

        $contract = ControlHubContract::factory()->create();
        // Un item d'un AUTRE type (registry) — pas un ordre d'install.
        ControlHubContractItem::factory()->create([
            'controlhub_contract_id' => $contract->id,
            'type' => 'registry',
            'key' => 'HKCU|P|X|REG_DWORD',
            'value' => '1',
            'enforcement_state' => ControlHubEnforcementState::Locked,
            'target_type' => ControlHubContractTarget::Instance,
            'target_label' => '',
        ]);

        $result = $this->provisioner()->provision();

        self::assertSame(0, $result->provisioned);
        self::assertSame(0, Application::query()->count());
        $spy->shouldNotHaveReceived('syncDepot');
        $spy->shouldNotHaveReceived('syncAllDepots');
    }

    #[Test]
    public function an_absent_order_is_not_a_provisioning_order(): void
    {
        $contract = ControlHubContract::factory()->create();
        // `absent` = l'autorité n'impose pas cette app → ce n'est pas un ordre.
        ControlHubContractItem::factory()->absent()->create([
            'controlhub_contract_id' => $contract->id,
            'type' => Application::TYPE_APPLICATIONS,
            'key' => 'firefox',
            'target_type' => ControlHubContractTarget::Instance,
            'target_label' => '',
        ]);
        $this->catalogWithSource($contract, 'firefox', 'Mozilla Firefox', 'https://depot.example/firefox.xml', 'sha-firefox');

        $result = $this->provisioner()->provision();

        self::assertSame(0, $result->provisioned);
        self::assertFalse(Application::query()->where('app_id', 'firefox')->exists());
    }

    // ── AC6 — ordre sans source = log sans crash + résilience par app ────────

    #[Test]
    public function an_order_without_a_catalog_entry_is_skipped_and_logged(): void
    {
        Log::spy();

        $contract = ControlHubContract::factory()->create();
        $this->orderInstance($contract, 'ghost'); // aucune entrée catalogue

        $result = $this->provisioner()->provision();

        self::assertSame(0, $result->provisioned);
        self::assertSame(1, $result->skipped);
        self::assertFalse(Application::query()->where('app_id', 'ghost')->exists());

        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message, $context = []) => $message === 'agent.applications.provision_skipped'
                && ($context['app_id'] ?? null) === 'ghost'
                && ($context['reason'] ?? null) === 'no_catalog_entry')
            ->once();
    }

    #[Test]
    public function an_order_whose_catalog_entry_has_no_source_is_skipped_and_logged(): void
    {
        Log::spy();

        $contract = ControlHubContract::factory()->create();
        $this->orderInstance($contract, 'nosrc');
        // Entrée catalogue SANS source_xml_url (champ optionnel non renseigné).
        ControlHubContractCatalogApp::factory()->create([
            'controlhub_contract_id' => $contract->id,
            'app_key' => 'nosrc',
            'display_name' => 'Sans Source',
            'source_xml_url' => null,
            'source_xml_sha' => null,
        ]);

        $result = $this->provisioner()->provision();

        self::assertSame(0, $result->provisioned);
        self::assertSame(1, $result->skipped);
        self::assertFalse(Application::query()->where('app_id', 'nosrc')->exists());

        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message, $context = []) => $message === 'agent.applications.provision_skipped'
                && ($context['app_id'] ?? null) === 'nosrc'
                && ($context['reason'] ?? null) === 'no_source_xml_url')
            ->once();
    }

    #[Test]
    public function a_skipped_order_does_not_prevent_other_apps_from_being_materialized(): void
    {
        $contract = ControlHubContract::factory()->create();
        $this->orderInstance($contract, 'ghost');   // sans catalogue → skip
        $this->orderInstance($contract, 'firefox'); // avec source → matérialisée
        $this->catalogWithSource($contract, 'firefox', 'Mozilla Firefox', 'https://depot.example/firefox.xml', 'sha-firefox');

        $result = $this->provisioner()->provision();

        self::assertSame(1, $result->provisioned);
        self::assertSame(1, $result->skipped);
        self::assertTrue(Application::query()->where('app_id', 'firefox')->exists());
        self::assertFalse(Application::query()->where('app_id', 'ghost')->exists());
    }

    #[Test]
    public function an_app_that_throws_during_materialization_does_not_prevent_other_apps(): void
    {
        Log::spy();

        $contract = ControlHubContract::factory()->create();
        $this->orderInstance($contract, 'boom');    // matérialisation lèvera une exception
        $this->orderInstance($contract, 'firefox'); // matérialisation réussira
        $this->catalogWithSource($contract, 'boom', 'Boom', 'https://depot.example/boom.xml', 'sha-boom');
        $this->catalogWithSource($contract, 'firefox', 'Mozilla Firefox', 'https://depot.example/firefox.xml', 'sha-firefox');

        // Mock du seul point de matérialisation : throw pour `boom`, succès pour le reste.
        $this->mock(AppStoreService::class, function ($mock): void {
            $mock->shouldReceive('materializeFromSource')
                ->andReturnUsing(function (string $appId, array $source): Application {
                    if ($appId === 'boom') {
                        throw new \RuntimeException('échec de matérialisation simulé');
                    }

                    return Application::create([
                        'app_id' => $appId,
                        'name' => $source['name'] ?? $appId,
                        'status' => ApplicationStatus::Available,
                        'xml_url' => $source['xml_url'] ?? null,
                    ]);
                });
        });

        $result = $this->provisioner()->provision();

        // Résilience par app (AC6) : l'échec de `boom` n'empêche PAS `firefox`.
        self::assertSame(1, $result->provisioned);
        self::assertSame(1, $result->failed);
        self::assertNotEmpty($result->errors);
        self::assertStringContainsString('boom', $result->errors[0]);
        self::assertTrue(Application::query()->where('app_id', 'firefox')->exists());
        self::assertFalse(Application::query()->where('app_id', 'boom')->exists());

        Log::shouldHaveReceived('error')
            ->withArgs(fn ($message, $context = []) => $message === 'agent.applications.provision_failed'
                && ($context['app_id'] ?? null) === 'boom')
            ->once();
    }

    // ── Déclenchement par le listener ControlHubContractChanged ──────────────

    #[Test]
    public function the_listener_provisions_on_contract_change(): void
    {
        $contract = ControlHubContract::factory()->create();
        $this->orderInstance($contract, 'firefox');
        $this->catalogWithSource($contract, 'firefox', 'Mozilla Firefox', 'https://depot.example/firefox.xml', 'sha-firefox');

        self::assertFalse(Application::query()->where('app_id', 'firefox')->exists());

        // Émission de l'événement réel (listener abonné dans EventServiceProvider).
        \App\Events\ControlHubContractChanged::dispatch($contract);

        self::assertTrue(
            Application::query()->where('app_id', 'firefox')->exists(),
            'le listener ProvisionOrderedApplications a matérialisé l\'app ordonnée',
        );
    }

    // ── Commande artisan re-jouable ──────────────────────────────────────────

    #[Test]
    public function artisan_command_provisions_when_a_contract_is_active(): void
    {
        $contract = ControlHubContract::factory()->create();
        $this->orderInstance($contract, 'firefox');
        $this->catalogWithSource($contract, 'firefox', 'Mozilla Firefox', 'https://depot.example/firefox.xml', 'sha-firefox');

        $this->artisan('controlhub:provision-ordered-apps')->assertExitCode(0);

        self::assertTrue(Application::query()->where('app_id', 'firefox')->exists());
    }

    #[Test]
    public function artisan_command_is_a_no_op_without_active_contract(): void
    {
        self::assertNull(ControlHubContract::active());

        $this->artisan('controlhub:provision-ordered-apps')
            ->expectsOutputToContain('Aucun contrat amont actif')
            ->assertExitCode(0);

        self::assertSame(0, Application::query()->count());
    }

    #[Test]
    public function artisan_command_returns_failure_when_an_app_fails_to_materialize(): void
    {
        $contract = ControlHubContract::factory()->create();
        $this->orderInstance($contract, 'boom');
        $this->catalogWithSource($contract, 'boom', 'Boom', 'https://depot.example/boom.xml', 'sha-boom');

        $this->mock(AppStoreService::class, function ($mock): void {
            $mock->shouldReceive('materializeFromSource')
                ->andThrow(new \RuntimeException('échec de matérialisation simulé'));
        });

        // Au moins une app en échec → exit non-zéro (reprise/CI), distinct du standalone.
        $this->artisan('controlhub:provision-ordered-apps')->assertExitCode(1);
    }

    // ── R3 — aucun identifiant « central » dans les classes 31.3 ─────────────

    #[Test]
    public function r3_no_central_identifier_in_provisioning_classes(): void
    {
        $deliveredFqcns = [
            OrderedApplicationProvisioner::class,
            \App\Services\ControlHub\Data\OrderedApplicationProvisioningResult::class,
            \App\Listeners\ProvisionOrderedApplications::class,
            \App\Console\Commands\ProvisionOrderedApplications::class,
        ];

        foreach ($deliveredFqcns as $fqcn) {
            $this->assertStringNotContainsStringIgnoringCase('central', $fqcn, "FQCN « {$fqcn} » ne doit pas contenir « central ».");

            $reflection = new \ReflectionClass($fqcn);
            foreach ($reflection->getMethods() as $method) {
                $this->assertStringNotContainsStringIgnoringCase('central', $method->getName(), "Méthode {$fqcn}::{$method->getName()}");
            }
            foreach ($reflection->getProperties() as $property) {
                $this->assertStringNotContainsStringIgnoringCase('central', $property->getName(), "Propriété {$fqcn}::\${$property->getName()}");
            }
            foreach (array_keys($reflection->getConstants()) as $constName) {
                $this->assertStringNotContainsStringIgnoringCase('central', (string) $constName, "Constante {$fqcn}::{$constName}");
            }
        }
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function provisioner(): OrderedApplicationProvisioner
    {
        return app(OrderedApplicationProvisioner::class);
    }

    private function orderInstance(ControlHubContract $contract, string $appId): ControlHubContractItem
    {
        return ControlHubContractItem::factory()->create([
            'controlhub_contract_id' => $contract->id,
            'type' => Application::TYPE_APPLICATIONS,
            'key' => $appId,
            'value' => null,
            'enforcement_state' => ControlHubEnforcementState::Locked,
            'target_type' => ControlHubContractTarget::Instance,
            'target_label' => '',
        ]);
    }

    private function orderLabel(ControlHubContract $contract, string $appId, string $label): ControlHubContractItem
    {
        return ControlHubContractItem::factory()->forLabel($label)->create([
            'controlhub_contract_id' => $contract->id,
            'type' => Application::TYPE_APPLICATIONS,
            'key' => $appId,
            'value' => null,
            'enforcement_state' => ControlHubEnforcementState::Locked,
        ]);
    }

    private function catalogWithSource(
        ControlHubContract $contract,
        string $appKey,
        ?string $displayName,
        string $sourceXmlUrl,
        ?string $sourceXmlSha,
    ): ControlHubContractCatalogApp {
        return ControlHubContractCatalogApp::factory()->create([
            'controlhub_contract_id' => $contract->id,
            'app_key' => $appKey,
            'display_name' => $displayName,
            'source_xml_url' => $sourceXmlUrl,
            'source_xml_sha' => $sourceXmlSha,
        ]);
    }

    private function provider(): ApplicationsStateProvider
    {
        return new ApplicationsStateProvider(new WorkstationPackagesResolver(), new UpstreamContractSource([]), new CloudSyncClient());
    }

    private function ctx(Workstation $ws): TargetContext
    {
        return TargetContext::for($ws, null);
    }

    /**
     * @param  Collection<int, StateCandidate>  $candidates
     * @return list<string>
     */
    private function appIdsOf(Collection $candidates): array
    {
        $ids = $candidates->map(fn (StateCandidate $c): string => $c->payload['app_id'])->all();
        sort($ids);

        return $ids;
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Agent;

use App\Enums\ActiveCloud;
use App\Enums\ApplicationStatus;
use App\Enums\CloudAccessPath;
use App\Enums\FileBackendName;
use App\Enums\ResourceSemantics;
use App\Enums\StateMaille;
use App\Enums\StateScope;
use App\Models\Application;
use App\Models\Workstation;
use App\Models\WorkstationGroup;
use App\Observers\WorkstationGroupObserver;
use App\Services\Agent\CloudSyncClient;
use App\Services\Agent\Providers\ApplicationsStateProvider;
use App\Services\Agent\StateCandidate;
use App\Services\Agent\TargetContext;
use App\Services\ControlHub\Resolution\UpstreamContractSource;
use App\Services\FilePolicyService;
use App\Services\Filesystem\FileLocations;
use App\Services\Filesystem\FileLocationService;
use App\Wpkg\Deployment\Services\WorkstationPackagesResolver;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\WpkgSchemaBootstrapper;
use Tests\TestCase;

/**
 * Tests Unit `ApplicationsStateProvider` — Story 27.5 (AC1, AC7).
 *
 * Type figé `applications` (aggregate / scope MACHINE — WPKG installe
 * machine-wide). Projection en LECTURE SEULE de l'ensemble cible WPKG
 * ({@see WorkstationPackagesResolver::computePackages}, méthode NON CACHÉE),
 * un item par `app_id` affecté, payload concret `{app_id, name}` (jamais un id
 * de catalogue/pivot/scope), maille `Broadcast` (résolution déjà finale — D4).
 * Lecture PG-pure : aucun AD/APCu/Cache (NFR7).
 */
class ApplicationsStateProviderTest extends TestCase
{
    // Schéma géré 100% par WpkgSchemaBootstrapper (create en setUp / drop en
    // tearDown), iso WorkstationPackagesResolverTest. On NE combine PAS
    // RefreshDatabase : sous SQLite :memory: + PHP 8.4 (transaction DEFERRED), le
    // wrap transactionnel de RefreshDatabase entre en conflit avec les
    // create/drop manuels du bootstrapper (« cannot start a transaction within a
    // transaction » / drop FK order) → faux échecs au tearDown.
    private ApplicationsStateProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();
        // Projection Postgres-pure : aucune synchro AD à déclencher (host sans
        // LDAP, iso NFR7). Pattern aligné sur AppConfigStateProviderTest (27.4).
        WorkstationGroupObserver::disableSync();

        WpkgSchemaBootstrapper::bootstrap();
        Cache::flush();

        // Story 31.2 — 2ᵉ dépendance : SOURCE des ordres d'install amont. Sans
        // contrat actif (ces tests n'en créent aucun), `orderedApplicationAppIds()`
        // court-circuite (NFR3) → l'ensemble cible reste inchangé vs 27.5.
        $this->provider = new ApplicationsStateProvider(
            new WorkstationPackagesResolver(),
            new UpstreamContractSource([]),
            new CloudSyncClient(),
        );
    }

    protected function tearDown(): void
    {
        WpkgSchemaBootstrapper::tearDown();
        WorkstationGroupObserver::enableSync();
        parent::tearDown();
    }

    private function newApp(string $appId, ?string $name = null): Application
    {
        return Application::create(['app_id' => $appId, 'name' => $name ?? ucfirst($appId)]);
    }

    private function ctx(Workstation $ws): TargetContext
    {
        // Machine-only (pas de user) : la portée applications est MACHINE.
        return TargetContext::for($ws, null);
    }

    #[Test]
    public function declares_frozen_type_semantics_and_scope(): void
    {
        self::assertSame(Application::TYPE_APPLICATIONS, $this->provider->type());
        self::assertSame('applications', $this->provider->type());
        self::assertSame(ResourceSemantics::Aggregate, $this->provider->semantics());
        // Portée MACHINE : WPKG installe machine-wide (leçon 🔴 27.4 #1).
        self::assertSame(StateScope::Machine, $this->provider->scope());
    }

    #[Test]
    public function emits_one_item_per_affected_app_union_of_mailles(): void
    {
        $appA = $this->newApp('alpha', 'Alpha');
        $appB = $this->newApp('bravo', 'Bravo');
        $appC = $this->newApp('charlie', 'Charlie');

        $ws = Workstation::create(['name' => 'PC27-5', 'status' => 'active']);
        $parc = WorkstationGroup::create(['name' => 'parc-27-5']);
        $ws->groups()->attach($parc);

        // Source poste direct + source parc → union des mailles.
        $ws->applications()->attach([$appA->id]);
        $parc->applications()->attach([$appB->id, $appC->id]);

        $candidates = $this->provider->itemsFor($this->ctx($ws));

        // Un candidat par app affectée (union poste + parc).
        self::assertCount(3, $candidates);
        $appIds = $candidates->map(fn (StateCandidate $c) => $c->payload['app_id'])->all();
        sort($appIds);
        self::assertSame(['alpha', 'bravo', 'charlie'], $appIds);
    }

    #[Test]
    public function includes_transitive_dependencies(): void
    {
        $appA = $this->newApp('alpha');   // racine, attachée au poste
        $appB = $this->newApp('bravo');   // dep de A
        $appC = $this->newApp('charlie'); // dep transitive de B

        DB::table('application_dependencies')->insert([
            ['application_id' => $appA->id, 'required_application_id' => $appB->id, 'created_at' => now(), 'updated_at' => now()],
            ['application_id' => $appB->id, 'required_application_id' => $appC->id, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $ws = Workstation::create(['name' => 'PCDEP', 'status' => 'active']);
        $ws->applications()->attach([$appA->id]);

        $candidates = $this->provider->itemsFor($this->ctx($ws));

        // L'ensemble cible inclut les dépendances transitives (single source of
        // truth — la résolution WPKG, jamais réimplémentée dans le provider).
        $appIds = $candidates->map(fn (StateCandidate $c) => $c->payload['app_id'])->all();
        sort($appIds);
        self::assertSame(['alpha', 'bravo', 'charlie'], $appIds);
    }

    #[Test]
    public function payload_carries_concrete_app_id_and_name_never_a_scope_id(): void
    {
        $app = $this->newApp('firefox', 'Mozilla Firefox');
        $ws = Workstation::create(['name' => 'PCPAYLOAD', 'status' => 'active']);
        $ws->applications()->attach([$app->id]);

        $candidates = $this->provider->itemsFor($this->ctx($ws));
        $firefox = $candidates->first(fn (StateCandidate $c) => $c->payload['app_id'] === 'firefox');

        self::assertNotNull($firefox);
        // Payload concret : exactement {app_id, name}, jamais un id de catalogue/pivot/scope.
        self::assertSame(['app_id', 'name'], array_keys($firefox->payload));
        self::assertSame('firefox', $firefox->payload['app_id']);
        self::assertSame('Mozilla Firefox', $firefox->payload['name']);
        self::assertArrayNotHasKey('id', $firefox->payload);
        self::assertArrayNotHasKey('scope', $firefox->payload);
        self::assertArrayNotHasKey('version', $firefox->payload);

        // Strings only (contrat §4.1 : jamais de float, jamais d'id de scope).
        self::assertIsString($firefox->payload['app_id']);
        self::assertIsString($firefox->payload['name']);
    }

    #[Test]
    public function candidates_are_broadcast_maille_and_source_id_is_the_pk(): void
    {
        $app = $this->newApp('vlc', 'VLC');
        $ws = Workstation::create(['name' => 'PCMAILLE', 'status' => 'active']);
        $ws->applications()->attach([$app->id]);

        $candidates = $this->provider->itemsFor($this->ctx($ws));
        $vlc = $candidates->first(fn (StateCandidate $c) => $c->payload['app_id'] === 'vlc');

        self::assertNotNull($vlc);
        // Maille Broadcast (D4 : la résolution WPKG est déjà finale).
        self::assertSame(StateMaille::Broadcast, $vlc->maille);
        // sourceId déterministe & injectif = la PK Application (ordre aggregate / ETag stable).
        self::assertSame($app->id, $vlc->sourceId);
    }

    #[Test]
    public function unknown_hostname_emits_no_candidate(): void
    {
        $ws = Workstation::create(['name' => 'PCNONE', 'status' => 'active']);
        // Aucune app affectée.

        $candidates = $this->provider->itemsFor($this->ctx($ws));

        self::assertCount(0, $candidates);
    }

    // ── Story 27.17 — apps « défaut parc » (is_parc_default → Broadcast) ──────

    #[Test]
    public function parc_default_apps_are_emitted_for_a_workstation_without_specific_config(): void
    {
        // Un poste SANS aucun rattachement (ni poste, ni parc, ni profil) reçoit
        // tout de même les apps marquées is_parc_default (couche Broadcast 27.17).
        $sevenZip = $this->newApp('7za', '7-Zip CLI');
        $sevenZip->is_parc_default = true;
        $sevenZip->save();

        $this->newApp('vlc', 'VLC'); // non-défaut : ne doit PAS apparaître

        $ws = Workstation::create(['name' => 'PCDEFAULT', 'status' => 'active']);

        $candidates = $this->provider->itemsFor($this->ctx($ws));

        $appIds = $candidates->map(fn (StateCandidate $c) => $c->payload['app_id'])->all();
        self::assertSame(['7za'], $appIds, 'seule l\'app défaut parc est diffusée');

        // Toujours en maille Broadcast (iso le reste du provider).
        self::assertSame(StateMaille::Broadcast, $candidates->first()->maille);
    }

    #[Test]
    public function parc_default_apps_union_with_resolved_apps_without_duplication(): void
    {
        $resolved = $this->newApp('alpha', 'Alpha');     // rattachée au poste
        $default = $this->newApp('7za', '7-Zip CLI');    // défaut parc
        $default->is_parc_default = true;
        $default->save();

        // Une app rattachée au poste ET marquée défaut parc : pas de doublon.
        $both = $this->newApp('nircmd', 'NirCmd');
        $both->is_parc_default = true;
        $both->save();

        $ws = Workstation::create(['name' => 'PCUNION', 'status' => 'active']);
        $ws->applications()->attach([$resolved->id, $both->id]);

        $candidates = $this->provider->itemsFor($this->ctx($ws));

        $appIds = $candidates->map(fn (StateCandidate $c) => $c->payload['app_id'])->all();
        sort($appIds);
        self::assertSame(['7za', 'alpha', 'nircmd'], $appIds, 'union sans doublon');
    }

    #[Test]
    public function no_parc_default_and_no_config_emits_no_candidate_regression(): void
    {
        // NON-RÉGRESSION 27.17 : aucune app défaut parc + aucune config spécifique
        // ⇒ le state Broadcast reste VIDE, exactement comme avant la story.
        $this->newApp('vlc', 'VLC'); // existe mais is_parc_default = false (défaut migration)

        $ws = Workstation::create(['name' => 'PCREG', 'status' => 'active']);

        $candidates = $this->provider->itemsFor($this->ctx($ws));

        self::assertCount(0, $candidates, 'sans défaut parc ni config, le state reste inchangé (vide)');
    }

    // ── Story 63.5 — le CLIENT DE SYNCHRONISATION, troisième source d'union ──

    /** Une recette WPKG qui DÉCRIT une désinstallation (garde prédictive AC3). */
    private static function recipeWithRemove(string $appId): string
    {
        return '<package id="'.$appId.'" name="Client">'
            .'<install cmd="setup.exe /S" /><remove cmd="uninstall.exe /S" /></package>';
    }

    /** Une application du catalogue, INSTALLÉE et désinstallable. */
    private function clientApp(string $appId, string $name): Application
    {
        return Application::create([
            'app_id' => $appId,
            'name' => $name,
            'status' => ApplicationStatus::Installed,
            'xml' => self::recipeWithRemove($appId),
        ]);
    }

    /** L'instance a un cloud actif, et l'accès s'y fait par le client. */
    private function accessByClient(ActiveCloud $cloud, string $designatedAppId): void
    {
        FileLocationService::set(FileLocations::make(FileBackendName::Posix, FileBackendName::Posix, $cloud));
        FilePolicyService::patchGlobal([
            'cloud_access_path' => CloudAccessPath::ClientNatif->value,
            $cloud === ActiveCloud::Nextcloud ? 'nextcloud_client_app_id' : 'opencloud_client_app_id' => $designatedAppId,
        ]);
    }

    /** @return list<string> */
    private function appIdsFor(Workstation $ws): array
    {
        return $this->provider->itemsFor($this->ctx($ws))
            ->map(fn (StateCandidate $c): string => (string) $c->payload['app_id'])
            ->values()
            ->all();
    }

    #[Test]
    public function the_designated_sync_client_is_unioned_into_the_target_set(): void
    {
        $assigned = $this->newApp('alpha', 'Alpha');
        $this->clientApp('nc-client', 'Nextcloud Desktop');

        $ws = Workstation::create(['name' => 'PCSYNC', 'status' => 'active']);
        $ws->applications()->attach([$assigned->id]);

        $this->accessByClient(ActiveCloud::Nextcloud, 'nc-client');

        // Troisième source, exactement comme les deux autres : l'ordre alpha
        // insensible à la casse et la dédup sont INCHANGÉS.
        self::assertSame(['alpha', 'nc-client'], $this->appIdsFor($ws));
    }

    #[Test]
    public function the_sync_client_payload_is_indistinguishable_from_any_other_application(): void
    {
        $this->clientApp('nc-client', 'Nextcloud Desktop');
        $ws = Workstation::create(['name' => 'PCPAYLOADSYNC', 'status' => 'active']);

        $this->accessByClient(ActiveCloud::Nextcloud, 'nc-client');

        $candidate = $this->provider->itemsFor($this->ctx($ws))->first();

        self::assertNotNull($candidate);
        // Aucune clé ajoutée, aucun marqueur d'origine — sans quoi une app
        // désignée ET assignée produirait DEUX items et le hash mentirait.
        self::assertSame(['app_id', 'name'], array_keys($candidate->payload));
        self::assertSame('nc-client', $candidate->payload['app_id']);
        self::assertSame('Nextcloud Desktop', $candidate->payload['name']);
        self::assertSame(StateMaille::Broadcast, $candidate->maille);
        self::assertSame(
            Application::where('app_id', 'nc-client')->value('id'),
            $candidate->sourceId,
            'sourceId = PK Application, comme pour toute autre source',
        );
    }

    #[Test]
    public function an_application_both_designated_and_assigned_collapses_into_one_item(): void
    {
        $client = $this->clientApp('nc-client', 'Nextcloud Desktop');
        $client->is_parc_default = true;
        $client->save();

        $ws = Workstation::create(['name' => 'PCCOLLAPSE', 'status' => 'active']);
        // Triple source : rattachement direct + défaut parc + désignation.
        $ws->applications()->attach([$client->id]);

        $this->accessByClient(ActiveCloud::Nextcloud, 'nc-client');

        self::assertSame(['nc-client'], $this->appIdsFor($ws), 'UN seul item, jamais trois');
    }

    #[Test]
    public function in_web_position_nothing_is_unioned_and_the_set_is_byte_identical(): void
    {
        $assigned = $this->newApp('alpha', 'Alpha');
        $this->clientApp('nc-client', 'Nextcloud Desktop');

        $ws = Workstation::create(['name' => 'PCWEB', 'status' => 'active']);
        $ws->applications()->attach([$assigned->id]);

        // Cloud actif, désignation valable — mais l'accès se fait par le
        // navigateur, qui est le DÉFAUT.
        FileLocationService::set(FileLocations::make(
            FileBackendName::Posix,
            FileBackendName::Posix,
            ActiveCloud::Nextcloud,
        ));
        FilePolicyService::patchGlobal(['nextcloud_client_app_id' => 'nc-client']);

        self::assertSame(['alpha'], $this->appIdsFor($ws));
    }

    #[Test]
    public function without_an_active_cloud_nothing_is_unioned(): void
    {
        $assigned = $this->newApp('alpha', 'Alpha');
        $this->clientApp('nc-client', 'Nextcloud Desktop');

        $ws = Workstation::create(['name' => 'PCNOCLOUD', 'status' => 'active']);
        $ws->applications()->attach([$assigned->id]);

        // Position `client_natif` ET désignation renseignée, mais AUCUN cloud
        // actif : le court-circuit prime, et rien n'est unionné.
        FilePolicyService::patchGlobal([
            'cloud_access_path' => CloudAccessPath::ClientNatif->value,
            'nextcloud_client_app_id' => 'nc-client',
        ]);

        self::assertSame(['alpha'], $this->appIdsFor($ws));
    }

    #[Test]
    public function a_designation_that_resolves_no_catalog_row_unions_nothing(): void
    {
        $ws = Workstation::create(['name' => 'PCUNTENABLE', 'status' => 'active']);

        // La désignation pointe un `app_id` que le catalogue ne porte pas : il
        // n'y aurait ni `name` à hydrater ni `sourceId`, et le provider
        // n'émettrait qu'un `Log::warning`.
        $this->accessByClient(ActiveCloud::Nextcloud, 'jamais-vu');

        self::assertSame([], $this->appIdsFor($ws));
    }

    /**
     * ⚠️ LA GARDE DE SAISIE NE SORT PAS DU CHEMIN DE COMPILATION (correction de
     * revue). Réinstaller ou mettre à jour l'application désignée la fait passer
     * par `Downloading` — sans qu'aucun administrateur ne décide quoi que ce
     * soit. La retirer de l'ensemble cible ferait DÉSINSTALLER le client par
     * WPKG sur tout le parc, puis réinstaller à la passe suivante.
     */
    #[Test]
    public function a_designated_application_being_reinstalled_stays_in_the_target_set(): void
    {
        $this->clientApp('nc-client', 'Nextcloud Desktop');
        $ws = Workstation::create(['name' => 'PCDOWNLOADING', 'status' => 'active']);

        $this->accessByClient(ActiveCloud::Nextcloud, 'nc-client');
        self::assertSame(['nc-client'], $this->appIdsFor($ws));

        // L'AppStore réinstalle : statut transitoire, aucune décision d'admin.
        Application::query()->where('app_id', 'nc-client')
            ->update(['status' => ApplicationStatus::Downloading->value]);

        self::assertSame(['nc-client'], $this->appIdsFor($ws), 'l\'ensemble cible ne bouge pas');

        // Et une recette réécrite par l'amont, qui perdrait son `<remove>`, ne
        // désinstalle pas davantage : le refus reste une garde d'écriture.
        Application::query()->where('app_id', 'nc-client')->update([
            'status' => ApplicationStatus::Installed->value,
            'xml' => '<package id="nc-client"><install cmd="setup.exe" /></package>',
        ]);

        self::assertSame(['nc-client'], $this->appIdsFor($ws));
    }

    #[Test]
    public function going_back_to_the_browser_removes_the_item_from_the_compiled_set(): void
    {
        $this->clientApp('nc-client', 'Nextcloud Desktop');
        $ws = Workstation::create(['name' => 'PCRETRAIT', 'status' => 'active']);

        $this->accessByClient(ActiveCloud::Nextcloud, 'nc-client');
        self::assertSame(['nc-client'], $this->appIdsFor($ws));

        // Repasser au navigateur : l'item DISPARAÎT de l'état. C'est tout ce que
        // le serveur garantit — la désinstallation est le `<remove>` de la
        // recette, exécuté par WPKG après que l'agent a constaté que le desired
        // set ne vaut plus le profil déposé.
        FilePolicyService::patchGlobal(['cloud_access_path' => CloudAccessPath::Web->value]);

        self::assertSame([], $this->appIdsFor($ws));
    }

    #[Test]
    public function removing_the_designation_also_removes_the_item(): void
    {
        $this->clientApp('nc-client', 'Nextcloud Desktop');
        $ws = Workstation::create(['name' => 'PCDESIGNOFF', 'status' => 'active']);

        $this->accessByClient(ActiveCloud::Nextcloud, 'nc-client');
        self::assertSame(['nc-client'], $this->appIdsFor($ws));

        FilePolicyService::patchGlobal(['nextcloud_client_app_id' => '']);

        self::assertSame([], $this->appIdsFor($ws));
    }

    #[Test]
    public function an_application_assigned_elsewhere_stays_when_going_back_to_the_browser(): void
    {
        $client = $this->clientApp('nc-client', 'Nextcloud Desktop');
        $ws = Workstation::create(['name' => 'PCASSIGNE', 'status' => 'active']);
        // La MÊME app est aussi rattachée au poste par un geste d'exploitation.
        $ws->applications()->attach([$client->id]);

        $this->accessByClient(ActiveCloud::Nextcloud, 'nc-client');
        self::assertSame(['nc-client'], $this->appIdsFor($ws));

        FilePolicyService::patchGlobal(['cloud_access_path' => CloudAccessPath::Web->value]);

        // L'union RESTE une union : le plan de fichiers ajoute une raison
        // d'installer, il ne gouverne pas les affectations d'applications.
        self::assertSame(['nc-client'], $this->appIdsFor($ws));
    }

    #[Test]
    public function switching_clouds_designates_the_other_product_package(): void
    {
        $this->clientApp('nc-client', 'Nextcloud Desktop');
        $this->clientApp('oc-client', 'OpenCloud Desktop');
        $ws = Workstation::create(['name' => 'PCBASCULE', 'status' => 'active']);

        $this->accessByClient(ActiveCloud::Nextcloud, 'nc-client');
        FilePolicyService::patchGlobal(['opencloud_client_app_id' => 'oc-client']);
        self::assertSame(['nc-client'], $this->appIdsFor($ws));

        FileLocationService::set(FileLocations::make(
            FileBackendName::Posix,
            FileBackendName::Posix,
            ActiveCloud::OpenCloud,
        ));

        self::assertSame(['oc-client'], $this->appIdsFor($ws), 'jamais le paquet de l\'autre produit');
    }

    #[Test]
    public function switching_to_a_cloud_without_designation_unions_nothing(): void
    {
        $this->clientApp('nc-client', 'Nextcloud Desktop');
        $ws = Workstation::create(['name' => 'PCBASCULE2', 'status' => 'active']);

        $this->accessByClient(ActiveCloud::Nextcloud, 'nc-client');
        self::assertSame(['nc-client'], $this->appIdsFor($ws));

        // OpenCloud devient actif, mais aucune application n'est désignée pour
        // LUI : rien n'est unionné — surtout pas le paquet de Nextcloud.
        FileLocationService::set(FileLocations::make(
            FileBackendName::Posix,
            FileBackendName::Posix,
            ActiveCloud::OpenCloud,
        ));

        self::assertSame([], $this->appIdsFor($ws));
    }

    #[Test]
    public function without_any_locations_row_the_set_is_exactly_what_it_was(): void
    {
        $assigned = $this->newApp('alpha', 'Alpha');
        $this->clientApp('nc-client', 'Nextcloud Desktop');

        $ws = Workstation::create(['name' => 'PCNOROW', 'status' => 'active']);
        $ws->applications()->attach([$assigned->id]);

        // Aucune ligne `files.locations` : les défauts 63.1 (`cloud.actif = aucun`)
        // s'appliquent, et rien ne lève.
        self::assertSame(['alpha'], $this->appIdsFor($ws));
    }

    #[Test]
    public function provider_reads_no_cache_uncached_resolution_only(): void
    {
        // PG-pur (NFR7) : le provider lit la résolution NON CACHÉE. Aucune entrée
        // de cache WPKG ne doit être écrite par itemsFor (contrairement à
        // resolve() qui wrappe Cache::remember).
        $app = $this->newApp('zip', '7-Zip');
        $ws = Workstation::create(['name' => 'PCCACHE', 'status' => 'active']);
        $ws->applications()->attach([$app->id]);

        Cache::flush();
        $this->provider->itemsFor($this->ctx($ws));

        // La clé cache que `resolve()` aurait posée NE doit PAS exister (le
        // provider n'a jamais appelé resolve()).
        self::assertFalse(
            Cache::has(WorkstationPackagesResolver::cacheKey('PCCACHE')),
            'le provider ne doit JAMAIS toucher le cache WPKG (NFR7) — uniquement computePackages (non caché)',
        );
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Agent;

use App\Enums\ActiveCloud;
use App\Enums\ControlHubContractTarget;
use App\Enums\ControlHubEnforcementState;
use App\Enums\FileBackendName;
use App\Models\AppProfile;
use App\Models\Application;
use App\Models\ControlHubContractItem;
use App\Models\Shortcut;
use App\Models\Workstation;
use App\Models\WorkstationGroup;
use App\Observers\AppProfileObserver;
use App\Observers\WorkstationGroupObserver;
use App\Observers\WorkstationObserver;
use App\Services\Agent\Providers\ApplicationsStateProvider;
use App\Services\Agent\Providers\ShortcutsStateProvider;
use App\Services\Agent\Reporting\DesiredStateOriginService;
use App\Services\Agent\StateCandidate;
use App\Services\Agent\TargetContext;
use App\Services\ControlHub\Resolution\UpstreamContractSource;
use App\Services\FilePolicyService;
use App\Services\Filesystem\FileLocations;
use App\Services\Filesystem\FileLocationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 37.1 — `DesiredStateOriginService` : origines exactes (AC4), multi-origines
 * (piège #5), ensembles vides (AC1), et FIDÉLITÉ à l'état servi à l'agent (AC3,
 * comparaison aux providers réels).
 */
class DesiredStateOriginServiceTest extends TestCase
{
    use RefreshDatabase;

    private DesiredStateOriginService $service;

    protected function setUp(): void
    {
        parent::setUp();
        // Projection PG-pure : aucune synchro AD à déclencher (host sans LDAP).
        WorkstationObserver::$syncEnabled = false;
        WorkstationGroupObserver::$syncEnabled = false;
        AppProfileObserver::$syncEnabled = false;

        $this->service = app(DesiredStateOriginService::class);
    }

    protected function tearDown(): void
    {
        WorkstationObserver::$syncEnabled = true;
        WorkstationGroupObserver::$syncEnabled = true;
        AppProfileObserver::$syncEnabled = true;
        parent::tearDown();
    }

    private function newApp(string $appId, ?string $name = null): Application
    {
        return Application::create(['app_id' => $appId, 'name' => $name ?? ucfirst($appId)]);
    }

    private function newShortcut(string $name, array $attrs = []): Shortcut
    {
        return Shortcut::create(array_merge([
            'key' => 'k-'.$name.'-'.uniqid(),
            'name' => $name,
            'place' => Shortcut::PLACE_DESKTOP,
            'windows_link' => 'C:\\Program\\'.$name.'.exe',
            'is_active' => true,
        ], $attrs));
    }

    /** @return array<string,mixed>|null */
    private function appRow(array $rows, string $appId): ?array
    {
        foreach ($rows as $row) {
            if ($row['detail'] === $appId) {
                return $row;
            }
        }

        return null;
    }

    // ── AC4 — origines exactes (applications) ─────────────────────────────────

    #[Test]
    public function app_directly_on_workstation_is_ce_poste(): void
    {
        $app = $this->newApp('alpha');
        $ws = Workstation::create(['name' => 'PC1', 'status' => 'active']);
        $ws->applications()->attach([$app->id]);

        $row = $this->appRow($this->service->applicationsFor($ws), 'alpha');

        self::assertNotNull($row);
        self::assertSame('workstation', $row['primary']['kind']);
    }

    #[Test]
    public function app_from_logical_park_is_logical_group_with_link(): void
    {
        $app = $this->newApp('bravo');
        $ws = Workstation::create(['name' => 'PC2', 'status' => 'active']);
        $parc = WorkstationGroup::create(['name' => 'parc-logique', 'is_physical' => false]);
        $ws->groups()->attach($parc);
        $parc->applications()->attach([$app->id]);

        $row = $this->appRow($this->service->applicationsFor($ws), 'bravo');

        self::assertNotNull($row);
        self::assertSame('logical_group', $row['primary']['kind']);
        self::assertSame($parc->id, $row['primary']['group_id']);
        self::assertSame('parc-logique', $row['primary']['group_name']);
    }

    #[Test]
    public function app_from_physical_room_is_physical_group(): void
    {
        $app = $this->newApp('charlie');
        $ws = Workstation::create(['name' => 'PC3', 'status' => 'active']);
        $salle = WorkstationGroup::create(['name' => 'salle-101', 'is_physical' => true]);
        $ws->groups()->attach($salle);
        $salle->applications()->attach([$app->id]);

        $row = $this->appRow($this->service->applicationsFor($ws), 'charlie');

        self::assertNotNull($row);
        self::assertSame('physical_group', $row['primary']['kind']);
        self::assertTrue($row['primary']['group_physical']);
    }

    #[Test]
    public function app_pulled_only_as_dependency_is_dependency_of_parent(): void
    {
        // Review M1 — name VOLONTAIREMENT distinct de l'app_id : `via` doit porter
        // le NOM D'AFFICHAGE du parent (« Dépendance de Mozilla Firefox »), pas
        // l'app_id WPKG brut (le masquage venait de fixtures où name ≈ app_id).
        $parent = $this->newApp('parent', 'Parent Display Name');
        $dep = $this->newApp('libdep');
        DB::table('application_dependencies')->insert([
            'application_id' => $parent->id,
            'required_application_id' => $dep->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $ws = Workstation::create(['name' => 'PC4', 'status' => 'active']);
        $ws->applications()->attach([$parent->id]);

        $row = $this->appRow($this->service->applicationsFor($ws), 'libdep');

        self::assertNotNull($row);
        self::assertSame('dependency', $row['primary']['kind']);
        self::assertSame('Parent Display Name', $row['primary']['via']);
    }

    #[Test]
    public function parc_default_app_without_attachment_is_socle_commun(): void
    {
        $app = $this->newApp('7za', '7-Zip');
        $app->is_parc_default = true;
        $app->save();

        $ws = Workstation::create(['name' => 'PC5', 'status' => 'active']);

        $row = $this->appRow($this->service->applicationsFor($ws), '7za');

        self::assertNotNull($row);
        self::assertSame('parc_default', $row['primary']['kind']);
    }

    #[Test]
    public function app_direct_and_from_park_is_multi_origin_most_specific_wins(): void
    {
        $app = $this->newApp('shared');
        $ws = Workstation::create(['name' => 'PC6', 'status' => 'active']);
        $parc = WorkstationGroup::create(['name' => 'parc-x', 'is_physical' => false]);
        $ws->groups()->attach($parc);
        $ws->applications()->attach([$app->id]);
        $parc->applications()->attach([$app->id]);

        $row = $this->appRow($this->service->applicationsFor($ws), 'shared');

        self::assertNotNull($row);
        // Badge principal = plus spécifique (Ce poste).
        self::assertSame('workstation', $row['primary']['kind']);
        // Les DEUX origines sont conservées (tooltip +N).
        $kinds = array_column($row['origins'], 'kind');
        self::assertContains('workstation', $kinds);
        self::assertContains('logical_group', $kinds);
        self::assertCount(2, $row['origins']);
    }

    #[Test]
    public function app_ordered_by_active_upstream_contract_is_contrat_amont(): void
    {
        // Review #2 — AC4 « Contrat amont » : une app ORDONNÉE par un contrat amont
        // ACTIF (item type=applications, cible instance) et NON résolue localement
        // doit apparaître avec le badge upstream (kind === 'upstream').
        $app = $this->newApp('teamviewer', 'TeamViewer');

        // Contrat ACTIF (factory par défaut) + item d'ordre d'install `instance`.
        ControlHubContractItem::factory()->create([
            'type' => Application::TYPE_APPLICATIONS,
            'key' => 'teamviewer',                          // = app_id
            'enforcement_state' => ControlHubEnforcementState::Locked,
            'target_type' => ControlHubContractTarget::Instance,
            'target_label' => '',
        ]);

        // `UpstreamContractSource` est un singleton MÉMOÏSÉ : on le ré-instancie
        // frais pour que sa résolution voie le contrat créé ci-dessus.
        app()->forgetInstance(UpstreamContractSource::class);
        $service = app(DesiredStateOriginService::class);

        $ws = Workstation::create(['name' => 'PCUP', 'status' => 'active']);

        $row = $this->appRow($service->applicationsFor($ws), 'teamviewer');

        self::assertNotNull($row);
        self::assertSame('upstream', $row['primary']['kind']);
    }

    #[Test]
    public function bare_workstation_has_empty_states_without_error(): void
    {
        $ws = Workstation::create(['name' => 'PCBARE', 'status' => 'active']);

        self::assertSame([], $this->service->applicationsFor($ws));
        self::assertSame([], $this->service->shortcutsFor($ws));
    }

    // ── AC4 — origines exactes (raccourcis) ───────────────────────────────────

    #[Test]
    public function shortcut_on_room_is_physical_badge_distinct_from_logical(): void
    {
        $ws = Workstation::create(['name' => 'PC7', 'status' => 'active']);
        $salle = WorkstationGroup::create(['name' => 'salle-A', 'is_physical' => true]);
        $parc = WorkstationGroup::create(['name' => 'parc-B', 'is_physical' => false]);
        $ws->groups()->attach([$salle->id, $parc->id]);

        $scRoom = $this->newShortcut('RoomLink');
        $scRoom->workstationGroups()->attach($salle->id);
        $scPark = $this->newShortcut('ParkLink');
        $scPark->workstationGroups()->attach($parc->id);

        $rows = $this->service->shortcutsFor($ws);
        $room = collect($rows)->firstWhere('label', 'RoomLink');
        $park = collect($rows)->firstWhere('label', 'ParkLink');

        self::assertSame('physical_group', $room['primary']['kind']);
        self::assertSame('logical_group', $park['primary']['kind']);
    }

    #[Test]
    public function inactive_shortcuts_are_excluded(): void
    {
        $ws = Workstation::create(['name' => 'PC8', 'status' => 'active']);
        $sc = $this->newShortcut('Hidden', ['is_active' => false]);
        $sc->workstations()->attach($ws->id);

        self::assertSame([], $this->service->shortcutsFor($ws));
    }

    #[Test]
    public function shortcut_multi_origin_workstation_and_park(): void
    {
        $ws = Workstation::create(['name' => 'PC9', 'status' => 'active']);
        $parc = WorkstationGroup::create(['name' => 'parc-multi', 'is_physical' => false]);
        $ws->groups()->attach($parc);

        $sc = $this->newShortcut('Both');
        $sc->workstations()->attach($ws->id);
        $sc->workstationGroups()->attach($parc->id);

        $rows = $this->service->shortcutsFor($ws);
        $row = collect($rows)->firstWhere('label', 'Both');

        self::assertNotNull($row);
        self::assertSame('workstation', $row['primary']['kind']);
        self::assertCount(2, $row['origins']);
    }

    // ── AC3 — fidélité à l'état servi à l'agent ───────────────────────────────

    #[Test]
    public function applications_set_equals_applications_state_provider(): void
    {
        $direct = $this->newApp('alpha');
        $parcApp = $this->newApp('bravo');
        $dep = $this->newApp('charlie');
        $default = $this->newApp('delta');
        $default->is_parc_default = true;
        $default->save();

        DB::table('application_dependencies')->insert([
            'application_id' => $direct->id,
            'required_application_id' => $dep->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $ws = Workstation::create(['name' => 'PCINV', 'status' => 'active']);
        $parc = WorkstationGroup::create(['name' => 'parc-inv', 'is_physical' => false]);
        $ws->groups()->attach($parc);
        $ws->applications()->attach([$direct->id]);
        $parc->applications()->attach([$parcApp->id]);

        // Ensemble UI.
        $uiAppIds = array_map(fn ($r) => $r['detail'], $this->service->applicationsFor($ws));
        sort($uiAppIds);

        // Ensemble provider réel.
        $provider = app(ApplicationsStateProvider::class);
        $providerAppIds = $provider->itemsFor(TargetContext::for($ws, null))
            ->map(fn (StateCandidate $c) => $c->payload['app_id'])
            ->all();
        sort($providerAppIds);

        self::assertSame($providerAppIds, $uiAppIds);
        self::assertSame(['alpha', 'bravo', 'charlie', 'delta'], $uiAppIds);
    }

    #[Test]
    public function shortcuts_set_equals_shortcuts_state_provider_machine_candidates(): void
    {
        $ws = Workstation::create(['name' => 'PCSC', 'status' => 'active']);
        $parc = WorkstationGroup::create(['name' => 'parc-sc', 'is_physical' => false]);
        $ws->groups()->attach($parc);

        $scWs = $this->newShortcut('OnWs');
        $scWs->workstations()->attach($ws->id);
        $scParc = $this->newShortcut('OnParc');
        $scParc->workstationGroups()->attach($parc->id);
        // Raccourci inactif : hors état cible.
        $this->newShortcut('Inactive', ['is_active' => false])->workstations()->attach($ws->id);

        // Ids UI (raccourcis locaux — clés 'sc-<id>').
        $uiIds = collect($this->service->shortcutsFor($ws))
            ->pluck('key')
            // Raccourcis LOCAUX seulement : ni amont, ni le portail (qui naît du
            // réglage global et n'a pas d'id de ligne).
            ->filter(fn ($k) => str_starts_with($k, 'sc-')
                && ! str_starts_with($k, 'sc-upstream-')
                && $k !== 'sc-portal')
            ->map(fn ($k) => (int) substr($k, 3))
            ->sort()->values()->all();

        // Ids provider réel (candidats machine, user = null).
        $provider = app(ShortcutsStateProvider::class);
        $providerIds = $provider->itemsFor(TargetContext::for($ws, null))
            ->map(fn (StateCandidate $c) => $c->sourceId)
            ->unique()->sort()->values()->all();

        self::assertSame($providerIds, $uiIds);
        self::assertEqualsCanonicalizing([$scWs->id, $scParc->id], $uiIds);
    }

    /**
     * Story 63.2 — la ligne d'explication suit le PLAN DE FICHIERS, exactement
     * comme le provider : un cloud actif ET un espace servi par lui. Une
     * condition qui divergerait ferait mentir l'écran : un raccourci annoncé et
     * jamais posé, ou posé et jamais annoncé.
     */
    #[Test]
    public function portal_shortcut_is_explained_when_the_active_cloud_serves_a_space(): void
    {
        $ws = Workstation::create(['name' => 'PCPORTAL', 'status' => 'active']);

        // Une URL renseignée, mais AUCUN cloud actif : rien à expliquer.
        FilePolicyService::setGlobal(true, true, true, 'https://cloud.etab.fr');
        self::assertSame(
            [],
            collect($this->service->shortcutsFor($ws))->where('key', 'sc-portal')->all(),
        );

        // Un cloud actif et joignable, mais dont AUCUN espace ne dépend :
        // toujours rien à expliquer — le provider ne pose rien non plus.
        FileLocationService::set(FileLocations::make(
            FileBackendName::Posix,
            FileBackendName::Posix,
            ActiveCloud::Nextcloud,
        ));
        self::assertSame(
            [],
            collect($this->service->shortcutsFor($ws))->where('key', 'sc-portal')->all(),
        );

        FileLocationService::set(FileLocations::make(
            FileBackendName::Nextcloud,
            FileBackendName::Posix,
            ActiveCloud::Nextcloud,
        ));

        $row = collect($this->service->shortcutsFor($ws))->firstWhere('key', 'sc-portal');

        self::assertNotNull($row, 'un `.lnk` posé sur tous les postes doit être explicable');
        self::assertSame(ShortcutsStateProvider::PORTAL_SHORTCUT_NAME, $row['label']);
        self::assertSame('file_policy', $row['primary']['kind']);
        // La DESTINATION, pas `rundll32.exe` : c'est ce que l'exploitant vient
        // vérifier sur cet écran.
        self::assertSame('https://cloud.etab.fr', $row['detail']);
    }

    /** Sous OpenCloud, l'écran montre l'URL OpenCloud — jamais celle du voisin. */
    #[Test]
    public function portal_shortcut_row_shows_the_active_cloud_url(): void
    {
        $ws = Workstation::create(['name' => 'PCPORTALOC', 'status' => 'active']);

        FilePolicyService::setGlobal(
            true,
            true,
            true,
            'https://nextcloud.etab.fr',
            opencloud: true,
            opencloudServerUrl: 'https://opencloud.etab.fr',
        );
        FileLocationService::set(FileLocations::make(
            FileBackendName::Posix,
            FileBackendName::OpenCloud,
            ActiveCloud::OpenCloud,
        ));

        $row = collect($this->service->shortcutsFor($ws))->firstWhere('key', 'sc-portal');

        self::assertNotNull($row);
        self::assertSame('https://opencloud.etab.fr', $row['detail']);
    }

    // ── AC2/D4 — page parc ────────────────────────────────────────────────────

    #[Test]
    public function group_page_shows_direct_via_profile_and_socle(): void
    {
        $directApp = $this->newApp('gdirect');
        $profileApp = $this->newApp('gprofile');
        $socle = $this->newApp('gsocle');
        $socle->is_parc_default = true;
        $socle->save();

        $parc = WorkstationGroup::create(['name' => 'parc-page', 'is_physical' => false]);
        $parc->applications()->attach([$directApp->id]);

        $profile = AppProfile::create(['name' => 'ProfilBureautique', 'is_active' => true]);
        $profile->applications()->attach([$profileApp->id]);
        $parc->appProfiles()->attach([$profile->id]);

        $rows = $this->service->applicationsForGroup($parc);

        $direct = $this->appRow($rows, 'gdirect');
        $viaProfile = $this->appRow($rows, 'gprofile');
        $socleRow = $this->appRow($rows, 'gsocle');

        self::assertSame('group_self', $direct['primary']['kind']);
        self::assertSame('group_profile', $viaProfile['primary']['kind']);
        self::assertSame('ProfilBureautique', $viaProfile['primary']['via']);
        self::assertSame('parc_default', $socleRow['primary']['kind']);
    }

    #[Test]
    public function group_page_shortcuts_are_ce_parc(): void
    {
        $parc = WorkstationGroup::create(['name' => 'parc-sc-page', 'is_physical' => false]);
        $sc = $this->newShortcut('ParcShortcut');
        $sc->workstationGroups()->attach($parc->id);

        $rows = $this->service->shortcutsForGroup($parc);

        self::assertCount(1, $rows);
        self::assertSame('group_self', $rows[0]['primary']['kind']);
        self::assertSame('ParcShortcut', $rows[0]['label']);
    }

    #[Test]
    public function physical_room_own_contribution_is_room_self(): void
    {
        // Review #5 — sur la page d'une SALLE physique, la contribution propre est
        // étiquetée `room_self` (« Cette salle », badge-warning) et non `group_self`
        // (« Ce parc ») — cohérent D6/AC4 (salle/parc distingués partout).
        $salle = WorkstationGroup::create(['name' => 'salle-self', 'is_physical' => true]);

        $app = $this->newApp('roomapp');
        $salle->applications()->attach([$app->id]);

        $sc = $this->newShortcut('RoomOwn');
        $sc->workstationGroups()->attach($salle->id);

        $appRow = $this->appRow($this->service->applicationsForGroup($salle), 'roomapp');
        self::assertNotNull($appRow);
        self::assertSame('room_self', $appRow['primary']['kind']);

        $shortcuts = $this->service->shortcutsForGroup($salle);
        self::assertCount(1, $shortcuts);
        self::assertSame('room_self', $shortcuts[0]['primary']['kind']);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Jobs\SyncAllFromAdJob;
use App\Models\AppProfile;
use App\Models\ErrorLog;
use App\Models\WorkstationGroup;
use App\Observers\AppProfileObserver;
use App\Observers\WorkstationGroupObserver;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\WpkgSchemaBootstrapper;
use Tests\TestCase;

/**
 * Story 15.3 / AC5.2 — Tests feature `SyncAllFromAdJob` durci.
 *
 * Couvre les 11 scénarios listés au tableau AC5.2 sans toucher à
 * LdapRecord : on injecte les fixtures AD via la sous-classe stub
 * `SyncAllFromAdJobStub` (les méthodes `fetchParcsFromAd()` /
 * `fetchGroupesFromAd()` sont `protected` justement pour cette raison —
 * cf. note dans le job).
 *
 * Patterns :
 * - Schéma SQLite shim via `WpkgSchemaBootstrapper` (15.2).
 * - Cache `array` (driver testing) — le lock `Cache::lock('wpkg:…')` est
 *   un fake lock (pas réel) ; pour le scénario lock anti-double-clic on
 *   acquiert manuellement le lock avant l'appel et on vérifie le retour
 *   `skipped_lock=true`.
 */
class SyncAllFromAdJobTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        WpkgSchemaBootstrapper::bootstrap();
        $this->ensureErrorLogsTable();
        Cache::flush();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('error_logs');
        WpkgSchemaBootstrapper::tearDown();
        parent::tearDown();
    }

    /**
     * Le bootstrap WPKG n'inclut pas `error_logs` (utilisé par `ErrorLoggerService`
     * lors de la détection de divergences nom AD/SQL ou de conflits GUID — Q2/#2).
     */
    private function ensureErrorLogsTable(): void
    {
        if (Schema::hasTable('error_logs')) {
            return;
        }

        Schema::create('error_logs', function ($table): void {
            $table->id();
            $table->string('source', 10);
            $table->text('message');
            $table->timestamp('created_at')->nullable();
        });
    }

    private function fixtureGroupe(string $name, string $guid, ?string $description = null, ?string $dn = null): array
    {
        return [
            'name' => $name,
            'description' => $description,
            'dn' => $dn ?? "OU={$name},OU=Computers,DC=test,DC=local",
            'uuid' => $guid,
        ];
    }

    private function fixtureParc(string $name, string $guid, ?string $description = null, ?string $dn = null): array
    {
        return [
            'name' => $name,
            'description' => $description,
            'dn' => $dn ?? "OU={$name},OU=Parcs,DC=test,DC=local",
            'uuid' => $guid,
        ];
    }

    #[Test]
    public function creates_workstation_group_when_absent_in_db(): void
    {
        $job = new SyncAllFromAdJobStub(
            parcsAd: [],
            groupesAd: [$this->fixtureGroupe('parc-physique-A', 'guid-1')]
        );

        $stats = $job->handle();

        self::assertSame(1, $stats['workstation_groups']['created']);
        self::assertSame(0, $stats['workstation_groups']['archived']);
        self::assertDatabaseHas('workstation_groups', [
            'name' => 'parc-physique-A',
            'ad_guid' => 'guid-1',
        ]);
    }

    #[Test]
    public function creates_app_profile_when_absent_in_db(): void
    {
        $job = new SyncAllFromAdJobStub(
            parcsAd: [$this->fixtureParc('profil-X', 'guid-px')],
            groupesAd: []
        );

        $stats = $job->handle();

        self::assertSame(1, $stats['app_profiles']['created']);
        self::assertDatabaseHas('app_profiles', [
            'name' => 'profil-X',
            'ad_guid' => 'guid-px',
        ]);
    }

    #[Test]
    public function rename_in_ad_does_not_overwrite_local_name_and_logs_divergence(): void
    {
        // Décision Q2 (review post 15.3) : Eloquent reste souverain sur le
        // `name` SQL. Une divergence de nom AD/SQL pour un GUID matché ne
        // doit PAS écraser le `name` local ; elle doit produire un log
        // info, une entrée `error_logs` source `wpkg`, et incrémenter le
        // compteur `name_divergences` du rapport stats.
        $g = WorkstationGroup::create([
            'name' => 'old-name',
            'ad_guid' => 'guid-1',
            'ad_dn' => 'OU=old-name,OU=Computers,DC=test,DC=local',
        ]);

        $job = new SyncAllFromAdJobStub(
            parcsAd: [],
            groupesAd: [$this->fixtureGroupe('new-name', 'guid-1')]
        );

        $stats = $job->handle();

        $g->refresh();
        // (a) `name` SQL préservé (Eloquent souverain).
        self::assertSame('old-name', $g->name, 'le name SQL ne doit pas être écrasé par le name AD');

        // (b) Compteur `name_divergences` incrémenté.
        self::assertSame(1, $stats['workstation_groups']['name_divergences']);

        // (c) Entrée error_logs source=wpkg avec mention du GUID.
        $errorLog = ErrorLog::query()->where('source', 'wpkg')->first();
        self::assertNotNull($errorLog, 'une entrée error_logs source=wpkg doit être créée pour la divergence');
        self::assertStringContainsString('guid-1', $errorLog->message);
        self::assertStringContainsString('old-name', $errorLog->message);
        self::assertStringContainsString('new-name', $errorLog->message);
    }

    #[Test]
    public function archives_orphan_db_row_when_absent_in_ad(): void
    {
        WorkstationGroup::create([
            'name' => 'orphan',
            'ad_guid' => 'guid-orphan',
        ]);

        $job = new SyncAllFromAdJobStub(parcsAd: [], groupesAd: []);
        $stats = $job->handle();

        self::assertSame(1, $stats['workstation_groups']['archived']);

        $row = WorkstationGroup::where('name', 'orphan')->first();
        self::assertNotNull($row->archived_at);
    }

    #[Test]
    public function noop_when_state_is_aligned(): void
    {
        WorkstationGroup::create([
            'name' => 'aligned',
            'ad_guid' => 'guid-A',
            'ad_dn' => 'OU=aligned,OU=Computers,DC=test,DC=local',
        ]);

        $job = new SyncAllFromAdJobStub(
            parcsAd: [],
            groupesAd: [$this->fixtureGroupe('aligned', 'guid-A')]
        );

        $stats = $job->handle();

        self::assertSame(0, $stats['workstation_groups']['created']);
        self::assertSame(0, $stats['workstation_groups']['archived']);
        self::assertSame(0, $stats['workstation_groups']['updated']);
        self::assertTrue($stats['idempotent']);
    }

    #[Test]
    public function dry_run_produces_diff_without_writes(): void
    {
        $job = new SyncAllFromAdJobStub(
            parcsAd: [],
            groupesAd: [$this->fixtureGroupe('to-create', 'guid-1')],
            dryRun: true,
        );

        $stats = $job->handle();

        // Le compteur reflète ce qui aurait été créé...
        self::assertSame(1, $stats['workstation_groups']['created']);
        // ...mais aucune ligne n'a été persistée.
        self::assertDatabaseMissing('workstation_groups', ['name' => 'to-create']);
    }

    #[Test]
    public function lock_blocks_concurrent_run(): void
    {
        // Acquisition manuelle du lock pour simuler une exécution concurrente.
        $heldLock = Cache::lock('wpkg:sync-all-from-ad', 60);
        self::assertTrue($heldLock->get());

        try {
            $job = new SyncAllFromAdJobStub(parcsAd: [], groupesAd: []);
            $stats = $job->handle();

            self::assertTrue($stats['skipped_lock']);
            self::assertTrue($stats['idempotent']);
            self::assertSame('lock_not_acquired', $stats['aborted_reason']);
        } finally {
            $heldLock->release();
        }
    }

    #[Test]
    public function pass1_failure_aborts_without_writes(): void
    {
        WorkstationGroup::create(['name' => 'existing', 'ad_guid' => 'guid-pre']);

        $job = new SyncAllFromAdJobStub(
            parcsAd: [],
            groupesAd: [],
            throwOnFetchGroupes: true,
        );

        $stats = $job->handle();

        self::assertNotNull($stats['aborted_reason']);
        self::assertStringContainsString('pass1_failed', $stats['aborted_reason']);
        // L'orphelin existant n'a PAS été archivé (atomicité stricte).
        $row = WorkstationGroup::where('name', 'existing')->first();
        self::assertNull($row->archived_at);
    }

    #[Test]
    public function idempotent_on_consecutive_runs(): void
    {
        $job1 = new SyncAllFromAdJobStub(
            parcsAd: [],
            groupesAd: [$this->fixtureGroupe('persist', 'guid-1')]
        );
        $stats1 = $job1->handle();
        self::assertSame(1, $stats1['workstation_groups']['created']);
        self::assertFalse($stats1['idempotent']);

        $job2 = new SyncAllFromAdJobStub(
            parcsAd: [],
            groupesAd: [$this->fixtureGroupe('persist', 'guid-1')]
        );
        $stats2 = $job2->handle();

        self::assertSame(0, $stats2['workstation_groups']['created']);
        self::assertSame(0, $stats2['workstation_groups']['updated']);
        self::assertSame(0, $stats2['workstation_groups']['archived']);
        self::assertTrue($stats2['idempotent']);
    }

    #[Test]
    public function first_run_strict_match_writes_guid_only_on_correct_scope(): void
    {
        // Cas faux positif : 1 row DB sans GUID, 2 rows AD homonymes
        // dont une dans OU=Parcs (mauvais scope pour un WorkstationGroup
        // qui doit être OU=Computers). Le match strict ne doit binder le
        // GUID que pour celui d'OU=Computers.
        WorkstationGroup::create(['name' => 'pc01', 'ad_guid' => null]);

        $job = new SyncAllFromAdJobStub(
            parcsAd: [],
            // Note : si le fetcher AD renvoyait un tuple « pc01 » dont le DN
            // ne contient pas OU=Computers, on doit refuser le match.
            groupesAd: [
                $this->fixtureGroupe('pc01', 'guid-correct-scope', null,
                    'OU=pc01,OU=Computers,DC=test,DC=local'),
            ],
        );
        $job->handle();

        $row = WorkstationGroup::where('name', 'pc01')->first();
        self::assertSame('guid-correct-scope', $row->ad_guid);

        // Réinitialise et tente un match avec un mauvais scope (OU=Parcs) →
        // pas de match : le `name` lower-case n'est pas tenté hors scope.
        WorkstationGroup::query()->delete();
        WorkstationGroup::create(['name' => 'pc02', 'ad_guid' => null]);

        $job2 = new SyncAllFromAdJobStub(
            parcsAd: [],
            groupesAd: [
                $this->fixtureGroupe('pc02', 'guid-wrong-scope', null,
                    'OU=pc02,OU=Parcs,DC=test,DC=local'),
            ],
        );
        $job2->handle();

        $row2 = WorkstationGroup::where('name', 'pc02')->first();
        // La row DB existante n'a pas reçu le GUID (scope OU=Parcs ≠ OU=Computers).
        // À la place, une nouvelle row a été créée — la fonctionnalité de
        // « match nom strict + scope » est donc bien protectrice.
        self::assertNull($row2->ad_guid, 'la row originelle ne doit pas matcher hors scope OU=Computers');
        self::assertSame(2, WorkstationGroup::where('name', 'pc02')->count());
    }

    #[Test]
    public function never_overwrites_existing_guid(): void
    {
        WorkstationGroup::create([
            'name' => 'pre-bound',
            'ad_guid' => 'guid-original',
            'ad_dn' => 'OU=pre-bound,OU=Computers,DC=test,DC=local',
        ]);

        // L'AD renvoie le même GUID — match propre, pas d'écrasement.
        $job = new SyncAllFromAdJobStub(
            parcsAd: [],
            groupesAd: [$this->fixtureGroupe('pre-bound', 'guid-original')],
        );
        $job->handle();

        $row = WorkstationGroup::where('name', 'pre-bound')->first();
        self::assertSame('guid-original', $row->ad_guid, 'le GUID existant ne doit jamais être écrasé');
    }

    #[Test]
    public function profile_group_links_are_idempotent(): void
    {
        $g = WorkstationGroup::create(['name' => 'parc1', 'ad_guid' => 'guid-g']);
        $p = AppProfile::create(['name' => 'parc1', 'ad_guid' => 'guid-p']);

        $job = new SyncAllFromAdJobStub(
            parcsAd: [$this->fixtureParc('parc1', 'guid-p')],
            groupesAd: [$this->fixtureGroupe('parc1', 'guid-g')],
        );
        $stats = $job->handle();

        self::assertSame(1, $stats['profile_group_links']['created']);
        self::assertTrue($p->workstationGroups()->where('workstation_group_id', $g->id)->exists());

        // Run #2 : lien déjà présent → skipped, idempotent.
        $job2 = new SyncAllFromAdJobStub(
            parcsAd: [$this->fixtureParc('parc1', 'guid-p')],
            groupesAd: [$this->fixtureGroupe('parc1', 'guid-g')],
        );
        $stats2 = $job2->handle();
        self::assertSame(0, $stats2['profile_group_links']['created']);
        self::assertGreaterThanOrEqual(1, $stats2['profile_group_links']['skipped']);
    }

    #[Test]
    public function archived_row_increments_restored_counter_when_reappears_in_ad(): void
    {
        // Correctif review #M3 : la restauration incrémente un compteur
        // dédié `restored` (et non `updated`), pour visibilité opérateur.
        WorkstationGroup::create([
            'name' => 'phoenix',
            'ad_guid' => 'guid-phoenix',
            'ad_dn' => 'OU=phoenix,OU=Computers,DC=test,DC=local',
            'archived_at' => now()->subDay(),
        ]);

        $job = new SyncAllFromAdJobStub(
            parcsAd: [],
            groupesAd: [$this->fixtureGroupe('phoenix', 'guid-phoenix')],
        );
        $stats = $job->handle();

        $row = WorkstationGroup::where('name', 'phoenix')->first();
        self::assertNull($row->archived_at, 'une row archivée doit être restaurée si l\'AD la renvoie à nouveau');

        self::assertSame(1, $stats['workstation_groups']['restored'], 'compteur restored incrémenté');
        self::assertSame(0, $stats['workstation_groups']['updated'], 'updated reste 0 (la restauration ne compte pas comme update)');
    }

    #[Test]
    public function pass2_disables_app_profile_observer_to_prevent_outbound_sync(): void
    {
        // Correctif review #1 : `AppProfileObserver::disableSync()` doit
        // être appelé en début de passe 2 (et `enableSync()` en finally),
        // sinon les `AppProfile::create()` / `save()` du job
        // déclencheraient des `AppProfileAdSyncJob` sortants qui
        // réécriraient en AD ce qu'on vient juste de lire.
        \Illuminate\Support\Facades\Queue::fake();

        // Le bootstrapper de test mute le dispatcher Eloquent (offline) ;
        // on le ré-attache pour observer le comportement réel des observers.
        \Illuminate\Database\Eloquent\Model::setEventDispatcher(app('events'));
        AppProfile::observe(\App\Observers\AppProfileObserver::class);
        WorkstationGroup::observe(WorkstationGroupObserver::class);

        try {
            self::assertTrue(AppProfileObserver::$syncEnabled, 'baseline : AppProfileObserver actif');

            $job = new SyncAllFromAdJobStub(
                parcsAd: [$this->fixtureParc('new-profile', 'guid-new-p')],
                groupesAd: [],
            );
            $job->handle();

            // Vérification clé : aucun job sortant AppProfile n'a été dispatché
            // pendant la passe 2 (observer désactivé correctement).
            \Illuminate\Support\Facades\Queue::assertNotPushed(\App\Jobs\AdSync\AppProfileAdSyncJob::class);

            // Et l'observer est réactivé en finally.
            self::assertTrue(AppProfileObserver::$syncEnabled, 'AppProfileObserver doit être réactivé en finally');
        } finally {
            \Illuminate\Database\Eloquent\Model::unsetEventDispatcher();
            AppProfileObserver::enableSync();
            WorkstationGroupObserver::enableSync();
        }
    }

    #[Test]
    public function conflict_guid_aborts_with_clean_log_and_lock_released(): void
    {
        // Correctif review #2 : si la DB contient 2 rows avec le même
        // `ad_guid` (corruption historique), le job doit (a) lever une
        // RuntimeException, (b) incrémenter `conflicts`, (c) écrire dans
        // error_logs, (d) libérer le lock pour permettre une 2e exec
        // après remédiation manuelle, (e) réactiver l'observer.
        WorkstationGroup::create(['name' => 'dupe-1', 'ad_guid' => 'guid-conflict']);
        WorkstationGroup::create(['name' => 'dupe-2', 'ad_guid' => 'guid-conflict']);

        $job = new SyncAllFromAdJobStub(parcsAd: [], groupesAd: []);

        $thrown = null;
        try {
            $job->handle();
        } catch (\Throwable $e) {
            $thrown = $e;
        }

        self::assertInstanceOf(\RuntimeException::class, $thrown, 'le conflit GUID doit lever une RuntimeException');
        self::assertStringContainsString('Conflit GUID', $thrown->getMessage());

        // (c) Entrée error_logs source=wpkg.
        $err = ErrorLog::query()->where('source', 'wpkg')->first();
        self::assertNotNull($err);
        self::assertStringContainsString('Conflit GUID', $err->message);
        self::assertStringContainsString('guid-conflict', $err->message);

        // (d) Lock libéré → un 2e job peut acquérir le lock et tourner
        // (ici on lui passe une DB sans conflit pour qu'il termine).
        WorkstationGroup::query()->where('name', 'dupe-2')->delete();

        $job2 = new SyncAllFromAdJobStub(parcsAd: [], groupesAd: []);
        $stats2 = $job2->handle();
        self::assertFalse($stats2['skipped_lock'], 'le lock doit avoir été libéré par le finally du 1er run');

        // (e) Observer réactivé.
        self::assertTrue(WorkstationGroupObserver::$syncEnabled);
        self::assertTrue(AppProfileObserver::$syncEnabled);
    }
}

/**
 * Stub testable de `SyncAllFromAdJob` : injecte des fixtures AD à la
 * place de la lecture LdapRecord réelle. Permet de couvrir tous les
 * scénarios AC5.2 sans dépendance LDAP.
 */
class SyncAllFromAdJobStub extends SyncAllFromAdJob
{
    public function __construct(
        private array $parcsAd,
        private array $groupesAd,
        bool $dryRun = false,
        private bool $throwOnFetchGroupes = false,
        private bool $throwOnFetchParcs = false,
    ) {
        parent::__construct($dryRun);
    }

    protected function fetchParcsFromAd(): array
    {
        if ($this->throwOnFetchParcs) {
            throw new \RuntimeException('AD partial: parcs fetch failed (test fixture)');
        }

        return $this->parcsAd;
    }

    protected function fetchGroupesFromAd(): array
    {
        if ($this->throwOnFetchGroupes) {
            throw new \RuntimeException('AD partial: groupes fetch failed (test fixture)');
        }

        return $this->groupesAd;
    }
}

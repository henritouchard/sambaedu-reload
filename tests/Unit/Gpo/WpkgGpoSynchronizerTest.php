<?php

declare(strict_types=1);

namespace Tests\Unit\Gpo;

use App\Config\SambaEduConfig;
use App\Gpo\Dto\GpoSummary;
use App\Gpo\Enums\WpkgGpoSyncSeverity;
use App\Gpo\Services\GpoService;
use App\Gpo\Services\WpkgGpoSynchronizer;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tests Unit `WpkgGpoSynchronizer` — Story 16.6 (AC1.* + AC2.*).
 *
 * Pattern iso `ReadUserManager` (16.3b) : on mocke `GpoService` et
 * `SambaEduConfig` directement ; le shim legacy `import_gpo` est remplacé
 * par un binding container `legacy.import_gpo` (closure testable, pattern
 * iso 16.3c `legacy.get_wine_shortcuts`).
 *
 * Note review fix #3 : l'appel séparé à `specialise_gpo` a été supprimé —
 * `import_gpo` enchaîne déjà `unzip_gpo → specialise_gpo → sysvol_put` en
 * interne (TD-16.6-1). On ne mocke donc plus `legacy.specialise_gpo`.
 */
class WpkgGpoSynchronizerTest extends TestCase
{
    private string $fixturePath = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixturePath = $this->makeFixtureTemplate(['SE4FS_NAME', 'DOMAIN', 'DOMAIN_SID']);
        config(['sambaedu.gpo.wpkg_sync.template_path' => $this->fixturePath]);
        config(['sambaedu.gpo.wpkg_sync.bearer_required' => false]);
    }

    protected function tearDown(): void
    {
        if ($this->fixturePath !== '' && is_dir($this->fixturePath)) {
            $this->rrmdir($this->fixturePath);
        }
        Mockery::close();
        parent::tearDown();
    }

    /**
     * Crée un répertoire fixture (équivalent à un `.zip` déballé) avec un
     * script `.cmd` contenant les placeholders demandés. Mode "directory"
     * supporté par le synchronizer pour permettre les tests host sans
     * `ext-zip` (cf. `scanDirectoryPlaceholders`).
     */
    private function makeFixtureTemplate(array $placeholders, ?string $extraContent = null): string
    {
        $base = sys_get_temp_dir() . '/se4_wpkg_test_' . uniqid('', true);
        $startupDir = $base . '/Machine/Scripts/Startup';
        mkdir($startupDir, 0700, true);

        $script = "@echo off\r\n";
        foreach ($placeholders as $p) {
            $script .= "set TARGET_{$p}=###_{$p}_###\r\n";
        }
        $script .= 'cscript wpkg.js /server=###_SE4FS_NAME_### /profile=%hostname%' . "\r\n";
        if ($extraContent !== null) {
            $script .= $extraContent;
        }
        file_put_contents($startupDir . '/wpkg.cmd', $script);
        file_put_contents($base . '/GPT.INI', "[General]\r\ndisplayName=se4_wpkg\r\nVersion=1\r\n");

        return $base;
    }

    private function rrmdir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $iter = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iter as $f) {
            if ($f->isDir()) {
                @rmdir($f->getPathname());
            } else {
                @unlink($f->getPathname());
            }
        }
        @rmdir($dir);
    }

    /**
     * @param  array<int, GpoSummary>  $listResult
     */
    private function makeSynchronizer(
        array $listResult = [],
        array $containers = [],
        array $configKv = [],
        ?\Throwable $listThrow = null,
    ): WpkgGpoSynchronizer {
        $gpoService = Mockery::mock(GpoService::class);
        if ($listThrow !== null) {
            $gpoService->shouldReceive('list')->andThrow($listThrow);
        } else {
            $gpoService->shouldReceive('list')->andReturn(new Collection($listResult));
        }
        $gpoService->shouldReceive('listContainers')->andReturn($containers)->byDefault();

        $samba = Mockery::mock(SambaEduConfig::class);
        $samba->shouldReceive('get')->andReturnUsing(
            fn (string $k, mixed $default = null) => $configKv[$k] ?? $default,
        );

        return new WpkgGpoSynchronizer($gpoService, $samba);
    }

    private function makeWpkgGpoSummary(): GpoSummary
    {
        return new GpoSummary(
            name: '{AAAAAAAA-BBBB-CCCC-DDDD-EEEEEEEEEEEE}',
            displayName: 'se4_wpkg',
            versionNumber: 12,
            dn: 'CN={AAAAAAAA-BBBB-CCCC-DDDD-EEEEEEEEEEEE},CN=Policies,CN=System,DC=example,DC=org',
            path: '\\\\example.org\\sysvol\\example.org\\Policies\\{AAAAAAAA-BBBB-CCCC-DDDD-EEEEEEEEEEEE}',
        );
    }

    // -------------------------------------------------------------------------
    // Volet 1 — audit()
    // -------------------------------------------------------------------------

    #[Test]
    public function audit_returns_error_severity_when_gpo_not_found(): void
    {
        $sync = $this->makeSynchronizer(listResult: []);
        $r = $sync->audit();

        self::assertFalse($r->gpoExists);
        self::assertNull($r->gpoGuid);
        self::assertSame(WpkgGpoSyncSeverity::Error, $r->severity);
        self::assertTrue($this->messagesContain($r->messages, 'introuvable'));
    }

    #[Test]
    public function audit_returns_warning_when_gpo_exists_but_unlinked(): void
    {
        $sync = $this->makeSynchronizer(
            listResult: [$this->makeWpkgGpoSummary()],
            containers: [],
        );
        $r = $sync->audit();

        self::assertTrue($r->gpoExists);
        self::assertSame('{AAAAAAAA-BBBB-CCCC-DDDD-EEEEEEEEEEEE}', $r->gpoGuid);
        self::assertSame([], $r->linkedOus);
        self::assertSame(WpkgGpoSyncSeverity::Warning, $r->severity);
        self::assertTrue($this->messagesContain($r->messages, 'aucune OU'));
    }

    #[Test]
    public function audit_returns_ok_when_all_checks_pass(): void
    {
        $sync = $this->makeSynchronizer(
            listResult: [$this->makeWpkgGpoSummary()],
            containers: ['OU=Computers,DC=example,DC=org'],
        );
        $r = $sync->audit();

        self::assertSame(WpkgGpoSyncSeverity::Ok, $r->severity);
        self::assertTrue($r->gpoExists);
        self::assertTrue($r->templateExists);
        self::assertNotEmpty($r->detectedPlaceholders);
        self::assertSame([], $r->unknownPlaceholders);
    }

    #[Test]
    public function audit_flags_template_missing_as_error(): void
    {
        config(['sambaedu.gpo.wpkg_sync.template_path' => '/does/not/exist.zip']);
        $sync = $this->makeSynchronizer(
            listResult: [$this->makeWpkgGpoSummary()],
            containers: ['OU=Computers,DC=example,DC=org'],
        );
        $r = $sync->audit();

        self::assertFalse($r->templateExists);
        self::assertSame(WpkgGpoSyncSeverity::Error, $r->severity);
        self::assertTrue($this->messagesContain($r->messages, 'non trouvé'));
    }

    #[Test]
    public function audit_detects_placeholder_outside_whitelist(): void
    {
        $bad = $this->makeFixtureTemplate(['SE4FS_NAME', 'INJECTED_KEY']);
        config(['sambaedu.gpo.wpkg_sync.template_path' => $bad]);

        try {
            $sync = $this->makeSynchronizer(
                listResult: [$this->makeWpkgGpoSummary()],
                containers: ['OU=Computers,DC=example,DC=org'],
            );
            $r = $sync->audit();

            self::assertContains('INJECTED_KEY', $r->unknownPlaceholders);
            self::assertSame(WpkgGpoSyncSeverity::Error, $r->severity);
        } finally {
            $this->rrmdir($bad);
        }
    }

    #[Test]
    public function audit_template_present_flag_reflects_disk_state(): void
    {
        $sync = $this->makeSynchronizer(
            listResult: [$this->makeWpkgGpoSummary()],
            containers: ['OU=Computers,DC=example,DC=org'],
        );
        $r = $sync->audit();
        self::assertTrue($r->templateExists);
    }

    #[Test]
    public function audit_resolves_route_urls_in_report(): void
    {
        // Définition d'une APP_URL stable pour rendre les routes absolues prévisibles.
        $sync = $this->makeSynchronizer(
            listResult: [$this->makeWpkgGpoSummary()],
            containers: ['OU=Computers,DC=example,DC=org'],
        );
        $r = $sync->audit();
        self::assertStringContainsString('hosts.xml', $r->expectedHostsXmlUrl);
        self::assertStringContainsString('profiles.xml', $r->expectedProfilesXmlUrl);
    }

    #[Test]
    public function audit_is_idempotent_two_consecutive_calls_return_same_state(): void
    {
        $sync = $this->makeSynchronizer(
            listResult: [$this->makeWpkgGpoSummary()],
            containers: ['OU=Computers,DC=example,DC=org'],
        );
        $r1 = $sync->audit();
        $r2 = $sync->audit();
        self::assertSame($r1->gpoExists, $r2->gpoExists);
        self::assertSame($r1->gpoGuid, $r2->gpoGuid);
        self::assertSame($r1->severity, $r2->severity);
        self::assertSame($r1->detectedPlaceholders, $r2->detectedPlaceholders);
        // operationId distinct car nouveau UUID à chaque appel.
        self::assertNotSame($r1->operationId, $r2->operationId);
    }

    #[Test]
    public function audit_marks_bearer_table_unavailable_when_schema_missing(): void
    {
        $sync = $this->makeSynchronizer(
            listResult: [$this->makeWpkgGpoSummary()],
            containers: ['OU=Computers,DC=example,DC=org'],
        );
        $r = $sync->audit();
        // Sans migration `workstation_api_secrets` (cas par défaut en testing
        // CI sans 15.5), le DTO le signale via flag dédié.
        self::assertFalse($r->bearerTableAvailable);
    }

    // -------------------------------------------------------------------------
    // Volet 2 — publish()
    // -------------------------------------------------------------------------

    #[Test]
    public function publish_throws_if_template_missing(): void
    {
        config(['sambaedu.gpo.wpkg_sync.template_path' => '/does/not/exist.zip']);
        $sync = $this->makeSynchronizer(
            listResult: [],
            containers: [],
            configKv: ['se4fs_name' => 'se4fs', 'domain' => 'example.org'],
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Template officiel/');
        $sync->publish();
    }

    #[Test]
    public function publish_is_noop_when_severity_ok_and_not_forced(): void
    {
        $sync = $this->makeSynchronizer(
            listResult: [$this->makeWpkgGpoSummary()],
            containers: ['OU=Computers,DC=example,DC=org'],
        );

        $imported = false;
        app()->bind('legacy.import_gpo', function () use (&$imported) {
            return function () use (&$imported) { $imported = true; return true; };
        });

        $r = $sync->publish(false);

        self::assertFalse($imported, 'no-op : import_gpo ne doit pas être appelé');
        self::assertSame(WpkgGpoSyncSeverity::Ok, $r->severity);
        self::assertTrue($this->messagesContain($r->messages, 'déjà à jour'));
    }

    #[Test]
    public function publish_forces_reimport_with_force_true(): void
    {
        $sync = $this->makeSynchronizer(
            listResult: [$this->makeWpkgGpoSummary()],
            containers: ['OU=Computers,DC=example,DC=org'],
            configKv: ['se4fs_name' => 'se4fs', 'domain' => 'example.org', 'samba_domain' => 'EXAMPLE'],
        );

        $importedArgs = null;
        app()->bind('legacy.import_gpo', function () use (&$importedArgs) {
            return function (array $cfg, string $name, string $archive, bool $update, bool $force) use (&$importedArgs) {
                $importedArgs = compact('cfg', 'name', 'archive', 'update', 'force');
                return true;
            };
        });

        $r = $sync->publish(true);

        self::assertNotNull($importedArgs, 'import_gpo doit être invoqué');
        self::assertSame('se4fs', $importedArgs['cfg']['se4fs_name']);
        self::assertSame('se4_wpkg', $importedArgs['name']);
        self::assertSame('se4_wpkg.zip', $importedArgs['archive']);
        self::assertTrue($importedArgs['force']);
        self::assertSame(WpkgGpoSyncSeverity::Ok, $r->severity);
    }

    #[Test]
    public function publish_throws_when_import_returns_false(): void
    {
        $sync = $this->makeSynchronizer(
            listResult: [$this->makeWpkgGpoSummary()],
            containers: ['OU=Computers,DC=example,DC=org'],
            configKv: ['se4fs_name' => 'se4fs'],
        );

        app()->bind('legacy.import_gpo', fn () => fn (array $cfg, string $name, string $archive, bool $update, bool $force) => false);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/retourné `false`/');
        $sync->publish(true);
    }

    #[Test]
    public function publish_blocks_concurrent_with_cache_lock(): void
    {
        // Review fix #10/#4 : la valeur par défaut a été remontée 60→300 / 10→30
        // pour absorber un `import_gpo` lent. Vérifie aussi que les valeurs
        // proviennent bien de la config (override testing → fallback default).
        config(['sambaedu.gpo.wpkg_sync.lock_timeout' => 300]);
        config(['sambaedu.gpo.wpkg_sync.lock_wait' => 30]);

        $lock = Mockery::mock(Lock::class);
        $lock->shouldReceive('block')->with(30)->andReturn(false);
        Cache::shouldReceive('lock')->with('gpo:wpkg:sync', 300)->andReturn($lock);

        $sync = $this->makeSynchronizer(
            listResult: [$this->makeWpkgGpoSummary()],
            containers: ['OU=Computers,DC=example,DC=org'],
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/déjà en cours/');
        $sync->publish(true);
    }

    #[Test]
    public function publish_lock_values_are_configurable(): void
    {
        config(['sambaedu.gpo.wpkg_sync.lock_timeout' => 120]);
        config(['sambaedu.gpo.wpkg_sync.lock_wait' => 5]);

        $lock = Mockery::mock(Lock::class);
        $lock->shouldReceive('block')->with(5)->andReturn(false);
        Cache::shouldReceive('lock')->with('gpo:wpkg:sync', 120)->andReturn($lock);

        $sync = $this->makeSynchronizer(
            listResult: [$this->makeWpkgGpoSummary()],
            containers: ['OU=Computers,DC=example,DC=org'],
        );

        $this->expectException(\RuntimeException::class);
        $sync->publish(true);
    }

    #[Test]
    public function publish_runs_initial_when_gpo_absent(): void
    {
        $sync = $this->makeSynchronizer(
            listResult: [],
            containers: [],
            configKv: ['se4fs_name' => 'se4fs'],
        );

        $imported = false;
        app()->bind('legacy.import_gpo', function () use (&$imported) {
            return function () use (&$imported) { $imported = true; return true; };
        });

        // Le re-audit final renvoie un DTO de sévérité Error (la GPO reste
        // absente du mock `list`), mais `publish()` ne throw pas pour ça :
        // il retourne simplement le DTO post-audit. Review fix #H : on
        // assertit explicitement l'appel du shim et la sévérité finale.
        $report = $sync->publish(false);

        self::assertTrue($imported, 'import_gpo doit être appelé en création initiale');
        self::assertSame(
            WpkgGpoSyncSeverity::Error,
            $report->severity,
            'Re-audit post-publish : GPO toujours introuvable côté mock → severity Error.',
        );
    }

    // -------------------------------------------------------------------------
    // Volet 3 — auditBearerCoverage (review fix #8 — couverture branche présente)
    // -------------------------------------------------------------------------

    /**
     * Crée les tables `workstations` + `workstation_api_secrets` minimales en
     * SQLite in-memory pour tester la branche `Schema::hasTable=true`. Schéma
     * réduit aux colonnes lues par le synchronizer (suffit pour le garde-fou
     * unitaire — pas iso 15.5).
     */
    private function bootstrapBearerTables(): void
    {
        Schema::create('workstations', function ($table) {
            $table->bigIncrements('id');
            $table->string('name');
            $table->string('ad_dn')->nullable();
            $table->timestamp('archived_at')->nullable();
        });
        // Le code utilise `workstation_name` dans la requête (cf. limitation
        // documentée) — on créé la table avec cette colonne pour tester la
        // jointure logique.
        Schema::create('workstation_api_secrets', function ($table) {
            $table->bigIncrements('id');
            $table->string('workstation_name');
            $table->timestamp('revoked_at')->nullable();
        });
    }

    private function teardownBearerTables(): void
    {
        Schema::dropIfExists('workstation_api_secrets');
        Schema::dropIfExists('workstations');
    }

    #[Test]
    public function audit_bearer_coverage_ok_when_all_workstations_have_secret(): void
    {
        $this->bootstrapBearerTables();
        try {
            DB::table('workstations')->insert([
                ['name' => 'pc-01', 'ad_dn' => 'CN=pc-01,OU=Computers,DC=example,DC=org'],
                ['name' => 'pc-02', 'ad_dn' => 'CN=pc-02,OU=Computers,DC=example,DC=org'],
            ]);
            DB::table('workstation_api_secrets')->insert([
                ['workstation_name' => 'pc-01'],
                ['workstation_name' => 'pc-02'],
            ]);

            $sync = $this->makeSynchronizer(
                listResult: [$this->makeWpkgGpoSummary()],
                containers: ['OU=Computers,DC=example,DC=org'],
            );
            $r = $sync->audit();

            self::assertTrue($r->bearerTableAvailable);
            self::assertSame(WpkgGpoSyncSeverity::Ok, $r->severity);
            self::assertNotEmpty($r->bearerCoverage);
            self::assertTrue($r->bearerCoverage['pc-01']);
            self::assertTrue($r->bearerCoverage['pc-02']);
        } finally {
            $this->teardownBearerTables();
        }
    }

    #[Test]
    public function audit_bearer_coverage_message_only_when_partial_and_not_required(): void
    {
        config(['sambaedu.gpo.wpkg_sync.bearer_required' => false]);
        $this->bootstrapBearerTables();
        try {
            // 10 postes liés, 2 sans secret → 20% missing → message info sans
            // bump severity (mode tolérant Phase 1).
            for ($i = 1; $i <= 10; $i++) {
                DB::table('workstations')->insert([
                    'name' => 'pc-' . $i,
                    'ad_dn' => 'CN=pc-' . $i . ',OU=Computers,DC=example,DC=org',
                ]);
            }
            for ($i = 1; $i <= 8; $i++) {
                DB::table('workstation_api_secrets')->insert(['workstation_name' => 'pc-' . $i]);
            }

            $sync = $this->makeSynchronizer(
                listResult: [$this->makeWpkgGpoSummary()],
                containers: ['OU=Computers,DC=example,DC=org'],
            );
            $r = $sync->audit();

            self::assertTrue($r->bearerTableAvailable);
            // Severity reste Ok car bearer_required=false (mode tolérant).
            self::assertSame(WpkgGpoSyncSeverity::Ok, $r->severity);
            self::assertTrue($this->messagesContain($r->messages, 'sans secret Bearer'));
        } finally {
            $this->teardownBearerTables();
        }
    }

    #[Test]
    public function audit_bearer_coverage_error_when_required_and_missing(): void
    {
        config(['sambaedu.gpo.wpkg_sync.bearer_required' => true]);
        $this->bootstrapBearerTables();
        try {
            // 10 postes, 5 sans secret → 50% missing → severity Error.
            for ($i = 1; $i <= 10; $i++) {
                DB::table('workstations')->insert([
                    'name' => 'pc-' . $i,
                    'ad_dn' => 'CN=pc-' . $i . ',OU=Computers,DC=example,DC=org',
                ]);
            }
            for ($i = 1; $i <= 5; $i++) {
                DB::table('workstation_api_secrets')->insert(['workstation_name' => 'pc-' . $i]);
            }

            $sync = $this->makeSynchronizer(
                listResult: [$this->makeWpkgGpoSummary()],
                containers: ['OU=Computers,DC=example,DC=org'],
            );
            $r = $sync->audit();

            self::assertTrue($r->bearerTableAvailable);
            self::assertSame(WpkgGpoSyncSeverity::Error, $r->severity);
        } finally {
            $this->teardownBearerTables();
        }
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function messagesContain(array $messages, string $needle): bool
    {
        foreach ($messages as $m) {
            if (stripos($m, $needle) !== false) {
                return true;
            }
        }
        return false;
    }
}

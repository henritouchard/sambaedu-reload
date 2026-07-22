<?php

declare(strict_types=1);

namespace Tests\Feature\Services\AppProfile;

use App\Models\AppProfile;
use App\Models\Application;
use App\Observers\AppProfileObserver;
use App\Services\AppProfile\AppProfileLegacyApplicationLinker;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Traits\CreatesAppStoreSchema;

/**
 * Tests de la population du pivot app_profile_application depuis les
 * assignations parc du legacy SE4 (étape « Importer les profils applicatifs »).
 *
 * Stratégie (cf. QuotaSeedFromLegacyCommandTest) : la connexion `legacy_mysql`
 * est reconfigurée au runtime vers une 2e BDD sqlite in-memory, où l'on crée le
 * schéma legacy minimal (`applications`, `parc`, `applications_profile`).
 */
class AppProfileLegacyApplicationLinkerTest extends TestCase
{
    use CreatesAppStoreSchema;

    protected function setUp(): void
    {
        parent::setUp();

        // L'observer AppProfile dispatche un job de sync AD (queue sync en test)
        // dès la création d'un profil → on le neutralise pour les fixtures.
        AppProfileObserver::disableSync();

        $this->createAppStoreSchema();
        $this->createAppProfileSchema();
        $this->setupLegacyConnection();
    }

    protected function tearDown(): void
    {
        AppProfileObserver::enableSync();
        try {
            Schema::connection('legacy_mysql')->dropIfExists('applications_profile');
            Schema::connection('legacy_mysql')->dropIfExists('parc');
            Schema::connection('legacy_mysql')->dropIfExists('applications');
        } catch (\Throwable $e) {
            // ignore
        }
        DB::purge('legacy_mysql');
        parent::tearDown();
    }

    private function createAppProfileSchema(): void
    {
        if (! Schema::hasTable('app_profiles')) {
            Schema::create('app_profiles', function (Blueprint $table) {
                $table->id();
                $table->string('name', 100)->unique();
                $table->text('description')->nullable();
                $table->string('ad_guid', 36)->nullable();
                $table->string('ad_dn', 512)->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamp('archived_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('app_profile_application')) {
            Schema::create('app_profile_application', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('app_profile_id');
                $table->unsignedBigInteger('application_id');
                $table->timestamps();
                $table->unique(['app_profile_id', 'application_id'], 'app_profile_application_unique');
            });
        }
    }

    private function setupLegacyConnection(): void
    {
        Config::set('database.connections.legacy_mysql', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        DB::purge('legacy_mysql');

        Schema::connection('legacy_mysql')->create('applications', function (Blueprint $table) {
            $table->integer('id_app');
            $table->string('id_nom_app');
            $table->tinyInteger('active_app')->default(1);
        });
        Schema::connection('legacy_mysql')->create('parc', function (Blueprint $table) {
            $table->integer('id_parc');
            $table->string('nom_parc');
        });
        Schema::connection('legacy_mysql')->create('applications_profile', function (Blueprint $table) {
            $table->id('id_applications_profile');
            $table->integer('id_appli');
            $table->string('type_entite', 10);
            $table->integer('id_entite');
        });
    }

    private function legacyApp(int $idApp, string $idNomApp, int $active = 1): void
    {
        DB::connection('legacy_mysql')->table('applications')->insert([
            'id_app' => $idApp,
            'id_nom_app' => $idNomApp,
            'active_app' => $active,
        ]);
    }

    private function legacyParc(int $idParc, string $nom): void
    {
        DB::connection('legacy_mysql')->table('parc')->insert([
            'id_parc' => $idParc,
            'nom_parc' => $nom,
        ]);
    }

    private function legacyAssign(int $idAppli, string $type, int $idEntite): void
    {
        DB::connection('legacy_mysql')->table('applications_profile')->insert([
            'id_appli' => $idAppli,
            'type_entite' => $type,
            'id_entite' => $idEntite,
        ]);
    }

    private function se5App(string $appId): Application
    {
        return Application::create(['app_id' => $appId, 'name' => $appId, 'status' => 'installed']);
    }

    private function se5Profile(string $name): AppProfile
    {
        return AppProfile::create(['name' => $name, 'is_active' => true]);
    }

    #[Test]
    public function it_links_applications_to_profiles_from_legacy_parc(): void
    {
        $this->legacyApp(1, 'firefox');
        $this->legacyApp(2, '7zip');
        $this->legacyParc(10, 'salle101');
        $this->legacyAssign(1, 'parc', 10);
        $this->legacyAssign(2, 'parc', 10);

        $this->se5App('firefox');
        $this->se5App('7zip');
        $profile = $this->se5Profile('salle101');

        $stats = app(AppProfileLegacyApplicationLinker::class)->linkFromLegacy();

        $this->assertFalse($stats['legacy_unavailable']);
        $this->assertSame(1, $stats['profiles_linked']);
        $this->assertSame(2, $stats['applications_linked']);
        $this->assertSame(0, $stats['applications_missing']);
        $this->assertEqualsCanonicalizing(
            ['firefox', '7zip'],
            $profile->fresh()->applications->pluck('app_id')->all(),
        );
    }

    #[Test]
    public function it_ignores_poste_level_assignments(): void
    {
        $this->legacyApp(1, 'firefox');
        $this->legacyParc(10, 'salle101');
        // Assignation niveau poste : hors-scope, ne doit pas relier le profil.
        $this->legacyAssign(1, 'poste', 999);

        $this->se5App('firefox');
        $profile = $this->se5Profile('salle101');

        $stats = app(AppProfileLegacyApplicationLinker::class)->linkFromLegacy();

        $this->assertSame(0, $stats['applications_linked']);
        $this->assertCount(0, $profile->fresh()->applications);
    }

    #[Test]
    public function it_does_not_flag_inactive_legacy_apps_as_missing(): void
    {
        // App inactive (active_app=0) encore assignée à un parc : absente du
        // catalogue SE5 par nature → ne doit PAS être comptée « manquante » (F2).
        $this->legacyApp(1, 'firefox', active: 0);
        $this->legacyParc(10, 'salle101');
        $this->legacyAssign(1, 'parc', 10);

        $profile = $this->se5Profile('salle101');

        $stats = app(AppProfileLegacyApplicationLinker::class)->linkFromLegacy();

        $this->assertSame(0, $stats['applications_missing']);
        $this->assertSame(0, $stats['applications_linked']);
        $this->assertCount(0, $profile->fresh()->applications);
    }

    #[Test]
    public function it_skips_profiles_without_legacy_parc(): void
    {
        $this->se5Profile('parc-cree-dans-se5');

        $stats = app(AppProfileLegacyApplicationLinker::class)->linkFromLegacy();

        $this->assertSame(1, $stats['profiles_without_legacy_parc']);
        $this->assertSame(0, $stats['applications_linked']);
    }

    #[Test]
    public function it_counts_applications_missing_from_se5_catalog(): void
    {
        $this->legacyApp(1, 'notepad-non-importe');
        $this->legacyParc(10, 'salle101');
        $this->legacyAssign(1, 'parc', 10);

        // Pas d'Application 'notepad-non-importe' côté SE5.
        $profile = $this->se5Profile('salle101');

        $stats = app(AppProfileLegacyApplicationLinker::class)->linkFromLegacy();

        $this->assertSame(1, $stats['applications_missing']);
        $this->assertSame(0, $stats['applications_linked']);
        $this->assertCount(0, $profile->fresh()->applications);
    }

    #[Test]
    public function it_is_non_destructive_and_idempotent(): void
    {
        $this->legacyApp(1, 'firefox');
        $this->legacyParc(10, 'salle101');
        $this->legacyAssign(1, 'parc', 10);

        $firefox = $this->se5App('firefox');
        $manual = $this->se5App('app-manuelle-se5');
        $profile = $this->se5Profile('salle101');
        // Lien manuel préexistant (hors legacy) : doit survivre.
        $profile->applications()->attach($manual->id);

        $linker = app(AppProfileLegacyApplicationLinker::class);
        $first = $linker->linkFromLegacy();
        $this->assertSame(1, $first['applications_linked']);

        // Rejeu : rien de nouveau à attacher.
        $second = $linker->linkFromLegacy();
        $this->assertSame(0, $second['applications_linked']);

        $this->assertEqualsCanonicalizing(
            ['firefox', 'app-manuelle-se5'],
            $profile->fresh()->applications->pluck('app_id')->all(),
        );
    }

    #[Test]
    public function it_reports_legacy_unavailable_when_connection_not_configured(): void
    {
        Config::set('database.connections.legacy_mysql', [
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'database' => '',
            'username' => '',
            'password' => '',
        ]);
        DB::purge('legacy_mysql');

        $this->se5Profile('salle101');

        $stats = app(AppProfileLegacyApplicationLinker::class)->linkFromLegacy();

        $this->assertTrue($stats['legacy_unavailable']);
        $this->assertSame(0, $stats['profiles_processed']);
    }
}

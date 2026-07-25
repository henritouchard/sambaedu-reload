<?php

declare(strict_types=1);

namespace Tests\Feature\Services\AppProfile;

use App\Models\AppProfile;
use App\Models\Application;
use App\Observers\AppProfileObserver;
use App\Services\AppProfile\AppProfileAdImporter;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 38.7 / AC9.1, AC9.2, AC10 — l'étape 7 de sync-from-ad ne réifie un
 * AppProfile QUE si le parc legacy porte des applications ; `_TousLesPostes`
 * n'est jamais un profil, ses applications sont promues en défaut d'établissement
 * (`is_parc_default`). Le seam `parcsEntriesSeam` injecte les entrées OU=Parcs.
 */
class AppProfileAdImporterSelectiveTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        AppProfileObserver::disableSync();
        $this->createSchema();
        $this->setupLegacyConnection();
    }

    protected function tearDown(): void
    {
        AppProfileAdImporter::$parcsEntriesSeam = null;
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

    private function createSchema(): void
    {
        Schema::create('workstation_groups', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->boolean('is_physical')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
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
        Schema::create('app_profile_workstation_group', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('app_profile_id');
            $table->unsignedBigInteger('workstation_group_id');
            $table->timestamps();
        });
        Schema::create('applications', function (Blueprint $table) {
            $table->id();
            $table->string('app_id');
            $table->string('name');
            $table->string('status')->nullable();
            $table->boolean('is_parc_default')->default(false);
            $table->text('description')->nullable();
            $table->timestamps();
        });
    }

    private function setupLegacyConnection(): void
    {
        Config::set('database.connections.legacy_mysql', [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true,
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
        DB::connection('legacy_mysql')->table('applications')->insert(['id_app' => $idApp, 'id_nom_app' => $idNomApp, 'active_app' => $active]);
    }

    private function legacyParc(int $id, string $nom): void
    {
        DB::connection('legacy_mysql')->table('parc')->insert(['id_parc' => $id, 'nom_parc' => $nom]);
    }

    private function legacyAssign(int $idAppli, int $idEntite): void
    {
        DB::connection('legacy_mysql')->table('applications_profile')->insert(['id_appli' => $idAppli, 'type_entite' => 'parc', 'id_entite' => $idEntite]);
    }

    /**
     * @param  list<string>  $names
     */
    private function seedAdEntries(array $names): void
    {
        AppProfileAdImporter::$parcsEntriesSeam = array_map(
            fn (string $name) => new class($name) {
                public function __construct(private string $name) {}

                public function getParcName(): ?string
                {
                    return $this->name;
                }

                public function getDescription(): ?string
                {
                    return 'desc ' . $this->name;
                }

                public function getFirstAttribute(string $k): mixed
                {
                    return null;
                }

                public function getAttribute(string $k): mixed
                {
                    return null;
                }

                public function getDn(): string
                {
                    return "CN={$this->name},OU=Parcs";
                }
            },
            $names,
        );
    }

    private function seedReferenceDump(): void
    {
        // Catalogue legacy.
        $this->legacyApp(1, 'firefox');
        $this->legacyApp(2, '7zip');
        $this->legacyApp(3, 'notepad-manquant'); // jamais importé côté SE5
        // Parcs.
        $this->legacyParc(2, '_TousLesPostes');
        $this->legacyParc(4, 'base');
        $this->legacyParc(5, 'rangement');
        // Assignations.
        $this->legacyAssign(1, 4); // base → firefox
        $this->legacyAssign(2, 4); // base → 7zip
        $this->legacyAssign(1, 2); // _TousLesPostes → firefox
        $this->legacyAssign(3, 2); // _TousLesPostes → notepad-manquant (absent SE5)

        // Catalogue SE5 (notepad-manquant absent volontairement).
        Application::create(['app_id' => 'firefox', 'name' => 'Firefox', 'status' => 'installed']);
        Application::create(['app_id' => '7zip', 'name' => '7-Zip', 'status' => 'installed']);

        $this->seedAdEntries(['_TousLesPostes', 'base', 'rangement']);
    }

    #[Test]
    public function it_creates_profiles_only_for_parcs_with_applications_and_promotes_all_workstations(): void
    {
        $this->seedReferenceDump();

        $stats = app(AppProfileAdImporter::class)->importFromAd();

        $this->assertFalse($stats['legacy_unavailable']);

        // base porte des apps → profil créé ; rangement sans app → sauté ;
        // _TousLesPostes → jamais un profil.
        $this->assertNotNull(AppProfile::where('name', 'base')->first());
        $this->assertNull(AppProfile::where('name', 'rangement')->first());
        $this->assertNull(AppProfile::where('name', '_TousLesPostes')->first());
        $this->assertSame(1, $stats['created']);
        $this->assertSame(1, $stats['skipped_no_apps']);

        // _TousLesPostes : firefox promu en défaut parc ; 7zip non (hors socle).
        $this->assertTrue(Application::where('app_id', 'firefox')->first()->is_parc_default);
        $this->assertFalse(Application::where('app_id', '7zip')->first()->is_parc_default);
        $this->assertSame(1, $stats['parc_defaults_promoted']);

        // notepad-manquant assigné à _TousLesPostes mais absent du catalogue → warning.
        $this->assertSame(1, $stats['parc_defaults_missing']);
    }

    #[Test]
    public function promotion_is_idempotent_and_never_unmarks(): void
    {
        $this->seedReferenceDump();
        $importer = app(AppProfileAdImporter::class);

        $importer->importFromAd();

        // Un défaut posé à la main sur une app hors socle doit survivre au rejeu.
        Application::where('app_id', '7zip')->update(['is_parc_default' => true]);

        // Rejeu (mêmes entrées).
        $this->seedAdEntries(['_TousLesPostes', 'base', 'rangement']);
        $importer->importFromAd();

        $this->assertTrue(Application::where('app_id', 'firefox')->first()->is_parc_default);
        $this->assertTrue(Application::where('app_id', '7zip')->first()->is_parc_default, 'Un défaut manuel ne doit jamais être retiré.');
        // Pas de doublon de profil.
        $this->assertSame(1, AppProfile::where('name', 'base')->count());
    }

    #[Test]
    public function legacy_unavailable_creates_nothing_and_warns(): void
    {
        $this->seedAdEntries(['base', 'rangement', '_TousLesPostes']);
        Config::set('database.connections.legacy_mysql', [
            'driver' => 'mysql', 'host' => '127.0.0.1', 'database' => '', 'username' => '', 'password' => '',
        ]);
        DB::purge('legacy_mysql');

        $stats = app(AppProfileAdImporter::class)->importFromAd();

        $this->assertTrue($stats['legacy_unavailable']);
        $this->assertSame(0, $stats['created']);
        $this->assertSame(0, AppProfile::count());
    }

    #[Test]
    public function no_all_workstations_parc_is_a_silent_noop(): void
    {
        $this->legacyApp(1, 'firefox');
        $this->legacyParc(4, 'base');
        $this->legacyAssign(1, 4);
        Application::create(['app_id' => 'firefox', 'name' => 'Firefox', 'status' => 'installed']);
        $this->seedAdEntries(['base']);

        $stats = app(AppProfileAdImporter::class)->importFromAd();

        $this->assertSame(0, $stats['parc_defaults_promoted']);
        $this->assertSame(0, $stats['parc_defaults_missing']);
        $this->assertFalse(Application::where('app_id', 'firefox')->first()->is_parc_default);
    }
}

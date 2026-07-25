<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Parc;

use App\Models\WorkstationGroup;
use App\Services\Parc\WorkstationGroupService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 38.7 / AC9.3 + AC10 — l'import des groupes logiques (étape 5) ne crée un
 * WorkstationGroup logique QUE si le parc legacy porte au moins une application ;
 * les CN sautés sont listés nommément AVEC leur nombre de machines ;
 * `_TousLesPostes` n'est jamais importé (ni groupe, ni profil). L'import PHYSIQUE
 * n'est pas concerné (non testé ici — il ne passe pas par ce chemin).
 */
class ImportLogicalGroupsSelectiveTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->createSchema();
        $this->setupLegacyConnection();
    }

    protected function tearDown(): void
    {
        WorkstationGroupService::$parcsEntriesSeam = null;
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
            $table->text('description')->nullable();
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->string('ad_dn')->nullable();
            $table->string('ad_guid')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('locked')->nullable();
            $table->timestamps();
        });
        Schema::create('workstations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });
        Schema::create('workstation_group_workstation', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workstation_id');
            $table->unsignedBigInteger('workstation_group_id');
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

        DB::connection('legacy_mysql')->table('applications')->insert(['id_app' => 1, 'id_nom_app' => 'firefox', 'active_app' => 1]);
        DB::connection('legacy_mysql')->table('parc')->insert([
            ['id_parc' => 2, 'nom_parc' => '_TousLesPostes'],
            ['id_parc' => 4, 'nom_parc' => 'base'],
            ['id_parc' => 5, 'nom_parc' => 'rangement'],
        ]);
        DB::connection('legacy_mysql')->table('applications_profile')->insert([
            ['id_appli' => 1, 'type_entite' => 'parc', 'id_entite' => 4], // base → firefox
            ['id_appli' => 1, 'type_entite' => 'parc', 'id_entite' => 2], // _TousLesPostes → firefox
        ]);
    }

    /**
     * @param  array<int, array{name: string, members: int}>  $entries
     */
    private function seedAdEntries(array $entries): void
    {
        WorkstationGroupService::$parcsEntriesSeam = array_map(
            fn (array $e) => new class($e['name'], $e['members']) {
                public function __construct(private string $name, private int $members) {}

                public function getParcName(): ?string
                {
                    return $this->name;
                }

                public function getDescription(): ?string
                {
                    return null;
                }

                public function getDn(): string
                {
                    return "CN={$this->name},OU=Parcs";
                }

                public function getFirstAttribute(string $k): mixed
                {
                    return null;
                }

                public function getAttribute(string $k): mixed
                {
                    if ($k !== 'member') {
                        return null;
                    }

                    return array_map(fn ($i) => "CN=pc-{$i},OU=Computers", range(1, max(0, $this->members)) ?: []);
                }
            },
            $entries,
        );
    }

    #[Test]
    public function it_imports_only_logical_groups_backed_by_applications(): void
    {
        $this->seedAdEntries([
            ['name' => 'base', 'members' => 3],
            ['name' => 'rangement', 'members' => 12],
            ['name' => '_TousLesPostes', 'members' => 40],
        ]);

        $stats = app(WorkstationGroupService::class)->importLogicalGroupsFromAd();

        // base porte firefox → créé.
        $this->assertNotNull(WorkstationGroup::where('name', 'base')->first());
        // rangement sans app → non créé, listé avec son nombre de machines.
        $this->assertNull(WorkstationGroup::where('name', 'rangement')->first());
        // _TousLesPostes → jamais importé.
        $this->assertNull(WorkstationGroup::where('name', '_TousLesPostes')->first());

        $this->assertSame(1, $stats['created']);
        $this->assertSame(1, $stats['skipped_no_apps']);
        $this->assertContains(
            ['name' => 'rangement', 'machines' => 12],
            $stats['skipped_no_apps_details'],
        );
    }

    #[Test]
    public function legacy_unavailable_creates_no_logical_group_and_warns(): void
    {
        $this->seedAdEntries([['name' => 'base', 'members' => 3]]);
        Config::set('database.connections.legacy_mysql', [
            'driver' => 'mysql', 'host' => '127.0.0.1', 'database' => '', 'username' => '', 'password' => '',
        ]);
        DB::purge('legacy_mysql');

        $stats = app(WorkstationGroupService::class)->importLogicalGroupsFromAd();

        $this->assertTrue($stats['legacy_unavailable']);
        $this->assertSame(0, $stats['created']);
        $this->assertNull(WorkstationGroup::where('name', 'base')->first());
    }
}

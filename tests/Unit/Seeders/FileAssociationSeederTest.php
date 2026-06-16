<?php

declare(strict_types=1);

namespace Tests\Unit\Seeders;

use App\Models\FileAssociation;
use App\Models\WorkstationGroup;
use App\Observers\WorkstationGroupObserver;
use Database\Seeders\FileAssociationSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tests du `FileAssociationSeeder` — Story 27.3bis (AC10, D-Henri n°7).
 *
 * Vérifie le TAGAGE par source sur la baseline figée (hôte/CI sans default.xml ni
 * packages.xml) : natives `source=native`/`wpkg_package=null`, WPKG `source=wpkg`/
 * `wpkg_package=<id>` ; l'idempotence (rejouable, zéro doublon) ; et la PRÉFÉRENCE
 * NATIVE quand une même paire `(identifier, progid)` arrive des deux sources.
 *
 * Le payload contrat (`{identifier, progid, type}`) n'est PAS concerné ici : ces
 * colonnes sont serveur-only (catalogue), jamais émises (golden/hash intouchés).
 */
class FileAssociationSeederTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // L'observer dispatche un job AD-sync (LDAP) à la création d'un parc — hors
        // sujet ici et indisponible sur l'hôte. On le coupe (iso ProviderTest).
        WorkstationGroupObserver::disableSync();
        $this->ensureSchema();
    }

    protected function tearDown(): void
    {
        WorkstationGroupObserver::enableSync();
        Schema::dropIfExists('file_association_assignables');
        Schema::dropIfExists('file_associations');
        Schema::dropIfExists('workstation_groups');
        parent::tearDown();
    }

    private function ensureSchema(): void
    {
        if (! Schema::hasTable('workstation_groups')) {
            Schema::create('workstation_groups', function (Blueprint $t): void {
                $t->id();
                $t->string('name')->unique();
                $t->boolean('is_physical')->default(false);
                $t->boolean('is_active')->default(true);
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('file_associations')) {
            Schema::create('file_associations', function (Blueprint $t): void {
                $t->id();
                $t->string('key')->unique();
                $t->string('label');
                $t->string('description')->nullable();
                $t->string('identifier');
                $t->string('assoc_type', 16);
                $t->string('progid');
                $t->string('source', 16)->default('native');
                $t->string('wpkg_package')->nullable();
                $t->boolean('is_active')->default(true);
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('file_association_assignables')) {
            Schema::create('file_association_assignables', function (Blueprint $t): void {
                $t->id();
                $t->unsignedBigInteger('file_association_id');
                $t->string('assignable_type');
                $t->unsignedBigInteger('assignable_id');
                $t->timestamps();
                $t->unique(['file_association_id', 'assignable_id', 'assignable_type'], 'faa_seed_unique');
            });
        }
    }

    #[Test]
    public function baseline_tags_natives_and_wpkg_sources(): void
    {
        // Hôte : pas de default.xml/packages.xml → baseline figée taguée.
        (new FileAssociationSeeder())->run();

        // Firefox = wpkg, paquet `firefox`.
        $firefox = FileAssociation::query()->where('progid', 'FirefoxURL')->first();
        self::assertNotNull($firefox);
        self::assertSame(FileAssociation::SOURCE_WPKG, $firefox->source);
        self::assertSame('firefox', $firefox->wpkg_package);
        self::assertFalse($firefox->isNative());

        // .jpg → WindowsPhotoViewer = native, wpkg_package null.
        $jpg = FileAssociation::query()->where('identifier', '.jpg')->first();
        self::assertNotNull($jpg);
        self::assertSame(FileAssociation::SOURCE_NATIVE, $jpg->source);
        self::assertNull($jpg->wpkg_package);
        self::assertTrue($jpg->isNative());

        // .txt → txtfile = native (le cas de Henri).
        $txt = FileAssociation::query()->where('identifier', '.txt')->first();
        self::assertNotNull($txt);
        self::assertSame(FileAssociation::SOURCE_NATIVE, $txt->source);
        self::assertSame('txtfile', $txt->progid);
        self::assertNull($txt->wpkg_package);
    }

    #[Test]
    public function seeder_is_idempotent(): void
    {
        (new FileAssociationSeeder())->run();
        $first = FileAssociation::query()->count();

        (new FileAssociationSeeder())->run();
        $second = FileAssociation::query()->count();

        self::assertSame($first, $second, 'upsert par clé (identifier, progid) — zéro doublon');
        self::assertGreaterThan(0, $first);
    }

    #[Test]
    public function seeder_attaches_defaults_to_all_active_parcs(): void
    {
        $parc = WorkstationGroup::query()->create(['name' => 'parc-seed', 'is_physical' => false, 'is_active' => true]);

        (new FileAssociationSeeder())->run();

        $assoc = FileAssociation::query()->first();
        self::assertTrue(
            $assoc->workstationGroups()->where('workstation_groups.id', $parc->id)->exists(),
            'reproduction de la portée legacy « all »',
        );
    }

    #[Test]
    public function native_wins_over_wpkg_for_the_same_pair(): void
    {
        // Même paire (.foo, FooProg) servie par les DEUX sources : la native gagne.
        $native = [[
            'identifier' => '.foo', 'progid' => 'FooProg', 'assoc_type' => 'file',
            'label' => 'foo native', 'source' => FileAssociation::SOURCE_NATIVE, 'wpkg_package' => null,
        ]];
        $wpkg = [[
            'identifier' => '.foo', 'progid' => 'FooProg', 'assoc_type' => 'file',
            'label' => 'foo wpkg', 'source' => FileAssociation::SOURCE_WPKG, 'wpkg_package' => 'foopkg',
        ]];

        $merged = ExposedFileAssociationSeeder::merge($native, $wpkg);

        self::assertCount(1, $merged, 'paire identique fusionnée');
        self::assertSame(FileAssociation::SOURCE_NATIVE, $merged[0]['source'], 'native bat wpkg');
        self::assertNull($merged[0]['wpkg_package']);
    }

    #[Test]
    public function distinct_pairs_accumulate_from_both_sources(): void
    {
        $native = [[
            'identifier' => '.txt', 'progid' => 'txtfile', 'assoc_type' => 'file',
            'label' => 'txt', 'source' => FileAssociation::SOURCE_NATIVE, 'wpkg_package' => null,
        ]];
        $wpkg = [[
            'identifier' => 'http', 'progid' => 'FirefoxURL', 'assoc_type' => 'protocol',
            'label' => 'http', 'source' => FileAssociation::SOURCE_WPKG, 'wpkg_package' => 'firefox',
        ]];

        $merged = ExposedFileAssociationSeeder::merge($native, $wpkg);

        self::assertCount(2, $merged);
    }
}

/**
 * Sous-classe de test exposant la fusion `protected static mergeCatalogs()` pour
 * vérifier la préférence native sans I/O fichier.
 */
class ExposedFileAssociationSeeder extends FileAssociationSeeder
{
    /**
     * @param  list<array<string,mixed>>  $native
     * @param  list<array<string,mixed>>  $wpkg
     * @return list<array<string,mixed>>
     */
    public static function merge(array $native, array $wpkg): array
    {
        return self::mergeCatalogs($native, $wpkg);
    }
}

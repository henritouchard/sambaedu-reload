<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Agent\Resolvers;

use App\Gpo\Services\PackagesXmlAssociationsReader;
use App\Models\Application;
use App\Models\FileAssociation;
use App\Models\NativeApplication;
use App\Models\WorkstationGroup;
use App\Observers\WorkstationGroupObserver;
use App\Services\Agent\Resolvers\AssociationResolver;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 27.11 — `AssociationResolver` : traduit *(extension X, app A)* en
 * *(progid, source, wpkg_package)* (AC3). Couvre les trois branches (riche /
 * générique / native), la jointure `packages.xml ⇄ app_id`, le garde-fou exe
 * manquant, et l'upsert `file_associations` iso `catalogKey` + attache au parc.
 *
 * PG-pur : aucune dépendance AD/APCu — la lecture `packages.xml` est injectée par
 * fixture (geste admin admis, hors chemin desired-state).
 */
class AssociationResolverTest extends TestCase
{
    /** Fixture WPKG : firefox déclare .html→FirefoxHTML, http→FirefoxURL, etc. */
    public const PACKAGES_XML = __DIR__ . '/../../../../Fixtures/Gpo/packages-xml-sample.xml';

    protected function setUp(): void
    {
        parent::setUp();
        WorkstationGroupObserver::disableSync();
        $this->ensureSchema();
    }

    protected function tearDown(): void
    {
        WorkstationGroupObserver::enableSync();
        Schema::dropIfExists('file_association_assignables');
        Schema::dropIfExists('file_associations');
        Schema::dropIfExists('native_applications');
        Schema::dropIfExists('applications');
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
        if (! Schema::hasTable('applications')) {
            Schema::create('applications', function (Blueprint $t): void {
                $t->id();
                $t->string('app_id');
                $t->string('name');
                $t->string('icon_url')->nullable();
                $t->string('executable')->nullable();
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('native_applications')) {
            Schema::create('native_applications', function (Blueprint $t): void {
                $t->id();
                $t->string('key')->unique();
                $t->string('label');
                $t->string('progid');
                $t->string('executable');
                $t->json('assoc_types');
                $t->string('icon_url')->nullable();
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
                $t->unique(['file_association_id', 'assignable_id', 'assignable_type'], 'faa_resolver_unique');
            });
        }
    }

    private function resolver(): AssociationResolver
    {
        // Reader pointé sur la fixture (geste admin admis, injecté).
        $reader = new class extends PackagesXmlAssociationsReader {
            public function read(?string $packagesXmlPath = null): array
            {
                return parent::read(AssociationResolverTest::PACKAGES_XML);
            }
        };

        return new AssociationResolver($reader);
    }

    private function wpkgApp(string $appId, ?string $executable = null): Application
    {
        return Application::create([
            'app_id' => $appId,
            'name' => ucfirst($appId),
            'executable' => $executable,
        ]);
    }

    private function notepad(): NativeApplication
    {
        return NativeApplication::create([
            'key' => 'notepad',
            'label' => 'Bloc-notes',
            'progid' => 'txtfile',
            'executable' => '%SystemRoot%\\system32\\notepad.exe',
            'assoc_types' => ['.txt'],
        ]);
    }

    // ── Branche 1 : native curée déclarant l'extension → ProgId canonique ──────

    #[Test]
    public function native_app_with_declared_identifier_yields_canonical_progid(): void
    {
        $resolved = $this->resolver()->resolve('.txt', $this->notepad());

        self::assertSame('txtfile', $resolved->progid);
        self::assertSame(FileAssociation::SOURCE_NATIVE, $resolved->source);
        self::assertNull($resolved->wpkgPackage);
        self::assertFalse($resolved->generic);
    }

    #[Test]
    public function native_app_without_declared_identifier_falls_back_to_generic_on_its_exe(): void
    {
        // Le Bloc-notes ne déclare PAS .clclcc → générique Applications\notepad.exe.
        $resolved = $this->resolver()->resolve('.clclcc', $this->notepad());

        self::assertSame('Applications\\notepad.exe', $resolved->progid);
        self::assertSame(FileAssociation::SOURCE_NATIVE, $resolved->source);
        self::assertNull($resolved->wpkgPackage);
        self::assertTrue($resolved->generic);
    }

    // ── Branche 2 : WPKG déclarant un handler pour X → ProgId riche ────────────

    #[Test]
    public function wpkg_app_with_declared_handler_yields_rich_progid(): void
    {
        $resolved = $this->resolver()->resolve('.html', $this->wpkgApp('firefox'));

        self::assertSame('FirefoxHTML', $resolved->progid);
        self::assertSame(FileAssociation::SOURCE_WPKG, $resolved->source);
        self::assertSame('firefox', $resolved->wpkgPackage);
        self::assertFalse($resolved->generic);
    }

    #[Test]
    public function wpkg_rich_progid_resolution_is_case_insensitive_on_identifier(): void
    {
        $resolved = $this->resolver()->resolve('.HTML', $this->wpkgApp('firefox'));

        self::assertSame('FirefoxHTML', $resolved->progid);
        self::assertFalse($resolved->generic);
    }

    #[Test]
    public function wpkg_protocol_handler_resolves_rich(): void
    {
        $resolved = $this->resolver()->resolve('http', $this->wpkgApp('firefox'));

        self::assertSame('FirefoxURL', $resolved->progid);
        self::assertSame(FileAssociation::SOURCE_WPKG, $resolved->source);
        self::assertSame('firefox', $resolved->wpkgPackage);
    }

    // ── Branche 3 : générique (WPKG sans handler déclaré pour X) ───────────────

    #[Test]
    public function wpkg_app_without_declared_handler_yields_generic_progid(): void
    {
        // VLC n'est pas dans packages.xml → générique sur son exe, source=wpkg.
        $resolved = $this->resolver()->resolve('.clclcc', $this->wpkgApp('vlc', 'C:\\Program Files\\VideoLAN\\VLC\\vlc.exe'));

        self::assertSame('Applications\\vlc.exe', $resolved->progid);
        self::assertSame(FileAssociation::SOURCE_WPKG, $resolved->source);
        self::assertSame('vlc', $resolved->wpkgPackage);
        self::assertTrue($resolved->generic);
    }

    // ── Garde-fou : générique sans exe → exception (piège n°4) ─────────────────

    #[Test]
    public function generic_without_executable_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        // Firefox déclare .html mais PAS .clclcc, et n'a pas d'exe → refusé.
        $this->resolver()->resolve('.clclcc', $this->wpkgApp('firefox', null));
    }

    // ── Upsert file_associations + attache parc (iso catalogKey) ───────────────

    #[Test]
    public function compose_upserts_file_association_and_attaches_to_parc(): void
    {
        $parc = WorkstationGroup::create(['name' => 'parc-1', 'is_physical' => false]);
        $assoc = $this->resolver()->compose('.html', 'file', $this->wpkgApp('firefox'), $parc);

        self::assertSame(FileAssociation::catalogKey('.html', 'FirefoxHTML'), $assoc->key);
        self::assertSame('FirefoxHTML', $assoc->progid);
        self::assertSame(FileAssociation::SOURCE_WPKG, $assoc->source);
        self::assertSame('firefox', $assoc->wpkg_package);

        $this->assertDatabaseHas('file_association_assignables', [
            'file_association_id' => $assoc->id,
            'assignable_type' => WorkstationGroup::class,
            'assignable_id' => $parc->id,
        ]);
    }

    #[Test]
    public function compose_does_not_reactivate_globally_killed_pair(): void
    {
        // M1 — kill-switch GLOBAL : `is_active=false` coupe la paire pour TOUS les
        // parcs (filtre du provider). Recomposer cette paire (depuis n'importe quel
        // parc) ne DOIT PAS la réactiver — sinon réactivation transverse silencieuse.
        $parc = WorkstationGroup::create(['name' => 'parc-kill', 'is_physical' => false]);

        $existing = FileAssociation::create([
            'key' => FileAssociation::catalogKey('.html', 'FirefoxHTML'),
            'label' => 'ancien', 'identifier' => '.html', 'assoc_type' => 'file',
            'progid' => 'FirefoxHTML', 'source' => FileAssociation::SOURCE_WPKG,
            'wpkg_package' => 'firefox', 'is_active' => false, // kill-switch posé
        ]);

        $assoc = $this->resolver()->compose('.html', 'file', $this->wpkgApp('firefox'), $parc);

        self::assertSame($existing->id, $assoc->id, 'même paire → upsert, pas de doublon');
        self::assertFalse($assoc->fresh()->is_active, 'is_active=false NE doit PAS être réactivé sur update (kill-switch global)');
    }

    #[Test]
    public function compose_sets_is_active_true_on_creation(): void
    {
        $parc = WorkstationGroup::create(['name' => 'parc-new', 'is_physical' => false]);

        $assoc = $this->resolver()->compose('.html', 'file', $this->wpkgApp('firefox'), $parc);

        self::assertTrue($assoc->fresh()->is_active, 'is_active=true à la création');
    }

    #[Test]
    public function compose_is_idempotent_on_identical_pair(): void
    {
        $parc = WorkstationGroup::create(['name' => 'parc-2', 'is_physical' => false]);
        $r = $this->resolver();

        $a = $r->compose('.html', 'file', $this->wpkgApp('firefox'), $parc);
        $b = $r->compose('.html', 'file', Application::where('app_id', 'firefox')->first(), $parc);

        self::assertSame($a->id, $b->id, 'même paire (identifier, progid) → upsert, pas de doublon');
        self::assertSame(1, FileAssociation::query()->count());
        self::assertSame(
            1,
            \Illuminate\Support\Facades\DB::table('file_association_assignables')
                ->where('file_association_id', $a->id)
                ->where('assignable_id', $parc->id)
                ->count(),
            'syncWithoutDetaching reste idempotent',
        );
    }
}

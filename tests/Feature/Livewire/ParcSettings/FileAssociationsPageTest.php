<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\ParcSettings;

use App\Models\AppProfile;
use App\Models\Application;
use App\Models\FileAssociation;
use App\Models\NativeApplication;
use App\Models\User;
use App\Models\WorkstationGroup;
use App\Gpo\Services\PackagesXmlAssociationsReader;
use App\Services\Agent\Resolvers\AssociationResolver;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\Concerns\SeedsWorkstationConfig;
use Tests\Support\WpkgSchemaBootstrapper;
use Tests\TestCase;

/**
 * Onglet Livewire « Associations par défaut » de la page d'un WorkstationGroup —
 * Story 27.11 (V2 COMPOSER). Le geste s'applique PAR groupe (parc/salle), monté en
 * onglet de `parc/groups/{id}` avec `groupId`.
 *
 * Vérifie : gate app.customize (rendu vs 403) ; le COMPOSER (saisie extension +
 * dropdown app par nom → création via AssociationResolver + attache parc) ; la
 * liste éditable/désactivable (désactiver = détacher = cesser de gérer) ; la
 * validation prédictive sur entrée custom (native/wpkg déployé → applicable ; wpkg
 * non déployé → indisponible + toast nommant le paquet) ; le garde-fou exe manquant
 * (générique refusé sans exe).
 */
class FileAssociationsPageTest extends TestCase
{
    use SeedsWorkstationConfig;

    private const COMPONENT = 'pages::parc.groups._partials.associations-tab';

    /** Fixture WPKG : firefox déclare .html→FirefoxHTML, http→FirefoxURL. */
    public const PACKAGES_XML = __DIR__ . '/../../../Fixtures/Gpo/packages-xml-sample.xml';

    protected function setUp(): void
    {
        parent::setUp();

        if (! config('app.key')) {
            config(['app.key' => 'base64:' . base64_encode(random_bytes(32))]);
        }

        WpkgSchemaBootstrapper::bootstrap();
        $this->seedWorkstationContextSchemas();
        $this->ensureAssociationTables();
        $this->ensureNativeAppsTable();
        $this->ensureApplicationComposerColumns();
        $this->ensureSpatieTables();

        Permission::firstOrCreate(['name' => 'app.customize', 'guard_name' => 'web']);

        // Le composer doit résoudre les ProgId riches via packages.xml fixturé
        // (geste admin admis, hors chemin desired-state).
        $this->bindResolverWithFixturePackagesXml();
    }

    protected function tearDown(): void
    {
        WpkgSchemaBootstrapper::tearDown();
        parent::tearDown();
    }

    private function bindResolverWithFixturePackagesXml(): void
    {
        $reader = new class extends PackagesXmlAssociationsReader {
            public function read(?string $packagesXmlPath = null): array
            {
                return parent::read(FileAssociationsPageTest::PACKAGES_XML);
            }
        };
        $this->app->bind(AssociationResolver::class, fn () => new AssociationResolver($reader));
    }

    private function ensureAssociationTables(): void
    {
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
                $t->unique(['file_association_id', 'assignable_id', 'assignable_type'], 'faa_unique');
            });
        }
    }

    private function ensureNativeAppsTable(): void
    {
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
    }

    /** Le bootstrapper WPKG crée `applications` sans `executable`/`icon_url` (27.11). */
    private function ensureApplicationComposerColumns(): void
    {
        Schema::table('applications', function (Blueprint $t): void {
            if (! Schema::hasColumn('applications', 'executable')) {
                $t->string('executable')->nullable();
            }
            if (! Schema::hasColumn('applications', 'icon_url')) {
                $t->string('icon_url')->nullable();
            }
        });
    }

    private function ensureSpatieTables(): void
    {
        if (! Schema::hasTable('permissions')) {
            Schema::create('permissions', function (Blueprint $t): void {
                $t->id();
                $t->string('name');
                $t->string('guard_name');
                $t->timestamps();
                $t->unique(['name', 'guard_name']);
            });
        }
        foreach (['model_has_permissions' => 'omp_mhp', 'model_has_roles' => 'omp_mhr'] as $table => $pk) {
            if (! Schema::hasTable($table)) {
                $col = $table === 'model_has_permissions' ? 'permission_id' : 'role_id';
                Schema::create($table, function (Blueprint $t) use ($col, $pk): void {
                    $t->unsignedBigInteger($col);
                    $t->string('model_type');
                    $t->unsignedBigInteger('model_id');
                    $t->primary([$col, 'model_id', 'model_type'], $pk);
                });
            }
        }
        if (! Schema::hasTable('roles')) {
            Schema::create('roles', function (Blueprint $t): void {
                $t->id();
                $t->string('name');
                $t->string('guard_name');
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('role_has_permissions')) {
            Schema::create('role_has_permissions', function (Blueprint $t): void {
                $t->unsignedBigInteger('permission_id');
                $t->unsignedBigInteger('role_id');
                $t->primary(['permission_id', 'role_id']);
            });
        }
    }

    private function manager(): User
    {
        $u = User::query()->create(['login' => 'assoc-mgr', 'role' => 'prof', 'is_active' => true]);
        $u->givePermissionTo('app.customize');

        return $u;
    }

    private function parc(): WorkstationGroup
    {
        return WorkstationGroup::create(['name' => 'parc-assoc', 'is_physical' => false]);
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

    private function wpkgApp(string $appId, ?string $executable = null): Application
    {
        return Application::create([
            'app_id' => $appId,
            'name' => ucfirst($appId),
            'executable' => $executable,
        ]);
    }

    /** Une association déjà attachée au parc (édition/désactivation/affichage). */
    private function attachedAssociation(WorkstationGroup $parc): FileAssociation
    {
        $assoc = FileAssociation::create([
            'key' => 'txt_txtfile',
            'label' => '.txt → Bloc-notes',
            'identifier' => '.txt',
            'assoc_type' => 'file',
            'progid' => 'txtfile',
            'source' => FileAssociation::SOURCE_NATIVE,
            'is_active' => true,
        ]);
        $assoc->workstationGroups()->attach($parc->id);

        return $assoc;
    }

    // ── Gate ───────────────────────────────────────────────────────────────────

    #[Test]
    public function renders_for_authorized_manager(): void
    {
        $parc = $this->parc();
        $this->notepad();
        $this->actingAs($this->manager());

        Livewire::test(self::COMPONENT, ['groupId' => $parc->id])
            ->assertOk()
            ->assertSee('Ajouter une association')
            ->assertSee('Bloc-notes'); // option du dropdown
    }

    #[Test]
    public function blocks_access_without_permission(): void
    {
        $viewer = User::query()->create(['login' => 'viewer-a', 'role' => 'eleve', 'is_active' => true]);
        $this->actingAs($viewer);

        Livewire::test(self::COMPONENT, ['groupId' => 1])->assertStatus(403);
    }

    // ── Composer : création ──────────────────────────────────────────────────

    #[Test]
    public function compose_creates_native_association_and_attaches_to_parc(): void
    {
        $parc = $this->parc();
        $native = $this->notepad();
        $this->actingAs($this->manager());

        Livewire::test(self::COMPONENT, ['groupId' => $parc->id])
            ->set('newIdentifier', '.txt')
            ->set('newAppRef', 'native:' . $native->id)
            ->call('compose')
            ->assertHasNoErrors();

        $assoc = FileAssociation::query()->where('identifier', '.txt')->firstOrFail();
        self::assertSame('txtfile', $assoc->progid);
        self::assertSame(FileAssociation::SOURCE_NATIVE, $assoc->source);
        $this->assertDatabaseHas('file_association_assignables', [
            'file_association_id' => $assoc->id,
            'assignable_type' => WorkstationGroup::class,
            'assignable_id' => $parc->id,
        ]);
    }

    #[Test]
    public function compose_creates_rich_wpkg_association_when_package_declares_handler(): void
    {
        $parc = $this->parc();
        $app = $this->wpkgApp('firefox');
        $this->actingAs($this->manager());

        Livewire::test(self::COMPONENT, ['groupId' => $parc->id])
            ->set('newIdentifier', '.html')
            ->set('newAppRef', 'wpkg:' . $app->id)
            ->call('compose')
            ->assertHasNoErrors();

        $assoc = FileAssociation::query()->where('identifier', '.html')->firstOrFail();
        self::assertSame('FirefoxHTML', $assoc->progid); // ProgId riche déclaré
        self::assertSame(FileAssociation::SOURCE_WPKG, $assoc->source);
        self::assertSame('firefox', $assoc->wpkg_package);
    }

    #[Test]
    public function compose_creates_generic_association_for_custom_extension(): void
    {
        $parc = $this->parc();
        $app = $this->wpkgApp('vlc', 'C:\\Program Files\\VideoLAN\\VLC\\vlc.exe');
        $this->actingAs($this->manager());

        Livewire::test(self::COMPONENT, ['groupId' => $parc->id])
            ->set('newIdentifier', '.clclcc')
            ->set('newAppRef', 'wpkg:' . $app->id)
            ->call('compose')
            ->assertHasNoErrors();

        $assoc = FileAssociation::query()->where('identifier', '.clclcc')->firstOrFail();
        self::assertSame('Applications\\vlc.exe', $assoc->progid); // ProgId générique fabriqué
        self::assertSame(FileAssociation::SOURCE_WPKG, $assoc->source);
        self::assertSame('vlc', $assoc->wpkg_package);
    }

    // ── Garde-fou exe manquant (piège n°4) ─────────────────────────────────────

    #[Test]
    public function compose_blocks_generic_without_executable(): void
    {
        $parc = $this->parc();
        // Firefox déclare .html mais PAS .clclcc, et n'a pas d'exe → refusé.
        $app = $this->wpkgApp('firefox', null);
        $this->actingAs($this->manager());

        Livewire::test(self::COMPONENT, ['groupId' => $parc->id])
            ->set('newIdentifier', '.clclcc')
            ->set('newAppRef', 'wpkg:' . $app->id)
            ->call('compose')
            ->assertHasNoErrors() // pas une erreur de validation Livewire : un toast
            ->assertDispatched(
                'toastMagic',
                fn (string $event, array $params): bool => ($params['status'] ?? null) === 'error',
            );

        self::assertSame(0, FileAssociation::query()->where('identifier', '.clclcc')->count());
    }

    #[Test]
    public function compose_rejects_invalid_identifier(): void
    {
        $parc = $this->parc();
        $native = $this->notepad();
        $this->actingAs($this->manager());

        Livewire::test(self::COMPONENT, ['groupId' => $parc->id])
            ->set('newIdentifier', 'pas valide!!')
            ->set('newAppRef', 'native:' . $native->id)
            ->call('compose')
            ->assertHasErrors('newIdentifier');
    }

    // ── Liste éditable / désactivable ──────────────────────────────────────────

    #[Test]
    public function parc_associations_list_shows_only_attached_entries(): void
    {
        $parc = $this->parc();
        $attached = $this->attachedAssociation($parc);
        // Une association NON attachée ne doit pas figurer.
        FileAssociation::create([
            'key' => 'other', 'label' => 'autre', 'identifier' => '.xyz',
            'assoc_type' => 'file', 'progid' => 'Xyz', 'is_active' => true,
        ]);
        $this->actingAs($this->manager());

        $rows = Livewire::test(self::COMPONENT, ['groupId' => $parc->id])->get('associations');

        self::assertCount(1, $rows);
        self::assertSame($attached->id, $rows[0]['id']);
    }

    #[Test]
    public function disable_detaches_the_association_from_the_parc(): void
    {
        $parc = $this->parc();
        $assoc = $this->attachedAssociation($parc);
        $this->actingAs($this->manager());

        Livewire::test(self::COMPONENT, ['groupId' => $parc->id])
            ->call('disable', $assoc->id)
            ->assertHasNoErrors();

        $this->assertDatabaseMissing('file_association_assignables', [
            'file_association_id' => $assoc->id,
            'assignable_id' => $parc->id,
        ]);
    }

    // ── Validation prédictive sur entrée custom (AC5) ──────────────────────────

    #[Test]
    public function predictive_native_association_is_applicable(): void
    {
        $parc = $this->parc();
        $this->attachedAssociation($parc); // native
        $this->actingAs($this->manager());

        $rows = Livewire::test(self::COMPONENT, ['groupId' => $parc->id])->get('associations');

        self::assertSame('applicable', $rows[0]['availability']);
    }

    #[Test]
    public function predictive_wpkg_unavailable_when_package_not_deployed(): void
    {
        $parc = $this->parc();
        $assoc = FileAssociation::create([
            'key' => 'http_firefox', 'label' => 'http → Firefox', 'identifier' => 'http',
            'assoc_type' => 'protocol', 'progid' => 'FirefoxURL',
            'source' => FileAssociation::SOURCE_WPKG, 'wpkg_package' => 'firefox', 'is_active' => true,
        ]);
        $assoc->workstationGroups()->attach($parc->id);
        $this->actingAs($this->manager());

        $component = Livewire::test(self::COMPONENT, ['groupId' => $parc->id]);
        $rows = $component->get('associations');

        self::assertSame('unavailable', $rows[0]['availability']);
        $component->assertSee('indisponible')->assertSee('firefox');
    }

    #[Test]
    public function predictive_wpkg_applicable_when_package_deployed(): void
    {
        $parc = $this->parc();
        $app = Application::create(['app_id' => 'firefox', 'name' => 'Firefox']);
        $parc->applications()->attach($app->id);
        $assoc = FileAssociation::create([
            'key' => 'http_firefox', 'label' => 'http → Firefox', 'identifier' => 'http',
            'assoc_type' => 'protocol', 'progid' => 'FirefoxURL',
            'source' => FileAssociation::SOURCE_WPKG, 'wpkg_package' => 'firefox', 'is_active' => true,
        ]);
        $assoc->workstationGroups()->attach($parc->id);
        $this->actingAs($this->manager());

        $rows = Livewire::test(self::COMPONENT, ['groupId' => $parc->id])->get('associations');

        self::assertSame('applicable', $rows[0]['availability']);
    }

    #[Test]
    public function predictive_generic_association_is_best_effort(): void
    {
        // C2/AC5 : un ProgId GÉNÉRIQUE (Applications\<exe>) → best-effort, JAMAIS
        // « applicable » — indépendamment de la source (ici native).
        $parc = $this->parc();
        $assoc = FileAssociation::create([
            'key' => 'clclcc_generic', 'label' => '.clclcc → Bloc-notes', 'identifier' => '.clclcc',
            'assoc_type' => 'file', 'progid' => 'Applications\\notepad.exe',
            'source' => FileAssociation::SOURCE_NATIVE, 'is_active' => true,
        ]);
        $assoc->workstationGroups()->attach($parc->id);
        $this->actingAs($this->manager());

        $component = Livewire::test(self::COMPONENT, ['groupId' => $parc->id]);
        $rows = $component->get('associations');

        self::assertSame('best-effort', $rows[0]['availability']);
        self::assertTrue($rows[0]['generic']);
        $component->assertSee('best-effort');
    }

    #[Test]
    public function composing_generic_association_emits_best_effort_info(): void
    {
        // C2 : composer un générique (extension custom + app à exe) → toast info best-effort.
        $parc = $this->parc();
        $app = $this->wpkgApp('vlc', 'C:\\Program Files\\VideoLAN\\VLC\\vlc.exe');
        $parc->applications()->attach($app->id); // déployé : prouve que générique prime
        $this->actingAs($this->manager());

        Livewire::test(self::COMPONENT, ['groupId' => $parc->id])
            ->set('newIdentifier', '.clclcc')
            ->set('newAppRef', 'wpkg:' . $app->id)
            ->call('compose')
            ->assertHasNoErrors()
            ->assertDispatched(
                'toastMagic',
                fn (string $event, array $params): bool => ($params['status'] ?? null) === 'info'
                    && str_contains((string) ($params['message'] ?? ''), 'best-effort'),
            );
    }

    #[Test]
    public function disable_on_association_not_attached_to_parc_is_honest_no_op(): void
    {
        // C3 : l'asso existe mais n'est PAS attachée au parc courant → pas de
        // faux toast « retirée », et aucun détachement.
        $parc = $this->parc();
        $other = WorkstationGroup::create(['name' => 'autre-parc', 'is_physical' => false]);
        $assoc = $this->attachedAssociation($other); // attachée à un AUTRE parc
        $this->actingAs($this->manager());

        Livewire::test(self::COMPONENT, ['groupId' => $parc->id])
            ->call('disable', $assoc->id)
            ->assertHasNoErrors()
            ->assertDispatched(
                'toastMagic',
                fn (string $event, array $params): bool => ($params['status'] ?? null) === 'error',
            );

        // L'attache à l'autre parc reste intacte.
        $this->assertDatabaseHas('file_association_assignables', [
            'file_association_id' => $assoc->id,
            'assignable_id' => $other->id,
        ]);
    }

    #[Test]
    public function composing_second_app_for_same_identifier_replaces_previous(): void
    {
        // Q2 (décision Henri 2026-06-18) : une asso existe déjà pour .html sur le parc
        // (FirefoxHTML) ; composer une 2e app pour .html (ProgId différent) → l'ancienne
        // est AUTOMATIQUEMENT détachée du parc (règle exclusive), la nouvelle la remplace.
        $parc = $this->parc();
        $existing = FileAssociation::create([
            'key' => 'html_firefox', 'label' => '.html → Firefox', 'identifier' => '.html',
            'assoc_type' => 'file', 'progid' => 'FirefoxHTML',
            'source' => FileAssociation::SOURCE_WPKG, 'wpkg_package' => 'firefox', 'is_active' => true,
        ]);
        $existing->workstationGroups()->attach($parc->id);

        $app = $this->wpkgApp('chrome', 'C:\\Program Files\\Google\\Chrome\\chrome.exe');
        $this->actingAs($this->manager());

        $component = Livewire::test(self::COMPONENT, ['groupId' => $parc->id])
            ->set('newIdentifier', '.html')
            ->set('newAppRef', 'wpkg:' . $app->id)
            ->call('compose')
            ->assertHasNoErrors();

        // L'ancienne association (FirefoxHTML) a été DÉTACHÉE du parc (remplacement auto).
        self::assertFalse(
            $existing->workstationGroups()->whereKey($parc->id)->exists(),
            'l\'ancienne association doit être détachée du parc (remplacement automatique Q2)',
        );

        // Une SEULE association reste attachée au parc pour .html : la nouvelle (≠ FirefoxHTML).
        $attachedIds = DB::table('file_association_assignables')
            ->where('assignable_type', WorkstationGroup::class)
            ->where('assignable_id', $parc->id)
            ->pluck('file_association_id');
        $htmlAttached = FileAssociation::query()->whereIn('id', $attachedIds)->where('identifier', '.html')->get();
        self::assertCount(1, $htmlAttached, 'une seule asso .html attachée au parc après remplacement');
        self::assertNotSame('FirefoxHTML', (string) $htmlAttached->first()->progid, 'la nouvelle association a remplacé l\'ancienne');

        // Un toast signale le remplacement de l'association précédente.
        $dispatches = collect($component->effects['dispatches'] ?? []);
        $replaced = $dispatches->first(
            fn (array $d): bool => $d['name'] === 'toastMagic'
                && str_contains((string) ($d['params']['message'] ?? ''), 'remplacée'),
        );
        self::assertNotNull($replaced, 'un toast doit signaler le remplacement de l\'association précédente (Q2)');
    }

    #[Test]
    public function composing_unavailable_wpkg_association_warns_naming_the_package(): void
    {
        $parc = $this->parc();
        $app = $this->wpkgApp('firefox'); // paquet PAS déployé sur le parc
        $this->actingAs($this->manager());

        Livewire::test(self::COMPONENT, ['groupId' => $parc->id])
            ->set('newIdentifier', '.html')
            ->set('newAppRef', 'wpkg:' . $app->id)
            ->call('compose')
            ->assertHasNoErrors()
            ->assertDispatched(
                'toastMagic',
                fn (string $event, array $params): bool => ($params['status'] ?? null) === 'warning'
                    && str_contains((string) ($params['message'] ?? ''), 'firefox')
                    && str_contains((string) ($params['message'] ?? ''), 'n\'est pas déployé'),
            );
    }

    #[Test]
    public function composing_applicable_association_emits_plain_success(): void
    {
        $parc = $this->parc();
        $native = $this->notepad();
        $this->actingAs($this->manager());

        Livewire::test(self::COMPONENT, ['groupId' => $parc->id])
            ->set('newIdentifier', '.txt')
            ->set('newAppRef', 'native:' . $native->id)
            ->call('compose')
            ->assertHasNoErrors()
            ->assertDispatched(
                'toastMagic',
                fn (string $event, array $params): bool => ($params['status'] ?? null) === 'success'
                    && str_contains((string) ($params['message'] ?? ''), 'ajoutée au parc'),
            );
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\ParcSettings;

use App\Models\AppProfile;
use App\Models\Application;
use App\Models\FileAssociation;
use App\Models\User;
use App\Models\WorkstationGroup;
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
 * Page Livewire « Associations par défaut » (catalogue par parc) — Story 27.3bis, AC7, AC11.
 *
 * Vérifie : gate app.customize (rendu vs 403), activation = assignation pivot,
 * désactivation = retrait, idempotence (réactiver ne double pas). La compilation
 * en items concrets est couverte par AssociationsStateProviderTest.
 *
 * **Validation prédictive (AC11, D-Henri n°7)** : une association `wpkg` dont le
 * paquet n'est PAS déployé sur le parc → `unavailable` (warning rendu + toast
 * exact) ; le même paquet déployé → `applicable` ; une `native` → toujours
 * `applicable`. Le calcul des paquets déployés est group-level Eloquent PG-pur.
 */
class FileAssociationsPageTest extends TestCase
{
    use SeedsWorkstationConfig;

    private const COMPONENT = 'pages::parc-settings.file-associations.index';

    protected function setUp(): void
    {
        parent::setUp();

        if (! config('app.key')) {
            config(['app.key' => 'base64:' . base64_encode(random_bytes(32))]);
        }

        // Bootstrap WPKG AVANT le contexte poste : crée `workstation_groups` AVEC
        // `archived_at` (requis par la validation prédictive group-level) + les
        // pivots applications/app_profiles. `seedWorkstationContextSchemas()`
        // complète ensuite users/user_groups (tables déjà présentes sautées).
        WpkgSchemaBootstrapper::bootstrap();
        $this->seedWorkstationContextSchemas();
        $this->ensureAssociationTables();
        $this->ensureSpatieTables();

        Permission::firstOrCreate(['name' => 'app.customize', 'guard_name' => 'web']);
    }

    protected function tearDown(): void
    {
        WpkgSchemaBootstrapper::tearDown();
        parent::tearDown();
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

    private function association(): FileAssociation
    {
        return FileAssociation::create([
            'key' => 'pdf_acrobat',
            'label' => 'PDF → Acrobat',
            'identifier' => '.pdf',
            'assoc_type' => 'file',
            'progid' => 'Acrobat.Document.DC',
            'is_active' => true,
        ]);
    }

    #[Test]
    public function renders_for_authorized_manager(): void
    {
        $this->parc();
        $this->association();
        $this->actingAs($this->manager());

        Livewire::test(self::COMPONENT)
            ->assertOk()
            ->assertSee('Associations par défaut')
            ->assertSee('PDF → Acrobat');
    }

    #[Test]
    public function blocks_access_without_permission(): void
    {
        $viewer = User::query()->create(['login' => 'viewer-a', 'role' => 'eleve', 'is_active' => true]);
        $this->actingAs($viewer);

        Livewire::test(self::COMPONENT)->assertStatus(403);
    }

    #[Test]
    public function toggle_assigns_the_association_to_the_parc(): void
    {
        $parc = $this->parc();
        $assoc = $this->association();
        $this->actingAs($this->manager());

        Livewire::test(self::COMPONENT)
            ->set('parcId', $parc->id)
            ->call('toggle', $assoc->id)
            ->assertHasNoErrors();

        $this->assertDatabaseHas('file_association_assignables', [
            'file_association_id' => $assoc->id,
            'assignable_type' => WorkstationGroup::class,
            'assignable_id' => $parc->id,
        ]);
    }

    #[Test]
    public function toggle_twice_removes_the_assignment(): void
    {
        $parc = $this->parc();
        $assoc = $this->association();
        $this->actingAs($this->manager());

        Livewire::test(self::COMPONENT)
            ->set('parcId', $parc->id)
            ->call('toggle', $assoc->id)   // active
            ->call('toggle', $assoc->id);  // désactive (cesser de gérer)

        $this->assertDatabaseMissing('file_association_assignables', [
            'file_association_id' => $assoc->id,
            'assignable_id' => $parc->id,
        ]);
    }

    #[Test]
    public function re_enabling_does_not_duplicate_the_pivot_row(): void
    {
        $parc = $this->parc();
        $assoc = $this->association();
        $this->actingAs($this->manager());

        $component = Livewire::test(self::COMPONENT)->set('parcId', $parc->id);
        $component->call('toggle', $assoc->id); // active
        $component->call('toggle', $assoc->id); // désactive
        $component->call('toggle', $assoc->id); // réactive

        $count = DB::table('file_association_assignables')
            ->where('file_association_id', $assoc->id)
            ->where('assignable_id', $parc->id)
            ->count();

        self::assertSame(1, $count, 'syncWithoutDetaching reste idempotent (pas de doublon)');
    }

    // ── AC11 — validation prédictive par parc (native / wpkg déployé / indispo) ──

    /** Association `wpkg` dont le paquet est rattaché à un parc (Application + pivot). */
    private function wpkgAssociation(string $package = 'firefox'): FileAssociation
    {
        return FileAssociation::create([
            'key' => 'http_' . $package,
            'label' => 'HTTP → ' . $package,
            'identifier' => 'http',
            'assoc_type' => 'protocol',
            'progid' => 'FirefoxURL',
            'source' => FileAssociation::SOURCE_WPKG,
            'wpkg_package' => $package,
            'is_active' => true,
        ]);
    }

    private function deployPackageToParc(WorkstationGroup $parc, string $appId): Application
    {
        $app = Application::create(['app_id' => $appId, 'name' => $appId]);
        $parc->applications()->attach($app->id);

        return $app;
    }

    #[Test]
    public function native_association_is_always_applicable(): void
    {
        $parc = $this->parc();
        $assoc = $this->association(); // source=native par défaut (.pdf → Acrobat)
        $this->actingAs($this->manager());

        $rows = Livewire::test(self::COMPONENT)
            ->set('parcId', $parc->id)
            ->get('associations');

        self::assertSame('applicable', $rows[0]['availability']);
    }

    #[Test]
    public function wpkg_association_unavailable_when_package_not_deployed(): void
    {
        $parc = $this->parc();
        $assoc = $this->wpkgAssociation('firefox'); // paquet NON déployé sur le parc
        $this->actingAs($this->manager());

        $component = Livewire::test(self::COMPONENT)->set('parcId', $parc->id);

        $rows = $component->get('associations');
        self::assertSame('unavailable', $rows[0]['availability']);

        // Le warning EXACT (badge « indisponible » + tooltip nommant le paquet) est rendu.
        $component->assertSee('indisponible')
            ->assertSee('firefox');
    }

    #[Test]
    public function wpkg_association_applicable_when_package_deployed_on_parc(): void
    {
        $parc = $this->parc();
        $this->deployPackageToParc($parc, 'firefox'); // paquet déployé sur le parc
        $assoc = $this->wpkgAssociation('firefox');
        $this->actingAs($this->manager());

        $rows = Livewire::test(self::COMPONENT)
            ->set('parcId', $parc->id)
            ->get('associations');

        self::assertSame('applicable', $rows[0]['availability']);
    }

    #[Test]
    public function wpkg_application_via_app_profile_makes_it_applicable(): void
    {
        $parc = $this->parc();
        $app = Application::create(['app_id' => 'firefox', 'name' => 'firefox']);
        $profile = AppProfile::create(['name' => 'profile-parc', 'is_active' => true]);
        $profile->applications()->attach($app->id);
        $parc->appProfiles()->attach($profile->id);

        $assoc = $this->wpkgAssociation('firefox');
        $this->actingAs($this->manager());

        $rows = Livewire::test(self::COMPONENT)
            ->set('parcId', $parc->id)
            ->get('associations');

        self::assertSame('applicable', $rows[0]['availability'], 'paquet déployé via app profile du parc');
    }

    #[Test]
    public function activating_unavailable_wpkg_association_warns_naming_the_package(): void
    {
        $parc = $this->parc();
        $assoc = $this->wpkgAssociation('firefox'); // non déployé
        $this->actingAs($this->manager());

        // Toast EXACT d'avertissement (event `toastMagic` status=warning) nommant le paquet manquant.
        Livewire::test(self::COMPONENT)
            ->set('parcId', $parc->id)
            ->call('toggle', $assoc->id)
            ->assertHasNoErrors()
            ->assertDispatched(
                'toastMagic',
                fn (string $event, array $params): bool => ($params['status'] ?? null) === 'warning'
                    && str_contains((string) ($params['message'] ?? ''), 'firefox')
                    && str_contains((string) ($params['message'] ?? ''), 'n\'est pas déployé'),
            );
    }

    #[Test]
    public function activating_native_association_emits_plain_success(): void
    {
        $parc = $this->parc();
        $assoc = $this->association(); // native
        $this->actingAs($this->manager());

        // Succès simple (event `toastMagic` status=success), aucun avertissement de paquet.
        Livewire::test(self::COMPONENT)
            ->set('parcId', $parc->id)
            ->call('toggle', $assoc->id)
            ->assertHasNoErrors()
            ->assertDispatched(
                'toastMagic',
                fn (string $event, array $params): bool => ($params['status'] ?? null) === 'success'
                    && str_contains((string) ($params['message'] ?? ''), 'Association activée pour le parc.'),
            );
    }
}

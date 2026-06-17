<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\ParcSettings;

use App\Models\RegistrySetting;
use App\Models\User;
use App\Models\WorkstationGroup;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\Concerns\SeedsWorkstationConfig;
use Tests\TestCase;

/**
 * Onglet Livewire « Réglages registre » de la page d'un WorkstationGroup —
 * Story 27.3, AC7. Le geste s'applique PAR groupe (parc/salle) : le composant est
 * monté en onglet de `parc/groups/{id}` et reçoit `groupId` au montage (plus de
 * sélecteur de parc global).
 *
 * Vérifie : gate app.customize (rendu vs 403), activation = assignation pivot,
 * désactivation = retrait, idempotence (réactiver ne double pas). La compilation
 * en items concrets est couverte par RegistryStateProviderTest.
 */
class RegistrySettingsPageTest extends TestCase
{
    use SeedsWorkstationConfig;

    private const COMPONENT = 'pages::parc.groups._partials.registry-tab';

    protected function setUp(): void
    {
        parent::setUp();

        if (! config('app.key')) {
            config(['app.key' => 'base64:' . base64_encode(random_bytes(32))]);
        }

        $this->seedWorkstationContextSchemas();
        $this->ensureRegistryTables();
        $this->ensureSpatieTables();

        Permission::firstOrCreate(['name' => 'app.customize', 'guard_name' => 'web']);
    }

    private function ensureRegistryTables(): void
    {
        if (! Schema::hasTable('registry_settings')) {
            Schema::create('registry_settings', function (Blueprint $t): void {
                $t->id();
                $t->string('key')->unique();
                $t->string('label');
                $t->string('description')->nullable();
                $t->string('hive', 16);
                $t->string('path');
                $t->string('name');
                $t->string('type', 16);
                $t->text('value');
                $t->boolean('is_active')->default(true);
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('registry_setting_assignables')) {
            Schema::create('registry_setting_assignables', function (Blueprint $t): void {
                $t->id();
                $t->unsignedBigInteger('registry_setting_id');
                $t->string('assignable_type');
                $t->unsignedBigInteger('assignable_id');
                $t->timestamps();
                $t->unique(['registry_setting_id', 'assignable_id', 'assignable_type'], 'rsa_unique');
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
        $u = User::query()->create(['login' => 'reg-mgr', 'role' => 'prof', 'is_active' => true]);
        $u->givePermissionTo('app.customize');

        return $u;
    }

    private function parc(): WorkstationGroup
    {
        return WorkstationGroup::create(['name' => 'parc-info', 'is_physical' => false]);
    }

    private function setting(): RegistrySetting
    {
        return RegistrySetting::create([
            'key' => 'show_file_extensions',
            'label' => 'Afficher les extensions',
            'hive' => 'HKCU',
            'path' => 'Software\\X\\Advanced',
            'name' => 'HideFileExt',
            'type' => 'REG_DWORD',
            'value' => '0',
            'is_active' => true,
        ]);
    }

    #[Test]
    public function renders_for_authorized_manager(): void
    {
        $parc = $this->parc();
        $this->setting();
        $this->actingAs($this->manager());

        Livewire::test(self::COMPONENT, ['groupId' => $parc->id])
            ->assertOk()
            ->assertSee('Réglages registre')
            ->assertSee('Afficher les extensions');
    }

    #[Test]
    public function blocks_access_without_permission(): void
    {
        $viewer = User::query()->create(['login' => 'viewer-r', 'role' => 'eleve', 'is_active' => true]);
        $this->actingAs($viewer);

        Livewire::test(self::COMPONENT, ['groupId' => 1])->assertStatus(403);
    }

    #[Test]
    public function toggle_assigns_the_setting_to_the_parc(): void
    {
        $parc = $this->parc();
        $setting = $this->setting();
        $this->actingAs($this->manager());

        Livewire::test(self::COMPONENT, ['groupId' => $parc->id])
            ->call('toggle', $setting->id)
            ->assertHasNoErrors();

        $this->assertDatabaseHas('registry_setting_assignables', [
            'registry_setting_id' => $setting->id,
            'assignable_type' => WorkstationGroup::class,
            'assignable_id' => $parc->id,
        ]);
    }

    #[Test]
    public function toggle_twice_removes_the_assignment(): void
    {
        $parc = $this->parc();
        $setting = $this->setting();
        $this->actingAs($this->manager());

        Livewire::test(self::COMPONENT, ['groupId' => $parc->id])
            ->call('toggle', $setting->id)   // active
            ->call('toggle', $setting->id);  // désactive (cesser de gérer)

        $this->assertDatabaseMissing('registry_setting_assignables', [
            'registry_setting_id' => $setting->id,
            'assignable_id' => $parc->id,
        ]);
    }

    #[Test]
    public function re_enabling_does_not_duplicate_the_pivot_row(): void
    {
        $parc = $this->parc();
        $setting = $this->setting();
        $this->actingAs($this->manager());

        $component = Livewire::test(self::COMPONENT, ['groupId' => $parc->id]);
        $component->call('toggle', $setting->id); // active
        $component->call('toggle', $setting->id); // désactive
        $component->call('toggle', $setting->id); // réactive

        $count = DB::table('registry_setting_assignables')
            ->where('registry_setting_id', $setting->id)
            ->where('assignable_id', $parc->id)
            ->count();

        self::assertSame(1, $count, 'syncWithoutDetaching reste idempotent (pas de doublon)');
    }
}

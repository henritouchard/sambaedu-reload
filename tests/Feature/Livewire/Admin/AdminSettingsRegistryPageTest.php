<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Admin;

use App\Models\RegistrySetting;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\Concerns\SeedsWorkstationConfig;
use Tests\TestCase;

/**
 * Page réglages serveur « Registre — valeurs par défaut » (/admin/settings/registry)
 * — Story 27.3ter, AC4b / AC7.
 *
 * L'admin (`server.admin`) fixe la VALEUR PAR DÉFAUT (`registry_settings.value`) du
 * catalogue (le défaut diffusé à toute la flotte). Vérifie : Gate admin (403 sans
 * droit), édition du défaut persiste, validation rejette une valeur incohérente,
 * warning exige confirmation, (dés)activation du réglage.
 */
class AdminSettingsRegistryPageTest extends TestCase
{
    use SeedsWorkstationConfig;

    private const COMPONENT = 'pages::admin.settings.registry.index';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        if (! config('app.key')) {
            config(['app.key' => 'base64:' . base64_encode(random_bytes(32))]);
        }

        $this->seedWorkstationContextSchemas();
        $this->ensureRegistryTable();
        $this->ensureSpatieTables();

        Permission::firstOrCreate(['name' => 'server.admin', 'guard_name' => 'web']);
    }

    private function ensureRegistryTable(): void
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
                $t->json('options')->nullable();
                $t->text('warning')->nullable();
                $t->boolean('is_active')->default(true);
                $t->boolean('overrides_locked')->default(false);
                $t->timestamps();
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
        foreach (['model_has_permissions' => 'omp_mhp2', 'model_has_roles' => 'omp_mhr2'] as $table => $pk) {
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

    private function admin(): User
    {
        $u = User::query()->create(['login' => 'reg-admin', 'role' => 'admin', 'is_active' => true]);
        $u->givePermissionTo('server.admin');

        return $u;
    }

    private function setting(array $overrides = []): RegistrySetting
    {
        return RegistrySetting::create(array_merge([
            'key' => 'show_file_extensions',
            'label' => 'Afficher les extensions',
            'hive' => 'HKCU',
            'path' => 'Software\\X\\Advanced',
            'name' => 'HideFileExt',
            'type' => 'REG_DWORD',
            'value' => '0',
            'is_active' => true,
        ], $overrides));
    }

    #[Test]
    public function blocks_access_without_server_admin(): void
    {
        $viewer = User::query()->create(['login' => 'viewer-a', 'role' => 'prof', 'is_active' => true]);
        $this->actingAs($viewer);

        Livewire::test(self::COMPONENT)->assertStatus(403);
    }

    #[Test]
    public function renders_for_admin(): void
    {
        $this->setting();
        $this->actingAs($this->admin());

        Livewire::test(self::COMPONENT)
            ->assertOk()
            ->assertSee('Afficher les extensions');
    }

    #[Test]
    public function edit_default_persists_registry_settings_value(): void
    {
        $setting = $this->setting(['value' => '0']);
        $this->actingAs($this->admin());

        Livewire::test(self::COMPONENT)
            ->call('openEdit', $setting->id)
            ->set('formValue', '1')
            ->call('saveDefault')
            ->assertHasNoErrors();

        self::assertSame('1', $setting->fresh()->value);
    }

    #[Test]
    public function validation_rejects_non_integer_default_for_dword(): void
    {
        $setting = $this->setting(['value' => '0']);
        $this->actingAs($this->admin());

        Livewire::test(self::COMPONENT)
            ->call('openEdit', $setting->id)
            ->set('formValue', 'xx')
            ->call('saveDefault')
            ->assertHasErrors('formValue');

        self::assertSame('0', $setting->fresh()->value, 'la valeur ne change pas si invalide');
    }

    #[Test]
    public function validation_rejects_empty_string_default_for_sz(): void
    {
        $setting = $this->setting(['type' => 'REG_SZ', 'value' => 'défaut']);
        $this->actingAs($this->admin());

        Livewire::test(self::COMPONENT)
            ->call('openEdit', $setting->id)
            ->set('formValue', '')
            ->call('saveDefault')
            ->assertHasErrors('formValue');

        self::assertSame('défaut', $setting->fresh()->value, 'la valeur ne change pas si vide');
    }

    #[Test]
    public function validation_rejects_qword_overflow_default_instead_of_clamping(): void
    {
        // Régression N1 : un QWORD à 20 chiffres (> 2^63-1) doit être REJETÉ,
        // pas clampé silencieusement à PHP_INT_MAX par (int).
        $setting = $this->setting(['type' => 'REG_QWORD', 'value' => '0']);
        $this->actingAs($this->admin());

        Livewire::test(self::COMPONENT)
            ->call('openEdit', $setting->id)
            ->set('formValue', '99999999999999999999')
            ->call('saveDefault')
            ->assertHasErrors('formValue');

        self::assertSame('0', $setting->fresh()->value, 'la valeur ne change pas en cas d\'overflow');
    }

    #[Test]
    public function warning_default_requires_explicit_confirmation(): void
    {
        $setting = $this->setting([
            'key' => 'disable_uac',
            'label' => 'Désactiver l\'UAC',
            'hive' => 'HKLM',
            'name' => 'EnableLUA',
            'value' => '1',
            'warning' => 'Désactive l\'UAC : trou de sécurité + casse Démarrer + redémarrage.',
        ]);
        $this->actingAs($this->admin());

        Livewire::test(self::COMPONENT)
            ->call('openEdit', $setting->id)
            ->set('formValue', '0')
            ->call('saveDefault')
            ->assertHasErrors('warningAcknowledged');

        self::assertSame('1', $setting->fresh()->value);

        Livewire::test(self::COMPONENT)
            ->call('openEdit', $setting->id)
            ->set('formValue', '0')
            ->set('warningAcknowledged', true)
            ->call('saveDefault')
            ->assertHasNoErrors();

        self::assertSame('0', $setting->fresh()->value);
    }

    #[Test]
    public function toggle_lock_freezes_new_overrides_without_touching_diffusion(): void
    {
        // « Geler » = verrouiller l'ajout de nouveaux overrides (overrides_locked),
        // SANS toucher à is_active (la diffusion reste inchangée).
        $setting = $this->setting(['overrides_locked' => false, 'is_active' => true]);
        $this->actingAs($this->admin());

        Livewire::test(self::COMPONENT)->call('toggleLock', $setting->id);

        self::assertTrue((bool) $setting->fresh()->overrides_locked, 'le réglage est gelé');
        self::assertTrue((bool) $setting->fresh()->is_active, 'la diffusion (is_active) n\'est PAS touchée');
    }
}

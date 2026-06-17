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
 * Onglet Livewire « Registre » de la page d'un WorkstationGroup — Story 27.3ter,
 * AC4a / AC7. Le composant édite les OVERRIDES de valeur par parc (colonne
 * `registry_setting_assignables.value`) : ajouter / éditer / retirer, contrôle
 * adapté au type, validation serveur, confirmation du `warning`.
 *
 * Vérifie : gate app.customize (rendu vs 403), ajout = pivot.value, édition =
 * change la valeur, retirer = detach (revient au défaut), validation rejette une
 * valeur incohérente, warning exige confirmation, n'affiche que les overrides.
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
                $t->json('options')->nullable();
                $t->text('warning')->nullable();
                $t->boolean('is_active')->default(true);
                $t->boolean('overrides_locked')->default(false);
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('registry_setting_assignables')) {
            Schema::create('registry_setting_assignables', function (Blueprint $t): void {
                $t->id();
                $t->unsignedBigInteger('registry_setting_id');
                $t->string('assignable_type');
                $t->unsignedBigInteger('assignable_id');
                $t->text('value')->nullable();
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

    private function override(RegistrySetting $setting, WorkstationGroup $parc, ?string $value): void
    {
        DB::table('registry_setting_assignables')->updateOrInsert(
            [
                'registry_setting_id' => $setting->id,
                'assignable_type' => WorkstationGroup::class,
                'assignable_id' => $parc->id,
            ],
            ['value' => $value, 'created_at' => now(), 'updated_at' => now()],
        );
    }

    #[Test]
    public function renders_for_authorized_manager(): void
    {
        $parc = $this->parc();
        $this->setting();
        $this->actingAs($this->manager());

        Livewire::test(self::COMPONENT, ['groupId' => $parc->id])
            ->assertOk()
            ->assertSee('Overrides de réglages registre');
    }

    #[Test]
    public function blocks_access_without_permission(): void
    {
        $viewer = User::query()->create(['login' => 'viewer-r', 'role' => 'eleve', 'is_active' => true]);
        $this->actingAs($viewer);

        Livewire::test(self::COMPONENT, ['groupId' => 1])->assertStatus(403);
    }

    #[Test]
    public function lists_only_overrides_not_the_full_catalog(): void
    {
        $parc = $this->parc();
        $withOverride = $this->setting(['key' => 'k1', 'label' => 'Réglage dévié', 'name' => 'A']);
        $noOverride = $this->setting(['key' => 'k2', 'label' => 'Réglage par défaut', 'name' => 'B']);
        $this->override($withOverride, $parc, '1');
        $this->actingAs($this->manager());

        $component = Livewire::test(self::COMPONENT, ['groupId' => $parc->id]);

        // La liste « overrides » ne contient QUE le réglage dévié — le réglage sans
        // override n'y figure pas (il n'apparaît que dans le catalogue d'ajout).
        $overrideIds = collect($component->instance()->overrides())->pluck('id')->all();
        self::assertSame([$withOverride->id], $overrideIds);

        $addableIds = collect($component->instance()->addableSettings())->pluck('id')->all();
        self::assertContains($noOverride->id, $addableIds);
        self::assertNotContains($withOverride->id, $addableIds);
    }

    #[Test]
    public function locked_setting_is_not_addable_but_existing_override_remains(): void
    {
        // « Gelé » (overrides_locked) = plus de NOUVEAUX overrides, mais une
        // déviation existante reste listée (et éditable). La diffusion est
        // inchangée (is_active reste true) — pas de stranding.
        $parc = $this->parc();
        $frozenWithOverride = $this->setting(['key' => 'k1', 'name' => 'A', 'overrides_locked' => true]);
        $frozenNoOverride = $this->setting(['key' => 'k2', 'name' => 'B', 'overrides_locked' => true]);
        $this->override($frozenWithOverride, $parc, '1');
        $this->actingAs($this->manager());

        $component = Livewire::test(self::COMPONENT, ['groupId' => $parc->id]);

        // L'override existant sur un réglage gelé reste listé…
        $overrideIds = collect($component->instance()->overrides())->pluck('id')->all();
        self::assertContains($frozenWithOverride->id, $overrideIds);

        // …mais aucun réglage gelé n'est proposé à l'ajout (ni celui déjà dévié,
        // ni celui sans override).
        $addableIds = collect($component->instance()->addableSettings())->pluck('id')->all();
        self::assertNotContains($frozenWithOverride->id, $addableIds);
        self::assertNotContains($frozenNoOverride->id, $addableIds);
    }

    #[Test]
    public function add_override_persists_pivot_value(): void
    {
        $parc = $this->parc();
        $setting = $this->setting();
        $this->actingAs($this->manager());

        Livewire::test(self::COMPONENT, ['groupId' => $parc->id])
            ->call('openAdd', $setting->id)
            ->set('formValue', '1')
            ->call('saveOverride')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('registry_setting_assignables', [
            'registry_setting_id' => $setting->id,
            'assignable_id' => $parc->id,
            'value' => '1',
        ]);
    }

    #[Test]
    public function edit_override_changes_the_value(): void
    {
        $parc = $this->parc();
        $setting = $this->setting();
        $this->override($setting, $parc, '1');
        $this->actingAs($this->manager());

        Livewire::test(self::COMPONENT, ['groupId' => $parc->id])
            ->call('openEdit', $setting->id)
            ->set('formValue', '0')
            ->call('saveOverride')
            ->assertHasNoErrors();

        $this->assertSame('0', DB::table('registry_setting_assignables')
            ->where('registry_setting_id', $setting->id)
            ->where('assignable_id', $parc->id)
            ->value('value'));
    }

    #[Test]
    public function remove_override_detaches_back_to_default(): void
    {
        $parc = $this->parc();
        $setting = $this->setting();
        $this->override($setting, $parc, '1');
        $this->actingAs($this->manager());

        Livewire::test(self::COMPONENT, ['groupId' => $parc->id])
            ->call('removeOverride', $setting->id);

        $this->assertDatabaseMissing('registry_setting_assignables', [
            'registry_setting_id' => $setting->id,
            'assignable_id' => $parc->id,
        ]);
    }

    #[Test]
    public function validation_rejects_non_integer_for_dword(): void
    {
        $parc = $this->parc();
        $setting = $this->setting(); // REG_DWORD
        $this->actingAs($this->manager());

        Livewire::test(self::COMPONENT, ['groupId' => $parc->id])
            ->call('openAdd', $setting->id)
            ->set('formValue', 'not-a-number')
            ->call('saveOverride')
            ->assertHasErrors('formValue');

        $this->assertDatabaseMissing('registry_setting_assignables', [
            'registry_setting_id' => $setting->id,
            'assignable_id' => $parc->id,
        ]);
    }

    #[Test]
    public function validation_rejects_value_outside_options(): void
    {
        $parc = $this->parc();
        $setting = $this->setting([
            'options' => [['value' => '0', 'label' => 'Off'], ['value' => '1', 'label' => 'On']],
        ]);
        $this->actingAs($this->manager());

        Livewire::test(self::COMPONENT, ['groupId' => $parc->id])
            ->call('openAdd', $setting->id)
            ->set('formValue', '9') // hors options
            ->call('saveOverride')
            ->assertHasErrors('formValue');
    }

    #[Test]
    public function validation_rejects_empty_string_for_sz(): void
    {
        $parc = $this->parc();
        $setting = $this->setting(['type' => 'REG_SZ', 'value' => 'défaut']);
        $this->actingAs($this->manager());

        Livewire::test(self::COMPONENT, ['groupId' => $parc->id])
            ->call('openAdd', $setting->id)
            ->set('formValue', '')
            ->call('saveOverride')
            ->assertHasErrors('formValue');

        $this->assertDatabaseMissing('registry_setting_assignables', [
            'registry_setting_id' => $setting->id,
            'assignable_id' => $parc->id,
        ]);
    }

    #[Test]
    public function validation_rejects_qword_overflow_instead_of_clamping(): void
    {
        // Régression N1 : (int) clampe silencieusement à PHP_INT_MAX au-delà ;
        // un QWORD à 20 chiffres doit être REJETÉ, pas stocké tronqué.
        $parc = $this->parc();
        $setting = $this->setting(['type' => 'REG_QWORD', 'value' => '0']);
        $this->actingAs($this->manager());

        Livewire::test(self::COMPONENT, ['groupId' => $parc->id])
            ->call('openAdd', $setting->id)
            ->set('formValue', '99999999999999999999') // > 2^63-1
            ->call('saveOverride')
            ->assertHasErrors('formValue');

        $this->assertDatabaseMissing('registry_setting_assignables', [
            'registry_setting_id' => $setting->id,
            'assignable_id' => $parc->id,
        ]);
    }

    #[Test]
    public function warning_setting_requires_explicit_confirmation(): void
    {
        $parc = $this->parc();
        $setting = $this->setting([
            'key' => 'disable_uac',
            'label' => 'Désactiver l\'UAC',
            'hive' => 'HKLM',
            'name' => 'EnableLUA',
            'warning' => 'Désactive l\'UAC : trou de sécurité + casse Démarrer + redémarrage.',
        ]);
        $this->actingAs($this->manager());

        // Sans confirmation → bloqué.
        Livewire::test(self::COMPONENT, ['groupId' => $parc->id])
            ->call('openAdd', $setting->id)
            ->set('formValue', '0')
            ->call('saveOverride')
            ->assertHasErrors('warningAcknowledged');

        $this->assertDatabaseMissing('registry_setting_assignables', [
            'registry_setting_id' => $setting->id,
            'assignable_id' => $parc->id,
        ]);

        // Avec confirmation → persiste.
        Livewire::test(self::COMPONENT, ['groupId' => $parc->id])
            ->call('openAdd', $setting->id)
            ->set('formValue', '0')
            ->set('warningAcknowledged', true)
            ->call('saveOverride')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('registry_setting_assignables', [
            'registry_setting_id' => $setting->id,
            'assignable_id' => $parc->id,
            'value' => '0',
        ]);
    }
}

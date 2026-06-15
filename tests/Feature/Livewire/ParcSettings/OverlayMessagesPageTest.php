<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\ParcSettings;

use App\Models\OverlaySignal;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\Concerns\SeedsWorkstationConfig;
use Tests\TestCase;

/**
 * Page Livewire « Infos à transmettre » (producteur du canal overlay posté).
 *
 * Vérifie : gate wallpaper.manage (rendu vs 403), publication broadcast/salle
 * (ciblage correct en base), retrait. La logique de signal est par ailleurs
 * couverte par OverlayApiV1Test / OverlaySignalBuilderTest.
 */
class OverlayMessagesPageTest extends TestCase
{
    use SeedsWorkstationConfig;

    private const COMPONENT = 'pages::parc-settings.overlay-messages.index';

    protected function setUp(): void
    {
        parent::setUp();

        if (! config('app.key')) {
            config(['app.key' => 'base64:' . base64_encode(random_bytes(32))]);
        }

        $this->seedWorkstationContextSchemas();
        $this->ensureOverlaySignalsTable();
        $this->ensureSpatieTables();

        Permission::firstOrCreate(['name' => 'wallpaper.manage', 'guard_name' => 'web']);
    }

    private function ensureOverlaySignalsTable(): void
    {
        if (Schema::hasTable('overlay_signals')) {
            return;
        }
        Schema::create('overlay_signals', function (Blueprint $t): void {
            $t->id();
            $t->string('kind', 32)->default('notice');
            $t->string('severity', 16)->default('info');
            $t->string('title');
            $t->text('text');
            $t->string('workstation_uuid', 36)->nullable()->index();
            $t->unsignedBigInteger('workstation_group_id')->nullable()->index();
            $t->string('user_login')->nullable()->index();
            $t->timestamp('expires_at')->nullable()->index();
            $t->string('mode', 16)->nullable(); // Story 27.1 — toggle strict/default
            $t->timestamps();
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
        if (! Schema::hasTable('roles')) {
            Schema::create('roles', function (Blueprint $t): void {
                $t->id();
                $t->string('name');
                $t->string('guard_name');
                $t->timestamps();
                $t->unique(['name', 'guard_name']);
            });
        }
        if (! Schema::hasTable('model_has_permissions')) {
            Schema::create('model_has_permissions', function (Blueprint $t): void {
                $t->unsignedBigInteger('permission_id');
                $t->string('model_type');
                $t->unsignedBigInteger('model_id');
                $t->primary(['permission_id', 'model_id', 'model_type'], 'omp_mhp');
            });
        }
        if (! Schema::hasTable('model_has_roles')) {
            Schema::create('model_has_roles', function (Blueprint $t): void {
                $t->unsignedBigInteger('role_id');
                $t->string('model_type');
                $t->unsignedBigInteger('model_id');
                $t->primary(['role_id', 'model_id', 'model_type'], 'omp_mhr');
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

    private function manager(string $login = 'mgr-1'): User
    {
        $u = User::query()->create(['login' => $login, 'role' => 'prof', 'is_active' => true]);
        $u->givePermissionTo('wallpaper.manage');

        return $u;
    }

    #[Test]
    public function renders_for_authorized_manager(): void
    {
        $this->actingAs($this->manager());

        Livewire::test(self::COMPONENT)
            ->assertOk()
            ->assertSee('Infos à transmettre')
            ->assertSee('Nouveau message');
    }

    #[Test]
    public function blocks_access_without_permission(): void
    {
        $viewer = User::query()->create(['login' => 'viewer-1', 'role' => 'eleve', 'is_active' => true]);
        $this->actingAs($viewer);

        Livewire::test(self::COMPONENT)->assertStatus(403);
    }

    #[Test]
    public function publishing_broadcast_creates_an_untargeted_signal(): void
    {
        $this->actingAs($this->manager());

        Livewire::test(self::COMPONENT)
            ->set('title', 'Annonce')
            ->set('text', 'À tout le parc.')
            ->set('severity', 'warning')
            ->set('targetType', 'broadcast')
            ->set('expiresInHours', null)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('overlay_signals', [
            'title' => 'Annonce',
            'severity' => 'warning',
            'workstation_uuid' => null,
            'workstation_group_id' => null,
            'user_login' => null,
        ]);
    }

    #[Test]
    public function publishing_to_a_salle_sets_the_group_target(): void
    {
        $seed = $this->seedWorkstationContext();
        $this->actingAs($this->manager());

        Livewire::test(self::COMPONENT)
            ->set('title', 'Salle')
            ->set('text', 'x')
            ->set('targetType', 'salle')
            ->set('targetSalleId', $seed['group']->id)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('overlay_signals', [
            'title' => 'Salle',
            'workstation_group_id' => $seed['group']->id,
            'workstation_uuid' => null,
        ]);
    }

    #[Test]
    public function salle_target_requires_a_salle(): void
    {
        $this->actingAs($this->manager());

        Livewire::test(self::COMPONENT)
            ->set('title', 'Sans salle')
            ->set('text', 'x')
            ->set('targetType', 'salle')
            ->set('targetSalleId', null)
            ->call('save')
            ->assertHasErrors('targetSalleId');
    }

    #[Test]
    public function publishing_persists_the_chosen_mode(): void
    {
        // Story 27.1 (FR26) — première exposition du toggle strict/default sur
        // overlay : le mode choisi est persisté sur le signal créé.
        $this->actingAs($this->manager());

        Livewire::test(self::COMPONENT)
            ->set('title', 'Souple')
            ->set('text', 'x')
            ->set('targetType', 'broadcast')
            ->set('mode', 'default')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('overlay_signals', [
            'title' => 'Souple',
            'mode' => 'default',
        ]);
    }

    #[Test]
    public function publishing_defaults_to_strict_mode(): void
    {
        $this->actingAs($this->manager());

        Livewire::test(self::COMPONENT)
            ->set('title', 'Strict défaut')
            ->set('text', 'x')
            ->set('targetType', 'broadcast')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('overlay_signals', [
            'title' => 'Strict défaut',
            'mode' => 'strict',
        ]);
    }

    #[Test]
    public function remove_deletes_the_signal(): void
    {
        $this->actingAs($this->manager());

        $signal = OverlaySignal::create([
            'kind' => 'notice', 'severity' => 'info',
            'title' => 'À retirer', 'text' => 'x',
        ]);

        Livewire::test(self::COMPONENT)
            ->call('remove', $signal->id)
            ->assertHasNoErrors();

        $this->assertDatabaseMissing('overlay_signals', ['id' => $signal->id]);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\AppCustomization;

use App\Enums\AppKind;
use App\Models\AppCustomization;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tests feature — composants Livewire AppCustomize (AC 7).
 */
class AppCustomizeModalTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Model::unguard();
        \App\Models\WorkstationGroup::flushEventListeners();
        \App\Models\UserGroup::flushEventListeners();
        \App\Models\User::flushEventListeners();

        if (empty(config('app.key'))) {
            config()->set('app.key', 'base64:' . base64_encode(random_bytes(32)));
        }

        Schema::create('users', function (Blueprint $t): void {
            $t->id();
            $t->string('login')->unique();
            $t->string('password')->nullable();
            $t->string('role')->default('eleve');
            $t->boolean('is_active')->default(true);
            $t->unsignedBigInteger('ad_rights_bitmask')->default(0);
            $t->timestamps();
        });
        Schema::create('workstation_groups', function (Blueprint $t): void {
            $t->id();
            $t->string('name')->unique();
            $t->boolean('is_physical')->default(true);
            $t->timestamps();
        });
        Schema::create('user_groups', function (Blueprint $t): void {
            $t->id();
            $t->string('name')->unique();
            $t->string('type')->default('classe');
            $t->timestamps();
        });
        Schema::create('app_customizations', function (Blueprint $t): void {
            $t->id();
            $t->string('app_kind', 32);
            $t->nullableMorphs('customizable');
            $t->json('policies_json');
            $t->boolean('is_default')->default(false);
            $t->unsignedBigInteger('created_by')->nullable();
            $t->unsignedBigInteger('updated_by')->nullable();
            $t->timestamps();
        });
        // Tables Spatie minimales (HasRoles sur User cherche ces tables).
        Schema::create('permissions', function (Blueprint $t): void {
            $t->id();
            $t->string('name');
            $t->string('guard_name');
            $t->timestamps();
        });
        Schema::create('roles', function (Blueprint $t): void {
            $t->id();
            $t->string('name');
            $t->string('guard_name');
            $t->timestamps();
        });
        Schema::create('model_has_permissions', function (Blueprint $t): void {
            $t->unsignedBigInteger('permission_id');
            $t->string('model_type');
            $t->unsignedBigInteger('model_id');
        });
        Schema::create('model_has_roles', function (Blueprint $t): void {
            $t->unsignedBigInteger('role_id');
            $t->string('model_type');
            $t->unsignedBigInteger('model_id');
        });
        Schema::create('role_has_permissions', function (Blueprint $t): void {
            $t->unsignedBigInteger('permission_id');
            $t->unsignedBigInteger('role_id');
        });

        config()->set('app-customizations.template_paths.firefox', [
            base_path('tests/fixtures/firefox/template.json'),
        ]);
        config()->set('app-customizations.template_paths.thunderbird', [
            base_path('tests/fixtures/thunderbird/template.json'),
        ]);
        config()->set('app-customizations.export_fs_on_save', false);
    }

    private function actingAsAdmin(): User
    {
        $user = User::create(['login' => 'admin']);
        // Court-circuit Spatie (pas de tables permissions en SQLite :memory:)
        Gate::define('app.customize', fn() => true);
        return tap($user, fn() => $this->actingAs($user));
    }

    #[Test]
    public function firefox_form_saves_homepage_and_bookmarks(): void
    {
        $this->actingAsAdmin();

        Livewire::test('components::organisms.firefox.customize-form', ['appKind' => 'firefox'])
            ->set('homepageUrl', 'https://new-etab/')
            ->call('addBookmark')
            ->set('bookmarks.0.Title', 'A')
            ->set('bookmarks.0.URL', 'https://a.com/')
            ->set('bookmarks.0.Folder', 'Outils')
            ->call('save')
            ->assertDispatched('customization-saved');

        $custo = AppCustomization::ofKind(AppKind::Firefox)->defaults()->first();
        $this->assertNotNull($custo);
        $this->assertSame('https://new-etab/', $custo->policies_json['policies']['Homepage']['URL']);
        $this->assertTrue($custo->policies_json['policies']['Homepage']['Locked']);
        $this->assertSame('A', $custo->policies_json['policies']['Bookmarks'][0]['Title']);
        $this->assertSame('toolbar', $custo->policies_json['policies']['Bookmarks'][0]['Placement']);
    }

    #[Test]
    public function thunderbird_form_saves_proxy(): void
    {
        $this->actingAsAdmin();

        Livewire::test('components::organisms.thunderbird.customize-form', ['appKind' => 'thunderbird'])
            ->set('proxyMode', 'manual')
            ->set('proxyHost', 'proxy.local')
            ->set('proxyPort', '3128')
            ->call('save')
            ->assertDispatched('customization-saved');

        $custo = AppCustomization::ofKind(AppKind::Thunderbird)->defaults()->first();
        $this->assertNotNull($custo);
        $this->assertSame('http://proxy.local:3128', $custo->policies_json['policies']['Proxy']['HTTPProxy']);
    }

    #[Test]
    public function non_admin_without_permission_cannot_save(): void
    {
        $user = User::create(['login' => 'bob']);
        // Permission denied via gate explicite
        Gate::define('app.customize', fn() => false);
        $this->actingAs($user);

        Livewire::test('components::organisms.firefox.customize-form', ['appKind' => 'firefox'])
            ->set('homepageUrl', 'https://attempt/')
            ->call('save');

        $this->assertSame(0, AppCustomization::count());
    }
}

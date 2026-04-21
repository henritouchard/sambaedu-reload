<?php

declare(strict_types=1);

namespace Tests\Feature\AppCustomization;

use App\Enums\AppKind;
use App\Models\AppCustomization;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tests feature — commande `apps:import-customizations-from-legacy` (AC 12).
 */
class ImportCustomizationsFromLegacyCommandTest extends TestCase
{
    private string $tmpBase;

    protected function setUp(): void
    {
        parent::setUp();

        Model::unguard();
        \App\Models\WorkstationGroup::flushEventListeners();
        \App\Models\UserGroup::flushEventListeners();
        \App\Models\User::flushEventListeners();

        Schema::create('users', function (Blueprint $t): void {
            $t->id();
            $t->string('login')->unique();
            $t->string('password')->nullable();
            $t->string('role')->default('eleve');
            $t->boolean('is_active')->default(true);
            $t->unsignedBigInteger('ad_rights_bitmask')->default(0);
            $t->timestamps();
        });
        Schema::create('user_groups', function (Blueprint $t): void {
            $t->id();
            $t->string('name')->unique();
            $t->string('type')->default('classe');
            $t->timestamps();
        });
        Schema::create('workstation_groups', function (Blueprint $t): void {
            $t->id();
            $t->string('name')->unique();
            $t->boolean('is_physical')->default(true);
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

        $this->tmpBase = sys_get_temp_dir() . '/app-custo-import-' . bin2hex(random_bytes(4));
        mkdir($this->tmpBase . '/firefox', 0755, true);
        mkdir($this->tmpBase . '/thunderbird', 0755, true);

        config()->set('app-customizations.fs_base_path', $this->tmpBase);
    }

    protected function tearDown(): void
    {
        $this->cleanup($this->tmpBase);
        parent::tearDown();
    }

    private function cleanup(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (glob($dir . '/*') ?: [] as $f) {
            is_dir($f) ? $this->cleanup($f) : @unlink($f);
        }
        @rmdir($dir);
    }

    #[Test]
    public function dry_run_does_not_write_db(): void
    {
        file_put_contents($this->tmpBase . '/firefox/default.json', json_encode([
            'policies' => ['Homepage' => ['URL' => 'https://etab/']],
        ]));

        $this->artisan('apps:import-customizations-from-legacy', ['--dry-run' => true])
            ->assertSuccessful();

        $this->assertSame(0, AppCustomization::count());
    }

    #[Test]
    public function imports_default_and_scoped_files(): void
    {
        $user = User::create(['login' => 'imported-user']);

        file_put_contents($this->tmpBase . '/firefox/default.json', json_encode([
            'policies' => ['Homepage' => ['URL' => 'https://etab/']],
        ]));
        file_put_contents($this->tmpBase . '/firefox/imported-user.json', json_encode([
            'policies' => ['Homepage' => ['URL' => 'https://user/']],
        ]));
        file_put_contents($this->tmpBase . '/thunderbird/default.json', json_encode([
            'policies' => ['Proxy' => ['Mode' => 'manual']],
        ]));

        $this->artisan('apps:import-customizations-from-legacy')->assertSuccessful();

        $this->assertSame(3, AppCustomization::count());

        $default = AppCustomization::ofKind(AppKind::Firefox)->defaults()->first();
        $this->assertNotNull($default);
        $this->assertSame('https://etab/', $default->policies_json['policies']['Homepage']['URL']);

        $scoped = AppCustomization::query()
            ->where('customizable_type', User::class)
            ->where('customizable_id', $user->id)
            ->first();
        $this->assertNotNull($scoped);
        $this->assertSame('https://user/', $scoped->policies_json['policies']['Homepage']['URL']);
    }

    #[Test]
    public function orphan_files_are_skipped(): void
    {
        file_put_contents($this->tmpBase . '/firefox/unknown-owner.json', json_encode([
            'policies' => ['Homepage' => ['URL' => 'https://ghost/']],
        ]));

        $this->artisan('apps:import-customizations-from-legacy')->assertSuccessful();

        $this->assertSame(0, AppCustomization::count());
    }

    #[Test]
    public function second_run_is_idempotent(): void
    {
        file_put_contents($this->tmpBase . '/firefox/default.json', json_encode([
            'policies' => ['Homepage' => ['URL' => 'https://a/']],
        ]));

        $this->artisan('apps:import-customizations-from-legacy')->assertSuccessful();
        $count1 = AppCustomization::count();

        $this->artisan('apps:import-customizations-from-legacy')->assertSuccessful();
        $count2 = AppCustomization::count();

        $this->assertSame($count1, $count2);
    }

    #[Test]
    public function kind_filter_restricts_scan(): void
    {
        file_put_contents($this->tmpBase . '/firefox/default.json', json_encode([
            'policies' => [],
        ]));
        file_put_contents($this->tmpBase . '/thunderbird/default.json', json_encode([
            'policies' => [],
        ]));

        $this->artisan('apps:import-customizations-from-legacy', ['--kind' => 'firefox'])
            ->assertSuccessful();

        $this->assertSame(1, AppCustomization::count());
        $this->assertSame(AppKind::Firefox, AppCustomization::first()->app_kind);
    }
}

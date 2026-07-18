<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Admin;

use App\Models\User;
use App\Services\FilePolicyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Page /admin/settings/files — réglage GLOBAL de la politique de gestion des
 * fichiers : trois capacités indépendantes (home/shares/nextcloud) + URL serveur
 * Nextcloud, persistées via {@see FilePolicyService} (SystemSetting `files.policy`).
 */
class FilePolicySettingsTest extends TestCase
{
    use RefreshDatabase;

    private const COMPONENT = 'pages::admin.settings.files._partials.personnels-partages-tab';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        $admin = User::query()->create(['login' => 'files-admin', 'role' => 'prof', 'is_active' => true]);
        $this->actingAs($admin);
        Gate::before(fn ($user, string $ability) => $ability === 'server.admin' ? true : null);
    }

    #[Test]
    public function it_prefills_the_persisted_global_capabilities(): void
    {
        FilePolicyService::setGlobal(false, true, true, 'https://cloud.etab.fr');

        Livewire::test(self::COMPONENT)
            ->assertSet('home', false)
            ->assertSet('shares', true)
            ->assertSet('nextcloud', true)
            ->assertSet('nextcloudServerUrl', 'https://cloud.etab.fr');
    }

    #[Test]
    public function it_defaults_to_home_and_shares_when_nothing_saved(): void
    {
        Livewire::test(self::COMPONENT)
            ->assertSet('home', true)
            ->assertSet('shares', true)
            ->assertSet('nextcloud', false);
    }

    #[Test]
    public function saving_persists_each_capability_independently(): void
    {
        Livewire::test(self::COMPONENT)
            ->set('home', false)
            ->set('shares', true)
            ->set('nextcloud', true)
            ->set('nextcloudServerUrl', 'https://cloud.etab.fr')
            ->call('save')
            ->assertHasNoErrors();

        self::assertSame(
            ['home' => false, 'shares' => true, 'nextcloud' => true],
            FilePolicyService::capabilities(),
        );
        self::assertSame('https://cloud.etab.fr', FilePolicyService::globalConfig()['nextcloud_server_url']);
    }

    #[Test]
    public function saving_everything_off_is_web_only(): void
    {
        Livewire::test(self::COMPONENT)
            ->set('home', false)
            ->set('shares', false)
            ->set('nextcloud', false)
            ->call('save')
            ->assertHasNoErrors();

        self::assertSame(
            ['home' => false, 'shares' => false, 'nextcloud' => false],
            FilePolicyService::capabilities(),
        );
    }
}

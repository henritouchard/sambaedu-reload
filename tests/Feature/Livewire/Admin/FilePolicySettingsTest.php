<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Admin;

use App\Enums\FilePolicyMode;
use App\Models\User;
use App\Services\FilePolicyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Page /admin/settings/files — déclaration du défaut d'instance de la politique
 * de gestion des fichiers (mode + config Nextcloud), persistée via
 * {@see FilePolicyService} (SystemSetting `files.policy`).
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
    public function it_prefills_the_persisted_global_policy(): void
    {
        FilePolicyService::setGlobal(FilePolicyMode::AutreWeb, 'https://cloud.etab.fr');

        Livewire::test(self::COMPONENT)
            ->assertSet('mode', 'autre_web')
            ->assertSet('nextcloudServerUrl', 'https://cloud.etab.fr');
    }

    #[Test]
    public function it_defaults_to_partages_when_nothing_saved(): void
    {
        Livewire::test(self::COMPONENT)->assertSet('mode', 'partages');
    }

    #[Test]
    public function saving_persists_the_global_policy(): void
    {
        Livewire::test(self::COMPONENT)
            ->set('mode', 'nextcloud_desktop')
            ->set('nextcloudServerUrl', 'https://cloud.etab.fr')
            ->call('save')
            ->assertHasNoErrors();

        self::assertSame(FilePolicyMode::NextcloudDesktop, FilePolicyService::globalMode());
        self::assertSame('https://cloud.etab.fr', FilePolicyService::globalConfig()['nextcloud']['server_url']);
    }

    #[Test]
    public function a_forged_invalid_mode_is_refused_and_writes_nothing(): void
    {
        Livewire::test(self::COMPONENT)
            ->set('mode', 'bogus')
            ->call('save');

        // Rien persisté → repli défaut Partages.
        self::assertSame(FilePolicyMode::Partages, FilePolicyService::globalMode());
    }
}

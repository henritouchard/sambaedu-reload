<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Admin;

use App\Models\User;
use App\Services\FilePolicyService;
use App\Services\Shortcuts\PortalShortcutIcon;
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
            // La capacité OpenCloud reste ÉTEINTE : elle est INDÉPENDANTE, et
            // basculer les autres ne l'allume pas — c'est la propriété que ce
            // test existe pour tenir, énoncée sur un axe de plus.
            ['home' => false, 'shares' => true, 'nextcloud' => true, 'opencloud' => false],
            FilePolicyService::capabilities(),
        );
        self::assertSame('https://cloud.etab.fr', FilePolicyService::globalConfig()['nextcloud_server_url']);
    }

    /**
     * Il n'y a plus de bouton « Enregistrer » : chaque bascule persiste seule via
     * le hook `updated()`. Ce test n'appelle donc JAMAIS `save()` — c'est tout
     * son intérêt (les autres l'appellent et masqueraient une régression).
     */
    #[Test]
    public function toggling_a_capability_persists_without_calling_save(): void
    {
        Livewire::test(self::COMPONENT)
            ->set('home', false)
            ->assertHasNoErrors();

        self::assertFalse(FilePolicyService::capabilities()['home']);
    }

    /** L'URL Nextcloud persiste elle aussi à la volée (`wire:model.blur`). */
    #[Test]
    public function editing_the_nextcloud_url_persists_without_calling_save(): void
    {
        Livewire::test(self::COMPONENT)
            ->set('nextcloud', true)
            ->set('nextcloudServerUrl', 'https://cloud.autosave.fr')
            ->assertHasNoErrors();

        self::assertSame('https://cloud.autosave.fr', FilePolicyService::globalConfig()['nextcloud_server_url']);
    }

    /**
     * Le raccourci-portail : une bascule qui persiste ET qui met l'icône à
     * disposition, sans quoi le `.lnk` porterait l'icône de `rundll32.exe` sur
     * tous les bureaux de l'établissement.
     */
    #[Test]
    public function toggling_the_portal_shortcut_persists_and_publishes_its_icon(): void
    {
        $served = sys_get_temp_dir().'/se5-portal-icon-'.uniqid();
        config(['shortcut_icons.served_path' => $served]);

        Livewire::test(self::COMPONENT)
            ->set('nextcloud', true)
            ->set('nextcloudServerUrl', 'https://cloud.etab.fr')
            ->set('nextcloudDesktopShortcut', true)
            ->assertHasNoErrors();

        self::assertTrue(FilePolicyService::globalConfig()['nextcloud_desktop_shortcut']);

        $published = app(PortalShortcutIcon::class)->current();
        self::assertNotNull($published, 'la publication a lieu au geste d\'administration');
        self::assertFileExists($served.'/'.$published['asset']);
    }

    /**
     * Le réglage est INDÉPENDANT des capacités : basculer `home` ou `shares` ne
     * doit jamais faire disparaître un raccourci déjà posé sur les bureaux.
     */
    #[Test]
    public function toggling_another_capability_never_drops_the_portal_shortcut(): void
    {
        FilePolicyService::setGlobal(
            true,
            true,
            true,
            'https://cloud.etab.fr',
            nextcloudDesktopShortcut: true,
        );

        Livewire::test(self::COMPONENT)
            ->assertSet('nextcloudDesktopShortcut', true)
            ->set('shares', false)
            ->assertHasNoErrors();

        self::assertTrue(FilePolicyService::globalConfig()['nextcloud_desktop_shortcut']);
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
            ['home' => false, 'shares' => false, 'nextcloud' => false, 'opencloud' => false],
            FilePolicyService::capabilities(),
        );
    }
}

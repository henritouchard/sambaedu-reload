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
 * LA PAGE DE CONNEXION NEXTCLOUD — ce qu'elle écrit, et surtout ce qu'elle
 * N'ÉCRIT PAS.
 *
 * ---------------------------------------------------------------------------
 * **RECADRAGE DE LA STORY 63.3.** Ce fichier éprouvait les trois interrupteurs
 * `home` / `shares` / `nextcloud` de l'onglet « Personnels et partagés ». Ces
 * interrupteurs n'existent plus : ils sont DÉRIVÉS des emplacements et du cloud
 * actif, décidés sur l'onglet « Emplacements et cloud » et projetés sur
 * `files.policy` par le miroir. La propriété que ces tests tenaient — *« chaque
 * réglage persiste seul, sans effet de bord sur les autres »* — est conservée,
 * et elle est exprimée à deux endroits :
 *  - ICI, sur les réglages de connexion, avec l'invariant renforcé : cette page
 *    ne touche JAMAIS aux quatre booléens ;
 *  - dans {@see FileLocationsMirrorTest}, sur les emplacements eux-mêmes.
 * ---------------------------------------------------------------------------
 */
class FilePolicySettingsTest extends TestCase
{
    use RefreshDatabase;

    private const COMPONENT = 'pages::admin.settings.files._partials.nextcloud-connection';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        $admin = User::query()->create(['login' => 'files-admin', 'role' => 'prof', 'is_active' => true]);
        $this->actingAs($admin);
        Gate::before(fn ($user, string $ability) => $ability === 'server.admin' ? true : null);
    }

    #[Test]
    public function it_prefills_the_persisted_connection_settings(): void
    {
        FilePolicyService::setGlobal(false, true, true, 'https://cloud.etab.fr', 'admin', 'se4fs', false);

        Livewire::test(self::COMPONENT)
            ->assertSet('nextcloudServerUrl', 'https://cloud.etab.fr')
            ->assertSet('nextcloudAdminUser', 'admin')
            ->assertSet('nextcloudSmbHost', 'se4fs')
            ->assertSet('nextcloudVerifyTls', false);
    }

    #[Test]
    public function it_starts_on_an_empty_connection_when_nothing_is_saved(): void
    {
        Livewire::test(self::COMPONENT)
            ->assertSet('nextcloudServerUrl', '')
            ->assertSet('nextcloudAdminUser', '');
    }

    /**
     * **L'INVARIANT CENTRAL DU DÉMÉNAGEMENT** : chaque réglage de connexion
     * persiste seul, et les QUATRE booléens — dérivés des emplacements — ne
     * bougent pas d'un pouce. Une page de connexion qui en écrirait un
     * ouvrirait un second chemin de décision, celui-là même que la story ferme.
     */
    #[Test]
    public function saving_the_connection_never_writes_any_of_the_four_capabilities(): void
    {
        FilePolicyService::setGlobal(false, true, true, 'https://cloud.etab.fr', 'admin', 'se4fs', true, true);

        Livewire::test(self::COMPONENT)
            ->set('nextcloudSmbHost', 'autre-serveur')
            ->call('save')
            ->assertHasNoErrors();

        self::assertSame(
            ['home' => false, 'shares' => true, 'nextcloud' => true, 'opencloud' => true],
            FilePolicyService::capabilities(),
        );
        self::assertSame('autre-serveur', FilePolicyService::globalConfig()['nextcloud_smb_host']);
    }

    /**
     * Il n'y a pas de bouton « Enregistrer » sur ce bloc : chaque champ persiste
     * seul via le hook `updated()`. Ce test n'appelle donc JAMAIS `save()` —
     * c'est tout son intérêt (les autres l'appellent et masqueraient une
     * régression).
     */
    #[Test]
    public function editing_the_nextcloud_url_persists_without_calling_save(): void
    {
        Livewire::test(self::COMPONENT)
            ->set('nextcloudServerUrl', 'https://cloud.autosave.fr')
            ->assertHasNoErrors();

        self::assertSame('https://cloud.autosave.fr', FilePolicyService::globalConfig()['nextcloud_server_url']);
    }

    /**
     * L'icône du raccourci-portail est publiée à l'ENREGISTREMENT, sans aucune
     * case ni aucune condition (Story 63.2 : le raccourci suit le cloud actif,
     * la case a disparu). Sans cette publication, le `.lnk` porterait l'icône de
     * `rundll32.exe` sur tous les bureaux de l'établissement.
     */
    #[Test]
    public function saving_the_connection_publishes_the_portal_icon(): void
    {
        $served = sys_get_temp_dir().'/se5-portal-icon-'.uniqid();
        config(['shortcut_icons.served_path' => $served]);

        // Aucune capacité cloud activée : la publication n'est plus conditionnée
        // à quoi que ce soit — publier une icône n'active rien et ne se voit
        // nulle part tant qu'aucun raccourci ne la réclame.
        Livewire::test(self::COMPONENT)
            ->set('nextcloudSmbHost', 'se4fs')
            ->assertHasNoErrors();

        $published = app(PortalShortcutIcon::class)->current();
        self::assertNotNull($published, 'la publication a lieu au geste d\'administration');
        self::assertFileExists($served.'/'.$published['asset']);
    }

    /**
     * `nextcloud_desktop_shortcut` n'a plus de lecteur (Story 63.2) mais reste
     * PERSISTÉE : la retirer casserait le payload de `files.policy`. Un
     * enregistrement depuis cet écran, qui ne la nomme plus, ne doit donc pas
     * l'effacer.
     */
    #[Test]
    public function saving_the_connection_never_wipes_the_orphaned_portal_shortcut_key(): void
    {
        FilePolicyService::setGlobal(
            true,
            true,
            true,
            'https://cloud.etab.fr',
            nextcloudDesktopShortcut: true,
        );

        Livewire::test(self::COMPONENT)
            ->set('nextcloudSmbHost', 'se4fs')
            ->assertHasNoErrors();

        self::assertTrue(FilePolicyService::globalConfig()['nextcloud_desktop_shortcut']);
    }

    /**
     * Même règle pour la clé neuve du chemin d'accès (Story 63.3) : cet écran ne
     * la nomme pas, il ne doit donc jamais la faire retomber sur son défaut.
     */
    #[Test]
    public function saving_the_connection_never_wipes_the_cloud_access_path(): void
    {
        FilePolicyService::setGlobal(
            true,
            true,
            true,
            'https://cloud.etab.fr',
            cloudAccessPath: 'client_natif',
        );

        Livewire::test(self::COMPONENT)
            ->set('nextcloudSmbHost', 'se4fs')
            ->assertHasNoErrors();

        self::assertSame('client_natif', FilePolicyService::globalConfig()['cloud_access_path']);
    }
}

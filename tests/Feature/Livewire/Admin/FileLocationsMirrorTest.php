<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Admin;

use App\Enums\ActiveCloud;
use App\Enums\FileBackendName;
use App\Models\User;
use App\Services\FilePolicyService;
use App\Services\Filesystem\FileLocationPolicyMirror;
use App\Services\Filesystem\FileLocations;
use App\Services\Filesystem\FileLocationService;
use App\Services\Nextcloud\NextcloudConnectionConfig;
use App\Services\OpenCloud\OpenCloudConnectionConfig;
use App\Services\ServiceCredentials;
use App\Services\Shortcuts\PortalShortcutIcon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Story 63.3 AC5 — LE MIROIR DÉRIVÉ, ET LE FAIT QU'AUCUN RÉGLAGE PERSISTÉ N'EST
 * PERDU.
 *
 * `files.locations` est la SOURCE ; les quatre booléens de `files.policy` en
 * sont la projection, écrite dans le même geste. Les huit réglages de connexion,
 * eux, sont CONSERVÉS clé par clé — et le piège est nommé : le paramètre d'URL
 * Nextcloud de `setGlobal()` n'est pas nullable, il est TOUJOURS écrit ; un
 * miroir qui l'omettrait effacerait l'adresse de l'instance et éteindrait la
 * chaîne entière.
 */
class FileLocationsMirrorTest extends TestCase
{
    use RefreshDatabase;

    private const COMPONENT = 'pages::admin.settings.files._partials.emplacements-tab';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Http::fake();

        $admin = User::query()->create(['login' => 'files-admin', 'role' => 'prof', 'is_active' => false]);
        $admin->forceFill(['source' => 'federated'])->save();
        $this->actingAs($admin);
        Gate::before(fn ($user, string $ability) => $ability === 'server.admin' ? true : null);

        config(['shortcut_icons.served_path' => sys_get_temp_dir().'/se5-portal-icon-'.uniqid()]);
    }

    private static function decide(FileBackendName $perso, FileBackendName $partage, ActiveCloud $cloud): void
    {
        FileLocationService::set(FileLocations::make($perso, $partage, $cloud));
    }

    /** Les deux connexions COMPLÈTES : les deux clouds sont posables. */
    private function bothConnectionsConfigured(bool $nextcloud, bool $opencloud): void
    {
        app(ServiceCredentials::class)->put(NextcloudConnectionConfig::CREDENTIAL_NAME, 'secret-nc');
        app(ServiceCredentials::class)->put(OpenCloudConnectionConfig::CREDENTIAL_NAME, 'secret-oc');

        FilePolicyService::setGlobal(
            true, true, $nextcloud,
            'https://cloud.etab.fr', 'nc-admin', 'se4fs', true,
            $opencloud, 'https://fichiers.etab.fr', 'oc-admin', false,
            true,
            'client_natif',
        );
    }

    // =====================================================================
    // Les trois positions d'`ActiveCloud` × les quatre combinaisons
    // =====================================================================

    public static function decisions(): array
    {
        return [
            'aucun cloud, tout sur le serveur' => ['posix', 'posix', 'aucun', true, true, false, false],
            'nextcloud actif, tout sur le serveur' => ['posix', 'posix', 'nextcloud', true, true, true, false],
            'nextcloud actif, perso au cloud' => ['nextcloud', 'posix', 'nextcloud', false, true, true, false],
            'nextcloud actif, partagé au cloud' => ['posix', 'nextcloud', 'nextcloud', true, false, true, false],
            'nextcloud actif, les deux au cloud' => ['nextcloud', 'nextcloud', 'nextcloud', false, false, true, false],
            'opencloud actif, tout sur le serveur' => ['posix', 'posix', 'opencloud', true, true, false, true],
            'opencloud actif, perso au cloud' => ['opencloud', 'posix', 'opencloud', false, true, false, true],
            'opencloud actif, les deux au cloud' => ['opencloud', 'opencloud', 'opencloud', false, false, false, true],
        ];
    }

    #[Test]
    #[DataProvider('decisions')]
    public function the_mirror_projects_the_decision_onto_the_four_booleans(
        string $perso,
        string $partage,
        string $cloud,
        bool $home,
        bool $shares,
        bool $nextcloud,
        bool $opencloud,
    ): void {
        $this->bothConnectionsConfigured(nextcloud: true, opencloud: true);
        self::decide(FileBackendName::Posix, FileBackendName::Posix, ActiveCloud::from($cloud));

        Livewire::test(self::COMPONENT)
            ->set('cloudActif', $cloud)
            ->set('espacePerso', $perso)
            ->set('espacePartage', $partage)
            ->call('save');

        self::assertSame(
            ['home' => $home, 'shares' => $shares, 'nextcloud' => $nextcloud, 'opencloud' => $opencloud],
            FilePolicyService::capabilities(),
        );

        // …et la SOURCE porte bien la décision.
        $locations = FileLocationService::current();
        self::assertSame($perso, $locations->espacePerso->value);
        self::assertSame($partage, $locations->espacePartage->value);
        self::assertSame($cloud, $locations->cloudActif->value);
    }

    /**
     * **« LES DEUX CLOUDS » DEVIENT IRREPRÉSENTABLE DANS `files.policy` AUSSI** :
     * le miroir écrit toujours exactement un des deux booléens à `true`, ou
     * aucun — jamais les deux.
     */
    #[Test]
    public function the_mirror_never_lights_both_cloud_capabilities(): void
    {
        $this->bothConnectionsConfigured(nextcloud: true, opencloud: true);

        foreach (ActiveCloud::cases() as $cloud) {
            self::decide(FileBackendName::Posix, FileBackendName::Posix, ActiveCloud::Aucun);

            Livewire::test(self::COMPONENT)
                ->set('cloudActif', $cloud->value)
                ->call('save');

            $capabilities = FilePolicyService::capabilities();
            $lit = (int) $capabilities['nextcloud'] + (int) $capabilities['opencloud'];

            self::assertLessThanOrEqual(1, $lit, 'le miroir a allumé les deux clouds sur : '.$cloud->value);
            self::assertSame($cloud === ActiveCloud::Nextcloud, $capabilities['nextcloud']);
            self::assertSame($cloud === ActiveCloud::OpenCloud, $capabilities['opencloud']);
        }
    }

    // =====================================================================
    // LE TEST DE REPRISE — aucun réglage persisté n'est perdu
    // =====================================================================

    /**
     * **LE TEST QUI COMPTE.** Sur une instance en place — les huit réglages de
     * connexion renseignés, un secret enregistré — ouvrir l'écran puis
     * enregistrer SANS RIEN CHANGER laisse `globalConfig()` identique CLÉ PAR
     * CLÉ. C'est ce qui rend vraie la fin observable « aucun réglage persisté
     * n'est perdu ».
     */
    #[Test]
    public function saving_without_changing_anything_leaves_the_policy_identical_key_by_key(): void
    {
        // Une instance EN PLACE, c'est-à-dire cohérente : c'est l'état que la
        // commande de reprise laisse derrière elle (les deux espaces sur le
        // serveur de fichiers, un cloud actif configuré).
        $this->bothConnectionsConfigured(nextcloud: true, opencloud: false);
        self::decide(FileBackendName::Posix, FileBackendName::Posix, ActiveCloud::Nextcloud);

        $avant = FilePolicyService::globalConfig();

        Livewire::test(self::COMPONENT)->call('save');

        self::assertSame($avant, FilePolicyService::globalConfig());

        // …et le secret n'a pas bougé non plus.
        self::assertSame(
            'secret-nc',
            app(ServiceCredentials::class)->password(NextcloudConnectionConfig::CREDENTIAL_NAME),
        );
    }

    /**
     * ⚠️ **LE PIÈGE NOMMÉ** : l'URL Nextcloud est le SEUL paramètre non nullable
     * de `setGlobal()`. Un miroir qui ne la repasserait pas l'effacerait —
     * l'objet de configuration lèverait, et toute la chaîne cloud s'éteindrait.
     * Ce test l'épingle sur le cas le plus tentant : enregistrer une décision
     * qui ne parle PAS de Nextcloud.
     */
    #[Test]
    public function the_mirror_never_wipes_the_nextcloud_url_even_when_the_decision_ignores_it(): void
    {
        $this->bothConnectionsConfigured(nextcloud: true, opencloud: true);
        self::decide(FileBackendName::Posix, FileBackendName::Posix, ActiveCloud::Nextcloud);

        Livewire::test(self::COMPONENT)
            ->set('cloudActif', 'opencloud')
            ->call('save');

        $config = FilePolicyService::globalConfig();

        self::assertSame('https://cloud.etab.fr', $config['nextcloud_server_url']);
        self::assertSame('nc-admin', $config['nextcloud_admin_user']);
        self::assertSame('se4fs', $config['nextcloud_smb_host']);
        self::assertTrue($config['nextcloud_verify_tls']);

        // …et les réglages de l'autre produit, symétriquement.
        self::assertSame('https://fichiers.etab.fr', $config['opencloud_server_url']);
        self::assertSame('oc-admin', $config['opencloud_admin_user']);
        self::assertFalse($config['opencloud_verify_tls']);

        // …ainsi que les deux clés qui n'ont pas de contrôle sur cet écran.
        self::assertTrue($config['nextcloud_desktop_shortcut']);
        self::assertSame('client_natif', $config['cloud_access_path']);
    }

    /**
     * **LE MIROIR EST MONO-DIRECTIONNEL** : régler une connexion ne redéfinit
     * jamais un emplacement. `files.policy` n'est jamais relu comme source.
     */
    #[Test]
    public function writing_a_connection_setting_never_moves_a_location(): void
    {
        $this->bothConnectionsConfigured(nextcloud: true, opencloud: false);
        self::decide(FileBackendName::Nextcloud, FileBackendName::Posix, ActiveCloud::Nextcloud);

        Livewire::test('pages::admin.settings.files._partials.nextcloud-connection')
            ->set('nextcloudSmbHost', 'autre-serveur');

        $locations = FileLocationService::current();
        self::assertSame('nextcloud', $locations->espacePerso->value);
        self::assertSame('posix', $locations->espacePartage->value);
        self::assertSame('nextcloud', $locations->cloudActif->value);
    }

    /**
     * L'icône du raccourci-portail est publiée par TOUT chemin qui rend un cloud
     * actif — l'écran comme la commande de reprise. Sans elle, le raccourci
     * arriverait sur les bureaux avec l'icône de `rundll32.exe`.
     */
    #[Test]
    public function saving_the_locations_publishes_the_portal_icon(): void
    {
        $served = sys_get_temp_dir().'/se5-portal-icon-'.uniqid();
        config(['shortcut_icons.served_path' => $served]);

        $this->bothConnectionsConfigured(nextcloud: true, opencloud: false);
        self::decide(FileBackendName::Posix, FileBackendName::Posix, ActiveCloud::Aucun);

        Livewire::test(self::COMPONENT)
            ->set('cloudActif', 'nextcloud')
            ->call('save');

        $published = app(PortalShortcutIcon::class)->current();
        self::assertNotNull($published, 'la publication a lieu au geste d\'administration');
        self::assertFileExists($served.'/'.$published['asset']);
    }

    // =====================================================================
    // LA SOURCE ET SON MIROIR SONT ATOMIQUES (correction de revue)
    // =====================================================================

    /**
     * **L'ÉTAT INTERMÉDIAIRE EST CELUI QUI COÛTE.** Entre les deux écritures, la
     * source dirait « l'espace personnel vit au cloud » — donc plus de lecteur
     * réseau — pendant que la capacité cloud serait encore éteinte, donc aucun
     * provisionnement : les utilisateurs perdraient le seul chemin vers leurs
     * fichiers. Un miroir qui échoue suffisait à le produire, et il restait.
     *
     * Le miroir est ici remplacé par un double qui lève : la décision ne doit
     * PAS avoir été écrite.
     */
    #[Test]
    public function a_failing_mirror_rolls_the_decision_back(): void
    {
        $this->bothConnectionsConfigured(nextcloud: true, opencloud: false);
        self::decide(FileBackendName::Posix, FileBackendName::Posix, ActiveCloud::Nextcloud);

        $avantSource = FileLocationService::current()->toArray();
        $avantPolicy = FilePolicyService::globalConfig();

        $this->app->instance(FileLocationPolicyMirror::class, new class
        {
            public function write(FileLocations $locations): void
            {
                throw new RuntimeException('le miroir a échoué');
            }
        });

        try {
            Livewire::test(self::COMPONENT)
                ->set('espacePerso', 'nextcloud')
                ->call('save');
            self::fail('l\'échec du miroir doit remonter, jamais être avalé');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('le miroir a échoué', $e->getMessage());
        }

        // NI la source, NI le miroir : l'instance reste dans l'état d'avant.
        self::assertSame($avantSource, FileLocationService::current()->toArray());
        self::assertSame($avantPolicy, FilePolicyService::globalConfig());
    }
}

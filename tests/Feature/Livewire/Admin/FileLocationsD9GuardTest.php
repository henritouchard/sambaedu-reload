<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Admin;

use App\Enums\ActiveCloud;
use App\Enums\FileBackendName;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\UserGroup;
use App\Observers\UserGroupObserver;
use App\Services\FilePolicyService;
use App\Services\Filesystem\FileLocations;
use App\Services\Filesystem\FileLocationService;
use App\Services\Nextcloud\NextcloudConnectionConfig;
use App\Services\OpenCloud\OpenCloudConnectionConfig;
use App\Services\ServiceCredentials;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 63.3 AC7 — LA GARDE D9 VUE DE L'ÉCRAN : refusée, expliquée, et ZÉRO
 * ÉCRITURE.
 *
 * Tant que le chantier de bascule n'a pas livré le déménagement des données,
 * déplacer un espace peuplé est refusé — et le refus n'écrit rien du tout : ni
 * la source (`files.locations`), ni son miroir (`files.policy`). L'écran reprend
 * l'état persisté plutôt que d'afficher une décision que la base ne porte pas.
 *
 * Le RICOCHET est couvert : changer le cloud actif alors qu'un espace y vit
 * OBLIGE à déplacer cet espace, la soumission retombe donc sous la garde.
 */
class FileLocationsD9GuardTest extends TestCase
{
    use RefreshDatabase;

    private const COMPONENT = 'pages::admin.settings.files._partials.emplacements-tab';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Http::fake();
        Queue::fake();
        UserGroupObserver::disableSync();

        // L'administrateur de l'écran n'est PAS un compte d'annuaire actif :
        // c'est chaque test qui décide si l'instance porte des données.
        $admin = User::query()->create(['login' => 'files-admin', 'role' => 'prof', 'is_active' => false]);
        $admin->forceFill(['source' => 'federated'])->save();
        $this->actingAs($admin);
        Gate::before(fn ($user, string $ability) => $ability === 'server.admin' ? true : null);

        config(['shortcut_icons.served_path' => sys_get_temp_dir().'/se5-portal-icon-'.uniqid()]);
    }

    /** Le motif d'un refus voyage par un toast : on l'interroge par son message. */
    private static function assertToastContains(object $component, string $needle): void
    {
        $component->assertDispatched(
            'toastMagic',
            fn (string $event, array $params): bool => str_contains((string) ($params['message'] ?? ''), $needle),
        );
    }

    private static function assertNoToastContains(object $component, string $needle): void
    {
        $component->assertNotDispatched(
            'toastMagic',
            fn (string $event, array $params): bool => str_contains((string) ($params['message'] ?? ''), $needle),
        );
    }

    private static function decide(FileBackendName $perso, FileBackendName $partage, ActiveCloud $cloud): void
    {
        FileLocationService::set(FileLocations::make($perso, $partage, $cloud));
    }

    private function bothCloudsConfigured(bool $nextcloud, bool $opencloud): void
    {
        app(ServiceCredentials::class)->put(NextcloudConnectionConfig::CREDENTIAL_NAME, 'secret-nc');
        app(ServiceCredentials::class)->put(OpenCloudConnectionConfig::CREDENTIAL_NAME, 'secret-oc');

        FilePolicyService::setGlobal(
            true, true, $nextcloud,
            'https://cloud.etab.fr', 'nc-admin', 'se4fs', true,
            $opencloud, 'https://fichiers.etab.fr', 'oc-admin', true,
        );
    }

    /** Un compte d'annuaire actif : l'espace personnel porte des données. */
    private function seedDirectoryAccount(): void
    {
        User::query()->create(['login' => 'p.durand', 'role' => 'prof', 'is_active' => true]);
    }

    // =====================================================================
    // Le refus, son motif, et ZÉRO écriture
    // =====================================================================

    #[Test]
    public function moving_a_populated_personal_space_is_refused_and_nothing_is_written(): void
    {
        $this->bothCloudsConfigured(nextcloud: true, opencloud: false);
        self::decide(FileBackendName::Posix, FileBackendName::Posix, ActiveCloud::Nextcloud);
        $this->seedDirectoryAccount();

        $sourceAvant = SystemSetting::get(FileLocationService::SETTING_KEY);
        $policyAvant = FilePolicyService::globalConfig();

        $component = Livewire::test(self::COMPONENT)
            ->set('espacePerso', 'nextcloud')
            ->call('save');

        // ① L'écran REPREND la valeur persistée : il n'affiche pas une décision
        //    que la base ne porte pas.
        $component->assertSet('espacePerso', 'posix');

        // ② ZÉRO écriture, sur les DEUX clés.
        self::assertSame($sourceAvant, SystemSetting::get(FileLocationService::SETTING_KEY));
        self::assertSame($policyAvant, FilePolicyService::globalConfig());

        // ③ Le motif est porté par un toast d'erreur, et il NOMME le chantier.
        self::assertToastContains($component, 'l\'espace personnel porte déjà des données');
        self::assertToastContains($component, 'Epic 64 — la bascule d\'autorité');
    }

    #[Test]
    public function moving_a_populated_shared_space_is_refused_with_its_own_wording(): void
    {
        $this->bothCloudsConfigured(nextcloud: true, opencloud: false);
        self::decide(FileBackendName::Posix, FileBackendName::Posix, ActiveCloud::Nextcloud);
        UserGroup::factory()->create();

        $component = Livewire::test(self::COMPONENT)
            ->set('espacePartage', 'nextcloud')
            ->call('save');

        $component->assertSet('espacePartage', 'posix');

        self::assertToastContains($component, 'l\'espace partagé porte déjà des données');
    }

    // =====================================================================
    // L'instance NEUVE passe librement
    // =====================================================================

    #[Test]
    public function a_brand_new_instance_saves_both_locations_freely(): void
    {
        $this->bothCloudsConfigured(nextcloud: true, opencloud: false);
        self::decide(FileBackendName::Posix, FileBackendName::Posix, ActiveCloud::Nextcloud);

        Livewire::test(self::COMPONENT)
            ->set('espacePerso', 'nextcloud')
            ->set('espacePartage', 'nextcloud')
            ->call('save');

        $locations = FileLocationService::current();
        self::assertSame('nextcloud', $locations->espacePerso->value);
        self::assertSame('nextcloud', $locations->espacePartage->value);
        self::assertSame(
            ['home' => false, 'shares' => false, 'nextcloud' => true, 'opencloud' => false],
            FilePolicyService::capabilities(),
        );
    }

    // =====================================================================
    // LE RICOCHET — changer de cloud déplace un espace, donc retombe sous la
    // garde
    // =====================================================================

    /**
     * Basculer d'un produit à l'autre alors qu'un espace vit au cloud OBLIGE à
     * déplacer cet espace ({@see FileLocations::make()} refuse la combinaison) :
     * la soumission retombe sous la garde, et elle est refusée avec le MÊME
     * motif.
     */
    #[Test]
    public function switching_clouds_while_a_space_lives_there_is_refused_by_the_same_guard(): void
    {
        $this->bothCloudsConfigured(nextcloud: true, opencloud: true);
        self::decide(FileBackendName::Nextcloud, FileBackendName::Posix, ActiveCloud::Nextcloud);
        $this->seedDirectoryAccount();

        $sourceAvant = SystemSetting::get(FileLocationService::SETTING_KEY);

        $component = Livewire::test(self::COMPONENT)
            ->set('cloudActif', 'opencloud')
            ->call('save');

        self::assertSame($sourceAvant, SystemSetting::get(FileLocationService::SETTING_KEY));
        self::assertToastContains($component, 'l\'espace personnel porte déjà des données');

        // …et l'écran est revenu à l'état persisté, cloud compris.
        $component->assertSet('cloudActif', 'nextcloud');
        $component->assertSet('espacePerso', 'nextcloud');
    }

    /** …alors que la même bascule, les deux espaces sur le serveur, PASSE. */
    #[Test]
    public function switching_clouds_is_free_when_both_spaces_stay_on_the_file_server(): void
    {
        $this->bothCloudsConfigured(nextcloud: true, opencloud: true);
        self::decide(FileBackendName::Posix, FileBackendName::Posix, ActiveCloud::Nextcloud);
        $this->seedDirectoryAccount();
        UserGroup::factory()->create();

        Livewire::test(self::COMPONENT)
            ->set('cloudActif', 'opencloud')
            ->call('save');

        self::assertSame('opencloud', FileLocationService::current()->cloudActif->value);
        self::assertSame(
            ['home' => true, 'shares' => true, 'nextcloud' => false, 'opencloud' => true],
            FilePolicyService::capabilities(),
        );
    }

    /**
     * La garde NE PORTE QUE sur les deux emplacements : ré-enregistrer une
     * décision inchangée sur une instance pleine ne se fait jamais refuser.
     */
    #[Test]
    public function re_saving_an_unchanged_decision_on_a_populated_instance_passes(): void
    {
        $this->bothCloudsConfigured(nextcloud: true, opencloud: false);
        self::decide(FileBackendName::Posix, FileBackendName::Posix, ActiveCloud::Nextcloud);
        $this->seedDirectoryAccount();
        UserGroup::factory()->create();

        $component = Livewire::test(self::COMPONENT)->call('save');

        self::assertNoToastContains($component, 'porte déjà des données');
        self::assertSame('nextcloud', FileLocationService::current()->cloudActif->value);
    }

    // =====================================================================
    // La garde de POSABILITÉ est rejouée avant toute écriture
    // =====================================================================

    /**
     * **LA SOUMISSION FORGÉE.** L'écran n'a jamais offert cette position — la
     * connexion est incomplète — mais une propriété Livewire se force. La garde
     * est rejouée avant l'écriture, elle refuse en nommant, et RIEN n'est écrit
     * ni sur la source, ni sur le miroir.
     */
    #[Test]
    public function a_forged_submission_of_an_unavailable_authority_is_refused_with_zero_write(): void
    {
        // Capacité active mais connexion VIDE : le cloud n'est pas posable.
        FilePolicyService::setGlobal(true, true, true, '');
        self::decide(FileBackendName::Posix, FileBackendName::Posix, ActiveCloud::Nextcloud);

        $sourceAvant = SystemSetting::get(FileLocationService::SETTING_KEY);
        $policyAvant = FilePolicyService::globalConfig();

        $component = Livewire::test(self::COMPONENT)
            ->set('espacePerso', 'nextcloud')
            ->call('save');

        self::assertSame($sourceAvant, SystemSetting::get(FileLocationService::SETTING_KEY));
        self::assertSame($policyAvant, FilePolicyService::globalConfig());
        $component->assertSet('espacePerso', 'posix');

        self::assertToastContains($component, 'La connexion à l\'instance Nextcloud est incomplète');
    }

    /** Une valeur d'autorité hors vocabulaire est refusée en nommant, jamais ramenée à un défaut. */
    #[Test]
    public function a_forged_out_of_vocabulary_authority_is_refused_by_name(): void
    {
        $this->bothCloudsConfigured(nextcloud: true, opencloud: false);
        self::decide(FileBackendName::Posix, FileBackendName::Posix, ActiveCloud::Nextcloud);

        $sourceAvant = SystemSetting::get(FileLocationService::SETTING_KEY);

        $component = Livewire::test(self::COMPONENT)
            ->set('espacePerso', 'dropbox')
            ->call('save');

        self::assertSame($sourceAvant, SystemSetting::get(FileLocationService::SETTING_KEY));
        self::assertToastContains($component, 'valeur d\'autorité inconnue');
    }

    /** L'aperçu n'est jamais un emplacement — forgé ou non. */
    #[Test]
    public function the_preview_backend_is_refused_even_when_forged(): void
    {
        $this->bothCloudsConfigured(nextcloud: true, opencloud: false);
        self::decide(FileBackendName::Posix, FileBackendName::Posix, ActiveCloud::Nextcloud);

        $sourceAvant = SystemSetting::get(FileLocationService::SETTING_KEY);

        $component = Livewire::test(self::COMPONENT)
            ->set('espacePartage', 'preview')
            ->call('save');

        self::assertSame($sourceAvant, SystemSetting::get(FileLocationService::SETTING_KEY));
        self::assertToastContains($component, 'aperçu');
    }
}

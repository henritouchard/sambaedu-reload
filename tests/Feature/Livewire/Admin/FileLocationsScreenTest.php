<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Admin;

use App\Enums\ActiveCloud;
use App\Enums\ApplicationStatus;
use App\Enums\FileBackendName;
use App\Models\Application;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\Workstation;
use App\Services\Agent\CloudSyncClient;
use App\Services\FilePolicyService;
use App\Services\Filesystem\FileLocations;
use App\Services\Filesystem\FileLocationService;
use App\Services\Nextcloud\NextcloudConnectionConfig;
use App\Services\OpenCloud\OpenCloudConnectionConfig;
use App\Services\ServiceCredentials;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 63.3 — L'ÉCRAN « EMPLACEMENTS ET CLOUD ».
 *
 * Trois questions, dans cet ordre : quel cloud (choix EXCLUSIF, avec sa page de
 * connexion et elle seule), où vit l'espace personnel, où vit l'espace partagé.
 * Chaque position impossible est ABSENTE avec son motif, chaque position
 * possible dit l'effet qu'elle produit sur le poste.
 *
 * **Un écran qui sonderait au montage rendrait une instance injoignable
 * inutilisable** : `Http::assertNothingSent()` l'épingle.
 *
 * Les deux pages de connexion sont des composants ENFANTS : `Livewire::test()`
 * ne rend d'eux qu'un jalon `wire:name`. C'est ce jalon qu'on interroge pour
 * savoir laquelle est montée — leur contenu, lui, a ses propres suites.
 */
class FileLocationsScreenTest extends TestCase
{
    use RefreshDatabase;

    private const COMPONENT = 'pages::admin.settings.files._partials.emplacements-tab';

    private const HOST = 'pages::admin.settings.files.index';

    private const NEXTCLOUD_PAGE = 'wire:name="pages::admin.settings.files._partials.nextcloud-connection"';

    private const OPENCLOUD_PAGE = 'wire:name="pages::admin.settings.files._partials.opencloud-connection"';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Http::fake();

        // L'administrateur qui pilote l'écran n'est PAS un compte d'annuaire
        // actif : sans cette précaution, la garde de données (volontairement
        // conservatrice) figerait l'espace personnel dès le premier test.
        $admin = User::query()->create(['login' => 'files-admin', 'role' => 'prof', 'is_active' => false]);
        $admin->forceFill(['source' => 'federated'])->save();
        $this->actingAs($admin);

        // La garde n'est ouverte QUE pour ce compte : le test de refus agit
        // sous un autre, et doit retomber sur la vraie autorisation.
        Gate::before(fn (User $user, string $ability): ?bool => $ability === 'server.admin'
            && $user->login === 'files-admin' ? true : null);
    }

    /** Le texte RENDU, sans balises ni entités, et à espaces normalisés. */
    private static function readable(string $html): string
    {
        return (string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES));
    }

    private static function decide(FileBackendName $perso, FileBackendName $partage, ActiveCloud $cloud): void
    {
        FileLocationService::set(FileLocations::make($perso, $partage, $cloud));
    }

    /** Nextcloud actif, connexion COMPLÈTE, décision enregistrée. */
    private function nextcloudInstance(): void
    {
        FilePolicyService::setGlobal(true, true, true, 'https://cloud.etab.fr', 'admin', 'se4fs', true);
        app(ServiceCredentials::class)->put(NextcloudConnectionConfig::CREDENTIAL_NAME, 'secret');
        self::decide(FileBackendName::Posix, FileBackendName::Posix, ActiveCloud::Nextcloud);
    }

    /**
     * Story 63.5 — une application du catalogue INSTALLÉE, dont la recette décrit
     * une désinstallation, et DÉSIGNÉE comme client du produit.
     */
    private function designatedClient(
        string $appId = 'nextcloud-client',
        string $name = 'Nextcloud Desktop',
        ActiveCloud $cloud = ActiveCloud::Nextcloud,
        ?string $xml = null,
        ApplicationStatus $status = ApplicationStatus::Installed,
    ): Application {
        $application = Application::create([
            'app_id' => $appId,
            'name' => $name,
            'status' => $status,
            'xml' => $xml ?? '<package id="'.$appId.'"><install cmd="s.exe" /><remove cmd="u.exe" /></package>',
        ]);

        FilePolicyService::patchGlobal([
            (string) app(CloudSyncClient::class)->policyKeyFor($cloud) => $appId,
        ]);

        return $application;
    }

    /** OpenCloud actif, connexion COMPLÈTE, décision enregistrée. */
    private function openCloudInstance(): void
    {
        FilePolicyService::setGlobal(
            true, true, false, '', null, null, null,
            true, 'https://fichiers.etab.fr', 'admin', true,
        );
        app(ServiceCredentials::class)->put(OpenCloudConnectionConfig::CREDENTIAL_NAME, 'secret');
        self::decide(FileBackendName::Posix, FileBackendName::Posix, ActiveCloud::OpenCloud);
    }

    // =====================================================================
    // AC1 — l'onglet, et les clés mortes
    // =====================================================================

    #[Test]
    public function the_host_page_opens_on_the_locations_tab(): void
    {
        Livewire::test(self::HOST)
            ->assertSet('tab', 'emplacements')
            ->assertSee('Emplacements et cloud');
    }

    #[Test]
    public function the_dead_tab_keys_fall_back_to_the_default(): void
    {
        foreach (['personnels-partages', 'opencloud', 'quotas-fs'] as $deadKey) {
            Livewire::test(self::HOST, ['tab' => $deadKey])->assertSet('tab', 'emplacements');
        }
    }

    // =====================================================================
    // AC2 — le cloud, choix EXCLUSIF, et sa page de connexion
    // =====================================================================

    #[Test]
    public function the_cloud_is_a_single_choice_with_the_three_frozen_labels(): void
    {
        $html = Livewire::test(self::COMPONENT)->html();
        $texte = self::readable($html);

        self::assertStringContainsString('Aucun cloud', $texte);
        self::assertStringContainsString('Nextcloud', $texte);
        self::assertStringContainsString('OpenCloud', $texte);

        // UN choix, pas deux interrupteurs indépendants : trois positions d'un
        // même groupe de boutons radio.
        self::assertSame(3, substr_count($html, 'name="cloud-actif"'));
        self::assertSame(3, substr_count($html, 'type="radio" name="cloud-actif"'));
    }

    #[Test]
    public function only_the_configuration_page_of_the_retained_product_is_revealed(): void
    {
        $this->nextcloudInstance();

        $html = Livewire::test(self::COMPONENT)->html();

        self::assertStringContainsString(self::NEXTCLOUD_PAGE, $html);
        self::assertStringNotContainsString(self::OPENCLOUD_PAGE, $html);
    }

    #[Test]
    public function switching_the_choice_switches_the_configuration_page(): void
    {
        $this->nextcloudInstance();

        $html = Livewire::test(self::COMPONENT)->set('cloudActif', 'opencloud')->html();

        self::assertStringContainsString(self::OPENCLOUD_PAGE, $html);
        self::assertStringNotContainsString(self::NEXTCLOUD_PAGE, $html);
    }

    /**
     * Côté OpenCloud, la page révélée est la sienne — et le DÉPLOIEMENT reste
     * une commande : la page le rappelle, elle ne l'offre pas. Un bouton ici
     * ferait croire que la seule instance légitime est locale.
     */
    #[Test]
    public function the_opencloud_page_is_revealed_and_carries_no_deployment_button(): void
    {
        $this->openCloudInstance();

        $html = Livewire::test(self::COMPONENT)->html();
        self::assertStringContainsString(self::OPENCLOUD_PAGE, $html);
        self::assertStringNotContainsString(self::NEXTCLOUD_PAGE, $html);

        $page = Livewire::test('pages::admin.settings.files._partials.opencloud-connection')->html();
        self::assertStringContainsString('php artisan opencloud:deploy', self::readable($page));
        self::assertStringNotContainsString('wire:click="deploy"', $page);
    }

    #[Test]
    public function no_connection_page_is_revealed_without_an_active_cloud(): void
    {
        $html = Livewire::test(self::COMPONENT)->html();

        self::assertStringNotContainsString(self::NEXTCLOUD_PAGE, $html);
        self::assertStringNotContainsString(self::OPENCLOUD_PAGE, $html);
    }

    // =====================================================================
    // AC3 — les deux emplacements, et l'effet sur le poste
    // =====================================================================

    #[Test]
    public function each_space_states_the_effect_it_produces_on_the_workstation(): void
    {
        $texte = self::readable(Livewire::test(self::COMPONENT)->html());

        self::assertStringContainsString('Espace personnel', $texte);
        self::assertStringContainsString('Espace partagé', $texte);
        self::assertStringContainsString('Serveur de fichiers (SMB)', $texte);
        self::assertStringContainsString('Lecteur K: monté sur le poste', $texte);
        self::assertStringContainsString('Lecteur H: monté sur le poste', $texte);
    }

    #[Test]
    public function the_cloud_position_states_that_no_drive_letter_is_emitted(): void
    {
        $this->nextcloudInstance();

        $texte = self::readable(Livewire::test(self::COMPONENT)->html());

        self::assertStringContainsString('Pas de lettre de lecteur : accès par le client', $texte);
    }

    /** Sans cette phrase, un administrateur croit qu'il éteint le partage personnel. */
    #[Test]
    public function the_screen_says_the_personal_share_stays_in_service_for_the_agent(): void
    {
        $texte = self::readable(Livewire::test(self::COMPONENT)->html());

        self::assertStringContainsString(
            'Le partage personnel du serveur de fichiers reste en service pour l\'agent '
            .'(Bureau, raccourcis, profils applicatifs) : seuls les fichiers de l\'utilisateur '
            .'changent d\'endroit.',
            $texte,
        );
    }

    /** Deux réglages d'instance, et rien d'autre : ni public, ni ligne, ni rang. */
    #[Test]
    public function no_audience_no_row_no_rank_no_precedence_appears(): void
    {
        $this->nextcloudInstance();

        $texte = self::readable(Livewire::test(self::COMPONENT)->html());

        foreach (['Public', 'Rang', 'Précédence', 'Spécificité', 'Hérit'] as $mort) {
            self::assertStringNotContainsString($mort, $texte);
        }
    }

    /**
     * Aucune troisième valeur « aucun » n'est offerte pour un emplacement.
     *
     * **Ce test COMPTE les positions rendues** (correction de revue) : la
     * première rédaction cherchait l'absence de deux `data-testid` que le
     * gabarit ne peut structurellement jamais produire — elle ne pouvait donc
     * pas échouer, quoi qu'on ajoute à l'écran. Compter est la seule forme qui
     * verrait une troisième position apparaître.
     */
    #[Test]
    public function each_location_offers_exactly_the_available_positions_and_not_one_more(): void
    {
        $this->nextcloudInstance();

        $html = Livewire::test(self::COMPONENT)->html();

        // Un cloud configuré ⇒ EXACTEMENT deux positions par emplacement.
        self::assertSame(2, substr_count($html, 'data-testid="espace-perso-option-'));
        self::assertSame(2, substr_count($html, 'data-testid="espace-partage-option-'));
        self::assertSame(2, substr_count($html, 'name="espace-perso"'));
        self::assertSame(2, substr_count($html, 'name="espace-partage"'));

        // …et ce sont bien celles-là, pas d'autres.
        foreach (['espace-perso', 'espace-partage'] as $espace) {
            self::assertStringContainsString('data-testid="'.$espace.'-option-posix"', $html);
            self::assertStringContainsString('data-testid="'.$espace.'-option-nextcloud"', $html);
        }
    }

    /** Sans cloud actif, il n'en reste qu'UNE — et toujours pas de troisième valeur. */
    #[Test]
    public function without_an_active_cloud_a_single_position_is_offered_per_location(): void
    {
        $html = Livewire::test(self::COMPONENT)->html();

        self::assertSame(1, substr_count($html, 'data-testid="espace-perso-option-'));
        self::assertSame(1, substr_count($html, 'data-testid="espace-partage-option-'));
        self::assertStringContainsString('data-testid="espace-perso-option-posix"', $html);
    }

    // =====================================================================
    // AC4 — une position non posable est ABSENTE, avec son motif
    // =====================================================================

    #[Test]
    public function without_an_active_cloud_the_cloud_position_is_absent_and_the_reason_is_shown(): void
    {
        $html = Livewire::test(self::COMPONENT)->html();

        self::assertStringContainsString(
            'Aucun cloud n\'est configuré : choisissez-en un ci-dessus avant d\'y placer un espace.',
            self::readable($html),
        );
        self::assertStringNotContainsString('espace-perso-option-nextcloud', $html);
        self::assertStringNotContainsString('espace-partage-option-opencloud', $html);
    }

    #[Test]
    public function an_incomplete_connection_makes_the_position_absent_with_its_reason(): void
    {
        // Capacité active, connexion VIDE, décision enregistrée.
        FilePolicyService::setGlobal(true, true, true, '');
        self::decide(FileBackendName::Posix, FileBackendName::Posix, ActiveCloud::Nextcloud);

        $html = Livewire::test(self::COMPONENT)->html();
        $texte = self::readable($html);

        self::assertStringContainsString('La connexion à l\'instance Nextcloud est incomplète', $texte);
        self::assertStringContainsString(
            'Complétez-la ci-dessus avant d\'y placer un espace.',
            $texte,
        );
        self::assertStringNotContainsString('espace-perso-option-nextcloud', $html);

        // …et la page de connexion, elle, EST là : c'est par elle qu'on répare.
        self::assertStringContainsString(self::NEXTCLOUD_PAGE, $html);
    }

    /** Connexion complète mais capacité éteinte : ABSENTE aussi. */
    #[Test]
    public function a_disabled_capability_also_makes_the_position_absent(): void
    {
        app(ServiceCredentials::class)->put(NextcloudConnectionConfig::CREDENTIAL_NAME, 'secret');
        FilePolicyService::setGlobal(true, true, false, 'https://cloud.etab.fr', 'admin', 'se4fs', true);
        self::decide(FileBackendName::Posix, FileBackendName::Posix, ActiveCloud::Nextcloud);

        $html = Livewire::test(self::COMPONENT)->html();

        self::assertStringNotContainsString('espace-perso-option-nextcloud', $html);
        self::assertStringContainsString('est incomplète', self::readable($html));
    }

    #[Test]
    public function a_configured_cloud_offers_the_position_and_shows_no_reason(): void
    {
        $this->nextcloudInstance();

        $html = Livewire::test(self::COMPONENT)->html();

        self::assertStringContainsString('espace-perso-option-nextcloud', $html);
        self::assertStringContainsString('espace-partage-option-nextcloud', $html);
        self::assertStringNotContainsString('est incomplète', self::readable($html));
        self::assertStringNotContainsString('espace-perso-refusal', $html);
    }

    // =====================================================================
    // AC8 — le bloc « Réglages » et sa phrase d'honnêteté
    // =====================================================================

    /**
     * ⚠️ **Ce test a changé de sujet avec la story 63.4, et pas de propriété.** Le
     * bloc « Réglages » ne portait que le chemin d'accès au cloud : sans cloud, il
     * était vide, donc absent. Il porte désormais aussi le plafond des espaces
     * personnels et la corbeille des répertoires personnels — deux réglages du
     * SERVEUR DE FICHIERS, qui sont précisément les plus utiles quand aucun cloud
     * n'est actif. Ce qui reste caché sans cloud, c'est le seul réglage qui ne
     * gouvernerait rien : le chemin d'accès.
     */
    #[Test]
    public function the_cloud_access_path_is_absent_without_an_active_cloud(): void
    {
        $html = Livewire::test(self::COMPONENT)->html();

        self::assertStringNotContainsString('Chemin d\'accès au cloud', self::readable($html));
        self::assertStringNotContainsString('cloud-access-path', $html);

        // Le bloc, lui, est bien là : il porte les réglages du serveur de fichiers.
        self::assertStringContainsString('bloc-reglages', $html);
    }

    /**
     * Story 63.5 — LA PHRASE D'HONNÊTETÉ DE 63.3 A DISPARU, et c'est un livrable.
     *
     * Elle disait que la pose du client était livrée par un chantier séparé. Ce
     * chantier est arrivé : la garder ferait de cet écran un écran qui promet
     * moins qu'il ne tient.
     */
    #[Test]
    public function the_settings_block_carries_the_access_path_and_no_longer_denies_its_effect(): void
    {
        $this->nextcloudInstance();

        $texte = self::readable(Livewire::test(self::COMPONENT)->html());

        self::assertStringContainsString('Par le navigateur', $texte);
        self::assertStringNotContainsString(
            'La pose du client de synchronisation sur les postes est livrée par un chantier séparé',
            $texte,
        );
    }

    #[Test]
    public function the_access_path_persists_on_its_own_once_a_client_is_designated(): void
    {
        $this->nextcloudInstance();
        $this->designatedClient();

        Livewire::test(self::COMPONENT)->set('cloudAccessPath', 'client_natif');

        self::assertSame('client_natif', FilePolicyService::globalConfig()['cloud_access_path']);
    }

    /**
     * Story 63.4 — les deux cartes sont MONTÉES dans le bloc « Réglages ». Ce sont
     * des composants ENFANTS : `Livewire::test()` n'en rend qu'un jalon `wire:name`,
     * et c'est ce jalon qu'on interroge — leur contenu a sa propre suite.
     */
    #[Test]
    public function the_settings_block_hosts_the_quota_and_trash_cards(): void
    {
        $this->nextcloudInstance();

        $html = Livewire::test(self::COMPONENT)->html();

        self::assertStringContainsString(
            'wire:name="pages::admin.settings.files._partials.quotas-card"',
            $html,
        );
        self::assertStringContainsString(
            'wire:name="pages::admin.settings.files._partials.corbeille-card"',
            $html,
        );
    }

    /** Sans cloud actif, les deux cartes sont là quand même : elles n'en dépendent pas. */
    #[Test]
    public function the_quota_and_trash_cards_do_not_depend_on_an_active_cloud(): void
    {
        $html = Livewire::test(self::COMPONENT)->html();

        self::assertStringContainsString(
            'wire:name="pages::admin.settings.files._partials.quotas-card"',
            $html,
        );
        self::assertStringContainsString(
            'wire:name="pages::admin.settings.files._partials.corbeille-card"',
            $html,
        );
    }

    /**
     * ⚠️ **CORRECTION DE REVUE — LES DEUX CARTES NE DÉPENDENT D'AUCUNE DÉCISION
     * D'EMPLACEMENT.**
     *
     * ---------------------------------------------------------------------------
     * Elles étaient montées à l'intérieur de la condition qui masque les contrôles
     * d'emplacement. Or cette condition est fausse dès qu'un bandeau de reprise est
     * affiché — c'est-à-dire sur TOUTE instance dont la reprise n'a pas été jouée,
     * soit exactement celles que la migration de bascule vient de modifier.
     * L'administrateur n'aurait alors pu ni voir ni corriger le plafond qu'on venait
     * d'écrire pour lui, ni régler la grâce, ni la corbeille : l'orphelinat que cette
     * story solde, reconduit sous une autre forme.
     * ---------------------------------------------------------------------------
     */
    #[Test]
    public function the_quota_and_trash_cards_survive_a_pending_adoption_banner(): void
    {
        // « accès au home coupé » : la reprise ne peut pas être devinée, le bandeau
        // s'affiche et les contrôles d'emplacement disparaissent.
        FilePolicyService::setGlobal(false, true, false, '');

        $html = Livewire::test(self::COMPONENT)->html();

        self::assertStringContainsString('locations-adoption-notice', $html);
        self::assertStringNotContainsString('bloc-emplacements', $html);

        // …et les deux cartes, elles, sont bien là.
        self::assertStringContainsString('bloc-reglages', $html);
        self::assertStringContainsString(
            'wire:name="pages::admin.settings.files._partials.quotas-card"',
            $html,
        );
        self::assertStringContainsString(
            'wire:name="pages::admin.settings.files._partials.corbeille-card"',
            $html,
        );
    }

    /** Même sur une ligne d'emplacements ILLISIBLE : le quota n'en dépend pas. */
    #[Test]
    public function the_quota_and_trash_cards_survive_an_unreadable_locations_row(): void
    {
        SystemSetting::set(FileLocationService::SETTING_KEY, ['espace_perso.autorite' => 'posix']);

        $html = Livewire::test(self::COMPONENT)->html();

        self::assertStringContainsString('locations-read-error', $html);
        self::assertStringContainsString(
            'wire:name="pages::admin.settings.files._partials.quotas-card"',
            $html,
        );
    }

    // =====================================================================
    // AC6 — les trois branches de la reprise
    // =====================================================================

    #[Test]
    public function a_brand_new_instance_shows_the_defaults_and_can_save(): void
    {
        Livewire::test(self::COMPONENT)
            ->assertSet('decided', false)
            ->assertSet('adoptionNotice', null)
            ->assertSet('espacePerso', 'posix')
            ->assertSet('espacePartage', 'posix')
            ->assertSet('cloudActif', 'aucun')
            ->call('save')
            ->assertSet('decided', true);

        self::assertTrue(FileLocationService::isDecided());
    }

    #[Test]
    public function a_non_default_legacy_state_shows_the_banner_and_hides_the_controls(): void
    {
        // « accès au home coupé » : la reprise ne peut pas être devinée.
        FilePolicyService::setGlobal(false, true, false, '');

        $component = Livewire::test(self::COMPONENT);
        $html = $component->html();

        $component->assertSet(
            'adoptionNotice',
            'Les emplacements n\'ont pas encore été repris depuis les réglages historiques. '
            .'Jouez `php artisan files:adopt-locations` sur le serveur, puis rechargez cette page.',
        );

        self::assertStringContainsString('php artisan files:adopt-locations', self::readable($html));
        self::assertStringContainsString('État hérité (lecture seule)', self::readable($html));

        // Les contrôles sont ABSENTS, pas grisés.
        self::assertStringNotContainsString('cloud-choice-nextcloud', $html);
        self::assertStringNotContainsString('save-locations', $html);
        self::assertStringNotContainsString('bloc-emplacements', $html);
    }

    /** …et aucune écriture d'emplacement n'est possible depuis cette branche. */
    #[Test]
    public function no_location_can_be_written_while_the_adoption_has_not_been_played(): void
    {
        FilePolicyService::setGlobal(false, true, false, '');

        Livewire::test(self::COMPONENT)
            ->set('espacePerso', 'posix')
            ->call('save');

        self::assertFalse(FileLocationService::isDecided());
    }

    /**
     * …mais les blocs de CONNEXION, eux, restent éditables : c'est par eux qu'on
     * répare une connexion incomplète, et la reprise en a besoin.
     */
    #[Test]
    public function the_connection_blocks_stay_editable_while_the_adoption_is_pending(): void
    {
        FilePolicyService::setGlobal(false, true, true, '');

        $html = Livewire::test(self::COMPONENT)->html();

        self::assertStringContainsString('locations-adoption-notice', $html);
        self::assertStringContainsString(self::NEXTCLOUD_PAGE, $html);
    }

    /**
     * **LE CAS QUI N'AVAIT AUCUNE SORTIE** (correction de revue).
     *
     * Reprise non jouée, et AUCUNE des deux capacités cloud héritée : l'écran
     * n'offrait ni radios (bandeau de reprise) ni bloc de connexion (ils
     * suivaient les capacités héritées, toutes deux fausses). Il était
     * totalement inerte — et la commande de reprise, qui refuse précisément cet
     * état, renvoie vers cet écran-là. La seule sortie passait par la base.
     *
     * Les blocs de connexion sont des réglages de CONNEXION : ils ne décident de
     * rien, ils ne peuvent donc pas être conditionnés à une décision.
     */
    #[Test]
    public function the_pending_screen_is_never_inert_even_with_no_cloud_capability_at_all(): void
    {
        // « Web uniquement » hérité : l'accès au home est coupé, aucun cloud.
        FilePolicyService::setGlobal(false, true, false, '');

        $component = Livewire::test(self::COMPONENT);
        $html = $component->html();

        // Le bandeau est là, les contrôles de décision restent absents…
        self::assertStringContainsString('locations-adoption-notice', $html);
        self::assertStringNotContainsString('save-locations', $html);

        // …mais les DEUX blocs de connexion sont montés.
        self::assertStringContainsString(self::NEXTCLOUD_PAGE, $html);
        self::assertStringContainsString(self::OPENCLOUD_PAGE, $html);

        // …et ils sont réellement utilisables : l'administrateur y déclare une
        // instance, qui est persistée.
        Livewire::test('pages::admin.settings.files._partials.nextcloud-connection')
            ->set('nextcloudServerUrl', 'https://cloud.etab.fr')
            ->set('nextcloudAdminUser', 'admin');

        $config = FilePolicyService::globalConfig();
        self::assertSame('https://cloud.etab.fr', $config['nextcloud_server_url']);
        self::assertSame('admin', $config['nextcloud_admin_user']);
    }

    /**
     * **ET LA SORTIE EST COMPLÈTE** : depuis l'écran bloqué, l'administrateur
     * complète la connexion du cloud dont la capacité est héritée, joue la
     * reprise, et l'écran redevient décidable — sans une ligne de SQL.
     */
    #[Test]
    public function completing_a_connection_from_the_pending_screen_opens_the_way_out(): void
    {
        // Héritage NON déductible : le home est coupé, la capacité Nextcloud est
        // allumée mais sa connexion est vide ⇒ la reprise refuse.
        FilePolicyService::setGlobal(false, true, true, '');

        Livewire::test(self::COMPONENT)->assertSet(
            'adoptionNotice',
            'Les emplacements n\'ont pas encore été repris depuis les réglages historiques. '
            .'Jouez `php artisan files:adopt-locations` sur le serveur, puis rechargez cette page.',
        );
        $this->artisan('files:adopt-locations')->assertExitCode(1);

        // L'administrateur complète la connexion DEPUIS le bloc que l'écran
        // continue de monter.
        Livewire::test('pages::admin.settings.files._partials.nextcloud-connection')
            ->set('nextcloudServerUrl', 'https://cloud.etab.fr')
            ->set('nextcloudAdminUser', 'admin')
            ->set('nextcloudAdminPassword', 'app-password');

        // La reprise passe…
        $this->artisan('files:adopt-locations')->assertExitCode(0);

        // …et l'écran redevient décidable : bandeau parti, contrôles présents.
        $component = Livewire::test(self::COMPONENT);
        $component->assertSet('adoptionNotice', null)->assertSet('decided', true);
        self::assertStringContainsString('save-locations', $component->html());
        self::assertSame('nextcloud', $component->get('espacePerso'));
    }

    #[Test]
    public function an_unreadable_row_shows_the_exception_message_and_no_control(): void
    {
        SystemSetting::set(FileLocationService::SETTING_KEY, ['espace_perso.autorite' => 'posix']);

        $component = Livewire::test(self::COMPONENT);
        $html = $component->html();

        self::assertNotNull($component->get('readError'));
        self::assertStringContainsString(
            'doivent toujours être présentes ensemble',
            self::readable($html),
        );

        // Aucun contrôle, et surtout AUCUN repli sur les défauts.
        self::assertStringNotContainsString('cloud-choice-aucun', $html);
        self::assertStringNotContainsString('save-locations', $html);
        self::assertStringNotContainsString(self::NEXTCLOUD_PAGE, $html);
    }

    // =====================================================================
    // L'écran ne se contredit plus après une connexion complétée
    // =====================================================================

    /**
     * **L'ÉCRAN SE CONTREDISAIT** (correction de revue) : l'administrateur
     * choisissait le cloud, l'enregistrait, complétait sa connexion dans le bloc
     * enfant juste en-dessous (auto-enregistré) — et la position cloud
     * n'apparaissait JAMAIS dans le bloc des emplacements, dont le motif
     * continuait d'annoncer « la connexion est incomplète : complétez-la
     * ci-dessus » alors qu'elle venait de l'être. Il fallait recharger la page.
     *
     * L'enfant émet donc un événement après tout enregistrement de connexion, et
     * le parent recalcule ce qu'il propose.
     */
    #[Test]
    public function a_completed_connection_is_announced_to_the_parent_screen(): void
    {
        FilePolicyService::setGlobal(true, true, true, '');
        self::decide(FileBackendName::Posix, FileBackendName::Posix, ActiveCloud::Nextcloud);

        // ① L'enfant ANNONCE chacun des gestes qui change la complétude.
        Livewire::test('pages::admin.settings.files._partials.nextcloud-connection')
            ->set('nextcloudServerUrl', 'https://cloud.etab.fr')
            ->assertDispatched('cloud-connexion-enregistree');

        Livewire::test('pages::admin.settings.files._partials.opencloud-connection')
            ->set('serverUrl', 'https://fichiers.etab.fr')
            ->assertDispatched('cloud-connexion-enregistree');

        // ② …et le parent, à la réception, RECALCULE : la position cloud, absente
        //    tant que la connexion était incomplète, apparaît sans rechargement.
        $parent = Livewire::test(self::COMPONENT);
        self::assertStringNotContainsString('espace-perso-option-nextcloud', $parent->html());

        FilePolicyService::patchGlobal(['nextcloud_admin_user' => 'admin']);
        app(ServiceCredentials::class)->put(NextcloudConnectionConfig::CREDENTIAL_NAME, 'secret');

        $html = $parent->dispatch('cloud-connexion-enregistree')->html();

        self::assertStringContainsString('espace-perso-option-nextcloud', $html);
        self::assertStringNotContainsString('est incomplète', self::readable($html));
    }

    // =====================================================================
    // Un geste qui ne change RIEN n'est jamais refusé
    // =====================================================================

    /**
     * **NE PAS ENFERMER L'ADMINISTRATEUR** (correction de revue). La posabilité
     * était rejouée sur les deux emplacements à chaque enregistrement, y compris
     * ceux qui ne bougeaient pas : dès que la connexion du cloud actif se
     * dégradait (secret révoqué, URL vidée), ré-enregistrer sans rien changer
     * devenait impossible. Elle ne porte plus que sur ce qui change,
     * symétriquement à la garde de données.
     */
    #[Test]
    public function re_saving_without_any_change_is_accepted_even_if_the_connection_degraded(): void
    {
        $this->nextcloudInstance();
        self::decide(FileBackendName::Nextcloud, FileBackendName::Posix, ActiveCloud::Nextcloud);

        // La connexion se dégrade APRÈS la décision : le secret est révoqué.
        app(ServiceCredentials::class)->forget(NextcloudConnectionConfig::CREDENTIAL_NAME);

        $component = Livewire::test(self::COMPONENT)->call('save');

        $component->assertDispatched(
            'toastMagic',
            fn (string $event, array $params): bool => str_contains(
                (string) ($params['message'] ?? ''),
                'Emplacements enregistrés.',
            ),
        );
        self::assertSame('nextcloud', FileLocationService::current()->espacePerso->value);
    }

    /** …mais POSER une position sur cette même connexion dégradée reste refusé. */
    #[Test]
    public function moving_a_location_onto_a_degraded_connection_is_still_refused(): void
    {
        $this->nextcloudInstance();
        app(ServiceCredentials::class)->forget(NextcloudConnectionConfig::CREDENTIAL_NAME);

        $before = SystemSetting::get(FileLocationService::SETTING_KEY);

        Livewire::test(self::COMPONENT)
            ->set('espacePerso', 'nextcloud')
            ->call('save')
            ->assertDispatched(
                'toastMagic',
                fn (string $event, array $params): bool => str_contains(
                    (string) ($params['message'] ?? ''),
                    'est incomplète',
                ),
            );

        self::assertSame($before, SystemSetting::get(FileLocationService::SETTING_KEY));
    }

    // =====================================================================
    // Ce que l'écran dit AVANT le refus
    // =====================================================================

    /** La fenêtre se referme, et l'écran le dit à côté du bouton. */
    #[Test]
    public function the_screen_warns_that_the_choice_freezes_once_an_account_exists(): void
    {
        $this->nextcloudInstance();

        $texte = self::readable(Livewire::test(self::COMPONENT)->html());

        self::assertStringContainsString(
            'Ce choix se fige dès que l\'instance porte un compte ou un groupe',
            $texte,
        );
        self::assertStringContainsString('Tranchez-le avant le premier import d\'annuaire.', $texte);
    }

    /**
     * LE RICOCHET, expliqué AVANT le refus : avec un espace au cloud, cliquer
     * « Aucun cloud » échoue sur « l'espace personnel porte déjà des données » —
     * un motif que rien ne relie au geste qui vient d'être fait.
     */
    #[Test]
    public function the_ricochet_is_explained_when_a_location_designates_the_cloud(): void
    {
        $this->nextcloudInstance();
        self::decide(FileBackendName::Nextcloud, FileBackendName::Posix, ActiveCloud::Nextcloud);

        $html = Livewire::test(self::COMPONENT)->html();

        self::assertStringContainsString('cloud-ricochet-notice', $html);
        self::assertStringContainsString(
            'Un espace vit actuellement sur ce cloud : changer de position ici le déplacerait',
            self::readable($html),
        );
    }

    /** …et elle n'encombre pas l'écran quand les deux espaces sont sur le serveur. */
    #[Test]
    public function no_ricochet_notice_when_both_spaces_stay_on_the_file_server(): void
    {
        $this->nextcloudInstance();

        self::assertStringNotContainsString(
            'cloud-ricochet-notice',
            Livewire::test(self::COMPONENT)->html(),
        );
    }

    // =====================================================================
    // Le rendu ne parle jamais à l'instance, et la garde est double
    // =====================================================================

    #[Test]
    public function rendering_the_screen_sends_nothing_over_the_network(): void
    {
        $this->nextcloudInstance();

        Livewire::test(self::COMPONENT)->html();

        Http::assertNothingSent();
    }

    /** Les DEUX pages de connexion portent la même double garde. */
    #[Test]
    public function the_two_connection_pages_are_guarded_at_mount_too(): void
    {
        $this->actingAs(User::query()->create(['login' => 'eleve2', 'role' => 'eleve', 'is_active' => true]));

        Livewire::test('pages::admin.settings.files._partials.nextcloud-connection')->assertStatus(403);
        Livewire::test('pages::admin.settings.files._partials.opencloud-connection')->assertStatus(403);
    }

    #[Test]
    public function a_non_admin_is_refused_at_mount(): void
    {
        $this->actingAs(User::query()->create(['login' => 'eleve', 'role' => 'eleve', 'is_active' => true]));

        Livewire::test(self::COMPONENT)->assertStatus(403);
    }

    // =====================================================================
    // Story 63.5 — LA DÉSIGNATION DU CLIENT, ET LA POSITION QUI N'EST PAS
    // PROPOSÉE TANT QU'ELLE N'EST PAS TENABLE
    // =====================================================================

    #[Test]
    public function without_a_designation_the_client_position_is_absent_with_its_reason(): void
    {
        $this->nextcloudInstance();

        $html = Livewire::test(self::COMPONENT)->html();
        $texte = self::readable($html);

        // ABSENTE de la liste — jamais grisée, jamais proposée puis refusée.
        self::assertStringNotContainsString('value="client_natif"', $html);
        self::assertStringContainsString('Par le navigateur', $texte);

        // Et le motif est DIT, à côté de la liste.
        self::assertStringContainsString('sync-client-refusal', $html);
        self::assertStringContainsString(
            sprintf(CloudSyncClient::REFUSAL_NO_DESIGNATION, 'Nextcloud'),
            $texte,
        );
    }

    #[Test]
    public function a_designated_client_makes_the_position_appear_without_any_reason(): void
    {
        $this->nextcloudInstance();
        $this->designatedClient();

        $html = Livewire::test(self::COMPONENT)->html();

        self::assertStringContainsString('value="client_natif"', $html);
        self::assertStringNotContainsString('sync-client-refusal', $html);
    }

    #[Test]
    public function an_application_that_is_not_installed_keeps_the_position_absent(): void
    {
        $this->nextcloudInstance();
        $this->designatedClient(status: ApplicationStatus::Available);

        $html = Livewire::test(self::COMPONENT)->html();

        self::assertStringNotContainsString('value="client_natif"', $html);
        self::assertStringContainsString(
            sprintf(CloudSyncClient::REFUSAL_NOT_INSTALLED, 'nextcloud-client'),
            self::readable($html),
        );
    }

    #[Test]
    public function a_recipe_without_a_removal_keeps_the_position_absent(): void
    {
        $this->nextcloudInstance();
        $this->designatedClient(xml: '<package id="nextcloud-client"><install cmd="s.exe" /></package>');

        $html = Livewire::test(self::COMPONENT)->html();

        self::assertStringNotContainsString('value="client_natif"', $html);
        self::assertStringContainsString(
            sprintf(CloudSyncClient::REFUSAL_NO_REMOVE, 'Nextcloud Desktop'),
            self::readable($html),
        );
    }

    #[Test]
    public function an_unreadable_recipe_keeps_the_position_absent_with_its_own_reason(): void
    {
        $this->nextcloudInstance();
        $this->designatedClient(xml: 'pas du XML <<< &&&');

        self::assertStringContainsString(
            sprintf(CloudSyncClient::REFUSAL_UNREADABLE_RECIPE, 'Nextcloud Desktop'),
            self::readable(Livewire::test(self::COMPONENT)->html()),
        );
    }

    #[Test]
    public function only_designatable_applications_are_offered_in_the_picker(): void
    {
        $this->nextcloudInstance();

        // Trois candidates : une valable, une non installée, une sans <remove>.
        Application::create([
            'app_id' => 'bon-client', 'name' => 'Bon Client', 'status' => ApplicationStatus::Installed,
            'xml' => '<package id="bon-client"><remove cmd="u.exe" /></package>',
        ]);
        Application::create([
            'app_id' => 'pas-installe', 'name' => 'Pas Installe', 'status' => ApplicationStatus::Available,
            'xml' => '<package id="pas-installe"><remove cmd="u.exe" /></package>',
        ]);
        Application::create([
            'app_id' => 'sans-remove', 'name' => 'Sans Remove', 'status' => ApplicationStatus::Installed,
            'xml' => '<package id="sans-remove"><install cmd="s.exe" /></package>',
        ]);

        $html = Livewire::test(self::COMPONENT)->html();

        self::assertStringContainsString('value="bon-client"', $html);
        self::assertStringNotContainsString('value="pas-installe"', $html);
        self::assertStringNotContainsString('value="sans-remove"', $html);
    }

    #[Test]
    public function designating_an_application_persists_it_for_the_active_product_only(): void
    {
        $this->nextcloudInstance();
        Application::create([
            'app_id' => 'bon-client', 'name' => 'Bon Client', 'status' => ApplicationStatus::Installed,
            'xml' => '<package id="bon-client"><remove cmd="u.exe" /></package>',
        ]);

        Livewire::test(self::COMPONENT)->set('clientAppId', 'bon-client');

        self::assertSame('bon-client', FilePolicyService::globalConfig()['nextcloud_client_app_id']);
        // L'autre produit n'a rien reçu : la désignation est PAR PRODUIT.
        self::assertNull(FilePolicyService::globalConfig()['opencloud_client_app_id']);
    }

    #[Test]
    public function a_forged_designation_is_refused_and_nothing_is_written(): void
    {
        $this->nextcloudInstance();
        Application::create([
            'app_id' => 'sans-remove', 'name' => 'Sans Remove', 'status' => ApplicationStatus::Installed,
            'xml' => '<package id="sans-remove"><install cmd="s.exe" /></package>',
        ]);

        Livewire::test(self::COMPONENT)
            ->set('clientAppId', 'sans-remove')
            ->assertSet('clientAppId', '');

        // Refusé ⇒ RIEN n'est persisté.
        self::assertNull(FilePolicyService::globalConfig()['nextcloud_client_app_id']);
    }

    /**
     * LA GARDE EST REJOUÉE CÔTÉ SERVICE. L'écran ne propose pas la position ;
     * une propriété Livewire se forge tout de même.
     */
    #[Test]
    public function a_forged_client_position_is_refused_and_writes_nothing(): void
    {
        $this->nextcloudInstance();

        Livewire::test(self::COMPONENT)
            ->set('cloudAccessPath', 'client_natif')
            ->assertSet('cloudAccessPath', 'web');

        self::assertSame('web', FilePolicyService::globalConfig()['cloud_access_path']);
    }

    #[Test]
    public function removing_the_designation_brings_the_access_path_back_to_the_browser(): void
    {
        $this->nextcloudInstance();
        $this->designatedClient();
        FilePolicyService::patchGlobal(['cloud_access_path' => 'client_natif']);

        Livewire::test(self::COMPONENT)->set('clientAppId', '');

        self::assertNull(FilePolicyService::globalConfig()['nextcloud_client_app_id']);
        self::assertSame('web', FilePolicyService::globalConfig()['cloud_access_path']);
    }

    // =====================================================================
    // AC6 — l'avertissement de version d'agent
    // =====================================================================

    #[Test]
    public function a_park_at_the_bound_raises_no_version_warning(): void
    {
        $this->nextcloudInstance();
        $this->designatedClient();
        $this->reportedAgent('PCOK', '2.16.0');

        self::assertStringNotContainsString(
            'agent-version-warning',
            Livewire::test(self::COMPONENT)->html(),
        );
    }

    #[Test]
    public function a_park_below_the_bound_is_warned_but_never_forbidden(): void
    {
        $this->nextcloudInstance();
        $this->designatedClient();
        $this->reportedAgent('PCOLD', '2.2.16');

        $html = Livewire::test(self::COMPONENT)->html();

        self::assertStringContainsString('agent-version-warning', $html);
        self::assertStringContainsString(
            sprintf(CloudSyncClient::WARNING_BELOW_MIN_VERSION, 1, CloudSyncClient::MIN_AGENT_VERSION),
            self::readable($html),
        );
        // Il INFORME : la position reste proposée.
        self::assertStringContainsString('value="client_natif"', $html);
    }

    #[Test]
    public function workstations_that_never_reported_are_counted_apart_on_screen(): void
    {
        $this->nextcloudInstance();
        $this->designatedClient();
        $this->reportedAgent('PCMUET', null);

        self::assertStringContainsString(
            sprintf(CloudSyncClient::NOTICE_UNKNOWN_VERSION, 1),
            self::readable(Livewire::test(self::COMPONENT)->html()),
        );
    }

    #[Test]
    public function the_designation_block_sends_nothing_over_the_network(): void
    {
        $this->nextcloudInstance();
        $this->designatedClient();
        $this->reportedAgent('PCOLD', '2.0.0');

        Livewire::test(self::COMPONENT)->html();

        Http::assertNothingSent();
    }

    /**
     * Story 63.5 — UNE POSITION PERSISTÉE QUI N'EST PLUS TENABLE EST DITE, pas
     * corrigée en douce.
     *
     * Le cas est réel : la story 63.3 enregistrait `client_natif` SANS aucune
     * garde (la position n'avait alors aucun effet), et un changement de cloud
     * suffit à le reproduire.
     */
    #[Test]
    public function a_persisted_client_position_that_no_longer_holds_is_named_on_screen(): void
    {
        $this->nextcloudInstance();
        // Payload « à la 63.3 » : la position est persistée, aucune application
        // n'est désignée.
        FilePolicyService::patchGlobal(['cloud_access_path' => 'client_natif']);

        $html = Livewire::test(self::COMPONENT)->html();

        self::assertStringContainsString('client-position-stale', $html);
        self::assertStringContainsString(
            'La position enregistrée est « Par le client de synchronisation », mais elle n\'est plus '
            .'tenable : rien n\'est posé sur les postes tant qu\'elle ne l\'est pas.',
            self::readable($html),
        );

        // ⚠️ ET RIEN N'EST RÉÉCRIT : le simple rendu de l'écran ne corrige aucune
        // clé, et l'enregistrement des emplacements non plus.
        self::assertSame('client_natif', FilePolicyService::globalConfig()['cloud_access_path']);
    }

    #[Test]
    public function saving_the_locations_never_touches_the_access_path(): void
    {
        $this->nextcloudInstance();
        FilePolicyService::patchGlobal(['cloud_access_path' => 'client_natif']);

        Livewire::test(self::COMPONENT)->call('save');

        // L'invariant de l'epic : aucun réglage persisté n'est perdu. Le geste
        // d'enregistrement ne gouverne pas le chemin d'accès.
        self::assertSame('client_natif', FilePolicyService::globalConfig()['cloud_access_path']);
    }

    // =====================================================================
    // Story 63.5 (correction de revue) — LE BROUILLON DU BLOC 1 N'EST PAS LE
    // CLOUD ACTIF, et RIEN de ce que le bloc 3 écrit ne s'ancre dessus
    // =====================================================================

    /**
     * LE TROU DE TEST QUE CETTE CORRECTION COMBLE : aucune suite ne faisait
     * DIVERGER la radio de l'écran de la ligne `files.locations` persistée —
     * toutes enregistraient d'abord. C'est exactement dans cette fenêtre que
     * vivait le défaut : les blocs 1 et 2 s'enregistrent d'un geste explicite,
     * le bloc 3 s'auto-enregistre.
     */
    #[Test]
    public function the_settings_block_stays_anchored_on_the_persisted_cloud_when_the_radio_diverges(): void
    {
        $this->nextcloudInstance();
        $this->designatedClient();

        $component = Livewire::test(self::COMPONENT)->assertSet('clientAppId', 'nextcloud-client');

        $html = $component->set('cloudActif', 'opencloud')->html();

        // La radio a bougé ; la désignation affichée, NON : elle porte le cloud
        // réellement en service.
        $component->assertSet('clientAppId', 'nextcloud-client');

        // Et l'écran le DIT — pas de désactivation muette.
        self::assertStringContainsString('cloud-selection-divergence', $html);
        self::assertStringContainsString(
            'Ces réglages portent sur le cloud actif enregistré (Nextcloud).',
            self::readable($html),
        );

        // La position reste offerte : elle est tenable POUR LE CLOUD ACTIF.
        self::assertStringContainsString('value="client_natif"', $html);
        self::assertStringNotContainsString('sync-client-refusal', $html);
    }

    /**
     * DEUX CLICS LÉGITIMES, SANS RIEN FORGER. Nextcloud est actif et n'a aucune
     * désignation ; OpenCloud, lui, en a une parfaitement valable — mais il
     * n'est pas actif. La garde doit refuser, parce que l'écriture qui en
     * résulterait s'appliquerait, à la compilation, au cloud PERSISTÉ.
     */
    #[Test]
    public function the_client_position_is_guarded_against_the_persisted_cloud_not_the_draft(): void
    {
        $this->nextcloudInstance();
        $this->designatedClient('oc-client', 'OpenCloud Desktop', ActiveCloud::OpenCloud);

        Livewire::test(self::COMPONENT)
            ->set('cloudActif', 'opencloud')
            ->set('cloudAccessPath', 'client_natif')
            ->assertSet('cloudAccessPath', 'web');

        self::assertSame('web', FilePolicyService::globalConfig()['cloud_access_path']);
    }

    /**
     * L'AUTRE MOITIÉ DU DÉFAUT : une désignation saisie pendant que la radio
     * diverge écrivait la clé du produit SEULEMENT ENVISAGÉ.
     */
    #[Test]
    public function designating_from_a_diverging_radio_writes_the_active_products_key(): void
    {
        $this->nextcloudInstance();
        $this->designatedClient();
        Application::create([
            'app_id' => 'autre-client', 'name' => 'Autre Client', 'status' => ApplicationStatus::Installed,
            'xml' => '<package id="autre-client"><remove cmd="u.exe" /></package>',
        ]);

        Livewire::test(self::COMPONENT)
            ->set('cloudActif', 'opencloud')
            ->set('clientAppId', 'autre-client');

        self::assertSame('autre-client', FilePolicyService::globalConfig()['nextcloud_client_app_id']);
        self::assertNull(FilePolicyService::globalConfig()['opencloud_client_app_id']);
    }

    /**
     * ET LE PLUS COÛTEUX DES TROIS : un client valable ne quitte pas l'ensemble
     * cible de tous les postes parce qu'une radio a bougé sans être enregistrée.
     */
    #[Test]
    public function a_valid_client_never_leaves_the_target_set_because_the_radio_moved(): void
    {
        $this->nextcloudInstance();
        $this->designatedClient();
        FilePolicyService::patchGlobal(['cloud_access_path' => 'client_natif']);

        $html = Livewire::test(self::COMPONENT)->set('cloudActif', 'opencloud')->html();

        self::assertStringNotContainsString('client-position-stale', $html);
        self::assertSame('client_natif', FilePolicyService::globalConfig()['cloud_access_path']);
        self::assertSame('nextcloud-client', FilePolicyService::globalConfig()['nextcloud_client_app_id']);
    }

    /**
     * UNE POSITION EN VIGUEUR EST UN FAIT, PAS UNE PROPOSITION. La masquer
     * cassait le seul contrôle qui permettait d'en sortir : le sélecteur
     * affichait déjà « Par le navigateur », si bien qu'un clic dessus
     * n'émettait aucun changement et n'écrivait RIEN.
     */
    #[Test]
    public function a_position_in_force_stays_in_the_list_and_can_actually_be_left(): void
    {
        $this->nextcloudInstance();
        FilePolicyService::patchGlobal(['cloud_access_path' => 'client_natif']);

        $component = Livewire::test(self::COMPONENT);
        $texte = self::readable($component->html());

        self::assertStringContainsString('value="client_natif"', $component->html());
        self::assertStringContainsString('Par le client de synchronisation — n\'est plus tenable', $texte);

        // Et la sortie que le message instruit s'exécute VRAIMENT.
        $component->set('cloudAccessPath', 'web');

        self::assertSame('web', FilePolicyService::globalConfig()['cloud_access_path']);
    }

    /**
     * AC6 — L'AVERTISSEMENT DE VERSION EST INCONDITIONNEL (correction de revue).
     * Il informe et n'interdit rien : c'est AVANT de s'engager sur une
     * désignation qu'il est le plus utile.
     */
    #[Test]
    public function the_version_warning_shows_even_when_the_position_is_not_tenable(): void
    {
        $this->nextcloudInstance();
        $this->reportedAgent('PCOLD', '2.2.16');

        $html = Livewire::test(self::COMPONENT)->html();

        self::assertStringContainsString('sync-client-refusal', $html);
        self::assertStringContainsString('agent-version-warning', $html);
        self::assertStringContainsString(
            sprintf(CloudSyncClient::WARNING_BELOW_MIN_VERSION, 1, CloudSyncClient::MIN_AGENT_VERSION),
            self::readable($html),
        );
    }

    /** Un poste du parc, avec (ou sans) version d'agent rapportée. */
    private function reportedAgent(string $name, ?string $version): Workstation
    {
        $ws = Workstation::create(['name' => $name, 'status' => 'active']);
        $ws->forceFill(['agent_reported_version' => $version])->save();

        return $ws;
    }
}

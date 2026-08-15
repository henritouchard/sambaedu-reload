<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Admin;

use App\Enums\ActiveCloud;
use App\Enums\FileBackendName;
use App\Models\SystemSetting;
use App\Models\User;
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

    #[Test]
    public function the_settings_block_is_absent_without_an_active_cloud(): void
    {
        $html = Livewire::test(self::COMPONENT)->html();

        self::assertStringNotContainsString('bloc-reglages', $html);
        self::assertStringNotContainsString('Chemin d\'accès au cloud', self::readable($html));
    }

    #[Test]
    public function the_settings_block_carries_the_access_path_and_says_it_has_no_effect_yet(): void
    {
        $this->nextcloudInstance();

        $texte = self::readable(Livewire::test(self::COMPONENT)->html());

        self::assertStringContainsString('Par le navigateur', $texte);
        self::assertStringContainsString('Par le client de synchronisation', $texte);
        self::assertStringContainsString(
            'La pose du client de synchronisation sur les postes est livrée par un chantier séparé. '
            .'D\'ici là, cette position est enregistrée mais seul l\'accès par le navigateur est '
            .'effectivement posé.',
            $texte,
        );
    }

    #[Test]
    public function the_access_path_persists_on_its_own(): void
    {
        $this->nextcloudInstance();

        Livewire::test(self::COMPONENT)->set('cloudAccessPath', 'client_natif');

        self::assertSame('client_natif', FilePolicyService::globalConfig()['cloud_access_path']);
    }

    /** Leur contenu appartient à la 63.4 : une carte vide serait de l'UI orpheline. */
    #[Test]
    public function no_quota_or_trash_card_is_rendered(): void
    {
        $this->nextcloudInstance();

        $texte = self::readable(Livewire::test(self::COMPONENT)->html());

        self::assertStringNotContainsString('Quota', $texte);
        self::assertStringNotContainsString('Corbeille', $texte);
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
}

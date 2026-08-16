<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Agent;

use App\Enums\ActiveCloud;
use App\Enums\ApplicationStatus;
use App\Models\Application;
use App\Models\Workstation;
use App\Services\Agent\CloudSyncClient;
use App\Services\FilePolicyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 63.5 — LA POSABILITÉ DU CLIENT DE SYNCHRONISATION.
 *
 * Le service ne pose rien et ne retire rien : il répond à « cette position
 * est-elle tenable, et quel `app_id` doit entrer dans l'ensemble cible ? ».
 * Toute la matrice des refus est épinglée sur ses LITTÉRAUX — un motif qu'on
 * reformule est un motif qu'un écran n'affiche plus.
 */
class CloudSyncClientTest extends TestCase
{
    use RefreshDatabase;

    private CloudSyncClient $client;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        $this->client = new CloudSyncClient();
    }

    /** Une recette WPKG minimale qui DÉCRIT une désinstallation. */
    private static function recipeWithRemove(string $appId): string
    {
        return '<package id="'.$appId.'" name="Client" revision="1">'
            .'<check type="uninstall" condition="exists" path="Client" />'
            .'<install cmd="setup.exe /S" />'
            .'<remove cmd="uninstall.exe /S" />'
            .'</package>';
    }

    /** Une recette qui installe mais ne décrit AUCUNE désinstallation. */
    private static function recipeWithoutRemove(string $appId): string
    {
        return '<package id="'.$appId.'" name="Client"><install cmd="setup.exe /S" /></package>';
    }

    private function catalogApp(
        string $appId = 'nextcloud-client',
        ApplicationStatus $status = ApplicationStatus::Installed,
        string $name = 'Nextcloud Desktop',
    ): Application {
        return $this->catalogAppWithRecipe($appId, self::recipeWithRemove($appId), $status, $name);
    }

    /** La même, mais avec une recette EXPLICITE — `null` compris. */
    private function catalogAppWithRecipe(
        string $appId,
        ?string $xml,
        ApplicationStatus $status = ApplicationStatus::Installed,
        string $name = 'Nextcloud Desktop',
    ): Application {
        return Application::create([
            'app_id' => $appId,
            'name' => $name,
            'status' => $status,
            'xml' => $xml,
        ]);
    }

    /** Un poste du parc, avec (ou sans) version d'agent rapportée. */
    private function workstation(string $name, ?string $reportedVersion = null): Workstation
    {
        $ws = Workstation::create(['name' => $name, 'status' => 'active']);

        // `agent_reported_version` n'est PAS assignable en masse (elle n'est
        // écrite que par l'ingestion de rapport) : on la force, comme le fait le
        // contrôleur.
        $ws->forceFill(['agent_reported_version' => $reportedVersion])->save();

        return $ws;
    }

    private function designate(ActiveCloud $cloud, string $appId): void
    {
        FilePolicyService::patchGlobal([
            $this->client->policyKeyFor($cloud) => $appId,
        ]);
    }

    private function accessByClient(): void
    {
        FilePolicyService::patchGlobal(['cloud_access_path' => 'client_natif']);
    }

    // =====================================================================
    // AC1 — les deux clés de désignation
    // =====================================================================

    #[Test]
    public function the_two_designation_keys_default_to_null(): void
    {
        self::assertNull(FilePolicyService::defaults()['nextcloud_client_app_id']);
        self::assertNull(FilePolicyService::defaults()['opencloud_client_app_id']);
    }

    #[Test]
    public function the_designation_is_read_per_product_never_shared(): void
    {
        $this->designate(ActiveCloud::Nextcloud, 'nc-client');

        self::assertSame('nc-client', $this->client->designatedAppId(ActiveCloud::Nextcloud));
        // Basculer de produit ne fait PAS hériter du paquet de l'autre : c'est
        // exactement ce qu'une clé unique « du cloud actif » aurait produit.
        self::assertNull($this->client->designatedAppId(ActiveCloud::OpenCloud));
        self::assertNull($this->client->designatedAppId(ActiveCloud::Aucun));
    }

    #[Test]
    public function an_empty_designation_reads_back_as_absent(): void
    {
        $this->designate(ActiveCloud::Nextcloud, '   ');

        self::assertNull($this->client->designatedAppId(ActiveCloud::Nextcloud));
        self::assertNull(FilePolicyService::globalConfig()['nextcloud_client_app_id']);
    }

    #[Test]
    public function a_call_that_does_not_name_the_designations_erases_nothing(): void
    {
        $this->designate(ActiveCloud::Nextcloud, 'nc-client');
        $this->designate(ActiveCloud::OpenCloud, 'oc-client');

        // Un appelant antérieur à cette story : il ne connaît pas les deux
        // derniers paramètres, et ne doit rien effacer.
        FilePolicyService::setGlobal(true, true, true, 'https://cloud.etab.fr');

        self::assertSame('nc-client', FilePolicyService::globalConfig()['nextcloud_client_app_id']);
        self::assertSame('oc-client', FilePolicyService::globalConfig()['opencloud_client_app_id']);
    }

    #[Test]
    public function an_explicit_empty_string_clears_a_designation(): void
    {
        $this->designate(ActiveCloud::Nextcloud, 'nc-client');

        // `null` conserve, chaîne vide EFFACE — sans quoi retirer une
        // désignation serait impossible.
        FilePolicyService::patchGlobal(['nextcloud_client_app_id' => '']);

        self::assertNull(FilePolicyService::globalConfig()['nextcloud_client_app_id']);
    }

    // =====================================================================
    // AC2 — la matrice des refus, littéraux figés
    // =====================================================================

    #[Test]
    public function without_an_active_cloud_the_position_is_refused(): void
    {
        self::assertSame(
            CloudSyncClient::REFUSAL_NO_ACTIVE_CLOUD,
            $this->client->refusalFor(ActiveCloud::Aucun),
        );
        self::assertFalse($this->client->isAvailable(ActiveCloud::Aucun));
    }

    #[Test]
    public function without_a_designation_the_position_is_refused_and_names_the_product(): void
    {
        self::assertSame(
            sprintf(CloudSyncClient::REFUSAL_NO_DESIGNATION, 'Nextcloud'),
            $this->client->refusalFor(ActiveCloud::Nextcloud),
        );
        self::assertSame(
            sprintf(CloudSyncClient::REFUSAL_NO_DESIGNATION, 'OpenCloud'),
            $this->client->refusalFor(ActiveCloud::OpenCloud),
        );
    }

    #[Test]
    public function an_unknown_app_id_is_refused(): void
    {
        $this->designate(ActiveCloud::Nextcloud, 'jamais-vu');

        self::assertSame(
            sprintf(CloudSyncClient::REFUSAL_NOT_INSTALLED, 'jamais-vu'),
            $this->client->refusalFor(ActiveCloud::Nextcloud),
        );
    }

    #[Test]
    public function an_application_that_is_not_installed_is_refused(): void
    {
        // `Available` : matérialisée par un ordre amont, jamais installée sur le
        // serveur — pas de recette exploitable, WPKG échouerait sur le poste.
        $this->catalogApp('nc-client', ApplicationStatus::Available);
        $this->designate(ActiveCloud::Nextcloud, 'nc-client');

        self::assertSame(
            sprintf(CloudSyncClient::REFUSAL_NOT_INSTALLED, 'nc-client'),
            $this->client->refusalFor(ActiveCloud::Nextcloud),
        );
    }

    #[Test]
    public function a_designated_and_installed_application_with_a_remove_is_accepted(): void
    {
        $this->catalogApp('nc-client');
        $this->designate(ActiveCloud::Nextcloud, 'nc-client');

        self::assertNull($this->client->refusalFor(ActiveCloud::Nextcloud));
        self::assertTrue($this->client->isAvailable(ActiveCloud::Nextcloud));
    }

    #[Test]
    public function assert_available_throws_the_refusal_verbatim(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(CloudSyncClient::REFUSAL_NO_ACTIVE_CLOUD);

        $this->client->assertAvailable(ActiveCloud::Aucun);
    }

    #[Test]
    public function assert_available_stays_silent_when_the_position_holds(): void
    {
        $this->catalogApp('nc-client');
        $this->designate(ActiveCloud::Nextcloud, 'nc-client');

        $this->client->assertAvailable(ActiveCloud::Nextcloud);

        self::assertTrue(true, 'aucune exception : la position est tenable');
    }

    // =====================================================================
    // AC3 — la validation PRÉDICTIVE du `<remove>`
    // =====================================================================

    #[Test]
    public function a_recipe_without_any_remove_is_refused(): void
    {
        $this->catalogAppWithRecipe('nc-client', self::recipeWithoutRemove('nc-client'));
        $this->designate(ActiveCloud::Nextcloud, 'nc-client');

        self::assertSame(
            sprintf(CloudSyncClient::REFUSAL_NO_REMOVE, 'Nextcloud Desktop'),
            $this->client->refusalFor(ActiveCloud::Nextcloud),
        );
    }

    #[Test]
    public function a_remove_carried_by_another_package_id_does_not_count(): void
    {
        // Le document décrit DEUX paquets ; seul l'autre porte la désinstallation.
        $xml = '<packages>'
            .self::recipeWithoutRemove('nc-client')
            .self::recipeWithRemove('un-autre')
            .'</packages>';

        $this->catalogAppWithRecipe('nc-client', $xml);
        $this->designate(ActiveCloud::Nextcloud, 'nc-client');

        self::assertSame(
            sprintf(CloudSyncClient::REFUSAL_NO_REMOVE, 'Nextcloud Desktop'),
            $this->client->refusalFor(ActiveCloud::Nextcloud),
        );
    }

    #[Test]
    public function a_recipe_that_describes_the_removal_of_its_own_package_is_accepted(): void
    {
        $xml = '<packages>'
            .self::recipeWithRemove('nc-client')
            .self::recipeWithoutRemove('un-autre')
            .'</packages>';

        $this->catalogAppWithRecipe('nc-client', $xml);
        $this->designate(ActiveCloud::Nextcloud, 'nc-client');

        self::assertNull($this->client->refusalFor(ActiveCloud::Nextcloud));
    }

    #[Test]
    public function an_empty_or_null_recipe_is_refused_with_its_own_reason(): void
    {
        foreach ([null, '', '   '] as $xml) {
            Application::query()->delete();
            $this->catalogAppWithRecipe('nc-client', $xml);
            $this->designate(ActiveCloud::Nextcloud, 'nc-client');

            self::assertSame(
                sprintf(CloudSyncClient::REFUSAL_UNREADABLE_RECIPE, 'Nextcloud Desktop'),
                $this->client->refusalFor(ActiveCloud::Nextcloud),
                'un `xml` vide ne prouve pas la présence d\'un <remove>',
            );
        }
    }

    #[Test]
    public function a_non_xml_recipe_is_refused_with_its_own_reason(): void
    {
        $this->catalogAppWithRecipe('nc-client', 'ceci n\'est pas du XML <<< &&& >');
        $this->designate(ActiveCloud::Nextcloud, 'nc-client');

        self::assertSame(
            sprintf(CloudSyncClient::REFUSAL_UNREADABLE_RECIPE, 'Nextcloud Desktop'),
            $this->client->refusalFor(ActiveCloud::Nextcloud),
        );
    }

    #[Test]
    public function reading_a_recipe_never_touches_the_network(): void
    {
        $this->catalogApp('nc-client');
        $this->designate(ActiveCloud::Nextcloud, 'nc-client');

        $this->client->refusalFor(ActiveCloud::Nextcloud);

        // La lecture se fait sur la colonne persistée, jamais par une relecture
        // du dépôt amont.
        Http::assertNothingSent();
    }

    // =====================================================================
    // AC4 — `appIdFor()` : les trois clouds × les deux chemins d'accès
    // =====================================================================

    #[Test]
    public function nothing_is_designated_for_the_agent_while_the_access_path_is_the_browser(): void
    {
        $this->catalogApp('nc-client');
        $this->designate(ActiveCloud::Nextcloud, 'nc-client');

        // Le DÉFAUT : `web`. Rien n'est unionné, et le golden d'état ne bouge pas.
        self::assertSame('web', FilePolicyService::globalConfig()['cloud_access_path']);
        self::assertNull($this->client->appIdFor(ActiveCloud::Nextcloud));
    }

    #[Test]
    public function the_designated_app_id_is_returned_only_in_client_position(): void
    {
        $this->catalogApp('nc-client');
        $this->designate(ActiveCloud::Nextcloud, 'nc-client');
        $this->accessByClient();

        self::assertSame('nc-client', $this->client->appIdFor(ActiveCloud::Nextcloud));
        // L'autre produit n'a aucune désignation : rien, même en position client.
        self::assertNull($this->client->appIdFor(ActiveCloud::OpenCloud));
        // Aucun cloud actif : court-circuit, aucune requête.
        self::assertNull($this->client->appIdFor(ActiveCloud::Aucun));
    }

    #[Test]
    public function switching_products_designates_the_other_package(): void
    {
        $this->catalogApp('nc-client');
        $this->catalogApp('oc-client', name: 'OpenCloud Desktop');
        $this->designate(ActiveCloud::Nextcloud, 'nc-client');
        $this->designate(ActiveCloud::OpenCloud, 'oc-client');
        $this->accessByClient();

        self::assertSame('nc-client', $this->client->appIdFor(ActiveCloud::Nextcloud));
        self::assertSame('oc-client', $this->client->appIdFor(ActiveCloud::OpenCloud));
    }

    /**
     * LE STRUCTUREL, ET LUI SEUL : une désignation qui ne résout aucune ligne de
     * catalogue ne peut rien unionner — il n'y aurait ni `name` à hydrater ni
     * `sourceId`, et le provider n'émettrait qu'un `Log::warning`.
     */
    #[Test]
    public function a_designation_that_resolves_no_catalog_row_yields_nothing(): void
    {
        $this->accessByClient();

        // Aucune désignation.
        self::assertNull($this->client->appIdFor(ActiveCloud::Nextcloud));

        // Désignation d'une app que le catalogue ne porte pas.
        $this->designate(ActiveCloud::Nextcloud, 'jamais-vu');
        self::assertNull($this->client->appIdFor(ActiveCloud::Nextcloud));
    }

    /**
     * ⚠️ LA GARDE DE SAISIE NE SORT PAS DU CHEMIN DE COMPILATION (correction de
     * revue).
     *
     * Le catalogue bouge sans qu'aucun administrateur ne décide :
     * `AppStoreService::installApplication()` bascule le statut à `Downloading`
     * avant téléchargement et ne le rend à `Installed` qu'à la finalisation, un
     * échec le laisse à `Error`, et la synchro amont réécrit `xml`. Rejouer le
     * contrôle de statut ici retirerait le client de l'ensemble cible pendant
     * plusieurs minutes — donc WPKG le DÉSINSTALLERAIT de tout le parc, pour le
     * réinstaller à la passe suivante. Une garde qui protège un formulaire n'a
     * pas à pouvoir désinstaller un parc.
     */
    #[Test]
    public function an_application_being_reinstalled_stays_in_the_target_set(): void
    {
        $this->catalogApp('nc-client');
        $this->designate(ActiveCloud::Nextcloud, 'nc-client');
        $this->accessByClient();

        foreach ([ApplicationStatus::Downloading, ApplicationStatus::Error, ApplicationStatus::Available] as $status) {
            Application::query()->where('app_id', 'nc-client')->update(['status' => $status->value]);

            self::assertSame(
                'nc-client',
                $this->client->appIdFor(ActiveCloud::Nextcloud),
                'la décision persistée se projette, quel que soit un statut de catalogue transitoire',
            );
        }
    }

    /**
     * Même figure pour la recette : un réimport amont qui la réécrit — ou la
     * casse — ne doit pas désinstaller le client du parc. La garde `<remove>`
     * vit à l'ÉCRITURE, et le refus reste dit à l'écran.
     */
    #[Test]
    public function a_recipe_that_loses_its_removal_does_not_uninstall_the_park(): void
    {
        $this->catalogApp('nc-client');
        $this->designate(ActiveCloud::Nextcloud, 'nc-client');
        $this->accessByClient();

        Application::query()->where('app_id', 'nc-client')->update([
            'xml' => self::recipeWithoutRemove('nc-client'),
        ]);

        self::assertSame('nc-client', $this->client->appIdFor(ActiveCloud::Nextcloud));
        // Mais l'écriture, elle, reste refusée — et nommée.
        self::assertSame(
            sprintf(CloudSyncClient::REFUSAL_NO_REMOVE, 'Nextcloud Desktop'),
            $this->client->refusalFor(ActiveCloud::Nextcloud),
        );
    }

    /**
     * L'`app_id` rendu est celui du CATALOGUE, jamais la chaîne désignée : c'est
     * lui que le `whereIn('app_id', …)` du provider retrouvera. Le dépôt a déjà
     * tranché l'appariement insensible à la casse au même endroit
     * (`ApplicationXmlReader`, `LOWER(app_id)`).
     */
    #[Test]
    public function the_catalog_spelling_wins_over_the_designated_one(): void
    {
        $this->catalogAppWithRecipe('NextCloud-Client', self::recipeWithRemove('nextcloud-client'));
        $this->designate(ActiveCloud::Nextcloud, 'nextcloud-client');
        $this->accessByClient();

        self::assertSame('NextCloud-Client', $this->client->appIdFor(ActiveCloud::Nextcloud));
        // Et la recette, dont l'`id` ne diffère que par la casse, décrit bien la
        // désinstallation de CE paquet.
        self::assertNull($this->client->refusalFor(ActiveCloud::Nextcloud));
    }

    // =====================================================================
    // AC6 — la borne de version, NOMMÉE
    // =====================================================================

    #[Test]
    public function the_minimum_agent_version_is_a_documented_public_constant(): void
    {
        self::assertSame('2.2.17', CloudSyncClient::MIN_AGENT_VERSION);
    }

    #[Test]
    public function a_park_entirely_at_or_above_the_bound_raises_no_warning(): void
    {
        $this->workstation('PC1', '2.2.17');
        $this->workstation('PC2', '2.16.0');

        self::assertSame(['below' => 0, 'unknown' => 0], $this->client->agentVersionCensus());
        self::assertNull($this->client->agentVersionWarning());
    }

    #[Test]
    public function workstations_below_the_bound_are_counted_and_named(): void
    {
        $this->workstation('PCOLD1', '2.2.16');
        $this->workstation('PCOLD2', '1.9.0');
        $this->workstation('PCOK', '2.3.0');

        self::assertSame(['below' => 2, 'unknown' => 0], $this->client->agentVersionCensus());
        self::assertSame(
            sprintf(CloudSyncClient::WARNING_BELOW_MIN_VERSION, 2, '2.2.17'),
            $this->client->agentVersionWarning(),
        );
    }

    #[Test]
    public function a_workstation_that_never_reported_is_counted_apart_never_as_below(): void
    {
        $this->workstation('PCMUET');
        $this->workstation('PCOK', '2.16.0');

        self::assertSame(['below' => 0, 'unknown' => 1], $this->client->agentVersionCensus());
        self::assertSame(
            sprintf(CloudSyncClient::NOTICE_UNKNOWN_VERSION, 1),
            $this->client->agentVersionWarning(),
        );
    }

    #[Test]
    public function a_mixed_park_names_both_counts(): void
    {
        $this->workstation('PCOLD', '2.0.0');
        $this->workstation('PCMUET');
        $this->workstation('PCOK', 'v2.2.17');

        // Le préfixe `v` d'un tag de release n'est pas une version : `v2.2.17`
        // est À la borne, pas en dessous.
        self::assertSame(['below' => 1, 'unknown' => 1], $this->client->agentVersionCensus());

        $warning = $this->client->agentVersionWarning();
        self::assertStringContainsString(sprintf(CloudSyncClient::WARNING_BELOW_MIN_VERSION, 1, '2.2.17'), (string) $warning);
        self::assertStringContainsString(sprintf(CloudSyncClient::NOTICE_UNKNOWN_VERSION, 1), (string) $warning);
    }

    #[Test]
    public function the_version_census_never_touches_the_network(): void
    {
        $this->workstation('PC1', '2.0.0');

        $this->client->agentVersionCensus();

        Http::assertNothingSent();
    }
}

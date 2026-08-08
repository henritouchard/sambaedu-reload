<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Admin;

use App\Enums\NextcloudInstanceMode;
use App\Jobs\ProvisionNextcloudJob;
use App\Models\User;
use App\Services\FilePolicyService;
use App\Services\Nextcloud\NextcloudConnectionConfig;
use App\Services\Nextcloud\NextcloudDelegateConfig;
use App\Services\Nextcloud\NextcloudProvisioningService;
use App\Services\ServiceCredentials;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Js;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 61.2 — AC2 / AC6 / AC10 : l'écran de déclaration du mode.
 *
 * Deux tests pivots :
 *  - {@see self::a_mode_the_account_cannot_honour_is_refused_and_nothing_is_persisted()} :
 *    fail-closed — une position refusée n'est pas enregistrée du tout ;
 *  - {@see self::saving_an_unrelated_setting_never_talks_to_the_instance()} : la
 *    sonde-garde ne s'exécute QUE sur le mode, sans quoi une instance en panne
 *    verrouillerait l'édition de réglages qui ne la concernent pas.
 */
class FilePolicyNextcloudModeTest extends TestCase
{
    use RefreshDatabase;

    private const COMPONENT = 'pages::admin.settings.files._partials.personnels-partages-tab';

    private const ADMIN_SECRET = 'AppPasswordAdminTresSecret';

    private const DELEGATE_SECRET = 'AppPasswordPorteurTresSecret';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        $admin = User::query()->create(['login' => 'files-admin', 'role' => 'prof', 'is_active' => true]);
        $this->actingAs($admin);
        Gate::before(fn ($user, string $ability) => $ability === 'server.admin' ? true : null);
    }

    private function ready(NextcloudInstanceMode $mode = NextcloudInstanceMode::Admin, string $delegateUser = 'se5porteur'): void
    {
        $credentials = app(ServiceCredentials::class);
        $credentials->put(NextcloudConnectionConfig::CREDENTIAL_NAME, self::ADMIN_SECRET);
        $credentials->put(NextcloudDelegateConfig::CREDENTIAL_NAME, self::DELEGATE_SECRET);

        FilePolicyService::setGlobal(
            true, true, true, 'https://cloud.etab.fr', 'admin', 'se4fs', true,
            $mode, $delegateUser,
        );
    }

    /** @param array<string, mixed> $data */
    private static function ocs(int $code, array $data = []): array
    {
        return ['ocs' => ['meta' => ['status' => 'ok', 'statuscode' => $code, 'message' => 'OK'], 'data' => $data]];
    }

    private static function capabilities(bool $sharingEnabled): array
    {
        return self::ocs(100, [
            'version' => ['string' => '34.0.2'],
            'capabilities' => ['files_sharing' => ['api_enabled' => $sharingEnabled]],
        ]);
    }

    /** Doubles d'une instance déléguée SAINE (codes mesurés : 207 puis 200). */
    private static function healthyDelegate(): array
    {
        return [
            '*/remote.php/dav/files/se5porteur/' => Http::response('<d:multistatus/>', 207),
            '*/ocs/v1.php/cloud/capabilities*' => Http::response(self::capabilities(true), 200),
        ];
    }

    /** Doubles d'une instance ADMINISTRÉE saine (sonde 61.1 : capacités puis montages globaux). */
    private static function healthyAdmin(): array
    {
        return [
            '*/ocs/v2.php/cloud/capabilities*' => Http::response(self::ocs(100), 200),
            '*/globalstorages*' => Http::response([], 200),
        ];
    }

    /** Doubles d'une instance qui REFUSE le privilège d'administration. */
    private static function nonAdmin(): array
    {
        return [
            '*/ocs/v2.php/cloud/capabilities*' => Http::response(self::ocs(100), 200),
            '*/globalstorages*' => Http::response('nope', 403),
        ];
    }

    // =====================================================================
    // AC2 — la sélection fail-closed
    // =====================================================================

    #[Test]
    public function a_sound_delegated_account_is_accepted_and_persisted(): void
    {
        $this->ready();
        Http::fake(self::healthyDelegate());

        Livewire::test(self::COMPONENT)
            ->set('nextcloudMode', NextcloudInstanceMode::Delegue->value)
            ->assertSet('nextcloudMode', 'delegue');

        self::assertSame(NextcloudInstanceMode::Delegue, FilePolicyService::nextcloudMode());
    }

    #[Test]
    public function a_mode_the_account_cannot_honour_is_refused_and_nothing_is_persisted(): void
    {
        $this->ready();

        // Identifiants porteurs refusés par l'instance.
        Http::fake(['*/remote.php/dav/files/se5porteur/' => Http::response('', 401)]);

        $component = Livewire::test(self::COMPONENT)
            ->set('nextcloudMode', NextcloudInstanceMode::Delegue->value);

        // Le mode courant reste en vigueur, à l'écran comme en base.
        $component->assertSet('nextcloudMode', 'admin');
        self::assertSame(NextcloudInstanceMode::Admin, FilePolicyService::nextcloudMode());

        // …et le motif EXACT est affiché, sans le secret.
        $message = (string) $component->get('probeResult')['message'];
        self::assertStringContainsString('compte porteur', $message);
        self::assertStringNotContainsString(self::DELEGATE_SECRET, $message);
        self::assertStringNotContainsString(self::ADMIN_SECRET, $component->html());
    }

    /** Choisir « délégué » sans identifiants porteurs : refusé en NOMMANT le manque. */
    #[Test]
    public function selecting_the_delegated_mode_without_credentials_names_what_is_missing(): void
    {
        app(ServiceCredentials::class)->put(NextcloudConnectionConfig::CREDENTIAL_NAME, self::ADMIN_SECRET);
        FilePolicyService::setGlobal(true, true, true, 'https://cloud.etab.fr', 'admin', 'se4fs', true);
        Http::fake();

        $component = Livewire::test(self::COMPONENT)
            ->set('nextcloudMode', NextcloudInstanceMode::Delegue->value);

        $component->assertSet('nextcloudMode', 'admin');
        self::assertStringContainsString(
            'l\'identifiant du compte porteur',
            (string) $component->get('probeResult')['message'],
        );

        // Aucun appel : une configuration incomplète se refuse sans réseau.
        Http::assertNothingSent();
    }

    /** Repasser en « administré » avec un compte NON admin est refusé en nommant le privilège. */
    #[Test]
    public function selecting_the_administered_mode_with_a_non_admin_account_is_refused(): void
    {
        $this->ready(NextcloudInstanceMode::Delegue);

        Http::fake([
            '*/ocs/v2.php/cloud/capabilities*' => Http::response(self::ocs(100), 200),
            '*/globalstorages*' => Http::response('nope', 403),
        ]);

        $component = Livewire::test(self::COMPONENT)
            ->set('nextcloudMode', NextcloudInstanceMode::Admin->value);

        $component->assertSet('nextcloudMode', 'delegue');
        self::assertSame(NextcloudInstanceMode::Delegue, FilePolicyService::nextcloudMode());
        self::assertStringContainsString('administrateur', (string) $component->get('probeResult')['message']);
    }

    /**
     * **Une instance injoignable refuse aussi la sélection** : on ne déclare pas
     * une position qu'on n'a pas pu vérifier (l'AC2 range « injoignable » parmi les
     * motifs de refus).
     */
    #[Test]
    public function an_unreachable_instance_refuses_the_mode_change(): void
    {
        $this->ready();
        Http::fake(['*' => static fn (): never => throw new ConnectionException('cURL error 7')]);

        Livewire::test(self::COMPONENT)
            ->set('nextcloudMode', NextcloudInstanceMode::Delegue->value)
            ->assertSet('nextcloudMode', 'admin');

        self::assertSame(NextcloudInstanceMode::Admin, FilePolicyService::nextcloudMode());
    }

    // =====================================================================
    // AC2 — la sonde-garde ne s'exécute QUE sur le mode
    // =====================================================================

    /**
     * **Retouché par la correction de revue #1** : `nextcloudVerifyTls` a QUITTÉ ce
     * test. Le drapeau TLS n'est pas un réglage orthogonal — il décide de ce qui est
     * joignable, donc il fait partie de ce qui définit la connexion et il déclenche
     * désormais la sonde-garde ({@see self::changing_only_the_tls_flag_re_probes()}).
     * Restent ici les réglages qui ne concernent VRAIMENT pas l'instance : les deux
     * capacités de montage et l'hôte SMB.
     */
    #[Test]
    public function saving_an_unrelated_setting_never_talks_to_the_instance(): void
    {
        $this->ready();
        Http::fake();

        Livewire::test(self::COMPONENT)
            ->set('home', false)
            ->set('shares', false)
            ->set('nextcloudSmbHost', 'autre-serveur');

        Http::assertNothingSent();

        // …et les réglages orthogonaux, eux, sont bien enregistrés.
        $config = FilePolicyService::globalConfig();
        self::assertFalse($config['home']);
        self::assertSame('autre-serveur', $config['nextcloud_smb_host']);
    }

    // =====================================================================
    // CORRECTION DE REVUE #1 — LE CHANGEMENT D'URL (ET DE TLS) RE-DÉCLENCHE
    // LA SONDE-GARDE
    //
    // Le défaut fermé ici : `identityChanged` ne comparait que l'identifiant du
    // compte. Changer la SEULE URL — nouvel hébergeur, ou faute de frappe —
    // traversait la garde sans le moindre appel HTTP, et `setGlobal()` persistait
    // une cible que le compte n'avait JAMAIS été vérifié capable d'honorer dans
    // ce mode. `nextcloudServerUrl` n'apparaissait dans aucune assertion de ce
    // fichier : c'est ce trou de couverture qui a laissé passer le défaut.
    // =====================================================================

    #[Test]
    public function changing_only_the_url_re_probes_the_declared_mode(): void
    {
        $this->ready(NextcloudInstanceMode::Admin);
        Http::fake(self::healthyAdmin());

        Livewire::test(self::COMPONENT)
            ->set('nextcloudServerUrl', 'https://nouvel-hebergeur.fr')
            ->assertSet('nextcloudServerUrl', 'https://nouvel-hebergeur.fr');

        // La sonde a bien été jouée — et sur la NOUVELLE cible.
        Http::assertSent(static fn (\Illuminate\Http\Client\Request $r): bool => str_contains(
            $r->url(),
            'nouvel-hebergeur.fr',
        ));

        self::assertSame('https://nouvel-hebergeur.fr', FilePolicyService::globalConfig()['nextcloud_server_url']);
    }

    #[Test]
    public function a_url_the_declared_mode_cannot_honour_is_refused_and_the_url_is_restored(): void
    {
        $this->ready(NextcloudInstanceMode::Admin);

        // Sur la nouvelle cible, le compte n'est pas administrateur.
        Http::fake(self::nonAdmin());

        $component = Livewire::test(self::COMPONENT)
            ->set('nextcloudServerUrl', 'https://nouvel-hebergeur.fr');

        // Rien n'est persisté…
        self::assertSame('https://cloud.etab.fr', FilePolicyService::globalConfig()['nextcloud_server_url']);
        self::assertSame(NextcloudInstanceMode::Admin, FilePolicyService::nextcloudMode());

        // …et l'écran a RESTAURÉ l'URL : il ne doit pas afficher une configuration
        // que la base ne porte pas.
        $component->assertSet('nextcloudServerUrl', 'https://cloud.etab.fr');
        self::assertStringContainsString('administrateur', (string) $component->get('probeResult')['message']);
    }

    #[Test]
    public function changing_only_the_tls_flag_re_probes(): void
    {
        $this->ready(NextcloudInstanceMode::Admin);
        Http::fake(self::healthyAdmin());

        Livewire::test(self::COMPONENT)->set('nextcloudVerifyTls', false);

        Http::assertSentCount(2);
        self::assertFalse(FilePolicyService::globalConfig()['nextcloud_verify_tls']);
    }

    #[Test]
    public function a_refused_tls_change_is_restored_and_nothing_is_persisted(): void
    {
        $this->ready(NextcloudInstanceMode::Admin);
        Http::fake(self::nonAdmin());

        $component = Livewire::test(self::COMPONENT)->set('nextcloudVerifyTls', false);

        $component->assertSet('nextcloudVerifyTls', true);
        self::assertTrue(FilePolicyService::globalConfig()['nextcloud_verify_tls']);
    }

    /**
     * …mais la garde de SONDABILITÉ tient toujours : une configuration en cours de
     * constitution (aucun secret pour ce mode) se saisit sans être refusée à chaque
     * frappe — sinon on ne pourrait jamais la constituer.
     */
    #[Test]
    public function typing_a_url_on_an_unconfigured_mode_does_not_probe(): void
    {
        FilePolicyService::setGlobal(true, true, true, '', 'admin', 'se4fs', true);
        Http::fake();

        Livewire::test(self::COMPONENT)->set('nextcloudServerUrl', 'https://cloud.etab.fr');

        Http::assertNothingSent();
        self::assertSame('https://cloud.etab.fr', FilePolicyService::globalConfig()['nextcloud_server_url']);
    }

    /** Re-sélectionner le même mode ne rejoue pas la sonde. */
    #[Test]
    public function re_selecting_the_same_mode_probes_nothing(): void
    {
        $this->ready(NextcloudInstanceMode::Delegue);
        Http::fake();

        Livewire::test(self::COMPONENT)
            ->set('nextcloudMode', NextcloudInstanceMode::Delegue->value)
            ->assertSet('nextcloudMode', 'delegue');

        Http::assertNothingSent();
        self::assertSame(NextcloudInstanceMode::Delegue, FilePolicyService::nextcloudMode());
    }

    /**
     * Changer l'identifiant du mode SÉLECTIONNÉ re-vérifie — la position déclarée
     * doit rester honorable.
     */
    #[Test]
    public function changing_the_selected_mode_identifier_re_verifies(): void
    {
        $this->ready(NextcloudInstanceMode::Delegue);

        Http::fake(['*/remote.php/dav/files/autre-porteur/' => Http::response('', 404)]);

        $component = Livewire::test(self::COMPONENT)
            ->set('nextcloudDelegueUser', 'autre-porteur');

        $component->assertSet('nextcloudDelegueUser', 'se5porteur');
        self::assertSame('se5porteur', FilePolicyService::globalConfig()['nextcloud_delegue_user']);
        self::assertStringContainsString(
            'aucun espace de fichiers',
            (string) $component->get('probeResult')['message'],
        );
    }

    /**
     * …mais une configuration EN COURS DE CONSTITUTION (aucun secret enregistré
     * pour ce mode) ne se fait pas refuser à chaque frappe : sans cela, on ne
     * pourrait jamais constituer la configuration.
     */
    #[Test]
    public function typing_an_identifier_on_an_unconfigured_mode_does_not_probe(): void
    {
        FilePolicyService::setGlobal(true, true, true, 'https://cloud.etab.fr');
        Http::fake();

        Livewire::test(self::COMPONENT)->set('nextcloudAdminUser', 'admin');

        Http::assertNothingSent();
        self::assertSame('admin', FilePolicyService::globalConfig()['nextcloud_admin_user']);
    }

    /** Capacité éteinte : aucune instance à qui imposer une position, aucun appel. */
    #[Test]
    public function a_disabled_capability_never_probes_on_a_mode_change(): void
    {
        FilePolicyService::setGlobal(false, false, false, 'https://cloud.etab.fr', 'admin', 'se4fs', true);
        Http::fake();

        Livewire::test(self::COMPONENT)
            ->set('nextcloudMode', NextcloudInstanceMode::Delegue->value)
            ->assertSet('nextcloudMode', 'delegue');

        Http::assertNothingSent();
    }

    // =====================================================================
    // AC4 / AC10 — les champs porteurs
    // =====================================================================

    #[Test]
    public function the_delegate_fields_are_absent_from_the_dom_in_administered_mode(): void
    {
        $this->ready(NextcloudInstanceMode::Admin);

        $html = Livewire::test(self::COMPONENT)->html();

        self::assertStringNotContainsString('nextcloud-delegue-user', $html);
        self::assertStringNotContainsString('nextcloud-delegue-password', $html);
    }

    #[Test]
    public function the_delegate_fields_appear_in_delegated_mode(): void
    {
        $this->ready(NextcloudInstanceMode::Delegue);

        $html = Livewire::test(self::COMPONENT)->html();

        self::assertStringContainsString('nextcloud-delegue-user', $html);
        self::assertStringContainsString('nextcloud-delegue-password', $html);
    }

    #[Test]
    public function the_delegate_secret_is_stored_encrypted_and_never_rendered(): void
    {
        $this->ready(NextcloudInstanceMode::Delegue);

        $component = Livewire::test(self::COMPONENT)
            ->set('nextcloudDeleguePassword', 'NouveauSecretPorteur');

        $component->assertSet('nextcloudDeleguePassword', '');
        $component->assertSet('hasDelegueSecret', true);
        self::assertStringNotContainsString('NouveauSecretPorteur', $component->html());

        self::assertSame(
            'NouveauSecretPorteur',
            app(ServiceCredentials::class)->password(NextcloudDelegateConfig::CREDENTIAL_NAME),
        );

        $stored = (string) \Illuminate\Support\Facades\DB::table('service_credentials')
            ->where('name', NextcloudDelegateConfig::CREDENTIAL_NAME)
            ->value('secret');
        self::assertStringNotContainsString('NouveauSecretPorteur', $stored);
    }

    #[Test]
    public function forgetting_the_delegate_secret_leaves_the_admin_one_intact(): void
    {
        $this->ready(NextcloudInstanceMode::Delegue);

        Livewire::test(self::COMPONENT)
            ->call('forgetDeleguePassword')
            ->assertSet('hasDelegueSecret', false);

        $credentials = app(ServiceCredentials::class);
        self::assertNull($credentials->password(NextcloudDelegateConfig::CREDENTIAL_NAME));
        self::assertSame(self::ADMIN_SECRET, $credentials->password(NextcloudConnectionConfig::CREDENTIAL_NAME));
    }

    // =====================================================================
    // CORRECTION DE REVUE #3 — REMPLACER UN SECRET NE LAISSE PLUS UN MODE
    // DÉCLARÉ « VÉRIFIÉ » QU'IL N'EST PLUS
    //
    // La variante retenue est NON BLOQUANTE : l'enregistrement du secret n'est
    // jamais annulé par le résultat de la sonde (refuser de stocker un app
    // password que l'instance ne confirme pas rendrait une instance
    // momentanément injoignable impossible à reconfigurer — un deadlock réel).
    // Ce qui change, c'est que l'écran ne peut plus laisser croire qu'un mode
    // est vérifié quand il ne l'est pas.
    // =====================================================================

    #[Test]
    public function a_secret_confirmed_by_the_probe_is_stored_and_shown_as_verified(): void
    {
        $this->ready(NextcloudInstanceMode::Delegue);
        Http::fake(self::healthyDelegate());

        $component = Livewire::test(self::COMPONENT)
            ->set('nextcloudDeleguePassword', 'NouveauSecretPorteur');

        $diagnostic = $component->get('probeResult');
        self::assertIsArray($diagnostic);
        self::assertTrue($diagnostic['ok']);
        self::assertArrayNotHasKey('unverified_since_secret_change', $diagnostic);

        self::assertSame(
            'NouveauSecretPorteur',
            app(ServiceCredentials::class)->password(NextcloudDelegateConfig::CREDENTIAL_NAME),
        );
    }

    /**
     * **L'ASSERTION QUI COMPTE** : le secret est enregistré QUAND MÊME. Le
     * deadlock (« instance injoignable ⇒ impossible d'enregistrer le secret qui la
     * rendrait joignable ») est bien évité — et l'écran affiche l'état honnête.
     */
    #[Test]
    public function a_secret_the_instance_rejects_is_still_stored_and_the_mode_is_shown_unverified(): void
    {
        $this->ready(NextcloudInstanceMode::Delegue);
        Http::fake(['*/remote.php/dav/files/se5porteur/' => Http::response('', 401)]);

        $component = Livewire::test(self::COMPONENT)
            ->set('nextcloudDeleguePassword', 'SecretQueLInstanceRefuse');

        // 1. Le secret EST enregistré (pas de deadlock).
        $component->assertSet('hasDelegueSecret', true);
        self::assertSame(
            'SecretQueLInstanceRefuse',
            app(ServiceCredentials::class)->password(NextcloudDelegateConfig::CREDENTIAL_NAME),
        );

        // 2. …et le mode déclaré est dit NON VÉRIFIÉ, avec son motif.
        $diagnostic = $component->get('probeResult');
        self::assertTrue($diagnostic['unverified_since_secret_change']);
        self::assertSame(NextcloudInstanceMode::Delegue->label(), $diagnostic['unverified_mode']);
        self::assertStringContainsString('compte porteur', (string) $diagnostic['message']);

        $html = $component->html();
        self::assertStringContainsString('non vérifié', $html);
        self::assertStringNotContainsString('SecretQueLInstanceRefuse', $html);
    }

    /** …et cet état SURVIT au rechargement de la page : ce n'est pas une propriété volatile. */
    #[Test]
    public function the_unverified_state_survives_a_page_reload(): void
    {
        $this->ready(NextcloudInstanceMode::Delegue);
        Http::fake(['*/remote.php/dav/files/se5porteur/' => Http::response('', 401)]);

        Livewire::test(self::COMPONENT)->set('nextcloudDeleguePassword', 'SecretQueLInstanceRefuse');

        // Un montage tout neuf — c'est ce que fait un F5.
        $reloaded = Livewire::test(self::COMPONENT);

        self::assertTrue($reloaded->get('probeResult')['unverified_since_secret_change']);
        self::assertStringContainsString('non vérifié', $reloaded->html());
    }

    /** Une instance injoignable ne bloque pas non plus : le secret passe, l'état est honnête. */
    #[Test]
    public function an_unreachable_instance_never_blocks_storing_a_secret(): void
    {
        $this->ready(NextcloudInstanceMode::Admin);
        Http::fake(['*' => static fn (): never => throw new ConnectionException('cURL error 7')]);

        $component = Livewire::test(self::COMPONENT)
            ->set('nextcloudAdminPassword', 'SecretQuiNArrivePasAPasser');

        $component->assertSet('hasAdminSecret', true);
        self::assertSame(
            'SecretQuiNArrivePasAPasser',
            app(ServiceCredentials::class)->password(NextcloudConnectionConfig::CREDENTIAL_NAME),
        );
        self::assertTrue($component->get('probeResult')['unverified_since_secret_change']);
    }

    /** Capacité éteinte : aucune sonde, et le diagnostic périmé est effacé. */
    #[Test]
    public function storing_a_secret_with_the_capability_off_probes_nothing(): void
    {
        FilePolicyService::setGlobal(true, true, false, 'https://cloud.etab.fr', 'admin', 'se4fs', true);
        Http::fake();

        $component = Livewire::test(self::COMPONENT)->set('nextcloudAdminPassword', 'UnSecret');

        Http::assertNothingSent();
        $component->assertSet('hasAdminSecret', true);
        $component->assertSet('probeResult', null);
    }

    // =====================================================================
    // AC10 — « Tester la connexion » sonde LE MODE SÉLECTIONNÉ
    // =====================================================================

    #[Test]
    public function testing_the_connection_probes_the_selected_mode(): void
    {
        $this->ready(NextcloudInstanceMode::Delegue);
        Http::fake(self::healthyDelegate());

        $component = Livewire::test(self::COMPONENT)->call('testConnection');

        self::assertTrue($component->get('probeResult')['ok']);
        self::assertSame('delegue', $component->get('probeResult')['mode']);

        // La sonde du mode délégué ne lit PAS les montages globaux : c'est une
        // opération d'administration, et ce mode ne l'a pas.
        Http::assertNotSent(static fn (\Illuminate\Http\Client\Request $r): bool => str_contains(
            $r->url(),
            'globalstorages',
        ));
    }

    // =====================================================================
    // AC5 / AC6 — l'écran DIT ce que le mode coupe et ce qu'il dégrade
    // =====================================================================

    #[Test]
    public function the_provision_button_is_disabled_with_its_reason_in_delegated_mode(): void
    {
        $this->ready(NextcloudInstanceMode::Delegue);
        Queue::fake();

        $html = Livewire::test(self::COMPONENT)->html();

        self::assertStringContainsString('Provisionnement indisponible dans ce mode', $html);
        self::assertStringContainsString('opérations', $html);

        // Le chemin est fermé pour de bon : un attribut `disabled` n'est pas une
        // autorisation.
        Livewire::test(self::COMPONENT)->call('provision');
        Queue::assertNothingPushed();
    }

    #[Test]
    public function the_provision_button_still_queues_in_administered_mode(): void
    {
        $this->ready(NextcloudInstanceMode::Admin);
        Queue::fake();

        Livewire::test(self::COMPONENT)->call('provision');

        Queue::assertPushed(ProvisionNextcloudJob::class);
    }

    #[Test]
    public function the_five_degradations_are_displayed_at_the_moment_of_the_choice(): void
    {
        $this->ready(NextcloudInstanceMode::Delegue);

        $html = Livewire::test(self::COMPONENT)->html();

        foreach (NextcloudInstanceMode::Delegue->degradations() as $degradation) {
            // Le rendu échappe les apostrophes typographiques : on compare sur un
            // fragment stable de chaque dégradation.
            $fragment = mb_substr($degradation, 0, 30);
            self::assertStringContainsString(e($fragment), $html, 'dégradation absente de l\'écran : ' . $fragment);
        }

        // …et l'honnêteté temporelle, dans les deux modes.
        self::assertStringContainsString(e(mb_substr(NextcloudInstanceMode::temporalHonesty(), 0, 40)), $html);
        self::assertStringContainsString(e(mb_substr(NextcloudInstanceMode::noImplicitRemoval(), 0, 40)), $html);
    }

    #[Test]
    public function the_administered_mode_states_its_promise_and_the_temporal_honesty(): void
    {
        $this->ready(NextcloudInstanceMode::Admin);

        $html = Livewire::test(self::COMPONENT)->html();

        self::assertStringContainsString(
            e(mb_substr(NextcloudInstanceMode::Admin->promises()[0], 0, 30)),
            $html,
        );
        self::assertStringContainsString(e(mb_substr(NextcloudInstanceMode::temporalHonesty(), 0, 40)), $html);
        self::assertStringNotContainsString('Provisionnement indisponible dans ce mode', $html);
    }

    // =====================================================================
    // AC7 — la modale de rattachement
    // =====================================================================

    private function seedReportWithMissingUser(string $candidate = 'p.durand-martin'): void
    {
        \Illuminate\Support\Facades\Cache::put(NextcloudProvisioningService::REPORT_CACHE_KEY, [
            'dry_run' => false,
            'started_at' => '2026-08-08T10:00:00+02:00',
            'refusal' => null,
            'connection' => ['ok' => true, 'message' => 'ok'],
            'mounts' => [],
            'users' => ['crees' => 0, 'adoptes' => 0, 'introuvables' => 1, 'echecs' => 0, 'exclus' => 0],
            'user_issues' => [[
                'login' => 'p.durand',
                'issue' => 'introuvable',
                'detail' => 'aucun compte Nextcloud pour ce login',
                'candidates' => [$candidate],
            ]],
            'exit_code' => 0,
        ], now()->addHour());
    }

    #[Test]
    public function the_modal_prefills_the_candidate_named_by_the_report(): void
    {
        $this->ready();
        $this->seedReportWithMissingUser();

        $component = Livewire::test(self::COMPONENT)
            ->call('openLinkModal', 'p.durand', 'p.durand-martin');

        $component->assertSet('showLinkModal', true);
        $component->assertSet('linkLogin', 'p.durand');
        $component->assertSet('linkNextcloudId', 'p.durand-martin');
    }

    #[Test]
    public function the_report_offers_the_attach_action_on_a_missing_account(): void
    {
        $this->ready();
        $this->seedReportWithMissingUser();

        $html = Livewire::test(self::COMPONENT)->html();

        self::assertStringContainsString('Rattacher', $html);
        self::assertStringContainsString('openLinkModal', $html);
    }

    #[Test]
    public function a_verified_attachment_writes_and_closes_the_modal(): void
    {
        $this->ready();
        User::query()->create(['login' => 'p.durand', 'role' => 'prof', 'is_active' => true, 'source' => 'ad']);

        Http::fake(['*/ocs/v1.php/cloud/users/*' => Http::response(self::ocs(100, ['id' => 'p.durand-martin']), 200)]);

        Livewire::test(self::COMPONENT)
            ->call('openLinkModal', 'p.durand', 'p.durand-martin')
            ->call('linkIdentity')
            ->assertSet('showLinkModal', false);

        self::assertSame('p.durand-martin', User::query()->where('login', 'p.durand')->value('nextcloud_user_id'));
    }

    #[Test]
    public function an_unverified_attachment_writes_nothing_and_keeps_the_modal_open(): void
    {
        $this->ready();
        User::query()->create(['login' => 'p.durand', 'role' => 'prof', 'is_active' => true, 'source' => 'ad']);

        Http::fake(['*/ocs/v1.php/cloud/users/*' => Http::response(self::ocs(998), 200)]);

        Livewire::test(self::COMPONENT)
            ->call('openLinkModal', 'p.durand', 'inexistant')
            ->call('linkIdentity')
            ->assertSet('showLinkModal', true);

        self::assertNull(User::query()->where('login', 'p.durand')->value('nextcloud_user_id'));
    }

    /**
     * CORRECTION DE REVUE #4 — UNE DONNÉE D'ORIGINE DISTANTE N'EST PAS INTERPOLÉE
     * NUE DANS UN `wire:click`.
     *
     * Blade échappe en entités HTML, mais le navigateur les DÉCODE avant que
     * Livewire n'évalue l'expression : une apostrophe dans un candidat suffisait à
     * casser l'attribut — et `candidates` vient de l'autocomplétion Nextcloud, une
     * source que SE5 ne contrôle pas (contrairement au login, contraint par l'AD).
     * `@js` produit un littéral JavaScript correctement échappé.
     */
    #[Test]
    public function a_candidate_carrying_quotes_never_breaks_the_click_expression(): void
    {
        $this->ready();
        $this->seedReportWithMissingUser('o\'brien"x');

        $html = Livewire::test(self::COMPONENT)->html();

        // L'apostrophe et le guillemet sont ÉCHAPPÉS EN JAVASCRIPT, jamais rendus
        // nus dans l'expression : ni `openLinkModal('o'brien…`, ni un `"` qui
        // refermerait l'attribut.
        // On isole l'EXPRESSION que Livewire évaluera : c'est là, et nulle part
        // ailleurs, que l'échappement compte. (L'instantané Livewire porte aussi le
        // rapport, mais c'est une valeur JSON d'attribut, jamais du code évalué.)
        self::assertSame(1, preg_match('/wire:click="openLinkModal\((.*?)\)"/', $html, $matches));
        $expression = $matches[1];

        // Le littéral attendu est celui que produit `@js` — construit avec la MÊME
        // fabrique plutôt que recopié à la main : recopier une séquence
        // d'échappement, c'est déjà supposer laquelle.
        self::assertStringContainsString((string) Js::from('o\'brien"x'), $expression);

        // …et ni l'apostrophe ni le guillemet ne sortent nus dans l'expression — ni
        // en entité, que le navigateur décoderait AVANT que Livewire ne l'évalue.
        self::assertStringNotContainsString('\'brien', $expression);
        self::assertStringNotContainsString('&#039;', $expression);
        self::assertStringNotContainsString('&quot;', $expression);

        // …et le geste marche toujours, jusqu'au pré-remplissage.
        Livewire::test(self::COMPONENT)
            ->call('openLinkModal', 'p.durand', 'o\'brien"x')
            ->assertSet('linkNextcloudId', 'o\'brien"x');
    }

    // =====================================================================
    // Le secret ne sort par aucun canal
    // =====================================================================

    #[Test]
    public function no_secret_ever_appears_in_the_rendered_html(): void
    {
        $this->ready(NextcloudInstanceMode::Delegue);
        Http::fake(self::healthyDelegate());

        $html = Livewire::test(self::COMPONENT)->call('testConnection')->html();

        self::assertStringNotContainsString(self::ADMIN_SECRET, $html);
        self::assertStringNotContainsString(self::DELEGATE_SECRET, $html);
    }
}

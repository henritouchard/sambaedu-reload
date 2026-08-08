<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Admin;

use App\Jobs\ProvisionNextcloudJob;
use App\Models\User;
use App\Services\FilePolicyService;
use App\Services\Nextcloud\NextcloudConnectionConfig;
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
 * Story 61.2 — AC2 / AC6 / AC10 : l'écran de configuration de la connexion.
 *
 * ---------------------------------------------------------------------------
 * **RECADRAGE DU 2026-08-08.** Ce fichier s'appelait `FilePolicyNextcloudModeTest`
 * et éprouvait le choix entre deux positions (instance administrée / compte porteur
 * délégué). Le mode délégué a été supprimé : un compte ordinaire ne peut créer ni
 * dossier d'équipe, ni groupe, ni partage de groupe — donc pas de clôture. Ce qui
 * reste est ce qui avait de la valeur, et qui se formule maintenant d'une seule
 * façon : **une configuration que le compte ne peut pas honorer est refusée avec son
 * motif, jamais acceptée puis dégradée en silence**.
 * ---------------------------------------------------------------------------
 *
 * Deux tests pivots :
 *  - {@see self::a_configuration_the_account_cannot_honour_is_refused_and_nothing_is_persisted()} :
 *    fail-closed — une configuration refusée n'est pas enregistrée du tout ;
 *  - {@see self::saving_an_unrelated_setting_never_talks_to_the_instance()} : la
 *    sonde-garde ne s'exécute QUE sur ce qui définit la connexion, sans quoi une
 *    instance en panne verrouillerait l'édition de réglages qui ne la concernent pas.
 */
class FilePolicyNextcloudConnectionGuardTest extends TestCase
{
    use RefreshDatabase;

    private const COMPONENT = 'pages::admin.settings.files._partials.personnels-partages-tab';

    private const ADMIN_SECRET = 'AppPasswordAdminTresSecret';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        $admin = User::query()->create(['login' => 'files-admin', 'role' => 'prof', 'is_active' => true]);
        $this->actingAs($admin);
        Gate::before(fn ($user, string $ability) => $ability === 'server.admin' ? true : null);
    }

    private function ready(): void
    {
        app(ServiceCredentials::class)->put(NextcloudConnectionConfig::CREDENTIAL_NAME, self::ADMIN_SECRET);

        FilePolicyService::setGlobal(true, true, true, 'https://cloud.etab.fr', 'admin', 'se4fs', true);
    }

    /** @param array<string, mixed> $data */
    private static function ocs(int $code, array $data = []): array
    {
        return ['ocs' => ['meta' => ['status' => 'ok', 'statuscode' => $code, 'message' => 'OK'], 'data' => $data]];
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
    // AC2 — la configuration fail-closed
    // =====================================================================

    /**
     * **LE TEST PIVOT.** Le compte saisi n'est pas administrateur de l'instance :
     * la configuration est refusée, RIEN n'est persisté, et le motif nomme le
     * privilège — jamais « enregistré, on verra bien ».
     */
    #[Test]
    public function a_configuration_the_account_cannot_honour_is_refused_and_nothing_is_persisted(): void
    {
        $this->ready();
        Http::fake(self::nonAdmin());

        $component = Livewire::test(self::COMPONENT)
            ->set('nextcloudAdminUser', 'compte-ordinaire');

        // L'identifiant précédent reste en vigueur, à l'écran comme en base.
        $component->assertSet('nextcloudAdminUser', 'admin');
        self::assertSame('admin', FilePolicyService::globalConfig()['nextcloud_admin_user']);

        // …et le motif EXACT est affiché, sans le secret.
        $message = (string) $component->get('probeResult')['message'];
        self::assertStringContainsString('administrateur', $message);
        self::assertStringNotContainsString(self::ADMIN_SECRET, $message);
        self::assertStringNotContainsString(self::ADMIN_SECRET, $component->html());
    }

    /**
     * **Une instance injoignable refuse aussi la configuration** : on ne déclare pas
     * une cible qu'on n'a pas pu vérifier.
     */
    #[Test]
    public function an_unreachable_instance_refuses_the_configuration_change(): void
    {
        $this->ready();
        Http::fake(['*' => static fn (): never => throw new ConnectionException('cURL error 7')]);

        Livewire::test(self::COMPONENT)
            ->set('nextcloudServerUrl', 'https://nouvel-hebergeur.fr')
            ->assertSet('nextcloudServerUrl', 'https://cloud.etab.fr');

        self::assertSame('https://cloud.etab.fr', FilePolicyService::globalConfig()['nextcloud_server_url']);
    }

    // =====================================================================
    // AC2 — la sonde-garde ne s'exécute QUE sur ce qui définit la connexion
    // =====================================================================

    /**
     * **Retouché par la correction de revue #1** : `nextcloudVerifyTls` a QUITTÉ ce
     * test. Le drapeau TLS n'est pas un réglage orthogonal — il décide de ce qui est
     * joignable, donc il fait partie de ce qui définit la connexion et il déclenche
     * la sonde-garde ({@see self::changing_only_the_tls_flag_re_probes()}). Restent
     * ici les réglages qui ne concernent VRAIMENT pas l'instance : les deux
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
    // CORRECTION DE REVUE #1 — LE CHANGEMENT D'URL (ET DE TLS) DÉCLENCHE LA
    // SONDE-GARDE
    //
    // Le défaut fermé ici : la comparaison ne portait que sur l'identifiant du
    // compte. Changer la SEULE URL — nouvel hébergeur, ou faute de frappe —
    // traversait la garde sans le moindre appel HTTP, et `setGlobal()` persistait
    // une cible que le compte n'avait JAMAIS été vérifié capable d'administrer.
    // =====================================================================

    #[Test]
    public function changing_only_the_url_re_probes(): void
    {
        $this->ready();
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
    public function a_url_the_account_cannot_honour_is_refused_and_the_url_is_restored(): void
    {
        $this->ready();

        // Sur la nouvelle cible, le compte n'est pas administrateur.
        Http::fake(self::nonAdmin());

        $component = Livewire::test(self::COMPONENT)
            ->set('nextcloudServerUrl', 'https://nouvel-hebergeur.fr');

        // Rien n'est persisté…
        self::assertSame('https://cloud.etab.fr', FilePolicyService::globalConfig()['nextcloud_server_url']);

        // …et l'écran a RESTAURÉ l'URL : il ne doit pas afficher une configuration
        // que la base ne porte pas.
        $component->assertSet('nextcloudServerUrl', 'https://cloud.etab.fr');
        self::assertStringContainsString('administrateur', (string) $component->get('probeResult')['message']);
    }

    #[Test]
    public function changing_only_the_tls_flag_re_probes(): void
    {
        $this->ready();
        Http::fake(self::healthyAdmin());

        Livewire::test(self::COMPONENT)->set('nextcloudVerifyTls', false);

        Http::assertSentCount(2);
        self::assertFalse(FilePolicyService::globalConfig()['nextcloud_verify_tls']);
    }

    #[Test]
    public function a_refused_tls_change_is_restored_and_nothing_is_persisted(): void
    {
        $this->ready();
        Http::fake(self::nonAdmin());

        $component = Livewire::test(self::COMPONENT)->set('nextcloudVerifyTls', false);

        $component->assertSet('nextcloudVerifyTls', true);
        self::assertTrue(FilePolicyService::globalConfig()['nextcloud_verify_tls']);
    }

    /**
     * …mais la garde de SONDABILITÉ tient toujours : une configuration en cours de
     * constitution (aucun secret enregistré) se saisit sans être refusée à chaque
     * frappe — sinon on ne pourrait jamais la constituer.
     */
    #[Test]
    public function typing_a_url_on_an_unconfigured_connection_does_not_probe(): void
    {
        FilePolicyService::setGlobal(true, true, true, '', 'admin', 'se4fs', true);
        Http::fake();

        Livewire::test(self::COMPONENT)->set('nextcloudServerUrl', 'https://cloud.etab.fr');

        Http::assertNothingSent();
        self::assertSame('https://cloud.etab.fr', FilePolicyService::globalConfig()['nextcloud_server_url']);
    }

    /** Changer l'identifiant admin re-vérifie : la cible doit rester administrable. */
    #[Test]
    public function changing_the_admin_identifier_re_verifies(): void
    {
        $this->ready();
        Http::fake(self::healthyAdmin());

        Livewire::test(self::COMPONENT)->set('nextcloudAdminUser', 'autre-admin');

        Http::assertSentCount(2);
        self::assertSame('autre-admin', FilePolicyService::globalConfig()['nextcloud_admin_user']);
    }

    /**
     * …mais une configuration EN COURS DE CONSTITUTION (aucun secret enregistré) ne
     * se fait pas refuser à chaque frappe : sans cela, on ne pourrait jamais la
     * constituer.
     */
    #[Test]
    public function typing_an_identifier_on_an_unconfigured_connection_does_not_probe(): void
    {
        FilePolicyService::setGlobal(true, true, true, 'https://cloud.etab.fr');
        Http::fake();

        Livewire::test(self::COMPONENT)->set('nextcloudAdminUser', 'admin');

        Http::assertNothingSent();
        self::assertSame('admin', FilePolicyService::globalConfig()['nextcloud_admin_user']);
    }

    /** Capacité éteinte : aucune instance à configurer, aucun appel. */
    #[Test]
    public function a_disabled_capability_never_probes(): void
    {
        app(ServiceCredentials::class)->put(NextcloudConnectionConfig::CREDENTIAL_NAME, self::ADMIN_SECRET);
        FilePolicyService::setGlobal(true, true, false, 'https://cloud.etab.fr', 'admin', 'se4fs', true);
        Http::fake();

        Livewire::test(self::COMPONENT)
            ->set('nextcloudAdminUser', 'compte-ordinaire')
            ->assertSet('nextcloudAdminUser', 'compte-ordinaire');

        Http::assertNothingSent();
    }

    // =====================================================================
    // Le mode délégué N'EXISTE PLUS : l'écran n'en porte aucune trace
    // =====================================================================

    /**
     * Garde de non-régression du recadrage du 2026-08-08. Un écran qui offrirait de
     * nouveau un choix d'administration proposerait une position que le produit ne
     * sait pas tenir.
     */
    #[Test]
    public function the_screen_offers_no_administration_mode_selection(): void
    {
        $this->ready();

        $html = Livewire::test(self::COMPONENT)->html();

        self::assertStringNotContainsString('nextcloud-mode', $html);
        self::assertStringNotContainsString('nextcloud-delegue-user', $html);
        self::assertStringNotContainsString('nextcloud-delegue-password', $html);

        // …et l'exigence est DITE là où le compte se saisit.
        self::assertStringContainsString('administrateur', $html);
    }

    // =====================================================================
    // CORRECTION DE REVUE #3 — REMPLACER UN SECRET NE LAISSE PLUS UNE
    // CONFIGURATION « VÉRIFIÉE » QU'ELLE N'EST PLUS
    //
    // La variante retenue est NON BLOQUANTE : l'enregistrement du secret n'est
    // jamais annulé par le résultat de la sonde (refuser de stocker un app
    // password que l'instance ne confirme pas rendrait une instance
    // momentanément injoignable impossible à reconfigurer — un deadlock réel).
    // =====================================================================

    #[Test]
    public function a_secret_confirmed_by_the_probe_is_stored_and_shown_as_verified(): void
    {
        $this->ready();
        Http::fake(self::healthyAdmin());

        $component = Livewire::test(self::COMPONENT)
            ->set('nextcloudAdminPassword', 'NouveauSecretAdmin');

        $diagnostic = $component->get('probeResult');
        self::assertIsArray($diagnostic);
        self::assertTrue($diagnostic['ok']);
        self::assertArrayNotHasKey('unverified_since_secret_change', $diagnostic);

        self::assertSame(
            'NouveauSecretAdmin',
            app(ServiceCredentials::class)->password(NextcloudConnectionConfig::CREDENTIAL_NAME),
        );
    }

    /**
     * **L'ASSERTION QUI COMPTE** : le secret est enregistré QUAND MÊME. Le deadlock
     * (« instance injoignable ⇒ impossible d'enregistrer le secret qui la rendrait
     * joignable ») est bien évité — et l'écran affiche l'état honnête.
     */
    #[Test]
    public function a_secret_the_instance_rejects_is_still_stored_and_the_connection_is_shown_unverified(): void
    {
        $this->ready();
        Http::fake(self::nonAdmin());

        $component = Livewire::test(self::COMPONENT)
            ->set('nextcloudAdminPassword', 'SecretQueLInstanceRefuse');

        // 1. Le secret EST enregistré (pas de deadlock).
        $component->assertSet('hasAdminSecret', true);
        self::assertSame(
            'SecretQueLInstanceRefuse',
            app(ServiceCredentials::class)->password(NextcloudConnectionConfig::CREDENTIAL_NAME),
        );

        // 2. …et la connexion est dite NON VÉRIFIÉE, avec son motif.
        $diagnostic = $component->get('probeResult');
        self::assertTrue($diagnostic['unverified_since_secret_change']);
        self::assertStringContainsString('administrateur', (string) $diagnostic['message']);

        $html = $component->html();
        self::assertStringContainsString('non vérifiée', $html);
        self::assertStringNotContainsString('SecretQueLInstanceRefuse', $html);
    }

    /** …et cet état SURVIT au rechargement de la page : ce n'est pas une propriété volatile. */
    #[Test]
    public function the_unverified_state_survives_a_page_reload(): void
    {
        $this->ready();
        Http::fake(self::nonAdmin());

        Livewire::test(self::COMPONENT)->set('nextcloudAdminPassword', 'SecretQueLInstanceRefuse');

        // Un montage tout neuf — c'est ce que fait un F5.
        $reloaded = Livewire::test(self::COMPONENT);

        self::assertTrue($reloaded->get('probeResult')['unverified_since_secret_change']);
        self::assertStringContainsString('non vérifiée', $reloaded->html());
    }

    /** Une instance injoignable ne bloque pas non plus : le secret passe, l'état est honnête. */
    #[Test]
    public function an_unreachable_instance_never_blocks_storing_a_secret(): void
    {
        $this->ready();
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
    // AC10 — « Tester la connexion »
    // =====================================================================

    #[Test]
    public function testing_the_connection_probes_the_configured_account(): void
    {
        $this->ready();
        Http::fake(self::healthyAdmin());

        $component = Livewire::test(self::COMPONENT)->call('testConnection');

        self::assertTrue($component->get('probeResult')['ok']);
        self::assertTrue($component->get('probeResult')['administrator']);
    }

    // =====================================================================
    // AC5 — le provisionnement
    // =====================================================================

    #[Test]
    public function the_provision_button_queues_the_job(): void
    {
        $this->ready();
        Queue::fake();

        Livewire::test(self::COMPONENT)->call('provision');

        Queue::assertPushed(ProvisionNextcloudJob::class);
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
        self::assertSame(1, preg_match('/wire:click="openLinkModal\((.*?)\)"/', $html, $matches));
        $expression = $matches[1];

        // Le littéral attendu est celui que produit `@js` — construit avec la MÊME
        // fabrique plutôt que recopié à la main : recopier une séquence
        // d'échappement, c'est déjà supposer laquelle.
        self::assertStringContainsString((string) Js::from('o\'brien"x'), $expression);

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
        $this->ready();
        Http::fake(self::healthyAdmin());

        $html = Livewire::test(self::COMPONENT)->call('testConnection')->html();

        self::assertStringNotContainsString(self::ADMIN_SECRET, $html);
    }
}

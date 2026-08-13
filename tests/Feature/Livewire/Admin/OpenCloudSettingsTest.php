<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Admin;

use App\Enums\FileBackendName;
use App\Models\User;
use App\Services\FilePolicyService;
use App\Services\Filesystem\Backend\FileBackendSelection;
use App\Services\OpenCloud\OpenCloudConnectionConfig;
use App\Services\OpenCloud\OpenCloudConnectionVerifier;
use App\Services\ServiceCredentials;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * L'ÉCRAN DE CONNEXION OPENCLOUD — et la seule chose qu'il ne doit JAMAIS faire.
 *
 * Un écran de réglages est le chemin le plus court d'un secret vers un journal :
 * une propriété qui garde sa valeur repart dans l'instantané du rendu suivant,
 * c'est-à-dire dans le HTML servi au navigateur. Le test qui compte le plus ici
 * est donc celui qui lit le HTML RENDU.
 */
class OpenCloudSettingsTest extends TestCase
{
    use RefreshDatabase;

    private const COMPONENT = 'pages::admin.settings.files._partials.opencloud-tab';

    protected function setUp(): void
    {
        parent::setUp();

        $admin = User::factory()->create();
        $this->actingAs($admin);
        \Illuminate\Support\Facades\Gate::before(fn (): bool => true);
    }

    #[Test]
    public function the_capability_toggle_persists_on_its_own_and_touches_nothing_else(): void
    {
        FilePolicyService::setGlobal(true, true, true, 'https://nuage.fr', 'ncadmin', 'se4fs', true);

        Livewire::test(self::COMPONENT)
            ->set('opencloud', true)
            ->assertHasNoErrors();

        $policy = FilePolicyService::globalConfig();
        self::assertTrue($policy['opencloud']);

        // Les réglages de l'autre produit sont INTACTS : les capacités sont
        // indépendantes, et l'écran de l'une n'écrit pas celle de l'autre.
        self::assertTrue($policy['nextcloud']);
        self::assertSame('https://nuage.fr', $policy['nextcloud_server_url']);
        self::assertSame('ncadmin', $policy['nextcloud_admin_user']);
    }

    #[Test]
    public function the_connection_settings_persist_on_blur(): void
    {
        Livewire::test(self::COMPONENT)
            ->set('opencloud', true)
            ->set('serverUrl', '  https://fichiers.etab.fr  ')
            ->set('adminUser', ' admin ')
            ->assertHasNoErrors();

        $policy = FilePolicyService::globalConfig();
        self::assertSame('https://fichiers.etab.fr', $policy['opencloud_server_url']);
        self::assertSame('admin', $policy['opencloud_admin_user']);
    }

    /** La vérification TLS est VRAIE par défaut, et son assouplissement est VISIBLE. */
    #[Test]
    public function tls_verification_is_on_by_default_and_relaxing_it_is_an_explicit_persisted_choice(): void
    {
        self::assertTrue(FilePolicyService::globalConfig()['opencloud_verify_tls']);

        Livewire::test(self::COMPONENT)
            ->assertSet('verifyTls', true)
            ->set('verifyTls', false);

        self::assertFalse(FilePolicyService::globalConfig()['opencloud_verify_tls']);
    }

    /**
     * **LE SECRET NE REPART JAMAIS DANS LE HTML.** Il est rangé chiffré, la
     * propriété est VIDÉE, et l'écran ne montre que le FAIT qu'un secret existe.
     */
    #[Test]
    public function the_secret_is_stored_encrypted_and_never_reaches_the_rendered_html(): void
    {
        $component = Livewire::test(self::COMPONENT)
            ->set('opencloud', true)
            ->set('adminPassword', 'ultra-secret-2026');

        $component->assertSet('adminPassword', '');
        $component->assertSet('hasAdminSecret', true);
        $component->assertDontSee('ultra-secret-2026');

        self::assertSame(
            'ultra-secret-2026',
            app(ServiceCredentials::class)->password(OpenCloudConnectionConfig::CREDENTIAL_NAME),
        );

        // Et il n'est pas non plus dans le réglage en clair.
        self::assertStringNotContainsString(
            'ultra-secret-2026',
            json_encode(FilePolicyService::globalConfig(), JSON_UNESCAPED_UNICODE),
        );
    }

    #[Test]
    public function forgetting_the_secret_clears_it_and_the_stale_diagnostic_with_it(): void
    {
        app(ServiceCredentials::class)->put(OpenCloudConnectionConfig::CREDENTIAL_NAME, 'x');
        app(OpenCloudConnectionVerifier::class)->rememberDiagnostic(['ok' => true, 'message' => 'vert']);

        Livewire::test(self::COMPONENT)
            ->set('opencloud', true)
            ->call('forgetAdminPassword')
            ->assertSet('hasAdminSecret', false)
            ->assertSet('probeResult', null);

        self::assertNull(app(OpenCloudConnectionVerifier::class)->lastDiagnostic());
    }

    /**
     * **UN DIAGNOSTIC VERT NE SURVIT PAS À UN CHANGEMENT DE SECRET SANS LE DIRE.**
     * Un « non vérifié » explicite vaut mieux qu'un vert qui ne dit plus rien de la
     * configuration courante.
     */
    #[Test]
    public function changing_the_secret_marks_the_previous_diagnostic_unverified(): void
    {
        app(OpenCloudConnectionVerifier::class)->rememberDiagnostic(['ok' => true, 'message' => 'vert']);

        Livewire::test(self::COMPONENT)
            ->set('opencloud', true)
            ->set('adminPassword', 'nouveau');

        $diagnostic = app(OpenCloudConnectionVerifier::class)->lastDiagnostic();
        self::assertTrue($diagnostic['unverified_since_secret_change'] ?? false);
    }

    /** La sonde n'écrit RIEN sur l'instance : elle ne fait que lire. */
    #[Test]
    public function the_probe_only_reads_and_persists_its_verdict(): void
    {
        FilePolicyService::setGlobal(true, true, false, '', null, null, null, true, 'https://fichiers.etab.fr', 'admin', true);
        app(ServiceCredentials::class)->put(OpenCloudConnectionConfig::CREDENTIAL_NAME, 'secret');

        Http::fake(['*' => Http::response(['onPremisesSamAccountName' => 'admin', 'value' => []], 200)]);

        Livewire::test(self::COMPONENT)->call('testConnection');

        Http::assertSentCount(3);
        Http::assertSent(fn ($request): bool => strtoupper($request->method()) === 'GET');

        $diagnostic = app(OpenCloudConnectionVerifier::class)->lastDiagnostic();
        self::assertTrue($diagnostic['ok'] ?? false);
    }

    /**
     * **CAPACITÉ ÉTEINTE ⇒ LA CASE EST ABSENTE, PAS GRISÉE**, et le motif est dit
     * ailleurs. Proposer puis refuser est exactement le défaut du signal accepté
     * sans destinataire.
     *
     * **ET LA CAPACITÉ NE SUFFIT PAS : IL FAUT AUSSI LA CONNEXION.** Une capacité
     * allumée sur une connexion vide ferait naître un répertoire dont AUCUNE
     * réconciliation ne peut aboutir — et dont l'autorité d'écriture ne se change
     * plus jamais (D9). Le rattrapage tardif au provisionnement est correct, mais
     * il arrive après la seule décision irréversible.
     */
    #[Test]
    public function the_authority_needs_both_the_capability_and_a_configured_connection(): void
    {
        $selection = app(FileBackendSelection::class);

        // ① capacité éteinte.
        self::assertNotContains(FileBackendName::OpenCloud, $selection->selectable());
        self::assertStringContainsString('Accès OpenCloud', (string) $selection->refusalFor(FileBackendName::OpenCloud));

        // ② capacité allumée, connexion VIDE : toujours pas posable, et le motif
        // nomme ce qui manque plutôt que de dire « indisponible ».
        FilePolicyService::setGlobal(true, true, false, '', null, null, null, true);

        self::assertNotContains(FileBackendName::OpenCloud, app(FileBackendSelection::class)->selectable());
        $refusal = (string) app(FileBackendSelection::class)->refusalFor(FileBackendName::OpenCloud);
        self::assertStringContainsString('connexion à l\'instance OpenCloud est incomplète', $refusal);
        self::assertStringContainsString('URL', $refusal);

        // ③ capacité allumée ET connexion complète : posable.
        FilePolicyService::setGlobal(
            true, true, false, '', null, null, null,
            true, 'https://fichiers.etab.fr', 'admin', true,
        );
        app(ServiceCredentials::class)->put(OpenCloudConnectionConfig::CREDENTIAL_NAME, 'secret');

        self::assertContains(FileBackendName::OpenCloud, app(FileBackendSelection::class)->selectable());
        self::assertNull(app(FileBackendSelection::class)->refusalFor(FileBackendName::OpenCloud));
    }

    /**
     * **LA GARDE EST REJOUÉE CÔTÉ SERVICE**, pas seulement dans la liste affichée :
     * une garde qui ne vit que dans l'écran protège l'étourderie, pas la requête
     * forgée.
     */
    #[Test]
    public function the_service_refuses_a_forged_choice_even_when_the_screen_never_offered_it(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Accès OpenCloud/');

        app(FileBackendSelection::class)->resolve('opencloud');
    }
}

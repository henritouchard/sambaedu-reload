<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Admin;

use App\Jobs\ProvisionNextcloudJob;
use App\Models\User;
use App\Services\FilePolicyService;
use App\Services\Nextcloud\NextcloudConnectionConfig;
use App\Services\ServiceCredentials;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 61.1 — l'écran de connexion Nextcloud sur `/admin/settings/files`.
 *
 * Le test pivot est {@see self::the_admin_secret_never_appears_in_the_rendered_html()} :
 * une propriété Livewire non vidée repart dans l'instantané du composant, donc
 * dans le HTML de la page. C'est le chemin le plus court, et le plus discret, vers
 * un secret exposé.
 */
class FilePolicyNextcloudSettingsTest extends TestCase
{
    use RefreshDatabase;

    private const COMPONENT = 'pages::admin.settings.files._partials.nextcloud-connection';

    private const SECRET = 'AppPasswordAdminTresSecret';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        $admin = User::query()->create(['login' => 'files-admin', 'role' => 'prof', 'is_active' => true]);
        $this->actingAs($admin);
        Gate::before(fn ($user, string $ability) => $ability === 'server.admin' ? true : null);
    }

    /** @param array<string, mixed> $data */
    private static function ocs(int $code, array $data = []): array
    {
        return ['ocs' => ['meta' => ['status' => 'ok', 'statuscode' => $code, 'message' => 'OK'], 'data' => $data]];
    }

    // =====================================================================
    // AC11 — les champs de connexion
    // =====================================================================

    /**
     * **Retouché par la story 63.3** : l'interrupteur de capacité a QUITTÉ ce
     * composant (« Accès Nextcloud » suit le cloud actif, décidé au-dessus). Ce
     * qui reste est la propriété que ce test tenait : le bloc de connexion est
     * bien rendu, et rien n'y est annoncé comme « pas encore disponible ».
     */
    #[Test]
    public function the_connection_block_is_rendered_and_nothing_is_announced_as_unavailable(): void
    {
        $html = Livewire::test(self::COMPONENT)->html();

        self::assertStringNotContainsString('n\'est pas encore disponible', $html);
        self::assertStringContainsString('Connexion à l\'instance Nextcloud', $html);
    }

    #[Test]
    public function the_connection_fields_persist_into_the_policy(): void
    {
        // La capacité est posée par le miroir des emplacements, jamais par cet
        // écran — on la met en place ici, et on épingle plus bas qu'il ne
        // l'éteint pas en enregistrant.
        FilePolicyService::setGlobal(true, true, true);

        Livewire::test(self::COMPONENT)
            ->set('nextcloudServerUrl', 'https://cloud.etab.fr')
            ->set('nextcloudAdminUser', 'admin')
            ->set('nextcloudSmbHost', 'se4fs')
            ->set('nextcloudVerifyTls', false)
            ->assertHasNoErrors();

        $config = FilePolicyService::globalConfig();

        self::assertTrue($config['nextcloud']);
        self::assertSame('https://cloud.etab.fr', $config['nextcloud_server_url']);
        self::assertSame('admin', $config['nextcloud_admin_user']);
        self::assertSame('se4fs', $config['nextcloud_smb_host']);
        self::assertFalse($config['nextcloud_verify_tls']);
    }

    /** Une URL sans schéma est refusée à la saisie, pas au premier appel. */
    #[Test]
    public function a_url_without_a_scheme_is_rejected_on_the_screen(): void
    {
        Livewire::test(self::COMPONENT)
            ->set('nextcloudServerUrl', 'cloud.etab.fr')
            ->assertHasErrors('nextcloudServerUrl');
    }

    /**
     * Basculer une capacité ne doit PAS effacer la configuration de connexion —
     * le mode de défaut qu'un défaut « chaîne vide » aurait introduit.
     */
    #[Test]
    public function toggling_a_capability_preserves_the_connection_settings(): void
    {
        FilePolicyService::setGlobal(true, true, true, 'https://cloud.etab.fr', 'admin', 'se4fs', true);

        FilePolicyService::setGlobal(false, true, true, 'https://cloud.etab.fr');

        $config = FilePolicyService::globalConfig();
        self::assertSame('admin', $config['nextcloud_admin_user']);
        self::assertSame('se4fs', $config['nextcloud_smb_host']);
    }

    // =====================================================================
    // AC1 — le secret, jamais rendu
    // =====================================================================

    #[Test]
    public function the_admin_secret_never_appears_in_the_rendered_html(): void
    {
        app(ServiceCredentials::class)->put(NextcloudConnectionConfig::CREDENTIAL_NAME, self::SECRET);
        FilePolicyService::setGlobal(true, true, true, 'https://cloud.etab.fr', 'admin', 'se4fs', true);

        $component = Livewire::test(self::COMPONENT);

        $component->assertSet('hasAdminSecret', true);
        self::assertStringNotContainsString(self::SECRET, $component->html());
    }

    #[Test]
    public function typing_a_secret_stores_it_encrypted_and_clears_the_property(): void
    {
        FilePolicyService::setGlobal(true, true, true, 'https://cloud.etab.fr', 'admin', 'se4fs', true);

        $component = Livewire::test(self::COMPONENT)
            ->set('nextcloudAdminPassword', self::SECRET);

        // La propriété est VIDÉE : sans cela, elle repartirait dans l'instantané.
        $component->assertSet('nextcloudAdminPassword', '');
        $component->assertSet('hasAdminSecret', true);
        self::assertStringNotContainsString(self::SECRET, $component->html());

        self::assertSame(
            self::SECRET,
            app(ServiceCredentials::class)->password(NextcloudConnectionConfig::CREDENTIAL_NAME),
        );

        // …et il est bien CHIFFRÉ en base : la colonne ne contient pas le clair.
        $stored = (string) \Illuminate\Support\Facades\DB::table('service_credentials')
            ->where('name', NextcloudConnectionConfig::CREDENTIAL_NAME)
            ->value('secret');
        self::assertStringNotContainsString(self::SECRET, $stored);
    }

    #[Test]
    public function the_secret_can_be_forgotten(): void
    {
        app(ServiceCredentials::class)->put(NextcloudConnectionConfig::CREDENTIAL_NAME, self::SECRET);
        FilePolicyService::setGlobal(true, true, true, 'https://cloud.etab.fr', 'admin', 'se4fs', true);

        Livewire::test(self::COMPONENT)
            ->call('forgetAdminPassword')
            ->assertSet('hasAdminSecret', false);

        self::assertNull(app(ServiceCredentials::class)->password(NextcloudConnectionConfig::CREDENTIAL_NAME));
    }

    // =====================================================================
    // AC1 / AC9 — les trois diagnostics, distincts sur l'écran
    // =====================================================================

    private function ready(): void
    {
        FilePolicyService::setGlobal(true, true, true, 'https://cloud.etab.fr', 'admin', 'se4fs', true);
        app(ServiceCredentials::class)->put(NextcloudConnectionConfig::CREDENTIAL_NAME, self::SECRET);
    }

    #[Test]
    public function the_three_connection_diagnostics_produce_three_distinct_messages(): void
    {
        $this->ready();

        // `Http::fake()` FUSIONNE les doubles successifs et le premier motif qui
        // correspond l'emporte : sans réinitialisation de la fabrique, le joker du
        // premier scénario répondrait aux deux suivants et le test serait vert
        // pour la pire des raisons (trois fois le même diagnostic).
        $diagnose = function (array $stubs): string {
            Http::swap(new \Illuminate\Http\Client\Factory());
            Http::fake($stubs);

            return Livewire::test(self::COMPONENT)->call('testConnection')->get('probeResult')['message'];
        };

        $messages = [
            'injoignable' => $diagnose([
                '*' => static fn (): never => throw new \Illuminate\Http\Client\ConnectionException('cURL error 7'),
            ]),
            'privilege' => $diagnose([
                '*/ocs/v2.php/cloud/capabilities*' => Http::response(self::ocs(100), 200),
                '*/globalstorages*' => Http::response('nope', 403),
            ]),
            'app' => $diagnose([
                '*/ocs/v2.php/cloud/capabilities*' => Http::response(self::ocs(100), 200),
                '*/globalstorages*' => Http::response('not found', 404),
            ]),
        ];

        self::assertCount(3, array_unique($messages), 'trois causes, trois messages distincts');
        self::assertStringContainsString('injoignable', $messages['injoignable']);
        self::assertStringContainsString('administrateur', $messages['privilege']);
        self::assertStringContainsString('files_external', $messages['app']);

        foreach ($messages as $message) {
            self::assertStringNotContainsString(self::SECRET, $message);
        }
    }

    #[Test]
    public function a_green_probe_is_reported_as_such(): void
    {
        $this->ready();

        Http::fake([
            '*/ocs/v2.php/cloud/capabilities*' => Http::response(self::ocs(100), 200),
            '*/globalstorages*' => Http::response([], 200),
        ]);

        $component = Livewire::test(self::COMPONENT)->call('testConnection');

        self::assertTrue($component->get('probeResult')['ok']);
    }

    /** Configuration incomplète : le test de connexion refuse, sans appel. */
    #[Test]
    public function testing_an_incomplete_configuration_names_the_missing_setting(): void
    {
        FilePolicyService::setGlobal(true, true, true, 'https://cloud.etab.fr', '', '', true);
        Http::fake();

        $component = Livewire::test(self::COMPONENT)->call('testConnection');

        self::assertFalse($component->get('probeResult')['ok']);
        self::assertStringContainsString('identifiant du compte admin', $component->get('probeResult')['message']);
        Http::assertNothingSent();
    }

    // =====================================================================
    // AC8 — le bouton enfile, il n'exécute pas
    // =====================================================================

    #[Test]
    public function the_provision_button_queues_the_job(): void
    {
        $this->ready();
        Queue::fake();

        Livewire::test(self::COMPONENT)->call('provision');

        Queue::assertPushed(ProvisionNextcloudJob::class, static fn (ProvisionNextcloudJob $job): bool =>
            $job->performedBy === 'files-admin');
    }
}

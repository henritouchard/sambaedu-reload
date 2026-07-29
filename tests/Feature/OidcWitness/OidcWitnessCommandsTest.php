<?php

declare(strict_types=1);

namespace Tests\Feature\OidcWitness;

use App\Auth\Oidc\Services\OidcClientRegistry;
use App\Console\Commands\OidcWitnessEnable;
use App\Models\OidcClient;
use App\OidcWitness\Support\WitnessCredentials;
use Database\Seeders\BundledExtensionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Oidc\Concerns\CapturesOidcLogs;
use Tests\TestCase;

/**
 * Story 55.3 — **AC4** : le provisioning de l'app-témoin.
 *
 * Patron `OidcClientCommandsTest` (55.1), avec UNE différence de doctrine
 * assumée : `oidc:client:register` AFFICHE le secret une fois (son destinataire
 * est un humain qui doit le recopier ailleurs) ; `oidc:witness:enable` ne
 * l'affiche JAMAIS — son destinataire est un fichier que la commande écrit
 * elle-même. Afficher un secret que personne n'a besoin de lire, c'est
 * l'exposer à l'historique du terminal et aux journaux d'exploitation pour rien.
 *
 * C'est cette absence que la suite vérifie, en plus de l'idempotence.
 */
class OidcWitnessCommandsTest extends TestCase
{
    use CapturesOidcLogs;
    use RefreshDatabase;

    private string $credentialsPath;

    protected function setUp(): void
    {
        parent::setUp();

        // ⚠️ Jamais le vrai `storage/app`.
        $this->credentialsPath = sys_get_temp_dir()
            . '/oidc-witness-cmd-' . getmypid() . '-' . bin2hex(random_bytes(6)) . '.json';

        config([
            'oidc.witness.credentials_path' => $this->credentialsPath,
            'oidc.issuer' => 'https://se5.test',
        ]);
    }

    protected function tearDown(): void
    {
        if (is_file($this->credentialsPath)) {
            @unlink($this->credentialsPath);
        }

        parent::tearDown();
    }

    private function seedRegistry(): void
    {
        (new BundledExtensionSeeder())->run();
    }

    // =====================================================================
    // enable
    // =====================================================================

    /**
     * Le dossier de destination peut ne pas exister (chemin surchargé par
     * `OIDC_WITNESS_CREDENTIALS_PATH`, `storage/app/` fraîchement recréé). Le
     * cas est distinct du nominal : c'est la branche où la commande CRÉE un
     * répertoire, donc celle où elle lui impose une identité et des droits.
     *
     * Un dossier 0700 appartenant au mauvais utilisateur rendrait le fichier
     * inatteignable même correctement chowné — la traversée échouerait avant
     * la lecture, et le témoin répondrait le même 503 opaque.
     */
    #[Test]
    public function enable_creates_the_destination_directory_when_it_is_missing(): void
    {
        $dir = sys_get_temp_dir() . '/oidc-witness-dir-' . getmypid() . '-' . bin2hex(random_bytes(6));
        $path = $dir . '/nested/oidc-witness.json';

        self::assertDirectoryDoesNotExist($dir);

        config(['oidc.witness.credentials_path' => $path]);

        $this->seedRegistry();

        try {
            $this->artisan('oidc:witness:enable')->assertExitCode(0);

            self::assertDirectoryExists(dirname($path));
            self::assertFileExists($path);
            self::assertSame('0600', substr(sprintf('%o', fileperms($path)), -4));

            // Le fichier est RELISIBLE par le processus courant : la création
            // du dossier n'a pas produit une arborescence intraversable.
            self::assertNotNull(WitnessCredentials::load());
        } finally {
            @unlink($path);
            @rmdir(dirname($path));
            @rmdir($dir);
        }
    }

    #[Test]
    public function enable_registers_a_client_and_writes_a_0600_credentials_file(): void
    {
        $this->seedRegistry();

        $this->artisan('oidc:witness:enable')->assertExitCode(0);

        // ── Le client ────────────────────────────────────────────────────
        $client = OidcClient::query()->firstOrFail();

        self::assertSame(OidcWitnessEnable::CLIENT_NAME, $client->name);
        self::assertTrue($client->enabled);
        self::assertSame([OidcWitnessEnable::REDIRECT_URI], $client->redirectUris());
        self::assertSame(OidcWitnessEnable::EXTENSION_KEY, $client->extension_key);

        // ── Le fichier ───────────────────────────────────────────────────
        self::assertFileExists($this->credentialsPath);
        self::assertSame('0600', substr(sprintf('%o', fileperms($this->credentialsPath)), -4));

        $credentials = WitnessCredentials::load();

        self::assertNotNull($credentials);
        self::assertSame($client->client_id, $credentials->clientId);
        self::assertSame('https://se5.test', $credentials->issuer);
        self::assertSame(OidcWitnessEnable::REDIRECT_URI, $credentials->redirectUri);

        // ── Contrôle POSITIF : le secret du fichier est bien CELUI qui
        //    authentifie le client. Sans lui, « le secret n'est pas affiché »
        //    pourrait n'être que le symptôme d'un secret jamais écrit.
        self::assertNotNull(
            app(OidcClientRegistry::class)->authenticate($credentials->clientId, $credentials->clientSecret),
        );
    }

    #[Test]
    public function enable_never_prints_the_secret_and_never_logs_it(): void
    {
        $this->seedRegistry();
        $this->captureLogs();

        // Sortie BRUTE de la commande — on cherche une absence, il faut donc
        // le texte intégral, pas une assertion « contient ».
        $exitCode = $this->withoutMockingConsoleOutput()->artisan('oidc:witness:enable');
        self::assertSame(0, $exitCode);

        $output = \Illuminate\Support\Facades\Artisan::output();

        self::assertStringContainsString('Le client_secret n\'est PAS affiché', $output);

        $credentials = WitnessCredentials::load();
        self::assertNotNull($credentials);

        // Contrôle POSITIF : le secret existe bel et bien (32 octets hex).
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $credentials->clientSecret);

        // NFR3 — il n'est ni à l'écran, ni au journal.
        self::assertStringNotContainsString($credentials->clientSecret, $output);
        self::assertStringNotContainsString($credentials->clientSecret, $this->flattenedLogs());

        // …mais l'événement, lui, EST journalisé (sinon l'absence ne
        // prouverait qu'un journal muet).
        self::assertNotEmpty($this->logContextsOfType('oidc.witness.provisioned'));
    }

    #[Test]
    public function the_clear_secret_is_never_persisted_in_the_client_row(): void
    {
        $this->seedRegistry();
        $this->artisan('oidc:witness:enable')->assertExitCode(0);

        $credentials = WitnessCredentials::load();
        self::assertNotNull($credentials);

        $row = (array) DB::table('oidc_clients')->where('client_id', $credentials->clientId)->first();

        foreach ($row as $column => $value) {
            self::assertNotSame(
                $credentials->clientSecret,
                (string) $value,
                'le secret clair apparaît en base dans la colonne ' . $column,
            );
        }
    }

    #[Test]
    public function re_running_enable_is_a_signalled_no_op(): void
    {
        $this->seedRegistry();
        $this->artisan('oidc:witness:enable')->assertExitCode(0);

        $before = WitnessCredentials::load();
        self::assertNotNull($before);

        $this->artisan('oidc:witness:enable')
            ->expectsOutputToContain('déjà provisionnée')
            ->assertExitCode(0);

        $after = WitnessCredentials::load();

        self::assertNotNull($after);
        self::assertSame($before->clientId, $after->clientId, 'aucun nouveau client');
        self::assertSame($before->clientSecret, $after->clientSecret, 'le secret n\'a pas bougé');
        self::assertSame(1, OidcClient::query()->count());
    }

    #[Test]
    public function rotate_revokes_the_previous_client_and_regenerates_the_secret(): void
    {
        $this->seedRegistry();
        $this->artisan('oidc:witness:enable')->assertExitCode(0);

        $before = WitnessCredentials::load();
        self::assertNotNull($before);

        $this->artisan('oidc:witness:enable', ['--rotate' => true])->assertExitCode(0);

        $after = WitnessCredentials::load();
        self::assertNotNull($after);

        self::assertNotSame($before->clientId, $after->clientId);
        self::assertNotSame($before->clientSecret, $after->clientSecret);

        $registry = app(OidcClientRegistry::class);

        // L'ANCIEN client ne peut plus rien : c'est ce qui fait de `--rotate`
        // une vraie rotation et pas un simple doublon.
        self::assertNull($registry->authenticate($before->clientId, $before->clientSecret));
        self::assertNull($registry->findEnabledByClientId($before->clientId));

        // Révocation = désactivation, jamais suppression : la trace reste.
        self::assertSame(2, OidcClient::query()->count());

        // Contrôle POSITIF : le NOUVEAU, lui, authentifie.
        self::assertNotNull($registry->authenticate($after->clientId, $after->clientSecret));
    }

    #[Test]
    public function enable_refuses_when_the_extension_is_absent_from_the_registry_and_creates_nothing(): void
    {
        // Le lien `--extension` exige l'extension au registre : sans elle, le
        // client serait déclaré sans être rattaché à quoi que ce soit — et la
        // tuile n'existerait pas non plus. Échec BRUYANT, avec le remède.
        $this->artisan('oidc:witness:enable')
            ->expectsOutputToContain('BundledExtensionSeeder')
            ->assertExitCode(1);

        self::assertSame(0, OidcClient::query()->count());
        self::assertFileDoesNotExist($this->credentialsPath);
    }

    #[Test]
    public function enable_refuses_to_repair_an_incoherent_state_silently(): void
    {
        // Fichier présent, client révoqué (par exemple `oidc:client:revoke`
        // joué à la main) : réenregistrer en douce masquerait la cause. On
        // échoue en nommant le remède.
        $this->seedRegistry();
        $this->artisan('oidc:witness:enable')->assertExitCode(0);

        $credentials = WitnessCredentials::load();
        self::assertNotNull($credentials);
        app(OidcClientRegistry::class)->revoke($credentials->clientId);

        $this->artisan('oidc:witness:enable')
            ->expectsOutputToContain('--rotate')
            ->assertExitCode(1);

        self::assertSame(1, OidcClient::query()->count(), 'aucun client fantôme');

        // Contrôle POSITIF : `--rotate`, lui, répare — c'est bien le remède
        // annoncé qui fonctionne.
        $this->artisan('oidc:witness:enable', ['--rotate' => true])->assertExitCode(0);
        self::assertSame(2, OidcClient::query()->count());
    }

    // =====================================================================
    // disable
    // =====================================================================

    #[Test]
    public function disable_revokes_the_client_and_removes_the_file(): void
    {
        $this->seedRegistry();
        $this->artisan('oidc:witness:enable')->assertExitCode(0);

        $credentials = WitnessCredentials::load();
        self::assertNotNull($credentials);

        $this->artisan('oidc:witness:disable')->assertExitCode(0);

        self::assertFileDoesNotExist($this->credentialsPath);
        self::assertNull(app(OidcClientRegistry::class)->findEnabledByClientId($credentials->clientId));
        self::assertFalse(OidcClient::query()->firstOrFail()->enabled);
    }

    #[Test]
    public function disable_is_idempotent(): void
    {
        $this->seedRegistry();
        $this->artisan('oidc:witness:enable')->assertExitCode(0);
        $this->artisan('oidc:witness:disable')->assertExitCode(0);

        $this->artisan('oidc:witness:disable')
            ->expectsOutputToContain('déjà retirée')
            ->assertExitCode(0);
    }

    #[Test]
    public function disable_on_a_never_provisioned_instance_is_a_signalled_no_op(): void
    {
        $this->artisan('oidc:witness:disable')
            ->expectsOutputToContain('déjà retirée')
            ->assertExitCode(0);

        self::assertSame(0, OidcClient::query()->count());
    }

    #[Test]
    public function disable_cleans_up_an_unreadable_credentials_file(): void
    {
        // Sans ce nettoyage, un fichier corrompu bloquerait `enable`
        // indéfiniment (il le verrait « non provisionné » mais échouerait à
        // l'écriture d'un état cohérent).
        file_put_contents($this->credentialsPath, '{ ceci n\'est pas du JSON');

        $this->artisan('oidc:witness:disable')
            ->expectsOutputToContain('illisible')
            ->assertExitCode(0);

        self::assertFileDoesNotExist($this->credentialsPath);
    }

    #[Test]
    public function the_witness_credentials_file_is_rejected_when_a_required_field_is_missing(): void
    {
        // Fail-closed : un fichier partiel n'est pas « à moitié provisionné »,
        // il est inexploitable. Le témoin doit le voir comme tel.
        file_put_contents($this->credentialsPath, json_encode([
            'client_id' => 'abc',
            'issuer' => 'https://se5.test',
            'redirect_uri' => '/sso-demo/callback',
            // `client_secret` manquant
        ]));

        self::assertTrue(WitnessCredentials::isProvisioned(), 'le fichier existe');
        self::assertNull(WitnessCredentials::load(), 'mais il est inexploitable');
    }
}

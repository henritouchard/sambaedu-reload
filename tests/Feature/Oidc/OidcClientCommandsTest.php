<?php

declare(strict_types=1);

namespace Tests\Feature\Oidc;

use App\Auth\Oidc\Services\OidcClientRegistry;
use App\Models\Extension;
use App\Models\ExtensionSource;
use App\Models\OidcClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 55.1 — **AC1/AC3** : les commandes de gestion des clients confidentiels.
 *
 * En 55.1, l'artisan est le SEUL canal d'enregistrement (le provisioning
 * automatique à l'installation d'une extension `app` arrive avec l'Epic 56, et
 * s'accrochera au même `OidcClientRegistry::register()`).
 *
 * ⚠️ L'invariant NFR3 le plus important est vérifié ici : **le secret clair
 * n'est nulle part en base**. Il est affiché une fois, puis n'existe plus que
 * chez l'intégrateur.
 */
class OidcClientCommandsTest extends TestCase
{
    use RefreshDatabase;

    // ── register ──────────────────────────────────────────────────────────

    #[Test]
    public function register_displays_the_secret_once_and_stores_only_its_hash(): void
    {
        $this->artisan('oidc:client:register', [
            'name' => 'App témoin',
            '--redirect-uri' => ['https://temoin.example.test/callback'],
        ])->assertExitCode(0);

        $client = OidcClient::query()->firstOrFail();

        self::assertSame('App témoin', $client->name);
        self::assertTrue($client->enabled);
        self::assertSame(['https://temoin.example.test/callback'], $client->redirectUris());
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $client->client_id);

        // Le hash stocké est bien un sha256 (64 hex), pas un secret déguisé.
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $client->client_secret_hash);

        // ⚠️ NFR3 : le hash ne sort JAMAIS d'une sérialisation du modèle.
        self::assertArrayNotHasKey('client_secret_hash', $client->toArray());
    }

    #[Test]
    public function the_clear_secret_is_never_persisted_anywhere_in_the_row(): void
    {
        // On passe par le service pour CONNAÎTRE le clair, puis on le cherche
        // dans la ligne brute : c'est la seule façon de prouver l'absence.
        $result = app(OidcClientRegistry::class)->register('Test', ['https://a.example.test/cb']);

        $row = (array) DB::table('oidc_clients')->where('client_id', $result['client_id'])->first();

        foreach ($row as $column => $value) {
            self::assertNotSame(
                $result['client_secret'],
                (string) $value,
                'le secret clair apparaît en base dans la colonne '.$column,
            );
        }

        // Contrôle POSITIF : le secret rendu est bien celui qui authentifie.
        self::assertNotNull(
            app(OidcClientRegistry::class)->authenticate($result['client_id'], $result['client_secret']),
        );
        self::assertNull(
            app(OidcClientRegistry::class)->authenticate($result['client_id'], $result['client_secret'].'x'),
        );
    }

    #[Test]
    public function register_binds_the_client_to_a_known_extension(): void
    {
        $source = ExtensionSource::factory()->bundled()->create();
        $extension = Extension::factory()->create([
            'extension_source_id' => $source->id,
            'key' => 'doc',
            'name' => 'Documentation',
        ]);

        $this->artisan('oidc:client:register', [
            'name' => 'Documentation',
            '--redirect-uri' => ['https://doc.example.test/callback'],
            '--extension' => 'doc',
        ])->assertExitCode(0);

        $client = OidcClient::query()->firstOrFail();

        self::assertSame($extension->id, $client->extension_id);
        // La clé DÉNORMALISÉE survivra à la suppression de l'extension
        // (patron du journal d'audit 54.2).
        self::assertSame('doc', $client->extension_key);
    }

    #[Test]
    public function register_refuses_an_unknown_extension_and_creates_nothing(): void
    {
        $this->artisan('oidc:client:register', [
            'name' => 'Fantôme',
            '--redirect-uri' => ['https://fantome.example.test/callback'],
            '--extension' => 'extension-inexistante',
        ])->assertExitCode(1);

        self::assertSame(0, OidcClient::query()->count());
    }

    #[Test]
    public function register_refuses_a_client_without_any_redirect_uri(): void
    {
        // Sans URI déclarée, aucun flux ne pourrait aboutir : mieux vaut
        // échouer à la déclaration qu'au premier SSO d'un utilisateur.
        $this->artisan('oidc:client:register', ['name' => 'Sans retour'])->assertExitCode(1);

        self::assertSame(0, OidcClient::query()->count());
    }

    #[Test]
    public function register_refuses_dangerous_redirect_uri_schemes(): void
    {
        // Précédent : correctif `entry_url` de la review 54.3. Une URI
        // `javascript:` ou `//hôte` placée dans un `Location:` détournerait
        // l'utilisateur — et le code d'autorisation avec lui.
        foreach (['javascript:alert(1)', 'data:text/html,<script>', '//evil.example/cb'] as $dangerous) {
            $this->artisan('oidc:client:register', [
                'name' => 'Malveillant',
                '--redirect-uri' => [$dangerous],
            ])->assertExitCode(1);
        }

        self::assertSame(0, OidcClient::query()->count());

        // Contrôle POSITIF : les formes légitimes passent toujours.
        $this->artisan('oidc:client:register', [
            'name' => 'Légitime',
            '--redirect-uri' => ['https://ok.example.test/cb', '/callback-interne'],
        ])->assertExitCode(0);

        self::assertSame(
            ['https://ok.example.test/cb', '/callback-interne'],
            OidcClient::query()->firstOrFail()->redirectUris(),
        );
    }

    #[Test]
    public function the_registry_rejects_a_url_without_a_host(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(OidcClientRegistry::class)->register('Bancal', ['https:///chemin-sans-hote']);
    }

    #[Test]
    public function the_registry_refuses_a_redirect_uri_longer_than_the_column_that_will_store_it(): void
    {
        // Correctif review 55.1 (#3). L'URI validée est RECOPIÉE dans
        // `oidc_authorization_codes.redirect_uri` (VARCHAR 512) à chaque
        // émission de code. Sans borne ici, un client accepté à
        // l'enregistrement échouerait à CHAQUE flux sur une `QueryException`
        // PostgreSQL → 500 générique, hors du journal `oidc` (FR20 non tenu).
        //
        // ⚠️ Ce test assert le comportement APPLICATIF, jamais la contrainte
        // SQL : SQLite (driver de toute la suite) n'applique aucune limite de
        // longueur sur un VARCHAR. Une assertion sur l'insertion ne prouverait
        // donc rien ici et masquerait la divergence au lieu de la révéler.
        $tooLong = 'https://ext.example.test/callback?jeton='
            .str_repeat('a', OidcClientRegistry::MAX_REDIRECT_URI_LENGTH);

        self::assertGreaterThan(OidcClientRegistry::MAX_REDIRECT_URI_LENGTH, mb_strlen($tooLong));

        $this->artisan('oidc:client:register', [
            'name' => 'URI démesurée',
            '--redirect-uri' => [$tooLong],
        ])->assertExitCode(1);

        self::assertSame(0, OidcClient::query()->count(), 'aucun client déclaré');

        // Contrôle POSITIF : une URI JUSTE en dessous de la borne passe — sans
        // lui, le refus ci-dessus pourrait n'être que le symptôme d'une
        // plomberie cassée et ne rien démontrer.
        $atLimit = 'https://ext.example.test/cb?j='
            .str_repeat('a', OidcClientRegistry::MAX_REDIRECT_URI_LENGTH - mb_strlen('https://ext.example.test/cb?j='));

        self::assertSame(OidcClientRegistry::MAX_REDIRECT_URI_LENGTH, mb_strlen($atLimit));

        $this->artisan('oidc:client:register', [
            'name' => 'URI à la limite',
            '--redirect-uri' => [$atLimit],
        ])->assertExitCode(0);

        self::assertSame([$atLimit], OidcClient::query()->firstOrFail()->redirectUris());
    }

    // ── revoke ────────────────────────────────────────────────────────────

    #[Test]
    public function revoke_disables_the_client_and_is_idempotent(): void
    {
        $result = app(OidcClientRegistry::class)->register('À révoquer', ['https://a.example.test/cb']);
        $clientId = $result['client_id'];

        $this->artisan('oidc:client:revoke', ['client_id' => $clientId])->assertExitCode(0);

        $client = OidcClient::query()->where('client_id', $clientId)->firstOrFail();
        self::assertFalse($client->enabled);

        // Révocation = DÉSACTIVATION, pas suppression : la trace reste.
        self::assertSame(1, OidcClient::query()->count());

        // Rejouable sans erreur (doctrine ops).
        $this->artisan('oidc:client:revoke', ['client_id' => $clientId])
            ->expectsOutputToContain('déjà révoqué')
            ->assertExitCode(0);
    }

    #[Test]
    public function revoking_an_unknown_client_fails_loudly(): void
    {
        // Échouer silencieusement laisserait croire à une révocation qui n'a
        // pas eu lieu — sur une faute de frappe, c'est un faux sentiment de
        // sécurité.
        $this->artisan('oidc:client:revoke', ['client_id' => 'inconnu'])->assertExitCode(1);
    }

    #[Test]
    public function a_revoked_client_can_no_longer_authenticate(): void
    {
        $registry = app(OidcClientRegistry::class);
        $result = $registry->register('À révoquer', ['https://a.example.test/cb']);

        // Contrôle POSITIF avant révocation.
        self::assertNotNull($registry->authenticate($result['client_id'], $result['client_secret']));

        $registry->revoke($result['client_id']);

        self::assertNull($registry->authenticate($result['client_id'], $result['client_secret']));
        self::assertNull($registry->findEnabledByClientId($result['client_id']));
    }
}

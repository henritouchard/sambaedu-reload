<?php

declare(strict_types=1);

namespace Tests\Feature\OidcWitness;

use App\Http\Middleware\Auth\AuditExternalAction;
use App\Http\Middleware\Auth\SambaEduAuth;
use App\Models\OidcClient;
use App\Models\User;
use App\Models\UserGroup;
use App\Observers\UserGroupObserver;
use Database\Factories\OidcClientFactory;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Oidc\Concerns\UsesOidcTestKeys;
use Tests\TestCase;

/**
 * Story 55.3 — **AC3, volet fonctionnel** : *aucun identifiant de base ni
 * d'annuaire ne fuit dans le canal des extensions* (FR24).
 *
 * Les tests de 55.2 vérifient la LISTE EXACTE des clés de claims. Celui-ci
 * vérifie autre chose, et c'est complémentaire : que les VALEURS internes
 * (`users.id`, `ad_guid`, `dn`) ne se sont glissées **nulle part** dans les
 * octets réellement servis — ni sous un nom de claim auquel personne n'a pensé,
 * ni dans le `sub`, ni dans une clé technique de la réponse.
 *
 * On inspecte donc le **payload brut décodé du JWT** et le **corps JSON brut de
 * `/userinfo`**, pas un tableau de claims déjà filtré : c'est la seule façon de
 * prouver une absence sur ce qui part sur le fil.
 *
 * ⚠️ Ce fichier est NOUVEAU et n'affaiblit aucun test existant : les assertions
 * de 55.2 restent en place et gardent leur objet propre.
 */
class ExtensionIdentityLeakTest extends TestCase
{
    use RefreshDatabase;
    use UsesOidcTestKeys;

    private const REDIRECT_URI = 'https://ext.example.test/callback';

    private const VERIFIER = 'verifier-0123456789-0123456789-0123456789-abcdef';

    /** L'`objectGUID` AD du compte de test — la valeur qu'on traque. */
    private const AD_GUID = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';

    /** Son `dn` d'annuaire — l'autre valeur qu'on traque. */
    private const AD_DN = 'CN=Professeur Dupont,OU=Profs,DC=college,DC=test';

    protected function setUp(): void
    {
        parent::setUp();

        $this->useOidcTestKeys();
        (new PermissionSeeder())->run();
        UserGroupObserver::disableSync();

        $this->withoutMiddleware([
            SambaEduAuth::class,
            AuditExternalAction::class,
        ]);
    }

    protected function tearDown(): void
    {
        UserGroupObserver::enableSync();

        parent::tearDown();
    }

    // ── Fixtures ──────────────────────────────────────────────────────────

    private function makeUser(): User
    {
        $user = User::query()->create([
            'login' => 'prof.dupont',
            'fullname' => 'Professeur Dupont',
            'role' => 'prof',
            'source' => 'ad',
            'is_active' => true,
            'dn' => self::AD_DN,
            'ad_guid' => self::AD_GUID,
        ]);

        $group = UserGroup::query()->create(['name' => '4B', 'type' => 'classe']);
        $user->userGroups()->attach($group->id);

        return $user;
    }

    private static function challengeFor(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    /**
     * Déroule le flux complet et rend le corps BRUT de la réponse du token
     * endpoint.
     *
     * @return array<string, mixed>
     */
    private function tokenResponse(User $user, OidcClient $client): array
    {
        $authorize = $this->actingAs($user)->get('/oidc/authorize?' . http_build_query([
            'response_type' => 'code',
            'client_id' => $client->client_id,
            'redirect_uri' => self::REDIRECT_URI,
            'scope' => 'openid profile groups',
            'state' => 'state-xyz',
            'code_challenge' => self::challengeFor(self::VERIFIER),
            'code_challenge_method' => 'S256',
            'nonce' => 'nonce-abc',
        ]));

        $authorize->assertStatus(302);
        parse_str((string) parse_url((string) $authorize->headers->get('Location'), PHP_URL_QUERY), $query);

        return (array) $this->post('/oidc/token', [
            'grant_type' => 'authorization_code',
            'code' => (string) $query['code'],
            'redirect_uri' => self::REDIRECT_URI,
            'code_verifier' => self::VERIFIER,
        ], [
            'Authorization' => 'Basic ' . base64_encode($client->client_id . ':' . OidcClientFactory::DEFAULT_SECRET),
        ])->assertOk()->json();
    }

    /** Le payload BRUT (segment 2 du JWT), décodé sans filtre. */
    private static function rawPayload(string $jwt): string
    {
        $segments = explode('.', $jwt);
        self::assertCount(3, $segments, 'un JWT a trois segments');

        $padded = str_pad(strtr($segments[1], '-_', '+/'), (int) (ceil(strlen($segments[1]) / 4) * 4), '=');

        return (string) base64_decode($padded, true);
    }

    // =====================================================================
    // AC3 — la capacité négative, vérifiée sur les octets servis
    // =====================================================================

    #[Test]
    public function neither_the_id_token_nor_userinfo_ever_carry_a_database_or_directory_identifier(): void
    {
        $user = $this->makeUser();
        $client = OidcClient::factory()->withRedirectUris(self::REDIRECT_URI)->create();

        $body = $this->tokenResponse($user, $client);

        $idTokenPayload = self::rawPayload((string) $body['id_token']);

        $userinfo = $this->get('/oidc/userinfo', [
            'Authorization' => 'Bearer ' . $body['access_token'],
        ])->assertOk();

        $userinfoBody = (string) $userinfo->getContent();

        // ── CONTRÔLE POSITIF d'abord ─────────────────────────────────────
        // Sans lui, les absences ci-dessous pourraient n'être que le symptôme
        // d'un flux cassé qui ne sert rien du tout.
        foreach ([$idTokenPayload, $userinfoBody] as $served) {
            self::assertStringContainsString('Professeur Dupont', $served, 'contrôle positif : le `name` EST servi');
            self::assertStringContainsString('4B', $served, 'contrôle positif : les `groups` SONT servis');
            self::assertStringContainsString('prof.dupont', $served, 'contrôle positif : le `sub` EST servi');
        }

        // ── Les absences ─────────────────────────────────────────────────
        foreach ([$idTokenPayload, $userinfoBody] as $served) {
            self::assertStringNotContainsString(self::AD_GUID, $served, 'l\'objectGUID AD a fui');
            self::assertStringNotContainsString('aaaaaaaa-bbbb', $served, 'un fragment d\'objectGUID a fui');
            self::assertStringNotContainsString('OU=Profs', $served, 'le `dn` d\'annuaire a fui');
            self::assertStringNotContainsString('DC=college', $served, 'le `dn` d\'annuaire a fui');

            foreach (['ad_guid', 'objectGUID', '"dn"', 'memberOf', 'distinguishedName', 'sAMAccountName'] as $attribute) {
                self::assertStringNotContainsString($attribute, $served, 'attribut d\'annuaire exposé : ' . $attribute);
            }
        }
    }

    #[Test]
    public function the_subject_is_the_login_and_never_the_database_primary_key(): void
    {
        // `sub` est le pivot d'identité de TOUTE extension. S'il portait
        // `users.id`, il exposerait une clé interne ET deviendrait instable au
        // premier réimport de comptes.
        $user = $this->makeUser();
        $client = OidcClient::factory()->withRedirectUris(self::REDIRECT_URI)->create();

        $body = $this->tokenResponse($user, $client);

        $claims = json_decode(self::rawPayload((string) $body['id_token']), true);
        self::assertIsArray($claims);

        self::assertSame('prof.dupont', $claims['sub']);
        self::assertNotSame((string) $user->id, (string) $claims['sub']);

        // Et `/userinfo` rend le MÊME sujet — l'égalité est garantie par
        // construction (55.2), on la vérifie ici depuis le canal extensions.
        $userinfo = $this->get('/oidc/userinfo', [
            'Authorization' => 'Bearer ' . $body['access_token'],
        ])->assertOk()->json();

        self::assertSame($claims['sub'], $userinfo['sub']);
    }

    #[Test]
    public function an_extension_never_receives_the_internal_row_identifiers_of_its_own_client(): void
    {
        // Corollaire moins évident : le canal ne doit pas non plus divulguer
        // les clés internes du REGISTRE (id de ligne `oidc_clients`,
        // `extension_id`). L'extension connaît son `client_id` opaque, et rien
        // d'autre.
        $user = $this->makeUser();
        $client = OidcClient::factory()->withRedirectUris(self::REDIRECT_URI)->create();

        $body = $this->tokenResponse($user, $client);
        $served = self::rawPayload((string) $body['id_token']) . json_encode($body);

        // Contrôle positif : le `client_id` PUBLIC, lui, est bien là (`aud`).
        self::assertStringContainsString($client->client_id, $served);

        foreach (['oidc_client_id', 'extension_id', 'client_secret_hash', 'token_hash', 'code_hash'] as $internal) {
            self::assertStringNotContainsString($internal, $served, 'clé interne exposée : ' . $internal);
        }
    }
}

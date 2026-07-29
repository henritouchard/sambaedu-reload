<?php

declare(strict_types=1);

namespace Tests\Feature\Oidc;

use App\Auth\Oidc\Services\OidcClientRegistry;
use App\Http\Middleware\Auth\AuditExternalAction;
use App\Http\Middleware\Auth\SambaEduAuth;
use App\Models\OidcAccessToken;
use App\Models\OidcClient;
use App\Models\User;
use App\Models\UserGroup;
use App\Observers\UserGroupObserver;
use Database\Factories\OidcClientFactory;
use Database\Seeders\PermissionSeeder;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Oidc\Concerns\UsesOidcTestKeys;
use Tests\TestCase;

/**
 * Story 56.4 — **AC1 et AC2** : les scopes ACCORDÉS, et le downscope.
 *
 * ══════════════════════════════════════════════════════════════════════════
 *  CE QUE CE FICHIER PROTÈGE
 *
 *  Une révocation qui ne mordrait que sur les NOUVEAUX jetons serait une
 *  fausse promesse : l'extension continuerait de lire les groupes d'un élève
 *  pendant les 600 s du jeton en cours, et personne ne le verrait. La
 *  propriété centrale de la story est donc « effet IMMÉDIAT sur les jetons
 *  déjà émis, sans purge » — obtenue en recalculant le scope effectif à chaque
 *  usage, en UN point unique.
 *
 *  Chaque refus est adossé à un CONTRÔLE POSITIF joué juste avant : sans lui,
 *  un `/userinfo` cassé produirait exactement les mêmes assertions négatives.
 * ══════════════════════════════════════════════════════════════════════════
 *
 * ⚠️ Bypass du guard `sambaedu.auth` : patron 55.1/55.2.
 */
class OidcGrantedScopesTest extends TestCase
{
    use RefreshDatabase;
    use UsesOidcTestKeys;

    private const REDIRECT_URI = 'https://ext.example.test/callback';

    private const VERIFIER = 'verifier-0123456789-0123456789-0123456789-abcdef';

    /** Les 7 claims standards de l'id_token quand un `nonce` est demandé. */
    private const STANDARD_CLAIMS = ['aud', 'exp', 'iat', 'iss', 'jti', 'nonce', 'sub'];

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

    private function makeProf(): User
    {
        $user = User::query()->create([
            'login' => 'prof.dupont',
            'fullname' => 'Professeur Dupont',
            'role' => 'prof',
            'source' => 'ad',
            'is_active' => true,
        ]);

        foreach (['4B' => 'classe', 'TechnoCollege' => 'equipe'] as $name => $type) {
            $group = UserGroup::query()->create(['name' => $name, 'type' => $type]);
            $user->userGroups()->attach($group->id);
        }

        return $user;
    }

    /** @param list<string>|null $grantedScopes `null` ⇒ défaut factory (tout accordé). */
    private function makeClient(?array $grantedScopes = null): OidcClient
    {
        $factory = OidcClient::factory()->withRedirectUris(self::REDIRECT_URI);

        if ($grantedScopes !== null) {
            $factory = $factory->grantedScopes($grantedScopes);
        }

        return $factory->create();
    }

    private static function challengeFor(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    private function obtainCode(OidcClient $client, User $user, string $scope): string
    {
        $response = $this->actingAs($user)->get('/oidc/authorize?'.http_build_query([
            'response_type' => 'code',
            'client_id' => $client->client_id,
            'redirect_uri' => self::REDIRECT_URI,
            'scope' => $scope,
            'state' => 'state-xyz',
            'code_challenge' => self::challengeFor(self::VERIFIER),
            'code_challenge_method' => 'S256',
            'nonce' => 'nonce-abc',
        ]));

        $response->assertStatus(302);
        parse_str((string) parse_url((string) $response->headers->get('Location'), PHP_URL_QUERY), $query);

        self::assertArrayHasKey('code', $query, 'La redirection nominale doit porter un `code`.');

        return (string) $query['code'];
    }

    private function exchange(OidcClient $client, string $code)
    {
        return $this->post('/oidc/token', [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => self::REDIRECT_URI,
            'code_verifier' => self::VERIFIER,
        ], [
            'Authorization' => 'Basic '.base64_encode($client->client_id.':'.OidcClientFactory::DEFAULT_SECRET),
        ]);
    }

    /**
     * Les clés d'un payload, TRIÉES — l'ordre des claims dans un JWT n'est pas
     * un contrat, leur ENSEMBLE l'est (patron `OidcIdTokenClaimsTest`).
     *
     * @param  array<string, mixed>  $payload
     * @return list<string>
     */
    private static function sortedKeys(array $payload): array
    {
        $keys = array_keys($payload);
        sort($keys);

        return $keys;
    }

    /**
     * La liste EXACTE attendue : les 7 claims standards + les claims métier
     * annoncés, triés.
     *
     * @param  list<string>  $business
     * @return list<string>
     */
    private static function expectedKeys(array $business): array
    {
        $keys = array_values(array_unique(array_merge(self::STANDARD_CLAIMS, $business)));
        sort($keys);

        return $keys;
    }

    /** @return array<string, mixed> Claims DÉCODÉS et VÉRIFIÉS de l'id_token. */
    private function decode(string $idToken): array
    {
        return (array) JWT::decode($idToken, new Key($this->testPublicKeyPem(), 'RS256'));
    }

    // ══════════════════════════════════════════════════════════════════════
    // AC2 — le downscope à l'ÉMISSION
    // ══════════════════════════════════════════════════════════════════════

    /**
     * CONTRÔLE POSITIF, joué en tête : un client pleinement consenti obtient
     * exactement ce qu'il demande. Sans lui, tous les downscopes ci-dessous
     * pourraient n'être que le symptôme d'une émission cassée.
     */
    #[Test]
    public function a_fully_granted_client_receives_the_scope_it_asked_for(): void
    {
        $user = $this->makeProf();
        $client = $this->makeClient(['profile', 'groups']);

        $response = $this->exchange($client, $this->obtainCode($client, $user, 'openid profile groups'));

        $response->assertOk();
        self::assertSame('openid profile groups', $response->json('scope'));

        $claims = $this->decode((string) $response->json('id_token'));

        self::assertSame(
            self::expectedKeys(['groups', 'name', 'role']),
            self::sortedKeys($claims),
            'Le client pleinement consenti reçoit les trois claims métier.',
        );

        self::assertSame('openid profile groups', OidcAccessToken::query()->first()?->scope);
    }

    #[Test]
    public function a_partially_granted_client_is_downscoped_and_told_so(): void
    {
        $user = $this->makeProf();
        $client = $this->makeClient(['profile']);

        $response = $this->exchange($client, $this->obtainCode($client, $user, 'openid profile groups'));

        $response->assertOk();

        // RFC 6749 §3.3 : la réduction est ANNONCÉE. Ce n'est pas une ignorance
        // silencieuse — c'est le canal par lequel l'extension apprend ce
        // qu'elle a réellement.
        self::assertSame('openid profile', $response->json('scope'));

        $claims = $this->decode((string) $response->json('id_token'));

        self::assertSame(
            self::expectedKeys(['name', 'role']),
            self::sortedKeys($claims),
            'Liste EXACTE : `groups` ne doit apparaître nulle part dans l\'id_token.',
        );

        // Le jeton persiste le scope EFFECTIF : ce qu'il ouvre, pas ce qui a
        // été demandé.
        self::assertSame('openid profile', OidcAccessToken::query()->first()?->scope);
    }

    #[Test]
    public function a_client_without_any_grant_receives_the_subject_and_nothing_else(): void
    {
        $user = $this->makeProf();
        $client = $this->makeClient([]);

        $response = $this->exchange($client, $this->obtainCode($client, $user, 'openid profile groups'));

        $response->assertOk();
        self::assertSame('openid', $response->json('scope'));

        $claims = $this->decode((string) $response->json('id_token'));

        self::assertSame(
            self::expectedKeys([]),
            self::sortedKeys($claims),
            'Fail-closed : aucun claim métier sans octroi.',
        );

        // Le SSO fonctionne quand même — c'est tout l'intérêt de réduire
        // plutôt que de refuser : l'utilisateur se connecte, l'extension
        // n'apprend simplement rien de lui.
        self::assertSame($user->login, $claims['sub']);
    }

    /**
     * NON-RÉGRESSION 55.2 : le fail-closed vise le scope INCONNU, qui reste
     * refusé à l'autorisation. Le non-accordé, lui, est réduit. Confondre les
     * deux transformerait chaque révocation en panne de SSO.
     */
    #[Test]
    public function an_unknown_scope_is_still_refused_at_authorization(): void
    {
        $user = $this->makeProf();
        $client = $this->makeClient(['profile', 'groups']);

        $response = $this->actingAs($user)->get('/oidc/authorize?'.http_build_query([
            'response_type' => 'code',
            'client_id' => $client->client_id,
            'redirect_uri' => self::REDIRECT_URI,
            'scope' => 'openid profile calendar',
            'state' => 'state-xyz',
            'code_challenge' => self::challengeFor(self::VERIFIER),
            'code_challenge_method' => 'S256',
        ]));

        $response->assertStatus(302);
        parse_str((string) parse_url((string) $response->headers->get('Location'), PHP_URL_QUERY), $query);

        self::assertSame('invalid_scope', $query['error'] ?? null);
        self::assertArrayNotHasKey('code', $query);
    }

    // ══════════════════════════════════════════════════════════════════════
    // AC2 — L'EFFET IMMÉDIAT SUR UN JETON DÉJÀ ÉMIS
    // ══════════════════════════════════════════════════════════════════════

    /**
     * LE test de la story : le jeton est émis AVANT la révocation, il n'est ni
     * purgé ni ré-émis, et pourtant `/userinfo` cesse de servir `groups`.
     */
    #[Test]
    public function revoking_a_scope_takes_effect_on_a_token_already_issued(): void
    {
        $user = $this->makeProf();
        $client = $this->makeClient(['profile', 'groups']);

        $token = (string) $this->exchange($client, $this->obtainCode($client, $user, 'openid profile groups'))
            ->json('access_token');

        // ── Contrôle POSITIF : avant révocation, `groups` est bien servi ──
        $before = $this->getJson('/oidc/userinfo', ['Authorization' => 'Bearer '.$token]);
        $before->assertOk();
        self::assertSame(['4B', 'TechnoCollege'], $before->json('groups'));
        self::assertSame('Professeur Dupont', $before->json('name'));

        // ── La révocation : une écriture en base, rien d'autre ────────────
        app(OidcClientRegistry::class)->revokeScope((string) $client->client_id, 'groups');

        // Le jeton est TOUJOURS le même, TOUJOURS valide, jamais purgé.
        self::assertNotNull(OidcAccessToken::query()->first());

        $after = $this->getJson('/oidc/userinfo', ['Authorization' => 'Bearer '.$token]);
        $after->assertOk();

        $keys = array_keys((array) $after->json());
        sort($keys);
        self::assertSame(['name', 'role', 'sub'], $keys, '`groups` doit avoir disparu SANS emporter le reste.');

        // Le scope STOCKÉ n'a pas bougé : c'est bien l'intersection qui agit,
        // pas une réécriture du jeton.
        self::assertSame('openid profile groups', OidcAccessToken::query()->first()?->scope);
    }

    #[Test]
    public function a_new_authorization_after_revocation_yields_a_reduced_token(): void
    {
        $user = $this->makeProf();
        $client = $this->makeClient(['profile', 'groups']);

        app(OidcClientRegistry::class)->revokeScope((string) $client->client_id, 'groups');

        // Le flux ABOUTIT (pas d'`invalid_scope`) mais le jeton est réduit.
        $response = $this->exchange($client, $this->obtainCode($client, $user, 'openid profile groups'));

        $response->assertOk();
        self::assertSame('openid profile', $response->json('scope'));
        self::assertArrayNotHasKey('groups', $this->decode((string) $response->json('id_token')));
    }

    // ══════════════════════════════════════════════════════════════════════
    // AC1/AC2 — le registre : octroi fermé, révocation idempotente
    // ══════════════════════════════════════════════════════════════════════

    #[Test]
    public function the_registry_persists_granted_scopes_normalized(): void
    {
        $result = app(OidcClientRegistry::class)->register(
            'Client de test',
            [self::REDIRECT_URI],
            null,
            ['groups', 'profile', 'groups'],
        );

        self::assertSame(['groups', 'profile'], $result['client']->grantedScopes());
    }

    #[Test]
    public function the_registry_refuses_a_grant_outside_the_closed_vocabulary(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(OidcClientRegistry::class)->register('Client hostile', [self::REDIRECT_URI], null, ['profile', 'directory']);
    }

    /**
     * `openid` est le PLANCHER du protocole : ni accordable, ni révocable.
     * L'accepter à l'octroi laisserait croire qu'on pourrait le retirer.
     */
    #[Test]
    public function openid_can_never_be_granted_explicitly(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(OidcClientRegistry::class)->register('Client', [self::REDIRECT_URI], null, ['openid']);
    }

    #[Test]
    public function revoking_openid_is_refused_and_leaves_the_grants_untouched(): void
    {
        $client = $this->makeClient(['profile', 'groups']);

        $result = app(OidcClientRegistry::class)->revokeScope((string) $client->client_id, 'openid');

        self::assertFalse($result['changed']);
        self::assertSame(['groups', 'profile'], $client->fresh()?->grantedScopes());
    }

    #[Test]
    public function revoking_a_scope_is_idempotent(): void
    {
        $client = $this->makeClient(['profile', 'groups']);
        $registry = app(OidcClientRegistry::class);

        $first = $registry->revokeScope((string) $client->client_id, 'groups');
        $second = $registry->revokeScope((string) $client->client_id, 'groups');

        self::assertSame(['found' => true, 'changed' => true], $first);
        self::assertSame(['found' => true, 'changed' => false], $second);
        self::assertSame(['profile'], $client->fresh()?->grantedScopes());
    }

    #[Test]
    public function revoking_a_scope_of_an_unknown_client_is_reported_as_not_found(): void
    {
        self::assertSame(
            ['found' => false, 'changed' => false],
            app(OidcClientRegistry::class)->revokeScope('client-inexistant', 'groups'),
        );
    }

    /**
     * Le point UNIQUE, exercé directement : c'est la règle que trois chemins
     * partagent, elle mérite ses propres cas limites.
     */
    #[Test]
    public function the_effective_scope_is_the_intersection_with_openid_as_a_floor(): void
    {
        $client = $this->makeClient(['profile']);

        self::assertSame('openid profile', $client->effectiveScopeFor('openid profile groups'));
        self::assertSame('openid', $client->effectiveScopeFor('openid groups'));
        // L'ordre du scope DEMANDÉ est préservé — un client pleinement consenti
        // ne voit aucune différence avec ce qu'il a demandé.
        self::assertSame('profile openid', $client->effectiveScopeFor('profile openid'));
        // Doublons et espaces multiples : normalisés sans invention.
        self::assertSame('openid profile', $client->effectiveScopeFor('openid  profile openid'));
    }
}

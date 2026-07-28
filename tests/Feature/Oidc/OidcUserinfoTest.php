<?php

declare(strict_types=1);

namespace Tests\Feature\Oidc;

use App\Auth\Oidc\Support\OidcErrorCodes;
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
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Oidc\Concerns\CapturesOidcLogs;
use Tests\Feature\Oidc\Concerns\UsesOidcTestKeys;
use Tests\TestCase;

/**
 * Story 55.2 — **AC3** : `GET|POST /oidc/userinfo`, conforme et fail-closed.
 *
 * ══════════════════════════════════════════════════════════════════════════
 *  L'ORDRE DE CE FICHIER EST DÉLIBÉRÉ (patron `OidcAuthorizeRefusalsTest`)
 *
 *  Le contrôle POSITIF vient d'abord — le flux complet, de `/oidc/authorize`
 *  jusqu'à `/oidc/userinfo`, avec un jeton réellement émis. Sans lui, tous les
 *  401 qui suivent pourraient n'être que le symptôme d'une route cassée : un
 *  endpoint qui répond 401 à TOUT passerait haut la main une suite de tests de
 *  refus. Le fail-closed se PROUVE, il ne s'affirme pas.
 * ══════════════════════════════════════════════════════════════════════════
 *
 * Les cinq causes de refus (absent / inconnu / expiré / client révoqué /
 * utilisateur disparu) sont vérifiées INDISTINCTES en réponse et DISTINCTES au
 * journal : c'est la doctrine « pas d'oracle » du token endpoint, appliquée
 * aux jetons.
 */
class OidcUserinfoTest extends TestCase
{
    use CapturesOidcLogs;
    use RefreshDatabase;
    use UsesOidcTestKeys;

    private const REDIRECT_URI = 'https://ext.example.test/callback';

    private const VERIFIER = 'verifier-0123456789-0123456789-0123456789-abcdef';

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
        Carbon::setTestNow();

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

    private function makeClient(): OidcClient
    {
        return OidcClient::factory()->withRedirectUris(self::REDIRECT_URI)->create();
    }

    /**
     * Déroule le flux COMPLET et rend `{access_token, id_token, client, user}`.
     *
     * @return array{access_token: string, id_token: string, client: OidcClient, user: User}
     */
    private function completeFlow(string $scope = 'openid profile groups', ?User $user = null): array
    {
        $user ??= $this->makeProf();
        $client = $this->makeClient();

        $challenge = rtrim(strtr(base64_encode(hash('sha256', self::VERIFIER, true)), '+/', '-_'), '=');

        $authorize = $this->actingAs($user)->get('/oidc/authorize?'.http_build_query([
            'response_type' => 'code',
            'client_id' => $client->client_id,
            'redirect_uri' => self::REDIRECT_URI,
            'scope' => $scope,
            'state' => 'state-xyz',
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
            'nonce' => 'nonce-abc',
        ]));

        $authorize->assertStatus(302);
        parse_str((string) parse_url((string) $authorize->headers->get('Location'), PHP_URL_QUERY), $query);

        $body = $this->post('/oidc/token', [
            'grant_type' => 'authorization_code',
            'code' => (string) $query['code'],
            'redirect_uri' => self::REDIRECT_URI,
            'code_verifier' => self::VERIFIER,
        ], [
            'Authorization' => 'Basic '.base64_encode($client->client_id.':'.OidcClientFactory::DEFAULT_SECRET),
        ])->assertOk()->json();

        return [
            'access_token' => (string) $body['access_token'],
            'id_token' => (string) $body['id_token'],
            'client' => $client,
            'user' => $user,
        ];
    }

    private function userinfo(string $token, string $method = 'get')
    {
        // ⚠️ `get($uri, $headers)` mais `post($uri, $data, $headers)` — les
        // deux signatures diffèrent, et se tromper ferait passer les en-têtes
        // pour un corps (donc un Bearer absent, donc un 401 qui ne prouve rien).
        $headers = ['Authorization' => 'Bearer '.$token];

        return $method === 'post'
            ? $this->post('/oidc/userinfo', [], $headers)
            : $this->get('/oidc/userinfo', $headers);
    }

    // ── LE CONTRÔLE POSITIF — tout le reste s'y adosse ────────────────────

    #[Test]
    public function userinfo_serves_the_same_sub_as_the_id_token_of_the_same_flow(): void
    {
        $flow = $this->completeFlow();

        $response = $this->userinfo($flow['access_token']);

        $response->assertOk();
        $payload = $response->json();

        // OIDC Core §5.3.2 : le `sub` DOIT être identique à celui de
        // l'id_token du même flux. Garanti PAR CONSTRUCTION (valeur stockée sur
        // le jeton), pas par une re-résolution qui pourrait diverger.
        $idClaims = (array) JWT::decode($flow['id_token'], new Key($this->testPublicKeyPem(), 'RS256'));
        self::assertSame($idClaims['sub'], $payload['sub']);

        self::assertSame('Professeur Dupont', $payload['name']);
        self::assertSame('prof', $payload['role']);
        self::assertSame(['4B', 'TechnoCollege'], $payload['groups']);

        // Liste EXACTE des clés — même exigence que pour l'id_token.
        $keys = array_keys($payload);
        sort($keys);
        self::assertSame(['groups', 'name', 'role', 'sub'], $keys);

        // Une réponse porteuse d'identité ne doit être conservée nulle part.
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    #[Test]
    public function userinfo_answers_post_exactly_like_get(): void
    {
        // OIDC Core §5.3.1 impose les DEUX méthodes.
        $flow = $this->completeFlow();

        $get = $this->userinfo($flow['access_token'], 'get')->assertOk()->json();
        $post = $this->userinfo($flow['access_token'], 'post')->assertOk()->json();

        self::assertSame($get, $post);
    }

    #[Test]
    public function a_token_scoped_openid_only_yields_the_sub_and_nothing_else(): void
    {
        // Minimisation NFR5 : le filtrage par scope s'applique AUSSI ici — le
        // canal de repli ne peut pas être une porte dérobée aux claims.
        $flow = $this->completeFlow('openid');

        $payload = $this->userinfo($flow['access_token'])->assertOk()->json();

        self::assertSame(['sub'], array_keys($payload));
    }

    #[Test]
    public function a_token_scoped_profile_only_omits_the_groups_claim(): void
    {
        $flow = $this->completeFlow('openid profile');

        $payload = $this->userinfo($flow['access_token'])->assertOk()->json();

        $keys = array_keys($payload);
        sort($keys);
        self::assertSame(['name', 'role', 'sub'], $keys);
    }

    #[Test]
    public function userinfo_never_serves_email_or_directory_attributes(): void
    {
        $user = $this->makeProf();
        $user->email = 'prof.dupont@college.test';
        $user->ad_guid = '11111111-2222-3333-4444-555555555555';
        $user->save();

        $flow = $this->completeFlow('openid profile groups', $user);

        $response = $this->userinfo($flow['access_token'])->assertOk();

        // Contrôle positif intégré : la réponse porte bien les claims du
        // contrat, donc les absences ci-dessous ne viennent pas d'un vide.
        self::assertSame('prof', $response->json('role'));

        $response->assertDontSee('@college.test');
        $response->assertDontSee('11111111-2222');
        foreach (['email', 'ad_guid', 'dn', 'permissions'] as $forbidden) {
            self::assertArrayNotHasKey($forbidden, $response->json());
        }
    }

    // ── Les refus, tous indistincts ───────────────────────────────────────

    /** Forme canonique d'un refus « jeton présenté et rejeté ». */
    private function assertPresentedTokenRefusal($response): void
    {
        $response->assertStatus(401);
        self::assertSame('invalid_token', $response->json('error'));
        self::assertSame(
            'The access token is invalid or expired.',
            $response->json('error_description'),
            'la description est IDENTIQUE pour les quatre causes : aucun oracle',
        );
        self::assertStringStartsWith('Bearer realm="oidc"', (string) $response->headers->get('WWW-Authenticate'));
        self::assertStringContainsString('error="invalid_token"', (string) $response->headers->get('WWW-Authenticate'));

        // AUCUNE donnée d'identité.
        self::assertNull($response->json('sub'));
        self::assertNull($response->json('name'));
        self::assertNull($response->json('groups'));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    #[Test]
    public function a_missing_bearer_is_refused_without_any_error_code(): void
    {
        $this->captureLogs();

        $response = $this->get('/oidc/userinfo');

        $response->assertStatus(401);
        // RFC 6750 §3 : sans aucune information d'authentification, le serveur
        // ne renvoie PAS de code d'erreur — juste le challenge.
        self::assertSame('Bearer realm="oidc"', $response->headers->get('WWW-Authenticate'));
        self::assertNull($response->json('error'));
        self::assertNull($response->json('sub'));

        self::assertContains(OidcErrorCodes::ACCESS_TOKEN_MISSING, $this->loggedCodes());
    }

    #[Test]
    public function a_basic_authorization_header_is_not_a_bearer(): void
    {
        $response = $this->get('/oidc/userinfo', [
            'Authorization' => 'Basic '.base64_encode('client:secret'),
        ]);

        $response->assertStatus(401);
        self::assertNull($response->json('sub'));
    }

    #[Test]
    public function an_unknown_token_is_refused(): void
    {
        $this->captureLogs();

        $this->assertPresentedTokenRefusal($this->userinfo(bin2hex(random_bytes(32))));

        self::assertContains(OidcErrorCodes::ACCESS_TOKEN_INVALID, $this->loggedCodes());
    }

    #[Test]
    public function an_expired_token_is_refused(): void
    {
        $flow = $this->completeFlow();

        // Contrôle positif AVANT le voyage temporel.
        $this->userinfo($flow['access_token'])->assertOk();

        $this->captureLogs();
        Carbon::setTestNow(Carbon::now()->addSeconds(601));

        $this->assertPresentedTokenRefusal($this->userinfo($flow['access_token']));

        self::assertContains(OidcErrorCodes::ACCESS_TOKEN_EXPIRED, $this->loggedCodes());
    }

    #[Test]
    public function revoking_the_client_kills_its_already_issued_tokens(): void
    {
        // C'est ICI que la promesse 55.1 — « révoquer un client rend ses jetons
        // inutilisables » — devient observable. Un access token AUTO-PORTEUR
        // (JWT) ne permettrait pas cela : c'est tout l'intérêt d'un jeton
        // opaque adossé à une ligne.
        $flow = $this->completeFlow();

        $this->userinfo($flow['access_token'])->assertOk();

        $this->captureLogs();
        $flow['client']->enabled = false;
        $flow['client']->save();

        $this->assertPresentedTokenRefusal($this->userinfo($flow['access_token']));

        self::assertContains(OidcErrorCodes::CLIENT_DISABLED, $this->loggedCodes());
    }

    #[Test]
    public function deleting_the_user_kills_their_already_issued_tokens(): void
    {
        $flow = $this->completeFlow();

        $this->userinfo($flow['access_token'])->assertOk();

        $this->captureLogs();
        $flow['user']->delete();

        $this->assertPresentedTokenRefusal($this->userinfo($flow['access_token']));

        self::assertContains(OidcErrorCodes::USER_MISSING, $this->loggedCodes());
    }

    #[Test]
    public function a_token_passed_in_the_query_string_is_ignored(): void
    {
        // Doctrine D-3 (55.1) : jamais de secret en query — il finirait dans
        // les logs du serveur, l'historique et le `Referer`. RFC 6750 autorise
        // cette forme ; SE5 ne la supporte pas, donc elle vaut « absent ».
        $flow = $this->completeFlow();

        // Contrôle positif : le MÊME jeton, dans l'en-tête, fonctionne.
        $this->userinfo($flow['access_token'])->assertOk();

        $response = $this->get('/oidc/userinfo?access_token='.$flow['access_token']);

        $response->assertStatus(401);
        self::assertNull($response->json('sub'));
        self::assertSame('Bearer realm="oidc"', $response->headers->get('WWW-Authenticate'));
    }

    #[Test]
    public function a_token_passed_in_the_post_body_is_ignored_too(): void
    {
        $flow = $this->completeFlow();

        $response = $this->post('/oidc/userinfo', ['access_token' => $flow['access_token']]);

        $response->assertStatus(401);
        self::assertNull($response->json('sub'));
    }

    // ── Journal : les codes fins partent, la PII reste ────────────────────

    #[Test]
    public function the_served_journal_entry_carries_no_pii(): void
    {
        $flow = $this->completeFlow();

        $this->captureLogs();
        $this->userinfo($flow['access_token'])->assertOk();

        $served = $this->logContextsOfType('oidc.userinfo.served');

        self::assertCount(1, $served, 'un appel servi doit laisser une trace exploitable');
        self::assertSame($flow['client']->client_id, $served[0]['client_id']);

        $journal = $this->flattenedLogs();
        self::assertStringNotContainsString('prof.dupont', $journal, 'jamais le `sub`');
        self::assertStringNotContainsString('Professeur Dupont', $journal, 'jamais le `name`');
        self::assertStringNotContainsString('TechnoCollege', $journal, 'jamais les `groups`');
        self::assertStringNotContainsString($flow['access_token'], $journal, 'jamais le jeton clair');
    }

    #[Test]
    public function the_claims_are_recomputed_from_the_current_sql_state(): void
    {
        // Décision figée : les claims sont RECALCULÉS à l'appel (comme
        // l'id_token l'est à l'échange), et non figés à l'émission. Un jeton
        // n'est pas un instantané d'identité.
        $flow = $this->completeFlow();

        self::assertSame(['4B', 'TechnoCollege'], $this->userinfo($flow['access_token'])->json('groups'));

        $nouvelle = UserGroup::query()->create(['name' => '3A', 'type' => 'classe']);
        $flow['user']->userGroups()->attach($nouvelle->id);

        self::assertSame(
            ['3A', '4B', 'TechnoCollege'],
            $this->userinfo($flow['access_token'])->json('groups'),
        );
    }

    #[Test]
    public function the_clear_token_never_touches_the_database(): void
    {
        $flow = $this->completeFlow();

        self::assertDatabaseMissing('oidc_access_tokens', ['token_hash' => $flow['access_token']]);
        self::assertSame(
            1,
            OidcAccessToken::query()->where('token_hash', hash('sha256', $flow['access_token']))->count(),
        );
    }
}

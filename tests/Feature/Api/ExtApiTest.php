<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Auth\Oidc\Http\Middleware\EnsureExtensionApiToken;
use App\Auth\Oidc\Services\OidcClientRegistry;
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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Oidc\Concerns\CapturesOidcLogs;
use Tests\Feature\Oidc\Concerns\UsesOidcTestKeys;
use Tests\TestCase;

/**
 * Story 56.4 — **AC3, AC4, AC5** : l'API extensions `/api/ext/v1/`.
 *
 * ══════════════════════════════════════════════════════════════════════════
 *  L'ORDRE DE CE FICHIER EST DÉLIBÉRÉ (patron `OidcUserinfoTest`)
 *
 *  Le contrôle POSITIF vient d'abord — le flux complet `/oidc/authorize` →
 *  `/oidc/token` → API, avec un jeton réellement émis. Sans lui, les 401 et
 *  403 qui suivent ne prouveraient rien : un endpoint qui refuse TOUT les
 *  obtiendrait aussi.
 *
 *  Les réponses sont assertées par la liste EXACTE de leurs clés. Le contrat
 *  v1 est public et gelé (NFR11) : une clé de trop est une dette permanente,
 *  et un `assertArrayNotHasKey` ne couvre que ce à quoi on a pensé.
 * ══════════════════════════════════════════════════════════════════════════
 */
class ExtApiTest extends TestCase
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
            'ad_guid' => 'e3b0c442-98fc-1c14-9afb-f4c8996fb924',
        ]);

        foreach (['4B' => 'classe', '3A' => 'classe', 'TechnoCollege' => 'equipe'] as $name => $type) {
            $group = UserGroup::query()->create(['name' => $name, 'type' => $type]);
            $user->userGroups()->attach($group->id);
        }

        return $user;
    }

    /**
     * Un utilisateur SANS groupe, au login choisi — pour les scénarios où
     * l'identité importe peu mais doit rester distincte (`users.login` et
     * `user_groups.name` sont UNIQUE en base).
     */
    private function makeUser(string $login, string $role = 'prof'): User
    {
        return User::query()->create([
            'login' => $login,
            'fullname' => 'Compte '.$login,
            'role' => $role,
            'source' => 'ad',
            'is_active' => true,
        ]);
    }

    /**
     * Déroule le flux COMPLET et rend le jeton réellement émis.
     *
     * @param  list<string>|null  $grantedScopes `null` ⇒ défaut factory (tout accordé)
     * @return array{token: string, client: OidcClient, user: User}
     */
    private function completeFlow(
        string $scope = 'openid profile groups',
        ?User $user = null,
        ?array $grantedScopes = null,
    ): array {
        $user ??= $this->makeProf();

        $factory = OidcClient::factory()->withRedirectUris(self::REDIRECT_URI);
        if ($grantedScopes !== null) {
            $factory = $factory->grantedScopes($grantedScopes);
        }
        $client = $factory->create();

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

        $token = $this->post('/oidc/token', [
            'grant_type' => 'authorization_code',
            'code' => (string) ($query['code'] ?? ''),
            'redirect_uri' => self::REDIRECT_URI,
            'code_verifier' => self::VERIFIER,
        ], [
            'Authorization' => 'Basic '.base64_encode($client->client_id.':'.OidcClientFactory::DEFAULT_SECRET),
        ]);

        $token->assertOk();

        return [
            'token' => (string) $token->json('access_token'),
            'client' => $client,
            'user' => $user,
        ];
    }

    /** @return array<string, string> */
    private function bearer(string $token): array
    {
        return ['Authorization' => 'Bearer '.$token];
    }

    /**
     * Les traces ÉCRITES PAR CETTE STORY, aplaties — pour prouver l'absence
     * d'une valeur (PII, jeton clair).
     */
    private function flattenedExtApiLogs(): string
    {
        $contexts = array_merge(
            $this->logContextsOfType('oidc.ext_api.rejected'),
            $this->logContextsOfType('oidc.ext_api.served'),
        );

        // Méta-garde : une assertion d'absence sur un tableau vide ne prouve
        // rien du tout.
        self::assertNotEmpty($contexts, 'le canal `oidc.ext_api.*` doit avoir écrit quelque chose');

        return json_encode($contexts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
    }

    /** @param array<string, mixed> $payload */
    private static function sortedKeys(array $payload): array
    {
        $keys = array_keys($payload);
        sort($keys);

        return $keys;
    }

    // ══════════════════════════════════════════════════════════════════════
    // AC3 — le contrat v1, au format maison
    // ══════════════════════════════════════════════════════════════════════

    #[Test]
    public function me_returns_the_frozen_identity_contract(): void
    {
        $flow = $this->completeFlow();

        $response = $this->getJson('/api/ext/v1/me', $this->bearer($flow['token']));

        $response->assertOk();

        // Liste EXACTE des clés : le contrat v1 est fermé.
        self::assertSame(
            ['message', 'name', 'role', 'sub', 'success'],
            self::sortedKeys((array) $response->json()),
        );

        // Format MAISON : clés métier À LA RACINE, jamais de wrapper `data:`.
        self::assertTrue($response->json('success'));
        self::assertIsString($response->json('message'));
        self::assertNull($response->json('data'));

        // Valeurs = contrat de claims 55.2, VERBATIM.
        self::assertSame('prof.dupont', $response->json('sub'));
        self::assertSame('Professeur Dupont', $response->json('name'));
        self::assertSame('prof', $response->json('role'));

        $response->assertHeader('Cache-Control', 'no-store, private');
    }

    #[Test]
    public function me_groups_returns_bare_sorted_group_names(): void
    {
        $flow = $this->completeFlow();

        $response = $this->getJson('/api/ext/v1/me/groups', $this->bearer($flow['token']));

        $response->assertOk();

        self::assertSame(
            ['groups', 'message', 'sub', 'success'],
            self::sortedKeys((array) $response->json()),
        );

        // Noms NUS, triés, classes ET équipes — jamais un DN, jamais un id.
        self::assertSame(['3A', '4B', 'TechnoCollege'], $response->json('groups'));
        self::assertSame('prof.dupont', $response->json('sub'));

        $response->assertHeader('Cache-Control', 'no-store, private');
    }

    /**
     * Contrat gelé 55.2 : un rôle non résoluble ⇒ **clé ABSENTE**, jamais
     * `null`, jamais `""`. Une extension qui n'obtient pas de rôle n'habilite
     * pas.
     */
    #[Test]
    public function me_omits_the_role_key_when_it_is_not_resolvable(): void
    {
        $user = $this->makeUser('sans.role', 'autre');

        $flow = $this->completeFlow('openid profile', $user);

        $response = $this->getJson('/api/ext/v1/me', $this->bearer($flow['token']));

        $response->assertOk();
        self::assertSame(['message', 'name', 'sub', 'success'], self::sortedKeys((array) $response->json()));
    }

    #[Test]
    public function me_groups_returns_an_empty_list_rather_than_omitting_the_key(): void
    {
        $user = $this->makeUser('sans.groupe');

        $response = $this->getJson('/api/ext/v1/me/groups', $this->bearer($this->completeFlow(user: $user)['token']));

        $response->assertOk();
        // « Aucun groupe » est une DONNÉE, pas une absence de réponse.
        self::assertSame([], $response->json('groups'));
    }

    // ══════════════════════════════════════════════════════════════════════
    // AC5 — FR24 : rien de la base ni de l'annuaire ne sort
    // ══════════════════════════════════════════════════════════════════════

    #[Test]
    public function no_directory_or_database_identifier_ever_reaches_the_payloads(): void
    {
        $flow = $this->completeFlow();
        $user = $flow['user'];

        $me = $this->getJson('/api/ext/v1/me', $this->bearer($flow['token']));
        $groups = $this->getJson('/api/ext/v1/me/groups', $this->bearer($flow['token']));

        // Contrôle POSITIF adossé (calque AC3 de 55.3) : sans lui, deux
        // réponses vides passeraient toutes les assertions négatives.
        self::assertSame('Professeur Dupont', $me->json('name'));
        self::assertSame(['3A', '4B', 'TechnoCollege'], $groups->json('groups'));

        $flat = json_encode([$me->json(), $groups->json()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';

        foreach ([
            'ad_guid' => (string) $user->ad_guid,
            'identifiant de base' => '"id"',
            'dn' => 'CN=',
            'annuaire' => 'memberOf',
            'email' => '@',
            'permission Spatie' => 'server.admin',
        ] as $label => $needle) {
            self::assertStringNotContainsString(
                $needle,
                $flat,
                sprintf('FR24 : « %s » ne doit JAMAIS traverser l\'API extensions.', $label),
            );
        }

        // Le `sub` est le login publié, jamais la clé primaire.
        self::assertNotSame((string) $user->id, $me->json('sub'));
    }

    // ══════════════════════════════════════════════════════════════════════
    // AC4 — 401 INDISTINCTS (5 causes), codes fins au journal seul
    // ══════════════════════════════════════════════════════════════════════

    #[Test]
    public function the_five_rejection_causes_produce_a_strictly_identical_body(): void
    {
        $this->captureLogs();

        $bodies = [];
        $codes = [];

        // 1. Aucun jeton.
        $bodies['absent'] = $this->getJson('/api/ext/v1/me')->assertStatus(401)->json();

        // 2. Jeton inconnu.
        $bodies['inconnu'] = $this->getJson('/api/ext/v1/me', $this->bearer(str_repeat('a', 64)))
            ->assertStatus(401)->json();

        // 3. Jeton expiré.
        $expired = $this->completeFlow(user: $this->makeUser('jeton.expire'));
        Carbon::setTestNow(Carbon::now()->addSeconds(601));
        $bodies['expiré'] = $this->getJson('/api/ext/v1/me', $this->bearer($expired['token']))
            ->assertStatus(401)->json();
        Carbon::setTestNow();

        // 4. Client révoqué pendant la vie du jeton (`ext:remove`).
        $revoked = $this->completeFlow(user: $this->makeUser('client.revoque'));
        app(OidcClientRegistry::class)->revoke((string) $revoked['client']->client_id);
        $bodies['client révoqué'] = $this->getJson('/api/ext/v1/me', $this->bearer($revoked['token']))
            ->assertStatus(401)->json();

        // 5. Utilisateur désactivé.
        $inactive = $this->completeFlow(user: $this->makeUser('parti.dupont'));
        $inactive['user']->update(['is_active' => false]);
        $bodies['utilisateur inactif'] = $this->getJson('/api/ext/v1/me', $this->bearer($inactive['token']))
            ->assertStatus(401)->json();

        // Le CORPS ne distingue rien : c'est la propriété de sécurité.
        $reference = $bodies['absent'];
        foreach ($bodies as $cause => $body) {
            self::assertSame($reference, $body, 'Corps distinct pour la cause « '.$cause.' » — oracle offert.');
        }

        self::assertFalse($reference['success']);
        self::assertSame('invalid_token', $reference['error']);

        // Les codes FINS, eux, sont au journal — sans quoi une intégration
        // ratée serait indiagnosticable (FR20).
        foreach ($this->logContextsOfType('oidc.ext_api.rejected') as $context) {
            $codes[] = $context['code'] ?? null;
        }

        foreach ([
            OidcErrorCodes::ACCESS_TOKEN_MISSING,
            OidcErrorCodes::ACCESS_TOKEN_INVALID,
            OidcErrorCodes::ACCESS_TOKEN_EXPIRED,
            OidcErrorCodes::CLIENT_DISABLED,
            OidcErrorCodes::USER_INACTIVE,
        ] as $expected) {
            self::assertContains($expected, $codes, 'code fin absent du journal : '.$expected);
        }

        // ⚠️ Aucune PII dans NOS traces, et jamais le jeton clair. L'assertion
        // porte sur les contextes du canal `oidc.ext_api.*` : c'est ce que
        // cette story écrit, et le seul journal dont elle réponde (le reste de
        // l'application journalise ses propres affaires, hors périmètre).
        $flat = $this->flattenedExtApiLogs();
        self::assertStringNotContainsString('parti.dupont', $flat);
        self::assertStringNotContainsString('jeton.expire', $flat);
        self::assertStringNotContainsString($expired['token'], $flat);
    }

    #[Test]
    public function a_rejection_carries_the_bearer_challenge_and_never_caches(): void
    {
        $presented = $this->getJson('/api/ext/v1/me', $this->bearer('inconnu'));

        $presented->assertStatus(401);
        $presented->assertHeader('WWW-Authenticate', 'Bearer realm="ext-api", error="invalid_token"');
        $presented->assertHeader('Cache-Control', 'no-store, private');

        // RFC 6750 §3 : sans aucune information d'authentification, le serveur
        // se contente du challenge — pas de code d'erreur dans l'EN-TÊTE. Le
        // corps, lui, reste identique (cf. test ci-dessus).
        $this->getJson('/api/ext/v1/me')
            ->assertStatus(401)
            ->assertHeader('WWW-Authenticate', 'Bearer realm="ext-api"');
    }

    /** Doctrine 55.2/D-3 : un jeton en query ou en corps est IGNORÉ. */
    #[Test]
    public function a_token_presented_outside_the_header_is_ignored(): void
    {
        $flow = $this->completeFlow();

        // Contrôle positif : le MÊME jeton, dans l'en-tête, passe.
        $this->getJson('/api/ext/v1/me', $this->bearer($flow['token']))->assertOk();

        $this->getJson('/api/ext/v1/me?access_token='.$flow['token'])->assertStatus(401);
        $this->json('GET', '/api/ext/v1/me', ['access_token' => $flow['token']])->assertStatus(401);
    }

    // ══════════════════════════════════════════════════════════════════════
    // AC4 — 403 `insufficient_scope`, générique
    // ══════════════════════════════════════════════════════════════════════

    #[Test]
    public function a_token_without_the_required_scope_is_forbidden_without_naming_scopes(): void
    {
        $flow = $this->completeFlow('openid', grantedScopes: []);

        $response = $this->getJson('/api/ext/v1/me', $this->bearer($flow['token']));

        $response->assertStatus(403);
        self::assertSame(['error', 'message', 'success'], self::sortedKeys((array) $response->json()));
        self::assertFalse($response->json('success'));
        self::assertSame('insufficient_scope', $response->json('error'));

        // La réponse ne dit NI ce qui est détenu, NI ce qui manquerait.
        $body = (string) $response->getContent();
        self::assertStringNotContainsString('profile', $body);
        self::assertStringNotContainsString('groups', $body);
        self::assertStringNotContainsString('openid', $body);
    }

    /**
     * Review 56.4 #1 — le garde-fou du CÂBLAGE, jusqu'ici sans aucun test.
     *
     * Une route de ce canal déclarée sans scope requis (ou avec un scope hors
     * du catalogue fermé) est une faute de câblage : le middleware refuse
     * plutôt que de servir des données à la faveur d'un paramètre oublié. Ce
     * chemin protège une story FUTURE — celle qui ajoutera une route et
     * oubliera son alias. Sans test, un refactor pouvait l'inverser ou le
     * supprimer sans que rien ne rougisse.
     *
     * On enregistre ici deux routes de test portant le même middleware, avec
     * un jeton PLEINEMENT consenti : seul le câblage diffère du cas nominal.
     */
    #[Test]
    public function a_route_wired_without_a_valid_required_scope_is_refused(): void
    {
        // Le middleware est appelé DIRECTEMENT, avec un jeton pleinement
        // consenti : seul le paramètre de câblage varie. Passer par une route
        // déclarée à la volée ne prouverait rien de plus et dépendrait de
        // l'état du routeur en cours de test.
        $flow = $this->completeFlow('openid profile groups');

        // La capture démarre APRÈS le flux d'émission : seules les traces du
        // câblage fautif restent à observer.
        $this->captureLogs();

        $middleware = app(EnsureExtensionApiToken::class);

        $served = fn () => response()->json(['success' => true]);

        foreach (['' => 'scope requis absent', 'calendrier' => 'scope hors catalogue'] as $wiring => $label) {
            $request = Request::create('/api/ext/v1/peu-importe', 'GET');
            $request->headers->set('Authorization', 'Bearer '.$flow['token']);

            $response = $middleware->handle($request, $served, (string) $wiring);

            self::assertSame(403, $response->getStatusCode(), "câblage fautif servi ({$label})");
            self::assertStringNotContainsString('"success":true', (string) $response->getContent());
        }

        // Et la faute est journalisée pour être diagnosticable — un 403 muet
        // enverrait l'exploitant chercher du côté des droits de l'extension.
        self::assertStringContainsString('required_scope_misconfigured', $this->flattenedExtApiLogs());
    }

    /**
     * **LE test de FR23** : le jeton est émis avec `groups`, PUIS le scope est
     * révoqué. Sans ré-émission et sans purge, l'endpoint doit fermer.
     */
    #[Test]
    public function revoking_a_scope_closes_the_endpoint_for_a_token_already_issued(): void
    {
        $this->captureLogs();

        $flow = $this->completeFlow();

        // Contrôle POSITIF : avant révocation, l'endpoint répond.
        $this->getJson('/api/ext/v1/me/groups', $this->bearer($flow['token']))
            ->assertOk()
            ->assertJsonPath('groups', ['3A', '4B', 'TechnoCollege']);

        app(OidcClientRegistry::class)->revokeScope((string) $flow['client']->client_id, 'groups');

        $this->getJson('/api/ext/v1/me/groups', $this->bearer($flow['token']))->assertStatus(403);

        // L'autre endpoint, lui, continue de fonctionner : on a révoqué UNE
        // donnée, pas l'accès.
        $this->getJson('/api/ext/v1/me', $this->bearer($flow['token']))->assertOk();

        // Le jeton n'a été ni purgé, ni réécrit.
        self::assertSame('openid profile groups', OidcAccessToken::query()->first()?->scope);

        $codes = array_column($this->logContextsOfType('oidc.ext_api.rejected'), 'code');
        self::assertContains(OidcErrorCodes::ACCESS_TOKEN_SCOPE_INSUFFICIENT, $codes);
    }

    // ══════════════════════════════════════════════════════════════════════
    // Journal nominal
    // ══════════════════════════════════════════════════════════════════════

    #[Test]
    public function a_served_call_is_logged_without_any_pii(): void
    {
        $this->captureLogs();

        $flow = $this->completeFlow();

        $this->getJson('/api/ext/v1/me', $this->bearer($flow['token']))->assertOk();

        $served = $this->logContextsOfType('oidc.ext_api.served');

        self::assertNotEmpty($served, 'un appel servi doit laisser une trace exploitable');
        self::assertSame($flow['client']->client_id, $served[0]['client_id'] ?? null);

        $flat = $this->flattenedExtApiLogs();
        self::assertStringNotContainsString('prof.dupont', $flat);
        self::assertStringNotContainsString('Professeur Dupont', $flat);
        self::assertStringNotContainsString('TechnoCollege', $flat);
        self::assertStringNotContainsString($flow['token'], $flat);
    }
}

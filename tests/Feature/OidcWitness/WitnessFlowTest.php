<?php

declare(strict_types=1);

namespace Tests\Feature\OidcWitness;

use App\Auth\Oidc\Services\OidcClientRegistry;
use App\Http\Middleware\Auth\AuditExternalAction;
use App\Http\Middleware\Auth\SambaEduAuth;
use App\Models\User;
use App\Models\UserGroup;
use App\Observers\UserGroupObserver;
use App\OidcWitness\Http\Controllers\WitnessController;
use App\OidcWitness\Support\WitnessCredentials;
use App\OidcWitness\Support\WitnessErrorCodes;
use Database\Seeders\BundledExtensionSeeder;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Oidc\Concerns\UsesOidcTestKeys;
use Tests\Feature\OidcWitness\Concerns\ReentersTheTestKernel;
use Tests\TestCase;

/**
 * Story 55.3 — **AC1** : le parcours de bout en bout de l'app-témoin.
 *
 * Le flux est déroulé POUR DE VRAI, à travers HTTP : le témoin découvre le
 * fournisseur (`/.well-known/openid-configuration`), fabrique `state`, `nonce`
 * et PKCE, redirige vers `/oidc/authorize`, reçoit un code, l'échange au VRAI
 * `POST /oidc/token`, récupère le VRAI JWKS et vérifie l'id_token avant
 * d'afficher quoi que ce soit. Aucun mock du protocole — seulement du transport
 * ({@see ReentersTheTestKernel}).
 *
 * ⚠️ Bypass du guard `sambaedu.auth` sur `/oidc/authorize` UNIQUEMENT : il lit
 * `$_SESSION` et le LDAP, inatteignables sur l'hôte (patron 55.1
 * `OidcAuthorizationFlowTest`). Le témoin, lui, n'est derrière AUCUN garde —
 * c'est ce qui rend la preuve « sans re-saisie d'identifiants » non circulaire.
 */
class WitnessFlowTest extends TestCase
{
    use ReentersTheTestKernel;
    use RefreshDatabase;
    use UsesOidcTestKeys;

    private string $credentialsPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useOidcTestKeys();

        // ⚠️ JAMAIS le vrai `storage/app` : le fichier porte un secret.
        $this->credentialsPath = sys_get_temp_dir()
            . '/oidc-witness-test-' . getmypid() . '-' . bin2hex(random_bytes(6)) . '.json';

        config([
            'oidc.witness.credentials_path' => $this->credentialsPath,
            'oidc.witness.replay_cache_store' => 'array',
        ]);

        // `businessRoles()` consulte Spatie ; créer un `user_groups` déclenche
        // une projection AD inatteignable sur l'hôte (patron `OidcIdTokenClaimsTest`).
        (new PermissionSeeder())->run();
        UserGroupObserver::disableSync();

        $this->withoutMiddleware([
            SambaEduAuth::class,
            AuditExternalAction::class,
        ]);

        $this->bindReentrantWitnessHttpClient();
    }

    protected function tearDown(): void
    {
        UserGroupObserver::enableSync();

        if (is_file($this->credentialsPath)) {
            @unlink($this->credentialsPath);
        }

        parent::tearDown();
    }

    // ── Fixtures ──────────────────────────────────────────────────────────

    /** Provisionne le témoin par SA commande — pas par un raccourci de test. */
    private function provision(): void
    {
        (new BundledExtensionSeeder())->run();

        $this->artisan('oidc:witness:enable')->assertExitCode(0);
    }

    private function makeProf(): User
    {
        $user = User::query()->create([
            'login' => 'prof.dupont',
            'fullname' => 'Professeur Dupont',
            'role' => 'prof',
            'source' => 'ad',
            'is_active' => true,
        ]);

        foreach (['4B' => 'classe', '3A' => 'classe'] as $name => $type) {
            $group = UserGroup::query()->create(['name' => $name, 'type' => $type]);
            $user->userGroups()->attach($group->id);
        }

        return $user;
    }

    /** Valeur BRUTE (chiffrée) du cookie d'état posé par `/sso-demo`. */
    private function stateCookieFrom(TestResponse $response): ?string
    {
        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->getName() === WitnessController::STATE_COOKIE) {
                return (string) $cookie->getValue();
            }
        }

        return null;
    }

    /**
     * Déroule `/sso-demo` puis `/oidc/authorize` et rend le nécessaire pour
     * attaquer le callback.
     *
     * @return array{start: TestResponse, cookie: string, callback_uri: string, authorize_query: array<string, string>}
     */
    private function walkUpToCallback(User $user): array
    {
        $start = $this->get('/sso-demo');
        $start->assertStatus(302);

        $cookie = $this->stateCookieFrom($start);
        self::assertNotNull($cookie, 'le témoin doit poser SON cookie d\'état');

        $authorizeUrl = (string) $start->headers->get('Location');
        parse_str((string) parse_url($authorizeUrl, PHP_URL_QUERY), $query);
        /** @var array<string, string> $query */

        $authorize = $this->actingAs($user)->get($authorizeUrl);
        $authorize->assertStatus(302);

        $callbackUri = (string) $authorize->headers->get('Location');

        return [
            'start' => $start,
            'cookie' => $cookie,
            'callback_uri' => $callbackUri,
            'authorize_query' => $query,
        ];
    }

    private function hitCallback(string $uri, string $cookie): TestResponse
    {
        return $this->withUnencryptedCookie(WitnessController::STATE_COOKIE, $cookie)->get($uri);
    }

    // =====================================================================
    // AC1 — le parcours nominal
    // =====================================================================

    #[Test]
    public function the_witness_walks_the_whole_sso_and_renders_the_verified_claims(): void
    {
        $this->provision();
        $user = $this->makeProf();

        $walk = $this->walkUpToCallback($user);

        // ── Le départ : PKCE S256, `state` et `nonce` fabriqués par le témoin
        $query = $walk['authorize_query'];

        self::assertSame('code', $query['response_type'] ?? null);
        self::assertSame('S256', $query['code_challenge_method'] ?? null, 'PKCE S256 — jamais `plain`');
        self::assertNotEmpty($query['code_challenge'] ?? '');
        self::assertNotEmpty($query['state'] ?? '');
        self::assertNotEmpty($query['nonce'] ?? '');
        self::assertSame('openid profile groups', $query['scope'] ?? null);
        self::assertSame('/sso-demo/callback', $query['redirect_uri'] ?? null);

        // ⚠️ Le `code_verifier` PKCE ne doit JAMAIS partir à l'autorisation.
        self::assertArrayNotHasKey('code_verifier', $query);

        // FR17 — aucune re-saisie d'identifiants : on repart chez le témoin,
        // pas vers un formulaire de connexion.
        self::assertStringStartsWith('/sso-demo/callback?', $walk['callback_uri']);
        self::assertStringNotContainsString('/login', $walk['callback_uri']);

        // ── Le retour : échange réel, vérification réelle, page de claims
        $response = $this->hitCallback($walk['callback_uri'], $walk['cookie']);

        $response->assertOk();
        $html = $response->getContent();

        self::assertStringContainsString('Bonjour Professeur Dupont', (string) $html);
        self::assertStringContainsString('prof', (string) $html);
        self::assertStringContainsString('4B', (string) $html);
        self::assertStringContainsString('3A', (string) $html);
        self::assertStringContainsString('Retour au lanceur', (string) $html);

        // ── La preuve que TOUT est passé par HTTP ────────────────────────
        $paths = $this->witnessHttpPaths();

        self::assertContains('/.well-known/openid-configuration', $paths, 'la découverte se fait par HTTP');
        self::assertContains('/oidc/token', $paths, 'l\'échange de code se fait par HTTP');
        self::assertContains('/oidc/jwks', $paths, 'le JWKS se récupère par HTTP');
    }

    #[Test]
    public function the_nominal_path_never_redirects_to_the_login_form(): void
    {
        // La preuve « sans re-login », isolée : aucune des trois réponses du
        // parcours ne pointe vers l'authentification de SE5.
        $this->provision();
        $user = $this->makeProf();

        $walk = $this->walkUpToCallback($user);
        $final = $this->hitCallback($walk['callback_uri'], $walk['cookie']);

        $final->assertOk();

        foreach ([$walk['start'], $final] as $response) {
            $location = (string) $response->headers->get('Location');
            self::assertStringNotContainsString('login', $location);
        }

        self::assertStringNotContainsString('mot de passe', strtolower((string) $final->getContent()));
    }

    #[Test]
    public function the_state_cookie_is_httponly_scoped_and_short_lived(): void
    {
        $this->provision();

        $start = $this->get('/sso-demo');

        $cookie = null;
        foreach ($start->headers->getCookies() as $candidate) {
            if ($candidate->getName() === WitnessController::STATE_COOKIE) {
                $cookie = $candidate;
            }
        }

        self::assertNotNull($cookie);
        self::assertTrue($cookie->isHttpOnly(), 'le verifier PKCE ne doit pas être lisible en JavaScript');
        self::assertSame('lax', strtolower((string) $cookie->getSameSite()));
        self::assertSame('/sso-demo', $cookie->getPath(), 'le cookie ne suit pas l\'utilisateur sur tout le site');

        // Contenu CHIFFRÉ : ni le `state`, ni le verifier ne sont lisibles.
        self::assertStringNotContainsString('verifier', (string) $cookie->getValue());
    }

    #[Test]
    public function a_user_without_a_resolvable_role_sees_the_role_as_unresolved(): void
    {
        // AC1 — un `role` absent (AC4 de 55.2) s'affiche comme absent, jamais
        // comme une valeur inventée.
        $this->provision();

        $user = User::query()->create([
            'login' => 'agent.polyvalent',
            'fullname' => 'Agent Polyvalent',
            'role' => 'autre',
            'source' => 'ad',
            'is_active' => true,
        ]);

        $walk = $this->walkUpToCallback($user);
        $response = $this->hitCallback($walk['callback_uri'], $walk['cookie']);

        $response->assertOk();
        $html = (string) $response->getContent();

        // Contrôle POSITIF : le `name`, lui, est bien là.
        self::assertStringContainsString('Bonjour Agent Polyvalent', $html);
        self::assertStringContainsString('(non résolu)', $html);
    }

    // =====================================================================
    // AC2 — le `state` au callback : le pendant client de `redirect_uri`
    // =====================================================================

    #[Test]
    public function a_diverging_state_is_refused_without_any_code_exchange(): void
    {
        $this->provision();
        $user = $this->makeProf();

        $walk = $this->walkUpToCallback($user);

        // On remplace le `state` du retour : c'est ce que ferait une injection
        // de code d'autorisation (le tiers fait consommer SON code à la
        // victime).
        parse_str((string) parse_url($walk['callback_uri'], PHP_URL_QUERY), $params);
        $params['state'] = 'state-fabrique-par-un-tiers';

        $before = count($this->witnessHttpCalls);

        $response = $this->hitCallback('/sso-demo/callback?' . http_build_query($params), $walk['cookie']);

        $response->assertStatus(400);
        self::assertStringContainsString(WitnessErrorCodes::STATE_MISMATCH, (string) $response->getContent());

        // ⚠️ AUCUN échange n'a eu lieu : le refus est PRÉALABLE.
        self::assertSame(
            [],
            array_slice($this->witnessHttpPaths(), $before),
            'un `state` divergent ne doit déclencher aucun appel sortant',
        );

        // Contrôle POSITIF : le BON `state`, lui, passe (même code, même cookie).
        $ok = $this->hitCallback($walk['callback_uri'], $walk['cookie']);
        $ok->assertOk();
        self::assertStringContainsString('Bonjour Professeur Dupont', (string) $ok->getContent());
    }

    #[Test]
    public function a_callback_without_the_state_cookie_is_refused(): void
    {
        $this->provision();
        $user = $this->makeProf();

        $walk = $this->walkUpToCallback($user);

        // Retour sans cookie : le témoin n'a aucun souvenir d'avoir demandé
        // quoi que ce soit.
        $response = $this->get($walk['callback_uri']);

        $response->assertStatus(400);
        self::assertStringContainsString(WitnessErrorCodes::STATE_MISSING, (string) $response->getContent());
    }

    #[Test]
    public function an_oversized_state_cookie_is_refused_rather_than_decoded(): void
    {
        // Piège 55.1 #3 transposé : rien n'est persisté en colonne ici, mais la
        // taille acceptée du cookie est bornée explicitement plutôt que laissée
        // au décodeur JSON.
        $this->provision();

        $oversized = str_repeat('x', WitnessController::MAX_STATE_COOKIE_BYTES + 1);

        $response = $this->hitCallback('/sso-demo/callback?code=abc&state=def', $oversized);

        $response->assertStatus(400);
        self::assertStringContainsString(WitnessErrorCodes::STATE_MISSING, (string) $response->getContent());
    }

    #[Test]
    public function a_replayed_callback_is_refused_by_the_provider_before_the_witness_even_looks(): void
    {
        // Le code d'autorisation est à usage unique (55.1) : rejouer le MÊME
        // callback échoue à l'échange. Le témoin le signale explicitement — il
        // n'affiche jamais de claims issus d'un échange raté.
        $this->provision();
        $user = $this->makeProf();

        $walk = $this->walkUpToCallback($user);

        $this->hitCallback($walk['callback_uri'], $walk['cookie'])->assertOk();

        $replay = $this->hitCallback($walk['callback_uri'], $walk['cookie']);

        $replay->assertStatus(502);
        self::assertStringContainsString(WitnessErrorCodes::TOKEN_EXCHANGE_FAILED, (string) $replay->getContent());
        self::assertStringNotContainsString('Bonjour', (string) $replay->getContent());
    }

    // =====================================================================
    // AC1 / AC4 — fail-closed : non provisionné, client révoqué
    // =====================================================================

    #[Test]
    public function an_unprovisioned_witness_fails_with_an_explicit_503(): void
    {
        // Aucune commande jouée : pas de fichier de credentials.
        self::assertFalse(WitnessCredentials::isProvisioned());

        $response = $this->get('/sso-demo');

        $response->assertStatus(503);
        $html = (string) $response->getContent();

        self::assertStringContainsString(WitnessErrorCodes::NOT_PROVISIONED, $html);
        self::assertStringContainsString('oidc:witness:enable', $html);

        // ⚠️ Jamais une 500 brute, jamais un contournement : aucun jeton n'a
        // été demandé.
        self::assertStringNotContainsString('Bonjour', $html);
        self::assertSame([], $this->witnessHttpPaths(), 'aucun appel sortant sans provisioning');
    }

    #[Test]
    public function a_revoked_client_makes_the_journey_impossible_with_an_explicit_error(): void
    {
        $this->provision();
        $user = $this->makeProf();

        // Contrôle POSITIF d'abord : tant que le client est actif, le parcours
        // aboutit.
        $walk = $this->walkUpToCallback($user);
        $this->hitCallback($walk['callback_uri'], $walk['cookie'])->assertOk();

        // Révocation — le scénario réel de la désinstallation d'une extension.
        $credentials = WitnessCredentials::load();
        self::assertNotNull($credentials);
        app(OidcClientRegistry::class)->revoke($credentials->clientId);

        // Le départ échoue dès l'autorisation (client inconnu ⇒ 400 local du
        // fournisseur, jamais une redirection).
        $start = $this->get('/sso-demo');
        $start->assertStatus(302);

        $authorize = $this->actingAs($user)->get((string) $start->headers->get('Location'));
        $authorize->assertStatus(400);
        $authorize->assertHeaderMissing('Location');
    }

    #[Test]
    public function disabling_the_witness_removes_its_credentials_and_closes_the_door(): void
    {
        $this->provision();

        $this->artisan('oidc:witness:disable')->assertExitCode(0);

        self::assertFalse(WitnessCredentials::isProvisioned());

        $this->get('/sso-demo')->assertStatus(503);
    }

    // =====================================================================
    // Task 1 — la tuile : le témoin est atteint par le lanceur, pas par une URL
    // =====================================================================

    #[Test]
    public function the_sso_demo_tile_reaches_the_launcher_once_integrated(): void
    {
        (new BundledExtensionSeeder())->run();

        $extension = \App\Models\Extension::query()->where('key', 'sso-demo')->firstOrFail();

        self::assertSame('/sso-demo', $extension->entryUrl());
        self::assertSame(['profile', 'groups'], $extension->requestedScopes());

        $launcher = app(\App\Services\Extensions\ExtensionLauncherService::class);

        // Tant qu'elle n'est pas INTÉGRÉE, aucune tuile — état par défaut
        // `available`, comme la Documentation.
        $user = $this->makeProf();
        self::assertNotContains('sso-demo', array_column($launcher->tilesFor($user), 'key'));

        app(\App\Services\Extensions\ExtensionLifecycleService::class)->integrate((int) $extension->id, $user);

        self::assertContains('sso-demo', array_column($launcher->tilesFor($user->fresh()), 'key'));
    }

    #[Test]
    public function the_sso_demo_tile_is_visible_to_every_declared_business_role(): void
    {
        (new BundledExtensionSeeder())->run();

        $extension = \App\Models\Extension::query()->where('key', 'sso-demo')->firstOrFail();
        $admin = $this->makeProf();
        app(\App\Services\Extensions\ExtensionLifecycleService::class)->integrate((int) $extension->id, $admin);

        $launcher = app(\App\Services\Extensions\ExtensionLauncherService::class);

        foreach (['prof', 'eleve', 'administratif'] as $index => $role) {
            $user = User::query()->create([
                'login' => 'user.' . $role,
                'fullname' => 'Utilisateur ' . $role,
                'role' => $role,
                'source' => 'ad',
                'is_active' => true,
            ]);

            self::assertContains(
                'sso-demo',
                array_column($launcher->tilesFor($user), 'key'),
                'la tuile doit être visible pour le rôle ' . $role,
            );
        }
    }
}

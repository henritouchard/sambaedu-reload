<?php

declare(strict_types=1);

namespace SambaEdu\ExtBbb\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SambaEdu\ExtBbb\App;
use SambaEdu\ExtBbb\Env;
use SambaEdu\ExtBbb\Http\ArraySessionStore;
use SambaEdu\ExtBbb\Http\Request;
use SambaEdu\ExtBbb\Http\Response;
use SambaEdu\ExtBbb\Identity;
use SambaEdu\ExtBbb\Oidc\ErrorCodes;
use SambaEdu\ExtBbb\Tests\Support\FakeJsonHttpClient;
use SambaEdu\ExtBbb\Tests\Support\JwtFactory;
use SambaEdu\ExtBbb\Tests\Support\RecordingBbbApiClient;
use SambaEdu\ExtBbb\Url;
use SambaEdu\ExtBbb\View;

/**
 * Story 57.1 — L'extension VUE DE L'EXTÉRIEUR : la sonde de santé, le parcours
 * de connexion, le refus d'un rôle hors vocabulaire.
 *
 * ══════════════════════════════════════════════════════════════════════════
 *  `GET /` EST LA SONDE — ELLE RÉPOND TOUJOURS, ET NE DÉPEND DE RIEN
 *
 *  `ExtensionHealthService` frappe `GET http://127.0.0.1:<port>/` toutes les
 *  5 minutes, connexion 2 s / total 3 s, et considère joignable TOUTE réponse
 *  HTTP. Une racine qui appellerait un serveur BBB, le fournisseur OIDC, ou
 *  simplement la base, deviendrait un faux « unreachable » le jour où l'un des
 *  trois est lent — et, sur un serveur mono-processus, bloquerait aussi les
 *  vraies requêtes.
 * ══════════════════════════════════════════════════════════════════════════
 */
final class AppTest extends TestCase
{
    private const ISSUER = 'https://se5.example.test';

    private const CLIENT_ID = 'client-hex';

    private const KID = 'test-oidc-kid';

    private string $directory = '';

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/ext-bbb-app-' . bin2hex(random_bytes(6));
        mkdir($this->directory, 0700, true);
    }

    protected function tearDown(): void
    {
        Url::reset();

        foreach (glob($this->directory . '/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->directory);
    }

    /** @param array<string, string> $overrides */
    private function env(array $overrides = []): Env
    {
        return Env::capture(array_merge([
            'SE5_EXT_KEY' => 'bbb',
            'SE5_EXT_BASE_PATH' => '/ext/bbb',
            'SE5_EXT_PORT' => '8600',
            'SE5_OIDC_ISSUER' => self::ISSUER,
            'SE5_OIDC_CLIENT_ID' => self::CLIENT_ID,
            'SE5_OIDC_CLIENT_SECRET' => 'secret-hex',
            'SE5_OIDC_REDIRECT_URI' => '/ext/bbb/oidc/callback',
            'STATE_DIRECTORY' => $this->directory,
        ], $overrides));
    }

    /** @return array<string, array<string, mixed>> */
    private function documents(): array
    {
        return [
            self::ISSUER . '/.well-known/openid-configuration' => [
                'issuer' => self::ISSUER,
                'authorization_endpoint' => self::ISSUER . '/oidc/authorize',
                'token_endpoint' => self::ISSUER . '/oidc/token',
                'jwks_uri' => self::ISSUER . '/oidc/jwks',
            ],
            self::ISSUER . '/oidc/jwks' => [
                'keys' => [JwtFactory::jwk(JwtFactory::keyPair()['public'], self::KID)],
            ],
        ];
    }

    private function app(
        ?Env $env = null,
        ?ArraySessionStore $session = null,
        ?RecordingBbbApiClient $api = null,
        ?FakeJsonHttpClient $http = null,
    ): App {
        return new App(
            $env ?? $this->env(),
            new View(dirname(__DIR__) . '/views'),
            $session ?? new ArraySessionStore(),
            $api ?? new RecordingBbbApiClient(),
            $http ?? new FakeJsonHttpClient($this->documents()),
        );
    }

    private function get(App $app, string $path, array $query = []): Response
    {
        return $app->handle(new Request('GET', $path, $query));
    }

    // =====================================================================
    // La sonde de santé
    // =====================================================================

    #[Test]
    public function the_root_answers_without_touching_anything(): void
    {
        $api = new RecordingBbbApiClient();
        $http = new FakeJsonHttpClient($this->documents());

        $response = $this->get($this->app(api: $api, http: $http), '/');

        self::assertSame(200, $response->status);
        self::assertStringContainsString('Se connecter', $response->body);
        self::assertStringContainsString('/ext/bbb/login', $response->body);

        // Ni BBB, ni OIDC, ni base : la racine ne dépend de rien.
        self::assertSame([], $api->calls);
        self::assertSame([], $http->getUrls);
        self::assertSame([], $http->posts);
        self::assertFileDoesNotExist($this->directory . '/database.sqlite');
    }

    #[Test]
    public function the_root_answers_even_when_the_extension_is_not_provisioned(): void
    {
        // Le paquet peut être installé sans que le fichier d'environnement n'ait
        // encore été posé : la sonde doit voir « joignable », pas une panne.
        $env = $this->env([
            'SE5_OIDC_ISSUER' => '',
            'SE5_OIDC_CLIENT_ID' => '',
            'SE5_OIDC_CLIENT_SECRET' => '',
            'SE5_OIDC_REDIRECT_URI' => '',
        ]);

        $response = $this->get($this->app($env), '/');

        self::assertSame(200, $response->status);
        self::assertStringContainsString('pas encore reçu ses identifiants', $response->body);
    }

    #[Test]
    public function the_root_answers_even_when_the_state_directory_is_unusable(): void
    {
        // Une base corrompue ou un répertoire d'état perdu doivent rendre une
        // page, jamais une connexion pendue.
        $env = $this->env(['STATE_DIRECTORY' => '/proc/interdit-par-le-noyau']);

        $response = $this->get($this->app($env), '/');

        self::assertSame(200, $response->status);
    }

    #[Test]
    public function an_unknown_path_is_a_sober_404_not_a_crash(): void
    {
        $response = $this->get($this->app(), '/chemin-inconnu');

        self::assertSame(404, $response->status);
        self::assertStringContainsString('Page introuvable', $response->body);
    }

    #[Test]
    public function every_page_offers_the_way_back_to_sambaedu(): void
    {
        // FR16 : une extension est un site à part, mais jamais un cul-de-sac.
        // La cible est l'issuer — la SEULE URL de SambaEdu que l'extension
        // connaisse.
        foreach (['/', '/chemin-inconnu'] as $path) {
            $body = $this->get($this->app(), $path)->body;

            self::assertStringContainsString('Retour à SambaEdu', $body, $path);
            self::assertStringContainsString(self::ISSUER, $body, $path);
        }
    }

    // =====================================================================
    // Le parcours de connexion
    // =====================================================================

    #[Test]
    public function the_login_route_redirects_to_the_provider(): void
    {
        $session = new ArraySessionStore();

        $response = $this->get($this->app(session: $session), '/login');

        self::assertSame(302, $response->status);
        self::assertStringStartsWith(self::ISSUER . '/oidc/authorize?', $response->headers['Location']);
        self::assertNotNull($session->get('oidc.state'));
    }

    #[Test]
    public function an_unprovisioned_extension_answers_503_on_login_never_500(): void
    {
        $env = $this->env(['SE5_OIDC_CLIENT_SECRET' => '']);

        $response = $this->get($this->app($env), '/login');

        self::assertSame(503, $response->status);
        self::assertStringContainsString(ErrorCodes::NOT_PROVISIONED, $response->body);
    }

    #[Test]
    public function a_complete_login_opens_a_session_and_lands_on_the_home(): void
    {
        $session = new ArraySessionStore();
        $nonce = '';
        $http = new FakeJsonHttpClient(
            $this->documents(),
            // Fermeture par RÉFÉRENCE : le `nonce` n'est connu qu'APRÈS `/login`.
            function () use (&$nonce): array {
                return $this->tokenResponseFor($nonce, 'prof');
            },
        );

        $app = $this->app(session: $session, http: $http);
        $this->get($app, '/login');
        $nonce = (string) $session->get('oidc.nonce');

        $response = $this->get($app, '/oidc/callback', [
            'code' => 'code-a-usage-unique',
            'state' => (string) $session->get('oidc.state'),
        ]);

        self::assertSame(302, $response->status);
        self::assertSame('/ext/bbb/', $response->headers['Location']);

        $identity = Identity::fromSessionStore($session);
        self::assertNotNull($identity);
        self::assertSame('prof.dupont', $identity->sub);
        self::assertSame('prof', $identity->role);

        // Et l'accueil montre bien la personne connectée.
        $home = $this->get($app, '/')->body;
        self::assertStringContainsString('Professeur Dupont', $home);
        self::assertStringContainsString('3A', $home);
    }

    #[Test]
    public function a_role_outside_the_closed_vocabulary_is_refused_and_opens_nothing(): void
    {
        // L'AC de l'epic : « rôle inconnu ⇒ refus explicite ». Contrat 55.2 :
        // rôle non résoluble ⇒ claim ABSENT. Une valeur inconnue signale donc
        // soit un contrat rompu, soit une tentative — et dans les deux cas, pas
        // de repli.
        $session = new ArraySessionStore();
        $nonce = '';
        $http = new FakeJsonHttpClient(
            $this->documents(),
            // Fermeture par RÉFÉRENCE : le `nonce` n'est connu qu'APRÈS `/login`.
            function () use (&$nonce): array {
                return $this->tokenResponseFor($nonce, 'super-admin');
            },
        );

        $app = $this->app(session: $session, http: $http);
        $this->get($app, '/login');
        $nonce = (string) $session->get('oidc.nonce');

        $response = $this->get($app, '/oidc/callback', [
            'code' => 'code',
            'state' => (string) $session->get('oidc.state'),
        ]);

        self::assertSame(403, $response->status);
        self::assertStringContainsString(ErrorCodes::ROLE_UNSUPPORTED, $response->body);
        self::assertStringContainsString('ne permet pas', $response->body);
        self::assertNull(Identity::fromSessionStore($session));
    }

    #[Test]
    public function a_login_without_a_role_claim_at_all_is_refused_the_same_way(): void
    {
        $session = new ArraySessionStore();
        $nonce = '';
        $http = new FakeJsonHttpClient(
            $this->documents(),
            // Fermeture par RÉFÉRENCE : le `nonce` n'est connu qu'APRÈS `/login`.
            function () use (&$nonce): array {
                return $this->tokenResponseFor($nonce, null);
            },
        );

        $app = $this->app(session: $session, http: $http);
        $this->get($app, '/login');
        $nonce = (string) $session->get('oidc.nonce');

        $response = $this->get($app, '/oidc/callback', [
            'code' => 'code',
            'state' => (string) $session->get('oidc.state'),
        ]);

        self::assertSame(403, $response->status);
        self::assertNull(Identity::fromSessionStore($session));
    }

    #[Test]
    public function a_forged_callback_never_opens_a_session(): void
    {
        $session = new ArraySessionStore();
        $app = $this->app(session: $session);

        $response = $this->get($app, '/oidc/callback', ['code' => 'c', 'state' => 's']);

        self::assertSame(403, $response->status);
        self::assertNull(Identity::fromSessionStore($session));
    }

    #[Test]
    public function an_already_connected_visitor_is_not_sent_through_the_provider_again(): void
    {
        $session = new ArraySessionStore();
        (new Identity('prof.dupont', 'Professeur Dupont', 'prof'))->storeIn($session);

        $http = new FakeJsonHttpClient($this->documents());
        $response = $this->get($this->app(session: $session, http: $http), '/login');

        self::assertSame(302, $response->status);
        self::assertSame('/ext/bbb/', $response->headers['Location']);
        self::assertSame([], $http->getUrls, 'aucune découverte inutile');
    }

    #[Test]
    public function logging_out_clears_the_extension_session_only(): void
    {
        $session = new ArraySessionStore();
        (new Identity('prof.dupont', 'Professeur Dupont', 'prof'))->storeIn($session);

        $response = $this->get($this->app(session: $session), '/logout');

        self::assertSame(302, $response->status);
        self::assertSame('/ext/bbb/', $response->headers['Location']);
        self::assertNull(Identity::fromSessionStore($session));
        self::assertSame([], $session->all(), 'aucun résidu d\'état');
    }

    // =====================================================================
    // L'administration passe par le contrôleur dédié
    // =====================================================================

    #[Test]
    public function the_admin_route_is_wired_for_both_methods(): void
    {
        $session = new ArraySessionStore();
        (new Identity('admin.dupont', 'Admin', 'admin'))->storeIn($session);

        $app = $this->app(session: $session);

        self::assertSame(200, $this->get($app, '/admin/servers')->status);
        self::assertSame(403, $app->handle(new Request('POST', '/admin/servers', [], []))->status);
    }

    // =====================================================================

    /**
     * ⚠️ Le `nonce` est CAPTURÉ après `/login`, jamais relu de l'état au moment
     * de l'échange : le client consomme son état d'autorisation AVANT d'appeler
     * le token endpoint (un retour, une tentative). Le relire ici donnerait une
     * chaîne vide — et un faux échec de nonce.
     *
     * @return array{status: int, body: array<string, mixed>}
     */
    private function tokenResponseFor(string $nonce, ?string $role): array
    {
        $claims = [
            'iss' => self::ISSUER,
            'sub' => 'prof.dupont',
            'aud' => self::CLIENT_ID,
            'iat' => time(),
            'exp' => time() + 300,
            'jti' => 'jti-' . bin2hex(random_bytes(8)),
            'nonce' => $nonce,
            'name' => 'Professeur Dupont',
            'groups' => ['3A'],
        ];

        if ($role !== null) {
            $claims['role'] = $role;
        }

        return [
            'status' => 200,
            'body' => ['id_token' => JwtFactory::sign($claims, 'RS256', null, self::KID)],
        ];
    }
}

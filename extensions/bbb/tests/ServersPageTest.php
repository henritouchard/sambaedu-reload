<?php

declare(strict_types=1);

namespace SambaEdu\ExtBbb\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SambaEdu\ExtBbb\Admin\ServersController;
use SambaEdu\ExtBbb\Bbb\ConnectionResult;
use SambaEdu\ExtBbb\Env;
use SambaEdu\ExtBbb\Http\ArraySessionStore;
use SambaEdu\ExtBbb\Http\Request;
use SambaEdu\ExtBbb\Http\Response;
use SambaEdu\ExtBbb\Identity;
use SambaEdu\ExtBbb\Store;
use SambaEdu\ExtBbb\Tests\Support\RecordingBbbApiClient;
use SambaEdu\ExtBbb\Url;
use SambaEdu\ExtBbb\View;

/**
 * Story 57.1 — **LA PAGE DE CONFIGURATION DES SERVEURS (AC2), AU NIVEAU
 * CONTRÔLEUR.**
 *
 * Aucun serveur HTTP : on injecte une requête, on affirme une réponse. Ce qui
 * est prouvé ici tient en trois propriétés que rien d'autre ne garantit :
 *
 * 1. **le rôle `admin` est exigé STRICTEMENT** — un claim est une donnée, la
 *    décision d'accès se rejoue à chaque requête ;
 * 2. **le secret ne sort jamais du serveur** — ni dans la liste, ni dans un
 *    champ, ni dans un message ;
 * 3. **le test de connexion est un ACTE de l'administrateur** — aucun rendu de
 *    page n'appelle un serveur BBB (garde anti-blocage du serveur intégré).
 */
final class ServersPageTest extends TestCase
{
    private string $directory = '';

    private Store $store;

    private RecordingBbbApiClient $api;

    private Env $env;

    protected function setUp(): void
    {
        Url::configure('/ext/bbb');

        $this->directory = sys_get_temp_dir() . '/ext-bbb-page-' . bin2hex(random_bytes(6));
        mkdir($this->directory, 0700, true);

        $this->store = new Store($this->directory . '/database.sqlite');
        $this->api = new RecordingBbbApiClient();
        $this->env = Env::capture([
            'SE5_EXT_BASE_PATH' => '/ext/bbb',
            'SE5_OIDC_ISSUER' => 'https://se5.example.test',
            'STATE_DIRECTORY' => $this->directory,
        ]);
    }

    protected function tearDown(): void
    {
        Url::reset();

        foreach (glob($this->directory . '/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->directory);
    }

    private function controller(): ServersController
    {
        return new ServersController(
            $this->store,
            $this->api,
            new View(dirname(__DIR__) . '/views'),
            $this->env,
        );
    }

    private function sessionFor(?string $role): ArraySessionStore
    {
        $session = new ArraySessionStore();

        if ($role !== null) {
            (new Identity('jean.dupont', 'Jean Dupont', $role, ['3A']))->storeIn($session);
        }

        return $session;
    }

    private function get(ArraySessionStore $session, array $query = []): Response
    {
        return $this->controller()->handle(new Request('GET', '/admin/servers', $query), $session);
    }

    /** @param array<string, string> $post */
    private function post(ArraySessionStore $session, array $post): Response
    {
        return $this->controller()->handle(new Request('POST', '/admin/servers', [], $post), $session);
    }

    private function csrf(ArraySessionStore $session): string
    {
        $this->get($session);

        return (string) $session->get('admin.csrf');
    }

    // =====================================================================
    // Gating
    // =====================================================================

    #[Test]
    public function an_anonymous_visitor_is_sent_to_the_login(): void
    {
        $response = $this->get($this->sessionFor(null));

        self::assertSame(302, $response->status);
        self::assertSame('/ext/bbb/login', $response->headers['Location']);
    }

    #[Test]
    public function every_role_other_than_admin_gets_a_clean_403(): void
    {
        foreach (['prof', 'eleve', 'administratif'] as $role) {
            $response = $this->get($this->sessionFor($role));

            self::assertSame(403, $response->status, 'rôle refusé : ' . $role);
            self::assertStringNotContainsString('Ajouter un serveur', $response->body);
        }
    }

    #[Test]
    public function a_role_outside_the_closed_vocabulary_never_opens_the_page(): void
    {
        // Un état porteur d'un rôle inconnu — contrat rompu, ou état fabriqué —
        // n'est pas une identité : il ne vaut même pas 403, il vaut « pas
        // connecté ». Aucun repli permissif, jamais.
        $session = new ArraySessionStore();
        $session->put('identity', ['sub' => 'x', 'name' => 'X', 'role' => 'super-admin', 'groups' => []]);

        $response = $this->get($session);

        self::assertSame(302, $response->status);
        self::assertSame('/ext/bbb/login', $response->headers['Location']);
    }

    #[Test]
    public function an_admin_sees_the_page(): void
    {
        // CONTRÔLE POSITIF : sans lui, les refus ci-dessus prouveraient seulement
        // que la page est cassée pour tout le monde.
        $response = $this->get($this->sessionFor('admin'));

        self::assertSame(200, $response->status);
        self::assertStringContainsString('Ajouter un serveur', $response->body);
        self::assertStringContainsString('Serveurs BigBlueButton', $response->body);
    }

    // =====================================================================
    // Le secret ne sort jamais
    // =====================================================================

    #[Test]
    public function the_secret_is_never_rendered_in_the_list(): void
    {
        $secret = 'secret-partage-tres-confidentiel-ABCD';
        $this->store->addServer('https://bbb.example.test/api', $secret);

        $response = $this->get($this->sessionFor('admin'));

        self::assertStringNotContainsString($secret, $response->body);
        self::assertStringNotContainsString('secret-partage-tres-confidentiel', $response->body);
        // Masque avec les 4 derniers caractères : de quoi reconnaître, pas de
        // quoi rejouer.
        self::assertStringContainsString('••••••••ABCD', $response->body);
    }

    #[Test]
    public function editing_a_server_never_prefills_its_secret(): void
    {
        $secret = 'secret-a-ne-jamais-reafficher';
        $id = $this->store->addServer('https://bbb.example.test/api', $secret);

        $response = $this->get($this->sessionFor('admin'), ['edit' => (string) $id]);

        self::assertSame(200, $response->status);
        self::assertStringContainsString('Modifier le serveur', $response->body);
        self::assertStringNotContainsString($secret, $response->body);
        self::assertStringContainsString('conserver le secret actuel', $response->body);
    }

    // =====================================================================
    // Écritures
    // =====================================================================

    #[Test]
    public function an_admin_adds_a_server(): void
    {
        $session = $this->sessionFor('admin');
        $token = $this->csrf($session);

        $response = $this->post($session, [
            '_token' => $token,
            'action' => 'create',
            'base_url' => 'https://bbb.example.test/bigbluebutton/api',
            'secret' => 'secret-partage',
        ]);

        self::assertSame(302, $response->status);
        self::assertSame('/ext/bbb/admin/servers', $response->headers['Location']);

        $servers = $this->store->servers();
        self::assertCount(1, $servers);
        self::assertSame('https://bbb.example.test/bigbluebutton/api', $servers[0]['base_url']);
        self::assertSame('secret-partage', $servers[0]['secret']);
        self::assertSame(0, $servers[0]['scalelite_threshold']);
        self::assertTrue($servers[0]['enabled']);
    }

    #[Test]
    public function a_scalelite_server_stores_its_fixed_threshold(): void
    {
        // Sémantique iso-legacy : un Scalelite n'est jamais SONDÉ, sa charge est
        // la valeur configurée. La table doit donc la porter telle quelle.
        $session = $this->sessionFor('admin');
        $token = $this->csrf($session);

        $this->post($session, [
            '_token' => $token,
            'action' => 'create',
            'base_url' => 'https://scalelite.example.test/bigbluebutton/api',
            'secret' => 'secret-partage',
            'scalelite' => '1',
            'scalelite_threshold' => '120',
        ]);

        self::assertSame(120, $this->store->servers()[0]['scalelite_threshold']);
    }

    #[Test]
    public function an_empty_secret_on_update_keeps_the_existing_one(): void
    {
        // Corollaire du champ write-only : l'administrateur ne revoit jamais le
        // secret, donc ne peut pas le renvoyer — éditer l'URL ne doit pas
        // l'effacer.
        $id = $this->store->addServer('https://bbb.example.test/api', 'secret-initial');
        $session = $this->sessionFor('admin');
        $token = $this->csrf($session);

        $this->post($session, [
            '_token' => $token,
            'action' => 'update',
            'id' => (string) $id,
            'base_url' => 'https://bbb.example.test/bigbluebutton/api',
            'secret' => '',
            'enabled' => '1',
        ]);

        $server = $this->store->server($id);
        self::assertSame('secret-initial', $server['secret']);
        self::assertSame('https://bbb.example.test/bigbluebutton/api', $server['base_url']);
    }

    #[Test]
    public function a_server_can_be_disabled_and_deleted(): void
    {
        $id = $this->store->addServer('https://bbb.example.test/api', 'secret');
        $session = $this->sessionFor('admin');
        $token = $this->csrf($session);

        $this->post($session, ['_token' => $token, 'action' => 'toggle', 'id' => (string) $id]);
        self::assertFalse($this->store->server($id)['enabled']);

        $this->post($session, ['_token' => $token, 'action' => 'delete', 'id' => (string) $id]);
        self::assertNull($this->store->server($id));
    }

    // =====================================================================
    // Validation
    // =====================================================================

    #[Test]
    public function an_invalid_url_is_refused_and_nothing_is_written(): void
    {
        $session = $this->sessionFor('admin');
        $token = $this->csrf($session);

        foreach (['pas-une-url', 'ftp://bbb.example.test/api', 'https://bbb.example.test/api?x=1', ''] as $url) {
            $response = $this->post($session, [
                '_token' => $token,
                'action' => 'create',
                'base_url' => $url,
                'secret' => 'secret-partage',
            ]);

            self::assertSame(422, $response->status, 'URL refusée : ' . $url);
            self::assertSame([], $this->store->servers());
        }
    }

    #[Test]
    public function a_missing_secret_is_refused_on_creation(): void
    {
        $session = $this->sessionFor('admin');
        $token = $this->csrf($session);

        $response = $this->post($session, [
            '_token' => $token,
            'action' => 'create',
            'base_url' => 'https://bbb.example.test/api',
            'secret' => '',
        ]);

        self::assertSame(422, $response->status);
        self::assertStringContainsString('secret partagé est obligatoire', $response->body);
        self::assertSame([], $this->store->servers());
    }

    #[Test]
    public function a_scalelite_without_a_usable_threshold_is_refused(): void
    {
        $session = $this->sessionFor('admin');
        $token = $this->csrf($session);

        $response = $this->post($session, [
            '_token' => $token,
            'action' => 'create',
            'base_url' => 'https://scalelite.example.test/api',
            'secret' => 'secret-partage',
            'scalelite' => '1',
            'scalelite_threshold' => '0',
        ]);

        self::assertSame(422, $response->status);
        self::assertSame([], $this->store->servers());
    }

    #[Test]
    public function an_http_url_is_accepted_but_never_silently(): void
    {
        // Décision : `http` reste possible (serveur de test sur réseau
        // d'établissement), mais l'avertissement est AFFICHÉ. Le secret partagé
        // circule dans chaque requête signée.
        $session = $this->sessionFor('admin');
        $token = $this->csrf($session);

        $this->post($session, [
            '_token' => $token,
            'action' => 'create',
            'base_url' => 'http://bbb.example.test/api',
            'secret' => 'secret-partage',
        ]);

        self::assertCount(1, $this->store->servers());

        $page = $this->get($session)->body;
        self::assertStringContainsString('circulera en clair', $page);
    }

    #[Test]
    public function the_url_validator_is_explicit_about_what_it_refuses(): void
    {
        self::assertSame('', ServersController::validateBaseUrl('https://bbb.example.test/api')['error']);
        self::assertNotSame('', ServersController::validateBaseUrl('https://user:pass@bbb.example.test/api')['error']);
        self::assertNotSame('', ServersController::validateBaseUrl('https://bbb.example.test/api#x')['error']);
        self::assertSame(
            'https://bbb.example.test/api',
            ServersController::validateBaseUrl('https://bbb.example.test/api/')['url'],
            'le slash final est normalisé',
        );
    }

    // =====================================================================
    // Anti-CSRF
    // =====================================================================

    #[Test]
    public function a_post_without_a_valid_token_is_refused(): void
    {
        $session = $this->sessionFor('admin');
        $this->csrf($session);

        $response = $this->post($session, [
            '_token' => 'jeton-fabrique-ailleurs',
            'action' => 'create',
            'base_url' => 'https://bbb.example.test/api',
            'secret' => 'secret-partage',
        ]);

        self::assertSame(403, $response->status);
        self::assertSame([], $this->store->servers());
    }

    // =====================================================================
    // Test de connexion
    // =====================================================================

    #[Test]
    public function rendering_the_page_never_calls_a_bbb_server(): void
    {
        // LA garde de conception : le serveur intégré de PHP est
        // mono-processus. Un appel BBB au rendu et un serveur lent gèlent toute
        // l'extension, sonde de santé comprise.
        $this->store->addServer('https://bbb.example.test/api', 'secret');

        $this->get($this->sessionFor('admin'));

        self::assertSame([], $this->api->calls);
    }

    #[Test]
    public function the_connection_test_is_an_explicit_action_and_reports_its_outcome(): void
    {
        $id = $this->store->addServer('https://bbb.example.test/api', 'secret-partage');
        $session = $this->sessionFor('admin');
        $token = $this->csrf($session);

        $response = $this->post($session, ['_token' => $token, 'action' => 'test', 'id' => (string) $id]);

        self::assertSame(302, $response->status);
        self::assertSame(
            [['url' => 'https://bbb.example.test/api', 'secret' => 'secret-partage']],
            $this->api->calls,
        );

        $page = $this->get($session)->body;
        self::assertStringContainsString('Connexion réussie', $page);
        self::assertStringContainsString('bbb.example.test', $page);
        self::assertStringNotContainsString('secret-partage', $page);
    }

    #[Test]
    public function a_failing_connection_test_names_the_cause(): void
    {
        $id = $this->store->addServer('https://bbb.example.test/api', 'mauvais-secret');
        $api = new RecordingBbbApiClient(ConnectionResult::invalidSecret());
        $session = $this->sessionFor('admin');
        $token = $this->csrf($session);

        $controller = new ServersController(
            $this->store,
            $api,
            new View(dirname(__DIR__) . '/views'),
            $this->env,
        );

        $controller->handle(
            new Request('POST', '/admin/servers', [], ['_token' => $token, 'action' => 'test', 'id' => (string) $id]),
            $session,
        );

        $page = $controller->handle(new Request('GET', '/admin/servers'), $session)->body;

        self::assertStringContainsString('Secret invalide', $page);
        self::assertStringNotContainsString('mauvais-secret', $page);
    }
}

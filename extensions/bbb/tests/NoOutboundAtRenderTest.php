<?php

declare(strict_types=1);

namespace SambaEdu\ExtBbb\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SambaEdu\ExtBbb\App;
use SambaEdu\ExtBbb\Env;
use SambaEdu\ExtBbb\Http\ArraySessionStore;
use SambaEdu\ExtBbb\Http\Request;
use SambaEdu\ExtBbb\Identity;
use SambaEdu\ExtBbb\Rooms\Room;
use SambaEdu\ExtBbb\Tests\Support\FakeJsonHttpClient;
use SambaEdu\ExtBbb\Tests\Support\RoomsWorkbench;
use SambaEdu\ExtBbb\View;

/**
 * Story 57.2 — **AUCUN APPEL SORTANT AU RENDU D'UNE PAGE.**
 *
 * ══════════════════════════════════════════════════════════════════════════
 *  POURQUOI C'EST UNE PROPRIÉTÉ, ET PAS UNE OPTIMISATION
 *
 *  L'extension est servie par le serveur HTTP intégré de PHP, mono-processus
 *  par nature (décision D2, faiblesse assumée et compensée). Un appel vers un
 *  serveur BigBlueButton au rendu d'une page, c'est :
 *
 *  - la liste des salons qui pend pendant que le serveur de visioconférence
 *    est lent, pour TOUT LE MONDE en même temps ;
 *  - et, sur `GET /`, une sonde de santé qui rapporte « injoignable » une
 *    extension parfaitement vivante — parce qu'un TIERS est tombé.
 *
 *  D'où la règle, vérifiée ici plutôt qu'écrite : les pages lisent SQLite, les
 *  actes appellent le réseau, et un acte est toujours un POST.
 * ══════════════════════════════════════════════════════════════════════════
 */
final class NoOutboundAtRenderTest extends TestCase
{
    private RoomsWorkbench $bench;

    private string $token = '';

    protected function setUp(): void
    {
        $this->bench = new RoomsWorkbench();

        $serverId = $this->bench->store->addServer('https://bbb.example.test/bigbluebutton/api', 'secret-serveur');
        $roomId = $this->bench->store->addRoom(
            'Cours de mathématiques',
            'prof.martin',
            'Madame Martin',
            Room::VISIBILITY_CLASSE,
            ['4B'],
        );
        $this->bench->store->markStarted($roomId, $serverId);

        $this->token = $this->bench->store->roomsVisibleTo(
            new Identity('prof.martin', 'Madame Martin', 'prof', ['4B'])
        )[0]->token;
    }

    protected function tearDown(): void
    {
        $this->bench->destroy();
    }

    #[Test]
    public function listing_the_rooms_never_calls_a_bbb_server(): void
    {
        foreach ([
            $this->bench->sessionFor('prof', 'prof.martin', 'Madame Martin', ['4B']),
            $this->bench->sessionFor('eleve', 'paul.durand', 'Paul Durand', ['4B']),
            $this->bench->sessionFor('admin', 'root', 'Admin', []),
        ] as $session) {
            self::assertSame(200, $this->bench->get($session)->status);
        }

        self::assertSame(0, $this->bench->api->outboundCalls());
    }

    #[Test]
    public function creating_a_room_never_calls_a_bbb_server_either(): void
    {
        // Créer ≠ démarrer : deux actes, deux boutons. Le premier n'écrit qu'une
        // ligne ; le second seul sort du serveur.
        $session = $this->bench->sessionFor('prof', 'prof.martin', 'Madame Martin', ['4B']);

        $this->bench->post($session, '/rooms', ['name' => 'Nouveau salon', 'visibility' => Room::VISIBILITY_ETAB]);

        self::assertSame(0, $this->bench->api->outboundCalls());
    }

    #[Test]
    public function starting_a_room_emits_exactly_one_call(): void
    {
        $session = $this->bench->sessionFor('prof', 'prof.martin', 'Madame Martin', ['4B']);

        $this->bench->post($session, '/rooms/start', ['token' => $this->token]);

        self::assertCount(1, $this->bench->api->created);
        self::assertSame([], $this->bench->api->probed);
        self::assertSame(1, $this->bench->api->outboundCalls());
        self::assertCount(1, $this->bench->api->joins, 'la fabrique d\'URL est LOCALE : elle ne compte pas');
    }

    #[Test]
    public function joining_a_room_emits_exactly_one_call(): void
    {
        $session = $this->bench->sessionFor('eleve', 'paul.durand', 'Paul Durand', ['4B']);

        $this->bench->post($session, '/rooms/join', ['token' => $this->token]);

        self::assertCount(1, $this->bench->api->probed);
        self::assertSame([], $this->bench->api->created);
        self::assertSame(1, $this->bench->api->outboundCalls());
    }

    #[Test]
    public function deleting_a_room_never_calls_a_bbb_server(): void
    {
        $session = $this->bench->sessionFor('prof', 'prof.martin', 'Madame Martin', ['4B']);

        $this->bench->post($session, '/rooms/delete', ['token' => $this->token]);

        self::assertSame(0, $this->bench->api->outboundCalls());
    }

    // =====================================================================
    // La sonde de santé reste ce qu'elle est
    // =====================================================================

    #[Test]
    public function the_health_probe_still_touches_absolutely_nothing(): void
    {
        // Invariant de 57.1, re-vérifié depuis que l'accueil renvoie vers les
        // salons : la racine ouvre toujours ZÉRO base, ZÉRO fournisseur
        // d'identité, ZÉRO serveur de visioconférence — un LIEN vers `/rooms`,
        // pas une liste incorporée.
        $directory = sys_get_temp_dir() . '/ext-bbb-probe-' . bin2hex(random_bytes(6));
        mkdir($directory, 0700, true);

        $env = Env::capture([
            'SE5_EXT_BASE_PATH' => '/ext/bbb',
            'SE5_OIDC_ISSUER' => 'https://se5.example.test',
            'SE5_OIDC_CLIENT_ID' => 'client',
            'SE5_OIDC_CLIENT_SECRET' => 'secret',
            'SE5_OIDC_REDIRECT_URI' => '/ext/bbb/oidc/callback',
            'STATE_DIRECTORY' => $directory,
        ]);

        $session = new ArraySessionStore();
        (new Identity('prof.martin', 'Madame Martin', 'prof', ['4B']))->storeIn($session);

        $http = new FakeJsonHttpClient();
        $api = $this->bench->api;

        $app = new App($env, new View(dirname(__DIR__) . '/views'), $session, $api, $http);
        $response = $app->handle(new Request('GET', '/'));

        self::assertSame(200, $response->status);
        self::assertStringContainsString('/ext/bbb/rooms', $response->body, 'un lien vers les salons');
        self::assertStringNotContainsString('Cours de mathématiques', $response->body, 'et surtout pas la liste');

        self::assertSame(0, $api->outboundCalls());
        self::assertSame([], $http->getUrls);
        self::assertSame([], $http->posts);
        self::assertFileDoesNotExist($directory . '/database.sqlite', 'la racine n\'ouvre pas la base');

        @rmdir($directory);
    }

    #[Test]
    public function a_corrupt_database_breaks_the_rooms_page_but_never_the_probe(): void
    {
        // Comportement VOULU : `/rooms` rend une page d'erreur (le processus
        // répond, c'est ce que la sonde mesure), et `/` reste verte.
        $directory = sys_get_temp_dir() . '/ext-bbb-corrupt-' . bin2hex(random_bytes(6));
        mkdir($directory, 0700, true);
        file_put_contents($directory . '/database.sqlite', 'ceci n\'est pas une base SQLite');

        $env = Env::capture([
            'SE5_EXT_BASE_PATH' => '/ext/bbb',
            'SE5_OIDC_ISSUER' => 'https://se5.example.test',
            'STATE_DIRECTORY' => $directory,
        ]);

        $session = new ArraySessionStore();
        (new Identity('prof.martin', 'Madame Martin', 'prof', ['4B']))->storeIn($session);

        $app = new App($env, new View(dirname(__DIR__) . '/views'), $session, $this->bench->api, new FakeJsonHttpClient());

        self::assertSame(200, $app->handle(new Request('GET', '/'))->status);
        self::assertSame(500, $app->handle(new Request('GET', '/rooms'))->status);

        @unlink($directory . '/database.sqlite');
        @rmdir($directory);
    }

    #[Test]
    public function the_room_actions_are_all_post_only(): void
    {
        // Un GET mutateur serait préchargé par un navigateur au survol du lien,
        // rejouable sans jeton anti-CSRF, et — sur un serveur mono-processus —
        // un moyen commode de bloquer l'extension entière.
        $directory = sys_get_temp_dir() . '/ext-bbb-methods-' . bin2hex(random_bytes(6));
        mkdir($directory, 0700, true);

        $env = Env::capture([
            'SE5_EXT_BASE_PATH' => '/ext/bbb',
            'SE5_OIDC_ISSUER' => 'https://se5.example.test',
            'STATE_DIRECTORY' => $directory,
        ]);

        $session = new ArraySessionStore();
        (new Identity('prof.martin', 'Madame Martin', 'prof', ['4B']))->storeIn($session);

        $app = new App($env, new View(dirname(__DIR__) . '/views'), $session, $this->bench->api, new FakeJsonHttpClient());

        foreach (['/rooms/start', '/rooms/join', '/rooms/delete'] as $path) {
            $response = $app->handle(new Request('GET', $path, ['token' => $this->token]));

            self::assertSame(405, $response->status, $path);
        }

        self::assertSame(0, $this->bench->api->outboundCalls());

        foreach (glob($directory . '/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($directory);
    }
}

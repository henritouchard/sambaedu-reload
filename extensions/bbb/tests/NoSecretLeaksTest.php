<?php

declare(strict_types=1);

namespace SambaEdu\ExtBbb\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SambaEdu\ExtBbb\Admin\ServersController;
use SambaEdu\ExtBbb\Bbb\RunningResult;
use SambaEdu\ExtBbb\Http\ArraySessionStore;
use SambaEdu\ExtBbb\Http\Request;
use SambaEdu\ExtBbb\Identity;
use SambaEdu\ExtBbb\Rooms\Room;
use SambaEdu\ExtBbb\Tests\Support\RoomsWorkbench;
use SambaEdu\ExtBbb\View;

/**
 * Story 57.2 — **AUCUN SECRET NE TRAVERSE LE NAVIGATEUR.**
 *
 * ══════════════════════════════════════════════════════════════════════════
 *  LE DÉFAUT LEGACY, MOT POUR MOT
 *
 *  SE4 servait, dans le HTML de sa page de jonction, trois champs cachés :
 *  `meetingId`, `attendedPW` et **`moderatorPW`**. N'importe qui pouvait lire
 *  la source de la page, récupérer le mot de passe modérateur d'un salon
 *  qu'il n'avait pas créé, et entrer en modérateur dans le cours d'un
 *  collègue. Le défaut ne demandait aucun outil : `Ctrl+U`.
 *
 *  Ce fichier cherche les valeurs RÉELLES posées en fixture dans le corps de
 *  chaque page rendue. Il ne cherche pas un motif ni un nom de champ : les
 *  chaînes elles-mêmes, celles qui ouvriraient la conférence.
 * ══════════════════════════════════════════════════════════════════════════
 */
final class NoSecretLeaksTest extends TestCase
{
    private RoomsWorkbench $bench;

    private string $token = '';

    private int $roomId = 0;

    /** @var array{attendee: string, moderator: string} */
    private array $secrets = ['attendee' => '', 'moderator' => ''];

    private const SERVER_SECRET = 'secret-partage-du-serveur-bbb-9f3b';

    protected function setUp(): void
    {
        $this->bench = new RoomsWorkbench();

        $serverId = $this->bench->store->addServer(
            'https://bbb.example.test/bigbluebutton/api',
            self::SERVER_SECRET,
        );

        $this->roomId = $this->bench->store->addRoom(
            'Cours de mathématiques',
            'prof.martin',
            'Madame Martin',
            Room::VISIBILITY_CLASSE,
            ['4B'],
        );

        $this->bench->store->markStarted($this->roomId, $serverId);

        $secrets = $this->bench->store->roomSecrets($this->roomId);
        self::assertNotNull($secrets);
        $this->secrets = $secrets;

        $this->token = $this->bench->store->roomsVisibleTo(
            new Identity('prof.martin', 'Madame Martin', 'prof', ['4B'])
        )[0]->token;
    }

    protected function tearDown(): void
    {
        $this->bench->destroy();
    }

    private function assertCarriesNoSecret(string $body, string $where): void
    {
        self::assertStringNotContainsString($this->secrets['attendee'], $body, $where . ' — mot de passe participant');
        self::assertStringNotContainsString($this->secrets['moderator'], $body, $where . ' — mot de passe modérateur');
        self::assertStringNotContainsString(self::SERVER_SECRET, $body, $where . ' — secret du serveur');
    }

    #[Test]
    public function no_page_of_the_rooms_feature_ever_carries_a_password(): void
    {
        $teacher = $this->bench->sessionFor('prof', 'prof.martin', 'Madame Martin', ['4B']);
        $pupil = $this->bench->sessionFor('eleve', 'paul.durand', 'Paul Durand', ['4B']);
        $stranger = $this->bench->sessionFor('eleve', 'jules.petit', 'Jules Petit', ['5A']);

        $pages = [
            'liste du créateur' => $this->bench->get($teacher)->body,
            'liste de l\'élève' => $this->bench->get($pupil)->body,
            'liste d\'un tiers' => $this->bench->get($stranger)->body,
            'formulaire en erreur' => $this->bench->post($teacher, '/rooms', [
                'name' => '',
                'visibility' => Room::VISIBILITY_ETAB,
            ])->body,
            'refus 404' => $this->bench->post($stranger, '/rooms/join', ['token' => $this->token])->body,
            'refus CSRF' => $this->bench->post($teacher, '/rooms', ['_token' => 'faux', 'name' => 'x'])->body,
        ];

        foreach ($pages as $where => $body) {
            self::assertNotSame('', $body, $where . ' doit bien rendre quelque chose');
            $this->assertCarriesNoSecret($body, $where);
        }
    }

    #[Test]
    public function the_closed_room_page_carries_no_password_either(): void
    {
        $this->bench->api->runningResult = RunningResult::notRunning();

        $pupil = $this->bench->sessionFor('eleve', 'paul.durand', 'Paul Durand', ['4B']);
        $body = $this->bench->post($pupil, '/rooms/join', ['token' => $this->token])->body;

        self::assertStringContainsString('n\'est pas ouvert', $body);
        $this->assertCarriesNoSecret($body, 'page « salon fermé »');
    }

    #[Test]
    public function the_only_place_a_password_ever_appears_is_a_location_header(): void
    {
        $pupil = $this->bench->sessionFor('eleve', 'paul.durand', 'Paul Durand', ['4B']);

        $response = $this->bench->post($pupil, '/rooms/join', ['token' => $this->token]);

        self::assertSame(302, $response->status);
        self::assertSame('', $response->body, 'une redirection ne rend AUCUNE page');
        self::assertStringContainsString(
            rawurlencode($this->secrets['attendee']),
            $response->headers['Location'],
            'l\'URL signée porte le mot de passe — c\'est sa seule sortie légitime',
        );

        $this->assertCarriesNoSecret($response->body, 'corps de la redirection');
    }

    #[Test]
    public function the_public_token_is_the_only_room_identifier_the_browser_sees(): void
    {
        // Contrôle POSITIF de l'ensemble : si les pages ne portaient RIEN, tous
        // les tests ci-dessus passeraient sans rien prouver. Le jeton, lui, doit
        // bien y être — c'est ce qui permet de désigner un salon dans une
        // action, sans rien décider.
        $body = $this->bench->get($this->bench->sessionFor('prof', 'prof.martin', 'Madame Martin', ['4B']))->body;

        self::assertStringContainsString($this->token, $body);
        self::assertStringContainsString('Cours de mathématiques', $body);
    }

    // =====================================================================
    // Story 57.3 — les pages neuves, et le mot de passe d'INVITATION
    // =====================================================================

    #[Test]
    public function none_of_the_new_pages_ever_carries_a_bigbluebutton_password(): void
    {
        $invitation = $this->bench->store->enableGuestAccess($this->roomId);

        $teacher = $this->bench->sessionFor('prof', 'prof.martin', 'Madame Martin', ['4B']);
        $pupil = $this->bench->sessionFor('eleve', 'paul.durand', 'Paul Durand', ['4B']);

        $pages = [
            'liste enrichie du créateur' => $this->bench->get($teacher)->body,
            'liste de l\'élève' => $this->bench->get($pupil)->body,
            'enregistrements' => $this->bench->getRecordings($teacher)->body,
            'refus 403 des enregistrements' => $this->bench->getRecordings($pupil)->body,
            'formulaire invité' => $this->bench->getGuest($invitation['token'])->body,
            'refus invité' => $this->bench->postGuest([
                'g' => $invitation['token'],
                'name' => 'Parent',
                'password' => 'faux',
            ])->body,
        ];

        foreach ($pages as $where => $body) {
            self::assertNotSame('', $body, $where . ' doit bien rendre quelque chose');
            $this->assertCarriesNoSecret($body, $where);
        }
    }

    #[Test]
    public function the_invitation_password_is_shown_to_its_creator_and_to_absolutely_nobody_else(): void
    {
        // C'est la seule valeur secrète de l'extension qui DOIT s'afficher : le
        // professeur ne peut pas la partager s'il ne la voit pas. Mais elle ne
        // sort que là.
        $invitation = $this->bench->store->enableGuestAccess($this->roomId);

        $teacher = $this->bench->sessionFor('prof', 'prof.martin', 'Madame Martin', ['4B']);
        $own = $this->bench->get($teacher)->body;

        // Contrôle POSITIF : sans lui, tout le reste passerait pour la mauvaise
        // raison (une page qui n'afficherait rien du tout).
        self::assertStringContainsString($invitation['password'], $own);
        self::assertStringContainsString($invitation['token'], $own);

        $elsewhere = [
            'liste d\'un élève de la classe' => $this->bench->get(
                $this->bench->sessionFor('eleve', 'paul.durand', 'Paul Durand', ['4B'])
            )->body,
            'liste d\'un autre professeur' => $this->bench->get(
                $this->bench->sessionFor('prof', 'prof.dupont', 'Monsieur Dupont', ['4B'])
            )->body,
            'liste d\'un administrateur' => $this->bench->get(
                $this->bench->sessionFor('admin', 'root', 'Admin', [])
            )->body,
            'refus public' => $this->bench->postGuest([
                'g' => $invitation['token'],
                'name' => 'Parent',
                'password' => 'faux',
            ])->body,
        ];

        foreach ($elsewhere as $where => $body) {
            self::assertStringNotContainsString($invitation['password'], $body, $where);
            self::assertStringNotContainsString($invitation['token'], $body, $where);
        }

        // Le formulaire public, lui, RENVOIE le jeton dans son champ caché —
        // c'est la valeur que le visiteur a lui-même apportée dans son URL, il
        // ne peut rien y apprendre. Mais le mot de passe, jamais : la page le
        // DEMANDE, elle ne le donne pas.
        $form = $this->bench->getGuest($invitation['token'])->body;

        self::assertStringContainsString($invitation['token'], $form);
        self::assertStringNotContainsString($invitation['password'], $form);
    }

    #[Test]
    public function the_guest_join_url_exists_only_in_a_location_header(): void
    {
        $invitation = $this->bench->store->enableGuestAccess($this->roomId);

        $response = $this->bench->postGuest([
            'g' => $invitation['token'],
            'name' => 'Monsieur Durand',
            'password' => $invitation['password'],
        ]);

        self::assertSame(302, $response->status);
        self::assertSame('', $response->body);
        self::assertStringContainsString(rawurlencode($this->secrets['attendee']), $response->headers['Location']);
        self::assertStringNotContainsString(rawurlencode($this->secrets['moderator']), $response->headers['Location']);
    }

    #[Test]
    public function the_admin_servers_page_still_hides_its_secret(): void
    {
        // Non-régression de 57.1 depuis la factorisation du jeton anti-CSRF.
        $session = new ArraySessionStore();
        (new Identity('root', 'Admin', 'admin', []))->storeIn($session);

        $controller = new ServersController(
            $this->bench->store,
            $this->bench->api,
            new View(dirname(__DIR__) . '/views'),
            $this->bench->env,
        );

        $body = $controller->handle(new Request('GET', '/admin/servers'), $session)->body;

        self::assertStringNotContainsString(self::SERVER_SECRET, $body);
        self::assertStringContainsString('••••••••', $body);
    }
}

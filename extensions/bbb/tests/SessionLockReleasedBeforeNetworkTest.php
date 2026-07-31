<?php

declare(strict_types=1);

namespace SambaEdu\ExtBbb\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SambaEdu\ExtBbb\Bbb\RecordingItem;
use SambaEdu\ExtBbb\Bbb\RecordingsResult;
use SambaEdu\ExtBbb\Identity;
use SambaEdu\ExtBbb\Rooms\Room;
use SambaEdu\ExtBbb\Tests\Support\RoomsWorkbench;

/**
 * Story 57.2, review #1 — **LE VERROU D'ÉTAT NE TRAVERSE JAMAIS LE RÉSEAU.**
 *
 * ══════════════════════════════════════════════════════════════════════════
 *  POURQUOI C'EST UNE PROPRIÉTÉ, ET PAS UNE OPTIMISATION
 *
 *  Le mécanisme natif de PHP verrouille le fichier d'état pendant TOUTE la
 *  requête : deux requêtes portant le même cookie se sérialisent, la seconde
 *  attend la première. Tenir ce verrou pendant un appel BigBlueButton borné à
 *  8 s a deux effets, et le second se voit tous les jours :
 *
 *   1. sur le serveur intégré à 4 workers (D2), autant de workers immobilisés
 *      par un seul compte — la review y voyait une amplification ouverte à tous
 *      les rôles authentifiés, là où 57.1 ne l'ouvrait qu'à `admin` ;
 *   2. **les autres onglets de la MÊME personne se bloquent** : un prof dont le
 *      démarrage traîne ne peut même plus recharger sa liste de salons. Ce
 *      n'est pas une attaque, c'est le lundi matin.
 *
 *  D'où la règle : relâcher le verrou avant tout appel sortant, et le laisser
 *  se reprendre tout seul à l'écriture suivante.
 * ══════════════════════════════════════════════════════════════════════════
 *
 * Les points de sortie sont TOUS couverts — `createMeeting`, `isMeetingRunning`,
 * le test de connexion de 57.1, puis, depuis la story 57.3, `getRecordings` et
 * `deleteRecordings` : une règle vraie à quatre endroits sur cinq n'est pas une
 * règle.
 *
 * Ce fichier porte aussi (review #3) les gardes CSRF des quatre routes mutantes :
 * même sujet, une classe de choses qui doivent être vraies AVANT qu'une route
 * mutante ne fasse quoi que ce soit.
 */
final class SessionLockReleasedBeforeNetworkTest extends TestCase
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
    public function starting_a_room_releases_the_lock_before_calling_bigbluebutton(): void
    {
        $session = $this->bench->sessionFor('prof', 'prof.martin', 'Madame Martin', ['4B']);
        $before = $session->closes;

        $this->bench->post($session, '/rooms/start', ['token' => $this->token]);

        self::assertNotSame([], $this->bench->api->created, 'l\'appel sortant doit avoir eu lieu');
        self::assertGreaterThan($before, $session->closes, 'verrou non relâché avant createMeeting');
    }

    #[Test]
    public function joining_a_room_releases_the_lock_before_calling_bigbluebutton(): void
    {
        $session = $this->bench->sessionFor('eleve', 'paul.durand', 'Paul Durand', ['4B']);
        $before = $session->closes;

        $this->bench->post($session, '/rooms/join', ['token' => $this->token]);

        self::assertNotSame([], $this->bench->api->probed, 'l\'appel sortant doit avoir eu lieu');
        self::assertGreaterThan($before, $session->closes, 'verrou non relâché avant isMeetingRunning');
    }

    #[Test]
    public function a_refusal_reaches_neither_the_network_nor_the_lock(): void
    {
        // Contrôle NÉGATIF, et il compte double. Il prouve à la fois que le
        // refus ne coûte aucun appel sortant — donc qu'il ne se trahit pas par
        // sa durée — et que `close()` n'est pas appelé « à tout hasard » en tête
        // de requête : sans cela, les deux tests ci-dessus ne prouveraient rien.
        $session = $this->bench->sessionFor('eleve', 'sophie.bernard', 'Sophie Bernard', ['5A']);
        $before = $session->closes;

        $this->bench->post($session, '/rooms/join', ['token' => $this->token]);

        self::assertSame(0, $this->bench->api->outboundCalls(), 'un refus ne doit faire aucun appel sortant');
        self::assertSame($before, $session->closes, 'rien à relâcher quand rien n\'est appelé');
    }

    // ── Review #3 — le CSRF protège les QUATRE routes mutantes ───────────────
    //
    // Le contrôle est centralisé avant l'aiguillage, donc il les couvre par
    // construction — mais seule la création le prouvait par un test. Un refactor
    // qui déplacerait ce contrôle APRÈS l'aiguillage casserait `start`, `join` et
    // `delete` en silence, sans qu'aucune assertion ne bouge. Ces trois-là ferment
    // la porte, et affirment à chaque fois les DEUX conséquences : refus, et
    // surtout aucun appel sortant.

    #[Test]
    public function starting_a_room_without_a_valid_csrf_token_is_refused(): void
    {
        $session = $this->bench->sessionFor('prof', 'prof.martin', 'Madame Martin', ['4B']);
        $this->bench->csrf($session);

        $response = $this->bench->post($session, '/rooms/start', [
            '_token' => 'jeton-fabrique-ailleurs',
            'token' => $this->token,
        ]);

        self::assertSame(403, $response->status);
        self::assertSame(0, $this->bench->api->outboundCalls());
    }

    #[Test]
    public function joining_a_room_without_a_valid_csrf_token_is_refused(): void
    {
        $session = $this->bench->sessionFor('eleve', 'paul.durand', 'Paul Durand', ['4B']);
        $this->bench->csrf($session);

        $response = $this->bench->post($session, '/rooms/join', [
            '_token' => 'jeton-fabrique-ailleurs',
            'token' => $this->token,
        ]);

        self::assertSame(403, $response->status);
        self::assertSame(0, $this->bench->api->outboundCalls());
    }

    #[Test]
    public function deleting_a_room_without_a_valid_csrf_token_is_refused(): void
    {
        $session = $this->bench->sessionFor('prof', 'prof.martin', 'Madame Martin', ['4B']);
        $this->bench->csrf($session);

        $response = $this->bench->post($session, '/rooms/delete', [
            '_token' => 'jeton-fabrique-ailleurs',
            'token' => $this->token,
        ]);

        self::assertSame(403, $response->status);

        // Et le salon est TOUJOURS là : un 403 qui aurait quand même supprimé
        // serait le pire des deux mondes.
        self::assertNotSame([], $this->bench->store->roomsVisibleTo(
            new Identity('prof.martin', 'Madame Martin', 'prof', ['4B'])
        ));
    }

    #[Test]
    public function a_valid_csrf_token_does_let_the_action_through(): void
    {
        // CONTRÔLE POSITIF des trois tests ci-dessus : sans lui, ils passeraient
        // tout aussi bien si les routes étaient cassées pour tout le monde.
        $session = $this->bench->sessionFor('prof', 'prof.martin', 'Madame Martin', ['4B']);

        $response = $this->bench->post($session, '/rooms/start', ['token' => $this->token]);

        self::assertNotSame(403, $response->status);
        self::assertNotSame([], $this->bench->api->created);
    }

    // ── Story 57.3 — les DEUX nouveaux points de sortie ──────────────────
    //
    // La règle passe de trois points à cinq. Le parcours invité, lui, ne figure
    // pas ici : il n'a AUCUN état à relâcher — le contrôleur n'en reçoit pas.
    // C'est `GuestAccessTest` qui l'affirme, par le typage et par l'absence de
    // tout `Set-Cookie`.

    #[Test]
    public function listing_the_recordings_releases_the_lock_before_each_server_call(): void
    {
        // Deux serveurs ⇒ deux appels ⇒ deux relâchements. « Avant le réseau »
        // ne veut pas dire « une fois en début de requête ».
        $secondServer = $this->bench->store->addServer('https://bbb2.example.test/bigbluebutton/api', 'secret-2');
        $other = $this->bench->store->addRoom('Conseil', 'prof.martin', 'Madame Martin', Room::VISIBILITY_PRIVATE);
        $this->bench->store->markStarted($other, $secondServer);

        $session = $this->bench->sessionFor('prof', 'prof.martin', 'Madame Martin', ['4B']);
        $before = $session->closes;

        $this->bench->getRecordings($session);

        self::assertCount(2, $this->bench->api->listed, 'les deux appels sortants doivent avoir eu lieu');
        self::assertGreaterThanOrEqual($before + 2, $session->closes, 'un relâchement par appel sortant');
    }

    #[Test]
    public function deleting_a_recording_releases_the_lock_before_the_check_and_before_the_deletion(): void
    {
        $this->bench->api->recordingsByServer['https://bbb.example.test/bigbluebutton/api'] = RecordingsResult::ok([
            new RecordingItem('rec-1', $this->token, 1.0, 2.0, 'https://bbb.example.test/playback/rec-1', 10),
        ]);

        $session = $this->bench->sessionFor('prof', 'prof.martin', 'Madame Martin', ['4B']);
        $before = $session->closes;

        $this->bench->postRecordings($session, ['token' => $this->token, 'record' => 'rec-1']);

        self::assertCount(1, $this->bench->api->listed);
        self::assertCount(1, $this->bench->api->deleted);
        self::assertGreaterThanOrEqual($before + 2, $session->closes);
    }

    #[Test]
    public function a_recordings_refusal_reaches_neither_the_network_nor_the_lock(): void
    {
        // Contrôle NÉGATIF des deux tests ci-dessus : sans lui, ils passeraient
        // aussi bien si `close()` était posé « à tout hasard » en tête de
        // requête — auquel cas ils ne prouveraient plus rien.
        $session = $this->bench->sessionFor('eleve', 'paul.durand', 'Paul Durand', ['4B']);
        $before = $session->closes;

        self::assertSame(403, $this->bench->getRecordings($session)->status);

        self::assertSame(0, $this->bench->api->outboundCalls());
        self::assertSame($before, $session->closes);
    }

    #[Test]
    public function enabling_an_invitation_never_releases_the_lock_because_it_never_calls_out(): void
    {
        // Troisième contrôle négatif : l'invitation est un fait LOCAL. Rien à
        // dire à BigBlueButton, donc rien à relâcher.
        $session = $this->bench->sessionFor('prof', 'prof.martin', 'Madame Martin', ['4B']);
        $this->bench->csrf($session);
        $before = $session->closes;

        $this->bench->post($session, '/rooms/guest/enable', ['token' => $this->token]);
        $this->bench->post($session, '/rooms/guest/revoke', ['token' => $this->token]);

        self::assertSame(0, $this->bench->api->outboundCalls());
        self::assertSame($before, $session->closes);
    }

    #[Test]
    public function the_guest_invitation_routes_are_protected_by_the_csrf_token_too(): void
    {
        $session = $this->bench->sessionFor('prof', 'prof.martin', 'Madame Martin', ['4B']);
        $this->bench->csrf($session);

        foreach (['/rooms/guest/enable', '/rooms/guest/revoke'] as $path) {
            $response = $this->bench->post($session, $path, [
                '_token' => 'jeton-fabrique-ailleurs',
                'token' => $this->token,
            ]);

            self::assertSame(403, $response->status, $path);
        }

        // Et rien n'a été activé au passage : un 403 qui aurait quand même
        // publié un lien public serait le pire des deux mondes.
        $room = $this->bench->store->roomByToken($this->token);
        self::assertNotNull($room);
        self::assertNull($this->bench->store->guestInvitation($room->id));
    }

    #[Test]
    public function listing_the_rooms_never_releases_the_lock_because_it_never_calls_out(): void
    {
        // Second contrôle négatif, sur le chemin le plus fréquenté : une page de
        // consultation ne parle à personne, donc n'a rien à relâcher.
        $session = $this->bench->sessionFor('prof', 'prof.martin', 'Madame Martin', ['4B']);

        $this->bench->get($session);

        self::assertSame(0, $this->bench->api->outboundCalls());
        self::assertSame(0, $session->closes);
    }
}

<?php

declare(strict_types=1);

namespace SambaEdu\ExtBbb\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
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
 * Les trois points de sortie sont couverts — `createMeeting`, `isMeetingRunning`
 * et le test de connexion de 57.1 : une règle vraie à deux endroits sur trois
 * n'est pas une règle.
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

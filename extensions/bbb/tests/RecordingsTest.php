<?php

declare(strict_types=1);

namespace SambaEdu\ExtBbb\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SambaEdu\ExtBbb\Bbb\DeleteResult;
use SambaEdu\ExtBbb\Bbb\RecordingItem;
use SambaEdu\ExtBbb\Bbb\RecordingsResult;
use SambaEdu\ExtBbb\Rooms\Room;
use SambaEdu\ExtBbb\Tests\Support\RoomsWorkbench;

/**
 * Story 57.3 — **L'AC2 : « SES SALONS UNIQUEMENT », ET RIEN D'AUTRE.**
 *
 * ══════════════════════════════════════════════════════════════════════════
 *  LE BUG LEGACY QUE CE FICHIER ENTERRE
 *
 *  SE4 listait les enregistrements de tout l'établissement puis les triait en
 *  PHP : `explode('-', $meetingID)[2] == md5($login)`. Le segment n°2 n'était
 *  le bon que pour un salon SANS classes ; dès qu'un salon en portait, l'index
 *  se décalait et le professeur ne retrouvait plus ses propres enregistrements
 *  (carte du legacy §5). Ici la propriété est une COLONNE — `rooms.owner_sub` —
 *  et il n'y a plus rien à décoder.
 *
 *  La deuxième moitié compte autant : le filtre `meetingID` de la requête est
 *  un paramètre qu'on ENVOIE. La doublure de ce fichier en renvoie
 *  volontairement TROP, comme le ferait un serveur qui l'ignorerait, pour que
 *  la re-vérification côté extension prouve réellement quelque chose.
 * ══════════════════════════════════════════════════════════════════════════
 */
final class RecordingsTest extends TestCase
{
    private const SERVER_A = 'https://bbb-a.example.test/bigbluebutton/api';

    private const SERVER_B = 'https://bbb-b.example.test/bigbluebutton/api';

    private RoomsWorkbench $bench;

    private int $serverA = 0;

    private int $serverB = 0;

    private string $mathsToken = '';

    private string $conseilToken = '';

    private string $foreignToken = '';

    private int $mathsId = 0;

    protected function setUp(): void
    {
        $this->bench = new RoomsWorkbench();

        $this->serverA = $this->bench->store->addServer(self::SERVER_A, 'secret-a');
        $this->serverB = $this->bench->store->addServer(self::SERVER_B, 'secret-b');

        // Deux salons à MOI, sur deux serveurs différents…
        $this->mathsId = $this->bench->store->addRoom(
            'Cours de mathématiques',
            'prof.martin',
            'Madame Martin',
            Room::VISIBILITY_CLASSE,
            ['4B'],
        );
        $this->bench->store->markStarted($this->mathsId, $this->serverA);

        $conseilId = $this->bench->store->addRoom(
            'Conseil de classe',
            'prof.martin',
            'Madame Martin',
            Room::VISIBILITY_PRIVATE,
        );
        $this->bench->store->markStarted($conseilId, $this->serverB);

        // … un salon jamais démarré, qui n'a donc pas de serveur…
        $this->bench->store->addRoom('Salon neuf', 'prof.martin', 'Madame Martin', Room::VISIBILITY_ETAB);

        // … et le salon d'un collègue, sur le MÊME serveur que le mien.
        $foreignId = $this->bench->store->addRoom(
            'Cours d\'anglais',
            'prof.dupont',
            'Monsieur Dupont',
            Room::VISIBILITY_ETAB,
        );
        $this->bench->store->markStarted($foreignId, $this->serverA);

        $this->mathsToken = $this->tokenOf($this->mathsId);
        $this->conseilToken = $this->tokenOf($conseilId);
        $this->foreignToken = $this->tokenOf($foreignId);
    }

    protected function tearDown(): void
    {
        $this->bench->destroy();
    }

    private function tokenOf(int $roomId): string
    {
        foreach ($this->bench->store->roomsOwnedBy('prof.martin') as $room) {
            if ($room->id === $roomId) {
                return $room->token;
            }
        }

        foreach ($this->bench->store->roomsOwnedBy('prof.dupont') as $room) {
            if ($room->id === $roomId) {
                return $room->token;
            }
        }

        self::fail('salon introuvable');
    }

    private function record(string $meetingId, string $recordId, float $startTime = 1_780_000_000_000.0): RecordingItem
    {
        return new RecordingItem(
            recordId: $recordId,
            meetingId: $meetingId,
            startTime: $startTime,
            endTime: $startTime + 3_600_000,
            playbackUrl: 'https://bbb.example.test/playback/' . $recordId,
            lengthMinutes: 60,
        );
    }

    // =====================================================================
    // « SES salons uniquement »
    // =====================================================================

    #[Test]
    public function a_teacher_never_sees_the_recordings_of_another_teacher_even_when_the_server_sends_them(): void
    {
        // Le serveur A renvoie TROIS enregistrements : le mien, celui d'un
        // collègue, et celui d'un salon qui n'existe plus en base. C'est
        // exactement ce que ferait un serveur ignorant le paramètre `meetingID`
        // — et c'est le scénario qui aurait démasqué `explode('-', …)[2]`.
        $this->bench->api->recordingsByServer[self::SERVER_A] = RecordingsResult::ok([
            $this->record($this->mathsToken, 'rec-a-moi'),
            $this->record($this->foreignToken, 'rec-du-collegue'),
            $this->record('salon-efface-depuis', 'rec-orphelin'),
        ]);

        $session = $this->bench->sessionFor('prof', 'prof.martin', 'Madame Martin', ['4B']);
        $body = $this->bench->getRecordings($session)->body;

        self::assertStringContainsString('rec-a-moi', $body);
        self::assertStringContainsString('Cours de mathématiques', $body);

        self::assertStringNotContainsString('rec-du-collegue', $body, 'jamais les enregistrements d\'autrui');
        self::assertStringNotContainsString('anglais', $body);
        self::assertStringNotContainsString('rec-orphelin', $body, 'ni ceux d\'un salon disparu');
    }

    #[Test]
    public function a_recording_whose_room_was_deleted_becomes_invisible_by_construction(): void
    {
        $this->bench->api->recordingsByServer[self::SERVER_A] = RecordingsResult::ok([
            $this->record($this->mathsToken, 'rec-a-moi'),
        ]);

        $session = $this->bench->sessionFor('prof', 'prof.martin', 'Madame Martin', ['4B']);
        self::assertStringContainsString('rec-a-moi', $this->bench->getRecordings($session)->body);

        // Décision de la story : pas de table d'archives, pas de purge côté
        // BigBlueButton (ce serait destructeur et irréversible). Sans ligne
        // `rooms`, il n'existe simplement plus de requête qui trouve cet
        // enregistrement — l'invisibilité est une conséquence, pas un filtre.
        $this->bench->store->deleteRoom($this->mathsId);

        $body = $this->bench->getRecordings($session)->body;

        self::assertStringNotContainsString('rec-a-moi', $body);
        self::assertStringNotContainsString('Cours de mathématiques', $body);
    }

    #[Test]
    public function each_server_is_asked_only_about_the_rooms_it_hosts(): void
    {
        $session = $this->bench->sessionFor('prof', 'prof.martin', 'Madame Martin', ['4B']);
        $this->bench->getRecordings($session);

        self::assertCount(2, $this->bench->api->listed, 'un appel par serveur, pas un par salon');

        $byUrl = [];
        foreach ($this->bench->api->listed as $call) {
            $byUrl[$call['url']] = $call['meetingIds'];
        }

        self::assertSame([$this->mathsToken], $byUrl[self::SERVER_A]);
        self::assertSame([$this->conseilToken], $byUrl[self::SERVER_B]);

        // Le salon d'un collègue est sur le serveur A : son jeton ne doit
        // apparaître dans AUCUNE requête.
        foreach ($this->bench->api->listed as $call) {
            self::assertNotContains($this->foreignToken, $call['meetingIds']);
        }
    }

    #[Test]
    public function a_room_that_was_never_started_costs_no_call_at_all(): void
    {
        $session = $this->bench->sessionFor('prof', 'prof.martin', 'Madame Martin', ['4B']);
        $this->bench->getRecordings($session);

        // Trois salons à moi, deux serveurs : deux appels. Le salon neuf n'a
        // jamais tourné, il n'a rien pu enregistrer, on ne demande rien pour lui.
        self::assertSame(2, $this->bench->api->outboundCalls());
    }

    #[Test]
    public function a_removed_or_disabled_server_is_never_called_and_the_page_says_so(): void
    {
        $this->bench->store->setServerEnabled($this->serverB, false);

        $session = $this->bench->sessionFor('prof', 'prof.martin', 'Madame Martin', ['4B']);
        $response = $this->bench->getRecordings($session);

        self::assertSame(200, $response->status);
        self::assertCount(1, $this->bench->api->listed);
        self::assertSame(self::SERVER_A, $this->bench->api->listed[0]['url']);
        self::assertStringContainsString('retiré ou désactivé', $response->body);
    }

    #[Test]
    public function an_unreachable_server_never_hides_the_other_one(): void
    {
        $this->bench->api->recordingsByServer[self::SERVER_A] = RecordingsResult::unreachable();
        $this->bench->api->recordingsByServer[self::SERVER_B] = RecordingsResult::ok([
            $this->record($this->conseilToken, 'rec-du-conseil'),
        ]);

        $session = $this->bench->sessionFor('prof', 'prof.martin', 'Madame Martin', ['4B']);
        $response = $this->bench->getRecordings($session);

        self::assertSame(200, $response->status);
        self::assertStringContainsString('injoignable', $response->body);
        self::assertStringContainsString('rec-du-conseil', $response->body, 'l\'autre serveur s\'affiche quand même');
    }

    #[Test]
    public function no_recordings_at_all_is_an_empty_list_and_not_an_error(): void
    {
        $session = $this->bench->sessionFor('prof', 'prof.martin', 'Madame Martin', ['4B']);
        $response = $this->bench->getRecordings($session);

        self::assertSame(200, $response->status);
        self::assertStringContainsString('Aucun enregistrement', $response->body);
        self::assertStringNotContainsString('inattendue', $response->body);
        self::assertStringNotContainsString('injoignable', $response->body);
    }

    #[Test]
    public function the_recordings_are_shown_newest_first(): void
    {
        $this->bench->api->recordingsByServer[self::SERVER_A] = RecordingsResult::ok([
            $this->record($this->mathsToken, 'rec-ancien', 1_700_000_000_000.0),
            $this->record($this->mathsToken, 'rec-recent', 1_780_000_000_000.0),
        ]);

        $session = $this->bench->sessionFor('prof', 'prof.martin', 'Madame Martin', ['4B']);
        $body = $this->bench->getRecordings($session)->body;

        // Comparateur ENTIER : le legacy passait à `usort` une fonction qui
        // rendait un booléen, ce qui produit un ordre arbitraire (défaut §9.10).
        self::assertLessThan(
            strpos($body, 'rec-ancien'),
            strpos($body, 'rec-recent'),
        );
    }

    // =====================================================================
    // Qui a le droit d'ouvrir cette page
    // =====================================================================

    #[Test]
    public function only_a_teacher_reaches_the_recordings_page(): void
    {
        foreach (['eleve', 'administratif', 'admin'] as $role) {
            $session = $this->bench->sessionFor($role, 'quelqu-un', 'Quelqu\'un', []);
            $response = $this->bench->getRecordings($session);

            self::assertSame(403, $response->status, $role);
        }

        self::assertSame(0, $this->bench->api->outboundCalls(), 'un refus ne parle à personne');
    }

    #[Test]
    public function an_anonymous_visitor_is_sent_to_the_login(): void
    {
        $response = $this->bench->recordingsController()->handle(
            new \SambaEdu\ExtBbb\Http\Request('GET', '/recordings'),
            new \SambaEdu\ExtBbb\Http\ArraySessionStore(),
        );

        self::assertSame(302, $response->status);
        self::assertStringContainsString('/login', $response->headers['Location']);
        self::assertSame(0, $this->bench->api->outboundCalls());
    }

    #[Test]
    public function the_link_to_the_recordings_only_shows_up_for_a_teacher(): void
    {
        $teacher = $this->bench->sessionFor('prof', 'prof.martin', 'Madame Martin', ['4B']);
        $pupil = $this->bench->sessionFor('eleve', 'paul.durand', 'Paul Durand', ['4B']);

        self::assertStringContainsString('/ext/bbb/recordings', $this->bench->get($teacher)->body);
        self::assertStringNotContainsString('/ext/bbb/recordings', $this->bench->get($pupil)->body);
    }

    // =====================================================================
    // Suppression : la propriété se PROUVE côté serveur
    // =====================================================================

    #[Test]
    public function deleting_verifies_the_ownership_against_the_server_before_deleting(): void
    {
        $this->bench->api->recordingsByServer[self::SERVER_A] = RecordingsResult::ok([
            $this->record($this->mathsToken, 'rec-a-moi'),
        ]);

        $session = $this->bench->sessionFor('prof', 'prof.martin', 'Madame Martin', ['4B']);
        $response = $this->bench->postRecordings($session, [
            'token' => $this->mathsToken,
            'record' => 'rec-a-moi',
        ]);

        self::assertSame(302, $response->status);

        // DEUX appels, et la vérification d'abord : la liste est interrogée sur
        // le seul `recordID` demandé, puis la suppression est émise.
        self::assertCount(1, $this->bench->api->listed);
        self::assertSame('rec-a-moi', $this->bench->api->listed[0]['recordId']);
        self::assertSame(self::SERVER_A, $this->bench->api->listed[0]['url']);

        self::assertCount(1, $this->bench->api->deleted);
        self::assertSame('rec-a-moi', $this->bench->api->deleted[0]['recordId']);
        self::assertSame(self::SERVER_A, $this->bench->api->deleted[0]['url']);
    }

    #[Test]
    public function a_record_id_that_belongs_to_another_room_deletes_absolutely_nothing(): void
    {
        // Le champ `record` du formulaire est une DEMANDE, pas une preuve. SE4
        // prenait même le serveur cible dans un champ caché non vérifié
        // (`bbb/records.php:38`).
        $this->bench->api->recordingsByServer[self::SERVER_A] = RecordingsResult::ok([
            $this->record($this->foreignToken, 'rec-du-collegue'),
        ]);

        $session = $this->bench->sessionFor('prof', 'prof.martin', 'Madame Martin', ['4B']);
        $response = $this->bench->postRecordings($session, [
            'token' => $this->mathsToken,
            'record' => 'rec-du-collegue',
        ]);

        self::assertSame(302, $response->status);
        self::assertCount(1, $this->bench->api->listed, 'la vérification a bien eu lieu');
        self::assertSame([], $this->bench->api->deleted, 'AUCUN appel de suppression n\'est émis');
    }

    #[Test]
    public function a_record_the_server_does_not_know_deletes_nothing_either(): void
    {
        $session = $this->bench->sessionFor('prof', 'prof.martin', 'Madame Martin', ['4B']);

        $this->bench->postRecordings($session, [
            'token' => $this->mathsToken,
            'record' => 'rec-invente-de-toutes-pieces',
        ]);

        self::assertSame([], $this->bench->api->deleted);
    }

    #[Test]
    public function deleting_a_recording_of_someone_elses_room_is_the_indistinct_404(): void
    {
        $session = $this->bench->sessionFor('prof', 'prof.martin', 'Madame Martin', ['4B']);
        $response = $this->bench->postRecordings($session, [
            'token' => $this->foreignToken,
            'record' => 'rec-du-collegue',
        ]);

        self::assertSame(404, $response->status);
        self::assertSame(0, $this->bench->api->outboundCalls(), 'ni vérification, ni suppression');
    }

    #[Test]
    public function deleting_without_a_valid_csrf_token_is_refused_before_any_call(): void
    {
        $session = $this->bench->sessionFor('prof', 'prof.martin', 'Madame Martin', ['4B']);
        $this->bench->csrf($session);

        $response = $this->bench->postRecordings($session, [
            '_token' => 'jeton-fabrique-ailleurs',
            'token' => $this->mathsToken,
            'record' => 'rec-a-moi',
        ]);

        self::assertSame(403, $response->status);
        self::assertSame(0, $this->bench->api->outboundCalls());
    }

    #[Test]
    public function a_deletion_refused_by_the_server_is_never_reported_as_a_success(): void
    {
        $this->bench->api->recordingsByServer[self::SERVER_A] = RecordingsResult::ok([
            $this->record($this->mathsToken, 'rec-a-moi'),
        ]);
        $this->bench->api->deleteResult = DeleteResult::refused();

        $session = $this->bench->sessionFor('prof', 'prof.martin', 'Madame Martin', ['4B']);
        $this->bench->postRecordings($session, [
            'token' => $this->mathsToken,
            'record' => 'rec-a-moi',
        ]);

        $body = $this->bench->getRecordings($session)->body;

        // ⚠️ Chercher un fragment SANS apostrophe : la vue échappe tout par
        // `bbb_e()`, et `'` y devient `&#039;`. Une assertion négative écrite
        // avec une apostrophe passerait pour la mauvaise raison.
        self::assertStringContainsString('pas supprimé cet enregistrement', $body);
        self::assertStringNotContainsString('Enregistrement supprimé', $body);
    }

    #[Test]
    public function deleting_on_a_disabled_server_says_so_without_calling_anything(): void
    {
        $this->bench->store->setServerEnabled($this->serverA, false);

        $session = $this->bench->sessionFor('prof', 'prof.martin', 'Madame Martin', ['4B']);
        $this->bench->postRecordings($session, [
            'token' => $this->mathsToken,
            'record' => 'rec-a-moi',
        ]);

        self::assertSame([], $this->bench->api->listed);
        self::assertSame([], $this->bench->api->deleted);
    }

    // =====================================================================
    // Aucun secret sur la page
    // =====================================================================

    #[Test]
    public function the_recordings_page_never_carries_a_password_or_a_server_secret(): void
    {
        $this->bench->api->recordingsByServer[self::SERVER_A] = RecordingsResult::ok([
            $this->record($this->mathsToken, 'rec-a-moi'),
        ]);

        $secrets = $this->bench->store->roomSecrets($this->mathsId);
        self::assertNotNull($secrets);

        $session = $this->bench->sessionFor('prof', 'prof.martin', 'Madame Martin', ['4B']);
        $body = $this->bench->getRecordings($session)->body;

        self::assertStringNotContainsString($secrets['attendee'], $body);
        self::assertStringNotContainsString($secrets['moderator'], $body);
        self::assertStringNotContainsString('secret-a', $body);
        self::assertStringNotContainsString('secret-b', $body);

        // Contrôle POSITIF : la page porte bien ce qu'elle doit porter.
        self::assertStringContainsString('https://bbb.example.test/playback/rec-a-moi', $body);
    }
}

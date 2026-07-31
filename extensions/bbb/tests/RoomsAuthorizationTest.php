<?php

declare(strict_types=1);

namespace SambaEdu\ExtBbb\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SambaEdu\ExtBbb\Bbb\RunningResult;
use SambaEdu\ExtBbb\Http\ArraySessionStore;
use SambaEdu\ExtBbb\Http\Request;
use SambaEdu\ExtBbb\Identity;
use SambaEdu\ExtBbb\Rooms\Room;
use SambaEdu\ExtBbb\Tests\Support\RoomsWorkbench;

/**
 * Story 57.2 — **LE TEST QUI PORTE L'AC2 : LA MATRICE D'AUTORISATION.**
 *
 * ══════════════════════════════════════════════════════════════════════════
 *  CE QUE CE FICHIER EMPÊCHE DE REVENIR
 *
 *  SE4 décidait tout côté client : le formulaire de jonction portait le
 *  `meetingID` et LES DEUX mots de passe en champs cachés, servis à qui
 *  visitait la page ; et le rôle se choisissait par un simple « si ce n'est
 *  pas un élève, prends le mot de passe modérateur » — sur n'importe quel
 *  salon, y compris celui d'un autre professeur.
 *
 *  Chaque test ci-dessous affirme donc DEUX choses : ce que le serveur décide,
 *  et **avec quel mot de passe** il fabrique l'URL de jonction. C'est le seul
 *  endroit où le rôle dans la conférence se lit.
 * ══════════════════════════════════════════════════════════════════════════
 */
final class RoomsAuthorizationTest extends TestCase
{
    private RoomsWorkbench $bench;

    /** Le salon de 4ᵉB, créé par sa professeure et déjà démarré une fois. */
    private string $token4B = '';

    private int $room4BId = 0;

    protected function setUp(): void
    {
        $this->bench = new RoomsWorkbench();

        $serverId = $this->bench->store->addServer('https://bbb.example.test/bigbluebutton/api', 'secret-serveur');

        $this->room4BId = $this->bench->store->addRoom(
            'Cours de mathématiques',
            'prof.martin',
            'Madame Martin',
            Room::VISIBILITY_CLASSE,
            ['4B'],
        );

        $this->bench->store->markStarted($this->room4BId, $serverId);

        $this->token4B = $this->tokenOf('Cours de mathématiques', $this->owner());
    }

    protected function tearDown(): void
    {
        $this->bench->destroy();
    }

    // ── Fixtures ─────────────────────────────────────────────────────────

    private function owner(): Identity
    {
        return new Identity('prof.martin', 'Madame Martin', 'prof', ['4B']);
    }

    private function tokenOf(string $name, Identity $viewer): string
    {
        foreach ($this->bench->store->roomsVisibleTo($viewer) as $room) {
            if ($room->name === $name) {
                return $room->token;
            }
        }

        self::fail('salon introuvable pour ce lecteur : ' . $name);
    }

    /** @return array{attendee: string, moderator: string} */
    private function secretsOf(int $roomId): array
    {
        $secrets = $this->bench->store->roomSecrets($roomId);
        self::assertNotNull($secrets);

        return $secrets;
    }

    private function lastJoinPassword(): string
    {
        self::assertCount(1, $this->bench->api->joins, 'exactement une URL de jonction fabriquée');

        return $this->bench->api->joins[0]['password'];
    }

    // =====================================================================
    // L'élève de la classe : il voit, il rejoint, en PARTICIPANT
    // =====================================================================

    #[Test]
    public function a_pupil_of_the_class_sees_the_room_and_joins_as_an_attendee(): void
    {
        $session = $this->bench->sessionFor('eleve', 'paul.durand', 'Paul Durand', ['4B']);

        self::assertStringContainsString('Cours de mathématiques', $this->bench->get($session)->body);

        $response = $this->bench->post($session, '/rooms/join', ['token' => $this->token4B]);

        self::assertSame(302, $response->status);
        self::assertSame('', $response->body, 'une redirection ne rend aucune page');
        self::assertSame($this->secretsOf($this->room4BId)['attendee'], $this->lastJoinPassword());
        self::assertSame('Paul Durand', $this->bench->api->joins[0]['fullName']);
    }

    #[Test]
    public function every_group_of_the_claim_counts_not_just_the_first_one(): void
    {
        // SE4 ne comparait que la PREMIÈRE classe de l'élève
        // (`list_classes(...)[0]`) : un élève multi-classes était exclu à tort
        // du salon de sa deuxième classe. Ici, toutes les entrées comptent.
        $session = $this->bench->sessionFor('eleve', 'lea.martin', 'Léa Martin', ['3A', '5C', '4B']);

        self::assertStringContainsString('Cours de mathématiques', $this->bench->get($session)->body);
        self::assertSame(302, $this->bench->post($session, '/rooms/join', ['token' => $this->token4B])->status);
    }

    // =====================================================================
    // L'élève d'une AUTRE classe : il ne voit rien, et l'URL directe échoue
    // =====================================================================

    #[Test]
    public function a_pupil_of_another_class_never_sees_the_room(): void
    {
        $session = $this->bench->sessionFor('eleve', 'jules.petit', 'Jules Petit', ['5A']);

        $body = $this->bench->get($session)->body;

        self::assertStringNotContainsString('Cours de mathématiques', $body);
        self::assertStringNotContainsString($this->token4B, $body);
    }

    #[Test]
    public function a_direct_url_by_a_pupil_of_another_class_is_refused_without_any_outgoing_call(): void
    {
        // LE cœur de l'AC : « l'accès direct par URL lui est refusé ». Et le
        // refus ne coûte AUCUN appel — sans quoi il se trahirait par le temps
        // qu'il met, et un refus chronométrable est un oracle de plus.
        $session = $this->bench->sessionFor('eleve', 'jules.petit', 'Jules Petit', ['5A']);

        $response = $this->bench->post($session, '/rooms/join', ['token' => $this->token4B]);

        self::assertSame(404, $response->status);
        self::assertSame(0, $this->bench->api->outboundCalls());
        self::assertSame([], $this->bench->api->joins);
    }

    #[Test]
    public function an_unknown_token_and_a_forbidden_room_are_indistinguishable(): void
    {
        // Deux réponses différentes diraient « ce salon existe, mais pas pour
        // vous » — c'est-à-dire un oracle d'existence, sur un identifiant qu'on
        // a précisément rendu non énumérable.
        $session = $this->bench->sessionFor('eleve', 'jules.petit', 'Jules Petit', ['5A']);

        $forbidden = $this->bench->post($session, '/rooms/join', ['token' => $this->token4B]);
        $unknown = $this->bench->post($session, '/rooms/join', ['token' => bin2hex(random_bytes(16))]);

        self::assertSame($forbidden->status, $unknown->status);
        self::assertSame($forbidden->body, $unknown->body);
        self::assertStringContainsString('bbb.rooms.not_found', $forbidden->body);
    }

    // =====================================================================
    // Le créateur : modérateur — et lui seul
    // =====================================================================

    #[Test]
    public function the_creator_starts_the_meeting_and_enters_as_a_moderator(): void
    {
        $session = $this->bench->sessionFor('prof', 'prof.martin', 'Madame Martin', ['4B']);

        $response = $this->bench->post($session, '/rooms/start', ['token' => $this->token4B]);

        self::assertSame(302, $response->status);
        self::assertCount(1, $this->bench->api->created, 'exactement un createMeeting');
        self::assertSame($this->secretsOf($this->room4BId)['moderator'], $this->lastJoinPassword());
    }

    #[Test]
    public function the_creator_joining_a_running_meeting_is_still_a_moderator(): void
    {
        $session = $this->bench->sessionFor('prof', 'prof.martin', 'Madame Martin', ['4B']);

        $this->bench->post($session, '/rooms/join', ['token' => $this->token4B]);

        self::assertSame($this->secretsOf($this->room4BId)['moderator'], $this->lastJoinPassword());
    }

    #[Test]
    public function a_colleague_of_the_same_class_is_a_participant_not_a_moderator(): void
    {
        // ⚠️ LE défaut legacy nommé : « tout non-élève est modérateur ». Un
        // professeur co-membre de la classe entre en PARTICIPANT, parce qu'il
        // n'a pas créé ce salon.
        $session = $this->bench->sessionFor('prof', 'prof.bernard', 'Monsieur Bernard', ['4B']);

        $this->bench->post($session, '/rooms/join', ['token' => $this->token4B]);

        self::assertSame($this->secretsOf($this->room4BId)['attendee'], $this->lastJoinPassword());
        self::assertNotSame($this->secretsOf($this->room4BId)['moderator'], $this->lastJoinPassword());
    }

    #[Test]
    public function a_staff_member_on_an_etab_room_is_a_participant_too(): void
    {
        $etabId = $this->bench->store->addRoom(
            'Conseil de classe',
            'prof.martin',
            'Madame Martin',
            Room::VISIBILITY_ETAB,
        );
        $this->bench->store->markStarted($etabId, 1);

        $staff = new Identity('agent.durand', 'Agent Durand', 'administratif', []);

        // Un salon d'établissement se voit sans le moindre groupe : le claim
        // vide est un cas NORMAL, pas une exception à contourner.
        $token = $this->tokenOf('Conseil de classe', $staff);

        $this->bench->post(
            $this->bench->sessionFor('administratif', 'agent.durand', 'Agent Durand', []),
            '/rooms/join',
            ['token' => $token],
        );

        self::assertSame($this->secretsOf($etabId)['attendee'], $this->lastJoinPassword());
    }

    #[Test]
    public function an_admin_never_gets_the_moderator_password_by_virtue_of_being_an_admin(): void
    {
        // Un claim est une DONNÉE, pas une autorisation : `role === 'admin'`
        // ouvre la configuration des serveurs, jamais la modération d'un salon
        // qu'on n'a pas créé.
        $etabId = $this->bench->store->addRoom('Réunion', 'prof.martin', 'Madame Martin', Room::VISIBILITY_ETAB);
        $this->bench->store->markStarted($etabId, 1);

        $token = $this->tokenOf('Réunion', new Identity('root', 'Admin', 'admin', []));

        $this->bench->post($this->bench->sessionFor('admin', 'root', 'Admin', []), '/rooms/join', ['token' => $token]);

        self::assertSame($this->secretsOf($etabId)['attendee'], $this->lastJoinPassword());
    }

    // =====================================================================
    // `private` : le créateur, et personne d'autre
    // =====================================================================

    #[Test]
    public function a_private_room_belongs_to_its_creator_alone(): void
    {
        // Le `private` du legacy signifiait « tous les personnels, pas les
        // élèves » : encore un accès large implicite. Ici, il veut dire ce
        // qu'il dit.
        $privateId = $this->bench->store->addRoom(
            'Entretien',
            'prof.martin',
            'Madame Martin',
            Room::VISIBILITY_PRIVATE,
        );
        $this->bench->store->markStarted($privateId, 1);

        $token = $this->tokenOf('Entretien', $this->owner());
        $owner = $this->bench->sessionFor('prof', 'prof.martin', 'Madame Martin', ['4B']);

        self::assertStringContainsString('Entretien', $this->bench->get($owner)->body);

        foreach ([
            $this->bench->sessionFor('prof', 'prof.bernard', 'Monsieur Bernard', ['4B']),
            $this->bench->sessionFor('eleve', 'paul.durand', 'Paul Durand', ['4B']),
            $this->bench->sessionFor('admin', 'root', 'Admin', []),
        ] as $intruder) {
            self::assertStringNotContainsString('Entretien', $this->bench->get($intruder)->body);
            self::assertSame(404, $this->bench->post($intruder, '/rooms/join', ['token' => $token])->status);
        }
    }

    // =====================================================================
    // Démarrage et suppression : le créateur, à l'exclusion de tout autre
    // =====================================================================

    #[Test]
    public function only_the_creator_can_start_or_delete_a_room_that_others_can_see(): void
    {
        $colleague = $this->bench->sessionFor('prof', 'prof.bernard', 'Monsieur Bernard', ['4B']);

        foreach (['/rooms/start', '/rooms/delete'] as $path) {
            $response = $this->bench->post($colleague, $path, ['token' => $this->token4B]);

            self::assertSame(404, $response->status, $path);
            self::assertStringContainsString('bbb.rooms.not_found', $response->body, $path);
        }

        self::assertSame(0, $this->bench->api->outboundCalls());
        self::assertNotNull($this->bench->store->roomByToken($this->token4B), 'le salon est intact');
    }

    #[Test]
    public function the_creator_deletes_the_room_and_its_groups_go_with_it(): void
    {
        $session = $this->bench->sessionFor('prof', 'prof.martin', 'Madame Martin', ['4B']);

        self::assertSame(1, $this->bench->store->roomGroupCount());

        $response = $this->bench->post($session, '/rooms/delete', ['token' => $this->token4B]);

        self::assertSame(302, $response->status);
        self::assertNull($this->bench->store->roomByToken($this->token4B));
        self::assertSame(0, $this->bench->store->roomGroupCount(), 'ON DELETE CASCADE');
    }

    // =====================================================================
    // Salon fermé, serveur disparu
    // =====================================================================

    #[Test]
    public function a_room_that_was_never_started_is_simply_closed_and_costs_no_call(): void
    {
        $this->bench->store->addRoom('Atelier', 'prof.martin', 'Madame Martin', Room::VISIBILITY_ETAB);

        $session = $this->bench->sessionFor('eleve', 'paul.durand', 'Paul Durand', []);
        $token = $this->tokenOf('Atelier', new Identity('paul.durand', 'Paul Durand', 'eleve', []));

        $response = $this->bench->post($session, '/rooms/join', ['token' => $token]);

        self::assertSame(200, $response->status);
        self::assertStringContainsString('n\'est pas ouvert', $response->body);
        self::assertSame(0, $this->bench->api->outboundCalls());
    }

    #[Test]
    public function a_meeting_that_ended_on_the_bbb_side_is_reported_as_closed_not_as_a_failure(): void
    {
        // Fin de durée, dernier participant parti, serveur BBB redémarré : le
        // salon (durable) survit au meeting (éphémère). Aucun état local à
        // réconcilier — c'est l'appel qui tranche, au moment où l'on demande.
        $this->bench->api->runningResult = RunningResult::notRunning();

        $session = $this->bench->sessionFor('eleve', 'paul.durand', 'Paul Durand', ['4B']);
        $response = $this->bench->post($session, '/rooms/join', ['token' => $this->token4B]);

        self::assertSame(200, $response->status);
        self::assertStringContainsString('n\'est pas ouvert', $response->body);
        self::assertSame([], $this->bench->api->joins, 'aucune URL de jonction fabriquée');
    }

    #[Test]
    public function an_unreachable_server_is_never_presented_as_a_closed_room(): void
    {
        // Dire « attendez votre professeur » alors que le serveur est éteint
        // enverrait toute une classe attendre pour rien.
        $this->bench->api->runningResult = RunningResult::unreachable();

        $session = $this->bench->sessionFor('eleve', 'paul.durand', 'Paul Durand', ['4B']);
        $this->bench->post($session, '/rooms/join', ['token' => $this->token4B]);

        self::assertStringContainsString('injoignable', $this->bench->get($session)->body);
    }

    #[Test]
    public function a_room_whose_server_was_disabled_is_closed_rather_than_broken(): void
    {
        $this->bench->store->setServerEnabled(1, false);

        $session = $this->bench->sessionFor('eleve', 'paul.durand', 'Paul Durand', ['4B']);
        $response = $this->bench->post($session, '/rooms/join', ['token' => $this->token4B]);

        self::assertSame(200, $response->status);
        self::assertStringContainsString('n\'est pas ouvert', $response->body);
        self::assertSame(0, $this->bench->api->outboundCalls());
    }

    #[Test]
    public function the_creator_restarting_after_a_server_change_gets_the_new_active_server(): void
    {
        // Le serveur d'origine a disparu de la configuration : le prochain
        // démarrage re-choisit le premier serveur actif, plutôt que d'échouer
        // sur une ligne morte.
        $this->bench->store->deleteServer(1);
        $newId = $this->bench->store->addServer('https://bbb2.example.test/bigbluebutton/api', 'secret-2');

        $session = $this->bench->sessionFor('prof', 'prof.martin', 'Madame Martin', ['4B']);
        $response = $this->bench->post($session, '/rooms/start', ['token' => $this->token4B]);

        self::assertSame(302, $response->status);
        self::assertSame('https://bbb2.example.test/bigbluebutton/api', $this->bench->api->created[0]['url']);
        self::assertSame($newId, $this->bench->store->roomByToken($this->token4B)?->serverId);
    }

    #[Test]
    public function without_any_active_server_the_creator_is_told_so_plainly(): void
    {
        $this->bench->store->setServerEnabled(1, false);

        $session = $this->bench->sessionFor('prof', 'prof.martin', 'Madame Martin', ['4B']);
        $this->bench->post($session, '/rooms/start', ['token' => $this->token4B]);

        self::assertSame(0, $this->bench->api->outboundCalls());
        self::assertStringContainsString(
            'Aucun serveur de visioconférence configuré',
            $this->bench->get($session)->body,
        );
    }

    // =====================================================================
    // AC3 — pas d'identité, pas de salon
    // =====================================================================

    #[Test]
    public function an_anonymous_visitor_reaches_no_room_route_at_all(): void
    {
        $routes = [
            ['GET', '/rooms'],
            ['POST', '/rooms'],
            ['POST', '/rooms/start'],
            ['POST', '/rooms/join'],
            ['POST', '/rooms/delete'],
        ];

        foreach ($routes as [$method, $path]) {
            $response = $this->bench->controller()->handle(
                new Request($method, $path, [], ['token' => $this->token4B]),
                new ArraySessionStore(),
            );

            self::assertSame(302, $response->status, $method . ' ' . $path);
            self::assertSame('/ext/bbb/login', $response->headers['Location']);
        }

        self::assertSame(0, $this->bench->api->outboundCalls());
    }

    #[Test]
    public function a_role_outside_the_closed_vocabulary_is_not_an_identity_at_all(): void
    {
        // Mécanisme livré par 57.1, et que les routes salons SUBISSENT : un état
        // porteur d'un rôle inconnu ne vaut même pas 403, il vaut « pas
        // connecté ». Aucun repli, dans un sens comme dans l'autre.
        $session = new ArraySessionStore();
        $session->put('identity', ['sub' => 'x', 'name' => 'X', 'role' => 'super-admin', 'groups' => ['4B']]);
        $session->put('identity.authenticated_at', time());

        $response = $this->bench->controller()->handle(
            new Request('POST', '/rooms/join', [], ['token' => $this->token4B]),
            $session,
        );

        self::assertSame(302, $response->status);
        self::assertSame('/ext/bbb/login', $response->headers['Location']);
        self::assertSame(0, $this->bench->api->outboundCalls());
    }

    #[Test]
    public function a_stale_identity_no_longer_opens_a_room(): void
    {
        $session = $this->bench->sessionFor('eleve', 'paul.durand', 'Paul Durand', ['4B']);
        $session->put('identity.authenticated_at', time() - Identity::MAX_AGE - 1);

        $response = $this->bench->controller()->handle(
            new Request('POST', '/rooms/join', [], ['token' => $this->token4B]),
            $session,
        );

        self::assertSame(302, $response->status);
        self::assertSame('/ext/bbb/login', $response->headers['Location']);
    }
}

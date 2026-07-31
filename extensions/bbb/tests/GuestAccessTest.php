<?php

declare(strict_types=1);

namespace SambaEdu\ExtBbb\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use SambaEdu\ExtBbb\Bbb\RunningResult;
use SambaEdu\ExtBbb\Guest\GuestController;
use SambaEdu\ExtBbb\Http\Response;
use SambaEdu\ExtBbb\Http\SessionStore;
use SambaEdu\ExtBbb\Identity;
use SambaEdu\ExtBbb\Rooms\Room;
use SambaEdu\ExtBbb\Tests\Support\RoomsWorkbench;

/**
 * Story 57.3 — **L'AC1, ET LA SEULE SURFACE OÙ UN ANONYME PEUT DEVINER.**
 *
 * ══════════════════════════════════════════════════════════════════════════
 *  CE QUE CE FICHIER VÉRIFIE, ET QU'UNE RELECTURE NE PEUT PAS DONNER
 *
 *  Le parcours invité tient sur des invariants NÉGATIFS : ce qui ne doit PAS
 *  apparaître, PAS être appelé, PAS être distinguable. Ce sont exactement les
 *  propriétés qu'un développeur pressé casse sans s'en apercevoir — un message
 *  d'erreur un peu plus serviable, et l'oracle est rouvert.
 *
 *  Le contre-modèle est nommé (carte du legacy §4) : `CONF_HASH` = le login du
 *  créateur en clair dans l'URL publique, comparaison du secret en clair, zéro
 *  limite de tentatives, aucune révocation, et une page d'attente en
 *  `Refresh: 15` qui faisait de chaque invité un générateur d'appels sortants.
 * ══════════════════════════════════════════════════════════════════════════
 */
final class GuestAccessTest extends TestCase
{
    private RoomsWorkbench $bench;

    private int $roomId = 0;

    private int $serverId = 0;

    private string $guestToken = '';

    private string $guestPassword = '';

    /** @var array{attendee: string, moderator: string} */
    private array $secrets = ['attendee' => '', 'moderator' => ''];

    protected function setUp(): void
    {
        $this->bench = new RoomsWorkbench();

        $this->serverId = $this->bench->store->addServer(
            'https://bbb.example.test/bigbluebutton/api',
            'secret-serveur',
        );

        $this->roomId = $this->bench->store->addRoom(
            'Conseil de classe',
            'prof.martin',
            'Madame Martin',
            Room::VISIBILITY_CLASSE,
            ['4B'],
        );

        $this->bench->store->markStarted($this->roomId, $this->serverId);

        $invitation = $this->bench->store->enableGuestAccess($this->roomId);
        $this->guestToken = $invitation['token'];
        $this->guestPassword = $invitation['password'];

        $secrets = $this->bench->store->roomSecrets($this->roomId);
        self::assertNotNull($secrets);
        $this->secrets = $secrets;
    }

    protected function tearDown(): void
    {
        $this->bench->destroy();
    }

    // =====================================================================
    // Le parcours nominal
    // =====================================================================

    #[Test]
    public function a_guest_with_the_right_password_joins_as_an_attendee_and_never_as_a_moderator(): void
    {
        $response = $this->bench->postGuest([
            'g' => $this->guestToken,
            'name' => 'Monsieur Durand',
            'password' => $this->guestPassword,
        ]);

        self::assertSame(302, $response->status);
        self::assertSame('', $response->body, 'une redirection ne rend AUCUNE page');

        $location = $response->headers['Location'];
        self::assertStringContainsString(rawurlencode($this->secrets['attendee']), $location);
        self::assertStringNotContainsString(
            rawurlencode($this->secrets['moderator']),
            $location,
            'IL N\'EXISTE AUCUN CHEMIN par lequel la route publique atteint le mot de passe modérateur',
        );

        // Et le mot de passe choisi vient bien de la table, pas d'un hasard :
        // la doublure a mémorisé lequel des deux le serveur lui a passé.
        self::assertSame($this->secrets['attendee'], $this->bench->api->joins[0]['password']);
    }

    #[Test]
    public function the_guest_is_announced_as_a_guest_whatever_name_he_chooses(): void
    {
        // Un externe choisit son nom — il ne choisit pas de passer pour un
        // membre de l'établissement.
        $this->bench->postGuest([
            'g' => $this->guestToken,
            'name' => 'Madame Martin',
            'password' => $this->guestPassword,
        ]);

        self::assertSame('Madame Martin (invité)', $this->bench->api->joins[0]['fullName']);
        self::assertSame(GuestController::GUEST_SUFFIX, ' (invité)');
    }

    #[Test]
    public function the_only_outgoing_call_of_the_whole_guest_path_is_the_running_probe(): void
    {
        $this->bench->postGuest([
            'g' => $this->guestToken,
            'name' => 'Monsieur Durand',
            'password' => $this->guestPassword,
        ]);

        self::assertCount(1, $this->bench->api->probed);
        self::assertSame(1, $this->bench->api->outboundCalls());
        self::assertCount(1, $this->bench->api->joins, 'la fabrique d\'URL est LOCALE : elle ne compte pas');
    }

    // =====================================================================
    // LE cœur de la story : quatre causes, UNE réponse
    // =====================================================================

    #[Test]
    public function the_four_refusals_are_indistinguishable_byte_for_byte(): void
    {
        // 1. Jeton inconnu — un lien inventé de toutes pièces.
        $unknown = $this->bench->postGuest([
            'g' => 'jeton-qui-n-a-jamais-existe',
            'name' => 'Intrus',
            'password' => 'peu importe',
        ]);

        // 2. Mot de passe faux sur un jeton VALIDE.
        $wrongPassword = $this->bench->postGuest([
            'g' => $this->guestToken,
            'name' => 'Intrus',
            'password' => 'ce-n-est-pas-le-bon',
        ]);

        // 3. Fenêtre saturée, AVEC le bon mot de passe.
        for ($i = 0; $i < GuestController::MAX_FAILURES; $i++) {
            $this->bench->postGuest([
                'g' => $this->guestToken,
                'name' => 'Intrus',
                'password' => 'encore-faux',
            ]);
        }

        $saturated = $this->bench->postGuest([
            'g' => $this->guestToken,
            'name' => 'Intrus',
            'password' => $this->guestPassword,
        ]);

        // 4. Invitation révoquée — le lien qui marchait il y a une seconde.
        $this->bench->store->revokeGuestAccess($this->roomId);

        $revoked = $this->bench->postGuest([
            'g' => $this->guestToken,
            'name' => 'Intrus',
            'password' => $this->guestPassword,
        ]);

        foreach ([$unknown, $wrongPassword, $saturated, $revoked] as $response) {
            self::assertSame(403, $response->status);
            self::assertSame($unknown->body, $response->body, 'les quatre refus doivent être le MÊME corps');
        }

        // Et surtout : aucun de ces quatorze refus n'a coûté un appel sortant.
        // Un refus qui appellerait le serveur se trahirait par sa durée, et
        // offrirait au passage un moyen commode de le faire travailler.
        self::assertSame(0, $this->bench->api->outboundCalls());
    }

    #[Test]
    public function a_saturated_window_refuses_even_the_right_password(): void
    {
        // Contrôle POSITIF adossé au précédent : sans lui, le test ci-dessus
        // passerait tout aussi bien si le bon mot de passe ne marchait JAMAIS.
        $ok = $this->bench->postGuest([
            'g' => $this->guestToken,
            'name' => 'Monsieur Durand',
            'password' => $this->guestPassword,
        ]);

        self::assertSame(302, $ok->status, 'le bon mot de passe passe, tant que la fenêtre n\'est pas saturée');

        for ($i = 0; $i < GuestController::MAX_FAILURES; $i++) {
            $this->bench->postGuest([
                'g' => $this->guestToken,
                'name' => 'Intrus',
                'password' => 'faux',
            ]);
        }

        $refused = $this->bench->postGuest([
            'g' => $this->guestToken,
            'name' => 'Monsieur Durand',
            'password' => $this->guestPassword,
        ]);

        self::assertSame(403, $refused->status, 'fenêtre saturée : même le bon mot de passe est refusé');
    }

    #[Test]
    public function a_success_wipes_the_failure_counter(): void
    {
        // La fenêtre punit des échecs CONSÉCUTIFS : elle ne doit pas accumuler
        // les fautes de frappe d'un parent sur trois semaines.
        for ($i = 0; $i < GuestController::MAX_FAILURES - 1; $i++) {
            $this->bench->postGuest([
                'g' => $this->guestToken,
                'name' => 'Parent',
                'password' => 'faux',
            ]);
        }

        self::assertSame(
            GuestController::MAX_FAILURES - 1,
            $this->bench->store->guestFailuresInWindow($this->roomId, time(), GuestController::WINDOW_SECONDS),
        );

        $this->bench->postGuest([
            'g' => $this->guestToken,
            'name' => 'Parent',
            'password' => $this->guestPassword,
        ]);

        self::assertSame(
            0,
            $this->bench->store->guestFailuresInWindow($this->roomId, time(), GuestController::WINDOW_SECONDS),
        );
    }

    #[Test]
    public function the_window_reopens_once_it_has_expired(): void
    {
        $now = 1_800_000_000;

        for ($i = 0; $i < GuestController::MAX_FAILURES; $i++) {
            $this->bench->postGuest([
                'g' => $this->guestToken,
                'name' => 'Parent',
                'password' => 'faux',
            ], $now);
        }

        self::assertSame(403, $this->bench->postGuest([
            'g' => $this->guestToken,
            'name' => 'Parent',
            'password' => $this->guestPassword,
        ], $now)->status);

        // Une seconde après la fin de la fenêtre, l'ardoise est repartie de zéro.
        self::assertSame(302, $this->bench->postGuest([
            'g' => $this->guestToken,
            'name' => 'Parent',
            'password' => $this->guestPassword,
        ], $now + GuestController::WINDOW_SECONDS + 1)->status);
    }

    // =====================================================================
    // Révocation et régénération — ce que SE4 ne savait pas faire
    // =====================================================================

    #[Test]
    public function regenerating_kills_the_previous_link_immediately(): void
    {
        // AVANT : le lien marche. Sans cette assertion, la suivante ne
        // prouverait rien du tout.
        self::assertSame(302, $this->bench->postGuest([
            'g' => $this->guestToken,
            'name' => 'Parent',
            'password' => $this->guestPassword,
        ])->status);

        $renewed = $this->bench->store->enableGuestAccess($this->roomId);

        self::assertNotSame($this->guestToken, $renewed['token']);
        self::assertNotSame($this->guestPassword, $renewed['password']);

        // APRÈS : l'ancien couple ne résout plus rien, et rend le refus
        // indistinct — pas un « lien expiré » qui confirmerait qu'il a existé.
        $dead = $this->bench->postGuest([
            'g' => $this->guestToken,
            'name' => 'Parent',
            'password' => $this->guestPassword,
        ]);

        self::assertSame(403, $dead->status);

        // Et le nouveau, lui, passe.
        self::assertSame(302, $this->bench->postGuest([
            'g' => $renewed['token'],
            'name' => 'Parent',
            'password' => $renewed['password'],
        ])->status);
    }

    #[Test]
    public function revoking_kills_the_link_immediately(): void
    {
        $this->bench->store->revokeGuestAccess($this->roomId);

        self::assertNull($this->bench->store->guestInvitation($this->roomId));

        $response = $this->bench->postGuest([
            'g' => $this->guestToken,
            'name' => 'Parent',
            'password' => $this->guestPassword,
        ]);

        self::assertSame(403, $response->status);
        self::assertSame(0, $this->bench->api->outboundCalls());
    }

    // =====================================================================
    // « Pas encore ouvert » — seulement APRÈS le bon mot de passe
    // =====================================================================

    #[Test]
    public function a_meeting_that_is_not_running_shows_the_not_open_page(): void
    {
        $this->bench->api->runningResult = RunningResult::notRunning();

        $response = $this->bench->postGuest([
            'g' => $this->guestToken,
            'name' => 'Parent',
            'password' => $this->guestPassword,
        ]);

        self::assertSame(200, $response->status);
        self::assertStringContainsString('n\'est pas encore ouverte', $response->body);
        self::assertStringNotContainsString('Refresh', $response->body, 'aucun auto-rafraîchissement, jamais');
    }

    #[Test]
    public function a_room_never_started_is_not_open_and_costs_no_call_at_all(): void
    {
        $fresh = $this->bench->store->addRoom('Salon neuf', 'prof.martin', 'Madame Martin', Room::VISIBILITY_PRIVATE);
        $invitation = $this->bench->store->enableGuestAccess($fresh);

        $response = $this->bench->postGuest([
            'g' => $invitation['token'],
            'name' => 'Parent',
            'password' => $invitation['password'],
        ]);

        self::assertSame(200, $response->status);
        self::assertStringContainsString('n\'est pas encore ouverte', $response->body);
        self::assertSame(0, $this->bench->api->outboundCalls(), 'aucun serveur à interroger');
    }

    #[Test]
    public function a_disabled_server_is_not_open_either_and_is_never_called(): void
    {
        $this->bench->store->setServerEnabled($this->serverId, false);

        $response = $this->bench->postGuest([
            'g' => $this->guestToken,
            'name' => 'Parent',
            'password' => $this->guestPassword,
        ]);

        self::assertSame(200, $response->status);
        self::assertSame(0, $this->bench->api->outboundCalls());
    }

    // =====================================================================
    // Le GET ne dit rien, et la validation locale non plus
    // =====================================================================

    #[Test]
    public function the_form_is_the_same_for_a_valid_token_and_for_an_invented_one(): void
    {
        $valid = $this->bench->getGuest($this->guestToken);
        $unknown = $this->bench->getGuest('jeton-invente-de-toutes-pieces');

        self::assertSame(200, $valid->status);
        self::assertSame(200, $unknown->status);

        // Les deux pages ne diffèrent QUE par la valeur que le visiteur a
        // lui-même fournie — c'est-à-dire par rien qu'il ne sache déjà.
        self::assertSame(
            str_replace($this->guestToken, 'JETON', $valid->body),
            str_replace('jeton-invente-de-toutes-pieces', 'JETON', $unknown->body),
        );

        self::assertSame(0, $this->bench->api->outboundCalls());
    }

    #[Test]
    public function a_missing_or_oversized_name_is_refused_before_anything_else(): void
    {
        $empty = $this->bench->postGuest([
            'g' => $this->guestToken,
            'name' => '   ',
            'password' => $this->guestPassword,
        ]);

        $tooLong = $this->bench->postGuest([
            'g' => $this->guestToken,
            'name' => str_repeat('a', GuestController::MAX_NAME_LENGTH + 1),
            'password' => $this->guestPassword,
        ]);

        self::assertSame(422, $empty->status);
        self::assertSame(422, $tooLong->status);
        self::assertSame(0, $this->bench->api->outboundCalls());

        // La validation du nom ne dépend PAS du jeton : elle dit la même chose
        // sur un lien inventé, donc elle n'apprend rien à personne.
        self::assertSame(422, $this->bench->postGuest([
            'g' => 'jeton-invente',
            'name' => '',
            'password' => 'x',
        ])->status);

        // Et le compteur d'échecs du salon n'a pas bougé : une faute de frappe
        // dans son propre nom ne consomme pas de tentative.
        self::assertSame(
            0,
            $this->bench->store->guestFailuresInWindow($this->roomId, time(), GuestController::WINDOW_SECONDS),
        );
    }

    // =====================================================================
    // Zéro état par visiteur — prouvé deux fois
    // =====================================================================

    #[Test]
    public function the_guest_controller_cannot_receive_a_state_store_at_all(): void
    {
        // La preuve par le TYPAGE : ce n'est pas qu'on oublie de lui en passer
        // un, c'est qu'il n'en prend pas. Un futur contributeur qui voudrait
        // « juste mémoriser le nom entre deux essais » devra changer cette
        // signature — et ce test tombera devant lui.
        $constructor = (new ReflectionClass(GuestController::class))->getConstructor();
        self::assertNotNull($constructor);

        foreach ($constructor->getParameters() as $parameter) {
            self::assertNotSame(
                SessionStore::class,
                (string) $parameter->getType(),
                'le parcours invité n\'ouvre AUCUN état par visiteur',
            );
        }
    }

    #[Test]
    public function no_cookie_is_ever_set_on_the_guest_path(): void
    {
        $responses = [
            'formulaire' => $this->bench->getGuest($this->guestToken),
            'refus' => $this->bench->postGuest([
                'g' => $this->guestToken,
                'name' => 'Parent',
                'password' => 'faux',
            ]),
            'jonction' => $this->bench->postGuest([
                'g' => $this->guestToken,
                'name' => 'Parent',
                'password' => $this->guestPassword,
            ]),
        ];

        foreach ($responses as $where => $response) {
            self::assertInstanceOf(Response::class, $response);

            foreach (array_keys($response->headers) as $name) {
                self::assertNotSame('set-cookie', strtolower((string) $name), $where);
            }
        }
    }

    #[Test]
    public function the_public_pages_never_carry_a_bigbluebutton_password(): void
    {
        $pages = [
            'formulaire' => $this->bench->getGuest($this->guestToken)->body,
            'refus' => $this->bench->postGuest([
                'g' => $this->guestToken,
                'name' => 'Parent',
                'password' => 'faux',
            ])->body,
        ];

        $this->bench->api->runningResult = RunningResult::notRunning();
        $pages['pas ouvert'] = $this->bench->postGuest([
            'g' => $this->guestToken,
            'name' => 'Parent',
            'password' => $this->guestPassword,
        ])->body;

        foreach ($pages as $where => $body) {
            self::assertNotSame('', $body);
            self::assertStringNotContainsString($this->secrets['attendee'], $body, $where);
            self::assertStringNotContainsString($this->secrets['moderator'], $body, $where);
            self::assertStringNotContainsString('secret-serveur', $body, $where);
        }

        // Le mot de passe D'INVITATION non plus : la page publique le
        // DEMANDE, elle ne le donne pas.
        foreach ($pages as $where => $body) {
            self::assertStringNotContainsString($this->guestPassword, $body, $where);
        }
    }

    #[Test]
    public function the_guest_token_never_appears_on_the_page_of_someone_else(): void
    {
        // Le jeton d'invitation EST l'URL publique du salon : il n'a rien à
        // faire dans la liste servie aux élèves. C'est structurel — l'objet
        // `Room` ne le porte pas — et c'est vérifié ici sur la page réelle.
        $pupil = $this->bench->sessionFor('eleve', 'paul.durand', 'Paul Durand', ['4B']);
        $body = $this->bench->get($pupil)->body;

        self::assertStringNotContainsString($this->guestToken, $body);
        self::assertStringNotContainsString($this->guestPassword, $body);

        // Contrôle positif : le créateur, lui, les voit — c'est leur raison
        // d'être.
        $teacher = $this->bench->sessionFor('prof', 'prof.martin', 'Madame Martin', ['4B']);
        $own = $this->bench->get($teacher)->body;

        self::assertStringContainsString($this->guestToken, $own);
        self::assertStringContainsString($this->guestPassword, $own);
    }

    #[Test]
    public function a_room_object_never_carries_the_invitation_secrets(): void
    {
        $room = $this->bench->store->roomByGuestToken($this->guestToken);

        self::assertNotNull($room);
        self::assertTrue($room->hasGuestAccess, 'un booléen d\'affichage, et rien de plus');

        $exported = print_r($room, true);

        self::assertStringNotContainsString($this->guestToken, $exported);
        self::assertStringNotContainsString($this->guestPassword, $exported);
    }

    #[Test]
    public function the_public_link_is_absolute_and_derived_from_the_issuer(): void
    {
        // Il doit fonctionner depuis l'EXTÉRIEUR de l'établissement, donc être
        // absolu — et il ne dérive jamais d'un en-tête `Host:` que le contrat
        // ne garantit pas (leçon de la revue de 57.1).
        $teacher = $this->bench->sessionFor('prof', 'prof.martin', 'Madame Martin', ['4B']);
        $body = $this->bench->get($teacher)->body;

        self::assertStringContainsString(
            'https://se5.example.test/ext/bbb/visio?g=' . $this->guestToken,
            $body,
        );
    }

    #[Test]
    public function only_the_owner_can_enable_or_revoke_an_invitation(): void
    {
        $token = $this->bench->store->roomsVisibleTo(
            new Identity('prof.martin', 'Madame Martin', 'prof', ['4B'])
        )[0]->token;

        $other = $this->bench->sessionFor('prof', 'prof.dupont', 'Monsieur Dupont', ['4B']);

        foreach (['/rooms/guest/enable', '/rooms/guest/revoke'] as $path) {
            $response = $this->bench->post($other, $path, ['token' => $token]);

            self::assertSame(404, $response->status, $path . ' — le refus indistinct de 57.2');
        }

        // Et l'invitation en place n'a pas bougé d'un caractère.
        $invitation = $this->bench->store->guestInvitation($this->roomId);
        self::assertNotNull($invitation);
        self::assertSame($this->guestToken, $invitation['token']);

        self::assertSame(0, $this->bench->api->outboundCalls(), 'un acte local ne parle à personne');
    }

    #[Test]
    public function enabling_and_revoking_never_call_a_bbb_server(): void
    {
        $teacher = $this->bench->sessionFor('prof', 'prof.martin', 'Madame Martin', ['4B']);
        $token = $this->bench->store->roomsVisibleTo(
            new Identity('prof.martin', 'Madame Martin', 'prof', ['4B'])
        )[0]->token;

        $this->bench->post($teacher, '/rooms/guest/enable', ['token' => $token]);
        $this->bench->post($teacher, '/rooms/guest/revoke', ['token' => $token]);

        self::assertSame(0, $this->bench->api->outboundCalls());
        self::assertNull($this->bench->store->guestInvitation($this->roomId));
    }
}

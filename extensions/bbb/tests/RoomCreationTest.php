<?php

declare(strict_types=1);

namespace SambaEdu\ExtBbb\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SambaEdu\ExtBbb\Identity;
use SambaEdu\ExtBbb\Rooms\Room;
use SambaEdu\ExtBbb\Rooms\RoomsController;
use SambaEdu\ExtBbb\Tests\Support\RoomsWorkbench;

/**
 * Story 57.2 — **LA CRÉATION D'UN SALON (AC1), ET SA GARDE SERVEUR.**
 *
 * Le formulaire ne propose que les classes et équipes du professeur. Ce n'est
 * pas ce qui protège quoi que ce soit : un formulaire se contourne en trois
 * secondes. **Ce qui protège, c'est la comparaison faite au serveur, à la
 * réception, contre les groupes de l'identité courante** — et elle REFUSE au
 * lieu de filtrer, parce qu'une valeur qu'un utilisateur légitime ne peut pas
 * produire n'est pas une faute de frappe à corriger.
 */
final class RoomCreationTest extends TestCase
{
    private RoomsWorkbench $bench;

    protected function setUp(): void
    {
        $this->bench = new RoomsWorkbench();
    }

    protected function tearDown(): void
    {
        $this->bench->destroy();
    }

    private function teacher(): \SambaEdu\ExtBbb\Http\ArraySessionStore
    {
        return $this->bench->sessionFor('prof', 'prof.martin', 'Madame Martin', ['4B', '5A', 'Équipe SVT']);
    }

    /** @return list<Room> */
    private function roomsOfTheTeacher(): array
    {
        return $this->bench->store->roomsVisibleTo(
            new Identity('prof.martin', 'Madame Martin', 'prof', ['4B', '5A', 'Équipe SVT'])
        );
    }

    // =====================================================================
    // Les trois visibilités
    // =====================================================================

    #[Test]
    public function a_teacher_creates_a_room_for_the_whole_school(): void
    {
        $response = $this->bench->post($this->teacher(), '/rooms', [
            'name' => 'Réunion parents',
            'visibility' => Room::VISIBILITY_ETAB,
        ]);

        self::assertSame(302, $response->status);
        self::assertSame('/ext/bbb/rooms', $response->headers['Location']);

        $rooms = $this->roomsOfTheTeacher();
        self::assertCount(1, $rooms);
        self::assertSame('Réunion parents', $rooms[0]->name);
        self::assertSame(Room::VISIBILITY_ETAB, $rooms[0]->visibility);
        self::assertSame([], $rooms[0]->groups);
        self::assertSame('prof.martin', $rooms[0]->ownerSub);
        self::assertNull($rooms[0]->serverId, 'créer n\'est PAS démarrer');
    }

    #[Test]
    public function a_teacher_creates_a_room_for_several_of_his_own_groups(): void
    {
        // Le claim ne distingue pas classes et équipes, et c'est voulu : un
        // salon d'« Équipe SVT » est aussi légitime qu'un salon de 4ᵉB, ses
        // co-membres le voient de la même façon.
        $this->bench->post($this->teacher(), '/rooms', [
            'name' => 'Préparation du conseil',
            'visibility' => Room::VISIBILITY_CLASSE,
            'groups' => ['4B', 'Équipe SVT'],
        ]);

        $rooms = $this->roomsOfTheTeacher();
        self::assertCount(1, $rooms);
        self::assertSame(['4B', 'Équipe SVT'], $rooms[0]->groups);
        self::assertSame(2, $this->bench->store->roomGroupCount());
    }

    #[Test]
    public function a_teacher_creates_a_private_room(): void
    {
        $this->bench->post($this->teacher(), '/rooms', [
            'name' => 'Entretien',
            'visibility' => Room::VISIBILITY_PRIVATE,
        ]);

        self::assertSame(Room::VISIBILITY_PRIVATE, $this->roomsOfTheTeacher()[0]->visibility);
    }

    #[Test]
    public function a_teacher_without_any_group_can_still_create_school_wide_and_private_rooms(): void
    {
        $session = $this->bench->sessionFor('prof', 'prof.sans.classe', 'Professeur Sans Classe', []);

        // L'option « classes » n'est pas affichée…
        self::assertStringNotContainsString('name="groups[]"', $this->bench->get($session)->body);

        // … et elle reste refusée quand on la soumet quand même.
        foreach ([Room::VISIBILITY_ETAB, Room::VISIBILITY_PRIVATE] as $visibility) {
            $response = $this->bench->post($session, '/rooms', [
                'name' => 'Salon ' . $visibility,
                'visibility' => $visibility,
            ]);

            self::assertSame(302, $response->status, $visibility);
        }

        $refused = $this->bench->post($session, '/rooms', [
            'name' => 'Salon volé',
            'visibility' => Room::VISIBILITY_CLASSE,
            'groups' => ['4B'],
        ]);

        self::assertSame(422, $refused->status);
    }

    // =====================================================================
    // LA garde : les groupes soumis ⊆ les groupes du claim
    // =====================================================================

    #[Test]
    public function a_group_that_is_not_in_the_claim_is_refused_never_silently_dropped(): void
    {
        // Le formulaire est contourné : la requête porte une classe qui n'est
        // pas celle de ce professeur. On ne « corrige » pas, on refuse — et
        // RIEN n'est écrit, pas même la partie légitime de la demande.
        $response = $this->bench->post($this->teacher(), '/rooms', [
            'name' => 'Salon de la classe des autres',
            'visibility' => Room::VISIBILITY_CLASSE,
            'groups' => ['4B', '6C'],
        ]);

        self::assertSame(422, $response->status);
        self::assertStringContainsString('vos propres classes', $response->body);
        self::assertSame([], $this->roomsOfTheTeacher());
        self::assertSame(0, $this->bench->store->roomGroupCount());
    }

    #[Test]
    public function a_group_name_is_compared_verbatim_and_strictly(): void
    {
        // Ni casse approchée, ni espaces en trop, ni comparaison lâche : les
        // noms de groupes viennent des claims et s'y comparent tels quels.
        foreach (['4b', ' 4B', '4B ', 'Equipe SVT'] as $approximation) {
            $response = $this->bench->post($this->teacher(), '/rooms', [
                'name' => 'Essai',
                'visibility' => Room::VISIBILITY_CLASSE,
                'groups' => [$approximation],
            ]);

            self::assertSame(422, $response->status, $approximation);
        }

        self::assertSame([], $this->roomsOfTheTeacher());
    }

    #[Test]
    public function a_class_room_without_any_group_is_a_validation_error(): void
    {
        $response = $this->bench->post($this->teacher(), '/rooms', [
            'name' => 'Salon sans classe',
            'visibility' => Room::VISIBILITY_CLASSE,
        ]);

        self::assertSame(422, $response->status);
        self::assertStringContainsString('au moins une classe', $response->body);
        self::assertSame([], $this->roomsOfTheTeacher());
    }

    #[Test]
    public function a_visibility_outside_the_closed_vocabulary_is_refused(): void
    {
        // `world` était la quatrième visibilité du legacy (« tous les
        // établissements ») : non portée, et refusée comme n'importe quelle
        // autre valeur inventée.
        foreach (['world', '', 'public', 'ETAB'] as $visibility) {
            $response = $this->bench->post($this->teacher(), '/rooms', [
                'name' => 'Salon',
                'visibility' => $visibility,
            ]);

            self::assertSame(422, $response->status, $visibility);
        }

        self::assertSame([], $this->roomsOfTheTeacher());
    }

    #[Test]
    public function a_name_is_required_and_bounded(): void
    {
        foreach (['', '   ', str_repeat('a', RoomsController::MAX_NAME_LENGTH + 1)] as $name) {
            $response = $this->bench->post($this->teacher(), '/rooms', [
                'name' => $name,
                'visibility' => Room::VISIBILITY_ETAB,
            ]);

            self::assertSame(422, $response->status);
        }

        self::assertSame([], $this->roomsOfTheTeacher());
    }

    #[Test]
    public function a_refused_form_keeps_what_was_typed(): void
    {
        $response = $this->bench->post($this->teacher(), '/rooms', [
            'name' => 'Salon à conserver',
            'visibility' => Room::VISIBILITY_CLASSE,
        ]);

        self::assertStringContainsString('Salon à conserver', $response->body);
    }

    // =====================================================================
    // Qui a le droit de créer
    // =====================================================================

    #[Test]
    public function only_a_teacher_may_create_a_room(): void
    {
        foreach (['eleve', 'administratif', 'admin'] as $role) {
            $session = $this->bench->sessionFor($role, 'compte.' . $role, 'Compte ' . $role, ['4B']);

            $response = $this->bench->post($session, '/rooms', [
                'name' => 'Salon interdit',
                'visibility' => Room::VISIBILITY_ETAB,
            ]);

            self::assertSame(403, $response->status, $role);
            self::assertStringNotContainsString('Créer le salon', $this->bench->get($session)->body, $role);
        }

        self::assertSame([], $this->roomsOfTheTeacher());
    }

    #[Test]
    public function a_teacher_does_see_the_creation_form(): void
    {
        // CONTRÔLE POSITIF : sans lui, les refus ci-dessus prouveraient
        // seulement que la page est cassée pour tout le monde.
        self::assertStringContainsString('Créer le salon', $this->bench->get($this->teacher())->body);
    }

    #[Test]
    public function a_post_without_a_valid_csrf_token_is_refused(): void
    {
        $session = $this->teacher();
        $this->bench->csrf($session);

        $response = $this->bench->post($session, '/rooms', [
            '_token' => 'jeton-fabrique-ailleurs',
            'name' => 'Salon',
            'visibility' => Room::VISIBILITY_ETAB,
        ]);

        self::assertSame(403, $response->status);
        self::assertSame([], $this->roomsOfTheTeacher());
    }

    // =====================================================================
    // Ce que la création fabrique
    // =====================================================================

    #[Test]
    public function the_two_passwords_are_cryptographic_distinct_and_never_reused(): void
    {
        // Le legacy tirait `rand(100,999)` et `rand(1000,9999)` : neuf cents et
        // neuf mille valeurs, d'un générateur non cryptographique. Ici, 128 bits
        // chacun, tirés de `random_bytes`, et jamais deux fois les mêmes.
        $seen = [];

        for ($i = 0; $i < 5; $i++) {
            $this->bench->post($this->teacher(), '/rooms', [
                'name' => 'Salon ' . $i,
                'visibility' => Room::VISIBILITY_ETAB,
            ]);
        }

        foreach ($this->roomsOfTheTeacher() as $room) {
            $secrets = $this->bench->store->roomSecrets($room->id);
            self::assertNotNull($secrets);

            self::assertSame(32, strlen($secrets['attendee']));
            self::assertSame(32, strlen($secrets['moderator']));
            self::assertNotSame(
                $secrets['attendee'],
                $secrets['moderator'],
                'les deux mots de passe PORTENT le rôle : les confondre ferait modérateur quiconque rejoint',
            );

            $seen[] = $secrets['attendee'];
            $seen[] = $secrets['moderator'];
            $seen[] = $room->token;
        }

        self::assertSame($seen, array_values(array_unique($seen)), 'aucune valeur tirée deux fois');
    }

    #[Test]
    public function the_public_token_carries_no_meaning_at_all(): void
    {
        // Le `meetingID` du legacy encodait la visibilité, un hash
        // d'établissement, le hash du login du créateur et un hash par classe —
        // ce qui obligeait à tout décoder ensuite, et cassait le filtre des
        // enregistrements dès qu'un salon portait des classes. Ici : 32
        // caractères hexadécimaux, et rien d'autre.
        $this->bench->post($this->teacher(), '/rooms', [
            'name' => 'Cours de mathématiques',
            'visibility' => Room::VISIBILITY_CLASSE,
            'groups' => ['4B'],
        ]);

        $token = $this->roomsOfTheTeacher()[0]->token;

        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $token);
        self::assertStringNotContainsString('prof.martin', $token);
        self::assertStringNotContainsString(md5('prof.martin'), $token);
        self::assertStringNotContainsString(md5('4B'), $token);
        self::assertStringNotContainsString(Room::VISIBILITY_CLASSE, $token);
    }
}

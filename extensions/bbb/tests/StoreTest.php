<?php

declare(strict_types=1);

namespace SambaEdu\ExtBbb\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SambaEdu\ExtBbb\Identity;
use SambaEdu\ExtBbb\Oidc\IdTokenVerifier;
use SambaEdu\ExtBbb\Oidc\SqliteReplayGuard;
use SambaEdu\ExtBbb\Rooms\Room;
use SambaEdu\ExtBbb\Store;

/**
 * Story 57.1 — L'état de l'extension : migration idempotente, droits du fichier,
 * serveurs, anti-rejeu.
 *
 * Story 57.2 — Et le palier v2 : la migration d'une base VIVANTE, les salons,
 * leur visibilité décidée en SQL, et le fait qu'aucun mot de passe ne puisse
 * atteindre une page.
 */
final class StoreTest extends TestCase
{
    private string $directory = '';

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/ext-bbb-test-' . bin2hex(random_bytes(6));
        mkdir($this->directory, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->directory);
    }

    private function path(): string
    {
        return $this->directory . '/database.sqlite';
    }

    // =====================================================================
    // Migration
    // =====================================================================

    #[Test]
    public function a_fresh_database_is_created_and_migrated(): void
    {
        self::assertFileDoesNotExist($this->path());

        $store = new Store($this->path());

        self::assertFileExists($this->path());
        self::assertSame(Store::SCHEMA_VERSION, $store->schemaVersion());
        self::assertSame([], $store->servers());
    }

    #[Test]
    public function migrating_twice_changes_nothing(): void
    {
        // « Idempotente au démarrage » : le service redémarre à chaque mise à
        // jour du paquet. Une migration qui ne serait pas rejouable casserait au
        // deuxième `systemctl restart`, jamais au premier.
        $first = new Store($this->path());
        $id = $first->addServer('https://bbb.example.test/api', 'secret-partage', 0, true);

        $second = new Store($this->path());
        $second->migrate();

        self::assertSame(Store::SCHEMA_VERSION, $second->schemaVersion());
        self::assertNotNull($second->server($id), 'la migration rejouée ne doit rien détruire');
        self::assertCount(1, $second->servers());
    }

    #[Test]
    public function the_created_database_file_is_only_readable_by_its_owner(): void
    {
        // Le fichier porte les secrets partagés des serveurs BBB : la protection
        // EST le système de fichiers.
        new Store($this->path());

        self::assertSame('0600', substr(sprintf('%o', fileperms($this->path())), -4));
    }

    // =====================================================================
    // Serveurs
    // =====================================================================

    #[Test]
    public function servers_are_created_read_updated_and_deleted(): void
    {
        $store = new Store($this->path());

        $id = $store->addServer('https://bbb1.example.test/api', 'secret-1', 0, true);
        $other = $store->addServer('https://bbb2.example.test/api', 'secret-2', 50, false);

        self::assertCount(2, $store->servers());

        $server = $store->server($id);
        self::assertNotNull($server);
        self::assertSame('https://bbb1.example.test/api', $server['base_url']);
        self::assertSame('secret-1', $server['secret']);
        self::assertSame(0, $server['scalelite_threshold']);
        self::assertTrue($server['enabled']);

        $scalelite = $store->server($other);
        self::assertNotNull($scalelite);
        self::assertSame(50, $scalelite['scalelite_threshold']);
        self::assertFalse($scalelite['enabled']);

        $store->updateServer($id, 'https://bbb1.example.test/bigbluebutton/api', null, 10, false);
        $updated = $store->server($id);
        self::assertNotNull($updated);
        self::assertSame('https://bbb1.example.test/bigbluebutton/api', $updated['base_url']);
        self::assertSame('secret-1', $updated['secret'], 'un secret non fourni est CONSERVÉ');
        self::assertSame(10, $updated['scalelite_threshold']);
        self::assertFalse($updated['enabled']);

        $store->updateServer($id, 'https://bbb1.example.test/api', 'nouveau-secret', 0, true);
        self::assertSame('nouveau-secret', $store->server($id)['secret']);

        $store->setServerEnabled($id, false);
        self::assertFalse($store->server($id)['enabled']);

        $store->deleteServer($id);
        self::assertNull($store->server($id));
        self::assertCount(1, $store->servers());
    }

    #[Test]
    public function deleting_a_middle_server_never_shifts_the_others(): void
    {
        // LE bug du legacy : trois listes CSV parallèles indexées par POSITION.
        // Supprimer le serveur du milieu décalait les index, et le serveur
        // suivant héritait du secret d'un autre. Une ligne SQL par serveur rend
        // ce bug impossible — encore faut-il le vérifier une fois.
        $store = new Store($this->path());

        $a = $store->addServer('https://a.example.test/api', 'secret-a');
        $b = $store->addServer('https://b.example.test/api', 'secret-b');
        $c = $store->addServer('https://c.example.test/api', 'secret-c');

        $store->deleteServer($b);
        $store->addServer('https://d.example.test/api', 'secret-d');

        $pairs = [];
        foreach ($store->servers() as $server) {
            $pairs[$server['base_url']] = $server['secret'];
        }

        self::assertSame([
            'https://a.example.test/api' => 'secret-a',
            'https://c.example.test/api' => 'secret-c',
            'https://d.example.test/api' => 'secret-d',
        ], $pairs);

        self::assertSame('secret-a', $store->server($a)['secret']);
        self::assertSame('secret-c', $store->server($c)['secret']);
    }

    // =====================================================================
    // Anti-rejeu
    // =====================================================================

    #[Test]
    public function a_jti_is_consumed_once_then_refused(): void
    {
        $store = new Store($this->path());
        $expires = time() + 300;

        self::assertTrue($store->consumeJti('jti-isole', $expires), '1er usage');
        self::assertFalse($store->consumeJti('jti-isole', $expires), 'rejeu');
    }

    #[Test]
    public function two_distinct_jti_do_not_interfere(): void
    {
        // Sans ce contrôle, un anti-rejeu qui refuserait TOUT après la première
        // consommation passerait le test précédent.
        $store = new Store($this->path());
        $expires = time() + 300;

        self::assertTrue($store->consumeJti('jti-un', $expires));
        self::assertTrue($store->consumeJti('jti-deux', $expires));
    }

    #[Test]
    public function an_already_expired_jti_is_refused_without_being_stored(): void
    {
        // Fail-closed : mémoriser un jeton déjà mort n'a pas de sens, et
        // l'accepter en rouvrirait le rejeu indéfiniment.
        $store = new Store($this->path());

        self::assertFalse($store->consumeJti('jti-perime', time() - 600));
        self::assertSame(0, $store->replayCount());
    }

    #[Test]
    public function expired_entries_are_purged_along_the_way(): void
    {
        // Une extension n'a pas d'ordonnanceur : la purge se fait au passage,
        // sinon la table grossit indéfiniment.
        $store = new Store($this->path());

        self::assertTrue($store->consumeJti('jti-court', time() + 10));
        self::assertSame(1, $store->replayCount());

        // Un temps de référence postérieur à l'expiration du premier `jti`.
        self::assertTrue($store->consumeJti('jti-long', time() + 3600, time() + 60));
        self::assertSame(1, $store->replayCount(), 'l\'entrée expirée a été purgée');
    }

    #[Test]
    public function the_raw_jti_never_reaches_the_database(): void
    {
        // Un `jti` est un identifiant de jeton : il n'a rien à faire en clair
        // dans un fichier que l'on sauvegarde.
        $store = new Store($this->path());
        $store->consumeJti('jti-en-clair-lisible', time() + 300);

        self::assertStringNotContainsString('jti-en-clair-lisible', (string) file_get_contents($this->path()));
    }

    // =====================================================================
    // Story 57.2 — Migration v1 → v2 SUR UNE BASE VIVANTE
    // =====================================================================

    #[Test]
    public function a_populated_v1_database_is_migrated_in_place_without_losing_anything(): void
    {
        // ⚠️ LE test qui compte pour une mise à jour de paquet : la base d'une
        // instance qui tourne DÉJÀ, avec ses serveurs déclarés en 57.1. Une
        // migration qui repartirait de zéro effacerait la configuration de
        // l'établissement au premier `apt upgrade` — et personne ne s'en
        // apercevrait avant le premier cours en visioconférence.
        $this->createV1Database();

        $store = new Store($this->path());

        self::assertSame(Store::SCHEMA_VERSION, $store->schemaVersion());

        $servers = $store->servers();
        self::assertCount(2, $servers, 'les serveurs de 57.1 survivent');
        self::assertSame('https://bbb1.example.test/bigbluebutton/api', $servers[0]['base_url']);
        self::assertSame('secret-historique-1', $servers[0]['secret']);
        self::assertSame(50, $servers[1]['scalelite_threshold']);
        self::assertFalse($servers[1]['enabled']);

        // Et les tables neuves sont utilisables immédiatement.
        $id = $store->addRoom('Salon d\'après migration', 'prof.martin', 'Madame Martin', Room::VISIBILITY_ETAB);
        self::assertNotNull($store->roomSecrets($id));
    }

    #[Test]
    public function migrating_a_v1_database_twice_is_a_no_op(): void
    {
        // Le service redémarre à chaque mise à jour du paquet : une migration
        // non rejouable casserait au DEUXIÈME redémarrage, jamais au premier.
        $this->createV1Database();

        $first = new Store($this->path());
        $roomId = $first->addRoom('Salon', 'prof.martin', 'Madame Martin', Room::VISIBILITY_ETAB, []);

        $second = new Store($this->path());
        $second->migrate();
        $second->migrate();

        self::assertSame(Store::SCHEMA_VERSION, $second->schemaVersion());
        self::assertCount(2, $second->servers());
        self::assertNotNull($second->roomSecrets($roomId), 'le salon créé entre-temps est intact');
    }

    /** Fabrique à la main le schéma EXACT de la version 1, avec des données dedans. */
    private function createV1Database(): void
    {
        $pdo = new \PDO('sqlite:' . $this->path(), null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);

        $pdo->exec(<<<'SQL'
            CREATE TABLE servers (
                id                  INTEGER PRIMARY KEY AUTOINCREMENT,
                base_url            TEXT    NOT NULL,
                secret              TEXT    NOT NULL,
                scalelite_threshold INTEGER NOT NULL DEFAULT 0,
                enabled             INTEGER NOT NULL DEFAULT 1,
                created_at          TEXT    NOT NULL,
                updated_at          TEXT    NOT NULL
            )
        SQL);

        $pdo->exec('CREATE TABLE oidc_replay (jti TEXT PRIMARY KEY, expires_at INTEGER NOT NULL)');
        $pdo->exec('CREATE INDEX oidc_replay_expires_at ON oidc_replay (expires_at)');

        $pdo->exec(<<<'SQL'
            INSERT INTO servers (base_url, secret, scalelite_threshold, enabled, created_at, updated_at) VALUES
                ('https://bbb1.example.test/bigbluebutton/api', 'secret-historique-1', 0, 1, '2026-07-01T08:00:00Z', '2026-07-01T08:00:00Z'),
                ('https://bbb2.example.test/bigbluebutton/api', 'secret-historique-2', 50, 0, '2026-07-01T08:00:00Z', '2026-07-01T08:00:00Z')
        SQL);

        $pdo->exec('PRAGMA user_version = 1');

        unset($pdo);
    }

    // =====================================================================
    // Story 57.3 — Migration v2 → v3 SUR UNE BASE VIVANTE
    // =====================================================================

    #[Test]
    public function a_populated_v2_database_is_migrated_to_v3_without_losing_anything(): void
    {
        // Même enjeu qu'en 57.2, mais la base porte MAINTENANT les salons de
        // l'établissement : une migration qui repartirait de zéro effacerait le
        // travail des professeurs au premier `apt upgrade`.
        $this->createV2Database();

        $store = new Store($this->path());

        self::assertSame(Store::SCHEMA_VERSION, $store->schemaVersion());
        self::assertSame(3, Store::SCHEMA_VERSION);

        $servers = $store->servers();
        self::assertCount(2, $servers, 'les serveurs de 57.1 survivent');
        self::assertSame('secret-historique-1', $servers[0]['secret']);

        $rooms = $store->roomsOwnedBy('prof.martin');
        self::assertCount(2, $rooms, 'les salons de 57.2 survivent');
        self::assertSame('jeton-public-2', $rooms[0]->token);
        self::assertSame('Conseil de classe', $rooms[0]->name);
        self::assertSame(['4B'], $rooms[1]->groups, 'les groupes de visibilité aussi');
        self::assertFalse($rooms[0]->hasGuestAccess, 'aucune invitation avant la migration, aucune après');

        // Et les colonnes neuves sont utilisables immédiatement.
        $invitation = $store->enableGuestAccess($rooms[0]->id);
        self::assertNotNull($store->roomByGuestToken($invitation['token']));
    }

    #[Test]
    public function migrating_a_v2_database_twice_is_a_no_op(): void
    {
        // `ALTER TABLE … ADD COLUMN` échoue si la colonne existe : c'est le
        // garde-fou `user_version` qui porte l'idempotence, et rien d'autre. Un
        // deuxième démarrage du service ne doit pas rejouer le palier.
        $this->createV2Database();

        $first = new Store($this->path());
        $invitation = $first->enableGuestAccess($first->roomsOwnedBy('prof.martin')[0]->id);

        $second = new Store($this->path());
        $second->migrate();
        $second->migrate();

        self::assertSame(Store::SCHEMA_VERSION, $second->schemaVersion());
        self::assertCount(2, $second->servers());
        self::assertNotNull($second->roomByGuestToken($invitation['token']), 'l\'invitation posée entre-temps est intacte');
    }

    // ── Review 57.3 #1 — la migration survit à la concurrence et au crash ────
    //
    // `php -S` tourne à 4 workers, qui sont des PROCESSUS distincts, et ce
    // magasin est reconstruit à CHAQUE requête. Deux requêtes simultanées juste
    // après une mise à jour du paquet lisaient toutes deux l'ancienne version et
    // rejouaient toutes deux le palier ; la seconde échouait sur `duplicate
    // column name`, et comme `user_version` n'était jamais posé, elle échouait
    // ENSUITE À CHAQUE REQUÊTE. `GET /` ne touchant pas la base, la sonde de
    // santé de SE5 serait restée au vert pendant que l'extension était morte.

    #[Test]
    public function a_second_store_opened_on_a_half_migrated_database_repairs_it(): void
    {
        // Simule EXACTEMENT l'état laissé par un processus tué au milieu de
        // l'ancien palier : des colonnes déjà ajoutées, mais `user_version`
        // resté en arrière. Avant correction, ceci relançait `ADD COLUMN` sur
        // une colonne existante — panne définitive.
        $this->createV2Database();

        $pdo = new \PDO('sqlite:' . $this->path());
        $pdo->exec('ALTER TABLE rooms ADD COLUMN guest_token TEXT');
        $pdo->exec('ALTER TABLE rooms ADD COLUMN guest_password TEXT');
        unset($pdo);

        // Aucune exception attendue, et la base doit finir COMPLÈTE.
        $store = new Store($this->path());

        self::assertSame(Store::SCHEMA_VERSION, $store->schemaVersion());
        self::assertCount(2, $store->servers(), 'la réparation ne détruit rien');

        $invitation = $store->enableGuestAccess($store->roomsOwnedBy('prof.martin')[0]->id);
        self::assertNotNull(
            $store->roomByGuestToken($invitation['token']),
            'les colonnes du palier v3 sont toutes là, pas seulement les deux posées à la main',
        );
    }

    #[Test]
    public function a_failing_migration_leaves_the_schema_version_untouched(): void
    {
        // CONTRÔLE POSITIF de la transaction : si le palier échoue, il ne doit
        // RIEN laisser derrière lui — ni colonne, ni version avancée. Sans la
        // transaction, `user_version` restait certes en arrière, mais les
        // colonnes déjà ajoutées, elles, restaient : c'est ce demi-état qui
        // rendait la panne définitive.
        $this->createV2Database();

        // On fabrique un échec certain au MILIEU du palier : une colonne du
        // palier existe déjà ET porte un index unique du même nom, ce que
        // `CREATE UNIQUE INDEX IF NOT EXISTS` ne rattrapera pas puisqu'il
        // portera sur une définition incompatible.
        $pdo = new \PDO('sqlite:' . $this->path());
        $pdo->exec('CREATE TABLE rooms_guest_token (bloque INTEGER)');
        unset($pdo);

        $store = null;

        try {
            $store = new Store($this->path());
        } catch (\Throwable) {
            // Attendu : l'échec doit être BRUYANT, jamais avalé.
        }

        $check = new \PDO('sqlite:' . $this->path());
        $version = (int) $check->query('PRAGMA user_version')?->fetchColumn();

        self::assertSame(2, $version, 'un palier qui échoue ne doit pas avancer la version');

        $columns = array_column(
            $check->query('PRAGMA table_info(rooms)')?->fetchAll(\PDO::FETCH_ASSOC) ?: [],
            'name',
        );

        self::assertNotContains(
            'guest_token',
            $columns,
            'un palier qui échoue ne doit laisser AUCUNE colonne derrière lui — c\'est le demi-état qui rendait la panne définitive',
        );

        unset($check, $store);
    }

    /** Fabrique à la main le schéma EXACT de la version 2, avec des données dedans. */
    private function createV2Database(): void
    {
        $this->createV1Database();

        $pdo = new \PDO('sqlite:' . $this->path(), null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);

        $pdo->exec(<<<'SQL'
            CREATE TABLE rooms (
                id              INTEGER PRIMARY KEY AUTOINCREMENT,
                token           TEXT    NOT NULL UNIQUE,
                name            TEXT    NOT NULL,
                owner_sub       TEXT    NOT NULL,
                owner_name      TEXT    NOT NULL,
                visibility      TEXT    NOT NULL CHECK (visibility IN ('etab','classe','private')),
                attendee_pw     TEXT    NOT NULL,
                moderator_pw    TEXT    NOT NULL,
                server_id       INTEGER,
                last_started_at TEXT,
                created_at      TEXT    NOT NULL,
                updated_at      TEXT    NOT NULL
            )
        SQL);

        $pdo->exec(<<<'SQL'
            CREATE TABLE room_groups (
                room_id    INTEGER NOT NULL REFERENCES rooms(id) ON DELETE CASCADE,
                group_name TEXT    NOT NULL,
                PRIMARY KEY (room_id, group_name)
            )
        SQL);

        $pdo->exec('CREATE INDEX room_groups_group_name ON room_groups (group_name)');
        $pdo->exec('CREATE INDEX rooms_owner_sub ON rooms (owner_sub)');

        $pdo->exec(<<<'SQL'
            INSERT INTO rooms (token, name, owner_sub, owner_name, visibility, attendee_pw, moderator_pw,
                               server_id, last_started_at, created_at, updated_at) VALUES
                ('jeton-public-1', 'Cours de mathématiques', 'prof.martin', 'Madame Martin', 'classe',
                 'pw-participant-1', 'pw-moderateur-1', 1, '2026-07-10T09:00:00Z',
                 '2026-07-01T08:00:00Z', '2026-07-10T09:00:00Z'),
                ('jeton-public-2', 'Conseil de classe', 'prof.martin', 'Madame Martin', 'private',
                 'pw-participant-2', 'pw-moderateur-2', NULL, NULL,
                 '2026-07-02T08:00:00Z', '2026-07-02T08:00:00Z')
        SQL);

        $pdo->exec("INSERT INTO room_groups (room_id, group_name) VALUES (1, '4B')");
        $pdo->exec('PRAGMA user_version = 2');

        unset($pdo);
    }

    // =====================================================================
    // Story 57.3 — L'invitation externe
    // =====================================================================

    #[Test]
    public function enabling_an_invitation_yields_a_token_and_a_dictatable_password(): void
    {
        $store = new Store($this->path());
        $roomId = $store->addRoom('Salon', 'prof.martin', 'Madame Martin', Room::VISIBILITY_ETAB);

        self::assertNull($store->guestInvitation($roomId), 'aucune invitation par défaut');

        $invitation = $store->enableGuestAccess($roomId);

        // Le jeton : 16 octets d'aléa cryptographique, soit 32 caractères
        // hexadécimaux. Le legacy y mettait le LOGIN du créateur, en clair.
        self::assertSame(32, strlen($invitation['token']));
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $invitation['token']);

        // Le mot de passe : huit caractères d'un alphabet sans ambiguïté — il
        // doit pouvoir se dicter au téléphone. Ni `0`/`O`, ni `1`/`l`/`I`.
        self::assertSame(8, strlen($invitation['password']));
        self::assertMatchesRegularExpression('/^[A-HJ-NP-Z2-9]{8}$/', $invitation['password']);

        self::assertSame($invitation, $store->guestInvitation($roomId));
        self::assertSame($invitation['password'], $store->guestPassword($roomId));

        $found = $store->roomByGuestToken($invitation['token']);
        self::assertNotNull($found);
        self::assertSame($roomId, $found->id);
        self::assertTrue($found->hasGuestAccess);
    }

    #[Test]
    public function regenerating_replaces_both_values_and_kills_the_previous_ones(): void
    {
        $store = new Store($this->path());
        $roomId = $store->addRoom('Salon', 'prof.martin', 'Madame Martin', Room::VISIBILITY_ETAB);

        $first = $store->enableGuestAccess($roomId);
        $second = $store->enableGuestAccess($roomId);

        self::assertNotSame($first['token'], $second['token']);
        self::assertNotSame($first['password'], $second['password']);

        self::assertNull($store->roomByGuestToken($first['token']), 'l\'ancien jeton ne résout plus rien');
        self::assertNotNull($store->roomByGuestToken($second['token']));
    }

    #[Test]
    public function revoking_takes_the_token_and_the_password_away_together(): void
    {
        $store = new Store($this->path());
        $roomId = $store->addRoom('Salon', 'prof.martin', 'Madame Martin', Room::VISIBILITY_ETAB);

        $invitation = $store->enableGuestAccess($roomId);
        $store->revokeGuestAccess($roomId);

        // Les deux colonnes vivent et meurent ENSEMBLE : il n'existe aucun état
        // où un mot de passe survivrait à son jeton.
        self::assertNull($store->guestInvitation($roomId));
        self::assertNull($store->guestPassword($roomId));
        self::assertNull($store->roomByGuestToken($invitation['token']));

        $room = $store->roomByToken(
            $store->roomsOwnedBy('prof.martin')[0]->token
        );
        self::assertNotNull($room);
        self::assertFalse($room->hasGuestAccess);
    }

    #[Test]
    public function many_rooms_without_an_invitation_coexist_without_colliding(): void
    {
        // ⚠️ L'index sur `guest_token` est UNIQUE, et c'est ce qui garantit
        // qu'un jeton ne désigne jamais deux salons. Encore faut-il que les
        // salons SANS invitation puissent cohabiter : en SQLite, deux `NULL`
        // sont mutuellement distincts pour un index unique — ils le peuvent.
        $store = new Store($this->path());

        for ($i = 0; $i < 5; $i++) {
            $store->addRoom('Salon ' . $i, 'prof.martin', 'Madame Martin', Room::VISIBILITY_ETAB);
        }

        self::assertCount(5, $store->roomsOwnedBy('prof.martin'));
    }

    #[Test]
    public function an_empty_guest_token_never_matches_anything(): void
    {
        $store = new Store($this->path());
        $store->addRoom('Salon', 'prof.martin', 'Madame Martin', Room::VISIBILITY_ETAB);

        self::assertNull($store->roomByGuestToken(''));
    }

    #[Test]
    public function the_failure_window_is_fixed_and_forgets_by_itself(): void
    {
        $store = new Store($this->path());
        $roomId = $store->addRoom('Salon', 'prof.martin', 'Madame Martin', Room::VISIBILITY_ETAB);
        $store->enableGuestAccess($roomId);

        $now = 1_800_000_000;
        $window = 900;

        self::assertSame(0, $store->guestFailuresInWindow($roomId, $now, $window));

        $store->recordGuestFailure($roomId, $now, $window);
        $store->recordGuestFailure($roomId, $now + 10, $window);
        $store->recordGuestFailure($roomId, $now + 20, $window);

        self::assertSame(3, $store->guestFailuresInWindow($roomId, $now + 30, $window));

        // La fenêtre est FIXE : elle court depuis le PREMIER échec, pas depuis
        // le dernier. Une fois expirée, l'ardoise est vierge — sans écriture,
        // sans purge, sans tâche planifiée.
        self::assertSame(0, $store->guestFailuresInWindow($roomId, $now + $window, $window));

        // Et un échec après l'expiration rouvre une fenêtre neuve, à un.
        $store->recordGuestFailure($roomId, $now + $window + 1, $window);
        self::assertSame(1, $store->guestFailuresInWindow($roomId, $now + $window + 2, $window));
    }

    #[Test]
    public function an_horodate_from_the_future_is_treated_as_no_window_at_all(): void
    {
        // Horloge qui a reculé, base recopiée d'ailleurs : on ne peut rien
        // affirmer, donc on ne bloque personne sur la foi d'un compteur douteux.
        $store = new Store($this->path());
        $roomId = $store->addRoom('Salon', 'prof.martin', 'Madame Martin', Room::VISIBILITY_ETAB);

        $store->recordGuestFailure($roomId, 1_800_000_000, 900);

        self::assertSame(0, $store->guestFailuresInWindow($roomId, 1_700_000_000, 900));
    }

    #[Test]
    public function resetting_the_failures_wipes_the_window_too(): void
    {
        $store = new Store($this->path());
        $roomId = $store->addRoom('Salon', 'prof.martin', 'Madame Martin', Room::VISIBILITY_ETAB);

        $now = 1_800_000_000;
        $store->recordGuestFailure($roomId, $now, 900);
        $store->recordGuestFailure($roomId, $now, 900);

        $store->resetGuestFailures($roomId);

        self::assertSame(0, $store->guestFailuresInWindow($roomId, $now, 900));
    }

    #[Test]
    public function enabling_an_invitation_clears_the_failure_counter(): void
    {
        // Régénérer, c'est repartir de zéro : un professeur qui renouvelle son
        // lien parce que quelqu'un l'a bourriné ne doit pas trouver la porte
        // encore fermée derrière.
        $store = new Store($this->path());
        $roomId = $store->addRoom('Salon', 'prof.martin', 'Madame Martin', Room::VISIBILITY_ETAB);

        $now = 1_800_000_000;
        $store->recordGuestFailure($roomId, $now, 900);
        $store->recordGuestFailure($roomId, $now, 900);

        $store->enableGuestAccess($roomId);

        self::assertSame(0, $store->guestFailuresInWindow($roomId, $now, 900));
    }

    #[Test]
    public function deleting_a_room_takes_its_invitation_with_it(): void
    {
        $store = new Store($this->path());
        $roomId = $store->addRoom('Salon', 'prof.martin', 'Madame Martin', Room::VISIBILITY_ETAB);
        $invitation = $store->enableGuestAccess($roomId);

        $store->deleteRoom($roomId);

        self::assertNull($store->roomByGuestToken($invitation['token']));
        self::assertNull($store->guestInvitation($roomId));
    }

    // =====================================================================
    // Story 57.2 — Salons
    // =====================================================================

    #[Test]
    public function a_room_is_created_with_a_public_token_and_two_distinct_passwords(): void
    {
        $store = new Store($this->path());

        $id = $store->addRoom('Cours', 'prof.martin', 'Madame Martin', Room::VISIBILITY_CLASSE, ['4B', '3A']);
        $secrets = $store->roomSecrets($id);

        self::assertNotNull($secrets);
        self::assertNotSame($secrets['attendee'], $secrets['moderator']);

        $room = $store->roomsVisibleTo(new Identity('prof.martin', 'Madame Martin', 'prof', []))[0];

        self::assertSame('Cours', $room->name);
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $room->token);
        self::assertSame(['3A', '4B'], $room->groups, 'les groupes reviennent triés');
        self::assertNull($room->serverId);
        self::assertNull($room->lastStartedAt);
    }

    #[Test]
    public function a_room_is_found_by_its_token_and_by_nothing_else(): void
    {
        $store = new Store($this->path());
        $id = $store->addRoom('Cours', 'prof.martin', 'Madame Martin', Room::VISIBILITY_ETAB);

        $token = $store->roomsVisibleTo(new Identity('prof.martin', 'Madame Martin', 'prof', []))[0]->token;

        self::assertSame($id, $store->roomByToken($token)?->id);
        self::assertNull($store->roomByToken(''), 'un jeton vide ne désigne rien');
        self::assertNull($store->roomByToken(bin2hex(random_bytes(16))));
    }

    #[Test]
    public function visibility_is_decided_in_sql_for_the_three_cases(): void
    {
        $store = new Store($this->path());

        $store->addRoom('Établissement', 'prof.martin', 'Madame Martin', Room::VISIBILITY_ETAB);
        $store->addRoom('Classe 4B', 'prof.martin', 'Madame Martin', Room::VISIBILITY_CLASSE, ['4B']);
        $store->addRoom('Privé', 'prof.martin', 'Madame Martin', Room::VISIBILITY_PRIVATE);

        $names = static fn (array $rooms): array => array_map(static fn (Room $r): string => $r->name, $rooms);

        self::assertSame(
            ['Privé', 'Classe 4B', 'Établissement'],
            $names($store->roomsVisibleTo(new Identity('prof.martin', 'Madame Martin', 'prof', ['4B']))),
            'le créateur voit tout ce qui est à lui',
        );

        self::assertSame(
            ['Classe 4B', 'Établissement'],
            $names($store->roomsVisibleTo(new Identity('paul.durand', 'Paul Durand', 'eleve', ['4B']))),
        );

        self::assertSame(
            ['Établissement'],
            $names($store->roomsVisibleTo(new Identity('jules.petit', 'Jules Petit', 'eleve', ['5A']))),
        );
    }

    #[Test]
    public function an_empty_groups_claim_is_a_normal_case_not_an_invalid_query(): void
    {
        // `IN ()` n'existe pas en SQL : une clause fabriquée à vide ferait
        // échouer la requête — donc la page — pour un administratif ou un
        // professeur sans classe déclarée. Cas NORMAL, court-circuité.
        $store = new Store($this->path());

        $store->addRoom('Établissement', 'prof.martin', 'Madame Martin', Room::VISIBILITY_ETAB);
        $store->addRoom('Classe 4B', 'prof.martin', 'Madame Martin', Room::VISIBILITY_CLASSE, ['4B']);

        $visible = $store->roomsVisibleTo(new Identity('agent.durand', 'Agent Durand', 'administratif', []));

        self::assertCount(1, $visible);
        self::assertSame('Établissement', $visible[0]->name);
    }

    #[Test]
    public function deleting_a_room_takes_its_groups_with_it(): void
    {
        // `ON DELETE CASCADE` ne fonctionne que si `PRAGMA foreign_keys` est
        // activé — ce qui n'est PAS le défaut de SQLite. La garde est posée à
        // l'ouverture ; ce test est là pour le jour où quelqu'un la retirera.
        $store = new Store($this->path());

        $id = $store->addRoom('Cours', 'prof.martin', 'Madame Martin', Room::VISIBILITY_CLASSE, ['4B', '3A']);
        self::assertSame(2, $store->roomGroupCount());

        $store->deleteRoom($id);

        self::assertSame(0, $store->roomGroupCount());
        self::assertSame([], $store->roomsVisibleTo(new Identity('prof.martin', 'Madame Martin', 'prof', ['4B'])));
    }

    #[Test]
    public function the_same_group_submitted_twice_is_stored_once(): void
    {
        $store = new Store($this->path());

        $store->addRoom('Cours', 'prof.martin', 'Madame Martin', Room::VISIBILITY_CLASSE, ['4B', '4B']);

        self::assertSame(1, $store->roomGroupCount());
    }

    #[Test]
    public function groups_are_ignored_for_a_visibility_that_has_no_use_for_them(): void
    {
        $store = new Store($this->path());

        $store->addRoom('Établissement', 'prof.martin', 'Madame Martin', Room::VISIBILITY_ETAB, ['4B']);
        $store->addRoom('Privé', 'prof.martin', 'Madame Martin', Room::VISIBILITY_PRIVATE, ['4B']);

        self::assertSame(0, $store->roomGroupCount());
    }

    #[Test]
    public function a_visibility_outside_the_vocabulary_never_reaches_the_table(): void
    {
        // Dernière ligne de défense : le `CHECK` du schéma. Le contrôleur refuse
        // déjà, mais une table qui accepterait `world` laisserait une ligne dont
        // plus personne ne saurait quoi faire.
        $store = new Store($this->path());

        $this->expectException(\PDOException::class);

        $store->addRoom('Salon', 'prof.martin', 'Madame Martin', 'world');
    }

    #[Test]
    public function starting_a_room_records_its_server_and_a_date_that_proves_nothing(): void
    {
        $store = new Store($this->path());

        $serverId = $store->addServer('https://bbb.example.test/bigbluebutton/api', 'secret');
        $id = $store->addRoom('Cours', 'prof.martin', 'Madame Martin', Room::VISIBILITY_ETAB);

        $store->markStarted($id, $serverId);

        $room = $store->roomsVisibleTo(new Identity('prof.martin', 'Madame Martin', 'prof', []))[0];

        self::assertSame($serverId, $room->serverId);
        self::assertNotNull($room->lastStartedAt);
    }

    #[Test]
    public function the_first_active_server_is_the_one_that_gets_used(): void
    {
        $store = new Store($this->path());

        self::assertNull($store->firstEnabledServer(), 'aucun serveur : rien à choisir, et surtout pas une erreur');

        $store->addServer('https://bbb1.example.test/api', 'secret-1', 0, false);
        $second = $store->addServer('https://bbb2.example.test/api', 'secret-2', 0, true);
        $store->addServer('https://bbb3.example.test/api', 'secret-3', 0, true);

        self::assertSame($second, $store->firstEnabledServer()['id'], 'le premier ACTIF, pas le premier tout court');
    }

    #[Test]
    public function no_room_password_is_ever_carried_by_the_object_that_reaches_a_page(): void
    {
        // Propriété STRUCTURELLE, et c'est ce qui la rend sûre : l'objet qui
        // traverse les contrôleurs et les vues n'a pas d'attribut où un mot de
        // passe pourrait se trouver. Le legacy, lui, les postait tous les deux
        // en champs cachés.
        $store = new Store($this->path());
        $store->addRoom('Cours', 'prof.martin', 'Madame Martin', Room::VISIBILITY_ETAB);

        $room = $store->roomsVisibleTo(new Identity('prof.martin', 'Madame Martin', 'prof', []))[0];
        $serialized = print_r($room, true);
        $secrets = $store->roomSecrets($room->id);

        self::assertNotNull($secrets);
        self::assertStringNotContainsString($secrets['attendee'], $serialized);
        self::assertStringNotContainsString($secrets['moderator'], $serialized);
    }

    #[Test]
    public function the_sqlite_guard_keeps_a_token_valid_within_the_clock_leeway(): void
    {
        // Le vérificateur accepte un jeton expiré depuis moins que la tolérance
        // d'horloge : l'anti-rejeu doit donc mémoriser jusqu'à `exp + leeway`,
        // sinon un jeton encore acceptable serait rejouable.
        $store = new Store($this->path());
        $guard = new SqliteReplayGuard($store);

        $justExpired = time() - (IdTokenVerifier::LEEWAY - 10);

        self::assertTrue($guard->consumeOnce('jti-dans-la-tolerance', $justExpired));
        self::assertFalse($guard->consumeOnce('jti-dans-la-tolerance', $justExpired), 'rejeu refusé');
    }
}

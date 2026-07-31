<?php

declare(strict_types=1);

namespace SambaEdu\ExtBbb;

use PDO;
use PDOException;
use SambaEdu\ExtBbb\Rooms\Room;
use Throwable;

/**
 * Story 57.1 — **L'ÉTAT DE L'EXTENSION : UNE BASE SQLITE, À ELLE.**
 *
 * Décision D3 : SQLite, et pas Postgres. Le helper root de l'Epic 56 ne sait ni
 * créer une base ni poser un identifiant de connexion ; lui apprendre à le faire
 * élargirait une frontière de privilège déjà livrée et reviewée. SQLite garde le
 * canal d'installation INCHANGÉ, l'extension réellement autonome, et la
 * sauvegarde triviale. Le volume — les serveurs et les salons d'un
 * établissement — est sans commune mesure avec les limites de SQLite.
 *
 * ══════════════════════════════════════════════════════════════════════════
 *  LE SCHÉMA EST APPLIQUÉ PAR L'APPLICATION, PAS PAR UN OUTIL EXTERNE
 *
 *  Aucun `migrate` à lancer, aucun paquet à re-configurer : {@see migrate()}
 *  est **idempotente** et tourne à chaque ouverture, pilotée par
 *  `PRAGMA user_version`. Un démarrage sur une base absente la crée ; un
 *  démarrage sur une base à jour ne fait rien.
 * ══════════════════════════════════════════════════════════════════════════
 *
 * **Où vit le fichier, et pourquoi ce n'est pas le postinst qui le crée.**
 * L'unité systemd déclare `DynamicUser=yes` : l'UID du service est **volatil**,
 * réalloué à chaque démarrage. Un `chown` figé au postinst casserait au premier
 * redémarrage. C'est `StateDirectory=sambaedu-ext-bbb` qui fait gérer
 * `/var/lib/sambaedu-ext-bbb` (création, propriétaire, remap) par systemd ;
 * l'application y crée `database.sqlite` elle-même. Le postinst reste vide.
 *
 * **Les secrets BBB sont stockés EN CLAIR dans ce fichier — décision assumée.**
 * La protection est le système de fichiers : répertoire d'état 0700 possédé par
 * l'utilisateur dynamique du service, fichier 0600, illisible par `www-admin`
 * comme par les autres extensions (NFR4 : isolation par processus). Chiffrer
 * applicativement n'apporterait rien — la clé vivrait au même endroit, lisible
 * par qui lit déjà la base. En revanche le secret ne doit JAMAIS quitter ce
 * fichier : ni dans une page, ni dans une URL, ni dans un journal.
 *
 * Toutes les requêtes sont préparées. Aucune concaténation de valeur dans du
 * SQL, jamais.
 */
final class Store
{
    /** Version de schéma appliquée par cette version du code. */
    public const SCHEMA_VERSION = 2;

    /**
     * Longueur en OCTETS de l'aléa d'un identifiant public ou d'un mot de passe
     * de salon. 16 octets = 128 bits = 32 caractères hexadécimaux.
     *
     * Le legacy tirait `rand(100, 999)` pour le mot de passe participant et
     * `rand(1000, 9999)` pour le mot de passe modérateur : neuf cents valeurs
     * possibles d'un côté, neuf mille de l'autre, produites par un générateur
     * non cryptographique et devinables en quelques secondes. C'est le défaut
     * §9.3 de la carte du legacy, et il est mort ici.
     */
    private const RANDOM_BYTES = 16;

    private PDO $pdo;

    public function __construct(private readonly string $path)
    {
        $isNew = ! file_exists($this->path);

        $directory = dirname($this->path);
        if (! is_dir($directory)) {
            @mkdir($directory, 0700, true);
        }

        $this->pdo = new PDO('sqlite:' . $this->path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);

        if ($isNew) {
            // 0600 dès la création : le fichier porte les secrets des serveurs
            // BBB. La fenêtre entre `new PDO` et `chmod` est d'un tour de
            // boucle, sur un répertoire déjà 0700.
            @chmod($this->path, 0600);
        }

        $this->pdo->exec('PRAGMA journal_mode = WAL');
        $this->pdo->exec('PRAGMA foreign_keys = ON');
        $this->pdo->exec('PRAGMA busy_timeout = 5000');

        $this->migrate();
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    public function schemaVersion(): int
    {
        $value = $this->pdo->query('PRAGMA user_version')?->fetchColumn();

        return is_scalar($value) ? (int) $value : 0;
    }

    // =====================================================================
    // Migration idempotente
    // =====================================================================

    /**
     * Applique les paliers de schéma manquants. Rejouable sans effet.
     *
     * `PRAGMA user_version` n'accepte pas de paramètre lié (c'est une directive,
     * pas une requête) : la valeur interpolée est une CONSTANTE de classe
     * entière, jamais une donnée d'entrée.
     */
    public function migrate(): void
    {
        $current = $this->schemaVersion();

        if ($current >= self::SCHEMA_VERSION) {
            return;
        }

        if ($current < 1) {
            // Un serveur BBB = UNE LIGNE. Le legacy SE4 tenait trois listes CSV
            // parallèles indexées par position dans un fichier de configuration
            // (`bbb_server_base_url`, `bbb_secret`, `bbb_server_scalelite`), et
            // son formulaire d'ajout se désindexait après suppression d'un
            // serveur intermédiaire — un serveur héritait alors du secret d'un
            // autre. Le bug disparaît par construction ici.
            $this->pdo->exec(<<<'SQL'
                CREATE TABLE IF NOT EXISTS servers (
                    id                  INTEGER PRIMARY KEY AUTOINCREMENT,
                    base_url            TEXT    NOT NULL,
                    secret              TEXT    NOT NULL,
                    scalelite_threshold INTEGER NOT NULL DEFAULT 0,
                    enabled             INTEGER NOT NULL DEFAULT 1,
                    created_at          TEXT    NOT NULL,
                    updated_at          TEXT    NOT NULL
                )
            SQL);

            // Anti-rejeu des `jti` d'id_token (voir Oidc\SqliteReplayGuard).
            $this->pdo->exec(<<<'SQL'
                CREATE TABLE IF NOT EXISTS oidc_replay (
                    jti        TEXT    PRIMARY KEY,
                    expires_at INTEGER NOT NULL
                )
            SQL);

            $this->pdo->exec('CREATE INDEX IF NOT EXISTS oidc_replay_expires_at ON oidc_replay (expires_at)');
        }

        if ($current < 2) {
            // ═══════════════════════════════════════════════════════════════
            //  LA TABLE **EST** LA SÉMANTIQUE DU SALON (AR11)
            //
            //  SE4 n'avait aucune persistance : un salon n'existait que dans
            //  le `meetingID` envoyé à BigBlueButton, fabriqué par
            //  concaténation de hashes —
            //  `<visibilité>-md5(base_dn)-md5(login)-md5(classe)…-rand-rand`.
            //  Conséquences directes : pour savoir qui voit quoi, il fallait
            //  relire TOUTES les classes de l'établissement et recalculer
            //  leurs `md5` ; et le filtre « mes enregistrements », qui lisait
            //  le segment n°2, se décalait dès qu'un salon portait des
            //  classes. Ici le `meetingID` est un jeton opaque : il ne veut
            //  RIEN dire, et c'est précisément sa qualité.
            // ═══════════════════════════════════════════════════════════════
            $this->pdo->exec(<<<'SQL'
                CREATE TABLE IF NOT EXISTS rooms (
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

            // Le nom NU du groupe, tel qu'il arrive dans les claims — jamais un
            // DN, jamais un identifiant d'annuaire, jamais un hash. C'est la
            // même chaîne des deux côtés (prof et élèves sont co-membres d'une
            // même ligne, contrat §6) : l'intersection suffit à décider.
            $this->pdo->exec(<<<'SQL'
                CREATE TABLE IF NOT EXISTS room_groups (
                    room_id    INTEGER NOT NULL REFERENCES rooms(id) ON DELETE CASCADE,
                    group_name TEXT    NOT NULL,
                    PRIMARY KEY (room_id, group_name)
                )
            SQL);

            $this->pdo->exec('CREATE INDEX IF NOT EXISTS room_groups_group_name ON room_groups (group_name)');
            $this->pdo->exec('CREATE INDEX IF NOT EXISTS rooms_owner_sub ON rooms (owner_sub)');
        }

        $this->pdo->exec('PRAGMA user_version = ' . self::SCHEMA_VERSION);
    }

    // =====================================================================
    // Serveurs BBB
    // =====================================================================

    /** @return list<array<string, mixed>> */
    public function servers(): array
    {
        $rows = $this->pdo->query('SELECT * FROM servers ORDER BY id')?->fetchAll() ?: [];

        return array_map(self::hydrateServer(...), $rows);
    }

    /** @return array<string, mixed>|null */
    public function server(int $id): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM servers WHERE id = :id');
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();

        return is_array($row) ? self::hydrateServer($row) : null;
    }

    /**
     * `$scaleliteThreshold` : `0` = serveur BBB normal, dont la charge se mesure
     * par `getMeetings` (Story 57.4) ; `> 0` = Scalelite, et la valeur est un
     * **seuil fixe configuré, jamais sondé** — sémantique reprise telle quelle
     * du legacy (D5), qui ne fait AUCUN appel API sur un serveur Scalelite et
     * retourne la valeur saisie comme nombre d'utilisateurs.
     */
    public function addServer(string $baseUrl, string $secret, int $scaleliteThreshold = 0, bool $enabled = true): int
    {
        $now = self::now();

        $this->pdo->prepare(<<<'SQL'
            INSERT INTO servers (base_url, secret, scalelite_threshold, enabled, created_at, updated_at)
            VALUES (:base_url, :secret, :scalelite, :enabled, :created_at, :updated_at)
        SQL)->execute([
            'base_url' => $baseUrl,
            'secret' => $secret,
            'scalelite' => max(0, $scaleliteThreshold),
            'enabled' => $enabled ? 1 : 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * `$secret === null` ⇒ le secret existant est CONSERVÉ. C'est le pendant
     * serveur du champ write-only du formulaire : l'admin ne le revoit jamais,
     * donc ne peut pas le renvoyer, donc ne doit pas l'effacer en éditant l'URL.
     */
    public function updateServer(int $id, string $baseUrl, ?string $secret, int $scaleliteThreshold, bool $enabled): void
    {
        $parameters = [
            'base_url' => $baseUrl,
            'scalelite' => max(0, $scaleliteThreshold),
            'enabled' => $enabled ? 1 : 0,
            'updated_at' => self::now(),
            'id' => $id,
        ];

        if ($secret === null) {
            $sql = 'UPDATE servers SET base_url = :base_url, scalelite_threshold = :scalelite,
                    enabled = :enabled, updated_at = :updated_at WHERE id = :id';
        } else {
            $sql = 'UPDATE servers SET base_url = :base_url, secret = :secret, scalelite_threshold = :scalelite,
                    enabled = :enabled, updated_at = :updated_at WHERE id = :id';
            $parameters['secret'] = $secret;
        }

        $this->pdo->prepare($sql)->execute($parameters);
    }

    public function deleteServer(int $id): void
    {
        $this->pdo->prepare('DELETE FROM servers WHERE id = :id')->execute(['id' => $id]);
    }

    public function setServerEnabled(int $id, bool $enabled): void
    {
        $this->pdo->prepare('UPDATE servers SET enabled = :enabled, updated_at = :updated_at WHERE id = :id')
            ->execute(['enabled' => $enabled ? 1 : 0, 'updated_at' => self::now(), 'id' => $id]);
    }

    /**
     * Le premier serveur ACTIF, par ordre d'insertion.
     *
     * Story 57.2 : c'est tout ce dont cette story a besoin. Le choix du serveur
     * le moins chargé, et la bascule sur panne, sont le sujet entier de la
     * story 57.4 — les écrire ici les écrirait à moitié.
     *
     * @return array<string, mixed>|null
     */
    public function firstEnabledServer(): ?array
    {
        $row = $this->pdo->query('SELECT * FROM servers WHERE enabled = 1 ORDER BY id LIMIT 1')?->fetch();

        return is_array($row) ? self::hydrateServer($row) : null;
    }

    // =====================================================================
    // Salons
    // =====================================================================

    /**
     * Crée un salon et ses groupes de visibilité, en UNE transaction.
     *
     * Le jeton public et les deux mots de passe sont tirés ICI, de
     * `random_bytes` : ni le contrôleur ni le formulaire n'ont voix au
     * chapitre. Les deux mots de passe sont DIFFÉRENTS — ce sont eux qui
     * portent le rôle côté BigBlueButton, les confondre reviendrait à faire
     * modérateur quiconque rejoint.
     *
     * @param  list<string>  $groups  Noms NUS, tels qu'ils arrivent dans les claims.
     */
    public function addRoom(
        string $name,
        string $ownerSub,
        string $ownerName,
        string $visibility,
        array $groups = [],
    ): int {
        $now = self::now();

        $this->pdo->beginTransaction();

        try {
            $this->pdo->prepare(<<<'SQL'
                INSERT INTO rooms (token, name, owner_sub, owner_name, visibility,
                                   attendee_pw, moderator_pw, created_at, updated_at)
                VALUES (:token, :name, :owner_sub, :owner_name, :visibility,
                        :attendee_pw, :moderator_pw, :created_at, :updated_at)
            SQL)->execute([
                'token' => self::newSecret(),
                'name' => $name,
                'owner_sub' => $ownerSub,
                'owner_name' => $ownerName,
                'visibility' => $visibility,
                'attendee_pw' => self::newSecret(),
                'moderator_pw' => self::newSecret(),
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $id = (int) $this->pdo->lastInsertId();

            if ($visibility === Room::VISIBILITY_CLASSE) {
                $statement = $this->pdo->prepare(
                    'INSERT OR IGNORE INTO room_groups (room_id, group_name) VALUES (:room_id, :group_name)'
                );

                foreach (array_unique($groups) as $group) {
                    $statement->execute(['room_id' => $id, 'group_name' => $group]);
                }
            }

            $this->pdo->commit();

            return $id;
        } catch (Throwable $e) {
            $this->pdo->rollBack();

            throw $e;
        }
    }

    /** Le salon désigné par son jeton PUBLIC, sans ses mots de passe. */
    public function roomByToken(string $token): ?Room
    {
        if ($token === '') {
            return null;
        }

        $statement = $this->pdo->prepare('SELECT * FROM rooms WHERE token = :token');
        $statement->execute(['token' => $token]);
        $row = $statement->fetch();

        if (! is_array($row)) {
            return null;
        }

        return Room::fromRow($row, $this->groupsOf((int) $row['id']));
    }

    /**
     * Les salons visibles POUR CETTE identité, décidés en SQL.
     *
     * Trois cas, et trois seulement : mes salons (quelle que soit leur
     * visibilité), les salons d'établissement, et les salons de classe dont au
     * moins un groupe figure dans MES groupes.
     *
     * ⚠️ Un claim `groups` vide est un cas NORMAL (un administratif, un prof
     * sans classe déclarée) : `IN ()` n'existe pas en SQL, la clause entière est
     * donc omise plutôt que fabriquée à vide.
     *
     * @return list<Room>
     */
    public function roomsVisibleTo(Identity $identity): array
    {
        $parameters = ['sub' => $identity->sub];
        $clauses = ['owner_sub = :sub', "visibility = '" . Room::VISIBILITY_ETAB . "'"];

        if ($identity->groups !== []) {
            $placeholders = [];

            foreach (array_values($identity->groups) as $index => $group) {
                $key = 'g' . $index;
                $placeholders[] = ':' . $key;
                $parameters[$key] = $group;
            }

            $clauses[] = "(visibility = '" . Room::VISIBILITY_CLASSE . "' AND EXISTS ("
                . 'SELECT 1 FROM room_groups WHERE room_groups.room_id = rooms.id '
                . 'AND room_groups.group_name IN (' . implode(', ', $placeholders) . ')))';
        }

        $statement = $this->pdo->prepare(
            'SELECT * FROM rooms WHERE ' . implode(' OR ', $clauses) . ' ORDER BY id DESC'
        );
        $statement->execute($parameters);

        $rooms = [];

        foreach ($statement->fetchAll() ?: [] as $row) {
            $rooms[] = Room::fromRow($row, $this->groupsOf((int) $row['id']));
        }

        return $rooms;
    }

    /**
     * Les deux mots de passe d'un salon.
     *
     * **La seule porte de sortie de ces valeurs**, et elle est délibérément
     * séparée de {@see Room} : un salon rendu dans une page ne PEUT pas porter
     * ses mots de passe, puisque l'objet qui le représente ne les a jamais eus.
     * Ils ne sont lus qu'au moment de fabriquer une URL de jonction signée.
     *
     * @return array{attendee: string, moderator: string}|null
     */
    public function roomSecrets(int $roomId): ?array
    {
        $statement = $this->pdo->prepare('SELECT attendee_pw, moderator_pw FROM rooms WHERE id = :id');
        $statement->execute(['id' => $roomId]);
        $row = $statement->fetch();

        if (! is_array($row)) {
            return null;
        }

        return ['attendee' => (string) $row['attendee_pw'], 'moderator' => (string) $row['moderator_pw']];
    }

    /**
     * Mémorise le serveur du dernier démarrage réussi.
     *
     * `last_started_at` est un INDICATIF D'AFFICHAGE, jamais une preuve : un
     * meeting BigBlueButton s'éteint tout seul (fin de durée, dernier
     * participant parti, redémarrage du serveur) sans prévenir personne. La
     * seule vérité du « en cours » est un appel, au moment où la question se
     * pose.
     */
    public function markStarted(int $roomId, int $serverId): void
    {
        $now = self::now();

        $this->pdo->prepare(
            'UPDATE rooms SET server_id = :server_id, last_started_at = :now, updated_at = :now WHERE id = :id'
        )->execute(['server_id' => $serverId, 'now' => $now, 'id' => $roomId]);
    }

    public function deleteRoom(int $roomId): void
    {
        // `room_groups` part avec, par `ON DELETE CASCADE` — les clés étrangères
        // sont activées à l'ouverture de la base, ce qui n'est PAS le défaut de
        // SQLite.
        $this->pdo->prepare('DELETE FROM rooms WHERE id = :id')->execute(['id' => $roomId]);
    }

    public function roomGroupCount(): int
    {
        $value = $this->pdo->query('SELECT COUNT(*) FROM room_groups')?->fetchColumn();

        return is_scalar($value) ? (int) $value : 0;
    }

    /** @return list<string> */
    private function groupsOf(int $roomId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT group_name FROM room_groups WHERE room_id = :id ORDER BY group_name'
        );
        $statement->execute(['id' => $roomId]);

        return array_map(static fn (array $row): string => (string) $row['group_name'], $statement->fetchAll() ?: []);
    }

    /** Aléa CRYPTOGRAPHIQUE — jamais `rand()`, jamais un compteur, jamais une date. */
    private static function newSecret(): string
    {
        return bin2hex(random_bytes(self::RANDOM_BYTES));
    }

    // =====================================================================
    // Anti-rejeu des `jti`
    // =====================================================================

    /**
     * Consomme un `jti` UNE fois. `true` = premier usage, `false` = rejeu détecté
     * OU impossibilité de trancher.
     *
     * **Fail-closed** : toute anomalie de stockage refuse. Un jeton d'entrée
     * humain ne s'accepte pas dans le doute.
     *
     * L'atomicité vient de la clé primaire : deux requêtes concurrentes portant
     * le même `jti` ne peuvent pas insérer toutes les deux. On n'écrit surtout
     * PAS `INSERT OR IGNORE` — il rendrait un succès silencieux sur le rejeu.
     *
     * C'est le SHA-256 du `jti` qui est stocké, jamais sa valeur : un `jti` est
     * un identifiant de jeton, il n'a rien à faire en clair dans un fichier de
     * sauvegarde.
     */
    public function consumeJti(string $jti, int $expiresAt, ?int $now = null): bool
    {
        $now ??= time();

        if ($jti === '' || $expiresAt <= $now) {
            return false;
        }

        try {
            // Purge opportuniste : le volume est marginal, la table reste propre
            // sans tâche planifiée — une extension n'a pas d'ordonnanceur.
            $this->pdo->prepare('DELETE FROM oidc_replay WHERE expires_at <= :now')->execute(['now' => $now]);

            $this->pdo->prepare('INSERT INTO oidc_replay (jti, expires_at) VALUES (:jti, :expires_at)')
                ->execute(['jti' => hash('sha256', $jti), 'expires_at' => $expiresAt]);

            return true;
        } catch (PDOException) {
            // Violation de clé primaire (rejeu) ou base indisponible : refus.
            return false;
        }
    }

    public function replayCount(): int
    {
        $value = $this->pdo->query('SELECT COUNT(*) FROM oidc_replay')?->fetchColumn();

        return is_scalar($value) ? (int) $value : 0;
    }

    // =====================================================================

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private static function hydrateServer(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'base_url' => (string) $row['base_url'],
            'secret' => (string) $row['secret'],
            'scalelite_threshold' => (int) $row['scalelite_threshold'],
            'enabled' => ((int) $row['enabled']) === 1,
            'created_at' => (string) $row['created_at'],
            'updated_at' => (string) $row['updated_at'],
        ];
    }

    private static function now(): string
    {
        return gmdate('Y-m-d\TH:i:s\Z');
    }
}

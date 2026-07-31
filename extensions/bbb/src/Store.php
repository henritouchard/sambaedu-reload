<?php

declare(strict_types=1);

namespace SambaEdu\ExtBbb;

use PDO;
use PDOException;

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
    public const SCHEMA_VERSION = 1;

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

<?php

declare(strict_types=1);

namespace SambaEdu\ExtBbb\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SambaEdu\ExtBbb\Oidc\IdTokenVerifier;
use SambaEdu\ExtBbb\Oidc\SqliteReplayGuard;
use SambaEdu\ExtBbb\Store;

/**
 * Story 57.1 — L'état de l'extension : migration idempotente, droits du fichier,
 * serveurs, anti-rejeu.
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

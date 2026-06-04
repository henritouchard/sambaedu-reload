<?php

namespace Tests\Feature\ControlHub;

use App\Models\ControlHubConnection;
use App\Repositories\ControlHubConnectionRepository;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Persistance du bloc idp_federated (clé publique SSO fédéré du controlHub)
 * reçu dans la réponse handshake, via le chemin réel :
 * ControlHubConnectionRepository::saveHandshakeConnection() → createOrUpdate().
 */
class HandshakeIdpFederatedPersistenceTest extends TestCase
{
    /** Vraie clé publique RSA 2048 (jetable, générée pour les tests) */
    private const PEM = "-----BEGIN PUBLIC KEY-----\nMIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAsdU1yPY6GRH7C6I4CvHG\n13pg6mlvDKHEfjFK/DgTxrOO9bDaCiZVfnHbHegUXmvG+C9pc4JqW4rz4s05nCBO\n0VzWwgNsx33Y7wgmdLqwjSjyfaEeUB+FR1gLN2bLvqbV/WJBZtCn2c67mUL3AhKk\nkY/u2+sbRgHQAztEkR9RcKFw7MTT6GVAhJn55ZHaLZsDhtkYjenuOa5cP8moXnsd\n8lDkgjrNOesM+/wEZ4GxeTvM3zy0iwkxjTwMx0p8Gszu+uaOltU6i+4YhDQN255c\nMfgA3ANcMmndSuq6l+Qr8e9jxau01s+jLfG9fJI9IZr6W65uWMuBn0TPaQkLmabt\nqwIDAQAB\n-----END PUBLIC KEY-----\n";

    private ControlHubConnectionRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createConnectionTable();
        $this->repository = new ControlHubConnectionRepository();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('controlhub_connection');
        parent::tearDown();
    }

    /**
     * Réplique du schéma controlhub_connection (schéma unifié + migration
     * 2026_06_04_130000_add_idp_federated_to_controlhub_connection).
     */
    private function createConnectionTable(): void
    {
        Schema::create('controlhub_connection', function (Blueprint $table) {
            $table->id();
            $table->string('base_url', 512);
            $table->text('api_token');
            $table->string('se4fs_api_token', 64);
            $table->integer('heartbeat_interval')->default(300);
            $table->boolean('heartbeat_enabled')->default(true);
            $table->integer('heartbeat_failures')->default(0);
            $table->string('status', 20)->default('unknown');
            $table->string('error_type', 100)->nullable();
            $table->timestamp('last_handshake_at')->nullable();
            $table->timestamp('last_heartbeat_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->boolean('is_active')->default(false);
            $table->text('idp_public_key')->nullable();
            $table->string('idp_kid', 100)->nullable();
            $table->string('idp_iss', 512)->nullable();
            $table->timestamps();
        });
    }

    private function saveConnection(?array $idpFederated): ControlHubConnection
    {
        return $this->repository->saveHandshakeConnection(
            baseUrl: 'https://central.exemple.fr',
            apiToken: 'tok_secret_123',
            se4fsApiToken: 'se4fs_instance_abcdef',
            heartbeatInterval: 120,
            expiresAt: null,
            idpFederated: $idpFederated
        );
    }

    #[Test]
    public function persiste_le_bloc_idp_federated_recu_au_handshake(): void
    {
        $connection = $this->saveConnection([
            'public_key' => self::PEM,
            'kid' => 'irundo-federated-key-1',
            'iss' => 'https://central.exemple.fr',
        ]);

        $fresh = $connection->fresh();
        $this->assertSame(self::PEM, $fresh->idp_public_key);
        $this->assertSame('irundo-federated-key-1', $fresh->idp_kid);
        $this->assertSame('https://central.exemple.fr', $fresh->idp_iss);
        $this->assertTrue($fresh->hasFederatedIdp());

        // Invariant : clé PUBLIQUE stockée EN CLAIR en DB (pas de mutateur Crypt,
        // contrairement à api_token) — la valeur brute doit être le PEM tel quel.
        $rawRow = DB::table('controlhub_connection')->where('id', $connection->id)->first();
        $this->assertSame(self::PEM, $rawRow->idp_public_key);
        $this->assertNotSame(self::PEM, $rawRow->api_token, 'api_token doit rester chiffré, lui');
    }

    #[Test]
    public function handshake_sans_bloc_idp_laisse_les_colonnes_null(): void
    {
        $connection = $this->saveConnection(null);

        $fresh = $connection->fresh();
        $this->assertNull($fresh->idp_public_key);
        $this->assertNull($fresh->idp_kid);
        $this->assertNull($fresh->idp_iss);
        $this->assertFalse($fresh->hasFederatedIdp());
    }

    #[Test]
    public function un_nouveau_handshake_sans_bloc_ne_garde_pas_la_cle_de_lancienne_connexion(): void
    {
        // createOrUpdate() désactive l'ancienne ligne et en crée une nouvelle :
        // la clé IDP suit ce cycle (pas d'héritage implicite entre connexions).
        $this->saveConnection([
            'public_key' => self::PEM,
            'kid' => 'irundo-federated-key-1',
            'iss' => 'https://central.exemple.fr',
        ]);

        $second = $this->saveConnection(null);

        $current = ControlHubConnection::current();
        $this->assertSame($second->id, $current->id);
        $this->assertFalse($current->hasFederatedIdp());
    }
}

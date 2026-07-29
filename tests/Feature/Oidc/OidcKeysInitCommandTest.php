<?php

declare(strict_types=1);

namespace Tests\Feature\Oidc;

use App\Auth\Oidc\Keys\OidcKeyManager;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 55.1 — **AC2** (prérequis) : `php artisan oidc:keys:init`.
 *
 * **Doctrine ops du projet** : une opération multi-instance est une commande
 * artisan IDEMPOTENTE, jamais une procédure manuelle à rejouer. `update.sh`
 * doit pouvoir la lancer à chaque déploiement de chaque instance sans jamais
 * détruire la clé en service — c'est précisément ce que ce fichier verrouille.
 *
 * ⚠️ Seul test de la story à générer une VRAIE paire RSA (les autres réutilisent
 * les fixtures commitées) : c'est son objet, et c'est ce qui coûte le plus cher
 * en temps d'exécution.
 */
class OidcKeysInitCommandTest extends TestCase
{
    private string $keyDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->keyDir = storage_path('framework/testing/oidc-keys-'.bin2hex(random_bytes(6)));

        config([
            'oidc.active_kid' => 'cmd-test-kid',
            'oidc.keys' => [
                'cmd-test-kid' => [
                    'private' => $this->keyDir.'/private.pem',
                    'public' => $this->keyDir.'/public.pem',
                ],
            ],
            // Clé courte : ce test vérifie l'idempotence et les permissions,
            // pas la robustesse cryptographique (couverte par la config prod).
            'oidc.key_bits' => 1024,
        ]);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->keyDir)) {
            foreach ((array) glob($this->keyDir.'/*') as $file) {
                @unlink((string) $file);
            }
            @rmdir($this->keyDir);
        }

        parent::tearDown();
    }

    #[Test]
    public function it_creates_the_keypair_when_absent(): void
    {
        self::assertFalse(app(OidcKeyManager::class)->isInitialized(), 'préalable : aucune clé');

        $this->artisan('oidc:keys:init')
            ->expectsOutputToContain('initialized')
            ->assertExitCode(0);

        self::assertFileExists($this->keyDir.'/private.pem');
        self::assertFileExists($this->keyDir.'/public.pem');

        $private = (string) file_get_contents($this->keyDir.'/private.pem');
        $public = (string) file_get_contents($this->keyDir.'/public.pem');

        self::assertStringContainsString('PRIVATE KEY', $private);
        self::assertStringContainsString('PUBLIC KEY', $public);
        self::assertStringNotContainsString('PRIVATE KEY', $public);

        // La clé privée n'est lisible QUE par son propriétaire.
        self::assertSame('0600', substr(sprintf('%o', fileperms($this->keyDir.'/private.pem')), -4));
        self::assertSame('0644', substr(sprintf('%o', fileperms($this->keyDir.'/public.pem')), -4));

        // La paire est cohérente : ce qui est signé par la privée se vérifie
        // par la publique.
        $signature = '';
        self::assertTrue(openssl_sign('charge utile', $signature, $private, OPENSSL_ALGO_SHA256));
        self::assertSame(1, openssl_verify('charge utile', $signature, $public, OPENSSL_ALGO_SHA256));
    }

    #[Test]
    public function re_running_it_is_a_signalled_no_op_and_never_destroys_the_key_in_use(): void
    {
        $this->artisan('oidc:keys:init')->assertExitCode(0);

        $before = (string) file_get_contents($this->keyDir.'/private.pem');
        $mtimeBefore = filemtime($this->keyDir.'/private.pem');

        $this->artisan('oidc:keys:init')
            ->expectsOutputToContain('already_initialized')
            ->assertExitCode(0);

        // ⚠️ L'invariant vital : `update.sh` rejoue cette commande à chaque
        // déploiement. Écraser la clé invaliderait silencieusement tous les
        // id_tokens en circulation et toutes les intégrations d'extensions.
        self::assertSame($before, (string) file_get_contents($this->keyDir.'/private.pem'));
        self::assertSame($mtimeBefore, filemtime($this->keyDir.'/private.pem'));
    }

    #[Test]
    public function force_regenerates_the_key_and_backs_up_the_previous_one(): void
    {
        $this->artisan('oidc:keys:init')->assertExitCode(0);
        $before = (string) file_get_contents($this->keyDir.'/private.pem');

        $this->artisan('oidc:keys:init --force --no-interaction')
            ->expectsOutputToContain('force_regenerated')
            ->assertExitCode(0);

        $after = (string) file_get_contents($this->keyDir.'/private.pem');

        self::assertNotSame($before, $after, 'la clé a bien été régénérée');
        self::assertNotSame([], glob($this->keyDir.'/private.pem.bak-*'), 'l\'ancienne clé est sauvegardée');
    }

    #[Test]
    public function force_aborts_without_touching_anything_when_the_operator_declines(): void
    {
        $this->artisan('oidc:keys:init')->assertExitCode(0);
        $before = (string) file_get_contents($this->keyDir.'/private.pem');

        $this->artisan('oidc:keys:init --force')
            ->expectsConfirmation(
                '⚠ Régénérer la clé de signature OIDC ? Les id_token déjà émis deviendront '
                .'invérifiables et les extensions devront rafraîchir le JWKS. Continuer ?',
                'no',
            )
            ->assertExitCode(0);

        self::assertSame($before, (string) file_get_contents($this->keyDir.'/private.pem'));
    }

    #[Test]
    public function the_generated_key_is_immediately_usable_by_the_jwks_endpoint(): void
    {
        // Preuve de bout en bout : la commande ne produit pas seulement des
        // fichiers, elle produit une clé que le fournisseur sait publier.
        $this->artisan('oidc:keys:init')->assertExitCode(0);

        $jwk = $this->get('/oidc/jwks')->assertOk()->json('keys.0');

        self::assertSame('cmd-test-kid', $jwk['kid']);
        self::assertSame('RSA', $jwk['kty']);
        self::assertNotEmpty($jwk['n']);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit\Extensions;

use App\Services\Extensions\CatalogSignatureVerifier;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Story 56.1 (AC1/AC4/AC6) — Vérificateur de signature Ed25519.
 *
 * Service PUR : test unitaire SANS base et sans framework (`PHPUnit\TestCase`,
 * pas `Tests\TestCase`). Les paires sont fabriquées à la volée par
 * `sodium_crypto_sign_keypair()` — aucune fixture binaire n'est commitée.
 *
 * Le contrat tenu ici est : **tout ce qui n'est pas une signature valide de ces
 * octets exacts par cette clé exacte rend `false`** — jamais une exception,
 * jamais un « presque bon ».
 */
class CatalogSignatureVerifierTest extends TestCase
{
    private CatalogSignatureVerifier $verifier;

    /** @var array{public: string, secret: string} */
    private array $keys;

    protected function setUp(): void
    {
        parent::setUp();

        $this->verifier = new CatalogSignatureVerifier();
        $this->keys = self::keypair();
    }

    /** @return array{public: string, secret: string} */
    private static function keypair(): array
    {
        $pair = sodium_crypto_sign_keypair();

        return [
            'public' => base64_encode(sodium_crypto_sign_publickey($pair)),
            'secret' => base64_encode(sodium_crypto_sign_secretkey($pair)),
        ];
    }

    private function sign(string $bytes, ?string $secretB64 = null): string
    {
        $secret = base64_decode($secretB64 ?? $this->keys['secret'], true);
        self::assertIsString($secret);

        return base64_encode(sodium_crypto_sign_detached($bytes, $secret));
    }

    // ── Chemin nominal ────────────────────────────────────────────────────

    #[Test]
    public function a_valid_signature_verifies(): void
    {
        $bytes = '{"index_version":1,"extensions":[]}';

        self::assertTrue($this->verifier->verify($bytes, $this->sign($bytes), $this->keys['public']));
    }

    #[Test]
    public function whitespace_around_the_base64_is_tolerated(): void
    {
        // Un dépôt statique publie presque toujours un `.sig` et un `.pub`
        // terminés par un retour à la ligne (tout éditeur en ajoute un).
        $bytes = 'catalogue';
        $signature = $this->sign($bytes);

        self::assertTrue($this->verifier->verify(
            $bytes,
            "\n  ".$signature."  \n",
            " \t".$this->keys['public']."\r\n",
        ));
    }

    #[Test]
    public function an_empty_payload_can_be_signed_and_verified(): void
    {
        // Le vérificateur ne fait AUCUNE hypothèse sur le contenu : c'est
        // l'appelant qui refuse un index vide, après vérification.
        self::assertTrue($this->verifier->verify('', $this->sign(''), $this->keys['public']));
    }

    // ── Altérations ───────────────────────────────────────────────────────

    #[Test]
    public function a_single_altered_byte_breaks_the_verification(): void
    {
        $bytes = '{"index_version":1,"extensions":[]}';
        $signature = $this->sign($bytes);

        $tampered = $bytes;
        $tampered[2] = 'X';

        self::assertFalse($this->verifier->verify($tampered, $signature, $this->keys['public']));
    }

    #[Test]
    public function a_signature_from_another_key_is_refused(): void
    {
        // Le scénario réel : le dépôt a changé de clé (rotation non annoncée,
        // ou compromission). La clé PINNÉE ne vérifie plus rien.
        $other = self::keypair();
        $bytes = 'catalogue';

        self::assertFalse($this->verifier->verify(
            $bytes,
            $this->sign($bytes, $other['secret']),
            $this->keys['public'],
        ));
    }

    #[Test]
    public function a_signature_of_another_payload_is_refused(): void
    {
        self::assertFalse($this->verifier->verify(
            'catalogue A',
            $this->sign('catalogue B'),
            $this->keys['public'],
        ));
    }

    // ── Entrées malformées : refus, jamais d'exception ────────────────────

    #[Test]
    public function invalid_base64_is_refused_and_never_throws(): void
    {
        $bytes = 'catalogue';

        // `base64_decode` STRICT : sans le mode strict, « !!!! » décoderait en
        // chaîne vide au lieu d'être refusé.
        self::assertFalse($this->verifier->verify($bytes, '!!!! not base64 !!!!', $this->keys['public']));
        self::assertFalse($this->verifier->verify($bytes, $this->sign($bytes), '@@@ pas une clé @@@'));
    }

    #[Test]
    public function an_empty_signature_or_key_is_refused(): void
    {
        $bytes = 'catalogue';

        self::assertFalse($this->verifier->verify($bytes, '', $this->keys['public']));
        self::assertFalse($this->verifier->verify($bytes, $this->sign($bytes), ''));
        self::assertFalse($this->verifier->verify($bytes, '', ''));
    }

    #[Test]
    public function wrong_lengths_are_refused_before_reaching_libsodium(): void
    {
        $bytes = 'catalogue';

        // 63 octets au lieu de 64, 31 au lieu de 32 : base64 valide, longueur
        // fausse. Les confier à libsodium lèverait une SodiumException.
        self::assertFalse($this->verifier->verify($bytes, base64_encode(str_repeat('A', 63)), $this->keys['public']));
        self::assertFalse($this->verifier->verify($bytes, base64_encode(str_repeat('A', 65)), $this->keys['public']));
        self::assertFalse($this->verifier->verify($bytes, $this->sign($bytes), base64_encode(str_repeat('A', 31))));
        self::assertFalse($this->verifier->verify($bytes, $this->sign($bytes), base64_encode(str_repeat('A', 33))));
    }

    #[Test]
    public function the_public_key_of_a_source_is_a_32_byte_base64_value(): void
    {
        self::assertTrue(CatalogSignatureVerifier::isValidPublicKey($this->keys['public']));
        self::assertTrue(CatalogSignatureVerifier::isValidPublicKey("  {$this->keys['public']}\n"));

        self::assertFalse(CatalogSignatureVerifier::isValidPublicKey(''));
        self::assertFalse(CatalogSignatureVerifier::isValidPublicKey('   '));
        self::assertFalse(CatalogSignatureVerifier::isValidPublicKey('pas-du-base64-!!'));
        self::assertFalse(CatalogSignatureVerifier::isValidPublicKey(base64_encode(str_repeat('A', 31))));
        // La clé SECRÈTE (64 octets) n'est pas une clé publique.
        self::assertFalse(CatalogSignatureVerifier::isValidPublicKey($this->keys['secret']));
    }

    #[Test]
    public function the_expected_lengths_match_libsodium_constants(): void
    {
        // Si une constante venait à diverger, tout serait refusé silencieusement.
        self::assertSame(SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES, CatalogSignatureVerifier::PUBLIC_KEY_BYTES);
        self::assertSame(SODIUM_CRYPTO_SIGN_BYTES, CatalogSignatureVerifier::SIGNATURE_BYTES);
    }
}

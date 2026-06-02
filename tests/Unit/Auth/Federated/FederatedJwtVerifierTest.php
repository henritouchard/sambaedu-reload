<?php

declare(strict_types=1);

namespace Tests\Unit\Auth\Federated;

use App\Auth\Federated\Jwt\Exceptions\InvalidFederatedJwtException;
use App\Auth\Federated\Jwt\FederatedJwtReplayChecker;
use App\Auth\Federated\Jwt\FederatedJwtVerifier;
use App\Auth\Federated\Support\FederatedJwtErrorCodes;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\IssuesFederatedJwt;
use Tests\TestCase;

/**
 * Story 20.1 — AC2-10, AC16. Tests de sécurité du `FederatedJwtVerifier`.
 *
 * Couvre H1/H2/M4 : RS256 pinné, rejet `alg:none` + confusion d'algo,
 * validation iss/aud/tier/exp/nbf, claims requis, anti-rejeu jti, leeway ±60s.
 */
class FederatedJwtVerifierTest extends TestCase
{
    use IssuesFederatedJwt;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureFederatedAuth();
        $this->ensureFederatedTables();
    }

    private function makeVerifier(): FederatedJwtVerifier
    {
        // Le verifier ne consomme plus le jti (anti-rejeu = controller, M1).
        return new FederatedJwtVerifier();
    }

    private function assertRejected(string $token, ?string $expectedCode = null): void
    {
        try {
            $this->makeVerifier()->verify($token);
            $this->fail('Expected InvalidFederatedJwtException');
        } catch (InvalidFederatedJwtException $e) {
            if ($expectedCode !== null) {
                $this->assertSame($expectedCode, $e->errorCode);
            }
            $this->assertTrue(true);
        }
    }

    #[Test]
    public function valid_rs256_jwt_returns_claims(): void
    {
        $emitted = $this->issueFederatedJwt(['sub' => 'ext-sub-1', 'role' => 'technicien']);

        $claims = $this->makeVerifier()->verify($emitted['token']);

        $this->assertSame('ext-sub-1', $claims->sub);
        $this->assertSame('technicien', $claims->role);
        $this->assertSame($this->federatedTestIss, $claims->iss);
        $this->assertSame($this->federatedTestAud, $claims->aud);
        $this->assertSame('federated-user', $claims->tier);
    }

    #[Test]
    public function alg_none_is_rejected(): void
    {
        $now = Carbon::now()->getTimestamp();
        $token = $this->signFederatedJwt([
            'iss' => $this->federatedTestIss,
            'aud' => $this->federatedTestAud,
            'sub' => 'x', 'jti' => 'j', 'kid' => $this->federatedTestKid,
            'tier' => 'federated-user', 'role' => 'technicien', 'login' => 'l',
            'iat' => $now, 'exp' => $now + 600,
        ], 'none', null, $this->federatedTestKid);

        $this->assertRejected($token, FederatedJwtErrorCodes::JWT_SIGNATURE_INVALID);
    }

    #[Test]
    public function hs256_algorithm_confusion_is_rejected(): void
    {
        // Attaque classique : signer en HS256 en utilisant la clé PUBLIQUE
        // (connue de l'attaquant) comme secret HMAC.
        $now = Carbon::now()->getTimestamp();
        $publicKey = (string) file_get_contents($this->federatedPublicKeyPath());

        $token = $this->signFederatedJwt([
            'iss' => $this->federatedTestIss,
            'aud' => $this->federatedTestAud,
            'sub' => 'x', 'jti' => 'j', 'kid' => $this->federatedTestKid,
            'tier' => 'federated-user', 'role' => 'technicien', 'login' => 'l',
            'iat' => $now, 'exp' => $now + 600,
        ], 'HS256', $publicKey, $this->federatedTestKid);

        $this->assertRejected($token, FederatedJwtErrorCodes::JWT_SIGNATURE_INVALID);
    }

    #[Test]
    public function expired_jwt_beyond_leeway_is_rejected(): void
    {
        $emitted = $this->issueFederatedJwt([
            'iat' => Carbon::now()->subMinutes(30)->getTimestamp(),
            'exp' => Carbon::now()->subMinutes(10)->getTimestamp(),
        ]);

        $this->assertRejected($emitted['token'], FederatedJwtErrorCodes::JWT_EXPIRED);
    }

    #[Test]
    public function nbf_in_future_beyond_leeway_is_rejected(): void
    {
        $emitted = $this->issueFederatedJwt([
            'nbf' => Carbon::now()->addMinutes(10)->getTimestamp(),
            'exp' => Carbon::now()->addMinutes(20)->getTimestamp(),
        ]);

        $this->assertRejected($emitted['token'], FederatedJwtErrorCodes::JWT_NOT_YET_VALID);
    }

    #[Test]
    public function wrong_audience_is_rejected(): void
    {
        $emitted = $this->issueFederatedJwt(['aud' => 'se5-some-other-college']);

        $this->assertRejected($emitted['token'], FederatedJwtErrorCodes::AUD_MISMATCH);
    }

    #[Test]
    public function unknown_issuer_is_rejected(): void
    {
        $emitted = $this->issueFederatedJwt(['iss' => 'rogue-idp']);

        $this->assertRejected($emitted['token'], FederatedJwtErrorCodes::ISS_MISMATCH);
    }

    #[Test]
    public function unknown_kid_is_rejected(): void
    {
        // kid absent de la key-map → la lib ne trouve pas la clé → rejet
        // signature (D9).
        $emitted = $this->issueFederatedJwt(['kid' => 'unknown-kid']);

        $this->assertRejected($emitted['token'], FederatedJwtErrorCodes::JWT_SIGNATURE_INVALID);
    }

    #[Test]
    public function wrong_tier_is_rejected(): void
    {
        $emitted = $this->issueFederatedJwt(['tier' => 'workstation']);

        $this->assertRejected($emitted['token'], FederatedJwtErrorCodes::WRONG_TIER);
    }

    #[Test]
    public function missing_required_claim_is_rejected(): void
    {
        // role manquant → missing_claim.
        $now = Carbon::now()->getTimestamp();
        $token = $this->signFederatedJwt([
            'iss' => $this->federatedTestIss,
            'aud' => $this->federatedTestAud,
            'sub' => 'x', 'jti' => 'j', 'kid' => $this->federatedTestKid,
            'tier' => 'federated-user', 'login' => 'l',
            'iat' => $now, 'exp' => $now + 600,
        ], 'RS256', null, $this->federatedTestKid);

        $this->assertRejected($token, FederatedJwtErrorCodes::MISSING_CLAIM);
    }

    #[Test]
    public function missing_exp_claim_is_rejected(): void
    {
        // exp absent → branche dédiée `exp === 0` (couverture AC8, #5).
        $now = Carbon::now()->getTimestamp();
        $token = $this->signFederatedJwt([
            'iss' => $this->federatedTestIss,
            'aud' => $this->federatedTestAud,
            'sub' => 'x', 'jti' => 'j-no-exp', 'kid' => $this->federatedTestKid,
            'tier' => 'federated-user', 'role' => 'technicien', 'login' => 'l',
            'iat' => $now,
        ], 'RS256', null, $this->federatedTestKid);

        $this->assertRejected($token, FederatedJwtErrorCodes::MISSING_CLAIM);
    }

    #[Test]
    public function missing_aud_claim_is_rejected_as_missing_not_mismatch(): void
    {
        // aud totalement absent → missing_claim (branche dédiée, #5).
        $now = Carbon::now()->getTimestamp();
        $token = $this->signFederatedJwt([
            'iss' => $this->federatedTestIss,
            'sub' => 'x', 'jti' => 'j-no-aud', 'kid' => $this->federatedTestKid,
            'tier' => 'federated-user', 'role' => 'technicien', 'login' => 'l',
            'iat' => $now, 'exp' => $now + 600,
        ], 'RS256', null, $this->federatedTestKid);

        $this->assertRejected($token, FederatedJwtErrorCodes::MISSING_CLAIM);
    }

    #[Test]
    public function aud_array_without_match_is_rejected_as_aud_mismatch(): void
    {
        // aud PRÉSENT (array) mais aucune valeur ne matche → aud_mismatch
        // (et NON missing_claim) : code d'erreur fidèle (#3).
        $emitted = $this->issueFederatedJwt([
            'aud' => ['se5-other-a', 'se5-other-b'],
        ]);

        $this->assertRejected($emitted['token'], FederatedJwtErrorCodes::AUD_MISMATCH);
    }

    #[Test]
    public function aud_array_with_match_is_accepted(): void
    {
        // aud array contenant l'audience attendue → accepté (RFC 7519).
        $emitted = $this->issueFederatedJwt([
            'aud' => ['se5-other-a', $this->federatedTestAud],
        ]);

        $claims = $this->makeVerifier()->verify($emitted['token']);
        $this->assertSame($this->federatedTestAud, $claims->aud);
    }

    #[Test]
    public function replay_checker_consumes_jti_once_then_rejects(): void
    {
        // M1 : la consommation jti est désormais portée par le replay-checker
        // (appelé par le controller après provisioning), plus par le verifier.
        // On teste donc directement le mécanisme d'usage unique.
        $checker = new FederatedJwtReplayChecker();
        $exp = Carbon::now()->addMinutes(10)->getTimestamp();

        $this->assertTrue($checker->consumeOnce('unique-jti-123', $exp), '1er usage doit réussir');
        $this->assertFalse($checker->consumeOnce('unique-jti-123', $exp), 'rejeu doit être refusé');
    }

    #[Test]
    public function verifier_does_not_consume_jti(): void
    {
        // Le même jeton vérifié deux fois reste valide côté verifier : la
        // consommation n'est plus de sa responsabilité (M1).
        $emitted = $this->issueFederatedJwt(['jti' => 'verify-twice-jti']);

        $first = $this->makeVerifier()->verify($emitted['token']);
        $second = $this->makeVerifier()->verify($emitted['token']);

        $this->assertSame('verify-twice-jti', $first->jti);
        $this->assertSame('verify-twice-jti', $second->jti);
    }

    #[Test]
    public function clock_skew_within_60s_is_accepted(): void
    {
        // exp dépassé de 30s mais dans le leeway ±60s → accepté.
        $emitted = $this->issueFederatedJwt([
            'iat' => Carbon::now()->subMinutes(10)->getTimestamp(),
            'exp' => Carbon::now()->subSeconds(30)->getTimestamp(),
        ]);

        $claims = $this->makeVerifier()->verify($emitted['token']);
        $this->assertSame($this->federatedTestIss, $claims->iss);
    }

    #[Test]
    public function garbage_string_is_rejected_as_malformed(): void
    {
        $this->assertRejected('not a jwt at all', FederatedJwtErrorCodes::JWT_MALFORMED);
    }

    #[Test]
    public function no_keys_configured_rejects_all(): void
    {
        config(['federated_auth.jwt.keys' => []]);
        $emitted = $this->issueFederatedJwt();

        $this->assertRejected($emitted['token'], FederatedJwtErrorCodes::JWT_SIGNATURE_INVALID);
    }
}

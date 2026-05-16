<?php

declare(strict_types=1);

namespace Tests\Unit\Auth\V1\Jwt;

use App\Auth\V1\Jwt\Exceptions\InvalidJwtException;
use App\Auth\V1\Jwt\WorkstationJwtRevocationChecker;
use App\Auth\V1\Jwt\WorkstationJwtVerifier;
use App\Auth\V1\Models\WorkstationJwtRevocation;
use App\Auth\V1\Support\JwtErrorCodes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\IssuesWorkstationJwt;
use Tests\TestCase;

/**
 * Story 16.10 — AC2.2 / AC7.1.
 *
 * Tests `WorkstationJwtVerifier` :
 *
 *  - happy path : JWT signé + tier valid + non revoqué = succès
 *  - 6 cas d'échec (D8) :
 *      missing (string vide), malformed (garbage), signature_invalid
 *      (signed avec autre clé), expired (exp < now), revoked (jti dans DB),
 *      wrong_tier
 *  - kid inconnu → rejet `jwt.signature_invalid` (D9)
 */
class WorkstationJwtVerifierTest extends TestCase
{
    use IssuesWorkstationJwt;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureTestKeyPair();
        $this->ensureAuthV1Tables();
    }

    private function makeVerifier(): WorkstationJwtVerifier
    {
        return new WorkstationJwtVerifier(new WorkstationJwtRevocationChecker());
    }

    #[Test]
    public function happy_path_returns_claims_dto(): void
    {
        $emitted = $this->issueTestJwt(['sub' => '11111111-1111-1111-1111-111111111111']);

        $claims = $this->makeVerifier()->verify($emitted['token']);

        $this->assertSame('11111111-1111-1111-1111-111111111111', $claims->sub);
        $this->assertSame('workstation', $claims->tier);
        $this->assertSame($emitted['jti'], $claims->jti);
    }

    #[Test]
    public function malformed_jwt_throws(): void
    {
        try {
            $this->makeVerifier()->verify('not-a-jwt');
            $this->fail('Expected InvalidJwtException');
        } catch (InvalidJwtException $e) {
            // Soit malformed (notre validation regex), soit signature_invalid si la lib
            // l'accepte ; on autorise les deux.
            $this->assertContains($e->errorCode, [
                JwtErrorCodes::JWT_MALFORMED,
                JwtErrorCodes::JWT_SIGNATURE_INVALID,
            ]);
        }
    }

    #[Test]
    public function expired_jwt_throws_with_expired_code(): void
    {
        $emitted = $this->issueTestJwt([
            'iat' => Carbon::now()->subDays(2)->getTimestamp(),
            'exp' => Carbon::now()->subDays(1)->getTimestamp(),
        ]);

        try {
            $this->makeVerifier()->verify($emitted['token']);
            $this->fail('Expected InvalidJwtException expired');
        } catch (InvalidJwtException $e) {
            $this->assertSame(JwtErrorCodes::JWT_EXPIRED, $e->errorCode);
        }
    }

    #[Test]
    public function wrong_tier_throws(): void
    {
        $emitted = $this->issueTestJwt(['tier' => 'controlhub']);

        try {
            $this->makeVerifier()->verify($emitted['token']);
            $this->fail('Expected InvalidJwtException wrong_tier');
        } catch (InvalidJwtException $e) {
            $this->assertSame(JwtErrorCodes::JWT_WRONG_TIER, $e->errorCode);
        }
    }

    #[Test]
    public function expected_tier_override_accepts_alternate_tier(): void
    {
        $emitted = $this->issueTestJwt(['tier' => 'agent']);
        $claims = $this->makeVerifier()->verify($emitted['token'], ['expected_tier' => 'agent']);
        $this->assertSame('agent', $claims->tier);
    }

    #[Test]
    public function revoked_jti_throws(): void
    {
        $jti = (string) Str::uuid();
        $emitted = $this->issueTestJwt(['jti' => $jti]);

        // Insert revocation
        WorkstationJwtRevocation::query()->create([
            'id' => (string) Str::uuid(),
            'jti' => $jti,
            'workstation_uuid' => $emitted['sub'],
            'revoked_at' => Carbon::now(),
            'reason' => 'manual_admin',
            'revoked_by' => 'admin:test',
            'expires_at' => Carbon::now()->addDay(),
        ]);

        try {
            $this->makeVerifier()->verify($emitted['token']);
            $this->fail('Expected InvalidJwtException revoked');
        } catch (InvalidJwtException $e) {
            $this->assertSame(JwtErrorCodes::JWT_REVOKED, $e->errorCode);
        }
    }

    #[Test]
    public function unknown_kid_throws_signature_invalid(): void
    {
        $emitted = $this->issueTestJwt(['kid' => 'rogue-kid']);

        try {
            $this->makeVerifier()->verify($emitted['token']);
            $this->fail('Expected InvalidJwtException for unknown kid');
        } catch (InvalidJwtException $e) {
            $this->assertSame(JwtErrorCodes::JWT_SIGNATURE_INVALID, $e->errorCode);
        }
    }

    #[Test]
    public function jwt_signed_with_different_key_throws_signature_invalid(): void
    {
        // Génère une autre paire et signe avec elle, mais en mettant le bon kid
        // pour passer la map (= la lib essaiera de vérifier avec la pub officielle
        // → signature_invalid).
        $other = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $this->assertNotFalse($other);
        $otherPriv = '';
        openssl_pkey_export($other, $otherPriv);

        $payload = [
            'iss' => 'rogue',
            'sub' => (string) Str::uuid(),
            'iat' => Carbon::now()->getTimestamp(),
            'exp' => Carbon::now()->addDay()->getTimestamp(),
            'jti' => (string) Str::uuid(),
            'tier' => 'workstation',
            'kid' => $this->authV1TestKid,
        ];
        $forged = \Firebase\JWT\JWT::encode($payload, $otherPriv, 'RS256', $this->authV1TestKid);

        try {
            $this->makeVerifier()->verify($forged);
            $this->fail('Expected signature_invalid');
        } catch (InvalidJwtException $e) {
            $this->assertSame(JwtErrorCodes::JWT_SIGNATURE_INVALID, $e->errorCode);
        }
    }

    #[Test]
    public function empty_jwt_throws_malformed(): void
    {
        try {
            $this->makeVerifier()->verify('');
            $this->fail('Expected InvalidJwtException malformed');
        } catch (InvalidJwtException $e) {
            $this->assertSame(JwtErrorCodes::JWT_MALFORMED, $e->errorCode);
        }
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit\Auth\V1\Jwt;

use App\Auth\V1\Jwt\WorkstationJwtIssuer;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\IssuesWorkstationJwt;
use Tests\TestCase;

/**
 * Story 16.10 — AC2.1 / AC7.1.
 *
 * Tests unit `WorkstationJwtIssuer` :
 *
 *  - émission claim complet (iss, sub, iat, exp, jti, tier, kid)
 *  - format header (`kid` présent)
 *  - TTL access = 86400s (24h, default config)
 *  - tier = 'workstation'
 *  - jti unique entre deux émissions
 *  - refresh token = 64 hex chars + entropie CSPRNG via random_bytes
 *  - hash sha256 retourné distinct du clear
 */
class WorkstationJwtIssuerTest extends TestCase
{
    use IssuesWorkstationJwt;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureTestKeyPair();
    }

    private function decodeWithTestKey(string $jwt): array
    {
        $pubPath = (string) config('auth_v1.jwt.keys.' . $this->authV1TestKid . '.public', '');
        $pub = (string) file_get_contents($pubPath);
        $keyMap = [
            $this->authV1TestKid => new Key($pub, 'RS256'),
        ];

        return (array) JWT::decode($jwt, $keyMap);
    }

    #[Test]
    public function issue_access_token_emits_full_claim_set(): void
    {
        $issuer = new WorkstationJwtIssuer();
        $sub = '11111111-1111-1111-1111-111111111111';

        $result = $issuer->issueAccessToken($sub);

        $this->assertArrayHasKey('token', $result);
        $this->assertArrayHasKey('jti', $result);
        $this->assertArrayHasKey('kid', $result);
        $this->assertArrayHasKey('expires_at', $result);

        $decoded = $this->decodeWithTestKey($result['token']);

        $this->assertSame('sambaedu-test', (string) $decoded['iss']);
        $this->assertSame($sub, (string) $decoded['sub']);
        $this->assertSame('workstation', (string) $decoded['tier']);
        $this->assertSame($this->authV1TestKid, (string) $decoded['kid']);
        $this->assertSame($result['jti'], (string) $decoded['jti']);
        $this->assertIsInt($decoded['iat']);
        $this->assertIsInt($decoded['exp']);
    }

    #[Test]
    public function access_token_ttl_is_24_hours(): void
    {
        $issuer = new WorkstationJwtIssuer();
        $result = $issuer->issueAccessToken('22222222-2222-2222-2222-222222222222');
        $decoded = $this->decodeWithTestKey($result['token']);

        $delta = (int) $decoded['exp'] - (int) $decoded['iat'];
        $this->assertSame(86400, $delta);
    }

    #[Test]
    public function tier_claim_is_workstation(): void
    {
        $issuer = new WorkstationJwtIssuer();
        $decoded = $this->decodeWithTestKey(
            $issuer->issueAccessToken('33333333-3333-3333-3333-333333333333')['token']
        );

        $this->assertSame('workstation', (string) $decoded['tier']);
    }

    #[Test]
    public function jti_is_unique_between_two_emissions(): void
    {
        $issuer = new WorkstationJwtIssuer();
        $a = $issuer->issueAccessToken('11111111-1111-1111-1111-111111111111');
        $b = $issuer->issueAccessToken('11111111-1111-1111-1111-111111111111');

        $this->assertNotSame($a['jti'], $b['jti']);
        $this->assertNotSame($a['token'], $b['token']);
    }

    #[Test]
    public function issue_refresh_token_returns_64_hex_clear_and_sha256_hash(): void
    {
        $issuer = new WorkstationJwtIssuer();
        $sub = '44444444-4444-4444-4444-444444444444';

        $refresh = $issuer->issueRefreshToken($sub);

        $this->assertArrayHasKey('clear', $refresh);
        $this->assertArrayHasKey('hash', $refresh);
        $this->assertArrayHasKey('expires_at', $refresh);
        $this->assertArrayHasKey('issued_at', $refresh);

        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/i', $refresh['clear']);
        $this->assertSame(hash('sha256', $refresh['clear']), $refresh['hash']);
        $this->assertNotSame($refresh['clear'], $refresh['hash']);
    }

    #[Test]
    public function refresh_token_clear_is_unique_between_two_emissions(): void
    {
        $issuer = new WorkstationJwtIssuer();
        $a = $issuer->issueRefreshToken('44444444-4444-4444-4444-444444444444');
        $b = $issuer->issueRefreshToken('44444444-4444-4444-4444-444444444444');

        $this->assertNotSame($a['clear'], $b['clear']);
        $this->assertNotSame($a['hash'], $b['hash']);
    }

    #[Test]
    public function jwt_header_contains_kid(): void
    {
        $issuer = new WorkstationJwtIssuer();
        $result = $issuer->issueAccessToken('55555555-5555-5555-5555-555555555555');

        $parts = explode('.', $result['token']);
        $this->assertCount(3, $parts);
        $header = json_decode(base64_decode(strtr($parts[0], '-_', '+/'), true) ?: '', true);
        $this->assertIsArray($header);
        $this->assertSame($this->authV1TestKid, $header['kid']);
        $this->assertSame('RS256', $header['alg']);
    }
}

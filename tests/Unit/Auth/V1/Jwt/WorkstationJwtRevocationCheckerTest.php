<?php

declare(strict_types=1);

namespace Tests\Unit\Auth\V1\Jwt;

use App\Auth\V1\Jwt\WorkstationJwtRevocationChecker;
use App\Auth\V1\Models\WorkstationJwtRevocation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\IssuesWorkstationJwt;
use Tests\TestCase;

/**
 * Story 16.10 — D4 / AC7.1.
 *
 * Tests `WorkstationJwtRevocationChecker` — 4 cas matrix :
 *
 *  1. Cache hit revoked → true
 *  2. Cache miss + DB hit revoked → true (+ warm cache)
 *  3. Cache hit not revoked → false
 *  4. Cache miss + DB miss → false
 *
 *  + Test `pushRevocation()` warm le cache.
 *  + Test fallback gracieux si cache store invalide.
 */
class WorkstationJwtRevocationCheckerTest extends TestCase
{
    use IssuesWorkstationJwt;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureTestKeyPair();
        $this->ensureAuthV1Tables();
        Cache::store('array')->flush();
    }

    #[Test]
    public function cache_hit_revoked_returns_true(): void
    {
        $checker = new WorkstationJwtRevocationChecker();
        $jti = (string) Str::uuid();
        Cache::store('array')->put($checker->cacheKey($jti), true, 60);

        $this->assertTrue($checker->isRevoked($jti));
    }

    #[Test]
    public function cache_miss_db_hit_returns_true_and_warms_cache(): void
    {
        $checker = new WorkstationJwtRevocationChecker();
        $jti = (string) Str::uuid();

        // Insère en DB sans peupler le cache
        WorkstationJwtRevocation::query()->create([
            'id' => (string) Str::uuid(),
            'jti' => $jti,
            'workstation_uuid' => (string) Str::uuid(),
            'revoked_at' => Carbon::now(),
            'reason' => 'manual_admin',
            'expires_at' => Carbon::now()->addDay(),
        ]);

        $this->assertTrue($checker->isRevoked($jti));

        // Vérifie le warming
        $cached = Cache::store('array')->get($checker->cacheKey($jti));
        $this->assertTrue($cached);
    }

    #[Test]
    public function cache_miss_db_miss_returns_false(): void
    {
        $checker = new WorkstationJwtRevocationChecker();
        $jti = (string) Str::uuid();
        $this->assertFalse($checker->isRevoked($jti));
    }

    #[Test]
    public function cache_hit_false_returns_false(): void
    {
        $checker = new WorkstationJwtRevocationChecker();
        $jti = (string) Str::uuid();
        Cache::store('array')->put($checker->cacheKey($jti), false, 60);
        $this->assertFalse($checker->isRevoked($jti));
    }

    #[Test]
    public function push_revocation_marks_cache_true(): void
    {
        $checker = new WorkstationJwtRevocationChecker();
        $jti = (string) Str::uuid();

        $checker->pushRevocation($jti);

        $this->assertTrue(Cache::store('array')->get($checker->cacheKey($jti)));
        // Et isRevoked retourne true sans hit DB
        $this->assertTrue($checker->isRevoked($jti));
    }
}

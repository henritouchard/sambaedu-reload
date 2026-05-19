<?php

declare(strict_types=1);

namespace Tests\Unit\Ipxe\Support;

use App\Ipxe\Support\UuidNormalizer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Story 3.1 — AC2.2 / T2.3.
 *
 * Tests unitaires du normalizer UUID. ≥4 cas. Tolérance volontaire : pas
 * de validation regex stricte UUID v4 (le legacy reconstruit des UUIDs
 * composites — cf. `boot.php:36-41`).
 */
class UuidNormalizerTest extends TestCase
{
    #[Test]
    public function it_normalises_lowercase_uuid(): void
    {
        $uuid = '12345678-1234-1234-1234-123456789abc';
        self::assertSame($uuid, UuidNormalizer::normalize($uuid));
    }

    #[Test]
    public function it_lowercases_uppercase_uuid(): void
    {
        self::assertSame(
            '12345678-1234-1234-1234-123456789abc',
            UuidNormalizer::normalize('12345678-1234-1234-1234-123456789ABC'),
        );
    }

    #[Test]
    public function it_trims_whitespace(): void
    {
        self::assertSame(
            'abcdef01-2345-6789-abcd-ef0123456789',
            UuidNormalizer::normalize("  abcdef01-2345-6789-abcd-ef0123456789  \n"),
        );
    }

    #[Test]
    public function it_returns_null_for_empty(): void
    {
        self::assertNull(UuidNormalizer::normalize(''));
        self::assertNull(UuidNormalizer::normalize('   '));
        self::assertNull(UuidNormalizer::normalize("\n\t"));
    }

    #[Test]
    public function it_accepts_malformed_uuid_iso_legacy(): void
    {
        // Le legacy `boot.php:36-41` génère des UUIDs composites non-v4 :
        // `xxxxxxxx-xxxx-xxxx-xxxx-<dechex(macHex)>`. On doit les accepter.
        self::assertSame(
            'abcd-efgh-1234-5678-deadbeef',
            UuidNormalizer::normalize('ABCD-EFGH-1234-5678-DEADBEEF'),
        );
    }
}

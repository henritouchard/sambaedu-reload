<?php

declare(strict_types=1);

namespace Tests\Unit\Ipxe\Support;

use App\Ipxe\Support\MacAddressNormalizer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Story 3.1 — AC2.2 / T2.3.
 *
 * Tests unitaires du normalizer MAC. ≥6 cas (variantes valides + invalides).
 */
class MacAddressNormalizerTest extends TestCase
{
    #[Test]
    public function it_normalises_canonical_lowercase_format(): void
    {
        self::assertSame(
            'aa:bb:cc:dd:ee:ff',
            MacAddressNormalizer::normalize('aa:bb:cc:dd:ee:ff'),
        );
    }

    #[Test]
    public function it_normalises_uppercase_with_colons(): void
    {
        self::assertSame(
            'aa:bb:cc:dd:ee:ff',
            MacAddressNormalizer::normalize('AA:BB:CC:DD:EE:FF'),
        );
    }

    #[Test]
    public function it_normalises_dash_separator(): void
    {
        self::assertSame(
            '12:34:56:78:9a:bc',
            MacAddressNormalizer::normalize('12-34-56-78-9A-BC'),
        );
    }

    #[Test]
    public function it_normalises_no_separator(): void
    {
        self::assertSame(
            'aa:bb:cc:dd:ee:ff',
            MacAddressNormalizer::normalize('aabbccddeeff'),
        );
    }

    #[Test]
    public function it_normalises_mixed_case_and_separators(): void
    {
        // Variantes que iPXE peut poser selon le firmware (`${net0/mac}` peut
        // varier sur certains BIOS).
        self::assertSame(
            'aa:bb:cc:dd:ee:ff',
            MacAddressNormalizer::normalize('Aa:Bb-Cc:Dd-Ee:Ff'),
        );
    }

    #[Test]
    public function it_returns_null_for_empty_string(): void
    {
        self::assertNull(MacAddressNormalizer::normalize(''));
        self::assertNull(MacAddressNormalizer::normalize('   '));
    }

    #[Test]
    public function it_returns_null_for_non_hex_characters(): void
    {
        self::assertNull(MacAddressNormalizer::normalize('zz:bb:cc:dd:ee:ff'));
        self::assertNull(MacAddressNormalizer::normalize('not-a-mac'));
    }

    #[Test]
    public function it_returns_null_for_wrong_length(): void
    {
        // 10 hex chars (trop court)
        self::assertNull(MacAddressNormalizer::normalize('aabbccddee'));
        // 14 hex chars (trop long)
        self::assertNull(MacAddressNormalizer::normalize('aabbccddeeff00'));
    }
}

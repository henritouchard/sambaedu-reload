<?php

declare(strict_types=1);

namespace Tests\Unit\Ipxe\Enums;

use App\Ipxe\Enums\LinuxDistribution;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 3.4 — AC1.2 / T1.2.
 *
 * Tests unitaires de la whitelist enum {@see LinuxDistribution}.
 */
class LinuxDistributionTest extends TestCase
{
    #[Test]
    public function it_has_exactly_three_cases(): void
    {
        $cases = LinuxDistribution::cases();
        self::assertCount(3, $cases);
    }

    #[Test]
    public function it_resolves_canonical_distribution_strings(): void
    {
        self::assertSame(LinuxDistribution::Debian, LinuxDistribution::fromString('debian'));
        self::assertSame(LinuxDistribution::Ubuntu, LinuxDistribution::fromString('ubuntu'));
        self::assertSame(LinuxDistribution::Nird, LinuxDistribution::fromString('nird'));
    }

    #[Test]
    public function it_resolves_debian_version_aliases(): void
    {
        self::assertSame(LinuxDistribution::Debian, LinuxDistribution::fromString('trixie'));
        self::assertSame(LinuxDistribution::Debian, LinuxDistribution::fromString('bookworm'));
        self::assertSame(LinuxDistribution::Debian, LinuxDistribution::fromString('bullseye'));
    }

    #[Test]
    public function it_resolves_ubuntu_version_aliases(): void
    {
        self::assertSame(LinuxDistribution::Ubuntu, LinuxDistribution::fromString('focal'));
        self::assertSame(LinuxDistribution::Ubuntu, LinuxDistribution::fromString('jammy'));
        self::assertSame(LinuxDistribution::Ubuntu, LinuxDistribution::fromString('noble'));
    }

    #[Test]
    public function it_is_case_insensitive(): void
    {
        self::assertSame(LinuxDistribution::Debian, LinuxDistribution::fromString('DEBIAN'));
        self::assertSame(LinuxDistribution::Ubuntu, LinuxDistribution::fromString('Ubuntu'));
        self::assertSame(LinuxDistribution::Nird, LinuxDistribution::fromString('NiRd'));
    }

    #[Test]
    public function it_rejects_invalid_distribution(): void
    {
        self::assertNull(LinuxDistribution::fromString(''));
        self::assertNull(LinuxDistribution::fromString('macos'));
        self::assertNull(LinuxDistribution::fromString('windows'));
    }

    #[Test]
    public function it_rejects_injection_attempts(): void
    {
        self::assertNull(LinuxDistribution::fromString('../etc/passwd'));
        self::assertNull(LinuxDistribution::fromString("debian\nubuntu"));
        self::assertNull(LinuxDistribution::fromString('debian; rm -rf /'));
        self::assertNull(LinuxDistribution::fromString('debian`whoami`'));
    }
}

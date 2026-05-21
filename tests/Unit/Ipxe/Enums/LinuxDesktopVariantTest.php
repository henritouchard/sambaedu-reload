<?php

declare(strict_types=1);

namespace Tests\Unit\Ipxe\Enums;

use App\Ipxe\Enums\LinuxDesktopVariant;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 3.4 — AC1.2 / T1.2.
 *
 * Tests unitaires de la whitelist enum {@see LinuxDesktopVariant}.
 */
class LinuxDesktopVariantTest extends TestCase
{
    #[Test]
    public function it_has_exactly_seven_cases(): void
    {
        $cases = LinuxDesktopVariant::cases();
        self::assertCount(7, $cases);
    }

    #[Test]
    public function it_resolves_all_canonical_variants(): void
    {
        self::assertSame(LinuxDesktopVariant::Base, LinuxDesktopVariant::fromString('base'));
        self::assertSame(LinuxDesktopVariant::Gnome, LinuxDesktopVariant::fromString('gnome'));
        self::assertSame(LinuxDesktopVariant::Lxde, LinuxDesktopVariant::fromString('lxde'));
        self::assertSame(LinuxDesktopVariant::Kde, LinuxDesktopVariant::fromString('kde'));
        self::assertSame(LinuxDesktopVariant::Mate, LinuxDesktopVariant::fromString('mate'));
        self::assertSame(LinuxDesktopVariant::Xfce, LinuxDesktopVariant::fromString('xfce'));
        self::assertSame(LinuxDesktopVariant::Cinnamon, LinuxDesktopVariant::fromString('cinnamon'));
    }

    #[Test]
    public function it_is_case_insensitive(): void
    {
        self::assertSame(LinuxDesktopVariant::Gnome, LinuxDesktopVariant::fromString('GNOME'));
        self::assertSame(LinuxDesktopVariant::Cinnamon, LinuxDesktopVariant::fromString('Cinnamon'));
    }

    #[Test]
    public function it_rejects_invalid_variant(): void
    {
        self::assertNull(LinuxDesktopVariant::fromString(''));
        self::assertNull(LinuxDesktopVariant::fromString('unity'));
        self::assertNull(LinuxDesktopVariant::fromString('budgie'));
    }

    #[Test]
    public function it_rejects_injection_attempts(): void
    {
        self::assertNull(LinuxDesktopVariant::fromString('../etc/passwd'));
        self::assertNull(LinuxDesktopVariant::fromString("gnome\nlxde"));
        self::assertNull(LinuxDesktopVariant::fromString('gnome; rm -rf /'));
        self::assertNull(LinuxDesktopVariant::fromString('gnome`whoami`'));
    }
}

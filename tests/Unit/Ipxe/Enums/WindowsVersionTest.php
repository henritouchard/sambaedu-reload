<?php

declare(strict_types=1);

namespace Tests\Unit\Ipxe\Enums;

use App\Ipxe\Enums\WindowsVersion;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 3.5 — AC1.2 / T1.3.
 *
 * Tests unitaires de la whitelist enum {@see WindowsVersion} (D1 — sécurité
 * critique : empêche l'injection de versions arbitraires dans cmdline).
 */
class WindowsVersionTest extends TestCase
{
    #[Test]
    public function it_lists_exactly_two_cases(): void
    {
        $cases = WindowsVersion::cases();
        self::assertCount(2, $cases);
    }

    #[Test]
    public function it_resolves_win10_and_win11(): void
    {
        self::assertSame(WindowsVersion::Win10, WindowsVersion::fromString('Win10'));
        self::assertSame(WindowsVersion::Win11, WindowsVersion::fromString('Win11'));
    }

    #[Test]
    public function it_returns_null_for_invalid_versions(): void
    {
        self::assertNull(WindowsVersion::fromString(''));
        self::assertNull(WindowsVersion::fromString('win10'));     // case-sensitive (parité legacy)
        self::assertNull(WindowsVersion::fromString('WIN10'));
        self::assertNull(WindowsVersion::fromString('Win7'));
        self::assertNull(WindowsVersion::fromString('Win11-old'));
        self::assertNull(WindowsVersion::fromString('Win12'));
    }

    #[Test]
    public function it_rejects_injection_attempts(): void
    {
        // Path traversal.
        self::assertNull(WindowsVersion::fromString('../../../etc/passwd'));
        // Newline injection (iPXE cmdline).
        self::assertNull(WindowsVersion::fromString("Win11\nkernel http://evil"));
        // Null byte.
        self::assertNull(WindowsVersion::fromString("Win11\x00"));
        // Shell command.
        self::assertNull(WindowsVersion::fromString('Win11; rm -rf /'));
    }

    #[Test]
    public function it_trims_whitespace_before_resolution(): void
    {
        self::assertSame(WindowsVersion::Win10, WindowsVersion::fromString('  Win10  '));
        self::assertSame(WindowsVersion::Win11, WindowsVersion::fromString("\tWin11\n"));
    }

    #[Test]
    public function it_exposes_value_property(): void
    {
        self::assertSame('Win10', WindowsVersion::Win10->value);
        self::assertSame('Win11', WindowsVersion::Win11->value);
    }
}

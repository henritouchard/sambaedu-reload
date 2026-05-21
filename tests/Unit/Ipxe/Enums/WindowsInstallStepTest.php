<?php

declare(strict_types=1);

namespace Tests\Unit\Ipxe\Enums;

use App\Ipxe\Enums\WindowsInstallStep;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 3.5 — AC1.2 / T1.3.
 *
 * Tests unitaires de la whitelist enum {@see WindowsInstallStep}.
 */
class WindowsInstallStepTest extends TestCase
{
    #[Test]
    public function it_lists_exactly_two_cases(): void
    {
        self::assertCount(2, WindowsInstallStep::cases());
    }

    #[Test]
    public function it_resolves_winpe_and_oobe(): void
    {
        self::assertSame(WindowsInstallStep::Winpe, WindowsInstallStep::fromString('winpe'));
        self::assertSame(WindowsInstallStep::Oobe, WindowsInstallStep::fromString('oobe'));
    }

    #[Test]
    public function it_resolves_case_insensitive(): void
    {
        self::assertSame(WindowsInstallStep::Winpe, WindowsInstallStep::fromString('WINPE'));
        self::assertSame(WindowsInstallStep::Oobe, WindowsInstallStep::fromString('Oobe'));
    }

    #[Test]
    public function it_returns_null_for_unsupported_steps(): void
    {
        // Étapes déférées 3.7 — doivent retourner null pour permettre le
        // log warning `ipxe.windows.action.unsupported_step`.
        self::assertNull(WindowsInstallStep::fromString('sysprep'));
        self::assertNull(WindowsInstallStep::fromString('nosysprep'));
        self::assertNull(WindowsInstallStep::fromString('join'));
        self::assertNull(WindowsInstallStep::fromString('renomme'));
        self::assertNull(WindowsInstallStep::fromString('post'));
        self::assertNull(WindowsInstallStep::fromString('wpkg'));
    }

    #[Test]
    public function it_returns_null_for_empty_or_garbage(): void
    {
        self::assertNull(WindowsInstallStep::fromString(''));
        self::assertNull(WindowsInstallStep::fromString('  '));
        self::assertNull(WindowsInstallStep::fromString("winpe\nkernel http://evil"));
        self::assertNull(WindowsInstallStep::fromString('../etc'));
        self::assertNull(WindowsInstallStep::fromString("\x00winpe"));
    }
}

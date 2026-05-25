<?php

declare(strict_types=1);

namespace Tests\Unit\Ipxe\Enums;

use App\Ipxe\Enums\WindowsInstallStep;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 3.5 — AC1.2 / T1.3.
 * Story 3.8 — AC1.1-1.4 / T2.5 — étendu à 8 cases (port complet post-OOBE).
 *
 * Tests unitaires de la whitelist enum {@see WindowsInstallStep}.
 */
class WindowsInstallStepTest extends TestCase
{
    /**
     * Story 3.8 — AC1.1 — 8 cases au total (Winpe + Oobe + 6 nouveaux).
     */
    #[Test]
    public function it_lists_exactly_eight_cases(): void
    {
        self::assertCount(8, WindowsInstallStep::cases());
    }

    #[Test]
    public function it_resolves_winpe_and_oobe(): void
    {
        self::assertSame(WindowsInstallStep::Winpe, WindowsInstallStep::fromString('winpe'));
        self::assertSame(WindowsInstallStep::Oobe, WindowsInstallStep::fromString('oobe'));
    }

    /**
     * Story 3.8 — AC1.3 — fromString accepte les 6 nouveaux cases.
     */
    #[Test]
    public function it_resolves_six_new_post_oobe_steps(): void
    {
        self::assertSame(WindowsInstallStep::Sysprep, WindowsInstallStep::fromString('sysprep'));
        self::assertSame(WindowsInstallStep::Nosysprep, WindowsInstallStep::fromString('nosysprep'));
        self::assertSame(WindowsInstallStep::Join, WindowsInstallStep::fromString('join'));
        self::assertSame(WindowsInstallStep::Renomme, WindowsInstallStep::fromString('renomme'));
        self::assertSame(WindowsInstallStep::Post, WindowsInstallStep::fromString('post'));
        self::assertSame(WindowsInstallStep::Wpkg, WindowsInstallStep::fromString('wpkg'));
    }

    #[Test]
    public function it_resolves_case_insensitive(): void
    {
        self::assertSame(WindowsInstallStep::Winpe, WindowsInstallStep::fromString('WINPE'));
        self::assertSame(WindowsInstallStep::Oobe, WindowsInstallStep::fromString('Oobe'));
        // Story 3.8 — casse mixte sur les 6 nouveaux cases.
        self::assertSame(WindowsInstallStep::Sysprep, WindowsInstallStep::fromString('SysPrep'));
        self::assertSame(WindowsInstallStep::Nosysprep, WindowsInstallStep::fromString('NOSYSPREP'));
        self::assertSame(WindowsInstallStep::Join, WindowsInstallStep::fromString('Join'));
        self::assertSame(WindowsInstallStep::Renomme, WindowsInstallStep::fromString('RENOMME'));
        self::assertSame(WindowsInstallStep::Post, WindowsInstallStep::fromString('POST'));
        self::assertSame(WindowsInstallStep::Wpkg, WindowsInstallStep::fromString('WPKG'));
    }

    #[Test]
    public function it_returns_null_for_unsupported_steps(): void
    {
        // Étapes inconnues (au-delà des 8 cases).
        self::assertNull(WindowsInstallStep::fromString('arbitrary'));
        self::assertNull(WindowsInstallStep::fromString('foo'));
        self::assertNull(WindowsInstallStep::fromString('init-modele'));
        self::assertNull(WindowsInstallStep::fromString('default'));
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

    /**
     * Story 3.8 — AC1.3 — strip whitespace safe avant tryFrom.
     */
    #[Test]
    public function it_strips_whitespace_before_resolution(): void
    {
        self::assertSame(WindowsInstallStep::Sysprep, WindowsInstallStep::fromString('  sysprep  '));
        self::assertSame(WindowsInstallStep::Join, WindowsInstallStep::fromString("\tjoin\t"));
    }
}

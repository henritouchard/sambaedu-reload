<?php

declare(strict_types=1);

namespace Tests\Unit\Ipxe\Enums;

use App\Ipxe\Enums\IpxeAdminAction;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 3.2 — AC1.1 / T1.2.
 *
 * Tests unitaires de la whitelist enum {@see IpxeAdminAction} (D9 — sécurité
 * critique : empêche l'exécution de scripts arbitraires).
 */
class IpxeAdminActionTest extends TestCase
{
    #[Test]
    public function it_has_exactly_three_cases_in_story_3_2(): void
    {
        $cases = IpxeAdminAction::cases();
        self::assertCount(
            3,
            $cases,
            'Story 3.2 : la whitelist doit contenir exactement 3 cases (rescuecd, winpe, factory_reset). '
            . 'Tout ajout doit être documenté par une nouvelle story (3.4/3.5/3.7) et ce test relaxé.',
        );
    }

    #[Test]
    public function it_resolves_template_path_for_each_case(): void
    {
        self::assertSame('ipxe.actions.rescuecd', IpxeAdminAction::Rescuecd->template());
        self::assertSame('ipxe.actions.winpe', IpxeAdminAction::Winpe->template());
        self::assertSame('ipxe.actions.factory_reset', IpxeAdminAction::FactoryReset->template());
    }

    #[Test]
    public function it_returns_null_for_unknown_action(): void
    {
        self::assertNull(IpxeAdminAction::tryFrom('unknown'));
        self::assertNull(IpxeAdminAction::tryFrom('install_macos'));
        self::assertNull(IpxeAdminAction::tryFrom(''));
        self::assertNull(IpxeAdminAction::tryFrom('rescuecd; rm -rf /'));
    }

    #[Test]
    public function it_returns_log_name_iso_value(): void
    {
        self::assertSame('rescuecd', IpxeAdminAction::Rescuecd->logName());
        self::assertSame('winpe', IpxeAdminAction::Winpe->logName());
        self::assertSame('factory_reset', IpxeAdminAction::FactoryReset->logName());
    }

    #[Test]
    public function it_resolves_known_strings_via_try_from(): void
    {
        self::assertSame(IpxeAdminAction::Rescuecd, IpxeAdminAction::tryFrom('rescuecd'));
        self::assertSame(IpxeAdminAction::Winpe, IpxeAdminAction::tryFrom('winpe'));
        self::assertSame(IpxeAdminAction::FactoryReset, IpxeAdminAction::tryFrom('factory_reset'));
    }
}

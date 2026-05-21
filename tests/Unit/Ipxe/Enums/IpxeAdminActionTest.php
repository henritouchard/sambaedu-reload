<?php

declare(strict_types=1);

namespace Tests\Unit\Ipxe\Enums;

use App\Ipxe\Enums\IpxeAdminAction;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 3.2 — AC1.1 / T1.2.
 * Story 3.4 — AC1.1 — extension +9 cases install_*.
 *
 * Tests unitaires de la whitelist enum {@see IpxeAdminAction} (D9 — sécurité
 * critique : empêche l'exécution de scripts arbitraires).
 */
class IpxeAdminActionTest extends TestCase
{
    #[Test]
    public function it_lists_exactly_twelve_cases_after_3_4(): void
    {
        $cases = IpxeAdminAction::cases();
        self::assertCount(
            12,
            $cases,
            'Story 3.4 : la whitelist doit contenir exactement 12 cases (3 historiques + 9 install_*).'
            . ' Tout ajout doit être documenté par une nouvelle story (3.5/3.7) et ce test relaxé.',
        );
    }

    #[Test]
    public function it_resolves_template_path_for_each_3_2_case(): void
    {
        self::assertSame('ipxe.actions.rescuecd', IpxeAdminAction::Rescuecd->template());
        self::assertSame('ipxe.actions.winpe', IpxeAdminAction::Winpe->template());
        self::assertSame('ipxe.actions.factory_reset', IpxeAdminAction::FactoryReset->template());
    }

    #[Test]
    public function it_resolves_install_deb_gnome_to_correct_template(): void
    {
        self::assertSame('ipxe.actions.install_deb_gnome', IpxeAdminAction::InstallDebGnome->template());
        self::assertSame('ipxe.actions.install_deb_base', IpxeAdminAction::InstallDebBase->template());
        self::assertSame('ipxe.actions.install_deb_cinnamon', IpxeAdminAction::InstallDebCinnamon->template());
        self::assertSame('ipxe.actions.install_deb_kde', IpxeAdminAction::InstallDebKde->template());
        self::assertSame('ipxe.actions.install_deb_lxde', IpxeAdminAction::InstallDebLxde->template());
        self::assertSame('ipxe.actions.install_deb_mate', IpxeAdminAction::InstallDebMate->template());
        self::assertSame('ipxe.actions.install_deb_xfce', IpxeAdminAction::InstallDebXfce->template());
        self::assertSame('ipxe.actions.install_nird', IpxeAdminAction::InstallNird->template());
        self::assertSame('ipxe.actions.install_ubuntu64', IpxeAdminAction::InstallUbuntu64->template());
    }

    #[Test]
    public function it_resolves_install_ubuntu64_with_ubuntu_distribution_meta(): void
    {
        $meta = IpxeAdminAction::InstallUbuntu64->linuxMeta();
        self::assertNotNull($meta);
        self::assertSame('ubuntu', $meta['distribution']);
        self::assertSame('base', $meta['variant']);
    }

    #[Test]
    public function it_resolves_install_nird_with_nird_distribution_meta(): void
    {
        $meta = IpxeAdminAction::InstallNird->linuxMeta();
        self::assertNotNull($meta);
        self::assertSame('nird', $meta['distribution']);
        self::assertSame('base', $meta['variant']);
    }

    #[Test]
    public function it_resolves_install_deb_variants_with_debian_distribution_meta(): void
    {
        foreach (
            [
                IpxeAdminAction::InstallDebBase => 'base',
                IpxeAdminAction::InstallDebCinnamon => 'cinnamon',
                IpxeAdminAction::InstallDebGnome => 'gnome',
                IpxeAdminAction::InstallDebKde => 'kde',
                IpxeAdminAction::InstallDebLxde => 'lxde',
                IpxeAdminAction::InstallDebMate => 'mate',
                IpxeAdminAction::InstallDebXfce => 'xfce',
            ] as $case => $expectedVariant
        ) {
            $meta = $case->linuxMeta();
            self::assertNotNull($meta, "linuxMeta() must not be null for {$case->value}");
            self::assertSame('debian', $meta['distribution']);
            self::assertSame($expectedVariant, $meta['variant']);
        }
    }

    #[Test]
    public function it_returns_null_meta_for_non_linux_cases(): void
    {
        self::assertNull(IpxeAdminAction::Rescuecd->linuxMeta());
        self::assertNull(IpxeAdminAction::Winpe->linuxMeta());
        self::assertNull(IpxeAdminAction::FactoryReset->linuxMeta());
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
    public function it_returns_correct_log_name_for_install_cases(): void
    {
        self::assertSame('install_deb_gnome', IpxeAdminAction::InstallDebGnome->logName());
        self::assertSame('install_ubuntu64', IpxeAdminAction::InstallUbuntu64->logName());
        self::assertSame('install_nird', IpxeAdminAction::InstallNird->logName());
    }

    #[Test]
    public function it_resolves_install_strings_via_try_from(): void
    {
        self::assertSame(IpxeAdminAction::InstallDebGnome, IpxeAdminAction::tryFrom('install_deb_gnome'));
        self::assertSame(IpxeAdminAction::InstallUbuntu64, IpxeAdminAction::tryFrom('install_ubuntu64'));
        self::assertSame(IpxeAdminAction::InstallNird, IpxeAdminAction::tryFrom('install_nird'));
    }
}

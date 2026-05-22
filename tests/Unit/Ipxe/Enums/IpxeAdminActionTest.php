<?php

declare(strict_types=1);

namespace Tests\Unit\Ipxe\Enums;

use App\Ipxe\Enums\IpxeAdminAction;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 3.2 — AC1.1 / T1.2.
 * Story 3.4 — AC1.1 — extension +9 cases install_*.
 * Story 3.5 — AC1.1 — extension +7 cases install_win*.
 *
 * Tests unitaires de la whitelist enum {@see IpxeAdminAction} (D9 — sécurité
 * critique : empêche l'exécution de scripts arbitraires).
 */
class IpxeAdminActionTest extends TestCase
{
    #[Test]
    public function it_lists_exactly_twentyfive_cases_after_3_7(): void
    {
        $cases = IpxeAdminAction::cases();
        self::assertCount(
            25,
            $cases,
            'Story 3.7 : la whitelist doit contenir exactement 25 cases (3 historiques + 9 install_* + 7 install_win* + 6 clonezilla/diagnostic).'
            . ' Tout ajout doit être documenté par une nouvelle story.',
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
        // PHP n'autorise pas les enums comme clés d'array → tuples.
        $cases = [
            [IpxeAdminAction::InstallDebBase, 'base'],
            [IpxeAdminAction::InstallDebCinnamon, 'cinnamon'],
            [IpxeAdminAction::InstallDebGnome, 'gnome'],
            [IpxeAdminAction::InstallDebKde, 'kde'],
            [IpxeAdminAction::InstallDebLxde, 'lxde'],
            [IpxeAdminAction::InstallDebMate, 'mate'],
            [IpxeAdminAction::InstallDebXfce, 'xfce'],
        ];
        foreach ($cases as [$case, $expectedVariant]) {
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

    /* ------------------------------------------------------------------
     * Story 3.5 — AC1.1 — extension +7 cases install_win*.
     * ------------------------------------------------------------------ */

    #[Test]
    public function it_resolves_install_win11_to_correct_template(): void
    {
        self::assertSame('ipxe.actions.install_win11', IpxeAdminAction::InstallWin11->template());
        self::assertSame('ipxe.actions.install_win10', IpxeAdminAction::InstallWin10->template());
        self::assertSame('ipxe.actions.install_win10_debug', IpxeAdminAction::InstallWin10Debug->template());
        self::assertSame('ipxe.actions.install_win10_disk', IpxeAdminAction::InstallWin10Disk->template());
        self::assertSame('ipxe.actions.install_win10_perso', IpxeAdminAction::InstallWin10Perso->template());
        self::assertSame('ipxe.actions.install_win11_disk', IpxeAdminAction::InstallWin11Disk->template());
        self::assertSame('ipxe.actions.install_win11_perso', IpxeAdminAction::InstallWin11Perso->template());
    }

    #[Test]
    public function it_resolves_install_win10_perso_with_perso_flag(): void
    {
        $meta = IpxeAdminAction::InstallWin10Perso->windowsMeta();
        self::assertNotNull($meta);
        self::assertSame('Win10', $meta['version']);
        self::assertSame('wimboot10', $meta['action']);
        self::assertSame(0, $meta['debug']);
        self::assertSame(0, $meta['disk']);
        self::assertSame(1, $meta['perso']);
    }

    #[Test]
    public function it_resolves_install_win10_disk_with_disk_flag(): void
    {
        $meta = IpxeAdminAction::InstallWin10Disk->windowsMeta();
        self::assertNotNull($meta);
        self::assertSame(0, $meta['perso']);
        self::assertSame(1, $meta['disk']);
        self::assertSame(0, $meta['debug']);
    }

    #[Test]
    public function it_resolves_install_win10_debug_with_debug_flag(): void
    {
        $meta = IpxeAdminAction::InstallWin10Debug->windowsMeta();
        self::assertNotNull($meta);
        self::assertSame(1, $meta['debug']);
        self::assertSame(0, $meta['disk']);
        self::assertSame(0, $meta['perso']);
    }

    #[Test]
    public function it_resolves_install_win11_default_with_no_flags(): void
    {
        $meta = IpxeAdminAction::InstallWin11->windowsMeta();
        self::assertNotNull($meta);
        self::assertSame('Win11', $meta['version']);
        self::assertSame('wimboot11', $meta['action']);
        self::assertSame(0, $meta['debug']);
        self::assertSame(0, $meta['disk']);
        self::assertSame(0, $meta['perso']);
    }

    #[Test]
    public function it_returns_null_windows_meta_for_non_windows_cases(): void
    {
        self::assertNull(IpxeAdminAction::Rescuecd->windowsMeta());
        self::assertNull(IpxeAdminAction::Winpe->windowsMeta());
        self::assertNull(IpxeAdminAction::FactoryReset->windowsMeta());
        self::assertNull(IpxeAdminAction::InstallDebGnome->windowsMeta());
        self::assertNull(IpxeAdminAction::InstallNird->windowsMeta());
        self::assertNull(IpxeAdminAction::InstallUbuntu64->windowsMeta());
    }

    #[Test]
    public function it_returns_null_linux_meta_for_install_win_cases(): void
    {
        // Non-régression 3.4 : linuxMeta retourne null pour les nouveaux cases.
        self::assertNull(IpxeAdminAction::InstallWin10->linuxMeta());
        self::assertNull(IpxeAdminAction::InstallWin10Debug->linuxMeta());
        self::assertNull(IpxeAdminAction::InstallWin11->linuxMeta());
        self::assertNull(IpxeAdminAction::InstallWin11Perso->linuxMeta());
    }

    #[Test]
    public function it_returns_correct_log_name_for_install_win_cases(): void
    {
        self::assertSame('install_win10', IpxeAdminAction::InstallWin10->logName());
        self::assertSame('install_win11', IpxeAdminAction::InstallWin11->logName());
        self::assertSame('install_win11_perso', IpxeAdminAction::InstallWin11Perso->logName());
    }

    #[Test]
    public function it_resolves_install_win_strings_via_try_from(): void
    {
        self::assertSame(IpxeAdminAction::InstallWin10, IpxeAdminAction::tryFrom('install_win10'));
        self::assertSame(IpxeAdminAction::InstallWin11, IpxeAdminAction::tryFrom('install_win11'));
        self::assertSame(IpxeAdminAction::InstallWin11Disk, IpxeAdminAction::tryFrom('install_win11_disk'));
        // Anti-injection sur valeur hors whitelist.
        self::assertNull(IpxeAdminAction::tryFrom('install_win11_old'));
        self::assertNull(IpxeAdminAction::tryFrom('install_win12'));
    }

    /* ------------------------------------------------------------------
     * Story 3.7 — AC1.1 / AC1.2 / AC1.3 / AC1.4 — extension +6 cases.
     * ------------------------------------------------------------------ */

    #[Test]
    public function it_resolves_3_7_clonezilla_cases_to_correct_templates(): void
    {
        self::assertSame('ipxe.actions.clonezilla_live', IpxeAdminAction::ClonezillaLive->template());
        self::assertSame('ipxe.actions.clonezilla_save_sda1_sda2', IpxeAdminAction::ClonezillaSaveSda1Sda2->template());
        self::assertSame('ipxe.actions.clonezilla_restore_sda2_sda1', IpxeAdminAction::ClonezillaRestoreSda2Sda1->template());
        self::assertSame('ipxe.actions.gparted', IpxeAdminAction::Gparted->template());
        self::assertSame('ipxe.actions.hdt', IpxeAdminAction::Hdt->template());
        self::assertSame('ipxe.actions.memtest86plus', IpxeAdminAction::Memtest86plus->template());
    }

    #[Test]
    public function it_resolves_3_7_cases_via_try_from(): void
    {
        self::assertSame(IpxeAdminAction::ClonezillaLive, IpxeAdminAction::tryFrom('clonezilla_live'));
        self::assertSame(IpxeAdminAction::ClonezillaSaveSda1Sda2, IpxeAdminAction::tryFrom('clonezilla_save_sda1_sda2'));
        self::assertSame(IpxeAdminAction::ClonezillaRestoreSda2Sda1, IpxeAdminAction::tryFrom('clonezilla_restore_sda2_sda1'));
        self::assertSame(IpxeAdminAction::Gparted, IpxeAdminAction::tryFrom('gparted'));
        self::assertSame(IpxeAdminAction::Hdt, IpxeAdminAction::tryFrom('hdt'));
        self::assertSame(IpxeAdminAction::Memtest86plus, IpxeAdminAction::tryFrom('memtest86plus'));
    }

    #[Test]
    public function it_returns_null_linux_and_windows_meta_for_3_7_cases(): void
    {
        // AC1.4 — les 6 nouveaux cases 3.7 retournent null pour linuxMeta() et windowsMeta().
        $cases37 = [
            IpxeAdminAction::ClonezillaLive,
            IpxeAdminAction::ClonezillaSaveSda1Sda2,
            IpxeAdminAction::ClonezillaRestoreSda2Sda1,
            IpxeAdminAction::Gparted,
            IpxeAdminAction::Hdt,
            IpxeAdminAction::Memtest86plus,
        ];

        foreach ($cases37 as $case) {
            self::assertNull($case->linuxMeta(), "linuxMeta() doit retourner null pour {$case->value}");
            self::assertNull($case->windowsMeta(), "windowsMeta() doit retourner null pour {$case->value}");
        }
    }

    #[Test]
    public function it_returns_correct_log_name_for_3_7_cases(): void
    {
        // AC1.3 — logName() retourne la valeur snake_case de l'enum (pas de modif requise).
        self::assertSame('clonezilla_live', IpxeAdminAction::ClonezillaLive->logName());
        self::assertSame('gparted', IpxeAdminAction::Gparted->logName());
        self::assertSame('memtest86plus', IpxeAdminAction::Memtest86plus->logName());
    }

    #[Test]
    public function it_returns_distinct_boot_log_actions_for_3_7_cases(): void
    {
        // D11 / AC8.1-8.4 — bootLogAction() retourne les valeurs distinctes pour l'audit.
        self::assertSame('ipxe_clonezilla', IpxeAdminAction::ClonezillaLive->bootLogAction());
        self::assertSame('ipxe_clonezilla', IpxeAdminAction::ClonezillaSaveSda1Sda2->bootLogAction());
        self::assertSame('ipxe_clonezilla', IpxeAdminAction::ClonezillaRestoreSda2Sda1->bootLogAction());
        self::assertSame('ipxe_gparted', IpxeAdminAction::Gparted->bootLogAction());
        self::assertSame('ipxe_hdt', IpxeAdminAction::Hdt->bootLogAction());
        self::assertSame('ipxe_memtest', IpxeAdminAction::Memtest86plus->bootLogAction());
    }

    #[Test]
    public function it_returns_ipxe_action_boot_log_for_legacy_3_2_cases(): void
    {
        // Non-régression : les 3 cases 3.2 (rescuecd/winpe/factory_reset) conservent
        // `'ipxe_action'` (compat historique — voir PHPDoc bootLogAction()).
        // **D2 — divergence intentionnelle** : FactoryReset garde `ipxe_action`
        // alors que ClonezillaRestoreSda2Sda1 prend `ipxe_clonezilla` malgré
        // une cmdline identique. Cf. doc IpxeAdminAction::bootLogAction().
        self::assertSame('ipxe_action', IpxeAdminAction::Rescuecd->bootLogAction());
        self::assertSame('ipxe_action', IpxeAdminAction::Winpe->bootLogAction());
        self::assertSame('ipxe_action', IpxeAdminAction::FactoryReset->bootLogAction());
    }

    /**
     * Post-review #7 — extension `bootLogAction()` aux cases install_* (3.4)
     * pour audit fin. Le data provider couvre les 9 mappings + flag distro.
     *
     * @return array<string, array{0:IpxeAdminAction, 1:string}>
     */
    public static function installLinuxBootLogMappingProvider(): array
    {
        return [
            'deb_base' => [IpxeAdminAction::InstallDebBase, 'ipxe_deb_base'],
            'deb_cinnamon' => [IpxeAdminAction::InstallDebCinnamon, 'ipxe_deb_cinnamon'],
            'deb_gnome' => [IpxeAdminAction::InstallDebGnome, 'ipxe_deb_gnome'],
            'deb_kde' => [IpxeAdminAction::InstallDebKde, 'ipxe_deb_kde'],
            'deb_lxde' => [IpxeAdminAction::InstallDebLxde, 'ipxe_deb_lxde'],
            'deb_mate' => [IpxeAdminAction::InstallDebMate, 'ipxe_deb_mate'],
            'deb_xfce' => [IpxeAdminAction::InstallDebXfce, 'ipxe_deb_xfce'],
            'nird' => [IpxeAdminAction::InstallNird, 'ipxe_nird'],
            'ubuntu64' => [IpxeAdminAction::InstallUbuntu64, 'ipxe_ubuntu64'],
        ];
    }

    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('installLinuxBootLogMappingProvider')]
    public function it_returns_distinct_boot_log_action_for_install_linux_cases(
        IpxeAdminAction $case,
        string $expectedLabel,
    ): void {
        self::assertSame($expectedLabel, $case->bootLogAction());
    }

    /**
     * Post-review #7 — extension `bootLogAction()` aux cases install_win*
     * (3.5) pour audit fin.
     *
     * @return array<string, array{0:IpxeAdminAction, 1:string}>
     */
    public static function installWindowsBootLogMappingProvider(): array
    {
        return [
            'win10' => [IpxeAdminAction::InstallWin10, 'ipxe_win10'],
            'win10_debug' => [IpxeAdminAction::InstallWin10Debug, 'ipxe_win10_debug'],
            'win10_disk' => [IpxeAdminAction::InstallWin10Disk, 'ipxe_win10_disk'],
            'win10_perso' => [IpxeAdminAction::InstallWin10Perso, 'ipxe_win10_perso'],
            'win11' => [IpxeAdminAction::InstallWin11, 'ipxe_win11'],
            'win11_disk' => [IpxeAdminAction::InstallWin11Disk, 'ipxe_win11_disk'],
            'win11_perso' => [IpxeAdminAction::InstallWin11Perso, 'ipxe_win11_perso'],
        ];
    }

    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('installWindowsBootLogMappingProvider')]
    public function it_returns_distinct_boot_log_action_for_install_windows_cases(
        IpxeAdminAction $case,
        string $expectedLabel,
    ): void {
        self::assertSame($expectedLabel, $case->bootLogAction());
    }

    /**
     * Post-review #7 — garde-fou strict VARCHAR(20).
     *
     * Toutes les valeurs retournées par `bootLogAction()` doivent tenir dans
     * `machine_boot_logs.action` (varchar(20)) — si un futur dev ajoute un
     * case install_* avec un label trop long, la migration silencieuse
     * tronquerait la valeur en DB et casserait l'audit. Ce test gèle la
     * contrainte au niveau enum.
     */
    #[Test]
    public function it_ensures_all_boot_log_actions_fit_in_varchar_20(): void
    {
        foreach (IpxeAdminAction::cases() as $case) {
            $label = $case->bootLogAction();
            self::assertLessThanOrEqual(
                20,
                strlen($label),
                "bootLogAction() pour {$case->value} = '{$label}' (" . strlen($label) . " chars) > 20 chars. "
                . 'La colonne machine_boot_logs.action est varchar(20) — soit raccourcir le label, soit créer une migration dédiée (HORS-SCOPE 3.7 D11).',
            );
        }
    }
}

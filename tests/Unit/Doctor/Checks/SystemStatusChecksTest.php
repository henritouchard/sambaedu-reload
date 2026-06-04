<?php

declare(strict_types=1);

namespace Tests\Unit\Doctor\Checks;

use App\Doctor\CheckResult;
use App\Doctor\Checks\ControlHub\ControlHubReachableCheck;
use App\Doctor\Checks\Database\PostgresConnectionCheck;
use App\Doctor\Checks\Ipxe\IpxeConfigCheck;
use App\Doctor\Level;
use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tests unitaires des nouveaux checks « État du système ».
 *
 * Les checks réseau/système (LdapBindCheck, ApacheConfigCheck) dépendent de
 * l'environnement réel — couverts par le smoke test « ne lève jamais »
 * (contrat EnvironmentCheck : toute défaillance devient un CheckResult).
 */
class SystemStatusChecksTest extends TestCase
{
    #[Test]
    public function it_postgres_check_reports_ok_on_test_connection(): void
    {
        // En environnement de test la connexion par défaut (SQLite :memory:)
        // répond — le check doit rapporter ok + driver effectif.
        $result = (new PostgresConnectionCheck())->run();

        self::assertSame(Level::Ok, $result->level);
        self::assertStringContainsString('sqlite', $result->detail);
    }

    #[Test]
    public function it_controlhub_check_warns_when_no_connection(): void
    {
        // Table controlhub_connection absente en test → le check doit
        // dégrader en warn (jamais d'exception).
        $result = (new ControlHubReachableCheck())->run();

        self::assertSame(Level::Warn, $result->level);
    }

    #[Test]
    public function it_ipxe_check_errors_when_no_assets_root_exists(): void
    {
        config([
            'ipxe.actions.os_assets.roots' => ['/nonexistent/se5-test-' . uniqid()],
        ]);

        $result = app(IpxeConfigCheck::class)->run();

        self::assertSame(Level::Error, $result->level);
        self::assertNotNull($result->fix);
    }

    #[Test]
    public function it_ipxe_check_warns_when_ad_vars_missing(): void
    {
        $tmp = sys_get_temp_dir() . '/se5-ipxe-check-' . uniqid();
        (new Filesystem())->makeDirectory($tmp, 0755, true);
        config([
            'ipxe.actions.os_assets.roots' => [$tmp],
            'sambaedu.domain' => '',
            'sambaedu.se4install_name' => 'se4install',
        ]);

        $result = app(IpxeConfigCheck::class)->run();

        self::assertSame(Level::Warn, $result->level);
        self::assertStringContainsString('SAMBAEDU_DOMAIN', $result->detail);

        (new Filesystem())->deleteDirectory($tmp);
    }

    #[Test]
    public function it_ipxe_check_ok_when_root_and_vars_present(): void
    {
        $tmp = sys_get_temp_dir() . '/se5-ipxe-check-' . uniqid();
        (new Filesystem())->makeDirectory($tmp, 0755, true);
        config([
            'ipxe.actions.os_assets.roots' => [$tmp],
            // Alignée sur la racine servie — sinon warn divergence (F5).
            'ipxe.iso_management.deployed_os_base_path' => $tmp,
            'sambaedu.domain' => 'localdev.fr',
            'sambaedu.se4install_name' => 'se4install',
        ]);

        $result = app(IpxeConfigCheck::class)->run();

        self::assertSame(Level::Ok, $result->level);

        (new Filesystem())->deleteDirectory($tmp);
    }

    #[Test]
    public function it_ipxe_check_warns_on_divergent_roots(): void
    {
        // Fix review F5 : racine servie ≠ racine inventaire → warn explicite.
        $tmp = sys_get_temp_dir() . '/se5-ipxe-div-' . uniqid();
        (new Filesystem())->makeDirectory($tmp, 0755, true);
        config([
            'ipxe.actions.os_assets.roots' => [$tmp],
            'ipxe.iso_management.deployed_os_base_path' => $tmp . '-other',
            'sambaedu.domain' => 'localdev.fr',
            'sambaedu.se4install_name' => 'se4install',
        ]);

        $result = app(IpxeConfigCheck::class)->run();

        self::assertSame(Level::Warn, $result->level);
        self::assertStringContainsString('divergentes', $result->detail);

        (new Filesystem())->deleteDirectory($tmp);
    }

    #[Test]
    public function it_all_new_checks_never_throw(): void
    {
        // Contrat EnvironmentCheck : un check défaillant retourne un
        // CheckResult, il ne crashe pas la page / la commande doctor.
        $checks = [
            app(\App\Doctor\Checks\Database\PostgresConnectionCheck::class),
            app(\App\Doctor\Checks\Ad\LdapBindCheck::class),
            app(\App\Doctor\Checks\ControlHub\ControlHubReachableCheck::class),
            app(\App\Doctor\Checks\Apache\ApacheConfigCheck::class),
            app(\App\Doctor\Checks\Ipxe\IpxeConfigCheck::class),
        ];

        foreach ($checks as $check) {
            self::assertInstanceOf(CheckResult::class, $check->run(), $check::class);
            self::assertNotSame('', $check->name());
            self::assertNotSame('', $check->tag());
        }
    }
}

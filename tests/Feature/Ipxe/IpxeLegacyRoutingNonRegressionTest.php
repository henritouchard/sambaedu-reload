<?php

declare(strict_types=1);

namespace Tests\Feature\Ipxe;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\IpxeSchemaBootstrapper;
use Tests\TestCase;

/**
 * Story 3.1 — AC6.2 / T6.3.
 *
 * Tests de non-régression : les routes legacy `/ipxe/*` (admin.php,
 * installation-linux.php, etc.) doivent **continuer** d'être servies par
 * le catchall legacy malgré l'introduction de `/ipxe/boot` natif.
 *
 * Le catchall logue tous les hits dans `legacy_catchall_logs` (sauf les
 * routes natives qui le court-circuitent). On vérifie ici :
 *
 *  1. `/ipxe/boot` n'est PAS loggé dans `legacy_catchall_logs` (court-circuit
 *     natif).
 *  2. `/ipxe/admin.php` reste traité par le catchall (= row dans
 *     `legacy_catchall_logs`).
 *  3. `/ipxe/installation-linux.php` reste traité par le catchall.
 */
class IpxeLegacyRoutingNonRegressionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        IpxeSchemaBootstrapper::bootstrap();

        // Provisionne les tables legacy_catchall_logs + error_logs (le
        // catchall en a besoin pour ne pas crasher en SQLite :memory:).
        if (! Schema::hasTable('legacy_catchall_logs')) {
            Schema::create('legacy_catchall_logs', function (Blueprint $table) {
                $table->id();
                $table->string('method', 10);
                $table->string('path', 2048);
                $table->string('ip', 45);
                $table->text('query_string')->nullable();
                $table->text('referer')->nullable();
                $table->timestamp('created_at');
            });
        }

        // Désactive le blocage des routes migrées pour ne pas masquer le
        // proxy legacy par un 410 (le test veut prouver que le catchall est
        // toujours appelé).
        Config::set('sambaedu.block_migrated_routes', false);

        config([
            'auth_v1.bootstrap.allowed_subnets' => '127.0.0.0/8,192.168.0.0/16',
        ]);
    }

    protected function tearDown(): void
    {
        \App\Models\LegacyCatchallLog::query()->delete();
        parent::tearDown();
    }

    #[Test]
    public function it_serves_ipxe_boot_natively_not_via_catchall(): void
    {
        $countBefore = \App\Models\LegacyCatchallLog::query()->count();

        $this->get('/ipxe/boot');

        $countAfter = \App\Models\LegacyCatchallLog::query()->count();

        // La route native ne doit JAMAIS être loggée dans legacy_catchall_logs
        // (elle court-circuite le catchall).
        self::assertSame(
            $countBefore,
            $countAfter,
            'La route native /ipxe/boot a généré une row dans legacy_catchall_logs '
            . '— ordre des routes incorrect (route 3.1 derrière le catchall ?).',
        );
    }

    #[Test]
    public function it_still_routes_ipxe_admin_via_catchall(): void
    {
        $this->get('/ipxe/admin.php');

        // Au moins une row pour /ipxe/admin.php doit avoir été créée par le
        // catchall (le path exact peut varier selon la résolution Laravel).
        $found = \App\Models\LegacyCatchallLog::query()
            ->where('path', 'like', '%ipxe/admin.php%')
            ->exists();

        self::assertTrue(
            $found,
            '/ipxe/admin.php devrait être servi par le catchall legacy mais '
            . 'aucune row legacy_catchall_logs ne le confirme. Risque : la '
            . 'route native /ipxe/boot a été déclarée avec un pattern trop '
            . 'large (ex. /ipxe/{file}) qui capture aussi /ipxe/admin.php.',
        );
    }

    #[Test]
    public function it_still_routes_ipxe_installation_linux_via_catchall(): void
    {
        $this->get('/ipxe/installation-linux.php');

        $found = \App\Models\LegacyCatchallLog::query()
            ->where('path', 'like', '%ipxe/installation-linux.php%')
            ->exists();

        self::assertTrue(
            $found,
            '/ipxe/installation-linux.php devrait être servi par le catchall '
            . 'legacy (sera réécrit par Story 3.4).',
        );
    }

    /* ------------------------------------------------------------------
     * Story 3.2 — AC6.2 / T6.6 — non-régression catchall pour les routes
     * 3.3-3.7 + court-circuit pour les routes natives 3.2.
     * ------------------------------------------------------------------ */

    #[Test]
    public function it_serves_ipxe_admin_natively_not_via_catchall(): void
    {
        $countBefore = \App\Models\LegacyCatchallLog::query()->count();

        $this->get('/ipxe/admin');

        $countAfter = \App\Models\LegacyCatchallLog::query()->count();

        self::assertSame(
            $countBefore,
            $countAfter,
            'La route native /ipxe/admin (3.2) a généré une row dans legacy_catchall_logs '
            . '— ordre des routes incorrect (route 3.2 derrière le catchall ?).',
        );
    }

    #[Test]
    public function it_serves_ipxe_maintenance_natively_not_via_catchall(): void
    {
        $countBefore = \App\Models\LegacyCatchallLog::query()->count();

        $this->get('/ipxe/maintenance');

        self::assertSame(
            $countBefore,
            \App\Models\LegacyCatchallLog::query()->count(),
            '/ipxe/maintenance (3.2) ne doit pas passer par le catchall',
        );
    }

    #[Test]
    public function it_serves_ipxe_action_natively_not_via_catchall(): void
    {
        $countBefore = \App\Models\LegacyCatchallLog::query()->count();

        $this->get('/ipxe/action/rescuecd');

        self::assertSame(
            $countBefore,
            \App\Models\LegacyCatchallLog::query()->count(),
            '/ipxe/action/rescuecd (3.2) ne doit pas passer par le catchall',
        );
    }

    #[Test]
    public function it_still_serves_ipxe_clonage_via_catchall(): void
    {
        $this->get('/ipxe/clonage.php');

        $found = \App\Models\LegacyCatchallLog::query()
            ->where('path', 'like', '%ipxe/clonage.php%')
            ->exists();

        self::assertTrue(
            $found,
            '/ipxe/clonage.php devrait continuer à être servi par le catchall '
            . 'jusqu\'à la Story 3.7.',
        );
    }
}

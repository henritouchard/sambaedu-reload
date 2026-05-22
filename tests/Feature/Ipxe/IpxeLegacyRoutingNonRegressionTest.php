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

    /* ------------------------------------------------------------------
     * Story 3.3 — AC8.2 / T6.7 — non-régression catchall pour les 5
     * routes legacy `.php` + court-circuit pour les 5 routes natives 3.3.
     * ------------------------------------------------------------------ */

    #[Test]
    public function it_serves_ipxe_enrollment_name_natively_not_via_catchall(): void
    {
        $countBefore = \App\Models\LegacyCatchallLog::query()->count();
        $this->get('/ipxe/enrollment/name');

        self::assertSame(
            $countBefore,
            \App\Models\LegacyCatchallLog::query()->count(),
            '/ipxe/enrollment/name (3.3) ne doit pas passer par le catchall',
        );
    }

    #[Test]
    public function it_serves_ipxe_enrollment_room_natively_not_via_catchall(): void
    {
        $countBefore = \App\Models\LegacyCatchallLog::query()->count();
        $this->get('/ipxe/enrollment/room');

        self::assertSame(
            $countBefore,
            \App\Models\LegacyCatchallLog::query()->count(),
            '/ipxe/enrollment/room (3.3) ne doit pas passer par le catchall',
        );
    }

    #[Test]
    public function it_still_serves_ipxe_enregistrement_php_via_catchall(): void
    {
        $this->get('/ipxe/enregistrement.php');

        $found = \App\Models\LegacyCatchallLog::query()
            ->where('path', 'like', '%ipxe/enregistrement.php%')
            ->exists();

        self::assertTrue(
            $found,
            '/ipxe/enregistrement.php (legacy `.php`) doit continuer à être servi '
            . 'par le catchall jusqu\'à la Story 3.7 cleanup.',
        );
    }

    #[Test]
    public function it_still_serves_ipxe_salles_php_via_catchall(): void
    {
        $this->get('/ipxe/salles.php');

        $found = \App\Models\LegacyCatchallLog::query()
            ->where('path', 'like', '%ipxe/salles.php%')
            ->exists();

        self::assertTrue(
            $found,
            '/ipxe/salles.php (legacy `.php`) doit continuer à être servi par le catchall.',
        );
    }

    #[Test]
    public function it_still_serves_ipxe_parcs_php_via_catchall(): void
    {
        $this->get('/ipxe/parcs.php');

        $found = \App\Models\LegacyCatchallLog::query()
            ->where('path', 'like', '%ipxe/parcs.php%')
            ->exists();

        self::assertTrue(
            $found,
            '/ipxe/parcs.php (legacy `.php`) doit continuer à être servi par le catchall.',
        );
    }

    /* ------------------------------------------------------------------
     * Story 3.4 — AC8.2 / T7.6 — non-régression catchall pour les
     * routes legacy `.php` + court-circuit pour les 4 routes natives 3.4.
     * ------------------------------------------------------------------ */

    #[Test]
    public function it_serves_ipxe_installation_linux_natively_not_via_catchall(): void
    {
        $countBefore = \App\Models\LegacyCatchallLog::query()->count();
        $this->get('/ipxe/installation-linux');

        self::assertSame(
            $countBefore,
            \App\Models\LegacyCatchallLog::query()->count(),
            '/ipxe/installation-linux (3.4) ne doit pas passer par le catchall',
        );
    }

    #[Test]
    public function it_serves_ipxe_linux_preseed_natively_not_via_catchall(): void
    {
        $countBefore = \App\Models\LegacyCatchallLog::query()->count();
        $this->get('/ipxe/linux/preseed?mac=&uuid=');

        self::assertSame(
            $countBefore,
            \App\Models\LegacyCatchallLog::query()->count(),
            '/ipxe/linux/preseed (3.4) ne doit pas passer par le catchall',
        );
    }

    #[Test]
    public function it_still_serves_ipxe_installation_windows_php_via_catchall(): void
    {
        // L'URL legacy avec `.php` (`installation-windows.php`) reste servie
        // par le catchall après 3.5 (clean-up = 3.7). Seule la version sans
        // `.php` (route native 3.5) court-circuite le catchall.
        $this->get('/ipxe/installation-windows.php');

        $found = \App\Models\LegacyCatchallLog::query()
            ->where('path', 'like', '%ipxe/installation-windows.php%')
            ->exists();

        self::assertTrue(
            $found,
            '/ipxe/installation-windows.php doit continuer à être servi par le catchall '
            . '(legacy URL avec .php — cleanup 3.7).',
        );
    }

    /* ------------------------------------------------------------------
     * Story 3.5 — AC8.2 — non-régression catchall sur les 6 routes natives
     * ------------------------------------------------------------------ */

    #[Test]
    public function it_serves_ipxe_installation_windows_natively_not_via_catchall(): void
    {
        $countBefore = \App\Models\LegacyCatchallLog::query()->count();
        $this->get('/ipxe/installation-windows');

        self::assertSame(
            $countBefore,
            \App\Models\LegacyCatchallLog::query()->count(),
            '/ipxe/installation-windows (3.5) ne doit pas passer par le catchall',
        );
    }

    #[Test]
    public function it_serves_ipxe_windows_unattend_natively_not_via_catchall(): void
    {
        $countBefore = \App\Models\LegacyCatchallLog::query()->count();
        $this->get('/ipxe/windows/unattend.xml?mac=&uuid=');

        self::assertSame(
            $countBefore,
            \App\Models\LegacyCatchallLog::query()->count(),
            '/ipxe/windows/unattend.xml (3.5) ne doit pas passer par le catchall',
        );
    }

    #[Test]
    public function it_serves_ipxe_windows_install_bat_natively_not_via_catchall(): void
    {
        $countBefore = \App\Models\LegacyCatchallLog::query()->count();
        $this->get('/ipxe/windows/install.bat?mac=&uuid=');

        self::assertSame(
            $countBefore,
            \App\Models\LegacyCatchallLog::query()->count(),
            '/ipxe/windows/install.bat (3.5) ne doit pas passer par le catchall',
        );
    }

    #[Test]
    public function it_still_serves_ipxe_win10_repair_bat_php_via_catchall(): void
    {
        // Le legacy `Win10/repair.bat.php` continue d'être servi par catchall
        // (utilisé par action winpe réparation 3.2 — non touché 3.5).
        $this->get('/ipxe/Win10/repair.bat.php');

        $found = \App\Models\LegacyCatchallLog::query()
            ->where('path', 'like', '%ipxe/Win10/repair.bat.php%')
            ->exists();

        self::assertTrue(
            $found,
            '/ipxe/Win10/repair.bat.php doit continuer à être servi par le catchall '
            . '(utilisé par action winpe 3.2 réparation — non touché 3.5).',
        );
    }

    #[Test]
    public function it_still_serves_ipxe_clonage_php_via_catchall(): void
    {
        $this->get('/ipxe/clonage.php');

        $found = \App\Models\LegacyCatchallLog::query()
            ->where('path', 'like', '%ipxe/clonage.php%')
            ->exists();

        self::assertTrue($found, '/ipxe/clonage.php doit continuer via catchall (Story 3.7)');
    }

    /* ------------------------------------------------------------------
     * Story 3.6 — AC7.2 — non-régression catchall pour `/ipxe/Win10/win_iso.php`
     * legacy + court-circuit pour `/admin/ipxe/iso-windows` native.
     * ------------------------------------------------------------------ */

    #[Test]
    public function it_still_serves_legacy_win_iso_php_via_catchall(): void
    {
        // Le legacy `Win10/win_iso.php` continue d'être servi par catchall
        // jusqu'à Story 3.7 cleanup (3.6 livre la page admin web SE5 native
        // sous `/admin/ipxe/iso-windows` mais ne RETIRE pas la route legacy).
        $this->get('/ipxe/Win10/win_iso.php');

        $found = \App\Models\LegacyCatchallLog::query()
            ->where('path', 'like', '%ipxe/Win10/win_iso.php%')
            ->exists();

        self::assertTrue(
            $found,
            '/ipxe/Win10/win_iso.php doit continuer à être servi par le catchall '
            . '(parité legacy — cleanup en Story 3.7).',
        );
    }

    #[Test]
    public function it_serves_new_admin_ipxe_iso_windows_natively_not_via_catchall(): void
    {
        // La nouvelle page admin SE5 `/admin/ipxe/iso-windows` est servie
        // nativement par Laravel Livewire — pas par le catchall.
        // Le middleware `sambaedu.auth` redirige vers le login (302), ce qui
        // est attendu côté non-authentifié — le but du test est de vérifier
        // qu'AUCUNE row legacy_catchall_logs n'est créée (= route déclarée
        // AVANT le catchall).
        $countBefore = \App\Models\LegacyCatchallLog::query()->count();

        $this->get('/admin/ipxe/iso-windows');

        self::assertSame(
            $countBefore,
            \App\Models\LegacyCatchallLog::query()->count(),
            '/admin/ipxe/iso-windows (3.6) ne doit pas passer par le catchall — la route '
            . 'doit être déclarée AVANT le catchall dans routes/web.php.',
        );
    }

    /* ------------------------------------------------------------------
     * Story 3.7 — D10 / AC7.1 / AC7.2 — cleanup final catchall Epic 3.
     * Les routes iPXE legacy migreees 3.1-3.7 retournent 410 Gone.
     * ------------------------------------------------------------------ */

    #[Test]
    public function it_serves_ipxe_clonezilla_menu_natively_not_via_catchall(): void
    {
        // La route native /ipxe/clonezilla-menu (3.7) ne doit pas passer par le catchall.
        $countBefore = \App\Models\LegacyCatchallLog::query()->count();

        $this->get('/ipxe/clonezilla-menu');

        self::assertSame(
            $countBefore,
            \App\Models\LegacyCatchallLog::query()->count(),
            '/ipxe/clonezilla-menu (3.7) ne doit pas passer par le catchall.',
        );
    }

    #[Test]
    public function it_blocks_ipxe_clonezilla_menu_php_with_410_gone(): void
    {
        // AC7.2 — Q-1 Henri = 410 Gone + corps iPXE pour les routes legacy migreees.
        // block_migrated_routes=true (defaut) doit intercepter ces requetes.
        Config::set('sambaedu.block_migrated_routes', true);

        $response = $this->get('/ipxe/clonezilla_menu.php');

        self::assertSame(
            410,
            $response->status(),
            '/ipxe/clonezilla_menu.php (legacy) doit retourner 410 Gone (D10 Q-1 Henri).',
        );
        self::assertStringContainsString('#!ipxe', (string) $response->getContent());
    }

    #[Test]
    public function it_blocks_ipxe_clonezilla_php_with_410_gone(): void
    {
        Config::set('sambaedu.block_migrated_routes', true);

        $response = $this->get('/ipxe/clonezilla.php');

        self::assertSame(410, $response->status());
        self::assertStringContainsString('#!ipxe', (string) $response->getContent());
    }

    #[Test]
    public function it_blocks_ipxe_gparted_php_with_410_gone(): void
    {
        Config::set('sambaedu.block_migrated_routes', true);

        $response = $this->get('/ipxe/gparted.php');

        self::assertSame(410, $response->status());
        self::assertStringContainsString('#!ipxe', (string) $response->getContent());
    }

    #[Test]
    public function it_blocks_ipxe_hdt_php_with_410_gone(): void
    {
        Config::set('sambaedu.block_migrated_routes', true);

        $response = $this->get('/ipxe/hdt.php');

        self::assertSame(410, $response->status());
        // Post-review #13 — cohérence couverture body iPXE iso autres tests blocked.
        self::assertStringContainsString('#!ipxe', (string) $response->getContent());
    }

    #[Test]
    public function it_blocks_ipxe_memtest86plus_php_with_410_gone(): void
    {
        Config::set('sambaedu.block_migrated_routes', true);

        $response = $this->get('/ipxe/memtest86plus.php');

        self::assertSame(410, $response->status());
        // Post-review #13 — cohérence couverture body iPXE iso autres tests blocked.
        self::assertStringContainsString('#!ipxe', (string) $response->getContent());
    }

    #[Test]
    public function it_blocks_ipxe_admin_php_with_410_gone(): void
    {
        Config::set('sambaedu.block_migrated_routes', true);

        $response = $this->get('/ipxe/admin.php');

        self::assertSame(410, $response->status());
        self::assertStringContainsString('#!ipxe', (string) $response->getContent());
    }

    #[Test]
    public function it_blocks_ipxe_maintenance_php_with_410_gone(): void
    {
        Config::set('sambaedu.block_migrated_routes', true);

        $response = $this->get('/ipxe/maintenance.php');

        self::assertSame(410, $response->status());
        // Post-review #13 — cohérence couverture body iPXE iso autres tests blocked.
        self::assertStringContainsString('#!ipxe', (string) $response->getContent());
    }

    #[Test]
    public function it_blocks_ipxe_actions_clonezilla_live_php_with_410_gone(): void
    {
        Config::set('sambaedu.block_migrated_routes', true);

        $response = $this->get('/ipxe/actions/clonezilla_live.php');

        self::assertSame(410, $response->status());
        // Post-review #13 — cohérence couverture body iPXE iso autres tests blocked.
        self::assertStringContainsString('#!ipxe', (string) $response->getContent());
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit\Gpo;

use App\Gpo\Enums\GpoHealthStatus;
use App\Gpo\Support\GpoHealthStatusCalculator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests unitaires — GpoHealthStatusCalculator (Story 16.14 AC7.2 / D4).
 *
 * Purement unitaires : pas de bootstrap Spatie, pas d'accès DB.
 */
class GpoHealthStatusCalculatorTest extends TestCase
{
    private function makeGpo(string $name, ?int $versionNumber, ?string $displayName = null): array
    {
        return [
            'name'          => $name,
            'displayName'   => $displayName ?? 'GPO Test',
            'versionNumber' => $versionNumber,
            'dn'            => null,
            'path'          => null,
        ];
    }

    #[Test]
    public function it_returns_healthy_for_active_gpo_with_links(): void
    {
        $gpo = $this->makeGpo('{AAAA-0001}', 65539); // version > 0
        $status = GpoHealthStatusCalculator::calculate($gpo, totalLinks: 2, hasConflict: false);

        self::assertSame(GpoHealthStatus::Healthy, $status);
    }

    #[Test]
    public function it_returns_stale_for_gpo_with_zero_version(): void
    {
        $gpo = $this->makeGpo('{AAAA-0002}', 0);
        $status = GpoHealthStatusCalculator::calculate($gpo, totalLinks: 3, hasConflict: false);

        self::assertSame(GpoHealthStatus::Stale, $status);
    }

    #[Test]
    public function it_returns_stale_for_gpo_with_null_version(): void
    {
        $gpo = $this->makeGpo('{AAAA-0003}', null);
        $status = GpoHealthStatusCalculator::calculate($gpo, totalLinks: 1, hasConflict: false);

        self::assertSame(GpoHealthStatus::Stale, $status);
    }

    #[Test]
    public function it_returns_orphaned_for_gpo_without_links(): void
    {
        $gpo = $this->makeGpo('{AAAA-0004}', 65539); // version > 0
        $status = GpoHealthStatusCalculator::calculate($gpo, totalLinks: 0, hasConflict: false);

        self::assertSame(GpoHealthStatus::Orphaned, $status);
    }

    #[Test]
    public function it_returns_conflicting_for_gpo_with_conflict(): void
    {
        $gpo = $this->makeGpo('{AAAA-0005}', 65539);
        $status = GpoHealthStatusCalculator::calculate($gpo, totalLinks: 2, hasConflict: true);

        self::assertSame(GpoHealthStatus::Conflicting, $status);
    }

    #[Test]
    public function stale_takes_priority_over_orphaned(): void
    {
        // GPO version 0 et sans lien : Stale prime sur Orphaned (D4 priorité)
        $gpo = $this->makeGpo('{AAAA-0006}', 0);
        $status = GpoHealthStatusCalculator::calculate($gpo, totalLinks: 0, hasConflict: false);

        self::assertSame(GpoHealthStatus::Stale, $status, 'Stale doit primer sur Orphaned (D4).');
    }

    #[Test]
    public function orphaned_takes_priority_over_conflicting(): void
    {
        // GPO version > 0, sans lien, mais conflict=true : Orphaned prime sur Conflicting
        $gpo = $this->makeGpo('{AAAA-0007}', 65539);
        $status = GpoHealthStatusCalculator::calculate($gpo, totalLinks: 0, hasConflict: true);

        self::assertSame(GpoHealthStatus::Orphaned, $status, 'Orphaned doit primer sur Conflicting (D4).');
    }

    #[Test]
    public function batch_calculation_returns_correct_statuses(): void
    {
        $gpos = collect([
            $this->makeGpo('{GUID-1}', 65539, 'se4_wallpaper'),   // → healthy (links=1)
            $this->makeGpo('{GUID-2}', 0, 'se4_proxy'),           // → stale
            $this->makeGpo('{GUID-3}', 65539, 'se4_shortcuts'),   // → orphaned (links=0)
        ]);

        $linksCount = [
            '{GUID-1}' => 1,
            '{GUID-2}' => 2,
            '{GUID-3}' => 0,
        ];

        $statuses = GpoHealthStatusCalculator::calculateBatch($gpos, $linksCount);

        self::assertSame(GpoHealthStatus::Healthy, $statuses['{GUID-1}']);
        self::assertSame(GpoHealthStatus::Stale, $statuses['{GUID-2}']);
        self::assertSame(GpoHealthStatus::Orphaned, $statuses['{GUID-3}']);
    }

    #[Test]
    public function batch_detects_conflicting_when_two_gpos_share_same_section(): void
    {
        // Deux GPOs avec wine pattern → conflit detecté
        $gpos = collect([
            $this->makeGpo('{GUID-W1}', 65539, 'se4_wine_apps'),
            $this->makeGpo('{GUID-W2}', 65539, 'wine_special'),
        ]);

        $linksCount = [
            '{GUID-W1}' => 1,
            '{GUID-W2}' => 1,
        ];

        $statuses = GpoHealthStatusCalculator::calculateBatch($gpos, $linksCount);

        self::assertSame(GpoHealthStatus::Conflicting, $statuses['{GUID-W1}']);
        self::assertSame(GpoHealthStatus::Conflicting, $statuses['{GUID-W2}']);
    }

    #[Test]
    public function batch_skips_conflict_detection_above_100_gpos(): void
    {
        // Si > 100 GPOs, la détection conflicting est skippée (R4 cap performance)
        $gpos = collect(array_map(
            fn($i) => $this->makeGpo("{GUID-{$i}}", 65539, "wine_gpo_{$i}"),
            range(1, 101)
        ));

        $linksCount = array_combine(
            array_map(fn($i) => "{GUID-{$i}}", range(1, 101)),
            array_fill(0, 101, 1)
        );

        $statuses = GpoHealthStatusCalculator::calculateBatch($gpos, $linksCount);

        // Aucun ne doit être conflicting (cap appliqué)
        foreach ($statuses as $status) {
            self::assertNotSame(GpoHealthStatus::Conflicting, $status);
        }
    }

    #[Test]
    public function health_status_enum_has_correct_values(): void
    {
        self::assertSame('healthy', GpoHealthStatus::Healthy->value);
        self::assertSame('orphaned', GpoHealthStatus::Orphaned->value);
        self::assertSame('conflicting', GpoHealthStatus::Conflicting->value);
        self::assertSame('stale', GpoHealthStatus::Stale->value);
    }
}

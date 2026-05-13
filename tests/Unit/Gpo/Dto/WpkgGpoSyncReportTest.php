<?php

declare(strict_types=1);

namespace Tests\Unit\Gpo\Dto;

use App\Gpo\Dto\WpkgGpoSyncReport;
use App\Gpo\Enums\WpkgGpoSyncSeverity;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests Unit DTO `WpkgGpoSyncReport` — Story 16.6 (AC1.1, AC1.6).
 *
 * Le DTO est `final readonly` : on vérifie l'immutabilité, la sérialisation
 * JSON et le helper `bearerCoverageRatio()`.
 */
class WpkgGpoSyncReportTest extends TestCase
{
    private function makeReport(array $overrides = []): WpkgGpoSyncReport
    {
        $defaults = [
            'gpoExists' => true,
            'gpoGuid' => '{AAAAAAAA-BBBB-CCCC-DDDD-EEEEEEEEEEEE}',
            'gpoDisplayName' => 'se4_wpkg',
            'gpoPath' => '\\\\example.org\\sysvol\\example.org\\Policies\\{AAAAAAAA-BBBB-CCCC-DDDD-EEEEEEEEEEEE}',
            'linkedOus' => ['OU=Computers,DC=example,DC=org'],
            'expectedHostsXmlUrl' => 'http://se4fs.example.org/wpkg/hosts.xml',
            'expectedProfilesXmlUrl' => 'http://se4fs.example.org/wpkg/profiles.xml',
            'templatePath' => '/usr/share/sambaedu/gpo/se4_wpkg.zip',
            'templateExists' => true,
            'templateLastModified' => new DateTimeImmutable('2026-05-01T10:00:00Z'),
            'detectedPlaceholders' => ['SE4FS_NAME', 'DOMAIN'],
            'unknownPlaceholders' => [],
            'bearerCoverage' => ['poste-01' => true, 'poste-02' => false],
            'bearerTableAvailable' => true,
            'severity' => WpkgGpoSyncSeverity::Warning,
            'messages' => ['1/2 postes sans secret Bearer'],
            'operationId' => 'op-1',
        ];

        return new WpkgGpoSyncReport(...array_merge($defaults, $overrides));
    }

    #[Test]
    public function it_is_readonly_and_serializable_to_array(): void
    {
        $r = $this->makeReport();
        $arr = $r->toArray();
        self::assertSame('se4_wpkg', $arr['gpoDisplayName']);
        self::assertSame('warning', $arr['severity']);
        self::assertSame('2026-05-01T10:00:00+00:00', $arr['templateLastModified']);
        self::assertSame(['SE4FS_NAME', 'DOMAIN'], $arr['detectedPlaceholders']);
    }

    #[Test]
    public function it_json_serializes(): void
    {
        $r = $this->makeReport();
        $json = json_encode($r);
        self::assertIsString($json);
        $decoded = json_decode($json, true);
        self::assertSame('warning', $decoded['severity']);
        self::assertSame('op-1', $decoded['operationId']);
    }

    #[Test]
    public function bearer_coverage_ratio_returns_correct_value(): void
    {
        $r = $this->makeReport(['bearerCoverage' => ['a' => true, 'b' => true, 'c' => false]]);
        self::assertEqualsWithDelta(2 / 3, $r->bearerCoverageRatio(), 0.001);
    }

    #[Test]
    public function bearer_coverage_ratio_is_null_when_table_absent(): void
    {
        $r = $this->makeReport(['bearerTableAvailable' => false]);
        self::assertNull($r->bearerCoverageRatio());
    }

    #[Test]
    public function bearer_coverage_ratio_is_null_when_no_workstations(): void
    {
        $r = $this->makeReport(['bearerCoverage' => []]);
        self::assertNull($r->bearerCoverageRatio());
    }

    #[Test]
    public function is_linked_returns_true_when_linked_ous_present(): void
    {
        $r = $this->makeReport(['linkedOus' => ['OU=Salles,DC=example,DC=org']]);
        self::assertTrue($r->isLinked());
    }

    #[Test]
    public function is_linked_returns_false_when_empty(): void
    {
        $r = $this->makeReport(['linkedOus' => []]);
        self::assertFalse($r->isLinked());
    }

    #[Test]
    public function severity_enum_merge_picks_highest(): void
    {
        $ok = WpkgGpoSyncSeverity::Ok;
        self::assertSame(WpkgGpoSyncSeverity::Error, $ok->merge(WpkgGpoSyncSeverity::Error));
        self::assertSame(WpkgGpoSyncSeverity::Warning, WpkgGpoSyncSeverity::Info->merge(WpkgGpoSyncSeverity::Warning));
        self::assertSame(WpkgGpoSyncSeverity::Warning, WpkgGpoSyncSeverity::Warning->merge(WpkgGpoSyncSeverity::Info));
    }

    #[Test]
    public function severity_exit_code_matches_unix_convention(): void
    {
        self::assertSame(0, WpkgGpoSyncSeverity::Ok->exitCode());
        self::assertSame(0, WpkgGpoSyncSeverity::Info->exitCode());
        self::assertSame(1, WpkgGpoSyncSeverity::Warning->exitCode());
        self::assertSame(2, WpkgGpoSyncSeverity::Error->exitCode());
    }
}

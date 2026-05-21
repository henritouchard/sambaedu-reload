<?php

declare(strict_types=1);

namespace Tests\Unit\Ipxe\Iso\Enums;

use App\Ipxe\Iso\Enums\WindowsIsoDownloadStatus;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Story 3.6 — AC1.3 — Tests unitaires de l'enum WindowsIsoDownloadStatus.
 */
class WindowsIsoDownloadStatusTest extends TestCase
{
    #[Test]
    public function it_exposes_six_cases_with_expected_values(): void
    {
        $cases = WindowsIsoDownloadStatus::cases();
        $values = array_map(static fn ($c) => $c->value, $cases);
        sort($values);

        self::assertSame([
            'cancelled',
            'downloading',
            'extracting',
            'failed',
            'pending',
            'success',
        ], $values);
    }

    #[Test]
    public function it_returns_running_for_pending_downloading_extracting(): void
    {
        self::assertTrue(WindowsIsoDownloadStatus::Pending->isRunning());
        self::assertTrue(WindowsIsoDownloadStatus::Downloading->isRunning());
        self::assertTrue(WindowsIsoDownloadStatus::Extracting->isRunning());
    }

    #[Test]
    public function it_returns_terminal_for_success_failed_cancelled(): void
    {
        self::assertTrue(WindowsIsoDownloadStatus::Success->isTerminal());
        self::assertTrue(WindowsIsoDownloadStatus::Failed->isTerminal());
        self::assertTrue(WindowsIsoDownloadStatus::Cancelled->isTerminal());
    }

    #[Test]
    public function it_running_and_terminal_are_mutually_exclusive(): void
    {
        foreach (WindowsIsoDownloadStatus::cases() as $status) {
            self::assertNotSame(
                $status->isRunning(),
                $status->isTerminal(),
                "Status {$status->value} doit être soit running soit terminal, pas les deux ni aucun.",
            );
        }
    }

    #[Test]
    public function it_returns_french_label_for_each_case(): void
    {
        self::assertSame('En attente', WindowsIsoDownloadStatus::Pending->label());
        self::assertSame('Téléchargement', WindowsIsoDownloadStatus::Downloading->label());
        self::assertSame('Extraction', WindowsIsoDownloadStatus::Extracting->label());
        self::assertSame('Succès', WindowsIsoDownloadStatus::Success->label());
        self::assertSame('Échec', WindowsIsoDownloadStatus::Failed->label());
        self::assertSame('Annulé', WindowsIsoDownloadStatus::Cancelled->label());
    }

    #[Test]
    public function it_returns_consistent_daisyui_badge_class(): void
    {
        $expected = [
            'pending'     => 'badge-ghost',
            'downloading' => 'badge-info',
            'extracting'  => 'badge-warning',
            'success'     => 'badge-success',
            'failed'      => 'badge-error',
            'cancelled'   => 'badge-neutral',
        ];
        foreach ($expected as $value => $class) {
            self::assertSame($class, WindowsIsoDownloadStatus::from($value)->badgeClass());
        }
    }
}

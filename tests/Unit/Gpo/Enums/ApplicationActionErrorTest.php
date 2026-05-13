<?php

declare(strict_types=1);

namespace Tests\Unit\Gpo\Enums;

use App\Gpo\Enums\ApplicationActionError;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Story 16.7 — AC4.1.
 *
 * Tests Unit pour `ApplicationActionError` : 7 cas + invalides + bitmask.
 * Vérifie strictement la parité bytes avec les constantes legacy
 * `SAMBAEDU_*_APP_ERROR` (cf. `sambaedu/includes/config.inc.php:36-44`).
 */
class ApplicationActionErrorTest extends TestCase
{
    #[Test]
    #[DataProvider('actionToBitmaskProvider')]
    public function from_action_maps_to_correct_bitmask(string $action, int $expectedBitmask): void
    {
        $error = ApplicationActionError::fromAction($action);
        self::assertSame($expectedBitmask, $error->bitmask());
        self::assertSame($expectedBitmask, $error->value);
    }

    /**
     * @return array<string, array{string, int}>
     */
    public static function actionToBitmaskProvider(): array
    {
        return [
            'startup'      => ['startup',      256],
            'shutdown'     => ['shutdown',     512],
            'logon'        => ['logon',        1024],
            'logoff'       => ['logoff',       2048],
            'logon-system' => ['logon-system', 4096],
            'logoff-system'=> ['logoff-system',8192],
            'wpkg'         => ['wpkg',         32768],
        ];
    }

    #[Test]
    public function from_action_throws_for_unknown_action(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Action inconnue pour ApplicationActionError : "remote-logon"');
        ApplicationActionError::fromAction('remote-logon');
    }

    #[Test]
    public function from_action_throws_for_empty_string(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ApplicationActionError::fromAction('');
    }

    #[Test]
    public function from_action_is_case_sensitive(): void
    {
        // Le legacy normalise les actions en lowercase via `HTMLPurifier->purify` ;
        // notre enum reste strict pour empêcher tout drift silencieux.
        $this->expectException(InvalidArgumentException::class);
        ApplicationActionError::fromAction('STARTUP');
    }

    #[Test]
    public function bitmask_is_alias_of_value(): void
    {
        foreach (ApplicationActionError::cases() as $case) {
            self::assertSame($case->value, $case->bitmask());
        }
    }

    #[Test]
    public function enum_exposes_seven_cases(): void
    {
        self::assertCount(7, ApplicationActionError::cases());
    }
}

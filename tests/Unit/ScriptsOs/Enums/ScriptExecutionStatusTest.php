<?php

declare(strict_types=1);

namespace Tests\Unit\ScriptsOs\Enums;

use App\ScriptsOs\Enums\ScriptExecutionStatus;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ScriptExecutionStatusTest extends TestCase
{
    #[Test]
    public function it_resolves_known_values(): void
    {
        self::assertSame(ScriptExecutionStatus::SUCCESS, ScriptExecutionStatus::tryFrom('success'));
        self::assertSame(ScriptExecutionStatus::FAILURE, ScriptExecutionStatus::tryFrom('failure'));
        self::assertSame(ScriptExecutionStatus::SKIPPED, ScriptExecutionStatus::tryFrom('skipped'));
        self::assertSame(ScriptExecutionStatus::TIMEOUT, ScriptExecutionStatus::tryFrom('timeout'));
    }

    #[Test]
    public function it_returns_null_for_unknown(): void
    {
        self::assertNull(ScriptExecutionStatus::tryFrom('partial'));
    }

    #[Test]
    public function values_lists_all_cases(): void
    {
        self::assertSame(
            ['success', 'failure', 'skipped', 'timeout'],
            ScriptExecutionStatus::values(),
        );
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit\ScriptsOs\Enums;

use App\ScriptsOs\Enums\ScriptExecutionAction;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Story 16.12 — AC1.3.
 */
class ScriptExecutionActionTest extends TestCase
{
    #[Test]
    public function it_resolves_known_values(): void
    {
        self::assertSame(ScriptExecutionAction::LOGON, ScriptExecutionAction::tryFrom('logon'));
        self::assertSame(ScriptExecutionAction::STARTUP, ScriptExecutionAction::tryFrom('startup'));
        self::assertSame(ScriptExecutionAction::SHUTDOWN, ScriptExecutionAction::tryFrom('shutdown'));
        self::assertSame(ScriptExecutionAction::LOGOFF, ScriptExecutionAction::tryFrom('logoff'));
        self::assertSame(ScriptExecutionAction::ONESHOT, ScriptExecutionAction::tryFrom('oneshot'));
    }

    #[Test]
    public function it_returns_null_for_unknown(): void
    {
        self::assertNull(ScriptExecutionAction::tryFrom('foobar'));
    }

    #[Test]
    public function values_lists_all_cases(): void
    {
        self::assertSame(
            ['logon', 'startup', 'shutdown', 'logoff', 'oneshot'],
            ScriptExecutionAction::values(),
        );
    }
}

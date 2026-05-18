<?php

declare(strict_types=1);

namespace Tests\Unit\ScriptsOs\Enums;

use App\ScriptsOs\Enums\ScriptExecutionOs;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ScriptExecutionOsTest extends TestCase
{
    #[Test]
    public function it_resolves_known_values(): void
    {
        self::assertSame(ScriptExecutionOs::WINDOWS, ScriptExecutionOs::tryFrom('windows'));
        self::assertSame(ScriptExecutionOs::LINUX, ScriptExecutionOs::tryFrom('linux'));
    }

    #[Test]
    public function it_returns_null_for_unknown(): void
    {
        self::assertNull(ScriptExecutionOs::tryFrom('darwin'));
    }

    #[Test]
    public function values_lists_all_cases(): void
    {
        self::assertSame(['windows', 'linux'], ScriptExecutionOs::values());
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit\ScriptsOs\Enums;

use App\ScriptsOs\Enums\ScriptExecutionSource;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ScriptExecutionSourceTest extends TestCase
{
    #[Test]
    public function it_resolves_known_values(): void
    {
        self::assertSame(ScriptExecutionSource::MANAGED_SCRIPT, ScriptExecutionSource::tryFrom('managed_script'));
        self::assertSame(ScriptExecutionSource::GPO_APPLICATIONS, ScriptExecutionSource::tryFrom('gpo_applications'));
        self::assertSame(ScriptExecutionSource::WPKG_POST, ScriptExecutionSource::tryFrom('wpkg_post'));
        self::assertSame(ScriptExecutionSource::MANUAL, ScriptExecutionSource::tryFrom('manual'));
    }

    #[Test]
    public function it_returns_null_for_unknown(): void
    {
        self::assertNull(ScriptExecutionSource::tryFrom('cron_job'));
    }

    #[Test]
    public function values_lists_all_cases(): void
    {
        self::assertSame(
            ['managed_script', 'gpo_applications', 'wpkg_post', 'manual'],
            ScriptExecutionSource::values(),
        );
    }
}

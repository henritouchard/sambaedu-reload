<?php

declare(strict_types=1);

namespace Tests\Unit\ScriptsOs\Support;

use App\ScriptsOs\Support\Humanize;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class HumanizeTest extends TestCase
{
    #[Test]
    public function duration_formats_milliseconds(): void
    {
        self::assertSame('45ms', Humanize::duration(45));
    }

    #[Test]
    public function duration_formats_seconds(): void
    {
        self::assertSame('1.2s', Humanize::duration(1234));
    }

    #[Test]
    public function duration_formats_minutes(): void
    {
        self::assertSame('2.0 min', Humanize::duration(120000));
    }

    #[Test]
    public function duration_formats_hours(): void
    {
        self::assertSame('1.5 h', Humanize::duration(5400000));
    }

    #[Test]
    public function duration_handles_negative(): void
    {
        self::assertSame('0ms', Humanize::duration(-100));
    }

    #[Test]
    public function bytes_formats_b(): void
    {
        self::assertSame('512 B', Humanize::bytes(512));
    }

    #[Test]
    public function bytes_formats_kib(): void
    {
        self::assertSame('1.50 KiB', Humanize::bytes(1536));
    }

    #[Test]
    public function bytes_formats_mib(): void
    {
        self::assertSame('1.00 MiB', Humanize::bytes(1024 * 1024));
    }
}

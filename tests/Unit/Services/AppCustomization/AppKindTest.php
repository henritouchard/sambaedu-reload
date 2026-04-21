<?php

declare(strict_types=1);

namespace Tests\Unit\Services\AppCustomization;

use App\Enums\AppKind;
use App\Services\AppCustomization\Firefox\FirefoxPolicyAdapter;
use App\Services\AppCustomization\Thunderbird\ThunderbirdPolicyAdapter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests unit — enum AppKind (AC 1).
 */
class AppKindTest extends TestCase
{
    #[Test]
    public function exposes_firefox_and_thunderbird_cases(): void
    {
        $this->assertSame('firefox', AppKind::Firefox->value);
        $this->assertSame('thunderbird', AppKind::Thunderbird->value);
    }

    #[Test]
    public function aliases_match_slug(): void
    {
        $this->assertSame('firefox', AppKind::Firefox->alias());
        $this->assertSame('thunderbird', AppKind::Thunderbird->alias());
    }

    #[Test]
    public function labels_are_fr(): void
    {
        $this->assertSame('Firefox', AppKind::Firefox->label());
        $this->assertSame('Thunderbird', AppKind::Thunderbird->label());
    }

    #[Test]
    public function adapter_classes_point_to_existing_classes(): void
    {
        $this->assertSame(FirefoxPolicyAdapter::class, AppKind::Firefox->adapterClass());
        $this->assertSame(ThunderbirdPolicyAdapter::class, AppKind::Thunderbird->adapterClass());
        $this->assertTrue(class_exists(AppKind::Firefox->adapterClass()));
        $this->assertTrue(class_exists(AppKind::Thunderbird->adapterClass()));
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit\Services\AppCustomization;

use App\Enums\AppKind;
use App\Services\AppCustomization\Firefox\FirefoxPolicyAdapter;
use App\Services\AppCustomization\Thunderbird\ThunderbirdPolicyAdapter;
use App\Services\AppCustomization\AppPolicyRegistry;
use App\Services\AppCustomization\Contracts\AppPolicyAdapter;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tests unit — registry (AC 1).
 */
class AppPolicyRegistryTest extends TestCase
{
    private function registry(): AppPolicyRegistry
    {
        return $this->app->make(AppPolicyRegistry::class);
    }

    #[Test]
    public function resolves_firefox_adapter_as_singleton(): void
    {
        $registry = $this->registry();
        $a = $registry->resolve(AppKind::Firefox);
        $b = $registry->resolve(AppKind::Firefox);
        $this->assertInstanceOf(FirefoxPolicyAdapter::class, $a);
        $this->assertSame($a, $b, 'le registry doit cacher les instances (singleton per-request)');
    }

    #[Test]
    public function resolves_thunderbird_adapter(): void
    {
        $adapter = $this->registry()->resolve(AppKind::Thunderbird);
        $this->assertInstanceOf(ThunderbirdPolicyAdapter::class, $adapter);
    }

    #[Test]
    public function resolves_from_string_alias(): void
    {
        $adapter = $this->registry()->resolve('firefox');
        $this->assertInstanceOf(FirefoxPolicyAdapter::class, $adapter);
    }

    #[Test]
    public function unknown_kind_throws_invalid_argument(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->registry()->resolve('doesnotexist');
    }

    #[Test]
    public function register_overrides_adapter_class(): void
    {
        $custom = new class implements AppPolicyAdapter {
            public function getTemplate(): array { return []; }
            public function applyAuto(array $t, array $c): array { return $t; }
            public function mergeOverrides(array $b, array $o): array { return $b; }
            public function renderFormComponent(): string { return 'custom-form'; }
            public function exportToFs(array $p, string $path): bool { return true; }
            public function validatePolicies(array $p): array { return []; }
            public function stripNonWhitelistedOverrides(array $p): array { return $p; }
        };

        $registry = $this->registry();
        $registry->register(AppKind::Firefox, $custom::class);

        $this->app->bind($custom::class, fn() => $custom);

        $resolved = $registry->resolve(AppKind::Firefox);
        $this->assertSame('custom-form', $resolved->renderFormComponent());
    }
}

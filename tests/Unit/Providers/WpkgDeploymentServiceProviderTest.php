<?php

declare(strict_types=1);

namespace Tests\Unit\Providers;

use App\Providers\WpkgDeploymentServiceProvider;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 15.1 / AC4.1 — couvre la logique de détection du check démarrage
 * (méthode pure `checkPaths()`). Le `boot()` reste skippé en environnement
 * `testing`, donc on teste la méthode extraite directement.
 */
class WpkgDeploymentServiceProviderTest extends TestCase
{
    private WpkgDeploymentServiceProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provider = new WpkgDeploymentServiceProvider($this->app);
    }

    #[Test]
    public function check_paths_returns_no_violation_when_all_paths_are_readable_and_writable(): void
    {
        $tmp = $this->makeTempDir();
        Config::set('sambaedu.wpkg.deploy_path', $tmp);

        $violations = $this->provider->checkPaths(['sambaedu.wpkg.deploy_path']);

        $this->assertSame([], $violations);
    }

    #[Test]
    public function check_paths_reports_missing_directory(): void
    {
        Config::set('sambaedu.wpkg.deploy_path', '/var/sambaedu/does-not-exist-' . uniqid());

        $violations = $this->provider->checkPaths(['sambaedu.wpkg.deploy_path']);

        $this->assertCount(1, $violations);
        $this->assertSame('sambaedu.wpkg.deploy_path', $violations[0]['config_key']);
        $this->assertFalse($violations[0]['exists']);
        $this->assertFalse($violations[0]['readable']);
        $this->assertFalse($violations[0]['writable']);
    }

    #[Test]
    public function check_paths_reports_directory_without_write_permission(): void
    {
        $tmp = $this->makeTempDir();
        chmod($tmp, 0500);
        // Skip si root : root ignore les bits Unix, le test perdrait son sens.
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            chmod($tmp, 0700);
            $this->markTestSkipped('Test non significatif sous root (ignore les permissions).');
        }

        Config::set('sambaedu.wpkg.deploy_path', $tmp);

        $violations = $this->provider->checkPaths(['sambaedu.wpkg.deploy_path']);

        chmod($tmp, 0700);

        $this->assertCount(1, $violations);
        $this->assertTrue($violations[0]['exists']);
        $this->assertFalse($violations[0]['writable']);
    }

    #[Test]
    public function check_paths_skips_keys_with_empty_config_value(): void
    {
        Config::set('sambaedu.wpkg.deploy_path', '');

        $violations = $this->provider->checkPaths(['sambaedu.wpkg.deploy_path']);

        $this->assertSame([], $violations);
    }

    #[Test]
    public function check_paths_collects_multiple_violations(): void
    {
        Config::set('sambaedu.wpkg.deploy_path', '/var/missing-a-' . uniqid());
        Config::set('sambaedu.wpkg.ini_path', '/var/missing-b-' . uniqid());

        $violations = $this->provider->checkPaths([
            'sambaedu.wpkg.deploy_path',
            'sambaedu.wpkg.ini_path',
        ]);

        $this->assertCount(2, $violations);
        $this->assertSame('sambaedu.wpkg.deploy_path', $violations[0]['config_key']);
        $this->assertSame('sambaedu.wpkg.ini_path', $violations[1]['config_key']);
    }

    private function makeTempDir(): string
    {
        $path = sys_get_temp_dir() . '/wpkg-sp-test-' . uniqid('', true);
        mkdir($path, 0700, true);
        return $path;
    }
}

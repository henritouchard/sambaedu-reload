<?php

declare(strict_types=1);

namespace Tests\Unit\Providers;

use App\Providers\WpkgDeploymentServiceProvider;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 15.1 / AC4.1 — couvre la logique de détection ET de création
 * automatique du check démarrage (méthode `ensurePaths()`). Le `boot()`
 * reste skippé en environnement `testing`, donc on teste la méthode
 * extraite directement.
 */
class WpkgDeploymentServiceProviderTest extends TestCase
{
    private WpkgDeploymentServiceProvider $provider;

    /** @var list<string> */
    private array $cleanup = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->provider = new WpkgDeploymentServiceProvider($this->app);
    }

    protected function tearDown(): void
    {
        foreach ($this->cleanup as $path) {
            if (is_dir($path)) {
                @chmod($path, 0700);
                @rmdir($path);
            }
        }
        parent::tearDown();
    }

    #[Test]
    public function ensure_paths_returns_no_violation_when_all_paths_are_readable_and_writable(): void
    {
        $tmp = $this->makeTempDir();
        Config::set('sambaedu.wpkg.deploy_path', $tmp);

        $violations = $this->provider->ensurePaths(['sambaedu.wpkg.deploy_path']);

        $this->assertSame([], $violations);
    }

    #[Test]
    public function ensure_paths_creates_a_missing_directory_under_writable_parent(): void
    {
        $parent = $this->makeTempDir();
        $missing = $parent . '/nested/auto-created';
        $this->cleanup[] = $missing;
        $this->cleanup[] = dirname($missing);
        Config::set('sambaedu.wpkg.deploy_path', $missing);

        $violations = $this->provider->ensurePaths(['sambaedu.wpkg.deploy_path']);

        $this->assertSame([], $violations, 'Le dossier doit avoir été créé silencieusement.');
        $this->assertDirectoryExists($missing);
    }

    #[Test]
    public function ensure_paths_reports_violation_when_creation_is_impossible(): void
    {
        // Parent non-écrivable → mkdir échoue, violation attendue.
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('Test non significatif sous root (ignore les permissions).');
        }
        $parent = $this->makeTempDir();
        chmod($parent, 0500);
        $missing = $parent . '/cannot-create';
        Config::set('sambaedu.wpkg.deploy_path', $missing);

        $violations = $this->provider->ensurePaths(['sambaedu.wpkg.deploy_path']);

        chmod($parent, 0700);

        $this->assertCount(1, $violations);
        $this->assertSame('sambaedu.wpkg.deploy_path', $violations[0]['config_key']);
        $this->assertTrue($violations[0]['create_attempted']);
        $this->assertFalse($violations[0]['create_succeeded']);
        $this->assertFalse($violations[0]['exists']);
    }

    #[Test]
    public function ensure_paths_reports_directory_without_write_permission(): void
    {
        $tmp = $this->makeTempDir();
        chmod($tmp, 0500);
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            chmod($tmp, 0700);
            $this->markTestSkipped('Test non significatif sous root (ignore les permissions).');
        }

        Config::set('sambaedu.wpkg.deploy_path', $tmp);

        $violations = $this->provider->ensurePaths(['sambaedu.wpkg.deploy_path']);

        chmod($tmp, 0700);

        $this->assertCount(1, $violations);
        $this->assertTrue($violations[0]['exists']);
        $this->assertFalse($violations[0]['writable']);
        $this->assertFalse($violations[0]['create_attempted'], 'mkdir ne doit pas être tenté si le dossier existe déjà.');
    }

    #[Test]
    public function ensure_paths_skips_keys_with_empty_config_value(): void
    {
        Config::set('sambaedu.wpkg.deploy_path', '');

        $violations = $this->provider->ensurePaths(['sambaedu.wpkg.deploy_path']);

        $this->assertSame([], $violations);
    }

    #[Test]
    public function ensure_paths_collects_multiple_violations(): void
    {
        $parent = $this->makeTempDir();
        chmod($parent, 0500);
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            chmod($parent, 0700);
            $this->markTestSkipped('Test non significatif sous root.');
        }
        Config::set('sambaedu.wpkg.deploy_path', $parent . '/a');
        Config::set('sambaedu.wpkg.ini_path', $parent . '/b');

        $violations = $this->provider->ensurePaths([
            'sambaedu.wpkg.deploy_path',
            'sambaedu.wpkg.ini_path',
        ]);

        chmod($parent, 0700);

        $this->assertCount(2, $violations);
        $this->assertSame('sambaedu.wpkg.deploy_path', $violations[0]['config_key']);
        $this->assertSame('sambaedu.wpkg.ini_path', $violations[1]['config_key']);
    }

    private function makeTempDir(): string
    {
        $path = sys_get_temp_dir() . '/wpkg-sp-test-' . uniqid('', true);
        mkdir($path, 0700, true);
        $this->cleanup[] = $path;
        return $path;
    }
}

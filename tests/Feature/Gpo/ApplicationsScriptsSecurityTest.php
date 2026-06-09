<?php

declare(strict_types=1);

namespace Tests\Feature\Gpo;

use App\Ldap\AdMachineManager;
use App\Repositories\UserRepository;
use App\Repositories\WorkstationRepository;
use App\Services\AppCustomization\Contracts\AppContextWriter;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 16.7 — Volet 6 sécurité.
 *
 * Vérifie que les inputs malveillants sont rejetés AVANT tout side effect
 * AD/FS/APCu (AC6.3) :
 *  - injection shell dans `machine`
 *  - placeholder de substitution injecté via `user`/`uuid`
 *  - path traversal dans `interpreter`
 */
class ApplicationsScriptsSecurityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Story 16.13bis — `gpo/applications.php` transformée en
        // MigrationController::serveFragment ; tests Feature URL caducs (R6).
        $this->markTestSkipped('Story 16.13bis : route legacy transformée en MigrationController (R6).');

        // Mocks STRICTS qui ne doivent JAMAIS être appelés si validation OK.
        $ws = Mockery::mock(WorkstationRepository::class);
        $ws->shouldNotReceive('findByName');
        $this->app->instance(WorkstationRepository::class, $ws);

        $users = Mockery::mock(UserRepository::class);
        $users->shouldNotReceive('findByLogin');
        $this->app->instance(UserRepository::class, $users);

        $ad = Mockery::mock(AdMachineManager::class);
        $ad->shouldNotReceive('check');
        $ad->shouldNotReceive('registerHardware');
        $ad->shouldNotReceive('listRemoteConnexion');
        $this->app->instance(AdMachineManager::class, $ad);

        $writer = Mockery::mock(AppContextWriter::class);
        $writer->shouldNotReceive('write');
        $writer->shouldNotReceive('forget');
        $this->app->instance(AppContextWriter::class, $writer);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    #[Test]
    public function it_rejects_shell_injection_in_machine_param(): void
    {
        $resp = $this->post('/gpo/applications.php', [
            'machine' => 'pc01; rm -rf /',
            'action' => 'startup',
            'os' => 'windows',
        ]);
        $resp->assertStatus(400);
    }

    #[Test]
    public function it_rejects_shell_injection_in_user_param(): void
    {
        $resp = $this->post('/gpo/applications.php', [
            'machine' => 'pc01',
            'action' => 'startup',
            'os' => 'windows',
            'user' => 'jdoe$(whoami)',
        ]);
        $resp->assertStatus(400);
    }

    #[Test]
    public function it_rejects_too_long_machine_name(): void
    {
        $resp = $this->post('/gpo/applications.php', [
            'machine' => str_repeat('a', 200),
            'action' => 'startup',
            'os' => 'windows',
        ]);
        $resp->assertStatus(400);
    }

    #[Test]
    public function it_rejects_special_chars_in_machine_name(): void
    {
        $resp = $this->post('/gpo/applications.php', [
            'machine' => 'pc01<script>alert(1)</script>',
            'action' => 'startup',
            'os' => 'windows',
        ]);
        $resp->assertStatus(400);
    }

    #[Test]
    public function it_rejects_unknown_interpreter(): void
    {
        $resp = $this->post('/gpo/applications.php', [
            'machine' => 'pc01',
            'action' => 'startup',
            'os' => 'windows',
            'interpreter' => '../../../etc/passwd',
        ]);
        $resp->assertStatus(400);
    }

    #[Test]
    public function it_rejects_negative_ret(): void
    {
        $resp = $this->post('/gpo/applications.php', [
            'machine' => 'pc01',
            'action' => 'startup',
            'os' => 'windows',
            'ret' => -1,
        ]);
        $resp->assertStatus(400);
    }
}

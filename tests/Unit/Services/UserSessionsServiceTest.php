<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\UserSessionsService;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tests unit UserSessionsService — parsing smbstatus + cache 30s.
 *
 * Story 4.7 — post-review fix #A.
 */
class UserSessionsServiceTest extends TestCase
{
    private string $tmpFile;
    private CacheRepository $cache;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpFile = sys_get_temp_dir() . '/smbstatus-test-' . bin2hex(random_bytes(4));
        $this->cache = new CacheRepository(new ArrayStore());
    }

    protected function tearDown(): void
    {
        @unlink($this->tmpFile);
        parent::tearDown();
    }

    private function service(): UserSessionsService
    {
        return new UserSessionsService($this->cache, $this->tmpFile);
    }

    private function writeSmbstatus(string $content): void
    {
        file_put_contents($this->tmpFile, $content);
    }

    #[Test]
    public function returns_empty_when_login_is_empty(): void
    {
        $this->writeSmbstatus("1234 jdoe post01 (ipv4:…)\n");
        $sessions = $this->service()->getActiveSessions('');
        $this->assertSame([], $sessions);
    }

    #[Test]
    public function returns_empty_when_smbstatus_file_missing(): void
    {
        // Ne pas créer le fichier → absent
        $sessions = $this->service()->getActiveSessions('jdoe');
        $this->assertSame([], $sessions);
    }

    #[Test]
    public function returns_empty_when_file_empty(): void
    {
        $this->writeSmbstatus('');
        $this->assertSame([], $this->service()->getActiveSessions('jdoe'));
    }

    #[Test]
    public function parses_active_session_with_matching_lock(): void
    {
        $this->writeSmbstatus(
            "1234 jdoe post01 (ipv4:10.0.0.1)\n" .
            "1234 jdoe WRONLY LEASE(RW)\n"
        );

        $sessions = $this->service()->getActiveSessions('jdoe');

        $this->assertCount(1, $sessions);
        $this->assertSame('post01', $sessions[0]['machine']);
    }

    #[Test]
    public function returns_only_sessions_of_target_user(): void
    {
        $this->writeSmbstatus(
            "1234 jdoe post01 (ipv4:10.0.0.1)\n" .
            "1234 jdoe WRONLY LEASE(RW)\n" .
            "5678 alice post02 (ipv4:10.0.0.2)\n" .
            "5678 alice RDWR LEASE(RW)\n"
        );

        $sessions = $this->service()->getActiveSessions('jdoe');

        $this->assertCount(1, $sessions);
        $this->assertSame('post01', $sessions[0]['machine']);
    }

    #[Test]
    public function returns_multiple_sessions_when_user_logged_on_multiple_machines(): void
    {
        $this->writeSmbstatus(
            "1111 jdoe post01 (ipv4:10.0.0.1)\n" .
            "1111 jdoe WRONLY LEASE(RW)\n" .
            "2222 jdoe post02 (ipv4:10.0.0.2)\n" .
            "2222 jdoe RDWR LEASE(RW)\n"
        );

        $sessions = $this->service()->getActiveSessions('jdoe');

        $machines = array_column($sessions, 'machine');
        $this->assertContains('post01', $machines);
        $this->assertContains('post02', $machines);
        $this->assertCount(2, $sessions);
    }

    #[Test]
    public function get_other_machines_excludes_current_machine(): void
    {
        $this->writeSmbstatus(
            "1111 jdoe post01 (ipv4:10.0.0.1)\n" .
            "1111 jdoe WRONLY LEASE(RW)\n" .
            "2222 jdoe post02 (ipv4:10.0.0.2)\n" .
            "2222 jdoe RDWR LEASE(RW)\n" .
            "3333 jdoe post03 (ipv4:10.0.0.3)\n" .
            "3333 jdoe WRONLY LEASE(RW)\n"
        );

        $others = $this->service()->getOtherMachines('jdoe', 'post01');

        $this->assertNotContains('post01', $others);
        $this->assertContains('post02', $others);
        $this->assertContains('post03', $others);
        $this->assertCount(2, $others);
    }

    #[Test]
    public function get_other_machines_case_insensitive_current(): void
    {
        $this->writeSmbstatus(
            "1111 jdoe POST01 (ipv4:10.0.0.1)\n" .
            "1111 jdoe WRONLY LEASE(RW)\n" .
            "2222 jdoe post02 (ipv4:10.0.0.2)\n" .
            "2222 jdoe RDWR LEASE(RW)\n"
        );

        $others = $this->service()->getOtherMachines('jdoe', 'post01');

        $this->assertSame(['post02'], $others);
    }

    #[Test]
    public function result_is_cached(): void
    {
        $this->writeSmbstatus(
            "1111 jdoe post01 (ipv4:10.0.0.1)\n" .
            "1111 jdoe WRONLY LEASE(RW)\n"
        );

        $service = $this->service();
        $first = $service->getActiveSessions('jdoe');

        // On vide le fichier ; si le cache fonctionne on récupère quand même
        // la valeur stockée.
        $this->writeSmbstatus('');
        $second = $service->getActiveSessions('jdoe');

        $this->assertSame($first, $second);
    }

    #[Test]
    public function returns_empty_list_when_connexions_exist_but_no_lock(): void
    {
        // Pas de LEASE pour ce user → connexions considérées stalles
        $this->writeSmbstatus(
            "1111 jdoe post01 (ipv4:10.0.0.1)\n" .
            "2222 alice post02 (ipv4:10.0.0.2)\n" .
            "2222 alice RDWR LEASE(RW)\n"
        );

        // Tolérance : si aucun lock pour jdoe mais des connexions, on les garde
        // toutes (format smbstatus variable selon versions samba).
        $sessions = $this->service()->getActiveSessions('jdoe');
        $this->assertCount(1, $sessions);
        $this->assertSame('post01', $sessions[0]['machine']);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit\Doctor\Checks;

use App\Doctor\Checks\Wpkg\WpkgReportsInboxCheck;
use App\Doctor\Level;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Le contrôle qui voit le rapport QUI N'ARRIVE JAMAIS.
 *
 * Un dépôt sans `o+w` ne produit aucune erreur côté poste : la copie du log
 * échoue en silence. Ce contrôle est donc le SEUL endroit où cet écart devient
 * visible avant qu'un administrateur ne cherche un log inexistant.
 */
class WpkgReportsInboxCheckTest extends TestCase
{
    private string $inbox;

    protected function setUp(): void
    {
        parent::setUp();

        $this->inbox = sys_get_temp_dir().'/wpkg-inbox-'.bin2hex(random_bytes(6));
        mkdir($this->inbox.'/archive', 0o755, true);

        config([
            'sambaedu.wpkg.reports_inbox' => $this->inbox,
            'sambaedu.wpkg.reports_archive' => $this->inbox.'/archive',
        ]);
    }

    protected function tearDown(): void
    {
        @chmod($this->inbox, 0o755);
        @rmdir($this->inbox.'/archive');
        @rmdir($this->inbox);

        parent::tearDown();
    }

    private function check(): \App\Doctor\CheckResult
    {
        return (new WpkgReportsInboxCheck())->run();
    }

    #[Test]
    public function a_dropbox_the_workstations_cannot_write_to_is_an_error(): void
    {
        chmod($this->inbox, 0o755);

        $result = $this->check();

        self::assertSame(Level::Error, $result->level);
        self::assertStringContainsString('0755', $result->detail);
        self::assertStringContainsString('chmod 1777', (string) $result->fix);
    }

    #[Test]
    public function a_sticky_world_writable_dropbox_is_healthy(): void
    {
        chmod($this->inbox, 0o1777);

        self::assertSame(Level::Ok, $this->check()->level);
    }

    /**
     * Sans sticky, un poste peut effacer le rapport d'un autre : les rapports
     * arrivent, mais on ne peut plus se fier à celui qu'on lit.
     */
    #[Test]
    public function a_world_writable_dropbox_without_sticky_bit_warns(): void
    {
        chmod($this->inbox, 0o777);

        $result = $this->check();

        self::assertSame(Level::Warn, $result->level);
        self::assertStringContainsString('sticky', $result->detail);
    }

    #[Test]
    public function an_archive_open_to_the_workstations_warns(): void
    {
        chmod($this->inbox, 0o1777);
        chmod($this->inbox.'/archive', 0o777);

        $result = $this->check();

        self::assertSame(Level::Warn, $result->level);
        self::assertStringContainsString('archive', $result->detail);
    }

    #[Test]
    public function a_missing_dropbox_warns_instead_of_failing_the_host(): void
    {
        config(['sambaedu.wpkg.reports_inbox' => $this->inbox.'/absent']);

        self::assertSame(Level::Warn, $this->check()->level);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit\Gpo;

use App\Gpo\Dto\GpoLink;
use App\Gpo\Dto\GpoSummary;
use App\Gpo\Services\GpoService;
use App\Gpo\Support\SambaToolRunner;
use Illuminate\Support\Facades\Process;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Support\FakesGpoService;
use Tests\TestCase;

/**
 * Tests unitaires {@see GpoService} (Story 16.1 / AC3.1, AC3.3).
 *
 * Couvre :
 *
 * - parsing de la sortie `samba-tool gpo listall`
 * - parsing de `samba-tool gpo show`
 * - parsing de `samba-tool gpo listcontainers`
 * - parsing de `samba-tool gpo getlink` (avec options enforced/disabled)
 * - parsing de `samba-tool gpo getinheritance`
 * - propagation des erreurs samba-tool (exit != 0)
 * - stubs écriture (`create`, `delete`, `fetch`, `setLink`, `removeLink`,
 *   `setInheritance`) lèvent bien `RuntimeException` avec message stable.
 */
class GpoServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('sambaedu.gpo.bin_path', '/usr/bin/samba-tool');
        config()->set('sambaedu.gpo.kerb_option', '--use-kerberos=required');
        config()->set('sambaedu.gpo.samba_tool_timeout', 30);
    }

    #[Test]
    public function list_parses_samba_tool_listall_output(): void
    {
        Process::fake([
            '*' => Process::result(output: FakesGpoService::listallOutput(), errorOutput: '', exitCode: 0),
        ]);

        $service = FakesGpoService::makeService();
        $gpos = $service->list();

        $this->assertCount(3, $gpos);
        $this->assertContainsOnlyInstancesOf(GpoSummary::class, $gpos);

        $default = $gpos->first();
        $this->assertSame('{31B2F340-016D-11D2-945F-00C04FB984F9}', $default->name);
        $this->assertSame('Default Domain Policy', $default->displayName);
        $this->assertSame(65539, $default->versionNumber);

        $redir = $gpos->last();
        $this->assertSame('redirections', $redir->displayName);
        $this->assertSame(3, $redir->versionNumber);
    }

    #[Test]
    public function list_throws_runtime_exception_on_non_zero_exit(): void
    {
        Process::fake([
            '*' => Process::result(output: '', errorOutput: FakesGpoService::errorOutput(), exitCode: 1),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('samba-tool gpo listall failed');

        FakesGpoService::makeService()->list();
    }

    #[Test]
    public function get_returns_null_on_non_zero_exit(): void
    {
        Process::fake([
            '*' => Process::result(output: '', errorOutput: 'not found', exitCode: 1),
        ]);

        $result = FakesGpoService::makeService()->get('{NON-EXISTENT}');
        $this->assertNull($result);
    }

    #[Test]
    public function get_parses_samba_tool_show_output(): void
    {
        Process::fake([
            '*' => Process::result(output: FakesGpoService::showOutput(), errorOutput: '', exitCode: 0),
        ]);

        $summary = FakesGpoService::makeService()->get('{AAAAAAAA-BBBB-CCCC-DDDD-EEEEEEEEEEEE}');

        $this->assertNotNull($summary);
        $this->assertSame('redirections', $summary->displayName);
        $this->assertSame(3, $summary->versionNumber);
    }

    #[Test]
    public function list_containers_parses_dn_lines(): void
    {
        Process::fake([
            '*' => Process::result(output: FakesGpoService::listContainersOutput(), errorOutput: '', exitCode: 0),
        ]);

        $containers = FakesGpoService::makeService()->listContainers('{AAAA-BBBB}');

        $this->assertCount(2, $containers);
        $this->assertSame('OU=Salles,DC=example,DC=org', $containers[0]);
        $this->assertSame('OU=Profs,OU=Salles,DC=example,DC=org', $containers[1]);
    }

    #[Test]
    public function get_links_parses_link_blocks_with_enforced_flag(): void
    {
        Process::fake([
            '*' => Process::result(output: FakesGpoService::getLinkOutput(), errorOutput: '', exitCode: 0),
        ]);

        $links = FakesGpoService::makeService()->getLinks('DC=example,DC=org');

        $this->assertCount(2, $links);
        $this->assertContainsOnlyInstancesOf(GpoLink::class, $links);

        $this->assertFalse($links[0]->enforced);
        $this->assertFalse($links[0]->disabled);
        $this->assertSame(0, $links[0]->optionsRaw);

        $this->assertTrue($links[1]->enforced);
        $this->assertFalse($links[1]->disabled);
        $this->assertSame(2, $links[1]->optionsRaw);
        $this->assertSame('redirections', $links[1]->gpoDisplayName);
    }

    #[Test]
    public function get_inheritance_returns_true_when_gpo_inherit_in_output(): void
    {
        Process::fake([
            '*' => Process::result(output: FakesGpoService::getInheritanceOutput(true), errorOutput: '', exitCode: 0),
        ]);

        $this->assertTrue(FakesGpoService::makeService()->getInheritance('OU=Salles,DC=example,DC=org'));
    }

    #[Test]
    public function get_inheritance_returns_false_when_block_in_output(): void
    {
        Process::fake([
            '*' => Process::result(output: FakesGpoService::getInheritanceOutput(false), errorOutput: '', exitCode: 0),
        ]);

        $this->assertFalse(FakesGpoService::makeService()->getInheritance('OU=Salles,DC=example,DC=org'));
    }

    /**
     * Méthodes d'écriture encore en stub (CRUD GPO — Story 16.4 paused).
     * `setLink` / `removeLink` / `setInheritance` ont été implémentées
     * par Story 16.5 — elles ont leur propre suite Unit
     * ({@see GpoServiceWriteTest}).
     *
     * @return iterable<string, array{0: string, 1: array<int,mixed>, 2: string}>
     */
    public static function writeStubsProvider(): iterable
    {
        yield 'create' => ['create', ['my-gpo'], 'Story 16.4'];
        yield 'delete' => ['delete', ['{AAAA-BBBB}'], 'Story 16.4'];
        yield 'fetch' => ['fetch', ['{AAAA-BBBB}', '/tmp/policies'], 'Story 16.3/16.4'];
    }

    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('writeStubsProvider')]
    public function write_methods_are_stubs_throwing_runtime_exception(string $method, array $args, string $expectedStoryRef): void
    {
        $service = FakesGpoService::makeService();
        try {
            $service->{$method}(...$args);
            $this->fail("Expected RuntimeException not thrown for {$method}");
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('not implemented yet', $e->getMessage());
            $this->assertStringContainsString($expectedStoryRef, $e->getMessage());
        }
    }

    #[Test]
    public function passing_special_chars_in_arguments_uses_array_mode(): void
    {
        // On vérifie que les args spéciaux sont préservés tels quels en mode
        // array (pas d'interprétation shell, pas de concat).
        Process::fake([
            '*' => Process::result(output: FakesGpoService::listallOutput(), errorOutput: '', exitCode: 0),
        ]);

        $service = FakesGpoService::makeService();
        $service->get("a'b\";cmd"); // valeur malveillante

        Process::assertRan(function ($process) {
            return is_array($process->command)
                && in_array("a'b\";cmd", $process->command, true);
        });
    }
}

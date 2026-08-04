<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Filesystem\Backend\Posix;

use App\Enums\FileBackendOutcome;
use App\Enums\PlanNodeNature;
use App\Observers\UserGroupObserver;
use App\Observers\WorkstationGroupObserver;
use App\Services\Filesystem\Backend\Posix\PosixFileBackend;
use App\Services\Filesystem\Plan\FilePlan;
use App\Services\Filesystem\Plan\PlanNode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Epic 34 → story 60.4 — la RÉVOCATION, descendue. Les assertions viennent des
 * tests de l'Epic 34 ; elles survivent, leur emplacement suit le code.
 *
 * L'obligation que le contrat DÉCRIVAIT sans que personne ne la tienne — « cette
 * méthode ne détruit pas de données » — est tenue ici, et vérifiée : aucune
 * commande de suppression n'est jamais émise.
 */
class PosixDeprovisionTest extends TestCase
{
    use RefreshDatabase;

    private string $tempRoot;

    protected function setUp(): void
    {
        parent::setUp();
        WorkstationGroupObserver::disableSync();
        UserGroupObserver::disableSync();

        $this->tempRoot = sys_get_temp_dir() . '/se5-deprov-' . uniqid();
        @mkdir($this->tempRoot, 0o755, true);
        config(['filesystem.shares_root' => $this->tempRoot]);
    }

    protected function tearDown(): void
    {
        @rmdir($this->tempRoot . '/proj/_travail');
        @rmdir($this->tempRoot . '/proj');
        @rmdir($this->tempRoot);
        WorkstationGroupObserver::enableSync();
        UserGroupObserver::enableSync();
        parent::tearDown();
    }

    private function plan(string $root = 'proj', string ...$children): FilePlan
    {
        $nodes = [new PlanNode(PlanNode::ROOT_PATH, 'Racine', PlanNodeNature::ContenuLibre)];
        foreach ($children as $child) {
            $nodes[] = new PlanNode($child, $child, PlanNodeNature::ContenuLibre);
        }

        return new FilePlan('@partage', $root, [], $nodes);
    }

    #[Test]
    public function an_already_absent_directory_is_conforme_and_nothing_runs(): void
    {
        Process::fake();

        $report = app(PosixFileBackend::class)->deprovision($this->plan('proj'));

        self::assertSame(FileBackendOutcome::Conforme, $report->for(PlanNode::ROOT_PATH)->outcome);
        Process::assertNothingRan();
    }

    #[Test]
    public function it_revokes_the_rights_closes_the_base_mode_and_archives_out_of_band(): void
    {
        @mkdir($this->tempRoot . '/proj', 0o755, true);
        Process::fake();

        $report = app(PosixFileBackend::class)->deprovision($this->plan('proj'));

        self::assertSame(FileBackendOutcome::Applique, $report->for(PlanNode::ROOT_PATH)->outcome);

        Process::assertRan(fn ($p): bool => str_contains($p->command, 'setfacl -R -P -b') && str_contains($p->command, '/proj'));
        Process::assertRan(fn ($p): bool => str_contains($p->command, 'chmod -R 0770') && str_contains($p->command, '/proj'));
        Process::assertRan(fn ($p): bool => str_contains($p->command, 'mkdir -p -m 0700') && str_contains($p->command, '.trash'));
        Process::assertRan(fn ($p): bool => str_contains($p->command, 'mv ') && str_contains($p->command, '.trash/proj-'));
    }

    /**
     * D9 : AUCUNE DESTRUCTION. Le contenu part par déplacement, jamais par
     * suppression — vérifié sur les commandes émises, pas sur une intention.
     */
    #[Test]
    public function no_destructive_command_is_ever_emitted(): void
    {
        @mkdir($this->tempRoot . '/proj', 0o755, true);
        Process::fake();

        app(PosixFileBackend::class)->deprovision($this->plan('proj'));

        Process::assertNotRan(fn ($p): bool => preg_match('/\brm\b|\brmdir\b|\bshred\b|\bunlink\b/', $p->command) === 1);
    }

    #[Test]
    public function a_failing_step_makes_every_node_fail_with_its_cause(): void
    {
        @mkdir($this->tempRoot . '/proj', 0o755, true);

        Process::fake([
            'sudo setfacl *' => Process::result(output: '', errorOutput: 'permission denied', exitCode: 1),
            '*' => Process::result(output: '', exitCode: 0),
        ]);

        $report = app(PosixFileBackend::class)->deprovision($this->plan('proj'));

        self::assertSame(FileBackendOutcome::Echec, $report->for(PlanNode::ROOT_PATH)->outcome);
        self::assertNotNull($report->for(PlanNode::ROOT_PATH)->detail);
        Process::assertNotRan(fn ($p): bool => str_contains($p->command, 'mv '));
    }

    /**
     * Un plan à plusieurs nœuds : le déplacement de la racine les emporte tous, et
     * CHACUN le dit. Un rapport amputé se lirait « tout va bien » sur le nœud dont
     * personne n'aurait parlé.
     */
    #[Test]
    public function every_node_of_the_plan_is_reported_even_when_one_gesture_covers_them_all(): void
    {
        @mkdir($this->tempRoot . '/proj/_travail', 0o755, true);
        Process::fake();

        $report = app(PosixFileBackend::class)->deprovision($this->plan('proj', '_travail'));

        self::assertSame(2, $report->count());
        self::assertSame(FileBackendOutcome::Applique, $report->for('_travail')->outcome);
        self::assertStringContainsString('racine du plan', (string) $report->for('_travail')->detail);
    }
}

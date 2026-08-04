<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Filesystem\Backend\Posix;

use App\Enums\PlanNodeNature;
use App\Models\User;
use App\Models\UserGroup;
use App\Observers\UserGroupObserver;
use App\Observers\WorkstationGroupObserver;
use App\Services\Filesystem\Backend\Posix\PosixFileBackend;
use App\Services\Filesystem\Plan\FilePlan;
use App\Services\Filesystem\Plan\PlanGrant;
use App\Services\Filesystem\Plan\PlanNode;
use App\Services\Filesystem\Plan\PlanSubject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 60.4 — LE JEU DE COMMANDES ÉMIS, ÉNUMÉRÉ.
 *
 * La promesse « aucune commande nouvelle » ne vaut que si elle est vérifiable.
 * Ce test parcourt les trois opérations du backend sur un plan représentatif et
 * ÉNUMÈRE les binaires effectivement invoqués.
 *
 * **La seule addition de la story est `getent`**, et elle est visible ici parce
 * qu'elle est dans la liste. C'est une LECTURE, SANS élévation de privilège, et
 * elle est exigée par la règle « jamais un nom de groupe inventé » : sans elle, le
 * backend poserait une entrée sur un nom que le système ne connaît pas — l'incident
 * mesuré du groupe sans suffixe d'établissement, où l'outil échoue et où, dans le
 * pire cas, l'entrée reste sans effet. Le même mécanisme est déjà en service dans
 * le dépôt pour le même besoin, sur le chemin figé des partages de classe.
 *
 * Tout le reste appartient au jeu de l'Epic 34, déjà couvert par la liste blanche
 * d'élévation de privilège.
 */
class PosixEmittedCommandsTest extends TestCase
{
    use RefreshDatabase;

    /** Le jeu FERMÉ. Toute addition doit passer par une modification de ce test. */
    private const ALLOWED_BINARIES = [
        'mkdir',
        'setfacl',
        'getfacl',
        'chown',
        'chgrp',
        'chmod',
        'mv',
        'getent',
    ];

    /** Les binaires du jeu de l'Epic 34 — ceux qui exigent une élévation. */
    private const PRIVILEGED_BINARIES = ['mkdir', 'setfacl', 'getfacl', 'chown', 'chgrp', 'chmod', 'mv'];

    private string $tempRoot;

    protected function setUp(): void
    {
        parent::setUp();
        WorkstationGroupObserver::disableSync();
        UserGroupObserver::disableSync();

        $this->tempRoot = sys_get_temp_dir() . '/se5-cmds-' . uniqid();
        @mkdir($this->tempRoot . '/proj', 0o755, true);
        config(['filesystem.shares_root' => $this->tempRoot]);
    }

    protected function tearDown(): void
    {
        @rmdir($this->tempRoot . '/proj');
        @rmdir($this->tempRoot);
        WorkstationGroupObserver::enableSync();
        UserGroupObserver::enableSync();
        parent::tearDown();
    }

    /** @return list<string> le binaire de chaque commande émise, dédupliqué */
    private function emittedBinaries(array $commands): array
    {
        $binaries = [];
        foreach ($commands as $command) {
            $tokens = preg_split('/\s+/', trim($command)) ?: [];
            $binary = $tokens[0] ?? '';
            if ($binary === 'sudo') {
                $binary = $tokens[1] ?? '';
            }
            $binaries[basename($binary)] = true;
        }

        return array_values(array_keys($binaries));
    }

    #[Test]
    public function the_whole_story_emits_exactly_the_expected_set_of_binaries(): void
    {
        Process::fake([
            'sudo getfacl *' => Process::result(output: "user::rwx\nother::rwx", exitCode: 0),
            '*' => Process::result(output: '', exitCode: 0),
        ]);

        $alice = User::factory()->create(['login' => 'alice']);
        $groupe = UserGroup::create(['name' => 'Direction', 'type' => 'custom']);

        $plan = new FilePlan('@partage', 'proj', [], [
            new PlanNode(PlanNode::ROOT_PATH, 'Racine', PlanNodeNature::ContenuLibre, [
                new PlanGrant('@assignation', PlanSubject::user((int) $alice->id), PlanGrant::ACCESS_RW),
                new PlanGrant('@assignation', PlanSubject::group((int) $groupe->id), PlanGrant::ACCESS_RO),
            ]),
        ]);

        $backend = app(PosixFileBackend::class);
        $backend->provision($plan);
        $backend->inspect($plan);
        $backend->quota($plan);
        $backend->deprovision($plan);

        $commands = [];
        Process::assertRan(function ($process) use (&$commands): bool {
            $commands[] = $process->command;

            return true;
        });

        $emitted = $this->emittedBinaries($commands);
        sort($emitted);
        $allowed = self::ALLOWED_BINARIES;
        sort($allowed);

        self::assertSame(
            [],
            array_values(array_diff($emitted, $allowed)),
            'COMMANDE NOUVELLE ÉMISE. Le jeu de commandes de ce backend est fermé : '
            . implode(', ', $allowed) . '. Émises : ' . implode(', ', $emitted),
        );

        // Contrôle inverse : si l'énumération ne voyait rien, elle serait vraie pour
        // la pire des raisons.
        self::assertContains('setfacl', $emitted);
        self::assertContains('getent', $emitted);
        self::assertContains('mv', $emitted);
    }

    /**
     * La sonde d'annuaire est la seule addition, et elle N'EST PAS privilégiée.
     * Toutes les autres le sont, comme avant.
     */
    #[Test]
    public function only_the_historic_set_is_privileged_and_the_directory_probe_never_is(): void
    {
        Process::fake();

        $groupe = UserGroup::create(['name' => 'Direction', 'type' => 'custom']);
        $plan = new FilePlan('@partage', 'proj', [], [
            new PlanNode(PlanNode::ROOT_PATH, 'Racine', PlanNodeNature::ContenuLibre, [
                new PlanGrant('@assignation', PlanSubject::group((int) $groupe->id), PlanGrant::ACCESS_RO),
            ]),
        ]);

        app(PosixFileBackend::class)->provision($plan);

        $commands = [];
        Process::assertRan(function ($process) use (&$commands): bool {
            $commands[] = $process->command;

            return true;
        });

        foreach ($commands as $command) {
            if (str_starts_with($command, 'getent ')) {
                continue;
            }
            self::assertStringStartsWith('sudo ', $command, 'commande non attendue : ' . $command);
            $binary = preg_split('/\s+/', $command)[1] ?? '';
            self::assertContains($binary, self::PRIVILEGED_BINARIES, 'binaire privilégié hors jeu : ' . $binary);
        }

        self::assertNotEmpty(array_filter($commands, static fn (string $c): bool => str_starts_with($c, 'getent ')));
    }
}

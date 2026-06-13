<?php

declare(strict_types=1);

namespace Tests\Feature\Agent;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Pont `php artisan test` → `go test ./...` du module agent (`agent/`).
 *
 * Demande Henri : la suite de tests Laravel doit AUSSI faire tourner les tests
 * de l'agent Go. Ce test shelle vers `go test ./...` dans `agent/` et échoue si
 * la suite Go échoue (sortie complète remontée dans le message d'assertion).
 *
 * Robustesse :
 *  - SKIP propre (jamais d'échec) si la toolchain Go est absente — un poste de
 *    dev sans Go reste vert ; la VM/le serveur l'installe via
 *    `scripts/setupGo.sh` (appelé par `scripts/update.sh`), après quoi ce pont
 *    s'active automatiquement.
 *  - Cache Go DÉDIÉ sous `storage/framework/cache/` (mêmes chemins que
 *    setupGo.sh, inscriptibles par www-admin) → pas d'écriture dans le HOME de
 *    l'appelant, pas de heurt de droits.
 *  - `GOTOOLCHAIN=local` : jamais de fetch réseau d'une autre toolchain.
 *
 * Groupe `go-agent` : `php artisan test --exclude-group go-agent` pour s'en
 * passer ponctuellement (ex. itération PHP rapide).
 */
#[Group('go-agent')]
final class GoAgentTest extends TestCase
{
    #[Test]
    public function go_agent_test_suite_passes(): void
    {
        $agentDir = base_path('agent');
        if (! is_file($agentDir . '/go.mod')) {
            $this->markTestSkipped('Module agent Go absent (agent/go.mod).');
        }

        $go = $this->resolveGoBinary();
        if ($go === null) {
            $this->markTestSkipped('Toolchain Go absente — lancer scripts/setupGo.sh (ou scripts/update.sh).');
        }

        $goPath = storage_path('framework/cache/go');
        $goCache = storage_path('framework/cache/go-build');
        foreach ([$goPath, $goCache] as $dir) {
            if (! is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
        }

        $process = new Process(
            [$go, 'test', './...'],
            $agentDir,
            [
                'GOPATH' => $goPath,
                'GOCACHE' => $goCache,
                'GOTOOLCHAIN' => 'local',
                'GOFLAGS' => '-mod=mod',
                'HOME' => $goPath,
                'PATH' => (string) getenv('PATH'),
            ],
            null,
            600.0,
        );
        $process->run();

        $this->assertSame(
            0,
            $process->getExitCode(),
            "La suite Go de l'agent a échoué (go test ./...) :\n\n"
                . $process->getOutput() . "\n" . $process->getErrorOutput(),
        );
    }

    /**
     * Résout le binaire `go` : symlink système posé par setupGo.sh, préfixe
     * d'installation épinglé, puis PATH.
     */
    private function resolveGoBinary(): ?string
    {
        foreach (['/usr/local/bin/go', '/usr/local/go/bin/go'] as $candidate) {
            if (is_executable($candidate)) {
                return $candidate;
            }
        }

        $which = trim((string) shell_exec('command -v go 2>/dev/null'));

        return $which !== '' && is_executable($which) ? $which : null;
    }
}

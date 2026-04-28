<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Services\Print\Contracts\CommandRunner;

/**
 * Story 6.1 — Test double pour `CommandRunner`.
 *
 * Programmable in-memory : on enregistre les réponses pour des commandes
 * précises (matching exact ou substring), et on récupère l'historique des
 * commandes exécutées pour assertions.
 */
class FakeCommandRunner implements CommandRunner
{
    /**
     * @var array<int, array{matcher: callable(string):bool, stdout: string[], stderr: string[], returnCode: int}>
     */
    private array $rules = [];

    /** @var string[] */
    public array $executed = [];

    /** Réponse par défaut si aucun matcher ne correspond. */
    private array $default = [
        'stdout' => [],
        'stderr' => ['no matcher defined'],
        'returnCode' => 1,
    ];

    /**
     * Ajoute une règle qui matche substring.
     *
     * @param  string|string[]  $stdout
     * @param  string|string[]  $stderr
     */
    public function whenContains(
        string $needle,
        string|array $stdout = '',
        int $returnCode = 0,
        string|array $stderr = '',
    ): self {
        $this->rules[] = [
            'matcher' => fn(string $cmd) => str_contains($cmd, $needle),
            'stdout' => is_array($stdout) ? $stdout : ($stdout === '' ? [] : explode("\n", rtrim($stdout, "\n"))),
            'stderr' => is_array($stderr) ? $stderr : ($stderr === '' ? [] : explode("\n", rtrim($stderr, "\n"))),
            'returnCode' => $returnCode,
        ];
        return $this;
    }

    /**
     * Ajoute une règle qui matche substring depuis un fichier fixture.
     */
    public function whenContainsFromFixture(string $needle, string $fixturePath, int $returnCode = 0): self
    {
        $content = file_get_contents($fixturePath);
        if ($content === false) {
            throw new \RuntimeException("Fixture introuvable : {$fixturePath}");
        }
        return $this->whenContains($needle, $content, $returnCode);
    }

    public function setDefault(int $returnCode, string|array $stdout = '', string|array $stderr = ''): self
    {
        $this->default = [
            'stdout' => is_array($stdout) ? $stdout : ($stdout === '' ? [] : explode("\n", rtrim($stdout, "\n"))),
            'stderr' => is_array($stderr) ? $stderr : ($stderr === '' ? [] : explode("\n", rtrim($stderr, "\n"))),
            'returnCode' => $returnCode,
        ];
        return $this;
    }

    public function run(string $command): array
    {
        $this->executed[] = $command;
        foreach ($this->rules as $rule) {
            if (($rule['matcher'])($command)) {
                return [
                    'stdout' => $rule['stdout'],
                    'stderr' => $rule['stderr'],
                    'returnCode' => $rule['returnCode'],
                ];
            }
        }
        return $this->default;
    }

    public function lastCommand(): ?string
    {
        return end($this->executed) ?: null;
    }
}

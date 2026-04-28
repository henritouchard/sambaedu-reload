<?php

declare(strict_types=1);

namespace App\Services\Print;

use App\Services\Print\Contracts\CommandRunner;

/**
 * Story 6.1 — Implémentation réelle de CommandRunner via proc_open.
 *
 * Capture stdout et stderr séparément (vs `exec()` qui mélange ou redirige).
 * Le `returnCode` est renvoyé exactement comme par le shell.
 */
class RealCommandRunner implements CommandRunner
{
    /**
     * {@inheritdoc}
     *
     * Préfixe systématiquement `LC_ALL=C` pour que la sortie de `lpstat` et autres
     * commandes CUPS soit en anglais indépendamment de la locale de la VM (Story 6.1 fix #14).
     */
    public function run(string $command): array
    {
        $command = 'LC_ALL=C ' . $command;

        $descriptors = [
            0 => ['pipe', 'r'], // stdin (non utilisé)
            1 => ['pipe', 'w'], // stdout
            2 => ['pipe', 'w'], // stderr
        ];

        $process = proc_open($command, $descriptors, $pipes);

        if (!is_resource($process)) {
            return [
                'stdout' => [],
                'stderr' => ['proc_open a échoué'],
                'returnCode' => -1,
            ];
        }

        fclose($pipes[0]);

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);

        fclose($pipes[1]);
        fclose($pipes[2]);

        $returnCode = proc_close($process);

        return [
            'stdout' => $this->splitLines($stdout),
            'stderr' => $this->splitLines($stderr),
            'returnCode' => $returnCode,
        ];
    }

    /**
     * @return string[]
     */
    private function splitLines(string $text): array
    {
        $text = rtrim($text, "\r\n");
        if ($text === '') {
            return [];
        }
        return preg_split("/\r?\n/", $text) ?: [];
    }
}

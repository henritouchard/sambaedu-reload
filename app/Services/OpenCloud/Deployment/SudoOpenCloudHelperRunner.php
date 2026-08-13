<?php

declare(strict_types=1);

namespace App\Services\OpenCloud\Deployment;

/**
 * Implémentation RÉELLE du seam privilégié : `sudo -n <helper> …`.
 *
 * Calque du runner du système d'extensions (`proc_open`, capture séparée
 * stdout/stderr/code retour), avec les trois propriétés que ce domaine exige :
 *
 * - **stdin réel** : le contenu est écrit puis le tube est FERMÉ avant toute
 *   lecture de stdout/stderr. Sans cette fermeture, le helper attendrait un EOF
 *   qui ne viendrait jamais et les deux processus s'interbloqueraient ;
 * - **`sudo -n`** (non interactif) : sans tty, un sudoers manquant échoue
 *   IMMÉDIATEMENT au lieu de bloquer le worker sur une invite invisible ;
 * - **`escapeshellarg` sur CHAQUE argument**, y compris le chemin du helper.
 *   Ce n'est pas la défense principale (le helper re-valide tout côté root),
 *   c'est la première.
 *
 * ⚠️ Le mot de passe d'administration ne transite QUE par `$stdin`. Rien de ce
 * qui passe ici n'est journalisé : ni la commande, ni a fortiori l'entrée
 * standard. Le journal utile est celui du pilote appelant, qui nomme l'ÉTAPE.
 */
class SudoOpenCloudHelperRunner implements OpenCloudHelperRunner
{
    public const DEFAULT_HELPER_PATH = '/usr/share/sambaedu/sbin/sambaedu-opencloud-helper.sh';

    /**
     * Compose la ligne de commande réellement exécutée.
     *
     * Publique et isolée pour deux raisons : elle est assertable telle quelle
     * (« la commande passe bien par `sudo -n` et échappe chaque argument »), et
     * un test de plomberie peut la substituer par une commande locale sans
     * privilège, ce qui permet d'éprouver le transport sur l'HÔTE.
     *
     * @param  list<string>  $args
     */
    public function buildCommand(array $args): string
    {
        $helper = (string) config('opencloud.helper_path', self::DEFAULT_HELPER_PATH);

        $parts = ['sudo', '-n', escapeshellarg($helper)];
        foreach ($args as $arg) {
            $parts[] = escapeshellarg((string) $arg);
        }

        return 'LC_ALL=C '.implode(' ', $parts);
    }

    /** {@inheritdoc} */
    public function run(array $args, ?string $stdin = null): array
    {
        $command = $this->buildCommand($args);

        $descriptors = [
            0 => ['pipe', 'r'], // stdin — le SEUL canal du secret
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = @proc_open($command, $descriptors, $pipes);

        if (! is_resource($process)) {
            return ['stdout' => [], 'stderr' => ['proc_open a échoué'], 'exitCode' => -1];
        }

        if ($stdin !== null && $stdin !== '') {
            fwrite($pipes[0], $stdin);
        }
        // Fermeture AVANT lecture : le helper doit voir son EOF.
        fclose($pipes[0]);

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);

        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        return [
            'stdout' => $this->splitLines($stdout),
            'stderr' => $this->splitLines($stderr),
            'exitCode' => $exitCode,
        ];
    }

    /** @return list<string> */
    private function splitLines(string $text): array
    {
        $text = rtrim($text, "\r\n");
        if ($text === '') {
            return [];
        }

        return preg_split("/\r?\n/", $text) ?: [];
    }
}

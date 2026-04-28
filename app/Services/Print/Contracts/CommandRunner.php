<?php

declare(strict_types=1);

namespace App\Services\Print\Contracts;

/**
 * Story 6.1 — Interface d'exécution de commandes shell.
 *
 * Permet de mocker les appels système dans les tests via un FakeCommandRunner
 * (vs Laravel `Process::fake()` : neutralité framework, réutilisable Story 6.2
 * `PrintDriverService` et future Epic 8 DHCP).
 *
 * Implémentation par défaut : `RealCommandRunner` (proc_open + capture séparée
 * stdout/stderr/returnCode). Bindée dans `AppServiceProvider`.
 */
interface CommandRunner
{
    /**
     * Exécute une commande shell et retourne stdout/stderr/returnCode.
     *
     * @param  string  $command  Commande complète, déjà escapée par l'appelant.
     * @return array{stdout: string[], stderr: string[], returnCode: int}
     */
    public function run(string $command): array;
}

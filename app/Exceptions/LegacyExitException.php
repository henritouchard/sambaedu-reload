<?php

namespace App\Exceptions;

/**
 * Sentinelle levée à la place des `exit()`/`die()` du code legacy lorsqu'il
 * est exécuté via {@see \App\Http\Controllers\LegacyCatchallController}.
 *
 * exit() est un construct PHP non-overridable qui termine le process, ce
 * qui casse la sérialisation du résultat sous PHPUnit
 * #[RunInSeparateProcess] (« child process ended unexpectedly »). Le
 * controller réécrit donc exit()/die() du legacy en
 * `throw new LegacyExitException(...)` avant un `eval()`, et catch cette
 * exception au niveau du bootstrap pour reprendre le flow normal
 * (capture de l'output, écriture des headers, etc.).
 */
class LegacyExitException extends \RuntimeException
{
}

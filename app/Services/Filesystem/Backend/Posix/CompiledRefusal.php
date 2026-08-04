<?php

declare(strict_types=1);

namespace App\Services\Filesystem\Backend\Posix;

use App\Enums\FileBackendOutcome;

/**
 * Story 60.4 — un octroi que la compilation N'A PAS écrit, et pourquoi.
 *
 * Un refus n'est pas une exception : la compilation d'un nœud continue, les
 * autres octrois s'écrivent, et le refus remonte dans l'état du nœud. C'est la
 * différence entre « fail-soft avec une entrée de journal » (ce que faisait le
 * code historique, et que personne ne lisait) et « fail-soft avec un état de
 * rapport » (ce que l'administrateur voit).
 *
 * `blocking` distingue le refus d'UN octroi du refus du NŒUD ENTIER : le garde-fou
 * d'échelle refuse d'écrire quoi que ce soit sur le nœud, parce que le geste
 * lui-même est le problème.
 */
final class CompiledRefusal
{
    public function __construct(
        public readonly FileBackendOutcome $outcome,
        public readonly string $detail,
        public readonly bool $blocking = false,
    ) {
    }
}

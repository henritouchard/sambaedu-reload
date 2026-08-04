<?php

declare(strict_types=1);

namespace App\Services\Filesystem\Backend\Posix;

/**
 * Story 60.4 — ce qu'un nœud de plan donne, une fois compilé pour POSIX : une
 * liste d'entrées à poser, et la liste de ce qui n'a PAS pu être écrit.
 *
 * Les deux voyagent ensemble parce qu'elles décrivent le même passage : poser les
 * entrées sans dire ce qui manque, c'est exactement le silence que cette story
 * supprime.
 */
final class CompiledNodeAcl
{
    /**
     * @param  list<string>  $acls
     * @param  list<CompiledRefusal>  $refusals
     */
    public function __construct(
        public readonly array $acls,
        public readonly array $refusals = [],
    ) {
    }

    /** `true` si le nœud entier est refusé — rien ne doit être écrit. */
    public function isBlocked(): bool
    {
        foreach ($this->refusals as $refusal) {
            if ($refusal->blocking) {
                return true;
            }
        }

        return false;
    }

    /** @return list<\App\Enums\FileBackendOutcome> */
    public function refusalOutcomes(): array
    {
        return array_map(static fn (CompiledRefusal $r): \App\Enums\FileBackendOutcome => $r->outcome, $this->refusals);
    }

    /** @return list<string> */
    public function refusalDetails(): array
    {
        return array_map(static fn (CompiledRefusal $r): string => $r->detail, $this->refusals);
    }
}

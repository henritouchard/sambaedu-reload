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
 *
 * ---------------------------------------------------------------------------
 * **Story 62.4 — deux ajouts, tous deux imposés par les quatre verbes.**
 *
 *  - `fileAcls` — la liste à poser sur les FICHIERS quand elle DIFFÈRE de celle des
 *    dossiers. Elle est VIDE dans l'immense majorité des cas, et sa vacuité
 *    signifie « une seule liste, posée partout, exactement comme hier ». Elle ne
 *    se peuple que si un octroi demande une découpe où le niveau des fichiers et
 *    celui des dossiers divergent (« lire + éditer », par exemple : écrire dans les
 *    fichiers sans pouvoir en créer ni en retirer). Poser alors un niveau unique
 *    donnerait un verbe de trop d'un côté ou de l'autre — en silence ;
 *  - `restrictsDeletion` — la restriction de suppression au propriétaire, qui est
 *    un attribut du DOSSIER et non d'une entrée. Elle voyage donc ici, décidée une
 *    fois pour tout le nœud, et jamais recalculée par l'exécution.
 */
final class CompiledNodeAcl
{
    /**
     * @param  list<string>  $acls  la liste des DOSSIERS (et de tout, si `$fileAcls` est vide)
     * @param  list<CompiledRefusal>  $refusals
     * @param  list<string>  $fileAcls  la liste des FICHIERS, vide si elle est identique
     * @param  bool  $restrictsDeletion  le dossier doit-il restreindre la suppression au propriétaire ?
     */
    public function __construct(
        public readonly array $acls,
        public readonly array $refusals = [],
        public readonly array $fileAcls = [],
        public readonly bool $restrictsDeletion = false,
    ) {
    }

    /**
     * Fichiers et dossiers exigent-ils des listes différentes ? Si oui, la pose se
     * fait en deux passages ciblés au lieu d'un seul passage récursif.
     */
    public function isDifferentiated(): bool
    {
        return $this->fileAcls !== [];
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

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
 *
 * ---------------------------------------------------------------------------
 * **Story 62.5 — une TROISIÈME liste, et elle ne ressemble à aucune des deux.**
 *
 * `traversalAcls` porte les COULOIRS D'ACCÈS dérivés
 * ({@see PosixTraversalPlanner}) : ce que des sujets doivent avoir sur CE nœud
 * pour atteindre un nœud plus profond qui, lui, leur accorde quelque chose. Elle
 * voyage à part parce que tout la distingue des deux autres :
 *
 *  - elle se pose sur le répertoire de TÊTE SEUL, jamais récursivement — descendre
 *    diffuserait la traversée dans tout le contenu de l'ancêtre ;
 *  - elle n'a AUCUN miroir d'héritage — un miroir la donnerait à tout enfant futur ;
 *  - elle n'a AUCUNE contrepartie fichier — la traversée d'un fichier n'existe pas.
 *
 * Elle est VIDE dans l'immense majorité des cas, et sa vacuité est la propriété
 * mesurée sur les recettes livrées : aucun rôle n'y reçoit en profondeur ce que ses
 * ancêtres ne lui donnent pas déjà.
 */
final class CompiledNodeAcl
{
    /**
     * @param  list<string>  $acls  la liste des DOSSIERS (et de tout, si `$fileAcls` est vide)
     * @param  list<CompiledRefusal>  $refusals
     * @param  list<string>  $fileAcls  la liste des FICHIERS, vide si elle est identique
     * @param  bool  $restrictsDeletion  le dossier doit-il restreindre la suppression au propriétaire ?
     * @param  list<string>  $traversalAcls  les couloirs dérivés, entrées d'ACCÈS de tête seules
     */
    public function __construct(
        public readonly array $acls,
        public readonly array $refusals = [],
        public readonly array $fileAcls = [],
        public readonly bool $restrictsDeletion = false,
        public readonly array $traversalAcls = [],
    ) {
    }

    /**
     * L'ensemble COMPLET des entrées attendues sur le répertoire de tête : celles du
     * nœud, plus ses couloirs.
     *
     * C'est cet ensemble-là, et pas `acls` seul, que la relecture de conformité doit
     * comparer : un couloir posé est dans l'état de tête relu, et l'oublier ferait
     * relire « dérivé » un nœud parfaitement conforme, à chaque passage.
     *
     * @return list<string>
     */
    public function headAcls(): array
    {
        return array_values([...$this->acls, ...$this->traversalAcls]);
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

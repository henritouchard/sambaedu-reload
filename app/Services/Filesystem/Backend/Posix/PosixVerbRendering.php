<?php

declare(strict_types=1);

namespace App\Services\Filesystem\Backend\Posix;

use App\Services\Filesystem\Plan\PlanGrant;

/**
 * Story 62.4 — CE QUE LE SERVEUR DE FICHIERS HISTORIQUE SAIT RENDRE d'une liste de
 * verbes, et ce qu'il ne sait pas.
 *
 * ---------------------------------------------------------------------------
 * **CE N'EST PAS UNE TABLE DE QUINZE CAS. C'EST DEUX AXES ET UN DRAPEAU.**
 *
 * La tentation, devant quatre verbes combinables, est d'écrire les quinze
 * combinaisons à la main. Elles seraient alors quinze DÉCISIONS, dont chacune
 * pourrait être fausse sans que rien ne le dise. Elles sont ici DÉRIVÉES de la
 * seule chose qui soit vraie du mécanisme :
 *
 *  - **l'axe FICHIER** — pour changer le CONTENU d'un fichier, il faut la
 *    permission d'écriture SUR LE FICHIER. `lire` donne la lecture, `editer` donne
 *    l'écriture. Créer et supprimer n'y ont aucune part ;
 *  - **l'axe DOSSIER** — pour faire apparaître ou disparaître une entrée, il faut
 *    la permission d'écriture SUR LE DOSSIER. `lire` donne le listage, `creer` ET
 *    `supprimer` demandent LE MÊME BIT. Éditer n'y a aucune part ;
 *  - **la traversée** — n'importe quel verbe suppose de pouvoir entrer dans le
 *    dossier. Elle accompagne donc tout octroi rendu, et n'est le rendu d'aucun
 *    verbe en particulier.
 *
 * Tout le reste s'ensuit, et notamment les deux conséquences qui comptent :
 *
 *  1. **`creer` et `supprimer` partagent un levier.** Les séparer demande un
 *     SECOND mécanisme — la restriction de suppression au propriétaire, posée sur
 *     le dossier. Elle rend « déposer sans effacer le travail des autres » de façon
 *     APPROCHÉE : le déposant peut encore retirer ses propres dépôts. C'est une
 *     dégradation, elle est réelle, et elle est DÉCLARÉE (jamais tue).
 *  2. **`supprimer` sans `creer` n'est pas exprimable du tout.** Le seul levier
 *     disponible donnerait aussi la création — un verbe que la recette n'a pas
 *     écrit. La règle interdit de l'accorder, donc `supprimer` n'est pas rendu, et
 *     le nœud le dit ({@see \App\Enums\FileBackendOutcome::NonExprimable}).
 *
 * ---------------------------------------------------------------------------
 * **LA RÈGLE UNIQUE DE DÉGRADATION.**
 *
 * Quand le mécanisme ne sait pas rendre la découpe demandée, il rend
 * l'**INTERSECTION EXPRIMABLE** — jamais un verbe de MUTATION que l'octroi ne
 * porte pas — et DÉCLARE ce qui manque.
 *
 * Le précédent du « surensemble nommé » (story 60.4, où une audience plus large
 * que celle demandée était acceptée) ne s'étend PAS ici : il portait sur QUI, pas
 * sur QUOI. Élargir l'audience d'un droit déjà accordé est une approximation
 * discutable ; accorder `creer` pour pouvoir rendre `supprimer` ouvrirait un droit
 * que l'administrateur n'a pas donné. Ce n'est pas la même chose, et la seconde
 * n'est jamais acceptable.
 *
 * ---------------------------------------------------------------------------
 * **LA RESTRICTION DE SUPPRESSION EST UNE PROPRIÉTÉ DU NŒUD, PAS DE L'ENTRÉE.**
 *
 * Elle se pose sur le DOSSIER et vaut pour tout le monde. Toute conception qui
 * l'attacherait à un octroi est fausse par construction. D'où le paramètre
 * `$restrictionAvailable` : c'est le NŒUD qui décide (voir
 * {@see PosixAclCompiler}), parce que sur un nœud MIXTE — un octroi « déposer sans
 * effacer » ET un octroi qui porte `supprimer` — la poser retirerait au second
 * l'effacement des fichiers d'autrui, c'est-à-dire un droit écrit dans la recette.
 * On ne la pose donc pas, et le premier octroi retombe sur l'intersection
 * exprimable, en le disant.
 */
final class PosixVerbRendering
{
    /**
     * @param  list<string>  $verbs  ce que l'octroi DEMANDE
     * @param  list<string>  $rendered  ce que le mécanisme REND effectivement
     * @param  list<string>  $missing  ce qui n'a PAS pu être rendu (ordre canonique)
     * @param  string  $directoryMode  niveau d'entrée des DOSSIERS (`''` si rien n'est rendu)
     * @param  string  $fileMode  niveau d'entrée des FICHIERS (`''` si rien n'est rendu)
     */
    private function __construct(
        public readonly array $verbs,
        public readonly array $rendered,
        public readonly array $missing,
        public readonly string $directoryMode,
        public readonly string $fileMode,
    ) {
    }

    /**
     * @param  list<string>  $verbs  verbes canoniques de l'octroi
     * @param  bool  $restrictionAvailable  le nœud peut-il porter la restriction de
     *                                      suppression au propriétaire ?
     */
    public static function of(array $verbs, bool $restrictionAvailable): self
    {
        $has = static fn (string $verb): bool => in_array($verb, $verbs, true);

        // Le levier d'écriture du DOSSIER porte création ET suppression. On ne
        // l'accorde donc que si l'octroi porte la création — sinon on donnerait un
        // verbe non écrit — et, s'il ne porte pas la suppression, qu'à la condition
        // que le nœud puisse porter la restriction qui l'approche.
        $directoryWrite = $has(PlanGrant::VERB_CREER)
            && ($has(PlanGrant::VERB_SUPPRIMER) || $restrictionAvailable);

        $rendered = [];
        if ($has(PlanGrant::VERB_LIRE)) {
            $rendered[] = PlanGrant::VERB_LIRE;
        }
        if ($has(PlanGrant::VERB_EDITER)) {
            $rendered[] = PlanGrant::VERB_EDITER;
        }
        if ($directoryWrite) {
            $rendered[] = PlanGrant::VERB_CREER;
            if ($has(PlanGrant::VERB_SUPPRIMER)) {
                $rendered[] = PlanGrant::VERB_SUPPRIMER;
            }
        }

        $missing = array_values(array_diff($verbs, $rendered));

        // Rien de rendu : aucune entrée n'est écrite du tout. Écrire une entrée
        // vide serait pire que rien — c'est la forme MATÉRIALISÉE d'une suspension,
        // et une observation la relirait comme telle.
        if ($rendered === []) {
            return new self($verbs, [], $missing, '', '');
        }

        // La traversée accompagne tout octroi rendu : sans elle, aucun des verbes
        // n'est atteignable. Elle est écrite sur les fichiers comme sur les
        // dossiers — c'est le comportement de toujours, et le préserver est ce qui
        // rend l'iso-sortie vraie sur les deux combinaisons qui existent en base.
        $directoryMode = ($has(PlanGrant::VERB_LIRE) ? 'r' : '') . ($directoryWrite ? 'w' : '') . 'x';
        $fileMode = ($has(PlanGrant::VERB_LIRE) ? 'r' : '') . ($has(PlanGrant::VERB_EDITER) ? 'w' : '') . 'x';

        return new self($verbs, $rendered, $missing, $directoryMode, $fileMode);
    }

    /** Le mécanisme rend-il EXACTEMENT ce que l'octroi demande ? */
    public function isExact(): bool
    {
        return $this->missing === [];
    }

    /** L'octroi ne produit AUCUNE entrée : rien de ce qu'il demande n'est rendu. */
    public function isEmpty(): bool
    {
        return $this->rendered === [];
    }

    /**
     * Les dossiers et les fichiers exigent-ils des niveaux DIFFÉRENTS ?
     *
     * Quand c'est le cas, une pose uniforme donnerait forcément un verbe de trop
     * d'un côté ou de l'autre : c'est l'exécution qui doit porter la distinction,
     * jamais un niveau unique approximé en silence.
     */
    public function isDifferentiated(): bool
    {
        return ! $this->isEmpty() && $this->directoryMode !== $this->fileMode;
    }
}

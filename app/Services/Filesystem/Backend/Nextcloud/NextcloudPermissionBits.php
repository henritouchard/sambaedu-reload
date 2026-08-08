<?php

declare(strict_types=1);

namespace App\Services\Filesystem\Backend\Nextcloud;

use App\Services\Filesystem\Plan\PlanGrant;

/**
 * Story 61.3 — LA TRADUCTION DES VERBES, BIT À BIT, ET LE BIT QU'ON N'ACCORDE PAS.
 *
 * ---------------------------------------------------------------------------
 * **LE MODÈLE TOMBE JUSTE, ET CE N'EST PAS UN HASARD.** Le vocabulaire de verbes
 * du plan (story 62.4) a été découpé sur DEUX plans de fichiers, dont celui-ci :
 * lire / mettre à jour le contenu / créer une entrée / supprimer une entrée sont
 * quatre permissions SÉPARÉES ici comme là-bas. La traduction est donc une SOMME,
 * pas une interprétation — aucune dégradation, aucun déclin à déclarer, aucune
 * classe d'équivalence à inventer :
 *
 *  | verbe du plan | bit natif |
 *  |---------------|-----------|
 *  | `lire`        | 1         |
 *  | `editer`      | 2         |
 *  | `creer`       | 4         |
 *  | `supprimer`   | 8         |
 *
 * ---------------------------------------------------------------------------
 * **LE CINQUIÈME BIT (16, le RE-PARTAGE) N'EST JAMAIS ACCORDÉ.** C'était la seule
 * question ouverte de la story (« `rw` = 15 ou 31 ? »), et elle se referme par le
 * modèle plutôt que par une préférence :
 *
 *  - le plan n'a AUCUN verbe pour le re-partage. L'accorder donnerait un droit que
 *    personne n'a écrit — la définition même de ce que le drift STRICT refuse ;
 *  - sous drift STRICT, un re-partage créé par un utilisateur serait un état que le
 *    plan ne sait pas DÉCRIRE. Au mieux il remonterait en dérive perpétuelle, au
 *    pire une réconciliation le supprimerait. On n'accorde pas un droit dont le
 *    modèle ne peut pas rendre compte ;
 *  - le précédent de production va dans le même sens : le canal historique écrit
 *    `15` pour l'accès complet.
 *
 * `rw` (les quatre verbes) vaut donc **15**, jamais 31. Le spike 60.0 avait relu
 * `31` parce que c'est ce qu'il avait ÉCRIT : l'instance ne coerce rien (mesuré sur
 * 1, 7, 15 et 31), la valeur est un choix de conception et non une contrainte.
 *
 * **Ce qu'`inspect` fait d'un bit 16 relu** : il ne le traduit pas en verbe (le
 * vocabulaire d'observation est fermé sur les quatre du plan et le refuserait), il
 * ne le jette pas non plus. Il est SIGNALÉ dans le `detail` du nœud comme une
 * permission relue hors du modèle — donc le nœud n'est jamais « conforme », et
 * l'administrateur voit qu'un droit existe que le plan ne décrit pas. Le taire
 * ferait de la relecture la porte dérobée de la ligne de coupe ; le convertir en
 * verbe inventerait une intention.
 */
final class NextcloudPermissionBits
{
    /** @var array<string, int> verbe du plan => bit natif */
    public const NATIVE = [
        PlanGrant::VERB_LIRE => 1,
        PlanGrant::VERB_EDITER => 2,
        PlanGrant::VERB_CREER => 4,
        PlanGrant::VERB_SUPPRIMER => 8,
    ];

    /** Les quatre bits que SE5 gouverne — la valeur de « tout ce que le plan sait dire ». */
    public const ALL_MODELLED = 15;

    /**
     * Le bit de RE-PARTAGE. Jamais écrit ; relu, il est signalé et jamais traduit.
     * Il est nommé ici pour que la relecture puisse le RECONNAÎTRE — un bit qu'on
     * ne nomme pas est un bit qu'on confond avec du bruit.
     */
    public const SHARE = 16;

    /**
     * Masque d'une règle de CLÔTURE : la règle gouverne les quatre bits du modèle
     * ET le re-partage, et les met tous à zéro.
     *
     * **Pourquoi 31 et pas 1.** Le relevé de canal a prouvé l'écriture avec un
     * masque de 1 (« retirer la lecture »), et c'est suffisant pour faire
     * disparaître le dossier du listing. Mais un masque de 1 laisse les bits de
     * mutation GOUVERNÉS PAR L'ANCÊTRE : une clôture qui ne referme que la lecture
     * est une clôture à moitié posée, et « à moitié » est précisément la forme que
     * prend, dans cet epic, le signal qui n'atteint pas son destinataire. Le masque
     * est un sélecteur de bits, pas une sémantique de protocole : l'élargir ne
     * change pas le canal, il change ce que la règle gouverne.
     */
    public const CLOSURE_MASK = 31;

    /**
     * Somme des bits d'une liste de verbes de plan. Une liste VIDE (octroi suspendu,
     * ou rôle clos) rend `0` — un octroi explicitement vide, jamais une absence.
     *
     * @param  list<string>  $verbs
     */
    public static function fromVerbs(array $verbs): int
    {
        $bits = 0;
        foreach ($verbs as $verb) {
            $bits |= self::NATIVE[$verb] ?? 0;
        }

        return $bits;
    }

    /**
     * Les verbes de plan qu'une valeur relue exprime, dans l'ordre canonique.
     *
     * Le bit de re-partage n'y figure jamais : {@see hasUnmodelledBits()} le dit
     * séparément, pour que la relecture le SIGNALE au lieu de le perdre.
     *
     * @return list<string>
     */
    public static function toVerbs(int $permissions): array
    {
        return array_values(array_filter(
            PlanGrant::VERBS,
            static fn (string $verb): bool => ($permissions & self::NATIVE[$verb]) === self::NATIVE[$verb],
        ));
    }

    /**
     * La valeur relue porte-t-elle des bits que le plan ne sait pas décrire ?
     * (aujourd'hui : le re-partage, et tout bit futur au-delà des cinq connus).
     */
    public static function hasUnmodelledBits(int $permissions): bool
    {
        return ($permissions & ~self::ALL_MODELLED) !== 0;
    }
}

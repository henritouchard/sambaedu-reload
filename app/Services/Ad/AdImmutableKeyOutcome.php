<?php

declare(strict_types=1);

namespace App\Services\Ad;

/**
 * Le verdict d'une pose de clé immuable, pour UN compte.
 *
 * Cinq positions, et **trois distinctions** portent tout le reste :
 *
 *  - `Unresolved` dit « je n'ai pas pu poser la question » (l'annuaire n'a rendu
 *    aucun `objectGUID`), `Failed` dit « j'ai posé la question et l'écriture a
 *    échoué ». Un doute et un échec ne se comptent pas ensemble — c'est la même
 *    règle que la pose d'ACL, où les confondre transforme une panne en révocation ;
 *  - `Written` dit « l'attribut était VIDE, je l'ai rempli ». `Divergent` dit
 *    « l'attribut portait DÉJÀ une autre valeur ». Les confondre laisserait un
 *    rapport annoncer « 250 à écrire » là où 250 valeurs d'un tiers seraient
 *    détruites ;
 *  - `Conforme` exige une égalité **exacte**. Une valeur qui ne devient égale
 *    qu'après nettoyage n'est pas conforme : le produit distant, lui, prend la
 *    chaîne telle quelle comme identifiant de compte.
 */
enum AdImmutableKeyOutcome: string
{
    /** La clé attendue était déjà en place, au caractère près. Rien n'a été écrit. */
    case Conforme = 'conforme';

    /** L'attribut était vide : la clé a été écrite. */
    case Written = 'written';

    /**
     * L'attribut portait DÉJÀ une autre valeur non vide.
     *
     * **Non écrasée par défaut.** L'inventaire du code SE4 et SE5 garantit que cet
     * attribut est libre *de notre fait* ; il ne dit rien d'un annuaire qu'un outil
     * tiers a touché (connecteur d'ENT, script académique, inventaire de parc). Un
     * écrasement muet y détruirait de la donnée métier sans une ligne de rapport.
     */
    case Divergent = 'divergent';

    /** L'annuaire n'a rendu aucun `objectGUID` exploitable : rien n'a été tenté. */
    case Unresolved = 'unresolved';

    /** L'écriture a été tentée et a échoué. */
    case Failed = 'failed';
}

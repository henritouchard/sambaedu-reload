---
title: Comprendre le modèle de droits
description: "Les trois briques du modèle de droits — profils, droits individuels, délégations par salle — et la règle qui décide quand un droit s'applique."
---

# Comprendre le modèle de droits

Cette fiche explique, en langage courant, comment SE5 décide de ce qu'une
personne a le droit de faire. Trois briques se combinent : les **profils de
droits**, les **droits individuels** et les **délégations par salle**.

## Où ça se passe

Tout se règle depuis le menu **Pilotage**, entrée **Gestion des droits**. Cette
fiche pose le vocabulaire ; les fiches suivantes du domaine décrivent chaque
geste.

## Les trois briques

### Les profils de droits

Un **profil de droits** est un ensemble nommé de droits, prêt à attribuer d'un
seul geste. Il en existe de deux origines :

- les **profils initiaux** — neuf profils livrés avec SE5, qui couvrent les
  rôles courants d'un établissement (voir
  [Les profils types](/admin/droits/profils-types)). Ils portent le repère
  « initial » et un cadenas : on ne peut ni les renommer, ni les modifier, ni
  les supprimer ;
- les **profils personnalisés** — ceux que vous composez vous-même quand aucun
  profil initial ne correspond au besoin (voir
  [Composer un profil de droits](/admin/droits/composer-un-profil)).

Une même personne peut porter plusieurs profils : leurs droits s'additionnent.

### Les droits individuels

En plus d'un profil, vous pouvez accorder à une personne un **droit
individuel** — par exemple « Voir les machines » — sans lui donner tout un
profil. Un droit individuel s'applique **partout dans l'établissement** : il
n'est pas limité à une salle.

Un droit reçu **par un profil** ne se retire pas isolément : pour le lui
enlever, il faut lui retirer le profil qui le porte.

### Les délégations par salle

Une **délégation** limite un droit à **une salle** — un
[groupe de postes](/glossaire#groupe-de-postes) — au lieu de l'ouvrir sur tout
l'établissement. Elle a un pendant : l'**exclusion**, qui **retire** un droit
sur une salle donnée, même à quelqu'un qui le possède partout ailleurs.

Une délégation ou une exclusion peut recevoir une **échéance** : passée cette
date, elle cesse d'elle-même de compter.

## La règle de priorité

Quand plusieurs briques se rencontrent sur une même salle, SE5 tranche toujours
dans cet ordre :

1. **une exclusion active sur la salle bloque**, même si la personne détient le
   droit au niveau de l'établissement ;
2. sinon, **un droit détenu au niveau de l'établissement ouvre toutes les
   salles** ;
3. sinon, **une délégation active n'ouvre que sa salle**.

Une délégation ou une exclusion **échue** ne compte plus : tout se recalcule
comme si elle n'existait pas.

## La portée de classe de certains profils

Deux profils initiaux n'agissent que sur les personnes de leurs **propres
classes**, jamais sur tout l'établissement : le profil **Professeur** et le
profil **Admin élèves**. Un professeur qui réinitialise un mot de passe ne peut
le faire que pour les élèves de ses classes. Cette limite est une propriété du
profil, décidée côté serveur — ce n'est pas une délégation, et elle ne se règle
pas salle par salle. Les profils **Admin utilisateurs**, **Référent numérique**
et **Super administrateur** agissent, eux, sur tout l'établissement.

## Le compte d'administration protégé

Le compte d'administration de l'établissement détient **tous les droits
d'office**, et **aucun ne peut lui être retiré**. Si vous tentez de lui enlever
un droit, l'opération l'ignore et le signale — c'est voulu : ce compte reste la
porte de secours de l'administration.

## La catégorie d'un compte n'ouvre aucun droit

Sur la fiche d'un compte, la **catégorie** affichée (élève, enseignant,
personnel administratif…) sert à l'organisation ; elle **n'accorde aucun droit
d'administration** par elle-même. Ce sont uniquement les profils et les droits
attribués qui décident de ce qu'une personne peut faire.

::: delai-effet immediat
Ces droits gouvernent l'**interface d'administration** de SE5, pas la session
Windows d'un poste : un profil attribué, un droit accordé ou une exclusion levée
valent dès le prochain chargement de page de la personne concernée. Il n'y a
rien à attendre côté poste.
:::

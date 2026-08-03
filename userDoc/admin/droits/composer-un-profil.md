---
title: Composer un profil de droits
description: "Créer un profil sur mesure avec exactement les droits voulus, le modifier, le supprimer, et voir qui le porte."
---

# Composer un profil de droits

Quand aucun des [profils types](/admin/droits/profils-types) ne correspond au
rôle d'une personne, vous pouvez composer un **profil personnalisé** avec
exactement les droits voulus. Cette fiche décrit sa création, sa modification,
sa suppression et la lecture de ses porteurs.

## Où ça se passe

Menu **Pilotage**, entrée **Gestion des droits**, onglet **Profils**. Le menu
**Actions** de la page porte l'entrée **Nouveau profil** ; un clic sur une
ligne de la liste ouvre la fiche du profil correspondant.

L'onglet présente **deux listes**, qui répondent à deux questions différentes :

- **Groupes porteurs de permissions** — quels groupes d'utilisateurs donnent un
  profil à leurs membres, et lequel. C'est ici que se règle l'attribution
  automatique (voir
  [Comprendre le modèle de droits](/admin/droits/comprendre-le-modele-de-droits)) ;
- **Profils non portés** — les profils qu'aucun groupe ne porte, et qui ne
  s'attribuent donc qu'à la main, personne par personne.

Un profil peut passer d'une liste à l'autre : le donner à un groupe, ou le lui
retirer, ne change rien à sa définition.

## Donner des permissions à un groupe

L'action **Donner des permissions à un groupe**, sur l'onglet **Profils**, relie
un groupe d'utilisateurs à un profil. À partir de là, **tous les membres du
groupe reçoivent le profil**, sans intervention compte par compte, et le
perdront en quittant le groupe.

1. Choisissez le **groupe** — la recherche ne propose que les groupes qui ne
   portent encore rien.
2. Choisissez le **profil** à lui faire porter.
3. Validez : le lien est posé **et** les membres du groupe reçoivent le profil
   dans la foulée. Il n'y a rien d'autre à lancer.

Sur une ligne de groupe porteur, deux actions : **changer le profil** et
**retirer le profil**. Dans les deux cas, l'ancien profil est repris à tous les
membres au moment du changement.

::: attention
Retirer un profil à un groupe le reprend **à tous ses membres d'un coup**. Si
certaines de ces personnes doivent le conserver, attribuez-le-leur à la main
**avant** de faire le retrait — sans quoi elles le perdront le temps de
l'opération.
:::

::: droit-requis
Il faut détenir le droit d'attribuer des droits (porté notamment par les
profils **Admin utilisateurs** et **Super administrateur**). Sans lui, la page
**Gestion des droits** répond « accès refusé ».
:::

## Créer un profil

1. Sur l'onglet **Profils**, ouvrez le menu **Actions** et choisissez
   **Nouveau profil**.
2. Donnez un **nom** au profil : il est obligatoire et doit être **unique**.
   Les noms des profils initiaux sont réservés.
3. Cochez les **droits** à inclure. Ils sont présentés par **catégorie**
   (utilisateurs, machines, applications…), chacun avec son libellé.
4. Validez avec **Créer le profil**.

## Résultat observable

Après la création, vous arrivez sur la **fiche du nouveau profil**, avec le
repère « personnalisé ». Il est dès lors proposé à l'attribution comme les
profils initiaux.

::: delai-effet immediat
Le profil est utilisable **dès sa création**. Les droits qu'il porte
s'appliquent à ses futurs porteurs au prochain chargement de page.
:::

## Modifier un profil

Depuis la fiche d'un profil, vous pouvez **renommer** le profil et **changer
les droits cochés** — mais **uniquement pour un profil personnalisé**.

La fiche d'un **profil initial** s'ouvre en **lecture seule** : le formulaire
est verrouillé et affiche le message « Rôle initial — permissions gérées par le
système ». Le bouton d'enregistrement n'y apparaît pas.

## Supprimer un profil

La suppression se fait depuis la fiche du profil (menu de la fiche) ou en masse
depuis la liste, par sélection puis **Actions**. Elle est **refusée** dans trois
cas :

- le profil est un **profil initial** ;
- le profil est **encore porté par au moins une personne** — retirez-le
  d'abord de ces comptes ;
- le profil est **porté par un groupe d'utilisateurs**. Le message nomme les
  groupes concernés : retirez-leur le profil avant de pouvoir le supprimer.
  Sans ce garde-fou, une suppression retirerait d'un coup leurs droits à tous
  les membres de ces groupes.

::: attention
La suppression d'un profil est **irréversible** : une confirmation explicite
vous est demandée avant qu'elle ne soit appliquée.
:::

## Voir qui porte un profil

La fiche d'un profil liste **les personnes qui le portent** — nom, identifiant
et statut actif ou inactif. Un clic sur une ligne ouvre la fiche du compte
correspondant. C'est le point de départ pour retirer un profil avant de
pouvoir le supprimer.

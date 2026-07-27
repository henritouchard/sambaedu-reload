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
depuis la liste, par sélection puis **Actions**. Elle est **refusée** dans deux
cas :

- le profil est un **profil initial** ;
- le profil est **encore porté par au moins une personne** — retirez-le
  d'abord de ces comptes.

::: attention
La suppression d'un profil est **irréversible** : une confirmation explicite
vous est demandée avant qu'elle ne soit appliquée.
:::

## Voir qui porte un profil

La fiche d'un profil liste **les personnes qui le portent** — nom, identifiant
et statut actif ou inactif. Un clic sur une ligne ouvre la fiche du compte
correspondant. C'est le point de départ pour retirer un profil avant de
pouvoir le supprimer.

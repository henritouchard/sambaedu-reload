---
title: Désactiver ou supprimer un compte
description: "Gérer un départ en deux temps : désactiver le compte (réversible), puis le supprimer définitivement seulement si nécessaire."
---

# Désactiver ou supprimer un compte

Cette fiche explique comment traiter le départ d'un compte. Le geste se fait en
**deux temps** : on **désactive** d'abord — une opération réversible — et on ne
**supprime définitivement** qu'ensuite, si c'est vraiment nécessaire.

## Où ça se passe

Menu **Pilotage**, entrée **Utilisateurs**. Ouvrez la fiche du compte concerné :
les deux actions se trouvent dans son menu d'actions.

::: droit-requis
Il faut être administrateur des utilisateurs.
:::

## Temps 1 — Désactiver le compte

La désactivation est l'étape normale d'un départ : elle bloque le compte sans
rien détruire, et se défait à tout moment.

1. Sur la fiche du compte, choisissez **Désactiver le compte**.
2. Confirmez la bascule dans la fenêtre qui s'ouvre.

Le compte est aussitôt **bloqué** côté serveur, marqué **Inactif** dans la
liste, et son [espace personnel](/glossaire#espace-personnel) est **archivé**.

Pour revenir en arrière, l'action **Activer le compte** restaure l'accès et le
dossier archivé (confirmation demandée là aussi).

## Temps 2 — Supprimer définitivement

La suppression n'est possible que sur un compte **déjà désactivé** : sur un
compte encore actif, le serveur la refuse et vous invite à le désactiver
d'abord.

1. Assurez-vous que le compte est **Inactif** (sinon, désactivez-le au temps 1).
2. Sur sa fiche, choisissez **Supprimer définitivement**.
3. Confirmez explicitement la suppression.

Le compte est alors retiré de l'établissement, avec son dossier personnel
archivé et ses données.

::: attention
La suppression définitive est **irréversible** : le compte et ses données ne
peuvent plus être récupérés. Pour un départ ordinaire, préférez la
désactivation, qui se défait.
:::

## Résultat observable

Après la désactivation, le compte porte le badge **Inactif** dans la liste ;
après la suppression, il disparaît de la liste.

::: delai-effet session
La désactivation ne coupe **pas** une session déjà ouverte : le blocage prend
effet à la **prochaine ouverture de session**, qui sera alors refusée.
:::

Les **comptes système** de l'établissement ne peuvent être ni désactivés ni
supprimés : le serveur refuse ces deux actions sur eux, quel que soit le droit
détenu.

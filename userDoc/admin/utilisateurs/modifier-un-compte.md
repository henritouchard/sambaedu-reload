---
title: Modifier un compte
description: "Corriger les informations d'un compte ou changer sa catégorie depuis sa fiche utilisateur."
---

# Modifier un compte

Cette fiche explique comment corriger les informations d'un compte existant et
comment changer sa catégorie ou son rattachement.

## Où ça se passe

Menu **Pilotage**, entrée **Utilisateurs**. Ouvrez la fiche du compte concerné
en cliquant sur sa ligne dans la liste (au besoin, la recherche
« Nom, prénom ou login » la retrouve dès deux caractères saisis). La fiche
porte deux formulaires : un pour les **informations personnelles**, un pour le
**changement de rôle et de catégorie**.

::: droit-requis
Il faut être administrateur des utilisateurs.
:::

## Les gestes

1. Ouvrez la **fiche** du compte à modifier.
2. Pour corriger l'état civil ou les coordonnées, agissez dans le formulaire
   des **informations personnelles** (nom, prénom, date de naissance…), puis
   enregistrez.
3. Pour changer la **catégorie** ou le **rattachement** (classes, fonction),
   utilisez le formulaire de **changement de rôle et de catégorie**, puis
   enregistrez.

## Résultat observable

Les valeurs modifiées s'affichent aussitôt à jour sur la fiche du compte, et la
liste des utilisateurs reflète le changement (catégorie, classe, statut).

::: delai-effet session
Un changement de catégorie ou de classes modifie les groupes du compte, donc ce
à quoi il a accès sur le poste. Ce nouvel accès s'applique à sa **prochaine
ouverture de session** ; une session déjà ouverte n'est pas mise à jour.
:::

Le compte **Administrator** de l'établissement fait exception : il ne se
modifie jamais et n'offre aucun menu d'actions sur sa fiche.

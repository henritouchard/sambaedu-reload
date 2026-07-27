---
title: Fonds d'écran
description: "Choisir le fond du bureau et celui de l'écran de verrouillage, pour l'établissement, une salle ou un utilisateur."
---

# Fonds d'écran

*Aussi appelé : image de fond, papier peint.*

Cette fiche explique comment fixer les fonds d'écran des postes. Deux types se
règlent séparément : le fond du **Bureau** et celui de l'**écran de
verrouillage**, celui qu'on voit avant même d'ouvrir une session.

## Où ça se passe

Selon la portée voulue, trois surfaces différentes :

- **Défaut de l'établissement** : menu **Serveur**, **Réglages**,
  **Configuration par défaut du parc**, onglets **Fond d'écran** et **Écran de
  verrouillage**.
- **Par salle** : en tête de la fiche d'une salle, la rangée **Fonds d'écran**
  propose deux vignettes, **Bureau** et **Verr.**
- **Par utilisateur** (Bureau seulement) : fiche de l'utilisateur, menu
  **Actions**, **Fond d'écran**, section **Fond d'écran personnel**.

![Rangée Fonds d'écran en tête d'une salle du Collège de Brumeville, avec les vignettes Bureau et Verrouillage](/captures/admin/applications/fonds-d-ecran/vignettes-salle.png)

::: droit-requis
Il faut détenir le droit de gestion des fonds d'écran pour agir sur une salle ou
un utilisateur. Les défauts de l'établissement sont réservés à l'administration
du serveur.
:::

## Le plus spécifique gagne

Un même poste peut être concerné par plusieurs réglages à la fois ; c'est
toujours le **plus spécifique** qui s'applique. Ainsi, un fond posé sur une
salle l'emporte sur le défaut de l'établissement.

L'écran de verrouillage, lui, **ne se personnalise jamais par utilisateur ni par
groupe d'utilisateurs** : il s'affiche avant qu'on sache qui se connecte. On ne
le règle donc qu'au niveau de l'établissement ou d'une salle. Le fond du bureau,
au contraire, peut aller jusqu'au réglage par utilisateur.

## Quand l'effet est visible

::: delai-effet agent
Le poste applique le fond au passage de son [agent](/glossaire#agent) : le fond
du bureau change pendant une session ouverte, dans la minute qui suit un
contact ; le fond de l'écran de verrouillage change **même si personne n'est
connecté** au poste.
:::

## Résultat observable

La vignette choisie remplace l'ancienne sur la surface concernée (défaut de
l'établissement, salle ou utilisateur).

::: vue-poste
Le nouveau fond apparaît sur le bureau du poste ; l'écran de verrouillage montre
sa nouvelle image dès le prochain verrouillage.
:::

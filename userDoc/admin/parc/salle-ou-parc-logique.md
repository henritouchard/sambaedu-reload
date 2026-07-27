---
title: Salle ou parc logique
description: "Comprendre les deux façons de regrouper les postes — l'emplacement et la sélection délibérée — et laquelle l'emporte quand les deux décident de la même chose."
---

# Salle ou parc logique

Cette fiche explique la différence entre une salle et un parc logique, et la
règle qui départage les deux quand ils portent un réglage concurrent.

## Deux façons de regrouper les postes

Un poste peut être regroupé de deux manières complémentaires :

- **La salle** (groupe physique) désigne l'**emplacement** du poste : une salle
  ou un bâtiment. Un poste n'appartient qu'à **une seule salle** à la fois, et
  les salles peuvent s'organiser en hiérarchie.
- **Le [parc](/glossaire#parc) logique** — aussi appelé
  [groupe de postes](/glossaire#groupe-de-postes) — est une **sélection
  délibérée de postes**, indépendante de leur emplacement. Un poste peut
  appartenir à **plusieurs parcs logiques** à la fois.

Les deux servent à rattacher des réglages et des applications aux postes qu'ils
contiennent.

## La règle de priorité

Quand plusieurs niveaux portent un réglage concurrent pour la même chose, **le
plus spécifique gagne**, dans cet ordre :

> réglage propre à l'utilisateur > groupe d'utilisateurs > poste > **parc
> logique** > **salle physique** > défaut d'établissement.

Autrement dit :

- si votre poste appartient à sa salle **et** à un parc, et que les deux
  décident de la même chose, **c'est le parc qui gagne** ;
- un réglage posé sur le poste lui-même gagne sur les deux ;
- les réglages liés à la personne connectée gagnent sur tout.

La sélection délibérée de postes qu'est un parc l'emporte donc sur le simple
emplacement.

## Un exemple concret

Une salle définit un fond d'écran, et un parc en définit un autre. Un poste qui
appartient à la fois à cette salle et à ce parc affiche **le fond d'écran du
parc** : entre les deux, le parc logique l'emporte sur la salle.

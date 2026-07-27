---
title: Raccourcis
description: "Poser des raccourcis d'application ou de lien web sur les postes, à un emplacement choisi et pour une cible choisie."
---

# Raccourcis

*Aussi appelé : icônes, liens.*

Cette fiche explique comment poser des raccourcis sur les postes : vers une
application installée ou vers un lien web, à l'emplacement voulu.

## Où ça se passe

Menu **Parc & postes**, entrée **Applications**, onglet **Raccourcis**. La liste
se filtre par type (**application** / **lien web**) et par emplacement.

::: droit-requis
Il faut détenir le droit de gestion des raccourcis.
:::

## Créer un raccourci

1. Onglet **Raccourcis**, **Nouveau raccourci**.
2. Donnez-lui un nom, choisissez son type — **application** ou **lien web** — et
   son **emplacement** : **Bureau** ou **Démarrage automatique** (l'emplacement
   **Barre des tâches** ne concerne que les postes Linux).
3. Choisissez à qui il s'applique : des [parcs](/glossaire#parc), des postes,
   des groupes d'utilisateurs ou des utilisateurs.

Un raccourci imposé par un contrat amont porte le badge **Géré par ControlHub**
et ne se modifie pas localement.

## Quand l'effet est visible

::: delai-effet agent
Le poste pose (et retire) les raccourcis lui-même au passage de son
[agent](/glossaire#agent), aux emplacements choisis — même mécanique et même
délai que les applications : en général **dans l'heure qui suit, poste allumé**.
:::

## Résultat observable

Le raccourci créé apparaît dans la liste de l'onglet, avec son type et son
emplacement. Le retrait est **symétrique** : retirer un raccourci le fait
disparaître des postes concernés au passage suivant de l'agent.

::: vue-poste
Le raccourci apparaît (ou disparaît) à l'emplacement prévu — sur le bureau, par
exemple.
:::

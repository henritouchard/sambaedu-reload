---
title: Agir sur un groupe
description: "Piloter plusieurs postes d'une salle à la fois et programmer ses allumages et extinctions automatiques."
---

# Agir sur un groupe

Cette fiche explique comment agir sur plusieurs postes d'un même groupe en une
fois, et comment programmer les allumages et extinctions d'une salle.

## Où ça se passe

Menu **Parc & postes**, entrée **Gestion du parc**, onglet **Groupes**. Cliquez
sur un groupe pour ouvrir sa page : c'est là que se pilotent ses postes.

::: droit-requis
Il faut détenir le droit **Contrôle à distance**.
:::

## Agir sur plusieurs postes à la fois

Sur la page d'un groupe, sélectionnez plusieurs postes, puis lancez sur eux, en
une seule fois, l'une des **quatre actions d'alimentation** : allumer, éteindre,
forcer l'extinction, redémarrer. L'**accès distant** est exclu du geste groupé :
il s'ouvre poste par poste.

Comme pour une machine isolée, un **message de lancement** confirme aussitôt le
départ (« lancée sur N machine(s)… »). Un poste **déjà en cours d'action** est
**ignoré** et compté à part (« … — M déjà en cours »). Un **résumé** de
l'opération reste affiché jusqu'à ce que vous l'effaciez.

Le détail de chaque action et de ce qu'il faut attendre en retour est décrit
dans [Agir sur un poste](/admin/parc/agir-sur-un-poste) : le comportement est le
même, à l'échelle d'une salle.

::: delai-effet immediat
L'ordre part **aussitôt** ; le **résultat** de chaque poste dépend de sa
disponibilité (allumé, joignable sur le réseau).
:::

::: attention
**Forcer l'extinction** sur une sélection coupe tous ces postes sans ménager les
sessions en cours : les utilisateurs connectés peuvent **perdre leur travail non
sauvegardé**.
:::

## Programmer les allumages et extinctions

La page d'un groupe permet de **programmer** des actions automatiques. Seuls
l'**allumage** et l'**extinction** se programment (ni le redémarrage, ni
l'extinction forcée). Une programmation est soit :

- **récurrente** — un ou plusieurs jours de la semaine, à une heure donnée ;
- **à date unique** — un allumage ou une extinction à une date précise.

L'**historique** des exécutions se consulte depuis la page du groupe. Une
programmation à date unique déjà passée peut être **dupliquée** pour être
rejouée à une nouvelle date.

## Résultat observable

Le résumé de l'opération groupée reste affiché avec le nombre de postes traités
et le nombre ignorés, jusqu'à effacement. Une programmation créée apparaît dans
la liste des programmations du groupe, et ses exécutions successives se lisent
dans son historique.

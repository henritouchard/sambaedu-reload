---
title: Constituer les groupes
description: "Créer un groupe de postes, choisir son type, ajouter et retirer des postes, et supprimer un groupe."
---

# Constituer les groupes

Cette fiche explique comment créer un groupe de postes, choisir son type,
composer ses membres et le supprimer.

## Où ça se passe

Menu **Parc & postes**, entrée **Gestion du parc**, onglet **Groupes**. Le
bouton **Nouveau groupe** ouvre la fenêtre de création.

::: droit-requis
Il faut détenir le droit **Installer un poste**.
:::

## Créer un groupe

1. Sur l'onglet **Groupes**, cliquez sur **Nouveau groupe**.
2. Saisissez le **nom affiché** du groupe : c'est le seul champ obligatoire.
3. Ajoutez, si vous le souhaitez, une **description**.
4. Choisissez le **type** du groupe (voir ci-dessous).
5. Validez.

L'**identifiant technique** du groupe est dérivé automatiquement du nom affiché
à la création : c'est le **seul élément figé**, il ne change plus ensuite. Tout
le reste — nom affiché, description, type et nature — reste **modifiable** par
la suite en rouvrant le groupe.

## Choisir le type à la création

Le type se choisit **à la création** parmi deux options :

- **Groupe physique** — une **salle ou un bâtiment**. Un groupe physique peut
  s'inscrire dans une hiérarchie, en désignant un groupe parent (cette
  hiérarchie est réservée au type physique).
- **Groupe logique** — un **parc de machines** : une sélection libre de postes,
  indépendante de leur emplacement.

## La nature des postes du groupe

Chaque groupe déclare la **nature de ses postes** — **Partagé**, **Personnel**
ou **Nomade**. Un poste qui appartient à plusieurs parcs hérite de la nature la
plus « forte » selon l'ordre **Nomade > Personnel > Partagé**.

## Ajouter ou retirer des postes

Les postes s'ajoutent à un groupe de deux façons : par sélection depuis l'onglet
**Postes**, ou depuis la page du groupe. On peut aussi ajouter ou retirer un
poste depuis sa propre fiche, onglet **Groupes logiques**.

Un poste peut appartenir à **plusieurs parcs logiques** en même temps, mais à
**une seule salle physique** à la fois.

::: attention
Affecter un poste à une salle le **retire automatiquement de sa salle
précédente** : la bascule se fait en un geste, sans étape intermédiaire. C'est
la règle « une salle au plus par poste ».
:::

::: delai-effet agent
Ce qu'un poste reçoit par ses groupes — réglages et applications rattachés —
s'applique au **prochain passage de l'agent**, le poste allumé et relié au
réseau.
:::

## Supprimer un groupe

Les groupes se suppriment depuis la liste, un par un ou par sélection. Certains
groupes sont **verrouillés** : gérés par une autorité amont ou protégés, ils ne
se modifient ni ne se suppriment. L'interface signale ce verrou et sa raison.

## Résultat observable

Un groupe créé apparaît aussitôt dans l'onglet **Groupes** ; un poste ajouté ou
retiré se reflète immédiatement dans la composition du groupe et sur la fiche du
poste. Un groupe verrouillé s'affiche sans les commandes de modification ni de
suppression.

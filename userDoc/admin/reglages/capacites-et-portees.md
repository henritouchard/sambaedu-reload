---
title: Capacités et portées
description: "Le comportement par défaut d'une capacité, l'écart décidé pour un groupe de postes, et la règle qui dit lequel s'applique."
---

# Capacités et portées

Une [capacité](/glossaire#capacite) est un réglage activable appliqué aux
postes — un fond d'écran, une imprimante, un lecteur réseau, une règle de
pare-feu. Cette fiche explique **où** se règle une capacité, **jusqu'où** son
réglage s'applique, et **lequel l'emporte** quand plusieurs se superposent.

Une capacité se règle à **deux étages**, qui n'ont pas la même portée.

## Étage 1 — le comportement par défaut de l'établissement

C'est la valeur qui s'applique **partout** tant que rien de plus précis n'a
été décidé : le [comportement commun à tous les postes](/glossaire#socle-commun).
**Sa portée est tout l'établissement.**

Où : menu **Serveur** → **Réglages** → carte **« Configuration par défaut du
parc »** → onglet **« Registre / capacités »**.

::: droit-requis
Il faut **administrer le serveur** pour agir sur ce défaut.
:::

L'onglet présente le catalogue des capacités, par catégorie. Chaque ligne
porte :

- son **libellé** et sa **description** (en infobulle) ;
- sa valeur **« Défaut »** — celle qui est diffusée à tous les postes ;
- un bouton **« Éditer le défaut »** pour la modifier ;
- un interrupteur **« Nouveaux overrides »** à deux états, **« Gelé »** ou
  **« Ouvert »** : « Gelé » empêche de décider de **nouveaux** écarts par
  groupe pour cette capacité (la diffusion du défaut, elle, continue).

::: attention
Une capacité **sensible** porte un pictogramme d'avertissement et exige une
**confirmation explicite** avant enregistrement. Lisez la description avant de
confirmer.
:::

## Étage 2 — un écart pour un groupe de postes

Sur un [groupe de postes](/glossaire#groupe-de-postes), on peut décider une
valeur **différente du défaut**, pour ce groupe seulement. **Sa portée est ce
groupe de postes**, et cet écart **prime sur le défaut** de l'établissement.

Où : menu **Parc & postes** → **Gestion du parc** → fiche du groupe de postes →
onglet **« Options / Capacités »**.

::: droit-requis
Il faut détenir le droit de **personnalisation des applications**. Sans lui,
l'onglet n'apparaît pas.
:::

L'onglet ne liste que les **écarts décidés pour ce groupe** — colonnes
Capacité, **« Valeur (parc) »** et « Défaut ». Trois actions :

- **« Ajouter une capacité »** — poser un nouvel écart ;
- **« Éditer »** — changer la valeur de l'écart ;
- **« Retirer »** — **revenir au défaut de l'établissement** (et non « cesser
  de gérer » la capacité).

Quand aucun écart n'est posé, l'onglet l'annonce : « Aucun override pour ce
parc — toutes les capacités appliquent leur valeur par défaut. »

## Y a-t-il un réglage par poste ?

**Non.** Un écart de capacité se décide uniquement sur un groupe de postes : la
maille la plus fine d'un réglage de capacité est le **groupe**, jamais le poste
seul. Pour donner à un poste un réglage qui n'appartient qu'à lui, la seule
voie est de le **placer dans son propre groupe** et de poser l'écart sur ce
groupe.

## Lequel s'applique : le plus précis l'emporte

Quand plusieurs réglages coexistent pour un même poste, **le réglage le plus
précis s'applique** :

- le réglage d'un **groupe** prime sur le **défaut** de l'établissement ;
- si un poste appartient à la fois à une **salle** et à un **parc logique**,
  c'est le **parc logique** qui l'emporte.

Le défaut de l'établissement ne s'applique donc qu'**en l'absence** d'un
réglage plus précis.

## Si l'établissement est rattaché à un pilotage central

Dans ce cas seulement, une capacité peut porter un badge :

- **« Verrouillé »** — la valeur est **imposée par contrat** et ne peut être
  modifiée nulle part ;
- **« Modifiable »** — l'amont propose une valeur, mais **votre réglage local
  prévaut**.

Sans rattachement, aucun de ces badges n'apparaît.

## Quand l'effet est-il visible ?

Certaines capacités portent un badge de temporalité qui annonce quand le
changement devient effectif sur le poste :

- **« Immédiat »** — dès que le poste applique le réglage ;
- **« Immédiat (le bureau redémarre) »** — de même, mais le bureau de
  l'utilisateur se recharge ;
- **« À la prochaine session »** — à la prochaine ouverture de session.

Une capacité **sans badge** s'applique côté machine, au passage de
l'[agent](/glossaire#agent).

::: delai-effet agent
Le poste doit être allumé et relié au réseau pour que l'agent applique le
réglage — au plus tard à son prochain contact avec le serveur.
:::

::: vue-poste
Ce que constate l'utilisateur du poste suit le badge de temporalité de la
capacité : un changement « À la prochaine session » n'est visible qu'après une
reconnexion.
:::

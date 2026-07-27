---
title: Lire l'état d'un poste
description: "Retrouver un poste dans la liste, comprendre son état de présence et sa conformité, et ouvrir sa fiche détaillée."
---

# Lire l'état d'un poste

Cette fiche explique où trouver la liste des postes, comment lire d'un coup
d'œil l'état d'une machine, et ce que montre sa fiche détaillée.

## Où ça se passe

Menu **Parc & postes**, entrée **Gestion du parc**. La page présente trois
onglets — **Groupes**, **Postes** et **Imprimantes**. L'onglet **Postes** liste
les machines de l'établissement.

::: droit-requis
Il faut détenir le droit **Voir les machines**. Si ce droit ne vous a été confié
que sur certains groupes, la liste ne montre que les postes de ces groupes.
:::

## Retrouver un poste

L'onglet **Postes** offre plusieurs façons de restreindre la liste :

- une **recherche** par nom de poste ;
- des **filtres** par système et par groupe ;
- un filtre de **présence** — **Tous**, **Allumés** ou **Éteints** ;
- des **tuiles de statistiques** cliquables (dont les postes « sans groupe » et
  les postes muets) qui filtrent la liste quand on clique dessus.

Un bouton de **réinitialisation** remet tous les filtres à zéro. Gardez ce
bouton en tête : une tuile ou un filtre resté actif est la première cause d'une
liste qui paraît incomplète.

## Comprendre la présence d'un poste

La présence d'un poste est **déduite des signes de vie de son agent** ; elle
n'est pas une mesure en temps réel. Quatre états sont possibles :

- **Allumé** — le poste a donné signe de vie récemment.
- **Éteint** — le poste a lui-même signalé son extinction.
- **Éteint ou injoignable** — plus aucun signe de vie depuis longtemps, sans
  signal d'extinction : le poste est **probablement éteint, sans certitude**.
- **Présence inconnue** — le poste n'a pas d'agent, sa présence ne peut pas être
  déduite.

::: attention
La détection du silence se compte **de l'ordre de l'heure**, pas à la seconde.
Un poste coupé brutalement peut rester affiché **Allumé** un moment avant de
basculer sur **Éteint ou injoignable**.
:::

## La conformité d'un poste

Chaque poste équipé de l'agent porte aussi un badge de **conformité** : il
indique si l'état réellement appliqué sur le poste correspond à l'état décidé
sur le serveur (conforme, en écart, en erreur, muet, ou jamais rapporté). Les
postes sans agent n'ont pas ce badge.

## Ouvrir la fiche d'un poste

Cliquer sur un poste ouvre sa **fiche détaillée**. En tête, elle rappelle son
état — présence, protection, mise en quarantaine. La fiche se découpe ensuite en
onglets :

- **Général** — la salle physique du poste et ses informations techniques ; la
  salle s'y assigne, se change et se retire.
- **Groupes logiques** — les [parcs](/glossaire#parc) auxquels le poste
  appartient, avec leur compteur ; on les y ajoute et on les y retire.

La fiche porte aussi des onglets d'applications, de réglages et d'agent :
leur contenu relève d'autres domaines de ce guide.

## Résultat observable

La liste reflète aussitôt vos filtres, et l'état de présence comme le badge de
conformité se lisent directement en face de chaque poste. La fiche d'un poste
s'ouvre sur son état et ses onglets dès le clic.

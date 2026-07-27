---
title: Affecter une application à des postes
description: "Décider quels postes reçoivent un logiciel : par profil applicatif, par groupe, par poste, ou pour tout l'établissement."
---

# Affecter une application à des postes

*Aussi appelé : installer un logiciel, déployer.*

Une fois une application au
[catalogue](/admin/applications/catalogue-et-depot), il reste à décider **quels
postes** la reçoivent. Cette fiche présente les trois façons de le faire, plus le
[socle commun](/glossaire#socle-commun) valable partout.

## Où ça se passe

Menu **Parc & postes**, entrée **Applications**, principalement les onglets
**Catalogue d'Applications** et **Profils Applicatifs**. L'affectation directe se
fait aussi depuis la fiche d'un groupe ou d'un poste (onglet **Applications**).

![Fiche d'une application du Collège de Brumeville, avec la liste des profils qui la portent et la carte Déploiement sur les postes](/captures/admin/applications/affecter-une-application/fiche-application.png)

::: droit-requis
Il faut être administrateur des applications du parc concerné. Ce droit peut
être **délégué salle par salle** : une personne peut alors affecter des
applications sur sa salle uniquement.
:::

## Le profil applicatif : la voie de référence

Un [profil applicatif](/glossaire#profil-applicatif) est un ensemble nommé
d'applications, qu'on rattache ensuite à des postes. C'est la façon la plus
lisible de gérer un usage : « le profil Bureautique », « le profil Arts
plastiques ».

1. Onglet **Profils Applicatifs**, **Nouveau Profil Applicatif** : donnez-lui un
   nom (obligatoire et unique) et, si utile, une description.
2. Sur la fiche du profil, l'onglet **Applications** compose son contenu ; les
   onglets **Groupes de postes** et **Postes** le rattachent aux postes visés.

Un profil rattaché à un groupe **parent vaut aussi pour ses sous-groupes** : la
fiche du profil peut afficher les groupes ainsi hérités.

## Affecter directement à un groupe

Deux portes mènent au même résultat :

- depuis l'onglet **Catalogue d'Applications**, sélectionnez une ou plusieurs
  applications puis **Déployer sur un groupe**, et choisissez parmi les salles
  et les parcs proposés ;
- depuis la fiche d'un [parc](/glossaire#parc) (onglet **Applications**), les
  cartes **Profils applicatifs** et **Applications directes** permettent
  d'**Ajouter** l'un ou l'autre.

## Affecter directement à un poste

Sur la fiche d'un poste, l'onglet **Applications** propose **Ajouter
directement** une application à ce seul poste. Cette même fiche distingue ce qui
est affecté **directement** au poste de ce dont il hérite par ses groupes (repère
« via … »).

## Le socle commun : pour tous les postes

Les applications à installer **partout** se désignent ailleurs : menu
**Serveur**, **Réglages**, carte **Configuration par défaut du parc**, onglet
**Applications** (bouton **Appliquer par défaut**). Elles s'ajoutent à ce que
chaque poste reçoit par ailleurs. Cette page est réservée à l'administration du
serveur ; si l'entrée **Réglages** n'apparaît pas dans votre menu, ce droit ne
vous a pas été confié.

## Quand l'effet est visible

::: delai-effet agent
Le poste récupère la décision tout seul — peu après son démarrage ou l'ouverture
d'une session, puis régulièrement dans la journée — et installe lui-même ce qui
lui manque. En général, l'application est là **dans l'heure qui suit, poste
allumé**.
:::

Pour accélérer un poste précis, la fiche de ce poste propose **Forcer la
synchro** : la demande est prise en compte au prochain contact du poste (voir le
domaine [Parc et postes](/admin/parc/agir-sur-un-poste)).

## Résultat observable

Côté serveur, deux repères confirment que le déploiement avance : la colonne
**Déploiement** de l'onglet Catalogue (installés / visés), et, sur la fiche de
l'application, la carte **Déploiement sur les postes** — barre de progression et
onglets **Succès** / **Échecs** / **En cours**, avec le statut de chaque poste.
La même fiche liste aussi **les profils qui portent l'application**, utile pour
comprendre par où elle arrive.

::: vue-poste
L'application apparaît dans le menu Démarrer du poste, prête à l'emploi.
:::

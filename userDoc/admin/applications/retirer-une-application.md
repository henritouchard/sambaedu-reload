---
title: Retirer une application
description: "Retirer un logiciel d'un ensemble de postes : le geste par chaque voie, et le fait que retirer, c'est désinstaller."
---

# Retirer une application

*Aussi appelé : désinstaller, enlever un logiciel.*

Cette fiche décrit comment retirer une application des postes qui la reçoivent.
Le retrait est le **symétrique** de l'[affectation](/admin/applications/affecter-une-application) :
on défait par la même voie qu'on a faite.

## Où ça se passe

Menu **Parc & postes**, entrée **Applications**, ou depuis la fiche du
[parc](/glossaire#parc) ou du poste concerné, onglet **Applications**.

::: droit-requis
Il faut être administrateur des applications du parc concerné — le même droit
que pour affecter, délégable salle par salle.
:::

## Retirer par chaque voie

- **D'un [profil applicatif](/glossaire#profil-applicatif)** : sur la fiche du
  profil, onglet **Applications**, retirez l'application. Elle disparaît de tous
  les postes rattachés à ce profil.
- **D'un groupe ou d'un poste** : sur la fiche du groupe ou du poste, onglet
  **Applications**, retirez le profil rattaché ou l'application affectée
  directement.
- **Du [socle commun](/glossaire#socle-commun)** : menu **Serveur**,
  **Réglages**, **Configuration par défaut du parc**, onglet **Applications**,
  **Retirer** (avec confirmation).
- **En supprimant un profil entier** : le profil disparaît de tous les postes
  qui le portaient.

## Retirer, c'est désinstaller

::: attention
Retirer une application d'un profil, d'un groupe, d'un poste ou du socle commun
la fait **réellement désinstaller** des postes concernés : le poste s'en occupe
tout seul, au passage suivant de son [agent](/glossaire#agent). Un logiciel qui a
été installé à la main sur un poste, en dehors de SE5, n'est **jamais** touché.
:::

## Quand l'effet est visible

::: delai-effet agent
Le poste récupère la décision tout seul — peu après son démarrage ou l'ouverture
d'une session, puis régulièrement dans la journée — et désinstalle lui-même ce
qui n'est plus prévu. En général, c'est fait **dans l'heure qui suit, poste
allumé**.
:::

## Résultat observable

Les compteurs de la colonne **Déploiement** (onglet Catalogue) et de la carte
**Déploiement sur les postes** de la fiche d'application redescendent à mesure
que les postes désinstallent l'application.

::: vue-poste
L'application disparaît du menu Démarrer du poste.
:::

Pour retirer une application de **tout l'établissement** et l'effacer du
catalogue, ce n'est pas ce geste-ci mais **Supprimer l'installation**, décrit
dans [Le catalogue et le dépôt](/admin/applications/catalogue-et-depot).

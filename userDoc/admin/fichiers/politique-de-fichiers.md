---
title: Régler la politique de fichiers
description: "Décider, pour tout l'établissement, quels espaces de fichiers sont mis à disposition sur les postes."
---

# Régler la politique de fichiers

Cette fiche explique comment décider, **pour tout l'établissement**, quels
espaces de fichiers sont montés sur les postes : l'[espace personnel](/glossaire#espace-personnel)
de chaque compte, les [partages](/glossaire#partage) réseau, ou aucun des deux.

## Où ça se passe

Menu **Serveur**, entrée **Réglages**, carte **Gestion des fichiers**, onglet
**Personnels et partagés**.

::: droit-requis
Il faut être administrateur du serveur. L'entrée **Réglages** n'apparaît que pour
les personnes qui en détiennent le droit.
:::

## Trois interrupteurs, valables pour tout l'établissement

L'onglet **Personnels et partagés** présente **trois interrupteurs
indépendants**. Ils s'appliquent à **tout l'établissement** — il n'existe aucun
réglage par salle ni par [parc](/glossaire#parc) : ce que vous décidez ici vaut
pour tous les postes et tous les utilisateurs.

- **Répertoire personnel (K:)** — commande le lecteur `K:` « Mes documents », le
  dossier personnel que chaque utilisateur retrouve d'un poste à l'autre. Ce que
  l'utilisateur y range est décrit dans
  [Mon espace personnel](/poste/fichiers/espace-personnel). Activé par défaut.
- **Partages réseau (H:)** — commande à la fois le lecteur `H:` « Classes » **et
  tous les lecteurs réseau gérés** de l'établissement. Ce que l'utilisateur y
  trouve est décrit dans
  [Les espaces partagés](/poste/fichiers/espaces-partages) ; pour savoir où
  enregistrer, voir aussi [D'un poste à l'autre](/poste/fichiers/dun-poste-a-lautre).
  Activé par défaut.
- **Nextcloud natif** — cette option est visible à l'écran mais **n'est pas
  encore activable**.

Quand les trois interrupteurs sont désactivés, l'accès est **« web
uniquement »** : aucun lecteur n'est monté sur aucun poste.

## Les gestes

1. Ouvrez le menu **Serveur**, entrée **Réglages**.
2. Ouvrez la carte **Gestion des fichiers**.
3. Restez sur l'onglet **Personnels et partagés**.
4. Activez ou désactivez chaque interrupteur selon ce que l'établissement doit
   proposer.

Il n'y a **pas de bouton Enregistrer** : chaque bascule est enregistrée
immédiatement. Un indicateur **Enregistré** confirme la prise en compte en haut
du formulaire.

## Résultat observable

Le récapitulatif **Effet sur le poste**, sous les interrupteurs, se met à jour
aussitôt et annonce ce que tout utilisateur verra à sa prochaine connexion : le
lecteur `K:` si le répertoire personnel est actif, le lecteur `H:` si les
partages réseau le sont, ou l'avertissement **Web uniquement** si tout est
désactivé.

::: delai-effet session
Le récapitulatif l'énonce lui-même : « Au prochain logon, tout utilisateur
voit… ». Une session déjà ouverte n'est pas modifiée.
:::

::: vue-poste
Selon vos choix, l'utilisateur voit apparaître ou disparaître les lecteurs
`K:` (« Mes documents ») et `H:` (« Classes ») dans son explorateur de fichiers,
à sa prochaine ouverture de session.
:::

::: attention
Désactiver un espace ne supprime **aucun fichier**. Le lecteur disparaît de tous
les postes, mais les fichiers restent en place sur le serveur et réapparaissent
si vous réactivez l'interrupteur.
:::

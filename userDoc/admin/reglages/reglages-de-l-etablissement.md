---
title: Les réglages de l'établissement
description: "La page Réglages : ce qu'on y traite, ce qui relève d'un autre domaine, et la déconnexion automatique de l'interface."
---

# Les réglages de l'établissement

Cette fiche présente la page **Réglages** — le point d'entrée des réglages du
serveur — et explique où chacun de ses sujets se traite. Elle décrit sur place
un réglage simple et utile : la déconnexion automatique de l'interface.

## Où ça se passe

Menu **Serveur**, entrée **Réglages**.

::: droit-requis
Il faut **administrer le serveur**. L'entrée **Réglages** n'apparaît qu'aux
personnes qui détiennent ce droit.
:::

La page est un **sommaire** : elle regroupe des cartes en quatre sections —
**« Système »**, **« Agent / Flotte »**, **« Migration »** et
**« Réseau & intégrations »**. Chaque carte porte un titre, une courte
description, et mène à une page dédiée.

## Ce que couvre ce guide, et ce qui relève d'ailleurs

Toutes les cartes de cette page ne sont pas des réglages d'administration
courante. Voici comment vous y retrouver.

**Traité dans ce domaine :**

- **« Console de la flotte »** — les indicateurs de version et de conformité de
  l'ensemble des postes : voir
  [Un poste en règle ou en retard](/admin/reglages/poste-en-regle-ou-en-retard).
- **« État du système »** — la vérification des connexions du serveur :
  voir [L'état du système](/admin/reglages/etat-du-systeme).
- **« Réseau DHCP »** — les adresses réseau des postes :
  voir [Les adresses réseau des postes](/admin/reglages/reseau-dhcp).
- **« Configuration par défaut du parc »**, pour son volet des capacités :
  voir [Capacités et portées](/admin/reglages/capacites-et-portees).
- **« Sécurité & session »** — la déconnexion automatique de l'interface,
  décrite ci-dessous.

**Traité dans un autre domaine :**

- **« Gestion des fichiers »** — les espaces de fichiers mis à disposition sur
  les postes : voir [Régler la politique de fichiers](/admin/fichiers/politique-de-fichiers).
- **« OS »** — les systèmes que l'on pourra installer sur les postes :
  voir [Préparer les systèmes](/admin/installer/preparer-les-systemes).
- Les onglets **fond d'écran**, **écran de verrouillage** et **applications**
  de la « Configuration par défaut du parc » relèvent du domaine des
  applications : voir [Fonds d'écran](/admin/applications/fonds-d-ecran) et
  [Le catalogue et le dépôt](/admin/applications/catalogue-et-depot).

**Ni l'un ni l'autre :** plusieurs cartes restantes ne relèvent pas de
l'administration courante mais de l'**exploitation du serveur** — outillage
technique, reprise de données depuis SE4, liaison à un pilotage central. Ce
guide ne les documente pas ; n'y intervenez pas sans l'accompagnement de la
personne qui exploite le serveur.

## La déconnexion automatique de l'interface

Le réglage **« Sécurité & session »** (section « Système ») ferme
automatiquement votre session de l'interface après une période d'inactivité —
utile sur un poste partagé. **Sa portée est tout l'établissement** : il vaut
pour toute l'interface d'administration.

1. Ouvrez la carte **« Sécurité & session »**.
2. Activez l'interrupteur **« Activer la déconnexion automatique »**.
3. Renseignez le **« Délai d'inactivité avant déconnexion (minutes) »** — une
   valeur comprise **entre 5 et 1440 minutes**.
4. Enregistrez.

Le délai est **remis à zéro à chaque action** : seule une inactivité continue
plus longue que le délai déclenche la déconnexion. Réglage désactivé, la
session suit la valeur par défaut du serveur (24 heures).

::: delai-effet immediat
:::

## Résultat observable

Un **message de confirmation** s'affiche à l'enregistrement. Le réglage prend
effet aussitôt pour l'ensemble de l'interface.

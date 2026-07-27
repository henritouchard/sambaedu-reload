---
title: Réinstaller un poste
description: "Repartir d'une installation saine sur un poste déjà connu, depuis l'interface : les trois portes, le choix du système, le suivi à six états, l'annulation et la relance, avec la double sécurité contre l'effacement accidentel."
---

# Réinstaller un poste

Cette fiche décrit comment repartir d'une installation propre sur un poste
**déjà déclaré**. Contrairement à l'installation d'un poste neuf, la
réinstallation se pilote **entièrement depuis l'interface web** : le poste
redémarre et se réinstalle sans intervention devant l'écran.

::: droit-requis
Il faut détenir le droit **« Installer un poste »**. Sans lui, les entrées de
réinstallation n'apparaissent pas. C'est un droit à l'échelle de l'établissement,
non délégable à une seule salle.
:::

## Où ça se passe

La réinstallation s'ouvre depuis **trois portes**, toutes dans le menu **Parc &
postes**, entrée **Gestion du parc** :

- **la fiche d'un poste** — menu **Actions**, section **« Système »**,
  **« Réinstaller le poste »** ;
- **la fiche d'une salle ou d'un [groupe](/glossaire#groupe-de-postes)** — **« Réinstaller la salle »** ;
- **la sélection multiple** sur la liste des postes — **« Réinstaller la
  sélection »**.

## Les gestes

1. Ouvrez la réinstallation par l'une des trois portes ci-dessus.
2. Dans la fenêtre, choisissez le **système à installer** (listes Windows et
   Linux — les systèmes que vous avez [préparés](/admin/installer/preparer-les-systemes)).
3. Choisissez le moment : **« Maintenant »** ou **« Planifier »** (date et
   heure). En « Maintenant », l'écran annonce : « Le poste sera forcé à
   redémarrer au prochain tick (≤ 60 s), dans la limite du plafond de charge
   configuré. » — autrement dit, dans la minute qui suit si le poste est
   joignable.
4. Confirmez. Deux garde-fous se succèdent : l'avertissement permanent
   « Cette opération EFFACE le disque et réinstalle l'OS choisi. Irréversible. »,
   puis une **confirmation chiffrée** rappelant le nombre exact de postes
   concernés (« Vous allez EFFACER N poste(s) et réinstaller … Irréversible. »).

![La fenêtre de réinstallation, avec le choix du système à installer, repère 2, et le choix du moment Maintenant ou Planifier, repère 3](/captures/admin/installer/reinstaller-un-poste/fenetre-de-reinstallation.png)

::: attention
La réinstallation **efface le disque du poste** et y réinstalle le système
choisi. L'opération est **irréversible**.
:::

## Les postes protégés ne se réinstallent jamais

Un poste **protégé** ne peut pas être réinstallé, quelle que soit la porte
utilisée : il porte un badge « Protégé », sa case de sélection est désactivée
(« Poste protégé — non réinstallable »), son entrée de menu est inerte (« Poste
protégé — réinstallation impossible »), et le serveur refuse toute demande le
visant. Une réinstallation déjà armée est même annulée d'office si le poste
devient protégé entre-temps.

Sur une salle ou une sélection, les postes protégés et ceux **déjà en chantier**
sont simplement **ignorés** ; le compte rendu le dit : « N poste(s) armé(s),
N déjà en cours, N protégé(s) ignoré(s). » Un poste ne porte qu'**une seule
réinstallation à la fois** (« Ce poste a déjà une réinstallation en cours. »).

## Suivre la réinstallation

La fiche du poste porte un **badge d'état**, dont les libellés exacts sont :

- « Réinstallation programmée »
- « Réinstallation démarrée »
- « Installation en cours »
- « Réinstallation terminée »
- « Réinstallation échouée »
- « Réinstallation annulée »

Sur la fiche d'une salle ou d'un groupe, un panneau **« Réinstallations en cours
(N) »** liste les postes concernés (colonnes Poste / OS / État / Planifiée), avec
une action **Annuler** par ligne.

## Annuler, relancer

- **Annuler** reste possible **tant que l'installation n'a pas réellement
  commencé**. Une fois l'état « Installation en cours », l'annulation disparaît :
  le serveur ne pourrait plus arrêter la machine.
- **Relancer.** Si une réinstallation n'aboutit pas, **« Relancer la
  réinstallation »** est proposé (« La tentative en cours sera abandonnée et le
  poste redémarrera pour repartir de zéro. »).
- Une réinstallation qui n'aboutit jamais finit **« échouée » d'elle-même**, par
  un garde-fou de délai et de tentatives.

## Ce qui se passe côté poste

Le serveur force le redémarrage du poste (avec une tentative de réveil par le
réseau s'il est éteint). Au redémarrage, le poste part **automatiquement** sur
l'installation choisie, sans intervention au clavier. Une planification future
n'est **jamais** servie avant l'heure : d'ici là, le poste démarre normalement.

::: delai-effet agent
Le poste doit être **joignable** — allumé, ou réveillable par le réseau — pour
redémarrer. Le réveil par le réseau n'atteint pas toutes les architectures : un
poste dans un segment isolé peut ne pas être réveillé à distance. Le suivi se lit
dans l'interface.
:::

::: vue-poste
Le poste redémarre puis s'installe seul, sans intervention de l'utilisateur.
**Toute session ouverte est perdue** et le contenu du disque est effacé.
:::

## Résultat observable

Le badge de la fiche du poste passe à **« Réinstallation terminée »**, et le
poste reprend sa place dans l'établissement. Confirmez-le avec
[Vérifier la mise en service](/admin/installer/verifier-la-mise-en-service).

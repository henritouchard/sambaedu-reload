---
title: Installer un poste neuf
description: "Déclarer puis installer une machine neuve, à son écran de démarrage par le réseau : s'identifier, nommer le poste, l'affecter, lancer l'installation. L'ordre déclarer-puis-installer est imposé."
---

# Installer un poste neuf

Cette fiche décrit la mise en service d'une machine neuve. **Ces gestes se font à
l'écran du poste lui-même**, sur les menus de démarrage par le réseau — pas dans
l'interface web. Il n'existe aucun bouton « Nouvelle machine » dans l'interface :
un poste se déclare devant l'écran, puis se vérifie dans « Gestion du parc ».

::: droit-requis
Il faut détenir le droit **« Installer un poste »**. Le poste vérifie votre
identité **et** ce droit à chaque écran ; c'est un droit à l'échelle de
l'établissement, non délégable à une seule salle.
:::

## Contexte : où se passent ces gestes

Devant le poste, au démarrage. Prévoyez les [prérequis](/admin/installer/prerequis)
et le système à installer déjà [préparé](/admin/installer/preparer-les-systemes)
côté serveur.

## Les gestes

1. **Faites démarrer le poste par le réseau.** L'écran affiche un menu de
   démarrage proposant notamment « (1) Acces au menu d'administration » et
   « (0) Quitter iPXE et booter le disque dur ».
   *Rassurant : sans intervention, à l'expiration du délai, le poste démarre
   normalement sur son disque. Le démarrage par le réseau n'installe jamais rien
   tout seul.*
2. **Ouvrez le menu d'administration et identifiez-vous** avec **votre compte
   SE5**. Le serveur vérifie votre identité et votre droit à chaque écran ; des
   identifiants invalides ou un droit manquant mènent à un écran d'échec puis au
   retour au démarrage. Votre mot de passe n'est jamais conservé.
3. **Nommez le poste** avec l'entrée « Nommer le poste (enregistrement) ». Saisissez
   le nom au clavier. Le poste répond :
   - « OK ! nom … reserve » si le nom est accepté ;
   - « ERREUR ! nom … indisponible » suivi du motif si le nom est déjà pris ;
   - un message signalant que la machine est déjà enregistrée sous ce nom, le cas
     échéant ;
   - « ATTENTION : sync AD echouee - verifiez avec admin SE5 » si la
     synchronisation avec l'annuaire de l'établissement a échoué (à traiter, voir
     [En cas de problème](/admin/installer/en-cas-de-probleme)).
4. **Affectez le poste à une salle ou à un [parc](/glossaire#parc)** depuis le
   même menu (« Affecter a une salle physique », « Ajouter a un parc logique »).
   La constitution des salles et des parcs elle-même relève du
   domaine [Parc et postes](/admin/parc/).
5. **Lancez l'installation.** Choisissez « Installation Windows (Win10/Win11) »
   ou « Installation Linux (Debian/Ubuntu) ». Le menu **refuse un poste non
   encore enregistré** : on déclare d'abord, on installe ensuite.

::: attention
Lancer une installation **efface le disque du poste** et y installe le système
choisi. Ne lancez cette étape que sur une machine neuve ou destinée à repartir de
zéro.
:::

## Résultat observable

- À l'écran du poste, la déclaration répond « OK ! nom … reserve ».
- Dans l'interface, le poste **apparaît dans « Gestion du parc »** (menu **Parc &
  postes**), avec son nom et, sous le nom, son identifiant technique dans
  l'annuaire de l'établissement.

::: delai-effet immediat
Le poste apparaît dans « Gestion du parc » dès son enregistrement, avant même la
fin de l'installation.
:::

L'installation joint ensuite le poste à l'établissement et se termine d'elle-même
— à la première ouverture de session d'accueil pour Windows. Vérifiez que tout
est en ordre avec [Vérifier la mise en service](/admin/installer/verifier-la-mise-en-service).

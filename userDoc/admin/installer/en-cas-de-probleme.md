---
title: En cas de problème
description: "Un poste qui ne démarre pas sur le réseau, une installation qui s'arrête, un poste installé mais pas rattaché à l'établissement : que vérifier dans l'interface."
---

# En cas de problème

Cette fiche part de trois situations d'installation et donne, pour chacune, ce
qu'il faut vérifier **dans l'interface**. Quand une vérification dépasse
l'interface, c'est signalé et renvoyé à l'exploitation du serveur, sans
procédure.

## Le poste ne démarre pas sur le réseau

- Vérifiez le **service d'adressage réseau** : la page « Réseau DHCP » affiche une
  bannière d'alerte s'il est arrêté (« Service DHCP injoignable… »). Tant que
  cette bannière est présente, aucun poste ne peut obtenir d'adresse pour
  s'installer.
- Lancez le contrôle **« iPXE »** de la page « État du système » : il vérifie la
  présence des sources d'installation et les informations de rattachement à
  l'établissement, avec un verdict et un conseil de correction.
- L'**ordre de démarrage** du poste (démarrer par le réseau avant le disque)
  relève du réglage matériel de la machine ; s'il n'est jamais proposé, c'est à
  vérifier sur le poste lui-même.
- Si le démarrage par le réseau n'est orienté vers le serveur sur **aucun**
  poste, l'orientation elle-même est à revoir : c'est une intervention
  d'exploitation, rapprochez-vous de la personne qui exploite le serveur.

## L'installation s'arrête

- Lisez le **badge d'état** sur la fiche du poste. « Réinstallation échouée »
  signale un échec : proposez **« Relancer la réinstallation »** pour repartir de
  zéro.
- **Carte réseau non reconnue** pendant l'installation Windows : fournissez les
  **pilotes réseau** sur la page « Gestion ISO Windows », puis « Réappliquer les
  pilotes ». Voir [Préparer les systèmes](/admin/installer/preparer-les-systemes).
- **Source du système absente ou en échec** : contrôlez la page « OS
  installables ». Une carte « Manquante » (Linux) ou « Aucune version »
  (Windows), ou le détail d'un dernier essai échoué, indique une source à
  (re)préparer.
- **Poste protégé** : un poste protégé n'est jamais réinstallé. Si vous attendiez
  une réinstallation qui ne part pas, vérifiez qu'il ne porte pas le badge
  « Protégé ».

## Le poste est installé mais pas rattaché à l'établissement

- Repensez au message vu à la déclaration : « ATTENTION : sync AD echouee -
  verifiez avec admin SE5 » signale que la synchronisation avec l'annuaire avait
  échoué au moment de nommer le poste.
- Lancez le contrôle des **informations de rattachement** dans la section
  « iPXE » de « État du système » : c'est lui qui vérifie ce qui permet au poste
  de rejoindre l'établissement.
- Dans « Gestion du parc », vérifiez que le poste affiche bien, **sous son nom,
  son identifiant technique** dans l'annuaire. Son absence indique un
  rattachement incomplet.
- Si ces vérifications sont au vert mais que le poste n'est toujours pas rattaché,
  le point à examiner dépasse l'interface : rapprochez-vous de la personne qui
  exploite le serveur.

---
title: L'état du système
description: "La page de vérification des connexions du serveur : lancer les contrôles, lire les verdicts, savoir qui doit corriger."
---

# L'état du système

Cette fiche décrit la page **« État du système »**, qui vérifie que les
différentes briques du serveur sont bien joignables, et explique comment lire
ses verdicts.

## Où ça se passe

Menu **Serveur** → **Réglages** → section « Système » → carte
**« État du système »**.

::: droit-requis
Il faut **administrer le serveur**.
:::

Les vérifications se lancent **automatiquement à l'ouverture** de la page
(« Vérification des connexions en cours… »). Vous pouvez ensuite les relancer à
la demande avec **« Rafraîchir »** ; l'heure du dernier contrôle est indiquée
(« Dernière vérification à HH:MM:SS »).

![La page « État du système » du serveur, avec ses cinq blocs de vérification et leur badge de résultat, et le bouton de relance.](/captures/admin/reglages/etat-du-systeme/verification-des-connexions.png)

## Les cinq contrôles

La page présente **cinq blocs**, chacun avec un badge **« OK »**,
**« Attention »** ou **« Erreur »** :

- **« Active Directory »** — l'annuaire des comptes et des postes ;
- **« Base de données »** — la base de données de SE5 ;
- **« controlHub »** — la liaison à un pilotage central, s'il y en a un ;
- **« Apache »** — le serveur web qui sert l'interface ;
- **« iPXE »** — le démarrage des postes par le réseau.

Chaque contrôle donne un **verdict**, un **détail**, et — le cas échéant — un
**conseil de correction** signalé par un pictogramme de clé à molette.

## Que faire d'un verdict « Erreur »

Un conseil de correction s'adresse le plus souvent à la **personne qui exploite
le serveur** : relancer une brique, rétablir une liaison ou libérer une
ressource ne se fait pas depuis l'administration courante. Si un bloc reste en
**« Erreur »** après un **« Rafraîchir »**, rapprochez-vous de cette personne —
voir [En cas de problème](/admin/reglages/en-cas-de-probleme).

L'onglet **« Logs »** de cette page rassemble des journaux d'erreurs
techniques : c'est un outil de **diagnostic destiné à l'exploitation**, pas un
écran d'administration courante.

## Résultat observable

À l'ouverture, puis à chaque **« Rafraîchir »**, chaque bloc affiche son badge
et l'heure du contrôle se met à jour. Tous les badges au vert (« OK ») : les
connexions du serveur sont saines.

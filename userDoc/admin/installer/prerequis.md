---
title: Prérequis
description: "Ce qui doit être en place avant d'installer un poste : démarrage par le réseau, adressage, sources d'installation, rattachement à l'établissement, et les droits nécessaires."
---

# Les prérequis avant d'installer un poste

Cette fiche énonce ce qui doit être en place **avant** de déclarer ou d'installer
une machine. Ce ne sont pas des gestes à faire à la chaîne : ce sont des
préalables à vérifier, dont certains relèvent de l'installation du serveur et
d'autres se contrôlent directement dans l'interface.

## Ce qui doit être en place

- **Le démarrage par le réseau doit être opérationnel et orienté vers le
  serveur SE5.** C'est ce qui permet à un poste neuf d'afficher les menus
  d'installation au lieu de démarrer sur son disque. Cette orientation se met en
  place à l'installation du serveur ; si elle n'est pas en place, c'est une
  intervention d'exploitation — rapprochez-vous de la personne qui exploite le
  serveur. Cette fiche ne la détaille pas.
- **Le service d'adressage réseau doit être actif.** Sans lui, un poste qui
  démarre par le réseau n'obtient pas d'adresse et ne peut rien installer. La
  page « Réseau DHCP » affiche une bannière d'alerte lorsque ce service est
  arrêté (« Service DHCP injoignable… »).
- **Les sources d'installation des systèmes visés doivent être déployées.** On
  n'installe que ce qui a été rendu disponible au préalable. La page « OS
  installables » indique, pour chaque système, s'il est déployé ou non. Voir
  [Préparer les systèmes](/admin/installer/preparer-les-systemes).
- **Les informations de rattachement à l'établissement doivent être en place.**
  Elles sont utilisées pendant l'installation pour que le poste rejoigne
  l'établissement une fois installé.

## Le contrôle qui vérifie tout ça dans l'interface

La page **« État du système »** (menu **Serveur** → **Réglages** → carte
**« État du système »**) exécute des vérifications à la demande. Sa section
**« iPXE »** contrôle deux des points ci-dessus : la présence des sources
d'installation sur le serveur, et les informations de rattachement à
l'établissement. Chaque vérification donne un verdict, un message et, en cas de
problème, un conseil de correction. C'est le point de contrôle à lancer avant de
se déplacer devant un poste.

## Résultat observable

Le service d'adressage n'affiche aucune bannière d'alerte sur « Réseau DHCP », le
système que vous comptez installer est marqué comme déployé sur « OS
installables », et la section « iPXE » de « État du système » ne signale rien.
Vous pouvez passer à la déclaration du poste.

## Les droits nécessaires

Deux habilitations distinctes entrent en jeu, et une personne peut détenir l'une
sans l'autre.

::: droit-requis
- **Déclarer et installer un poste** exige le droit **« Installer un poste »**.
  C'est un droit qui s'applique à l'échelle de l'établissement : une délégation
  limitée à une seule salle ne l'ouvre pas.
- **Préparer les systèmes et lancer les contrôles** (pages « OS installables »,
  « Gestion ISO Windows », « Réseau DHCP », « État du système ») exige le droit
  d'**administration du serveur**. Le menu **Réglages** n'apparaît qu'aux
  personnes qui le détiennent.
:::

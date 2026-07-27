---
title: Préparer les systèmes
description: "Rendre disponibles, depuis l'interface, les systèmes que l'on pourra installer sur les postes : distributions Linux en un clic, sources Windows par URL ou dépôt de fichier, pilotes réseau de l'installeur."
---

# Préparer les systèmes installables

*Aussi appelé : sources d'installation, systèmes déployés.*

Cette fiche décrit comment rendre disponibles, depuis l'interface, les systèmes
que l'on pourra ensuite installer sur les postes. Tant qu'un système n'est pas
préparé ici, il ne peut pas être installé.

## Où ça se passe

Menu **Serveur** → **Réglages** → carte **« OS »** (« Sources d'installation des
systèmes déployés… »). On arrive sur la page **« OS installables »**, réservée à
l'administration du serveur.

![La page « OS installables » avec la carte Windows et les cartes des distributions Linux, chacune portant son badge de disponibilité](/captures/admin/installer/preparer-les-systemes/os-installables.png)

::: droit-requis
Il faut détenir le droit d'**administration du serveur**. Le menu **Réglages**
n'apparaît qu'aux personnes qui le détiennent.
:::

## Lire l'état des systèmes

Sur la page « OS installables » :

- **Windows** (10 et 11) est regroupé dans **une seule carte**, qui renvoie à la
  page « Gestion ISO Windows » avec un badge « N déployée(s) » ou « Aucune
  version ».
- Chaque **distribution Linux** (Debian, Ubuntu, PrimTux, NIRD) a sa propre
  carte, avec un badge **« Disponible »** ou **« Manquante »**.

## Installer une distribution Linux, en un clic

1. Sur la carte de la distribution voulue, lancez son installation depuis la
   page.
2. Le traitement se poursuit **en arrière-plan** ; son avancement se suit sur la
   même page.
3. Si un essai précédent a échoué, la carte affiche le **détail du dernier essai
   échoué**.

::: delai-effet immediat
Le résultat se lit sur la page même : la carte passe à « Disponible » une fois le
traitement terminé. Les traitements longs s'y suivent au fil de leur avancement.
:::

## Ajouter une source Windows

La carte Windows renvoie à la page **« Gestion ISO Windows »** (« Gérer les ISO
Windows »), qui liste les versions déployées (la version courante et la
précédente, pour Windows 10 comme pour Windows 11).

1. Choisissez **« Nouvelle source »**.
2. Indiquez soit une **URL Microsoft**, soit un **dépôt de fichier**.
3. Le traitement se déroule **en arrière-plan** ; une carte de suivi se
   rafraîchit régulièrement.
4. Un bouton **« Annuler »** permet d'interrompre un traitement en cours.

## Fournir les pilotes réseau de l'installeur Windows

Certains modèles de poste ont une carte réseau que l'installeur Windows ne
reconnaît pas d'emblée : l'installation ne peut alors pas joindre le réseau. La
page « Gestion ISO Windows » porte, pour chaque version, une colonne **« Pilotes
réseau »** qui répond à ce cas.

1. Déposez une **archive de pilotes** pour la version concernée.
2. Utilisez l'action **« Réappliquer les pilotes »** pour les intégrer à
   l'installeur. La confirmation rappelle : « L'opération dure quelques minutes
   et se déroule en arrière-plan ».

## Résultat observable

Le système que vous vouliez préparer s'affiche comme **« déployé(s) »** (Windows)
ou **« Disponible »** (Linux) sur la page « OS installables ». Il pourra être
proposé à l'installation d'un poste.

::: attention
Les sources d'installation sont **volumineuses** : leur préparation peut être
longue. L'opération se poursuit en arrière-plan — vous pouvez quitter la page,
son avancement reste consultable à votre retour.
:::

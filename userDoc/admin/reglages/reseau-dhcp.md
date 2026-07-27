---
title: Les adresses réseau des postes
description: "Vérifier qu'un poste a bien une adresse sur le réseau, et lui en fixer une qui ne change pas."
---

# Les adresses réseau des postes

*Aussi appelé : réservation, adresse fixe.*

Cette fiche explique comment vérifier qu'un poste reçoit bien une adresse sur
le réseau de l'établissement, et comment lui **fixer une adresse** qui ne
change plus.

## Où ça se passe

Menu **Serveur** → **Réglages** → carte **« Réseau DHCP »** (section
« Réseau & intégrations »). On arrive sur la page **« Réseau DHCP »**.

::: droit-requis
Il faut **administrer le serveur**.
:::

La page présente trois onglets. **Sa portée est tout l'établissement.**

- **« Baux actifs »** — la liste des postes qui ont **reçu une adresse** ;
  c'est ici qu'on vérifie qu'un poste est bien servi.
- **« Réservations »** — les postes dont l'adresse a été **fixée** pour ne plus
  changer ; c'est ici qu'on crée, modifie ou supprime une adresse fixe.
- **« Sous-réseaux »** — les plages d'adresses de l'établissement, en lecture
  et en création.

## Vérifier qu'un poste a une adresse

Ouvrez l'onglet **« Baux actifs »** et retrouvez le poste dans la liste : sa
présence confirme qu'il a bien reçu une adresse. Un poste absent de cette liste
n'a pas (ou pas encore) été servi.

## Fixer l'adresse d'un poste

1. Ouvrez l'onglet **« Réservations »**.
2. Lancez la création d'une réservation : une **fenêtre dédiée** s'ouvre.
3. Renseignez les informations demandées pour associer le poste à son adresse,
   puis enregistrez.

Pour modifier ou supprimer une réservation existante, utilisez les actions de
sa ligne — chacune ouvre sa propre fenêtre de confirmation.

::: delai-effet immediat
Le tableau reflète l'enregistrement aussitôt.
:::

::: attention
Si le service d'adressage est arrêté, une **bannière d'alerte** s'affiche en
tête de page — « Service DHCP injoignable » : tant que le service n'est pas
relancé, **vos modifications ne sont pas appliquées** aux postes, même
enregistrées. Relancer ce service relève d'une intervention sur le serveur —
voir [En cas de problème](/admin/reglages/en-cas-de-probleme).
:::

## Résultat observable

La réservation apparaît dans l'onglet **« Réservations »** dès l'enregistrement.
Au prochain adressage, le poste concerné recevra l'adresse fixée.

## L'import de configuration

La page propose aussi un **import de configuration** : c'est une **assistance à
la reprise** depuis une installation existante, pas un réglage courant — ce
guide ne le détaille pas.

---
title: Limiter l'espace de stockage
description: "Fixer un quota de stockage à un compte ou à un groupe, et repérer les comptes en dépassement."
---

# Limiter l'espace de stockage

*Aussi appelé : quota.*

Cette fiche explique comment limiter la place occupée sur le serveur par un
compte ou par un groupe, et comment repérer les comptes qui débordent.

## Où ça se passe

Menu **Pilotage**, entrée **Utilisateurs**. Le quota se règle depuis deux
fiches :

- la **fiche d'un compte**, dans sa section **Quotas disque** ;
- la **fiche d'un groupe**, dans sa section **Quota du groupe**.

::: droit-requis
Il faut être administrateur du serveur pour modifier un quota.
:::

## Le quota d'un compte

La section **Quotas disque** de la fiche d'un utilisateur montre l'utilisation
par espace — l'[espace personnel](/glossaire#espace-personnel) (K:) et les
partages (Classes/Docs). Deux boutons :

1. **Actualiser** — recalcule l'utilisation réelle au moment où vous cliquez.
2. **Modifier le quota** — fixe la limite de l'espace concerné.

## Le quota d'un groupe

La section **Quota du groupe** de la fiche d'un groupe affiche l'état courant :
**Hérité (défaut)**, **Illimité**, ou une valeur propre au groupe. Le bouton
**Modifier** ajuste cette limite, qui s'applique aux membres du groupe. Quand
plusieurs règles pourraient s'appliquer à un même compte, **la règle la plus
favorable au compte s'applique**.

## Résultat observable

La valeur affichée dans la section se met à jour, et un message confirme le
changement.

::: delai-effet immediat
La limite est appliquée en arrière-plan, quelques instants après la validation.
:::

## Repérer les comptes en dépassement

La liste des utilisateurs propose un filtre d'audit **Quota dépassé** qui isole
les comptes qui débordent : voir le domaine
[Utilisateurs et groupes](/admin/utilisateurs/).

::: vue-poste
Quand un compte atteint sa limite, l'enregistrement de nouveaux fichiers échoue
sur le poste, et l'utilisateur en est averti à sa connexion. Ce que vit
l'utilisateur est décrit dans
[Mon espace personnel](/poste/fichiers/espace-personnel).
:::

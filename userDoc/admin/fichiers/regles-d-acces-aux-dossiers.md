---
title: Règles d'accès aux dossiers
description: "Interdire ou autoriser un dossier des postes à un groupe d'utilisateurs, salle par salle, sans attendre un réglage du catalogue."
---

# Interdire ou autoriser un dossier des postes

Cette fiche explique comment poser une règle d'accès sur un **dossier des postes**
— pas sur un [partage](/glossaire#partage) réseau — pour l'**interdire** ou
l'**autoriser** à un groupe d'utilisateurs, et n'appliquer cette règle qu'aux
salles choisies.

*Aussi appelé : permissions NTFS, droits sur un dossier.*

## À quoi ça sert

Le [catalogue de capacités](/admin/reglages/capacites-et-portees) couvre les
réglages courants et nommés une fois pour toutes. Une règle d'accès aux dossiers
répond au cas qu'il ne couvre pas : un dossier **local aux postes** qu'il faut
fermer ou ouvrir à un groupe précis, sans attendre qu'une
[capacité](/glossaire#capacite) dédiée existe.

L'exemple type : masquer `D:\Ressources` aux élèves de `3B` sur les seuls postes
de `salle-101`.

## Où ça se passe

Menu **Serveur**, entrée **Réglages**, carte **Règles d'accès aux dossiers**.
La page liste les règles existantes ; cliquer sur une ligne ouvre la règle.

::: droit-requis
Il faut détenir le droit **Gérer les règles d'accès aux dossiers** pour créer,
modifier ou supprimer une règle ; **Voir les règles d'accès aux dossiers** suffit
pour les consulter. L'entrée **Réglages** n'apparaît qu'aux administrateurs du
serveur.
:::

## Les quatre décisions d'une règle

Une règle se lit comme une phrase : *interdire à ce groupe de parcourir ce
dossier et son contenu*. Quatre champs la composent.

- **Sens** — **Interdire** ou **Autoriser**.
- **Niveau** — **Parcourir** (voir le dossier sans l'ouvrir), **Lire**,
  **Écrire**, **Modifier** (lire et écrire).
- **Portée** — **Ce dossier seul**, ou **Dossier et contenu** (la règle descend
  sur les sous-dossiers et les fichiers).
- **Groupe concerné** — un groupe d'utilisateurs de l'établissement, choisi dans
  la liste.

S'y ajoutent un **libellé**, qui sert à retrouver la règle, et le **chemin du
dossier**, qui doit être un chemin Windows absolu (par exemple `D:\Ressources`).

## Créer une règle

1. Cliquez sur **Nouvelle règle**.
2. Donnez-lui un **libellé** parlant et saisissez le **chemin du dossier**.
3. Choisissez le **groupe concerné** en le recherchant par son nom.
4. Réglez le **sens**, le **niveau** et la **portée**.
5. Si le sens est **Interdire**, cochez **J'ai compris les implications** — la
   validation est refusée sans cette confirmation.
6. Validez.

La règle est créée **sans aucune salle cible** : elle ne s'applique encore à
aucun poste. SE5 vous amène directement sur sa page pour l'étape suivante.

## Choisir les salles où la règle s'applique

Sur la page d'une règle, la section **Parcs cibles** décide où elle s'applique :
sélectionnez un [parc](/glossaire#parc) et cliquez sur **Assigner**. Une règle
sans parc cible reste sans effet — la liste l'affiche alors avec la mention
**Aucun** dans la colonne **Parcs**.

Retirer un parc ne désactive pas la règle : elle continue de s'appliquer aux
autres parcs assignés.

::: delai-effet agent
Le poste doit être allumé et connecté au réseau de l'établissement. La règle est
posée sur le dossier au passage suivant de l'[agent](/glossaire#agent).
:::

## Suspendre ou supprimer une règle

**Désactiver** une règle ne l'oublie pas : au passage suivant de l'agent, SE5
**retire** du dossier ce que la règle y avait posé. L'accès revient à ce qu'il
était.

La **suppression** n'est proposée que sur une règle **déjà désactivée**. C'est
volontaire : on retire d'abord l'effet des postes, on efface ensuite la règle.

::: attention
La suppression est définitive. Désactivez la règle, vérifiez sur un poste que
l'accès est bien revenu à la normale, puis supprimez.
:::

## Les garde-fous

Certaines règles sont refusées à la saisie, parce qu'elles casseraient le poste :

- **Interdire avec la portée « Dossier et contenu » sur une racine système
  Windows** (le disque système lui-même, `Windows`, `Program Files`,
  `ProgramData`). Interdire **Ce dossier seul** y reste possible : masquer sans
  casser est autorisé partout.
- **Interdire à un compte système** (Système, Administrateurs, Tout le monde…) :
  le poste ne redémarrerait plus correctement.

Deux messages, eux, sont de simples avertissements : la règle est bien créée,
mais méritent une vérification.

- **La règle recouvre une capacité active** — les deux visent le même dossier.
  En cas de conflit, le réglage le plus précis et le plus récent l'emporte.
- **Groupe sans correspondance connue** — le groupe choisi n'a pas d'équivalent
  côté annuaire ; le poste ne saura pas à qui appliquer la règle.

## Résultat observable

La règle apparaît dans la liste avec son sens, son niveau, sa portée, le nombre
de parcs assignés et son statut **Active** ou **Désactivée**. Sur un poste de
l'une des salles ciblées, le dossier devient inaccessible — ou accessible — au
passage suivant de l'agent.

::: vue-poste
L'utilisateur d'un poste ciblé ne voit plus le dossier interdit dans
l'explorateur de fichiers, ou obtient un refus d'accès s'il en connaît le
chemin. Aucun message ne lui explique pourquoi : prévenez les personnes
concernées.
:::

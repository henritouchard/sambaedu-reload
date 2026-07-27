---
title: Gérer les accès d'un partage
description: "Accorder, ajuster et retirer les accès d'un lecteur réseau en distinguant lecture et écriture, et comprendre la différence entre voir un lecteur et pouvoir y accéder."
---

# Gérer les accès d'un partage

Cette fiche explique comment décider qui accède à un [partage](/glossaire#partage)
et à quel niveau. Le point clé : **voir un lecteur n'est pas la même chose que
pouvoir y accéder**.

## Où ça se passe

Menu **Serveur**, entrée **Réglages**, carte **Gestion des fichiers**, onglet
**Lecteurs réseaux** : cliquez sur un partage de la liste pour ouvrir sa page.
Tout se gère depuis cette page dédiée.

::: droit-requis
Il faut être administrateur du serveur.
:::

## Deux choses différentes : voir la lettre et avoir l'accès

Un partage a **deux axes** qu'il ne faut pas confondre :

- **La lettre est visible** dès qu'une assignation existe — qu'elle vise un
  utilisateur, un [groupe d'utilisateurs](/glossaire#groupe-de-postes) ou une
  salle ([parc](/glossaire#parc)). Le lecteur apparaît alors dans l'explorateur
  des personnes concernées.
- **L'accès réel** n'est accordé qu'aux **utilisateurs et groupes
  d'utilisateurs**, à l'un de ces deux niveaux :
  - **Lire** — consultation seule ;
  - **Modifier** — consultation et écriture.

Une assignation à une **salle (parc)** est un **« montage seul »** : la lettre
s'affiche sur les postes de la salle, mais **aucun accès n'est accordé**. Pour
ouvrir réellement l'accès, il faut ajouter un utilisateur ou un groupe
d'utilisateurs.

::: attention
Quand un partage n'est assigné qu'à des salles, la page affiche cet
avertissement :

> Ce répertoire n'est assigné qu'à des parcs (postes) : la lettre sera VISIBLE
> sur ces postes, mais aucun accès réel ne sera accordé. Ajoutez un utilisateur
> ou un groupe d'utilisateurs pour ouvrir l'accès en lecture ou écriture.
:::

## Qui est concerné quand on accorde l'accès à un groupe

Un accès accordé à un groupe vaut pour **tous ses membres**. Pour une classe,
l'accès accordé au groupe couvre les **élèves** de la classe ; l'**équipe
enseignante** est un groupe distinct, à qui l'on accorde l'accès séparément. Une
personne **ajoutée au groupe après coup** bénéficie de l'accès à sa **prochaine
ouverture de session**, pas au cours d'une session déjà ouverte.

## Les gestes

Depuis la page du partage, sur chaque assignation :

1. **Ajouter une assignation** — choisissez le type de cible (**Utilisateur**,
   **Groupe d'utilisateurs** ou **Parc (montage seul)**), recherchez la cible,
   puis choisissez le niveau **Lire** ou **Modifier** (**Lire** est proposé par
   défaut).
2. **Changer le niveau** d'une assignation existante entre **Lire** et
   **Modifier**.
3. **Retirer** une assignation.

Chaque geste **réapplique aussitôt les accès sur le serveur** ; un message le
confirme.

## Vérifier la conformité

La page affiche l'état de conformité entre les accès voulus et les accès
réellement en place sur le serveur (conforme, en écart, ou dossier pas encore
créé). En cas d'écart, un bouton **Resynchroniser** réapplique tout d'un geste.

## Supprimer un partage

Le bouton de suppression révoque les accès et **archive le dossier sur le
serveur** : les fichiers **sont conservés côté serveur** mais ne sont plus
accessibles depuis les postes. Un **message de confirmation** s'affiche avant
que la suppression ne soit exécutée.

::: delai-effet session
Le **retrait** d'un accès vaut immédiatement côté serveur. En revanche, une
**nouvelle lettre** (partage tout juste ouvert à quelqu'un) n'apparaît qu'à la
prochaine ouverture de session de la personne concernée.
:::

::: vue-poste
L'utilisateur peut voir un lecteur sans pouvoir y écrire, ou même sans pouvoir
l'ouvrir : ce n'est pas une panne. Ce cas est expliqué côté poste dans
[Les espaces partagés](/poste/fichiers/espaces-partages).
:::

::: attention
Assigner un partage à une salle rend seulement la lettre **visible**, sans
donner aucun accès. À la suppression d'un partage, les accès sont révoqués et le
dossier archivé : les fichiers restent sur le serveur mais ne sont plus
accessibles depuis les postes.
:::

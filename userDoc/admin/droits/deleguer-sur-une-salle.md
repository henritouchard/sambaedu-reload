---
title: Déléguer un droit sur une salle
description: "Limiter un droit à une seule salle, ce que cette délégation ouvre réellement, et le suivi des délégations en cours."
---

# Déléguer un droit sur une salle

Une **délégation** confie un droit à une personne pour **une seule salle** — un
[groupe de postes](/glossaire#groupe-de-postes) — au lieu de l'ouvrir sur tout
l'établissement. Cette fiche décrit comment poser une délégation, ce qu'elle
ouvre **vraiment**, et comment en suivre l'ensemble.

## Où ça se passe

Deux portes ouvrent la même fenêtre de délégation, depuis la page des
utilisateurs (menu **Pilotage**, entrée **Utilisateurs** — voir
[Utilisateurs et groupes](/admin/utilisateurs/)) :

- **depuis la liste** : sélectionnez un ou plusieurs comptes, puis choisissez
  **Déléguer un droit sur une salle** ;
- **depuis la fiche d'un compte** : entrée **Délégation sur une salle**.

::: droit-requis
Il faut détenir le droit d'attribuer des droits. Sans lui, l'entrée de
délégation n'apparaît pas, et l'opération est refusée côté serveur.
:::

## La fenêtre de délégation

La fenêtre ne propose que des **salles physiques actives** : on délègue toujours
sur une salle, jamais sur un regroupement logique de postes.

Pour chaque personne, la fenêtre affiche **l'origine du droit** sur la salle
choisie (délégation posée sur cette salle, exclusion, droit détenu au niveau de
l'établissement, droit reçu par un profil, ou aucun droit) et son état
(autorisé, bloqué, ou aucun). Elle propose alors une action :

- **Accorder** — poser une délégation qui ouvre le droit sur cette salle ;
- **Révoquer** — retirer une délégation posée ;
- **Exclure** — retirer le droit sur cette salle, même à quelqu'un qui le
  détient partout ailleurs ;
- **Lever l'exclusion** — annuler une exclusion posée ;
- **Auto** — laisser SE5 choisir l'action qui correspond à l'état courant.

Chaque délégation ou exclusion peut recevoir une **échéance** (date et heure) :
passée cette date, elle cesse d'elle-même de s'appliquer.

## La règle de priorité

Sur une salle, l'ordre est toujours le même : une **exclusion active bloque**,
même face à un droit détenu au niveau de l'établissement ; sinon un droit
d'établissement ouvre toutes les salles ; sinon une délégation n'ouvre que sa
salle (voir
[Comprendre le modèle de droits](/admin/droits/comprendre-le-modele-de-droits)).

::: attention
L'**exclusion prime sur tout** : elle prive de son droit, sur la salle visée,
même une personne qui porte un profil d'administrateur ouvrant ce droit partout
ailleurs. C'est le levier pour mettre une salle hors de portée de quelqu'un,
sans toucher au reste de ses droits.
:::

## Ce qu'une délégation ouvre réellement

Aujourd'hui, **deux droits** produisent un effet réellement limité à la salle :

- **Voir les machines** — la personne accède à la page du parc, mais la liste
  est **restreinte à ses salles déléguées** : elle ouvre la fiche de la salle
  et de ses postes, et rien d'autre. Les regroupements logiques qui contiennent
  la salle restent hors de sa vue ;
- **Affecter des applications** — la personne peut **affecter et retirer des
  applications** sur la salle déléguée et ses postes, et nulle part ailleurs.

Ce qu'elle fait ensuite dans le parc est décrit par les domaines
[Parc et postes](/admin/parc/) et
[Applications et personnalisation des postes](/admin/applications/).

Une **exclusion** joue sur ces deux mêmes points : une personne qui voit
d'ordinaire tout le parc, exclue d'une salle, cesse d'y voir les postes et d'y
affecter des applications.

::: attention
Les **autres droits** proposés dans cette fenêtre — comme le contrôle à
distance ou l'administration des postes — **ne produisent pas**, à ce jour,
d'effet limité à la salle : quand une action correspondante existe, elle reste
gouvernée par le droit au niveau de **l'établissement** ; d'autres droits
proposés ici ne commandent, à ce jour, aucun écran. Déléguer l'un de ces droits
« sur une salle » ne le restreint donc pas à cette salle. Lorsque l'action
existe, attribuez plutôt le droit ou le profil au niveau de l'établissement
(voir [Attribuer des droits à une personne](/admin/droits/attribuer-des-droits)).
:::

::: delai-effet immediat
Une délégation posée, révoquée ou une exclusion levée valent dès le prochain
chargement de page de la personne concernée.
:::

## Suivre les délégations

Deux onglets de la page **Gestion des droits** donnent la vue d'ensemble :

- **Délégations actives** — le tableau de toutes les délégations et exclusions
  en cours (personne, salle, droit, type accordée ou exclusion, échéance). Un
  clic sur une ligne rouvre la fenêtre pré-remplie ; une sélection permet une
  révocation en masse ;
- **Historique** — le journal de toutes les opérations de délégation (date,
  auteur, action, salle, droit), filtrable par action, par personne visée et
  par période. Ce journal est **inaltérable** : ses entrées ne peuvent être ni
  modifiées ni réécrites après coup — c'est la trace qui permet de retracer qui
  a changé quoi.

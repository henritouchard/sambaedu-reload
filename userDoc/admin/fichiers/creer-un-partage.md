---
title: Créer un partage
description: "Ouvrir un nouveau lecteur réseau, à la main ou depuis un modèle prêt à l'emploi."
---

# Créer un partage

*Aussi appelé : lecteur réseau, répertoire réseau.*

Cette fiche explique comment ouvrir un nouveau [partage](/glossaire#partage) —
un dossier commun accessible depuis les postes avec sa propre lettre de lecteur.
Deux chemins existent : la création à la main, ou la création depuis un modèle
prêt à l'emploi.

## Où ça se passe

Menu **Serveur**, entrée **Réglages**, carte **Gestion des fichiers**, onglet
**Lecteurs réseaux**. La liste des partages existants s'y affiche, avec deux
boutons : **Nouveau répertoire** (création à la main) et **Créer depuis un
template** (création depuis un modèle).

::: droit-requis
Il faut être administrateur du serveur.
:::

## Créer un partage à la main

Le bouton **Nouveau répertoire** ouvre un formulaire :

1. **Nom** (obligatoire) — le nom du partage.
2. **Nom de répertoire** — le nom du dossier créé sur le serveur. Il n'accepte
   que des lettres, des chiffres et les caractères `.`, `_`, `-`, **sans espace
   ni accent**, et doit être unique.
3. **Libellé du lecteur** (facultatif) — le nom affiché dans l'explorateur. Si
   vous le laissez vide, le nom du partage sert de libellé.
4. **Lettre** (facultative) — pré-remplie avec la prochaine lettre libre. Vous
   pouvez la changer ou l'effacer : laissée vide, une lettre est attribuée
   automatiquement.
5. Validez pour créer le partage.

Certaines lettres sont réservées par le système : si vous en saisissez une, le
formulaire la refuse avec un message qui vous invite à en choisir une autre ou à
laisser le champ vide.

## Créer depuis un modèle

Le bouton **Créer depuis un template** propose quatre modèles prêts à l'emploi.
Chacun réunit d'avance les bons rôles — qui **dépose** (accès en écriture) et
qui **consulte** (accès en lecture) :

- **Direction → tous (publication descendante)** — une équipe dépose, des
  groupes destinataires consultent en lecture seule.
- **Profs → élèves (distribution de devoirs)** — l'équipe enseignante dépose, la
  classe consulte en lecture seule.
- **Utilisateur ↔ utilisateur (échange bilatéral)** — deux utilisateurs
  partagent un espace commun en lecture et écriture.
- **Groupe (espace commun)** — tous les membres d'un groupe partagent un espace
  en lecture et écriture.

Choisissez le modèle, désignez ses cibles (l'écran vous demande, par rôle, quel
groupe ou quel utilisateur remplir), puis validez. Un aperçu récapitule les
accès qui seront posés avant la création. Le partage obtenu est un partage
ordinaire : vous pourrez ensuite l'ajuster comme n'importe quel autre, depuis sa
page. Un modèle ne cible jamais une salle ni un parc.

## Résultat observable

Le nouveau partage apparaît dans la liste des **Lecteurs réseaux**, et son
dossier est créé aussitôt sur le serveur. Un message confirme la création.

## À quelle lettre s'attendre

Les lecteurs `K:` (« Mes documents ») et `H:` (« Classes ») sont les seuls à ne
jamais changer de lettre — mais ils n'apparaissent que si leur espace est servi
par le **serveur de fichiers** : quand il est servi par le cloud, la lettre
n'existe pas du tout. Voir
[Choisir où vivent les fichiers](/admin/fichiers/politique-de-fichiers).

Les partages que vous créez, eux, gardent toujours une lettre. Un partage à
lettre automatique peut en recevoir une différente selon le contexte :
**repérez-le par son nom**, qui, lui, ne change pas — ne promettez pas une lettre
fixe à un utilisateur pour un partage dont la lettre est automatique.

::: delai-effet session
La lettre du nouveau partage apparaît sur les postes à la prochaine ouverture de
session des personnes qui y ont accès.
:::

::: vue-poste
Les personnes concernées voient un nouveau lecteur, portant le libellé donné au
partage, dans leur explorateur de fichiers.
:::

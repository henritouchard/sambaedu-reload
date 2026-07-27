---
title: Groupes d'utilisateurs
description: "Constituer et faire évoluer les classes, équipes et projets : créer un groupe, gérer ses membres et leurs rôles, agir sur une sélection."
---

# Groupes d'utilisateurs

Cette fiche explique comment réunir des utilisateurs en groupes — classes,
équipes, projets — et comment faire évoluer leur composition au fil de l'année.

## Où ça se passe

Menu **Pilotage**, entrée **Utilisateurs**, onglet **Groupes**. Cet onglet liste
les groupes de l'établissement avec, pour chacun, son nom affiché, son nom
technique, son type et son nombre de membres ; une recherche et une pagination
aident à s'y retrouver.

::: droit-requis
Il faut être administrateur des utilisateurs.
:::

## Créer un groupe

1. Sur l'onglet **Groupes**, cliquez sur **Nouveau groupe**.
2. Saisissez le **nom** du groupe : c'est le seul champ à la création, et il
   sert d'identifiant. Il n'accepte que lettres, chiffres et les caractères
   `. _ - @`, **sans espace ni accent**.
3. Choisissez le **type** parmi : personnalisé, classe, cours, matière,
   matière-classe, projet ou équipe.
4. Validez.

Vous arrivez alors sur la **fiche du groupe** pour le configurer. Le nom affiché
reste modifiable ensuite ; le nom technique, lui, est fixé à la création.

## Gérer un groupe depuis sa fiche

![Fiche d'un groupe de type classe, avec la liste de ses membres et leur rôle Élève, Prof ou Prof principal (repère 1), et le menu d'actions du groupe (repère 2).](/captures/admin/utilisateurs/groupes-d-utilisateurs/fiche-groupe.png)

Sur la fiche d'un groupe, le menu d'actions permet de :

- **Modifier le groupe** — ajuster son nom affiché, son type et ses membres ;
- **retirer un membre** directement depuis la liste des membres ;
- attribuer à chaque membre son **rôle dans le groupe** — **Élève**, **Prof**
  ou **Prof principal**, ce dernier n'existant que pour les groupes de type
  **classe** ;
- **Nommer un professeur principal** (pour les classes uniquement) ;
- **Réinitialiser les mdp du groupe** — voir
  [Réinitialiser un mot de passe](/admin/utilisateurs/reinitialiser-un-mot-de-passe).

::: delai-effet session
Ajouter ou retirer un membre change ce à quoi il a accès sur le poste. Ce
nouvel accès s'applique à sa **prochaine ouverture de session**.
:::

## Agir sur une sélection

Cocher plusieurs lignes fait apparaître une barre d'actions en bas de l'écran.

- Sur l'onglet **Utilisateurs**, une sélection de comptes permet de **Gérer les
  groupes** (leur assigner ou leur retirer des groupes d'un seul geste) et de
  **Réinitialiser les mots de passe**.
- Sur l'onglet **Groupes**, une sélection de groupes permet de **Resynchroniser
  AD** (rattrapage), de **Réinitialiser les mots de passe** et de **Supprimer**
  les groupes.

::: attention
La suppression de groupes se confirme avant de s'appliquer : vérifiez votre
sélection, car elle retire les groupes choisis.
:::

## Résultat observable

La liste des groupes et la liste des membres reflètent aussitôt vos actions :
un groupe créé apparaît dans l'onglet **Groupes**, un membre retiré disparaît de
la fiche du groupe, un rôle changé s'affiche à jour en face du membre.

D'autres réglages figurent sur la fiche d'un groupe — ses applications et sa
personnalisation, son espace de fichiers partagé et sa place disque : ils sont
traités dans les domaines **Applications et personnalisation des postes** et
**Fichiers et partages** de ce guide.

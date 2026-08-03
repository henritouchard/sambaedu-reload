---
title: Attribuer des droits à une personne
description: "Donner un profil ou un droit individuel à un ou plusieurs comptes, lire l'état des droits d'une personne, et retirer ce qui a été accordé."
---

# Attribuer des droits à une personne

Cette fiche explique comment donner à une ou plusieurs personnes un
[profil de droits](/admin/droits/profils-types) ou un droit individuel, comment
lire l'état complet de leurs droits, et comment retirer ce qui a été accordé.

## Où ça se passe

Deux portes mènent au même volet de gestion des droits, depuis la page des
utilisateurs (menu **Pilotage**, entrée **Utilisateurs** — voir
[Utilisateurs et groupes](/admin/utilisateurs/)) :

- **depuis la liste** : sélectionnez un ou plusieurs comptes, puis dans la
  barre d'actions choisissez **Gérer les droits** ;
- **depuis la fiche d'un compte** : ouvrez la carte **Permissions** ou l'entrée
  **Rôles & permissions** du menu **Actions**.

::: droit-requis
Il faut détenir le droit d'attribuer des droits. Sans lui, l'entrée **Gérer les
droits** n'apparaît pas, et l'opération est refusée côté serveur.
:::

## Le volet de gestion des droits

Le volet présente **deux onglets**.

### Onglet Rôles

Cet onglet **assigne ou retire un profil** à toute la sélection d'un seul
geste. Un repère par profil indique s'il est porté par **tous**, **aucun** ou
seulement **une partie** des comptes sélectionnés. Assigner et retirer sont
**deux gestes distincts** :

- **assigner** un profil l'**ajoute** aux profils déjà portés — il ne remplace
  aucun profil existant ;
- **retirer** un profil l'enlève des comptes qui le portaient.

#### Les profils verrouillés

Certains profils apparaissent **désactivés** : ni cochables, ni décochables. Ce
sont ceux qui sont **portés par un groupe d'utilisateurs** (voir
[Comprendre le modèle de droits](/admin/droits/comprendre-le-modele-de-droits)).
Pour ceux-là, c'est l'**appartenance au groupe** qui décide, et l'interface
indique quel groupe est concerné.

Le geste de remplacement est donc ailleurs : **ajoutez la personne au groupe**
pour lui donner le profil, **retirez-l'en** pour le lui reprendre.

::: attention
Ce verrouillage n'est pas qu'une précaution d'interface : il vaut aussi côté
serveur. Une attribution manuelle qui passerait outre serait de toute façon
défaite à la synchronisation suivante, puisque l'appartenance au groupe reste la
source de vérité — mieux vaut donc que le geste soit refusé tout de suite, et
que l'écran dise quoi faire à la place.
:::

### Onglet Permissions

Cet onglet **accorde ou retire des droits individuels**, qui s'appliquent
**partout dans l'établissement**. L'état de chaque droit est indiqué pour la
sélection.

Un droit reçu **par un profil** ne se retire **pas** ici : il porte un repère
et l'infobulle « via un rôle (non retirable individuellement) ». Pour l'enlever,
retirez le profil qui le porte, depuis l'onglet **Rôles** — ou, si ce profil est
porté par un groupe, retirez la personne du groupe.

::: delai-effet immediat
Un profil assigné ou retiré, un droit accordé ou révoqué valent dès le prochain
chargement de page de la personne concernée.
:::

## Lire l'état des droits d'une personne

Deux vues donnent le tableau complet des droits d'un compte :

- la **carte Permissions** de sa fiche : ses profils (avec un lien vers chaque
  fiche de profil), ses droits directs, ses droits reçus par profil, et ses
  délégations sur salles ;
- l'onglet **Droits d'un utilisateur** de la page **Gestion des droits** : une
  recherche par nom mène à une fiche récapitulative de la personne, complétée
  des dernières opérations qui la concernent.

## Le compte d'administration protégé

Si le compte d'administration protégé figure dans une sélection au moment d'un
retrait, il est **ignoré** et l'opération le signale : « Le compte
d'administration protégé a été ignoré : ses droits ne peuvent pas être
retirés. »

::: attention
Les droits d'administration du compte protégé **ne peuvent pas lui être
retirés** : c'est la porte de secours de l'administration de l'établissement.
Un retrait qui le viserait est sans effet sur lui.
:::

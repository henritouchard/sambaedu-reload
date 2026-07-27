---
title: En cas de problème
description: "Un compte introuvable, une connexion refusée, un groupe qui semble sans effet : que vérifier dans l'interface."
---

# En cas de problème

Cette fiche part de trois situations courantes et donne, pour chacune, ce qu'il
faut vérifier **dans l'interface** — sans aucune manipulation sur le serveur.

## Un compte est introuvable

- Un **filtre** est peut-être encore actif : les filtres en cours s'affichent
  en « chips » cliquables sous la barre de recherche. Cliquez sur **Tout
  effacer** pour les retirer et revoir toute la liste.
- La **recherche** ne prend effet qu'à partir de **deux caractères** saisis :
  en dessous, elle n'affiche rien.
- Le compte est peut-être **Inactif** : un compte désactivé reste dans la liste
  avec le badge **Inactif**, mais un filtre de statut peut le masquer.

## Une connexion est refusée

- Vérifiez le **statut** du compte dans la liste : un badge **Inactif** signale
  un compte désactivé, qui ne peut plus ouvrir de session.
- Le compte est peut-être resté sur son **mot de passe par défaut** : le bouton
  **Filtres** propose un filtre d'audit **Mot de passe par défaut** qui isole
  ces comptes. La solution est de
  [réinitialiser le mot de passe](/admin/utilisateurs/reinitialiser-un-mot-de-passe),
  ce qui force le choix d'un nouveau mot de passe à la connexion suivante.

## Un groupe semble sans effet

- Vérifiez d'abord la **composition réelle** du groupe sur sa fiche : le membre
  attendu y figure-t-il, avec le bon rôle ?
- Un changement de composition ne se voit sur le poste qu'à la **prochaine
  ouverture de session** du membre concerné : une session déjà ouverte n'est
  pas mise à jour.
- En dernier recours, l'action **Resynchroniser AD** de l'onglet **Groupes**
  rattrape un groupe dont l'application aurait été manquée.

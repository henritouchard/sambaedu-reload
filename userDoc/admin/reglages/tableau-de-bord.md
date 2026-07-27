---
title: Le tableau de bord
description: "Lire la vue d'ensemble de l'établissement : indicateurs de santé, compteurs cliquables et activité récente."
---

# Le tableau de bord

Cette fiche apprend à **lire** le tableau de bord : les indicateurs de santé du
serveur, les compteurs qui mènent aux listes, et le journal d'activité récente.

## Où ça se passe

Menu **Pilotage**, entrée **Tableau de bord**. Cette page est **ouverte à toute
personne connectée** à l'interface — aucun droit particulier n'est requis pour
la consulter.

Un bouton **« Actualiser »**, en tête de page, recharge toutes les valeurs à la
demande.

![Le tableau de bord de l'établissement, avec la rangée de tuiles d'état du serveur en haut, les compteurs cliquables au centre et le journal d'activité récente en bas.](/captures/admin/reglages/tableau-de-bord/vue-d-ensemble.png)

## Les tuiles d'état

En haut de la page, des tuiles résument la santé du serveur.

- **« PostgreSQL »** — l'état de la base de données : « Connecté » ou
  « Déconnecté ».
- **« MariaDB (legacy) »** — une base héritée de SE4 : « Connecté »,
  « Déconnecté » ou **« Non configuré »**. Sur un serveur neuf, « Non
  configuré » est **normal** et ne signale aucun problème.
- **« Espace Disque »** — le pourcentage occupé et l'espace restant. La tuile
  se borde d'**orange dès 75 %** d'occupation, et de **rouge dès 90 %**.
- **« Queue Workers »** — les traitements en arrière-plan (envois, tâches
  différées). Le compteur passe au **rouge à 0** ; la tuile montre aussi les
  traitements en attente et **échoués**. Son menu (⋮) porte l'action
  **« Redémarrer les workers »**.

### La tuile « Active Directory »

Cette tuile affiche l'annuaire comme « Connecté ». Elle n'est pas un voyant
qui changerait de couleur : pour une **vraie vérification** de l'annuaire,
ouvrez la page [L'état du système](/admin/reglages/etat-du-systeme), qui teste
réellement la connexion.

### Pour chaque tuile au rouge

- **« Espace Disque »** au rouge → relève du serveur : voir
  [En cas de problème](/admin/reglages/en-cas-de-probleme).
- **« Queue Workers »** à 0 ou traitements échoués → tentez
  **« Redémarrer les workers »**, puis **« Actualiser »** : voir
  [En cas de problème](/admin/reglages/en-cas-de-probleme).

## Les compteurs cliquables

Des cartes-compteurs récapitulent l'établissement et **mènent directement à la
liste correspondante**, déjà filtrée quand on clique sur une de leurs lignes :

- **Utilisateurs** — comptes actifs, total, groupes ;
- **Machines** — postes en ligne, hors ligne, inventoriés ;
- **Parcs** — [groupes de postes](/glossaire#groupe-de-postes), machines,
  imprimantes ;
- **Applications** — dépôts, applications, profils applicatifs ;
- **Raccourcis** — bureau, démarrage, barre des tâches.

## L'activité récente

La page montre les **5 derniers événements** — un poste « a été démarré »,
« a été éteint », « a redémarré », un compte « synchronisé ». Le lien
**« Voir tout »** ouvre la page **Activité** complète :

- une **recherche** par machine ou par utilisateur ;
- un filtre **Tous les types / Postes / Utilisateurs** ;
- un tableau **Nom / Type / Action / Initiateur / Statut / Date** — le statut
  vaut **« OK »** ou **« Échec »**, et la date, relative, se précise en
  infobulle ;
- une pagination.

Le lien **« Retour dashboard »** ramène à la vue d'ensemble.

## Résultat observable

Chaque tuile reflète l'état du serveur au dernier chargement ; un clic sur une
ligne de compteur ouvre la liste filtrée ; **« Actualiser »** met à jour toutes
les valeurs de la page.

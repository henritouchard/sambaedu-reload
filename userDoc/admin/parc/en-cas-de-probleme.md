---
title: En cas de problème
description: "Un poste qui ne répond pas, une action sans effet visible, un poste absent de la liste : que vérifier dans l'interface."
---

# En cas de problème

Cette fiche part de trois situations courantes et donne, pour chacune, ce qu'il
faut vérifier **dans l'interface** — sans aucune manipulation sur le serveur.

## Un poste ne répond pas

- Lisez d'abord son **état de présence** sur l'onglet **Postes**. « Éteint ou
  injoignable » signifie **probablement éteint, sans certitude** ; et la
  détection du silence se compte de l'ordre de l'heure, pas à la seconde : un
  poste tout juste coupé peut encore s'afficher **Allumé**.
- Vérifiez que le poste est **réellement allumé** et branché au réseau.
- Si vous tentiez de l'**allumer à distance** : le signal d'allumage ne traverse
  pas toutes les architectures de réseau et suppose un poste configuré pour ce
  réveil. Si un poste ne s'allume **jamais** à distance, rapprochez-vous de la
  personne qui gère le réseau.
- Un **pare-feu local** restrictif sur le poste peut fausser la détection de sa
  disponibilité, dans un sens comme dans l'autre : un poste peut répondre sur le
  réseau sans qu'une session y soit ouvrable, ou l'inverse.

## Une action sans effet visible

- **Laissez le suivi aller à son terme** — environ deux minutes — puis lisez le
  **message de fin** : il indique si le poste est devenu disponible ou est resté
  injoignable.
- Sur une action **groupée**, un poste **déjà en cours d'action** est ignoré et
  compté à part : relisez le résumé de l'opération, qui distingue les postes
  traités des postes ignorés.
- Pour une **programmation** : consultez son **historique** d'exécutions sur la
  page du groupe pour voir si et quand elle s'est déclenchée.
- Pour un **changement de configuration** qui tarde à s'appliquer : depuis la
  fiche du poste, **Forcer la synchro** demande au poste de resynchroniser sa
  configuration au prochain passage de son agent (poste allumé et relié au
  réseau).

## Un poste absent de la liste

- **Réinitialisez les filtres.** Une tuile de statistiques ou un filtre resté
  actif restreint la liste : le bouton de réinitialisation de l'onglet
  **Postes** remet tout à zéro.
- Vérifiez votre **périmètre**. Si le droit **Voir les machines** ne vous a été
  confié que sur certains groupes, vous ne voyez que les postes de ces groupes.
- Relancez l'inventaire des postes avec **Synchroniser depuis l'AD** (onglets
  **Groupes** et **Postes**).
- Lisez l'**encart d'état de synchronisation** affiché sur l'onglet **Groupes** :
  il indique où en est le dernier rapprochement de l'inventaire.

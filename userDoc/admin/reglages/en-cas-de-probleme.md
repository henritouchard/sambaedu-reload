---
title: En cas de problème
description: "Ce que vous corrigez vous-même depuis l'interface, et ce qui relève d'une intervention sur le serveur."
---

# En cas de problème

Cette fiche sépare nettement **deux volets** : les situations que vous corrigez
**vous-même** depuis l'interface, sans aucune commande, et celles qui relèvent
d'une **intervention sur le serveur**, à confier à la personne qui l'exploite.

## Ce que vous corrigez vous-même

Ces gestes se font entièrement dans l'interface.

- **Un poste « Muet »** → vérifiez d'abord qu'il est **allumé et branché au
  réseau**, puis attendez son prochain contact avec le serveur. « Muet »
  signifie « probablement éteint », pas « en panne ». Voir
  [Un poste en règle ou en retard](/admin/reglages/poste-en-regle-ou-en-retard).
- **Un poste « En écart » ou « Erreur »** → sur sa fiche, lisez le **détail** et
  la colonne **« Depuis »** ; un écart de quelques minutes se résorbe souvent
  seul. Au besoin, **« Forcer la synchro »**, puis relisez après quelques
  minutes. Voir
  [Un poste en règle ou en retard](/admin/reglages/poste-en-regle-ou-en-retard).
- **« Queue Workers » à 0 ou traitements échoués** → depuis le tableau de bord,
  utilisez **« Redémarrer les workers »**, puis **« Actualiser »**. Voir
  [Le tableau de bord](/admin/reglages/tableau-de-bord).
- **Des déconnexions intempestives de l'interface** → ajustez le délai de
  **« Sécurité & session »**. Voir
  [Les réglages de l'établissement](/admin/reglages/reglages-de-l-etablissement).
- **Un poste sans adresse réseau** → vérifiez l'onglet **« Baux actifs »** et,
  si besoin, **posez une réservation**. Voir
  [Les adresses réseau des postes](/admin/reglages/reseau-dhcp).

## Ce qui relève du serveur

Ces situations **ne se corrigent pas** depuis l'interface d'administration :
rapprochez-vous de la personne qui exploite le serveur. Ce guide ne les détaille
pas.

- **« Espace Disque » au rouge** sur le tableau de bord.
- **Un bloc d'« État du système » en « Erreur »** qui **persiste après
  « Rafraîchir »** — l'annuaire, la base de données, la liaison à un pilotage
  central, le serveur web ou le démarrage réseau. Voir
  [L'état du système](/admin/reglages/etat-du-systeme).
- **« Service DHCP injoignable »** : tant que le service d'adressage n'est pas
  relancé, les modifications d'adresses ne s'appliquent pas. Voir
  [Les adresses réseau des postes](/admin/reglages/reseau-dhcp).
- **Les journaux de l'onglet « Logs »** de la page « État du système ».
- **« Queue Workers » toujours à 0** après un **« Redémarrer les workers »**.

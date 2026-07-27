---
title: En cas de problème
description: "Une application qui ne s'installe pas, un retrait qui tarde, un écart entre demandé et constaté : que vérifier dans l'interface."
---

# En cas de problème

Cette fiche part de trois situations courantes et donne, pour chacune, ce qu'il
faut vérifier **dans l'interface** — sans aucune manipulation sur le serveur.

## Une application ne s'installe pas

- **Est-elle en erreur au catalogue ?** Sur l'onglet **Catalogue
  d'Applications**, une application en erreur le signale sur sa ligne. Ouvrez le
  détail de l'erreur, puis relancez avec **Réessayer l'installation**.
- **Le poste est-il bien visé ?** Sur la fiche de l'application, la carte
  **Déploiement sur les postes**, onglet **Échecs**, liste les postes en échec ;
  les mentions **Erreur** et **Non installé** ouvrent le journal d'installation
  du poste.
- **Le poste a-t-il eu le temps ?** Le poste doit être allumé et relié au réseau
  pour récupérer la décision. Laissez passer le cycle habituel, ou utilisez
  **Forcer la synchro** sur la fiche du poste pour accélérer.

## Une application ne se retire pas

- **A-t-elle été retirée de TOUTES ses voies ?** Une application peut arriver sur
  un poste par plusieurs chemins. La fiche de l'application liste **les profils
  qui la portent** ; la fiche du poste distingue ce qui est **direct** de ce qui
  est hérité. Pensez aussi au [socle commun](/glossaire#socle-commun) (Réglages →
  Configuration par défaut du parc → Applications).
- **Sinon, même patience :** une fois retirée partout, la désinstallation se fait
  au passage suivant de l'[agent](/glossaire#agent) ; **Forcer la synchro**
  accélère un poste précis.
- **Ce logiciel n'a jamais été déployé par SE5 ?** S'il a été installé à la main
  sur le poste, SE5 n'y touche pas : son retrait ne passe pas par ici.

## Un écart entre demandé et constaté

- Sur la fiche de l'application, lisez la carte **Déploiement sur les postes**
  **poste par poste** (onglets Succès / Échecs / En cours) et comparez avec ce
  que le poste porte réellement.
- Relancez le poste concerné avec **Forcer la synchro**, puis laissez le cycle se
  faire.
- Si l'écart persiste, signalez-le avec le détail lu sur cette carte.

---
title: En cas de problème
description: "Une page qui n'apparaît pas, une action refusée malgré un profil attribué : que vérifier dans l'interface, sans aucune manipulation serveur."
---

# En cas de problème

Cette fiche part de deux situations courantes autour des droits et donne, pour
chacune, ce qu'il faut vérifier **dans l'interface** — sans aucune manipulation
sur le serveur.

## Une personne ne voit pas une page attendue

- **La page Gestion des droits répond « accès refusé ».** Son entrée de menu
  est visible pour tout le monde, mais la page elle-même exige le droit
  d'attribuer des droits. Sans ce droit, l'accès est refusé : vérifiez le
  profil de la personne.
- **Une entrée de menu n'apparaît pas du tout.** Certaines entrées ne
  s'affichent qu'aux personnes qui en détiennent le droit — les réglages du
  serveur, par exemple, ne sont visibles que pour qui administre le serveur.
  Une entrée absente signale un droit non confié, pas une panne.
- **Un délégué ne voit qu'une partie du parc.** C'est normal : une délégation
  « Voir les machines » posée sur une salle **restreint la vue à cette salle**.
  Le délégué ne voit pas non plus les regroupements logiques qui contiennent sa
  salle — c'est voulu, la délégation ne porte que sur des salles physiques.
- **Pour lever le doute**, ouvrez l'onglet **Droits d'un utilisateur** de la
  page **Gestion des droits** : la fiche récapitulative liste les profils, les
  droits directs, les droits reçus par profil et les délégations en cours de la
  personne. Vous y lisez exactement ce dont elle dispose.

## Une action est refusée malgré un profil attribué

- **Une exclusion bloque sur cette salle.** Une exclusion prime sur tout, même
  sur un profil d'administrateur. Vérifiez l'onglet **Délégations actives** ou
  la carte **Permissions** de la personne : une exclusion sur la salle
  concernée y figure.
- **Une délégation est échue.** Une délégation ou une exclusion avec échéance
  cesse d'elle-même à sa date : passée cette date, elle ne compte plus.
  L'onglet **Délégations actives** montre l'échéance de chaque ligne.
- **La portée de classe limite un professeur.** Les profils **Professeur** et
  **Admin élèves** n'agissent que sur les élèves de leurs propres classes : une
  action sur une personne d'une autre classe est normalement refusée.
- **Le droit reste au niveau de l'établissement.** Certains droits proposés à
  la délégation ne produisent pas d'effet limité à une salle : quand l'action
  correspondante existe, elle exige le droit au niveau de l'établissement ;
  d'autres droits ne commandent, à ce jour, aucun écran (voir
  [Déléguer un droit sur une salle](/admin/droits/deleguer-sur-une-salle)).
  Déléguer un tel droit sur une salle ne débloque donc pas l'action.
- **Pour retracer un changement**, l'onglet **Historique** journalise qui a
  accordé, révoqué ou posé une exclusion, sur quelle salle et quel droit. Ce
  journal est inaltérable : il dit fidèlement ce qui s'est passé.

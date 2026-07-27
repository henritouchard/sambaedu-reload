---
title: En cas de problème
description: "Un espace qui n'apparaît pas sur le poste, un accès refusé malgré l'appartenance à un groupe : que vérifier dans l'interface."
---

# En cas de problème

Cette fiche part de deux situations courantes et donne, pour chacune, ce qu'il
faut vérifier **dans l'interface**, de la cause la plus probable à la plus rare
— sans aucune manipulation sur le serveur.

## Un espace n'apparaît pas sur le poste

- **L'interrupteur global correspondant est-il actif ?** Un lecteur `K:` absent
  renvoie à l'option **Répertoire personnel (K:)** ; un lecteur `H:` ou un
  lecteur réseau absent renvoie à l'option **Partages réseau (H:)**. Voir
  [Régler la politique de fichiers](/admin/fichiers/politique-de-fichiers).
- **Pour un lecteur réseau géré : la personne figure-t-elle dans les
  assignations ?** Vérifiez, sur la page du partage, que l'utilisateur, son
  groupe ou sa salle y est bien assigné. Voir
  [Gérer les accès d'un partage](/admin/fichiers/gerer-les-acces-d-un-partage).
- **La session a-t-elle été rouverte depuis ?** Une lettre nouvellement ouverte
  n'apparaît qu'à la prochaine ouverture de session.
- **La lettre attendue était-elle automatique ?** Seuls `K:` et `H:` sont fixes.
  Une lettre automatique peut changer selon le contexte : repérez le partage par
  son **nom**, pas par sa lettre.

## Accès refusé alors que la personne est dans le groupe

- **L'accès du groupe est-il en « Lire » alors qu'on attend « Modifier » ?**
  Sur la page du partage, vérifiez le niveau accordé à ce groupe.
- **Le partage n'est-il assigné qu'à des salles ?** Dans ce cas la lettre est
  visible mais aucun accès n'est accordé : ajoutez un utilisateur ou un groupe
  d'utilisateurs. Voir
  [Gérer les accès d'un partage](/admin/fichiers/gerer-les-acces-d-un-partage).
- **L'appartenance au groupe date-t-elle de la session en cours ?** Une personne
  ajoutée au groupe n'obtient l'accès qu'à sa prochaine ouverture de session.
- **L'indicateur de conformité de la page du partage signale-t-il un écart ?**
  Si oui, le bouton **Resynchroniser** réapplique les accès attendus.
- **Pour une classe : le dossier d'échange est-il désactivé ?** Un dossier
  d'échange fermé refuse le dépôt à toute la classe. Voir
  [Le partage de classe](/admin/fichiers/partage-de-classe).

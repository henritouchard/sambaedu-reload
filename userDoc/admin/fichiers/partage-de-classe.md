---
title: Le partage de classe
description: "Ouvrir le dossier commun d'une classe, gérer son dossier d'échange et réappliquer les accès."
---

# Le partage de classe

Cette fiche explique comment ouvrir et entretenir le dossier commun d'une
classe : le [partage](/glossaire#partage) que les enseignants et les élèves
retrouvent dans le lecteur `H:` « Classes ».

## Où ça se passe

Menu **Pilotage**, entrée **Utilisateurs**, onglet **Groupes** : ouvrez la fiche
du groupe classe. La section **Partage de classe** n'apparaît que sur un groupe
de type classe.

::: droit-requis
La **consultation** du partage de classe suppose un droit de lecture des
partages : sans lui, la section affiche « Accès restreint » au lieu de son
contenu. La **gestion** (créer le partage, resynchroniser, activer ou désactiver
l'espace d'échange) suppose en plus un droit de gestion dédié : avec la lecture
seule, la section reste visible mais ses boutons d'action sont absents.
:::

## Qui accède à quoi dans le dossier de classe

Le dossier d'une classe contient plusieurs sous-dossiers, chacun avec sa règle.
Le tableau ci-dessous précise, pour chacun, qui peut lire et qui peut écrire.

| Sous-dossier | Qui peut lire | Qui peut écrire |
|---|---|---|
| Dossier de travail | les élèves de la classe | l'équipe enseignante de la classe |
| Dossier réservé aux enseignants | l'équipe enseignante de la classe | l'équipe enseignante de la classe |
| Dossier d'échange | toute la classe, s'il est activé | toute la classe, s'il est activé |
| Dossier au nom de chaque élève | l'élève concerné et l'équipe enseignante | l'élève concerné et l'équipe enseignante |

L'équipe enseignante et la classe sont **deux groupes distincts** : l'équipe
réunit les enseignants, la classe réunit les élèves. Le **dossier au nom d'un
élève n'est pas son espace personnel privé** — l'équipe enseignante y a aussi
accès. Ce que vivent élèves et enseignants côté poste est décrit dans
[Les espaces partagés](/poste/fichiers/espaces-partages).

## Les gestes

Deux actions sont disponibles dans la section **Partage de classe** :

1. **Créer le partage, ou réappliquer les accès** — met en place les dossiers de
   la classe et (re)pose les accès attendus. C'est un geste de rattrapage sans
   danger : le relancer sur un partage déjà en place ne fait que rétablir les
   accès corrects.
2. **Activer ou désactiver le dossier d'échange** — ouvre ou ferme le dossier où
   toute la classe peut déposer des fichiers.

::: delai-effet immediat
La bascule du dossier d'échange prend effet aussitôt pour les sessions déjà
ouvertes. En revanche, un élève **ajouté à la classe** ne reçoit ses accès qu'à
sa prochaine ouverture de session.
:::

## Résultat observable

L'état du partage affiche le dossier d'échange comme **activé** ou
**désactivé**, et un message confirme chaque action.

::: vue-poste
Élèves et enseignants voient le dossier de la classe sous le lecteur `H:`
« Classes ». Quand le dossier d'échange est activé, les membres de la classe
peuvent y déposer des fichiers.
:::

::: attention
Désactiver le dossier d'échange **ne supprime pas son contenu** : les fichiers
sont conservés, mais le dossier devient invisible aux membres de la classe. Le
réactiver le rend de nouveau accessible.
:::

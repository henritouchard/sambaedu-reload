---
title: Gérer les imprimantes
description: "Déclarer une imprimante du serveur, la rattacher aux salles qui doivent la voir, publier son pilote Windows et la retirer."
---

# Gérer les imprimantes

Cette fiche explique comment déclarer une imprimante sur le serveur, décider
quelles salles la voient, publier le pilote que les postes Windows utiliseront,
et retirer une imprimante hors service.

*Aussi appelé : imprimante réseau, file d'impression.*

## Où ça se passe

Menu **Parc & postes**, entrée **Gestion du parc**, onglet **Imprimantes**.
Cette page unique regroupe la liste des imprimantes du serveur et, en bas,
le panneau **Pilotes Windows publiés**.

::: droit-requis
Il faut être administrateur du serveur pour ajouter, configurer ou supprimer une
imprimante. Une personne qui n'a qu'une délégation sur une salle **voit** les
imprimantes de ses [parcs](/glossaire#parc) mais ne peut pas les modifier.
:::

## Lire la liste

Chaque ligne décrit une imprimante : son **nom**, son **URI** (son adresse sur
le réseau), son **état**, le nombre de documents en **file** d'attente, son
**lieu**, son **modèle** et les **parcs** auxquels elle est rattachée.

Deux mentions attirent l'attention sur une imprimante à reprendre :

- **non rattachée** — l'imprimante existe sur le serveur mais aucune salle ne la
  reçoit : personne ne la voit sur son poste ;
- **orphan** — SE5 en garde la trace alors que le serveur d'impression ne la
  connaît plus. Elle n'est plus modifiable : supprimez-la, puis recréez-la si
  elle doit revenir.

Les quatre filtres au-dessus de la liste — **Toutes**, **Rattachées**, **Non
rattachées**, **Orphelines** — servent exactement à isoler ces cas.

## Ajouter une imprimante

1. Ouvrez le menu **Actions** en haut de la page, puis **Ajouter une
   imprimante**.
2. Saisissez son **nom** : c'est le nom que les utilisateurs verront sur leur
   poste, limité à 15 caractères — préférez un intitulé qui évoque la salle,
   par exemple `imprimante-salle-101`.
3. Renseignez l'**URI**, l'adresse réseau de l'imprimante — celle indiquée par
   sa fiche ou par le fournisseur.
4. Renseignez la **description** et le **lieu** : ils aident à retrouver
   l'imprimante dans la liste et apparaissent côté poste.
5. Choisissez son **modèle (PPD)** dans la liste proposée par le serveur.
6. Dans **Rattachement aux parcs**, cochez les salles qui doivent recevoir cette
   imprimante.
7. Validez.

::: delai-effet agent
Le poste doit être allumé et connecté au réseau de l'établissement. L'imprimante
apparaît dans la liste des imprimantes du poste au passage suivant de
l'[agent](/glossaire#agent), en général dans l'heure.
:::

## Rattacher ou détacher des salles

Le bouton **Configurer** d'une ligne rouvre les mêmes champs, dont la section
**Rattachement aux parcs**. Ajouter un parc met l'imprimante à disposition de ses
postes ; en retirer un la fait disparaître de ces postes au passage suivant de
l'agent.

Une imprimante peut être rattachée à **plusieurs parcs** : c'est le cas normal
d'une imprimante de couloir partagée entre deux salles.

## Suspendre une imprimante

Le bouton **Désactiver** met l'imprimante en pause sur le serveur : elle reste
visible sur les postes et les documents continuent de s'empiler dans sa file,
mais rien n'est envoyé au matériel. **Activer** repart de la file en l'état.

C'est le geste à utiliser pendant une intervention (bourrage, changement de
consommable, déplacement) — il évite les impressions perdues.

::: vue-poste
L'utilisateur voit toujours l'imprimante et peut lancer une impression sans
message d'erreur : son document attend simplement dans la file jusqu'à la
réactivation.
:::

## Publier un pilote Windows

Les postes Windows récupèrent automatiquement le pilote publié par le serveur —
l'utilisateur n'a rien à installer. Le panneau **Pilotes Windows publiés**, en
bas de l'onglet, gère cet inventaire.

Pour publier le pilote d'une imprimante, SE5 le récupère depuis un **poste
Windows pivot** où il est déjà installé :

1. Ouvrez **Configurer** sur l'imprimante concernée, section **Drivers
   Windows**, puis **Téléverser un driver**.
2. Saisissez le nom du **poste pivot** — il doit être **allumé**, joignable, et
   le pilote doit y être installé et partagé.
3. Cliquez sur **Lister les drivers**, puis sélectionnez celui qui convient dans
   la liste que le pivot renvoie.
4. Cliquez sur **Téléverser et associer** : le pilote est copié sur le serveur
   puis associé à l'imprimante.

Le panneau se filtre par **Tous**, **Avec imprimante**, **Sans imprimante** et
**Orphans**, et propose une **synchronisation** qui réaligne l'inventaire sur ce
que le serveur publie réellement.

::: attention
Si le poste pivot est éteint ou injoignable, la publication échoue et rien n'est
modifié : rallumez le poste et recommencez. Un pilote listé comme **sans
imprimante** n'est pas une anomalie — il reste disponible pour une future
imprimante.
:::

## Supprimer une imprimante

Le bouton **Supprimer** retire l'imprimante du serveur et de tous ses parcs,
après confirmation.

::: attention
La suppression est **définitive** et emporte les documents encore en file
d'attente. Pour une indisponibilité temporaire, utilisez **Désactiver**.
:::

## Résultat observable

L'imprimante ajoutée apparaît dans la liste avec son état et les parcs
rattachés ; une imprimante suspendue s'affiche comme désactivée. Côté poste,
elle apparaît ou disparaît de la liste des imprimantes au passage suivant de
l'agent — ce que voit alors l'utilisateur est décrit dans
[Imprimer](/poste/impression).

## Quand rien n'y fait

- **Toute la liste indique un service injoignable** : le service d'impression du
  serveur ne répond pas. Les états et les files affichés ne sont plus à jour ;
  les modifications sont refusées tant qu'il n'est pas rétabli.
- **L'imprimante n'arrive pas sur les postes** : vérifiez qu'elle est bien
  rattachée au parc de la salle, puis que les postes concernés remontent bien à
  l'heure — voir [Un poste en règle ou en retard](/admin/reglages/poste-en-regle-ou-en-retard).
- **Un message signale que les postes verront la modification plus tard** : le
  changement est enregistré, seule la prise en compte immédiate par le service
  de partage a échoué. Elle se fera au redémarrage du service.

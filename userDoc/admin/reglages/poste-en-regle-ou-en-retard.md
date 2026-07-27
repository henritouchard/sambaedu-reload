---
title: Un poste en règle ou en retard
description: "Lire les indicateurs de conformité et de version d'un poste, comprendre ce qu'ils veulent vraiment dire, et savoir quoi faire."
---

# Un poste en règle ou en retard

Cette fiche explique les indicateurs qui disent si un poste applique bien ce qui
a été décidé (**conformité**), s'il donne encore de ses nouvelles, et si sa
version est **à jour**. Pour chaque indicateur au rouge, elle précise **quoi
faire** — et surtout **ce qu'il ne faut pas en conclure trop vite**.

## Les cinq états de conformité

Chaque poste équipé de l'[agent](/glossaire#agent) porte un **badge de
conformité**. Les mêmes cinq libellés se retrouvent partout — liste des postes,
fiche du poste, panneau du groupe. Un poste sans agent affiche « — ».

- **Conforme**
- **En écart**
- **Erreur**
- **Muet**
- **Jamais rapporté**

La lecture de la liste des postes et de ces badges est décrite dans
[Lire l'état d'un poste](/admin/parc/lire-l-etat-d-un-poste). Voici ce que
chaque état veut dire, et le bon réflexe.

**Conforme** — l'état constaté sur le poste correspond à ce qui a été décidé.
Rien à faire.

**En écart** — un écart a été détecté entre le décidé et le constaté. La cible
**fait toujours loi** : le poste la **réapplique de lui-même** — il n'existe
aucun écart « toléré ». *Quoi faire* : regarder **depuis quand** l'écart dure
avant d'agir (voir plus bas). *Ne pas conclure* qu'une intervention est
requise : un écart de quelques minutes est le plus souvent une convergence en
cours.

**Erreur** — l'application d'un réglage a **échoué** ; le détail s'affiche sur
la fiche du poste. *Quoi faire* : lire le détail, **forcer la synchro** (voir
plus bas), relire après quelques minutes — et seulement ensuite escalader.

**Muet** — le poste est équipé mais **ne donne plus de nouvelles** au-delà d'un
certain délai : il est **probablement éteint, sans certitude**. *Quoi faire* :
vérifier d'abord qu'il est **allumé et relié au réseau**. *Ne pas conclure* à
une panne : « Muet » n'est pas « en panne ».

**Jamais rapporté** — le poste est équipé mais **n'a encore rien remonté**.
*Ne pas conclure* à un problème : c'est l'état normal d'un poste qui n'a pas
encore été allumé depuis son équipement.

## La lecture fine, sur la fiche du poste

La fiche d'un poste porte un tableau **« État rapporté par type »** : un statut
par ressource, avec les colonnes **« Depuis »** et « Rapporté ».

- La colonne **« Depuis »** distingue une **convergence en cours** d'un **écart
  installé**. Un écart de **quelques minutes** est presque toujours une
  convergence — un poste réinstallé, par exemple, est non conforme par
  construction à son tout premier passage. Un écart **ancien** est, lui,
  installé et mérite attention.
- Une **alerte datée « Poste muet »** signale l'absence de nouvelles récentes.
- La mention **« Jamais rapporté »** rappelle que le poste est enrôlé mais n'a
  encore remonté aucun état.
- Un historique **« Derniers événements »**, daté, complète la lecture.

## Le panneau de conformité d'un groupe

L'onglet **Général** de la fiche d'un [groupe de postes](/glossaire#groupe-de-postes)
porte un panneau **« Conformité agent »** : des compteurs — **« N enrôlé(s) »**,
**« N en écart »**, **« N conformes »**, **« N muet(s) »**,
**« N jamais rapporté »** — puis, par type de ressource, un décompte
« n/N conformes » suivi de la **seule liste des exceptions**, datées et
**cliquables** vers la fiche du poste concerné. Les postes conformes ne sont
jamais listés : ce panneau montre ce qui demande de l'attention, pas le reste.

## Forcer la synchro

Pour redemander au poste d'appliquer l'état décidé sans attendre son cycle
habituel, le bouton **« Forcer la synchro »** est présent sur la fiche du poste
(et **« Forcer la synchro du groupe »** sur la fiche d'un groupe).

- L'état complet est re-servi au **prochain contact du poste** avec le serveur.
- L'affichage se **remet à jour tout seul** (rafraîchi environ toutes les 15
  secondes).
- Un poste en **quarantaine** ne peut pas être forcé : le bouton est inerte et
  le motif s'affiche en infobulle.

::: droit-requis
Lire ces états suppose le droit **Voir les machines**. **Forcer la synchro**
suppose en plus le droit **Contrôle à distance**.
:::

::: delai-effet agent
« Forcer la synchro » prend effet au **prochain contact du poste** avec le
serveur : le poste doit être allumé et relié au réseau.
:::

## « En retard » : une question de version

Le déploiement de l'agent progresse dans le temps. La **« Console de la
flotte »** (menu **Serveur** → **Réglages** → section « Agent / Flotte »)
montre cette progression : la **version ciblée** face aux **versions rapportées
par les postes**, réparties en trois colonnes — **« À jour »**,
**« En retard »**, **« Jamais vus »**.

La version d'un poste est **celle qu'il rapporte lorsqu'il contacte le
serveur**. Un poste qui ne s'est pas manifesté depuis la publication d'une
nouvelle version est donc **« En retard »** — *sans être en panne pour autant*.
*Ne pas conclure* à un dysfonctionnement : le poste rattrapera son retard à son
prochain contact ; aucun délai de rattrapage n'est garanti.

La console porte aussi les **demandes d'enrôlement** des postes et le pilotage
des versions de l'agent : ce sont des gestes avancés, hors du champ de cette
fiche.

---
title: "J'administre SE5 : le plan du guide"
description: "Les domaines de l'administration, l'endroit où chaque besoin se traite, et comment lire les fiches de ce guide."
---

# J'administre SE5

Ce guide s'adresse au **référent numérique** — la personne qui gère
l'informatique de l'établissement. Il rassemble, tâche par tâche, ce qu'on
fait au quotidien depuis l'interface d'administration de SE5.

Cette page est la carte du guide : elle présente les grands domaines de
l'administration, indique par quel menu chacun se traite, relie les besoins
courants d'un établissement à l'endroit où on y répond, et explique comment
lire les fiches qui suivent. Les fiches détaillées de chaque domaine viendront
s'y rattacher au fil du temps.

## Les domaines de l'administration

L'administration se répartit en sept domaines. Pour chacun : ce qu'on y fait,
et le chemin à suivre dans l'interface pour l'atteindre.

### [Utilisateurs et groupes](/admin/utilisateurs/)

Consulter et créer les comptes des élèves et des enseignants, réinitialiser un
mot de passe, et repérer les comptes qui demandent une attention (mot de passe
resté par défaut, espace de fichiers saturé). Les élèves d'une même classe et
les enseignants d'une même équipe sont réunis en groupes prêts à l'emploi.

Chemin : menu **Pilotage**, entrée **Utilisateurs**.

### [Parc et postes](/admin/parc/)

Regrouper les postes de l'établissement en [parcs](/glossaire#parc) — aussi
appelés [groupes de postes](/glossaire#groupe-de-postes) —, le plus souvent
une salle par parc, et suivre l'état de chaque poste. Le parc est l'unité à
laquelle on rattache ensuite les réglages et les applications.

Chemin : menu **Parc & postes**, entrée **Gestion du parc**.

### [Applications et personnalisation des postes](/admin/applications/)

Choisir, dans le [dépôt d'applications](/glossaire#depot-applications), les
logiciels installés sur les postes d'un parc, en plus du
[socle commun](/glossaire#socle-commun) présent partout ; définir un fond
d'écran ou d'autres réglages — les [capacités](/glossaire#capacite) — pour une
salle ; réunir un ensemble de logiciels en [profil applicatif](/glossaire#profil-applicatif)
appliqué à plusieurs parcs d'un seul geste.

Chemin : menu **Parc & postes**, entrée **Applications**.

### [Fichiers et partages](/admin/fichiers/)

Ouvrir un [partage](/glossaire#partage) — un dossier commun — à une classe ou à
une équipe, et régler la place accordée à l'[espace personnel](/glossaire#espace-personnel)
de chaque utilisateur.

Chemin : menu **Serveur**, entrée **Réglages**.

### [Droits et délégation](/admin/droits/)

Confier une partie de l'administration à un collègue sans lui donner la main
sur tout, en composant des profils de droits adaptés à son rôle.

Chemin : menu **Pilotage**, entrée **Gestion des droits**.

### [Installation et déploiement d'un poste](/admin/installer/)

Préparer le démarrage par le réseau et mettre en service un poste neuf pour
qu'il rejoigne l'établissement.

Chemin : menu **Serveur**, entrée **Réglages**.

### [Réglages et supervision](/admin/reglages/)

Vérifier d'un coup d'œil que l'établissement fonctionne bien — postes, comptes,
activité — et ajuster les réglages généraux du serveur.

Chemin : menu **Pilotage**, entrée **Tableau de bord**, et menu **Serveur**,
entrée **Réglages**.

::: droit-requis
Certaines entrées de menu n'apparaissent qu'aux personnes qui en détiennent le
droit : le menu **Réglages**, par exemple, n'est visible que pour qui
administre le serveur. Si une entrée citée ici ne figure pas dans votre menu,
c'est que ce droit ne vous a pas été confié.
:::

## Trouver le bon domaine à partir d'un besoin

Un besoin courant de l'établissement mène toujours à l'un des sept domaines
ci-dessus. Voici quelques exemples de correspondance.

<div class="se5-plan" role="list">
<div class="se5-plan__pair" role="listitem">
<div class="se5-plan__need">Accueillir les nouveaux élèves à la rentrée</div>
<div class="se5-plan__arrow" aria-hidden="true">→</div>
<div class="se5-plan__domain">Utilisateurs et groupes</div>
</div>
<div class="se5-plan__pair" role="listitem">
<div class="se5-plan__need">Organiser les postes d'une salle</div>
<div class="se5-plan__arrow" aria-hidden="true">→</div>
<div class="se5-plan__domain">Parc et postes</div>
</div>
<div class="se5-plan__pair" role="listitem">
<div class="se5-plan__need">Installer un logiciel dans une salle</div>
<div class="se5-plan__arrow" aria-hidden="true">→</div>
<div class="se5-plan__domain">Applications et personnalisation des postes</div>
</div>
<div class="se5-plan__pair" role="listitem">
<div class="se5-plan__need">Ouvrir un espace commun à une classe</div>
<div class="se5-plan__arrow" aria-hidden="true">→</div>
<div class="se5-plan__domain">Fichiers et partages</div>
</div>
<div class="se5-plan__pair" role="listitem">
<div class="se5-plan__need">Donner la main à un collègue sans tout lui confier</div>
<div class="se5-plan__arrow" aria-hidden="true">→</div>
<div class="se5-plan__domain">Droits et délégation</div>
</div>
<div class="se5-plan__pair" role="listitem">
<div class="se5-plan__need">Préparer un poste neuf</div>
<div class="se5-plan__arrow" aria-hidden="true">→</div>
<div class="se5-plan__domain">Installation et déploiement d'un poste</div>
</div>
<div class="se5-plan__pair" role="listitem">
<div class="se5-plan__need">Vérifier que tout fonctionne bien</div>
<div class="se5-plan__arrow" aria-hidden="true">→</div>
<div class="se5-plan__domain">Réglages et supervision</div>
</div>
</div>

## Comment lire une fiche

Chaque fiche de ce guide décrit une tâche concrète. Là où c'est utile, elle
porte trois informations repérables d'un coup d'œil : le droit requis pour
agir, le résultat que vous devez observer, et le moment où l'effet devient
visible sur le poste.

### Le droit requis

Quand une tâche suppose une habilitation particulière, la fiche l'annonce dans
un encart dédié. Vous savez ainsi avant de commencer si l'action est à votre
portée.

::: droit-requis
Il faut être administrateur du parc concerné.
:::

### Le résultat observable

Chaque fiche décrit ce que vous devez voir une fois les gestes effectués : une
ligne qui apparaît, un message de confirmation, une valeur mise à jour. C'est
le repère qui vous dit que l'action a bien abouti.

### Le moment où l'effet est visible

Une action faite sur le serveur ne se traduit pas toujours instantanément sur
le poste de l'utilisateur. La fiche le précise dans un encart, avec l'une de
ces trois temporalités, nommées exactement comme vous les rencontrerez :

::: delai-effet immediat
Le changement est visible dès que l'action est terminée, sans rien faire de
plus.
:::

::: delai-effet session
L'utilisateur doit fermer puis rouvrir sa session sur le poste pour voir le
changement.
:::

::: delai-effet agent
Le poste doit être allumé et relié au réseau de l'établissement : c'est alors
l'[agent](/glossaire#agent) qui applique le changement, à son prochain passage.
:::

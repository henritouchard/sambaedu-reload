---
title: Le catalogue et le dépôt
description: "Remplir le catalogue d'applications de l'établissement depuis un dépôt, et comprendre ce qu'un contrat amont y change."
---

# Le catalogue et le dépôt

*Aussi appelé : logiciels, programmes.*

Cette fiche explique comment l'établissement se constitue un **catalogue**
d'applications prêtes à déployer, à partir d'un
[dépôt d'applications](/glossaire#depot-applications), et comment retirer une
application de ce catalogue.

## Où ça se passe

Menu **Parc & postes**, entrée **Applications**. Deux onglets servent ici :
**Catalogue d'Applications** — la liste des applications de l'établissement —
et **Dépôt** — la source dans laquelle on va les chercher.

![Onglet Catalogue d'Applications du Collège de Brumeville, avec la liste des applications, leur état et la colonne Déploiement](/captures/admin/applications/catalogue-et-depot/onglet-catalogue.png)

::: droit-requis
Il faut être administrateur des applications du parc concerné.
:::

## Le catalogue se remplit depuis le dépôt

Le catalogue n'a **pas de saisie manuelle** : on n'y ajoute jamais une
application « à la main ». Toute application entre au catalogue en la
choisissant dans un dépôt. Une application absente du dépôt ne peut donc pas
être déployée.

L'onglet **Catalogue d'Applications** liste les applications déjà retenues, avec
pour chacune son identifiant, sa version, sa catégorie, sa compatibilité et son
état. Une recherche et un filtre par catégorie aident à s'y retrouver. La
colonne **Déploiement** indique, pour chaque application, combien de postes
l'ont installée par rapport au nombre visé — c'est le premier signe visible
qu'un déploiement avance.

## Ajouter une application au catalogue

1. Sur l'onglet **Catalogue d'Applications**, ouvrez le menu **Actions** puis
   **Ajouter des applications**.
2. La fenêtre **Ajouter des applications depuis le dépôt** s'ouvre. Au besoin,
   cliquez sur **Synchroniser le dépôt** pour rafraîchir la liste disponible.
3. Choisissez la branche : **Stable** est le choix normal ; **Testing** et
   **Manuel** ne servent qu'à des essais ponctuels.
4. Recherchez, cochez une ou plusieurs applications, puis validez par
   **Installer**.

![Fenêtre Ajouter des applications depuis le dépôt, avec les branches Stable, Testing et Manuel et la sélection multiple](/captures/admin/applications/catalogue-et-depot/modale-depot.png)

Un message confirme le nombre d'applications ajoutées au catalogue. Le serveur
récupère alors les fichiers nécessaires : une application peut rester un moment
« en cours », et signaler une **erreur** si la récupération échoue. Dans ce cas,
sélectionnez la ou les applications concernées et utilisez **Réessayer
l'installation**.

## Résultat observable

L'application ajoutée apparaît dans la liste du catalogue avec son état
(installée au catalogue, en cours, ou en erreur). Tant qu'elle est seulement au
catalogue, elle n'est encore posée sur aucun poste : c'est son
[affectation](/admin/applications/affecter-une-application) à des postes qui
déclenche l'installation.

## Retirer une application du catalogue

Le bouton **Supprimer l'installation** (sur la ligne d'une application) retire
l'application du catalogue de l'établissement. C'est un geste différent du
[retrait d'une affectation](/admin/applications/retirer-une-application), qui ne
concerne que certains postes : ici, l'application quitte l'établissement tout
entier.

::: attention
**Supprimer l'installation** est **irréversible** et efface les fichiers locaux
de l'application. Cette suppression **détache aussi l'application de tous les
profils, parcs et postes** qui la recevaient : elle sera désinstallée des postes
concernés au passage suivant de leur [agent](/glossaire#agent). Pour retirer une
application de quelques postes seulement sans la sortir du catalogue, passez par
le [retrait d'une affectation](/admin/applications/retirer-une-application).
:::

## Si votre établissement est relié à une autorité de gestion

Un établissement peut être relié à une **autorité amont** qui fixe, par contrat,
le catalogue applicatif de référence. Dans ce cas, l'onglet **Dépôt** affiche un
bandeau **Dépôts gérés par l'autorité amont** et le dépôt du contrat porte la
mention **(imposé par l'autorité amont)**.

Concrètement, ce que vous verrez change ainsi :

- Le choix des dépôts n'est **plus modifiable localement** : les actions
  d'ajout, de suppression et de synchronisation manuelle d'un dépôt sont
  désactivées. Le dépôt imposé **se met à jour tout seul** par la liaison amont.
- Seules les applications prévues par le contrat peuvent être **ajoutées au
  catalogue**.
- En revanche, **l'affectation reste entièrement votre main** : tout ce qui est
  déjà au catalogue local reste librement affectable aux parcs et aux postes de
  l'établissement.

Ces verrous valent **tant que le lien avec l'autorité amont est actif** ; s'il
est rompu, la main revient à l'établissement.

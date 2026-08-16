---
title: Limiter l'espace de stockage
description: "Fixer le plafond par défaut de l'établissement, le corriger pour un groupe ou pour un compte, et repérer les comptes en dépassement."
---

# Limiter l'espace de stockage

*Aussi appelé : quota.*

Cette fiche explique comment limiter la place occupée sur le serveur par les
comptes de l'établissement, et comment repérer ceux qui débordent.

## Où ça se passe

Trois endroits, du plus général au plus précis :

- le **plafond par défaut de l'établissement** — menu **Serveur**, entrée
  **Réglages**, carte **Gestion des fichiers**, onglet **Emplacements et
  cloud**, carte **Quotas des espaces personnels** ;
- la **fiche d'un groupe**, section **Quota du groupe** ;
- la **fiche d'un compte**, section **Quotas disque**.

Les fiches de compte et de groupe s'ouvrent depuis le menu **Pilotage**, entrée
**Utilisateurs**.

::: droit-requis
Il faut être administrateur du serveur pour modifier un quota.
:::

## Quelle règle s'applique à un compte

SE5 cherche dans cet ordre et **s'arrête à la première réponse trouvée** :

1. une règle posée sur **le compte lui-même** ;
2. sinon, une règle posée sur **un des groupes** du compte ;
3. sinon, le **plafond par défaut de l'établissement** ;
4. si rien de tout cela n'existe, le compte est **sans limite**.

Quand plusieurs groupes du compte portent une règle, c'est celle qui accorde le
**plus grand plafond chiffré** qui l'emporte.

::: attention
Une règle de groupe réglée sur **Illimité** ne l'emporte que si **toutes** les
règles de groupe du compte sont illimitées. Dès qu'un autre groupe du compte
porte un plafond chiffré, c'est ce plafond qui s'applique, même s'il est plus
restrictif. Pour garantir l'illimité à quelqu'un, posez la règle sur **son
compte**.
:::

## Le plafond par défaut de l'établissement

C'est le plafond qui s'applique à tout compte qu'aucune règle personnelle ni
règle de groupe ne couvre — c'est-à-dire, en pratique, la majorité des comptes.
Un budget plus large pour une population donnée se pose en **règle de groupe**,
où il se voit.

### Les quatre champs, et pourquoi il y en a quatre

La carte **Quotas des espaces personnels** porte, pour chaque espace, quatre
champs. Deux se saisissent librement, un se calcule, un décide du délai :

| Champ | Ce qu'il fait |
| --- | --- |
| **Plafond (Mo)** | la limite à partir de laquelle le compte est **en dépassement**. Il écrit encore. `0` signifie illimité. |
| **Dépassement toléré (%)** | de combien le compte peut dépasser ce plafond avant que l'écriture ne devienne impossible. Il se saisit en pourcentage, jamais en mégaoctets. |
| **Blocage de l'écriture** | la valeur à laquelle l'écriture est refusée, sans délai ni recours. **Elle se calcule** à partir des deux champs précédents : l'écran l'affiche, il ne vous la demande pas. |
| **Période de grâce (jours)** | le délai laissé au compte, **après** être passé en dépassement, pour redescendre avant que l'écriture ne soit bloquée. De 0 à 30 jours. |

Autrement dit, **un dépassement ne bloque pas tout de suite**. Entre le plafond
et le blocage, la personne dispose de la période de grâce pour faire le ménage ;
elle est prévenue à sa connexion. Le blocage arrive au terme de ce délai, ou
d'un coup si elle atteint la valeur de **Blocage de l'écriture** — le premier des
deux qui survient.

::: attention
C'est la **période de grâce**, et non le plafond, qui décide **quand quelqu'un
est réellement arrêté**. Un plafond serré avec une grâce confortable dérange
beaucoup moins qu'un plafond large sans délai. Régler un plafond très généreux
« pour ne bloquer personne » n'est donc pas nécessaire : le délai est déjà là
pour ça.
:::

### Si un avertissement annonce un regroupement de plafonds

Il se peut que la carte affiche en permanence un avertissement disant que
**plusieurs plafonds par défaut ont été regroupés en un seul**, en listant les
valeurs regroupées.

Ce que ça veut dire : cet établissement réglait autrefois un plafond différent
selon le type de compte. Ces réglages ont été **remplacés par un plafond unique**,
et c'est **la valeur la plus large** qui a été retenue. Personne n'a perdu de
place — mais tout le monde a la place du plus favorisé, **élèves compris**. La
valeur la plus large a été retenue parce qu'un plafond qui rétrécit met des gens
à l'arrêt sans que personne n'ait cliqué, alors qu'un plafond qui s'élargit ne
bloque personne **et n'occupe aucun disque** : un plafond limite, il ne réserve
rien.

**Quoi en faire.** Regardez la valeur retenue, décidez si elle convient à votre
établissement, et **resserrez-la si elle est trop généreuse** — l'écran vous dira
avant le clic combien de comptes basculeraient en dépassement. L'avertissement
disparaît dès que vous enregistrez une valeur vous-même, y compris si vous
enregistrez celle qui est déjà là.

::: attention
Le regroupement **ne se défait pas**. Les anciens plafonds par type de compte
n'existent plus et ne peuvent pas être rétablis d'un geste : un budget particulier
pour une population donnée se repose en **règle de groupe**.
:::

**Enregistrer n'est pas appliquer.** La carte annonce combien de comptes sont
couverts par ce plafond et combien passeraient immédiatement en dépassement, puis
un bouton **Appliquer à tous les comptes couverts** porte la valeur sur le
serveur. Tant que vous ne l'avez pas cliqué, la nouvelle valeur ne change rien
aux comptes existants.

Quand le serveur n'applique pas de quota sur un espace, le champ est **fermé et
le motif est affiché**. Deux motifs bien distincts :

- **les quotas ne sont pas appliqués sur cette partition** — c'est un fait, à
  corriger côté serveur ;
- **impossible de déterminer si cette partition porte un quota** — SE5 ne sait
  pas, et il le dit plutôt que de conclure à tort.

## Le quota d'un groupe

La section **Quota du groupe** de la fiche d'un groupe affiche l'état courant :
**Hérité (défaut)**, **Illimité**, ou une valeur propre au groupe. Le bouton
**Modifier** ajuste cette limite, qui s'applique aux membres du groupe.

## Le quota d'un compte

La section **Quotas disque** de la fiche d'un compte montre l'utilisation par
espace — l'[espace personnel](/glossaire#espace-personnel) et les
[partages](/glossaire#partage). Deux boutons :

1. **Actualiser** — recalcule l'utilisation réelle au moment où vous cliquez.
2. **Modifier le quota** — fixe la limite de l'espace concerné, pour ce compte
   seul.

## Résultat observable

La valeur affichée dans la section se met à jour, et un message confirme le
changement.

::: delai-effet immediat
Une règle posée sur un compte ou sur un groupe est appliquée en arrière-plan,
quelques instants après la validation. Le plafond par défaut, lui, n'atteint les
comptes existants qu'au clic sur **Appliquer à tous les comptes couverts**.
:::

## Repérer les comptes en dépassement

La liste des utilisateurs propose un filtre d'audit **Quota dépassé** qui isole
les comptes qui débordent : voir le domaine
[Utilisateurs et groupes](/admin/utilisateurs/).

::: vue-poste
Quand un compte passe au-dessus de son plafond, l'utilisateur en est averti à sa
connexion et continue d'écrire pendant la période de grâce. Passé ce délai — ou
dès qu'il atteint la valeur de **Blocage de l'écriture** —, l'enregistrement de
nouveaux fichiers échoue sur le poste. Ce que vit l'utilisateur est décrit dans
[Mon espace personnel](/poste/fichiers/espace-personnel).
:::

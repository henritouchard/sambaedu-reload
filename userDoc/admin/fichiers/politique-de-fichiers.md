---
title: Choisir où vivent les fichiers
description: "Décider, pour tout l'établissement, si l'espace personnel et l'espace partagé sont servis par le serveur de fichiers ou par le cloud, et ce que cela change sur les postes."
---

# Choisir où vivent les fichiers

*Aussi appelé : politique de fichiers.*

Cette fiche explique comment décider, **pour tout l'établissement**, où sont
rangés les fichiers : sur le serveur de fichiers, ou dans le cloud de
l'établissement. Elle décrit aussi ce que chaque choix change concrètement sur
les postes.

## Où ça se passe

Menu **Serveur**, entrée **Réglages**, carte **Gestion des fichiers**, onglet
**Emplacements et cloud**.

::: droit-requis
Il faut être administrateur du serveur. L'entrée **Réglages** n'apparaît que pour
les personnes qui en détiennent le droit.
:::

## Deux espaces, deux décisions

L'écran pose **deux questions indépendantes**, valables pour **tout
l'établissement** — il n'existe aucun réglage par salle ni par
[parc](/glossaire#parc) :

- **Espace personnel** — le dossier que chaque personne retrouve d'un poste à
  l'autre. Ce que l'utilisateur y range est décrit dans
  [Mon espace personnel](/poste/fichiers/espace-personnel).
- **Espace partagé** — les dossiers de classe et d'équipe. Ce que l'utilisateur y
  trouve est décrit dans
  [Les espaces partagés](/poste/fichiers/espaces-partages).

Chacun des deux vit **soit** sur le **serveur de fichiers**, **soit** dans le
**cloud de l'établissement**. Les deux réponses sont indépendantes : l'espace
personnel peut partir dans le cloud pendant que l'espace partagé reste sur le
serveur, et l'inverse.

## Un seul cloud à la fois

Au-dessus des deux espaces, un bloc **Le cloud de l'établissement** propose trois
positions exclusives : **Aucun cloud**, ou l'un des deux produits pris en charge.
Choisir une position fait apparaître **sa** page de connexion, et elle seule :
c'est là que se saisissent l'adresse du serveur, le compte administrateur et la
vérification du certificat.

Tant qu'aucun cloud n'est retenu, les deux espaces n'ont qu'une réponse possible :
le serveur de fichiers.

## Ce que ça change sur les postes

| Décision | Effet sur le poste |
| --- | --- |
| Espace personnel sur le **serveur de fichiers** | le lecteur `K:` « Mes documents » est monté |
| Espace personnel dans le **cloud** | pas de lecteur `K:` |
| Espace partagé sur le **serveur de fichiers** | le lecteur `H:` « Classes » est monté |
| Espace partagé dans le **cloud** | pas de lecteur `H:` |
| Au moins un des deux espaces dans le **cloud** | un raccourci **Mes fichiers en ligne** apparaît sur le bureau |

Deux points à connaître, parce qu'ils surprennent :

- **Les [partages](/glossaire#partage) réseau que vous créez vous-même gardent
  toujours leur lettre de lecteur.** Ils ne suivent pas ces deux décisions :
  chacun garde l'emplacement choisi au moment de sa création. Une lettre de
  lecteur ne pointe jamais vers le cloud.
- **Mettre l'espace personnel dans le cloud ne coupe pas le dossier personnel du
  serveur.** L'[agent](/glossaire#agent) continue de s'en servir pour le bureau
  redirigé, les raccourcis et les réglages d'applications. Seuls les fichiers de
  l'utilisateur changent d'endroit. L'écran le rappelle sous la question.

## Les gestes

::: attention
**À lire avant de commencer : ces gestes n'aboutissent que sur un établissement
qui n'a pas encore de comptes ni de groupes.** Dès qu'il en existe, SE5 refuse de
déplacer un espace, n'enregistre rien, et l'écran reprend les valeurs déjà en
place. Sur un établissement en service, les cinq gestes ci-dessous se soldent donc
par un refus. Tranchez ces deux questions **avant le premier import de comptes**.
:::

1. Ouvrez le menu **Serveur**, entrée **Réglages**.
2. Ouvrez la carte **Gestion des fichiers**, onglet **Emplacements et cloud**.
3. Dans **Le cloud de l'établissement**, choisissez la position voulue. Si vous
   retenez un produit, complétez sa page de connexion qui vient d'apparaître.
4. Dans **Où vivent les fichiers**, répondez pour l'**espace personnel** puis pour
   l'**espace partagé**.
5. Cliquez sur **Enregistrer les emplacements**.

Le cloud et les deux espaces s'enregistrent **d'un seul geste** : c'est leur
combinaison qui est vérifiée, pas chaque case prise isolément.

## Comment les gens atteignent le cloud

Quand un cloud est en service, le bloc **Réglages**, plus bas dans le même
onglet, propose un champ **Chemin d'accès au cloud** à deux positions :

- **Par le navigateur** — l'utilisateur ouvre ses fichiers depuis le raccourci du
  bureau. Rien n'est installé sur le poste.
- **Par le client de synchronisation** — l'application cliente du produit est
  posée sur les postes.

La seconde position n'est proposée que si vous avez d'abord désigné, dans le
champ **Application cliente du cloud**, l'application du dépôt qui joue ce rôle.
SE5 n'invente aucun paquet : il vous laisse désigner celui de votre dépôt, et
c'est le canal de déploiement habituel qui l'installe et le retire.

::: attention
Sur un poste partagé par plusieurs personnes, le client de synchronisation doit
être réglé en **fichiers à la demande**. Sans cela, chaque session recopie
l'intégralité de l'espace de son utilisateur sur le disque du poste.
:::

## Résultat observable

Sous chaque question, l'écran annonce l'effet correspondant (« Lecteur K: monté
sur le poste », ou « Pas de lettre de lecteur : accès par le client »). Après
l'enregistrement, un message confirme la prise en compte et l'écran reprend les
valeurs enregistrées.

::: delai-effet session
Les lecteurs et le raccourci du bureau sont mis en place à l'ouverture de session
suivante. Une session déjà ouverte n'est pas modifiée.
:::

::: vue-poste
Selon vos choix, l'utilisateur voit apparaître ou disparaître les lecteurs `K:`
(« Mes documents ») et `H:` (« Classes ») dans son explorateur de fichiers, et le
raccourci **Mes fichiers en ligne** sur son bureau.
:::

::: attention
**Ce choix se fige dès que l'établissement a des comptes ou des groupes.** À
partir de là, SE5 refuse de déplacer un espace et n'enregistre rien.

Et il n'existe **aucun autre endroit** où le faire : **déménager des fichiers
déjà en place n'est pas possible aujourd'hui**, ni depuis cet écran ni ailleurs.
Le refus n'est pas un renvoi vers une autre page, c'est une limite du produit. Si
un espace doit changer de place sur un établissement en service, c'est une
opération à mener avec votre support.
:::

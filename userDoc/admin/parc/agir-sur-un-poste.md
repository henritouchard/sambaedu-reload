---
title: Agir sur un poste
description: "Allumer, éteindre, redémarrer ou prendre la main sur un poste à distance, et forcer une resynchronisation, en sachant ce que l'on voit en retour."
---

# Agir sur un poste

Cette fiche décrit les actions disponibles sur un poste et, pour chacune, ce que
vous voyez **immédiatement** en retour et ce qu'il faut **attendre ensuite**.

## Où ça se passe

Menu **Parc & postes**, entrée **Gestion du parc**, onglet **Postes**. Cliquez
sur un poste pour ouvrir sa fiche : les actions s'y trouvent en tête.

::: droit-requis
Il faut détenir le droit **Contrôle à distance**.
:::

## Le principe : retour immédiat, puis suivi

Dès qu'une action est lancée, un **message confirme son départ** (« Allumage
lancé », par exemple). L'interface **suit ensuite le poste** et annonce soit sa
disponibilité (« Machine disponible en… »), soit son injoignabilité au bout d'un
délai d'attente — **environ deux minutes**. Pendant ce suivi, les autres actions
du poste sont **désactivées**, sauf l'accès distant.

La « disponibilité » constatée signifie que **le poste répond sur le réseau** ;
elle n'atteste pas qu'une session est ouvrable à la seconde.

::: delai-effet immediat
L'ordre part **aussitôt** ; le **résultat** côté machine dépend du poste (allumé,
joignable sur le réseau).
:::

## Les cinq actions

- **Allumer** — envoie un signal d'allumage au poste par le réseau. Aucune
  confirmation n'est demandée.
- **Éteindre** — demande au poste de s'éteindre. Une confirmation est demandée.
- **Forcer l'extinction** — même effet technique qu'**Éteindre**, avec une
  confirmation renforcée (voir l'avertissement ci-dessous).
- **Redémarrer** — demande au poste de redémarrer. Une confirmation est
  demandée.
- **Accès distant** — ouvre une prise en main du poste. Aucune confirmation
  n'est demandée ; l'action reste disponible même pendant le suivi d'une autre
  commande.

::: attention
**Forcer l'extinction** coupe le poste sans ménager la session en cours : un
utilisateur connecté peut **perdre son travail non sauvegardé**. Ne l'employez
que lorsqu'un poste ne répond plus à une extinction ordinaire.
:::

::: vue-poste
À l'extinction ou au redémarrage, l'utilisateur voit son poste s'éteindre ou
redémarrer sous ses yeux.
:::

### À propos de l'allumage à distance

Le signal d'allumage est diffusé sur le réseau de l'établissement. Dans la
plupart des installations, le poste s'allume — à condition qu'il soit branché au
courant et configuré pour ce réveil. Selon l'architecture du réseau, le signal
peut toutefois ne pas atteindre certains postes. **Si un poste ne s'allume
jamais à distance**, rapprochez-vous de la personne qui gère le réseau de
l'établissement.

## Forcer la synchro

La fiche d'un poste propose aussi **Forcer la synchro** : elle demande au poste
de resynchroniser sa configuration. La demande est notée côté serveur et honorée
**au prochain passage de l'agent**. Elle est refusée si le poste est en
quarantaine.

::: delai-effet agent
Le poste doit être allumé et relié au réseau de l'établissement : la
resynchronisation a lieu au prochain passage de son agent.
:::

## Résultat observable

Après chaque action d'alimentation, le message de lancement s'affiche
immédiatement, puis le suivi conclut sur « disponible » ou « non joignable ».
Pour **Forcer la synchro**, la demande apparaît comme en attente jusqu'à ce que
le poste la prenne en compte.

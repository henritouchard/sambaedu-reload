<!--
  Modèle de fiche — Story 52.2 (gabarit de fiche, encarts, glossaire).
  Ce fichier n'est PAS publié (srcExclude) : copiez-le pour démarrer une
  nouvelle fiche, puis retirez ce commentaire et les crochets [entre
  crochets]. Le gabarit complet est détaillé dans CONTRIBUTING.md.
-->
---
title: "[Titre court pour la nav/onglet]"
description: "[Une phrase : ce que le lecteur repart en sachant faire]"
---

# [La tâche ou la question du lecteur — jamais un nom d'écran technique]

[Une phrase d'intention : ce que cette fiche permet de faire ou de
comprendre.]

## Où ça se passe

[Parcours admin : l'écran ou le menu où se trouve ce dont parle la fiche.
Parcours poste : le contexte d'usage — remplacer ce titre par une phrase de
contexte si la fiche s'adresse à l'utilisateur du poste.]

![Texte alternatif à remplacer : ce que montre l'écran et, s'il y a des repères numérotés, ce qu'ils pointent](/captures/[chemin-de-la-fiche-sans-extension]/[nom-de-lecran].png)

<!-- Capture facultative : à retirer si la fiche ne décrit aucun écran que le
     lecteur doit reconnaître. Sinon, la garder ICI, ou la déplacer juste
     après « Les gestes » si elle en annote la liste numérotée (repères =
     numéros des gestes). Voir « Captures d'écran » dans CONTRIBUTING.md :
     jeu fictif ratifié, nommage kebab-case sans numéro d'ordre, alt
     obligatoire. Tant qu'aucun fichier n'existe au chemin donné, cette ligne
     rend un placeholder « Illustration à venir » — rien à modifier ici au
     dépôt de l'image. -->

::: droit-requis
[L'habilitation nécessaire pour effectuer ce qui suit, si la fiche en
décrit une. Retirer cet encart si aucun droit particulier n'est requis.]
:::

## Les gestes

1. [Premier geste, une action par étape.]
2. [Deuxième geste.]
3. [Etc.]

::: delai-effet immediat
[Précision facultative en une phrase. Retirer la phrase si elle n'apporte
rien — la valeur immediat|session|agent, elle, est OBLIGATOIRE.]
:::

## Résultat observable

[Ce que le lecteur doit voir une fois les gestes effectués, pour savoir que
ça a marché.]

::: vue-poste
[Ce que voit concrètement l'utilisateur du poste, si le geste décrit a une
conséquence visible côté poste. Retirer l'encart sinon.]
:::

::: attention
[Un point de vigilance : action irréversible, effet de bord, etc. Retirer
l'encart si la fiche n'a rien de ce type à signaler — ne jamais utiliser
::: warning ou ::: danger à la place, le lint les refuse.]
:::

Premier usage d'un mot maison dans cette fiche : lier vers le glossaire à sa
PREMIÈRE occurrence seulement, par exemple un
[parc](/glossaire#parc) ou une [capacité](/glossaire#capacite).

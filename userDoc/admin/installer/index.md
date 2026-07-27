---
title: Installer et déployer un poste
description: "Le parcours complet d'un poste, de ses prérequis à sa mise en service : préparer les systèmes, déclarer et installer une machine neuve, la réinstaller au besoin, vérifier qu'elle a rejoint l'établissement."
---

# Installer et déployer un poste

Ce domaine décrit le parcours complet d'une machine : intégrer un poste neuf ou
repartir d'une installation saine, jusqu'à ce que le poste ait rejoint
l'établissement et soit prêt à l'emploi.

Il a une particularité qu'aucun autre domaine du guide ne partage : une partie
des gestes se fait **dans l'interface web** (préparer les systèmes, réinstaller
un poste, vérifier son état), et une autre partie se fait **à l'écran du poste
lui-même**, sur les menus de démarrage par le réseau (déclarer la machine et
lancer l'installation d'un poste neuf). Chaque fiche indique clairement où l'on
se trouve.

## Le parcours, dans l'ordre réel

Pour mettre en service un poste neuf, on suit ces cinq étapes dans l'ordre.
Chacune se termine par un repère observable qui confirme qu'on peut passer à la
suivante.

1. **Vérifier les prérequis.** Le démarrage par le réseau, l'adressage réseau,
   les sources d'installation et les informations de rattachement à
   l'établissement doivent être en place.
   *Réussite : le contrôle « iPXE » de la page « État du système » ne signale
   rien.* → [Prérequis](/admin/installer/prerequis)
2. **Préparer les systèmes côté serveur.** Rendre disponibles, dans
   l'interface, les systèmes que l'on pourra installer (Windows, distributions
   Linux).
   *Réussite : le système visé s'affiche comme déployé ou disponible sur la page
   « OS installables ».* → [Préparer les systèmes](/admin/installer/preparer-les-systemes)
3. **Déclarer la machine, à l'écran du poste.** Faire démarrer le poste par le
   réseau, s'identifier, puis lui donner un nom. Cette étape ne se fait **pas**
   dans l'interface web.
   *Réussite : le poste apparaît dans « Gestion du parc », avec son nom.*
   → [Installer un poste neuf](/admin/installer/installer-un-poste-neuf)
4. **Lancer et suivre l'installation.** Depuis le même menu du poste, choisir
   l'installation Windows ou Linux.
   *Réussite : l'installation se déroule sans intervention et le poste redémarre
   sur son nouveau système.* → [Installer un poste neuf](/admin/installer/installer-un-poste-neuf)
5. **Vérifier la mise en service.** Contrôler, dans l'interface, que le poste a
   bien rejoint l'établissement.
   *Réussite : le système affiché est à jour, le poste est « Allumé » et son
   dernier rapport est récent.* → [Vérifier la mise en service](/admin/installer/verifier-la-mise-en-service)

## Réinstaller un poste déjà connu

Un poste **déjà déclaré** ne repasse pas par l'écran de démarrage : sa
réinstallation se pilote **entièrement depuis l'interface**, poste par poste,
salle entière ou sélection. C'est le geste à connaître pour repartir d'une
installation propre.
→ [Réinstaller un poste](/admin/installer/reinstaller-un-poste)

## Les fiches de ce domaine

- [Prérequis](/admin/installer/prerequis) — ce qui doit être en place avant
  d'installer quoi que ce soit, et le contrôle qui le vérifie.
- [Préparer les systèmes](/admin/installer/preparer-les-systemes) — rendre les
  systèmes installables disponibles depuis l'interface.
- [Installer un poste neuf](/admin/installer/installer-un-poste-neuf) — déclarer
  puis installer une machine, à son écran de démarrage.
- [Réinstaller un poste](/admin/installer/reinstaller-un-poste) — repartir d'une
  installation saine depuis l'interface, avec suivi.
- [Vérifier la mise en service](/admin/installer/verifier-la-mise-en-service) —
  les signes qui confirment qu'un poste a rejoint l'établissement.
- [En cas de problème](/admin/installer/en-cas-de-probleme) — un poste qui ne
  démarre pas sur le réseau, une installation qui s'arrête, un poste installé
  mais pas rattaché à l'établissement.

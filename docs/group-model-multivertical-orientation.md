# Orientation future — modèle de groupes multi-vertical (post-ISO SE4)

> **Statut : orientation, NON planifié.** Au 2026-06-24, le cap retenu reste **ISO SE4** :
> on porte le comportement de groupes de SE4 tel quel. Ce document consigne une
> direction long terme (vision « SE6 » multi-vertical école **et** entreprise) issue
> d'une analyse de faisabilité, à reprendre quand le sujet sera priorisé.

## Problème

SE4 crée pour chaque classe **trois** groupes AD indissociables : `Classe_X` (élèves),
`Equipe_X` (profs), `PP_X` (profs principaux). Le préfixe encode à la fois la *nature*
de la collection et le *rôle* du membre. C'est figé sur le vocabulaire scolaire et
duplique chaque entité ×3. Inutilisable tel quel pour un vertical entreprise (où
« classe »/« équipe » n'ont pas de sens).

## Direction proposée

Remplacer le trio par :

- **Un seul groupe logique** (nom libre, ex. « 3eme »), sans préfixe.
- **Rôle porté par l'arête** user↔groupe (colonne `role` sur le pivot `user_group_user`).
  Les rôles sont des **données créées en formulaire** (pas un enum figé), mais leurs
  **effets** mappent un vocabulaire d'accès **borné** : `none | read | read-write | admin`
  (sinon `setfacl`/agent ne peut pas traduire de façon déterministe).
- **ProvisioningProfile** (≈ « type de groupe ») déclarant des **zones** (dossiers) et une
  **matrice (role × zone → niveau)**. Swappable par vertical. `profile = null` ⇒ groupe
  de pur membership, sans disque. Même philosophie *catalogue-first* que le registre de
  capacités.
- **Projection backend** : des role-groups AD dérivés `grp_<groupe>__<role>` peuplés
  depuis les arêtes, que les ACL référencent. La complexité reste cachée côté admin
  (un seul groupe en UI), matérialisée côté AD/Samba (« AD/Samba = projections »).
- **Compat SE4** : seeder le profil « école » + ses rôles (élève/prof/PP) à l'installation.
  Import = `Classe_X`→`member`, `Equipe_X`→`manager`, `PP_X`→`owner`.

## Faisabilité : zéro perte d'accès — VALIDÉE

Croisement de la matrice ACL exhaustive SE4 (`legacy includes/partages.inc.php`) avec
l'implémentation SE5 (`ShareService`/`AclService`). Le modèle reproduit **tous** les
accès SE4 **sans perte**, à condition de câbler 4 mécanismes qui ne sont pas du
`role×zone` pur (3 existent déjà en SE5) :

1. **Zone « par-membre » à ACL nominative** `user:<login>:rwx` (dossier perso élève).
   ⟵ point critique : sans ça, l'élève perd l'accès exclusif à son dossier.
2. **Zone togglable** (`_echange` rwx↔none) — déjà géré (`ShareService::toggleEchange`).
3. **Workflow de mutation** (archivage au changement de classe) — déjà géré
   (`syncUserClassMemberships`).
4. **Hors-modèle, inchangé** : homes `/home/<cn>` (perms unix, pas d'ACL), zones globales
   Docs/Progs, deny Samba `@no_shares`, et comportements pilotés par le **type OU**
   (Eleves/Profs : création de home, droits UI → garder `User.role` global).

> Audit avant toute bascule réelle : SE4 *lit* parfois une ACL `group:Profs` posée **à la
> main** sur `/Classes` (jamais posée par le code). À relever sur les installs concernées.

Détail : le modèle ré-applique les ACL **explicitement par zone** au lieu de dépendre de
l'héritage `default:` — ce qui efface l'asymétrie d'héritage piégeuse de SE4 (le `default`
racine ne propage que `equipe`, jamais `classe`) tout en préservant l'accès.

## Bonus : corrige un bug latent

La pièce neuve (projection des role-groups AD depuis les arêtes) répare un trou actuel :
**SE5 ne peuple jamais `Equipe_<classe>`** (`GroupRepository::createGroup` le crée vide,
aucun code ne le remplit), alors que les ACL prof ciblent `equipe_<x>`. En SE5 greenfield,
le `rwx` prof ne mord donc sur personne. Voir détail ci-dessous.

> ⚠️ **Pertinent même en ISO SE4** : SE4 *peuple* `Equipe_X`. Pour une vraie parité ISO SE4,
> ce gap de peuplement devrait être comblé indépendamment de cette refonte.

## Coût : GROS (3 chantiers à risque élevé)

| Chantier | Ampleur | Risque |
|---|---|---|
| Migration pivot `role` + refonte PK composite + data-migration | Moyen | Élevé |
| Réécriture `ShareService` (6 builders hard-codés → moteur matrice) | Gros | Élevé |
| Projection role-groups AD dans l'observer (code 100 % neuf, écrit dans AD fédéré) | Gros | Élevé |
| Synchro membership AD multi-rôles + read-back `role` depuis le CN | Moyen→Gros | Moyen |
| Tables profile/zone/matrice + CRUD UI + rôle par membre | Gros | Faible |
| ~11 fichiers de tests | Moyen | Faible |

Garde-fous techniques : `AclService::MAX_DEPTH=3` et `validatePath` mono-racine
(`/var/sambaedu/Classes`) plafonnent la liberté des zones, à lever avec précaution.

## Séquençage recommandé (si priorisé — pas de big-bang)

1. **Socle rôle** : colonne `role` sur l'arête + projection role-groups AD, en gardant
   `ShareService` hard-codé. Bénéfice immédiat (corrige `Equipe_X` vide), périmètre maîtrisé.
2. **Généricité** : profil/zones/matrice + réécriture `ShareService` + UI → multi-vertical,
   une fois le socle rôle validé en prod.

## Références code

- Legacy ACL : `sambaedu/includes/partages.inc.php` (cœur), `admin_ui.inc.php` (zones
  globales), `samba.inc.php` (deny `@no_shares`), `ldap.inc.php:5580+` (création groupes).
- SE5 : `app/Services/Filesystem/ShareService.php`, `AclService.php`,
  `app/Models/Pivot/UserGroupUserPivot.php`, `app/Observers/UserGroupUserPivotObserver.php`,
  `app/Repositories/GroupRepository.php:438-556`, `app/Services/UserGroupService.php`,
  `app/Policies/UserPolicy.php:239-271`.

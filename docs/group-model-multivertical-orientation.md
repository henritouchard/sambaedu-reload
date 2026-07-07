# Modèle de groupes multi-vertical — orientation retenue

> **Statut : DÉCIDÉ (2026-07-07), en attente de cadrage epic.** Le cap ISO SE4 posé le
> 2026-06-24 est levé : le modèle générique décrit ici est retenu, avec un séquençage
> arbitré — **phase 1 : projection sur le stockage SMB/POSIX existant** (moteur de
> matrice `setfacl` + role-groups AD), **phase 2 : Nextcloud branché par-dessus**
> (d'abord en simple vue external-storage ; un driver Nextcloud complet — Team folders
> pilotés via l'API OCS — reste l'option future, documentée en fin de document).
> Invariant d'architecture dès le premier jour : le compilateur est backend-neutre,
> la matérialisation passe par une interface `StorageDriver`, et le backend est porté
> par le groupe (un groupe = un backend, jamais deux autorités d'écriture sur une
> même zone).

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
- **Quatre natures de zones** (elles couvrent tout SE4 — vérifié à la fois contre la
  matrice ACL `partages.inc.php` et contre le module cloud legacy `cloud.inc.php`, qui
  reconstruisait la même organisation sur Nextcloud) :
  - `partagée` : ligne de matrice classique (ex. racine, `_Travail`) ;
  - `par-membre` : un sous-dossier par arête, ACL **nominative** `user:<login>` pour le
    membre lui-même ; la matrice ne règle que ce que les *autres* rôles en voient.
    Porte l'option de cycle de vie « archiver au départ du membre » ;
  - `activable` : matrice classique + toggle on/off par groupe (ex. `_Echange`) ;
  - `workflow` : dossier réservé dont les droits appartiennent à une fonctionnalité
    applicative (ex. `Devoirs`) — ligne de matrice inerte.
- **Projection backend (phase 1 = SMB/POSIX)** : des role-groups AD dérivés
  `grp_<groupe>__<role>` peuplés depuis les arêtes, que les ACL `setfacl` référencent
  pour les zones partagées. La complexité reste cachée côté admin (un seul groupe en
  UI), matérialisée côté AD/Samba (« AD/Samba = projections »). Le nominatif est
  réservé aux zones par-membre : les ACL POSIX ont une limite physique d'entrées
  (bloc xattr) — un groupe de 300 membres en nominatif ne passe pas.
- **Dégradation de vocabulaire assumée en POSIX** : le niveau `admin` s'aplatit en
  `read-write` (POSIX ne connaît que rwx) ; l'UI l'affiche plutôt que de le cacher.
- **Réconciliation, pas application directe** : les opérations lourdes (`setfacl -R`
  sur un arbre, ajout d'une zone à un profil utilisé par N groupes) tournent en jobs
  avec état visible (statut de synchronisation / drift par groupe, comme le reporting
  de convergence de l'agent).
- **Ligne rouge : pas d'éditeur d'ACL brut.** Toute ACL posée doit être dérivable du
  modèle (condition de la réconciliation, de la détection de drift et de la
  reconstruction depuis un dump SQL). Modifier les droits = modifier la matrice.
  Pour les ACL hors-modèle rencontrées à l'import (custom SE4) : adopter dans le
  modèle ou archiver, jamais coexister.
- **Compat SE4** : seeder le profil « école » + ses rôles (élève/prof/PP) à l'installation.
  Import = `Classe_X`→`member`, `Equipe_X`→`manager`, `PP_X`→`owner`. Les Matières et
  la Direction cessent d'être des cas particuliers : ce sont de simples groupes avec
  leur profil (« matière » : élèves `member`, prof `manager` ; « direction » : partages
  par-membre).

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

## Séquençage retenu (pas de big-bang)

1. **Socle rôle** : colonne `role` sur l'arête + projection role-groups AD, en gardant
   `ShareService` hard-codé. Bénéfice immédiat (corrige `Equipe_X` vide), périmètre maîtrisé.
2. **Généricité** : tables profil/zones/matrice + compilateur backend-neutre +
   interface `StorageDriver` + driver SMB/POSIX (réécriture `ShareService` en moteur
   de matrice) + UI d'admin → multi-vertical, une fois le socle rôle validé.
3. **Nextcloud en vue** : instance Nextcloud optionnelle en *external storage SMB*
   (accès web/hors-LAN ; POSIX reste l'unique autorité d'écriture, zéro migration).
4. **(option, non planifié)** Driver Nextcloud complet — voir section dédiée.

## UI d'admin (identique quel que soit le backend)

Deux surfaces, conformément à la règle « catalogue en settings, assignation sur la
page du groupe » :

- **Catalogue « Profils de groupes »** (settings) : liste des profils (nom, rôles,
  zones, nombre de groupes utilisateurs), actions créer/dupliquer/éditer/archiver.
  Les profils seedés sont des données normales ; « dupliquer » est le chemin
  d'adaptation naturel.
- **Éditeur de profil** : rôles (données libres, avec rôle par défaut) + zones
  (nom, nature) + **matrice** zone × rôle à vocabulaire fermé
  (`— / lecture / écriture / admin`), et un **aperçu simulé** recalculé à la volée
  (arbre de dossiers d'un groupe fictif avec un personnage par rôle) — c'est
  l'aperçu qui rend la matrice lisible et qui porte les validations en contexte
  (rôle sans accès, zone sans rédacteur…). Enregistrer un profil en usage passe par
  une confirmation explicitant la resynchronisation (« s'appliquera à N groupes »).
- **Pages groupes** : select « Profil » (défaut : aucun = membership seul), colonne
  rôle sur la liste des membres, toggles des zones activables. Changer le profil d'un
  groupe = confirmation avec diff (zones créées / archivées).

## Option future : driver Nextcloud (Team folders via OCS)

Scénario étudié et écarté comme *première* étape (décision 2026-07-07), conservé
comme option : Nextcloud en stockage primaire, où la projection devient des groupes
Nextcloud internes dérivés des arêtes + un Team folder par groupe avec la matrice en
ACL par sous-dossier, le tout piloté par l'API OCS — l'AD étant réduit à l'identité
(`user_ldap`, puis OIDC/Keycloak). Avantages : remplace les deux chantiers à risque
(role-groups AD fédéré, moteur `setfacl`) par des appels REST ; supprime le besoin de
groupes utilisateurs AD ; cohérent avec la sortie d'AD long terme. Raisons du report :
Nextcloud s'*ajoute* à Samba sans le remplacer (dette d'exploitation pour des
établissements sans admin dédié), dépendance structurelle à l'app Team folders (hors
du cœur Nextcloud), profil de charge scolaire (burst synchrone) favorable à SMB,
sémantique fichiers (verrous Office) dégradée en WebDAV, et gain hors-LAN partiellement
couvert par nuage.apps.education.fr. Le précédent legacy (`sambaedu/includes/cloud.inc.php`,
~3 900 lignes : arborescence de classes reconstruite sur un NC d'étab via shares OCS,
montage postes par `rclone mount`, migration `rclone copy`) prouve la faisabilité du
pilotage OCS et sert de référence fonctionnelle.

## Références code

- Legacy ACL : `sambaedu/includes/partages.inc.php` (cœur), `admin_ui.inc.php` (zones
  globales), `samba.inc.php` (deny `@no_shares`), `ldap.inc.php:5580+` (création groupes).
- Legacy cloud (référence fonctionnelle de l'option NC) : `sambaedu/includes/cloud.inc.php`
  (`list_partages_groups` = état désiré, `diff_shares`/`update_nc_partages` = réconciliation,
  `cloud_mount` = montage rclone), `partages/rep_cloud.php` + `rep_cloud_cron.php`
  (déclencheurs), `partages/cloud_out.php` (script logon). Côté SE5, déjà porté :
  `UserService::configureUserCloud()` (app password OCS + config rclone, gate `no_cloud`).
- SE5 : `app/Services/Filesystem/ShareService.php`, `AclService.php`,
  `app/Models/Pivot/UserGroupUserPivot.php`, `app/Observers/UserGroupUserPivotObserver.php`,
  `app/Repositories/GroupRepository.php:438-556`, `app/Services/UserGroupService.php`,
  `app/Policies/UserPolicy.php:239-271`.

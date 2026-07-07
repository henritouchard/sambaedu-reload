---
stepsCompleted: [step-01, step-02, step-03, step-04]
inputDocuments:
  - docs/group-model-multivertical-orientation.md
  - _bmad-output/implementation-artifacts/4-12-peuplement-equipe-x-par-role.md
---
# Socle rôle sur l'arête user↔groupe - Epic Breakdown (Epic 42)

## Overview

Phase 1 (étape « socle rôle ») du modèle de groupes multi-vertical — **décision Henri
2026-07-07** (`docs/group-model-multivertical-orientation.md`, statut DÉCIDÉ) : porter le
**rôle sur l'arête** user↔groupe (colonne `role` sur le pivot `user_group_user`) et
projeter les memberships AD **depuis les arêtes**, en gardant `ShareService` hard-codé
et le nommage AD legacy (`Classe_X`/`Equipe_X`/`PP_X`).

Bénéfices immédiats : la source de vérité du rôle devient l'arête (fondation de la
généricité profils/zones/matrice, epic ultérieur) ; le peuplement `Equipe_X` cesse de
dépendre de la partition `User::isProf()` posée par la quick-spec 4.12 (status review),
et `PP_X` — non couvrable par un rôle global — devient enfin peuplable.

**Hors périmètre** (phases suivantes du séquençage retenu) : tables profils/zones/matrice,
UI catalogue de profils, moteur de matrice `setfacl`, interface `StorageDriver`,
role-groups `grp_<groupe>__<role>`, Nextcloud.

## Requirements Inventory

### Functional Requirements

- **FR-S1** — Toute arête user↔groupe (pivot `user_group_user`) porte un **rôle** ;
  vocabulaire seedé pour l'école : `member` (élève), `manager` (prof), `owner`
  (prof principal) — stocké en données (pas d'enum SQL figé), vocabulaire borné côté
  applicatif.
- **FR-S2** — **Backfill** des arêtes existantes : `manager` si `User::isProf()`,
  `owner` si `is_head_teacher` (attribut d'arête 4.14, **absorbé** par `role` —
  source unique après migration), `member` sinon.
- **FR-S3** — La **projection AD** des memberships est dérivée des arêtes :
  `member`→`Classe_X`, `manager`→`Equipe_X`, `owner`→`PP_X` — **nommage legacy
  conservé tel quel au socle** (le nommage `grp_<groupe>__<role>` du doc d'orientation
  est réservé à la phase généricité et n'existe nulle part dans l'AD aujourd'hui).
  Diff idempotent (pattern `syncAdGroupMembersByUserIds`). Remplace la partition
  `isProf()` introduite par la 4.12.
- **FR-S4** — Le rôle est **visible et éditable** sur la page du groupe (colonne rôle
  sur la liste des membres) ; défaut au rattachement dérivé de `User.role` global ;
  toute édition resynchronise la projection AD.
- **FR-S5** — L'import/migration (`sync-from-ad`) fait le **read-back des rôles**
  depuis les groupes AD legacy (`Classe_`/`Equipe_`/`PP_`), résolution par `ad_guid`.
- **FR-S6** — `ShareService`/`AclService` **inchangés** (les ACL ciblent toujours
  `classe_<x>`/`equipe_<x>`) — effet net : le `rwx` prof devient effectif, y compris
  greenfield.

### NonFunctional Requirements

- **NFR-S1** — Écritures AD **idempotentes et fail-soft** ; AD fédéré partagé
  (75 étab) : jamais la racine, opérations membership par GUID
  (cf. `project_ad_sync_resolve_by_guid`, `project_ad_federated_root_gpos`).
- **NFR-S2** — **Zéro régression iso-SE4** : mêmes groupes AD, mêmes ACL posées,
  brownfield (AD pré-peuplé par SE4) ET greenfield.
- **NFR-S3** — `User.role` **global conservé** (comportements type OU : création de
  home, droits UI — cf. orientation §Faisabilité pt 4) ; le rôle d'arête ne le
  remplace pas.
- **NFR-S4** — Tests exécutables sur l'hôte (SQLite : pas d'enforcement varchar →
  validations applicatives ; gardes suite `withoutVite`/reguard).

### Additional Requirements

- **Nommage AD legacy vérifié sur lab1 étab (2026-07-07, AD fédéré réel)** :
  - CN à casse préservée `Classe_<Nom>` / `Equipe_<Nom>` / `PP_<Nom>` ; mono-étab :
    `OU=classes|equipes,OU=Groups` ; fédéré : **OU par UAI**
    (`CN=Classe_3CK,OU=classes,OU=0991229y,OU=Groups`).
  - Le **suffixe étab est porté par le sAMAccountName** (`classe_3ck-1229y`), PAS par
    le CN — c'est l'explication concrète du bug « ShareService omet le suffixe »
    (`project_acl_equipe_group_missing_etab_suffix`) : les ACL doivent cibler le
    sAMAccountName suffixé. Matching par sAMAccountName/GUID, jamais par CN.
  - Volumétrie réelle : 58 `classe_`, **606 `equipe_`** (SE4 crée aussi des `Equipe_`
    par cours/sous-groupe : `equipe_301_esp`, `equipe_301 g1` — espaces dans les noms
    inclus), **4 `pp_`** seulement (dont déchets `pp_profs`, `pp_legaco`) → PP marginal
    en production ; les `Equipe_` du lab sont bien peuplées (SE4 peuple, SE5 non).
- Pivot : piège Laravel `withPivot` — sans déclaration, `sync([$id => ['role' => …]])`
  ignore l'attribut d'arête (précédent story 4.14 sur `is_head_teacher`).
- Point d'ancrage projection : `UserGroupUserPivotObserver` existant.
- **Compat noms PG (vérifié code 2026-07-08)** : depuis la 4.13 les classes/équipes
  sont **foldées au nom nu en SQL** (`name="3A"`, préfixe uniquement en projection
  AD via `resolvePrimaryGroupName`/`stripClasseLikePrefix`, strip défensif des vieux
  CN) ; les autres types restent préfixés (`Cours_X`, `Matiere_X@Y`, garde double
  préfixe 4.16). **L'epic ne modifie PAS cette mécanique de noms** — il ne remplace
  que les heuristiques de routage. Résolution nom résolu vs nom brut dans
  `updateGroup` (piège corrigé par la 4.12 — ne pas le réintroduire).
- **État actuel du routage AD** (`UserGroupService::syncRoleAwareAdGroupMembers`) =
  DEUX heuristiques à remplacer par le rôle d'arête : partition `User::isProf()`
  (4.12) → `Equipe_`/`Classe_`, et flag d'arête `is_head_teacher` (4.15) → `PP_`
  (cible orthogonale : un PP reste dans `Equipe_` ET est ajouté à `PP_` — sémantique
  à conserver avec `owner`).
- Transition 4.12/4.15 : ces mécanismes restent le comportement de référence tant
  que le backfill n'est pas joué ; la bascule arête-source doit être atomique
  (pas d'état où ni les heuristiques ni les arêtes ne peuplent).
- Import AD : boucles de fold sensibles au piège savepoint 25P02
  (`project_vm_ad_junk_classe_groups`).
- Migrations VM non auto-jouées (`project_vm_migrations_not_auto_applied`) :
  `migrate:status` avant tout e2e.

### UX Design Requirements

- **UX-S1** — Colonne « Rôle » (select au vocabulaire du seed) sur la liste des membres
  des pages groupes ; conventions maison : label au-dessus, pas de hints décoratifs
  (`feedback_form_label_above_input_tooltip_hints`) ; propriété par-groupe → pages
  groupes, pas dans les settings (`feedback_per_group_property_belongs_on_group_pages`).

### FR Coverage Map

- FR-S1 : Epic 42 — modèle : colonne `role` sur le pivot + seed du vocabulaire
- FR-S2 : Epic 42 — data-migration backfill (absorbe `is_head_teacher`)
- FR-S3 : Epic 42 — projection AD depuis les arêtes (nommage legacy)
- FR-S4 : Epic 42 — UI rôle sur pages groupes + resync à l'édition
- FR-S5 : Epic 42 — read-back des rôles au sync-from-ad
- FR-S6 : Epic 42 — invariant transverse (ShareService intact), vérifié par tests de parité
- NFR-S1..S4 : transverses, ancrées dans chaque story concernée

## Epic List

### Epic 42: Socle rôle sur l'arête user↔groupe (projection AD depuis les arêtes)

Un admin SE5 voit et gère **qui joue quel rôle dans chaque groupe** (élève / prof /
prof principal) directement sur la page du groupe, et l'AD reflète fidèlement ces
rôles : les groupes legacy `Classe_X`/`Equipe_X`/`PP_X` sont peuplés depuis les arêtes
— le `rwx` prof sur les dossiers de classe devient effectif (y compris greenfield),
`PP_X` devient peuplable, et la source de vérité du rôle passe de l'heuristique
globale (`User.role`) à la relation elle-même, fondation de la généricité
profils/zones/matrice (epic ultérieur).

**FRs covered:** FR-S1..FR-S6, NFR-S1..NFR-S4

## Epic 42: Socle rôle sur l'arête user↔groupe

Chaque story reste autonome et déployable ; ordre : **42.1 → 42.2**, puis **42.3** et
**42.4** (indépendantes entre elles). L'invariant FR-S6 (`ShareService`/`AclService`
intacts, zéro régression iso-SE4) est vérifié par des tests de parité dans chaque story
qui touche la projection.

---

### Story 42.1: Colonne `role` sur l'arête + backfill (absorption `is_head_teacher`)

**Intention.** Porter le rôle sur le pivot `user_group_user` : migration additive
`role` (string, vocabulaire applicatif borné `member|manager|owner`, pas d'enum SQL),
`withPivot('role')` sur les relations, backfill des arêtes existantes, absorption de
l'attribut d'arête `is_head_teacher` (4.14) dont `role` devient la source unique.
Aucune écriture AD dans cette story.

**AC-skeleton (à figer au create-story) :**
- Migration additive `user_group_user.role` (default `member`, index) + garde
  `hasColumn` ; down propre.
- Backfill data-migration : `manager` si `User::isProf()`, `owner` si
  `is_head_teacher`, `member` sinon — idempotent, rejouable.
- `withPivot('role')` déclaré partout où l'arête est synchronisée (piège 4.14 :
  sans lui, `sync()` ignore l'attribut) ; cast/validation applicative du vocabulaire
  (SQLite ne borne pas les varchar — NFR-S4).
- Les consommateurs de `is_head_teacher` (UserPolicy, UI 4.14) lisent désormais
  `role === 'owner'` — SAUF la projection AD 4.15 (`syncRoleAwareAdGroupMembers`),
  qui ne bascule qu'en 42.2 : la colonne `is_head_teacher` reste donc **écrite en
  miroir** (cohérente avec `role`) jusqu'à la bascule ; sa suppression est une
  **tâche de 42.2** (jamais de fenêtre où la projection lit une colonne morte).
- Défaut au rattachement : toute création d'arête sans rôle explicite dérive de
  `User.role` global (prof→`manager`, sinon `member`).
- `isProf`/`isEleve` : sur les listes, lire la colonne SQL, pas de round-trip LDAP
  par user (`project_isprof_iseleve_ldap_first_cost`).

**Dépendances.** Amont : pivot global memberships (4.11), attribut 4.14. Bloquant
pour 42.2/42.3/42.4. **Reco dev** : sonnet (migration + backfill cadrés) — à
confirmer au create-story.

---

### Story 42.2: Projection AD des memberships depuis les arêtes (remplace la partition 4.12)

**Intention.** La synchro AD des groupes classe/equipe route les membres **par rôle
d'arête** : `member`→`Classe_X`, `manager`→`Equipe_X`, `owner`→`PP_X` (nommage legacy
strict — le générique `grp__` attendra la phase profils). Remplace les DEUX
heuristiques actuelles de `syncRoleAwareAdGroupMembers` : la partition
`User::isProf()` (4.12) ET le flag `is_head_teacher`→`PP_` (4.15) — bascule atomique,
pas d'état où ni les heuristiques ni les arêtes ne peuplent. La mécanique de noms
(SQL nu ↔ CN préfixé, `resolvePrimaryGroupName`/`stripClasseLikePrefix`) est
**intacte**. Corrige définitivement `Equipe_X` vide et rend `PP_X` peuplable.

**AC-skeleton (à figer au create-story) :**
- `syncRoleAwareAdGroupMembers` résout les cibles depuis les arêtes+rôles (plus
  d'appel `isProf()` ni de lecture `is_head_teacher`) ; réutilise le diff idempotent
  `syncAdGroupMembersByUserIds` ; sémantique PP conservée : `owner` reste projeté
  dans `Equipe_` ET ajouté à `PP_` (cible orthogonale, vidage sans rémanence — 4.15).
- Changement d'arête (attach/detach/changement de rôle) → resync du groupe concerné
  (ancrage `UserGroupUserPivotObserver`) ; écritures AD idempotentes, fail-soft
  (NFR-S1), résolution par GUID/sAMAccountName, **jamais par CN** (suffixe étab dans
  le sAMAccountName — vérifié lab1).
- `PP_X` peuplé depuis `owner` ; absence de groupe `PP_X` en AD tolérée (fail-soft,
  volumétrie réelle : PP marginal).
- Tests de parité FR-S6/NFR-S2 : mêmes groupes AD ciblés qu'en SE4, `ShareService`
  non modifié, greenfield ET brownfield (AD pré-peuplé) ; nom résolu vs nom brut
  (piège corrigé par 4.12, non réintroduit).
- **Tâche** : sort du helper 4.12 (suppression vs délégation) documenté dans la story.

**Dépendances.** Amont : 42.1, quick-spec 4.12 (review — à statuer avant dev).
Bloquant pour 42.3 (resync à l'édition) et 42.4. **Reco dev** : fable/opus (écriture
AD fédéré, transition sensible) — à confirmer au create-story.

---

### Story 42.3: UI — rôle visible et éditable sur la page du groupe

**Intention.** L'admin voit et modifie le rôle de chaque membre depuis la page du
groupe : colonne « Rôle » sur la liste des membres (select au vocabulaire seedé),
édition → resync AD (42.2). Propriété par-groupe → pages groupes
(`feedback_per_group_property_belongs_on_group_pages`).

**AC-skeleton (à figer au create-story) :**
- Colonne « Rôle » sur la liste des membres (pages groupes, Livewire SFC existant) ;
  libellés FR affichés (Élève/Prof/Prof principal), valeurs techniques masquées.
- Édition du rôle → écriture pivot + resync AD du groupe ; toasts `WithToasts` ;
  conventions formulaires maison (labels au-dessus, pas de hints décoratifs).
- Le rattachement d'un membre propose le rôle par défaut dérivé (42.1) et permet de
  le surcharger.
- Gardes d'autorisation : mêmes policies que l'édition de groupe ; pas d'action
  Livewire nommée `upload` (piège réservé).

**Dépendances.** Amont : 42.1 (colonne), 42.2 (resync). Indépendante de 42.4.
**Reco dev** : sonnet (UI Livewire cadrée) — à confirmer au create-story.

---

### Story 42.4: Read-back des rôles au sync-from-ad (import legacy)

**Intention.** L'import AD→SQL (migration depuis SE4, transitoire) reconstruit les
**arêtes avec rôle** depuis les groupes legacy : membre de `Equipe_X`→`manager`,
`PP_X`→`owner`, `Classe_X`→`member`, résolution par `ad_guid`/sAMAccountName.

**AC-skeleton (à figer au create-story) :**
- Précédence à l'import quand un user apparaît dans plusieurs groupes du trio d'une
  même classe : `owner` > `manager` > `member` (une seule arête par user×groupe).
- Suffixes étab (`-<uai>`) et OU par UAI gérés (fold vers la ligne SQL nue,
  `project_usergroup_sql_fold_bare_name`) ; groupes déchets tolérés sans casser la
  boucle (savepoint 25P02, `project_vm_ad_junk_classe_groups`).
- Volumétrie réelle supportée : ~600 `equipe_` dont sous-groupes/cours avec espaces
  dans les noms (lab1) ; les `Equipe_` de cours sans `Classe_` jumelle ne créent pas
  d'arête orpheline — **tâche** : définir le comportement (ignorer vs groupe
  membership-only).
- Import idempotent (re-run = no-op) ; rôles existants en SQL non écrasés sans
  changement AD.

**Dépendances.** Amont : 42.1 (colonne), 42.2 (cohérence projection). Indépendante
de 42.3. **Reco dev** : opus (heuristiques d'import, données sales) — à confirmer
au create-story.

# Story 34.1 : Fondations des lecteurs réseau gérés (modèle, provisioning FS/ACL, projection agent)

Status: review

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

En tant qu'**administrateur d'établissement (refnum)**,
je veux **un modèle de données + un service de provisioning + une projection agent pour des répertoires réseau nommés, assignables à des utilisateurs / groupes d'utilisateurs / parcs (WorkstationGroup) avec un accès lecture seule ou lecture-écriture**,
afin que **la fondation backend du module « lecteurs réseau gérés » existe (sans UI ni templates pour l'instant), réutilise le canal `drives` natif de l'agent, et serve de socle aux stories suivantes (UI 34.2, templates 34.3, validation prédictive 34.x)**.

## Contexte & intention

**PREMIÈRE story d'un NOUVEL Epic 34 « Lecteurs réseau gérés ».** Cet epic n'a PAS de narratif dans `epics.md` — comme les Epics 28-33, il vit dans `backlog.data.js` + ses fichiers de story. Cette story est cadrée **fondation backend d'abord** (décision Henri, validation 2026-06-29) : pas d'UI, pas de templates. L'UI admin (création/assignation) et les templates de répertoire (échanges direction / profs / élèves / user↔user / groupes) sont des stories ULTÉRIEURES qui s'appuient sur ce socle.

**Ce que cette story livre :**
- **Persistance** : table `network_shares` (le répertoire nommé) + pivot **polymorphe** `network_share_assignables` (cible `User | UserGroup | WorkstationGroup`, porte `access = ro|rw`). C'est le chaînon manquant DÉLIBÉRÉMENT reporté en 27.2 (le « MVP-B » écarté à l'époque — table + pivot d'assignation configurable).
- **Provisioning serveur** : `NetworkShareService` (nouveau, générique — NE PAS surcharger `ShareService` qui est spécifique aux classes) qui crée le répertoire sous une racine dédiée `/var/sambaedu/Partages/<directory_name>` et applique les ACLs POSIX dérivées des assignations `User`/`UserGroup` (RO=`:rx`, RW=`:rwx`), idempotent et fail-soft, audit via `quota_audit_logs` (`target_type='share'`).
- **Projection agent** : extension de `DrivesStateProvider` (type `drives` DÉJÀ figé contrat §7, NFR12) pour émettre, en plus du jeu fixe K:/H:, les répertoires `network_shares` résolus par maille via `TargetContext`. Payload v1 INCHANGÉ `{letter, unc, label}` — l'agent Go monte déjà n'importe quelle lettre→UNC sans modification (handler `handler_drives.go` réutilisé tel quel).

**Modèle d'accès — TRANCHÉ (décision Henri 2026-06-29, « flexibilité maximale »).** Deux résolutions distinctes sur LE MÊME jeu d'assignations polymorphes :
- **Visibilité (montage)** : N'IMPORTE QUELLE maille (`User`, `UserGroup`, `WorkstationGroup`) fait apparaître la lettre pour les sessions correspondantes. L'union / dédup / précédence du `StateCompiler` existant gère tout — ZÉRO modif du compilateur.
- **ACL POSIX (accès réel RO/RW)** : dérivée des assignations `User`/`UserGroup` UNIQUEMENT (`user:<login>` / `group:<unix_group>` à `rx`/`rwx`). Une assignation `WorkstationGroup` est **montage-seul** : la lettre s'affiche sur les postes du parc, mais l'accès réel vient des grants user/group (POSIX ne sait pas exprimer « les utilisateurs de la machine X »). Documenté comme invariant.

**Pourquoi natif & pourquoi maintenant.** L'agent est désormais l'autorité sur les lecteurs réseau (`DrivesStateProvider`, commit `1ab53ed` « lecteurs réseau natifs K: home + H: classes », et mémoire `native_drive_management_direction` 2026-06-29) — successeur de la GPO/AD `homeDrive`/`homeDirectory`. Ce module ajoute la couche CONFIGURABLE par-dessus la projection fixe : l'admin pourra (stories suivantes) créer des répertoires d'échange et les diffuser à la bonne audience sur les bons postes.

**Ce que cette story N'EST PAS :**
- **Pas d'UI** : aucune page/SFC Livewire. La création/assignation se fait par migration + factory + `php artisan tinker` (ou seeder de test). UI = Story 34.2.
- **Pas de templates** : aucune notion de « échanges direction/profs/élèves/user↔user/groupes ». Les templates sont des recettes d'ACL préconfigurées = Story 34.3. NE PAS les implémenter ici.
- **Pas de gestion `smb.conf` / `net usershare`** : l'export SMB `[partages]` → `/var/sambaedu/Partages` est une tâche d'INFRA serveur (hors git, comme `[users]`/`[classes]`). Voir §[PROD]. SE5 gère uniquement les répertoires + ACLs POSIX.
- **Pas de modification de l'agent Go** : le handler `drives` monte déjà toute lettre→UNC. Aucun changement `agent/**`, donc **pas de bump de version agent** (mémoire `agent_edit_bump_version` — ne s'applique QUE si on touche `agent/**`).
- **Pas de toucher au canal legacy** lecteurs/partages (`partages.inc.php`, module legacy `partages` superseded, `dossier_echange`). ZÉRO retrofit legacy.
- **Pas de modification de `ShareService`/`PrintersStateProvider`** : on LIT le pattern, on ne le modifie pas. `ShareService` reste la source FS des partages de CLASSE.

## ⚠️ Pièges & tensions découverts à l'analyse (lire AVANT de coder)

1. **`AclService` est verrouillé sur `/var/sambaedu/Classes`** (`$classesRoot`, `validatePath()` refuse tout path hors racine, `MAX_DEPTH = 3`). Pour provisionner sous `/var/sambaedu/Partages`, il faut **généraliser le service de validation de path** SANS affaiblir les gardes (triple garde : regex anti-traversal + `escapeshellarg` + whitelist sudo). **NE PAS** détendre `AclService::validatePath` au point d'autoriser n'importe quel chemin. Approche recommandée : `NetworkShareService` porte sa propre racine `$sharesRoot = '/var/sambaedu/Partages'` + sa propre validation calquée 1:1 sur `AclService::validatePath` (ou un `AclService::validatePathUnder($path, $root)` paramétrable réutilisé par les deux). Tranché en décision n° 3 ci-dessous.

2. **Le golden d'état est figé** (mémoire `native_drive_management_direction`) : modifier `DrivesStateProvider` re-touche potentiellement `tests/Fixtures/Agent/state.v1.json` + `ContractV1Test::FROZEN_STATE_HASH` (PHP) + `agent/shared/hasher_test.go::frozenStateHash` (Go). **MAIS** : avec ZÉRO ligne `network_shares` dans le contexte du fixture canonique, la sortie du provider est **byte-identique** (toujours K:/H:). → **Aucun bump de golden requis** si le fixture `ContractV1Test` n'a pas de répertoire configuré (vérifier que le contexte du fixture est « vierge » de network_shares). Ajouter un test dédié qui ASSERTE l'identité de sortie quand aucune ligne n'existe (non-régression du hash figé). Si le fixture devait inclure un répertoire configuré, alors bump SCIEMMENT (recalcul via `StateHasher::hashState/hashItem`, méthode vérifiée en tinker).

3. **Collision de lettre entre deux répertoires DIFFÉRENTS = non gérée ici (volontaire).** Le type `drives` est `aggregate` : la dédup du compilateur collapse les payloads `{letter, unc, label}` IDENTIQUES. Or un même `network_share` atteint via plusieurs mailles (ex. user ∈ userGroup assigné ET poste ∈ WG assigné) produit le **même** payload → dédupliqué naturellement, AUCUN doublon. Le SEUL cas non couvert est **deux répertoires distincts portant la même lettre** : c'est une **erreur d'authoring** qui n'a pas de surface de création en 34.1 (pas d'UI). Elle sera bloquée par la **validation prédictive** d'une story UI ultérieure (calquée sur la validation prédictive 30.5 / `associations_native_vs_wpkg_predictive_validation`). NE PAS sur-concevoir une exclusivité-par-lettre côté compilateur ici (la spécificité par maille ne doit JAMAIS fuiter dans le provider — D2, cf. `StateMaille` docblock). Documenter l'invariant, ne pas l'implémenter.

4. **Lettres réservées** : `K:` (home) et `H:` (classes) sont émis en dur par `DrivesStateProvider` ; `I:` (Docs) et `L:` (Progs) sont réservées par convention legacy (`individuel.php`, docblock `DrivesStateProvider`). `C:`/`D:` = disques locaux, `A:`/`B:` = legacy floppy. → L'attribution de lettre d'un `network_share` doit **exclure** au minimum `A,B,C,D,H,I,K,L`. Pool sûr conseillé : `M..Z`. Voir décision n° 4.

5. **Ciblage par maille ≠ par CN AD.** Le provider résout via `TargetContext` (`$ctx->user?->id`, `$ctx->userGroupIds`, `$ctx->physicalGroupIds`, `$ctx->logicalGroupIds`) — **Postgres only**, JAMAIS `LdapRecord`/APCu/`ad_*` (NFR7, critère Keycloak, grep en review). Les assignations sont des FK SQL (User/UserGroup/WorkstationGroup), pas des CN. Cohérent avec `TargetContext` (« hydratation exclusivement Postgres »). Contrairement à `shortcut_assignables` qui mixe pivot polymorphe (WG/Workstation) + colonnes JSON `ad_users`/`ad_user_groups` (CN AD), on fait **tout en pivot polymorphe SQL** (User et UserGroup sont des modèles SQL réels) — plus simple, SQL-first, zéro AD.

6. **`access = ro|rw` est un champ MÉTIER (ACL), PAS un champ de payload `drives`.** Le payload agent reste `{letter, unc, label}` — l'agent monte, c'est l'ACL POSIX côté serveur qui gouverne RO/RW (Windows/Samba honore l'ACL POSIX). NE PAS ajouter de flag d'accès au payload (sinon bump golden inutile + l'agent n'en ferait rien). `access` vit sur le pivot et pilote `:rx` vs `:rwx` dans `NetworkShareService`.

7. **`scope = session`** (comme les drives existants) : un montage réseau vit DANS la session user (lettre par-user). Le handler `drives` tourne dans le compagnon de session. Émis indépendamment du `WorkstationEnvironment` (décision 27.2 n° 6 « émis partout », réutilisée). NE PAS consommer le resolver nomade 26.1.

8. **Idempotence & fail-soft** : calquer `ShareService`/`AclService` — `Process::run` encapsulé, `Log::error` préfixé, retour `bool`, `setfacl -b` (wipe) avant le batch pour idempotence, aucun exception propagée. `Process::fake()` en tests (NE PAS toucher le FS réel en test).

## Décisions de design — TRANCHÉES PAR HENRI (2026-06-29) — DÉFINITIVES pour 34.1

> Le dev applique ces décisions telles quelles, **ne les re-tranche pas**.

1. **Périmètre = fondation backend.** Migrations + modèles + `NetworkShareService` + extension `DrivesStateProvider` + tests + doc. **AUCUNE UI, AUCUN template.**
2. **Modèle d'accès = deux axes orthogonaux, flexibilité maximale** (cf. §Contexte). Pivot polymorphe `User|UserGroup|WorkstationGroup` + `access=ro|rw`. Visibilité = toute maille ; ACL = grants user/group ; WG = montage-seul.
3. **Racine FS dédiée = `/var/sambaedu/Partages`**, configurable via `config('filesystem.shares_root')` (fallback static, comme `classes_root`). Validation de path durcie réutilisée/paramétrée depuis `AclService` (triple garde préservée). `MAX_DEPTH` adaptée (répertoire nommé = 1 niveau sous la racine ; prévoir 2 pour de futurs sous-dossiers de template).
4. **Lettre de lecteur** : colonne `letter` sur `network_shares` (string, ex. `'P:'`), **nullable**. Si `null`, le provider **auto-assigne** déterministiquement la première lettre libre du pool `M..Z` (exclut `A,B,C,D,H,I,K,L` + toute lettre déjà émise par le provider dans le même set). Auto-assignation = pur calcul provider, déterministe (tri par `network_shares.id` asc).
5. **Export SMB = export parent unique** `\\<se4fs>\partages\<directory_name>`. Un seul partage Samba `[partages]` → `/var/sambaedu/Partages` (infra, §[PROD]). Chaque répertoire = sous-dossier. UNC émis : `\\<se4fs>\partages\<directory_name>\` (token `<se4fs>` substitué localement par l'agent, iso K:/H:).

## Acceptance Criteria

### AC1 — Persistance : table `network_shares` + pivot polymorphe `network_share_assignables` (FR fondation)

**Given** le besoin de répertoires nommés assignables à plusieurs mailles avec un accès RO/RW
**When** les migrations sont jouées
**Then** une table `network_shares` existe avec au moins : `id`, `name` (libellé affiché), `directory_name` (segment FS sûr, unique, validé `^[A-Za-z0-9_-][A-Za-z0-9_.-]*$`), `label` (libellé du lecteur dans l'explorateur, nullable → défaut = `name`), `letter` (nullable, ex. `'P:'`), `created_by_user_id` (FK nullable), `timestamps`
**And** une table pivot polymorphe `network_share_assignables` existe avec : `id`, `network_share_id` (FK cascade), `assignable_id` + `assignable_type` (`morphs('assignable')`), `access` (enum/string `ro|rw`, défaut `ro`), `timestamps`, contrainte `unique(network_share_id, assignable_id, assignable_type)`
**And** les types polymorphes autorisés sont `User`, `UserGroup`, `WorkstationGroup` (validés applicativement, calqué sur `shortcut_assignables`)
**And** les migrations passent sur SQLite (tests hôte) ET Postgres (`/vm`), `down()` réversible.

### AC2 — Modèles Eloquent + relations (FR fondation)

**Given** les tables créées
**When** on manipule les modèles
**Then** `App\Models\NetworkShare` expose `assignments()` (hasMany pivot) et des accesseurs `users()`, `userGroups()`, `workstationGroups()` (morphedByMany via `network_share_assignables`), plus une constante `NetworkShare::TYPE_DRIVES = 'drives'` réutilisée par le provider (iso `Wallpaper::TYPE_WALLPAPER`)
**And** `User`, `UserGroup`, `WorkstationGroup` exposent une relation inverse `networkShares()` (morphToMany) portant le pivot `access`
**And** aucun accès AD/LdapRecord dans les modèles (FK SQL pures).

### AC3 — Provisioning FS + ACLs RO/RW dérivées des assignations user/group (FR fondation)

**Given** un `NetworkShare` avec des assignations `User`/`UserGroup` (`access` ro/rw) et éventuellement `WorkstationGroup`
**When** `NetworkShareService::provision($share)` est appelé
**Then** le répertoire `/var/sambaedu/Partages/<directory_name>` est créé (sudo mkdir -p, idempotent) sous la racine configurée, validé par la garde de path durcie (refuse traversal/espaces/métacaractères, profondeur bornée)
**And** les ACLs POSIX sont appliquées (`setfacl -b` wipe puis batch) : pour chaque assignation `User` → `user:<login>:rx|rwx` (+ default), pour chaque `UserGroup` → `group:<unix_group>:rx|rwx` (+ default), avec `rx` si `access=ro`, `rwx` si `access=rw` ; set canonique de base (`user::rwx`, `group::---`, `group:domain\040admins:rwx`, `mask::rwx`, `other::---`) + défauts miroir pour l'héritage (calqué `ShareService::buildEchangeAcls`)
**And** une assignation `WorkstationGroup` ne contribue **AUCUNE** ACL (montage-seul — invariant documenté)
**And** l'ownership est posée (`chown www-admin`, `chgrp 'domain admins'`)
**And** le nom de groupe Unix d'un `UserGroup` est dérivé correctement (réutiliser la logique `ShareService::aclGroupLocalPart` / suffixe établissement fédéré pour les groupes `classe`/`equipe`, à défaut le `name` du groupe — documenter le mapping retenu)
**And** l'opération est idempotente (2ᵉ appel = mêmes ACLs, zéro modif des données), fail-soft (retour `bool`, `Log::error` préfixé, aucune exception), et audite dans `quota_audit_logs` (`target_type='share'`, `partition='/var/sambaedu'`).

### AC4 — Projection agent : `DrivesStateProvider` émet les répertoires configurés par maille, sans casser K:/H: (FR fondation, NFR12)

**Given** des `network_shares` assignés (mailles user/userGroup/WG) et un `TargetContext` (poste, user)
**When** `DrivesStateProvider::itemsFor($ctx)` compile
**Then** le jeu fixe K:/H: est émis **inchangé** (non-régression) PLUS un candidat par `network_share` applicable, étiqueté de la maille correcte : assignation `User` → `StateMaille::User` (si `$ctx->user?->id` matche), `UserGroup` → `StateMaille::UserGroup` (si `in_array(id, $ctx->userGroupIds)`), `WorkstationGroup` → `StateMaille::PhysicalGroup` ou `LogicalGroup` selon `is_physical` (si `in_array(id, physicalGroupIds/logicalGroupIds)`)
**And** le payload v1 reste `{letter, unc, label}` : `letter` = `share.letter` ou auto-assignée (pool `M..Z`, exclut `A,B,C,D,H,I,K,L`, déterministe par `id` asc), `unc` = `\\<se4fs>\partages\<directory_name>\`, `label` = `share.label ?? share.name`
**And** lecture **Postgres only** (relations Eloquent, ids de `TargetContext`) — **zéro** AD/LdapRecord/APCu, **zéro** re-requête des appartenances (grep en review)
**And** quand AUCUN `network_share` n'existe, la sortie est **byte-identique** à l'actuelle (golden `state.v1.json` + `FROZEN_STATE_HASH` PHP/Go **inchangés** — un test l'asserte)
**And** un même `network_share` atteignable via plusieurs mailles produit le même payload → dédupliqué par l'agrégat du compilateur (zéro doublon), **sans aucune modification du `StateCompiler`** (pattern 23.4).

### AC5 — Tests : PHPUnit serveur, baselines intactes (NFR13)

**Then** `tests/Unit/Services/Agent/DrivesStateProviderTest.php` est étendu : K:/H: inchangés ; émission par maille (user/userGroup/physical/logical) ; auto-assignation de lettre (pool, exclusions, déterminisme) ; dédup multi-maille ; lecture seule / zéro AD ; sortie identique si aucune ligne
**And** `tests/Unit/Services/Filesystem/NetworkShareServiceTest.php` (avec `Process::fake()`, AUCUN FS réel) : provision idempotente, ACL `rx` vs `rwx` selon `access`, WG = aucune ACL, garde de path (rejet traversal/métacaractères), audit écrit, fail-soft sur échec `setfacl`
**And** tests des modèles/relations (morph, pivot `access`, contrainte d'unicité)
**And** non-régression : `--filter Agent` + `--filter ContractV1` verts (baseline relevée AVANT le dev — mémoire `vm_phpunit_bulk_run_false_failures` : valider par filtres ciblés, pas en run massif) ; sur l'HÔTE (php8.4 + sqlite, mémoire `phpunit_test_env_host_vs_vm`).

### AC6 — Documentation + backlog (append-only)

**Then** `docs/agent/state-providers.md` : section `drives` enrichie (répertoires configurables par maille, payload inchangé, lettre auto-assignée, racine `partages`, modèle d'accès deux-axes) ; `docs/agent/contract-v1.md §7` reste **INTOUCHÉ** (type `drives` déjà figé)
**And** une note d'INFRA `[PROD]` documente l'export SMB `[partages]` → `/var/sambaedu/Partages` à déclarer côté serveur (hors git), + l'entrée sudoers (déjà couverte : `setfacl/getfacl/mkdir/mv/chown/chgrp` whitelistés par commande, path-agnostique)
**And** `_bmad-output/backlog.data.js` : Epic 34 « Lecteurs réseau gérés » ajouté avec la story `34-1` (statut suivi via `sprint-status.yaml`) ; les 4 fichiers backlog committés ensemble (mémoire `backlog_split_multifile`)
**And** restent **INTOUCHÉS** : canal legacy partages/lecteurs, `ShareService`, `PrintersStateProvider`, `agent/**` (donc pas de bump version agent), `contract-v1.md §7`, `StateCompiler`, `AclService::$classesRoot` (la racine classes ne change pas).

## Tasks / Subtasks

- [x] **T1 — Persistance** (AC1, AC2)
  - [x] Migration `create_network_shares_table` : `id`, `name`, `directory_name` (unique), `label` (nullable), `letter` (nullable), `created_by_user_id` (FK nullable, `nullOnDelete`), `timestamps`. (Note SQLite : domaine `access` non contraint en SQLite — mémoire `sqlite_tests_no_varchar_enforcement` ; valider applicativement.)
  - [x] Migration `create_network_share_assignables_table` : `id`, `network_share_id` (FK cascade), `morphs('assignable')`, `access` (string `ro|rw` défaut `ro`), `timestamps`, `unique(network_share_id, assignable_id, assignable_type)`.
  - [x] Modèle `App\Models\NetworkShare` : `$fillable`, const `TYPE_DRIVES`, `assignments()` (hasMany vers `NetworkShareAssignable`), `users()`/`userGroups()`/`workstationGroups()` (morphedByMany, pivot `access`), accesseur `effectiveLabel()`. + modèle pivot `NetworkShareAssignable` (morphTo + `isWritable()`).
  - [x] Relations inverses `networkShares()` (morphToMany avec pivot `access`) sur `User`, `UserGroup`, `WorkstationGroup`.
  - [x] Map morph : le projet n'a PAS de `Relation::enforceMorphMap` — on stocke le FQCN en clair (iso `shortcut_assignables`). `ALLOWED_ASSIGNABLE_TYPES` documente les 3 types autorisés.
  - [x] Factory `NetworkShareFactory` (pour tests + tinker en l'absence d'UI).

- [x] **T2 — `NetworkShareService` (provisioning FS/ACL générique)** (AC3)
  - [x] `app/Services/Filesystem/NetworkShareService.php`. `$sharesRoot = '/var/sambaedu/Partages'` + `sharesRoot()` lisant `config('filesystem.shares_root', …)`. **Décision** : self-contained (shell-outs propres) — `AclService::setAcls/validatePath` est verrouillé sur `classesRoot` et refuse `Partages` ; on N'A PAS détendu `AclService` (garde-fou « ZÉRO touche AclService » + baseline 5.2 préservée). Injecte `ShareService` UNIQUEMENT pour réutiliser ses helpers de nommage PUBLICS (`aclGroupLocalPart`/`establishmentSuffix`).
  - [x] Garde de path durcie : méthode privée `validateSharePath()` calquée 1:1 sur `AclService::validatePath`, paramétrée sur `sharesRoot()` + `MAX_DEPTH=2`. Triple garde préservée (regex anti-traversal + `escapeshellarg` + whitelist sudo).
  - [x] `resolveSharePath($share)` → `/var/sambaedu/Partages/<directory_name>` (validé) ou `null`.
  - [x] `buildAcls($share)` : set canonique + une ligne `user:`/`group:` par assignation user/group (rx|rwx), défauts miroir. WG ignoré (aucune ACL).
  - [x] Mapping `UserGroup → groupe Unix` (documenté dans le code) : `classe` → `classe_<localPart>`, `equipe` → `equipe_<localPart>` (localPart via `aclGroupLocalPart` = nom court + suffixe étab fédéré), sinon `<localPart>` (« à défaut le name du groupe »). Anti double-préfixe (`stripAclPrefix`).
  - [x] `provision($share, ?performedBy)` : lock `Cache::store('file')->lock()` (mémoire `apcu_cache_no_lock`), mkdir idempotent, `setAcls -b`, chown/chgrp, audit `quota_audit_logs`, fail-soft, invalidation cache.
  - [x] `getStatus($share)` (lecture sans side-effect, pour future UI/commande).

- [x] **T3 — Extension `DrivesStateProvider`** (AC4)
  - [x] Conserver K:/H: à l'identique (candidats fixes inchangés ; constructeur sans dépendance préservé).
  - [x] Après K:/H:, charger les `network_shares` applicables au `TargetContext` (une requête `DB::table` bornée par les ids du contexte : `assignable` ∈ {user.id} ∪ userGroupIds ∪ workstationGroupIds, par type). Étiqueter chaque candidat de sa `StateMaille`.
  - [x] Auto-assignation de lettre déterministe (pool `M..Z`, exclusions, tri `id` asc) quand `letter` null. Helpers purs `resolveLetters`/`nextFreeLetter` + testés.
  - [x] Payload `{letter, unc:'\\<se4fs>\\partages\\<directory_name>\\', label}`. `sourceId = 2 + pivot.id` (déterministe, injectif, ≥3 après K=1/H=2).
  - [x] Vérifié en review : zéro AD, zéro re-requête d'appartenance, `declare(strict_types=1)`.

- [x] **T4 — Tests** (AC5)
  - [x] Étendu `DrivesStateProviderTest` (mailles user/userGroup/physical/logical, auto-lettre, dédup multi-maille, machine-only, + test « zéro ligne ⇒ sortie identique »). 20 tests.
  - [x] `NetworkShareServiceTest` avec `Process::fake()` (provision, ACL rx/rwx, WG=aucune ACL, garde de path, audit, fail-soft, idempotence). 13 tests.
  - [x] Tests modèles/relations/morph/unicité (`NetworkShareTest`). 8 tests.
  - [x] Baseline relevée AVANT (Agent 526 / ContractV1 5 / Drives 8) et re-validée APRÈS (Agent 538 / ContractV1 5 / NetworkShare 21) — filtres ciblés, HÔTE php8.4+sqlite.

- [x] **T5 — Documentation + backlog** (AC6)
  - [x] `docs/agent/state-providers.md` (section drives enrichie d'un sous-chapitre 34.1 + note `[PROD]` export SMB).
  - [x] `_bmad-output/backlog.data.js` : story 34-1 → `review` (Epic 34 déjà présent). `runbook` QA `docs/qa/domains/filesystem.md` enrichi (Story 34.1, scénarios 34.1-1..8) + entrée README mise à jour.
  - [x] Vérifié `contract-v1.md §7`, golden `state.v1.json`, `FROZEN_STATE_HASH` PHP/Go et `agent/**` INTOUCHÉS (git status).

## Dev Notes

### Patterns à RÉUTILISER (ne pas réinventer)

- **Provider scopé WG par maille** : `app/Services/Agent/Providers/PrintersStateProvider.php` est le gabarit canonique (lit un pivot WG, étiquette par maille physique/logique). `app/Services/Agent/Providers/ShortcutsStateProvider.php` pour l'union multi-maille user+WG.
- **Pivot polymorphe** : migration `database/migrations/2026_02_09_173400_create_shortcut_assignables_table.php` (`morphs('assignable')` + `unique`). On reste 100 % pivot SQL (User/UserGroup/WG sont des modèles SQL), PAS de colonnes JSON `ad_*`.
- **Provisioning FS + ACL** : `app/Services/Filesystem/ShareService.php` (mkdir/chown/chgrp/audit/lock/idempotence) + `app/Services/Filesystem/AclService.php` (`setAcls`/`addAcl`/`getFacl`, `validatePath`, `escapeshellarg`, sudo). **Lire `buildEchangeAcls()` (l. 295) pour le set canonique RO/RW avec défauts miroir.**
- **Contexte de ciblage** : `app/Services/Agent/TargetContext.php` — propriétés `user`, `physicalGroupIds`, `logicalGroupIds`, `userGroupIds` ; accesseur `workstationGroupIds()`. Hydraté Postgres-only.
- **Mailles** : `app/Enums/StateMaille.php` (`User`, `UserGroup`, `PhysicalGroup`, `LogicalGroup`, `Broadcast`). **La précédence vit dans `StateCompiler::specificity()` SEUL — ne JAMAIS la dupliquer dans le provider (D2).**
- **Handler agent (INCHANGÉ)** : `agent/shared/handler_drives.go` + `agent/windows/handler_drives_windows.go` montent toute lettre→UNC (Win32 `mpr.dll`, marqueur de périmètre `\\<se4fs>\` pour ne jamais démonter un lecteur user). Token `<se4fs>` substitué localement (`handler_shortcuts_windows.go::substituteTokens`).
- **Audit** : `App\Models\QuotaAuditLog::log(...)` avec `targetType='share'` (cf. `ShareService::writeAudit`).
- **Policy (future UI)** : `app/Policies/SharePolicy.php` (`share.view`/`share.manage`) — pertinent en 34.2, mentionné ici pour cohérence des abilities.

### Contraintes d'environnement

- **Tests sur l'HÔTE** (php8.4 + pdo_sqlite ; la VM n'a pas pdo_sqlite) — mémoire `phpunit_test_env_host_vs_vm`.
- **Migrations VM pas auto-jouées** : `migrate:status` avant tout e2e base sur `/vm` — mémoire `vm_migrations_not_auto_applied`.
- **Run massif PHPUnit = faux échecs** sur VM : valider par filtres ciblés — mémoire `vm_phpunit_bulk_run_false_failures`.
- **PHP-FPM = www-admin** : tout fichier créé/lu par PHP doit être `chown www-admin` ; le provisioning pose déjà `chown www-admin` sur les répertoires — mémoire `php_fpm_user_www_admin`.
- **`Cache::lock()` + APCu** : APCu ne supporte pas les locks cross-process → `Cache::store('file')->lock()` — mémoire `apcu_cache_no_lock`.
- **Racine projet = Laravel** : `artisan`/`app/` à la racine (pas `laravel/*`) — mémoire `root_is_laravel`.

### [PROD] — Infra serveur (hors git, à documenter)

- Déclarer dans `smb.conf` un partage `[partages]` → `path = /var/sambaedu/Partages`, accessible aux utilisateurs authentifiés, traversable (`other` au niveau racine permet la traversée, l'accès réel est gaté par l'ACL de chaque sous-dossier). Iso `[users]`/`[classes]` (déjà configurés externement, SE5 ne gère pas `smb.conf`).
- Créer `/var/sambaedu/Partages` (`mkdir`, `chown www-admin:'domain admins'`) — peut être fait par le service au premier `provision()` (mkdir -p sur la racine) ou par l'infra. Privilégier mkdir -p idempotent dans le service.
- Sudoers : déjà couvert (`/etc/sudoers.d/sambaedu` whiteliste les commandes `setfacl/getfacl/mkdir/mv/chown/chgrp` par binaire, indépendamment du path).

### Project Structure Notes

- Modèles : `app/Models/NetworkShare.php`.
- Service : `app/Services/Filesystem/NetworkShareService.php`.
- Provider : extension de `app/Services/Agent/Providers/DrivesStateProvider.php` (enregistrement déjà présent `AgentServiceProvider.php:188` — pas de nouvelle ligne registry sauf si on opte pour un provider séparé, ce que la story ÉCARTE pour ne pas dupliquer le type `drives`).
- Migrations : `database/migrations/` (préfixe date du jour).
- Config : ajouter `shares_root` dans `config/filesystem.php` (créer le fichier s'il n'existe pas ; aujourd'hui `classes_root` tombe sur le fallback static).
- Tests : `tests/Unit/Services/Agent/DrivesStateProviderTest.php`, `tests/Unit/Services/Filesystem/NetworkShareServiceTest.php`.

### Decompose — suite de l'Epic 34 (hors scope 34.1, pour cadrage seulement)

- **34.2** : UI admin (refnum) — créer un répertoire nommé, choisir l'audience (user/userGroup/WG) + RO/RW, lettre ; SFC Livewire, `WithToasts`, `SharePolicy`, modale réutilisable, validation prédictive des collisions de lettre.
- **34.3** : Templates de répertoire modifiables (échanges direction / profs / élèves / user↔user / groupes de users) = recettes d'assignation+ACL préconfigurées.
- **34.x** : commande de resync (`shares:resync-network` calquée `SharesResyncClassCommand`), réconciliation FS, archivage/suppression deux temps.

### References

- [Source: _bmad-output/implementation-artifacts/27-2-handlers-lecteurs-imprimantes.md] — drives MVP-A, fork « MVP-B » (table+pivot+UI) explicitement écarté à l'époque = cette story le livre ; pattern provider, payload, handler Go.
- [Source: app/Services/Agent/Providers/DrivesStateProvider.php] — jeu fixe K:/H:, lettres réservées, scope/semantics, tokens.
- [Source: app/Services/Filesystem/ShareService.php] — provisioning classe, ACL builders, audit, lock, idempotence.
- [Source: app/Services/Filesystem/AclService.php] — setfacl/getfacl, validatePath, triple garde, MAX_DEPTH.
- [Source: app/Services/Agent/TargetContext.php] — mailles résolues Postgres-only.
- [Source: app/Enums/StateMaille.php] — mailles + invariant « précédence dans StateCompiler seul ».
- [Source: database/migrations/2026_02_09_173400_create_shortcut_assignables_table.php] — pivot polymorphe + unique.
- [Source: app/Services/Agent/Providers/PrintersStateProvider.php] — gabarit provider scopé WG par maille (référence story 27.2).
- [Source: memory/project_native_drive_management_direction.md] — direction native, golden figé, lettres K/H/I/L.
- [Source: memory/project_acl_equipe_group_missing_etab_suffix.md] — suffixe établissement fédéré sur les groupes ACL.

## Dev Agent Record

### Agent Model Used

Opus 4.8 (1M context) — `claude-opus-4-8[1m]`.

### Debug Log References

- **Piège `Process::fake()` (résolu)** : un 2ᵉ appel à `Process::fake()` sur un fake déjà actif est IGNORÉ (les handlers ne sont configurables qu'au 1er appel). Le fake `'*' => exit 0` de `setUp()` shadowait le `'sudo setfacl*' => exit 1` du test fail-soft → `provision()` retournait `true` à tort. Correctif : `NetworkShareServiceTest::setUp()` ne fake PAS ; chaque test appelle `Process::fake(...)` une seule fois avec ses handlers.
- **Régression évitée `config/filesystem.php`** : ne PAS déclarer `classes_root` dans le nouveau fichier — sa présence masquerait l'override statique `AclService::$classesRoot` et casserait `ShareServiceTest`/`AclServiceTest`. Le fichier ne porte QUE `shares_root` (vérifié : « supports classes root override via static property » reste vert).
- Tests HÔTE (php8.4.5 + pdo_sqlite). `vendor/` + `.env` + `bootstrap/cache` matérialisés localement dans le worktree pour exécuter la suite (artefacts non versionnés, gitignored).

### Completion Notes List

- **Périmètre** : fondation backend pure (zéro UI, zéro template) conforme aux 5 décisions Henri.
- **Modèle d'accès 2 axes** : visibilité = toute maille (provider étiquette `User`/`UserGroup`/`PhysicalGroup`/`LogicalGroup` ; le `StateCompiler` arbitre, ZÉRO modif) ; ACL POSIX = grants `User`/`UserGroup` seulement (rx/rwx selon `access`) ; `WorkstationGroup` = montage-seul (aucune ACL — invariant documenté + testé `buildAcls`).
- **Décision mapping `UserGroup → groupe Unix`** (documentée code + runbook) : `classe`→`classe_<localPart>`, `equipe`→`equipe_<localPart>` (localPart = `ShareService::aclGroupLocalPart` = nom court + suffixe étab fédéré), sinon `<localPart>`. Anti double-préfixe `stripAclPrefix`.
- **Décision `NetworkShareService` self-contained** : `AclService::validatePath`/`setAcls` est verrouillé sur `classesRoot` et refuserait `Partages` ; plutôt que détendre cette garde partagée (risque baseline 5.2 + garde-fou « ZÉRO touche AclService »), le service porte sa propre triple garde (`validateSharePath`, MAX_DEPTH=2) et ses propres shell-outs `setfacl/mkdir/chown/chgrp`. `ShareService` réutilisé en lecture seule (helpers de nommage publics) — non modifié.
- **Lettre auto-assignée** : pool `M..Z`, exclut `A,B,C,D,H,I,K,L` + lettres déjà émises dans le set ; déterministe (tri `network_shares.id` asc). Helpers purs `resolveLetters`/`nextFreeLetter`, testés (pool, exclusions, déterminisme).
- **Payload INCHANGÉ** `{letter, unc, label}` — `access` n'y figure jamais (grep en revue). UNC `\\<se4fs>\partages\<directory_name>\` (token substitué localement par l'agent).
- **GARDE-FOUS PROUVÉS** : golden `state.v1.json` + `FROZEN_STATE_HASH` PHP/Go INCHANGÉS (test dédié `zero_network_shares_yields_byte_identical_fixed_output` + ContractV1 vert) ; `agent/**`, `StateCompiler`, `AclService`, `ShareService`, `PrintersStateProvider`, `contract-v1.md §7` INTOUCHÉS (git status) ; pas de bump de version agent ; zéro AD/LdapRecord/APCu dans le code livré.
- **Résultats tests (HÔTE, filtres ciblés)** : `--filter Agent` → 538 passed / 22 skipped (1862 assertions) [baseline 526] ; `--filter ContractV1` → 5 passed (104) [inchangé] ; `--filter NetworkShare` → 21 passed (56) ; `ShareServiceTest`+`AclServiceTest` → 67 passed (141) [non-régression config].

### File List

**Créés :**
- `config/filesystem.php`
- `database/migrations/2026_06_29_120000_create_network_shares_table.php`
- `database/migrations/2026_06_29_120100_create_network_share_assignables_table.php`
- `app/Models/NetworkShare.php`
- `app/Models/NetworkShareAssignable.php`
- `app/Services/Filesystem/NetworkShareService.php`
- `database/factories/NetworkShareFactory.php`
- `tests/Unit/Models/NetworkShareTest.php`
- `tests/Unit/Services/Filesystem/NetworkShareServiceTest.php`

**Modifiés :**
- `app/Services/Agent/Providers/DrivesStateProvider.php` (extension : émission des `network_shares` par maille + auto-lettre ; K:/H: inchangés)
- `app/Models/User.php` (relation inverse `networkShares()`)
- `app/Models/UserGroup.php` (relation inverse `networkShares()` + import `MorphToMany`)
- `app/Models/WorkstationGroup.php` (relation inverse `networkShares()`)
- `tests/Unit/Services/Agent/DrivesStateProviderTest.php` (12 nouveaux tests network_shares)
- `docs/agent/state-providers.md` (section `drives` enrichie + [PROD] export SMB)
- `docs/qa/domains/filesystem.md` (Story 34.1, scénarios 34.1-1..8)
- `docs/qa/README.md` (entrée domaine filesystem → +Story 34.1)
- `_bmad-output/backlog.data.js` (story 34-1 → `review`)
- `_bmad-output/implementation-artifacts/sprint-status.yaml` (34-1 → `review`)
- `_bmad-output/implementation-artifacts/34-1-fondations-lecteurs-reseau-geres.md` (cette story)

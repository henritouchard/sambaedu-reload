# Sprint Change Proposal — 2026-04-17

**Auteur :** John (PM), sous pilotage d'Henri
**Statut :** Validé
**Portée :** Réorganisation Epic 1bis v2 — suite de l'audit `idempotency.md` (gap analysis sambaedu ↔ sambaedu-reload)

---

## Déclencheur

Audit produit par Henri + John consigné dans `_bmad-output/planning-artifacts/idempotency.md` (219 → 379 lignes, v2 du 2026-04-16) :

> L'Epic 1bis développe un mode de compatibilité pour tous les modules legacy. Seulement je n'en ai pas besoin d'autant puisque certains modules sont déjà réimplémentés au moins partiellement dans `sambaedu-reload/` en Laravel.
> — Henri, 2026-04-16

La vérification empirique (§ 8 d'`idempotency.md`) révèle deux faits décisifs :

1. Le shim LDAP existant (`sambaedu-reload/legacy/ldap.inc.php`, 1982 L) couvre **100%** des fonctions LDAP appelées par les 6 modules backlog (annu2, parcs2, acls, partages, printers, dhcp, bbb, infos). Le coût d'un shim pour ces modules est donc **< 3h chacun** (cp + smoke tests), pas plusieurs jours.
2. `annu2` et `parcs2` disposent d'une **base Laravel déjà substantielle** (≥ 70% — `UserService`, `UserGroupService`, `WorkstationService`, `WorkstationGroupService`, `MachinePowerService`, pages `/users/*`, `/rights-management`, `/parc/*`). Shimmer ces deux modules = code jeté sous un sprint.

## Décisions

### D1 — Category A : SHIM EXPRESS pour 6 modules (maintenus dans Epic 1bis)

Modules cloisonnés via `cp -r sambaedu/{module} sambaedu-reload/legacy/modules/{module}/` + smoke tests via catchall. Acceptance criteria allégés : "module chargé sans fatale, pages principales répondent, exec système loggent gracefully".

| Story | Module | Effort estimé | Exec système à valider |
|-------|--------|---------------|------------------------|
| 1bis-14 | `partages` | ~1h | aucun |
| 1bis-16 | `dhcp` | ~2h | 2 (configs DHCP) |
| 1bis-17 | `bbb` | ~2h | aucun (API externe BBB) |
| 1bis-19 | `infos` | ~3h | 7 (`df`, `du`, `uname`, `uptime`…) + split secondaire |
| 1bis-13b | `acls` (ex `parcs2+acls`) | ~3h | 3 (`samba-tool`) |
| 1bis-15 | `printers` | ~3h | 4 (`lpadmin` + CUPS, nécessite cups-pdf sur VM dev) |

**Total Category A : ≈ 14h / 2 jours cumulés.** Les refontes natives s'intégreront aux epics fonctionnels ensuite, en supprimant ces shims à ce moment.

### D2 — Category B : BUILD direct, skip shim

Deux modules basculent en **BUILD Laravel direct** via leurs epics fonctionnels naturels — shim inutile puisque la base Laravel est déjà là.

| Story 1bis | Module | Défer vers | Rationale |
|-----------|--------|-----------|-----------|
| 1bis-12 | `annu2` | **Epic 2** | `/users/*`, `/rights-management`, `UserService`, Spatie Permissions déjà en place. Reste à compléter : profils Spatie (migration bitmask), réinit mdp bulk, completion `/users/new`. |
| 1bis-13a | `parcs2` | **Epic 4** | `WorkstationService`, `WorkstationGroupService`, `MachinePowerService`, `/parc/*` déjà en place. Reste à compléter : délégations salle (`delegate_parc.php`), historique actions, UI `/parc/groups/new`. |

### D3 — Split de la story 1bis-13

L'ancienne story unique `1bis-13-modules-parcs2-acls` est scindée en deux :
- **1bis-13a** (parcs2) → **cancelled**, couverte par Epic 4
- **1bis-13b** (acls) → reste dans Epic 1bis avec scope **SHIM EXPRESS**

### D4 — Aucun changement pour Category C (shims confirmés)

| Story | Statut |
|-------|--------|
| 1bis-11 wpkg | ready-for-dev (priorité #1) |
| 1bis-18c firefox/thunderbird | ready-for-dev |
| 1bis-18d wallpaper | backlog (shim classique) |
| 1bis-18e scripts/veyon/wine/assoc | backlog (shim classique) |
| 1bis-18f profils itinérants | backlog (shim classique) |
| 1bis-18g shims LDAP/SYSVOL | review |

### D5 — `infos` splittée sans story 1bis dédiée

Validation Henri : le SHIM EXPRESS 1bis-19 couvre la transition. Les sous-domaines se redistribuent ensuite :
- Dashboard `/app/dashboard` → absorbe `df`, `du`, `uname`, `uptime`
- Epic 2 / 5 → `quota_fixer`, `quota_visu`, `infomdp` (cohérence `QuotaService`, `QuotaAuditLog`, `PasswordService` existants)
- Admin tools `/admin/` → `test_ldap` (outil debug ponctuel)
- `fix_se4.php` → à analyser (probable outil admin à conserver en shim durable)

### D6 — ~~Point suspendu~~ → **Tranché** : `acls` obsolète

~~Henri indiquera plus tard si `visuacls.php` est utilisé par les admins sur le terrain.~~
**Mise à jour 2026-04-17 (même journée)** : Henri confirme que le module `acls` legacy est **obsolète** — aucun usage terrain. La story `1bis-13b` est donc **cancelled** et déférée à **Epic 5 (FR13-16, ACLs POSIX/Windows)** sans shim intermédiaire. Gain : −3h sur le sprint SHIM EXPRESS (Category A passe de ~14h à ~11h).

---

### D7 — Recalage Epic 7 (Spatie) + Epic 12 (Matrice) suite à découverte de l'existant

**Découverte 2026-04-17 (audit PM) :** le socle Spatie est **très largement livré** côté `sambaedu-reload/` — bien plus que ce que le backlog laissait paraître. Inventaire :

- `spatie/laravel-permission` v6.24 installé + migration `create_permission_tables` exécutée (2026-02-06)
- Enums `SambaPermission` (19 permissions dot-notation) + `SambaRole` (9 rôles) définis
- Mapping bidirectionnel `SambaPermission ↔ LegacyRight` (bitmask compat) via `legacyRight()`
- `PermissionService` complet : `syncFromAd`/`syncToAd`, `bitmaskToPermissions`/`permissionsToBitmask`, `grantDelegation`/`revokeDelegation`/`negateDelegation`, `canOnWorkstationGroup` (scope check)
- Model `Delegation` existant avec relations
- 4 Policies câblées dans `AuthServiceProvider::boot()` : User, Group, WorkstationGroup, Shortcut
- `@can` utilisé dans 13+ fichiers Blade + `UserService` + `PermissionService`
- `PermissionSeeder` (dev)
- Page Livewire `/rights-management` (513 lignes, fonctionnelle, à améliorer)

**Conséquence sur le backlog :**

| Story | Avant 2026-04-17 | Après |
|-------|-------------------|-------|
| 7-1 Attribution Droits Délégués | backlog | **in-progress** (socle livré, UI à améliorer, tests prod à faire) |
| 7-2 Calcul & Application Spatie | backlog | **in-progress** (Policies livrées pour 4 models, reste Delegation/Machine/Printer/Share/Dhcp + middleware + tests) |
| 7-3 Migration bitmask → Spatie prod | *(n'existait pas)* | **backlog — NOUVEAU** (point bloquant pour passer en prod) |
| 12-1 Matrice Profils × Droits | backlog | **in-progress** (partielle dans enums, reste audit complétude + doc markdown versionnée) |
| 10-2 Profils par Défaut + Rôles imposés controlHub | *(n'existait pas)* | **backlog — NOUVEAU** (seeder prod standalone + API push roles controlHub) |
| Story 2-6 Réinit mdp bulk | gate temporaire `SE_USER_ADMIN` | **directement `@can('user.password.init')`** (Spatie déjà opérationnel) |

**Stratégie A actée (Spatie source de vérité + bitmask projection legacy) :**

Henri a tranché (2026-04-17) : on ne garde PAS le bitmask comme référence. Spatie devient la **source de vérité** côté Laravel. Le bitmask AD reste **écrit** à chaque changement via `permissionsToBitmask()` pour que les shims legacy PHP continuent à le lire sans rupture. Les délégations scopées (par workstationGroup) et les permissions directes restent **exclusivement Spatie** (non représentables en bitmask).

Conséquences concrètes pour Story 7-3 :
- Migration one-shot : lire bitmask AD de chaque user → `bitmaskToPermissions()` → `assignRole` + `syncPermissions` Spatie
- Observer sur Role/Permission Spatie qui met à jour le bitmask AD à chaque changement
- Pas d'abandon du bitmask — inversion du flux : Spatie → bitmask (pas l'inverse)
- Les shims legacy PHP continuent de fonctionner sans modification

**Distinction claire 12-1 vs 7-2 :**
- **12-1 = spec métier** : "quel rôle a le droit de faire quoi" (réunion équipe, produit la matrice)
- **7-2 = implémentation Laravel** : Policies, @can, middleware (les policies manquantes restent dans 7-2, pas dans 12-1)

## Impact sprint

- **Epic 1bis** : passage de 9 stories backlog → **7 stories restantes** (6 SHIM EXPRESS + 1bis-11 wpkg + cluster 18c-f).
- **Epic 2** : 0 nouvelle story créée *maintenant* — seront générées via `bmad-create-story` au moment du démarrage (profils Spatie, mdp bulk).
- **Epic 4** : 0 nouvelle story créée *maintenant* — seront générées via `bmad-create-story` (délégations, historique, édition UI).
- **Aucun travail déjà livré n'est invalidé.** Les stories done (1bis-1 à 1bis-10, 18a/b/g review) restent intactes.

## Rationale

| Fait | Conséquence |
|------|-------------|
| Le shim LDAP couvre toutes les fonctions appelées par annu2/parcs2/acls/partages/printers/dhcp/bbb/infos | Shim = `cp + smoke test`, pas plusieurs jours |
| `annu2` : 20 / 22 fichiers sont des wrappers HTML < 1 KB, seul `profiles.php` (5.2 KB) contient du métier | Shimmer = code trivial mais immédiatement jeté quand Epic 2 couvrira |
| `parcs2` : WOL, stop, reboot, import AD, mass-actions, import/export CSV déjà dans `WorkstationService` | Shimmer les 12 fichiers = double maintenance inutile |
| `bbb` : 6 fichiers, 503 L, aucun exec système, API BBB externe | Shim 2h >> BUILD 2j (validation Henri) |
| `printers`/`dhcp` : pas d'équivalent Laravel MAIS cycles d'exec `lpadmin` / configs DHCP fragiles en test | Shim express = keep feature alive pendant qu'Epic 6/8 se planifient |
| `partages`/`infos` : très petits modules, 0-1 exec bas risque | Shim express immédiat |

## Fichiers modifiés

- `_bmad-output/planning-artifacts/idempotency.md` — doc source de la décision (v2, 379 L)
- `_bmad-output/planning-artifacts/epics.md` — scope révisé des stories 1bis-12/13/14/15/16/17/19, split 13 en 13a/13b
- `_bmad-output/implementation-artifacts/sprint-status.yaml` — statuts révisés, split 13, changelog `last_updated`
- `_bmad-output/backlog.html` — même changements que sprint-status, refs Epic 2/4
- `_bmad-output/planning-artifacts/sprint-change-proposal-2026-04-17.md` (ce document)

## Ordre de bataille post-validation

1. **Sprint court SHIM EXPRESS** : les 6 stories Category A en un bundle (≈ 2j). Une seule session possible si l'environnement VM est prêt.
2. **Débloquer 1bis-11 wpkg** (priorité #1 du reste, XL).
3. **Finir cluster GPO** 18c → 18f (bundle TDD court).
4. **Fermer Epic 1bis** après ces 7 stories restantes.
5. **Créer les stories annu2 dans Epic 2** via `bmad-create-story` (profils Spatie, mdp bulk).
6. **Créer les stories parcs2 dans Epic 4** via `bmad-create-story` (délégations, histo, édition UI).
7. **Refonte native** `printers` → Epic 6, `dhcp` → Epic 8, `partages` + `acls` → Epic 5, `bbb` → nouveau micro-epic ou story transverse quand prioritaire. À ce moment, **supprimer les shims express** correspondants.

## Points d'attention résiduels

1. **Label `1bis-13-modules-parcs2-acls`** : l'ID existe dans `sprint-status.yaml` et `backlog.html` mais sans fichier d'implémentation (backlog). Renommage en 13a/13b propre, pas de rupture de traçabilité.
2. **Usage `visuacls.php` à confirmer par Henri** (D6). Jusque-là, 1bis-13b reste SHIM EXPRESS.
3. **VM dev CUPS** : installer `cups-pdf` avant de lancer le shim 1bis-15 (déjà prévu comme imprimante virtuelle dans la note Epic 6 d'`epics.md`).
4. **4 appels SQL résiduels dans `parcs2/wolstop_station.php`** : non bloquants pour 1bis (parcs2 bascule en Category B, Epic 4). Seront résolus lors du BUILD Epic 4 (couverts par les modèles Eloquent natifs `Workstation`, `WorkstationGroup`).

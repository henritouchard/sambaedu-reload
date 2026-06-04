# Story 4.11 : Unification de l'appartenance poste↔groupe dans le pivot global (suppression FK `physical_room_id`)

Status: done

## Story

As a **développeur SE5 / responsable du parc**,
I want que l'appartenance d'un poste à une salle physique vive dans le même pivot `workstation_group_workstation` que les parcs logiques,
so that tous les consommateurs « groupes d'un poste » (WPKG, GPO, raccourcis, filtres, UI) voient la salle sans logique d'union, et que la salle ne soit qu'un groupe avec une règle d'exclusivité.

## Contexte & Motivation

### Décision d'architecture (Henri, 2026-06-04)

Le modèle actuel scinde l'appartenance en deux mécanismes :
- **salle physique** → FK `workstations.physical_room_id` (1 poste = 1 salle), relation `Workstation::physicalRoom()` / `WorkstationGroup::physicalWorkstations()` ;
- **parc logique** → pivot N:N `workstation_group_workstation`.

Audit du 2026-06-04 : **7 familles de consommateurs** lisent le pivot seul et ratent donc la salle, contre **1 seule famille** (iPXE/OU AD) qui consomme la FK. Le split optimise le cas minoritaire et piège le cas majoritaire — trois vagues de bugs indépendantes en attestent :

| Consommateur cassé/amputé | Symptôme |
|---|---|
| `app/Gpo/Services/WorkstationConfigContextResolver.php:223-236` | Cherche `is_physical=true` **dans le pivot** (suppose explicitement l'ancien modèle unifié) → contexte « salle » toujours introuvable |
| `app/Wpkg/Deployment/Queries/ActiveDeploymentForWorkstationQuery.php:44-54` | Un déploiement WPKG attaché à une salle ne se résout jamais |
| `app/Wpkg/Deployment/Services/WorkstationPackagesResolver.php:96-125` | Idem — packages via salle invisibles |
| `app/Services/ShortcutCompilerService.php:121` | Raccourcis attachés à une salle jamais compilés |
| `app/Repositories/WorkstationGroupRepository.php:150,217` (`whereHas('groups')`) | Filtrer l'onglet Postes par une salle → 0 résultat |
| `app/Services/AdSync/AdSyncChecker.php:332` | Compare les groupes pivot vs AD sans la salle |
| UI parc (compteurs, modale « ajouter à un groupe ») | Compteurs à 0 pour les salles (corrigé 2026-06-04 via accessor transitoire `members_count`) ; `addMachinesToGroup` attache une salle via pivot sans poser la FK |

Décision : **pivot global unique**. La salle d'un poste se lit `$ws->groups()->where('is_physical', true)`. L'invariant « 1 salle max par poste » devient une règle de service (swap transactionnel), plus une contrainte DB optionnelle (cf. D3). Cohérent avec la vision multi-vertical : domain-first SQL, l'OU AD n'est qu'une projection.

Décision mémorisée : `~/.claude/projects/.../memory/project_pivot_global_memberships.md`.

### Gap préexistant découvert pendant l'audit

`app/Jobs/AdSync/WorkstationMembershipAdSyncJob.php` (action `move` = déplacement OU AD) n'est **dispatché nulle part** dans `app/`. Le docblock de `WorkstationEnrollmentService::assignRoom()` (l.379-392) affirme que la propagation OU est « déléguée au workflow existant Epic 4 (au moins via observers / jobs) » — mais les hooks pivot de `WorkstationObserver` ont été **supprimés par la story 4-9** (D4, code mort audit-only) et `assignToPhysicalRoom()` ne dispatch rien. Le déplacement OU AD lors d'un changement de salle semble donc ne pas être propagé aujourd'hui. **AC7 traite ce point** : la story doit au minimum ne pas aggraver, idéalement câbler le dispatch dans le nouveau point d'écriture unique.

## Acceptance Criteria

1. **Given** la migration de schéma exécutée sur une base contenant des postes avec `physical_room_id` non nul, **When** je lis `workstation_group_workstation`, **Then** chaque ancien couple (poste, salle FK) existe en ligne pivot (backfill idempotent, sans doublon si la ligne existait déjà), **And** la colonne `workstations.physical_room_id` est supprimée (avec `down()` restaurant colonne + données depuis le pivot).
2. **Given** un poste membre d'une salle (pivot) et de N parcs logiques, **When** un consommateur lit `$ws->groups`, **Then** la salle ET les parcs sont retournés, **And** `$ws->physicalRoom` (nouvel accessor/relation pivot filtrée `is_physical`) retourne la salle unique ou null.
3. **Given** l'écriture « affecter le poste à la salle B » alors qu'il est en salle A, **When** le service exécute le swap, **Then** l'opération est transactionnelle (detach de toute salle physique existante + attach de B dans la même transaction), **And** il est impossible d'observer un état intermédiaire avec 0 ou 2 salles depuis une autre connexion.
4. **Given** les 7 familles de consommateurs listées en Contexte, **When** un profil WPKG / raccourci / contexte GPO / filtre machines référence une salle, **Then** la résolution fonctionne (le poste de la salle reçoit le déploiement, le filtre retourne les postes de la salle, `WorkstationConfigContextResolver` trouve le groupe `is_physical` dans `groups()`), **And** aucun de ces consommateurs ne contient de logique d'union FK+pivot.
5. **Given** les call-sites iPXE (`WorkstationLocator`, `IpxeEnrollmentMenuBuilder::buildRoomMenuVariables`, `WorkstationEnrollmentService::assignRoom`, `IpxeWindowsUnattendController`, `Workstation::machineObjectOu`), **When** un poste boote/s'enrôle, **Then** le comportement iPXE est strictement identique (menu salles, marquage `is_current`, OU cible de l'unattend), **And** les tests Feature iPXE existants passent sans modification sémantique (seules les fixtures changent de mécanisme d'écriture).
6. **Given** les accessors transitoires `WorkstationGroup::getMembersAttribute` / `getMembersCountAttribute` / `getWorkstationCountAttribute` (2026-06-04), **When** la migration est en place, **Then** ils délèguent tous trivialement à `workstations()` (l'aiguillage `is_physical` disparaît), **And** `physicalWorkstations()` et `Workstation::assignToPhysicalRoom()` sont supprimés ou délèguent au service (pas de double chemin d'écriture conservé).
7. **Given** un changement de salle via le point d'écriture unique du service, **When** le swap réussit, **Then** `WorkstationMembershipAdSyncJob::move` est dispatché (déplacement OU AD), **And** un test d'observation (`Bus::fake`) le prouve. Si l'audit (Tâche 1) révèle que la propagation OU passe en réalité par un autre canal vivant, documenter ce canal et adapter l'AC à l'existant — ne pas créer de double dispatch.
8. **Given** la modale « ajouter à un groupe » de l'onglet Postes (`machines-tab`), **When** la cible est une salle physique, **Then** l'attache passe par le service de swap (règle 1-salle-max + confirmation conflit existante `checkPhysicalRoomConflict`), pas par un attach pivot brut.
9. **Given** la suite de tests, **When** `php artisan test` tourne sur la VM, **Then** 0 régression, **And** les 3 tests préexistants en échec de `GroupShowPageTest` (fixtures attachant une salle via pivot — redevenues correctes par cette story) passent, **And** les fixtures des ~10 fichiers de tests utilisant `physical_room_id`/`physicalRoom` sont migrées vers le pivot.
10. **Given** la doc QA, **When** la story est livrée, **Then** `docs/qa/domains/` documente le nouveau modèle d'appartenance (append-only) et le runbook de migration (backfill + rollback).

## Tasks / Subtasks

### Tâche 1 — Audit préalable (AC: 7)
- [x] 1.1 Tracer le canal réel de propagation OU AD lors d'un changement de salle (grep `MembershipAdSyncJob`, `modrdn`, `move`, hooks 4-9) : confirmer le gap ou identifier le canal vivant. **Gap CONFIRMÉ** : `WorkstationMembershipAdSyncJob::move` n'était dispatché nulle part dans `app/` (seule occurrence non-définition = docblock `WorkstationEnrollmentService:462`). `AdSyncService::moveMachineToSalle` existe et est vivant, mais aucun déclencheur. → AC7 câble le dispatch dans le service.
- [x] 1.2 Inventaire exhaustif final des call-sites `physical_room_id|physicalRoom|physicalWorkstations|assignToPhysicalRoom` (app/, resources/, tests/, database/) — réalisé (cf. Completion Notes).
- [x] 1.3 Vérifier les écritures de FK côté imports : seul `database/seeders/WorkstationSeeder.php` écrivait la FK (migré vers pivot). Aucun import CSV/AD vivant n'écrit `physical_room_id`.

### Tâche 2 — Migration de schéma + backfill (AC: 1)
- [x] 2.1 Migration : backfill FK → pivot via `insertOrIgnore` (cross-driver PG `ON CONFLICT DO NOTHING` / SQLite `INSERT OR IGNORE`) avec timestamps explicites ; JOIN sur `workstation_groups` écartant les FK orphelines. Idempotent via `wg_ws_unique`.
- [x] 2.2 Drop `workstations.physical_room_id` (+ index/FK). `down()` recrée colonne + FK et repeuple depuis le pivot `is_physical=true`.
- [x] 2.3 D3 app-only respectée : pas de colonne pivot dénormalisée, pas d'index partiel. Documenté en doc QA (`parc.md` Section 1) avec critère de réouverture + sonde Scénario 1.3.

### Tâche 3 — Modèles (AC: 2, 6)
- [x] 3.1 `Workstation::physicalRooms()` (relation pivot filtrée `is_physical=true`) + accessor singulier `getPhysicalRoomAttribute` ; `getAdOu` inchangé (`physicalRoom?->ad_dn`).
- [x] 3.2 `Workstation::hasPhysicalRoom()` réécrit sur le pivot ; `assignToPhysicalRoom()` supprimé (écriture = service uniquement).
- [x] 3.3 `WorkstationGroup` : `members`/`members_count`/`workstation_count` délèguent à `workstations()` ; `physicalWorkstations()` supprimée.
- [x] 3.4 Casts/fillable : `physical_room_id` retiré + property docblock.

### Tâche 4 — Service de swap transactionnel (AC: 3, 7, 8)
- [x] 4.1 `assignMachineToPhysicalRoom()` réécrit : `DB::transaction` { detach salles physiques courantes + attach cible via `syncWithoutDetaching` (préserve les parcs logiques) } ; `$roomId=null` = detach seul.
- [x] 4.2 Validation existante conservée (salle inexistante / non physique → `InvalidArgumentException`) ; `checkPhysicalRoomConflict`/`moveMachineToPhysicalRoom` lisent le pivot.
- [x] 4.3 Dispatch `WorkstationMembershipAdSyncJob::move` post-commit, gardé par `roomId !== null && oldRoomId !== roomId` (pas de move parasite ni sur detach).
- [x] 4.4 `WorkstationEnrollmentService::assignRoom` délègue au service (`app(WorkstationGroupService)`) ; docblock mensonger corrigé.

### Tâche 5 — Consommateurs réparés (AC: 4)
- [x] 5.1 `WorkstationConfigContextResolver` : heuristique « premier `is_physical=true` dans `groups()` » déjà correcte ; `groups()` contient désormais la salle (aucun changement de code requis, validé par test existant).
- [x] 5.2 WPKG (`ActiveDeploymentForWorkstationQuery`, `WorkstationPackagesResolver`) : aucun changement de code (lisent déjà `groups`) — test `physical_room_packages_resolve_via_pivot` ajouté.
- [x] 5.3 `AdSyncChecker:341` (FK → `physicalRoom?->id`), `WorkstationGroupRepository:319` (`whereNull('physical_room_id')` retiré, couvert par `whereDoesntHave('groups')`) ; `ShortcutCompilerService` lit déjà `groups()` (aucun changement).
- [x] 5.4 UI `addMachinesToGroup` (parc/index) : route les salles vers le service de swap (AC8) ; blade `machines/[id]` `physical_room_id` → `physicalRoom?->id` ; commentaires `groups/[id]` corrigés.

### Tâche 6 — iPXE (AC: 5)
- [x] 6.1 `WorkstationLocator` : eager load `physicalRoom` → `physicalRooms` (relation pivot filtrée).
- [x] 6.2 `IpxeEnrollmentMenuBuilder::buildRoomMenuVariables` : `$ws->physical_room_id` → `$ws->physicalRoom` (pivot) ; `is_current` inchangé.
- [x] 6.3 `IpxeWindowsUnattendController` : transparent (`getAdOu` → `physicalRoom?->ad_dn`).

### Tâche 7 — Tests (AC: 9)
- [x] 7.1 Migration : `UnifyMembershipPivotTest` (backfill, idempotence double-up, skip orphelins, down()).
- [x] 7.2 Service swap : `PhysicalRoomSwapTest` (transactionnel, 1-salle-max, préservation parcs, null-detach, dispatch + anti-double-dispatch via Bus::fake, non-physique throws, conflit lit pivot).
- [x] 7.3 Consommateurs : WPKG-via-salle (`WorkstationPackagesResolverArchivedTest`), GPO context (test existant déjà pivot).
- [x] 7.4 Fixtures migrées (FK → pivot) : `WorkstationEnrollmentServiceTest`, `IpxeEnrollmentMenuBuilderTest`, `IpxeEnrollmentRoomEndpointTest`, `IpxeWindowsUnattendEndpointTest`, `WorkstationLocatorTest` ; les 3 `GroupShowPageTest` passent.
- [x] 7.5 Suite complète VM : 3 failed (préexistants byte-parity hors scope) / 3667 passed — 0 régression.

### Tâche 8 — Documentation (AC: 10)
- [x] 8.1 `docs/qa/domains/parc.md` créé (append-only) : modèle d'appartenance unifié + invariant D3 app-only + critère de réouverture + sonde + runbook migration/rollback. README mis à jour.

## Dev Notes

### Décisions actées (Henri, 2026-06-04)

- **D1 — Pivot global, pas de double écriture.** La colonne pivot `physical` historique a déjà été jugée morte et supprimée par migration ; ne pas la réintroduire en mode « écho de FK ». Une seule source de vérité : le pivot.
- **D2 — Invariant « 1 salle max » = règle de service.** Swap transactionnel dans `WorkstationGroupService` ; toutes les écritures de salle (UI, iPXE, imports) passent par ce point unique.
- **D3 — Invariant app-only (acté Henri 2026-06-04).** Pas de contrainte DB : pas de colonne `is_physical` dénormalisée sur le pivot, pas d'index unique partiel. La règle « 1 salle max » vit uniquement dans le swap transactionnel du service (D2), qui est le point d'écriture unique après suppression des chemins de traverse (Tâche 4). Rationale : un index partiel Postgres ne peut pas référencer `workstation_groups.is_physical` (autre table) — le filet DB exigerait de réintroduire une colonne pivot redondante, jugée non justifiée tant qu'aucun incident de double-salle n'est observé. Critère de réouverture documenté en doc QA.
- **D4 — `physicalRoom` reste l'API de lecture.** Les ~10 call-sites iPXE continuent d'appeler `$ws->physicalRoom` / `machineObjectOu` ; seule l'implémentation change (pivot filtré au lieu de belongsTo FK). Minimise le diff iPXE.

### Contraintes architecturales (non négociables)

- **Aucune logique d'union FK+pivot ne doit survivre** — c'est le but de la story. Si un consommateur semble en avoir besoin, c'est que la migration est incomplète.
- **Écriture de salle uniquement via le service** (cf. D2). Supprimer `Workstation::assignToPhysicalRoom()` plutôt que le laisser comme piège.
- **Ne pas toucher au fork Guacamole ni au legacy** (`../sambaedu/`).
- **Auth machine iso-legacy** : rien dans cette story ne touche l'auth.
- **Coordination 4-9/4-10 (en review)** : 4-9 a supprimé les hooks pivot de `WorkstationObserver` (D4 de 4-9) et introduit `WorkstationAdSyncJob` (rename/status/delete — PAS le move OU). Ne pas réintroduire de hooks pivot dans l'observer ; le dispatch move se fait dans le service (Tâche 4.3). Si 4-9 bouge pendant la review, re-vérifier `WorkstationObserver`.
- **Migration Postgres uniquement** (prod SE5) ; `_archive_mysql/` ne se migre pas.

### Code existant à réutiliser (anti-réinvention)

| Besoin | Fichier/Classe | Notes |
|---|---|---|
| Validation salle + conflit | `WorkstationGroupService::assignMachineToPhysicalRoom/checkPhysicalRoomConflict/moveMachineToPhysicalRoom` (l.1010-1100) | Garder l'API publique, réécrire l'intérieur |
| Job move OU | `app/Jobs/AdSync/WorkstationMembershipAdSyncJob.php` (`::move`) | Existant, jamais dispatché — câbler, pas réécrire |
| Accessors transitoires | `WorkstationGroup::getMembersAttribute/getMembersCountAttribute` | Simplifier, pas supprimer (API UI) |
| Pattern test observation | `tests/Feature/Observers/` + `Bus::fake` (cf. story 4-9) | Pour AC7 |
| Écriture iPXE salle | `WorkstationEnrollmentService::assignRoom` (l.393+) | Déléguer au service, garder logs `ipxe.enrollment.room.*` + `persistMachineBootLog` |

### Pitfalls connus et points d'attention

- **`whereHas('groups')` scoping (story 7.1)** : `getMachinesScoped` restreint par groupes autorisés — après migration, un user scoped sur une salle voit ses machines via le pivot. C'est le comportement désiré, mais vérifier que ça n'élargit pas un scope par accident (un poste dans salle autorisée + parc non autorisé reste visible — OK, il l'était par la salle).
- **Eager loading `physicalRoom`** : une relation `belongsToMany` filtrée se charge avec `->with('physicalRoom')` mais retourne une **collection** ; l'accessor singulier doit faire `->first()`. Attention aux `$ws->physicalRoom->name` existants (null-safe déjà présents : `physicalRoom?->ad_dn`).
- **`normalizeGroups` iPXE** (`IpxeEnrollmentMenuBuilder:223`) : sanitize ASCII sur `display_name` — inchangé, mais les fixtures de `IpxeEnrollmentMenuBuilderTest` écrivent la FK.
- **Backfill et postes orphelins** : `physical_room_id` pointant vers un groupe supprimé/archivé — le backfill doit joindre `workstation_groups` pour ne pas créer de lignes pivot orphelines (FK pivot violée sinon).
- **Méthodes d'attache génériques** : `WorkstationGroup::attachWorkstations/syncWorkstations` (l.208-235) et `Workstation::groups()->attach/sync` deviennent des chemins capables de poser une 2e salle. Soit les garder logiques-only (guard `!is_physical` + exception), soit router les salles vers le service de swap — trancher et tester (un attach direct d'une salle en double doit être impossible ou détecté).
- **`GroupShowPageTest`** : les 3 échecs préexistants (2026-06-04) viennent de fixtures pivot sur salle physique — ils redeviennent verts naturellement ; ne pas « corriger » ces fixtures vers la FK entre-temps.
- **inotify ne propage pas les deletes** : si la migration supprime des fichiers, vérifier les fantômes sur la VM avant de tester (demander à Henri avant cleanup SSH).
- **Vendor/lock** : pas de dépendance nouvelle attendue ; si composer.lock bouge, réinstaller vendor sur l'hôte avec `--ignore-platform-req=ext-apcu --ignore-platform-req=ext-imagick`.

### Source tree — fichiers prévus impactés

```
# À CRÉER
database/migrations/2026_06_XX_unify_workstation_membership_pivot.php
tests/Feature/Migrations/UnifyMembershipPivotTest.php            # ou pattern migration-test du projet
tests/Unit/Services/Parc/PhysicalRoomSwapTest.php

# À MODIFIER — modèles
app/Models/Workstation.php                                       # physicalRoom pivot, hasPhysicalRoom, -assignToPhysicalRoom, -fillable/cast FK
app/Models/WorkstationGroup.php                                  # members/members_count/workstation_count → workstations(), -physicalWorkstations

# À MODIFIER — services
app/Services/Parc/WorkstationGroupService.php                    # swap transactionnel + dispatch move (l.1010-1100)
app/Ipxe/Services/WorkstationEnrollmentService.php               # assignRoom délègue (l.379-435)
app/Services/AdSync/AdSyncChecker.php                            # l.332-341 lecture unifiée

# À MODIFIER — iPXE lecture
app/Ipxe/Services/WorkstationLocator.php                         # eager load l.79
app/Ipxe/Services/IpxeEnrollmentMenuBuilder.php                  # buildRoomMenuVariables l.91-136

# À VÉRIFIER (probablement transparents si D4 respecté)
app/Ipxe/Http/Controllers/IpxeWindowsUnattendController.php      # machineObjectOu l.141
app/Gpo/Services/WorkstationConfigContextResolver.php            # l.216-236
app/Wpkg/Deployment/Queries/ActiveDeploymentForWorkstationQuery.php
app/Wpkg/Deployment/Services/WorkstationPackagesResolver.php
app/Services/ShortcutCompilerService.php
app/Repositories/WorkstationGroupRepository.php                  # l.150, 217, 319 (whereNull physical_room_id → whereDoesntHave)

# À MODIFIER — UI
resources/views/pages/parc/_partials/machines-tab.blade.php      # modale addMachinesToGroup → service swap pour salles
resources/views/pages/parc/machines/[id]/index.blade.php         # drawer change-physical-room l.911-920
resources/views/pages/parc/groups/[id]/index.blade.php           # commentaires l.189/222 + handlers attach/detach salle

# TESTS — fixtures à migrer (FK → pivot)
tests/Unit/LdapShimTest.php
tests/Unit/Ipxe/Services/{IpxeEnrollmentMenuBuilderTest,WorkstationEnrollmentServiceTest,WorkstationLocatorTest}.php
tests/Unit/Services/{WorkstationGroupServicePowerActionTest,Parc/WorkstationGroupScheduleServiceTest,Parc/WorkstationGroupServiceScopingTest}.php
tests/Unit/Gpo/Services/WorkstationConfigContextResolverTest.php
tests/Feature/Ipxe/IpxeEnrollmentRoomEndpointTest.php
tests/Feature/Livewire/Parc/{GroupSchedulesPageTest,GroupShowPageTest}.php

# FIN DE STORY
_bmad-output/implementation-artifacts/sprint-status.yaml         # → review
docs/qa/domains/                                                  # doc modèle + runbook (append-only)
```

### Tests standards du projet

- Exécution sur VM (`/vm` : `ssh -i ~/.ssh/id_se4fs_vm root@192.168.122.50`, projet `/var/www/sambaedu-reload`) — jamais depuis un worktree git.
- `Bus::fake([WorkstationMembershipAdSyncJob::class])` pour AC7 ; pas de connexion AD/LDAP réelle en CI.
- Les tests Livewire du projet montent les composants pages SFC (`resources/views/pages/...`) — cf. `GroupShowPageTest` pour le pattern (tables créées en setUp si schéma de test incomplet).
- Baseline avant story : `php artisan test` complet sur VM, noter les échecs préexistants (au 2026-06-04 : 3 dans `GroupShowPageTest`).

### References

- [Source: memory/project_pivot_global_memberships.md] — décision et rationale
- [Source: _bmad-output/planning-artifacts/epics.md#Epic 4 (l.1446)] — périmètre epic
- [Source: app/Models/WorkstationGroup.php:155-210] — relations + accessors transitoires 2026-06-04
- [Source: app/Services/Parc/WorkstationGroupService.php:1010-1100] — chemin d'écriture actuel
- [Source: app/Ipxe/Services/WorkstationEnrollmentService.php:379-435] — assignRoom + docblock propagation à corriger
- [Source: app/Jobs/AdSync/WorkstationMembershipAdSyncJob.php] — job move jamais dispatché
- [Source: _bmad-output/implementation-artifacts/4-9-sync-ad-machine-observer-ldaprecord.md] — D4 (hooks pivot supprimés), pattern observer/job, contrainte « conflit avec WorkstationMembershipAdSyncJob »
- [Source: database/migrations/2026_02_04_160821_add_physical_to_workstation_group_workstation_table.php] — historique colonne pivot `physical` (morte)

### Previous Story Intelligence (4-9, en review)

- 4-9 a établi le pattern `WorkstationObserver::withoutSync(callable)` comme unique bypass — si la migration/backfill écrit des `Workstation`, l'utiliser pour ne pas déclencher de jobs AD parasites pendant le backfill.
- 4-9 D4 : les hooks pivot de l'observer sont supprimés — le dispatch AD de cette story vit dans le **service**, pas dans un observer pivot.
- 4-9 pitfall « Conflit avec WorkstationMembershipAdSyncJob » anticipait déjà la zone de friction : vérifier qu'un enrôlement iPXE neuf (création + salle) ne dispatch pas le move deux fois.
- Learnings process : tests d'observation avec `Bus::fake`, validation finale sur VM, doc QA append-only.

## Dev Agent Record

### Agent Model Used

Claude Opus 4.8 (1M context) — `claude-opus-4-8[1m]`.

### Debug Log References

- Baseline VM (avant story) : `6 failed, 9 risky, 1 incomplete, 194 skipped, 3652 passed` — 3 `GroupShowPageTest` (cibles AC9) + 3 `ApplicationsScriptsByteParityTest` (hors scope).
- Final VM : `3 failed, 9 risky, 1 incomplete, 194 skipped, 3667 passed` — seuls les 3 `ApplicationsScriptsByteParityTest` préexistants subsistent (legacy byte parity, hors scope) ; les 3 `GroupShowPageTest` sont verts.
- Migration validée sur PG (VM) : colonne `physical_room_id` droppée, backfill peuplant le pivot, `physicalRoom` accessor lisant le pivot OK.

### Completion Notes List

**Audit Tâche 1 — gap OU AD CONFIRMÉ.** `WorkstationMembershipAdSyncJob::move` n'était dispatché nulle part dans `app/` ; le canal `AdSyncService::moveMachineToSalle` existe mais sans déclencheur. Le déplacement OU AD au changement de salle n'était donc pas propagé. AC7 le câble dans le point d'écriture unique (`WorkstationGroupService::assignMachineToPhysicalRoom`), gardé contre le double-dispatch (`oldRoomId !== roomId`).

**Décisions / écarts en cours de route :**
- **Consommateurs WPKG/GPO/shortcut sans changement de code.** L'inventaire a confirmé que `ActiveDeploymentForWorkstationQuery`, `WorkstationPackagesResolver`, `ShortcutCompilerService` et les filtres `WorkstationGroupRepository::getMachines(Scoped)` lisent **déjà** `groups()` (pivot). Ils gagnent la correction automatiquement dès que la salle entre dans le pivot — seuls des tests ont été ajoutés (pas de code). Cela confirme le diagnostic de la story (le split piégeait le cas majoritaire).
- **Accessor singulier `physicalRoom` = attribut, pas relation.** La relation pivot filtrée est `physicalRooms()` (collection) ; l'accessor `getPhysicalRoomAttribute` extrait `->first()` et réutilise la relation eager-loadée si présente (anti N+1). L'API de lecture `$ws->physicalRoom?->...` reste identique (D4). Les call-sites d'eager-load (`WorkstationLocator`) passent à `physicalRooms`.
- **Colonne pivot `physical` (morte) laissée intacte.** Contrairement à ce qu'affirmaient les Dev Notes (« supprimée par migration »), la colonne `physical` (bool, default false) est **toujours présente** sur la VM. Conformément à D1 (ne pas la réintroduire comme écho de FK), je ne l'utilise pas et ne la touche pas — hors scope. Les fixtures de test qui font `attach($id, ['physical' => true])` continuent de fonctionner (colonne présente, valeur ignorée par le modèle).
- **Pas de guard throwing sur `WorkstationGroup::attachWorkstations`.** L'invariant 1-salle-max est app-only (D3), imposé aux points d'écriture UI/iPXE/imports via le swap service. Ajouter un throw dans la méthode générique d'attache aurait cassé le `LegacyParcBridgeService` et de nombreux tests qui attachent à des groupes quelconques via `->workstations()->attach()`. La sonde de détection (doc QA Scénario 1.3) couvre le risque résiduel.
- **`WorkstationSeeder` migré** : la salle est désormais attachée via le pivot (`$workstation->groups()->attach($room->id)`).

**AC couverts :** AC1 (migration backfill + down, testé), AC2 (`physicalRoom` accessor pivot + `groups` unifiés), AC3 (swap transactionnel, testé), AC4 (consommateurs réparés sans union, WPKG-via-salle testé), AC5 (iPXE iso-comportement, fixtures migrées, tests verts), AC6 (accessors délégués + `physicalWorkstations`/`assignToPhysicalRoom` supprimés), AC7 (dispatch move câblé + Bus::fake), AC8 (modale addMachinesToGroup route les salles vers le swap), AC9 (0 régression, 3 GroupShowPageTest verts), AC10 (doc QA `parc.md` + runbook).

### File List

**Créés**
- `database/migrations/2026_06_04_120000_unify_workstation_membership_pivot.php`
- `tests/Feature/Migrations/UnifyMembershipPivotTest.php`
- `tests/Unit/Services/Parc/PhysicalRoomSwapTest.php`
- `docs/qa/domains/parc.md`

**Modifiés — code**
- `app/Models/Workstation.php` (physicalRooms pivot + accessor, hasPhysicalRoom, -assignToPhysicalRoom, -fillable/cast/docblock FK, -import BelongsTo)
- `app/Models/WorkstationGroup.php` (members/members_count délèguent à workstations(), -physicalWorkstations)
- `app/Services/Parc/WorkstationGroupService.php` (swap transactionnel + dispatch move, checkPhysicalRoomConflict lit pivot, import du job)
- `app/Ipxe/Services/WorkstationEnrollmentService.php` (assignRoom délègue au service, docblock corrigé)
- `app/Ipxe/Services/WorkstationLocator.php` (eager load physicalRoom → physicalRooms)
- `app/Ipxe/Services/IpxeEnrollmentMenuBuilder.php` (currentRoom lu via pivot)
- `app/Services/AdSync/AdSyncChecker.php` (physical_room_id → physicalRoom?->id)
- `app/Repositories/WorkstationGroupRepository.php` (getMachinesWithoutGroup : retrait whereNull physical_room_id)

**Modifiés — UI**
- `resources/views/pages/parc/index.blade.php` (addMachinesToGroup route les salles vers le swap)
- `resources/views/pages/parc/machines/[id]/index.blade.php` (physical_room_id → physicalRoom?->id)
- `resources/views/pages/parc/groups/[id]/index.blade.php` (commentaires corrigés)

**Modifiés — seeder / doc**
- `database/seeders/WorkstationSeeder.php` (attache salle via pivot)
- `docs/qa/README.md` (entrée parc.md)

**Modifiés — tests (fixtures FK → pivot / fakes)**
- `tests/Unit/Ipxe/Services/WorkstationEnrollmentServiceTest.php`
- `tests/Unit/Ipxe/Services/IpxeEnrollmentMenuBuilderTest.php`
- `tests/Unit/Ipxe/Services/WorkstationLocatorTest.php`
- `tests/Feature/Ipxe/IpxeEnrollmentRoomEndpointTest.php`
- `tests/Feature/Ipxe/IpxeWindowsUnattendEndpointTest.php`
- `tests/Feature/Wpkg/Deployment/Services/WorkstationPackagesResolverArchivedTest.php`

**Supprimés** : aucun (aucun fichier supprimé → pas de fantôme inotify sur la VM).

## Change Log

| Date | Version | Description | Auteur |
|---|---|---|---|
| 2026-06-04 | 1.0 | Implémentation Story 4.11 — unification de l'appartenance poste↔groupe dans le pivot global (`workstation_group_workstation`), suppression FK `physical_room_id` (migration backfill idempotent + down), swap transactionnel app-only (D3) dans `WorkstationGroupService`, câblage du dispatch `WorkstationMembershipAdSyncJob::move` (gap OU AD comblé), consommateurs réparés (WPKG/GPO/shortcut/filtres lisent déjà le pivot), iPXE iso-comportement, doc QA `parc.md`. 3 GroupShowPageTest verts, 0 régression. | Dev Agent (Opus 4.8) |
| 2026-06-04 | 1.1 | Corrections post-review (review Sonnet + second avis Opus, cf. `codeReviews/4-11.md`) : #1 eager-load `groups`+`physicalRooms` dans `AdSyncChecker` (N+1), #2 race `oldRoomId` acceptée + documentée (commentaire), #6 docblock `getWorkstationCountAttribute` mis à jour, #7 test e2e dispatch unique via `assignRoom` (enrôlement neuf). En attente arbitrage : #3 `importFromAd` (bug latent guard `wherePivot`), #4 DDL tests résiduels, #N1 conventions pivot `physical`. | Review fix (Opus 4.8) |
| 2026-06-04 | 1.2 | Arbitrages review résolus : #3 `importFromAd` 3e passe routée vers le swap du service (`dispatchAdSync: false`, nouveau param), #N1 plus aucune écriture de la colonne pivot morte `physical`, #4 `physical_room_id` purgé des 15 DDL SQLite de test. Suite complète VM : 3688 passed / 3 failed (ByteParity préexistants hors scope), 0 régression. | Review fix (Opus 4.8) |

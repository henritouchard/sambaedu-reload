# Story 4.11 : Unification de l'appartenance poste↔groupe dans le pivot global (suppression FK `physical_room_id`)

Status: ready-for-dev

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
- [ ] 1.1 Tracer le canal réel de propagation OU AD lors d'un changement de salle (grep `MembershipAdSyncJob`, `modrdn`, `move`, hooks 4-9) : confirmer le gap ou identifier le canal vivant.
- [ ] 1.2 Inventaire exhaustif final des call-sites `physical_room_id|physicalRoom|physicalWorkstations|assignToPhysicalRoom` (app/, resources/, tests/, database/) — base : inventaire du 2026-06-04 en Dev Notes.
- [ ] 1.3 Vérifier les écritures de FK côté imports (CSV story 4-5, import AD `WorkstationGroupService` section migration initiale, `SyncWorkstationGroupsFromAd`).

### Tâche 2 — Migration de schéma + backfill (AC: 1)
- [ ] 2.1 Migration : backfill `INSERT INTO workstation_group_workstation (workstation_id, workstation_group_id, created_at, updated_at) SELECT w.id, w.physical_room_id, NOW(), NOW() FROM workstations w JOIN workstation_groups g ON g.id = w.physical_room_id WHERE w.physical_room_id IS NOT NULL ON CONFLICT (workstation_group_id, workstation_id) DO NOTHING` — idempotent grâce à la contrainte unique `wg_ws_unique` (vérifiée présente sur la VM 2026-06-04) ; le JOIN écarte les FK orphelines ; les timestamps doivent être posés explicitement (insert SQL brut, pas Eloquent).
- [ ] 2.2 Drop `workstations.physical_room_id` (+ index/contrainte FK associés). `down()` : recrée la colonne et la repeuple depuis le pivot (`is_physical=true`).
- [ ] 2.3 D3 actée app-only (Henri 2026-06-04) : PAS de colonne `is_physical` dénormalisée sur le pivot, PAS d'index partiel. Documenter le choix dans la doc QA (Tâche 8) avec le critère de réouverture : durcir via index dénormalisé uniquement si un incident de double-salle survient.

### Tâche 3 — Modèles (AC: 2, 6)
- [ ] 3.1 `Workstation::physicalRoom` → relation pivot filtrée (`belongsToMany(...)->wherePivot`... non : `->where('workstation_groups.is_physical', true)`) + accessor singulier ; `machineObjectOu` inchangé dans sa signature (`physicalRoom?->ad_dn`).
- [ ] 3.2 `Workstation::hasPhysicalRoom()` réécrit sur le pivot ; suppression `assignToPhysicalRoom()` (écriture = service uniquement).
- [ ] 3.3 `WorkstationGroup` : `members`/`members_count`/`workstation_count` délèguent à `workstations()` ; suppression `physicalWorkstations()`.
- [ ] 3.4 Casts/fillable : retirer `physical_room_id`.

### Tâche 4 — Service de swap transactionnel (AC: 3, 7, 8)
- [ ] 4.1 `WorkstationGroupService::assignMachineToPhysicalRoom()` réécrit : `DB::transaction` { detach des salles physiques courantes (jointure `is_physical=true`) + attach cible } ; `$roomId=null` = detach seul.
- [ ] 4.2 Conserver la validation existante (salle inexistante / non physique → `InvalidArgumentException`) et `checkPhysicalRoomConflict`/`moveMachineToPhysicalRoom` (lecture pivot).
- [ ] 4.3 Dispatch `WorkstationMembershipAdSyncJob::move` post-commit (selon résultat Tâche 1.1).
- [ ] 4.4 `WorkstationEnrollmentService::assignRoom` (iPXE) délègue au service (plus d'écriture directe modèle) ; mettre à jour le docblock mensonger l.379-392.

### Tâche 5 — Consommateurs réparés (AC: 4)
- [ ] 5.1 `WorkstationConfigContextResolver` : supprimer tout doute — `groups()` contient désormais la salle ; vérifier l'heuristique « premier `is_physical=true` » toujours valide.
- [ ] 5.2 WPKG (`ActiveDeploymentForWorkstationQuery`, `WorkstationPackagesResolver`) : aucun changement de code attendu (ils lisent déjà `groups`) — ajouter les tests prouvant qu'une salle porteuse de profil se déploie.
- [ ] 5.3 `ShortcutCompilerService`, `AdSyncChecker`, filtres `WorkstationGroupRepository` : idem — vérifier + tester.
- [ ] 5.4 UI `machines-tab` modale addMachinesToGroup : router les salles vers le service de swap (AC8) ; drawer « change-physical-room » de `machines/[id]` : lecture pivot.

### Tâche 6 — iPXE (AC: 5)
- [ ] 6.1 `WorkstationLocator` : eager load adapté (`groups` suffit, retirer `physicalRoom` ou le garder comme relation pivot filtrée).
- [ ] 6.2 `IpxeEnrollmentMenuBuilder::buildRoomMenuVariables` : `$ws->physical_room_id` → lecture pivot ; `is_current` inchangé.
- [ ] 6.3 `IpxeWindowsUnattendController` : `machineObjectOu` (transparent si 3.1 bien fait).

### Tâche 7 — Tests (AC: 9)
- [ ] 7.1 Migration : test backfill idempotent + down().
- [ ] 7.2 Service swap : transactionnel, 1-salle-max, null-detach, dispatch AD (Bus::fake).
- [ ] 7.3 Consommateurs : WPKG-via-salle, GPO context, filtre repository, shortcut.
- [ ] 7.4 Migrer les fixtures des fichiers listés en Dev Notes ; vérifier que les 3 échecs préexistants `GroupShowPageTest` passent.
- [ ] 7.5 Suite complète sur VM (`/vm`) : 0 régression.

### Tâche 8 — Documentation (AC: 10)
- [ ] 8.1 `docs/qa/domains/` : modèle d'appartenance unifié + runbook migration/rollback (append-only).

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

### Debug Log References

### Completion Notes List

### File List

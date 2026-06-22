# Story 27.14 : Extinction du canal legacy en bloc — PRÉPARER sans supprimer

Status: ready-for-dev

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

> **ℹ️ IDENTITÉ & NUMÉROTATION (tranché Henri 2026-06-19).**
> Cette story est l'**« Extinction du canal legacy en bloc »**. Elle **supersede la story `15-7`**
> (`15-7-bascule-production-retrait-shim-wpkg-legacy`, annulée le 2026-06-19) et reprend les **Acceptance Criteria
> de l'épic `Story 27.6 : Extinction du canal legacy`**
> (`_bmad-output/planning-artifacts/epics-agent-desired-state.md`, ~L774). Le numéro `27.14` a été **réaffecté**
> par Henri : le « localgroup / admin local session-scoped » évoqué dans la note de tête de la story `27.12`
> (qui pointait `27.14`) **prendra un AUTRE numéro** — ne pas le traiter ici.
>
> ⚠️ **NE PAS CONFONDRE.** Dans `sprint-status.yaml`, la ligne `27-6-catalogue-wpkg-source-unique-module` est une
> AUTRE story (catalogue WPKG, source unique du bundle). « 27.6 » dans **l'épic** = l'extinction du canal legacy =
> CETTE story `27.14`.

## ✅ CADRAGE TRANCHÉ PAR HENRI (2026-06-19) — NE PAS RE-DÉBATTRE

> Décisions d'amont. **Procéder sans re-demander.**
>
> **D1 — SCOPE = PRÉPARER SANS SUPPRIMER.** Le gate de **parité de compétences** (palier 3 : validation lab sur
> postes migrés ET neufs, salles multiples) **n'est PAS encore validé**. Donc cette story **ne supprime AUCUN
> code** et **ne flippe AUCUNE route**. Elle produit **trois livrables documentaires** (A / B / C, ci-dessous).
> L'exécution réelle du retrait sera une **story/passe ULTÉRIEURE**, une fois la parité démontrée.
>
> **D2 — Le kill-switch coupe DÉJÀ le canal au runtime.** `LEGACY_CONFIG_CHANNEL_ENABLED=false` (activé /vm le
> 2026-06-12) neutralise déjà `/api/v1/workstation-config/*`, `/wpkg/*` (linux_out/winget_out/profiles.xml/hosts.xml)
> et `/gpo/*_out.php` (→ 410 / no-op). **L'inventaire prépare le retrait du CODE, pas la coupure runtime** (faite).
>
> **D3 — La GPO-dispatcher bootstrap (Story 25.4) SURVIT.** Figée, jamais ré-éditée — dernier artefact AD, filet
> éternel qui réinstalle/répare l'agent (FR25). Elle doit être **distinguée explicitement** dans l'inventaire
> comme « à NE PAS retirer ».
>
> **D4 — Distinguer le canal WPKG SAIN du legacy MySQL.** Le **canal classique WPKG pgsql** (ingestion des
> rapports `WpkgReportController`, catalogue `packages.xml` statique servi par Apache, UI déploiement,
> `WpkgReportIngestionService` des stories 9-4/9-5) est **SAIN et porté** → **NE PAS lister à retirer**. Seuls
> `packages_xml_out.php` (MySQL, **non porté**, vit dans le repo legacy externe) et les surfaces
> `linux_out`/`winget_out`/`profiles.xml`/`hosts.xml` gatées `legacy.config.channel` sont du legacy à retirer.
>
> **D5 — Zéro prod.** Pas de risque utilisateur réel (`project_zero_prod_publish_is_test`). Mais on **ne supprime
> quand même PAS** tant que la parité lab n'est pas démontrée (D1) : « publier = tester » s'applique au geste de
> bascule, pas à la suppression de code spéculative.

## Story

En tant que **mainteneur SambaEdu / Scrum Master**,
je veux **produire la checklist de parité de compétences, l'inventaire exhaustif des artefacts legacy à retirer, et
le plan d'extinction ordonné — SANS supprimer ni modifier le moindre code legacy**,
afin que, le jour où la parité lab sera démontrée (palier 3), l'extinction du canal legacy puisse s'exécuter **en
bloc, sans archéologie ni risque d'oubli**, et que SE5 ne porte plus qu'**UN** système de configuration des postes.

## Contexte & intention

**D'où on part.** Le canal de configuration legacy livre encore (au niveau du CODE — il est coupé au runtime depuis
le 2026-06-12) le wallpaper, les raccourcis, les lecteurs/imprimantes, les associations, le registre, la config
d'app et les applications, via :
- les contrôleurs `/api/v1/workstation-config/*` (groupe de routes nommé `agent.v1.config.*`, gaté
  `legacy.config.channel` — **piège : ce nom de route contient `agent` mais c'est le canal LEGACY**, distinct du
  vrai canal agent `/api/v1/agent/*`) ;
- les générateurs/assembleurs de scripts `app/Gpo/Services/*` et le matching de contexte par tags `list_*` ;
- les surfaces WPKG legacy `/wpkg/{linux_out,winget_out}.php`, `profiles.xml`, `hosts.xml` ;
- les modules PHP legacy servis par le bridge (`legacy/modules/gpo/*`) ;
- les templates GPO serveur (`/usr/share/sambaedu/gpo/*.zip`).

**Ce que la série 27.x a porté.** Chaque capacité legacy a désormais un équivalent sur le **canal agent
desired-state** (handlers Go + providers PG-purs), démontré en lab et documenté dans `docs/qa/domains/agent.md` :
raccourcis (27.1), overlay/wallpaper (24.4/27.1bis/27.1ter), lecteurs & imprimantes (27.2), registre/capacités
(27.3 → 27.3ter → 27.12), associations (27.3bis/27.11), config d'app (27.4), applications/WPKG (27.5), nature de
poste (26.1). Le canal agent est **indépendant** (`/api/v1/agent/*`, bearer Sanctum) et déjà à parité de démo.

**Ce que cette story fait.** Elle **fige la connaissance** nécessaire au retrait : (A) la grille de validation de
parité (le gate du palier 3), (B) le recensement précis de TOUT ce qu'il faudra retirer (chemin:ligne + rôle +
remplaçant + ordre/risque), (C) la séquence d'extinction et ses critères de gate. **Rien n'est supprimé** : la
preuve en est qu'un `git diff` de cette story ne touche **que de la documentation**.

**Ce que cette story N'EST PAS :**
- Le **retrait effectif** du code legacy → passe ULTÉRIEURE, après parité validée (D1).
- La **coupure runtime** du canal → déjà faite par le kill-switch (D2).
- Une **réécriture** d'un quelconque handler agent ou du contrat → intouchés.
- Le **localgroup / admin local** → autre numéro de story (cf. note d'identité).

## Livrables (A / B / C) — emplacements décidés

| Livrable | Contenu | Fichier (chemin décidé) |
|---|---|---|
| **A — Checklist de parité de compétences** | Tableau « capacité legacy → équivalent canal agent (story 27.x) → statut parité (à valider / validé) → comment le démontrer en lab (renvoi à la section `agent.md`) » | **`docs/qa/domains/legacy-shims.md`** (création du domaine — déjà planifié dans `docs/qa/README.md` L26) |
| **B — Inventaire exhaustif des artefacts legacy à retirer** | Pour CHAQUE artefact : `chemin:ligne` + rôle + remplacé-par + sain-à-conserver/legacy-à-retirer + risque/ordre | **`docs/legacy-extinction-plan.md`** (section « Inventaire ») |
| **C — Plan d'extinction ordonné** | Séquence de retrait (dépendances), critères de gate, commandes d'audit de vérification, points d'attention | **`docs/legacy-extinction-plan.md`** (section « Plan d'extinction ») |

> **Choix d'emplacement (assumé) :** A va dans un **nouveau domaine QA `legacy-shims.md`** parce que la parité est
> un **gate de pré-prod** (le README QA prévoyait déjà ce domaine, L26 : « routes catchall legacy, modules GPO /
> iPXE / DHCP / BBB / imprimantes »). B+C vont dans **un seul** doc `docs/legacy-extinction-plan.md` (hors
> `docs/qa/`, car ce n'est pas un runbook de test manuel mais un plan d'ingénierie). A renvoie à B/C pour le « quoi
> retirer ». **Mettre à jour la table « Domaines couverts » de `docs/qa/README.md`** (ligne pour `legacy-shims`) et
> **cocher `[x]` la ligne L26** « À créer au fil des prochaines stories ».

## Acceptance Criteria

> **Format scope « préparer sans supprimer » :** les AC portent sur la **PRÉSENCE** et l'**EXHAUSTIVITÉ** des trois
> livrables, PAS sur une suppression effective.

1. **AC1 — Aucune suppression/modification de code legacy.** Le `git diff` de cette story ne touche **QUE** de la
   documentation : `docs/qa/domains/legacy-shims.md` (créé), `docs/legacy-extinction-plan.md` (créé),
   `docs/qa/README.md` (table domaines + coche), `_bmad-output/implementation-artifacts/sprint-status.yaml`
   (statut), et le fichier de cette story. **Aucun fichier sous `app/`, `routes/`, `config/`, `tests/`,
   `legacy/`, `database/` n'est créé, modifié ou supprimé.** (Preuve : `git diff --name-only` ⊆ liste ci-dessus.)

2. **AC2 — Checklist de parité (livrable A) complète.** `docs/qa/domains/legacy-shims.md` existe et contient un
   tableau couvrant **AU MINIMUM** ces capacités : wallpapers, raccourcis, lecteurs réseau, imprimantes (+
   imprimante par défaut), associations de fichiers, registre/capacités, config d'app (policies.json / profils
   navigateur), applications (WPKG), ciblage par critères (nature de poste / mailles). **Chaque ligne** mappe la
   capacité vers la **story 27.x** qui l'a portée, un **statut de parité** (« à valider » / « validé »), et un
   **renvoi explicite** à la section de `docs/qa/domains/agent.md` qui prouve la capacité en lab. Toute capacité
   non encore validée en environnement réaliste (postes migrés + neufs, salles multiples) est marquée « à valider »
   — le passage à « validé » est le **gate du palier 3** (cf. épic 27.6 AC1).

3. **AC3 — Inventaire exhaustif (livrable B).** `docs/legacy-extinction-plan.md` recense, avec
   **`chemin:ligne`** précis, AU MINIMUM les artefacts suivants, chacun annoté `rôle` / `remplacé-par` /
   `legacy-à-retirer` OU `sain-à-conserver` / `risque-ordre` :
   - `app/Gpo/Services/ApplicationScriptsGenerator.php` et `ApplicationScriptsAssembler.php` (+ leurs consommateurs) ;
   - le matching par tags `list_*` (`app/Gpo/Services/WorkstationConfigContextResolver.php`) ;
   - le groupe de routes `/api/v1/workstation-config/*` (`routes/api.php` ~L396-411) et ses 9 contrôleurs
     (`WallpaperController`, `OverlayController`, `AppPolicyController`, `ShortcutExportController`,
     `NetworkOutController`, `VeyonOutController`, `AssociationsOutController`, `ApplicationsScriptsController`) ;
   - le groupe `/api/v1/shortcuts/export` (`routes/api.php` ~L411) ;
   - les surfaces WPKG legacy gatées `legacy.config.channel` : `/wpkg/linux_out.php` + `/wpkg/winget_out.php`
     (`routes/web.php` ~L739/L747, contrôleurs `LinuxOutController`/`WingetOutController`) + les vestiges
     `profiles.xml`/`hosts.xml` (cf. note web.php ~L700-711) ;
   - le bridge legacy et ses modules de config : `app/Http/Controllers/LegacyCatchallController.php`,
     `legacy/modules/gpo/*` (`applications.php`, `associations_out.php`, `network_out.php`, `veyon_out.php`,
     `gpo-maj.php`, `gpo-export.php`, …), `legacy/bootstrap.php`, stubs et shims associés ;
   - le **shim 1bis-11** (référencé dans `legacy/bootstrap.php` et les artefacts planning) ;
   - le dossier `sambaedu/wpkg/` du **repo legacy externe** (hors repo SE5 — recensé comme **archivage**, dont
     `packages_xml_out.php` MySQL **non porté**) ;
   - l'**arbitrage de retrait** des composants des stories `9-4`/`9-5` (`app/Services/Windows/WpkgReportIngestionService.php`,
     `IngestionResult.php`) : trancher dans le plan s'ils survivent (canal pgsql sain) ou non ;
   - les templates GPO serveur `/usr/share/sambaedu/gpo/*.zip` (`config/sambaedu.php` ~L518/L559/L746) hors
     bootstrap, et les services `GpoPublisher`/`GpoTemplateRegistry`/`WpkgGpoSynchronizer`/`GpoService` —
     en **distinguant** ce qui relève de la **GPO-dispatcher bootstrap (25.4) qui SURVIT** (D3) ;
   - les **tests** du canal legacy (≥ les suites `tests/Feature/Gpo/*`, `tests/Feature/Api/V1/Config/*`,
     `tests/Unit/Gpo/ApplicationScripts*`, `tests/Feature/Wpkg/Deployment/Http/{Winget,Linux}Out*`,
     `tests/Feature/Legacy/*`, `tests/Unit/LegacyGpoShimsTest.php`).

4. **AC4 — Distinction SAIN vs LEGACY explicite.** L'inventaire (B) **ne classe AUCUN** artefact du **canal WPKG
   pgsql sain** dans « à retirer » : `WpkgReportController` + route `/wpkg/reports/{hostname}`, le catalogue
   `packages.xml` statique (`wpkg:bundle`, servi par Apache), l'UI `/app/wpkg/deployments` + `/admin/settings/gpo/*`,
   `WpkgReportIngestionService` (sauf arbitrage explicite AC3) sont marqués **`sain-à-conserver`**. Et il
   **isole** la **GPO-dispatcher bootstrap (25.4)** comme **`survit-figé`** (D3), distincte des templates GPO de
   config à retirer.

5. **AC5 — Plan d'extinction ordonné (livrable C).** `docs/legacy-extinction-plan.md` contient une **séquence
   numérotée** de retrait respectant les **dépendances** entre artefacts (ex. retirer les routes avant les
   contrôleurs, les contrôleurs avant les services, les tests avec leur cible), des **critères de gate** (parité A
   validée + audit), et **les commandes de vérification** d'audit post-extinction, dont au minimum :
   - audit SYSVOL « **0 GPO créée ou modifiée hors bootstrap** » (KPI du brief — cf. `AuditApplicationsGpoTemplateCommand`) ;
   - vérification « **aucune route legacy ne répond** » (les surfaces gatées renvoient déjà 410, post-retrait =
     404) ;
   - vérification « **les tests du canal supprimé sont retirés avec lui** » (la suite reste verte après retrait).

6. **AC6 — Points d'attention consignés.** Le plan (C) liste explicitement les pièges connus : **fantômes inotify
   sur les `delete`** (après `git rm`/`trash`, fichiers résiduels sur la VM — demander au user avant cleanup SSH,
   cf. `project_inotify_no_delete_sync`) ; **ordre des migrations** (drop réversible des éventuelles tables/colonnes
   legacy) ; **cache config/routes VM** (`config:cache` + `route:cache` + `chown www-admin` après retrait) ;
   **dépendances de chargement** (un contrôleur retiré dont une route subsiste = 500) ; **repo legacy externe**
   (`sambaedu/wpkg/` archivé, pas supprimé du repo SE5).

7. **AC7 — Traçabilité épic.** Les AC de l'épic `Story 27.6 : Extinction du canal legacy`
   (`_bmad-output/planning-artifacts/epics-agent-desired-state.md` ~L774) sont **couverts** par les livrables : AC
   épic « checklist de parité = gate palier 3 » → livrable A (AC2) ; AC épic « sont supprimés en bloc … » → livrable
   B + C (AC3/AC5), **en préparation, pas en exécution** ; AC épic « audit post-extinction (0 GPO hors bootstrap,
   aucune route legacy, tests retirés) » → commandes d'audit du plan C (AC5).

## Tasks / Subtasks

- [ ] **T1 — Recherche & inventaire des artefacts legacy (AC3, AC4)**
  - [ ] T1.1 Confirmer/compléter les générateurs/assembleurs : `app/Gpo/Services/ApplicationScriptsGenerator.php`,
        `ApplicationScriptsAssembler.php`, `NetworkScriptGenerator.php`, `VeyonConfigGenerator.php`,
        `AssociationsResolver.php`, et leurs consommateurs (grep des `new`/`->make`/injections).
  - [ ] T1.2 Localiser le matching par tags `list_*` (`WorkstationConfigContextResolver.php`, `list_u` ~L136/L178)
        et les modules legacy qui en dépendent (`legacy/modules/ipxe/*`, `legacy/ldap.inc.php`,
        `legacy/modules/gpo/veyon_out.php`).
  - [ ] T1.3 Cartographier les routes du canal config : groupe `/api/v1/workstation-config/*` (`routes/api.php`
        ~L396) + 9 contrôleurs, `/api/v1/shortcuts/export` (~L411), `/wpkg/{linux,winget}_out.php`
        (`routes/web.php` ~L739/L747), vestiges `profiles.xml`/`hosts.xml` (~L700-711). Noter le middleware
        `legacy.config.channel` (`app/Http/Middleware/EnsureLegacyConfigChannelEnabled.php`) + le garde inline
        `MigrationController::serveFragment` pour `/gpo/*`.
  - [ ] T1.4 Cartographier le bridge legacy : `LegacyCatchallController.php` (executeViaBootstrap / proxyToLegacy),
        `legacy/bootstrap.php`, `legacy/modules/gpo/*`, stubs (`legacy/stubs/*`), README, et le **shim 1bis-11**.
  - [ ] T1.5 Cartographier les templates GPO serveur (`config/sambaedu.php` `templates_dir`, `wpkg_sync`,
        `applications`) et les services `GpoPublisher`/`GpoTemplateRegistry`/`WpkgGpoSynchronizer`/`GpoService` ;
        **ISOLER** la GPO-dispatcher bootstrap 25.4 (`app/Http/Controllers/Api/V1/Agent/BootstrapController.php` +
        story `25-4-deux-chemins-installation-gpo-dispatcher-figee-depot-ipxe.md`) comme `survit-figé`.
  - [ ] T1.6 Trancher l'arbitrage `9-4`/`9-5` : `WpkgReportIngestionService` / `IngestionResult` survivent-ils
        (canal pgsql sain) ? Documenter la décision et son critère.
  - [ ] T1.7 Recenser le `sambaedu/wpkg/` du repo legacy externe (dont `packages_xml_out.php` MySQL non porté,
        `winget_out.php`, `hosts_xml_out.php`, `linux_out.php`) — marqué **archivage**, hors repo SE5.
  - [ ] T1.8 Lister exhaustivement les **tests** du canal legacy (cf. AC3) et marquer ceux qui partent avec leur
        cible vs ceux qui couvrent du sain (à conserver).
  - [ ] T1.9 Pour CHAQUE artefact : produire la ligne `chemin:ligne | rôle | remplacé-par | classe | risque/ordre`.

- [ ] **T2 — Rédiger la checklist de parité de compétences (livrable A) (AC2)**
  - [ ] T2.1 Créer `docs/qa/domains/legacy-shims.md` en suivant la structure de `docs/qa/domains/rights-management.md`
        (pré-requis communs, sections numérotées stables, checklist finale) — cf. `docs/qa/README.md` § Convention.
  - [ ] T2.2 Tableau de parité : 1 ligne par capacité (≥ la liste AC2) → story 27.x → statut (à valider/validé) →
        renvoi à la section `agent.md` (ex. Section 13 raccourcis, Section 14 lecteurs/imprimantes, Section 19
        registre strict, Story 27.4 app_config, Story 27.5 applications…).
  - [ ] T2.3 Énoncer le **gate palier 3** : tant qu'une ligne est « à valider », l'extinction (C) reste bloquée.
  - [ ] T2.4 Mettre à jour `docs/qa/README.md` : ajouter `legacy-shims` dans « Domaines couverts » + cocher `[x]`
        la ligne L26 « À créer au fil des prochaines stories ».

- [ ] **T3 — Rédiger le plan d'extinction (livrables B + C) (AC3, AC5, AC6)**
  - [ ] T3.1 Créer `docs/legacy-extinction-plan.md` ; section « Inventaire » = sortie de T1 (B).
  - [ ] T3.2 Section « Plan d'extinction » (C) : séquence numérotée respectant les dépendances (routes → contrôleurs
        → services → générateurs → modules bridge → templates GPO → tests), avec pour chaque étape la cible exacte.
  - [ ] T3.3 Critères de gate : parité A validée + check-list d'audit ; commandes de vérification (audit SYSVOL « 0
        GPO hors bootstrap » via `AuditApplicationsGpoTemplateCommand` ; `curl` des routes legacy → 404 ; `php
        artisan test` vert après retrait des tests).
  - [ ] T3.4 Points d'attention (AC6) : fantômes inotify sur `delete`, ordre des migrations, `config:cache` +
        `route:cache` + `chown www-admin` VM, dépendances de chargement, repo legacy externe.
  - [ ] T3.5 Marquer NOIR-SUR-BLANC ce qui **SURVIT** : GPO-dispatcher bootstrap 25.4, canal WPKG pgsql sain.

- [ ] **T4 — Intégration QA / runbook (AC2, AC7)**
  - [ ] T4.1 Vérifier que la checklist de parité (A) renvoie correctement aux sections existantes de `agent.md`
        (pas de duplication de scénarios : A pointe, ne recopie pas).
  - [ ] T4.2 Vérifier la traçabilité épic 27.6 (AC7) : chaque AC de l'épic est adressé par un livrable.

- [ ] **T5 — Validation de forme & non-suppression (AC1)**
  - [ ] T5.1 Lancer `git status` / `git diff --name-only` et **PROUVER** que seuls les fichiers de doc + story +
        sprint-status sont touchés (aucun `app/`, `routes/`, `config/`, `tests/`, `legacy/`, `database/`).
  - [ ] T5.2 Relire les 3 livrables pour exhaustivité (toutes les cibles AC3 présentes avec `chemin:ligne`).
  - [ ] T5.3 Mettre à jour `sprint-status.yaml` : ligne `27-14-extinction-canal-legacy-en-bloc` → `ready-for-dev`
        (déjà positionnée par le SM) → `review` à la fin du dev ; `last_updated` daté.

## Dépendances

> Le **gate du palier 3** = toutes ces stories à parité **validée** en environnement réaliste. Statuts lus dans
> `_bmad-output/implementation-artifacts/sprint-status.yaml` (2026-06-19).

| Capacité | Story portant la parité | Statut sprint-status | Preuve lab (`agent.md`) |
|---|---|---|---|
| Wallpaper + overlay | 24.4 / 27.1bis / 27.1ter | done / done / done | Sections 4, 15, 21 |
| Raccourcis (bureau) | 27.1 | done | Section 13 |
| Lecteurs réseau & imprimantes | 27.2 | done | Section 14 |
| Registre / capacités | 27.3 → 27.3ter → **27.12** | done / review / review | Sections 16, 19 + Stories 27.3/27.3ter/27.12 |
| Associations de fichiers | 27.3bis / **27.11** | review / review | Story 27.3bis / 27.11 |
| Config d'app (policies.json) | 27.4 | review | Story 27.4 |
| Applications (WPKG) | 27.5 | done | Story 27.5 |
| Nature de poste (ciblage) | 26.1 | done | domaine `parc.md` (env. de poste) |
| Mode strict (drift) | 27.8 | done | Section 19 |
| Réveil agent au logon | 27.9 | done | Section 20 |

**Dépendances structurelles :**
- **Story 25.4** (`25-4-…-gpo-dispatcher-figee-…`, done) — la GPO-dispatcher bootstrap qu'on **préserve** (D3).
- **Story 15-7** (cancelled, superseded par cette story) — items WPKG-spécifiques repliés ici : flip routes
  `wpkg/*` → 410 (déjà fait par kill-switch), retrait shim 1bis-11, archivage `sambaedu/wpkg/`, arbitrage 9-4/9-5.
- **Stories 9-4 / 9-5** (done) — composants `WpkgReportIngestionService` à arbitrer (T1.6).

**Bloque :** la **passe d'extinction ULTÉRIEURE** (non créée) qui exécutera le plan C une fois la parité validée.

## Dev Notes

### Cartographie vérifiée (à reprendre dans l'inventaire B)

- **Kill-switch runtime (déjà actif, NE PAS toucher) :** `config/sambaedu.php` L41
  (`legacy_config_channel_enabled`), middleware `app/Http/Middleware/EnsureLegacyConfigChannelEnabled.php` (→ 410),
  garde inline `MigrationController::serveFragment` pour `/gpo/*`. Le commentaire L29-40 de `config/sambaedu.php`
  liste les 3 surfaces gatées.
- **Routes du canal config (à retirer) :** `routes/api.php` L396-409 (groupe `v1/workstation-config`, nom
  `agent.v1.config.*` — **PIÈGE de nommage**, c'est legacy), L411-417 (`v1/shortcuts/export`) ; `routes/web.php`
  L739-753 (`/wpkg/linux_out.php`, `/wpkg/winget_out.php`).
- **Controllers config (à retirer) :** `app/Http/Controllers/Gpo/{NetworkOut,VeyonOut,AssociationsOut,ApplicationsScripts}Controller.php`,
  `app/Http/Controllers/{Wallpaper,Overlay,AppPolicy}Controller.php`,
  `app/Http/Controllers/Api/v1/ShortcutExportController.php`,
  `app/Wpkg/Deployment/Http/Controllers/{LinuxOut,WingetOut}Controller.php`. ⚠️ `WallpaperController`/
  `OverlayController` portent AUSSI l'historique overlay migré sous `/api/v1/agent/*` — vérifier qu'on ne retire que
  la méthode `apiV1` legacy, pas le chemin agent (à trancher dans le plan C).
- **Générateurs / résolveur de contexte (à retirer) :** `app/Gpo/Services/ApplicationScriptsGenerator.php`,
  `ApplicationScriptsAssembler.php`, `NetworkScriptGenerator.php`, `VeyonConfigGenerator.php`,
  `AssociationsResolver.php`, `WorkstationConfigContextResolver.php` (tags `list_u` L136/L178).
- **Bridge legacy (à retirer / archiver) :** `app/Http/Controllers/LegacyCatchallController.php`,
  `legacy/bootstrap.php` (+ shim 1bis-11), `legacy/modules/gpo/*` (`applications.php`, `associations_out.php`,
  `network_out.php`, `veyon_out.php`, `gpo-maj.php`, `gpo-export.php`, `gestion_gpo.php`, `wine.php`),
  `legacy/stubs/*`. **NE PAS** confondre avec `legacy/modules/ipxe/*` et `legacy/modules/dhcp/*` (iPXE/DHCP =
  features SE5 vivantes servies par le bridge — HORS scope de l'extinction config).
- **Templates GPO serveur (à retirer hors bootstrap) :** `config/sambaedu.php` L518 (`templates_dir`), L559
  (`se4_wpkg.zip`), L746 (`se4_applications.zip`) ; services `GpoPublisher`, `GpoTemplateRegistry`,
  `WpkgGpoSynchronizer`, `GpoService`, `GpoExportSerializer`, `SambaToolRunner`, `NativeSectionResolver`.
- **SURVIT (D3) :** `app/Http/Controllers/Api/V1/Agent/BootstrapController.php` + la GPO-dispatcher bootstrap de la
  story 25.4. À isoler explicitement.
- **SAIN — canal WPKG pgsql (D4, NE PAS retirer) :** `routes/api.php` L172-174 (`/wpkg/reports/{hostname}` →
  `WpkgReportController`), `app/Services/Windows/WpkgReportIngestionService.php` (9-4) + `IngestionResult.php` (sauf
  arbitrage T1.6), catalogue `packages.xml` statique (`php artisan wpkg:bundle`, `config('agent.wpkg_bundle_path')`),
  UI `/app/wpkg/deployments` (`routes/web.php` L260+) et `/admin/settings/gpo/*`.
- **Tests du canal legacy (à retirer avec leur cible) :** `tests/Feature/Gpo/{ApplicationsScriptsApiV1,
  ApplicationsScriptsByteParity,ApplicationsScriptsCriticalParity,ApplicationsScriptsWrapperIntegration,
  NetworkOutComparison,VeyonOutEndpoint,GpoDetailPage,GpoLoggingChannel}Test.php`,
  `tests/Feature/Api/V1/Config/*` (Firefox/Thunderbird/Overlay/Network/Security),
  `tests/Feature/Wallpaper/LegacyOutEndpointTest.php`,
  `tests/Feature/Wpkg/Deployment/Http/{WingetOut,LinuxOut}*Test.php`,
  `tests/Feature/Legacy/LegacyModuleGpoOutputsTest.php`, `tests/Unit/LegacyGpoShimsTest.php`,
  `tests/Unit/Gpo/ApplicationScripts{Generator,Assembler}Test.php`, `tests/Architecture/GpoNamespaceTest.php`
  (à arbitrer : la règle d'archi peut rester comme garde de non-régression).

### Inventaire approfondi (recherche dédiée — à intégrer/vérifier dans le livrable B)

> Recensement issu d'une passe de recherche dédiée. **Le dev vérifie chaque `chemin:ligne` au moment de rédiger B**
> (les numéros de ligne ont pu bouger) ; certains items demandent un **arbitrage explicite** (notés ⚖️).

- **Double face `/gpo/*_out.php` (legacy) vs `/api/v1/workstation-config/*` (natif 16.13).** Les contrôleurs sous
  `app/Http/Controllers/Gpo/*` et `Wallpaper/Overlay/AppPolicy/ShortcutExport` portent **DEUX méthodes** : une
  méthode `legacyOut()`/`legacyDispatch()`/`legacyFirefoxOut()`… exposée sur les routes `/gpo/*_out.php`
  (`routes/web.php` ~L601-678) ET une méthode `apiV1()` exposée sur `/api/v1/workstation-config/*`
  (`routes/api.php` ~L400-408). **Les deux faces sont du canal legacy** (la face `apiV1` est le port natif 16.13 du
  même canal, pas le canal agent). ⚖️ Trancher dans B/C : retirer les routes `/gpo/*_out.php` + méthodes `legacyOut`
  d'abord, puis le groupe `workstation-config` + méthodes `apiV1`, puis les contrôleurs entiers.
- **Framework de migration SE4→SE5 « fragment+reboot » (Story 16.13bis) — composant legacy majeur :**
  `app/Auth/V1/Migration/Http/Controllers/MigrationController.php` (`serveFragment()`, `PASSTHROUGH_HANDLERS` des 8
  endpoints `/gpo/*`), `app/Auth/V1/Migration/Services/MigrationFragmentRenderer.php`,
  `app/Auth/V1/Migration/Services/MigrationStatusChecker.php`. C'est le **garde inline `/gpo/*`** du kill-switch.
  ⚖️ Trancher : ce framework migre les postes SE4 vers le canal natif — il part quand il n'y a plus de poste SE4 à
  migrer (peut survivre au-delà de l'extinction config si des SE4 restent). À documenter comme **dépendance de
  séquencement** dans C.
- **GPO-dispatcher bootstrap concrète (SURVIT, D3) :** le template `resources/gpo/se4_agent_bootstrap/` (la GPO
  figée 25.4 qui pose le CA, télécharge l'agent, enrôle, lance `agent.exe`). À ISOLER comme `survit-figé`,
  distinct de `MigrationController` (16.13bis, legacy) — **ne pas confondre les deux mécanismes de bootstrap SE4**.
- **Tags `list_*` : NON TROUVÉS comme mécanisme dédié.** La recherche n'a pas trouvé de `list_machines`/`list_groups`
  de matching de contexte ; seule trace `list_u` dans `WorkstationConfigContextResolver.php` (~L136/L178) et des
  `list_*` dans des modules legacy iPXE (`legacy/modules/ipxe/*` — HORS scope config). ⚖️ B doit **confirmer** que le
  matching par tags `list_*` de FR30 désigne bien `WorkstationConfigContextResolver` et non un artefact absent.
- **Pas de « shim 1bis-11 » unique.** La série 1bis est fragmentée (~1bis.1 → 1bis.18g). Les shims actifs
  (`legacy/ldap.inc.php`, `legacy/dhcp_shim.inc.php`, `legacy/wpkg_libsql.php`, `legacy/stubs/*`) restent
  **NÉCESSAIRES** au bridge legacy iPXE/DHCP (features SE5 vivantes) → **CONSERVÉS**, pas dans l'extinction config.
  Le shim GPO est déjà **ARCHIVÉ** (`legacy/archived/gpo-shim-2026-05-20/`, retiré par 16.13bis).
- ⚖️ **`/wpkg/linux_out.php` + `/wpkg/winget_out.php` : arbitrage à trancher.** La recherche les classe « sain
  natif 17.6 », MAIS ils sont gatés `legacy.config.channel` et le commentaire `routes/web.php` ~L711 les défère
  explicitement à « 27.6 » (cette story) comme surfaces legacy. **Trancher dans C** : sont-ils du canal legacy à
  retirer (ils servent install OS / startup linux, pas le canal agent) ou un transport natif conservé ? Décision à
  consigner — c'est exactement le genre d'artefact à NE PAS classer à la légère.
- **`packages_xml_out.php` (MySQL) :** confirmé **absent du repo SE5** (jamais porté) ; vit dans le repo legacy
  externe `sambaedu/wpkg/`. Recensé en **archivage** (B), pas un fichier SE5 à supprimer.
- **Tests complémentaires repérés (à confirmer existence + classer dans B) :** `tests/Unit/LegacyBootstrapTest.php`,
  `tests/Unit/LegacyConfigBridgeTest.php`, `tests/Unit/LegacyGpoIncludesTest.php`,
  `tests/Feature/LegacyBootstrapCatchallTest.php`, `tests/Feature/LegacyBootstrapShimsTest.php`,
  `tests/Feature/LegacyCatchallTest.php`, `tests/Feature/Legacy/LegacyModuleGpoGestionTest.php`,
  `tests/Feature/Legacy/LegacyRoamingRedirectsTest.php`,
  `tests/Feature/AppCustomization/AppPolicyLegacyEndpointTest.php`,
  `tests/Architecture/GpoLegacyIsolationTest.php`, `tests/Architecture/ApiV1ConfigRoutesTest.php`, + les 4 tests
  Migration (`MigrationController`/`MigrationE2EScenario`/`MigrationFragmentRenderer`/`MigrationStatusChecker`).
  ⚖️ Distinguer les tests du **bridge legacy conservé** (bootstrap/catchall/shims iPXE-DHCP = restent) des tests du
  **canal config retiré** (GPO config, workstation-config, application-scripts = partent).

### Standards & conventions

- **Cette story est de la DOCUMENTATION pure** : pas de code, pas de migration, pas de test exécutable. Pas de
  `php artisan` autre que pour la recherche (grep). Pas d'accès VM mutant.
- Rédaction en **français**, conventions des stories du repo (voir `27-12-…` pour le style).
- `legacy-shims.md` suit la **structure des domaines QA** (`docs/qa/README.md` § Convention) : numérotation stable,
  append-only.
- L'inventaire doit donner des **`chemin:ligne` absolus relatifs à la racine du projet** (ex.
  `routes/api.php:396`). Les `.claude/worktrees/*` sont des worktrees git — **les ignorer** dans l'inventaire.

### Project Structure Notes

- Aucun nouveau code, aucun nouveau namespace. Deux fichiers doc créés + deux fichiers doc/yaml édités.
- Le fichier de plan `docs/legacy-extinction-plan.md` vit **hors** `docs/qa/` (plan d'ingénierie, pas runbook de
  test) ; la checklist de parité vit **dans** `docs/qa/domains/` (gate de pré-prod).

### References

- [Source: _bmad-output/planning-artifacts/epics-agent-desired-state.md#Story 27.6 (~L774)] — AC canoniques de l'extinction.
- [Source: _bmad-output/planning-artifacts/epics-agent-desired-state.md#FR30 (~L76), #NFR10 (~L89), #Paliers (~L111)]
- [Source: _bmad-output/implementation-artifacts/sprint-status.yaml] — ligne 15-7 (cancelled/superseded), 27-x.
- [Source: config/sambaedu.php#L29-41] — kill-switch + commentaire des 3 surfaces.
- [Source: app/Http/Middleware/EnsureLegacyConfigChannelEnabled.php] — gate runtime → 410.
- [Source: routes/api.php#L396-417] — groupe `workstation-config` + `shortcuts/export`.
- [Source: routes/web.php#L696-753] — vestiges WPKG legacy + linux_out/winget_out.
- [Source: legacy/README.md] — architecture du bridge legacy (catchall, bootstrap, proxy).
- [Source: docs/qa/README.md#L26] — domaine `legacy-shims.md` planifié.
- [Source: docs/qa/domains/agent.md] — sections de preuve lab par capacité (renvois de la checklist A).
- Mémoires : `project_legacy_config_kill_switch`, `project_no_legacy_transition_state`,
  `project_gpo_dispatcher_static_anchor`, `project_wpkg_delivery_winget_only_pgsql_vs_legacy_mysql`,
  `project_inotify_no_delete_sync`, `project_zero_prod_publish_is_test`,
  `project_audit_applications_scripts` (audit `audit-applications-scripts.md` validé Epic 17).

## Recommandation Modèle Dev

**Recommandation : `opus`.**

C'est une story de PRÉPARATION/DOCUMENTATION (checklist + inventaire + plan), donc pas d'implémentation de code.
Mais le cœur de risque est l'**inventaire exhaustif et la classification fine** dans un gros codebase brownfield :
le danger n'est pas d'écrire du code faux, c'est (a) de **rater un artefact** legacy (un oubli devient une dette
fantôme le jour de l'extinction) et (b) de **classer à tort** un artefact SAIN (canal WPKG pgsql, GPO-dispatcher
bootstrap 25.4, ingestion 9-4/9-5) comme « à retirer », ce qui casserait du fonctionnel au moment du retrait. Cette
discrimination demande de la recherche profonde et un raisonnement nuancé sur des homonymies trompeuses (route
`agent.v1.config.*` qui est legacy ; `WallpaperController::apiV1` legacy vs chemin agent ; `/wpkg/reports` sain vs
`/wpkg/winget_out` legacy). `opus` est justifié par la **fiabilité du recensement**, pas par la complexité du code.

## Dev Agent Record

### Agent Model Used

### Debug Log References

### Completion Notes List

### File List

# Story 27.5 : Applications — l'agent déclenche WPKG (un tuyau, deux outils)

Status: done

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

En tant qu'admin d'établissement,
je veux que les installations d'applications passent par le canal agent,
afin que la dernière ressource quitte le transport GPO sans réécrire le moteur de paquets.

## Contexte (à lire en premier)

**« Un tuyau, deux outils ».** L'agent unifie le **transport** (le déclencheur), PAS le **modèle d'exécution**. WPKG reste le **moteur déclaratif de paquets** (résolution de dépendances, `<check>/<install>/<upgrade>`, versions) — il **n'est PAS absorbé** par l'agent. Cette story branche l'agent comme **déclencheur** de `wpkg.js` à la place de la GPO `se4_wpkg`, projette l'ensemble cible des applications en item d'état, et fait remonter le résultat **par application** (fondation des licences à pool). C'est la **dernière ressource** à basculer avant l'extinction du canal legacy (27.6).

**Le slot est déjà réservé, mais vide.** L'identifiant de type `applications` est **déjà figé** des deux côtés du contrat (`app/Services/Agent/StateContract.php:53` et `agent/shared/contract.go:34`), mais **aucun provider ne l'émet** et **aucun handler ne le traite** — `AgentServiceProvider` enregistre tous les types **sauf** `applications`. Cette story remplit ce slot. **Pas de bump de contrat pour l'identifiant lui-même** ; la story possède la sous-structure du `payload` `applications` (contrat §3.2).

**27.5 résout une limite connue de 27.4.** Le doc contrat §7.3 (`app_config`) pointe explicitement : « Pour que la policy ait un effet, l'app doit être installée (Firefox/Thunderbird présents) → story 27.5 (applications/WPKG). » 27.5 est le résolveur nommé de ce couplage.

**Correction d'un présupposé (vérifié en analyse) :** contrairement à la note mémoire « WPKG livraison = winget only », le canal WPKG **classique** est pgsql-backed (catalogue `packages.xml` écrit par `PackagesXmlService`, profil par-hôte par `WorkstationPackagesResolver`). Seul l'**endpoint HTTP legacy** `packages_xml_out.php` n'a jamais été porté. Le canal **winget** (`winget_out.php`) est un sous-pont **distinct**, gaté `WPKG_WINGET_ENABLED=false` — **hors-scope** (l'agent déclenche `wpkg.js`, pas le pont winget).

**🔧 Décision 2026-06-18 — livraison WPKG repensée NATIVE SE5 (le shim legacy dégage).** Vérifié sur la VM : la chaîne WPKG est **intégralement à plat** aujourd'hui (URLs client `*_xml_out.php` → **500** ; URLs SE5 `*.xml` → **410** kill-switch ; `SE4FS_NAME` **non substitué**). Plutôt que rafistoler le legacy, Henri tranche **D6 = tout-en-un, livraison native** : (1) **supprimer le shim legacy** WPKG ; (2) **SE5 génère le bundle pré-substitué** (scripts versionnés `resources/wpkg/*` + catalogue) dans un **sous-dossier servi en statique par Apache** (**pas** via Laravel, **pas** tout `/storage`) ; (3) l'**agent donne juste l'URL** au bootstrap (il **ne télécharge pas** — c'est le client qui download depuis Apache : zéro charge Laravel, pas de goroutine) + **dépose** le profil par-hôte localement, puis **déclenche** le client ; (4) on **patche** `wpkg-se4.js` (+ `wpkg.cmd`) — désormais versionnés `resources/wpkg/` — pour pointer vers SE5 ; (5) installeurs **SMB inchangé** (D11). → cf. AC5 (réécrit), T10, Dev Notes « Livraison WPKG native SE5 ». **Élargissement de périmètre assumé : 27.5 = canal agent + refonte de la livraison WPKG.**

## Acceptance Criteria

### AC1 — `ApplicationsStateProvider` : l'ensemble cible WPKG projeté en état (serveur, PG-pur)

**Given** le type `applications` (identifiant figé, déjà dans `StateContract::RESOURCE_TYPES`), `semantics = aggregate`, `scope = machine`, avec son `ApplicationsStateProvider`
**When** le serveur compile l'état d'un poste
**Then** la portée `machine` contient **un item `applications` par application affectée** au poste (union des mailles : profils + apps sur poste + profils + apps sur groupes, + dépendances transitives), item à **exactement 4 clés** `{type, semantics, payload, hash}`, payload concret `{app_id, name}`, provider enregistré dans `AgentServiceProvider`, hash calculé par `StateHasher` (jamais par le provider)
**And** le provider est une **projection PG-pure** : il réutilise la résolution WPKG existante (`WorkstationPackagesResolver`, **méthode NON cachée**) — **jamais** le wrapper `resolve()` (APCu) ; le grep garde `ldap|apcu|samba-tool|Cache::|LdapRecord|PackagesXml` sur le fichier provider est **vide** (commentaires exceptés) — critère Keycloak NFR7
**And** le provider **n'applique aucune précédence/tri/dédup** (discipline D2 : seul `StateCompiler` le fait) ; il étiquette ses candidats par maille et s'arrête là.

### AC2 — Le handler agent **déclenche** WPKG (Go, portée machine / SYSTEM)

**Given** le handler `applications` enregistré dans le **`MachineEngine`** (service SYSTEM) de `agent/windows/main_windows.go`
**When** l'agent converge la portée machine
**Then** le handler **passe au bootstrap l'URL** du bundle WPKG (sous-dossier statique servi par Apache, URL portée par la config agent) + **dépose** localement le profil par-hôte, puis **déclenche le moteur WPKG local** (`wpkg.cmd`/`wpkg-client.vbs`) via une opération Windows injectée (`ApplicationsOps`) — c'est le **client** qui télécharge (pas l'agent : ni blocage, ni goroutine, ni charge Laravel) ; le handler **ne réimplémente PAS** l'installation, la détection de présence, la résolution de dépendances ou la gestion de version (WPKG reste le moteur déclaratif, non absorbé)
**And** le handler suit la machine d'états STRICT inconditionnelle (27.8) : ensemble cible déjà appliqué → `Test` vrai → `compliant` sans re-déclenchement ; ensemble modifié ou installation incomplète → `Test` faux → `Apply` (déclenche WPKG) → `drift` ; échec du déclenchement/installation → `error` + `detail` non vide
**And** `Apply` est **idempotent / level-triggered** (re-déclencher WPKG sur un poste déjà convergé est sans effet cumulatif — WPKG no-op de lui-même)
**And** le cœur du handler (`agent/shared/handler_applications.go`) est **testable sur l'hôte** avec un faux `ApplicationsOps` ; le shell-out vers `wpkg.js` est **isolé** dans l'implémentation Windows (`agent/windows/handler_applications_windows.go`) — seule exception justifiée à la convention « API native, zéro shell-out » (déclencher un moteur externe ne s'écrit pas en Win32) ; `go.mod` inchangé.

### AC3 — Convergence level-triggered (poste hors ligne)

**Given** un poste hors ligne pendant la fenêtre d'installation programmée
**When** le poste revient en ligne
**Then** il converge à son **prochain cycle** (boot / login / timer / bouton forcer) — fini les postes joints qui n'installent rien : le déclencheur n'est plus l'**événement GPO ponctuel** au boot mais le **cycle agent répété** (Vérité #5 : « programmé aujourd'hui, effectif demain »).

### AC4 — Inventaire par poste rapporté (fondation des licences à pool, SANS UI)

**Given** le rapport de l'agent (`POST /api/v1/agent/report`)
**When** le handler `applications` a déclenché/vérifié WPKG
**Then** le rapport **porte l'inventaire par poste** : un **résultat par application** (`app_id` + statut), stocké côté serveur de façon **comptabilisable** (table dédiée, ex. `agent_application_inventory`, clé `(workstation_id, app_id)`) — fondation des licences à pool, **sans implémenter l'UI**
**And** le **verdict de conformité du type `applications` reste PAR TYPE** : un seul statut `compliant|drift|error` dans `agent_resource_states` (grain figé 27.8, `pire statut gagne`), qui circule dans `ConformityService` + l'UI de conformité existante **sans aucune modification UI**
**And** l'inventaire par application est une **donnée ADDITIVE sous** la ligne d'état par type — **jamais** une réintroduction du grain `item × poste` du débat 27.8 (le statut par type ne devient PAS per-app ; l'inventaire est une donnée séparée, pas un verdict).

### AC5 — Livraison WPKG **native SE5** (shim legacy supprimé ; Apache statique ; l'agent donne l'URL & déclenche)

**Given** le shim legacy WPKG est supprimé (serving `*_xml_out.php` qui 500, gating `legacy.config.channel`)
**Then** SE5 **génère** le bundle **pré-substitué** (scripts versionnés `resources/wpkg/{wpkg-se4.js, wpkg-client.vbs, wpkg.cmd}` + catalogue `packages.xml`, variables résolues `SE4FS_NAME`/base URL) dans un **sous-dossier public spécifique**, **servi en statique par Apache** (pas tout `/storage`, **pas** via Laravel) — auth = LAN sur ce sous-dossier restreint (iso-legacy)
**And** l'**agent donne l'URL** du sous-dossier (config agent) au **bootstrap** et **déclenche** `wpkg.cmd`/`wpkg-client.vbs` — c'est le **client qui télécharge** (l'agent ne télécharge pas : ni blocage, ni goroutine, zéro charge Laravel) ; `wpkg.cmd` est patché pour un **download HTTP** (au lieu du `XCOPY` SMB l.23)
**And** `wpkg-se4.js` est **patché** : `wpkg_base` (l.321) + noms de fetch (l.479/481/483) → endpoints SE5 / fichiers locaux ; les namespaces `wpkg.org` (l.534-538, schéma XML) **intouchés**
**And** le **profil par-hôte** est fourni **sans charge Laravel** : l'**agent dépose localement** `profiles.xml`/`hosts.xml` générés depuis son état `applications` (il a déjà la liste), `wpkg-se4.js` lisant en local *(D9 — CONFIRMÉ Henri 2026-06-18)*
**And** les **installeurs** restent sur le partage **SMB** `%Z%\packages` (D11 — `/noDownload`, inchangé)
**And** la GPO `se4_wpkg` est **retirée** (D2) ; ce qui meurt en 27.5 = le **serving WPKG legacy uniquement** — pas `winget_out`/`linux_out`/`/gpo/*`, ni `WorkstationPackagesResolver` (réutilisé) (→ 27.6).

### AC6 — Contrat & golden files (NFR13, tests croisés PHP ⇄ Go)

**Given** l'évolution du contrat par cette story
**Then** `tests/Fixtures/Agent/state.v1.json` gagne un item `applications` (4 clés) en portée `machine`
**And** `tests/Fixtures/Agent/report.v1.json` illustre le **résultat par application** (champ additif optionnel sur l'item `applications` du rapport)
**And** les hashes figés **PHP** (`ContractV1Test::FROZEN_STATE_HASH`) **et Go** (`agent/shared/hasher_test.go` `frozenStateHash`) sont re-bumpés à la **même valeur** ; `agent/shared/contract_test.go` ajuste les comptes par portée (machine +1)
**And** `docs/agent/contract-v1.md` gagne la section **§7.4 (`applications`)** (sous-structure du payload + invariant central) et une **note §6** documentant le champ d'inventaire additif côté rapport (champ ajouté = évolution **mineure**, l'ancien serveur l'ignore — reste `v1`).

### AC7 — Aucune dépendance AD ni dette nouvelle (critère Keycloak NFR7 + anti-patterns)

**Given** le canal agent
**Then** aucun code livré (provider, handler, ingest, migration) n'introduit de dépendance AD / Kerberos / `samba-tool` / `LdapRecord` / APCu
**And** aucun des anti-patterns interdits : provider qui applique la précédence, table générique de règles, handler synchrone bloquant au logon, endpoint hors `/api/v1/agent/*`, renommage d'identifiant publié, réintroduction de `mode`/`drifted_allowed`/tolérance de dérive, fonctionnalité de non-convergence dans l'agent (anti-couteau-suisse #30 — l'inventaire reste un sous-produit du rapport de convergence, pas un module d'inventaire dédié).

## Tasks / Subtasks

- [x] **T1 — Identifiant de type figé sur le modèle** (AC1)
  - [x] Ajouter `public const TYPE_APPLICATIONS = 'applications';` sur `app/Models/Application.php` (iso `Printer::TYPE_PRINTERS`, `AppCustomization::TYPE_APP_CONFIG`), avec docblock « identifiant figé du type d'état — contrat §7, NFR12, jamais renommé »
  - [x] Vérifier la cohérence avec `StateContract::RESOURCE_TYPES` (déjà présent ligne 53) — aucun changement à faire là

- [x] **T2 — Exposer une résolution WPKG NON cachée (PG-pure)** (AC1, NFR7)
  - [x] Sur `app/Wpkg/Deployment/Services/WorkstationPackagesResolver.php` : exposer une méthode publique **sans cache** que le provider appellera (ex. rendre `computePackages(string $hostname): Collection` publique, ou ajouter `resolveUncached(string $hostname): Collection` qui délègue à `computePackages`) — **ne JAMAIS** appeler `resolve()` depuis le provider (il wrappe `Cache::remember`, APCu, ligne 50-64)
  - [x] Confirmer que la méthode exposée ne touche **aucun** cache (le `Cache::remember` reste exclusivement dans `resolve()`)
  - [x] Couvrir d'un test unitaire la méthode exposée (mêmes paquets que `resolve()`, sans I/O cache)

- [x] **T3 — `ApplicationsStateProvider`** (AC1)
  - [x] Créer `app/Services/Agent/Providers/ApplicationsStateProvider.php implements StateProvider`
  - [x] `type()` → `Application::TYPE_APPLICATIONS` ; `semantics()` → `ResourceSemantics::Aggregate` ; `scope()` → `StateScope::Machine`
  - [x] Constructeur : injecter `WorkstationPackagesResolver`
  - [x] `itemsFor(TargetContext $ctx)` : résoudre l'ensemble cible via la méthode NON cachée (`$ctx->workstation->name`) → `Collection<string>` d'`app_id` ; **hydrater** les lignes `Application::whereIn('app_id', $appIds)->get()` (PG-pur) pour sourcer `name`/`id`/`updated_at` ; émettre **un `StateCandidate` par `app_id`**, `maille: StateMaille::Broadcast` (l'ensemble est déjà la résolution finale poste+groupes côté WPKG — voir Décision D4 sur la maille), `payload: ['app_id' => …, 'name' => …]` (`name` = libellé d'affichage de l'app, colonne sur `Application` ; strings only, jamais de float, jamais un id de scope/catalogue), `updatedAt` = `Application::updated_at`, `sourceId` **déterministe & injectif** (ex. `Application::id`)
  - [x] **Zéro** tri/précédence/dédup dans le provider (D2 = compilateur)
  - [x] Docblock : règles NFR7 + « projette la résolution WPKG (single source of truth), jamais une réimplémentation de l'union/BFS de dépendances »

- [x] **T4 — Enregistrement du provider** (AC1)
  - [x] Ajouter `$app->make(ApplicationsStateProvider::class),` dans le tableau du `StateCompiler` de `app/Providers/AgentServiceProvider.php` (après `AppConfigStateProvider`, ~ligne 130) + l'`use` en tête de fichier + un commentaire `// Story 27.5 — type applications` iso les précédents

- [x] **T5 — Test unitaire du provider** (AC1, AC7)
  - [x] `tests/Unit/Services/Agent/ApplicationsStateProviderTest.php` : test d'identité figée en premier (`type()===' applications'`, `Aggregate`, `Machine`) ; **désactiver les observers AD en `setUp`** (`WorkstationGroupObserver::disableSync()` etc. — pas de LDAP sur l'hôte) ; test « un item par app affectée (union mailles + dépendances) » ; test « payload sans float, sans id de scope » ; test « provider PG-pur » (aucun appel cache)

- [x] **T6 — Handler Go cœur (portable, testable hôte)** (AC2, AC3)
  - [x] `agent/shared/handler_applications.go` : `ApplicationsSpec` (parse `{app_id, name}`), interface `ApplicationsOps` (ex. `ListInstalled() ([]string, error)`, `TriggerWpkg() (WpkgResult, error)` où `WpkgResult` porte le résultat par paquet), struct `ApplicationsHandler{Ops, Log}`
  - [x] `desiredSet(items)` : parse + dédup défensif (dernier gagne) de l'ensemble des `app_id`
  - [x] `Test(items) (bool, error)` : vrai si l'ensemble cible est déjà appliqué (comparer l'ensemble cible à l'état WPKG / au dernier-appliqué persisté) — **niveau, pas événement**
  - [x] `Apply(items) error` : déclencher WPKG via `Ops.TriggerWpkg()`, collecter le résultat par paquet ; effort-maximal (tenter tout, renvoyer la 1re erreur à la fin)
  - [x] Le handler **n'écrit pas le rapport** (le moteur le fait via `ResolveItemStatus`) — mais il expose l'inventaire par app pour `BuildReport` (voir T9)
  - [x] `agent/shared/handler_applications_test.go` : table-driven, machine STRICT (compliant / drift+apply / error), faux `ApplicationsOps`

- [x] **T7 — Handler Go Windows (donne l'URL + dépose le profil + déclenche)** (AC2, AC5)
  - [x] `agent/windows/handler_applications_windows.go` : implémentation réelle de `ApplicationsOps` :
    - **Donner l'URL** du sous-dossier Apache au bootstrap (injecter dans `wpkg.cmd` ou passer en variable/arg) + **déposer localement** `profiles.xml`/`hosts.xml` générés depuis l'état `applications` (D9). **L'agent ne télécharge PAS** le bundle — c'est `wpkg.cmd` qui le download depuis Apache (D7/D10).
    - **Déclencher** `cscript //B //NoLogo wpkg-client.vbs /NOTempo` (→ `wpkg-se4.js /synchronize /noDownload /applymultiple:true` ; **PAS** `wpkg.js /server /profile` en direct) ; garantir `%SE4FS%` + accès partage SMB (installeurs).
    - Capturer le **code de sortie**, puis **lire `wpkg.xml`** pour l'**état par paquet** → inventaire + statut (D5). Spike : chemin/format `wpkg.xml`.
  - [x] Stub `!windows` no-op pour la compilation hôte
  - [x] Justifier en commentaire le shell-out (déclencher un moteur externe ≠ API Win32) ; **aucune dépendance AD** (vérifié)

- [x] **T8 — Enregistrement du handler (MachineEngine / SYSTEM)** (AC2)
  - [x] Enregistrer `"applications": &shared.ApplicationsHandler{Ops: &applicationsOps{…}, Log: logger}` dans la map `MachineEngine.Handlers` de `agent/windows/main_windows.go` (iso `app_config`/`registry` HKLM) — **PAS** dans `companion_windows.go` (installation machine-wide = SYSTEM, leçon 🔴 27.4 #1 : portée de livraison ≠ portée de résolution)

- [x] **T9 — Inventaire par poste : contrat rapport + stockage** (AC4, AC6)
  - [x] **Contrat rapport** : l'item `applications` du rapport porte un champ additif optionnel **`inventory`** (recommandé — évite de masquer le `type`; aligné sur le vocabulaire AC4 « le reporting porte l'inventaire ») : `inventory: [{app_id, status, detail?}]` (statut ∈ `compliant|drift|error` ; `detail` optionnel non vide si `status=error`) ; le `status` du type = **pire statut** des apps (fold worst-status, `error > drift > compliant`)
  - [x] Côté agent : `BuildReport` (cf. `agent/shared/loop.go`) inclut ce champ pour le type `applications`
  - [x] **Validation** : `app/Http/Requests/Api/V1/Agent/ReportRequest.php` accepte `items.*.inventory` (array nullable ; `inventory.*.app_id` string ; `inventory.*.status` enum `AgentResourceStatus` ; `inventory.*.detail` string nullable)
  - [x] **Migration** : nouvelle table `agent_application_inventory` (`workstation_id` FK cascadeOnDelete, `app_id` varchar, `status` varchar(32), `reported_at` timestamp, `UNIQUE(workstation_id, app_id)`), migration idempotente + `down()` symétrique + `->comment()` daté
  - [x] **Ingest** : `app/Services/Agent/Reporting/ReportIngestService.php` upserte les lignes d'inventaire (`updateOrCreate` sur `(workstation_id, app_id)`) **en plus** de la ligne d'état par type (inchangée) — dans la même transaction ; nettoie les lignes d'apps absentes de l'inventaire rapporté (level-triggered : une app retirée n'occupe plus de siège)
  - [x] **Aucune UI** (AC4) ; modèle `app/Models/AgentApplicationInventory.php` minimal (lecture future)
  - [x] Log `agent.applications.*` (ex. `agent.applications.reported`) iso convention `agent.<domaine>.<event>`

- [x] **T10 — Livraison WPKG native SE5 (Apache statique, shim legacy supprimé)** (AC5)
  - [x] **Supprimer le shim legacy WPKG** (serving `*_xml_out.php` → 500 + gating `legacy.config.channel`, `routes/web.php:704-710`). **Ne PAS** toucher `winget_out`/`linux_out`/`/gpo/*` (→ 27.6).
  - [x] **Générer le bundle pré-substitué** (scripts `resources/wpkg/*` + `packages.xml`, variables résolues serveur : `SE4FS_NAME` ← conf serveur, base URL → SE5) dans un **sous-dossier public spécifique** ; régénérer à la pose / au changement de conf (D7).
  - [x] **Servir en statique par Apache** ce sous-dossier (alias dédié — **pas** tout `/storage`, **pas** via Laravel) ; auth = LAN/restriction du sous-dossier (D10), **pas de bearer**.
  - [x] **Substitution (D7)** à la génération : porter `<variable source="sambaedu">` (≥ `SE4FS_NAME` ← clé `se4fs_name`) iso `packages_xml_out.php:46-56` (source conf serveur, **pas l'AD**).
  - [x] **Patcher `wpkg-se4.js` (D8)** : `wpkg_base` (l.321) + `web_{packages,profiles,hosts}_file_name` (l.479/481/483) + log (l.8441) → endpoints SE5 / fichiers locaux ; **ne PAS** toucher les namespaces `wpkg.org` (l.534-538).
  - [x] **Patcher `wpkg.cmd`** : remplacer le `XCOPY \\%SE4FS%\install\wpkg\wpkg-client.vbs` (l.23) par un **download HTTP** depuis l'URL Apache ; conserver `%SE4FS%`.
  - [x] **Profil par-hôte (D9 — CONFIRMÉ)** : l'agent **dépose** `profiles.xml`/`hosts.xml` localement (depuis l'état `applications`) → pas d'endpoint dynamique. *(mini-route Laravel écartée)*
  - [x] **Installeurs (D11)** : **inchangé** — restent sur SMB `\\<SE4FS>\install` (`%Z%\packages`, `/noDownload`).
  - [x] **Déclencheur GPO (D2)** : retirer la **publication** de `se4_wpkg` (`app/Gpo/Services/WpkgGpoSynchronizer.php` + point d'appel + action page `wpkg-deployment`) ; documenter le **déliage lab** (Henri, hors worktree).
  - [x] **Config agent** : URL du sous-dossier dans `config/agent.php` (servie à l'agent) → `config:cache` + chown www-admin /vm.

- [x] **T11 — Golden files + tests croisés contrat** (AC6)
  - [x] Ajouter l'item `applications` (4 clés, portée `machine`) à `tests/Fixtures/Agent/state.v1.json` ; ajouter l'illustration d'inventaire à `tests/Fixtures/Agent/report.v1.json`
  - [x] Recalculer les hashes : **(1)** recalculer & écrire le `hash` de chaque item, **PUIS (2)** recalculer le hash d'état ; relever d'abord la valeur courante (`FROZEN_STATE_HASH` actuel = `6f0ff33e8ea114d28f67094042bea656a68d6cfdafa01ee6ad9f9537dff377fb`) qui bouge à chaque story de revue
  - [x] Bumper `ContractV1Test::FROZEN_STATE_HASH` (PHP) et `agent/shared/hasher_test.go` `frozenStateHash` (Go) à la **même** valeur ; ajuster `agent/shared/contract_test.go` (compte portée `machine` +1)
  - [x] Ajouter un commentaire `// Story 27.5` dans `ContractV1Test.php`

- [x] **T12 — Documentation (suit le code)** (AC6)
  - [x] `docs/agent/contract-v1.md` : §7.4 `applications` (table de payload, invariant central « le payload ne porte jamais un id de catalogue/pivot, juste l'app_id concret », « désactiver = cesser de gérer », « moteur WPKG non absorbé ») + note §6 (champ d'inventaire additif, évolution mineure)
  - [x] `docs/agent/state-providers.md` (section `ApplicationsStateProvider`), `agent/README.md` (puce handler), `docs/qa/domains/agent.md` (`## Story 27.5` append-only), `docs/qa/README.md` (une ligne)

- [x] **T13 — Validation (host + /vm)**
  - [x] **Hôte** : `cd agent && ~/go-toolchain/go/bin/go test ./... && go vet ./...` + cross-compile `CGO_ENABLED=0 GOOS=windows GOARCH=amd64 go vet ./... && go build ./...` + `gofmt -l` ; `php -l` sur les fichiers PHP touchés ; grep garde NFR7 vide sur le provider
  - [ ] **/vm (action Henri, hors worktree)** : `php artisan migrate:status` puis `migrate --force` (table `agent_application_inventory` Pending) ; `php artisan test --filter Agent` (PHPUnit indisponible dans le worktree, varchar non appliqué en SQLite) ; e2e : assigner une app à un parc → vérifier l'item `applications` dans `GET /api/v1/agent/state` (curl) → poste converge → inventaire en base

## Dev Notes

### Le pattern Epic 27 (à imiter à l'identique)

Story 27.5 est un **provider en lecture seule** d'un système métier **existant** (WPKG / `Application`), pas un créateur de catalogue — exactement le cas 27.4 (`app_config` lisant `AppCustomizationService`). **Ne créer aucune table de règles** : l'ensemble cible existe déjà (`WorkstationPackagesResolver`). La **seule** nouvelle table est `agent_application_inventory` (côté **rapport**, pas côté règles — AC4).

Provider modèle à copier : **`AppConfigStateProvider`** (`app/Services/Agent/Providers/AppConfigStateProvider.php`) — projette un service métier existant, aggregate, scope `machine`, un item par sous-entité, payload concret. Handler modèle : **`handler_app_config.go`** + son `_windows.go` (struct + `Ops` interface injectée + cœur testable hôte).

### Frontière « un tuyau, deux outils » — ce que l'agent fait / ne fait PAS

| | Agent (le tuyau) | WPKG (l'outil déclaratif) |
|---|---|---|
| Quand installer | **Déclenche** au cycle (boot/login/timer/forcer) | — |
| Quelles apps | **Projette** l'ensemble cible (`computePackages`) en état + l'utilise comme clé d'inventaire | Fetch son profil/catalogue depuis les **endpoints SE5 natifs** (substitués) — `wpkg-se4.js` patché |
| Livraison du client | **Donne l'URL** au bootstrap + dépose le profil local (l'agent ne télécharge pas) | le **client** télécharge le bundle depuis le sous-dossier **Apache statique** (pré-substitué) ; installeurs depuis SMB (D11) |
| Comment installer | **rien** | `<check>/<install>/<upgrade>`, dépendances, versions |
| Détection de présence | lit le résultat WPKG (ne réimplémente pas `<check>`) | son moteur de check |
| Rapport | statut **par type** + inventaire **par app** | (peut écrire son propre log local que l'agent lit) |

**Conséquence :** l'`ApplicationsStateProvider` projette **l'ensemble** (les `app_id`), pas les recettes d'installation. Le `payload` = `{app_id, name}` — `app_id` est l'identifiant de paquet WPKG (= le `package-id` émis dans `profiles.xml` = `Application::app_id`). Pas de version, pas de `<check>`, pas de `<install>` dans le payload (c'est la propriété de `packages.xml`). Quand une affectation change → l'ensemble change → le hash d'état change → 304 manqué → l'agent re-déclenche WPKG.

### Lecture PG-pure de l'ensemble cible (NFR7 — le point sensible)

`WorkstationPackagesResolver::resolve()` (public, ligne 50) wrappe `Cache::remember(...)` (APCu, TTL 1000s) → **interdit dans un provider** (critère Keycloak). La logique d'union (4 sources : profils/apps × poste/groupes, lignes archivées filtrées) + BFS de dépendances transitives vit dans `computePackages()` (privé, ligne 86) — **cache-free**. T2 expose cette résolution non cachée ; le provider la réutilise → **single source of truth** sur « ce que WPKG va installer », sans dupliquer l'algorithme (réimplémenter l'union+BFS dans le provider = risque de divergence agent vs WPKG réel — interdit).

⚠️ **Préempter un faux positif de revue.** La précédence 27.3bis (où `AssociationsResolver` APCu n'a PAS été réutilisé, et la validation WPKG vit dans la Livewire `deployedPackagesForParc()`) concernait une **lecture de cache pour validation UI**. Ici c'est différent : la **résolution de l'ensemble** est la logique métier centrale ; on la réutilise **non cachée**. Le grep garde reste vide sur le provider car la méthode exposée ne touche aucun cache. Documenter ce raisonnement dans le docblock du provider.

### Maille du candidat (Décision D4)

`computePackages($hostname)` résout déjà l'union **poste + groupes + dépendances** par hostname — c'est la résolution **finale**. Le provider reçoit un `TargetContext` mais l'ensemble cible WPKG est mono-résolution (pas une liste de candidats par maille à composer). **Choix retenu :** émettre chaque app comme candidat `StateMaille::Broadcast` (résolution déjà finale) — le compilateur en `aggregate` fait l'union/dédup sans précédence à arbitrer (tous Broadcast). C'est une **adaptation** documentée (iso le collapse mono-WG de 27.4, finding 🟠 #2) du modèle « liste de mailles » de l'Epic 27 à une API de résolution mono-sortie. Alternative écartée : ré-étiqueter chaque app par sa maille d'origine (poste vs groupe) — coûteux, sans valeur (aggregate ⇒ union de toute façon, et la précédence ne joue pas pour un type aggregate). À documenter dans le docblock + `state-providers.md`.

### `test()` : déclencheur fin, pas vérificateur (Décision D5 — comporte un spike)

Le handler **ne réimplémente pas** la détection de présence de WPKG (`<check>`). `Test` doit répondre « l'ensemble cible est-il déjà appliqué ? » au **niveau** (pas à l'événement). Approche recommandée : comparer l'ensemble cible (hash agrégé, déjà calculé par le moteur `AggregateHash`) au **dernier-appliqué persisté** (le store applied-state existe, conservé en 27.8 D-B) **et** à l'état de réussite du dernier run WPKG. Si inchangé + dernier run OK → `compliant` (pas de re-déclenchement). Sinon → `Apply` (déclenche WPKG).

🔬 **Lecture du résultat WPKG (Décision D5 — TRANCHÉE).** Le handler **déclenche `wpkg.js`** (capture son **code de sortie** = signal de run global), puis **lit `wpkg.xml`** — la base d'état locale de WPKG, source de vérité **par paquet** (paquets installés + version). Approche retenue : structurée, idiomatique WPKG, level-triggered (l'agent lit l'état courant), et elle **survit à l'extinction 27.6** (artefact client, pas l'endpoint serveur legacy). Écarté : (b) exit code seul = pas de granularité par paquet ; (c) capter le POST `wpkg/reports` que WPKG émet = couplage à un endpoint legacy qui meurt en 27.6. `Test()` lit `wpkg.xml` (« désiré ⊆ installé ? »), `Apply()` déclenche puis relit. **Spike réduit (seule inconnue restante)** : confirmer le chemin exact (`%SystemDrive%\wpkg.xml` ou dossier settings WPKG) et le format sur un poste lab — l'approche, elle, est figée.

### Déclenchement concret & dépendances WPKG (vérifié sur la VM, lecture seule)

**📦 Le client WPKG n'est PAS dans le code applicatif.** `wpkg-se4.js` (le `wpkg.js` custom, 349 Ko), `wpkg-client.vbs` (orchestrateur), `wpkg.cmd` sont livrés par le **paquet Debian `sambaedu-wpkg`** (confirmé `dpkg -S`) dans `/var/sambaedu/unattended/install/wpkg/`, servis aux postes via SMB `\\<SE4FS>\install\wpkg\` et copiés en `%WinDir%\wpkg-client.vbs` à l'install. Les dépôts git (SE5 + legacy) ne portent que le **côté serveur** (les `*_xml_out.php` legacy + les contrôleurs SE5 qui génèrent les XML). → modifier le client = dépôt de packaging `sambaedu-wpkg`, hors de cette story ; 27.5 ne fait que **déclencher** ce client déjà déployé. ⚠️ Dépendance de déploiement : `%WinDir%\wpkg-client.vbs` doit être présent sur le poste (copié à l'install ; potentiellement **absent sur un poste migré** non passé par le robocopy WinPE — cf. mémoire helpers client manquants).

**🟢 Aucune dépendance AD dans le chemin WPKG (NFR7 OK).** `wpkg-client.vbs` + `wpkg-se4.js` ne touchent l'AD que via `getHostGroups()` (`WinNT://…`) **wrappé `try/catch`, jamais utilisé** : le `hosts.xml` SambaEdu matche par **NOM** (`<host name="X" profile-id="X"/>`), pas par groupe AD → tolérant à l'absence d'annuaire. L'identité du poste = **local** (`WScript.Network.ComputerName`). Le matching poste→paquets est **100 % serveur** (résolu en pgsql, servi en HTTP). Le `.vbs` n'a **aucune** requête LDAP.

**Le déclencheur réel = l'orchestrateur VBS, pas `wpkg.js` en direct.** L'agent reproduit `cscript //B //NoLogo wpkg-client.vbs /NOTempo`. **Deux prérequis** que l'agent doit garantir — **aucun n'est de l'AD** :
- variable **machine `%SE4FS%`** posée (nom du serveur de fichiers) → le `.js` en dérive `http://<se4fs>/wpkg/…` (posée aujourd'hui par `wpkg.cmd` via `SETX SE4FS … /m`) ;
- partage **`\\<SE4FS>\install`** accessible (lecteur `Z:`) pour les installeurs (`%Z%\packages\…`).

**✅ Deux écarts de livraison WPKG pré-existants — CORRIGÉS par cette story (livraison native, T10) :**
1. **Substitution `SE4FS_NAME`** (`<variable … value="se4fs_name" source="sambaedu"/>`, clé conf serveur — **pas l'AD**) : portée par le nouveau chemin de serving SE5 (T10/D7).
2. **Noms d'endpoints** : `wpkg-se4.js` est **patché** (T10/D8) pour viser les endpoints SE5 natifs ; le shim legacy `*_xml_out.php` (qui 500) est supprimé.

> Réf. (non versionné, VM) : `/var/sambaedu/unattended/install/wpkg/{wpkg-client.vbs,wpkg-se4.js,wpkg.cmd,packages.xml}` ; legacy substitution `../sambaedu/wpkg/packages_xml_out.php:46-56` + `../sambaedu/includes/config.inc.php` (`get_config`) ; SE5 `app/Services/AppStore/PackagesXmlService.php` (sans substitution) ; SE5 `app/Wpkg/Deployment/Http/Controllers/{HostsXml,ProfilesXml}Controller.php`.

### Livraison WPKG native SE5 (Décisions D6–D11, 2026-06-18)

Refonte décidée par Henri : SE5 devient autonome pour livrer WPKG (plus de dépendance au serving legacy ni, pour les scripts, au `.deb`).

**Périmètre par artefact — « pareil pour tous » vs « custom par-poste » (clarif. Henri 2026-06-18) :**

| Artefact | Portée | Livraison |
|---|---|---|
| `wpkg-se4.js`, `wpkg-client.vbs`, `wpkg.cmd` | **pareil pour tous** (config instance : nom serveur, lu via `%se4fs%`/runtime) | Apache statique (substitué **1×** à la génération) |
| `packages.xml` (catalogue global) | **pareil pour tous** (instance ; `SE4FS_NAME` substitué **1×**) | Apache statique |
| `profiles.xml` + `hosts.xml` (sélection + mapping) | **custom PAR-POSTE** | **déposés par l'agent** (depuis l'état `applications` — D9) |

→ Le **seul** vrai custom par-poste = `profiles.xml`/`hosts.xml`, et l'agent l'a **déjà** → **aucun serving dynamique/custom côté serveur** ; Apache ne sert que du statique same-for-all. (Le catalogue vient d'Apache, pas de l'agent : l'agent a la *liste* d'`app_id`, pas les recettes d'install.)

Flux cible :

1. **Génération (SE5)** — SE5 génère le **bundle pré-substitué** (scripts `resources/wpkg/*` + catalogue `packages.xml`, variables résolues `SE4FS_NAME`/base URL) dans un **sous-dossier public spécifique** (régénéré à la pose / au changement de conf, **pas** par requête — D7).
2. **Serving (Apache statique, D10)** — Apache sert ce sous-dossier **en statique** (pas tout `/storage`, **pas** via Laravel). Auth = LAN/restriction du sous-dossier (iso-legacy). **Zéro charge Laravel** sur le gros download.
3. **Patch client (D8)** — `wpkg-se4.js` patché (`wpkg_base` l.321 + fetch l.479/481/483 + log l.8441 → SE5/local ; namespaces `wpkg.org` intouchés). `wpkg.cmd` patché : `XCOPY` SMB (l.23) → **download HTTP** depuis l'URL Apache.
4. **Agent (D7) — donne l'URL, ne télécharge pas** — l'URL du sous-dossier est en **config agent** (`config/agent.php`, servie à l'agent). Le handler **injecte l'URL** dans le bootstrap + **dépose localement** `profiles.xml`/`hosts.xml` (depuis son état `applications` — D9) + **déclenche** `wpkg.cmd`/`wpkg-client.vbs` + lit `wpkg.xml`. Le **client** télécharge le bundle (pas l'agent : ni blocage, ni goroutine).
5. **Profil par-hôte (D9)** — l'agent dépose localement (il a déjà la liste dans l'état) → **zéro endpoint dynamique Laravel**. *(alt confirmable : mini-route)*
6. **Installeurs (D11)** — **SMB inchangé** (`/noDownload`, `%Z%\packages`).
7. **Shim legacy supprimé** — serving WPKG legacy (`*_xml_out.php` → 500, gating `legacy.config.channel`) retiré. Pas l'extinction globale (27.6) : `winget_out`/`linux_out`/`/gpo/*` intouchés.

### Inventaire vs grain figé 27.8 (Décision D1 — la subtilité centrale)

Le rapport est **une ligne par `(workstation_id, type)`** (contrainte `UNIQUE` DB + validation `distinct` + fold `MergeReportItemsByType` worst-status). `applications` étant aggregate de N apps, son **verdict de conformité reste UN statut par type** (respecte 27.8, circule dans `ConformityService` + l'UI conformité **gratuitement**). L'**inventaire par app** est porté **en plus**, en champ additif sur l'item `applications` du rapport, et stocké dans `agent_application_inventory` **sous** la ligne d'état par type. **Ce n'est PAS** une réintroduction du grain `item × poste` (le débat 27.8 portait sur le **statut/verdict** ; l'inventaire est une **donnée**, pas un verdict). Comptage de sièges = lignes d'inventaire `status ∈ {compliant, drift}` (toutes deux = installé ; `error` = non installé). **Aucune tolérance de dérive, aucun `mode`, aucun `drifted_allowed` réintroduit.**

Évolution de contrat (§9) : ajouter un champ optionnel à l'item de **rapport** (agent→serveur) = **mineur**, reste `v1` (le serveur lit le nouveau champ ; un vieux serveur l'ignorerait). Bumper les golden + note §6. (Le « exactement 4 clés » du contrat concerne l'item d'**état** §3, pas l'item de **rapport** §6.)

### Conventions transverses (rappel handler)

- **Item d'état = 4 clés** `{type, semantics, payload, hash}`. **Pas** de `mode()` sur le provider, **pas** de `mode` au payload (figé 27.8, 5 sources). Le `StateProvider` ne déclare que `type/semantics/scope/itemsFor`.
- **Machine d'états STRICT** (`agent/shared/engine.go:135-141`) : `ResolveItemStatus(isCompliant)` → `compliant` ou `drift`(+apply). 3 statuts : `compliant|drift|error` ; `error` ⇒ `detail` non vide.
- **`Handler` interface Go** : `Test(items)(bool,error)` + `Apply(items)error` uniquement (le moteur construit l'item de rapport).
- **Isolation** : un handler en échec ne tue ni les autres types ni le rapport (recover au dispatch). Un échec d'install d'une app → `error` du type `applications`, les autres types convergent.
- **`sourceId` déterministe & injectif** (pilote l'ordre aggregate → l'ETag). `Application::id` convient (PK stable).
- **`hash` calculé par le compilateur** (`StateCompiler.php:140-152`, `hashItem`), jamais par le provider.
- **Tokens** (`<se4fs>`, etc.) substitués **localement** par l'agent — jamais de secret au payload. (Peu probable ici : `app_id`/`name` sont des constantes métier.)
- **NFC** : si le handler compare des chaînes réel-vs-cible (noms de paquets), normaliser en NFC (`golang.org/x/text`) pour éviter de faux `drift` (Windows peut produire du NFD).

### Décisions de cadrage (arbitrées — Henri 2026-06-18)

| # | Décision | Choix retenu | Alternative écartée |
|---|----------|----------------|-------------|
| **D1** | Inventaire par app (AC4) | **✅ OPTION A** — champ additif `inventory` sur l'item `applications` du rapport + table `agent_application_inventory` (comptabilisable, **sans UI**) ; verdict conformité **reste par type** (grain 27.8 intact) | (B : différer l'inventaire à la story licences — écartée ; l'AC1 « rapporte le résultat par application » + l'AC3 l'exigent) |
| **D2** | Déclencheur GPO `se4_wpkg` | **✅ Henri 2026-06-18 : RETIRER dès 27.5** — SE5 cesse de publier `se4_wpkg` + délier côté lab → l'agent **seul** déclencheur (test sans collision/double-déclenchement). Nettoyage résiduel du code cohérent 27.6 | (initial : rendre superflue en 27.5, suppression physique en 27.6) |
| **D3** | Découplage livraison WPKG du kill-switch | **✅ Absorbé par D6** : le shim legacy WPKG (dont le gating `legacy.config.channel`) est **supprimé** et remplacé par le serving `/storage` natif → la question du découplage devient sans objet | — |
| **D4** | Maille du candidat | `Broadcast` (résolution `computePackages` déjà finale) | Ré-étiqueter par maille d'origine (sans valeur en aggregate) |
| **D5** | Lecture résultat par paquet | **✅ Recommandé (Henri délègue) : lire `wpkg.xml`** (base d'état locale WPKG = source de vérité par paquet, structurée, **survit à 27.6**) **+ code de sortie de `wpkg.js`** (signal de run). Spike réduit : confirmer **chemin/format de `wpkg.xml`** sur un poste lab | (b) exit code seul = pas de granularité par paquet ; (c) capter le POST `wpkg/reports` legacy = couple à un endpoint qui meurt en 27.6 |
| **D6** | Livraison WPKG (écarts + canal) | **✅ Henri 2026-06-18 : TOUT-EN-UN — livraison NATIVE SE5.** Shim legacy supprimé ; bundle pré-substitué servi en **Apache statique** ; agent **donne l'URL** & déclenche (le client télécharge) ; profil déposé par l'agent ; `wpkg-se4.js`+`wpkg.cmd` patchés. Élargit le périmètre (assumé) | (prérequis séparé — écarté) |
| **D7** | Substitution + qui télécharge | **✅ Henri 2026-06-18 : substitution serveur à la GÉNÉRATION** (fichiers pré-substitués dans le sous-dossier). L'**agent ne télécharge PAS** — il **donne l'URL** au bootstrap ; le **client** télécharge depuis Apache (zéro charge Laravel, pas de goroutine/blocage) | Agent télécharge (rejeté : occupe Laravel + bloque l'agent) |
| **D8** | Patcher `wpkg-se4.js` (URLs) | **✅ OUI** — `wpkg_base` (l.321) + `web_{packages,profiles,hosts}_file_name` (l.479/481/483) + log (l.8441) → endpoints SE5/fichiers locaux ; namespaces `wpkg.org` (l.534-538) **intouchés**. On devient mainteneur du script | Faire répondre SE5 aux noms legacy `*_xml_out.php` (rejeté : on garde le shim qu'on tue) |
| **D9** | Profil par-hôte | **✅ CONFIRMÉ Henri 2026-06-18 : l'agent DÉPOSE `profiles.xml`/`hosts.xml` localement** (depuis son état `applications` — il a déjà la liste) ; `wpkg-se4.js` patché pour lecture locale → **zéro endpoint Laravel** | Mini-route dynamique (re-Laravel — moins aligné « pas de charge Laravel ») |
| **D10** | Serving & auth du bundle | **✅ Henri 2026-06-18 : Apache sert un SOUS-DOSSIER spécifique en STATIQUE** (pas tout `/storage`, pas via Laravel) ; auth = LAN/restriction du sous-dossier (iso-legacy) ; **pas de bearer** | Route Laravel + bearer (rejeté : charge Laravel inutile) |
| **D11** | Binaires d'installeurs (`%Z%\packages\…`) | **✅ reco : SMB inchangé** — `/noDownload` (WPKG lit `%Z%`), `<install>` par-paquet en `%Z%`, `PackagesXmlService` strip déjà `<download>` → repointer HTTP = grosse chirurgie hors-but. Scripts+catalogue HTTP, installeurs SMB | Servir aussi les payloads en HTTP (follow-up éventuel) |

### Project Structure Notes

- Provider serveur : `app/Services/Agent/Providers/ApplicationsStateProvider.php` (couche Services, jamais dans un controller).
- Code agent : `agent/shared/handler_applications.go` (+ test) et `agent/windows/handler_applications_windows.go` — jamais mélangé à `app/`.
- Tests : `tests/Unit/Services/Agent/ApplicationsStateProviderTest.php`, `tests/Feature/Api/V1/Agent/` (ingest inventaire), `agent/shared/handler_applications_test.go`.
- Migration : `database/migrations/[date]_create_agent_application_inventory_table.php` (une migration par feature finale).
- **Bundle WPKG versionné** : `resources/wpkg/{wpkg-se4.js, wpkg-client.vbs, wpkg.cmd, packages.xml, README.md}` (importé du `.deb` `sambaedu-wpkg`, déjà staged) ; servi via un **sous-dossier Apache statique** (pré-substitué) ; profil par-hôte **déposé localement par l'agent** ; URL du sous-dossier en `config/agent.php`.
- **Worktree `wpkg`** : ne pas interagir avec la VM depuis le worktree ; les actions /vm (migrate, PHPUnit, e2e) sont des actions Henri. Inotify ne propage pas les deletes → signaler les fichiers à `trash`, ne jamais `rm -rf`.

### References

- **Épopée & ACs** : `_bmad-output/planning-artifacts/epics-agent-desired-state.md:756-772` (Story 27.5), `:58` (FR21), `:141` (FR coverage), `:174-177` (Epic 27).
- **Contrat** : `docs/agent/contract-v1.md` §3 (item 4 clés), §5 (STRICT 27.8), §6 (rapport), §7 (identifiants figés), §7.3 (`app_config`, pointe vers 27.5), §9 (évolution mineure/majeure).
- **Architecture** : `_bmad-output/planning-artifacts/architecture-agent-desired-state.md:248-260` (D1 projection), `:262-272` (D2 semantics), `:380-409` (naming), `:459-487` (interfaces provider/handler), `:522-529` (anti-patterns), `:638-639` (WPKG déclenché par l'agent).
- **Provider/contrat (code)** : `app/Services/Agent/Contracts/StateProvider.php:32-50` ; `app/Services/Agent/StateContract.php:23,44-54` ; `app/Services/Agent/StateCompiler.php:140-152,176-208,318-328` ; `app/Services/Agent/StateCandidate.php` ; `app/Services/Agent/TargetContext.php` ; `app/Providers/AgentServiceProvider.php` (tableau StateCompiler, après `AppConfigStateProvider`) ; enums `app/Enums/{ResourceSemantics,StateScope,AgentResourceStatus,StateMaille}.php`.
- **Provider modèle** : `app/Services/Agent/Providers/AppConfigStateProvider.php` (projection service existant, aggregate, machine).
- **WPKG (domaine)** : `app/Wpkg/Deployment/Services/WorkstationPackagesResolver.php:50` (resolve cached), `:86` (computePackages privé), `:162` (BFS deps) ; `app/Models/Application.php` (`app_id`, table `applications`) ; pivots `application_workstation(_group)`, `app_profile_*`, `application_dependencies` ; `app/Wpkg/Deployment/Http/Controllers/{ProfilesXml,HostsXml}Controller.php` ; `routes/web.php:704-710` (middleware `legacy.config.channel` à retirer) ; `app/Services/AppStore/PackagesXmlService.php` (packages.xml disque) ; `app/Gpo/Services/WpkgGpoSynchronizer.php` (GPO `se4_wpkg`, suppression → 27.6) ; `resources/views/ipxe/windows/cmd/wpkg.blade.php` (chemins client `wpkg-client.vbs`/flags).
- **Livraison native SE5 (T10)** : `resources/wpkg/*` (bundle versionné, importé du `.deb`) ; **sous-dossier Apache statique** (pré-substitué, **pas** via Laravel) ; substitution à la génération iso `../sambaedu/wpkg/packages_xml_out.php:46-56` (`get_config`) ; **patch** `resources/wpkg/{wpkg-se4.js (l.321/479/481/483/8441), wpkg.cmd (l.23)}` ; profil par-hôte **déposé par l'agent** ; `config/agent.php` (URL) ; shim legacy `routes/web.php:704-710` (`*_xml_out.php` + `legacy.config.channel`) à **supprimer** ; GPO `app/Gpo/Services/WpkgGpoSynchronizer.php` (publication à retirer).
- **Rapport/ingest** : `app/Services/Agent/Reporting/ReportIngestService.php` ; `app/Http/Requests/Api/V1/Agent/ReportRequest.php:46-71` ; migration `2026_06_11_140000_create_agent_report_tables.php` (modèle de table) ; `app/Services/Agent/Reporting/ConformityService.php` (conformité par type — inchangée) ; UI conformité `resources/views/pages/parc/machines/[id]/_partials/agent-conformity.blade.php` (hérite gratuitement le statut par type).
- **Agent (Go)** : `agent/shared/engine.go:98-101` (Handler), `:135-141` (ResolveItemStatus STRICT), `:147-155` (AggregateHash), `:245-300` (isolation/detail) ; `agent/shared/handler_app_config.go` + `agent/windows/handler_app_config_windows.go` (modèle) ; `agent/windows/main_windows.go` (MachineEngine.Handlers) ; `agent/shared/contract.go:34` (`applications`) ; `agent/shared/loop.go` (BuildReport / MergeReportItemsByType) ; `agent/shared/contract_test.go:38` (comptes portée) ; `agent/shared/hasher_test.go:65` (frozenStateHash).
- **Golden** : `tests/Fixtures/Agent/state.v1.json`, `tests/Fixtures/Agent/report.v1.json` ; `tests/Unit/Services/Agent/ContractV1Test.php` (`FROZEN_STATE_HASH = 6f0ff33e…`).

## Previous Story Intelligence

### Story 27.4 (`app_config`, prédécesseur immédiat, commit `0ad3a5a`) — leçons devenues conventions

- 🔴 **#1 — Portée de livraison ≠ portée de résolution.** 27.4 a d'abord pris `scope=session` (combiner parc+user à la résolution) puis a dû corriger en `machine`/SYSTEM car écrire `%ProgramFiles%` depuis le compagnon user = `ACCESS_DENIED`. **WPKG installe machine-wide → `scope=machine`, handler en `MachineEngine`/SYSTEM.** (Erreur la plus coûteuse de 27.4 — ne pas la répéter.)
- 🟠 **#7 — Ne jamais rapporter un faux `compliant`.** Si un artefact étranger bloque l'agent, rapporter `error` (jamais écraser l'étranger, jamais mentir). Transposition WPKG : un échec d'install est `error` + `detail`, jamais un `compliant` optimiste.
- 🟠 **#3 — Parité de canonicalisation.** Si le handler compare une relecture à la cible, un seul canonicaliseur partagé (`shared.CanonicalJSON`, `UseNumber`) + test de parité (sinon boucle de réécriture). Pertinent si le handler compare des ensembles sérialisés.
- 🟡 **#8 — Écriture atomique avec cleanup** (tmp+rename, `defer os.Remove` annulé par un flag `renamed`). Pertinent si le handler écrit un fichier d'état local.
- **Provider lit, ne crée pas.** 27.4 a réutilisé la table 4.8 sans migration de règles. 27.5 réutilise WPKG sans table de règles (seule nouveauté = inventaire, côté rapport).
- **Tests 27.4** : `go test ./...` + `go vet` (linux + GOOS=windows) PASS sur l'hôte ; **PHPUnit non joué dans le worktree** (vendor absent, VM interdite depuis worktree) → action Henri /vm `php artisan test --filter Agent`. Même caveat ici.

### Cross-story (27.1 → 27.3bis)

- **MachineEngine** inventé en 27.3 (premier type machine, `registry` HKLM) : `Agent.convergeMachine()` lit le cache, `ItemsFromScope(machine)`, `RunPass`, persiste l'applied-state sous ProgramData (ACL SYSTEM). 27.4 y a ajouté `app_config`. **27.5 y ajoute `applications`.**
- **`MergeReportItemsByType`** (`agent/shared/dropcollect.go`) : worst-status-wins si un type vient de deux portées. `applications` étant machine-only, sans objet — mais le **fold worst-status intra-type** (sur les N apps) est le même principe, appliqué dans le handler/BuildReport.
- **Précédence inversée `logical > physical`** (27.3 D-Q3, `StateCompiler::specificity()`) : sans effet ici (type aggregate, candidats Broadcast).
- **NFR7 grep guard** : `grep -E 'ldap|apcu|samba-tool|Cache::|LdapRecord|PackagesXml'` sur le provider doit être **vide**. Précédent 27.3bis : résolveur APCu **non réutilisé**, cross-check WPKG mis dans la Livewire (PG-pure). Ici : réutiliser la résolution **non cachée** (cf. Dev Notes).
- **Interop Windows native, `go.mod` inchangé** : seule exception ici = le shell-out de déclenchement WPKG (isolé dans `ApplicationsOps` Windows, justifié).
- **Marqueur de périmètre / level-triggered** : ne jamais toucher un objet hors périmètre SambaEdu ; « désactiver = cesser de gérer » (une app retirée des affectations disparaît de l'état → l'inventaire la libère, l'agent ne la désinstalle que si WPKG le prévoit via `<remove>`).

### Git intelligence (commits handler de l'epic)

Commits handler de référence : **27.1 `46d6ad1`**, **27.2 `bd8bd6e`**, **27.3 `4fa9b40`**, **27.3bis `7f4ecee`**, **27.4 `0ad3a5a`**. Diff-type d'une story handler = { provider + 1 ligne register + const modèle } × { handler shared + handler windows + test } × { golden state + hashes PHP/Go + contract_test counts } × { docs append-only }. 27.5 ajoute en propre : la table d'inventaire + l'ingest + la **refonte de la livraison WPKG native** (bundle Apache statique pré-substitué, profil déposé par l'agent, patch `wpkg-se4.js`+`wpkg.cmd`, suppression shim legacy + GPO `se4_wpkg`).

## Testing Requirements

- **Hôte (worktree, autorisé)** : Go `cd agent && ~/go-toolchain/go/bin/go test ./... && go vet ./...` (linux) + cross `CGO_ENABLED=0 GOOS=windows GOARCH=amd64 go vet ./... && go build ./...` + `gofmt -l` (0). PHP : `php -l` sur les fichiers touchés. NFR7 : grep garde vide sur le provider.
- **Golden / NFR13** : tests croisés PHP ⇄ Go sur `state.v1.json` (`TestHashStateGoldenMatchesFrozenHash`, `TestHashItemCrossValidatedAgainstPhp`). Ordre de recalcul : items d'abord, état ensuite ; relever la valeur courante du tree (elle bouge à chaque story de revue). Bumper PHP **et** Go à la même valeur.
- **Provider test** : identité figée en 1er, observers AD désactivés en `setUp` (pas de LDAP hôte), payload sans float / sans id de scope, PG-pure.
- **Ingest test** : `tests/Feature/Api/V1/Agent/` — POST report avec `items[].applications` → lignes `agent_application_inventory` upsertées + ligne d'état par type inchangée ; app absente du rapport → ligne d'inventaire nettoyée.
- **/vm (action Henri, hors worktree)** : `migrate:status` → `migrate --force` (`agent_application_inventory` Pending ; SQLite n'applique pas varchar → overflow PG 22001 invisibles en test) ; `php artisan test --filter Agent` ; **pas** de `route:cache`/`config:cache` requis sauf si une route/config est ajoutée (T10 retire un middleware sur des routes existantes → vérifier `route:cache` + chown www-admin sur /vm) ; e2e curl `GET /api/v1/agent/state`.
- **Modèle dev / revue** : dev **opus** (`claude-opus-4-8[1m]` ; toutes les stories agent de l'Epic 27 l'ont utilisé, Fable indisponible) ; pattern adversarial dev opus → review sonnet → 2e avis opus → doc `_bmad-output/codeReviews/27-5.md`.

## Dev Agent Record

### Agent Model Used

claude-opus-4-8

### Debug Log References

- Validation hôte (worktree) : `cd agent && ~/go-toolchain/go/bin/go test ./...` → PASS (`sambaedu/agent/shared` ok) ; `go vet ./...` (linux) clean ; cross `CGO_ENABLED=0 GOOS=windows GOARCH=amd64 go vet ./... && go build ./...` clean ; `gofmt -l` vide sur tous les fichiers touchés (5 fichiers pré-existants non liés restent flaggés, hors périmètre).
- `php -l` clean sur tous les fichiers PHP modifiés/créés.
- NFR7 grep `ldap|apcu|samba-tool|Cache::|LdapRecord|PackagesXml` sur `ApplicationsStateProvider.php` → vide hors commentaires (le docblock documente la règle).
- Hash d'état recalculé : item `applications` `145bf532…0237a2d` ; hash d'état `283f391d005d2ec42b305d0c093ed48b16b2bfa4f9bc6b895c65ccf61ba29daf`. Cross-check PHP↔Go : `TestHashStateGoldenMatchesFrozenHash` + `TestHashItemGoldenItemsMatchTheirHashFields` (10 items) PASS — les deux côtés calculent la MÊME valeur.
- PHPUnit non joué dans le worktree (vendor absent, VM interdite) → tests provider/feature écrits + lintés, exécution = action Henri /vm (`php artisan test --filter Agent`).

### Completion Notes List

- **T1-T5 (serveur, canal agent)** : `Application::TYPE_APPLICATIONS` ajouté ; `WorkstationPackagesResolver::computePackages()` rendue PUBLIQUE (NON cachée — le `Cache::remember` reste exclusivement dans `resolve()`) ; `ApplicationsStateProvider` (aggregate/machine, maille Broadcast, payload `{app_id, name}`, PG-pur NFR7, jamais `resolve()`) ; enregistré dans `AgentServiceProvider` après `AppConfigStateProvider` ; test unitaire (identité figée, payload sans float/id de scope, PG-pur cache-free, union mailles + dépendances).
- **T6-T8 (agent Go)** : `shared/handler_applications.go` (`ApplicationsSpec`, `ApplicationsOps{ListInstalled, TriggerWpkg}`, `WpkgResult`, `ApplicationsHandler` — `Test` = désiré⊆installé lu dans wpkg.xml, `Apply` = déclenche WPKG, level-triggered/idempotent, NFC, effort-maximal, inventaire exposé via `InventoryReporter`) + test table-driven (faux Ops, machine STRICT, isolation) ; `windows/handler_applications_windows.go` (dépose profiles.xml/hosts.xml localement D9, déclenche `cscript wpkg-client.vbs /NOTempo`, donne l'URL bundle, lit wpkg.xml — spike D5 : tente `%SystemDrive%\wpkg.xml` + `%PROGRAMDATA%\wpkg.xml`) ; enregistré dans `MachineEngine.Handlers` (SYSTEM). `go.mod` inchangé (`golang.org/x/text` déjà présent).
- **T9 (inventaire)** : champ additif `inventory` sur `ReportItem` Go (omitempty) ; migration `agent_application_inventory` (FK cascade, UNIQUE(workstation_id, app_id)) + modèle `AgentApplicationInventory` ; ingest upsert + nettoyage level-triggered dans `ReportIngestService` (même transaction) ; validation `ReportRequest` (`items.*.inventory` nullable) ; Feature test ingest. Le moteur joint l'inventaire sur TOUS les chemins (y compris `error`) via `withInventory`.
- **T10 (livraison native SE5)** : shim legacy supprimé (routes `/wpkg/hosts.xml` + `/wpkg/profiles.xml` retirées, 2 tests contrôleur `git rm`) ; `wpkg-se4.js` patché (`wpkg_base`→`%SE4_WPKG_BUNDLE_URL%`/défaut bundle, profils LOCAUX via `hosts_path`/`profiles_path`, noms web→`packages.xml`/`profiles.xml`/`hosts.xml`, POST log legacy neutralisé ; namespaces wpkg.org INTOUCHÉS) ; `wpkg.cmd` patché (download HTTP bitsadmin/pwsh au lieu de XCOPY SMB) ; `WpkgBundleGenerator` + commande `php artisan wpkg:bundle` (substitue `SE4FS_NAME` iso `packages_xml_out.php`, copie verbatim les scripts) ; `config/agent.php` (`wpkg_bundle_path`/`wpkg_bundle_url`) + `shared.WpkgBundlePath` ; publication GPO `se4_wpkg` retirée de la page wpkg-deployment (`confirmPublish`→no-op re-audit informatif, `publish()` du synchronizer conservé intact → tests unit/command/namespace verts) ; `WpkgGpoSynchronizer` audit repointé sur le bundle natif (`resolveRouteUrl`/`URL` supprimés, devenus morts).
- **T11 (golden + hashes croisés)** : item `applications` (4 clés, machine) ajouté à `state.v1.json` ; illustration inventaire ajoutée à `report.v1.json` ; `FROZEN_STATE_HASH` re-bumpé `6f0ff33e…`→`283f391d…` côté PHP ET Go (même valeur, vérifiée par le cross-check Go) ; comptes ajustés (`contract_test.go` machine 2→3 ; `hasher_test.go` 9→10 items).
- **T12 (doc)** : `contract-v1.md` §7.4 `applications` + note §6 (champ inventaire additif) ; `state-providers.md` section `ApplicationsStateProvider` ; `agent/README.md` puce handler ; `docs/qa/domains/agent.md` Section 27.5 (7 scénarios + checklist, append-only) ; `docs/qa/README.md` ligne agent enrichie.
- **Décisions appliquées** : D1 (inventaire additif, verdict par type), D2 (GPO publication retirée — page no-op, méthode conservée pour les tests), D4 (maille Broadcast), D5 (lecture wpkg.xml, 2 chemins candidats), D6-D11 (livraison native : bundle Apache statique, agent donne l'URL, profil local, scripts patchés, installeurs SMB).
- **Pour Henri /vm** : `migrate --force` (table Pending) ; `php artisan test --filter Agent` ; `php artisan wpkg:bundle` + chown www-admin sur le sous-dossier + alias Apache statique vers `config('agent.wpkg_bundle_path')` ; délier la GPO `se4_wpkg` résiduelle côté lab ; e2e curl `/state` + convergence poste. **inotify ne propage pas les deletes** : 2 fichiers de test supprimés (`HostsXmlControllerTest`, `ProfilesXmlControllerTest`) + routes retirées — fantômes possibles sur la VM, cleanup SSH à confirmer.

### File List

**Serveur (PHP) — créés**
- `app/Services/Agent/Providers/ApplicationsStateProvider.php`
- `app/Models/AgentApplicationInventory.php`
- `app/Wpkg/Deployment/Services/WpkgBundleGenerator.php`
- `app/Console/Commands/WpkgBundleGenerateCommand.php`
- `database/migrations/2026_06_18_140000_create_agent_application_inventory_table.php`
- `tests/Unit/Services/Agent/ApplicationsStateProviderTest.php`
- `tests/Feature/Api/V1/Agent/ApplicationsInventoryIngestTest.php`

**Serveur (PHP) — modifiés**
- `app/Models/Application.php` (const `TYPE_APPLICATIONS`)
- `app/Wpkg/Deployment/Services/WorkstationPackagesResolver.php` (`computePackages` publique)
- `app/Providers/AgentServiceProvider.php` (enregistrement provider)
- `app/Http/Requests/Api/V1/Agent/ReportRequest.php` (validation `inventory`)
- `app/Services/Agent/Reporting/ReportIngestService.php` (ingest inventaire)
- `app/Gpo/Services/WpkgGpoSynchronizer.php` (audit repointé bundle natif, `resolveRouteUrl`/`URL` retirés)
- `config/agent.php` (`wpkg_bundle_path`/`wpkg_bundle_url`)
- `routes/web.php` (shim WPKG legacy supprimé)
- `resources/views/pages/admin/settings/gpo/wpkg-deployment/index.blade.php` (`confirmPublish` no-op)
- `tests/Feature/Gpo/WpkgDeploymentPageTest.php` (test publish retiré → no-op)

**Serveur (PHP) — supprimés (`git rm`)**
- `tests/Feature/Wpkg/Deployment/Http/HostsXmlControllerTest.php`
- `tests/Feature/Wpkg/Deployment/Http/ProfilesXmlControllerTest.php`

**Agent (Go) — créés**
- `agent/shared/handler_applications.go`
- `agent/shared/handler_applications_test.go`
- `agent/windows/handler_applications_windows.go`

**Agent (Go) — modifiés**
- `agent/shared/contract.go` (`ReportItem.Inventory` + `ReportInventoryItem`)
- `agent/shared/engine.go` (`InventoryReporter` + `withInventory`)
- `agent/shared/files.go` (const `WpkgBundlePath`)
- `agent/windows/main_windows.go` (handler `applications` dans MachineEngine)
- `agent/shared/contract_test.go` (compte machine 2→3)
- `agent/shared/hasher_test.go` (`frozenStateHash` + 9→10 items)

**Bundle WPKG (resources/wpkg) — patchés (D8)**
- `resources/wpkg/wpkg-se4.js` (wpkg_base, profils locaux, noms web, log)
- `resources/wpkg/wpkg.cmd` (download HTTP)

**Golden / contrat**
- `tests/Fixtures/Agent/state.v1.json` (+1 item `applications`)
- `tests/Fixtures/Agent/report.v1.json` (illustration inventaire)
- `tests/Unit/Services/Agent/ContractV1Test.php` (`FROZEN_STATE_HASH`)

**Documentation**
- `docs/agent/contract-v1.md`, `docs/agent/state-providers.md`, `agent/README.md`, `docs/qa/domains/agent.md`, `docs/qa/README.md`

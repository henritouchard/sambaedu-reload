# Story 25.1: Releases serveur — binaires signés, manifest, rings = WorkstationGroups

Status: done

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

En tant que **mainteneur SambaEdu**,
je veux **publier des versions de l'agent ciblées par ring**,
afin **qu'une release atteigne 1 poste de lab avant 1 salle avant le parc**.

## Contexte & intention

Première story de l'**Epic 25** (« Gestion de flotte — distribution canari, bootstrap GPO, porte des postes migrés ») — le prérequis de tout déploiement hors lab (NFR8 : le canal d'update est la partie la plus testée du système). L'Epic 24 a produit l'artefact : le binaire Go **complet et signé Authenticode** (24.5 build + 24.6 compagnon/handlers, version courante `2.1.2`, artefact `agent/build/dist/sambaedu-agent-<version>.exe`).

> **Dépendance amont (sprint-change-proposal-2026-06-12)** : le binaire signé déposé dans `storage/agent/releases/` est produit par les stories 24.5 (build signé Authenticode) + 24.6 (binaire complet). **25.1 le distribue, ne le crée pas.** Statuts au moment de la création de cette story : 24-5 `review`, 24-6 `done`, 24-7 `review` — la chaîne de build est livrée et utilisable.

Cette story livre la **moitié serveur de la distribution (D6, FR24)** : table `agent_releases` (version, hash, fichier), ciblage par **ring = WorkstationGroup existant** (table de liaison `agent_release_rings` — un ring n'est PAS une nouvelle entité, c'est un WG), endpoint manifest `{version, hash, url}` authentifié agent et **résolu selon le ring du poste appelant**, serving binaire authentifié, et l'outillage CLI de création/ciblage (l'UI arrive en 25.5, l'auto-update côté agent en 25.2).

Valeur autonome immédiate : une release se publie en artisan, le manifest se vérifie en **curl depuis un poste enrôlé** (iso-méthode de validation e2e Epic 23) — canari sur un ring d'1 poste de lab observable avant qu'aucun agent ne sache s'auto-updater.

## ⚠️ Pièges connus (lire avant de coder)

1. **Fenêtre 1500 chars de `routes/api.php`** (`tests/Architecture/ScriptsOsNamespaceTest.php:88-97` cherche `auth.v1.workstation` dans les 1500 chars précédant la route 16.12) : les 2 routes neuves s'ajoutent **à la FIN du bloc agent desired-state** (après `agent.v1.assets.wallpaper`, routes/api.php:279-281), **jamais** avant le groupe 16.12. Piège confirmé trois fois (23.3, 23.5, 24.1) ; le commentaire du bloc (l.260-261) le rappelle déjà.
2. **VM : les migrations ne sont PAS auto-jouées** (mémoire projet) + `bootstrap/cache/` non synchronisé par inotify : après livraison → `/vm` `php artisan migrate` + `config:cache` (clé config NEUVE ici) + `route:cache` + chown www-admin. 18/18 tests en 404 vécus en 23.5 pour cette raison.
3. **PHP-FPM tourne en `www-admin` (uid 599)** : `storage/agent/releases/` et tout binaire déposé dedans doivent être lisibles www-admin, sinon `hash_file()` retourne `false` et le serving 404 silencieusement. Création du répertoire + chown = opération VM tracée (le répertoire est NEUF, non versionné — convention storage).
4. **inotify ne propage ni les deletes ni ce qui est hors repo** : les binaires sont déposés directement sur la VM (scp/cp), jamais committés ; ne pas s'attendre à ce qu'un dépôt côté host apparaisse sur la VM.
5. **`hash_file('sha256')` ici n'est PAS un hash d'état** : l'anti-pattern « hash ad hoc » (architecture, enforcement n° 2) vise les hashes d'ÉTAT (JSON canonicalisé → `StateHasher` unique). Le SHA-256 d'un **fichier binaire** est un autre objet : `hash_file()` standard est correct et attendu — ne pas essayer de passer un binaire dans `StateHasher`, ne pas non plus s'auto-censurer au grep `hash(` de review (le commenter en File List).
6. **URLs absolues obligatoires** (mémoire `project_ipxe_relative_urls_trap`) : le champ `url` du manifest est une **URL absolue** (`route(..., absolute: true)`), jamais un chemin relatif. Vérifier `APP_URL` côté VM au smoke test.
7. **Fichiers FIGÉS intouchables** : `docs/agent/contract-v1.md`, `tests/Fixtures/Agent/state.v1.json`, `report.v1.json`, `FROZEN_STATE_HASH` (`6c0e8135…`). Le manifest est un **wire contract NEUF** → fixture NEUVE additive (`release-manifest.v1.json`), zéro édit des artefacts figés.
8. **`X-Agent-New-Token` doit survivre** aux réponses manifest ET download (invariant D5) : le middleware pose le header après `$next($request)` — ça marche par construction, mais c'est un invariant de sécurité du recouvrement : **test obligatoire** (rotation due + GET manifest → 200 avec header), iso 24.1 piège n° 4.
9. **Tests SQLite n'appliquent pas les varchar** (mémoire projet) : `version` et `hash` sont des domaines fermés validés **en code** (regex) avant écriture, jamais par la largeur de colonne.
10. **Le serving binaire ne porte pas le wrapper SE5** : `BinaryFileResponse` iso `AssetController` (24.4), 404 indistinct `{error, message}` pour TOUT échec (filename malformé, release inconnue, fichier absent) — aucun oracle de présence. Seul le manifest (JSON) porte le wrapper SE5.

## Décisions de design prises ici (à challenger en review, pas à re-trancher en dev)

1. **Création de release = commande artisan, pas d'UI** (l'UI rings/releases = 25.5) : `agent:release:create {version} {filename} --hash=<sha256> [--stable]`. Le `--hash` est **obligatoire** : c'est la valeur produite par le pipeline de build que le serveur contre-vérifie — `ReleaseCreationService` calcule `hash_file('sha256')` du fichier dans `storage/agent/releases/` et **refuse la création** (exception + exit ≠ 0 + log `agent.release.rejected`, AUCUNE ligne écrite) si : fichier absent/illisible, hash calculé ≠ hash déclaré, version déjà existante, version/filename malformés. C'est l'AC « impossible de publier un artefact incohérent ».
2. **DB : colonne `filename`, champ `url` calculé à la réponse.** L'AC épic nomme `agent_releases : version, hash, url` — on stocke le `filename` (la donnée stable) et le manifest sert `url` = URL **absolue** construite par `route('agent.v1.release.download', …)` au moment de la réponse. Une URL absolue figée en DB casserait au premier changement de host/scheme ; le contrat wire (ce que voit l'agent) porte bien `{version, hash, url}`.
3. **Ring = ligne de `agent_release_rings`** : `workstation_group_id` (UNIQUE, FK cascade) → `agent_release_id` (FK cascade). Un ring = UN WorkstationGroup existant (salle physique OU parc logique — le pivot 4.11 ne distingue pas pour ce besoin), version cible par ring. **Aucune colonne sur `workstation_groups`** (frontière : le canal agent n'écrit que dans `agent_*`).
4. **Conflit multi-rings : la ligne ring la plus récemment modifiée (`updated_at`) gagne** + warning `agent.release.ring_conflict` (contexte workstation_id + group_ids candidats). Iso-philosophie FR4 (« conflit = la règle la plus récente gagne + warning »). Cette règle couvre les deux cas réels : canari (le ciblage lab posé APRÈS le ciblage parc → le poste de lab reçoit la canari) et rollback (le re-ciblage stable posé APRÈS → il gagne). Pas de précédence D2 ici : la résolution est machine-only et la spécificité physique/logique n'a pas de sens pour des rings.
5. **Stable = boolean `is_stable` sur `agent_releases`, au plus une ligne à true** — invariant **transactionnel dans le service** (unset l'ancienne, set la nouvelle), pas de contrainte partielle PG (parité SQLite des tests). `--stable` à la création + commande `agent:release:promote {version}` (déplace le pointeur stable, log `agent.release.promoted`) — hors AC épic mais indispensable à l'exploitation lab avant l'UI 25.5 (rollback du pointeur par défaut).
6. **Ciblage d'un ring en CLI** : `agent:release:target {version} {workstation-group}` (lookup WG par `name`, `updateOrCreate` de la ligne ring → rafraîchit `updated_at`, donc cohérent avec la règle de récence n° 4). Outillage lab provisoire — l'UI 25.5 écrira les mêmes lignes via le même service.
7. **Aucune release applicable** (poste sans ring ET aucune stable) → **404** `{error: 'no_release', message}` (pattern `AssetController::notFound`). L'agent 25.2 traitera 404 = « rien à faire » — jamais un 200 vide ambigu, jamais une canari par accident.
8. **Pas de vérification Authenticode côté serveur** : osslsigncode n'est pas une dépendance runtime du serveur ; l'intégrité à la création = SHA-256 (décision n° 1), la vérification **signature avant exécution** = l'agent (25.2, AC épic) + `build.sh` refuse déjà de produire du non-signé sans `ALLOW_UNSIGNED=1`. Documenter cette répartition dans la doc de story.
9. **Manifest = wrapper SE5** `{success: true, version, hash, url}` (clés métier à la racine, format API standard SE5) — fixture golden NEUVE `tests/Fixtures/Agent/release-manifest.v1.json`, consommée par les feature tests serveur et, en 25.2, par les tests croisés de l'agent Go (NFR13).
10. **Résolution par le poste authentifié** : `$request->attributes->get('agent.workstation')` (posé par `AuthenticateAgentToken`) → ids de groupes via la relation pivot `groups()` (pivot global 4.11). Pas de `TargetContext` (il est fait pour la compilation d'état (poste, user) ; ici machine-only et règle de récence dédiée) — mais comme lui, **lecture seule Postgres, zéro AD** (critère Keycloak, NFR7).
11. **Chemin des releases dans `config/agent.php`** : clé `releases_path` (défaut `storage_path('agent/releases')`, surchargée en test vers un répertoire temporaire). Nouvelle clé config ⇒ `config:cache` + chown sur VM (piège n° 2).

## Acceptance Criteria

### AC1 — Création de release : l'artefact incohérent est impubliable (FR24)

**Given** un binaire signé déposé dans `storage/agent/releases/` (non versionné, convention storage)
**When** une release est créée (`agent_releases` : version, hash, url — décision n° 2 : filename en DB, url calculée) via `agent:release:create {version} {filename} --hash=<sha256>`
**Then** la ligne `agent_releases` est créée avec le hash **vérifié** contre le fichier réel
**And** un binaire dont le hash ne correspond pas au manifest → **la release est refusée à la création** — impossible de publier un artefact incohérent (fichier absent, hash divergent, version dupliquée, formats invalides → refus, aucune écriture, exit ≠ 0)
**And** `--stable` marque la release stable (au plus une, invariant transactionnel — décision n° 5) ; log `agent.release.created` (contexte version + hash) / `agent.release.rejected` (warning + raison).

### AC2 — GET manifest : résolu selon le ring du poste (FR24, D6)

**Given** un poste authentifié (token 23.2/23.3) membre d'un WorkstationGroup ciblé par un ring (`agent_release_rings`)
**When** `GET /api/v1/agent/release` (ReleaseController, **authentifié agent** — chaîne middleware iso state/report)
**Then** la réponse 200 renvoie `{version, hash, url}` (wrapper SE5, décision n° 9) **résolu selon le ring du poste** — un ring = un WorkstationGroup, version cible par ring
**And** `url` est une URL **absolue** vers le téléchargement du binaire (piège n° 6)
**And** si le poste matche plusieurs rings → la ligne ring la plus récemment modifiée gagne + warning `agent.release.ring_conflict` (décision n° 4)
**And** la réponse est conforme au golden `tests/Fixtures/Agent/release-manifest.v1.json` (forme, pas valeurs).

### AC3 — Poste sans ring : stable par défaut, jamais une canari par accident (FR24)

**Given** un poste n'appartenant à aucun ring
**Then** il reçoit la **version stable par défaut** (`is_stable = true`) — jamais une canari par accident
**And** aucun ring ET aucune stable → 404 `no_release` (décision n° 7), pas de 500, pas de 200 vide
**And** un poste dont le ring pointe une release supprimée ne casse pas (FK cascade : la ligne ring disparaît avec la release → retombe sur la stable).

### AC4 — Téléchargement du binaire : authentifié, indistinct en échec

**Given** l'`url` servie par le manifest
**When** `GET /api/v1/agent/releases/{filename}` avec le bearer du poste
**Then** 200 `BinaryFileResponse` du fichier de `storage/agent/releases/` — le SHA-256 du corps reçu = le `hash` du manifest (testé)
**And** filename malformé (pattern strict `sambaedu-agent-<version>.exe`, anti-traversal), release inconnue en DB ou fichier absent → **404 indistinct** `{error, message}` (piège n° 10, iso `AssetController`)
**And** seul un filename présent dans `agent_releases` est servi (lookup DB d'abord, disque ensuite).

### AC5 — Frontières & observabilité (FR27)

**Then** logs channel `agent`, actions namespacées `agent.release.*` : `created`, `rejected`, `promoted`, `targeted`, `ring_conflict`, `manifest_served` (debug — un par check-in, volume NFR4), `download_served` / `download_not_found` — toujours `workstation_id` quand applicable, jamais de token ni de payload binaire dans les logs
**And** **aucune écriture hors `agent_*`** (`agent_releases`, `agent_release_rings` — zéro write sur `workstations`, `workstation_groups` ou toute table métier ; le ciblage LIT les WorkstationGroups, D6 × D1)
**And** aucun appel AD/LdapRecord/Kerberos/APCu (critère Keycloak NFR7, grep en review) ; aucune logique métier dans le controller (couche Services).

### AC6 — Sécurité du canal : middleware, rotation, throttle

**Given** les routes réelles `agent.v1.release` (manifest) et `agent.v1.release.download` (binaire), **à la FIN du bloc agent** (piège n° 1)
**Then** chaîne `['auth.v1.secure-headers', 'throttle:60,1', 'agent.token']` ; sans bearer → 401 `AGENT_TOKEN_MISSING`, token invalide → 401 `AGENT_TOKEN_INVALID`, quarantaine → 403 `AGENT_QUARANTINED` (formats du middleware 23.2, intouchés)
**And** rotation due → la réponse 200 du manifest porte `X-Agent-New-Token` (invariant D5, testé — piège n° 8).

### AC7 — Tests

**Then** `tests/Feature/Api/V1/Agent/ReleaseEndpointTest.php` (conventions `StateEndpointTest`/`ReportEndpointTest` : `Workstation::factory()`, `WorkstationGroup::factory()`, `TokenRotationService::issueFor()`, helper privé, mock channel `agent` étendu error/critical) couvre : manifest résolu par ring ; sans ring → stable ; ni ring ni stable → 404 ; multi-rings → récence + warning ; ring orphelin (release supprimée) → stable ; conformité au golden manifest ; download 200 + SHA-256 du corps = hash ; download 404 × (malformé, traversal `../`, inconnu DB, fichier absent) ; 401 missing/invalid ; 403 quarantaine ; X-Agent-New-Token sur 200
**And** `tests/Unit/Services/Agent/ReleaseCreationServiceTest.php` (refus hash mismatch / fichier absent / version dupliquée / formats, succès + stable swap transactionnel) et `ReleaseManifestServiceTest.php` (résolution ring/stable/récence/aucune) — `config(['agent.releases_path' => <tmp>])` pour les fichiers de test
**And** un test des commandes artisan (create OK/KO exit codes, target updateOrCreate + récence, promote swap)
**And** `php artisan test --filter Agent` intégralement vert sur `/vm` — baseline post-24.7 constatée au premier run (≥ 206 passed), zéro régression, golden files figés + `FROZEN_STATE_HASH` intouchés. **UNIQUEMENT ce filtre** — jamais la suite complète (décision Henri).

### AC8 — Transversal : doc, VM

**Then** `docs/agent/release-distribution.md` (NEUF) documente : tables D6, commandes artisan, endpoints + codes (200/401/403/404/429), règle de résolution (ring → récence → stable → 404), répartition intégrité création (SHA-256 serveur) vs exécution (signature agent 25.2 — décision n° 8), convention de dépôt des binaires (`storage/agent/releases/`, chown www-admin, hors git/inotify)
**And** `docs/qa/domains/agent.md` : section NEUVE append-only (scénarios numérotés : créer release depuis `agent/build/dist/`, curl manifest poste enrôlé, canari ring 1 poste, rollback re-ciblage, download + sha256sum) ; `contract-v1.md` FIGÉ — zéro édit
**And** opérations VM exécutées et tracées : `mkdir -p storage/agent/releases` + chown www-admin, `php artisan migrate` + `config:cache` + `route:cache` + chown www-admin (`bootstrap/cache/`), smoke curl manifest sans token → 401 JSON (route vivante derrière le cache), `APP_URL` vérifiée (url absolue correcte).

## Tasks / Subtasks

- [x] **T1 — Migration `create_agent_release_tables`** (AC1, AC2, AC3)
  - [x] `database/migrations/2026_06_12_<hhmmss>_create_agent_release_tables.php` (guards `Schema::hasTable`, docblock + down() iso `2026_06_11_140000_create_agent_report_tables.php`) :
    - `agent_releases` : id ; `version` string(32) **unique** ; `hash` string(64) ; `filename` string(255) unique ; `is_stable` boolean default false + index ; timestamps.
    - `agent_release_rings` : id ; `workstation_group_id` FK `constrained()->cascadeOnDelete()` **unique** ; `agent_release_id` FK `constrained('agent_releases')->cascadeOnDelete()` ; timestamps (l'`updated_at` EST la donnée de récence — décision n° 4).
- [x] **T2 — Modèles** (AC1-AC3)
  - [x] `app/Models/AgentRelease.php` — `$fillable`, cast `is_stable => bool`, relation `rings()` ; `app/Models/AgentReleaseRing.php` — relations `release()`, `workstationGroup()`. Docblocks iso `AgentResourceState`.
- [x] **T3 — Config** (décision n° 11)
  - [x] `config/agent.php` : clé `releases_path` (commentaire D6 daté story, défaut `storage_path('agent/releases')`) — addition pure, clés existantes intouchées.
- [x] **T4 — Services `app/Services/Agent/Releases/`** (AC1, AC2, AC3, AC5)
  - [x] `ReleaseCreationService.php` — `create(version, filename, declaredHash, bool $stable): AgentRelease` : validations regex (`version` `/^[0-9A-Za-z.+~-]{1,32}$/`, `filename` `/^sambaedu-agent-[0-9A-Za-z.+~-]+\.exe$/`), `hash_file('sha256', <releases_path>/<filename>)` vs déclaré, `DB::transaction` (+ swap stable), exceptions métier dédiées, logs ; `promote(version)` ; `target(version, WorkstationGroup)` → `updateOrCreate` ring.
  - [x] `ReleaseManifestService.php` (nom figé architecture) — `manifestFor(Workstation $w): ?array` : ids de groupes via `$w->groups()->pluck('workstation_groups.id')`, rings candidats triés `updated_at` desc (récence — décision n° 4, warning si > 1), fallback stable, null si rien ; retourne `{version, hash, url}` avec url absolue `route('agent.v1.release.download', ['filename' => …])`.
  - [x] Bindings singleton dans `AgentServiceProvider::register()` (iso `ReportIngestService`).
- [x] **T5 — Commandes artisan** (AC1, décisions n° 1/5/6)
  - [x] `app/Console/Commands/AgentReleaseCreateCommand.php` (`agent:release:create {version} {filename} {--hash=} {--stable}`, `--hash` requis), `AgentReleaseTargetCommand.php` (`agent:release:target {version} {group}` — lookup WG par name, erreur claire si introuvable), `AgentReleasePromoteCommand.php` (`agent:release:promote {version}`). Controllers minces autour des services, exit codes corrects, output iso `PruneAgentReportsCommand`. Pas d'entrée Kernel (commandes à la demande).
- [x] **T6 — Controller + routes** (AC2, AC3, AC4, AC6)
  - [x] `app/Http/Controllers/Api/V1/Agent/ReleaseController.php` (nom figé architecture) — `manifest(Request)` : workstation depuis `agent.workstation`, service, 200 wrapper SE5 ou 404 `no_release` ; `download(Request, string $filename)` : pattern strict AVANT tout accès, lookup `AgentRelease` par filename, `is_file` sous `releases_path` (realpath confiné), `response()->file()`, 404 indistinct via helper privé (iso `AssetController::notFound`).
  - [x] `routes/api.php` : import aliasé `AgentReleaseController` ; 2 routes **après** `agent.v1.assets.wallpaper` (fin du bloc — piège n° 1), middleware `['auth.v1.secure-headers', 'throttle:60,1', 'agent.token']`, noms `agent.v1.release` / `agent.v1.release.download` (vérifiés libres) ; commentaire du bloc mis à jour (« 25.1 release manifest + download »).
- [x] **T7 — Golden manifest** (AC2, NFR13)
  - [x] `tests/Fixtures/Agent/release-manifest.v1.json` (NEUF — additive, figés intouchés) : forme normative `{success, version, hash, url}` + note d'évolution (champ ajouté = mineur) dans la doc T8. Consommé par le feature test (assertions de forme).
- [x] **T8 — Documentation** (AC8)
  - [x] `docs/agent/release-distribution.md` (NEUF, contenu AC8) ; renvoi 1 ligne depuis `agent/README.md` (§ déploiement : « publication serveur : voir docs/agent/release-distribution.md ») — `contract-v1.md` FIGÉ, zéro édit.
  - [x] `docs/qa/domains/agent.md` : section « Distribution des releases (25.1) » append-only, scénarios numérotés stables.
- [x] **T9 — Tests** (AC7)
  - [x] Feature `ReleaseEndpointTest.php` + Unit `ReleaseCreationServiceTest.php` / `ReleaseManifestServiceTest.php` + test commandes — matrice AC7 complète, fichiers binaires factices en tmp via `config(['agent.releases_path' => …])`.
- [x] **T10 — Vérifications finales + VM** (AC5, AC7, AC8)
  - [x] `php -l` sur tous les fichiers ; grep critère Keycloak (`ldap|kerberos|apcu|samba-tool`) → vide ; grep writes hors `agent_*` → vide ; `hash_file` justifié en File List (piège n° 5).
  - [x] `/vm` (`ssh -i ~/.ssh/id_se4fs_vm root@192.168.122.50`, `/var/www/sambaedu-reload`) : `mkdir -p storage/agent/releases` + chown www-admin ; `php artisan migrate` → `config:cache` → `route:cache` → chown www-admin ; `php artisan test --filter Agent` (baseline post-24.7, zéro régression — **jamais la suite complète**) ; smoke : déposer `agent/build/dist/sambaedu-agent-<v>.exe` (ou un binaire factice), `agent:release:create` (un KO hash + un OK `--stable`), curl manifest avec le token d'un poste enrôlé (ws 49 dispo) → 200 `{version, hash, url}` absolu, curl sans token → 401.

## Dev Notes

### Périmètre — livré / hors-scope

| Livré (25.1) | Hors-scope (story) |
|---|---|
| `agent_releases` + `agent_release_rings` + modèles | Auto-update côté agent Go (download, vérif hash+signature, swap binaire) → **25.2** |
| `ReleaseCreationService` (refus incohérence) + `ReleaseManifestService` (résolution ring) | Porte 2 enrôlement migrés + approbation → **25.3** |
| `GET /api/v1/agent/release` (manifest) + `GET /api/v1/agent/releases/{filename}` (binaire) | GPO-dispatcher figée + dépôt iPXE du binaire → **25.4** |
| Commandes `agent:release:{create,target,promote}` (outillage pré-UI) | UI `parc-settings/agent/` (rings, releases, progression — `agent.release.promoted` ring-à-ring) → **25.5** |
| Golden `release-manifest.v1.json` + doc + QA | Persistance de `agent_version` rapportée par poste (le rapport la porte déjà, le serveur ne la stocke pas encore — progression par ring = 25.2/25.5) |
| | Production du binaire signé (build 24.5/24.6 — dépendance amont) ; vérification Authenticode serveur (décision n° 8) |

### Patterns existants à imiter (NE PAS réinventer)

- **Serving binaire authentifié canal agent** : `app/Http/Controllers/Api/V1/Agent/AssetController.php` (24.4) — pattern strict AVANT disque/DB (l.46, 53), lookup DB puis `is_file`, `response()->file()`, 404 indistinct via helper `notFound()` loggé, `Str::limit` sur contexte user-controlled. **Le modèle exact de `download()`.**
- **Workstation authentifié** : `$request->attributes->get('agent.workstation')` — posé par `AuthenticateAgentToken` (`app/Http/Middleware/AuthenticateAgentToken.php:162`) ; constantes 401/403 (l.56-58), `HEADER_NEW_TOKEN` (l.61).
- **Controller mince + wrapper SE5** : `ReportController.php` (`{success, …}`, l.74-75) ; controller strict_types, injection constructeur, logs `action_type` namespacés.
- **Service + binding** : `app/Providers/AgentServiceProvider.php:43-54` — singletons ; ajouter les 2 services Releases au même endroit.
- **Migrations agent** : `2026_06_11_140000_create_agent_report_tables.php` — guards `Schema::hasTable`, docblock détaillé, down() ordre inverse FK.
- **Commandes artisan** : `app/Console/Commands/PruneAgentReportsCommand.php` (signature, output, lecture config).
- **Feature tests canal** : `tests/Feature/Api/V1/Agent/StateEndpointTest.php`, `ReportEndpointTest.php`, `AssetEndpointTest.php` — helper privé, `Workstation::factory()`, `WorkstationGroup::factory()` (existe), `TokenRotationService::issueFor()`, rotation due par recul `agent_token_rotated_at`, quarantaine via `quarantine()`, mock channel `agent` debug→critical (P2 23.5).
- **Pivot groupes du poste** : `Workstation::groups()` (`app/Models/Workstation.php:203` — pivot global 4.11 `workstation_group_workstation`, salles + parcs confondus) ; tri déterministe des ids iso `TargetContext::ids()` si exposés.

### Architecture — conventions figées applicables (NON négociables)

[Source: architecture-agent-desired-state.md#D6 ; #Naming Patterns ; #Structure (delta) ; #Communication Patterns ; #Enforcement Guidelines]

- **D6** : binaires signés servis par SE5 en HTTP (`storage/agent/releases/`, non versionnés — convention storage), manifest JSON `{version, hash, url}` en DB (`agent_releases`), **version cible par ring, un ring = un WorkstationGroup** (1 poste lab → 1 salle → parc). D6 × D1 : le ciblage WG est LE concept pivot — réutilisé, jamais ré-implémenté.
- Nommage : modèle `AgentRelease`, service `App\Services\Agent\Releases\ReleaseManifestService`, controller `App\Http\Controllers\Api\V1\Agent\ReleaseController`, tables `agent_*`, routes `agent.v1.*`, config `config/agent.php`, channel `agent`, fixtures `tests/Fixtures/Agent/`.
- Frontières : le canal agent n'écrit QUE dans `agent_*` ; lecture seule sur `workstation_groups`/pivot ; **aucune écriture AD, aucun appel AD** (critère Keycloak NFR7, vérifiable en review) ; endpoint agent jamais hors `/api/v1/agent/*`.
- Codes canal : 200 / 304 (state only) / 401 / 403 / 404 (asset/release indistinct) / 409 / 422 (+ 429 throttle).
- Logs : channel `agent`, `agent.release.*` (l'architecture cite `agent.release.promoted`), contexte `workstation_id` quand applicable.
- NFR4 (volumétrie) : le manifest est appelé à chaque check-in (~0,7 req/s parc plein) — résolution en O(2 requêtes) (rings des groupes du poste + fallback stable), log nominal en debug.
- NFR8 : ce canal devient **la partie la plus testée** — la matrice AC7 n'est pas négociable à la baisse.

### Dépendance amont — l'artefact distribué (24.5/24.6)

[Source: sprint-change-proposal-2026-06-12.md §4.C ; agent/build/build.sh ; agent/shared/version.go]

- Artefact : `agent/build/dist/sambaedu-agent-<version>.exe` — binaire Go statique unique, cross-compilé Windows, signé Authenticode (osslsigncode, CA interne `storage/keys/pki/`), version injectée `-ldflags` depuis `agent/shared/version.go` (courante `2.1.2`).
- Le nom d'artefact justifie le pattern strict du download (`sambaedu-agent-…​.exe`) ; le build **vérifie sa propre signature** (`osslsigncode verify`) — le serveur n'a pas à la re-vérifier (décision n° 8).
- ⚠️ Le PFX CA réelle est une action Henri en cours (note 24.5) : le smoke VM peut utiliser un binaire signé cert TEST ou un fichier factice — la story ne dépend pas du certificat de prod.

### Project Structure Notes

- **Racine = projet Laravel** ; code édité sur l'hôte, exécuté sur la VM `/vm` ; sync inotify auto (jamais de sync manuel ; si non-synchro → notifier Henri et attendre) ; inotify ne propage NI les deletes NI les fichiers hors repo (les binaires VM se déposent à la main).
- Story avec **migration + routes + clé config nouvelle** → VM : `php artisan migrate` + `config:cache` + `route:cache` + chown www-admin (`bootstrap/cache/` + `storage/agent/releases/` — PHP-FPM = `www-admin`, uid 599).
- `storage/agent/releases/` : NEUF, non versionné (pas de `.gitkeep` committé avec des binaires dedans ; le répertoire est créé par les ops VM et à la volée par le service si absent).
- Jamais de VM/SSH depuis un worktree git (mémoire projet).

### Testing standards

- PHPUnit, exécution de référence sur `/vm` ; SQLite `:memory:` en feature (RefreshDatabase) — piège n° 9 (varchar) : domaines fermés validés en code.
- **Commande prescrite : `php artisan test --filter Agent` UNIQUEMENT** (décision Henri). Baseline : 206 passed (839 assertions) post-24.6 ; 24-7 (review) en a ajouté — **constater la baseline au premier run avant tout commit**.
- Fichiers binaires de test : tmp dir + `config(['agent.releases_path' => …])` (décision n° 11) — jamais d'écriture dans le vrai `storage/` en test.
- Golden files figés (`state.v1.json`, `report.v1.json`, `FROZEN_STATE_HASH 6c0e8135…`) : intouchés ; `release-manifest.v1.json` = NOUVEAU golden, additive.

### Intelligence stories précédentes

- **23.2 (done)** : middleware = autorité 401/403/check-in/rotation ; formats d'erreur figés ; jamais de secret en log, `Str::limit` sur contexte user-controlled.
- **23.5 (done)** : chaîne middleware réutilisée telle quelle ; piège cache routes VM vécu (18/18 en 404) ; mock logs étendu error/critical ; throttle AVANT `agent.token` (le lookup DB est protégé du flood).
- **24.1 (done)** : trio FormRequest/service/controller mince ; routes à la FIN du bloc (piège confirmé) ; transaction DB autour des écritures groupées ; wrapper SE5 pour tout ce qui n'est pas le contrat brut de /state.
- **24.4→24.6 (done)** : `AssetController` = le précédent exact du serving binaire authentifié (pattern strict, 404 indistinct, content-addressed) ; l'agent Go vérifie les SHA-256 **avant écriture** côté poste — il fera pareil pour le binaire en 25.2 : le hash du manifest doit donc être exact à l'octet près (d'où le refus à la création).
- **24.5 (review)** : `agent/build/dist/` + version.go = la source de vérité artefact/version ; reste PFX CA réelle (action humaine) — ne pas bloquer dessus.
- **24.7 (review)** : `SyncRequestService` montre le pattern « 2 écrivains stricts » sur colonne workstations — ici AUCUNE colonne workstations n'est touchée (tout vit dans les 2 tables neuves).

### References

- [Source: _bmad-output/planning-artifacts/epics-agent-desired-state.md#Story 25.1 (l.513-534)] — ACs source, FR24, note de dépendance amont.
- [Source: _bmad-output/planning-artifacts/sprint-change-proposal-2026-06-12.md §4.C, §5] — 25.1 distribue le binaire produit par 24.5/24.6, dépendance close.
- [Source: _bmad-output/planning-artifacts/architecture-agent-desired-state.md#D6 (l.335-341) ; #Naming Patterns (l.380-409) ; #Structure delta (l.553-587) ; #Communication Patterns (l.450-457)] — ReleaseController, AgentRelease, ReleaseManifestService, storage/agent/releases/, logs.
- [Source: app/Http/Controllers/Api/V1/Agent/AssetController.php] — modèle exact du download (pattern, 404 indistinct, logs).
- [Source: app/Http/Middleware/AuthenticateAgentToken.php:56-61, 162-167] — codes, attribut workstation, header rotation D5.
- [Source: app/Models/Workstation.php:203-210 ; app/Services/Agent/TargetContext.php] — pivot groups() 4.11, tri déterministe des ids.
- [Source: app/Providers/AgentServiceProvider.php:43-54] — bindings singletons à étendre.
- [Source: config/agent.php] — clés existantes + style des commentaires ; `releases_path` à ajouter.
- [Source: routes/api.php:260-281 ; tests/Architecture/ScriptsOsNamespaceTest.php:88-97] — fin du bloc agent, fenêtre 1500 chars.
- [Source: agent/build/build.sh ; agent/shared/version.go ; agent/README.md] — artefact `sambaedu-agent-<version>.exe`, signature, version 2.1.2.
- [Source: docs/qa/domains/agent.md ; docs/qa/README.md#Convention] — domaine QA existant, sections append-only.
- [Source: tests/Feature/Api/V1/Agent/AssetEndpointTest.php ; StateEndpointTest.php] — conventions de test du canal.

## Dev Agent Record

### Agent Model Used

claude-fable-5 (reco story suivie — mémoire `feedback_epic23_model_fable5`).

### Debug Log References

Commandes VM exécutées (`ssh -i ~/.ssh/id_se4fs_vm root@192.168.122.50`, `/var/www/sambaedu-reload`), dans l'ordre :

1. `mkdir -p storage/agent/releases && chown -R www-admin: storage/agent` (répertoire NEUF, convention storage, piège n° 3).
2. `php artisan migrate --force` → `2026_06_12_120000_create_agent_release_tables` DONE (était Pending — piège n° 2 constaté via `migrate:status` avant).
3. `php artisan config:cache` + `php artisan route:cache` + `chown -R www-admin: bootstrap/cache` (clé config + routes neuves) ; `route:list | grep agent/release` → 2 routes vivantes.
4. `php artisan test --filter Agent` → **274 passed (1048 assertions), 0 failed, 13.12s** — baseline héritée (274 − 42 tests neufs = 232 préexistants, tous verts ; ≥ 206 post-24.6 + ajouts 24.7), **zéro régression**. Filtre Agent UNIQUEMENT (décision Henri).
5. Smoke AC8 (tracé intégral en session) : `install -o www-admin agent/build/dist/sambaedu-agent-2.1.2.exe storage/agent/releases/` → `agent:release:create 2.1.2 … --hash=000…0` → **refus `hash_mismatch`, exit 1** → re-create avec le sha256sum réel `--stable` → **publiée, exit 0** → poste smoke éphémère (INSERT SQL brut `SMOKE-25-1` + hash de token contrôlé — **zéro observer AD, ws 49 intouché**) → `curl` sans token → **401 `AGENT_TOKEN_MISSING`** (route vivante derrière le cache) → `curl` avec token → **200 `{success, version: 2.1.2, hash, url}`**, `url` ABSOLUE `http://se4fs/api/v1/agent/releases/sambaedu-agent-2.1.2.exe` (= `APP_URL`, piège n° 6 vérifié) → download → **sha256sum identique au hash du manifest** → DELETE du poste smoke (1 ligne).

### Completion Notes List

- **AC1** : `ReleaseCreationService::create()` contre-vérifie `hash_file('sha256')` sur le fichier réel AVANT toute écriture ; refus (exception `ReleaseOperationException` + raison machine + log `agent.release.rejected` + exit ≠ 0) pour : fichier absent/illisible, hash divergent, version dupliquée, **filename dupliqué** (ajout défensif — colonne unique en base, refus propre plutôt que QueryException), version/filename/hash malformés. Swap stable transactionnel (au plus une ligne à true).
- **AC2/AC3** : `ReleaseManifestService::manifestFor()` — rings des groupes du poste (pivot 4.11) triés `updated_at` desc + **tie-break `id` desc** (déterminisme : timestamps à la seconde) ; > 1 ring → warning `ring_conflict` (workstation_id + group_ids) ; fallback stable ; null → 404 `no_release` côté controller. Ring orphelin : FK cascade fait foi (testé), + skip défensif en code si release absente.
- **AC4** : `ReleaseController::download()` iso `AssetController` — pattern strict AVANT tout accès, lookup `agent_releases` d'abord (un binaire orphelin sur disque n'est JAMAIS servi), realpath confiné sous `releases_path`, 404 indistinct via helper privé.
- **AC6** : routes à la FIN du bloc agent (piège n° 1 — le bloc 16.12 `script-execution-logs` est AVANT le bloc agent, fenêtre intacte, `ScriptsOsNamespaceTest` vert), chaîne `['auth.v1.secure-headers', 'throttle:60,1', 'agent.token']`, X-Agent-New-Token testé sur le 200 manifest.
- **Piège n° 5 assumé** : `hash_file('sha256')` dans `ReleaseCreationService` = hash de FICHIER binaire (commenté sur place + ici) — PAS un hash d'état, `StateHasher` non concerné.
- **Déviations (3, mineures, documentées)** : (1) log `agent.release.no_release` (debug) ajouté hors liste AC5 — namespacé `agent.release.*`, observabilité du 404 manifest ; (2) `target()` fait `updateOrCreate` **+ `touch()`** : updateOrCreate seul ne rafraîchit PAS `updated_at` quand la ligne est inchangée (re-ciblage de la MÊME version = cas rollback) — le touch garantit la règle de récence de la décision n° 4 (testé unit + commande) ; (3) raison `duplicate_filename` ajoutée à la matrice de refus (cf. AC1 ci-dessus).
- **Hors AC, à savoir** : la release 2.1.2 (binaire cert TEST de 24.6) reste **publiée stable sur la VM** après le smoke — sans effet poste (aucun agent ne consomme le manifest avant 25.2), utile aux tests 25.2 ; purge possible via tinker si indésirable.
- Tests : **42 neufs** (15 Feature endpoint + 12 Unit creation + 8 Unit manifest + 7 Feature commandes), matrice AC7 complète ; golden files figés (`state.v1.json`, `report.v1.json`, `FROZEN_STATE_HASH`) et `contract-v1.md` **intouchés** (vérifié git status).

### File List

**Créés :**

- `database/migrations/2026_06_12_120000_create_agent_release_tables.php` — tables `agent_releases` + `agent_release_rings` (guards hasTable, FK cascade, down() ordre inverse).
- `app/Models/AgentRelease.php`, `app/Models/AgentReleaseRing.php` — modèles (docblocks iso `AgentResourceState`).
- `app/Services/Agent/Releases/ReleaseCreationService.php` — create (refus vérifié — **`hash_file('sha256')` justifié : hash de fichier binaire, piège n° 5**), promote, target.
- `app/Services/Agent/Releases/ReleaseManifestService.php` — résolution ring → récence → stable → null.
- `app/Services/Agent/Releases/ReleaseOperationException.php` — exception métier (raison machine).
- `app/Console/Commands/AgentReleaseCreateCommand.php`, `AgentReleaseTargetCommand.php`, `AgentReleasePromoteCommand.php` — commandes minces, exit codes, pas d'entrée Kernel.
- `app/Http/Controllers/Api/V1/Agent/ReleaseController.php` — manifest (wrapper SE5) + download (404 indistinct).
- `tests/Fixtures/Agent/release-manifest.v1.json` — golden NEUF (additif, figés intouchés).
- `tests/Feature/Api/V1/Agent/ReleaseEndpointTest.php` (15 tests), `tests/Unit/Services/Agent/ReleaseCreationServiceTest.php` (12), `tests/Unit/Services/Agent/ReleaseManifestServiceTest.php` (8), `tests/Feature/Console/AgentReleaseCommandsTest.php` (7).
- `docs/agent/release-distribution.md` — doc AC8 (tables, commandes, endpoints/codes, résolution, répartition intégrité, convention dépôt, observabilité).

**Modifiés :**

- `config/agent.php` — clé `releases_path` (addition pure, clés existantes intouchées).
- `app/Providers/AgentServiceProvider.php` — 2 imports + 2 bindings singleton.
- `routes/api.php` — import aliasé `AgentReleaseController`, 2 routes à la FIN du bloc agent, commentaire du bloc complété (25.1).
- `agent/README.md` — renvoi 1 ligne (§ Installation lab) vers `release-distribution.md`.
- `docs/qa/domains/agent.md` — Section 8 (scénarios 8.1-8.5) + 6 entrées checklist, append-only.
- `docs/qa/README.md` — ligne d'index du domaine agent étendue (Stories 24.1 → 25.1).
- `_bmad-output/implementation-artifacts/sprint-status.yaml` — 25-1 → review + last_updated.

**Supprimés :** aucun (zéro fantôme inotify).

## Change Log

- 2026-06-12 — Story 25.1 implémentée intégralement (T1-T10) par claude-fable-5 : distribution serveur des releases agent (D6/FR24) — création vérifiée hash, rings = WorkstationGroups, manifest résolu par récence, serving binaire authentifié, CLI create/target/promote, 42 tests neufs, doc + QA Section 8, VM migrée/cachée/smoke-testée (274 passed). Status → review.

## Recommandation Modèle Dev

**fable** — story de canal de sécurité multi-fichiers : serving binaire authentifié (anti-traversal, 404 indistinct sans oracle), intégrité d'artefact (refus transactionnel à la création — c'est la garantie « jamais un binaire incohérent sur le parc », NFR8 : le canal d'update est LA partie la plus testée), règle de résolution ring avec précédence de récence à concevoir sans D2, et ~18 fichiers (migration, 2 modèles, 2 services, 3 commandes, controller, routes, golden, docs, 5 fichiers de test) sous contraintes architecture nombreuses (frontière `agent_*`, critère Keycloak, fenêtre routes). Cohérent avec la mémoire projet `feedback_epic23_model_fable5` (stories agent desired-state → fable) — `opus` serait défendable pour la seule plomberie CRUD, mais le couple résolution-ring + intégrité binaire est exactement le genre de logique critique où le réflexe « contrat = petit modèle » est à éviter.

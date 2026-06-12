# Story 24.5 : Agent Go — core de convergence, service SYSTEM, build signé

Status: review

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

En tant que mainteneur SambaEdu,
je veux le cœur de l'agent réécrit en Go (boucle, hash, cache, résilience, build signé) tournant en service SYSTEM,
afin de disposer de l'artefact de production réel, signable et déployable, en lieu et place du prototype PowerShell.

## Contexte & intention

**Story issue de la course-correction 2026-06-12** (`_bmad-output/planning-artifacts/sprint-change-proposal-2026-06-12.md`, validée et appliquée par Henri le jour même). La décision techno **Go** (2026-06-11, mémoire `project_agent_runtime_go`) est tombée le même jour que le découpage de l'Epic 24 et n'avait jamais été réinjectée : tout l'Epic 24 prototypait en PowerShell jetable, et l'Epic 25 (25.1) présupposait un binaire signé que personne ne produisait. Cette story comble ce trou de séquencement.

**Ce que cette story est :**
- La réécriture en **Go** du core validé par le spike PS 24.2 : boucle GET state → cache → POST report, service SYSTEM, rotation token D5, backoff, quarantaine, ACL SYSTEM, log local
- Le **`StateHasher` Go**, reproduction à l'identique de l'algorithme PHP 23.1, validé contre les golden files (tests croisés serveur/agent) — le noyau de conformité du binaire
- Le **build de production réel** : binaire Go statique unique, cross-compilé Windows, **signé Authenticode** (CA interne) — l'artefact que 25.1 distribuera
- La résolution **formelle** du gate techno (ex-PoC P2) dans `agent/README.md` contre le cahier des 7 contraintes
- Le **retrait des `.ps1` de 24.2** (squelette + install/uninstall/build) une fois leur équivalent Go vert — pas d'état transitoire

**Ce que cette story n'est PAS :**
- Le compagnon de session Go ni les handlers wallpaper/overlay (→ 24.6 ; les `.ps1` de 24.3/24.4 et `ContractV1.ps1` restent au repo jusqu'à 24.6)
- L'UI de conformité (→ 24.7, gate palier 1)
- La distribution canari / auto-update / manifest (→ 25.x — cette story PRODUIT le binaire, elle ne le distribue pas)
- Une story serveur : **AUCUN code PHP n'est modifié** (StateController, ReportController, middleware, ReportIngestService, golden files = figés). Si un test croisé exigeait un changement serveur, c'est un signal d'alarme à justifier explicitement — à éviter.

**Le spike PS 24.2 (superseded) a rempli sa fonction de dérisquage** : boucle réseau + modèle 2-process validés, design tranché en review. Ses décisions de design sont REPRISES ici (pas re-tranchées) ; seule la techno change.

## Dépendances

| Dépendance | Statut | Ce qu'on en consomme |
|---|---|---|
| **23.1** contrat v1 | done | `docs/agent/contract-v1.md` + golden files `tests/Fixtures/Agent/{state,report}.v1.json` (FIGÉS) ; algorithme StateHasher à reproduire ; `FROZEN_STATE_HASH` |
| **23.2** cycle de vie token | done | Middleware `AuthenticateAgentToken` : codes 401/403, `X-Agent-New-Token`, anti-clonage `X-Agent-Hostname` |
| **23.3** enrôlement iPXE | done | Chemin token `C:\ProgramData\SambaEdu\Agent\token` = CONTRAT (`docs/agent/enrollment.md` §3, INTOUCHÉ) ; CA interne déployée sur le poste lab |
| **23.5** GET /state | done | ETag/304, `?user=` absent en portée machine |
| **24.1** POST /report | done — **requis** | Ingestion serveur des rapports ; `items: []` valide ; hostname court (defer #8) |
| **24.2** squelette PS | **superseded — source de design** | Toutes les décisions validées en review : boucle, rotation D5 en mémoire, backoff 30 s → ×2 → plafond, quarantaine avec levée auto, ACL icacls, hostname court, ETag verbatim, config.json, log rotation 7 j. Ses `.ps1` sont retirés ICI. |
| 24.3 / 24.4 (PS) | superseded | NE PAS toucher leurs artefacts (`SessionCompanion.ps1`, `SessionStateFetch.ps1`, `ConvergenceEngine.ps1`, `handlers/`, `ContractV1.ps1`) — retrait en 24.6 |

**24.6 dépend de cette story** (le compagnon et les handlers Go s'appuient sur le core livré ici). **25.1 dépend du binaire signé produit ici.**

## ⚠️ Pièges connus (lire avant de coder)

1. **Chemin du token = CONTRAT figé 23.3** : `C:\ProgramData\SambaEdu\Agent\token` (64 hex, sans newline), ACL `NT AUTHORITY\SYSTEM` (`*S-1-5-18`) + `BUILTIN\Administrators` (`*S-1-5-32-544`) uniquement. Relire le fichier à chaque cycle (la rotation peut le changer sur disque). Jamais d'autre chemin. Source : `docs/agent/enrollment.md` §3 — INTOUCHÉ.
2. **Hostname COURT dans le rapport** (defer 24.1 #8, résolu en 24.2 — à CONSERVER en Go) : `ReportController` compare le hostname déclaré à `workstations.name` (nom court) → FQDN = spam `agent.report.identity_mismatch`. En Go : `os.Hostname()` sous Windows retourne le nom court (COMPUTERNAME). Vérifier en test.
3. **`X-Agent-New-Token` lu sur TOUTE réponse** (invariant D5, comportement 24.2 validé) : GET 200 **et** 304, POST même non-200. Écriture **atomique** du nouveau token sur disque ; ancien token gardé **en mémoire** pour la fenêtre de grâce (pas de `token.previous` sur disque — surface minimale, décision 24.2).
4. **401 pendant rotation = un réessai avec l'ancien token** ; 401 irrécupérable → **arrêt + log local, JAMAIS de re-enrôlement automatique silencieux** (l'admin intervient).
5. **403 `AGENT_QUARANTINED` = check-ins légers** : plus de POST /report ni de traitement d'état, mais GET /state continue à cadence normale ; levée automatique au premier 200/304 (comportement 24.2).
6. **ETag opaque stocké VERBATIM** (guillemets RFC 7232 inclus, ex. `"6c0e8135…"`) et renvoyé tel quel dans `If-None-Match`. Tout trim/déquotage brise le 304. L'agent ne recalcule JAMAIS le hash d'état depuis sa propre sérialisation pour le comparer à l'ETag.
7. **Canonicalisation JSON en Go — LES pièges qui casseraient le hash croisé** (voir Dev Notes § StateHasher) :
   - `encoding/json` échappe `&`, `<`, `>` en `\u00XX` par défaut — PHP non. Utiliser `json.Encoder` + `SetEscapeHTML(false)` (et trimmer le `\n` final de l'Encoder).
   - Décoder avec `json.Decoder.UseNumber()` : sans cela, tout nombre devient `float64` et `12` peut ressortir `12` ou `1.2e+01` — le contrat est **zéro float** (§4.1), `json.Number` préserve le littéral exact.
   - Tri des clés : `ksort(SORT_STRING)` PHP = tri lexicographique octet-par-octet. Le marshal Go des maps trie déjà les clés byte-wise — mais implémenter le tri **explicitement et récursivement** (iso-`sortRecursive` PHP) pour ne pas dépendre d'un comportement implicite. **Les listes ne sont PAS triées** (ordre significatif), on descend dans leurs éléments.
   - Compact sans espaces = défaut Go ; UTF-8 brut (pas de `\uXXXX` pour l'Unicode) = défaut Go une fois `SetEscapeHTML(false)`.
8. **`hashState` exclut `generated_at`** (champ volatil) **avant** canonicalisation ; `hashItem` exclut la clé `hash` de l'item (dépendance circulaire). Iso-PHP `app/Services/Agent/StateHasher.php` ligne à ligne.
9. **Golden files = source canonique unique** : les tests Go consomment `tests/Fixtures/Agent/state.v1.json` et `report.v1.json` EN PLACE (chemin relatif depuis `agent/`, ex. `../tests/Fixtures/Agent/`) — ne JAMAIS les copier dans `agent/` (NFR13 : un seul jeu de golden files, serveur ET agent). Hash attendu : `FROZEN_STATE_HASH = 6c0e8135118a24538b526ede21e70a08685643d2bd056c6a79010d7cd52496b7` (cf. `tests/Unit/Services/Agent/ContractV1Test.php:33` — constante dupliquable dans le test Go, le hash est figé).
10. **Refus d'un major inconnu** (contrat §9) : `schema` ≠ `se5.desired-state/v1` (major) → log erreur, ne pas écraser le cache, continuer les check-ins. Champ ajouté inconnu = ignoré (forward-compat).
11. **Timer 60 min + jitter ±10 %** (D7) : 3600 s ± 360 s, configurable via `config.json` (`interval_seconds`) — format local hérité de 24.2, documenté dans `docs/agent/agent-skeleton.md`. **Backoff** : 30 s → ×2 → plafond 3600 s (serveur injoignable, timeout, 5xx, 429). Jamais de retry agressif.
12. **ACL SYSTEM sur tout fichier créé** (token écrit en rotation, cache, applied-state, logs) : les API ACL Go (`golang.org/x/sys/windows`) sont pénibles — le shell-out `icacls.exe` est l'échappatoire documentée et acceptable (iso-24.2, et l'addendum architecture autorise le shell-out depuis l'agent Go). Écritures atomiques (fichier temp + rename).
13. **inotify ne propage PAS les deletes** : le retrait des `.ps1` (T9) laissera des fichiers fantômes sur la VM. **Signaler à Henri AVANT tout cleanup SSH — ne pas le faire soi-même.** (Et : jamais de sync manuelle vers la VM.)
14. **Toolchain Go absente a priori** (hôte et VM) : tâche d'amorçage explicite (T1). Les tests unitaires Go ne dépendent PAS de Windows (`go test ./...` sur l'hôte Linux) ; le code Windows-only vit derrière build tags `//go:build windows` et se valide par cross-compile (`GOOS=windows GOARCH=amd64 go build` + `go vet`) + lab humain. **N'installer la toolchain que sur l'hôte** — rien à installer sur la VM (le Go ne tourne pas côté serveur).
15. **Tests PHP serveur = sur la VM uniquement** : `ssh -i ~/.ssh/id_se4fs_vm root@192.168.122.50 "cd /var/www/sambaedu-reload && php artisan test --filter Agent"` — baseline **206 passed (839 assertions)**. Cette story ne modifie aucun PHP → le run sert de preuve de non-régression. Jamais la suite complète (décision Henri).
16. **Anti-clonage** : envoyer le header `X-Agent-Hostname` (nom court) sur chaque appel (comportement 24.2, consommé par le middleware 23.2). UUID SMBIOS envoyé verbatim (le serveur normalise en minuscules).
17. **`agent/` ne contient JAMAIS de code Laravel et réciproquement** : le Go vit sous `agent/` top-level, rien dans `app/`. Le module Go est autonome (`agent/go.mod`).

## Décisions de design prises ici (à challenger en review, pas à re-trancher en dev)

1. **Module Go unique sous `agent/`** : `agent/go.mod` (nom de module au choix du dev, ex. `sambaedu/agent`, documenté README). Packages : `agent/shared/` = code OS-agnostique (canonicalisation + StateHasher, parsing contrat v1, construction du rapport, logique de boucle/backoff/rotation testable) ; `agent/windows/` = `main` + service SYSTEM + spécifique Win32 (build tags). `agent/build/` = scripts de build/signature, sortie dans `agent/build/dist/` (gitignorée si volumineuse — convention storage pour les artefacts non versionnés).
2. **Service Windows via `golang.org/x/sys/windows/svc`** (package canonique, maintenu par l'équipe Go) : le binaire expose des sous-commandes `install` / `uninstall` / `run` / `version` — elles REMPLACENT `Install-SambaEduAgent.ps1` / `Uninstall-SambaEduAgent.ps1` (plus de wrapper ServiceBase compilé à l'install : le binaire parle le protocole SCM nativement — c'est précisément un des gains de Go). Démarrage automatique, compte LocalSystem, relance sur crash (delay 30 s, via `mgr` ou `sc.exe failure`). `uninstall` conserve token/cache/logs par défaut (flag de purge optionnel), iso-24.2.
3. **Dépendances Go minimales** : stdlib + `golang.org/x/sys` (service, Windows). UUID SMBIOS : au choix du dev — (a) `github.com/digitalocean/go-smbios` (GetSystemFirmwareTable, pur Go, zéro cgo), ou (b) shell-out `powershell Get-CimInstance Win32_ComputerSystemProduct` (échappatoire explicitement admise par l'addendum architecture). Pas de framework HTTP, pas de lib de config : `net/http` + `encoding/json` suffisent. Toute dépendance ajoutée se justifie dans le README contre la contrainte 6 (zéro dépendance exotique).
4. **`agent_version = "2.0.0"`** : la lignée PS était `1.0.0` ; le binaire Go marque une rupture d'artefact — le `2.x` rend les rapports Go discernables des rapports PS en lab. Source unique de la version dans le code Go (variable injectable au build via `-ldflags "-X ..."`), reprise par le zip/nommage de `agent/build/`.
5. **Signature Authenticode depuis l'hôte Linux via `osslsigncode`** (le binaire est cross-compilé sur l'hôte ; `Set-AuthenticodeSignature` exigerait Windows) : `agent/build/` signe le PE avec le certificat de la CA interne SambaEdu (même CA que 24.2, racine déjà déployée sur le poste lab par iPXE 23.3). **Si le matériel de signature (PFX/clé) n'est pas accessible depuis l'hôte → STOP, signaler à Henri** (ne pas générer une nouvelle CA, ne pas livrer non signé). Le build vérifie la signature après coup (`osslsigncode verify`).
6. **Contrats locaux du poste INCHANGÉS** (= ceux de 24.2, documentés `agent-skeleton.md`) : `C:\ProgramData\SambaEdu\Agent\{token, config.json, applied-state.json, cache\state.json, cache\etag.txt, logs\agent.log}`. Le compagnon PS de 24.3 (encore présent jusqu'à 24.6) lit `cache\` : conserver ces chemins lui laisse une chance de continuer à fonctionner, mais **sa continuité n'est PAS un AC** (pas d'état transitoire — casse temporaire admise en lab entre 24.5 et 24.6).
7. **Rapport portée machine, `items: []` admis** : tant que les handlers ne sont pas portés (24.6), le binaire Go rapporte une liste vide — valide côté serveur (AC9 de 24.1). `applied-state.json` (infra mode `default`, gap 1) est créé/préservé vide pour 24.6.
8. **Log local structuré** : `logs\agent.log`, format `[ISO 8601] [LEVEL] message`, rotation quotidienne, 7 jours de rétention — iso-24.2, implémenté en Go (pas de lib externe de logging nécessaire).
9. **Tests Go = `go test ./...` sur l'hôte** : tout `agent/shared/` est testable sans Windows (table-driven). Le spécifique Windows est validé par cross-compile + `go vet` + installation lab humaine (T11). Pas de CI Go montée dans cette story (le repo n'en a pas) — le `go test` local est le gate, sa commande est documentée README.

## Acceptance Criteria

### AC1 — Structure repo Go + retrait des `.ps1` de 24.2 (pas d'état transitoire)

**Given** le repo `agent/`
**Then** le code Go vit sous `agent/` (`shared/` = boucle test/apply/report + parsing du contrat + `StateHasher` Go ; `windows/` = service SYSTEM ; `build/` = build/signature), jamais dans `app/` ; module Go autonome (`agent/go.mod`)
**And** les `.ps1` de 24.2 — `windows/SambaEduAgent.ps1`, `windows/Install-SambaEduAgent.ps1`, `windows/Uninstall-SambaEduAgent.ps1`, `build/Build-Agent.ps1` — sont **retirés** (git rm) une fois leur équivalent Go vert ; l'historique git garde la trace
**And** les artefacts de 24.3/24.4 (`SessionCompanion.ps1`, `SessionStateFetch.ps1`, `ConvergenceEngine.ps1`, `windows/handlers/`, `shared/ContractV1.ps1` — encore consommé par eux) sont INTOUCHÉS (retrait → 24.6)
**And** le piège inotify-deletes est signalé à Henri (fichiers fantômes VM — aucun cleanup SSH sans son accord).

### AC2 — Boucle service SYSTEM : check-in, cache, rapport machine (FR17, FR18, FR23)

**Given** un poste de lab enrôlé (token de 23.3)
**When** le service SYSTEM Go démarre (boot) puis à chaque timer (60 min + jitter ±10 %, configurable via `config.json`)
**Then** il lit le token (`C:\ProgramData\SambaEdu\Agent\token`, relu à chaque cycle), appelle `GET /api/v1/agent/state` avec `If-None-Match` (ETag verbatim) et le header `X-Agent-Hostname` (nom court), met en cache local le dernier état connu (`cache\state.json` + `cache\etag.txt`, fichiers sous ACL SYSTEM, écriture atomique)
**And** il construit et envoie son rapport portée machine (`POST /api/v1/agent/report`) : `schema`, `generated_at`, `agent_version`, `workstation: {hostname: <court>, uuid: <SMBIOS verbatim>}`, `items: []` admis tant que les handlers ne sont pas portés (24.6)
**And** sur 304, le cache est réutilisé et le rapport part quand même ; le serveur stocke (`agent_last_checkin_at` mis à jour, vérifiable) ; aucun appel AD/Kerberos/LDAP nulle part (critère Keycloak, NFR7).

### AC3 — StateHasher Go iso-23.1, validé golden files (tests croisés)

**Given** `StateHasher`
**Then** l'agent Go reproduit l'algorithme de 23.1 **à l'identique** : tri récursif lexicographique octet-par-octet des clés des objets (iso `ksort SORT_STRING` — les listes ne sont PAS triées), JSON compact UTF-8 sans échappement HTML ni espaces, **zéro float** (`json.Number`), `generated_at` exclu de `hashState`, clé `hash` exclue de `hashItem`, SHA-256 hex
**And** les tests Go le valident contre les golden files canoniques `tests/Fixtures/Agent/` consommés EN PLACE (jamais copiés) : hash du golden state == `FROZEN_STATE_HASH` (`6c0e8135…`), hashes d'items == champs `hash` du golden file — tests croisés serveur/agent (NFR13)
**And** en runtime l'agent compare des hashes **opaques** fournis par le serveur (ETag, `item.hash`) — il ne recalcule jamais un hash depuis sa propre sérialisation pour décider.

### AC4 — Résilience : serveur injoignable, backoff exponentiel (FR22)

**Given** le serveur SE5 injoignable (network unreachable, timeout, 5xx — et 429 throttle)
**Then** l'agent fonctionne sur son dernier état en cache (aucun crash, le service reste up)
**And** les retries suivent un backoff exponentiel 30 s → 60 s → 120 s → … plafonné au timer (3600 s) — jamais de retry agressif
**And** le log local indique le motif et le délai du prochain retry.

### AC5 — Rotation D5 et quarantaine (FR13, FR15, FR22)

**Given** le serveur renvoie `X-Agent-New-Token` (sur GET 200/304 ou POST, même non-200)
**Then** l'agent écrit le nouveau token atomiquement à `C:\ProgramData\SambaEdu\Agent\token` et l'utilise dès l'appel suivant ; l'ancien token est gardé en mémoire pour la fenêtre de grâce
**And** un 401 juste après rotation → un réessai avec l'ancien token ; un 401 irrécupérable → **arrêt + log local, jamais de re-enrôlement silencieux**
**And** un 403 `AGENT_QUARANTINED` → l'agent cesse de converger (plus de POST /report ni de traitement d'état) mais poursuit des check-ins légers (`GET /state` à cadence normale) ; levée automatique au premier 200/304.

### AC6 — Build signé + gate techno résolu (NFR6)

**Given** le build
**Then** `agent/build/` produit un **binaire Go statique unique** (CGO désactivé), cross-compilé Windows (`GOOS=windows GOARCH=amd64`), **signé Authenticode** avec la CA interne SambaEdu (racine déployée par l'install iPXE 23.3), signature vérifiée par le build lui-même ; zéro dépendance runtime sur le poste (NFR6) ; version injectée au build (source unique)
**And** `agent/README.md` (réécrit) documente la décision **Go** contre le cahier des **7 contraintes** (service SYSTEM + compagnon, artefact signable, ACL SYSTEM, auto-update fiable, cœur partageable, zéro dépendance exotique, empreinte discrète) — gate techno (ex-PoC P2) **résolu** ; mentionne la règle Rust/COM-WinRT (réévaluation uniquement si l'Epic 27 l'exige, échappatoire = shell-out PowerShell)
**And** Windows n'affiche aucun blocage SmartScreen/politique d'exécution sur le poste lab.

### AC7 — Tests : go test local, tests croisés, baseline serveur intacte

**Given** la suite de tests Go
**When** `go test ./...` s'exécute sur l'hôte (Linux, sans Windows)
**Then** tous les tests passent : StateHasher (golden files croisés, AC3), parsing contrat (3 portées, refus major inconnu, forward-compat), construction du rapport (hostname court, `items: []`), logique rotation/grâce/backoff/quarantaine (testée via interfaces/fakes HTTP, sans réseau réel)
**And** le code Windows-only compile en cross (`GOOS=windows go build ./...` + `go vet ./...`) — sa validation runtime = lab humain (T11)
**And** côté serveur : **AUCUN fichier PHP modifié** ; run de non-régression sur /vm `php artisan test --filter Agent` = **206 passed (839 assertions)**, zéro régression (jamais la suite complète).

### AC8 — Docs et intouchables

**Given** la documentation
**Then** `agent/README.md` porte : décision Go (AC6), structure du module, commandes build/test (`go test ./...`, cross-compile, signature), contrats locaux du poste (chemins, formats — inchangés), procédure d'installation lab (`agent.exe install`)
**And** `docs/agent/agent-skeleton.md` est mis à jour là où il référençait l'implémentation PowerShell (la boucle vue serveur, les codes HTTP et les contrats locaux restent identiques)
**And** `docs/qa/domains/agent.md` est enrichi **append-only** (nouvelle section « Boucle agent Go », sans renuméroter l'existant) : install du service Go sur le poste lab, vérification check-in/rapport, résilience — jamais de fichier QA par story
**And** restent INTOUCHÉS : `docs/agent/contract-v1.md`, `docs/agent/enrollment.md`, `tests/Fixtures/Agent/*`, `FROZEN_STATE_HASH`, tout le PHP serveur (`StateController`, `ReportController`, `AuthenticateAgentToken`, `ReportIngestService`, `StateHasher.php`…).

## Tasks / Subtasks

- [x] **T1 — Amorçage toolchain Go + squelette de module** (AC1, AC7)
  - [x] Vérifier `go version` sur l'hôte ; si absent, installer une toolchain Go stable récente (tarball officiel go.dev privilégié — l'apt Ubuntu peut être daté ; documenter la version retenue dans `agent/README.md`). RIEN à installer sur la VM. → **go1.26.4 user-local `~/go-toolchain/go/bin/go`, documentée README §Toolchain**
  - [x] `agent/go.mod` + arborescence packages : `agent/shared/` (OS-agnostique), `agent/windows/` (main + build tags `//go:build windows`), `agent/build/` → module `sambaedu/agent`, contrainte Windows par suffixe `_windows.go` + stub `//go:build !windows`
  - [x] Vérifier que `go test ./...` (hôte) et `GOOS=windows GOARCH=amd64 go build ./...` passent à vide

- [x] **T2 — StateHasher Go + tests croisés golden files** (AC3) — *à faire EN PREMIER après T1 : c'est le noyau de conformité*
  - [x] Canonicalisation : décodage `UseNumber()`, tri récursif explicite des clés (octet-par-octet, listes non triées), encodage compact `SetEscapeHTML(false)` sans `\n` final → encodage chaînes implémenté à la main (`appendCanonicalString`) pour l'iso-PHP exact (\b/\f nommés, U+2028/U+2029, hex minuscule) + reproduction du comportement `json_decode assoc` (clés "0".."n-1" → liste, `{}` → `[]`)
  - [x] `hashState` (exclusion `generated_at`) + `hashItem` (exclusion `hash`) — miroir ligne à ligne de `app/Services/Agent/StateHasher.php`
  - [x] Tests croisés : golden `state.v1.json` → `FROZEN_STATE_HASH` ; chaque item du golden → son champ `hash` ; cas tordus (clés numériques `"9"`/`"10"`, Unicode NFC, imbrication, slashes) → + 8 cas hex calculés contre le StateHasher PHP RÉEL sur la VM (2026-06-12)

- [x] **T3 — Parsing contrat v1 + construction du rapport** (AC2, AC7)
  - [x] Parse state : validation `schema` (refus major inconnu → log + cache préservé + check-ins continuent), extraction des 3 portées, champs inconnus ignorés (forward-compat §9)
  - [x] Build report : `schema`, `generated_at` (ISO 8601 + timezone), `agent_version` (source unique injectée au build), `workstation: {hostname court, uuid SMBIOS verbatim}`, `items: []`
  - [x] Hostname court (`os.Hostname()`, vérifié) ; UUID SMBIOS (go-smbios OU shell-out PowerShell — choix documenté README) → **shell-out PowerShell retenu** (option b : zéro dépendance ajoutée, même source CIM que le spike 24.2)

- [x] **T4 — Client HTTP agent** (AC2, AC4, AC5)
  - [x] Bearer token (relu sur disque à chaque cycle), `If-None-Match` verbatim, header `X-Agent-Hostname`, timeouts explicites, TLS via cert pool système (racine CA interne déjà déployée)
  - [x] Lecture `X-Agent-New-Token` sur TOUTE réponse (GET 200/304, POST même non-200) → écriture atomique token + ancien en mémoire (grâce)
  - [x] Gestion codes : 200, 304, 401 (grâce puis arrêt), 403 (quarantaine), 429/5xx/timeout (backoff) → + durcissement deux-acteurs 24.3 conservé (relecture disque avant 401 irrécupérable)
  - [x] Tests avec `httptest` (rotation, grâce, 304, quarantaine, backoff) — zéro réseau réel

- [x] **T5 — Cache local + ACL SYSTEM** (AC2)
  - [x] `cache\state.json` + `cache\etag.txt` : écriture atomique (temp + rename), ACL SYSTEM + Administrators (shell-out `icacls.exe` admis, iso-24.2) → temp suffixé PID (deux écrivains SYSTEM, décision 24.3), ACL posée sur le temp AVANT rename
  - [x] `applied-state.json` créé/préservé vide (infra gap 1 pour 24.6)
  - [x] Logique de cache testable côté `shared/` (interface filesystem ou répertoire paramétrable) ; l'ACL Windows-only derrière build tag → `Store{Root, SetACL}` injectables (répertoire paramétrable, SetACL nil = no-op tests)

- [x] **T6 — Boucle de convergence core** (AC2, AC4, AC5)
  - [x] Cycle : token → GET /state (If-None-Match) → 200: persister cache/ETag | 304: réutiliser → rapport (`items: []`) → POST /report → attendre timer
  - [x] Timer 3600 s + jitter ±10 % (config.json `interval_seconds`, format 24.2 conservé) ; backoff 30 s → ×2 → plafond 3600 s ; états quarantaine (check-ins légers, levée auto) et arrêt 401
  - [x] Log local `logs\agent.log` : `[ISO 8601] [LEVEL] message`, rotation quotidienne, rétention 7 j
  - [x] Tests de la machine à états (cycle, transitions quarantaine/backoff/rotation) en pur Go, sans Windows

- [x] **T7 — Service Windows SYSTEM** (AC2)
  - [x] `golang.org/x/sys/windows/svc` : le binaire tourne en service (LocalSystem, démarrage auto), arrêt propre sur signal SCM
  - [x] Sous-commandes `install` / `uninstall` / `run` / `version` (remplacent les .ps1 d'install/uninstall) ; relance sur crash 30 s ; `uninstall` conserve token/cache/logs par défaut (flag purge optionnel) → relance via `SetRecoveryActions` (sortie PROPRE sur 401 = pas de relance SCM) ; install idempotent (stop + delete + attente disparition effective)
  - [x] Validation par cross-compile + `go vet` (runtime = T11 lab)

- [x] **T8 — Build signé** (AC6)
  - [x] Script `agent/build/` : `CGO_ENABLED=0 GOOS=windows GOARCH=amd64 go build -ldflags "-X <version>"` → binaire statique unique dans `agent/build/dist/` (~7 Mo, `-trimpath -s -w`)
  - [x] Localiser le matériel de signature CA interne (cf. README 24.2 / chaîne iPXE 23.3) ; **si introuvable/inaccessible depuis l'hôte → STOP et signaler à Henri** → **localisé : PKI sur la VM (`storage/keys/pki/ca-root.{crt,key}`) mais AUCUN PFX code-signing n'existe — son émission depuis la CA de prod = décision Henri (procédure documentée README §Signature) ; pipeline validé avec cert de test jetable, voir Completion Notes**
  - [x] Signature Authenticode (`osslsigncode` sur l'hôte Linux) + vérification post-build (`osslsigncode verify`) intégrée au script → osslsigncode 2.9 user-local (`~/.local/opt/osslsigncode`), refus de build sans `CODESIGN_PFX` (échappatoire dev explicite `ALLOW_UNSIGNED=1`, jamais déployable), vérification chaîne obligatoire (`CODESIGN_CA`)
  - [x] Ne JAMAIS committer de PFX/clé privée ; `dist/` non versionné si artefacts produits (convention storage) → `.gitignore` : `/agent/build/dist/` + `*.pfx`

- [x] **T9 — Retrait des `.ps1` de 24.2** (AC1) — *seulement une fois T2→T8 verts*
  - [x] `git rm` : `agent/windows/SambaEduAgent.ps1`, `Install-SambaEduAgent.ps1`, `Uninstall-SambaEduAgent.ps1`, `agent/build/Build-Agent.ps1` (stagés)
  - [x] NE PAS toucher : `SessionCompanion.ps1`, `SessionStateFetch.ps1`, `ConvergenceEngine.ps1`, `handlers/`, `shared/ContractV1.ps1` (consommés par 24.3/24.4, retrait en 24.6 — vérifié par grep) → intacts ; casse temporaire ASSUMÉE du dot-source `SessionStateFetch.ps1` → `SambaEduAgent.ps1` (documentée README + QA, décision n° 6)
  - [x] Noter dans la story (Completion Notes) le rappel fichiers fantômes VM (inotify no-delete) — cleanup SSH = décision Henri, pas du dev

- [x] **T10 — Documentation** (AC6, AC8)
  - [x] `agent/README.md` réécrit : décision Go vs 7 contraintes (gate résolu), règle Rust/COM-WinRT + échappatoire shell-out, structure module, toolchain/commandes (test, cross-compile, build signé), contrats locaux inchangés, install lab
  - [x] `docs/agent/agent-skeleton.md` : remplacer les références d'implémentation PowerShell par le binaire Go (boucle/contrats/codes HTTP inchangés côté serveur)
  - [x] `docs/qa/domains/agent.md` : nouvelle section append-only « Boucle agent Go » (install service Go, check-in vérifiable serveur, résilience, signature) — ne pas renuméroter l'existant → Section 5 (scénarios 5.1-5.5 + checkboxes), sections 1-4 non renumérotées ; + ligne 24.5 dans `docs/qa/README.md`

- [ ] **T11 — Validation finale** (AC2, AC7)
  - [x] `go test ./...` vert sur l'hôte + cross-compile/`go vet` verts → 73 tests PASS (53 fonctions + sous-tests), `go vet` vert sur linux ET GOOS=windows
  - [x] Run non-régression serveur sur /vm : `php artisan test --filter Agent` → **206 passed (839 assertions)**, zéro modification PHP (git status le prouve)
  - [ ] Installation lab (poste windoobe ws 49) : **ACTION HUMAINE** (Henri) — `agent.exe install` après désinstallation du service PS ; vérifier `agent_last_checkin_at` + rapport reçu (runbook QA section Go) ; documenter le résultat en Completion Notes

## Dev Notes

### Périmètre — livré / hors-scope

| Livré (24.5) | Hors-scope (story) |
|---|---|
| Core Go : boucle GET state → cache → POST report (`items: []`) | Compagnon de session Go (24.6) |
| StateHasher Go + tests croisés golden files | Handlers wallpaper/overlay Go (24.6) |
| Service SYSTEM Go (install/uninstall/run intégrés au binaire) | Retrait de `ContractV1.ps1`, `ConvergenceEngine.ps1`, `SessionCompanion.ps1`, `handlers/` (24.6) |
| Rotation D5, backoff, quarantaine, ACL SYSTEM, log local | UI conformité + forcer la synchro (24.7) |
| Build signé Authenticode (binaire statique cross-compilé) | Distribution canari, manifest, auto-update (25.x) |
| Retrait des `.ps1` de 24.2 (squelette + install/uninstall/build) | Enrôlement porte 2 (25.3) |
| README gate techno résolu + doc QA append-only | Tout code PHP serveur (figé) ; agent Linux |

### Contrat v1 — ce que l'agent consomme (invariants figés, JAMAIS à modifier)

[Source: docs/agent/contract-v1.md §2, §6, §9 ; tests/Fixtures/Agent/state.v1.json ; report.v1.json]

**Enveloppe état (`GET /state` → 200) :** `{schema: "se5.desired-state/v1", generated_at, ttl_seconds, machine: [], session: [], machine_user: []}`. Vérifier `schema`, refuser un major inconnu, ignorer les champs ajoutés.

**Rapport (`POST /report`) :** `{schema, generated_at, agent_version, workstation: {hostname, uuid}, items: []}` — `items` vide = valide (AC9 24.1, aucune ligne `agent_resource_states` créée, check-in stampé par le middleware).

**ETag** : émis quoté (`"6c0e8135…"`), renvoyé verbatim dans `If-None-Match`.

### StateHasher — l'algorithme PHP à reproduire (le cœur de l'AC3)

[Source: app/Services/Agent/StateHasher.php ; docs/agent/contract-v1.md §4, §4.1 ; tests/Unit/Services/Agent/ContractV1Test.php:33]

Référence PHP, comportement exact :
1. `hashState(state)` : `unset(generated_at)` → canonicalize → sha256 hex.
2. `hashItem(item)` : `unset(hash)` → canonicalize → sha256 hex.
3. `canonicalize` : tri récursif PUIS `json_encode(JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)` — compact, UTF-8 brut, slashes non échappés.
4. `sortRecursive` : tableau associatif → `ksort($v, SORT_STRING)` (tri lexicographique octet-par-octet — `"10"` < `"9"`) puis récursion sur les valeurs ; **liste** (clés séquentielles) → PAS de tri, récursion sur les éléments ; scalaire → tel quel.

Équivalences Go et pièges (cf. Pièges #7-#9) : `UseNumber()` (zéro float, §4.1 — la sérialisation des floats dépend de la plateforme, un float rendrait le hash instable), `SetEscapeHTML(false)`, tri explicite récursif (ne pas se reposer sur le tri implicite du marshal de map), trim du `\n` de l'Encoder. Unicode : le serveur émet en **NFC**, le hash compare octet à octet — pas de normalisation à faire dans le hasher lui-même ; la normalisation NFC concernera les comparaisons de valeurs des handlers (24.6, `golang.org/x/text/unicode/norm` le moment venu).

**Rôle runtime vs rôle test** : en runtime, l'agent ne se sert PAS du StateHasher pour décider (ETag verbatim, `item.hash` opaque) — le StateHasher Go existe pour la conformité prouvée (tests croisés) et les besoins futurs (empreintes locales 24.6). Cette distinction est dans l'AC de l'epics : « l'agent compare des hashes opaques, il ne recalcule jamais depuis sa propre sérialisation ».

### Chemins locaux du poste — CONTRATS (figés 23.3 / 24.2)

[Source: docs/agent/enrollment.md §3 (INTOUCHÉ) ; docs/agent/agent-skeleton.md ; agent/README.md (24.2)]

- `C:\ProgramData\SambaEdu\Agent\token` — 64 hex sans newline, ACL `*S-1-5-18` + `*S-1-5-32-544`. **Chemin = contrat, purge sysprep obligatoire (mémoire projet).**
- `…\Agent\config.json` — `interval_seconds` (cadence), format 24.2 conservé
- `…\Agent\cache\state.json`, `…\Agent\cache\etag.txt` — cache état + ETag, ACL SYSTEM
- `…\Agent\applied-state.json` — infra mode `default` (vide jusqu'à 24.6)
- `…\Agent\logs\agent.log` — rotation quotidienne, 7 j

### Middleware AuthenticateAgentToken — comportements serveur à gérer (figé 23.2)

[Source: app/Http/Middleware/AuthenticateAgentToken.php]

- Token manquant/invalide → `401` (`AGENT_TOKEN_MISSING` / `AGENT_TOKEN_INVALID`)
- Quarantaine → `403` (`AGENT_QUARANTINED`)
- Rotation due → `X-Agent-New-Token` sur tout 2xx (et géré par 24.2 sur toute réponse — conserver)
- Anti-clonage : `X-Agent-Hostname` divergent → quarantaine ; envoyer le nom court
- `agent_last_checkin_at` écrit par le middleware (pas par le controller)
- Throttle `throttle:60,1` sur state/report — sans impact au timer 60 min ; 429 → backoff

### Environnement de dev — règles VM (critiques)

- Code édité sur l'hôte, sync inotify auto vers la VM (`/var/www/sambaedu-reload`). **Jamais de sync manuelle.** inotify ne propage pas les deletes → T9 = fichiers fantômes VM, signaler à Henri.
- **Go = hôte uniquement** : toolchain, `go test`, cross-compile, signature — tout sur l'hôte. La VM ne sert que pour les tests PHP (`ssh -i ~/.ssh/id_se4fs_vm root@192.168.122.50`).
- Aucune route/config/migration Laravel nouvelle → aucune opération artisan (`config:cache`/`route:cache`) requise sur la VM pour cette story.
- PHP-FPM user `www-admin` : non applicable (aucun fichier servi par PHP créé).
- Si le repo passe par un worktree git : jamais d'interaction VM depuis le worktree (mémoire projet).

### Intelligence stories précédentes

- **23.1 (done)** : contrat v1 + golden files INTOUCHABLES ; `FROZEN_STATE_HASH = 6c0e8135118a24538b526ede21e70a08685643d2bd056c6a79010d7cd52496b7` ; règle d'évolution §9.
- **23.2/23.3 (done)** : middleware + chemin token (contrats ci-dessus) ; CA interne déployée par iPXE.
- **24.1 (done)** : `items: []` accepté ; hostname FQDN → warning `identity_mismatch` (rapport accepté quand même) ; rien à modifier.
- **24.2 (superseded, source de design)** : TOUTES les décisions de design listées plus haut viennent de sa review (validée). Son `AgentSkeletonE2eTest.php` (12 tests) teste le SERVEUR en simulant un agent — il reste valide tel quel pour l'agent Go (la boucle est identique vue du serveur) : NE PAS le modifier.
- **24.3/24.4 (superseded)** : leurs artefacts PS restent au repo jusqu'à 24.6 ; leur design (per-SID drop, compagnon résident, handlers) sera la source de 24.6 — hors-scope ici.
- **Baseline tests** : `--filter Agent` = **206 passed (839 assertions)** sur /vm (post-24.4). Jamais la suite complète.

### Project Structure Notes

- `agent/` top-level = module Go autonome, hors Laravel. Racine du repo = projet Laravel (artisan/app à la racine).
- État actuel d'`agent/` (avant cette story) : `README.md`, `shared/{ContractV1.ps1, ConvergenceEngine.ps1}`, `windows/{SambaEduAgent.ps1, SessionCompanion.ps1, SessionStateFetch.ps1, Install-SambaEduAgent.ps1, Uninstall-SambaEduAgent.ps1, handlers/{Wallpaper.ps1, Overlay.ps1}}`, `build/Build-Agent.ps1`. Après 24.5 : Go partout, + les `.ps1` de 24.3/24.4 et `ContractV1.ps1` en sursis (24.6).
- `agent/build/dist/` : si des binaires y sont produits, ne pas les committer (convention storage : assets non versionnés ; le binaire de prod sera déposé dans `storage/agent/releases/` par 25.1).

### References

- [Source: _bmad-output/planning-artifacts/sprint-change-proposal-2026-06-12.md §4.A] — définition de la story, justification Plan 2, critères de succès
- [Source: _bmad-output/planning-artifacts/epics-agent-desired-state.md#Story 24.5] — AC source ; FR17-FR18, FR22-FR23 ; note bascule Go Epic 24
- [Source: _bmad-output/planning-artifacts/architecture-agent-desired-state.md#Côté client — techno agent + Addendum 2026-06-12] — 7 contraintes, gate résolu Go, règle Rust/COM-WinRT, échappatoire shell-out
- [Source: app/Services/Agent/StateHasher.php] — algorithme de référence à reproduire (sortRecursive SORT_STRING, listes non triées, volatils)
- [Source: docs/agent/contract-v1.md §2, §4, §4.1, §6, §9] — enveloppe, hash, zéro float/NFC, rapport, évolution
- [Source: docs/agent/enrollment.md §3] — chemin token figé (INTOUCHÉ)
- [Source: tests/Fixtures/Agent/state.v1.json ; report.v1.json] — golden files normatifs, consommés en place par les tests Go
- [Source: tests/Unit/Services/Agent/ContractV1Test.php:33] — FROZEN_STATE_HASH
- [Source: app/Http/Middleware/AuthenticateAgentToken.php] — 401/403, X-Agent-New-Token, anti-clonage
- [Source: _bmad-output/implementation-artifacts/24-2-agent-squelette-windows-service-checkin-cache-build.md] — décisions de design validées (rotation en mémoire, backoff, quarantaine levée auto, config.json, log 7 j, hostname court)
- [Source: docs/agent/agent-skeleton.md] — boucle vue serveur, contrats locaux (à mettre à jour côté implémentation)
- [Source: docs/qa/domains/agent.md] — runbook QA par domaine, append-only

## Dev Agent Record

### Agent Model Used

claude-fable-5 (deux sessions : développement initial interrompu par la limite de session, puis **reprise** le même jour par un second agent fable — audit complet du code déposé, corrections, exécution des gates de test, build signé, bookkeeping).

### Debug Log References

- `go vet ./...` : vert (hôte Linux ET `GOOS=windows GOARCH=amd64`)
- `go test ./... -count=1` : **73 tests PASS** (53 fonctions de test + sous-tests), 0 fail — dont preuve golden files : `TestHashStateGoldenMatchesFrozenHash` (golden `state.v1.json` → `6c0e8135118a24538b526ede21e70a08685643d2bd056c6a79010d7cd52496b7`), `TestHashItemGoldenItemsMatchTheirHashFields` (3 items du golden → leurs champs `hash`), `TestHashItemCrossValidatedAgainstPhp` (8 cas tordus, hashes calculés avec le StateHasher PHP réel sur la VM le 2026-06-12)
- `CGO_ENABLED=0 GOOS=windows GOARCH=amd64 go build ./...` : vert
- `agent/build/build.sh` (cert de test jetable) : build statique 6,7 Mo → signature osslsigncode `Succeeded` → `osslsigncode verify` `Signature verification: ok` → artefact `agent/build/dist/sambaedu-agent-2.0.0.exe` (non versionné)
- /vm `php artisan test --filter Agent` : **206 passed (839 assertions)**, durée 12,2 s — baseline intacte, zéro PHP modifié

### Completion Notes List

1. **Reprise après interruption (2026-06-12)** : le code Go complet déposé par la première session a été audité tâche par tâche contre les AC — verdict : complet et conforme (StateHasher iso-PHP avec encodage de chaînes manuel reproduisant `json_encode` exactement, client rotation D5/grâce/deux-acteurs, boucle/quarantaine/backoff, service SCM natif, store atomique ACL). Une seule correction apportée en reprise : **bug `build.sh` non re-exécutable** (osslsigncode refuse d'écraser une sortie existante → `rm -f "$signed"` avant signature).
2. **Signature : cert de test jetable, CA réelle = action Henri.** La PKI interne vit sur la VM (`storage/keys/pki/ca-root.{crt,key}`) mais **aucun PFX code-signing n'existe** ; l'émettre depuis la CA de production est une décision/action Henri (procédure complète documentée `agent/README.md` §Signature — la clé CA ne quitte pas le serveur, seul le PFX descend sur la machine de build). Le pipeline build→sign→verify a été **prouvé de bout en bout** avec un certificat de test jetable (`CN=SambaEdu Codesign TEST (jetable)`, validité 24 h, jamais committé). ⚠️ Le binaire actuellement dans `dist/` est signé TEST : **ne pas déployer en lab avant re-build avec le PFX CA réel** (sinon la chaîne ne remonte pas à la racine déployée par iPXE 23.3 → SmartScreen).
3. **Fichiers fantômes VM (rappel piège n° 13)** : les `git rm` des 4 `.ps1` de 24.2 ne se propagent PAS à la VM (inotify ne synchronise pas les deletes) → `SambaEduAgent.ps1`, `Install-SambaEduAgent.ps1`, `Uninstall-SambaEduAgent.ps1`, `Build-Agent.ps1` restent présents sur `/var/www/sambaedu-reload`. **Cleanup SSH = décision Henri**, rien n'a été touché. (Sans gravité : la VM ne sert que le PHP, ces fichiers n'y sont jamais exécutés.)
4. **Casse temporaire assumée du chemin session PS** (décision n° 6 de la story) : `SessionStateFetch.ps1` (24.3, conservé) dot-source `SambaEduAgent.ps1` (retiré) → le fetch de session PS est cassé en lab entre 24.5 et 24.6. Documenté README (note transition) + QA domaine (bandeau bascule Go). Les contrats locaux (`cache\`, chemins) sont conservés pour le portage 24.6.
5. **T11-lab = ACTION HUMAINE restante** (Henri, poste windoobe ws 49) : désinstaller le service PS (`Uninstall-SambaEduAgent.ps1` du bundle 24.2-24.4), re-builder signé CA réelle (note 2), copier `agent.exe`, `agent.exe install -server-url …`, dérouler le runbook QA Section 5 (scénarios 5.2-5.5 : check-in serveur, résilience, rotation, uninstall conservateur). L'AC6 « aucun blocage SmartScreen » ne se constate qu'à ce moment-là.
6. **Choix d'implémentation documentés** : module `sambaedu/agent` ; dépendances = stdlib + `golang.org/x/sys` uniquement ; UUID SMBIOS par shell-out PowerShell (option b de la décision n° 3 — zéro dépendance ajoutée, même source CIM que le spike) ; contrainte Windows par suffixe de fichier `_windows.go` + stub `!windows` (équivalent au build tag explicite) ; `agent_version = "2.0.0"` injectable `-ldflags -X`.
7. **Artefacts non versionnés** : `.gitignore` exclut `/agent/build/dist/` et `*.pfx` — le `.exe` ne rentre pas en git (convention storage ; le binaire de prod ira dans `storage/agent/releases/` en 25.1).
8. Les modifications de `_bmad-output/{backlog.html, planning-artifacts/architecture-…, planning-artifacts/epics-…}` présentes dans le working tree proviennent de l'application du sprint-change-proposal du même jour (course-correct), pas de cette story.

### File List

**Créés (code Go + build) :**
- `agent/go.mod`, `agent/go.sum` — module `sambaedu/agent` (go 1.26, dep unique `golang.org/x/sys v0.46.0`)
- `agent/shared/version.go` — doc package + `Version = "2.0.0"` (source unique, injectable)
- `agent/shared/hasher.go` + `agent/shared/hasher_test.go` — StateHasher Go iso-PHP (canonicalisation manuelle) + tests croisés golden files/PHP réel
- `agent/shared/contract.go` + `agent/shared/contract_test.go` — constantes figées v1, ParseState (refus major, forward-compat), BuildReport
- `agent/shared/client.go` + `agent/shared/client_test.go` — client HTTP : Bearer, X-Agent-Hostname, If-None-Match verbatim, rotation D5, grâce, durcissement deux-acteurs
- `agent/shared/files.go` + `agent/shared/files_test.go` — Store : chemins contrats figés, token 64-hex, écritures atomiques (tmp PID + ACL + rename), cache/etag/applied-state, config.json
- `agent/shared/loop.go` + `agent/shared/loop_test.go` — boucle de convergence : cycle, quarantaine (levée auto), backoff 30 s→×2→3600 s, jitter ±10 %, arrêt 401
- `agent/shared/logger.go` + `agent/shared/logger_test.go` — log `[ISO 8601] [LEVEL]`, rotation quotidienne, rétention 7 j
- `agent/windows/main_windows.go` — main : détection contexte service, sous-commandes install/uninstall/run/version, assemblage agent
- `agent/windows/service_windows.go` — svc.Handler SCM (arrêt propre, sortie gracieuse sur 401)
- `agent/windows/install_windows.go` — install/uninstall SCM natifs (LocalSystem, auto, relance 30 s, uninstall conservateur + -purge)
- `agent/windows/acl_windows.go` — setAgentACL (icacls, SIDs bruts S-1-5-18/S-1-5-32-544, héritage retiré)
- `agent/windows/smbios_windows.go` — UUID SMBIOS (shell-out Get-CimInstance, échec → uuid vide non bloquant)
- `agent/windows/main_stub.go` — stub `!windows` (compilabilité hôte)
- `agent/build/build.sh` — build statique cross-compilé + signature osslsigncode + verify intégré (corrigé en reprise : re-run)
- (non versionné, gitignoré : `agent/build/dist/sambaedu-agent-2.0.0.exe`)

**Modifiés :**
- `.gitignore` — `/agent/build/dist/` + `*.pfx`
- `agent/README.md` — réécrit : décision Go vs 7 contraintes (gate résolu), règle Rust/COM-WinRT, toolchain/commandes, contrats locaux, signature (procédure émission PFX), install lab, note transition 24.5→24.6
- `docs/agent/agent-skeleton.md` — références d'implémentation PS → binaire Go (boucle/codes HTTP inchangés vus serveur), agent_version 2.0.0
- `docs/qa/domains/agent.md` — append-only : Section 5 « Boucle agent Go » (5.1 build signé, 5.2 install service, 5.3 check-in serveur, 5.4 résilience, 5.5 uninstall) + bandeau bascule Go + checkboxes 5.x
- `docs/qa/README.md` — ligne domaine agent étendue à 24.5
- `_bmad-output/implementation-artifacts/24-5-agent-go-core-service-systeme-build-signe.md` — cette story (bookkeeping)
- `_bmad-output/implementation-artifacts/sprint-status.yaml` — ligne 24-5 → review

**Supprimés (git rm, stagés) :**
- `agent/windows/SambaEduAgent.ps1`
- `agent/windows/Install-SambaEduAgent.ps1`
- `agent/windows/Uninstall-SambaEduAgent.ps1`
- `agent/build/Build-Agent.ps1`

**Intouchés (vérifiés)** : `docs/agent/contract-v1.md`, `docs/agent/enrollment.md`, `tests/Fixtures/Agent/*`, tout le PHP serveur, `ContractV1.ps1`/`ConvergenceEngine.ps1`/`SessionCompanion.ps1`/`SessionStateFetch.ps1`/`handlers/` (sursis 24.6).

## Recommandation Modèle Dev

**fable.** Décision projet existante : les stories agent desired-state se développent avec fable (mémoire `feedback_epic23_model_fable5` — le réflexe « contrat = petit modèle » est précisément à éviter). Sur le fond, cette story coche toutes les cases du critère fable : nouveau langage dans le repo (amorçage toolchain Go, module neuf), composant de conformité critique (StateHasher Go bit-à-bit identique au PHP — les pièges de canonicalisation `encoding/json` vs `json_encode` sont subtils et un hash divergent serait silencieusement catastrophique), intégration système Windows (service SYSTEM via `x/sys/windows/svc`, ACL, signature Authenticode cross-compilée), machine à états de résilience (rotation D5 avec grâce, quarantaine, backoff) et sécurité (frontière de confiance, jamais de re-enrôlement). `opus` se réserverait à des stories plus simples comme l'UI Livewire 24.7.

## Change Log

- 2026-06-12 — Story 24.5 REVIEWÉE (cycle complet 1 session) : review adversariale opus (5 findings : 1🟠 StateHasher divergence liste/objet sur clés numériques séquentielles ≥ 11, 4🟡 ; verdict approuvé avec corrections, 8/8 AC) → évaluation directe des findings par l'orchestrateur fable. Correctif #1 ÉLARGI au-delà de la proposition opus : la contre-vérification VM a montré que la sémantique PHP dépend de l'ORDRE D'INSERTION des clés (`array_is_list` au json_decode) — fix ensembliste proposé par opus lui-même infidèle au contre-cas hors-ordre. Implémenté : `OrderedMap` + `DecodeJSONOrdered` (ordre du document préservé) + sémantique PHP en 2 temps dans `appendCanonicalObject` (liste à l'insertion / ksort puis re-test / objet), `Canonicalize` refuse les map natives ; +4 tests croisés (hashes PHP réels VM : liste `8854cf4d…`, objet `c99fe485…`). #2 documenté (grands entiers hors-contrat §4.1), #3/#4/#5 pertinence 1 (http iso-legacy, TLS lab = T11, logger ModTime iso-PS). Gates post-corrections : go vet linux+windows ✅, go test 77 PASS (73 → 77) ✅, cross-compile ✅, zéro PHP touché. Doc review codeReviews/24-5.md to-validate.
- 2026-06-12 — Story 24.5 DÉVELOPPÉE (DEV claude-fable-5, en deux sessions — la première interrompue par la limite de session, reprise et terminée le jour même) : module Go `sambaedu/agent` complet (T1-T10) — StateHasher Go iso-23.1 prouvé contre les golden files en place (FROZEN_STATE_HASH + hashes d'items + 8 cas croisés contre le PHP réel), client HTTP rotation D5/grâce/deux-acteurs, boucle quarantaine/backoff/jitter, service SYSTEM natif x/sys/windows/svc (install/uninstall/run/version), build statique signé Authenticode (osslsigncode, vérification intégrée ; pipeline prouvé avec cert de test jetable — émission du PFX CA réelle = action Henri), retrait des 4 .ps1 de 24.2 (artefacts 24.3/24.4 intacts), docs (README gate Go résolu, agent-skeleton, QA Section 5 append-only). Gates : go vet + 73 tests Go PASS (hôte), cross-compile windows/amd64 vert, /vm --filter Agent 206 passed (839 assertions) avec zéro PHP modifié. Correctif de reprise : build.sh re-exécutable (rm de la sortie avant osslsigncode). Reste : T11 install lab ws 49 (action humaine, après re-build signé CA réelle). Status ready-for-dev → review.
- 2026-06-12 — Story 24.5 créée (SM/create-story, suite au sprint-change-proposal-2026-06-12 validé par Henri) : réécriture Go du core agent (boucle, StateHasher iso-23.1 + tests croisés golden files, cache ACL SYSTEM, rotation D5, backoff, quarantaine), service SYSTEM natif (`x/sys/windows/svc`, install/uninstall intégrés au binaire), build statique cross-compilé signé Authenticode (osslsigncode, CA interne), retrait des `.ps1` de 24.2 (ceux de 24.3/24.4 + ContractV1.ps1 → 24.6), gate techno résolu dans le README. Aucun code PHP serveur modifié (baseline 206 passed à préserver). Status backlog → ready-for-dev.

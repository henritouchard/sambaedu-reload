# Story 27.1bis : Rendu overlay verrouillé (Rainmeter)

Status: review

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

En tant qu'**admin d'établissement**,
je veux **que l'overlay d'information (identité, parc, alertes) soit RENDU à l'écran au logon, dans une fenêtre que l'élève ne peut ni déplacer, ni masquer, ni fermer durablement**,
afin que **le bureau converge ET affiche l'information de poste de façon fiable et infalsifiable — l'agent gère le cycle de vie de l'outil de rendu (Rainmeter), pas seulement la donnée**.

## Contexte & intention

**Accélérateur de démo rattaché à 27.1.** Cette story N'EST PAS du pattern « 1 ressource config » d'Epic 27
(« 1 StateProvider + 1 handler Go + identifiant figé + golden »). C'est le **cycle de vie d'un outil de rendu
tiers (Rainmeter) + durcissement poste**. Le handler de **données** overlay existe déjà (Story 24.6 écrit
`overlay.json` per-user, en parité byte-à-byte avec le POC) ; cette story ajoute le **RENDU verrouillé** :
poser l'outil, l'épingler, le verrouiller, le maintenir vivant. À l'issue de 27.1 + 27.1bis, **le bureau
converge ET l'overlay verrouillé s'affiche au logon**.

**Trois volets — le cœur de la story :**

1. **PROVISIONING — héberger Rainmeter portable + le poser au bootstrap par l'agent.** Rainmeter (open-source
   GPLv2) est extrait en **mode portable** dans un dossier ACL (zéro registre, zéro install MSI/NSIS). L'artefact
   portable est hébergé sur le **canal release SE5** (cohérent Epic 25 releases/rings) et **posé par l'agent au
   bootstrap** (install-if-absent, vérifié par hash, comme `SyncWallpaperAssets`). **JAMAIS d'install par le
   handler runtime** (contrainte forte projet : « handler jamais installeur », cf. mémoire
   `project_rainmeter_provisioning_direction`).

2. **SERVICE SYSTEM écrit `overlay.json` au logon, ACL read-only pour l'user.** Le service SYSTEM s'abonne aux
   **notifications de session Windows** (`golang.org/x/sys/windows/svc`, `Accepts: AcceptSessionChange`, EventType
   `WTS_SESSION_LOGON` 0x5), résout l'utilisateur de la session (`WTSQuerySessionInformation` — **RÉUTILISE** le
   WTS + `LookupSID` de 24.6), compose et écrit `overlay.json` per-user avec une **ACL read-only pour
   l'utilisateur** (SYSTEM en est propriétaire → l'élève ne peut pas falsifier la donnée affichée). Écriture
   **événementielle au logon**, pas de polling fichier. C'est le **durcissement de la donnée** (aujourd'hui
   `overlay.json` est écrit par le compagnon *en droits user* → falsifiable ; cette story le bascule sous
   SYSTEM, infalsifiable — NFR5).

3. **VERROUILLAGE du rendu.** Poser la skin `.ini` en **UTF-16 LE + BOM** au bon hash (source repo UTF-8,
   conversion à la pose — sinon mojibake `Â·`), appliquer `TrayIcon=0` (Rainmeter.ini), `Draggable=0` /
   `ClickThrough=1` / `KeepOnScreen=1` + `!Move` (skin), poser config + `Rainmeter.ini` sous
   `C:\ProgramData\SambaEdu\Rainmeter\` en **ACL Users=read-only**, SYSTEM/Admins=full (le mode portable pointe
   dessus), et un **watchdog** qui relance `Rainmeter.exe` s'il disparaît. **PAS d'obfuscation de process** : un
   élève non-admin voit/tue son `Rainmeter.exe` → c'est le watchdog qui répond, pas le masquage.

**Ce que cette story livre :**
- **Agent Go** : provisioning portable Rainmeter au bootstrap (download vérifié hash + extraction ACL) ; pose de
  la skin `.ini` (conversion UTF-16 LE + BOM) et de `Rainmeter.ini` durci sous ProgramData ACL ; **écriture de
  `overlay.json` au logon par le SERVICE SYSTEM** (abonnement session-change WTS_SESSION_LOGON) avec ACL
  read-only user ; watchdog Rainmeter.
- **Serveur** : hébergement de l'artefact portable Rainmeter sur le canal release (extension du pattern 25.1 à un
  artefact NON-binaire-agent — décision D8) ; aucun nouveau StateProvider de **config** n'est requis (l'overlay
  `OverlayStateProvider` 24.6 fournit déjà la donnée ; la skin/Rainmeter.ini sont des **assets** posés par
  l'agent, pas un item d'état).
- **Skin de démo** : déjà en place (`resources/overlay/rainmeter/SambaEduOverlay/SambaEduOverlay.ini`, déployée
  sur `\\se4fs\progs\SambaEduOverlay\`) — à enrichir des options de verrouillage.

**Ce que cette story N'EST PAS :**
- Une refonte du **payload** overlay ni de `OverlayStateProvider` / `OverlayService` (24.4/24.6) — la donnée et
  son sérialiseur byte-compatible sont **réutilisés tels quels** ; seul l'**acteur** (SYSTEM au lieu du
  compagnon) et l'**ACL** changent (voir Q1).
- Un nouvel identifiant de type figé contrat §7 (pas d'item d'état nouveau ; pas de bump golden requis — voir Q2).
- L'install de Rainmeter par MSI/NSIS/winget (mode portable assumé, zéro registre).
- L'obfuscation/masquage du process Rainmeter (explicitement écarté).
- Le décommissionnement legacy (27.6).

## ⚠️ Pièges & tensions découverts à l'analyse (lire avant de coder)

1. **`overlay.json` est AUJOURD'HUI écrit par le COMPAGNON en droits user, pas par SYSTEM.** Le code 24.6 écrit
   `%LOCALAPPDATA%\SambaEdu\Agent\overlay.json` via `shared.OverlayHandler` (handler de données), enregistré dans
   la map du **compagnon** (`agent/windows/companion_windows.go`), qui tourne aux **droits de la session**
   (NFR5 : il ne peut pas toucher l'agent, mais l'élève PEUT éditer son `overlay.json` puisqu'il est dans son
   profil). Le cadrage de cette story **déplace l'écriture vers le SERVICE SYSTEM** (au logon, ACL read-only
   user) pour rendre la donnée infalsifiable. **C'est un changement d'acteur structurant** : il faut soit (a)
   garder le handler compagnon ET ajouter une écriture SYSTEM (redondance/conflit de propriétaire), soit (b)
   **retirer overlay de la map du compagnon** et faire écrire le SYSTEM seul. **Décision D1 : option (b)** —
   l'overlay quitte le compagnon, le SYSTEM est seul propriétaire. La composition (`ComposeOverlayDocument`,
   `agent/shared/overlay_compose.go`) reste **réutilisée à l'identique** (logique pure, OS-agnostique).

2. **Le chemin de `overlay.json` change-t-il ?** Sous SYSTEM, écrire dans `%LOCALAPPDATA%` de l'utilisateur exige
   de résoudre le profil de la session (faisable : SID + dossier profil). La skin lit aujourd'hui
   `%LOCALAPPDATA%/SambaEdu/Agent/overlay.json` (variable `JsonPath`). **Décision D2 : conserver le chemin
   per-user `%LOCALAPPDATA%\SambaEdu\Agent\overlay.json`** (la skin n'a PAS à changer de `JsonPath`), mais le
   fichier est désormais **possédé par SYSTEM** avec ACL `<SID>:R` (l'user lit, ne modifie pas). Le service
   résout le `%LOCALAPPDATA%` de la session via le token de session (`WTSQueryUserToken` + `SHGetFolderPath`/
   `KNOWNFOLDERID` FOLDERID_LocalAppData, ou substitution depuis le profil). **À challenger en review** : si la
   résolution du profil sous SYSTEM s'avère fragile, fallback documenté = `%ProgramData%\SambaEdu\overlay.json`
   commun (chemin POC d'origine) + `JsonPath` de la skin re-pointé — mais on PERD le per-user multi-session.

3. **L'abonnement session-change est ABSENT du service actuel.** `agent/windows/service_windows.go::Execute`
   déclare `Accepts: svc.AcceptStop | svc.AcceptShutdown` **seulement** — pas de `svc.AcceptSessionChange`, pas
   de case `svc.SessionChange` dans le `switch req.Cmd`. Il faut **étendre `Accepts`** et **gérer `SessionChange`**
   (EventType `WTS_SESSION_LOGON=0x5`, le `req.EventType` porte le code, `req.Context` le `SessionID`). C'est
   l'ajout structurant côté service. Ne PAS casser la boucle existante (`agent.Run(ctx)` en goroutine, sortie
   propre sur Stop/Shutdown) : le case `SessionChange` déclenche une composition+écriture overlay pour la session
   concernée, sans perturber le cycle de fetch/report.

4. **Rainmeter portable n'est PAS un binaire agent → le canal release 25.1 le refuse aujourd'hui.** Le download
   (`ReleaseController::download`) impose un pattern de filename **strict figé** `^sambaedu-agent-[…]\.exe$` et un
   lookup `agent_releases` (mono-artefact). Héberger un portable Rainmeter (`.zip` au nom différent) exige
   **d'élargir le canal**. **Décision D8 : route/asset dédié pour les artefacts de rendu** (iso `AssetController`),
   PAS de pollution de `agent_releases` (qui pilote l'auto-update agent, 25.2). Convention storage
   `storage/agent/tools/` (non versionné, chown www-admin), intégrité SHA-256 à la création, servi authentifié
   agent. **Le rings/versioning d'Epic 25 N'EST PAS réutilisé** pour Rainmeter (mono-version suffit en démo) —
   à confirmer Q3.

5. **UTF-16 LE + BOM = piège mojibake connu.** La source repo `SambaEduOverlay.ini` est en **UTF-8** ; Rainmeter
   attend de l'**UTF-16 LE** pour les caractères non-ASCII (sinon `Â·`, cf. mémoire
   `project_overlay_rainmeter_lockdown_direction`). La conversion se fait **à la pose** par l'agent
   (`golang.org/x/text/encoding/unicode` — déjà dépendance `x/text v0.38.0`, utilisé par `norm.NFC` dans
   `handler_overlay.go`). Poser un BOM `FF FE` en tête. **Hash figé** : le dev calcule le hash de la sortie
   UTF-16 attendue et le vérifie (idempotence : ne réécrire que si divergence — pattern `test`/`apply`).

6. **`Draggable` / `ClickThrough` / `KeepOnScreen` vivent dans `Rainmeter.ini` (settings d'instance), `TrayIcon`
   aussi ; `!Move` et `OnRefreshAction` vivent dans la skin.** Distinguer les deux fichiers : (a) la **skin**
   `SambaEduOverlay.ini` porte `OnRefreshAction=[!Move …]` (déjà présent) + éventuellement des bangs de
   verrouillage ; (b) le **`Rainmeter.ini`** global (settings) porte `TrayIcon=0`, et la section
   `[SambaEduOverlay\…]` porte `Draggable=0`, `ClickThrough=1`, `KeepOnScreen=1`, `Position` épinglée. C'est le
   `Rainmeter.ini` (sous ProgramData ACL) qui verrouille réellement — la skin seule ne suffit pas.

7. **ProgramData ACL Users=read-only, SYSTEM/Admins=full — pattern déjà outillé.** `setAssetsACL`
   (`agent/windows/acl_windows.go`) pose déjà `*S-1-5-32-545:(OI)(CI)R` (Users:R) + SYSTEM/Admins full via
   `icacls`. **Réutiliser** ce pattern pour `C:\ProgramData\SambaEdu\Rainmeter\`. La pose ACL read-only user sur
   `overlay.json` réutilise `setSessionCacheACL` (`<SID>:(OI)(CI)R`) — déjà écrit.

8. **Le watchdog doit cohabiter avec le polling existant sans le réimplémenter.** Le compagnon est une boucle
   résidente (mtime cache ~60 s + re-test ~5 min) ; le watchdog Rainmeter (relancer `Rainmeter.exe` s'il
   disparaît) est un **comportement supplémentaire**. **Décision D5 : le watchdog vit côté SERVICE SYSTEM**
   (le service est résident, survit au logoff, et c'est lui qui possède la config ProgramData) — re-lancer
   Rainmeter dans la session interactive depuis SYSTEM exige `CreateProcessAsUser`/token de session (non trivial).
   **Challenger en review** : alternative = watchdog côté compagnon (droits user, relance triviale `os/exec`)
   mais le compagnon meurt au logoff (acceptable : pas de session = rien à rendre). **Option compagnon retenue
   par défaut** (D5) pour la simplicité ; le service garde l'écriture overlay + ACL.

9. **Rainmeter absent reste GRACIEUX (acquis 24.4/24.6).** Si le provisioning n'a pas encore posé Rainmeter (ou
   échoue), l'écriture de `overlay.json` ne doit JAMAIS rapporter `error` du seul fait de l'absence de Rainmeter
   (amendement Henri 2026-06-12, testé en 24.6). Conserver cet invariant.

10. **`inotify` ne propage pas les deletes — sans objet ici** (aucun fichier Laravel supprimé ; l'artefact
    portable Rainmeter est posé sur la VM hors git/inotify, convention storage). Si une route/migration est
    ajoutée : `route:cache` + chown www-admin sur /vm ; pas de `config/*.php` nouveau attendu (sauf clé
    `tools_path` dans `config/agent.php` → `config:cache` /vm, mémoire `project_vm_config_cache_not_synced`).

11. **NFR7 (critère Keycloak) : zéro dépendance AD dans les composants agent.** Aucun `ldap`/`apcu`/`LdapRecord`/
    `ad_*` dans le code Go ni dans un éventuel provider/asset serveur. La résolution de l'user de session se fait
    par WTS/LSA (déjà 24.6), jamais par l'AD. Grep en review.

12. **`agent/` jamais mélangé à `app/`.** Le code Go reste sous `agent/` (`shared/` portable + `windows/`
    spécifique) ; le serveur (route/asset Rainmeter) sous `app/`. Anti-couteau-suisse NFR9 : `agent/` ne fait que
    convergence/rapport — le provisioning d'un outil de rendu reste « pose d'un asset vérifié + maintien », pas
    de l'inventaire/remote-control.

## Décisions de design — TRANCHÉES (cadrage validé avec Henri 2026-06-15)

> Le périmètre (3 volets) et les contraintes fortes ont été cadrés avec Henri. Les décisions ci-dessous en
> découlent ; les forks restants sont en Questions ouvertes (Q1..Q4).

1. **D1 — L'overlay quitte la map du compagnon ; le SERVICE SYSTEM est seul propriétaire de `overlay.json`.**
   L'écriture est événementielle au logon (session-change). `ComposeOverlayDocument` (logique pure) est réutilisé
   à l'identique. Motif : infalsifiabilité de la donnée affichée (NFR5).
2. **D2 — Chemin conservé `%LOCALAPPDATA%\SambaEdu\Agent\overlay.json`** (la skin ne change pas de `JsonPath`),
   mais fichier possédé SYSTEM + ACL `<SID>:R`. Résolution du profil de session sous SYSTEM via le token de
   session. Fallback documenté `%ProgramData%\SambaEdu\overlay.json` si la résolution s'avère fragile (Q1).
3. **D3 — Provisioning Rainmeter PORTABLE par l'agent au bootstrap, JAMAIS par le handler runtime.** Extraction
   dans un dossier ACL, zéro registre. Download vérifié SHA-256 (pattern `SyncWallpaperAssets`/`assets.go`).
   Install-if-absent idempotent.
4. **D4 — Verrouillage = `Rainmeter.ini` durci sous ProgramData ACL + skin avec `!Move`.** `TrayIcon=0`
   (Rainmeter.ini), `Draggable=0`/`ClickThrough=1`/`KeepOnScreen=1` (section skin du Rainmeter.ini),
   `OnRefreshAction=[!Move …]` (skin, déjà présent). Config read-only Users, SYSTEM/Admins full (`setAssetsACL`).
5. **D5 — Watchdog côté COMPAGNON (droits user, relance `os/exec` triviale)** ; meurt au logoff (acceptable).
   Le SERVICE garde l'écriture overlay + ACL + provisioning. À challenger en review (alternative watchdog SYSTEM
   avec `CreateProcessAsUser`).
6. **D6 — Skin posée en UTF-16 LE + BOM par l'agent (conversion depuis la source UTF-8 du repo).**
   `golang.org/x/text/encoding/unicode` (UTF-16 LE + UseBOM). Idempotence par comparaison de hash de la sortie.
7. **D7 — PAS d'obfuscation de process.** Un élève non-admin voit/tue son `Rainmeter.exe` → le watchdog le
   relance. Pas de masquage.
8. **D8 — Artefact portable Rainmeter servi par une route/asset DÉDIÉE** (iso `AssetController`), convention
   storage `storage/agent/tools/`, intégrité SHA-256, authentifié agent. **PAS dans `agent_releases`** (réservé
   au binaire agent + auto-update 25.2). Rings/versioning Epic 25 NON réutilisés pour Rainmeter (Q3).

## Acceptance Criteria

### AC1 — Rainmeter portable hébergé sur le canal SE5 + posé par l'agent au bootstrap (FR24-adjacent, NFR5)

**Given** un artefact Rainmeter **portable** (extraction sans registre) déposé sur la VM sous `storage/agent/tools/`
(non versionné, chown www-admin), intègre par SHA-256
**When** l'agent démarre (bootstrap) et Rainmeter est absent du dossier cible
**Then** l'agent télécharge l'artefact via une route **dédiée** authentifiée agent (D8 — iso `AssetController`,
PAS `agent_releases`), **vérifie le hash avant extraction**, et l'extrait en mode portable dans un dossier ACL
(SYSTEM/Admins full, Users read-only)
**And** l'opération est **idempotente** (Rainmeter déjà posé et conforme → no-op) ; **jamais d'install MSI/NSIS/
winget**, zéro écriture registre ; le **handler runtime n'installe jamais** Rainmeter (contrainte « handler
jamais installeur »).

### AC2 — Le SERVICE SYSTEM écrit overlay.json au logon, ACL read-only pour l'user (NFR5, NFR7)

**Given** le service Windows SYSTEM résident
**When** une session ouvre (`WTS_SESSION_LOGON`, EventType 0x5 reçu via `svc.AcceptSessionChange`)
**Then** le service résout l'utilisateur de la session (`WTSQuerySessionInformation` + `LookupSID` — RÉUTILISE
le code 24.6, jamais l'AD : NFR7), compose le document (`ComposeOverlayDocument` réutilisé à l'identique) et
**écrit `overlay.json`** au chemin per-user (D2) en **possédant le fichier (SYSTEM) avec ACL `<SID>:R`** — l'élève
peut lire, **jamais falsifier** (NFR5)
**And** l'écriture est **événementielle** (au logon), PAS un polling fichier ; l'overlay quitte la map du
compagnon (D1) ; l'écriture est atomique (`WriteFileAtomic` réutilisé)
**And** Rainmeter absent reste **gracieux** : l'écriture réussit, aucun `error` rapporté du seul fait de l'absence
de Rainmeter (invariant 24.4/24.6 conservé).

### AC3 — Skin posée en UTF-16 LE + BOM, sans mojibake (D6)

**Given** la source `resources/overlay/rainmeter/SambaEduOverlay/SambaEduOverlay.ini` en UTF-8 (repo)
**When** l'agent pose la skin sur le poste
**Then** elle est convertie en **UTF-16 LE avec BOM** (`FF FE`) — les caractères non-ASCII s'affichent
correctement (pas de `Â·`)
**And** la pose est **idempotente** : la skin n'est réécrite que si elle diverge du hash attendu (pattern
test/apply) ; le `JsonPath` pointe le chemin per-user de D2.

### AC4 — Rendu verrouillé : non déplaçable, non masquable, épinglé (D4, D7)

**Given** Rainmeter portable posé et la config sous `C:\ProgramData\SambaEdu\Rainmeter\` en ACL **Users=read-only**,
SYSTEM/Admins=full (`setAssetsACL` réutilisé)
**When** Rainmeter rend la skin
**Then** `Rainmeter.ini` applique `TrayIcon=0` (pas d'icône de tray pilotable), et la section overlay applique
`Draggable=0`, `ClickThrough=1`, `KeepOnScreen=1`, position épinglée ; la skin applique `!Move` au refresh
**And** l'élève non-admin **ne peut pas** déplacer, masquer ni reconfigurer durablement l'overlay (config
read-only) ; **aucune obfuscation de process** (D7 — il peut voir/tuer le process, le watchdog répond).

### AC5 — Watchdog : Rainmeter relancé s'il disparaît (D5)

**Given** Rainmeter posé et lancé
**When** le process `Rainmeter.exe` disparaît (élève qui le tue, crash)
**Then** le **watchdog** (côté compagnon, droits user — D5) le **relance** dans la session, pointant la config
verrouillée ProgramData
**And** le watchdog est **idempotent** (Rainmeter déjà vivant → no-op) et **borné** (pas de boucle de relance
serrée : back-off/cadence raisonnable) ; il meurt proprement au logoff (pas de session = rien à rendre).

### AC6 — Tests : go test agent + serveur, baselines intactes (NFR13)

**Then** côté **agent Go** : tests `go test` sur la logique pure (conversion UTF-16 LE + BOM idempotente ;
décision provisioning install-if-absent ; composition overlay réutilisée inchangée ; watchdog table-driven
present/absent/relance-bornée) ; `go test ./...`, `go vet` (linux + `GOOS=windows`), cross-compile verts sur l'hôte ;
le spécifique Windows (session-change, ACL, CreateProcess) validé cross-compile + lab humain
**And** côté **Laravel** : test de la route/asset Rainmeter (intégrité hash, 404 indistinct hors pattern,
authentifié agent — iso tests `AssetController`/`ReleaseController`) ; non-régression `--filter Agent` sur /vm
(baseline à relever au début du dev)
**And** **aucun bump de golden** attendu (pas de nouvel item d'état ; `overlay.json` byte-identique au format
24.6 — le golden `agent/shared/testdata/overlay.golden.json` reste vert, à vérifier explicitement) ; si la
composition diverge d'un octet, c'est un bug.

### AC7 — Documentation + QA (append-only)

**Then** `resources/overlay/README.md` : section « Rendu verrouillé (27.1bis) » (provisioning portable, pose skin
UTF-16 LE + BOM, Rainmeter.ini durci, ACL ProgramData/overlay.json, watchdog, écriture SYSTEM au logon) ;
`agent/README.md` : volet rendu verrouillé + commande/bootstrap Rainmeter ; `docs/agent/state-providers.md` ou
`docs/agent/session-companion.md` : note sur le déplacement de l'écriture overlay vers SYSTEM (D1) et son ACL
**And** `docs/qa/domains/agent.md` enrichi **append-only** (nouvelle section, sans renuméroter) : provisioning
Rainmeter, overlay verrouillé (non déplaçable/masquable/épinglé), watchdog (kill → relance), UTF-16 LE sans
mojibake, ACL read-only ; ligne 27.1bis dans `docs/qa/README.md`
**And** restent **INTOUCHÉS** : `OverlayService`/`OverlayController`/`OverlayStateProvider` (payload/sérialiseur
réutilisés), `ComposeOverlayDocument` (réutilisé à l'identique), tout le canal legacy.

## Tasks / Subtasks

- [x] **T0 — Baseline & repérage** (AC6)
  - [x] Relever la baseline `--filter Agent` sur /vm (nb tests passants) AVANT toute modif.
  - [x] `go test ./...` + `go vet` (linux + `GOOS=windows`) + cross-compile verts sur l'hôte (état initial).
  - [x] Confirmer que `agent/shared/testdata/overlay.golden.json` est vert (référence à ne PAS casser).

- [x] **T1 — Serveur : route/asset dédiée pour l'artefact Rainmeter portable** (AC1) — *décision D8*
  - [x] Route/contrôleur dédié (iso `app/Http/Controllers/Api/V1/Agent/AssetController.php` /
        `ReleaseController.php`) servant l'artefact depuis `storage/agent/tools/` : pattern de filename strict
        anti-traversal, lookup intégrité (SHA-256 connu côté serveur), 404 indistinct, **authentifié agent**
        (chaîne middleware iso `['auth.v1.secure-headers','throttle','agent.token']`). **PAS** dans
        `agent_releases` (réservé binaire agent / 25.2).
  - [x] Clé `tools_path` dans `config/agent.php` (défaut `storage_path('agent/tools')`) — `config:cache` /vm.
  - [x] Routes API insérées APRÈS le groupe 16.12 (mémoire `project_api_routes_arch_test_window_trap`).
  - [x] Déposer l'artefact portable Rainmeter sur la VM (`storage/agent/tools/`, chown www-admin) — action /vm.

- [x] **T2 — Agent : provisioning portable Rainmeter au bootstrap** (AC1) — *décision D3*
  - [x] Fonction Go (calquée sur `agent/shared/assets.go::SyncWallpaperAssets`) : download authentifié →
        **vérif SHA-256 avant extraction** → extraction portable dans dossier ACL → idempotent (no-op si
        conforme). Logique pure testable hôte ; I/O réseau/FS Windows injectés.
  - [x] Dossier cible Rainmeter portable sous ACL SYSTEM/Admins full, Users read-only (`setAssetsACL` réutilisé,
        `agent/windows/acl_windows.go`). Zéro registre, zéro MSI/NSIS/winget.
  - [x] Câbler le provisioning au **bootstrap** de l'agent (cycle SYSTEM `shared/loop.go`/`RunCycle`, pas un
        handler runtime — contrainte « handler jamais installeur »).

- [x] **T3 — Agent : pose de la skin (UTF-16 LE + BOM) + Rainmeter.ini durci** (AC3, AC4) — *décisions D4, D6*
  - [x] Conversion UTF-8 → **UTF-16 LE + BOM** (`golang.org/x/text/encoding/unicode`, `UseBOM`) de la skin source ;
        pose atomique idempotente (hash de la sortie attendue), `JsonPath` pointant le chemin per-user D2.
  - [x] Générer/poser `Rainmeter.ini` durci sous `C:\ProgramData\SambaEdu\Rainmeter\` : `TrayIcon=0`, section
        `[SambaEduOverlay\…]` `Draggable=0`/`ClickThrough=1`/`KeepOnScreen=1`/position épinglée. Skin
        `OnRefreshAction=[!Move …]` (déjà présent — vérifier/conserver).
  - [x] ACL Users=read-only, SYSTEM/Admins=full sur `C:\ProgramData\SambaEdu\Rainmeter\` (`setAssetsACL`).

- [x] **T4 — Agent : écriture overlay.json par le SERVICE SYSTEM au logon + ACL read-only user** (AC2) — *décisions D1, D2*
  - [x] `agent/windows/service_windows.go` : étendre `Accepts` à `svc.AcceptSessionChange` ; gérer
        `case svc.SessionChange` avec `req.EventType == windows.WTS_SESSION_LOGON (0x5)` et `req.Context`
        (SessionID) — sans casser la boucle `agent.Run(ctx)` / Stop / Shutdown existante.
  - [x] Au logon : résoudre l'user de la session (RÉUTILISER `agent/windows/sessions_windows.go` :
        `WTSQuerySessionInformationW`, `LookupSID`) ; résoudre `%LOCALAPPDATA%` de la session sous SYSTEM
        (token de session — `WTSQueryUserToken` + KNOWNFOLDERID FOLDERID_LocalAppData, ou substitution profil).
  - [x] Composer (`shared.ComposeOverlayDocument` réutilisé à l'identique) et écrire `overlay.json`
        (`WriteFileAtomic`), puis poser l'ACL **`<SID>:R`** (SYSTEM propriétaire — `setSessionCacheACL` réutilisé).
  - [x] **Retirer overlay de la map du COMPAGNON** (`agent/windows/companion_windows.go`) — D1. Vérifier la
        non-régression wallpaper/shortcuts/printers/drives (toujours dans la map compagnon).
  - [x] Conserver l'invariant **Rainmeter absent = gracieux** (jamais `error`).

- [x] **T5 — Agent : watchdog Rainmeter (compagnon)** (AC5) — *décision D5*
  - [x] Côté compagnon (droits user) : surveiller `Rainmeter.exe`, le **relancer** s'il disparaît (pointant la
        config verrouillée ProgramData), idempotent + **borné** (back-off/cadence), arrêt propre au logoff.
        Logique de décision pure testable hôte ; lancement OS injecté.
  - [x] Intégrer à la boucle résidente du compagnon sans réimplémenter le polling cache existant
        (`agent/shared/companion.go`).

- [x] **T6 — Skin : options de verrouillage** (AC4)
  - [x] Enrichir `resources/overlay/rainmeter/SambaEduOverlay/SambaEduOverlay.ini` (source repo, UTF-8) des
        bangs/options de verrouillage côté skin si nécessaire (le verrouillage dur vit dans `Rainmeter.ini`, T3) ;
        conserver `OnRefreshAction=[!Move …]` et le contrat render (regex WebParser, `JsonPath`).

- [x] **T7 — Tests** (AC6)
  - [x] Go : conversion UTF-16 LE+BOM (idempotence/round-trip), provisioning install-if-absent (no-op si conforme,
        échec hash → pas d'extraction), watchdog (present/absent/relance-bornée), composition overlay inchangée
        (golden vert). `go test ./...`, `go vet` (linux+windows), cross-compile.
  - [x] Laravel : route/asset Rainmeter (intégrité hash, 404 indistinct hors pattern, authentifié agent).
        Non-régression `--filter Agent` /vm.

- [x] **T8 — Documentation + QA** (AC7)
  - [x] `resources/overlay/README.md` (rendu verrouillé), `agent/README.md` (provisioning + lockdown),
        `docs/agent/session-companion.md` ou `state-providers.md` (déplacement écriture overlay → SYSTEM, ACL),
        QA append-only `docs/qa/domains/agent.md` + ligne 27.1bis `docs/qa/README.md`.

- [x] **T9 — Validation finale** (AC6)
  - [x] `php -l` sur les fichiers PHP ; grep critère Keycloak (`ldap|apcu|LdapRecord|ad_`) sur le nouveau code
        agent + serveur → vide (NFR7) ; grep « zéro retrofit legacy » (aucun fichier legacy au diff).
  - [x] `go test ./...` + `go vet` + cross-compile verts sur l'hôte ; golden overlay vert ; `--filter Agent` /vm
        sans régression.
  - [x] **Validation lab (poste Windows) : ACTION HUMAINE (Henri)** — Rainmeter posé en portable, overlay rendu
        au logon, non déplaçable/masquable, `TrayIcon` absent, config inaltérable par l'élève, kill
        `Rainmeter.exe` → relancé par le watchdog, caractères accentués corrects (UTF-16, pas de `Â·`),
        `overlay.json` non éditable par l'user (ACL `<SID>:R`).

## Dev Notes

### Périmètre — livré / hors-scope

| Livré (27.1bis) | Hors-scope (story) |
|---|---|
| Provisioning Rainmeter **portable** au bootstrap (download vérifié hash + extraction ACL) | Install MSI/NSIS/winget de Rainmeter (mode portable assumé) |
| Écriture `overlay.json` par le SERVICE SYSTEM au logon (session-change) + ACL `<SID>:R` | Refonte du payload/sérialiseur overlay (réutilisés 24.4/24.6) |
| Pose skin UTF-16 LE + BOM + `Rainmeter.ini` durci sous ProgramData ACL | Nouvel identifiant de type figé / bump golden (pas d'item d'état nouveau) |
| Watchdog Rainmeter (relance si tué) | Obfuscation/masquage du process Rainmeter (écarté D7) |
| Route/asset dédiée canal release pour l'artefact Rainmeter | Rings/versioning Epic 25 appliqués à Rainmeter (Q3) |
| Tests go + serveur + QA | Décommissionnement legacy (27.6) |

### Le socle 24.6 — ce qu'on réutilise (ne PAS réinventer)

[Source: agent/shared/overlay_compose.go ; agent/shared/handler_overlay.go ; agent/windows/sessions_windows.go ; agent/windows/companion_windows.go]

- `ComposeOverlayDocument(items []StateItem, computerName string) string` (`agent/shared/overlay_compose.go`) :
  sérialiseur à **structure littérale fixe** (`": "`, ordre de clés stable, UTF-8 brut, **aucun champ volatil**),
  byte-compatible avec le PS 24.4. `OverlaySchema = "se5.wallpaper-overlay/v1"`. `sanitizeOverlayText`,
  `escapeOverlayJSONString`, bornes 16/255/2000. **Réutilisé à l'identique** — l'octet près (golden vert).
- `shared.OverlayHandler` (`handler_overlay.go`) : aujourd'hui dans la map **compagnon** ; D1 le retire de là.
  `Test` compare après NFC (`golang.org/x/text/unicode/norm`), `Apply` = `WriteFileAtomic`. Rainmeter absent =
  gracieux (jamais `error`).
- `agent/windows/sessions_windows.go` : `wtsQuerySessionString` (`WTSQuerySessionInformationW`),
  `enumerateInteractiveSessions` (WTSEnumerate + `LookupSID` + liste blanche `S-1-5-21-`), `currentProcessSID`
  (`OpenCurrentProcessToken`/`GetTokenUser`). **C'est la résolution user/SID à réutiliser sous SYSTEM.**
- `agent/windows/companion_windows.go` : `rainmeterPresent()` (os.Stat sur `%ProgramFiles%`/`%LOCALAPPDATA%`
  `Rainmeter.exe`) — adapter pour pointer le dossier portable cible. Map des Handlers (retirer overlay, D1).
- `agent/shared/sessionstore.go` : `WriteFileAtomic(path, data)` (tmp+PID+rename, sans ACL — l'ACL est posée
  séparément) ; `UserStore.OverlayPath()` (`%LOCALAPPDATA%\SambaEdu\Agent\overlay.json`) ; `Store` ProgramData.
- `agent/shared/assets.go` (`SyncWallpaperAssets`) : **modèle du provisioning** download vérifié SHA-256 +
  pose idempotente. Le provisioning Rainmeter le calque.

### Le service SYSTEM — ce qu'on étend

[Source: agent/windows/service_windows.go ; agent/windows/install_windows.go ; agent/windows/acl_windows.go]

- `service_windows.go::Execute` : aujourd'hui `Accepts: svc.AcceptStop | svc.AcceptShutdown`. **Ajouter
  `svc.AcceptSessionChange`** et un `case svc.SessionChange` (`req.EventType == 0x5` WTS_SESSION_LOGON,
  `req.Context` = SessionID). Ne PAS perturber la goroutine `agent.Run(ctx)` ni les case Stop/Shutdown/Interrogate.
- `acl_windows.go` : `setAssetsACL` (Users:R + SYSTEM/Admins full, via `icacls`) → ProgramData Rainmeter ;
  `setSessionCacheACL` (`<SID>:(OI)(CI)R`) → `overlay.json` possédé SYSTEM. **Shell-out `icacls` documenté**
  (échappatoire admise, pattern existant).
- `install_windows.go` : `store.EnsureAssetsDir(setAssetsACL)` pattern d'arborescence + ACL à la pose.
- Lancement de Rainmeter dans la session depuis SYSTEM (si watchdog SYSTEM, alternative écartée D5) exigerait
  `CreateProcessAsUser` + token de session — D5 préfère le watchdog **compagnon** (droits user, `os/exec` trivial).

### Le canal release 25.1 — ce qu'on contourne (D8)

[Source: app/Http/Controllers/Api/V1/Agent/ReleaseController.php ; app/Services/Agent/Releases/ ; app/Http/Controllers/Api/V1/Agent/AssetController.php (modèle)]

- `agent_releases` + `ReleaseController` = **mono-artefact** : pattern filename strict `^sambaedu-agent-…\.exe$`,
  lookup DB, réservé au binaire agent + auto-update 25.2. Rainmeter (`.zip`, nom différent) y serait **rejeté**.
- **D8** : route/asset DÉDIÉE iso `AssetController` (qui sert déjà des assets vérifiés authentifiés agent),
  convention storage `storage/agent/tools/`, intégrité SHA-256, 404 indistinct, anti-traversal. URL absolue.
  Pas de pollution de `agent_releases`. Rings non réutilisés (Q3).

### La skin & le format — pièges

[Source: resources/overlay/rainmeter/SambaEduOverlay/SambaEduOverlay.ini ; resources/overlay/README.md ; mémoire project_overlay_rainmeter_lockdown_direction]

- Skin actuelle `0.2-poc` : `[Rainmeter]` `OnRefreshAction=[!Move …]` (épingle haut-droite), `[Variables]`
  `JsonPath=%LOCALAPPDATA%/SambaEdu/Agent/overlay.json`, measures WebParser regex `(?siU)…` (fragile : ordre des
  clés et `": "` un espace doivent être préservés — garanti par `ComposeOverlayDocument`).
- **Aucune option de verrouillage présente** (grep `TrayIcon|Draggable|ClickThrough|KeepOnScreen` négatif sur tout
  le repo) — à ajouter, l'essentiel dans `Rainmeter.ini` (settings d'instance), pas la skin.
- **UTF-16 LE + BOM** : source repo UTF-8 → conversion à la pose (`x/text/encoding/unicode`), sinon mojibake `Â·`.

### Environnement de dev — règles VM

- Code à la RACINE (app/, agent/, …) ; édité sur l'hôte, sync inotify auto, **jamais de sync manuelle**.
- **Go = hôte uniquement** (`~/go-toolchain/go/bin/go`, package main = `agent/windows`). PHPUnit sur /vm.
- Artefact portable Rainmeter déposé manuellement sur la VM (`storage/agent/tools/`, **chown www-admin** — sinon
  `hash_file()` → false → 404 silencieux, mémoire `project_php_fpm_user_www_admin`). Convention storage non
  versionnée (`project_storage_convention_non_versioned`).
- `config:cache` /vm après ajout de la clé `tools_path` ; `route:cache` /vm si route réelle ajoutée (mémoires
  `project_vm_config_cache_not_synced`, `project_route_cache_vm_ephemeral_test_routes`).
- Jamais d'interaction VM depuis un worktree git.

### Dépendances

| Story | Rôle pour 27.1bis | Statut (sprint-status.yaml) | Bloquant ? |
|-------|----------------|------------------------------|------------|
| 27.1 — handler raccourcis | **Prérequis** : réutilise le compagnon/engine, même Epic ; le bureau converge ET l'overlay s'affiche ensemble | `review` | **Prérequis fort** (même Epic, infra compagnon/service partagée) — dev autorisé avec **rebase si correctifs** |
| 24.6 — agent Go compagnon + handlers | Fournit `ComposeOverlayDocument`, `OverlayHandler`, session-fetch WTS/LookupSID, service SCM, ACL, WriteFileAtomic, assets.go | `review` | Non (consommé/réutilisé) ; la T12 lab non jouée n'empêche pas le code |
| 24.4 — handlers wallpaper/overlay (spec POC) | Spec fonctionnelle d'origine de l'écriture overlay.json (proto PowerShell superseded) | `review` | Non (spec) |
| 25.1 — releases serveur, manifest, rings | Modèle du canal release / asset authentifié agent ; D8 s'en inspire SANS y mêler Rainmeter | `done` | **Dépendance souple** — pattern réutilisé (AssetController/ReleaseController), non bloquant |
| 23.4 / 23.1 — StateProvider / contrat / golden | Contrat StateItem / golden overlay (à ne PAS casser) | `done` | Non (consommé) |

27.1bis se positionne **juste après 27.1** dans l'Epic 27 (numérotation `bis`, ne renumérote PAS 27.1–27.6).
`epic-27` est déjà `in-progress` (ouvert à la création de 27.1).

### Project Structure Notes

- Serveur : route/contrôleur asset Rainmeter → `app/Http/Controllers/Api/V1/Agent/` (iso `AssetController.php`) ;
  route API APRÈS le groupe 16.12 ; clé `tools_path` → `config/agent.php`.
- Agent (provisioning + skin + ACL) : `agent/shared/` (logique pure : conversion UTF-16, décision provisioning,
  watchdog) + `agent/windows/` (download/extraction, ACL `icacls`, session-change service, CreateProcess).
- Service : `agent/windows/service_windows.go` (Accepts + case SessionChange).
- Compagnon : `agent/windows/companion_windows.go` (retirer overlay de la map D1, ajouter watchdog) +
  `agent/shared/companion.go` (intégration watchdog).
- Skin : `resources/overlay/rainmeter/SambaEduOverlay/SambaEduOverlay.ini` (source UTF-8).
- Doc : `resources/overlay/README.md`, `agent/README.md`, `docs/agent/session-companion.md`,
  `docs/qa/domains/agent.md`, `docs/qa/README.md`.
- Artefact Rainmeter portable : `storage/agent/tools/` (VM, non versionné, chown www-admin).

### Section contrat / golden

**Aucun bump de golden attendu.** Cette story n'ajoute **pas** d'item d'état (pas de nouveau type figé contrat
§7). `overlay.json` est écrit avec le **même** `ComposeOverlayDocument` 24.6 → byte-identique → golden
`agent/shared/testdata/overlay.golden.json` **reste vert** (à vérifier explicitement en T0/T9). Le contrat StateItem
est inchangé. Si la composition diverge d'un octet, c'est un bug à corriger, pas un bump à acter. Le seul
« contrat » nouveau est la **route asset Rainmeter** (golden serveur optionnel iso `release-manifest.v1.json` si
le dev juge utile ; non requis par l'AC).

### References

- [Source: _bmad-output/planning-artifacts/epics-agent-desired-state.md#FR20] — handler overlay (l'agent devient le fetch du POC, écrit overlay.json local).
- [Source: _bmad-output/planning-artifacts/epics-agent-desired-state.md#NFR5] — frontière de confiance : binaire/config sous ACL SYSTEM, l'élève ne modifie ni l'agent ni son cache ; overlay.json = seul artefact lisible session.
- [Source: _bmad-output/planning-artifacts/epics-agent-desired-state.md#NFR7] — critère Keycloak : zéro dépendance AD du canal agent.
- [Source: _bmad-output/planning-artifacts/epics-agent-desired-state.md#NFR12, NFR13] — identifiants figés ; golden = tests croisés serveur/agent (sans objet ici : pas de nouvel item).
- [Source: _bmad-output/planning-artifacts/epics-agent-desired-state.md#Epic 27] — pattern « 1 StateProvider + 1 handler + id figé + golden » (27.1bis y déroge : cycle de vie d'un outil tiers).
- [Source: _bmad-output/planning-artifacts/epics-agent-desired-state.md#Story 25.1, FR24, D6] — canal release SE5 (binaires signés, manifest, rings = WorkstationGroups, storage/agent/releases/).
- [Source: _bmad-output/planning-artifacts/architecture-agent-desired-state.md#Frontière de confiance] — config sous ACL SYSTEM, overlay.json modèle facade client (lignes 609-614).
- [Source: _bmad-output/implementation-artifacts/24-4-handlers-wallpaper-overlay-convergence-reelle.md] — spec écriture overlay.json (sérialiseur fixe, Rainmeter supposé installé, gracieux si absent, lockdown ABSENT).
- [Source: _bmad-output/implementation-artifacts/24-6-agent-go-compagnon-handlers-parite-demo.md] — agent Go : ComposeOverlayDocument, OverlayHandler, sessions_windows.go (WTS/LookupSID), companion, service SCM, tâches planifiées at-logon.
- [Source: _bmad-output/implementation-artifacts/25-1-releases-serveur-binaires-signes-manifest-rings.md] — canal release mono-artefact (pattern filename strict), AssetController/ReleaseController, convention storage, intégrité SHA-256.
- [Source: agent/shared/overlay_compose.go] — sérialiseur fixe réutilisé à l'identique (golden à ne pas casser).
- [Source: agent/shared/handler_overlay.go ; agent/windows/companion_windows.go] — handler overlay (à retirer de la map compagnon, D1) ; rainmeterPresent().
- [Source: agent/windows/sessions_windows.go] — WTSQuerySessionInformationW, LookupSID, enumerateInteractiveSessions (réutilisés sous SYSTEM).
- [Source: agent/windows/service_windows.go] — Execute : Accepts AcceptStop|AcceptShutdown (à étendre AcceptSessionChange + case SessionChange).
- [Source: agent/windows/acl_windows.go] — setAssetsACL (Users:R), setSessionCacheACL (<SID>:R), runIcacls (réutilisés).
- [Source: agent/shared/assets.go] — SyncWallpaperAssets : modèle provisioning download vérifié hash + idempotent.
- [Source: agent/shared/sessionstore.go] — WriteFileAtomic, UserStore.OverlayPath() (chemin per-user conservé D2).
- [Source: resources/overlay/rainmeter/SambaEduOverlay/SambaEduOverlay.ini] — skin POC (JsonPath, OnRefreshAction !Move, regex WebParser, aucune option lockdown).
- [Source: resources/overlay/README.md] — convention de pose, fetch Windows déprécié (l'agent EST le fetch), install Rainmeter hors POC.
- [Mémoire: project_overlay_rainmeter_lockdown_direction] — UI masquée (TrayIcon=0/Draggable=0/ClickThrough=1) + ACL ProgramData read-only + watchdog ; pas d'obfuscation ; skin UTF-16 LE ; design écriture per-user SYSTEM vs compagnon à trancher.
- [Mémoire: project_rainmeter_provisioning_direction] — install manuelle temporaire (démo 24.4) ; livraison cible = workflow install postes, piste agent en 1re op ; handler jamais installeur.
- [Mémoire: project_zero_prod_publish_is_test ; project_storage_convention_non_versioned ; project_vm_config_cache_not_synced ; project_api_routes_arch_test_window_trap].

## Questions ouvertes — pour décision Henri

> Ces forks ne sont PAS tranchés par le cadrage. À arbitrer avant ou pendant le dev.

- **Q1 — Refresh des alertes *live* en cours de session (QUESTION PRINCIPALE). → TRANCHÉE 2026-06-15 (Henri) :
  Option B — logon-only.** Les alertes (multi-session, quota, prise en main) changent **PENDANT** la session ;
  l'écriture **au logon** (D1, événementielle) ne les rafraîchit pas. Décision : **écriture au logon uniquement**,
  alertes figées pour la durée de la session — pas de timer périodique SYSTEM. Une prise en main / un quota
  dépassé n'apparaît qu'au prochain logon ; suffisant pour la démo, et conforme au modèle événementiel SYSTEM
  (zéro polling). L'option A (re-write périodique) reste un follow-up possible si le besoin terrain émerge, mais
  n'est **pas** implémentée dans cette story. *(Note : le code 24.6 actuel rafraîchit via le polling compagnon
  ~60 s ; D1 retire ce polling au profit de l'événementiel SYSTEM, et Q1=B confirme qu'on ne le remplace pas par
  un timer.)*

- **Q2 — Résolution du `%LOCALAPPDATA%` de la session sous SYSTEM (D2).** Confirmer la mécanique :
  `WTSQueryUserToken(sessionID)` + KNOWNFOLDERID `FOLDERID_LocalAppData` via le token, ou substitution depuis le
  chemin du profil ? Si fragile (sessions AzureAD `S-1-12-1-*` notées en limite 24.3 #6) → fallback chemin commun
  `%ProgramData%\SambaEdu\overlay.json` + `JsonPath` skin re-pointé (perte du per-user multi-session). À confirmer.

- **Q3 — Versioning/rings de l'artefact Rainmeter (D8).** Mono-version simple (un seul portable Rainmeter servi à
  tous) suffit-il en démo, ou faut-il réutiliser le modèle rings d'Epic 25 (version Rainmeter par WorkstationGroup) ?
  Défaut proposé : **mono-version** (simplicité ; Rainmeter ne bouge quasi jamais).

- **Q4 — Watchdog : compagnon (D5, défaut) vs service SYSTEM.** Le watchdog compagnon (droits user, relance
  triviale) meurt au logoff ; le watchdog SYSTEM (résident, survit) exige `CreateProcessAsUser` + token de session
  (plus robuste mais plus complexe). Défaut proposé : **compagnon**. À confirmer / challenger en review.

## Recommandation Modèle Dev

**Recommandation : `opus`.**

Justification : story **système Windows à fort risque, non mécanique**. Elle combine (a) une **ACL Windows**
correcte sur `overlay.json` et la config ProgramData (propriété SYSTEM + `<SID>:R`, falsifiabilité = enjeu
sécurité NFR5) ; (b) un **watchdog** borné et idempotent ; (c) un **abonnement session-change SYSTEM**
(`svc.AcceptSessionChange` + case `SessionChange` + résolution du token/profil de session sous SYSTEM —
`CreateProcessAsUser`/`WTSQueryUserToken`, terrain piégeux) ; (d) un **verrouillage de rendu** réparti entre skin
et `Rainmeter.ini` (mauvais fichier = verrou inopérant) ; (e) une **conversion UTF-16 LE + BOM** avec hash figé
(mojibake = régression visible) ; et (f) un **changement d'acteur structurant** (overlay quitte le compagnon pour
le SYSTEM — D1) à mener sans casser le golden ni la non-régression des autres handlers. Le risque majeur —
infalsifiabilité réelle + cohabitation service/compagnon + interop Windows session — exige le raisonnement le plus
rigoureux. `opus`.

## Dev Agent Record

### Contexte d'exécution

- **Modèle dev** : claude-opus-4-8 (reco respectée). Développé sur l'hôte (`main`, hors worktree).
- **Environnement tests** : Go (`~/go-toolchain/go/bin`, `package main` = `agent/windows`) + PHPUnit hôte (SQLite,
  `vendor/` présent). **Aucune commande VM** (les tests VM + la validation lab sont des actions humaines — Henri).
- **Arbitrages appliqués sans re-trancher** : Q1 = **Option B (logon-only)** — `overlay.json` écrit UNIQUEMENT à
  l'event logon (case `SessionChange` / `WTS_SESSION_LOGON`), **aucun timer périodique SYSTEM**, alertes figées
  pour la session (assumé). Q2/Q3/Q4 = défauts documentés (chemin per-user via token de session + fallback
  `%ProgramData%` ; mono-version Rainmeter ; watchdog compagnon).

### Décisions d'implémentation notables

- **D8 — route /tools séparée** : `ToolController` neuf (iso `ReleaseController`), filename strict
  `^sambaedu-rainmeter-[…]\.zip$` + realpath confiné sous `agent.tools_path`, 404 indistinct, chaîne middleware
  agent. **PAS** de table DB (mono-version, Q3) : l'intégrité SHA-256 est garantie **côté agent** (constante
  `RainmeterToolChecksum` bakée dans le binaire, pattern `SyncWallpaperAssets`), le serveur garantit le confinement.
- **D2/Q2 — résolution du profil de session sous SYSTEM** : via `WTSQueryUserToken(sessionID)` →
  `Token.GetUserProfileDirectory()` → `%LOCALAPPDATA% = <profil>\AppData\Local`. Chemin per-user conservé (la skin
  ne change pas de `JsonPath`). Fallback `%ProgramData%\SambaEdu\overlay.json` + warning si le profil n'est pas
  résoluble (limite assumée : per-user multi-session perdu sur ce chemin).
- **Session-change sans déréférencement du `lpEventData`** : `go vet` (`unsafeptr`) rejette tout `uintptr→Pointer`
  d'un pointeur de callback SCM. Plutôt que de déréférencer `WTSSESSION_NOTIFICATION`, on **ré-énumère** les
  sessions interactives au logon (`interactiveSessionIDs`, WTS vet-clean réutilisé de 24.6) — la nouvelle session
  y apparaît, on écrit `overlay.json` pour chacune (idempotent). **`go vet` reste clean sur les deux plateformes.**
- **D1 — overlay retiré de la map du compagnon** : la composition (`ComposeOverlayDocument`) est réutilisée à
  l'identique côté SYSTEM via `shared.OverlayDocumentForSession` (logique pure : lit le cache per-SID, extrait les
  seuls items `overlay`, compose). **Golden `overlay.golden.json` INCHANGÉ** (vérifié explicitement). Le handler
  `OverlayHandler` reste dans le repo (consommé par les tests existants) mais n'est plus enregistré.
- **Skin embarquée** : `go:embed` ne peut référencer un fichier hors du package → copie
  `agent/shared/embedded/SambaEduOverlay.ini` maintenue **identique** à la skin canonique du repo (vérifié `diff`).
  Conversion **UTF-16 LE + BOM** à la pose (`ToUTF16LEWithBOM`, `golang.org/x/text/encoding/unicode` — déjà au
  go.mod, aucune dépendance ajoutée).
- **Verrouillage** : `BuildHardenedRainmeterIni` génère le `Rainmeter.ini` durci (`TrayIcon=0` + section d'instance
  `Draggable=0`/`ClickThrough=1`/`KeepOnScreen=1`/position épinglée). Posé en UTF-16 LE + BOM sous l'arbre
  ProgramData ACL Users:R (`setAssetsACL` réutilisé). `overlay.json` ACL `<SID>:R` via `setOverlayFileACL` (FICHIER,
  pas de `(OI)(CI)` — acquis T12 ws 49).
- **Watchdog (D5)** : `RainmeterWatchdog` (logique pure, back-off borné ~30 s) + `rainmeterOps` Windows
  (`CreateToolhelp32Snapshot` pour la présence, `os/exec` pour la relance pointant la skin verrouillée). Intégré au
  tour de boucle résident du compagnon (`Companion.Watchdog.Tick()`), nil-safe (inerte hôte/!windows).
- **Provisioning inerte tant que `RainmeterToolChecksum` est vide** : l'artefact portable réel n'étant pas figé,
  la constante est vide → `SyncRainmeterTool` ne télécharge/extrait rien (Rainmeter absent reste gracieux). Le
  déposant de l'artefact sur la VM (`storage/agent/tools/`, chown www-admin) fige le hash en regard du `.zip` servi.

### Validations exécutées (hôte)

- **Go** : `go test ./...` VERT (nouveaux tests : conversion UTF-16 BOM/round-trip/déterminisme, `Rainmeter.ini`
  durci, `fileMatchesContent`, extraction zip + **zip-slip rejeté** + idempotence, store install-if-absent,
  watchdog table-driven [nil/absent/running/relance/borné], `OverlayDocumentForSession` [compose/absent/machine-only/
  corrompu], embed convertible). `go vet ./...` **exit 0 linux ET `GOOS=windows`**. Cross-compile `GOOS=windows`
  OK (~10,6 Mo). **Golden `overlay.golden.json` VERT** (composition byte-identique — aucun bump).
- **PHPUnit (hôte, SQLite)** : nouveau `ToolEndpointTest` **8/8 VERT** (200 binaire, 404 inconnu/malformé/tools_path
  illisible, 401 sans token, 403 quarantaine, X-Agent-New-Token sur 200). `--filter Agent` : **404 tests** (était
  396 en baseline → **+8 = mes ToolEndpoint**), **41 erreurs + 1 échec PRÉEXISTANTS** (identiques à la baseline :
  les 41 = `ldap_search()` host sans LDAP via `WorkstationGroupObserver` ; l'1 échec = `ReleaseEndpointTest`
  traversal `%2f` → 500 vs 404, quirk framework non lié, déjà présent). **Aucune régression introduite.**
- **NFR7** : grep `ldap|apcu|ldaprecord|ad_users|ad_user_groups|samba-tool|kerberos` sur tout le nouveau code agent
  + `ToolController` → seule occurrence = un commentaire documentant l'interdit. `php -l` vert sur tous les PHP.

### Reste à faire (actions humaines)

- **/vm** : `php artisan config:cache` (clé `agent.tools_path`) + chown www-admin ; `route:cache` (route
  `agent.v1.tools.download` réelle ajoutée) ; déposer l'artefact portable Rainmeter sous `storage/agent/tools/`
  (chown www-admin) + figer son SHA-256 dans `RainmeterToolChecksum` ; rejouer `--filter Agent` sur /vm.
- **Validation lab Windows (T9, Henri)** : provisioning portable au bootstrap, overlay rendu au logon, non
  déplaçable/masquable, `TrayIcon` absent, config + `overlay.json` inaltérables par l'élève (ACL), kill
  `Rainmeter.exe` → relancé par le watchdog, accents corrects (UTF-16, pas de `Â·`). Cf. QA `agent.md` Section 15.

### Corrections post-review 2026-06-16

Suite à la review `_bmad-output/codeReviews/27-1bis.md` (sonnet + 2e avis opus), corrections appliquées
(A→H). Golden `overlay.golden.json` INCHANGÉ (aucune touche à la composition overlay).

- **A — Convention de chemins/lancement portable Rainmeter (cause racine #7/M1/#8/#18/M2)** :
  `RainmeterStore` aligné sur la structure portable RÉELLE (`Rainmeter.exe`, `Rainmeter.ini`, `Skins/` TOUS à la
  racine — suppression du sous-dossier `app/`, `ExePath()`/`SettingsPath()`/`SkinsDir()` à la racine).
  `BuildHardenedRainmeterIni` : `WindowX=0`/`WindowY=0` en ENTIERS d'amorçage (plus de `#WORKAREAWIDTH#-384` ni
  formule — non résolus dans le `.ini` de settings) ; placement fin délégué à l'`OnRefreshAction=!Move` de la
  skin. `Launch` lance `Rainmeter.exe` SANS argument (la skin se charge via `Active=1`).
- **B — ACL Rainmeter inconditionnelle (#3)** : `ensureRainmeterConfig` pose l'ACL à CHAQUE passage (icacls
  idempotent), plus seulement sur écriture effective → drift ACL recorrigé.
- **C — Sentinelle d'extraction atomique (#10)** : extraction vers dossier temporaire sibling puis bascule, +
  marqueur `rainmeter.installed` écrit en DERNIER (après extraction complète + ACL) ; `RainmeterInstalled()`
  s'appuie sur le marqueur, PLUS sur la présence de `Rainmeter.exe` → une extraction partielle n'est plus
  comptée « installée ».
- **D — Détachement réel du process (#13)** : `Launch` ajoute `CREATE_NEW_PROCESS_GROUP | DETACHED_PROCESS` dans
  `SysProcAttr` ; commentaire aligné.
- **E — Filtre de session du watchdog (#12)** : `Running()` filtre les process par
  `ProcessIdToSessionId(pid) == session courante` → pas de faux positif multi-utilisateurs.
- **F — Robustesse base path (#1)** : `ToolController` `rtrim($base, DIRECTORY_SEPARATOR)` avant la comparaison
  `str_starts_with` (évite `//`, base vide = 404 confiné).
- **G — Borne totale zip (#9)** : `ExtractPortableZip` compte les bytes totaux décompressés, rejette > 500 Mio
  cumulés (en plus de la borne 64 Mio par entrée).
- **H — Test de non-divergence skin (M3)** : `agent/shared/rainmeter_embed_test.go` vérifie que la skin
  embarquée (`embedded/SambaEduOverlay.ini`) est byte-identique à
  `resources/overlay/rainmeter/SambaEduOverlay/SambaEduOverlay.ini`.
- **Items documentés (pas de changement de comportement)** : commentaires explicatifs ajoutés pour #5 (sessions
  Disconnected — écriture idempotente assumée), #4 (micro-fenêtre TOCTOU ACL — pattern 24.6) ; #17 déjà documenté.
  #6/#14 inchangés (faux positifs).

**Gates post-correction (hôte)** : `go test ./...` VERT ; `go vet ./...` exit 0 linux ET `GOOS=windows` ;
`GOOS=windows go build ./...` OK ; `ToolEndpointTest --filter Tool` 8/8 VERT ; golden overlay INCHANGÉ.

## File List

### Nouveaux fichiers

**Serveur (Laravel)**
- `app/Http/Controllers/Api/V1/Agent/ToolController.php` — route/asset dédiée artefact outil de rendu (D8).
- `tests/Feature/Api/V1/Agent/ToolEndpointTest.php` — tests Feature route /tools (8 cas).

**Agent (Go — shared, logique pure testable hôte)**
- `agent/shared/rainmeter.go` — `RainmeterStore` + chemins, `ToUTF16LEWithBOM`, `BuildHardenedRainmeterIni`,
  `ExtractPortableZip` (anti zip-slip), constantes (`RainmeterToolFilename`, `RainmeterToolChecksum`).
- `agent/shared/rainmeter_provision.go` — orchestration SYSTEM `SyncRainmeterTool` (download+hash+extract+config).
- `agent/shared/rainmeter_embed.go` — `go:embed` de la skin canonique (copie embarquée).
- `agent/shared/embedded/SambaEduOverlay.ini` — copie UTF-8 de la skin canonique (maintenue identique).
- `agent/shared/overlay_logon.go` — `OverlayDocumentForSession` (compose overlay depuis le cache per-SID).
- `agent/shared/watchdog.go` — `RainmeterWatchdog` + `RainmeterOps` (logique de relance bornée).
- `agent/shared/rainmeter_test.go`, `agent/shared/watchdog_test.go`, `agent/shared/overlay_logon_test.go` — tests.
- `agent/shared/rainmeter_embed_test.go` — test de non-divergence skin embarquée ↔ skin canonique repo (M3,
  ajouté en correction post-review).

**Agent (Go — windows, spécifique OS)**
- `agent/windows/overlay_logon_windows.go` — écriture overlay.json au logon (WTSQueryUserToken + profil + ACL <SID>:R).
- `agent/windows/rainmeter_windows.go` — `rainmeterOps` (présence via Toolhelp32, relance), store portable.

### Fichiers modifiés

**Serveur (Laravel)**
- `config/agent.php` — clé `tools_path`.
- `routes/api.php` — import `ToolController` + route `agent.v1.tools.download` (après le groupe 16.12).

**Agent (Go)**
- `agent/shared/loop.go` — champs `Agent.Rainmeter`/`Agent.RainmeterACL` + appel `SyncRainmeterTool` au bootstrap.
- `agent/shared/companion.go` — champ `Companion.Watchdog` + `Watchdog.Tick()` dans la boucle résidente.
- `agent/shared/files.go` — constante `DefaultProgramDataSambaEdu` (fallback overlay).
- `agent/windows/service_windows.go` — `Accepts |= AcceptSessionChange` + `case SessionChange` (logon → overlay).
- `agent/windows/sessions_windows.go` — `interactiveSessionIDs()` (SessionID WTS, vet-clean).
- `agent/windows/acl_windows.go` — `setOverlayFileACL` (FICHIER, SYSTEM full + `<SID>:R`).
- `agent/windows/companion_windows.go` — retrait `overlay` de la map (D1) + retrait `rainmeterPresent` + watchdog.
- `agent/windows/main_windows.go` — câblage `Rainmeter`/`RainmeterACL` dans `newAgent`.

**Skin & documentation**
- `resources/overlay/rainmeter/SambaEduOverlay/SambaEduOverlay.ini` — commentaire de verrouillage (T6).
- `resources/overlay/README.md` — section « Rendu VERROUILLÉ (27.1bis) » + ligne d'emplacement.
- `agent/README.md` — volet rendu verrouillé + note install/bootstrap Rainmeter.
- `docs/agent/session-companion.md` — note D1 (overlay → SYSTEM, ACL, watchdog).
- `docs/qa/domains/agent.md` — Section 15 (7 scénarios) + checklist (append-only).
- `docs/qa/README.md` — ligne domaine agent enrichie (27.1bis).
- `_bmad-output/implementation-artifacts/sprint-status.yaml` — statut 27-1bis → review.

## Change Log

| Date | Auteur | Changement |
|------|--------|-----------|
| 2026-06-16 | DEV opus (claude-opus-4-8) | Implémentation 27.1bis (T0-T9) : route/asset /tools dédiée (D8), provisioning Rainmeter portable au bootstrap (hash vérifié, install-if-absent, zip-slip), écriture overlay.json par le SERVICE SYSTEM au logon (session-change, ACL `<SID>:R`, D1/D2/Q1=logon-only), skin UTF-16 LE+BOM + Rainmeter.ini durci ACL ProgramData (D4/D6), watchdog compagnon (D5). Golden overlay INCHANGÉ. Go test/vet(linux+windows)/cross-compile verts ; ToolEndpointTest 8/8 ; `--filter Agent` +8 sans régression (41 err + 1 fail préexistants). Status → review. |
| 2026-06-16 | DEV opus (claude-opus-4-8) | Corrections post-review (A→H) : convention portable Rainmeter alignée sur la structure réelle (exe+ini+Skins à la racine, plus de `app/`), `Rainmeter.ini` durci `WindowX/Y` entiers + `Launch` sans argument (skin via `Active=1`), ACL inconditionnelle, marqueur `rainmeter.installed` atomique, `CREATE_NEW_PROCESS_GROUP\|DETACHED_PROCESS`, filtre session watchdog, `rtrim` base path ToolController, borne totale zip (500 Mio), test non-divergence skin (M3). Commentaires #4/#5 (limites assumées). Golden INCHANGÉ. Gates verts (go test/vet linux+windows/build windows + ToolEndpointTest 8/8). |

# Story 27.20: Staging des outils WPKG partagés (`%Z%\wpkg\tools\`) sur le poste

Status: done

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

As an **administrateur d'établissement SE5**,
I want **que les outils WPKG partagés (`7za.exe`, `nircmd.exe`, …) soient présents sur le poste à l'emplacement attendu (`%Z%\wpkg\tools\` = `c:\windows\install\wpkg\tools\`)**,
so that **les recettes qui les invoquent (extraction d'archive, création de raccourcis) s'exécutent réellement — aujourd'hui elles échouent car les outils ne sont jamais déposés sur le poste**.

## Contexte & cadrage (suite e2e 27.19, 2026-06-24)

L'e2e de 27.19 sur la VM a révélé un gap résiduel. 27.19 (puis son extension `%Z%\packages\`) livre désormais en HTTP les **payloads par-app**. Mais une partie des recettes dépend aussi d'**outils PARTAGÉS** appelés via `%Z%\wpkg\tools\…` :

- `%Z%\wpkg\tools\7za.exe` — archiveur (extraction des `.zip`/`.7z` côté poste, ex. recette `adnarn`) ;
- `%Z%\wpkg\tools\nircmd.exe` — création de raccourcis (`NirCmd.exe shortcut …`) ;
- (présents serveur, pas encore référencés mais du même canal) `md5sum.exe`, `wintail.exe`, `tooltip/wpkg-msg.exe`, `tooltip/tooltip.exe`.

`%Z%` = `c:\windows\install` ; `%Z%\wpkg\tools\` = `c:\windows\install\wpkg\tools\` — **vide en SE5** (le montage SMB legacy qui le peuplait est débranché, cf. [[project_wpkg_native_bundle_payload_gap]]). Ce ne sont **PAS** des payloads par-app : ils n'ont **aucun `<download saveto>`** dans les recettes → 27.19 ne les couvre pas (à dessein — réécriture chirurgicale `%Z%\packages\` uniquement, cf. [[project_wpkg_zvar_packages_and_tools_gap]]).

Constat e2e (`C:\Windows\wpkg.log`, poste `testenrol`) : `adnarn` → `Exit code (1) on command '%ComSpec% /C %Z%\wpkg\tools\7za.exe e … %Z%\packages\adnarn\adnarn.zip'` — même après livraison du payload (`%TEMP%\adnarn\adnarn.zip`), l'outil `7za.exe` absent fait échouer l'extraction.

**Décision de cadrage à acter** : les outils sont « pareils pour tous » (config d'instance, petits, stables) — comme les scripts du bundle. La direction naturelle = **les déposer UNE FOIS sur le poste** à `c:\windows\install\wpkg\tools\` (l'emplacement que `%Z%\wpkg\tools\` résout), de sorte que **les recettes restent inchangées** (aucune réécriture `%Z%\wpkg\tools\` — contrairement aux payloads). Source serveur : `/var/sambaedu/unattended/install/wpkg/tools/` (déjà peuplé).

### Hypothèses de cadrage (à valider)
1. **Staging local one-shot, pas de re-download par recette** : déposer les outils sur le poste (≠ télécharger dans `%TEMP%` à chaque install) — ils sont partagés et réutilisés par de nombreuses recettes.
2. **Recettes INCHANGÉES** : on ne réécrit PAS `%Z%\wpkg\tools\…` ; on rend le chemin valide en peuplant le dossier. (Alternative rejetée : réécrire chaque appel d'outil en `%TEMP%` = re-téléchargements redondants + recettes plus fragiles.)
3. **Transport HTTP** (cohérent 27.19) : les outils sont servis par Apache et récupérés par le poste (pas de retour SMB).

## Acceptance Criteria

**Transport HTTP des outils**
1. Les outils WPKG partagés sont accessibles en HTTP depuis le serveur (ex. alias `/wpkg/tools` → `/var/sambaedu/unattended/install/wpkg/tools`), `-Indexes`, `Require all granted`, même garde-fou sécurité que `/wpkg/files` (jamais l'arbre `install` entier, jamais `storage/keys/pki`). Alias câblé dans `scripts/setupApache.sh` + test de complétude `update.sh` (modèle `/wpkg/files`, story 27.19).

**Dépôt sur le poste**
2. Au déclenchement WPKG, l'**AGENT** dépose/rafraîchit les outils sous `%WinDir%\install\wpkg\tools\` (= `%Z%\wpkg\tools\`), en préservant la sous-arborescence (`tooltip/…`). **Idempotent VRAI par hash** : l'agent fetch un `manifest.json` (énumération + sha256), compare le fichier local au sha256 attendu et **SKIP s'il est déjà à jour** ; il ne (re)télécharge (download atomique tmp+rename) **que** sur dérive (absent ou hash divergent — un outil corrompu est ainsi re-fetché, un outil conforme n'engendre aucun réseau). Le staging est piloté par l'agent (module générique `agent/provision` + `provision.Reconcile`) AVANT le déclenchement de `wpkg-client.vbs` — **fail-soft** (un outil manquant ne bloque pas le run). *(Pivot post-review : `wpkg.cmd` ne s'exécutant sur aucun chemin runtime SE5, la logique outils y est déplacée vers l'agent.)*
3. Les recettes restent **inchangées** : `%Z%\wpkg\tools\7za.exe` et `%Z%\wpkg\tools\nircmd.exe` résolvent vers des fichiers présents → extraction et raccourcis fonctionnent.

**Non-régression & e2e**
4. `adnarn` (recette de référence du gap) s'installe de bout en bout : payload livré (27.19) + outil `7za.exe` présent → extraction OK → `<check>` OK → rapport compliant.
5. Les payloads livrés par 27.19 et la purge `%TEMP%` ne sont pas affectés ; les outils déposés ne sont PAS purgés (ils persistent pour les recettes suivantes).
6. Inventaire serveur des outils servis world-readable (664) sous le canal, comme les payloads.

## Tasks / Subtasks

- [x] **T1 — Alias Apache `/wpkg/tools`** (AC: 1) — modèle `/wpkg/files` ; `scripts/setupApache.sh` + ajout au test de complétude `update_apache()` de `update.sh`. *(Conservé tel quel — déjà correct.)*
- [x] **T2 — Module agent générique + câblage handler** (AC: 2,3) — **PIVOT** : `wpkg.cmd` reverti (logique inerte). NOUVEAU module `agent/provision` OS-agnostique (`Resource`, `TargetResolver`, `Reconcile` par hash → skip/apply/fail) + adaptateur Windows seul (`WindowsResolver` → `%Z%\wpkg\tools`, matérialisation reparse point). Câblage dans `handler_applications_windows.go` (`stageSharedTools` : fetch `manifest.json` + `Reconcile`) AVANT `cscript wpkg-client.vbs`, fail-soft.
- [x] **T3 — Provisioning serveur + `manifest.json`** (AC: 6) — `ensure_wpkg_tools` (perms 664/755, `chown www-admin`, fail-soft) **+ génération du `manifest.json`** (énumération `tools/` récursive, sha256 par fichier, JSON aligné sur `provision.Resource`, world-readable 664/www-admin, idempotent).
- [x] **T5 — Bump version agent** — `agent/shared/version.go` `2.2.18` → `2.2.19` (règle : toute édition agent/** = bump).
- [x] **T6 — Tests + doc** (AC: 4,5) — tests Go `agent/provision` (HÔTE : skip-si-hash, apply-si-drift, download atomique, mismatch, 404, sous-arbre, multi-ressources) + tests PHP infra serveur (alias, `ensure_wpkg_tools` perms + `manifest.json`) ; runbook `docs/qa/domains/wpkg-deploy.md` (Section 11 révisée agent-driven).

## Dev Notes

- Outils présents serveur : `/var/sambaedu/unattended/install/wpkg/tools/{7za.exe, nircmd.exe, md5sum.exe, wintail.exe, README.TXT, tooltip/{wpkg-msg.exe, tooltip.exe, tooltip.au3, wpkg-msg.au3}}`.
- Référencés aujourd'hui par le catalogue : `%Z%\wpkg\tools\7za.exe`, `%Z%\wpkg\tools\nircmd.exe` (casse variable `nircmd`/`NirCmd`).
- `%Z%` = `c:\windows\install` (posé par `wpkg-client.vbs` `MapZ()`), donc cible de dépôt = `c:\windows\install\wpkg\tools\`.
- Cohérence 27.19 : transport HTTP, jamais SMB ; agent Go inchangé (le poste récupère via le canal bundle/wpkg.cmd).
- **À trancher** : (a) déposer via `wpkg.cmd` (au déclenchement, contexte du moteur) vs bootstrap GPO ; (b) manifeste d'outils explicite vs miroir du répertoire serveur ; (c) fréquence de rafraîchissement (chaque run vs versionné).

## Dépendances
- 27.19 (livraison WPKG full HTTP — payloads + extension `%Z%\packages`) — review/done. Cette story complète le volet OUTILS laissé en suivi.

## Recommandation Modèle Dev

**opus** — touche le canal de déclenchement poste (`wpkg.cmd`/VBS), un alias Apache sécurisé, un mécanisme de dépôt idempotent, et exige une validation e2e poste (adnarn). Décisions de cadrage (manifeste vs miroir, dépôt vs bootstrap) à arbitrer.

## Dev Agent Record

**Modèle dev** : opus (claude-opus-4-8[1m]). **Date** : 2026-06-24.

### PIVOT ARCHITECTURAL (post-review) — pourquoi

La 1re implémentation déposait les outils via `resources/wpkg/wpkg.cmd`. Review (sonnet) + 2e avis (opus) + vérif orchestrateur ont prouvé un **bug critique** : ce `wpkg.cmd` ne s'exécute sur **AUCUN chemin runtime SE5**. L'agent déclenche WPKG via `cscript //B //NoLogo %WinDir%\wpkg-client.vbs /NOTempo` (`handler_applications_windows.go`) avec env `SE4FS`/`SE4_WPKG_BUNDLE_URL`/`SE4_WPKG_LOCAL_PROFILE_DIR` — **jamais** `wpkg.cmd` ; et le bundle HTTP ne re-fetche que la `wpkg-client.vbs`. La logique outils dans `wpkg.cmd` était donc **inerte**. Direction Henri : « limiter le GPO, mettre un MAX de logique dans l'AGENT », extensible à d'autres OS (Linux/AppImage à terme).

### Conception cible livrée — serveur DÉCLARATIF / agent IMPÉRATIF

- **Module générique `agent/provision`** (OS-agnostique, stdlib seule, testable hôte Linux) : `Resource{ID,Kind,RelPath,URL,SHA256,Executable}` + interface `TargetResolver{Resolve(r) (absPath, err)}` + `Reconcile(res, tgt) []Outcome`. Pour chaque ressource : TEST (présent ET sha256 == attendu ? → `skipped`) → APPLY (download avec vérif hash du flux, écriture atomique tmp+rename, chmod +x si `Executable` → `applied`) → `failed` sinon. **Jamais d'erreur globale** : un échec n'interrompt pas les suivantes. **Idempotence VRAIE par hash** (⇒ AC2 « skip si déjà à jour » redevient exact). Le `7za.exe`/`nircmd` deviennent des `kind:"wpkg-tool"` ; un AppImage serait `kind:"appimage"` — même moteur.
- **Adaptateur Windows seul** (`provision_windows.go`, build tag `windows`) : `WindowsResolver.Resolve` place `wpkg-tool` sous `%WinDir%\install\wpkg\tools\<RelPath>` (sous-arbo `tooltip/` préservée via RelPath), `MkdirAll` du dossier cible. **L'interface Linux n'est PAS implémentée** : `TargetResolver` étant une interface, un futur resolver Linux s'ajoute sans toucher `Reconcile` (agent Linux post-MVP, pas de code mort).
- **Câblage** (`handler_applications_windows.go`) : `toolsURL()` dérive `server_url + shared.WpkgToolsPath` (`/wpkg/tools`) — **exactement** comme `bundleURL()` dérive `WpkgBundlePath`. `stageSharedTools()` fetch `manifest.json`, construit `[]Resource` (URL = toolsURL + relpath, `Executable` si `.exe`), appelle `provision.Reconcile`, loggue les Outcomes — **AVANT** `cscript wpkg-client.vbs`. **Fail-soft** : manifeste inaccessible / outil en échec → WARN + on continue (le déclenchement WPKG n'échoue jamais pour un outil optionnel, modèle existant).

### Investigation `%Z%` sur le chemin AGENT (greenfield) — résultat

`MapZ()` (`wpkg-client.vbs`) renvoie le **littéral** `c:\windows\install` ; le mapping SMB legacy (`MKLINK`/montage `\\%SE4FS%\install`) est **commenté** dans la vbs SE5. Le GPO bootstrap `resources/gpo/SE_agent_bootstrap/.../startup.cmd` crée `%WINDIR%\install\wpkg` en **vrai dossier** (ligne `if not exist … md`), mais **PAS** `…\wpkg\tools`. Sur le chemin agent-driven, rien dans `wpkg-client.vbs → wpkg-se4.js` ne crée `…\wpkg\tools` : `%Z%\wpkg\tools\7za.exe` résolvait vers un chemin **inexistant**. `wpkg-se4.js` pose `%Z%=c:\windows\install`, `WPKGROOT=%Z%\wpkg`, etc. ⇒ `%Z%\wpkg\tools\7za.exe` = `c:\windows\install\wpkg\tools\7za.exe`. **Conclusion** : c'est exactement là que `WindowsResolver` doit garantir le dossier — `MkdirAll(%WinDir%\install\wpkg\tools)`. Le piège du **reparse point** ne concerne plus que les **postes MIGRÉS** (legacy `MKLINK /D … \\%SE4FS%\install`) : `materializeInstallRoot()` détecte le reparse (`Lstat` mode symlink + repli `fsutil reparsepoint query`) et le retire (`os.Remove` = lien seul, cible distante intacte) avant de recréer un dossier local. Sur greenfield, `c:\windows\install` est déjà un vrai dossier → détection no-op.

### Provisioning serveur + `manifest.json`

- Source `/var/sambaedu/unattended/install/wpkg/tools/` **déjà peuplée** par le `.deb` legacy `sambaedu-wpkg` → binaires non re-livrés (pas d'assets versionnés). `ensure_wpkg_tools` garantit les **droits** (664 fichiers / 755 dossiers world-readable « other » — un 660 = 404 silencieux ; `chown www-admin` ; sous-arbre `tooltip/` via `find`) **ET génère `manifest.json`** : énumération récursive (`find … -print0`, robuste aux espaces), `sha256sum` par fichier, tableau JSON `[{id, kind:"wpkg-tool", relpath, sha256}]` aligné sur `provision.Resource` (l'agent compose l'URL = toolsURL + relpath). Écriture atomique (tmp HORS `tools_dir` pour ne pas être énuméré, puis `mv`), `manifest.json` exclu de sa propre énumération, world-readable 664 + www-admin. **Idempotent** (régénéré à chaque `update.sh`). Fail-soft : dossier absent → WARNING + `return 0`.

### Bump version agent

- `agent/shared/version.go` : **2.2.18 → 2.2.19** (toute édition `agent/**` = bump). Entrée d'historique ajoutée (staging agent-driven, module `provision`, idempotence par hash). Contrat wire/golden **inchangés** (aucune surface `/state` ou rapport touchée).

### Revert

- `resources/wpkg/wpkg.cmd` **remis à HEAD** (`git checkout HEAD --`). Toute la logique outils/matérialisation y était inerte. Cela rend caducs les findings de review #1 (CRLF), #3 (bitsadmin), #6c (collision ancre MKLINK). Le `.gitattributes` (`resources/wpkg/*.cmd text eol=crlf`) est **conservé** (garde-fou correct).

### Tests

- **Go `agent/provision`** (`provision_test.go`, HÔTE Linux `~/go-toolchain/go/bin/go test`) : skip-si-hash-match (zéro réécriture), apply-si-absent, apply-si-dérive-de-hash, fail-si-hash-divergent (+ aucun fichier publié/tmp résiduel = atomicité), fail-si-404, fail-si-résolution-KO, sous-arbre `tooltip/` préservé, multi-ressources (un échec n'interrompt pas les suivants), hash-vide → toujours apply. **Tous verts.** Adaptateur Windows : cross-compile `GOOS=windows go build ./...` OK (pas de test HÔTE — build-tagué windows). Suite Go complète (`go test ./...`) : `provision` + `shared` verts.
- **PHP** `WpkgSharedToolsStagingTest.php` (7 tests, HÔTE php 8.4) : alias scopé + garde-fous + check complétude (T1) ; `wpkg.cmd` reverti (ne porte plus `SE4_WPKG_TOOLS_URL`/`fsutil`) ; `ensure_wpkg_tools` 664/755/www-admin/fail-soft + génération `manifest.json` (sha256, `kind:"wpkg-tool"`, relpath, 664). **Tous verts.**
- Non-régression 27.19 : `PackagesXmlServiceTest` + `WpkgBundleGeneratorTest` (33 tests) verts.

### PENDING /vm (à valider manuellement sur la VM/poste — non exécutable côté hôte)

- **Effet agent = rebuild + publication requis** : le staging agent-driven n'existe sur le poste qu'après `build-agent.sh --stable` (binaire 2.2.19 publié + rapporté par check-in). NON fait ici (interdit d'agir sur la VM). Tant que le binaire publié est antérieur au commit, `stageSharedTools` n'est pas dans le binaire qui tourne.
- **Migrations** : aucune (story sans schéma).
- **Apache + manifeste** : sur la VM, `update.sh` relance `setupApache.sh` (alias `/wpkg/tools`) + `ensure_wpkg_tools` (droits + `manifest.json`, `chown www-admin`). À vérifier : `curl -sI http://<se4fs>/wpkg/tools/7za.exe` → 200, pas de listing ; `curl -s …/wpkg/tools/manifest.json | jq` → tableau sha256, pas d'auto-référence (Scénarios 11.1/11.2).
- **e2e poste `adnarn`** (Scénario 11.3) : agent 2.2.19 → `stageSharedTools` (manifest + Reconcile) avant `cscript` → `%Z%\wpkg\tools\7za.exe` présent → extraction OK → `<check>` OK → compliant. Vérifier log agent (`N déposé(s), M à jour, 0 en échec`), idempotence 2ᵉ run (tout `skipped`), `C:\Windows\install` vrai dossier (`fsutil reparsepoint query`), outils persistants entre runs (NON purgés).

## File List

- `agent/provision/provision.go` — **créé** (T2) : module générique OS-agnostique (`Resource`, `TargetResolver`, `Reconcile` par hash → skipped/applied/failed, download atomique + vérif sha256).
- `agent/provision/provision_windows.go` — **créé** (T2) : `WindowsResolver` (build tag windows) — `wpkg-tool` → `%WinDir%\install\wpkg\tools\<RelPath>`, matérialisation `%Z%` (reparse point legacy).
- `agent/provision/provision_test.go` — **créé** (T6) : tests HÔTE du moteur (skip/apply/drift/mismatch/404/sous-arbre/multi-ressources).
- `agent/windows/handler_applications_windows.go` — **modifié** (T2) : `toolsURL()` + `stageSharedTools()` (fetch `manifest.json` + `provision.Reconcile`) appelé dans `TriggerWpkg` AVANT `cscript`, fail-soft.
- `agent/shared/files.go` — **modifié** (T2) : constante `WpkgToolsPath = "/wpkg/tools"`.
- `agent/shared/version.go` — **modifié** (T5) : `2.2.18` → `2.2.19` + entrée d'historique.
- `resources/wpkg/wpkg.cmd` — **REVERTI à HEAD** (T2/revert) : logique outils inerte retirée.
- `scripts/setupApache.sh` — *(inchangé par le pivot — T1 déjà correct)* : alias Apache `/wpkg/tools` scopé sur `.../install/wpkg/tools` (`-Indexes`, `Require all granted`, pas de FallbackResource).
- `scripts/update.sh` — **modifié** (T1+T3) : `update_apache()` exige l'alias `/wpkg/tools` ; `ensure_wpkg_tools()` (droits 664/755 + www-admin, fail-soft) **+ génération du `manifest.json`** (énumération récursive + sha256, JSON aligné `provision.Resource`, world-readable, idempotent, tmp atomique hors arbre).
- `tests/Unit/Wpkg/Deployment/WpkgSharedToolsStagingTest.php` — **modifié** (T6) : retrait des assertions sur le contenu de `wpkg.cmd` (reverti) ; conservé T1 (alias) + T3 (perms) ; ajout assertion `manifest.json` (sha256). 7 tests HÔTE.
- `docs/qa/domains/wpkg-deploy.md` — **modifié** (T6) : Section 11 RÉVISÉE agent-driven (manifeste + `Reconcile` avant `cscript`, scénario `adnarn` reformulé, idempotence par hash).
- `docs/qa/README.md` — **modifié** : entrée « Domaines couverts » wpkg-deploy (Story 27.20).
- `_bmad-output/implementation-artifacts/sprint-status.yaml` — **modifié** : commentaire 27-20 (pivot agent-driven), reste `review`.

## Change Log

| Date | Version | Description |
|---|---|---|
| 2026-06-24 | 0.1 | Story créée (suite e2e 27.19, volet outils partagés). |
| 2026-06-24 | 1.0 | Implémentation T1-T4 (opus) : alias Apache `/wpkg/tools` + check complétude ; `wpkg.cmd` matérialise `%Z%` en dossier local (retrait symlink SMB) + manifeste HTTP des outils ; `ensure_wpkg_tools` (droits 664/www-admin) ; tests HÔTE (9) verts + non-régression 27.19 ; runbook Section 11. Status → review. PENDING /vm : Apache curl + e2e poste adnarn. |
| 2026-06-24 | 2.0 | **PIVOT ARCHITECTURAL** (post-review, bug critique #6 : `wpkg.cmd` ne s'exécute sur aucun chemin runtime SE5). Staging des outils déplacé dans l'AGENT : `wpkg.cmd` reverti à HEAD ; nouveau module Go générique `agent/provision` (`Resource`/`TargetResolver`/`Reconcile` par hash, OS-agnostique) + adaptateur Windows seul (`WindowsResolver` → `%Z%\wpkg\tools`, matérialisation reparse point) ; câblage `handler_applications_windows.go` (`stageSharedTools` : fetch `manifest.json` + `Reconcile` AVANT `cscript`, fail-soft) ; `ensure_wpkg_tools` génère désormais `manifest.json` (énumération + sha256) ; bump agent 2.2.18 → 2.2.19 ; tests Go (`provision`) + PHP (7) verts + non-régression 27.19. AC2 reformulé (idempotence VRAIE par hash). Runbook Section 11 révisée agent-driven. Status reste `review`. PENDING /vm : Apache+manifeste curl, rebuild/publication agent, e2e poste adnarn. |

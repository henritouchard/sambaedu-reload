# Story 27.1ter : Rainmeter settings per-user writable (mode installé)

Status: review

<!-- Suite directe de 27.1bis (provisioning overlay verrouillé). -->

## Story

En tant qu'**élève (utilisateur standard non-admin)**,
je veux **que l'overlay Rainmeter s'affiche au logon SANS aucune modale d'erreur**,
afin que **le poste soit propre et silencieux à l'ouverture de session — pas de « Rainmeter.ini is not writable », pas de « Safe Start » qui propose de charger les skins par défaut**.

## Contexte & intention

**Suite directe de 27.1bis.** Le verrouillage livré par 27.1bis pose `Rainmeter.ini` en **lecture seule** dans l'arbre ACL `C:\ProgramData\SambaEdu\Rainmeter\` (Users:R, durci en `RX` par le fix `setRainmeterACL` du 2026-06-16 pour permettre le lancement de `Rainmeter.exe`). Ce choix « verrouillage par `.ini` read-only » s'est révélé **cassé en e2e sur poste (2026-06-16)** :

1. **Modale « Rainmeter.ini is not writable. Settings will not be saved »** — Rainmeter veut persister ses settings (positions, état des skins) et ne peut pas.
2. **Modale « Rainmeter Safe Start »** — Rainmeter détecte un arrêt non-propre car il ne peut pas effacer/écrire son marqueur d'arrêt dans le `.ini` read-only → il croit avoir planté à chaque relance (aggravé par le watchdog qui relance après une sortie). Si l'utilisateur clique « Oui », Rainmeter **décharge notre skin et charge les défauts illustro**.

Symptôme observé : **admin** ne voit PAS ces modales (il a `Administrators:F` → son Rainmeter écrit l'`.ini`), mais **un user standard** (`RX`) les voit à chaque logon. L'overlay rend quand même (Rainmeter poursuit après l'avertissement), mais l'expérience est cassée.

**Décision (mémoire `project_overlay_rainmeter_lockdown_direction`, point « écriture per-user tranchée 2026-06-16 ») : passer Rainmeter en MODE INSTALLÉ, settings per-user writable.** Rainmeter lit son `Rainmeter.ini` dans `%APPDATA%\Rainmeter\` (writable, local, toujours dispo sur poste nomade — le home réseau ne l'est pas, cf. `project_nomade_local_fr29_closed`), tout en gardant les **skins verrouillées** en ProgramData (`RX`) via la directive `SkinPath`.

**Ce que cette story livre :**
- **Agent Go (provisioning SYSTEM)** : l'extraction portable reste RX-verrouillée (`Rainmeter.exe`, `Skins\SambaEduOverlay\`, runtime) MAIS on **retire tout `Rainmeter.ini` du dossier ProgramData** — y compris celui que le zip portable embarque (sa présence à côté de `Rainmeter.exe` force le **mode portable**). Plus aucun `Rainmeter.ini` dans l'arbre verrouillé.
- **Agent Go (builder `.ini`)** : `BuildHardenedRainmeterIni` gagne une directive `SkinPath=C:\ProgramData\SambaEdu\Rainmeter\Skins\` (pointe vers les skins restées verrouillées) en plus de `[Rainmeter] TrayIcon=0/Debug=0` et de la section `[SambaEduOverlay]` (Active=1, Draggable=0, ClickThrough=1, KeepOnScreen=1, AlwaysOnTop=1, SnapEdges=0, position d'amorçage) — inchangées.
- **Agent Go (compagnon, droits user)** : à son démarrage (AVANT le lancement de Rainmeter par le watchdog), il **écrit `%APPDATA%\Rainmeter\Rainmeter.ini`** avec ce contenu durci, **WRITABLE** (c'est le `%APPDATA%` de l'user, aucune ACL à poser), écriture **atomique idempotente** (réimpose le durci à chaque logon).
- **Watchdog** : inchangé, lance toujours `Rainmeter.exe` depuis ProgramData → Rainmeter lit désormais `%APPDATA%\Rainmeter\Rainmeter.ini` (writable) → charge la skin verrouillée via `SkinPath` → **plus de modale not-writable ni Safe Start**.

**Ce que cette story N'EST PAS :**
- Une refonte du **payload** overlay ni de `OverlayStateProvider` (la donnée et `overlay.json` sont inchangés — `overlay.json` reste écrit par SYSTEM, read-only user, NFR5 intact).
- Un changement du **contrat desired-state** (aucun item d'état nouveau ; pas de bump golden).
- Le préchargement de l'identité machine (poste + salle) au logon — **story suivante distincte**.
- Le refresh overlay mid-session — différé.

## ⚠️ Pièges & tensions (lire AVANT de coder)

1. **Mode portable vs installé = présence de `Rainmeter.ini` à côté de `Rainmeter.exe`.** Rainmeter cherche d'abord un `Rainmeter.ini` dans le dossier de l'exe → si présent, **mode portable** (settings là). S'il est absent → **mode installé**, settings dans `%APPDATA%\Rainmeter\`. **Donc** : il faut GARANTIR qu'aucun `Rainmeter.ini` ne subsiste dans le dossier ProgramData après extraction (le zip portable en embarque probablement un — à supprimer post-extraction).

2. **Le `.ini` per-user DOIT rester writable.** C'est tout l'objet de la story : ne PAS poser d'ACL read-only dessus. C'est le `%APPDATA%` de l'user, écrit par le compagnon **en droits user** — il appartient naturellement à l'user. Tradeoff lockdown assumé (voir Décisions).

3. **Ordre d'écriture vs lancement.** Le compagnon doit écrire `%APPDATA%\Rainmeter\Rainmeter.ini` **AVANT** que le watchdog lance `Rainmeter.exe` (sinon Rainmeter lit un `.ini` absent/ancien au premier lancement). Le levier A (2.2.8) a déjà fait remonter le `Watchdog.Tick()` au tout début de `Companion.Run` → l'écriture du `.ini` per-user doit se faire **juste avant** ce Tick.

4. **`SkinPath` et résolution du config.** La section `[SambaEduOverlay]` réfère au dossier de config `SambaEduOverlay` SOUS `SkinPath`. Donc `SkinPath=...\Skins\` + config `SambaEduOverlay` → charge `...\Skins\SambaEduOverlay\SambaEduOverlay.ini`. Structure inchangée par rapport à 27.1bis ; seul l'emplacement du `Rainmeter.ini` bouge. Rainmeter LIT les skins (RX OK) et ÉCRIT son état dans le `.ini` (désormais en `%APPDATA%`, writable).

5. **Idempotence + suppression du `.ini` portable côté provisioning.** `ensureRainmeterConfig` (SYSTEM) écrivait jusqu'ici le `Rainmeter.ini` durci sous ProgramData (`SettingsPath`). Il faut : (a) **arrêter** cette écriture sous ProgramData, (b) **supprimer** tout `Rainmeter.ini` résiduel du dossier ProgramData (portable embarqué + ancien durci d'une install 27.1bis antérieure), de façon idempotente.

6. **Poste avec Rainmeter déjà installé (rare, poste migré).** `%APPDATA%\Rainmeter\Rainmeter.ini` peut préexister (avec un `@Backup` Safe Start). L'écriture atomique idempotente du compagnon **écrase** ce fichier par le durci — acceptable et voulu (on impose notre config), mais à documenter. Pas de fusion.

7. **Architecture pure/Windows (mémoire `project_root_is_laravel`, conventions agent).** La logique pure (contenu du `.ini` durci avec `SkinPath`, chemin per-user relatif) vit dans `shared/`, testable sur l'hôte. Le spécifique Windows (`os.Getenv("APPDATA")`, écriture) est injecté ou dans `agent/windows`. UTF-16 LE + BOM conservé pour homogénéité Rainmeter (le `.ini` durci est ASCII pur, mais 27.1bis le pose en UTF-16 LE+BOM — reconduire).

## Décisions de design — TRANCHÉES

- **D1 — `%APPDATA%\Rainmeter\Rainmeter.ini`** comme emplacement des settings (mode installé). PAS le home réseau (indispo nomade), PAS `%LOCALAPPDATA%` (Rainmeter installé lit `%APPDATA%\Rainmeter` par convention).
- **D2 — Le COMPAGNON écrit le `.ini` per-user** (droits user, accès trivial à `%APPDATA%`), pas le service SYSTEM (qui devrait résoudre le profil + poser une ACL — inutile ici puisqu'on VEUT du writable). Cohérent avec « le compagnon gère le rendu user ».
- **D3 — `.ini` per-user WRITABLE, sans ACL.** Tradeoff lockdown : l'élève peut éditer son `Rainmeter.ini` dans sa session, mais (a) `overlay.json` reste SYSTEM read-only → la **donnée affichée n'est jamais falsifiée** (NFR5) ; (b) le compagnon **réimpose le durci au logon suivant** ; (c) la **skin reste verrouillée** en ProgramData (RX). L'élève peut au pire masquer l'overlay pour sa session courante — récupérable, et il ne peut pas afficher de fausse donnée.
- **D4 — Skins verrouillées inchangées** en `C:\ProgramData\SambaEdu\Rainmeter\Skins\` (RX), pointées par `SkinPath`.
- **D5 — Écriture idempotente** : le compagnon réécrit le `.ini` per-user à chaque démarrage si absent OU si son contenu diverge du durci attendu (hash). Réécriture wholesale OK (Rainmeter re-sauvegarde positions en session ; l'amorce repositionne via `!Move`).

## Acceptance Criteria

### AC1 — Provisioning SYSTEM : plus aucun `Rainmeter.ini` dans l'arbre ProgramData
- L'extraction portable reste RX-verrouillée (exe, Skins, runtime) — inchangé.
- `ensureRainmeterConfig` **n'écrit plus** `Rainmeter.ini` sous ProgramData (`SettingsPath`).
- Tout `Rainmeter.ini` résiduel du dossier racine ProgramData (embarqué par le zip portable OU ancien durci) est **supprimé** de façon idempotente après extraction.
- Le marqueur d'install (`rainmeter.installed`) et `Rainmeter.exe` restent intacts (la détection `Installed()`/`ExePath()` ne dépend pas du `.ini`).

### AC2 — Builder `.ini` durci : directive `SkinPath` ajoutée
- `BuildHardenedRainmeterIni` émet `SkinPath=C:\ProgramData\SambaEdu\Rainmeter\Skins\` dans `[Rainmeter]` (chemin résolu depuis le store, pas codé en dur dans la mesure du possible).
- Le reste (`TrayIcon=0`, `Debug=0`, section `[SambaEduOverlay]` avec les flags de verrouillage + position d'amorçage) est **inchangé**.
- Déterministe (idempotence par hash).

### AC3 — Compagnon : écriture du `.ini` per-user writable au démarrage
- Au démarrage de `Companion.Run`, **AVANT** le `Watchdog.Tick()` anticipé (levier A), le compagnon écrit `%APPDATA%\Rainmeter\Rainmeter.ini` avec le contenu durci (UTF-16 LE + BOM, iso 27.1bis).
- Écriture **atomique** (tmp + rename) et **idempotente** (réécrit si absent ou divergent du durci attendu).
- **Aucune ACL** posée (fichier writable, propriété naturelle de l'user).
- Spécifique Windows (`%APPDATA%`) injecté ou dans `agent/windows` ; nil/no-op en test hôte et non-Windows.
- Un échec d'écriture est **gracieux** (log, jamais de blocage/panique — NFR1) ; le watchdog tente quand même.

### AC4 — Résultat e2e : plus de modale, skin chargée
- Sur un user standard non-admin, au logon : **aucune modale** « not writable » ni « Safe Start ».
- Rainmeter charge bien `SambaEduOverlay` (PAS les défauts illustro).
- `overlay.json` reste écrit par SYSTEM, ACL read-only user (NFR5) — non régressé.

### AC5 — Tests (host) + non-régression
- Logique pure testée sur l'hôte : `SkinPath` présent et correct dans le `.ini` durci ; chemin per-user résolu correctement ; idempotence (même contenu → pas de réécriture / même hash).
- Provisioning : un `Rainmeter.ini` présent dans le dossier d'extraction est bien supprimé ; aucun `.ini` écrit sous ProgramData.
- Non-régression : extraction portable, ACL RX de l'arbre, watchdog, écriture `overlay.json` SYSTEM — inchangés et verts.
- `GOOS=windows go build ./...` OK ; suite `go test ./...` verte.

### AC6 — Documentation + QA (append-only)
- Enrichir le runbook QA du domaine overlay/agent (`docs/qa/domains/...`) en append-only avec un scénario : « logon user standard → aucune modale Rainmeter, skin SambaEduOverlay chargée, `%APPDATA%\Rainmeter\Rainmeter.ini` présent et writable ».
- Bump version agent.

## Tasks / Subtasks

- [x] **T1 — Builder `.ini` durci + `SkinPath`** (`agent/shared/rainmeter.go`)
  - [x] Ajouter `SkinPath=<RainmeterRoot>\Skins\` à `BuildHardenedRainmeterIni` (helper `hardenedSkinPath()` dérivé de `DefaultRainmeterRoot` + `rainmeterSkinsDirName`, fonction pure documentée).
  - [x] Test host : `SkinPath` présent, pointe la racine Skins, dans `[Rainmeter]` ; reste du `.ini` inchangé ; déterminisme.
- [x] **T2 — Provisioning : retrait du `.ini` ProgramData** (`agent/shared/rainmeter_provision.go`, `rainmeter.go`)
  - [x] `ensureRainmeterConfig` n'écrit plus `Rainmeter.ini` sous ProgramData (`SettingsPath`).
  - [x] Suppression idempotente de tout `Rainmeter.ini` résiduel du dossier racine ProgramData (`os.Remove` ignorant `os.IsNotExist`).
  - [x] Vérifié : `Installed()`/`RainmeterInstalled()`/`ExePath()`/marqueur ne dépendent pas du `.ini` — intacts.
  - [x] Tests host : `.ini` portable présent → supprimé ; aucun `.ini` écrit sous ProgramData ; idempotent.
- [x] **T3 — Compagnon : écriture `.ini` per-user** (`agent/shared/companion.go`, `agent/windows/companion_windows.go`, `rainmeter_windows.go`)
  - [x] Primitive Windows injectée `EnsureUserRainmeterIni func() error` ; logique pure `WriteUserRainmeterIni` (UTF-16 LE+BOM, atomique, idempotent, sans ACL) ; chemin `%APPDATA%\Rainmeter\` résolu dans `ensureUserRainmeterIni` (Windows).
  - [x] Appel au démarrage de `Run`, AVANT le `Watchdog.Tick()` anticipé ; gracieux (log warning sur échec, ne bloque rien).
  - [x] nil/no-op en test hôte et non-Windows.
  - [x] Tests host : contenu durci + idempotence + writable (`WriteUserRainmeterIni`) ; ordre écriture-avant-lancement + gracieux (`Companion.Run`).
- [x] **T4 — Non-régression + ACL** : arbre ProgramData reste RX (`RainmeterACL` sur `RootDir()` inchangé), watchdog inchangé, `overlay.json` SYSTEM (`overlay_logon*.go`) non touché.
- [x] **T5 — Tests, build, version** : `GOOS=windows go build ./...` OK, `go test ./...` + `-race` verts, version agent bumpée `2.2.8` → `2.2.9`.
- [x] **T6 — Doc QA append-only + Dev Agent Record + File List + Change Log** (Section 21 de `docs/qa/domains/agent.md`).

## Dev Notes

### Infra réutilisée (ne PAS réinventer)
- `BuildHardenedRainmeterIni`, `ToUTF16LEWithBOM`, `WriteFileAtomic`, le hash d'idempotence (`hashBytes`/`fileMatchesContent`) — tous dans `agent/shared/rainmeter.go`.
- `RainmeterStore` (chemins `RootDir`/`SkinsDir`/`SkinDir`/`ExePath`) — `agent/shared/rainmeter.go`. `SettingsPath`/`SettingsDir` deviennent **obsolètes côté ProgramData** (le settings part en `%APPDATA%`).
- Le levier A (2.2.8) : `Watchdog.Tick()` anticipé en tête de `Companion.Run` — point d'insertion de l'écriture per-user (juste avant).
- L'écriture `overlay.json` SYSTEM (`overlay_logon*.go`) — **NE PAS TOUCHER**.

### Périmètre — livré / hors-scope
- Livré : settings per-user writable, plus de modale, skin verrouillée chargée.
- Hors-scope : préchargement identité machine (poste+salle) → story suivante ; refresh mid-session → différé ; payload overlay / contrat → inchangés.

### Référence (où était écrit le `.ini` ProgramData)
- `agent/shared/rainmeter_provision.go` ~ligne 286 : `ensureRainmeterConfig` posait `Rainmeter.ini` durci + ACL sur `RootDir()`. C'est ce qui change.
- `agent/windows/main_windows.go` : `RainmeterACL: setRainmeterACL` (arbre RX) — conservé (skins + exe restent verrouillés).

## Recommandation Modèle Dev

**fable** — story **agent Go** (provisioning + compagnon + builder `.ini`), logique poste sensible (durcissement/lockdown), conforme à la préférence projet « stories agent desired-state → fable » (mémoire `feedback_epic23_model_fable5`). Review adversariale **opus** (modèle opposé).

## Dev Agent Record

**Modèle dev** : opus (fallback assumé — Fable indisponible).

**Résumé** : Rainmeter passé en MODE INSTALLÉ. (1) `BuildHardenedRainmeterIni` émet `SkinPath=C:\ProgramData\SambaEdu\Rainmeter\Skins\` dans `[Rainmeter]` (helper pur `hardenedSkinPath()`). (2) Le provisioning SYSTEM (`ensureRainmeterConfig`) n'écrit plus de `Rainmeter.ini` sous ProgramData et SUPPRIME idempotemment tout résiduel (`os.Remove`/`os.IsNotExist`). (3) Le compagnon (droits user) écrit `%APPDATA%\Rainmeter\Rainmeter.ini` durci+writable au démarrage de `Run`, AVANT le `Watchdog.Tick()` anticipé, via le hook injecté `EnsureUserRainmeterIni` (no-op nil en test/non-Windows) ; logique pure testable `WriteUserRainmeterIni` (atomique, idempotente par hash, sans ACL) ; chemin `%APPDATA%` résolu dans `agent/windows`. Version bumpée 2.2.9.

**Micro-décisions** :
- `hardenedSkinPath()` compose le chemin de PRODUCTION canonique à partir des constantes (`DefaultRainmeterRoot` + `rainmeterSkinsDirName`) car `BuildHardenedRainmeterIni` est pure (pas d'accès au store). Test de cohérence avec `RainmeterStore.SkinsDir()` (Root vide → défaut). Tradeoff assumé : si un store custom Root est utilisé (tests), le `SkinPath` du `.ini` reste le chemin de prod — acceptable, le `.ini` n'est consommé qu'en prod Windows.
- `WriteUserRainmeterIni` retourne `(wrote bool, err)` pour logguer pose vs no-op idempotent côté Windows, et faciliter le test d'idempotence.
- Hook `EnsureUserRainmeterIni func() error` plutôt qu'une interface : un seul appel, signature triviale, cohérent avec le pattern `RainmeterACL func(string) error` déjà en place sur l'Agent.
- `SettingsPath()`/`SettingsDir()` du store conservés (utilisés pour CIBLER la suppression du résiduel) bien que « obsolètes côté écriture » — ils nomment le `Rainmeter.ini` à retirer.
- `fakeRainmeterOps` étendu d'un hook `onLaunch` (nil-inerte) pour tracer l'ordre `.ini` puis `launch` dans un test concurrent sans data-race.

**Points d'attention review** :
- La directive `SkinPath` est codée sur le chemin de prod (pas résolu du store dans le `.ini`). AC2 demande « pas codé en dur dans la mesure du possible » — c'est dérivé des constantes mais figé au chemin de prod car la fonction est pure. À challenger si on veut un `.ini` par-store.
- AC4 (e2e : plus de modale, skin chargée) est **non vérifiable sur l'hôte** — ACTION HUMAINE lab Windows (Scénario 21.1). Couvert par la doc QA, pas par un test automatisé.

## File List

- `agent/shared/rainmeter.go` — `hardenedSkinPath()` + `SkinPath` dans `BuildHardenedRainmeterIni` ; helpers purs `BuildUserRainmeterIniBytes` + `WriteUserRainmeterIni` (per-user, atomique, idempotent, writable).
- `agent/shared/rainmeter_provision.go` — `ensureRainmeterConfig` : retrait de l'écriture du `.ini` ProgramData, ajout de la suppression idempotente du résiduel (commentaires/doc mis à jour).
- `agent/shared/companion.go` — champ `EnsureUserRainmeterIni func() error` + appel gracieux dans `Run` avant le `Watchdog.Tick()` anticipé.
- `agent/shared/version.go` — version `2.2.8` → `2.2.9` + entrée de lignée.
- `agent/windows/rainmeter_windows.go` — primitive `ensureUserRainmeterIni(log)` (résout `%APPDATA%\Rainmeter\Rainmeter.ini`, délègue à `shared.WriteUserRainmeterIni`) ; doc `Launch` actualisée (mode installé).
- `agent/windows/companion_windows.go` — câblage `EnsureUserRainmeterIni` sur la construction du `Companion`.
- `agent/shared/rainmeter_test.go` — tests `SkinPath`, `BuildUserRainmeterIniBytes`, `WriteUserRainmeterIni` (writable/atomique/idempotent/réimposition).
- `agent/shared/rainmeter_provision_test.go` — assertions inversées (plus de `.ini` ProgramData) + `TestSyncRainmeterTool_ResidualProgramDataIniRemoved`.
- `agent/shared/companion_test.go` — `TestCompanionRunWritesUserRainmeterIniBeforeWatchdog` + `TestCompanionRunUserRainmeterIniFailureGraceful` (+ imports `errors`/`sync`).
- `agent/shared/watchdog_test.go` — hook `onLaunch` (nil-inerte) sur `fakeRainmeterOps`.
- `docs/qa/domains/agent.md` — Section 21 (mode installé, scénarios 21.1–21.3, append-only).

## Change Log

- 2026-06-16 — Story 27.1ter implémentée (Rainmeter mode installé, settings per-user writable). Build Windows + `go test ./...` + `-race` verts. Version agent 2.2.9. Status → review.

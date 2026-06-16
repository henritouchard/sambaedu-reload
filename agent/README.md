# Agent SambaEdu desired-state — code client (Epic 24)

Ce dossier contient le code de l'**agent poste** du canal desired-state (le
successeur des GPO, Epics 23/24). Il vit **hors du code Laravel** (`app/` =
serveur uniquement) et constitue un **module Go autonome** (`agent/go.mod`,
module `sambaedu/agent`) :

```
agent/
├── README.md      ← ce fichier : décision techno (gate résolu) + contrats
│                    locaux du poste + build/install
├── go.mod         ← module Go autonome (stdlib + golang.org/x/sys +
│                    golang.org/x/text uniquement)
├── shared/        ← cœur OS-AGNOSTIQUE (contrainte n° 5) : StateHasher Go
│                    (miroir bit-à-bit du PHP 23.1, tests croisés golden
│                    files), parsing contrat v1, rapport, client HTTP
│                    (rotation D5, grâce, quarantaine), cache atomique
│                    (machine + per-SID + assets + drops), boucle/backoff,
│                    moteur de convergence §5 (engine.go), compagnon de
│                    session (companion.go), fetch de session + sync assets
│                    + collecte des drops, composition overlay (sérialiseur
│                    fixe) + handler overlay, log local — 100 % testé sur
│                    l'hôte Linux (`go test ./...`)
├── windows/       ← spécifique Win32 (suffixe _windows) : main + service
│                    SYSTEM (x/sys/windows/svc), sous-commandes install/
│                    uninstall/run/session-fetch/companion/version, ACL
│                    icacls, UUID SMBIOS, énumération WTS des sessions,
│                    handler wallpaper (registre HKCU + SystemParametersInfoW
│                    en FFI sans cgo), tâches planifiées at-logon
│                    (Register-ScheduledTask, shell-out admis)
└── build/         ← build statique cross-compilé + signature Authenticode
                     (build.sh, osslsigncode — sortie : build/dist/, non
                     versionnée)
```

Le contrat wire (serveur ⇄ agent) est **figé** côté serveur :
`docs/agent/contract-v1.md` + golden files `tests/Fixtures/Agent/*.v1.json`.
Rien ici ne le redéfinit — `shared/` ne fait que le consommer, et les tests Go
lisent les golden files **en place** (`../tests/Fixtures/Agent/`, jamais
copiés — NFR13 : un seul jeu de golden files, serveur ET agent).

> **Note transition (SOLDÉE en 24.6)** : la totalité du spike PowerShell
> 24.2-24.4 est **remplacée** par ce binaire Go — **zéro `.ps1` agent au
> repo** (critère du sprint-change-proposal 2026-06-12, pas d'état
> transitoire). Le compagnon de session, les handlers wallpaper/overlay, le
> moteur §5 et la collecte des drops sont portés en Go (Story 24.6) ; les
> tâches planifiées PS héritées du spike sont désenregistrées par
> `agent.exe install`. La casse temporaire du chemin de session PS en lab
> (24.5) est résorbée.

---

## Décision technologique : Go (gate résolu — Story 24.5)

**Choix : Go** — binaire statique unique, cross-compilé depuis l'hôte Linux,
signé Authenticode. Décision actée le 2026-06-11 (mémoire
`project_agent_runtime_go`), formalisée dans l'addendum 2026-06-12 de
`architecture-agent-desired-state.md`, implémentée ici. Le gate techno
(ex-PoC P2, « décision reportée ») est **résolu** : le spike PowerShell 24.2
a dérisqué la boucle réseau et le modèle 2-process, ses décisions de design
sont REPRISES par le binaire Go ; seule la techno change.

Évaluation contre le cahier des **7 contraintes** :

| # | Contrainte | Go (binaire statique) |
|---|---|---|
| 1 | Service SYSTEM + compagnon par session | ✅ natif : `golang.org/x/sys/windows/svc` (package canonique de l'équipe Go) — le binaire parle le protocole SCM directement, plus de wrapper ServiceBase compilé à l'install comme en 24.2. Compagnon de session : MÊME binaire, sous-commandes `session-fetch` (SYSTEM) + `companion` (user, résident) — livré 24.6. |
| 2 | Signable Authenticode | ✅ le PE produit par `go build` se signe (`osslsigncode` depuis l'hôte Linux) et se vérifie au build. |
| 3 | Fichiers sous ACL SYSTEM | ✅ icacls (convention 23.3 conservée) ; le binaire lui-même est compilé — plus de script en clair éditable pour le cœur (la contrainte 3 disqualifiait précisément les `.ps1` du spike). |
| 4 | Auto-update fiable | ✅ swap d'un exe versionné unique — LA raison de sortir de PowerShell avant l'Epic 25 (remplacement de scripts à chaud trop fragile pour ~600 postes). |
| 5 | Cœur partageable cross-OS | ✅ `shared/` est 100 % OS-agnostique, compilé/testé sur Linux ; le spécifique Win32 est isolé derrière build tags. |
| 6 | Zéro runtime exotique sur le poste | ✅ binaire STATIQUE (`CGO_ENABLED=0`) : rien à installer, aucun runtime — vieux Windows 10 inclus. La toolchain Go n'existe que sur les machines de build (serveur SE5 via `scripts/build-agent.sh`, hôte de dev), jamais sur les postes. |
| 7 | Empreinte discrète | ✅ ~7 Mo, un seul processus, poll HTTP + actions locales. |

**Règle Rust / COM-WinRT** (addendum architecture) : Windows iso-legacy =
Win32 plat (registre, `SystemParametersInfo`, spawn de process) où Go n'a
aucun handicap. **Réévaluer Rust uniquement si l'Epic 27 entre dans
COM/WinRT** (IShellLink, Task Scheduler COM, PackageManager WinRT).
L'échappatoire intermédiaire admise = **shell-out PowerShell depuis l'agent
Go** — utilisée ici pour l'UUID SMBIOS (`Get-CimInstance
Win32_ComputerSystemProduct`, iso-24.2) et les ACL (`icacls.exe`).

**Dépendances** (contrainte 6 — zéro dépendance exotique) : stdlib +
`golang.org/x/sys` (protocole SCM/Windows, WTS, registre) +
`golang.org/x/text` (normalisation Unicode NFC — le serveur émet NFC,
Windows peut produire du NFD : toute comparaison réel/cible de CHAÎNES dans
les handlers normalise avant ; annoncée par 24.5 « le moment venu », ajoutée
en 24.6 — module de l'équipe Go, même niveau de confiance que x/sys)
**uniquement**. Pas de framework HTTP, pas de lib de config, pas de lib de
logging, pas de go-smbios (le shell-out PowerShell suffit et reproduit la
source exacte du spike). NB : le StateHasher, lui, ne normalise RIEN (forme
canonique figée — hash octet à octet).

---

## Toolchain & commandes (build nominal = le serveur SE5 ; dev/tests = l'hôte)

```bash
# Dev/tests sur l'hôte : toolchain user-local, sans sudo — go1.26.4
#   tarball officiel go.dev extrait dans ~/go-toolchain/
#   (sur le serveur : /usr/local/go, amorcée par scripts/build-agent.sh,
#    version + SHA-256 épinglés dans le script)
export PATH=$HOME/go-toolchain/go/bin:$PATH

cd agent/
go test ./...                                   # tests (hôte Linux, golden files croisés)
go vet ./...
CGO_ENABLED=0 GOOS=windows GOARCH=amd64 go build ./...   # validation cross du code Windows
GOOS=windows GOARCH=amd64 go vet ./...

# Build de production signé — chemin NOMINAL : sur le serveur SE5, automatique
# via scripts/update.sh (ensure_codesign_pfx + ensure_agent_build), ou à la main :
sudo scripts/build-agent.sh        # [--force] — PFX/toolchain/osslsigncode gérés
# → agent/build/dist/sambaedu-agent-<version>.exe (signé CA interne, vérifié)

# Build manuel depuis une autre machine (cf. §Signature pour rapatrier le PFX) :
CODESIGN_PFX=… CODESIGN_CA=… [CODESIGN_PASS=…] agent/build/build.sh
# Build de dev non signé (JAMAIS déployable) :
ALLOW_UNSIGNED=1 agent/build/build.sh
```

`go test ./...` est le gate de la story (pas de CI Go dans le repo) : tout
`shared/` est testable sans Windows — StateHasher contre les golden files
(hash figé `6c0e8135…`), parsing contrat, rotation/grâce/quarantaine/backoff
et fetch de session/assets via `httptest` (zéro réseau réel), machine
d'états §5 table-driven, validation des drops, composition overlay (golden
byte-compatible du sérialiseur PS 24.4). Le code Windows-only (WTS,
registre, FFI, tâches) se valide par cross-compile + `go vet` +
installation lab.

**Version** : `agent_version = "2.1.0"` (binaire COMPLET 24.6 — compagnon +
handlers ; 2.0.0 = core-only 24.5 ; rupture d'artefact vs lignée PS `1.0.0`
— les rapports se discernent en lab). Source unique : `shared/version.go`
(`shared.Version`), injectable au build
(`-ldflags -X sambaedu/agent/shared.Version=…`), reprise par le nommage de
l'artefact (`sambaedu-agent-<version>.exe`).

---

## Contrats locaux du poste (chemins, formats — INCHANGÉS depuis 23.3/24.2)

| Élément | Chemin | Notes |
|---|---|---|
| **Token agent** | `C:\ProgramData\SambaEdu\Agent\token` | **CONTRAT FIGÉ 23.3** (`docs/agent/enrollment.md` §3) : 64 hex, sans newline, ACL SYSTEM + Administrators (icacls `*S-1-5-18` / `*S-1-5-32-544`). Relu sur disque **à chaque cycle** (la rotation peut le changer). Ne jamais hardcoder ailleurs. Purge sysprep obligatoire. |
| Config locale | `C:\ProgramData\SambaEdu\Agent\config.json` | `{"server_url": "...", "interval_seconds": 3600}` — posée par `agent.exe install` (format 24.2 conservé). Cadence configurable (D7 : défaut 3600 s, jitter ±10 % appliqué par l'agent). |
| Cache état cible | `C:\ProgramData\SambaEdu\Agent\cache\state.json` | Enveloppe `se5.desired-state/v1` **brute** du dernier `GET /state` 200. Écriture atomique (tmp PID + rename), ACL SYSTEM. |
| Cache ETag | `C:\ProgramData\SambaEdu\Agent\cache\etag.txt` | Header `ETag` stocké **VERBATIM** (guillemets RFC 7232 inclus), renvoyé tel quel en `If-None-Match`. Tout trim/déquotage brise le 304. |
| Dernier-appliqué MACHINE | `C:\ProgramData\SambaEdu\Agent\applied-state.json` | Créé/préservé **vide** (`{}`) — réservé aux FUTURS handlers de portée machine (les deux types 24.6, wallpaper et overlay, sont de scope session : dernier-appliqué **per-user**). Jamais écrasé s'il existe. |
| Cache de session | `C:\ProgramData\SambaEdu\Agent\cache\sessions\<SID>\{state.json, etag.txt}` | Écrit par le fetch SYSTEM (`session-fetch` at-logon + cycle du service, in-process). ETag **DU contexte** (poste, user), verbatim. ACL à la création : SYSTEM F, Admins F, `<SID>:(OI)(CI)R` — le user LIT le sien, rien d'autre. |
| Cache d'assets | `C:\ProgramData\SambaEdu\Agent\assets\<filename>` | Téléchargé par SYSTEM via **GET statique SANS token** sur l'Alias Apache `GET /assets/wallpaper/<filename>` (hors PHP-FPM, calque 27.7), SHA-256 vérifié AVANT écriture, content-addressed (jamais re-téléchargé). ACL : SYSTEM F, Admins F, `BUILTIN\Users:(OI)(CI)R`. Pas de purge (volume borné par la biblio, noté). |
| Cache d'icônes raccourci | `C:\ProgramData\SambaEdu\Agent\icons\<sha256>.ico` | **Story 27.7** — icônes UPLOADÉES content-addressed. Téléchargé par SYSTEM via **GET HTTP simple SANS token** (`<server_url>/assets/shortcut-icons/<sha>.ico`, Alias Apache statique), SHA-256 vérifié AVANT écriture, content-addressed (jamais re-téléchargé). ACL identique aux assets (Users:R). L'`IconLocation` du `.lnk` pointe sur ce fichier local. |
| Drop session | `C:\ProgramData\SambaEdu\Agent\reports\sessions\<SID>\session-report.json` | Écrit par le COMPAGNON après chaque passe (sa seule écriture hors profil), collecté + **validé strictement** par le service au cycle → items réels du `POST /report`. ACL : SYSTEM F, Admins F, `<SID>:(OI)(CI)M`. |
| Profil user | `%LOCALAPPDATA%\SambaEdu\Agent\{applied-state.json, overlay.json, companion.log}` | Écritures du compagnon : dernier-appliqué per-user (§5), façade overlay (le render lit), log compagnon (format/rotation iso agent.log). |
| Logs | `C:\ProgramData\SambaEdu\Agent\logs\agent.log` | Format `[ISO 8601] [LEVEL] message`, rotation quotidienne (`agent-YYYY-MM-DD.log`), rétention 7 jours — iso-24.2, implémenté en Go sans lib externe. |

### Hostname court (defer review 24.1 #8 — conservé en Go)

Le rapport (`workstation.hostname`) porte le **nom court** du poste —
`os.Hostname()` sous Windows retourne le computer name (jamais le FQDN),
vérifié + fallback `COMPUTERNAME`. Le serveur compare ce champ à
`workstations.name` et loggue `agent.report.identity_mismatch` à chaque
divergence.

### UUID

`workstation.uuid` = UUID SMBIOS via shell-out PowerShell
(`Get-CimInstance Win32_ComputerSystemProduct`, iso-24.2 — échappatoire
admise par l'addendum architecture). Envoyé **verbatim** (normalisation
minuscules côté serveur) ; vide admis (champ déclaratif, l'identité réelle
est le token) ; placeholder firmware (tout-0/tout-F) tracé en WARNING local.

### Comportements réseau (résumé — détail dans `docs/agent/agent-skeleton.md`)

- **Rotation D5** : header `X-Agent-New-Token` lu sur **toute** réponse (GET
  200 **et** 304, POST même non-200) → écriture **atomique** du nouveau token
  (ACL posée avant rename), ancien token gardé **en mémoire** pour la fenêtre
  de grâce (jamais de `token.previous` sur disque). 401 après rotation → un
  réessai avec l'ancien token, puis **durcissement deux-acteurs** (relecture
  du token sur disque, réessai unique s'il diffère). 401 irrécupérable =
  **arrêt + log local, JAMAIS de re-enrôlement automatique**.
- **403 `AGENT_QUARANTINED`** : check-ins légers — `GET /state` continue à
  cadence normale (le serveur surveille la présence pour lever la
  quarantaine), plus aucun `POST /report` ni traitement d'état ; **levée
  automatique** au premier 200/304.
- **Serveur injoignable / 5xx / 429** : l'agent vit sur son cache, backoff
  exponentiel 30 s → 60 s → … plafonné à la cadence normale (3600 s).
- **Major inconnu** (`se5.desired-state/v2`) : log erreur, cache **préservé**,
  check-ins maintenus à cadence normale (contrat §9).
- **X-Agent-Hostname** (nom court) sur chaque appel (anti-clonage 23.2) ;
  `X-Agent-Mac` volontairement non envoyé (multi-NIC → faux positif
  quarantaine, décision 24.2 maintenue).
- **TLS** : magasin de certificats système (la racine CA interne est déployée
  par iPXE 23.3) ; timeout 30 s par requête.

### Compagnon de session & handlers (Go — Story 24.6)

Le sous-système compagnon est porté en Go dans CE binaire — vue d'ensemble
dans `docs/agent/session-companion.md` et
`docs/agent/handlers-wallpaper-overlay.md` (séquences, ACL et conventions
inchangées par le portage) :

- **`agent.exe session-fetch`** (tâche `SambaEduAgent-SessionFetch`, SYSTEM,
  At log on, limite 10 min) : énumère les sessions interactives (WTS, liste
  blanche `S-1-5-21-` + login non vide), `GET /state?user=<login court>`
  avec l'ETag DU contexte via le MÊME client HTTP (rotation D5/grâce/
  deux-acteurs), cache per-SID, puis sync des assets. Le cycle du service
  rejoue le même code **in-process** après la portée machine.
- **`agent.exe companion`** (tâche `SambaEduAgent-SessionCompanion`, groupe
  `BUILTIN\Users`, At log on, **sans limite — résident délibéré** : poll du
  mtime du cache ~60 s + re-test périodique ~5 min) : NI réseau NI token
  (frontière NFR5) — lit son cache per-SID (poll borné 2 s/60 s au
  démarrage, fallback dernier cache, sinon attente résidente), partition
  `session` + `machine_user` SEULEMENT, moteur §5, handlers wallpaper
  (HKCU + `SystemParametersInfoW` FFI), overlay (`overlay.json` per-user,
  sérialiseur fixe, Rainmeter absent = gracieux) et **shortcuts** (Story
  27.1), applied-state per-user, drop per-SID après chaque passe.
- **Handler `shortcuts`** (aggregate / `machine_user` — Story 27.1, fix Bug C) :
  pose/retire les `.lnk` au chemin **résolu côté serveur** (`desktop_path`
  réseau ou local selon `WorkstationEnvironment` du parc). Création **COM
  IShellLink en Go natif** (`handler_shortcuts_windows.go` — pas de shell-out
  PowerShell, zéro dépendance ajoutée : COM via `golang.org/x/sys/windows` +
  syscall vtable). Logique pure (résolution du set cible, décision test/apply,
  level-triggered) dans `shared/handler_shortcuts.go` (testée hôte). Convergence
  **level-triggered, jamais d'accumulation** : un raccourci sorti des règles est
  supprimé ; un `.lnk` créé par l'utilisateur (sans le **marqueur de gestion**
  écrit dans le champ Description, `shared.ShortcutManagedMarker`) n'est **jamais**
  touché. Les tokens serveur (`<user>` → `%USERNAME%`, `<se4fs>` → serveur de
  fichiers) sont substitués **localement**.
  - **Icône UPLOADÉE → asset statique (Story 27.7)** : quand l'item porte
    `{icon_asset, icon_checksum}` (le provider l'émet pour une icône uploadée,
    nom nu), le SERVICE SYSTEM **pré-télécharge** l'icône via un **GET HTTP
    simple SANS token** sur l'Alias Apache statique
    `server_url + "/assets/shortcut-icons/" + icon_asset` (URL **dérivée**, pas
    de champ `url` — décision 24.4), vérifie le **SHA-256 AVANT écriture**, la
    dépose content-addressed sous `C:\ProgramData\SambaEdu\Agent\icons\<sha>.ico`
    (ACL Users:R, `SyncShortcutIcons` dans `shared/icon_assets.go`, qui partage
    avec `SyncWallpaperAssets` le helper de download statique `getStatic` — depuis
    le passage du wallpaper en Alias statique, les deux suivent le même transport).
    Le compagnon pointe alors
    l'`IconLocation` du `.lnk` sur ce **fichier local**. **Convergence
    gracieuse** : `.ico` local absent / checksum KO ⇒ raccourci posé **sans
    IconLocation** (icône défaut), drift + re-sync au cycle suivant, JAMAIS une
    icône « feuille blanche ». Idempotent (content-addressed). Le cas **icône
    réelle** (`firefox.exe,0`, sans `icon_asset`) reste géré par
    `ParseIconLocation` (inchangé). Backfill serveur :
    `php artisan shortcuts:backfill-icons`.
- **Handler `printers`** (aggregate / `session` — Story 27.2) : installe/retire
  les **connexions imprimante réseau** (partage Samba `\\<se4fs>\<cups_name>`,
  connexion logique résolue serveur) et pose l'imprimante **par défaut** sur
  l'item `is_default` (résolu serveur : WG physique > logique). API Win32
  **winspool en Go natif** (`handler_printers_windows.go` — `AddPrinterConnection`
  / `DeletePrinterConnection` / `SetDefaultPrinter` / `EnumPrinters`, pas de
  shell-out, zéro dépendance ajoutée). Logique pure (set cible, défaut,
  level-triggered, isolation) dans `shared/handler_printers.go` (testée hôte).
  **Marqueur de périmètre** = serveur SambaEdu (`<se4fs>` résolu localement) :
  seules les connexions vers CE serveur sont gérées ; une imprimante installée
  par l'utilisateur n'est **jamais** désinstallée. Serveur d'impression
  injoignable → statut `error` isolé (les autres types continuent), retry au
  cycle suivant.
- **Handler `drives`** (aggregate / `session` — Story 27.2) : monte/démonte les
  **lecteurs réseau** (lettre → UNC des classes du user, projection MVP-A). API
  Win32 **mpr en Go natif** (`handler_drives_windows.go` — `WNetAddConnection2`
  / `WNetCancelConnection2` / `WNetGetConnection`, pas de shell-out `net use`).
  Logique pure (set cible lettre→UNC, level-triggered) dans
  `shared/handler_drives.go` (testée hôte). **Marqueur de périmètre** = serveur
  SambaEdu : un lecteur monté par l'utilisateur (vers un autre serveur, ou une
  lettre cible déjà occupée par un montage user) n'est **jamais** démonté ni
  écrasé. Tokens `<se4fs>`/`<login>` substitués **localement**.
- **Rendu overlay VERROUILLÉ (Story 27.1bis)** — l'agent gère le **cycle de vie
  du rendu** (Rainmeter), pas seulement la donnée :
  - **Provisioning portable au bootstrap** (SERVICE SYSTEM, `SyncRainmeterTool`
    dans `shared/rainmeter_provision.go`, calqué `SyncWallpaperAssets`) : download
    de l'archive PORTABLE via la route **dédiée** `GET /api/v1/agent/tools/<filename>`
    (≠ `/releases`, réservé au binaire agent), **SHA-256 vérifié AVANT extraction**,
    extraction `archive/zip` (anti zip-slip) sous `C:\ProgramData\SambaEdu\Rainmeter\`,
    ACL Users:R. Install-if-absent **idempotent**. **Jamais** par un handler runtime,
    **jamais** MSI/NSIS/winget, zéro registre (mode portable).
  - **Catalogue de tools serveur (Story 25.6)** — le portable ET la skin sont
    désormais des **assets gérés serveur, toggleables depuis l'UI**, plus un
    artefact déposé à la main + une skin embarquée. L'agent lit un **manifest
    dédié** `GET /api/v1/agent/tools-manifest` (`{tool:{key,filename,sha256,size},
    skin:{filename,sha256}}`) : le SHA-256 du portable vient de l'**état servi**
    (catalogue `agent_tools`, plus la constante Go `RainmeterToolChecksum`
    RETIRÉE) ; tool absent/désactivé (`tool: null`) → **no-op gracieux** (pas de
    désinstallation — D4). La **skin est TÉLÉCHARGÉE** (vérif SHA-256 avant
    écriture) via `GET /api/v1/agent/overlay-skin` (route agent token'd, pas
    d'alias public) — **l'embed `go:embed` est SUPPRIMÉ** (`rainmeter_embed.go`,
    `embedded/`, son test de non-divergence) : une retouche de skin n'exige plus
    de recompiler l'agent.
  - **`overlay.json` écrit par le SERVICE SYSTEM au logon** (`overlay_logon_windows.go`)
    — abonnement session-change (`svc.AcceptSessionChange` + `case SessionChange`
    sur `WTS_SESSION_LOGON`), résolution user/profil de session via le token
    (`WTSQueryUserToken` + `GetUserProfileDirectory`, jamais l'AD — NFR7),
    composition via `shared.OverlayDocumentForSession` (réutilise
    `ComposeOverlayDocument` à l'identique — golden inchangé), écriture atomique
    possédée SYSTEM + **ACL `<SID>:R`** (`setOverlayFileACL`) : infalsifiable
    (NFR5). **Logon-only** (Q1=B : pas de re-write périodique). L'overlay a
    **quitté la map du compagnon** (D1). Fallback documenté
    `%ProgramData%\SambaEdu\overlay.json` si le profil n'est pas résoluble.
  - **Verrouillage** : skin posée **UTF-16 LE + BOM** (`ToUTF16LEWithBOM`, sinon
    mojibake `Â·`) + `Rainmeter.ini` durci (`BuildHardenedRainmeterIni` :
    `TrayIcon=0` / `Draggable=0` / `ClickThrough=1` / `KeepOnScreen=1`) sous
    ProgramData ACL Users:R ; **watchdog** côté compagnon
    (`shared/watchdog.go` + `rainmeter_windows.go`) relance `Rainmeter.exe` s'il
    disparaît (idempotent, borné, meurt au logoff). **Pas d'obfuscation** (D7).
- L'IPC named-pipe service ⇄ session reste écarté (modèle fichier per-SID
  validé par les spikes et les reviews) — à réévaluer à l'Epic 27.
- Quarantaine : pas de fetch de session ; le compagnon converge sur son
  dernier cache (level-triggered, inoffensif — limitation MVP documentée).

---

## Signature Authenticode (NFR6)

Tout artefact déployé est **signé** : non signé = SmartScreen/politique
d'exécution bloquent. Le build (`agent/build/build.sh`) signe le PE avec
`osslsigncode` **depuis l'hôte Linux** (le binaire est cross-compilé ;
`Set-AuthenticodeSignature` exigerait Windows) et **vérifie la signature**
après coup (`osslsigncode verify -CAfile <racine>`).

**Matériel de signature** : la PKI interne vit sur le serveur SE5
(`storage/keys/pki/ca-root.{crt,key}` — story 16.10) ; sa racine est déployée
sur les postes par la chaîne iPXE 23.3. Le certificat **code signing** (EKU
`1.3.6.1.5.5.7.3.3`) est émis par cette CA et exporté en **PFX sans mot de
passe** par `scripts/emit-codesign-pfx.sh` — **appelé automatiquement par
`scripts/update.sh`** (`ensure_codesign_pfx`, donc aussi par `install.sh` qui
rejoue update.sh) : no-op si le PFX existe, valide > 30 j et chaînant vers le
ca-root courant ; ré-émission forcée si la CA vient d'être régénérée. Dans la
foulée, **`ensure_agent_build` (→ `scripts/build-agent.sh`) builde le binaire
signé sur le serveur même** (toolchain Go épinglée + osslsigncode amorcés au
premier passage, no-op si dist/ est à jour) : le PFX ne quitte jamais le
serveur. Le rapatriement ci-dessous ne sert qu'à un build manuel ailleurs :

```bash
# Sur le serveur SE5 (la clé CA ne quitte jamais le serveur) :
sudo scripts/emit-codesign-pfx.sh            # [--force pour ré-émettre]
# → storage/keys/pki/sambaedu-codesign.pfx
# Rapatrier le PFX sur la machine de build (hors repo !), puis :
scp root@<serveur>:/var/www/sambaedu-reload/storage/keys/pki/sambaedu-codesign.pfx ~/
CODESIGN_PFX=~/sambaedu-codesign.pfx CODESIGN_CA=<ca-root.crt> agent/build/build.sh
```

Le PFX/la clé privée ne se committent **jamais** dans le repo (`*.pfx` est
gitignoré). `osslsigncode` s'installe sans privilèges :
`apt-get download osslsigncode && dpkg -x osslsigncode_*.deb ~/.local/opt/osslsigncode`.

## Installation lab (manuelle — la distribution automatique est l'Epic 25)

> Publication serveur (releases, rings, manifest — Story 25.1) : voir
> `docs/agent/release-distribution.md`.

```powershell
# Sur le poste (admin), poste déjà enrôlé (token 23.3) :
# 0. si le service PS du spike est encore là : .\Uninstall-SambaEduAgent.ps1 (bundle 24.2-24.4)
# 1. copier le binaire signé :
mkdir 'C:\Program Files\SambaEdu\Agent' -Force
copy sambaedu-agent-2.1.0.exe 'C:\Program Files\SambaEdu\Agent\agent.exe'
# 2. installer : service SYSTEM (auto, relance 30 s) + 2 tâches at-logon
#    (SessionFetch SYSTEM + SessionCompanion Users — les tâches PS homonymes
#    du spike sont désenregistrées au passage) :
& 'C:\Program Files\SambaEdu\Agent\agent.exe' install -server-url 'http://<serveur-se5>'
# Vérifier : Get-Service SambaEduAgent ; Get-ScheduledTask SambaEduAgent-* ;
#   log C:\ProgramData\SambaEdu\Agent\logs\agent.log ;
#   log compagnon (après un logon) %LOCALAPPDATA%\SambaEdu\Agent\companion.log
# Signature : Get-AuthenticodeSignature 'C:\Program Files\SambaEdu\Agent\agent.exe' → Valid
# Prérequis DÉMO overlay (≤ 27.1) : Rainmeter installé manuellement (NSIS /S).
# Story 27.1bis : Rainmeter PORTABLE posé par l'AGENT au bootstrap (route
#   /api/v1/agent/tools/, SHA-256 vérifié) — déposer l'artefact sur la VM sous
#   storage/agent/tools/ (chown www-admin) + figer son hash dans la constante
#   RainmeterToolChecksum (agent/shared/rainmeter.go). overlay.json est alors
#   écrit par le SERVICE SYSTEM au logon (ACL <SID>:R) — cf. resources/overlay/README.md.
# Désinstaller (service + tâches ; token/cache/logs conservés) : agent.exe uninstall
#   (-purge pour tout effacer)
# Debug console : agent.exe run   (Ctrl-C pour arrêter)
```

## Auto-update (Story 25.2)

L'agent se met à jour seul depuis le manifest serveur (Story 25.1 —
`docs/agent/release-distribution.md`), **sans jamais briquer le parc**.
`shared.SelfUpdate` est appelé en **fin de cycle machine** (iso
`SyncWallpaperAssets`, sous garde `!quarantined`, avant le `POST /report`).

**Déclenchement (égalité stricte, PAS semver).** Le manifest dit
autoritairement « voici la version que ce poste DOIT avoir » (résolue par ring
côté serveur). L'agent applique `manifest.version != shared.Version` → update —
même un **downgrade** (rollback décidé serveur). Pas de comparateur de récence
côté agent : le serveur est l'autorité. `manifest 404 no_release` = rien à faire
(no-op silencieux) ; `401` = la portée machine arrêtera le service
(re-enrôlement MANUEL) ; `403` sur le **canal release** (manifest OU download) =
**update sauté** ce cycle, **PAS de quarantaine globale** (M4, Option 1) : le
poste continue son cycle et **envoie son report** (il reste visible sur sa
conformité). La quarantaine globale — qui, elle, supprime le `POST /report` —
reste réservée au `403` du canal principal `/state`.

**Double vérification, dans l'ordre (deux portes successives).**
1. **SHA-256** du corps téléchargé == `hash` du manifest **AVANT toute
   écriture** (pattern `assets.go`) — divergent → binaire **jeté, rien écrit**,
   retry au prochain cycle.
2. **Signature Authenticode** du fichier **stagé** (sous `ProgramData\…\update\`)
   **AVANT tout swap** — invalide/non-confiance → **jeté, aucun swap**.

Un download corrompu/non-signé n'atteint **jamais** le swap.

**Choix Authenticode : `WinVerifyTrust` (in-process, `golang.org/x/sys/windows`).**
On utilise `WinVerifyTrustEx` + `WINTRUST_ACTION_GENERIC_VERIFY_V2` (déjà au
`go.mod`, **aucune dépendance neuve**) plutôt qu'un shell-out
`Get-AuthenticodeSignature`. Rationale : code de retour **binaire** (`nil` =
signé + chaîne de confiance valide jusqu'à une CA de confiance machine ; tout
autre = rejet), pas de parsing de chaîne localisée fragile en locale FR, pas de
spawn de `powershell.exe`. La CA interne SE5 est déployée par l'install
(racine de confiance machine, brief #31). Le shell-out reste l'échappatoire
documentée si besoin terrain (`agent/windows/update_windows.go`).

**Swap atomique anti-brique** (cœur `shared.PerformSwap`, wrapper Windows
`swapAndRestart`) :

```
(pré) copier ATOMIQUEMENT le binaire stagé À CÔTÉ de la cible (agent.exe.new,
      tmp+rename même volume que Program Files — staged vit sous ProgramData,
      volume potentiellement distinct → rename cross-device impossible ; M1)
(re)  RE-HASHER agent.exe.new == hash manifest        (M2 : le binaire qui sera
        exécuté repasse la porte d'intégrité à SA position finale)
        si divergent -> cleanup .new + abort (agent vN intact)
(a)   rm agent.exe.old (résidu)
(b)   agent.exe -> agent.exe.old        (rename OK même IMAGE VERROUILLÉE)
(c)   agent.exe.new -> agent.exe        (rename ATOMIQUE même volume)
        si (c) KO -> rollback (b) inverse, abort (agent vN intact)
(d)   SORTIE NON-GRACIEUSE os.Exit(≠0)  (Option A) → la recovery SCM relance
        le binaire vN+1 (le process meurt SANS signaler SERVICE_STOPPED → le
        SCM voit une terminaison anormale → ServiceRestart ×3)
(e)   au boot vN+1 : rm agent.exe.old   (cleanupOldBinary, best-effort)
```

**Redémarrage = sortie non-gracieuse + recovery SCM (Option A).** Après un swap
réussi, l'agent **provoque sa propre sortie** (`os.Exit(swapExitCode)`, code ≠ 0)
au lieu de tenter un stop+start in-process (l'ancien mécanisme `restartService`
était structurellement cassé : un service ne peut pas s'arrêter+redémarrer
depuis sa propre goroutine sans deadlock). La recovery SCM — `ServiceRestart` ×3,
déjà configurée à l'install + `SetRecoveryActionsOnNonCrashFailures(true)` pour
couvrir une sortie à code non nul — relance le service avec le binaire vN+1.
**Contrepartie** : dans le journal d'événements Windows, cette sortie volontaire
**ressemble à un plantage** (terminaison anormale, code 42) — c'est ATTENDU
(un log local explicite `swap … réussi, sortie volontaire pour relance SCM`
précède la sortie). La **preuve de succès** reste la nouvelle `agent_version`
rapportée par le binaire vN+1 au prochain check-in.

**Invariant** : à aucun instant `agent.exe` n'est absent ou corrompu — entre
(b) et (c) il existe sous `.old`, (c) est atomique. Chaque étape destructive a
son inverse, et `os.Exit` n'est appelé qu'APRÈS un swap réussi (jamais sur une
erreur). Si la copie/re-hash/dépose échoue → abort + rollback, agent vN en place.
Si le restart auto échoue (recovery SCM saturée) → image vN+1 en place mais
service arrêté → le prochain boot ou le bootstrap GPO figé (25.4) le relance.
**Jamais d'état « ni ancien ni nouveau »** (NFR8, l'AC la plus dure).

**Données hors périmètre du swap.** Le swap ne touche QUE `agent.exe`
(`Program Files`) ; le **token** et le cache vivent sous
`C:\ProgramData\SambaEdu\Agent\` — **survivent par construction** (relus après
restart, jamais de ré-enrôlement). La rotation D5 (token tournant) survit aussi.

**Borne 16 Mio.** Le `Client` borne le corps HTTP à 16 Mio (`io.LimitReader`).
Un binaire agent fait ~7 Mo (vérifier `agent/build/dist/`). Un binaire
>16 Mio serait tronqué → SHA-256 divergent → rejeté (fail-safe correct, mais
update bloqué : un binaire si gros signale un problème de build). **Ne pas
relever cette borne sans raison.**

**Résilience.** Un échec ne casse **jamais** le cycle machine (recover + log) ni
le `POST /report` (l'état réel du poste part quand même). **Un seul download par
cycle** (pas de boucle intra-cycle) ; retry au prochain check-in (cadence
`ttl_seconds`, pas de timer dédié). L'échec est **rapporté** via un item
`agent_update` (`status: error` + detail) — ce n'est **PAS** un type de
ressource desired-state (pas de handler/provider serveur), juste un canal de
signalement. Le **succès** ne pose pas d'item : la nouvelle `agent_version` du
rapport EST la preuve de succès (la trace de déploiement qui fait foi —
`download_served` côté serveur = debug). **Zéro dépendance AD** dans le chemin
d'update (critère Keycloak NFR7) : bearer token neuf, url verbatim du manifest.

**Découpage testabilité.** L'orchestration (décision/download/hash/staging) vit
dans `shared/update.go` ; le **cœur anti-brique** (copie-atomique→re-hash→
rename→ROLLBACK) vit dans `shared/swap.go` (`PerformSwap`) — tous deux testés
sur Linux (les renames POSIX se comportent comme Windows pour ce besoin). La
matrice NFR8 (`shared/update_test.go` + `shared/swap_test.go`) couvre le swap
nominal (triggerRestart appelé), le staged absent, le hash `.new` divergent
(M2) et l'échec du rename final (ROLLBACK vérifié : ancien binaire restauré,
triggerRestart JAMAIS appelé). Seules restent côté `windows/update_windows.go`
(`*_windows.go`, non testées en CI Linux — validées au **smoke** sur poste de
lab) les **spécificités OS** : `verifyAuthenticode` (WinVerifyTrust), la
résolution du chemin réel (`os.Executable`), l'ACL du staging, et la sortie
`os.Exit` (injectée dans `PerformSwap` comme `triggerRestart`). Publication
serveur : voir `docs/agent/release-distribution.md` (Story 25.1).

## Versionnement

`agent_version` (`2.1.0` pour le binaire complet 24.6) vit dans
`shared/version.go` (`shared.Version`) — source unique, déclarée dans chaque
rapport, injectable au build, à bumper à chaque release (Epic 25). L'auto-update
25.2 compare `manifest.version` à cette variable (égalité stricte).

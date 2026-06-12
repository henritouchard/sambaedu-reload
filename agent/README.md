# Agent SambaEdu desired-state — code client (Epic 24)

Ce dossier contient le code de l'**agent poste** du canal desired-state (le
successeur des GPO, Epics 23/24). Il vit **hors du code Laravel** (`app/` =
serveur uniquement) et constitue un **module Go autonome** (`agent/go.mod`,
module `sambaedu/agent`) :

```
agent/
├── README.md      ← ce fichier : décision techno (gate résolu) + contrats
│                    locaux du poste + build/install
├── go.mod         ← module Go autonome (stdlib + golang.org/x/sys uniquement)
├── shared/        ← cœur OS-AGNOSTIQUE (contrainte n° 5) : StateHasher Go
│   │                (miroir bit-à-bit du PHP 23.1, tests croisés golden
│   │                files), parsing contrat v1, rapport, client HTTP
│   │                (rotation D5, grâce, quarantaine), cache atomique,
│   │                boucle/backoff, log local — 100 % testé sur l'hôte Linux
│   │                (`go test ./...`)
│   │                + en SURSIS jusqu'à 24.6 : ContractV1.ps1 (consommé par
│   │                le compagnon PS) et ConvergenceEngine.ps1 (24.4)
├── windows/       ← spécifique Win32 (build tags) : main + service SYSTEM
│   │                (x/sys/windows/svc), sous-commandes install/uninstall/
│   │                run/version, ACL icacls, UUID SMBIOS
│   │                + en SURSIS jusqu'à 24.6 : SessionCompanion.ps1,
│   │                SessionStateFetch.ps1 (compagnon de session PS 24.3)
│   └── handlers/  ← en SURSIS jusqu'à 24.6 : Wallpaper.ps1, Overlay.ps1 (24.4)
└── build/         ← build statique cross-compilé + signature Authenticode
                     (build.sh, osslsigncode — sortie : build/dist/, non
                     versionnée)
```

Le contrat wire (serveur ⇄ agent) est **figé** côté serveur :
`docs/agent/contract-v1.md` + golden files `tests/Fixtures/Agent/*.v1.json`.
Rien ici ne le redéfinit — `shared/` ne fait que le consommer, et les tests Go
lisent les golden files **en place** (`../tests/Fixtures/Agent/`, jamais
copiés — NFR13 : un seul jeu de golden files, serveur ET agent).

> **Note transition (24.5 → 24.6)** : le spike PowerShell 24.2 (boucle +
> install/uninstall/build) est **remplacé** par ce binaire Go — ses `.ps1`
> sont retirés du repo (pas d'état transitoire). Les artefacts PS de
> 24.3/24.4 (compagnon de session, handlers, `ContractV1.ps1`,
> `ConvergenceEngine.ps1`) restent jusqu'au portage Go 24.6 ; **attention** :
> `SessionStateFetch.ps1` dot-sourçait `SambaEduAgent.ps1` (retiré) — la
> casse temporaire du chemin de session PS en lab est **assumée** (décision
> story 24.5, la continuité du compagnon PS n'est pas un AC).

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
| 1 | Service SYSTEM + compagnon par session | ✅ natif : `golang.org/x/sys/windows/svc` (package canonique de l'équipe Go) — le binaire parle le protocole SCM directement, plus de wrapper ServiceBase compilé à l'install comme en 24.2. Compagnon de session → 24.6 (même binaire, mode compagnon). |
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
`golang.org/x/sys` (protocole SCM/Windows) **uniquement**. Pas de framework
HTTP, pas de lib de config, pas de lib de logging, pas de go-smbios (le
shell-out PowerShell suffit et reproduit la source exacte du spike).

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
via `httptest` (zéro réseau réel). Le code Windows-only se valide par
cross-compile + `go vet` + installation lab.

**Version** : `agent_version = "2.0.0"` (rupture d'artefact vs lignée PS
`1.0.0` — les rapports Go sont discernables en lab). Source unique :
`shared/version.go` (`shared.Version`), injectable au build
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
| Dernier-appliqué MACHINE | `C:\ProgramData\SambaEdu\Agent\applied-state.json` | Créé/préservé **vide** (`{}`) — infrastructure du mode `default` (gap 1) pour les handlers 24.6. Jamais écrasé s'il existe. |
| Cache de session (24.3, PS) | `C:\ProgramData\SambaEdu\Agent\cache\sessions\<SID>\…` | Conservé tel quel (le compagnon PS le lit jusqu'à 24.6) — le service Go n'y écrit pas en 24.5. |
| Assets / drops (24.4, PS) | `…\assets\`, `…\reports\sessions\<SID>\` | Idem : portage → 24.6. |
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

### Compagnon de session & handlers (PS 24.3/24.4 — portage Go en 24.6)

Le sous-système compagnon (fetch SYSTEM `?user=`, processus user, handlers
wallpaper/overlay) reste documenté dans `docs/agent/session-companion.md` et
`docs/agent/handlers-wallpaper-overlay.md`. Ses `.ps1` sont au repo jusqu'à
24.6 mais **le chemin PS est cassé en lab depuis 24.5** (le retrait de
`SambaEduAgent.ps1` casse le dot-source de `SessionStateFetch.ps1`) — casse
temporaire assumée, pas d'état transitoire maintenu. Le binaire Go 24.6
reprendra la voie cible (broker du service vers les sessions).

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

```powershell
# Sur le poste (admin), poste déjà enrôlé (token 23.3) :
# 0. si le service PS du spike est encore là : .\Uninstall-SambaEduAgent.ps1 (bundle 24.2-24.4)
# 1. copier le binaire signé :
mkdir 'C:\Program Files\SambaEdu\Agent' -Force
copy sambaedu-agent-2.0.0.exe 'C:\Program Files\SambaEdu\Agent\agent.exe'
# 2. installer + démarrer le service (SYSTEM, auto, relance 30 s sur crash) :
& 'C:\Program Files\SambaEdu\Agent\agent.exe' install -server-url 'http://<serveur-se5>'
# Vérifier : Get-Service SambaEduAgent ; log C:\ProgramData\SambaEdu\Agent\logs\agent.log
# Signature : Get-AuthenticodeSignature 'C:\Program Files\SambaEdu\Agent\agent.exe' → Valid
# Désinstaller (token/cache/logs conservés) : agent.exe uninstall   (-purge pour tout effacer)
# Debug console : agent.exe run   (Ctrl-C pour arrêter)
```

## Versionnement

`agent_version` (`2.0.0` pour le core Go) vit dans `shared/version.go`
(`shared.Version`) — source unique, déclarée dans chaque rapport, injectable
au build, à bumper à chaque release (Epic 25).

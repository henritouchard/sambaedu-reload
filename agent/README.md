# Agent SambaEdu desired-state — code client (Epic 24)

Ce dossier contient le code de l'**agent poste** du canal desired-state (le
successeur des GPO, Epics 23/24). Il vit **hors du code Laravel** (`app/` =
serveur uniquement) :

```
agent/
├── README.md      ← ce fichier : décision techno + contrats locaux du poste
├── windows/       ← agent Windows : service SYSTEM (SambaEduAgent.ps1),
│                    compagnon de session 24.3 (SessionStateFetch.ps1 SYSTEM
│                    + SessionCompanion.ps1 droits user), install/uninstall
├── shared/        ← cœur partageable cross-OS : parsing contrat v1, rapport
└── build/         ← build + signature Authenticode (sortie : build/dist/)
```

Le contrat wire (serveur ⇄ agent) est **figé** côté serveur :
`docs/agent/contract-v1.md` + golden files `tests/Fixtures/Agent/*.v1.json`.
Rien ici ne le redéfinit — `shared/ContractV1.ps1` ne fait que le consommer.

---

## Décision technologique (Story 24.2)

**Choix : PowerShell 5.1 (Windows PowerShell, présent sur tout poste Windows)
pour le MVP lab — migration vers un binaire .NET autonome documentée comme
prérequis avant l'Epic 25 (distribution canari / auto-update).**

Évaluation contre les 7 contraintes du cahier des charges :

| # | Contrainte | PowerShell 5.1 (MVP) | .NET self-contained (cible 25.x) | Go |
|---|---|---|---|---|
| 1 | Service SYSTEM | ✅ via wrapper ServiceBase minimal compilé à l'install (un `.ps1` ne parle pas le protocole SCM) | ✅ natif (`UseWindowsService`) | ✅ natif |
| 2 | Signable Authenticode | ✅ `Set-AuthenticodeSignature` sur les `.ps1` | ✅ signtool sur l'exe | ✅ signtool |
| 3 | Fichiers sous ACL SYSTEM | ✅ `icacls` (déjà la convention 23.3) | ✅ | ✅ |
| 4 | Auto-update fiable | ⚠️ faible (remplacement de scripts à chaud fragile) → **c'est LA raison du passage .NET avant 25.x** | ✅ swap d'un exe versionné | ✅ |
| 5 | Cœur partageable cross-OS | ⚠️ partiel (`shared/` isole le contrat, PowerShell Core existe sous Linux mais n'est pas déployé sur le parc) | ✅ .NET cross-platform | ✅ |
| 6 | Zéro runtime exotique | ✅ rien à installer (PS 5.1 + .NET Framework inclus dans Windows) | ✅ si publish self-contained | ⚠️ toolchain Go absent de l'infra SE5 |
| 7 | Empreinte discrète | ✅ ~0 Mo installé, un processus powershell idle | ✅ (~15 Mo self-contained) | ✅ |

**Motivation du MVP PowerShell** : délai court vers la démo palier 1, code du
contrat lisible/reviewable en clair, zéro dépendance à installer sur le poste
lab, signature et ACL identiques aux conventions déjà posées par la chaîne
iPXE 23.3. **Limite assumée** : l'auto-update de scripts PowerShell est trop
fragile pour un parc (~600 postes) — la migration vers un binaire .NET
self-contained (cœur `shared/` porté en bibliothèque) **doit précéder
l'Epic 25**. Go reste possible mais introduirait un toolchain absent de
l'infra SE5 (contrainte 6).

---

## Contrats locaux du poste (chemins, formats)

| Élément | Chemin | Notes |
|---|---|---|
| **Token agent** | `C:\ProgramData\SambaEdu\Agent\token` | **CONTRAT FIGÉ 23.3** (`docs/agent/enrollment.md` §3) : 64 hex, sans newline, ACL SYSTEM + Administrators (icacls `*S-1-5-18` / `*S-1-5-32-544`). Relu sur disque **à chaque cycle** (la rotation peut le changer). Ne jamais hardcoder ailleurs. |
| Config locale | `C:\ProgramData\SambaEdu\Agent\config.json` | `{"server_url": "...", "interval_seconds": 3600}` — posée par `Install-SambaEduAgent.ps1`. La cadence est configurable ici (D7 : défaut 3600 s, jitter ±10 % appliqué par l'agent). |
| Cache état cible | `C:\ProgramData\SambaEdu\Agent\cache\state.json` | Enveloppe `se5.desired-state/v1` brute du dernier `GET /state` 200. ACL SYSTEM + Administrators. |
| Cache ETag | `C:\ProgramData\SambaEdu\Agent\cache\etag.txt` | Header `ETag` stocké **VERBATIM** (guillemets RFC 7232 inclus), renvoyé tel quel en `If-None-Match`. Tout trim/déquotage brise le 304. |
| Dernier-appliqué | `C:\ProgramData\SambaEdu\Agent\applied-state.json` | Créé **vide** (`{}`) dès 24.2 — infrastructure du mode `default` (gap 1 du contrat §5), consommé par les handlers 24.4. |
| Cache de session (24.3) | `C:\ProgramData\SambaEdu\Agent\cache\sessions\<SID>\{state.json,etag.txt}` | Un répertoire **par SID** de session, écrit par le fetch SYSTEM. ETag **du contexte** (poste, user) — jamais celui du cache machine. ACL : SYSTEM F, Administrators F, `<SID>` **lecture seule** (les fichiers héritent). |
| Logs | `C:\ProgramData\SambaEdu\Agent\logs\agent.log` | Format `[ISO 8601] [LEVEL] message`, rotation quotidienne (`agent-YYYY-MM-DD.log`), rétention 7 jours. Trace locale de la boucle — le serveur ne voit que les rapports. Le fetch de session (SYSTEM) logue ICI aussi. |
| Log compagnon (24.3) | `%LOCALAPPDATA%\SambaEdu\Agent\companion.log` | Seule écriture du processus user (profil user, aucune élévation). Même format/rotation que `agent.log` (`companion-YYYY-MM-DD.log`, 7 jours). |

### Hostname court (résolution defer review 24.1 #8)

Le rapport (`workstation.hostname`) porte le **nom court** du poste —
`$env:COMPUTERNAME` — **jamais** le FQDN. Le serveur compare ce champ à
`workstations.name` (nom court d'enrôlement, convention middleware 23.2) et
loggue un warning `agent.report.identity_mismatch` à chaque divergence : un
FQDN spammerait ce warning sur chaque rapport légitime. Contrat documenté côté
serveur dans `docs/agent/agent-skeleton.md`.

### UUID

`workstation.uuid` = UUID SMBIOS :
`(Get-CimInstance Win32_ComputerSystemProduct).UUID`. Envoyé tel quel — la
normalisation en minuscules est faite côté serveur.

### Comportements réseau (résumé — détail dans `docs/agent/agent-skeleton.md`)

- **Rotation D5** : header `X-Agent-New-Token` sur toute réponse (200, 304,
  même 422) → écrire le nouveau token sur disque (écriture atomique + ACL),
  garder l'ancien en mémoire pour la fenêtre de grâce. **Jamais de
  re-enrôlement automatique sur 401** : 401 irrécupérable = arrêt + log,
  intervention admin.
- **403 `AGENT_QUARANTINED`** : check-ins légers seulement — `GET /state`
  continue à cadence normale (le serveur surveille la présence pour lever la
  quarantaine), plus aucun `POST /report` ni traitement d'état.
- **Serveur injoignable / 5xx / 429** : l'agent vit sur son cache, backoff
  exponentiel 30 s → 60 s → … plafonné à la cadence normale (3600 s).

### Compagnon de session (Story 24.3)

Le token étant illisible user (ACL 23.3 figée), le canal réseau reste
**100 % SYSTEM** : le « compagnon de session » est un sous-système en
**deux tâches planifiées** at-logon, enregistrées par
`Install-SambaEduAgent.ps1` (vue serveur complète :
`docs/agent/session-companion.md`) :

- **`SambaEduAgent-SessionFetch`** (SYSTEM) → `SessionStateFetch.ps1` :
  énumère les sessions interactives (CIM, jamais `quser` — l'identité est
  résolue côté SYSTEM, anti-usurpation), `GET /state?user=<login court>`
  avec l'`If-None-Match` **du contexte**, écrit
  `cache\sessions\<SID>\{state.json,etag.txt}` (user en lecture seule).
  Même code (`Invoke-SessionStateFetch` dans `SambaEduAgent.ps1`) appelé
  par le cycle du service pour le rafraîchissement mid-session.
- **`SambaEduAgent-SessionCompanion`** (principal `BUILTIN\Users`, tourne
  dans la session avec SES droits) → `SessionCompanion.ps1` : poll borné du
  cache (2 s / 60 s, fallback dernier cache, sinon sortie silencieuse),
  `Parse-State`, traite les portées **`session` + `machine_user`**
  seulement (la portée `machine` reste au service) — no-op journalisé en
  24.3, handlers en 24.4. N'écrit que dans `%LOCALAPPDATA%\SambaEdu\Agent\`.
  Ne dot-source que `ContractV1.ps1` (lisible user sous Program Files).

**Durcissement 401 deux-acteurs** (le service ET le fetch partagent le même
fichier token — une rotation reçue par l'un peut invalider un appel en vol
de l'autre, dont le `PreviousToken` mémoire est null) : sur 401, après la
grâce mémoire, `Invoke-AgentHttpWithGrace` **relit le token sur disque** et
réessaie UNE fois s'il diffère — c'est seulement après ça qu'un 401 est
irrécupérable (arrêt + log, jamais de re-enrôlement auto).

**Note agent définitif** (binaire Go/.NET, prérequis Epic 25) : la voie
naturelle est un **broker IPC named-pipe** (le service pousse l'état aux
processus de session, plus de cache-fichiers ni de double tâche), écartée
pour le MVP PowerShell (service mono-thread 24.2, artefact temporaire).

### NFC (note pour 24.4 — handlers)

Le serveur émet en **NFC** et le hash compare octet à octet. Windows peut
produire du NFD (`é` = `e` + combinant) pour des chemins visuellement
identiques : les comparaisons *réel vs cible* des futurs handlers (24.4)
doivent normaliser en NFC avant comparaison
(`"…".Normalize([Text.NormalizationForm]::FormC)`), sinon faux `drift`.
Non critique en 24.2 (aucun handler, `items: []`).

---

## Signature Authenticode (NFR6)

Tout artefact déployé est **signé dès ce premier prototype** : non signé =
SmartScreen/ExecutionPolicy bloquent = démo impossible. Le certificat racine
`SambaEdu-RootCA` est déjà déployé sur les postes par la chaîne iPXE 23.3.

**Obtenir le certificat de signature** (sur le serveur SE5, CA interne) :
émettre un certificat **code signing** (EKU `1.3.6.1.5.5.7.3.3`) signé par
`SambaEdu-RootCA`, exporter en PFX (clé privée + chaîne), l'importer sur le
poste de build (`certutil -user -importPFX sambaedu-codesign.pfx` ou
double-clic). Le PFX ne se commit **jamais** dans le repo.

**Builder** : `agent/build/Build-Agent.ps1 -CertificateThumbprint <hex>` (ou
`-PfxPath`). Sortie : `agent/build/dist/` (scripts signés) +
`SambaEduAgent-<version>.zip`. Le script vérifie que la chaîne du certificat
remonte bien à la CA SambaEdu.

## Installation lab (AC8 — manuelle, MVP)

```powershell
# Sur le poste (admin), après build :
.\Install-SambaEduAgent.ps1 -ServerUrl 'http://<serveur-se5>'
# Vérifier : Get-Service SambaEduAgent ; log C:\ProgramData\SambaEdu\Agent\logs\agent.log
# Tâches compagnon (24.3) : Get-ScheduledTask SambaEduAgent-Session* → Ready
# Désinstaller (service + tâches, token conservé) : .\Uninstall-SambaEduAgent.ps1
```

La distribution automatique (bootstrap, canari, auto-update) est la story 25.x.

## Versionnement

`agent_version` (`1.0.0` pour le squelette) vit dans
`shared/ContractV1.ps1` (`$script:AgentVersion`) — source unique, déclarée
dans chaque rapport, à bumper à chaque release (Epic 25).

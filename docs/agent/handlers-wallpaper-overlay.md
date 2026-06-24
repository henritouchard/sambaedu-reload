# Handlers `wallpaper` + overlay — convergence côté agent

> Vue **côté serveur** des deux handlers de scope session du canal agent
> desired-state. Orthogonale à [session-companion.md](session-companion.md)
> (le sous-système compagnon qui les exécute) et à
> [state-providers.md](state-providers.md) (les payloads qu'ils consomment).
> Le wire format est défini par [contract-v1.md](contract-v1.md) ; le
> transport par [state-endpoint.md](state-endpoint.md) /
> [report-endpoint.md](report-endpoint.md).
>
> **Implémentation : binaire Go** (`agent/`). Tests serveur :
> `tests/Feature/Api/V1/Agent/{AssetEndpointTest,HandlersE2eTest}.php` ;
> moteur, sérialiseur overlay et validation des drops testés `go test` (hôte).

## 1. Vue d'ensemble — qui fait quoi

`wallpaper` et `overlay` sont tous deux **scope `session`** : c'est le
**compagnon** (droits user, ni réseau ni token) qui applique. Le service
SYSTEM fournit l'amont (download/vérification des assets, création des
répertoires de drop) et l'aval (collecte + validation des drops, rapport).

```
UI (règles wallpaper / signaux overlay)
   │  compilation desired-state (+ item identity)
   ▼
GET /state, /state?user=        ──►  caches (machine + sessions\<SID>\)
GET /assets/wallpaper/<file>    ──►  assets\<filename>   (SYSTEM, SHA-256 vérifié)
                                          │ lecture seule (Users:R / <SID>:R)
                                          ▼
                                  SessionCompanion (boucle RÉSIDENTE, droits user)
                                  ConvergenceEngine : test → apply si écart
                                          │ écritures user : HKCU, overlay.json,
                                          │ applied-state.json (%LOCALAPPDATA%)
                                          ▼
                                  drop reports\sessions\<SID>\session-report.json
                                          │ collecte + VALIDATION STRICTE (SYSTEM)
                                          ▼
POST /report (items réels)      ──►  agent_resource_states / agent_report_events
```

| Acteur | Rôle |
|---|---|
| Service SYSTEM | télécharge/vérifie les assets, crée les répertoires de drop, collecte + valide les drops, rapporte |
| Compagnon (user) | converge wallpaper (HKCU + `SystemParametersInfo`) et overlay (`overlay.json` per-user), persiste son applied-state, dépose son drop |

Fichiers Go :

| Rôle | Code Go |
|---|---|
| Moteur de convergence (machine d'états §5) | `agent/shared/engine.go` (`ResolveItemStatus`, `Engine.RunPass`) |
| Handler wallpaper | `agent/windows/handler_wallpaper_windows.go` (registre + `SystemParametersInfoW` FFI sans cgo) + logique pure `agent/shared/handler_wallpaper.go` |
| Handler overlay | `agent/shared/overlay_compose.go` (sérialiseur fixe, golden byte-compatible) + `agent/shared/handler_overlay.go` |
| Sync des assets wallpaper | `agent/shared/assets.go` |
| Collecte des drops session | `agent/shared/dropcollect.go` |
| Boucle compagnon résidente | `agent/shared/companion.go` + `agent/windows/companion_windows.go` |

## 2. Serving des assets wallpaper — Alias Apache statique

Le fond d'écran est servi **EN DIRECT par Apache** via l'Alias
`/assets/wallpaper/<sha256>.<ext>` (`config/apache/sambaedu.conf` +
`scripts/setupApache.sh`, scopé sur `storage/app/wallpaper`, `Options -Indexes`,
**pas** de FallbackResource) — comme `/assets/shortcut-icons`. L'agent fait un
**GET HTTP simple SANS token** (`SyncWallpaperAssets`, helper `getStatic`
mutualisé avec `icon_assets.go`) : les images (centaines de Ko à plusieurs Mo)
ne traversent pas PHP-FPM. Garantie d'intégrité = content-addressing +
**SHA-256 vérifié AVANT écriture** (un contenu divergent n'entre jamais dans le
cache). Garde-fou sécu : l'Alias pointe EXACTEMENT sur le sous-dossier dédié,
jamais sur `storage/` entier (`storage/keys/pki/` = PFX code-signing + clés CA).

Le payload wallpaper est `{asset, checksum}` (sans champ `url`) : l'agent
construit l'URL depuis `server_url` (config.json) + le chemin documenté ci-dessus
— comme pour `/state` et `/report`.

**Côté poste** (`Sync-WallpaperAssets`, SYSTEM) : scan des items `wallpaper`
de tous les états en cache (machine + sessions), download des manquants vers
`C:\ProgramData\SambaEdu\Agent\assets\<filename>` (tmp `$PID` + Move),
**vérification SHA-256 = `payload.checksum`** (divergent = supprimé + log,
retry au cycle suivant). Content-addressed ⇒ un fichier présent n'est jamais
re-téléchargé. ACL du répertoire à la création : SYSTEM F, Administrators F,
**`BUILTIN\Users` R** (un wallpaper n'est pas un secret, la session doit
l'afficher).

## 3. Handler `wallpaper` (exclusive / session)

`agent/windows/handler_wallpaper_windows.go` (+ logique pure
`agent/shared/handler_wallpaper.go`, testée hôte) — exécuté par le compagnon :

- **test** : `HKCU:\Control Panel\Desktop\WallPaper` pointe-t-il vers
  `assets\<filename>` attendu ? Comparaison **case-insensitive** + NFC.
- **apply** : valeur registre + style `fill` (WallpaperStyle=10,
  TileWallpaper=0) + `SystemParametersInfo(SPI_SETDESKWALLPAPER,
  UPDATEINIFILE|SENDCHANGE)` (P/Invoke). **Idempotent**.
- `asset: null` = règle explicite « pas de fond imposé » : le handler **ne
  touche pas** au fond → `compliant`. Type absent de la liste = aucun statut
  émis (géré par le moteur).
- Asset pas encore téléchargé (course avec le download SYSTEM) → `error` +
  detail explicite, résorbé au passage suivant.
- Le fond cible est **toujours réimposé** : modifier le fond à la main sur le
  poste est corrigé au passage suivant (STRICT inconditionnel — cf. §5).

## 4. Handler `overlay` (aggregate / session)

`agent/shared/overlay_compose.go` + `agent/shared/handler_overlay.go`
(OS-agnostique par injection, testé hôte — golden byte-compatible) :

- La cible = le document `overlay.json` **composé localement** depuis TOUS
  les items overlay de la passe (union aggregate, ordre serveur) :
  - item **`kind: "identity"`** (enrichissement serveur — `OverlayStateProvider`
    l'émet en contexte user : `{kind, login, fullname, room}`, room = salle
    physique du poste) → blocs `identity.fullname/login` + `machine.room` ;
  - `machine.name` = `$env:COMPUTERNAME` **local** ;
  - les signaux postés → `alerts[]` `{severity, title, text}` (texte aplati
    iso `OverlayService::sanitizeText`).
- **test** = contenu identique (comparaison après NFC) ; **apply** =
  écriture atomique de `%LOCALAPPDATA%\SambaEdu\Agent\overlay.json` —
  per-user par construction (multi-session correct).
- Sérialisation **à structure fixe** (ordre de clés stable, `": "` simple,
  UTF-8 brut, aucun champ volatil type `generated_at`) : la regex WebParser de
  la skin Rainmeter est fragile et un sérialiseur générique (double espace,
  `\uXXXX`) la casserait. Le test est une comparaison de contenu.
- **Rendu** : la variable `JsonPath` de la skin (`resources/overlay/rainmeter/`)
  pointe sur `overlay.json` ; le rendu Linux est intouché.
- **Rainmeter absent = comportement gracieux** : `overlay.json` est
  composé/écrit quand même (la ressource config EST convergée → statut normal,
  jamais `error` de ce seul fait) + log info « rainmeter absent, overlay non
  rendu ». Le handler n'installe JAMAIS d'application — la livraison de
  Rainmeter relève du workflow d'install des postes.
- Aucun item overlay (ni identity — ex. cache machine-only sans signal) →
  type absent du drop, aucun statut.

## 5. Moteur de convergence — STRICT inconditionnel

`agent/shared/engine.go` — cœur **portable** (aucune dépendance Windows,
machine d'états couverte table-driven sur l'hôte) :

- itération **dans l'ordre du payload serveur**, séquentielle, jamais de
  parallélisme ; dispatch par type ; type sans handler = ignoré + log DEBUG ;
- **try/catch par type** : un échec → `{status: error, detail}` et la passe
  continue (isolation par item) ;
- machine d'états (`ResolveItemStatus`), **STRICT inconditionnel** :
  - réel ≠ cible → applique → `drift` ; réel = cible → `compliant` (la cible
    fait TOUJOURS loi) ;
  - le store applied-state (dernier-appliqué) est conservé pour la traçabilité,
    sans incidence sur le verdict.
- **applied-state PER-USER** : `%LOCALAPPDATA%\SambaEdu\Agent\applied-state.json`
  (map `type → {hash, applied_at}`, hash d'item opaque / empreinte d'agrégat).
  L'`applied-state.json` **machine** (ProgramData, ACL SYSTEM) reste réservé aux
  handlers de scope machine.

### Conventions de hash du rapport

| Semantics | Hash rapporté |
|---|---|
| `exclusive` (wallpaper) | le hash d'item opaque du serveur, **verbatim** |
| `aggregate` (overlay) | **empreinte d'agrégat** : SHA-256 hex de la concaténation des hashes opaques des items, dans l'ordre serveur |

Le serveur ne fournit pas de hash d'ensemble par type et ne compare le hash
du rapport **qu'au rapport précédent** ([report-endpoint.md](report-endpoint.md))
: l'empreinte est invisible pour lui — ce n'est PAS un recalcul de hash d'item
(interdit), c'est une empreinte déterministe construite sur des chaînes opaques.

## 6. Remontée des résultats session — drop per-SID

Le rapport v1 n'a **pas de dimension user** : le compagnon ne poste jamais.
À la place :

1. Le fetch SYSTEM crée `C:\ProgramData\SambaEdu\Agent\reports\sessions\<SID>\`
   (ACL `/inheritance:r`, SYSTEM F, Administrators F, **`<SID>:(OI)(CI)M`** — le
   user écrit SON drop, n'énumère/ne lit pas ceux des autres).
2. Le compagnon y écrit `session-report.json` après **chaque** passe
   (atomique, tmp `$PID`) : `{generated_at, items: [{type, status, hash,
   detail?}]}` — sa SEULE écriture hors `%LOCALAPPDATA%`.
3. Au cycle, le service (`Read-SessionReports`) lit les drops, **valide
   strictement** chaque entrée, fusionne **unique par type** (le
   `generated_at` le plus récent gagne) et passe ces items à `POST /report`.
   Le serveur ingère sans modification.

### Frontière de confiance du drop

Le user peut forger SON `session-report.json` (et SON applied-state local).
Validation côté service AVANT fusion : type ∈ liste publiée, status ∈ enum,
hash `^[0-9a-f]{64}$`, `detail` borné (2000), `error` sans detail = rejeté,
taille de fichier plafonnée (256 KiB), JSON invalide = drop ignoré + log.
**Impact borné par construction** : un user ne peut fausser que les statuts
session de SON poste.

## 7. Boucle résidente du compagnon

Le compagnon ne sort pas après une passe : il **reste résident** dans la
session — poll du mtime de son cache (~60 s), re-convergence quand l'état
change ET re-test périodique (~5 min, level-triggered : détecte les dérives
locales). Le processus meurt au logoff ; `-MultipleInstances IgnoreNew`
empêche le doublon au logon suivant. L'`ExecutionTimeLimit` de la tâche
compagnon est **illimité** (une limite tuerait la boucle après la première
passe).

## 8. Limitations connues

- **Latence ≤ 1 cycle** entre convergence session et rapport serveur.
- **Multi-session** : la fusion des drops garde un item par type (le plus
  récent gagne) — le statut rapporté est celui d'UNE session (postes d'école
  = 1 session interactive).
- **Quarantaine** : le fetch session est sauté → le compagnon converge sur
  son **dernier cache** (level-triggered, inoffensif : l'état ne change plus).
  Pas de canal de signalisation quarantaine vers le compagnon.
- **Drop forgeable** par le user de la session (cf. §6 — impact borné à son
  poste).
- **Livraison de Rainmeter** hors-scope desired-state (workflow d'install des
  postes).
- Pas de purge des caches `assets\` / drops (volume borné).

## 9. Ce que le serveur observe (vérification lab)

- `agent.asset.served` au premier cycle après une règle wallpaper avec un
  asset nouveau pour le poste ;
- `POST /report` avec des items **réels** : lignes `agent_resource_states`
  (wallpaper/overlay) + événements `agent_report_events` sur transition,
  zéro événement sur rapport identique ;
- modifier le fond à la main sur le poste → le fond cible est **réappliqué**
  (`drift`) au rapport suivant.

Runbook démo : `docs/qa/domains/agent.md` §4.

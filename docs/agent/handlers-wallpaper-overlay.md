# Handlers wallpaper + overlay — la convergence réelle (Story 24.4, portage Go 24.6)

> Vue **côté serveur** des deux premiers handlers réels du canal agent
> desired-state (Epic 24, gate palier 1). Complète `session-companion.md`
> (le sous-système compagnon, 24.3) et `state-providers.md` (les payloads,
> 23.4). Le wire format reste défini par `contract-v1.md` (**FIGÉ** — rien
> dans cette story ne le modifie : le rapport n'a toujours pas de dimension
> user), le transport par `state-endpoint.md` / `report-endpoint.md`.
> Tests serveur : `tests/Feature/Api/V1/Agent/{AssetEndpointTest,
> HandlersE2eTest}.php`.
>
> **Implémentation (depuis 24.6) : le binaire Go** (`agent/`) — le spike
> PowerShell 24.4 est retiré du repo. Les séquences, ACL, conventions de
> hash et formats locaux ci-dessous sont **inchangés** par le portage ; le
> gain : la machine d'états §5, l'isolation, la validation des drops et le
> sérialiseur overlay (golden byte-compatible) sont désormais **testés**
> (`go test`, hôte). Les références de fichiers PS de ce document se lisent
> avec la table de correspondance ci-dessous (§1).

## 1. Vue d'ensemble — qui fait quoi

`wallpaper` et `overlay` sont tous deux **scope `session`** (constantes des
providers 23.4) : c'est le **compagnon** (droits user, ni réseau ni token —
partition 24.3) qui applique. Le service SYSTEM fournit l'amont et l'aval :

```
UI (règles wallpaper / signaux overlay)
   │  compilation 23.4 (+ item identity 24.4)
   ▼
GET /state, /state?user=        ──►  caches (machine + sessions\<SID>\)
GET /assets/wallpaper/<file>    ──►  assets\<filename>   (SYSTEM, SHA-256 vérifié)
                                          │ lecture seule (Users:R / <SID>:R)
                                          ▼
                                  SessionCompanion (boucle RÉSIDENTE, droits user)
                                  ConvergenceEngine : test → apply si écart
                                  (machine d'états §5, isolation par item)
                                          │ écritures user : HKCU, overlay.json,
                                          │ applied-state.json (%LOCALAPPDATA%)
                                          ▼
                                  drop reports\sessions\<SID>\session-report.json
                                          │ collecte + VALIDATION STRICTE (SYSTEM)
                                          ▼
POST /report (items réels)      ──►  agent_resource_states / agent_report_events
```

| Acteur | Rôle 24.4 |
|---|---|
| Service SYSTEM | télécharge/vérifie les assets, crée les répertoires de drop, collecte + valide les drops, rapporte |
| Compagnon (user) | converge wallpaper (HKCU + `SystemParametersInfo`) et overlay (`overlay.json` per-user), persiste son applied-state, dépose son drop |

Correspondance spike PS → implémentation Go (24.6) :

| Référence PS (spike, retiré) | Code Go |
|---|---|
| `ConvergenceEngine.ps1` (`Resolve-ItemStatus`, `Invoke-ConvergencePass`) | `agent/shared/engine.go` (`ResolveItemStatus`, `Engine.RunPass`) — §5 table-driven testé |
| `handlers/Wallpaper.ps1` | `agent/windows/handler_wallpaper_windows.go` (registre + `SystemParametersInfoW` FFI sans cgo) + logique pure `agent/shared/handler_wallpaper.go` |
| `handlers/Overlay.ps1` (`Build-OverlayDocument`, `Format-OverlayJson`) | `agent/shared/overlay_compose.go` (sérialiseur fixe, golden byte-compatible) + `agent/shared/handler_overlay.go` |
| `Sync-WallpaperAssets` | `agent/shared/assets.go` |
| `Read-SessionReports` | `agent/shared/dropcollect.go` |
| `SessionCompanion.ps1` (boucle résidente, drop) | `agent/shared/companion.go` + `agent/windows/companion_windows.go` |

## 2. Serving des assets — Alias Apache statique (calque Story 27.7)

Le fond d'écran est servi **EN DIRECT par Apache** via l'Alias
`/assets/wallpaper/<sha256>.<ext>` (`config/apache/sambaedu.conf` +
`scripts/setupApache.sh`, scopé sur `storage/app/wallpaper`, `Options -Indexes`,
**pas** de FallbackResource) — exactement comme `/assets/shortcut-icons` (27.7).
L'agent fait un **GET HTTP simple SANS token** (`SyncWallpaperAssets`, helper
`getStatic` mutualisé avec `icon_assets.go`) : les images (centaines de Ko à
plusieurs Mo) ne traversent plus PHP-FPM. Garantie d'intégrité = content-addressing
+ **SHA-256 vérifié AVANT écriture** (un contenu divergent n'entre jamais dans le
cache). Garde-fou sécu : l'Alias pointe EXACTEMENT sur le sous-dossier dédié,
jamais sur `storage/` entier (`storage/keys/pki/` = PFX code-signing + clés CA).

**Route Laravel token'd conservée le temps du rollout** : la route ci-dessous
reste vivante car les postes en **ancien agent** dérivent encore cette URL ; son
retrait est un cleanup ultérieur séparé.

| | |
|---|---|
| URL (legacy, transition) | `GET /api/v1/agent/assets/wallpaper/{filename}` (route `agent.v1.assets.wallpaper`) |
| Middlewares | `auth.v1.secure-headers` + `throttle:60,1` + `agent.token` — chaîne iso state/report ; `X-Agent-New-Token` survit (D5) |
| Controller | `App\Http\Controllers\Api\V1\Agent\AssetController` (mince) |
| 200 | contenu binaire de l'asset (`WallpaperAsset` lookup par filename, fichier sous `config('wallpapers.library_path')`) |
| 404 | filename non conforme (`^[0-9a-f]{64}\.[a-z0-9]{2,5}$`), asset inconnu, fichier absent — indistinct (pas d'oracle), jamais de traversal (validation amont + défense `absolutePath`) |
| 401/403 | middleware `agent.token` inchangé |
| Logs | channel `agent` : `agent.asset.served` / `agent.asset.not_found` (contexte `workstation_id`, `filename`) |

**Décision « pas de champ `url` »** : le payload wallpaper reste
`{asset, checksum}` (figé 23.4). L'agent construit l'URL depuis `server_url`
(config.json) + ce chemin documenté — exactement comme pour `/state` et
`/report`. Un champ `url` resterait possible plus tard sans casse (champ
ajouté = mineur, contrat §9).

**Côté poste** (`Sync-WallpaperAssets`, SYSTEM) : scan des items `wallpaper`
de tous les états en cache (machine + sessions), download des manquants vers
`C:\ProgramData\SambaEdu\Agent\assets\<filename>` (tmp `$PID` + Move),
**vérification SHA-256 = `payload.checksum`** (divergent = supprimé + log,
retry au cycle suivant). Content-addressed ⇒ un fichier présent n'est jamais
re-téléchargé. ACL du répertoire à la création : SYSTEM F, Administrators F,
**`BUILTIN\Users` R** (un wallpaper n'est pas un secret, la session doit
l'afficher). Pas de purge en 24.4 (volume borné par la bibliothèque).

## 3. Handler `wallpaper` (exclusive / default / session)

`agent/windows/handler_wallpaper_windows.go` (+ logique pure
`agent/shared/handler_wallpaper.go`, testée hôte) — exécuté par le compagnon :

- **test** : `HKCU:\Control Panel\Desktop\WallPaper` pointe-t-il vers
  `assets\<filename>` attendu ? Comparaison **case-insensitive** + NFC.
- **apply** : valeur registre + style `fill` (WallpaperStyle=10,
  TileWallpaper=0) + `SystemParametersInfo(SPI_SETDESKWALLPAPER,
  UPDATEINIFILE|SENDCHANGE)` (P/Invoke). **Idempotent**.
- `asset: null` = règle explicite « pas de fond imposé » (contrat §8) : le
  handler **ne touche pas** au fond → `compliant`. Type absent de la liste =
  aucun statut émis (géré par le moteur).
- Asset pas encore téléchargé (course avec le download SYSTEM) → `error` +
  detail explicite, résorbé au passage suivant.
- Mode `default` → machine d'états §5 complète (cf. §5 ci-dessous) : le fond
  personnel d'un élève est LE cas d'école du `drifted_allowed`.

## 4. Handler `overlay` (aggregate / strict / session) — l'agent devient le fetch du POC

`agent/shared/overlay_compose.go` + `agent/shared/handler_overlay.go`
(OS-agnostique par injection, testé hôte — golden byte-compatible) :

- La cible = le document `overlay.json` **composé localement** depuis TOUS
  les items overlay de la passe (union aggregate, ordre serveur) :
  - item **`kind: "identity"`** (enrichissement serveur 24.4 —
    `OverlayStateProvider` l'émet en contexte user : `{kind, login,
    fullname, room}`, room = salle physique du poste) → blocs
    `identity.fullname/login` + `machine.room` ;
  - `machine.name` = `$env:COMPUTERNAME` **local** ;
  - les signaux postés → `alerts[]` `{severity, title, text}` (texte aplati
    iso `OverlayService::sanitizeText`).
- **test** = contenu identique (comparaison après NFC) ; **apply** =
  écriture atomique de `%LOCALAPPDATA%\SambaEdu\Agent\overlay.json` —
  per-user par construction (multi-session correct), plus jamais le
  `%PROGRAMDATA%\SambaEdu\overlay.json` du POC.
- Sérialisation **à structure fixe** (ordre de clés stable, `": "` simple,
  UTF-8 brut) : la regex WebParser de la skin Rainmeter est fragile
  (caveat POC) — `ConvertTo-Json` PS 5.1 (double espace, `\uXXXX`) la
  casserait. Aucun champ volatil (`generated_at`) : le test est une
  comparaison de contenu.
- **Render inchangé** : seule la variable `JsonPath` de la skin
  (`resources/overlay/rainmeter/`) pointe sur le nouveau fichier. Le fetch
  POC Windows (`overlay-fetch.ps1`) est **déprécié** (l'agent EST le fetch) ;
  Linux intouché.
- **Rainmeter absent = comportement gracieux** (amendement 2026-06-12) :
  overlay.json est composé/écrit quand même (la ressource config EST
  convergée → statut normal, jamais `error` de ce seul fait) + log info
  « rainmeter absent, overlay non rendu ». Le handler n'installe JAMAIS
  d'application — la livraison de Rainmeter = workflow d'install des postes.
- Mode `strict` : toute divergence du fichier est réécrite → `drift`.
- Aucun item overlay (ni identity — ex. cache machine-only sans signal) →
  type absent du drop, aucun statut.

## 5. Moteur de convergence + mode `default` (gap 1 réalisé)

`agent/shared/engine.go` — cœur **portable** (aucune dépendance Windows,
machine d'états couverte table-driven sur l'hôte) :

- itération **dans l'ordre du payload serveur** (FR18), séquentielle, jamais
  de parallélisme ; dispatch par type ; type sans handler = ignoré + log
  DEBUG (contrat §8) ;
- **try/catch par type** : un échec → `{status: error, detail}` et la passe
  continue (isolation, AC epic) ;
- machine d'états **§5 verbatim** (`Resolve-ItemStatus`) :
  - `strict` : réel ≠ cible → applique → `drift` ; sinon `compliant` ;
  - `default` : réel ≠ cible ∧ dernier-appliqué = cible → **dérive humaine**
    → ne réapplique PAS → `drifted_allowed` ; dernier-appliqué ≠ cible →
    applique → `drift` ;
  - **premier passage** (pas de mémoire) : jamais `drifted_allowed`.
- **applied-state PER-USER** : `%LOCALAPPDATA%\SambaEdu\Agent\
  applied-state.json` (map `type → {hash, applied_at}`, hash d'item opaque /
  empreinte d'agrégat). Le `applied-state.json` **machine** de 24.2
  (ProgramData, ACL SYSTEM) reste réservé aux futurs handlers machine.

### Conventions de hash du rapport (décision n° 7)

| Semantics | Hash rapporté |
|---|---|
| `exclusive` (wallpaper) | le hash d'item opaque du serveur, **verbatim** |
| `aggregate` (overlay) | **empreinte d'agrégat** : SHA-256 hex de la concaténation des hashes opaques des items, dans l'ordre serveur |

Le serveur ne fournit pas de hash d'ensemble par type et ne compare le hash
du rapport **qu'au rapport précédent** (`report-endpoint.md`) : l'empreinte
est invisible pour lui — ce n'est PAS un recalcul de hash d'item (interdit),
c'est une empreinte déterministe construite sur des chaînes opaques.

## 6. Remontée des résultats session — drop per-SID

Le rapport v1 n'a **pas de dimension user** (§6 FIGÉ) : le compagnon ne
poste jamais. À la place :

1. Le fetch SYSTEM crée `C:\ProgramData\SambaEdu\Agent\reports\sessions\
   <SID>\` (ACL `/inheritance:r`, SYSTEM F, Administrators F,
   **`<SID>:(OI)(CI)M`** — le user écrit SON drop, n'énumère/ne lit pas
   ceux des autres).
2. Le compagnon y écrit `session-report.json` après **chaque** passe
   (atomique, tmp `$PID`) : `{generated_at, items: [{type, status, hash,
   detail?}]}` — sa SEULE écriture hors `%LOCALAPPDATA%`.
3. Au cycle, le service (`Read-SessionReports`) lit les drops, **valide
   strictement** chaque entrée, fusionne **unique par type** (le
   `generated_at` le plus récent gagne) et passe ces items à
   `POST /report`. Le serveur ingère sans modification (24.1).

### Frontière de confiance du drop

Le user peut forger SON `session-report.json` (et SON applied-state local).
Validation côté service AVANT fusion : type ∈ liste publiée §7, status ∈
enum, hash `^[0-9a-f]{64}$`, `detail` borné (2000), `error` sans detail =
rejeté, taille de fichier plafonnée (256 KiB), JSON invalide = drop ignoré +
log. **Impact borné par construction** : il ne peut fausser que les statuts
session de SON poste — documenté, pas sur-ingénié.

## 7. Boucle résidente du compagnon

Depuis 24.4 le compagnon ne sort plus après une passe : il **reste
résident** dans la session — poll du mtime de son cache (~60 s), re-convergence
quand l'état change ET re-test périodique (~5 min, level-triggered : détecte
les dérives locales). Le processus meurt au logoff ;
`-MultipleInstances IgnoreNew` (24.3) empêche le doublon au logon suivant.
**`ExecutionTimeLimit` de la tâche compagnon = illimité** (changement
délibéré du réglage post-review 24.3 #8, motivé en commentaire d'install :
une limite tuerait la boucle après la première passe).

## 8. Limitations MVP (assumées, documentées)

- **Latence ≤ 1 cycle** entre convergence session et rapport serveur
  (NFR3 fraîcheur laxe) — la démo redémarre le service ou attend le cycle ;
  le « forcer la synchro » arrive en 24.5.
- **Multi-session** : la fusion des drops garde un item par type (le plus
  récent gagne) — postes d'école = 1 session interactive ; le statut
  rapporté est celui d'UNE session.
- **Quarantaine** : le fetch session est sauté → le compagnon converge sur
  son **dernier cache** (level-triggered, inoffensif : l'état ne change
  plus). Pas de canal de signalisation quarantaine vers le compagnon.
- **Drop forgeable** par le user de la session (cf. §6 — impact borné à son
  poste).
- **Livraison de Rainmeter** : hors-scope desired-state — install manuelle
  pour la démo (T12), automatisation au workflow d'install des postes
  (piste : l'agent l'installe en première opération — story future).
- Pas de purge des caches `assets\` / drops (volume borné, noté pour plus
  tard).

## 9. Ce que le serveur observe (vérification lab)

- `agent.asset.served` au premier cycle après une règle wallpaper avec un
  asset nouveau pour le poste ;
- `POST /report` avec des items **réels** : lignes `agent_resource_states`
  (wallpaper/overlay) + événements `agent_report_events` sur transition,
  zéro événement sur rapport identique ;
- mode default : modifier le fond à la main sur le poste →
  `drifted_allowed` au rapport suivant (et le fond N'EST PAS réappliqué).

Runbook démo palier 1 : `docs/qa/domains/agent.md` §4.

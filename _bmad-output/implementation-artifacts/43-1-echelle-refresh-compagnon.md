# Story 43.1 : Agent — échelle de rafraîchissement du compagnon (hint `refresh` du payload)

Status: review

<!-- Source d'autorité : _bmad-output/planning-artifacts/epics-application-immediate.md#Story-43.1
     + Overview (constats ancrés code 2026-07-10) + FR-A1/FR-A2 (volet agent) + NFR-A1..A5.
     Lignes de code re-vérifiées à la création de story (2026-07-11, worktree ultradev/43-1) —
     toutes les citations de l'epic sont EXACTES sauf loop.go (EffectiveInterval = 582-600,
     pas 582-596 — sans incidence : fichier HORS périmètre 43.1, c'est le levier 43.3). -->

## Story

En tant qu'administrateur du parc,
je veux qu'un réglage appliqué par le compagnon devienne effectif en session courante
(sans délog/relog), via le geste de rafraîchissement minimal déclaré par le serveur,
afin de supprimer le « double logon » (Explorer lit ses policies au démarrage : toute
écriture HKCU post-shell est invisible jusqu'au relogon).

## Contexte & intention

Le patron du fix existe en embryon : après une écriture HKCU **effective**, les handlers
`registry` et `registry_list` émettent `SHChangeNotify(SHCNE_ASSOCCHANGED)` via le hook
optionnel `registryNotifier` — gated sur `changed`, jamais au régime stable. Il manque les
gestes plus forts (`WM_SETTINGCHANGE "Policy"`, restart Explorer) et leur pilotage
déclaratif. Cette story dote le compagnon de l'**échelle complète** pilotée par un champ
`refresh` porté par le **payload** des items :

`shell_notify` < `policy_broadcast` < `explorer_restart`

En fin de passe compagnon, si des items ont **effectivement changé**, UN SEUL geste — le
plus fort requis par les items changés — est exécuté. Zéro geste si passe stable.

**Consommateur immédiat** : Epic 41 (mode examen) — `restrict_run` (41.2, HKCU
`Policies\Explorer\RestrictRun`) passera d'« effet au logon suivant » à « effectif en
session courante » dès que la 43.2 posera le hint. Le lot vues Explorer existant
(Hidden, HideFileExt) en profite sans régression.

**Ancrage code re-vérifié (2026-07-11)** :

- `agent/shared/handler_registry.go:184-194` — interface `registryNotifier` (hook
  optionnel) ; `:363` flag `shellRefresh` ; `:392-394` gate `changed && isUserHive` ;
  `:404-408` émission `NotifyShellChanged()` en fin d'Apply. À MIGRER vers l'échelle.
- `agent/shared/handler_registry_list.go:178,220-222,242-245,251-255` — même patron
  (flag + gate + émission). À MIGRER pareillement.
- `agent/windows/handler_registry_windows.go:299-326` — FFI `SHChangeNotify`
  (`NewLazySystemDLL("shell32.dll")`), impl de `registryNotifier` sur `registryOps`.
- `agent/shared/companion.go:145-199` — `Companion.RunPass` (lecture cache → partition
  session+machine_user → `c.Engine.RunPass(items, applied)` → applied-state → drop).
  Point d'agrégation de l'échelle : **fin de RunPass**.
- `agent/shared/engine.go` — moteur générique portable, machine d'états §5. **RESTE
  INTOUCHÉ** (AC d'epic) : l'agrégation vit dans le compagnon, pas dans le moteur.
- `agent/windows/companion_windows.go:84-179` — câblage compagnon (map Handlers :
  `registry` :130, `registry_list` :140). Point d'injection des ops de refresh.
- `agent/windows/main_windows.go:183+` — `MachineEngine` SYSTEM (registry :185,
  registry_list :195) : mêmes types Go instanciés côté session 0 — ne doit JAMAIS
  produire de geste (le gate `isUserHive` rend déjà false pour HKLM/HKU, et le moteur
  machine ne consommera pas l'échelle).
- `agent/windows/handler_wallpaper_windows.go:47-56,133-141` — patron FFI de référence
  (`NewLazySystemDLL`, procs user32, `SystemParametersInfoW`). Le wallpaper garde SON
  rafraîchissement propre : HORS échelle.
- `agent/shared/version.go:241` — `Version = "2.9.0"` → bump **2.10.0**.

## Décisions de design (tranchées en création de story)

- **D1 — Interface optionnelle côté handler, consommée par le COMPAGNON (pas le
  moteur).** Nouveau contrat interne `RefreshRequester { TakeRefreshRequest() RefreshLevel }`
  (patron des interfaces additives `DetailReporter`/`InventoryReporter`, mais interrogé
  par `Companion.RunPass` en itérant `c.Engine.Handlers` APRÈS `Engine.RunPass`) —
  `engine.go` : **zéro diff**. `Take…` retourne le niveau max accumulé pendant l'Apply
  de LA passe et se remet à zéro (consommation par passe). Le service SYSTEM
  (`runService`/MachineEngine) ne consomme jamais → aucun geste en session 0, y compris
  sur le fan-out HKU (piège existant n° 9, préservé structurellement).
- **D2 — Plancher `shell_notify` pour tout changement HKCU effectif (migration
  iso-comportement).** Le comportement actuel (SHChangeNotify sur changed HKCU,
  inconditionnel) devient le niveau plancher : item changé SANS hint (ou hint inconnu)
  ⇒ `shell_notify`. Un hint ne peut qu'ESCALADER (`max(plancher, hint)`), jamais
  affaiblir — c'est ce qui rend le champ absent/inconnu « comportement actuel » (additif
  sûr, NFR-A4) ET couvre la non-régression du lot vues Explorer.
- **D3 — Échelle typée dans `shared`.** `RefreshLevel` (int ordonné :
  `RefreshNone < RefreshShellNotify < RefreshPolicyBroadcast < RefreshExplorerRestart`)
  + `ParseRefreshLevel(string) RefreshLevel` **indulgent** : `""`/absent/inconnu →
  `RefreshNone` + log debug — JAMAIS une erreur d'enveloppe (la validation stricte du
  vocabulaire est serveur, AuthoringGuard 43.2, « rejet à l'authoring, jamais au
  runtime »).
- **D4 — Ops injectées, best-effort.** Interface `RefreshOps { ShellNotify();
  PolicyBroadcast() error; RestartExplorer() error }` dans `shared`, champ
  `Companion.Refresh RefreshOps` (nil = no-op — tests hôte, non-Windows). Un geste en
  échec = WARNING loggé, JAMAIS une erreur de passe ni un statut d'item (les clés SONT
  écrites ; au pire l'effet attend le relogon — sémantique best-effort du
  `registryNotifier` actuel, conservée).
- **D5 — Un geste par passe, exécuté en toute fin de `RunPass`** (après applied-state et
  drop : un `SendMessageTimeout` qui traîne ne retarde ni la persistance ni le rapport ;
  `SMTO_ABORTIFHUNG` + timeout bornent l'appel).
- **D6 — Validation lab de `policy_broadcast` : DÉPORTÉE (décision de création).** Le
  lab n'est pas accessible depuis ce contexte. L'échelle implémente les TROIS gestes
  quoi qu'il arrive ; l'efficacité réelle de `policy_broadcast` sur
  `RestrictRun`/`DisallowRun` se valide en action QA MANUELLE (runbook
  `docs/qa/domains/agent.md`, section 43.1) ; le CHOIX du hint par capacité
  (`policy_broadcast` vs `explorer_restart`) appartient à la **43.2**. Non bloquant ici.
- **D7 — Contrat/golden : RIEN ne bouge en 43.1.** `refresh` vit dans le **payload**
  (sous-structure provider-defined, contrat §3.2 — le wrapper 4 clés §3 est intact).
  Golden `tests/Fixtures/Agent/*.v1.json` INCHANGÉS (aucun provider n'émet encore le
  champ — c'est 43.2, avec doc contrat §7.1/§7.6, AuthoringGuard et mise à jour des
  golden). Justification à consigner au Dev Agent Record (règle §9 : la forme du wire ne
  change pas côté 43.1).

## ⚠️ Pièges & tensions (lire AVANT de coder)

1. **Piège #1 — parseurs : ignorer `refresh`, ne PAS durcir.** `parseRegistrySpec`
   (`handler_registry.go:464-507`) et `parseRegistryListSpec`
   (`handler_registry_list.go:266-301`) lisent les clés connues et IGNORENT le reste —
   c'est ce qui rend le champ additif sûr pour un agent antérieur. Lire `refresh` de
   façon indulgente (D3) ; n'introduire AUCUNE validation de « clés exactes » (le
   commentaire « 4 clés exactement » de registry_list décrit l'ÉMISSION serveur, pas une
   règle de parsing).
2. **Piège #2 — mêmes types Go, deux moteurs.** `RegistryHandler`/`RegistryListHandler`
   sont instanciés PAR le compagnon ET par le MachineEngine SYSTEM
   (`main_windows.go:185,195`). L'accumulation de refresh est un état PAR INSTANCE
   (mono-thread, patron acquis) ; côté SYSTEM elle reste vide (gate `isUserHive` : HKLM
   et HKU rendent false — `handler_registry.go:196-209`) ET personne ne la consomme.
   Test négatif explicite : fan-out HKU changé ⇒ `TakeRefreshRequest() == RefreshNone`.
3. **Piège #3 — `explorer_restart` : double-lancement = fenêtre parasite.** Lancer
   `explorer.exe` alors que le shell tourne déjà OUVRE UNE FENÊTRE de l'Explorateur (ce
   n'est pas un no-op). Or Windows relance parfois le shell tout seul après un kill.
   Séquence robuste : énumérer les `explorer.exe` de SA session
   (`CreateToolhelp32Snapshot`/`Process32Next` de `golang.org/x/sys/windows` +
   `ProcessIdToSessionId`), `TerminateProcess`, attendre brièvement (poll borné,
   ~2-3 s), re-énumérer : shell revenu tout seul → NE PAS relancer ; sinon lancer
   `%WINDIR%\explorer.exe` (droits du compagnon = droits user, JAMAIS d'élévation —
   NFR-A1 : les applis restent intactes, seules les fenêtres de l'Explorateur sont
   perdues, assumé).
4. **Piège #4 — `SendMessageTimeoutW` : lParam = pointeur UTF-16 vivant.**
   `SendMessageTimeout(HWND_BROADCAST(0xFFFF), WM_SETTINGCHANGE(0x001A), 0,
   lParam→"Policy", SMTO_ABORTIFHUNG(0x0002), timeout, &result)` — le buffer UTF-16 de
   `"Policy"` doit rester référencé pendant l'appel (piège GC classique des FFI :
   `unsafe.Pointer` + `runtime.KeepAlive`, patron `UTF16PtrFromString` du wallpaper
   `handler_wallpaper_windows.go:133-138`). Timeout raisonnable (ex. 5000 ms) : une
   fenêtre pendue ne doit pas bloquer le compagnon (c'est le rôle d'ABORTIFHUNG).
5. **Piège #5 — migration `registryNotifier` : REMPLACER, pas empiler.** Les blocs
   d'émission (`handler_registry.go:404-408`, `handler_registry_list.go:251-255`)
   disparaissent au profit de l'accumulation ; l'interface `registryNotifier` et
   `registryOps.NotifyShellChanged` (`handler_registry_windows.go:315-326`) migrent
   (supprimées ou recyclées comme impl de `RefreshOps.ShellNotify` — au choix du dev,
   mais UNE seule voie d'émission à l'arrivée). Sinon un changement HKCU produirait
   DEUX SHChangeNotify (un par handler + un par l'échelle). Migrer aussi les tests
   existants qui comptent `notifyCnt` (`handler_registry_test.go:25,40-42,1211`,
   `handler_registry_list_test.go:295+`) — les adapter, pas les dupliquer.
6. **Piège #6 — le wallpaper est HORS échelle.** Son
   `SystemParametersInfoW(SPI_SETDESKWALLPAPER, …, SPIF_SENDCHANGE)` est LE geste
   minimal spécifique au fond d'écran, déjà idempotent — ne pas le raccorder, ne pas le
   supprimer.
7. **Piège #7 — le hash d'item est OPAQUE côté agent.** Quand la 43.2 ajoutera
   `refresh` aux payloads, le hash serveur changera (drift ponctuel de re-application,
   bénin — NFR-A4). RIEN à faire côté agent : ne jamais recalculer/filtrer le hash.
8. **Piège #8 — publication AVANT seeders 43.2 (gate Epic 35).** Un binaire ≤ 2.9.0 qui
   reçoit un payload avec `refresh` l'ignore SANS erreur (piège #1 : parseurs
   indulgents) — pas de casse, mais AUCUN geste : l'effet « immédiat » promis par l'UI
   43.2 serait un mensonge sur les postes non à jour. La release **2.10.0** doit être
   publiée MANUELLEMENT (update.sh ne publie JAMAIS —
   `project_agent_selfupdate_validated_publish_gap`) AVANT de jouer tout seeder 43.2.
   Publication = action manuelle HORS story (à consigner au Dev Agent Record, avec
   l'état de publication des 2.6.0→2.9.0 — jamais publiées à la création de la 38.3).
9. **Piège #9 — worktree/VM.** Story développée dans le worktree `ultradev/43-1` :
   JAMAIS d'interaction VM/lab depuis le worktree ; tests Go sur l'HÔTE (toolchain
   `~/go-toolchain/go/bin` hors PATH) ; e2e lab = après merge + publication.

## Acceptance Criteria

### AC1 — Échelle typée + lecture indulgente du hint

**Given** le package `agent/shared`
**When** l'échelle est introduite (`RefreshLevel` ordonné : none < shell_notify <
policy_broadcast < explorer_restart ; `ParseRefreshLevel`)
**Then** un champ `refresh` du payload d'un item `registry`/`registry_list` est parsé
vers son niveau ; `refresh` ABSENT, vide ou de valeur INCONNUE ⇒ `RefreshNone` + log
debug — jamais une enveloppe invalide, jamais un `{status: error}` (additif sûr,
FR-A2/NFR-A4) ; les parseurs existants restent par ailleurs inchangés (piège #1).

### AC2 — Trois gestes Windows en FFI (NewLazySystemDLL, zéro cgo)

**Given** l'impl Windows des ops de refresh (`agent/windows`)
**When** chaque geste est exécuté dans la SESSION du compagnon (droits user, jamais
SYSTEM)
**Then** `shell_notify` émet `SHChangeNotify(SHCNE_ASSOCCHANGED, SHCNF_IDLIST)`
(existant, raccordé à l'échelle) ; `policy_broadcast` émet
`SendMessageTimeout(HWND_BROADCAST, WM_SETTINGCHANGE, 0, "Policy", SMTO_ABORTIFHUNG,
timeout borné)` (piège #4) ; `explorer_restart` termine puis relance `explorer.exe` de
la session courante avec garde anti-double-lancement (piège #3) ; chaque geste est
best-effort : échec = warning loggé, jamais une erreur de convergence (D4)
**And** `GOOS=windows go -C agent build ./...` + `vet` passent (aucun cgo).

### AC3 — Agrégation centralisée : UN geste par passe, le plus fort, gate `changed`

**Given** une passe `Companion.RunPass` dont des items `registry`/`registry_list` HKCU
ont **effectivement changé** (écriture ou suppression réelle — le gate `changed`
existant)
**When** la passe se termine
**Then** le compagnon collecte les besoins via `TakeRefreshRequest()` (D1) et exécute
**UN SEUL** geste : le plus fort requis parmi les items changés (`max(plancher
shell_notify, hint)` par item changé — D2) ; une passe STABLE (zéro écriture) n'exécute
AUCUN geste (NFR-A2, pas de « flicker ») ; `agent/shared/engine.go` est **sans aucun
diff** ; le moteur MACHINE (SYSTEM) ne déclenche jamais de geste, y compris sur fan-out
HKU changé (piège #2, test négatif).

### AC4 — Migration SHChangeNotify sans régression du lot vues Explorer

**Given** un item `registry` HKCU existant SANS hint (ex. lot Explorer : Hidden,
HideFileExt) dont l'Apply change effectivement une valeur
**When** la passe se termine
**Then** `shell_notify` est émis exactement comme aujourd'hui (plancher D2) — même
séquence observable pour le lot vues Explorer ; l'ancienne voie `registryNotifier`
inline a disparu des deux handlers (piège #5 : une seule émission, plus jamais deux) ;
les tests existants `notifyCnt` sont migrés vers la nouvelle mécanique.

### AC5 — Tests portables hôte (fake ops enregistrant les gestes)

**Given** un fake `RefreshOps` en mémoire enregistrant la séquence des gestes
**When** `go -C agent test ./...` tourne sur l'hôte Linux
**Then** sont prouvés : (a) agrégation — items changés avec hints hétérogènes
(`shell_notify`+`explorer_restart` ⇒ un seul `explorer_restart`) ; (b) gate changed —
passe compliant/stable ⇒ zéro geste ; item non changé avec hint fort ⇒ zéro geste ;
(c) un-seul-geste — plusieurs handlers changés dans la même passe ⇒ exactement 1 geste ;
(d) plancher — changé sans hint / hint inconnu ⇒ `shell_notify` (AC4) ; (e) `Refresh`
nil ⇒ no-op sans panique ; (f) geste en échec ⇒ passe et rapport intacts (D4) ;
(g) accumulation remise à zéro entre deux passes (pas de geste fantôme au tick suivant).

### AC6 — Version 2.10.0 + note de publication (gate Epic 35)

**Given** `agent/shared/version.go` à 2.9.0
**When** la story est livrée
**Then** version bumpée **2.10.0** avec bloc de changelog (patron des blocs existants),
golden `tests/Fixtures/Agent/*.v1.json` INCHANGÉS avec justification D7 consignée, et le
Dev Agent Record rappelle EXPLICITEMENT : publication MANUELLE obligatoire AVANT tout
seeder 43.2 (piège #8), publication = action hors story, état des releases 2.6.0→2.9.0
vérifié et consigné.

### AC7 — Runbook QA : validation lab de `policy_broadcast` (déportée, non bloquante)

**Given** `docs/qa/domains/agent.md` (append-only)
**When** la section Story 43.1 y est ajoutée
**Then** elle décrit le protocole MANUEL de validation lab : poste migré 2.10.0, item
HKCU `Policies\Explorer` type `RestrictRun`/`DisallowRun` changé avec hint
`policy_broadcast`, session ouverte → vérifier l'effet SANS relogon ni restart Explorer ;
critère de décision consigné pour la 43.2 (si suffisant : `explorer_restart` devient
l'exception) ; la story n'est PAS bloquée par cette validation (D6).

## Tasks / Subtasks

- [x] **T1 — Échelle + parsing (AC1)**
  - [x] `agent/shared/refresh.go` (nouveau) : `RefreshLevel` ordonné + constantes +
        `ParseRefreshLevel` indulgent + `String()` (logs) + interface `RefreshOps`
  - [x] `RegistrySpec.Refresh` / `RegistryListSpec.Refresh` : lecture du champ payload
        dans `parseRegistrySpec`/`parseRegistryListSpec` (indulgente, piège #1)
- [x] **T2 — Accumulation par handler + interface `RefreshRequester` (AC3, AC4)**
  - [x] `handler_registry.go` : remplacer `shellRefresh`/`registryNotifier`
        (:363,:392-394,:404-408) par l'accumulation `max(RefreshShellNotify,
        spec.Refresh)` sur `changed && isUserHive` ; exposer `TakeRefreshRequest()`
        (consommation + reset)
  - [x] `handler_registry_list.go` : même migration (:178,:220-222,:242-245,:251-255)
  - [x] Supprimer l'interface `registryNotifier` (ou la recycler — piège #5 : une seule
        voie d'émission) → SUPPRIMÉE (interface + les 2 blocs d'émission inline +
        `registryOps.NotifyShellChanged` Windows)
- [x] **T3 — Agrégation compagnon (AC3)**
  - [x] `companion.go` : champ `Refresh RefreshOps` (nil = no-op) ; en toute fin de
        `RunPass` (après drop, D5) : itérer `c.Engine.Handlers`, asserter
        `RefreshRequester`, prendre le max, exécuter LE geste, logger niveau + issue
  - [x] `engine.go` : ZÉRO diff (vérifié au `git diff` final — absent du diff)
- [x] **T4 — Gestes Windows (AC2)**
  - [x] `agent/windows/refresh_windows.go` (nouveau) : impl `RefreshOps` —
        `ShellNotify` (SHChangeNotify, déplacé/réutilisé de
        `handler_registry_windows.go:299-326`), `PolicyBroadcast`
        (SendMessageTimeoutW, piège #4), `RestartExplorer` (Toolhelp32 + session
        courante + garde anti-double-lancement, piège #3)
  - [x] `companion_windows.go` : injecter `Refresh` sur le `shared.Companion` (:84-179)
  - [x] `main_windows.go` : AUCUNE injection côté MachineEngine (piège #2 — fichier
        sans aucun diff)
- [x] **T5 — Tests portables (AC5)** : fake `RefreshOps` (séquence enregistrée) ;
      scénarios (a)→(g) de l'AC5 dans `refresh_test.go`/`companion_test.go` ; migration
      des tests `notifyCnt` existants (`handler_registry_test.go`,
      `handler_registry_list_test.go`) ; test négatif SYSTEM/HKU (piège #2)
- [x] **T6 — Version + publication (AC6)** : bump 2.10.0 + bloc changelog + note
      publication manuelle AVANT seeders 43.2 au Dev Agent Record + justification
      golden inchangés (D7)
- [x] **T7 — Runbook QA (AC7)** : section 43.1 dans `docs/qa/domains/agent.md`
      (protocole lab policy_broadcast sur RestrictRun/DisallowRun, critère de décision
      pour 43.2)

## Dev Notes

### Fichiers à toucher (prévu)

- `agent/shared/refresh.go` (A), `agent/shared/refresh_test.go` (A)
- `agent/shared/handler_registry.go` (M), `agent/shared/handler_registry_test.go` (M)
- `agent/shared/handler_registry_list.go` (M), `agent/shared/handler_registry_list_test.go` (M)
- `agent/shared/companion.go` (M), `agent/shared/companion_test.go` (M)
- `agent/shared/version.go` (M — 2.10.0)
- `agent/windows/refresh_windows.go` (A)
- `agent/windows/handler_registry_windows.go` (M — retrait/déplacement NotifyShellChanged)
- `agent/windows/companion_windows.go` (M — injection Refresh)
- `docs/qa/domains/agent.md` (M — append-only, section 43.1)
- **INTERDITS de diff** : `agent/shared/engine.go`, `agent/windows/main_windows.go`
  (hors commentaire éventuel), `app/**`, `tests/Fixtures/Agent/*.v1.json`,
  `config/agent.php`, `app/Services/Agent/StateCompiler.php` (43.2/43.3).

### Patterns existants à imiter (chemins worktree)

- **FFI sans cgo** : `agent/windows/handler_wallpaper_windows.go:47-56,133-141`
  (`NewLazySystemDLL`, `UTF16PtrFromString`, appel `proc.Call`) ;
  `handler_registry_windows.go:305-326` (SHChangeNotify).
- **Interface optionnelle consommée hors handler** : `DetailReporter`/
  `InventoryReporter` (`engine.go:109-127`) — MAIS ici la consommation est dans le
  COMPAGNON (D1), le moteur ne bouge pas.
- **Champ injectable nil-safe du compagnon** : `Companion.Watchdog` /
  `EnsureUserRainmeterIni` (`companion.go:57-72`) — même style pour `Refresh`.
- **Fake ops de test** : `fakeRegistryOps` (`handler_registry_test.go:20-45`) ;
  `newTestCompanion` (`companion_test.go`) pour les passes bout-en-bout.
- **Bloc de version** : en-tête de `agent/shared/version.go` (patron 2.9.0, lignes
  208-240 : quoi, pièges rollout, état des publications).

### Rappels transverses (garde-fous epic + projet)

- Story agent Go : bump `version.go` + publication manuelle
  (`feedback_agent_edit_bump_version`, `project_agent_handler_not_in_published_binary`).
- Tests : HÔTE uniquement — `go -C agent test ./...`,
  `GOOS=windows go -C agent build ./... && GOOS=windows go -C agent vet ./...`,
  toolchain `~/go-toolchain/go/bin` (hors PATH). `gofmt` sur les fichiers touchés.
- Le compagnon n'a NI réseau NI token (frontière NFR5, `companion.go:14-39`) : les
  gestes sont purement locaux, aucun changement de frontière.
- La fenêtre résiduelle au logon (~10-60 s avant convergence du compagnon) est ASSUMÉE
  (NFR-A5) : cette story ne la traite pas (pas d'apply synchrone pré-shell).
- Jamais `rm -rf` (utiliser `trash`) ; jamais de sync manuelle VM ; inotify ne couvre
  pas les deletes.

### Project Structure Notes

- Aucune route, aucune UI, aucun schéma : story 100 % agent Go. Aucun chevauchement de
  fichiers avec 43.2 (serveur/UI) ni 43.3 (StateCompiler/config) — parallélisables après
  celle-ci pour la 43.2 (gate de publication), immédiatement pour la 43.3.
- Le champ `refresh` N'EST PAS dans le wrapper d'item (contrat §3 : 4 clés figées) — il
  vit dans le `payload` (sous-structure provider-defined, §3.2). C'est ce qui permet
  golden inchangés en 43.1 et évolution mineure en 43.2.

### References

- [Source: _bmad-output/planning-artifacts/epics-application-immediate.md#Story-43.1 +
  Overview + FR-A1/FR-A2 + NFR-A1..A5 + Notes de coordination]
- [Source: docs/agent/contract-v1.md §3/§3.2 (wrapper vs payload), §8 (type sans
  handler), §9 (règle d'évolution — patron des bumps 2.3.0→2.9.0)]
- [Source: _bmad-output/planning-artifacts/architecture-agent-desired-state.md —
  frontières moteur/compagnon/SYSTEM, cœur portable]
- [Source: agent/shared/handler_registry.go:184-209,363,392-408 ;
  handler_registry_list.go:178,251-255 ; companion.go:145-199 ;
  agent/windows/handler_registry_windows.go:299-326 ; companion_windows.go:84-179 ;
  main_windows.go:183-196 ; handler_wallpaper_windows.go:47-56,133-141 — re-vérifiés
  2026-07-11]
- [Source: stories 38-3 (patron story agent + publication), 35-3 (piège n° 9 fan-out
  HKU sans geste), 27-8 (STRICT inconditionnel)]

## Dépendances

- **Amont : AUCUNE.** Indépendante de 43.3 (fichiers disjoints : elle ne touche ni
  `loop.go` ni le serveur). Développable immédiatement.
- **Aval : BLOQUE la 43.2** — au double titre du mécanisme (le hint n'a d'effet que si
  l'agent sait l'exécuter) et du rollout NFR-A4 : release **2.10.0 PUBLIÉE** (action
  manuelle hors story) AVANT tout seeder/retrofit 43.2. La 41.2 (`restrict_run`)
  consommera l'échelle via son seed en 43.2.
- La validation lab de `policy_broadcast` (AC7) est une action QA manuelle post-merge —
  elle informe le CHOIX de hint de la 43.2, pas la livraison de la 43.1.

## Recommandation Modèle Dev

**FABLE** — prescription explicite de l'epic (« Reco dev : fable — agent Go, cf.
`feedback_epic23_model_fable5` ») : story 100 % agent Go avec FFI Win32 délicates
(SendMessageTimeout, kill/relaunch du shell dans la bonne session), migration d'un
mécanisme existant sans régression et invariants de frontière (session 0, moteur
intouché). Review adversariale par le modèle opposé (opus) recommandée — criticité :
code qui termine `explorer.exe` sur tout le parc.

## Dev Agent Record

### Agent Model Used

claude-fable-5 (dev-story, worktree `ultradev/43-1`, 2026-07-11) — conforme à la
Recommandation Modèle Dev de la story.

### Debug Log References

- `go -C agent test ./...` (hôte, -count=1) : `ok sambaedu/agent/provision`,
  `ok sambaedu/agent/shared` — 0 échec (112 tests/sous-tests sur le périmètre
  Refresh|Registry|Companion).
- `GOOS=windows go -C agent build ./...` + `GOOS=windows go -C agent vet ./...` :
  OK (zéro cgo — FFI NewLazySystemDLL uniquement). `go vet` hôte : OK.
- `git diff` final : `agent/shared/engine.go` et `agent/windows/main_windows.go`
  ABSENTS du diff (zéro octet modifié) ; `tests/Fixtures/Agent/*.v1.json`
  intouchés.

### Completion Notes List

- **D1 réalisé tel quel** : `RefreshRequester{TakeRefreshRequest()}` dans
  `refresh.go`, accumulation PAR INSTANCE (`refreshWanted`) dans les deux
  handlers, consommation dans `Companion.runRefreshGesture()` appelé en TOUTE
  FIN de `RunPass` (après applied-state, drop ET le log de fin de passe — D5).
  L'accumulation est TOUJOURS drainée, même avec `Refresh == nil` (pas de geste
  fantôme si l'ops apparaît plus tard) — testé.
- **D2** : plancher `shell_notify` = `max(RefreshShellNotify, spec.Refresh)` sur
  `changed && isUserHive` — un hint ne peut qu'escalader ; hint fort sur item
  NON changé = zéro effet (gate changed strict) — testé.
- **D3 (nuance assumée)** : `ParseRefreshLevel(string) RefreshLevel` est PURE
  (signature de la story, pas de logger) ; le log debug du hint INCONNU vit
  dans `logUnknownRefreshHint` (refresh.go), appelé par
  `desiredSpecs`/`desiredListSpecs` (seuls foyers avec logger). Champ
  absent/vide = silencieux (cas nominal de TOUT le parc pré-43.2 — logger
  chaque item aurait pollué le debug). Tolérance de forme : trim + lowercase
  (le vocabulaire canonique reste serveur, AuthoringGuard 43.2).
- **Piège #5 (migration, pas empilement)** : interface `registryNotifier`
  SUPPRIMÉE de `handler_registry.go`, blocs d'émission inline supprimés des
  deux handlers, `registryOps.NotifyShellChanged` + FFI SHChangeNotify RETIRÉS
  de `handler_registry_windows.go` (import `x/sys/windows` devenu inutile,
  retiré) et RECRÉÉS dans `refresh_windows.go` (`refreshOps.ShellNotify`).
  UNE seule voie d'émission ; tests `notifyCnt` migrés vers
  `TakeRefreshRequest()` (adaptés, pas dupliqués).
- **Piège #3 (RestartExplorer)** : Toolhelp32 (`x/sys/windows`, zéro dépendance
  nouvelle) filtré sur `ProcessIdToSessionId == session courante` ;
  TerminateProcess best-effort par PID ; poll borné 3 s (250 ms) — un NOUVEAU
  PID explorer.exe dans la session ⇒ Windows a relancé le shell ⇒ on ne
  relance PAS ; s'il reste UN explorer vivant à la borne (ancien PID résistant)
  on ne relance pas non plus (jamais de fenêtre parasite) ; sinon lancement de
  `%WINDIR%\explorer.exe` via exec.Command (droits du compagnon, jamais
  d'élévation).
- **Piège #4 (PolicyBroadcast)** : `SendMessageTimeoutW(HWND_BROADCAST,
  WM_SETTINGCHANGE, 0, "Policy", SMTO_ABORTIFHUNG, 5000 ms)` — buffer UTF-16
  maintenu vivant (`runtime.KeepAlive` après le Call, patron wallpaper) ;
  retour 0 = erreur remontée → warning compagnon (best-effort D4).
- **Piège #2 (deux moteurs, mêmes types)** : injection de `refreshOps`
  UNIQUEMENT dans `companion_windows.go` ; `main_windows.go` sans aucun diff ;
  test négatif : fan-out HKU changé AVEC hint `explorer_restart` forgé ⇒
  `TakeRefreshRequest() == RefreshNone` (gate isUserHive).
- **D7 (golden/contrat INCHANGÉS — justification)** : le hint `refresh` vit
  dans le PAYLOAD des items (sous-structure provider-defined, contrat §3.2) —
  le wrapper 4 clés §3 est intact et AUCUN provider serveur n'émet encore le
  champ. La forme du wire ne change donc pas côté 43.1 (règle §9) : golden
  `tests/Fixtures/Agent/*.v1.json` strictement inchangés (vérifié au diff) ;
  la doc contrat (§7.1/§7.6), l'AuthoringGuard et la mise à jour des golden
  sont la Story 43.2.
- **AC6 — PUBLICATION MANUELLE OBLIGATOIRE (gate Epic 35, piège #8)** : la
  release **2.10.0 doit être publiée MANUELLEMENT** (update.sh ne publie
  JAMAIS — `project_agent_selfupdate_validated_publish_gap`) **AVANT de jouer
  tout seeder/retrofit 43.2** — un binaire ≤ 2.9.0 ignore le hint EN SILENCE
  (clés écrites, aucun geste : l'« effet immédiat » promis par l'UI serait un
  mensonge). État des publications consigné : à la création de la 38.3, les
  2.6.0 (fs_acl), 2.7.0 (firewall) et 2.8.0 (privilege) n'avaient JAMAIS été
  publiées ; le statut de publication de la 2.9.0 n'est PAS vérifiable depuis
  ce worktree (aucun accès VM/lab — piège #9) → à vérifier au moment de
  publier : si la 2.9.0 n'est pas sortie, la 2.10.0 livre les cinq lots d'un
  coup. La publication est une action HORS story (non tentée).
- **gofmt** : le code AJOUTÉ est gofmt-clean ; `gofmt -l` signale des écarts
  PRÉ-EXISTANTS dans companion.go/handler_registry.go (one-liners historiques,
  identiques sur des fichiers non touchés comme handler_firewall.go) — non
  reformatés pour ne pas polluer le diff de review hors périmètre.
- **AC7 (D6)** : validation lab de `policy_broadcast` DÉPORTÉE au runbook
  (`docs/qa/domains/agent.md`, section 43.1, scénario 43.1.1) avec le critère
  de décision pour le choix de hint de la 43.2 — non bloquant pour cette story.

### Corrections post-review (2026-07-11, 4 findings opus appliqués)

- **#1 (🟠) Throttle anti-thrash `explorer_restart`** : intervalle minimal de
  **10 minutes** entre deux restarts PAR INSTANCE de `Companion`
  (`explorerRestartMinInterval` + champ `lastExplorerRestart time.Time`,
  companion.go — en mémoire, jamais persisté : le thrash visé est intra-vie du
  compagnon, un redémarrage ré-arme légitimement). Dans la fenêtre : geste
  DÉGRADÉ en `policy_broadcast` + warning explicite (« THROTTLÉ … drift
  récurrent probable »). Premier restart jamais throttlé ; `shell_notify`/
  `policy_broadcast` jamais throttlés. Horloge = le champ `Now func()
  time.Time` DÉJÀ présent sur Companion (c.now()) — rien d'ajouté. Horodaté à
  la TENTATIVE (même en échec : le shell a pu être tué — le throttle protège
  la session, pas le succès du geste). Tests : (a) deux passes changed
  successives ⇒ [explorer_restart, policy_broadcast] + warning ; (b) avance
  d'horloge > 10 min ⇒ restart repart ; (c) gestes faibles jamais throttlés,
  même dans la fenêtre. Runbook : throttle documenté en intro 43.1 + étape 6
  du scénario 43.1.3.
- **#2 (🟡) Tests companion `policy_broadcast`** : le case médian de
  `runRefreshGesture` est désormais exercé — succès (séquence EXACTEMENT
  [policy_broadcast]) et `broadcastErr` non-nil (passe/drop/applied-state
  intacts, warning « policy_broadcast en échec » loggé, aucune erreur de
  passe). `fakeRefreshOps.broadcastErr` n'est plus déclaré-jamais-utilisé.
- **#3 (🟡) Log hint inconnu dédupliqué** : `desiredSpecs`/`desiredListSpecs`
  prennent un paramètre `logHints bool` — la trace `logUnknownRefreshHint`
  n'est émise que depuis le chemin **Test** (Apply re-parse les mêmes items
  dans la même passe §5 : la ligne partait DEUX fois par passe et par item).
  Solution minimale, zéro refactor ; testé sur les deux handlers (compteur
  d'occurrences == 1 après Test+Apply).
- **#4 (🟡) Garde anti-double-lancement — course post-borne** : avant le
  `exec.Command` de relance, délai de grâce supplémentaire
  (`explorerRelaunchGrace` = 1 s) puis ULTIME re-vérification de la présence
  d'un explorer.exe dans la session — abandon de la relance si présent
  (refresh_windows.go). La course RÉSIDUELLE (relance Windows entre ce dernier
  check et le Start) est incompressible, non testable hôte — assumée et
  documentée au runbook 43.1.3 (étape 5, hypothèse « relance rapide par
  Windows »).
- Vérifs : `go test -count=1 ./...` (hôte) 0 échec ; `GOOS=windows go build` +
  `go vet` OK ; `engine.go`/`main_windows.go` toujours ZÉRO diff ; code ajouté
  gofmt-clean (les écarts `gofmt -l` restants sont les one-liners
  PRÉ-existants déjà consignés ci-dessus). Version inchangée (2.10.0 — jamais
  publiée, les corrections font partie du même lot).

### File List

- `agent/shared/refresh.go` (A) — échelle `RefreshLevel` + `ParseRefreshLevel`
  indulgent + `String()` + `maxRefreshLevel` + `logUnknownRefreshHint` +
  interfaces `RefreshOps`/`RefreshRequester`
- `agent/shared/refresh_test.go` (A) — parsing indulgent, ordre de l'échelle,
  String, nil-safety
- `agent/shared/handler_registry.go` (M) — `RegistrySpec.Refresh`, lecture
  indulgente du hint, accumulation `refreshWanted` + `TakeRefreshRequest()`,
  suppression `registryNotifier` + émission inline
- `agent/shared/handler_registry_test.go` (M) — migration `notifyCnt` →
  `TakeRefreshRequest`, nouveaux tests hint (escalade, inconnu, HKLM, HKU forgé)
- `agent/shared/handler_registry_list.go` (M) — `RegistryListSpec.Refresh`,
  même migration (recordRefresh), suppression émission inline
- `agent/shared/handler_registry_list_test.go` (M) — migration `notifyCnt`,
  test hint sur conteneur
- `agent/shared/companion.go` (M) — champ `Refresh RefreshOps` +
  `runRefreshGesture()` en toute fin de `RunPass` (un geste max, best-effort)
- `agent/shared/companion_test.go` (M) — `fakeRefreshOps` (séquence) +
  scénarios AC5 (a)→(g) bout-en-bout
- `agent/shared/version.go` (M) — 2.9.0 → 2.10.0 + bloc changelog (patron des
  blocs existants, notes publication)
- `agent/windows/refresh_windows.go` (A) — impl `RefreshOps` production :
  ShellNotify (SHChangeNotify migré), PolicyBroadcast (SendMessageTimeoutW),
  RestartExplorer (Toolhelp32 + session + garde)
- `agent/windows/handler_registry_windows.go` (M) — retrait
  `NotifyShellChanged` + FFI shell32 (migrés vers refresh_windows.go), retrait
  import `x/sys/windows`
- `agent/windows/companion_windows.go` (M) — injection `Refresh:
  &refreshOps{...}` (compagnon SEUL)
- `docs/qa/domains/agent.md` (M) — section « Story 43.1 » append-only
  (scénarios 43.1.1 → 43.1.5 + check-list)
- `_bmad-output/implementation-artifacts/43-1-echelle-refresh-compagnon.md`
  (M) — checkboxes, Dev Agent Record, File List, Status
- `_bmad-output/implementation-artifacts/sprint-status.yaml` (M) — ligne
  43-1-echelle-refresh-compagnon → review
- **Intouchés (vérifiés)** : `agent/shared/engine.go`,
  `agent/windows/main_windows.go`, `tests/Fixtures/Agent/*.v1.json`,
  `docs/agent/contract-v1.md`, `app/**`, `config/agent.php`

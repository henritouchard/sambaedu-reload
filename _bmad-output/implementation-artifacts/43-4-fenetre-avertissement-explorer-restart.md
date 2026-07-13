# Story 43.4 : Agent — fenêtre d'avertissement avant redémarrage d'Explorer (`explorer_restart`)

Status: review

<!-- Suite de la Story 43.1 (échelle de rafraîchissement). Naît de la validation lab
     2026-07-13 (VM, poste testenrol 2.10.0) : `explorer_restart` rend bien un réglage de
     vue Explorer effectif en un seul logon (là où `shell_notify` échouait — F5 manuel
     requis), mais le redémarrage d'`explorer.exe` survient sans aucun avertissement, ~qq s
     après l'ouverture de session, alors que l'utilisateur peut déjà interagir. Cette story
     couvre CE trou d'UX. Périmètre 100 % agent Go — AUCUN changement serveur/contrat/golden
     (la fenêtre est une réaction locale du compagnon au geste, pas un nouveau hint). -->

## Story

En tant qu'utilisateur d'un poste géré,
je veux être averti par une brève fenêtre « patientez » juste avant que le bureau ne se
recharge (redémarrage d'`explorer.exe`),
afin de ne pas être surpris par le clignotement de la barre des tâches et de ne pas cliquer
dans le vide pendant le rafraîchissement.

En tant qu'administrateur du parc,
je veux que cet avertissement n'apparaisse QUE quand un redémarrage d'Explorer a réellement
lieu (jamais sur un logon stable, jamais pour les gestes légers),
afin de préserver le principe « zéro geste, zéro clignotement au régime stable » de l'Epic 43.

## Contexte & intention

L'échelle de rafraîchissement (43.1) exécute, en toute fin de `Companion.RunPass`, **un
seul** geste — le plus fort requis par les items HKCU effectivement changés :

`shell_notify` < `policy_broadcast` < `explorer_restart`

Seul `explorer_restart` est **visuellement perturbant** : il termine puis relance
`explorer.exe` dans la session du compagnon (barre des tâches qui disparaît/réapparaît
~2-3 s, fenêtres de l'Explorateur perdues — assumé NFR-A1, autres applis intactes). Les deux
gestes plus faibles sont invisibles. La validation lab (2026-07-13) a confirmé qu'`explorer_restart`
est le seul geste qui rend fiablement effectif un réglage de **vue** Explorer (Hidden,
HideFileExt) en un seul logon — donc il va être posé sur le lot des vues Explorer et sur
`restrict_run` (mode examen, Epic 41). Il sera de plus en plus fréquent : il mérite un
avertissement.

**Idée directrice (tranchée avec le PO 2026-07-13)** : quand — et seulement quand — le geste
résolu de la passe est un `explorer_restart` **réellement exécuté**, le compagnon lève sa
**propre** petite fenêtre top-most « Application des réglages, patientez… » AVANT de tuer le
shell, la maintient pendant le redémarrage, puis la ferme une fois le nouveau shell revenu.

**Correction de mécanique (piège central)** : la fenêtre appartient au **processus du
compagnon**, pas à Explorer — redémarrer `explorer.exe` ne la ferme donc PAS (c'est
précisément ce qui lui permet de *survivre* au restart et de couvrir le trou). C'est le
compagnon qui la ferme lui-même, après le retour du shell. « Fenêtre par défaut / kill en
l'absence d'action » se traduit ici par : **on ne la crée jamais** si le geste n'est pas un
`explorer_restart` exécuté.

**Ancrage code (re-vérifié 2026-07-13)** :

- `agent/shared/companion.go:238-296` — `runRefreshGesture()` : résolution du niveau max,
  `switch level`, throttle anti-thrash, appel `RestartExplorer()`. **Point d'insertion
  unique** : la branche `case RefreshExplorerRestart`, APRÈS le passage du throttle et AVANT
  `c.Refresh.RestartExplorer()`.
- `agent/shared/refresh.go:114-132` — interface `RefreshOps { ShellNotify();
  PolicyBroadcast() error; RestartExplorer() error }` (injectée, nil = no-op hôte). Point
  d'extension : une méthode de notice fakeable.
- `agent/windows/refresh_windows.go` — impl de production de `RefreshOps` (43.1) :
  `RestartExplorer` (Toolhelp32 + session courante + garde anti-double-lancement). Lieu
  d'implémentation de la fenêtre.
- `agent/windows/handler_wallpaper_windows.go:47-56,133-141` — **patron FFI de référence**
  (`NewLazySystemDLL("user32.dll")` DÉJÀ chargé, `NewProc`, `proc.Call`,
  `UTF16PtrFromString`, `runtime.KeepAlive`). Aucune fenêtre native n'existe encore dans
  l'agent : c'est le terrain neuf de cette story.
- `agent/shared/version.go` — `Version = "2.10.0"` → bump **2.11.0**.

## Décisions de design (tranchées en création de story)

- **D1 — Fenêtre gatée STRICTEMENT sur un `explorer_restart` exécuté.** Jamais pour
  `shell_notify`/`policy_broadcast`, jamais sur passe stable (`RefreshNone`), **jamais quand
  le restart est throttlé→dégradé en `policy_broadcast`** (pas de perturbation à couvrir).
  Insertion dans `runRefreshGesture`, branche `RefreshExplorerRestart`, APRÈS le check
  throttle, AVANT `RestartExplorer()`. C'est l'expression exacte de « par défaut affichée /
  kill en l'absence d'action » : l'absence d'action = on ne l'a jamais créée.
- **D2 — Fenêtre = processus compagnon, SURVIT au restart, fermée par le compagnon.** La
  fenêtre top-most vit dans le process du compagnon (pas parentée au shell) → le
  `TerminateProcess` d'`explorer.exe` ne la touche pas. Séquence :
  `ShowRestartNotice → (bref délai de lecture) → RestartExplorer → dismiss()`.
  `RestartExplorer` (43.1) sonde DÉJÀ le retour du shell (poll ~3 s + grâce 1 s) : à son
  retour, le nouveau shell est up → `dismiss()` ferme la fenêtre. Elle est donc visible de
  juste-avant-le-kill jusqu'à juste-après-le-retour du shell.
- **D3 — Méthode injectable, testable hôte.** Étendre `RefreshOps` d'une méthode
  `ShowRestartNotice(text string) (dismiss func())` (nil ops = no-op ; un fake enregistre
  l'appel et l'ordre du `dismiss` relativement à `RestartExplorer`). La LOGIQUE (quand
  montrer, dans quel ordre) est ainsi 100 % prouvable sur l'hôte Linux ; le rendu Win32
  reste derrière l'impl `agent/windows`.
- **D4 — Best-effort ABSOLU : la fenêtre ne doit JAMAIS retarder ni empêcher le restart.**
  Échec de création → warning loggé, `dismiss` = no-op, **le restart a lieu quand même** (le
  redémarrage est la valeur ; l'avertissement est un confort). `dismiss()` est idempotent et
  borné (jamais de blocage). Aucun statut d'item, aucune erreur de passe (patron D4 de 43.1).
- **D5 — Bref délai de lecture (lead time) borné et constant.** Const
  `restartNoticeLeadTime` (~2 s) entre l'affichage et le kill, pour laisser lire le message.
  Uniquement sur la branche restart, donc jamais au régime stable — le surcoût ne frappe que
  le logon où un réglage change réellement. Valeur tunable, documentée.
- **D6 — Libellé court, français, sans jargon.** Const (ex. « Application des réglages en
  cours — l'écran va se rafraîchir, merci de patienter quelques secondes. »). Pas de bouton,
  pas d'interaction : purement informatif, auto-fermé.
- **D7 — ZÉRO changement serveur/contrat/golden.** Aucun nouveau hint, aucun champ de
  payload, aucune projection : la fenêtre est une réaction LOCALE du compagnon au geste déjà
  résolu. `tests/Fixtures/Agent/*.v1.json`, `docs/agent/contract-v1.md`, `app/**`,
  `config/agent.php`, `StateCompiler.php` : INTOUCHÉS. Un opt-out serveur (masquer la
  fenêtre par parc) est HORS-SCOPE V1 (à ouvrir si un cas concret l'exige).

## ⚠️ Pièges & tensions (lire AVANT de coder)

1. **Piège #1 — la fenêtre DOIT survivre au restart.** Elle vit dans le process compagnon,
   NON parentée au shell (pas de `HWND` propriétaire lié à la barre des tâches). C'est ce qui
   la distingue d'un toast (emporté avec Explorer). Ne jamais la rendre enfant/propriété du
   shell.
2. **Piège #2 — message pump sur thread OS verrouillé.** Une fenêtre Win32 exige une boucle
   de messages (`GetMessage`/`DispatchMessage`) sur le thread qui l'a créée
   (`runtime.LockOSThread` sur une goroutine dédiée). `ShowRestartNotice` crée la fenêtre sur
   ce thread, attend qu'elle soit affichée (au moins un `UpdateWindow`/premier pump — sinon
   elle peut ne pas peindre avant le kill) PUIS rend `dismiss` ; `dismiss` poste un
   `WM_CLOSE`/`PostQuitMessage` pour sortir proprement la boucle et `DestroyWindow`.
3. **Piège #3 — `syscall.NewCallback` pour la WNDPROC.** Le WNDPROC est une callback stable
   (paquet, pas re-créée à chaque appel — `NewCallback` a un quota process-wide) ; messages
   non gérés → `DefWindowProcW`. `RegisterClassExW` UNE seule fois (`sync.Once` : ré-enregistrer
   la même classe échoue). Painter le texte via un contrôle `STATIC` enfant (auto-peint)
   plutôt qu'un `WM_PAINT`/GDI custom : moins de code, moins de surface de bug.
4. **Piège #4 — ne JAMAIS bloquer le restart.** Si la création échoue ou traîne au-delà d'un
   court timeout : warning + on continue vers `RestartExplorer` sans fenêtre. `ShowRestartNotice`
   ne doit pas pouvoir pendre la passe (borne dure). `dismiss()` appelable même si la fenêtre
   n'a jamais été créée (no-op).
5. **Piège #5 — session 0 exclue par construction.** `runRefreshGesture` n'est appelé QUE par
   le compagnon (session user) ; le `MachineEngine` SYSTEM ne le consomme jamais (43.1,
   piège n° 2) et ne produit jamais d'`explorer_restart`. Aucune fenêtre en session 0 (elle y
   serait invisible et dangereuse). Ne pas router la notice ailleurs que depuis
   `runRefreshGesture`.
6. **Piège #6 — throttle : pas de notice sur le geste dégradé.** Quand `explorer_restart` est
   throttlé (<10 min depuis le dernier), il DÉGRADE en `policy_broadcast` (43.1, review #1) :
   aucun kill de shell → aucune fenêtre. Placer `ShowRestartNotice` APRÈS le `if throttlé …
   return` existant, dans le seul chemin qui atteint `RestartExplorer`.
7. **Piège #7 — centrage multi-écran & lisibilité.** Petite fenêtre `WS_POPUP` +
   `WS_EX_TOPMOST` + `WS_EX_TOOLWINDOW` (pas de bouton barre des tâches), centrée sur le
   moniteur principal, fond lisible. Pas de barre de titre, pas de bordure de
   redimensionnement.
8. **Piège #8 — worktree/VM.** Tests Go sur l'HÔTE (toolchain `~/go-toolchain/go/bin` hors
   PATH) ; `GOOS=windows go -C agent build ./... && vet` (zéro cgo — FFI `NewLazySystemDLL`
   uniquement) ; e2e réel = après merge + publication 2.11.0 sur la VM. Jamais d'interaction
   VM depuis un worktree.

## Acceptance Criteria

### AC1 — Fenêtre gatée sur un `explorer_restart` EXÉCUTÉ (et rien d'autre)

**Given** une passe `Companion.RunPass` dont le geste résolu de fin de passe est
`explorer_restart`, throttle NON actif (le restart va réellement s'exécuter)
**When** `runRefreshGesture` atteint la branche `RefreshExplorerRestart`
**Then** `ShowRestartNotice` est appelé AVANT `RestartExplorer` ; `dismiss` est appelé APRÈS
le retour de `RestartExplorer`
**And** pour une passe résolue en `shell_notify`, `policy_broadcast`, `RefreshNone` (stable),
OU en `explorer_restart` **throttlé→dégradé** : `ShowRestartNotice` n'est **JAMAIS** appelé
(D1, piège #6). Prouvé sur hôte via fake `RefreshOps` (séquence enregistrée).

### AC2 — Cycle de vie & best-effort (jamais bloquer le restart)

**Given** un fake `RefreshOps`
**When** `ShowRestartNotice` renvoie un `dismiss` normal / renvoie un no-op après erreur
simulée
**Then** dans les deux cas `RestartExplorer` est appelé (le restart n'est jamais empêché par
la notice, D4) ; `dismiss` est idempotent (double appel sans effet) ; la passe, le drop et
l'applied-state restent intacts ; un échec de notice = warning loggé, jamais une erreur de
passe ni un statut d'item.

### AC3 — Fenêtre Windows en FFI (top-most, survit au restart, zéro cgo)

**Given** l'impl Windows de `ShowRestartNotice` (`agent/windows`)
**When** elle s'exécute dans la session du compagnon
**Then** une fenêtre `WS_POPUP` top-most (`WS_EX_TOPMOST | WS_EX_TOOLWINDOW`), sans bordure,
centrée, portant le libellé D6, est affichée ; elle appartient au process compagnon et
**n'est pas fermée par le redémarrage d'`explorer.exe`** (piège #1) ; la boucle de messages
tourne sur un thread OS verrouillé (piège #2) ; `dismiss` la détruit proprement
(`WM_CLOSE`/`DestroyWindow`) ; classe enregistrée une seule fois (piège #3)
**And** `GOOS=windows go -C agent build ./...` + `vet` passent (aucun cgo — `NewLazySystemDLL`).

### AC4 — Aucune régression du chemin `explorer_restart` (43.1)

**Given** les tests companion existants de l'échelle (43.1)
**When** la notice est branchée
**Then** la séquence observable du geste `explorer_restart` (kill + garde anti-double-lancement
+ throttle + dégradation) est **inchangée** ; le fake par défaut (sans notice) reste no-op ;
`agent/shared/engine.go` et `agent/windows/main_windows.go` restent **sans aucun diff**.

### AC5 — Session 0 / SYSTEM ne montre jamais de fenêtre

**Given** le `MachineEngine` SYSTEM
**When** une passe machine s'exécute (fan-out HKU, HKLM)
**Then** aucun `explorer_restart` n'est produit et `ShowRestartNotice` n'est jamais atteint
(chemin structurellement réservé au compagnon, piège #5) — test négatif hôte / argument
structurel documenté.

### AC6 — Version 2.11.0 + note de publication (gate Epic 35)

**Given** `agent/shared/version.go` à 2.10.0
**When** la story est livrée
**Then** version bumpée **2.11.0** avec bloc de changelog (patron existant) ; le Dev Agent
Record rappelle la publication MANUELLE (update.sh ne publie jamais) — cette story change un
comportement VISIBLE, elle DOIT être publiée pour prendre effet ; golden inchangés
(justification D7 consignée : aucun changement de wire).

### AC7 — Runbook QA (validation manuelle)

**Given** `docs/qa/domains/agent.md` (append-only)
**When** la section Story 43.4 y est ajoutée
**Then** elle décrit : (a) POSITIF — sur un poste 2.11.0, bascule d'une capacité de vue
Explorer (ou `restrict_run`) taguée `explorer_restart`, relogon → la fenêtre « patientez »
apparaît AVANT le clignotement de la barre des tâches et disparaît APRÈS le retour du shell,
les autres applis intactes ; (b) NÉGATIF — bascule d'une capacité `shell_notify` (ex.
`show_file_extensions` resté en `shell_notify`) → AUCUNE fenêtre ; (c) STABLE — relogon sans
changement → aucune fenêtre ; (d) THROTTLE — deux restarts <10 min → le second (dégradé) ne
montre pas de fenêtre.

## Tasks / Subtasks

- [x] **T1 — Interface + gating compagnon (AC1, AC2, AC4)**
  - [x] `agent/shared/refresh.go` : étendre `RefreshOps` de
        `ShowRestartNotice(text string) (dismiss func())` ; const libellé (D6) + lead time
        (D5) dans `shared`
  - [x] `agent/shared/companion.go` : dans `runRefreshGesture`, branche
        `RefreshExplorerRestart` APRÈS le check throttle et AVANT `RestartExplorer` —
        `dismiss := c.Refresh.ShowRestartNotice(notice)` ; court `sleep` lead time
        (best-effort, non bloquant pour le reste) ; `RestartExplorer()` ; `defer dismiss()`
        après retour. nil-safe (Refresh == nil déjà géré en amont).
- [x] **T2 — Fenêtre Windows FFI (AC3)**
  - [x] `agent/windows/notice_windows.go` (nouveau) OU extension de `refresh_windows.go` :
        `ShowRestartNotice` — `RegisterClassExW` (sync.Once), WNDPROC via
        `syscall.NewCallback` (→ `DefWindowProcW`), `CreateWindowExW`
        (`WS_POPUP`/`WS_EX_TOPMOST | WS_EX_TOOLWINDOW`), STATIC enfant pour le texte,
        centrage moniteur principal, `ShowWindow`+`UpdateWindow`, boucle de messages sur
        goroutine `LockOSThread` ; `dismiss` = `PostMessage(WM_CLOSE)`/`DestroyWindow`
        idempotent + borné. Best-effort : toute erreur → `dismiss` no-op + retour immédiat
        (piège #4).
  - [x] `agent/windows/companion_windows.go` : la même instance `refreshOps` porte la
        nouvelle méthode (aucune injection nouvelle côté MachineEngine — `main_windows.go`
        sans diff, piège #5).
- [x] **T3 — Tests portables hôte (AC1, AC2, AC5)** : `fakeRefreshOps` enregistre
      `ShowRestartNotice`/`dismiss` ; scénarios : restart non-throttlé ⇒ notice avant +
      dismiss après ; shell_notify/policy_broadcast/stable/throttlé ⇒ zéro notice ; erreur
      notice ⇒ restart quand même ; dismiss idempotent ; négatif SYSTEM/HKU.
- [x] **T4 — Version + publication (AC6)** : bump 2.11.0 + bloc changelog + note publication
      manuelle au Dev Agent Record + justification golden inchangés (D7).
- [x] **T5 — Runbook QA (AC7)** : section 43.4 dans `docs/qa/domains/agent.md` (positif +
      négatifs).

## Dev Notes

### Fichiers à toucher (prévu)

- `agent/shared/refresh.go` (M — méthode `ShowRestartNotice` sur `RefreshOps` + consts)
- `agent/shared/companion.go` (M — appel dans `runRefreshGesture`, branche restart)
- `agent/shared/companion_test.go` (M — fake étendu + scénarios AC1/AC2/AC5)
- `agent/shared/version.go` (M — 2.11.0)
- `agent/windows/notice_windows.go` (A) **ou** `agent/windows/refresh_windows.go` (M)
- `agent/windows/companion_windows.go` (M seulement si la construction de `refreshOps` bouge)
- `docs/qa/domains/agent.md` (M — append-only, section 43.4)
- **INTERDITS de diff** : `agent/shared/engine.go`, `agent/windows/main_windows.go`,
  `tests/Fixtures/Agent/*.v1.json`, `docs/agent/contract-v1.md`, `app/**`,
  `config/agent.php`, `app/Services/Agent/StateCompiler.php` (aucun changement serveur/wire).

### Patterns existants à imiter

- **FFI user32 sans cgo** : `agent/windows/handler_wallpaper_windows.go:47-56,133-141`
  (`modUser32 = NewLazySystemDLL("user32.dll")` DÉJÀ chargé, `NewProc`, `proc.Call`,
  `UTF16PtrFromString`, `runtime.KeepAlive`).
- **`RestartExplorer` (session courante, garde, poll retour shell)** :
  `agent/windows/refresh_windows.go` (43.1) — la notice s'enroule AUTOUR de cet appel.
- **Champ injectable nil-safe + fake ops** : `RefreshOps` / `fakeRefreshOps`
  (`agent/shared/refresh.go`, `companion_test.go` — 43.1).
- **Bloc de version** : en-tête `agent/shared/version.go` (patron 2.10.0 : quoi, pièges
  rollout, état des publications).

### Rappels transverses (garde-fous epic + projet)

- Story agent Go : bump `version.go` + publication manuelle
  (`feedback_agent_edit_bump_version`, `project_agent_selfupdate_validated_publish_gap`,
  `project_agent_handler_not_in_published_binary`).
- Tests HÔTE uniquement : `go -C agent test ./...`,
  `GOOS=windows go -C agent build ./... && GOOS=windows go -C agent vet ./...`, toolchain
  `~/go-toolchain/go/bin` (hors PATH), `gofmt` sur les fichiers touchés.
- Le compagnon n'a NI réseau NI token : la fenêtre est purement locale, aucune frontière
  franchie.
- La fenêtre résiduelle au logon (~10-60 s) reste ASSUMÉE (NFR-A5) — cette story ne touche
  PAS l'ordonnancement de convergence, elle enrobe seulement le geste `explorer_restart`.
- Jamais `rm -rf` (utiliser `trash`) ; jamais de sync manuelle VM ; inotify ne couvre pas
  les deletes.

### Project Structure Notes

- Story 100 % agent Go : aucune route, aucune UI serveur, aucun schéma, aucun golden. Ne
  chevauche aucun fichier serveur ; s'appuie sur le mécanisme `explorer_restart` déjà livré
  par la 43.1 (mergée, agent 2.10.0 publié).
- Terrain neuf : première fenêtre native de l'agent. Le risque est concentré dans la FFI
  Win32 (message pump, WNDPROC, survie au restart) — d'où le fake côté logique et la
  validation e2e manuelle post-publication.

### References

- [Source: _bmad-output/planning-artifacts/epics-application-immediate.md — Epic 43,
  NFR-A1 (~2 s, applis intactes), NFR-A5 (fenêtre logon assumée)]
- [Source: _bmad-output/implementation-artifacts/43-1-echelle-refresh-compagnon.md — échelle,
  D5 (un geste fin de passe), review #1 (throttle 10 min + dégradation), pièges #2/#3]
- [Source: agent/shared/companion.go:238-296 (runRefreshGesture) ; refresh.go:114-132
  (RefreshOps) ; agent/windows/refresh_windows.go (RestartExplorer) ;
  handler_wallpaper_windows.go:47-56,133-141 (patron FFI user32) — re-vérifiés 2026-07-13]
- [Source: docs/qa/domains/agent.md §43.1 (scénarios explorer_restart 43.1.3) — la 43.4
  ajoute l'avertissement autour du même geste]
- [Source: validation lab VM 2026-07-13 — explorer_restart confirmé seul geste efficace pour
  les vues Explorer ; motive la fréquence croissante du geste et donc l'avertissement]

## Dépendances

- **Amont : 43.1 LIVRÉE + agent 2.10.0 PUBLIÉ** (mécanisme `explorer_restart` en place). ✅
- **Aval : aucune.** Indépendante de 43.2/43.3 et de l'Epic 41. Se combine naturellement avec
  la future migration de seed qui bascule le lot des vues Explorer en `explorer_restart`
  (les deux se renforcent mais ne se bloquent pas).
- Release **2.11.0 à publier manuellement** (action hors story) pour que l'avertissement
  prenne effet sur le parc.

## Recommandation Modèle Dev

**FABLE** — story 100 % agent Go, cohérente avec la prescription de l'epic
(`feedback_epic23_model_fable5`). Cœur délicat = FFI Win32 neuve (création de fenêtre,
message pump sur thread verrouillé, WNDPROC via `NewCallback`, survie au restart) + gating
best-effort qui ne doit JAMAIS empêcher le redémarrage. Review adversariale par le modèle
opposé (opus) recommandée — criticité : code qui dessine une fenêtre top-most juste avant de
tuer le shell sur tout le parc (un blocage y serait très visible).

## Dev Agent Record

### Agent Model Used

claude-fable-5 (2026-07-13, dev-story sur main — pas de worktree).

### Debug Log References

- `~/go-toolchain/go/bin/go -C agent test ./...` : VERT (shared 2.6 s, provision
  cached ; 6 nouveaux tests 43.4 + tous les tests 43.1 inchangés).
- `GOOS=windows go -C agent build ./...` : OK (zéro cgo — `x/sys/windows`
  n'exporte pas `GetModuleHandleW` → `GetModuleHandleEx(0, nil, &h)` via helper
  `noticeModuleHandle()`, seul écart au plan).
- `GOOS=windows go -C agent vet ./...` : OK.
- `gofmt -l` sur les 5 fichiers touchés : rien (après `gofmt -w`).

### Completion Notes List

- **T1** : `RefreshOps.ShowRestartNotice(text) (dismiss func())` (contrat
  best-effort documenté dans l'interface) ; consts `restartNoticeText` (D6) et
  `restartNoticeLeadTime` = 2 s (D5) dans `shared/refresh.go`. Gating dans
  `runRefreshGesture` : branche `RefreshExplorerRestart`, APRÈS le check
  throttle (seul chemin atteignant `RestartExplorer`), AVANT le kill —
  `show → sleep(leadTime) → RestartExplorer → dismiss()` (dismiss appelé même
  en échec du geste, garde anti-nil défensive). NOUVEAU champ injectable
  `Companion.NoticeLeadTime` (défaut = const via `defaultDuration`) — requis
  pour que la suite de tests ne dorme pas 2 s par restart (D5 « valeur
  tunable » honorée au passage).
- **T2** : NOUVEAU `agent/windows/notice_windows.go` — première fenêtre native
  de l'agent. `RegisterClassExW` sous `sync.Once` (erreur mémorisée), WNDPROC
  stable au niveau paquet via `windows.NewCallback` (quota process-wide ;
  WM_DESTROY→PostQuitMessage, reste→`DefWindowProcW`), `CreateWindowExW`
  `WS_POPUP` + `WS_EX_TOPMOST|WS_EX_TOOLWINDOW` centrée moniteur principal,
  STATIC enfant `SS_CENTER` (police `DEFAULT_GUI_FONT`), `ShowWindow(SW_SHOWNA)`
  (pas de vol de focus) + `UpdateWindow`, pump sur goroutine `LockOSThread`.
  `dismiss` = `PostMessageW(WM_CLOSE)` cross-thread (la boucle détruit sur son
  propre thread) + attente bornée 2 s, idempotent (`sync.Once`), sûr si la
  fenêtre n'a jamais existé ; création bornée à 1 s (au-delà : warning + on
  part sans fenêtre ; si elle aboutit après coup, la goroutine la détruit —
  jamais de fenêtre orpheline). `companion_windows.go` : AUCUN diff nécessaire
  (la méthode se greffe sur la même instance `*refreshOps`) ; `main_windows.go`
  et `engine.go` : ZÉRO diff (vérifié git).
- **T3** : `fakeRefreshOps` étendu — `events` (ordre complet gestes +
  show_notice/dismiss) SÉPARÉ de `seq` (gestes seuls) pour que les assertions
  43.1 restent byte-identiques (AC4) ; `noticeFails` mime le contrat D4
  (dismiss no-op, jamais nil), `dismissCalls`/`lastDismiss` pour l'idempotence.
  6 nouveaux tests : ordre notice→restart→dismiss + libellé D6 (AC1) ; zéro
  notice sur shell_notify/policy_broadcast/stable (AC1/D1) ; zéro notice sur
  restart throttlé→dégradé (piège #6) ; échec notice + échec geste ⇒ restart
  tenté quand même, passe/drop/applied-state intacts (AC2/D4) ; dismiss
  idempotent (AC2) ; négatif portée machine + argument structurel SYSTEM/HKU
  (AC5 — s'appuie sur `TestRegistryHkuNeverTriggersShellRefresh` de 43.1).
- **T4** : `Version` 2.10.0 → **2.11.0**, bloc changelog au patron (quoi,
  pièges rollout, état des publications). **Golden inchangés (D7)** : aucun
  changement de wire — la fenêtre est une réaction locale du compagnon au
  geste déjà résolu, aucun hint/champ/projection nouveau ; `git status` sur
  `tests/Fixtures/Agent/`, `docs/agent/contract-v1.md`, `config/agent.php`,
  `StateCompiler.php`, `engine.go`, `main_windows.go` : propre.
- **T5** : section « Story 43.4 » append-only dans `docs/qa/domains/agent.md`
  (scénarios 43.4.1 positif, 43.4.2 négatif shell_notify/policy_broadcast,
  43.4.3 stable, 43.4.4 throttle + check-list) — numérotation existante
  intacte.
- ⚠️ **PUBLICATION MANUELLE 2.11.0 REQUISE** (hors story) : update.sh ne
  publie jamais seul, et cette story change un comportement VISIBLE — un
  binaire ≤ 2.10.0 redémarre Explorer sans avertissement. Vérifier au moment
  de publier si la 2.10.0 (gate des seeders 43.2) l'a été.
- Validation e2e réelle de la fenêtre (rendu Win32, survie au restart) =
  runbook QA 43.4 post-publication — non testable hôte, comme prévu par la
  story.

### File List

- `agent/shared/refresh.go` (M — méthode `ShowRestartNotice` sur `RefreshOps`,
  consts `restartNoticeText`/`restartNoticeLeadTime`)
- `agent/shared/companion.go` (M — champ `NoticeLeadTime` + helper, gating
  notice dans `runRefreshGesture` branche restart)
- `agent/shared/companion_test.go` (M — fake étendu `events`/`noticeFails`/
  `dismissCalls`, lead time 1 ms au banc d'essai, 6 tests 43.4)
- `agent/shared/version.go` (M — 2.11.0 + changelog)
- `agent/windows/notice_windows.go` (A — fenêtre FFI complète)
- `docs/qa/domains/agent.md` (M — append-only, section 43.4)
- `_bmad-output/implementation-artifacts/sprint-status.yaml` (M — statut 43-4)
- `agent/windows/companion_windows.go` : AUCUN diff (même instance
  `refreshOps`) ; `engine.go`/`main_windows.go`/golden/serveur : intouchés.

### Corrections post-review (opus adversariale, 2026-07-13)

Review complète : `_bmad-output/codeReviews/43-4.md`. Verdict : approuvé avec
réserves mineures (aucun critique/important, 7 mineurs). Corrections appliquées :

- **#1 — Fuite refcount `GetModuleHandleEx`** : ajout du flag
  `GET_MODULE_HANDLE_EX_FLAG_UNCHANGED_REFCOUNT` (`0x2`) → appel iso-
  `GetModuleHandleW`, plus de bump du refcount à chaque `explorer_restart`.
- **#3 — Fenêtre figée sur `GetMessage == -1`** : `pumpNoticeMessages(hwnd)`
  détruit désormais la fenêtre (`DestroyWindow`, thread créateur) sur le chemin
  d'erreur avant de sortir — plus de fenêtre top-most résiduelle.
- **#4 — Nom de test surpromettant** : `TestCompanionRestartNoticeNeverReached
  FromMachineScope` → `TestCompanionIgnoresMachineScopeItems` (la garantie
  session 0 reste structurelle, cf. `TestRegistryHkuNeverTriggersShellRefresh`).
- **#2 — Lead-time payé même sans fenêtre** (tranché user : corriger) :
  `ShowRestartNotice` remonte désormais `(shown bool, dismiss func())` ; le
  compagnon ne dort le lead time QUE si `shown == true` (interface + impl
  Windows + fake + site d'appel mis à jour).
- **#6 (DPI)** : laissé au runbook (décision user) — fenêtre agrandie suffisante
  en 1re intention. #5 (idempotence réelle) / #7 (borne lead time) : limites
  assumées (voir doc de review).

### Amélioration design fenêtre (demande user post-review)

- **Spinner animé + typographie** : ajout d'un spinner ASCII (`|/-\` via
  `WM_TIMER` sur le thread dédié de la fenêtre, ~120 ms/frame) et de polices
  `CreateFontW` Segoe UI (message ~14 pt semibold, spinner ~22 pt bold ;
  fenêtre agrandie 480×184). L'animation vit sur le thread propre de la fenêtre
  → best-effort D4 préservé (jamais de retard/blocage du restart). Nettoyage
  `KillTimer` + `DeleteObject` au `WM_DESTROY`. Zéro comctl32, zéro cgo.
  Build/vet Windows + tests hôte re-vérifiés verts. Runbook 43.4.1 enrichi.

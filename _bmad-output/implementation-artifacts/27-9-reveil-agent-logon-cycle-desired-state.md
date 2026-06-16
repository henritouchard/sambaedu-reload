# Story 27.9: Réveil de l'agent au logon — cycle desired-state

Status: ready-for-dev

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

En tant qu'utilisateur d'un poste géré par SambaEdu,
je veux que l'agent applique l'état cible (config + self-update) dès l'ouverture de ma session Windows,
afin de ne plus attendre le prochain tick de polling (jusqu'à ~1 h, voire 24 h) pour voir mes raccourcis, lecteurs, imprimantes et mises à jour converger.

## Contexte & problème

Aujourd'hui, la convergence de l'agent Go (`agent/shared/loop.go` → `Run`) est pilotée **uniquement par le ticker de polling**, jamais par un événement de session :

- 1er cycle **immédiat** au démarrage du service (pas de sleep initial — `Run` exécute `RunCycle` avant le premier `select` de sieste, `loop.go:506-516`) ;
- puis sieste jusqu'au prochain cycle : `DefaultIntervalSeconds = 3600` s (1 h) + jitter ±10 % (`files.go:34-36`, `loop.go:535-541`), ou le `ttl_seconds` serveur clampé `[MinServerIntervalSeconds=60, MaxServerIntervalSeconds=86400]` (`loop.go:131-134`, `EffectiveInterval` `loop.go:426-440`) ;
- en cas d'erreur réseau au boot : backoff exponentiel 30 s → 60 s → 120 s… plafonné à la cadence nominale (`NextBackoff` `loop.go:468-478`).

La sieste est un `select { case <-ctx.Done(): … case <-time.After(sleep): }` (`loop.go:544-550`). **Rien n'interrompt cette sieste sur un logon.** Conséquence : un utilisateur qui ouvre sa session juste après un cycle peut attendre jusqu'à la cadence complète avant que toute MAJ de config / desired-state / self-update n'arrive. C'est le « il ne se passe rien pendant un moment » au logon.

**L'infrastructure est déjà à moitié là.** Le service Windows s'abonne **déjà** à `svc.AcceptSessionChange` et réagit au `WTS_SESSION_LOGON` (`agent/windows/service_windows.go:42-73`) — mais aujourd'hui **uniquement pour réécrire `overlay.json`** (Story 27.1bis), **PAS** pour réveiller la boucle `Run`. Il n'y a donc **ni tâche planifiée à créer, ni nouvelle dépendance** : il suffit de réutiliser ce hook logon existant pour réveiller la sieste.

## Décisions de design (validées par Henri)

1. **Réutiliser le hook logon SCM existant** (`service_windows.go`, branche `WTS_SESSION_LOGON`) — pas de tâche planifiée « At log on », pas de nouvelle dépendance.
2. **Mécanisme = canal de réveil.** Ajouter un canal `wake` au `select` de sieste de `Run`. Au logon, le handler SCM y poste un signal **NON-BLOQUANT** → la sieste est interrompue → un cycle frais part immédiatement.
3. **Déclencheur = CYCLE COMPLET** (`RunCycle` : poll `/state` + portée session + sync assets/icônes/Rainmeter + self-update + report), **pas** seulement la réécriture overlay. La réécriture overlay (27.1bis) reste en place **en plus**.
4. **Garde-fou anti-martèlement = MIN-INTERVAL (debounce).** Un réveil logon ne déclenche un cycle frais **que si** un délai minimum s'est écoulé depuis le **début du dernier cycle**, pour éviter le martèlement sur des logons/déconnexions rapprochés (sessions multiples, RDP qui claque, fast user switching).

## Acceptance Criteria

1. **Given** l'agent en sieste (entre deux ticks de polling) **When** une session Windows interactive s'ouvre (`WTS_SESSION_LOGON`) **Then** la sieste est interrompue et un **cycle complet** (`RunCycle`) démarre immédiatement — sans attendre la fin de l'intervalle nominal / TTL serveur.

2. **Given** le handler SCM qui reçoit l'événement logon **When** il signale le réveil à la boucle **Then** l'envoi est **non-bloquant** : il ne bloque jamais le thread de contrôle du service (`Execute`), même si la boucle est occupée (cycle en vol, HTTP lent) ou déjà réveillée — le signal est coalescé, jamais mis en file illimitée.

3. **Given** un dernier cycle démarré il y a **moins de** `MinLogonWakeIntervalSeconds` **When** un réveil logon survient **Then** le debounce **empêche** un nouveau cycle immédiat (coalescence) ; le cycle se déclenchera au plus tôt à l'expiration du min-interval, ou au tick nominal si celui-ci arrive avant. Plusieurs logons rapprochés ⇒ **au plus un** cycle dans la fenêtre min-interval.

4. **Given** la branche `WTS_SESSION_LOGON` existante (27.1bis) **When** un logon survient **Then** la réécriture de `overlay.json` pour toutes les sessions **continue de fonctionner sans régression** — le réveil de la boucle s'ajoute à l'écriture overlay, il ne la remplace pas et ne l'ordonne pas (les deux sont best-effort, indépendants, et une panique de l'un ne tue ni le SCM ni l'autre).

5. **Given** l'absence d'événement logon **When** l'agent tourne normalement **Then** le comportement de ticker / jitter / TTL serveur / backoff exponentiel reste **strictement inchangé** (cadence nominale, clamps, sieste, sortie sur `ctx.Done()` / 401 `OutcomeStop`).

6. **Given** une plateforme sans sessions Windows (console de debug `runConsole`, tests hôte Linux) **When** la boucle tourne **Then** le mécanisme de réveil est **inerte / no-op** (canal nil-safe) : aucun panic, aucune dépendance Windows tirée dans `agent/shared`.

## Tasks / Subtasks

- [ ] **T1 — Canal de réveil dans `Agent` (`agent/shared/loop.go`)** (AC: 1, 2, 6)
  - [ ] Ajouter un champ canal de réveil bufferisé taille 1 (ex. `wake chan struct{}`), créé à la construction (voir T4) ou lazy-initialisé ; documenter qu'un canal nil = réveil inerte (tests/console).
  - [ ] Exposer une méthode `RequestWake()` qui poste sur le canal en **send non-bloquant** (`select { case a.wake <- struct{}{}: default: }`) — nil-safe, jamais de blocage, coalescence naturelle (buffer 1).
- [ ] **T2 — Interruption de sieste + debounce dans `Run` (`agent/shared/loop.go`)** (AC: 1, 3, 5)
  - [ ] Suivre l'instant de **début du dernier cycle** (`time.Time`, mis à jour juste avant `RunCycle`).
  - [ ] Ajouter `case <-a.wake:` au `select` de sieste (`loop.go:544-550`), aux côtés de `ctx.Done()` et `time.After(sleep)`.
  - [ ] Sur réveil : si `time.Since(dernierCycle) >= MinLogonWakeIntervalSeconds` → repartir en haut de boucle pour un cycle frais ; sinon (debounce) → **re-sleeper** le reliquat (`MinLogonWakeIntervalSeconds - elapsed`) au lieu de lancer le cycle, sans réinitialiser le backoff (le tick nominal reste l'échéance de repli).
  - [ ] Garantir AC5 : hors réveil, la logique de cadence/jitter/backoff/`OutcomeStop` est intacte (aucune branche existante modifiée hors ajout du `case`).
- [ ] **T3 — Constante de debounce (`agent/shared/files.go`)** (AC: 3)
  - [ ] Ajouter `MinLogonWakeIntervalSeconds` (valeur par défaut proposée : **60 s**, iso plancher `MinServerIntervalSeconds`) avec un commentaire explicitant le rôle anti-martèlement.
- [ ] **T4 — Câblage SCM au logon (`agent/windows/service_windows.go`)** (AC: 1, 2, 4)
  - [ ] Dans la branche `if req.EventType == windows.WTS_SESSION_LOGON` : conserver `writeOverlayForAllSessions(...)` (27.1bis) **ET** ajouter l'appel à `agent.RequestWake()`.
  - [ ] Garder l'appel best-effort : ni l'écriture overlay ni le réveil ne doivent bloquer le SCM ; la garde `recover()` existante (overlay) reste, le `RequestWake()` non-bloquant n'a pas besoin de garde mais ne doit pas être placé sous une panique de l'overlay (les rendre indépendants).
  - [ ] S'assurer que le canal de réveil partagé entre la goroutine `Run` et le handler est initialisé **avant** que `Run` ne démarre (créer le canal dans `newAgent` est le plus sûr).
- [ ] **T5 — Tests hôte (`agent/shared/loop_test.go`)** (AC: 1, 3, 5, 6)
  - [ ] Test : un `RequestWake()` interrompt la sieste de `Run` et provoque un cycle frais avant l'échéance nominale (intervalle de test long, wake immédiat, compter les cycles ; piloter via `Client`/serveur de test existant et `ctx` annulé après le 2e cycle).
  - [ ] Test debounce : deux `RequestWake()` rapprochés (< min-interval) ⇒ **un seul** cycle supplémentaire dans la fenêtre.
  - [ ] Test nil-safe : `RequestWake()` sur un `Agent` sans canal (ou canal nil) ne panique pas (AC6).
  - [ ] Non-régression : `ctx.Done()` sort toujours proprement même avec un réveil concurrent ; les tests existants `RunCycle*` / `EffectiveInterval*` / `NextBackoff*` restent verts.
- [ ] **T6 — Vérifications de build & non-régression** (AC: 4, 5, 6)
  - [ ] `go vet` + `go test ./agent/shared/...` (hôte Linux) verts.
  - [ ] Cross-compile Windows (`GOOS=windows`) vert — le câblage `service_windows.go` compile, l'overlay 27.1bis inchangé.
  - [ ] Aucun nouvel import Windows dans `agent/shared` (le réveil est plateforme-agnostique).

## Dev Notes

### Architecture & contraintes

- **Périmètre = runtime/boucle agent, PAS un handler de ressource.** Les stories 27.1–27.6 suivent le pattern « 1 StateProvider + 1 handler + identifiant de type + golden file ». 27.9 ne touche **ni au contrat d'état, ni aux golden files, ni au serveur SE5** : c'est purement le **moteur de la boucle Go**. Aucun bump de `FROZEN_STATE_HASH`, aucune migration, aucune route. [Source: `_bmad-output/planning-artifacts/epics-agent-desired-state.md#Epic-27`]
- **« Cycle complet » = `RunCycle`** (`loop.go:141-321`) : il enchaîne déjà GET `/state` (ETag), portée session (`fetchSessionStates`), `SyncWallpaperAssets`, `SyncShortcutIcons`, `SyncRainmeterTool`, `SelfUpdate`, puis POST `/report`. Réveiller la boucle = relancer une itération de `Run` → un `RunCycle`. **Ne pas** appeler des sous-étapes à la main depuis le SCM.
- **Non-blocage SCM (lucidité 27.1bis).** Le commentaire `service_windows.go:54-60` est explicite : « une composition/écriture overlay ne doit JAMAIS bloquer le SCM ». Le réveil suit la même règle — `send` non-bloquant sur canal bufferisé 1, jamais de `RunCycle` synchrone dans le thread `Execute` (le cycle tourne dans la goroutine `Run`, c'est elle qu'on réveille).
- **Coalescence (buffer 1 + send `default`).** Si la boucle est déjà occupée ou un réveil est déjà en attente, le `select … default` jette le signal en trop : 50 logons en rafale ⇒ au plus un réveil pendant. Le debounce min-interval (T2/T3) couvre le cas « la boucle se réveille puis re-dort » — les deux mécanismes sont complémentaires (l'un côté producteur SCM, l'autre côté consommateur boucle).
- **Debounce placé côté boucle (single-thread), pas côté SCM.** La fenêtre min-interval se mesure dans `Run` (goroutine unique propriétaire de l'instant « dernier cycle ») pour éviter toute course sur un état partagé entre le SCM et la boucle. Le SCM ne fait que poster un signal ; la boucle décide si elle l'honore.
- **Interaction backoff.** Un réveil pendant une sieste de backoff (serveur injoignable au boot) interrompt aussi le sleep ; si le debounce autorise, le cycle frais retente — utile si le réseau est revenu au logon. Le backoff n'est **pas** réinitialisé par le réveil lui-même (seul un cycle `OutcomeOK` le remet à 0, logique existante `loop.go:533-534`). Ne pas transformer le réveil en bypass du garde-fou anti-martèlement réseau (FR22).
- **Premier cycle / boot.** Le 1er cycle reste immédiat au démarrage du service (inchangé). Le réveil adresse le **2e+ cas** : un logon qui tombe pendant la longue sieste qui suit.

### Source tree — fichiers à toucher

- `agent/shared/loop.go` — champ canal `wake` + `RequestWake()` + instant « dernier cycle » + `case <-a.wake` dans le `select` de `Run` + logique debounce. **Cœur de la story, testable hôte.**
- `agent/shared/files.go` — constante `MinLogonWakeIntervalSeconds` (près de `DefaultIntervalSeconds`, ~ligne 34).
- `agent/windows/service_windows.go` — appel `agent.RequestWake()` dans la branche `WTS_SESSION_LOGON`, à côté de `writeOverlayForAllSessions`.
- `agent/shared/loop_test.go` — nouveaux tests réveil + debounce + nil-safe.
- (`agent/windows/*` construction `newAgent`) — initialiser le canal `wake` à la création de l'`Agent` partagé entre `Run` et le handler SCM.

### Testing standards

- L'orchestration `agent/shared` se teste **entièrement sur l'hôte Linux** (pas de primitive Windows) — `loop_test.go` utilise déjà un `Client` HTTP sur serveur de test + `Store` sur `t.TempDir()` + `ctx` annulable. Réutiliser ces helpers ; piloter le timing avec des intervalles courts et un `ctx` annulé après N cycles comptés.
- La partie `service_windows.go` est `//go:build windows` : non testée unitairement sur l'hôte → couverture par **cross-compile + `go vet`** (le câblage y est volontairement trivial : un appel non-bloquant). Garder la logique métier (debounce, coalescence) **dans `agent/shared`** pour qu'elle soit testable.
- Ne pas introduire de `time.Sleep` long dans les tests ; s'appuyer sur les canaux (`wake`, `ctx.Done()`, signal de fin de cycle via le serveur de test).

### Pièges (à baliser mécaniquement)

- **Send bloquant = SCM gelé.** Un `a.wake <- struct{}{}` sans `default` peut bloquer le thread SCM si la boucle ne consomme pas → service qui ne répond plus aux `Stop`/`Interrogate`. **Toujours** le `select … default`.
- **Canal non initialisé.** Si `RequestWake` est appelé avant que `Run` ait créé le canal → nil channel (send bloque pour toujours) ou panic. Créer le canal à la **construction** de l'`Agent` (`newAgent`), nil-safe dans `RequestWake`.
- **Régression overlay 27.1bis.** Ne pas déplacer/supprimer `writeOverlayForAllSessions` ni sa garde `recover()`. Le réveil s'**ajoute**, indépendant (une panique overlay ne doit pas empêcher le réveil et vice-versa).
- **Ne pas toucher la cadence nominale.** L'ajout du `case <-a.wake` ne doit modifier aucun calcul de `sleep`/jitter/backoff existant (AC5). Le debounce ne s'applique qu'au chemin « réveil », jamais au tick normal.
- **Pas de bypass anti-martèlement réseau.** Le réveil n'efface pas le backoff ; il interrompt seulement la sieste, et le debounce empêche le spin sur des logons rapprochés.

### Project Structure Notes

- Conforme au layout agent Go existant : logique plateforme-agnostique dans `agent/shared`, câblage Windows (SCM/WTS) dans `agent/windows`. Aucune nouvelle dépendance `go.mod`. Aucun impact serveur SE5 (Laravel) ni contrat v1.
- Worktree git `logonTriggerAgent` : **aucune interaction VM** ([[feedback_worktree_no_vm_sync]]). Le dev se fait en local (`go test`/`vet`/cross-compile hôte, toolchain `~/go-toolchain` [[project_host_go_toolchain_path]], `package main` = `agent/windows`). La validation sur poste Windows réel (logon → cycle observé dans les logs agent) est une **action humaine** post-merge, hors worktree.
- Pas de release agent à publier dans le périmètre dev de la story ; la livraison effective au parc (publier = tester, [[project_zero_prod_publish_is_test]]) est l'étape de validation post-review d'Henri.

### References

- [Source: `agent/shared/loop.go#Run`] — boucle principale, `select` de sieste `loop.go:544-550`, cadence/jitter/backoff.
- [Source: `agent/shared/loop.go#RunCycle`] — définition du « cycle complet » (state + session + assets + self-update + report).
- [Source: `agent/windows/service_windows.go#Execute`] — handler SCM, `AcceptSessionChange`, branche `WTS_SESSION_LOGON` (27.1bis) `service_windows.go:42-73`.
- [Source: `agent/shared/files.go#DefaultIntervalSeconds`] — cadence par défaut 3600 s, emplacement de la nouvelle constante.
- [Source: `agent/shared/loop.go#MinServerIntervalSeconds`] — plancher 60 s (référence pour la valeur de debounce).
- [Source: `_bmad-output/planning-artifacts/epics-agent-desired-state.md#Epic-27`] — Epic 27, pattern par story, périmètre handlers vs runtime.

### Modèle recommandé

**Fable** (`claude-fable-5`) — consigne projet pour les stories agent desired-state ([[feedback_epic23_model_fable5]]). Story bien cadrée, patterns iso-existants déjà lus (canal/`select` Go, hook SCM 27.1bis), zéro décision d'archi ouverte ; les invariants (send non-bloquant, debounce côté boucle, non-régression overlay) sont balisés mécaniquement dans les pièges/AC. Review adversariale Opus en second avis pour le résiduel concurrence (course canal/`ctx`, coalescence).

## Dev Agent Record

### Agent Model Used

### Debug Log References

### Completion Notes List

### File List

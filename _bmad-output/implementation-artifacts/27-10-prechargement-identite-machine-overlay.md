# Story 27.10 : Préchargement de l'identité machine dans l'overlay (salle en portée machine)

Status: review

## Story

En tant qu'**élève**,
je veux **voir mon poste ET ma salle dans l'overlay dès l'ouverture de session, instantanément**,
afin que **l'information stable du poste (nom + salle) s'affiche sans attendre le fetch per-user du serveur (login/fullname) — qui peut tarder ou échouer au tout début de session**.

## Contexte & intention

Constat e2e (2026-06-16) : l'overlay (poste/salle/login) tarde au logon car sa donnée vient **entièrement** du fetch per-user (`GET /state?user=`). Or **deux champs sont STABLES par poste** et n'ont rien à faire dans le canal per-user :
- **nom du poste** (`machine.name`) — déjà résolu **localement** (`COMPUTERNAME`, `service_windows.go`), aucun fetch ;
- **salle** (`machine.room` = `workstation.physicalRooms[0].name`) — propriété **machine-stable** (invariant « 1 salle/poste »), mais aujourd'hui émise par `OverlayStateProvider` dans l'item `identity` de portée **session** (`scope() == Session`), donc livrée uniquement au `GET /state?user=` et **jamais mise en cache machine**.

Le document `overlay.json` sépare DÉJÀ `machine{name, room}` de `identity{login, fullname}` (`ComposeOverlayDocument`, `overlay_compose.go:135-148`). Le seul problème est la **SOURCE de `room`** : portée session au lieu de portée machine. En la basculant en portée **machine** (cache machine PERSISTANT, survit aux reboots, rempli par le cycle service + réveil-logon 27.9), l'agent peut composer un overlay avec **poste + salle dès le logon** depuis le cache machine, sans attendre le fetch per-user — puis enrichir `identity{login, fullname}` quand le cache session arrive.

**Ce que cette story livre :**
- **Serveur** : la salle est émise en portée **machine** (nouvel item overlay `{kind: "machine", room}` via un provider machine, ou split de l'`OverlayStateProvider` existant) ; l'item `identity` session ne porte plus que `{kind: "identity", login, fullname}` (room **retiré** — source unique = machine).
- **Agent Go** : `ComposeOverlayDocument` prend `room` de l'item `kind:"machine"` (portée machine) et `login`/`fullname` de l'item `kind:"identity"` (portée session) ; `machine.name` reste local. La composition au logon (`OverlayDocumentForSession`) lit **le cache machine ET le cache session** → poste + salle depuis le cache machine **même si le cache session est absent/périmé**.
- **Contrat** : un item overlay machine-scope est ajouté ; l'item identity session perd `room`. Golden `state.v1.json` + les 2 hashes figés (PHP `ContractV1Test::FROZEN_STATE_HASH` + Go `frozenStateHash`) bumpés croisés.

**Ce que cette story N'EST PAS :**
- Le refresh overlay mid-session (différé — l'overlay reste composé aux évènements SYSTEM logon/fetch).
- Un changement du rendu Rainmeter (27.1ter) ni du payload des signaux/alertes.
- Le préchargement de login/fullname (impossible : per-user par nature).

## ⚠️ Pièges & tensions (lire AVANT de coder)

1. **LE CONTRAT BOUGE — croisé PHP↔Go + golden + 2 hashes figés** (comme story 27.8). Un item overlay passe de la portée session à la portée machine, et l'item identity session perd un champ. Il faut : (a) modifier le(s) provider(s) PHP ; (b) régénérer le golden `tests/Fixtures/Agent/state.v1.json` (un item overlay machine-scope ; identity session sans room) ; (c) bumper `ContractV1Test::FROZEN_STATE_HASH` (PHP) ET `frozenStateHash` (Go `hasher_test.go`) à l'IDENTIQUE (le hash Go DOIT suivre le PHP — test croisé NFR13) ; (d) recalculer aussi le `hash` par-item du/des item(s) overlay touché(s) dans la fixture. Cf. la procédure exacte utilisée pour la normalisation `<login>→<user>` et 27.8.

2. **Un provider = une portée.** `OverlayStateProvider` a `scope() == Session`. Émettre un item machine-scope impose soit un **nouveau provider** (`OverlayMachineStateProvider`, `scope() == Machine`) émettant `{kind:"machine", room}`, soit une refonte. Choisir le plus simple et cohérent avec le registre de providers existant (`StateCompiler`). L'item machine-scope est émis **même sans user** (machine-only, `GET /state`).

3. **Composition lit DEUX caches.** Aujourd'hui `OverlayDocumentForSession` (overlay_logon.go) lit le cache per-SID **session** uniquement. Pour la salle, il faut AUSSI lire le **cache machine** (`store` machine state, `cache/state.json`) et passer ses items overlay à `ComposeOverlayDocument` en plus de ceux de la session. Attention à la PARTITION des portées (le service écrit le cache machine ; le compose overlay tourne côté SYSTEM au logon → accès aux deux caches OK). Ne PAS faire converger le compagnon (droits user) sur la portée machine (invariant : machine = SYSTEM seul).

4. **`room` machine présent, session absent (préchargement).** C'est le CŒUR : au logon, si le cache session n'est pas encore frais, le compose doit quand même produire `machine.room` (depuis le cache machine persistant) + `machine.name` (local). `identity{login,fullname}` reste vide jusqu'à l'arrivée du cache session. Le render (regex) exige la présence des clés → champs vides, jamais omis (déjà le cas, `overlay_compose.go:90`).

5. **`ComposeOverlayDocument` — nouveau `kind`.** La boucle dispatch par `kind` (`identity` → identity ; autres → alerts). Ajouter `kind:"machine"` → extraire `room`. Garder « le PREMIER gagne » par kind (défense). NE PAS casser le byte-format littéral du document (compat render).

6. **Cache machine doit être frais au logon.** Le réveil-logon 27.9 (`RequestWake` → `RunCycle` → fetch machine) rafraîchit le cache machine au logon. Mais même périmé, le cache machine porte la salle (elle ne change jamais) → préchargement OK. Pas de dépendance dure à la fraîcheur.

## Décisions de design — TRANCHÉES

- **D1 — `room` en portée MACHINE, source unique.** Retiré de l'item identity session (pas de redondance). Justification : `room` est une propriété du POSTE (investigation 2026-06-16 : `workstation.physicalRooms[0].name`), pas du user.
- **D2 — `machine.name` reste LOCAL** (`COMPUTERNAME`), jamais serveur (inchangé).
- **D3 — Compose lit les deux caches** (machine + session) côté SYSTEM au logon. Le compagnon (user) ne touche jamais la portée machine.
- **D4 — Bump contrat assumé** (golden + 2 hashes croisés), comme 27.8. L'overlay `kind:"machine"` est un item d'état machine-scope `type:"overlay"`.

## Acceptance Criteria

### AC1 — Serveur : salle en portée machine, identity session sans room
- Un item overlay machine-scope `{kind:"machine", room:"<salle>"}` est émis (room = `workstation.physicalRooms[0].name`, null/absent → room vide), même en machine-only (`GET /state` sans user).
- L'item `identity` (session) ne porte plus que `login` + `fullname`.
- Tests PHP : provider machine émet l'item room ; provider session n'émet plus room.

### AC2 — Contrat & golden bumpés croisés
- `tests/Fixtures/Agent/state.v1.json` : item overlay machine-scope ajouté (portée `machine`) ; item identity session sans `room` ; `hash` par-item recalculés.
- `ContractV1Test::FROZEN_STATE_HASH` (PHP) et `frozenStateHash` (Go `hasher_test.go`) bumpés à l'IDENTIQUE ; le test croisé Go↔PHP reste vert (NFR13).
- Item à 4 clés (type/semantics/payload/hash) respecté.

### AC3 — Agent : compose depuis les deux portées
- `ComposeOverlayDocument` extrait `room` de l'item `kind:"machine"` et `login`/`fullname` de `kind:"identity"` ; `machine.name` reste local. Byte-format du document inchangé (compat render).
- `OverlayDocumentForSession` (compose au logon) lit le cache machine ET le cache session.
- Tests host : room machine + login/fullname session → document correct ; **room machine seul (session absente) → `machine.room` rempli, `identity` vide** (préchargement) ; aucune fuite de la portée machine vers le compagnon user.

### AC4 — Préchargement effectif
- Au logon, `machine.name` + `machine.room` sont composés depuis le cache machine persistant + local, **sans dépendre** du fetch per-user. `identity.login/fullname` arrivent avec le cache session.

### AC5 — Tests + non-régression + build
- Suite PHP (`ContractV1`, providers overlay) verte ; suite Go (`overlay_compose`, `overlay_logon`, `hasher`) verte ; `GOOS=windows go build ./...` + `go test ./...` + `-race` verts.
- Non-régression : `overlay.json` byte-format, écriture SYSTEM read-only (NFR5), partition des portées (machine=SYSTEM, session+machine_user=compagnon) — intacts.

### AC6 — Documentation + QA + version
- Doc QA append-only (domaine overlay/agent) : scénario « logon → poste + salle affichés immédiatement même si le serveur tarde sur le per-user ; login/fullname se remplissent ensuite ».
- Bump version agent.

## Tasks / Subtasks

- [x] **T1 — Serveur** : émettre `{kind:"machine", room}` en portée machine (nouveau `OverlayMachineStateProvider` `scope()==Machine` recommandé) ; retirer `room` de l'item identity session de `OverlayStateProvider`. Enregistrer le provider dans le compilateur d'état. Tests PHP.
- [x] **T2 — Contrat/golden** : régénérer `state.v1.json` (item overlay machine + identity session sans room + hashes par-item) ; bumper les 2 hashes figés (PHP + Go) à l'identique ; vérifier le test croisé.
- [x] **T3 — Agent compose** : `ComposeOverlayDocument` gère `kind:"machine"` (room) ; `OverlayDocumentForSession` lit cache machine + session. Tests host (dont le cas préchargement room-sans-session).
- [x] **T4 — Non-régression** : byte-format document, NFR5, partition des portées.
- [x] **T5 — Build, tests, -race, bump version**.
- [x] **T6 — Doc QA append-only + Dev Agent Record + File List + Change Log + sprint-status → review**.

## Dev Agent Record

**Modèle dev** : claude-opus-4-8 (fallback assumé — Fable indisponible, précédent 27.8/27.9).

### Decisions & implementation notes

- **Provider machine séparé** (option recommandée du piège n° 2) : nouveau
  `OverlayMachineStateProvider` (`scope()==Machine`, `type()=='overlay'`,
  aggregate). Aucune modif du `StateCompiler` ; un seul `$app->make(...)` ajouté
  au registre `AgentServiceProvider`. La salle est émise **même machine-only**
  (`itemsFor` ne dépend pas de `$ctx->user`).
- **Kind réservé `machine`** : ajout de `OverlayService::KIND_RESERVED_MACHINE`
  (= `'machine'`) + reclassement dans `postSignal()` (iso `identity`, review 24.4
  #2) — un signal posté revendiquant ce kind est reclassé `notice` au lieu de
  disparaître en silence.
- **Golden enrichi (6 items)** : l'ancien item overlay session générique
  (`{tool:"rainmeter", ttl_seconds:60}`) est remplacé par DEUX items réels et
  illustratifs de la story — un overlay machine-scope `{kind:"machine", room}` ET
  un overlay identity session `{kind:"identity", login, fullname}` (sans `room`).
  Le golden passe de 5 à 6 items ; l'assertion de compte Go (`checked != 5`→`6`)
  et l'assertion PHP « portée machine vide » (devenue peuplée) sont mises à jour.
- **Compose double-cache gracieux à toutes granularités** :
  `OverlayDocumentForSession` lit `StateCachePath()` (machine) ET
  `SessionStatePath(sid)` (session). Un cache illisible/absent n'avorte pas la
  composition de l'autre portée (best-effort) ; `("", false)` seulement si AUCUNE
  portée exploitable. Préchargement : room machine seul → `machine.room` rempli,
  `identity` vide.
- **Byte-format `overlay.json` inchangé** : `room` provient désormais de l'item
  `kind:"machine"` (jamais de l'item identity), mais la structure littérale du
  document est identique (golden Go `testdata/overlay.golden.json` inchangé).

### Hashes figés (bump croisé)

- `state.v1.json` `hashState` : `1599cc48341c732d941e24c830c9facb1237dccf0f17390f939e59f082aafb1b` → **`8174042c0ac8d8f7b6ef1fecf0ff4313b0eba23451e50136c8f712bb5afb4975`**.
- Bumpé à l'IDENTIQUE côté PHP (`ContractV1Test::FROZEN_STATE_HASH`) ET Go
  (`hasher_test.go::frozenStateHash`). Test croisé `go test ./shared/ -run Hash`
  vert + `php artisan test --filter=ContractV1` vert.
- Hashes par-item recalculés via `StateHasher::hashItem` : overlay machine =
  `a752794a4de8a6914c393c8beef059da1c77e1290b82acb5b786ffa2bbe20d24` ; overlay
  identity session = `9d5e55411c14ff191042cc270d5d128c4e6d9a192b0790b9d54868605984ddc4`.

### Résultats de validation

- `GOOS=windows GOARCH=amd64 go build ./...` : OK.
- `go test ./...` : OK (`sambaedu/agent/shared`).
- `go test -race ./...` : OK.
- `php artisan test --filter=ContractV1` : 5 passed (75 assertions).
- `php artisan test --filter='OverlayStateProvider|OverlayMachineStateProvider|ContractV1'` : 21 passed.
- Suite PHP large : les échecs résiduels sont environnementaux (LDAP injoignable
  sur l'hôte + Vite manifest absent), pré-existants au baseline (63 fail avant /
  59 fail après = -4, 0 régression introduite).

### File List

**Créés**
- `app/Services/Agent/Providers/OverlayMachineStateProvider.php` — provider machine-scope émettant `{kind:"machine", room}`.
- `tests/Unit/Services/Agent/OverlayMachineStateProviderTest.php` — tests du provider machine.

**Modifiés (PHP)**
- `app/Services/Agent/Providers/OverlayStateProvider.php` — `room` retiré de l'item identity session.
- `app/Services/Overlay/OverlayService.php` — `KIND_RESERVED_MACHINE` + reclassement `postSignal`.
- `app/Providers/AgentServiceProvider.php` — enregistrement du nouveau provider.
- `tests/Unit/Services/Agent/OverlayStateProviderTest.php` — identity sans room.
- `tests/Unit/Services/Agent/ContractV1Test.php` — `FROZEN_STATE_HASH` bumpé + assertion portée machine peuplée.
- `tests/Fixtures/Agent/state.v1.json` — item overlay machine ajouté + identity session sans room (6 items, hashes recalculés).

**Modifiés (Go)**
- `agent/shared/overlay_compose.go` — dispatch `kind:"machine"` → `room`.
- `agent/shared/overlay_logon.go` — lecture cache machine + session.
- `agent/shared/overlay_compose_test.go` — helpers split (identity/machine) + tests préchargement.
- `agent/shared/overlay_logon_test.go` — tests double-cache + préchargement.
- `agent/shared/hasher_test.go` — `frozenStateHash` bumpé + compte 6 items.
- `agent/shared/contract_test.go` — compte portées 1/4/1.
- `agent/shared/version.go` — `2.2.9` → `2.2.10`.

**Documentation**
- `docs/qa/domains/agent.md` — Section 22 (préchargement poste+salle, append-only).
- `_bmad-output/implementation-artifacts/sprint-status.yaml` — statut → review.

### Change Log

- 2026-06-16 — Story 27.10 implémentée (claude-opus-4-8) : salle en portée machine, préchargement poste+salle au logon, compose double-cache, contrat bumpé croisé, version 2.2.10. Status → review.

## Dev Notes

### Infra & emplacements (investigation 2026-06-16)
- Serveur : `app/Services/Agent/Providers/OverlayStateProvider.php` (identity, scope Session, room l.120-122) ; `app/Services/Agent/TargetContext.php` (`physicalGroupIds` l.50) ; `app/Models/Workstation.php` (`physicalRooms()` l.157-166, pivot global `is_physical`) ; `StateCompiler` (registre des providers) ; `app/Enums/StateScope.php` (Machine/Session/MachineUser).
- Agent : `agent/shared/overlay_compose.go` (`ComposeOverlayDocument` l.79-171, dispatch par `kind`, doc `machine{name,room}`/`identity{login,fullname}`) ; `agent/shared/overlay_logon.go` (`OverlayDocumentForSession` — lit le cache session, à étendre au cache machine) ; cache machine = `cache/state.json` (persistant, `files.go`).
- Contrat : `tests/Fixtures/Agent/state.v1.json` ; `tests/Unit/Services/Agent/ContractV1Test.php` (`FROZEN_STATE_HASH`) ; `agent/shared/hasher_test.go` (`frozenStateHash`) ; `agent/shared/StateHasher`/PHP `StateHasher` (hashItem exclut `hash`, hashState exclut `generated_at`).

### Procédure de bump des hashes (rappel, iso 27.8 / normalisation token)
1. Modifier providers + golden fixture (payloads + item `hash` recalculés via `StateHasher::hashItem`).
2. Calculer `hashState` du golden → nouvelle valeur des DEUX constantes figées (PHP + Go), identiques.
3. Vérifier `ContractV1Test` (PHP) + le test croisé Go (`hasher_test.go`) verts.

### Périmètre
- Livré : salle en portée machine, préchargement poste+salle au logon, compose double-cache.
- Hors-scope : refresh mid-session ; login/fullname (per-user) ; Rainmeter (27.1ter).

## Recommandation Modèle Dev

**fable** si dispo (story agent + contrat) ; sinon **opus** (fallback assumé, Fable indisponible — précédent 27.8/27.9). Review adversariale **opus** (modèle opposé). Story **cross-stack** (PHP serveur + contrat golden + Go agent) → exige rigueur sur le bump croisé des hashes (piège n°1).

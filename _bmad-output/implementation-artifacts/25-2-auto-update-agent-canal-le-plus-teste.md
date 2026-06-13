# Story 25.2: Auto-update de l'agent — le canal le plus testé

Status: review

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

En tant que **mainteneur SambaEdu**,
je veux **que l'agent se mette à jour seul depuis le manifest, sans jamais briquer le parc**,
afin **que les évolutions se déploient sans tournée des salles**.

## Contexte & intention

Deuxième story de l'**Epic 25** (« Gestion de flotte »). La 25.1 (done) a livré la **moitié serveur** : table `agent_releases` + rings, endpoint manifest `GET /api/v1/agent/release` → `{success, version, hash, url}` résolu par ring, serving binaire authentifié `GET /api/v1/agent/releases/{filename}` (`BinaryFileResponse`). **25.2 livre la moitié agent : la consommation.** L'agent Go détecte au check-in qu'une version plus récente est annoncée, télécharge le binaire, **vérifie hash + signature AVANT toute exécution**, se remplace et redémarre proprement, et rapporte. C'est **le chemin le plus testé de l'agent (NFR8)** — un update raté ne doit JAMAIS laisser un poste « ni ancien ni nouveau ».

C'est une story **100 % côté agent Go** (module `sambaedu/agent`, `agent/`). Le serveur n'est pas touché (contrat wire figé par 25.1). La valeur : une release canari ciblée sur un ring d'1 poste de lab (déjà publiable en 25.1) devient **effectivement appliquée** par l'agent — la boucle de déploiement par ring se ferme (la progression visible par ring = l'UI 25.5, mais la version rapportée qui fait foi est posée ici).

> **Dépendance amont (25.1, done)** : le manifest `{version, hash, url}` et le download authentifié EXISTENT et sont smoke-testés sur la VM (release `2.1.2` publiée stable). 25.2 les CONSOMME, ne les recrée pas. Décisions amont consommées : **n° 2** (`url` absolue construite serveur, à utiliser telle quelle — ne jamais la reconstruire côté agent), **n° 7** (404 `no_release` = « rien à faire », jamais un 200 vide), **n° 8** (le serveur ne vérifie PAS Authenticode — **c'est l'agent qui le fait, ici**), **n° 9** (wrapper SE5 `{success, version, hash, url}`, golden `tests/Fixtures/Agent/release-manifest.v1.json`).

## ⚠️ Pièges connus (lire avant de coder)

1. **`agent.exe` est VERROUILLÉ tant que le service tourne** (Windows pose un lock sur l'image d'un processus actif) : impossible d'écraser `C:\Program Files\SambaEdu\Agent\agent.exe` en place. Pattern atomique imposé (décision n° 4) : **rename de l'ancien** (`agent.exe` → `agent.exe.old`, autorisé même verrouillé) **puis dépose du neuf** sous `agent.exe`, **puis stop/start du service** (le SCM relance la nouvelle image). Le rename d'un binaire en cours d'exécution est OK sous Windows (le handle ouvert suit l'inode). Jamais d'écriture directe par-dessus l'image active.
2. **La vérification Authenticode se fait ICI, côté agent** (25.1 décision n° 8 délègue explicitement) : un binaire dont la signature ne valide pas est **jeté sans installation**. Aucun code Go de vérif Authenticode n'existe encore dans `agent/` (grep `wintrust|WinVerifyTrust|Authenticode` → vide). Stratégie imposée (décision n° 3) : **WinVerifyTrust via `wintrust.dll`** (FFI `golang.org/x/sys/windows`, déjà au go.mod) avec `WINTRUST_ACTION_GENERIC_VERIFY_V2` sur un `WINTRUST_FILE_INFO` ; échappatoire admise = shell-out `Get-AuthenticodeSignature` (style des autres shell-out `icacls`/PowerShell de l'agent) si le FFI s'avère trop lourd — **à trancher en dev, documenter le choix**. La vérif Authenticode est **Windows-only** → fichier `*_windows.go` ; le code `shared/` reste cross-platform (la vérif est injectée comme une fonction, cf. décision n° 6).
3. **Vérifier le hash AVANT d'écrire, vérifier la signature AVANT de swapper** (ordre strict) : (a) SHA-256 du corps téléchargé == `hash` du manifest → sinon jeté (pattern exact `assets.go:146-154`, déjà en place pour les wallpapers) ; (b) signature Authenticode valide sur le fichier posé → sinon jeté. **Un download corrompu/non-signé ne doit JAMAIS atteindre le swap.** Les deux vérifs sont des portes successives, pas alternatives.
4. **Le corps HTTP du Client est borné à 16 Mio** (`client.go`, `io.LimitReader(resp.Body, 16<<20)`) : un binaire agent fait ~7 Mo, ça passe — **mais c'est un plafond dur**. Si le binaire dépasse 16 Mio, le corps est tronqué silencieusement → SHA-256 divergent → rejeté (fail-safe correct, mais update bloqué). Documenter la borne ; **ne pas la relever sans raison** (un binaire >16 Mio signale un problème de build). Vérifier la taille réelle de `agent/build/dist/` au smoke.
5. **Le token et le cache d'état doivent SURVIVRE à l'update** : le nouvel agent relit le token sur `C:\ProgramData\SambaEdu\Agent\token` (le swap ne touche QUE `C:\Program Files\SambaEdu\Agent\agent.exe` ; les données vivent sous `ProgramData`, jamais sous `Program Files`). La rotation D5 (token tournant) survit par construction puisque le fichier token est hors du périmètre du swap. **Test obligatoire** : after-swap, le nouvel agent doit pouvoir relire token + cache sans ré-enrôlement.
6. **`agent_version` figure DÉJÀ dans chaque rapport** (`contract.go:158`, `BuildReport` injecte `AgentVersion: Version`) : l'AC « la version figure dans chaque rapport » est **déjà satisfaite par construction** — il NE FAUT PAS la réimplémenter, juste la **vérifier par un test** et s'assurer qu'après swap+restart la nouvelle valeur de `shared.Version` (injectée au build via `-ldflags`) est bien celle rapportée. Le piège serait de croire qu'il faut ajouter du code ; le travail réel est de garantir que la version remonte correctement après un cycle d'update.
7. **Un update qui échoue à mi-chemin laisse l'agent EN PLACE et fonctionnel** (anti-brique, l'AC la plus dure) : si la dépose du neuf échoue après le rename de l'ancien → **rollback** (`agent.exe.old` → `agent.exe`) ; si le redémarrage du service échoue → l'agent en place (ancienne image, rename inverse fait) continue de tourner. Jamais d'état où `agent.exe` est absent ou corrompu. Chaque étape destructive a son inverse, séquencé pour qu'aucune fenêtre ne laisse le poste sans binaire valide.
8. **L'échec d'update se rapporte au serveur** sans casser le cycle machine (iso `SyncWallpaperAssets` : « un échec ne casse jamais le cycle »). L'update est tenté **en fin de cycle**, après le `POST /report` de la version courante (ou via un item de rapport dédié) — un update raté ne doit pas empêcher le poste de rapporter son état réel. Forme du report d'échec à décider (décision n° 7) : item de rapport `agent_update` avec status `error` + detail, OU log structuré remonté au cycle suivant.
9. **Pas de boucle de mise à jour infinie** : si le manifest annonce la version `X` et que l'agent vient d'installer `X` mais que le swap a « réussi à moitié » (rare), il ne doit pas re-télécharger en boucle à chaque check-in. La comparaison `manifest.version != shared.Version` est l'unique déclencheur ; après un échec, **backoff** (ne pas retenter le download dans le même cycle ; retry au cycle suivant, cadence `ttl_seconds` — pas de retry agressif, iso résilience architecture).
10. **404 `no_release` du manifest = rien à faire** (décision amont n° 7), pas une erreur : l'agent traite 404 comme « aucune release applicable pour ce poste » (poste sans ring + aucune stable) → no-op silencieux, jamais un log d'erreur. Distinguer 404 (rien à faire) de 401 (token mort → arrêt) / 403 (quarantaine).
11. **Comparaison de version = égalité, PAS ordre semver** : le manifest dit autoritairement « voici la version que ce poste DOIT avoir » (résolue par ring serveur). L'agent applique `manifest.version != currentVersion` → update (même un downgrade volontaire de rollback, décidé serveur). **Ne pas implémenter de comparateur semver** (« est-ce plus récent ? ») — le serveur a déjà tranché par la résolution de ring (décision amont n° 4 : récence du ciblage). L'AC épic dit « version plus récente » mais la sémantique réelle = « version cible différente » : le serveur est l'autorité, l'agent obéit. Documenter cette nuance.
12. **Tests cross-platform vs Windows-only** : le module se teste sur **Linux** (`go test ./...` dans `agent/`, cf. README — pas de Windows en CI). Donc : la logique de décision/download/vérif-hash/orchestration du swap vit dans `shared/` (testable Linux, c'est le gros de NFR8) ; SEULS la vérif Authenticode et l'exécution réelle du rename+SCM-restart sont en `*_windows.go` (non testés en CI, vérifiés au smoke). Concevoir avec **injection de fonction** (cf. `AssetsACL func(string) error` du type `Agent`) pour que `shared/` orchestre et teste le flux complet avec des stubs des parties Windows.

## Décisions de design prises ici (à challenger en review, pas à re-trancher en dev)

1. **L'update est un nouveau "sync" en fin de cycle machine, iso `SyncWallpaperAssets`.** Méthode `(a *Agent) SelfUpdate(cfg Config)` appelée dans `RunCycle` au même point que `SyncWallpaperAssets` (après portée machine, sous garde `!a.quarantined`, avant/autour du `POST /report`). Un échec ne casse jamais le cycle (recover + log + report d'échec). Réutilise le `Client` (rotation D5 incluse), `ReadToken`, le pattern SHA-256-avant-écriture déjà éprouvé. **Pas de nouveau processus/daemon d'update** : l'agent se met à jour lui-même dans sa boucle.
2. **Découpage `shared/` (orchestration testable) vs `windows/` (primitives OS).** `agent/shared/update.go` : `SelfUpdate` (décision update, GET manifest, GET binaire, vérif hash) + interface des primitives Windows injectées. `agent/windows/update_windows.go` : implémentations réelles (`verifyAuthenticode(path) error`, `swapAndRestart(newBinaryPath) error`). Câblage dans `newAgent` (`main_windows.go`) iso les ACL injectées. Sur `!windows` (stub `main_stub.go`), primitives no-op/erreur — l'update ne tourne qu'en service Windows réel, mais `shared/` se teste intégralement avec des fakes.
3. **Vérif Authenticode = WinVerifyTrust (`wintrust.dll`) en priorité**, fallback shell-out `Get-AuthenticodeSignature` admis. FFI via `golang.org/x/sys/windows` (`WINTRUST_ACTION_GENERIC_VERIFY_V2`, `WINTRUST_FILE_INFO`, `WTD_UI_NONE`, `WTD_REVOKE_NONE`). Vérifie une chaîne valide remontant à une CA de confiance machine (la CA interne SE5 est déployée par l'install, brief #31). **Le résultat est binaire : signé-et-confiance OK → on swappe ; sinon → jeté.** Le détail d'implémentation (FFI vs shell-out) est tranché en dev et documenté dans la story finale + `agent/README.md`.
4. **Swap atomique = rename-ancien → dépose-neuf → restart-service, avec rollback.** Séquence exacte dans `swapAndRestart(newPath)` :
   - (a) `agent.exe.old` supprimé s'il traîne (résidu d'un swap précédent) ;
   - (b) `agent.exe` → `agent.exe.old` (rename, OK même verrouillé) ;
   - (c) `newPath` → `agent.exe` (rename atomique, même volume) ; **si échec → rollback (b) inverse, abort** ;
   - (d) restart du service `SambaEduAgent` (stop → start via SCM) ; le process courant meurt au stop, la nouvelle image démarre ;
   - (e) au démarrage suivant, le nouvel agent nettoie `agent.exe.old` (best-effort, plus verrouillé une fois l'ancien process mort).
   Le binaire neuf est d'abord déposé dans un emplacement de staging (`C:\ProgramData\SambaEdu\Agent\update\agent-<version>.exe`, ACL SYSTEM) — vérifié hash+signature LÀ — puis `(c)` le déplace en place. Jamais de vérif sur le fichier déjà en place.
5. **Staging sous `ProgramData`, jamais sous `Program Files`.** Le download + les deux vérifs se font sur `C:\ProgramData\SambaEdu\Agent\update\` (sous `DefaultAgentRoot`, ACL SYSTEM via le pattern existant). `Program Files` n'est touché qu'à l'instant du rename final `(c)`. Cela garde le téléchargement et la vérif dans le domaine de données de l'agent (cohérent token/cache).
6. **Comparaison de version = égalité stricte avec `shared.Version`** (piège n° 11). `manifest.version != shared.Version` → tenter l'update. Pas de comparateur semver. Le filename à télécharger n'est PAS reconstruit : il est **extrait de l'`url` absolue** du manifest (dernier segment, percent-decodé) — l'url est autoritaire (décision amont n° 2). Le download GET sur l'`url` du manifest verbatim.
7. **Report d'échec via un item de rapport `agent_update`** (status `error` + detail tronqué), agrégé au prochain `BuildReport` du cycle — PAS un endpoint dédié (le contrat report v1 porte déjà des items `{type, status, hash, detail}`). Le succès d'update ne se rapporte pas explicitement : la **nouvelle version dans `agent_version` du rapport** EST la preuve de succès (piège n° 6) — le serveur voit la progression par ring via la version rapportée (25.5). `agent_update` n'est PAS un type de ressource desired-state (pas de provider serveur) : c'est un canal de signalement d'échec, à documenter comme tel pour ne pas le confondre avec un handler.
8. **Backoff sur échec, déclenchement unique par cycle** (piège n° 9) : un seul download tenté par cycle ; après échec, pas de retry intra-cycle (le prochain check-in `ttl_seconds` plus tard retentera si le manifest annonce toujours une version divergente). Si le swap a réussi mais le restart échoue, l'ancien agent (rename inverse) continue — au prochain cycle il re-verra la divergence et retentera le swap (idempotent : staging re-vérifié).
9. **Logging via le `Logger` existant**, messages préfixés explicites (`Auto-update : …`). Niveaux : `Infof` (update détecté/appliqué), `Warningf` (download/vérif échoués, retry au prochain cycle), `Errorf` (rollback déclenché, incohérence). Best-effort iso le reste de l'agent (un échec de log ne tombe jamais l'agent).

## Acceptance Criteria

### AC1 — Détection + téléchargement + double vérification AVANT exécution (FR24, NFR8)

**Given** un manifest (`GET /api/v1/agent/release`, 200) annonçant une `version` différente de `shared.Version`
**When** le check-in machine la détecte (en fin de cycle, sous garde `!quarantined`)
**Then** l'agent télécharge le binaire via l'`url` **absolue** du manifest (verbatim, décision n° 6) avec le bearer du poste (Client, rotation D5)
**And** vérifie le **SHA-256 du corps reçu == `hash` du manifest** AVANT toute écriture (pattern `assets.go`) — divergent → jeté, warning, retry au prochain cycle, **rien d'écrit**
**And** vérifie la **signature Authenticode** du fichier stagé AVANT tout swap (décision n° 3) — invalide/non-confiance → jeté, warning, retry au prochain cycle, **aucun swap**
**And** un manifest 404 `no_release` = no-op silencieux (rien à faire — décision amont n° 7, piège n° 10) ; un 401 = arrêt (re-enrôlement manuel) ; un 403 = quarantaine.

### AC2 — Auto-remplacement atomique + redémarrage propre (FR24)

**Given** un binaire téléchargé, hash OK ET signature OK, stagé sous `ProgramData\…\update\`
**When** l'agent se remplace
**Then** le swap suit la séquence atomique copie-atomique → re-hash `.new` (M2) → rename-ancien → dépose-neuf, puis **sortie non-gracieuse `os.Exit(≠0)` (Option A, rework review)** : `agent.exe` (verrouillé) renommé `.old`, le neuf renommé en place, puis le process sort volontairement → la **recovery SCM** (`ServiceRestart ×3`) relance le service avec vN+1 (PLUS de stop+start in-process — celui-ci provoquait un deadlock, #1)
**And** le nouvel agent démarre, **relit le token et le cache sous `ProgramData`** sans ré-enrôlement (piège n° 5), nettoie `agent.exe.old` (best-effort)
**And** la nouvelle version de `shared.Version` (injectée `-ldflags` au build) est désormais celle qui tourne.

### AC3 — Anti-brique : un update raté laisse l'agent en place fonctionnel (NFR8 — l'AC la plus dure)

**Given** un update qui échoue à mi-chemin (dépose du neuf KO, ou restart KO, ou signature KO découverte tard)
**Then** l'agent **en place continue de fonctionner** — jamais d'état « ni ancien ni nouveau » : signature/hash KO → jamais entré en swap ; dépose-neuf KO → rollback (`.old` → `agent.exe`), ancienne image intacte ; restart KO → ancienne image (rename inverse) tourne toujours
**And** à aucun instant `agent.exe` n'est absent ou corrompu (chaque étape destructive a son inverse séquencé — piège n° 7)
**And** l'échec est **rapporté au serveur** (item `agent_update` status `error` + detail, décision n° 7) sans casser le cycle machine (le `POST /report` de l'état réel passe quand même).

### AC4 — La version figure dans chaque rapport (contrat 23.1, progression par ring)

**Given** la version de l'agent
**Then** elle figure dans **chaque** rapport (`agent_version` du payload report, `contract.go` `BuildReport` — déjà en place, piège n° 6)
**And** après un update réussi + restart, le rapport porte la **nouvelle** version (test : version injectée → rapportée) — le serveur voit la progression du déploiement par ring (l'affichage = 25.5)
**And** la version rapportée est la **trace de déploiement qui fait foi** (mémoire projet : `download_served` côté serveur = debug ; la version rapportée = la vérité).

### AC5 — Frontières & résilience

**Then** l'update est tenté **en fin de cycle**, recover + log si panique, un échec **ne casse jamais** le cycle machine ni le report (iso `SyncWallpaperAssets`)
**And** **un seul download par cycle**, backoff implicite (retry au prochain check-in `ttl_seconds`), jamais de boucle de re-download intra-cycle (piège n° 9)
**And** download/staging/vérif **100 % SYSTEM** sous `ProgramData` (ACL existante) ; `Program Files` touché au seul instant du rename final
**And** **zéro dépendance AD** dans le chemin d'update (critère Keycloak NFR7) ; le canal reste le bearer token neuf, l'url verbatim du manifest, aucune reconstruction de chemin.

### AC6 — Tests : LE chemin le plus testé de l'agent (NFR8, non négociable)

**Then** la couverture est la **plus complète de l'agent** — matrice obligatoire (`agent/shared/update_test.go`, exécution `go test ./...` sur **Linux**, primitives Windows stubées via injection — décision n° 2 / piège n° 12) :
- **cas nominal** : manifest version divergente → download → hash OK → signature OK (stub) → swap appelé (stub) → succès ;
- **manifest 404 no_release** → no-op, aucun download ;
- **manifest version == courante** → no-op, aucun download ;
- **hash KO** : corps téléchargé ≠ hash manifest → jeté, **aucune écriture**, **swap jamais appelé**, warning, retry ;
- **signature KO** : hash OK mais Authenticode invalide (stub renvoie erreur) → jeté, **swap jamais appelé** ;
- **download interrompu / serveur injoignable** → skip propre, cycle continue ;
- **download tronqué (>16 Mio)** → hash divergent → jeté (piège n° 4) ;
- **échec de swap (dépose-neuf KO)** → rollback **RÉELLEMENT testé** dans `shared/swap_test.go` (`PerformSwap` : staged absent / hash `.new` divergent M2 / échec rename final → ancien binaire restauré, `triggerRestart` JAMAIS appelé) ; côté orchestration, `SwapAndRestart` renvoie une erreur → report d'échec émis, agent en place (rework #6/M6) ;
- **swap nominal** (`PerformSwap`) → fichiers permutés + `triggerRestart` appelé APRÈS succès (= `os.Exit` côté Windows, Option A) ;
- **401** sur le download → arrêt ; **403 release** (manifest OU download) → **update sauté SANS quarantaine globale** (M4, Option 1), le report continue ;
- **report d'échec** : item `agent_update` status `error` présent dans le `BuildReport` du cycle ;
- **version dans le rapport** : `shared.Version` injectée → `agent_version` du payload (test `contract.go`) ;
- **un seul download par cycle** (pas de boucle) ;
- **token/cache survivent** : after-swap (simulé), relecture token OK.

### AC7 — Transversal : doc, build, smoke VM

**Then** `agent/README.md` : section auto-update (déclenchement, double vérif hash+signature, swap atomique, anti-brique, stratégie Authenticode retenue FFI/shell-out, borne 16 Mio) — renvoi croisé `docs/agent/release-distribution.md` (25.1)
**And** `docs/qa/domains/agent.md` : section append-only « Auto-update agent (25.2) », scénarios numérotés (publier release `vN+1` sur ring 1 poste → l'agent converge vers `vN+1` → la version rapportée passe à `vN+1` ; corrompre le binaire → rejeté ; binaire non signé → rejeté)
**And** **smoke réel** : un binaire `vN+1` signé (cert TEST OK) publié via `agent:release:create` 25.1 sur un ring contenant le poste de lab → l'agent télécharge, vérifie, swappe, redémarre, et `agent_version` du rapport passe à `vN+1` — la boucle de déploiement par ring observable de bout en bout
**And** `go build ./...` + `go vet ./...` verts dans `agent/` ; `go test ./...` (Linux) intégralement vert, zéro régression sur les tests agent existants.

## Tasks / Subtasks

- [x] **T1 — Orchestration update (`agent/shared/update.go`)** (AC1, AC5, décisions n° 1/6)
  - [x] `func (a *Agent) SelfUpdate(cfg Config)` : garde quarantaine, `GET cfg.ServerURL + "/api/v1/agent/release"` (Client, token relu), parse wrapper SE5 `{success, version, hash, url}` ; 404 = no-op, 401 = arrêt, 403 = quarantaine, autres = warning+skip.
  - [x] Décision `manifest.version != shared.Version` (égalité stricte, PAS semver — piège n° 11) ; si égal → return.
  - [x] Extraction du filename depuis l'`url` absolue (dernier segment, percent-decode via `url.Parse` + `path.Base`) + re-validation pattern strict ; download `GET url` verbatim (décision n° 6) ; gestion 200/401/403/404.
  - [x] SHA-256 du corps == `hash` manifest AVANT écriture (pattern `assets.go:146`) ; divergent → warning, return (rien écrit) ; **un seul download par cycle** (pas de retry intra-cycle — piège n° 9).
  - [x] Écriture du binaire stagé sous `Store` update dir (`WriteFileAtomic`), ACL SYSTEM via `EnsureUpdateDir(a.UpdateACL)`.
- [x] **T2 — Primitives Windows injectées (interface dans `shared/`, impl dans `windows/`)** (AC2, AC3, décisions n° 2/3/4)
  - [x] Dans `shared/` : champs/fonctions injectées sur le type `Agent` (iso `AssetsACL func(string) error`) : `VerifyAuthenticode func(path string) error`, `SwapAndRestart func(stagedPath, version string) error`, `UpdateACL func(string) error` (nil sur `!windows` ou en test → stubs ; `SwapAndRestart == nil` ⇒ update INERTE).
  - [x] `agent/windows/update_windows.go` : `verifyAuthenticode(path)` (**WinVerifyTrust via `WinVerifyTrustEx` de `golang.org/x/sys/windows`**, `WINTRUST_ACTION_GENERIC_VERIFY_V2` + `WTD_STATEACTION_VERIFY`/`CLOSE` — choix tranché : API native > shell-out, cf. Dev Agent Record) ; `swapAndRestart(staged, version)` (séquence décision n° 4 : copie-à-côté pour le rename même-volume, rename `.old`, rename-en-place, rollback sur échec, stop+start `SambaEduAgent` via SCM).
  - [x] Câblage dans `newAgent` (`main_windows.go`) ; le chemin `!windows` n'a pas de `newAgent` (stub `main_stub.go` = simple message) ⇒ primitives nil ⇒ update inerte (couvert par `TestSelfUpdateWithoutPrimitivesIsNoop`).
  - [x] Nettoyage `agent.exe.old` (+ `.new`) best-effort au démarrage du nouvel agent (`cleanupOldBinary` appelé dans `service_windows.go`).
- [x] **T3 — Staging dir + chemins** (décision n° 5, piège n° 5)
  - [x] `Store` : chemin `update/` sous `DefaultAgentRoot` (`C:\ProgramData\SambaEdu\Agent\update\`), `UpdateDir`/`UpdateStagePath`/`EnsureUpdateDir` + ACL SYSTEM (iso `EnsureAssetsDir`, sans Users:R) ; cible du swap = `os.Executable()` du service installé (`agent.exe` sous Program Files). Créé aussi à l'`install`.
  - [x] Garantir que le swap ne touche QUE `agent.exe` (token/config/cache intouchés sous `ProgramData` — `TestTokenSurvivesUpdate`).
- [x] **T4 — Câblage dans la boucle** (AC1, AC5, décision n° 1)
  - [x] `loop.go RunCycle` : appel `a.SelfUpdate(cfg)` au point de `SyncWallpaperAssets` (après portée machine, sous `!quarantined`) ; isolation par le `recover` existant de `RunCycle` ; ordonné AVANT le `POST /report` pour que l'item d'échec rejoigne le rapport et que la version courante parte même si l'update échoue.
- [x] **T5 — Report d'échec** (AC3, décision n° 7)
  - [x] Item de rapport `agent_update` (status `error` + detail tronqué 480) agrégé au `BuildReport` du cycle via `drainUpdateReportItems()` ; documenté (code + README) que ce n'est PAS un type de ressource desired-state.
  - [x] Vérifié (test `TestReportCarriesAgentVersion`) que `agent_version` du report = `shared.Version` (déjà en place `contract.go:158` — non réimplémenté).
- [x] **T6 — Tests (`agent/shared/update_test.go`)** (AC6 — NFR8, matrice complète non négociable)
  - [x] Fake serveur HTTP (`httptest`) servant manifest + binaire ; stubs `VerifyAuthenticode` / `SwapAndRestart` (OK / erreur) ; **23 tests** couvrant la matrice AC6 intégrale (nominal, 404, version égale, hash KO, signature KO, download injoignable, tronqué >16 Mio, swap KO, restart KO, 401 manifest+binaire, 403 manifest+binaire, quarantaine, sans primitives, report d'échec, version rapportée, single-download, survie token, filename verbatim, parse manifest, EnsureUpdateDir, intégration boucle).
  - [x] Assertions : aucun fichier écrit quand hash KO ; swap jamais appelé quand vérif KO ; report d'échec présent dans le `POST /report` du cycle ; un download par cycle.
- [x] **T7 — Doc + build + smoke** (AC7)
  - [x] `agent/README.md` section auto-update (déclenchement, double vérif, swap atomique anti-brique, choix Authenticode, borne 16 Mio, résilience) ; `docs/qa/domains/agent.md` Section 9 append-only (scénarios 9.0-9.5) + checklist ; entrée README QA mise à jour.
  - [x] `go build ./...` + `go vet ./...` + `go test ./...` verts dans `agent/` (Linux) ; cross-compile + vet `windows/amd64` verts.
  - [ ] Smoke réel (VM serveur 25.1 + poste de lab) : publier `vN+1` signé sur un ring 1-poste → observer download/vérif/swap/restart → `agent_version` rapportée = `vN+1`. **→ action manuelle Henri** (story agent Go pure, pas de SSH VM depuis le dev-cycle ; cf. Completion Notes + QA Section 9).

## Dev Notes

### Périmètre — livré / hors-scope

| Livré (25.2) | Hors-scope (story) |
|---|---|
| `SelfUpdate` (détection, download, double vérif, orchestration) côté agent Go | Serveur (manifest + download) → **25.1 (done)**, intouché |
| Vérif Authenticode côté agent (WinVerifyTrust / shell-out) | Production du binaire signé (build `agent/build/build.sh` → 24.5/24.6) |
| Swap atomique rename + restart SCM + rollback anti-brique | Porte 2 enrôlement migrés → **25.3** |
| Report d'échec `agent_update` + version rapportée (preuve de succès) | GPO-dispatcher figée + dépôt iPXE → **25.4** |
| Matrice de tests NFR8 (Linux, primitives Windows stubées) | UI progression par ring (`agent.release.promoted` ring-à-ring) → **25.5** |
| Doc README + QA + smoke ring observable | Persistance serveur de la version rapportée par poste (le rapport la porte ; l'agrégation/affichage = 25.5) |

### Patterns existants à imiter (NE PAS réinventer)

- **Le squelette EXACT de l'update = `agent/shared/assets.go`** (`SyncWallpaperAssets`) : garde quarantaine, `ReadToken` + `Client.SetToken`, `Client.Get(url, "")`, switch 200/401/403/404, **SHA-256 du corps comparé AVANT écriture** (l.146-154), `WriteFileAtomic`, « un échec ne casse jamais le cycle ». L'update reprend ce flux et ajoute : vérif signature + swap au lieu du simple write.
- **Injection de primitives OS** : le type `Agent` (`loop.go`) reçoit déjà `AssetsACL func(string) error`, `Sessions func()`, `UUID func() string` — injectées dans `newAgent` (`main_windows.go`), stubées en test. **Reproduire ce pattern** pour `VerifyAuthenticode` / `SwapAndRestart` → `shared/` reste testable sur Linux (piège n° 12).
- **Client HTTP** (`agent/shared/client.go`) : `Get(url, etag)` / `Post`, bearer auto, rotation D5 (`X-Agent-New-Token` géré), corps borné 16 Mio, timeout 30 s. **Réutiliser tel quel** — ne pas créer un second client.
- **Version** (`agent/shared/version.go`) : `var Version = "2.2.0"` injectable `-ldflags "-X sambaedu/agent/shared.Version=…"`. La comparaison se fait contre cette variable ; le build d'un `vN+1` la surcharge.
- **Report** (`agent/shared/contract.go`) : `BuildReport(hostname, uuid, items, now)` injecte `AgentVersion: Version` (l.158) ; `ReportItem{Type, Status, Hash, Detail}`. L'item `agent_update` réutilise cette struct.
- **Service Windows** (`agent/windows/service_windows.go`) : `serviceName = "SambaEduAgent"`, `svc.Handler`, boucle annulée sur `svc.Stop`/`Shutdown` (attente 30 s). Le restart se fait via le SCM (stop+start), pas par auto-exec.
- **Store / chemins** (`agent/windows/files.go` côté `shared`) : `DefaultAgentRoot = C:\ProgramData\SambaEdu\Agent\`, `TokenPath`, `writeAtomic`, `EnsureAssetsDir`/ACL. Ajouter `update/` au même endroit.
- **ACL** (`agent/windows/acl_windows.go`) : `setAgentACL` (SYSTEM+Admins, `(OI)(CI)` sur répertoire) — le staging dir update doit être ACL SYSTEM (pas de Users:R, contrairement aux assets).
- **Install** (`agent/windows/install_windows.go`) : `os.Executable()` = chemin du binaire service (`C:\Program Files\SambaEdu\Agent\agent.exe`) — la cible du swap.

### Architecture — conventions figées applicables (NON négociables)

[Source: architecture-agent-desired-state.md#D6 ; #Process Patterns ; #Enforcement Guidelines ; #Architectural Boundaries]

- **D6** : binaires signés servis par SE5 HTTP, manifest `{version, hash, url}`, version cible par ring (un ring = WorkstationGroup). Le bootstrap GPO figé reste le filet (réinstalle un agent mort) → 25.4, pas ici.
- **Brief #27 (NFR8)** : « canal d'update = partie la plus testée ; canari 1 poste → 1 salle → 1 étab ». La matrice AC6 n'est pas négociable à la baisse.
- **Brief #31 (signature)** : CA interne déployée par l'install ; la vérif Authenticode agent valide contre cette CA de confiance machine.
- **Résilience agent** (Process Patterns) : serveur injoignable → pas de retry agressif (backoff plafonné au timer) ; 401 → arrêt + log (re-enrôlement humain/GPO, jamais auto) ; 403 → quarantaine. **L'update respecte ces règles** (déjà incarnées par `SyncWallpaperAssets`).
- **Frontière de confiance #12** : binaire sous ACL SYSTEM, l'élève ne peut modifier ni l'agent ni son staging. Le download/vérif sous `ProgramData` ACL SYSTEM.
- **Critère Keycloak NFR7** : zéro dépendance AD dans le chemin d'update (vérifiable en review) — bearer token neuf uniquement.
- **Anti-couteau-suisse #30** : `agent/` ne contient QUE convergence + rapport. L'auto-update EST de la convergence (l'agent converge vers la bonne VERSION de lui-même) — légitime ; mais pas de télémétrie/inventaire greffé.

### Dépendance amont — le contrat consommé (25.1, done)

[Source: 25-1-releases-serveur-binaires-signes-manifest-rings.md (décisions n° 2/7/8/9) ; docs/agent/release-distribution.md ; tests/Fixtures/Agent/release-manifest.v1.json]

- `GET /api/v1/agent/release` → 200 `{success: true, version, hash, url}` (wrapper SE5) résolu par ring ; **`url` ABSOLUE** vers `GET /api/v1/agent/releases/{filename}` (à utiliser verbatim, ne JAMAIS reconstruire — décision amont n° 2 / mémoire `project_ipxe_relative_urls_trap`).
- 404 `{error: "no_release"}` = poste sans ring ET aucune stable = **rien à faire** (décision amont n° 7).
- `GET /api/v1/agent/releases/{filename}` → `BinaryFileResponse` (pas de wrapper) ; filename pattern strict `sambaedu-agent-<version>.exe` ; 404 indistinct.
- Chaîne middleware iso state/report : bearer 23.2, throttle 60/min, **rotation D5 (`X-Agent-New-Token`) survit aux réponses manifest ET download** (testé 25.1) — le Client la gère déjà.
- **Répartition intégrité figée (décision amont n° 8)** : le serveur garantit le hash à la création (SHA-256), **NE vérifie PAS Authenticode**. L'agent 25.2 DOIT : (a) SHA-256 du corps == `hash` manifest avant écriture, (b) **vérifier Authenticode avant exécution**. C'est le cœur de cette story.
- Golden `tests/Fixtures/Agent/release-manifest.v1.json` : forme du manifest — réutilisable pour les fixtures du fake serveur en test agent (NFR13, tests croisés).
- Note 25.1 : la release `2.1.2` (binaire cert TEST) reste publiée stable sur la VM — point de départ utile pour le smoke 25.2 (publier `2.2.x` sur un ring de lab).

### Stratégie Authenticode (le point neuf le plus délicat)

[Source: piège n° 2 ; décision n° 3 ; agent/build/build.sh ; brief #31]

- Aucun code de vérif signature n'existe dans `agent/` (à créer, Windows-only).
- **Option A (préférée) — WinVerifyTrust FFI** : `wintrust.dll!WinVerifyTrust` via `golang.org/x/sys/windows` (déjà au go.mod), `WINTRUST_ACTION_GENERIC_VERIFY_V2`, `WINTRUST_FILE_INFO{ pcwszFilePath }`, `WINTRUST_DATA{ dwUIChoice: WTD_UI_NONE, fdwRevocationChecks: WTD_REVOKE_NONE, dwUnionChoice: WTD_CHOICE_FILE }`. Retour `0` (`ERROR_SUCCESS`) = signé + chaîne de confiance valide → OK ; tout autre code = rejet. Penser à `WTD_STATEACTION_CLOSE` pour libérer le contexte.
- **Option B (fallback admis) — shell-out** : `powershell -Command "(Get-AuthenticodeSignature '<path>').Status"` → `Valid` requis. Cohérent avec les autres shell-out de l'agent (`icacls`), `HideWindow: true`. Plus lent, plus simple.
- **Trancher en dev**, documenter le choix + le rationale dans la story finale ET `agent/README.md`. Le build (`build.sh`) signe déjà avec la CA interne (`storage/keys/pki/`) et `osslsigncode verify` au build — la même CA doit être de confiance machine côté poste (déployée par l'install, brief #31). ⚠️ Le PFX CA réelle est une action Henri en cours (note 24.5) : le smoke peut valider avec un cert TEST dont la CA est ajoutée au magasin de confiance machine du poste de lab.

### Anti-brique — séquence atomique détaillée (l'invariant le plus important)

[Source: piège n° 1, n° 7 ; décision n° 4]

```
État stable : Program Files\…\agent.exe = vN (en cours d'exécution, verrouillé)
              ProgramData\…\update\agent-vN+1.exe = stagé, hash OK, signature OK

(pré) copie ATOMIQUE staged -> agent.exe.new (tmp+rename même volume, M1)
(re)  re-hash agent.exe.new == hash manifest (M2)       [si KO -> cleanup .new, abort, agent vN intact]
(a) rm agent.exe.old si résidu                          [idempotent]
(b) rename agent.exe -> agent.exe.old                   [OK même verrouillé]
(c) rename agent.exe.new -> agent.exe                   [si KO -> rollback (b), abort, agent vN intact]
(d) os.Exit(≠0) [Option A]  (process vN meurt SANS SERVICE_STOPPED)
    -> recovery SCM ServiceRestart relance vN+1          [si recovery saturée -> prochain boot / GPO 25.4]
(e) au boot vN+1 : rm agent.exe.old best-effort

À AUCUN instant agent.exe n'est absent : entre (b) et (c) il existe sous .old ;
(c) est un rename atomique (même volume) -> pas de fenêtre "fichier vide".
```

- Invariant : **chaque étape destructive a son inverse, et aucune fenêtre ne laisse le poste sans binaire valide à un chemin connu**. Si `(c)` échoue (rare, même volume), `(b)` inverse restaure `vN`. Si `(d)` start échoue, `vN+1` est en place mais le service est arrêté → la recovery SCM (`ServiceRestart` ×3, install_windows.go) ou le prochain boot le relance ; au pire l'admin/GPO-dispatcher (25.4) répare.

### Project Structure Notes

- **Code agent dans `agent/`** (top-level, hors `app/`) : `shared/` cross-platform (testé Linux), `windows/` Win32 (`*_windows.go`, build tag). Module `sambaedu/agent`, **go 1.26**, deps `golang.org/x/sys` + `golang.org/x/text` uniquement (pas de framework — rester minimal).
- **Go hors PATH zsh** : toolchain sous `~/go-toolchain/go/bin` (mémoire `project_host_go_toolchain_path`) ; `go test ./...` se lance depuis `agent/`. Le package `./windows` ne compile pas sous Linux (FFI Windows) — vérifier la build tag (`//go:build windows`) pour que `go test ./...` reste vert sur Linux (les tests vivent dans `shared/`).
- **Story 100 % agent Go** : AUCUN fichier Laravel touché, AUCUNE migration, AUCUN cache VM (`config:cache`/`route:cache` non concernés — le serveur 25.1 est figé). Le smoke utilise la VM serveur existante + un poste de lab Windows.
- **Jamais de VM/SSH depuis un worktree git** (mémoire projet) ; inotify ne sync pas le code agent vers la VM serveur (sans objet : l'agent tourne sur le poste, pas la VM).
- Binaire de test signé : `agent/build/build.sh` (cert TEST ou réel), artefact `agent/build/dist/sambaedu-agent-<version>.exe`.

### Testing standards

- **`go test ./...` dans `agent/`** (Linux, env hôte) — pas de Windows en CI. Tout le flux décision/download/hash/orchestration testé dans `shared/update_test.go` avec `httptest` + stubs des primitives Windows (injection décision n° 2).
- **NFR8** : c'est LA partie la plus testée — la matrice AC6 (≥14 cas) est un plancher, pas un plafond. Les cas destructifs (swap KO, rollback, restart KO) se testent via stubs qui renvoient erreur aux bonnes étapes et assertent l'état (swap jamais appelé / report d'échec présent / rien écrit).
- Réutiliser le golden `tests/Fixtures/Agent/release-manifest.v1.json` (forme du manifest servi par le fake serveur) — cohérence serveur/agent (NFR13, tests croisés).
- Les parties Windows réelles (Authenticode, rename+SCM) ne sont validées qu'au **smoke** sur poste de lab — documenter explicitement ce qui n'est PAS couvert par `go test`.

### Intelligence stories précédentes

- **24.4 → 24.6 (done)** : `SyncWallpaperAssets` = le précédent exact (SHA-256 avant écriture, isolation, content-addressed). L'agent vérifie déjà des hashes avant écriture — l'update fait pareil + signature + swap.
- **24.x token** : token sous `C:\ProgramData\SambaEdu\Agent\token` ACL SYSTEM, purge sysprep — **hors du périmètre du swap** (sous `ProgramData`, jamais `Program Files`), survit par construction (mémoire `project_agent_token_file_path_contract`).
- **25.1 (done)** : contrat manifest + download figé, smoke-testé VM (release `2.1.2` stable publiée) ; décision n° 8 délègue Authenticode à l'agent — **c'est le contrat que cette story honore** ; `download_served` côté serveur = debug (la version rapportée fait foi — mémoire `project_release_deploy_trace_checkin_not_download`).
- **2.2.0 (cadence pilotée serveur)** : `ttl_seconds` serveur gouverne le poll (`loop.go EffectiveInterval`, clampé `[60s, 86400s]`) — l'update hérite de cette cadence (retry au prochain `ttl_seconds`, pas de timer dédié).
- **Mémoire `project_no_legacy_transition_state`** : pas d'état transitoire legacy/agent ; l'auto-update est le mécanisme qui permet l'extinction « en bloc » du canal legacy (parité à terminaison).

### References

- [Source: _bmad-output/planning-artifacts/epics-agent-desired-state.md#Story 25.2 (l.536-553)] — ACs source, FR24, NFR8 (chemin le plus testé).
- [Source: _bmad-output/implementation-artifacts/25-1-releases-serveur-binaires-signes-manifest-rings.md (décisions n° 2/7/8/9, AC2/AC4, File List)] — contrat manifest/download consommé, délégation Authenticode à l'agent.
- [Source: _bmad-output/planning-artifacts/architecture-agent-desired-state.md#D6 (l.335-341) ; #Process Patterns (l.491-505) ; #Architectural Boundaries (l.594-617) ; #Enforcement Guidelines (l.507-529)] — distribution rings, résilience, frontières, critère Keycloak.
- [Source: agent/shared/assets.go (SyncWallpaperAssets, l.105-177)] — squelette exact du download + SHA-256 avant écriture.
- [Source: agent/shared/client.go] — Client.Get/Post, bearer, rotation D5, borne 16 Mio, timeout 30 s.
- [Source: agent/shared/version.go (var Version, ldflags) ; agent/shared/contract.go (BuildReport, AgentVersion l.158)] — version courante + injection dans le rapport.
- [Source: agent/shared/loop.go (Agent, RunCycle, injection de fonctions, EffectiveInterval)] — point d'insertion + pattern d'injection des primitives.
- [Source: agent/windows/service_windows.go (serviceName SambaEduAgent, svc.Handler) ; install_windows.go (os.Executable, recovery) ; acl_windows.go (setAgentACL)] — service/SCM, chemin binaire, ACL.
- [Source: agent/build/build.sh ; agent/README.md] — artefact signé `sambaedu-agent-<version>.exe`, osslsigncode, CA interne, conventions module.
- [Source: docs/agent/release-distribution.md ; tests/Fixtures/Agent/release-manifest.v1.json] — doc 25.1, golden manifest réutilisable.
- [Source: docs/qa/domains/agent.md] — domaine QA, sections append-only.

## Dev Agent Record

### Agent Model Used

opus (Claude Opus 4.8, 1M) — story de sécurité fail-safe (reco story).

### Debug Log References

- `cd agent && PATH="$HOME/go-toolchain/go/bin:$PATH" go test ./...` → `ok sambaedu/agent/shared`, `windows [no test files]`.
- **Rework post-review (2026-06-13)** : `go test ./...` vert (159 fonctions de test top-level, ~202 cas avec sous-tests) ; `go vet ./...` (Linux) + `GOOS=windows GOARCH=amd64 go vet ./windows/...` propres ; cross-compile `GOOS=windows go build ./...` vert. Le swap/rollback (AC3) et M4 (403 release) sont désormais réellement testés sur Linux (`shared/swap_test.go` + `shared/update_test.go`/`loop_test.go`).
- Cross-compile + vet : `CGO_ENABLED=0 GOOS=windows GOARCH=amd64 go build ./... && go vet ./...` → vert (le package `./windows`, FFI WinVerifyTrust + SCM, ne compile pas sous Linux mais cross-compile proprement).
- `go test -race ./...` signale UNE data race **pré-existante** dans `TestCompanionRunResidentReconvergesOnCacheChange` (`companion_test.go` / `engine_test.go`, hors scope 25.2) — la commande de CI du projet est `go test ./...` (sans `-race`), verte. À signaler à Henri comme dette de test existante, non introduite ici.

### Rework post-review (2026-06-13) — arbitrages Henri

- **P1 = Option A (sortie non-gracieuse + recovery SCM).** La mécanique
  `restartService` (stop+start in-process, source du deadlock #1) est
  **SUPPRIMÉE**. Après un swap RÉUSSI, l'agent provoque sa **propre sortie
  non-gracieuse** `os.Exit(swapExitCode=42)` : le process meurt SANS signaler
  `SERVICE_STOPPED` → le SCM voit une terminaison anormale → la recovery
  (`ServiceRestart ×3`, déjà configurée à l'install) relance le binaire vN+1.
  - **Garantie de relance** : `install_windows.go` ajoute
    `SetRecoveryActionsOnNonCrashFailures(true)` (ceinture-bretelles) pour que la
    recovery s'applique AUSSI si le SCM classe notre `os.Exit(≠0)` comme un
    « échec non-crash » (code de sortie ≠ 0) et pas seulement comme un « crash ».
    Sans ce flag, une sortie à code ≠ 0 considérée non-crash ne déclencherait pas
    la relance.
  - **Aucun rapport de succès avant la sortie** (mémoire
    `project_release_deploy_trace_checkin_not_download`) : swap OK → log local
    explicite + exit, point. La preuve de succès = la nouvelle `agent_version`
    rapportée par vN+1 au prochain check-in.
  - **Lisibilité journal Windows** : un log local `swap vX→vY réussi, sortie
    volontaire (code 42) pour relance SCM` précède l'exit (le contre de l'Option
    A = ça ressemble à un plantage dans le journal d'événements — documenté comme
    « plantage volontaire attendu » dans QA Section 9).
- **#6 + M6 = swap/rollback extrait dans `shared/` et RÉELLEMENT testé Linux.**
  Le cœur anti-brique vit désormais dans `shared/swap.go` (`PerformSwap`),
  opérant sur des chemins injectés (les renames POSIX se comportent comme Windows
  pour ce besoin). Le déclencheur de restart (`os.Exit`) est **injecté** comme
  `triggerRestart func()`, stubé dans les tests. `windows/swapAndRestart` se
  réduit aux spécificités OS : résolution `os.Executable`, log + `os.Exit`. Les
  doublons de tests « échec swap »/« échec restart » (indiscernables, #6) sont
  remplacés par les VRAIS tests de rollback dans `shared/swap_test.go`.
- **M1 = copie atomique.** `atomicCopyFile` (tmp+rename SUR LE MÊME RÉPERTOIRE
  que la cible, donc même volume) remplace l'ancien `copyFile` (`ReadFile`+
  `WriteFile` direct) : un crash pendant l'écriture ne laisse jamais un
  `agent.exe.new` tronqué visible. Cross-volume géré (le tmp est à côté de la
  cible, jamais dans `os.TempDir`).
- **M2 = re-vérification du binaire réellement exécuté.** `PerformSwap` re-hashe
  `agent.exe.new` à SA position finale (Program Files) == `manifest.Hash` AVANT le
  rename final ; divergent → abort + cleanup, ancien binaire intact. Le hash
  manifest est transmis via la nouvelle signature
  `SwapAndRestart(stagedPath, version, expectedHash string)`. Le binaire qui sera
  exécuté a passé la porte d'intégrité à sa position finale, pas seulement au
  staging (cohérent « deux portes »).
- **M4 = Option 1 (403 release ≠ quarantaine globale).** Un `403` sur le canal
  RELEASE (manifest OU download) ne fait plus que **sauter l'update** : il pose un
  `pendingUpdateError` (rapporté comme item `agent_update`) SANS positionner
  `a.quarantined`. Le poste continue son cycle normal et **envoie son report**.
  La quarantaine globale (qui supprime le `POST /report`) reste réservée au `403`
  du canal principal `/state` (loop.go, inchangé). Testé en `shared/` (deux tests
  SelfUpdate sans quarantaine) ET via un **cycle complet** `runCycle`
  (`TestRunCycle403ReleaseSkipsUpdateButStillReports` : 403 release → pas de
  quarantaine, report émis, item `agent_update`).

### Completion Notes List

- **Choix Authenticode tranché : WinVerifyTrust in-process via `golang.org/x/sys/windows`** (et NON le shell-out `Get-AuthenticodeSignature`). `x/sys/windows` (déjà au `go.mod`, **zéro dépendance neuve**) expose nativement `WinVerifyTrustEx`, `WINTRUST_ACTION_GENERIC_VERIFY_V2`, `WinTrustData`/`WinTrustFileInfo` et les constantes `WTD_*` : pas de FFI manuel à écrire ni de struct Win32 à redéclarer (le risque « erreur silencieuse sur struct mal alignée » du FFI brut est écarté). Le code de retour est **binaire** (`nil` = signé + chaîne de confiance valide jusqu'à une CA de confiance machine ; tout autre = rejet) — pas de parsing de chaîne localisée (`Valid`/`Valide`) fragile en locale FR, pas de spawn `powershell.exe`. `WTD_REVOKE_NONE` (poste de salle potentiellement hors-ligne ; la confiance de chaîne jusqu'à la CA interne suffit, brief #31). `WTD_STATEACTION_CLOSE` appelé pour libérer le contexte. Le shell-out reste l'échappatoire documentée (README) si besoin terrain.
- **Précision swap volume (au-delà de la story) : copie-à-côté avant rename.** Le staging vit sous `ProgramData` et la cible sous `Program Files` — potentiellement des **volumes distincts** → un `os.Rename` direct (étape (c)) échouerait en cross-device. `swapAndRestart` copie d'abord le binaire stagé (déjà hash+signature OK) en `agent.exe.new` **à côté de la cible** (même volume), puis fait les renames `.old`/en-place localement : le rename final reste atomique sur le volume de Program Files. `cleanupOldBinary` nettoie `.old` ET `.new` au boot.
- **Update INERTE sans primitives.** `SelfUpdate` retourne tôt si `SwapAndRestart == nil` (Linux, tests sans stub) : aucun appel réseau d'update, l'auto-remplacement n'a de sens qu'en service Windows réel (pas de SCM ni Authenticode ailleurs). Couvert par `TestSelfUpdateWithoutPrimitivesIsNoop`.
- **AC4 (version dans le rapport) déjà satisfaite par construction** (`contract.go:158`, `BuildReport` injecte `AgentVersion: Version`) — non réimplémentée, juste vérifiée par test. Le succès d'update ne pose PAS d'item ; la nouvelle `agent_version` rapportée par l'image vN+1 EST la preuve (mémoire `project_release_deploy_trace_checkin_not_download`).
- **Ordre strict des deux portes** : (1) SHA-256 du corps == hash manifest AVANT toute écriture (rien écrit si divergent — `TestSelfUpdateHashMismatchNeverWritesNeverSwaps`) ; (2) Authenticode du fichier stagé AVANT tout swap (jamais swappé si invalide — `TestSelfUpdateSignatureInvalidNeverSwaps`). Vérifications successives, pas alternatives.
- **Égalité stricte, pas semver** (`manifest.version != shared.Version`) : un downgrade de rollback décidé serveur est appliqué. Anti-boucle par construction (une version appliquée fait passer `shared.Version` à la cible au redémarrage → plus de divergence).
- **Smoke VM/poste de lab NON exécuté** (AC7 dernier point) : story agent Go pure, pas de SSH `/vm` depuis le dev-cycle (consigne). Documenté comme **action manuelle Henri** dans `docs/qa/domains/agent.md` Section 9 (scénarios 9.1-9.5, prérequis CA de confiance machine + binaire vN+1 signé). La VM serveur 25.1 est figée (intouchée par cette story).

### File List

**Créés :**
- `agent/shared/update.go` — orchestration `SelfUpdate` (décision/download/double vérif/staging), parse manifest, extraction filename, item `agent_update`. **Rework** : 403 release = update sauté SANS quarantaine (M4) ; appel `SwapAndRestart(staged, version, hash)` (hash pour M2).
- `agent/shared/update_test.go` — matrice NFR8 (httptest + stubs) ; **rework** : 403 release → pas de quarantaine (M4), cycle complet `runCycle` 403 release → report émis, hash transmis au swap (M2), stub `SwapAndRestart` à 3 args.
- `agent/shared/swap.go` — **CRÉÉ (rework #6/M6/M1/M2)** : `PerformSwap` (cœur anti-brique testable : `atomicCopyFile`→re-hash `.new` (M2)→rename→rollback, `triggerRestart` injecté), `atomicCopyFile`, `verifyFileHash`.
- `agent/shared/swap_test.go` — **CRÉÉ (rework AC3)** : rollback RÉELLEMENT testé Linux (nominal + triggerRestart appelé, staged absent, hash `.new` divergent M2, échec rename final → rollback + triggerRestart jamais appelé, copie atomique).
- `agent/windows/update_windows.go` — `verifyAuthenticode` (WinVerifyTrust), `setUpdateACL`, `cleanupOldBinary` ; **rework** : `swapAndRestart` réduit aux spécificités OS (résout `os.Executable`, délègue à `shared.PerformSwap`, `triggerRestart = os.Exit(swapExitCode)` — Option A) ; `restartService`/`copyFile` SUPPRIMÉS.

**Modifiés :**
- `agent/shared/loop.go` — champs injectés `VerifyAuthenticode`/`SwapAndRestart`(signature à 3 args)/`UpdateACL` + `pendingUpdateError` ; appel `a.SelfUpdate(cfg)` dans `RunCycle` ; agrégation de l'item `agent_update` au `BuildReport` ; commentaires Option A + M4.
- `agent/shared/loop_test.go` — **rework M4** : `newFakeServer` monte un handler `/api/v1/agent/release` configurable (`releaseCode`) pour tester le 403 release dans un cycle complet.
- `agent/shared/sessionstore.go` — `updateDirName` + `UpdateDir`/`UpdateStagePath`/`EnsureUpdateDir` (inchangé au rework).
- `agent/windows/main_windows.go` — câblage des 3 primitives dans `newAgent` (signature `swapAndRestart` à 3 args).
- `agent/windows/service_windows.go` — `cleanupOldBinary()` au démarrage du service.
- `agent/windows/install_windows.go` — `EnsureUpdateDir(setUpdateACL)` à l'install ; **rework Option A** : `SetRecoveryActionsOnNonCrashFailures(true)` pour garantir la relance après la sortie non-gracieuse post-swap.
- `agent/README.md` — section « Auto-update (Story 25.2) » : diagramme swap mis à jour (re-hash M2, copie atomique M1, sortie non-gracieuse + recovery SCM Option A), 403 release ≠ quarantaine (M4), découpage testabilité (`shared/swap.go`).
- `docs/qa/domains/agent.md` — Section 9 : scénario 9.1/9.4 restart = recovery SCM après sortie non-gracieuse (plantage volontaire attendu code 42), scénario 9.6 (M4 403 release) ajouté, pré-req + checklist mis à jour.
- `docs/qa/README.md` — entrée domaine agent étendue à 25.2.
- `_bmad-output/implementation-artifacts/sprint-status.yaml` — 25-2 → review.

## Change Log

- 2026-06-13 — Implémentation Story 25.2 (auto-update agent). `SelfUpdate` côté agent Go : détection (égalité stricte vs `shared.Version`), download via url manifest verbatim, double vérification SHA-256-avant-écriture + Authenticode-avant-swap, swap atomique anti-brique (rename→dépose→restart-SCM avec rollback, copie-à-côté pour le rename même-volume), report d'échec via item `agent_update`. Vérif Authenticode = WinVerifyTrust in-process (`x/sys/windows`, zéro dépendance neuve). Découpage `shared/` (testé Linux, 23 tests NFR8) × `windows/` (primitives stubées). 150 tests verts, build+vet Linux+Windows verts. Smoke VM/poste = action manuelle Henri (QA Section 9). Status → review.

## Recommandation Modèle Dev

**opus** — story de **sécurité et de logique critique fail-safe**, le profil exact où le réflexe « contrat agent = petit modèle » est à proscrire :

- **Vérification de signature AVANT exécution** : code FFI Windows neuf (`WinVerifyTrust`/`wintrust.dll`), aucun précédent dans le repo — domaine où une erreur silencieuse (vérif qui « passe » à tort) ouvre l'exécution d'un binaire non signé sur tout le parc. Exige rigueur sur les structures Win32 et la sémantique des codes de retour.
- **Auto-remplacement d'un binaire Windows en cours d'exécution** : séquence atomique rename→dépose→restart avec **rollback à chaque étape destructive** — l'anti-brique est l'AC la plus dure (NFR8), un raisonnement séquentiel sur les fenêtres d'échec est requis pour qu'« aucun instant ne laisse le poste sans binaire valide ».
- **Couverture de tests exhaustive NFR8** (« LE chemin le plus testé ») : matrice ≥14 cas avec injection de stubs des primitives Windows pour tester sur Linux le flux complet incluant les chemins destructifs — conception de testabilité non triviale (découpage `shared/`/`windows/` par injection de fonction).
- **Code Go cross-platform + Windows** sous contraintes architecture (frontière `agent_*`, critère Keycloak, résilience, url verbatim) et consommation fidèle d'un contrat amont figé.

`fable` (réflexe Epic 23/24 agent) serait défendable pour la seule plomberie HTTP du download — mais le couple *vérif-signature-avant-exécution* × *swap anti-brique* × *matrice NFR8* est précisément la logique critique qui justifie opus ici.

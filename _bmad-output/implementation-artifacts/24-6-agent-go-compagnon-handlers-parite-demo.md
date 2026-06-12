# Story 24.6 : Agent Go — compagnon de session, handlers wallpaper/overlay, parité démo

Status: review

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

En tant qu'admin d'établissement (et prof/élève côté session),
je veux le compagnon de session et les handlers wallpaper/overlay en Go, convergents et idempotents,
afin que la boucle complète tourne sur le binaire de production et que la démo palier 1 soit jouée pour de vrai.

## Contexte & intention

**Story issue de la course-correction 2026-06-12** (`_bmad-output/planning-artifacts/sprint-change-proposal-2026-06-12.md`, validée par Henri). Le binôme 24.5+24.6 réécrit en Go le prototype PowerShell de l'Epic 24. **24.5 (en review) a livré le core** : module `sambaedu/agent` (`shared/` OS-agnostique + `windows/` service SCM natif + `build/` signé), StateHasher iso-23.1 prouvé golden files, rotation D5/grâce/deux-acteurs, quarantaine, backoff, sous-commandes `install/uninstall/run/version`, build signé osslsigncode automatisé côté serveur (`scripts/build-agent.sh`). Cette story livre le **reste du binaire** : tout ce que les spikes PS 24.3 (compagnon) et 24.4 (handlers) avaient prototypé et validé en review.

**Ce que cette story est :**
- Le **compagnon de session Go** : portage du sous-système 2 tâches at-logon de 24.3 (fetch SYSTEM `?user=` + processus user résident), login jamais bloquant par construction, frontière de confiance NFR5 intacte
- Les **handlers `wallpaper` et `overlay` Go** + le **moteur de convergence générique** (machine d'états §5 du contrat, mode `default` → `drifted_allowed`, isolation par item, ordre serveur) — portage du design 24.4
- La chaîne complète **assets** (download SYSTEM + checksum + ACL Users R) et **drop per-SID → rapport** (collecte/validation stricte au cycle), iso-design 24.4
- Le **retrait des `.ps1` restants** (24.3/24.4 + `ContractV1.ps1`, sans consommateur après bascule) — pas d'état transitoire
- La **parité démo palier 1 sur le binaire Go signé** : UI → état → agent Go → rapport, vérifiable curl/jq iso-Epic 23 (le bouclage visuel UI = 24.7)

**Ce que cette story n'est PAS :**
- L'UI conformité / forcer la synchro (→ 24.7, gate palier 1)
- La distribution canari / auto-update / manifest (→ 25.x — la story COMPLÈTE le binaire que 25.1 distribuera)
- D'autres handlers (shortcuts, printers… → Epic 27, écrits directement en Go)
- Un installeur Rainmeter : install manuelle temporaire = prérequis démo (décision Henri 2026-06-12) ; le handler n'est JAMAIS un installeur
- Une story serveur : **AUCUN code PHP n'est modifié**. La route assets (`agent.v1.assets.wallpaper`), l'item `identity` d'`OverlayStateProvider` et les tests serveur (SessionCompanion/Asset/Handlers E2e) ont été livrés par 24.3/24.4 et RESTENT — les stories PS sont superseded côté agent, pas côté serveur.

**Les spikes 24.3/24.4 (superseded) sont la référence fonctionnelle** : leurs décisions de design ont été validées en reviews adversariales (et corrigées) — elles sont REPRISES ici, pas re-tranchées. Seule la techno change : ce qui était revue statique PS devient testable en vrai (`go test` sur l'hôte) — c'est un des gains majeurs du portage, l'exploiter (moteur §5 table-driven).

## Dépendances

| Dépendance | Statut | Ce qu'on en consomme |
|---|---|---|
| **24.5** agent Go core | **review** (seul T11 install lab restant) | TOUT le socle : `shared/{hasher (OrderedMap/DecodeJSONOrdered), contract (ParseState/BuildReport/enums), client (rotation D5/grâce/deux-acteurs), files (Store atomique tmp+PID/ACL), loop (Agent/RunCycle/backoff/quarantaine), logger}` ; `windows/{main, service SCM, install, acl (icacls), smbios}` ; `build/build.sh` + `scripts/build-agent.sh` (version lue dans `shared/version.go`). **Précédent projet : dev sur dépendance en review accepté — rebaser si la review 24.5 bouge le code.** |
| **24.3** compagnon PS | superseded — **source de design** | Design broker 2 tâches at-logon, canal réseau 100 % SYSTEM, identité résolue côté SYSTEM, cache per-SID + ETag par contexte, liste blanche `^S-1-5-21-` + login non vide (review #1), poll borné, quarantaine = pas de fetch session. Doc : `docs/agent/session-companion.md` |
| **24.4** handlers PS | superseded — **source de design** | Moteur §5 verbatim, conventions de hash (exclusive verbatim / aggregate empreinte), assets SYSTEM + checksum, drop per-SID `<SID>:M` + validation stricte, overlay.json per-user à sérialiseur fixe, compagnon résident, Rainmeter absent gracieux. Doc : `docs/agent/handlers-wallpaper-overlay.md` |
| 23.1→23.5, 24.1 | done | Contrat v1 + golden files FIGÉS, `?user=` + ETag par contexte, middleware token, ingestion rapports — tout le serveur, INTOUCHÉ |

**24.7 dépend de cette story** (la démo UI se joue sur le binaire complet). **25.1 dépend du binaire complet produit ici.**

## ⚠️ Pièges connus (lire avant de coder)

1. **Le nœud structurant (hérité 24.3, inchangé en Go)** : token = ACL SYSTEM+Administrators, contrat FIGÉ 23.3 (`docs/agent/enrollment.md` §3) → **le processus compagnon (droits user) ne peut NI lire le token NI appeler le serveur**. Tout le HTTP reste côté SYSTEM (service + fetch de session). Ne jamais relâcher l'ACL, jamais copier le token en zone lisible user, jamais le passer en argument/env d'un processus user.
2. **Un ETag par couple (poste, user)** : le contexte machine (cache 24.5) et chaque contexte `?user=` ont chacun LEUR `etag.txt` (per-répertoire de session). Réutiliser l'ETag machine sur un fetch `?user=` casse la revalidation. ETag stocké VERBATIM (guillemets RFC 7232 inclus), comme dans `Store.ReadEtag` 24.5.
3. **Partition stricte des portées — `session` n'est PAS vide sans user** : le scope est déclaré par TYPE (wallpaper et overlay = scope `session`), pas par maille — un wallpaper broadcast sort en portée `session` même machine-only. Service SYSTEM → portée `machine` SEULEMENT ; compagnon → `session` + `machine_user` SEULEMENT. Jamais de recouvrement.
4. **Mode `default` — règle §5 VERBATIM** (le « sabotage le plus dangereux » du contrat) : `réel ≠ cible ∧ dernier-appliqué = cible` → dérive humaine → ne PAS réappliquer → `drifted_allowed`. **Premier passage (pas de mémoire) = jamais `drifted_allowed`** : `réel = cible → compliant + persiste` ; `réel ≠ cible → applique + drift + persiste`. Comparaisons dernier-appliqué/cible par **hash d'item opaque** (fourni serveur). Cible changée (dernier-appliqué ≠ nouvelle cible) → applique → `drift`. C'est EXACTEMENT ce que le moteur Go doit tester table-driven (gain du portage).
5. **Conventions de hash du rapport (24.4, à conserver À L'IDENTIQUE)** : exclusive (wallpaper) = hash de l'item traité VERBATIM ; aggregate (overlay) = SHA-256 local de la **concaténation des hashes opaques** des items du type, **dans l'ordre du payload serveur**. Ce n'est PAS un recalcul de hash d'item (interdit — l'agent ne recalcule jamais depuis sa propre sérialisation). Changer la convention = fausses transitions `agent_report_events` côté serveur au premier rapport Go.
6. **`asset: null` = règle explicite « pas de fond imposé »** → no-op `compliant` ; **type absent** de la liste → l'agent ne touche pas et n'émet AUCUN statut. Type sans handler enregistré → ignoré + log DEBUG (contrat §8).
7. **Le rapport v1 n'a PAS de dimension user** (contrat §6 FIGÉ) ; items **uniques par type** (dupliqué = 422), `hash` hex-64, `detail` obligatoire non vide si `error`. La remontée session = drop per-SID collecté/fusionné par le service (plus récent gagne), iso-24.4.
8. **Frontière de confiance du drop** : le user peut forger SON `session-report.json` et SON applied-state local. Validation STRICTE côté service avant fusion (type ∈ `shared.ResourceTypes`, status ∈ `shared.ResourceStatuses`, hash `^[0-9a-f]{64}$`, detail borné, taille de fichier plafonnée, JSON invalide = ignoré + log). Impact borné à SON poste — documenter, pas sur-ingénier.
9. **NFC** : le serveur émet NFC, Windows peut produire du NFD → normaliser NFC avant toute comparaison réel/cible de CHAÎNES dans les handlers (`golang.org/x/text/unicode/norm` — la dépendance annoncée par 24.5 « le moment venu » : c'est maintenant ; justifier au README contre la contrainte 6, x/text = même confiance que x/sys). Les chemins Windows se comparent **case-insensitive**. Le StateHasher, lui, ne normalise RIEN (figé 24.5).
10. **overlay.json = sérialiseur à structure fixe, PAS `encoding/json` brut** (acquis 24.4, décision dev n° 1) : la regex WebParser Rainmeter exige `": "` (espace simple après les deux-points), ordre de clés littéral stable, Unicode brut UTF-8 — `encoding/json` émet compact `":"` sans espace. Reproduire byte-à-byte le format du sérialiseur PS 24.4 (le `test` = comparaison de contenu : tout octet compte). **Aucun champ volatil** (pas de `generated_at`) sinon drift à chaque passe.
11. **Rotation D5 deux-acteurs : déjà durci dans `shared/client.go` 24.5** (grâce mémoire + relecture disque avant 401 irrécupérable). Le fetch de session DOIT passer par ce même client — jamais un second client HTTP. Écritures token/cache déjà atomiques tmp+`$PID` (acquis review 24.3 #3, repris 24.5) : la concurrence service ⟷ tâche at-logon (deux processus SYSTEM) est couverte, ne pas la réintroduire ailleurs (drop, assets : mêmes patterns).
12. **Quarantaine (403)** : pas de fetch de session (check-ins légers = GET /state machine du service uniquement) ; le compagnon continue de converger sur son **dernier cache** (level-triggered, inoffensif — limitation MVP documentée 24.4, reconduite). La tâche at-logon (processus neuf) ne connaît pas l'état quarantaine du service : elle tente UN fetch, encaisse le 403, s'arrête (nuance documentée session-companion.md §7 — reconduire).
13. **Énumération de sessions** : côté SYSTEM, JAMAIS déclarée par le user (anti-usurpation par construction). Liste **blanche** `^S-1-5-21-` + garde login non vide (review 24.3 #1 — la liste noire DWM/UMFD était insuffisante). Login COURT (jamais `DOMAIN\user` vers `?user=`), jamais `quser` (sortie localisée). **Review 24.3 #6 (SID double-source)** : en PS, le SID venait de `Win32_Account.SID` (fetch) vs `WindowsIdentity` (compagnon) — divergence possible (AzureAD `S-1-12-1-*`) = compagnon muet. Le portage Go est l'endroit noté pour le résoudre : sourcer les deux côtés depuis le même sous-système Win32 (fetch : `LookupAccountName` ; compagnon : SID du token de processus) et DOCUMENTER l'équivalence — ou constater la limite résiduelle dans la doc.
14. **Login jamais bloquant par construction (NFR1)** : tâches planifiées at-logon asynchrones UNIQUEMENT — rien dans Winlogon/Userinit/GPO logon script, aucune attente réseau dans une étape bloquante. Le poll borné du compagnon (cache frais ~2 s / timeout ~60 s) est asynchrone à l'ouverture, donc licite.
15. **`unknown_user` sans bruit** : login inconnu/compte local → le serveur répond 200 machine-only + log `agent.state.unknown_user` (jamais d'erreur). Le compagnon traite cette enveloppe normalement (broadcasts possibles). `?user=` vide ne doit JAMAIS partir (garde login non vide — le test serveur 24.3 #9 fige le comportement).
16. **Binaire et artefacts** : `agent.exe` vit sous `C:\Program Files\SambaEdu\Agent\` (ACL défaut : Users read+execute — exécutable par la tâche compagnon). Les données restent sous `C:\ProgramData\SambaEdu\Agent\` (ACL SYSTEM+Admins) et `%LOCALAPPDATA%\SambaEdu\Agent\` (profil user). Le compagnon n'écrit RIEN sous ProgramData **sauf** son drop `reports\sessions\<SID>\` (ACL `<SID>:M` posée par SYSTEM).
17. **Zéro PHP, zéro route, zéro migration** → aucune opération artisan sur la VM. Tests serveur = non-régression seulement : `ssh -i ~/.ssh/id_se4fs_vm root@192.168.122.50 "cd /var/www/sambaedu-reload && php artisan test --filter Agent"` — baseline **206 passed (839 assertions)**, JAMAIS la suite complète (décision Henri).
18. **Tests Go = hôte uniquement** (toolchain go1.26.4 user-local `~/go-toolchain/go/bin/go`, cf. README 24.5) : `go test ./...`, `go vet` (linux ET `GOOS=windows`), cross-compile `GOOS=windows GOARCH=amd64`. Le spécifique Windows (FFI, WTS, registry, tâches) vit derrière suffixe `_windows.go` + stubs, validé cross-compile + lab humain. Rien à installer sur la VM (le build serveur `scripts/build-agent.sh` a sa propre toolchain épinglée).
19. **inotify ne propage PAS les deletes** : le retrait des `.ps1` (T9) laissera des fantômes sur la VM. **Signaler à Henri — cleanup SSH = SA décision, jamais celle du dev.** (Précédent 24.5 : purge faite par Henri via commit 6cec1af.)
20. **`ContractV1.ps1` : consommateur unique = `SessionCompanion.ps1`** (vérifié par grep 2026-06-12) → il part AVEC les `.ps1` de 24.3/24.4. Re-vérifier par grep au moment du rm (rien d'autre ne doit le dot-sourcer).
21. **Le chemin session PS est DÉJÀ cassé en lab** depuis 24.5 (`SessionStateFetch.ps1` dot-source `SambaEduAgent.ps1`, retiré) — casse temporaire assumée, documentée README. Ce portage la résorbe : pas de précaution de compat PS à prendre, mais l'install Go doit **désenregistrer les 2 tâches planifiées PS** (`SambaEduAgent-SessionFetch`, `SambaEduAgent-SessionCompanion`) si présentes (poste lab ws 49 les a encore).
22. **Rainmeter absent du poste = comportement gracieux** (amendement Henri, AC2 de 24.4) : le handler compose et écrit quand même `overlay.json` (la ressource config EST convergée → statut machine d'états normale, jamais `error` du seul fait de l'absence de Rainmeter) + log informatif. Install Rainmeter = prérequis manuel de la démo (NSIS silencieux, cf. `resources/overlay/README.md`), PAS du handler.
23. **`resources/overlay/` est INTOUCHÉ** : la skin Rainmeter pointe déjà sur le fichier per-user (24.4), le fetch POC Windows est déjà déprécié, Conky/Linux hors-scope. Le format d'`overlay.json` (contrat render : `identity.fullname`, `machine.name`, `machine.room`, `alerts[]`) est figé par `resources/overlay/README.md`.
24. **Findings reviews à ne pas régresser au portage** : 24.3 #1 (liste blanche), #3 (tmp `$PID`), #6 (SID — cf. piège 13) ; 24.4 #1 (échec UUID SMBIOS ne musèle jamais le rapport — déjà non-bloquant dans `smbios_windows.go` 24.5), #4 (dispatch handler durci — en Go, l'interface typée `Handler` règle structurellement le problème) ; 24.5 #1 (toute donnée à hasher passe par `DecodeJSONOrdered`/`OrderedMap`, jamais une map native — `Canonicalize` refuse les `map[string]any`, ne pas contourner).
25. **Fichiers FIGÉS — zéro édit** : `docs/agent/contract-v1.md`, `docs/agent/enrollment.md`, golden files `tests/Fixtures/Agent/*.v1.json`, `FROZEN_STATE_HASH`, TOUT le PHP serveur (`StateController`, `ReportController`, `AssetController`, `AuthenticateAgentToken`, `ReportIngestService`, providers, `OverlayService`…), `agent/shared/hasher.go` (noyau de conformité prouvé — on le CONSOMME).

## Décisions de design prises ici (à challenger en review, pas à re-trancher en dev)

1. **Un seul binaire, deux sous-commandes nouvelles** : `agent.exe session-fetch` (contexte SYSTEM, lancé par la tâche at-logon) et `agent.exe companion` (contexte user, lancé par la tâche at-logon Users, résident). Le cycle du service appelle la logique de session-fetch **in-process** (même code `shared/`, pas de sous-processus) après la portée machine — iso-design 24.3 décision n° 4 (rafraîchissement mid-session). L'IPC named-pipe service ⇄ session reste écarté (le modèle fichier per-SID est validé par les spikes et la review ; réévaluer à l'Epic 27) — maintenir la note README.
2. **Énumération des sessions interactives en Win32 plat** : WTS API (`WTSEnumerateSessions` + `WTSQuerySessionInformation` WTSUserName/WTSDomainName, via `golang.org/x/sys/windows`) privilégiée — zéro COM, zéro parsing localisé ; SID résolu par `LookupAccountName`. Échappatoire admise si un cas le justifie (addendum architecture) : shell-out `powershell Get-CimInstance` iso-spike. Filtres ACQUIS de la review 24.3 : liste blanche `^S-1-5-21-` + login non vide, dédoublonnage par SID. Côté compagnon : SID du token du processus courant (`windows.OpenCurrentProcessToken` → `GetTokenUser`). Documenter la résolution du double-lookup (piège 13) dans session-companion.md §10.
3. **Tâches planifiées enregistrées par `agent.exe install` (étendu)** : `SambaEduAgent-SessionFetch` (principal SYSTEM `S-1-5-18`, trigger AtLogOn, ExecutionTimeLimit 10 min) et `SambaEduAgent-SessionCompanion` (principal groupe `BUILTIN\Users` `S-1-5-32-545`, trigger AtLogOn, **sans limite d'exécution — résident, motivé en commentaire**, iso-24.4 piège n° 9), `MultipleInstances IgnoreNew`, idempotent (unregister si présentes — y compris les tâches PS homonymes héritées du spike). Implémentation : shell-out `powershell Register-ScheduledTask` (échappatoire explicitement admise — Task Scheduler natif = COM, exclu par la règle Rust/COM-WinRT ; `schtasks.exe` gère mal les principals de groupe). SIDs traduits par API, jamais de noms localisés en dur. `uninstall` supprime les 2 tâches (conserve les données, flag purge inchangé).
4. **Moteur de convergence générique dans `agent/shared/` (`engine.go`)** — cœur portable, contrainte n° 5 du cahier des charges, AUCUNE dépendance Windows : interface `Handler` (`Test(items) (bool, error)` / `Apply(items) error` — signature exacte au choix du dev, typée, par type de ressource), itération dans l'ordre du payload, **isolation par item** (erreur/panic d'un handler → item `{status: error, detail}` + on continue ; `recover()` au point de dispatch), machine d'états §5 (strict/default/premier passage) avec store applied-state **injecté**, production des items de rapport (unique par type, conventions de hash piège 5). Mode inconnu traité en `strict` + type exclusive multi-items = le DERNIER fait foi avec warning (acquis dev 24.4). **Testable table-driven sur l'hôte — la couverture §5 complète est un AC.**
5. **Handler `wallpaper` (`agent/windows/`)** : `test` = `HKCU\Control Panel\Desktop\WallPaper` (`golang.org/x/sys/windows/registry`) pointe-t-il vers `assets\<filename>` attendu (comparaison case-insensitive) ; `apply` = valeurs registre (WallpaperStyle=10, TileWallpaper=0) + `SystemParametersInfoW(SPI_SETDESKWALLPAPER, …, UPDATEINIFILE|SENDCHANGE)` via `windows.NewLazySystemDLL("user32.dll")` — **FFI sans cgo** (l'AC epic le nomme : « SystemParametersInfo via FFI Win32, ou shell-out PowerShell documenté si un cas le justifie »). Idempotent. `asset: null` → no-op `compliant` ; asset pas encore téléchargé (course avec la sync) → `error` + detail explicite, résorbé à la passe suivante.
6. **Handler `overlay` (`agent/windows/` pour l'écriture, composition testable dans `shared/`)** : composition du document depuis TOUS les items overlay de la passe (aggregate = union, ordre serveur ; item `identity` serveur en tête + `machine.name` = COMPUTERNAME local + `alerts[]`), **sérialiseur à structure fixe** (piège 10 — reproduire le format 24.4 byte-à-byte, golden de non-régression : un `overlay.json` produit par le PS 24.4 doit être reproduit à l'identique par le Go à payload égal) ; `test` = contenu identique après normalisation NFC ; `apply` = écriture atomique `%LOCALAPPDATA%\SambaEdu\Agent\overlay.json`. Mode `strict` → toute divergence réécrite + `drift`. Aucun item overlay ni identity → type absent du drop.
7. **Chemins locaux = CONTRATS 24.3/24.4 conservés tels quels** (le serveur et la doc QA les connaissent) : `cache\sessions\<SID>\{state.json,etag.txt}` (ACL `/inheritance:r`, SYSTEM F, Admins F, `<SID>:(OI)(CI)R`), `assets\<filename>` (ACL + `BUILTIN\Users` R), `reports\sessions\<SID>\session-report.json` (ACL `<SID>:(OI)(CI)M`), `%LOCALAPPDATA%\SambaEdu\Agent\{companion.log, overlay.json, applied-state.json}`. Extension du `Store` 24.5 (racines paramétrables, `SetACL` injectable → testable hôte) plutôt qu'un second système de fichiers. ACL posées À LA CRÉATION des répertoires per-SID ; les fichiers héritent (jamais de ré-ACL des tmp — acquis 24.3).
8. **Sync des assets wallpaper côté SYSTEM** : extension du `Client` 24.5 pour le download binaire (le `Response.Body []byte` actuel suffit pour des images ; streaming si le dev le juge utile), GET `/api/v1/agent/assets/wallpaper/<filename>` (URL construite depuis `server_url`, route documentée — pas de champ `url` au payload, décision 24.4 n° 2 figée), vérif **SHA-256 = `payload.checksum`** (corrompu = supprimé + log + retry au prochain passage), content-addressed (présent = jamais re-téléchargé). Appelée au cycle service ET en fin de session-fetch. Pas de purge (iso-24.4, noté).
9. **Drop & collecte iso-24.4** : le compagnon écrit `session-report.json` `{generated_at, items: [{type, status, hash, detail?}]}` après chaque passe (atomique, tmp PID) ; au cycle, le service lit tous les drops, valide strictement (piège 8), fusionne unique par type (`generated_at` le plus récent gagne — limitation multi-session documentée), ordre des types **ascendant** dans le rapport (déterminisme, acquis dev 24.4 n° 5), et passe les items à `BuildReport`. Latence ≤ 1 cycle acceptée (NFR3 ; « forcer la synchro » = 24.7).
10. **Compagnon résident** : au démarrage, poll borné du cache frais (~2 s / timeout ~60 s, fallback dernier cache existant ; aucun cache → reste résident, le cycle service peut l'écrire mid-session — acquis dev 24.4 n° 7) ; puis surveille le mtime du cache (~60 s) et rejoue les handlers sur changement + re-test périodique (~5 min, level-triggered, détection des dérives locales). Partition stricte : `session` + `machine_user` uniquement. Sortie propre à la fin de session (le processus meurt avec elle). Logs `%LOCALAPPDATA%\…\companion.log`, format/rotation/rétention iso-`shared.Logger` (réutiliser le Logger avec racine paramétrée).
11. **`agent_version` bump → `2.1.0`** (source unique `shared/version.go`) : les rapports du binaire complet se discernent en lab des rapports core-only `2.0.0` (et de la lignée PS `1.x`). Les scripts de build lisent la version dans `version.go` — le nommage `dist/sambaedu-agent-2.1.0.exe` suit automatiquement (`build.sh` + `scripts/build-agent.sh` vérifiés). Le re-build signé CA réelle se fait côté serveur (`update.sh::ensure_agent_build`, no-op sinon).
12. **Retrait des `.ps1` (T9) — seulement une fois les équivalents Go verts** : `agent/windows/SessionCompanion.ps1`, `agent/windows/SessionStateFetch.ps1`, `agent/shared/ConvergenceEngine.ps1`, `agent/windows/handlers/Wallpaper.ps1`, `agent/windows/handlers/Overlay.ps1`, `agent/shared/ContractV1.ps1` (consommateur unique = SessionCompanion.ps1, re-vérifier par grep). Après ce rm : **zéro `.ps1` agent au repo** (critère de succès du sprint-change-proposal). `resources/overlay/fetch/overlay-fetch.ps1` n'est PAS un `.ps1` agent (POC overlay, déjà déprécié côté Windows, Linux intact) — INTOUCHÉ.
13. **Pas de re-mesure KPI logon exigée par cette story côté dev** : la procédure 24.3 (3 logons ON vs 3 OFF) reste dans le runbook QA et se rejoue au lab sur le binaire Go (action humaine, T-lab) — le « jamais bloquant » est garanti par construction (même mécanisme tâches at-logon), la mesure le confirme.

## Acceptance Criteria

### AC1 — Compagnon de session Go : login jamais bloquant, frontière de confiance (FR17, NFR1, NFR5 — AC epic)

**Given** un logon utilisateur sur un poste enrôlé
**When** le compagnon de session Go démarre (tâche at-logon `BUILTIN\Users`)
**Then** l'ouverture de session n'attend RIEN du réseau : rien dans le chemin synchrone du logon (tâches asynchrones uniquement — piège 14), la convergence `session`/`machine_user` démarre APRÈS ouverture
**And** le compagnon tourne aux droits de la session (jamais SYSTEM), ne peut ni lire le token, ni modifier les fichiers de l'agent (binaire, config, caches → Access Denied), ni lire le cache/drop d'un autre user (ACL per-SID) ; ses écritures = `%LOCALAPPDATA%\SambaEdu\Agent\` + SON drop `reports\sessions\<SID>\` (piège 16)
**And** l'identité de session est résolue côté SYSTEM (énumération WTS, liste blanche `^S-1-5-21-` + login non vide) — le processus user ne déclare jamais son identité
**And** aucun appel AD/Kerberos/LDAP dans tout le binaire (critère Keycloak NFR7, grep en review).

### AC2 — Fetch de session SYSTEM : `?user=`, cache per-SID, ETag par contexte (FR17, FR23)

**Given** un logon d'un user du domaine
**Then** la tâche `session-fetch` (SYSTEM) appelle `GET /api/v1/agent/state?user=<login_court>` avec le `If-None-Match` DU contexte (poste, user) via le client 24.5 (rotation D5/grâce/deux-acteurs inchangés), écrit le cache `cache\sessions\<SID>\{state.json,etag.txt}` (atomique tmp+PID, ACL à la création)
**And** le cycle du service rafraîchit aussi les caches des sessions actives (in-process, après la portée machine) puis synchronise les assets
**And** login inconnu/compte local → enveloppe machine-only traitée sans erreur ni bruit ; `?user=` vide ne part jamais ; quarantaine → aucun fetch de session (pièges 12, 15)
**And** le compagnon lit ce cache (poll borné), `ParseState`, et ne traite que `session` + `machine_user` (piège 3).

### AC3 — Convergence wallpaper de bout en bout (FR18, FR20 — AC epic)

**Given** l'état cible contient un item `wallpaper` (biblio d'assets, maille résolue)
**When** la boucle du compagnon exécute `test` puis `apply` si écart
**Then** le fond d'écran correspond à l'asset cible : téléchargé par SYSTEM (route 24.4, checksum SHA-256 vérifié, cache content-addressed ACL Users R), appliqué via registre + `SystemParametersInfoW` en **FFI Win32 sans cgo** (shell-out PowerShell admis seulement si documenté/justifié — AC epic)
**And** `apply` idempotent : deux passes consécutives sur état stable = `compliant`, zéro écriture
**And** le statut est rapporté (item `wallpaper` réel dans `POST /report`, visible `agent_resource_states`)
**And** `asset: null` → no-op `compliant` ; type absent → aucun statut (piège 6).

### AC4 — Overlay : l'agent Go devient le fetch du POC (FR20 — AC epic)

**Given** l'item `overlay` (signaux postés + item `identity` serveur)
**When** le handler s'exécute
**Then** l'agent Go écrit `overlay.json` local (`%LOCALAPPDATA%\SambaEdu\Agent\overlay.json`, écriture atomique, **sérialiseur à structure fixe byte-compatible 24.4** — piège 10, golden de non-régression) — il DEVIENT le fetch du POC
**And** Rainmeter/Conky inchangés (`resources/overlay/` INTOUCHÉ — skin déjà pointée per-user), l'overlay affiche identité user + parc (fullname + room serveur, machine.name local)
**And** mode `strict` : toute divergence réécrite + `drift` ; comparaison de contenu normalisée NFC (piège 9)
**And** Rainmeter absent → comportement gracieux (config convergée, statut normal, jamais `error`, log informatif — piège 22).

### AC5 — Mode `default` : la dérive humaine est respectée (FR19, gap 1 — AC epic)

**Given** un item en mode `default` dont l'état réel a été modifié par un humain (réel ≠ cible ∧ dernier-appliqué = cible)
**Then** le handler ne réapplique PAS et rapporte `drifted_allowed`
**And** la persistance du dernier-appliqué par item vit per-user (`%LOCALAPPDATA%\…\applied-state.json`, map `type → {hash, applied_at}`, hashes opaques)
**And** premier passage sans mémoire : jamais `drifted_allowed` ; cible changée → applique → `drift` (règle §5 verbatim, piège 4)
**And** la machine d'états complète est couverte par des tests Go table-driven (toutes les transitions strict/default/premier passage).

### AC6 — Isolation, ordre serveur, drop per-SID → rapport (FR18 — AC epic, contrat INTOUCHÉ)

**Given** un handler qui échoue
**Then** statut `error` + `detail` non vide pour CE type, les autres handlers et le rapport continuent (isolation par item, `recover` au dispatch) ; exécution séquentielle dans l'ordre du payload serveur ; type sans handler → ignoré sans statut
**And** le compagnon écrit son drop `session-report.json` après chaque passe ; au cycle, le service valide STRICTEMENT (piège 8), fusionne unique par type (plus récent gagne, types ordonnés asc) et envoie un `POST /report` conforme au golden `report.v1.json` (schéma figé, pas de dimension user)
**And** conventions de hash À L'IDENTIQUE de 24.4 : exclusive = hash d'item verbatim, aggregate = empreinte de concaténation ordre serveur (piège 5)
**And** le serveur ingère SANS modification (`agent_resource_states` upserté, événements sur transition).

### AC7 — Retrait des `.ps1` restants : zéro `.ps1` agent au repo (AC epic, pas d'état transitoire)

**Given** les équivalents Go verts (tests + cross-compile)
**Then** `git rm` : `SessionCompanion.ps1`, `SessionStateFetch.ps1`, `ConvergenceEngine.ps1`, `handlers/Wallpaper.ps1`, `handlers/Overlay.ps1`, `ContractV1.ps1` (consommateur unique vérifié par grep avant rm — piège 20)
**And** plus AUCUN `.ps1` sous `agent/` (critère sprint-change-proposal) ; `resources/overlay/` intouché
**And** le piège inotify-deletes est signalé à Henri (fantômes VM — aucun cleanup SSH sans son accord, piège 19).

### AC8 — Build, install, parité démo sur le binaire complet (NFR6 — AC epic)

**Given** le build
**Then** le binaire complet (core 24.5 + compagnon + handlers) sort signé du pipeline existant (`build.sh`/`scripts/build-agent.sh` inchangés dans leur principe), `agent_version` bumpée (source unique `shared/version.go` — décision n° 11), toujours statique/cross-compilé/zéro dépendance runtime
**And** `agent.exe install` enregistre les 2 tâches planifiées (et désenregistre les tâches PS héritées si présentes — piège 21), `uninstall` les supprime ; idempotence conservée
**And** la convergence est démontrable de bout en bout : changer le wallpaper d'un parc dans l'UI → le poste de lab converge → le rapport remonte, **vérifiable curl/jq iso-Epic 23** — le bouclage visuel UI est scellé par 24.7 (AC epic).

### AC9 — Tests : go test hôte, baseline serveur intacte

**Given** la suite de tests Go
**When** `go test ./...` sur l'hôte (sans Windows)
**Then** tout passe : moteur §5 table-driven (AC5), isolation/ordre/dispatch (AC6), validation des drops + fusion, conventions de hash, composition overlay (golden byte-compatible 24.4 — AC4), partition des portées, parsing/empreintes via `OrderedMap` exclusivement (piège 24)
**And** `go vet ./...` (linux + `GOOS=windows`) et `CGO_ENABLED=0 GOOS=windows GOARCH=amd64 go build ./...` verts — le spécifique Windows (WTS, registry, FFI, tâches) validé cross-compile + lab humain
**And** côté serveur : **AUCUN fichier PHP modifié** ; non-régression /vm `php artisan test --filter Agent` = **206 passed (839 assertions)** (jamais la suite complète).

### AC10 — Documentation + QA (append-only)

**Then** `agent/README.md` mis à jour : sous-commandes `session-fetch`/`companion`, tâches planifiées, chemins per-user/per-SID, handlers, note de transition 24.5→24.6 soldée, version bumpée
**And** `docs/agent/session-companion.md` et `docs/agent/handlers-wallpaper-overlay.md` : références d'implémentation PS → Go (la vue serveur, les séquences, les ACL et les conventions restent identiques) ; §10 session-companion : résolution du double-lookup SID documentée (décision n° 2)
**And** `docs/qa/domains/agent.md` enrichi **append-only** (nouvelle **Section 6** « Compagnon + handlers Go : parité démo », sans renuméroter 1-5) : install tâches, logon nominal/hors-ligne, frontière de confiance (token illisible, écritures refusées), convergence wallpaper UI→poste, overlay, `drifted_allowed`, erreur isolée, rapport en base — la démo répétable côté agent ; + ligne 24.6 dans `docs/qa/README.md`
**And** restent INTOUCHÉS : `contract-v1.md`, `enrollment.md`, goldens, `FROZEN_STATE_HASH`, tout le PHP, `resources/overlay/`, `agent/shared/hasher.go` (piège 25).

## Tasks / Subtasks

- [x] **T1 — Moteur de convergence générique Go** (AC5, AC6) — *à faire EN PREMIER : c'est le cœur testable*
  - [x] `agent/shared/engine.go` : interface `Handler` typée, dispatch par type dans l'ordre du payload, isolation par item (`recover` + `{error, detail}`), machine d'états §5 (strict/default/premier passage — verbatim piège 4), store applied-state injecté, items de rapport uniques par type, conventions de hash (exclusive verbatim / aggregate concat ordre serveur), mode inconnu = strict, exclusive multi-items = dernier + warning
  - [x] Tests table-driven exhaustifs de la machine d'états (toutes transitions) + isolation + ordre + conventions de hash — pur Go, hôte
- [x] **T2 — Store : extension session/assets/drops** (AC1, AC2, AC6)
  - [x] Chemins contrats 24.3/24.4 (décision n° 7) : `cache\sessions\<SID>\`, `assets\`, `reports\sessions\<SID>\`, racine per-user `%LOCALAPPDATA%\SambaEdu\Agent\` (applied-state, overlay.json, companion.log) — racines paramétrables, écritures atomiques tmp+PID, ACL injectées (per-SID R / M, Users R sur assets) posées à la création des répertoires
  - [x] Lecture/écriture applied-state per-user (map `type → {hash, applied_at}`), drop session-report, ETag par contexte
  - [x] Tests hôte (SetACL no-op) : atomicité, ETag verbatim par contexte, formats
- [x] **T3 — Énumération sessions + fetch de session SYSTEM** (AC2)
  - [x] `agent/windows/sessions_windows.go` : WTS API (décision n° 2), liste blanche `^S-1-5-21-` + login non vide + dédoublonnage SID, login court, SID via `LookupAccountName`
  - [x] Logique de fetch dans `shared/` (testable httptest) : `GET /state?user=` avec ETag du contexte via le Client 24.5, 200 → cache per-SID, 304 → valide, 403 → skip quarantaine, erreur → log + skip (rattrapage au cycle)
  - [x] Sous-commande `session-fetch` (point d'entrée tâche) + intégration in-process au cycle du service (après portée machine, sans casser les outcomes existants)
- [x] **T4 — Sync des assets wallpaper (SYSTEM)** (AC3)
  - [x] Download binaire via le Client 24.5 (extension si nécessaire), URL construite depuis `server_url` (décision n° 8), vérif SHA-256 = checksum (corrompu = supprimé + log), content-addressed, ACL Users R
  - [x] Branché au cycle ET en fin de session-fetch ; tests httptest (200 binaire, checksum KO, skip si présent)
- [x] **T5 — Compagnon résident (sous-commande `companion`)** (AC1, AC2, AC5, AC6)
  - [x] Résolution de SON SID (token de processus), poll borné du cache frais (fallback dernier cache, sinon résident — décision n° 10), `ParseState`, partition `session`+`machine_user`, exécution moteur T1 + handlers, applied-state per-user, drop après chaque passe
  - [x] Boucle résidente : poll mtime ~60 s + re-test périodique ~5 min, sortie propre fin de session ; log `%LOCALAPPDATA%` via `shared.Logger` racine paramétrée
  - [x] AUCUN code réseau ni lecture token dans ce chemin (grep en review)
- [x] **T6 — Handler wallpaper** (AC3)
  - [x] `agent/windows/handler_wallpaper_windows.go` : test registre HKCU (case-insensitive) / apply registre + `SystemParametersInfoW` FFI (décision n° 5), `asset: null` no-op compliant, asset manquant = error + detail
  - [x] Logique pure (résolution chemin attendu, décision asset null) factorisée testable hôte
- [x] **T7 — Handler overlay** (AC4)
  - [x] Composition dans `shared/` (testable) : identity serveur + machine.name local + alerts, ordre serveur, sérialiseur à structure fixe byte-compatible 24.4 (piège 10) — **test golden** : document PS 24.4 reproduit à l'identique
  - [x] Écriture atomique per-user, test = contenu identique NFC (`x/text/unicode/norm`, justifiée README), strict → réécrit + drift, Rainmeter absent gracieux (log info)
- [x] **T8 — Collecte des drops au cycle → rapport items réels** (AC6)
  - [x] `shared/` : lecture des drops, validation stricte (type/status/hash/detail/taille — piège 8, table-driven), fusion unique par type (plus récent gagne), types asc, câblage dans `RunCycle` → `BuildReport` (remplace `items: []`)
- [x] **T9 — Install/uninstall/build étendus** (AC8)
  - [x] `install` : enregistrement des 2 tâches (décision n° 3, shell-out Register-ScheduledTask, idempotent), désenregistrement des tâches PS héritées, création/ACL des répertoires ; `uninstall` : suppression des 2 tâches
  - [x] Bump `shared/version.go` → décision n° 11 (les scripts de build suivent automatiquement) ; vérifier `build.sh` local (cert test) passe
- [x] **T10 — Retrait des `.ps1` restants** (AC7) — *seulement une fois T1→T9 verts*
  - [x] Grep des consommateurs (notamment `ContractV1.ps1`) puis `git rm` des 6 fichiers (décision n° 12) ; vérifier qu'il ne reste AUCUN `.ps1` sous `agent/`
  - [x] Noter en Completion Notes le rappel fantômes VM (cleanup SSH = décision Henri)
- [x] **T11 — Documentation + QA** (AC10)
  - [x] `agent/README.md` (sous-commandes, tâches, chemins, transition soldée), `docs/agent/session-companion.md` + `handlers-wallpaper-overlay.md` (impl. Go, note SID §10), `docs/qa/domains/agent.md` Section 6 append-only + checklist, `docs/qa/README.md` ligne 24.6
- [x] **T12 — Validation finale** (AC8, AC9)
  - [x] `go test ./...` + `go vet` (linux + windows) + cross-compile verts sur l'hôte
  - [x] /vm : `php artisan test --filter Agent` → 206 passed attendus, zéro PHP modifié (git status le prouve)
  - [ ] **Validation lab (poste windoobe ws 49) : ACTION HUMAINE (Henri)** — re-build signé CA réelle côté serveur, `agent.exe install`, prérequis Rainmeter manuel (NSIS silencieux + skin, cf. `resources/overlay/README.md`), dérouler runbook QA Section 6 : logon ON/OFF, frontière de confiance, démo wallpaper UI→poste→rapport (curl/jq), `drifted_allowed`, overlay identité+signal ; KPI logon 3×ON/3×OFF rejoué sur le binaire Go ; résultats tracés en Completion Notes. Solde au passage le T11 de 24.5 (même séance lab possible)

## Dev Notes

### Périmètre — livré / hors-scope

| Livré (24.6) | Hors-scope (story) |
|---|---|
| Compagnon de session Go (fetch SYSTEM + processus user résident) | UI conformité + forcer la synchro (24.7) |
| Moteur de convergence générique `shared/` + machine d'états §5 testée | Distribution canari, manifest, auto-update (25.x) |
| Handlers wallpaper (FFI Win32) + overlay (sérialiseur fixe) | Autres handlers (Epic 27) ; agent Linux |
| Sync assets SYSTEM + drop per-SID → rapport items réels | Installeur Rainmeter (workflow d'install postes — décision Henri) |
| Retrait des 6 `.ps1` restants (zéro `.ps1` agent au repo) | IPC named-pipe (documenté, réévalué Epic 27) |
| Bump version + binaire complet signé (pipeline existant) | Purge caches assets/drops (noté, plus tard) |
| Docs Go + QA Section 6 (parité démo) | Tout code PHP serveur (figé) ; `resources/overlay/` ; décommissionnement canal legacy wallpaper (F3, Epic 27) |

### Le socle 24.5 — ce qu'on consomme (ne PAS réinventer)

[Source: agent/shared/*.go ; agent/windows/*.go ; agent/README.md]

- `shared/client.go` : `Client` (Bearer relu disque, `X-Agent-Hostname`, If-None-Match verbatim, rotation D5 sur toute réponse, grâce mémoire + relecture disque deux-acteurs) — le fetch session et le download d'assets passent par LUI
- `shared/files.go` : `Store{Root, SetACL}` (écriture atomique tmp+PID, token/cache/etag/config/applied-state machine) — à ÉTENDRE (T2), pas à dupliquer
- `shared/contract.go` : `ParseState` (refus major, forward-compat), `BuildReport` (accepte des items — le champ existe depuis 24.5), `ContractScopes`, `ResourceTypes`, `ResourceStatuses`
- `shared/hasher.go` : `OrderedMap`, `DecodeJSONOrdered`, `HashItem` — toute empreinte locale (aggregate) se construit à partir des hashes OPAQUES serveur, le hasher ne sert qu'aux besoins de conformité ; ne JAMAIS hasher depuis une map native (Canonicalize refuse, c'est voulu)
- `shared/loop.go` : `Agent.RunCycle` (machine à états quarantaine/backoff/401) — y câbler session-fetch + sync assets + collecte drops sans casser les `Outcome`
- `shared/logger.go` : format `[ISO 8601] [LEVEL]`, rotation 7 j — réutiliser avec racine per-user pour `companion.log`
- `windows/` : `setAgentACL` (icacls, SIDs bruts), `smbiosUUID` (échec non bloquant — review 24.4 #1 déjà réglée), `install/uninstall` SCM (à étendre T9), `main_windows.go` (dispatch sous-commandes — y ajouter `session-fetch`/`companion`), `main_stub.go` (compilabilité hôte — étendre pour les nouveaux fichiers si besoin)

### Les spikes PS — référence fonctionnelle (design validé en review, à porter fidèlement)

[Source: _bmad-output/implementation-artifacts/24-3-*.md ; 24-4-*.md ; codeReviews/24-3.md ; 24-4.md ; docs/agent/session-companion.md ; docs/agent/handlers-wallpaper-overlay.md]

- **24.3** : nœud broker (canal réseau 100 % SYSTEM), séquence logon → fetch → cache → compagnon, ETag par contexte, partition, quarantaine at-logon (un fetch tenté, 403 encaissé), durcissement 401 deux-acteurs (DÉJÀ en Go 24.5). Corrections review à conserver : liste blanche `^S-1-5-21-`+login vide (#1), tmp PID (#3 — déjà 24.5), `ExecutionTimeLimit` différencié (#8), SID double-source à résoudre AU PORTAGE (#6 — c'est ICI).
- **24.4** : mode default §5 verbatim, conventions de hash, drop validé, fusion plus-récent-gagne, overlay à sérialiseur fixe (bug latent POC double-espace/Unicode évité — le Go doit reproduire CE format), `asset: null`, Rainmeter gracieux (amendement Henri), compagnon résident motivé. Corrections review : UUID non-bloquant (#1 — réglé 24.5), dispatch durci (#4 — structurel en Go), `KIND_RESERVED_IDENTITY`/tri ids (#2/#3 — côté serveur, INTOUCHÉS).
- Les fichiers `.ps1` eux-mêmes sont la spec d'implémentation la plus précise (lisibles au repo jusqu'au T10) : `SessionCompanion.ps1` (poll/partition/résident/drop), `ConvergenceEngine.ps1` (machine §5, `Resolve-ItemStatus`), `handlers/{Wallpaper,Overlay}.ps1` (test/apply exacts, sérialiseur), `SessionStateFetch.ps1` + fonctions session de l'ex-`SambaEduAgent.ps1` (dans l'historique git, retiré en 24.5 — au besoin `git show`).

### Contrat & serveur — invariants consommés (FIGÉS)

[Source: docs/agent/contract-v1.md §3-§9 ; report-endpoint.md ; state-endpoint.md ; app/Http/Controllers/Api/V1/Agent/*]

- Wallpaper : `exclusive`/`default`/`session`, payload `{asset, checksum}` (pas de champ url — décision 24.4 figée). Overlay : `aggregate`/`strict`/`session`, signaux + item `identity` `{kind, login, fullname, room}` (kind réservé serveur).
- Rapport : items uniques par type, hash hex-64 opaque, detail requis sur error, 422 si malformé. `ReportIngestService` compare (status, hash) au précédent — la convention d'agrégat lui est invisible mais sa STABILITÉ évite les fausses transitions.
- Route assets : `GET /api/v1/agent/assets/wallpaper/{filename}` (filename content-addressed `^[0-9a-f]{64}\.[a-z0-9]{2,5}$`), 404 indistinct, middleware token complet (401/403/X-Agent-New-Token).
- `?user=` : login court, case-insensitive, inconnu = 200 machine-only + `agent.state.unknown_user`, un ETag PAR contexte.

### Environnement de dev — règles VM (critiques)

- Code édité sur l'hôte, sync inotify auto. **Jamais de sync manuelle.** inotify ne propage pas les deletes → T10 = fantômes VM, signaler à Henri.
- **Go = hôte uniquement** (toolchain `~/go-toolchain/go/bin/go`, go1.26.4). La VM ne sert que pour `php artisan test --filter Agent` (et rebuilde le binaire signé toute seule via update.sh — pas une étape dev).
- Aucune route/config/migration → aucune opération artisan VM. Si le repo passe par un worktree git : jamais d'interaction VM depuis le worktree.

### Project Structure Notes

- `agent/` top-level = module Go autonome (`sambaedu/agent`), hors Laravel. Spécifique Windows par suffixe `_windows.go` + stubs `!windows` (convention 24.5).
- Nouveaux fichiers attendus (indicatif) : `agent/shared/{engine.go, sessionstore.go|files étendu, sessionfetch.go, assets.go, dropcollect.go, overlay_compose.go}` + tests ; `agent/windows/{sessions_windows.go, companion_windows.go, handler_wallpaper_windows.go, handler_overlay_windows.go, tasks_windows.go}` (+ stubs) ; découpage exact au choix du dev — `shared/` reste OS-agnostique et testable, c'est la frontière qui compte.
- Supprimés (T10) : les 6 `.ps1` (décision n° 12). Modifiés : `shared/version.go`, `shared/loop.go`, `windows/main_windows.go`, `windows/install_windows.go`, `main_stub.go`, `agent/README.md`, `docs/agent/{session-companion,handlers-wallpaper-overlay}.md`, `docs/qa/domains/agent.md`, `docs/qa/README.md`.
- Dépendances : + `golang.org/x/text` (norm NFC) — justifier README. Rien d'autre a priori (WTS/registry/FFI = `golang.org/x/sys` déjà présent).

### Intelligence stories précédentes

- **24.5 (review)** : structure module, conventions (suffixes _windows, stubs, Store injectable, httptest, table-driven), `BuildReport` prêt pour des items, `applied-state.json` machine préservé vide (reste l'infra des FUTURS handlers machine — les deux types présents sont session : per-user), review #1 = leçon OrderedMap (piège 24). Si la review 24.5 livre des correctifs → rebaser.
- **Baseline tests** : `--filter Agent` = **206 passed (839 assertions)** sur /vm. Go : 77 tests PASS post-review 24.5 — les nouveaux s'y ajoutent.
- **Mémoire projet** : pas d'état transitoire (extinction .ps1 en bloc à équivalence) ; Rainmeter provisioning = direction workflow install postes ; comprendre le métier avant le design (les spikes SONT le métier ici) ; doc suit le code.

### References

- [Source: _bmad-output/planning-artifacts/sprint-change-proposal-2026-06-12.md §4.A] — définition de la story, critères de succès (zéro .ps1 résiduel, démo palier 1 répétable sur binaire Go)
- [Source: _bmad-output/planning-artifacts/epics-agent-desired-state.md#Story 24.6] — AC source ; FR17, FR19, FR20 ; note bascule Go Epic 24
- [Source: _bmad-output/planning-artifacts/architecture-agent-desired-state.md#Addendum 2026-06-12 ; #Process Patterns ; #Enforcement Guidelines] — gate Go résolu, échappatoire shell-out, anti-patterns (logon synchrone, couteau-suisse), critère Keycloak
- [Source: _bmad-output/implementation-artifacts/24-3-compagnon-session-portee-user-login-jamais-bloquant.md ; codeReviews/24-3.md] — design broker + corrections (liste blanche, tmp PID, SID #6)
- [Source: _bmad-output/implementation-artifacts/24-4-handlers-wallpaper-overlay-convergence-reelle.md ; codeReviews/24-4.md] — handlers, moteur §5, conventions hash, drop, sérialiseur overlay, Rainmeter gracieux
- [Source: _bmad-output/implementation-artifacts/24-5-agent-go-core-service-systeme-build-signe.md ; codeReviews/24-5.md] — socle Go, pièges canonicalisation, OrderedMap (#1), build/signature serveur
- [Source: docs/agent/contract-v1.md §3, §4.1, §5, §6, §8 — FIGÉ] — payloads, NFC/zéro float, mode default verbatim, rapport, tableau vide ≠ type absent
- [Source: docs/agent/session-companion.md ; docs/agent/handlers-wallpaper-overlay.md] — la vue serveur des deux sous-systèmes (inchangée par le portage)
- [Source: docs/agent/enrollment.md §3 — FIGÉ] — ACL token = contrainte fondatrice
- [Source: resources/overlay/README.md] — contrat render (champs, regex Rainmeter, chemins), prérequis install manuelle
- [Source: agent/README.md ; agent/shared/*.go ; agent/windows/*.go] — socle 24.5, note transition à solder
- [Source: scripts/build-agent.sh ; agent/build/build.sh] — version lue dans shared/version.go, pipeline signé serveur

## Dev Agent Record

### Agent Model Used

claude-fable-5 (DEV BMAD, workflow dev-story, branche main, 1 session — 2026-06-12).

### Debug Log References

- Gates Go (hôte, toolchain go1.26.4 `~/go-toolchain/go/bin/go`) : `go test ./...` → **121 tests PASS** (44 nouveaux vs 77 post-24.5) ; `go vet ./...` vert sur linux ET `GOOS=windows` ; `CGO_ENABLED=0 GOOS=windows GOARCH=amd64 go build ./...` vert.
- Build signé local : `agent/build/build.sh` avec cert TEST jetable (openssl) → `sambaedu-agent-2.1.0.exe`, `osslsigncode verify` OK (nommage suit `version.go` automatiquement). Artefact local supprimé après validation (jamais déployable).
- /vm : `php artisan test --filter Agent` → **206 passed (839 assertions)** = baseline exacte, zéro régression. (Préalable : `composer install` rejoué sur la VM — le vendor avait été réinstallé `--no-dev` le 2026-06-12 à 16:11, phpunit/collision absents, `artisan test` inopérant.)
- ZÉRO PHP modifié (le `git status` hôte le prouve : seuls `agent/`, `docs/`, `_bmad-output/` touchés par la story ; les modifs `app/`/`routes/`/`config/` présentes au statut sont le travail kill-switch préexistant d'Henri, hors story).

### Completion Notes List

- **T1 moteur** : `shared/engine.go` — interface `Handler` typée (`Test/Apply` sur `[]StateItem`) : le dispatch durci de la review 24.4 #4 est réglé structurellement. `ResolveItemStatus` = §5 VERBATIM (strict/default/premier passage), couvert table-driven (9 cas) + cycle de vie complet intégré (premier passage drift → compliant → dérive humaine `drifted_allowed` sans réapplication → cible changée drift). Isolation par `recover()` au point de dispatch (erreur ET panic → `{error, detail}` borné 2000 runes, jamais vide, la passe continue). Conventions de hash 24.4 à l'identique : exclusive = hash d'item VERBATIM (dernier fait foi multi-items + warning), aggregate = SHA-256 hex de la concaténation des hashes opaques dans l'ordre serveur. Mode inconnu = strict + warning. Un Apply en échec ne persiste JAMAIS la cible.
- **T2 store** : `shared/sessionstore.go` — chemins contrats 24.3/24.4 conservés (`cache\sessions\<SID>`, `assets\`, `reports\sessions\<SID>`), ACL injectées (`SessionACL`) posées À LA CRÉATION des répertoires seulement (fichiers par héritage, testé : zéro ré-ACL à la réécriture), `WriteFileAtomic` tmp+PID générique, ETag de session VERBATIM par contexte (testé isolé de l'ETag machine), `UserStore` (%LOCALAPPDATA% : applied-state per-user, overlay.json, companion.log), applied-state corrompu → map vide + flag (premier passage §5). `Logger.FileName` paramétrable (companion.log, archives `companion-YYYY-MM-DD.log` — rotation/rétention identiques).
- **T3 sessions+fetch** : `windows/sessions_windows.go` — WTS pur Win32 plat (`WTSEnumerateSessions` + `WTSQuerySessionInformationW` lazy-DLL, états Active/Disconnected), liste blanche `^S-1-5-21-` + login non vide + dédoublonnage SID, login COURT (WTSUserName), SID par `LookupSID`. **Review 24.3 #6 (double-lookup SID) RÉSOLUE au portage** : fetch = LookupAccountName, compagnon = SID du token de processus — même sous-système LSA des deux côtés ; limite résiduelle AzureAD documentée (session-companion.md §10). `shared/sessionfetch.go` testé httptest : 200→cache per-SID + drop dir garanti AVANT passe, 304 (If-None-Match DU contexte), 401 arrêt des fetchs, 403 quarantaine (+ plus aucun fetch ensuite), v2 → cache du contexte PRÉSERVÉ, `?user=` vide ne part JAMAIS (garde structurelle), login URL-escapé, unknown_user 200 machine-only silencieux. Intégré in-process au cycle (`loop.go`) sans toucher les Outcome (tests 24.5 inchangés, tous verts) + sous-commande `agent.exe session-fetch` (process neuf : un fetch, 403 encaissé, sortie — asymétrie quarantaine reconduite).
- **T4 assets** : `shared/assets.go` — scan machine + toutes sessions, validation stricte filename/checksum AVANT toute jointure de chemin (le golden legacy `fonds/ecole-2026.jpg` est ignoré, testé), download via LE client 24.5 (rotation D5 incluse), SHA-256 vérifié AVANT écriture (un contenu corrompu n'entre jamais dans le cache), content-addressed (jamais re-téléchargé, testé), 404 non fatal, ACL Users:R à la création. Borne 16 Mio du client documentée (asset tronqué → checksum KO → jamais écrit). Appelé au cycle ET en fin de session-fetch. Pas de purge (iso-24.4, noté).
- **T5 compagnon** : `shared/companion.go` (+ `windows/companion_windows.go`) — AUCUN code réseau ni token dans le chemin companion (aucun `Client` construit — grep review : `shared/companion.go`, `windows/companion_windows.go`). Poll borné 2 s/60 s, fenêtre de fraîcheur 5 min (review 24.3 #4), fallback dernier cache, sinon attente RÉSIDENTE (cache mid-session rattrapé, testé). Partition stricte session+machine_user (portée machine jamais dispatchée, testé), applied-state PER-USER, drop après chaque passe (dir absent = skip + warn, convergence quand même — testé), boucle résidente mtime ~60 s + re-test ~5 min level-triggered, sortie propre sur ctx, cadences injectables (testées).
- **T6 wallpaper** : `windows/handler_wallpaper_windows.go` — registre HKCU (x/sys/registry) + `SystemParametersInfoW` en **FFI sans cgo** (user32 lazy-DLL), style fill (10/0), comparaison NFC + EqualFold (case-insensitive chemins Windows), idempotent. Logique pure factorisée `shared/handler_wallpaper.go` (testée hôte) : `asset: null` = no-op compliant, payload sans champ asset = error, format content-addressed obligatoire (anti-traversal testé), dernier item fait foi.
- **T7 overlay** : `shared/overlay_compose.go` — sérialiseur à STRUCTURE FIXE byte-compatible PS 24.4 (`": "` simple, ordre littéral, UTF-8 brut, \n, pas de \n final, clés toujours présentes) ; **golden** `shared/testdata/overlay.golden.json` (transcription ligne à ligne du sérialiseur PS — identité accentuée + sanitize espaces/retours-ligne + guillemet échappé) ; sanitize iso `OverlayService::sanitizeText` (Unicode whitespace, clamps 16/255/2000) ; AUCUN champ volatil (déterminisme testé). Handler `shared/handler_overlay.go` OS-agnostique par injection (testé hôte) : test = contenu identique après NFC (fichier NFD équivalent = conforme, testé), apply atomique, Rainmeter absent = gracieux (écrit quand même + log info, JAMAIS error — testé) ; détection Rainmeter côté windows.
- **T8 drops** : `shared/dropcollect.go` — plafond 256 KiB AVANT parse, validation stricte table-driven (type §7, status enum, hash hex-64 minuscule, error sans detail rejeté, detail borné), entrée forgée ne coule jamais le rapport (testé : la valide survit), fusion unique par type plus-récent-gagne (date non parsable = époque zéro), types ASC, câblé dans `RunCycle` → `BuildReport(items réels)` (testé bout en bout : drop → rapport HTTP).
- **T9 install/build** : `install` étendu — assets dir ACLé dès l'install, 2 tâches at-logon via shell-out `Register-ScheduledTask` (échappatoire admise — COM exclu, schtasks gère mal les groupes) : SessionFetch SYSTEM S-1-5-18 limite 10 min ; Companion groupe S-1-5-32-545 traduit par API, **ExecutionTimeLimit zéro = illimité, motivé en commentaire** (résident), `MultipleInstances IgnoreNew` ; idempotent, REPREND les tâches PS homonymes du spike (ws 49). `uninstall` supprime les 2 tâches (données conservées, -purge inchangé). Bump `2.1.0` (source unique), `build.sh` local vérifié signé (cert test).
- **T10 retrait .ps1** : grep consommateurs OK (ContractV1.ps1 = SessionCompanion.ps1 uniquement) puis `git rm` des 6 fichiers — **zéro `.ps1` sous `agent/`** (critère sprint-change-proposal) ; `resources/overlay/` intouché.
- **T11 docs** : `agent/README.md` (arbo, note transition SOLDÉE, x/text justifié contre la contrainte 6, contrats locaux complets per-SID/assets/drops/profil user, install lab 2.1.0 + tâches), `session-companion.md` (PS → Go, WTS, §10 double-lookup SID résolu + code Go), `handlers-wallpaper-overlay.md` (note portage + table de correspondance PS → Go), `docs/qa/domains/agent.md` **Section 6 append-only** (scénarios 6.1-6.8 : install tâches, logon nominal/hors-ligne + KPI, frontière de confiance, démo wallpaper UI→poste→rapport curl/jq, mode default, overlay gracieux/strict, erreur isolée + drops forgés) + checklist 6.x ; `docs/qa/README.md` ligne agent étendue 24.6.
- **Fichiers FIGÉS intouchés** (piège 25) : `contract-v1.md`, `enrollment.md`, goldens `tests/Fixtures/Agent/*`, `FROZEN_STATE_HASH`, tout le PHP serveur, `resources/overlay/`, `agent/shared/hasher.go`.
- ⚠️ **POINTS VM — décisions Henri (aucun cleanup SSH fait par le dev)** :
  1. **Fantômes `.ps1` sur la VM** (inotify ne propage pas les deletes) : `agent/shared/{ContractV1,ConvergenceEngine}.ps1`, `agent/windows/{SessionCompanion,SessionStateFetch}.ps1`, `agent/windows/handlers/{Wallpaper,Overlay}.ps1` — présents sur la VM, absents du repo.
  2. **`agent/build/dist/sambaedu-agent-2.1.0.exe` signé CERT TEST a synced sur la VM** (inotify, fichier gitignored) pendant la validation du build local. Parade posée : `version.go` re-modifié après coup (mtime VM 18:03 > exe 17:53) → `ensure_agent_build` rebuildera avec la CA réelle au prochain `update.sh` ; sinon `scripts/build-agent.sh --force` AVANT tout déploiement lab. **Ne jamais déployer l'exe actuellement dans dist/ VM.**
  3. **Vendor VM réinstallé avec dev deps** (`composer install`) : il avait été pruné `--no-dev` le 2026-06-12 à 16:11 (phpunit/collision absents → `artisan test` inopérant) — restauré pour la baseline ; si le prune venait d'update.sh, le comportement se reproduira.
- **RESTE (action humaine, T12-lab)** : validation lab ws 49 — re-build signé CA réelle côté serveur, `agent.exe install`, prérequis Rainmeter manuel (NSIS /S + skin), dérouler runbook QA **Section 6** (6.1→6.8), KPI logon 3×ON/3×OFF sur le binaire Go, résultats à tracer ici ; solde au passage le T11 de 24.5 (même séance).

### File List

Créés :
- agent/shared/engine.go — moteur de convergence générique (§5, isolation, conventions hash)
- agent/shared/engine_test.go
- agent/shared/sessionstore.go — extension Store (cache per-SID, assets, drops, applied-state per-user, UserStore, WriteFileAtomic)
- agent/shared/sessionstore_test.go
- agent/shared/sessionfetch.go — fetch de session SYSTEM (?user=, ETag par contexte, quarantaine) + RunSessionFetch
- agent/shared/sessionfetch_test.go
- agent/shared/assets.go — sync des assets wallpaper (SHA-256, content-addressed)
- agent/shared/assets_test.go
- agent/shared/dropcollect.go — collecte + validation stricte des drops → items de rapport
- agent/shared/dropcollect_test.go
- agent/shared/companion.go — compagnon résident (passe, poll borné, boucle, drop)
- agent/shared/companion_test.go
- agent/shared/overlay_compose.go — composition overlay.json (sérialiseur à structure fixe byte-compatible 24.4)
- agent/shared/overlay_compose_test.go
- agent/shared/handler_overlay.go — handler overlay OS-agnostique (NFC, Rainmeter gracieux)
- agent/shared/handler_wallpaper.go — logique pure wallpaper (asset null, anti-traversal)
- agent/shared/testdata/overlay.golden.json — golden byte-compatible du sérialiseur PS 24.4
- agent/windows/sessions_windows.go — énumération WTS + SID (LookupSID, token de processus)
- agent/windows/companion_windows.go — câblage sous-commande companion (zéro réseau/token)
- agent/windows/handler_wallpaper_windows.go — registre HKCU + SystemParametersInfoW (FFI sans cgo)
- agent/windows/tasks_windows.go — tâches planifiées at-logon (Register-ScheduledTask shell-out)

Modifiés :
- agent/shared/loop.go — Agent : champs Sessions/ACL, fetch sessions + sync assets in-process au cycle, rapport items réels (CollectSessionReports)
- agent/shared/logger.go — FileName paramétrable (companion.log)
- agent/shared/version.go — bump 2.1.0 (binaire complet)
- agent/windows/main_windows.go — sous-commandes session-fetch/companion, câblage newAgent (WTS + ACL)
- agent/windows/install_windows.go — assets dir + tâches at-logon à l'install, suppression à l'uninstall
- agent/windows/acl_windows.go — setSessionCacheACL/setSessionReportACL/setAssetsACL (icacls, SIDs bruts)
- agent/go.mod, agent/go.sum — + golang.org/x/text v0.38.0 (norm NFC, justifié README)
- agent/README.md — arbo, transition soldée, dépendances, contrats locaux, compagnon Go, install 2.1.0
- docs/agent/session-companion.md — implémentation Go, WTS, §10 double-lookup SID résolu
- docs/agent/handlers-wallpaper-overlay.md — note portage Go + table de correspondance PS → Go
- docs/qa/domains/agent.md — Section 6 (scénarios 6.1-6.8) + checklist 6.x, append-only
- docs/qa/README.md — ligne domaine agent étendue (24.6)
- _bmad-output/implementation-artifacts/sprint-status.yaml — 24-6 → review + note datée
- _bmad-output/implementation-artifacts/24-6-agent-go-compagnon-handlers-parite-demo.md — ce fichier

Supprimés (git rm — zéro .ps1 agent au repo) :
- agent/shared/ContractV1.ps1
- agent/shared/ConvergenceEngine.ps1
- agent/windows/SessionCompanion.ps1
- agent/windows/SessionStateFetch.ps1
- agent/windows/handlers/Wallpaper.ps1
- agent/windows/handlers/Overlay.ps1

## Recommandation Modèle Dev

**fable.** Décision projet actée (mémoire `feedback_epic23_model_fable5` : stories agent desired-state = fable, le réflexe « contrat = petit modèle » est précisément à éviter) — confirmée par l'analyse. Cette story est le portage le plus dense de l'epic : elle doit reproduire FIDÈLEMENT deux designs validés en review (broker de confiance 24.3 + machine d'états §5 et conventions de hash 24.4) dans un autre langage, où toute approximation est silencieusement catastrophique (sérialiseur overlay byte-exact sous peine de drift perpétuel, convention d'agrégat stable sous peine de fausses transitions serveur, §5 approximé = wallpapers réappliqués en boucle ou dérives jamais corrigées). S'y ajoutent du FFI Win32 sans cgo (SystemParametersInfo, WTS, registry), de la concurrence multi-process sur fichiers partagés, une frontière de confiance où chaque erreur d'ACL est un trou de sécurité ou un agent muet, et l'exigence de transformer la revue statique PS en vraie couverture de tests Go. `opus` reste le bon choix pour 24.7 (UI conformité Livewire).

## Change Log

- 2026-06-12 — Story 24.6 DÉVELOPPÉE (DEV claude-fable-5, dev-story) : T1-T11 livrés, status → review. Binaire Go complet 2.1.0 — moteur §5 table-driven + compagnon résident + session-fetch WTS (double-lookup SID 24.3 #6 résolu) + sync assets + collecte drops → rapport items réels + handlers wallpaper (FFI sans cgo) / overlay (golden byte-compatible PS 24.4, NFC x/text) ; install/uninstall étendus (2 tâches at-logon) ; 6 .ps1 retirés (zéro .ps1 agent) ; docs + QA Section 6 append-only. Gates : 121 tests Go PASS, vet linux+windows, cross-compile, build signé local vérifié (cert test) ; /vm --filter Agent 206 passed (839 assertions), zéro PHP. Reste : T12 lab (humain) ; points VM tracés en Completion Notes (fantômes .ps1, exe cert-test dans dist/ VM à rebuilder CA réelle, vendor dev deps restauré).
- 2026-06-12 — Story 24.6 créée (SM/create-story, suite au sprint-change-proposal-2026-06-12) : portage Go du compagnon de session (broker 2 tâches at-logon, fetch SYSTEM `?user=` + processus user résident, frontière de confiance NFR5, login jamais bloquant par construction) et des handlers wallpaper (FFI Win32)/overlay (sérialiseur fixe byte-compatible, NFC) avec moteur de convergence générique `shared/` (mode default §5 verbatim testé table-driven), sync assets SYSTEM + drop per-SID → rapport items réels (conventions de hash 24.4 conservées), retrait des 6 `.ps1` restants (zéro `.ps1` agent au repo), bump version, parité démo palier 1 curl/jq sur le binaire signé. Zéro PHP serveur (baseline 206 passed à préserver). Dépend de 24.5 (review — rebaser si correctifs). Status backlog → ready-for-dev.

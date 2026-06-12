# Story 24.4 : Handlers wallpaper + overlay — la convergence devient réelle

Status: review

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

En tant qu'admin d'établissement,
je veux que le fond d'écran et l'overlay d'identité convergent sur le poste depuis mes règles UI,
afin de voir le modèle fonctionner sur les premières ressources réelles.

## Contexte & intention

**Quatrième story de l'Epic 24** (gate palier 1 : démo live UI → état → agent → rapport → UI). État du canal : Epic 23 done 5/5 + validé e2e ; 24.1 done (POST /report) ; **24.2 et 24.3 en review** (code committé main, baseline /vm `--filter Agent` : **189 passed / 745 assertions** — seule la validation lab humaine T8/T9 reste). Toute la tuyauterie existe : état compilé servi (wallpaper + overlay déjà DANS l'enveloppe via les providers 23.4), boucle service SYSTEM, compagnon de session, ingestion des rapports. **Cette story remplace les no-op par les deux premiers handlers réels** — c'est elle qui rend la démo possible (24.5 ajoutera l'UI conformité).

**Ce que cette story est :**
- Les handlers `wallpaper` et `overlay` côté poste : boucle `test → apply si écart → report` (FR18), idempotente, isolée, séquentielle dans l'ordre du payload serveur
- La première implémentation du **mode `default`** (gap 1 du contrat §5, le « sabotage le plus dangereux ») : persistance du dernier-appliqué par item, `drifted_allowed` sur dérive humaine
- Le **devenir du fetch POC overlay** : l'agent écrit `overlay.json` local, Rainmeter/Conky inchangés
- La **remontée des résultats session dans le rapport** — le design déféré par 24.3 (piège n° 10), à résoudre SANS toucher le contrat v1
- Deux compléments serveur explicitement déférés à 24.4 par les stories antérieures : la **route de serving des assets wallpaper** (`state-providers.md` : « URL de téléchargement → 24.4 ») et l'**arbitrage de composition d'`overlay.json`** (bloc identité/machine — `OverlayStateProvider` : « l'arbitrage final appartient à la story 24.4 »)

**Ce que cette story n'est PAS :**
- L'UI conformité / forcer la synchro (→ 24.5)
- La distribution/auto-update (→ 25.x) — on reste dans le MVP PowerShell jetable
- D'autres handlers (shortcuts, printers… → Epic 27)
- Le décommissionnement du canal legacy wallpaper (`/api/v1/workstation-config/wallpaper`, `WallpaperResolver`) — il reste intouché jusqu'à l'extinction par ressource (F3) ; en lab les deux coexistent (cohabitation = lab uniquement, décision projet)

**Le nœud de design de la story (à lire avant tout) :** `wallpaper` et `overlay` sont tous deux **scope `session`** (constantes des providers 23.4). La partition 24.3 est stricte : portées `session`/`machine_user` = compagnon, **droits user**. Or le compagnon **n'a ni réseau ni token** (contrat 23.3 figé) et **n'écrit rien hors `%LOCALAPPDATA%`** (NFR5). Conséquences structurantes : (a) le téléchargement des assets wallpaper est fait **en amont par SYSTEM** (détenteur du token) dans un cache local lisible user ; (b) le dernier-appliqué des items session est **per-user** (le `applied-state.json` machine de 24.2 est inaccessible en écriture au compagnon — il reste l'infra des futurs handlers machine) ; (c) les résultats de convergence session remontent au service par un **drop per-SID** que le service collecte, valide et fusionne dans SON rapport (le rapport v1 n'a pas de dimension user).

## ⚠️ Pièges connus (lire avant de coder)

1. **Contrat v1 FIGÉ — zéro édit** : `docs/agent/contract-v1.md`, golden files `tests/Fixtures/Agent/*.v1.json`, `FROZEN_STATE_HASH` (`6c0e8135…`), `enrollment.md`, `StateController.php`, `ReportController.php`, `AuthenticateAgentToken.php`. Le rapport (§6 + `report-endpoint.md`) impose : **items à types UNIQUES** (type dupliqué = 422), `hash` = hex-64 **opaque** (jamais recalculé depuis un payload), `detail` obligatoire et non vide si `status = error`, `status` ∈ enum. Le `ReportIngestService` (24.1) accepte déjà des items réels — AUCUNE modification serveur du chemin report n'est nécessaire.
2. **Scope `session` pour les DEUX types → c'est le COMPAGNON qui applique, pas le service.** Le wallpaper Windows est par-user (HKCU + `SystemParametersInfo`), l'overlay s'affiche dans la session. Ne jamais « simplifier » en appliquant côté SYSTEM : violation de la partition 24.3 (chaque item est traité par exactement un acteur) et cassure multi-session.
3. **Le compagnon ne peut PAS écrire `C:\ProgramData\SambaEdu\Agent\applied-state.json`** (ACL SYSTEM+Admins). Le dernier-appliqué des items session vit per-user sous `%LOCALAPPDATA%\SambaEdu\Agent\applied-state.json` (décision n° 5). Le fichier machine créé vide en 24.2 reste en place (futurs handlers machine) — le pattern/format est repris, pas le fichier.
4. **Mode `default` — la règle §5 EXACTE, ne pas l'approximer** : `réel ≠ cible ∧ dernier-appliqué = cible` → dérive humaine → ne PAS réappliquer → `drifted_allowed`. **Premier passage (pas de mémoire) = jamais `drifted_allowed`** : `réel = cible → compliant + persiste` ; `réel ≠ cible → applique + drift + persiste`. La comparaison dernier-appliqué/cible se fait **par hash d'item** (opaque, fourni par le serveur).
5. **NFC + casse** : le serveur émet en NFC, Windows peut produire du NFD — normaliser (`.Normalize([Text.NormalizationForm]::FormC)`) avant toute comparaison réel/cible de chaînes (note 24.2 explicitement adressée à 24.4). Les chemins Windows se comparent **case-insensitive**. (Les filenames d'assets sont hex ASCII — le piège vit surtout dans les comparaisons overlay/registre.)
6. **Hash rapporté pour un type `aggregate` (overlay) : le serveur ne fournit PAS de hash d'ensemble par type.** L'état porte un hash PAR item ; le rapport exige UN item par type avec UN hash hex-64. Le serveur ne compare ce hash qu'au **rapport précédent** (`report-endpoint.md` : « compare (status, hash) entrant à la ligne existante »), jamais à l'état compilé. Convention agent (décision n° 7) : SHA-256 local de la concaténation des hashes opaques des items du type, dans l'ordre du payload serveur. Ce n'est PAS un recalcul de hash d'item (interdit) — c'est une empreinte d'agrégat construite à partir de chaînes opaques. Pour `exclusive` (wallpaper) : le hash de l'item traité, verbatim.
7. **`asset: null` = règle EXPLICITE « pas de fond imposé »** (contrat §8), distinct du type absent. Handler : ne touche pas au fond, rapporte `compliant` (décision n° 8). Type absent de la liste = l'agent **ne touche pas** à la ressource et **n'émet aucun statut** pour elle.
8. **`routes/api.php` : la nouvelle route assets s'ajoute à la FIN du bloc agent desired-state** (le commentaire in-file le dit : « Futurs endpoints du canal : derrière l'alias `agent.token`, à ajouter ici, à la FIN du bloc »), jamais juste avant le groupe 16.12 (fenêtre 1500 chars `ScriptsOsNamespaceTest`). **Route ajoutée ⇒ sur la VM : `php artisan route:cache` + chown www-admin** (piège récurrent — sinon 404 navigateur avec tests verts).
9. **`ExecutionTimeLimit` du compagnon = 2 min** (correctif review 24.3 #8) : incompatible avec la boucle résidente requise pour la réexécution mid-session (décision n° 6). À ajuster dans `Install-SambaEduAgent.ps1` — changement délibéré d'un réglage post-review 24.3, à motiver en commentaire.
10. **Frontière de confiance du drop de résultats** : le user peut forger SON `session-report.json` (et SON applied-state local). Validation STRICTE côté service avant fusion (types ∈ liste publiée §7, status ∈ enum, hash `^[0-9a-f]{64}$`, detail tronqué/borné, taille de fichier plafonnée, JSON invalide = drop ignoré + log). Impact borné : il ne peut fausser que les statuts session de SON poste — à documenter, pas à sur-ingénier.
11. **Exécution séquentielle dans l'ordre du payload serveur** (AC epic, FR18) : l'agent n'invente pas d'ordre, ne parallélise pas. `Build-Report` (ContractV1.ps1) accepte déjà `-Items` avec `-Depth 10` — prêt pour cette story.
12. **Quarantaine (403)** : le fetch session est sauté (24.3) → le compagnon continue de converger sur son **dernier cache** (level-triggered, inoffensif : l'état ne change plus). Limitation MVP assumée, à documenter — ne PAS construire un canal de signalisation quarantaine vers le compagnon pour ça.
13. **24.2 et 24.3 sont en review, pas done** : réutiliser leurs fonctions (`Invoke-AgentHttpWithGrace`, `Update-TokenIfRotated`, `Get-InteractiveSessions`, `Initialize-SessionCacheDir`, `Save-SessionStateCache`, `Invoke-SessionStateFetch`, `Set-AgentAcl`, `Parse-State`, `Build-Report`, `Wait-SessionStateCache`, `Write-CompanionLog`…) — jamais les dupliquer. Si un correctif post-review tombe, rebaser.
14. **Tests : `--filter Agent` UNIQUEMENT, jamais la suite complète** (décision Henri). Baseline attendue : **189 passed (745 assertions)** post-corrections 24.3. SQLite en tests : les varchar ne sont pas appliqués (mémoire projet) — pas de garde-fou de longueur à « tester » côté DB.
15. **Rainmeter = regex WebParser fragile** (caveat POC) : un guillemet `"` dans `title`/`text` casse le parsing, l'**ordre des clés** du JSON compte. La composition d'`overlay.json` doit garder un ordre de clés STABLE (`[ordered]@{}`) et réutiliser l'aplatissement de texte (iso `OverlayService::sanitizeText`) côté composition locale. Conky (jq) non concerné.
16. **Pas de tests PowerShell** (décision projet 24.2/24.3 reconduite) : couverture = tests serveur + revue statique stricte (StrictMode, `@()` anti-déroulement scalaire, `PSObject.Properties`, écriture atomique tmp+`Move-Item` suffixé `$PID` là où deux écrivains existent, SIDs jamais de noms localisés).

## Décisions de design prises ici (à challenger en review, pas à re-trancher en dev)

1. **Route serveur de serving des assets wallpaper (NEUVE)** : `GET /api/v1/agent/assets/wallpaper/{filename}`, middleware iso state/report (`auth.v1.secure-headers`, `throttle:60,1`, `agent.token`), nom `agent.v1.assets.wallpaper`, controller `App\Http\Controllers\Api\V1\Agent\AssetController` (mince — aucun service métier requis : validation stricte du filename content-addressed `^[0-9a-f]{64}\.[a-z0-9]{2,5}$`, lookup `WallpaperAsset` par filename, `BinaryFileResponse` depuis `absolutePath` — la défense anti-traversal du modèle existe déjà, review F7). 404 si inconnu/fichier absent ; logs channel `agent` : `agent.asset.served` / `agent.asset.not_found` (contexte `workstation_id`, `filename`).
2. **PAS de champ `url` dans le payload wallpaper.** Le payload reste `{asset, checksum}` (figé 23.4) : l'agent construit l'URL depuis `server_url` (config.json) + chemin de route documenté — exactement comme il le fait pour `/state` et `/report`. Évite un churn d'ETag/hash sur tous les contextes au déploiement et toute question d'URL absolue/relative. L'alternative « champ mineur `url` » (notée par `state-providers.md`) reste possible plus tard sans casse (champ ajouté = mineur, contrat §9).
3. **Téléchargement des assets côté SYSTEM, cache partagé lisible user** : `C:\ProgramData\SambaEdu\Agent\assets\<filename>` (content-addressed), ACL à la création du répertoire : SYSTEM F, Administrators F, **`BUILTIN\Users` (`*S-1-5-32-545`) R** — un wallpaper n'est pas un secret et le user doit pouvoir l'afficher. Fonction `Sync-WallpaperAssets` appelée par le cycle du service ET par le fetch de session : scanne les items `wallpaper` de tous les états fraîchement écrits (machine + sessions), télécharge les assets manquants, **vérifie le SHA-256 = `payload.checksum`** (fichier corrompu = supprimé + log, retry au prochain cycle). Pas de purge en 24.4 (volume borné par la biblio), noté pour plus tard.
4. **Composition `overlay.json` : enrichissement serveur + assemblage local.** L'épic exige « identité user + parc » — le compagnon ne connaît localement ni le fullname ni la salle, et le critère Keycloak interdit tout appel AD. Résolution : `OverlayStateProvider` émet, **en plus** des signaux, un **candidat synthétique `kind: "identity"`** (maille `User`, uniquement si `ctx->user` non null) : `{kind: "identity", login, fullname, room}` — `fullname` = users, `room` = nom du premier WG **physique** du poste (null si aucun) — données STABLES (l'ETag ne bouge que si elles bougent : correct). Les alertes dérivées volatiles (quota, multi-session — `OverlaySignalBuilder`) restent HORS desired-state, conformément à 23.4. Le handler compose ensuite `overlay.json` **iso-contrat render POC** (`identity.fullname`, `machine.name` = `$env:COMPUTERNAME` local, `machine.room`, `alerts[]` {severity, title, text}) — Rainmeter/Conky n'utilisent que ces champs.
5. **`overlay.json` est écrit par le compagnon sous `%LOCALAPPDATA%\SambaEdu\Agent\overlay.json`** — pas le `%PROGRAMDATA%\SambaEdu\overlay.json` du POC : le compagnon n'écrit que dans son profil (NFR5), et un fichier per-user est correct par construction en multi-session. Le code regex des adaptateurs render est **inchangé** ; seul le **chemin lu** par la skin Rainmeter est mis à jour (variable de chemin dans le `.ini` — c'est de la config d'adaptateur, pas du code : l'esprit « Rainmeter/Conky inchangés » de l'epic est la façade JSON, préservée). L'adaptateur `fetch` POC Windows (`overlay-fetch.ps1`) est marqué déprécié côté Windows dans `resources/overlay/README.md` (l'agent EST le fetch désormais) ; Linux intouché.
6. **Dernier-appliqué session per-user + compagnon résident.** `%LOCALAPPDATA%\SambaEdu\Agent\applied-state.json` : map `type → {hash, applied_at}` (hash d'item opaque pour exclusive ; empreinte d'agrégat pour aggregate). Le compagnon devient une **boucle résidente** : après la passe du logon, il re-scanne son cache (poll mtime ~60 s) et rejoue les handlers quand l'état change ET périodiquement (~5 min) pour détecter les dérives locales (level-triggered). `ExecutionTimeLimit` de la tâche compagnon ajusté (illimité — piège n° 9), `-MultipleInstances IgnoreNew` déjà posé en 24.3 empêche le doublon.
7. **Remontée des résultats session : drop per-SID + collecte au cycle.** Le fetch SYSTEM crée `C:\ProgramData\SambaEdu\Agent\reports\sessions\<SID>\` à côté du cache (ACL : `/inheritance:r`, SYSTEM F, Administrators F, **`<SID>:(OI)(CI)M`** — le user écrit SON drop, ne lit pas ceux des autres). Le compagnon y écrit `session-report.json` après chaque passe : `{generated_at, items: [{type, status, hash, detail?}]}`. Au cycle, le service lit tous les drops, **valide strictement** (piège n° 10), fusionne **unique par type** (le `generated_at` le plus récent gagne — postes d'école = 1 session interactive, limitation multi-session documentée) et passe ces items à `Build-Report`. Latence ≤ 1 cycle entre convergence session et rapport serveur : acceptable (NFR3, fraîcheur laxe) — la démo utilise un redémarrage de service ou attend le cycle (le « forcer la synchro » arrive en 24.5). Alternative écartée : lecture des profils users par SYSTEM via `Win32_UserProfile.LocalPath` (résolution de chemins de profils fragile, écritures agent hors arborescence agent).
8. **Handler `wallpaper`** (`agent/windows/handlers/Wallpaper.ps1`) : `test` = le fond courant de la session (`HKCU:\Control Panel\Desktop\WallPaper`) pointe-t-il vers `assets\<filename>` attendu (comparaison de chemins case-insensitive) ; `apply` = écrit la valeur + style `fill` (WallpaperStyle=10, TileWallpaper=0) + rafraîchit via `SystemParametersInfo(SPI_SETDESKWALLPAPER)` (P/Invoke `Add-Type`, flags UPDATEINIFILE|SENDCHANGE) — **idempotent** (mêmes écritures = même état). `asset: null` → no-op `compliant` (décision n° 8 = piège n° 7). Asset pas encore téléchargé (course avec `Sync-WallpaperAssets`) → `error` + detail explicite, résorbé au passage suivant. Mode `default` du provider → logique §5 complète avec applied-state.
9. **Handler `overlay`** (`agent/windows/handlers/Overlay.ps1`) : la cible = le document `overlay.json` composé (décision n° 4) depuis TOUS les items overlay de la passe (aggregate = union, ordre serveur). `test` = le fichier existant est-il identique au document cible (comparaison de contenu après normalisation NFC) ; `apply` = écriture atomique. Mode `strict` du provider → toute divergence est réécrite (`drift`), pas d'applied-state nécessaire pour le verdict mais l'empreinte d'agrégat est persistée pour le rapport. Aucun item overlay (et pas d'identity, ex. cache machine-only) → type absent du drop.
10. **Moteur de convergence générique dans `agent/shared/ConvergenceEngine.ps1`** (cœur portable, contrainte n° 5 du cahier des charges — AUCUNE dépendance Windows) : itère les items dans l'ordre du payload, dispatch vers le handler enregistré par `type` (type sans handler = ignoré silencieusement + log DEBUG, contrat §8), **try/catch PAR item** (un échec → `{status: error, detail}` et on CONTINUE — AC epic isolation), applique la machine d'états §5 (strict/default/premier passage) avec le store applied-state injecté, produit la liste d'items de rapport (unique par type, conventions de hash décision n° 7/piège n° 6). Les handlers Windows ne contiennent QUE le `test`/`apply` spécifique OS.

## Acceptance Criteria

### AC1 — Convergence wallpaper de bout en bout (FR18, FR19 — AC epic)

**Given** l'état cible contenant un item `wallpaper` (biblio d'assets, maille résolue — provider 23.4 inchangé)
**When** la boucle du compagnon exécute `test` puis `apply` si écart
**Then** le fond d'écran de la session correspond à l'asset cible (asset téléchargé par SYSTEM, checksum SHA-256 vérifié, appliqué via registre + `SystemParametersInfo`), `apply` est **idempotent** (rejouable sans effet cumulatif — deux passes consécutives sur état stable = `compliant`, zéro écriture)
**And** le statut est rapporté au serveur (item `wallpaper` réel dans `POST /report`, visible dans `agent_resource_states`)
**And** `asset: null` (règle explicite « pas de fond imposé ») → le handler ne touche pas au fond et rapporte `compliant` ; type absent → aucun statut émis pour ce type.

### AC2 — Overlay : l'agent devient le fetch du POC (AC epic)

**Given** l'item `overlay` (signaux postés + item `identity` serveur, décision n° 4)
**When** le handler s'exécute
**Then** l'agent écrit `overlay.json` local (chemin per-user `%LOCALAPPDATA%\SambaEdu\Agent\overlay.json`, écriture atomique, ordre de clés stable) — il DEVIENT le fetch du POC
**And** le code des adaptateurs render Rainmeter/Conky est inchangé (seul le chemin lu par la skin Windows est pointé sur le nouveau fichier) et l'overlay affiche **identité user + parc** (fullname + room servis par l'enrichissement `identity` de `OverlayStateProvider`, machine.name local)
**And** mode `strict` (constante provider) : toute divergence du fichier est réécrite et rapportée `drift`
**And** **Rainmeter absent du poste = comportement gracieux** : le handler compose et écrit quand même `overlay.json` (la ressource config EST convergée → statut selon la machine d'états normale, jamais `error` du seul fait de l'absence de Rainmeter) + log local informatif « rainmeter absent, overlay non rendu » — installer l'application n'est pas du desired-state config (livraison = workflow d'install des postes, hors-scope).

### AC3 — Mode `default` : la dérive humaine est respectée (gap 1, contrat §5 — AC epic)

**Given** un item en mode `default` dont l'état réel a été modifié par un humain (réel ≠ cible ∧ dernier-appliqué = cible)
**Then** le handler ne réapplique PAS et rapporte `drifted_allowed`
**And** la persistance du dernier état appliqué par item existe per-user (`%LOCALAPPDATA%\SambaEdu\Agent\applied-state.json`, comparaisons par hash d'item opaque)
**And** premier passage sans mémoire : jamais `drifted_allowed` (réel = cible → `compliant` + persiste ; réel ≠ cible → applique → `drift` + persiste — règle §5 verbatim)
**And** cible changée côté serveur (dernier-appliqué ≠ nouvelle cible) → l'agent applique la nouvelle cible → `drift`.

### AC4 — Isolation des échecs + exécution séquentielle (AC epic)

**Given** un handler qui échoue (ex. asset absent, écriture refusée)
**Then** statut `error` + `detail` non vide rapportés pour CE type, les autres handlers et le rapport continuent (try/catch par item)
**And** l'exécution est séquentielle dans l'ordre du payload serveur (jamais d'ordre inventé, jamais de parallélisme)
**And** un type sans handler enregistré est ignoré sans erreur ni statut (contrat §8 : « ne touche pas »).

### AC5 — Remontée des résultats session dans le rapport, contrat v1 INTOUCHÉ

**Given** une passe compagnon terminée
**Then** le compagnon écrit son drop `reports\sessions\<SID>\session-report.json` (sa SEULE écriture hors `%LOCALAPPDATA%`, ACL `<SID>:M` posée par SYSTEM — décision n° 7)
**And** au cycle suivant, le service collecte les drops, **valide strictement** chaque entrée (type ∈ §7, status ∈ enum, hash hex-64, detail borné, taille plafonnée — entrée invalide = ignorée + log), fusionne unique par type et envoie un `POST /report` avec ces items réels
**And** le serveur ingère sans modification : `agent_resource_states` upserté par (workstation, type), événements `agent_report_events` sur transition — le payload rapport reste conforme au golden `report.v1.json` (schéma figé, pas de dimension user)
**And** le hash rapporté : exclusive = hash d'item verbatim ; aggregate = empreinte d'agrégat déterministe des hashes opaques dans l'ordre serveur (décision n° 7).

### AC6 — Route de serving des assets wallpaper (serveur, NEUVE)

**Given** `GET /api/v1/agent/assets/wallpaper/{filename}` avec un token agent valide
**Then** 200 + contenu binaire de l'asset de la bibliothèque (`WallpaperAsset` lookup par filename, fichier sous `config('wallpapers.library_path')`)
**And** filename non conforme au format content-addressed (`^[0-9a-f]{64}\.[a-z0-9]{2,5}$`) ou asset inconnu/fichier manquant → 404, jamais de traversal (défense `absolutePath` du modèle + validation amont)
**And** sans token → 401 ; poste en quarantaine → 403 (middleware `agent.token` inchangé) ; `X-Agent-New-Token` survit (D5, le middleware le pose sur tout 2xx)
**And** route ajoutée À LA FIN du bloc agent de `routes/api.php` (piège n° 8) ; **VM : `route:cache` + chown www-admin** documenté et exécuté
**And** logs channel `agent` : `agent.asset.served` / `agent.asset.not_found`.

### AC7 — Tests serveur : la boucle complète avec items réels

**Given** les tests `tests/Feature/Api/V1/Agent/` et `tests/Unit/Services/Agent/`
**Then** ils couvrent (conventions 23.5/24.x : factories, `TokenRotationService::issueFor()`, helpers privés, captureAgentLogs) :
- route assets : 200 binaire + bon contenu, 401, 403 quarantaine, 404 inconnu, 404 filename malformé (tentatives traversal), rotation due → `X-Agent-New-Token` sur le 200
- `OverlayStateProvider` : item `identity` présent en contexte user (login, fullname, room du WG physique ; room null sans salle), ABSENT en machine-only, payload sans float, ETag stable entre deux compilations
- e2e handlers : `GET /state` avec règles wallpaper + overlay réelles → `POST /report` avec items aux 4 statuts (`compliant`, `drift`, `drifted_allowed`, `error` + detail) → 200, `agent_resource_states` reflète, événements sur transition, rapport identique = zéro événement (comportements 24.1 vérifiés sur items réels)
**And** `php artisan test --filter Agent` sur /vm : baseline **189** + les nouveaux, zéro régression — **jamais la suite complète**.

### AC8 — Artefacts, installation, documentation, QA

**Given** le bundle agent
**Then** `agent/windows/handlers/{Wallpaper,Overlay}.ps1` + `agent/shared/ConvergenceEngine.ps1` sont copiés sous `C:\Program Files\SambaEdu\Agent\` (lisibles user, dot-sourcés par le compagnon), inclus dans le bundle signé (`Build-Agent.ps1`), install/uninstall idempotents (répertoires `assets\` + `reports\sessions\` créés/ACLés par l'install ou à la volée ; `ExecutionTimeLimit` compagnon ajusté — piège n° 9)
**And** `docs/agent/handlers-wallpaper-overlay.md` (NEUF, vue serveur iso `session-companion.md`) : les deux handlers, le flux assets (route + cache SYSTEM + checksum), la composition overlay.json (item identity), le drop de résultats + validation + conventions de hash, les limitations MVP (latence 1 cycle, multi-session, quarantaine/cache périmé, drop forgeable)
**And** `agent/README.md` enrichi (handlers, assets, overlay.json per-user, applied-state per-user, drop) ; `docs/agent/state-providers.md` mis à jour (item identity overlay, décision « pas de champ url ») ; `resources/overlay/README.md` annoté (fetch Windows déprécié, agent = fetch)
**And** `docs/qa/domains/agent.md` **Section 4** (APPEND-ONLY, numérotation existante intacte) : scénarios convergence wallpaper UI→poste, overlay (identité + signal posté), dérive humaine → `drifted_allowed`, erreur isolée, rapport visible en base — la **démo live répétable** du gate palier 1
**And** fichiers FIGÉS intouchés (piège n° 1).

## Tasks / Subtasks

- [x] **T1 — Route serveur assets wallpaper** (AC6)
  - [x] `app/Http/Controllers/Api/V1/Agent/AssetController.php` (NEUF) : validation filename, lookup `WallpaperAsset`, `BinaryFileResponse`, logs `agent.asset.*`
  - [x] Route à la FIN du bloc agent de `routes/api.php` (piège n° 8), nom `agent.v1.assets.wallpaper`, middleware iso state
  - [x] Tests feature route (AC7) ; VM : `route:cache` + chown www-admin après sync
- [x] **T2 — Enrichissement `identity` de OverlayStateProvider** (AC2, décision n° 4)
  - [x] Candidat synthétique `{kind: "identity", login, fullname, room}` (maille User, seulement si user non null ; room = nom du 1er WG physique du poste, sinon null ; lecture seule, pas de float)
  - [x] Tests unit provider mis à jour + déterminisme (deux compilations = même hash)
- [x] **T3 — Téléchargement des assets côté SYSTEM** (AC1, décision n° 3)
  - [x] `Sync-WallpaperAssets` dans `SambaEduAgent.ps1` : scan des items wallpaper des états écrits, GET assets manquants (réutilise la couche HTTP 24.2/24.3 — `Invoke-AgentHttpWithGrace`), vérif SHA-256 = checksum, ACL `assets\` (SYSTEM F / Admins F / Users R) à la création
  - [x] Branché dans `Invoke-AgentCycle` et `Invoke-SessionStateFetch` (après écriture des caches)
- [x] **T4 — Moteur de convergence générique** (AC3, AC4, décision n° 10)
  - [x] `agent/shared/ConvergenceEngine.ps1` (AUCUNE dépendance Windows) : dispatch par type, ordre serveur, try/catch par item, machine d'états §5 (strict/default/premier passage), store applied-state injecté, items de rapport (unique par type, hashs décision n° 7)
- [x] **T5 — Handler wallpaper** (AC1, AC3, décision n° 8)
  - [x] `agent/windows/handlers/Wallpaper.ps1` : test (HKCU WallPaper vs chemin asset, case-insensitive), apply (registre + style fill + `SystemParametersInfo` P/Invoke), asset null = no-op compliant, asset manquant = error + detail
- [x] **T6 — Handler overlay** (AC2, décision n° 9)
  - [x] `agent/windows/handlers/Overlay.ps1` : composition iso-contrat render POC (identity serveur + machine.name local + alerts), sanitize texte (piège n° 15), ordre de clés stable, test = contenu identique (NFC), apply = écriture atomique `%LOCALAPPDATA%\SambaEdu\Agent\overlay.json`
  - [x] Skin Rainmeter (`resources/overlay/rainmeter/`) : chemin lu → nouveau fichier per-user ; `resources/overlay/README.md` annoté (fetch Windows déprécié)
- [x] **T7 — Compagnon : câblage handlers + boucle résidente + applied-state** (AC1-AC4, décision n° 6)
  - [x] `SessionCompanion.ps1` : remplace le no-op par le moteur T4 + handlers T5/T6 ; applied-state per-user (lecture/écriture `%LOCALAPPDATA%`) ; boucle résidente (poll mtime cache ~60 s, re-convergence sur changement + re-test périodique ~5 min) ; sortie propre au logoff
- [x] **T8 — Remontée des résultats : drop per-SID + collecte service** (AC5, décision n° 7)
  - [x] Création/ACL `reports\sessions\<SID>\` côté SYSTEM (avec `Initialize-SessionCacheDir` ou équivalent dédié, ACL `<SID>:M`)
  - [x] Compagnon : écriture `session-report.json` après chaque passe (atomique, tmp `$PID`)
  - [x] Service : `Read-SessionReports` (validation stricte piège n° 10, fusion unique par type, plus récent gagne) branchée dans `Invoke-AgentCycle` → `Build-Report -Items`
- [x] **T9 — Install/uninstall/build** (AC8)
  - [x] `Install-SambaEduAgent.ps1` : copie handlers + engine, répertoires/ACL, `ExecutionTimeLimit` compagnon ajusté (commentaire motivé — piège n° 9) ; `Uninstall` : nettoyage ; `Build-Agent.ps1` : nouveaux .ps1 dans le bundle signé
- [x] **T10 — Tests serveur e2e handlers** (AC7)
  - [x] `tests/Feature/Api/V1/Agent/HandlersE2eTest.php` (NEUF) : scénarios AC7 (state avec règles réelles → report 4 statuts → états/événements en base)
  - [x] Run /vm : `php artisan test --filter Agent` — 189 + nouveaux, zéro régression (206 passed / 839 assertions)
- [x] **T11 — Documentation + QA** (AC8)
  - [x] `docs/agent/handlers-wallpaper-overlay.md` (NEUF) ; `agent/README.md`, `docs/agent/state-providers.md`, `resources/overlay/README.md` (modifs) ; `docs/qa/domains/agent.md` §4 (append-only)
- [ ] **T12 — Validation lab : LA démo palier 1** (ACTION HUMAINE, iso T9 24.2 / T8 24.3)
  - [ ] PRÉREQUIS (manuel, temporaire — décision Henri 2026-06-12) : installer Rainmeter sur ws 49 (installeur NSIS silencieux, cf. `resources/overlay/README.md` : `Rainmeter-x.y.z.exe /S /AUTOSTARTUP=1 /DESKTOPSHORTCUT=0`) + déployer la skin `resources/overlay/rainmeter/` pointée sur le fichier per-user ; la livraison automatisée sera intégrée au workflow d'install des postes (hors-scope, cf. tableau périmètre)
  - [ ] Sur windoobe (ws 49, enchaînable avec T9 24.2 + T8 24.3 encore dus) : changer le wallpaper d'un parc dans l'UI → cycle → le fond du poste change → rapport `compliant` en base ; modifier le fond à la main → `drifted_allowed` (mode default) ; poster un signal overlay → visible dans l'overlay Rainmeter avec identité + salle
  - [ ] Résultats tracés en Completion Notes (runbook `docs/qa/domains/agent.md` §4)

## Dépendances

| Story | Statut | Ce que 24.4 en consomme |
|---|---|---|
| **24.2 — Agent squelette** | `review` (code committé main ; reste T9 install lab humain) | Boucle service/cycle, couche HTTP + rotation D5, cache machine, `applied-state.json` (pattern), `Set-AgentAcl`, build signé, install service |
| **24.3 — Compagnon de session** | `review` (code committé main ; reste T8 lab humain : logons, KPI, frontière) | Sous-système fetch SYSTEM + compagnon user, cache per-SID + ETag par contexte, `Get-InteractiveSessions`, durcissement 401 deux-acteurs, tâches planifiées, partition des portées |

Précédent projet : 24.3 a été développée avec 24.2 en review — même approche ici (piège n° 13 : rebaser si correctifs post-review). Les validations lab T9/T8 restantes sont **enchaînables avec le T12** de cette story (une seule séance lab pour les trois).

Côté serveur, 23.4/23.5/24.1 (done) fournissent providers, `?user=`, ingestion — seuls `OverlayStateProvider` (enrichissement) et `routes/api.php` + nouveau controller sont touchés.

## Dev Notes

### Périmètre — livré / hors-scope

| Livré (24.4) | Hors-scope (story) |
|---|---|
| Handlers wallpaper + overlay (compagnon, session) | UI conformité / forcer la synchro (24.5) |
| Mode default + applied-state per-user (gap 1 réalisé) | Autres handlers — shortcuts, printers… (Epic 27) |
| Route serveur assets + download SYSTEM + checksum | Purge des caches assets/drops (noté, plus tard) |
| Item identity overlay (enrichissement provider) | Alertes dérivées volatiles (quota, multi-session) dans le desired-state |
| Drop per-SID + collecte + rapport items réels | Toute modification du contrat v1 / des goldens / du ReportIngestService |
| Compagnon résident (réexécution mid-session) | Décommissionnement canal legacy wallpaper (F3, Epic 27) |
| Démo palier 1 exécutable (runbook §4) | IPC named-pipe (agent définitif Go/.NET) |
| Adaptateur Rainmeter pointé per-user, fetch POC Windows déprécié | Adaptateur/agent Linux |
| Comportement gracieux si Rainmeter absent (config convergée + log info) | **Livraison automatisée de Rainmeter** — à intégrer au workflow d'install des postes (piste retenue : l'agent l'installe en première opération ; décision Henri 2026-06-12, install manuelle temporaire pour la démo) |

### Contrat & serveur — invariants consommés (FIGÉS, ne jamais modifier)

[Source: docs/agent/contract-v1.md §3-§8 ; docs/agent/report-endpoint.md ; tests/Fixtures/Agent/*.v1.json]

- Item d'état : exactement `{type, semantics, mode, payload, hash}`. Wallpaper : `exclusive`/`default`/`session`, payload `{asset, checksum}`. Overlay : `aggregate`/`strict`/`session`, payload par signal `{kind, severity, title, text, expires_at}` (+ `identity` ajouté ici — champ de payload, owné par la story provider, contrat §3.2 : PAS une évolution d'enveloppe).
- Rapport : `{schema, generated_at, agent_version, workstation, items}` ; items **uniques par type**, `hash` hex-64 opaque, `detail` requis sur `error`. 422 si malformé, rien n'est écrit.
- Règle §5 mode default + premier passage : recopiée au piège n° 4 — l'implémenter VERBATIM dans le moteur (T4), c'est LE point que la review 23.1 qualifiait de sabotage le plus dangereux.
- `ReportIngestService` compare `(status, hash)` au rapport précédent uniquement — il ne recalcule jamais rien depuis un payload : la convention d'empreinte d'agrégat (décision n° 7) est invisible pour lui.

### Code existant à réutiliser (ne PAS réinventer)

[Source: agent/windows/SambaEduAgent.ps1 ; agent/windows/SessionCompanion.ps1 ; agent/shared/ContractV1.ps1]

- HTTP/rotation : `Invoke-AgentHttp`/`Invoke-AgentHttpWithGrace` (HttpWebRequest, jamais Invoke-WebRequest), `Update-TokenIfRotated` — le download d'assets passe par là (Bearer, 401 grace, 403 quarantaine)
- Sessions/caches : `Get-InteractiveSessions` (liste blanche `^S-1-5-21-`), `Initialize-SessionCacheDir` (pattern ACL per-SID à reproduire pour `reports\sessions\`), `Save-SessionStateCache` (écriture atomique tmp `$PID`)
- Compagnon : `Wait-SessionStateCache`, `Write-CompanionLog`, `Invoke-CompanionPass` (le no-op à remplacer est commenté « le handler correspondant (24.4) remplacera cette ligne »)
- Contrat : `Parse-State`, `Build-Report -Items` (déjà `-Depth 10`), `Get-ContractResourceTypes`, `$script:ResourceStatuses`
- Serveur : `WallpaperAsset::libraryPath()`/`absolutePath` (défense traversal incluse), `TargetContext` (physicalGroupIds), pattern controller mince du canal (StateController/ReportController — à imiter, pas à modifier)

### Project Structure Notes

- Racine = projet Laravel (hôte → VM par inotify) ; `agent/` top-level hors Laravel ; handlers sous `agent/windows/handlers/` (arborescence prévue par l'architecture)
- **UNE route nouvelle** → VM : `php artisan route:cache` + chown www-admin (piège n° 8). AUCUNE migration, AUCUNE config nouvelle (la route assets lit `config('wallpapers.library_path')` existant) → pas de `config:cache`
- PHP-FPM user = www-admin : le nouveau controller suivra le sync inotify normal ; tests `--filter Agent` sur /vm uniquement
- inotify ne propage pas les suppressions — ne rien supprimer côté `resources/overlay/` (annoter, pas retirer)

### Intelligence stories précédentes

- **23.4 (done)** : payload wallpaper `{asset, checksum}` figé par la story provider ; « URL de téléchargement → 24.4 » et « arbitrage composition overlay.json → 24.4 » écrits noir sur blanc — cette story les solde
- **23.5 (done)** : un ETag par contexte (poste, user) ; piège cache routes VM vécu (18/18 en 404) — d'où l'opération route:cache OBLIGATOIRE ici
- **24.1 (done)** : 422 strict (type dupliqué, hash non hex-64, detail manquant) ; hostname court ; rapport identique = zéro événement
- **24.2 (review)** : ETag verbatim, écriture atomique, ACL par SID, `applied-state.json` machine créé vide « infra 24.4 » — son PATTERN est consommé ici, le fichier machine reste aux futurs handlers machine (les deux premiers types sont session) ; NFC explicitement « à traiter en 24.4 »
- **24.3 (review)** : partition stricte des portées ; compagnon sans réseau/token ; tmp `$PID` partout où DEUX écrivains existent ; `ExecutionTimeLimit` 2 min compagnon (à ajuster ICI, motivé) ; `$script:Quarantined` process-local (le compagnon ne le voit pas — piège n° 12)
- **Mémoire projet** : comprendre le métier avant le design (le POC overlay et la refonte wallpaper SONT le métier ici — les réutiliser, pas les refaire) ; doc suit le code

### References

- [Source: _bmad-output/planning-artifacts/epics-agent-desired-state.md#Story 24.4] — AC source (4 AC epic repris en AC1-AC4)
- [Source: _bmad-output/planning-artifacts/architecture-agent-desired-state.md#Implementation Patterns — contrat handler ; #Anti-patterns] — idempotence, isolation, mode default, ordre serveur, anti-couteau-suisse
- [Source: docs/agent/contract-v1.md §3.1, §3.2, §4.1, §5, §6, §8 — FIGÉ] — semantics, payload ownership, NFC/pas de float, mode default verbatim, rapport, tableau vide ≠ type absent
- [Source: docs/agent/report-endpoint.md] — validation 422 (types uniques, hex-64, detail), comparaison (status, hash) au précédent
- [Source: docs/agent/state-providers.md#Payloads v1 ; #Hors scope] — payloads wallpaper/overlay, défers explicites vers 24.4
- [Source: docs/agent/session-companion.md §5, §7, §10] — partition, quarantaine, « ce que 24.4 branchera dessus »
- [Source: app/Services/Agent/Providers/{Wallpaper,Overlay}StateProvider.php] — constantes scope/mode/semantics, commentaires de défer 24.4
- [Source: app/Models/WallpaperAsset.php#libraryPath, #getAbsolutePathAttribute] — serving + défense traversal (review F7)
- [Source: resources/overlay/README.md] — contrat render (identity.fullname, machine.name, machine.room, alerts[]), caveat regex Rainmeter, modèle facade
- [Source: agent/windows/SessionCompanion.ps1 ; agent/windows/SambaEduAgent.ps1 ; agent/shared/ContractV1.ps1] — fonctions 24.2/24.3 à réutiliser
- [Source: routes/api.php — bloc agent desired-state] — « futurs endpoints… à la FIN du bloc » ; [mémoire projet] fenêtre 1500 chars ScriptsOsNamespaceTest, route:cache VM

## Dev Agent Record

### Agent Model Used

claude-fable-5 (workflow dev-story, branche main)

### Debug Log References

- Premier run /vm `--filter Agent` : 39 failed (404 généralisés, y compris sur `POST /report` préexistant) — cause PUREMENT environnementale : `bootstrap/cache/routes-v7.php` périmé (du 11 juin) chargé par les tests pendant que `routes/api.php` venait d'être synchronisé. Résolu par `php artisan route:cache && php artisan config:cache && chown -R www-admin: bootstrap/cache` (piège n° 8 / mémoire projet « cache routes VM ») → run suivant 206 passed, AUCUNE régression réelle.
- Smoke navigateur-équivalent : `curl` VM sans token sur `/api/v1/agent/assets/wallpaper/<64a>.jpg` → 401 `AGENT_TOKEN_MISSING` (middleware en place sur le vrai serveur HTTP, route:cache fait).

### Completion Notes List

- **Tests /vm `--filter Agent` : baseline 189 passed (745 assertions) → 206 passed (839 assertions)** — +17 tests (4 unit provider identity, 9 feature AssetEndpointTest, 4 feature HandlersE2eTest), zéro régression. Suite complète JAMAIS lancée (décision Henri). Opérations VM faites : `route:cache` + `config:cache` + chown www-admin + smoke 401.
- **Fichiers FIGÉS : zéro édit** — `contract-v1.md`, goldens `tests/Fixtures/Agent/*.v1.json`, hash 6c0e8135, `enrollment.md`, `StateController.php`, `ReportController.php`, `AuthenticateAgentToken.php`, `ReportIngestService`. Le rapport reste sans dimension user.
- **Décisions prises en cours de dev** (dans le cadre des décisions de story, à challenger en review) :
  1. **Sérialiseur overlay.json à structure fixe** (pas `ConvertTo-Json`) : PS 5.1 émet `":  "` (double espace) et échappe l'Unicode en `\uXXXX` — les deux cassent la regex WebParser de la skin (piège n° 15, et le POC avait ce bug latent : le serveur émet du JSON compact `":"` que la regex `": "` ne matchait pas). Le handler écrit un JSON déterministe à espace simple, Unicode brut UTF-8, ordre de clés littéral — « render inchangé » tenu au sens fort (seule la variable `JsonPath` de la skin a bougé).
  2. **Pas de champ volatil dans overlay.json** (pas de `generated_at`) : le `test` est une comparaison de contenu — un horodatage ferait dériver chaque passe.
  3. **`-OutFile` ajouté à `Invoke-AgentHttp`/`WithGrace`** : le StreamReader UTF-8 existant corromprait un download binaire — extension de la couche HTTP unique (grace/rotation D5 inchangées) plutôt qu'un second client HTTP.
  4. **`Sync-WallpaperAssets` appelé fin de `Invoke-SessionStateFetch` ET dans `Invoke-AgentCycle`** (la story demandait les deux branchements) : le double passage éventuel dans un même cycle est un no-op (content-addressed, fichier présent = jamais re-téléchargé) ; l'appel cycle couvre le pré-téléchargement AVANT le premier logon (zéro session).
  5. **Hash de drop : ordre types asc dans le rapport** (déterminisme/diff history) — le serveur n'impose pas d'ordre.
  6. **Engine : mode inconnu traité en `strict`** (posture sûre, contrat futur) + type exclusive multi-items = le DERNIER fait foi (§3.1) avec warning.
  7. **Compagnon sans cache au démarrage : reste résident** (au lieu de sortir) — le cycle service peut écrire le cache mid-session, le compagnon converge dès qu'il apparaît. Sortie silencieuse iso 24.3 préservée (aucun message visible).
  8. **AssetController : 404 indistinct** (malformé / inconnu / fichier absent) — pas d'oracle de présence ; logs `agent.asset.served`/`agent.asset.not_found`.
- **Validation PowerShell = revue statique** (décision projet reconduite — pas de tests PS) : StrictMode partout, `@()` anti-déroulement, `PSObject.Properties` avant tout accès, tmp suffixé `$PID` + `Move-Item` partout, SIDs jamais de noms localisés, ACL posées à la création (jamais de ré-ACL des tmp), `[bool]` cast unique sur la sortie des `Test`.
- **RESTE — ACTIONS HUMAINES (T12, enchaînable avec T9 24.2 + T8 24.3)** :
  - **PRÉREQUIS Rainmeter manuel** (décision Henri 2026-06-12) : installer Rainmeter sur ws 49 (`Rainmeter-x.y.z.exe /S /AUTOSTARTUP=1 /DESKTOPSHORTCUT=0`) + déployer la skin `resources/overlay/rainmeter/SambaEduOverlay/` (pointe déjà sur le fichier per-user). Sans lui, le volet rendu visuel de la démo manque (le reste de 4.2-4.6 passe — comportement gracieux).
  - **Démo palier 1 sur windoobe (ws 49)** : runbook `docs/qa/domains/agent.md` §4 (scénarios 4.1-4.6) — wallpaper UI → fond du poste → `compliant` en base ; fond changé à la main → `drifted_allowed` ; signal posté → visible dans l'overlay avec identité + salle. Résultats à tracer ici.
- **Limitations MVP documentées** (`handlers-wallpaper-overlay.md` §8) : latence ≤ 1 cycle (forcer la synchro → 24.5), fusion multi-session = plus récent gagne, quarantaine = convergence sur dernier cache, drop forgeable (impact borné au poste), pas de purge assets/drops, livraison Rainmeter hors-scope (workflow d'install des postes).

### File List

**Créés :**
- app/Http/Controllers/Api/V1/Agent/AssetController.php
- agent/shared/ConvergenceEngine.ps1
- agent/windows/handlers/Wallpaper.ps1
- agent/windows/handlers/Overlay.ps1
- tests/Feature/Api/V1/Agent/AssetEndpointTest.php
- tests/Feature/Api/V1/Agent/HandlersE2eTest.php
- docs/agent/handlers-wallpaper-overlay.md

**Modifiés :**
- routes/api.php (import aliasé + route `agent.v1.assets.wallpaper` à la FIN du bloc agent)
- app/Services/Agent/Providers/OverlayStateProvider.php (candidat synthétique `identity`)
- agent/windows/SambaEduAgent.ps1 (chemins 24.4, `-OutFile` couche HTTP, `Initialize-AssetsDir`, `Initialize-SessionReportDir`, `Get-WantedWallpaperAssets`, `Sync-WallpaperAssets`, `Read-SessionReports`, câblage cycle/fetch, rapport avec items réels)
- agent/windows/SessionCompanion.ps1 (réécriture : dot-source engine+handlers, applied-state per-user, boucle résidente, drop per-SID)
- agent/windows/Install-SambaEduAgent.ps1 (copie modules/handlers, répertoires assets+reports, ExecutionTimeLimit compagnon illimité motivé)
- agent/windows/Uninstall-SambaEduAgent.ps1 (en-tête : périmètre 24.4)
- agent/build/Build-Agent.ps1 (3 nouveaux .ps1 dans le bundle signé)
- resources/overlay/rainmeter/SambaEduOverlay/SambaEduOverlay.ini (JsonPath per-user — seul changement, regex/meters intacts)
- resources/overlay/README.md (fetch Windows déprécié, table des chemins)
- agent/README.md (arborescence handlers, table des chemins 24.4, section handlers, NFC appliqué)
- docs/agent/state-providers.md (item identity, décision « pas de champ url », défers soldés)
- tests/Unit/Services/Agent/OverlayStateProviderTest.php (4 tests identity + helper signalCandidates)
- tests/Unit/Services/Agent/StateCompilerTest.php (union overlay = 4 items avec identity en tête)
- docs/qa/domains/agent.md (§4 append-only + en-têtes + checklist)
- docs/qa/README.md (ligne domaine agent : stories 24.1 → 24.4)
- _bmad-output/implementation-artifacts/sprint-status.yaml (statuts)

**Modifiés en corrections post-review :**
- agent/windows/SambaEduAgent.ps1 (#1 — UUID CIM sous try/catch)
- agent/shared/ConvergenceEngine.ps1 (#4 — dispatch Test durci)
- app/Services/Agent/TargetContext.php (#3 — ids() triés)
- app/Services/Overlay/OverlayService.php (#2 — KIND_RESERVED_IDENTITY + reclassement postSignal)
- app/Services/Agent/Providers/OverlayStateProvider.php (#2 — référence la constante)
- tests/Feature/Api/V1/Config/OverlayApiV1Test.php (#2 — test reclassement)

## Recommandation Modèle Dev

**fable.** Décision projet existante (stories agent desired-state = fable), confirmée par l'analyse : c'est la story la plus transversale de l'epic — elle traverse QUATRE frontières simultanément (serveur Laravel : route assets + enrichissement provider sans casser le déterminisme ETag ; SYSTEM : download/checksum/ACL ; user : handlers + applied-state ; remontée : drop validé à travers la frontière de confiance). Elle implémente la machine d'états du mode `default` (le « sabotage le plus dangereux » du contrat §5, où une approximation produit soit des wallpapers réappliqués en boucle, soit des dérives jamais corrigées), invente une convention de hash d'agrégat compatible avec un contrat figé qu'on n'a pas le droit de toucher, et chaque erreur d'ACL est soit un trou de sécurité, soit un agent muet. La validation PowerShell étant par revue statique uniquement (pas de tests PS), la qualité du raisonnement du dev EST le filet. `opus` reste le bon choix pour 24.5 (UI conformité Livewire).

## Change Log

- 2026-06-12 — Story REVIEWÉE (review adversariale opus → évaluation directe des findings par l'orchestrateur fable) : approuvé avec corrections, 8/8 AC conformes, 5 findings (1 🟠, 4 🟡). Corrigés (4) : #1 pertinence 3 UUID SMBIOS sous try/catch (panne WMI ne musèle plus le POST /report), #3 tri `TargetContext::ids()` (room/ETag déterministes même si invariant 1-salle violé), #4 dispatch moteur durci `(@(...))[-1]` (futurs handlers pollueurs de pipeline), #2 arbitrage Henri : `kind='identity'` réservé — reclassement `identity`→`notice` dans `OverlayService::postSignal()` + constante `KIND_RESERVED_IDENTITY` + test (l'UI force déjà `notice` ; exposition = erreur dev future). Ignoré (1) : #5 TOCTOU AssetController (théorique, bibliothèque immuable). Tests post-corrections : `--filter Agent` 206 passed (839 assertions) + `--filter OverlayApiV1` 18 passed, zéro régression. Doc review `_bmad-output/codeReviews/24-4.md` to-validate. Status `review` MAINTENU jusqu'à T12 (démo lab palier 1).
- 2026-06-12 — Story DÉVELOPPÉE par DEV claude-fable-5 (workflow dev-story, branche main) : T1-T11 livrés (T12 = action humaine restante, cf. Completion Notes). Serveur : route assets `agent.v1.assets.wallpaper` + AssetController mince + enrichissement `identity` d'OverlayStateProvider (déterminisme ETag prouvé par test). Agent : moteur de convergence générique shared/ (mode default §5 verbatim, isolation par item, ordre serveur, empreinte d'agrégat), handlers Wallpaper/Overlay (compagnon, scope session), Sync-WallpaperAssets SYSTEM (SHA-256 vérifié, ACL Users:R), drop per-SID `<SID>:M` + Read-SessionReports (validation stricte) → rapport avec items réels, compagnon résident (ExecutionTimeLimit illimité motivé), overlay.json per-user à sérialiseur fixe (regex Rainmeter préservée — bug latent POC double-espace/Unicode évité), skin pointée per-user, install/uninstall/build étendus. Docs : handlers-wallpaper-overlay.md (NEUF) + README/state-providers/overlay annotés ; QA §4 append-only (démo palier 1, prérequis Rainmeter manuel). Tests /vm `--filter Agent` : 189 → 206 passed (839 assertions), zéro régression ; contrat v1/goldens/controllers figés intouchés. Status → review.
- 2026-06-12 — Amendement validation Henri : Rainmeter n'est pas installé d'office sur les postes → (1) AC2 enrichi d'un comportement gracieux en son absence (overlay.json convergé quand même + log info, jamais `error`) ; (2) T12 prérequis explicite install manuelle temporaire sur ws 49 (NSIS silencieux + skin) ; (3) hors-scope : livraison automatisée à intégrer au workflow d'install des postes (piste : l'agent en première opération) — story future.
- 2026-06-12 — Story 24.4 créée (SM/orchestrateur) : handlers wallpaper + overlay côté compagnon (scope session — le nœud : ni réseau ni token côté user → assets pré-téléchargés SYSTEM via route serveur NEUVE `agent.v1.assets.wallpaper`, applied-state per-user, drop de résultats per-SID collecté/validé par le service pour le rapport — contrat v1 INTOUCHÉ) ; moteur de convergence générique shared/ (mode default §5 verbatim, isolation par item, ordre serveur) ; overlay.json per-user devient le fetch du POC (item identity serveur pour « identité user + parc », render inchangé) ; compagnon résident (réexécution mid-session). Solde les défers explicites de 23.4 (route assets, composition overlay) et de 24.3 (remontée rapport, handlers). Dépend de 24.2/24.3 (review — précédent projet : dev sur review accepté, rebase si correctifs). Status (création) → ready-for-dev.

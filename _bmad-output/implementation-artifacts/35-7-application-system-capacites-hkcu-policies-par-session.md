# Story 35.7 : Application SYSTEM des capacités `HKCU\…\Policies\*` par session (correct-course)

Status: ready-for-dev

<!-- Source d'autorité : _bmad-output/planning-artifacts/epics-capacites-v2.md (Epic 35, DONE — story de correct-course sur défaut runtime confirmé). Décision de cadrage validée par Henri le 2026-07-13 (option 1 : ciblage par-utilisateur conservé). -->

## Story

En tant que **référent numérique**,
je veux **que les capacités Session écrivant sous `HKCU\…\Policies\*` (blocage d'exécutables, regedit) s'appliquent réellement sur les postes joints au domaine**,
afin **que le ciblage par groupe d'utilisateurs (élèves) fonctionne — le service SYSTEM écrit la ruche de LA session ciblée à la place du compagnon, qui échoue en « Accès refusé »**.

## Contexte & intention

**Défaut confirmé en runtime** : la capacité `blocked_executables` (Story 35.2, cible = override UserGroup élèves via 35.4) échoue au poste avec « Accès refusé » sur ses DEUX projections :

- `registry` : `HKCU\Software\Microsoft\Windows\CurrentVersion\Policies\Explorer`, name `DisallowRun = 1` ;
- `registry_list` : `HKCU\…\Policies\Explorer\DisallowRun\1..5` (powershell, powershell_ise, pwsh, mstsc, cmd).

**Cause racine** : le compagnon de session tourne en contexte USER, et sur machine **jointe au domaine**, TOUT le sous-arbre `HKCU\…\Policies\*` — y compris `CurrentVersion\Policies`, pas seulement `Software\Policies` comme le planning 35.3 le supposait — est en **lecture seule pour l'utilisateur standard** (durcissement ACL anti-contournement de GPO). Même classe de bug que `hide_drives` et `windows_copilot_off`, déjà rustinés par déplacement HKLM (migrations `2026_07_06_100000`, `2026_06_19_100000` — la seconde documente déjà la leçon : « tout `…\Policies` sous HKCU »). Le seed `2026_07_03_110000` (et le seed `2026_07_02_100000` pour `registry_editing_disabled`) portent un commentaire **FAUX** affirmant que le tree `CurrentVersion\Policies` est « user-writable » — à corriger.

**Décision de cadrage (validée par Henri — option 1)** : le ciblage reste **par utilisateur/élève**. `blocked_executables` est assignée à un **UserGroup** (35.4) : elle n'existe QUE dans le contrat de la session élève. Un déplacement HKLM machine-wide (rustine hide_drives/copilot) bloquerait aussi profs/techniciens sur le même poste ET ne colle pas au modèle d'affectation. **Correctif retenu** : le **service SYSTEM applique le sous-ensemble « Policies » du contrat PAR-SESSION dans `HKU\<SID de cette session>`**, comme le fait une GPO user policy ; le **compagnon cesse** de tenter ces écritures. Effet au **logon suivant** (Explorer lit `DisallowRun` au logon — comportement attendu d'une policy user, AC7).

**Distinction structurante 35.3 vs 35.7** (à ne jamais confondre) :

| | 35.3 (`hive: HKU`, livré) | 35.7 (cette story) |
|---|---|---|
| Portée | machine (state SANS `?user`) | session (state PAR-SESSION `?user=`) |
| Ciblage | AUCUN ciblage user (structurel) | overrides UserGroup/User ATTEIGNENT l'item |
| Application | fan-out `.DEFAULT` + TOUTES les ruches chargées | UN SID : la ruche de LA session ciblée |
| Marqueur | valeur de `hive` | champ additif `writer: "system"` (hive reste `HKCU`) |

**Ce que la story livre** :

1. **Contrat** : champ additif optionnel `writer` (§7.1 + §7.6) sur les payloads `registry`/`registry_list` — enum fermé, seule valeur publiée `"system"`. Golden + hashes jumeaux PHP↔Go mis à jour.
2. **Serveur** : la clé de `spec` porte l'attribut `'writer' => 'system'` ; les providers Session le recopient sur l'item émis ; le guard le borne (HKCU uniquement).
3. **Compagnon** : SKIPPE tout item marqué (plus d'écriture user-context, plus d'« Accès refusé »).
4. **Service SYSTEM** : nouvelle passe par-session — pour chaque session interactive énumérée, applique les items marqués du cache `cache\sessions\<SID>\state.json` dans `HKU\<SID>` (application ciblée UN-SID via décorateur d'ops, distincte du fan-out 35.3).
5. **Lot** : retrofit re-routant `blocked_executables` (2 projections) + `registry_editing_disabled` vers le marqueur ; correction des commentaires faux des seeds ; audit du catalogue cité (AC5).

## ⚠️ Pièges & tensions (lire AVANT de coder)

1. **Piège #1 — binaire antérieur : le marqueur est ignoré EN SILENCE (pas de casse, mais pas de correctif).** `parseRegistrySpec` ignore les champs inconnus (§9) : un compagnon ≤ 2.11.0 recevant un item `writer: "system"` continue EXACTEMENT comme aujourd'hui (tentative HKCU → « Accès refusé » → error du type au drop = le statu quo du défaut) ; un service ≤ 2.11.0 n'a pas la passe par-session → item non appliqué, sans erreur. Contrairement au piège 35.3 (item HKU = type machine entier en error), AUCUNE régression flotte — mais le correctif n'est effectif qu'à la publication. Ordre opérateur : **publier 2.12.0 AVANT `php artisan migrate` sur /vm** (l'inverse laisse le défaut visible en croyant l'avoir corrigé).

2. **Piège #2 — marqueur EXPLICITE, jamais de path-sniffing.** Le routage compagnon/SYSTEM se décide sur le champ `writer` du payload, JAMAIS sur une regex `\…\Policies\…` (fragile : faux positifs sur des trees applicatifs contenant « Policies », faux négatifs sur d'autres trees durcis, et le serveur perdrait l'autorité d'authoring). Le serveur DÉCLARE, l'agent ROUTE.

3. **Piège #3 — ne PAS réutiliser le fan-out 35.3.** Les items marqués restent `hive: "HKCU"` (cible logique = la ruche de l'utilisateur de la session — c'est VRAI). Ne pas émettre `hive: HKU` (sémantique 35.3 = toutes les ruches, portée machine, pas de ciblage user — la forker par portée serait un piège sémantique) ; ne pas passer par `isUsersHive`/`fanOutUserHives`/`UserHives`. La traduction HKCU→`HKU\<SID>` est un **décorateur d'ops** par session, invisible des handlers.

4. **Piège #4 — le décorateur hérite GRATUITEMENT de la sonde race-logoff (review 35.3 #1).** En traduisant vers `{hive: "HKU", path: "<SID>\<path>"}`, l'op `Write` Windows sonde déjà la racine de fan-out avant d'écrire (ruche démontée = no-op nil, jamais de clé orpheline matérialisée sous HKEY_USERS). Ne PAS ré-implémenter la garde ; un test doit prouver qu'une session déloguée entre l'énumération et l'écriture ne matérialise rien.

5. **Piège #5 — partition symétrique, engine INTOUCHÉ.** Le compagnon applique aujourd'hui `session + machine_user` ; la nouvelle règle : compagnon = ces portées MOINS les items porteurs de `writer` ; passe SYSTEM par-session = ces portées, items `writer == "system"` SEULEMENT. Le filtre vit AVANT le moteur (inspection du payload brut `StateItem.Payload`, générique tous types) — `engine.go` et `parseRegistrySpec` restent byte-identiques. Valeur `writer` inconnue (future) = skippée par LES DEUX acteurs sans erreur (iso « type inconnu ignoré » §8, forward-compat) ; le compagnon skippe sur PRÉSENCE du champ, la passe SYSTEM sélectionne sur ÉGALITÉ stricte `"system"`.

6. **Piège #6 — `refresh` et `writer` sont mutuellement exclusifs.** Le §7.1 (43.2) est formel : `refresh` n'est émis QUE sur les items appliqués par le compagnon. Or le retrofit `2026_07_11_100000` a posé `policy_broadcast` sur les 3 projections re-routées ici (`blocked_executables` ×2, `registry_editing_disabled`). Double correction : (a) le foyer `withRefreshHint()` ne pose JAMAIS `refresh` sur un item `writer: system` (garde structurelle) ; (b) le retrofit 35.7 RETIRE le hint des 3 projections (donnée cohérente, `down()` le repose). L'effet devient « logon suivant » — c'est le comportement ATTENDU d'une policy user (AC7), pas une régression à compenser (pas de broadcast SYSTEM→session, sur-conception).

7. **Piège #7 — golden : cette fois ils BOUGENT (contrairement à 35.3).** `writer` est un CHAMP nouveau (pas une valeur de domaine) : la forme du wire change → `tests/Fixtures/Agent/state.v1.json` gagne le champ sur des items de portée session (les DEUX types, registry + registry_list — de préférence en MODIFIANT des items existants pour ne pas déplacer les comptages de `loop_test.go`/`contract_test.go`), et les hashes figés jumeaux sont RECALCULÉS À L'IDENTIQUE des deux côtés : `ContractV1Test::FROZEN_STATE_HASH` (PHP) ↔ `frozenStateHash` (`agent/shared/hasher_test.go`, Go) — avec justification écrite (règle 23.1). `report.v1.json` INCHANGÉ (le rapport ne porte pas `writer`). Attention piège #6 : ne pas laisser `refresh` sur l'item golden qui gagne `writer`.

8. **Piège #8 — applied-state par-session, jamais celui du compagnon ni celui de la machine.** La passe SYSTEM a besoin de son propre dernier-appliqué PAR SID (sinon chaque cycle serait un « premier passage §5 » et le drift ne serait jamais rapporté) : `cache\sessions\<SID>\applied-state.json` (écrit par SYSTEM, ACL héritée `<SID>:R` — lecture user inoffensive : hashes/timestamps opaques, iso justification applied-state machine). Ne toucher NI à l'applied-state machine (`Store.AppliedStatePath()`) NI au per-user du compagnon (`%LOCALAPPDATA%`). Côté compagnon, les items disparus de sa cible (désormais skippés) relèvent du régime « ne pas gérer » (§8) — aucune purge à coder.

9. **Piège #9 — rapport : réutiliser la fusion par type, un seul canal.** Les verdicts de la passe par-session (types `registry`/`registry_list`) rejoignent `a.machineReportItems` du cycle service : `MergeReportItemsByType` (déjà en place, pire statut gagne) fusionne avec le verdict machine ET les drops compagnon — l'unicité des types §6 est préservée sans rien inventer. La tâche at-logon (`agent.exe session-fetch`, processus SYSTEM éphémère) CONVERGE mais ne rapporte pas (pas de canal POST — le cycle service re-testera, level-triggered).

10. **Piège #10 — capacités déjà rustinées : NE PAS TOUCHER.** `hide_drives` et `windows_copilot_off` sont en HKLM = machine-scope CORRECT pour leur usage (diffusion parc). Ne pas les « rapatrier » vers `writer: system` (hors périmètre, décision de cadrage). De même `numlock_on_logon` (HKU 35.3 + HKCU Control Panel, tree user-writable) et `photo_viewer_restored` (`Software\Classes`, user-writable) ne sont PAS candidates.

11. **Piège #11 — version courante = 2.11.0 (epic 43).** Bump **2.11.0 → 2.12.0** avec entrée changelog (style 2.10.0/2.11.0). Si un correctif intermédiaire a bougé la version d'ici le dev, ajuster le point de départ (iso piège #13 de 35.3) — le bump MINEUR reste dû (`agent/**` touché).

12. **Piège #12 — l'énumération des sessions et le cache par-SID EXISTENT : réutiliser, pas refaire.** `(SID, login)` : `agent/windows/sessions_windows.go` (liste blanche `S-1-5-21-`, login non vide, dédoublonnage) injecté via `Agent.Sessions` ; le contrat par-session est déjà fetché+caché par `fetchSessionStates` (`agent/shared/sessionfetch.go`) dans `cache\sessions\<SID>\state.json` (paths : `agent/shared/sessionstore.go`). La passe 35.7 se GREFFE après ce fetch (cycle service) et dans `RunSessionFetch` (tâche at-logon — « un seul code pour les deux déclencheurs », décision 24.3 n° 4). Fetch en échec/offline : appliquer sur le DERNIER cache existant (level-triggered, iso compagnon).

## Décisions de design (tranchées — cadrage Henri + exploration code)

1. **D1 — Le marqueur est le champ additif optionnel `writer`, par CLÉ.**
   - **Wire (contrat)** : `"writer": "system"` sur les payloads `registry` et `registry_list`, portées session/machine_user uniquement. Enum FERMÉ, seule valeur publiée : `"system"`. Champ absent = exécutant par défaut de la portée (compagnon). Jamais émis sur un item machine/HKU (le service y est déjà l'exécutant). Formes : écriture `{hive, path, name, type, value, writer}` ; suppression `{hive, path, name, ensure: "absent", writer}` ; liste `{hive, path, entry_type, values, writer}`. `refresh` et `writer` mutuellement exclusifs (piège #6).
   - **Pourquoi un champ et pas une valeur de `hive`** : HKU (35.3) a une sémantique FIGÉE machine/fan-out — la forker par portée serait ambigu ; un champ additif est le chemin D1 canonique (binaire antérieur : champ inconnu ignoré, comportement inchangé) ; et l'item reste honnête (`hive: HKCU` = la ruche de l'utilisateur ciblé). Iso-patron des champs `ensure` (35.1) et `refresh` (43.2) du §7.1.
   - **Granularité clé, pas projection** (contrairement à `refresh`) : le besoin d'un exécutant SYSTEM est une propriété de la CLÉ (l'ACL de son tree), pas de la passe — une projection future peut légitimement mêler clés Policies et clés user-writable.
2. **D2 — Serveur : attribut de spec recopié, `StateCompiler`/`exclusiveKey()` INTOUCHÉS.** La clé de `spec` porte `'writer' => 'system'` (littéral en migration — les migrations ne référencent pas le code applicatif, décision 35.1) ; `expand()` des providers registry/registry_list le recopie tel quel sur l'item émis. L'identité `{hive|path|name}` (ou `{hive|path}` list) est INCHANGÉE : la précédence de compilation existante arbitre normalement, `writer` voyage avec la clé gagnante. Constante de domaine (ex. `CapabilityProjection::WRITER_SYSTEM = 'system'`) pour le code serveur.
3. **D3 — Guard** : `CapabilitySpecCollisionGuard` borne `writer` — valeur ∈ {`system`} (autre valeur = violation nommée), admis sur les mécanismes `registry` et `registry_list`, et UNIQUEMENT sur une clé `hive: HKCU` (`writer` sur HKLM/HKU = violation nommée : l'exécutant y est déjà SYSTEM). Docblock : sémantique + exclusion mutuelle refresh/writer.
4. **D4 — Routage agent = partition AVANT moteur** (piège #5) : helper shared (ex. `SplitSystemWriterItems(items) (companion, system []StateItem)`) sur le payload brut — compagnon jette les items porteurs de `writer` (log debug avec compte), passe SYSTEM sélectionne `writer == "system"`. `engine.go`, `parseRegistrySpec`, `parseRegistryListSpec` : byte-identiques (le champ inconnu y est déjà ignoré).
5. **D5 — Application ciblée UN-SID = décorateur d'ops** : type shared pur (ex. `sessionHiveOps{ops RegistryOps, sid string}`) qui traduit `hive: HKCU` → `(hive: "HKU", path: "<SID>\<path>")` sur `Read/Write/Delete/ValueNames` ; `UserHives()` → erreur franche (« jamais appelé en contexte par-session » — les items sont HKCU par construction). Les handlers `RegistryHandler`/`RegistryListHandler` sont réutilisés TELS QUELS (zéro diff) ; la sonde race-logoff de `Write` Windows joue gratuitement (piège #4). Le rafraîchissement shell n'accumule rien : `isUserHive` gate sur le spec HKCU… — vérifier et NEUTRALISER si besoin (la passe SYSTEM ne consomme jamais `TakeRefreshRequest`, iso MachineEngine — aucun geste depuis la session 0).
6. **D6 — Passe par-session côté service** : nouveau fichier shared (ex. `agent/shared/sessionapply.go`) — pour chaque session de la DERNIÈRE énumération (celle de `fetchSessionStates`, pas de second appel WTS) : lire `cache\sessions\<SID>\state.json`, partition D4 sur `session + machine_user`, moteur par-session (handlers registry+registry_list sur ops décorées D5), applied-state per-SID (piège #8), verdicts → `machineReportItems` (piège #9). Injection plateforme iso `MachineEngine` : l'`Agent` gagne un champ (ex. `SessionSystemOps RegistryOps` — les ops registre RÉELLES, décorées par SID côté shared) câblé par `main_windows.go` ; nil = passe INERTE (tests hôte via fake, console de debug). Isolation : une session en échec n'empêche ni les autres sessions ni le cycle (best-effort loggé, iso convergeMachine).
7. **D7 — Lot re-routé** : nouvelle migration `2026_07_13_100000_retrofit_session_system_writer_policies.php` — pose `'writer' => 'system'` sur les clés HKCU des 3 projections (`blocked_executables` registry + registry_list, `registry_editing_disabled` registry) ET retire leur hint `refresh` (piège #6). Idempotente, garde `hasTable`, `down()` = état antérieur exact (retire writer, repose `refresh: policy_broadcast`). Les seeds antérieurs ne changent que par leurs COMMENTAIRES (zéro donnée) : pattern iso `move_copilot`/`move_hide_drives` (le seed original reste, le retrofit transforme).
8. **D8 — Audit du catalogue (généralisation, AC5)** — capacités Session sous `HKCU\…\Policies\*` :
   - **Re-routées (cette story)** : `blocked_executables` (`…\CurrentVersion\Policies\Explorer` + conteneur `…\DisallowRun` — défaut CONFIRMÉ runtime) ; `registry_editing_disabled` (`…\CurrentVersion\Policies\System\DisableRegistryTools` — même tree durci, même commentaire faux « → OK companion », seed `2026_07_02_100000` l. ~260 ; cible override UserGroup élèves, défaut latent).
   - **Non candidates (justifié)** : `outlook_disable_o365_account_creation` (`Software\Microsoft\Office\…`, user-writable) ; `photo_viewer_restored` (`Software\Classes`, user-writable) ; `numlock_on_logon` (Control Panel + clé HKU 35.3) ; `hide_drives`/`windows_copilot_off` (déjà HKLM = machine-scope correct — piège #10, NE PAS TOUCHER).
   - Le mécanisme couvre TOUTE capacité Session future sous `HKCU\…\Policies\*` : poser `'writer' => 'system'` dans la spec = data, zéro release agent (invariant central conservé).

## Acceptance Criteria

### AC1 — Contrat : champ `writer` additif + golden jumeaux

**Given** le contrat `se5.desired-state/v1`
**When** un payload `registry` ou `registry_list` de portée session/machine_user porte le nouveau champ optionnel `writer: "system"`
**Then** l'absence du champ vaut « exécutant par défaut de la portée » (rétro-compatible, ajout mineur §9) ; seule la valeur `"system"` est publiée (enum fermé) ; le champ n'est JAMAIS émis sur un item machine/HKU ni combiné à `refresh` (exclusion mutuelle documentée)
**And** `docs/agent/contract-v1.md` §7.1 et §7.6 gagnent la ligne `writer` (sémantique : « appliqué par le service SYSTEM dans `HKU\<SID>` de la session du contexte — jamais par le compagnon ; nécessaire aux trees `HKCU\…\Policies\*`, lecture seule pour l'utilisateur standard sur poste joint au domaine »), §9 documente l'évolution (champ ajouté = mineur ; binaire ≤ 2.11.0 : marqueur ignoré EN SILENCE — compagnon garde l'échec actuel, service n'applique pas → publier AVANT d'armer)
**And** le golden `tests/Fixtures/Agent/state.v1.json` fige la forme `writer` sur les DEUX types (items de portée session, sans hint `refresh` concomitant) et les hashes figés jumeaux sont recalculés À L'IDENTIQUE : `ContractV1Test::FROZEN_STATE_HASH` (PHP) ↔ `frozenStateHash` (`agent/shared/hasher_test.go`, Go) — justification écrite au Dev Agent Record (règle 23.1) ; `report.v1.json` INCHANGÉ.

### AC2 — Serveur : émission + garde-fou d'authoring

**Given** une clé de `spec` portant `'writer' => 'system'` sur une projection `windows/registry` ou `windows/registry_list`
**When** les providers Session (`RegistryUserCapabilityProvider`, `RegistryListUserCapabilityProvider`) expansent la projection
**Then** l'item émis recopie `writer: "system"` (formes D1 exactes : 6 clés écriture / 5 clés `ensure: absent` / 5 clés liste — zéro fuite d'id de capacité, zéro float) ; une clé SANS l'attribut émet le payload historique byte-identique (5/4 clés — non-régression)
**And** le foyer `withRefreshHint()` ne pose JAMAIS `refresh` sur un item `writer: system` (même si la projection porte un hint résiduel — garde structurelle, piège #6)
**And** `exclusiveKey()` et `StateCompiler` sont INTOUCHÉS (un test de compilation prouve qu'un override UserGroup posant la clé marquée bat le défaut Broadcast, et que le marqueur voyage avec la clé gagnante dans le state par-session)
**And** `CapabilitySpecCollisionGuard` borne le champ : valeur ∈ {`system`}, mécanismes `registry`|`registry_list`, clés `hive: HKCU` UNIQUEMENT (writer sur HKLM/HKU = violation nommée) — invariant vert sur le catalogue réellement seedé APRÈS retrofit
**And** les providers restent Postgres purs (zéro AD/LdapRecord/APCu) ; providers Machine et fichiers non concernés byte-identiques (tests existants verts sans modification d'attendus).

### AC3 — Compagnon : skip des items marqués

**Given** un cache de session dont la portée session (ou machine_user) contient des items porteurs de `writer`
**When** le compagnon exécute `RunPass`
**Then** ces items sont ÉCARTÉS AVANT le moteur (partition D4 — `engine.go` intouché) : AUCUNE tentative d'écriture user-context, plus jamais d'« Accès refusé » sur ces clés, log debug avec compte des items délégués
**And** le skip est GÉNÉRIQUE (tout type porteur du champ, présence suffit — une valeur future inconnue est skippée sans erreur, forward-compat)
**And** les items NON marqués convergent exactement comme avant (byte-identité du chemin historique, applied-state per-user et drop inchangés) ; l'échelle de rafraîchissement 43.1 ne reçoit plus rien de ces items (ils ne passent plus par ses handlers).

### AC4 — Service SYSTEM : passe par-session ciblée UN-SID

**Given** le service SYSTEM avec des sessions interactives ouvertes (énumération WTS existante)
**When** le cycle de convergence passe (et la tâche at-logon `session-fetch`, même code — décision 24.3 n° 4)
**Then** pour CHAQUE session `(SID, login)` de la dernière énumération, les items `writer == "system"` des portées session/machine_user du cache `cache\sessions\<SID>\state.json` sont appliqués dans `HKU\<SID>` via le décorateur d'ops D5 (`HKCU` → `HKU`, path préfixé `<SID>\`) — handlers `registry`/`registry_list` réutilisés TELS QUELS, réconciliation de conteneur `DisallowRun\1..N` incluse (D3 de 35.2)
**And** l'application est CIBLÉE : uniquement la ruche du SID de la session dont provient le contrat — jamais `.DEFAULT`, jamais les autres ruches (test : 2 sessions aux contrats différents ⇒ chaque ruche ne reçoit QUE ses items ; distinction 35.3 prouvée)
**And** le dernier-appliqué est persisté PAR SID (`cache\sessions\<SID>\applied-state.json`, écriture atomique, piège #8) : policy STRICT démontrée À TRAVERS le moteur (drift re-imposé au cycle suivant, iso `TestRegistryAbsentThroughEngineStrictRedrift`)
**And** les verdicts rejoignent le rapport du cycle service via `machineReportItems` + `MergeReportItemsByType` (pire statut gagne, unicité des types §6 — la tâche at-logon converge sans rapporter, piège #9)
**And** l'isolation tient : session déloguée en course = no-op silencieux sans clé orpheline (sonde race-logoff héritée, piège #4) ; une session en échec n'empêche ni les autres sessions, ni la portée machine, ni le cycle (best-effort loggé) ; quarantaine = passe sautée (aucun traitement d'état) ; `SessionSystemOps` nil = passe inerte (tests hôte).

### AC5 — Lot : retrofit + commentaires corrigés + audit cité

**Given** la nouvelle migration `database/migrations/2026_07_13_100000_retrofit_session_system_writer_policies.php`
**When** elle est jouée
**Then** les clés HKCU des 3 projections re-routées (`blocked_executables` registry + registry_list, `registry_editing_disabled` registry) portent `'writer' => 'system'` ET perdent leur hint `refresh` (piège #6) ; le reste des specs est byte-identique
**And** la migration est IDEMPOTENTE (rejouable, garde `hasTable`, update ciblé par `key`) avec `down()` restaurant l'état antérieur exact (writer retiré, `refresh: policy_broadcast` reposé) — test idempotence/réversibilité iso pattern existant
**And** les commentaires FAUX sont corrigés (commentaires SEULS, zéro donnée) : seed `2026_07_03_110000` (bloc « cmd.exe ≈ DisableCMD » l. ~34-39 + `$userPoliciesExplorer` l. ~57 : « user-writable » → « lecture seule pour l'utilisateur standard sur poste joint au domaine — appliqué par SYSTEM via `writer: system`, Story 35.7 ») et seed `2026_07_02_100000` (l. ~75-76 et ~260, même correction)
**And** le commentaire de tête du retrofit consigne : la cause racine (tout `HKCU\…\CurrentVersion\Policies` durci domaine, pas seulement `Software\Policies`), l'audit D8 (candidates re-routées + non-candidates justifiées), l'effet « logon suivant », et le préalable de publication (piège #1)
**And** un test d'intégration provider sur données réelles prouve : `blocked_executables` effectif `on` ⇒ le provider Session émet le flag `DisallowRun` ET le conteneur `registry_list` TOUS DEUX marqués `writer: "system"` sans `refresh` ; effectif `off` ⇒ l'item `ensure: absent` ET la liste vide, marqués idem.

### AC6 — Version agent + note de publication

**Given** l'agent modifié
**Then** `agent/shared/version.go` est bumpé (**2.11.0 → 2.12.0**, piège #11) avec entrée de changelog (marqueur `writer`, skip compagnon, passe SYSTEM par-session `HKU\<SID>`, nuance déploiement)
**And** la note de fin de story impose l'ORDRE : release **2.12.0 publiée manuellement AVANT** la migration AC5 sur /vm — un binaire ≤ 2.11.0 ignore le marqueur EN SILENCE : pas de casse flotte (différence assumée avec 35.3), mais le défaut « Accès refusé » persiste côté compagnon et rien n'est appliqué côté service tant que la release n'est pas remontée (version rapportée au check-in fait foi ; update.sh ne publie jamais seul).

### AC7 — Effet au logon suivant : limite documentée + e2e lab manuel

**Given** la capacité `blocked_executables` armée `on` par override UserGroup élèves (geste 35.4) sur un poste à jour (≥ 2.12.0, migration jouée)
**Then** la documentation (contrat §7.1, runbook QA `docs/qa/domains/agent.md` section 35.7 append-only) énonce la limite ATTENDUE : les clés sont posées dans `HKU\<SID>` par SYSTEM, Explorer les lit AU LOGON → effet au **logon suivant** de la session ciblée (comportement d'une GPO user policy — aucun geste de rafraîchissement mid-session, piège #6)
**And** le scénario e2e lab MANUEL est consigné (action post-story, jamais exécutée par le dev) : session élève → au logon suivant `cmd.exe` refuse de se lancer (restriction Explorer) et le rapport remonte `compliant` ; session prof/technicien sur le MÊME poste → `cmd.exe` fonctionne (preuve du ciblage par-utilisateur — c'est le critère qui invalidait la rustine HKLM).

## Tasks / Subtasks

- [ ] **Task 1 — Contrat & golden (AC1)** *(commencer ici : fige la sémantique)*
  - [ ] 1.1 `docs/agent/contract-v1.md` : §7.1 ligne `writer` au tableau (+ exemple 6 clés) + note d'articulation avec « Portée → acteur » et l'exclusion `refresh` ; §7.6 idem pour `registry_list` ; §9 évolution 35.7 (champ ajouté = mineur, binaire antérieur = silence, publier avant d'armer).
  - [ ] 1.2 Golden : `state.v1.json` étendu (writer sur un item registry ET un item registry_list de portée session, sans `refresh` — modifier des items existants pour préserver les comptages) ; recalcul des hashes jumeaux `ContractV1Test::FROZEN_STATE_HASH` ↔ `hasher_test.go frozenStateHash` (et hashes d'items dépendants) ; `report.v1.json` intouché ; justification au Dev Agent Record.
- [ ] **Task 2 — Providers PHP + guard (AC2)**
  - [ ] 2.1 `app/Models/CapabilityProjection.php` : const `WRITER_SYSTEM = 'system'` (+ docblock sémantique).
  - [ ] 2.2 `AbstractCapabilityStateProvider.php` : recopie de l'attribut `writer` de la clé de spec sur l'item émis (chemins écriture ET `$ensure: absent`) ; garde `withRefreshHint()` (jamais de `refresh` sur item writer) ; docblock.
  - [ ] 2.3 `AbstractRegistryListCapabilityProvider.php` : recopie idem sur le payload conteneur.
  - [ ] 2.4 `CapabilitySpecCollisionGuard.php` : borné D3 (valeur, mécanismes, HKCU-only) + docblock (sémantique, exclusion refresh, leçon Policies domaine).
  - [ ] 2.5 Tests : `CapabilityRegistryProviderTest` (émission 6 clés / 5 clés absent / byte-identité sans attribut / jamais refresh+writer), `CapabilityRegistryListProviderTest` (conteneur marqué), `CapabilityRegistryCompilationTest` (précédence UserGroup > Broadcast avec marqueur voyageant), tests guard (writer invalide, writer sur HKLM/HKU refusés, catalogue seedé vert).
- [ ] **Task 3 — Agent Go : partition compagnon (AC3)**
  - [ ] 3.1 Helper shared de partition D4 (payload brut, générique) + branchement dans `companion.go RunPass` (avant `Engine.RunPass`, log debug) — `engine.go`/parsers intouchés.
  - [ ] 3.2 Tests compagnon : items marqués écartés (aucun op registre tenté), items non marqués inchangés, valeur writer inconnue skippée sans erreur, drop/applied-state per-user inchangés.
- [ ] **Task 4 — Agent Go : passe SYSTEM par-session (AC4)**
  - [ ] 4.1 Décorateur d'ops D5 (shared, pur) : traduction HKCU→`HKU\<SID>`, `UserHives` = erreur franche ; doc (sonde race-logoff héritée de l'impl Windows `Write`).
  - [ ] 4.2 `agent/shared/sessionapply.go` : passe par-session D6 (lecture cache per-SID, partition, moteur registry+registry_list sur ops décorées, applied-state per-SID via nouveau path `sessionstore.go`, verdicts → `machineReportItems`) ; branchement `loop.go` (après `fetchSessionStates`, hors quarantaine) + `sessionfetch.go RunSessionFetch` (converge sans rapporter) ; champ `Agent.SessionSystemOps` (nil = inerte) câblé dans `agent/windows/main_windows.go`.
  - [ ] 4.3 Tests (fake RegistryOps réutilisé, jamais dupliqué) : ciblage un-SID (2 sessions, contrats différents), STRICT re-drift à travers le moteur, `ensure:absent` + réconciliation `registry_list` dans la ruche ciblée, session déloguée = no-op sans orpheline, erreur isolée par session, quarantaine = passe sautée, ops nil = inerte, fusion des verdicts par type dans le rapport.
  - [ ] 4.4 `agent/shared/version.go` : bump **2.12.0** + changelog (piège #11).
- [ ] **Task 5 — Lot : retrofit + commentaires (AC5)**
  - [ ] 5.1 Migration `2026_07_13_100000_retrofit_session_system_writer_policies.php` (D7 : writer posé, refresh retiré, idempotente, down() exact, commentaire de tête = cause racine + audit D8 + ordre de publication).
  - [ ] 5.2 Corrections de COMMENTAIRES seuls dans `2026_07_03_110000` et `2026_07_02_100000` (AC5 — zéro donnée).
  - [ ] 5.3 `tests/Feature/Migrations/CapabilitiesSchemaAndSeedTest.php` : specs re-routées (writer présent, refresh absent), idempotence/réversibilité, intégration provider données réelles (AC5), invariant guard étendu.
- [ ] **Task 6 — Validation finale (AC6, AC7)**
  - [ ] 6.1 Tests HÔTE ciblés (php8.4 + sqlite, JAMAIS de run massif) : filtres de la section Testing.
  - [ ] 6.2 Tests Go (`~/go-toolchain/go/bin/go`, hors PATH) : `cd agent && go test ./...` ; `GOOS=windows go build ./...` ; `go vet ./...` (linux ET GOOS=windows).
  - [ ] 6.3 Docs : `docs/agent/state-providers.md` (note writer, section registry/registry_list) ; `docs/qa/domains/agent.md` section « Story 35.7 » append-only (scénarios : élève bloqué au logon suivant, prof OK même poste, binaire antérieur silencieux, session déloguée en course).
  - [ ] 6.4 Dev Agent Record : justification golden (piège #7), ⚠️ ORDRE OPÉRATEUR (publier 2.12.0 → attendre remontée version → `php artisan migrate` sur /vm — jamais auto-appliquée), consigne e2e lab manuel AC7.

## Dev Notes

### Fichiers à toucher (exhaustif prévu)

| Fichier | Nature |
|---|---|
| `docs/agent/contract-v1.md` | §7.1 + §7.6 ligne `writer` ; §9 évolution |
| `tests/Fixtures/Agent/state.v1.json` | champ writer figé (2 types, portée session) |
| `tests/Feature/Api/Agent/ContractV1Test.php` + `tests/Unit/Services/Agent/StateHasherTest.php` | FROZEN_STATE_HASH recalculé |
| `agent/shared/hasher_test.go` (+ `contract_test.go`/`loop_test.go` si comptages) | frozenStateHash jumeau recalculé |
| `app/Models/CapabilityProjection.php` | const `WRITER_SYSTEM` |
| `app/Services/Agent/Providers/AbstractCapabilityStateProvider.php` | recopie writer + garde withRefreshHint |
| `app/Services/Agent/Providers/AbstractRegistryListCapabilityProvider.php` | recopie writer (conteneur) |
| `app/Services/Agent/Providers/CapabilitySpecCollisionGuard.php` | borné writer (D3) |
| `tests/Unit/Services/Agent/CapabilityRegistry{Provider,ListProvider,Compilation}Test.php` | tests AC2 |
| `agent/shared/companion.go` (+ helper partition, ex. dans `engine.go`-adjacent ou fichier dédié) | skip items marqués (AC3) |
| `agent/shared/sessionapply.go` | NOUVEAU — passe SYSTEM par-session + décorateur d'ops (AC4) |
| `agent/shared/sessionstore.go` | path applied-state per-SID |
| `agent/shared/loop.go` + `agent/shared/sessionfetch.go` | branchement de la passe (cycle + at-logon) |
| `agent/windows/main_windows.go` | câblage `SessionSystemOps` |
| `agent/shared/version.go` | bump 2.11.0 → **2.12.0** + changelog |
| `agent/shared/companion_test.go` / `handler_registry_test.go`-adjacents / nouveau `sessionapply_test.go` | tests AC3/AC4 |
| `database/migrations/2026_07_13_100000_retrofit_session_system_writer_policies.php` | NOUVEAU — retrofit D7 |
| `database/migrations/2026_07_03_110000_…` + `2026_07_02_100000_…` | COMMENTAIRES seuls (AC5) |
| `tests/Feature/Migrations/CapabilitiesSchemaAndSeedTest.php` | tests retrofit + intégration + invariant guard |
| `docs/agent/state-providers.md`, `docs/qa/domains/agent.md` | doc + runbook QA 35.7 |

**NE PAS TOUCHER** : `app/Services/Agent/StateCompiler.php` (D2 epic) et `StateHasher.php`/`hasher.go` (le hash change par les DONNÉES golden, pas par l'algo) ; `agent/shared/engine.go` (partition AVANT moteur) ; `parseRegistrySpec`/`parseRegistryListSpec` (champ inconnu déjà ignoré — le routage ne vit PAS au parse) ; le fan-out 35.3 (`isUsersHive`/`fanOutUserHives`/`UserHives` impl Windows) ; `hide_drives`/`windows_copilot_off` et leurs migrations « move » (piège #10) ; `StateController`/`ReportRequest` (types inchangés, ingestion inchangée) ; wiring compagnon (`companion_windows.go`) hors partition ; `sprint-status.yaml`/`backlog.*` (orchestrateur). Attention aux fichiers du working tree en cours (story 43.4 : `companion.go`, `notice_windows.go`…) : se rebaser sur main À JOUR avant de coder.

### Patterns existants à imiter

- **Champ additif §7.1** : `ensure` (35.1) et `refresh` (43.2) — mêmes gestes (ligne de tableau contrat, golden étendu + hashes jumeaux, providers recopient, tests byte-identité sans le champ).
- **Injection plateforme nil-inerte** : `Agent.MachineEngine`/`Rainmeter`/`VerifyAuthenticode` (loop.go) — `SessionSystemOps` suit le même patron (nil = no-op, tests hôte).
- **Passe best-effort greffée au cycle** : `convergeMachine()` (loop.go) — lecture cache, ParseState, ItemsFromScope, RunPass, applied-state atomique, items drainés au rapport.
- **Réutilisation du fake** : `fakeRegistryOps` (handler_registry_test.go) étendu/réutilisé, JAMAIS dupliqué (il sert aussi registry_list).
- **Migration de retrofit** : `2026_07_11_100000_retrofit_capabilities_refresh_hints.php` (update ciblé par `(key, mechanism)`, spec JSON retouchée chirurgicalement, down() exact) — le plus proche parent : il touche EXACTEMENT les 3 projections visées ici.
- **Tests STRICT à travers le moteur** : `TestRegistryAbsentThroughEngineStrictRedrift` (35.1/35.3).

### Rappels transverses (garde-fous epic)

- Contrat **additif uniquement** (D1 epic) ; golden changés AVEC justification (règle 23.1) — hashes jumeaux PHP↔Go recalculés à l'identique.
- **Drift policy STRICT** (27.8) — verdict PAR TYPE ; **zéro float** ; **zéro AD/LdapRecord/APCu** dans les providers.
- Tests PHP sur l'**HÔTE** (php8.4 + sqlite), **filtres ciblés uniquement** (run massif = faux échecs) ; Go via `~/go-toolchain/go/bin/go`.
- Migration **à rejouer sur /vm** = SIGNALÉE, jamais exécutée par le dev — et APRÈS publication de la release (piège #1).
- Toute modif `agent/**` ⇒ bump `agent/shared/version.go` ; **update.sh ne publie jamais seul** ; le handler absent du binaire publié = réglage sans effet (mémoire projet).

### Project Structure Notes

- Serveur : tout vit sous `app/Services/Agent/Providers/` + une migration — aucun nouveau fichier PHP hors migration ; aucune UI (le marqueur est de la donnée de `spec`).
- Agent : la logique nouvelle vit dans `agent/shared/` (partition, décorateur, passe par-session — 100 % testable hôte) ; `agent/windows/` n'apporte que le câblage `SessionSystemOps` (~5 lignes).
- Convention arborescence/pages non concernée (zéro route, zéro vue).

### References

- [Source: _bmad-output/planning-artifacts/epics-capacites-v2.md#Story-35.3 (mur n° 3 — l'hypothèse « Software\Policies seulement » que cette story corrige) + #Décisions-structurantes D1/D2/D5 + #Garde-fous-d'epic] — autorité de cadrage
- [Source: _bmad-output/implementation-artifacts/35-3-ruche-hku-ecriture-system.md — fan-out machine HKU (à NE PAS réutiliser ici), sonde race-logoff (review 35.3 #1), discipline golden/publication] ; [35-2-type-registry-list-listes-indexees.md — réconciliation conteneur D3] ; [36-1-mecanisme-fs-acl.md — patron type additif + AudienceTokens/LookupSID côté LSA]
- [Source: agent/shared/companion.go (partition des portées, RunPass) + agent/shared/loop.go (convergeMachine, machineReportItems, MergeReportItemsByType, fetchSessionStates→activeSIDs) + agent/shared/sessionfetch.go (cache per-SID, décision « un seul code pour les deux déclencheurs ») + agent/shared/sessionstore.go (paths sessions) + agent/windows/sessions_windows.go (énumération WTS S-1-5-21-)]
- [Source: agent/shared/handler_registry.go (RegistryOps, applyTarget, isUserHive/isUsersHive — frontière 35.3/35.7) + agent/shared/handler_registry_list.go + agent/windows/handler_registry_windows.go (rootKey HKU, sonde Write ruche démontée)]
- [Source: app/Services/Agent/Providers/AbstractCapabilityStateProvider.php (expand/SPEC_ENSURE/withRefreshHint — foyers de recopie) + CapabilitySpecCollisionGuard.php + app/Services/Agent/StateContract.php]
- [Source: database/migrations/2026_07_03_110000_seed_capabilities_registry_list_lot.php (commentaires faux l. ~34-39 et ~57) + 2026_07_02_100000_seed_capabilities_gpo_cd95_lot.php (l. ~75-76 et ~260) + 2026_07_06_100000_move_hide_drives_capability_to_hklm.php (leçon « tout …\Policies sous HKCU ») + 2026_07_11_100000_retrofit_capabilities_refresh_hints.php (hints à retirer)]
- [Source: docs/agent/contract-v1.md §7.1 (lignes ensure/refresh — patron du champ additif), §7.6, §8, §9]
- Mémoires projet : `project_hkcu_policies_not_writable_by_companion`, `project_registry_apply_effect_next_logon`, `project_agent_handler_not_in_published_binary`, `project_drift_policy_strict_only`, `project_zero_prod_publish_is_test`, `feedback_agent_edit_bump_version`, `feedback_no_overengineered_choices`, `project_vm_migrations_not_auto_applied`, `project_vm_phpunit_bulk_run_false_failures`.

## Dépendances

- **En amont (toutes DONE, mergées main)** :
  - **35.1** (verbe `ensure`) : le chemin `off` de `blocked_executables` (`$ensure: absent`) doit fonctionner dans la ruche ciblée ;
  - **35.2** (`registry_list` + réconciliation conteneur) : la seconde projection re-routée ;
  - **35.3** (ruche HKU côté agent) : `rootKey()` accepte déjà HKU et `Write` porte la sonde race-logoff — le décorateur D5 s'appuie dessus sans le modifier ;
  - **35.4** (override UserGroup) : le geste d'armement qui rend le défaut visible — c'est LUI que l'e2e AC7 exerce ;
  - **36.1** (résolution SID LSA `AudienceTokens`/`windows.LookupSID`) : disponible si besoin — mais la passe 35.7 consomme le SID DÉJÀ résolu par l'énumération WTS (aucun lookup nouveau attendu).
- **Epic 43 (livré, agent 2.11.0)** : l'échelle `refresh` — interaction réglée par le piège #6 (exclusion mutuelle + retrofit).
- **En aval** : aucune story ne dépend de 35.7 ; le mécanisme ouvre la porte à toute capacité Session future sous `HKCU\…\Policies\*` (data-only).

## Testing

- **PHP hôte (php8.4 + sqlite, filtres CIBLÉS — jamais de run massif, faux échecs VM)** :
  - `php artisan test --filter=CapabilityRegistryProviderTest` (+ `CapabilityRegistryListProviderTest`, `CapabilityRegistryCompilationTest`, `CapabilityRegistryListCompilationTest`) — émission writer, byte-identité sans attribut, précédence ;
  - `php artisan test --filter=CapabilitiesSchemaAndSeedTest` — retrofit (idempotence/réversibilité), intégration provider données réelles, invariant guard ;
  - `php artisan test --filter="ContractV1Test|StateHasherTest"` — golden + hash figé PHP.
- **Go (`~/go-toolchain/go/bin/go`, hors PATH)** : `cd agent && go test ./...` (hash figé jumeau, partition compagnon, passe par-session, décorateur) ; `GOOS=windows go build ./...` ; `go vet ./...` (linux ET GOOS=windows).
- **Cross-language (NFR13)** : le test croisé des hashes jumeaux DOIT porter la nouvelle forme (un état golden avec `writer` hashé identiquement des deux côtés).
- **E2E lab (MANUEL, post-story — jamais par le dev)** : publier 2.12.0 → attendre la remontée de version au check-in → `php artisan migrate` sur /vm → armer `blocked_executables` (override UserGroup élèves) → session élève : au logon suivant `cmd.exe`/PowerShell/mstsc bloqués (restriction Explorer), rapport `compliant` ; session prof/technicien MÊME poste : `cmd.exe` OK (preuve du ciblage par-utilisateur) ; contrôler `HKU\<SID élève>\…\Policies\Explorer` (flag + entrées 1..5) et l'absence de toute clé dans les autres ruches.

## Recommandation Modèle Dev

**fable** — prescription de l'epic (« fable pour les stories touchant l'agent Go ») + mémoire `feedback_epic23_model_fable5`. Profil-type au carré : évolution de contrat cross-language avec golden hashes jumeaux PHP↔Go à recalculer à l'identique, sémantique de convergence sensible (routage bi-acteur, ciblage un-SID vs fan-out 35.3, races logoff, policy STRICT) et surface sécurité (écriture SYSTEM dans les ruches utilisateur, frontière de confiance compagnon NFR5) — exactement la classe de travail où opus/sonnet dérivent.

## Dev Agent Record

### Agent Model Used

### Debug Log References

### Completion Notes List

### File List

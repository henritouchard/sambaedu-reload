# Story 27.2 : Handlers lecteurs & imprimantes

Status: review

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

En tant qu'**admin d'établissement**,
je veux **que les lecteurs réseau et les imprimantes d'un poste suivent ses règles**,
afin que **« l'imprimante de la salle » soit un item d'état comme les autres (Vérité #9), géré par le bon modèle (un item d'état, pas une GPO ni un script de logon legacy)**.

## Contexte & intention

**Deuxième story de l'Epic 27 « Parité de compétences ».** Elle reconduit À L'IDENTIQUE le pattern
inauguré par 27.1, mais pour **deux types d'un coup** : `drives` (lecteurs réseau) et `printers`
(imprimantes). Pattern par type : **1 StateProvider serveur (lecture seule) + 1 handler agent Go +
identifiant de type figé (déjà gravé contrat §7) + golden file**.

**Ce que cette story livre :**
- **Serveur** : `DrivesStateProvider` et `PrintersStateProvider` (lecture seule des règles existantes),
  types figés `drives` et `printers` (contrat §7, NFR12 — DÉJÀ gravés, ne pas ré-inventer),
  `semantics=aggregate`, `scope=session`, payloads v1 ownés ici.
- **Agent Go** : deux handlers `agent/shared/handler_drives.go` + `handler_printers.go` (logique pure,
  testable hôte) et leurs spécifiques Windows (`agent/windows/handler_drives_windows.go` /
  `handler_printers_windows.go`), enregistrés dans le moteur du compagnon — convergence level-triggered
  (union, sans doublon, apply idempotent ; mapping/imprimante retiré des règles → démonté au passage suivant).
- **Sous-item `exclusive` (imprimante par défaut)** : un seul item « fait foi » pour le défaut, **la règle
  la plus spécifique gagne** (poste > salle physique > parc logique). Documenté **au schéma du payload**
  (drapeau `is_default`), PAS une nouvelle sémantique du type.
- **Isolation des erreurs** : serveur d'impression injoignable à l'`apply` → statut `error` + détail, les
  **autres items continuent**, retry au prochain cycle (machine d'états §5 existante).
- **Golden file** : mise à jour SCIEMMENT de `tests/Fixtures/Agent/state.v1.json` (+ `report.v1.json`)
  pour les payloads `drives` + `printers` réels, avec bump documenté du hash figé `ContractV1Test`
  (`FROZEN_STATE_HASH`) + son jumeau Go (`hasher_test.go`).

**Ce que cette story N'EST PAS :**
- Le décommissionnement du canal legacy lecteurs/imprimantes — il meurt **en bloc** en **27.6**. Ici,
  **ZÉRO retrofit legacy** : on construit À CÔTÉ, on ne câble RIEN au canal legacy.
- Une absorption de CUPS (`CupsPrinterService`) : ce service reste le pilote **serveur** de CUPS/Samba
  (créer/supprimer une imprimante côté SERVEUR). Le provider le **lit** (catalogue d'imprimantes + URI),
  l'agent **installe la connexion** côté POSTE. Deux étages distincts, on ne fusionne pas.
- La gestion des partages de classe (`ShareService`) : elle reste la source FS des dossiers de classe.
  Le provider `drives` **émet le montage** (lettre → UNC), il ne crée AUCUN partage.
- Le toggle UI strict/default : **27.1 l'a déjà introduit** (colonne `mode` + agrégation par type au
  compilateur). L'AC 27.2 **ne le réclame pas explicitement** pour drives/printers → **NE PAS sur-spécifier**.
  Voir décision n° 7 (le mode est un champ d'infra déjà en place : on le câble sobrement, on n'expose un
  toggle UI QUE si la table de règles le porte naturellement).

## ⚠️ Pièges & tensions découverts à l'analyse (lire AVANT de coder)

> Ces tensions sont issues de l'exploration du codebase. Les forks majeurs (modèle de données `drives`,
> ciblage des imprimantes par user, schéma de l'imprimante par défaut) **doivent être tranchés par Henri
> à la validation de story** — voir « Questions pour Henri ». Le dev ne re-tranche pas un fork arbitré.

### A) `printers` — domaine PROPRE, peu de surprises

1. **`printers` est assigné aux `WorkstationGroup` UNIQUEMENT, jamais aux `UserGroup`.** Le pivot
   `printer_workstation_group` (`cups_name`, `workstation_group_id`, `attached_at`, `attached_by_user_id`)
   lie une imprimante à des parcs/salles. **Pas de relation `UserGroup → Printer`.** → le provider cible
   par `$ctx->physicalGroupIds` + `$ctx->logicalGroupIds` (mailles POSTE), jamais par user. C'est cohérent
   avec « l'imprimante de la salle » (Vérité #9 = ressource de POSTE).

2. **Clé d'imprimante = `cups_name` (string, 15 char, PK non auto-incrémentée).** Le payload porte ce nom +
   l'URI CUPS (`socket://…`, `ipp://…`, …) lue via `CupsPrinterService::listPrinters()`/`getPrinter()`.
   ⚠️ L'URI n'est PAS en base (`printers` ne stocke que `cups_name`/`description_ser`/`orphan`) : elle vit
   dans CUPS. **Question : le provider lit-il l'URI live de CUPS (couplage runtime CUPS) ou émet-il un
   pointeur logique (`\\<se4fs>\<cups_name>` connexion Samba/IPP) que l'agent monte ?** Voir décision n° 4.

3. **Imprimante par défaut = sous-item `exclusive`, mais AUCUNE colonne `is_default`/`default`/`exclusive`
   n'existe** sur le pivot ni la table. Le défaut est **dérivé à la compilation** par « la règle la plus
   spécifique » (poste > salle physique > parc logique). ⚠️ « Règle de poste » n'existe PAS encore (pas de
   pivot `printer_workstation` poste-à-poste) → en MVP, la spécificité se joue **salle physique > parc
   logique** (les 2 mailles existantes). **Question Henri : faut-il une règle de poste (nouveau pivot) ou
   le défaut se résout-il sur les seules mailles WG existantes (physique > logique) en MVP ?** Voir
   décision n° 5 (le drapeau `is_default` est porté par le COMPILATEUR, pas une colonne SQL).

4. **`type=printers` est aggregate** (union des imprimantes de toutes les mailles du poste) **AVEC** un
   sous-item exclusif (`is_default`) : la collection est une union, mais **un seul** item porte
   `is_default: true`. C'est la première fois qu'un type aggregate porte un drapeau d'unicité interne →
   l'unicité du défaut est **résolue côté compilateur/provider** (le plus spécifique gagne, départage
   déterministe sur `cups_name` asc si égalité de spécificité), PAS côté agent. L'agent applique bêtement
   le `is_default` reçu.

### B) `drives` — TENSION STRUCTURELLE MAJEURE (à trancher Henri)

5. **Il N'EXISTE AUCUN modèle `Share` Eloquent, AUCUNE table SQL de partages, AUCUNE notion de lettre de
   lecteur (`drive_letter`/`mount_point`/`letter`) dans tout le codebase.** Les partages de classe sont
   **filesystem-truth** (`/var/sambaedu/Classes/Classe_<name>/…`, gérés par `ShareService` + ACLs).
   `SharesResyncClassCommand` réconcilie le FS, pas une table. → **Contrairement à `shortcuts`/`printers`,
   il N'Y A PAS de « règles existantes » SQL à lire en lecture seule pour `drives`.** C'est le fork le plus
   lourd de la story. Voir **Question Henri n° 1** + décision n° 1.

6. **Deux modèles possibles pour `drives` (décision n° 1, à trancher Henri) :**
   - **(MVP-A) Projection des partages de classe existants** : le provider dérive les montages depuis
     `$ctx->user->groups()->where('type','classe')` (les classes du user → chemin UNC
     `\\<se4fs>\Classe_<name>\<login>\`). **Pas de nouvelle table**, lettres de lecteur conventionnelles
     (ex. `H:` = home, `K:` = classe) **par convention figée serveur**. Léger, iso-existant, mais lettres
     non paramétrables.
   - **(MVP-B) Nouvelle table de règles `drives`** (lettre + UNC + ciblage par maille via pivot
     polymorphe `drive_assignables` calqué sur `shortcut_assignables`) + UI de règles. Plus complet
     (paramétrable, mailles parc/user/poste), mais **introduit une table métier + UI** → plus proche de
     27.3 (registry crée une table) que du pattern 27.1/27.2 (lecture des règles existantes).
   - **Note D1 (architecture)** : « jamais de table polymorphe GÉNÉRIQUE » — une table `drives` DÉDIÉE
     (option B) n'enfreint PAS D1 (D1 interdit la table fourre-tout, pas une table métier par ressource).
   - **Recommandation analyse** : MVP-A si Henri veut une parité rapide iso-classes ; MVP-B si la lettre
     de lecteur doit être pilotable par l'admin. **Question Henri n° 1 — décision de design majeure.**

7. **`drives` = `scope=session`** (l'AC epic le dit explicitement : « en portée session »). Cohérent : un
   lecteur réseau est monté DANS la session user (lettre par-user, UNC dépendant du login). Le handler
   agent `drives` est exécuté côté **compagnon de session** (déjà géré par le moteur, comme overlay).
   ⚠️ Diffère de `shortcuts` (27.1 = `machine_user` car le chemin du bureau dépend du POSTE). Pour `drives`,
   pas de dépendance au `WorkstationEnvironment` a priori (un montage réseau est réseau par nature) →
   **vérifier en dev** si un poste `nomade`/`personal_local` doit voir ses lecteurs réseau supprimés
   (poste 100 % local, mémoire `nomade_local_fr29_closed`). Voir décision n° 6.

8. **Imprimante par défaut ET lettre de lecteur sont des champs de PAYLOAD ownés par CETTE story** (contrat
   §3.2 : « payload owné par la story du provider »). Pas de nouvelle entrée §7 (`drives`/`printers` déjà
   gravés). C'est une **évolution mineure** du contrat (payload ajouté) → bump SCIEMMENT du hash figé.

### C) Tensions communes (réutiliser 27.1)

9. **`aggregate` (union) + machine d'états §5 + `AggregateHash` = RÉUTILISÉS, jamais réécrits.** Comme
   overlay/shortcuts : un verdict par TYPE, hash d'agrégat = concat des hashes opaques ordre serveur
   (`engine.go::AggregateHash`). L'isolation par item (AC epic : « les autres items continuent ») est DÉJÀ
   le comportement du moteur (`RunPass` isole par type ; un item `printers` en `error` n'empêche pas
   `drives` ni les autres types). **Ne pas réimplémenter §5.**

10. **Dédup aggregate au compilateur = DÉJÀ livré par 27.1** (`StateCompiler` dédoublonne les candidats
    aggregate par clé de contenu, ordre déterministe). Réutilisé tel quel pour drives/printers — vérifier
    la non-régression (pas de payload qui casse la canonicalisation du hasher).

11. **Mode par candidat = infra DÉJÀ posée par 27.1** (`StateCandidate` porte `?StateMode`, le compilateur
    agrège par type, défaut résolu via `mode()` quand null). Les deux nouveaux providers déclarent un
    `mode()` par défaut (`strict`, posture sûre, iso overlay/wallpaper). **Ne PAS ajouter de colonne `mode`
    ni de toggle UI** sauf si l'option `drives` MVP-B crée une table de règles qui le porte naturellement
    (décision n° 7). L'AC 27.2 ne réclame PAS le toggle → ne pas sur-spécifier.

12. **Le handler agent est Windows, la logique pure est testable hôte** (pattern 24.6/27.1). `drives` :
    `net use <lettre> <UNC>` (montage) + démontage des lettres gérées sorties des règles. `printers` :
    connexion imprimante réseau (`Add-Printer`/`rundll32 printui.dll` ou API Win32
    `AddPrinterConnection`) + défaut (`SetDefaultPrinter`). **Marqueur de périmètre géré** (comme les
    `.lnk` de 27.1) : ne JAMAIS démonter un lecteur monté par l'utilisateur, ne JAMAIS désinstaller une
    imprimante installée hors SambaEdu. Voir décision n° 8.

13. **Zéro PHP touché côté legacy.** Cette story AJOUTE 2 providers + 2 lignes au registry
    `AgentServiceProvider`, (selon décision n° 1 drives MVP-B) une migration + une UI, et MODIFIE le golden
    (sciemment). Elle ne touche AUCUN fichier du canal legacy lecteurs/imprimantes.

## Décisions de design — TRANCHÉES PAR HENRI (2026-06-15, validation de story) — DÉFINITIVES pour 27.2

> Forks arbitrés par Henri à la validation. Le dev applique ces décisions telles quelles, **ne les re-tranche
> pas**. Synthèse : drives = **MVP-A** (projection, pas de table) ; URI imprimante = **connexion logique** ;
> défaut imprimante = **réglable par workstationGroup** (drapeau pivot, physique > logique) ; lecteurs émis
> **partout** (pas de coupe nomade) ; **pas** de toggle mode UI.

1. **Modèle de données `drives` → MVP-A (TRANCHÉ : « au plus simple »).** Projection des partages de classe
   existants, **AUCUNE nouvelle table, AUCUNE UI de règles drives**, lettres conventionnelles **figées
   serveur**. MVP-B (table `drives` + pivot + UI) **écarté**. → T2 = provider de projection seul ; T6 sans UI
   drives.

2. **Lettres de lecteur → convention figée serveur (TRANCHÉ avec n° 1).** Documenter une convention stable
   (proposition dev : `H:` = home user, `K:` = classe ; **vérifier d'abord une convention legacy historique**
   dans `../sambaedu/` et la reprendre si elle existe). Pas de colonne `letter` SQL (MVP-A).

3. **Ciblage des `drives` → par les classes du user (TRANCHÉ avec n° 1).** `UserGroup type=classe`, maille
   `user_group`, via `TargetContext`. **JAMAIS** par CN AD (`ad_*`) — NFR7, critère Keycloak (grep en review).

4. **Source de l'URI imprimante → connexion logique (TRANCHÉ : « nom logique via le serveur »).** Le provider
   émet une **connexion logique** stable `\\<se4fs>\<cups_name>` (partage Samba imprimante) que l'agent monte
   côté poste ; **JAMAIS** l'URI back-end CUPS live (couplage runtime écarté). Token `<se4fs>` substitué
   localement par l'agent (iso 27.1). `CupsPrinterService` lu **uniquement** pour la métadonnée
   (description/location), pas l'URI back-end.

5. **Imprimante par défaut → réglable PAR WORKSTATIONGROUP (TRANCHÉ — nuance Henri, NE PAS auto-déduire « la
   salle »).** Le défaut est un **réglage explicite porté par le pivot `printer_workstation_group`** (drapeau
   `is_default` settable par l'admin sur l'attachement imprimante↔WG), valable **aussi bien pour un WG physique
   (salle) que logique (parc)**. À la convergence, quand un poste appartient à plusieurs WG porteurs d'un
   défaut : **le WG physique l'emporte sur le WG logique** ; départage déterministe `cups_name` asc à
   spécificité égale. Le provider/compilateur résout l'unicité et émet **UN SEUL** `is_default: true` dans le
   payload (sous-item `exclusive`, drapeau de payload, PAS une sémantique de type). **Conséquence de scope
   (vs proposition initiale d'auto-dérivation)** : ajouter une **colonne `is_default` (boolean, défaut false)
   au pivot `printer_workstation_group`** (migration) + une **affordance UI** pour la cocher dans la liste des
   imprimantes du WG (`printers-list.blade.php`). Pas de règle de poste (pivot poste-à-poste) — écarté.

6. **Lecteurs sur poste local/nomade → émis PARTOUT (TRANCHÉ : « oui, partout pareil »).** Les `drives` réseau
   sont émis **indépendamment du `WorkstationEnvironment`** ; comportement uniforme. **NE PAS** consommer le
   resolver 26.1 ici (26.1 devient sans objet pour 27.2). Un poste local fera ce qu'il veut du montage.

7. **Toggle UI mode pour drives/printers → NON exposé (TRANCHÉ : « pas pour cette story »).** Les providers
   déclarent `mode() = strict`. Aucun toggle UI strict/souple ajouté (ni drives — pas de table — ni printers).
   Scope resserré, conforme à l'AC.

8. **Marqueur de périmètre géré côté agent** (piège 12) — les lettres montées / imprimantes installées par
   l'agent sont tracées (applied-state per-user / liste de lettres+imprimantes gérées) pour que `apply`
   puisse **retirer** un mapping sorti des règles SANS toucher aux montages/imprimantes créés par
   l'utilisateur. Convention exacte au choix du dev (challengée en review) ; invariant = « level-triggered,
   jamais d'accumulation, jamais de suppression hors périmètre SambaEdu » (iso décision 27.1 n° 5).

## Acceptance Criteria

### AC1 — Types `drives` et `printers` servis : providers lecture seule + identifiants figés + golden (FR21, FR2)

**Given** les types `drives` et `printers` (identifiants DÉJÀ figés contrat §7, NFR12), `semantics=aggregate`,
`scope=session`
**When** `DrivesStateProvider` et `PrintersStateProvider` sont enregistrés dans `AgentServiceProvider` et le
`StateCompiler` compile
**Then** leurs types sont servis **sans aucune modification du compilateur** (AC1 pattern 23.4) ; les providers
lisent **en lecture seule** les règles/partages/imprimantes existants (Postgres + CUPS via `CupsPrinterService`
pour les imprimantes ; partages de classe Postgres pour les drives) — **aucun write métier, aucun appel
AD/LdapRecord/APCu** (critère Keycloak, grep en review)
**And** les payloads v1 (décisions n° 4/5 printers, n° 1-3 drives) sont émis sans float ; le golden
`tests/Fixtures/Agent/state.v1.json` est mis à jour SCIEMMENT pour refléter les payloads réels, avec **bump
documenté** du hash figé `ContractV1Test::FROZEN_STATE_HASH` + son jumeau Go (`hasher_test.go`).

### AC2 — Convergence en portée session : lecteurs mappés + imprimantes installées = union des mailles (FR21, FR18)

**Given** des règles `drives`/`printers` sur plusieurs mailles (parc + salle + [user/classe pour drives])
**When** l'agent converge en portée **session**
**Then** les lecteurs mappés et imprimantes installées correspondent à l'**union** des mailles applicables,
**sans doublon** (dédup aggregate au compilateur, réutilisée de 27.1) ; `apply` est **idempotent** (deux passes
sur état stable = `compliant`, zéro écriture)
**And** le provider résout les mailles via `TargetContext` (`physicalGroupIds`/`logicalGroupIds` pour printers ;
+ `userGroupIds`/`user` pour drives selon décision n° 3) — **jamais de re-requête** des appartenances.

### AC3 — Imprimante par défaut = réglage explicite par workstationGroup (sous-item `exclusive`, physique > logique) (FR21)

**Given** un drapeau `is_default` réglé par l'admin sur des attachements imprimante↔WG (pivot
`printer_workstation_group`), et un poste appartenant à plusieurs WG (salle physique + parc logique) porteurs
chacun d'un défaut
**When** l'agent converge
**Then** **une seule** imprimante porte `is_default: true` dans le payload — celle réglée sur le **WG physique**
(qui l'emporte sur le WG logique, décision n° 5 ; départage déterministe `cups_name` asc à spécificité égale),
résolu côté serveur (provider/compilateur)
**And** l'admin peut cocher « imprimante par défaut » sur l'attachement imprimante↔WG (colonne pivot `is_default`
+ affordance UI dans `printers-list.blade.php`), pour un WG physique **comme** logique
**And** le sous-item `exclusive` (`is_default`) est **documenté au schéma du payload** (`docs/agent/state-providers.md`,
contrat §3.2) — c'est un champ de payload, PAS une nouvelle sémantique de type ; l'agent applique `SetDefaultPrinter`
sur l'item marqué, sans recalculer la spécificité.

### AC4 — Isolation des erreurs : serveur d'impression injoignable → `error`, les autres continuent (FR18)

**Given** un serveur d'impression injoignable à l'`apply` d'un item `printers`
**When** la boucle du compagnon exécute `apply`
**Then** le statut `error` + **detail exploitable** sont rapportés pour CET item/type, **les autres items et types
continuent** (isolation `engine.go::RunPass`, réutilisée — jamais réimplémentée), et l'agent **retente au prochain
cycle** (level-triggered)
**And** un échec `printers` n'empêche NI la convergence `drives` NI les autres types (shortcuts/wallpaper/overlay).

### AC5 — Level-triggered : un mapping retiré des règles est démonté au passage suivant (FR21, FR18)

**Given** un lecteur mappé / une imprimante installée par l'agent, puis sa règle retirée côté serveur
**When** l'agent converge au passage suivant
**Then** le mapping est **démonté** (lettre `net use /delete` / imprimante désinstallée) — convergence, **pas
accumulation**
**And** les lecteurs montés / imprimantes installées **par l'utilisateur hors périmètre SambaEdu** ne sont
**JAMAIS** supprimés (marqueur de périmètre géré, décision n° 8).

### AC6 — Handlers agent Go + golden + reporting par item (FR21, FR18)

**Given** l'état cible contenant des items `drives` et/ou `printers`
**When** la boucle du compagnon exécute `test` puis `apply` si écart
**Then** deux handlers (`agent/shared/handler_drives.go` + `handler_printers.go` logique pure testable hôte ;
`agent/windows/handler_drives_windows.go` montage `net use` + `handler_printers_windows.go` connexion imprimante
+ `SetDefaultPrinter`) sont enregistrés dans le moteur du compagnon (`companion_windows.go`) ; isolation par item ;
exécution dans l'ordre du payload serveur
**And** les statuts sont rapportés (`compliant|drift|drifted_allowed|error`) dans `POST /report` conforme au golden
`report.v1.json` (hash d'agrégat conventionnel `AggregateHash`, ordre serveur)
**And** convention de hash d'agrégat = concat des hashes opaques (jamais de recalcul depuis la sérialisation agent —
piège réutilisé de 24.6/27.1) ; stub `!windows` (no-op) pour chaque handler spécifique.

### AC7 — Tests : PHPUnit serveur + go test agent, baselines intactes (NFR13)

**Then** côté **Laravel** : `tests/Unit/Services/Agent/DrivesStateProviderTest.php` +
`PrintersStateProviderTest.php` (mailles, union, payload, lecture seule, zéro AD ; printers : défaut = plus
spécifique + départage déterministe ; drives : ciblage par classe/maille selon décision n° 1) ; test compilateur
(dédup/union réutilisée, défaut exclusif) ; non-régression `--filter Agent` sur `/vm` (baseline relevée au début
du dev)
**And** côté **agent Go** : `agent/shared/handler_drives_test.go` + `handler_printers_test.go` (set cible,
test/apply idempotent, suppression level-triggered, marqueur de périmètre géré, défaut imprimante, item `error`
isolé, machine d'états §5 table-driven) ; `go test ./...`, `go vet` (linux + `GOOS=windows`), cross-compile verts
sur l'hôte ; spécifique Windows validé cross-compile + lab humain
**And** golden files cohérents serveur (PHPUnit) ET agent (`go test`) — tests croisés (NFR13).

### AC8 — Documentation + QA (append-only)

**Then** `docs/agent/state-providers.md` : sections `drives` + `printers` (payloads v1, mailles, union, défaut
exclusif, source URI imprimante, lettres de lecteur, isolation des erreurs) ; `agent/README.md` : handlers `drives`
+ `printers` ; `docs/agent/contract-v1.md §7` reste INTOUCHÉ (déjà figé)
**And** `docs/qa/domains/agent.md` (canal agent, handlers) enrichi **append-only** (nouvelle section sans
renuméroter) : convergence lecteurs+imprimantes UI→poste, union, défaut, suppression level-triggered, isolation
serveur d'impression down ; ligne 27.2 dans `docs/qa/README.md` ; le cas échéant `docs/domains/printers.md` enrichi
**And** restent **INTOUCHÉS** : tout le canal legacy lecteurs/imprimantes, `CupsPrinterService` (lu, jamais
modifié), `ShareService` (lu/projeté, jamais modifié pour drives), le PHP serveur agent figé hors
providers/registry, `contract-v1.md §7`, `agent/shared/hasher.go`/`engine.go::ResolveItemStatus` (réutilisés).

## Tasks / Subtasks

- [x] **T0 — Forks tranchés par Henri (2026-06-15)** (toutes AC) — *décisions n° 1-7 ACTÉES*
  - [x] Drives = **MVP-A** (projection classes, pas de table, pas d'UI drives) ; URI imprimante = **connexion
        logique** `\\<se4fs>\<cups_name>` ; défaut imprimante = **réglable par WG** (colonne pivot `is_default`,
        physique > logique) ; lecteurs **émis partout** (pas de coupe nomade, 26.1 sans objet) ; **pas** de
        toggle mode UI. Voir « Décisions de design » (TRANCHÉES).

- [x] **T1 — `PrintersStateProvider` (serveur, lecture seule) + défaut par WG** (AC1, AC2, AC3)
  - [x] **Migration** : colonne `is_default` (boolean, défaut `false`) sur le pivot `printer_workstation_group`
        (décision n° 5). Pas d'index unique global (l'unicité du défaut est résolue à la compilation, pas en SQL).
  - [x] **UI** : affordance « imprimante par défaut » (checkbox/toggle) sur l'attachement imprimante↔WG dans
        `resources/views/pages/parc/groups/[id]/_partials/printers-list.blade.php` (Livewire existant ;
        `WithToasts` ; Gate cohérent avec l'attache/détache existant). Valable WG physique **et** logique.
  - [x] `app/Services/Agent/Providers/PrintersStateProvider.php` (`final`, `declare(strict_types=1)`, calqué sur
        `OverlayStateProvider`). `type()` → `'printers'` (constante `Printer::TYPE_PRINTERS` à ajouter, iso
        `Wallpaper::TYPE_WALLPAPER`), `semantics()` → `Aggregate`, `scope()` → `Session`, `mode()` → `Strict`.
  - [x] `itemsFor(TargetContext $ctx)` : lit les imprimantes via la relation `WorkstationGroup::printers()`
        restreinte à `$ctx->physicalGroupIds + $ctx->logicalGroupIds` (mailles POSTE), avec le pivot
        `is_default` chargé ; étiquette chaque candidat par maille (`PhysicalGroup`/`LogicalGroup`) ; **zéro
        précédence/tri** côté provider sauf le calcul du défaut exclusif (décision n° 5).
  - [x] Payload v1 `{cups_name, connection, description, location, is_default}` — `connection` = **connexion
        logique** `\\<se4fs>\<cups_name>` (décision n° 4, token `<se4fs>` substitué agent) ; lire la métadonnée
        (description/location) via `CupsPrinterService::getPrinter()` (lecture seule, jamais d'écriture CUPS,
        jamais l'URI back-end).
  - [x] `is_default` : résolu serveur — parmi les WG porteurs d'un `is_default=true` applicables au poste, le
        **WG physique l'emporte sur le logique** ; départage `cups_name` asc — UN SEUL `is_default: true` dans
        la collection.
  - [x] Enregistrer dans `AgentServiceProvider::register()` (une ligne dans le tableau du `StateCompiler`).

- [x] **T2 — `DrivesStateProvider` (serveur, lecture seule, MVP-A projection)** (AC1, AC2) — *décision n° 1 = MVP-A*
  - [x] **Préalable** : vérifier dans `../sambaedu/` (legacy SE4) s'il existe une **convention de lettres
        historique** (montage classe/home) à reprendre ; sinon documenter la convention retenue (proposition :
        `H:` = home user, `K:` = classe). NE PAS créer de table ni d'UI (MVP-A).
  - [x] `app/Services/Agent/Providers/DrivesStateProvider.php` (`final`, `declare(strict_types=1)`).
        `type()` → `'drives'`, `semantics()` → `Aggregate`, `scope()` → `Session`, `mode()` → `Strict`.
  - [x] `itemsFor()` **projette** les montages depuis les classes du user (`$ctx->user?->groups()` type
        `classe`, ou via les ids `userGroupIds` déjà résolus du contexte si exposés) → un candidat par classe,
        payload `{letter, unc, label}` avec UNC `\\<se4fs>\Classe_<name>\<login>\` et lettre conventionnelle
        figée (décision n° 2). Maille `UserGroup`. Tokens `<se4fs>`/`<login>` substitués localement par l'agent.
  - [x] **Émis indépendamment du `WorkstationEnvironment`** (décision n° 6 : partout pareil) — **NE PAS**
        consommer le resolver 26.1.
  - [x] Enregistrer dans `AgentServiceProvider::register()`.

- [x] **T3 — Handler agent Go `printers`** (AC4, AC5, AC6)
  - [x] `agent/shared/handler_printers.go` : logique PURE (set cible des imprimantes, décision test/apply, item
        `is_default`, marqueur de périmètre géré — décision n° 8), testable hôte. Aggregate : `test` = l'ensemble
        des imprimantes gérées == union cible ∧ le défaut == celui marqué ? `apply` = installer les manquantes +
        désinstaller les gérées sorties des règles + `SetDefaultPrinter` (idempotent, level-triggered). **Item
        `error` isolé** si serveur injoignable (detail) — les autres continuent.
  - [x] `agent/windows/handler_printers_windows.go` : connexion imprimante réseau (`AddPrinterConnection` Win32
        ou `printui.dll`/`Add-Printer` documenté si justifié — échappatoire admise comme COM 27.1) +
        `SetDefaultPrinter`. Substitution des tokens (`<se4fs>`). Stub `!windows` (no-op). Réutiliser l'interop
        Win32 déjà présente (24.6/27.1) si elle existe avant d'ajouter une dépendance.
  - [x] Enregistrer `"printers"` dans la map `Handlers` (`companion_windows.go`).
  - [x] Réutiliser `engine.go::ResolveItemStatus` (§5) + `AggregateHash` — **ne pas réimplémenter**.

- [x] **T4 — Handler agent Go `drives`** (AC2, AC5, AC6)
  - [x] `agent/shared/handler_drives.go` : logique PURE (set cible des montages lettre→UNC, décision test/apply,
        marqueur de périmètre géré), testable hôte. `test` = lettres gérées == union cible ? `apply` = monter les
        manquants (`net use`) + démonter les gérés sortis des règles (`net use /delete`), idempotent,
        level-triggered. Item `error` isolé si serveur injoignable.
  - [x] `agent/windows/handler_drives_windows.go` : `net use <lettre> <UNC>` / `net use <lettre> /delete` (ou API
        `WNetAddConnection2`/`WNetCancelConnection2` documentée). Substitution tokens. Stub `!windows`.
  - [x] Enregistrer `"drives"` dans la map `Handlers`.
  - [x] Réutiliser `engine.go::ResolveItemStatus` + `AggregateHash`.

- [x] **T5 — Golden files (sciemment) + bump hash figé** (AC1, AC6)
  - [x] Mettre à jour `tests/Fixtures/Agent/state.v1.json` (items `drives` + `printers` réels, scope `session`) et
        `report.v1.json` (items `drives`/`printers`, dont un `printers` en `error` pour figer l'isolation) ; bumper
        le `FROZEN_STATE_HASH`/hash de `ContractV1Test` **sciemment** + le jumeau Go `hasher_test.go::frozenStateHash`,
        documenter le bump (évolution mineure du contrat, §9). Vérifier la cohérence Go (`go test` golden = test
        croisé NFR13).

- [x] **T6 — UI = toggle « imprimante par défaut » par WG (PAS d'UI drives)** (AC3) — *décisions n° 1/5/7*
  - [x] **Drives : AUCUNE UI** (MVP-A, décision n° 1 — les partages de classe ont déjà leur UI
        `class-share-section`, lue/projetée, pas modifiée). **Pas de toggle mode strict/souple** (décision n° 7).
  - [x] **Printers** : la seule UI de cette story = l'affordance « imprimante par défaut » sur l'attachement
        imprimante↔WG (cf. T1) dans `printers-list.blade.php` — checkbox/toggle Livewire, `WithToasts`, Gate
        cohérent avec l'attache/détache existant. (Listée ici pour mémoire ; implémentée avec la migration de T1.)

- [x] **T7 — Tests** (AC7)
  - [x] PHPUnit : `PrintersStateProviderTest` (mailles, union, payload, défaut = plus spécifique + départage,
        lecture seule, zéro AD) ; `DrivesStateProviderTest` (selon décision n° 1 : projection classes ou table) ;
        compilateur (union/dédup réutilisée, défaut exclusif). Non-régression `--filter Agent` /vm.
  - [x] Go : `handler_printers_test.go` + `handler_drives_test.go` (set cible, idempotence, suppression
        level-triggered, marqueur de périmètre géré, défaut imprimante, item `error` isolé, §5 table-driven).
        `go test ./...`, `go vet` (linux+windows), cross-compile.

- [x] **T8 — Documentation + QA** (AC8)
  - [x] `docs/agent/state-providers.md` (sections drives + printers), `agent/README.md` (handlers), QA append-only
        (`agent.md`), ligne 27.2 `docs/qa/README.md`, `docs/domains/printers.md` enrichi le cas échéant.

- [x] **T9 — Validation finale** (AC7)
  - [x] `php -l` sur les fichiers PHP ; grep critère Keycloak (`ldap|apcu|get_apps|samba-tool|ad_users|ad_user_groups`)
        sur les 2 providers → vide ; grep « zéro retrofit legacy » (aucun fichier du canal legacy
        lecteurs/imprimantes dans le diff ; `CupsPrinterService`/`ShareService` lus, non modifiés).
  - [x] `go test ./...` + `go vet` (linux+windows) + cross-compile verts sur l'hôte ; `--filter Agent` /vm sans
        régression. Migration (si MVP-B) → **à jouer sur la VM** (`migrate:status` avant e2e).
  - [x] **Validation lab (poste Windows) : ACTION HUMAINE (Henri)** — montage des lecteurs réseau + installation
        des imprimantes selon les règles, union multi-mailles, imprimante par défaut = plus spécifique, suppression
        level-triggered (mapping retiré → démonté), isolation (serveur d'impression down → `error`, le reste
        converge).

## Dev Notes

### Périmètre — livré / hors-scope

| Livré (27.2) | Hors-scope (story) |
|---|---|
| `PrintersStateProvider` (lecture seule, union, défaut exclusif) | Décommissionnement canal legacy lecteurs/imprimantes → **27.6** |
| `DrivesStateProvider` (lecture seule, montages réseau) | Modification de `CupsPrinterService` (lu seulement) / `ShareService` (projeté seulement) |
| Handlers agent Go `printers` + `drives` (Windows, level-triggered, isolation) | Handlers registre/associations (27.3), app_config (27.4), applications (27.5) |
| Sous-item `exclusive` (imprimante par défaut) au schéma de payload | Toggle UI mode (déjà introduit 27.1 ; non réclamé par AC 27.2) |
| Golden files mis à jour + bump hash documenté (PHP + Go) | Ciblage par CN AD (`ad_*`) — exclu NFR7 |
| Tests PHPUnit + go test + QA | Tout le canal legacy lecteurs/imprimantes (INTOUCHÉ) |

### Le pattern 27.1 — ce qu'on imite À L'IDENTIQUE (ne PAS réinventer)

[Source: _bmad-output/implementation-artifacts/27-1-handler-raccourcis-convergence-bureau.md ; app/Services/Agent/Providers/OverlayStateProvider.php ; ShortcutsStateProvider.php]

- **Provider** = `final`, `declare(strict_types=1)`, implémente `StateProvider` (`type/semantics/mode/scope/itemsFor`).
  `OverlayStateProvider` est le modèle **aggregate/session** le plus proche (1 candidat/source, étiquetage par
  maille, zéro précédence, `mode()` = `Strict`, `scope()` = `Session`). **Le copier.**
- **Registry** : une ligne dans `AgentServiceProvider::register()` (tableau du `StateCompiler`).
- **`StateCandidate`** (readonly : `maille`, `payload`, `updatedAt`, `sourceId`, `?StateMode $mode`) — porte déjà
  le mode par candidat (27.1) ; on passe `mode: null` (retombe sur `mode()` = `strict`).
- **`StateCompiler`** porte DÉJÀ la dédup aggregate par contenu + l'agrégation du mode par type (27.1) — réutilisés.
- **Tokens** `<se4fs>`/`<user>`/`<login>` substitués localement par l'agent (jamais de secret en payload, pas de
  dépendance réseau au calcul) — iso 27.1 décision n° 3.

### Le socle agent Go 24.6/27.1 — ce qu'on consomme

[Source: agent/shared/engine.go ; handler_overlay.go ; handler_shortcuts.go ; agent/windows/companion_windows.go ; handler_shortcuts_windows.go]

- `Engine.RunPass` + `ResolveItemStatus` (machine d'états §5 strict/default/premier passage) + `AggregateHash`
  (concat hashes opaques, ordre serveur) — **réutilisés, jamais réécrits**. L'isolation par item (AC4) EST le
  comportement de `RunPass` : un type en `error` n'empêche pas les autres.
- `handler_shortcuts.go` est le modèle de handler **aggregate level-triggered avec marqueur de périmètre géré**
  (suppression sélective, jamais un objet utilisateur) — calquer pour drives/printers.
- Enregistrement : map `type → Handler` dans `companion_windows.go` (y ajouter `"drives"` + `"printers"`).
- Conventions : suffixe `_windows.go` + stub `!windows`, écriture atomique, applied-state per-user, `go test` hôte,
  interop Win32/COM native (préférée au shell-out, décision 27.1 n° 7 — vérifier ce qui existe avant d'ajouter une
  dépendance ; `go.mod` à garder inchangé si possible).

### Domaine imprimantes — à LIRE, pivot existant

[Source: app/Models/Printer.php ; app/Models/WorkstationGroup.php:278 (printers()) ; database/migrations/2026_04_27_120000_create_printers_table.php ; ..120100_create_printer_workstation_group_table.php ; app/Services/Print/CupsPrinterService.php ; app/Policies/PrinterPolicy.php]

- `Printer` : PK = `cups_name` (VARCHAR(15), non auto-incr), `description_ser`, `orphan` (drift CUPS↔SER),
  `drivers()` (HasMany `printer_drivers`). Relation `WorkstationGroup::printers()` (BelongsToMany via
  `printer_workstation_group`, pivot `attached_at`/`attached_by_user_id`). **PAS de relation `UserGroup → Printer`.**
- **Colonne `is_default` à AJOUTER au pivot `printer_workstation_group`** (décision n° 5 tranchée Henri) :
  réglage explicite par WG (physique comme logique). L'unicité (un seul défaut par poste) est résolue à la
  compilation (physique > logique, départage `cups_name` asc), PAS par contrainte SQL.
- `CupsPrinterService::listPrinters()/getPrinter($name)` → `[name, uri, state, description, location, model, …]`.
  L'URI vit dans CUPS, pas en SQL. **Lecture seule** (jamais `addPrinter`/`updatePrinter`/`deletePrinter` ici).
- UI existante (lue, non modifiée pour le canal agent) : `resources/views/pages/parc/_partials/printers-tab.blade.php`
  + `resources/views/pages/parc/groups/[id]/_partials/printers-list.blade.php`.
- Spécificité MVP : salle physique (`is_physical=true`) > parc logique (`is_physical=false`). Pas de règle de poste
  (pas de pivot `printer_workstation`) — départage `cups_name` asc.

### Domaine lecteurs/partages — TENSION : pas de table SQL, filesystem-truth

[Source: app/Services/Filesystem/ShareService.php ; app/Console/Commands/SharesResyncClassCommand.php ; app/Policies/SharePolicy.php ; app/Models/UserGroup.php ; resources/views/pages/users/groups/[id]/_partials/class-share-section.blade.php]

- **AUCUN modèle `Share`, AUCUNE table `shares`, AUCUNE notion `drive_letter`/`mount_point`/`letter`** dans tout
  le codebase (recherche exhaustive). Les partages de classe sont **filesystem-truth**
  (`/var/sambaedu/Classes/Classe_<name>/…`, ACLs gérées par `ShareService`). `SharesResyncClassCommand` réconcilie
  le FS, pas une table.
- `ShareService` (lu/projeté, JAMAIS modifié pour drives) : `createClassShare`, `getStatus`, `syncUserClassMemberships`,
  `toggleEchange`, `archiveClassShare`. Chemin FS : `/var/sambaedu/Classes/Classe_<nom_groupe>`.
- Classes = `UserGroup` `type='classe'`, pivot `user_group_user`. Un poste obtient ses lecteurs via le couple
  `(poste, user)` → classes du user. **Pas de relation directe poste→partage.**
- → **Décision n° 1 (Henri)** : MVP-A (projection des classes, pas de table, lettres conventionnelles) ou MVP-B
  (table `drives` + pivot `drive_assignables` + UI). **À TRANCHER avant T2.** D1 (architecture) n'interdit qu'une
  table polymorphe GÉNÉRIQUE — une table `drives` dédiée est admise.

### Contrat & golden

[Source: docs/agent/contract-v1.md §3.2, §7, §8, §9 ; tests/Fixtures/Agent/state.v1.json ; report.v1.json ; tests/Unit/Services/Agent/ContractV1Test.php ; agent/shared/hasher_test.go]

- `drives` et `printers` **DÉJÀ figés** au §7 (ligne 246-247) : ne PAS créer d'entrée §7, ne PAS renommer.
- `aggregate` = union (l'agent applique l'union) ; le défaut imprimante = **drapeau de payload**, pas une sémantique.
- Payload owné par la story du provider (§3.2) → évolution **mineure** (champ ajouté), forward-compatible. Le golden
  est **illustratif** mais c'est la frontière de contrat : son hash est figé → bump SCIEMMENT documenté (PHP
  `FROZEN_STATE_HASH` + Go `frozenStateHash` = test croisé NFR13).
- §8 : type absent ≠ payload vide. Un poste sans règle drives/printers → type ABSENT (l'agent ne touche pas).

### Enums & contexte (réutilisés)

[Source: app/Enums/StateScope.php (Session) ; ResourceSemantics.php (Aggregate) ; StateMaille.php (UserGroup/PhysicalGroup/LogicalGroup) ; StateMode.php (Strict) ; app/Services/Agent/TargetContext.php]

- `StateScope::Session`, `ResourceSemantics::Aggregate`, `StateMode::Strict`.
- `TargetContext` : `physicalGroupIds` (salles), `logicalGroupIds` (parcs), `userGroupIds` (groupes SQL du user),
  `workstationGroupIds()` (union salle+parcs), `user` (nullable machine-only). **Consommer, jamais re-requêter.**
- Printers : mailles `PhysicalGroup`/`LogicalGroup`. Drives (MVP-A) : maille `UserGroup` (classes).

### Project Structure Notes

- Providers → `app/Services/Agent/Providers/{Printers,Drives}StateProvider.php` ; registry →
  `AgentServiceProvider::register()`.
- Modèle → `app/Models/Printer.php` (const `TYPE_PRINTERS`). Drives MVP-B → nouveau modèle/migration/pivot.
- Agent → `agent/shared/handler_{printers,drives}.go` (+ tests) ; `agent/windows/handler_{printers,drives}_windows.go`
  (+ stubs) ; enregistrement `companion_windows.go`.
- Golden → `tests/Fixtures/Agent/state.v1.json` (+ `report.v1.json`) ; hash figé `ContractV1Test` + `hasher_test.go`.
- UI → AUCUNE si drives MVP-A ; Livewire SFC de règles drives si MVP-B (`pages/`).
- Doc → `docs/agent/state-providers.md`, `agent/README.md`, `docs/qa/domains/agent.md`, `docs/qa/README.md`,
  `docs/domains/printers.md`.

### Environnement de dev — règles VM

- Code à la RACINE (app/, agent/, …) ; édité sur l'hôte, sync inotify auto, **jamais de sync manuelle**.
- **Go = hôte uniquement** (`~/go-toolchain/go/bin/go`, package main = `agent/windows`). PHPUnit sur /vm.
- Migration (si drives MVP-B) → **à jouer sur la VM** (`migrate:status` avant e2e — VM migrations pas auto-jouées).
- **SQLite n'applique pas les varchar** (cups_name 15, letter 2…) : overflow PG 22001 invisibles en test — viser
  /vm pour la non-régression finale.
- Jamais d'interaction VM depuis un worktree git.

### References

- [Source: _bmad-output/planning-artifacts/epics-agent-desired-state.md#Story 27.2 (l.701-717)] — AC d'origine ; FR21 ; Vérité #9 (« l'imprimante de la salle »).
- [Source: _bmad-output/planning-artifacts/epics-agent-desired-state.md#Epic 27 (l.679) + Story 27.6] — du simple au dur, extinction en bloc 27.6, ZÉRO retrofit legacy.
- [Source: _bmad-output/implementation-artifacts/27-1-handler-raccourcis-convergence-bureau.md] — pattern complet (provider+handler Go+id figé+golden+mode), marqueur de périmètre géré, tokens, bump hash croisé.
- [Source: app/Services/Agent/Providers/OverlayStateProvider.php] — modèle aggregate/session le plus proche à copier.
- [Source: app/Models/Printer.php ; app/Models/WorkstationGroup.php:278] — pivot `printer_workstation_group`, relation `printers()`, pas de `UserGroup→Printer`, pas de `is_default`.
- [Source: app/Services/Print/CupsPrinterService.php] — `listPrinters()/getPrinter()` (URI/state/description), lecture seule.
- [Source: app/Services/Filesystem/ShareService.php ; SharesResyncClassCommand.php] — partages filesystem-truth, pas de table SQL, pas de drive_letter.
- [Source: app/Services/Agent/TargetContext.php] — mailles résolues une fois (physicalGroupIds/logicalGroupIds/userGroupIds/user).
- [Source: agent/shared/engine.go] — `ResolveItemStatus` (§5), `AggregateHash`, isolation par item (AC4), enregistrement Handler.
- [Source: agent/shared/handler_shortcuts.go ; agent/windows/handler_shortcuts_windows.go] — modèle handler aggregate level-triggered + marqueur de périmètre géré + interop Win32 native.
- [Source: docs/agent/contract-v1.md §3.2, §7 (l.246-247), §8, §9] — `drives`/`printers` figés, aggregate/union, payload owné par story, type absent ≠ vide, règle d'évolution.
- [Source: tests/Fixtures/Agent/state.v1.json ; report.v1.json ; tests/Unit/Services/Agent/ContractV1Test.php ; agent/shared/hasher_test.go] — golden à faire évoluer + hash croisé figé.
- [Source: docs/domains/printers.md] — domaine imprimantes (Story 6.1/6.2), architecture CUPS/SER.

## Dépendances

| Story | Rôle pour 27.2 | Statut (sprint-status.yaml) | Bloquant ? |
|-------|----------------|------------------------------|------------|
| **27.1** — handler raccourcis + pattern + infra mode | Fournit le PATTERN complet (provider+handler Go+golden), l'infra `StateCandidate.mode` + agrégation par type + dédup aggregate au compilateur, le marqueur de périmètre géré, le golden + hash croisé à faire évoluer | `review` | **Prérequis fort** — le compilateur (dédup aggregate) et le contrat (golden bumpé) viennent de 27.1. En `review` (Henri teste les lots) : dev autorisé avec **rebase si correctifs post-review** (mêmes findings que `_bmad-output/codeReviews/27-1.md`). Idéalement `done`/`review` exploitable au démarrage. |
| **26.1** — enum WorkstationEnvironment + resolver | Consommé UNIQUEMENT si décision n° 6 (couper les lecteurs réseau sur poste nomade/local) | `review` | **Non bloquant** — consommé en lecture seulement si Henri active le filtrage nomade (a priori non : un montage réseau reste réseau). |
| 23.4 — StateCompiler / StateProvider / TargetContext | Pattern provider + compilateur + contexte que 27.2 étend | `done` | Non (consommé) |
| 24.6 — agent Go compagnon + handlers + moteur §5 | Moteur de convergence, `AggregateHash`, isolation par item, enregistrement handlers | `done` | Non (consommé) |
| 23.1 — contrat v1 + golden + StateHasher | `drives`/`printers` déjà figés §7, golden à faire évoluer sciemment | `done` | Non |
| 6.1 / 6.2 — domaine imprimantes (Printer, pivot, CupsPrinterService, drivers) | Catalogue d'imprimantes + pivot WG + métadonnée CUPS à lire | `done` (en prod) | Non (lu seulement) |
| 5.x — partages de classe (ShareService) | Source FS des partages de classe à projeter en montages (MVP-A drives) | `done` (en prod) | Non (lu/projeté seulement) |

## Questions pour Henri — RÉSOLUES (2026-06-15, validation de story)

> Toutes tranchées. Détail dans « Décisions de design » (TRANCHÉES). Récap :

1. **Modèle `drives`** → **MVP-A** (projection des classes, pas de table, pas d'UI, lettres figées serveur).
2. **Lettres & ciblage `drives`** → convention figée serveur (vérifier legacy ; sinon `H:`/`K:`), ciblage par
   classes du user (maille `user_group`), jamais AD.
3. **Source URI imprimante** → **connexion logique** `\\<se4fs>\<cups_name>` (pas l'URI back-end CUPS).
4. **Imprimante par défaut** → **réglable par workstationGroup** (drapeau pivot `is_default`, physique **et**
   logique), résolution physique > logique. PAS de règle de poste, PAS d'auto-dérivation « la salle ».
5. **Lecteurs sur poste nomade/local** → **émis partout** (indépendant du `WorkstationEnvironment`, 26.1 sans objet).
6. **Toggle UI mode** → **non exposé** (scope resserré).

## Recommandation Modèle Dev

**Recommandation : `opus`.**

Justification : story **multi-domaine, multi-fichiers et à forte incertitude de conception**. Elle livre
simultanément **deux types** (chacun = 1 StateProvider PHP + 1 handler Go pur + 1 spécifique Windows + golden), soit
~8-10 fichiers de code + golden + doc/QA. Trois facteurs élèvent le risque au-delà d'un portage mécanique :
(a) le type `drives` n'a **AUCUN socle SQL** (pas de modèle `Share`, pas de table, pas de lettre de lecteur) → le dev
doit instruire un fork structurel (projection vs nouvelle table+pivot+UI) et le câbler proprement sans dériver vers du
legacy ni violer NFR7/D1 ; (b) le **sous-item `exclusive`** (imprimante par défaut) introduit une unicité interne dans
un type aggregate — logique de spécificité + départage déterministe + cohérence du hash croisé PHP/Go ; (c)
l'**isolation des erreurs** (serveur d'impression injoignable → `error` sans casser les autres items) doit être
prouvée par golden + tests Go table-driven en réutilisant §5 sans le réimplémenter. À cela s'ajoute l'**évolution
sciemment du golden file** (frontière de contrat figée, bump du hash, tests croisés serveur/agent). Le piège
dominant — retrofit legacy interdit (`CupsPrinterService`/`ShareService` lus mais jamais câblés au canal mourant) +
cohérence du contrat figé + convergence level-triggered avec marqueur de périmètre géré — exige le raisonnement le
plus rigoureux. `opus`.

## Dev Agent Record

### Modèle utilisé

`opus` (claude-opus-4-8) — conformément à la recommandation de la story.

### Décisions d'implémentation (les 7 forks d'Henri appliqués SANS re-trancher)

- **drives = MVP-A** : `DrivesStateProvider` projette les classes du user (`UserGroup type='classe'`
  parmi les `userGroupIds` du `TargetContext`), aucune table, aucune UI drives. UNC tokenisé
  `\\<se4fs>\Classe_<bare>\<login>\` (préfixe `Classe_` normalisé via `ShareService::bareClassName()`,
  lu jamais modifié).
- **Lettres figées serveur** : vérification legacy faite (`grep "net use"` sur `../sambaedu/`) → AUCUNE
  convention historique de lettre classe/home (le `net use` SE4 ne monte que `z:` pour l'installeur WPKG).
  Convention retenue et documentée : `K:` = classe (incrément `K`,`L`,`M`… par classe, ordre nom asc) ;
  `H:` (home) réservé pour une projection future, **non émis** ici.
- **URI imprimante = connexion logique** : payload `connection = \\<se4fs>\<cups_name>`. `CupsPrinterService::getPrinter()`
  lu UNIQUEMENT pour `description`/`location` ; l'URI back-end CUPS (`socket://…`) ne fuit jamais (testé).
  CUPS injoignable à la compilation → métadonnée vide, l'imprimante reste servie (la connexion logique est stable).
- **Défaut par WG (`is_default`)** : migration ajoutant la colonne `is_default` (boolean, défaut false, pas
  d'index unique) au pivot `printer_workstation_group` + toggle Livewire dans `printers-list.blade.php`
  (`toggleDefaultPrinter()` sur le composant `[id]/index.blade.php`, `Gate::authorize('manage-printer')`,
  `WithToasts`). Résolution serveur dans le provider : WG physique > logique, départage `cups_name` asc,
  UN SEUL `is_default: true`. **Toggle exclusif intra-WG** (cocher un défaut décoche les autres du même WG)
  pour une UX prévisible — la résolution inter-WG reste côté serveur.
- **Lecteurs émis partout** : `DrivesStateProvider` NE consomme PAS le `WorkstationEnvironmentResolver` (26.1
  sans objet) — émis indépendamment de l'environnement (testé sur parc nomade).
- **Pas de toggle mode UI** : les deux providers déclarent `mode() = Strict`, `StateCandidate.mode = null`
  (retombe sur le défaut du type au compilateur). Aucun toggle ajouté.

### Décision laissée au dev (challengée en review)

- **Marqueur de périmètre géré côté agent** : convention retenue = le **serveur SambaEdu** (`<se4fs>` résolu
  localement). Pour `printers`, on ne gère/désinstalle QUE les connexions `\\<se4fs>\…` (l'identité Windows
  d'une connexion EST son nom `\\serveur\nom`, donc pas d'homonyme « user » possible — `Blocked()` retourne
  toujours false côté Windows, documenté). Pour `drives`, on ne gère QUE les lettres montées vers `\\<se4fs>\…`
  (une lettre montée par l'utilisateur vers un autre serveur, ou une lettre cible occupée par un montage user,
  est `Blocked` → jamais écrasée ni démontée).

### Interop Windows native (iso décision 27.1 n° 7 — zéro shell-out, go.mod inchangé)

- `printers` : winspool.drv (`AddPrinterConnectionW` / `DeletePrinterConnectionW` / `SetDefaultPrinterW` /
  `GetDefaultPrinterW` / `EnumPrintersW` niveau 4) via `golang.org/x/sys/windows` lazy DLL.
- `drives` : mpr.dll (`WNetAddConnection2W` persistant / `WNetCancelConnection2W` force /
  `WNetGetConnectionW`) via `golang.org/x/sys/windows` lazy DLL.

### Golden file — bump du hash figé (évolution mineure §9, SCIEMMENT)

- `state.v1.json` : ajout de 2 items réels en portée `session` — `printers`
  (`{cups_name, connection, description, location, is_default}`) et `drives` (`{letter, unc, label}`).
- `FROZEN_STATE_HASH` (PHP `ContractV1Test`) : `82095869…a7432` → `fe4cb1216da04ab7ad02215e6958251b72174d62923d545013b54487619c174e`.
- `frozenStateHash` (Go `hasher_test.go`) : bumpé à la MÊME valeur (test croisé NFR13 vert).
- `report.v1.json` : **INCHANGÉ** — il illustrait déjà un item `printers` en `error` (avec `detail`),
  exactement ce que la story demande pour figer l'isolation. (Un ajout d'item `drives` a été tenté puis
  retiré : il cassait `ReportEndpointTest` qui fige 4 items / `compliant:1,drift:1,drifted_allowed:1,error:1`.)

### Tests

- **Go** (`~/go-toolchain/go/bin/go`, depuis `agent/`) : `go test ./...` VERT (handler_printers_test.go +
  handler_drives_test.go = set cible, idempotence, suppression level-triggered, marqueur de périmètre,
  défaut imprimante + bascule, item `error` isolé + isolation inter-types via le moteur, payload invalide,
  machine d'états §5 table-driven, empreinte d'agrégat). `go vet ./...` (linux ET `GOOS=windows`) VERT.
  Cross-compile `GOOS=windows GOARCH=amd64 go build ./windows` VERT (10.4 Mo). Cohérence golden PHP↔Go OK
  (test croisé). 2 tests existants ajustés au nouveau golden : `contract_test.go` (session 2→4) +
  `hasher_test.go` (checked 3→5).
- **PHP** (hôte, PHP 8.4) : `PrintersStateProviderTest` (9 verts), `DrivesStateProviderTest` (9 verts),
  `StateCompilerTest::printers_same_printer_on_two_mailles_dedups_and_default_survives` (1 vert),
  `ContractV1Test` (5 verts). `--filter Agent` : **396 tests** (était 377, +19), **41 erreurs PRÉEXISTANTES**
  (toutes `ldap_search: Can't contact LDAP server` — host sans LDAP, baseline identique à 27.1) + **1 échec
  PRÉEXISTANT** non lié (`ReleaseEndpointTest::malformed_or_traversal_filenames` — traversal encodé renvoie
  500 au lieu de 404 sur l'hôte, concerne le download de releases, zéro lien avec 27.2). Les 4 échecs que mon
  golden avait introduits transitoirement sont RÉSOLUS. **À rejouer `--filter Agent` sur /vm** (host sans LDAP
  ni Vite → faux négatifs ; SQLite n'applique pas les varchar).

### Validation NFR7 / zéro retrofit legacy

- `grep -E 'ldap|apcu|get_apps|samba-tool|ad_users|ad_user_groups|LdapRecord'` sur les 2 providers → seules
  occurrences = commentaires documentant l'interdit (aucune ligne de code).
- `CupsPrinterService` et `ShareService` : **aucun diff git** (lus/projetés, jamais modifiés ni câblés au
  canal legacy). Aucun fichier du canal legacy lecteurs/imprimantes dans le diff.

### Points laissés à valider humainement

- **Migration VM Pending** : `2026_06_15_120000_add_is_default_to_printer_workstation_group.php` migre en
  SQLite (tests) mais reste **Pending sur la VM** (les migrations ne sont pas auto-jouées). Jouer
  `php artisan migrate` après `migrate:status`.
- **Validation lab poste Windows** (action Henri) : montage des lecteurs + installation des imprimantes,
  union multi-mailles, imprimante par défaut = WG physique > logique, suppression level-triggered, isolation
  serveur d'impression down → `error`. Runbook QA : `docs/qa/domains/agent.md` Section 14.
- Incident environnement pendant le dev : disque hôte saturé (100 %) a tronqué `docs/domains/printers.md` à
  1 ligne lors d'un write ; restauré depuis git puis ré-édité après libération d'espace — contenu intègre,
  vérifié.

## ⚠️ Écart de parité legacy `drives` — À ARBITRER AVANT 27.6 (relevé 2026-06-16)

> Relevé en revue avec Henri (comparaison du comportement legacy SE4 réellement servi vs ce que livre 27.2).
> **Sans effet tant que le canal legacy vit (27.6) ; devient visible utilisateur le jour de la bascule.**
> À trancher avant le décommissionnement legacy. Le dev de 27.2 N'A PAS re-tranché — c'est un fork de design.

**Constat factuel.** La décision n° 2 (T2) affirme « aucune convention de lettre historique trouvée dans le
legacy SE4 (`net use` legacy ne montait que `z:`) ». **C'est inexact** : la convention de lettres legacy EST
documentée dans `../sambaedu/individuel.php:44,59`. Le grep `net use` est passé à côté parce que le legacy ne
mappe PAS ces lettres par `net use` — K: vient du **profil itinérant Windows** (USERPROFILE), et H:/I:/L: de
**drive-maps poussées au logon** (script de logon / GPO drive-maps), côté Windows.

**Convention legacy réelle** (`individuel.php:44,59`) :

| Lettre | Cible legacy | Contenu |
|---|---|---|
| **K:** | `\\se4fs\users\<login>` (`/home/<login>`) | **Home perso** (Documents, Bureau, profil) — RW |
| **H:** | `\\se4fs\classes` = `/var/sambaedu/Classes` (**racine**) | **Toutes les classes**, vue ACL-filtrée |
| **I:** | `\\se4fs\docs` | Documentation partagée |
| **L:** | `\\se4fs\progs` (`ro/` + `rw/`) | Logiciels |

**3 divergences de 27.2 (MVP-A) vs legacy :**

1. **Périmètre.** 27.2 ne couvre QUE les classes. H: (classes racine), I: (docs), L: (progs) et le home
   perso **ne sont pas émis**. Au décommissionnement legacy (27.6), un poste perdrait I:/L: + le home tant
   qu'aucun provider ne les porte.
2. **Modèle de montage.** Legacy = **UNE** lettre (H:) sur la **racine** du partage classes → l'utilisateur
   navigue dans toutes les classes, les **ACL Samba filtrent** (lecture sur toutes, écriture sur son propre
   `Classe_X/<login>/` + équipe enseignante `rwx` — `../sambaedu/includes/partages.inc.php:372,498,506,567`).
   27.2 = **une lettre PAR classe**, pointée directement sur le sous-dossier `Classe_<nom>\<user>\` →
   plus de vue inter-classes par le lecteur ; un prof multi-classes obtient K:, L:, M:…
3. **Collision de lettre.** 27.2 réutilise **`K:`** pour les classes, alors que `K:` = home perso en legacy.

**Précision importante (NON un écart) :** les ACL ne changent pas — Samba reste seul maître des droits, 27.2
ne touche pas le FS. La sémantique « lecture sur toutes les classes / écriture sur mon dossier » est bien le
comportement historique (ACL legacy ci-dessus), pas une régression de droits. L'écart est sur le **mapping**
(lettres + modèle de navigation + couverture), pas sur les permissions.

**À trancher (Henri) avant 27.6 :**
- (a) **Modèle cible** : reproduire le legacy (H: racine ACL-filtrée + K: home + I:/L:) — parité 1:1 — ou
  assumer le nouveau modèle « une lettre par classe pointée sur le sous-dossier user » (rupture d'UX) ?
- (b) **Couverture** : qui porte home/docs/progs ? (nouveau(x) provider(s), ou hors-scope agent assumé ?)
- (c) **Lettre classes** : conserver `K:` (collision home à gérer) ou repasser à `H:` pour la parité ?

Tant que non tranché, **ne pas activer la bascule 27.6 pour `drives`** sous peine de régression visible
utilisateur (H: « toutes les classes » → K: « ma classe seulement », perte de I:/L:/home).

## File List

### Providers PHP (serveur, lecture seule)
- `app/Services/Agent/Providers/PrintersStateProvider.php` (NEW)
- `app/Services/Agent/Providers/DrivesStateProvider.php` (NEW)
- `app/Providers/AgentServiceProvider.php` (registry : +2 lignes)
- `app/Models/Printer.php` (const `TYPE_PRINTERS` + `withPivot('is_default')`)
- `app/Models/WorkstationGroup.php` (`printers()` : `withPivot('is_default')`)

### Migration
- `database/migrations/2026_06_15_120000_add_is_default_to_printer_workstation_group.php` (NEW)

### UI (toggle imprimante par défaut)
- `resources/views/pages/parc/groups/[id]/index.blade.php` (méthode Livewire `toggleDefaultPrinter()`)
- `resources/views/pages/parc/groups/[id]/_partials/printers-list.blade.php` (colonne + toggle)

### Handlers Go (purs testables hôte + spécifiques Windows + enregistrement)
- `agent/shared/handler_printers.go` (NEW)
- `agent/shared/handler_drives.go` (NEW)
- `agent/windows/handler_printers_windows.go` (NEW — winspool natif)
- `agent/windows/handler_drives_windows.go` (NEW — mpr natif)
- `agent/windows/companion_windows.go` (enregistrement `printers` + `drives`)

### Golden + contrat
- `tests/Fixtures/Agent/state.v1.json` (items `printers` + `drives` session)
- `tests/Unit/Services/Agent/ContractV1Test.php` (`FROZEN_STATE_HASH` bumpé)
- `agent/shared/hasher_test.go` (`frozenStateHash` bumpé + `checked` 3→5)
- `agent/shared/contract_test.go` (session 2→4)

### Tests
- `tests/Unit/Services/Agent/PrintersStateProviderTest.php` (NEW)
- `tests/Unit/Services/Agent/DrivesStateProviderTest.php` (NEW)
- `tests/Unit/Services/Agent/StateCompilerTest.php` (test dédup + défaut exclusif printers)
- `agent/shared/handler_printers_test.go` (NEW)
- `agent/shared/handler_drives_test.go` (NEW)

### Documentation + QA
- `docs/agent/state-providers.md` (sections `printers` + `drives`)
- `agent/README.md` (handlers `printers` + `drives`)
- `docs/qa/domains/agent.md` (Section 14, append-only)
- `docs/qa/README.md` (ligne agent enrichie 27.2)
- `docs/domains/printers.md` (pivot `is_default` + section « Canal agent desired-state »)

### Tracking
- `_bmad-output/implementation-artifacts/27-2-handlers-lecteurs-imprimantes.md` (statut, tâches, ce record)
- `_bmad-output/implementation-artifacts/sprint-status.yaml`

## Change Log

| Date | Auteur | Changement |
|---|---|---|
| 2026-06-16 | Henri + Claude | Relevé d'un **écart de parité legacy `drives`** (nouvelle section avant File List) : convention de lettres legacy H/K/I/L documentée (`../sambaedu/individuel.php:44,59`) contredisant la décision n° 2 ; 27.2 (MVP-A) ne couvre que les classes, change le modèle de montage (lettre/classe vs H: racine ACL-filtrée) et réutilise `K:` (= home legacy). ACL inchangées (non un écart). À arbitrer avant 27.6. |
| 2026-06-15 | DEV opus | Story 27.2 développée : providers `printers`+`drives` (lecture seule), migration pivot `is_default`, toggle UI défaut par WG, handlers agent Go (winspool/mpr natifs, level-triggered, marqueur de périmètre serveur SambaEdu, isolation par item), golden bumpé sciemment (PHP+Go croisé NFR13), tests + doc + QA. ready-for-dev → review. |

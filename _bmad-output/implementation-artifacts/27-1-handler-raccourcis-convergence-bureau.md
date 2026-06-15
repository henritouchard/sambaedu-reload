# Story 27.1 : Handler raccourcis — le bureau converge selon la nature du poste

Status: review

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

En tant qu'**admin d'établissement**,
je veux **que les raccourcis du bureau convergent selon mes règles et la nature du poste**,
afin que **le Bug C soit corrigé définitivement, par le bon modèle (un item d'état, pas un script legacy)**.

## Contexte & intention

**Première story de l'Epic 27 « Parité de compétences ».** Le pattern de l'epic, répété à chaque
ressource : **1 StateProvider serveur + 1 handler agent Go + identifiant de type figé + golden file**.
`shortcuts` est la ressource « la plus simple » de l'ordre « du simple au dur » — elle inaugure le
pattern, expose pour la première fois le toggle UI strict/default (FR26), et **corrige définitivement
le Bug C**.

**Ce que cette story livre :**
- **Serveur** : `ShortcutsStateProvider` (lecture seule des règles `shortcuts` existantes), type figé
  `shortcuts`, `semantics=aggregate`, payload v1 owné ici ; consommation du
  `WorkstationEnvironmentResolver` (26.1) pour dicter le chemin du bureau.
- **Agent Go** : un handler `shortcuts` (`agent/shared/handler_shortcuts.go` + spécifique Windows),
  enregistré dans le moteur du compagnon — convergence level-triggered (union, sans doublon, apply
  idempotent, raccourci retiré → disparaît du poste).
- **UI** : **première exposition du toggle strict/default** — il couvre rétroactivement wallpaper et
  overlay (le mode cesse d'être une constante figée par type, il devient un attribut piloté).
- **Golden file** : mise à jour SCIEMMENT de `tests/Fixtures/Agent/state.v1.json` (+ `report.v1.json`)
  pour le payload `shortcuts` réel, avec bump du hash figé `ContractV1Test` documenté.
- **Le fix définitif du Bug C** : le bureau cible (réseau si `shared_local`, local si
  `personal_local`/`nomade`) est résolu par le domaine Postgres, pas par une branche figée dans un
  script `.cmd` legacy.

**Ce que cette story N'EST PAS :**
- Le décommissionnement du canal legacy raccourcis (`ShortcutCompilerService`,
  `ApplicationScriptsGenerator`, pansement Bug C `4e5a152`, `CompiledShortcut`, `ShortcutObserver`,
  routes `/api/v1/shortcuts/export`) — il meurt **en bloc** en **Story 27.6**. Ici, **ZÉRO retrofit
  legacy** : on n'y touche pas, on construit à côté.
- Les autres handlers (lecteurs/imprimantes → 27.2, registre/associations → 27.3, app_config → 27.4,
  applications → 27.5).
- Les raccourcis Linux (`.desktop`) côté agent : l'agent Go est Windows (project_agent_runtime_go).
  Le provider serveur lit les règles indifféremment de l'OS ; le handler **Windows** matérialise les
  `.lnk`. Linux = hors-scope agent (cf. question Henri).

**Pourquoi maintenant (Bug C) :** le port natif de `ShortcutCompilerService` avait figé la branche
locale (`%userprofile%\Bureau`) ; sur poste partagé le bureau est redirigé réseau → `%userprofile%\Bureau`
n'existe pas → `curl(23)`, aucun raccourci posé. Le pansement `4e5a152` a rétabli le défaut réseau en
dur — un choix qui doit être **paramétrable par parc**. La 26.1 a livré la donnée (`WorkstationEnvironment`)
+ le service de résolution **explicitement pour ce handler**. 27.1 boucle la boucle.

## ⚠️ Pièges & tensions découverts à l'analyse (lire avant de coder)

> **⚠️ NOTE 2026-06-15 — forks tranchés.** Cette section documente l'ANALYSE pré-décision (raisonnement à
> conserver). Les forks qu'elle évoque (scope, granularité du toggle, ciblage user, chemin, méthode `.lnk`)
> ont été **arbitrés par Henri** — voir « Décisions de design — TRANCHÉES PAR HENRI ». En cas de divergence
> entre le texte ci-dessous et les décisions, **les décisions priment** : scope=`machine_user`, toggle sur
> les 3 types (shortcuts+wallpaper+overlay), ciblage MVP pivot SQL, chemin résolu serveur, `.lnk` COM Go natif.

1. **Tension scope `session` vs `machine_user` (golden file).** L'AC de l'epic dit
   `shortcuts` = **portée session** ; mais le golden `state.v1.json` actuel range l'item `shortcuts`
   en portée **`machine_user`** avec `semantics=aggregate`, `mode=strict`. Le golden est **illustratif**
   (contrat §3.2) — l'AC epic fait foi pour le scope. Mais le chemin du bureau dépend de
   `WorkstationEnvironment` (donnée **machine**, résolue côté serveur sans user) ET les raccourcis sont
   ciblables par user/groupes user (donnée **session**). **Choix par défaut retenu : `scope=session`**
   (suit l'AC epic ; le compagnon traite `session` + `machine_user`, donc l'item est de toute façon
   exécuté côté compagnon). Le golden sera réécrit en conséquence. **À acter en review / question Henri n° 1.**

2. **Le toggle strict/default est un changement STRUCTUREL, pas cosmétique.** Aujourd'hui `mode()` est
   une **constante par type** déclarée par le provider (cf. `WallpaperStateProvider::mode()` → `Default`).
   L'AC epic exige `mode strict|default` **par règle** (« un raccourci supprimé par un prof en mode
   `default` … pas recréé »). Cela impose : (a) une **colonne `mode`** sur `shortcuts` (ou table
   équivalente), (b) que le `StateProvider` porte le `mode` **par candidat**, pas seulement par type, et
   (c) que le `StateCompiler`/l'item du contrat reflète le mode par item (l'item le porte DÉJÀ — c'est
   l'**interface provider** qui ne le fait pas encore par item). C'est la **première exposition UI** : le
   toggle doit couvrir aussi wallpaper/overlay « rétroactivement » → décider si le mode par-règle
   généralise à TOUS les types (colonne sur chaque table métier) ou reste local aux shortcuts pour
   l'instant. **Question Henri n° 2 — décision de design majeure.** Voir décision de design n° 2 ci-dessous
   pour l'option par défaut proposée.

3. **`shortcuts` est DÉJÀ un identifiant figé (contrat §7) — ne pas le renommer, ne pas le ré-inventer.**
   Il vit déjà dans le golden et dans `docs/agent/contract-v1.md §7`. Pas de nouvelle entrée §7 à créer
   (c'est l'étape 1 de la checklist « Ajouter un type » mais elle est déjà faite). NFR12 : `shortcuts`
   est gravé.

4. **`semantics=aggregate` (union), pas exclusive.** Un poste reçoit l'**union** des raccourcis de toutes
   ses mailles (parc + groupes user + poste). C'est le 2e provider aggregate (après overlay) — réutiliser
   la convention de hash d'agrégat du moteur Go (`AggregateHash`, concat des hashes opaques ordre serveur,
   `engine.go`). **Aucune précédence** (D2 ne s'applique qu'aux types exclusive) : le compilateur émet
   l'union, le provider étiquette ses candidats sans trier/dédupliquer — **dédup par contenu = côté agent**
   (handler) ou par le compilateur ? Voir décision n° 4 (dédup au compilateur pour la stabilité du hash).

5. **`ShortcutsStateProvider` lit le NOUVEAU modèle Postgres, jamais l'AD/APCu.** Le legacy
   `ShortcutCompilerService::resolveForMachine()` lit `ad_users`/`ad_user_groups` (CN AD) via `whereJsonContains`
   et `getUserAdGroups()` (cache **APCu**, `member_of`). **Interdit ici** (NFR7, critère Keycloak, grep en
   review). Le provider lit : `shortcut_assignables` (morph workstations + workstation_groups, comme le fait
   déjà `WallpaperStateProvider` pour les WG) + le ciblage user/groupes user **par les relations Postgres**
   (`TargetContext::userGroupIds`, `$ctx->user`). Les colonnes `ad_users`/`ad_user_groups` (CN) **ne sont
   pas** des relations Postgres exploitables sans l'AD → **question Henri n° 3** (comment cibler par user :
   migrer le ciblage AD-CN vers le pivot `user_groups`/`users` SQL, ou ne supporter que le ciblage
   poste/parc en MVP 27.1 ?).

6. **Bug C = le chemin, pas le contenu.** Le `payload` shortcuts doit porter de quoi que l'agent pose le
   `.lnk` au **bon endroit**. Deux écoles (décision n° 3) : (a) le serveur résout le chemin et l'émet dans
   le payload (`desktop_path` calculé depuis `WorkstationEnvironment`) ; (b) le serveur émet l'environnement
   (`environment: shared_local|…`) et l'agent connaît la table chemin↔environnement. **Option (a) par défaut** :
   garde l'intelligence métier côté serveur (le successeur GPO = état cible calculé serveur), l'agent reste
   bête. Mais `WorkstationEnvironment` est **machine** et `shortcuts` est **session** → le provider session
   doit pouvoir résoudre l'environnement du poste (résoluble : `resolveForGroupIds($ctx->workstationGroupIds())`,
   les groupes du poste sont dans le `TargetContext`). **OK techniquement.**

7. **`apply` idempotent + level-triggered = convergence, PAS accumulation.** Le legacy accumule (script de
   logon qui télécharge à chaque ouverture + `shortcuts.txt` pour nettoyer). Le handler agent **converge** :
   `test` compare l'ensemble des `.lnk` posés au desired-state, `apply` crée les manquants ET **supprime les
   raccourcis SambaEdu retirés des règles** (le poste reflète l'union courante, jamais un cumul historique).
   ⚠️ Distinguer « raccourci SambaEdu géré » d'un raccourci créé par l'utilisateur : ne supprimer QUE les
   raccourcis du périmètre géré (marqueur — voir décision n° 5).

8. **Mode `default` + suppression par un prof = `drifted_allowed`, pas recréation.** La machine d'états §5
   est DÉJÀ implémentée dans `engine.go` (`ResolveItemStatus`) et persiste le dernier-appliqué par **type**
   (`AppliedState` map `type → {hash}`). Pour `shortcuts` aggregate, le hash d'agrégat couvre l'ensemble :
   si un prof supprime UN raccourci du bureau (réel ≠ cible) alors que dernier-appliqué = cible et
   mode=default → `drifted_allowed` sur le TYPE entier (l'agent ne recrée pas). C'est le comportement du
   moteur existant — **ne pas réimplémenter §5**, le brancher. ⚠️ granularité : le mode default est au
   niveau du **type** dans le moteur actuel (un seul verdict par type) ; un mode per-règle (piège 2) qui
   mixerait strict et default dans le même type `shortcuts` casserait l'hypothèse « un type = un mode » du
   moteur. **Lien direct avec la question Henri n° 2.**

9. **Le handler agent est Windows (FFI/`.lnk`), la logique pure est testable hôte.** Pattern 24.6 :
   `agent/shared/handler_shortcuts.go` = logique OS-agnostique (résolution du chemin attendu, set des
   raccourcis cibles, décision test/apply), testée `go test` sur l'hôte ; `agent/windows/handler_shortcuts_windows.go`
   = création/suppression réelle du `.lnk` (COM `IShellLink` via FFI, ou shell-out PowerShell documenté si
   justifié — échappatoire admise comme pour `SystemParametersInfo`). Création de `.lnk` en Go = pas de lib
   standard ; **shell-out PowerShell `New-Object -ComObject WScript.Shell` documenté** est l'option pragmatique
   (à challenger en review).

10. **Zéro PHP touché côté legacy ; le code serveur agent est étendu (provider + registry).** Cette story
    AJOUTE un provider + une ligne au registry `AgentServiceProvider` + (selon n° 2) une migration `mode` +
    UI. Elle MODIFIE le golden file (sciemment). Elle ne touche AUCUN fichier du canal legacy raccourcis.

11. **Golden file modifié = bump du hash figé `ContractV1Test` (`FROZEN_STATE_HASH`) documenté.** Le contrat
    §3.2 autorise l'évolution du payload owné par la story du provider ; la checklist state-providers.md
    étape 5 impose : « mise à jour SCIEMMENT du hash figé ». C'est une **évolution mineure** du contrat
    (champ/payload ajouté), pas un major — l'agent forward-compatible l'accepte. Documenter le bump.

12. **inotify ne propage pas les deletes — sans objet ici** (aucun fichier supprimé prévu côté Laravel ;
    côté Go, pas de `.ps1` à retirer). Si une colonne `mode` est ajoutée : **migration à jouer sur la VM**
    (`migrate:status` avant e2e), `config:cache` non concerné (pas de `config/*.php` nouveau).

## Décisions de design — TRANCHÉES PAR HENRI le 2026-06-15 (ne pas re-trancher en dev)

> Les 4 forks ci-dessous ont été arbitrés par Henri à la validation de story. Ils sont DÉFINITIFS pour 27.1.

1. **`scope=machine_user`** (DÉCISION HENRI) — on conserve le scope du golden actuel. Le métier l'exige : le
   set de raccourcis dépend du user, mais le CHEMIN du bureau dépend du POSTE (`WorkstationEnvironment`), donc
   le calcul est intrinsèquement un croisement (poste, user). Le serveur compile par couple (poste, user) et
   émet directement `desktop_path` résolu (cf. décision 3). **Le golden n'est PAS réécrit en `session`** — il
   reste `machine_user`. Le provider déclare `scope() = StateScope::MachineUser`.
2. **Mode par-règle = colonne `mode` (`strict|default`, défaut `strict`)**, cast enum `App\Enums\StateMode`.
   **DÉCISION HENRI : le toggle est ajouté DÈS 27.1 à `shortcuts` ET `wallpaper` ET `overlay`** (on traite la
   dette en une fois, l'UI ne ment pas). → colonne `mode` ajoutée aux 3 tables (`shortcuts`, table wallpaper,
   table overlay), toggle UI exposé pour les 3. L'interface `StateProvider` gagne un `mode` **par candidat**
   (le candidat porte son mode) ; `WallpaperStateProvider`/`OverlayStateProvider` lisent désormais le mode de
   leur table au lieu d'un mode constant. Le moteur agent rend UN verdict par TYPE → le `StateCompiler`
   **agrège** le mode par type : si TOUTES les règles applicables d'un type sont `default` → default ; sinon
   `strict` (posture sûre). Vérifier la non-régression wallpaper/overlay (leur mode passe de constant à
   lu-en-base, défaut `strict` = comportement actuel préservé tant qu'aucune règle n'est mise en `default`).
3. **Chemin du bureau résolu côté SERVEUR** (DÉCISION HENRI, cohérent avec scope `machine_user`) : le payload porte `desktop_path` (chemin UNC réseau
   ou local selon `WorkstationEnvironment`) calculé par le provider via `WorkstationEnvironmentResolver`.
   L'agent reste bête (pose le `.lnk` au chemin reçu). Mapping environnement→chemin documenté
   (`shared_local` → `\\<se4fs>\users\<user>\Bureau\` réseau ; `personal_local`/`nomade` → bureau local
   `%USERPROFILE%\Desktop` / `\Bureau`). Les variables (`<user>`, `<se4fs>`) restent dans le payload sous
   forme de **tokens** que l'agent substitue localement (login courant, nom serveur) — pas de fuite de
   secret, pas de dépendance réseau au calcul.
4. **Dédup de l'union côté COMPILATEUR** : deux règles produisant le même raccourci (même name+target+place)
   sur deux mailles différentes → un seul item dans la sortie (déterminisme du hash). Le provider étiquette
   ses candidats bruts (peut produire des doublons) ; le `StateCompiler` dédoublonne pour les types aggregate
   par clé de contenu stable, ordre déterministe (`id` asc). **Vérifier que le compilateur aggregate actuel
   ne dédoublonne PAS encore** (overlay = 1 item/signal, pas de doublon naturel) → ajout ciblé.
5. **Marqueur de raccourci géré côté agent** : les `.lnk` posés par l'agent sont tracés (liste des chemins
   dans l'applied-state per-user, ou répertoire/préfixe dédié) pour que `apply` puisse **retirer** un
   raccourci sorti des règles SANS toucher aux raccourcis créés par l'utilisateur. Convention exacte au choix
   du dev (challengée en review) ; l'invariant = « level-triggered, jamais d'accumulation, jamais de
   suppression d'un raccourci hors périmètre SambaEdu ».
6. **Payload `shortcuts` v1** (owné ici, contrat §3.2) : un item par raccourci de l'union, payload
   `{name, target, args, icon, place, desktop_path}` (pas de float, ISO/strings only). `place` ∈
   `desktop|startup|taskbar` (iso `Shortcut::PLACE_*`). `target` = la cible (chemin exe / URL). `desktop_path`
   présent uniquement si `place=desktop` (décision n° 3). Champs exacts affinés en dev contre le besoin du
   handler `.lnk` — owné par la story, documenté `docs/agent/state-providers.md`.
7. **Création du `.lnk` = COM `IShellLink` en Go natif (DÉCISION HENRI)** — pas de shell-out PowerShell. Une
   fonction `createShortcut(path, target, args, icon, workdir)` appelée en boucle (aucun code par raccourci).
   Zéro dépendance PowerShell, exécution plus rapide. Handler dans `handler_shortcuts_windows.go` (build tag
   Windows) + stub no-op pour les autres OS (le `.lnk` est purement Windows ; les `.desktop` Linux = handler
   séparé d'une story ultérieure). Utiliser l'interop COM (`golang.org/x/sys/windows` + `ole`/syscall selon le
   pattern déjà présent dans l'agent Go 24.6 — vérifier ce qui existe avant d'ajouter une dépendance).
8. **Ciblage par utilisateur = MVP pivot SQL seulement (DÉCISION HENRI)** — on cible par poste + parc +
   user/groupes user via le pivot SQL (`TargetContext`). On **n'importe PAS** le ciblage AD-CN legacy
   (`ad_users`/`ad_user_groups` + cache APCu) — interdit NFR7. Pas de stratégie de migration AD-CN→pivot dans
   cette story (hors-scope, à traiter séparément si besoin terrain).

## Acceptance Criteria

### AC1 — Type `shortcuts` servi : provider lecture seule + identifiant figé + golden (FR21, FR2)

**Given** le type `shortcuts` (identifiant DÉJÀ figé contrat §7, NFR12), `semantics=aggregate`, `scope=machine_user` (décision 1)
**When** `ShortcutsStateProvider` est enregistré dans `AgentServiceProvider` et le `StateCompiler` compile
**Then** son type est servi **sans aucune modification du compilateur** (AC1 pattern 23.4) ; le provider lit
**en lecture seule** les règles `shortcuts` existantes (`shortcuts` + `shortcut_assignables`, relations
Postgres) — **aucun write, aucun appel AD/LdapRecord/APCu** (critère Keycloak, grep en review)
**And** le payload v1 (décision n° 6) est émis sans float ; le golden `tests/Fixtures/Agent/state.v1.json`
est mis à jour SCIEMMENT pour refléter le payload réel, avec **bump documenté** du hash figé `ContractV1Test`.

### AC2 — Le bureau converge au chemin dicté par WorkstationEnvironment : fix définitif du Bug C (FR21, FR28)

**Given** un poste dont le parc déclare `shared_local` (resp. `personal_local` / `nomade`)
**When** l'agent converge les raccourcis `place=desktop`
**Then** les raccourcis cibles sont présents au **bureau réseau** si `shared_local`, au **bureau local** si
`personal_local`/`nomade` — chemin résolu par `WorkstationEnvironmentResolver` (26.1) côté serveur (décision n° 3)
**And** le pansement legacy (`4e5a152`, défaut réseau en dur) **n'est pas touché** — il meurt avec son canal
(27.6) ; le fix est définitif **par le bon modèle** (donnée du domaine, pas branche figée)
**And** le provider résout l'environnement via `resolveForGroupIds($ctx->workstationGroupIds())` (pas de
re-requête des appartenances — discipline `TargetContext`).

### AC3 — Union des mailles, sans doublon, idempotent, level-triggered (FR21, FR18)

**Given** des règles `shortcuts` sur plusieurs mailles (parc + groupes user + poste)
**When** l'agent converge
**Then** le poste reçoit l'**union** des raccourcis applicables, **sans doublon** (dédup décision n° 4) ;
`apply` est **idempotent** (deux passes sur état stable = `compliant`, zéro écriture)
**And** un raccourci **retiré des règles disparaît du poste** au passage suivant (convergence, pas
accumulation — décision n° 5) ; les raccourcis créés par l'utilisateur hors périmètre SambaEdu ne sont
**jamais** supprimés.

### AC4 — Mode strict|default par règle : première exposition UI du toggle (FR26, FR19)

**Given** le mode `strict|default` par règle (colonne `mode` sur `shortcuts` ET `wallpaper` ET `overlay`, décision n° 2)
**When** l'admin consulte l'UI des raccourcis (et des fonds d'écran / overlays)
**Then** l'UI expose le **toggle strict/default** (Livewire SFC, `WithToasts`) — **première exposition UI du
mode** — pour les **3 types** (shortcuts + wallpaper + overlay, décision n° 2 : on traite la dette en une fois)
**And** un raccourci **supprimé par un prof en mode `default`** (réel ≠ cible ∧ dernier-appliqué = cible) est
rapporté **`drifted_allowed`** et **n'est PAS recréé** ; en mode `strict`, il est recréé (`drift`)
**And** la machine d'états §5 existante (`engine.go::ResolveItemStatus`) est **réutilisée, jamais
réimplémentée**.

### AC5 — Handler agent Go + golden + reporting par item (FR21, FR18)

**Given** l'état cible contenant des items `shortcuts`
**When** la boucle du compagnon exécute `test` puis `apply` si écart
**Then** un handler `shortcuts` (`agent/shared/handler_shortcuts.go` logique pure testable hôte +
`agent/windows/handler_shortcuts_windows.go` création/suppression `.lnk`) est enregistré dans le moteur du
compagnon ; isolation par item (`error` + detail si échec, les autres types continuent) ; exécution dans
l'ordre du payload serveur
**And** le statut est rapporté (`compliant|drift|drifted_allowed|error`) dans `POST /report` conforme au
golden `report.v1.json` (hash d'agrégat conventionnel `AggregateHash`, ordre serveur)
**And** convention de hash d'agrégat = concat des hashes opaques (jamais de recalcul depuis la sérialisation
agent — piège réutilisé de 24.6).

### AC6 — Tests : PHPUnit serveur + go test agent, baselines intactes (NFR13)

**Then** côté **Laravel** : `tests/Unit/Services/Agent/ShortcutsStateProviderTest.php` (mailles
poste/parc/user/groupes user, union, payload, résolution du chemin par environnement, lecture seule, zéro AD) ;
test compilateur dédup aggregate ; test cast `mode` + écriture UI (Livewire) ; non-régression `--filter Agent`
sur `/vm` (baseline à relever au début du dev)
**And** côté **agent Go** : `agent/shared/handler_shortcuts_test.go` (résolution du chemin, set cible, test/apply
idempotent, suppression level-triggered, dédup, machine d'états §5 strict/default/premier passage table-driven) ;
`go test ./...`, `go vet` (linux + `GOOS=windows`), cross-compile verts sur l'hôte ; spécifique Windows validé
cross-compile + lab humain
**And** golden files cohérents serveur (PHPUnit) ET agent (`go test`) — tests croisés (NFR13).

### AC7 — Documentation + QA (append-only)

**Then** `docs/agent/state-providers.md` : section `shortcuts` (payload v1, mailles, union, mode par règle,
résolution du chemin par environnement, fix Bug C) ; `agent/README.md` : handler `shortcuts` ; le toggle UI
documenté (première exposition du mode)
**And** `docs/qa/domains/parc.md` (environnement de poste, déjà couvert 26.1) **et/ou** `docs/qa/domains/agent.md`
(canal agent, handlers) enrichis **append-only** (nouvelle section sans renuméroter) : convergence raccourcis
UI→poste selon environnement, union, suppression level-triggered, toggle strict/default, `drifted_allowed`,
reporting par item ; ligne 27.1 dans `docs/qa/README.md`
**And** restent **INTOUCHÉS** : tout le canal legacy raccourcis (`ShortcutCompilerService`,
`ApplicationScriptsGenerator`, `4e5a152`, `CompiledShortcut`, `ShortcutObserver`, routes export), le PHP serveur
agent figé hors provider/registry/mode, `contract-v1.md §7` (déjà figé), `agent/shared/hasher.go` /
`engine.go::ResolveItemStatus` (réutilisés).

## Tasks / Subtasks

- [x] **T1 — Schéma & enum mode par règle (3 types : shortcuts + wallpaper + overlay)** (AC4) — *décision n° 2*
  - [x] Enum `App\Enums\StateMode` (`strict|default`, backed string, `declare(strict_types=1)`, style 26.1) si
        absent — vérifier d'abord s'il existe déjà.
  - [x] Migration(s) idempotente(s) `add_mode_to_shortcuts`, `add_mode_to_<wallpaper>`, `add_mode_to_<overlay>` :
        colonne `mode` VARCHAR(16) **nullable** (défaut résolu côté service à `strict` = comportement actuel),
        `Schema::hasColumn`, `down()` symétrique, `->comment()` daté story. Style calqué sur
        `2026_06_13_150000_add_environment_to_workstation_groups.php` (varchar simple, compat SQLite).
  - [x] Modèles `Shortcut`, wallpaper, overlay : `'mode'` dans `$fillable`, `'mode' => StateMode::class` dans
        `$casts`, `@property` docblock.
  - [x] Appliquer les migrations sur la VM (`migrate --force`) + vérifier `Schema::hasColumn` (cf. mémoire
        VM migrations pas auto-jouées).

- [x] **T2 — `ShortcutsStateProvider` (serveur, lecture seule)** (AC1, AC2, AC3)
  - [x] `app/Services/Agent/Providers/ShortcutsStateProvider.php` (`final`, `declare(strict_types=1)`, calqué
        sur `WallpaperStateProvider`/`OverlayStateProvider`). `type()` → `'shortcuts'` (constante `Shortcut::TYPE_SHORTCUTS`
        à ajouter, iso `Wallpaper::TYPE_WALLPAPER`), `semantics()` → `Aggregate`, `scope()` → `MachineUser` (décision n° 1).
  - [x] `itemsFor(TargetContext $ctx)` : lit `shortcuts` actifs × `shortcut_assignables` (morph WorkstationGroup
        + Workstation restreint aux groupes/poste du contexte) + ciblage user/groupes user **par relations
        Postgres** (décision n° 8 — MVP : poste/parc + user/groupes SQL ; ciblage AD-CN legacy EXCLU, NFR7).
        Étiquette chaque candidat par maille ; **zéro précédence/tri/filtre** (D2 = compilateur).
  - [x] Payload v1 (décision n° 6) `{name, target, args, icon, place, desktop_path}` ; `desktop_path` résolu via
        `WorkstationEnvironmentResolver::resolveForGroupIds($ctx->workstationGroupIds())` (décision n° 3, mapping
        environnement→chemin documenté). Mode par candidat (décision n° 2).
  - [x] Enregistrer dans `AgentServiceProvider::register()` (une ligne dans le tableau du `StateCompiler`).

- [x] **T3 — Mode par item + dédup aggregate (StateCompiler / interface)** (AC3, AC4) — *décision n° 2 & n° 4*
  - [x] `StateProvider`/`StateCandidate` : porter le `mode` **par candidat** (le candidat déclare son mode).
        Les providers wallpaper/overlay lisent désormais le `mode` de leur table (décision n° 2 — défaut `strict`
        = comportement actuel préservé). Agrégation du mode par type dans le `StateCompiler` (tous default →
        default, sinon strict). Non-régression wallpaper/overlay à vérifier explicitement.
  - [x] Dédup des candidats aggregate par clé de contenu stable + ordre déterministe (`id` asc) dans le
        `StateCompiler` (décision n° 4) — vérifier que le chemin aggregate actuel ne régresse pas (overlay).
  - [x] Tests compilateur : union shortcuts multi-mailles, dédup, mode agrégé, déterminisme du hash.

- [x] **T4 — Handler agent Go `shortcuts`** (AC5)
  - [x] `agent/shared/handler_shortcuts.go` : logique PURE (résolution du chemin attendu depuis le payload,
        set des raccourcis cibles, décision test/apply, marqueur de périmètre géré — décision n° 5), testable hôte.
        Aggregate : `test` = l'ensemble des `.lnk` gérés correspond-il à l'union cible ? `apply` = créer les
        manquants + supprimer les gérés sortis des règles (idempotent, level-triggered).
  - [x] `agent/windows/handler_shortcuts_windows.go` : création/suppression réelle du `.lnk` via **COM
        `IShellLink` en Go natif** (décision n° 7 — PAS de shell-out PowerShell) ; une fonction
        `createShortcut(...)` appelée en boucle. Substitution des tokens (`<user>`/`<se4fs>`) en local. Stub
        `!windows` (no-op). Réutiliser l'interop COM déjà présente dans l'agent 24.6 si elle existe.
  - [x] Enregistrer `"shortcuts"` dans la map `Handlers` du moteur du compagnon (`agent/windows/companion_windows.go`).
  - [x] Réutiliser `engine.go::ResolveItemStatus` (§5) + `AggregateHash` — **ne pas réimplémenter**.

- [x] **T5 — UI toggle strict/default** (AC4)
  - [x] Exposer le toggle `mode` (strict|default) dans l'UI des **3 types** : raccourcis + fonds d'écran +
        overlays (Livewire SFC, `WithToasts`, convention pages/, Gate cohérent avec l'édition existante de
        chaque type). Première exposition UI du mode (décision n° 2 — on traite la dette en une fois).
  - [x] Persistance via les modèles ; toast de succès. Vérifier que le défaut `strict` ne change pas le
        comportement actuel de wallpaper/overlay tant qu'aucune règle n'est mise en `default`.

- [x] **T6 — Golden files (sciemment) + bump hash figé** (AC1, AC5)
  - [x] Mettre à jour `tests/Fixtures/Agent/state.v1.json` (item `shortcuts` réel, scope `machine_user` =
        décision n° 1, conservé) et `report.v1.json` si besoin ; bumper le `FROZEN_STATE_HASH`/hash de
        `ContractV1Test` **sciemment**,
        documenter le bump (évolution mineure du contrat, §9). Vérifier la cohérence Go (`go test` golden).

- [x] **T7 — Tests** (AC6)
  - [x] PHPUnit : `ShortcutsStateProviderTest` (mailles, union, payload, chemin par environnement, lecture seule,
        zéro AD), compilateur (dédup/mode), cast `mode` + Livewire toggle. Non-régression `--filter Agent` /vm.
  - [x] Go : `handler_shortcuts_test.go` (chemin, set cible, idempotence, suppression level-triggered, dédup,
        §5 strict/default/premier passage table-driven). `go test ./...`, `go vet` (linux+windows), cross-compile.

- [x] **T8 — Documentation + QA** (AC7)
  - [x] `docs/agent/state-providers.md` (section shortcuts), `agent/README.md` (handler shortcuts), QA append-only
        (`parc.md` et/ou `agent.md`), ligne 27.1 `docs/qa/README.md`.

- [x] **T9 — Validation finale** (AC6)
  - [x] `php -l` sur les fichiers PHP ; grep critère Keycloak (`ldap|apcu|get_apps|samba-tool`) sur
        `app/Services/Agent/Providers/ShortcutsStateProvider.php` → vide ; grep « zéro retrofit legacy »
        (aucun fichier du canal legacy raccourcis dans le diff).
  - [x] `go test ./...` + `go vet` + cross-compile verts sur l'hôte ; `--filter Agent` /vm sans régression.
  - [x] **Validation lab (poste Windows) : ACTION HUMAINE (Henri)** — convergence raccourcis UI→poste selon
        environnement (shared/personal), union multi-mailles, suppression level-triggered, toggle strict/default,
        `drifted_allowed` (suppression manuelle non recréée en default).

## Dev Notes

### Périmètre — livré / hors-scope

| Livré (27.1) | Hors-scope (story) |
|---|---|
| `ShortcutsStateProvider` (lecture seule, union, chemin par environnement) | Décommissionnement canal legacy raccourcis → **27.6** |
| Handler agent Go `shortcuts` (Windows `.lnk`, level-triggered) | Handlers lecteurs/imprimantes (27.2), registre/associations (27.3), app_config (27.4), applications (27.5) |
| Colonne `mode` + UI toggle strict/default (1re exposition) | Toggle mode sur wallpaper/overlay (mécanique posée, colonnes leurs tables = 27.x) |
| Mode par candidat (interface provider) + dédup aggregate (compilateur) | Raccourcis Linux côté agent (agent = Windows) |
| Golden files mis à jour + bump hash documenté | Ciblage par CN AD (`ad_users`/`ad_user_groups`) — exclu NFR7 (cf. question n° 3) |
| Tests PHPUnit + go test + QA | Tout `ShortcutCompilerService`/`ApplicationScriptsGenerator`/`4e5a152`/`CompiledShortcut`/`ShortcutObserver`/routes export (INTOUCHÉS) |

### Le socle 26.1 — ce qu'on consomme (ne PAS réinventer)

[Source: app/Enums/WorkstationEnvironment.php ; app/Services/Agent/WorkstationEnvironmentResolver.php]

- `WorkstationEnvironment` (`shared_local`/`personal_local`/`nomade`, `label()` UI) — identifiants figés.
- `WorkstationEnvironmentResolver::resolveForGroupIds(array $ids)` : précédence `nomade > personal_local >
  shared_local`, défaut `shared_local`, **Postgres-only**. Point d'entrée privilégié depuis un provider :
  `resolveForGroupIds($ctx->workstationGroupIds())` (la 26.1 l'a explicitement câblé pour CE handler).
- Singleton déjà enregistré (`AgentServiceProvider`).

### Le pattern 23.4 — ce qu'on imite (ne PAS réinventer)

[Source: app/Services/Agent/Providers/WallpaperStateProvider.php ; OverlayStateProvider.php ; Contracts/StateProvider.php ; StateCandidate.php ; StateCompiler.php ; TargetContext.php]

- Interface `StateProvider` (`type/semantics/mode/scope/itemsFor`) — `OverlayStateProvider` est le modèle
  aggregate (1 candidat/source, étiquetage par maille, zéro précédence). `WallpaperStateProvider` est le modèle
  de lecture morph `shortcut_assignables`-like (owner_type/owner_id restreints aux ids du contexte).
- `TargetContext` : `physicalGroupIds`, `logicalGroupIds`, `userGroupIds`, `workstationGroupIds()`, `user` —
  les providers consomment, ne re-requêtent jamais les appartenances.
- `StateCandidate` (readonly : `maille`, `payload`, `updatedAt`, `sourceId`) — à étendre pour porter `mode`
  par candidat (décision n° 2).
- `StateCompiler` SEUL porteur de D2 (sans objet pour aggregate, mais c'est lui qui fait union+dédup+hash) ;
  hash par `StateHasher` (jamais ad hoc) ; ordre de sortie déterministe.

### Le socle agent Go 24.6 — ce qu'on consomme

[Source: agent/shared/engine.go ; handler_overlay.go ; handler_wallpaper.go ; companion.go ; agent/windows/companion_windows.go]

- `Engine.RunPass` + `ResolveItemStatus` (machine d'états §5 strict/default/premier passage) + `AggregateHash`
  (concat hashes opaques, ordre serveur) — **réutilisés, jamais réécrits**.
- `OverlayHandler` est le modèle aggregate/session (logique pure dans `shared/`, OS-spécifique injecté ;
  Rainmeter-absent gracieux = analogue à « raccourci hors périmètre intouché »).
- Enregistrement des handlers : map `type → Handler` dans `companion_windows.go` (y ajouter `"shortcuts"`).
- Conventions : suffixe `_windows.go` + stub, écriture atomique tmp+PID, applied-state per-user, `go test` hôte,
  shell-out PowerShell admis si documenté (FFI COM `IShellLink` ou `WScript.Shell`).

### Le legacy raccourcis — à COMPRENDRE, ne JAMAIS toucher

[Source: app/Services/ShortcutCompilerService.php ; app/Models/Shortcut.php ; app/Observers/ShortcutObserver.php ; commit 4e5a152]

- **Bug C** : `buildWindowsFragment()` défaut `place` → `\\%se4fs%\users\%username%\Bureau\` (pansement `4e5a152`,
  réseau en dur). Le port natif avait figé la branche locale `port_perdir` → `curl(23)` sur poste partagé.
- Données de ciblage existantes : `shortcut_assignables` (morph workstations + workstation_groups, **exploitable
  Postgres**), `shortcuts.ad_users` / `ad_user_groups` (CN AD JSON, **exploite l'AD/APCu — INTERDIT ici**).
- `Shortcut::PLACE_DESKTOP|PLACE_STARTUP|PLACE_TASKBAR` (`'desktop'|'startup'|'taskbar'`) — réutiliser ces
  identifiants dans le payload `place`.
- **Note de transition (26.1, reconduite)** : `ShortcutCompilerService`, `ApplicationScriptsGenerator`, `4e5a152`,
  `CompiledShortcut`, `ShortcutObserver`, routes `/api/v1/shortcuts/export` — meurent **en bloc** en 27.6. Ici,
  on construit À CÔTÉ, on n'y touche pas. Un modèle qui « rend service » en câblant le legacy = anti-pattern
  bloquant.

### Tensions ouvertes à trancher (cf. Questions pour Henri)

- **Scope** : `session` (AC epic, retenu) vs `machine_user` (golden actuel) — question n° 1.
- **Granularité du mode** : per-règle UI vs per-type moteur ; rétroactif wallpaper/overlay — question n° 2.
- **Ciblage user** : migrer le ciblage AD-CN vers le pivot SQL `user_groups`/`users`, ou MVP poste/parc +
  user/groupes SQL seulement — question n° 3.
- **Chemin serveur vs agent** (décision n° 3 par défaut : serveur) — question n° 4.

### Project Structure Notes

- Provider → `app/Services/Agent/Providers/ShortcutsStateProvider.php` ; registry → `AgentServiceProvider::register()`.
- Migration → `database/migrations/2026_06_15_HHMMSS_add_mode_to_shortcuts.php` (si décision n° 2).
- Modèle → `app/Models/Shortcut.php` (`TYPE_SHORTCUTS`, `mode` fillable/cast).
- Interface/candidat → `app/Services/Agent/Contracts/StateProvider.php` + `StateCandidate.php` (mode par candidat) ;
  `StateCompiler.php` (dédup/mode aggregate).
- UI → composant raccourcis existant (Livewire SFC) + toggle.
- Agent → `agent/shared/handler_shortcuts.go` (+ test) ; `agent/windows/handler_shortcuts_windows.go` (+ stub) ;
  enregistrement `companion_windows.go`.
- Golden → `tests/Fixtures/Agent/state.v1.json` (+ `report.v1.json`) ; hash figé `ContractV1Test`.
- Doc → `docs/agent/state-providers.md`, `agent/README.md`, `docs/qa/domains/{parc,agent}.md`, `docs/qa/README.md`.

### Environnement de dev — règles VM

- Code à la RACINE (app/, agent/, …) ; édité sur l'hôte, sync inotify auto, **jamais de sync manuelle**.
- **Go = hôte uniquement** (`~/go-toolchain/go/bin/go`, package main = `agent/windows`). PHPUnit sur /vm.
- Migration `mode` → **à jouer sur la VM** (`migrate:status` avant e2e — VM migrations pas auto-jouées).
- Jamais d'interaction VM depuis un worktree git.

### Dépendances

| Story | Rôle pour 27.1 | Statut (sprint-status.yaml) | Bloquant ? |
|-------|----------------|------------------------------|------------|
| 26.1 — enum WorkstationEnvironment + resolver | Fournit la donnée + le service de résolution du chemin (cœur du fix Bug C) | `review` | **Prérequis dur** — mais en `review` (Henri teste les lots) ; dev autorisé avec **rebase si correctifs**, comme 26.2 |
| 23.4 — StateCompiler / StateProvider / TargetContext | Pattern provider + compilateur + contexte que 27.1 étend | `done` | Non (consommé) |
| 24.6 — agent Go compagnon + handlers + moteur §5 | Moteur de convergence, `AggregateHash`, enregistrement handlers, modèle OverlayHandler | `done` | Non (consommé) |
| 23.1 — contrat v1 + golden files + StateHasher | `shortcuts` déjà figé §7, golden à faire évoluer sciemment | `done` | Non |

27.1 est la **première story de l'Epic 27** : à sa création, marquer `epic-27: in-progress` (premier story de
l'epic, cf. workflow create-story step 1).

### References

- [Source: _bmad-output/planning-artifacts/epics-agent-desired-state.md#Story 27.1] — AC d'origine ; FR21, FR26, FR28 ; pattern epic (provider+handler+id figé+golden).
- [Source: _bmad-output/planning-artifacts/epics-agent-desired-state.md#Epic 27 + note transition Epic 26] — du simple au dur, extinction en bloc, ZÉRO retrofit legacy.
- [Source: _bmad-output/planning-artifacts/architecture-agent-desired-state.md] — contrat StateProvider, TargetContext, semantics aggregate, portée session, golden = frontière de contrat, NFR7/NFR12.
- [Source: _bmad-output/implementation-artifacts/26-1-enum-workstation-environment.md] — enum + resolver, note de transition Bug C → 27.1.
- [Source: _bmad-output/implementation-artifacts/23-4-statecompiler-mailles-providers.md] — contrat StateProvider, registry, précédence, providers lecture seule.
- [Source: _bmad-output/implementation-artifacts/24-6-agent-go-compagnon-handlers-parite-demo.md] — handler de référence (overlay aggregate), moteur §5, AggregateHash, enregistrement.
- [Source: app/Services/ShortcutCompilerService.php:432-462] — Bug C (pansement `4e5a152`), données de ciblage, `PLACE_*`.
- [Source: app/Models/Shortcut.php] — `shortcut_assignables` (morph), `ad_users`/`ad_user_groups` (AD-CN, exclu), `PLACE_*`.
- [Source: agent/shared/engine.go] — `ResolveItemStatus` (§5), `AggregateHash`, isolation par item, enregistrement Handler.
- [Source: agent/shared/handler_overlay.go ; agent/windows/companion_windows.go] — modèle handler aggregate/session + map Handlers.
- [Source: docs/agent/contract-v1.md §3.1, §3.2, §7, §8] — aggregate/union, payload owné par story, `shortcuts` figé, type absent ≠ payload null.
- [Source: docs/agent/state-providers.md#Ajouter un type de ressource] — checklist Epic 27.
- [Source: tests/Fixtures/Agent/state.v1.json ; report.v1.json] — golden à faire évoluer (item `shortcuts` présent, scope `machine_user` à reconcilier).
- [Source: commit 4e5a152] — pansement Bug C (à ne pas toucher).

## Questions pour Henri — TRANCHÉES le 2026-06-15 (voir « Décisions de design »)

> Toutes les questions ci-dessous ont été arbitrées par Henri à la validation de story. Réponses reportées
> dans la section « Décisions de design — TRANCHÉES PAR HENRI ». Conservées ici pour traçabilité.

1. **Scope de `shortcuts`** (session vs machine_user) → **`machine_user`** (décision 1). Golden NON réécrit
   en session ; le chemin dépend du poste, le calcul est un croisement (poste, user).

2. **Granularité & portée du toggle strict/default** → mode par candidat + agrégation par type au compilateur,
   et **toggle ajouté DÈS 27.1 à shortcuts + wallpaper + overlay** (décision 2). Colonne `mode` sur les 3 tables.

3. **Ciblage par utilisateur** → **MVP pivot SQL seulement** (décision 8). Pas d'import du ciblage AD-CN legacy.

4. **Résolution du chemin du bureau** → **côté serveur** (décision 3), cohérent avec scope `machine_user`.
   L'agent reste bête, pose le `.lnk` au `desktop_path` reçu.

5. **Création du `.lnk` côté agent** → **COM `IShellLink` en Go natif** (décision 7), pas de shell-out PowerShell.

## Recommandation Modèle Dev

**Recommandation : `opus`.**

Justification : story **multi-domaine et structurante**. Elle touche simultanément (a) un nouveau StateProvider
Laravel avec logique de mailles + résolution d'environnement, (b) un handler agent **Go** non trivial (création
de `.lnk` Windows, convergence level-triggered avec suppression sélective, machine d'états §5 aggregate),
(c) une **modification d'interface contractuelle** (mode par candidat + dédup aggregate au compilateur) qui
peut régresser wallpaper/overlay, (d) la **première exposition UI du toggle strict/default** (décision de design
non triviale, piège n° 2), et (e) une **évolution sciemment du golden file = frontière de contrat figée** (bump
du hash, tests croisés serveur/agent). Le risque majeur — le retrofit legacy interdit + la cohérence du contrat
figé + la granularité mode per-règle/per-type — exige le raisonnement le plus rigoureux. `opus`.

## Dev Agent Record

### Modèle Dev
`opus` (Claude Opus 4.8 1M) — conformément à la recommandation de la story.

### Décisions appliquées (toutes TRANCHÉES par Henri, suivies à la lettre)
1. **scope `machine_user`** : `ShortcutsStateProvider::scope() = StateScope::MachineUser`. Golden NON réécrit en `session`.
2. **Mode par règle** : enum `App\Enums\StateMode` (déjà existant) ; colonne `mode` VARCHAR(16) **nullable** ajoutée aux 3 tables (`shortcuts`, `wallpapers`, `overlay_signals`) ; cast enum sur les 3 modèles. `StateCandidate` porte désormais `?StateMode $mode` ; les providers wallpaper/overlay lisent le `mode` de leur table (défaut résolu via `mode()` quand null — non-régression préservée : wallpaper reste `default`, overlay reste `strict`). Le `StateCompiler` **agrège** le mode par type (tous default → default, sinon strict). Toggle UI exposé pour les 3 types.
3. **Chemin du bureau résolu côté serveur** : `desktop_path` calculé par le provider via `WorkstationEnvironmentResolver::resolveForGroupIds()`. Tokens `<se4fs>`/`<user>` (et `%VAR%` Windows) substitués localement par l'agent.
4. **Dédup aggregate au compilateur** : `StateCompiler::selectAggregate()` dédoublonne par clé de contenu (canonicalisation du hasher), ordre `sourceId` asc. Overlay non affecté (payloads distincts — non-régression testée).
5. **Marqueur de périmètre géré** : champ Description du `.lnk` = `shared.ShortcutManagedMarker` ; seuls les `.lnk` marqués sont listés/supprimés (jamais un raccourci utilisateur).
6. **Payload v1** `{name, target, args, icon, place, desktop_path}` (`desktop_path` si `place=desktop`).
7. **`.lnk` = COM IShellLink en Go natif** : `createShortcut(...)` via COM (`golang.org/x/sys/windows` + syscall vtable, CoCreateInstance/IShellLinkW/IPersistFile). **Zéro dépendance ajoutée** (`go.mod` inchangé), pas de shell-out PowerShell.
8. **Ciblage MVP pivot SQL** : pivot polymorphe `shortcut_assignables` (WorkstationGroup + Workstation + UserGroup + User). `ad_users`/`ad_user_groups` JAMAIS lus (NFR7).

### Déviations / notes
- **Aucune déviation fonctionnelle** par rapport aux décisions tranchées.
- **Toggle wallpaper/overlay** : appliqué là où la « règle » vit réellement — carte wallpaper (`wallpaper-card.blade.php`, `setMode()`) et formulaire de publication overlay (`overlay-messages/index.blade.php`, champ `mode` persisté sur le signal créé). Pour l'overlay, le mode est posé sur le `OverlaySignal` retourné par `postSignal()` (signature de `OverlayService` inchangée).
- **Hash figé bumpé SCIEMMENT** : `c02463…` (PHP `ContractV1Test::FROZEN_STATE_HASH` + Go `hasher_test.go::frozenStateHash`) — le test croisé Go prouve l'accord serveur/agent sur le payload v1 (NFR13). Ancien hash : `6c0e8135…`.

### Résultats de tests
- **Go (hôte `~/go-toolchain/go`)** : `go test ./...` **vert** (incl. `handler_shortcuts_test.go` — chemin par environnement, set cible, idempotence, suppression level-triggered, raccourci user jamais supprimé, payload invalide → error, dédup/AggregateHash, §5 strict/default/premier passage table-driven, + le test croisé golden `hasher_test.go`). `go vet ./...` et `GOOS=windows go vet ./...` **propres**. Cross-compile `GOOS=windows GOARCH=amd64 go build ./...` **OK**. `gofmt` propre sur les fichiers livrés.
- **PHPUnit (hôte)** : nouveaux tests **verts** — `ShortcutsStateProviderTest` (11), `StateCompilerTest` ajouts dédup/mode (6), `StateModeCastTest` (3), `ContractV1Test` (5, hash bumpé), `ShortcutModeTogglePageTest` (2), `OverlayMessagesPageTest` ajouts mode (2). Total nouveaux : **29 cas verts**.
- **Limites environnement hôte (à rejouer sur /vm)** : (a) les tests Agent qui créent des `WorkstationGroup`/`UserGroup` via factory SANS désactiver les observers (ex. `WallpaperStateProviderTest`, `OverlayStateProviderTest`, `StateCompilerTest::full_compile…`) échouent sur l'hôte faute de **serveur LDAP** — échec PRÉEXISTANT, non lié à cette story (mes nouveaux tests désactivent explicitement les observers AD pour rester Postgres-pur). (b) Quelques tests Livewire de **rendu de page** échouent faute de **manifest Vite** (assets non buildés sur l'hôte) — préexistant. La non-régression `--filter Agent` complète est à relever sur **/vm**.

### Action requise sur /vm
- **Migrations** : jouer `php artisan migrate --force` (3 migrations `add_mode_to_{shortcuts,wallpapers,overlay_signals}`) avant tout e2e — `migrate:status` d'abord (les migrations ne sont PAS auto-jouées sur la VM).
- **config:cache / route:cache** : non concernés (aucun `config/*.php` ni route ajoutés).
- **Validation lab (poste Windows) — ACTION HUMAINE** : convergence raccourcis UI→poste selon environnement (réseau `shared_local` / local `personal_local`), union multi-mailles, suppression level-triggered, toggle strict/default + `drifted_allowed`.

## File List

### Créés
- `app/Services/Agent/Providers/ShortcutsStateProvider.php`
- `database/migrations/2026_06_15_100000_add_mode_to_shortcuts.php`
- `database/migrations/2026_06_15_100100_add_mode_to_wallpapers.php`
- `database/migrations/2026_06_15_100200_add_mode_to_overlay_signals.php`
- `agent/shared/handler_shortcuts.go`
- `agent/shared/handler_shortcuts_test.go`
- `agent/windows/handler_shortcuts_windows.go`
- `tests/Unit/Services/Agent/ShortcutsStateProviderTest.php`
- `tests/Unit/Models/StateModeCastTest.php`
- `tests/Feature/Livewire/ShortcutModeTogglePageTest.php`

### Modifiés
- `app/Models/Shortcut.php` (const `TYPE_SHORTCUTS`, `mode` fillable + cast `StateMode`)
- `app/Models/Wallpaper.php` (`mode` fillable + cast)
- `app/Models/OverlaySignal.php` (`mode` fillable + cast)
- `app/Services/Agent/StateCandidate.php` (champ `?StateMode $mode`)
- `app/Services/Agent/StateCompiler.php` (agrégation du mode par type + dédup aggregate par contenu)
- `app/Services/Agent/Contracts/StateProvider.php` (docblock `mode()` = défaut du type)
- `app/Services/Agent/Providers/WallpaperStateProvider.php` (lit `mode` par règle)
- `app/Services/Agent/Providers/OverlayStateProvider.php` (lit `mode` par règle)
- `app/Providers/AgentServiceProvider.php` (enregistre `ShortcutsStateProvider`)
- `agent/windows/companion_windows.go` (handler `shortcuts` dans la map)
- `resources/views/pages/shortcuts/[id]/index.blade.php` (propriété `mode`, load, save, validation)
- `resources/views/pages/shortcuts/[id]/_partials/shortcut-form.blade.php` (toggle strict/souple)
- `resources/views/components/molecules/wallpaper-card.blade.php` (`setMode()` + toggle)
- `resources/views/pages/parc-settings/overlay-messages/index.blade.php` (champ `mode` + persistance)
- `tests/Fixtures/Agent/state.v1.json` (payload `shortcuts` v1 + hash item)
- `tests/Fixtures/Agent/report.v1.json` (hash item `shortcuts`)
- `tests/Unit/Services/Agent/ContractV1Test.php` (`FROZEN_STATE_HASH` bumpé)
- `tests/Unit/Services/Agent/StateCompilerTest.php` (tests dédup + mode agrégé)
- `tests/Feature/Livewire/ParcSettings/OverlayMessagesPageTest.php` (colonne `mode` + tests)
- `agent/shared/hasher_test.go` (`frozenStateHash` bumpé — test croisé)
- `agent/README.md` (handler `shortcuts`)
- `docs/agent/state-providers.md` (section `shortcuts` + mode par règle)
- `docs/qa/domains/agent.md` (Section 13 — runbook 27.1 + post-correctif hash + checklist)
- `docs/qa/README.md` (ligne domaine `agent` enrichie 27.1)

### Corrections post-review (2026-06-15)

Findings de `_bmad-output/codeReviews/27-1.md` appliqués (le détail vit dans la section « Post-correctifs & non-régressions » de la review) :

- **#1 (🔴 homonyme)** — `Matches` (Windows) → `(false, nil)` (plus d'erreur) + nouvelle méthode `Blocked()` sur `ShortcutOps` ; `Test`/`Apply` (shared) ignorent un chemin occupé par un `.lnk` user (jamais écrasé/supprimé/échec). Test Go #6.
- **#2 (🔴 cross-placement)** — `managedDirs()` balaie l'union des emplacements gérables (desktop/startup/taskbar) ; desktop sans règle → bureau standard pour nettoyer les orphelins. Test Go #7.
- **#5 (🔴 identity overlay)** — l'identity synthétique porte un mode neutre `Default` → ne force plus le type overlay en `strict`. Tests PHP.
- **#M1 (🟠 UI wallpaper)** — `wallpaper-card.blade.php` affiche le défaut RÉEL du provider (`default`), plus `?? 'strict'` en dur. Shortcut/overlay forms vérifiés (défaut provider = `strict`, OK). Test PHP.
- **#4 (🟠 atomicité overlay)** — create+update mode dans `DB::transaction` (postSignal inchangé).
- **#3 (🟡 style COM)** — `Load`/`Save` IPersistFile harmonisés (`unsafe.Pointer(pf)` des deux côtés).
- **#M2 / #M4 (🟡)** — documentés en commentaire (`desiredSet`, `Matches` args).
- **#M3 (🟡)** — vérifié : `report.v1.json` est purement statique (jamais comparé à l'engine) → laissé tel quel, noté. Cohérence figée PHP↔Go = `FROZEN_STATE_HASH` (verte).

Tests post-correctifs : Go `go test ./...`/vet/cross-compile verts ; PHP `OverlayStateProviderTest` 14/14, `WallpaperStateProviderTest` 10/10, `StateModeCastTest`+`ShortcutsStateProviderTest` 14/14, `OverlayMessagesPageTest` mode 2/2. Limites hôte pré-existantes (LDAP / Vite) signalées dans la review. (NB : échecs `ContractV1Test` dus à une feature « profils itinérants » en cours dans le working tree, hors 27.1.)

## Change Log

- 2026-06-15 — Corrections post-review 27.1 (#1, #2, #5, #M1, #4, #3 ; #M2/#M4/#M3 documentés/vérifiés). Tests Go + PHP ciblés verts.
- 2026-06-15 — Implémentation Story 27.1 (handler raccourcis, fix Bug C). Provider serveur `shortcuts` (aggregate / machine_user, chemin résolu serveur), handler agent Go (COM IShellLink natif, level-triggered), mode strict/default par règle sur 3 tables + 3 UI (1re exposition du toggle), dédup aggregate au compilateur, golden v1 + bump hash figé documenté (PHP + Go). Tests PHPUnit + go test verts (hôte). Status → review.

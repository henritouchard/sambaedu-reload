# StateProviders & compilation d'état — `se5.desired-state/v1`

> Story 23.4 — Epic 23 (agent desired-state). Complète `contract-v1.md` (le
> wire format, FIGÉ) sans le modifier : ce document décrit le **côté serveur**
> de la compilation — comment l'état cible d'un (poste, user) est calculé
> depuis les tables métier existantes, et comment on ajoute un type.
> Le compilé est servi par `GET /api/v1/agent/state` — voir `state-endpoint.md`.

## Vue d'ensemble

```
GET /state (23.5) ──► StateCompiler::compile(TargetContext)
                            │
                            │  interroge le registry (AgentServiceProvider)
                            ▼
              StateProvider (1 par type de ressource)
              ├── WallpaperStateProvider  (lit wallpapers × wallpaper_assets)
              └── OverlayStateProvider    (lit overlay_signals)
                            │
                            ▼  candidats bruts étiquetés par maille
              StateCompiler — SEUL porteur de D2 :
              spécificité, union, conflits, hash, enveloppe v1
```

- **D1** : l'état cible est une **projection pure** des tables métier — les
  écrans d'administration existants SONT la source, aucune table générique de
  règles, aucune ressaisie.
- **D2** : la sémantique de merge (union aggregate / précédence exclusive) est
  implémentée **une fois**, dans `StateCompiler`. Un provider qui trie, filtre
  par maille ou applique la précédence lui-même est une **violation bloquante
  en review** (architecture, Enforcement Guidelines).

## L'interface `StateProvider`

`App\Services\Agent\Contracts\StateProvider` :

```php
interface StateProvider {
    public function type(): string;                    // identifiant figé (§7, NFR12)
    public function semantics(): ResourceSemantics;    // aggregate | exclusive
    public function scope(): StateScope;               // machine | session | machine_user
    public function itemsFor(TargetContext $ctx): Collection; // candidats bruts par maille
}
```

> **Story 27.8** : la méthode `mode()` a été **retirée** de l'interface (le
> mécanisme `mode` strict/default est supprimé — convergence STRICT
> inconditionnelle). L'item du contrat n'a plus de clé `mode`.

`itemsFor()` retourne des `StateCandidate` (readonly) : `maille`
(enum interne `App\Enums\StateMaille`), `payload`, `updatedAt` + `sourceId`
(récence pour l'arbitrage des conflits + ids loggés). **Pas** des items
finaux : le compilateur applique D2, calcule `hash` via
`StateHasher::hashItem()` et assemble l'enveloppe.

Règles du provider :

- **Lecture seule** sur les tables métier — aucun write, aucun appel
  AD/LdapRecord/APCu (critère Keycloak, NFR7).
- Il restreint sa requête aux règles **applicables au contexte**
  (appartenances déjà résolues dans `TargetContext`) et étiquette chaque
  candidat par maille — c'est de l'applicabilité et de l'étiquetage, pas de
  la précédence.
- Jamais de float dans un payload (contrat §4.1) ; dates en ISO 8601 UTC.

## `TargetContext`

`App\Services\Agent\TargetContext::for(Workstation $ws, ?User $user)` résout
**une fois** les appartenances depuis Postgres (pivot 4.11 +
`user_group_user`) : `physicalGroupIds` (salles, `is_physical = true`),
`logicalGroupIds` (parcs), `userGroupIds`. Les providers consomment ces
listes et ne re-requêtent jamais les appartenances.

`$user` est **nullable** : compilation machine-only (check-in boot, 23.5) —
les mailles user sont alors vides, aucune erreur. Ne pas confondre avec
`App\Dto\Wallpaper\WallpaperContext` (canal legacy, hydraté depuis APCu).

## Chaîne de spécificité (extension de D2, décision 23.4 n° 1)

D2 ne figeait que la partie machine. La chaîne **complète**, tranchée en 23.4
puis **INVERSÉE GLOBALEMENT par la Story 27.3 (D-Q3)** sur le couple
logique/physique :

```
user > groupes user > poste > WG LOGIQUE > WG PHYSIQUE > broadcast
```

**Inversion D-Q3 (27.3) :** le **parc LOGIQUE** est une **sélection délibérée de
postes** (transverse aux salles) → plus spécifique que la **salle PHYSIQUE**. Cet
ordre s'applique à **TOUS** les types exclusifs (registry, wallpaper) ET à la
résolution du défaut `printers` (alignée séparément côté provider). Avant 27.3 :
`… WG physique > WG logique …`.

Rationale iso-legacy : `WallpaperResolver` plaçait déjà le user et ses groupes
au-dessus de la salle et de l'étab — un wallpaper personnel bat celui de la
salle, comportement connu des admins. La distinction legacy « type principal vs
groupe AD » s'écrase en UNE maille `groupes user` (divergence douce assumée : un
conflit entre deux groupes devient une règle intra-maille, ci-dessous).

- **Type `aggregate`** : UNION des candidats de toutes les mailles
  applicables (la spécificité ne joue pas).
- **Type `exclusive`** : la maille la plus spécifique gagne. **Conflit
  intra-maille** : la règle la plus récente gagne (`updated_at` desc, tiebreak
  `id` desc — déterminisme du hash) + warning loggé `agent.state.conflict`
  (channel `agent`, contexte `workstation_id`, `type`, maille, ids des
  règles). Le warning n'est émis que pour la maille gagnante.

Le rang de spécificité vit dans `StateCompiler::specificity()` **seul** —
ni dans l'enum `StateMaille`, ni dans les providers.

## Déterminisme (exigence ETag, story 23.5)

Deux compilations du même état à des instants différents → même
`StateHasher::hashState()` (seul `generated_at` est volatil). Garanties :

- providers traités par `type` asc (`strcmp`), indépendamment de l'ordre
  d'enregistrement dans le registry ;
- intra-type aggregate : candidats triés par `sourceId` asc (overlay : `id`
  asc des signaux) ;
- conflits arbitrés avec tiebreak `id` desc (jamais d'ambiguïté à
  `updated_at` égal) ;
- aucun float, aucune clé volatile hors `generated_at`.

L'ordre de sortie est fixé par le serveur et significatif : le hasher ne trie
pas les listes (contrat §4).

## Payloads v1

> La sous-structure de `payload` est ownée par cette story (contrat §3.2) ;
> les payloads des golden files sont **illustratifs** et ne changent pas.

### `wallpaper` — `exclusive` / `session`

```json
{ "asset": "9aa326c3….jpg", "checksum": "9aa326c3…" }
```

- `asset` : filename content-addressed de la bibliothèque
  (`<checksum>.<ext>`, cf. `WallpaperAsset::libraryPath()`), `checksum` :
  SHA-256 du fichier — ce que le handler compare en `test`.
- `{"asset": null, "checksum": null}` = règle explicite « pas de fond
  imposé » (contrat §8) — distinct du type absent (« aucune règle »).
- Mailles lues : broadcast (`owner_id` null + `is_default`), WG physique ou
  logique (owner `WorkstationGroup`, départagé par `is_physical`), groupe
  user (owner `UserGroup`), user (owner `User`).
- **Décision 24.4 : PAS de champ `url`.** La route de serving existe
  (`GET /api/v1/agent/assets/wallpaper/{filename}`, route
  `agent.v1.assets.wallpaper` — cf. `handlers-wallpaper-overlay.md`) mais le
  payload reste `{asset, checksum}` : l'agent construit l'URL depuis
  `server_url` + chemin documenté, comme pour `/state` et `/report`. Évite
  un churn d'ETag sur tous les contextes au déploiement ; un champ `url`
  resterait possible plus tard sans casse (champ ajouté = mineur, §9).

### `overlay` — `aggregate` / `session`

Un item **par signal posté actif** (`overlay_signals`, union) :

```json
{ "kind": "info", "severity": "warning", "title": "…", "text": "…",
  "expires_at": "2026-06-12T08:00:00+00:00" }
```

**+ depuis 24.4, un item synthétique `identity`** (uniquement en contexte
user — jamais en machine-only) :

```json
{ "kind": "identity", "login": "mdupont", "fullname": "Marie Dupont",
  "room": "salle_201" }
```

- `expires_at` : ISO 8601 UTC ou null. Signal expiré = exclu à la
  compilation (l'état change réellement → l'ETag change : correct).
- Maille d'un signal : `user_login` → user ; `workstation_uuid` → poste ;
  `workstation_group_id` → WG (physique/logique selon le groupe) ; tout
  null → broadcast. Multi-critères → maille la plus spécifique (étiquette
  cohérente pour logs/tests ; sans incidence de précédence, aggregate).
- Item `identity` (décision 24.4 n° 4) : `fullname` = users (fallback
  login), `room` = nom du WG **physique** du poste (null sans salle) —
  données STABLES (l'ETag ne bouge que si elles bougent), maille User,
  `sourceId` 0 (sort en tête de l'union). Permet au handler overlay de
  composer « identité user + parc » sans appel AD côté poste (critère
  Keycloak). Champ de payload owné par le provider (§3.2) — pas une
  évolution d'enveloppe. Les alertes dérivées volatiles (quota,
  multi-session) restent HORS desired-state.

### `shortcuts` — `aggregate` / `machine_user` (Story 27.1)

Un item **par couple (raccourci actif × assignation applicable)** — union des
mailles, dédoublonnée par contenu au compilateur (décision n° 4) :

```json
{ "name": "Intranet", "target": "https://intranet.example.edu",
  "args": "", "icon": "",
  "place": "desktop",
  "desktop_path": "\\\\<se4fs>\\users\\<user>\\Bureau\\" }
```

- **Lecture Postgres PURE** (`shortcuts` × `shortcut_assignables`, morph
  WorkstationGroup + Workstation + UserGroup + User restreint au
  `TargetContext`). Le ciblage AD-CN legacy (`ad_users`/`ad_user_groups`,
  cache APCu) n'est **JAMAIS** lu (NFR7, critère Keycloak — décision n° 8).
  Raccourci `is_active = false` = exclu.
- **`desktop_path` = fix définitif du Bug C.** Résolu **côté serveur**
  (décision n° 3) via `WorkstationEnvironmentResolver::resolveForGroupIds()`
  (26.1) — bureau **réseau** si le parc est `shared_local`
  (`\\<se4fs>\users\<user>\Bureau\`), bureau **local** si
  `personal_local`/`nomade` (`%USERPROFILE%\Desktop\`). Plus de branche figée
  dans un `.cmd` legacy : c'est la donnée du domaine qui dicte le chemin. Les
  tokens `<se4fs>` / `<user>` (et les `%VAR%` Windows) restent dans le payload :
  l'agent les substitue **localement** (login courant, nom serveur) — aucune
  fuite de secret, aucune dépendance réseau au calcul. `desktop_path` n'est
  présent **que** pour `place=desktop` (startup/taskbar = chemins standards
  résolus par l'agent).
- **`scope=machine_user`** (décision n° 1) : le set dépend du user, le chemin du
  poste — le calcul est un croisement (poste, user). **`semantics=aggregate`** :
  le poste reçoit l'union de toutes ses mailles, sans précédence.
- **Convergence level-triggered** côté agent (handler Go `shortcuts`) : un
  raccourci retiré des règles **disparaît** au passage suivant ; un raccourci
  créé par l'utilisateur (hors marqueur de gestion) n'est **jamais** supprimé
  (cf. `agent/README.md`, décision n° 5).
- **Drift policy : STRICT inconditionnel** (Story 27.8) — le mécanisme
  `mode` strict/default est **retiré** (colonne `shortcut_assignables.mode`
  droppée). L'assignation ne porte plus de mode ; la cible fait toujours loi
  (cf. la note « Mode strict|default — RETIRÉ » plus bas).
- `place` ∈ `desktop|startup|taskbar` (iso `Shortcut::PLACE_*`). Tous les champs
  sont des strings (jamais de float, §4.1).

**Icône UPLOADÉE → asset statique content-addressed (Story 27.7).** Le champ
`icon` peut porter soit un **chemin réel** (`firefox.exe,0`, `%APPDATA%\x.ico` —
géré par `ParseIconLocation` côté agent, INCHANGÉ depuis 2.2.1), soit le **nom
NU** d'un raccourci dont l'admin a **uploadé** une icône (`Calculatrice` — le
`.ico` réel vit côté serveur). Le provider reproduit la détection legacy
`!preg_match('#[\\/.,%]#', $icon)` (`ShortcutCompilerService:187`) : aucun
séparateur de chemin/index ⇒ nom nu. Quand c'est un nom nu **ET** qu'un asset
content-addressed existe en base (`shortcuts.icon_asset` non null), le payload
**ajoute** deux champs (forward-compatible) :

```json
{ "name": "Calculatrice", "target": "C:\\Windows\\System32\\calc.exe",
  "args": "", "icon": "Calculatrice",
  "icon_asset": "3a7b…abcd.ico", "icon_checksum": "3a7b…abcd",
  "place": "desktop", "desktop_path": "\\\\<se4fs>\\users\\<user>\\Bureau\\" }
```

- **PAS de champ `url`** (décision n° 4, iso 24.4) : l'agent **dérive** l'URL
  depuis `server_url + "/assets/shortcut-icons/" + icon_asset` (Alias Apache
  statique scopé, cf. `agent/README.md` et `config/apache/sambaedu.conf`).
- **Transport STATIQUE, pas token'd** (≠ wallpaper) : un `.ico` est un blob
  public-safe ; l'agent fait un **GET HTTP simple** (sans token), vérifie le
  SHA-256 = `icon_checksum` **AVANT** écriture locale, dépose
  `%PROGRAMDATA%\SambaEdu\Agent\icons\<sha>.ico` (content-addressed,
  idempotent). Le content-addressing + checksum **EST** la garantie
  d'intégrité.
- **Lecture de colonnes pures** au render (`icon_asset`/`icon_checksum` lus,
  jamais hashés à la volée — le checksum est calculé à l'**upload**/au
  **backfill** et persisté). Invariant perf des providers préservé.
- **Convergence gracieuse** : un nom nu **sans** asset backfillé
  (`icon_asset` null) tombe sur `icon` brut (ancien comportement), JAMAIS un
  asset cassé ; côté agent, un `.ico` local manquant/checksum KO ⇒ raccourci
  posé **sans IconLocation** (icône défaut), drift + re-sync au cycle suivant,
  JAMAIS d'icône « feuille blanche » ni d'erreur bloquant les autres
  raccourcis.
- **Backfill** : `php artisan shortcuts:backfill-icons` content-adresse les
  icônes uploadées **existantes** (name-addressed `<name>.ico` → `<sha>.ico`,
  copie jamais déplacement, dédup checksum) et renseigne les colonnes.
- **Hors-scope** : le canal wallpaper garde son transport token'd
  (`AssetController`) — non migré (décision n° 5).

### `printers` — `aggregate` / `session` (Story 27.2)

Un item **par (imprimante × maille POSTE applicable)** — union des mailles
(salle physique + parc logique), dédoublonnée par contenu au compilateur :

```json
{ "cups_name": "imp-salle101",
  "connection": "\\\\<se4fs>\\imp-salle101",
  "description": "HP LaserJet salle 101",
  "location": "Salle 101",
  "is_default": true }
```

- **Lecture Postgres + CUPS PURE** (`printer_workstation_group` restreint aux
  `physicalGroupIds` + `logicalGroupIds` du `TargetContext`). L'imprimante est
  une **ressource de POSTE** (Vérité #9 « l'imprimante de la salle ») : il
  n'existe **aucune** relation `UserGroup → Printer`, le ciblage est purement
  par maille POSTE. Aucun appel AD/LdapRecord/APCu (NFR7, critère Keycloak).
- **`connection` = connexion LOGIQUE** (décision n° 4) : le partage Samba
  imprimante `\\<se4fs>\<cups_name>`, **jamais** l'URI back-end CUPS
  (`socket://…`, `ipp://…`). `CupsPrinterService::getPrinter()` est lu
  **uniquement** pour la métadonnée (`description`/`location`) — jamais
  d'écriture CUPS, jamais l'URI live. CUPS injoignable à la compilation =
  métadonnée vide, l'imprimante reste servie (la connexion logique est stable).
  Le token `<se4fs>` est substitué **localement** par l'agent.
- **`is_default` = sous-item `exclusive`** (drapeau de PAYLOAD, pas une
  sémantique de type — décision n° 5) : réglé **explicitement** par l'admin sur
  l'attachement imprimante↔WG (colonne pivot `is_default`), valable pour un WG
  **physique comme logique**. L'unicité (un seul défaut par poste) est résolue
  **côté serveur** : parmi les WG porteurs d'un défaut applicables au poste, le
  **WG physique l'emporte sur le logique** ; départage déterministe `cups_name`
  asc à spécificité égale. **UN SEUL** `is_default: true` dans la collection.
  L'agent applique bêtement `SetDefaultPrinter` sur l'item marqué — il ne
  recalcule **jamais** la spécificité.
  > **⚠️ Invariant de garde (review 27.2, M3)** : `is_default` doit être résolu
  > **GLOBALEMENT par le provider** (un seul gagnant calculé sur l'ensemble des
  > mailles), **jamais** porté tel quel depuis le pivot brut au payload par maille.
  > Raison : la dédup aggregate du compilateur fusionne par CONTENU de payload. Si
  > deux candidats de la **même** imprimante (rattachée à 2 WG) portaient des
  > `is_default` divergents (`true` côté WG-défaut, `false` côté autre WG), leurs
  > payloads différeraient → **non dédupliqués** → l'agent recevrait l'imprimante
  > en double. La résolution globale garantit le même `is_default` sur tous les
  > candidats d'une imprimante donnée, donc la dédup tient. Ne pas régresser cet
  > invariant lors d'un refactor.
- **`scope=session`** (la connexion imprimante est per-user) ;
  **`semantics=aggregate`** (union sans précédence). Convergence level-triggered
  côté agent : une imprimante retirée des règles est **désinstallée** au passage
  suivant ; une imprimante installée par l'utilisateur hors périmètre SambaEdu
  n'est **jamais** désinstallée (marqueur de périmètre = serveur SambaEdu).
  > **Périmètre géré = serveur SambaEdu (décision Henri 27.2, review F1)** : toute
  > connexion per-user dont la cible est `\\<se4fs>\…` est considérée **gérée**
  > (donc désinstallable si hors règles), **y compris** une connexion que
  > l'utilisateur aurait ajoutée lui-même vers le serveur SambaEdu. L'agent ne
  > touche **jamais** une connexion vers un **autre** serveur (`ListManaged` filtre
  > par préfixe du serveur SambaEdu). Choix assumé : « connexion vers le serveur
  > SambaEdu = du ressort de SambaEdu ».
- **Retrait du défaut (décision Henri 27.2, review F2/M1)** : si l'admin **décoche**
  le défaut sur tous les WG (plus aucun `is_default: true` au payload), l'agent
  **laisse l'imprimante par défaut Windows en place** — Windows impose toujours UNE
  par-défaut, sans cible naturelle vers quoi rebasculer. Ni `Apply` ni `Test` ne
  touchent au défaut quand aucune cible n'est marquée (figé par
  `TestPrintersDefaultRemovedLeavesCurrentInPlace`). Une éventuelle « remise à zéro
  active » du défaut est laissée à une story ultérieure (point backlog).
- **Isolation des erreurs** : serveur d'impression injoignable à l'`apply` →
  statut `error` + détail pour le SEUL type `printers` ; `drives` et les autres
  types continuent (engine `RunPass` §5 réutilisé). Retry au cycle suivant.

### `drives` — `aggregate` / `session` (Story 27.2, MVP-A)

Un item **par classe du user** (projection des partages de classe existants —
**pas de table SQL**, décision n° 1 MVP-A) :

```json
{ "letter": "K:",
  "unc": "\\\\<se4fs>\\Classe_3emeA\\<login>\\",
  "label": "Classe 3emeA" }
```

- **Projection en lecture seule** : aucun modèle `Share`, aucune table de
  partages, aucune notion de lettre de lecteur dans le codebase — les partages
  de classe sont **filesystem-truth** (`/var/sambaedu/Classes/Classe_<name>`,
  gérés par `ShareService`, **lu/projeté jamais modifié**). Le provider DÉRIVE
  les montages depuis les **classes du user** (`UserGroup type='classe'` parmi
  les `userGroupIds` du `TargetContext`) — maille `user_group`. Aucun ciblage
  AD-CN (NFR7).
- **Lettres conventionnelles figées serveur** (décision n° 2) : aucune
  convention legacy historique n'existe (le `net use` SE4 ne montait que `z:`
  pour l'installeur WPKG). Convention retenue : **`K:` = classe** (incrément
  `K:`, `L:`, `M:`… par classe, ordre déterministe par nom asc). `H:` (home
  user) est **réservé** pour une projection future et non émis ici.
- **UNC tokenisé** : `\\<se4fs>\Classe_<name>\<login>\` (iso legacy
  `Classes/Classe_<name>/<login>`). Le préfixe `Classe_` est normalisé via
  `ShareService::bareClassName()` (pas de double préfixe). Tokens `<se4fs>` /
  `<login>` substitués **localement** par l'agent.
- **Émis PARTOUT** (décision n° 6) : indépendamment du `WorkstationEnvironment`
  (le resolver 26.1 n'est **pas** consommé) — un montage réseau est réseau par
  nature, y compris sur poste local/nomade. Contexte machine-only (user null) =
  aucun lecteur (un montage de classe dépend du login).
- **`scope=session`** ; **`semantics=aggregate`**. Convergence level-triggered :
  un mapping retiré des règles est **démonté** ; un lecteur monté par
  l'utilisateur hors périmètre SambaEdu n'est **jamais** démonté (marqueur de
  périmètre = serveur SambaEdu).

### `registry` — `exclusive` PAR IDENTITÉ DE CLÉ / `machine` + `session` (Story 27.3 → **rewrite capability-first 27.12**)

> **🔁 Story 27.12 — le registre n'est plus une table d'authoring : c'est une
> PROJECTION de capacité.** L'admin manipule désormais une **capacité** (intention
> métier OS-agnostique — « Afficher les extensions », « Bureau à distance »,
> « MAJ Windows gérées »…), jamais une clé de registre. La table centrale est
> `capabilities` ; le registre est une `capability_projections` (mechanism
> `registry`). **Le payload `registry`, le `StateCompiler`, le contrat et le
> handler Go sont INCHANGÉS** (D3) : l'item reste `{hive, path, name, type,
> value}`. Le rewrite est borné à l'authoring + la compilation côté serveur.
> Les anciennes `registry_settings`/`registry_setting_assignables` + providers
> `Registry{Machine,User}StateProvider` sont **superseded** (droppées).

#### Modèle capacité → projection → item (3 couches, D2)

- **Capacité** (`capabilities`) : intention métier. Porte le modèle de valeur
  (`value_type` = toggle/enum/scalar, `options`), le **défaut diffusé**
  (`default_value`), les métadonnées (`warning`, `applies_to_os`), `is_active`,
  `overrides_locked`. Le `key`/`id` ne fuite **JAMAIS** au payload (invariant
  central, piège n°1).
- **Projection** (`capability_projections`, unique `(capability_id, os,
  mechanism)`) : COMMENT l'intention se matérialise sur un `os` via un `mechanism`
  (= `type` du contrat). `registry` est publié (gratuit) ; un nouveau mécanisme
  (firewall/localgroup) = ajout au contrat figé + handler Go (hors 27.12).
- **Item de contrat** : concret, mécanisme-typé, ce que l'agent reçoit déjà.

#### Interpréteur de `spec` (D5 — le cœur)

Une projection registry porte `spec = { "keys": [ {hive, path, name, type, value},
… ] }`. Pour chaque clé **de la ruche du provider**, `value` est résolu contre la
**valeur effective de capacité** (`assignment.value ?? default_value`, D4) :

- **littéral** (scalaire OU liste détectée par `array_is_list($value)`, ex.
  MULTI_SZ `["a","b"]`) → **toujours émis** quand la capacité s'applique ;
- **map** valeur-capacité → donnée (objet assoc, ex. `{"on":0,"off":1}`) → on
  cherche la valeur effective ; **clé de map absente ⇒ la clé n'est PAS émise**
  (= cesser de gérer cette clé, ex. bundle on-only `{"on":1}` : un override `off`
  n'émet rien). Disambiguïsation map vs littéral-MULTI_SZ via `array_is_list`.

Puis **coercition par `type`** (DWORD/QWORD→int, MULTI_SZ→liste de chaînes,
SZ/EXPAND_SZ→chaîne) pour le contrat §4.1 (**zéro float**).

> ⚠️ **Coin liste vide.** `array_is_list([]) === true` → un littéral `value: []` est
> traité comme **littéral liste** (MULTI_SZ vide ÉMIS), PAS comme « cesser de gérer ».
> Pour « cesser de gérer » une clé selon la valeur de capacité, utiliser **une map**
> sans la valeur effective (ex. on-only `{"on":1}`), jamais `[]`.

#### Compilation (inchangée)

- **DEUX providers, UN handler Go.** `RegistryMachineCapabilityProvider`
  (`hive=HKLM`, `scope=Machine`) et `RegistryUserCapabilityProvider`
  (`hive=HKCU`, `scope=Session`) ; logique commune dans
  `AbstractCapabilityStateProvider`. Côté agent : UN seul handler Go `registry`
  (HKLM par le service SYSTEM, HKCU par le compagnon). Note **HKCR** : routé en
  `HKCU\Software\Classes\…` (vue per-user, ex. `onedrive_hidden`).
- **Broadcast (défaut diffusé) + override par maille (D4).** Le provider émet, par
  capacité applicable : (1) un lot de candidats **Broadcast** pour `default_value`
  (`sourceId` = `capability.id`, maille `Broadcast`, sans `mailleFor()`) ; (2) un
  lot par maille par assignation applicable, valeur effective = `assignment.value
  ?? default_value`. Candidats **BRUTS** (aucune précédence/tri/dédup). La
  précédence existante (`logique > physique > broadcast`) fait que l'override bat
  le défaut **pour cette clé**.
- **Bundle = une capacité → N candidats.** Une projection de N clés produit N
  candidats (un par clé émise), tous au même `sourceId`. Deux capacités définissant
  la **même** clé → collision arbitrée par la récence au compilateur.
- **Exclusive PAR IDENTITÉ DE CLÉ** (`KeyedExclusiveProvider`,
  `exclusiveKey={hive,path,name}` insensible à la casse) — inchangé.
- **Lecture Postgres pure** (NFR7) : `capabilities` actives (projection registry
  windows) × `capability_assignments` restreint aux ids du `TargetContext`. Aucun
  AD/APCu/`samba-tool`.
- **« Retirer un override = revenir au défaut » (D4).** Supprimer la ligne
  `capability_assignments` fait re-converger le poste vers `default_value` au
  cycle suivant (PAS « cesser de gérer »). Métadonnées d'authoring (`options`,
  `warning`, `overrides_locked`) = serveur/UI uniquement, **jamais** au payload.
- **Rapport unique-type** : les deux portées émettant `registry`, l'agent fusionne
  par type avant le POST /report (`MergeReportItemsByType`, pire statut gagne).

#### Limites connues (Story 27.3 — review)

- **`REG_EXPAND_SZ` comparé en littéral non développé.** `RegistryHandler.Equal`
  compare la chaîne cible telle quelle (NFC), et `registryOps.Read` lit la valeur
  BRUTE non développée (écriture via `SetExpandStringValue`). Si un autre acteur a
  stocké la valeur DÉJÀ développée (`C:\Users\…` au lieu de `%USERPROFILE%\…`) ou
  avec une casse de variable différente, l'agent verra un **drift permanent** et
  réécrira à chaque cycle. Le set initial du catalogue n'a aucun `REG_EXPAND_SZ` →
  impact nul aujourd'hui ; à garder à l'esprit quand le catalogue grossit par data.
- **`registry` machine + session collapsé en UNE ligne d'état serveur.** Le contrat
  §6 exige des types uniques au rapport ; quand un poste a des clés HKLM (machine)
  ET HKCU (session), les deux verdicts sont fusionnés (`MergeReportItemsByType`,
  pire statut gagne) → l'ingestion écrit UNE ligne `agent_resource_states(poste,
  'registry')`. Conséquences : (a) si le hash dominant alterne entre les deux
  portées d'un cycle à l'autre, un `AgentReportEvent` peut être émis sans dérive
  « réelle » d'une ressource unique ; (b) un seul `detail` survit → on ne distingue
  pas côté serveur « HKLM en erreur » de « HKCU en erreur ». Acceptable en v1 ;
  un raffinement futur (sous-type `registry:machine`/`registry:session` ou portée
  au rapport) lèverait la limite.

### `associations` — `exclusive` PAR IDENTIFIANT / `session` (Story 27.3bis)

Successeur natif du volet poste `associations.ps1`/`SFTA.ps1` (le canal legacy
`gpo/associations_out.php` reste intouché, il meurt en 27.6). Table catalogue
**DÉDIÉE** `file_associations` (D1, iso `registry_settings`) + pivot
`file_association_assignables` (morph WorkstationGroup/Workstation/UserGroup/User).

- **UN provider, portée session.** `AssociationsStateProvider` (`scope=Session`) :
  l'association vit sous `HKCU` (UserChoice de l'utilisateur connecté), appliquée
  par le **compagnon** au logon. Pas de pendant machine (contrairement à
  `registry`) — l'association par défaut est intrinsèquement per-user.
- **Catalogue → items CONCRETS.** Chaque association se **compile** en un payload
  `{identifier, progid, type}` concret (cf. `contract-v1.md` §7.2). 🔴 **Invariant
  central** : le `key`/`id` du catalogue ne fuite **JAMAIS** au payload. 🔴 **Le
  hash UserChoice n'est JAMAIS au payload** : il dépend du SID/timestamp/GUID
  « user experience » du poste → calculé **100 % côté agent** (piège n° 2).
- **Exclusive PAR IDENTIFIANT** (`KeyedExclusiveProvider`). Une extension/un
  protocole = UN programme par défaut ; le `StateCompiler` groupe les candidats par
  `exclusiveKey(payload)` = `identifier` (insensible à la casse) et arbitre CHAQUE
  groupe : la maille la plus spécifique gagne **pour cet identifiant** (D-Q3 : WG
  logique > WG physique), les identifiants distincts **s'accumulent**. Même
  mécanisme générique que `registry` — zéro modification du compilateur.
- **Lecture Postgres pure** (NFR7) : catalogue × pivot restreint aux ids du
  `TargetContext`. Aucun AD/APCu/`samba-tool`. ⚠️ **NE réutilise PAS** la
  dépendance APCu/WPKG de `AssociationsResolver` (16.3c) — le canal desired-state
  lit Postgres et lui seul.
- **Couplage app installée (D-Henri n°5).** Le provider **émet** l'item tel quel
  (pas de filtre WPKG, découplé de 27.5). Côté agent : si le **ProgId cible n'est
  pas enregistré** sur le poste, le handler **ne supprime pas et ne réécrit pas**
  la clé UserChoice existante → **choix utilisateur préservé** (pas de clobber),
  statut `error` **non fatal** (`detail` = « ProgId X non enregistré, choix
  utilisateur conservé »), **pas de réécriture en boucle**.
- **Reproduction legacy (D-Henri n°4).** Migration de seed (baseline figée
  FirefoxHTML/FirefoxURL + visionneuse photos, iso fixture
  `legacy-associations-out.json`) **+** `FileAssociationSeeder` (câblé dans
  `DatabaseSeeder`, idempotent/rejouable) qui **parse `default.xml` legacy** quand
  il est lisible (VM/prod) sinon retombe sur la baseline. But : à la bascule, les
  défauts d'associations sont déjà en base — zéro régression.
- **« Désactiver = cesser de gérer ».** Une association retirée disparaît →
  l'agent ne touche plus la clé (le choix courant reste, pas de reset OFF, §8).
- **Catalogue tagué `native` / `wpkg` + validation PRÉDICTIVE UI (D-Henri n°7).**
  Chaque entrée porte une `source` SERVEUR-only : `native` (built-in Windows, ex.
  `.txt → txtfile`, `.jpg → WindowsPhotoViewer` — toujours applicable) ou `wpkg`
  avec `wpkg_package` = le `<package id>` WPKG d'origine (= `Application::app_id`).
  L'**UI** d'assignation calcule, par parc, un statut EXACT : `native` →
  applicable ; `wpkg` & paquet déployé sur le parc → applicable ; `wpkg` & paquet
  NON déployé → **`unavailable`** affiché AVANT déploiement (warning rouge +
  tooltip nommant le paquet : « `<pkg>` n'est pas déployé sur ce parc → cette
  association échouera ici », toast EXACT à l'activation). Source du déploiement par
  parc = requête **group-level Eloquent PG-pure** (`appProfiles.applications` +
  `applications` directes du `WorkstationGroup` + déps transitives), **SANS le cache
  APCu** de `WorkstationPackagesResolver`. 🔴 Ce croisement WPKG vit UNIQUEMENT dans
  l'UI (Livewire) : `AssociationsStateProvider` reste PG-pur (NFR7) et **émet
  toujours** (D-Henri n°3) ; `source`/`wpkg_package` ne fuient JAMAIS au payload
  contrat (inchangé). L'agent (`ProgIDRegistered`) reste le dernier rempart sur un
  poste divergent. Clé de jointure vérifiée : `<package id>` (clé du reader
  `PackagesXmlAssociationsReader`) = `Application::$app_id` (sortie du resolver) —
  `PackagesXmlService::regenerate()` émet le `$app->xml` dont la racine `<package id>`
  vaut `app_id`.
- **Composer V2 + resolver riche/générique (Story 27.11).** L'UI passe d'un catalogue
  figé (toggles) à un **composer** : l'admin saisit une extension/protocole et choisit
  une app PAR SON NOM (apps WPKG `Application` + natives Win32 curées
  `NativeApplication`, UWP exclues). Un service serveur **`AssociationResolver`**
  (`App\Services\Agent\Resolvers\AssociationResolver`, **PG-pur**) traduit
  *(extension X, app A)* → *(progid, source, wpkg_package)* :
  (1) **A native curée** déclarant X → ProgId canonique (`txtfile`…), `source=native` ;
  (2) **A WPKG déclarant un handler POUR X** (`packages.xml`) → ProgId **riche**,
  `source=wpkg`, `wpkg_package=A.app_id` ; (3) **sinon** → ProgId **générique**
  `Applications\<exe de A>` (`source=wpkg`/`native` selon A). Le résultat est **upserté**
  comme ligne `file_associations` (clé `catalogKey(identifier, progid)`, iso 27.3bis)
  attachée au parc — **payload aval inchangé, pas de migration de `file_associations`**.
  La lecture `packages.xml` du resolver est un geste d'**administration** admis (hors
  chemin desired-state, iso `FileAssociationSeeder`) — `AssociationsStateProvider` reste
  PG-pur. **Donnée neuve** : `applications.executable` (nullable) + table
  `native_applications` (`{label, progid, executable, assoc_types}`, seedée idempotente).
  **Garde-fou (piège n°4)** : générique requis sans exe → composition refusée (pas de
  générique sans exe ; le `%1` est obligatoire dans la commande générée).
- **Agent : auto-enregistrement per-user du générique (Story 27.11, AC6).** Pour un
  ProgId générique `Applications\<exe>` non enregistré, le **compagnon** (droits user)
  résout le chemin de l'exe sur le poste (`App Paths`/PATH — jamais reçu du serveur) et
  écrit `HKCU\Software\Classes\Applications\<exe>\shell\open\command = "<chemin>" "%1"`
  AVANT d'imposer UserChoice (aucune écriture HKLM/admin). `ProgIDRegistered` est raffiné
  **pour ce cas générique uniquement** (vérifie `shell\open\command`, pas juste le nœud).
  Exe introuvable → abstention D-Henri n°5. Le hash UserChoice (`getHash`) et
  `WriteUserChoice` sont **réutilisés tels quels** (golden/contrat INTOUCHÉS).

#### Le hash UserChoice (cœur de risque, AC5)

Le handler `agent/shared/handler_associations.go` porte FIDÈLEMENT l'algorithme
`SFTA.ps1::Get-Hash` (~565-711) : `baseInfo = ("{identifier}{sid}{progid}{dateTimeHex}
{userExperience}").ToLower()` → **MD5 sur l'encodage UTF-16LE** de `baseInfo + "\x00\x00"`
→ deux passes de dérivation à constantes → 16 octets → XOR fold 8 octets → **Base64**.
La logique pure (calcul du hash) est **testable hôte** (`handler_associations_test.go`,
**vecteurs de hash** verrouillés contre un portage indépendant) ; les ops Windows
(SID via token de processus, FileTime hex secondes mises à zéro, GUID `{D18B6DD5-…}`
extrait de `shell32.dll`, écriture HKCU + **suppression-avant-réécriture** de la clé
UserChoice à ACL hérité) vivent dans `handler_associations_windows.go`. Une seule
constante fausse = hash silencieusement rejeté par Windows = association non
appliquée — d'où les tests vectoriels obligatoires.

### `app_config` — `aggregate` PAR `app_kind` / `machine` (Story 27.4)

Successeur natif du canal export-FS des policies d'app (`exportToFs` →
`/etc/sambaedu/applications/{kind}/*.json`, tiré par GPO/scripts WPKG — le canal
legacy reste intouché, il meurt en 27.6). Contrairement à `registry`/
`associations` (premiers types SANS table métier), `app_config` est dans le cas
`wallpaper`/`printers` : la **table métier existe déjà** (`app_customizations`,
story 4.8) avec son service de résolution. **On la LIT, on ne la double pas**
(créer une 2ᵉ table de policies = doublon de source de vérité, interdit).

> **Deux mécanismes legacy distincts (cadrage 27.4).** Le legacy traite Firefox
> via DEUX mécanismes qui coexistent : (A) **config** = `policies.json`
> (marque-pages, page d'accueil, extensions, proxy), écrit dans
> `%ProgramFiles%\Mozilla Firefox\distribution\policies.json` = **machine-wide,
> contexte SYSTEM/admin, PAR-PARC** — **c'est le périmètre de 27.4** ; (B)
> **profil user** = jonctions/redirection du dossier profil vers le home
> (roaming) — **HORS 27.4** (story roaming de suivi). Le par-user de Firefox est
> le Mécanisme B (le PROFIL), PAS `policies.json`.

- **Provider `AppConfigStateProvider`** (`aggregate` / `machine`) : un candidat
  PAR `app_kind` (`AppKind::cases()` = Firefox, Thunderbird), résolu via
  `AppCustomizationService::resolvePoliciesForMachine($wg, null, $kind, 'windows')`
  — **`$user = null`** : résolution PAR PARC, niveaux **1-4** (template → auto
  proxy/DNS/popup → défaut étab → WG). Les niveaux 5-6 (UserGroups/User) ne sont
  PAS résolus (le par-user de Firefox = le profil, Mécanisme B). **PG + config-pur**
  (NFR7, critère Keycloak — grep `Cache::|apcu|Ldap|samba-tool` vide) : le cache
  APCu `CacheAppContextRepository` sert l'AUTRE canal (legacy-port), jamais ce
  chemin.
- **Pourquoi `machine`.** `policies.json` est **machine-wide**, posé sous
  `%ProgramFiles%\…\distribution\` (ACL admin-write). Un compagnon aux droits user
  prendrait `ACCESS_DENIED` à chaque logon — la portée est donc **`machine`** : le
  **service SYSTEM** écrit le fichier (iso le handler `registry` HKLM, 27.3). La
  config est par-parc (parité legacy) ; le par-user de Firefox passe par le profil
  (Mécanisme B / roaming, hors 27.4).
- **Impédance « WG unique 4.8 » vs « mailles Epic 27 ».**
  `resolvePoliciesForMachine` prend UN `WorkstationGroup`, alors que le
  `TargetContext` expose des LISTES de mailles. Le provider collapse l'axe WG en
  feedant le WG **gagnant en précédence** du poste (`logique > physique` —
  inversion globale story 27.3, alignée sur `StateCompiler::specificity()`) → un
  candidat par app. Sans ce collapse, itérer tous les WG produirait plusieurs
  items Firefox (un par override de WG).
  - ⚠️ **Tiebreak multi-parcs logiques (limite connue, review #2, statu quo
    assumé).** Un poste appartenant à PLUSIEURS parcs logiques avec des policies
    différentes : seul le WG gagnant (précédence, puis **plus petit id** —
    déterminisme) est résolu ; les autres parcs logiques sont **silencieusement
    ignorés**. `policies.json` est machine-wide (un fichier par install) : il ne
    peut pas porter deux configs Firefox concurrentes. Tiebreak documenté ici et
    dans le docblock du provider.
- **Payload `{app_kind, policies}`** : policies CONCRÈTES (jamais un id de
  scope/customization), sans float (§4.1 — un float de policy → string).
- **Handler `app_config`** (`agent/shared/handler_app_config.go` pur +
  `agent/windows/handler_app_config_windows.go`) : UN SEUL mécanisme — écrire le
  `policies.json` enterprise natif au chemin d'install de l'app (écriture
  atomique), level-triggered, drift STRICT, marqueur de périmètre (clé
  `_sambaedu_managed`). Enregistré dans le **MachineEngine SYSTEM** (portée
  machine). Réutilise `engine.go::ResolveItemStatus` (§5) + `AggregateHash`. La
  canonicalisation du `policies.json` (cible côté serveur ET fichier relu côté
  agent) passe par **UNE seule fonction** `shared.CanonicalJSON` (idempotence
  garantie sur les `json.Number`, review #3).

#### Limites connues (Story 27.4)

- **App butée sans mécanisme enterprise = non géré.** Une app qui écrirait un
  réglage **sans aucun `policies.json` exploitable** n'est **PAS bricolée** (pas
  de patch de config user, pas de hook) : le réglage est documenté comme non géré
  (« match nul » assumé du brainstorming). Invariant : un handler n'écrit que via
  un mécanisme enterprise documenté de l'app.
- **Couplage installation.** Pour que la policy ait un effet, l'app doit être
  installée → story 27.5 (applications/WPKG). Le service SYSTEM écrit sous Program
  Files ; si le dossier d'install est absent (app non installée), l'écriture
  échoue → `{status: error}` pour le seul type `app_config`, les autres types
  convergent (isolation `engine.go::RunPass`).
- **`policies.json` hors périmètre = conflit `error` (review #7).** Un fichier
  posé au chemin natif par un autre outil/admin (sans marqueur) n'est **jamais
  écrasé ni supprimé** (non-ingérence préservée). Mais comme la policy agent n'est
  alors PAS active, l'item de cette app est rapporté **`error`** (détail :
  « policies.json hors-périmètre présent, policy agent non appliquée ») au lieu de
  `compliant` (qui serait trompeur). Les autres apps/types convergent. _(Défaut =
  signaler sans écraser ; une future « prise de possession » SYSTEM pourra être
  décidée.)_
- **Chrome/Edge + redirection de profil = HORS 27.4.** Le legacy ne gère aucune
  policy Chrome/Edge (net-new sans référence) ; la redirection de profil est un
  sujet **roaming** serveur (Mécanisme B, pas une policy client) → renvoyé au
  domaine roaming/`WorkstationEnvironment` (26.x / story de suivi).

### `applications` — `aggregate` / `machine` (Story 27.5)

- **Provider `ApplicationsStateProvider`** (`aggregate` / `machine`) : projection
  en LECTURE SEULE de l'**ensemble cible WPKG** d'un poste vers des candidats
  d'état. Un candidat **par `app_id` affecté** (union poste + groupes +
  dépendances transitives), payload concret `{app_id, name}`.
- **Single source of truth, NON CACHÉE (NFR7).** Le provider réutilise la
  résolution WPKG existante via
  `WorkstationPackagesResolver::computePackages($hostname)` — la méthode **NON
  CACHÉE** (la logique d'union 4 sources + BFS de dépendances). Il n'appelle
  **JAMAIS** le wrapper `resolve()` (qui enveloppe `Cache::remember`/APCu —
  interdit dans un provider). Réimplémenter l'union/BFS ici divergerait de WPKG
  réel → interdit. Le grep garde `ldap|apcu|samba-tool|Cache::|LdapRecord|PackagesXml`
  est **vide** (commentaires exceptés).
  > ⚠️ **Préempter un faux positif de revue.** La précédence 27.3bis (où
  > `AssociationsResolver` APCu n'a PAS été réutilisé) concernait une lecture de
  > cache pour validation UI. Ici, la **résolution de l'ensemble** est la logique
  > métier centrale ; on la réutilise **non cachée** → le grep reste vide.
- **Maille `Broadcast` (D4).** `computePackages($hostname)` résout déjà l'union
  poste + groupes + dépendances — résolution **finale, mono-sortie** (pas une
  liste de candidats par maille à composer). On émet donc chaque app comme
  candidat `StateMaille::Broadcast` ; le compilateur en `aggregate` fait l'union
  sans précédence à arbitrer (tous Broadcast). Adaptation documentée (iso le
  collapse mono-WG de 27.4) du modèle « liste de mailles » d'Epic 27 à une API de
  résolution mono-sortie. Alternative écartée : ré-étiqueter par maille d'origine
  (coûteux, sans valeur — aggregate ⇒ union de toute façon).
- **Portée MACHINE** (leçon 🔴 27.4 #1) : WPKG installe machine-wide → le service
  SYSTEM déclenche. `sourceId` = `Application::id` (PK stable, déterministe &
  injectif → ordre aggregate / ETag stable). `payload` = `{app_id, name}` —
  jamais une recette d'install (pas de version/`<check>`/`<install>`), jamais un
  id de catalogue/pivot/scope (invariant central, contrat §7.4).
- **« Un tuyau, deux outils ».** Le provider projette l'**ensemble** (les
  `app_id`), pas les recettes. Le handler agent **déclenche** WPKG (le moteur
  déclaratif, non absorbé) et lit `wpkg.xml`. Inventaire par app rapporté en
  champ additif (contrat §6) → `agent_application_inventory`.

### Mode `strict|default` — RETIRÉ (Story 27.8)

> **Story 27.8 — RETRAIT TOTAL.** Le mécanisme `mode ∈ {strict, default}`
> (introduit par 27.1, déplacé par 27.3) est **entièrement supprimé**. La review
> 27.3 a établi que le grain réel du mode était `type × poste` (un seul verdict
> par type côté agent), pas `item × cible` — la promesse « par assignation »
> était creuse au niveau agent. Henri a tranché : **comportement UNIQUE = STRICT
> inconditionnel** (la cible fait toujours loi, la dérive humaine est toujours
> corrigée, le statut `drifted_allowed` disparaît).
>
> Conséquences : `StateProvider::mode()`, `StateCandidate::$mode`,
> `StateCompiler::aggregateMode()`, l'enum `App\Enums\StateMode`, les colonnes
> `mode` des 3 tables (`shortcut_assignables`/`wallpapers`/`overlay_signals`) et
> les 3 toggles UI sont **retirés**. L'item du contrat passe de 5 à 4 clés. Voir
> `docs/agent/contract-v1.md` §5.

## Ajouter un type de ressource (checklist Epic 27)

1. **Identifiant figé** : ajouter le type à `docs/agent/contract-v1.md` §7
   (snake_case, jamais renommé — NFR12).
2. Choisir `semantics` / `scope` (constantes déclarées par le provider).
3. Écrire `App\Services\Agent\Providers\<Type>StateProvider` : lecture seule
   des tables métier, candidats étiquetés par maille, payload sans float.
4. L'enregistrer dans `AgentServiceProvider::register()` (une ligne dans le
   tableau du `StateCompiler`) — **zéro modification du compilateur**.
5. Tests unit du provider (mapping mailles, applicabilité, payload) ; le
   golden file n'évolue que si l'on veut illustrer le nouveau type (et alors :
   mise à jour SCIEMMENT du hash figé `ContractV1Test`).
6. Handler côté agent (Epic 24/27) : `test`/`apply`/`report` idempotent.

## Environnement de poste (`WorkstationEnvironment`) — Story 26.1

La **nature d'un poste** est une donnée du domaine portée **par parc** sur
`workstation_groups.environment` (colonne VARCHAR(32) nullable, cast enum
`App\Enums\WorkstationEnvironment`). Trois valeurs aux **identifiants figés
(NFR12)** :

| Valeur | Sémantique |
|---|---|
| `shared_local` | Poste **partagé** : bureau réseau, profils redirigés. C'est le parc historique (salle de classe). **Défaut implicite.** |
| `personal_local` | Modèle **perdir / direction** : bureau local à l'utilisateur, données sur le home réseau (nominatif, pas déconnecté). |
| `nomade` | **Tout local avec synchronisation** (offline / resync — réalisé en Story 26.2). |

### Résolution multi-parcs

Un poste appartient à N parcs (sa salle physique + ses parcs logiques). Le
service `App\Services\Agent\WorkstationEnvironmentResolver` résout **un** seul
environnement avec la **précédence** :

    nomade > personal_local > shared_local

et le **défaut `shared_local`** quand aucune valeur n'est déclarée (poste sans
groupe, ou tous les parcs à `null`). La précédence vit **dans le service SEUL**
(décision D1, parallèle `StateMaille`/`StateCompiler`) — ni dans l'enum, ni
dans les providers. `null` (« non déclaré ») reste distinct de `shared_local`
(décision D2 : pas de default SQL, pas de backfill).

Lecture **exclusivement Postgres** (`WorkstationGroup::whereIn('id', …)`),
JAMAIS d'AD / LdapRecord / APCu (NFR7, même discipline que `TargetContext`).
Pour la consommation par un `StateProvider` de l'Epic 27, préférer
`resolveForGroupIds(TargetContext::workstationGroupIds())` — les ids sont déjà
résolus, le provider ne re-requête pas les appartenances.

### Point de consommation & note de transition

Ce service ne produit que la **donnée** : aucun handler ni StateProvider
« environment » n'existe en 26.1. Il sera consommé par les handlers de l'Epic
27 (raccourcis 27.1, profils navigateur 27.4). **AUCUN retrofit legacy** :
`ApplicationScriptsGenerator`, `ShortcutCompilerService` et le pansement Bug C
(`4e5a152`) restent intouchés — ils meurent avec le canal legacy (27.6) ; le
Bug C est corrigé définitivement par le handler raccourcis (27.1). Ne PAS
brancher ce service sur le canal legacy.

### UI

Onglet « Environnement » de `parc-settings` (SFC Livewire
`_partials/environment-tab.blade.php`) : un `<select>` par parc (logiques ET
physiques), Gate `update-workstationGroup` (même autorisation que l'édition
d'un parc), persistance via le modèle, toast de succès.

### Mode nomade (Story 26.2)

**Modèle retenu (décision Henri, 2026-06-13) : le poste nomade est 100 % LOCAL,
et c'est ASSUMÉ.** Concrètement, un poste `nomade` :

- **n'a pas de profil utilisateur réseau** (ni itinérant, ni redirigé) ;
- **stocke ses documents en local sur la machine**, et ils **ne sont jamais
  supprimés** (le poste *est* la source de vérité de ses données) ;
- reçoit sa configuration (logiciels WPKG, raccourcis, wallpaper, …) par
  **l'agent desired-state** (Epics 23-25), qui converge comme sur tout autre
  poste. **C'est tout.**

> **Ce que la Story 26.2 NE fait PAS — et pourquoi (clôture de FR29).** L'epic
> (FR29) recommandait à l'origine un modèle « serveur = source de vérité + cache
> offline + resynchronisation » (Folder Redirection + Offline Files / CSC). Ce
> modèle a été **écarté** : il ne correspond pas à l'exploitation réelle des
> nomades (qui gardent tout en local) et ajouterait une machinerie Windows
> lourde (redirection, CSC, conflits de sync) sans bénéfice recherché. **Aucune
> redirection de dossiers, aucun fichier hors-connexion, aucune synchronisation
> serveur n'est mise en place.** **Conséquence explicitement acceptée** : si le
> portable est perdu/volé/en panne, ses données locales sont perdues (pas de
> copie serveur). Un éventuel filet de sauvegarde serait une décision séparée,
> hors 26.2.

> **`clean_profiles` / `del-roam` ne concernent PAS les nomades.** Ces mécanismes
> legacy agissent sur le store des profils **itinérants** `/home/profiles`, par
> **user**, et sont **aveugles au poste** (`clean_profiles('*')` = purge des
> orphelins, action admin manuelle `ldap_cleaner.php?do=3` ; `del-roam.sh` =
> trim per-`${username}`, logon script SYSVOL). Un nomade n'ayant **pas de profil
> réseau**, ces mécanismes ne le touchent pas — il n'y avait rien à désactiver.
> La **réimplémentation native** du nettoyage de profils (pastille tableau user +
> purge des orphelins, calcul journalier) est un sujet **distinct** traité en
> **Story 26.3**. AUCUN retrofit legacy (`RoamingProfileService`,
> `ApplicationScriptsGenerator`, `ShortcutCompilerService`, GPO `redirections`).

## Hors scope (et où ça vit)

| Sujet | Pourquoi pas ici | Où |
|---|---|---|
| Alertes overlay dérivées (quota, multi-session) | volatiles à chaque poll → détruiraient l'ETag ; métrologie temps réel, pas état cible | hors desired-state (arbitrage 24.4 SOLDÉ : composition locale par le handler, identité stable servie par l'item `identity`) |
| Fallback wallpaper système (`default.jpg`), perso `/home/<user>/Photos`, override quota | features du canal legacy, pas des règles d'état serveur (le mode strict/default a été retiré en 27.8 — convergence STRICT inconditionnelle) | canal legacy jusqu'à extinction (Epic 27) |
| Type `lockscreen` | pas dans les identifiants figés §7 | futur type séparé (Epic 27) |
| URL de téléchargement des assets | **soldé 24.4** : route de serving livrée, payload INCHANGÉ (décision « pas de champ url » ci-dessus) | `handlers-wallpaper-overlay.md` §2 |
| `config('agent.ttl_seconds')` | clé formalisée avec l'endpoint | story 23.5 (défaut code 3600 en attendant) |
| UI des warnings de conflit | le log structuré suffit à ce stade | story 24.5 (UI conformité) |

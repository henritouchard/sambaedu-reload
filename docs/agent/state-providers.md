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
    public function mode(): StateMode;                 // strict | default
    public function scope(): StateScope;               // machine | session | machine_user
    public function itemsFor(TargetContext $ctx): Collection; // candidats bruts par maille
}
```

`mode()` est une extension 23.4 de l'interface de l'architecture (qui ne
déclarait que 4 méthodes) : l'item du contrat porte `mode`, et AC1 interdit
toute table type→mode dans le compilateur — donc le provider déclare sa
constante, au même titre que `semantics()` et `scope()`. Ces constantes par
type tiennent jusqu'à l'UI du toggle (Epic 27) ; aucune table de config.

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

D2 ne figeait que la partie machine (`poste > WG physique > WG logique >
broadcast`). La chaîne **complète**, tranchée en 23.4 :

```
user > groupes user > poste > WG physique > WG logique > broadcast
```

Rationale iso-legacy : `WallpaperResolver` plaçait déjà le user (niveau 6) et
ses groupes (niveaux 4-5) au-dessus de la salle (3) et de l'étab (2) — un
wallpaper personnel bat celui de la salle, comportement connu des admins. La
distinction legacy « type principal vs groupe AD » s'écrase en UNE maille
`groupes user` (divergence douce assumée : un conflit entre deux groupes
devient une règle intra-maille, ci-dessous).

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

### `wallpaper` — `exclusive` / `default` / `session`

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

### `overlay` — `aggregate` / `strict` / `session`

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

### `shortcuts` — `aggregate` / `strict` / `machine_user` (Story 27.1)

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
- `place` ∈ `desktop|startup|taskbar` (iso `Shortcut::PLACE_*`). Tous les champs
  sont des strings (jamais de float, §4.1).

### Mode `strict|default` par règle (Story 27.1 — FR26)

Le mode d'application **n'est plus une constante par type** : c'est un attribut
**par règle** (colonne `mode` sur `shortcuts`, `wallpapers`, `overlay_signals`),
porté par `StateCandidate::$mode`. Le `StateCompiler` **agrège** le mode par
type (un seul verdict côté agent) : **`default` ssi TOUTES** les règles retenues
sont `default`, sinon **`strict`** (posture sûre). Une règle sans `mode` (null
en base) retombe sur `StateProvider::mode()` (le défaut du type) — wallpaper
reste `default`, overlay/shortcuts restent `strict` tant qu'aucune règle n'est
basculée via l'UI (toggle exposé pour les 3 types, décision n° 2).

## Ajouter un type de ressource (checklist Epic 27)

1. **Identifiant figé** : ajouter le type à `docs/agent/contract-v1.md` §7
   (snake_case, jamais renommé — NFR12).
2. Choisir `semantics` / `mode` / `scope` (constantes déclarées par le
   provider).
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
| Fallback wallpaper système (`default.jpg`), perso `/home/<user>/Photos`, override quota | features du canal legacy, pas des règles d'état serveur ; le perso est le cas d'école du mode `default`/`drifted_allowed` (réalisé en 24.4 côté handler) | canal legacy jusqu'à extinction (Epic 27) |
| Type `lockscreen` | pas dans les identifiants figés §7 | futur type séparé (Epic 27) |
| URL de téléchargement des assets | **soldé 24.4** : route de serving livrée, payload INCHANGÉ (décision « pas de champ url » ci-dessus) | `handlers-wallpaper-overlay.md` §2 |
| `config('agent.ttl_seconds')` | clé formalisée avec l'endpoint | story 23.5 (défaut code 3600 en attendant) |
| UI des warnings de conflit | le log structuré suffit à ce stade | story 24.5 (UI conformité) |

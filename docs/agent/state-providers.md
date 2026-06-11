# StateProviders & compilation d'état — `se5.desired-state/v1`

> Story 23.4 — Epic 23 (agent desired-state). Complète `contract-v1.md` (le
> wire format, FIGÉ) sans le modifier : ce document décrit le **côté serveur**
> de la compilation — comment l'état cible d'un (poste, user) est calculé
> depuis les tables métier existantes, et comment on ajoute un type.

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
- L'URL de téléchargement sera AJOUTÉE quand la route de serving existera
  (champ ajouté = évolution mineure, contrat §9) → story 24.4.

### `overlay` — `aggregate` / `strict` / `session`

Un item **par signal posté actif** (`overlay_signals`, union) :

```json
{ "kind": "info", "severity": "warning", "title": "…", "text": "…",
  "expires_at": "2026-06-12T08:00:00+00:00" }
```

- `expires_at` : ISO 8601 UTC ou null. Signal expiré = exclu à la
  compilation (l'état change réellement → l'ETag change : correct).
- Maille d'un signal : `user_login` → user ; `workstation_uuid` → poste ;
  `workstation_group_id` → WG (physique/logique selon le groupe) ; tout
  null → broadcast. Multi-critères → maille la plus spécifique (étiquette
  cohérente pour logs/tests ; sans incidence de précédence, aggregate).

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

## Hors scope (et où ça vit)

| Sujet | Pourquoi pas ici | Où |
|---|---|---|
| Alertes overlay dérivées (quota, multi-session) et bloc identité/machine | volatiles à chaque poll → détruiraient l'ETag ; métrologie temps réel, pas état cible | arbitrage à la story 24.4 (composition `overlay.json`) |
| Fallback wallpaper système (`default.jpg`), perso `/home/<user>/Photos`, override quota | features du canal legacy, pas des règles d'état serveur ; le perso est le cas d'école du mode `default`/`drifted_allowed` | réexamen au handler 24.4 |
| Type `lockscreen` | pas dans les identifiants figés §7 | futur type séparé (Epic 27) |
| URL de téléchargement des assets | la route de serving n'existe pas encore | champ mineur ajouté en 24.4 |
| `config('agent.ttl_seconds')` | clé formalisée avec l'endpoint | story 23.5 (défaut code 3600 en attendant) |
| UI des warnings de conflit | le log structuré suffit à ce stade | story 24.5 (UI conformité) |

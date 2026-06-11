# Contrat agent `se5.desired-state/v1`

> **Statut : figé (v1).** Ce document décrit le contrat HTTP/JSON entre le
> serveur SambaEdu (SE5) et l'agent *desired-state* des postes (successeur des
> GPO). Story 23.1 (Epic 23). Les golden files normatifs sont
> `tests/Fixtures/Agent/state.v1.json` (état cible) et
> `tests/Fixtures/Agent/report.v1.json` (rapport de conformité).
>
> Un agent déployé **fige le wire format**. Tant qu'aucun consommateur n'existe,
> on peut décider de la forme de l'enveloppe, de la présence du booléen `mode`
> et de l'algorithme de hash ; **après, plus jamais** sans bump de version
> majeure. C'est l'objet de cette story : figer les irréversibles.

## 1. Vue d'ensemble

| Sens | Endpoint (stories futures) | Schéma | Golden file |
|---|---|---|---|
| Serveur → agent | `GET /api/v1/agent/state` (23.5) | `se5.desired-state/v1` | `state.v1.json` |
| Agent → serveur | `POST /api/v1/agent/report` (24.1) | `se5.desired-state/v1` | `report.v1.json` |

La source unique du nom de schéma est `App\Services\Agent\StateContract::SCHEMA`
(constante figée, **jamais** une variable d'environnement — NFR12).

Conventions communes (iso POC overlay `se5.wallpaper-overlay/v1`, commit
`f9b3ad9`) : clés `snake_case`, structure plate, tableaux d'objets, timestamps
UTC ISO 8601 (`Carbon::now('UTC')->toIso8601String()`).

## 2. Enveloppe de l'état cible

```json
{
  "schema": "se5.desired-state/v1",
  "generated_at": "2026-06-11T08:00:00+00:00",
  "ttl_seconds": 3600,
  "machine":      [ /* items portée machine */ ],
  "session":      [ /* items portée session */ ],
  "machine_user": [ /* items portée machine × user */ ]
}
```

| Champ | Type | Sens |
|---|---|---|
| `schema` | string | Version du contrat. L'agent **refuse un major inconnu**. |
| `generated_at` | string (ISO 8601 **avec timezone**) | Instant de compilation. **Champ volatil exclu du hash.** |
| `ttl_seconds` | int | Cadence de poll/rafraîchissement conseillée. |
| `machine` / `session` / `machine_user` | list\<item\> | Les trois **portées** d'application. |

### Portées (`App\Enums\StateScope`)

- `machine` — s'applique au poste, indépendamment de la session ouverte.
- `session` — s'applique à la session de l'utilisateur courant.
- `machine_user` — s'applique au couple poste × utilisateur (persistant par
  poste pour un utilisateur donné).

Les valeurs sont **aussi** les clés de l'enveloppe.

## 3. Item

Chaque item porte **exactement** ces cinq clés (ni plus, ni moins) :

```json
{
  "type": "wallpaper",
  "semantics": "exclusive",
  "mode": "default",
  "payload": { "asset": "fonds/ecole-2026.jpg", "style": "fill" },
  "hash": "f65db3c8…2d6e2d37"
}
```

| Clé | Type | Sens |
|---|---|---|
| `type` | string snake_case | Identifiant de type de ressource **figé** (cf. §7). |
| `semantics` | `aggregate` \| `exclusive` | Règle de combinaison (cf. §3.1). |
| `mode` | `strict` \| `default` | Tolérance à la dérive (cf. §5). |
| `payload` | object | Données spécifiques au type — **owné par la story du provider** (§3.2). |
| `hash` | string (sha256 hex) | Hash opaque du contenu définissant de l'item (cf. §4). |

### 3.1 Sémantique `aggregate` vs `exclusive` (`App\Enums\ResourceSemantics`)

- `exclusive` — **un seul** item fait foi pour la ressource (ex. `wallpaper` :
  il n'y a qu'un fond d'écran). L'agent applique l'unique / le dernier, jamais
  une union.
- `aggregate` — plusieurs items du même type **s'additionnent** (ex. `overlay`,
  plusieurs `shortcuts`). L'agent applique l'**union**.

### 3.2 `payload` : frontière de responsabilité

Ce qui est **figé en 23.1** = le *wrapper* (`type/semantics/mode/payload/hash`)
et l'enveloppe. La **sous-structure interne de `payload`** par type est **owné
par la story du provider correspondant** (wallpaper/overlay → 23.4 ; etc.). Les
payloads présents dans `state.v1.json` sont **illustratifs** et n'engagent pas
le détail final de chaque provider.

## 4. Hash — `App\Services\Agent\StateHasher`

Algorithme **unique et déterministe** (FR7). C'est la **source unique** du hash
dans le canal agent : il sert l'ETag de `GET /state` (23.5) **et** la
comparaison des rapports (24.1). **Jamais** de `md5` / `hash('sha256', …)` ad
hoc ailleurs.

**Canonicalisation :**

1. Tri **récursif** des clés des **tableaux associatifs** — **lexicographique
   octet-par-octet** (`ksort(…, SORT_STRING)`, pas de collation locale ni de
   comparaison numérique : `"10"` < `"9"`).
2. Les **listes** ne sont **pas** triées : l'ordre des items est significatif et
   fixé par le serveur.
3. Encodage `json_encode(…, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE |
   JSON_THROW_ON_ERROR)` — UTF-8, sans échappement superflu, **sans espaces**
   (pas de `JSON_PRETTY_PRINT`).
4. `hash('sha256', $canonical)` → hex.

> ⚠️ `json_encode` de PHP **ne trie pas** les clés : le tri récursif est
> implémenté à la main (`ksort` sur les sous-tableaux associatifs uniquement).

**Deux portes d'entrée :**

- `hashState(array $state)` — exclut le champ volatil `generated_at` **avant**
  canonicalisation. Deux compilations du même état à des instants différents →
  **hash identique**. (Tout futur champ volatil s'exclut au même endroit —
  single point of truth.)
- `hashItem(array $item)` — exclut la clé `hash` de l'item (sinon dépendance
  circulaire). Le hash dérive du seul contenu *définissant*.

Le hash est **opaque** : l'agent **compare des chaînes**, il ne le recalcule
jamais. La canonicalisation n'existe donc que **côté serveur** — mais elle doit
rester déterministe à vie (un upgrade PHP ne doit pas changer les hashes), d'où
les contraintes ci-dessous.

### 4.1 Contraintes sur les valeurs des payloads (stabilité du hash)

- **Pas de flottants.** Types autorisés dans un payload : chaînes, entiers,
  booléens, `null`, objets, listes. La sérialisation JSON des floats dépend de
  la version/configuration PHP (`serialize_precision` ; `1.0` s'encode `1`) —
  un float rendrait le hash instable d'un upgrade serveur à l'autre. Une
  quantité décimale se porte en chaîne (`"opacity": "0.8"`) ou en entier
  (`"opacity_pct": 80`).
- **Objet vide `{}` ≢ liste vide `[]` : distinction non fiable.** Après
  décodage PHP, les deux sont canonicalisés en `[]`. Aucun payload ne doit
  faire porter du sens à cette différence (un « aucune valeur » explicite se
  porte par un champ dédié, ex. `asset: null`, cf. §8).
- **Unicode : le serveur émet en NFC**, et le hash compare octet à octet (pas
  de normalisation). Côté agent, la comparaison *réel vs cible* faite par les
  handlers doit **normaliser en NFC** avant comparaison — Windows peut produire
  du NFD (`é` = `e` + accent combinant) pour des chemins/noms visuellement
  identiques, ce qui créerait de faux `drift`.

## 5. Mode `strict` vs `default` — gap 1 (la règle à ne pas rater)

`App\Enums\StateMode`. Le mode `default` est le **sabotage le plus dangereux**
s'il est mal spécifié — d'où la règle écrite **noir sur blanc** :

L'agent **persiste, par item, le dernier état qu'il a lui-même APPLIQUÉ**.
À chaque cycle, pour un item donné, il compare trois choses : l'état **réel**
sur le poste, l'état **cible** (du serveur), et son **dernier-appliqué** mémorisé.

### `mode = strict`

Toute dérive est réappliquée. La cible **fait loi**, sans exception.

```
réel ≠ cible  →  l'agent RÉAPPLIQUE la cible  →  rapporte `drift`
réel = cible  →  rien à faire                 →  rapporte `compliant`
```

### `mode = default`

Tolère la **dérive humaine volontaire**.

```
réel = cible                              →  rapporte `compliant`
réel ≠ cible  ∧  dernier-appliqué ≠ cible →  la cible a bougé, pas encore poussée
                                              →  l'agent APPLIQUE la cible  →  `drift`
réel ≠ cible  ∧  dernier-appliqué = cible →  DÉRIVE HUMAINE
                                              →  l'agent NE RÉAPPLIQUE PAS
                                              →  rapporte `drifted_allowed`
```

**Premier passage (pas de `dernier-appliqué` persisté)** : à la première
rencontre d'un item — aucune mémoire pour lui — l'agent traite le cas comme
`dernier-appliqué ≠ cible` :

```
réel = cible  →  rapporte `compliant`  →  persiste la cible comme dernier-appliqué
réel ≠ cible  →  APPLIQUE la cible     →  rapporte `drift`  →  persiste
```

L'absence de mémoire n'est **jamais** interprétée comme une dérive humaine
(`drifted_allowed` exige un `dernier-appliqué` connu et égal à la cible).

Autrement dit : si l'agent a déjà appliqué la cible **et** que l'état réel en a
divergé depuis, c'est qu'un humain a volontairement changé la valeur → en
`default`, on respecte ce choix et on **ne réapplique pas**. En `strict`, on
écrase.

## 6. Rapport de conformité — gap 2 (`POST /report`)

```json
{
  "schema": "se5.desired-state/v1",
  "generated_at": "2026-06-11T08:05:00+00:00",
  "agent_version": "1.0.0",
  "workstation": { "hostname": "salle101-pc03", "uuid": "f1d2c3b4-…" },
  "items": [
    { "type": "wallpaper", "status": "compliant",       "hash": "f65db3c8…" },
    { "type": "overlay",   "status": "drift",            "hash": "fe63c684…" },
    { "type": "shortcuts", "status": "drifted_allowed",  "hash": "2fb7f510…" },
    { "type": "printers",  "status": "error",            "hash": "9f2c4e7a…",
      "detail": "service Spooler indisponible (RPC 0x6ba)" }
  ]
}
```

| Champ | Sens |
|---|---|
| `schema` | Même version que l'état (`se5.desired-state/v1`). |
| `generated_at` | Instant du rapport (UTC ISO 8601). |
| `agent_version` | Version de l'agent émetteur. |
| `workstation` | Identité du poste (`hostname`, `uuid`). |
| `items[].type` | Identifiant de type figé. |
| `items[].status` | `compliant` \| `drift` \| `drifted_allowed` \| `error` (`App\Enums\AgentResourceStatus`). |
| `items[].hash` | Hash **opaque** de la cible que l'agent a traitée (échoué tel quel, jamais recalculé). |
| `items[].detail` | **Obligatoire et non vide** quand `status = error` ; optionnel sinon. |

Statuts (`App\Enums\AgentResourceStatus`) :

- `compliant` — réel = cible, rien à faire.
- `drift` — dérive détectée **et réappliquée** (`strict`, ou `default` avec
  dérive non humaine).
- `drifted_allowed` — dérive humaine **tolérée** en `default`, non réappliquée
  (cf. §5).
- `error` — l'application a échoué ; `detail` documente la cause.

## 7. Identifiants de type de ressource (NFR12 — figés)

Clé de voûte du contrat, **partagés** serveur / agent / JSON / DB / UI. Ils sont
`snake_case` et **figés une fois publiés** : un identifiant publié ne se
**renomme JAMAIS**. En cas d'erreur de nommage → **déprécier + ajouter** un
nouvel identifiant, jamais renommer en place.

Identifiants prévus :

`wallpaper`, `overlay`, `shortcuts`, `printers`, `drives`, `associations`,
`registry`, `app_config`, `applications`.

## 8. Tableau vide ≠ type absent (décision de contrat — AC1)

Les items d'une portée sont une **liste**, pas une map. La distinction
« rien à faire » vs « pas géré » se joue ainsi :

- **Type absent de la liste** = « le serveur n'a **aucune règle** pour ce type
  sur ce poste » → l'agent **ne touche pas** à cette ressource.
- **Portée = `[]` (liste vide)** = « aucune règle d'**aucun** type pour cette
  portée » → cas particulier du précédent (tous types absents). Illustré par la
  portée `machine` du golden `state.v1.json`.
- Vouloir explicitement « remettre la ressource à l'état neutre / aucune
  valeur » se porte **dans le `payload`** (ex. `wallpaper` avec `asset: null` =
  « pas de fond imposé »), **jamais** par omission.

Aucune ambiguïté « rien à faire » vs « pas géré » ne doit subsister : c'est une
décision de contrat consommée par chaque handler côté agent.

## 9. Règle d'évolution (AC5 — NFR13)

- **Champ ajouté** → version **mineure**. L'agent **ignore l'inconnu**
  (forward-compat).
- **Champ retiré / renommé** OU **sémantique changée** → version **MAJEURE**
  (`se5.desired-state/v2`). L'agent **refuse un major inconnu**.
- Toute évolution = **mise à jour des golden files + bump de version explicite**.

Les golden files `tests/Fixtures/Agent/{state,report}.v1.json` sont **normatifs**
et **consommés par PHPUnit** (`tests/Unit/Services/Agent/ContractV1Test.php`) :
structure validée + **hash d'état figé** (garde-fou de régression sur la
canonicalisation). Quand l'agent existera (Epic 24), ces mêmes golden files
seront partagés serveur ⇄ agent pour des tests croisés.

## 10. Aucune dépendance AD (critère Keycloak — NFR7, AC6)

Rien dans le code livré (`app/Enums/*`, `app/Services/Agent/*`, fixtures) n'appelle
l'AD / LdapRecord / Kerberos / `samba-tool`. Le canal agent est **neuf** et reste
indépendant de l'annuaire — condition de la sortie AD → Keycloak à terme.

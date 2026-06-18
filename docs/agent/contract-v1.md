# Contrat agent `se5.desired-state/v1`

> **Statut : figé (v1).** Ce document décrit le contrat HTTP/JSON entre le
> serveur SambaEdu (SE5) et l'agent *desired-state* des postes (successeur des
> GPO). Story 23.1 (Epic 23). Les golden files normatifs sont
> `tests/Fixtures/Agent/state.v1.json` (état cible) et
> `tests/Fixtures/Agent/report.v1.json` (rapport de conformité).
>
> Un agent déployé **fige le wire format**. Tant qu'aucun consommateur n'existe,
> on peut décider de la forme de l'enveloppe et de l'algorithme de hash ;
> **après, plus jamais** sans bump de version majeure. C'est l'objet de cette
> story : figer les irréversibles.
>
> **Story 27.8** : le mécanisme `mode` strict/default est **entièrement retiré**
> (convergence STRICT inconditionnelle). L'item passe de 5 à 4 clés, le statut
> `drifted_allowed` disparaît, les hashes figés sont bumpés (zéro prod → rupture
> interne assumée, schéma reste `v1`).

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
  "debug": false,
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
| `debug` | bool | Mode debug du poste (`workstations.debug`). En debug, le compagnon de session garde sa console ouverte (toutes sessions) et y recopie ses logs. **Champ opérationnel inclus dans le hash** : un toggle change l'ETag et franchit le cache 304. L'agent ignore ce champ s'il ne le connaît pas (forward-compat). |
| `machine` / `session` / `machine_user` | list\<item\> | Les trois **portées** d'application. |

### Portées (`App\Enums\StateScope`)

- `machine` — s'applique au poste, indépendamment de la session ouverte.
- `session` — s'applique à la session de l'utilisateur courant.
- `machine_user` — s'applique au couple poste × utilisateur (persistant par
  poste pour un utilisateur donné).

Les valeurs sont **aussi** les clés de l'enveloppe.

## 3. Item

Chaque item porte **exactement** ces quatre clés (ni plus, ni moins) — Story
27.8 : la clé `mode` a été **retirée** (convergence STRICT inconditionnelle,
cf. §5) :

```json
{
  "type": "wallpaper",
  "semantics": "exclusive",
  "payload": { "asset": "fonds/ecole-2026.jpg", "style": "fill" },
  "hash": "66fbf6ff…03ed4d9"
}
```

| Clé | Type | Sens |
|---|---|---|
| `type` | string snake_case | Identifiant de type de ressource **figé** (cf. §7). |
| `semantics` | `aggregate` \| `exclusive` | Règle de combinaison (cf. §3.1). |
| `payload` | object | Données spécifiques au type — **owné par la story du provider** (§3.2). |
| `hash` | string (sha256 hex) | Hash opaque du contenu définissant de l'item (cf. §4). |

### 3.1 Sémantique `aggregate` vs `exclusive` (`App\Enums\ResourceSemantics`)

- `exclusive` — **un seul** item fait foi pour la ressource (ex. `wallpaper` :
  il n'y a qu'un fond d'écran). L'agent applique l'unique / le dernier, jamais
  une union.
- `aggregate` — plusieurs items du même type **s'additionnent** (ex. `overlay`,
  plusieurs `shortcuts`). L'agent applique l'**union**.

### 3.2 `payload` : frontière de responsabilité

Ce qui est **figé en 23.1** = le *wrapper* (`type/semantics/payload/hash`)
et l'enveloppe (Story 27.8 : la clé `mode` a été retirée du wrapper — convergence
strict inconditionnelle, cf. §5). La **sous-structure interne de `payload`** par type est **owné
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

## 5. Convergence STRICT inconditionnelle (Story 27.8)

> **Story 27.8 — RÉVISION.** Le mécanisme `mode ∈ {strict, default}` (gap 1
> introduit par 27.1, déplacé par 27.3) est **entièrement RETIRÉ**. La review
> 27.3 a établi que le grain réel du mode était `type × poste` (un seul verdict
> de mode par type côté agent), pas `item × cible` : la promesse « un même
> raccourci verrouillé sur un parc, modifiable sur un autre, sur le même poste »
> était creuse au niveau agent. Henri a tranché : **comportement UNIQUE = la
> cible fait TOUJOURS loi**.

Comportement unique : **la cible fait loi, sans exception** (l'ancien
`mode = strict`, rendu inconditionnel). La dérive humaine n'est **plus** tolérée.

```
réel ≠ cible  →  l'agent RÉAPPLIQUE la cible  →  rapporte `drift`
réel = cible  →  rien à faire                 →  rapporte `compliant`
```

Il n'existe plus de statut `drifted_allowed`, plus de clé `mode` au payload, plus
de paramètre `mode`/`lastAppliedHash` au verdict (`ResolveItemStatus(isCompliant)`).

**Persistance du dernier-appliqué (conservée).** L'agent persiste toujours, par
type, le dernier état qu'il a appliqué (horodatage — traçabilité, décision 24.4
n° 9). Cette mémoire n'a **plus d'incidence sur le verdict** (elle ne sert qu'à
la trace), mais le store applied-state est conservé (Story 27.8 D-B).

**Premier passage** : aucune mémoire ⇒ même comportement que tout autre passage —
`réel = cible → compliant` (+ persiste), `réel ≠ cible → APPLIQUE → drift`
(+ persiste).

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
| `items[].status` | `compliant` \| `drift` \| `error` (`App\Enums\AgentResourceStatus`). |
| `items[].hash` | Hash **opaque** de la cible que l'agent a traitée (échoué tel quel, jamais recalculé). |
| `items[].detail` | **Obligatoire et non vide** quand `status = error` ; optionnel sinon. |

Statuts (`App\Enums\AgentResourceStatus`) — Story 27.8 : 3 statuts (le
`drifted_allowed` est retiré) :

- `compliant` — réel = cible, rien à faire.
- `drift` — dérive détectée **et réappliquée** (la cible fait toujours loi).
- `error` — l'application a échoué ; `detail` documente la cause.

**Champ additif `inventory` (Story 27.5, évolution MINEURE §9).** L'item
`applications` du **rapport** peut porter un champ optionnel `inventory` : un
**résultat par application** `[{app_id, status, detail?}]` (statut ∈
`compliant|drift|error`). C'est une **donnée additive** sous la ligne d'état par
type — **jamais** un verdict per-app : le `status`/`hash` de l'item RESTENT le
verdict **PAR TYPE** (pire statut des apps ; grain 27.8 intact). Le serveur
stocke l'inventaire dans `agent_application_inventory` (clé `(workstation_id,
app_id)`, level-triggered) — **fondation des licences à pool**, sans UI. Un
serveur antérieur ignore ce champ (reste `v1`).

```json
{
  "type": "applications",
  "status": "drift",
  "hash": "1a2b3c4d…",
  "inventory": [
    { "app_id": "firefox", "status": "compliant" },
    { "app_id": "vlc", "status": "error", "detail": "installeur en échec (code 1603)" }
  ]
}
```

## 7. Identifiants de type de ressource (NFR12 — figés)

Clé de voûte du contrat, **partagés** serveur / agent / JSON / DB / UI. Ils sont
`snake_case` et **figés une fois publiés** : un identifiant publié ne se
**renomme JAMAIS**. En cas d'erreur de nommage → **déprécier + ajouter** un
nouvel identifiant, jamais renommer en place.

Identifiants prévus :

`wallpaper`, `overlay`, `shortcuts`, `printers`, `drives`, `associations`,
`registry`, `app_config`, `applications`.

### 7.1 Payload `registry` (Story 27.3)

Type `registry` — sémantique **`exclusive` PAR IDENTITÉ DE CLÉ** (une clé de
registre = une valeur ; la maille la plus spécifique gagne POUR CETTE clé, les
clés distinctes s'accumulent). Le payload porte un item de registre **CONCRET** :

```json
{
  "type": "registry",
  "semantics": "exclusive",
  "payload": {
    "hive": "HKCU",
    "path": "Software\\Microsoft\\Windows\\CurrentVersion\\Explorer\\Advanced",
    "name": "HideFileExt",
    "type": "REG_DWORD",
    "value": 0
  },
  "hash": "92730f99…af686b5"
}
```

| Clé | Type JSON | Sens |
|---|---|---|
| `hive` | string | `HKLM` (portée machine, service SYSTEM) \| `HKCU` (portée session, compagnon). |
| `path` | string | Chemin de clé sous la ruche (backslashes échappés en JSON). |
| `name` | string | Nom de la valeur de registre. |
| `type` | string | `REG_SZ` \| `REG_DWORD` \| `REG_EXPAND_SZ` \| `REG_MULTI_SZ` \| `REG_QWORD`. |
| `value` | int \| string \| list&lt;string&gt; | Valeur cible TYPÉE : `REG_DWORD`/`REG_QWORD` → **entier** (zéro float, §4.1) ; `REG_SZ`/`REG_EXPAND_SZ` → **string** ; `REG_MULTI_SZ` → **liste de strings** (jamais `{}` — §4.1). |

> **🔴 Invariant central (27.3).** Le payload `registry` ne porte **JAMAIS** un
> `setting_id` / `setting_key` de catalogue serveur. Le catalogue
> (`registry_settings`) est un détail SERVEUR qui se **compile** en cet item
> concret dans le provider. C'est ce qui garde l'option « éditeur de clés brutes »
> (v2) gratuite : une 2ᵉ source d'autoring produira les **mêmes** items concrets
> → zéro changement d'agent/contrat/provider.

> **Story 27.3ter — `value` = override de parc, sinon défaut catalogue.** La
> STRUCTURE du payload est INCHANGÉE (5 clés). Côté serveur, le `value` émis est
> l'**override de parc** (`registry_setting_assignables.value`) si présent, sinon
> la **valeur par défaut du catalogue** (`registry_settings.value`) — cette
> dernière étant désormais **diffusée à TOUTES les machines** (maille Broadcast).
> L'agent et le golden ne changent pas : une valeur concrète reste une valeur
> concrète. « Retirer un override » = supprimer la ligne de pivot → le poste
> **re-converge vers le défaut** au cycle suivant (PAS « cesser de gérer »).

> **Portée → acteur (D-Q2).** UN seul handler Go `registry`, instancié deux fois :
> le **service SYSTEM** applique les items HKLM (portée `machine`), le
> **compagnon** applique les items HKCU (portée `session`). Comme les deux
> portées émettent le type `registry`, l'agent **fusionne par type** avant le
> rapport (pire statut gagne) pour respecter l'unicité des types §6.

### 7.2 Payload `associations` (Story 27.3bis)

Type `associations` — sémantique **`exclusive` PAR IDENTIFIANT** (une
extension/un protocole = UN programme par défaut ; la maille la plus spécifique
gagne POUR CET identifiant, les identifiants distincts s'accumulent). Portée
**`session`** (UserChoice HKCU, appliqué par le compagnon au logon). Le payload
porte une association **CONCRÈTE** :

```json
{
  "type": "associations",
  "semantics": "exclusive",
  "payload": {
    "identifier": ".html",
    "progid": "FirefoxHTML",
    "type": "file"
  },
  "hash": "5d4bb788…358468b"
}
```

| Clé | Type JSON | Sens |
|---|---|---|
| `identifier` | string | Extension (`.pdf`, `.html`) ou protocole (`http`, `https`). |
| `progid` | string | ProgId Windows cible inscrit sous UserChoice (ex. `Acrobat.Document.DC`, `FirefoxURL`). Peut être un ProgId **générique** `Applications\<exe>` (cf. note ci-dessous, Story 27.11). |
| `type` | string | `file` (→ `FileExts\<ext>\UserChoice`) \| `protocol` (→ `UrlAssociations\<proto>\UserChoice`). |

> **`progid` peut être `Applications\<exe>` (Story 27.11 — composer).** Quand
> l'admin compose une association *(extension, app)* sans ProgId riche déclaré pour
> cette extension, le serveur fabrique le ProgId **générique** `Applications\<exe>`
> (« Ouvrir avec », ce que Windows crée nativement) — ex. `Applications\vlc.exe`. Le
> **payload ne change PAS** (`{identifier, progid, type}`) et le **hash** est calculé
> sur la chaîne ProgId VERBATIM (le `\` n'est pas un cas particulier — confirmé
> empiriquement 2026-06-18). Le **chemin complet** de l'exe n'est JAMAIS dans le
> payload : le compagnon le résout sur le poste (App Paths/PATH) et auto-enregistre
> per-user `HKCU\Software\Classes\Applications\<exe>\shell\open\command` AVANT
> d'imposer UserChoice. Golden/contrat INCHANGÉS.

> **🔴 Le hash UserChoice n'est JAMAIS au payload.** Windows protège
> l'association par défaut par un hash anti-tamper dérivé de
> `extension + SID + ProgId + timestamp + experienceGUID`. Ses entrées ne sont
> connues que **sur le poste** (SID de session, FileTime courant, GUID
> `{D18B6DD5-…}` de `shell32.dll`) → le hash est calculé **100 % côté agent**.
> Le contrat ne porte que la cible logique `{identifier, progid, type}`.

> **🔴 Invariant central (27.3bis).** Le payload ne porte **JAMAIS** un
> `key`/`id` de catalogue (`file_associations`). Le catalogue se **compile** en
> cet item concret dans le provider — option « clés brutes » (v2) gratuite.

> **`source`/`wpkg_package` sont SERVEUR-only (D-Henri n°7, 27.3bis).** Le
> catalogue tague chaque association `native` (built-in Windows, toujours
> applicable) ou `wpkg` (ProgId fourni par un paquet WPKG, `wpkg_package` = le
> `<package id>` = `Application::app_id`). Ces colonnes alimentent la **validation
> prédictive de l'UI** (« paquet non déployé sur ce parc → cette association
> échouera ici », AVANT déploiement) mais **NE fuient JAMAIS au payload** : il
> reste `{identifier, progid, type}` INCHANGÉ. **L'agent ne connaît pas la notion
> native/wpkg** — il reste le dernier rempart via `ProgIDRegistered` (poste
> divergent). Le croisement WPKG vit dans l'UI/assignation (Livewire), jamais dans
> `AssociationsStateProvider` (PG-pur, NFR7), qui **émet toujours** (D-Henri n°3).

> **ProgId absent → choix préservé (D-Henri n°5).** Si le ProgId cible n'est pas
> enregistré sur le poste, l'agent **ne supprime pas et ne réécrit pas** la clé
> UserChoice existante (pas de clobber), et `Apply` ne rejoue PAS ce défaut
> inapplicable. `detail` = « ProgId X non enregistré, choix utilisateur conservé ».
>
> **⚠️ Granularité du statut = PAR TYPE, pas par item (grain §5 figé 27.8).** Le
> dispatch agent est par **type** : si AU MOINS un item `associations` échoue
> (ProgId absent, clé verrouillée), le type ENTIER est reporté `error` et **n'est
> pas persisté** (pas de cache 304 → re-convergence au cycle suivant). Les autres
> associations du même type sont quand même APPLIQUÉES en interne (`Apply`
> best-effort), mais le rapport masque le type en `error` tant qu'un item résiste.
> « error non fatal » signifie donc « n'avorte pas la passe / ne tue pas les autres
> TYPES » — **pas** « n'affecte pas les autres associations du même type ». Avec la
> non-intersection WPKG (D-Henri n°3), un défaut ciblant une app non installée
> maintient `associations` en `error` à chaque cycle : c'est **attendu**, pas un
> bug (un grain réel item×poste rouvrirait le débat 27.8, cf. mémoire
> `drift_policy_strict_only`).

> **« Désactiver = cesser de gérer ».** Une association retirée d'un parc
> DISPARAÎT → l'agent ne touche plus la clé (le choix courant reste, §8).

### 7.3 Payload `app_config` (Story 27.4)

Type `app_config` — sémantique **`aggregate` PAR `app_kind`** (un item par
application configurable : Firefox ET Thunderbird coexistent ; pour UNE app, un
seul jeu de policies effectif déjà fusionné côté serveur). Portée **`machine`**
(`policies.json` est **machine-wide**, posé sous `%ProgramFiles%\…\distribution\`
en contexte admin-write → écrit par le **service SYSTEM** ; résolution **PAR
PARC**, niveaux 1-4 : template → auto → défaut étab → WG, avec `$user = null`).
Le par-user de Firefox = le **profil** (Mécanisme B / roaming, hors 27.4), PAS
`policies.json`. Le payload porte les policies **RÉSOLUES CONCRÈTES** (le contenu
exact que l'agent écrit dans `policies.json`) :

```json
{
  "type": "app_config",
  "semantics": "aggregate",
  "payload": {
    "app_kind": "firefox",
    "policies": {
      "policies": {
        "DisableTelemetry": true,
        "Homepage": { "URL": "https://etab.local/" }
      }
    }
  },
  "hash": "cb347596…636ef8c2"
}
```

| Clé | Type JSON | Sens |
|---|---|---|
| `app_kind` | string | `firefox` \| `thunderbird` (les apps configurables par `policies.json` — `AppKind::cases()`). Pas de Chrome/Edge (le legacy ne gère aucune policy ; recadrage 2026-06-17). |
| `policies` | object | Les policies **résolues** (6 niveaux fusionnés serveur) à écrire telles quelles dans `policies.json`. Forme native de l'app (Firefox/Thunderbird = `{ "policies": { … } }`). Strings/ints/bools/null/objets/listes — **jamais de float** (§4.1 ; un float de policy est normalisé en string par le provider). |

**Mécanisme : UN SEUL — `policies.json` enterprise natif.** L'agent écrit un
`policies.json` au chemin natif d'install de l'app (Firefox
`…\Mozilla Firefox\distribution\policies.json`, Thunderbird au chemin
équivalent), en **écriture atomique**. PAS de mécanisme « registre policies »,
PAS de redirection de profil (sujet **roaming** serveur, renvoyé au domaine
roaming/`WorkstationEnvironment` — 26.x / story de suivi). Une app posée par
`policies.json` future = data serveur (un `AppKind` + un adapter), **zéro release
agent**.

> **🔴 Invariant central (27.4).** Le payload `app_config` ne porte **JAMAIS** un
> `customization_id` ni un scope de catalogue (`app_customizations`). La
> hiérarchie 6 niveaux (story 4.8) est un détail SERVEUR qui se **compile** en ces
> policies concrètes dans le provider — option « 2ᵉ source d'authoring » gratuite.

> **Marqueur de périmètre + conflit hors-périmètre (`error`).** Seul un
> `policies.json` posé par l'agent (clé d'extension `_sambaedu_managed: true`,
> inerte côté app) est géré. Un `policies.json` posé hors SambaEdu (autre outil,
> admin) au chemin natif n'est **JAMAIS écrasé ni supprimé** (non-ingérence). Mais
> comme la policy agent n'est alors PAS active, l'item de cette app est rapporté
> **`error`** (détail : « policies.json hors-périmètre présent, policy agent non
> appliquée ») et non `compliant` (qui serait trompeur). Les autres apps/types
> convergent (isolation).

> **« Désactiver = cesser de gérer ».** Une app retirée des règles voit son
> `policies.json` GÉRÉ **retiré** au passage suivant (level-triggered, drift
> STRICT) ; jamais un fichier hors périmètre.

> **App butée = limite connue.** Une app qui écrirait un réglage **sans aucun
> mécanisme enterprise natif** (pas de `policies.json` exploitable) n'est **PAS
> bricolée** par l'agent (pas de patch de config user, pas de hook) : le réglage
> est documenté comme non géré. Invariant : un handler n'écrit que via un
> mécanisme enterprise documenté de l'app.

> **Couplage installation (limite connue).** Pour que la policy ait un effet,
> l'app doit être installée (Firefox/Thunderbird présents) → story 27.5
> (applications/WPKG). Le service SYSTEM écrit sous Program Files ; si le dossier
> d'install est absent (app non installée), l'écriture échoue → `{status: error}`
> pour le seul type `app_config`, les autres types convergent.

### 7.4 Payload `applications` (Story 27.5)

Type `applications` — sémantique **`aggregate`** (un item par application
affectée au poste ; l'union poste + groupes + dépendances transitives), portée
**`machine`** (WPKG installe **machine-wide** → le **service SYSTEM** déclenche ;
leçon 🔴 27.4 #1 : portée de livraison = machine, jamais session). Le payload
porte un identifiant de paquet WPKG **CONCRET** :

```json
{
  "type": "applications",
  "semantics": "aggregate",
  "payload": {
    "app_id": "firefox",
    "name": "Mozilla Firefox"
  },
  "hash": "145bf532…0237a2d"
}
```

| Clé | Type JSON | Sens |
|---|---|---|
| `app_id` | string | Identifiant de paquet WPKG ( = `package-id` de `profiles.xml` = `Application::app_id`). Clé de déclenchement ET d'inventaire. |
| `name` | string | Libellé d'affichage de l'application (`Application::name`). Informe l'inventaire/les logs. Strings only (§4.1). |

**« Un tuyau, deux outils ».** L'agent unifie le **transport** (le déclencheur),
PAS le moteur de paquets. WPKG reste le **moteur déclaratif** (résolution de
dépendances, `<check>/<install>/<upgrade>`, versions) — il **n'est PAS absorbé**.
Le handler `applications` **déclenche** le moteur WPKG local
(`wpkg-client.vbs /NOTempo`) à la place de la GPO `se4_wpkg`, **donne l'URL** du
bundle (Apache statique) au bootstrap + **dépose** localement le profil par-hôte
(`profiles.xml`/`hosts.xml`), puis **lit `wpkg.xml`** pour l'état par paquet. Il
ne réimplémente **JAMAIS** l'installation, la détection de présence ou la
résolution de dépendances.

> **🔴 Invariant central (27.5).** Le payload `applications` ne porte **JAMAIS**
> un id de catalogue/pivot/scope ni une recette d'installation (pas de version,
> pas de `<check>`, pas de `<install>` — propriété de `packages.xml`). Juste
> l'`app_id` concret + son libellé. L'ensemble cible est projeté en lecture seule
> de la résolution WPKG existante (`WorkstationPackagesResolver::computePackages`,
> méthode **NON CACHÉE** — NFR7) : single source of truth, jamais une
> réimplémentation de l'union/BFS de dépendances.

> **« Désactiver = cesser de gérer ».** Une app retirée des affectations disparaît
> de l'ensemble cible → l'agent ne l'exige plus installée (l'inventaire la
> libère) ; il ne la **désinstalle pas** de lui-même (c'est WPKG qui le ferait via
> `<remove>` dans son profil — le handler ne touche jamais au poste hors du
> déclenchement WPKG).

> **Convergence level-triggered.** `Test` = « l'ensemble cible est-il déjà
> entièrement installé ? » (désiré ⊆ installé, lu dans `wpkg.xml`) → `compliant`
> sans re-déclenchement. Ensemble modifié / installation incomplète → `Apply`
> (déclenche WPKG, idempotent). Échec d'install après run = `error` + `detail`
> (jamais un faux `compliant`). Fini le poste joint qui n'installe rien : le
> déclencheur n'est plus l'événement GPO ponctuel au boot mais le **cycle agent
> répété** (boot/login/timer/forcer).

> **Livraison NATIVE SE5 (D6).** Le shim WPKG legacy (`/wpkg/hosts.xml`,
> `/wpkg/profiles.xml`) est **supprimé**. SE5 **génère** le bundle pré-substitué
> (`php artisan wpkg:bundle` : scripts versionnés `resources/wpkg/*` + catalogue
> `packages.xml`, `SE4FS_NAME` résolu) dans un sous-dossier servi en **statique
> par Apache** (`config('agent.wpkg_bundle_path')`, pas via Laravel). Le profil
> par-hôte est **déposé par l'agent** (D9). Les installeurs restent sur SMB
> (D11). La GPO `se4_wpkg` n'est **plus publiée** par SE5 (l'agent est le seul
> déclencheur — D2).

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

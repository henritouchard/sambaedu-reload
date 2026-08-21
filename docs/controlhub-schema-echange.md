# Schéma d'échange controlHub ↔ SE5

> **Ce document est la source unique du format** du contrat amont échangé entre
> l'autorité **controlHub** et **SE5**. Il est le seul écrit que les deux côtés
> partagent : toute évolution du format se répercute des deux côtés, et se reflète
> ici. Une divergence entre ce document et l'un des deux codes est un défaut, pas
> une variante.

> **Vocabulaire.** « amont », `upstream`, `authority`, `ControlHub*`. Le mot
> « central » est proscrit dans tout identifiant, colonne, message ou champ de
> schéma — il désignait dans SE4 une topologie qui n'a plus cours.

---

## 1. Version et compatibilité

- **Version courante** : `1.0` — champ `schema_version`, chaîne semver.
- Côté SE5, la version de référence et la liste des versions acceptées vivent dans
  `App\Services\ControlHub\ControlHubContractSchema` (`CURRENT_VERSION`,
  `SUPPORTED_VERSIONS`).
- **« Supportée » signifie égalité stricte** avec une entrée de `SUPPORTED_VERSIONS`.
  Accepter toute `1.x` reste possible le jour où une deuxième version existe ;
  l'écrire avant serait invérifiable de bout en bout.
- **Payload sans `schema_version`** : accepté, la version enregistrée est la version
  courante. Une version absente n'est jamais un motif de rejet.
- **Version déclarée mais non supportée** : rejetée. `ControlHubContractSchema::negotiate()`
  lève `UnsupportedSchemaVersionException` — un type dédié, distinct de
  `InvalidUpstreamContractException`. La négociation a lieu en validation pure, avant
  la transaction d'ingestion : **aucune écriture**, état persisté strictement inchangé.
  L'écart est tracé (« reçue ‹X› vs supportées ‹…› », log `warning` `{declared, supported}`)
  sans rien persister — un rejet ne mute pas l'état.

## 2. Placement de `schema_version`

Champ **à la racine** du payload, à côté des agrégats :

```json
{
  "schema_version": "1.0",
  "items": [ ... ],
  "labels": [ ... ],
  "imposed_groups": [ ... ],
  "catalog_apps": [ ... ]
}
```

SE5 enregistre la version retenue sur le contrat actif
(`controlhub_contracts.schema_version`) et l'expose dans `ContractIngestionResult::$schemaVersion`.

> **À ne jamais confondre.** `schema_version` versionne le **format d'échange**
> amont → SE5. Il est sans rapport avec `ContractV1` / `se5.desired-state/v1`, qui
> versionne le **contrat agent** émis par SE5 *vers le poste*. Le versionnement
> d'échange est serveur-only, invisible de l'agent.

## 3. Les agrégats

Schéma physique : `database/migrations/2026_06_26_100000_create_controlhub_contract_tables.php`.
Sémantique d'ingestion : `App\Services\ControlHub\ControlHubContractIngestionService`.

Tous les agrégats sont des **listes** ; une liste absente vaut liste vide, donc
**prune complet** de ses enfants.

### 3.1 `items` — items imposés

| Champ               | Type     | Domaine / règle                                                            | Requis |
| ------------------- | -------- | -------------------------------------------------------------------------- | ------ |
| `type`              | string   | vocabulaire d'entité amont (`capabilities`, `wallpapers`, `applications`, `shortcuts`, `agent_tools`, …) | oui |
| `key`               | string   | clé de l'item (identifiant de capacité, `app_id`, clé de raccourci…)       | oui    |
| `value`             | string?  | valeur ; sémantique selon `type` ; `null` = absence explicite              | non    |
| `enforcement_state` | enum     | `locked` \| `permissive` \| `absent`                                       | oui    |
| `target_type`       | enum     | `instance` \| `label` ; défaut `instance`                                  | non    |
| `target_label`      | string?  | nom du label ciblé si `target_type=label` ; `null`/absent/`''` ⇒ cible `instance` | conditionnel |
| `delivery_mode`     | string?  | mode de livraison du binaire côté amont (ex. `install`, `download_direct`). **Capturé, non arbitré** : stocké pour traçabilité, aucun domaine fermé, aucun rejet sur ce champ | non |
| `artifact`          | object?  | descripteur du binaire imposé (cf. §3.1.4). Le binaire **ne transite jamais** dans le payload | non |
| `spec`              | object?  | attributs typés dont le vocabulaire dépend de `type` (cf. §3.1.1). Un type que SE5 ne consomme pas encore voit son `spec` stocké tel quel. Les clés sont **triées à la réception** — sans quoi deux payloads identiques mais de clés désordonnées casseraient le no-op | non |

**Cohérence de cible**, rejet à la réception sinon : `target_type=label` exige un
`target_label` non vide ; `target_type=instance` exige un `target_label` vide.

**Clé naturelle** : `(contract, type, key, target_type, target_label)`.

#### 3.1.1 `spec` des items `shortcuts`

Un raccourci ne se décrit pas avec une clé et une valeur : l'agent **rejette en bloc**
tout raccourci sans `place`, et un raccourci utile porte aussi sa cible et ses
arguments. D'où ce vocabulaire, repris à l'identique des colonnes `shortcuts` de SE5
— mêmes noms que le canal de tâches historique, pour n'avoir qu'un vocabulaire à
connaître.

| Champ                  | Type    | Défaut si absent   | Rôle                                     |
| ---------------------- | ------- | ------------------ | ---------------------------------------- |
| `name`                 | string? | la `key` de l'item | nom affiché du raccourci                 |
| `place`                | enum?   | `desktop`          | `desktop` \| `startup` \| `taskbar` — **domaine fermé, rejet sinon** |
| `windows_link`         | string? | le `value` de l'item | cible Windows (exe ou URL)             |
| `windows_args`         | string? | `null`             | arguments                                |
| `windows_workdir`      | string? | `null`             | répertoire de travail                    |
| `windows_path`         | string? | `null`             | chemin complémentaire                    |
| `linux_link`           | string? | `null`             | cible Linux                              |
| `linux_args`           | string? | `null`             | arguments Linux                          |
| `linux_workdir`        | string? | `null`             | répertoire de travail Linux              |
| `linux_path`           | string? | `null`             | chemin Linux                             |
| `linux_startupwmclass` | string? | `null`             | `StartupWMClass` du `.desktop`           |
| `category`             | string? | `null`             | catégorie                                |
| `description`          | string? | `null`             | description                              |
| `is_url`               | bool?   | déduit de la cible | force le traitement en raccourci web     |

**Sans cible** — ni `spec.windows_link` ni `value` — l'item est reçu mais **aucun
raccourci n'est matérialisé** : un raccourci qui ne mène nulle part n'a rien à faire
sur un bureau. Le reste du contrat s'applique normalement, et l'item est rapporté en
`error` (§3.1.3).

**L'icône** passe par le bloc `artifact` de l'item, jamais en base64 dans le payload.
SE5 tire le `.ico`, vérifie son sha256 côté serveur, le range content-adressé
(`<checksum>.ico`) et le rattache au raccourci. Une icône déjà présente pour ce
checksum n'est pas re-téléchargée.

SE5 crée puis maintient une ligne dans sa bibliothèque de raccourcis, marquée de la
clé de l'item. Un raccourci que le contrat ne demande plus est **supprimé** ; les
raccourcis d'origine locale ou posés par le canal de tâches historique ne sont
**jamais** touchés. Un item `locked` fige le raccourci dans l'interface
d'administration, un item `permissive` le laisse modifiable.

#### 3.1.2 Ce que SE5 fait d'un item, par type et par cible

Un item ne « s'applique » pas tout seul : SE5 le traduit en une écriture dans **ses
propres** tables d'assignation — les mêmes que celles qu'un administrateur remplit à
la main. Un item ciblant un `label` est posé sur **chaque parc portant ce label** ;
un item ciblant l'`instance` devient un **défaut d'établissement**.

| `type`         | cible `label`                                | cible `instance`                        |
| -------------- | -------------------------------------------- | --------------------------------------- |
| `applications` | assignée à chaque parc porteur                | défaut de parc (tous les postes)        |
| `shortcuts`    | assigné à chaque parc porteur                 | défaut de parc (tous les postes)        |
| `capabilities` | valeur surchargée sur chaque parc porteur     | déplace le **défaut diffusé** de la capacité |
| `wallpapers`   | fond du parc porteur                          | fond par défaut de l'établissement      |
| `registry`     | appliqué par le compilé d'état (pas d'écriture d'assignation) | idem |
| `agent_tools`  | binaire tiré en bibliothèque ; pas d'assignation | idem |

**`capabilities`** : la `key` doit être une **clé de capacité du catalogue SE5** (ex.
`onedrive_hidden`), et `value` une valeur de son domaine (`on`/`off` pour un booléen).
Une clé inconnue n'est jamais appliquée en silence.

**`wallpapers`** : l'assignation exige que l'image ait été **tirée** au préalable
(bloc `artifact`, §3.1.4). Tant qu'elle ne l'est pas, le fond n'est pas posé — la
réception suivante, ou `php artisan controlhub:apply-assignments`, le rattrape.

> **Ce qui n'est jamais défait.** Le contrat retire ce qu'il a lui-même posé, et
> **rien d'autre** : une assignation faite à la main par l'administrateur survit à une
> réception qui ne la mentionne pas. Seule exception assumée, dans l'autre sens : un
> **défaut d'établissement** posé par le contrat sur un objet d'origine locale n'est
> pas retiré quand l'ordre disparaît — il reste visible et retirable à la main.

#### 3.1.3 Ce que SE5 rapporte de chaque item

Le rapport de conformité `se5-contract-compliance/v1` porte un `status` par item.
Ce statut est un **constat**, pas une déduction : il vient des réconciliateurs qui
ont essayé de poser l'item à la réception.

| `status`     | Ce que ça veut dire                                                             |
| ------------ | ------------------------------------------------------------------------------- |
| `applied`    | l'item est posé dans les tables de SE5                                           |
| `pending`    | l'ordre est recevable, l'état local n'est pas encore là — **personne n'a rien à corriger** |
| `error`      | l'ordre ne peut pas aboutir en l'état — **sans intervention, la prochaine réception échouera pareil** |
| `overridden` | l'item est `permissive` et un geste local l'a surchargé                          |

Le champ `detail` porte le motif en clair. Cas typiques :

| Situation                                                        | `status`  |
| ----------------------------------------------------------------- | --------- |
| Aucun parc ne porte le label ciblé                                 | `pending` |
| Application ordonnée absente de l'inventaire local                 | `pending` |
| Image d'un fond déclarée mais pas encore tirée                     | `pending` |
| Fond d'écran **sans bloc `artifact`**                              | `error`   |
| Raccourci **sans cible** (ni `spec.windows_link` ni `value`)       | `error`   |
| Clé de capacité inconnue du catalogue SE5                          | `error`   |
| Échec du pull d'un binaire (sha256 non concordant, réseau)         | `error`   |

Un item bloqué par son binaire rapporte **le binaire**, pas l'assignation : le pull
est la cause, l'assignation manquante n'est que le symptôme.

Les types que SE5 ne traduit pas en assignation (`registry`, `agent_tools`) gardent
la règle antérieure : `locked` → `applied`.

#### 3.1.4 Le bloc `artifact` — pull des binaires imposés

Significatif pour `type ∈ {wallpapers, agent_tools, shortcuts}`.

| Sous-champ | Type   | Rôle                                                                 |
| ---------- | ------ | -------------------------------------------------------------------- |
| `url`      | string | URL **signée, régénérée à chaque émission** — jamais persistée en colonne |
| `checksum` | string | sha256 hex — **identité stable** du binaire                          |
| `filename` | string | informatif ; jamais utilisé tel quel pour le nommage disque          |
| `size`     | int    | octets                                                                |

SE5 lit et persiste `delivery_mode` et `artifact.{checksum,filename,size}`. Pour un
item porteur d'un `artifact` complet dont l'asset **n'existe pas déjà localement**,
il tire le binaire après commit, **vérifie le sha256 côté serveur**, puis le
matérialise dans le foyer local. Un checksum non concordant laisse l'item en `error`
sans rien matérialiser.

**Idempotence par checksum, jamais par URL.** Les URL signées étant régénérées à
chaque émission, le calcul de mutation ne porte que sur l'identité stable ;
`artifact.url` n'est pas une colonne, donc ne peut pas polluer le no-op.

Un payload **sans** `artifact` reste accepté à l'identique.

### 3.2 `labels` — labels imposés

| Champ  | Type   | Domaine / règle                             | Requis |
| ------ | ------ | ------------------------------------------- | ------ |
| `name` | string | nom du label (ex. `salle-info`, `nomade`)   | oui    |
| `mode` | enum   | `free` \| `reserved`                        | oui    |

**Clé naturelle** : `(contract, name)`.

### 3.3 `imposed_groups` — groupes imposés

| Champ         | Type    | Domaine / règle                                                               | Requis |
| ------------- | ------- | ----------------------------------------------------------------------------- | ------ |
| `name`        | string  | nom du `WorkstationGroup` à garantir (`workstation_groups.name`)              | oui    |
| `label_name`  | string? | label associé ; s'il est non nul, il doit être **déclaré** dans `labels` ; `null`/`''`/absent légitime | non |
| `is_physical` | bool?   | nature du parc réclamée. `true` ⇒ **salle physique** (SE5 crée l'`OU` sous `OU=Computers`, là où les GPO sont liées) ; `false` ⇒ **parc logique** (purement SQL, aucune écriture AD) ; **absent/`null`** ⇒ non déclarée, SE5 applique son défaut (**logique**). Accepté aussi en chaîne (`"true"`/`"false"`) ou entier (`1`/`0`) ; toute autre valeur est rejetée | non |

**Clé naturelle** : `(contract, name)`.

> **La nature n'est appliquée qu'à la CRÉATION.** Sur un groupe qui existe déjà avec
> une nature différente, SE5 ne bascule pas le parc : passer en physique créerait une
> `OU` AD, l'inverse en laisserait une orpheline, et les postes membres changeraient
> de rattachement AD sans demande explicite. La divergence est **tracée**
> (`warning`, `{group_name, local_is_physical, imposed_is_physical}`) pour arbitrage
> humain, jamais résolue en silence.

### 3.4 `catalog_apps` — catalogue applicatif faisant autorité

| Champ            | Type    | Domaine / règle                                                  | Requis |
| ---------------- | ------- | ----------------------------------------------------------------- | ------ |
| `app_key`        | string  | identifiant d'app faisant autorité (`applications.app_id`)        | oui    |
| `display_name`   | string? | nom d'affichage ; `''` normalisé `null`                           | non    |
| `source_xml_url` | string? | référence de source par-app (dépôt SambaEdu) ; `''` → `null`      | non    |
| `source_xml_sha` | string? | empreinte de la source par-app ; `''` → `null`                    | non    |
| `version`        | string? | version d'affichage dans le dépôt imposé ; `''` → `null`          | non    |
| `category`       | string? | catégorie d'affichage dans le dépôt imposé ; `''` → `null`        | non    |
| `icon_url`       | string? | URL d'icône d'affichage dans le dépôt imposé ; `''` → `null`      | non    |
| `executable`     | object? | descripteur d'exécutable, même forme qu'`artifact`. **Persisté seulement** : SE5 stocke `checksum`/`filename`/`size`, ne tire ni ne matérialise rien | non |

**Clé naturelle** : `(contract, app_key)`.

> **Le catalogue du contrat EST la source du dépôt imposé.** L'autorité amont n'émet
> jamais de `packages.xml` : elle envoie du JSON via `catalog_apps`. SE5 le projette
> table → table en dépôt imposé (`controlhub_contract_catalog_apps` →
> `depot_applications`), sans aucun appel HTTP ni parsing XML de catalogue. Les
> recettes unitaires, elles, restent des XML WPKG téléchargés à `source_xml_url`.
>
> Conséquence pour l'amont : `catalog_apps` doit être **peuplé** pour toute instance
> dont le canal dépôts doit basculer. Un contrat actif à catalogue **vide** = **pas de
> bascule** — le verrou d'ajout reste actif, mais rien n'est détruit.

### 3.5 `contract` — porteur de l'état

Le contrat racine (`controlhub_contracts`) porte l'état du lien et la version de
schéma reçue. Il **n'est pas** un agrégat du payload : ses attributs sont dérivés
côté SE5.

| Attribut         | Type     | Origine                                                                          |
| ---------------- | -------- | -------------------------------------------------------------------------------- |
| `link_state`     | enum     | `active` \| `severed` ; **jamais** lu du payload (réception ⇒ `active`)          |
| `received_at`    | datetime | horodatage de la dernière réception, mis à jour sur mutation                     |
| `schema_version` | string?  | version négociée du dernier payload reçu                                          |

## 4. Idempotence

- Réception **identique** — même contenu **et** même version effective — ⇒ **no-op
  total** : aucune écriture, `schema_version` et `received_at` inchangés, aucun
  événement `ControlHubContractChanged`.
- **Changement de version supportée** à contenu identique ⇒ **mutation** : nouvelle
  version enregistrée, événement émis **une fois**.

## 5. Faire évoluer le format

Un champ **additif optionnel** ne bumpe pas `schema_version` : un payload qui ne le
porte pas reste accepté à l'identique. C'est la voie normale — `source_xml_url`,
`artifact`, `spec`, `is_physical` sont tous arrivés ainsi.

Toute autre évolution — champ requis, domaine fermé modifié, sémantique changée —
impose un bump de `schema_version`, la mise à jour de ce document, et la
répercussion des deux côtés.

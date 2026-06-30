# Schéma d'échange controlHub ↔ SE5 — source unique versionnée

> **Statut** : artefact partagé (R2). Ce document est la **source unique** du format de payload
> du **contrat amont** échangé entre l'autorité **controlHub** (amont) et **SE5**. Toute évolution
> du format doit être répercutée **des deux côtés** et reflétée ici.
>
> **Stories d'origine** : 33.1 — « Schéma d'échange versionné » + 33.2 — « Négociation et rejet
> gracieux d'une version incompatible » (Epic 33, **clos**).
> **Périmètre 33.1** : formaliser + versionner le schéma ; accepter un payload **conforme** ou
> **sans version** (chemin heureux).
> **Périmètre 33.2** (livré) : la négociation devient **stricte** — une version **déclarée** non
> supportée est **rejetée** (état inchangé, écart tracé « reçue vs supportées »). Le chemin heureux
> 33.1 est inchangé.
>
> ⚠️ **Vocabulaire (R3)** : « amont » / `upstream` / `authority` / `ControlHub*`. Le mot
> « central » est **proscrit** dans tout identifiant, colonne, message ou champ de schéma.

---

## 1. Version courante & politique de compatibilité

- **Version courante** : `1.0` (champ `schema_version`, **chaîne semver**).
- Côté code SE5, la version de référence et la liste des versions supportées vivent dans
  `App\Services\ControlHub\ControlHubContractSchema` (`CURRENT_VERSION`, `SUPPORTED_VERSIONS`).
- **« Supportée »** signifie **égalité stricte** avec une version de `SUPPORTED_VERSIONS` (à ce
  jour, la seule version courante).
- **Compatibilité sur le MAJOR** (accepter toute `1.x`) : **différée tant qu'une seule version
  existe** (Story 33.2, Q1). La coder sans 2ᵉ version réelle à confronter serait spéculatif et non
  testable de bout en bout (anti sur-engineering). `SUPPORTED_VERSIONS`/`isSupported()` restent le
  point d'extension propre le jour où une 2ᵉ version apparaît.
- **Payload sans `schema_version`** (cas de transition — les payloads de l'Epic 28 n'en portent
  pas) : **accepté**, version enregistrée = **version courante** (rétro-compatibilité, décision
  Q1=A). Une version **absente** n'est **jamais** un motif de rejet.
- **Rejet d'une version déclarée non supportée (Story 33.2, livré)** : une `schema_version`
  **déclarée** (chaîne non vide) hors `SUPPORTED_VERSIONS` est **rejetée** —
  `ControlHubContractSchema::negotiate()` lève `UnsupportedSchemaVersionException` (type **dédié**,
  distinct de `InvalidUpstreamContractException`). La négociation a lieu en **validation pure**
  (avant la transaction d'ingestion) ⇒ **aucune écriture**, état persisté **strictement inchangé**.
  **Trace** de l'écart : message « reçue ‹X› vs supportées ‹…› » + log structuré `warning`
  `{declared, supported}`. **Aucune** persistance d'audit (un rejet ne mute pas l'état).

## 2. Placement du champ `schema_version`

`schema_version` est un champ **à la racine** du payload, à côté des cinq agrégats :

```json
{
  "schema_version": "1.0",
  "items": [ ... ],
  "labels": [ ... ],
  "imposed_groups": [ ... ],
  "catalog_apps": [ ... ]
}
```

Côté SE5, la version retenue est **enregistrée** sur le contrat actif (colonne
`controlhub_contracts.schema_version`, ajoutée par migration additive en 33.1) et **exposée**
dans le DTO de résultat d'ingestion (`ContractIngestionResult::$schemaVersion`).

> ⚠️ **Distinction à ne jamais confondre** : `schema_version` versionne le **format d'échange**
> amont→SE5. Il est **sans rapport** avec `ContractV1` / `se5.desired-state/v1`, qui versionne le
> **contrat agent** (desired-state émis par SE5 *vers l'agent*, figé/golden). Le versionnement de
> schéma d'échange est **serveur-only**, invisible de l'agent.

## 3. Les cinq agrégats

Source de vérité du schéma physique : migration
`database/migrations/2026_06_26_100000_create_controlhub_contract_tables.php` (Story 28.1) ;
sémantique d'ingestion : `App\Services\ControlHub\ControlHubContractIngestionService` (28.2+).
Tous les agrégats sont des **listes** ; absence ⇒ liste vide (prune complet des enfants).

### 3.1 `items` — items imposés

| Champ               | Type     | Domaine / règle                                                            | Requis |
| ------------------- | -------- | -------------------------------------------------------------------------- | ------ |
| `type`              | string   | vocabulaire d'entité amont (`capabilities`, `wallpapers`, `applications`, `shortcuts`, `agent_tools`, …) | oui |
| `key`               | string   | clé de l'item (ex. identifiant de capacité, `app_id`, clé de raccourci)    | oui    |
| `value`             | string?  | valeur ; sémantique selon `type` ; `null` = absence explicite              | non    |
| `enforcement_state` | enum     | `locked` \| `permissive` \| `absent` (`ControlHubEnforcementState`)        | oui    |
| `target_type`       | enum     | `instance` \| `label` (`ControlHubContractTarget`) ; défaut `instance`     | non    |
| `target_label`      | string?  | nom du label ciblé si `target_type=label` ; `null`/absent/`''` ⇒ cible `instance` (normalisé `null→''`) | conditionnel |

Cohérence de cible (rejet à la réception sinon) : `target_type=label` exige un `target_label`
non vide ; `target_type=instance` exige un `target_label` vide.
Clé naturelle : `(contract, type, key, target_type, target_label)`.

### 3.2 `labels` — labels imposés

| Champ  | Type   | Domaine / règle                                  | Requis |
| ------ | ------ | ------------------------------------------------ | ------ |
| `name` | string | nom du label (ex. `salle-info`, `nomade`)        | oui    |
| `mode` | enum   | `free` \| `reserved` (`ControlHubLabelMode`)     | oui    |

Clé naturelle : `(contract, name)`.

### 3.3 `imposed_groups` — groupes imposés

| Champ        | Type    | Domaine / règle                                                               | Requis |
| ------------ | ------- | ----------------------------------------------------------------------------- | ------ |
| `name`       | string  | nom du `WorkstationGroup` à garantir (correspond à `workstation_groups.name`) | oui    |
| `label_name` | string? | label associé ; s'il est **non nul**, il doit être **déclaré** dans `labels` (intégrité référentielle 30.1) ; `null`/`''`/absent légitime | non |

Clé naturelle : `(contract, name)`.

### 3.4 `catalog_apps` — catalogue applicatif faisant autorité

| Champ            | Type    | Domaine / règle                                                  | Requis |
| ---------------- | ------- | --------------------------------------------------------------- | ------ |
| `app_key`        | string  | identifiant d'app faisant autorité (correspond à `applications.app_id`) | oui |
| `display_name`   | string? | nom d'affichage (informatif) ; `''` normalisé `null`            | non    |
| `source_xml_url` | string? | référence de source par-app (dépôt SambaEdu, Story 31.3) ; `''`→`null` | non |
| `source_xml_sha` | string? | empreinte de la source par-app (Story 31.3) ; `''`→`null`       | non    |

Clé naturelle : `(contract, app_key)`.

### 3.5 `contract` — porteur de l'état

Le contrat racine (`controlhub_contracts`) porte l'état du lien et, depuis 33.1, la version de
schéma reçue. Il n'est **pas** un agrégat du payload : ses attributs sont **dérivés** côté SE5.

| Attribut         | Type    | Origine                                                                          |
| ---------------- | ------- | -------------------------------------------------------------------------------- |
| `link_state`     | enum    | `active` \| `severed` ; **jamais** lu du payload (réception ⇒ `active` ; rupture = Epic 32) |
| `received_at`    | datetime| horodatage de la dernière réception (mis à jour sur mutation)                    |
| `schema_version` | string? | **version négociée** du dernier payload reçu (Story 33.1)                        |

## 4. Idempotence & enregistrement de version (NFR4)

- Réception **identique** (même contenu **et** même version effective) ⇒ **no-op total** : aucune
  écriture, `schema_version`/`received_at` inchangés, aucun événement `ControlHubContractChanged`.
- **Changement de version supportée** (contenu sinon identique) ⇒ **mutation** : nouvelle version
  enregistrée + événement émis **une fois**. La comparaison de version est intégrée au calcul de
  mutation du contrat racine (calquée sur `received_at`/`link_state`), jamais écrite à l'identique.

## 5. Coordination R2 (note pour les deux BMAD)

> Cet artefact est la **référence unique** du schéma d'échange. **Les deux BMAD** doivent y
> pointer :
> - **côté SE5** : référencé depuis `prd-contrat-manage-se5.md#9` (couture controlHub) ;
> - **côté controlHub** : le **handoff §7** (`handoff-controlhub-contrat-manage.md`, autre BMAD,
>   worktree E30) doit **renvoyer symétriquement** à ce document comme source unique du format.
>   Action de coordination **consignée ici** ; l'édition du handoff est **hors périmètre** de la
>   Story 33.1 (autre dépôt).

Toute évolution du format = bump de `schema_version` + mise à jour de ce document + répercussion
des deux côtés. Le **rejet gracieux** d'une version incompatible (négociation stricte, trace de
l'écart « reçue vs supportées », état inchangé) est **livré par la Story 33.2** (cf. §1) ;
l'**Epic 33 est clos**. Côté code SE5, le rejet est `App\Exceptions\ControlHub\UnsupportedSchemaVersionException`
levée par `ControlHubContractSchema::negotiate()` et propagée telle quelle par
`ControlHubContractIngestionService::ingest()`.

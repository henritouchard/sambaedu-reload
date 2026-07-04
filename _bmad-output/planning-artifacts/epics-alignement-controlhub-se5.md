---
stepsCompleted: [step-01-validate-prerequisites, step-02-design-epics]
epicNumbering: "39 (suite du backlog projet, max existant = Epic 38)"
inputDocuments:
  - _bmad-output/planning-artifacts/prd-contrat-manage-se5.md
  - _bmad-output/planning-artifacts/schema-echange-controlhub-se5.md
  - ../irundoo/documentation/CONTRAT-MANAGE-SE5-IRUNDO.md
  - ../irundoo/documentation/SE5-CONTRACT-COMPLIANCE-V1.md
docType: feature-epics
feature: alignement-controlhub-se5
scope: SE5 (sambaedu-reload) — côté local uniquement
note: "Fichier DÉDIÉ — suite de epics-contrat-manage-se5.md (Epics 28-33, clos). Cadrage haut niveau : ACs à figer story par story au moment du dev-cycle (patron Epics 16/17/35/36)."
---

# Alignement de la couture controlHub ↔ SE5 - Epic Breakdown (Epic 39)

## Overview

Les Epics 28-33 ont livré, côté SE5, la **réception, la résolution et l'enforcement** d'un contrat amont
générique, plus le **schéma d'échange versionné** (source unique R2 `schema-echange-controlhub-se5.md`).
Ce qui restait délibérément **non câblé** (famille OPEN-5, différée) ou **désynchronisé** est cadré ici,
suite à un **audit de compatibilité croisé** (2026-07-04) entre l'implémentation SE5 et le contrat tel
qu'implémenté côté central (docs `CONTRAT-MANAGE-SE5-IRUNDO.md` + `SE5-CONTRACT-COMPLIANCE-V1.md`,
board central clos le même jour).

Résultat de l'audit — 5 canaux du contrat managé :

| Canal | État SE5 constaté | Verdict | Story |
|---|---|---|---|
| ② Rupture (`sever-link`) | Route + service + réponse (`items_lifted`, `values_materialized`) conformes | ✅ Compatible | — |
| ① Diffusion (`se5-contract/v1`) | `ControlHubContractIngestionService::ingest()` existe mais **aucune route HTTP** ne l'expose (invoqué en test seulement) | ⚠️ Gap OPEN-5, **bloquant** | **39.1** |
| ③ Conformité (`se5-contract-compliance/v1`) | **Aucun émetteur** SE5 vers `/api/sambaedu/contract-compliance/{instance}` | ⚠️ Gap OPEN-5 | **39.2** |
| ⑤ SSO fédéré | Callback + vérif JWT RS256 corrects **en structure**, mais la clé/kid/iss reçus au **handshake** (`controlhub_connection.idp_*`) sont **stockés puis jamais branchés** sur `FederatedJwtVerifier` (lit uniquement `config/federated_auth.*`) ; `aud` par défaut = `SE4FS_NAME` ≠ uuid d'instance émis par le central | 🔴 **Bug de câblage interne** | **39.3** |
| ④ Binaires (`artifact`/`executable`/`delivery_mode`) | Émis par le central (Epic D + extension D.7) mais **ignorés à l'ingestion** ; absents aussi de l'artefact partagé R2 | ⚠️ **Divergence de modèle de livraison** | **39.4** |

### La divergence du canal ④ (contexte décisif)

SE5 est architecturé en **désir d'état pur** : le contrat amont impose **quoi** (quelle capacité / quel
fond / quel outil / quelle app), **jamais le binaire**. Les binaires proviennent aujourd'hui exclusivement
de sources **locales** : bibliothèque `wallpapers`/`wallpaper_assets` (+ `AssetController`), dépôt
`config('agent.tools_path')` (+ `ToolController`, sha256 serveur), référentiel WPKG du dépôt SambaEdu via
`source_xml_url` (+ `OrderedApplicationProvisioner`). Le central, lui, **héberge** ses binaires et diffuse
des **URL signées + checksum** (canal ④). Les deux BMAD divergent donc sur *où vivent les binaires*. Cela
fonctionne tant que tout binaire référencé par le central **existe déjà en local** sur SE5 ; le risque est
**sémantique** : un asset téléversé au référentiel central mais absent du local SE5 se résoudrait dans le
vide.

**Décision produit (Henri, 2026-07-04) : adopter le pull central (canal ④).** SE5 ingère les blocs
`artifact`/`executable`/`delivery_mode` et **télécharge** le binaire depuis le central (vérif sha256) quand
l'asset local est absent. Le désir d'état reste piloté par la clé ; le pull **comble l'absence** locale.

> **Constat de gouvernance R2** : l'artefact partagé `schema-echange-controlhub-se5.md`, censé être la
> **source unique synchronisée entre les deux BMAD**, ne définit ni `delivery_mode`, ni `artifact`, ni
> `executable`. Le central a introduit ces champs (Epic D/D.7) **sans les répercuter** dans l'artefact ni
> monter `schema_version` (toléré par la doctrine « additif = même version », mais non documenté). La
> Story 39.4 **remet l'artefact R2 en cohérence** ; toute divergence résiduelle constatée à la
> ratification donnera lieu à une story d'alignement (famille OPEN).

## Requirements Inventory

### Functional Requirements

FR-A1: SE5 **expose un endpoint HTTP** de réception du contrat amont (`se5-contract/v1`), câblé sur
l'ingestion idempotente existante, authentifié par le canal `controlhub.auth` (clé API instance en Bearer).
FR-A2: SE5 **émet** un rapport de conformité **état-intégral** (`se5-contract-compliance/v1`) vers le
central, sur la clé naturelle miroir de l'émission, avec les statuts `applied|pending|error|overridden`.
FR-A3: Le SSO fédéré **utilise l'IdP provisionné au handshake** (clé publique/kid/iss) et valide l'`aud`
contre l'**uuid d'instance**, supprimant tout réglage env manuel fragile.
FR-A4: SE5 **ingère et tire** les binaires amont (`artifact`/`executable`, mode `delivery_mode`) avec
vérification d'intégrité sha256, en fallback de la source locale.

### NonFunctional Requirements

NFR-A1: L'ingestion reste le **seul** écrivain de `controlhub_contract_*` (NFR3 hérité) ; sans contrat
reçu, comportement SE5 strictement inchangé.
NFR-A2: **Idempotence** de bout en bout — ré-réception d'un contrat = no-op ; ré-émission d'un rapport de
conformité identique = no-op naturel (garde de fraîcheur `reported_at` côté central) ; ré-pull d'un binaire
au même checksum = no-op.
NFR-A3: **Sécurité transport** — HTTPS exigé en prod ; token/clé jamais loggés ; ordre de contrôle strict
côté endpoints entrants (auth avant lecture du corps).
NFR-A4: **Contrat agent figé intact** — `StateCompiler`/`ContractV1`/golden/`FROZEN_STATE_HASH`/`agent/**`
non touchés par 39.1-39.3 ; 39.4 peut toucher les providers de binaires (wallpaper/tools) → vérifier
l'invariant golden et bump `agent/shared/version.go` **uniquement** si le payload agent change.
NFR-A5: **Vocabulaire (R3)** — aucun « central » dans un identifiant/colonne/message SE5 ; « amont » /
`upstream` / `authority` / `ControlHub*`.

### Additional Requirements

- Réutiliser les services existants — `ControlHubContractIngestionService` (39.1), `ControlHubApiClient` +
  `controlhub_connection.api_token` du handshake (39.2), `FederatedJwtVerifier` + `HandshakeResponse.idp_*`
  (39.3), `WallpaperStateProvider`/`AgentToolService`/`OrderedApplicationProvisioner` (39.4). Ne pas
  réinventer.
- Les schémas `se5-contract/v1` et `se5-contract-compliance/v1` sont des **contrats de données partagés**
  (R2). Toute story qui en fige un champ doit répercuter dans `schema-echange-controlhub-se5.md` et
  signaler la coordination avec le BMAD controlHub.
- Fenêtre `routes/api.php` : nouveaux blocs API **après** le groupe 16.12 (piège de la fenêtre 1500 chars).
- Hygiène config : `ControlHubAuth` référence `config('controlHub.master_api_key')` **absent** du fichier
  de config (branche morte) → à définir explicitement ou retirer (traité en tâche de 39.1).

### FR Coverage Map

FR-A1: Epic 39 — Story 39.1 (endpoint d'ingestion, canal ①)
FR-A2: Epic 39 — Story 39.2 (émetteur de conformité, canal ③)
FR-A3: Epic 39 — Story 39.3 (bridge IdP handshake → verifier, canal ⑤)
FR-A4: Epic 39 — Story 39.4 (ingestion + pull des binaires, canal ④)

## Epic 39: Activation & alignement de la couture controlHub ↔ SE5

Rendre le lien managé **réellement opérant** de bout en bout : exposer la réception du contrat, émettre la
conformité attendue par le central, faire fonctionner le SSO fédéré avec l'IdP réellement provisionné, et
réconcilier le modèle de livraison des binaires (pull central). Chaque story reste **autonome et
déployable** ; l'ordre recommandé est 39.1 → (39.2 ∥ 39.3 ∥ 39.4).
**FRs covered:** FR-A1..FR-A4, NFR-A1..NFR-A5

---

### Story 39.1: Exposer l'endpoint HTTP d'ingestion du contrat amont (canal ①)

**Intention.** Câbler `POST /api/v1/controlhub/contract` (conventionnel côté central : OPEN-5) sur
l'ingestion idempotente **déjà livrée** (`ControlHubContractIngestionService::ingest()`, Epics 28/33) —
sans réécrire l'ingestion. Contrôleur mince, auth `controlhub.auth` (clé API instance en Bearer, symétrique
du `sever-link` 32.1), mapping des exceptions de domaine en codes HTTP.

**AC-skeleton (à figer au create-story) :**
- Route `POST /v1/controlhub/contract` sous `controlhub.auth`, déclarée après le groupe 16.12 de
  `routes/api.php` (fenêtre 1500 chars).
- Un payload conforme (`se5-contract/v1`) est ingéré via `ingest()` → **200** avec le résumé du
  `ContractIngestionResult` ; ré-réception identique = **no-op** (NFR4).
- `UnsupportedSchemaVersionException` / `InvalidUpstreamContractException` → **422** (état inchangé, écart
  tracé) ; auth invalide → réponse `controlhub.auth` existante.
- Aucune régression de l'ingestion existante ; `StateCompiler`/contrat agent intacts (NFR-A4).
- **Tâche** : trancher la branche morte `master_api_key` (définir en config ou retirer du middleware).

**Dépendances.** Amont : 28.2, 33.1/33.2 (DONE). **Bloquant** pour tout le reste (sans réception, pas de
lien). **Questions ouvertes** : forme exacte du corps de réponse 200 (résumé complet vs minimal) ;
faut-il un rate-limit dédié.
**Reco dev** : sonnet (câblage/mapping HTTP, peu de logique neuve) — à confirmer au create-story.

### Story 39.2: Émetteur de conformité `se5-contract-compliance/v1` (canal ③)

**Intention.** Livrer l'émetteur SE5 (service + job + command) qui compile l'**état d'application** des
items du contrat en un **rapport état-intégral** et le **POST** vers
`{controlhub_base}/api/sambaedu/contract-compliance/{instance}` avec le Bearer `api_token` reçu au
handshake (`controlhub_connection`, via `ControlHubApiClient`). Aligné sur la spec figée côté central
`SE5-CONTRACT-COMPLIANCE-V1.md` (proposition **non ratifiée** — toute divergence → story d'alignement).

**AC-skeleton (à figer au create-story) :**
- Enveloppe `{schema_version:'1.0', instance_id, link_state, contract_received_at, reported_at, items[]}` ;
  item = clé naturelle **miroir** de l'émission `(type, key, target_type, target_label)` + `status` ∈
  `applied|pending|error|overridden` (+ `detail`, `observed_at`).
- `status=overridden` = **indicateur d'override permissif** (FR24) — pas de booléen séparé.
- `items: []` valide (purge légitime post-release) ; `reported_at` monotone (le central applique la garde
  de fraîcheur).
- Émission via `ControlHubApiClient` (HTTPS, token du handshake, jamais loggé) ; endpoint ajouté à
  `config/controlHub.php`.
- Sans lien actif / sans contrat : pas d'émission parasite (NFR-A1).

**Dépendances.** Amont : handshake (`api_token`) + résolution d'état (Epics 28-31) + reporting agent
existant (source des statuts). **Questions ouvertes** : cadence (piggyback sur le heartbeat vs job
périodique dédié) ; source des statuts par item (agrégation des rapports agent 24.x) ; ratification du
schéma avec le BMAD controlHub.
**Reco dev** : opus (compilation état-intégral + mapping statuts + idempotence/fraîcheur).

### Story 39.3: Brancher l'IdP fédéré du handshake sur le vérificateur JWT (canal ⑤)

**Intention.** Supprimer le trou de câblage : la clé publique / `kid` / `iss` que le central transmet au
**handshake** (persistés `controlhub_connection.idp_public_key/idp_kid/idp_iss`) doivent **alimenter**
`FederatedJwtVerifier` (qui lit aujourd'hui **uniquement** `config/federated_auth.*`, laissant l'IdP
provisionné mort). Et l'`aud` attendu doit être l'**uuid d'instance** (`controlHub.se4fs.instance_id`), pas
le fallback `SE4FS_NAME`.

**AC-skeleton (à figer au create-story) :**
- Le verifier résout sa key-map / `expected_iss` / `expected_aud` depuis l'IdP provisionné au handshake
  (bridge DB→verifier), avec la config env comme **repli** explicite (pas l'inverse).
- `aud` validé contre l'uuid d'instance ; un JWT émis par le central (kid du handshake) est accepté sans
  réglage env manuel.
- Le rôle porté par le jeton (`super-admin` côté central) est **résolu** (existence Spatie garantie / seed)
  ou mappé — sinon 403 documenté.
- Anti-régression : RS256 pinné, `exp/nbf/iss/aud/tier` + anti-rejeu `jti` inchangés ; le chemin env pur
  reste fonctionnel en absence de handshake.

**Dépendances.** Amont : handshake (`HandshakeResponse.idp_*` déjà persisté). **Indépendante** de 39.1/2/4.
**Questions ouvertes** : rotation de clé (plusieurs kid) ; comportement si l'IdP du handshake diverge de
l'env ; provisioning du rôle `super-admin`.
**Reco dev** : opus (sécurité JWT + précédence de sources + non-régression anti-rejeu).

### Story 39.4: Ingestion + pull des binaires amont (canal ④ : `artifact`/`executable`/`delivery_mode`)

**Intention.** Adopter le **pull central** (décision 2026-07-04) : étendre le schéma d'échange et
l'ingestion pour lire `delivery_mode` (items), le bloc `artifact {url, checksum, filename, size}` (items
`wallpapers`/`agent_tools`) et `executable {…}` (`catalog_apps`, extension D.7), puis **télécharger** le
binaire (URL signée, vérif **sha256**) en **fallback** quand l'asset local est absent. Le désir d'état reste
piloté par la clé ; le pull comble l'absence.

**AC-skeleton (à figer au create-story) :**
- **Artefact R2 d'abord** : `schema-echange-controlhub-se5.md` documente `delivery_mode`, `artifact`,
  `executable` comme champs **additifs optionnels** (pas de bump `schema_version` — doctrine additive) ;
  coordination BMAD controlHub notée.
- Migration additive (colonnes/table de suivi des artefacts) ; ingestion lit et persiste les nouveaux
  champs sans casser le no-op 28.2/33.
- Client de pull : télécharge depuis l'URL signée, **vérifie le sha256**, matérialise dans le foyer local
  approprié (biblio wallpaper / `tools_path` / store app) ; ré-pull au même checksum = **no-op** ;
  échec de checksum = item en `error` (remonté par 39.2).
- **Précédence** : source locale existante prioritaire ; pull uniquement si l'asset local (par clé) est
  absent. Comportement à 0 binaire amont strictement inchangé (golden préservé ; bump `agent/shared/version.go`
  **seulement** si le payload agent change).

**Dépendances.** Amont : 39.1 (réception) ; providers de binaires locaux (wallpaper/tools/app store).
**Questions ouvertes** : foyer de stockage des binaires tirés ; moment du pull (à l'ingestion vs à la
résolution/au check-in agent) ; TTL des URL signées et re-signature ; que faire d'un `delivery_mode`
inconnu.
**Reco dev** : opus (extension de schéma + intégrité + fallback + préservation du contrat agent figé).

---

## Notes de coordination (R2 — deux BMAD)

- **39.1 / 39.2** dépendent des chemins exacts exposés/attendus côté central
  (`/api/v1/controlhub/contract`, `/api/sambaedu/contract-compliance/{instance}`) — figés dans les docs
  central mais à **ratifier** ; toute divergence = story d'alignement (famille OPEN).
- **39.4** ré-synchronise la source unique `schema-echange-controlhub-se5.md` avec l'émetteur central
  (`ContractEmitter`) — les deux BMAD doivent pointer le même artefact après livraison.
- L'audit source de cet epic : `../irundoo/documentation/CONTRAT-MANAGE-SE5-IRUNDO.md` (5 canaux) +
  `../irundoo/documentation/SE5-CONTRACT-COMPLIANCE-V1.md` (spec canal ③).

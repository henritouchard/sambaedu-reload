# Ultradev Epic 39 — Points à trancher / ratifier / actions manuelles

Date : 2026-07-04
Statut epic : **in-progress** — 4/4 stories `to-validate`, dev+review+intégration complets sur `main` (c2b9fe8). 178/178 tests epic-39 verts.

Aucune de ces questions n'a **bloqué** le développement (positions par défaut argumentées prises dans chaque story). Elles requièrent une décision / coordination / action de Henri **avant clôture `done` et mise en production**.

---

## 1. Ratification R2 — mismatch `type='registry'` vs `'capabilities'` (Story 39.2, canal ③)

**Contexte** : SE5 n'a qu'un canal d'imposition câblé pour les capacités : `type='registry'`. La valeur `type='capabilities'` de la spec centrale `SE5-CONTRACT-COMPLIANCE-V1.md` est un pseudo-canal mort. L'émetteur miroite **strictement** le `type` stocké (`'registry'`) — pas de remap silencieux (position figée par la story).

**Enjeu** : l'`enum ContractItemType` côté central pourrait **rejeter en 422** un item `type='registry'`. C'est un vrai point de couture à trancher entre les deux BMAD.

**Options** :
| Option | Conséquences |
|--------|--------------|
| A. Ratifier `registry` côté central (Recommandé) | Le central accepte `registry` ; miroir fidèle SE5, aucune traduction. |
| B. Remap `registry→capabilities` à l'émission SE5 | SE5 traduit ; risque de divergence sémantique + perte d'information de mécanisme. |

**Recommandation** : **A** — coordonner avec le BMAD controlHub pour élargir l'enum ; le miroir strict est la bonne sémantique R2.

## 2. Prérequis rollout — `SE4FS_INSTANCE_ID` figé (Story 39.3, canal ⑤)

**Contexte** : depuis 39.3, le fallback `aud` du JWT fédéré = `config('controlHub.se4fs.instance_id')`, dont le défaut régénère un **UUID aléatoire** à chaque chargement de config si `SE4FS_INSTANCE_ID` n'est pas fixé en `.env`.

**Enjeu** : sur une instance qui ne fixe ni `FEDERATED_AUTH_EXPECTED_AUD` ni `SE4FS_INSTANCE_ID`, **tous les logins fédérés seront rejetés** après déploiement (fail-closed — pas un bypass, mais indisponibilité du SSO fédéré).

**Action requise (Henri)** : garantir que le provisioning (`scripts/create-env.sh`) écrit `SE4FS_INSTANCE_ID` avant la mise en prod de 39.3. (Un garde `doctor`/artisan est un candidat de story d'infra distincte, hors périmètre 39.3.)

## 3. Confirmation de scope — `executable`/`catalog_apps` = persistance seule (Story 39.4, canal ④)

**Contexte** : le bloc `executable` (URL+checksum d'un installeur mono-fichier pour `catalog_apps`) recouvre un design SE5 **déjà tenté et abandonné** (`applications.installer_url/installer_sha256`). 39.4 **persiste** les champs mais ne **pull** pas d'installeur (dispatch limité à `wallpapers`/`agent_tools`).

**Recommandation** : conserver la position « persistance seule » ; ne matérialiser un pull d'installeur `catalog_apps` que si un besoin métier réel émerge (éviter de ressusciter le mécanisme abandonné par mimétisme). À confirmer.

## 4. Resynchronisation de l'artefact R2 (Story 39.4)

`schema-echange-controlhub-se5.md` a été mis à jour (additif : `delivery_mode`, `artifact`, `executable`, **sans** bump `schema_version` — doctrine additive). **Le BMAD controlHub doit pointer le même artefact** après cette livraison ; toute divergence résiduelle constatée à la ratification → story d'alignement (famille OPEN).

## 5. Actions manuelles VM (déploiement)

- **2 migrations additives** (39.4) à jouer : `php artisan migrate` (`2026_07_04_130000_*` items + `2026_07_04_130100_*` catalog_apps — nullable, garde `hasColumn`).
- **Cache** : `php artisan route:cache` + `config:cache` + `chown www-admin` après sync inotify (route 39.1 + config compliance/artifact non visibles sinon).
- **E2e curl** (différés, runbooks) : ingestion 200/422×2/403×2 (Section 21) ; émission conformité observée (Section 22) ; pull sha256 OK/KO + précédence (Section 23).

## 6. Suivis techniques (non bloquants, candidats stories futures)

- 39.3 #3/#6 : logger la **source de résolution** (db/config) du verifier + résoudre `ControlHubConnection::current()` une seule fois par `verify()` (observabilité + micro-perf du chemin sécurité).
- 39.4 #4 : **reconciliation périodique** des items `pull_status ∈ {null, error}` sans asset local (indépendante de la mutation du contrat), pour rattraper un dispatch de pull échoué.

---

## Décisions de l'orchestrateur (prises en autonomie, tracées)

- **Fallback modèle create-story** : quota Fable 5 épuisé en cours de Vague 2 → create-story 39.2/39.3/39.4 basculés `fable → sonnet`. N'affecte pas la logique d'opposition dev↔review (dev opus, review sonnet). Le modèle de dev restait pré-recommandé par l'epic (opus) pour les 3.
- **Commits** : 1 commit/story sur branche `ultradev/39-x` (Vague 2) mergée `--no-ff` sur `main` ; 39.1 committé directement sur `main` (Vague 1 séquentielle). Aucun push distant.
- **Conflits d'intégration** résolus par union : `sprint-status.yaml` (lignes 39-x distinctes) + `docs/qa/domains/controlhub-contract.md` (39.4 renumérotée Section 23 pour coexister avec la Section 22 de 39.2). WIP non lié de Henri (story 35-6 + `backlog.data.js`) préservé (non commité).

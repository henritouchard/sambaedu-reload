# Sprint Change Proposal — 2026-05-19

**Auteur :** John (PM), sous pilotage d'Henri
**Statut :** Validé (à compléter par Henri à l'approbation finale)
**Portée :** Re-scope architectural de la Story 16.13 — modèle de migration SE4 → SE5 transformé en fragment+reboot, split en 2 stories (16.13 + 16.13bis)

---

## 1. Issue Summary

### Déclencheur

Lors du lancement du cycle de dev sur **Story 16.13 (`cleanup-shims-definitif-archivage-legacy`)** le 2026-05-19, vérification des critères de bascule D8 (≥95% parc migré, 14j sans erreur, taux erreur `/api/v1/*` <1%). Henri révèle que ces critères ne sont pas atteignables tels que cadrés :

> *"Ce mode de migration est absurde. Je suis en train de coder une refonte de sambaedu qui est en prod à plusieurs endroits. Le jour où je vais migrer un collège, le nouveau code sera migré complet, je ne vais pas pull des versions progressives."*
> — Henri, 2026-05-19

### Catégorie du problème

**Misunderstanding of original requirements** — le tech-spec `tech-spec-epic-16-17-phase2.md` §8 cadre la migration legacy → v1 selon un pattern de rollout progressif sur cluster unique (iso Story 15.7 WPKG). Or le modèle de déploiement réel est **multi-collège** : chaque collège reçoit le package complet de sambaedu-reload (SE5) à un instant donné, sans pulls progressifs intra-collège.

### Conséquences

L'auto-bootstrap 16.11 (interception requête legacy + injection fragment migration) est par conséquent un **mécanisme permanent** dans sambaedu-reload pour le court/moyen terme (~2 ans, le temps que tous les collèges actifs basculent depuis legacy SE4), et non transitoire. Il ne disparaîtra naturellement qu'une fois qu'aucun collège SE4 n'existera plus. La Story 16.13 dans sa forme actuelle (cleanup destructif après 14j de stabilité prod) ne correspond plus à ce modèle.

### Évidence

- (a) Citation Henri ci-dessus
- (b) Tech-spec §8.1 Sprint 4 : *"1-2 semaines, après 14j stabilité"* → assume rollout temporel cluster unique
- (c) Tech-spec §8.2 D8 : métriques *"% postes migrés ≥95%, 14j stable, taux erreur <1%"* → per-deployment, pas multi-collège
- (d) Tech-spec §9 ligne risque *"Suppression shim 1bis.18 trop tôt"* → présuppose un "bon moment" unique pour supprimer
- (e) Memory `project_legacy_central_vs_local_split.md` confirme split architectural central vs local par collège

---

## 2. Impact Analysis

### Epic Impact

| Epic | Impact | Notes |
|---|---|---|
| **Epic 16 (Gestion native GPOs)** | ⚠️ Limité — re-scope de 16.13 + ajout 16.13bis | Stories 16.1-16.12 inchangées. Périmètre objectif intact. |
| Epic 17 (Scripts Win/Linux) | ✅ Aucun | Parallèle, indépendant |
| Epic 18 (DNS + réplication AD) | ✅ Aucun | Déjà annulé 2026-05-13 |

### Story Impact

| Story | État actuel | Impact |
|---|---|---|
| 16.1-16.10 | done | ✅ Aucun |
| 16.11 (auto-bootstrap) | review | ⚠️ **Partiellement superseded** — le middleware `InjectBootstrapFragment` sera retiré par 16.13bis (logique absorbée par MigrationController fragment+reboot). Les tables `workstations_migration_status` + `workstation_migration_attempts` **restent productives** — réutilisées par le nouveau modèle. À annoter dans sprint-status. |
| 16.12 (logs exécution) | review | ✅ Aucun — couche logs indépendante du modèle migration |
| **16.13** | backlog | 🔄 **Re-scopée** : devient *"Exposition endpoints natifs `/api/v1/*`"* |
| **16.13bis** | n'existe pas | ➕ **À créer** : *"Module migration simplifié + cleanup shim 1bis.18 + UI tracking"* |
| 16.14 (UX) | backlog | ✅ Aucun |
| 16.15 (cache Laravel) | backlog | ✅ Aucun |

### Artifact Impact

| Artifact | Changement |
|---|---|
| `tech-spec-epic-16-17-phase2.md` | ⚠️ Major edits : D7, D8, §5 cadrage 16.13, §5.6 schéma, §8.1 sprint 4, §8.2 critères de bascule, §9 risques, tableau audit shims |
| `epics.md` | ⚠️ Réécriture Story 16.13 + ajout Story 16.13bis |
| `sprint-status.yaml` | ⚠️ Entrée 16.13 reformulée + ajout 16.13bis + note `superseded-partial` sur 16.11 |
| `backlog.html` | ⚠️ Sync depuis sprint-status |
| `prd.md` | ✅ N/A — ne référence pas 16.13 |
| `architecture.md` | ✅ N/A — ne référence pas 16.13 |
| UI/UX specs | ✅ N/A — backend pur |

### Technical Impact

- **Code base** : suppression du shim 1bis.18 + middleware `InjectBootstrapFragment` (gain net). Création `MigrationController` (fragment+reboot only).
- **Schema DB** : aucune migration nécessaire (tables 16.11 réutilisées + relation Eloquent ajoutée sans schema change).
- **Infrastructure** : aucun changement (pas de critères de bascule prod à instrumenter, pas de TLS toggling, pas de feature flag).
- **Tests** : runbook QA `docs/qa/domains/auth.md` à enrichir lors du dev de 16.13bis (scénarios fragment+reboot vérifiés).

---

## 3. Recommended Approach

### Option retenue : Direct Adjustment (Option 1)

Re-scope de 16.13 en deux stories, intégrées à la séquence Phase 2 existante. Pas de rollback. Pas de re-plan stratégique.

**Justification** :
- L'objectif Epic 16 (UI native + migration parc) reste atteignable
- 16.1-16.12 conservent leur valeur ; 16.11 partiellement superseded mais ses tables restent productives
- Le re-scope corrige une hypothèse architecturale obsolète au plus tôt
- Charge raisonnable : 16.13 (2-3j) + 16.13bis (4-5j) = **~6-8j cumulés** (vs 2-3j de l'estimation initiale 16.13 seule augmentée de la valeur du refactor architectural absorbé)
- Pas d'impact PRD / Architecture / UX → propagation minimale

### Options écartées

- **Option 2 (Rollback 16.11)** : 🔴 Perte massive d'investissement (~10j + corrections), risque régression. Non justifié.
- **Option 3 (PRD MVP Review)** : 🔴 Périmètre disproportionné, pas de pivot stratégique nécessaire.

### Effort estimate

| Story | Effort | Risque |
|---|---|---|
| Doc updates (tech-spec + epics + sprint-status + backlog) | ~1h | 🟢 Faible |
| Story 16.13 (Exposition `/api/v1/*`) | 2-3j | 🟢 Faible — pure addition de routes, controllers existent |
| Story 16.13bis (Module migration simplifié + cleanup + UI) | 4-5j | 🟡 Modéré — refactor de mécanisme critique, tests E2E couvrent |
| **Total** | **~6-8j + doc** | 🟡 Modéré global |

### Timeline impact

Aucun ralentissement de la séquence Phase 2 — 16.13 + 16.13bis remplacent l'ancienne 16.13 dans le calendrier, avec une charge marginalement supérieure absorbant le refactor architectural. La fin de Phase 2 reste cohérente avec le planning initial.

---

## 4. Detailed Change Proposals

### 4.1 Story 16.13 — Exposition endpoints natifs `/api/v1/*` (NOUVEAU CADRAGE)

**Status :** `backlog` (inchangé)

**OLD** (epics.md §"Story 16.13") :
> *Cadrage haut niveau : à l'atteinte des critères de bascule (D8 — ≥95% parc migré, 14j sans erreur sur pipeline natif, taux erreur `/api/v1/*` <1%), retire les endpoints legacy `gpo/*_out.php` md5/APCu (410 Gone), supprime le shim GPO 1bis.18, archive `sambaedu/gpo/` dans `legacy/archived/gpo-YYYY-MM-DD/`. Supprime aussi le middleware d'injection de bootstrap (`InjectBootstrapFragment`) une fois la migration complète. Tests de non-régression sur la consultation des dashboards et la base de données existante. Charge : 2-3j. Prérequis : toutes les autres stories Phase 2.*

**NEW** :
> *Cadrage haut niveau : exposition des 8 endpoints natifs sous `/api/v1/*` (wallpaper, firefox, thunderbird, shortcuts, network, veyon, associations, applications-scripts). Réutilisation directe des controllers existants livrés par 4.7, 4.8, 16.3a/b/c, 16.7. Auth via middleware JWT `auth.v1.workstation` (livré par 16.10), extraction `workstation_uuid` depuis JWT (pattern iso 16.12). Tests feature + architecture sur invariance des 8 routes. **Les endpoints legacy `*_out.php` restent inchangés** durant cette story — le refactor du modèle migration est dans 16.13bis. Charge : 2-3j. Prérequis : 4.7, 4.8, 16.3a/b/c, 16.7, 16.10.*

**Rationale** : pure addition de routes sous `/api/v1/*` qui ne modifie pas les endpoints legacy existants. Permet aux postes migrés (par 16.13bis ultérieurement) d'avoir une cible fonctionnelle. Story indépendante, faiblement risquée, livrable en premier.

---

### 4.2 Story 16.13bis — Module migration simplifié + cleanup shim 1bis.18 (NOUVELLE)

**Status :** `backlog`

**Cadrage haut niveau** :
> *Refactor du modèle de migration SE4 → SE5 vers fragment+reboot, suppression du shim 1bis.18, isolation dans un module dédié.*
>
> **Module migration `App\Auth\V1\Migration`** :
> - Refactor des 8 endpoints `/sambaedu/gpo/*_out.php` en `MigrationController::serveFragment(endpoint, os)` qui renvoie text/plain :
>   1. script de migration cmd|sh (download CA, enroll, tokens DPAPI/0600)
>   2. update du registre Windows / fichiers Linux pour pointer vers `/api/v1/*` (URLs créées par 16.13)
>   3. `shutdown /r /t 30` avec message user-friendly (uniforme Windows + Linux)
> - Suppression du middleware `InjectBootstrapFragment` (logique absorbée par `MigrationController`)
> - Suppression du shim 1bis.18 (`legacy/sambaedu/gpo/*.php` embarqué)
> - Archive `sambaedu/gpo/` → `legacy/archived/gpo-YYYY-MM-DD/`
> - **Commentaire en tête de module** : `Module de migration SE4 → SE5. Ce code pourra être supprimé lorsqu'il n'existera plus de nécessité de migrer un déploiement SE4 vers SE5.`
>
> **Eloquent tracking** :
> - `App\Models\Workstation::migrationStatus()` (hasOne `WorkstationMigrationStatus`, FK `workstation_uuid` ↔ PK `workstations.uuid`)
> - Accessor `$workstation->migrated` (bool)
> - Scopes `Workstation::migrated()` et `Workstation::notMigrated()`
>
> **UI admin minimaliste** :
> - Colonne "Migration" sur l'index admin des workstations (badge ✅ migré / ⏳ en cours / ❌ pas migré)
> - Filtre par statut migration sur la même page
>
> **Décision proof-of-possession à trancher en SM** :
> - Option α : préserver `gpo/applications.php` actif (émetteur md5 APCu via 16.7 `ApcuAppContextWriter`) — coût : ce fichier reste vivant
> - Option β : abandonner md5, reposer sur `EnsureLanIp` + UUID self-declared (SE4FS strictement LAN)
>
> Tests E2E (poste appelle legacy URL → fragment → migre → reboot → next boot /api/v1/* OK) + runbook QA `docs/qa/domains/auth.md` enrichi.
>
> Charge : 4-5j. Prérequis : **16.13 done** (impératif — sinon postes migrés se retrouvent sans cible `/api/v1/*` fonctionnelle).

---

### 4.3 Story 16.11 — Annotation de superseding partiel

Ajouter dans le commentaire YAML de l'entrée `16-11-auto-bootstrap-migration-postes` dans `sprint-status.yaml` :

> *2026-05-19 — Partiellement superseded par 16.13bis (Sprint Change Proposal 2026-05-19). Le middleware `InjectBootstrapFragment` sera retiré en 16.13bis (logique absorbée par MigrationController fragment+reboot). Les tables `workstations_migration_status` + `workstation_migration_attempts` **restent productives** — réutilisées par le nouveau modèle. Le status `review` reste valable jusqu'à validation Henri sur les corrections post-review déjà appliquées.*

---

### 4.4 Tech-spec edits (`tech-spec-epic-16-17-phase2.md`)

#### Décision D7 (L91) — Reformulée

**OLD** :
> *Le shim 1bis.18 (GPO) sera retiré à la fin de la Phase 2 uniquement (après stabilisation complète). Pattern iso Story 15.7 : on retire le shim WPKG après 14 jours de stabilité prod. Idem ici.*

**NEW** :
> *Le shim 1bis.18 (GPO) est retiré dès Story 16.13bis, **sans attendre de critères de stabilité prod**. Le modèle de bascule passe à fragment+reboot via un `MigrationController` dédié, qui n'exige plus la coexistence dual-mode prolongée. Le pattern iso Story 15.7 (cleanup post-stabilisation cluster unique) ne s'applique pas — le déploiement de sambaedu-reload se fait par collège, en package complet, pas en versions progressives intra-cluster.*

#### Décision D8 (L92) — Reformulée

**OLD** :
> *Backward-compat absolue postes : les endpoints legacy `/gpo/*_out.php` md5/APCu restent fonctionnels pendant toute la Phase 2 — ils déclenchent la migration en parallèle. Aucun poste ne doit casser à un instant T. La désactivation des endpoints legacy se fera après confirmation que ≥95% du parc est migré (critère à instrumenter).*

**NEW** :
> *Backward-compat postes : les 8 endpoints legacy `/gpo/*_out.php` sont **transformés** en routes du module migration (fragment+reboot only) dès 16.13bis. Le poste non-migré reçoit un fragment de bootstrap au lieu de la réponse legacy ; il enrôle, met à jour son registre pour pointer vers `/api/v1/*`, puis reboot. Au boot suivant, le poste utilise nativement `/api/v1/*`. Modèle compatible avec déploiement-par-collège : la bascule s'opère poste-par-poste au premier appel post-déploiement, sans coupure de service ni critères de stabilité globaux.*

#### Section §5 — Cadrage Story 16.13 (L410)

**OLD** :
> `| **16.13** | Cleanup shims définitif (1bis.18g + résiduels) + archivage code legacy | 2-3j | toutes les autres |`

**NEW** (2 lignes) :
> `| **16.13** | Exposition endpoints natifs /api/v1/* | 2-3j | 4.7, 4.8, 16.3a/b/c, 16.7, 16.10 |`
> `| **16.13bis** | Module migration simplifié (fragment+reboot) + cleanup shim 1bis.18 + UI tracking | 4-5j | 16.13 |`

#### Section §5.6 — Schéma de séquencement (L436)

Mettre à jour le schéma ASCII pour inclure `16.13 → 16.13bis` au lieu de `16.13` seul.

#### Section §8.1 — Sprint 4 (L478)

**OLD** :
> *Sprint 4 (1-2 semaines, après 14j stabilité) — 17.4 + 16.13 Cleanup shims + archivage legacy*

**NEW** :
> *Sprint 4 — 17.4 + 16.13 + 16.13bis enchainées. Pas d'attente de critères prod, le refactor du modèle migration est livré dans la séquence Phase 2 normale.*

#### Section §8.2 — Critères de bascule D8 (L481-491)

**ACTION : remplacer toute la section §8.2** par le texte ci-dessous.

> ### 8.2 Évolution du modèle de bascule (Sprint Change Proposal 2026-05-19)
>
> Le modèle initial (rollout progressif intra-cluster + bascule "dual-mode → v1-only" après ≥95% migré + 14j sans erreur) a été abandonné suite au Sprint Change Proposal 2026-05-19. Motif : le déploiement réel de sambaedu-reload (SE5) se fait par collège, en package complet, sans versions progressives intra-collège. Les métriques per-deployment (95%, 14j) ne sont pas pertinentes dans ce modèle.
>
> Le nouveau modèle (livré par 16.13 + 16.13bis) :
> - 16.13 expose les endpoints natifs `/api/v1/*` accessibles aux postes migrés
> - 16.13bis transforme les endpoints `*_out.php` en `MigrationController` qui renvoie un fragment de bootstrap + reboot
> - La bascule s'opère poste-par-poste, automatiquement, dès le premier appel post-déploiement collège
> - Le module migration porte un commentaire d'auto-obsolescence : *"Ce code pourra être supprimé lorsqu'il n'existera plus de nécessité de migrer un déploiement SE4 vers SE5"*
>
> Aucun critère de bascule global à instrumenter. Aucune coordination de timing nécessaire avec les bascules collège. Le suivi par collège se fait via `Workstation::notMigrated()->count()` exposé en UI admin minimaliste (16.13bis).

#### Section §9 — Risques (L449)

**Supprimer la ligne** :
> *| Suppression shim 1bis.18 (16.13) trop tôt → pages legacy non couvertes cassent | 🟡 Moyenne | ... |*

Motif : sans objet dans le nouveau modèle (le shim est explicitement remplacé par un mécanisme stateless `MigrationController` qui ne nécessite pas de surveillance progressive).

**Reformuler la ligne L454** :
> *| Charge dev dépassant l'estimation | 🟢 Mineure | Stories indépendantes : 16.8 et 16.9 peuvent être livrées sans 16.10-16.13bis. Possible de releaser par sous-jalon. |*

#### Section §10 / Tableau audit shims/legacy (L515-519)

**OLD lignes** :
> `| gpo/*_out.php (firefox, wallpaper, network, veyon, associations, shortcuts) | ✅ Porté | Maintenu en dual-mode, retiré en 16.13 |`
> `| Auth md5/APCu | ✅ Implémenté (parité legacy) | Remplacé par JWT (16.10), désactivé après migration (16.13) |`
> `| Shim GPO 1bis.18 | 🟠 Encore actif | Retiré en 16.13 |`

**NEW lignes** :
> `| gpo/*_out.php (firefox, wallpaper, network, veyon, associations, shortcuts) | ✅ Porté | Transformés en routes module migration (fragment+reboot) en 16.13bis. Endpoints natifs équivalents exposés sous /api/v1/* en 16.13 |`
> `| Auth md5/APCu | ✅ Implémenté (parité legacy) | Décision proof-of-possession à trancher en SM 16.13bis (préservation md5 vs LAN-only+UUID self-declared) |`
> `| Shim GPO 1bis.18 | 🟠 Encore actif | Retiré en 16.13bis sans attendre critères stab prod |`

---

### 4.5 Sprint Status YAML

Modifications à appliquer dans `_bmad-output/implementation-artifacts/sprint-status.yaml` :

**Entrée 16-13 actuelle** :
```yaml
16-13-cleanup-shims-definitif-archivage-legacy: backlog  # 2026-05-15 cadrée Phase 2 (D7+D8)...
```

**Remplacer par 2 entrées** :
```yaml
16-13-exposition-endpoints-api-v1: backlog  # 2026-05-19 RE-CADRÉE via Sprint Change Proposal 2026-05-19. Anciennement "cleanup-shims-definitif-archivage-legacy" (modèle rollout progressif inadapté multi-collège). Nouveau scope : exposition 8 endpoints natifs /api/v1/* (wallpaper, firefox, thunderbird, shortcuts, network, veyon, associations, applications-scripts) via réutilisation directe controllers 4.7/4.8/16.3a/b/c/16.7. Auth JWT auth.v1.workstation (16.10). Tests feature + archi. Charge 2-3j. Prérequis : 4.7, 4.8, 16.3a/b/c, 16.7, 16.10. Source : sprint-change-proposal-2026-05-19.md.

16-13bis-module-migration-simplifie: backlog  # 2026-05-19 CRÉÉE via Sprint Change Proposal 2026-05-19. Refactor modèle migration SE4 → SE5 en fragment+reboot, suppression shim 1bis.18, module isolé App\Auth\V1\Migration. Relation Eloquent Workstation::migrationStatus + accessor + scopes. UI admin minimaliste (colonne+filtre+badge sur index workstations). Décision proof-of-possession à trancher en SM (md5 préservé vs LAN-only+UUID). Tests E2E + runbook QA. Charge 4-5j. Prérequis : 16.13 done. Source : sprint-change-proposal-2026-05-19.md.
```

**Et mettre à jour le commentaire** de `16-11-auto-bootstrap-migration-postes` pour ajouter la note superseding partielle (voir §4.3).

---

### 4.6 backlog.html

Synchroniser depuis `sprint-status.yaml` après modification :
- Renommer la card existante 16.13
- Ajouter une nouvelle card 16.13bis
- Pas de changement de status global (les deux restent `backlog`)
- Mettre à jour stats globales si nécessaire

---

## 5. Implementation Handoff

### Scope classification : 🟡 Moderate

Re-organisation backlog + dev de 2 stories nouvelles + édition de 2 documents de planning. Pas d'implication PRD ou architecture.

### Rôles & responsabilités

| Rôle | Action | Quand |
|---|---|---|
| **PM (John)** | (1) Compiler ce Sprint Change Proposal ✅ ; (2) Éditer `tech-spec-epic-16-17-phase2.md` (D7/D8 + §5 + §8 + §9 + audit) ; (3) Éditer `epics.md` (16.13 réécrite + 16.13bis ajoutée) ; (4) Mettre à jour `sprint-status.yaml` (16.13 reformulée + 16.13bis ajoutée + note `superseded-partial` sur 16.11) ; (5) Synchroniser `backlog.html` | Suite de la session courante (après approbation) |
| **SM (next session)** | Création des deux stories dédiées via `bmad-create-story` quand Henri déclenche le dev : `16-13-exposition-endpoints-api-v1.md` puis `16-13bis-module-migration-simplifie.md` | Quand Henri lance `/dev-cycle 16.13` |
| **Dev (subagent)** | Implémentation suivant patterns Phase 2 établis (static delivery, tests non lancés VM, pattern iso 16.10/16.11/16.12) | Cycle dev/review classique |
| **Henri** | (1) Approuver Sprint Change Proposal ; (2) Trancher proof-of-possession à la création de 16.13bis (Option α md5 préservé vs β LAN-only) ; (3) Smoke tests post-VM-up ; (4) Décider moment de bascule par collège (auto poste-par-poste, sans coupure) | Plusieurs jalons |

### Critères de succès

- ✅ Sprint Change Proposal approuvé et committé dans `planning-artifacts/`
- ✅ Tech-spec + epics + sprint-status + backlog cohérents avec nouveau modèle
- ✅ Pas de breaking change apparent sur les stories Phase 2 déjà livrées
- ✅ Stories 16.13 + 16.13bis cadrées, prêtes pour `bmad-create-story` quand Henri voudra lancer

---

## 6. Synthèse exécutive

| Item | Statut |
|---|---|
| Catégorie | Misunderstanding of original requirements (rollout pattern inadapté) |
| Option retenue | Direct Adjustment — re-scope ciblé |
| Stories touchées | 16.13 (re-scopée) + 16.13bis (créée) + 16.11 (note superseding partielle) |
| Documents touchés | tech-spec-epic-16-17-phase2.md (major edits) + epics.md (Story 16.13 réécrite + 16.13bis ajoutée) + sprint-status.yaml + backlog.html |
| PRD / Architecture / UX | ✅ Intacts |
| Charge supplémentaire | ~6-8j (vs 2-3j initial) — refactor architectural absorbé |
| Risque | 🟡 Modéré — refactor mécanisme critique, mitigé par tests E2E et runbook QA |
| Scope du change | 🟡 Moderate |

---

## 7. Décisions à trancher au moment de la création de 16.13bis (SM)

| Item | Options | Recommandation PM |
|---|---|---|
| Proof-of-possession migration | α = préserver `gpo/applications.php` (md5 APCu via 16.7) / β = abandonner md5, baser sur `EnsureLanIp` + UUID self-declared | β (SE4FS strictement LAN, ratio simplicité/sécurité favorable, suppression shim plus complète) — à confirmer par Henri en SM |
| Reboot strategy uniforme | B1 = reboot 30s + message (Win+Linux uniforme) — déjà tranchée Henri 2026-05-19 | ✅ Acquise |
| Namespace module migration | `App\Auth\V1\Migration` (cohérent avec Auth V1) vs `App\LegacyMigration` (standalone) | `App\Auth\V1\Migration` — proximité avec 16.10/16.11 |
| Routes legacy → fragment | Conserver paths exacts `/sambaedu/gpo/*_out.php` (les postes legacy y appellent) | ✅ Imposé par contrat poste — pas un choix |

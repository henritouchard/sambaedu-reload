---
stepsCompleted:
  - step-01-document-discovery
  - step-02-prd-analysis
  - step-03-epic-coverage-validation
  - step-04-ux-alignment
  - step-05-epic-quality-review
  - step-06-final-assessment
  - addendum-re-verification-2026-05-03
filesUsed:
  - prd.md
  - architecture.md
  - epics.md
scope: Story 15.1
verdict: READY (révisé après amendements 2026-05-03)
---

# Implementation Readiness Assessment Report

**Date:** 2026-05-03
**Project:** codebase (wpkg)
**Scope:** Story 15.1 — Fondations Pipeline Déploiement WPKG

## Document Inventory

| Type | Fichier | Taille | Modifié |
|------|---------|--------|---------|
| PRD | prd.md | 27K | 2026-04-16 |
| Architecture | architecture.md | 30K | 2026-04-16 |
| Epics & Stories | epics.md | 218K | 2026-05-01 |

*Aucun document UX trouvé.*

---

## PRD Analysis

### Exigences Fonctionnelles extraites (scope WPKG/Déploiement Windows)

| FR | Texte | Epic |
|----|-------|------|
| FR23 | Le responsable peut consulter et gérer les GPOs via l'interface SER (Services/Legacy/) | Epic 9 → Epic 16 |
| FR24 | Le responsable peut gérer les packages WPKG (définition, association aux profils, déclenchement au démarrage) | Epic 9 + **Epic 15** |
| FR25 | Le responsable peut consulter les logs WPKG et les rapports d'installation | Epic 9 + **Epic 15** |
| FR26 | Le responsable peut gérer les scripts de démarrage Windows | Epic 9 → Epic 17 |

*Total FRs PRD : 36 (FR1-FR29 + FR33-FR39). FRs directement impactés par Story 15.1 : FR24, FR25.*

### Exigences Non-Fonctionnelles pertinentes

- **NFR12** : Les migrations de données sont idempotentes — une migration exécutée deux fois ne corrompt pas les données
- **NFR13** : Tout le code est typé (PHP typed properties, return types, DTOs)
- **NFR15** : Chaque fonctionnalité livrée est accompagnée de tests automatisés avant passage en bêta
- **NFR18** : Les intégrations système encapsulées dans des Services dédiés — aucun appel système direct depuis les SFC Livewire

---

## Epic Coverage Validation

### Matrice de couverture FR (périmètre WPKG)

| FR | Texte PRD | Couverture Epic | Statut |
|----|-----------|-----------------|--------|
| FR24 | Gestion packages WPKG + pipeline déploiement | Epic 9 (admin) + Epic 15 (pipeline natif) | ✅ Couvert |
| FR25 | Logs WPKG + rapports installation | Epic 9 (shim) + Epic 15.5 (natif) | ✅ Couvert |
| FR23 | GPOs | Epic 9 → Epic 16 | ✅ Couvert (Epic 16) |
| FR26 | Scripts démarrage Windows | Epic 9 → Epic 17 | ✅ Couvert (Epic 17) |

### ⚠️ Écart de documentation

La carte de couverture FR dans `epics.md` (lignes ~196-199) mappe FR23-FR26 uniquement vers Epic 9. Les Epics 15/16/17 (ajoutés 2026-05-01) ne sont pas encore reflétés dans ce tableau de couverture. **Recommandation** : mettre à jour le tableau de couverture FR de `epics.md` pour inclure Epics 15, 16 et 17 comme couverture additionnelle.

### Statistiques de couverture globale

- Total FRs PRD : 36
- FRs couverts dans les epics : 36
- Couverture : **100%** (pas de FR orphelin identifié)

---

## UX Alignment Assessment

### Statut document UX

**Non trouvé** — aucun fichier `*ux*.md` dans les planning artifacts.

### Évaluation

Story 15.1 est une story de **pure infrastructure** (channel logs, namespace, migrations, config, helpers) : elle ne génère aucun écran utilisateur. L'absence de document UX est **normale et attendue** pour cette story.

Pour les stories suivantes de l'Epic 15 (notamment 15.4 — UI admin assignation apps), un document UX sera pertinent.

---

## Epic Quality Review — Story 15.1

### Structure de l'Epic 15

| Critère | Évaluation |
|---------|------------|
| Valeur utilisateur Epic 15 | ✅ Pipeline de déploiement → postes Windows reçoivent les bons logiciels |
| Indépendance de l'epic | ✅ Prérequis (Epic 4, Story 9.2) déjà livrés |
| Story 15.1 comme foundation | ⚠️ Pas de valeur utilisateur directe — assumé par design |
| Format ACs (BDD Given/When/Then) | ✅ Structuré et précis sur les 6 volets |
| Scope clairement borné | ✅ "Hors scope : aucune logique métier de déploiement" |
| Dépendances forward | ✅ Aucune dépendance vers des stories futures |

---

### 🔴 CRITIQUE — Conflit clés de configuration (Volet 4)

**Problème :** Story 15.1 Volet 4 spécifie de nouvelles clés de config :
- `config('sambaedu.wpkg.reports_inbox')` 
- `config('sambaedu.wpkg.reports_archive')`

**Or**, la configuration existante (`config/sambaedu.php`) utilise :
- `config('sambaedu.wpkg.reports_path')` → `/var/sambaedu/unattended/install/wpkg/rapports`
- `config('sambaedu.wpkg.reports_archive_path')`

Et le service existant `App\Services\Windows\WpkgReportIngestionService` consomme ces clés actuelles (via les constantes legacy).

**Impact :** Si Story 15.1 crée de nouvelles clés sans renommer/déprécier les anciennes, `WpkgReportIngestionService` continuera à utiliser les anciennes clés (`reports_path` / `reports_archive_path`) tandis que les Generators de Story 15.2 utiliseront les nouvelles (`reports_inbox` / `reports_archive`). Risque de divergence de chemins en production.

**Recommandation :** Avant d'implémenter, décider explicitement :
- Option A : renommer les clés existantes en `reports_inbox` / `reports_archive` dans Story 15.1 **et** mettre à jour `WpkgReportIngestionService` dans la même story
- Option B : conserver les clés actuelles et ajuster les noms dans la story 15.1
- Mettre à jour la Story 15.1 pour mentionner la migration des clés existantes

---

### 🟠 MAJEUR — AtomicFileWriter dupliqué (Volet 5)

**Problème :** Une classe `AtomicFileWriter` existe déjà :
- **Existante** : `App\Services\AppCustomization\Support\AtomicFileWriter::write($path, $content)` — format tmp : `.<basename>.tmp-<hex6>`
- **Story 15.1** crée : `App\Wpkg\Deployment\Support\AtomicFileWriter::write($path, $content)` — format tmp : `<path>.tmp.<pid>.<random>`

La story 15.1 demande un format de fichier temporaire différent (avec PID explicite). La signature de méthode est identique mais le comportement est légèrement différent.

**Risque :** Duplication de logique critique sans justification explicite dans la story. Si la classe AppCustomization est suffisante, la dupliquer crée une dette. Si elle ne l'est pas (le PID dans le nom est une garantie supplémentaire d'unicité), cela doit être justifié.

**Recommandation :** Ajouter dans les Notes de Story 15.1 : soit "réutiliser `App\Services\AppCustomization\Support\AtomicFileWriter` en le déplaçant vers un namespace commun `App\Support\`", soit justifier explicitement la création d'une seconde implémentation (e.g. "le PID est requis pour le debug de concurrence sur postes WPKG").

---

### 🟠 MAJEUR — PHPStan/ArchTest absent du projet (Volet 2)

**Problème :** Volet 2 exige un "test architecture (PHPStan rule ou ArchTest)" pour vérifier que `App\Wpkg\Deployment\` n'importe pas `LdapRecord\*` ni `App\Services\Ad\*`.

**État actuel :** Le projet n'a **ni PHPStan/Larastan, ni ArchTest** configuré (`composer.json` ne contient que `phpunit/phpunit`). Aucun fichier `phpstan.neon` trouvé.

**Impact :** Le test d'architecture spécifié dans l'AC ne peut pas être implémenté en l'état. La story doit soit inclure l'installation de l'outil, soit l'AC doit être reformulée pour utiliser un test PHPUnit custom (assertions sur les `use` statements via réflexion).

**Recommandation :** Ajouter à Story 15.1 : une tâche d'installation de `phpstan/phpstan` + `nunomaduro/larastan`, ou préciser que le "test architecture" sera un test PHPUnit qui inspecte les namespaces importés.

---

### 🟡 MINEUR — Ambiguïté type `deployment_id` (Volet 3 vs Story 15.5)

**Problème :** Volet 3 spécifie `id` dans le schéma de `wpkg_deployments` sans préciser UUID ou auto-increment. Story 15.5 mentionne dans le XML WPKG un commentaire `deployment_id={uuid}`, impliquant que `id` est un UUID.

**Recommandation :** Préciser dans le Volet 3 : `id` (UUID, type `uuid`, default `Str::uuid()`).

---

### 🟡 MINEUR — Service existant hors périmètre de l'audit (Note de story)

**Problème :** La Note de Story 15.1 dit "si un service `App\Wpkg\*` existant entre en conflit... l'audit de Story 15.3 le tracera." Mais le service existant pertinent est `App\Services\Windows\WpkgReportIngestionService` (namespace `App\Services\Windows\`, pas `App\Wpkg\*`). Il ne sera pas détecté par cet audit.

**Recommandation :** Amender la Note pour mentionner explicitement `WpkgReportIngestionService` et clarifier s'il sera migré vers `App\Wpkg\Deployment\Services\` dans une story future (probablement 15.5 ou 15.7).

---

### ✅ Points positifs

- **Dépendances prérequis satisfaites** : Epic 4 (Workstation, WorkstationGroup, AppProfile) ✅, Story 9.2 ✅
- **Isolation claire** : le namespace `App\Wpkg\Deployment\` n'existe pas encore — zéro conflit de namespace
- **Tables de tracking non existantes** : migrations propres à écrire, pas de legacy à migrer
- **Check démarrage applicatif (Volet 4)** : bonne pratique de fail-fast sur les chemins inaccessibles
- **Test concurrence AtomicFileWriter (Volet 5)** : AC bien définie et testable

---

## Summary and Recommendations

### Overall Readiness Status

**⚠️ NEEDS WORK** — 1 critique, 2 majeurs, 2 mineurs à résoudre avant ou pendant l'implémentation.

### Critical Issues Requiring Immediate Action

1. **[CRITIQUE] Aligner les clés de config `wpkg.*`** : résoudre le conflit entre `reports_path`/`reports_archive_path` (existants) et `reports_inbox`/`reports_archive` (demandés par Story 15.1). Décider de la stratégie de migration et mettre à jour la story **avant de coder**.

### Recommended Next Steps

1. **Décider de la stratégie config** : renommer les clés existantes ou garder les nouvelles — mettre à jour `WpkgReportIngestionService` en conséquence dans le scope de Story 15.1.
2. **Clarifier AtomicFileWriter** : décider entre réutilisation (déplacement vers `App\Support\`) ou duplication justifiée. Mettre à jour la Note de la story.
3. **Installer PHPStan/Larastan** ou reformuler l'AC du Volet 2 en test PHPUnit custom — à décider avant d'écrire les tests.
4. **Préciser UUID** dans le Volet 3 de la story (`id` → `id (UUID)`).
5. **Mentionner `WpkgReportIngestionService`** dans la Note de la story pour indiquer sa trajectoire de migration.

### Final Note

Cette évaluation a identifié **6 points** (1 critique, 2 majeurs, 3 mineurs) sur la Story 15.1. Le point critique (conflit config) doit être résolu avant l'implémentation pour éviter une divergence de chemins en production. Les autres points peuvent être adressés pendant le développement mais méritent une décision explicite pour ne pas générer de dette technique silencieuse.

La Story 15.1 est bien structurée et ses ACs sont précises — le fonds est solide. Ces ajustements sont de l'ordre de la clarification, pas de la refonte.

---

## Addendum — Re-vérification après amendements (2026-05-03, session PM)

**Verdict révisé : ✅ READY**

Les 6 findings de l'évaluation initiale ont été traités via amendement direct de la Story 15.1 dans `epics.md` (cf. session PM du 2026-05-03). Statut point par point :

| # | Sévérité | Finding initial | Résolution | Statut |
|---|----------|-----------------|------------|--------|
| 1 | 🔴 Critique | Conflit clés config `reports_path` ↔ `reports_inbox` | **Volet 4** : note de décision **rename** explicite. **Volet 4bis** ajouté : migration des consommateurs (`WorkstationLogReader.php`, `WpkgProcessReportsCommand.php`), suppression des anciennes clés du `config/sambaedu.php` + rename variables `.env` (`WPKG_REPORTS_PATH` → `WPKG_REPORTS_INBOX`, idem archive), tests de non-régression exigés, note ops dans `docs/wpkg-deploy/architecture.md`. | ✅ Résolu |
| 2 | 🟠 Majeur | `AtomicFileWriter` dupliqué | **Volet 5** : consolidation explicite vers `App\Support\AtomicFileWriter`, suppression de `App\Services\AppCustomization\Support\AtomicFileWriter`, suffixe PID partagé, tests de non-régression `AppCustomization` exigés, le namespace `App\Wpkg\Deployment\Support\` n'expose pas de classe propre. | ✅ Résolu |
| 3 | 🟠 Majeur | PHPStan/ArchTest absent | **Volet 2** reformulé : test PHPUnit custom `tests/Architecture/WpkgDeploymentNamespaceTest.php` (scan `use` via `Symfony\Finder` + reflection). Note explicite : migration vers ArchTest/PHPStan rule lorsqu'un de ces outils sera installé (ticket tooling séparé hors scope 15.1). | ✅ Résolu |
| 4 | 🟡 Mineur | UUID `id` non explicite | **Volet 3** : `$table->uuid('id')->primary()` explicitement spécifié pour `wpkg_deployments`, FK UUID propagée à `wpkg_deployment_workstation_status.deployment_id`. | ✅ Résolu |
| 5 | 🟡 Mineur | Conflits `App\Wpkg\*` incomplets | **Notes** : extension explicite à `App\Services\Windows\WpkgReportIngestionService`, `App\Services\Windows\WorkstationLogReader`, `App\Console\Commands\WpkgProcessReportsCommand` ; trajectoire tracée par audit Story 15.3 volet 1. | ✅ Résolu |
| 6 | 🟡 Mineur | Tableau couverture FR `epics.md` non mis à jour pour Epics 15/16/17 | **Notes** : action de doc planifiée à la clôture de la story (non bloquante pour la livraison code). | 🟢 Planifié (clôture) |

### Décisions clés validées par henri (PM session 2026-05-03)

- **Rename, pas alias** sur les clés config rapports — pas de fallback, pas de dette résiduelle.
- **Consolidation** `AtomicFileWriter` plutôt que duplication justifiée.
- **Test PHPUnit custom** plutôt qu'install PHPStan dans le scope 15.1.
- **UUID explicite** dans la migration.

### Verdict final

La Story 15.1 est désormais **prête pour l'implémentation**. Aucun bloquant restant. Le seul point ouvert (#6, mise à jour tableau de couverture FR) est explicitement planifié en fin de story et n'impacte pas la livraison code.

**Recommandation** : enchaîner sur `bmad-dev-story` ou `dev-cycle` pour démarrer le développement de 15.1.

# Story 1bis.14 : Module `partages`

Status: paused — prévu Epic 5

## Story

As a **développeur**,
I want intégrer le module legacy `partages` dans `legacy/modules/partages/` via le cloisonnement SHIM EXPRESS,
So que la gestion des partages réseau legacy (répertoires de classes, synchronisation cloud) est accessible via le catchall Laravel pendant que la refonte native est préparée dans Epic 5 (FR13-16).

---

## Contexte

> **⚡ SHIM EXPRESS ~1h** — décision 2026-04-17, cf. `sprint-change-proposal-2026-04-17.md` + `idempotency.md § 8`
>
> Audit empirique : `have_right` (2 occurrences dans 2 fichiers distincts) est déjà shimmé via le bridge LDAP (`legacy/ldap.inc.php`). **0 exec système. 0 SQL direct.** Le shim existant couvre 100% des besoins du module.
>
> Scope minimal : `cp -r sambaedu/partages sambaedu-reload/legacy/modules/partages/` + smoke tests via catchall. La refonte native est déférée à **Epic 5 (FR14, FR15)**.

---

## Acceptance Criteria

**AC1 — Module copié et accessible**
Given le module `partages` est copié dans `legacy/modules/partages/` (4 fichiers PHP),
When j'accède aux URLs principales (`/partages/rep_classes.php`, `/partages/rep_cloud.php`, `/partages/rep_cloud_cron.php`) via le catchall Laravel,
Then chaque page se charge sans erreur PHP fatale
And le rendu HTML est wrappé dans le layout SER.

**AC2 — Sortie raw pour `cloud_out.php`**
Given `cloud_out.php` émet `header("Content-type: text/plain")` et génère un script CMD,
When le catchall sert cette page,
Then la détection `isHtmlWebPage()` du `LegacyCatchallController` retourne false
And la réponse est servie raw (sans wrapping layout HTML).

**AC3 — Shim LDAP `have_right` fonctionnel**
Given `rep_classes.php` appelle `have_right($config, SE_SHARE_REFRESH)` et `rep_cloud.php` appelle `have_right($config, SE_USER_ADMIN)`,
When ces fonctions sont invoquées via le bridge LDAP shimmé (`legacy/ldap.inc.php`),
Then elles retournent une valeur cohérente (true/false selon les droits de l'utilisateur courant)
And aucune fatal error PHP n'est levée.

**AC4 — Résolution des includes legacy**
Given le module charge `samba.inc.php`, `partages.inc.php`, `partages_ui.inc.php`, `cloud.inc.php`, `ent.inc.php`, `logs.inc.php` depuis `sambaedu/includes/` via l'include_path,
When le bootstrap legacy est actif (`LEGACY_BOOTSTRAP_LOADED`),
Then tous les includes se résolvent sans conflit avec les stubs (`legacy/stubs/`)
And aucune fonction n'est redéclarée (fatal "Cannot redeclare").

**AC5 — Error logger propre**
Given le module est intégré et la suite de smoke tests passe,
When le error logger (`ErrorLoggerService`) est consulté après exécution,
Then aucune erreur récurrente bloquante (niveau ERROR ou FATAL) n'est présente pour le tag `partages`.

---

## Dépendances

| Story | Titre | Status | Détail |
|-------|-------|--------|--------|
| 1bis-1 | Error logger & dashboard | done | `LegacyErrorHandler` actif, capte les erreurs du module |
| 1bis-2 | Bootstrap & shim LDAP | done | `legacy/ldap.inc.php` fournit `have_right()`, `get_config()` bridge, include_path prépendé |
| 1bis-3 | Shim SQL MySQL → Eloquent | done | Requis par le bootstrap — aucune dépendance SQL directe dans ce module |
| 1bis-4 | Bundle Tier 1 (catchall) | done | `LegacyCatchallController` avec `executeViaBootstrap()`, `chdir()`, `isHtmlWebPage()` — patterns validés |

Toutes les dépendances sont satisfaites. La story peut être implémentée immédiatement.

---

## Tasks / Subtasks

- [ ] **Tâche 1 : Copier le module `partages` dans `legacy/modules/partages/`** (AC: 1, 2)
  - [ ] Copier l'intégralité du dossier `sambaedu/partages/` vers `legacy/modules/partages/`
  - [ ] Vérifier la structure : 4 fichiers PHP (`cloud_out.php`, `rep_classes.php`, `rep_cloud_cron.php`, `rep_cloud.php`) — 304 lignes total
  - [ ] Ne pas modifier le contenu des fichiers PHP — le bootstrap + shims doivent les faire fonctionner tels quels

- [ ] **Tâche 2 : Vérifier la résolution des includes** (AC: 4)
  - [ ] Confirmer que `samba.inc.php`, `partages.inc.php`, `partages_ui.inc.php` se résolvent depuis `sambaedu/includes/` via include_path
  - [ ] Confirmer que `cloud.inc.php` (3861 L) et `ent.inc.php` (6481 L) se chargent sans redéclaration
  - [ ] Confirmer que `logs.inc.php` est fourni par le stub `legacy/stubs/logs.inc.php` (déjà présent) et non par le legacy (éviter double déclaration)
  - [ ] Confirmer que `admin_ui.inc.php` est résolu par `legacy/stubs/admin_ui.inc.php` (déjà présent)

- [ ] **Tâche 3 : Vérifier le shim LDAP `have_right`** (AC: 3)
  - [ ] `rep_classes.php` : `have_right($config, SE_SHARE_REFRESH)` — constante `SE_SHARE_REFRESH` vérifiée dans le shim ou les includes
  - [ ] `rep_cloud.php` : `have_right($config, SE_USER_ADMIN)` — constante `SE_USER_ADMIN` vérifiée
  - [ ] Si une constante manque, la déclarer dans `legacy/config.inc.php` ou le stub approprié

- [ ] **Tâche 4 : Valider la sortie raw de `cloud_out.php`** (AC: 2)
  - [ ] `cloud_out.php` émet `Content-type: text/plain` et génère un script CMD via `genere_script_partage_utilisateur()`
  - [ ] Vérifier que `LegacyCatchallController::isHtmlWebPage()` détecte le Content-Type text/plain et sert la réponse raw
  - [ ] Vérifier que la fonction `genere_script_partage_utilisateur()` est définie dans `cloud.inc.php` (qui est dans include_path)

- [ ] **Tâche 5 : Écrire les tests Feature** (AC: 1, 2, 3, 5)
  - [ ] Créer `tests/Feature/LegacyModulePartagesTest.php`
  - [ ] Test : `rep_classes.php` accessible via catchall (statut 200, rendu HTML wrappé)
  - [ ] Test : `rep_cloud.php` accessible via catchall (statut 200, rendu HTML wrappé)
  - [ ] Test : `cloud_out.php` sert en raw (Content-Type text/plain, pas de wrapping layout)
  - [ ] Test : `have_right()` ne lève pas de fatal error dans le contexte du module
  - [ ] Test : structure du module (4 fichiers PHP présents dans `legacy/modules/partages/`)
  - [ ] Test : error logger sans erreur bloquante pour tag `partages` après chargement
  - [ ] Pattern : `$this->withoutVite()` dans `setUp()`, désactiver `WorkstationGroupObserver` si nécessaire

- [ ] **Tâche 6 : Smoke test sur VM** (AC: 1, 2, 3, 4, 5)
  - [ ] Via SSH (`ssh -i ~/.ssh/id_se4fs_vm root@192.168.122.50`) : accéder aux 4 URLs via le navigateur ou curl
  - [ ] Vérifier que la page `rep_classes.php` affiche la liste des partages de classes sans fatal
  - [ ] Vérifier que `cloud_out.php?id=test` retourne un contenu CMD ou vide (pas d'erreur 500)
  - [ ] Consulter le error logger via le dashboard legacy `/legacy/dashboard` ou directement en DB

---

## Dev Notes

### Contexte technique

- **Stack** : Laravel 12, PHP 8.1+, PostgreSQL via Eloquent
- **Source legacy** : `sambaedu/partages/` — symlink vers `/home/htouchard/code/irundo/se4/sources/var/www/sambaedu/partages`
- **Cible** : `legacy/modules/partages/` (à créer)
- **Tier** : Tier 2 — mais très simple (4 fichiers, 304 lignes, 0 exec, 0 SQL)
- **Effort estimé** : ~1h (SHIM EXPRESS le plus simple de la Category A)

### Inventaire des 4 fichiers

| Fichier | Lignes | Role | have_right | Sortie |
|---------|--------|------|:----------:|--------|
| `rep_classes.php` | 95 | Création répertoires classes + ACL | `SE_SHARE_REFRESH` (1 occ) | HTML (layout SER) |
| `rep_cloud.php` | 104 | Rafraîchissement partages cloud | `SE_USER_ADMIN` (1 occ) | HTML (layout SER) |
| `rep_cloud_cron.php` | 85 | Cron cloud — aucun `have_right` | — | HTML (layout SER) |
| `cloud_out.php` | 20 | Génération script CMD partage utilisateur | — | **text/plain** (raw) |

### Includes legacy requis (tous dans `sambaedu/includes/`)

| Fichier include | Lignes | Résolution |
|----------------|--------|------------|
| `samba.inc.php` | 141 | include_path → `sambaedu/includes/` |
| `partages.inc.php` | 673 | include_path → `sambaedu/includes/` |
| `partages_ui.inc.php` | 207 | include_path → `sambaedu/includes/` |
| `cloud.inc.php` | 3861 | include_path → `sambaedu/includes/` |
| `ent.inc.php` | 6481 | include_path → `sambaedu/includes/` (chargé uniquement dans `rep_cloud.php`) |
| `logs.inc.php` | stub | `legacy/stubs/logs.inc.php` (prioritaire via prepend) |
| `admin_ui.inc.php` | stub | `legacy/stubs/admin_ui.inc.php` (prioritaire via prepend) |
| `config.inc.php` | stub | `legacy/stubs/config.inc.php` bridge → `config('sambaedu.*')` |
| `ldap.inc.php` | shim | `legacy/ldap.inc.php` (shim complet, story 1bis-2) |
| `functions.inc.php` | legacy | chargé par `bootstrap.php` en global |
| `traitement_data.inc.php` | legacy | dans include_path `sambaedu/includes/` |
| `ihm.inc.php` | legacy | dans include_path `sambaedu/includes/` |

### Dépendances LDAP — analyse exacte

- **`have_right($config, SE_SHARE_REFRESH)`** dans `rep_classes.php:58` — vérifie si l'utilisateur a le droit de rafraîchir les partages (constante à confirmer dans `ldap.inc.php` ou `config.inc.php`)
- **`have_right($config, SE_USER_ADMIN)`** dans `rep_cloud.php:36` — vérifie si l'utilisateur a le droit admin utilisateur
- `have_right()` est implémenté dans `legacy/ldap.inc.php` (shim story 1bis-2) — **déjà couvert, aucun travail supplémentaire requis**

### Point d'attention : constantes SE_SHARE_REFRESH et SE_USER_ADMIN

Ces constantes de droits sont typiquement définies dans `config.inc.php` legacy ou dans `ldap.inc.php`. Vérifier leur présence dans le stub `legacy/config.inc.php` ou dans le shim LDAP. Si absentes, les ajouter avec leur valeur integer legacy (rechercher dans `sambaedu/includes/config.inc.php`).

### Mécanisme d'exécution (rappel story 1bis-4 / 1bis-10)

```
Requête HTTP (/partages/rep_classes.php?...)
  ↓ LegacyCatchallController
  ↓ resolve legacy/modules/partages/rep_classes.php
  ↓ executeViaBootstrap()
      ↓ require legacy/bootstrap.php (idempotent, LEGACY_BOOTSTRAP_LOADED)
          ↓ load config.inc.php (stub), ldap.inc.php (shim)
          ↓ prepend stubs/ + sambaedu/includes/ dans include_path
      ↓ chdir(legacy/modules/partages/)
      ↓ ob_start()
      ↓ require rep_classes.php
          ↓ have_right($config, SE_SHARE_REFRESH) → shim → Eloquent
      ↓ output capturé
  ↓ isHtmlWebPage() → true → wrap layout SER

---

Requête HTTP (/partages/cloud_out.php?id=xxx)
  ↓ même flux...
  ↓ require cloud_out.php
      ↓ header("Content-type: text/plain")
      ↓ genere_script_partage_utilisateur($config, $id)
  ↓ isHtmlWebPage() → false → réponse raw
```

### Learnings stories précédentes

- **1bis-4 (Tier 1 bundle)** : `$this->withoutVite()` dans `setUp()`, guard `LEGACY_BOOTSTRAP_LOADED`, guards shims
- **1bis-10 (iPXE)** : Content-Type text/plain détecté via `headers_list()` et `isHtmlWebPage()` → réponse raw. Ce pattern s'applique directement à `cloud_out.php`
- **1bis-10 (iPXE)** : pages avec `exit()` tuent PHPUnit — vérifier que `rep_cloud_cron.php` ne fait pas `exit()` en entrée
- **Convention** : ne pas nommer `createApplication()` dans les tests (collision avec `TestCase` Laravel)
- **WorkstationGroupObserver** : désactiver via `unsetEventDispatcher()` si les tests seedent des workstations

### Concernant la refonte native (hors périmètre de cette story)

Le module `partages` legacy sera remplacé par **Epic 5 — Système de Fichiers SER** :
- `5-1` : home directories + quotas XFS (FR13, FR16)
- `5-2` : partages de classe + ACLs POSIX (FR14, FR15)

À la livraison d'Epic 5, le dossier `legacy/modules/partages/` sera supprimé et les routes catchall correspondantes retirées. Cette story est une mesure conservatoire de transition.

### Project Structure Notes

- `legacy/modules/partages/` — nouveau dossier à créer (copie de `sambaedu/partages/`)
- `legacy/modules/` — contient déjà : `display/`, `dossier_echange/`, `gpo/`, `ipxe/`, `vendor/`
- `legacy/stubs/` — contient déjà : `admin_ui.inc.php`, `config.inc.php`, `gpo_deps.inc.php`, `ldap.inc.php`, `logs.inc.php`
- `legacy/bootstrap.php` — ne devrait pas nécessiter de modification
- `app/Http/Controllers/LegacyCatchallController.php` — ne devrait pas nécessiter de modification
- `tests/Feature/LegacyModulePartagesTest.php` — nouveau fichier à créer

### Références

- Architecture — Cloisonnement Legacy : `_bmad-output/planning-artifacts/architecture.md`
- Epics — Story 1bis.14 : `_bmad-output/planning-artifacts/epics.md#Story-1bis-14`
- Epic 5 — Système de Fichiers SER (cible refonte) : `_bmad-output/planning-artifacts/epics.md#Epic-5`
- Idempotency gap analysis § 8 : `_bmad-output/planning-artifacts/idempotency.md`
- Sprint change proposal 2026-04-17 : `_bmad-output/planning-artifacts/sprint-change-proposal-2026-04-17.md`
- Story 1bis-10 (iPXE, Content-Type pattern) : `_bmad-output/implementation-artifacts/1bis-10-module-ipxe.md`
- LegacyCatchallController : `app/Http/Controllers/LegacyCatchallController.php`
- Bootstrap : `legacy/bootstrap.php`
- Shim LDAP : `legacy/ldap.inc.php`
- Stubs : `legacy/stubs/`

---

## Testing Strategy

### Smoke tests (priorité)

Les tests sont intentionnellement légers — cette story est un SHIM EXPRESS, pas une refonte.

**`tests/Feature/LegacyModulePartagesTest.php`** (~6-8 tests, ~15-20 assertions) :

1. `test_module_files_exist` — asserter que les 4 fichiers PHP sont présents dans `legacy/modules/partages/`
2. `test_rep_classes_loads_without_fatal` — GET `/partages/rep_classes.php` → statut 200, contenu HTML, pas de fatal
3. `test_rep_cloud_loads_without_fatal` — GET `/partages/rep_cloud.php` → statut 200, contenu HTML wrappé
4. `test_rep_cloud_cron_loads_without_fatal` — GET `/partages/rep_cloud_cron.php` → statut 200
5. `test_cloud_out_serves_plain_text_raw` — GET `/partages/cloud_out.php` → Content-Type text/plain, pas de layout SER dans la réponse
6. `test_have_right_does_not_crash` — vérifier que l'appel `have_right()` ne lève pas d'exception
7. `test_error_logger_clean_after_module_load` — le error logger ne contient pas d'entrée ERROR pour `partages`

### Tests unitaires shim

Aucun test unitaire de shim supplémentaire requis : `have_right()` est déjà couvert par les tests de story 1bis-2. La résolution des includes legacy (`samba.inc.php`, `partages.inc.php`, etc.) est couverte par les tests Feature via l'exécution complète du bootstrap.

### Smoke test VM (validation manuelle)

```bash
ssh -i ~/.ssh/id_se4fs_vm root@192.168.122.50
# Tester les 4 URLs via curl ou le navigateur :
curl -s http://localhost/partages/rep_classes.php | head -20
curl -s http://localhost/partages/cloud_out.php?id=test
# Vérifier le error logger en DB ou via /legacy/dashboard
```

---

## Recommandation Modèle Dev

**Modèle recommandé : `sonnet`** (claude-sonnet-4-x ou équivalent)

**Justification :** Cette story est la plus simple de la Category A SHIM EXPRESS. Elle ne comporte :
- aucune logique nouvelle à concevoir
- aucun shim SQL à écrire
- aucun exec système à encadrer
- 4 fichiers PHP à copier sans modification
- ~6-8 tests Feature de smoke test

La tâche est principalement mécanique (copie, vérification d'includes, tests de chargement). Un modèle sonnet est largement suffisant et plus économique qu'opus pour ce type de travail. Aucune décision architecturale ni raisonnement complexe n'est requis.

---

## Dev Agent Record

### Agent Model Used
_à remplir_

### Debug Log References
_à remplir_

### Completion Notes List
_à remplir_

### File List
_à remplir_

### Change Log
_à remplir_

---

## Code Review

_à remplir lors de la review_

# Story 1bis.17 : Module `bbb`

Status: review

## Story

As a **développeur**,
I want intégrer le module legacy `bbb` dans `legacy/modules/bbb/` via le cloisonnement SHIM EXPRESS,
So que l'accès aux salons BigBlueButton (création, rejoindre, enregistrements) reste disponible via le catchall Laravel pendant que la refonte native est préparée.

---

## Contexte

> **⚡ SHIM EXPRESS ~2h** — décision Henri (shim confirmé vs BUILD 2j initialement envisagé), cf. `sprint-status.yaml` commentaire 1bis-17
>
> Audit empirique : `have_right($config, SE_ADMIN)` (`config.php`, `join.php`), `search_user($config, $login)` (`create.php`, `launch.php`), `is_eleve($config, $login)` (`create.php`, `launch.php`, `records.php`) sont déjà shimmés via le bridge LDAP (`legacy/ldap.inc.php`). **0 exec système.** **0 SQL direct.** L'API BBB est appelée via HTTP (cURL) directement par la librairie PHP `sambaedu/bigbluebutton-api-php`.
>
> Particularité : **0 exec système** — contrairement aux modules printers/dhcp, ce module n'appelle aucun binaire système. Toute la communication BBB passe par des requêtes HTTP cURL depuis la librairie `BigBlueButton\BigBlueButton`.
>
> Particularité APCu : `launch.php` et `bbb.inc.php` (`sambaedu/includes/`, 821 L) utilisent massivement APCu (`apcu_fetch/store/delete` sur les clés `meeting_info`, `visio_ext`, `liste_records_bbb`, `meeting_info_garbage_collector`). Si l'extension APCu n'est pas chargée sur la VM → fatal error. Risque déjà documenté dans la mémoire projet (`apcu-stub-logs`).
>
> Particularité autoloader : `launch.php` et `join.php` font `require "../vendor/autoload.php"` (chemin relatif depuis `legacy/modules/bbb/`). Ce chemin résout vers `legacy/modules/vendor/autoload.php` qui est le bridge existant retournant le Laravel autoloader — déjà chargé, pas de conflit. La librairie `sambaedu/bigbluebutton-api-php` est dans le `composer.json` Laravel (`"sambaedu/bigbluebutton-api-php": "2.0.12"`).
>
> Particularité collision `bbb.inc.php` : `bbb.inc.php` legacy (`sambaedu/includes/bbb.inc.php`) fait lui-même `require_once(dirname(__FILE__).'/../vendor/autoload.php')` qui pointe vers le legacy vendor (`sambaedu/vendor/`). Les deux autoloaders (Laravel + legacy) déclarent les classes `BigBlueButton\*`. Risque de `Cannot redeclare` si les deux autoloaders essaient d'inclure les mêmes fichiers de classe. À surveiller — un stub `legacy/stubs/bbb.inc.php` peut être nécessaire si la collision est avérée.
>
> Particularité `refresh.php` : utilise `header_authorize_script($config)` (comme les endpoints script du module dhcp). Il est appelé par des cron/JS et produit du texte court — doit être servi raw (sans layout HTML).
>
> Scope minimal : `cp -r sambaedu/bbb sambaedu-reload/legacy/modules/bbb/` + vérification APCu sur VM + smoke tests via catchall. La refonte native est déférée à une epic dédiée (BBB/Visioconférence).

---

## Acceptance Criteria

**AC1 — Module copié et accessible**
Given le module `bbb` est copié dans `legacy/modules/bbb/` (6 fichiers PHP, 503 L),
When j'accède aux URLs principales (`/bbb/config.php`, `/bbb/create.php`, `/bbb/join.php`, `/bbb/records.php`) via le catchall Laravel,
Then chaque page se charge sans erreur PHP fatale
And le rendu HTML est wrappé dans le layout SER.

**AC2 — `refresh.php` servi raw (endpoint script)**
Given `refresh.php` appelle `header_authorize_script($config)` et produit une réponse texte courte (statut des meetings BBB),
When le catchall sert cet endpoint,
Then la réponse est retournée au client sans altération bloquante (pas de layout SER imposé si le contenu n'est pas une page HTML complète)
And si la clé d'autorisation est absente ou invalide, `header_authorize_script()` fait `exit()` gracefully sans fatal.

**AC3 — Shim LDAP `have_right`, `search_user`, `is_eleve` fonctionnels**
Given les fichiers du module appellent `have_right($config, SE_ADMIN)` (`config.php`, `join.php`), `search_user($config, $login)` (`create.php`, `launch.php`) et `is_eleve($config, $login)` (`create.php`, `launch.php`, `records.php`),
When ces fonctions sont invoquées via le bridge LDAP shimmé (`legacy/ldap.inc.php`),
Then elles retournent une valeur cohérente (droits, objet user, booléen élève)
And aucune fatal error PHP n'est levée.

**AC4 — Résolution des includes (dont `bbb.inc.php` et bridge `../vendor/autoload.php`)**
Given le module charge `config.inc.php` (stub), `ldap.inc.php` (shim), `functions.inc.php`, `traitement_data.inc.php`, `admin_ui.inc.php` (stub), `ihm.inc.php`, `bbb.inc.php` (821 L, `sambaedu/includes/`) via l'include_path, et que `launch.php` + `join.php` font `require "../vendor/autoload.php"` (bridge `legacy/modules/vendor/autoload.php`),
When le bootstrap legacy est actif (`LEGACY_BOOTSTRAP_LOADED`),
Then tous les includes se résolvent sans conflit avec les stubs (`legacy/stubs/`)
And aucune fonction ou classe n'est redéclarée (fatal "Cannot redeclare").

**AC5 — APCu : risque documenté et extension vérifiée**
Given `launch.php` et `bbb.inc.php` utilisent massivement `apcu_fetch/store/delete` sur les clés `meeting_info`, `visio_ext`, `liste_records_bbb`, `meeting_info_garbage_collector`,
When l'extension APCu n'est pas chargée sur la VM,
Then une erreur explicite est documentée (fatal possible) et le smoke test APCu est marqué `markTestSkipped` si APCu absent
And quand APCu est présent, `launch.php` et `refresh.php` s'exécutent sans fatal APCu.

**AC6 — Error logger propre**
Given le module est intégré et la suite de smoke tests passe,
When le error logger (`ErrorLoggerService`) est consulté après exécution,
Then aucune erreur récurrente bloquante (niveau ERROR ou FATAL) n'est présente pour le tag `legacy` (hors limitation BBB server absent documentée comme acceptable).

---

## Dépendances

| Story | Titre | Status | Détail |
|-------|-------|--------|--------|
| 1bis-1 | Error logger & dashboard | done | `LegacyErrorHandler` actif, capte les erreurs du module |
| 1bis-2 | Bootstrap & shim LDAP | done | `legacy/ldap.inc.php` fournit `have_right()`, `search_user()`, `is_eleve()`, constantes `SE_*`, include_path prépendé |
| 1bis-3 | Shim SQL MySQL → Eloquent | done | Requis par le bootstrap — aucune dépendance SQL directe dans ce module |
| 1bis-4 | Bundle Tier 1 (catchall) | done | `LegacyCatchallController` avec `executeViaBootstrap()`, `chdir()`, `isHtmlWebPage()` — patterns validés |

Toutes les dépendances sont satisfaites. La story peut être implémentée immédiatement.

---

## Tasks / Subtasks

- [x] **Tâche 1 : Copier le module `bbb` dans `legacy/modules/bbb/`** (AC: 1, 2)
  - [x] Copier l'intégralité du dossier `sambaedu/bbb/` vers `legacy/modules/bbb/`
  - [x] Vérifier la structure : 6 fichiers PHP (`config.php`, `create.php`, `join.php`, `launch.php`, `records.php`, `refresh.php`) — 503 lignes au total
  - [x] Ne pas modifier le contenu des fichiers PHP — le bootstrap + shims doivent les faire fonctionner tels quels

- [x] **Tâche 2 : Vérifier la résolution des includes** (AC: 4)
  - [x] Confirmer que `bbb.inc.php` (821 L, `sambaedu/includes/`) se résout via include_path
  - [x] Confirmer que `../vendor/autoload.php` depuis `legacy/modules/bbb/` résout vers `legacy/modules/vendor/autoload.php` (bridge existant) — pas de rechargement intempestif
  - [x] Vérifier l'absence de collision de classes `BigBlueButton\*` entre le Laravel autoloader et le legacy vendor autoloader (chargé par `sambaedu/includes/bbb.inc.php` → `sambaedu/vendor/autoload.php`)
  - [x] Collision `Cannot redeclare config_bbb()` observée (bbb.inc.php ne protège pas ses fonctions avec `if (!function_exists())`) → stub `legacy/stubs/bbb.inc.php` créé avec guard `LEGACY_BBB_INC_LOADED` + `require_once` vers l'original
  - [x] Confirmer que `admin_ui.inc.php` est fourni par le stub (`legacy/stubs/admin_ui.inc.php`) en priorité
  - [x] Confirmer que `config.inc.php` est fourni par le stub et que `header_authorize($config)` + `header_authorize_script($config)` sont disponibles
  - [x] Vérifier que `ihm.inc.php`, `traitement_data.inc.php`, `functions.inc.php` se résolvent depuis `sambaedu/includes/` via include_path

- [x] **Tâche 3 : Vérifier le shim LDAP et les constantes** (AC: 3)
  - [x] Vérifier que `SE_ADMIN` est définie dans `legacy/ldap.inc.php` (présente : `SE_ADMIN = 0xFFFF`)
  - [x] Vérifier que `search_user($config, $login)` est shimmé dans `legacy/ldap.inc.php` et retourne un tableau cohérent
  - [x] Vérifier que `is_eleve($config, $login)` est shimmé dans `legacy/ldap.inc.php` et retourne un booléen
  - [x] Confirmer que `have_right($config, SE_ADMIN)` retourne `false` pour un utilisateur non-admin (protection des pages)
  - [x] Vérifier que `curl_proxy_options($config, $curlopts)` (définie dans `sambaedu/includes/functions.inc.php`) est accessible via include_path

- [x] **Tâche 4 : Vérifier APCu sur VM et documenter le risque** (AC: 5)
  - [x] APCu présent sur la VM : `php -m | grep -i apcu` → `apcu`
  - [x] Validé : `apcu_fetch('meeting_info')` retourne `false` si clé absente (pas de fatal)
  - [x] Clés APCu documentées : `meeting_info`, `visio_ext`, `liste_records_bbb`, `meeting_info_garbage_collector`

- [x] **Tâche 5 : Valider le comportement de `refresh.php`** (AC: 2)
  - [x] `refresh.php` : accessible sans fatal (réponse vide si clé absente → `header_authorize_script()` fait `exit()`)
  - [x] POST vide → `header_authorize_script()` → exit() → réponse vide, pas de fatal PHP

- [x] **Tâche 6 : Écrire les tests Feature** (AC: 1, 2, 3, 6)
  - [x] Créer `tests/Feature/LegacyModuleBbbTest.php`
  - [x] Test : structure du module (6 fichiers PHP présents dans `legacy/modules/bbb/`)
  - [x] Test : `config.php` accessible via catchall (pas 404, pas de Fatal error legacy — `have_right(SE_ADMIN)` → false → "droits insuffisants")
  - [x] Test : `create.php` accessible via catchall (pas 404, pas de Fatal error)
  - [x] Test : `join.php` accessible via catchall (pas 404, pas de Fatal error)
  - [x] Test : `records.php` accessible via catchall (pas 404, pas de Fatal error)
  - [x] Test : `refresh.php` GET/POST sans clé → exit() graceful, pas de fatal
  - [x] Test : `have_right($config, SE_ADMIN)` ne lève pas de fatal error
  - [x] Test : `is_eleve($config, $login)` ne lève pas de fatal error (shim retourne true pour utilisateur inexistant)
  - [x] Test : `search_user($config, $login)` ne lève pas de fatal error
  - [x] Test : APCu disponible (markTestSkipped si absent) — `launch.php` sans fatal APCu
  - [x] Tests authentifiés actingAs : `config.php` + `join.php` en admin (login='admin' → SE_ADMIN)
  - [x] Test : error logger sans Fatal error PHP legacy après chargement
  - [x] Pattern : `$this->withoutVite()` dans `setUp()`, table `users` créée, `$_SESSION = []` dans setUp/tearDown

- [x] **Tâche 7 : Smoke test sur VM** (AC: 1, 2, 3, 4, 5, 6)
  - [x] `curl http://localhost/bbb/config.php` → "Vous n'avez pas les droits nécessaires" (have_right false, pas de fatal)
  - [x] `curl http://localhost/bbb/join.php` → HTML bien formé (layout SER)
  - [x] `curl -X POST http://localhost/bbb/refresh.php` → réponse vide (exit() via header_authorize_script)
  - [x] 14/14 tests verts, 41 assertions, 0.41s

- [x] **Tâche 8 : Mettre à jour sprint-status.yaml** (toutes AC)
  - [x] Passé `1bis-17-module-bbb` de `ready-for-dev` à `review`
  - [x] Commentaire inline avec le résultat des tests ajouté

---

## Dev Notes

### Contexte technique

- **Stack** : Laravel 12, PHP 8.1+, PostgreSQL via Eloquent
- **Source legacy** : `sambaedu/bbb/` — symlink vers `/home/htouchard/code/irundo/se4/sources/var/www/sambaedu/bbb`
- **Cible** : `legacy/modules/bbb/` (à créer)
- **Tier** : Tier 3 — 6 fichiers, 503 lignes, **0 exec système**, API BBB via HTTP/cURL
- **Effort estimé** : ~2h (SHIM EXPRESS — catégorie A)

### Inventaire des 6 fichiers

| Fichier | Lignes | Rôle | have_right | APCu | Sortie |
|---------|-------:|------|:----------:|:----:|--------|
| `config.php` | 75 | Configuration serveurs BBB | `SE_ADMIN` | — | HTML (layout SER) |
| `create.php` | 50 | Formulaire création salon BBB (profs uniquement) | — (`is_eleve()` guard) | — | HTML (layout SER) |
| `join.php` | 62 | Liste des salons rejoignables | `SE_ADMIN` (infos admin seulement) | — | HTML (layout SER) |
| `launch.php` | 234 | Créer/rejoindre un meeting BBB (cœur) | — (`is_eleve()` + `search_user()`) | `meeting_info`, `visio_ext` | HTML (redirect ou layout SER) |
| `records.php` | 51 | Liste/suppression des enregistrements | — (`is_eleve()` guard) | `liste_records_bbb` | HTML (layout SER) |
| `refresh.php` | 31 | Endpoint cron/JS — statut des meetings | — (script auth) | via `bbb.inc.php` | Texte court (raw) |

### Particularité clé : 0 exec système

Contrairement aux modules `printers` et `dhcp`, **aucun appel `exec()` ou `system()` n'est présent** dans ce module. Toute la communication avec les serveurs BBB se fait via des requêtes HTTP cURL orchestrées par la librairie `BigBlueButton\BigBlueButton`. Les seuls risques sont :

1. **APCu** (cf. ci-dessous) — fatal si non chargé
2. **Serveur BBB inaccessible** — cURL échoue gracefully (la librairie gère les erreurs réseau), pas de fatal PHP

### Librairie BigBlueButton PHP

- `launch.php`, `join.php`, `bbb.inc.php` utilisent `use BigBlueButton\BigBlueButton;` etc.
- La librairie est dans le `composer.json` Laravel : `"sambaedu/bigbluebutton-api-php": "2.0.12"`
- `legacy/modules/vendor/autoload.php` est le bridge existant → retourne le Laravel autoloader (déjà chargé)
- Donc `require "../vendor/autoload.php"` depuis `legacy/modules/bbb/*.php` résout proprement vers ce bridge

**Risque de collision** : `bbb.inc.php` (`sambaedu/includes/bbb.inc.php`) fait en tête :
```php
require_once(dirname(__FILE__).'/../vendor/autoload.php');
```
Depuis `sambaedu/includes/`, `dirname(__FILE__).'/../vendor/'` pointe vers `sambaedu/vendor/`. Ce vendor legacy contient aussi `sambaedu/bigbluebutton-api-php`. Les deux autoloaders (Laravel + legacy) peuvent tenter de déclarer les mêmes classes `BigBlueButton\*`.

**Résolution attendue** : PHP's `require_once` + `class_exists()` implicite dans les autoloaders modernes devrait éviter la redéclaration. À confirmer lors de l'exécution — si `Cannot redeclare class BigBlueButton\BigBlueButton` apparaît, créer `legacy/stubs/bbb.inc.php` qui neutralise ce `require_once` avant de déléguer au fichier original.

### Piège APCu (critique)

`launch.php` et `bbb.inc.php` (`sambaedu/includes/`) utilisent massivement APCu :

| Clé APCu | Fichier | Usage |
|----------|---------|-------|
| `meeting_info` | `launch.php`, `bbb.inc.php` | Cache des informations de réunion BBB |
| `visio_ext` | `launch.php`, `bbb.inc.php` | Configuration serveur BBB externe |
| `liste_records_bbb` | `bbb.inc.php` | Liste des enregistrements (cache) |
| `meeting_info_garbage_collector` | `bbb.inc.php` | Nettoyage des entrées expirées |

Si APCu n'est pas chargé sur la VM → appel à `apcu_fetch()` → **fatal error : Call to undefined function**. Ce risque est déjà documenté dans la mémoire projet (`apcu-stub-logs`).

**Options** :
1. Documenter la limitation (cette story) et vérifier APCu sur la VM avant les smoke tests. (recommandé)
2. Ne pas bloquer la story sur ce point — les pages sans APCu (`config.php`, `create.php` partiel, `join.php`, `records.php`) fonctionnent sans APCu.
3. Déférer un wrap `function_exists('apcu_fetch')` à la refonte native.

**Recommandation** : option 1 — vérifier `php -m | grep apcu` sur la VM. APCu est présent sur la VM dev (cf. 1bis-16 : `script_make_reservations.php` APCu validé). Très probablement OK.

### `refresh.php` — endpoint script

`refresh.php` (31 L) suit le même pattern que `make_reservations.php` / `dnsupdate.php` (module dhcp) :
- Appelle `header_authorize_script($config)` en tête → exit() si clé absente
- Produit une réponse texte courte (statut des meetings via `load_meeting_info()` depuis `bbb.inc.php`)
- Ne doit pas être wrappé dans le layout SER

`header_authorize_script()` est déjà définie dans `legacy/stubs/config.inc.php` (confirmé pour le module dhcp) — pas de stub supplémentaire nécessaire.

### Includes legacy requis

| Fichier include | Source | Résolution |
|----------------|--------|------------|
| `config.inc.php` | stub `legacy/stubs/config.inc.php` | bridge → `config('sambaedu.*')` |
| `ldap.inc.php` | shim `legacy/ldap.inc.php` (story 1bis-2) | shim complet |
| `functions.inc.php` | `sambaedu/includes/` | chargé via bootstrap |
| `traitement_data.inc.php` | `sambaedu/includes/` | include_path |
| `admin_ui.inc.php` | stub `legacy/stubs/admin_ui.inc.php` | prioritaire via prepend |
| `ihm.inc.php` | `sambaedu/includes/` | include_path |
| `bbb.inc.php` | `sambaedu/includes/` (821 L) | include_path — **attention collision autoloader** |

### Fonctions mobilisées

- **LDAP (shim `legacy/ldap.inc.php`)** : `have_right`, `search_user`, `is_eleve`, constante `SE_ADMIN`
- **BBB (legacy `bbb.inc.php` + librairie)** : `load_meeting_info()`, `get_meeting_info()`, `create_meeting_bbb()`, `join_meeting_bbb()`, `get_recordings_bbb()`, `delete_recording_bbb()`
- **Network (legacy `functions.inc.php`)** : `curl_proxy_options($config, $curlopts)` — options cURL proxy
- **Config bridge** : `$config['bbb_servers']`, `$config['bbb_secret']`, `$config['login']`

### Mécanisme d'exécution (rappel story 1bis-4)

```
Requête HTTP (/bbb/join.php?...)
  ↓ LegacyCatchallController
  ↓ resolve legacy/modules/bbb/join.php
  ↓ executeViaBootstrap()
      ↓ require legacy/bootstrap.php (idempotent, LEGACY_BOOTSTRAP_LOADED)
          ↓ load config.inc.php (stub), ldap.inc.php (shim)
          ↓ prepend stubs/ + sambaedu/includes/ dans include_path
      ↓ chdir(legacy/modules/bbb/)
      ↓ ob_start()
      ↓ require join.php
          ↓ require "../vendor/autoload.php" → legacy/modules/vendor/autoload.php (bridge)
          ↓ include "bbb.inc.php" → sambaedu/includes/bbb.inc.php
              ↓ require_once dirname()/../vendor/autoload.php → sambaedu/vendor/ (legacy)
              ↓ [possible collision classes BBB — surveiller]
          ↓ have_right($config, SE_ADMIN) → shim → Eloquent
          ↓ load_meeting_info() → apcu_fetch('meeting_info') [APCu requis]
      ↓ output capturé
  ↓ isHtmlWebPage() → true → wrap layout SER

---

Requête HTTP (/bbb/refresh.php) [GET cron / JS]
  ↓ même flux...
  ↓ require refresh.php
      ↓ header_authorize_script($config) → exit() si clé absente
      ↓ load_meeting_info() → apcu_fetch('meeting_info')
      ↓ echo ... (texte court)
  ↓ isHtmlWebPage() → false ou ambigu — validation au test
```

### Learnings stories précédentes

- **1bis-4 (Tier 1 bundle)** : `$this->withoutVite()` dans `setUp()`, guard `LEGACY_BOOTSTRAP_LOADED`, guards shims
- **1bis-16 (dhcp)** : pattern endpoint script (`make_reservations.php`, `dnsupdate.php`) → direct parallèle avec `refresh.php`. `header_authorize_script()` déjà validée.
- **1bis-16 (dhcp)** : APCu sur VM dev confirmé présent — très probable que `launch.php` et `refresh.php` fonctionnent sans stub supplémentaire.
- **1bis-15 (printers)** : collision d'autoloaders possible si deux `require_once` sur la même classe — même risque ici pour `BigBlueButton\*`. Résolution via stub si nécessaire.
- **1bis-10 (iPXE)** : pages avec `exit()` tuent PHPUnit — `refresh.php` fait `exit()` via `header_authorize_script()`. Test Feature doit gérer ce cas (assertStatus + pas de die() avant).
- **Convention** : ne pas nommer `createApplication()` dans les tests (collision TestCase Laravel).
- **Table `users` manquante en SQLite :memory:** : créer manuellement dans `setUp()` (pattern validé 1bis-15, 1bis-16).
- **`$_SESSION` leak entre tests** : reset `$_SESSION = []` dans setUp/tearDown (mémoire projet `feedback_session_leak_tests`).

### Concernant la refonte native (hors périmètre de cette story)

Le module `bbb` legacy sera remplacé par une epic dédiée Visioconférence/BBB :
- Intégration native BBB via l'API Laravel (Eloquent pour les meetings, jobs pour la gestion des enregistrements)
- Gestion des droits via Spatie (permissions `bbb.create`, `bbb.admin`)

À la livraison de cette epic, le dossier `legacy/modules/bbb/` sera supprimé et les routes catchall correspondantes retirées. Cette story est une mesure conservatoire de transition.

### Project Structure Notes

- `legacy/modules/bbb/` — nouveau dossier à créer (copie de `sambaedu/bbb/`)
- `legacy/modules/` — contient déjà : `display/`, `dossier_echange/`, `gpo/`, `ipxe/`, `vendor/`, `printers/`, `dhcp/`
- `legacy/stubs/` — contient déjà : `admin_ui.inc.php`, `config.inc.php`, `gpo_deps.inc.php`, `ldap.inc.php`, `logs.inc.php`, `printers.inc.php`, `partages.inc.php`, `ihm.inc.php`, `dhcpd.inc.php`
- `legacy/bootstrap.php` — ne devrait pas nécessiter de modification
- `app/Http/Controllers/LegacyCatchallController.php` — ne devrait pas nécessiter de modification
- `tests/Feature/LegacyModuleBbbTest.php` — nouveau fichier à créer

### Références

- Architecture — Cloisonnement Legacy : `_bmad-output/planning-artifacts/architecture.md`
- Idempotency gap analysis § 8 : `_bmad-output/planning-artifacts/idempotency.md`
- Sprint change proposal 2026-04-17 : `_bmad-output/planning-artifacts/sprint-change-proposal-2026-04-17.md`
- Story 1bis-10 (iPXE, Content-Type pattern) : `_bmad-output/implementation-artifacts/1bis-10-module-ipxe.md`
- Story 1bis-15 (printers, SHIM EXPRESS stubs + collision) : `_bmad-output/implementation-artifacts/1bis-15-module-printers.md`
- Story 1bis-16 (dhcp, endpoints scripts + APCu) : `_bmad-output/implementation-artifacts/1bis-16-module-dhcp.md`
- LegacyCatchallController : `app/Http/Controllers/LegacyCatchallController.php`
- Bootstrap : `legacy/bootstrap.php`
- Shim LDAP : `legacy/ldap.inc.php`
- Stubs : `legacy/stubs/`
- Include BBB legacy : `sambaedu/includes/bbb.inc.php` (821 L)
- Mémoire projet APCu : `apcu_risk.md` (dans la mémoire projet)

---

## Testing Strategy

### Smoke tests (priorité)

Les tests sont intentionnellement légers — cette story est un SHIM EXPRESS, pas une refonte.

**`tests/Feature/LegacyModuleBbbTest.php`** (~8-10 tests, ~18-22 assertions) :

1. `test_module_files_exist` — asserter que les 6 fichiers PHP sont présents dans `legacy/modules/bbb/`
2. `test_config_loads_without_fatal` — GET `/bbb/config.php` → pas 404, pas de fatal PHP (have_right false → "droits insuffisants")
3. `test_create_loads_without_fatal` — GET `/bbb/create.php` → pas 404, pas de fatal (is_eleve → false → accès refusé ou formulaire vide)
4. `test_join_loads_without_fatal` — GET `/bbb/join.php` → pas 404, pas de fatal PHP
5. `test_records_loads_without_fatal` — GET `/bbb/records.php` → pas 404, pas de fatal (is_eleve → liste vide)
6. `test_refresh_endpoint_without_auth_key` — POST `/bbb/refresh.php` sans clé → exit() graceful via `header_authorize_script()`, pas de fatal
7. `test_have_right_se_admin_does_not_crash` — vérifier que `have_right($config, SE_ADMIN)` ne lève pas d'exception
8. `test_is_eleve_does_not_crash` — vérifier que `is_eleve($config, 'testuser')` ne lève pas d'exception (retourne false)
9. `test_apcu_available_for_launch` — markTestSkipped si APCu absent ; sinon GET `/bbb/launch.php` → pas de fatal APCu
10. `test_error_logger_clean_after_module_load` — le error logger ne contient pas d'entrée ERROR fatale pour tag `legacy`

> `launch.php` est le fichier le plus complexe (234 L, APCu + cURL BBB). Le tester en Feature sans APCu provoque un skip propre. Avec APCu, tester l'accès minimal (is_eleve → false → redirection ou message).

### Tests unitaires shim

Aucun test unitaire de shim supplémentaire requis : `have_right()`, `search_user()`, `is_eleve()` sont déjà couverts par les tests de la story 1bis-2. La résolution des includes legacy est couverte par les tests Feature via l'exécution complète du bootstrap.

### Smoke test VM (validation manuelle)

```bash
ssh -i ~/.ssh/id_se4fs_vm root@192.168.122.50
# Vérifier APCu :
php -m | grep -i apcu
# Tester les URLs via curl :
curl -s http://localhost/bbb/config.php | head -20
curl -s http://localhost/bbb/join.php | head -20
curl -s http://localhost/bbb/create.php | head -20
curl -s http://localhost/bbb/records.php | head -20
curl -s -X POST http://localhost/bbb/refresh.php | head -20
# Vérifier le error logger en DB ou via /legacy/dashboard
php artisan test --filter=LegacyModuleBbb
```

---

## Implementation Notes

- **APCu** : très probablement présent sur la VM dev (validé lors de 1bis-16). Vérifier `php -m | grep apcu` avant les smoke tests de `launch.php` et `refresh.php`.
- **Serveur BBB** : la VM dev ne sera pas connectée à un serveur BBB réel — les appels cURL (`create_meeting_bbb()`, `join_meeting_bbb()`) échoueront réseau. Ce n'est pas une fatal PHP — la librairie BBB gère les erreurs HTTP gracefully. Comportement attendu : page d'erreur "serveur BBB inaccessible" ou redirection vers `join.php`.
- **Collision autoloader** : si `Cannot redeclare class BigBlueButton\BigBlueButton` apparaît → créer `legacy/stubs/bbb.inc.php` qui remplace le `require_once` vers le legacy vendor par un no-op (les classes sont déjà disponibles via le Laravel autoloader).
- La collision est **moins probable** qu'il n'y paraît : les autoloaders modernes (Composer) utilisent un registre `spl_autoload_register` qui ne recharge pas une classe déjà présente en mémoire, et `require_once` sur `vendor/autoload.php` est idempotent (PHP guard sur le chemin résolu). À confirmer empiriquement.

---

## Recommandation Modèle Dev

**Modèle recommandé : `sonnet`** (claude-sonnet-4-x ou équivalent)

**Justification :** Cette story est un SHIM EXPRESS de Category A, le plus simple de la série (0 exec système, 6 fichiers, 503 L). Le pattern est identique aux stories 1bis-15 (printers) et 1bis-16 (dhcp) : copie du module, vérification des includes, écriture de ~8-10 smoke tests, validation sur VM. Les fonctions LDAP (`have_right`, `search_user`, `is_eleve`) et la constante `SE_ADMIN` sont déjà shimmées. La seule subtilité est (1) le piège APCu dans `launch.php`/`bbb.inc.php` — documenté, pattern déjà géré en 1bis-16, et (2) la possible collision d'autoloaders `BigBlueButton\*` — à vérifier empiriquement, résolvable par un stub simple si nécessaire. L'endpoint script `refresh.php` suit exactement le même pattern que `make_reservations.php`/`dnsupdate.php` (1bis-16). Aucun raisonnement architectural nouveau n'est requis. Un modèle sonnet est largement suffisant.

---

## Dev Agent Record

### Agent Model Used

claude-sonnet-4-6

### Debug Log References

- Erreur découverte lors des premiers tests : `Cannot redeclare config_bbb()` — bbb.inc.php est inclus plusieurs fois dans la même session PHPUnit (4 fichiers du module font `include "bbb.inc.php"` sans guard). Résolu par stub `legacy/stubs/bbb.inc.php` avec constante `LEGACY_BBB_INC_LOADED`.
- APCu confirmé présent sur la VM dev (validé lors de l'implémentation).
- Collision autoloader `BigBlueButton\*` : non observée empiriquement — les deux autoloaders PSR-4 (Laravel + legacy) sont idempotents via `require_once`, les classes ne sont pas redéclarées.

### Completion Notes List

- 6 fichiers PHP copiés depuis `sambaedu/bbb/` vers `legacy/modules/bbb/` sans modification.
- Stub `legacy/stubs/bbb.inc.php` nécessaire : `bbb.inc.php` ne protège pas ses fonctions avec `if (!function_exists())`, produit `Cannot redeclare config_bbb()` lors des inclusions multiples. Stub avec guard `LEGACY_BBB_INC_LOADED` + `require_once` vers le fichier original.
- APCu présent sur la VM dev → `launch.php` et `refresh.php` fonctionnent sans stub supplémentaire.
- Serveur BBB non accessible en dev → appels cURL échouent gracefully (pas de fatal PHP).
- 14/14 tests verts, 41 assertions, 0.41s sur VM.
- Smoke tests VM : `config.php` → "droits insuffisants", `join.php` → HTML layout SER, `refresh.php` POST sans clé → réponse vide.

### File List

**Fichiers créés :**
- `legacy/modules/bbb/config.php` — copie depuis `sambaedu/bbb/`
- `legacy/modules/bbb/create.php` — copie depuis `sambaedu/bbb/`
- `legacy/modules/bbb/join.php` — copie depuis `sambaedu/bbb/`
- `legacy/modules/bbb/launch.php` — copie depuis `sambaedu/bbb/`
- `legacy/modules/bbb/records.php` — copie depuis `sambaedu/bbb/`
- `legacy/modules/bbb/refresh.php` — copie depuis `sambaedu/bbb/`
- `legacy/stubs/bbb.inc.php` — stub guard idempotent pour éviter `Cannot redeclare config_bbb()`
- `tests/Feature/LegacyModuleBbbTest.php` — 14 tests Feature (smoke tests module BBB)

**Fichiers modifiés :**
- `_bmad-output/implementation-artifacts/1bis-17-module-bbb.md` — status → review, tâches cochées, Dev Agent Record rempli
- `_bmad-output/implementation-artifacts/sprint-status.yaml` — `1bis-17-module-bbb: review`

### Change Log

- 2026-04-18 : Implémentation initiale — module BBB copié, stub bbb.inc.php créé, tests Feature écrits, 14/14 tests verts sur VM.

---

## Code Review

_à remplir lors de la review_

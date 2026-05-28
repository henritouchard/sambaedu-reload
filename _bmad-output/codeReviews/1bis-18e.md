# Code Review — Story 1bis.18e : Module GPO — Scripts réseau, Veyon, Wine, applications, associations

Status: in-review
Date: 2026-04-21
Dev model: claude-sonnet-4-6 (sonnet)
Review model: claude-opus-4-7 (opus)
Second review: non (opus a déjà reviewé → pas de second avis nécessaire)

---

## Questions en attente

> Questions nécessitant une décision de Henri avant finalisation.

1. **Exécution VM effectivement faite ?** Le dev a écrit "Tests non exécutés localement" — as-tu pu vérifier en VM que les 16 tests passent ? Si non, AC #11 reste formellement non validé. Les corrections post-review (voir P1/P2/P8) augmentent la probabilité de passage en VM mais ne remplacent pas l'exécution réelle.

2. ~~**P4 — Bug `$application = $select_application` de `wine.php`**~~ — **Décidé 2026-04-22** : option (a) retenue, legacy conservé byte-identique. Backlog epic 9 pour remédiation future.

3. **P6 — Stub `traitement_data.inc.php` désactive HTMLPurifier sur VM aussi** : le stub no-op intercepte l'original sur VM (stubs préfixés dans include_path). Corrigé en P6-fix (stub conditionnel qui charge l'original si `sambaedu/vendor/autoload.php` existe). À confirmer en VM : est-ce que `sambaedu/vendor/autoload.php` existe bien sur la VM de prod ? Sinon, la purification reste désactivée et il faudra un follow-up séparé.

---

## Synthèse des problèmes

| # | Problème | Sévérité | Pertinence Opus | Statut |
|---|----------|----------|-----------------|--------|
| 1 | Tests intégralement skipés en local (LEGACY_SKIP_LEGACY_INCLUDES) | 🔴 | — | ✅ Corrigé |
| 2 | `@runInSeparateProcess` absent sur 4 tests avec `exit()` | 🔴 | — | ✅ Corrigé |
| 3 | `test_wine_page_denies_access_without_admin` ne teste pas assez | 🟠 | — | ✅ Corrigé |
| 4 | Audit sécurité : `batch_command` non échappé + bug ligne 52 `wine.php` | 🟠 | — | ✅ Décidé (a) : legacy byte-identique conservé, backlog epic 9 |
| 5 | Audit exec `$_POST['id']` user-controlled mal documenté | 🟠 | — | ✅ Corrigé (doc) |
| 6 | Stub `traitement_data.inc.php` désactive silencieusement HTMLPurifier | 🟠 | — | ✅ Corrigé |
| 7 | Assertions tolérantes qui masquent les échecs réels | 🟡 | — | ✅ Corrigé |
| 8 | `test_no_fatal_error_after_passive_load` s'auto-sabote (exit) | 🟡 | — | ✅ Corrigé |
| 9 | Commentaire du stub `wpkg_libsql.php` trompeur | 🟡 | — | ✅ Corrigé |
| 10 | Side effects `test_mef`/`mise_en_forme_personnalisee` non shimmés | 🟡 | — | ✅ Corrigé (doc) |
| 11 | `test_associations_out_returns_json_content_type_with_mocked_apcu` fragile | 🟡 | — | ✅ Corrigé |

Légende sévérité : 🔴 Critique — 🟠 Important — 🟡 Mineur

---

## Détail des problèmes

### #1 — Tests intégralement skipés en local (LEGACY_SKIP_LEGACY_INCLUDES)

**Sévérité** : 🔴 Critique
**Fichier(s)** : `tests/Feature/Legacy/LegacyModuleGpoOutputsTest.php:109-118`, `tests/bootstrap.php:17-19`

**Constat initial (review)** :
`tests/bootstrap.php` définit inconditionnellement `LEGACY_SKIP_LEGACY_INCLUDES`, et 10 des 12 tests appellent `skipIfBootstrapUnavailable()` qui skip dès que cette constante est définie. Résultat : la suite ne peut jamais échouer en host/CI. AC #11 formellement non rempli. Le pattern de `LegacyModuleGpoGestionTest::skipIfBootstrapUnavailable()` (lignes 125-150) skip uniquement si les chemins `includes/gpo.inc.php` / `sambaedu/vendor/autoload.php` sont absents — pas sur la constante.

**Solutions possibles** :
1. Aligner `skipIfBootstrapUnavailable` sur le pattern 18b (test le chemin réel).
2. Ajouter un `@group vm` pour séparer les tests VM-only.

**Action appliquée** : Solution 1 — aligner sur 18b.

### #2 — `@runInSeparateProcess` absent sur 4 tests avec `exit()`

**Sévérité** : 🔴 Critique
**Fichier(s)** : `tests/Feature/Legacy/LegacyModuleGpoOutputsTest.php:247, 276, 388, 468`

**Constat initial (review)** :
`veyon_out.php` fait `exit()` lignes 19 et 27. `associations_out.php` fait `exit()` ligne 26. Les tests correspondants déclenchent un `exit()` qui termine PHPUnit. La story documentait pourtant explicitement (Dev Notes §Particularités #2 et §Learnings 18b) qu'il faut utiliser `@runInSeparateProcess / @preserveGlobalState disabled`.

Tests concernés :
- `test_veyon_out_licence_mode`
- `test_veyon_out_nominal_without_apcu_is_graceful`
- `test_associations_out_rejects_missing_id_or_list`
- `test_no_fatal_error_after_passive_load` (couvert aussi par P8)

**Solutions possibles** :
1. Ajouter `@runInSeparateProcess` + `@preserveGlobalState disabled` sur les 4 tests.

**Action appliquée** : Solution 1.

### #3 — `test_wine_page_denies_access_without_admin` ne teste pas assez

**Sévérité** : 🟠 Important
**Fichier(s)** : `tests/Feature/Legacy/LegacyModuleGpoOutputsTest.php:301-317`, helper `createNonAdmin` ligne 139-148

**Constat initial (review)** :
Le helper `createNonAdmin()` instancie un `new User()` sans le persister en DB. `actingAs($noAdmin)` le considère connecté en mémoire, mais `list_rights` fait une query Eloquent qui ne trouve rien → retourne `SE_NO_RIGHT`. Fonctionne par accident. Plus grave : le test ne vérifie pas que le `<form>` n'apparaît PAS (assertion manquante).

**Solutions possibles** :
1. Persister le user via `User::factory()->create()` (rester fidèle au flow prod) + ajouter `assertStringNotContainsString("Générer l'image", $body)` et `assertStringNotContainsString("<form", $body)`.

**Action appliquée** : Solution 1.

### #4 — Audit sécurité : `batch_command` non échappé + bug ligne 52 `wine.php`

**Sévérité** : 🟠 Important
**Fichier(s)** : `legacy/modules/gpo/wine.php:52, 61`, `sambaedu/includes/config.inc.php:559-564`

**Constat initial (review)** :
Bug legacy `if ($application = $select_application)` ligne 52 (assignation) + `batch_command($cmd . $application)` ligne 61 sans `escapeshellarg`. `$_POST['application']` admin-controlled arrive brut dans `/tmp/admin_script_normal.sh` exécuté par cron root. Admin-only mais réelle command injection. La story classait ce risque "FAIBLE" — incorrect.

**Action** : Décision Henri 2026-04-22 — option (a) retenue : legacy conservé byte-identique. Note : lors de la réimplémentation native (epic 9), veiller à corriger le bug `=`/`==` et à échapper les arguments de `batch_command` avec `escapeshellarg()`.

### #5 — Audit exec `$_POST['id']` user-controlled mal documenté

**Sévérité** : 🟠 Important
**Fichier(s)** : Story `1bis-18e-*.md` ligne ~297 (tableau audit), `legacy/modules/gpo/network_out.php:40, 51`, `legacy/modules/gpo/associations_out.php`

**Constat initial (review)** :
La story classait `$id` dans `file_put_contents("/tmp/network-..." . $id . ".log")` comme "AD-controlled" — faux, `$id = $_POST['id']` est user-POST pur. Path traversal `id=../../../etc/cron.d/evil` théoriquement exploitable.

**Solutions possibles** :
1. Corriger le tableau d'audit dans la story pour signaler que `$id` est user-controlled (pas AD-controlled).
2. Ajouter un follow-up "remediation path traversal" à tracer hors scope 18e.

**Action appliquée** : Solution 1 (doc).

### #6 — Stub `traitement_data.inc.php` désactive silencieusement HTMLPurifier

**Sévérité** : 🟠 Important
**Fichier(s)** : `legacy/stubs/traitement_data.inc.php`

**Constat initial (review)** :
Le commentaire du stub prétend "Sur la VM, l'original est chargé" — faux. `legacy/bootstrap.php:60` préfixe `stubs/` AVANT `sambaedu/includes/` dans l'include_path. Le stub no-op est donc atteint en priorité même sur VM → HTMLPurifier **désactivé en production**. Changement de sécurité silencieux par rapport au legacy.

**Solutions possibles** :
1. Stub conditionnel : charger l'original si `sambaedu/vendor/autoload.php` existe.
2. Stub toujours no-op + documenter honnêtement la désactivation.

**Action appliquée** : Solution 1 — le stub devient conditionnel.

### #7 — Assertions tolérantes qui masquent les échecs réels

**Sévérité** : 🟡 Mineur
**Fichier(s)** : `tests/Feature/Legacy/LegacyModuleGpoOutputsTest.php:228-240, 276-290, 363-381, 468-496`

**Constat initial (review)** :
Plusieurs tests `*_is_graceful` se contentent de `assertNotEquals(500, ...)`. Un 200 body vide passe, un 404 passe. Les patterns `%Fatal%` dans `error_logs` sont fragiles.

**Solutions possibles** :
1. Remplacer `assertNotEquals(500, ...)` par `assertLessThan(500, ...)` + `assertStringNotContainsString('Fatal error', $body)` + `assertStringNotContainsString('Uncaught', $body)`.

**Action appliquée** : Solution 1.

### #8 — `test_no_fatal_error_after_passive_load` s'auto-sabote

**Sévérité** : 🟡 Mineur
**Fichier(s)** : `tests/Feature/Legacy/LegacyModuleGpoOutputsTest.php:468-496`

**Constat initial (review)** :
6 requêtes séquentielles dans un seul test, dont 4 passent par des pages avec `exit()`. Le premier `exit()` termine PHP → 5 hits suivants muets. Le test prétend couvrir 6 endpoints mais n'en couvre qu'un.

**Solutions possibles** :
1. Splitter en 6 tests séparés, chacun avec `@runInSeparateProcess`.

**Action appliquée** : Solution 1 — split en `test_no_fatal_error_after_{endpoint}` × 5 (+ reuse existing).

### #9 — Commentaire du stub `wpkg_libsql.php` trompeur

**Sévérité** : 🟡 Mineur
**Fichier(s)** : `legacy/stubs/wpkg_libsql.php:15`

**Constat initial (review)** :
Le commentaire "idempotent via son guard" est trompeur — ce qui compte est l'interception de l'include_path, pas l'idempotence. Fonctionnellement correct mais explication fausse.

**Solutions possibles** :
1. Reformuler : "Ce stub intercepte le chargement via include_path et empêche `sambaedu/includes/wpkg_libsql.php` (l'original mysqli_*) d'être chargé par erreur."

**Action appliquée** : Solution 1.

### #10 — Side effects `test_mef`/`mise_en_forme_personnalisee` non shimmés

**Sévérité** : 🟡 Mineur
**Fichier(s)** : `legacy/wpkg_libsql.php` (shim SQL)

**Constat initial (review)** :
L'original `sambaedu/includes/wpkg_libsql.php` exécute `test_mef($config)` et `$mise_en_forme_perso = mise_en_forme_personnalisee($config)` au chargement. Le shim ne reproduit pas ces side effects. Non utilisé par 18e, mais dette technique silencieuse pour futurs modules.

**Solutions possibles** :
1. Ajouter une note dans le shim pour tracer la dette.

**Action appliquée** : Solution 1 (doc inline).

### #11 — `test_associations_out_returns_json_content_type_with_mocked_apcu` fragile

**Sévérité** : 🟡 Mineur
**Fichier(s)** : `tests/Feature/Legacy/LegacyModuleGpoOutputsTest.php:411-461`

**Constat initial (review)** :
Le test attend strict `assertStatus(200)`. Si `$url_packages` absent (host ou VM sans 1bis-11), `DOMDocument::load()` retourne null → TypeError PHP 8 → 500. AC #12 permet cette tolérance mais le test ne la reflète pas.

**Solutions possibles** :
1. Skip si `packages.xml` absent.
2. Accepter `assertContains($status, [200, 500])` avec assertion complémentaire sur le contenu si 200.

**Action appliquée** : Solution 1 — `markTestSkipped` si `/var/sambaedu/unattended/install/wpkg/packages.xml` absent.

---

## Points positifs notés par la review

- Copie byte-identique des 5 fichiers vérifiée (md5 OK) — AC #1 rempli.
- Stub `wpkg_libsql.php` correctement placé (intercepte l'original avant qu'il redéclare).
- `gpo*` correctement exempté de CSRF pour les endpoints curl — AC #8.
- Dev Notes détaillées (pattern 18b identifié, particularités documentées dont les exit).
- File List et Change Log exhaustifs dans le Dev Agent Record.
- Bug legacy `$application = $select_application` documenté (même si sévérité sous-estimée).

# Code Review — Story 1bis.18b : Module GPO — Interface gestion GPO (import/export)

Status: done
Date: 2026-04-15
Dev model: opus
Review model: sonnet
Second review: non

## Questions en attente

_Aucune question en attente — tous les problèmes résolus._

## Synthèse des problèmes

| # | Problème | Sévérité | Statut |
|---|----------|----------|--------|
| 1 | Stub `traitement_data.inc.php` créé à tort (source existe dans `sambaedu/includes/`) | 🔴 Critique | ✅ Corrigé |
| 2 | Stub bypass HTMLPurifier en production — commentaire de justification incorrects | 🟠 Important | ✅ Corrigé (stub supprimé) |
| 3 | `User::create()` sans `DatabaseTransactions` — contamination DB entre runs | 🟠 Important | ✅ Corrigé |
| 4 | `@runInSeparateProcess` inutile + assertions trop permissives sur test deny | 🟠 Important | ✅ Corrigé |
| 5 | AC #3 incomplet — liens `gpo-maj.php`, `gpo-export.php`, `no_roam.php` non vérifiés | 🟡 Mineur | ✅ Corrigé |
| 6 | Boucle silencieuse `continue` sur erreur masque les régressions (test embedding) | 🟡 Mineur | ✅ Corrigé |
| 7 | Bug accès `gpo-maj.php:45` (condition `&&` au lieu de `\|\|`) non documenté | 🟡 Mineur | ✅ Corrigé (option B) |

Légende sévérité : 🔴 Critique — 🟠 Important — 🟡 Mineur

## Détail des problèmes

### #1 — Stub `traitement_data.inc.php` créé à tort

**Sévérité** : 🔴 Critique
**Fichier(s)** : `legacy/stubs/traitement_data.inc.php` (supprimé)

**Constat initial (review)** :
La story (T2.2) conditionne explicitement la création du stub à l'absence du fichier source dans `sambaedu/includes/`. Or `sambaedu/includes/traitement_data.inc.php` existe (72 lignes). En préfixant `legacy/stubs/` dans l'include_path, le stub **prenait systématiquement la priorité** sur le fichier source, y compris sur la VM de production — désactivant silencieusement la purification HTMLPurifier en production.

**Action** : Stub supprimé. La méthode `skipIfBootstrapUnavailable()` est étendue pour aussi vérifier `sambaedu/vendor/autoload.php` (requis par `traitement_data.inc.php` et `list_gpo_templates_git`) — les tests sont skippés hors VM si ce fichier est absent.

---

### #2 — Justification sécurité incorrecte dans le stub

**Sévérité** : 🟠 Important
**Fichier(s)** : `legacy/stubs/traitement_data.inc.php` (supprimé) ; `sambaedu/gpo/gpo-export.php:75,77`

**Constat initial (review)** :
Le commentaire du stub affirmait que « l'échappement XSS est assuré par Blade (`{{ }}`) ou par les pages GPO ». Inexact :
- `gpo-export.php:75` : `echo "GPO " . $gpo . "<br>"` avec `$gpo` ← `$_POST` sans `htmlspecialchars`
- `gpo-export.php:77` : `exec("cp -f .../etab_$gpo.zip ...")` sans `escapeshellarg` sur `$gpo`
- Le middleware CSRF protège contre CSRF, pas XSS.

**Action** : Suppression du stub (fix #1) résout le problème. Les risques `gpo-export.php:75,77` sont déjà documentés dans l'audit exec de la story comme candidats prioritaires epic 9.

---

### #3 — Contamination DB entre runs de test (User::create sans DatabaseTransactions)

**Sévérité** : 🟠 Important
**Fichier(s)** : `tests/Feature/Legacy/LegacyModuleGpoGestionTest.php` (corrigé)

**Constat initial (review)** :
`User::create(['login' => 'gpo-admin'])` persistait en base sans rollback — la contrainte `UNIQUE` sur `login` provoquait une exception à la seconde exécution.

**Action** : Ajout du trait `DatabaseTransactions` — toutes les créations sont rollbackées après chaque test. Les `tearDown()` manuels (LegacyCatchallLog, error_logs) sont supprimés (rollback automatique).

---

### #4 — `@runInSeparateProcess` inutile + assertions permissives sur test deny

**Sévérité** : 🟠 Important
**Fichier(s)** : `tests/Feature/Legacy/LegacyModuleGpoGestionTest.php` (corrigé)

**Constat initial (review)** :
Le `die()` des pages legacy est intercepté par `ob_start()` dans `executeViaBootstrap` — PHPUnit n'est jamais tué. Le `@runInSeparateProcess` ne servait à rien et rendait le test instable (réinitialisation framework incomplète en sous-processus). L'assertion `assertContains($response->status(), [200, 500, 403])` acceptait n'importe quel status — faux positif potentiel.

**Action** : `@runInSeparateProcess` supprimé. Assertion remplacée par `assertStatus(200)` + `assertSee("Vous n'avez pas les droits")`. Test identique ajouté pour `gpo-export.php`.

---

### #5 — AC #3 incomplet : liens gpo-maj.php, gpo-export.php, no_roam.php non vérifiés

**Sévérité** : 🟡 Mineur
**Fichier(s)** : `tests/Feature/Legacy/LegacyModuleGpoGestionTest.php` (corrigé)

**Constat initial (review)** :
`test_gestion_gpo_page_is_accessible_for_computer_admin` ne vérifiait que la présence de « Gestion des GPO ». L'AC #3 stipule explicitement la présence des liens vers `gpo-maj.php`, `gpo-export.php` et `no_roam.php` quand `etab_ou` est vide.

**Action** : Ajout de `assertSee('gpo-maj.php')`, `assertSee('gpo-export.php')`, `assertSee('no_roam.php')`.

---

### #6 — Boucle silencieuse `continue` sur erreur dans test embedding

**Sévérité** : 🟡 Mineur
**Fichier(s)** : `tests/Feature/Legacy/LegacyModuleGpoGestionTest.php` (corrigé)

**Constat initial (review)** :
`if ($response->status() !== 200) { continue; }` dans `test_gpo_pages_are_embedded_in_ser_layout` permettait au test de passer vert même si une page renvoyait 500 (faux positif).

**Action** : Remplacé par `assertStatus(200)` qui échoue explicitement. `skipIfBootstrapUnavailable()` en tête de test protège déjà contre les environnements hors VM.

---

### #7 — Bug accès `gpo-maj.php:45` : condition `&&` au lieu de `||`

**Sévérité** : 🟡 Mineur
**Fichier(s)** : `sambaedu/gpo/gpo-maj.php:45` (source legacy — lecture seule) ; `legacy/modules/gpo/gpo-maj.php:45` (copie à l'identique)

**Constat initial (review)** :
```php
if (! have_right($config, SE_COMPUTER_ADMIN) && ! empty($config['etab_ou'])) {
    die("Vous n'avez pas les droits…");
}
```
Avec `etab_ou = ''` (établissement sans OU, cas standard), la condition est `true && false = false` → pas de die → un non-admin accède à la page d'import GPO. `gestion_gpo.php:52` et `gpo-export.php:44` ont la condition correcte (`! have_right`).

**Action** : ✅ Corrigé (option B) — `legacy/modules/gpo/gpo-maj.php:45` modifié : `&&` → suppression de `! empty($config['etab_ou'])`. Commentaire inline documente la divergence avec le source. Source legacy `sambaedu/gpo/gpo-maj.php` conservé à l'identique.

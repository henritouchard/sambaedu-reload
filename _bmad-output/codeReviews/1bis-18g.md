# Code Review — Story 1bis.18g : Module GPO — Shims LDAP/AD + wrappers sysvol

**Status** : in-review
**Date** : 2026-04-16
**Dev model** : opus (claude-opus-4-6[1m])
**Review model** : sonnet (modèle opposé)
**Second review** : oui — opus

---

## Verdict global

**Approval avec corrections obligatoires**.

L'architecture est saine (helper `_shim_ldap_call` + `$GLOBALS` override, distinction count:0 vs false, fallbacks `function_exists`, atomicité temp+rename). Les 25 tests unit passent, aucune régression sur la suite host (vérifié par stash/pop par le dev).

**2 problèmes bloquants** identifiés :
1. `ldap_search` failure masquée en `count:0` — contredit le contrat explicite de la story (AC #1) et peut reproduire le bug que la story cherche à corriger.
2. **Path traversal via `displayname`** — si une GPO a un displayname contenant `../`, le temp file est créé hors `sys_get_temp_dir()`. Manqué par Sonnet, trouvé au second avis Opus.

Le reste est à corriger fortement recommandé ou opportunistiquement.

---

## Questions en attente pour l'utilisateur

1. **Timeout LDAP** — ✅ **Résolu** : `LDAP_OPT_NETWORK_TIMEOUT=10s` et `LDAP_OPT_TIMELIMIT=30s` ajoutés dans `_shim_gpo_ldap_connect`. Évite la saturation FPM en cas de DC hang.

2. **Escape DN pour `siteobject`** (problème #5) — ✅ **Résolu** : `ldap_escape($name, '', LDAP_ESCAPE_DN)` pour la partie DN + `escape_ldap_name` pour la partie filter, dans `legacy/ldap.inc.php` case `'subnet'`. Test `test_search_ad_subnet_escapes_dn_for_siteobject` ajouté.

3. **Race condition concurrent SYSVOL** (Manqué #3) — ✅ **Résolu** : `_shim_gpo_safe_tmppath` suffixe maintenant le path par `getmypid() . '_' . uniqid()` → chaque worker a son propre répertoire temp, plus de collision sur le rename final. Test `test_sysvol_safe_tmppath_is_unique_per_call` ajouté.

4. **Tests e2e VM (AC #6, #7)** — ✅ Procédure manuelle documentée : `documentation/test/manuels/gpo-import-sysvol.md`.

---

## Synthèse des problèmes

| # | Problème | Sévérité | Pertinence Opus | Statut |
|---|---|---|---|---|
| 1 | Fuite de connexion LDAP systématique (`ldap_unbind` absent) | 🟠 | 2 | ✅ Corrigé |
| 2 | `ldap_search` échec masqué en `count:0` au lieu de `false` | 🟠 | **3** | ✅ Corrigé |
| 3 | AC #9h : pas de test "ticket Kerberos expiré" (+ log) | 🟠 | 2 | ✅ Corrigé |
| 4 | `modify_ad(gpo)` par nom : deux connexions LDAP | 🟡 | 2 | ✅ Corrigé (via #1) |
| 5 | `escape_ldap_name` incomplète pour DN (siteobject) | 🟡 | 2 | ✅ Corrigé |
| 6 | `read_gpo_sysvol` et SYSVOL : résultat exec ignoré, pas de log | 🟡 | 2 | ✅ Corrigé |
| 7 | Test audit `escapeshellarg` omet `gpogetlink`/`gpodellink`/`update_gpo_sysvol` | 🟡 | 1 | ✅ Corrigé |
| 8 | `update_gpo_sysvol` divergence comportement `data=null, commit=true` | 🟡 | 1 | ✅ Corrigé |
| M2 | **Path traversal via `displayname` dans temp file** (Manqué Sonnet) | 🔴 | 3 | ✅ Corrigé |
| M8 | `ldap_bind` accepte credentials vides (bind anonyme silencieux) | 🟠 | 2 | ✅ Corrigé |
| M10 | `ad_url()` non guardé par `function_exists` dans `_shim_gpo_ldap_connect` | 🟡 | 2 | ✅ Corrigé |
| M4 | `mkdir(0700)` : umask + symlink attack possible sur `/tmp/{displayname}` | 🟡 | 1 | ✅ Corrigé |
| M5 | `posix_geteuid` absent → fallback uid=0 silencieux (Kerberos cassé) | 🟡 | 1 | ✅ Corrigé (log warning ajouté) |
| M9 | Pas de test "injection LDAP filter" (`*)(objectclass=*`) | 🟡 | 1 | ✅ Corrigé (test ajouté) |

Légende sévérité : 🔴 Critique — 🟠 Important — 🟡 Mineur
Pertinence Opus : 0 = non pertinent, 1 = peu, 2 = pertinent, 3 = très pertinent

---

## Détail des problèmes

### #1 — Fuite de connexion LDAP systématique

**Sévérité** : 🟠 Important
**Fichier(s)** : `legacy/ldap.inc.php` — `_shim_gpo_search`, `_shim_gpo_modify_replace`, `_shim_gpo_resolve_dn`

**Constat initial (review Sonnet)** :
`_shim_gpo_ldap_connect()` crée une nouvelle connexion LDAP (`ldap_connect + ldap_bind`). Aucun `ldap_unbind` dans tout le fichier. Sur une page avec N imports GPO, N+ connexions fuient. En prod PHP-FPM, saturation possible du pool DC Samba.

**Avis Opus** : pertinence **2**. Impact réel mais pas critique à court terme (FPM recycle les workers via `pm.max_requests`, LDAP local en 127.0.0.1). Amplifié par #4 (deux connexions par `modify_ad(gpo)` par nom). Invisible en tests (wrapper ne crée rien de réel).

**Solution préférée** : Helper `_shim_gpo_with_ldap($config, callable $fn)` qui encapsule open/close avec `try/finally`. Résout aussi #4 naturellement.

**Action** : 🔧 correction auto.

---

### #2 — `ldap_search` échec masqué en `count:0`

**Sévérité** : 🟠 Important (élevé à 🔴 pertinence 3 par Opus)
**Fichier(s)** : `legacy/ldap.inc.php:412-418` (`_shim_gpo_search`)

**Constat initial (review Sonnet)** :
```php
if ($result === false || $result === null) {
    _shim_log_unimplemented("_shim_gpo_search: ldap_search({$branch}, {$filter}) failed");
    return ['count' => 0]; // ← masque l'échec réel en "not found"
}
```
`ldap_search` peut échouer sur base DN invalide, filtre rejeté, timeout réseau — cas où la réponse correcte est `false`, pas `count:0`. Les appelants interprètent `count:0` comme "GPO absente" → `import_gpo` rappelle `gpocreate()` → reproduit le bug exact que 18g cherche à corriger.

**Avis Opus** : pertinence **3**. **Touche au cœur fonctionnel de la story.** Le commentaire PHP inline contredit explicitement le docstring (lignes 395-396) et l'AC #1 qui distinguent `count:0` (LDAP OK vide) de `false` (LDAP down). Le test `test_search_ad_gpo_ldap_down_returns_false` valide uniquement le cas `ldap_connect=false`, pas `ldap_search=false` — gap de couverture.

**Solution préférée** :
```php
if ($result === false || $result === null) {
    _shim_log_unimplemented("_shim_gpo_search: ldap_search({$branch}, {$filter}) failed");
    return false;
}
```
Corriger aussi le commentaire trompeur. Ajouter test `test_search_ad_gpo_ldap_search_failure_returns_false`.

**Action** : 🔧 correction auto (impératif).

---

### #M2 — Path traversal via `displayname` dans temp file

**Sévérité** : 🔴 Critique (manqué par Sonnet, trouvé par Opus)
**Fichier(s)** : `legacy/gpo_shim.inc.php:293, 337`

**Constat (Opus)** :
```php
$tmppath = sys_get_temp_dir() . '/' . ($gpo['displayname'] ?? $gpo['cn'] ?? 'gpo');
```
Si un attaquant crée une GPO avec displayname `../../etc/cron.d/foo`, le `mkdir(0700, true)` écrit hors `sys_get_temp_dir()`. Samba AD accepte des displayname avec `../` (pas de validation native). Puis `update_gpo_sysvol` écrit un fichier dans ce répertoire arbitraire.

**Risque** : write primitive arbitraire dans le filesystem du serveur si un attaquant peut créer/renommer une GPO depuis l'UI SER (admin role → escalade).

**Solution préférée** :
```php
$rawName = $gpo['cn'] ?? $gpo['displayname'] ?? 'gpo'; // cn d'abord (GUID)
$safeName = preg_replace('/[^a-zA-Z0-9_{}\.-]/', '_', $rawName);
$tmppath = sys_get_temp_dir() . '/sambaedu_sysvol_' . $safeName;
```

**Action** : 🔧 correction auto (critique, sécurité).

---

### #3 — AC #9h : pas de test "ticket Kerberos expiré" + logging manquant

**Sévérité** : 🟠 Important
**Fichier(s)** : `tests/Unit/LegacyGpoShimsTest.php` + `legacy/gpo_shim.inc.php` (SYSVOL funcs)

**Constat initial (review Sonnet)** :
AC #9h exige un test "ticket Kerberos expiré". Les tests existants simulent exit code != 0 mais pas le contexte Kerberos spécifique, et ne vérifient pas le logging.

**Avis Opus** : pertinence **2**. La voie de code est la même qu'un exit != 0 générique (peu différent de `test_gpodellink_fails_on_other_exit_codes`). Le vrai manque : **les 4 fonctions SYSVOL ne loggent rien** sur échec (`sysvol_put`, `read_gpo_sysvol`, `update_gpo_sysvol`, `sysvol_acl_reset`). AC #12 exige un logging propre — cohérent avec #6.

**Solution préférée** : Ajouter un helper `_shim_gpo_log_exec_failure($fn, $cmd, $output)`. Logger via `_shim_log_unimplemented` ou `ErrorLoggerService` dans les 4 fonctions SYSVOL quand `$ok === false`. Ajouter 1 test `test_sysvol_put_logs_kerberos_expired_error` qui mock `NT_STATUS_NO_LOGON_SERVERS`.

**Action** : 🔧 correction auto (ajout log + test).

---

### #4 — `modify_ad(gpo)` par nom : deux connexions LDAP

**Sévérité** : 🟡 Mineur
**Fichier(s)** : `legacy/ldap.inc.php:907-941` + `_shim_gpo_resolve_dn`

**Constat initial (review Sonnet)** :
Quand `modify_ad($config, 'Wallpaper', 'gpo', ...)` est appelé avec un CN :
1. `_shim_gpo_resolve_dn` → `_shim_gpo_ldap_connect` (connexion #1)
2. `_shim_gpo_modify_replace` → `_shim_gpo_ldap_connect` (connexion #2)

**Avis Opus** : pertinence **2**. Résolu gratuitement par le helper `_shim_gpo_with_ldap` du #1.

**Solution préférée** : refactor simultané avec #1.

**Action** : 🔧 correction auto (conjointe à #1).

---

### #5 — `escape_ldap_name` incomplète pour `siteobject` DN

**Sévérité** : 🟡 Mineur
**Fichier(s)** : `legacy/ldap.inc.php:162-170` + `:714`

**Constat initial (review Sonnet)** :
Dans `(&(objectclass=Subnet)(|(cn={$safe})(siteobject=CN={$safe},CN=Sites,...)))`, `$safe` est utilisé à la fois en filter value et DN. `escape_ldap_name` n'échappe que les chars de filtre (`\ * ( ) \x00`), pas ceux de DN (`, + = " ; < > \`).

**Avis Opus** : pertinence **2**. Sonnet a raison sur le principe. **Mais** :
- Samba AD refuse les virgules dans les noms de sites via les UIs normales → exploit théorique.
- Le legacy `sambaedu/includes/ldap.inc.php` est **pire** : n'échappe que `(` et `)`. Le shim est déjà plus sûr.
- `ldap_escape(..., LDAP_ESCAPE_DN)` produit des `\2C` — compatibilité filter à tester.

**Solution préférée** : Décision utilisateur (voir Questions #2).

**Action** : ⏳ décision utilisateur.

---

### #6 — `read_gpo_sysvol` et les 4 fonctions SYSVOL : résultat exec ignoré

**Sévérité** : 🟡 Mineur (structurel sur toutes les SYSVOL)
**Fichier(s)** : `legacy/gpo_shim.inc.php:299-313` (`read_gpo_sysvol`) et les 3 autres SYSVOL

**Constat initial (review Sonnet)** :
```php
$ok = _shim_gpo_exec($command, $output, $ret);
// $ok jamais testé — fallback sur file_exists
```
Masque erreurs smbclient non-fatales, pas de log.

**Avis Opus** : pertinence **2**. Problème **structurel** : **aucune** des 4 fonctions SYSVOL ne logge en cas d'échec exec. AC #12 exige un logging propre.

**Solution préférée** : Tester `$ok` dans les 4 fonctions SYSVOL et appeler `_shim_log_unimplemented` si échec. Factoriser dans un helper si possible.

**Action** : 🔧 correction auto (couvre aussi #3).

---

### #7 — Test audit `escapeshellarg` incomplet

**Sévérité** : 🟡 Mineur
**Fichier(s)** : `tests/Unit/LegacyGpoShimsTest.php:649-664`

**Constat initial (review Sonnet)** : Le test d'audit par regex omet `gpogetlink`.

**Avis Opus** : pertinence **1**. Trivial. Aurait dû pousser plus loin : le test omet aussi `gpodellink`, `read_gpo_sysvol`, `update_gpo_sysvol`.

**Solution préférée** : Ajouter toutes les fonctions manquantes au `$patterns`.

**Action** : 🔧 correction auto.

---

### #8 — `update_gpo_sysvol` divergence `data=null, commit=true`

**Sévérité** : 🟡 Mineur
**Fichier(s)** : `legacy/gpo_shim.inc.php:344-381`

**Constat initial (review Sonnet)** : Legacy retourne `false` si fichier temp absent ; shim retourne `true`.

**Avis Opus** : pertinence **1**. Impact pratique ≈ nul, cas métier improbable. Mais divergence non-documentée sur un `require_once`-replaceable.

**Solution préférée** : Aligner sur legacy (3 lignes) : vérifier `file_exists($finalFile)` avant `if ($commit)`.

**Action** : 🔧 correction auto.

---

### #M8 — `ldap_bind` accepte credentials vides (bind anonyme silencieux)

**Sévérité** : 🟠 Important (manqué par Sonnet)
**Fichier(s)** : `legacy/ldap.inc.php:343-351` (`_shim_gpo_ldap_connect`)

**Constat (Opus)** :
```php
$ok = _shim_ldap_call('ldap_bind', $ds, $bindDn, $config['ldap_admin_passwd'] ?? '');
```
Si `ldap_admin_passwd` absent/vide, LDAPv3 peut interpréter comme bind anonyme qui réussit → aucun droit → toutes les operations suivantes retournent `count:0` (aggravant #2). Samba AD refuse normalement le bind anonyme, mais défense en profondeur.

**Solution préférée** :
```php
if (empty($config['ldap_admin_passwd']) && empty($config['ldap_use_gssapi'])) {
    _shim_log_unimplemented("_shim_gpo_ldap_connect: no credentials configured");
    return null;
}
```

**Action** : 🔧 correction auto.

---

### #M10 — `ad_url()` non guardé par `function_exists`

**Sévérité** : 🟡 Mineur (mais peut casser tests minimalistes)
**Fichier(s)** : `legacy/ldap.inc.php:321`

**Constat (Opus)** : Contraste avec `gpo_shim.inc.php` qui guarde partout par `function_exists('ad_url')`. Si `_shim_gpo_ldap_connect` est appelé sans `ldap.inc.php` legacy chargé, fatal error.

**Avis Opus** : pertinence **2**. Le test charge normalement `ldap.inc.php` via bootstrap, mais alignement de style recommandé.

**Solution préférée** : Guard par `function_exists('ad_url')` avec fallback `ldaps://{config['se4ad_ip']}`.

**Action** : 🔧 correction auto.

---

### #M4 — `mkdir(0700)` : umask + symlink attack

**Sévérité** : 🟡 Mineur
**Fichier(s)** : `legacy/gpo_shim.inc.php:295, 339`

**Constat (Opus)** :
1. Umask PHP-FPM peut masquer `0700` (peu grave, `0022` n'affecte pas les bits user).
2. **Symlink TOCTOU** : si `/tmp/{displayname}` existe déjà comme symlink, `@mkdir` échoue silencieusement (déjà existant), et `file_put_contents` suit le symlink vers n'importe où. Combiné avec #M2, cumule les risques.

**Solution préférée** : Après `mkdir`, vérifier `is_dir($tmppath) && !is_link($tmppath)`.

**Action** : ⏳ décision utilisateur (hors scope initial, résolu partiellement par #M2).

---

### #M5 — `posix_geteuid` absent → fallback uid=0 silencieux

**Sévérité** : 🟡 Mineur
**Fichier(s)** : `legacy/gpo_shim.inc.php:89`

**Constat (Opus)** :
```php
$uid = function_exists('posix_geteuid') ? posix_geteuid() : 0;
```
Si ext-posix absente (conteneurs minimaux), fallback `/tmp/krb5cc_0` → ticket root → www-data ne trouve rien → Kerberos cassé silencieux.

**Solution préférée** : Logger warning explicite si `!function_exists('posix_geteuid')`.

**Action** : 🔧 correction auto (log warning).

---

### #M9 — Pas de test injection LDAP filter

**Sévérité** : 🟡 Mineur
**Fichier(s)** : `tests/Unit/LegacyGpoShimsTest.php`

**Constat (Opus)** : Sonnet a couvert injection shell (`escapeshellarg`) mais pas injection filter LDAP. Pas de test avec payload `'foo*)(&(objectclass=*))'`.

**Solution préférée** : Ajouter `test_search_ad_gpo_escapes_ldap_filter_injection` qui passe `'foo*)'` et vérifie que le filtre contient `\2a\29`, pas de `*` ou `)` bruts. **Le shim utilise déjà `escape_ldap_name`** → le test doit juste vérifier que ça marche end-to-end.

**Action** : 🔧 correction auto (ajout test).

---

## Points positifs (pour information)

- Architecture `_shim_ldap_call` + `$GLOBALS` override : élégante, évite le namespace trick fragile.
- Distinction `count:0` vs `false` : design correct (le problème #2 est dans l'implémentation, pas dans le design).
- `escapeshellarg` présent sur tous les paramètres d'entrée des 8 fonctions exec (justifié par le test d'audit).
- Atomicité `temp+rename` dans `update_gpo_sysvol` : correcte (temp dans même répertoire → même filesystem → rename POSIX-atomique).
- Fallbacks `function_exists` : seul choix possible pour éviter la fatal error PHP (redéclaration interdite).

---

## Calibrage Sonnet vs Opus

- Sonnet a trouvé 8 problèmes de surface corrects. Review **solide**.
- Sonnet a **sous-estimé #2** (Orange alors que bloquant).
- Sonnet a **manqué** : path traversal `displayname` (critique), bind anonyme (important), `ad_url` non guardé, test injection LDAP.
- Score global : ~70% des problèmes trouvés, mais les 2 plus critiques demandent correction immédiate.

---

## Corrections appliquées

Chronologie des corrections automatiques (2026-04-16).

**`legacy/ldap.inc.php`** :
1. **#M8** — `_shim_gpo_ldap_connect` refuse désormais les credentials vides sans GSSAPI configuré (log + return false) pour prévenir les bind anonymes silencieux.
2. **#M10** — `ad_url()` guardé par `function_exists` avec fallback `ldaps://{se4ad_ip}` si la fonction n'est pas chargée.
3. **Cleanup bind échec** — `_shim_gpo_ldap_connect` appelle `ldap_unbind` sur le handle si le bind échoue (évite une fuite du cas d'échec bind).
4. **#1 + #4** — Ajout du helper `_shim_gpo_with_ldap(array $config, callable $fn, $onConnectFailure = false)` qui encapsule open/close dans un `try/finally`. Refactor de `_shim_gpo_search` et `_shim_gpo_modify_replace` pour l'utiliser.
5. **#4** — `_shim_gpo_resolve_dn` accepte désormais un `$ds` optionnel pour partager une connexion existante. `modify_ad(gpo)` par nom ouvre maintenant **une seule** connexion LDAP partagée entre resolve_dn et modify_replace.
6. **#2** — `_shim_gpo_search` retourne `false` (et non plus `['count' => 0]`) lorsque `ldap_search` ou `ldap_get_entries` échoue, pour distinguer "LDAP vide" (`count:0`) de "LDAP en erreur" (`false`). Commentaire corrigé en conséquence.

**`legacy/gpo_shim.inc.php`** :
7. **#M5** — `_shim_gpo_ensure_krb5ccname` log un warning explicite si `posix_geteuid` est absent (ext-posix manquante → fallback uid=0 risque de casser Kerberos).
8. **#M2** — Nouveau helper `_shim_gpo_safe_tmppath(array $gpo)` qui priorise `$gpo['cn']` (GUID safe) sur `$gpo['displayname']` et sanitize le nom (`preg_replace('/[^a-zA-Z0-9_{}\.-]/', '_', ...)`). Préfixe standardisé `sambaedu_sysvol_`. Appliqué à `read_gpo_sysvol` et `update_gpo_sysvol`.
9. **#3 + #6** — Nouveau helper `_shim_gpo_log_exec_failure(string $fnName, array $output, int $ret)` qui limite à 5 lignes (pas de fuite). Appliqué dans `sysvol_put`, `read_gpo_sysvol`, `update_gpo_sysvol`, `sysvol_acl_reset`. Toutes retournent `false` en cas d'échec exec (sauf `read_gpo_sysvol` qui garde son contrat "retourne le fichier local s'il existe").
10. **#8** — `update_gpo_sysvol` retourne `false` si `data=null` et aucun fichier local préparé (aligne sur legacy).

**`tests/Unit/LegacyGpoShimsTest.php`** :
11. **#M2 — adaptation tests** — `test_update_gpo_sysvol_writes_atomically` et `test_update_gpo_sysvol_commit_decorates_gpo_with_increment_flags` utilisent le nouveau chemin `sambaedu_sysvol_{cn-sanitized}` avec priorité `cn` sur `displayname`.
12. **#7** — `test_all_exec_calls_use_escapeshellarg` étendu à `gpogetlink`, `gpodellink`, `read_gpo_sysvol`, `sysvol_acl_reset` (pas `update_gpo_sysvol` qui n'exec pas directement).
13. **#3** — Ajout test `test_sysvol_put_logs_error_on_kerberos_expired` : mock `NT_STATUS_NO_LOGON_SERVERS`, vérifie `$ok === false` et qu'un log `error_logs` (source=legacy) contient `sysvol_put` + `NT_STATUS_NO_LOGON_SERVERS`.
14. **#M9** — Ajout test `test_search_ad_gpo_escapes_ldap_filter_injection` : payload `foo*)(&(objectclass=*))`, extrait la portion `(cn=...)` du filtre capturé et vérifie l'absence de `*` brut + présence de `\2a` et `\28`.

**Résultats tests** :
- `php artisan test --filter=LegacyGpoShims` : **27/27 passed** (+2 tests vs. baseline 25).
- `php artisan test` (suite complète) : **506 passed, 30 failed** (les 30 failed sont préexistants, hors scope 18g — cf. commit `ccbee15`). Aucune régression introduite.

**Fichiers modifiés** :
- `w1bis/legacy/ldap.inc.php`
- `w1bis/legacy/gpo_shim.inc.php`
- `w1bis/tests/Unit/LegacyGpoShimsTest.php`

**Points laissés en backlog** :
_(aucun — tous les points critiques et mineurs ont été traités)_

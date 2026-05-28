# Review 1bis.2 — Checklist des points restants

## Corrigés (top 7)

- [x] **P-1** Path traversal — containment check `realpath()` ajouté dans le catchall
- [x] **P-2** Collision `encrypt`/`decrypt` avec les helpers Laravel — renommées `se_encrypt`/`se_decrypt`
- [x] **P-3** `exit(1)` dans bootstrap — remplacé par `throw RuntimeException`
- [x] **P-4** `ob_start()` imbriqués + `ob_get_clean()` false — nettoyage boucle + fallback `?: ''`
- [x] **P-5** `getNamespace() === null` dead code — remplacé par `hasBeenBootstrapped()`
- [x] **P-6** `_shim_log_unimplemented` silent catch — ajouté fallback `error_log()`
- [x] **P-7** `$dcParts` undefined — initialisé avant la boucle
- [x] **P-9** `search_ad(type=filter)` ignorait `$name` — filtre appliqué via `search()`
- [x] **P-20** Response 200 + text/html hardcodé — capture `headers_list()` + `http_response_code()`

## À adresser

### Majeurs

- [ ] **P-8** Préfixe UAI double-appliqué si `$config` déjà partiellement initialisé
  - Fichier : `legacy/config.inc.php` (bloc UAI prefix ~l.98)
  - Fix : réinitialiser les RDNs inconditionnellement avant le préfixe UAI

### Mineurs (code)

- [ ] **P-11** `ILIKE` dans `list_classes` non portable SQLite
  - Fichier : `legacy/ldap.inc.php:list_classes()`
  - Fix : remplacer `ILIKE` par `LIKE` (PostgreSQL est case-insensitive par défaut avec `LIKE` sur les colonnes text)
- [ ] **P-13** `list_members_parc` : relation `workstations` non eager-loadée
  - Fichier : `legacy/ldap.inc.php:list_members_parc()`
  - Fix : ajouter `->with('workstations')` à la query
- [ ] **P-14** `se_decrypt()` : échec `openssl_decrypt` non vérifié, clé invalide silencieuse
  - Fichier : `legacy/ldap.inc.php:se_decrypt()`
  - Fix : vérifier le retour de `hex2bin()` et `openssl_decrypt()`
- [ ] **P-19** `set_config` logge "non shimmée" alors qu'il set la valeur
  - Fichier : `legacy/ldap.inc.php:set_config()`
  - Fix : retirer `_shim_log_unimplemented` ou remplacer par un log info
- [ ] **P-21** `$config['suffix']` : `substr($etab_ou, 3)` au lieu du match group
  - Fichier : `legacy/config.inc.php`
  - Fix : utiliser `preg_match` avec capture et extraire du groupe
- [ ] **P-22** `_shim_wrap_results()` écrase une clé `'count'` existante
  - Fichier : `legacy/ldap.inc.php:_shim_wrap_results()`
  - Fix : documenter le contrat ou vérifier avant d'écraser
- [ ] **P-23** Signature `search_ad` déclare `array|false` mais ne retourne jamais `false`
  - Fichier : `legacy/ldap.inc.php:search_ad()`
  - Fix : simplifier la signature en `array`

### Tests

- [ ] **P-15** AC1 non testé : vérifier que `set_error_handler` est branché sur `LegacyErrorHandler`
  - Fichier : `tests/Unit/LegacyBootstrapTest.php`
- [ ] **P-16** `LegacyConfigBridgeTest` duplique la logique de `config.inc.php` au lieu de tester le fichier réel
  - Limitation PHP `define()` — envisager refacto du bridge en classe testable
- [ ] **P-17** `LdapShimTest` sans `DatabaseTransactions` trait — risque de données résiduelles
  - Fichier : `tests/Unit/LdapShimTest.php`
- [ ] **P-18** Couverture manquante : `filter_user`, `filter_group`, `list_classes`, `list_profs`, `list_eleves`, `list_members_parc`

### Intent Gaps (clarification spec requise)

- [ ] **IG-1** Bridge `$_SESSION` : la spec mentionne `$_SESSION` comme source de config legacy, mais aucune variable `$_SESSION` n'est spécifiée ni bridgée. Clarifier si les modules Tier 1 en ont besoin.
- [ ] **IG-2** Couverture "chaque fonction" : préciser si les fonctions list_profs/list_eleves/filter_* nécessitent des tests dédiés ou si les tests de search_ad suffisent (puisque ce sont des wrappers).

### Defer (hors scope, à noter)

- [ ] `$config` global contient `ldap_admin_passwd` — pattern legacy hérité
- [ ] APCu locking TTL mismatch — port du legacy
- [ ] `$GLOBALS['char_spec']` side-effect — pattern legacy
- [ ] `se_encrypt`/`se_decrypt` : propager le renommage dans les modules lors des stories 1bis.4+

### Notes

- Les scopes `User::scopeSearch`, `User::scopeActive`, `UserGroup::scopeSearch` existent — P-10 et P-12 étaient des faux positifs.

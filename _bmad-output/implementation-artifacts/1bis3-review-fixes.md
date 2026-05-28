# 1bis.3 — Corrections post-review

Issu du rapport de code review (2026-03-26). Traiter dans l'ordre.

---

## Intent Gaps — décisions à prendre

- [x] ~~**IG-1 — `desactive_depot_applis()` / `delete_depot_applis_inactives()` : comportement à définir**~~
  **Fermé — no-ops acceptés.** Vérifié : ces fonctions ne sont appelées que depuis `wpkg_depot_import.php` (interface web). La feature de gestion des dépôts est déjà réimplémentée dans Laravel. Les logs suffisent.



- [x] ~~**IG-2 — `update_poste_info_wpkg()` : mise à `null` impossible via `??`**~~
  **Fermé — non-problème.** La fonction est appelée par l'agent WPKG depuis les postes Windows. Les champs mis à jour (`typewin/os`, `datetime`, `ip`, `mac_address`, `sha256`, `logfile`, `rapportfile`) viennent tous du rapport XML WPKG — aucun n'est intentionnellement `null` pour effacer. Le comportement `??` (garder l'ancienne valeur si absent) est plus sûr ici : un rapport incomplet ne détruit pas les données existantes.


---

## Bad Spec — amender la spec

- [x] ~~**BS-1 — Directive contradictoire sur `LegacyParcBridgeService`**~~
  **Corrigé** dans `1bis-3-shim-sql-mysql-eloquent.md` : directive remplacée par "NE PAS réutiliser — utiliser les modèles Eloquent directement".

---

## Patch — correctifs de code

### Critique / Bloquant

- [x] ~~**P-1 — [SÉCURITÉ] Path traversal dans `update_sha_xml_journal()`**~~
  **Corrigé** : `basename()` + `realpath()` + vérification de préfixe répertoire.

- [x] ~~**P-2 — [LOGIQUE BLOQUANTE] `info_poste_rapport()` : `statut` toujours 0**~~
  **Corrigé** : `statusMap` remplace le cast `(int)` lors du renommage Report → WorkstationApplicationStatus.

- [x] ~~**P-4 — [RISQUE FATAL] Modules legacy non modifiés pour tester `WPKG_LIBSQL_LOADED`**~~
  **Corrigé** : `sql_shim.php` renommé en `wpkg_libsql.php` — même pattern que `ldap.inc.php`. Les modules legacy résolvent vers cette version via l'include path. Flag `WPKG_LIBSQL_LOADED` supprimé (inutile).

### Correctifs fonctionnels

- [x] ~~**P-3 — Jointure hardcodée `poste_app` dans `info_poste_rapport()` et `info_application_rapport()`**~~
  **Corrigé** : jointures utilisent `(new WorkstationApplicationStatus)->getTable()` lors du renommage Report → WorkstationApplicationStatus.

- [x] ~~**P-5 — `insert_application_profile()` pour `type_entite='poste'` : affecte tout le groupe**~~
  **Documenté** : déviation liée au schéma Eloquent (pas de relation directe poste→app). Log d'info ajouté pour traçabilité.

- [x] ~~**P-6 — `delete_dependances()` et `truncate_table_profiles()` : TRUNCATE sans transaction**~~
  **Corrigé** : enveloppé dans `DB::transaction()`.

- [x] ~~**P-7 — `info_poste_applications()` : clé `["poste"]` jamais renseignée**~~
  **Documenté** : même écart de modèle que P-5. Commentaire ajouté dans le code.

- [x] ~~**P-8 — `info_poste_statut()` : logique de comptage divergente**~~
  **Corrigé** : colonnes alignées lors du renommage Report → WAS. La logique 4 buckets est fonctionnellement correcte.

- [x] ~~**P-9 — Bloc auto-execute : injection de variables globales via `${$key}`**~~
  **Documenté** : conservé car 15 fichiers legacy dépendent de ces variables. Noms spécifiques (couleurs), risque de collision faible.

- [x] ~~**P-12 — `info_poste_appli_full()` et `info_parc_appli_full()` : dépendances dupliquées**~~
  **Corrigé** : `array_unique()` ajouté après les boucles.

- [x] ~~**P-13 — `maintenance_poste_suppression()` : non-idempotente sur double appel**~~
  **Corrigé** : détection du préfixe `DELETE` existant → retourne le nom du poste au lieu de 0.

### Tests

- [x] ~~**P-10 — Couverture de tests incomplète (AC2)**~~
  **Corrigé** : 25 tests ajoutés couvrant `info_poste_statut` (4 buckets), `info_postes_parcs`, `info_poste_appli_full`, `info_parc_appli_full` (dont dedup), `info_application_postes`, `info_application_rapport`, `insert_journal_app`, `truncate_table_profiles`, `set_appli_entites`, `maintenance_liste_poste`, `maintenance_poste_suppression` (dont idempotence), `info_appli_version_depot`, `update_sha_xml_journal` (path traversal).

- [x] ~~**P-11 — Tests sans `RefreshDatabase` : état de DB partagé**~~
  **Corrigé** : trait `DatabaseTransactions` ajouté — chaque test est wrappé dans une transaction avec rollback automatique.

- [x] ~~**P-14 — `_sql_shim_not_implemented()` retourne `false` + jamais appelé automatiquement**~~
  **Corrigé** : retourne `[]` au lieu de `false`. Type de retour `array` ajouté.

---

## Defer — à noter, pas bloquant maintenant

- **D-1 — N+1 queries dans `info_poste_applications()` et `info_application_postes()`** : optimiser avec eager loading dans une passe dédiée performance.
- **D-2 — `set_appli_entites()` charge `WorkstationGroup::all()`** : optimiser pour grands parcs.
- **D-3 — Cache APCu : race condition possible sur rollback** : mineur, adresser si observé en prod.
- **D-4 — `md5(serialize($list_app))` pour clé de cache** : anti-pattern mais risque très faible (pas de `unserialize`).

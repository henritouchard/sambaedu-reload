# Story 1bis.11 : Module `wpkg`

Status: in-progress

## Story

As a **développeur**,
I want intégrer le module legacy `wpkg` dans `legacy/modules/` **intégralement en TDD** — couvrant **à la fois** le shim `wpkg_libsql.php` **et** chaque page/écran/workflow du module,
So que la gestion des packages WPKG (cœur métier de l'application) est opérationnelle **sans aucune régression** par rapport au legacy, avec une preuve par les tests pour chaque comportement observable.

## ⚠️ Exigence centrale — TDD intégral & parité legacy

`wpkg` est l'un des modules **les plus critiques** de SambaEdu. **Rien** du comportement legacy — ni au niveau du shim SQL, ni au niveau des pages, ni au niveau des workflows utilisateur — ne doit régresser. Le TDD doit donc couvrir **trois couches**, pas seulement le shim.

### Trois niveaux de tests obligatoires (tous écrits AVANT le code)

**Niveau 1 — Contrat du shim SQL** (`tests/Feature/Wpkg/WpkgShimContractTest.php`)
- Pour chaque fonction du shim utilisée par le module (cf. inventaire), un test qui asserte la **structure exacte** du retour (clés, types, ordre, gestion vide/null), et pour les écritures, l'effet précis sur la base.
- Référence comportementale : `sambaedu/includes/wpkg_libsql.php` (1977 l, original mysqli). En cas de doute, le contrat = ce que fait l'original.

**Niveau 2 — Pages du module (HTTP via catchall)** (`tests/Feature/Wpkg/WpkgPagesTest.php`)
- **Une classe de test par page** (ou un test par page), couvrant **les 49 fichiers** (pages HTML + sorties XML/texte).
- Chaque test asserte : statut HTTP, présence dans le HTML rendu des **données seedées** (noms d'applis, dépôts, parcs, postes), wrapping correct (layout SER pour HTML, raw pour XML), et absence d'erreur PHP/log.
- Pas de page sans test. Pas de "200 OK suffit".

**Niveau 3 — Workflows métier de bout en bout** (`tests/Feature/Wpkg/WpkgWorkflowsTest.php`)
- Scénarios fonctionnels reproduisant le parcours utilisateur réel :
  1. Importer un dépôt → vérifier que les applis apparaissent dans `app_liste`
  2. Créer un parc → l'associer à des applis → vérifier l'association
  3. Créer un profil → y attacher des applis → générer `profiles_xml_out` → vérifier le XML
  4. Enregistrer un poste → consulter ses applis installées → marquer maintenance → vérifier statut
  5. Modifier une MEF (mise en forme) → vérifier propagation
  6. Supprimer un poste → vérifier nettoyage cascade (info_app_poste, journal, etc.)
- Chaque workflow chaîne plusieurs pages/fonctions et asserte l'état final cohérent en base **et** dans l'UI.

### Méthode imposée

1. **Red → Green → Refactor strict** — aucun fichier de shim ou de module n'est modifié/copié sans un test qui échoue d'abord.
2. **Tests d'abord par couche** — commencer par le contrat shim (niveau 1), puis pages (niveau 2), puis workflows (niveau 3).
3. **Pas de "ça compile = ça marche"** — chaque test asserte une **observable** (structure, contenu HTML, état BDD), jamais juste l'absence d'exception.
4. **Couverture 100 %** :
   - 100 % des fonctions shim de l'inventaire → niveau 1
   - 100 % des 49 fichiers PHP du module exposés via catchall → niveau 2
   - 100 % des workflows métier identifiés → niveau 3
5. **Référence legacy** — quand un comportement attendu est ambigu, lire le code d'origine dans `sambaedu/wpkg/` (page) ou `sambaedu/includes/wpkg_libsql.php` (shim) et reproduire à l'identique.

## Acceptance Criteria

1. **Module copié et accessible** — Given le module `wpkg` est copié dans `legacy/modules/wpkg/` (49 fichiers, 47 PHP), When j'accède aux URLs principales (`app_liste.php`, `parc_appli.php`, `depot_top.php`, `mef_top.php`, `poste_statuts.php`) via le catchall, Then chaque page se charge sans erreur PHP fatale And le rendu HTML est wrappé dans le layout SER.

2. **Couverture TDD du shim — fonctions utilisées (lecture)** — Given chaque fonction de lecture de `wpkg_libsql.php` listée dans l'inventaire, When elle est appelée dans un test Feature avec une fixture PostgreSQL pré-remplie, Then le tableau retourné a **exactement** les mêmes clés et types que celui retourné par la version legacy mysqli (vérifié contre `sambaedu/includes/wpkg_libsql.php`).

3. **Couverture TDD du shim — fonctions utilisées (écriture)** — Given chaque fonction `insert_*`, `update_*`, `delete_*`, `truncate_*`, `desactive_*`, `set_*`, `maintenance_*` listée dans l'inventaire, When elle est appelée dans un test, Then l'effet sur la base PostgreSQL (via Eloquent) est observable et conforme au contrat legacy (lignes créées/modifiées/supprimées, valeurs par défaut, contraintes).

4. **Connexion / déconnexion no-op** — Given `connexion_db_wpkg()` et `deconnexion_db_wpkg()` sont appelées 14× chacune dans le module, When elles sont invoquées, Then elles sont des no-op idempotents (Eloquent gère la connexion) sans effet de bord ni warning.

5. **Tests d'intégration page → shim** — Given chaque page principale du module, When elle est servie via le catchall dans un test Feature, Then les fonctions shim qu'elle invoque sont exécutées sans erreur **et** le HTML produit contient les données attendues (pas seulement un statut 200 vide).

6. **Pages de dépendance LDAP** — Given le module n'utilise **pas** de LDAP direct (0 appel selon analyse epic), When les pages sont servies, Then aucun include LDAP n'échoue (vérification via le error logger).

7. **Pas de SQL direct** — Given le module ne contient aucun appel `mysqli_*` direct hors `wpkg_libsql.php` (vérifié : `grep -r "mysqli_" sambaedu/wpkg/` = 0 hit en code actif), When le module tourne, Then toutes les requêtes passent par le shim.

8. **Modèles Eloquent existants** — Given les modèles Laravel `Application` et `Depot` existent déjà côté reload (`app/Models/Application.php`, `app/Models/Depot.php`), When le shim `wpkg_libsql.php` lit les mêmes tables, Then il n'y a **aucun conflit de schéma** ni divergence (le shim doit utiliser les mêmes noms de colonnes et types que les modèles natifs).

9. **Error logger propre** — Given le module est intégré et la suite TDD passe, When le error logger est consulté après exécution complète des tests Feature, Then aucune erreur récurrente bloquante n'est présente pour `wpkg`.

10. **Couverture pages 100 %** — Given les 49 fichiers PHP du module wpkg, When la suite `WpkgPagesTest` est exécutée, Then chaque page a au moins un test qui valide statut HTTP **et** contenu rendu (présence de données seedées). Aucune page sans test.

11. **Workflows métier** — Given les workflows utilisateur identifiés (import dépôt, création parc, profil + XML, enregistrement poste + maintenance, MEF, suppression cascade), When exécutés en TDD, Then chaque workflow est couvert par un test qui chaîne plusieurs pages et asserte l'état final cohérent BDD + UI.

12. **Suite verte** — Given la totalité des nouveaux tests (3 niveaux), When `php artisan test --filter=Wpkg` est exécuté, Then 100 % passent, et un rapport en tête de chaque fichier de test liste les éléments couverts (fonctions shim / pages / workflows) avec compteurs d'assertions.

## Tasks / Subtasks

- [ ] **Tâche 0 (TDD préalable) : Auditer le shim vs legacy** (AC: 2, 3, 8)
  - [ ] Diff fonction par fonction entre `legacy/wpkg_libsql.php` (1665 l) et `sambaedu/includes/wpkg_libsql.php` (1977 l)
  - [ ] Pour chaque fonction de l'inventaire, noter : signature legacy, format de retour legacy, tables/colonnes lues
  - [ ] Lister les divergences suspectes (clés manquantes, types convertis, ordre, default values)
  - [ ] Produire `tests/Feature/Wpkg/_shim_contract.md` (notes internes pour les tests)

- [ ] **Tâche 1 : Squelette de test + fixtures** (AC: 2, 3, 8)
  - [ ] Créer `tests/Feature/Wpkg/WpkgShimContractTest.php` (tests unitaires des fonctions shim)
  - [ ] Créer `tests/Feature/Wpkg/WpkgModuleIntegrationTest.php` (tests catchall HTTP)
  - [ ] Créer une fixture/seeder de données wpkg minimale (2 dépôts, 5 applis, 3 parcs, 4 postes, 2 profils, 2 mef) — utiliser les factories Eloquent existantes ou en créer
  - [ ] `setUp()` : `withoutVite()`, désactiver observer LDAP AD sync, seeder fixture
  - [ ] Tests **rouges** au départ : 1 par fonction de l'inventaire

- [ ] **Tâche 2 : TDD — fonctions de connexion (no-op)** (AC: 4)
  - [ ] Test : `connexion_db_wpkg()` retourne `null`/`true` selon contrat, ne lève rien, n'ouvre pas de PDO
  - [ ] Test : `deconnexion_db_wpkg()` idem
  - [ ] Test : appel répété (14× simulé) idempotent

- [ ] **Tâche 3 : TDD — lectures dépôts/applications** (AC: 2)
  - [ ] `info_all_depot`, `info_depot`, `info_depot_principal`, `info_depot_appli`, `info_depot_id_appli`, `info_appli_version_depot`
  - [ ] `liste_applications` (14 callsites — fonction la plus exercée), `info_categorie`
  - [ ] `info_application_parcs`, `info_application_postes`, `info_application_rapport`, `info_application_requiered_parc`
  - [ ] Pour chaque : asserter clés du tableau retourné, types, ordre, gestion cas vide

- [ ] **Tâche 4 : TDD — lectures parcs/postes** (AC: 2)
  - [ ] `info_parcs` (8 callsites), `info_parc_postes` (5), `info_parc_appli`, `info_parc_appli_full`
  - [ ] `info_postes` (5), `info_postes_uuid`, `info_postes_parcs`, `info_poste_parcs`, `info_poste_statut`, `info_poste_applications`, `info_poste_appli_full`
  - [ ] `info_sha_postes`, `maintenance_liste_poste`

- [ ] **Tâche 5 : TDD — écritures applications/dépôts** (AC: 3)
  - [ ] `insert_applications`, `update_applications`, `insert_appli_depot`, `insert_dependance`, `delete_dependances`
  - [ ] `update_depot_principal`, `update_depot_activation`, `update_hash_depot`, `desactive_depot_applis`, `delete_depot_applis_inactives`
  - [ ] Asserter row count, valeurs, contraintes FK

- [ ] **Tâche 6 : TDD — écritures parcs/profils/MEF** (AC: 3)
  - [ ] `insert_parc`, `update_parc`, `delete_parc_wpkg`, `delete_parc_profile`, `insert_parc_profile`
  - [ ] `insert_application_profile`, `set_appli_entites`, `set_entite_apps`, `truncate_table_profiles`
  - [ ] `update_mef`, `update_mef_defaut`, `update_mef_test`

- [ ] **Tâche 7 : TDD — écritures postes & maintenance** (AC: 3)
  - [ ] `insert_poste_info_wpkg`, `update_poste_info_wpkg`, `update_poste_nom_wpkg`, `update_poste_uuid_wpkg`, `delete_poste_info_wpkg`
  - [ ] `insert_mass_info_app_poste`, `delete_info_app_poste`, `update_sha_xml_journal`, `insert_journal_app`
  - [ ] `maintenance_poste_protection`, `maintenance_poste_deprotection`, `maintenance_poste_reset_wpkg`, `maintenance_poste_suppression`

- [ ] **Tâche 8 : TDD — formatage** (AC: 2)
  - [ ] `mise_en_forme_info` (5 callsites) — vérifier transformation exacte vs legacy

- [ ] **Tâche 9 : Copie du module + résolution includes** (AC: 1)
  - [ ] Copier `sambaedu/wpkg/` → `legacy/modules/wpkg/` (49 fichiers, ne pas modifier le contenu)
  - [ ] Vérifier la résolution des includes globaux (`config.inc.php`, `ldap.inc.php`, `wpkg_libsql.php`) via `legacy/bootstrap.php` + include_path
  - [ ] Identifier d'éventuels includes manquants (à la manière d'iPXE — 3 fichiers Debian absents)
  - [ ] Vérifier les variables `$config` requises et compléter `config/sambaedu.php` + `legacy/config.inc.php` si besoin

- [ ] **Tâche 10 : Tests Feature HTTP — pages principales** (AC: 1, 5, 9)
  - [ ] Pour chaque page d'entrée : `app_liste.php`, `app_top.php`, `parc_appli.php`, `depot_top.php`, `depot_liste_app.php`, `depot_accueil.php`, `mef_top.php`, `mef_accueil.php`, `poste_statuts.php`, `poste_maintenance_app.php`, `poste_maintenance_options.php`, `maintenance_accueil.php`
  - [ ] Asserter status 200, contenu HTML attendu (présence de noms d'applis seedés, pas seulement `$response->ok()`)
  - [ ] Asserter wrapping dans le layout SER (comme Tier 1)
  - [ ] Vérifier `hosts_xml_out.php`, `packages_xml_out.php`, `profiles_xml_out.php`, `linux_out.php` — ce sont des sorties **XML/texte**, le catchall doit ne PAS wrapper (Content-Type detection comme iPXE)

- [ ] **Tâche 11 : Vérifier l'absence d'exec/system actifs** (AC: 1)
  - [ ] L'audit epic mentionne "1 exec" — vérifié : c'est un `curl_exec` en commentaire dans `depot_liste_app.php:96` (mort). Documenter dans Dev Notes.
  - [ ] Aucune tâche d'encadrement exec nécessaire

- [ ] **Tâche 12 : Cohérence avec modèles natifs `Application` / `Depot`** (AC: 8)
  - [ ] Lire `app/Models/Application.php` et `app/Models/Depot.php` : noter `$table`, `$fillable`, casts
  - [ ] Vérifier que `wpkg_libsql.php` lit/écrit les mêmes colonnes
  - [ ] Si divergence, **adapter le shim** (pas le modèle natif) pour rester source-of-truth
  - [ ] Tests croisés : créer une `Application` via Eloquent, la lire via `liste_applications()` → doit être trouvée

- [ ] **Tâche 13 : Niveau 2 — Tests pages (49 fichiers)** (AC: 1, 5, 10)
  - [ ] Pour CHAQUE fichier PHP du module exposé via le catchall, un test : statut + contenu rendu (données seedées présentes dans le HTML/XML)
  - [ ] Couverture vérifiée par un test méta qui liste les fichiers de `legacy/modules/wpkg/*.php` et asserte qu'aucun n'est sans test

- [ ] **Tâche 14 : Niveau 3 — Tests workflows métier** (AC: 11)
  - [ ] Workflow A : import dépôt → liste applis
  - [ ] Workflow B : création parc → assoc applis → vérif
  - [ ] Workflow C : création profil → attache applis → génération `profiles_xml_out` → parse XML
  - [ ] Workflow D : enregistrement poste → consultation applis → maintenance → statut
  - [ ] Workflow E : modification MEF → propagation
  - [ ] Workflow F : suppression poste → vérif cascade

- [ ] **Tâche 15 : Suite verte + rapport de couverture** (AC: 9, 12)
  - [ ] `php artisan test --filter=Wpkg` 100 % vert
  - [ ] Header de chaque fichier de test : éléments couverts (shim / pages / workflows) + assertions
  - [ ] Vérifier le error logger via une assertion finale (pas d'entrée niveau ERROR sur tag `wpkg`)

## Inventaire shim — fonctions à couvrir

**Source :** `grep -hoE "^function [a-zA-Z_]+" legacy/wpkg_libsql.php` — **70 fonctions** (parité 100 % avec `sambaedu/includes/wpkg_libsql.php`).

### Utilisées dans le module wpkg (à tester en priorité)

| # callsites | Fonction | Type |
|---:|---|---|
| 14 | `connexion_db_wpkg` | no-op |
| 14 | `deconnexion_db_wpkg` | no-op |
| 14 | `liste_applications` | read |
| 8 | `info_parcs` | read |
| 5 | `info_postes` | read |
| 5 | `info_parc_postes` | read |
| 5 | `mise_en_forme_info` | format |
| 3 | `update_poste_nom_wpkg` | write |
| 3 | `set_entite_apps` | write |
| 3 | `insert_poste_info_wpkg` | write |
| 3 | `insert_parc_profile` | write |
| 3 | `info_poste_applications` | read |
| 3 | `info_application_postes` | read |
| 2 | `update_poste_uuid_wpkg` | write |
| 2 | `update_poste_info_wpkg` | write |
| 2 | `update_parc` | write |
| 2 | `set_appli_entites` | write |
| 2 | `insert_parc` | write |
| 2 | `insert_application_profile` | write |
| 2 | `info_poste_parcs` | read |
| 2 | `info_parc_appli` | read |
| 2 | `info_depot_principal` | read |
| 2 | `info_depot` | read |
| 2 | `info_appli_version_depot` | read |
| 2 | `info_application_rapport` | read |
| 2 | `delete_parc_profile` | write |
| 1 | `update_sha_xml_journal` | write |
| 1 | `update_mef_test` | write |
| 1 | `update_mef_defaut` | write |
| 1 | `update_mef` | write |
| 1 | `update_hash_depot` | write |
| 1 | `update_depot_principal` | write |
| 1 | `update_depot_activation` | write |
| 1 | `update_applications` | write |
| 1 | `truncate_table_profiles` | write |
| 1 | `maintenance_poste_suppression` | write |
| 1 | `maintenance_poste_reset_wpkg` | write |
| 1 | `maintenance_poste_protection` | write |
| 1 | `maintenance_poste_deprotection` | write |
| 1 | `maintenance_liste_poste` | read |
| 1 | `insert_mass_info_app_poste` | write |
| 1 | `insert_journal_app` | write |
| 1 | `insert_dependance` | write |
| 1 | `insert_appli_depot` | write |
| 1 | `insert_applications` | write |
| 1 | `info_sha_postes` | read |
| 1 | `info_postes_uuid` | read |
| 1 | `info_poste_statut` | read |
| 1 | `info_postes_parcs` | read |
| 1 | `info_poste_appli_full` | read |
| 1 | `info_parc_appli_full` | read |
| 1 | `info_depot_id_appli` | read |
| 1 | `info_depot_appli` | read |
| 1 | `info_categorie` | read |
| 1 | `info_application_requiered_parc` | read |
| 1 | `info_application_parcs` | read |
| 1 | `info_all_depot` | read |
| 1 | `desactive_depot_applis` | write |
| 1 | `delete_poste_info_wpkg` | write |
| 1 | `delete_parc_wpkg` | write |
| 1 | `delete_info_app_poste` | write |
| 1 | `delete_depot_applis_inactives` | write |
| 1 | `delete_dependances` | write |

**Total utilisé directement : 63 fonctions.**

### Non utilisées par le module wpkg (couverture minimale — smoke test)

Présentes dans le shim et l'original mais pas appelées par les pages actuelles. Les couvrir par un smoke test (signature + appel sans crash) pour la parité legacy :

`truncate_depot_applications`, `test_parent`, `test_mef`, `mise_en_forme_personnalisee`, `insert_info_app_poste`, `info_poste_rapport`, `delete_info_pkg_depot`, et helpers internes `_sql_shim_clear_wpkg_cache`, `_sql_shim_default_mef`, `_sql_shim_not_implemented`.

## Dev Notes

### Contexte

- **Stack** : Laravel 12, PHP 8.1+, PostgreSQL via Eloquent
- **Source legacy** : `sambaedu/wpkg/` (49 fichiers, 47 PHP) — symlink vers `/home/htouchard/code/irundo/se4/sources/var/www/sambaedu/wpkg`
- **Shim SQL existant** : `legacy/wpkg_libsql.php` (1665 lignes, 70 fonctions, créé en story 1bis.3)
- **Shim original mysqli** : `sambaedu/includes/wpkg_libsql.php` (1977 lignes, 70 fonctions) — **référence comportementale**
- **Cible** : `legacy/modules/wpkg/`
- **Tier** : Tier 2 (gros module, deuxième Tier 2 après iPXE story 1bis.10)

### Vérifications préalables effectuées

- **Aucun appel SQL direct** dans le module : `grep -r "mysqli_" sambaedu/wpkg/` ne renvoie rien d'actif.
- **Aucun appel LDAP direct** : confirmé par l'audit epic (0 LDAP).
- **L'epic mentionne "1 exec"** : il s'agit de `// $data = curl_exec($ch);` dans `depot_liste_app.php:96` — **commentaire mort**, pas d'encadrement nécessaire.
- **Une seule fonction custom** non préfixée détectée : `wpkg_poste_info(` (à vérifier — appartient probablement au module lui-même, pas au shim).
- **Parité shim ↔ original** : `comm -23 orig shim` = vide → toutes les 70 fonctions originales sont présentes dans le shim. **Mais ceci ne garantit pas la parité comportementale** — d'où l'exigence TDD.

### Points de vigilance comportementale

1. **Format de retour** — l'original mysqli renvoie souvent des `mysqli_fetch_assoc` sous forme de tableaux indexés ET associatifs (`MYSQLI_BOTH`). Vérifier que le shim Eloquent reproduit la même chose si du code legacy lit par index numérique (`$row[0]`).
2. **Types** — mysqli renvoie tout en string par défaut. Eloquent peut renvoyer des int. Le code legacy peut faire des comparaisons strictes `===` qui casseraient.
3. **Encodage** — UTF-8 vs latin1 : vérifier que les noms d'applis avec accents passent intacts.
4. **NULL vs '' vs 0** — les distinctions matter dans le legacy.
5. **Ordre des résultats** — si le legacy fait `ORDER BY` implicite, le shim doit le reproduire.
6. **Cache shim** — `_sql_shim_clear_wpkg_cache` existe, vérifier où le cache est posé et si les tests l'invalident.

### Décision d'architecture — Générateurs XML servis via le catchall (pas de réécriture native ici)

Les 3 endpoints critiques consommés par le client `wpkg-client.vbs` sur les postes Windows :

- `hosts_xml_out.php?poste=NAME` → `hosts.xml`
- `profiles_xml_out.php?poste=NAME` → `profiles.xml`
- `packages_xml_out.php` → catalogue global (lit `/var/sambaedu/unattended/install/wpkg/packages.xml` sur disque + injection winget dynamique)

…sont servis **tels quels** via le `LegacyCatchallController`, en mode raw grâce au Content-Type detection (mécanisme validé en story 1bis.10 pour iPXE). **Aucune réécriture native dans cette story.**

**Pourquoi ce choix :**

1. **Cohérence avec l'epic 1bis** — l'objectif est le cloisonnement legacy, pas la réécriture (réservée à l'epic 9).
2. **L'outillage existe déjà** — le catchall fait exactement ce dont on a besoin : require sous bootstrap, capture output, sert avec le bon Content-Type.
3. **Pas d'engagement architectural prématuré** — on ne tranche pas maintenant les questions ouvertes (`packages.xml` en base vs disque, binaires en object store, rapports en JSON, etc.). Ces décisions reviennent à epic 9.
4. **Le harnais TDD niveau 3 (workflows) devient le filet de non-régression pour epic 9** — quand on réécrira nativement, les tests workflow (notamment workflow C : profil → `profiles_xml_out` → parse XML) doivent continuer à passer sur l'implémentation native.

**Points à valider en début d'implémentation (tâche 0bis) :**

- ✅ Le client `wpkg-client.vbs` pointe bien sur ces URLs (à confirmer en SSH sur la VM)
- ✅ Les 3 scripts ne font pas de `exit()` qui casserait le bootstrap (ou stratégie d'interception)
- ✅ Le Content-Type `text/xml` est bien détecté par le catchall et la sortie n'est pas wrappée dans le layout SER

**Surface explicitement HORS périmètre de cette story (renvoyée à epic 9) :**

- Réécriture native du moteur XML (`hosts_xml_out`, `profiles_xml_out`, `packages_xml_out`)
- Migration de `packages.xml` (fichier disque) vers une table `AppDefinition` ou équivalent
- Migration des binaires de packages vers un object store
- Refonte du flux de rapports d'exécution (lecture .txt sur partage Samba → endpoint d'upload JSON)
- MEF (mise en forme) — à évaluer : dette morte ou vraie feature ?
- `wpkg_ldap_update.php` — probablement obsolète vu que Workstation/WorkstationGroup sont déjà alignés sur l'AD côté reload, à confirmer
- Cluster inter-SE (`wpkg_lib_get/post`) — pertinence Irundo à clarifier

### Pages du module wpkg (49 fichiers, identifiées en racine)

**Pages HTML (catchall normal, layout SER) :**
`app_liste.php`, `app_top.php`, `app_extract.php`, `app_maintenance.php`, `app_maintenance_parc.php`, `app_maintenance_poste.php`, `app_parcs.php`, `admin_app_upload.php`, `parc_appli.php`, `parc_maintenance_clone.php`, `depot_top.php`, `depot_accueil.php`, `depot_liste_app.php`, `depot_upload_app.php`, `mef_top.php`, `mef_accueil.php`, `mef_actuelle.php`, `mef_default.php`, `mef_gestion.php`, `mef_modification.php`, `mef_test.php`, `maintenance_top.php`, `maintenance_accueil.php`, `maintenance_gestion_depot.php`, `maintenance_gestion_poste.php`, `maintenance_gestion_sql.php`, `poste_statuts.php`, `poste_maintenance_app.php`, `poste_maintenance_options.php`, `log.php`, `wpkg_depot_import.php`.

**Pages sortie XML/texte (catchall raw, pas de layout) :**
`hosts_xml_out.php`, `packages_xml_out.php`, `profiles_xml_out.php`, `linux_out.php` — vérifier que le `Content-Type` détection du `LegacyCatchallController` les renvoie en raw (mécanisme validé en story 1bis.10 pour iPXE).

### Mécanisme d'exécution (rappel 1bis.4 / 1bis.10)

```
Requête HTTP (/wpkg/app_liste.php?...)
  ↓ LegacyCatchallController
  ↓ resolve legacy/modules/wpkg/app_liste.php
  ↓ executeViaBootstrap()
      ↓ require legacy/bootstrap.php (idempotent, LEGACY_BOOTSTRAP_LOADED)
          ↓ load config.inc.php, ldap.inc.php, wpkg_libsql.php (shims, guards)
          ↓ prepend stubs/ + sambaedu/includes/ dans include_path
      ↓ chdir(legacy/modules/wpkg/)
      ↓ ob_start()
      ↓ require app_liste.php
          ↓ appels liste_applications(), info_parcs()… → shim → Eloquent → PostgreSQL
      ↓ output capturé
  ↓ détection Content-Type → wrap layout (HTML) ou raw (XML)
```

### Learnings stories précédentes

**Story 1bis.4 (Tier 1) :**
- `$this->withoutVite()` dans `setUp()`
- Config sambaedu : sections `legacy_path`, `legacy_ldap`, `wpkg`
- Bootstrap idempotent — guard `LEGACY_BOOTSTRAP_LOADED`
- Guards shims : `LDAP_SHIM_LOADED`, `SQL_SHIM_LOADED`, `WPKG_LIBSQL_LOADED`
- Conflit noms tests : éviter `createApplication()` (collision TestCase Laravel) — **pertinent ici**, on manipule des `Application`
- `WorkstationGroupObserver` (LDAP AD sync) : désactiver via `unsetEventDispatcher()` dans les tests
- Vendor bridge : `legacy/modules/vendor/autoload.php`

**Story 1bis.10 (iPXE) :**
- `LegacyCatchallController` détecte `Content-Type` via `headers_list()` et `isHtmlWebPage()` → utile pour les `*_xml_out.php`
- Tests Feature : 8 tests/23 assertions ont suffi pour iPXE — pour wpkg viser **70+ fonctions × ≥1 assertion structurelle ≈ 100+ assertions minimum**
- Pages avec `exit()` tuent PHPUnit — choisir des entrées qui ne font pas `exit()` ou intercepter

### Modèles natifs existants

- `app/Models/Application.php` — modèle Eloquent existant côté reload
- `app/Models/Depot.php` — modèle Eloquent existant côté reload
- **Risque** : conflit de schéma si le shim et le modèle natif divergent. À vérifier en tâche 12.

### Sécurité

- Le module gère des packages installables sur tout le parc → **toute injection SQL ou path traversal a un impact massif**. Lors de l'audit shim, vérifier que les paramètres passent par des bindings PDO (pas de concaténation brute).
- Vérifier `wpkg_depot_import.php` et `admin_app_upload.php` : upload de fichiers → validation des extensions, taille, destination.

### Project Structure Notes

- `legacy/modules/wpkg/` — nouveau dossier (copie de `sambaedu/wpkg/`)
- `legacy/wpkg_libsql.php` — shim existant, sera complété/corrigé en TDD
- `tests/Feature/Wpkg/` — nouveau dossier dédié (au moins 2 fichiers : contract + integration)
- `app/Http/Controllers/LegacyCatchallController.php` — pas de modification attendue
- `config/sambaedu.php` — section `wpkg` existante, à compléter au besoin

### References

- Architecture — Cloisonnement Legacy : [_bmad-output/planning-artifacts/architecture.md#Cloisonnement-Legacy]
- Architecture — Shims SQL : [_bmad-output/planning-artifacts/architecture.md#Shims]
- Epics — Story 1bis.11 : [_bmad-output/planning-artifacts/epics.md#Story-1bis-11]
- Epic 9 — Gestion packages WPKG natif (cible long terme) : [_bmad-output/planning-artifacts/epics.md#Story-8.2]
- Story 1bis.3 — Création shim SQL `wpkg_libsql.php` : [_bmad-output/implementation-artifacts/1bis-3-shim-sql-mysql-eloquent.md]
- Story 1bis.4 — Bundle Tier 1 (patterns d'intégration modules) : [_bmad-output/implementation-artifacts/1bis-4-integration-modules-tier-1.md]
- Story 1bis.10 — iPXE (premier Tier 2, patterns Content-Type) : [_bmad-output/implementation-artifacts/1bis-10-module-ipxe.md]
- Shim SQL : [legacy/wpkg_libsql.php]
- Original mysqli (référence comportementale) : [sambaedu/includes/wpkg_libsql.php]
- Modèles natifs : [app/Models/Application.php], [app/Models/Depot.php]
- LegacyCatchallController : [app/Http/Controllers/LegacyCatchallController.php]
- Bootstrap : [legacy/bootstrap.php]

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

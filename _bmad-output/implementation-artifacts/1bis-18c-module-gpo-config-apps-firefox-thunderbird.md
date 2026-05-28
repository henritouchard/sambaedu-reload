# Story 1bis.18c : Module GPO — Configuration applications (Firefox, Thunderbird)

Status: ready-for-dev

## Story

As a **développeur**,
I want intégrer les 4 pages legacy de configuration applications (`gestion_apps.php`, `firefox.php`, `firefox_out.php`, `thunderbird_out.php`) dans `legacy/modules/gpo/` via le catchall Laravel,
So que les administrateurs peuvent éditer les politiques Firefox (page d'accueil, marque-pages, extensions) via l'interface legacy embarquée dans le layout SER **et** que les postes clients (Windows/Linux) peuvent récupérer les policies JSON Firefox/Thunderbird via les endpoints `*_out.php` retournés **bruts** (pas de wrapping layout), sans régression fonctionnelle par rapport au comportement direct du legacy.

## Acceptance Criteria

1. **Module copié et accessible via le catchall** — Given le dossier `legacy/modules/gpo/` contient au moins les 4 fichiers `gestion_apps.php`, `firefox.php`, `firefox_out.php`, `thunderbird_out.php` (copie à l'identique depuis `sambaedu/gpo/`, **aucune modification**), When j'accède à `/gpo/gestion_apps.php`, `/gpo/firefox.php`, `/gpo/firefox_out.php?id=<uuid>&os=linux`, `/gpo/thunderbird_out.php?id=<uuid>` via le `LegacyCatchallController`, Then chaque route renvoie un HTTP 200 et aucune erreur PHP fatale n'est levée.

2. **Chargement des fonctions Firefox via include_path** — Given le bootstrap legacy (18a) a positionné l'include_path avec `legacy/stubs/` en préfixe et `sambaedu/includes/` en suffixe, When les 4 pages sont exécutées via `executeViaBootstrap` et `firefox.php` / `firefox_out.php` / `thunderbird_out.php` font `include "firefox.inc.php"`, Then `sambaedu/includes/firefox.inc.php` est résolu et les fonctions `ff_import_policy`, `ff_export_policy`, `ff_form_policy`, `tb_import_policy`, `get_ff_ext_id` sont disponibles (`function_exists()` = `true` après `include`) sans aucun `Call to undefined function`. `firefox.inc.php` **n'est PAS ajouté au bootstrap** (chargé localement par les pages).

3. **Embedding SER des pages HTML (`gestion_apps.php`, `firefox.php`)** — Given l'utilisateur a le droit `SE_COMPUTER_ADMIN` (pour `gestion_apps.php`) ou `SE_ADMIN` (pour `firefox.php`), When la page est rendue, Then le HTML est embarqué dans le layout SER (absence de `<html`, `<head`, `<body` du legacy ; topbar legacy retirée ; token CSRF injecté dans les `<form method="post">` ; `action="firefox.php"` réécrite vers l'URL courante via `cleanLegacyHtml()`). Given l'utilisateur n'a PAS le droit requis, When la page est rendue, Then le HTML contient le message « Vous n'avez pas les droits suffisants… » (legacy `die()` capturé par `ob_get_clean()`).

4. **Réponse JSON brute des endpoints `*_out.php` (PAS d'embedding)** — Given `firefox_out.php` appelle `header('Content-type:application/json;charset=utf-8')` (ligne 14) et `thunderbird_out.php` appelle le même header (ligne 12), When le catchall traite l'output via `isHtmlWebPage($contentType, $output)`, Then `isHtmlWebPage` retourne `false` (Content-Type ≠ `text/html`) et la réponse est retournée **brute** (status 200, headers préservés, body = JSON valide `json_decode`-able) — **sans injection du layout SER, sans réécriture d'action, sans token CSRF**. Un client `curl` sur `/gpo/firefox_out.php?id=<uuid>&os=linux` reçoit directement le JSON des policies Firefox. Source : `LegacyCatchallController::isHtmlWebPage()` ligne 470.

5. **Menu `gestion_apps.php` — liens conditionnés par droits** — Given l'utilisateur a `SE_COMPUTER_ADMIN`, When `gestion_apps.php` est rendu, Then la page affiche le titre « Gestion des applications » et au moins les liens vers `firefox.php` (Firefox) et la rubrique Thunderbird. Given l'utilisateur n'a PAS `SE_COMPUTER_ADMIN`, Then la page exécute `die("Vous n'avez pas les droits suffisants…")` et aucun lien d'action n'est affiché (le HTML capturé est embarqué dans le layout SER).

6. **Formulaire de configuration `firefox.php`** — Given l'utilisateur a `SE_ADMIN`, When `firefox.php` est rendu en GET, Then la page appelle successivement `ff_import_policy($config, "", 'linux', false)` (chargement du template), affiche le formulaire via `ff_form_policy($config, $json)` (home page, marque-pages, extensions) sans erreur fatale. Given l'utilisateur soumet le formulaire en POST, When `ff_export_policy($config, $json_modifié, "/etc/sambaedu/applications/firefox/default.json")` est invoqué, Then le retour conditionne un message de succès ou d'erreur dans le HTML rendu. Le test vérifie uniquement le contrat HTML/branchement (les écritures FS `/etc/sambaedu/...` sont laissées à l'intégration sur VM).

7. **Endpoint `firefox_out.php` — contrat JSON** — Given une requête GET `/gpo/firefox_out.php?id=<uuid>&os=linux` (ou POST équivalent), When le catchall exécute la page, Then la réponse a `Content-Type: application/json; charset=utf-8`, le body est un JSON parsable, et la structure racine contient `policies` (ou le format émis par `ff_import_policy` + `ff_export_policy` sans chemin). Given `$id` est vide (paramètre absent), When la page s'exécute, Then `exit()` est appelé (ligne 10) — la réponse est vide (HTTP 200, body vide ou minimal) et **aucune stacktrace** n'est émise dans le HTML. Le test vérifie les deux cas (id présent vs id vide).

8. **Endpoint `thunderbird_out.php` — contrat JSON** — Given une requête GET `/gpo/thunderbird_out.php?id=<uuid>`, When le catchall exécute la page, Then la réponse a `Content-Type: application/json; charset=utf-8` et le body est un JSON parsable émis par `tb_import_policy($config, $id)` + `ff_export_policy($config, $json)`. Given `$id` est vide, Then `exit()` est appelé (ligne 8) — même contrat que firefox_out.

9. **Résolution des includes relatifs** — Given chaque page legacy fait des `include "config.inc.php"`, `require "ldap.inc.php"`, `require_once "functions.inc.php"`, `require_once "traitement_data.inc.php"`, `include 'admin_ui.inc.php'`, `include "ihm.inc.php"`, `include "firefox.inc.php"`, When le catchall exécute avec `chdir(legacy/modules/gpo/)` puis include_path préfixé par `legacy/stubs/` et suffixé par `sambaedu/includes/`, Then `config.inc.php`, `ldap.inc.php`, `admin_ui.inc.php` résolvent vers les stubs `legacy/stubs/` (pas de redéclaration) ; `functions.inc.php`, `traitement_data.inc.php`, `ihm.inc.php`, `firefox.inc.php` résolvent vers `sambaedu/includes/` sans erreur. Aucun `Cannot redeclare function` ni `Failed opening required` n'est levé. **Vérification préalable** : les 3 fichiers `sambaedu/includes/traitement_data.inc.php`, `sambaedu/includes/ihm.inc.php`, `sambaedu/includes/firefox.inc.php` **existent** (vérifié avant écriture de la story — aucun stub n'est à créer pour 18c).

10. **`curl_proxy_options()` disponible à l'appel depuis firefox.inc.php** — Given `firefox.inc.php` ligne 260 (fonction `get_ff_ext_id`) appelle `curl_proxy_options($config, $opt)`, When cette fonction est sollicitée, Then elle est résolue — soit via `sambaedu/includes/config.inc.php:727` chargé par l'include_path, soit via le stub `legacy/stubs/config.inc.php` si la fonction y est déjà shimée. Chaque page 18c inclut explicitement `config.inc.php` **avant** d'inclure `firefox.inc.php` (gestion_apps L20 → firefox pas d'include firefox.inc.php ; firefox.php L4 puis L17 ; firefox_out.php L2 puis L5 ; thunderbird_out.php L2 puis L5). Le test confirme `function_exists('curl_proxy_options')` après l'exécution d'une page.

11. **Droits — bitmask legacy** — Given le shim `have_right($config, SE_COMPUTER_ADMIN|SE_ADMIN)` mappe les rôles Spatie (voir `legacy/ldap.inc.php`), When un utilisateur Spatie a un rôle incluant `COMPUTER_ADMIN` (pour gestion_apps) ou `ADMIN` (pour firefox), Then `have_right` retourne `true` et les formulaires sont exposés. Sinon, la page exécute `die(...)` (pour `gestion_apps.php` et `firefox.php`) — le catchall capture l'output via `ob_get_clean()` et l'embarque dans le layout. Les endpoints `*_out.php` **n'appellent pas `have_right`** (design legacy : endpoints publics destinés aux postes clients, sans cookie Laravel).

12. **Error logger propre après chargement passif** — Given les 4 pages sont ouvertes en GET par un utilisateur autorisé (pages HTML) ou avec un `id` valide (endpoints JSON), When l'`ErrorLoggerService` est consulté, Then aucune erreur fatale (`CRITICAL`/`ERROR` sur channel `legacy`) relative au module `gpo/apps` n'est enregistrée. Les warnings non fatals (ex. `apcu_fetch` non disponible, chemins `/etc/sambaedu/applications/...` absents hors VM) sont tolérés mais listés dans le Debug Log.

13. **Tests Feature TDD** — Given un fichier `tests/Feature/Legacy/LegacyModuleGpoAppsTest.php`, When `php artisan test --filter=LegacyModuleGpoApps` est exécuté, Then au minimum 9 tests passent couvrant : (a) accessibilité HTTP de `gestion_apps.php` avec droit admin, (b) refus `gestion_apps.php` sans droit admin (message "Vous n'avez pas les droits"), (c) accessibilité HTTP de `firefox.php` avec droit admin + présence du formulaire de config, (d) endpoint `firefox_out.php?id=xxx&os=linux` → Content-Type JSON + body JSON valide, (e) endpoint `firefox_out.php` avec `id` vide → réponse JSON vide ou 200 sans stacktrace, (f) endpoint `thunderbird_out.php?id=xxx` → Content-Type JSON + body JSON valide, (g) présence des fonctions Firefox clés via `function_exists` après `require firefox.inc.php`, (h) injection CSRF + réécriture d'action dans `firefox.php` (page POST), (i) embedding HTML dans layout SER pour `gestion_apps.php` et `firefox.php` ; **pas d'embedding** pour `firefox_out.php` / `thunderbird_out.php` (assertion sur absence de `<html>` wrapper SER dans la réponse JSON).

14. **Sécurité — audit des vecteurs exec/FS/auth documenté** — Given les 4 pages + `firefox.inc.php` manipulent des accès FS, un téléchargement HTTP d'extension via Guzzle, une écriture de config dans `/etc/sambaedu/applications/firefox/default.json` et des endpoints sans authentification, When la story est revue, Then un tableau d'audit dans les Dev Notes liste : (a) les chemins FS en lecture/écriture, (b) les téléchargements réseau (Guzzle `GET url.xpi` dans `get_ff_ext_id` → peut tomber sur un attaquant MITM), (c) le statut d'authentification des endpoints `*_out.php` (aucune — intentionnel legacy), (d) le risque résiduel (injection via `$os` dans `ff_import_policy`, path traversal sur XPI téléchargé, enumeration des UUID machines via les endpoints, écriture de la config Firefox par un admin malveillant). Aucun `exec()` n'est invoqué dans les 5 fichiers (`firefox.inc.php` est **stateless, sans exec**).

## Tasks / Subtasks

### Phase 1 : Analyse et pré-requis (AC: #2, #9, #10, #14)

- [ ] **T1.1** Vérifier que la story 1bis.18a est `done` et que le bootstrap charge bien les 4 includes GPO core (`samba-tool`, `gpo`, `delegations`, `gpo_ui`) + `gpo_deps.inc.php`. (AC: #2)
- [ ] **T1.2** Confirmer que `sambaedu/includes/traitement_data.inc.php`, `sambaedu/includes/ihm.inc.php`, `sambaedu/includes/firefox.inc.php` existent (vérifié en amont : les 3 fichiers existent). Aucun stub à créer pour 18c. (AC: #9)
- [ ] **T1.3** Lister les fonctions consommées par les 4 pages + `firefox.inc.php` (grep sur `sambaedu/gpo/{gestion_apps,firefox,firefox_out,thunderbird_out}.php` + `sambaedu/includes/firefox.inc.php`) et confirmer leur résolution. Fonctions attendues : `ff_import_policy`, `ff_export_policy`, `ff_form_policy`, `tb_import_policy`, `get_ff_ext_id` (firefox.inc.php) ; `curl_proxy_options` (sambaedu/includes/config.inc.php) ; `have_right`, `get_config`, `header_authorize`, `admin_{header,topbar,menu,footer}_html` (stubs). (AC: #2, #10)
- [ ] **T1.4** Vérifier que le shim LDAP couvre `have_right`, `get_config` et que `SE_COMPUTER_ADMIN` (pour gestion_apps) et `SE_ADMIN` (pour firefox) sont définies comme constantes accessibles. Constantes localisées dans `legacy/ldap.inc.php:57-59`. (AC: #3, #5, #11)
- [ ] **T1.5** Produire le tableau d'audit sécurité (AC #14) — pas d'`exec()` dans les 5 fichiers, mais : (a) téléchargement HTTP Guzzle d'extension XPI dans `get_ff_ext_id` (firefox.inc.php:261), (b) extraction ZIP `manifest.json` via `ZipArchive` (risque path-traversal XPI), (c) écriture FS `/etc/sambaedu/applications/firefox/default.json` par `ff_export_policy`, (d) endpoints `*_out.php` sans authentification (design legacy). (AC: #14)

### Phase 2 : Copie du module (AC: #1, #9)

- [ ] **T2.1** Créer `legacy/modules/gpo/` **si absent** (peut être créé par 18b en amont — vérifier avant, sinon créer). Y copier **à l'identique** les 4 fichiers : `gestion_apps.php`, `firefox.php`, `firefox_out.php`, `thunderbird_out.php` (aucune modification). (AC: #1)
- [ ] **T2.2** Ne PAS copier `firefox.inc.php` dans `legacy/modules/gpo/` — il reste dans `sambaedu/includes/` et sera résolu via include_path quand les pages feront `include "firefox.inc.php"`. (AC: #2)
- [ ] **T2.3** Ne PAS modifier `legacy/bootstrap.php` — toutes les dépendances nécessaires sont déjà en place via 18a (bootstrap charge GPO core) + stubs Tier 1 (config, ldap, admin_ui) + include_path (resolution de firefox.inc.php/ihm.inc.php/traitement_data.inc.php). Si un include manquant apparaît à l'exécution, documenter dans le Debug Log et choisir entre : (a) charger dans le bootstrap, (b) créer un stub, (c) laisser la résolution include_path. (AC: #2, #9)

### Phase 3 : Intégration catchall — vérifications manuelles par tests (AC: #1, #3, #4, #9)

- [ ] **T3.1** Lancer un GET sur `/gpo/gestion_apps.php` via un test Feature minimaliste (login forcé + droit `COMPUTER_ADMIN`). Vérifier l'embedding dans le layout SER (absence de `<html`, `<head`, topbar legacy). (AC: #1, #3, #5)
- [ ] **T3.2** Idem pour `/gpo/firefox.php` (GET avec droit `SE_ADMIN`). Vérifier présence du formulaire `ff_form_policy`. (AC: #1, #3, #6)
- [ ] **T3.3** Lancer un GET sur `/gpo/firefox_out.php?id=test-uuid&os=linux` **SANS login Laravel** (ou avec — le catchall ne bloque pas) et vérifier : Content-Type = `application/json`, body = JSON valide, **absence du layout SER** (pas de `<html>`, pas de navbar). Le catchall doit détecter `Content-Type: application/json` → retour raw. (AC: #4, #7)
- [ ] **T3.4** Idem pour `/gpo/thunderbird_out.php?id=test-uuid`. (AC: #4, #8)
- [ ] **T3.5** Vérifier que `cleanLegacyHtml()` a bien réécrit les `action="firefox.php"` vers l'URL courante et injecté `<input type="hidden" name="_token">` dans le `<form method="post">` de `firefox.php`. Assertion sur le HTML rendu. (AC: #3)

### Phase 4 : Tests (AC: #13)

- [ ] **T4.1** Créer `tests/Feature/Legacy/LegacyModuleGpoAppsTest.php` (pattern `LegacyModuleIpxeTest.php` + `LegacyModuleGpoGestionTest.php` de 18b). `setUp()` : `$this->withoutVite()`, seed d'un utilisateur Spatie avec rôle donnant `SE_COMPUTER_ADMIN` + un deuxième avec `SE_ADMIN` si différent. (AC: #13)
- [ ] **T4.2** Test `test_gestion_apps_page_is_accessible_for_computer_admin()` : GET `/gpo/gestion_apps.php` → 200 + présence de « Gestion des applications » + liens Firefox/Thunderbird. (AC: #5, #13)
- [ ] **T4.3** Test `test_gestion_apps_denies_access_without_right()` : GET avec user sans `COMPUTER_ADMIN` → HTML contient « Vous n'avez pas les droits ». (AC: #5, #11, #13)
- [ ] **T4.4** Test `test_firefox_page_renders_policy_form()` : GET `/gpo/firefox.php` avec `SE_ADMIN` → 200 + présence du formulaire (marque-pages, home page, extensions). Si `ff_import_policy` requiert `/usr/share/sambaedu/applications/firefox/default.json` et que le fichier n'existe pas hors VM, mocker la fonction ou accepter un JSON vide + vérifier absence d'erreur fatale. (AC: #6, #13)
- [ ] **T4.5** Test `test_firefox_out_returns_json_payload()` : GET `/gpo/firefox_out.php?id=test&os=linux` → Content-Type contient `application/json` + `json_decode` du body ne retourne pas `null`. Si la fonction `ff_import_policy` échoue hors VM (fichiers absents), accepter un JSON minimal/vide mais valide. (AC: #4, #7, #13)
- [ ] **T4.6** Test `test_firefox_out_with_empty_id_returns_empty()` : GET `/gpo/firefox_out.php` (sans `id`) → 200 ou 204, body vide ou JSON minimal, **aucune** stacktrace PHP ni erreur fatale dans le HTML. (AC: #7, #13)
- [ ] **T4.7** Test `test_thunderbird_out_returns_json_payload()` : GET `/gpo/thunderbird_out.php?id=test` → Content-Type contient `application/json` + body JSON parsable. (AC: #4, #8, #13)
- [ ] **T4.8** Test `test_firefox_inc_functions_are_available_after_include()` : simuler un include de `firefox.inc.php` (via `require_once` dans le test) puis `function_exists` pour `ff_import_policy`, `ff_export_policy`, `ff_form_policy`, `tb_import_policy`, `get_ff_ext_id`. (AC: #2, #13)
- [ ] **T4.9** Test `test_firefox_form_has_csrf_token_and_current_action()` : GET `/gpo/firefox.php` → assertion `<input type="hidden" name="_token"` + action pointant sur l'URL courante (pas `firefox.php` relatif). (AC: #3, #13)
- [ ] **T4.10** Test `test_out_endpoints_are_not_embedded_in_ser_layout()` : GET `/gpo/firefox_out.php?id=x&os=linux` et `/gpo/thunderbird_out.php?id=x` → body **ne contient pas** `<html`, `<nav`, `<head`, ni tout marker du layout SER (par exemple chercher la classe CSS principale du layout — ajuster selon le layout actuel). (AC: #4, #13)
- [ ] **T4.11** Test `test_html_pages_are_embedded_in_ser_layout()` : GET `/gpo/gestion_apps.php` et `/gpo/firefox.php` → le HTML rendu **ne contient pas** `<html` du legacy (si présent, c'est celui du layout SER — asserter la présence du layout SER plutôt). (AC: #3, #13)

### Phase 5 : Documentation, sécurité, finalisation (AC: #12, #14)

- [ ] **T5.1** Consolider le tableau d'audit sécurité dans les Dev Notes (Section « Audit sécurité — vecteurs FS/HTTP/auth »). Mettre en avant : (a) endpoints `*_out.php` sans authentification, (b) téléchargement Guzzle XPI vers URL fournie par admin dans `get_ff_ext_id`, (c) écriture de la config Firefox dans `/etc/sambaedu/...` par `ff_export_policy`. (AC: #14)
- [ ] **T5.2** Documenter la commande SSH de validation manuelle : `ssh sshlab1Etab` puis `curl -k "https://<vm>/gpo/firefox_out.php?id=<uuid>&os=linux"` — **ne PAS exécuter depuis le subagent** (VM accessible uniquement via `sshlab1Etab`).
- [ ] **T5.3** Vérifier via une assertion de test que l'`ErrorLoggerService` ne contient pas d'entrée niveau `ERROR` ou `CRITICAL` avec tag/channel `legacy` après les tests passifs. (AC: #12)
- [ ] **T5.4** Préparer la section Change Log et File List dans ce fichier story (à remplir par le dev après implémentation).

## Dev Notes

### Contexte

Troisième sous-story du module GPO (Tier 3, le plus risqué), consécutive à 1bis.18a (bootstrap core) et 1bis.18b (pages import/export). 18c a la **particularité d'introduire 2 endpoints JSON** (`firefox_out.php`, `thunderbird_out.php`) consommés par les postes clients (Windows/Linux agents) — c'est le premier cas d'endpoint JSON non-HTML du module GPO dans le cloisonnement (précédent : `boot.php` en `text/plain` dans 1bis.10 iPXE ; `packages.xml` en XML dans 1bis.11 WPKG).

**Aucun shim n'est à créer** : `firefox.inc.php`, `traitement_data.inc.php`, `ihm.inc.php` existent déjà dans `sambaedu/includes/` et seront résolus via include_path. Toutes les fonctions GPO sont déjà chargées par 18a. Les pages legacy appellent explicitement `include "config.inc.php"` **avant** `include "firefox.inc.php"`, ce qui garantit la disponibilité de `curl_proxy_options()` (utilisée par `get_ff_ext_id` pour le proxy).

**Pages concernées (taille totale : 185 lignes) :**

- `sambaedu/gpo/gestion_apps.php` — 48 lignes, page-menu applications. Droit requis : `SE_COMPUTER_ADMIN`.
- `sambaedu/gpo/firefox.php` — 107 lignes, page de configuration Firefox (formulaire d'édition home page, marque-pages, extensions). Droit requis : `SE_ADMIN`.
- `sambaedu/gpo/firefox_out.php` — 16 lignes, endpoint JSON appelé par les postes clients pour récupérer les policies Firefox (paramètres GET/POST `id` + `os`).
- `sambaedu/gpo/thunderbird_out.php` — 14 lignes, endpoint JSON appelé par les postes clients pour récupérer les policies Thunderbird (paramètre GET/POST `id`).

**Librairie utilisée :**

- `sambaedu/includes/firefox.inc.php` — 292 lignes, **stateless, sans exec()**. 5 fonctions exposées : `ff_import_policy`, `ff_export_policy`, `ff_form_policy`, `tb_import_policy`, `get_ff_ext_id`. Dépendance transitive : `curl_proxy_options` (config.inc.php:727) + Guzzle + `ZipArchive`.

### Carte des dépendances

```
Pages (4 fichiers, 185 LOC) + firefox.inc.php (292 LOC, lib)
  │
  ├── Includes à résoudre au chargement :
  │     config.inc.php          → legacy/stubs/ (existant)
  │     ldap.inc.php            → legacy/stubs/ (existant)
  │     functions.inc.php       → sambaedu/includes/ (déjà chargé par bootstrap 18a)
  │     traitement_data.inc.php → sambaedu/includes/ (✅ existe, chargé via include_path)
  │     admin_ui.inc.php        → legacy/stubs/ (existant)
  │     ihm.inc.php             → sambaedu/includes/ (✅ existe, chargé par les pages via include_path)
  │     firefox.inc.php         → sambaedu/includes/ (✅ existe, chargé par les pages via include_path)
  │
  ├── Fonctions Firefox consommées (via firefox.inc.php) :
  │     ff_import_policy($c, $id, $os, $auto=true)  (firefox.inc.php:7)
  │         ↳ lit /usr/share/sambaedu/applications/firefox/default.json (template)
  │         ↳ scan /etc/sambaedu/applications/firefox/ (surcharges JSON)
  │         ↳ apcu_fetch("apps.$id") (optionnel, protégé)
  │         ↳ retourne array [policies => ...]
  │     ff_export_policy($c, $json, $path="")       (firefox.inc.php:80)
  │         ↳ si $path = "" → retourne string JSON (utilisé par *_out.php)
  │         ↳ si $path != "" → file_put_contents (utilisé par firefox.php POST)
  │     ff_form_policy($c, $json)                   (firefox.inc.php:89)
  │         ↳ génère HTML formulaire (marque-pages, home page, extensions)
  │     tb_import_policy($c, $id, $auto=true)       (firefox.inc.php:201)
  │         ↳ équivalent ff_import_policy pour Thunderbird (sans $os)
  │     get_ff_ext_id($c, $url, &$html)             (firefox.inc.php:251)
  │         ↳ Guzzle GET $url → télécharge XPI en /tmp/
  │         ↳ ZipArchive ouvre XPI, lit manifest.json, retourne ID
  │         ↳ unlink /tmp/...xpi
  │         ↳ utilise curl_proxy_options($c, $opt) si proxy configuré
  │
  ├── Fonctions support :
  │     curl_proxy_options($c, $opt)  (sambaedu/includes/config.inc.php:727)
  │         ↳ disponible après `include "config.inc.php"` dans les pages
  │     have_right($c, SE_…)          (legacy/ldap.inc.php shim)
  │     get_config()                  (legacy/stubs/config.inc.php)
  │     admin_{header,topbar,menu,footer}_html + header_authorize
  │                                    (legacy/stubs/admin_ui.inc.php)
  │
  └── Pas d'exec() dans les 5 fichiers — firefox.inc.php est stateless
        (contrairement à 18b où gpo.inc.php + gpo-export.php avaient du `exec` + `sudo apt install`)
```

### Audit sécurité — vecteurs FS/HTTP/auth (pas d'exec)

| Vecteur                                                                 | Emplacement                    | Paramètre user         | Mitigation / échappement          | Risque      | Remédiation story |
|-------------------------------------------------------------------------|--------------------------------|------------------------|-----------------------------------|-------------|-------------------|
| Lecture `/usr/share/sambaedu/applications/firefox/default.json`         | `firefox.inc.php:17`           | aucun (literal)        | N/A                               | FAIBLE      | OK (template système) |
| Lecture `/etc/sambaedu/applications/firefox/*.json` (scandir)           | `firefox.inc.php:65-75`        | aucun                  | `scandir` puis `preg_match` filter | FAIBLE      | OK |
| Écriture `/etc/sambaedu/applications/firefox/default.json`              | `firefox.inc.php:83` (via `$path`) | `$json` ← form POST admin | auth `SE_ADMIN` + CSRF + JSON structure | FAIBLE      | OK (admin trust) |
| Lecture `/usr/share/sambaedu/applications/thunderbird/default.json`     | `firefox.inc.php:210`          | aucun                  | N/A                               | FAIBLE      | OK |
| Téléchargement HTTP Guzzle XPI `GET $url`                                | `firefox.inc.php:261-265`      | `$url` ← form POST admin | **AUCUN whitelist** — TLS verify par défaut | MODÉRÉ      | Documenter — un admin malveillant peut pointer vers URL hostile, mais droit `SE_ADMIN` requis |
| Extraction ZIP XPI (`ZipArchive::extractTo` / `getFromName`)             | `firefox.inc.php:268-283`      | contenu ZIP téléchargé | `getFromName('manifest.json')` ciblé | FAIBLE      | OK (pas de `extractTo` full) |
| Écriture temp `/tmp/$fileName`                                           | `firefox.inc.php:265`          | `$fileName` ← basename($url) | `basename()` filter             | FAIBLE      | OK |
| Unlink temp `/tmp/$fileName`                                             | `firefox.inc.php:282`          | idem                   | idem                              | FAIBLE      | OK |
| Endpoint `firefox_out.php` — pas d'auth                                  | `firefox_out.php` (entier)     | `id`, `os` ← GET/POST | **AUCUNE auth** (design legacy)  | MODÉRÉ      | Documenter — design intentionnel (postes clients sans cookie) |
| Endpoint `thunderbird_out.php` — pas d'auth                              | `thunderbird_out.php` (entier) | `id` ← GET/POST        | idem                              | MODÉRÉ      | idem |
| Injection via `$os` dans `ff_import_policy`                              | `firefox_out.php:12`           | `$os` ← GET/POST       | utilisé comme clé de lookup JSON (`[$os]`) | FAIBLE | OK (pas interpolé dans shell/FS) |
| Énumération UUID machines via endpoints                                  | `*_out.php`                    | `id` ← GET/POST        | `apcu_fetch("apps.$id")` → miss = JSON template générique | FAIBLE | Documenter (pas de fuite critique) |

**Point de vigilance 1** : les endpoints `*_out.php` sont **publics** (pas de middleware auth). Design intentionnel legacy (les postes clients Windows/Linux n'ont pas de cookie Laravel). Vérifier que le middleware `web` du catchall ne bloque pas ces routes (si besoin, exemption via middleware `api` ou liste blanche). Cf. 1bis.10 iPXE `boot.php` qui a le même problème et est résolu par le catchall (Laravel auth passe sur la route mais la page elle-même ne vérifie pas `auth()->user()`).

**Point de vigilance 2** : `get_ff_ext_id` télécharge une URL fournie par un admin (`SE_ADMIN`). C'est un vecteur SSRF potentiel (un admin pourrait sonder le réseau interne via cette fonction). **Non corrigé dans cette story** (hérité legacy) — documenté comme candidat pour un durcissement futur (epic 9 ou refactor Firefox).

**Pas d'`exec()` dans les 5 fichiers** — c'est la différence majeure avec 18b (`gpo-maj.php:67` avait `sudo apt install`).

### Points de vigilance

1. **Content-Type detection pour `*_out.php`** — `isHtmlWebPage()` (LegacyCatchallController.php:470) vérifie `str_contains(strtolower($contentType), 'text/html')`. Les endpoints `*_out.php` émettent `Content-type:application/json;charset=utf-8` → `isHtmlWebPage` retourne `false` → réponse **raw** (code 343-355 de `executeViaBootstrap`). Pattern validé par 1bis.10 (boot.php en `text/plain`). Le test T4.10 doit vérifier cette absence d'embedding.
2. **`exit()` dans `firefox_out.php:10` et `thunderbird_out.php:8`** — appelé quand `$id` vide. L'exit() est invoqué **après** `header()` mais **avant** l'écho JSON → réponse avec header `application/json` mais body vide. En PHPUnit, l'exit intervient dans le process PHP fork mais `ob_get_clean()` du catchall a déjà capturé ce qui est écrit. Pattern validé par 1bis.10 (iPXE `boot.php` avec `exit()` sur mac vide). Le test T4.6 doit vérifier le cas vide.
3. **`die()` dans `gestion_apps.php`** — appelé quand droits insuffisants. Le `die` interrompt le process PHP en cours, mais `ob_get_clean()` capture l'output avant. Pas de stacktrace. Pattern validé par 18b.
4. **`$config['etab_ou']` et `get_config()`** — `firefox.php` et les pages legacy appellent `get_config()` (sans paramètres) — le stub `legacy/stubs/config.inc.php::get_config()` doit retourner un `$config` contenant les clés `se4fs_name`, `se4_url`, `proxy_type`, `proxy_address`, `proxy_port`, `proxy_url` utilisées par `firefox.inc.php`. À vérifier lors du test T4.5 — si `$config` renvoie des valeurs vides, les JSON produits seront vides mais valides.
5. **APCu optionnel** — `firefox.inc.php:13,206` appelle `apcu_fetch("apps.$id")`. L'extension APCu peut ne pas être chargée (cf. note `apcu-stub-logs` dans `sprint-status.yaml:187`) → le code utilise `if ($info = apcu_fetch(...))` donc un `false` rentre simplement dans le fallback. Pas de blocage. **Ne PAS stubber APCu dans les tests** — laisser le fallback naturel.
6. **`CzProject\GitPhp\Git` / `vendor/autoload.php`** — *contrairement à 18b*, `firefox.inc.php` utilise `vendor/autoload.php` pour Guzzle (ligne 256 : `require_once (dirname(__FILE__) . '/../vendor/autoload.php')`). Ce chemin pointe vers `sambaedu/vendor/autoload.php` qui n'existe pas dans le repo reload. Le vendor bridge `legacy/modules/vendor/autoload.php` existe déjà (cf. 1bis.4). **Tester** : si le `require_once` vers un vendor absent lève une erreur fatale à l'exécution de `get_ff_ext_id`, (a) créer un shim `sambaedu/vendor/autoload.php` pont vers `vendor/autoload.php` du reload, OU (b) intercepter le chemin via un helper, OU (c) laisser le `require_once` échouer silencieusement si la fonction n'est jamais appelée par les tests. **Cette story ne teste pas `get_ff_ext_id`** (pas de téléchargement XPI en test) → l'erreur ne devrait pas se déclencher. Documenter dans le Debug Log si elle survient.
7. **Chemins absolus `/usr/share/sambaedu/applications/...` et `/etc/sambaedu/applications/...`** — présents dans `firefox.inc.php`. Sur la VM dev, ces chemins existent ; sur le host/CI ils n'existent pas → `file_get_contents` retourne `false` / `scandir` vide → `ff_import_policy` retourne un tableau minimal, pages se rendent mais sans données. Les tests doivent l'accepter.
8. **Session + auth pour les endpoints `*_out.php`** — les postes clients appellent ces endpoints sans cookie Laravel. Vérifier que le middleware `web` du catchall ne les bloque PAS avec un redirect `/login`. Cf. 1bis.10 iPXE où le problème a été résolu — mêmes routes. **À tester** : T4.5 et T4.7 sans login forcé (ou avec un middleware bypass explicite si nécessaire).
9. **`traitement_data.inc.php`** — ✅ existe dans `sambaedu/includes/` (vérifié 2026-04-14). Aucun stub à créer, contrairement à ce qui était envisagé dans 18b.
10. **Ordre d'inclusion `config.inc.php` avant `firefox.inc.php`** — vérifié dans les 4 pages : gestion_apps.php L20 (pas de firefox.inc.php), firefox.php L4→L17, firefox_out.php L2→L5, thunderbird_out.php L2→L5. L'ordre est respecté → `curl_proxy_options` sera toujours disponible quand `firefox.inc.php` l'appellera.

### Pattern d'intégration catchall (rappel — identique à 18b avec variante JSON)

```
Requête HTTP /gpo/firefox_out.php?id=xxx&os=linux
  ↓ LegacyCatchallController::handle()
  ↓ strip UAI prefix du path si présent
  ↓ test legacy/modules/gpo/firefox_out.php existe et est dans legacy/modules/ (containment)
  ↓ logLegacyAccess() → legacy_catchall_logs
  ↓ executeViaBootstrap(request, resolvedPath, false)
      ↓ require_once legacy/bootstrap.php (idempotent)
          ↓ charge shims (config, ldap, wpkg_libsql, gpo_deps)
          ↓ préfixe include_path avec legacy/stubs/
          ↓ suffixe include_path avec sambaedu/includes/
          ↓ require_once samba-tool.inc.php, gpo.inc.php, delegations.inc.php, gpo_ui.inc.php (18a)
      ↓ bridgeLegacySession() → $_SESSION (ou vide si appel sans cookie)
      ↓ chdir(legacy/modules/gpo/)
      ↓ $_SERVER['PHP_SELF'] = request->getPathInfo()
      ↓ ob_start()
      ↓ require firefox_out.php
          ↓ include "config.inc.php"     → resolve legacy/stubs/config.inc.php
          ↓ $config = get_config();
          ↓ include "ldap.inc.php"       → resolve legacy/stubs/ldap.inc.php
          ↓ include "firefox.inc.php"    → resolve sambaedu/includes/firefox.inc.php
          ↓ $id = $_POST["id"] ?? $_GET["id"];
          ↓ $os = $_POST["os"] ?? $_GET["os"];
          ↓ if (empty($id)) exit();
          ↓ $json_array = ff_import_policy($config, $id, $os);
          ↓ $json = ff_export_policy($config, $json_array);  // sans path → retourne string
          ↓ header('Content-type:application/json;charset=utf-8');
          ↓ echo $json;
      ↓ output capturé ($output)
      ↓ contentType = "application/json; charset=utf-8"  (depuis headers_list())
  ↓ isHtmlWebPage(contentType, output) → FALSE  (Content-Type ne contient pas text/html)
  ↓ return response($output, 200)
       ->header('Content-Type', 'application/json; charset=utf-8')  (headers préservés)
  ↓ réponse HTML 200 Content-Type application/json — SANS layout SER
```

Pour `firefox.php` et `gestion_apps.php` (HTML), le flow est identique à 18b : `isHtmlWebPage` retourne `true` → `cleanLegacyHtml` → `view('legacy-embed')`.

### Learnings de 1bis.18a et 1bis.18b (référence critique)

- **Bootstrap 18a charge déjà les 4 includes GPO + stubs** : `samba-tool.inc.php`, `gpo.inc.php`, `delegations.inc.php`, `gpo_ui.inc.php`, `legacy/stubs/gpo_deps.inc.php`. **Aucune modification de `legacy/bootstrap.php` n'est requise pour 18c.**
- **Constantes `SE_COMPUTER_ADMIN` / `SE_ADMIN`** : définies dans `legacy/ldap.inc.php:57-59` (guards `if (!defined(...))`). Disponibles après chargement du bootstrap.
- **`have_right` et `get_config`** : shimés dans `legacy/stubs/config.inc.php` et `legacy/ldap.inc.php` (cf. 1bis.4 Tier 1 + 18b). Ne pas re-shimer.
- **14 tests PASSANT dans `tests/Unit/LegacyGpoIncludesTest.php`** (18a) : pattern à reprendre pour les tests 18c Unit si besoin. Pour 18c, privilégier Feature tests (pattern 18b `LegacyModuleGpoGestionTest.php`).
- **VM SSH inaccessible pendant l'implémentation 18a et 18b** : pas de validation sur VM par le subagent. **Même contrainte pour 18c** — le dev mettra en review avant merge manuel avec validation SSH `sshlab1Etab`.
- **Pattern d'endpoint non-HTML dans le catchall** : déjà validé par 1bis.10 iPXE (`boot.php` en `text/plain`) et 1bis.11 WPKG (XML). La détection `isHtmlWebPage()` fonctionne par Content-Type. 18c introduit le cas JSON — doit fonctionner identique.

### Learnings de 1bis.10 (iPXE — premier Tier 2 intégré, endpoints non-HTML)

- `$this->withoutVite()` dans `setUp()` des tests Feature (obligatoire).
- Pages avec `exit()` (`boot.php`) → le catchall capture l'output via `ob_get_clean()` avant l'exit. PHPUnit ne crash pas.
- `isHtmlWebPage()` détecte `text/html` + présence de `<form`, `<table`, `<div`, `<h1` etc. Les pages `*_out.php` de 18c émettent du JSON → `isHtmlWebPage` = `false` → réponse raw.
- `Content-Type` détecté via `headers_list()` pendant `ob_start`. L'ordre d'appel `header()` puis `echo` est respecté par les pages legacy.
- `chdir()` : le CWD est positionné dans `legacy/modules/gpo/` avant exécution, puis restauré dans `finally`.

### Project Structure Notes

```
# Fichiers à créer
legacy/modules/gpo/gestion_apps.php         # copie à l'identique de sambaedu/gpo/gestion_apps.php (48 L)
legacy/modules/gpo/firefox.php              # copie à l'identique de sambaedu/gpo/firefox.php (107 L)
legacy/modules/gpo/firefox_out.php          # copie à l'identique de sambaedu/gpo/firefox_out.php (16 L)
legacy/modules/gpo/thunderbird_out.php      # copie à l'identique de sambaedu/gpo/thunderbird_out.php (14 L)
tests/Feature/Legacy/LegacyModuleGpoAppsTest.php  # 9+ tests (AC #13)

# Fichiers à NE PAS créer / NE PAS modifier
# - firefox.inc.php n'est pas copié (résolu via include_path depuis sambaedu/includes/)
# - legacy/bootstrap.php n'est pas modifié (18a suffit)
# - aucun stub à créer (traitement_data.inc.php, ihm.inc.php, firefox.inc.php existent tous)

# Fichiers source (lecture seule — NE PAS modifier)
sambaedu/gpo/gestion_apps.php               # 48 lignes
sambaedu/gpo/firefox.php                    # 107 lignes
sambaedu/gpo/firefox_out.php                # 16 lignes
sambaedu/gpo/thunderbird_out.php            # 14 lignes
sambaedu/includes/firefox.inc.php           # 292 lignes (lib stateless)
sambaedu/includes/traitement_data.inc.php   # utilitaire chargé via include_path
sambaedu/includes/ihm.inc.php               # utilitaire chargé via include_path
sambaedu/includes/config.inc.php            # fournit curl_proxy_options via include_path

# Fichiers existants pertinents (référence)
app/Http/Controllers/LegacyCatchallController.php  # executeViaBootstrap + cleanLegacyHtml + isHtmlWebPage
legacy/bootstrap.php                               # charge les 4 includes GPO + stubs
legacy/stubs/gpo_deps.inc.php                      # 18a
legacy/stubs/admin_ui.inc.php                      # admin_header_html, admin_topbar_html, etc.
legacy/stubs/config.inc.php                        # get_config, $config bridge
legacy/stubs/ldap.inc.php                          # wrapper pour legacy/ldap.inc.php
legacy/ldap.inc.php                                # shim search_ad, have_right, SE_* constants
tests/Feature/LegacyModuleIpxeTest.php             # pattern de test (8 tests, endpoint text/plain)
_bmad-output/implementation-artifacts/1bis-18b-module-gpo-gestion-import-export.md  # pattern 18b (HTML pur)
```

### Alignement avec l'architecture projet

- **Convention routing Laravel** : les pages legacy ne passent PAS par `resources/views/pages/` (réservé aux nouvelles pages Livewire). Elles sont servies via `LegacyCatchallController` qui intercepte tout path non matché.
- **Shims** : aucun nouveau shim pour 18c — tout est couvert par 18a + Tier 1 (config, ldap, admin_ui).
- **Session / auth** : `bridgeLegacySession()` synchronise `auth()->user()` → `$_SESSION['login']` pour les pages HTML (gestion_apps, firefox). Les endpoints `*_out.php` n'utilisent pas la session (paramètres en GET/POST).
- **CSRF** : injecté automatiquement par `cleanLegacyHtml()` dans le `<form method="post">` de `firefox.php`. Les endpoints `*_out.php` ne sont pas concernés (réponse JSON brute, pas de formulaire HTML).
- **Middleware web** : vérifier que le middleware CSRF / session ne bloque pas les POST sur `*_out.php` si un poste client les sollicite. Cf. issue similaire 1bis.10 iPXE — exemption probable nécessaire ou POST silencieux (à valider sur VM).

### Commandes de validation manuelle (VM)

Sur le host, **ne pas exécuter depuis le subagent**. Pour le dev après implémentation :

```bash
# Bash (depuis le host)
ssh sshlab1Etab

# Sur la VM, une fois loggé :
cd /var/www/sambaedu-reload  # ou équivalent
php artisan test --filter=LegacyModuleGpoApps

# Puis tests HTTP manuels :
# 1. Pages HTML (avec session authentifiée)
curl -k -b "laravel_session=<valeur>" https://<vm>/gpo/gestion_apps.php
curl -k -b "laravel_session=<valeur>" https://<vm>/gpo/firefox.php

# 2. Endpoints JSON (sans authentification — poste client)
curl -k "https://<vm>/gpo/firefox_out.php?id=test-uuid&os=linux" | jq .
curl -k "https://<vm>/gpo/thunderbird_out.php?id=test-uuid" | jq .

# 3. Vérifier les logs
tail -f storage/logs/legacy.log
```

### References

- Architecture — Cloisonnement Legacy : [_bmad-output/planning-artifacts/architecture.md#Cloisonnement-Legacy]
- Architecture — Shims : [_bmad-output/planning-artifacts/architecture.md#Shims]
- Epics — Story 1bis.18 (section complète) : [_bmad-output/planning-artifacts/epics.md#Story-1bis-18]
- Epics — Story 1bis.18c : [_bmad-output/planning-artifacts/epics.md#Story-1bis-18c] (lignes 986-1016)
- Story précédente 1bis.18a (dépendance directe) : [_bmad-output/implementation-artifacts/1bis-18a-module-gpo-includes-core.md]
- Story précédente 1bis.18b (pattern HTML catchall GPO) : [_bmad-output/implementation-artifacts/1bis-18b-module-gpo-gestion-import-export.md]
- Story 1bis.10 (pattern Tier 2, endpoint text/plain non-HTML) : [_bmad-output/implementation-artifacts/1bis-10-module-ipxe.md]
- Story 1bis.11 (pattern Tier 2, endpoint XML) : [_bmad-output/implementation-artifacts/1bis-11-module-wpkg.md]
- Story 1bis.4 (patterns Tier 1, bootstrap + stubs) : [_bmad-output/implementation-artifacts/1bis-4-integration-modules-tier-1.md]
- Catchall : [app/Http/Controllers/LegacyCatchallController.php] (méthodes `handle`, `executeViaBootstrap`, `isHtmlWebPage`, `cleanLegacyHtml`)
- Bootstrap : [legacy/bootstrap.php]
- Shims : [legacy/stubs/], [legacy/ldap.inc.php], [legacy/config.inc.php]
- Sources legacy (lecture seule) : [sambaedu/gpo/gestion_apps.php], [sambaedu/gpo/firefox.php], [sambaedu/gpo/firefox_out.php], [sambaedu/gpo/thunderbird_out.php], [sambaedu/includes/firefox.inc.php]
- Tests pattern : [tests/Feature/LegacyModuleIpxeTest.php] (endpoint non-HTML), [tests/Feature/Legacy/LegacyModuleGpoGestionTest.php] (pattern 18b — à créer/suivre)

## Recommandation Modèle Dev

**Sonnet** — Cette story est plus légère que 18b (pas d'`exec()`, pas de `sudo apt install`, pas de dépendance Git/Composer à débloquer, aucun stub à créer). Les fichiers cibles sont compacts (185 LOC pour les 4 pages + 292 LOC pour la lib déjà existante). Les deux points de complexité sont : (1) la gestion du Content-Type JSON dans le catchall (déjà résolue en 1bis.10), (2) la vérification que les endpoints `*_out.php` passent bien sans cookie Laravel. Sonnet est adéquat avec vigilance particulière sur T3.3/T3.4 (vérification que `isHtmlWebPage` renvoie bien `false` pour les réponses JSON) et T4.5/T4.7 (tests sans login forcé). Si Opus est disponible et que le contexte global du cycle est chargé, Opus offre une marge supplémentaire pour éviter une régression sur les endpoints publics.

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

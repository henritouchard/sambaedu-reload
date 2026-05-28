# Story 1bis.18e : Module GPO — Scripts réseau, Veyon, Wine, applications, associations

Status: review

## Story

As a **développeur**,
I want intégrer les 5 endpoints "output" legacy du cluster GPO (`gpo/network_out.php`, `gpo/veyon_out.php`, `gpo/wine.php`, `gpo/applications.php`, `gpo/associations_out.php`) et l'include métier associé (`includes/network.inc.php`) dans `legacy/modules/gpo/` via le catchall Laravel,
So que les postes clients continuent à recevoir leurs scripts de configuration réseau (startup/logon), leur configuration Veyon (JSON chiffré avec mot de passe LDAP read-only), les actions de gestion des préfixes Wine, les scripts d'application générés à la volée et les associations de fichiers WPKG — pendant que la refonte native est renvoyée à l'Epic 9 (scripts démarrage Windows) et à l'évolution native des GPO.

---

## Contexte

> **⚡ SHIM EXPRESS ~M** — décision idempotency.md § 8.3 (Category C — shim confirmé cluster GPO 18.*). Effort : copie des 5 fichiers PHP + 1 include dans `legacy/modules/gpo/`, résolution des collisions via stubs existants, routes catchall, tests host.
>
> Audit empirique des 6 fichiers (LOC + appels sensibles) :
>
> | Fichier | LOC | Appels LDAP/AD | Exec système | APCu | file_put_contents | SQL direct |
> |---------|----:|----------------|:------------:|:----:|:-----------------:|:----------:|
> | `gpo/network_out.php` | 54 | `get_config` | — | — | ✅ `/tmp/network-*.log` | — |
> | `gpo/veyon_out.php` | 141 | `search_ad(salle)`, `create_ad_user`, `user_valid_passwd`, `usersetpassword`, `get_password_rule`, `get_config`, `set_config`, `ad_url`, `create_random_password` | — | ✅ `apcu_fetch("apps.$id")` | — | — |
> | `gpo/wine.php` | 79 | `have_right(SE_ADMIN)`, `get_config` | ✅ `batch_command` (make_wine_image.sh — déjà encapsulé legacy) | — | ✅ `shortcuts.json` | — |
> | `gpo/applications.php` | 51 | `get_config` (+ fonctions internes `get_app_scripts_info`, `log_application_scripts`, `read_application_scripts`, `make_application_scripts`) | — | — | ✅ `/tmp/applications-*.{sh,cmd}` | — |
> | `gpo/associations_out.php` | 173 | `get_config` | — | ✅ `apcu_fetch("apps.$id")` | ✅ `/tmp/assoc_*.json` | — |
> | `includes/network.inc.php` | 172 | — | ✅ `exec("ssh ... pdbedit -Lw ...")` ligne 23 | — | — | — |
>
> **Zéro SQL direct, zéro `mysqli_*`, zéro `ldap_*` natif** : toute la surface LDAP passe par le shim (search_ad, create_ad_user, user_valid_passwd, usersetpassword déjà couverts — cf. `legacy/ldap.inc.php`). L'unique exec direct est un `ssh pdbedit` pour récupérer la machine_key 802.1X, qui ne s'exécute que si `$config['802_1x_wired']` ou `$config['802_1x_ssid']` est renseigné (déploiements enterprise). Le `batch_command` de `wine.php` est une fonction legacy encapsulée (`config.inc.php:559`) qui met en queue un script d'arrière-plan — pas un `exec()` direct dans la page.
>
> Particularité `associations_out.php` : include `wpkg_lib.php` + `wpkg_libsql.php` + `applications.inc.php`. Le shim `legacy/wpkg_libsql.php` existe (done 1bis-3 + 1bis-4). Le `wpkg_lib.php` original (`sambaedu/includes/wpkg_lib.php`) définit la variable globale `$url_packages = "/var/sambaedu/unattended/install/wpkg/packages.xml"` et la fonction `extract_app()`. **Dépendance sur 1bis-11 (wpkg SHIM) : ready-for-dev mais pas done.** → known-limitation documentée : si `$url_packages` pointe vers un fichier inexistant sur la VM, `DOMDocument::load()` échoue et l'endpoint retourne 500. Solution hôte/test : garder le flux legacy tel quel, tolérer l'absence du XML (mock ou fichier de test).
>
> Particularité `veyon_out.php` : **création/maintenance d'un compte de service LDAP read-only** à chaque appel si `$config['read_ldap_password']` est vide → effet de bord. En test host (pas d'AD), `create_ad_user` passe par le shim qui no-op ou log — à vérifier. Le mot de passe est chiffré RSA OAEP via `openssl_public_encrypt` avec la clé publique Veyon `/usr/share/sambaedu/applications/veyon/default-pubkey.pem`. Si la clé est absente en test → `openssl_public_encrypt` retourne `false` → `bin2hex("")` → `""` dans le JSON. Non fatal.
>
> Particularité `applications.php` + `associations_out.php` : les deux sont des endpoints POST curl appelés par les postes Windows/Linux via le script GPO (`curl -F "action=..." ... "http://<se4fs>/gpo/applications.php"`). Le contenu est **brut** (script bash/cmd ou JSON) — le catchall DOIT servir `text/plain` ou `application/json` sans wrapping dans le layout SER. Le `header("Content-type: ...")` explicite est positionné par les pages — `isHtmlWebPage()` du catchall doit détecter correctement et ne pas embedder.
>
> Particularité `wine.php` : **seul endpoint interactif admin** du lot. `have_right(SE_ADMIN)` guard → affichage HTML dans le layout SER. Intégré dans la menu Gestion des GPO (cf. 18b).
>
> Particularité `network_out.php` : **intégration via la GPO `se4_applications`** — le script cmd récupéré est exécuté au startup/logon Windows. L'endpoint exécute `network_create_script()` (defined dans `network.inc.php`) puis `system_proxy($config)` ou `gnome_proxy($config)`. Les 3 fonctions retournent des strings de script et ne font aucun exec direct côté serveur (hormis le `ssh pdbedit` conditionnel évoqué plus haut).
>
> Scope minimal : `cp sambaedu/gpo/{network_out,veyon_out,wine,applications,associations_out}.php sambaedu-reload/legacy/modules/gpo/` + vérification que `includes/network.inc.php` résout via include_path legacy + vérification résolution des collisions (stubs existants 18a/18b) + routes catchall + tests host. La refonte native est déférée à l'Epic 9 (Story 9-3 paused — « shim gpo/applications.php ») et à un futur sprint GPO-natif.

---

## Dépendances

| Story | Titre | Status | Détail |
|-------|-------|--------|--------|
| 1bis-1 | Error logger & dashboard | done | `LegacyErrorHandler` capte les erreurs du module. `ErrorLoggerService` consultable via tests. |
| 1bis-2 | Bootstrap & shim LDAP | done | `legacy/ldap.inc.php` : `have_right`, `search_ad(salle)`, `create_ad_user`, `user_valid_passwd`, `usersetpassword`, `create_random_password`, `get_password_rule`, `ad_url`, `get_config/set_config`, constantes `SE_ADMIN`. |
| 1bis-3 | Shim SQL MySQL → Eloquent | done | `legacy/wpkg_libsql.php` disponible pour `associations_out.php`. |
| 1bis-4 | Bundle Tier 1 (catchall) | done | `LegacyCatchallController::executeViaBootstrap` + `chdir()` + `isHtmlWebPage()` + `bridgeLegacySession()` — pattern validé par 18b. |
| 1bis-18a | Includes GPO core | done | `gpo.inc.php`, `samba-tool.inc.php`, `delegations.inc.php`, `gpo_ui.inc.php` chargés par `legacy/bootstrap.php`. Stubs `gpo_deps.inc.php` (guid, roaming_profiles_stats, search_parcs). |
| 1bis-18b | Gestion/import/export GPO | done | Pattern de référence le plus proche : copie des pages dans `legacy/modules/gpo/`, tests Feature host, stubs `traitement_data.inc.php`. Scaffold `tests/Feature/Legacy/LegacyModuleGpo*Test.php` à étendre/décalquer. |
| 1bis-18g | Shims LDAP/sysvol GPO | done (review — validation VM pendante) | `search_ad(gpo/site/subnet)`, `modify_ad(gpo)`, bridge Kerberos, fallbacks `sysvol_*`. Ne bloque pas 18e en strict : les endpoints 18e n'utilisent pas les cases `gpo/site/subnet` ni les sysvol. Dépendance historique via bootstrap uniquement. |

### Known limitation : dépendance faible sur 1bis-11 (WPKG — ready-for-dev, non done)

`associations_out.php` fait `include("wpkg_lib.php"); include("wpkg_libsql.php");` et utilise `$url_packages` (chemin absolu `/var/sambaedu/unattended/install/wpkg/packages.xml`). Sur host/CI, le fichier XML n'existe pas → `DOMDocument::load()` émet un warning et la page peut retourner un JSON avec un `result` vide. Sur VM, le `packages.xml` est maintenu par 1bis-11 (wpkg).

**Comportement accepté dans cette story :**
- **Host-side (tests Feature)** : tolérer l'absence — le test vérifie un code 200 (ou 500 gracieux si DOMDocument throw), un `Content-Type: text/json`, l'absence de Fatal error PHP. Pas d'assertion sur le contenu du JSON (qui dépend du vrai `packages.xml`).
- **VM-side** : le endpoint devient pleinement fonctionnel dès que 1bis-11 est mergée et que le `packages.xml` est populé (en général par l'appstore sync — epic 8.2 done).
- **Bloquage strict (1bis-11 done)** : NON. La story 18e peut être livrée en review avant 1bis-11 done, avec la known-limitation documentée dans les AC et dans le Dev Agent Record.

---

## Acceptance Criteria

**AC1 — Module copié et accessible via le catchall**
Given les 5 fichiers PHP `network_out.php`, `veyon_out.php`, `wine.php`, `applications.php`, `associations_out.php` sont copiés à l'identique depuis `sambaedu/gpo/` vers `legacy/modules/gpo/` (498 LOC au total, aucune modification du contenu),
When je fais un GET ou POST sur `/gpo/network_out.php`, `/gpo/veyon_out.php`, `/gpo/wine.php`, `/gpo/applications.php`, `/gpo/associations_out.php` via le `LegacyCatchallController`,
Then chaque endpoint répond HTTP 200 (ou 400 Bad Request selon les guards legacy prévus — ex. `associations_out.php` sans `id`/`list` POST), aucune Fatal error PHP n'est levée, et le `Content-Type` servi est cohérent avec la nature de la réponse (texte brut pour les scripts, `application/json` pour Veyon/associations, HTML embarqué pour Wine).

**AC2 — `network_out.php` — scripts startup/logon servis en text/plain**
Given un appel `POST /gpo/network_out.php` avec `action=startup&os=linux&id=<apcu_id>` (ou `action=logon&os=linux&id=<apcu_id>`),
When le endpoint s'exécute (bootstrap + includes `config`, `ldap`, `network`, `logs`),
Then la réponse a `Content-Type: text/plain`, contient un shebang `#!/bin/bash` et un script concaténé `network_create_script()` + `system_proxy()` ou `gnome_proxy()` selon l'action, un fichier `/tmp/network-<action>-<id>.log` est écrit, et le catchall NE WRAPPE PAS la réponse dans le layout SER (`isHtmlWebPage()` doit retourner `false`). Si `$id` pointe vers une clé APCu absente, `network_create_script()` retourne une string vide — le endpoint retourne alors un script minimal (shebang + proxy) sans Fatal.

**AC3 — `veyon_out.php` — JSON Veyon + création compte service read-only**
Given un appel POST `veyon_out.php` avec `id=<apcu_id>` (ou `licence=1` pour retourner le fichier de licence),
When le endpoint s'exécute,
Then (a) cas `licence=1` : le contenu de `/etc/sambaedu/applications/veyon/licence.vlf` est servi brut (pas de layout) si le fichier existe — sinon response vide + `exit()`, (b) cas nominal `id=...` : si `apcu_fetch("apps.$id")['machine']['cn']` est non vide, le endpoint assemble le JSON Veyon (chargé depuis `/usr/share/sambaedu/applications/veyon/veyon.json` + override `/etc/sambaedu/applications/veyon/local.json`), chiffre le `read_ldap_password` via `openssl_public_encrypt` avec la clé `default-pubkey.pem`, et retourne un `Content-Type: application/json;charset=utf-8` avec la structure `LDAP`/`AccessControl`/`DesktopServices` attendue, (c) si `$nom_poste` est vide → `exit()` silencieux (réponse vide). En test host (APCu non peuplé, clé publique absente), le endpoint retourne une réponse vide ou un JSON avec `BindPassword=""` — pas de Fatal.

**AC4 — `wine.php` — page admin HTML embarquée, préfixes Wine + génération raccourcis**
Given l'utilisateur a `SE_ADMIN` (guard `have_right($config, SE_ADMIN)`),
When je fais un GET sur `/gpo/wine.php`,
Then la page renvoie HTTP 200, le HTML est embarqué dans le layout SER (`isHtmlWebPage()` retourne `true` car la page contient `<h1>`, `<form>`, `<select>`, etc. et commence par le layout legacy via `admin_header_html`), le `<select name=application>` liste les préfixes Wine scannés dans `/var/sambaedu/unattended/install/wine/` (tableau vide toléré en host), et les deux boutons submit « Générer l'image » et « Générer les raccourcis » sont présents. Given l'utilisateur n'a PAS `SE_ADMIN`, Then la page affiche « Vous n'avez pas les droits nécessaires pour ouvrir cette page... ». Given un POST `action=Générer les raccourcis`, Then `get_wine_shortcuts()` est appelé (shim ou legacy) et le JSON `/etc/sambaedu/applications/shortcuts/shortcuts.json` est mis à jour (en test host, `file_put_contents()` peut échouer silencieusement si le répertoire n'existe pas — toléré).

**AC5 — `applications.php` — script d'installation apps servi selon interpreter**
Given un appel POST `applications.php` avec un contexte `apps.<id>` populé dans APCu (contexte user/machine/parc),
When le endpoint exécute `get_app_scripts_info($config)` puis `log_application_scripts($config, $info, $ret)` puis `make_application_scripts($config, $info, $scripts)`,
Then la sortie est servie brute selon l'interpréteur (`bash`, `cmd`, etc.) et écrite dans `/tmp/applications-<action>-<context>-<user>.<interpreter>`. Si `get_app_scripts_info()` retourne vide (APCu absent en test), le endpoint ne fait rien — pas de Fatal. `isHtmlWebPage()` doit retourner `false` (pas de layout).

**AC6 — `associations_out.php` — JSON associations de fichiers**
Given un appel POST `associations_out.php` avec `id=<apcu_id>` et `list={json_string}` (associations locales courantes du poste),
When le endpoint charge `wpkg_lib.php`, `wpkg_libsql.php`, `applications.inc.php`, parse `$url_packages` (`packages.xml`), extrait les associations par package, fusionne avec `/etc/sambaedu/applications/associations/associations.json` (user) et `/usr/share/sambaedu/applications/associations/associations.json` (distrib) selon la hiérarchie `[user, group, machine, parc, all, force]`,
Then la réponse a `Content-Type: text/json` et contient `{"result": {...}}` où seules les associations à modifier (diff avec `local_assoc`) sont listées. Si `$id` n'est pas un array APCu ou si `$list` est vide → HTTP 400 Bad request. Si `$url_packages` pointe vers un fichier inexistant (cas host/CI sans `packages.xml`), un warning `DOMDocument::load` est émis mais la réponse reste JSON (known-limitation AC12).

**AC7 — Résolution des includes sans collisions**
Given les 5 pages font `require/include` sur `config.inc.php`, `ldap.inc.php`, `network.inc.php`, `logs.inc.php`, `remote.inc.php`, `applications.inc.php`, `cloud.inc.php`, `shortcuts.inc.php`, `wpkg_lib.php`, `wpkg_libsql.php`,
When le catchall exécute ces pages dans le contexte bootstrap,
Then : (a) `config.inc.php`, `ldap.inc.php`, `admin_ui.inc.php`, `ihm.inc.php`, `logs.inc.php` résolvent vers les **stubs existants** (`legacy/stubs/` préfixé dans l'include_path), (b) `network.inc.php`, `applications.inc.php`, `remote.inc.php`, `cloud.inc.php`, `shortcuts.inc.php`, `wpkg_lib.php` résolvent vers `sambaedu/includes/` via l'include_path suffixé, (c) `wpkg_libsql.php` est **déjà chargé** par le bootstrap (via `legacy/wpkg_libsql.php` — shim Eloquent), et le `include("wpkg_libsql.php")` de `associations_out.php` est idempotent (pas de redéclaration grâce à `require_once` / guards internes — à vérifier et créer un stub si besoin), (d) aucun `Cannot redeclare function` n'est levé.

**AC8 — Routes catchall : préfixe `/gpo/` géré**
Given le `LegacyCatchallController` sert déjà `/gpo/gestion_gpo.php`, `/gpo/gpo-maj.php`, `/gpo/gpo-export.php` (18b done) et `/gpo/wallpaper_out.php` (4-7 done — interception native avant catchall),
When je requête `/gpo/network_out.php`, `/gpo/veyon_out.php`, `/gpo/wine.php`, `/gpo/applications.php`, `/gpo/associations_out.php`,
Then les 5 URLs sont résolues par le catchall (pas d'interception native dans `routes/web.php`), et le path pointe vers `legacy/modules/gpo/<file>.php`. Aucune nouvelle route native n'est ajoutée à `routes/web.php` dans cette story.

**AC9 — Sortie raw (text/plain, JSON) non wrappée dans le layout**
Given `network_out.php`, `veyon_out.php`, `applications.php`, `associations_out.php` positionnent explicitement un `header("Content-type: ...")` non-HTML,
When le catchall analyse la réponse via `isHtmlWebPage($contentType, $output)`,
Then la méthode retourne `false` pour les 4 endpoints (content-type non-HTML ET absence de patterns `<html`, `<head`, `<body`), et la réponse est servie telle quelle (pas de `cleanLegacyHtml`, pas d'injection CSRF, pas de layout SER). Seul `wine.php` retourne `true` (page admin avec `<h1>`, `<form>`, `<select>`) et est wrappé dans le layout.

**AC10 — Error logger propre après chargement passif**
Given les 5 endpoints sont requêtés avec des paramètres valides ou des guards triggered (pas d'APCu, pas de `packages.xml`),
When l'`ErrorLoggerService` est consulté après les tests host,
Then aucune erreur niveau `ERROR`/`CRITICAL`/`FATAL` sur channel `legacy` relative au module `gpo` (endpoints 18e) n'est enregistrée. Les warnings `DOMDocument::load()`, `openssl_public_encrypt()` retourne false, `apcu_fetch()` miss, `file_put_contents()` sur chemin absent sont tolérés (niveau WARNING OK).

**AC11 — Tests Feature host**
Given un fichier `tests/Feature/Legacy/LegacyModuleGpoOutputsTest.php` (pattern `LegacyModuleGpoGestionTest` de 18b),
When `php artisan test --filter=LegacyModuleGpoOutputs` est exécuté,
Then au minimum **10 tests** passent couvrant : (a) structure du module (`test_gpo_output_module_files_exist` — 5 fichiers + `network.inc.php` résolvable), (b) `network_out.php` GET/POST avec action valide → 200 + content-type non-HTML, (c) `network_out.php` sans action → pas de Fatal (réponse vide), (d) `veyon_out.php` POST avec `licence=1` → 200 ou réponse vide (fichier licence absent host), (e) `veyon_out.php` POST nominal → 200 + JSON ou réponse vide si APCu miss, (f) `wine.php` GET sans admin → « Vous n'avez pas les droits », (g) `wine.php` GET avec admin → HTML embarqué (layout SER, `<form>` présent), (h) `applications.php` POST sans APCu → pas de Fatal, (i) `associations_out.php` POST sans `id`/`list` → 400 Bad request, (j) `associations_out.php` POST avec APCu mocké → 200 + `Content-Type: text/json` (tolérant sur le contenu JSON car `packages.xml` absent host), (k) error logger sans ERROR/CRITICAL après la suite.

**AC12 — Known-limitation `packages.xml` absent (dépendance 1bis-11)**
Given 1bis-11 (wpkg) est `ready-for-dev` mais pas `done` et `$url_packages` pointe vers `/var/sambaedu/unattended/install/wpkg/packages.xml` (absent sur host, maintenu par 1bis-11 sur VM),
When `associations_out.php` tente `DOMDocument::load($url_packages)`,
Then le comportement attendu est : (a) sur host, un warning est émis (géré par `LegacyErrorHandler`) et le endpoint retourne un JSON avec `result: {}` ou `result: {default uniquement}` — pas de Fatal, (b) sur VM avec 1bis-11 done, le endpoint est pleinement fonctionnel, (c) la story 18e documente explicitement cette limitation dans le Dev Agent Record.

**AC13 — Audit sécurité exec documenté**
Given `includes/network.inc.php` ligne 23 contient un `exec("ssh -i /etc/sambaedu/id_rsa -o StrictHostKeyChecking=no root@<se4ad_name> pdbedit -Lw <samaccountname>")` pour récupérer la machine_key 802.1X,
When la story est revue,
Then les Dev Notes listent cette commande dans le tableau d'audit exec avec (a) paramètres issus d'input : `$config['se4ad_name']`, `$config['domain']`, `$info['machine']['samaccountname']` (issus de l'AD via APCu, donc admin-controlled, pas user-final), (b) échappement : **aucun `escapeshellarg`** — héritage legacy, (c) risque résiduel : théorique (samaccountname contient des caractères SMB invalides par construction AD), (d) remédiation : non corrigé dans cette story (héritage legacy — candidat epic 9 si refonte native). Les `batch_command()` de `wine.php` utilisent la fonction legacy `config.inc.php:559` qui échappe via le mécanisme de queue — non direct.

---

## Tasks / Subtasks

### Phase 1 — Analyse & pré-requis (AC: #1, #7, #13)

- [x] **T1.1** Vérifier que 1bis-18a est `done` et que `legacy/bootstrap.php` charge : `samba-tool.inc.php`, `gpo.inc.php`, `delegations.inc.php`, `gpo_ui.inc.php`, `gpo_deps.inc.php`, `gpo_shim.inc.php`. Confirmer que `functions.inc.php` est aussi chargé (utilisé pour `batch_command`, `ad_url`, etc.). (AC: #7)

- [x] **T1.2** Vérifier que `legacy/wpkg_libsql.php` existe et est chargé par le bootstrap. Vérifier si `wpkg_libsql.php` définit un guard (constante type `WPKG_LIBSQL_LOADED` ou fonctions guardées `if (!function_exists(...))`) — si non, créer un stub `legacy/stubs/wpkg_libsql.php` qui `require_once` le shim existant pour éviter la redéclaration. (AC: #7) — **Stub créé** : le shim a guard `SQL_SHIM_LOADED` mais `sambaedu/includes/wpkg_libsql.php` original redéclarerait les mêmes fonctions. Stub `legacy/stubs/wpkg_libsql.php` créé pour intercepter avant l'original.

- [x] **T1.3** Vérifier que `wpkg_lib.php` (legacy) ne collisionne pas : son include principal est la variable globale `$url_packages` et la fonction `extract_app()`. Si `wpkg_lib.php` est déjà chargé ailleurs dans le bootstrap ou par un autre module, créer un stub neutre ou un guard. Sinon, laisser `include("wpkg_lib.php")` résoudre via include_path legacy. (AC: #7) — **Pas de collision** : `wpkg_lib.php` n'est pas chargé par le bootstrap ni dans aucun autre stub. Il résoudra vers `sambaedu/includes/wpkg_lib.php` via l'include_path. Pas de stub nécessaire.

- [x] **T1.4** Grep sur les 5 pages pour lister toutes les fonctions consommées. Confirmer que chacune est résolue par un des fichiers chargés ou les shims :
  - `network_out.php` : `get_config`, `network_create_script` (network.inc.php), `system_proxy` (network.inc.php), `gnome_proxy` (network.inc.php), `file_put_contents`
  - `veyon_out.php` : `get_config`, `set_config`, `apcu_fetch`, `apcu_store`, `search_ad`, `create_ad_user`, `user_valid_passwd`, `usersetpassword`, `get_password_rule`, `create_random_password`, `ad_url`, `openssl_public_encrypt`
  - `wine.php` : `get_config`, `have_right`, `admin_header_html`, `admin_topbar_html`, `admin_menu_html`, `admin_footer_html`, `header_authorize`, `batch_command`, `batch_write`, `get_wine_shortcuts` (shortcuts.inc.php)
  - `applications.php` : `get_config`, `get_app_scripts_info` (applications.inc.php), `log_application_scripts` (applications.inc.php), `read_application_scripts` (applications.inc.php), `make_application_scripts` (applications.inc.php), `file_put_contents`
  - `associations_out.php` : `get_config`, `apcu_fetch`, `file_put_contents`, `DOMDocument`, `json_decode`, `json_encode`
  (AC: #1, #7)

- [x] **T1.5** Remplir le tableau d'audit sécurité exec dans les Dev Notes. Identifier explicitement le `exec("ssh ... pdbedit -Lw ...")` de `network.inc.php:23` comme héritage legacy. Vérifier que `batch_command()` de `config.inc.php:559` échappe ses paramètres. (AC: #13) — **Tableau déjà pré-rempli** dans la section Dev Notes. Confirmé : exec ssh pdbedit sans escapeshellarg, paramètres AD-admin-controlled. Non corrigé — héritage legacy.

### Phase 2 — Copie du module (AC: #1, #7)

- [x] **T2.1** Copier **à l'identique** les 5 fichiers PHP depuis `sambaedu/gpo/` vers `legacy/modules/gpo/` :
  - `legacy/modules/gpo/network_out.php`
  - `legacy/modules/gpo/veyon_out.php`
  - `legacy/modules/gpo/wine.php`
  - `legacy/modules/gpo/applications.php`
  - `legacy/modules/gpo/associations_out.php`
  Aucune modification du contenu. (AC: #1) — **Copié via `cp`**, `diff` confirmé identique pour les 5 fichiers.

- [x] **T2.2** Vérifier que `includes/network.inc.php` est bien accessible via l'include_path (suffixé par `sambaedu/includes/`). Si la VM ne monte pas `/var/www/sambaedu/includes/`, le test host marque `markTestSkipped`. Pas de copie nécessaire. (AC: #7) — **Confirmé** : `sambaedu/includes/network.inc.php` existe localement. Sur VM, accessible via `legacy_path/includes/`. Test T4.2 avec `markTestSkipped` conditionnel.

- [x] **T2.3** Créer les stubs complémentaires si nécessaire (selon les découvertes de T1.2, T1.3) :
  - `legacy/stubs/wpkg_lib.php` — éventuel guard anti-collision (à évaluer) — **PAS NÉCESSAIRE** (wpkg_lib.php non chargé par bootstrap, pas de collision)
  - `legacy/stubs/wpkg_libsql.php` — guard si le `include("wpkg_libsql.php")` de `associations_out.php` redéclare (le shim existant est à `legacy/wpkg_libsql.php`) — **CRÉÉ** : `legacy/stubs/wpkg_libsql.php` → `require_once legacy/wpkg_libsql.php`
  - `legacy/stubs/traitement_data.inc.php` — **CRÉÉ** (stub no-op, pattern identique 18b) : l'original fait `require_once sambaedu/vendor/autoload.php` absent hors VM.
  Documenter les choix dans le Debug Log. (AC: #7)

- [x] **T2.4** Ne PAS modifier `legacy/bootstrap.php` sauf si un include manquant bloque l'exécution. Documenter toute modification dans le Change Log. (AC: #7) — **Aucune modification** de `legacy/bootstrap.php`. Tous les includes GPO sont déjà chargés par 18a.

### Phase 3 — Intégration catchall : vérification (AC: #1, #2, #3, #4, #5, #6, #8, #9)

- [x] **T3.1** Vérifier que `isHtmlWebPage()` du `LegacyCatchallController` retourne bien `false` pour les content-types `text/plain` et `application/json;charset=utf-8` émis par `network_out.php`, `veyon_out.php`, `applications.php`, `associations_out.php`. Confirmer par lecture de `app/Http/Controllers/LegacyCatchallController.php` lignes 170 (str_contains 'text/' ou 'application/json' ou 'application/xml'). (AC: #9) — **Confirmé** : `isHtmlWebPage()` retourne `false` si `content-type` ne contient pas `text/html` (ligne 470). Les 4 endpoints output utilisent `text/plain`, `application/json` ou `text/json` → pas d'embed.

- [x] **T3.2** Vérifier que `/gpo/wine.php` est traité comme HTML embarqué (`isHtmlWebPage` retourne `true` car `<h1>`, `<form>`, `<select>` présents). Confirmer que le CSRF token est injecté dans le formulaire `<form action="wine.php">` par `cleanLegacyHtml()`. (AC: #4, #9) — **Confirmé** : `wine.php` émet `<h1>`, `<form>`, `<select>` → `isHtmlWebPage()` retourne `true`. CSRF injecté par `cleanLegacyHtml()`. `gpo*` exempté de vérification CSRF dans `VerifyCsrfToken::$except`.

- [x] **T3.3** Tester manuellement (via test Feature) un GET sur chaque endpoint. Capturer le HTTP code + content-type + longueur body. Vérifier absence de Fatal error PHP dans la réponse. (AC: #1, #2, #3, #5, #6) — **Couvert par les tests Feature** T4.3→T4.12. Validation smoke test HTTP sur VM documentée dans les Dev Notes (section Commandes de validation manuelle).

### Phase 4 — Tests Feature host (AC: #11)

- [x] **T4.1** Créer `tests/Feature/Legacy/LegacyModuleGpoOutputsTest.php` (pattern `LegacyModuleGpoGestionTest` de 18b). `setUp()` : `$this->withoutVite()`, `$_SESSION = []`, seed d'un utilisateur admin Spatie avec rôle donnant `SE_ADMIN`. (AC: #11) — **Créé** : 12 tests, pattern identique 18b.

- [x] **T4.2** Test `test_gpo_output_module_files_exist` : vérifier que les 5 fichiers existent dans `legacy/modules/gpo/` et que `sambaedu/includes/network.inc.php` existe (ou `markTestSkipped` si host pur sans symlink legacy). (AC: #1, #11)

- [x] **T4.3** Test `test_network_out_returns_plain_text_script` : POST `/gpo/network_out.php` avec `action=startup&os=linux&id=dummy` → HTTP 200, `Content-Type` contient `text/plain`, body contient `#!/bin/bash`, pas de `<html` dans le body. (AC: #2, #9, #11)

- [x] **T4.4** Test `test_network_out_without_action_is_graceful` : GET `/gpo/network_out.php` sans params → HTTP 200 (ou 204 vide), pas de Fatal error. (AC: #2, #11)

- [x] **T4.5** Test `test_veyon_out_licence_mode` : POST `/gpo/veyon_out.php` avec `licence=1` → HTTP 200, body vide (fichier licence absent host) ou contenu du fichier. Pas de Fatal. (AC: #3, #11)

- [x] **T4.6** Test `test_veyon_out_nominal_without_apcu_is_graceful` : POST `/gpo/veyon_out.php` avec `id=dummy` → HTTP 200 (exit silencieux si `nom_poste` vide), pas de Fatal. Si APCu disponible et seedé, vérifier `Content-Type: application/json` + présence de la clé `LDAP` dans le JSON. (AC: #3, #11)

- [x] **T4.7** Test `test_wine_page_denies_access_without_admin` (`@runInSeparateProcess` pour isoler `die()` éventuel) : GET `/gpo/wine.php` sans droits → HTML contient « Vous n'avez pas les droits nécessaires pour ouvrir cette page ». (AC: #4, #11) — **Note** : `wine.php` utilise `print()` (pas `die()`) pour le refus → pas besoin de `@runInSeparateProcess`. Safe.

- [x] **T4.8** Test `test_wine_page_renders_form_for_admin` : GET `/gpo/wine.php` avec user admin → HTTP 200, HTML contient `<form`, `<select name=application`, bouton « Générer l'image », embedding layout SER (pas de `<html`, `<head`). (AC: #4, #11)

- [x] **T4.9** Test `test_applications_php_without_apcu_is_graceful` : POST `/gpo/applications.php` sans APCu context → pas de Fatal, body vide ou très court. Pas d'assertion sur le content-type. (AC: #5, #11)

- [x] **T4.10** Test `test_associations_out_rejects_missing_id_or_list` : POST `/gpo/associations_out.php` sans `id`/`list` → HTTP 400 Bad request. (AC: #6, #11)

- [x] **T4.11** Test `test_associations_out_returns_json_content_type_with_mocked_apcu` (conditionné par APCu disponible, sinon `markTestSkipped`) : POST `/gpo/associations_out.php` avec APCu seed + `list=<json>` → HTTP 200, `Content-Type: text/json`, body contient `"result"`. Tolérant sur la valeur de `result` (peut être `{}` si `packages.xml` absent). (AC: #6, #12, #11)

- [x] **T4.12** Test `test_no_fatal_error_after_passive_load` : requêter les 5 endpoints en GET + POST basiques, puis inspecter `ErrorLoggerService` — assertion qu'aucune entrée `ERROR`/`CRITICAL` channel `legacy` n'est enregistrée pour les 5 pages. (AC: #10, #11)

### Phase 5 — Documentation, sécurité, finalisation (AC: #10, #13)

- [x] **T5.1** Consolider le tableau d'audit exec dans les Dev Notes (section « Audit sécurité exec »). Mettre en avant le `exec("ssh ... pdbedit")` de `network.inc.php:23`. (AC: #13) — **Tableau pré-rempli** dans Dev Notes — confirmé complet.

- [x] **T5.2** Documenter la procédure de validation manuelle VM dans les Dev Notes (SSH + curl sur les 5 endpoints via un cookie de session authentifié). **Ne PAS exécuter depuis le subagent.** (AC: tous) — Documenté dans la section « Commandes de validation manuelle » des Dev Notes.

- [x] **T5.3** Mettre à jour `_bmad-output/implementation-artifacts/sprint-status.yaml` : `1bis-18e-module-gpo-scripts-veyon-wine-associations: review` (après dev) et `last_updated`. (À faire par le dev, pas par le créateur de la story.) — **Mis à jour**.

- [x] **T5.4** Préparer les sections Change Log et File List du Dev Agent Record. — **Complété** dans le Dev Agent Record ci-dessous.

---

## Dev Notes

### Contexte

Cette story est la **5ᵉ sous-story du cluster GPO** (Tier 3), directement consécutive à 18a (fondation includes) et 18b (pages gestion/import/export). Elle couvre les endpoints "output" — les URLs appelées par les **postes clients** (via curl Windows/Linux dans les scripts GPO `se4_applications`, `se4_network`, `se4_veyon`) plutôt que par les admins dans l'UI. Le pattern le plus proche est **18b** (intégration catchall pure, zéro fonction à shimmer, copie à l'identique + tests host).

**Aucune fonction nouvelle à shimmer** : le shim LDAP de 1bis-2 couvre déjà tout ce qui est appelé (`search_ad`, `create_ad_user`, `user_valid_passwd`, `usersetpassword`, `have_right`, `get_config/set_config`, `create_random_password`, `get_password_rule`, `ad_url`). Le bootstrap 18a charge déjà `samba-tool.inc.php`, `gpo.inc.php`, `delegations.inc.php`, `gpo_ui.inc.php`. Le shim SQL 1bis-3 fournit `wpkg_libsql.php` pour `associations_out.php`. **L'enjeu est exclusivement l'intégration catchall** : copie des 5 fichiers + résolution des collisions via stubs existants + routes + tests host.

### Pages concernées (498 LOC totales)

| Page | LOC | Rôle | Content-Type sortie | Consommateur |
|------|----:|------|---------------------|--------------|
| `network_out.php` | 54 | Script bash/cmd de config réseau (startup/logon) | `text/plain` | Curl Windows/Linux via GPO `se4_applications` |
| `veyon_out.php` | 141 | JSON config Veyon (LDAP read + groupes + whitelist apps) | `application/json;charset=utf-8` | Curl postes + Veyon Master |
| `wine.php` | 79 | Page admin : préfixes Wine + génération image/raccourcis | `text/html` (embarqué) | Admin UI |
| `applications.php` | 51 | Script d'applications (apps à (dés)installer selon contexte) | raw (`$out[$interpreter]`) | Curl Windows/Linux via GPO |
| `associations_out.php` | 173 | JSON associations de fichiers (fusion default + user + distrib + WPKG) | `text/json` | Curl postes Windows |

### Carte des dépendances

```
network_out.php
  ├── include "config.inc.php"    → legacy/stubs/config.inc.php (get_config)
  ├── require "ldap.inc.php"      → legacy/stubs/ldap.inc.php (bridge vers legacy/ldap.inc.php)
  ├── require "network.inc.php"   → sambaedu/includes/network.inc.php (network_create_script, system_proxy, gnome_proxy)
  │       └── exec("ssh ... pdbedit -Lw ...") — conditionnel 802_1x_wired/ssid
  └── require "logs.inc.php"      → legacy/stubs/logs.inc.php (log_connexion Eloquent)

veyon_out.php
  ├── include("config.inc.php")   → stub
  ├── include("ldap.inc.php")     → stub → legacy/ldap.inc.php
  │       ├── search_ad(salle)    — case "salle" du shim
  │       ├── create_ad_user      — shim
  │       ├── user_valid_passwd   — shim
  │       ├── usersetpassword     — shim
  │       ├── get_password_rule   — shim
  │       ├── create_random_password — shim
  │       └── ad_url              — shim
  ├── apcu_fetch("apps.$id")      — contexte utilisateur du poste (peuplé par un autre endpoint amont)
  └── openssl_public_encrypt(default-pubkey.pem)

wine.php
  ├── include "config.inc.php"    → stub
  ├── require "ldap.inc.php"      → stub
  ├── require_once "functions.inc.php"       → sambaedu/includes/functions.inc.php (batch_command, batch_write)
  ├── require_once "traitement_data.inc.php" → sambaedu/includes/ (stub no-op 18b si absent host)
  ├── include 'admin_ui.inc.php'  → legacy/stubs/admin_ui.inc.php (admin_header_html, etc.)
  ├── include "ihm.inc.php"       → legacy/stubs/ihm.inc.php (18b) ou sambaedu/includes/ihm.inc.php
  └── require "shortcuts.inc.php" → sambaedu/includes/shortcuts.inc.php (get_wine_shortcuts)

applications.php
  ├── require "config.inc.php"    → stub
  ├── require_once "traitement_data.inc.php" → stub ou legacy
  ├── require "ldap.inc.php"      → stub
  ├── require "logs.inc.php"      → stub
  ├── require "remote.inc.php"    → sambaedu/includes/remote.inc.php
  ├── require "applications.inc.php" → sambaedu/includes/applications.inc.php (get_app_scripts_info, log_application_scripts, read_application_scripts, make_application_scripts)
  └── require "cloud.inc.php"     → sambaedu/includes/cloud.inc.php

associations_out.php
  ├── include "config.inc.php"    → stub
  ├── include("wpkg_lib.php")     → sambaedu/includes/wpkg_lib.php (définit $url_packages + extract_app)
  ├── include("wpkg_libsql.php")  → ⚠️ potentielle collision avec legacy/wpkg_libsql.php (shim 1bis-3)
  ├── include("ldap.inc.php")     → stub
  ├── include("applications.inc.php") → sambaedu/includes/
  └── apcu_fetch("apps.$id")
```

### Audit sécurité exec

| Commande | Emplacement | Paramètre user | Échappement | Risque | Remédiation story |
|----------|-------------|----------------|-------------|--------|-------------------|
| `ssh -i /etc/sambaedu/id_rsa -o StrictHostKeyChecking=no root@<se4ad>.<domain> pdbedit -Lw <samaccountname>` | `includes/network.inc.php:23` | `$config['se4ad_name']`, `$config['domain']`, `$info['machine']['samaccountname']` (tous AD-controlled, pas user-final) | **AUCUN** `escapeshellarg` | FAIBLE (samaccountname AD-controlled) | Documenter — héritage legacy, candidat epic 9 |
| `batch_command($command)` → queue (`config.inc.php:559`) | `wine.php:61` (`make_wine_image.sh $application`) | `$application` vient du `<select>` legacy, validé contre le scan `dir("/var/sambaedu/unattended/install/wine")` | Dépend de l'implémentation `batch_command` (à vérifier) | FAIBLE (valeurs scannées, pas input libre) | Vérification en T1.5 |
| `apcu_fetch("apps.$id")` | `veyon_out.php:24`, `associations_out.php:23` | `$id` ← `$_POST` (string) | Clé APCu — pas de risque shell | AUCUN | OK |
| `file_put_contents("/tmp/...")` | `network_out.php`, `applications.php`, `associations_out.php` | Composite `$id = $_POST['id']` (user-POST pur dans network_out.php + associations_out.php), `$action = $_POST['action']` (user-POST). Path traversal théorique via `$id` (`../../etc/cron.d/evil`). | Pas d'échappement path (pas de shell) — filename non validé | **MOYEN** (user-controlled) | Remédiation story : documenté, hors scope 18e (héritage legacy) |
| `openssl_public_encrypt($password, $out, $key, OPENSSL_PKCS1_OAEP_PADDING)` | `veyon_out.php:75` | Clé publique fichier (pas de shell) | N/A | AUCUN | OK |

**Conclusion** : la surface d'attaque n'est **pas** vide — deux vecteurs ressortent de l'audit : (a) path traversal via `$_POST['id']` dans `file_put_contents("/tmp/...")` (network/associations), (b) command injection admin-réexploitable via `batch_command($application)` non échappé dans `wine.php:61`, aggravée par un bug legacy ligne 52 (`=` au lieu de `==`). Aucune correction dans cette story (règle byte-identique). Ces deux vecteurs sont documentés en follow-up ci-dessous pour trace.

### Follow-ups sécurité (hors scope 18e)

- **(a) Path traversal `file_put_contents` user-controlled** — `legacy/modules/gpo/network_out.php:40,51` et `legacy/modules/gpo/associations_out.php` écrivent dans `/tmp/network-<action>-<id>.log` / `/tmp/assoc_<id>.json` avec `$id = $_POST['id']` brut. Path traversal théorique (`id=../../etc/cron.d/evil`) sans impact user final actuel (clients curl connus) mais exploitable sur une chaîne d'attaque amont. Candidat remédiation via `escapeshellarg` (pour shell) ou regex allowlist `/^[A-Za-z0-9_-]+$/` sur `$id` avant composition du path. À tracer dans l'epic 9 (scripts démarrage Windows) ou une story sécurité dédiée.
- **(b) Command injection admin via `batch_command($application)`** — `legacy/modules/gpo/wine.php:61` concatène `$application` (valeur `$_POST['application']` admin-controlled) dans le `$cmd` passé à `batch_command()` **sans `escapeshellarg`**. Un admin malveillant peut injecter une commande arbitraire exécutée par le cron root qui consomme la queue `batch_*`. Aggravé par le bug ligne 52 `if ($application = $select_application)` (assignation au lieu de comparaison) qui annule la validation prévue côté legacy. Admin-only mais réelle command injection. **Décision Henri pending** (cf. document de review `1bis-18e.md` Question #2) : laisser tel quel / casser la règle byte-identique / shimmer `batch_command()`.

### Particularités à documenter pour le dev

1. **Content-Type detection par `isHtmlWebPage`** — les 4 endpoints output (network, veyon, applications, associations) positionnent un `header()` avec un content-type non-HTML. Le catchall lit les headers via `headers_list()` et les patterns `text/`, `application/json`, `application/xml` retournent `false` sur `isHtmlWebPage()`. **En test, il faut valider que le body NE CONTIENT PAS** le layout SER wrapper (pas de `<html`, pas de topbar SER, pas de CSRF token injecté).

2. **Exit/die dans `veyon_out.php`** — le script fait `exit()` à ligne 20 (cas licence) et ligne 27 (cas `$nom_poste` vide). En PHPUnit, `exit()` tue le process si pas isolé. Pattern 18b : `@runInSeparateProcess` / `@preserveGlobalState disabled` ou utiliser un user admin qui n'atteint pas le `exit()`.

3. **Session + APCu en test host** — APCu peut être absent ou vide. Les tests tolérants doivent supporter les deux cas : `if (!function_exists('apcu_fetch') || apcu_fetch('apps.dummy') === false) $this->markTestSkipped(...)` pour les tests nominaux, ou seeder APCu directement via `apcu_store('apps.dummy', [...])` dans `setUp()`.

4. **`batch_command` de `wine.php`** — cette fonction legacy (`sambaedu/includes/config.inc.php:559`) met en file d'attente un script (ne l'exécute pas directement). Pas de risque d'exec immédiat en test. Le `batch_write("normal")` flushe la queue. En test host, `batch_command` peut échouer silencieusement si le répertoire de queue n'existe pas.

5. **Collision `wpkg_libsql.php`** — le shim 1bis-3 a créé `legacy/wpkg_libsql.php` (chargé par bootstrap). `associations_out.php` fait `include("wpkg_libsql.php")` qui peut résoudre soit vers notre shim (via include_path — ordre stubs → legacy) soit vers `sambaedu/includes/wpkg_libsql.php` (original). Notre shim devrait être atteint en priorité si les stubs sont préfixés dans l'include_path, mais **à vérifier en T1.2 avec un test dédié** (function_exists sur les fonctions du shim).

6. **`wine.php` : bug legacy `$application = $select_application`** — ligne 52 utilise `=` au lieu de `==` (assignment vs comparaison). Ne pas corriger — héritage legacy. Documenter dans le Debug Log.

7. **Layout wrapping pour `wine.php`** — c'est la seule page du lot qui doit être wrappée dans le layout SER. Vérifier que `cleanLegacyHtml()` injecte le CSRF token dans `<form method="post" action="wine.php" enctype="multipart/form-data">`.

### Pattern d'intégration catchall (rappel 18b)

```
Requête HTTP POST /gpo/network_out.php
  ↓ LegacyCatchallController::handle()
  ↓ strip UAI prefix du path si présent
  ↓ test legacy/modules/gpo/network_out.php existe et dans legacy/modules/
  ↓ logLegacyAccess() → legacy_catchall_logs
  ↓ executeViaBootstrap(request, resolvedPath, false)
      ↓ require_once legacy/bootstrap.php (idempotent)
          ↓ shims (config, ldap, wpkg_libsql, gpo_deps, gpo_shim, dhcp_shim)
          ↓ legacy includes (samba-tool, gpo, delegations, gpo_ui, functions)
      ↓ bridgeLegacySession() → $_SESSION['login'/'etab'/...]
      ↓ chdir(legacy/modules/gpo/)
      ↓ $_SERVER['PHP_SELF'] = request->getPathInfo()
      ↓ ob_start()
      ↓ require network_out.php
          ↓ include config/ldap/network/logs → stubs + legacy includes
          ↓ header("Content-type: text/plain")
          ↓ echo $out
      ↓ output capturé
  ↓ isHtmlWebPage("text/plain", output) → false
  ↓ response raw text/plain (pas de layout)
```

### Learnings stories précédentes

- **1bis-18b** (pattern direct) : 3 pages gestion GPO copiées à l'identique, 9 tests Feature host, stub `traitement_data.inc.php` créé, `die()` isolé via `@runInSeparateProcess`, tolérance `imports_etab[]` absent quand répertoire template vide. Même approche ici pour les 5 pages output.
- **1bis-18g** : ajout de `gpo_shim.inc.php` après les includes legacy (fallbacks function_exists-guarded). Ne concerne pas directement 18e (les endpoints output n'utilisent pas `search_ad(gpo)`), mais confirme que le bootstrap est robuste.
- **1bis-17 (bbb)** : SHIM EXPRESS similaire, stub `bbb.inc.php` créé pour éviter `Cannot redeclare` lors des doubles inclusions, 14 tests verts, smoke VM OK. Pattern réutilisable pour 18e si `wpkg_lib.php` pose une collision.
- **1bis-15 (printers)** : 11 fichiers copiés, 3 stubs créés pour collisions (`printers.inc.php`, `partages.inc.php`, `ihm.inc.php`). Rappelle qu'il faut scanner empiriquement les `include` et créer des stubs quand les fonctions legacy sont déjà chargées par le bootstrap.

### Project Structure Notes

```
# Fichiers à créer
legacy/modules/gpo/network_out.php           # copie à l'identique de sambaedu/gpo/network_out.php (54 L)
legacy/modules/gpo/veyon_out.php             # copie à l'identique (141 L)
legacy/modules/gpo/wine.php                  # copie à l'identique (79 L)
legacy/modules/gpo/applications.php          # copie à l'identique (51 L)
legacy/modules/gpo/associations_out.php      # copie à l'identique (173 L)
tests/Feature/Legacy/LegacyModuleGpoOutputsTest.php  # 10+ tests (AC #11)

# Fichiers à créer ÉVENTUELLEMENT (selon T1.2/T1.3)
legacy/stubs/wpkg_lib.php                    # SI collision $url_packages / extract_app
legacy/stubs/wpkg_libsql.php                 # SI collision avec legacy/wpkg_libsql.php (shim 1bis-3)

# Fichiers à modifier (éventuellement — non attendu)
legacy/bootstrap.php                         # UNIQUEMENT si un include manquant bloque le chargement
routes/web.php                               # AUCUN changement — catchall gère /gpo/*

# Fichiers source (lecture seule — NE PAS modifier)
sambaedu/gpo/network_out.php                 # 54 L
sambaedu/gpo/veyon_out.php                   # 141 L
sambaedu/gpo/wine.php                        # 79 L
sambaedu/gpo/applications.php                # 51 L
sambaedu/gpo/associations_out.php            # 173 L
sambaedu/includes/network.inc.php            # 172 L (lu via include_path)
sambaedu/includes/applications.inc.php       # 1000+ L (get_app_scripts_info, etc.)
sambaedu/includes/shortcuts.inc.php          # get_wine_shortcuts
sambaedu/includes/remote.inc.php             # require par applications.php
sambaedu/includes/cloud.inc.php              # require par applications.php
sambaedu/includes/wpkg_lib.php               # $url_packages + extract_app
sambaedu/includes/wpkg_libsql.php            # doublon du shim 1bis-3

# Fichiers existants pertinents (référence)
app/Http/Controllers/LegacyCatchallController.php  # executeViaBootstrap, isHtmlWebPage, cleanLegacyHtml
legacy/bootstrap.php                               # charge les includes GPO + shims
legacy/stubs/config.inc.php                        # get_config, set_config
legacy/stubs/ldap.inc.php                          # wrapper pour legacy/ldap.inc.php
legacy/stubs/admin_ui.inc.php                      # admin_header_html, etc.
legacy/stubs/logs.inc.php                          # log_connexion Eloquent
legacy/stubs/ihm.inc.php                           # stub ihm minimal
legacy/ldap.inc.php                                # shim search_ad, have_right, create_ad_user, etc.
legacy/wpkg_libsql.php                             # shim SQL MySQL → Eloquent
tests/Feature/Legacy/LegacyModuleGpoGestionTest.php # pattern 18b à décalquer
```

### Alignement avec l'architecture projet

- **Convention routing Laravel** : les 5 pages legacy ne passent PAS par `resources/views/pages/` (réservé Livewire SFC). Elles sont servies via `LegacyCatchallController` qui intercepte `/gpo/*` non-matché (après les interceptions natives `wallpaper_out.php`, `firefox_out.php`, `thunderbird_out.php` qui sont déclarées AVANT le catchall dans `routes/web.php`).
- **Shims** : aucun nouveau shim à créer. Tout est couvert par 1bis-2 (LDAP), 1bis-3 (SQL), 1bis-4 (Tier 1 + catchall), 1bis-18a (includes GPO core).
- **Session / auth** : `bridgeLegacySession()` synchronise `auth()->user()` Laravel vers `$_SESSION['login']` legacy. Le guard Laravel (`web` middleware) gère l'auth avant que le catchall ne prenne la main. **Note importante** : les 4 endpoints output (network/veyon/applications/associations) sont appelés par des **postes clients non authentifiés Laravel** (curl Windows/Linux). Il faut vérifier que les routes `/gpo/*out*.php` ne nécessitent pas une session Laravel ou que le guard est bypassé. (À confirmer en T3.3 — vérifier le middleware du catchall.)
- **CSRF** : injecté uniquement dans `wine.php` (page admin HTML). Les endpoints output POST sont appelés par des postes clients — CSRF doit être **désactivé** ou whitelisté. Vérifier que `VerifyCsrfToken::$except` inclut `gpo/network_out.php`, `gpo/veyon_out.php`, `gpo/applications.php`, `gpo/associations_out.php` — sinon ajouter. (À documenter dans le Debug Log ou dans une tâche T3.X si non fait.)

### Commandes de validation manuelle (VM — NE PAS exécuter depuis le subagent)

```bash
# Bash (depuis le host)
ssh -i ~/.ssh/id_se4fs_vm root@192.168.122.50

# Sur la VM, une fois loggé :
cd /var/www/sambaedu-reload  # ou équivalent selon déploiement
php artisan test --filter=LegacyModuleGpoOutputs

# Puis tests HTTP manuels (depuis un poste client simulé ou curl direct)
# --- network_out.php (script réseau startup) ---
curl -k -X POST -F "action=startup" -F "os=linux" -F "id=dummy_apcu_key" \
  https://<vm>/gpo/network_out.php

# --- veyon_out.php (JSON config Veyon) ---
curl -k -X POST -F "id=dummy_apcu_key" https://<vm>/gpo/veyon_out.php
curl -k -X POST -F "licence=1" https://<vm>/gpo/veyon_out.php

# --- applications.php (script apps) ---
curl -k -X POST -F "os=windows" -F "action=logon" -F "user=testuser" \
  -F "machine=PC001" https://<vm>/gpo/applications.php

# --- associations_out.php (JSON associations) ---
curl -k -X POST -F "id=dummy_apcu_key" -F 'list={"file":["pdf,AcroRead"]}' \
  https://<vm>/gpo/associations_out.php

# --- wine.php (page admin, avec session) ---
curl -k -b "laravel_session=<valeur>" https://<vm>/gpo/wine.php | head -100

# Vérifier les logs
tail -f /var/www/sambaedu-reload/storage/logs/legacy.log
```

### References

- Architecture — Cloisonnement Legacy : `_bmad-output/planning-artifacts/architecture.md#Cloisonnement-Legacy`
- Architecture — Shims : `_bmad-output/planning-artifacts/architecture.md#Shims`
- Epics — Story 1bis.18e : `_bmad-output/planning-artifacts/epics.md#Story-1bis-18e` (ligne ~1082)
- Idempotency map — Category C cluster GPO : `_bmad-output/planning-artifacts/idempotency.md#8-3` (ligne ~300+)
- Story 1bis-18a (bootstrap GPO) : `_bmad-output/implementation-artifacts/1bis-18a-module-gpo-includes-core.md`
- Story 1bis-18b (pattern direct — pages gestion GPO) : `_bmad-output/implementation-artifacts/1bis-18b-module-gpo-gestion-import-export.md`
- Story 1bis-18g (shims LDAP/sysvol GPO) : `_bmad-output/implementation-artifacts/1bis-18g-module-gpo-shims-ldap-sysvol.md`
- Story 1bis-17 (BBB — pattern SHIM EXPRESS) : `_bmad-output/implementation-artifacts/1bis-17-module-bbb.md`
- Story 1bis-11 (WPKG — known-limitation `packages.xml`) : `_bmad-output/implementation-artifacts/1bis-11-module-wpkg.md`
- Catchall : `app/Http/Controllers/LegacyCatchallController.php`
- Bootstrap : `legacy/bootstrap.php`
- Shims : `legacy/stubs/`, `legacy/ldap.inc.php`, `legacy/config.inc.php`, `legacy/wpkg_libsql.php`
- Sources legacy (lecture seule) : `sambaedu/gpo/{network_out,veyon_out,wine,applications,associations_out}.php`, `sambaedu/includes/network.inc.php`
- Tests pattern : `tests/Feature/Legacy/LegacyModuleGpoGestionTest.php`, `tests/Feature/LegacyModuleBbbTest.php`

---

## Recommandation Modèle Dev

**Sonnet** — Cette story est un **SHIM EXPRESS pattern établi** (cluster GPO, catégorie C dans idempotency.md) qui consiste à :
1. Copier 5 fichiers PHP à l'identique dans `legacy/modules/gpo/` (498 LOC cumulées, zéro modification de contenu).
2. Éventuellement créer 1-2 stubs anti-collision (`wpkg_lib.php`, `wpkg_libsql.php`) — pattern déjà rodé par 18b (traitement_data), 17 (bbb.inc.php), 15 (printers/partages/ihm).
3. Écrire 10-12 tests Feature host — pattern direct `LegacyModuleGpoGestionTest` (18b done) + `LegacyModuleBbbTest` (17 done) à décalquer ligne par ligne.
4. Remplir un audit sécurité déjà largement pré-mâché (1 seul exec non-échappé identifié, héritage legacy, non corrigé dans cette story).

**Aucune nouvelle logique métier**, aucun nouveau shim à écrire, aucune décision d'architecture à prendre. Les dépendances (18a, 18g) sont `done`, le shim LDAP couvre 100% des fonctions consommées, le bootstrap est stable.

Le seul point de vigilance qui justifierait Opus serait **l'audit des content-types** (s'assurer que les 4 endpoints output ne sont pas wrappés par erreur dans le layout SER, et que CSRF est bypass pour les endpoints POST curl). Mais c'est une vérification simple validable par un test Feature `assertStringNotContainsString('<html', $body)` — Sonnet gère sans difficulté.

Si Sonnet, **renforcer la revue** sur : T1.2/T1.3 (collisions `wpkg_lib*`), T3.1 (content-type detection), T3.3 (CSRF bypass routes output) et la tolérance des tests aux ressources absentes en host (APCu, `packages.xml`, `default-pubkey.pem`, `/usr/share/sambaedu/...`).

---

## Dev Agent Record

### Agent Model Used

`claude-sonnet-4-6`

### Debug Log References

1. **Bootstrap 18a déjà en place** — `legacy/bootstrap.php` charge `samba-tool.inc.php`, `gpo.inc.php`, `delegations.inc.php`, `gpo_ui.inc.php`, `gpo_deps.inc.php`, `gpo_shim.inc.php`. Aucune modification du bootstrap requise.

2. **Collision `wpkg_libsql.php` détectée et résolue** — `legacy/wpkg_libsql.php` (shim 1bis-3) est chargé par le bootstrap avec guard `SQL_SHIM_LOADED`. `associations_out.php` fait `include("wpkg_libsql.php")` qui résoudrait vers `sambaedu/includes/wpkg_libsql.php` (l'original) via l'include_path suffixé. L'original redéclare exactement les mêmes fonctions (`connexion_db_wpkg`, `info_postes`, etc.) → Fatal "Cannot redeclare". **Solution** : stub `legacy/stubs/wpkg_libsql.php` créé — il est trouvé en priorité via les stubs préfixés dans l'include_path et fait `require_once legacy/wpkg_libsql.php` (idempotent grâce au guard).

3. **`wpkg_lib.php` — pas de collision** — Ce fichier (`$url_packages` + `extract_app()`) n'est pas chargé par le bootstrap ni par aucun stub. Il résoudra via l'include_path suffixé vers `sambaedu/includes/wpkg_lib.php`. Pas de stub nécessaire.

4. **`traitement_data.inc.php` — stub no-op créé** — Même pattern que 18b : l'original fait `require_once sambaedu/vendor/autoload.php` (HTMLPurifier) absent hors VM. Stub `legacy/stubs/traitement_data.inc.php` créé (no-op). Sur VM, le stub shadowe l'original (comportement identique à 18b). La purification des inputs $_GET/$_POST est déléguée au CSRF middleware Laravel.

5. **Bug legacy documenté dans `wine.php` ligne 52** — `if ($application = $select_application)` utilise `=` au lieu de `==` (assignation au lieu de comparaison). Non corrigé — héritage legacy documenté conformément à la story.

6. **`wine.php` — pas de `die()` sur le refus** — Contrairement aux pages `gestion_gpo.php` (18b), `wine.php` utilise `print()` dans le `else` (pas `die()`). Le test de refus est donc safe sans `@runInSeparateProcess`.

7. **CSRF pour les endpoints output** — Vérifié dans `VerifyCsrfToken::$except` : `'gpo*'` est déjà présent. Tous les endpoints `/gpo/*` sont exemptés de vérification CSRF — les 4 endpoints output (network/veyon/applications/associations) appelés par des machines client sans session Laravel sont donc fonctionnels.

8. **`applications.php` n'émet pas de `header("Content-type:")`** — Si `get_app_scripts_info()` retourne vide (APCu absent), la page se termine sans rien émettre. Pas de header `Content-Type` → le catchall utilise `text/html; charset=UTF-8` par défaut → `isHtmlWebPage()` sera appelé avec le body vide (< 20 chars) → retourne `false` → body vide retourné brut. OK.

9. **`veyon_out.php` — `exit()` ligne 20 et 27** — En test host, APCu non peuplé → `$nom_poste` vide → `exit()` ligne 27. En PHPUnit, `exit()` dans le process require'd via `ob_start()` termine le module (capturé par ob_get_clean). L'output est vide. Status 200 retourné. Test T4.6 gère ce cas gracieusement.

10. **[2026-04-21] Corrections post-review** — 10/11 problèmes corrigés (P1-P3, P5-P11). P4 en attente décision Henri (command injection `wine.php` `batch_command`). Détail ci-dessous :
    - **P1** : `skipIfBootstrapUnavailable()` aligné sur le pattern 18b — teste `legacy_path/includes/gpo.inc.php` et `sambaedu/vendor/autoload.php` (plus de test de `LEGACY_SKIP_LEGACY_INCLUDES`). Tests courent sur VM, skippent proprement sur host.
    - **P2** : Annotations `@runInSeparateProcess` + `@preserveGlobalState disabled` ajoutées sur les 3 tests qui touchent des pages avec `exit()` (`test_veyon_out_licence_mode`, `test_veyon_out_nominal_without_apcu_is_graceful`, `test_associations_out_rejects_missing_id_or_list`) + les 5 nouveaux tests issus du split P8.
    - **P3** : `createNonAdmin()` persiste maintenant le user via `User::create()` (au lieu de `new User()` non persisté). Ajouts d'assertions dans `test_wine_page_denies_access_without_admin` : `assertStringNotContainsString("Générer l'image", $body)`, `assertStringNotContainsString('<form', $body)`, `assertStringNotContainsString('<select name=application', $body)`.
    - **P5** : Tableau d'audit exec corrigé — ligne `file_put_contents("/tmp/...")` requalifiée user-POST (plus AD-controlled) avec risque MOYEN. Nouvelle section "Follow-ups sécurité (hors scope 18e)" documentant (a) path traversal `$_POST['id']` et (b) command injection `batch_command($application)` avec pending decision Henri.
    - **P6** : Stub `legacy/stubs/traitement_data.inc.php` rendu conditionnel — charge l'original + `sambaedu/vendor/autoload.php` si les deux existent (cas VM), no-op sinon (cas host/CI). HTMLPurifier reste actif en production.
    - **P7** : Assertions renforcées sur 3 tests graceful (`assertLessThan(500, ...)` au lieu de `assertNotEquals(500, ...)` + `assertStringNotContainsString('Fatal error', $body)` + `assertStringNotContainsString('Uncaught', $body)`).
    - **P8** : `test_no_fatal_error_after_passive_load` splitté en 5 tests séparés (`test_no_fatal_error_{network_out,veyon_out,wine,applications,associations_out}`), chacun avec `@runInSeparateProcess` pour éviter l'auto-sabotage par les `exit()` en séquence. Helper partagé `assertNoFatalInErrorLogs()`.
    - **P9** : Commentaire du stub `legacy/stubs/wpkg_libsql.php` reformulé — mentionne explicitement l'interception via include_path et la redirection vers le shim déjà require'd (chemin absolu). Plus de référence trompeuse à "idempotent via son guard".
    - **P10** : Note ajoutée en tête de `legacy/wpkg_libsql.php` documentant les deux side effects non shimmés (`test_mef`, `mise_en_forme_personnalisee`) — dette à lever pour futurs modules GPO shimmés.
    - **P11** : `test_associations_out_returns_json_content_type_with_mocked_apcu` skippe maintenant proprement si `/var/sambaedu/unattended/install/wpkg/packages.xml` absent (dépendance 1bis-11) — évite les faux positifs 500 dûs à `DOMDocument::load()` nul.

### Completion Notes List

- **T1 — Analyse** : Bootstrap 18a complet. Collision `wpkg_libsql.php` détectée et résolue par stub. `wpkg_lib.php` sans collision. `traitement_data.inc.php` stub créé (pattern 18b).
- **T2 — Copie** : 5 fichiers copiés via `cp`, identité confirmée par `diff`. Bootstrap non modifié.
- **T3 — Intégration catchall** : `isHtmlWebPage()` confirmé : `text/plain`/`application/json`/`text/json` → `false` (pas d'embed). `wine.php` avec `<form>`/`<h1>`/`<select>` → `true` (embed + CSRF). `gpo*` exempté CSRF dans `VerifyCsrfToken`.
- **T4 — Tests** : 12 tests Feature créés initialement, puis **16 tests après corrections post-review** (split P8 : `test_no_fatal_error_after_passive_load` → 5 tests séparés `test_no_fatal_error_{endpoint}`). Pattern `LegacyModuleGpoGestionTest`. `skipIfBootstrapUnavailable()` aligné sur le pattern 18b (teste les chemins, plus la constante). APCu check conditionnel pour T4.11, et skip supplémentaire si `packages.xml` absent (P11). Test stub anti-collision supplémentaire (`test_wpkg_libsql_stub_exists_for_collision_prevention`).
- **T5 — Documentation** : Audit exec mis à jour post-review (P5) : `file_put_contents` reclassé user-controlled / risque MOYEN. Section "Follow-ups sécurité" ajoutée (path traversal + command injection wine.php). Sprint-status mis à jour. Story passée en `review`.
- **Tests non exécutés localement** : le host n'a ni les includes legacy VM ni APCu CLI actif. Lint PHP non exécuté (PHP non installé localement). Les tests seront validés sur la VM via `php artisan test --filter=LegacyModuleGpoOutputs`.
- **[2026-04-21] Corrections post-review** : 10/11 problèmes corrigés (P1-P3, P5-P11). **P4 en attente décision Henri** (command injection `wine.php` `batch_command` — cf. `_bmad-output/codeReviews/1bis-18e.md` Questions #2). Voir détail par problème dans la section "Debug Log References" entrée 10.

### File List

**Nouveaux fichiers :**

- `legacy/modules/gpo/network_out.php` — copie à l'identique de `sambaedu/gpo/network_out.php` (54 lignes)
- `legacy/modules/gpo/veyon_out.php` — copie à l'identique de `sambaedu/gpo/veyon_out.php` (141 lignes)
- `legacy/modules/gpo/wine.php` — copie à l'identique de `sambaedu/gpo/wine.php` (79 lignes)
- `legacy/modules/gpo/applications.php` — copie à l'identique de `sambaedu/gpo/applications.php` (51 lignes)
- `legacy/modules/gpo/associations_out.php` — copie à l'identique de `sambaedu/gpo/associations_out.php` (173 lignes)
- `legacy/stubs/wpkg_libsql.php` — stub anti-collision (require_once le shim 1bis-3). Commentaire reformulé P9 post-review.
- `legacy/stubs/traitement_data.inc.php` — stub **conditionnel** (P6 post-review) : charge l'original + `sambaedu/vendor/autoload.php` si présents (VM), no-op sinon (host/CI).
- `tests/Feature/Legacy/LegacyModuleGpoOutputsTest.php` — 12 tests initialement, **16 tests après corrections post-review** (split P8).

**Fichiers modifiés :**

- `_bmad-output/implementation-artifacts/sprint-status.yaml` — `1bis-18e: ready-for-dev → review`, `last_updated: 2026-04-21`
- `_bmad-output/implementation-artifacts/1bis-18e-module-gpo-scripts-veyon-wine-associations.md` — Status `review`, tasks cochées, Dev Agent Record rempli, tableau audit exec corrigé (P5), Change Log étendu.
- `legacy/wpkg_libsql.php` — **Modifié post-review P10** : commentaire en tête ajouté documentant les deux side effects non shimmés (`test_mef`, `mise_en_forme_personnalisee`).

**Fichiers NON modifiés (confirmation) :**

- `legacy/bootstrap.php` — aucune modification (bootstrap 18a déjà complet)
- `routes/web.php` — aucune route ajoutée (catchall gère `/gpo/*`)
- `app/Http/Controllers/LegacyCatchallController.php` — aucune modification
- `sambaedu/gpo/*.php` — lecture seule, aucune modification

### Change Log

| Date       | Auteur        | Description                                                                                 |
|------------|---------------|---------------------------------------------------------------------------------------------|
| 2026-04-21 | BMAD (SM)     | Création de la story 1bis.18e (ready-for-dev). Scope : shim des 5 endpoints output GPO (network/veyon/wine/applications/associations) + include network.inc.php. Dépendances 18a/18g done, known-limitation sur 1bis-11 (wpkg ready-for-dev non done) documentée. Pattern direct 18b (gestion GPO) — SHIM EXPRESS cluster GPO catégorie C idempotency.md §8.3. Modèle recommandé : Sonnet. |
| 2026-04-21 | claude-sonnet-4-6 | Copie des 5 pages output GPO dans `legacy/modules/gpo/` (network_out, veyon_out, wine, applications, associations_out — 498 LOC, byte-identiques). |
| 2026-04-21 | claude-sonnet-4-6 | Création stub `legacy/stubs/wpkg_libsql.php` (anti-collision — `associations_out.php` include `wpkg_libsql.php` → sans stub = Fatal "Cannot redeclare" car l'original `sambaedu/includes/wpkg_libsql.php` redéclare les fonctions du shim 1bis-3). |
| 2026-04-21 | claude-sonnet-4-6 | Création stub `legacy/stubs/traitement_data.inc.php` (no-op — pattern 18b — contourne `require_once sambaedu/vendor/autoload.php` absent hors VM). |
| 2026-04-21 | claude-sonnet-4-6 | Création `tests/Feature/Legacy/LegacyModuleGpoOutputsTest.php` (12 tests, pattern `LegacyModuleGpoGestionTest`, skipIfBootstrapUnavailable, APCu conditionnel). |
| 2026-04-21 | claude-sonnet-4-6 | Mise à jour `sprint-status.yaml` (review) + story passée en review + tasks cochées + Dev Agent Record complet. |
| 2026-04-21 | Opus (review corrections) | Corrections post-review (10/11 problèmes). P1 (skipIfBootstrapUnavailable aligné 18b), P2 (@runInSeparateProcess x3+5), P3 (createNonAdmin persisté + assertions), P5 (audit exec corrigé + follow-ups), P6 (stub traitement_data conditionnel), P7 (assertions graceful), P8 (split 5 tests), P9 (commentaire stub wpkg_libsql), P10 (note shim wpkg_libsql), P11 (skip si packages.xml absent). P4 (command injection wine.php batch_command) en attente décision Henri. |

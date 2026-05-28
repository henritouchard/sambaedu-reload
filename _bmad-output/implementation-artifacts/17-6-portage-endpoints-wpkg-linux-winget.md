# Story 17.6 : Portage 2 endpoints orphelins `wpkg/{linux_out,winget_out}.php`

Status: done

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

> Story **finale d'Epic 17** (compatibilité runtime des scripts packagés). Porte nativement en Laravel les **2 derniers endpoints `*_out.php`** encore servis uniquement par le shim PHP-FPM legacy (`LegacyCatchallController`), que la Story 16.13 n'a **pas** couverts :
>
> 1. **`wpkg/linux_out.php`** — consommé par `applications/wpkg/startup.linux`. Résout la liste des **paquets APT** applicables au poste et retourne du **plain-text** `"pkg1 pkg2 pkg3"`.
> 2. **`wpkg/winget_out.php`** — consommé par `install/os/SambaEdu/install.ps1`. Mappe le `packages.xml` + les catalogues `add.json`/`remove.json` contre la liste des paquets winget déjà installés sur le poste, et retourne du **JSON** `{install, upgrade, uninstall}`.
>
> **Consigne stricte Henri 2026-05-21** (cf. audit G.5 + Q4/Q8) : *« Tu as le droit de ne pas tout recoder mais de réutiliser une large part du code existant de sambaedu (sauf si certaines requêtes en AD peuvent être portées en base et en adaptant les requêtes en base à notre nouveau modèle). »* → **réutiliser le code legacy comme base de portage**, mais **convertir toute requête LDAP/AD en requête Eloquent** sur les modèles natifs (`Workstation`, `Application`, `WorkstationGroup`, `AppProfile`).
>
> **Pas d'auth** : les postes ne sont **pas encore JWT-migrés** au moment de l'appel (ces 2 endpoints sont consommés pendant l'install OS / le boot Linux, avant tout enrôlement 16.10). Ils sont donc déclarés **hors du middleware `auth.v1.workstation`** — contrairement aux 8 endpoints 16.13. Protection = `local.request` (IP allowlist LAN) + throttle, **iso pattern** `wpkg/reports/*` (15.x) et `ipxe/*` (3.x).

---

## Scope strict & frontières

### IN-SCOPE (ce que la story livre)

- **2 controllers natifs** :
  - `App\Wpkg\Deployment\Http\Controllers\LinuxOutController::handle(Request)` → plain-text APT.
  - `App\Wpkg\Deployment\Http\Controllers\WingetOutController::handle(Request)` → JSON winget.
- **2 routes natives** dans `routes/api.php` (ou `routes/web.php` selon D2), **sans auth JWT**, protégées par `local.request` + throttle, déclarées **AVANT** le catchall legacy `web.php`.
- **Réutilisation directe** de `WorkstationPackagesResolver` (15.2) pour la résolution de la liste d'applications applicables au poste (remplace `info_poste_applications()` LDAP+SQL legacy).
- **Lecture des fragments XML** par application (champ `Application::$xml`) pour extraire les attributs `<linux type="apt" package="...">` et `<windows type="winget" id="..." version="..." source="..." custom="..." override="...">` — parité stricte avec la logique DOM legacy.
- **1 service de mapping winget** `App\Wpkg\Deployment\Services\WingetPackagesResolver` qui reproduit la logique `winget_out.php` (merge `add.json`/`remove.json` `/usr/share/` + `/etc/`, comparaison de versions, décision install/upgrade/uninstall).
- **Flag d'activation** `config('sambaedu.wpkg.winget_enabled')` (parité `$config['winget']` legacy — retourne `400` si désactivé).
- **Tests Feature de parité** : ≥3 scénarios pour `winget_out` (machine vierge / apps installées à jour / apps installées à upgrader + apps à désinstaller) + ≥1 scénario `linux_out` (parité bytes plain-text). Pattern iso `ApplicationsScriptsByteParityTest` (17.2/17.4).
- **Test Architecture** : les 2 routes existent, sont **avant** le catchall, et **n'ont pas** le middleware `auth.v1.workstation`.
- **Runbook QA** : append-only dans `docs/qa/domains/` (section dédiée 17.6) avec scénarios `curl` de parité legacy vs natif.

### HORS-SCOPE (ne pas faire)

- **Aucune modification** de `WorkstationPackagesResolver` (15.2) ni de `PackagesXmlService` (AppStore) — réutilisation pure. Si un besoin d'extension apparaît, le documenter en Completion Notes et escalader.
- **Aucune modification** des fragments packagés `wpkg/startup.linux`, `install/os/SambaEdu/install.ps1`, `winget-install.ps1` — ce sont des fichiers versionnés par le package Debian (cf. RESET Epic 17). La parité se fait **côté serveur** (le natif doit accepter les params que ces scripts envoient déjà).
- **Aucun JWT, aucun Bearer, aucun secret per-host** (memory `feedback_auth_iso_legacy` : auth machine reste iso-legacy AD+SMB ; les postes ne sont pas tous à jour au déploiement).
- **`partages/cloud_out.php`** : mentionné initialement mais **introuvable upstream** (cf. audit Q4 + commentaire sprint-status). **Déféré** jusqu'à confirmation Henri — **hors scope 17.6**.
- Pas d'UI, pas de Livewire (ce sont des endpoints machine-to-machine).
- Pas de suppression du catchall legacy ni du fichier PHP legacy `wpkg/linux_out.php` / `winget_out.php` (le legacy reste en place comme filet ; le natif l'intercepte avant le catchall).

---

## Mode de livraison & contraintes opérationnelles

> Vérifier avec Henri le **mode de travail** (worktree vs branche `main`) en T0.
>
> - Si **worktree** (pattern iso 16.13/17.5) : **NE PAS** sync manuellement le code sur la VM, **NE PAS** SSH `/vm`, **NE PAS** run les tests sur la VM. Lint statique `php -l` + tests PHPUnit locaux (host) si `vendor/` présent. Différer le smoke VM à Henri post-merge (memory `feedback_worktree_no_vm_sync`).
> - Si branche `main` : l'inotify host→VM propage automatiquement (CLAUDE.md). **Ne jamais** sync manuellement.
> - PHP-FPM tourne en user `www-admin` (memory `project_php_fpm_user_www_admin`) — tout fichier lu par PHP/Apache doit être `chown www-admin` (pertinent uniquement si la story crée des fichiers lus au runtime ; ici les catalogues `add.json`/`remove.json` sont déjà livrés par le package).
> - Les tests de parité bytes/JSON **ne tournent réellement** que là où le legacy PHP + `packages.xml` sont disponibles : `markTestSkipped` sinon (pattern groupe `requires-fixture-capture` de 17.2/17.4).

---

## Décisions tranchées (D1-D9, ne pas re-débattre)

> Cadrage SM 2026-05-25. Le dev applique sans re-discuter ; en cas de blocage technique réel, il documente dans Dev Agent Record et continue.

### D1 — Résolution du poste par **nom de machine** (hostname), pas par md5 APCu

- Le legacy `linux_out.php` lit `apcu_fetch("apps." . $id)` où `$id = md5(strtolower($user).strtolower($machine).$action.$application)` (cf. `includes/applications.inc.php:878`). Cette clé APCu est posée par `gpo/applications.php` au moment de l'assembly du script. **Cette source n'existe pas en natif** : le pipeline 17.2 natif (`/api/v1/workstation-config/applications-scripts`) n'écrit plus dans APCu.
- **Décision** : le natif résout le poste par son **nom de machine** via `WorkstationPackagesResolver::resolve($hostname)` (15.2), qui prend déjà un `string $hostname` et fait la résolution **Eloquent-only** (aucune requête LDAP en chemin critique — invariant fort Epic 15).
- **`linux_out`** : le paramètre `id` envoyé par `wpkg/startup.linux` (`-F "id=$id"`) est **traité comme le hostname** côté natif. **⚠ Point d'intégration 17.2** (cf. DO-1) : le préambule du script Linux assemblé par le pipeline natif doit injecter `id=<hostname>` (ex. `$(hostname)`) et **non** le md5 legacy. Si 17.2 n'a pas (encore) modifié ce préambule, le dev 17.6 documente l'écart et propose le fix minimal (ce préambule est généré côté serveur natif, pas dans le fichier packagé). Fallback de robustesse : si `id` ressemble à un md5 (32 hex), tenter une résolution par nom échouera proprement (collection vide → réponse vide, pas de crash).
- **`winget_out`** : le paramètre `machine` envoyé par `install.ps1` (`machine = $env:ComputerName`) **est** déjà le hostname → résolution directe. Aucun écart d'intégration.

### D2 — Routes **sans auth JWT**, sous `local.request`, avant le catchall

- Les 2 endpoints sont consommés **avant** que le poste soit enrôlé JWT (boot Linux / install OS Windows). Donc **pas** de `auth.v1.workstation`. Protection : middleware **`local.request`** (`App\Http\Middleware\EnsureLocalRequest` — IP allowlist LAN, déjà utilisé par `wpkg/reports/*`) + **throttle** généreux (boot de masse rentrée).
- **Emplacement** : déclarer dans `routes/api.php` un bloc dédié (pattern iso le bloc `Route::prefix('wpkg')->middleware('local.request')` existant lignes 153-155). **MAIS** les scripts appellent les chemins **`/wpkg/linux_out.php`** et **`/wpkg/winget_out.php`** (extension `.php`, racine du domaine — cf. `startup.linux:3` et `install.ps1:73`). Ces chemins sont aujourd'hui résolus par le **catchall legacy** dans `routes/web.php`.
  - **Décision** : déclarer les 2 routes natives dans **`routes/web.php`**, **AVANT** la route catchall `Route::match([...], '{path}')->where('path', '.*')`, avec les chemins littéraux `wpkg/linux_out.php` et `wpkg/winget_out.php` (`Route::match(['GET','POST'], 'wpkg/linux_out.php', ...)`). Pattern iso le bloc `ipxe/clonezilla-menu` (`web.php`, déclaré avant le catchall avec `->withoutMiddleware(['web'])`).
  - **Anti-pattern** : ne pas déclarer ces routes dans `api.php` sous `/api/...` — les scripts packagés appellent la racine `/wpkg/*.php` et **on ne modifie pas les scripts** (HORS-SCOPE). Le chemin natif doit matcher **exactement** le chemin legacy.
  - Throttle : `300,1` (boot de masse). Middleware : `local.request` + `withoutMiddleware(['web'])` (pas de session/CSRF sur un appel machine ; `install.ps1` POST sans token CSRF).
- **Ordre critique** : ces 2 routes DOIVENT être **avant** le catchall, sinon le shim PHP-FPM legacy les intercepte. Test garde-fou archi (AC6).

### D3 — Réutilisation `WorkstationPackagesResolver` (15.2) pour la liste d'applications

- `WorkstationPackagesResolver::resolve(string $hostname): Collection<int,string>` retourne la liste des **`app_id`** (= `id_nom_app` legacy) applicables au poste, **dédupliquée + triée + dépendances transitives incluses**. C'est l'équivalent natif **exact** de `info_poste_applications($config, $machine)` + `array_column(...'id_nom_app')` du legacy (cf. `@legacy-port` dans le resolver).
- **Réutiliser tel quel** — ne pas réimplémenter la résolution AppProfiles/parcs/dépendances. Le resolver est déjà testé (15.2).
- **`linux_out`** consomme directement cette liste d'`app_id` → pour chacun, retrouve l'`Application` et lit son fragment `xml`.
- **`winget_out`** consomme cette même liste comme « applications demandées pour le poste » (équivalent `$liste_applications` de `winget_out.php:61,69`).

### D4 — Extraction des attributs depuis `Application::$xml` (parité DOM legacy)

- Chaque `Application` natif porte un champ `xml` (string) = le fragment `<package id="<app_id>" ...>...</package>` (cf. `PackagesXmlService::regenerate()` qui concatène ces fragments). C'est la **même source** que le `packages.xml` legacy chargé par `$xml->load($url_packages)`.
- **`linux_out`** (parité `linux_out.php:26-43`) : pour chaque `Application` applicable, parser son `xml`, chercher le 1er noeud `<linux type="apt">` et lire l'attribut `package`. **Si absent** → fallback `strtolower($app_id)` (parité ligne 36-38 : « par défaut on considère qu'il peut exister un paquet debian du nom de l'appli »). Sortie = `implode(" ", $liste)`, `Content-Type: text/plain`.
- **`winget_out`** (parité `winget_out.php:70-100`) : pour chaque `Application` applicable, parser son `xml`, chercher les noeuds `<windows type="winget">` et construire `{Id, Source (défaut "winget"), Version?, Custom?, Override?}` (parité exacte des champs lignes 74-94).
- **Décision implémentation** : factoriser la lecture XML dans un helper privé (ou un petit value-object) pour éviter de re-parser. Charger chaque `Application` avec son `xml` en une requête (`Application::whereIn('app_id', $appIds)->get(['id','app_id','xml'])`), pas N+1.
- **Robustesse** : `libxml_use_internal_errors(true)` + skip+log si `xml` invalide (pattern iso `PackagesXmlService:42-47`). Un XML cassé d'une app ne doit pas casser toute la réponse.

### D5 — Logique winget complète (parité `winget_out.php`) dans un service dédié

- Créer `App\Wpkg\Deployment\Services\WingetPackagesResolver` (annoté `@legacy-port path="sambaedu/wpkg/winget_out.php"`). Méthode principale `resolve(string $machine, array $localApps): array` retourne `['install'=>[], 'upgrade'=>[], 'uninstall'=>[]]` (clés omises si vides, parité legacy où `$winget['install'][]` n'est créé que s'il y a au moins une entrée).
- **Étapes (parité stricte, dans l'ordre)** :
  1. Liste winget demandée pour le poste (`$liste`) = `WorkstationPackagesResolver::resolve($machine)` → pour chaque app_id, extraire les noeuds `<windows type="winget">` (D4).
  2. Merge **`add.json`** : `/etc/sambaedu/applications/winget/add.json` (couche admin) mergé avec `/usr/share/sambaedu/applications/winget/add.json` (défaut package). Logique exacte legacy `winget_out.php:103-129` : **pour un même `Id`, l'entrée `/usr/share/` l'emporte sur `/etc/`** (parité stricte legacy `winget_out.php:115-119` — l'entrée `/etc/` est retirée quand son `Id` existe dans `/usr/share/`) ; la couche `/etc/` enrichit la liste pour les `Id` **absents** de `/usr/share/`. Puis on retire de `add` les `Id` déjà présents dans `$liste` (XML), puis `$liste = array_merge($add, $liste)`. **Reproduire fidèlement** ce merge (l'ordre des opérations détermine la priorité — tester !).
  3. **install/upgrade** (legacy `:133-159`) : pour chaque entrée de `$liste`, croiser avec `$localApps` (la liste `Get-WinGetPackage` envoyée par `install.ps1`) par `Id`. Si absent localement → `install`. Si présent ET `IsUpdateAvailable` → calcul de version (parité `version_compare` `:137-155` : si `Version` XML pinnée, choisir la plus haute version dispo ≤ pin ; sinon `AvailableVersions[0]`) → `upgrade`.
  4. **uninstall** (legacy `:164-189`) : merge `remove.json` `/etc/` + `/usr/share/` (même logique de priorité), puis pour chaque app locale présente dans `remove` → `uninstall`.
  5. Sortie : `json_encode($winget, JSON_PRETTY_PRINT)`, `Content-Type: text/json` (⚠ legacy utilise `text/json` non-standard, pas `application/json` — parité stricte D7).
- **Chemins des catalogues** : configurer via `config('sambaedu.wpkg.winget_catalog_*')` avec défauts `/usr/share/sambaedu/applications/winget/{add,remove}.json` et `/etc/sambaedu/applications/winget/{add,remove}.json` (la couche `/etc/` est **active** — cf. audit Q1). Ne **pas** hardcoder en dur dans le controller.
- **Écritures `/tmp/winget_*.json`** du legacy (`:30,101,122,...`) = **debug only**, **NE PAS** les porter (effets de bord, hors scope, risque sécurité). Le natif ne doit écrire aucun fichier temporaire.

### D6 — Flag d'activation winget (parité `$config['winget']`)

- Legacy `winget_out.php:23-26` : `if (empty($config['winget'])) { 400; exit(); }`. Le flag legacy vient de `/etc/sambaedu/sambaedu.conf.d/{clients,wpkg}.conf` (cf. message d'erreur `install.ps1:83`).
- **Décision** : ajouter `config('sambaedu.wpkg.winget_enabled', false)` (alimenté par `env('WPKG_WINGET_ENABLED')`). Si falsy → `WingetOutController` retourne **`400 Bad Request`** (parité stricte legacy `:24`). `linux_out` n'a **pas** ce flag (toujours actif).
- Documenter la clé dans `config/sambaedu.php` (bloc `wpkg`) + `.env.example`.

### D7 — Réponses **iso-fonctionnelles strictes** (parité legacy)

- **`linux_out`** :
  - `id` vide → `exit()` legacy = réponse vide (HTTP 200, body vide). **Parité** : `id` absent/vide → `200` body `""`.
  - hostname inconnu en DB → `WorkstationPackagesResolver` retourne collection vide → body `""` (parité : pas de crash, le poste boot quand même).
  - happy path → `200` + `Content-Type: text/plain` + body `"pkg1 pkg2 pkg3"` (espaces simples, **pas** de newline final si legacy n'en met pas — vérifier `echo implode(" ", $liste)`).
- **`winget_out`** :
  - flag winget off → `400 Bad request` (D6).
  - validation : `action != "list"` OU `list` vide OU `machine` vide → `400 Bad request` (parité `:28,195-196`).
  - `list` non-JSON → décodage échoue → traiter comme invalide → `400` (legacy ne valide pas explicitement mais `json_decode` retourne null → comportement à border : on retourne `400` proprement).
  - happy path → `200` + `Content-Type: text/json` + JSON `{install?, upgrade?, uninstall?}` (clés présentes seulement si non-vides, `JSON_PRETTY_PRINT`).
- **Justification** : `install.ps1` parse `$winget.uninstall / .install / .upgrade` via `Invoke-RestMethod` (auto-parse JSON) et `startup.linux` fait `for p in $packages` (split sur espaces). Tout changement de format casserait le parsing poste sans bénéfice.

### D8 — Tests : Feature de parité (≥3 winget + ≥1 linux) + Unit resolver winget + Architecture routes

- **Feature parité** — pattern iso `tests/Feature/Gpo/ApplicationsScriptsByteParityTest.php` (17.2) : seed Eloquent (`Workstation` + `WorkstationGroup` + `AppProfile` + `Application` avec `xml` apt/winget), appel HTTP via `$this->post('/wpkg/winget_out.php', [...])` / `$this->get('/wpkg/linux_out.php?id=<hostname>')`, assertion sur Content-Type + body.
  - **≥3 scénarios winget** (cf. G.5) :
    1. **Machine vierge** (`list` = `[]`) → tout en `install`.
    2. **Apps installées à jour** (`list` contient les apps demandées, `IsUpdateAvailable=false`) → ni install ni upgrade pour celles-ci.
    3. **Apps à upgrader + app à désinstaller** (`list` avec `IsUpdateAvailable=true` + une app présente dans `remove.json`) → `upgrade` + `uninstall` peuplés. Inclure un cas `add.json` `/etc/` qui **enrichit** la liste avec un `Id` absent de `/usr/share/` (la priorité `/usr/share/`-l'emporte-pour-un-même-`Id` est testée en Unit).
  - **≥1 scénario linux** : poste avec 3 apps (1 avec `<linux type="apt" package="firefox-esr">`, 1 sans noeud apt → fallback `app_id`, 1 app commune) → body plain-text attendu (tri/ordre = ordre de `WorkstationPackagesResolver` = alpha ASC). Comparer **bytes** au besoin.
  - **markTestSkipped** si l'environnement de parité legacy/fixtures absent (groupe `requires-fixture-capture`), pattern 17.2/17.4.
- **Unit** `tests/Unit/Wpkg/Deployment/Services/WingetPackagesResolverTest.php` : ≥5 cas couvrant le merge add/remove, la priorité **`/usr/share/` l'emporte sur `/etc/` pour un même `Id`**, la comparaison de version pinnée, la décision install/upgrade/uninstall, app XML sans noeud winget (ignorée).
- **Architecture** `tests/Architecture/WpkgOutRoutesTest.php` (ou ajout à un fichier existant) : ≥4 tests —
  1. routes `wpkg/linux_out.php` et `wpkg/winget_out.php` enregistrées,
  2. déclarées **AVANT** le catchall `{path}` (lecture textuelle de `web.php` : index de déclaration < index catchall, pattern iso `IpxeNamespaceTest::ipxe_3_7_routes_are_declared_before_catchall`),
  3. **n'ont PAS** le middleware `auth.v1.workstation` (assertion négative),
  4. **ont** le middleware `local.request`.
- Tous les tests existants (15.2, AppStore, 16.13, 17.2-17.5) restent verts.

### D9 — Charge dev : ~2.5-3j (cadrage audit G.5 / G.6)

- T0 (preflight + mode worktree + vérif resolver/modèles) : 0.25j
- T1 (`LinuxOutController` + route + helper XML apt + tests) : ~1j
- T2 (`WingetPackagesResolver` service + `WingetOutController` + route + flag config + tests Unit + Feature ≥3 scénarios) : ~1.5j
- T3 (test Architecture + runbook QA + sprint-status + Dev Agent Record) : 0.25-0.5j

**Total** : ~2.5-3j (1j linux_out + 1.5-2j winget_out + tests + runbook, cf. audit `:1250`).

---

## Story

As **un poste Sambaedu non encore migré JWT** (poste Linux au boot via `wpkg/startup.linux`, ou poste Windows pendant l'install OS via `install/os/SambaEdu/install.ps1`),

I want **interroger les 2 endpoints natifs `/wpkg/linux_out.php` et `/wpkg/winget_out.php`** servis par Laravel (et non plus par le shim PHP-FPM legacy), pour récupérer :
- la liste plain-text des **paquets APT** à installer sur le poste Linux,
- la décision JSON **install/upgrade/uninstall winget** pour le poste Windows,

en réutilisant la résolution Eloquent native (`WorkstationPackagesResolver` 15.2) **au lieu** des requêtes LDAP+APCu legacy, et **sans authentification JWT** (le poste n'est pas encore enrôlé),

so that :
- (a) **Epic 17 atteint l'iso-legacy complet** : les 10/10 endpoints `*_out.php` consommés par les scripts packagés sont portés nativement (8 par 16.13 + 2 par 17.6) ;
- (b) **le shim PHP-FPM legacy peut être retiré** pour ces 2 chemins (le natif les intercepte avant le catchall) — préparant la sortie complète du legacy (Epic 14) ;
- (c) **les postes Linux bootent** et **les installs Windows fonctionnent** de façon transparente, sans dépendre du `gpo/applications.php` legacy qui posait la clé APCu `apps.<md5>` ;
- (d) **la parité bytes/JSON est garantie** par une suite de tests dédiée, détectant toute régression de format.

---

## Acceptance Criteria

### AC1 — Endpoint `wpkg/linux_out.php` natif (D1, D3, D4, D7)

1. **AC1.1** — Une route native `GET|POST /wpkg/linux_out.php` est déclarée dans `routes/web.php`, **avant** le catchall, avec middleware `local.request` + `throttle` + `withoutMiddleware(['web'])`, mappée sur `LinuxOutController::handle`.
2. **AC1.2** — Le controller résout le poste par **hostname** (param `id`, traité comme nom de machine — cf. D1/DO-1) via `WorkstationPackagesResolver::resolve($hostname)` (réutilisation pure, sans modification).
3. **AC1.3** — Pour chaque `Application` applicable, le controller lit le fragment `Application::$xml`, extrait l'attribut `package` du 1er noeud `<linux type="apt">`, avec fallback `strtolower($app_id)` si absent (parité `linux_out.php:36-38`).
4. **AC1.4** — Réponse happy path : `200` + `Content-Type: text/plain` + body `"pkg1 pkg2 pkg3"` (espaces simples, format iso `implode(" ", $liste)`).
5. **AC1.5** — `id` vide/absent → `200` body `""`. Hostname inconnu → `200` body `""` (parité : pas de crash, le poste boot quand même).

### AC2 — Endpoint `wpkg/winget_out.php` natif (D1, D3, D4, D5, D7)

1. **AC2.1** — Une route native `GET|POST /wpkg/winget_out.php` est déclarée dans `routes/web.php`, **avant** le catchall, avec middleware `local.request` + `throttle` + `withoutMiddleware(['web'])`, mappée sur `WingetOutController::handle`.
2. **AC2.2** — Le controller résout le poste par `machine` (= `$env:ComputerName`, déjà le hostname) via `WorkstationPackagesResolver::resolve($machine)`.
3. **AC2.3** — La logique de mapping est implémentée dans `App\Wpkg\Deployment\Services\WingetPackagesResolver` qui reproduit fidèlement `winget_out.php` : merge `add.json` (**`/usr/share/` l'emporte sur `/etc/` pour un même `Id`** — parité stricte legacy `winget_out.php:115-119` ; la couche `/etc/` enrichit la liste pour les `Id` absents de `/usr/share/`), comparaison de version pinnée, décision `install`/`upgrade`/`uninstall` (avec `remove.json`, même logique de priorité).
4. **AC2.4** — Réponse happy path : `200` + `Content-Type: text/json` + JSON `{install?, upgrade?, uninstall?}` (clés présentes seulement si non-vides, `JSON_PRETTY_PRINT`). Champs par entrée : `Id`, `Source` (défaut `"winget"`), et `Version`/`Custom`/`Override`/`AvailableVersion` selon parité legacy.
5. **AC2.5** — Validation : `winget_enabled=false` → `400` ; `action != "list"` OU `list` vide OU `machine` vide OU `list` non-JSON → `400 Bad request` (parité `winget_out.php:24,28,195-196`).
6. **AC2.6** — Aucune écriture de fichier temporaire (`/tmp/winget_*.json` legacy **non porté**).

### AC3 — Réutilisation native & conversion LDAP→Eloquent (consigne Henri)

1. **AC3.1** — Aucune requête LDAP/AD dans le chemin critique des 2 endpoints (invariant Epic 15). Toute résolution passe par Eloquent (`WorkstationPackagesResolver`, `Application`, `WorkstationGroup`, `AppProfile`).
2. **AC3.2** — `WorkstationPackagesResolver` (15.2) et `PackagesXmlService` (AppStore) ne sont **pas modifiés**.
3. **AC3.3** — Les controllers portent une annotation `@legacy-port path="sambaedu/wpkg/{linux_out,winget_out}.php"` (traçabilité, pattern iso `WorkstationPackagesResolver`).

### AC4 — Pas d'authentification JWT (D2, memory `feedback_auth_iso_legacy`)

1. **AC4.1** — Aucune des 2 routes n'est sous `auth.v1.workstation` / Bearer / Sanctum. Protection = `local.request` (IP allowlist LAN) + throttle.
2. **AC4.2** — Un appel depuis une IP hors allowlist `local.request` est rejeté (parité comportement `wpkg/reports/*`).

### AC5 — Flag d'activation winget (D6)

1. **AC5.1** — `config('sambaedu.wpkg.winget_enabled', false)` ajouté dans `config/sambaedu.php` (bloc `wpkg`) + clé `WPKG_WINGET_ENABLED` documentée dans `.env.example`.
2. **AC5.2** — `winget_out` retourne `400` si le flag est falsy (parité `winget_out.php:23-26`).

### AC6 — Tests de parité + Unit + Architecture (D8)

1. **AC6.1** — `tests/Feature/Wpkg/.../WingetOutEndpointTest.php` (ou nom équivalent) : ≥3 scénarios de parité JSON (machine vierge / apps à jour / apps à upgrader+désinstaller avec surcharge `/etc/`).
2. **AC6.2** — `tests/Feature/Wpkg/.../LinuxOutEndpointTest.php` : ≥1 scénario de parité plain-text (apt explicite + fallback app_id).
3. **AC6.3** — `tests/Unit/Wpkg/Deployment/Services/WingetPackagesResolverTest.php` : ≥5 cas (merge add/remove, priorité **`/usr/share/` sur `/etc/` pour un même `Id`**, version pinnée, décisions, XML sans noeud winget).
4. **AC6.4** — `tests/Architecture/WpkgOutRoutesTest.php` : ≥4 tests (2 routes enregistrées, avant catchall, sans `auth.v1.workstation`, avec `local.request`).
5. **AC6.5** — Tous les tests existants (15.2, AppStore, 16.13, 17.x) restent verts. Lint `php -l` sur tous les fichiers créés = 0 erreur.

### AC7 — Runbook QA + non-régression catchall

1. **AC7.1** — Section append-only dans `docs/qa/domains/` (fichier WPKG/scripts pertinent — vérifier en T0 lequel existe) « Story 17.6 » avec scénarios `curl` :
   - `curl "http://se4fs/wpkg/linux_out.php?id=<hostname>"` → plain-text paquets.
   - `curl -X POST -F "machine=<host>" -F "list=[]" -F "action=list" http://se4fs/wpkg/winget_out.php` → JSON install.
   - parité legacy vs natif (comparer body pour un même poste).
2. **AC7.2** — Le catchall legacy reste intact (les autres chemins `*.php` legacy continuent d'être servis par `LegacyCatchallController`). Test archi `routes_declared_before_catchall` garantit l'ordre.

---

## Tasks / Subtasks

### T0 — Preflight (0.25j)

- [x] **T0.1** Mode de travail confirmé par le prompt orchestrateur : branche `main`, checkout principal (PAS un worktree). Politique : pas de sync VM manuel, pas de SSH ; lint `php -l` + PHPUnit local (host, `vendor/` présent).
- [x] **T0.2** Les 2 fichiers legacy lus en entier : `linux_out.php` (47 L) et `winget_out.php` (198 L). Contrat I/O confirmé (params GET/POST, headers `text/plain`/`text/json`, format `implode(" ")` / JSON).
- [x] **T0.3** Signature confirmée : `WorkstationPackagesResolver::resolve(string $hostname): Collection<int,string>` (liste d'`app_id` triée alpha ASC, Eloquent-only). `Application` porte `app_id` + `xml` (cf. `app/Models/Application.php`).
- [x] **T0.4** `local.request` = `App\Http\Middleware\EnsureLocalRequest`, alias enregistré dans `app/Http/Kernel.php:78` (PAS dans `bootstrap/app.php` — Laravel 10-style). Pattern `wpkg/reports/*` (`api.php:153`) + `ipxe/clonezilla-menu` (`web.php:928`) + bloc `wpkg/hosts.xml`/`profiles.xml` (`web.php:645-650`) confirmés avant catchall.
- [x] **T0.5** Pattern parité confirmé : `ApplicationsScriptsByteParityTest` + trait `Tests\Concerns\AssertsScriptParity`, groupe `requires-fixture-capture`, `markTestSkipped`.
- [x] **T0.6** Runbook QA cible identifié : `docs/qa/domains/wpkg-deploy.md` (domaine WPKG/déploiement). Section append-only « Section 6 — Story 17.6 ».

### T1 — Endpoint `linux_out` (~1j) (AC1, AC3, AC4)

- [x] **T1.1** `LinuxOutController::handle(Request, WorkstationPackagesResolver, ApplicationXmlReader)` créé, annoté `@legacy-port`. Hostname résolu depuis `id` (D1).
- [x] **T1.2** Extraction APT factorisée dans `App\Wpkg\Deployment\Support\ApplicationXmlReader` (`loadByAppIds` = 1 requête `whereIn`, pas N+1 ; `parseFragment` avec `libxml_use_internal_errors`, pattern iso `PackagesXmlService` ; `aptPackageFor` = `<linux type="apt">@package` + fallback `strtolower(app_id)`).
- [x] **T1.3** Réponse plain-text `implode(" ", …)` + `Content-Type: text/plain`. `id` vide → body `""` (200).
- [x] **T1.4** Route `Route::match(['GET','POST'], '/wpkg/linux_out.php', …)` déclarée dans `routes/web.php` après le bloc `wpkg/{hosts,profiles}.xml` et **avant** le catchall, avec `['local.request','throttle:300,1']` + `withoutMiddleware(['web'])`.
- [x] **T1.5** `LinuxOutEndpointTest` : 5 tests (parité apt+fallback, id vide, hostname inconnu, POST, IP non-locale → 403).
- [x] **T1.6** `php -l` OK.

### T2 — Service + endpoint `winget_out` (~1.5j) (AC2, AC3, AC4, AC5)

- [x] **T2.1** `WingetPackagesResolver` créé (`@legacy-port`). `resolve(string $machine, array $localApps): array` reproduit `winget_out.php:61-194` (D5) : liste XML winget + merge add.json + install/upgrade (version pinnée) + remove.json/uninstall. Clés omises si vides.
- [x] **T2.2** Extraction des noeuds `<windows type="winget">` dans `ApplicationXmlReader::wingetEntriesFor` (Id, Source défaut `winget`, Version, Custom, Override — ordre des clés iso-legacy).
- [x] **T2.3** Chemins catalogues via `config('sambaedu.wpkg.winget_catalog_{add,remove}_{local,default}')` (défauts `/etc/` + `/usr/share/`). `readCatalog` avec garde fichier-absent/JSON invalide → `[]`.
- [x] **T2.4** `WingetOutController::handle` : flag `winget_enabled` (400), validation `action/list/machine` + `list` non-JSON (400), délégation au service, réponse `text/json` + `JSON_PRETTY_PRINT`. **Aucune écriture `/tmp`** (vérifié par test).
- [x] **T2.5** `config/sambaedu.php` bloc `wpkg` : `winget_enabled` + `winget_catalog_*`. `.env.example` : `WPKG_WINGET_ENABLED` + chemins catalogues documentés.
- [x] **T2.6** Route `Route::match(['GET','POST'], '/wpkg/winget_out.php', …)` déclarée dans `routes/web.php` avant le catchall (mêmes middlewares que T1.4).
- [x] **T2.7** `WingetPackagesResolverTest` (7 cas Unit) + `WingetOutEndpointTest` (8 tests dont 3 scénarios parité D8 : machine vierge / apps à jour / upgrade+uninstall+surcharge /etc/).
- [x] **T2.8** `php -l` OK.

### T3 — Architecture, runbook, finalisation (0.25-0.5j) (AC6, AC7)

- [x] **T3.1** `tests/Architecture/WpkgOutRoutesTest.php` créé (4 tests D8 : 2 routes enregistrées + controllers référencés, avant catchall, sans `auth.v1.workstation`/Bearer/JWT, avec `local.request` + throttle).
- [x] **T3.2** Runbook QA : section append-only « Section 6 — Story 17.6 » (5 scénarios `curl` 6.1-6.5 + post-correctifs) dans `docs/qa/domains/wpkg-deploy.md`.
- [x] **T3.3** Suite locale lancée (`vendor/` présent, SQLite :memory:) : nouveaux tests 22/22 verts ; non-régression `tests/Architecture` + `tests/Unit/Wpkg` + `tests/Feature/Wpkg` = 215/215 (2 skipped pré-existants, 1 risky pré-existant non lié) + AppStore/Gpo parité = 40/40 (17 skipped `requires-fixture-capture`).
- [x] **T3.4** `Dev Agent Record` rempli (Agent Model, Debug Log, Completion Notes, File List).
- [x] **T3.5** `sprint-status.yaml` : `17-6-...` → `review` (commentaire append daté).

---

## Dev Notes

### Fichiers legacy source (base de portage — repo upstream)

> Repo upstream de référence : `/home/htouchard/code/irundo/se4/sources/`. **Ne pas modifier** ces fichiers (lecture seule, base de portage). Ils ne sont **pas** dans le repo `sambaedu-reload`.

- **`var/www/sambaedu/wpkg/linux_out.php`** (47 lignes) — l'endpoint APT. Logique : `apcu_fetch("apps.$id")` → `liste_applications` ; charge `packages.xml` ; pour chaque package dont l'`id` ∈ `liste_applications`, lit `<linux type="apt">@package` (fallback `strtolower(id)`) ; `echo implode(" ", $liste)` en `text/plain`.
- **`var/www/sambaedu/wpkg/winget_out.php`** (198 lignes) — l'endpoint winget. Logique détaillée en D5. Inputs POST `machine`, `list`, `action=list` ; flag `$config['winget']` ; merge `add.json`/`remove.json` `/etc/`+`/usr/share/` ; décision install/upgrade/uninstall ; `text/json`.
- **`var/www/sambaedu/includes/wpkg_libsql.php`** → `info_poste_applications($config, $machine)` : la requête SQL (table wpkg `postes/parc/parc_profile/applications_profile/applications/dependance`) que `WorkstationPackagesResolver` (15.2) **a déjà portée** (cf. `@legacy-port` du resolver). **Ne pas re-porter.**
- **`var/www/sambaedu/includes/applications.inc.php:878`** — `$id = md5(strtolower($user).strtolower($machine).$action.$application)` : l'origine du `$id` md5 APCu. **Inutile en natif** (cf. D1).

### Scripts consommateurs packagés (contrat client — ne pas modifier)

- **`usr/share/sambaedu/applications/wpkg/startup.linux`** (15 lignes) : `packages=$(curl -s -F "id=$id" http://###_SE4FS_NAME_###.###_DOMAIN_###/wpkg/linux_out.php)` puis `for p in $packages; do apt-get install -y -q $p; done`. → split sur espaces (format `text/plain` critique).
- **`var/sambaedu/unattended/install/os/SambaEdu/install.ps1:73-81`** : `$Uri = "http://${env:SE4FS}/wpkg/winget_out.php"` ; `$Form = @{ machine=$env:ComputerName; list=$list; action='list' }` ; `Invoke-RestMethod -Method Post`. Consomme `$winget.uninstall/.install/.upgrade` (`:89,97,117`). `$list` = `Get-WinGetPackage | ConvertTo-Json` (chaque entrée porte `Id`, `InstalledVersion`, `IsUpdateAvailable`, `AvailableVersions`, `Source`).
  - **⚠ `winget-install.ps1`** (asheroto, gestion du binaire winget) est **hors scope** — ce n'est PAS lui qui appelle `winget_out.php`, c'est `install.ps1`.

### Code natif à réutiliser (NE PAS réinventer)

- **`app/Wpkg/Deployment/Services/WorkstationPackagesResolver.php`** (15.2) — `resolve(string $hostname): Collection<int,string>` (liste d'`app_id`, dédupliquée, triée alpha ASC, dépendances transitives incluses, Eloquent-only, cache `wpkg:packages:<hostname>` TTL 1000s). **C'est la brique centrale des 2 endpoints.**
- **`app/Models/Application.php`** — champ `xml` (fragment `<package id="<app_id>">...</package>`), `app_id`, scopes `installed()`. Relations `appProfiles()`, `workstations()`, `workstationGroups()`, `dependencies()`.
- **`app/Models/Workstation.php`** — résolution par `name` (`where('name', $hostname)`, déjà fait dans le resolver). Relations `groups()`, `appProfiles()`, `applications()`.
- **`app/Services/AppStore/PackagesXmlService.php`** — référence pour le **parsing DOM robuste** (`libxml_use_internal_errors(true)` + skip+log XML invalide, lignes 42-47). Reproduire ce pattern, ne pas le modifier.
- **`app/Http/Middleware/EnsureLocalRequest.php`** (alias `local.request`) — protection LAN des endpoints machine.

### Pattern routes (référence)

- **Bloc `wpkg/reports`** (`routes/api.php:153-155`) : `Route::prefix('wpkg')->middleware('local.request')->group(...)` — pattern endpoint machine sans auth.
- **`ipxe/clonezilla-menu`** (`routes/web.php`, avant catchall) : `Route::match([...], '/ipxe/clonezilla-menu', ...)->middleware([...])->withoutMiddleware(['web'])` — **pattern à copier** pour déclarer une route native AVANT le catchall legacy. Le commentaire « ORDRE STRICT : ce bloc DOIT rester AVANT le catchall » + le test `IpxeNamespaceTest::ipxe_3_7_routes_are_declared_before_catchall` sont les références directes pour D2/AC6.
- **Catchall legacy** (`routes/web.php`, dernière route) : `Route::match([...], '{path}')->where('path', '.*')` → `LegacyCatchallController::handle`. Les 2 nouvelles routes doivent être **avant**.

### Pattern test de parité (référence 17.2/17.4)

- `tests/Feature/Gpo/ApplicationsScriptsByteParityTest.php` — helper `assertScriptParity()`, fixtures `tests/Fixtures/Gpo/applications/<scenario>/expected.<ext>`, `markTestSkipped` quand `/usr/share/sambaedu/applications/` absent (groupe `requires-fixture-capture`). **Réutiliser/adapter** ce pattern pour les fixtures winget (`add.json`/`remove.json`) et la capture du body legacy de référence.
- Trait JWT `tests/Concerns/IssuesWorkstationJwt` : **PAS nécessaire ici** (endpoints sans auth). Ne pas l'inclure.

### Project Structure Notes

- Convention : controllers WPKG sous `app/Wpkg/Deployment/Http/Controllers/` (pattern existant `HostsXmlController`, `ProfilesXmlController`). Services sous `app/Wpkg/Deployment/Services/`. Cohérent avec le namespace `App\Wpkg\Deployment\*` du resolver 15.2.
- Filesystem-based routing (CLAUDE.md) concerne les **pages** Livewire (`resources/views/pages/`). Ici ce sont des **endpoints API machine** → on suit le pattern routes explicites (16.13/15.x), pas le routing par convention.
- Pas de migration DB, pas de modèle Eloquent nouveau (réutilisation 15.2 + AppStore).

### Discrepances / décisions ouvertes (DO)

- **DO-1 (intégration 17.2 ↔ 17.6, à confirmer en dev)** : le `wpkg/startup.linux` envoie `id=$id`. Côté legacy, `$id` = md5 APCu substitué par `applications.php`. Côté natif, le pipeline d'assembly Linux (17.2, `/api/v1/workstation-config/applications-scripts`) doit injecter `id=<hostname>` (ex. `$(hostname)`) dans le préambule du script, **pas** le md5. **Action dev** : vérifier ce que le pipeline natif 17.2 (`ApplicationScriptsAssembler`) injecte réellement comme `id` dans les fragments Linux. Si c'est encore le md5 (ou un identifiant non résolvable par nom), documenter l'écart + proposer le fix minimal côté assembler (génération serveur, hors fichier packagé). Fallback robuste implémenté D1 : `id` non résolvable → collection vide → body `""` (pas de crash). **Décision par défaut SM** : traiter `id` comme hostname ; escalader à Henri si le pipeline 17.2 ne l'alimente pas ainsi.
- **DO-2 (`partages/cloud_out.php`)** : **hors scope** (introuvable upstream, cf. audit Q4 + sprint-status). Ne rien faire. Si Henri confirme son existence plus tard → story séparée.
- **DO-3 (newline final `linux_out`)** : le legacy fait `echo implode(" ", $liste)` sans `\n` final, mais le `?>` de fermeture PHP peut ajouter un caractère. **Action dev** : capturer le body legacy de référence exact (octets) et matcher (le `for p in $packages` shell tolère un trailing space/newline, donc peu risqué — mais viser la parité bytes stricte pour le test).
- **DO-4 (cache resolver)** : `WorkstationPackagesResolver` cache par hostname (TTL 1000s). Les 2 endpoints en bénéficient gratuitement. Ne pas ajouter de cache supplémentaire côté controller (éviter double-cache incohérent).

### References

- [Source: _bmad-output/planning-artifacts/epics.md#Story 17.6] — cadrage epic (ligne 3471-3473).
- [Source: _bmad-output/planning-artifacts/audit-applications-scripts.md#G.5] — périmètre révisé 17.6, consigne Henri (lignes 1215-1251).
- [Source: _bmad-output/planning-artifacts/audit-applications-scripts.md#Q4-Q8] — résolutions Henri 2026-05-21 (lignes 1545-1605).
- [Source: _bmad-output/planning-artifacts/audit-applications-scripts.md#A.1] — fiches `wpkg/startup.linux` (712-726), `winget/startup.windows` (676-694).
- [Source: _bmad-output/planning-artifacts/audit-applications-scripts.md#A.3] — `install.ps1` / `winget-install.ps1` (778-803).
- [Source: _bmad-output/implementation-artifacts/16-13-exposition-endpoints-api-v1.md] — pattern endpoints natifs, résolution contexte poste, parité iso-fonctionnelle, tests Feature/Architecture.
- [Source: _bmad-output/implementation-artifacts/15-2-generators-xml-ini-par-poste.md] — `WorkstationPackagesResolver` (résolution apps applicables au poste).
- [Source: _bmad-output/implementation-artifacts/17-4-tests-integration-runtime-vm.md] — pattern parité bytes `assertScriptParity` + `markTestSkipped`.
- [Source: app/Wpkg/Deployment/Services/WorkstationPackagesResolver.php] — brique de résolution centrale.
- [Source: sambaedu/wpkg/linux_out.php + sambaedu/wpkg/winget_out.php (upstream)] — code legacy base de portage.
- [Source: routes/web.php (catchall + ipxe/clonezilla-menu) + routes/api.php (wpkg/reports)] — patterns de déclaration de routes.
- [Source: CLAUDE.md + memory feedback_auth_iso_legacy + project_php_fpm_user_www_admin] — contraintes projet (auth iso-legacy, www-admin, pas de sync VM manuel).

---

## Recommandation Modèle Dev

**Recommandation : `opus`.**

### Justification

Story de **complexité moyenne-haute** malgré une charge modérée (~2.5-3j), justifiant `opus` :

- **Parité iso-legacy délicate** : `winget_out.php` (198 lignes) porte une logique métier non-triviale — merge d'ordre-sensible de `add.json`/`remove.json` (`/etc/` vs `/usr/share/`), comparaison de versions pinnées (`version_compare` + parcours `AvailableVersions`), décision tri-état install/upgrade/uninstall. Une erreur d'ordre dans le merge ou de sens dans la comparaison de versions casse silencieusement la parité — exactement le genre de bug que `sonnet` peut introduire en « simplifiant » le portage.
- **Décision d'intégration ouverte (DO-1)** : le couplage avec le pipeline 17.2 (quel identifiant le script Linux envoie réellement comme `id`) demande une analyse cross-story et un arbitrage (hostname vs md5, fallback robuste). Jugement nécessaire pour ne pas casser le boot Linux du parc.
- **Sécurité/exposition** : endpoints **sans auth** ouverts sur le LAN, consommés en masse au boot. Le dev doit border correctement `local.request`, le throttle, l'absence d'écriture `/tmp`, et l'ordre avant catchall (un mauvais ordre = le legacy reprend la main silencieusement).
- **Parité bytes/JSON testée** : capture de référence legacy + assertions strictes sur Content-Type non-standard (`text/json`) et format plain-text — précision requise.

**Pas sonnet** : ce n'est pas du CRUD ni de l'UI ; c'est un portage métier avec parité stricte, une intégration cross-story (17.2/15.2), et une surface sans auth sur le LAN. Le risque de régression silencieuse (parité winget cassée, boot Linux KO) est élevé. `opus` est aligné avec le profil des stories de portage legacy critiques (17.2 a été développée en opus selon le cadrage epic).

---

## Dev Agent Record

### Agent Model Used

claude-opus-4-7[1m]

### Debug Log References

- `php -l` sur les 4 fichiers source + config + routes + 4 fichiers de test : 0 erreur.
- `vendor/bin/phpunit tests/Architecture/WpkgOutRoutesTest.php` → 4 tests OK (25 assertions).
- `vendor/bin/phpunit tests/Unit/Wpkg/Deployment/Services/WingetPackagesResolverTest.php` → 7 tests OK (17 assertions).
- `vendor/bin/phpunit tests/Feature/Wpkg/Deployment/Http/{LinuxOut,WingetOut}EndpointTest.php` → 11 tests OK (35 assertions).
- Non-régression : `tests/Architecture` + `tests/Unit/Wpkg` + `tests/Feature/Wpkg` → 215 tests OK (2 skipped, 1 risky pré-existant `ApiV1ConfigRoutesTest::no_legacy_unprefixed_routes_remain` non lié).
- Non-régression AppStore/Gpo : `PackagesXmlServiceTest` + `AppStoreServiceTest` + `ApplicationsScriptsByteParityTest` + `AssociationsOutEndpointTest` → 40 tests OK (17 skipped `requires-fixture-capture`).
- 2 incidents corrigés en cours de dev : (1) `ApplicationXmlReader` était `final` → impossible à mocker (Mockery) → rendu non-`final` (pattern iso `PackagesXmlService`) ; (2) Laravel ajoute `; charset=utf-8` aux Content-Type `text/*` → assertions de test alignées sur le pattern natif accepté du repo (`assertStringStartsWith('text/plain'|'text/json')`, iso `AssociationsOutEndpointTest` 16.13).

### Completion Notes List

**Implémentation (parité legacy stricte) :**
- 2 controllers natifs `LinuxOutController` (plain-text APT) + `WingetOutController` (JSON winget), 1 service `WingetPackagesResolver`, 1 helper partagé `ApplicationXmlReader`. Tous annotés `@legacy-port` (AC3.3).
- Réutilisation **pure** de `WorkstationPackagesResolver` (15.2) et `PackagesXmlService` (AppStore) — **aucune modification** (AC3.2). Toute résolution est Eloquent-only, aucune requête LDAP/AD en chemin critique (AC3.1).
- Routes déclarées dans `routes/web.php` aux chemins littéraux `/wpkg/linux_out.php` + `/wpkg/winget_out.php`, juste après le bloc `wpkg/{hosts,profiles}.xml`, **avant** le catchall, avec `local.request` + `throttle:300,1` + `withoutMiddleware(['web'])`. **Pas de JWT** (D2/AC4).
- Flag `config('sambaedu.wpkg.winget_enabled', false)` → 400 si off (AC5). `linux_out` n'a pas ce flag.
- Parsing DOM robuste (`libxml_use_internal_errors(true)` + skip+log XML invalide). Chargement batch (1 requête `whereIn`, pas de N+1).
- **Aucune écriture `/tmp`** (les `file_put_contents("/tmp/winget_*.json")` debug du legacy ne sont pas portés — AC2.6, vérifié par test).

**DO-1 (intégration 17.2 ↔ 17.6) — ÉCART CONFIRMÉ, À ESCALADER À HENRI :**
- Vérification de ce que le pipeline 17.2 (`app/Gpo/Services/ApplicationScriptsAssembler.php`) injecte comme `id` dans le préambule du script Linux : ligne 368, `"id=" . $id . "\n\n"` où `$id = (string) ($info['id'] ?? '')`. Cet `$info['id']` est le **md5 de session** (`md5(user+machine+action+os)`, cf. `ApplicationsScriptsByteParityTest.php:91,142,192` → `md5('pc-testpc-teststartuplinux')`), **PAS le hostname**.
- Le script consommateur packagé `applications/wpkg/startup.linux` fait `curl -F "id=$id" .../wpkg/linux_out.php` où `$id` = cette variable shell md5.
- **Conséquence** : en l'état, `linux_out` natif recevra un `id` = md5 → résolution par hostname échoue proprement → `WorkstationPackagesResolver::resolve(<md5>)` retourne une collection vide → **body `""`** (fallback robuste D1, le poste boot quand même, AC1.5). **Mais aucun paquet APT ne sera installé tant que 17.2 n'injecte pas `id=<hostname>`.**
- **Fix minimal proposé (hors scope 17.6, à arbitrer)** : modifier `ApplicationScriptsAssembler::headerScripts()` pour que le préambule **bash** injecte `id=$(hostname)` (génération serveur, pas dans le fichier packagé) au lieu du md5 — OU exposer le hostname via une variable shell dédiée que `startup.linux` passerait. Ce changement touche le pipeline 17.2 et **casserait la parité bytes** des fixtures 17.2/17.4 (groupe `requires-fixture-capture`) → nécessite recapture + validation Henri. **Décision par défaut SM appliquée** : traiter `id` comme hostname avec fallback robuste ; écart documenté ici, NON corrigé dans 17.6 pour ne pas élargir le scope ni casser 17.2. Le scénario QA 6.2 #3 le mentionne explicitement.
- `winget_out` n'a **aucun écart** : `install.ps1` envoie `machine=$env:ComputerName` = déjà le hostname → résolution directe (D1).

**DO-3 (newline final `linux_out`)** : le natif fait `response(implode(' ', …))` sans newline final (parité `echo implode(" ", $liste)`). Le `for p in $packages` shell tolère un trailing space/newline. Pas de fixture de capture bytes legacy disponible localement → parité bytes stricte différée au smoke VM (mentionné scénario QA 6.1 #5).

**DO-4 (cache)** : aucun cache ajouté côté controller — `WorkstationPackagesResolver` cache déjà par hostname (TTL 1000s). Pas de double-cache.

**Note parité D5 (priorité add/remove)** : le code legacy `winget_out.php:115-119` donne la priorité aux entrées `/usr/share/` sur `/etc/` **pour un même Id** (l'entrée `/etc/` du même Id est retirée). La phrase D5 « /etc/ prioritaire » simplifie ; j'ai reproduit fidèlement le **code legacy** (source de vérité), couvert par le test Unit `priorite_usr_share_sur_etc_pour_meme_id`. Écart de formulation documenté ici.

**Modifications hors controllers/service** : ajout de la colonne `xml` (nullable, non-breaking) à `tests/Support/WpkgSchemaBootstrapper.php` (la table `applications` du shim de test ne la portait pas). `ApplicationXmlReader` rendu non-`final` pour testabilité (mock).

**Corrections post-review 2026-05-25 (Dev Agent — claude-opus-4-7[1m]) :**

- **#1 (🔴, décision Henri « aligner sur les 6 siblings ») — corrigé.** `LinuxOutController` ne résout PLUS le poste par hostname via `WorkstationPackagesResolver`. Constat : le script `startup.linux` envoie `id=<md5>` (md5 de session, posé par `ApplicationScriptsGenerator:103`), pas le hostname → la résolution échouait → body vide → 0 paquet APT en prod. Correctif : alignement strict sur les 6 autres endpoints `*_out.php` natifs (`AssociationsOutController::legacyOut` comme modèle) — injection `AppContextRepository` en constructeur, validation `id` md5 (`^[a-f0-9]{32}$`), `findById($id)`, extraction de `raw['liste_applications']`. **Forme exacte de `liste_applications`** : liste **plate de chaînes** (`app_id` minuscules, dédupliquée) — confirmé legacy (`applications.inc.php:969` : `array_map('strtolower', array_column(... "id_nom_app"))`) ET natif (`ApplicationScriptsGenerator::resolveInstalledApplications` : `$apps[] = strtolower($name)`). Comme le legacy `linux_out.php:27` matche en `array_search(strtolower($id), $liste_applications)` (case-insensitive), `ApplicationXmlReader::loadByAppIds()` matche désormais en `LOWER(app_id) IN (lowercased_inputs)` + index `strtolower(app_id)` (robustesse collation, sans impact sur winget qui passe les `app_id` bruts). **`WingetOutController` et `ApplicationScriptsAssembler`/`Generator` (17.2) NON modifiés** (parité 17.2/17.4 préservée). Comportements iso-legacy : id absent/invalide → body `""` 200 ; contexte expiré/absent → body `""` 200 + `Log::debug`.
- **S1 (🟠, décision Henri « oui, filtrer ->installed() ») — corrigé.** `ApplicationXmlReader::loadByAppIds()` chaîne `->installed()` (= `where('status', ApplicationStatus::Installed)`) — parité stricte packages.xml legacy (qui ne contient que les apps Installed). Appliqué aux 2 endpoints (helper partagé). Impact attendu : les apps des scénarios `WingetOutEndpointTest` ont été passées en `status => installed`.
- **Mineures** : #2 — test Unit dédié `tests/Unit/Wpkg/Deployment/Support/ApplicationXmlReaderTest.php` (apt simple/multi-noeud/fallback, winget attributs/défaut Source/multi-package indépendant/non-winget ignoré, XML invalide+log, XML NULL/vide). #5 — scénario `WingetOutEndpointTest::scenario_machine_inconnue_add_json_part_en_install` (machine inconnue + `add.json` peuplé → install baseline). #6 — texte D5/AC2.3 (+ occurrences) corrigé : « `/usr/share/` l'emporte sur `/etc/` pour un même `Id` ; `/etc/` enrichit pour les `Id` absents ». #7 — commentaire inline dans `wingetEntriesFor()` (réinit `$app` = correction pollution inter-packages legacy). #8 — `WPKG_ALLOWED_IPS` documenté dans `.env.example` + pré-requis runbook QA 17.6. S4 — note runbook QA (Content-Type text/plain posé même sur cas vide) + commentaire `LinuxOutController::emptyBody()`.
- **Runbook QA** `docs/qa/domains/wpkg-deploy.md` section 17.6 : scénarios 6.1/6.2 réécrits (le `id` est un md5, pas un hostname ; lecture `apps.<md5>`), pré-requis `WPKG_ALLOWED_IPS` ajouté, checklist post-correctifs enrichie (#1, S1, S4).
- **Tests post-correctifs** (5 fichiers 17.6 = 37 tests) : `ApplicationXmlReaderTest` 12/12, `LinuxOutEndpointTest` 7/7, `WingetOutEndpointTest` 7/7, `WingetPackagesResolverTest` 7/7, `WpkgOutRoutesTest` 4/4. Non-régression `tests/Architecture`+`Feature/Gpo`+`Unit/Gpo` = 642 OK (93 skipped, 4 risky pré-existants). `--filter AppStore|PackagesXmlService|ApplicationScripts|WorkstationPackagesResolver` = 73 OK. `php -l` = 0 erreur sur les fichiers touchés.

### File List

**Créés :**
- `app/Wpkg/Deployment/Support/ApplicationXmlReader.php`
- `app/Wpkg/Deployment/Http/Controllers/LinuxOutController.php`
- `app/Wpkg/Deployment/Http/Controllers/WingetOutController.php`
- `app/Wpkg/Deployment/Services/WingetPackagesResolver.php`
- `tests/Unit/Wpkg/Deployment/Services/WingetPackagesResolverTest.php`
- `tests/Unit/Wpkg/Deployment/Support/ApplicationXmlReaderTest.php` *(post-review #2 — test Unit dédié au helper)*
- `tests/Feature/Wpkg/Deployment/Http/LinuxOutEndpointTest.php`
- `tests/Feature/Wpkg/Deployment/Http/WingetOutEndpointTest.php`
- `tests/Architecture/WpkgOutRoutesTest.php`

**Modifiés :**
- `routes/web.php` (2 routes `/wpkg/{linux,winget}_out.php` avant le catchall)
- `config/sambaedu.php` (bloc `wpkg` : `winget_enabled` + `winget_catalog_*`)
- `.env.example` (`WPKG_WINGET_ENABLED` + chemins catalogues ; **post-review #8** : `WPKG_ALLOWED_IPS` documenté)
- `tests/Support/WpkgSchemaBootstrapper.php` (colonne `xml` nullable sur `applications`)
- `docs/qa/domains/wpkg-deploy.md` (Section 6 — Story 17.6, append-only ; **post-review** : scénarios 6.1/6.2 `apps.<md5>`, pré-requis `WPKG_ALLOWED_IPS`, note S4, checklist #1/S1)
- `_bmad-output/implementation-artifacts/sprint-status.yaml` (`17-6-…` → `review`)

**Post-review (2026-05-25) — modifiés pour les corrections #1 + S1 + mineures :**
- `app/Wpkg/Deployment/Http/Controllers/LinuxOutController.php` (#1 : lecture `apps.<md5>` via `AppContextRepository`, plus de résolution hostname)
- `app/Wpkg/Deployment/Support/ApplicationXmlReader.php` (S1 : scope `->installed()` + matching `app_id` case-insensitive ; #7 : commentaire `$app = []`)
- `tests/Feature/Wpkg/Deployment/Http/LinuxOutEndpointTest.php` (#1 : seed `apps.<md5>` ; S1 : cas app non-Installed exclue)
- `tests/Feature/Wpkg/Deployment/Http/WingetOutEndpointTest.php` (S1 : apps en `status=installed` ; #5 : scénario machine inconnue + add.json)

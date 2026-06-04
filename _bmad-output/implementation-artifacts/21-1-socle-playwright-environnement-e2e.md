# Story 21.1 : Socle Playwright + environnement e2e

Status: review

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

> **Première story d'Epic 21** « Tests E2E Playwright sur Postgres préseedé ». Elle pose **uniquement l'infrastructure** : un harnais Playwright sur l'hôte, un environnement Laravel `e2e` sur la VM adossé à une DB Postgres `sambaedu_e2e` recréable en ~centisecondes depuis une **template DB** `sambaedu_e2e_template`, et les **garde-fous structurels** qui rendent impossible de dropper la DB de dev/prod. Le fake AD (21.2), le seed de référence (21.3) et les 4 parcours fonctionnels (21.4-21.7) viennent **après** et **dépendent** de ce socle.
>
> **L'agent dev n'a PAS lu l'epic** : tout le contexte nécessaire est dans ce fichier. Lire la section « Contexte critique » avant de coder.

---

## Contexte critique (à lire avant de coder)

### Topologie d'exécution : hôte → VM (PAS de docker, PAS de Playwright sur la VM)
- **Playwright et ses navigateurs s'installent sur la machine HÔTE** (poste de henri). Les tests s'exécutent depuis l'hôte et **ciblent par le réseau** l'instance e2e servie par la VM (`baseURL` = URL HTTP de l'instance e2e VM).
- **La VM** héberge l'app SE5 (PHP-FPM + vhost) et Postgres. L'instance e2e est une **instance Laravel dédiée** en env `e2e`.
- Décision de cadrage henri (2026-06-04) : pas de stack docker dédiée, pas de Playwright sur la VM.

### ⚠️ Branche `playwrite` vs sync inotify (PIÈGE MAJEUR)
- Le code est édité sur l'hôte et **synchronisé vers la VM par inotify UNIQUEMENT pour la branche `main`** (règle `CLAUDE.md`). La branche courante de ce travail est **`playwrite`** → **le code de cette branche n'est PAS sur la VM**.
- Conséquence : les e2e valident **le code `main` déployé sur la VM**. Tester le code de la branche `playwrite` = **déploiement explicite demandé par henri**, jamais automatique. **Ne jamais sync manuellement le code vers la VM.**
- Pour cette story : le dev livre le **socle** (config, scripts, commande artisan, garde-fous, doc). La **provision réelle de l'instance e2e sur la VM** (création DB, vhost, `.env.e2e`, première construction de template) est une action d'exploitation à exécuter par henri quand il décide de déployer — documentée par cette story, **pas exécutée par l'agent dev**.

### Interdits stricts pour l'agent dev
- ❌ **Aucune commande SSH vers la VM (`/vm`) ni lab1.** Travail local : code, lint `php -l`, tests PHPUnit host (SQLite `:memory:`). Le smoke test Playwright réel contre la VM est confié à henri post-déploiement.
- ❌ Ne pas exécuter `npx playwright install` de navigateurs si l'environnement de dev ne le permet pas proprement — documenter la commande, ne pas présumer qu'elle tourne en CI/agent.
- ❌ Ne jamais introduire de chemin de code capable de dropper la DB de dev/prod (voir garde-fous ci-dessous).

### PHP-FPM tourne sous `www-admin` (uid 599)
- Pool custom Sambaedu, **pas `www-data`** (mémoire `project_php_fpm_user_www_admin`). Tout fichier de config ou `.env.e2e` lu par PHP/Apache sur la VM doit être `chown www-admin`. À rappeler dans la doc de provisioning, pas une action de l'agent dev (host-only).

### État des lieux des tests actuels (recon code)
- `phpunit.xml` : tests sur **SQLite `:memory:`** (`DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`), `APP_ENV=testing`. Schémas créés manuellement par `setUp()`. Groupe `requires-postgres` **exclu** par défaut. → Les e2e sont un **canal totalement distinct** : Postgres réel, navigateur réel, pas PHPUnit.
- **Aucun outillage navigateur existant** : ni Playwright, ni Dusk (`node_modules/@playwright` absent, pas de `playwright.config.*`, pas de `tests/e2e/`).
- `package.json` : projet Vite/Tailwind, `"type": "module"`. Pas de dépendance Playwright. Scripts `test:*` = PHPUnit/artisan.
- `config/database.php` : connexion `pgsql` paramétrée par env (`DB_HOST`/`DB_PORT`/`DB_DATABASE`/`DB_USERNAME`/`DB_PASSWORD`, `search_path=public`). **C'est cette connexion que l'instance e2e utilisera**, pointée sur `sambaedu_e2e`.
- `database/seeders/DatabaseSeeder.php` : appelle `PermissionSeeder`, `WorkstationSeeder`, `DepotSeeder`, `DepotApplicationSeeder`, `AppProfileSeeder`, `ShortcutSeeder`, `WpkgReportSeeder`. **Pas d'utilisateurs/établissement** (c'est l'objet de la Story 21.3, hors scope ici — 21.1 réutilise le seed existant pour valider que la template se construit).
- **Précédent de garde-fou anti-prod déjà en place** : `app/Console/Commands/DbSeedCommand.php` (override de `db:seed`) refuse de seeder si la DB ne ressemble pas à une DB de test (ni sqlite, ni suffixée `_test`), sauf `--force`, et **détecte le config cache** (`bootstrap/cache/config.php` prime sur les env vars). **CALQUER ce pattern** pour le garde-fou du reset e2e — ne pas réinventer.
- Login : `routes/web.php:35` `GET login` → `AuthController::showLogin` → `view('auth.login', …)` (`resources/views/auth/login.blade.php`). **C'est la page cible du smoke test** (la page de login s'affiche).

---

## Scope strict & frontières

### IN-SCOPE (ce que la story livre)

1. **Harnais Playwright sur l'hôte** : installation de `@playwright/test` (devDependency `package.json`), `playwright.config.ts` (ou `.js` cohérent avec `"type":"module"`), `baseURL` paramétrable par variable d'environnement (`E2E_BASE_URL`), dossier `tests/e2e/`, `workers: 1` documenté comme défaut. Scripts npm (`test:e2e`, `test:e2e:headed`, etc.).
2. **Smoke test** `tests/e2e/smoke.spec.ts` : navigue vers la page de login (`/login`) et asserte qu'elle s'affiche (élément identifiant du formulaire de login présent). Passe contre l'instance e2e de la VM (validé par henri post-déploiement ; structurellement correct côté code).
3. **Commande de build de la template** : commande artisan `e2e:build-template` (nom à confirmer en T0) qui, en env `e2e` uniquement, (re)construit `sambaedu_e2e_template` = `migrate:fresh` + `db:seed` sur la template, à relancer **uniquement quand migrations/seeders changent**. Réutilise les seeders existants (`DatabaseSeeder`) pour cette story.
4. **Commande/mécanisme de reset rapide** : `DROP DATABASE sambaedu_e2e` + `CREATE DATABASE sambaedu_e2e TEMPLATE sambaedu_e2e_template` (copie binaire ~centisecondes, **pas** de re-migration). Exposée comme commande artisan (`e2e:reset`) déclenchée par le `globalSetup`/`beforeAll` du runner.
5. **Garde-fous destructifs STRUCTURELS** (cœur sécurité de la story) : le reset (et le build de template) **refusent de s'exécuter** si :
   - `APP_ENV !== 'e2e'`, **OU**
   - la DB cible ne porte pas le suffixe `_e2e` (resp. `_e2e_template`).
   - Garde-fou = **code, pas config** : une exception levée dans le code de la commande, calquée sur `DbSeedCommand::isProductionDatabase()`, avec **détection du config cache** (`bootstrap/cache/config.php` prime). Jamais de chemin capable de dropper dev/prod.
6. **Câblage du reset dans le runner Playwright** : `globalSetup` (au lancement de la suite) déclenche le reset via le canal retenu en T0 (voir « Points de décision »). Reset **par suite** par défaut.
7. **Configuration de l'environnement e2e (côté repo)** : un fichier `.env.e2e.example` documentant les clés (`APP_ENV=e2e`, `APP_URL`, `DB_CONNECTION=pgsql`, `DB_DATABASE=sambaedu_e2e`, `DB_HOST`/`DB_PORT`/`DB_USERNAME`/`DB_PASSWORD`, `CACHE_DRIVER`, etc.). **Le `.env.e2e` réel vit sur la VM, jamais commité** (secrets).
8. **Reconnaissance de l'env `e2e` par le framework** : s'assurer que `e2e` est un environnement Laravel valide (rien à faire si `APP_ENV` est libre, mais vérifier qu'aucun garde existant ne bloque `e2e` ; ajouter `e2e` aux env autorisés là où c'est pertinent, ex. exclusions de prod).
9. **Documentation de provisioning** : un doc opérationnel (`docs/qa/e2e-setup.md` ou section dans le runbook QA) décrivant comment henri provisionne l'instance e2e sur la VM (DB, vhost/port, `.env.e2e` chown `www-admin`, première construction de template) et lance la suite depuis l'hôte. **Décrit, pas exécuté.**
10. **Tests host du garde-fou** : test PHPUnit (host, SQLite :memory:) vérifiant que la commande de reset **refuse** de s'exécuter quand `APP_ENV !== e2e` et/ou DB non suffixée `_e2e` (le cœur sécurité doit être couvert sans toucher Postgres ni la VM).

### HORS-SCOPE (ne pas faire)

- ❌ Le **fake AD/Samba** (driver factice, garde-fou anti-instanciation du client AD réel, auth via fake) = **Story 21.2**.
- ❌ Le **seed e2e de référence** (établissement, utilisateurs par rôle, salles, machines documentés) = **Story 21.3**. 21.1 réutilise `DatabaseSeeder` existant juste pour valider la construction de la template.
- ❌ Les **4 parcours fonctionnels** (Auth & navigation, Users CRUD, Machines & salles, Fédération) = Stories 21.4-21.7.
- ❌ **CI distante** : les e2e tournent depuis l'hôte de henri, pas en CI (hors scope epic).
- ❌ **Parallélisme multi-worker** (une DB par worker) : optimisation future ; `workers: 1` au départ.
- ❌ **Exécution réelle SSH/VM** par l'agent dev (provision, smoke contre la VM). Confié à henri.
- ❌ Toute modification du canal de tests PHPUnit/SQLite existant (`phpunit.xml` reste inchangé).

---

## Points de décision — ✅ TRANCHÉS par henri (2026-06-04, pré-dev)

> Ces points étaient listés par l'epic comme « à trancher en 21.1 ». **Henri a validé les 4 recommandations le 2026-06-04** : DP-1 = Option A (SSH depuis `globalSetup`), DP-2 = Option A (vhost/port dédié + `.env.e2e`), Q-3 = `e2e:reset` / `e2e:build-template`, Q-4 = connexion de maintenance à la volée. L'agent dev applique ces choix sans re-débattre et les consigne dans le Dev Agent Record.

### DP-1 — Canal de déclenchement du reset DB depuis le runner Playwright
- **Option A — SSH depuis `globalSetup`** : le `globalSetup` Playwright (sur l'hôte) ouvre une session SSH vers la VM et exécute `php artisan e2e:reset`.
  - ✅ Aucune surface HTTP destructive exposée. ✅ Aligné avec le modèle d'exécution (hôte pilote la VM).
  - ⚠️ Dépend d'une clé SSH disponible côté runner ; couplage au transport SSH.
- **Option B — Route HTTP artisan-gated réservée à l'env `e2e`** : un endpoint (ex. `POST /e2e/reset`) actif **uniquement si `APP_ENV === 'e2e'`** (sinon 404/inexistant), appelé par le runner.
  - ✅ Pas de dépendance SSH côté runner. ✅ Simple à appeler en HTTP.
  - ⚠️ Surface HTTP destructive (même gated) ; doit être **inerte hors e2e** (route non enregistrée si `APP_ENV !== e2e`, double garde-fou + le garde-fou de la commande).
- **Recommandation : Option A (SSH depuis `globalSetup`)** — zéro surface HTTP destructive, cohérent avec « l'hôte pilote la VM ». Le garde-fou structurel de la commande reste la défense ultime quel que soit le canal. *(Si la friction SSH côté runner est trop forte, basculer sur B avec route conditionnée à `APP_ENV === e2e`.)*

### DP-2 — Forme de l'instance e2e sur la VM
- **Option A — Second vhost/port dédié avec `.env.e2e`** : une instance servie en parallèle de l'instance de dev, propre `APP_ENV=e2e`, propre DB.
  - ✅ **Zéro risque de polluer le dev** (instances et DB physiquement séparées). ✅ Isolation nette.
  - ⚠️ Provisioning : un vhost de plus, un port/host à configurer.
- **Option B — Bascule d'environnement sur l'instance existante** : on bascule l'instance de dev en `e2e` le temps de la suite.
  - ⚠️ Risque de polluer/écraser l'état de dev ; bascule d'env fragile ; config cache à invalider.
- **Recommandation : Option A (vhost/port dédié)** — l'epic la qualifie de « plus sûre (zéro risque de polluer le dev) ». Le `baseURL` Playwright pointe sur ce vhost dédié. *(Provisioning décrit dans la doc, exécuté par henri.)*

### DP-3 — Stratégie de reset par cadence
- Défaut **par suite** (`globalSetup`) — le coût ~centisecondes autorise du par-test si une suite l'exige (à décider par story consommatrice). Documenter le défaut.

---

## Decisions (tranchées au cadrage — ne pas re-débattre)

### D-1 — Reset par template DB, pas `migrate:fresh --seed`
Reset = `DROP DATABASE` + `CREATE DATABASE … TEMPLATE …` (copie binaire ~100 ms). La template est construite (migrate+seed) **une seule fois**, reconstruite **uniquement** quand migrations/seeders changent. *Écartés : `migrate:fresh --seed` par suite (secondes vs centisecondes) ; truncate+reseed (fuites d'état).*

### D-2 — Garde-fou structurel obligatoire (code, pas config)
Le reset refuse de s'exécuter si `APP_ENV !== e2e` **ou** si la DB cible n'est pas suffixée `_e2e`. Implémenté en code (exception), calqué sur `DbSeedCommand::isProductionDatabase()`, avec détection du config cache. C'est un **invariant de sécurité testé** (T host).

### D-3 — `workers: 1` au départ
DB partagée par la suite → un seul worker. Parallélisme multi-DB = optimisation future triviale grâce à la template (hors scope).

### D-4 — Le `.env.e2e` réel n'est jamais commité
Seul `.env.e2e.example` (sans secret) est versionné. Le `.env.e2e` réel vit sur la VM, `chown www-admin`.

### D-5 — Provision VM = action de henri, documentée par la story
L'agent dev livre le code/config/doc ; il **n'exécute aucune** commande VM/SSH. La création réelle de DB/vhost/template et le smoke réel sont faits par henri (branche `playwrite` non syncée, déploiement explicite requis).

---

## Story

As a **développeur**,
I want **un harnais Playwright installé sur l'hôte (config, scripts npm, baseURL) ciblant une instance e2e dédiée de SE5 sur la VM (env Laravel `e2e`, DB Postgres `sambaedu_e2e` recréable depuis `sambaedu_e2e_template`)**,
so that **les tests e2e s'exécutent de façon reproductible sans jamais perturber l'instance de dev ni ses données**.

## Acceptance Criteria

1. **Given** le harnais Playwright installé sur l'hôte et l'instance e2e provisionnée sur la VM, **When** je lance la suite Playwright depuis l'hôte, **Then** un smoke test (la page de login `/login` s'affiche) passe contre l'instance e2e de la VM.
2. **Given** la configuration e2e, **Then** l'instance e2e utilise une DB Postgres dédiée **suffixée `_e2e`** (`sambaedu_e2e`) — jamais la DB de dev/prod.
3. **Given** la commande de build de template, **When** je l'exécute en env `e2e`, **Then** elle construit `sambaedu_e2e_template` (migrate + seed) ; **And** elle n'est à relancer **que** quand migrations/seeders changent.
4. **Given** le runner Playwright, **When** la suite démarre, **Then** le reset (`DROP`/`CREATE` depuis la template) est déclenché **avant la suite**, en ~centisecondes (pas de re-migration).
5. **Given** un contexte où `APP_ENV !== 'e2e'`, **When** le reset est invoqué, **Then** il **refuse explicitement** de s'exécuter (exception/erreur, aucune DB droppée).
6. **Given** une DB cible **non suffixée `_e2e`**, **When** le reset est invoqué (même avec `APP_ENV=e2e`), **Then** il **refuse explicitement** de s'exécuter (garde-fou structurel, pas une simple option de config).
7. **Given** le repo, **Then** `workers: 1` est le **défaut documenté** de la config Playwright.
8. **Given** le canal de tests existant (PHPUnit/SQLite), **Then** il est **strictement inchangé** (`phpunit.xml` non modifié, suite PHPUnit existante verte).
9. **Given** le garde-fou du reset, **Then** un test host (SQLite :memory:) prouve le refus quand `APP_ENV !== e2e` et/ou DB non suffixée `_e2e`.
10. **Given** le `.env.e2e.example` versionné, **Then** il documente toutes les clés nécessaires ; **And** aucun secret ni `.env.e2e` réel n'est commité.

## Tasks / Subtasks

- [x] **T0 — Recon + trancher DP-1/DP-2/DP-3** (no code) : confirmer le pattern `DbSeedCommand::isProductionDatabase()` (override `db:seed`, détection config cache `bootstrap/cache/config.php`), la connexion `pgsql` de `config/database.php`, la route/vue de login (`routes/web.php:35` → `auth.login`), l'absence de Playwright. Trancher DP-1 (canal reset : **recommandé SSH globalSetup**), DP-2 (instance : **recommandé vhost dédié**), DP-3 (cadence : **par suite**). Consigner les choix dans le Dev Agent Record. (AC: tous)
- [x] **T1 — Harnais Playwright (hôte)** : ajouter `@playwright/test` en devDependency ; `playwright.config.ts` (cohérent `"type":"module"`) avec `baseURL` = `process.env.E2E_BASE_URL`, `workers: 1`, `testDir: tests/e2e`, `globalSetup` pointant sur le reset. Scripts npm (`test:e2e`, `test:e2e:headed`). Documenter `npx playwright install` (navigateurs) **sans présumer son exécution en agent/CI**. (AC: 1,7)
- [x] **T2 — Commande artisan `e2e:reset`** : `DROP DATABASE IF EXISTS sambaedu_e2e` + `CREATE DATABASE sambaedu_e2e TEMPLATE sambaedu_e2e_template` via une connexion Postgres « maintenance » (DB `postgres`, car on ne peut pas dropper la DB active). **Garde-fou D-2 EN PREMIER** : refuser si `APP_ENV !== e2e` ou DB cible non suffixée `_e2e` (calqué `DbSeedCommand`, avec détection config cache). (AC: 4,5,6)
- [x] **T3 — Commande artisan `e2e:build-template`** : en env `e2e` uniquement, (re)construit `sambaedu_e2e_template` = `DROP`/`CREATE … _e2e_template` puis `migrate:fresh` + `db:seed` (réutilise `DatabaseSeeder` existant). Même garde-fou structurel (suffixe `_e2e_template` + `APP_ENV=e2e`). (AC: 3,5,6)
- [x] **T4 — Câblage `globalSetup`** : le `globalSetup` Playwright déclenche `e2e:reset` via le canal DP-1 (SSH si A : invocation `ssh … 'php artisan e2e:reset'` ; HTTP si B). Reset **par suite**. (AC: 4)
- [x] **T5 — Smoke test** `tests/e2e/smoke.spec.ts` : `page.goto('/login')` (résolu via `baseURL`) + assertion sur un élément stable du formulaire de login (champ identifiant/mot de passe, cf. `resources/views/auth/login.blade.php`). (AC: 1)
- [x] **T6 — `.env.e2e.example`** : toutes les clés (`APP_ENV=e2e`, `APP_URL`, `DB_*` pointant `sambaedu_e2e`, `CACHE_DRIVER`, …), commentaires sécurité. Vérifier `.gitignore` couvre `.env.e2e`. (AC: 2,10)
- [x] **T7 — Reconnaissance env `e2e`** : auditer les gardes existants qui pourraient bloquer/ignorer `e2e` (ex. `DbSeedCommand` n'autorise que sqlite/`_test` → s'assurer que le **reset/build e2e** a son propre garde acceptant `_e2e`, sans toucher le garde `db:seed` existant). Ajouter `e2e` aux env autorisés là où pertinent **sans élargir les permissions de prod**. (AC: 5,6,8)
- [x] **T8 — Test host du garde-fou** : `tests/Feature/E2e/E2eResetGuardTest.php` (ou Unit) — prouve que `e2e:reset` **refuse** quand `APP_ENV !== e2e` et quand DB non suffixée `_e2e` ; aucun appel destructif réel (mock/connexion sqlite, on teste le **chemin de refus** avant tout DROP). (AC: 9)
- [x] **T9 — Doc de provisioning** `docs/qa/e2e-setup.md` : pas-à-pas pour henri (créer DB `sambaedu_e2e` + template, vhost/port dédié DP-2, `.env.e2e` chown `www-admin`, `php artisan e2e:build-template`, lancer la suite depuis l'hôte avec `E2E_BASE_URL`). Rappel branche `playwrite` non syncée. (AC: 1,2,3)
- [x] **T10 — Validation host** : `php -l` sur les PHP créés/modifiés ; suite PHPUnit existante verte (non-régression AC8) ; lint/format du config Playwright. **Aucune commande VM/SSH.** (AC: 8,9)

## Dev Notes

- **Réutilisation > réécriture** : le garde-fou anti-prod existe déjà → `app/Console/Commands/DbSeedCommand.php` (`isProductionDatabase()`, détection `bootstrap/cache/config.php` qui **prime** sur les env vars). **Calquer** la logique pour `e2e:reset`/`e2e:build-template` (inverser le sens : on n'autorise QUE `APP_ENV=e2e` + suffixe `_e2e`). Ne pas réinventer.
- **DROP/CREATE Postgres** : on **ne peut pas dropper la DB à laquelle on est connecté**. Le reset doit ouvrir une connexion sur une DB de maintenance (`postgres`) — prévoir une connexion dédiée (ex. cloner la config `pgsql` avec `database => 'postgres'`) ou exécuter le DROP/CREATE via cette connexion. `CREATE DATABASE … TEMPLATE …` **échoue s'il y a des connexions ouvertes sur la template** → fermer/terminer les sessions ou s'assurer qu'aucune connexion n'est active sur `sambaedu_e2e` (ex. `pg_terminate_backend` sur la cible avant DROP).
- **Connexion e2e** : l'instance e2e utilise la connexion `pgsql` de `config/database.php` (`search_path=public`, params par env). Le `.env.e2e` pointe `DB_DATABASE=sambaedu_e2e`. Rien à changer dans `config/database.php` sauf éventuellement ajouter une connexion `pgsql_maintenance` (DB `postgres`) pour le DROP/CREATE — choix d'implémentation à documenter.
- **Login cible du smoke** : route `GET /login` (`routes/web.php:35`, `name('login')`) → `AuthController::showLogin` → `view('auth.login')` (`resources/views/auth/login.blade.php`). Asserter un sélecteur stable (champ login/mot de passe). Ne pas dépendre de texte traduit fragile.
- **PHP-FPM = `www-admin`** : tout fichier lu par l'app sur la VM (`.env.e2e`) doit être `chown www-admin` — **dans la doc de provisioning** (host-only, l'agent ne touche pas la VM).
- **Cache** : `CACHE_DRIVER` via `.env` (mémoire `project_story_16-15_cache_driver`, fallback APCu). En e2e, prévoir un store qui n'interfère pas avec le dev (ex. `array` ou un préfixe dédié). À documenter dans `.env.e2e.example`.
- **Non-régression PHPUnit (AC8)** : ne **pas** modifier `phpunit.xml`. Le canal e2e est entièrement séparé. Les nouvelles commandes artisan ne doivent pas casser le bootstrap des tests existants.
- **Sécurité (cœur de la story)** : le garde-fou D-2 est l'invariant le plus important. Il doit être **impossible** de dropper une DB non `_e2e` ou hors `APP_ENV=e2e`, même si quelqu'un mécomprend la config. C'est du **code testé** (T8), pas un commentaire.

### Project Structure Notes

- **Côté hôte (JS/Playwright)** : `playwright.config.ts` à la racine du repo ; tests dans `tests/e2e/` ; `globalSetup` dans `tests/e2e/global-setup.ts` (ou `support/`). Scripts dans `package.json`.
- **Côté Laravel (PHP)** : commandes dans `app/Console/Commands/` (`E2eResetCommand.php`, `E2eBuildTemplateCommand.php` — signatures `e2e:reset`, `e2e:build-template`). Test garde-fou dans `tests/Feature/E2e/` ou `tests/Unit/E2e/`.
- **Config/env** : `.env.e2e.example` à la racine (versionné) ; `.env.e2e` jamais commité (vérifier `.gitignore`).
- **Doc** : `docs/qa/e2e-setup.md` (+ référence depuis `docs/qa/README.md` si pertinent).

### References

- [Source: _bmad-output/planning-artifacts/epics.md#Epic 21 : Tests E2E Playwright sur Postgres préseedé] (décisions de cadrage 2026-06-04, contraintes transverses, Story 21.1, points de décision, prérequis)
- [Source: sambaedu-reload/app/Console/Commands/DbSeedCommand.php] (pattern garde-fou anti-prod à calquer : `isProductionDatabase()` + détection config cache)
- [Source: sambaedu-reload/phpunit.xml] (canal de tests existant SQLite :memory:, à ne PAS modifier — AC8)
- [Source: sambaedu-reload/config/database.php:66-79] (connexion `pgsql` paramétrée par env, utilisée par l'instance e2e)
- [Source: sambaedu-reload/database/seeders/DatabaseSeeder.php] (seed réutilisé pour la construction de template — pas d'utilisateurs/établissement, c'est 21.3)
- [Source: sambaedu-reload/routes/web.php:35] (route `GET login` → `AuthController::showLogin`, cible du smoke)
- [Source: sambaedu-reload/resources/views/auth/login.blade.php] (page de login affichée par le smoke)
- [Source: sambaedu-reload/package.json] (projet Vite `"type":"module"`, scripts à étendre)
- [Source: CLAUDE.md] (sync inotify `main` only, cibles SSH `/vm`, jamais de sync manuelle)
- [Mémoire: project_php_fpm_user_www_admin] (PHP-FPM uid 599 www-admin — chown `.env.e2e`)
- [Mémoire: feedback_worktree_no_vm_sync / project_inotify_no_delete_sync] (branche non syncée → pas de VM depuis l'agent)

## Questions pour Henri — ✅ toutes tranchées (2026-06-04)

- **Q-1 (DP-1)** : ✅ **SSH depuis `globalSetup`** (pas de route HTTP destructive).
- **Q-2 (DP-2)** : ✅ **vhost/port dédié + `.env.e2e`** (provisioning documenté, exécuté par henri).
- **Q-3** : ✅ **`e2e:reset` / `e2e:build-template`**.
- **Q-4** : ✅ **connexion de maintenance construite à la volée** (DB `postgres`) — pas d'entrée permanente dans `config/database.php`.

## Dev Agent Record

### Agent Model Used

claude-opus-4-8[1m] (dev-story BMAD), branche `playwrite`, 2026-06-04.

### Décisions T0 (recon + points de décision tranchés)

Recon confirmée :
- Garde-fou anti-prod existant `app/Console/Commands/DbSeedCommand.php` (override `db:seed`, `isProductionDatabase()`, détection config cache `bootstrap/cache/config.php` qui prime sur les env vars) → **pattern calqué et INVERSÉ** (allowlist e2e) dans le trait `Concerns/GuardsE2eDatabase`.
- Connexion `pgsql` paramétrée par env dans `config/database.php` (search_path=public) → utilisée par l'instance e2e ; connexion de maintenance clonée à la volée (DB `postgres`), **aucune entrée permanente ajoutée** (Q-4).
- `DatabaseSeeder` existant réutilisé tel quel pour la template (seed de référence = 21.3, hors scope).
- Route login réelle = `GET /authentication/login` (prefix `authentication` + `login`, name `auth.login`, `routes/web.php:35`) → cible du smoke. **Précision vs story** : la story mentionnait `/login` ; le chemin effectif est `/authentication/login` (vérifié), retenu dans le smoke.
- Vue `resources/views/auth/login.blade.php` : champs stables `#login` et `#password` → sélecteurs du smoke.
- Aucun outillage navigateur préexistant (Playwright/Dusk absents) — confirmé.
- Enregistrement des commandes : `app/Console/Kernel.php` fait `$this->load(__DIR__.'/Commands')` (auto-discovery) → les 2 nouvelles commandes sont prises en compte sans modification du Kernel.

Points de décision (tranchés par henri 2026-06-04, **appliqués sans re-débat**) :
- **DP-1** = SSH depuis `globalSetup` (`tests/e2e/global-setup.ts` ouvre une session SSH et lance `php artisan e2e:reset` ; params SSH configurables via `E2E_SSH_*`/`E2E_PROJECT_PATH`/`E2E_ARTISAN` ; aucune surface HTTP destructive).
- **DP-2** = vhost/port dédié + `.env.e2e` (provisioning documenté `docs/qa/e2e-setup.md`, exécuté par henri).
- **DP-3** = reset **par suite** (`globalSetup`), `workers: 1` (D-3).
- **Q-3** = commandes `e2e:reset` / `e2e:build-template`.
- **Q-4** = connexion Postgres de maintenance construite à la volée (clone `pgsql` → DB `postgres`), pas d'entrée permanente.

### Debug Log References

- `npm install` (hôte) : `@playwright/test ^1.49.0` ajouté, 80 packages installés.
- `npx playwright test --list` : config + globalSetup + smoke transpilés OK (esbuild loader), 1 test découvert. `--list` n'exécute PAS le globalSetup → aucun SSH déclenché.
- `git check-ignore` : `.env.e2e` ignoré, `.env.e2e.example` traçable (négation `!` active).

### Completion Notes List

- **T0** recon + décisions consignées ci-dessus.
- **T1** `playwright.config.ts` (type:module, `baseURL=process.env.E2E_BASE_URL`, `workers:1`, `fullyParallel:false`, `testDir tests/e2e`, `globalSetup`) + 4 scripts npm (`test:e2e[:headed|:ui|:report]`). `npx playwright install` (navigateurs) **documenté, non exécuté** (action hôte de henri).
- **T2** `E2eResetCommand` (`e2e:reset`) : garde-fou D-2 EN PREMIER (refus → FAILURE avant toute connexion), puis `pg_terminate_backend` sur la cible + `DROP DATABASE IF EXISTS` + `CREATE DATABASE … TEMPLATE …` via connexion de maintenance.
- **T3** `E2eBuildTemplateCommand` (`e2e:build-template`) : même garde-fou (+ revalidation suffixe `_e2e_template`), DROP/CREATE template vide, puis `migrate:fresh --force` + `db:seed --force` en repointant temporairement `pgsql.database` sur la template (restauration en `finally`).
- **T4** `global-setup.ts` : reset via `ssh -i <key> -p <port> user@host 'cd <projet> && php artisan e2e:reset'` (BatchMode, accept-new) ; `E2E_RESET_DISABLED=1` saute le reset ; échec SSH → erreur actionnable bloquante.
- **T5** `smoke.spec.ts` : `goto('/authentication/login')`, asserte réponse 2xx + `#login` + `#password` + `form[method=POST]` visibles.
- **T6** `.env.e2e.example` (versionné, sans secret) + `.gitignore` (`.env.e2e` ignoré, `!.env.e2e.example` re-tracké).
- **T7** Audit env e2e : aucun garde existant ne bloque `e2e` (les providers GpoServiceProvider/WpkgDeploymentServiceProvider/IpxeServiceProvider/AuthV1ServiceProvider/AppServiceProvider ne spécialisent que `'testing'` ; `e2e` boote comme une instance réelle — cohérent, le fake AD est 21.2). **Aucune modification** des gardes existants (`DbSeedCommand` intact ; `db:seed --force` utilisé par build-template). Pas d'élargissement des permissions de prod.
- **T8** `tests/Feature/E2e/E2eResetGuardTest.php` : 5 cas de **refus** (env≠e2e, base non `_e2e`, piège `_e2e_template` pour reset, pour les 2 commandes), assertion `assertExitCode(1)`. **AUCUN test du chemin autorisé** (éviterait un vrai DROP si un Postgres était joignable — explicitement proscrit T8).
- **T9** `docs/qa/e2e-setup.md` (runbook provisioning henri) + `docs/qa/domains/e2e-infra.md` (runbook domaine, scénarios numérotés stables) + entrée README QA.
- **T10** Validation host : `npx playwright test --list` vert (harnais OK). **`php -l` et la suite PHPUnit n'ont PAS pu être exécutés : ni `php` ni `vendor/` ne sont présents sur l'hôte** (le code tourne sur la VM, cf. CLAUDE.md). PHP relu manuellement (syntaxe, accolades, usage API). `phpunit.xml` **non modifié** (AC8).

### Écarts vs story

- **Chemin du smoke** : `/authentication/login` (réel) au lieu de `/login` (mentionné story). Justifié par recon de `routes/web.php` (prefix `authentication`).
- **Validation host PHP** : `php -l` + PHPUnit non exécutables sur l'hôte (absence de `php`/`vendor`). Non-régression AC8 garantie structurellement (`phpunit.xml` intact, nouvelles commandes auto-découvertes sans modifier le bootstrap) ; exécution réelle de la suite PHPUnit à confirmer sur la VM par henri.

### Reste à faire par henri (hors scope agent)

- Provisionner l'instance e2e sur la VM : rôle+DB `sambaedu_e2e` (CREATEDB), `.env.e2e` (chown `www-admin` uid 599), vhost/port dédié, `php artisan e2e:build-template`. Cf. `docs/qa/e2e-setup.md`.
- Hôte : `npx playwright install` (téléchargement navigateurs), exporter `E2E_BASE_URL`/`E2E_SSH_*`, lancer `npm run test:e2e` (smoke réel — AC1).
- Faire tourner la suite PHPUnit sur la VM pour confirmer la non-régression AC8 (`E2eResetGuardTest` inclus).

### File List

**Créés :**
- `playwright.config.ts`
- `tests/e2e/global-setup.ts`
- `tests/e2e/smoke.spec.ts`
- `app/Console/Commands/E2eResetCommand.php`
- `app/Console/Commands/E2eBuildTemplateCommand.php`
- `app/Console/Commands/Concerns/GuardsE2eDatabase.php`
- `app/Console/Commands/Concerns/UsesMaintenanceConnection.php`
- `tests/Feature/E2e/E2eResetGuardTest.php`
- `.env.e2e.example`
- `docs/qa/e2e-setup.md`
- `docs/qa/domains/e2e-infra.md`

**Modifiés :**
- `package.json` (devDependency `@playwright/test` + scripts `test:e2e*`)
- `.gitignore` (`.env.e2e` ignoré, `!.env.e2e.example` re-tracké)
- `docs/qa/README.md` (entrée domaine `e2e-infra`)
- `_bmad-output/implementation-artifacts/sprint-status.yaml` (21-1 → review + last_updated)
- `_bmad-output/implementation-artifacts/21-1-socle-playwright-environnement-e2e.md` (cette story : checkboxes, Dev Agent Record, Status, Change Log)

## Change Log

| Date       | Auteur                  | Changement |
|------------|-------------------------|------------|
| 2026-06-04 | claude-opus-4-8[1m] dev | Implémentation socle Playwright + env e2e (harnais hôte, commandes `e2e:reset`/`e2e:build-template`, garde-fou structurel D-2, smoke `/authentication/login`, `.env.e2e.example`, doc provisioning). Status → review. |

## Recommandation Modèle Dev

**opus.**

Justification : story d'infrastructure transverse à surface de risque élevée. Le cœur est un **garde-fou destructif** (`DROP DATABASE`) dont une erreur de logique peut détruire la DB de dev/prod de la VM — exactement le genre d'invariant de sécurité où un raisonnement rigoureux prime. S'y ajoutent : une **nouvelle librairie** (Playwright, jamais présente dans le repo) avec son intégration hôte→VM, un **modèle d'exécution inhabituel** (template DB Postgres, connexion de maintenance, ordre DROP/CREATE/terminate des sessions), des **garde-fous structurels testés** à calquer finement sur `DbSeedCommand`, et un **piège de contexte fort** (branche `playwrite` non syncée, interdiction VM/SSH pour l'agent) qui exige de bien distinguer ce qui est codé de ce qui est délégué à henri. Plusieurs fichiers (config JS, 2 commandes artisan, test garde-fou, env example, doc), logique critique sécurité, intégration d'une lib nouvelle → opus.

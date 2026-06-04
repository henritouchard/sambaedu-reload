# Provisioning de l'instance e2e (Playwright) — Runbook Henri

> **Story 21.1 — Socle Playwright + environnement e2e.** Ce document décrit le
> **provisioning manuel** de l'instance e2e sur la VM et le lancement de la
> suite depuis l'hôte. Le socle (config Playwright, commandes artisan, garde-fous,
> doc) est livré par le dev ; **la provision réelle est une action d'exploitation
> exécutée par Henri**, jamais par l'agent dev.

---

## ⚠️ Pièges à connaître avant de commencer

- **Branche `playwrite` NON syncée.** La sync inotify host→VM ne concerne que
  `main`. Le code de la branche `playwrite` **n'est pas sur la VM**. Les e2e
  valident le code **déployé** sur la VM. Déployer la branche `playwrite` pour
  la tester = **action explicite** (merge/checkout sur la VM), jamais automatique.
- **PHP-FPM tourne sous `www-admin` (uid 599), pas `www-data`.** Tout fichier lu
  par l'app — en particulier `.env.e2e` — doit être `chown www-admin`, sinon
  l'instance ne démarre pas / ne lit pas sa config.
- **Garde-fou structurel.** Les commandes `e2e:reset` / `e2e:build-template`
  **refusent** de s'exécuter si `APP_ENV != e2e` **ou** si la base cible n'est
  pas suffixée `_e2e`. C'est volontaire (impossible de dropper dev/prod). Si une
  commande refuse, vérifier `APP_ENV` **et** un éventuel config cache
  (`bootstrap/cache/config.php` prime sur le `.env` → `php artisan config:clear`).

---

## Topologie cible (décisions 21.1)

- **DP-2 — vhost/port dédié + `.env.e2e`** : l'instance e2e est servie en
  parallèle de l'instance de dev, avec son propre `APP_ENV=e2e` et sa propre DB
  `sambaedu_e2e`. Isolation physique → zéro risque de polluer le dev.
- **D-1 — reset par template DB** : `sambaedu_e2e` est recréée en ~centisecondes
  par copie binaire de `sambaedu_e2e_template` (DROP + CREATE … TEMPLATE), sans
  re-migration. La template n'est reconstruite que quand migrations/seeders changent.
- **DP-1 — reset déclenché par SSH** depuis le `globalSetup` Playwright (hôte).
- **DP-3 / D-3 — reset par suite**, `workers: 1` au départ.

```
   HÔTE (Henri)                                  VM (192.168.122.50)
 ┌───────────────┐   E2E_BASE_URL (HTTP)   ┌──────────────────────────────┐
 │ Playwright    │ ─────────────────────► │ vhost e2e  (APP_ENV=e2e)      │
 │ tests/e2e     │                         │   └─ DB sambaedu_e2e          │
 │ globalSetup   │ ─── ssh artisan ──────► │        ↑ CREATE … TEMPLATE    │
 └───────────────┘   e2e:reset             │   DB sambaedu_e2e_template    │
                                           └──────────────────────────────┘
```

---

## Étape 1 — Rôle et base Postgres e2e (sur la VM)

Créer un rôle avec privilège `CREATEDB` (nécessaire pour DROP/CREATE … TEMPLATE)
et la base e2e suffixée `_e2e` :

```sql
-- En tant que superutilisateur Postgres
CREATE ROLE sambaedu_e2e WITH LOGIN PASSWORD '<secret>' CREATEDB;
CREATE DATABASE sambaedu_e2e OWNER sambaedu_e2e;
```

> Le suffixe `_e2e` est **obligatoire** : le garde-fou des commandes refuse tout
> nom de base qui ne s'y termine pas.

---

## Étape 2 — `.env.e2e` (sur la VM, chown www-admin)

Copier le modèle versionné et le compléter avec les secrets :

```bash
cd /var/www/sambaedu-reload
cp .env.e2e.example .env.e2e
# Éditer .env.e2e : APP_URL (vhost e2e), DB_PASSWORD, etc.
php artisan key:generate --env=e2e        # remplit APP_KEY de .env.e2e

# IMPÉRATIF : PHP-FPM tourne sous www-admin (uid 599)
chown www-admin:www-admin .env.e2e
chmod 640 .env.e2e
```

Clés essentielles (cf. `.env.e2e.example` pour la liste complète) :

| Clé            | Valeur e2e                         | Rôle |
|----------------|------------------------------------|------|
| `APP_ENV`      | `e2e`                              | Invariant sécurité n°1 (garde-fou) |
| `APP_URL`      | URL du vhost e2e                   | Doit = `E2E_BASE_URL` côté hôte |
| `DB_DATABASE`  | `sambaedu_e2e`                     | Invariant sécurité n°2 (suffixe `_e2e`) |
| `CACHE_DRIVER` | `array` (ou store dédié préfixé)   | Ne pas partager le cache du dev |
| `SESSION_DRIVER` / `QUEUE_CONNECTION` | `array` / `sync` | Pas de worker/fichier partagé |

---

## Étape 3 — vhost/port dédié (sur la VM)

Configurer un second vhost Apache (ou bloc serveur) qui sert
`/var/www/sambaedu-reload/public` en **chargeant `.env.e2e`**. Deux approches
courantes :

- Variable d'environnement de pool PHP-FPM dédié : `APP_ENV=e2e` +
  `setenv APP_ENV e2e` côté Apache, ou
- Pool PHP-FPM e2e séparé pointant un répertoire avec `.env` → `.env.e2e`.

Servir sur un port/host distinct de l'instance de dev (ex. `:8081`). Reporter ce
host:port dans `APP_URL` (`.env.e2e`) **et** dans `E2E_BASE_URL` (hôte).

> Le détail exact du vhost dépend de la conf Apache/FPM de la VM (hors git,
> provisioning). Ne pas réutiliser le vhost de dev (risque de pollution).

---

## Étape 4 — Construire la template (sur la VM)

**Une seule fois**, puis à **chaque changement de migrations/seeders** :

```bash
cd /var/www/sambaedu-reload
APP_ENV=e2e php artisan e2e:build-template
```

Cette commande (en env `e2e` uniquement) :
1. applique le garde-fou (refuse hors e2e / base non `_e2e`),
2. DROP/CREATE `sambaedu_e2e_template` (vide),
3. `migrate:fresh` + `db:seed` (DatabaseSeeder existant) **sur la template**.

> Le seed de référence e2e (établissement + utilisateurs par rôle) est l'objet
> de la **Story 21.3**. Pour 21.1, on réutilise `DatabaseSeeder` tel quel pour
> valider que la template se construit.

> **Story 21.2 — nouvelle migration `e2e_ad_writes`** (journal du fake AD).
> Elle est **e2e-only** : son `up()` ne crée la table QUE si `APP_ENV=e2e`.
> Après pull de 21.2, **reconstruire la template** (`e2e:build-template`) pour
> que `migrate:fresh` matérialise la table dans `sambaedu_e2e_template` — sinon
> `GET /e2e/ad-writes` et la capture des écritures échoueront (« table absente »).
> Renseigner aussi `E2E_FAKE_AD_PASSWORD` dans `.env.e2e` (credential des users
> seedés pour l'auth fake — cf. `.env.e2e.example`).

---

## Étape 5 — Reset (déclenché automatiquement par la suite)

Pas d'action manuelle en routine : le `globalSetup` Playwright (hôte) ouvre une
session SSH et lance `php artisan e2e:reset` **avant** la suite. La base
`sambaedu_e2e` est recréée depuis la template en ~centisecondes.

Vérification manuelle possible :

```bash
cd /var/www/sambaedu-reload
APP_ENV=e2e php artisan e2e:reset
```

---

## Étape 6 — Lancer la suite depuis l'hôte

Sur le **poste hôte** (où Playwright et ses navigateurs sont installés) :

```bash
# Une fois : installer les navigateurs Playwright (téléchargement)
npx playwright install

# Variables d'environnement hôte (cf. .env.e2e.example, section HÔTE)
export E2E_BASE_URL=http://192.168.122.50:8081   # = APP_URL de l'instance e2e
export E2E_SSH_KEY=~/.ssh/id_se4fs_vm
export E2E_SSH_HOST=192.168.122.50
export E2E_PROJECT_PATH=/var/www/sambaedu-reload

# Lancer la suite (globalSetup → reset SSH, puis smoke)
npm run test:e2e
```

`npm run test:e2e:headed` pour voir le navigateur ; `npm run test:e2e:ui` pour
le mode interactif ; `npm run test:e2e:report` pour le rapport HTML.

Pour itérer sans toucher la DB e2e (debug local), `export E2E_RESET_DISABLED=1`
saute le reset.

---

## Checklist provisioning

- [ ] Rôle `sambaedu_e2e` (CREATEDB) + base `sambaedu_e2e` créés
- [ ] `.env.e2e` créé, `APP_ENV=e2e`, `DB_DATABASE=sambaedu_e2e`, `APP_KEY` généré
- [ ] `.env.e2e` `chown www-admin:www-admin`, `chmod 640`
- [ ] vhost/port e2e dédié servi, `APP_URL` = host:port e2e
- [ ] `php artisan e2e:build-template` OK (template construite)
- [ ] `php artisan e2e:reset` OK (refuse si on tente hors e2e — c'est attendu)
- [ ] Hôte : `npx playwright install` fait, `E2E_BASE_URL` exporté
- [ ] `npm run test:e2e` → smoke `/authentication/login` vert

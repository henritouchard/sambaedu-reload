# Rapport de test de déploiement — VM neuve

- **Date** : 2026-06-21
- **Cible** : `/vm` (`root@192.168.122.50`, Debian 12 / kernel 6.1, hostname `se4fs`)
- **Source** : clone frais de `git@github.com:henritouchard/sambaedu-reload.git` (branche `main`, HEAD `3e3cfdb` « windows capabilities ») dans `../test-deploy/`, copié par `rsync` vers `/var/www/sambaedu-reload`.
- **Méthode** : `bash scripts/install.sh` lancé détaché, stdin = `ny` (pour passer le prompt `interactive_env_validation`), `COMPOSER_ALLOW_SUPERUSER=1 DEBIAN_FRONTEND=noninteractive`.

## Résultat global

✅ **L'install se termine sans erreur fatale** et **le site native répond** :
- `GET /authentication/login` → **200**, page native rendue (titre SambaEdu, champs `login`/`password`, CSRF).
- `GET /app/dashboard` → **302** vers `/authentication/login` (auth guard OK).
- `GET /` → meta-refresh vers `user/index.php` (legacy, par design — routes non portées tombent sur le catch-all).
- Legacy vhost `:8082` → 200.
- PostgreSQL 16.14 healthy (docker), 124 migrations, 84 tables, `users` = 0 (normal : `APP_ENV=production` → `migrate --force` sans seed ; auth iso-legacy LDAP).
- 3 workers systemd actifs (`laravel-queue-{sync,worker,general}`), cron scheduler installé.
- `update.sh` (finalisation idempotente) : tout vert (NPM build, Laravel update, PKI Auth V1, Apache, systemd, LDAP SASL_NOCANON, **PXE bootstrap natif**, helpers wpkg.cmd, bundle WPKG natif, permissions partage).

⚠️ **MAIS l'authentification LDAP est cassée out-of-the-box** (voir BUG #1) : 176 `production.ERROR` au démarrage. Le site charge, la page de login s'affiche, **mais un utilisateur ne pourra pas se connecter**.

---

## Manquements

### 🔴 BUG #1 — `create-env.sh` : regex qui jette toutes les clés `se4*` (CRITIQUE)

`scripts/create-env.sh` source la conf via :

```bash
source <(grep -E '^[a-z_]+ = ' /etc/sambaedu/sambaedu.conf | sed 's/ = /=/g')
```

La classe de caractères `[a-z_]` **ne contient pas les chiffres**. Toute clé contenant un chiffre est donc silencieusement ignorée : `se4ad_ip`, `se4ad_etab_ip`, `se4fs_ip`, `se4fs_name`, `se4_url`, `se4_key`, `se4install_name`, `se4install_passwd`… (le `4` casse `[a-z_]+` avant ` = `).
Seules les clés sans chiffre passent (`sql_passwd` → `DB_PASSWORD` ✅, `ipxe_url` du conf.d → `IPXE_URL` ✅).

**`.env` généré (constaté) :**

| Variable | Attendu (depuis `sambaedu.conf`) | Obtenu |
|---|---|---|
| `DB_PASSWORD` | `U-W6PiYSK7` (`sql_passwd`) | `U-W6PiYSK7` ✅ |
| `IPXE_URL` | `http://192.168.122.50/ipxe/` (conf.d) | idem ✅ |
| `SE4AD_IP` | `192.168.122.60` (`se4ad_ip`) | **vide** ❌ |
| `SE4AD_ETAB_IP` | `192.168.122.60` (fallback `se4ad_ip`) | **vide** ❌ |
| `SE4FS_IP` | `192.168.122.50` (`se4fs_ip`) | **vide** ❌ |
| `SE4FS_NAME` | `se4fs` (`se4fs_name`) | **vide** ❌ |

**Conséquence fonctionnelle directe** — runtime LDAP (`app/Config/LdapConfig.php:136`) :

```
production.ERROR: LdapConfig: AD établissement non configuré et mode strict activé
  {"etab_ip":"","central_ip":"192.168.122.60","strict_mode":true}
AD établissement (se4ad_etab_ip) non configuré. En mode strict, la connexion
  à l'AD central est interdite. Configurez SE4AD_ETAB_IP dans le fichier .env
```

`SE4AD_ETAB_IP` vide + mode strict ⇒ **connexion LDAP interdite ⇒ login impossible**. (Note : `sambaedu:doctor` affiche `✓ Bind LDAP AD` car le *doctor* lit les paramètres LDAP directement depuis `sambaedu.conf` ; c'est uniquement le chemin runtime LdapRecord, qui dépend du `.env`, qui casse.)

**Correctif** : ajouter les chiffres à la classe, aux **2** endroits (bloc conf principal + boucle conf.d) :

```bash
grep -E '^[a-z0-9_]+ = '   # au lieu de '^[a-z_]+ = '
```

---

### 🟠 BUG #2 — `SE4FS_INSTANCE_ID` non régénéré (`uuidgen` absent)

`create-env.sh` ne régénère `SE4FS_INSTANCE_ID` que si `uuidgen` existe. **`uuidgen` n'est pas installé** sur la VM ⇒ la valeur **placeholder de `.env.example`** est conservée (`fc31c204-5c4f-4033-9b75-00abea24e8d3`). Deux déploiements partagent donc le même ID d'instance.
(`SE4FS_INSTANCE_API_KEY` est bien régénérée car basée sur `php`, pas `uuidgen`.)

**Correctif** : soit installer `uuid-runtime`, soit générer l'UUID via PHP (comme l'API key) pour supprimer la dépendance :
`SE4FS_INSTANCE_ID=$(php -r 'printf("%04x%04x-%04x-%04x-%04x-%04x%04x%04x", ...);')` ou `Str::uuid()`.

---

### 🟠 BUG #3 — `APP_URL` toujours écrasée par le placeholder

`install.sh::generate_env` écrase l'`APP_URL` (que `create-env.sh` avait — ou aurait — dû dériver de `se4_url`) par `https://URL_A_COMPLETER/<UAI>`. Comme **aucune clé `UAI` n'existe** dans `sambaedu.conf`, on obtient `APP_URL=https://URL_A_COMPLETER`. Confirmé au runtime (`artisan about` → `URL ... URL_A_COMPLETER`).
En `production`, URL placeholder ⇒ génération d'URL/redirections incorrectes (les redirections d'auth pointent déjà vers `https://127.0.0.1/...`). **Étape manuelle obligatoire** non automatisée.

**Piste** : si `se4_url` est présent (et une fois BUG #1 corrigé), l'utiliser comme défaut au lieu d'écraser systématiquement par le placeholder.

---

### 🟠 BUG #4 — Build agent Go échoue sur déploiement frais

Dans `update.sh` (finalisation), `build-agent.sh` échoue :

```
error obtaining VCS status: exit status 128
  Use -buildvcs=false to disable VCS stamping.
[!] Build agent Go échoué — artefact signé non produit
```

Cause : le repo copié est **owned by root** ; `git` refuse (`dubious ownership`) ⇒ le stamping VCS de `go build` plante. Non bloquant (l'update continue), mais **l'agent signé n'est pas produit** sur une install neuve.

**Correctif** : `git config --global --add safe.directory /var/www/sambaedu-reload` (+ `chown`), ou `go build -buildvcs=false` dans `build-agent.sh`.

---

### 🟡 Mineurs / environnement (hors périmètre strict du script)

- **`sambaedu:doctor` → 2 erreurs** :
  - `✗ Samba private files` : `passdb.tdb` / `netlogon_creds_cli.tdb` non lisibles par `uid=599 (www-admin)` ⇒
  - `✗ samba-tool gpo listall` (Exit 255, `Could not find machine account in secrets database`). Conséquence du point ci-dessus. Correctif : `setfacl -m u:www-admin:r /var/lib/samba/private/{passdb.tdb,netlogon_creds_cli.tdb,secrets.tdb}`.
- **`⚠ Config iPXE` : `SE4INSTALL_NAME` manquant** ⇒ les installs joignent le domaine à vide. (Double cause : clé absente de la conf **ET** serait jetée par BUG #1 car contient `4`.)
- **`ESTABLISHMENT_NAME` vide** : pas de clé `etab_name`/`UAI` dans cette `sambaedu.conf` (dépend des données du site).
- **`git dubious ownership`** sur `/var/www/sambaedu-reload` (repo root-owned après `rsync`).
- **`install.sh` est interactif** (`interactive_env_validation` : 2 `read`) ⇒ non automatisable sans TTY/feed stdin ; bloque un déploiement non-interactif (contourné ici via `printf "ny"`).
- **Redis** commenté dans `docker-compose.yml` mais `REDIS_PASSWORD` est généré et le service reste référencé (incohérence cosmétique ; sans impact car `QUEUE=database`, `CACHE=apc`).

---

## Pré-requis absents sur la VM neuve (tous auto-installés par `install.sh` ✅)

- Docker + plugin compose (via `get.docker.com`)
- Node/NPM (via NodeSource)
- `php8.2-pgsql` (via apt)

Présents d'origine : git, PHP 8.2, Composer, Apache 2.4.62, user `www-admin` (uid 599), `/etc/sambaedu/sambaedu.conf`, APCu, imagick.

## Recommandation de priorisation

1. **BUG #1** (regex `se4*`) — bloque le login, correctif trivial (1 caractère × 2 lignes).
2. **BUG #2** (uuidgen) et **BUG #3** (APP_URL) — identité d'instance + URL prod.
3. **BUG #4** (build agent Go) — pour que l'agent soit produit sur déploiement frais.
4. Mineurs (ACL Samba, SE4INSTALL_NAME, interactivité install.sh).

---

## Suivi / Résolution — 2026-06-21

- **BUG #1 — CORRIGÉ** dans `scripts/create-env.sh` : regex `^[a-z0-9_]+ = ` (2 occurrences,
  bloc conf principal + boucle conf.d). Validé contre la vraie `sambaedu.conf` :
  `SE4AD_IP`, `SE4AD_ETAB_IP`, `SE4FS_IP`, `SE4FS_NAME` désormais injectés.
- **BUG #2 — CORRIGÉ** dans `scripts/create-env.sh` : fallback PHP (UUID v4) quand `uuidgen`
  absent. `SE4FS_INSTANCE_ID` n'est plus le placeholder de `.env.example`.
- **BUG #3 — CORRIGÉ** dans `scripts/install.sh::generate_env` : l'override `URL_A_COMPLETER`
  est désormais **conditionnel** — appliqué uniquement si `APP_URL` est restée vide (pas de
  `se4_url`). Sinon la valeur dérivée par create-env.sh est conservée (le placeholder reste
  le garde-fou pour les déploiements proxifiés sans `se4_url`).
- **BUG #4 — NON un bug produit** : c'était un artefact de la méthode de test (repo copié par
  `rsync -a` → owned `user` uid 1000, build lancé en `root` → `git` refuse « dubious ownership »
  → `go build` (buildvcs=auto) échoue `exit 128`). Prouvé : après
  `git config --global --add safe.directory /var/www/sambaedu-reload`, `build-agent.sh --force`
  produit et signe `agent/build/dist/sambaedu-agent-2.2.17.exe`. En déploiement normal l'arbo
  sous `/var/www` n'est pas un repo git owné de travers → `buildvcs` est un no-op.
  *Durcissement optionnel* : `-buildvcs=false` sur le `go build` de `agent/build/build.sh`.

**.env de la VM de test** régénéré via le script corrigé (`.env.bak-pre-fix-2026-06-21` conservé) :
`APP_URL=http://se4fs` (sans `.fr`, choix opérateur), `SE4AD_ETAB_IP=192.168.122.60`. Après
`config:cache` + **restart complet** de php-fpm (un simple `reload` laisse OPcache servir
l'ancien cache) : plus aucune erreur LDAP `mode strict`, `authentication/login` → 200.

> Modifications de scripts **non commitées** (repo hôte = source de vérité). Cette VM neuve
> n'a pas de sync inotify : les scripts corrigés ont été poussés manuellement par `scp`
> (sync manuel autorisé pour la session).

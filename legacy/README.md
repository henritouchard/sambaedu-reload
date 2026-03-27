# Legacy Bridge

Ce dossier contient le mecanisme de pont entre le code legacy SambaEdu (PHP natif, LDAP direct) et l'application Laravel SER (SambaEdu Reload).

## Architecture globale

```
Client (navigateur / script iPXE / Windows)
    |
    v
Apache port 80
DocumentRoot: /var/www/sambaedu-reload/public/
    |
    v
Laravel (SER) - routes/web.php
    |
    |-- Route Laravel definie ? (/users, /admin...)
    |   OUI -> Controller Laravel classique
    |
    |-- Route bloquee ? (config/sambaedu.php blocked_legacy_routes)
    |   OUI -> Redirect vers l'equivalent SER
    |
    |-- legacy/modules/{path} existe ?
    |   OUI -> executeViaBootstrap()
    |          Execution dans le process Laravel avec shims
    |
    |-- Fichier existe sur le vhost legacy ?
    |   OUI -> proxyToLegacy()
    |          HTTP interne vers 127.0.0.1:8082 (vhost legacy)
    |          Si page HTML -> nettoyage chrome + embed layout SER
    |          Si script/API -> retour brut
    |
    '-- Sinon -> 404
```

## Deux modes d'execution legacy

### 1. Bootstrap direct (`executeViaBootstrap`)

Pour les modules copies dans `legacy/modules/`. Le code legacy s'execute **dans le process Laravel** avec les shims LDAP/config a la place des vrais appels AD.

**Avantages** : pas de vhost necessaire, donnees via Eloquent/PostgreSQL.
**Limites** : seules les fonctions shimmees fonctionnent. Si le module appelle une fonction LDAP non shimmee, ca plante.

**Adapte pour** : modules a faible dependance LDAP (auth, SSO, display, API).

### 2. Proxy HTTP (`proxyToLegacy`)

Pour tout le reste. Laravel proxie la requete vers le vhost legacy interne (port 8082) qui execute le PHP avec le vrai `ldap.inc.php`, la vraie connexion AD, etc.

**Avantages** : compatibilite totale, aucune fonction a shimmer.
**Limites** : necessite le vhost legacy fonctionnel + AD accessible.

**Detection auto page web vs script** : apres reception de la reponse du vhost, le catchall detecte si c'est une page HTML (presence de `<form>`, `<table>`, `<div>`...). Si oui, le chrome legacy (header, sidebar, styles) est nettoye et le contenu est embed dans le layout SER. Sinon (JSON, text/plain, iPXE...), la reponse est retournee brute.

## Structure du dossier

```
legacy/
  README.md              # Ce fichier
  bootstrap.php          # Initialise le contexte Laravel pour les modules legacy
  config.inc.php         # Config bridge : $config array Laravel -> format legacy
  ldap.inc.php           # Shim LDAP : fonctions legacy -> Eloquent/PostgreSQL
  wpkg_libsql.php        # Shim SQL pour les fonctions WPKG
  encrypt_compat.php     # Compatibilite chiffrement
  stubs/                 # Interceptent les require() des modules legacy
    config.inc.php       # Charge le bridge + toutes les fonctions utilitaires
    ldap.inc.php         # Redirige vers le shim LDAP
    admin_ui.inc.php     # Neutralise le chrome UI legacy (header, sidebar, footer)
  modules/               # Modules legacy copies, executes via bootstrap
    api/                 # Endpoint ecowatt
    cas/                 # Authentification CAS
    display/             # Page d'affichage (accueil legacy)
    dossier_echange/     # Gestion dossiers partages
    oauth2/              # Login/callback OAuth2
    sso/                 # SSO (CAS, OAuth2, OpenID)
    vendor/              # Bridge autoload.php -> autoloader Laravel
```

## Mecanisme des stubs

Les modules legacy font `require 'config.inc.php'` ou `require 'ldap.inc.php'`. Sans intervention, ils chargeraient les fichiers originaux depuis `/var/www/sambaedu/includes/`, provoquant des redeclarations fatales de fonctions deja definies par nos shims.

**Solution** : `bootstrap.php` prepend `legacy/stubs/` dans l'include_path **avant** `sambaedu/includes/`. Quand un module fait `require 'config.inc.php'`, PHP resout vers `legacy/stubs/config.inc.php` (notre version) au lieu de l'original.

```
Ordre include_path :
  legacy/stubs/  ->  sambaedu/includes/  ->  reste du path PHP
```

Chaque stub utilise des guards `if (!function_exists(...))` pour eviter les conflits en cas de double chargement.

## Shim LDAP (`ldap.inc.php`)

Remplace les fonctions LDAP de haut niveau par des requetes Eloquent/PostgreSQL :

| Fonction legacy | Shim |
|---|---|
| `search_ad()` | Query Eloquent sur User, UserGroup, Workstation |
| `search_user()` | `User::where('login', ...)` |
| `modify_ad()` | `User::update(...)` / `UserGroup::update(...)` |
| `have_right()` | Verification role/droits via Eloquent |
| `is_eleve()`, `is_prof()` | Check `$user->role` |
| `my_etabs()`, `activated_etabs()` | Retourne l'etab courant (mono-etab) |
| `etab_to_name()` | Retourne le nom depuis `$config['etab_name']` |

Les fonctions non shimmees loggent une erreur via `ErrorLoggerService` et retournent une valeur neutre (tableau vide, false...).

## Config bridge (`config.inc.php`)

Construit le tableau `$config` global (format legacy) a partir de `config('sambaedu.*')` Laravel :

- `$config['ldap_base_dn']` <- `config('sambaedu.legacy_ldap.base_dn')`
- `$config['etab_ou']` <- `config('sambaedu.etab_ou')`
- `$config['login']` <- `auth()->user()->login`
- Constantes WOL/shutdown/WPKG definies statiquement
- DNs construits (people, groups, computers...)

## Stub config (`stubs/config.inc.php`)

Charge le bridge puis expose les 36 fonctions utilitaires du `config.inc.php` legacy original (cache, params, proxy, FPM...) avec guards `function_exists`.

**Fonctions overridees** (version Laravel) :
- `get_config()` : retourne le `$config` global du bridge au lieu de lire `/etc/sambaedu/`
- `header_authorize()` : retourne vide (l'auth est geree par Laravel)

## Nettoyage HTML pour l'embed

Quand une page legacy est embeddee dans le layout SER (via `legacy-embed.blade.php`), le HTML est nettoye :

1. Suppression `<!DOCTYPE>`, `<html>`, `<head>`, `<body>`
2. Suppression `<link stylesheet>` et `<style>` (evite les conflits CSS)
3. Suppression `<header class="page-header">` (chrome legacy)
4. Suppression `<nav class="navbar...topbar">` (topbar Bootstrap legacy)
5. Suppression `<div id="menu">` (sidebar legacy)
6. Suppression "Bonjour xxx" (redondant avec navbar SER)
7. Suppression scripts legacy (jQuery, sambaedu.js)
8. Neutralisation `position:absolute` (reintegre le contenu dans le flux)
9. Reecriture des `action` de formulaires vers l'URL courante
10. Injection token CSRF dans les formulaires POST

## Blocage de routes migrees

Dans `config/sambaedu.php` :

```php
'blocked_legacy_routes' => [
    '^annu2/annu\.php' => '/users',  // redirige vers la page SER
],
```

Les patterns sont des regex testees sur le path. Quand un match est trouve, l'utilisateur est redirige vers l'URL SER correspondante. Les `allowed_legacy_routes` prennent la priorite (pour autoriser des sous-pages specifiques).

## Vhost legacy

Le vhost legacy tourne sur `127.0.0.1:8082` (config dans `/etc/apache2/sites-enabled/sambaedu-legacy.conf`). Il n'est **pas accessible de l'exterieur** — uniquement via le proxy interne du catchall. Il execute le PHP legacy avec le vrai `config.inc.php`, le vrai `ldap.inc.php`, et la vraie connexion AD.

## Cycle de vie d'un module legacy

1. Le module est d'abord accessible via le **proxy** (aucun travail necessaire)
2. Optionnellement, il est copie dans `legacy/modules/` pour tourner via **bootstrap** (si les shims couvrent ses besoins)
3. Une page SER equivalente est developpee en Livewire
4. La route legacy est ajoutee dans `blocked_legacy_routes` avec redirection vers la page SER
5. Le module est retire de `legacy/modules/` si necessaire

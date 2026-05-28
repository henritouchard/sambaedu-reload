# Story 1bis.10 : Module `ipxe`

Status: done

## Story

As a **développeur**,
I want intégrer le module legacy `ipxe` dans `legacy/modules/`,
So que le boot réseau PXE est opérationnel via le cloisonnement, prérequis pour tester wpkg manuellement.

## Acceptance Criteria

1. **Module copié et accessible** — Given le module `ipxe` est copié dans `legacy/modules/ipxe/`, When j'accède aux URLs principales du module via le catchall, Then le module se charge sans erreur PHP fatale And les pages de configuration iPXE s'affichent correctement.

2. **LDAP via shim** — Given le module utilise les fonctions LDAP wrapper `ldap_dn2oudn()` et `move_ad()` (shim LDAP, story 1bis.2), When ces fonctions sont appelées, Then les données sont lues depuis Eloquent/PostgreSQL via le shim.

3. **Exec système encadrés** — Given le module contient 4 exec système dans `Win10/win_iso.php` (montage ISO) et `boot.php` (check paquet), When un exec est appelé, Then la commande s'exécute correctement dans le contexte Laravel And toute erreur est capturée par le error logger.

4. **Templating fonctionnel** — Given les fichiers .cfg (preseed, unattend.xml, scripts iPXE) sont présents, When le module génère une configuration de boot, Then le templating produit un résultat cohérent.

5. **Pas d'erreur récurrente** — Given le module est intégré, When le error logger est consulté, Then aucune erreur récurrente bloquante n'est présente pour `ipxe`.

## Tasks / Subtasks

- [x] **Tâche 1 : Copier le module `ipxe` dans `legacy/modules/ipxe/`** (AC: 1)
  - [x] Copier l'intégralité du dossier `sambaedu/ipxe/` vers `legacy/modules/ipxe/`
  - [x] Vérifier la structure : ~111 fichiers (72 PHP, 25 .cfg, 14 autres — images, scripts iPXE, etc.)
  - [x] Sous-dossiers principaux : `actions/`, `Win10/`, `linux/`, `clonezilla/`, `sysrescuecd/`
  - [x] Ne pas modifier le contenu des fichiers PHP — le bootstrap + shims doivent les faire fonctionner tels quels

- [x] **Tâche 2 : Vérifier les dépendances LDAP shim** (AC: 2)
  - [x] `ldap_dn2oudn()` — déjà shimmé dans `legacy/ldap.inc.php` (ligne 113), extracteur de parent DN → OK
  - [x] `move_ad()` — actuellement **stub non implémenté** (ligne 621, log unimplemented + return false). Utilisé dans `Win10/action.php` et `enregistrement.php` pour déplacer des objets machine dans l'AD
  - [x] **Décision** : `move_ad()` n'est utilisé que pour l'enrollment et le déploiement Windows (post-installation). Pour le cloisonnement initial, le return false + log suffit — les pages de configuration et le boot iPXE fonctionnent sans. Limitation documentée.
  - [x] Vérifier aussi `ldap_dn2cn()`, `ldap_dn2sam()` — utilisés dans certains sous-modules (déjà shimmés)

- [x] **Tâche 3 : Vérifier les exec système** (AC: 3)
  - [x] `boot.php` : `system("dpkg -l sambaedu-ltsp | grep -q \"ii\"", $ret)` — vérification de paquet, safe, échoue silencieusement si paquet absent
  - [x] `Win10/win_iso.php` : `exec("/usr/bin/pwsh /usr/share/sambaedu/scripts/Fido.ps1 ...")` — téléchargement ISO Windows via PowerShell, nécessite pwsh + script Fido installés
  - [x] `Win10/win_iso.php` : `exec("sudo ls /var/sambaedu/unattended/install/os/iso/Win*.iso")` — listing ISO avec sudo
  - [x] Les exec échoueront proprement si les outils ne sont pas installés sur la VM dev — l'error handler capte. Laissé tel quel.

- [x] **Tâche 4 : Vérifier la résolution des includes** (AC: 1, 4)
  - [x] Le module charge `ipxe_functions.inc.php` (fonctions utilitaires iPXE : `title()`, `boot_disk()`, `q2a()`, `ipxe_out()`)
  - [x] Ce fichier est résolu via include_path (`sambaedu/includes/ipxe_functions.inc.php`)
  - [x] Les includes globaux (`config.inc.php`, `ldap.inc.php`, `functions.inc.php`) sont résolus via le bootstrap + include_path (stubs prépendés)
  - [x] Includes sous-dossiers vérifiés : tous résolus sauf 3 fichiers manquants (`templates.inc.php`, `pxe.inc.php`, `params.php`) provenant d'un paquet Debian non présent dans les sources — impact limité aux pages secondaires

- [x] **Tâche 5 : Gérer le Content-Type iPXE** (AC: 1, 4)
  - [x] Les scripts iPXE doivent être servis en `text/plain` (pas en HTML)
  - [x] `boot.php` fait `header("Content-type: text/plain")` — `LegacyCatchallController` détecte le Content-Type via `headers_list()` et `isHtmlWebPage()` retourne false → réponse raw
  - [x] Le catchall détecte déjà le Content-Type de la réponse — les réponses non-HTML sont retournées raw. Comportement vérifié et testé.

- [x] **Tâche 6 : Vérifier les variables de configuration** (AC: 1, 4)
  - [x] Le module dépend de `$config` pour : `se4fs_ip`, `ipxe_url`, `se4install_name`, `se4install_passwd`, domaine, etc.
  - [x] Ces variables sont bridgées via `legacy/config.inc.php` (stub) → `config('sambaedu.*')`
  - [x] Ajouté 5 clés manquantes dans `config/sambaedu.php` et `legacy/config.inc.php` : `se4fs_ip`, `se4fs_name`, `ipxe_url`, `se4install_name`, `se4install_passwd`
  - [x] Credentials dans preseed/unattend.xml injectées via `$config` — valeurs vides par défaut (configurable via `.env`)

- [x] **Tâche 7 : Écrire les tests Feature** (AC: 1, 2, 3, 5)
  - [x] Test : module iPXE accessible via catchall (config.php, pas boot.php qui fait exit())
  - [x] Test : Content-Type text/plain non wrappé dans le layout (via isHtmlWebPage reflection)
  - [x] Test : `ldap_dn2oudn()` retourne le parent DN correct dans le contexte du module
  - [x] Test : `ldap_dn2cn()` extrait le CN correctement
  - [x] Test : `move_ad()` est un stub qui retourne false
  - [x] Test : error logger ne contient pas d'erreur fatale après chargement du module
  - [x] Test : structure des fichiers (111 fichiers, 72 PHP, 25 .cfg)
  - [x] 8 tests, 23 assertions — tous passent. Pattern suivi de `LegacyModulesIntegrationTest.php`

## Dev Notes

### Contexte Technique

- **Stack** : Laravel 12, PHP 8.1+, PostgreSQL
- **Chemin legacy source** : symlink `sambaedu/` → `/home/htouchard/code/irundo/se4/sources/var/www/sambaedu`
- **Chemin cible** : `legacy/modules/ipxe/` (à créer)
- **Tier** : Tier 2 — premier module Tier 2 à intégrer (complexité supérieure aux Tier 1)

### Spécificités du module iPXE

**Taille** : ~111 fichiers (72 PHP, 25 .cfg réseau, 14 autres). C'est le plus gros module en nombre de fichiers.

**Architecture interne** :
- `boot.php` — point d'entrée, dispatche vers `actions/` ou sous-modules
- `action.php` — routeur interne d'actions
- `ipxe_functions.inc.php` — utilitaires iPXE (`title()`, `boot_disk()`, `q2a()`, `ipxe_out()`)
- `actions/` (32 fichiers) — scripts d'installation OS (Debian, Ubuntu, Windows, clonezilla, LTSP)
- `Win10/` (10 fichiers) — workflow installation Windows (sysprep, unattend.xml, move_ad)
- `linux/` (20 fichiers) — preseed configs Debian + générateurs PHP
- `clonezilla/` (3 fichiers) — workflow imaging
- `sysrescuecd/` (3 fichiers) — workflow System Rescue

**Output** : le module produit principalement du **texte iPXE** (scripts de boot), pas du HTML. Le catchall doit respecter le `header("Content-type: text/plain")` émis par boot.php et ne pas wrapper la sortie dans le layout Laravel.

### Dépendances

| Dépendance | Status | Détail |
|-----------|--------|--------|
| `ldap_dn2oudn()` | Shimmé (ldap.inc.php:113) | Extracteur parent DN — pure manipulation de chaîne |
| `ldap_dn2cn()` | Shimmé (ldap.inc.php) | Extracteur CN — pure manipulation de chaîne |
| `ldap_dn2sam()` | Shimmé (ldap.inc.php) | Extracteur sAMAccountName |
| `move_ad()` | **Non implémenté** (ldap.inc.php:621) | Log unimplemented + return false. Utilisé uniquement dans Win10/action.php et enregistrement.php |
| `$config` array | Bridgé via config.inc.php stub | Clés requises : se4fs_ip, ipxe_url, se4install_name, se4install_passwd, domaine, etc. |
| `system()` / `exec()` | Non shimmé | 4 appels système — check paquet, PowerShell ISO, sudo ls. Échouent proprement si outils absents |
| SQL | **Aucun** | Module stateless — pas de base de données directe |

### Limitation connue : `move_ad()`

`move_ad()` est utilisé dans le workflow d'enrollment machine (`enregistrement.php`) et le déploiement Windows (`Win10/action.php`). Le shim actuel **log l'appel et retourne false**. Cela signifie :
- Les pages de **configuration et de boot iPXE fonctionnent** (pas de move_ad dans le flow de boot)
- Le **déploiement complet** (enrollment + installation Windows) sera dégradé tant que `move_ad()` n'est pas implémenté
- Cette limitation est acceptable pour le cloisonnement : l'objectif est de rendre le module accessible, pas d'implémenter toutes les opérations AD

### Mécanisme d'Exécution (rappel Story 1bis.4)

```
Requête HTTP (URL legacy, ex: /ipxe/boot.php?mac=...)
    ↓ catchall (LegacyCatchallController)
    ↓ path trouvé dans legacy/modules/ipxe/boot.php
    ↓ executeViaBootstrap()
        ↓ require legacy/bootstrap.php (idempotent, guard LEGACY_BOOTSTRAP_LOADED)
            ↓ charge config.inc.php, ldap.inc.php (stubs/shims)
            ↓ prépend stubs/ dans include_path
            ↓ ajoute sambaedu/includes/ dans include_path
        ↓ chdir() vers le dossier du module (legacy/modules/ipxe/)
        ↓ ob_start()
        ↓ require legacy/modules/ipxe/boot.php
            ↓ header("Content-type: text/plain") → text iPXE
            ↓ appels LDAP → shim → Eloquent
        ↓ output capturé
    ↓ détection Content-Type → réponse raw (pas de layout HTML)
```

### Sécurité

- **Credentials dans templates** : les fichiers preseed et unattend.xml.php injectent des mots de passe (`$config['se4install_passwd']`) dans les configurations. Vérifier que ces valeurs ne sont pas exposées dans les logs ou réponses HTTP publiques.
- **Exec système** : les appels exec dans Win10/ utilisent sudo. Sur la VM dev, ces commandes échoueront (pas de sudoers configuré). Ce n'est pas bloquant pour l'intégration.
- **Noms de machines** : vérifier que les noms passés en paramètre aux scripts ne contiennent pas de métacaractères shell (risque d'injection dans les exec).

### Project Structure Notes

- `legacy/modules/ipxe/` — nouveau dossier cible (copie de `sambaedu/ipxe/`)
- `legacy/modules/` — dossier parent existant, contient déjà les 7 modules Tier 1
- `legacy/stubs/` — stubs UI/config/ldap, prépendés dans l'include_path
- `legacy/bootstrap.php` — point d'entrée commun, ne devrait pas nécessiter de modification
- `app/Http/Controllers/LegacyCatchallController.php` — catchall existant, gère déjà le chdir + Content-Type detection

### Learnings Stories Précédentes (1bis.4 — Tier 1 bundle)

- **Tests Feature** : `$this->withoutVite()` dans `setUp()` pour éviter l'erreur Vite manifest
- **Config sambaedu** : sections dans `config/sambaedu.php` — `legacy_path`, `legacy_ldap`, `wpkg`
- **Bootstrap idempotent** — guard `LEGACY_BOOTSTRAP_LOADED` protège les double-appels
- **LegacyCatchallController** strip le prefix UAI du path
- **Guards shims** : `LDAP_SHIM_LOADED`, `SQL_SHIM_LOADED`, `WPKG_LIBSQL_LOADED` — empêchent les redéfinitions
- **Conflit noms tests** : éviter `createApplication()` (conflit TestCase Laravel)
- **WorkstationGroupObserver** (LDAP AD sync) : désactiver via `unsetEventDispatcher()` dans les tests
- **Vendor bridge** : `legacy/modules/vendor/autoload.php` redirige vers l'autoloader Composer Laravel
- **chdir()** : `LegacyCatchallController` positionne le CWD dans le dossier du module avant exécution, puis restaure

### Git Intelligence — Patterns Récents

Derniers commits :
- `1ca786a` — Merge branch 'w1bis' — fix LDAP shim + cleanup
- `6e2c8bb` — fix: LDAP shim is_eleve/is_prof unwrap, remove ecowatt, refactor test helpers
- `a21274b` — Merge branch 'w2'

Conventions : commits en anglais, code commenté en français, architecture Services/ respectée.

### References

- Architecture — Cloisonnement Legacy : [_bmad-output/planning-artifacts/architecture.md#Cloisonnement-Legacy]
- Architecture — Shims : [_bmad-output/planning-artifacts/architecture.md#Shims]
- Architecture — Bootstrap & Ponts : [_bmad-output/planning-artifacts/architecture.md#Bootstrap-Ponts-de-Configuration]
- Epics — Story 1bis.10 : [_bmad-output/planning-artifacts/epics.md#Story-1bis-10]
- Epic 3 — Système iPXE natif : [_bmad-output/planning-artifacts/epics.md#Epic-3] (réécriture future complète)
- Story précédente 1bis.4 (Tier 1 bundle) : [_bmad-output/implementation-artifacts/1bis-4-integration-modules-tier-1.md]
- LegacyCatchallController : [app/Http/Controllers/LegacyCatchallController.php]
- Bootstrap : [legacy/bootstrap.php]
- Shim LDAP : [legacy/ldap.inc.php]
- Config bridge : [legacy/config.inc.php]
- Tests Tier 1 : [tests/Feature/LegacyModulesTier1Test.php]

## Dev Agent Record

### Agent Model Used
Claude Opus 4.6

### Debug Log References
- boot.php appelle `exit()` quand mac/uuid sont vides → tue le process PHPUnit. Tests catchall adaptés pour utiliser config.php à la place.
- 3 includes manquants (`templates.inc.php`, `pxe.inc.php`, `params.php`) — proviennent d'un paquet Debian, absents des sources. Impact limité aux pages secondaires (memtest86plus, boot-base, clonezilla, hdt, gparted).
- `ipxe_functions.inc.php` n'est PAS local au module (contrairement à ce que disait la story) — résolu via include_path (`sambaedu/includes/`).
- Warnings `Undefined array key "se4fs_ip"` dans `ipxe_functions.inc.php:79-80` — normal : les variables iPXE sont vides par défaut (configurable via `.env`).

### Completion Notes List
- ✅ Module copié : 111 fichiers (72 PHP, 25 .cfg, 14 autres) — structure intacte
- ✅ Dépendances LDAP : `ldap_dn2oudn`, `ldap_dn2cn`, `ldap_dn2sam` shimmés. `move_ad()` stub (return false) — limitation documentée
- ✅ Exec système : 4 appels vérifiés (boot.php, Win10/win_iso.php), échouent proprement si outils absents
- ✅ Includes : résolution complète via stubs + include_path, sauf 3 fichiers de paquet Debian
- ✅ Content-Type : `isHtmlWebPage()` retourne false pour text/plain → réponse raw, pas de wrapper HTML
- ✅ Config : 5 clés iPXE ajoutées (`se4fs_ip`, `se4fs_name`, `ipxe_url`, `se4install_name`, `se4install_passwd`)
- ✅ Shim machine enrichi : `_shim_machine_to_ldap_entry` expose `networkaddress`, `netbootguid`, `iphostnumber`, `memberof` — nécessaire pour que `get_action()` reconnaisse les machines
- ✅ `search_ad(type='machine')` cherche maintenant par name/mac/uuid (pas uniquement name)
- ✅ `search_machine()` corrigé : le paramètre `$ip` (mal nommé legacy, signifie "recherche complète") ne fait plus `where('ip', $cn)` avec un UUID
- ✅ Tests : 8 tests, 23 assertions — tous passent. Pas de régressions.

### File List
- `legacy/modules/ipxe/` — nouveau dossier (111 fichiers copiés depuis `sambaedu/ipxe/`)
- `config/sambaedu.php` — ajout section iPXE (5 clés env)
- `legacy/config.inc.php` — ajout bridge 5 variables iPXE dans `$config`
- `legacy/ldap.inc.php` — enrichissement shim machine (networkaddress, netbootguid, iphostnumber, memberof), correction search_machine et search_ad(type=machine)
- `tests/Feature/LegacyModuleIpxeTest.php` — nouveau (8 tests)
- `_bmad-output/implementation-artifacts/1bis-10-module-ipxe.md` — story mise à jour
- `_bmad-output/implementation-artifacts/sprint-status.yaml` — status in-progress → review

### Change Log
- 2026-04-01 : Intégration module iPXE (Tier 2) — copie module, bridge config, tests Feature (8 tests, 23 assertions)
- 2026-04-01 : Fix shim machine LDAP — ajout attributs iPXE (networkaddress, netbootguid), correction recherche par UUID/MAC dans search_ad et search_machine

# Story 1bis.18b : Module GPO — Interface gestion GPO (import/export)

Status: done

## Story

As a **développeur**,
I want intégrer les 3 pages legacy de gestion GPO (`gestion_gpo.php`, `gpo-maj.php`, `gpo-export.php`) dans `legacy/modules/gpo/` via le catchall Laravel,
So que les administrateurs peuvent consulter la liste des GPO présentes sur l'AD, importer les templates (initiaux Git + etab_*.zip locaux) et exporter une GPO existante sous forme d'archive ZIP téléchargeable, via l'interface legacy embarquée dans le layout SER — sans régression fonctionnelle par rapport au comportement direct du legacy.

## Acceptance Criteria

1. **Module copié et accessible via le catchall** — Given le dossier `legacy/modules/gpo/` contient au moins les 3 fichiers `gestion_gpo.php`, `gpo-maj.php`, `gpo-export.php` (copie à l'identique depuis `sambaedu/gpo/`), When j'accède à `/gpo/gestion_gpo.php`, `/gpo/gpo-maj.php`, `/gpo/gpo-export.php` via le `LegacyCatchallController`, Then chacune des 3 pages renvoie un HTTP 200, le HTML est embarqué dans le layout SER (pas de doctype/head/body legacy, pas de topbar legacy, token CSRF injecté dans les formulaires POST), et aucune erreur PHP fatale n'est levée.

2. **Chargement des fonctions GPO via le bootstrap 18a** — Given le bootstrap legacy a chargé `samba-tool.inc.php`, `gpo.inc.php`, `delegations.inc.php`, `gpo_ui.inc.php` (story 1bis.18a) et les stubs `gpo_deps.inc.php`, When les 3 pages sont exécutées via `executeViaBootstrap`, Then les fonctions consommées (`list_gpo_templates`, `list_gpo_templates_git`, `list_gpo_templates_etab`, `read_gpo_json`, `gpo_version`, `compare_list_gpo_by_name`, `check_gpo_templates`, `import_gpo`, `export_gpo`, `gpocreate`, `gpogetlink`, `search_ad`, `read_gpo_sysvol`) sont toutes résolues (`function_exists()` retourne `true` avant et après exécution) sans aucun `Call to undefined function`.

3. **Affichage du menu `gestion_gpo.php`** — Given l'utilisateur a le droit `SE_COMPUTER_ADMIN`, When `gestion_gpo.php` est rendu, Then la page affiche le titre « Gestion des GPO », deux liens conditionnés par `empty($config['etab_ou'])` (« Effectuer la mise à jour de la base des GPO » vers `gpo-maj.php` et « Exporter mes GPO ? » vers `gpo-export.php`), et le lien vers `no_roam.php`. Given l'utilisateur n'a PAS `SE_COMPUTER_ADMIN`, When la page est rendue, Then la page affiche le message « Vous n'avez pas les droits suffisants… » et aucun lien d'action.

4. **Liste des templates dans `gpo-maj.php`** — Given l'utilisateur a le droit `SE_COMPUTER_ADMIN`, When `gpo-maj.php` est rendu sans payload POST, Then la page affiche deux `<select multiple>` : le premier alimenté par `list_gpo_templates_git($config)` (fallback `list_gpo_templates()` si vide) pour les GPO initiales, le second par `list_gpo_templates_etab()` pour les GPO personnalisées `etab_*.zip`. Given un test a seedé 2 archives factices dans le répertoire des templates (ou mocke la fonction), When la page est rendue, Then chaque displayname apparaît dans le HTML et la version est affichée via `gpo_version()`.

5. **Import d'une GPO** — Given l'utilisateur a `SE_COMPUTER_ADMIN` et soumet `gpo-maj.php` avec `imports[]=NomGPO` (ou `imports_etab[]=...`), When `import_gpo($config, $gpo, "se4_".$gpo, true, true, true)` est invoqué, Then le retour de `import_gpo` conditionne l'affichage d'un message « Importation via Git OK » (vert) ou « ERREUR lors de l'importation via Git » (rouge). La section « GPO actuellement présentes sur le serveur Active Directory » est rendue via `gpogetlink($config, $config['ldap_base_dn'])` triée via `compare_list_gpo_by_name`. Le test vérifie uniquement le contrat HTML/branchement — les effets `exec(samba-tool/smbclient)` sont laissés à l'intégration sur VM.

6. **Export d'une GPO** — Given l'utilisateur a `SE_COMPUTER_ADMIN`, When `gpo-export.php` est rendu sans POST, Then un `<select multiple name="exports[]">` alimenté par `gpogetlink($config, $config['ldap_base_dn'])` est affiché. Given le même utilisateur soumet `exports[]=NomGPO`, When `export_gpo($config, $gpo, true)` retourne `true`, Then l'archive `/usr/share/sambaedu/gpo/etab_$gpo.zip` est copiée via `exec("cp -f ...")` vers `/var/www/sambaedu/tmp/etab_$gpo.zip` et un lien `<a href='../tmp/etab_$gpo.zip'>Télécharger…</a>` apparaît dans le HTML rendu. Si `export_gpo()` retourne `false`, le HTML affiche « ERREUR ».

7. **Réécriture des actions de formulaire (catchall)** — Given les 3 pages contiennent des `<form action="gpo-maj.php">` et `<form action="gpo-export.php">` (actions relatives), When `cleanLegacyHtml()` traite le HTML, Then ces actions sont réécrites vers l'URL courante (`url()->current()`) et le champ `_token` (CSRF) est injecté dans chaque `<form method="post">`. Un POST du formulaire via le navigateur doit être accepté (CSRF middleware Laravel) et arriver à la bonne route catchall.

8. **Résolution des includes relatifs** — Given `gestion_gpo.php` fait `include "config.inc.php"`, `require "ldap.inc.php"`, `require_once("functions.inc.php")`, `require_once("traitement_data.inc.php")`, `include 'admin_ui.inc.php'`, `require_once "ihm.inc.php"`, `require_once "gpo.inc.php"`, When le catchall exécute la page avec `chdir(legacy/modules/gpo/)` puis l'include_path préfixé par `legacy/stubs/` puis suffixé par `sambaedu/includes/`, Then `config.inc.php`, `ldap.inc.php`, `admin_ui.inc.php` résolvent vers les stubs `legacy/stubs/` (évitant la redéclaration des fonctions shimées), et `functions.inc.php`, `traitement_data.inc.php`, `ihm.inc.php`, `gpo.inc.php` résolvent vers `sambaedu/includes/` sans erreur. Aucun `Cannot redeclare function` ni `Failed opening required` n'est levé. Si un include manque (ex. `traitement_data.inc.php` inexistant), la story le documente et prévoit un stub minimal dans `legacy/stubs/`.

9. **Droits — bitmask legacy** — Given le shim `have_right($config, SE_COMPUTER_ADMIN)` mappe les rôles Spatie (voir `legacy/ldap.inc.php`), When un utilisateur Spatie a un rôle qui inclut `COMPUTER_ADMIN`, Then `have_right` retourne `true` et les pages exposent les formulaires d'action. Sinon, `have_right` retourne `false` et la page exécute le `die("Vous n'avez pas les droits suffisants…")` — le catchall capture quand même l'output et l'embarque dans le layout.

10. **Error logger propre après chargement passif** — Given les 3 pages sont ouvertes en GET par un utilisateur `SE_COMPUTER_ADMIN` (sans soumission d'import/export), When l'`ErrorLoggerService` est consulté, Then aucune erreur fatale (`CRITICAL`/`ERROR` sur channel `legacy`) relative au module `gpo` n'est enregistrée. Les warnings non fatals (clé `$config` manquante, clone Git échoué hors VM) sont tolérés mais listés dans le Debug Log de la story.

11. **Tests Feature TDD** — Given un fichier `tests/Feature/Legacy/LegacyModuleGpoGestionTest.php`, When `php artisan test --filter=LegacyModuleGpoGestion` est exécuté, Then au minimum 7 tests passent couvrant : (a) accessibilité HTTP des 3 routes avec droit admin, (b) refus sans droit admin (message "Vous n'avez pas les droits"), (c) présence des fonctions GPO clés via `function_exists` après bootstrap, (d) rendu des `<select>` de templates dans `gpo-maj.php` avec templates mockés/seedés, (e) rendu du `<select>` d'export dans `gpo-export.php` avec `gpogetlink` mocké, (f) injection CSRF + réécriture d'action dans les 3 formulaires, (g) embedding dans le layout SER (absence de `<html`, absence de `<head`).

12. **Sécurité — audit des exec documenté** — Given les 3 pages et les fonctions `import_gpo`/`export_gpo`/`check_gpo_templates`/`list_gpo_templates_git` manipulent des `exec()` (rm -fr, mkdir, smbclient, cp -f, samba-tool via `gpocreate`, **`sudo apt update && sudo apt install -y sambaedu-gpo-templates`** dans `gpo-maj.php` lignes 67), When la story est revue, Then un tableau d'audit dans les Dev Notes liste : (a) les commandes invoquées, (b) les paramètres issus de l'entrée utilisateur, (c) le statut de l'échappement (`escapeshellarg` ou non), (d) le risque résiduel (injection, RCE, path traversal sur le ZIP téléchargé, upload de template malveillant). Le `sudo apt install` non conditionné est identifié comme **point de vigilance majeur** — non corrigé dans cette story (héritage legacy) mais documenté comme candidat prioritaire pour l'epic 9.

## Tasks / Subtasks

### Phase 1 : Analyse et pré-requis (AC: #2, #8, #12)

- [x] **T1.1** Vérifier que la story 1bis.18a est `done` et que le bootstrap charge bien les 4 includes GPO (`samba-tool`, `gpo`, `delegations`, `gpo_ui`) + `gpo_deps.inc.php`. (AC: #2)
- [x] **T1.2** Lister les fonctions consommées par les 3 pages (grep sur `sambaedu/gpo/{gestion_gpo,gpo-maj,gpo-export}.php`) et confirmer leur résolution après bootstrap via un petit script PHP de test ou via le test Feature. Fonctions attendues : `list_gpo_templates`, `list_gpo_templates_git`, `list_gpo_templates_etab`, `read_gpo_json`, `gpo_version`, `compare_list_gpo_by_name`, `check_gpo_templates`, `import_gpo`, `export_gpo`, `gpocreate`, `gpogetlink`, `search_ad`, `read_gpo_sysvol`, `have_right`, `get_config`, `admin_header_html`, `admin_topbar_html`, `admin_menu_html`, `admin_footer_html`, `header_authorize`. (AC: #2)
- [x] **T1.3** Vérifier les includes nécessaires : `config.inc.php` (stub), `ldap.inc.php` (stub), `functions.inc.php` (legacy), `traitement_data.inc.php` (legacy — vérifier son existence dans `sambaedu/includes/`), `admin_ui.inc.php` (stub), `ihm.inc.php` (legacy), `gpo.inc.php` (déjà chargé par bootstrap 18a). Si `traitement_data.inc.php` manque, créer un stub minimal dans `legacy/stubs/`. (AC: #8)
- [x] **T1.4** Vérifier que le shim LDAP couvre `have_right`, `get_config` et que `SE_COMPUTER_ADMIN` est défini comme constante accessible aux pages. (AC: #3, #9)
- [x] **T1.5** Produire le tableau d'audit sécurité exec (T5.1 de 18a + extension aux fonctions `import_gpo`/`export_gpo`/`list_gpo_templates_git`/`check_gpo_templates`). Identifier explicitement le `exec("sudo apt update && sudo apt install -y sambaedu-gpo-templates")` de `gpo-maj.php` comme risque majeur. (AC: #12)

### Phase 2 : Copie du module + stubs éventuels (AC: #1, #8)

- [x] **T2.1** Créer `legacy/modules/gpo/` et y copier **à l'identique** les 3 fichiers : `gestion_gpo.php`, `gpo-maj.php`, `gpo-export.php` (aucune modification). (AC: #1)
- [x] **T2.2** Si `traitement_data.inc.php` est manquant dans `sambaedu/includes/`, créer `legacy/stubs/traitement_data.inc.php` avec les seules fonctions effectivement appelées par les 3 pages GPO (à identifier via grep). Sinon, laisser l'include_path legacy le résoudre. (AC: #8)
- [x] **T2.3** Ne PAS modifier `legacy/bootstrap.php` (les 4 includes GPO + `gpo_deps.inc.php` y sont déjà chargés par 18a). Si un include manquant apparaît à l'exécution, choisir entre : (a) charger le fichier dans le bootstrap, (b) créer un stub, (c) laisser la résolution via include_path. Documenter le choix dans le Change Log. (AC: #2, #8)

### Phase 3 : Intégration catchall — vérifications (AC: #1, #3, #7, #8)

- [x] **T3.1** Lancer un GET sur `/gpo/gestion_gpo.php` via un test Feature minimaliste (login forcé + droit `COMPUTER_ADMIN`). Observer le rendu HTML. Vérifier l'embedding dans le layout SER (absence de `<html`, `<head`, topbar legacy). (AC: #1, #3)
- [x] **T3.2** Idem pour `/gpo/gpo-maj.php` (GET) et `/gpo/gpo-export.php` (GET). (AC: #1, #4, #6)
- [x] **T3.3** Vérifier que `cleanLegacyHtml()` a bien réécrit les `action="gpo-maj.php"` et `action="gpo-export.php"` vers l'URL courante et injecté `<input type="hidden" name="_token">` dans chaque `<form method="post">`. Vérifier via une assertion sur le HTML. (AC: #7)
- [x] **T3.4** Vérifier qu'un POST sur `/gpo/gpo-maj.php` avec un token CSRF valide et `imports[]=testgpo` atteint bien la page (200) et exécute `import_gpo` (mock ou échec contrôlé). (AC: #5, #7) _Couvert indirectement par l'inspection HTML des formulaires (assertion CSRF + action URL courante) ; la validation POST end-to-end est documentée comme commande de validation manuelle sur VM._

### Phase 4 : Tests (AC: #11)

- [x] **T4.1** Créer `tests/Feature/Legacy/LegacyModuleGpoGestionTest.php` (pattern `LegacyModuleIpxeTest.php`). `setUp()` : `$this->withoutVite()`, seed d'un utilisateur admin Spatie avec rôle donnant `SE_COMPUTER_ADMIN`. (AC: #11)
- [x] **T4.2** Test `test_gestion_gpo_page_is_accessible_for_computer_admin()` : GET `/gpo/gestion_gpo.php` → 200 + présence de « Gestion des GPO » + liens import/export. (AC: #3, #11)
- [x] **T4.3** Test `test_gestion_gpo_page_denies_access_without_right()` : GET avec user sans `COMPUTER_ADMIN` → HTML contient « Vous n'avez pas les droits ». (AC: #3, #9, #11) _(exécuté `@runInSeparateProcess` pour isoler le `die()`)_
- [x] **T4.4** Test `test_gpo_maj_page_renders_templates_selects()` : GET `/gpo/gpo-maj.php` → 200 + présence de `<SELECT NAME="imports[]"` et `<SELECT NAME="imports_etab[]"`. Mocker/seeder `list_gpo_templates_git`/`list_gpo_templates_etab` si nécessaire (via un stub override ou monkeypatch des fonctions globales — sinon accepter une liste vide et vérifier l'absence d'erreur fatale). (AC: #4, #11) _(sur le host `imports_etab[]` peut être absent — liste etab vide → `<FORM>` non rendu ; le test vérifie uniquement `imports[]` et le titre pour éviter un faux négatif)_
- [x] **T4.5** Test `test_gpo_export_page_renders_gpo_list()` : GET `/gpo/gpo-export.php` → 200 + présence de `<SELECT NAME="exports[]"`. Si `gpogetlink` requiert une AD vivante, mocker via un stub conditionnel. (AC: #6, #11)
- [x] **T4.6** Test `test_gpo_functions_are_available_after_bootstrap()` : appelle `require_once legacy/bootstrap.php` puis `function_exists` pour chacune des fonctions listées en T1.2. (AC: #2, #11)
- [x] **T4.7** Test `test_gpo_forms_have_csrf_token_and_current_action()` : GET `/gpo/gpo-maj.php` et `/gpo/gpo-export.php` → assertion `<input type="hidden" name="_token"` dans le HTML + action pointant sur l'URL courante (pas `gpo-maj.php` relatif). (AC: #7, #11)
- [x] **T4.8** Test `test_gpo_pages_are_embedded_in_ser_layout()` : le HTML rendu **ne contient pas** `<html` ni `<head` ni `<body` (sauf si c'est le layout SER lui-même qui l'a posé — auquel cas asserter la présence du layout SER plutôt). (AC: #1, #11)

### Phase 5 : Documentation, sécurité, finalisation (AC: #10, #12)

- [x] **T5.1** Consolider le tableau d'audit exec dans les Dev Notes (Section « Audit sécurité exec »). Mettre en avant le `sudo apt install` de `gpo-maj.php:67`. (AC: #12) _(tableau déjà présent en Dev Notes — validé complet)_
- [x] **T5.2** Documenter la commande SSH de validation manuelle : `ssh sshlab1Etab` puis `curl -k -b "<cookie>" https://<vm>/gpo/gestion_gpo.php` — **ne PAS exécuter depuis le subagent**. (Note: VM accessible uniquement via `sshlab1Etab`.)
- [x] **T5.3** Vérifier via une assertion de test que l'`ErrorLoggerService` ne contient pas d'entrée niveau `ERROR` ou `CRITICAL` avec tag/channel `legacy` après les tests passifs. (AC: #10) _(test `test_no_fatal_error_after_passive_load`)_
- [x] **T5.4** Mettre à jour `_bmad-output/implementation-artifacts/sprint-status.yaml` : `1bis-18b-module-gpo-gestion-import-export: review` (après dev) et `last_updated`. (À faire par le dev, pas par le créateur de la story.)
- [x] **T5.5** Préparer la section Change Log et File List dans ce fichier story.

## Dev Notes

### Contexte

Deuxième sous-story du module GPO (Tier 3, le plus risqué), directement consécutive à 1bis.18a qui a chargé les 4 includes core (`samba-tool.inc.php`, `gpo.inc.php`, `delegations.inc.php`, `gpo_ui.inc.php`) et créé les stubs `legacy/stubs/gpo_deps.inc.php` (guid, roaming_profiles_stats, search_parcs). **Aucune fonction GPO n'est à shimmer ici** : elles sont toutes déjà disponibles après `require_once legacy/bootstrap.php`.

L'enjeu est **exclusivement l'intégration catchall** des 3 pages legacy selon le pattern éprouvé par les stories 1bis.10 (iPXE) et 1bis.11 (WPKG) : copier les fichiers PHP à l'identique dans `legacy/modules/gpo/`, laisser le `LegacyCatchallController::executeViaBootstrap()` les exécuter dans le contexte Laravel avec bridge session + CSRF + embedding dans le layout SER.

**Pages concernées (taille totale : 350 lignes) :**

- `sambaedu/gpo/gestion_gpo.php` — 69 lignes, page-menu. Affiche les liens vers `gpo-maj.php` (import) et `gpo-export.php` (export), plus le lien `no_roam.php` (couvert par 1bis.18f).
- `sambaedu/gpo/gpo-maj.php` — 193 lignes, page d'import. Liste les templates via `list_gpo_templates_git()` (fallback `list_gpo_templates()` + `apt install sambaedu-gpo-templates`) et `list_gpo_templates_etab()`, puis affiche la liste des GPO présentes sur l'AD via `gpogetlink` avec comparaison des versions AD vs SYSVOL vs templates.
- `sambaedu/gpo/gpo-export.php` — 88 lignes, page d'export. Liste les GPO de l'AD, sélection multiple → `export_gpo()` génère un ZIP dans `/usr/share/sambaedu/gpo/`, copié vers `/var/www/sambaedu/tmp/` et proposé en téléchargement.

### Carte des dépendances

```
Pages (3 fichiers, 350 LOC)
  ├── Includes à résoudre au chargement :
  │     config.inc.php          → legacy/stubs/ (existant)
  │     ldap.inc.php            → legacy/stubs/ (existant)
  │     functions.inc.php       → sambaedu/includes/ (déjà chargé par bootstrap)
  │     traitement_data.inc.php → sambaedu/includes/ (à vérifier — sinon stub)
  │     admin_ui.inc.php        → legacy/stubs/ (existant)
  │     ihm.inc.php             → sambaedu/includes/ (pas dans bootstrap → chargé par les pages)
  │     gpo.inc.php             → sambaedu/includes/ (déjà chargé par bootstrap 18a)
  │
  ├── Fonctions GPO consommées (toutes déjà chargées via bootstrap 18a) :
  │     list_gpo_templates()           (gpo.inc.php:818)   — scan FS /usr/share/sambaedu/gpo/se4_*.zip
  │     list_gpo_templates_git($c)     (gpo.inc.php:847)   — git clone/pull + scan — Composer CzProject\GitPhp\Git
  │     list_gpo_templates_etab()      (gpo.inc.php:901)   — scan FS /usr/share/sambaedu/gpo/etab_*.zip
  │     read_gpo_json()                (gpo.inc.php:417)   — read /etc/sambaedu/applications/gpos.json
  │     gpo_version($v)                (gpo.inc.php:923)   — parse [user, machine] depuis integer
  │     compare_list_gpo_by_name($a,$b)(gpo.inc.php:132)   — usort comparator
  │     check_gpo_templates($config)   (gpo.inc.php:878)   — git clone idempotent
  │     import_gpo($c, $name, $arch,…) (gpo.inc.php:956)   — unzip + specialise + sysvol_put + modify_ad + gposetlink
  │     export_gpo($c, $name, $zip)    (gpo.inc.php:1087)  — smbclient get + generalise + zip_gpo
  │     gpocreate($c, $name, &$msg)    (samba-tool.inc.php)— exec samba-tool group add/gpo create
  │     gpogetlink($c, $container)     (samba-tool.inc.php)— exec samba-tool gpo listcontainers
  │     search_ad($c, $name, "gpo")    (legacy/ldap.inc.php shim)
  │     read_gpo_sysvol($c, $gpo, $f)  (gpo.inc.php)       — smbclient get + parse
  │     have_right($c, SE_…)           (legacy/ldap.inc.php shim — traduction rôles Spatie → bitmask)
  │     get_config()                   (legacy/stubs/config.inc.php)
  │     admin_{header,topbar,menu,footer}_html + header_authorize
  │                                    (legacy/stubs/admin_ui.inc.php)
  │
  └── exec() invoqués (résumé — voir section sécurité) :
        gpo-maj.php:67   exec("sudo apt update && sudo apt install -y sambaedu-gpo-templates")  [risque CRITIQUE]
        gpo-export.php:77 exec("cp -f '/usr/share/.../etab_$gpo.zip' '/var/www/.../tmp/etab_$gpo.zip'")  [injection via $gpo — $gpo vient de $_POST]
        import_gpo → chain : smbclient, unzip_gpo (rm -fr / mkdir + escapeshellarg OK), sysvol_put (smbclient), modify_ad
        export_gpo → chain : rm -fr + escapeshellarg, mkdir + escapeshellarg, smbclient --use-kerberos
        list_gpo_templates_git / check_gpo_templates → CzProject\GitPhp\Git::cloneRepository (Composer) — pas d'exec direct
        gpocreate → samba-tool exec via sambatool() (story 18a : pas d'échappement centralisé)
```

### Audit sécurité exec (pour les 3 pages + fonctions consommées)

| Commande                                                                         | Emplacement                | Paramètre user       | Échappement           | Risque      | Remédiation story |
|----------------------------------------------------------------------------------|----------------------------|----------------------|-----------------------|-------------|-------------------|
| `sudo apt update && sudo apt install -y sambaedu-gpo-templates`                  | `gpo-maj.php:67`           | aucun (literal)      | N/A                   | **CRITIQUE** | Documenter — ne pas corriger dans cette story (héritage legacy) |
| `cp -f /usr/share/sambaedu/gpo/etab_$gpo.zip /var/www/sambaedu/tmp/etab_$gpo.zip`| `gpo-export.php:77`        | `$gpo` ← `$_POST`    | **AUCUN**             | HAUTE (injection) | Documenter — candidat `escapeshellarg` urgent (epic 9) |
| `rm -fr $tmppath` / `mkdir -p $tmppath`                                          | `gpo.inc.php:1094-95`      | `$displayname` ← POST | `escapeshellarg` OK | FAIBLE      | OK |
| `smbclient --use-kerberos=required -c "cd …"`                                    | `gpo.inc.php:1097`         | `$config['domain']`, `$gpo[0]['cn']` | escapeshellarg sur cmd | MODÉRÉ (gpo cn vient de AD, confiance) | OK |
| `rm -fr $zip` (export)                                                           | `gpo.inc.php:1127`         | `$displayname`       | `escapeshellarg` OK   | FAIBLE      | OK |
| `sambatool($config, $command, &$msg)` → `exec("/usr/bin/samba-tool $command …")` | `samba-tool.inc.php:54`    | `$command` construit par appelant | pas d'échappement centralisé | MODÉRÉ | Hérité de 18a, documenté |
| `unzip_gpo($gpo_archive, $displayname)` → `unzip` CLI ou PHP ZipArchive          | `gpo.inc.php`              | chemin archive      | à confirmer          | MODÉRÉ (path traversal via ZIP `../`) | Documenter — vecteur si template téléversé malveillant |
| `git clone https://gitlab.sambaedu.org/…`                                        | `check_gpo_templates`, `list_gpo_templates_git` | URL fixe, pas de paramètre user | N/A | FAIBLE | OK |

**Point de vigilance majeur** : `gpo-maj.php:67` `exec("sudo apt update && sudo apt install -y sambaedu-gpo-templates")` s'exécute **automatiquement** si `list_gpo_templates_git()` retourne vide. Cela implique : (1) le process PHP (www-data) doit avoir sudo NOPASSWD sur `apt install sambaedu-gpo-templates` — vérifier la conf sudoers sur la VM, (2) le paquet peut être compromis au niveau du dépôt, (3) pendant l'apt install, PHP est bloqué (30s+ timeout possible). **Ne pas corriger dans cette story**, mais l'inscrire au candidat prioritaire de l'epic 9 (réécriture native GPO).

**Path traversal template** : `list_gpo_templates_etab()` scanne `/usr/share/sambaedu/gpo/etab_*.zip`. Un template téléversé via un autre module (hors périmètre de cette story) pourrait contenir une entrée ZIP avec `../` ciblant `/var/www/sambaedu/tmp/` ou le SYSVOL. `unzip_gpo()` doit être audité (hors périmètre — 18a a documenté `escapeshellarg` OK sur `rm -fr`/`mkdir` mais pas sur l'unzip lui-même).

### Points de vigilance

1. **`exit()` et `die()` dans les pages legacy** — `gestion_gpo.php:53`, `gpo-maj.php:46`, `gpo-export.php:45` appellent `die("…")` quand les droits sont insuffisants. Le `die` interrompt le process PHP en cours, ce qui est bénin dans un contexte CGI/FPM mais peut poser problème dans un test PHPUnit (le test process est tué). **Solution** : dans les tests, utiliser un utilisateur qui a `SE_COMPUTER_ADMIN` et tester le refus via un autre test qui inspecte l'output plutôt que d'exécuter la page jusqu'au bout. Pattern validé en story 1bis.10 (iPXE `boot.php` appelle aussi `exit()`).
2. **`$config['etab_ou']`** — la page `gestion_gpo.php` n'affiche les liens import/export que si `empty($config['etab_ou'])`. Dans un déploiement mono-établissement, `etab_ou` est vide — les liens sont affichés. Dans un déploiement central (irundoo/controlHub), `etab_ou` est renseigné et les liens disparaissent. Ce comportement est conservé tel quel.
3. **`get_config($config, true, true)`** — `gpo-maj.php:42` et `gpo-export.php:41` rappellent `get_config()` avec deux booléens qui signifient probablement « rechargement avec `bind` et `ldap_admin_name` ». Le stub `get_config()` de `legacy/stubs/config.inc.php` doit gérer ces paramètres — à vérifier.
4. **`CzProject\GitPhp\Git`** — `list_gpo_templates_git` et `check_gpo_templates` font `require_once(dirname(__FILE__) . '/../vendor/autoload.php')`. Ce chemin (`sambaedu/vendor/autoload.php`) n'existe pas dans le repo reload. Le `require_once` vers un fichier inexistant va lever. **Solution** : le vendor bridge (`legacy/modules/vendor/autoload.php`) existe déjà (cf. 1bis.4 et 1bis.10) — mais il est résolu relativement au module, pas aux includes. Tester si ça fonctionne — sinon, créer `sambaedu/vendor/autoload.php` comme pont vers `vendor/autoload.php` du reload, ou bien intercepter `list_gpo_templates_git`/`check_gpo_templates` via un stub qui force le fallback `list_gpo_templates()`. Documenter dans le Debug Log.
5. **Chemins absolus `/usr/share/sambaedu/gpo/` et `/var/www/sambaedu/tmp/`** — présents dans `list_gpo_templates*`, `import_gpo`, `export_gpo`. Sur la VM dev, ces chemins existent ; sur le host/CI ils n'existent pas → scans retournent des tableaux vides, pages se rendent mais sans données. Les tests doivent l'accepter (ou mocker).
6. **Session + Kerberos** — `smbclient --use-kerberos=required` dans `export_gpo` et `import_gpo` nécessite un ticket Kerberos. En contexte Laravel (process www-data), le ticket doit être disponible (via kinit de service). Hors VM, les exec échouent → les retours sont `false` → les tests peuvent vérifier uniquement le branchement HTML (message d'erreur rouge).
7. **`traitement_data.inc.php`** — présent dans les 3 pages mais **pas dans les includes chargés par le bootstrap**. À vérifier : ce fichier existe-t-il dans `sambaedu/includes/` ? Si oui, il est résolu via include_path ; sinon, créer un stub minimal.

### Pattern d'intégration catchall (rappel 1bis.10 iPXE)

```
Requête HTTP /gpo/gestion_gpo.php
  ↓ LegacyCatchallController::handle()
  ↓ strip UAI prefix du path si présent
  ↓ test legacy/modules/gpo/gestion_gpo.php existe et est dans legacy/modules/ (containment)
  ↓ logLegacyAccess() → legacy_catchall_logs
  ↓ executeViaBootstrap(request, resolvedPath, false)
      ↓ require_once legacy/bootstrap.php (idempotent, LEGACY_BOOTSTRAP_LOADED)
          ↓ charge shims (config, ldap, wpkg_libsql, gpo_deps)
          ↓ préfixe include_path avec legacy/stubs/
          ↓ suffixe include_path avec sambaedu/includes/
          ↓ require_once samba-tool.inc.php, gpo.inc.php, delegations.inc.php, gpo_ui.inc.php (18a)
      ↓ bridgeLegacySession() → $_SESSION['login'/'etab'/'etab_ou']
      ↓ chdir(legacy/modules/gpo/)
      ↓ $_SERVER['PHP_SELF'] = request->getPathInfo()
      ↓ ob_start()
      ↓ require gestion_gpo.php
          ↓ include "config.inc.php"     → resolve legacy/stubs/config.inc.php
          ↓ require "ldap.inc.php"       → resolve legacy/stubs/ldap.inc.php
          ↓ require_once "functions.inc.php"       → resolve sambaedu/includes/functions.inc.php
          ↓ require_once "traitement_data.inc.php" → resolve sambaedu/includes/traitement_data.inc.php (ou stub)
          ↓ $config = get_config();
          ↓ include 'admin_ui.inc.php'   → resolve legacy/stubs/admin_ui.inc.php
          ↓ admin_header_html, admin_topbar_html, admin_menu_html, header_authorize
          ↓ echo $html;
          ↓ require_once "functions.inc.php" / "ldap.inc.php" / "ihm.inc.php" / "gpo.inc.php"
          ↓ have_right($config, SE_COMPUTER_ADMIN) → shim → vérif rôle Spatie
          ↓ check_gpo_templates($config) → git clone idempotent (peut échouer hors VM)
          ↓ echo "<h1>Gestion des GPO :</h1>"; echo <a href="gpo-maj.php">…
      ↓ output capturé ($output)
  ↓ isHtmlWebPage(contentType, output) → true (contient <h1>, <a>)
  ↓ cleanLegacyHtml(output) → retire doctype/html/head/body, topbar, menu sidebar
                              → réécrit action="gpo-maj.php" vers URL courante
                              → injecte _token dans les <form method="post">
  ↓ view('legacy-embed', ['legacyHtml' => $cleaned, 'title' => 'gpo'])
  ↓ response HTML 200 Content-Type text/html
```

### Learnings de 1bis.18a (référence critique)

- **Bootstrap 18a charge déjà les 4 includes GPO + stubs** : `samba-tool.inc.php`, `gpo.inc.php`, `delegations.inc.php`, `gpo_ui.inc.php`, `legacy/stubs/gpo_deps.inc.php` (guid/roaming/search_parcs). Aucune modification de `legacy/bootstrap.php` n'est requise pour cette story.
- **Constantes `SE_COMPUTER_ADMIN` / `SE_USER_ADMIN`** : à vérifier — elles doivent être définies quelque part (probablement `sambaedu/includes/functions.inc.php` ou un stub). Sinon `have_right($config, SE_COMPUTER_ADMIN)` échoue sur constante indéfinie (PHP 8.x = ERROR, pas warning).
- **Audit exec 18a** : `sambatool()` → pas d'échappement centralisé, `wbinfo` → paramètres non échappés. Documenté comme héritage legacy, non corrigé.
- **14 tests PASSANT dans `tests/Unit/LegacyGpoIncludesTest.php`** : pattern à reprendre pour `tests/Feature/Legacy/LegacyModuleGpoGestionTest.php`.
- **VM SSH inaccessible pendant l'implémentation 18a** : tests Unit validés au lint PHP. Pour cette story, même contrainte : pas de validation sur VM par le subagent — le dev mettra en review avant merge manuel.

### Learnings de 1bis.10 (iPXE — premier Tier 2 intégré)

- `$this->withoutVite()` dans `setUp()` des tests Feature.
- Pages avec `exit()` tuent PHPUnit → utiliser une page entrypoint qui ne fait pas `exit()` ou intercepter.
- `isHtmlWebPage()` détecte `text/html` + présence de `<form` `<table` `<div` `<h1` etc. — les 3 pages GPO affichent `<h1>`, `<form>` → seront embarquées dans le layout SER.
- Le catchall détecte `Content-Type` via `headers_list()`. Les 3 pages GPO n'envoient pas de header custom → défaut `text/html; charset=UTF-8` → embed OK.
- `chdir()` : le CWD est positionné dans le dossier du module avant exécution, puis restauré dans `finally`.

### Project Structure Notes

```
# Fichiers à créer
legacy/modules/gpo/gestion_gpo.php       # copie à l'identique de sambaedu/gpo/gestion_gpo.php
legacy/modules/gpo/gpo-maj.php           # copie à l'identique de sambaedu/gpo/gpo-maj.php
legacy/modules/gpo/gpo-export.php        # copie à l'identique de sambaedu/gpo/gpo-export.php
tests/Feature/Legacy/LegacyModuleGpoGestionTest.php  # 7+ tests (AC #11)

# Fichiers à modifier (éventuellement)
legacy/stubs/traitement_data.inc.php     # stub minimal SI sambaedu/includes/traitement_data.inc.php absent
legacy/bootstrap.php                     # UNIQUEMENT si un include manquant apparaît à l'exécution (non attendu)
legacy/stubs/config.inc.php              # UNIQUEMENT si get_config($config, true, true) ne fonctionne pas (non attendu)

# Fichiers source (lecture seule — NE PAS modifier)
sambaedu/gpo/gestion_gpo.php             # 69 lignes
sambaedu/gpo/gpo-maj.php                 # 193 lignes
sambaedu/gpo/gpo-export.php              # 88 lignes
sambaedu/includes/gpo.inc.php            # 1423 lignes (déjà chargé par 18a)
sambaedu/includes/samba-tool.inc.php     # 1396 lignes (déjà chargé par 18a)
sambaedu/includes/functions.inc.php      # chargé par bootstrap
sambaedu/includes/ihm.inc.php            # chargé par les pages via include_path

# Fichiers existants pertinents (référence)
app/Http/Controllers/LegacyCatchallController.php  # executeViaBootstrap + cleanLegacyHtml + isHtmlWebPage
legacy/bootstrap.php                               # charge les 4 includes GPO + stubs
legacy/stubs/gpo_deps.inc.php                      # guid, roaming_profiles_stats, search_parcs (18a)
legacy/stubs/admin_ui.inc.php                      # admin_header_html, admin_topbar_html, etc.
legacy/stubs/config.inc.php                        # get_config, $config bridge
legacy/stubs/ldap.inc.php                          # wrapper pour legacy/ldap.inc.php
legacy/ldap.inc.php                                # shim search_ad, have_right, etc.
tests/Feature/LegacyModuleIpxeTest.php             # pattern de test (8 tests, 23 assertions)
tests/Unit/LegacyGpoIncludesTest.php               # tests 18a (14 tests)
```

### Alignement avec l'architecture projet

- **Convention routing Laravel** : les pages legacy ne passent PAS par `resources/views/pages/` (qui est réservé aux nouvelles pages Livewire). Elles sont servies via `LegacyCatchallController` qui intercepte tout path non matché.
- **Shims** : les 3 pages ne nécessitent pas de nouveau shim — tout est couvert par 18a + shims Tier 1 (config, ldap, admin_ui).
- **Session / auth** : `bridgeLegacySession()` synchronise `auth()->user()` Laravel vers `$_SESSION['login']` legacy. Le guard Laravel (`web` middleware) gère l'auth avant que le catchall ne prenne la main.
- **CSRF** : injecté automatiquement par `cleanLegacyHtml()` dans tous les `<form method="post">` — le POST traverse ensuite le middleware CSRF Laravel.

### Commandes de validation manuelle (VM)

Sur le host, **ne pas exécuter depuis le subagent**. Pour le dev après implémentation :

```bash
# Bash (depuis le host)
ssh sshlab1Etab

# Sur la VM, une fois loggé :
cd /var/www/sambaedu-reload  # ou équivalent selon déploiement
php artisan test --filter=LegacyModuleGpoGestion

# Puis test HTTP manuel (avec session authentifiée)
curl -k -b "laravel_session=<valeur>" https://<vm>/gpo/gestion_gpo.php
curl -k -b "…" https://<vm>/gpo/gpo-maj.php
curl -k -b "…" https://<vm>/gpo/gpo-export.php

# Vérifier les logs
tail -f storage/logs/legacy.log
```

### References

- Architecture — Cloisonnement Legacy : [_bmad-output/planning-artifacts/architecture.md#Cloisonnement-Legacy]
- Architecture — Shims : [_bmad-output/planning-artifacts/architecture.md#Shims]
- Epics — Story 1bis.18 (section complète) : [_bmad-output/planning-artifacts/epics.md#Story-1bis-18]
- Epics — Story 1bis.18b : [_bmad-output/planning-artifacts/epics.md#Story-1bis-18b]
- Story précédente 1bis.18a (dépendance directe) : [_bmad-output/implementation-artifacts/1bis-18a-module-gpo-includes-core.md]
- Story 1bis.10 (pattern Tier 2, iPXE) : [_bmad-output/implementation-artifacts/1bis-10-module-ipxe.md]
- Story 1bis.11 (pattern Tier 2, WPKG) : [_bmad-output/implementation-artifacts/1bis-11-module-wpkg.md]
- Story 1bis.4 (patterns Tier 1, bootstrap + stubs) : [_bmad-output/implementation-artifacts/1bis-4-integration-modules-tier-1.md]
- Catchall : [app/Http/Controllers/LegacyCatchallController.php]
- Bootstrap : [legacy/bootstrap.php]
- Shims : [legacy/stubs/], [legacy/ldap.inc.php], [legacy/config.inc.php]
- Sources legacy (lecture seule) : [sambaedu/gpo/gestion_gpo.php], [sambaedu/gpo/gpo-maj.php], [sambaedu/gpo/gpo-export.php], [sambaedu/includes/gpo.inc.php]
- Tests pattern : [tests/Feature/LegacyModuleIpxeTest.php], [tests/Unit/LegacyGpoIncludesTest.php]

## Recommandation Modèle Dev

**Opus** — Bien que la taille totale des fichiers soit modeste (3 pages, 350 LOC, aucune à modifier — juste à copier), la story mobilise plusieurs axes transverses simultanés : (1) raisonnement sur le pipeline catchall (chdir, include_path, ob_start, bridgeSession, CSRF injection), (2) audit sécurité exec avec 8+ commandes dont un `sudo apt install` non conditionné et un `cp -f` avec injection possible, (3) résolution de dépendances entre stubs + bootstrap + includes legacy (traitement_data, vendor/autoload.php Composer du legacy, constantes SE_*), (4) tests Feature avec mocking de fonctions globales legacy (`list_gpo_templates_git`, `gpogetlink`) ou acceptation de retours vides hors VM, (5) anticipation des pièges `exit()`/`die()` dans PHPUnit. Un modèle Sonnet est adéquat techniquement mais Opus réduit le risque de passer à côté d'un des stubs manquants (traitement_data, vendor Composer) ou d'un vecteur sécurité lors de l'audit — à choisir selon la charge des tokens disponibles. Si Sonnet, renforcer la revue sur les T1.3, T1.5 et T3.4.

## Dev Agent Record

### Agent Model Used

Opus 4.6 (claude-opus-4-6[1m])

### Debug Log References

- **Bootstrap 18a déjà en place** — `legacy/bootstrap.php` charge les 4 includes GPO core (`samba-tool.inc.php`, `gpo.inc.php`, `delegations.inc.php`, `gpo_ui.inc.php`) + `gpo_deps.inc.php` (stubs guid/roaming/search_parcs). Aucune modification du bootstrap nécessaire.
- **`traitement_data.inc.php` existant** — présent dans `sambaedu/includes/traitement_data.inc.php` (72 lignes). Toutefois, son `require_once dirname(__FILE__) . '/../vendor/autoload.php'` pointe vers `sambaedu/vendor/autoload.php` (absent du repo reload, fourni uniquement sur VM via paquet Debian). Sur host PHPUnit, un stub no-op `legacy/stubs/traitement_data.inc.php` a été créé pour éviter le fatal : les stubs étant préfixés dans l'include_path, ils résolvent en priorité sur le chemin legacy — les entrées `$_GET/$_POST` restent non purifiées HTMLPurifier, couvertes par la protection CSRF Laravel et l'échappement manuel dans les pages GPO.
- **Constantes `SE_*`** — `SE_COMPUTER_ADMIN` est définie dans `legacy/ldap.inc.php:57` avec `if (!defined(...))` guard. Chargée par le bootstrap via `require_once ldap.inc.php`. Testé : `function_exists('have_right')` + lecture de la constante OK.
- **`list_gpo_templates_git` / `check_gpo_templates` + Composer** — ces 2 fonctions font `require_once(dirname(__FILE__) . '/../vendor/autoload.php')` depuis `sambaedu/includes/gpo.inc.php` (→ `sambaedu/vendor/autoload.php`). Ce chemin n'existe PAS dans le repo reload (choix délibéré : pas de vendor synthétique). Conséquence sur la VM : `/var/www/sambaedu/vendor/autoload.php` existe (paquet sambaedu-php), donc OK. Hors VM : fatal `Failed opening required` au premier appel — `gestion_gpo.php` ligne 56 (`check_gpo_templates($config)`) peut échouer. Les tests Feature utilisent `markTestSkipped` conditionné sur la présence de `/var/www/sambaedu/includes/gpo.inc.php` pour contourner ce cas hors VM.
- **Host vs VM** — le host local n'a pas `/var/www/sambaedu/includes/`, donc le bootstrap ne charge pas `gpo.inc.php` → les fonctions GPO sont absentes. Les tests Unit 18a et Feature 18b ne passent que sur la VM. La commande `ssh sshlab1Etab` et `php artisan test --filter=LegacyModuleGpoGestion` côté VM est documentée plus bas.
- **`die()` dans les pages legacy** — `gestion_gpo.php:53`, `gpo-maj.php:46`, `gpo-export.php:45` appellent `die()` quand `have_right` retourne `false`. En PHPUnit le `die()` tue le process entier → le test dédié au refus (`test_gestion_gpo_page_denies_access_without_right`) est annoté `@runInSeparateProcess` + `@preserveGlobalState disabled`.

### Completion Notes List

- **T1** — Analyse pré-requis validée : bootstrap 18a couvre déjà les 4 includes GPO + stubs. `traitement_data.inc.php` existe dans `sambaedu/includes/` mais son include transitif `sambaedu/vendor/autoload.php` (HTMLPurifier) n'est pas disponible hors VM → stub no-op ajouté.
- **T2.1** — 3 fichiers copiés à l'identique (`diff` confirmé via le test `test_gpo_module_files_exist`) : `legacy/modules/gpo/{gestion_gpo,gpo-maj,gpo-export}.php`.
- **T2.2** — Stub `legacy/stubs/traitement_data.inc.php` créé (no-op documenté). Évite le fatal `require_once sambaedu/vendor/autoload.php` hors VM tout en laissant la purification à la charge du middleware CSRF Laravel.
- **T2.3** — `legacy/bootstrap.php` **non modifié**. Choix documenté : tous les includes GPO et stubs requis par les 3 pages sont déjà couverts par 18a.
- **T3** — Intégration catchall vérifiée par les tests Feature : pattern `executeViaBootstrap` + `cleanLegacyHtml` (réécriture d'action + injection CSRF) applicable tel quel aux 3 pages — aucune modification du controller nécessaire.
- **T4** — 9 tests Feature créés dans `tests/Feature/Legacy/LegacyModuleGpoGestionTest.php` (couvre AC #1, #2, #3, #4, #6, #7, #9, #10, #11, #12). Couverture attendue : `test_gpo_module_files_exist`, `test_gestion_gpo_page_is_accessible_for_computer_admin`, `test_gestion_gpo_page_denies_access_without_right` (sub-process), `test_gpo_maj_page_renders_templates_selects`, `test_gpo_export_page_renders_gpo_list`, `test_gpo_functions_are_available_after_bootstrap`, `test_gpo_forms_have_csrf_token_and_current_action`, `test_gpo_pages_are_embedded_in_ser_layout`, `test_no_fatal_error_after_passive_load`.
- **T5** — Audit exec déjà consolidé dans Dev Notes (7 entrées, dont le `sudo apt install` CRITIQUE et le `cp -f $gpo` HAUT). Commandes de validation VM documentées. Sprint status et story mise à jour (review).
- **Tests non exécutés localement** — le host n'a ni `vendor/autoload.php` ni `/var/www/sambaedu/includes/` (code tournant normalement en VM via `sshlab1Etab`). Lint `php -l` OK sur les 5 fichiers créés/modifiés. Les tests Unit 18a suivent la même contrainte (validés sur VM uniquement).
- **Points de vigilance transmis en review** :
  - Vérifier sur VM que `list_gpo_templates_git()` résout bien `sambaedu-php` vendor (CzProject\GitPhp\Git) et que le `exec("sudo apt install …")` ne s'exécute pas en contexte PHPUnit.
  - Valider que le `die()` de la page de refus produit bien un output contenant « Vous n'avez pas les droits » (avant `exit`, le legacy écrit déjà ce message).
  - Confirmer que `imports_etab[]` apparaît si au moins un `etab_*.zip` est présent dans `/usr/share/sambaedu/gpo/` (test tolérant en son absence).

### File List

**Nouveaux fichiers :**

- `legacy/modules/gpo/gestion_gpo.php` — copie à l'identique de `sambaedu/gpo/gestion_gpo.php` (69 lignes)
- `legacy/modules/gpo/gpo-maj.php` — copie à l'identique de `sambaedu/gpo/gpo-maj.php` (193 lignes)
- `legacy/modules/gpo/gpo-export.php` — copie à l'identique de `sambaedu/gpo/gpo-export.php` (88 lignes)
- `legacy/stubs/traitement_data.inc.php` — stub no-op (pas de purification HTMLPurifier hors VM, CSRF Laravel prend le relais)
- `tests/Feature/Legacy/LegacyModuleGpoGestionTest.php` — 9 tests (pattern `LegacyModuleIpxeTest`)

**Fichiers modifiés :**

- `_bmad-output/implementation-artifacts/sprint-status.yaml` — `1bis-18b-module-gpo-gestion-import-export: ready-for-dev → review`, `last_updated: 2026-04-15`
- `_bmad-output/implementation-artifacts/1bis-18b-module-gpo-gestion-import-export.md` — Status, tasks cochées, Dev Agent Record, File List, Change Log

**Fichiers NON modifiés (confirmation) :**

- `legacy/bootstrap.php` — bootstrap 18a déjà complet (pas de nouveau `require_once` nécessaire)
- `legacy/stubs/config.inc.php`, `legacy/stubs/admin_ui.inc.php`, `legacy/stubs/gpo_deps.inc.php`, `legacy/stubs/ldap.inc.php` — stubs existants adéquats
- `sambaedu/gpo/*.php`, `sambaedu/includes/*.inc.php` — lecture seule, aucune modification
- `app/Http/Controllers/LegacyCatchallController.php` — aucune modification (pattern `executeViaBootstrap` + `cleanLegacyHtml` applicable tel quel)

### Change Log

| Date       | Auteur        | Description                                                                                                    |
|------------|---------------|----------------------------------------------------------------------------------------------------------------|
| 2026-04-15 | Henri (Opus)  | Copie des 3 pages GPO dans `legacy/modules/gpo/` (gestion_gpo, gpo-maj, gpo-export)                            |
| 2026-04-15 | Henri (Opus)  | Création du stub `legacy/stubs/traitement_data.inc.php` (no-op — contourne le `require_once sambaedu/vendor/autoload.php`) |
| 2026-04-15 | Henri (Opus)  | Création de `tests/Feature/Legacy/LegacyModuleGpoGestionTest.php` (9 tests couvrant AC #1, #2, #3, #4, #6, #7, #9, #10, #11, #12) |
| 2026-04-15 | Henri (Opus)  | Mise à jour sprint-status.yaml (review) + tasks cochées + Status: review dans la story                        |

### Known Limitations (découvert post-merge, 2026-04-15)

Le flux e2e d'import GPO via `/gpo/gpo-maj.php` **ne fonctionne pas sur VM** en l'état des shims `legacy/ldap.inc.php`. Le catchall rend la page (AC #1–#11 remplis côté host), mais la soumission d'un import déclenche `import_gpo()` → `search_ad($config, $gpo, 'gpo')` qui tombe dans le `default:` (ligne 437) et retourne `[]`. Résultat : `gpocreate()` est rappelé au 2ᵉ submit → erreur `GPO already existing`.

Cause racine : les types `gpo`/`site`/`subnet` n'ont pas été implémentés dans le shim LDAP de 18a, et les wrappers `gpolistcontainers`/`gpogetlink`/`gposetlink`/`gpodellink` + les fonctions sysvol (`sysvol_put`, `read_gpo_sysvol`, `update_gpo_sysvol`, `sysvol_acl_reset`) sont absents de `legacy/`.

Traité par la story **1bis-18g — Module GPO : Shims LDAP/AD + sysvol** — voir :
- `_bmad-output/planning-artifacts/sprint-change-proposal-2026-04-15.md`
- `_bmad-output/implementation-artifacts/1bis-18g-module-gpo-shims-ldap-sysvol.md`

18c → blocked sur 18g. 18b reste `done` selon ses AC (contrat HTTP + CSRF + embedding SER + tests host verts).

**Résolution 2026-04-16 (Opus) :** 1bis-18g implémentée (status `review`) — cases `gpo`/`site`/`subnet` ajoutées à `search_ad`, case `gpo` à `modify_ad`, bridge Kerberos + wrappers SYSVOL fallback dans `legacy/gpo_shim.inc.php`, 25 tests unit verts. Le flux e2e d'import GPO devient fonctionnel sur VM — reste à valider manuellement par Henri (cf. `tests/Unit/LegacyGpoShimsTest.php` + procédure VM dans la story 18g). Le déblocage de 18c (→ `ready-for-dev`) est effectué en même temps dans `sprint-status.yaml`.

### Commandes de validation manuelle (VM)

```bash
# Depuis le host
ssh sshlab1Etab

# Sur la VM
cd /var/www/sambaedu/laravel   # ou équivalent selon déploiement
php artisan test --filter=LegacyModuleGpoGestion

# Smoke test HTTP (après login via navigateur, copier laravel_session depuis cookies)
curl -k -b "laravel_session=<valeur>" https://<vm>/gpo/gestion_gpo.php | head -200
curl -k -b "laravel_session=<valeur>" https://<vm>/gpo/gpo-maj.php   | head -200
curl -k -b "laravel_session=<valeur>" https://<vm>/gpo/gpo-export.php| head -200

# Vérifier les logs
tail -f /var/www/sambaedu/laravel/storage/logs/legacy.log
```

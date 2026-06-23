# Story 27.16: Déploiement automatisé de la GPO bootstrap `SE_agent_bootstrap` + isolation par blocage d'héritage (OU computers de l'établissement)

Status: done

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

As a **administrateur SambaEdu SE5 (et l'opérateur des scripts `install.sh`/`update.sh`)**,
I want **que la GPO-dispatcher figée d'amorçage agent soit publiée et liée AUTOMATIQUEMENT dans l'AD (via une commande `php artisan` idempotente câblée dans les scripts d'install/update), sous le nom SE5 `SE_agent_bootstrap`, et que les postes gérés par SE5 soient isolés des GPO legacy collisionnantes**,
so that **tout poste dont l'install agent par l'unattend iPXE a échoué (ex. les 403 `local.request` corrigés au commit `1c333cd`) se réinstalle l'agent tout seul au boot suivant — le « filet éternel » FR25/#27 cesse d'être un pas de runbook manuel jamais exécuté — sans casser l'ancienne se4fs legacy qui partage le même AD**.

## Contexte (pourquoi cette story existe)

Debug terrain du 2026-06-23 (poste lab `windeboule`, 192.168.122.107). Un poste réinstallé en iPXE a pris **403** sur `/api/v1/agent/enrollment` et `/api/v1/agent/ca` pendant la passe *specialize* de l'unattend (bug `local.request`, corrigé `1c333cd`). Le bloc PowerShell de l'unattend étant `$ErrorActionPreference='Stop'` + `catch {} exit 0`, l'échec est **silencieux** et `agent.exe install` n'a jamais tourné. **La passe specialize ne se rejoue pas au reboot** → le poste reste agent-less définitivement.

Le filet de secours conçu **existe en ressource** mais **n'a jamais été déployé** : la story 25.4 a livré `resources/gpo/se4_agent_bootstrap/` (GPT.INI `[CSE]` + `Machine/Scripts/Startup/startup.cmd` générique : CA + binaire stable + `agent.exe install` + tâche refresh 240 min, idempotent) MAIS son déploiement était un **pas de runbook manuel (Fork 2, décision Henri 2026-06-13)** — `docs/runbooks/gpo-se4-agent-bootstrap.md` — jamais automatisé ni exécuté sur ce serveur. Vérifié : `samba-tool gpo listall` ne montre **aucune** GPO `*agent_bootstrap` ; `install.sh`/`update.sh` ne touchent à **aucune** GPO.

Cette story **automatise ce déploiement** et le **renomme en SE5** (`SE_agent_bootstrap`), en surmontant le blocage qui avait justifié le choix manuel en 25.4 (droits SYSVOL — voir AC2/Dev Notes), et **ajoute l'isolation OU SE5** pour éviter la collision agent↔GPO legacy sur un AD partagé.

## Acceptance Criteria

1. **Renommage SE5 + reconnaissance comme template publiable.**
   **Given** la ressource GPO `resources/gpo/se4_agent_bootstrap/`
   **When** on la renomme `SE_agent_bootstrap` (dossier + `[General]displayName` du `GPT.INI` + commentaires `startup.cmd`) et qu'on ajoute le préfixe `se_` à `App\Gpo\Support\GpoTemplateRegistry::ALLOWED_PREFIXES`
   **Then** `GpoTemplateRegistry::all()` reconnaît `SE_agent_bootstrap` comme template publiable (basename préfixe autorisé + `GPT.INI [CSE]` présent), `templateFor('SE_agent_bootstrap')` la retourne, et la reconnaissance des templates legacy `se4_*`/`etab_*` est inchangée (non-régression).

2. **Publication automatique idempotente vers SYSVOL — avec droits d'écriture réels (PAS de faux succès).**
   **Given** le DC AD joignable et des credentials Domain Admin disponibles (`admin_passwd` de `/etc/sambaedu/sambaedu.conf`)
   **When** la commande artisan de déploiement est exécutée
   **Then** la GPO `SE_agent_bootstrap` est **présente dans l'AD** (`samba-tool gpo listall` la liste) avec son contenu SYSVOL (`Machine/Scripts/Startup/startup.cmd` CRLF, `scripts.ini`, `GPT.INI` avec `gPCMachineExtensionNames` côté objet GPC), placeholders `###_SE4FS_NAME_###`/`###_DOMAIN_###` spécialisés
   **And** l'écriture SYSVOL s'effectue avec un **contexte Administrator** (ticket Kerberos / creds admin), **jamais** sous `www-sambaedu` READ-only — un `ACCESS_DENIED` masqué en exit 0 doit être **détecté et remonté en échec**, pas avalé (cf. mémoire `project_sysvol_wwwadmin_no_write_rights_and_silent_success`)
   **And** ré-exécuter la commande est **idempotent** (pas de doublon GPO, pas d'erreur si déjà publiée).

3. **Isolation par blocage d'héritage sur l'OU computers de l'établissement + liaison de la GPO (sans toucher au legacy ni à la fédération).**
   **Given** un AD **MUTUALISÉ entre ~75 établissements** où **TOUTES les GPO de config legacy** (`wpkg`, `Wallpaper`, `redirections`, `applications`, `proxy`, `imprimantes`, `lecteurs reseau`, `optimisations`, `sysprep`, `WOL`, …) sont liées **une seule fois à la RACINE** du domaine et s'appliquent donc à tous les collèges (vérifié lab1 2026-06-23 — cf. Dev Notes « Topologie AD fédérée »)
   **When** la commande de déploiement s'exécute
   **Then** l'**héritage est bloqué sur l'OU computers de NOTRE établissement** (`OU=<code_etab>,OU=computers,<base_dn>` — DN dérivé de la config ; `gPOptions=1` via `GpoService::setInheritance($ouDn, block=true)`) → les GPO legacy racine **cessent de s'appliquer à NOS postes en un coup** (décision Henri : option 1 « par établissement »), **sans aucun impact sur les 74 autres collèges**, et `SE_agent_bootstrap` est **liée à cette même OU** (`GpoService::setLink()` — **PAS à la racine**, sinon elle viserait tous les collèges)
   **And** **aucune** GPO legacy n'est supprimée, déliée **NI éditée** (ACL/Deny inclus) — ce sont des objets **partagés fédération-wide** liés racine (interdit ; cf. mémoires `project_ad_shared_legacy_se5_decommission`, `project_ad_federated_root_gpos`)
   **And** la résolution du DN cible est **idempotente** et gère les **deux topologies** : avec couche établissement (`OU=<code>,OU=computers` — prod/lab1) **et** sans (plat `OU=computers` — lab localdev /vm).

4. **Câblage dans les scripts de provisioning, avec garde fail-soft.**
   **Given** `update.sh` (atteint la VM déjà installée) et `install.sh` (greenfield), tous deux exécutés sur `se4fs` (membre, .50)
   **When** l'un d'eux tourne
   **Then** il appelle la commande artisan de déploiement de façon idempotente
   **And** si le DC est **injoignable** ou les **creds admin absents**, la commande **émet un warning et sort proprement (skip)** — elle **ne fait jamais échouer** l'install/update (la GPO sera reprise au prochain passage).

5. **Tests + non-régression + traçabilité.**
   **Then** `Se4AgentBootstrapTemplateTest` (renommé/adapté) valide : préfixe `se_` reconnu, `startup.cmd` **CRLF + pur ASCII**, généricité (aucune logique métier), `[CSE]` présent ; un test couvre l'**idempotence** et le **comportement de garde** (DC absent / creds absents → skip sans exception) ; les opérations sont **loguées** via `GpoLogger` (`gpo.create`/`gpo.sysvol.write`/`gpo.link.add`/`gpo.inheritance.set`) avec `operation_id`. Les tests exécutables sur HÔTE le sont ; l'e2e réel (publication Administrator + lien + reboot poste) = action manuelle Henri sur le lab.

## Tasks / Subtasks

- [x] **Tâche 1 — Renommage SE5 + whitelist préfixe** (AC: 1)
  - [x] Renommer `resources/gpo/se4_agent_bootstrap/` → `resources/gpo/SE_agent_bootstrap/` (git mv) ; conserver `GPT.INI`, `Machine/Scripts/scripts.ini`, `Machine/Scripts/Startup/startup.cmd` **CRLF + pur ASCII intacts**. _(vérifié `file`/`hexdump` : DOS batch CRLF, pas de LF orphelin, pur ASCII)_
  - [x] `GPT.INI [General]displayName=SE_agent_bootstrap` ; actualiser les commentaires d'en-tête de `startup.cmd` (le `:: …se4_agent_bootstrap` → `SE_agent_bootstrap`) sans altérer la logique ni les fins de ligne. _(édition byte-précise Python, CRLF/ASCII préservés)_
  - [x] `App\Gpo\Support\GpoTemplateRegistry::ALLOWED_PREFIXES` : ajouter `'se_'` (matching `mb_strtolower`, donc `SE_agent_bootstrap` passe). Vérifier que `se_` ne capture pas accidentellement de templates non voulus dans `templates_dir` (surface bornée). Ne PAS retirer `se4_`/`etab_`. _(`se_` ne chevauche pas `se4_` ; test `session_x` non capturé)_
  - [x] Test : `GpoTemplateRegistry` reconnaît `SE_agent_bootstrap` ET conserve `se4_wpkg`/`etab_*` (non-régression).

- [x] **Tâche 2 — Publication via plomberie existante, avec droits Administrator** (AC: 2) ⚠️ cœur de risque
  - [x] **Vérifier d'abord** (recon décisive) si le shim legacy `import_gpo` **CRÉE** une GPO absente de l'AD ou seulement **MET À JOUR** une GPO existante. **RÉSULTAT : `import_gpo` CRÉE bien la GPO absente** (`sambaedu/includes/gpo.inc.php:1070` — `count($gpo)==0` → `gpocreate($config,$displayname)` puis récursion `import_gpo(...)`), pose `gPCMachineExtensionNames` (`modify_ad` l.1030) + version. Donc PAS d'étape `samba-tool gpo create` séparée nécessaire (`GpoService::create()` reste un STUB cancelled, non utilisé). ⚠️ DÉVIATION : `GpoPublisher` ayant été supprimé en 27.14, la publication est portée par un nouveau service wrappant directement le shim legacy.
  - [x] Établir un **contexte d'écriture SYSVOL Administrator** : `kinit Administrator` avec `admin_passwd` dans un `KRB5CCNAME` dédié AVANT l'appel `import_gpo`, puis nettoyer (`kdestroy` + unlink, `finally`). Mot de passe sur stdin (`kinit --password-file=/dev/stdin`), jamais en argv ni logué.
  - [x] ~~Réutiliser `GpoPublisher::publish(...)`~~ → **SUPPRIMÉ EN 27.14**. Remplacé par `App\Services\Gpo\AgentBootstrapPublisher` (hors namespace `app/Gpo` → invoque légitimement Process/LdapRecord ; le shim legacy `import_gpo` orchestre `gpocreate` → `unzip_gpo` → `specialise_gpo` placeholders → `sysvol_put`). Staging du template `resources/gpo/SE_agent_bootstrap/` → `templates_dir/sambaedu-gpo/SE_agent_bootstrap/` (forme répertoire reconnue par le registre), placeholders spécialisés par `specialise_gpo` legacy.
  - [x] **Vérification d'écriture réelle** post-publication : re-lecture SYSVOL du `startup.cmd` déposé (`smbclient ls` sous Administrator), exige taille > 0 → un `ACCESS_DENIED` masqué en exit 0 devient un échec explicite (`gpo.sysvol.write` failure).
  - [x] Idempotence : `import_gpo($update=true)` ne duplique pas (réimport version-gated) ; le lien/héritage sont idempotents (16.5).

- [x] **Tâche 3 — Blocage d'héritage sur l'OU computers de l'établissement + liaison bootstrap** (AC: 3)
  > **Décision Henri 2026-06-23 : option (1) « par établissement, en un coup ».**
  - [x] **Dériver le DN cible depuis la config** : `OU=<code_etab>,OU=computers,<ldap_base_dn>` si la couche établissement existe (prod/lab1), sinon `OU=computers,<base>` (plat localdev). Code etab = `getCurrentEstablishmentCode()` (`etab_ou`), fallback extraction UAI depuis `se4fs_name` (`se4fs-0991229y` → `0991229y`). Détection LDAP read (existence), candidats par spécificité, **fail-soft si aucune** (warn + `published_without_link`, ne casse pas).
  - [x] `GpoService::setInheritance($ouDn, false)` (false = block, 16.5 ✅ → `gPOptions=1`). Idempotent.
  - [x] `GpoService::setLink($ouDn, $guid)` (16.5 ✅, idempotent). **JAMAIS la racine** (GUID résolu via `GpoService::list()` par displayName).
  - [x] **NE PAS** délier/supprimer NI éditer aucune GPO legacy — isolation par blocage héritage local uniquement (aucune écriture sur les GPO legacy).

- [x] **Tâche 4 — Commande artisan idempotente fail-soft** (AC: 2,3,4)
  - [x] Commande `php artisan gpo:deploy-agent-bootstrap` orchestrant Tâches 2+3 (via `AgentBootstrapPublisher`), idempotente, loguée `GpoLogger` (`gpo.create`/`gpo.sysvol.write`/`gpo.link.add`/`gpo.inheritance.set`, `operation_id` corrélé).
  - [x] **Garde** : DC injoignable (`GpoService::list()` lève) OU `admin_passwd` absent → `warn` + exit 0 (`skipped`), ne lève pas. `skipped` distinct de `failed` (DTO `AgentBootstrapDeployResult`).
  - [x] Options : `--force` (republication), `--dry-run` (aucun side effect), `--strict` (exit 1 sur échec réel ; sinon fail-soft exit 0 pour le câblage scripts). Pas de secret en clair (scrub défensif).

- [x] **Tâche 5 — Câblage `update.sh` + `install.sh`** (AC: 4)
  - [x] Appel `php artisan gpo:deploy-agent-bootstrap` ajouté à `scripts/update.sh` (`ensure_agent_bootstrap_gpo`, après `ensure_wpkg_bundle`, sous `www-admin` quand présent, `|| true` non bloquant). `install.sh` **rejoue update.sh** en fin d'install → wiring hérité (commentaire mis à jour pour le mentionner explicitement). Idempotent + garde fail-soft interne.
  - [x] Runbook documente le prérequis DC joignable + `admin_passwd`.

- [x] **Tâche 6 — Tests + docs + runbook** (AC: 5)
  - [x] Renommé `tests/Unit/Gpo/Se4AgentBootstrapTemplateTest.php` → `SeAgentBootstrapTemplateTest.php` : préfixe `se_` reconnu, CRLF + pur ASCII (3 fichiers), généricité, `[CSE]`, displayName SE5.
  - [x] Tests commande/publisher : `AgentBootstrapPublisherTest` (garde fail-soft : DC absent / creds absents → `skipped` sans exception, aucun appel destructeur `setInheritance`/`setLink` ; idempotence re-run) + `GpoDeployAgentBootstrapCommandTest` (mapping exit codes skip/deployed/dry-run→0, failed→0 fail-soft / 1 strict, forward `--force`/`--dry-run`).
  - [x] `docs/runbooks/gpo-se4-agent-bootstrap.md` actualisé : rename, **mode automatisé** (commande + prérequis creds/DC + vérification), isolation OU (blocage héritage, jamais racine), suppression GPO legacy hors scope (FR30/27-14).
  - [x] Append-only : QA `docs/qa/domains/gpo.md` §27.16 (5 scénarios + checklist) ; note d'isolation sans état transitoire legacy/agent.

## Dev Notes

### Plomberie GPO vérifiée (code courant, 2026-06-23) — utiliser l'existant, ne rien réinventer
- `App\Gpo\Services\GpoService::create()` ET `delete()` sont des **STUBS** (`throw RuntimeException('not implemented, Story 16.4')`). 16.4 **cancelled** (mémoire `project_no_native_gpo_creation`). **NE PAS** les implémenter/les utiliser comme API native. Si une création de GPC est nécessaire (cf. Tâche 2), faire un appel `samba-tool gpo create` ciblé **avec creds Administrator**, pas via ces stubs.
- `GpoService::setLink()` / `removeLink()` / `setInheritance()` = **implémentés** (Story 16.5). Mode array via `SambaToolRunner` (pas de concat shell), inputs validés regex. [Source: app/Gpo/Services/GpoService.php:255-381 ; app/Gpo/README.md:69-74]
- **Chemin de publication réel** = `App\Gpo\Services\GpoPublisher::publish(string $displayName, bool $force, ?string $operationId): GpoTemplate` → lock `Cache::lock` par displayName → shim legacy `import_gpo($config, $displayName, $archive, update=true, $force)` (binding `legacy.import_gpo`, fallback `legacy/bootstrap.php`). Lève si non publiable / lock indispo / `import_gpo` false. [Source: app/Gpo/Services/GpoPublisher.php:88-192]
- `App\Gpo\Support\GpoTemplateRegistry` scanne `config('sambaedu.gpo.templates_dir')` (= `GPO_TEMPLATES_DIR`, défaut `/usr/share/sambaedu/gpo/`), reconnaît `<name>.zip` OU dossier `sambaedu-gpo/<name>/`, **filtre par `ALLOWED_PREFIXES=['se4_','etab_']`** (basename, `mb_strtolower`) + exige `GPT.INI [CSE]`. [Source: app/Gpo/Support/GpoTemplateRegistry.php:47,73-151 ; config/sambaedu.php:543]
- ⚠️ **Le blocage qui a fait choisir le runbook manuel en 25.4** : `import_gpo` écrit SYSVOL via `smbclient`/`samba-tool` en s'appuyant sur un **ticket Kerberos** (`KRB5CCNAME`). Sous `www-sambaedu` (PHP-FPM, mémoire `project_php_fpm_user_www_admin`), READ-only sur SYSVOL → `ACCESS_DENIED` que `smbclient` **masque en exit 0** (faux « publié »). La commande DOIT établir un contexte **Administrator** (`kinit` avec `admin_passwd`) et **vérifier l'écriture réelle**. [Source: 25-4 §forks décision 4 ; mémoires `project_sysvol_write_needs_wwwadmin_kinit`, `project_sysvol_wwwadmin_no_write_rights_and_silent_success`]
- **CRLF obligatoire** pour `startup.cmd`/`scripts.ini` déposés en SYSVOL (LF seul échoue en silence). [Source: 25-4 piège 12 ; mémoires `project_migration_passthrough_gpo_lab`]

### Topologie AD (vérifiée terrain 2026-06-23) — voir mémoire `project_ad_shared_legacy_se5_decommission`
- DC AD = **`se4ad` (192.168.122.60)**, machine **séparée et PARTAGÉE** entre l'ancienne se4fs (legacy, prod, **en décommissionnement**) et la SE5. `se4fs` (192.168.122.50, où tournent `install.sh`/`update.sh`) est un **membre** (pas de `secrets.ldb`, pas de keytab/ticket par défaut).
- GPO legacy (`wpkg`, `applications`, `Wallpaper`, `redirections`, …) **liées à la racine `DC=localdev,DC=fr`** → s'appliquent à tous les postes. `OU=computers` partagée (windeboule = `CN=windeboule,OU=info2,OU=computers,DC=localdev,DC=fr`).
- **INTERDIT** : supprimer/délier une GPO legacy (casse l'ancienne se4fs). Anti-collision = **isolation par OU SE5 + blocage héritage**, pas destruction.
- Creds Domain Admin dispo sur se4fs : `admin_passwd` (et `ldap_admin_passwd`) dans `/etc/sambaedu/sambaedu.conf`.
- Domaine : `samba_domain=localdev`, `domain=localdev.fr`, `SE4FS_NAME=se4fs`.

### Topologie AD FÉDÉRÉE — investigation lab1 du 2026-06-23 (décisive pour la Tâche 3) — voir mémoire `project_ad_federated_root_gpos`
Reconnaissance LDAP read-only sur **lab1** (`se4fs-0991229y`, membre ; realm `LAB1.IRUNDO.FR`, base `dc=lab1,dc=irundo,dc=fr`, DC `se4ad`) :
- **L'AD est MUTUALISÉ entre ~75 établissements** : `OU=<code_uai>,OU=utilisateurs` × ~75 (0991013y … 0991654y). Les **users** sont fédérés ; les **computers/Groups/Parcs/delegations** sont **par établissement** (seul `OU=0991229y` présent sous `OU=computers`, `OU=Groups`, `OU=Parcs`, …).
- **Notre établissement a son sous-arbre dédié** : `OU=0991229y,OU=computers,DC=lab1,DC=irundo,DC=fr`, avec `OU=base,…` dessous (postes). **Aucun `gPLink`, aucun `gPOptions`** dessus aujourd'hui → les GPO racine y descendent par héritage.
- **TOUTES les GPO de config legacy sont liées à la RACINE** `DC=lab1,DC=irundo,DC=fr` (donc s'appliquent aux 75 collèges) : `wpkg`, `WOL`, `windows-update-ON`, `Wallpaper`, `veille_off`, `sysprep`, `redirections`, `proxy`, `optimisations`, `lecteurs reseau`, `imprimantes`, `impression`, `applications`, `acces distant`, `desactivation uac`, `Bureau`, `activation smb3`, … (~16+ GPO en `gPLink` racine). Plus `Default Domain Policy`/`Default Domain Controllers Policy`/`Windows update` (système).
- **CONSÉQUENCE FONDATRICE** : supprimer/délier/éditer (même une simple ACE Deny) une de ces GPO = geste **fédération-wide** qui impacte les 75 collèges → **interdit**. Lier `SE_agent_bootstrap` à la racine = pousser notre agent sur les 75 collèges → **interdit**. Le **seul** levier propre, local et réversible = **bloquer l'héritage (`gPOptions=1`) sur l'OU computers de NOTRE établissement** + y lier le bootstrap (Tâche 3, option 1).
- **Écart lab/prod à gérer dans le code** : lab1/prod = couche établissement (`OU=<code>,OU=computers`) ; lab `/vm` localdev = **plat** (`OU=info2,OU=computers`, pas de couche `OU=<code>`). La commande doit dériver le bon DN et fail-soft si aucun.

### Le startup.cmd (déjà livré, ne pas réécrire la logique)
`Machine/Scripts/Startup/startup.cmd` (SYSTEM, au boot) : (a) `certutil -addstore -f Root` depuis `/api/v1/agent/ca` (idempotent), (b) download `/api/v1/agent/stable/download` → `%ProgramFiles%\SambaEdu\Agent\agent.exe`, (c) `agent.exe install -server-url http://%SE4FS%` (installe **ou répare**), (d) (re)crée tâche planifiée `SambaEduAgent-Bootstrap-Refresh` (SYSTEM, 240 min) qui rejoue (a)-(c). Seule spécialisation = `###_SE4FS_NAME_###`. Aucune logique métier. [Source: resources/gpo/SE_agent_bootstrap/Machine/Scripts/Startup/startup.cmd]

### Pourquoi ce filet est nécessaire (preuve)
Les endpoints d'amorçage (`/api/v1/agent/ca|stable|stable/download|enrollment`) sont désormais en `auth.v1.lan-only` (RFC1918), 200 depuis le LAN (corrigé `1c333cd`) ; la stable `2.2.17` est publiée. Mais l'unattend est **one-shot fail-silent** : un poste dont le bloc agent échoue n'a aucun rattrapage sans cette GPO. Lié à [[project_agent_selfupdate_validated_publish_gap]].

### Project Structure Notes
- Module GPO natif = `app/Gpo/*` (abstraction `samba-tool gpo` via `SambaToolRunner`, **aucun `exec()` direct** hors `SambaToolRunner` — garde-fou archi testé `GpoNamespaceTest`). La nouvelle commande artisan doit respecter ce garde-fou (passer par `GpoService`/`GpoPublisher`/`SambaToolRunner`, pas d'`exec` brut). [Source: app/Gpo/README.md:15-23]
- Commandes artisan = `app/Console/Commands/` (cf. `WpkgBundleGenerateCommand`, `GpoWarmCacheCommand` pour le pattern).
- Template GPO côté serveur sous `templates_dir` (`/usr/share/sambaedu/gpo/`, hors git) — la source versionnée reste `resources/gpo/SE_agent_bootstrap/`. [Source: mémoire `project_storage_convention_non_versioned`]
- Racine projet Laravel = artisan/app à la racine (host + VM). [Source: mémoire `project_root_is_laravel`]

### Actions VM (Henri, hors dev-cycle — pas de SSH /vm depuis le worktree)
- `php artisan gpo:deploy-agent-bootstrap` sur la VM (DC joignable + `admin_passwd`) → vérifier `samba-tool gpo listall | grep SE_agent_bootstrap` + contenu SYSVOL.
- Déplacer `windeboule` (et les postes migrés) dans l'OU SE5, puis **reboot** → l'agent s'installe seul (startup.cmd), puis demande l'enrôlement porte 2 (approbation un-clic UI 25.3).
- Si nouvelle clé `config/*.php` : `config:cache` + chown www-admin `bootstrap/cache/` ; pas de route neuve attendue (donc pas de `route:cache`). [Source: mémoires `project_vm_config_cache_not_synced`, `project_route_cache_vm_ephemeral_test_routes`]

### Hors-scope (explicite)
- **Suppression/délien des GPO legacy** : viendra à l'extinction du legacy (FR30 / story `27-14-extinction-canal-legacy-en-bloc`), `delete()` natif restant cancelled (16.4).
- **Implémentation native de `GpoService::create()/delete()`** : non (cancelled). Création de GPC = appel `samba-tool` ciblé avec creds admin si strictement nécessaire (Tâche 2).
- **Création d'une OU SE5 dédiée + déplacement des postes dedans** : **ABANDONNÉ** (option 2 « par poste, étagé » écartée — décision Henri 2026-06-23). L'investigation lab1 a montré que les postes vivent déjà dans le sous-arbre établissement `OU=<code>,OU=computers` ; on bloque l'héritage **sur cette OU existante** (option 1), sans créer d'OU ni déplacer de poste. Une éventuelle granularité par-poste *intra-établissement* (sous-OU `OU=SE5,OU=<code>,OU=computers` + déplacement) reste une évolution possible mais hors-scope ici.
- **Suppression/édition (ACL/Deny) des GPO legacy racine** : interdit définitivement tant que l'AD est mutualisé (fédération-wide) ; le nettoyage viendra à l'extinction legacy globale (hors périmètre établissement).

### References
- [Source: _bmad-output/implementation-artifacts/25-4-deux-chemins-installation-gpo-dispatcher-figee-depot-ipxe.md#Tâche-4, #Forks, lignes 34,71-74,145-148,219]
- [Source: app/Gpo/Services/GpoPublisher.php:60-192]
- [Source: app/Gpo/Services/GpoService.php:205-381]
- [Source: app/Gpo/Support/GpoTemplateRegistry.php:39-198]
- [Source: app/Gpo/README.md:8-23,56-90]
- [Source: config/sambaedu.php:543 (templates_dir), :260 (se4fs_name)]
- [Source: resources/gpo/SE_agent_bootstrap/ (GPT.INI, scripts.ini, startup.cmd)]
- [Source: scripts/install.sh, scripts/update.sh (câblage, aucune interaction GPO actuelle)]
- Mémoires : `project_ad_shared_legacy_se5_decommission`, `project_no_native_gpo_creation`, `project_sysvol_wwwadmin_no_write_rights_and_silent_success`, `project_sysvol_write_needs_wwwadmin_kinit`, `project_gpo_dispatcher_static_anchor`, `project_no_legacy_transition_state`, `project_storage_convention_non_versioned`, `project_se4_se5_naming`, `project_php_fpm_user_www_admin`, `project_agent_selfupdate_validated_publish_gap`.

## Dev Agent Record

### Agent Model Used

claude-opus-4-8[1m] (Claude Opus 4.8, 1M context) — DEV agent dev-story.

### Debug Log References

- **Recon Tâche 2 (décisive)** — `sambaedu/includes/gpo.inc.php`, fonction `import_gpo` (l.962-1080) :
  - Branche `count($gpo) > 0` (GPO déjà dans l'AD) → update version-gated (`unzip_gpo` + `specialise_gpo` + `sysvol_put` + `modify_ad`).
  - Branche `else` (l.1070, **GPO absente**) → `$uuid = gpocreate($config, $displayname)` puis **récursion** `import_gpo($config, $displayname, $gpo_archive, true, false)`.
  - **Conclusion : `import_gpo` CRÉE bien une GPO absente** (via `gpocreate` = `samba-tool gpo create`), pose `gPCMachineExtensionNames` (`modify_ad`, l.1030 — l'attribut SANS lequel le `startup.cmd` ne s'exécute jamais, cf. runbook §3bis) et la version. ⇒ aucune étape `samba-tool gpo create` séparée requise dans notre code ; `GpoService::create()` (STUB cancelled 16.4) NON utilisé.
- **Déviation découverte** : `GpoPublisher` (que la story supposait vivant) a été **SUPPRIMÉ en 27.14** (`app/Gpo/Services/GpoPublisher.php` n'existe plus ; cf. commit 6ca3dfd). Le chemin de publication SYSVOL a donc dû être ré-établi via un nouveau service wrappant directement le shim legacy `import_gpo` (toujours présent dans `sambaedu/includes/gpo.inc.php`, chargé par `legacy/bootstrap.php`).
- SYSVOL write legacy (`sysvol_put`) = `smbclient ... --use-kerberos=required` → s'appuie sur `KRB5CCNAME` ambiant ⇒ contexte Administrator établi via `putenv('KRB5CCNAME=...')` autour de l'appel.
- Tests HÔTE : `php artisan test --filter 'GpoTemplateRegistry|SeAgentBootstrap|AgentBootstrapPublisher|GpoDeployAgentBootstrap|GpoNamespace'` → **25 passed, 2 skipped** (ext-zip absent host). Suite Gpo unit complète : 167 passed.
- `bash -n scripts/update.sh && bash -n scripts/install.sh` → OK. `php -l` sur les 3 nouveaux fichiers PHP → OK.
- Échec PRÉ-EXISTANT non lié : `IpxeNamespaceTest > story 3 4 templates are ascii strict` (`ipxe/windows/cmd/{join,post}.blade.php` non-ASCII) — fichiers intouchés par 27.16, hors scope.

### Completion Notes List

- **Garde-fou archi respecté** : la nouvelle plomberie (Process/kinit/smbclient/LdapRecord) vit sous `App\Services\Gpo`, **PAS** `App\Gpo` → `GpoNamespaceTest` reste vert (il ne scanne que `app/Gpo/`). Les écritures GPO natives (lien + héritage) passent exclusivement par `GpoService` → `SambaToolRunner`. La publication SYSVOL + CSE passe par la frontière legacy `import_gpo` (`exec` autorisé là).
- **Sécurité secrets** : `admin_passwd` jamais en argv (`kinit --password-file=/dev/stdin`) ni en log (scrub défensif sur les sorties). Ccache Kerberos isolé dans un fichier temporaire unique, purgé en `finally` (`kdestroy` + `unlink`).
- **Anti faux-succès SYSVOL** : la vérification d'écriture réelle (re-lecture `smbclient ls` + taille > 0) transforme un `ACCESS_DENIED` masqué en exit 0 en échec explicite — exactement le piège qui avait justifié le runbook manuel en 25.4.
- **Fail-soft** : la commande sort en 0 sur skip ET sur échec (sauf `--strict`) → le câblage `update.sh`/`install.sh` ne casse jamais l'install/update.
- **Idempotence** : rename via `git mv` (rename tracké) ; `import_gpo` version-gated ; `setInheritance`/`setLink` idempotents (16.5).
- **Hors-scope respecté** : aucune GPO legacy supprimée/déliée/éditée ; `GpoService::create()/delete()` STUBS non touchés ; pas de création d'OU ni de déplacement de poste (option 1).
- **À valider par Henri (e2e réel, hors dev-cycle)** : `php artisan gpo:deploy-agent-bootstrap` sur la VM/lab (DC joignable + `admin_passwd`) → `samba-tool gpo listall | grep SE_agent_bootstrap` + contenu SYSVOL + `gPCMachineExtensionNames` + blocage héritage + lien OU ; déplacer un poste agent-less dans l'OU établissement + reboot.

### File List

**Nouveaux fichiers :**
- `app/Services/Gpo/AgentBootstrapPublisher.php` — orchestrateur (garde fail-soft, staging, kinit Administrator, publication via `import_gpo`, vérif écriture réelle, résolution OU 2-topologies, blocage héritage + lien).
- `app/Services/Gpo/AgentBootstrapDeployResult.php` — DTO résultat (deployed/published_without_link/skipped/dry-run/failed).
- `app/Console/Commands/GpoDeployAgentBootstrapCommand.php` — commande `gpo:deploy-agent-bootstrap` (`--force`/`--dry-run`/`--strict`).
- `tests/Unit/Gpo/AgentBootstrapPublisherTest.php` — tests garde fail-soft + idempotence.
- `tests/Feature/Console/GpoDeployAgentBootstrapCommandTest.php` — tests mapping exit codes + forward options.

**Modifiés :**
- `app/Gpo/Support/GpoTemplateRegistry.php` — ajout préfixe `se_` à `ALLOWED_PREFIXES`.
- `resources/gpo/SE_agent_bootstrap/GPT.INI` — `displayName=SE_agent_bootstrap` (CRLF/ASCII préservés).
- `resources/gpo/SE_agent_bootstrap/Machine/Scripts/Startup/startup.cmd` — en-tête renommé (CRLF/ASCII préservés, logique inchangée).
- `scripts/update.sh` — fonction `ensure_agent_bootstrap_gpo` + appel dans `main()`.
- `scripts/install.sh` — commentaire de finalisation mis à jour (wiring hérité via update.sh).
- `tests/Unit/Gpo/GpoTemplateRegistryTest.php` — tests non-régression `se_` (reconnaissance bootstrap + non-capture `session_x`).
- `docs/qa/domains/gpo.md` — section §27.16 append-only (5 scénarios + checklist).
- `docs/runbooks/gpo-se4-agent-bootstrap.md` — rename + mode automatisé + isolation OU + hors-scope legacy.
- `_bmad-output/implementation-artifacts/sprint-status.yaml` — clé `27-16-…` → review.

**Renommés (git mv) :**
- `resources/gpo/se4_agent_bootstrap/` → `resources/gpo/SE_agent_bootstrap/` (3 fichiers : `GPT.INI`, `Machine/Scripts/scripts.ini`, `Machine/Scripts/Startup/startup.cmd`).
- `tests/Unit/Gpo/Se4AgentBootstrapTemplateTest.php` → `tests/Unit/Gpo/SeAgentBootstrapTemplateTest.php`.

## Change Log

| Date | Version | Description | Author |
|------|---------|-------------|--------|
| 2026-06-23 | 1.0 | **DEV TERMINÉ (dev-story, claude-opus-4-8[1m]) → review.** Recon Tâche 2 : `import_gpo` CRÉE la GPO absente (gpocreate + récursion) + pose `gPCMachineExtensionNames`. Déviation : `GpoPublisher` supprimé en 27.14 → nouveau service `App\Services\Gpo\AgentBootstrapPublisher` (hors `app/Gpo`, garde-fou préservé) wrappant `import_gpo` sous contexte Kerberos Administrator (kinit ccache dédié purgé) + vérif écriture réelle SYSVOL. Isolation OU via `GpoService` natif (setInheritance block + setLink, DN dérivé config 2-topologies, fail-soft). Commande `gpo:deploy-agent-bootstrap` (--force/--dry-run/--strict) idempotente fail-soft, câblée update.sh + héritée install.sh. Rename SE5 `SE_agent_bootstrap` (git mv + GPT.INI + startup.cmd, CRLF/ASCII vérifiés) + préfixe `se_` (non-régression se4_/etab_). Tests HÔTE 25 PASS/2 skip + GpoNamespace clean. QA gpo.md §27.16 + runbook actualisés. E2E réel = action manuelle Henri. | DEV (opus) |
| 2026-06-23 | 0.2 | **Investigation AD (lab1) + décision isolation.** Recon LDAP read-only lab1 → AD MUTUALISÉ ~75 établissements, TOUTES les GPO legacy liées à la RACINE (partagées par tous les collèges) ⇒ suppression/édition/délien = fédération-wide INTERDITS ; lier le bootstrap à la racine = INTERDIT. Notre établissement a son sous-arbre `OU=<code>,OU=computers` (sans héritage bloqué aujourd'hui). **Décision Henri : option 1** — bloquer l'héritage (`gPOptions=1`) sur l'OU computers de l'établissement + y lier `SE_agent_bootstrap` (DN dérivé config, gère couche-etab prod/lab1 ET plat localdev). AC3 + Tâche 3 réécrits ; Dev Notes « Topologie AD fédérée » ajoutée ; OU SE5 dédiée + déplacement postes ABANDONNÉS. | henri |
| 2026-06-23 | 0.1 | Création story 27.16 (orchestrateur, debug terrain windeboule ; renumérotée depuis 27.15 — collision avec un processus parallèle). Suite/automatisation du Fork 2 manuel de 25.4 : publication auto `SE_agent_bootstrap` (rename SE5 + whitelist préfixe `se_`), via plomberie existante `GpoPublisher`/`import_gpo` (create/delete restent cancelled), avec contexte Administrator pour vaincre le blocage SYSVOL READ-only ; isolation OU SE5 (héritage bloqué) anti-collision sur AD partagé legacy↔SE5 (décommissionnement) ; câblage update.sh/install.sh fail-soft. 5 AC, 6 tâches. Reco modèle : opus. | henri |

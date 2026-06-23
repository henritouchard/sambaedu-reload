# Story 27.16: Déploiement automatisé de la GPO bootstrap `SE_agent_bootstrap` + isolation OU SE5

Status: ready-for-dev

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

3. **Isolation OU SE5 + liaison de la GPO (sans toucher au legacy).**
   **Given** l'AD partagé legacy↔SE5, `OU=computers` partagée, GPO legacy liées à la racine `DC=localdev,DC=fr`
   **When** la commande de déploiement s'exécute
   **Then** une **OU SE5 dédiée** existe (idempotent ; nom cible décidé en Tâche 3 — défaut proposé `OU=SE5,OU=computers,DC=<...>`), l'**héritage y est bloqué** (`GpoService::setInheritance(..., false)` → les GPO legacy domain-root ne s'y appliquent plus), et `SE_agent_bootstrap` y est **liée** (`GpoService::setLink()`)
   **And** **aucune** GPO legacy n'est supprimée ni déliée (interdit — casserait l'ancienne se4fs ; cf. mémoire `project_ad_shared_legacy_se5_decommission`).

4. **Câblage dans les scripts de provisioning, avec garde fail-soft.**
   **Given** `update.sh` (atteint la VM déjà installée) et `install.sh` (greenfield), tous deux exécutés sur `se4fs` (membre, .50)
   **When** l'un d'eux tourne
   **Then** il appelle la commande artisan de déploiement de façon idempotente
   **And** si le DC est **injoignable** ou les **creds admin absents**, la commande **émet un warning et sort proprement (skip)** — elle **ne fait jamais échouer** l'install/update (la GPO sera reprise au prochain passage).

5. **Tests + non-régression + traçabilité.**
   **Then** `Se4AgentBootstrapTemplateTest` (renommé/adapté) valide : préfixe `se_` reconnu, `startup.cmd` **CRLF + pur ASCII**, généricité (aucune logique métier), `[CSE]` présent ; un test couvre l'**idempotence** et le **comportement de garde** (DC absent / creds absents → skip sans exception) ; les opérations sont **loguées** via `GpoLogger` (`gpo.create`/`gpo.sysvol.write`/`gpo.link.add`/`gpo.inheritance.set`) avec `operation_id`. Les tests exécutables sur HÔTE le sont ; l'e2e réel (publication Administrator + lien + reboot poste) = action manuelle Henri sur le lab.

## Tasks / Subtasks

- [ ] **Tâche 1 — Renommage SE5 + whitelist préfixe** (AC: 1)
  - [ ] Renommer `resources/gpo/se4_agent_bootstrap/` → `resources/gpo/SE_agent_bootstrap/` (git mv) ; conserver `GPT.INI`, `Machine/Scripts/scripts.ini`, `Machine/Scripts/Startup/startup.cmd` **CRLF + pur ASCII intacts**.
  - [ ] `GPT.INI [General]displayName=SE_agent_bootstrap` ; actualiser les commentaires d'en-tête de `startup.cmd` (le `:: …se4_agent_bootstrap` → `SE_agent_bootstrap`) sans altérer la logique ni les fins de ligne.
  - [ ] `App\Gpo\Support\GpoTemplateRegistry::ALLOWED_PREFIXES` : ajouter `'se_'` (matching `mb_strtolower`, donc `SE_agent_bootstrap` passe). Vérifier que `se_` ne capture pas accidentellement de templates non voulus dans `templates_dir` (surface bornée). Ne PAS retirer `se4_`/`etab_`.
  - [ ] Test : `GpoTemplateRegistry` reconnaît `SE_agent_bootstrap` ET conserve `se4_wpkg`/`etab_*` (non-régression).

- [ ] **Tâche 2 — Publication via plomberie existante, avec droits Administrator** (AC: 2) ⚠️ cœur de risque
  - [ ] **Vérifier d'abord** (recon décisive) si le shim legacy `import_gpo` **CRÉE** une GPO absente de l'AD ou seulement **MET À JOUR** une GPO existante. Source : `sambaedu/includes/gpo.inc.php` (`import_gpo`), `legacy/bootstrap.php`. Si `import_gpo` ne crée pas → ajouter une étape de création du GPC AVANT (`samba-tool gpo create` exécuté **avec creds Administrator** — `GpoService::create()` est un STUB 16.4, NE PAS le ré-implémenter en natif ; faire un appel `samba-tool` ciblé dans la commande/un support dédié, mode array via `SambaToolRunner` ou processus Administrator).
  - [ ] Établir un **contexte d'écriture SYSVOL Administrator** : `kinit Administrator` avec `admin_passwd` (`/etc/sambaedu/sambaedu.conf`) dans un `KRB5CCNAME` dédié AVANT l'appel `import_gpo`/samba-tool, puis nettoyer. Raison : `www-sambaedu` n'a que READ sur SYSVOL → `mkdir`/`put` `ACCESS_DENIED` que `smbclient` masque en exit 0 (mémoires `project_sysvol_write_needs_wwwadmin_kinit`, `project_sysvol_wwwadmin_no_write_rights_and_silent_success`). C'est exactement le workaround Administrator du runbook 25.4, ici automatisé.
  - [ ] Réutiliser `App\Gpo\Services\GpoPublisher::publish('SE_agent_bootstrap', force, operationId)` (lock par displayName, `import_gpo` → extraction zip + `specialise_gpo` placeholders + write SYSVOL). Le template doit être présent dans `config('sambaedu.gpo.templates_dir')` (défaut `/usr/share/sambaedu/gpo/`) → prévoir le **dépôt du template** depuis `resources/gpo/SE_agent_bootstrap/` vers `templates_dir` (étape de la commande ou des scripts ; convention server-side hors git `project_storage_convention_non_versioned`).
  - [ ] **Vérification d'écriture réelle** post-publication : relire le contenu SYSVOL (taille/hash du `startup.cmd` déposé vs source) pour transformer un faux succès en échec explicite. Ne PAS se fier au seul exit code de smbclient.
  - [ ] Idempotence : republier une GPO déjà présente ne duplique pas et ne lève pas (best-effort, log `noop`).

- [ ] **Tâche 3 — OU SE5 + blocage héritage + liaison** (AC: 3)
  - [ ] **Décision de nommage/DN de l'OU SE5** (à figer avec Henri si non tranché : défaut proposé `OU=SE5,OU=computers,DC=localdev,DC=fr`). Création idempotente (`samba-tool ou create` ou LdapRecord, avec creds admin ; ne rien faire si existe).
  - [ ] `GpoService::setInheritance('<OU SE5 DN>', block=true)` (16.5 ✅) → les GPO legacy racine ne s'appliquent plus aux postes de l'OU (anti-collision wallpaper/registry/applications agent).
  - [ ] `GpoService::setLink('<OU SE5 DN>', 'SE_agent_bootstrap')` (16.5 ✅, idempotent : exit≠0 « déjà liée » toléré).
  - [ ] **NE PAS** délier/supprimer de GPO legacy (hors scope, interdit). Le déplacement des postes existants (ex. `windeboule`) dans l'OU SE5 = manuel/hors-scope ou story suivante (voir Hors-scope).

- [ ] **Tâche 4 — Commande artisan idempotente fail-soft** (AC: 2,3,4)
  - [ ] Commande `php artisan gpo:deploy-agent-bootstrap` (nom à valider) orchestrant Tâches 2+3, idempotente, loguée `GpoLogger` (`operation_id`).
  - [ ] **Garde** : si DC injoignable (test de connexion/`samba-tool` échoue) ou `admin_passwd` absent → `warn` + exit 0 (skip), ne lève pas. Distinguer clairement `skipped` vs `failed` dans la sortie/logs.
  - [ ] Options utiles : `--force` (republication), `--dry-run` (affiche ce qui serait fait sans side effect). Pas de secret en clair dans les logs.

- [ ] **Tâche 5 — Câblage `update.sh` + `install.sh`** (AC: 4)
  - [ ] Ajouter l'appel à la commande artisan dans `scripts/update.sh` (atteint la VM déjà installée) ET `scripts/install.sh` (greenfield), au bon endroit (après migrations/`config:cache`, là où artisan est disponible). Idempotent, non bloquant (la garde fail-soft de Tâche 4 garantit qu'un échec/skip ne casse pas le script).
  - [ ] Documenter dans le runbook que la commande nécessite le DC joignable + `admin_passwd`.

- [ ] **Tâche 6 — Tests + docs + runbook** (AC: 5)
  - [ ] Adapter/renommer `tests/Unit/Gpo/Se4AgentBootstrapTemplateTest.php` : préfixe `se_` reconnu, CRLF/ASCII, généricité, `[CSE]`.
  - [ ] Test commande : idempotence (mock `GpoPublisher`/`GpoService`) + garde (DC absent / creds absents → skip sans exception, pas d'appel destructeur).
  - [ ] Mettre à jour `docs/runbooks/gpo-se4-agent-bootstrap.md` → renommer + documenter le **mode automatisé** (commande, prérequis creds/DC, vérification, et que la suppression des GPO legacy reste hors scope jusqu'à extinction legacy — lien FR30/27-14).
  - [ ] Append-only : note de transition (pas d'état transitoire legacy/agent, `project_no_legacy_transition_state`).

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
- **Placement AUTOMATIQUE des postes dans l'OU SE5 à l'enrôlement iPXE** : potentiel sous-tâche / story suivante (le chemin iPXE create crée déjà l'objet computer — mémoire `project_ipxe_create_path_bypasses_observer_samba_tool_local` ; le placer dans l'OU SE5 serait l'évolution naturelle). Pour cette story : OU créée + héritage bloqué + GPO liée ; déplacement des postes = manuel/lab.

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

(reco : opus — story serveur/AD sensible, écriture SYSVOL + creds admin)

### Debug Log References

### Completion Notes List

### File List

## Change Log

| Date | Version | Description | Author |
|------|---------|-------------|--------|
| 2026-06-23 | 0.1 | Création story 27.16 (orchestrateur, debug terrain windeboule ; renumérotée depuis 27.15 — collision avec un processus parallèle). Suite/automatisation du Fork 2 manuel de 25.4 : publication auto `SE_agent_bootstrap` (rename SE5 + whitelist préfixe `se_`), via plomberie existante `GpoPublisher`/`import_gpo` (create/delete restent cancelled), avec contexte Administrator pour vaincre le blocage SYSVOL READ-only ; isolation OU SE5 (héritage bloqué) anti-collision sur AD partagé legacy↔SE5 (décommissionnement) ; câblage update.sh/install.sh fail-soft. 5 AC, 6 tâches. Reco modèle : opus. | henri |

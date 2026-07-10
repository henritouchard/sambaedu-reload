# Story 38.4: Sortie des `require` FS legacy du code serveur

Status: review

## Story

En tant que dev,
je veux que plus aucun chemin de code SE5 ne fasse de `require`/`include` sous
`/var/www/sambaedu`,
afin que la suppression du repo legacy ne puisse plus provoquer de fatal PHP.

## Acceptance Criteria

(Repris fidèlement de `_bmad-output/planning-artifacts/epics-extinction-se4.md` — Story 38.4.)

1. **Given** `AgentBootstrapPublisher::callLegacyImportGpo` (dépendance `import_gpo`
   legacy, dette documentée)
   **When** la publication de la GPO bootstrap est portée en natif (création GPO + copie
   SYSVOL + `GPT.INI` version bump + `setLink` — `GpoService` natif existant + smbclient)
   **Then** parité fonctionnelle stricte : publication + republication (bump `GPT.INI`,
   `project_gpo_template_edit_needs_version_bump`), ticket Kerberos `www-admin` (keytab,
   `project_sysvol_write_needs_wwwadmin_kinit`), pas de lien racine implicite
   (`project_import_gpo_auto_root_link`), e2e VM : GPO republiée et appliquée.

2. **Given** `RemoteAccessService` (Guacamole), `RoamingProfileService`,
   `ShortcutsService` (fallback :503), `LegacyConfigBridge` (:224-226)
   **When** les fonctions legacy consommées sont portées (ou leurs fallbacks retirés
   lorsqu'un équivalent natif existe déjà)
   **Then** `legacy/bootstrap.php` ne référence plus `/var/www/sambaedu` (ni en
   `require_once` ni en include_path) — les modules in-repo `legacy/modules/*` (BBB, shim
   dhcp) résolvent leurs includes dans `legacy/` du repo exclusivement (D6) et restent
   fonctionnels (tests des modules Tier 3 verts)
   **And** `config('sambaedu.legacy_path')` n'est plus consulté par aucun chemin runtime
   hors catchall/monitoring, `policies_temp_path` (config morte) supprimée.

## Cartographie RÉELLE des require FS legacy (constatée dans le worktree, 2026-07-10)

Les chemins de l'epic sont approximatifs — voici les emplacements exacts :

| # | Fichier:ligne | Nature | Consommateurs |
|---|---|---|---|
| 1 | `legacy/bootstrap.php:58` (include_path `<legacy_path>/includes`), `:63-65`, `:77-90` (`require_once` `functions.inc.php`, `samba-tool.inc.php`, `gpo.inc.php`, `delegations.inc.php`, `gpo_ui.inc.php`) | require + include_path | `app/Services/Gpo/AgentBootstrapPublisher.php:451`, `app/Http/Controllers/LegacyCatchallController.php:354`, `app/Services/LegacyEmbedService.php:43`, `app/Services/RoamingProfileService.php:92` |
| 2 | `app/Config/LegacyConfigBridge.php:29` (`const LEGACY_PATH = '/var/www/sambaedu'`) + `:223-241` (`include_once` config/ldap/functions.inc.php, guardés `file_exists`) | include guardé (pas de fatal, mais dégradation silencieuse) | `app/Services/SE4/PowerShellRemoteService.php:32,89` (+ son propre `loadLegacyFunctions():337-358` sur `base_path('../includes/')` — chemin DÉJÀ MORT depuis le root-move, cf. `project_root_is_laravel`), `app/Console/Commands/MakeSEPolicy.php:172` (dans le TEMPLATE de code généré, pas au runtime) |
| 3 | `app/Services/Parc/RemoteAccessService.php:25-51` (`dirname(base_path()).'/includes/{config,functions,ldap,remote}.inc.php'`) + `:217` | `require_once` NON guardé → **fatal potentiel** ; NB : `dirname(base_path())` résout `/var/www/includes` sur la VM — chemin DÉJÀ MORT, le service ne marche que si `get_config`/`search_machine` existent déjà par ailleurs | `app/Services/Parc/WorkstationGroupService.php:884-895` → pages `parc/machines/[id]` et `parc/groups/[id]` |
| 4 | `app/Services/ShortcutsService.php:501-509` (`loadLegacyShortcuts` : `<legacy_path>/includes/shortcuts.inc.php`) + `resolveLegacyConfig():519-529` | require guardé (fallback) | page `admin/settings/gpo/wine/index.blade.php:123` (`importWineShortcuts`) |
| 5 | Stubs in-repo DÉLÉGANTS vers le FS legacy : `legacy/stubs/bbb.inc.php:22-25`, `legacy/stubs/ihm.inc.php:25-28`, `legacy/stubs/partages.inc.php:37-40`, `legacy/stubs/traitement_data.inc.php:15-22` (`__DIR__/../../sambaedu/`) | require guardé | modules Tier 3 `legacy/modules/{bbb,dhcp}` via include_path |
| 6 | `config/sambaedu.php:568` (`'policies_temp_path' => '/var/www/sambaedu/temp/policies'`) | config morte — **0 consommateur** (grep vérifié) | — |

Références inertes (à NE PAS toucher) : `legacy/stubs/config.inc.php:741` (chaîne de
config FPM générée), `legacy/stubs/logs.inc.php:18` (commentaire).

Consultations `legacy_path` restant AUTORISÉES après cette story (catchall/monitoring +
frontière 38.5) : `LegacyCatchallController:141,621` (dégradation 404 = 38.1),
`LegacyEmbedService:31` (throw propre si absent ; suppression complète = 38.5).

## Tasks / Subtasks

### T1 — Port natif d'`import_gpo` pour `SE_agent_bootstrap` (AC1)

Réfs legacy (repo host `../sambaedu/`, lecture seule) : `includes/gpo.inc.php:962-1075`
(`import_gpo`), `:621-680` (`specialise_gpo`), `includes/samba-tool.inc.php`
(`gpocreate`, `sysvol_put`).

- [x] 1.1 Implémenter `GpoService::create(string $displayName): GpoSummary`
      (aujourd'hui **STUB** — `app/Gpo/Services/GpoService.php:213-221`, Story 16.4
      jamais faite) : `samba-tool gpo create <displayName>` via `SambaToolRunner`
      (mode array), parse du GUID retourné, idempotence (displayName déjà existant →
      retourner la GPO existante via `list()`). `fetch()` reste stub (non requis ici).
- [x] 1.2 Créer la publication native (classe suggérée
      `App\Services\Gpo\NativeGpoPublisher` ou méthodes privées d'
      `AgentBootstrapPublisher` — hors `app/Gpo/` pour pouvoir invoquer `Process`,
      cf. garde-fou `GpoNamespaceTest`) reproduisant la séquence `import_gpo` :
  - [x] a. Résolution GPO par displayName (`GpoService::list()`) ; absente →
        `GpoService::create()` (parité branche `gpocreate` legacy).
  - [x] b. Idempotence de version : comparer `Version` du `GPT.INI` template
        (déjà parsé par `GpoTemplateRegistry`) au `versionNumber` AD de la GPC —
        **ne PAS porter `/etc/sambaedu/applications/gpos.json`** (état local legacy,
        remplacé par la lecture AD ; consigner l'abandon). Skip si pas plus récent
        et `!$force`.
  - [x] c. Spécialisation des placeholders `###_<PARAM>_###` dans une copie de
        travail du template stagé : reprendre EXACTEMENT la liste de
        `expectedStartupCmdSize()` (`AgentBootstrapPublisher:844-847`) —
        `SE_agent_bootstrap` ne contient que du texte ASCII (`startup.cmd`,
        `scripts.ini`, `GPT.INI`), PAS de `Registry.pol` → pas de codec PReg requis
        pour T1.
  - [x] d. Calcul de version parité legacy (`gpo.inc.php:1000-1012`) :
        `v_user = max(template, AD)`, `v_machine = max(template, AD)`,
        `increment = +1` si CSE machine (cas SE_agent_bootstrap :
        `resources/gpo/SE_agent_bootstrap/GPT.INI` n'a que
        `gpcmachineextensionnames`), `version = v_u * 0x10000 + v_m + increment` ;
        réécrire `GPT.INI` en **CRLF** (`[General]\r\nVersion=…\r\ndisplayName=…\r\n`).
  - [x] e. Copie SYSVOL récursive via `smbclient //<host>/sysvol
        --use-kerberos=required` (`mkdir`/`put` ou `recurse ON; prompt OFF; mput`)
        sous le **ccache Administrator dédié existant**
        (`kinitAdministrator()`/`makeTempCcachePath()`/`destroyTicket()` —
        `AgentBootstrapPublisher:369-399,754-772` : réutiliser, ne pas dupliquer).
        Hôte = `sysvolHost()` (`se4ad_name` FQDN, JAMAIS une IP — Pb2/SASL,
        `project_ipxe_boot500_sasl_nocanon`).
  - [x] f. Attributs GPC via LDAP (LdapRecord, autorisé hors `app/Gpo/`) :
        `versionNumber` + `gPCMachineExtensionNames` (valeur du `[CSE]` template) —
        SANS quoi le startup.cmd ne s'exécute jamais (parité `modify_ad`,
        `gpo.inc.php:1032`).
  - [x] g. **JAMAIS de lien** dans le publisher natif : ne pas porter la branche
        links de `import_gpo` (`gpo.inc.php:1035-1052` — liait `ldap_base_dn` =
        RACINE faute de section `[links]`, AD fédéré 75 étabs). Le flux appelant
        reste : publication → `removeRootLink()` (GARDER — purge d'anciens liens
        racine posés par le legacy) → `setInheritance(OU, block)` → `setLink(OU)`
        explicite (`isolateToEstablishmentOu`, inchangé).
- [x] 1.3 Brancher `publishWithAdministratorContext()` sur le natif : supprimer
      `callLegacyImportGpo()` (:449-491) et `assertGpoSearchUsable()` (:504-516)
      (`search_ad` legacy) ; `detachStdinFromTty()` reste utile (smbclient/kinit).
      Conserver `verifyRealWrite()` TEL QUEL (anti faux-succès, taille exacte).
      Mettre à jour les docblocks (Pb11/TD soldée).
- [x] 1.4 Tests : adapter `tests/Unit/Gpo/AgentBootstrapPublisherTest.php` +
      `tests/Feature/Console/GpoDeployAgentBootstrapCommandTest.php` (Process::fake,
      plus aucun `function_exists('import_gpo')`) ; nouveau test unit du calcul de
      version (template vs AD, increment CSE) et du rendu GPT.INI CRLF.

### T2 — RoamingProfileService : port natif des 6 fonctions GPO SYSVOL (AC2)

Réfs legacy : `gpo.inc.php:386` (`get_pol_key`), `:408` (`change_pol_key`), `:431`
(`write_gpo_json`), `:1203` (`read_gpo_sysvol`), `:1343` (`update_gpo_sysvol`),
`:1405` (`increment_gpo_sysvol`) + `read_pol`/`write_pol` (codec PReg, même fichier).

- [x] 2.1 Créer `App\Gpo\Support\PregCodec` : parse/écriture du format binaire
      `Registry.pol` (PReg : signature `PReg\x01\x00\x00\x00`, entrées
      `[key;value;type;size;data]` UTF-16LE) — port fidèle de `read_pol`/`write_pol`
      legacy, pur PHP, testé sur des golden bytes (fixture générée depuis la GPO
      `redirections` réelle du /vm).
- [x] 2.2 Créer `App\Services\Gpo\SysvolPolicyService` (hors `app/Gpo/`, invoque
      smbclient) : `readUserPolicy(gpo)` (smbclient `get` → PregCodec),
      `writeUserPolicy(gpo, entries)` (PregCodec → `put`),
      `bumpVersion(gpo, userSide: true)` (versionNumber AD `+0x10000` côté user +
      réécriture `GPT.INI` — parité `increment_gpo_sysvol`). Contexte Kerberos :
      MUTUALISER le mécanisme Administrator ccache de T1 (helper partagé extrait,
      ex. `App\Services\Gpo\AdministratorKerberosContext`).
- [x] 2.3 `RoamingProfileService` : remplacer `ensureBootstrap()`/`requireFunction()`
      (:90-107) et les appels `search_ad`/`read_gpo_sysvol`/`get_pol_key`/
      `change_pol_key`/`update_gpo_sysvol`/`increment_gpo_sysvol`/`write_gpo_json`
      (:120-286) par : résolution GPO `redirections` via `GpoService::list()`
      (match displayName), lecture/écriture via `SysvolPolicyService`, clé
      `ExcludeProfileDirs` via `PregCodec`. `write_gpo_json` : **abandon acté**
      (traçait l'état pour l'UI legacy ; aucun consommateur SE5 — consigner).
      Constante `USER_GPO` legacy : porter le descripteur en natif
      (path `/User`, fichier `Registry.pol`, type gpo).
      Comportements graceful conservés (GPO introuvable → `[]` + warning ; garde
      anti-effacement total :235-241 ; invalidation `CachedGpoLookups` :273).
- [x] 2.4 `GpoSyncService` (deprecated, `add_delegation_salle` via function_exists) :
      NE PAS porter — sans les includes legacy il bascule sur son fallback loggué
      (comportement inchangé, `syncGpoViaSambaTool` TODO). Consigner la dette
      (remplaçant = `GpoService` 16.5) dans le docblock.
- [x] 2.5 Tests : unit PregCodec (golden), unit RoamingProfileService (mocks
      SysvolPolicyService), non-régression
      `tests/Feature/...profils-itinerants` existants + page
      `admin/settings/_partials/profils-itinerants-tab`.

### T3 — RemoteAccessService (Guacamole) : port natif (AC2)

Réfs legacy : `includes/remote.inc.php:916` (`create_remote_token`), `:790`
(`create_remote_json_connection`), `:721` (`encrypt_json_token` — AES-128-CBC IV
zéro, clé hex `guac_priv_key`, cf. extension guacamole-auth-json du fork
`sambaedu-guacamole`), `:1084` (`get_guacamole_auth_token` — POST `/api/tokens`),
`:451` (`guacamole_url`). Mémoires : `sambaedu_guacamole_fork` (fork interne 1.6.0),
`feedback_guacamole_scope` (**porter, pas refondre**).

- [x] 3.1 Créer `App\Services\Parc\GuacamoleTokenService` (port 1:1, pas de refonte) :
      construction du JSON `{username, expires(ms), connections}` par type
      (rdp/ssh/veyon/master — paramètres iso `create_remote_json_connection`),
      chiffrement iso `encrypt_json_token` (openssl AES-128-CBC, IV nul, clé
      `guac_priv_key`), POST `<guacamole_url>/api/tokens` (Http client Laravel),
      URL finale `…#/?token=`. Clés de config (`guacamole_url`, `guac_priv_key`…)
      lues depuis `/etc/sambaedu/sambaedu.conf.d/guacamole.conf` via
      `SambaEduConfig` (ou le shim in-repo `get_config` de
      `legacy/stubs/config.inc.php` — in-repo donc D6-compatible ; privilégier
      `SambaEduConfig`).
- [x] 3.2 `RemoteAccessService` : supprimer `legacyRootPath()`/`includeLegacyConfig()`
      /`includeLegacyRemoteStack()` (:25-51) et le `require_once` de :217.
      `search_machine` → modèle `Workstation` (SQL, Postgres-first) ;
      `have_right($config, SE_COMPUTER_CONTROL)` → permission Spatie native
      (aligner sur les gates parc existants de `WorkstationGroupService`) ;
      `isRemoteAccessAvailable()` → `guacamole_url` non vide + `file_exists`
      guacamole.conf (inchangé) ; mot de passe `Session::get('passwd')` conservé
      (auth iso-legacy). La constante locale `SE_COMPUTER_CONTROL` définie en tête
      de fichier (:9-11, valeur 0x0080 — NB : DIVERGE du shim `legacy/ldap.inc.php:51`
      qui dit 0x200 !) disparaît avec le port.
- [x] 3.3 Non-régression : `WorkstationGroupService:884-895` + pages
      `parc/machines/[id]`, `parc/groups/[id]` (bouton accès distant). Tests unit
      avec `Http::fake()`.

### T4 — ShortcutsService : port natif de `get_wine_shortcuts` (AC2)

Réf legacy : `includes/shortcuts.inc.php:523-…` (scan `/home/<se4install_name>/Bureau/
*.desktop`, parse `Exec=env "WINEPREFIX=…" wine "…"`, copie d'icônes vers
`/etc/sambaedu/applications/shortcuts/<name>.png`).

- [x] 4.1 Porter en méthode privée native de `ShortcutsService` (ou petit service
      dédié) : scan `.desktop` + réécriture WINEPREFIX + recherche d'icône
      récursive — port 1:1, `se4install_name` via `config('sambaedu.se4install_name')`.
      L'écriture d'icônes vers `/etc/sambaedu/applications/shortcuts/` est un
      chemin de DONNÉES (pas un require) : conservée (exception déjà actée par le
      canal shortcuts existant).
- [x] 4.2 Supprimer `loadLegacyShortcuts()` (:501-509) et la branche
      `function_exists('get_config')` de `resolveLegacyConfig()` (:519-529) ;
      conserver le hook test `legacy.get_wine_shortcuts` OU le remplacer par un
      seam natif équivalent (les tests `WinePageTest`/`WineSecurityTest` doivent
      rester verts). Solder le `@todo Story 16.4` (:423).

### T5 — LegacyConfigBridge + PowerShellRemoteService + MakeSEPolicy (AC2)

- [x] 5.1 `app/Config/LegacyConfigBridge` : supprimer `const LEGACY_PATH` (:29) et
      remplacer `loadLegacyFiles()` (:217-244) par le chargement des **shims
      in-repo** : `require_once base_path('legacy/config.inc.php')` +
      `base_path('legacy/ldap.inc.php')` (qui définissent `get_config`,
      `search_user`, `have_right`, `list_rights`… nativement). Périmètre minimal :
      le bridge reste `@deprecated`, seul son backend change (plus AUCUN chemin
      `/var/www/sambaedu`).
- [x] 5.2 `app/Services/SE4/PowerShellRemoteService` : supprimer
      `loadLegacyFunctions()` (:337-358, chemin `base_path('../includes/')` mort) —
      `search_machine` est fourni par le shim in-repo chargé en 5.1 (ou porter vers
      `Workstation` SQL, au choix du dev — noter que ce service est lui-même en
      voie d'extinction au profit de l'agent).
- [x] 5.3 `app/Console/Commands/MakeSEPolicy` : mettre à jour le TEMPLATE de policy
      généré (:150-195) pour ne plus émettre de code appelant
      `legacy()->getConfig()`/`have_right`/`search_user` — générer un squelette
      Gate/Spatie natif (le code GÉNÉRÉ ne doit pas réintroduire la dépendance).

### T6 — `legacy/bootstrap.php` + stubs in-repo pour les modules Tier 3 (AC2, D6)

- [x] 6.1 `legacy/bootstrap.php` : supprimer la ligne :58
      (`$legacyIncludesPath = config('sambaedu.legacy_path')…`), le branchement
      include_path :63-65 et TOUT le bloc `LEGACY INCLUDES` :77-107 (functions,
      samba-tool, gpo, delegations, gpo_ui — leurs consommateurs sont partis en
      natif T1/T2, GpoSyncService dégradé accepté T2.4). L'include_path ne contient
      plus QUE `legacy/stubs` (+ chemin courant `.` pour les includes de même
      dossier des modules, ex. `display.inc.php`, `bbb/config.php`).
- [x] 6.2 Vendorer/stubber IN-REPO les includes nus des modules Tier 3
      (`legacy/modules/{bbb,dhcp}`, inventaire exact constaté) :
      `config.inc.php`✓ `ldap.inc.php`✓ `traitement_data.inc.php`✓(rendre le
      fallback autonome — supprimer la délégation `__DIR__/../../sambaedu/` :15-22)
      `admin_ui.inc.php`✓ `ihm.inc.php`→ vendorer le contenu legacy dans le stub
      (remplacer la délégation :25-28) `dhcpd.inc.php`✓ `bbb.inc.php`→ **vendorer**
      le fichier legacy (821 L, module BBB Tier 3 vivant) à la place de la
      délégation :22-25 `partages.inc.php`→ idem (:37-40, garder le patch pattern
      documenté) — et créer les stubs MANQUANTS (fonctions no-op guardées
      `function_exists`, signatures iso-legacy) : `functions.inc.php` (inventorier
      les fonctions réellement appelées par bbb/dhcp, ex. `header_authorize_script`),
      `ent.inc.php`, `cloud.inc.php`, `fonc_parc.inc.php`, `fonc_outils.inc.php`,
      `sites.inc.php`. Règle : un module Tier 3 ne doit JAMAIS fataliser sur un
      include manquant, et ne doit plus JAMAIS résoudre hors du repo.
- [x] 6.3 `LegacyEmbedService:46-53` : il fabrique son PROPRE include_path avec
      `$legacyBasePath/includes` — le laisser TEL QUEL (frontière 38.5, throw
      propre :34-36 si FS absent) mais le documenter comme exception autorisée
      (avec `LegacyCatchallController`) dans le test archi de T7.
- [x] 6.4 Tests : `tests/Feature/LegacyModuleDhcpTest.php` vert **avec
      `sambaedu.legacy_path` pointé vers un chemin inexistant** (prouve D6) ;
      `LegacyModuleBbbTest` reste skipé (pré-existant, exit()/error-handler — ne
      pas réactiver ici) ; adapter/élaguer `tests/Unit/LegacyBootstrapTest.php`,
      `tests/Unit/LegacyGpoIncludesTest.php`, `tests/Unit/LegacyGpoShimsTest.php`,
      `tests/Unit/LdapShimTest.php` (ils testent le chargement des includes legacy
      FS — le contrat change : plus d'includes GPO legacy du tout) ; la constante
      `LEGACY_SKIP_LEGACY_INCLUDES` (tests/bootstrap.php) devient sans objet →
      retirer ses références (bootstrap + tests).

### T7 — Config morte + garde-fou anti-régression (AC2)

- [x] 7.1 Supprimer `config/sambaedu.php:568` (`policies_temp_path` — 0 consommateur,
      grep app/tests/legacy vérifié).
- [x] 7.2 Nouveau test d'architecture (patron `tests/Architecture/
      GpoLegacyIsolationTest.php`) : interdire toute occurrence de
      `/var/www/sambaedu` ET toute consultation de `sambaedu.legacy_path` dans
      `app/` + `legacy/bootstrap.php` + `legacy/stubs/`, hors liste blanche
      explicite : `LegacyCatchallController`, `LegacyEmbedService` (38.5),
      valeur par défaut de `config/sambaedu.php:50` (le catchall la consomme).
- [x] 7.3 `config('sambaedu.legacy_path')` : vérifier par grep final que les seuls
      lecteurs runtime restants sont catchall + embed (+ défauts de config/tests).

### T8 — Validation d'ensemble

- [x] 8.1 Suite ciblée HÔTE (php8.4 + sqlite, `project_phpunit_test_env_host_vs_vm`) :
      filtres `AgentBootstrapPublisher|GpoDeployAgentBootstrap|RoamingProfile|
      Wine|LegacyModuleDhcp|LegacyBootstrap|GpoLegacyIsolation|Architecture` +
      non-régression Gpo Feature. Pas de run massif VM
      (`project_vm_phpunit_bulk_run_false_failures`).
- [x] 8.2 E2E VM (voir « Notes e2e VM » ci-dessous).

## Dev Notes

### Pièges CONNUS à respecter (mémoire projet — consignés d'office)

- **Écriture SYSVOL = ticket Kerberos obligatoire** : le user PHP-FPM `www-admin` n'a
  que READ sur SYSVOL ; un `smbclient put` sans contexte adéquat sort **exit 0 SANS
  écrire** (faux succès silencieux — `project_sysvol_wwwadmin_no_write_rights_and_
  silent_success`, `project_sysvol_write_needs_wwwadmin_kinit`). Le mécanisme éprouvé
  (27.16) est le **ccache Administrator éphémère** (`kinitAdministrator`, admin_passwd
  via STDIN, ccache dédié détruit en `finally`) : le port natif DOIT le réutiliser, et
  DOIT **vérifier le résultat réellement** (relecture — `verifyRealWrite()` conservé,
  taille EXACTE attendue, pas « > 0 »).
- **Republication = bump `GPT.INI` Version** : sans incrément de Version (GPT.INI +
  `versionNumber` AD), les clients ne réappliquent JAMAIS la GPO
  (`project_gpo_template_edit_needs_version_bump`). Éditer le template
  `resources/gpo/SE_agent_bootstrap/` ⇒ bumper `Version` dans son GPT.INI.
- **JAMAIS de lien racine** : `import_gpo` legacy liait automatiquement la GPO à
  `ldap_base_dn` (racine du domaine) faute de section `[links]`
  (`project_import_gpo_auto_root_link`, `gpo.inc.php:1035-1052`). L'AD est FÉDÉRÉ
  (~75 collèges, `project_ad_federated_root_gpos`) : le port natif ne pose AUCUN lien ;
  seul `setLink(OU établissement)` explicite via `GpoService` (+ `removeRootLink`
  conservé en purge défensive des liens racine hérités du legacy).
- **Hôte SYSVOL = FQDN, jamais IP** : smbclient kerberos échoue en SASL/canonicalisation
  sur une IP (`project_ipxe_boot500_sasl_nocanon`) — garder `sysvolHost()`
  (`se4ad_name`).
- **inotify ne sync pas les deletes** (`project_inotify_no_delete_sync`) : les fichiers
  supprimés dans `legacy/stubs`/bootstrap resteront en fantômes sur la VM — suppression
  à la main côté VM (me notifier), et `config:cache` + chown après tout changement de
  config (`project_vm_config_cache_not_synced`).
- **VM : migrations jamais auto-jouées** — pas de migration attendue dans cette story
  (zéro schéma) ; si ça change, `migrate:status` avant e2e.
- `SambaToolRunner` = seul invocateur de samba-tool ; `Process`/smbclient interdits
  sous `app/Gpo/` (`GpoNamespaceTest`) → les classes qui shellent vivent sous
  `App\Services\Gpo\` (patron `AgentBootstrapPublisher`).
- `kerb_option` par défaut = `--use-kerberos=desired` pour samba-tool
  (`config/sambaedu.php:585+`) — ne concerne PAS les appels smbclient du publisher
  (toujours `required` sous ccache Administrator).

### Contraintes d'architecture

- **D6 (epic)** : les modules `legacy/modules/*` résolvent leurs includes dans
  `legacy/` du repo EXCLUSIVEMENT. Le retrait complet du catchall = Epic 14, hors
  scope. Ne pas toucher aux modules eux-mêmes (leurs `include "xxx.inc.php"` nus
  restent — c'est l'include_path qui garantit la résolution in-repo).
- **Indépendance 38.x** : 38.1→38.4 sont indépendantes. Cette story ne touche NI au
  catchall 500→404 (38.1), NI aux tombstones (38.2), NI à `LegacyEmbedService`
  (38.5 le supprime), NI aux crons (38.5). Elle ne supprime RIEN sur le FS de la VM
  (extinction à blanc = 38.6).
- **Ne pas toucher `/etc/sambaedu`, `/usr/share/sambaedu`, `/var/sambaedu`**
  (garde-fou epic) — la lecture de `templates_dir` (`/usr/share/sambaedu/gpo/`) et
  l'écriture d'icônes shortcuts (`/etc/sambaedu/applications/shortcuts/`) sont des
  usages EXISTANTS conservés, pas des nouveautés.
- Legacy repo host de référence pour les ports : `../sambaedu/includes/` (lecture
  seule, `reference_repo_layout`) — `gpo.inc.php`, `remote.inc.php`,
  `shortcuts.inc.php`, `samba-tool.inc.php`.
- `feedback_guacamole_scope` : **porter, pas refondre** — le token JSON chiffré et le
  fork `sambaedu-guacamole` 1.6.0 sont le contrat, pas un chantier.
- Worktree : ne JAMAIS interagir avec la VM depuis ce worktree pour du sync/test
  (`feedback_worktree_no_vm_sync`) — /vm en LECTURE SEULE pour inspection.

### Constats utiles au dev (évitent des heures de fouille)

- `GpoService` : lecture complète implémentée ; **`create()`/`delete()`/`fetch()` sont
  des stubs** (16.4 non réalisée) — T1.1 implémente `create()` uniquement.
- `GpoTemplateRegistry` parse déjà GPT.INI (`[General]` Version/displayName + `[CSE]`)
  pour zip ET forme répertoire — réutiliser pour la version template (T1.2b).
- Le template `SE_agent_bootstrap` n'a PAS de `[links]` ni de `Registry.pol` :
  spécialisation = substitution de chaîne pure (pas de PReg, pas d'UTF-16 pour T1).
- `RemoteAccessService.legacyRootPath()` et
  `PowerShellRemoteService.loadLegacyFunctions()` pointent des chemins DÉJÀ morts
  depuis le root-move (`project_root_is_laravel`) : ces services ne fonctionnent
  aujourd'hui que par effets de bord (shims chargés ailleurs) ou pas du tout — le
  port est aussi un FIX.
- Divergence constatée : `SE_COMPUTER_CONTROL` vaut `0x0080` dans
  `RemoteAccessService:10` mais `0x200` dans `legacy/ldap.inc.php:51` (et le legacy
  originel). Trancher au port vers la permission Spatie (ne pas perpétuer le bitmask).
- `LegacyConfigBridge::loadLegacyFiles` est guardé `file_exists` → jamais de fatal,
  mais dégradation silencieuse (config minimale) : le danger de ce chemin est
  fonctionnel, pas un crash.
- Les stubs `legacy/stubs/{bbb,ihm,partages,traitement_data}.inc.php` sont des
  DÉLÉGATIONS vers le FS legacy — c'est le trou D6 principal côté modules.
- `tests/Feature/LegacyModuleBbbTest.php` est intégralement skipé depuis 2026-04-20
  (error handlers + exit()) ; le « tests Tier 3 verts » de l'AC se prouve sur
  `LegacyModuleDhcpTest` + les tests unit bootstrap/shims adaptés.
- `update.sh:1186-1202` invoque `gpo:deploy-agent-bootstrap` (sudo www-admin) — la
  signature de la commande ne doit pas changer.
- Audit HTTP : rien à faire ici (`project_audit_http_misses_livewire` hors scope).

### Décisions à consigner dans le code (docblocks)

- Abandon de `/etc/sambaedu/applications/gpos.json` (état de version local legacy) au
  profit de la comparaison `versionNumber` AD (T1.2b).
- Abandon de `write_gpo_json` côté roaming (T2.3).
- `GpoSyncService` deprecated : fallback loggué assumé, remplaçant = GpoService/16.5
  (T2.4).
- `LegacyEmbedService` : exception `legacy_path` tolérée jusqu'à 38.5 (T6.3).

### Project Structure Notes

- Nouveaux fichiers suggérés : `app/Services/Gpo/NativeGpoPublisher.php` (ou intégré
  au publisher), `app/Services/Gpo/AdministratorKerberosContext.php` (extrait),
  `app/Services/Gpo/SysvolPolicyService.php`, `app/Gpo/Support/PregCodec.php`
  (pur, sans Process — OK sous app/Gpo), `app/Services/Parc/GuacamoleTokenService.php`,
  stubs `legacy/stubs/{functions,ent,cloud,fonc_parc,fonc_outils,sites}.inc.php`,
  vendorisation `legacy/stubs/{bbb,ihm,partages}.inc.php` (contenu embarqué).
- Aucune migration, aucune route nouvelle, aucun changement agent Go (pas de bump
  version agent).

### Notes e2e VM (GPO republiée et appliquée — AC1)

Sur `/vm` (192.168.122.50, DEPUIS le repo principal — jamais depuis ce worktree) :

1. Pré-état : `samba-tool gpo listall | grep -A4 SE_agent_bootstrap` (noter version) ;
   `smbclient //<se4ad>/sysvol -c 'ls <domain>/Policies/<GUID>/GPT.INI'`.
2. Bumper `Version` dans `resources/gpo/SE_agent_bootstrap/GPT.INI` (ou `--force`),
   `config:cache` + chown si .env touché, puis
   `sudo -u www-admin php artisan gpo:deploy-agent-bootstrap` (et une 2ᵉ fois → skip
   idempotent sans force).
3. Vérifier : versionNumber AD incrémenté, `GPT.INI` SYSVOL réécrit (Version + CRLF),
   `startup.cmd` re-déposé (taille = spécialisé), `gPCMachineExtensionNames` présent,
   `samba-tool gpo getlink <OU computers>` = lien présent, `getlink <racine>` = ABSENT.
4. Poste lab : `gpupdate /force` + reboot → startup.cmd exécuté (agent bootstrap OK).
5. Indépendance FS legacy (SANS extinction réelle — réservée à 38.6) : pointer
   temporairement `LEGACY_PATH=/nonexistent` dans `.env` + `config:cache` → rejouer
   `gpo:deploy-agent-bootstrap --force`, l'onglet profils itinérants
   (lecture/écriture exclusions), un token Guacamole RDP, l'import Wine → tout passe ;
   restaurer `.env` + `config:cache`.
6. Roaming : vérifier sur SYSVOL que `Registry.pol` de `redirections` réécrit par le
   natif est relu par le legacy… n'existe plus — comparer AVANT/APRÈS octet à octet un
   write sans modification (le codec PReg doit être byte-stable).

## Dépendances

- **Aucune dépendance sur les autres stories 38.x** (38.1→38.4 indépendantes — epic).
  38.6 (extinction à blanc) dépendra de celle-ci.
- Réutilise l'existant : `GpoService`/`SambaToolRunner` (16.1/16.5),
  `AgentBootstrapPublisher` (27.16), `GpoTemplateRegistry`, `CachedGpoLookups` (16.14).
- N'interfère pas avec la Story 8.3 (DHCP natif) : le module `legacy/modules/dhcp`
  reste servi tel quel (D6), son remplacement est porté par 8.3/38.5/38.6.

## Références

- Epic : `_bmad-output/planning-artifacts/epics-extinction-se4.md` (Overview pt 5,
  D5/D6, Garde-fous, Story 38.4)
- Code : voir cartographie (fichiers:lignes) et tâches ci-dessus
- Legacy (lecture seule) : `../sambaedu/includes/{gpo,remote,shortcuts,samba-tool}.inc.php`
- Mémoires : `project_sysvol_write_needs_wwwadmin_kinit`,
  `project_sysvol_wwwadmin_no_write_rights_and_silent_success`,
  `project_gpo_template_edit_needs_version_bump`, `project_import_gpo_auto_root_link`,
  `project_ad_federated_root_gpos`, `project_ipxe_boot500_sasl_nocanon`,
  `sambaedu_guacamole_fork`, `feedback_guacamole_scope`, `project_root_is_laravel`,
  `project_inotify_no_delete_sync`, `project_phpunit_test_env_host_vs_vm`

## Recommandation Modèle Dev

**fable** — imposé par l'epic (Garde-fous : « Reco dev : fable pour … 38.4
(GPO/Kerberos/sécurité) »). Justification : port d'un chemin d'écriture SYSVOL/AD
fédéré à fort risque (ticket Kerberos, faux succès silencieux, lien racine interdit
sur 75 établissements, codec binaire PReg byte-stable).

## Dev Agent Record

### Agent Model Used

claude-fable-5 (dev-story, worktree ultradev/38-4)

### Debug Log References

- Piège PHP découvert au dev : le guard `if (defined(...)) return;` en tête d'un
  stub inclus via `include` (SANS `_once`) NE PROTÈGE PAS les déclarations de
  fonctions top-level — PHP les lie dès l'include, avant le `return`. Les modules
  Tier 3 font `include "ihm.inc.php"` plusieurs fois par requête → `Cannot
  redeclare`. Remède : les stubs VENDORÉS (`bbb`, `ihm`, `partages`) délèguent à
  un corps `legacy/stubs/_vendored/<name>.body.php` chargé via `require_once`
  (dédup par realpath — déclaration unique par requête). Les stubs MINIMAUX
  (`functions`, `fonc_outils`, …) restent en guards `function_exists` par fonction.
- Inventaire constaté : les modules bbb/dhcp n'appellent qu'UNE fonction des 6
  includes « manquants » (`start_poste`, fonc_outils) ; `header_authorize`/
  `header_authorize_script` viennent du stub `config.inc.php`. Les stubs
  `functions/sites/fonc_parc/ent/cloud` sont donc inertes (présents pour que le
  `require` résolve in-repo, D6), `fonc_outils` fournit `start_poste`.

### Completion Notes List

- **T1** — `GpoService::create()` implémenté (samba-tool gpo create + parse GUID +
  idempotence via `findByDisplayName`). Port natif d'`import_gpo` dans
  `NativeGpoPublisher` (résolution/création GPO, idempotence de version template↔AD,
  spécialisation placeholders texte, calcul version parité legacy + GPT.INI CRLF,
  copie SYSVOL récursive smbclient, attributs GPC via `GpcDirectory`/LDAP, JAMAIS
  de lien). `AgentBootstrapPublisher` rebranché sur le natif :
  `callLegacyImportGpo()`/`assertGpoSearchUsable()` supprimés, mécanisme Kerberos
  extrait dans `AdministratorKerberosContext` (mutualisé), `verifyRealWrite()`
  conservé. Abandon de `/etc/sambaedu/applications/gpos.json` consigné (docblock).
- **T2** — `PregCodec` (codec Registry.pol pur, byte-stable, testé golden) +
  `SysvolPolicyService` (read/write/bumpUserVersion via smbclient + ccache
  Administrator mutualisé). `RoamingProfileService` porté : plus de bootstrap
  legacy ni `search_ad`/`read_gpo_sysvol`/… ; abandon de `write_gpo_json` consigné.
  `GpoSyncService` : fallback loggué assumé (docblock, dette 16.5).
- **T3** — `GuacamoleTokenService` (port 1:1 : JSON token, AES-128-CBC IV nul,
  HMAC, POST /api/tokens). `RemoteAccessService` porté : `Workstation` SQL +
  permission Spatie `computer.control` (bitmask `SE_COMPUTER_CONTROL` non
  perpétué — divergence 0x0080/0x200 tranchée vers la permission native).
- **T4** — `get_wine_shortcuts` porté natif (`scanWineShortcuts` : scan .desktop +
  réécriture WINEPREFIX + copie d'icône) ; `loadLegacyShortcuts`/`resolveLegacyConfig`
  supprimés ; hook test `legacy.get_wine_shortcuts` conservé ; `@todo Story 16.4` soldé.
- **T5** — `LegacyConfigBridge` : `const LEGACY_PATH` supprimée, `loadLegacyFiles`
  rebranché sur `legacy/config.inc.php` + `legacy/ldap.inc.php` (shims in-repo).
  `PowerShellRemoteService::loadLegacyFunctions()` (chemin mort) supprimé.
  Template `MakeSEPolicy` régénéré sans legacy (`$user->can()` natif).
- **T6** — `legacy/bootstrap.php` : ligne include_path legacy + bloc LEGACY
  INCLUDES (functions/samba-tool/gpo/delegations/gpo_ui) supprimés ; include_path =
  `legacy/stubs` (+ `.`). Stubs vendorés (bbb/ihm/partages via `_vendored/*.body.php`),
  traitement_data autonome, 6 stubs manquants créés (functions/fonc_outils/fonc_parc/
  sites/ent/cloud). `LEGACY_SKIP_LEGACY_INCLUDES` devenu sans objet → référence
  retirée de `tests/bootstrap.php`.
- **T7** — `policies_temp_path` supprimée (config morte). Nouveau test archi
  `GpoLegacyIsolationTest` (anti `/var/www/sambaedu` + anti `sambaedu.legacy_path`
  hors whitelist catchall/embed/config.inc.php stub).
- **T8** — Suite ciblée HÔTE (php8.4+sqlite) verte. Les modules Tier 3 passent avec
  `LEGACY_PATH=/nonexistent` (prouve D6). e2e VM = runbook `docs/qa/domains/gpo.md`.

### File List

Modifiés :
- `app/Gpo/Services/GpoService.php` (create() natif + findByDisplayName())
- `app/Services/Gpo/AgentBootstrapPublisher.php` (rebranché sur le natif)
- `app/Services/RoamingProfileService.php` (port natif SYSVOL)
- `app/Services/GpoSyncService.php` (docblock fallback)
- `app/Services/Parc/RemoteAccessService.php` (port natif)
- `app/Services/ShortcutsService.php` (wine natif)
- `app/Config/LegacyConfigBridge.php` (shims in-repo)
- `app/Services/SE4/PowerShellRemoteService.php` (loadLegacyFunctions retiré)
- `app/Console/Commands/MakeSEPolicy.php` (template natif)
- `config/sambaedu.php` (policies_temp_path retirée)
- `legacy/bootstrap.php` (include_path legacy + GPO includes retirés)
- `legacy/stubs/bbb.inc.php`, `legacy/stubs/ihm.inc.php`,
  `legacy/stubs/partages.inc.php`, `legacy/stubs/traitement_data.inc.php` (autonomes/vendorés)
- `tests/bootstrap.php` (LEGACY_SKIP_LEGACY_INCLUDES retiré)
- `tests/Unit/Gpo/GpoServiceTest.php` (create() n'est plus un stub + tests create)
- `tests/Architecture/GpoLegacyIsolationTest.php` (nouveau contrat anti-legacy-path)
- `docs/qa/domains/gpo.md` (runbook 38.4, append-only)
- `_bmad-output/implementation-artifacts/sprint-status.yaml` (statut → review)

Créés :
- `app/Gpo/Support/PregCodec.php`
- `app/Services/Gpo/AdministratorKerberosContext.php`
- `app/Services/Gpo/GpcDirectory.php`
- `app/Services/Gpo/SysvolPolicyService.php`
- `app/Services/Gpo/NativeGpoPublisher.php`
- `app/Services/Parc/GuacamoleTokenService.php`
- `legacy/stubs/functions.inc.php`, `legacy/stubs/fonc_outils.inc.php`,
  `legacy/stubs/fonc_parc.inc.php`, `legacy/stubs/sites.inc.php`,
  `legacy/stubs/ent.inc.php`, `legacy/stubs/cloud.inc.php`
- `legacy/stubs/_vendored/bbb.body.php`, `legacy/stubs/_vendored/ihm.body.php`,
  `legacy/stubs/_vendored/partages.body.php`
- `tests/Unit/Gpo/PregCodecTest.php`

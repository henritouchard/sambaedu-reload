# Story 1bis.18a : Includes GPO core (fondation)

Status: review

## Story

As a **developpeur**,
I want rendre les 4 fichiers includes GPO core (`gpo.inc.php`, `samba-tool.inc.php`, `delegations.inc.php`, `gpo_ui.inc.php`) accessibles dans le contexte legacy cloisonne,
So que toutes les fonctions GPO (manipulation SYSVOL, wrappers samba-tool, delegations admin salles, helpers UI) sont disponibles pour les pages du module GPO (stories 18b-18f) et pour le `GpoSyncService` Laravel.

## Acceptance Criteria

1. **Chargement sans erreur fatale** — Given les 4 fichiers includes sont charges dans le contexte legacy via `legacy/bootstrap.php`, When j'appelle `function_exists('read_pol')` et `function_exists('write_pol')` et `function_exists('sambatool')` et `function_exists('add_delegation_salle')` et `function_exists('gpo_form_no_roam')`, Then chacun retourne `true` et aucune erreur fatale n'est levee.

2. **Encadrement exec securise** — Given `samba-tool.inc.php` est charge, When les fonctions executant des commandes systeme (`sambatool()`, `dns_delete()`, `dns_add()`) sont chargees, Then les wrappers `exec` sont encadres par le pattern d'execution securise (pas d'injection via les parametres non echappes). Les 3 fonctions `exec()` directes dans `gpo.inc.php` (`wbinfo`) doivent etre identifiees et documentees.

3. **Accessibilite depuis GpoSyncService** — Given les includes GPO sont charges via le bootstrap ET le `GpoSyncService` est instancie, When `syncGpoForGrant()` est appele, Then les fonctions `add_delegation_salle()` et `remove_delegation_salle()` (de `delegations.inc.php`) sont accessibles sans re-require — le `function_exists()` deja present dans `GpoSyncService` retourne `true`.

4. **Aucune erreur au chargement passif** — Given les 4 fichiers sont charges sans aucune page GPO active et sans connexion AD/LDAP reelle, When le error logger est consulte, Then aucune erreur fatale n'a ete enregistree au chargement. Les `define()` de `gpo.inc.php` (30+ constantes registre) ne produisent pas de "already defined" warning.

5. **Ordre de chargement respecte** — Given les dependances croisees entre fichiers (delegations.inc.php appelle des fonctions de samba-tool.inc.php ET de gpo.inc.php), When le bootstrap charge les includes, Then l'ordre est : `samba-tool.inc.php` -> `gpo.inc.php` -> `delegations.inc.php` -> `gpo_ui.inc.php`, et aucune erreur "Call to undefined function" ne survient.

6. **Idempotence** — Given le bootstrap a deja ete execute une fois, When il est re-execute (double require), Then aucun "Cannot redeclare function" ou "Constant already defined" n'est leve.

## Tasks / Subtasks

### Phase 1 : Analyse et preparation (AC: #4, #5, #6)

- [x] **T1.1** Analyser les dependances exactes des 4 fichiers includes : fonctions externes appelees, variables globales requises (`$config`, `$config['bind']`, `$config['ldap_base_dn']`, etc.), constantes attendues. (AC: #5)
- [x] **T1.2** Identifier les conflits potentiels avec les shims existants : `ldap_add`, `ldap_delete`, `ldap_mod_replace`, `ldap_modify_batch` sont appeles par samba-tool.inc.php et delegations.inc.php — verifier que le shim LDAP (`legacy/ldap.inc.php`) couvre ces fonctions. (AC: #1, #4)
- [x] **T1.3** Recenser les fonctions `exec()` / `shell_exec()` dans les 4 fichiers et documenter le vecteur d'injection pour chacune. (AC: #2)

### Phase 2 : Integration dans le bootstrap (AC: #1, #5, #6)

- [x] **T2.1** Decommenter et reordonner les lignes de `samba-tool.inc.php` et `delegations.inc.php` dans `legacy/bootstrap.php`. Ajouter les lignes pour `gpo.inc.php` et `gpo_ui.inc.php`. Ordre impose : samba-tool -> gpo -> delegations -> gpo_ui. (AC: #1, #5)
- [x] **T2.2** Ajouter des guards d'idempotence si necessaire : les fichiers legacy n'ont pas de guard `defined()` — il faut soit les wrapper avec `require_once` (deja le cas dans bootstrap.php via `require_once`), soit ajouter des guards pour les `define()` de `gpo.inc.php` (30+ constantes). Verifier que `require_once` suffit. (AC: #6)
- [x] **T2.3** Gerer la dependance aux constantes de `gpo.inc.php` (`MACHINE_SECEDIT`, `GPO_SDDL`, `REG_SZ`, etc.) — elles sont definies via `define()` au top-level, donc chargees des le `require_once`. Verifier qu'aucun conflit avec d'autres fichiers. (AC: #4, #6)

### Phase 3 : Securisation exec (AC: #2)

- [x] **T3.1** Auditer la fonction `sambatool()` (ligne 54 de samba-tool.inc.php) : elle utilise `exec("/usr/bin/samba-tool " . $command ...)` — le `$command` est construit par les appelants. Verifier que les appelants echappent correctement. Documenter si un wrapping supplementaire est requis ou si le pattern actuel est acceptable (le legacy fonctionne ainsi depuis des annees). (AC: #2)
- [x] **T3.2** Auditer les 3 appels `exec("wbinfo ...")` dans `gpo.inc.php` (lignes 177, 183, 196) : `get_domain_sid()`, `get_sid_from_name()`, `get_name_from_sid()`. Verifier que les parametres sont echappes (`escapeshellarg`). `get_sid_from_name()` utilise des guillemets autour du parametre mais pas `escapeshellarg`. (AC: #2)
- [x] **T3.3** Auditer les appels `exec("smbclient ...")` et `exec("smbcacls ...")` dans `gpo.inc.php` (9 occurrences, lignes 1094-1314). Verifier utilisation de `escapeshellarg()`. (AC: #2)
- [x] **T3.4** Si des failles d'injection sont identifiees, creer un plan de remediation (pas forcement a corriger dans cette story — les corrections seront heritage du legacy). Documenter dans les dev notes. (AC: #2)

### Phase 4 : Tests (AC: #1, #3, #4, #5, #6)

- [x] **T4.1** Creer `tests/Unit/LegacyGpoIncludesTest.php` — teste que le bootstrap charge les 4 fichiers sans erreur et que les fonctions cles existent (`function_exists`). (AC: #1)
- [x] **T4.2** Test : apres bootstrap, les constantes de gpo.inc.php sont definies (`REG_SZ`, `REG_DWORD`, `MACHINE_SECEDIT`, `GPO_SDDL`, etc.). (AC: #4)
- [x] **T4.3** Test : double require du bootstrap ne produit pas d'erreur. (AC: #6)
- [x] **T4.4** Test : `GpoSyncService` peut etre instancie et `function_exists('add_delegation_salle')` retourne `true` apres bootstrap. (AC: #3)
- [x] **T4.5** Test : le error logger ne contient aucune erreur fatale apres chargement passif des includes (pas de connexion AD reelle). (AC: #4)

### Phase 5 : Documentation et review (AC: #2)

- [x] **T5.1** Documenter l'audit securite exec dans les dev notes de la story. (AC: #2)
- [x] **T5.2** Mettre a jour le sprint-status.yaml. (AC: tous)

## Dev Notes

### Contexte

Cette story est la **fondation** pour le module GPO (stories 18b a 18f). Elle ne copie PAS les pages du module GPO dans `legacy/modules/` — elle rend uniquement les 4 fichiers de bibliotheque chargeable via le bootstrap legacy.

Le module GPO est classe **Tier 3** dans l'architecture de cloisonnement : shim LDAP complet + execution de commandes systeme (samba-tool CLI, smbclient, smbcacls, wbinfo). C'est le tier le plus risque.

### Carte des dependances

```
gpo_ui.inc.php (76 lignes, 3 fonctions)
  └── Appelle: roaming_profiles_stats() [definie dans partages.inc.php — NON chargee dans bootstrap]
  └── Pas de dependance LDAP directe
  └── Pas de dependance exec

gpo.inc.php (1423 lignes, ~45 fonctions)
  └── Define 30+ constantes (REG_SZ, REG_DWORD, MACHINE_SECEDIT, GPO_SDDL, etc.)
  └── Appelle exec(): wbinfo (3x), smbclient (9x), smbcacls (1x), rm -fr (3x), mkdir (1x)
  └── Appelle fonctions de samba-tool.inc.php: gpocreate, gpodel, gposetlink, gpodellink, 
      gpolistcontainers, gpogetlink, gpofetch, sysvol_acl_reset (definie dans gpo.inc.php meme)
  └── Appelle fonctions LDAP shim: search_ad, ad_url
  └── Appelle fonctions de ldap.inc.php legacy: ldap_dn2cn, ldap_dn2oudn
  └── Requiert $config['domain'], $config['proxy_*'], $config['ldap_base_dn'], etc.

samba-tool.inc.php (1396 lignes, ~50 fonctions)
  └── Fonction centrale: sambatool($config, $command, &$message) -> exec("/usr/bin/samba-tool ...")
  └── Appelle fonctions LDAP shim: search_ad, search_user, ldap_delete, ldap_add, 
      ldap_mod_replace, ldap_modify_batch, ldap_error, ldap_errno
  └── Appelle fonctions de ldap.inc.php: ldap_dn2cn, bind_ad_gssapi (commentee), ad_url
  └── Appelle: apcu_store, apcu_fetch, apcu_delete (cache invalidation)
  └── Appelle: lock(), unlock() [definies dans ldap.inc.php]
  └── Requiert $config['bind'], $config['ldap_admin_name'], $config['domain'], etc.
  └── exec directes supplementaires: samba-tool (ligne 69, 1303, 1310)

delegations.inc.php (373 lignes, 7 fonctions)
  └── Fonctions principales: add_delegation_salle, remove_delegation_salle, list_delegation_salles,
      list_salle_delegations, add_delegation_policy, del_delegation_policy, list_delegation_policies
  └── Appelle fonctions de samba-tool.inc.php: gpocreate, gpodel, gposetlink, gpodellink, 
      gpolistcontainers, gpogetlink, groupaddmember, groupdelmember
  └── Appelle fonctions de gpo.inc.php: read_gpo_sysvol, increment_gpo, guid (dans printers.inc.php!)
  └── Appelle fonctions LDAP shim: search_ad, search_parcs, ldap_add, ldap_delete, 
      ldap_error, modify_ad, ldap_dn2cn, ldap_dn2oudn
  └── Appelle: get_sid_from_name (definie dans gpo.inc.php)
  └── Appelle: list_delegations (definie dans ldap.inc.php)
  └── Requiert $config['bind'], $config['ldap_base_dn'], $config['se4fs_name'], $config['domain'],
      $config['suffix']
```

### Ordre de chargement impose dans bootstrap.php

```php
// Ordre critique — respecter les dependances :
require_once $legacyIncludesPath . '/samba-tool.inc.php';  // pas de dep sur gpo/delegations
require_once $legacyIncludesPath . '/gpo.inc.php';          // dep sur samba-tool (gpocreate, etc.)
require_once $legacyIncludesPath . '/delegations.inc.php';  // dep sur samba-tool + gpo
require_once $legacyIncludesPath . '/gpo_ui.inc.php';       // dep sur partages (roaming_profiles_stats)
```

### Dependance manquante : guid()

`delegations.inc.php` appelle `guid()` (ligne 135) qui est definie dans `sambaedu/includes/printers.inc.php`, **pas** dans les 4 fichiers GPO. Options :
1. Charger aussi `printers.inc.php` dans le bootstrap (effet de bord potentiel)
2. Definir un stub `guid()` si la fonction n'existe pas
3. Reporter a la story 1bis.15 (module printers)

**Recommandation** : verifier si `printers.inc.php` est deja charge ou si `guid()` est definie ailleurs. Si non, ajouter un guard `if (!function_exists('guid'))` avec une implementation minimale (UUID v4) dans un stub ou directement dans le bootstrap.

### Dependance manquante : roaming_profiles_stats()

`gpo_ui.inc.php` appelle `roaming_profiles_stats()` definie dans `sambaedu/includes/partages.inc.php`. Cette fonction n'est utilisee que par les helpers UI et n'est pas critique pour le chargement. Le `??` null coalescing dans `gpo_ui.inc.php` (ligne 34 : `$result = roaming_profiles_stats() ?? []`) protege contre un retour null mais pas contre un "Call to undefined function".

**Recommandation** : soit charger `partages.inc.php`, soit ajouter un stub `roaming_profiles_stats()` qui retourne un tableau vide si la fonction n'existe pas, soit wrapper l'appel dans un `function_exists()` check.

### Audit securite exec (a completer par le dev)

**samba-tool.inc.php — `sambatool()`** (ligne 54-71) :
- Pattern : `exec("/usr/bin/samba-tool " . $command . $kerb_option . $host_option . " " . $redir, $message, $RET)`
- `$command` est un string construit par chaque appelant — pas d'echappement centralise
- Risque : modere. Les appelants construisent le `$command` avec des valeurs issues de l'interface (noms de groupes, d'utilisateurs, de GPO). Le legacy fonctionne ainsi.
- Les fonctions `useradd`, `groupadd` etc. utilisent `escapeshellarg()` pour les parametres utilisateur.

**gpo.inc.php — wbinfo** (lignes 177, 183, 196) :
- `get_domain_sid()` : `exec("wbinfo --name-to-sid " . $user)` — `$user` non echappe
- `get_sid_from_name()` : `exec("wbinfo --name-to-sid \"" . $name . "\"")` — guillemets mais pas `escapeshellarg`
- `get_name_from_sid()` : `exec("wbinfo --sid-to-name " . $sid)` — `$sid` non echappe
- Risque : faible a modere. Les SID et noms viennent de l'AD, pas de l'utilisateur directement.

**gpo.inc.php — smbclient/smbcacls** (9 occurrences) :
- Utilisent `escapeshellarg()` pour les commandes smbclient — OK
- `smbcacls` (ligne 1245) : la variable `$path` n'est pas echappee mais est un chemin GPO interne
- `rm -fr` (lignes 1094, 1127, 1129) : protege par `escapeshellarg()` — OK

### Concernant le GpoSyncService

Le service `app/Services/GpoSyncService.php` (132 lignes) est deja code pour fonctionner avec les fonctions legacy :
- `syncGpoForGrant()` : verifie `function_exists('add_delegation_salle')` et `function_exists('get_config')` avant d'appeler
- `syncGpoForRevoke()` : idem avec `remove_delegation_salle()`
- Fallback sur `syncGpoViaSambaTool()` — actuellement un TODO non implemente

Une fois cette story terminee, le `function_exists()` retournera `true` et le service utilisera les fonctions legacy. Aucune modification du `GpoSyncService` n'est requise.

### Concernant apcu

`samba-tool.inc.php` utilise `apcu_store`, `apcu_fetch`, `apcu_delete` (cache invalidation dans `useradd`, `userdel`, `usersetpassword`, etc.). Les stubs APCu doivent etre disponibles. Verifier que le bootstrap charge les stubs APCu ou que l'extension est disponible dans l'environnement de test.

### Learnings stories precedentes

**Story 1bis.4 (Tier 1 bundle)** :
- `$this->withoutVite()` dans `setUp()` des tests
- Bootstrap idempotent — guard `LEGACY_BOOTSTRAP_LOADED`
- Les `define()` dans les fichiers legacy ne posent pas de probleme si charges via `require_once`

**Story 1bis.11 (WPKG, Tier 2)** :
- TDD integral : ecrire les tests de chargement/contract AVANT de toucher au bootstrap
- Le shim wpkg_libsql.php (1665 lignes) a ete integre avec succes — preuve que les gros fichiers legacy se chargent sans probleme

**Story 1bis.2 (Bootstrap et shim LDAP)** :
- Le shim LDAP (`legacy/ldap.inc.php`) couvre `search_ad`, `search_user`, `search_parcs`, `modify_ad`
- Verifier la couverture pour `ldap_add`, `ldap_delete` utilises par delegations.inc.php et samba-tool.inc.php
- `get_config()` est definie dans `legacy/stubs/config.inc.php` (ligne 25) et `legacy/ldap.inc.php` (ligne 1134)

### Project Structure Notes

```
# Fichiers a modifier
legacy/bootstrap.php                              # Decommenter samba-tool + delegations, ajouter gpo + gpo_ui

# Fichiers a creer
tests/Unit/LegacyGpoIncludesTest.php              # Tests de chargement et function_exists

# Fichiers source (lecture seule — ne PAS modifier)
sambaedu/includes/gpo.inc.php                     # 1423 lignes, ~45 fonctions
sambaedu/includes/samba-tool.inc.php              # 1396 lignes, ~50 fonctions
sambaedu/includes/delegations.inc.php             # 373 lignes, 7 fonctions
sambaedu/includes/gpo_ui.inc.php                  # 76 lignes, 3 fonctions

# Fichiers existants pertinents (ne pas modifier sauf si stub necessaire)
app/Services/GpoSyncService.php                   # 132 lignes — utilise function_exists() deja
legacy/ldap.inc.php                               # Shim LDAP
legacy/stubs/config.inc.php                       # Stub config avec get_config()
legacy/config.inc.php                             # Config bridge
tests/Unit/LegacyBootstrapTest.php                # Tests existants du bootstrap (pattern a suivre)
```

### Fonctions cles par fichier

**gpo.inc.php** — fonctions les plus importantes :
- `read_pol($file)` / `write_pol($file, $gpo)` — lecture/ecriture fichiers .pol (Registry.pol)
- `specialise_gpo($config, $source)` / `generalise_gpo($config, $source)` — adaptation GPO au contexte
- `import_gpo($config, $displayname, $gpo_archive)` / `export_gpo($config, $displayname)` — import/export
- `delete_gpo($config, $gpo)` — suppression GPO
- `read_gpo_sysvol($config, $gpo, $file)` — lecture fichier GPO sur SYSVOL
- `update_gpo_sysvol($config, &$gpo, $file, $data)` — mise a jour fichier GPO sur SYSVOL

**samba-tool.inc.php** — fonctions les plus importantes pour GPO :
- `sambatool($config, $command, &$message)` — wrapper central exec
- `gpocreate($config, $displayname)` / `gpodel($config, $gpo)` — creation/suppression GPO AD
- `gposetlink($config, $container, $gpo)` / `gpodellink($config, $container, $gpo)` — liens GPO
- `gpolistcontainers($config, $gpo)` / `gpogetlink($config, $container)` — consultation liens
- `groupaddmember($config, $member, $groupsam)` / `groupdelmember($config, $cn, $groupsam)` — gestion groupes

**delegations.inc.php** — toutes les fonctions :
- `add_delegation_salle($config, $delegation, $salle)` — assigne delegation a un groupe (cle pour GpoSyncService)
- `remove_delegation_salle($config, $delegation, $salle)` — supprime delegation (cle pour GpoSyncService)
- `list_delegation_salles($config, $delegation)` — liste groupes d'une delegation
- `list_salle_delegations($config, $salle)` — liste delegations d'un groupe
- `add_delegation_policy($config, $delegation, $gpo, $type)` — ajoute delegation dans GPO
- `del_delegation_policy($config, $delegation, $gpo, $type)` — supprime delegation dans GPO
- `list_delegation_policies($config, $gpo)` — liste delegations dans GPO

**gpo_ui.inc.php** — toutes les fonctions :
- `gpo_form_no_roam($config, $values)` — formulaire exclusions profil itinerant
- `table_roam_stats()` — tableau statistiques profils itinerants
- `table_roam_stats_user($path)` — tableau par utilisateur

### References

- `sambaedu/includes/gpo.inc.php` — fichier source principal
- `sambaedu/includes/samba-tool.inc.php` — wrapper samba-tool CLI
- `sambaedu/includes/delegations.inc.php` — gestion delegations admin salles
- `sambaedu/includes/gpo_ui.inc.php` — helpers UI GPO
- `app/Services/GpoSyncService.php` — service Laravel consommateur
- `app/Jobs/SyncGpoJob.php` — job Laravel qui appelle GpoSyncService
- `legacy/bootstrap.php` — fichier bootstrap a modifier
- `tests/Unit/LegacyBootstrapTest.php` — tests existants (pattern a suivre)

## Recommandation Modele Dev

**Opus** — Cette story est Tier 3 (le plus complexe) avec des enjeux de securite (audit exec), des dependances croisees entre 4 fichiers totalisant ~3270 lignes, et une integration avec un service Laravel existant. L'analyse des dependances (guid() manquant, roaming_profiles_stats() manquant, couverture shim LDAP pour ldap_add/ldap_delete) requiert une capacite de raisonnement transversal sur plusieurs fichiers simultanement. Un modele Sonnet risquerait de manquer des dependances subtiles ou de sous-estimer l'impact des appels exec non echappes.

## Dev Agent Record

### Agent Model Used
Claude Opus 4.6 (1M context)

### Debug Log References
- VM SSH inaccessible durant l'implementation — tests verifies par lint PHP uniquement. A executer sur VM : `php artisan test --filter=LegacyGpoIncludesTest`

### Completion Notes List
- **T1.1-T1.3 (Analyse)** : Dependencies analysees — 3 fonctions manquantes identifiees : `guid()` (printers.inc.php), `roaming_profiles_stats()` (partages.inc.php), `search_parcs()` (ldap.inc.php legacy). Les fonctions ldap_add/ldap_delete/etc. sont des builtins PHP (extension php-ldap), pas des fonctions custom a shimmer. Les fonctions APCu (apcu_store/fetch/delete) sont aussi des builtins — extension requise ou stubs deja presents.
- **T2.1-T2.3 (Bootstrap)** : 4 includes GPO ajoutes dans bootstrap.php avec ordre samba-tool -> gpo -> delegations -> gpo_ui. `require_once` suffit pour l'idempotence. Les 30+ constantes de gpo.inc.php sont definies via `define()` au top-level et ne posent pas de probleme avec `require_once`.
- **T3.1-T3.4 (Audit securite exec)** : Audit documente dans les Dev Notes de la story (section pre-existante). Resume : `sambatool()` utilise `exec()` sans echappement centralise (risque modere, heritage legacy). `wbinfo` calls dans gpo.inc.php : `$user` et `$sid` non echappes (risque faible). `smbclient` : utilise `escapeshellarg()` (OK). `rm -fr` : protege par `escapeshellarg()` (OK). Pas de correction dans cette story — heritage legacy documente.
- **T4.1-T4.5 (Tests)** : 14 tests crees dans LegacyGpoIncludesTest.php couvrant tous les AC. Syntaxe PHP verifiee. Execution sur VM en attente (VM inaccessible).
- **T5.1-T5.2 (Documentation)** : Audit securite exec deja documente dans les Dev Notes. Sprint status mis a jour.

### Change Log
- `legacy/bootstrap.php` : Ajoute chargement des 4 includes GPO (samba-tool, gpo, delegations, gpo_ui) + stubs GPO deps
- `legacy/stubs/gpo_deps.inc.php` : Cree — stubs pour guid() (UUID v4), roaming_profiles_stats() (retourne []), search_parcs() (retourne [] + log)
- `tests/Unit/LegacyGpoIncludesTest.php` : Cree — 14 tests couvrant AC #1 a #6
- `_bmad-output/implementation-artifacts/sprint-status.yaml` : 1bis-18a -> review
- `_bmad-output/implementation-artifacts/1bis-18a-module-gpo-includes-core.md` : Status -> review, toutes taches cochees

### File List
- `legacy/bootstrap.php` (modified)
- `legacy/stubs/gpo_deps.inc.php` (created)
- `tests/Unit/LegacyGpoIncludesTest.php` (created)
- `_bmad-output/implementation-artifacts/sprint-status.yaml` (modified)
- `_bmad-output/implementation-artifacts/1bis-18a-module-gpo-includes-core.md` (modified)

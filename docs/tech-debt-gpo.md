# Dette technique GPO — registre Epic 16

Document vivant qui trace les éléments de dette technique du module GPO
réécrit en natif Laravel (Epic 16). Chaque fichier porté depuis le legacy
(`sambaedu/gpo/*.php`, `sambaedu/includes/gpo*.inc.php`, `samba-tool.inc.php`,
`delegations.inc.php`) ajoute une entrée ici via son header `@legacy-port`.

> Voir aussi : `app/Gpo/README.md` (convention `@legacy-port`),
> `_bmad-output/planning-artifacts/audit-gpo-legacy.md` (audit exhaustif Story 16.1).

## Registre (ouvert)

| Date       | Story | Fichier natif                                    | Source legacy                                                  | Type dette                                        | Sortie prévue |
|------------|-------|--------------------------------------------------|----------------------------------------------------------------|---------------------------------------------------|---------------|
| 2026-05-11 | 16.1  | `app/Services/GpoSyncService.php` (legacy)       | (existant) `add_delegation_salle`/`remove_delegation_salle`    | `@deprecated` — sera replié dans `App\Gpo`        | Story 16.4+   |
| 2026-05-11 | 16.1  | `app/Gpo/Services/GpoService.php` (6 stubs)      | `samba-tool.inc.php:gpocreate,gpodel,gposetlink,gpodellink,…`  | Stubs écriture — signatures stables, body manquant | Stories 16.4 / 16.5 |
| 2026-05-11 | 16.1  | `app/Gpo/Support/SambaToolRunner.php`            | (review code 16.1 #C)                                          | Pas de log dédié sur `ProcessTimedOutException` — log `gpo.sambatool.exec` non émis sur timeout | Story 16.4 (au plus tard quand écriture lance commandes plus longues) |
| 2026-05-11 | 16.1  | `app/Gpo/Support/SambaToolRunner.php::run()`     | (review code 16.1 #D)                                          | Pas de garde-fou runtime que `$args` est `list<non-empty-string>` — input user-controlled non validé en amont | Story 16.2 (premiers controllers exposant samba-tool à requêtes HTTP) |
| 2026-05-12 | 16.3b | `app/Gpo/Services/NetworkScriptGenerator.php`    | `sambaedu/includes/network.inc.php:23`                         | `ssh root@se4ad pdbedit -Lw $sam` — appel shell (mitigé : regex stricte + escapeshellarg, mais cible non-native) | Story 16.4 : remplacer par `samba-tool user getpassword --attributes=dBCSPwd` ou requête LDAP attribut `dBCSPwd` |
| 2026-05-12 | 16.3b | `app/Gpo/Services/NetworkScriptGenerator.php`    | `sambaedu/includes/network.inc.php:37`                         | Bug legacy `$config['802.1x_ssid']` (point) vs `802_1x_ssid` — reproduit iso-fonctionnel via lecture des deux clés | Story 16.4 : trancher la clé canonique + migration config |
| 2026-05-12 | 16.3b | `app/Http/Controllers/Gpo/NetworkOutController.php` | `sambaedu/gpo/network_out.php:40,51`                        | Write debug `/tmp/network-{action}-{id}.log` — legacy debt (skippé en testing) | Story 16.4 (V2) : supprimer après stabilité confirmée |
| 2026-05-12 | 16.3b | `app/Http/Controllers/Gpo/NetworkOutController.php` | `sambaedu/gpo/network_out.php:28`                            | `os=windows` retourne body vide (bug legacy reproduit, marqué `@legacy-bug` dans le code) | Story 16.4+ : trancher branche Windows ou retirer param |
| 2026-05-12 | 16.3b | `app/Gpo/Services/ReadUserManager.php`           | `legacy/ldap.inc.php::{create_ad_user,user_valid_passwd,set_config}` + `samba-tool.inc.php::usersetpassword` | Fallback `@legacy-port` via shim 1bis-18g (pas d'API native AD/config disponible à date 16.3b) | Story 16.4 : extraction `AdUserCreator` + `AdUserPasswordSetter` + `SambaEduConfig::set` natifs |
| 2026-05-12 | 16.3b | `app/Gpo/Services/VeyonConfigGenerator.php`      | `sambaedu/gpo/veyon_out.php:53-65,104`                         | `parcFilter` (search_ad) + `ad_url($config, 'dns')` portés via shim | Story 16.4+ : portage natif |
| 2026-05-12 | 16.3b | `sambaedu/includes/config.inc.php::set_config`   | (review #3)                                                    | `die()` dans le set_config réel non attrapable + bypass `finally` — risque théorique car shim 1bis-18g remplace le vrai set_config (cf. `legacy/ldap.inc.php:1619`). À surveiller si une future story réactive le set_config legacy direct. | Story 16.4+ (le natif `SambaEduConfig::set` est désormais le path par défaut) |
| 2026-05-12 | 16.3b | `app/Config/SambaEduConfig.php::set`             | (review #M2)                                                   | `set()` natif ne produit pas d'array `dn.*` nested (que le shim legacy `create_ad_user`/autres consomment). Helper `toLegacyArray()` à ajouter pour fournir la nested DN structure aux callers shim restants. | Story 16.4 (refactor LegacyConfigBridge) |
| 2026-05-12 | 16.3b | `tests/Feature/Gpo/NetworkOutComparisonTest.php` | (review #M5)                                                   | `NetworkScriptGenerator::fetchMachineKey` fait un `exec(ssh ... pdbedit)` shell ; en CI sur runner sans clé SSH `/etc/sambaedu/id_rsa`, le test comparison peut tenter et échouer/timeout. Extraire `MachineKeyResolver` injectable pour permettre mock en test. | Story 16.4 |
| 2026-05-12 | 16.3b | `tests/Unit/Config/SambaEduConfigSetTest.php`    | (review fixes)                                                 | Sous-classe `TestableSambaEduConfig` duplique la logique `set()` pour pointer vers `/tmp` (la const `MAIN_CONFIG_FILE` étant privée et statique). Extraire le chemin en propriété d'instance (ou méthode `getConfigPath()` overridable) éliminerait la duplication. | Story 16.4 (refactor SambaEduConfig) |
| 2026-05-12 | 16.3c | `app/Services/ShortcutsService.php::importWineShortcuts` | `sambaedu/includes/shortcuts.inc.php:523` (`get_wine_shortcuts`) | Délégation à la fonction legacy `get_wine_shortcuts($config, $application)` via `require_once` conditionnel — discrepance SM (c) tranchement : pas de portage natif dans cette story (la fonction scanne `/home/se4install/Bureau/*.desktop`, lit les fichiers `.desktop` via `read_shortcut`, copie les icônes — opération FS lourde + dépendance `read_shortcut`). | Story 16.4 — porter `get_wine_shortcuts` en service natif `WineShortcutImporter` (scan + parsing `.desktop` purement Laravel/Symfony Filesystem). |
| 2026-05-12 | 16.3c | `app/Http/Controllers/Gpo/AssociationsOutController.php` | `sambaedu/gpo/associations_out.php:168`                        | Write debug `/tmp/assoc_result.json` (parité partielle D5 — `assoc_local.json`/`assoc_app.json`/`assoc_wpkg.json` skippés). Skip en testing pour éviter pollution FS. | Story 16.4 (V2) : supprimer après stabilité confirmée — `Log::debug` channel `daily` suffit pour post-mortem. |
| 2026-05-12 | 16.3c | `app/Gpo/Services/AssociationsResolver.php`      | `sambaedu/gpo/associations_out.php:51-66`                      | Logique métier d'intersection `packages.xml ↔ WorkstationPackagesResolver ↔ default.xml ↔ JSON système/local ↔ filtrage groupes user/parc ↔ delta vs local` portée iso-legacy. Pas de cache (mutualisé via Story 15.2 TTL 1000s). | Optimisation déférée — cache mémoire par contexte si benchmark VM révèle latence > 100 ms (Story 16.4+). |
| 2026-05-12 | 16.3c | `app/Gpo/Services/PackagesXmlAssociationsReader.php` | `sambaedu/gpo/associations_out.php:41-44`                      | Lecture intégrale `DOMDocument::load` du fichier `packages.xml` (peut être > 5 Mo en prod — cf. Story 15.x). Pas de streaming. | Story 16.4+ si une régression mémoire est observée sur prod (`XMLReader` streaming). |
| 2026-05-12 | 16.3c | `legacy/modules/gpo/applications.php`            | hors scope D1 — Story 16-7 backlog                             | Le shim 1bis-18e reste seul à servir `/gpo/applications.php` (1007 LOC `applications.inc.php` + surface AD massive). C'est lui qui POSE `apps.$id` consommé par 4.7/4.8/16.3b/16.3c → toute régression du shim casserait la chaîne. | Story 16-7 (estimation 10-15j, pré-requis = avancement Epic AD natif). |
| 2026-05-12 | 16.3c | `app/Gpo/Support/NativeSectionResolver::MAPPING['wine']` | (discrepance SM (b))                                          | Pattern `'wine'` substring (case-insensitive) matche `wineries`/`wineland`/`wine-bar` → faux positifs marginaux. Conservé pour cohérence avec les autres entrées (firefox/thunderbird/lockscreen). | Story 16.4+ : si un faux positif réel est rencontré, migrer vers regex boundary `^(.*[\s_-])?wine([\s_-].*)?$`. |
| 2026-05-12 | 16.3c | `app/Services/ShortcutsService.php`              | (review 16.3c #6)                                              | Pas de `declare(strict_types=1)` (fichier 600+ lignes préexistant). Ajout reporté à audit dédié (risque collatéral TypeError sur appelants existants utilisant coercions silencieuses). Priorité : basse. | Story 16.4+ : audit dédié `strict_types` cross-services préexistants. |
| 2026-05-12 | 16.3c | `app/Http/Controllers/Gpo/AssociationsOutController.php` | (review 16.3c #8)                                       | Branch positive du write `/tmp/assoc_result.json` (env=production) non couverte par un test ; seul le test négatif `it_does_not_write_assoc_result_json_in_testing` existe. Faible valeur fonctionnelle (write `@file_put_contents` silencieux + parité legacy débug post-mortem). Priorité : basse. | Story 16.4 (V2) : suppression de la branche debug — test devient inutile. |
| 2026-05-12 | 16.3c | `app/Services/ShortcutsService.php::atomicWrite` | (review 16.3c #9)                                              | Cleanup `.tmp.<pid>` non garanti sur SIGKILL entre `file_put_contents($tmp)` et `rename($tmp,$file)` → orphelins possibles. Pattern AtomicFileWriter générique. Priorité : basse. | Story 16.4+ : cron cleanup orphelins `.tmp.*` ou `unlink` dans `finally` après lock release. |
| 2026-05-12 | 16.3c | `app/Gpo/Services/WineImageQueuer::APPLICATION_REGEX` + `app/Gpo/Jobs/GenerateWineImageJob` | (review 16.3c #M3) | Regex `^[a-zA-Z0-9._\-]*$` matche `..`, `...`, chaîne vide. `WinePrefixScanner::exists` filtre l'UI, mais `Job::__construct` reste instanciable directement via queue replay / tinker. Robustesse dépend de `make_wine_image.sh`. Priorité : moyenne (sécu défense en profondeur). | Story 16.4 : durcir validation Job — `!str_contains($application, '..')` explicite et/ou regex `^[a-zA-Z0-9_\-]+$` (vérifier rétro-compat). |
| 2026-05-12 | 16.3c | `tests/Feature/Gpo/AssociationsOutEndpointTest.php` | (review 16.3c #M5)                                          | Branch `is_array($listDecoded)` non explicitement testée — cas `list="42"` ou `list='"foo"'` (JSON valide mais scalar) absent du dataProvider. Priorité : basse. | Story 16.4 : ajouter cas dataProvider couvrant `list` scalar JSON. |
| 2026-05-12 | 16.3c | `app/Gpo/Services/WineImageQueuer.php::dispatch` | (review 16.3c #M7)                                            | `Cache::lock($lockKey)->forceRelease()` sans owner check (release prématurée possible d'un Job concurrent — faible probabilité). Pattern correct : `$lock->release()` avec owner. Justifié par le contexte dispatcher ≠ worker queue (cf. commentaire l. 149-152 du Job). Priorité : basse. | Story 16.4+ : adopter pattern `$lock->release()` avec owner unique partagé dispatcher/worker. |

## Catégories de dette à anticiper

### Encodage UTF-16 dans `.pol`

`sambaedu/includes/gpo.inc.php:247,342` (`read_pol`, `write_pol`) gèrent
l'encodage policy registry Windows. Toute lecture/écriture de `.pol` dans
`App\Gpo` devra réutiliser cette logique (potentiellement via un helper porté
`@legacy-port`).

Sortie prévue : Story 16.3 (édition sections) ou Story 16.4 (CRUD).

### Vecteurs d'injection `samba-tool`

`sambaedu/includes/samba-tool.inc.php:54` (`sambatool()`) utilise
`exec("/usr/bin/samba-tool " . $command . …)` — le `$command` est construit
par chaque appelant sans échappement centralisé.

Mitigé dès Story 16.1 par `SambaToolRunner` qui impose le mode array
(`Process::run([...])`). Toute écriture de section ou de policy SYSVOL
doit passer par ce runner.

### Constantes legacy (`gpo.inc.php`)

`sambaedu/includes/gpo.inc.php` définit 30+ constantes top-level
(`REG_NONE`, `REG_SZ`, `GPO_SDDL`, `USER_GPO`, `MACHINE_GPO`, etc.).
Le module natif doit, si nécessaire, les redéfinir comme constantes de classe
ou enums PHP 8.x — pas réutiliser les `define()` globaux.

### Fonctions wbinfo non échappées

`sambaedu/includes/gpo.inc.php:177,183,196` (`get_domain_sid`,
`get_sid_from_name`, `get_name_from_sid`) appellent `exec("wbinfo …")` sans
`escapeshellarg()`. Si on doit porter ces fonctions, le port natif devra
utiliser un `WbinfoRunner` sur le même modèle que `SambaToolRunner`.

Sortie prévue : Story 16.3 / 16.4 selon qui consomme la résolution SID.

### `smbclient` / `smbcacls` pour le SYSVOL

`sambaedu/includes/gpo.inc.php` (9 occurrences, lignes 1094-1314) utilise
`smbclient` et `smbcacls` pour interagir avec SYSVOL via Samba. Story 16.4
décidera : (a) implémenter un `SmbClientRunner` sibling de `SambaToolRunner`,
ou (b) accéder au SYSVOL local (mount Samba) en filesystem direct.

## Discipline

- Toute nouvelle entrée doit pointer vers une story Epic 16 qui la sortira.
- Pas de dette « ouverte sans date » : si on ne sait pas quand on la sort, on
  le dit explicitement dans une cellule « Sortie prévue : non décidé » avec un
  TODO à clarifier.
- Avant de marquer Epic 16 terminé, ce registre doit être vide (ou tous les
  items doivent porter une note explicite « conservée volontairement »).


## Story 16.7 — Portage natif `applications.php` (2026-05-13)

### `AdMachineManager::listRemoteConnexion` — shim fallback Guacamole

`App\Ldap\AdMachineManager::listRemoteConnexion()` retourne actuellement `''`
quand `config('sambaedu.guacamole_url')` est non vide. Le portage natif
complet (lecture du groupe AD `remote_<machineCn>` objectClass
`guacConfigGroup`) requiert un `RemoteConnectionRepository` natif (LdapModel
+ Repository) qui n'est pas dans le scope 16.7.

**Impact iso-legacy** : le legacy renvoyait `'rdp'`/`'vnc'`/`'ssh'` selon
le `guacConfigProtocol`. Conséquence : les utilisateurs RDP n'auront pas
l'élément `remote_user` injecté dans `list_u`/`list_ue` côté natif. Les
scripts conditionnels sur `remote_user` (filtres include `'remote_user'`)
seront donc rendus comme « non remote » par défaut.

Sortie prévue : story dédiée Epic Guacamole (post-Epic 16) ou Epic 17.

### `local_admin_scripts` — droits AD `have_right`/`have_delegation`/`get_local_admin_right`

Le port natif `ApplicationScriptsAssembler::localAdminScripts()` est
**simplifié** par rapport au legacy : il ne convertit plus les utilisateurs
en administrateurs locaux Windows (`net localgroup administrateurs`) car les
fonctions legacy `have_right`/`have_delegation`/`get_local_admin_right`
(Story 7.x) n'ont pas d'équivalent natif Spatie exposé proprement.

**Impact** : les utilisateurs avec délégation `SE_COMPUTER_ADMIN` ne seront
plus élevés en admin local Windows/Linux par le script généré. Les autres
mécanismes d'élévation (GPO, etc.) restent fonctionnels.

Sortie prévue : Story 7.x dédiée OU shim `@legacy-port` ad hoc dans
`ApplicationScriptsAssembler::localAdminScripts()` selon retour smoke VM
T9 Henri.

### `header_scripts` — `domainsid` runtime

Le legacy `applications.inc.php:362` lit `$domainsid` via `exec("sudo net
getdomainsid | grep domain | cut -d' ' -f6")`. Le port natif ne reproduit
PAS cet appel (sécurité shell + testabilité) — `SET DOMAINSID=` reste vide
côté script généré.

**Impact iso-bytes** : le diff `cmp -b` retournera ≥1 byte de différence
sur les scripts startup Windows si la VM legacy peuplait ce champ. Si
impact détecté à T8 smoke VM (Henri), on portera la lecture native via
un `DomainInfoRepository` (samba-tool show domaininfo) — story dédiée.

Sortie prévue : selon retour T8 smoke VM. Acceptable côté postes : le
script généré reste exécutable, juste `%DOMAINSID%` reste vide.

### `register_machine_hardware` — branchement `add` vs `replace` simplifié

Le legacy distingue 2 cas (`netbootguid` absent → `add`, présent et différent
→ `replace` avec trigger_error). Le port natif utilise `--set-attribute=` qui
est idempotent côté samba-tool (équivalent replace) — perd la trace warning
quand l'UUID change. Compromis pragmatique : un log `gpo` `[gpo]
ad.machine.hardware.register success` est émis à chaque appel, l'audit reste
possible via diff sur logs successifs.

Sortie prévue : itération si demande métier explicite.

### `get_machine_status` — pas porté natif

Le legacy `log_application_scripts:780` consulte `get_machine_status` pour
détecter le changement d'OS (dual boot). Le port natif passe systématiquement
par `AdMachineManager::setOs()` (idempotent — déjà membre = no-op). Impact
zéro côté AD, perte d'optimisation côté code.

### Mockery `final` — limite env CI sans uopz/runkit

Plusieurs services 16.7 (`AdMachineManager`, `ApcuAppContextWriter`,
`ApplicationLoggerService`, `ApplicationScriptsGenerator`) sont déclarés
`final` (ou ont des dépendances `final` non mockables sans extension PHP).
Pour les tests Unit nécessitant mock de `SambaToolRunner` (final), on
utilise `Process::fake()` Laravel à la place (pattern AdMachineManagerTest).
Pour les autres, `AdMachineManager` a été déclaré `class` (sans `final`)
pour permettre le mock dans `ApplicationScriptsGeneratorTest`.

Sortie prévue : non-bloquante. Installation de `uopz` ou `runkit` côté CI
résoudrait globalement (cf. discrepance générale Epic 16).

### Fixtures comparison VM non capturées en CI

`tests/Feature/Gpo/ApplicationsScriptsComparisonTest` est marqué
`@group requires-fixture-capture` et skippé tant que Henri n'a pas capturé
`legacy-applications-startup-windows.cmd` + `legacy-applications-logon-linux.sh`
depuis la VM legacy. Procédure documentée dans `docs/qa/domains/gpo.md`
section 6.8.

Sortie prévue : action Henri T9 smoke VM post-merge 16.7.

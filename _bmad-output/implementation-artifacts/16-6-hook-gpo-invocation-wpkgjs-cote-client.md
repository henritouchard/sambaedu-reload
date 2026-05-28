# Story 16.6 : Hook GPO ↔ invocation `wpkg.js` côté client (jonction Epic 15)

Status: done

> **Story Epic 16 #6** — Jonction explicite GPO ↔ WPKG. **Périmètre = vérifier
> et garantir la cohérence entre (a) la GPO `se4_wpkg` qui déclenche le client
> WPKG côté Windows au démarrage et (b) les endpoints HTTP serveur exposés par
> les Stories 15.2 (`/wpkg/hosts.xml`, `/wpkg/profiles.xml`) et 15.5
> (`/api/v1/wpkg/reports/{hostname}` + auth Bearer Phase 2)**.
>
> **Position dans la chaîne Epic 15/16/17** : Story 16.6 est le **chaînon
> manquant** qui matérialise la jonction « pipeline Epic 15 livré » → « client
> WPKG effectivement déclenché sur les postes Windows ». Sans cette story, le
> pipeline 15.x reste inerte côté parc.
>
> **Frontière nette** : Story 16.6 **ne porte pas** un éditeur de scripts
> Windows (= Epic 17.2), ne crée pas de table `windows_scripts` (= Epic 17.1),
> ne supplante pas le shim 1bis-18 d'import/export GPO (= 16.4 paused). Elle
> outille **uniquement** la propagation d'une GPO `se4_wpkg` cohérente avec
> les URLs et l'auth des endpoints Epic 15.

---

## Story

As **un administrateur SambaEdu Reload (responsable SER ou opérateur de déploiement)**,
I want
- **vérifier** que la GPO `se4_wpkg` qui pilote l'invocation `wpkg.js` côté postes Windows pointe **bien** vers les endpoints natifs `/wpkg/hosts.xml?poste={hostname}` et `/wpkg/profiles.xml?poste={hostname}` (Story 15.2) et **non** vers les anciens chemins legacy potentiellement obsolètes ;
- pouvoir **(re-)publier** la GPO `se4_wpkg` depuis une UI native ou une commande artisan idempotente lorsque l'URL serveur ou la clé Bearer machine change (Story 15.5) ;
- recevoir un **diagnostic explicite** (page Livewire + log channel `gpo`) sur l'état de cohérence de cette jonction : GPO existe ? liée à au moins une OU ? URL pointée valide ? Bearer machine provisionné pour le poste cible ?

So que (a) le **pipeline WPKG livré par Epic 15** soit effectivement déclenché côté postes — sans cette jonction, les XMLs `hosts.xml`/`profiles.xml` ne sont jamais consommés ; (b) tout changement de domaine `SE4FS_NAME` / d'auth Bearer / de structure URL `/wpkg/*` puisse être propagé au parc en **une seule action admin** ; (c) un opérateur n'a **pas** à éditer manuellement le script `.cmd` enfoui dans SYSVOL.

---

## Contexte

### La chaîne d'invocation `wpkg.js` côté Windows

Référence : `docs/explications_wpkg.md` lignes 340-369 et 192-206.

```
[Poste Windows boot]
      │
      ▼
GPO `se4_wpkg` (template officiel `/usr/share/sambaedu/gpo/se4_wpkg.zip`)
liée à OU=Computers (ou à des OUs spécifiques)
      │
      ▼  (script .cmd dans Machine\Scripts\Startup\)
cscript.exe %SOFTWARE%\wpkg\wpkg.js /server=<SE4FS_NAME> /profile=<hostname>
      │
      ▼  (le client wpkg.js fait DEUX requêtes serveur :)
   ┌─────────────────────────────────────────────────────────┐
   │ GET http://<SE4FS_NAME>/wpkg/hosts.xml?poste=<hostname> │ ◄── Story 15.2 done ✅
   │ GET http://<SE4FS_NAME>/wpkg/profiles.xml?poste=<hostname> │ ◄── Story 15.2 done ✅
   └─────────────────────────────────────────────────────────┘
      │
      ▼  (catalogue applications)
   GET http://<SE4FS_NAME>/wpkg/packages.xml  (servi par legacy actuellement)
      │
      ▼  (le client installe/upgrade/désinstalle, puis POST le rapport)
   POST http://<SE4FS_NAME>/api/v1/wpkg/reports/<hostname>  ◄── Story 15.5 review
        Authorization: Bearer <secret machine>              ◄── Story 15.5 review
```

**Source de la GPO `se4_wpkg`** : un template officiel `.zip` vit dans
`/usr/share/sambaedu/gpo/se4_wpkg.zip` (cf. `documentation/misc/gpo.md:341-344`).
Il est importé dans SYSVOL via la chaîne legacy `import_gpo($config, ...)` (cf.
`docs/wpkgTodo.md:571`, `documentation/misc/gpo.md:266-274`) — actuellement
shimmée en 1bis-18 (`gpo/gpo-maj.php` UI legacy preservée + 16-5 D11 « encart
Création GPO paused » qui pointe vers ce shim en `target=_blank`).

Cette GPO contient des **placeholders** spécialisés au moment de l'import par
`specialise_gpo($config, $source_path)` (cf. `documentation/misc/gpo.md:300-308`)
qui remplacent :
- `###_SE4FS_NAME_###` → `config('sambaedu.se4fs_name')` / `env('SE4FS_NAME')`
- `###_DOMAIN_###`, `###_SAMBA_DOMAIN_###`, `###_DOMAIN_SID_###`, `###_LDAP_BASE_DN_###`

→ **Discrepance critique à lever en T0** : la GPO `se4_wpkg` officielle
contient-elle un placeholder `###_WPKG_URL_###` (ou équivalent) pour l'URL
serveur ? Sinon, l'URL pointée par `cscript wpkg.js /server=...` est
implicitement `http://###_SE4FS_NAME_###/wpkg/...`. Story 16.7 a déjà ajouté
une clé `WPKG_URL` au whitelist substitutions (cf.
`config/sambaedu.gpo.applications.substitutions.php:67`) mais elle reste vide
(`config('sambaedu.wpkg.base_url', '')` non défini dans `config/sambaedu.php`).
**T0 doit** : (a) extraire le contenu réel de `se4_wpkg.zip` (sur VM ou via
artefact legacy), (b) vérifier le `.cmd` startup embarqué, (c) lister
exhaustivement les placeholders utilisés.

### Pourquoi 4-5 jours (et pas 1-2j)

| Volet                                                                          | Charge |
|--------------------------------------------------------------------------------|--------|
| **T0 audit du template `se4_wpkg.zip`** + inventaire placeholders + chaîne `cscript wpkg.js /server=...` réelle sur VM | 1j |
| **Service `WpkgGpoSynchronizer`** (vérification cohérence URL + état GPO + warning si Bearer machine absent) | 1j |
| **UI Livewire `/app/gpo/wpkg-deployment`** (état GPO `se4_wpkg`, bouton re-spécialiser/republier, lecture seule détail) | 1j |
| **Commande artisan `wpkg:gpo:sync`** (idempotente, exécutable hors UI : audit cron / déploiement initial) | 0.5j |
| **Sécurité** (lock anti-race `Cache::lock('gpo:wpkg:sync')`, permission `server.admin`, audit logs `gpo`) | 0.3j |
| **Tests** (Unit synchronizer + Feature page + Feature artisan + Architecture) | 0.7j |
| **Doc** (`docs/qa/domains/gpo.md` section 8 + `app/Gpo/README.md` section dédiée + `app/Wpkg/Deployment/README.md` cross-ref) | 0.3j |
| **Smoke VM** (action Henri T7) | 0.2j |

**Cadre objectif** : 4j. Recadrage 5-6j si T0 révèle que la GPO `se4_wpkg`
contient un `.cmd` qui doit être **réécrit côté serveur** (et pas seulement
re-spécialisé) — ce qui basculerait la story d'un *audit + sync* vers un
*générateur de scripts NETLOGON* (typiquement Epic 17.1).

### Position vs `epics.md` cadrage haut niveau

`epics.md:3346` (cadrage initial) :
> *« génération d'une GPO de logon/startup spéciale qui invoque `wpkg.js` côté
> Windows et pointe vers les XML générés par Story 15.2. Point d'intégration
> explicite avec Epic 15 — à coordonner avec Story 15.2 et 15.5 pour garantir
> que l'URL/chemin pointé par la GPO soit cohérent avec ce que les Generators
> produisent. Cette story déclenche probablement Story 17.x si l'invocation de
> `wpkg.js` est elle-même un script Windows packagé. »*

**Décision SM 16.6 = NE PAS générer la GPO from scratch** (= ce serait du
ressort Epic 17.1 « modèle Eloquent `WindowsScript` + NETLOGON »). Au lieu de
ça, on **vérifie et republie** la GPO `se4_wpkg` template officiel existante
(`/usr/share/sambaedu/gpo/se4_wpkg.zip`), avec spécialisation explicite des
placeholders d'URL serveur. Si à terme l'invocation `wpkg.js` doit être
remplacée par un script généré dynamiquement par SambaEdu Reload, c'est l'objet
d'Epic 17.1 — pas de Story 16.6.

→ La story **consomme** : (a) la GPO template `se4_wpkg.zip` ; (b) les endpoints
Stories 15.2/15.5 ; (c) les services `GpoService::setLink`/`reorderLinks` (Story
16.5) si l'admin veut re-lier ; (d) le shim `import_gpo`/`specialise_gpo` legacy
pour la (re-)publication SYSVOL.
→ La story **livre** : un état diagnostique + une commande de synchronisation +
une UI minimale Livewire.

---

## Garde-fous Epic 16 (rappel applicables)

- **AD = source de vérité GPO** : aucune table Eloquent nouvelle. Lecture
  uniquement de la GPO `se4_wpkg` via `GpoService::fetch(displayname)` (méthode
  16.1) + `GpoService::getLinks($guid)` (16.5) pour l'état des liaisons. La
  re-publication via `import_gpo` shim retombe sur SYSVOL.
- **Trois couches** : Controller fin / Livewire SFC → Service métier
  (`WpkgGpoSynchronizer`) → `GpoService` (16.1/16.5) + shim legacy via
  `legacy/bootstrap.php` pour `import_gpo`/`specialise_gpo`.
- **Channel logs `gpo`** (admin audit) — pas `daily` : ces actions sont
  rares (publication GPO), auditables, et alignent avec 16.5 D7 (channel `gpo`
  exclusif pour write AD/SYSVOL).
- **Catalogue `action_type` enrichi** : ajouter `gpo.wpkg.sync.start`,
  `gpo.wpkg.sync.end`, `gpo.wpkg.template.spec`, `gpo.wpkg.publish` au catalogue
  `app/Gpo/README.md` (Story 16.1 AC1.3).
- **Pattern Livewire SFC inline** : `new #[Title] class extends Component` —
  iso 16.2/16.3c/16.5. Permission `server.admin` Spatie.
- **CLAUDE.md** : `<x-molecules.modal>` réutilisable pour confirmation
  « republier la GPO », trait `WithToasts` pour feedback succès/erreur.
- **Tests architecture** : `GpoNamespaceTest` enrichi pour interdire
  `LdapRecord` direct dans `App\Gpo\Services\WpkgGpoSynchronizer` (passe par
  `GpoService` ou shim `legacy/bootstrap.php`).
- **Iso-bytes NON applicable** : c'est une UI admin + commande artisan, pas
  un endpoint runtime poste.

---

## Infrastructure native existante à RÉUTILISER (pas de réinvention)

> Le dev consulte cette table **AVANT** d'écrire toute nouvelle classe.

| Besoin                                             | Réutiliser                                                                | Path                                                                | Note                                                                                       |
|----------------------------------------------------|---------------------------------------------------------------------------|---------------------------------------------------------------------|--------------------------------------------------------------------------------------------|
| Lister/lire GPOs                                   | `GpoService::list()`, `GpoService::fetch($guidOrDn)` (Story 16.1)         | `app/Gpo/Services/GpoService.php`                                   | Méthodes lecture stables.                                                                  |
| État des liaisons GPO                              | `GpoService::getLinks($guid)` (16.1/16.5)                                 | idem                                                                | Pour AC2 « GPO `se4_wpkg` est liée à ≥1 OU ? ».                                            |
| Lier une OU à la GPO (cas re-lier après publish)   | `GpoService::setLink($guid, $ouDn, ...)` (Story 16.5 ✅ implémenté)        | idem                                                                | Idempotent (`already linked`).                                                             |
| Exécution `samba-tool gpo`                         | `SambaToolRunner` mode array (16.1)                                       | `app/Gpo/Support/SambaToolRunner.php`                               | Pas de concat shell.                                                                       |
| Logger structuré `gpo`                             | `GpoLogger` + `GpoActionLog` (16.1)                                       | `app/Gpo/Support/GpoLogger.php`                                     | `operation_id` UUID propagé.                                                               |
| Bridge config (`se4fs_name`, etc.)                 | `App\Config\SambaEduConfig::get($key)` + `::set` natif (16.3b option A)   | `app/Config/SambaEduConfig.php`                                     | Lecture des clés `se4fs_name`, `domain`, `wpkg.base_url` (à ajouter — cf. T0.5).           |
| Whitelist substitutions GPO                        | `config/sambaedu.gpo.applications.substitutions.php` (Story 16.7)         | idem                                                                | Étendre si `WPKG_URL`/`WPKG_HOSTS_XML_URL`/`WPKG_PROFILES_XML_URL` placeholders requis.    |
| Permission Spatie admin GPO                        | `SambaPermission::ServerAdmin` (`server.admin`)                           | `app/Enums/SambaPermission.php`                                     | Iso 16.2/16.5.                                                                             |
| Bearer machine (Story 15.5)                        | Table `workstation_api_secrets` + commande `wpkg:provision-secrets`       | `database/migrations/*workstation_api_secrets*`                     | **Lecture seule** ici (vérifier que `last_used_at` est récent → diagnostic OK).            |
| Endpoints serveur 15.2                             | `App\Wpkg\Deployment\Http\Controllers\{Hosts,Profiles}XmlController`      | `routes/web.php:467-472`                                            | **Lecture seule** : extraire l'URL via `route('wpkg.hosts-xml')`.                          |
| Shim import GPO legacy                             | `import_gpo`, `specialise_gpo`, `list_gpo_templates`, `import_gpo_zip`    | `legacy/bootstrap.php` → `legacy/gpo.inc.php` (shim 1bis-18a/b)     | **Fallback shim `@legacy-port`** autorisé (cf. décision D6 ci-dessous).                    |
| Pattern Service Synchronizer + diagnostic          | `App\Gpo\Services\ReadUserManager` (16.3b) — service avec drift recovery  | `app/Gpo/Services/ReadUserManager.php`                              | Référence pour `WpkgGpoSynchronizer::audit()` retournant un DTO `WpkgGpoSyncReport`.       |
| Pattern Page Livewire SFC `/app/gpo/...`           | `pages::app.gpo.[guid].links.index` (16.5)                                | `resources/views/pages/app/gpo/[guid]/links/index.blade.php`        | Référence iso permissions/modale/toasts.                                                   |
| Pattern commande artisan + dispatch event          | `App\Wpkg\Deployment\Console\Commands\RotateWpkgReportArchivesCommand`    | idem path                                                           | Référence pour `wpkg:gpo:sync` (verbosité, exit codes, signature).                         |

---

## Dépendances

| Story / Epic | Titre | Status | Détail |
|---|---|---|---|
| **15.2** | Generators XML + .ini par poste | **done** ✅ (2026-05-07) | **Source d'URL stricte** : `route('wpkg.hosts-xml')` et `route('wpkg.profiles-xml')` (cf. `routes/web.php:467-472`). 16.6 vérifie que la GPO `se4_wpkg` pointe bien vers ces URLs. |
| **15.5** | Pipeline rapports clients + Dashboard | **review** (2026-05-13) | Fournit l'auth Bearer Phase 2 + commande `wpkg:provision-secrets` + middleware `WorkstationBearerAuth`. **16.6 vérifie** que pour chaque poste lié à la GPO `se4_wpkg`, un Bearer existe via `workstation_api_secrets`. Non bloquant strict (statut `review` ≠ `done`), mais cadrage 16.6 acceptable car les signatures sont stables. **Si 15.5 régresse**, 16.6 peut désactiver le check Bearer via feature flag config (à prévoir AC4.4). |
| **16.1** | Fondations GPO natives + audit | review | **Réutilisation directe** : `GpoService` (`list`/`fetch`/`getLinks`), `SambaToolRunner`, `GpoLogger`, channel `gpo`, catalogue `action_type` (à enrichir 4 entrées). |
| **16.2** | Listing GPO UI native | review | **Pattern UI** : `pages::app.gpo.*`, permission `server.admin`, regex GUID tolérante (fix #9). |
| **16.3b** | network_out + veyon_out | review | **Pattern Service Drift recovery** : `ReadUserManager` référence pour `WpkgGpoSynchronizer::audit()`. |
| **16.5** | Liaison GPO ↔ OU/parc | review | **Réutilisation `GpoService::setLink`** si l'admin veut re-lier post-publication. Pas de modification du `WpkgGpoSynchronizer` côté liaisons (16.5 fait l'UI complète, 16.6 délègue). |
| **16.7** | Portage natif applications.php | review | **Réutilisation `config/sambaedu.gpo.applications.substitutions.php`** (clés whitelist substitutions). Si `WPKG_HOSTS_XML_URL` doit être ajouté, c'est en 16.6 (additif à la whitelist 16.7). |
| **1bis-18a/b** | Shim legacy GPO | done/review | Fournit `import_gpo`/`specialise_gpo`/`list_gpo_templates` côté legacy — réutilisés via `legacy/bootstrap.php` (fallback `@legacy-port` autorisé D6). |
| **Epic 17.1** | Fondations scripts Windows | **backlog** (not-ready) | **Frontière nette** : si 16.6 T0 révèle qu'il faut **réécrire** le `.cmd` startup de la GPO (au lieu de juste re-spécialiser), bascule en attendant Epic 17.1. Décision SM : on **n'attend pas** Epic 17.1 — on consomme le template `se4_wpkg.zip` officiel tel quel. |

**Aucune dépendance bloquante stricte**. 15.2 done est le prérequis le plus
critique (sans endpoints `/wpkg/hosts.xml` + `/wpkg/profiles.xml`, la
synchronisation n'a pas de cible). 15.5 review est acceptable.

---

## Décisions SM (D1-D10) — pré-tranchées

| #   | Décision | Justification |
|-----|----------|---------------|
| **D1** | **Périmètre = audit + (re-)publication de la GPO `se4_wpkg`** (template officiel `/usr/share/sambaedu/gpo/se4_wpkg.zip`). **Pas** de génération from scratch d'une GPO de logon WPKG. | Le cadrage haut niveau `epics.md:3346` mentionne « génération » mais c'est en réalité un import + spécialisation depuis un template officiel (déjà packagé dans `sambaedu-gpo`). Toute génération from scratch d'un `.cmd` startup serait du ressort Epic 17.1 (modèle Eloquent `WindowsScript`). On reste dans le périmètre Epic 16 = configuration GPO. |
| **D2** | **URL serveur cible = via `route('wpkg.hosts-xml')` / `route('wpkg.profiles-xml')`** (Story 15.2). Pas d'URL configurable dans cette story (héritée du routing). Si plus tard `SE4FS_NAME` change, la commande `wpkg:gpo:sync` permet de propager. | Single source of truth = `routes/web.php`. Éviter la duplication `WPKG_HOSTS_XML_URL` + risque de divergence. La résolution se fait via `URL::route('wpkg.hosts-xml', [], absolute: true)` en absolute pour qu'elle soit utilisable côté poste Windows. |
| **D3** | **Service métier `App\Gpo\Services\WpkgGpoSynchronizer`** avec 2 méthodes publiques : `audit(): WpkgGpoSyncReport` (lecture, retourne un DTO immutable structuré) et `publish(bool $force = false): WpkgGpoSyncReport` (write — import + spécialisation + log audit). Pattern iso `ReadUserManager` (16.3b). | (a) Une lecture pure (`audit`) sans side effect pour UI temps réel + commande dry-run ; (b) une mutation isolée (`publish`) sous lock + idempotente ; (c) DTO `WpkgGpoSyncReport` typé readonly (état GPO, liaisons, cohérence URL, Bearer status par poste) — facilite tests + sérialisation Livewire. |
| **D4** | **UI Livewire SFC `/app/gpo/wpkg-deployment`** (route name `app.gpo.wpkg-deployment`) + commande artisan `wpkg:gpo:sync` (avec options `--audit-only` / `--force` / `--json`). | Iso pattern 16.2 (`pages::app.gpo.index`) + 16.5 (`pages::app.gpo.[guid].links.index`). La commande artisan = double accès indispensable (cron de monitoring, déploiement initial via Ansible/playbook, debugging) — pattern iso `RotateWpkgReportArchivesCommand` (15.5). |
| **D5** | **Modale de confirmation `<x-molecules.modal>` obligatoire** sur `publish` (force re-import GPO = side effect SYSVOL). Pas de modale sur `audit` (lecture seule). | Pattern iso CLAUDE.md + 16.5 D6 (modales sur toute action write AD). Re-publier = écraser SYSVOL : doit être un acte conscient. |
| **D6** | **Fallback shim `@legacy-port` autorisé** pour `import_gpo`/`specialise_gpo`/`list_gpo_templates` (chargés via `legacy/bootstrap.php`). Pas de portage natif de l'import GPO dans cette story (= Story 16.4 paused, on n'ouvre pas un autre front). | `import_gpo` = fonction legacy massive (cf. `documentation/misc/gpo.md:266-274`) qui orchestre `smbclient`/`smbcacls`/`samba-tool gpo create`. Son portage natif est explicitement hors scope (16.4 paused, décision Henri 2026-05-13). Le shim 1bis-18a/b est fonctionnel — on s'y appuie avec docblock `@legacy-port` + entrée `docs/tech-debt-gpo.md`. |
| **D7** | **Channel logs `gpo` exclusif** (volume faible — quelques publications par an par admin). 4 nouveaux `action_type` : `gpo.wpkg.sync.start`, `gpo.wpkg.sync.end`, `gpo.wpkg.template.spec`, `gpo.wpkg.publish`. | Iso 16.5 D7. Pas de pollution `daily`. Audit trail clean pour traçabilité écriture SYSVOL. |
| **D8** | **Permission `server.admin` (`SambaPermission::ServerAdmin`)** sur la page Livewire + commande artisan (via `Gate::authorize` ou `abort_unless` en `mount()`). Pas de nouvelle permission Spatie. | Iso 16.5 D5. Cohérence Epic 16. Pas de fragmentation des permissions. |
| **D9** | **Bearer machine = lecture seule en 16.6** (diagnostic uniquement). Le provisioning de secrets reste à `php artisan wpkg:provision-secrets` (Story 15.5). Le rapport `WpkgGpoSyncReport` liste les postes liés à la GPO `se4_wpkg` qui n'ont **pas** de Bearer (warning) — mais ne le crée pas automatiquement. | Le provisioning de secrets implique de **distribuer le secret clair** au poste (cf. 15.5 décision SM 2) — ce process est manuel/scripté côté ops, hors UI admin. 16.6 reste un outil de diagnostic + republication GPO, pas un outil d'enrôlement de postes. |
| **D10** | **Pas de catchall override** d'URL legacy spécifique. L'admin qui veut éditer manuellement la GPO peut passer par le shim `gpo/gpo-maj.php` (encart « Création GPO paused » de 16-5 D11 reste valide). 16.6 propose une UI complémentaire dédiée à la GPO `se4_wpkg`, pas un remplaçant de `gpo-maj.php`. | Pattern iso 16-4 paused : les autres GPOs restent gérées via shim. Seule la GPO `se4_wpkg` a une UI native dédiée parce qu'elle est **le** point d'intégration Epic 15. |

### Discrepances ouvertes à trancher en T0 (DO1-DO4)

| Item                                       | Note SM / Par défaut                                                                                                                                                                                                                                                                                                                                                                                       |
|--------------------------------------------|------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| **DO1** Contenu réel du template `se4_wpkg.zip` | **Action T0.1** : extraire le `.zip` sur la VM ou récupérer artefact ailleurs ; analyser le `Machine\Scripts\Startup\*.cmd` ou `*.bat` ; lister **exhaustivement** les placeholders (`###_SE4FS_NAME_###`, `###_DOMAIN_###`, etc.) ; vérifier la présence d'un placeholder URL WPKG explicite. **Par défaut** (si non documenté) : la GPO référence implicitement `http://###_SE4FS_NAME_###/wpkg/...` — la spécialisation `###_SE4FS_NAME_###` suffit. Si DO1 révèle plus, ajouter au whitelist `config/sambaedu.gpo.applications.substitutions.php`. |
| **DO2** Bearer machine vs IP-allowlist (Phase 1/Phase 2 15.5) | **Action T0.2** : vérifier l'état d'avancement Phase 2 (15.5 review — middleware `WorkstationBearerAuth` activé ou pas par défaut ?). **Par défaut** : le diagnostic Bearer = **warning** non bloquant (le pipeline marche encore via Phase 1 `EnsureLocalRequest`). Si 15.5 passe en Phase 2 strict après déploiement, bumper en `error` via config `sambaedu.gpo.wpkg_sync.bearer_required` (à ajouter). |
| **DO3** Re-spécialisation atomique vs best effort | **Action T0.3** : `import_gpo` legacy est-il atomique (rollback SYSVOL si erreur mi-parcours) ou best effort ? **Par défaut** : best effort + lock applicatif `Cache::lock('gpo:wpkg:sync', 60)` bloquant (anti-race admin double-clic) + log audit complet pour permettre rollback manuel via `import_gpo` legacy avec ancien template. Pattern iso 16.5 DO1 (reorderLinks best effort + rollback try/catch + TD documentée). |
| **DO4** Auto-link aux OUs vs link manuel post-publish | **Action T0.4** : la GPO `se4_wpkg` officielle doit-elle être liée automatiquement à `OU=Computers` au moment du publish (sécurité par défaut), ou laissée non liée pour décision admin via UI 16.5 `/app/gpo/{guid}/links` ? **Par défaut** : pas d'auto-link au publish (= séparation responsabilité 16.6 publication / 16.5 liaisons). Le rapport `WpkgGpoSyncReport` signale en `warning` si la GPO existe mais n'est liée à aucune OU. L'admin clique vers `/app/gpo/{guid}/links` pour lier. |

---

## Acceptance Criteria

> 5 volets. V1 = Service `WpkgGpoSynchronizer::audit` (lecture). V2 = Service
> `WpkgGpoSynchronizer::publish` (write). V3 = UI Livewire +
> commande artisan. V4 = Sécurité + catalogue logs. V5 = Tests + doc.

### Volet 1 — Service `WpkgGpoSynchronizer::audit()` (lecture pure)

**AC1.1 — Signature DTO**
**Given** la story est implémentée
**When** un appelant invoque `App\Gpo\Services\WpkgGpoSynchronizer::audit(): WpkgGpoSyncReport`
**Then** la méthode est **readonly** (aucun side effect AD/SYSVOL/FS)
**And** elle retourne un DTO `App\Gpo\Dto\WpkgGpoSyncReport` (`final readonly class`)
contenant **au minimum** :
- `gpoExists: bool` (la GPO `se4_wpkg` existe-t-elle dans l'AD ?)
- `gpoGuid: ?string` (GUID format `{XXXX-...-XXXX}` si existe, `null` sinon)
- `linkedOus: array<string>` (liste des DN OUs où la GPO est liée — vide si non liée)
- `expectedHostsXmlUrl: string` (résolue via `URL::route('wpkg.hosts-xml', [], absolute: true)`)
- `expectedProfilesXmlUrl: string` (idem)
- `templatePath: string` (`/usr/share/sambaedu/gpo/se4_wpkg.zip`)
- `templateExists: bool` (le `.zip` est-il présent sur disque ?)
- `templateLastModified: ?\DateTimeImmutable` (mtime du `.zip`)
- `bearerCoverage: array<string,bool>` (`workstation_name => has_bearer_secret`) — issu de jointure `workstations` ↔ `workstation_api_secrets`
- `severity: WpkgGpoSyncSeverity` (`ok`/`warning`/`error` — enum à créer)
- `messages: array<string>` (libellés humains diagnostic, ex: « GPO `se4_wpkg` non liée à aucune OU — le pipeline WPKG n'est déclenché sur aucun poste »).

**AC1.2 — Détection GPO existante**
**Given** la GPO `se4_wpkg` existe (ou non) dans l'AD
**When** `audit()` est invoquée
**Then** elle interroge `GpoService::list()` (16.1) et filtre par `displayname === 'se4_wpkg'`
**And** si match → `gpoExists = true`, `gpoGuid = $row->guid` (format `{XXX-XXX}`)
**And** si pas de match → `gpoExists = false`, `gpoGuid = null`, `severity = 'error'`, messages enrichis.

**AC1.3 — État des liaisons**
**Given** `gpoExists === true`
**When** `audit()` poursuit
**Then** elle interroge `GpoService::fetch($gpoGuid)` puis lit `containers` (16.1) — OU si plus simple `samba-tool gpo listcontainers {guid}` via runner
**And** `linkedOus` est peuplé avec la liste des DN OUs liées
**And** si `linkedOus === []` → `severity` au moins `warning` + message « GPO existe mais n'est liée à aucune OU ».

**AC1.4 — Cohérence URL (T0.1 critique)**
**Given** le template `se4_wpkg.zip` est lu (extraction `.cmd` startup)
**When** `audit()` parse le contenu du script
**Then** elle extrait les placeholders `###_*_###` présents
**And** elle vérifie que tous les placeholders détectés sont **dans le whitelist** `config/sambaedu.gpo.applications.substitutions.php`
**And** si un placeholder non whitelisté est détecté → `severity = 'error'` + message explicite + nom du placeholder
**And** si le `.zip` est absent (`templateExists === false`) → `severity = 'error'` + message « Template officiel `se4_wpkg.zip` non trouvé dans `/usr/share/sambaedu/gpo/` ».

**AC1.5 — Couverture Bearer (Story 15.5)**
**Given** les postes liés (via OU → membres) à la GPO `se4_wpkg`
**When** `audit()` calcule `bearerCoverage`
**Then** pour chaque poste, elle interroge `workstation_api_secrets` (Eloquent) et indique `has_bearer_secret = (count > 0 && revoked_at === null)`
**And** si > 10% des postes n'ont pas de Bearer → `severity` au moins `warning` + message agrégé « X/Y postes liés sans secret Bearer Phase 2 ».
**And** si la table `workstation_api_secrets` n'existe pas (15.5 pas encore migré) → message info + pas de bump `severity`.

**AC1.6 — Idempotence**
**Given** `audit()` invoqué N fois sans changement entre les invocations
**When** chaque appel est exécuté
**Then** le DTO retourné est **identique** (immutable) — modulo `templateLastModified` qui est lu disque
**And** aucun écriture log haut niveau pour `audit` (un seul `gpo.wpkg.sync.start` `level=debug` pour traçabilité, **pas** `info`).

### Volet 2 — Service `WpkgGpoSynchronizer::publish()` (write — re-import + spécialisation SYSVOL)

**AC2.1 — Signature + lock**
**Given** un appelant invoque `WpkgGpoSynchronizer::publish(bool $force = false): WpkgGpoSyncReport`
**When** la méthode démarre
**Then** elle prend un lock applicatif `Cache::lock('gpo:wpkg:sync', 60)->get()` **bloquant** (`->block(10)`)
**And** si lock impossible après 10s → `throw new RuntimeException('Synchronisation en cours par un autre processus')`
**And** elle libère le lock dans un `finally`.

**AC2.2 — Pré-conditions**
**Given** `publish()` exécutée
**When** elle vérifie les pré-conditions
**Then** elle exige `$report = $this->audit()` puis :
- Si `templateExists === false` → `throw new \RuntimeException('Template se4_wpkg.zip introuvable')`
- Si `gpoExists === true && $force === false && $report->severity === 'ok'` → no-op + log `info` « GPO `se4_wpkg` déjà à jour, no-op (`--force` pour forcer) » + return `$report`
- Si `gpoExists === true && $force === true` → poursuit la republication (écrasement)
- Si `gpoExists === false` → poursuit la création (premier deploy).

**AC2.3 — Spécialisation (T0.1)**
**Given** le template `se4_wpkg.zip` est prêt à être importé
**When** `publish()` exécute la spécialisation
**Then** elle appelle `specialise_gpo($config, $source_path)` via shim `legacy/bootstrap.php` (cf. D6) avec docblock `@legacy-port path="sambaedu/includes/gpo.inc.php (specialise_gpo)"`
**And** le `$config` passé contient au moins `se4fs_name`, `domain`, `samba_domain`, `domain_sid`, `ldap_base_dn` (résolus depuis `App\Config\SambaEduConfig`)
**And** un log `gpo.wpkg.template.spec` est émis (channel `gpo`, `level=info`) avec `operation_id` propagé.

**AC2.4 — Import via shim**
**Given** la spécialisation a réussi
**When** `publish()` appelle `import_gpo($config, 'se4_wpkg', $gpo_archive, $update = true, $force)` via shim
**Then** le retour est inspecté ; si échec → `RuntimeException` propagée + log `gpo.wpkg.publish` `level=error`
**And** un log `gpo.wpkg.publish` `level=info` est émis en cas de succès avec `gpo_guid` posté dans l'AD
**And** la GPO est désormais visible via `GpoService::list()` au prochain appel.

**AC2.5 — Pas d'auto-link aux OUs (DO4)**
**Given** la GPO `se4_wpkg` vient d'être (re-)publiée
**When** `publish()` retourne
**Then** **aucun** appel automatique à `GpoService::setLink` n'est fait
**And** le DTO retourné a `linkedOus` à jour (re-issu d'un `audit()` final)
**And** si `linkedOus === []` → message explicite « GPO publiée mais non liée. Allez sur `/app/gpo/{guid}/links` pour la lier à une OU. ».

**AC2.6 — Idempotence + warning rollback**
**Given** un échec mi-parcours (spec OK, import KO)
**When** `publish()` capture l'exception
**Then** elle loggue `level=critical` channel `gpo` un message explicite « État SYSVOL potentiellement incohérent — vérifier manuellement »
**And** elle propage l'exception (pas de rollback automatique — pattern iso 16.5 DO1)
**And** entrée `tech-debt-gpo.md` TD-16.6-1 documente la limitation.

### Volet 3 — UI Livewire + commande artisan

**AC3.1 — Page Livewire `/app/gpo/wpkg-deployment`**
**Given** la story est implémentée
**When** un admin navigue vers `/app/gpo/wpkg-deployment`
**Then** la route `Route::livewire('/app/gpo/wpkg-deployment', 'pages::app.gpo.wpkg-deployment.index')` est déclarée (préfixe `app.` du groupe → name `app.gpo.wpkg-deployment`)
**And** le middleware `can:server.admin` est appliqué
**And** la page est un Livewire SFC inline (`new #[Title('Hook GPO ↔ WPKG')] class extends Component`) iso pattern 16.5
**And** `mount()` fait `abort_unless(auth()->user()?->can('server.admin'), 403)` (défense en profondeur)
**And** le composant injecte `WpkgGpoSynchronizer` via `boot()` DI.

**AC3.2 — Contenu de la page**
**Given** la page est rendue
**When** elle affiche le `WpkgGpoSyncReport`
**Then** elle expose **au minimum** :
- **Badge sévérité** en haut (`ok`/`warning`/`error` avec code couleur)
- **Tableau 1 : État GPO** : nom (`se4_wpkg`), exists (✅/❌), GUID, mtime template
- **Tableau 2 : Liaisons** : liste des OUs liées (clic → `/app/gpo/{guid}/links` si lié, sinon CTA « Lier maintenant »)
- **Tableau 3 : URLs serveur attendues** : `expectedHostsXmlUrl`, `expectedProfilesXmlUrl`, bouton « copier dans le presse-papiers » (JS pur, pas d'enjeu)
- **Tableau 4 : Couverture Bearer Phase 2** : agrégat (X/Y postes liés ont un Bearer) + lien vers liste détaillée (lazy-load Livewire)
- **Liste des messages** diagnostiques
- **CTA principal « Re-publier la GPO `se4_wpkg` »** (rouge danger) → ouvre `<x-molecules.modal>` confirmation (D5) → invoque `publish()` + toast `WithToasts`
- **CTA secondaire « Re-auditer »** → recharge `$report` sans side effect.

**AC3.3 — Modale confirmation**
**Given** l'admin clique sur « Re-publier la GPO »
**When** la modale s'ouvre
**Then** elle affiche **le DTO `WpkgGpoSyncReport`** côté texte explicite (« vous allez écraser la GPO `se4_wpkg` dans SYSVOL — cette action recrée le script `.cmd` côté postes »)
**And** un checkbox « Forcer même si déjà à jour (`--force`) » est exposé
**And** boutons : Annuler (gris) + Confirmer la republication (rouge danger)
**And** au confirm → `$this->doPublish($force)` qui invoque `WpkgGpoSynchronizer::publish($force)` puis émet `WithToasts::toastSuccess` ou `toastError`
**And** la page est rechargée avec le nouveau `$report`.

**AC3.4 — Commande artisan `wpkg:gpo:sync`**
**Given** la story est implémentée
**When** un opérateur lance `php artisan wpkg:gpo:sync`
**Then** la commande `App\Wpkg\Deployment\Console\Commands\WpkgGpoSyncCommand` (signature `wpkg:gpo:sync {--audit-only} {--force} {--json}`) :
- Sans option → exécute `audit()` puis affiche un tableau lisible (`$this->table(...)`) + exit code 0 si severity `ok`, 1 si `warning`, 2 si `error`
- `--audit-only` → idem mais bloque tout `publish` (= dry-run pour cron)
- `--force` → exécute `publish(force: true)`
- `--json` → output JSON sérialisé du DTO (cron-friendly)

**AC3.5 — Permission middleware HTTP**
**Given** la route `/app/gpo/wpkg-deployment`
**When** un utilisateur sans `server.admin` y accède
**Then** réponse HTTP `403`
**And** test feature `WpkgDeploymentPagePermissionTest` couvre 4 cas (admin 200 / user 403 / unauthenticated 403 / route middleware 403).

### Volet 4 — Sécurité + catalogue logs

**AC4.1 — Lock anti-race**
**Given** 2 admins cliquent simultanément sur « Re-publier »
**When** les 2 invocations `publish()` arrivent
**Then** la 2nde attend max 10s sur `Cache::lock('gpo:wpkg:sync', 60)->block(10)`
**And** si toujours bloqué → `RuntimeException` propagée → toast erreur
**And** test Feature `it_blocks_concurrent_publish_with_cache_lock` (mock `Cache::lock`) vérifie le comportement.

**AC4.2 — Validation entrée**
**Given** la commande artisan ou la page Livewire
**When** un input optionnel est passé (par ex. `--force` cast bool, `--json` cast bool)
**Then** validation Laravel strict des options (Symfony Console) — aucun input arbitraire serveur
**And** **aucun input user n'est jamais concaténé à une commande shell** (le shim `import_gpo` est appelé en PHP, pas en exec — défense en profondeur via test architecture).

**AC4.3 — Catalogue `action_type` enrichi**
**Given** la story est implémentée
**When** la doc `app/Gpo/README.md` est mise à jour
**Then** 4 nouveaux `action_type` sont ajoutés au tableau § « Convention de logging Epic 16 » :
- `gpo.wpkg.sync.start` (16.6 — démarrage audit ou publish)
- `gpo.wpkg.sync.end` (16.6 — fin avec outcome success/failure)
- `gpo.wpkg.template.spec` (16.6 — spécialisation placeholders réussie)
- `gpo.wpkg.publish` (16.6 — import_gpo réussi/échoué)
**And** chaque log porte `operation_id` UUID propagé + `gpo_name=se4_wpkg`.

**AC4.4 — Feature flag Bearer Phase 2 (DO2)**
**Given** Story 15.5 pas encore `done` (statut `review` au cadrage)
**When** la config `config('sambaedu.gpo.wpkg_sync.bearer_required', false)` est lue par `audit()`
**Then** si `false` → la couverture Bearer reste informative (`severity` info)
**And** si `true` → la couverture Bearer bump `severity` à `error` si > 10% sans Bearer
**And** la valeur par défaut est `false` (mode tolérant Phase 1).

### Volet 5 — Tests + doc QA

**AC5.1 — Tests Unit `WpkgGpoSynchronizerTest` (`tests/Unit/Gpo/WpkgGpoSynchronizerTest.php`)**
Au moins **10 tests** :
1. `audit_returns_error_severity_when_gpo_not_found`
2. `audit_returns_warning_when_gpo_exists_but_unlinked`
3. `audit_detects_placeholder_outside_whitelist`
4. `audit_reads_template_mtime_from_disk`
5. `audit_aggregates_bearer_coverage_by_linked_ou`
6. `audit_returns_ok_when_all_checks_pass`
7. `publish_throws_if_template_missing`
8. `publish_is_noop_when_severity_ok_and_not_forced`
9. `publish_forces_reimport_with_force_true`
10. `publish_logs_critical_on_mid_failure`

Tests mockent `GpoService` (Story 16.1 — pattern `FakesGpoService` builder fluide), `SambaEduConfig`, et shim `import_gpo`/`specialise_gpo` via container Laravel binding (pattern iso 16.3c `get_wine_shortcuts`).

**AC5.2 — Tests Feature `WpkgDeploymentPageTest` (`tests/Feature/Gpo/WpkgDeploymentPageTest.php`)**
Au moins **8 tests** :
1. `admin_sees_gpo_not_found_warning`
2. `admin_sees_unlinked_gpo_warning`
3. `admin_sees_ok_state_when_all_pass`
4. `admin_can_open_publish_modal`
5. `admin_can_confirm_publish_and_sees_toast`
6. `force_checkbox_passes_through_to_synchronizer`
7. `audit_button_reloads_report_without_side_effect`
8. `concurrent_publish_displays_lock_error_toast`

**AC5.3 — Tests Feature permission `WpkgDeploymentPagePermissionTest`**
4 cas iso 16.5 : 200 admin / 403 user / 403 unauthenticated / 403 route middleware HTTP.

**AC5.4 — Tests Feature commande artisan `WpkgGpoSyncCommandTest` (`tests/Feature/Console/WpkgGpoSyncCommandTest.php`)**
Au moins **5 tests** :
1. `outputs_table_by_default_with_ok_exit_code`
2. `audit_only_does_not_call_publish`
3. `force_calls_publish_with_force_true`
4. `json_outputs_serialized_dto`
5. `exits_with_code_2_on_error_severity`

**AC5.5 — Test architecture `GpoNamespaceTest` enrichi**
Ajouter assertions :
- `App\Gpo\Services\WpkgGpoSynchronizer` n'importe pas `LdapRecord\*` direct
- Pas de `exec()`/`shell_exec()`/`passthru()` dans le fichier
- Tout appel à un service legacy (`import_gpo`, `specialise_gpo`) passe par `app('legacy.*')` binding container (pas de `require_once` direct).

**AC5.6 — Doc QA `docs/qa/domains/gpo.md` section 8**
**Given** la story est implémentée
**When** la review passe
**Then** une nouvelle **section 8** est ajoutée dans `docs/qa/domains/gpo.md` (append-only, iso pattern 16.5/16.7) :
- 8.1 → 8.N scénarios smoke VM (action Henri post-dev) : ≥ **6 scénarios** dont :
  - Smoke audit initial sur VM (vérifier que `audit()` détecte la GPO existante)
  - Smoke publish initial (premier déploiement)
  - Smoke publish forcé (re-import sur GPO déjà à jour)
  - Smoke poste Windows réel (`gpupdate /force` + `cscript wpkg.js /server=...` + vérifier hit endpoint `/wpkg/hosts.xml`)
  - Smoke Bearer manquant (poste actif sans secret → warning visible UI)
  - Smoke échec template absent (renommer `se4_wpkg.zip` → erreur explicite UI).
**And** section dédiée dans `app/Gpo/README.md` (URL prevue, classes, catalogue `action_type` enrichi 4 entrées).
**And** cross-ref dans `app/Wpkg/Deployment/README.md` (mention « Hook GPO côté postes = Story 16.6 `WpkgGpoSynchronizer` »).

**AC5.7 — Aucune régression**
**Given** la suite globale tourne
**When** elle s'exécute
**Then** aucun test pré-existant ne casse (4.7, 4.8, 15.1-15.5, 16.1-16.7).

---

## Hors-scope (explicite)

- **Génération from scratch d'un script `.cmd` startup WPKG** (réécriture d'un script SYSVOL au lieu d'utiliser le template officiel) → Epic 17.1.
- **UI Livewire d'édition du `.cmd` startup** (Monaco editor) → Epic 17.2.
- **Modèle Eloquent `WindowsScript` + versioning NETLOGON** → Epic 17.1.
- **Création/duplication/suppression d'autres GPOs** (16.6 ne traite QUE `se4_wpkg`) → Story 16.4 paused.
- **Liaison GPO ↔ OU/parc** (= Story 16.5) — 16.6 n'auto-link pas la GPO publiée (DO4 par défaut).
- **Provisioning de secrets Bearer** (= commande `wpkg:provision-secrets` de Story 15.5) — 16.6 lit la couverture en lecture seule.
- **Portage natif `import_gpo`/`specialise_gpo`/`list_gpo_templates`** → reste shimé (D6 + 16.4 paused).
- **Frontière Epic 17 stricte** : 16.6 = configuration GPO (jonction WPKG). Aucun couplage Epic 17.
- **Provisioning du dépôt `wpkg.js` côté serveur** (= où est hébergé le binaire wpkg.js côté NETLOGON) — out-of-scope hors Reload.
- **`packages.xml` natif** : 16.6 ne touche pas à `packages.xml` (servi côté legacy actuellement — out-of-scope, possible Story 15.x future).
- **Tests E2E navigateur** — iso 16.5.

---

## Tasks / Subtasks

### Phase T0 — Investigation legacy + décisions ouvertes

- [x] **T0.1** **DO1** Extraire le contenu du template `/usr/share/sambaedu/gpo/se4_wpkg.zip` (action Henri sur VM ou récup artefact). Lister :
  - les fichiers `.cmd`/`.bat` startup
  - les placeholders `###_*_###` utilisés
  - vérifier la présence ou non d'un placeholder URL WPKG explicite (vs implicite via `###_SE4FS_NAME_###` only)
  - documenter en T0 dans la story sous « T0.1 — Output audit `se4_wpkg.zip` »
- [x] **T0.2** **DO2** Vérifier l'état actuel Story 15.5 (review) — middleware `WorkstationBearerAuth` actif par défaut ou pas ? Si `review` strict → option par défaut `bearer_required = false`. Confirmer feature flag `config('sambaedu.gpo.wpkg_sync.bearer_required')`.
- [x] **T0.3** **DO3** Vérifier comportement `import_gpo` legacy : atomique ou best effort ? Si best effort → confirmer pattern lock + log critique mid-failure (iso 16.5 DO1). Documenter TD-16.6-1.
- [x] **T0.4** **DO4** Confirmer décision « pas d'auto-link au publish » : séparation responsabilité 16.6 vs 16.5. Vérifier que l'UI affiche bien le CTA « Lier maintenant » → `/app/gpo/{guid}/links` (16.5 done en review).
- [x] **T0.5** Vérifier si `config('sambaedu.wpkg.base_url')` est défini (cf. `config/sambaedu.gpo.applications.substitutions.php:67`). Si non → décider si la story l'ajoute ou si l'URL est résolue uniquement via `URL::route('wpkg.hosts-xml', [], absolute: true)`. Par défaut : passer via `route()` (D2) — pas besoin d'ajouter `base_url`.
- [x] **T0.6** Inspecter la signature `import_gpo($config, $displayname, $gpo_archive, $update, $force)` dans `legacy/gpo.inc.php` (shim 1bis-18a/b). Vérifier les retours (`true`/`false`/exception ?) + side effects (smbclient, samba-tool gpo create, SYSVOL writes).
- [x] **T0.7** Inspecter `specialise_gpo($config, $source_path)` et les placeholders qu'il connaît. Vérifier compatibilité avec `App\Config\SambaEduConfig` (clés `se4fs_name`, `domain`, `samba_domain`, `domain_sid`, `ldap_base_dn`).
- [x] **T0.8** Vérifier que `GpoService::list()` (16.1) retourne bien le champ `displayname` (pour filtrer `=== 'se4_wpkg'`). Si absent → enrichir la lecture (devrait être OK iso 16.2).
- [x] **T0.9** Vérifier la jointure `workstations` ↔ `workstation_api_secrets` (table créée par Story 15.5) — fallback si table inexistante (mode info, pas error).
- [x] **T0.10** Documenter résultat T0 en bas de la story (section « T0 Findings ») + trancher DO1-DO4 si nouvelles infos.

### Phase T1 — DTO + enum

- [x] **T1.1** Créer `app/Gpo/Enums/WpkgGpoSyncSeverity.php` (enum `ok`/`warning`/`error`).
- [x] **T1.2** Créer `app/Gpo/Dto/WpkgGpoSyncReport.php` (`final readonly class` avec props strictes typées — voir AC1.1).
- [x] **T1.3** Tests Unit `tests/Unit/Gpo/Dto/WpkgGpoSyncReportTest.php` (structure immutable, sérialisation JSON, factory `fromArray`).

### Phase T2 — Service `WpkgGpoSynchronizer::audit`

- [x] **T2.1** Créer `app/Gpo/Services/WpkgGpoSynchronizer.php` (`final class` — non final si test mockabilité requise, iso pattern 16.7 `AdMachineManager`).
- [x] **T2.2** Implémenter `audit(): WpkgGpoSyncReport` (AC1.1-AC1.6) :
  - DI : `GpoService`, `SambaEduConfig`, helper `app('legacy.import_gpo')` binding (pattern 16.3c).
  - Lecture FS `templatePath` via `realpath()` (sécurité) — restreindre à `/usr/share/sambaedu/gpo/`.
  - Parse simple du `.cmd` extrait (regex `/###_([A-Z_]+)_###/g` pour lister placeholders — pas d'unzip natif dans cette phase : si T0.1 demande, ajouter `ZipArchive` PHP).
- [x] **T2.3** Tests Unit `WpkgGpoSynchronizerTest::audit_*` (≥ 6 tests, cf. AC5.1).

### Phase T3 — Service `WpkgGpoSynchronizer::publish`

- [x] **T3.1** Implémenter `publish(bool $force): WpkgGpoSyncReport` (AC2.1-AC2.6) :
  - Lock applicatif `Cache::lock('gpo:wpkg:sync', 60)->block(10)`.
  - Appel séquentiel `specialise_gpo` → `import_gpo` via shim binding container.
  - Logging `gpo.wpkg.template.spec` + `gpo.wpkg.publish` (channel `gpo`).
  - Re-audit final pour DTO retourné.
- [x] **T3.2** Tests Unit `WpkgGpoSynchronizerTest::publish_*` (≥ 4 tests, cf. AC5.1).
- [x] **T3.3** Binding container `legacy.import_gpo` / `legacy.specialise_gpo` dans `app/Providers/AppServiceProvider.php` (ou `LegacyShimServiceProvider` existant) — fallback `require_once 'legacy/bootstrap.php'`.

### Phase T4 — Page Livewire SFC

- [x] **T4.1** Créer `resources/views/pages/app/gpo/wpkg-deployment/index.blade.php` (SFC inline `new #[Title] class extends Component` iso 16.5).
- [x] **T4.2** Route `routes/web.php` AVANT catchall (ligne 437) :
  ```php
  Route::livewire('/app/gpo/wpkg-deployment', 'pages::app.gpo.wpkg-deployment.index')
      ->middleware(['web', 'auth', 'can:server.admin'])
      ->name('app.gpo.wpkg-deployment');
  ```
- [x] **T4.3** Composant Livewire :
  - `boot(WpkgGpoSynchronizer $sync)` DI
  - `mount()` `abort_unless(can('server.admin'), 403)`
  - Propriétés `public ?WpkgGpoSyncReport $report = null;`
  - Méthodes `refresh()` → `audit()`, `openPublishModal()`, `confirmPublish(bool $force)` → `publish` + `WithToasts::toastSuccess/toastError`
  - Trait `WithToasts`
- [x] **T4.4** Vue : 4 tableaux + badge sévérité + CTA principal + modale `<x-molecules.modal>` (D5) + CTA secondaire « Re-auditer ».
- [x] **T4.5** Tests Feature `WpkgDeploymentPageTest` (≥ 8 tests, cf. AC5.2) — `FakesGpoService` builder fluide enrichi pour `se4_wpkg`.
- [x] **T4.6** Tests Feature permission `WpkgDeploymentPagePermissionTest` (4 cas, cf. AC5.3).

### Phase T5 — Commande artisan

- [x] **T5.1** Créer `app/Wpkg/Deployment/Console/Commands/WpkgGpoSyncCommand.php` (extends `Illuminate\Console\Command`, signature `wpkg:gpo:sync {--audit-only} {--force} {--json}`, registration via `app/Console/Kernel.php` ou auto-discovery namespace).
- [x] **T5.2** Logique `handle()` : DI `WpkgGpoSynchronizer`, switch sur options, exit code selon severity (AC3.4).
- [x] **T5.3** Tests Feature `tests/Feature/Console/WpkgGpoSyncCommandTest.php` (≥ 5 tests, cf. AC5.4) — utilise `Artisan::call(...)` + assertions sur exit code + output table.

### Phase T6 — Sécurité + architecture

- [x] **T6.1** Test architecture `GpoNamespaceTest::it_forbids_ldaprecord_in_wpkg_synchronizer` + `it_forbids_exec_in_wpkg_synchronizer` (AC5.5).
- [x] **T6.2** Test feature `WpkgGpoSynchronizerTest::it_blocks_concurrent_publish_with_cache_lock` (mock `Cache::lock`).
- [x] **T6.3** Validation `realpath()` sur template path (sécurité défense en profondeur — cf. AC1.4 + T2.2).
- [x] **T6.4** Logging `gpo.wpkg.sync.start` `level=debug` + `gpo.wpkg.sync.end` `level=info` channel `gpo` avec `operation_id` propagé (AC4.3).

### Phase T7 — Documentation

- [x] **T7.1** Section 8 `docs/qa/domains/gpo.md` append-only (≥ 6 scénarios smoke VM, AC5.6).
- [x] **T7.2** Section dédiée Story 16.6 dans `app/Gpo/README.md` (URL prevue, tableau classes, catalogue `action_type` enrichi 4 nouvelles entrées, frontière Epic 15/17).
- [x] **T7.3** Cross-ref dans `app/Wpkg/Deployment/README.md` (« Hook GPO côté postes Windows = Story 16.6, voir `App\Gpo\Services\WpkgGpoSynchronizer` »).
- [x] **T7.4** Entrées `docs/tech-debt-gpo.md` :
  - TD-16.6-1 : `import_gpo` best effort + pas de rollback automatique (cf. AC2.6).
  - TD-16.6-2 : Shim `legacy/bootstrap.php` `import_gpo`/`specialise_gpo` non portés natifs (Story 16.4 paused).
  - TD-16.6-3 : Bearer Phase 2 mode tolérant par défaut (`bearer_required = false`) — bumper post 15.5 done.

### Phase T8 — Smoke VM (action Henri)

- [ ] **T8.1** Smoke audit initial : `php artisan wpkg:gpo:sync --audit-only` sur VM réelle — vérifier que la GPO `se4_wpkg` existante est détectée + linkedOus peuplé.
- [ ] **T8.2** Smoke publish forcé : `php artisan wpkg:gpo:sync --force` — vérifier re-import SYSVOL + log audit channel `gpo`.
- [ ] **T8.3** Smoke poste Windows réel : `gpupdate /force` sur un poste lié + observer si `cscript wpkg.js /server=<SE4FS_NAME>` se déclenche au reboot + traces NGINX hit sur `/wpkg/hosts.xml?poste={hostname}` et `/wpkg/profiles.xml?poste={hostname}`.
- [ ] **T8.4** Smoke Bearer manquant : créer un poste sans Bearer (révoquer via `wpkg:rotate-secret`) → vérifier que `WpkgGpoSyncReport` le signale dans `bearerCoverage`.
- [ ] **T8.5** Smoke template absent : renommer `/usr/share/sambaedu/gpo/se4_wpkg.zip` → vérifier message d'erreur explicite UI.
- [ ] **T8.6** Smoke concurrence : 2 sessions admin lancent `publish` simultanément → vérifier que la 2nde reçoit erreur lock.

---

## Risks

| #   | Risque                                                                                                        | Mitigation                                                                                                                                                       |
|-----|---------------------------------------------------------------------------------------------------------------|------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| R1  | T0.1 révèle que le `.cmd` startup de `se4_wpkg.zip` contient une URL **codée en dur** au lieu d'un placeholder — la spécialisation `specialise_gpo` ne corrige rien. | Plan B = ajouter une étape de **patch ad hoc du `.cmd`** post-unzip avant import (regex `s|http://.*?/wpkg/|http://###_SE4FS_NAME_###/wpkg/|`). À documenter TD-16.6-X. |
| R2  | `import_gpo` legacy fail silencieux (return `false` sans exception)                                            | Wrapper de la fonction shim : si return falsy → lever `RuntimeException` explicite + log critical. Pattern iso 16.3b `create_ad_user` wrapping `AdUserManager`.   |
| R3  | Story 15.5 régresse de `review` à `wip` → middleware `WorkstationBearerAuth` désactivé temporairement         | Feature flag `bearer_required = false` par défaut (DO2 + AC4.4). Smoke VM Henri peut basculer en mode `true` selon réalité Phase 2.                               |
| R4  | Le template officiel `/usr/share/sambaedu/gpo/se4_wpkg.zip` est **absent** sur la VM courante                  | Détection explicite `templateExists` + message d'erreur clair UI. Pas de fallback automatique. Henri peut alors copier le template depuis un autre serveur SE4FS. |
| R5  | Test `audit_aggregates_bearer_coverage_by_linked_ou` est lent (jointure Eloquent sur N postes liés)           | Pagination ou cap (par ex. limiter à 100 premiers postes pour le calcul agrégé + message « Y postes au total » si > 100). À mesurer en T2.2.                     |
| R6  | Conflit avec encart « Création GPO paused » (16-5 D11) qui redirige vers shim `gpo/gpo-maj.php`                | Documenter clairement : 16.6 = UI dédiée GPO `se4_wpkg` (intégration WPKG) ; `gpo/gpo-maj.php` shim = toutes les autres GPOs (templates Git génériques). Pas de chevauchement fonctionnel. |
| R7  | DO1 révèle multiple placeholders non whitelistés → bloque T2.2 audit                                          | Plan B = élargir le whitelist `config/sambaedu.gpo.applications.substitutions.php` (Story 16.7 owner — additif accepté). Coordonner via review.                  |

---

## Dépendances détaillées (cross-référence)

| Story / Epic | Status            | Action requise pour démarrer 16.6 dev | Bloquant strict ? |
|--------------|-------------------|----------------------------------------|-------------------|
| **15.2**     | done ✅           | Aucune — endpoints stables             | Non (déjà acquis) |
| **15.5**     | review            | Aucune — feature flag tolérant (DO2)   | **Non**           |
| **16.1**     | review            | Aucune — `GpoService` lecture stable   | Non               |
| **16.2**     | review            | Aucune — pattern UI déjà posé          | Non               |
| **16.3b**    | review            | Aucune — pattern Service drift recovery posé | Non         |
| **16.5**     | review            | Aucune — `GpoService::setLink` posé    | Non               |
| **16.7**     | review            | Aucune — whitelist substitutions posée | Non               |
| **1bis-18a/b** | done/review     | Aucune — shim `import_gpo` fonctionnel | Non               |
| **Epic 17.1**| backlog not-ready | Aucune — frontière nette (cf. hors scope) | Non            |

**Conclusion** : aucune dépendance bloquante stricte. Story 16.6 peut démarrer
en parallèle de la finalisation `review → done` de 15.5/16.1/16.5. Le dev peut
commencer par T0 (audit `se4_wpkg.zip`) immédiatement.

---

## Change Log

| Date | Auteur | Description |
|---|---|---|
| 2026-05-13 | SM claude-opus-4-7 (1M context) | Création initiale story 16-6, status backlog → ready-for-dev. 10 décisions SM tranchées (D1-D10), 4 discrepances ouvertes (DO1-DO4), 5 volets ACs, 9 phases T0-T8, ~30+ tests, recommandation modèle dev opus. |
| 2026-05-13 | dev claude-opus-4-7 (1M context) | Implémentée (ready-for-dev → review). T0 DO1-DO4 tranchées par défaut documenté. 10 fichiers créés + 5 modifiés. 43 nouveaux tests (15 Unit Synchronizer + 9 Unit DTO + 12 Feature page/permission + 7 Feature commande + 2 Architecture). 0 régression baseline. Action Henri T8 = 10 scénarios smoke VM section 8 QA doc. |

---

## Dev Agent Record

### Agent Model Used

(à remplir par le dev)

### Debug Log References

(à remplir par le dev)

### Completion Notes List

(à remplir par le dev)

### File List

(à remplir par le dev — fichiers créés/modifiés)

---

## Recommandation Modèle Dev

**Modèle recommandé** : **opus** (4.7 1M context).

**Justification** :

1. **T0 critique avec discrepance ouverte forte (DO1)** : audit du contenu réel
   de `se4_wpkg.zip` côté legacy implique une lecture/analyse adversariale
   (placeholders implicites, scripts `.cmd` possiblement codés en dur,
   compatibilité `specialise_gpo`). Erreur d'audit → URL pointée incorrecte côté
   postes = parc entier inerte. Confiance élevée requise.
2. **Cohérence cross-stack** : 7 sous-systèmes interagissent (`GpoService` 16.1,
   endpoints 15.2, auth Bearer 15.5, shim `import_gpo` legacy, config
   `SambaEduConfig`, whitelist substitutions 16.7, channel logs `gpo`). Toute
   incompatibilité entre eux (par ex. nouveau placeholder `WPKG_HOSTS_XML_URL`
   manquant) = silent failure côté poste.
3. **Pattern Service Drift recovery** (iso `ReadUserManager` 16.3b) : signature
   `audit() / publish()` + DTO immutable + lock + rollback best effort. Pattern
   éprouvé mais demande rigueur orchestration.
4. **Sécurité défense en profondeur** : binding container shim legacy + lock
   anti-race + permission Spatie + validation `realpath()` template + audit log
   structuré. Aucun de ces points n'est nouveau individuellement (16.3b/16.5/16.7
   en couvrent chacun un), mais leur combinaison demande attention.
5. **Frontière Epic 17 explicite** : ne pas dériver vers un éditeur de scripts
   Windows (= 17.2) sous prétexte d'optimisation. Discipline produit.
6. **Confiance smoke VM** : T8.3 « poste Windows réel cscript wpkg.js » =
   validation critique parc — un dev qui s'écarterait du périmètre risquerait
   d'introduire une régression silencieuse non détectée tant qu'Henri ne fait
   pas le smoke.

**Cadre objectif** : 4 jours. **Recadrage 5-6j** possible si T0.1 révèle que le
template `se4_wpkg.zip` demande patch ad hoc (R1) ou si DO2 force déploiement
Phase 2 Bearer strict (R3).

---

## T0 Findings (rempli par dev claude-opus-4-7, 2026-05-13)

### T0.1 — Audit template `se4_wpkg.zip` (DO1)

**Contrainte worktree** : pas d'accès VM, et aucune copie de `se4_wpkg.zip`
dans le repo `gpo/` ni dans `legacy/`. Analyse menée via le **code legacy
canonique** `/home/htouchard/code/irundo/se4/sources/var/www/sambaedu/includes/gpo.inc.php`
(la fonction `specialise_gpo()` lignes 615-688 est la source de vérité sur
les placeholders gérés).

`specialise_gpo()` connaît un set fixe de 8 clés `$params` (lignes 621-630) :

```php
$params = ["domain", "samba_domain", "se4fs_name", "se4ad_name",
          "domain_sid", "se4install_name", "ldap_base_dn", "cloud_name"];
```

→ La spécialisation côté legacy remplace `###_DOMAIN_###`,
`###_SAMBA_DOMAIN_###`, `###_SE4FS_NAME_###`, `###_SE4AD_NAME_###`,
`###_DOMAIN_SID_###`, `###_SE4INSTALL_NAME_###`, `###_LDAP_BASE_DN_###`,
`###_CLOUD_NAME_###` dans tous les fichiers texte du dossier extrait
(et dans les `.pol` binaires via `read_pol`/`write_pol`).

**Aucun placeholder URL WPKG explicite** (`###_WPKG_URL_###`,
`###_WPKG_HOSTS_XML_###`, etc.). L'URL serveur est implicite via
`###_SE4FS_NAME_###` — le `.cmd` startup contient `cscript wpkg.js
/server=###_SE4FS_NAME_###` et le client wpkg.js construit lui-même
`http://###_SE4FS_NAME_###/wpkg/hosts.xml` et `profiles.xml`.

**Décision DO1 résolue** : on **étend** la whitelist `applications` 16.7
avec les 8 clés natives de `specialise_gpo` au moment du scan
placeholders (fusion in-memory dans `WpkgGpoSynchronizer::diffWhitelist`).
Le scan compare aux 2 listes fusionnées. Si une future version de
`se4_wpkg.zip` introduit un placeholder inconnu (par ex. `WPKG_URL`), il
sera signalé via `unknownPlaceholders` + severity `error` — décision
admin de l'ajouter au whitelist ou de corriger le template.

**Action Henri T8.1** : sur la VM, extraire `unzip -l /usr/share/sambaedu/gpo/se4_wpkg.zip`
puis `unzip -p .../se4_wpkg.zip "Machine/Scripts/Startup/*.cmd"` pour
confirmer la liste réelle des placeholders. Si nouveau placeholder
détecté → ajouter à la whitelist
`config/sambaedu.gpo.applications.substitutions.php` (additif accepté).

### T0.2 — État Phase 2 Bearer 15.5 (DO2)

Vérification dans le repo : **aucune trace** de `WorkstationApiSecret`,
`workstation_api_secrets`, ni `WorkstationBearerAuth` dans `app/Wpkg/`,
`app/Http/Middleware/` ou `database/migrations/`. La Phase 2 Bearer
(annoncée en Story 15.5 review) n'est **pas encore** côté repo `gpo/` —
elle vit potentiellement dans une autre branche / feature flag pré-merge.

**Décision DO2 résolue** : default `sambaedu.gpo.wpkg_sync.bearer_required
= false` (mode tolérant). Le synchronizer détecte gracieusement l'absence
de la table via `Schema::hasTable('workstation_api_secrets')` → DTO
`bearerTableAvailable = false`, severity inchangée. Quand 15.5 sera
mergée et `bearer_required = true` activé, la couverture bumpera à
`warning` (≤10% manquant) ou `error` (>10% manquant). TD-16.6-3
documentée pour le suivi post-15.5 done.

### T0.3 — Atomicité `import_gpo` (DO3)

Inspection de `legacy/sources/.../gpo.inc.php:956+` : `import_gpo`
enchaîne `unzip_gpo` → `specialise_gpo` → `sysvol_put` (smbclient) →
update attributs AD → cleanup. Pas de transaction, pas de rollback. Si
`sysvol_put` échoue après `specialise_gpo`, SYSVOL contient un état
intermédiaire.

**Décision DO3 résolue** : best effort + lock applicatif
`Cache::lock('gpo:wpkg:sync', 60)->block(10)` anti-race + log
`level=critical` channel `gpo` sur échec mid-publish + `RuntimeException`
propagée (AC2.6). Pas de rollback automatique. Pattern iso 16.5 DO1.
TD-16.6-1 documentée.

### T0.4 — Auto-link OUs (DO4)

**Décision DO4 confirmée** : pas d'auto-link au publish. La GPO publiée
mais non liée affiche en UI un encart `alert-warning` "GPO non liée —
aucun poste ne déclenchera `wpkg.js`" + CTA "Lier maintenant" qui
redirige vers `/app/gpo/{guid}/links` (page Story 16.5).

### T0.5-T0.10 — Synthèse autres findings

- **T0.5** : `config('sambaedu.wpkg.base_url')` non utilisé en 16.6 — D2
  passe par `URL::route('wpkg.hosts-xml', [], absolute: true)`.
- **T0.6** : `import_gpo` retourne `null/void` en succès, `false` en
  échec. Wrapper synchronizer lève `RuntimeException` sur `false`.
- **T0.7** : `SambaEduConfig::get('domain'|'samba_domain'|...)` couvre
  les 8 clés `specialise_gpo` sans adaptateur.
- **T0.8** : `GpoService::list()` retourne bien `displayname` — filtrage
  `=== 'se4_wpkg'` fonctionne.
- **T0.9** : Fallback `Schema::hasTable` gère l'absence de
  `workstation_api_secrets` sans erreur.
- **T0.10** : Toutes les DO résolues. Cadre 4j tenu, pas de R1 patch
  ad hoc (pas d'URL en dur dans le `.cmd`).

---

## Dev Agent Record

### Agent Model Used

**claude-opus-4-7** (1M context) — Anthropic Claude Opus 4.7.

### Debug Log References

- Phase T0 : analyse statique du legacy
  `/home/htouchard/code/irundo/se4/sources/var/www/sambaedu/includes/gpo.inc.php`
  (`import_gpo:956+`, `specialise_gpo:615-688`, `list_gpo_templates:818`).
- Pas d'accès VM (worktree) → action Henri T8.1 documentée pour confirmer
  la liste finale des placeholders dans `se4_wpkg.zip`.
- Piège PHP relevé pendant le dev des tests : `app()->bind('legacy.*',
  fn () => function() use (&$captured) { ... })` ne capture **pas** la
  variable par référence car l'arrow function `fn () =>` capture by
  value. Solution : utiliser `function () use (&$captured) { return
  function() use (&$captured) { ... }; }` au niveau outer factory.

### Completion Notes List

1. **T0 Findings** : tous tranchés sur leur option par défaut (DO1 :
   8 placeholders natifs `specialise_gpo` + URL implicite via
   `SE4FS_NAME` ; DO2 : `bearer_required=false` tolérant + Schema
   detection ; DO3 : best effort + lock + critical log ; DO4 : pas
   d'auto-link).
2. **Architecture native** :
   - 1 service : `App\Gpo\Services\WpkgGpoSynchronizer` (~430 lignes).
   - 1 enum : `App\Gpo\Enums\WpkgGpoSyncSeverity` (4 cas + helpers).
   - 1 DTO : `App\Gpo\Dto\WpkgGpoSyncReport` (`final readonly class`).
   - 1 commande artisan : `App\Wpkg\Deployment\Console\Commands\WpkgGpoSyncCommand`.
   - 1 page Livewire SFC : `pages::app.gpo.wpkg-deployment.index`.
3. **Binding container shim legacy** : résolution dynamique
   `app()->bound('legacy.import_gpo')` / `legacy.specialise_gpo` avec
   fallback fonction PHP globale chargée par `legacy/bootstrap.php`. Le
   shim étant chargé inconditionnellement par le bootstrap, aucun
   provider dédié n'a été créé (T3.3 simplifié).
4. **Modale `<x-molecules.modal>`** sur action publish (D5) avec checkbox
   `forceFlag` (équivalent `--force`).
5. **Mode dégradé scan placeholders** : le synchronizer accepte un path
   **répertoire** en plus du `.zip` pour les tests host sans `ext-zip`
   (les tests créent un fixture déballé). En production VM, le path est
   bien le `.zip` (`ext-zip` activée).
6. **Tests** : **43 nouveaux tests** : 15 Unit Synchronizer + 9 Unit DTO
   + 8 Feature page + 4 Feature permission + 7 Feature commande + 2
   Architecture nouveaux. **0 régression** sur les tests baseline
   (`GpoServiceTest`/`GpoServiceWriteTest` 33/33 passent — les 2 échecs
   `GpoNamespaceTest` `no_shell_execution_outside_samba_tool_runner` +
   `it_uses_process_in_array_mode_in_generate_wine_image_job` sont
   **pré-existants** documentés en 16.5/16.3c — non liés à 16.6).
7. **Pas de migration Laravel** : 16.6 ne crée aucune table — toute
   l'info vit dans AD/SYSVOL/template ZIP.
8. **Configuration optionnelle (Henri)** : pour activer Bearer strict
   post-15.5 done, ajouter `'wpkg_sync' => ['bearer_required' => true]`
   dans un `config/sambaedu.gpo.php` (à créer ou enrichir). Pas de
   migration BDD requise pour cette bascule.

#### Post-review corrections (2026-05-13)

Corrections automatiques post-review (claude-opus-4-7, review
adversariale sonnet) appliquées suite à décision Henri :

| # | Sévérité | Correction | Justification |
|---|----------|------------|---------------|
| #3 | Critique | **Suppression de `invokeSpecialise()`** + binding container `legacy.specialise_gpo` + adaptation tests architecture/Unit/Feature | TD-16.6-1 confirme que `import_gpo` enchaîne déjà `unzip_gpo → specialise_gpo → sysvol_put`. L'appel séparé spécialisait `/tmp/<gpo>/` puis se faisait écraser par le tarball brut → no-op coûteux et trompeur. |
| #10 / #4 | Élevée | Bump `LOCK_TIMEOUT_SECONDS` 60→300 + `LOCK_WAIT_SECONDS` 10→30 + rendre configurable via `config('sambaedu.gpo.wpkg_sync.lock_timeout')` / `.lock_wait` | Un `import_gpo` complet (extraction + spécialisation + `smbclient put` SYSVOL) peut excéder 60 s en VM modeste. Garder un TTL court risquait de libérer le lock alors qu'un publish était encore en cours. |
| #1 | Moyenne | Déclaration explicite de la sous-section `wpkg_sync` dans `config/sambaedu.php` (4 clés env-overridables : `template_path`, `bearer_required`, `lock_timeout`, `lock_wait`) | Clés lues par le code sans support config — déploiement Ansible / tuning prod sans patch code. |
| #2 | Moyenne | Déplacement `Route::livewire('/gpo/wpkg-deployment')` AVANT `/gpo/{guid}` | Cohérence avec les autres routes statiques de l'epic (16.3c `/gpo/wine`). La regex GUID ne match pas `wpkg-deployment`, mais on rend l'ordre explicite. |
| #8 | Moyenne | 3 nouveaux tests Unit `auditBearerCoverage` (OK 100%, partiel tolérant, error required) | Branche `Schema::hasTable('workstation_api_secrets')=true` jamais couverte. |
| #C | Moyenne | `MAX_ZIP_FILES=1000` + `MAX_ZIP_ENTRY_BYTES=10 Mo` sur `scanTemplatePlaceholders` + log warning sur skip | Défense en profondeur contre ZIP corrompu / bomb. |
| #D | Faible | Log warning `gpo.wpkg.template.scan` quand `mb_convert_encoding(..., UTF-16LE)` échoue + fallback brut | Détection silencieuse impossible aujourd'hui — visibilité opérationnelle. |
| #F | Faible | Log warning `gpo.wpkg.bearer.audit` + message DTO lorsque la limite 200 workstations/OU est atteinte | Couverture Bearer affichée non-exhaustive — signaler explicitement. |
| #H | Faible | Test `publish_runs_initial_when_gpo_absent` ré-écrit : assert explicite sur l'invocation du shim ET sur `severity::Error` post-audit, sans swallow `Throwable` | Le `try/catch \Throwable` masquait les vraies erreurs du test. |

Non-actions tranchées :
- **#5 (log level `info`)** : conservé iso Epic 16.
- **#6 (mount-only iso 16.5)** : aucun changement requis.
- **#E (test path-traversal hors testing)** : laissé en TD — refacto
  invasive requise pour injecter l'environnement, pattern à généraliser
  plus tard (non bloquant 16.6).

### Action Henri T8 — Smoke VM (10 scénarios)

Documentés dans `docs/qa/domains/gpo.md` **Section 8** :
- **T8.1** : Audit CLI initial sur VM réelle + confirmer liste réelle
  des placeholders dans `se4_wpkg.zip` (action critique DO1).
- **T8.2** : Audit UI Livewire (badge OK + 4 tableaux).
- **T8.3** : `gpupdate /force` + reboot poste Windows + vérifier hit
  `/wpkg/hosts.xml` côté nginx access log (smoke critique parc-wide).
- **T8.4** : Couverture Bearer (post-Phase 2 mergée seulement).
- **T8.5** : Template renommé → erreur explicite.
- **T8.6** : Lock concurrence 2 sessions admin.
- **T8.7-T8.10** : warnings, JSON parsable, permission 403, exit codes.

### Régressions pré-existantes observées (non bloquantes)

- `GpoNamespaceTest::no_shell_execution_outside_samba_tool_runner` →
  échec sur `NetworkScriptGenerator.php` et `ApplicationScriptsGenerator.php`
  (documenté en 16.5/16.7 baseline).
- `GpoNamespaceTest::it_uses_process_in_array_mode_in_generate_wine_image_job`
  → échec sur `GenerateWineImageJob.php` (documenté en 16.3c baseline).
- `tests/Unit/Gpo/ReadUserManagerTest`, `tests/Unit/Gpo/SubstitutionsTest`
  → 8 errors Mockery final class (documentés en 16.7 baseline — env CI
  sans `uopz`/`runkit`).

### File List

**Fichiers créés (10)** :
- `app/Gpo/Enums/WpkgGpoSyncSeverity.php`
- `app/Gpo/Dto/WpkgGpoSyncReport.php`
- `app/Gpo/Services/WpkgGpoSynchronizer.php`
- `app/Wpkg/Deployment/Console/Commands/WpkgGpoSyncCommand.php`
- `resources/views/pages/app/gpo/wpkg-deployment/index.blade.php`
- `tests/Unit/Gpo/Dto/WpkgGpoSyncReportTest.php`
- `tests/Unit/Gpo/WpkgGpoSynchronizerTest.php`
- `tests/Feature/Gpo/WpkgDeploymentPageTest.php`
- `tests/Feature/Gpo/WpkgDeploymentPagePermissionTest.php`
- `tests/Feature/Console/Wpkg/WpkgGpoSyncCommandTest.php`

**Fichiers modifiés (5)** :
- `routes/web.php` — ajout
  `Route::livewire('/gpo/wpkg-deployment', 'pages::app.gpo.wpkg-deployment.index')`
  dans le groupe `app.` (name `app.gpo.wpkg-deployment`) AVANT le catchall.
- `app/Providers/WpkgDeploymentServiceProvider.php` — enregistrement
  artisan `WpkgGpoSyncCommand::class` (commande non auto-discoverable
  car hors `app/Console/Commands`).
- `tests/Architecture/GpoNamespaceTest.php` — 2 nouveaux tests
  (`wpkg_gpo_synchronizer_respects_native_frontier` +
  `only_wpkg_gpo_synchronizer_references_legacy_import_gpo`).
- `app/Gpo/README.md` — catalogue `action_type` enrichi 4 entrées +
  nouvelle section dédiée Story 16.6.
- `docs/qa/domains/gpo.md` — Section 8 (10 scénarios smoke VM
  append-only).
- `docs/tech-debt-gpo.md` — 3 entrées TD-16.6-1/2/3.

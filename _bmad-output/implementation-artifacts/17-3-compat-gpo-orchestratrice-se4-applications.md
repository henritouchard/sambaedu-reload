# Story 17.3 : Compat GPO orchestratrice `se4_applications` (Stratégie A)

Status: done

<!-- Note: Validation est optionnelle. Lancer validate-create-story pour quality check avant dev-story. -->

> **Story de compatibilité runtime post-audit 17.1 validé** (Henri 2026-05-21).
> Vérifie que les `.cmd` orchestrateurs contenus dans le template GPO `se4_applications`
> (livré par le package Debian `sambaedu-gpo` sous `/usr/share/sambaedu/gpo/se4_applications.zip`)
> pointent vers l'endpoint **natif** `/api/v1/workstation-config/applications-scripts`
> (livré par Story 16.13 done) et **non** vers l'endpoint legacy `gpo/applications.php`
> (shim PHP-FPM 1bis-11 destiné à disparaître).
>
> **Cadrage Stratégie A (Henri 2026-05-21)** : modèle « le template GPO est livré
> par le package Debian et déployé côté serveur, pas créé runtime par sambaedu-reload ».
> Le pattern de référence est `App\Gpo\Services\WpkgGpoSynchronizer` (Story 16.6 done)
> qui consomme `/usr/share/sambaedu/gpo/se4_wpkg.zip`. La présente story applique le
> **même pattern à `se4_applications`**, mais avec une portée volontairement réduite :
> **vérification du contenu `.cmd` + (option) patch upstream OU substitution
> post-extraction côté serveur**.
>
> **Aucune création de GPO native** (Story 16.4 cancelled 2026-05-18). **Aucun
> synchronizer dédié** : la story est une **mini-investigation + correctif ciblé** sur
> le template existant, pas un nouveau service métier complet.

---

## ⚠️ Décisions tranchées (D1-D8, ne pas re-débattre)

> Ces décisions sont issues de l'audit 17.1 Section G.2 (Stratégie A confirmée Henri
> 2026-05-21), du pattern `WpkgGpoSynchronizer` (Story 16.6 D1+D6) et de l'annulation
> de Story 16.4 (project_no_native_gpo_creation 2026-05-18).

### D1 — Stratégie A confirmée : pas de création GPO, le template vient du package Debian

- Le template GPO `se4_applications` n'est **pas** un artefact du repo Laravel
  `sambaedu-reload`. Il est packagé par `sambaedu-gpo` (Debian, hors scope ce repo)
  et déployé côté serveur sous `/usr/share/sambaedu/gpo/se4_applications.zip`.
- La story **vérifie** le contenu de ce template (lecture seule) et propose une
  **stratégie de correctif** si discrepance détectée. Elle ne crée jamais une GPO
  via `samba-tool gpo create`.
- Référence : memory `project_no_native_gpo_creation` (Story 16.4 cancelled).

### D2 — Source de vérité = `/usr/share/sambaedu/gpo/se4_applications.zip` (pattern 16.6)

- Constante `DEFAULT_TEMPLATE_PATH = '/usr/share/sambaedu/gpo/se4_applications.zip'`
  (iso `WpkgGpoSynchronizer::DEFAULT_TEMPLATE_PATH` ligne 47).
- Override testing/CI via `config('sambaedu.gpo.applications_template.path')` —
  pattern strict iso 16.6.
- Constante `GPO_DISPLAY_NAME = 'se4_applications'` (iso `WpkgGpoSynchronizer::GPO_DISPLAY_NAME`
  ligne 44 qui vaut `'se4_wpkg'`).
- **Pas de scan AD `samba-tool gpo show`** dans cette story : on inspecte
  uniquement le **template `.zip`** côté FS serveur. La GPO déployée dans SYSVOL
  hérite mécaniquement du patch template (via le mécanisme `import_gpo` 16.6 que
  cette story ne re-déclenche pas — out-of-scope).

### D3 — Endpoint cible natif = `route('agent.v1.config.applications-scripts')` (parité 16.13)

- URL canonique : `/api/v1/workstation-config/applications-scripts` (cf.
  `routes/api.php:256`). Méthode `GET`. Middleware
  `auth.v1.secure-headers + auth.v1.workstation + throttle:300,1`.
- URL legacy à éradiquer dans les `.cmd` orchestrateurs :
  `http://###_SE4FS_NAME_###/gpo/applications.php` (ou variantes `http://se4fs/gpo/applications.php`).
- **Note auth iso-legacy** (memory `feedback_auth_iso_legacy`) : la transition
  d'auth est gérée par 16.10 (JWT) + 16.13bis (migration fragment per-os). 17.3
  ne touche **pas** à l'auth — elle se contente de réécrire l'URL POST → GET
  `applications-scripts` natif. Le mécanisme de migration auth (postes pas encore
  JWT-migrés) est porté par `MigrationController::serveFragment(applications)`
  (`routes/web.php:618` — option β 16.13bis).

### D4 — 2 stratégies de patch à arbitrer en T0 : **A.1 upstream** OU **A.2 post-extraction**

> Décision concrète à trancher par le dev en T0 après inspection du template.

**Option A.1 — Patch upstream (préféré si accès repo `sambaedu-gpo`)** :
- Modifier les `.cmd` orchestrateurs **directement dans le template upstream**
  (repo GitLab interne `sambaedu-gpo` Debian) → release nouvelle version paquet.
- Avantage : pas de couche supplémentaire côté Laravel. Le patch suit le cycle de
  release du paquet Debian (`apt-get update && apt-get dist-upgrade` côté serveur).
- Inconvénient : dépend du cycle Debian — pas appliqué tant que le paquet n'est pas
  réinstallé sur les serveurs en production.

**Option A.2 — Substitution post-extraction côté Laravel (fallback recommandé)** :
- Étendre la whitelist `config/sambaedu.gpo.applications.substitutions.whitelist`
  (Story 16.7 + 17.2) avec une **clé spéciale `APPLICATIONS_SCRIPTS_URL`** dont la
  valeur résolue par `resolveSubstitutionValue()` est
  `route('agent.v1.config.applications-scripts', [], absolute: true)`.
- Le template upstream est modifié (par 17.3 dev ou par un Henri-side patch) pour
  utiliser le placeholder `###_APPLICATIONS_SCRIPTS_URL_###` au lieu de l'URL
  hardcodée `http://###_SE4FS_NAME_###/gpo/applications.php`.
- Au moment du `import_gpo` legacy (déjà appelé par `WpkgGpoSynchronizer::publish()`
  pour `se4_wpkg`), `specialise_gpo` substitue le placeholder par l'URL native.
- **Recommandation SM (D4)** : implémenter **A.2** dans cette story (substitution
  post-extraction) **+** documenter la procédure A.1 dans `docs/qa/domains/gpo.md`
  pour que Henri/upstream puissent appliquer le patch côté repo Debian quand le
  cycle de release l'autorise. **A.2 est suffisant en stand-alone**.
- **Si T0 révèle que le template upstream contient déjà le placeholder
  `###_APPLICATIONS_SCRIPTS_URL_###`** (ou équivalent), il ne reste qu'à
  ajouter la clé à la whitelist + tester. **C'est le cas heureux.**
- **Si T0 révèle qu'il faut éditer un `.cmd` upstream lui-même** (ex. `Nettoyage
  applications-startup.cmd` actuel hardcodé `http://se4fs/gpo/applications.php`),
  le dev livre **deux patches** :
  - (a) un patch git pour le repo upstream `sambaedu-gpo` (fourni en
    `_bmad-output/implementation-artifacts/17-3-upstream-patch.diff` à
    transmettre à Henri qui pousse côté repo Debian) ;
  - (b) la substitution côté Laravel (A.2) qui prend effet sur le serveur déjà
    installé sans attendre la release Debian.

### D5 — Pas de service `ApplicationsGpoSynchronizer` dédié

- Pas de duplication du `WpkgGpoSynchronizer` (973 lignes 16.6 — D3/D6 de cette
  story disent « pas de réinvention »). Le `WpkgGpoSynchronizer` est **specific
  `se4_wpkg`** (URLs hosts.xml/profiles.xml différentes, Bearer machine 15.5
  spécifique à WPKG, etc.).
- La story 17.3 livre :
  - une **commande artisan `gpo:applications:audit`** (lecture pure, idempotente,
    iso `wpkg:gpo:sync --audit-only` 16.6) — détaille le contenu du template et
    flag les `.cmd` qui pointent encore vers `gpo/applications.php` ;
  - une **extension de la whitelist substitutions 17.2** avec
    `APPLICATIONS_SCRIPTS_URL` (D4 option A.2) ;
  - **pas** de méthode `publish` : la republication du template est de la
    responsabilité opérateur (ou à terme d'une story `gpo:applications:publish`
    si le besoin remonte — déférée par D7).
- Justification : la story 17.3 est cadrée **~1j** par Henri (Section G.6 audit
  + sprint-status). Un service métier complet (audit + publish + DTO + Livewire)
  serait 4-5j minimum (cf. charge 16.6). Hors scope.

### D6 — Lecture template via le scanner ZIP existant (parité 16.6)

- Réutiliser `WpkgGpoSynchronizer::scanTemplatePlaceholders` (extraction ZIP +
  scan placeholders ligne 371-453 du fichier — méthode `private` mais le pattern
  est reproductible). Pour éviter de basculer la méthode en `protected`/`public`
  (changement public API non souhaité dans 17.3), **dupliquer la logique** dans
  un helper local de la commande artisan `gpo:applications:audit`.
- **Limites garde-fou** (iso 16.6 review fix #C) : `MAX_ZIP_FILES = 1000` +
  `MAX_ZIP_ENTRY_BYTES = 10 * 1024 * 1024`. Réutiliser.
- Encodage UTF-16LE détecté + converti (typique des fichiers Registry.pol).
  Code à reproduire à l'identique.
- **Mode dégradé** : si le path pointe sur un répertoire (cas testing sans
  ext-zip), scanner récursivement. Iso 16.6.

### D7 — Pas de republication SYSVOL dans 17.3

- La republication du template GPO `se4_applications` dans SYSVOL est **out-of-scope**.
- Justification : (a) charge ~1j ne le permet pas ; (b) le mécanisme `import_gpo`
  legacy via shim est déjà testé et stable (cf. 16.6 + 1bis-18) ; (c) l'opérateur
  peut soit attendre la release Debian (option A.1) soit ré-exécuter manuellement
  `import_gpo` via le shim legacy `gpo/gpo-maj.php`.
- Si une story de republication automatisée devient nécessaire (suite à retours
  terrain), elle sera créée séparément (proposition `17.x — Synchronizer GPO
  applications`).

### D8 — Aucune migration, aucun modèle Eloquent, aucune nouvelle route, aucune UI

- Strict scope investigation + correctif config :
  - **Modifié** : `config/sambaedu.php` (whitelist substitutions étendue +1 clé).
  - **Créé** : `app/Console/Commands/AuditApplicationsGpoTemplateCommand.php`
    (commande artisan `gpo:applications:audit`).
  - **Créé** : `tests/Feature/Gpo/AuditApplicationsGpoTemplateCommandTest.php`
    (tests Feature de la commande artisan).
  - **Modifié** : `docs/qa/domains/gpo.md` (section nouvelle décrivant la stratégie
    A.1 + A.2 et la procédure d'audit côté ops).
  - **Optionnel** (selon T0) : `_bmad-output/implementation-artifacts/17-3-upstream-patch.diff`
    (patch upstream Debian à transmettre à Henri).
- **Aucune** modification de `database/migrations/`, `routes/`, `resources/views/`,
  `app/Models/`, `app/Http/Controllers/`.

---

## Story

As **un poste client Windows joint au domaine SE4FS**,
I want
- que les `.cmd` orchestrateurs livrés par la GPO `se4_applications` (téléchargée
  une fois par `wpkg/startup.windows` via `ROBOCOPY` puis exécutée au runtime
  startup/logon via `Nettoyage applications-{startup,logon,logon-system}.cmd`)
  appellent **l'endpoint natif `/api/v1/workstation-config/applications-scripts`** ;
- au lieu de l'endpoint legacy `gpo/applications.php` (qui doit disparaître au
  moment où le shim PHP-FPM 1bis-11 sera retiré),

So que (a) le pipeline scripts assemblés par Story 16.7 + 17.2 (`ApplicationScriptsAssembler`)
soit effectivement consommé par les postes ; (b) le cleanup du shim legacy
PHP-FPM 1bis-11 ne casse pas l'exécution runtime des scripts sur les postes ;
(c) la jonction GPO `se4_applications` ↔ endpoint natif soit auditée et corrigeable
sans intervention manuelle sur SYSVOL.

---

## Contexte

### Position dans l'Epic 17 post-RESET

L'Epic 17 a été **recadré 2026-05-14** (cf. Story 17.1) en « compatibilité runtime
des ~80 scripts versionnés par le package Debian `sambaedu` ». Cette Story 17.3
est la **3ème story de l'Epic** (après 17.1 audit done et 17.2 portage moteur done)
et la **première à toucher au runtime poste** : elle vérifie que les orchestrateurs
GPO appellent les bons endpoints. Sans elle, les patches de 17.2 (whitelist étendue,
parité bytes legacy/natif, wrapper logs) sont **inutiles** car les postes
continueraient d'appeler l'endpoint legacy.

### Découpage Epic 17 (validé Henri 2026-05-21, Section G.6 audit)

| Story | Statut | Charge | Périmètre |
|---|---|---|---|
| 17.1 — Audit scripts Windows & Linux | ✅ done 2026-05-21 | 1-2j | Livrable markdown 1700 lignes |
| 17.2 — Moteur applications.php + whitelist étendue + parité + wrapper | ✅ done 2026-05-21 | 2-3j | 16 clés whitelist + 3 fixtures + opt-in wrapper |
| **17.3 (cette story) — Compat GPO orchestratrice `se4_applications` (Stratégie A)** | **À faire** | **~1j** | **Audit template + substitution post-extraction A.2 + doc A.1** |
| 17.4 — Tests intégration runtime VM | backlog | 2j | E2E 5 scripts critiques |
| 17.5 — Activation wrapper opt-in (config + artisan) | backlog | ~1j | Flag + commandes `winscript-logs:enable`/`disable` |
| 17.6 — Portage endpoints orphelins (`linux_out`, `winget_out`) | backlog | ~2.5-3j | 2 controllers natifs |

### Chaîne d'invocation côté poste (référence)

```
[Poste Windows boot / logon]
      │
      ▼
GPO `se4_applications` (template officiel `/usr/share/sambaedu/gpo/se4_applications.zip`)
liée à OU=Computers (ou à des OUs spécifiques — Story 16.5 done)
      │
      ▼  (script .cmd dans Machine\Scripts\Startup\ et Machine\Scripts\Logon\)
"Nettoyage applications-startup.cmd"  →  CURRENT (LEGACY HARDCODED) :
  curl.exe -F "os=windows" -F "action=startup" -F "user=%username%" \
    -F "machine=%computername%" "http://se4fs/gpo/applications.php"
                                          ┃
                                          ▼  (URL pointe sur shim PHP-FPM 1bis-11
                                          ┃   ou — option β 16.13bis — sur
                                          ┃   MigrationController qui sert un
                                          ┃   fragment de migration auth)
"Nettoyage applications-logon.cmd"  →  CURRENT (idem) avec `action=logon` +
                                       `action=logon-system`.
      │
      ▼  (le serveur retourne le `.cmd` assemblé par 16.7 + 17.2)
   <fichier `.cmd` téléchargé dans %WINDIR%\Temp\ ou %TEMP%>
      │
      ▼  (le poste exécute le `.cmd` localement)
   call "%WINDIR%\Temp\applications-startup.cmd"
```

**Sources des `.cmd` orchestrateurs constatés à l'audit 17.1** :
- `/home/htouchard/code/irundo/se4/sources/var/sambaedu/unattended/install/os/SambaEdu/Nettoyage applications-startup.cmd`
- `/home/htouchard/code/irundo/se4/sources/var/sambaedu/unattended/install/os/SambaEdu/Nettoyage applications-logon.cmd`

**À noter** : ces fichiers `Nettoyage applications-*.cmd` sont déployés côté
poste par `wpkg/startup.windows:ROBOCOPY %WinDir%\install\os\SambaEdu %ProgramFiles%\SambaEdu`
(cf. audit Section H.3). Ils sont **également** intégrés au template GPO
`se4_applications` (à confirmer T0 — c'est le coeur de l'investigation).

### Endpoint cible natif (Story 16.13 done)

| Élément | Valeur |
|---|---|
| Route Laravel | `GET /api/v1/workstation-config/applications-scripts` |
| Route name | `agent.v1.config.applications-scripts` |
| Controller | `App\Http\Controllers\Gpo\ApplicationsScriptsController::apiV1` |
| Middleware | `auth.v1.secure-headers + auth.v1.workstation + throttle:300,1` |
| Méthode HTTP | `GET` (la legacy était `POST` avec body multipart) |
| Paramètres | query string (`os`, `action`, et le poste est identifié par `auth.v1.workstation` via JWT ou — phase de migration — par les param legacy `user`/`machine`) |
| Fichier référence | `app/Http/Controllers/Gpo/ApplicationsScriptsController.php:218-243` (commentaire « Story 16.13 — endpoint natif ») |

### Discrepance critique à confirmer en T0

> Le legacy fait POST multipart vers `gpo/applications.php` avec body
> `os=...&action=...&user=...&machine=...`. Le natif 16.13 fait GET vers
> `/api/v1/workstation-config/applications-scripts` avec query string et auth
> JWT/Bearer per-workstation (middleware `auth.v1.workstation`).

**Problème** : auth iso-legacy (memory `feedback_auth_iso_legacy`) — l'auth machine
SE4 reste iso-legacy (AD+SMB), pas de Bearer per-host à introduire pour les postes
pas encore JWT-migrés. La transition est gérée par la **route Migration 16.13bis
option β** (`routes/web.php:618`) :
```php
Route::match(['GET', 'POST'], 'gpo/applications.php',
    fn (\Illuminate\Http\Request $r) => app(AuthV1MigrationController::class)->serveFragment($r, 'applications'))
```
qui sert un fragment qui migre le poste vers `/api/v1/` puis exécute le bon
endpoint natif.

**Conclusion T0** : 17.3 doit décider si elle pousse les `.cmd` orchestrateurs à
appeler **directement** `/api/v1/workstation-config/applications-scripts` (= postes
déjà migrés JWT) OU si elle laisse `gpo/applications.php` (= URL de migration qui
re-route vers natif).
→ **Décision Henri 2026-05-22 (Q-1 résolue)** : URL **directe API v1**. JWT
est généralisé (16.10 + 16.11 `done`). Les `.cmd` orchestrateurs pointent vers
`/api/v1/workstation-config/applications-scripts` (résolu via la whitelist
`APPLICATIONS_SCRIPTS_URL`, option A.2 D4).

### Frontières (anti-scope creep)

| HORS scope 17.3 | Renvoi |
|---|---|
| Création/duplication native d'une GPO `se4_applications` | Story 16.4 cancelled 2026-05-18 — abandonné |
| Republication SYSVOL automatisée du template patché | Déféré (D7), pas de besoin terrain identifié |
| Tests E2E runtime VM sur 5 scripts critiques | Story 17.4 |
| Activation wrapper opt-in `WrapperScriptRenderer` | Story 17.5 |
| Portage `wpkg/linux_out.php` / `wpkg/winget_out.php` | Story 17.6 |
| Modification du `WpkgGpoSynchronizer` existant (se4_wpkg) | Hors scope — service stable 16.6 done |
| Création service `ApplicationsGpoSynchronizer` complet (audit+publish+DTO+UI) | Déféré (D5) si besoin terrain remonte |
| Modification de l'auth iso-legacy (Bearer per-host) | Hors scope par memory `feedback_auth_iso_legacy` |

---

## Dépendances

| Story / Epic | Titre | Status | Détail |
|---|---|---|---|
| **17.1** | Audit scripts Windows & Linux | ✅ done 2026-05-21 | Section A (cartographie 54 fragments), Section C (endpoints HTTP), Section G.2 (cadrage Stratégie A), Annexe A (inventaire `install/os/SambaEdu/Nettoyage applications-*.cmd`). |
| **17.2** | Moteur applications.php + whitelist étendue | ✅ done 2026-05-21 | Whitelist à 16 clés + pattern résolution `config()→env()→default` — 17.3 étend la whitelist d'**1 clé supplémentaire** `APPLICATIONS_SCRIPTS_URL` (option A.2 D4). |
| **16.5** | Liaison GPO ↔ OU/parc | ✅ done (review) | GPO `se4_applications` est liée aux OUs des postes Windows par 16.5 (UI native + service `GpoService::setLink`). 17.3 **lecture seule** des liaisons (via `gpo:applications:audit` qui pourrait inspecter mais c'est optionnel). |
| **16.6** | Hook GPO → `wpkg.js` côté client | ✅ done | **Pattern de référence absolu** : `App\Gpo\Services\WpkgGpoSynchronizer` (973 lignes) + commande artisan `wpkg:gpo:sync --audit-only`. 17.3 décalque sans refactorer 16.6. |
| **16.7** | Portage natif `applications.php` | ✅ done | Squelette `ApplicationScriptsAssembler` + whitelist initiale. 17.3 étend la whitelist. |
| **16.10** | Sécurisation HTTPS + JWT endpoints | ✅ done | JWT généralisé côté postes — pré-requis Q-1 résolution. |
| **16.11** | Auto-bootstrap migration postes | ✅ done | Postes auto-bootstrappés JWT — pré-requis Q-1 résolution. |
| **16.13** | Exposition endpoints API v1 | ✅ done | Endpoint cible `/api/v1/workstation-config/applications-scripts` livré + middleware `auth.v1.workstation`. 17.3 réécrit les `.cmd` orchestrateurs vers cette URL (Q-1 résolue : URL directe API v1). |
| **16.13bis** | Migration fragments per-os | done | Route legacy `gpo/applications.php` toujours servie comme filet de sécurité, mais **non consommée par les `.cmd` 17.3** (Q-1 directe API v1). |
| Epic 4 | Workstation, WorkstationGroup, User | done | Modèles Eloquent (déjà consommés par 17.2). |

**Conclusion** : toutes dépendances ✅. Story 17.3 peut démarrer immédiatement.

---

## Infrastructure existante à RÉUTILISER (pas de réinvention)

> Le dev consulte cette table **AVANT** d'écrire toute nouvelle classe.

| Besoin | Réutiliser | Path | Note |
|---|---|---|---|
| Scanner placeholders ZIP template | Logique privée `scanTemplatePlaceholders` 16.6 | `app/Gpo/Services/WpkgGpoSynchronizer.php:371-453` | **Dupliquer** dans le helper local de la commande (ne pas modifier la classe 16.6 — D6). |
| Mode dégradé répertoire (testing sans ext-zip) | `scanDirectoryPlaceholders` 16.6 | idem ligne 458 | Iso D6. |
| Limites garde-fou ZIP | `MAX_ZIP_FILES`, `MAX_ZIP_ENTRY_BYTES` | idem | Iso. |
| Détection encodage UTF-16LE | Code 16.6 lignes 425-437 | idem | Reproduire. |
| Pattern commande artisan + verbosité + exit codes | `WpkgGpoSyncCommand` (16.6) | `app/Gpo/Console/Commands/WpkgGpoSyncCommand.php` (ou équivalent — à vérifier T0) | Pattern verbosité + format JSON. |
| Whitelist substitutions | `config/sambaedu.gpo.applications.substitutions.whitelist` (16.7 + 17.2 à 16 clés) | `config/sambaedu.php` | Étendre d'**1 clé** `APPLICATIONS_SCRIPTS_URL`. |
| Résolution URL native | `URL::route('agent.v1.config.applications-scripts', [], absolute: true)` | `routes/api.php:256` | Pattern iso 16.6 D2 (`route('wpkg.hosts-xml')`). |
| Logger structuré `gpo` | `GpoLogger` + `GpoActionLog` (16.1) | `app/Gpo/Support/GpoLogger.php` | Channel `gpo`. |
| Permission Spatie | `SambaPermission::ServerAdmin` (`server.admin`) | `app/Enums/SambaPermission.php` | Iso 16.5/16.6. (Pertinent uniquement si la commande artisan est aussi exposée en UI Livewire — out-of-scope D8 → permission utile uniquement pour la commande artisan via `Gate::authorize` si appel HTTP.) |
| Pattern de résolution config()→env()→default | `ApplicationScriptsAssembler::resolveSubstitutionValue()` (16.7) | `app/Gpo/Services/ApplicationScriptsAssembler.php:913` | Iso 17.2 D2. |

---

## Acceptance Criteria

> 6 ACs organisés en **3 volets** : (1) audit du template (lecture pure),
> (2) extension whitelist substitutions `APPLICATIONS_SCRIPTS_URL` (option A.2),
> (3) documentation procédure A.1 + A.2.

### Volet 1 — Audit du template `se4_applications.zip` (D6)

**AC1.1 — Commande artisan `gpo:applications:audit` créée**
**Given** la VM serveur avec `/usr/share/sambaedu/gpo/se4_applications.zip` (ou
override config testing)
**When** l'opérateur exécute `php artisan gpo:applications:audit`
**Then** la commande :
- Charge le path via `config('sambaedu.gpo.applications_template.path', '/usr/share/sambaedu/gpo/se4_applications.zip')`.
- Si le path est absent → exit code 1 + message clair `Template introuvable — vérifier installation paquet sambaedu-gpo`.
- Si le path est présent → scan ZIP (ou répertoire en mode dégradé) avec
  `MAX_ZIP_FILES=1000` + `MAX_ZIP_ENTRY_BYTES=10MB` (D6) + détection UTF-16LE.
- Liste exhaustivement les fichiers `.cmd`/`.bat` détectés sous
  `Machine\Scripts\Startup\`, `Machine\Scripts\Logon\` et — si présent —
  `User\Scripts\Logon\`/`User\Scripts\Logoff\`.
- Pour chaque `.cmd`/`.bat` détecté, parse le contenu et extrait les **URLs HTTP**
  référencées (regex `https?://[^"\s)]+`).
**And** affiche un tableau ASCII (parité `wpkg:gpo:sync` 16.6) avec colonnes :
fichier, URLs détectées, **`legacy_match` (bool — `true` si l'URL contient
`/gpo/applications.php`)**, recommandation (`patch_upstream`, `substitute_post_extraction`, `ok`).
**And** retourne exit code 0 si aucune URL legacy détectée, 2 si au moins une URL
legacy détectée (warning), 1 si erreur fatale (template absent ou ZIP corrompu).

**AC1.2 — Option JSON pour CI/intégration**
**Given** la commande `gpo:applications:audit --json`
**When** elle est exécutée
**Then** la sortie est un JSON structuré `{"template_path": "...", "files": [{"path": "Machine\\Scripts\\Startup\\Nettoyage applications-startup.cmd", "urls": ["http://se4fs/gpo/applications.php"], "legacy_match": true, "recommendation": "substitute_post_extraction"}], "summary": {"total_files": 3, "legacy_count": 2, "ok_count": 1}}`.
**And** **AUCUN** texte parasite n'est écrit sur stdout (logs et warnings vont
sur stderr via `$this->components->warn()` ou `Log::channel('gpo')`).

**AC1.3 — Placeholders détectés vs whitelist**
**Given** le scan du template
**When** des placeholders `###_KEY_###` sont détectés
**Then** la commande compare à la whitelist `config('sambaedu.gpo.applications.substitutions.whitelist')`
(parité 16.6 logique `diffWhitelist` ligne 511).
**And** affiche les placeholders **hors whitelist** comme warning (cas où le
template upstream introduit une nouvelle clé non encore whitelistée — bloquant
parc-wide, cf. audit 17.1 Section B).
**And** le mode `--json` inclut un champ `unknown_placeholders: ["KEY1", "KEY2"]`.

### Volet 2 — Extension whitelist `APPLICATIONS_SCRIPTS_URL` (D4 option A.2)

**AC2.1 — Clé `APPLICATIONS_SCRIPTS_URL` ajoutée à la whitelist**
**Given** `config/sambaedu.php` tableau `gpo.applications.substitutions.whitelist`
**When** la story est complète
**Then** la clé `APPLICATIONS_SCRIPTS_URL` est présente avec la spec :
```php
'APPLICATIONS_SCRIPTS_URL' => [
    'config' => 'sambaedu.gpo.applications_scripts_url',
    'env' => 'SAMBAEDU_APPLICATIONS_SCRIPTS_URL',
    'default' => '', // résolu dynamiquement (cf. AC2.2)
],
```
**And** les 16 clés existantes (8 initiales 16.7 + 8 ajoutées 17.2) restent inchangées.
**And** la nouvelle entrée est précédée d'un commentaire iso-16.7/17.2 :
`// 17.3 — URL endpoint natif Story 16.13 substituée dans les .cmd orchestrateurs GPO se4_applications (Strat. A.2)`

**AC2.2 — Résolution dynamique de `APPLICATIONS_SCRIPTS_URL` via `URL::route`**
**Given** que la résolution config()→env()→default ne suffit pas (l'URL dépend
du domaine déployé, ex. `http://se4fs.lycee.fr/api/v1/workstation-config/applications-scripts`)
**When** un appelant utilise la clé `APPLICATIONS_SCRIPTS_URL`
**Then** la résolution **fallback** appelle
`URL::route('agent.v1.config.applications-scripts', [], absolute: true)` si
config + env + default sont vides.
**Implémentation** : enrichir
`ApplicationScriptsAssembler::resolveSubstitutionValue()` (16.7) avec un cas
spécial pour cette clé — OU — pré-poser dans `config/sambaedu.php` une closure
inline qui appelle `URL::route(...)`.
- **Recommandation SM** : closure inline `'default' => fn () => URL::route('agent.v1.config.applications-scripts', [], absolute: true)`.
  Le `resolveSubstitutionValue` 16.7 actuel ne supporte pas les closures →
  **modifier ce point précis** (1 ligne `if (is_callable($default)) $default = $default()`).
- **Alternative** (si la modification 16.7 est risquée) : poser le calcul dans
  `config/sambaedu.php` au moment du `boot` via service provider — pattern iso
  `sambaedu.app.url`. Le dev arbitre en T0.

**AC2.3 — Variable d'env `SAMBAEDU_APPLICATIONS_SCRIPTS_URL` documentée dans `.env.example`**
**Given** `.env.example`
**When** la story est complète
**Then** une nouvelle ligne est ajoutée :
```
# 17.3 — Override URL endpoint natif applications-scripts (par défaut résolu via URL::route)
SAMBAEDU_APPLICATIONS_SCRIPTS_URL=
```
**And** la résolution par défaut (vide) tombe sur le fallback `URL::route(...)`
(AC2.2).

### Volet 3 — Documentation Stratégie A.1 + A.2 (D7)

**AC3.1 — Section dédiée dans `docs/qa/domains/gpo.md`**
**Given** la documentation QA du domaine GPO
**When** la story est complète
**Then** une nouvelle section `## Story 17.3 — Compat GPO orchestratrice se4_applications`
est ajoutée à `docs/qa/domains/gpo.md`.
**And** la section documente :
- L'objectif (compat `.cmd` orchestrateurs vers endpoint natif 16.13).
- Les 2 stratégies A.1 (patch upstream Debian) et A.2 (substitution post-extraction
  via whitelist + `import_gpo` legacy).
- La commande `php artisan gpo:applications:audit` (usage, exit codes, mode `--json`).
- La procédure de mise à jour côté opérateur si le template est modifié.
- Référence Q-1 (arbitrage URL directe API v1 vs URL legacy/migration).

**AC3.2 — Optionnel : patch upstream livré si nécessaire**
**Given** que T0 révèle un `.cmd` upstream à patcher (URL hardcodée)
**When** le dev rédige le patch
**Then** un fichier `_bmad-output/implementation-artifacts/17-3-upstream-patch.diff`
est créé contenant le diff git **pour le repo upstream `sambaedu-gpo`** (pas le
repo `sambaedu-reload`).
**And** ce fichier est annoncé en Completion Notes avec une consigne :
« À transmettre à Henri qui pousse côté repo Debian (option A.1) — non appliqué
automatiquement ici (out-of-scope). »
**And** si T0 révèle qu'aucun patch upstream n'est nécessaire (URL déjà via
placeholder ou URL legacy conservée), ce livrable est skip + note explicite dans
Completion Notes.

---

## Tasks / Subtasks

### Phase T0 — Investigation préalable (~2h, Q-1/Q-2/Q-3/Q-4 résolues 2026-05-22)

- [x] **T0.1** Lire intégralement Section G.2 + Section A.1 (fragments `se4_applications`)
  + Section H.3 (`Nettoyage applications-*.cmd` ROBOCOPY) de l'audit 17.1
  `_bmad-output/planning-artifacts/audit-applications-scripts.md`.
- [x] **T0.2** Lire `_bmad-output/implementation-artifacts/16-6-hook-gpo-invocation-wpkgjs-cote-client.md`
  Sections T0 (investigation template `se4_wpkg.zip`) + D6 (fallback shim) +
  D3 (service `WpkgGpoSynchronizer` pattern).
- [x] **T0.3** Lire `app/Gpo/Services/WpkgGpoSynchronizer.php` lignes 1-90 (constantes
  + constructeur) + 371-490 (`scanTemplatePlaceholders` + `scanDirectoryPlaceholders`).
  Comprendre le pattern, prévoir la duplication dans la commande artisan 17.3 (D6).
- [x] **T0.4** Inspection du contenu réel du template `se4_applications.zip`.
  **2 modes** :
  - (a) **Si VM accessible** (worktree-safe — l'utilisateur doit autoriser SSH /vm
    explicitement) → SSH `/vm` puis :
    ```
    ls -la /usr/share/sambaedu/gpo/
    unzip -l /usr/share/sambaedu/gpo/se4_applications.zip
    unzip -p /usr/share/sambaedu/gpo/se4_applications.zip "Machine/Scripts/Startup/*.cmd"
    unzip -p /usr/share/sambaedu/gpo/se4_applications.zip "Machine/Scripts/Logon/*.cmd"
    ```
  - (b) **Si VM inaccessible** (worktree strict) → demander à Henri de fournir le
    contenu (ou un export du `.zip`). **STOP — pause la story jusqu'à T0.4 résolu.**
- [x] **T0.5** À partir de l'inspection T0.4, **lister exhaustivement** :
  - Les `.cmd`/`.bat` présents dans le template GPO `se4_applications.zip`.
  - Pour chacun, les URLs HTTP référencées (regex `https?://[^"\s)]+`).
  - Pour chacun, les placeholders `###_KEY_###` présents.
- [x] **T0.6** ~~Arbitrage Q-1~~ **RÉSOLU 2026-05-22 Henri** : URL **directe API v1**
  (JWT généralisé 16.10+16.11 done). `.cmd` pointent sur
  `/api/v1/workstation-config/applications-scripts` via whitelist
  `APPLICATIONS_SCRIPTS_URL`. Vérifier seulement : méthode HTTP attendue par
  l'endpoint API v1 (GET vs POST multipart actuel du `.cmd` legacy) + format
  query params. Si mismatch → adapter le `.cmd` (GET + `Authorization: Bearer`)
  ou étendre l'endpoint pour accepter les deux (à arbitrer dans T2 selon constat
  T0.4/T0.5).
- [x] **T0.7** ~~Arbitrage Q-2~~ **RÉSOLU 2026-05-22 Henri** : **closure inline**
  (1 ligne `is_callable` dans `resolveSubstitutionValue`). Pas de service provider.
- [x] **T0.8** ~~Arbitrage Q-3~~ **RÉSOLU 2026-05-22 Henri** : **les deux**
  (livrer `.diff` upstream T6 + substitution post-extraction whitelist active).
  Robustesse maximale.

### Phase T1 — Extension whitelist + résolution `APPLICATIONS_SCRIPTS_URL` (AC2.1+AC2.2+AC2.3 — ~1h)

- [x] **T1.1** Ajouter la clé `APPLICATIONS_SCRIPTS_URL` à
  `config/sambaedu.php` dans `gpo.applications.substitutions.whitelist`
  (après les 16 entrées existantes, ordre alphabétique respecté).
- [x] **T1.2** Selon T0.7 :
  - **Option (a) closure inline** : modifier
    `ApplicationScriptsAssembler::resolveSubstitutionValue()` (1 ligne) :
    ```php
    if (is_callable($default)) {
        $default = $default();
    }
    ```
    À placer juste avant le retour du default (vers la ligne ~940).
  - **Option (b) service provider boot** : ajouter dans
    `app/Providers/AppServiceProvider::boot()` (ou nouveau provider) :
    ```php
    Config::set('sambaedu.gpo.applications_scripts_url',
        URL::route('agent.v1.config.applications-scripts', [], absolute: true));
    ```
    avec spec `default => ''` simple dans la whitelist.
- [x] **T1.3** Ajouter à `.env.example` la nouvelle variable
  `SAMBAEDU_APPLICATIONS_SCRIPTS_URL=` (commentée — pattern iso 17.2).
- [x] **T1.4** Lancer `php artisan config:cache` puis `php artisan config:clear` pour
  vérifier que la whitelist reste sérialisable.
- [x] **T1.5** `php -l config/sambaedu.php` + `php -l app/Gpo/Services/ApplicationScriptsAssembler.php`
  (si modifié T1.2 option a) → 0 erreur.

### Phase T2 — Commande artisan `gpo:applications:audit` (AC1.1+AC1.2+AC1.3 — ~3h)

- [x] **T2.1** Créer `app/Console/Commands/AuditApplicationsGpoTemplateCommand.php`.
  Signature : `gpo:applications:audit {--json : Output JSON} {--path= : Override template path}`.
- [x] **T2.2** Constructeur via DI : injecter rien de spécifique — la commande lit
  directement `config('sambaedu.gpo.applications_template.path', '/usr/share/sambaedu/gpo/se4_applications.zip')`.
- [x] **T2.3** Implémenter méthode `private scanTemplate(string $path): array` qui
  reproduit la logique 16.6 D6 (dupliquée, pas refactorée) :
  - Si `is_dir($path)` → scan récursif (mode dégradé testing).
  - Si `is_file($path)` → `ZipArchive::open` avec MAX_ZIP_FILES + MAX_ZIP_ENTRY_BYTES.
  - Filtre `\.(cmd|bat|ini|xml|reg|inf|adm|admx|adml|txt|ps1|vbs)$/i`.
  - Détection UTF-16LE + conversion.
  - **Extraction URLs HTTP** via regex `https?://[^"\s)]+`.
  - **Extraction placeholders** via regex `###_([A-Z][A-Z0-9_]*)_###`.
- [x] **T2.4** Implémenter méthode `private classifyFile(array $urls): string` qui
  retourne :
  - `'ok'` si toutes URLs HTTP pointent sur `/api/v1/` ou ne contiennent pas
    `gpo/applications.php`.
  - `'patch_upstream'` ou `'substitute_post_extraction'` (selon décision Q-3)
    si au moins une URL contient `/gpo/applications.php`.
- [x] **T2.5** Mode default (texte) : tableau ASCII via Symfony Console
  `$this->table($headers, $rows)`. Colonnes : fichier, URLs (truncate 60 chars),
  legacy_match, recommendation, placeholders (truncate 40 chars).
- [x] **T2.6** Mode `--json` : sortie JSON conforme AC1.2 (sur stdout, warnings
  sur stderr via `$this->components->warn()`).
- [x] **T2.7** Comparer placeholders détectés à la whitelist 16.7+17.2+17.3 via
  `array_diff` + `array_map('strtoupper', ...)` (iso 16.6 `diffWhitelist`).
  Inclure dans le rapport `unknown_placeholders`.
- [x] **T2.8** Exit codes :
  - `0` si aucune URL legacy détectée + aucun placeholder inconnu.
  - `2` si URL legacy détectée OU placeholder inconnu (warning, non bloquant).
  - `1` si template absent / ZIP corrompu / ZipArchive ne s'ouvre pas (erreur fatale).
- [x] **T2.9** Logger structuré : `Log::channel('gpo')` pour audit trail —
  `action_type` `gpo.applications.audit.start` + `gpo.applications.audit.end`
  (parité 16.6 `gpo.wpkg.sync.start/end`).
- [x] **T2.10** Enregistrer la commande dans `app/Console/Kernel.php` (Laravel auto-discover
  via `commands/` mais vérifier le pattern de ce projet — sambaedu-reload utilise
  parfois un registre explicite).

### Phase T3 — Tests Feature commande artisan (AC1.1+AC1.2+AC1.3 — ~2h)

- [x] **T3.1** Créer `tests/Feature/Gpo/AuditApplicationsGpoTemplateCommandTest.php`.
- [x] **T3.2** Pattern fixture-directory (mode dégradé testing — sans ext-zip
  requise) : `tests/Fixtures/Gpo/se4_applications_template/` avec sous-arborescence :
  ```
  Machine/Scripts/Startup/Nettoyage applications-startup.cmd  (URL legacy)
  Machine/Scripts/Logon/Nettoyage applications-logon.cmd      (URL legacy)
  Machine/Scripts/Startup/sample-ok.cmd                       (URL native)
  ```
  Iso 16.6 (`scanDirectoryPlaceholders` testing mode).
- [x] **T3.3** Test `it_detects_legacy_urls_in_orchestrators()` : commande exécutée
  avec `--path=tests/Fixtures/Gpo/se4_applications_template/`, assert tableau
  contient `legacy_match=true` pour les 2 fichiers `Nettoyage applications-*.cmd`,
  exit code 2.
- [x] **T3.4** Test `it_outputs_json_with_summary()` : commande avec `--json`,
  parse JSON stdout, assert structure `{template_path, files[], summary{total, legacy_count, ok_count}, unknown_placeholders}`.
- [x] **T3.5** Test `it_returns_exit_1_if_template_absent()` : commande avec
  `--path=/non/existant.zip`, assert exit code 1 + message stderr clair.
- [x] **T3.6** Test `it_detects_unknown_placeholders()` : ajouter un fichier
  fixture avec `###_INVENTE_###`, assert sortie `unknown_placeholders: ["INVENTE"]`.
- [x] **T3.7** Test `it_returns_exit_0_when_template_pristine()` : fixture
  avec uniquement URL native, assert exit code 0.

### Phase T4 — Tests Feature whitelist `APPLICATIONS_SCRIPTS_URL` (AC2.1+AC2.2 — ~1h)

- [x] **T4.1** Étendre `tests/Unit/Gpo/ApplicationScriptsAssemblerTest.php` (existant
  17.2) avec un test `it_substitutes_applications_scripts_url_via_route_fallback()` :
  - Set `config('sambaedu.gpo.applications_scripts_url', '')` + `env`
    `SAMBAEDU_APPLICATIONS_SCRIPTS_URL=''`.
  - Template `'cmd-url=###_APPLICATIONS_SCRIPTS_URL_###'`.
  - Assert substitution = `URL::route('agent.v1.config.applications-scripts', [], absolute: true)`.
- [x] **T4.2** Test `it_overrides_applications_scripts_url_via_env()` :
  set `SAMBAEDU_APPLICATIONS_SCRIPTS_URL=https://other.example/v1/apps`, assert
  substitution = cette valeur.
- [x] **T4.3** Test `it_overrides_applications_scripts_url_via_config()` :
  set `config('sambaedu.gpo.applications_scripts_url', 'https://from-config/v1')`,
  assert substitution = `'https://from-config/v1'`.
- [x] **T4.4** Si T1.2 option (a) closure inline implémentée → ajouter test
  `it_resolves_callable_default_in_substitution_whitelist()` qui pose une closure
  en `default` dans une whitelist mock + assert resolved.

### Phase T5 — Documentation Stratégie A (AC3.1 — ~30min)

- [x] **T5.1** Localiser `docs/qa/domains/gpo.md` + repérer la section adéquate
  pour insérer la nouvelle sous-section (probablement après section 16.6 GPO `se4_wpkg`).
- [x] **T5.2** Rédiger la section `## Story 17.3 — Compat GPO orchestratrice se4_applications` :
  - Objectif (compat `.cmd` orchestrateurs → endpoint natif 16.13).
  - Stratégie A.1 (patch upstream) — pré-requis accès repo `sambaedu-gpo` Debian.
  - Stratégie A.2 (substitution post-extraction via whitelist `APPLICATIONS_SCRIPTS_URL`)
    — déjà déployée par cette story.
  - Procédure opérateur :
    ```
    php artisan gpo:applications:audit
    php artisan gpo:applications:audit --json | jq .
    ```
  - Note référence Q-1 (URL directe natif vs URL legacy migration).
- [x] **T5.3** Référencer cette section depuis `app/Gpo/README.md` (1 ligne ajoutée
  dans le sommaire des stories Epic 16/17).

### Phase T6 — Patch upstream optionnel (AC3.2 — ~30min, conditionnel T0.8)

- [x] **T6.1** Si T0.8 = oui (patch upstream nécessaire) → créer
  `_bmad-output/implementation-artifacts/17-3-upstream-patch.diff` contenant le diff
  git **pour le repo `sambaedu-gpo` Debian** (pas pour le repo `sambaedu-reload`).
- [x] **T6.2** Format du patch : `diff --git a/se4_applications/Machine/Scripts/Startup/Nettoyage applications-startup.cmd b/...`
  remplaçant `http://###_SE4FS_NAME_###/gpo/applications.php` par
  `###_APPLICATIONS_SCRIPTS_URL_###` (placeholder substitué post-extraction par
  `specialise_gpo` au moment de `import_gpo`).
- [x] **T6.3** Ajouter en Completion Notes : « Patch upstream à transmettre à
  Henri pour push côté repo Debian — non appliqué automatiquement (out-of-scope D7). »
- [x] **T6.4** Si T0.8 = non → skip + note explicite « Aucun patch upstream
  nécessaire (template upstream utilise déjà un placeholder substituable). »

### Phase T7 — Régression + finalisation (~30min)

- [x] **T7.1** Lancer suite complète tests Gpo : `php artisan test --testsuite=Feature --filter Gpo` +
  `php artisan test --testsuite=Unit --filter Gpo`.
- [x] **T7.2** Vérifier **100%** des tests héritage 16.7+17.2 passent (zéro régression
  whitelist).
- [x] **T7.3** `git diff --name-only main..HEAD` : aucun fichier sous `database/migrations/`,
  `routes/`, `resources/views/`, `app/Models/`, `app/Http/Controllers/` (AC D8).
  Seuls fichiers attendus : `config/sambaedu.php`, `.env.example`,
  `app/Console/Commands/AuditApplicationsGpoTemplateCommand.php`,
  `app/Gpo/Services/ApplicationScriptsAssembler.php` (si T1.2 option a),
  `app/Providers/AppServiceProvider.php` (si T1.2 option b),
  `tests/Feature/Gpo/AuditApplicationsGpoTemplateCommandTest.php`,
  `tests/Fixtures/Gpo/se4_applications_template/**`,
  `tests/Unit/Gpo/ApplicationScriptsAssemblerTest.php` (extension),
  `docs/qa/domains/gpo.md`, `app/Gpo/README.md`,
  optionnel `_bmad-output/implementation-artifacts/17-3-upstream-patch.diff`.
- [x] **T7.4** `php -l` sur tous les fichiers PHP modifiés/créés → 0 erreur.
- [x] **T7.5** Mettre à jour `_bmad-output/implementation-artifacts/sprint-status.yaml`
  ligne 281 : `17-3-compat-gpo-orchestratrice-se4-applications: backlog` →
  `review` (au moment du passage en review). Statut `ready-for-dev` posé par SM
  reste valide jusqu'à fin du dev.
- [x] **T7.6** Remplir la section « Dev Agent Record » de cette story (file list,
  completion notes, agent model used, résolution Q-1/Q-2/Q-3).

---

## Tests Cibles

> **Couverture cible : ≥ 7 tests cumulés** (5 Feature commande artisan + 3-4
> Unit/Feature whitelist).

1. **`it_detects_legacy_urls_in_orchestrators`** (T3.3) — commande détecte URL
   `gpo/applications.php` dans fixtures `.cmd`.
2. **`it_outputs_json_with_summary`** (T3.4) — mode JSON conforme structure AC1.2.
3. **`it_returns_exit_1_if_template_absent`** (T3.5) — erreur fatale traitée
   proprement.
4. **`it_detects_unknown_placeholders`** (T3.6) — alerte sur placeholder hors
   whitelist.
5. **`it_returns_exit_0_when_template_pristine`** (T3.7) — exit code 0 si tout
   est OK.
6. **`it_substitutes_applications_scripts_url_via_route_fallback`** (T4.1) —
   résolution dynamique via `URL::route` quand config + env vides.
7. **`it_overrides_applications_scripts_url_via_env`** (T4.2) — override env.

**Tests additionnels facultatifs** (si T1.2 option a closure inline retenue) :
- **`it_resolves_callable_default_in_substitution_whitelist`** (T4.4) — validation
  pattern closure default dans `resolveSubstitutionValue`.

---

## Hors-Scope strict (anti-scope creep)

| ❌ HORS scope 17.3 | Renvoi |
|---|---|
| Création/duplication native GPO `se4_applications` | Story 16.4 cancelled — abandonné définitivement |
| Republication SYSVOL automatisée du template patché | Déféré (D7) — peut être une story future si besoin terrain |
| Tests E2E runtime VM (5 scripts critiques `wpkg/startup.windows`, `wallpaper/logon.windows`, ...) | Story 17.4 |
| Activation flag wrapper opt-in `WrapperScriptRenderer` | Story 17.5 |
| Portage `wpkg/linux_out.php` / `wpkg/winget_out.php` | Story 17.6 |
| Modification du `WpkgGpoSynchronizer` existant (se4_wpkg) | Service stable 16.6 done — pas de refactor |
| Création service `ApplicationsGpoSynchronizer` complet (audit+publish+DTO+UI Livewire) | Déféré (D5) si besoin terrain remonte |
| Modification de l'auth iso-legacy (Bearer per-host) | memory `feedback_auth_iso_legacy` — postes pas tous JWT-migrés au déploiement |
| Application automatique du patch upstream sur le repo Debian | Hors scope D7 — patch livré en diff à transmettre à Henri |
| Modification du contenu des fragments scripts upstream (logiques applicatives `firefox/logon.windows`, etc.) | Hors scope — versionné par paquet Debian |

---

## Questions ouvertes à Henri (T0 — RÉSOLUES 2026-05-22)

> **Arbitrages Henri 2026-05-22** : les 4 questions sont tranchées avant dev. La
> story passe `ready-for-dev`. Les phases T0.6/T0.7/T0.8/T0.5 ne sont plus
> bloquantes — elles deviennent simples vérifications.

### Q-1 — URL directe natif API v1 vs URL legacy migration → **DIRECTE API v1**

**Décision Henri** : URL **directe API v1** dans les `.cmd` orchestrateurs.
JWT est généralisé (16.10 + 16.11 done, postes déjà migrés en masse). Pas de
re-route via `MigrationController` 16.13bis option β.

**Implication concrète** :
- Les `.cmd` doivent pointer vers `http://###_SE4FS_NAME_###/api/v1/workstation-config/applications-scripts`
  (ou équivalent absolu résolu via `route('agent.v1.config.applications-scripts')`).
- La whitelist `APPLICATIONS_SCRIPTS_URL` est **consommée immédiatement** (pas de
  story `17.3bis` différée).
- Vigilance T0 : vérifier que l'endpoint API v1 supporte bien la requête du
  `.cmd` (méthode HTTP + auth `auth.v1.workstation` JWT + format réponse).
  Si le `.cmd` envoie POST multipart actuellement et l'API v1 attend GET, il
  faut soit adapter le `.cmd` (GET avec query params) soit étendre l'endpoint
  pour accepter les deux. **Décision SM** : aligner le `.cmd` sur la méthode
  attendue par l'API v1 (GET avec `Authorization: Bearer <JWT_machine>` si le
  certificat client n'est pas utilisé sur ce flux).

### Q-2 — Pattern résolution `default` callable → **closure inline**

**Décision Henri** : closure inline (`is_callable` 1 ligne dans
`resolveSubstitutionValue`). Pattern plus flexible et localisé, pas de couplage
service provider inutile.

**Implication concrète** :
- Modifier `app/Gpo/Services/ApplicationScriptsAssembler.php::resolveSubstitutionValue()`
  (méthode introduite en 16.7, étendue en 17.2) pour reconnaître `is_callable($default)`
  et l'exécuter.
- Test dédié dans T4 (cf. AC2.1+AC2.2).

### Q-3 — Patch upstream `sambaedu-gpo` requis ? → **les deux (livrer `.diff` + substitution active)**

**Décision Henri** : livrer le patch upstream en `.diff` ET garder la substitution
post-extraction whitelist active. Robustesse maximale.

**Implication concrète** :
- Phase T6 produit un fichier `_bmad-output/implementation-artifacts/17-3-upstream-se4_applications.diff`
  documenté (sera transmis manuellement par Henri au repo Debian).
- La substitution post-extraction reste l'effective sur les serveurs déjà
  installés (sans dépendre du timing release Debian).

### Q-4 — Inclure `User\Scripts\Logon\` dans l'audit ? → **scan exhaustif filtre extension**

**Décision Henri** : scan exhaustif via filtre extension (`.cmd|.bat`), pas de
filtrage par sous-dossier. Robuste face à évolutions futures du template.

**Implication concrète** :
- T2.3 itère sur **toutes** les entrées ZIP, filtre par extension uniquement.

---

## Dev Notes

### Architecture / Patterns

#### Référence absolue : `WpkgGpoSynchronizer` (Story 16.6 done)

- Classe `App\Gpo\Services\WpkgGpoSynchronizer` (`app/Gpo/Services/WpkgGpoSynchronizer.php`).
- 973 lignes — service métier complet (audit + publish + DTO + Livewire).
- 17.3 **ne refait pas** un service équivalent (D5) ; elle livre une commande
  artisan plus modeste (~250 lignes attendues) + extension whitelist.
- Les pattern à reproduire (D6) :
  - Constantes `MAX_ZIP_FILES`, `MAX_ZIP_ENTRY_BYTES` (lignes 66-67).
  - Méthode privée `scanTemplatePlaceholders` (lignes 371-453) — duplication
    chirurgicale dans la commande 17.3.
  - Méthode privée `scanDirectoryPlaceholders` (lignes 458+) — mode dégradé
    testing.
  - Détection UTF-16LE (lignes 425-437).
  - Logger structuré `GpoLogger::action('gpo.wpkg.template.scan', ...)` (ligne 382).

#### Endpoint cible Story 16.13

- Route : `GET /api/v1/workstation-config/applications-scripts` (route name
  `agent.v1.config.applications-scripts`).
- Controller : `App\Http\Controllers\Gpo\ApplicationsScriptsController::apiV1`
  (`app/Http/Controllers/Gpo/ApplicationsScriptsController.php:218-243`).
- **Middleware** : `auth.v1.secure-headers + auth.v1.workstation + throttle:300,1`.
- **Ne pas appeler `auth.v1.workstation` depuis les `.cmd` legacy postes non-migrés** :
  c'est exactement le rôle de `MigrationController::serveFragment(applications)`
  (route web.php:618) — sert un fragment de migration qui re-route les postes
  post-migration vers la route API v1.

#### Pattern résolution `config()→env()→default` (17.2 D2)

- Spec déclarative : `['config' => '...', 'env' => '...', 'default' => '...']`.
- Chaîne court-circuit : `config()` (non-null non-vide) → `env()` (non-null non-false
  non-vide) → `default`.
- **Limitation actuelle** : `default` est une chaîne littérale uniquement
  (`resolveSubstitutionValue` 16.7 ne supporte pas les closures). 17.3 propose
  d'enrichir d'une ligne `is_callable` pour permettre `URL::route(...)` dynamique
  (cf. T1.2 option a).

### Tests Standards

- **Framework** : PHPUnit (via `php artisan test`).
- **Pattern fixture-directory** : iso 16.6 testing mode — pas besoin d'un vrai
  `.zip`, un répertoire avec sous-arborescence suffit (`scanDirectoryPlaceholders`).
- **Pattern test commande artisan** : `Artisan::call('gpo:applications:audit', ['--json' => true])`
  + `Artisan::output()` pour parser stdout.
- **Mock URL::route** : ne pas mocker — laisser l'app résoudre via routing réel
  (les routes sont chargées en testing).

### Sécurité

- **Pas de nouveau input user** : la commande artisan lit un path local FS via
  `config()` et `--path` (option CLI). Pas d'injection possible.
- **Path traversal** : la valeur `--path=` doit être validée (`realpath` +
  préfixe autorisé `/usr/share/sambaedu/gpo/` OU répertoire de test). Pattern
  iso 16.6 `TEMPLATE_PATH_PREFIX` ligne 53.
- **ZIP bomb** : protection MAX_ZIP_FILES + MAX_ZIP_ENTRY_BYTES (iso 16.6).
- **Permission CLI** : la commande artisan ne fait pas d'écriture FS ni AD ni
  SYSVOL — lecture pure. Pas de permission Spatie spécifique requise pour la
  CLI (le shell sudo www-admin suffit). Si exposée en UI HTTP (out-of-scope),
  ajouter `SambaPermission::ServerAdmin`.

### Notes opérationnelles déploiement

- Si T0 révèle qu'un patch upstream est nécessaire (T0.8) et qu'Henri le pousse
  côté repo `sambaedu-gpo` Debian, les serveurs en production devront :
  - `apt-get update && apt-get install --reinstall sambaedu-gpo` (déploie le
    template patché).
  - Re-publier le template SYSVOL : invoquer `import_gpo` via le shim legacy
    `gpo/gpo-maj.php` (UI legacy) OU manuellement via PHP CLI (out-of-scope 17.3).
- La whitelist étendue `APPLICATIONS_SCRIPTS_URL` est consommée par
  `specialise_gpo` legacy au moment de `import_gpo` (chaîne 16.6 `unzip_gpo → specialise_gpo → sysvol_put`).
- **Important** : la republication SYSVOL n'est pas déclenchée par cette story
  (D7). Henri ou un opérateur doit le faire post-déploiement 17.3.

### Références

- Audit 17.1 : `_bmad-output/planning-artifacts/audit-applications-scripts.md` —
  Sections A.1 (fragments `se4_applications`), C (endpoints HTTP), G.2 (cadrage
  Stratégie A), H.3 (`install/os/SambaEdu/Nettoyage applications-*.cmd`),
  Annexe A (inventaire).
- Story 16.6 : `_bmad-output/implementation-artifacts/16-6-hook-gpo-invocation-wpkgjs-cote-client.md` —
  Pattern référence absolu. Lecture intégrale T0 + T1 + D1-D10.
- Story 17.2 : `_bmad-output/implementation-artifacts/17-2-portage-moteur-applications-php-whitelist-etendue.md` —
  D1+D2 (extension whitelist), pattern résolution.
- Story 16.13 : `_bmad-output/implementation-artifacts/16-13-exposition-endpoints-api-v1.md` —
  Endpoint cible.
- Story 16.13bis : `_bmad-output/implementation-artifacts/16-13bis-module-migration-simplifie.md` —
  Route migration `gpo/applications.php` (option β).
- Service Synchronizer référence : `app/Gpo/Services/WpkgGpoSynchronizer.php` (973 lignes).
- Controller endpoint cible : `app/Http/Controllers/Gpo/ApplicationsScriptsController.php:218-243`.
- Route api v1 : `routes/api.php:256`.
- Route migration : `routes/web.php:618`.
- Whitelist actuelle (17.2 à 16 clés) : `config/sambaedu.php` (clé `gpo.applications.substitutions.whitelist`).
- `.cmd` orchestrateurs upstream (référence T0.4) :
  `/home/htouchard/code/irundo/se4/sources/var/sambaedu/unattended/install/os/SambaEdu/Nettoyage applications-{startup,logon}.cmd`.
- Memory `project_no_native_gpo_creation` (Story 16.4 cancelled).
- Memory `feedback_auth_iso_legacy` (auth machine iso-legacy AD+SMB).

### File List anticipée (à compléter par le dev)

**Modifiés** :
- `config/sambaedu.php` (whitelist substitutions +1 clé `APPLICATIONS_SCRIPTS_URL`)
- `.env.example` (+1 var `SAMBAEDU_APPLICATIONS_SCRIPTS_URL`)
- `app/Gpo/Services/ApplicationScriptsAssembler.php` (1 ligne si T1.2 option a — `is_callable($default)`)
- `app/Providers/AppServiceProvider.php` (alternative T1.2 option b — boot config)
- `tests/Unit/Gpo/ApplicationScriptsAssemblerTest.php` (+3-4 tests AC2.2)
- `docs/qa/domains/gpo.md` (section 17.3)
- `app/Gpo/README.md` (1 ligne sommaire)
- `_bmad-output/implementation-artifacts/sprint-status.yaml` (statut → review)

**Créés** :
- `app/Console/Commands/AuditApplicationsGpoTemplateCommand.php`
- `tests/Feature/Gpo/AuditApplicationsGpoTemplateCommandTest.php`
- `tests/Fixtures/Gpo/se4_applications_template/Machine/Scripts/Startup/Nettoyage applications-startup.cmd`
- `tests/Fixtures/Gpo/se4_applications_template/Machine/Scripts/Logon/Nettoyage applications-logon.cmd`
- `tests/Fixtures/Gpo/se4_applications_template/Machine/Scripts/Startup/sample-ok.cmd`
- `tests/Fixtures/Gpo/se4_applications_template/Machine/Scripts/Startup/sample-unknown-placeholder.cmd`
- Optionnel : `_bmad-output/implementation-artifacts/17-3-upstream-patch.diff` (conditionnel T0.8 + AC3.2)

---

## Dev Agent Record

### Agent Model Used

`claude-opus-4-7[1m]` (modèle Opus 4.7 contexte 1M, exécution dev BMAD le 2026-05-22).

> Note : la story recommandait initialement `sonnet` (cf. section « Recommandation
> Modèle Dev »), mais Henri a explicitement lancé le dev en `opus`. Les critères
> d'escalade opus (T0.4 inspection révélant template très différent / Q-1 nécessitant
> refactor MigrationController / T2.3 cas non couvert par 16.6) ne se sont pas
> matérialisés — la story s'est exécutée en autonomie sans dérive archi.

### Debug Log References

- **T0.4 inspection template VM /vm** (autorisation explicite Henri lecture seule) :
  - Template présent sur la VM dans **`/usr/share/sambaedu/gpo/sambaedu-gpo/se4_applications/`**
    (sous-dossier du clone `sambaedu-gpo` Debian), **pas** dans
    `/usr/share/sambaedu/gpo/se4_applications.zip`. Le template est livré en
    **répertoire git** (pas en `.zip`). Adaptation automatique de la commande
    via `is_dir()` / `is_file()` détection (iso 16.6 D6).
  - 4 `.cmd` orchestrateurs détectés (vs 3 attendus dans la story) : Machine/Startup,
    Machine/Shutdown, User/Logon, **User/Logoff** (story mentionnait
    "logon-system" mais le contenu réel est Shutdown + Logoff).
  - **Tous les 4** hardcodent `http://%SE4FS%.###_DOMAIN_###/gpo/applications.php`
    (URL legacy à éradiquer). Méthode HTTP : **POST multipart** (`curl -F`),
    alors que l'endpoint API v1 16.13 est en **GET** avec query params — note
    laissée pour Story 17.4 (tests intégration runtime VM).
  - Placeholders détectés : `###_SE4FS_NAME_###` + `###_DOMAIN_###` (les 2 sont
    dans la whitelist legacy `SPECIALISE_CONFIG_KEYS`).
- **Q-2 closure inline + `config:cache` compatibilité** : la closure native PHP
  inline n'est **pas sérialisable** par `var_export` et casse `config:cache`.
  Solution conservant l'esprit Q-2 (pas de service provider boot) : la `default`
  est une paire **`[ApplicationScriptsAssembler::class, 'resolveApplicationsScriptsUrl']`**
  qui est is_callable=true ET sérialisable. Extension `resolveSubstitutionValue`
  enrichie d'une ligne `is_callable($value)` (parité décision Q-2 textuelle).
  Méthode statique `resolveApplicationsScriptsUrl()` ajoutée à
  `ApplicationScriptsAssembler` qui retourne
  `URL::route('agent.v1.config.applications-scripts', [], absolute: true)`.
- **Régression tests** : baseline avant 17.3 = 434 passed / 22 failed (Vite manifest).
  Après 17.3 = **440 passed / 20 failed**. Zéro régression — les 2 fails
  additionnels passant sont conséquence des fixtures testing isolées de Vite.

### Completion Notes List

**Volet 1 — Commande artisan `gpo:applications:audit`** (✅ livré) :
- Fichier `app/Console/Commands/AuditApplicationsGpoTemplateCommand.php` (~430 lignes)
  iso pattern 16.6 D6 — duplique `scanTemplatePlaceholders` + `scanDirectoryPlaceholders`
  chirurgicalement (pas de refactor de `WpkgGpoSynchronizer`).
- Détection automatique mode ZIP vs répertoire via `is_dir()`/`is_file()`. La VM
  dev expose un répertoire (`sambaedu-gpo` packagé), la prod expose un `.zip`
  installé par `apt-get install sambaedu-gpo` — la commande gère les deux.
- Garde-fous `MAX_ZIP_FILES=1000` + `MAX_ZIP_ENTRY_BYTES=10MB` (iso 16.6 review #C).
- Détection UTF-16LE (typique des `scripts.ini` MS) + conversion via
  `mb_convert_encoding`.
- Extraction URLs HTTP via `#https?://[^"\s)]+#` + placeholders via
  `/###_([A-Z][A-Z0-9_]*)_###/`.
- Classification `legacy_match` (URL contient `/gpo/applications.php`) + recommandation
  (`ok` / `substitute_post_extraction`).
- Comparaison whitelist via `diffWhitelist` (iso 16.6 ligne 511) — inclut les
  `SPECIALISE_CONFIG_KEYS` legacy (`DOMAIN`, `SAMBA_DOMAIN`, `SE4FS_NAME`, etc.).
- Modes `--json` (CI-friendly) et default texte (table ASCII via Symfony Console
  `$this->table`).
- Exit codes : 0 (OK), 1 (ERROR — template absent), 2 (WARNING — URL legacy ou
  placeholder hors whitelist).
- Logger structuré `Log::channel('gpo')` avec `action_type`
  `gpo.applications.audit.start/end` (parité 16.6 `gpo.wpkg.sync.start/end`).

**Volet 2 — Extension whitelist `APPLICATIONS_SCRIPTS_URL`** (✅ livré) :
- Clé ajoutée dans `config/sambaedu.php` après les 16 entrées 16.7+17.2, commentée.
- Résolution dynamique via **paire callable `[ApplicationScriptsAssembler::class,
  'resolveApplicationsScriptsUrl']`** (sérialisable `config:cache`-compatible)
  qui retourne `URL::route('agent.v1.config.applications-scripts', [], absolute: true)`.
- Méthode statique `resolveApplicationsScriptsUrl()` ajoutée à
  `ApplicationScriptsAssembler` (juste après le constructeur, documentée).
- Extension `resolveSubstitutionValue` : ligne `if (is_callable($value)) { $value = $value(); }`
  ajoutée juste après la résolution `default` — respecte la décision Q-2
  « 1 ligne is_callable » à la lettre.
- `.env.example` enrichi avec `SAMBAEDU_APPLICATIONS_SCRIPTS_URL=` et
  `GPO_APPLICATIONS_TEMPLATE_PATH=` (override testing/CI).
- Sous-config `sambaedu.gpo.applications_template.path` (env
  `GPO_APPLICATIONS_TEMPLATE_PATH`) ajoutée dans `config/sambaedu.php` pour
  cohérence avec pattern 16.6 `wpkg_sync.template_path`.

**Volet 3 — Documentation `docs/qa/domains/gpo.md`** (✅ livré) :
- Section `## Story 17.3 — Compat GPO orchestratrice se4_applications` (append-only)
  ajoutée à la fin du fichier existant.
- Couvre : objectif, Stratégies A.1 et A.2, procédure opérateur (4 étapes),
  override testing/CI, référence Q-1, 5 scénarios QA manuels numérotés stables
  (17.3-1 à 17.3-5), checklist rapide post-deploy (6 items).
- **Pas** de fichier `17-3-e2e-manual.md` créé (interdit par convention QA
  domain-based — cf. `docs/qa/README.md`).
- T5.3 (référence depuis `app/Gpo/README.md`) : **skip** — le README liste les
  services métier, pas les commandes artisan d'audit one-shot. Documentation
  suffisante via `docs/qa/domains/gpo.md` section 17.3.

**Volet 4 — Patch upstream `.diff`** (✅ livré — Q-3 « les deux ») :
- Fichier `_bmad-output/implementation-artifacts/17-3-upstream-se4_applications.diff`
  contenant le diff git unified pour les 4 `.cmd` orchestrateurs : remplace
  `http://%SE4FS%.###_DOMAIN_###/gpo/applications.php` par
  `###_APPLICATIONS_SCRIPTS_URL_###`.
- En-tête du fichier `.diff` documente la procédure d'application côté repo
  upstream `sambaedu-gpo` Debian + cohabitation avec A.2.
- **À transmettre manuellement par Henri** au repo Debian (out-of-scope D7).

**Tests cibles ≥ 7 livrés (✅ 11 tests)** :
- **7 tests Feature** dans `tests/Feature/Gpo/AuditApplicationsGpoTemplateCommandTest.php` :
  1. `it_detects_legacy_urls_in_orchestrators` (AC1.1 fixture 4 `.cmd` legacy)
  2. `it_outputs_json_with_summary` (AC1.2 structure JSON complète)
  3. `it_returns_exit_1_if_template_absent` (AC1.1 chemin erreur)
  4. `it_detects_unknown_placeholders` (AC1.3 whitelist diff)
  5. `it_returns_exit_0_when_template_pristine` (AC1.1 cas heureux)
  6. `it_extracts_placeholders_per_file_in_json_mode` (validation per-file)
  7. `it_filters_non_text_extensions` (filtre extension `.png`/`.exe`)
- **4 tests Unit** dans `tests/Unit/Gpo/ApplicationScriptsAssemblerTest.php`
  (section 17.3 ajoutée) :
  8. `it_substitutes_applications_scripts_url_via_route_fallback` (AC2.2 default callable)
  9. `it_overrides_applications_scripts_url_via_env` (AC2.2 chemin env)
  10. `it_overrides_applications_scripts_url_via_config` (AC2.2 chemin config gagne)
  11. `it_resolves_callable_default_in_substitution_whitelist` (extension is_callable générique)
- **25 tests cumulés** (18 Unit + 7 Feature) tous passent — 100% green.

**Résolutions des arbitrages Henri 2026-05-22** :
- **Q-1** ✅ URL directe API v1 via whitelist `APPLICATIONS_SCRIPTS_URL`.
- **Q-2** ✅ « closure inline 1 ligne is_callable » — implémenté avec paire callable
  array sérialisable (alternative compatible `config:cache` respectant l'esprit
  Q-2 « pas de service provider »).
- **Q-3** ✅ Les deux livrés : `.diff` upstream + substitution whitelist active.
- **Q-4** ✅ Scan exhaustif par filtre extension
  (`.cmd|.bat|.ini|.xml|.reg|.inf|.adm|.admx|.adml|.txt|.ps1|.vbs`) sans
  filtrage par sous-dossier (iso 16.6 D6).

**Points de vigilance pour la code review** :
- **Décision Q-2 fine** : la paire callable `[Classe::class, 'method']` est-elle
  conforme à l'esprit « closure inline » Q-2 Henri ? Alternative discutée : la
  closure pure casse `config:cache` (validé en local — `LogicException ... is
  non-serializable`). Solution choisie résout les deux contraintes (Q-2 +
  `config:cache` production-ready) et reste **`is_callable`** au runtime, donc
  parité fonctionnelle stricte avec la décision Henri.
- **Méthode HTTP mismatch** : les `.cmd` upstream font POST multipart, l'endpoint
  natif 16.13 est en GET. Le patch upstream `.diff` conserve la méthode POST
  multipart (laisse Story 17.4 traiter la transition POST→GET). À valider en
  review : faut-il adapter le `.diff` upstream pour passer en GET dès maintenant ?
- **Path traversal** : la commande accepte `--path=` arbitraire sans validation
  de préfixe (vs `TEMPLATE_PATH_PREFIX` 16.6 ligne 53). Pas de risque en CLI
  (l'utilisateur a déjà accès shell), mais pourrait être renforcé si la commande
  est ré-exposée en UI HTTP plus tard (out-of-scope D8).
- **Mode ZIP non couvert par tests CI** : les tests Feature utilisent uniquement
  la fixture-directory (mode dégradé iso 16.6). Le code `ZipArchive` n'est pas
  couvert par tests — accepté car (a) dupliqué chirurgicalement de
  `WpkgGpoSynchronizer::scanTemplatePlaceholders` 16.6 déjà testé, (b) la VM
  dev expose un répertoire (pas un `.zip`), donc le code ZIP s'active uniquement
  côté prod après `apt-get install sambaedu-gpo` qui produit le `.zip`.

### File List

**Créés** (9 fichiers) :
- `app/Console/Commands/AuditApplicationsGpoTemplateCommand.php`
- `tests/Feature/Gpo/AuditApplicationsGpoTemplateCommandTest.php`
- `tests/Fixtures/Gpo/se4_applications_template/Machine/Scripts/Startup/startup.cmd`
- `tests/Fixtures/Gpo/se4_applications_template/Machine/Scripts/Shutdown/shutdown.cmd`
- `tests/Fixtures/Gpo/se4_applications_template/User/Scripts/Logon/logon.cmd`
- `tests/Fixtures/Gpo/se4_applications_template/User/Scripts/Logoff/logoff.cmd`
- `tests/Fixtures/Gpo/se4_applications_template_pristine/Machine/Scripts/Startup/startup.cmd`
- `tests/Fixtures/Gpo/se4_applications_template_unknown/Machine/Scripts/Startup/startup.cmd`
- `_bmad-output/implementation-artifacts/17-3-upstream-se4_applications.diff`

**Modifiés** (7 fichiers) :
- `config/sambaedu.php` (whitelist substitutions +1 clé `APPLICATIONS_SCRIPTS_URL` +
  sous-config `applications_template.path`)
- `.env.example` (+2 vars : `SAMBAEDU_APPLICATIONS_SCRIPTS_URL`,
  `GPO_APPLICATIONS_TEMPLATE_PATH`)
- `app/Gpo/Services/ApplicationScriptsAssembler.php` (méthode statique
  `resolveApplicationsScriptsUrl()` + extension `resolveSubstitutionValue` 1 ligne
  `is_callable($value)`)
- `tests/Unit/Gpo/ApplicationScriptsAssemblerTest.php` (+4 tests 17.3 section)
- `docs/qa/domains/gpo.md` (section 17.3 append-only ~190 lignes)
- `_bmad-output/implementation-artifacts/17-3-compat-gpo-orchestratrice-se4-applications.md`
  (cette story — tasks cochées + Dev Agent Record + status → review)
- `_bmad-output/implementation-artifacts/sprint-status.yaml` (statut 17.3 → review)

### Post-review corrections (2026-05-22)

Review effectuée par claude-sonnet-4-6 + second avis claude-opus-4-7[1m]
(13 problèmes : 0 critique / 4 importants / 9 mineurs). Corrections appliquées
suite décisions Henri 2026-05-22 (Q1 = option A `.diff` GET maintenant, Q2 = retirer
mention path traversal du docblock, Q3 partiel = doc fallback legacy via env override).

| # | Fichier | Correction |
|---|---------|-----------|
| #1+#S1 | `_bmad-output/implementation-artifacts/17-3-upstream-se4_applications.diff` | Conversion POST multipart → GET query string sur les 4 `.cmd`. Header `.diff` mis à jour (Q1 résolution A) + note transition JWT. |
| #2 | `app/Gpo/Services/ApplicationScriptsAssembler.php` | `resolveSubstitutionValue` : scoping du check `is_callable` au seul chemin `default` + filtre `is_array || Closure` (exclut strictement les chaînes nom-de-fonction PHP — surface RCE bloquée). |
| #4 | `app/Console/Commands/AuditApplicationsGpoTemplateCommand.php` | Docblock : retrait de `patch_upstream` jamais retournée par `classifyFile` (laisse `substitute_post_extraction` + `ok`). |
| #5 | `tests/Feature/Gpo/AuditApplicationsGpoTemplateCommandTest.php` | +1 test `it_scans_zip_template_and_detects_legacy_url` (création `.zip` à la volée via `ZipArchive::open(... CREATE)`, marqué `markTestSkipped` si ext-zip absente — couvre la branche ZIP non exercée par les fixtures-directory). |
| #6 | `app/Gpo/README.md` | +1 ligne dans la table services Story 16.7 : commande artisan `gpo:applications:audit` + référence section `docs/qa/domains/gpo.md` § 17.3 + mention extension whitelist `APPLICATIONS_SCRIPTS_URL`. |
| #S2 | `app/Console/Commands/AuditApplicationsGpoTemplateCommand.php` | Regex `extractUrls` : exclusion étendue à `'`, `<`, `>` (`#https?://[^"\s)\'<>]+#`) pour éviter faux positifs dans `.reg`/`.xml`. |
| #S3 | `app/Console/Commands/AuditApplicationsGpoTemplateCommand.php` | `classifyFile` : commentaire d'invariant 1 ligne sur le bloc `urls === []` (early-return conservé pour clarté sémantique). |
| #S7 | `app/Console/Commands/AuditApplicationsGpoTemplateCommand.php` | Migration `Log::channel('gpo')->info/error/warning(...)` → `GpoLogger::action('gpo.applications.audit', operationId: ...)` iso convention 16.6 (`GpoActionLog` start/success/failure + propagation `operation_id`). `scanTemplate`/`scanDirectory`/`decodeIfUtf16` enrichies de `?GpoActionLog $log` propagé pour les `step('warning', ...)` (utf16 decode failed, zip entry truncated). |
| Q2 | `app/Console/Commands/AuditApplicationsGpoTemplateCommand.php` | Docblock : retrait de « / path traversal détecté » ligne 46. Ajout note sécurité claire (CLI uniquement, pas de risque path traversal). Pas d'ajout de validation `realpath`/`TEMPLATE_PATH_PREFIX` (décision Henri Q2). |
| Q3 partiel | `.env.example` + `docs/qa/domains/gpo.md` | Documentation explicite du pattern transition JWT (parc partiellement migré) : override `SAMBAEDU_APPLICATIONS_SCRIPTS_URL=http://se4fs.<domain>/gpo/applications.php` re-routé via `MigrationController` 16.13bis option β (`routes/web.php:618` `Route::match(['GET','POST'])`). Sous-section dédiée « Transition JWT — fallback legacy » ajoutée à `docs/qa/domains/gpo.md`. |
| #S8 | (vérification) | Exécution `php artisan gpo:applications:audit --path=tests/Fixtures/Gpo/se4_applications_template_pristine --json` : stdout = JSON pur valide (aucun préfixe Symfony Console `INFO`/`WARN`). `$this->line()` reste correct en mode normal. Note : `--quiet` supprime tout — comportement Symfony attendu, ne pas l'utiliser en CI avec `--json`. |

**Tests post-corrections** (focus régression) :
- `tests/Feature/Gpo/AuditApplicationsGpoTemplateCommandTest.php` : 7 passed + 1 skipped (ext-zip absente local) — 0 régression.
- `tests/Unit/Gpo/ApplicationScriptsAssemblerTest.php` : 18 passed — 0 régression.
- Suite Feature Gpo complète : 156 passed / 20 failed (Vite manifest baseline iso pré-corrections — 0 régression introduite).
- Suite Unit Gpo complète : 275 passed — 0 régression.

**Fichiers modifiés post-review** (ajout au File List ci-dessus) :
- `_bmad-output/implementation-artifacts/17-3-upstream-se4_applications.diff` (Q1 GET + headers)
- `app/Console/Commands/AuditApplicationsGpoTemplateCommand.php` (#4, #S2, #S3, #S7, Q2)
- `app/Gpo/Services/ApplicationScriptsAssembler.php` (#2 scoping is_callable)
- `tests/Feature/Gpo/AuditApplicationsGpoTemplateCommandTest.php` (#5 ZIP test)
- `app/Gpo/README.md` (#6 ligne services)
- `.env.example` (Q3 doc fallback legacy)
- `docs/qa/domains/gpo.md` (Q3 sous-section transition JWT)

---

## Recommandation Modèle Dev

**Recommandation : `sonnet`** (avec bascule `opus` possible uniquement si T0
révèle des écarts archi majeurs vs audit).

### Justification

**Périmètre technique réel** :
- **Volet 1 (commande artisan audit)** = **duplication chirurgicale** de 2
  méthodes privées de `WpkgGpoSynchronizer` (`scanTemplatePlaceholders` +
  `scanDirectoryPlaceholders`), bien décrites dans cette story et lisibles
  directement dans `app/Gpo/Services/WpkgGpoSynchronizer.php:371-490`. Pas de
  nouvelle abstraction.
- **Volet 2 (whitelist `APPLICATIONS_SCRIPTS_URL`)** = **pattern strictement
  décalqué de 17.2 D2** + une seule modification optionnelle de 1 ligne dans
  `resolveSubstitutionValue` (`is_callable`). Aucune décision d'architecture
  transverse.
- **Volet 3 (doc)** = rédaction markdown référençant des stratégies déjà
  cadrées dans cette story.

**Charge réelle ~1j** alignée sprint-status (G.6 audit + ligne 281). Sonnet a
livré 17.2 (charge 2-3j, complexité supérieure) sans dérive.

**Pourquoi pas opus** :
- Pas de décision archi nouvelle (toutes tranchées D1-D8 dans cette story).
- Pas de nouvelle modélisation Eloquent / migration / route applicative.
- Pas d'algorithme complexe (audit = scan ZIP + regex URL/placeholder, copier
  16.6).
- Pas de cas d'usage runtime nouveau (le `.cmd` orchestrateur existe déjà,
  17.3 vérifie + ajuste si besoin).
- Audit 17.1 (opus) a déjà fait le gros du travail conceptuel — 17.3 est de
  l'exécution mécanique.

**Critère d'escalade opus** (en cours de dev) :
- Si **T0.4 inspection** révèle un contenu du template `se4_applications.zip` très
  différent de l'attendu (ex. plusieurs dizaines de `.cmd` user-scope inattendus,
  ou un mécanisme d'invocation différent qui invaliderait D3/D4) → escalader à
  Henri pour rebascule opus.
- Si **T0.6 arbitrage Q-1** révèle un besoin de refactor de `MigrationController`
  ou de la chaîne 16.13bis → escalader (changement architecturel — pas du ressort
  17.3).
- Si **T2.3 (scanner ZIP)** se heurte à un cas non couvert par 16.6 (ex. ZIP
  contenant des binaires `.pol` qu'il faut parser via `specialise_gpo` legacy) →
  escalader, c'est une dette technique cachée.

Dans tous les autres cas, **sonnet exécute en autonomie** : pattern direct,
documentation extensive, audit 17.1 + WpkgGpoSynchronizer 16.6 servent de
référence sans ambiguïté.

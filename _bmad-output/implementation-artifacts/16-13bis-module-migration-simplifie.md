# Story 16.13bis : Module migration simplifié (SE4 → SE5) + cleanup shim 1bis.18 + UI tracking

Status: review

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

> Story **clôture** du refactor architectural de la migration SE4 → SE5 (Sprint Change Proposal 2026-05-19). Transforme les 8 endpoints legacy `/sambaedu/gpo/*_out.php` (md5/APCu) en **fragment+reboot** servi par un `MigrationController` dédié, supprime le middleware `InjectBootstrapFragment` livré par 16.11, retire le shim `legacy/gpo_shim.inc.php` (1bis.18), archive `sambaedu/gpo/` et expose une UI admin minimaliste de suivi de migration par collège (badge ✅/⏳/❌ + filtre + compteur X/Y postes migrés).
>
> **Scope strict 16.13bis** = (a) module isolé `App\Auth\V1\Migration` avec `MigrationController::serveFragment(endpoint, os)` qui renvoie `text/plain` un script `.cmd`|`.sh` autoportant (download CA root local → enroll JWT → tokens DPAPI Win / `0600 root` Linux → update registre Win / fichiers conf Linux pour pointer vers `/api/v1/workstation-config/*` livrés par 16.13 → `shutdown /r /t 30` Win+Linux + message user-friendly uniforme) ; (b) suppression du middleware `inject.bootstrap-fragment` (16.11) et du wrapping `Route::middleware('inject.bootstrap-fragment')->group(...)` dans `routes/web.php` ; (c) **bascule** des 8 routes legacy `gpo/{shortcuts,wallpaper,firefox,thunderbird,network,veyon,associations,applications}_*.php` vers `MigrationController::serveFragment` (les controllers métier `WallpaperController::legacyOut`, `AppPolicyController::legacy*Out`, etc. **deviennent inaccessibles** depuis ces routes — leurs méthodes `apiV1` 16.13 restent intactes sur `/api/v1/workstation-config/*`) ; (d) archive horodatée `legacy/gpo_shim.inc.php` + dépendances → `legacy/archived/gpo-shim-YYYY-MM-DD/` + retrait du `require_once 'legacy/gpo_shim.inc.php'` dans `legacy/bootstrap.php` ; (e) Eloquent : ajout `App\Models\Workstation::migrationStatus()` (hasOne `WorkstationMigrationStatus` via `uuid`↔`workstation_uuid`) + accessor `$workstation->migrated` + scopes `migrated()` / `notMigrated()` ; (f) UI admin minimaliste sur `/parc` (machines-tab) : colonne « Migration » avec badge ✅/⏳/❌ + filtre par statut migration + bandeau « X/Y postes migrés » sur les pages collège (stats-cards) ; (g) tests Feature module + Architecture non-régression `/api/v1/workstation-config/*` 16.13 + tests E2E scénario complet (legacy URL → fragment → migre → reboot simulé → `/api/v1/workstation-config/*` 200 OK) ; (h) runbook QA `docs/qa/domains/auth.md` enrichi append-only avec section dédiée `## Story 16.13bis` (scénarios numérotés 16.13bis-1 à 16.13bis-N).
>
> **HORS-SCOPE 16.13bis** :
> - Création d'un agent Go binaire unifié (= Phase 3 post-prod, cf. Tech Spec §5.6).
> - UI Livewire complète de monitoring temps-réel des attempts (`workstation_migration_attempts`) — la 16.11 `migration:health-check` daily + logs `auth-v1` suffisent ; un mini-bandeau de stats migrées suffit pour 16.13bis. Une UI dédiée pourra venir Phase 3 si besoin terrain.
> - Refactor des **endpoints** `/api/v1/workstation-config/*` 16.13 — ils restent strictement inchangés (test archi de non-régression D9).
> - Rotation/révocation JWT — déjà livrée 16.10.
> - Suppression de `gpo/applications.php` natif (selon Q1 ci-dessous — par défaut Option β, on supprime). À trancher Henri.
> - Modifications côté Windows GPO `Registry.pol` ou côté `unattend.xml`/`preseed.cfg` (= hors scope D6 Tech Spec).
> - Renommage / déplacement des controllers métier `WallpaperController` / `AppPolicyController` / etc. — uniquement la **route** legacy est rebranchée sur `MigrationController`. Les méthodes `legacyOut` deviennent code mort (à signaler `@deprecated` + tracker dans une story de cleanup future, pas dans 16.13bis).

---

## Mode de livraison & contraintes opérationnelles (worktree)

> **Mode worktree git** — branche `16-13bis`. Pattern static delivery iso 16.10/16.11/16.12/16.13.
>
> - **NE PAS** sync manuellement le code sur la VM. L'inotify host→VM se déclenche **uniquement** depuis `main` — depuis ce worktree il n'y a pas de propagation (memory `feedback_worktree_no_vm_sync`).
> - **NE PAS** SSH `/vm` depuis ce worktree, ni `/lab1`.
> - **NE PAS** run les tests sur la VM. Lint statique `php -l` + tests PHPUnit/Pest locaux (host) si `vendor/` présent — sinon différer les tests à Henri post-merge sur `main` (pattern iso 16.10/16.11/16.12/16.13).
> - **Actions Henri post-merge** listées en section finale « Smoke test à exécuter quand VM up » : `composer install`, smoke `curl` legacy + smoke fragment substitué + smoke `/api/v1/workstation-config/*` toujours OK + vérification archive `legacy/archived/gpo-shim-YYYY-MM-DD/`, exécution `./vendor/bin/pest tests/Feature/Auth/V1/Migration tests/Architecture/Migration* tests/Unit/Auth/V1/Migration tests/Feature/Pages/Parc tests/Unit/Models/WorkstationMigrationTest.php`, vérification logs `auth-v1`.

---

## Encadré contexte

**Topologie cible** (post-16.13bis) :

```
┌─────────────────────────────┐                                      ┌──────────────────────────────────┐
│ Poste SE4 (legacy non migré) │                                      │ Poste SE5 (migré post-16.13bis)  │
│                              │  ───── GET /sambaedu/gpo/X_out.php   │                                  │
│ scripts cmd/bash             │  ──────────────────────────────────► │ scripts cmd/bash                 │
│ HKLM:SambaEdu/AuthV1 absent  │                                      │ HKLM:SambaEdu/AuthV1 présent     │
│ /var/lib/sambaedu absent     │  ◄──── text/plain fragment+reboot    │ /var/lib/sambaedu présent        │
└─────────────────────────────┘        (MigrationController)          │ HTTPS Bearer JWT                 │
        ↓ exécute fragment                                            └────────────┬─────────────────────┘
        ↓ download CA, enroll JWT, write registry/conf,                            │ GET /api/v1/
        ↓ shutdown /r /t 30                                                        │   workstation-config/*
        ↓                                                                          │ (16.13 endpoints natifs)
        REBOOT
        ↓
        Le poste boot et redevient un « Poste SE5 (migré) »
```

**Côté serveur**, la cohabitation 16.11 (legacy actif + middleware `InjectBootstrapFragment` qui préfixe un fragment d'enroll dans les réponses legacy fonctionnelles) **disparaît**. Les 8 routes legacy `/sambaedu/gpo/*_out.php` ne renvoient **plus** le résultat de leur controller métier (`WallpaperController::legacyOut`, etc.) ni un préfixe — elles renvoient **exclusivement** un fragment de migration `text/plain` (cmd ou sh selon `?os=`) qui :
1. **No-op si déjà migré** (présence registre HKLM Windows / fichier `/var/lib/sambaedu/migrated` Linux). Conséquence : un poste déjà migré qui appellerait par erreur une URL legacy reçoit un script qui termine immédiatement (pas de reboot intempestif).
2. **Sinon** : download CA root local, parse paramètres locaux (UUID/MAC/hostname), POST `/api/v1/agent/enroll` (16.10 + `EnsureLanIp` 16.11), stocke `access_token` + `refresh_token` (DPAPI Win / `auth.json 0600 root` Linux), met à jour le registre Windows / les fichiers conf Linux pour rediriger les futurs appels vers `/api/v1/workstation-config/*` (8 endpoints natifs 16.13), puis `shutdown /r /t 30 /c "SambaEdu : migration terminée, redémarrage automatique dans 30 secondes…"`.
3. **Idempotence multi-niveaux** :
   - Côté poste : check `if exist HKLM:\SOFTWARE\SambaEdu\AuthV1\Migrated` (Win) / `[ -f /var/lib/sambaedu/migrated ] && exit 0` (Linux) en tête du fragment.
   - Côté serveur : `MigrationController` lookup `WorkstationMigrationStatus::where('workstation_uuid', $uuid)->exists()` — si oui, renvoie un fragment **trivial no-op** (`@echo SambaEdu : poste déjà migré, no-op` Win / `echo "SambaEdu: déjà migré, no-op"` Linux + `exit 0`).

**Conséquence comportementale** : un poste non-migré qui boote et appelle `gpo/applications.php` reçoit immédiatement le fragment de migration → l'exécute → reboot. Au boot suivant, le poste n'appelle **plus** les URLs legacy (le registre a été mis à jour). Les URLs legacy restent **techniquement actives** sur le serveur tant qu'il existe potentiellement un poste SE4 non-migré qui pourrait les solliciter. Le module `App\Auth\V1\Migration` porte un commentaire d'auto-obsolescence en tête de chaque fichier :

```php
/**
 * Module de migration SE4 → SE5.
 *
 * Ce code pourra être supprimé lorsqu'il n'existera plus de nécessité de
 * migrer un déploiement SE4 vers SE5 (typiquement : quand aucun collège
 * actif n'utilise plus SE4 = sambaedu legacy PHP-only).
 *
 * Sprint Change Proposal 2026-05-19. Story 16.13bis.
 */
```

**Réutilisation 16.11** : les tables `workstations_migration_status` + `workstation_migration_attempts` (migrations 16.11 du 2026-05-18) **restent productives**. Le `MigrationController` les peuple exactement comme le `EnrollController` 16.11 et le middleware `InjectBootstrapFragment` 16.11 le faisaient. Le modèle Eloquent `App\Auth\V1\Models\WorkstationMigrationStatus` est consommé en lecture par 16.13bis et exposé via la relation `Workstation::migrationStatus()`.

**Cohabitation `/api/v1/agent/*` (16.10/16.11) et `/api/v1/workstation-config/*` (16.13)** : strictement préservée. `MigrationController` n'introduit aucune nouvelle route sous `/api/v1/*` — il utilise les endpoints `enroll` / `refresh` existants (16.10) côté script poste. Les routes `bootstrap.cmd|.sh` (16.11) **sont supprimées** (logique absorbée par `MigrationController` directement sur les routes legacy `*_out.php` — voir D2 ci-dessous).

**Frontière `legacy/gpo_shim.inc.php`** : ce fichier (story 1bis.18g, ~580 lignes selon la story 1bis.18) bridge Kerberos + 8 fonctions GPO SYSVOL fallback. Il est **chargé par `legacy/bootstrap.php` ligne 117**. Il est entièrement **inutile** pour le flot fragment+reboot (qui ne touche pas SYSVOL ni samba-tool). 16.13bis le supprime du chargement + l'archive horodaté. Les fonctions natives Laravel (`App\Gpo\*Service`) restent intactes — elles n'utilisent pas le shim legacy.

---

## Décisions tranchées (D1-D12, ne pas re-débattre)

> Cadrage SM 2026-05-20 (Henri + opus 4.7). Le dev applique sans re-discuter ; en cas de blocage technique réel, il documente la difficulté dans Dev Agent Record et continue.

### D1 — Namespace module : **`App\Auth\V1\Migration`** (parallélisme 16.10/16.11)

- Tout le code du module migration vit sous `app/Auth/V1/Migration/`.
- Arborescence cible :
  ```
  app/Auth/V1/Migration/
    Http/
      Controllers/
        MigrationController.php          # serveFragment(endpoint, os): Response
    Services/
      MigrationFragmentRenderer.php      # rend Blade view + substitue placeholders
      MigrationStatusChecker.php         # lookup WorkstationMigrationStatus + log attempt
  resources/views/auth/v1/migration/
    fragment-cmd.blade.php               # script .cmd complet (~80 lignes)
    fragment-sh.blade.php                # script .sh complet (~70 lignes)
    fragment-noop-cmd.blade.php          # ~5 lignes "déjà migré"
    fragment-noop-sh.blade.php           # idem Linux
  ```
- **Pourquoi `Auth\V1\Migration` et pas `App\LegacyMigration`** : proximité immédiate avec 16.10 (`Auth\V1\Http\Controllers\EnrollController`, `Auth\V1\Models\WorkstationMigrationStatus`) — le module migration consomme directement ces classes et partage leur namespace conceptuel. Cohérent avec la recommandation Sprint Change Proposal §7.
- **Anti-pattern** : ne pas placer `MigrationController` sous `App\Http\Controllers\` racine — ce n'est pas un controller "métier" classique, c'est un module clos d'obsolescence programmée.

### D2 — Routes legacy `*_out.php` : **transformées en `MigrationController::serveFragment`**

- Les 8 routes legacy actuelles dans `routes/web.php` (lignes 467-560) :
  ```
  gpo/shortcuts_out.php       (shortcuts.legacy)            → ShortcutExportController::legacyDispatch
  gpo/wallpaper_out.php       (wallpaper.legacy)            → WallpaperController::legacyOut
  gpo/firefox_out.php         (app-policy.firefox.legacy)   → AppPolicyController::legacyFirefoxOut
  gpo/thunderbird_out.php     (app-policy.thunderbird.legacy) → AppPolicyController::legacyThunderbirdOut
  gpo/network_out.php         (gpo.network-out.legacy)      → NetworkOutController::legacyOut
  gpo/veyon_out.php           (gpo.veyon-out.legacy)        → VeyonOutController::legacyOut
  gpo/associations_out.php    (gpo.associations-out.legacy) → AssociationsOutController::legacyOut
  gpo/applications.php        (gpo.applications.legacy)     → ApplicationsScriptsController::generate
  ```
- **Transformation** : retrait du wrapping `Route::middleware('inject.bootstrap-fragment')->group(...)` (16.11) + remplacement du target controller par `MigrationController::serveFragment` avec un paramètre `endpoint` figé via Route closure :
  ```php
  Route::match(['GET', 'POST'], 'gpo/wallpaper_out.php',
      fn (Request $r) => app(MigrationController::class)->serveFragment($r, 'wallpaper'))
      ->name('migration.legacy.wallpaper');
  // … idem pour les 7 autres
  ```
- **Noms de routes** : `migration.legacy.{shortcuts,wallpaper,firefox,thunderbird,network,veyon,associations,applications}` (8 noms). Les anciens noms (`wallpaper.legacy`, `app-policy.firefox.legacy`, etc.) **sont conservés en alias** pour préserver les `route('wallpaper.legacy')` éventuels dans le code Laravel non-touchés par 16.13bis. **Decision** : `Route::name('migration.legacy.wallpaper')` ET `Route::name('wallpaper.legacy')` ne sont pas cumulables — il faut choisir. **Choix retenu** : on garde **uniquement les nouveaux noms** `migration.legacy.*`, et on grep le repo pour traquer toute référence aux anciens noms (testée AC10 D9). Si une référence est trouvée, on l'adapte. **Anti-pattern** : ne pas faire d'alias double-naming, ça complique la maintenance.
- **Middleware** : retrait complet de `inject.bootstrap-fragment`. Conservation du `throttle:300,1` (parité rentrée scolaire 300 postes simultanés). Pas de nouveau middleware.
- **`gpo/applications.php`** (= émetteur APCu md5 16.7) : **transformé aussi** en `MigrationController::serveFragment(..., endpoint: 'applications')`. Le fragment correspondant **n'écrit pas** dans APCu (le poste migré n'a plus besoin du md5 — il a JWT). Conséquence : la méthode `ApplicationsScriptsController::generate` 16.7 + l'`ApcuAppContextWriter` 16.7 deviennent **code mort sur la route legacy** mais restent appelés par `ApplicationsScriptsController::apiV1` (16.13). Cette **suppression de la pose APCu côté route legacy** est cohérente avec Q1 (option β retenue par défaut).
- **Routes `/api/v1/agent/bootstrap.cmd|.sh`** (16.11) : **supprimées de `routes/api.php`**. Les références (`BootstrapScriptController`, `resources/views/auth/v1/bootstrap.{cmd,sh}.blade.php`) sont également supprimées car la logique est entièrement portée par `MigrationController`. Voir D3.

### D3 — Suppression du middleware `InjectBootstrapFragment` (16.11) + du `BootstrapScriptController` (16.11)

- **Fichiers à supprimer** :
  - `app/Auth/V1/Http/Middleware/InjectBootstrapFragment.php`
  - `app/Auth/V1/Http/Controllers/BootstrapScriptController.php`
  - `resources/views/auth/v1/bootstrap.cmd.blade.php`
  - `resources/views/auth/v1/bootstrap.sh.blade.php`
  - `resources/views/auth/v1/bootstrap-fragment-cmd.blade.php` (= partial préfixe 16.11)
  - `resources/views/auth/v1/bootstrap-fragment-sh.blade.php`
- **Fichiers à modifier** :
  - `app/Auth/V1/Providers/AuthV1ServiceProvider.php` (`boot()`) : retrait des `Router::aliasMiddleware('inject.bootstrap-fragment', InjectBootstrapFragment::class)` + alias éventuel `bootstrap.cmd|.sh`. Conservation des autres alias 16.10/16.11 (`auth.v1.workstation`, `auth.v1.secure-headers`, `auth.v1.bootstrap`, `auth.v1.lan-only`, `auth.v1.bootstrap` etc.) — strict iso 16.10/16.11/16.12.
  - `routes/api.php` : retrait des 2 routes `agent.v1.bootstrap.cmd|.sh` (16.11 — protégées par `auth.v1.lan-only`).
  - `routes/web.php` : retrait des 2 `Route::middleware('inject.bootstrap-fragment')->group(...)` (lignes 467 et 520 environ).
- **Tests à supprimer** :
  - `tests/Feature/Auth/V1/Bootstrap/BootstrapScriptControllerTest.php`
  - `tests/Unit/Auth/V1/Http/Middleware/InjectBootstrapFragmentTest.php`
  - `tests/Feature/Auth/V1/Bootstrap/InjectBootstrapFragmentIntegrationTest.php`
  - **Et toutes leurs assertions transverses** (test archi `inject_bootstrap_fragment_middleware_is_attached_to_8_legacy_routes` doit être supprimé ; AC6.1 16.13 doit perdre son test `inject_bootstrap_fragment_middleware_still_attached_to_legacy_routes` — c'est un changement de contrat assumé).
- **Tests à modifier** :
  - `tests/Architecture/AuthV1NamespaceTest.php` : adapter `legacy_out_routes_are_preserved` — le test vérifiait que les 8 routes legacy existent ET ont leur controller métier originel. **Maintenant** : les 8 routes existent toujours mais pointent vers `MigrationController::serveFragment`. Adapter l'assertion pour vérifier que les 8 patterns d'URI legacy sont **toujours enregistrés** mais que leur callable cible est `MigrationController::serveFragment` (ou détecter le nouveau nom de route `migration.legacy.*`).
  - `tests/Architecture/ApiV1ConfigRoutesTest.php` (16.13) : supprimer le test `inject_bootstrap_fragment_middleware_still_attached_to_legacy_routes` ou le remplacer par `legacy_routes_now_point_to_migration_controller`. Le test `the_8_routes_are_registered_under_api_v1_workstation_config_prefix` (16.13 Q4) **reste vert** — `/api/v1/workstation-config/*` est intact.
- **Anti-pattern** : ne pas garder le code 16.11 en mode "deprecated commented out". On supprime franchement. La trace git suffit.

### D4 — Suppression du shim 1bis.18 (`legacy/gpo_shim.inc.php`) + archivage

- **Fichier à archiver** : `legacy/gpo_shim.inc.php` (+ ses dépendances si elles ne sont utilisées que par lui — à vérifier en T0.4 par grep `require_once`).
  - Candidats potentiels (à confirmer T0.4) : `legacy/stubs/gpo_deps.inc.php` (Story 1bis.18a stubs), helpers `_shim_gpo_*` dans `legacy/ldap.inc.php` (à inspecter — si purement utilisés par `gpo_shim.inc.php` → supprimer / sinon → laisser).
- **Cible d'archive** : `legacy/archived/gpo-shim-2026-05-20/` (format `gpo-shim-YYYY-MM-DD/` daté du jour de merge — à substituer par le dev au moment du commit). Convention horodatée iso pattern de `_bmad-output/codeReviews/` daté.
- **Fichier à modifier** : `legacy/bootstrap.php`
  - Ligne ~109-117 : retrait du bloc `─── GPO shim (story 1bis.18g) ───` + `require_once __DIR__ . '/gpo_shim.inc.php';`.
  - Si `gpo_shim.inc.php` est encore appelé par d'autres fichiers du dossier `legacy/` (autres `require_once` ou `include`) → adapter ces fichiers (typiquement : supprimer la ligne `require_once`).
- **Validation runtime** : un `legacy/bootstrap.php` sans le shim doit toujours charger sans fatal error. Test à ajouter : `tests/Architecture/LegacyBootstrapTest.php::legacy_bootstrap_loads_without_gpo_shim` — `require_once __DIR__ . '/../../legacy/bootstrap.php'` sans erreur.
- **Note** : le **scope dépot** de cette archive est strictement les fichiers du dossier `legacy/`. Le dossier `sambaedu/gpo/` mentionné par le tech-spec **n'existe pas** dans le repo `sambaedu-reload` (audit T0.4) — il vit dans le repo legacy SE4 séparé. Donc l'instruction "archive `sambaedu/gpo/` → `legacy/archived/gpo-YYYY-MM-DD/`" du tech-spec se traduit ici par l'archivage de `legacy/gpo_shim.inc.php` + dépendances **propres au shim** uniquement. Documenter cette précision dans Dev Agent Record.
- **Anti-pattern** : ne pas faire un `git rm` direct sans archive — laisser les fichiers dans `legacy/archived/gpo-shim-YYYY-MM-DD/` permet d'audit a posteriori si un bug remonte. Note : `legacy/archived/` doit être inclus dans le repo (pas en `.gitignore`).

### D5 — Templates Blade fragment cmd + sh + noop : **autoportants, no curl -k production**

- 4 vues Blade à créer :
  - `resources/views/auth/v1/migration/fragment-cmd.blade.php` (~80 lignes) — script Windows complet
  - `resources/views/auth/v1/migration/fragment-sh.blade.php` (~70 lignes) — script Linux complet
  - `resources/views/auth/v1/migration/fragment-noop-cmd.blade.php` (~10 lignes) — poste déjà migré, no-op uniforme
  - `resources/views/auth/v1/migration/fragment-noop-sh.blade.php` (~8 lignes) — idem Linux
- **Variables Blade injectées** par `MigrationFragmentRenderer` :
  - `$server_base_url` (résolu via `config('auth_v1.server.base_url')` ou fallback `https://<se4fs_name>.<ldap_domain>`)
  - `$ca_cert_pem_b64` (CA root local encodé base64 via `base64_encode($caInitializer->getCaCertPem())` — méthode existante 16.10)
  - `$enroll_endpoint`, `$refresh_endpoint`, `$ping_endpoint` (résolus via `route('agent.v1.enroll')`, etc.)
  - `$workstation_config_base` (résolu via `url('/api/v1/workstation-config')` — base path 16.13 Q4)
  - `$migration_message_fr` (= constante, voir D11 message user-friendly uniforme)
- **Contenu fragment-cmd.blade.php** (Windows) :
  1. `@echo off`
  2. `chcp 65001 > NUL` (UTF-8 console pour le message FR)
  3. Check existence registre `HKLM\SOFTWARE\SambaEdu\AuthV1\Migrated` (REG_DWORD = 1) ou présence `%ProgramData%\SambaEdu\auth.json`. Si oui → message inline + `exit /b 0`.
  4. Téléchargement et import CA root :
     - Le CA est embarqué inline en base64 (`$ca_cert_pem_b64`).
     - Décodage via `certutil.exe -decode tmpfile output.crt` ou `[Convert]::FromBase64String` en PowerShell.
     - Import dans `Cert:\LocalMachine\Root` via `Import-Certificate -CertStoreLocation Cert:\LocalMachine\Root -FilePath ...`.
  5. Collecte runtime :
     - `$uuid = (Get-CimInstance Win32_ComputerSystemProduct).UUID.ToLower()`
     - `$mac = (Get-NetAdapter | Where-Object Status -eq 'Up' | Select-Object -First 1).MacAddress`
     - `$hostname = $env:COMPUTERNAME`
  6. POST `/api/v1/agent/enroll` via `Invoke-RestMethod` (PowerShell — parse JSON natif).
     - **Pas de `-SkipCertificateCheck`** (16.12 Q5 — TLS strict fail-closed). Le CA installé en step 4 fait que la requête HTTPS doit réussir.
  7. Stockage tokens via DPAPI :
     - `$enc = [System.Security.Cryptography.ProtectedData]::Protect([Text.Encoding]::UTF8.GetBytes($accessToken), $null, 'LocalMachine')`
     - `New-ItemProperty -Path HKLM:\SOFTWARE\SambaEdu\AuthV1 -Name AccessTokenProtected -Value $enc -PropertyType Binary -Force`
  8. Mise à jour registre Win pour pointer vers `/api/v1/workstation-config/*` :
     - `New-ItemProperty -Path HKLM:\SOFTWARE\SambaEdu\AuthV1 -Name WallpaperUrl -Value "$workstation_config_base/wallpaper" -PropertyType String -Force`
     - Idem pour les 7 autres endpoints (`firefox`, `thunderbird`, `shortcuts`, `network`, `veyon`, `associations`, `applications-scripts`).
  9. Marquage migré : `New-ItemProperty -Path HKLM:\SOFTWARE\SambaEdu\AuthV1 -Name Migrated -Value 1 -PropertyType DWord -Force`
  10. Task Scheduler refresh tokens 25j (parité 16.11) — **OPTIONNEL D5bis** : peut être déféré en story dédiée si le scope explose. **Décision SM** : on **garde** dans 16.13bis (parité fonctionnelle avec 16.11 `bootstrap.cmd.blade.php` qui le faisait déjà — pas de régression). Si le dev découvre une complexité technique non anticipée, il peut le déférer en documentant Dev Agent Record + ticket follow-up.
  11. Message user-friendly final (FR uniforme) :
      `echo SambaEdu : migration terminee. Redemarrage automatique dans 30 secondes...`
  12. `shutdown /r /t 30 /c "SambaEdu : migration terminée, redémarrage automatique dans 30 secondes."`
- **Contenu fragment-sh.blade.php** (Linux) :
  1. `#!/bin/bash`
  2. `set -e` (strict)
  3. Check `[ -f /var/lib/sambaedu/migrated ] && { echo "SambaEdu : déjà migré, no-op."; exit 0; }`
  4. Décodage CA : `echo "{{ $ca_cert_pem_b64 }}" | base64 -d > /tmp/sambaedu-ca.crt`
  5. Installation CA : `cp /tmp/sambaedu-ca.crt /usr/local/share/ca-certificates/sambaedu-ca.crt && update-ca-certificates`
  6. Collecte runtime :
     - `UUID=$(cat /sys/class/dmi/id/product_uuid 2>/dev/null | tr 'A-Z' 'a-z' || true)`
     - `MAC=$(ip -br link | awk '$1!="lo" && $2=="UP" {print $3; exit}')`
     - `HOSTNAME=$(hostname -f)`
  7. POST `/api/v1/agent/enroll` via `curl -fsS -X POST $enroll_endpoint -d ...` (pas de `-k` — TLS strict 16.12 Q5).
  8. Parse JSON via `jq` (fallback `python3 -c "import json,sys;…"` si jq absent — pattern iso 16.11).
  9. Écriture `/var/lib/sambaedu/auth.json` (`0600 root:root`) :
     ```json
     {"access_token": "...", "refresh_token": "...", "expires_at": "...", "server_base_url": "{{ $server_base_url }}", "workstation_config_base": "{{ $workstation_config_base }}"}
     ```
     **Note importante** : `workstation_config_base` est le pivot — les scripts logon Linux migrés liront cette URL plutôt que `http://SE4FS/sambaedu/gpo/*_out.php`. Convention : les scripts Linux migrés utiliseront `jq -r .workstation_config_base /var/lib/sambaedu/auth.json` pour résoudre le base path à runtime.
  10. Mise à jour fichiers conf Linux pour rediriger les futurs appels (cf. D6 ci-dessous pour le détail des fichiers conf — le fragment écrit un `/etc/sambaedu/endpoints.conf` que les scripts logon parseront).
  11. Création systemd timer refresh 25j (parité 16.11) — voir D5bis.
  12. `touch /var/lib/sambaedu/migrated`
  13. Message user-friendly :
      `echo "SambaEdu : migration terminée. Redémarrage automatique dans 30 secondes..."`
  14. `shutdown -r +1 "SambaEdu : migration terminée, redémarrage automatique dans 1 minute."`
      Note : `shutdown -r +1` Linux n'accepte pas de granularité < minute (le -t 30s Windows est plus fin). Acceptable — sur Linux la commande équivalente la plus proche est `(sleep 30 && shutdown -r now) &` avec backgrounding. **Décision SM** : utiliser `(sleep 30 && /sbin/shutdown -r now) &` pour avoir 30s exact iso Windows.
- **Contenu fragment-noop-cmd.blade.php** : simple `@echo off` + `echo SambaEdu : poste deja migre, no-op.` + `exit /b 0`.
- **Contenu fragment-noop-sh.blade.php** : `#!/bin/bash` + `echo "SambaEdu: déjà migré, no-op."` + `exit 0`.
- **Charset des templates** : UTF-8 côté Blade. Le `chcp 65001` Windows assure que le `echo` console rend correctement les accents.
- **Anti-pattern** : ne pas hardcoder l'URL `https://se4fs.localdev.fr/...` — toujours passer par les variables Blade substituées.

### D6 — Mise à jour des configs poste pour pointer vers `/api/v1/workstation-config/*`

> Le fragment doit **rediriger les futurs appels** du poste migré vers les nouveaux endpoints natifs 16.13. La méthode varie par OS et par type de script appelant.

**Approche retenue** : pivot par fichier de configuration uniforme.

- **Windows** : registre `HKLM\SOFTWARE\SambaEdu\AuthV1\Endpoints` (créé par le fragment, step 8 D5). 8 valeurs string nommées (`WallpaperUrl`, `FirefoxUrl`, etc.) avec les URLs complètes `https://se4fs-<UAI>/api/v1/workstation-config/wallpaper` etc. Les scripts logon Windows (`logon.cmd` etc.) liront ces clés via `reg query` avant chaque appel HTTP. **Hypothèse** : les scripts logon Windows existants utilisent `curl.exe -fsS http://SE4FS/sambaedu/gpo/wallpaper_out.php?...` en dur. **16.13bis ne modifie PAS ces scripts logon legacy** — c'est hors-scope (les scripts logon vivent côté Windows, distribués par GPO/NETLOGON, leur refonte est Phase 3 agent Go). **Conséquence pratique** : le poste migré garde temporairement les vieux scripts logon qui pointent sur les URLs legacy. Au prochain appel, il reçoit un fragment-noop (poste déjà migré) → `exit 0` → le script logon legacy continue sans config. C'est **acceptable** car la suite du logon (resolved en GPO) continue d'invoquer les pages legacy de toute façon. **Le réel basculement vers `/api/v1/workstation-config/*` se fera quand l'admin re-générera les GPO via Story Phase 3 (hors-scope 16.13bis)**. Documenter cette limitation dans Dev Notes + runbook QA.
- **Linux** : fichier `/etc/sambaedu/endpoints.conf` (créé par le fragment, step 10 D5). Format key=value :
  ```
  WALLPAPER_URL=https://se4fs-<UAI>/api/v1/workstation-config/wallpaper
  FIREFOX_URL=https://se4fs-<UAI>/api/v1/workstation-config/firefox
  …
  ```
  Idem Linux : les scripts logon Linux (`/etc/profile.d/sambaedu-logon.sh`, etc.) ne sont pas réécrits par 16.13bis. La même limitation s'applique.
- **Décision SM assumée** : on **écrit** les nouveaux endpoints dans le registre / `/etc/sambaedu/endpoints.conf` pour préparer une future re-génération des scripts logon (Phase 3), mais **on n'attend pas** que les scripts logon legacy en tirent profit immédiatement. Le bénéfice immédiat est la marque "Migrated" qui empêche le re-déclenchement du fragment au boot suivant.
- **Anti-pattern** : ne pas tenter de réécrire les scripts logon Windows/Linux en cours d'exécution depuis le fragment — risque de corrompre le logon, hors-scope, complexité non justifiée.

### D7 — Eloquent : relation + accessor + scopes sur `App\Models\Workstation`

- **Relation** :
  ```php
  // app/Models/Workstation.php — ajout
  use App\Auth\V1\Models\WorkstationMigrationStatus;
  use Illuminate\Database\Eloquent\Relations\HasOne;

  public function migrationStatus(): HasOne
  {
      return $this->hasOne(WorkstationMigrationStatus::class, 'workstation_uuid', 'uuid');
  }
  ```
- **Accessor** :
  ```php
  /** Indique si le poste a basculé vers le mode SE5 (présence d'une row migration_status). */
  public function getMigratedAttribute(): bool
  {
      return $this->migrationStatus()->exists();
  }
  ```
- **Scopes** :
  ```php
  public function scopeMigrated(Builder $query): Builder
  {
      return $query->whereHas('migrationStatus');
  }

  public function scopeNotMigrated(Builder $query): Builder
  {
      return $query->whereDoesntHave('migrationStatus');
  }
  ```
- **FK considération** : `workstations.uuid` est `string|null`, pas indexé au niveau modèle (audit 16.13 DO-1) mais présumé indexé en DB Postgres prod. Pour les tests SQLite, ajouter `->index()` sur `workstation_uuid` (déjà fait en 16.11) + sur `workstations.uuid` via le helper `SeedsWorkstationConfig` 16.13 si pas encore fait.
- **Tests unit** ≥4 cas sur `Workstation` : (a) `migrationStatus()` retourne null si aucune row, (b) `migrationStatus()` retourne l'instance si row existe (verif via `uuid` match), (c) `$workstation->migrated` retourne `false` puis `true` après création d'une `WorkstationMigrationStatus`, (d) scope `Workstation::migrated()->count()` = 1 si 1 poste migré sur 3 seedés.
- **Anti-pattern** : ne pas créer une relation inverse côté `WorkstationMigrationStatus::workstation()` polluant — la table `workstations_migration_status` n'a pas de FK formelle vers `workstations` (cf. 16.11 D7 — un poste peut migrer avant d'apparaître dans `workstations`). On peut ajouter une relation pour la query inverse si utile : `belongsTo(Workstation::class, 'workstation_uuid', 'uuid')` qui retourne null si pas matché — au choix du dev, non-bloquant.

### D8 — UI admin minimaliste : colonne « Migration » + filtre + compteur

- **Page cible** : `/parc` (route nommée `app.parc.index` → `resources/views/pages/parc/index.blade.php` + partiel `resources/views/pages/parc/_partials/machines-tab.blade.php` audité T0.3).
- **Modifications partiel machines-tab.blade.php** :
  - Ajout d'une colonne `<th>Migration</th>` dans le `<thead>` après la colonne Statut existante.
  - Ajout d'une `<td>` correspondante dans le `<tbody>` avec le badge :
    ```blade
    <td class="text-center">
      @php
        $migrationLabel = match (true) {
            $machine->migrated => ['icon' => '✅', 'class' => 'badge-success', 'label' => 'Migré'],
            // ⏳ "en cours" = un attempt récent mais pas encore status final
            isset($machine->latest_migration_attempt) && $machine->latest_migration_attempt->status === 'started' => ['icon' => '⏳', 'class' => 'badge-warning', 'label' => 'En cours'],
            default => ['icon' => '❌', 'class' => 'badge-error', 'label' => 'Non migré'],
        };
      @endphp
      <span class="badge {{ $migrationLabel['class'] }} badge-sm" title="{{ $migrationLabel['label'] }}">
        {{ $migrationLabel['icon'] }}
      </span>
    </td>
    ```
  - **N+1 prevention** : le composant Livewire qui charge `$this->machines` doit `->with(['migrationStatus', 'latestMigrationAttempt'])` (eager loading). Le dev ajoute la relation `latestMigrationAttempt` côté `Workstation` (hasOne `WorkstationMigrationAttempt::class` ordered `latest('started_at')`). **Décision** : la relation `latestMigrationAttempt` est optionnelle pour le MVP — si scope explose, on s'en tient au badge ✅/❌ (booléen `migrated`) et on saute le `⏳ En cours`. Voir AC6 pour la formulation prudente.
- **Filtre** :
  - Ajout d'une propriété Livewire `$migrationFilter` (`null|migrated|not-migrated|in-progress`) sur le composant Parc.
  - Ajout dans le panneau de filtres existant (cf. parc index : recherche, filtre groupe…) d'un select `<x-molecules.select wire:model.live="migrationFilter">` avec 4 options.
  - Logique service : `if ($this->migrationFilter === 'migrated') $query->migrated()` etc.
- **Compteur « X/Y postes migrés »** :
  - Modifications de `resources/views/pages/parc/_partials/stats-cards.blade.php` : ajout d'une 5ème card (ou remplacement d'une existante si pas de place) avec le titre "Postes migrés" et la valeur `{{ $machineStats['migrated'] ?? 0 }} / {{ $machineStats['total'] ?? 0 }}`.
  - Côté composant Livewire : ajout dans la méthode `loadStats()` (à identifier T0.3) :
    ```php
    'migrated' => Workstation::migrated()->count(),
    'total' => Workstation::count(),
    ```
  - **Hypothèse** : la page `/parc` est scopée par collège via l'AD côté authent — si oui (pattern iso 7.1 délégation), le compteur est de facto par-collège.
- **Tests Livewire Feature** ≥3 cas : (a) la colonne Migration apparaît dans le rendu, (b) le filtre `migrationFilter=migrated` retourne uniquement les postes avec status, (c) la card "Postes migrés" affiche X/Y correctement.
- **Anti-pattern** : ne pas créer une nouvelle page Livewire dédiée `/admin/settings/migration` — c'est du scope creep. Décision Henri (Sprint Change Proposal §7) : UI **minimaliste** sur l'index workstations existant.

### D9 — Tests Architecture invariance : `/api/v1/workstation-config/*` 16.13 inchangé + ControlHub intact + suppression artefacts 16.11

- Créer un **nouveau** fichier `tests/Architecture/MigrationModuleArchitectureTest.php` avec ≥8 tests :
  1. `migration_controller_serve_fragment_route_is_registered_for_8_endpoints` — vérifie que les 8 routes `migration.legacy.*` existent et pointent vers `MigrationController::serveFragment`.
  2. `legacy_out_routes_no_longer_use_business_controllers` — assert que `routes/web.php` (lecture textuelle) ne contient **plus** `WallpaperController::class, 'legacyOut'` ni les autres `legacy*Out` sur les chemins `gpo/*_out.php`. **Garde-fou** : les méthodes `legacyOut` restent dans le code source des controllers (non touchées), mais ne sont plus référencées par le routing.
  3. `inject_bootstrap_fragment_middleware_no_longer_referenced` — grep `routes/web.php` → 0 occurrence de `inject.bootstrap-fragment`.
  4. `inject_bootstrap_fragment_class_no_longer_exists` — `class_exists(App\Auth\V1\Http\Middleware\InjectBootstrapFragment::class) === false`.
  5. `bootstrap_script_controller_no_longer_exists` — `class_exists(App\Auth\V1\Http\Controllers\BootstrapScriptController::class) === false`.
  6. `api_v1_workstation_config_routes_remain_intact` — assertion sur l'existence inchangée des 8 routes nommées `agent.v1.config.{wallpaper,…,applications-scripts}` (livrées par 16.13, fix Q4 préfixe `/api/v1/workstation-config/`).
  7. `controlhub_routes_remain_intact` — assertion sur `/api/v1/snapshot`, `/api/v1/workstation-groups/*` (parité 16.13 D8).
  8. `legacy_gpo_shim_no_longer_loaded_by_bootstrap` — lecture textuelle de `legacy/bootstrap.php` → 0 occurrence de `gpo_shim.inc.php`.
  9. `legacy_archived_directory_exists_with_dated_subfolder` — `is_dir(base_path('legacy/archived'))` ET au moins un sous-dossier `gpo-shim-YYYY-MM-DD/`.
- **Tests à mettre à jour** :
  - `tests/Architecture/ApiV1ConfigRoutesTest.php` (16.13) :
    - **Supprimer** le test `inject_bootstrap_fragment_middleware_still_attached_to_legacy_routes` (devenu sans objet — le middleware n'existe plus).
    - **Garder** le test `the_8_routes_are_registered_under_api_v1_workstation_config_prefix` (16.13 Q4) tel quel.
  - `tests/Architecture/AuthV1NamespaceTest.php` (16.10) :
    - Adapter `legacy_out_routes_are_preserved` — vérifie maintenant que les 8 chemins URI `gpo/*_out.php` sont enregistrés dans `routes/web.php` (lecture textuelle), mais ne vérifie plus l'identité du controller cible.

### D10 — Tests E2E parcours complet : legacy URL → fragment → migre → reboot simulé → /api/v1/workstation-config/* OK

- Créer un **nouveau** fichier `tests/Feature/Auth/V1/Migration/MigrationE2EScenarioTest.php` avec ≥4 tests E2E (chacun ~50 lignes) :
  1. `windows_workstation_migrates_via_fragment_then_consumes_api_v1` :
     - **Étape 1** : seed `Workstation` UUID=A (pas dans `workstations_migration_status`). Émet une requête `GET /sambaedu/gpo/wallpaper_out.php?os=windows&id=<md5>&uuid=A` (paramètre `uuid` query).
     - **Assert** : response 200 + Content-Type `text/plain` + body **commence** par `@echo off` + body contient `Migrated` + body contient `shutdown /r /t 30`.
     - **Étape 2** : simule l'exécution du fragment côté poste — pose une row `WorkstationMigrationStatus::create(['workstation_uuid' => 'A', 'os' => 'windows', 'migrated_at' => now()])` + génère un JWT valide via le trait `IssuesWorkstationJwt`.
     - **Étape 3** : `GET /api/v1/workstation-config/wallpaper` avec `Authorization: Bearer <JWT>`.
     - **Assert** : response 200 + Content-Type image (parité 16.13).
     - **Étape 4** : re-poste le `GET /sambaedu/gpo/wallpaper_out.php` avec UUID=A (= poste désormais migré).
     - **Assert** : response 200 + Content-Type `text/plain` + body est **fragment-noop** (commence par `@echo off` + contient `deja migre` + `exit /b 0`).
  2. `linux_workstation_migrates_via_fragment_then_consumes_api_v1` : idem mais OS=linux + assertions sur `#!/bin/bash`, `update-ca-certificates`, `shutdown -r +`, `/var/lib/sambaedu/migrated`.
  3. `migration_attempt_is_logged_on_fragment_request` : assert qu'un `WorkstationMigrationAttempt::create(...)` est inséré à l'étape 1 du test #1 (status='started', os='windows', client_ip).
  4. `applications_endpoint_no_longer_sets_apcu_context` : seed APCu vide → `GET /sambaedu/gpo/applications.php?os=linux&uuid=A` → assert response 200 fragment + assert `apcu_fetch('apps.<md5>')` retourne false (pas de pose APCu côté route legacy après 16.13bis — coherence Q1 option β).
- **Tests Feature unitaires `MigrationController`** ≥6 cas :
  - `serve_fragment_returns_cmd_for_windows_query_param`
  - `serve_fragment_returns_sh_for_linux_query_param`
  - `serve_fragment_falls_back_to_windows_when_os_param_missing` (parité legacy default)
  - `serve_fragment_returns_noop_when_workstation_already_migrated`
  - `serve_fragment_creates_migration_attempt_row`
  - `serve_fragment_response_uses_text_plain_no_store_headers`
- **Tests Feature UI Parc** ≥3 cas (cf. D8) :
  - `parc_index_displays_migration_column_with_badges`
  - `parc_filter_migration_status_returns_only_migrated`
  - `parc_stats_card_shows_x_of_y_migrated`
- **Tests Unit `WorkstationMigrationStatus`** (already covered 16.11) — **réutiliser**.
- **Tests Unit `Workstation::migrationStatus`** ≥4 cas (cf. D7).
- **Total tests cumulés attendus** : ≥17 nouveaux + plusieurs adaptations sur archi (D9). Cohérent avec charge 4-5j.

### D11 — Message user-friendly uniforme Windows + Linux

> Décision Henri 2026-05-19 (Sprint Change Proposal §7) : `shutdown /r /t 30` + message uniforme. Décision **B1 acquise**.

- Message FR canonique : **« SambaEdu : migration terminée, redémarrage automatique dans 30 secondes. »**
  - Windows : `shutdown /r /t 30 /c "SambaEdu : migration terminée, redémarrage automatique dans 30 secondes."`
  - Linux : utilisation de `wall` + `(sleep 30 && /sbin/shutdown -r now) &` (`shutdown -r +1` n'a pas le commentaire libre en GNU coreutils ; `wall` envoie le message à tous les TTY).
- **Affichage console pré-shutdown** (avant la commande) : `echo SambaEdu : migration terminee. Redemarrage automatique dans 30 secondes...` (sans accents Windows pour compat CP1252 fallback ; Linux peut utiliser accents).
- **Constante centralisée** : `App\Auth\V1\Migration\Support\MigrationMessages::REBOOT_FR = 'SambaEdu : migration terminée, redémarrage automatique dans 30 secondes.';` — réutilisé par les 2 templates Blade.
- **Internationalisation** : pas dans le scope 16.13bis (FR-only iso projet école français). Document follow-up Phase 3 si besoin.

### D12 — Charge dev : 4-5j (cadrage Sprint Change Proposal 2026-05-19)

- T0 (preflight) : 0.25j
- T1 (`MigrationController` + `MigrationFragmentRenderer` + `MigrationStatusChecker`) : 1j
- T2 (4 templates Blade fragment cmd/sh + noop) : 1j (incl. tests rendu Blade)
- T3 (transformation 8 routes legacy + suppression middleware/controller 16.11) : 0.5j
- T4 (archivage shim 1bis.18 + bootstrap.php cleanup) : 0.25j
- T5 (Eloquent relation + accessor + scopes Workstation) : 0.25j
- T6 (UI admin colonne + filtre + compteur) : 0.75j
- T7 (tests Feature MigrationController + tests Unit + tests Eloquent) : 0.5j
- T8 (tests E2E scénario complet × 4) : 0.5j
- T9 (test archi non-régression + suppression tests obsoletes 16.11) : 0.25j
- T10 (runbook QA append-only + sprint-status) : 0.25j
- T11 (validation finale + Dev Agent Record + File List) : 0.25j

**Total estimé** : 4-5j (cohérent Sprint Change Proposal 2026-05-19).

---

## Story

Comme **mainteneur du codebase `sambaedu-reload`** + **Henri en tant qu'admin déploiement multi-collège** :

I want
- **livrer le refactor final** du modèle migration SE4 → SE5 : transformer les 8 endpoints legacy `/sambaedu/gpo/*_out.php` en un mécanisme **fragment+reboot** stateless (au lieu du modèle 16.11 d'injection en préfixe d'une réponse legacy fonctionnelle), supprimer le middleware `InjectBootstrapFragment` (16.11) devenu obsolète, retirer le shim 1bis.18 (`legacy/gpo_shim.inc.php`) ;
- **isoler ce code transitoire** dans un module dédié `App\Auth\V1\Migration` portant un commentaire d'auto-obsolescence (« Ce code pourra être supprimé lorsqu'il n'existera plus de nécessité de migrer un déploiement SE4 vers SE5 »), pour faciliter le cleanup futur ;
- **exposer côté admin** une UI minimaliste (colonne « Migration » sur l'index workstations + filtre + compteur « X/Y migrés ») pour qu'Henri puisse suivre rapidement par collège l'avancement de bascule ;
- **garantir l'idempotence** : un poste qui appelle une URL legacy alors qu'il est déjà migré reçoit un fragment-noop (pas de reboot intempestif), un poste qui appelle une URL legacy alors qu'il n'est pas encore migré reçoit le fragment complet (download CA → enroll → reboot 30s) ;
- **garantir la non-régression 16.13** : les 8 endpoints natifs `/api/v1/workstation-config/*` (livrés par 16.13) restent **strictement inchangés** ; ControlHub `/api/v1/snapshot` reste intact ;
- **archiver le code legacy supprimé** dans `legacy/archived/gpo-shim-YYYY-MM-DD/` pour traçabilité ;
- **enrichir le runbook QA** `docs/qa/domains/auth.md` en append-only avec une section dédiée Story 16.13bis + scénarios numérotés (16.13bis-1 à 16.13bis-N) couvrant l'E2E + non-régression + smoke route registry ;

So que :
- (a) **le déploiement multi-collège fonctionne** sans coordination de timing ni critères de bascule globaux — chaque poste migre poste-par-poste au premier appel post-déploiement collège ;
- (b) **le code obsolète disparaît proprement** — middleware 16.11 supprimé + shim 1bis.18 archivé + module migration auto-documenté pour cleanup futur ;
- (c) **Henri dispose d'un suivi** opérationnel rapide (UI parc) sans avoir besoin d'une UI Livewire complexe à maintenir (différée Phase 3) ;
- (d) **les futures stories Phase 3 (agent Go)** trouvent un terrain assaini où les artefacts transitoires sont isolés dans `App\Auth\V1\Migration` et peuvent être supprimés en bloc le jour où aucun collège SE4 n'existe plus.

---

## Contexte

### État entrant (post-16.10 done, 16.11 done, 16.12 review, 16.13 review/done en pratique)

| Élément | État actuel | Action 16.13bis |
|---|---|---|
| Tables `workstations_migration_status` + `workstation_migration_attempts` | ✅ Créées 16.11 (migrations `2026_05_18_120000` + `_120100`) | **Réutilisées** — non modifiées |
| Modèle `App\Auth\V1\Models\WorkstationMigrationStatus` | ✅ Livré 16.11 | **Consommé en lecture** par `MigrationController` |
| Modèle `App\Auth\V1\Models\WorkstationMigrationAttempt` | ✅ Livré 16.11 | **Consommé en lecture+écriture** (insert status='started' au début du fragment, status='enrolled' à la fin via `EnrollController` 16.11) |
| Middleware `inject.bootstrap-fragment` (16.11) | ✅ Livré, attaché aux 8 routes legacy | **Supprimé** — classe + alias + référence routes |
| Controller `BootstrapScriptController` + routes `bootstrap.cmd|.sh` (16.11) | ✅ Livré | **Supprimé** — la logique est portée par `MigrationController` |
| Templates Blade `bootstrap.{cmd,sh}.blade.php` + `bootstrap-fragment-{cmd,sh}.blade.php` (16.11) | ✅ Livrés | **Supprimés** — remplacés par 4 nouveaux templates `fragment-{cmd,sh,noop-cmd,noop-sh}.blade.php` sous `auth.v1.migration` |
| Validator `LegacyBootstrapTokenValidator` (16.11) | ✅ Durci avec couple token↔UUID | **Conservé** — utilisé par `EnrollController` 16.10/16.11 |
| Middleware `EnsureLanIp` (16.11) | ✅ Livré + attaché à `/api/v1/agent/enroll` | **Conservé** strictement — l'enroll reste LAN-only |
| Routes legacy `gpo/{shortcuts,wallpaper,firefox,thunderbird,network,veyon,associations,applications}*.php` dans `routes/web.php` (lignes 467-560) | ✅ Actives + middleware `inject.bootstrap-fragment` + controllers métier `legacyOut`/`generate` | **Transformées** — controller cible devient `MigrationController::serveFragment` ; middleware `inject.bootstrap-fragment` retiré ; nouveau nom de route `migration.legacy.*` |
| Méthodes `legacyOut(...)` / `generate(...)` des 7 controllers métier (4.7/4.8/16.3a/b/c/16.7) | ✅ Livrées Phase 1 | **Inchangées** — deviennent code mort sur le routing legacy mais restent appelables (tests legacy 4.7/4.8/16.3a/b/c/16.7 peuvent rester verts via `withoutMiddleware` ou via injection directe controller). À documenter en `@deprecated` (follow-up Phase 3 cleanup). |
| Méthodes `apiV1(...)` 16.13 sur les 7 controllers | ✅ Livrées 16.13 | **Inchangées** — toujours servies par `/api/v1/workstation-config/*` |
| Service `WorkstationConfigContextResolver` 16.13 (`App\Gpo\Services`) | ✅ Livré 16.13 | **Inchangé** |
| Shim `legacy/gpo_shim.inc.php` (1bis.18g) | ✅ Chargé par `legacy/bootstrap.php` ligne 117 | **Supprimé** — archivé dans `legacy/archived/gpo-shim-YYYY-MM-DD/` + référence retirée de `legacy/bootstrap.php` |
| `legacy/stubs/gpo_deps.inc.php` (1bis.18a) | ✅ Chargé par `legacy/bootstrap.php` | **À vérifier T0.4** — si stubs uniquement utilisés par le shim 1bis.18g → archiver aussi ; sinon laisser |
| Channel logs `auth-v1` | ✅ Livré 16.10 (driver daily) | **Réutilisé** pour tous les logs migration (`migration.fragment.served`, `migration.fragment.noop`, `migration.attempt.created`) |
| Runbook QA `docs/qa/domains/auth.md` | ✅ Sections 1-28 + post-correctifs (16.10/16.11/16.12/16.13) | **Append** une section `## Story 16.13bis` + scénarios numérotés 16.13bis-1 à 16.13bis-N (≥10) |
| UI admin `/parc` index + machines-tab partial | ✅ Livrée Epic 4 + 7 | **Étendue** — colonne Migration + filtre + compteur (cf. D8) |

### Topologie réseau Sambaedu (rappel)

- SE4FS local strictement LAN (memory `feedback_auth_iso_legacy`). Le `EnsureLanIp` 16.11 reste actif sur `/api/v1/agent/enroll` — le fragment 16.13bis embarque l'URL absolue `https://se4fs-<UAI>/api/v1/agent/enroll` (substituée via Blade) que le poste appellera depuis le LAN.
- Le CA root local (16.10 D9 — un CA par établissement) est embarqué inline base64 dans le fragment via `$ca_cert_pem_b64` → installé par le script poste → permet le HTTPS strict (pas de `-k` curl ni `-SkipCertificateCheck` PowerShell — fail-closed 16.12 Q5).
- Pas de changement DNS/DHCP/PXE — hors-scope Epic 18 (cancelled).

### Risques entrants (analyse SM 2026-05-20)

| Risque | Sévérité | Mitigation 16.13bis |
|---|---|---|
| **R1 — Décision proof-of-possession (Option α vs β)** : `gpo/applications.php` natif (16.7) pose la clé md5 APCu en émettant un `apps.<md5>` qui est ensuite consommé par les 7 autres endpoints legacy. Si on bascule `gpo/applications.php` en fragment migration (Option β), un poste **non encore migré** qui appellerait directement (sans passer par `applications.php` au préalable) un autre endpoint legacy `*_out.php` recevrait quoi ? | 🟡 Moyenne | **Hypothèse retenue par défaut** : Option β (`gpo/applications.php` aussi transformé en fragment). Le poste non-migré reçoit un fragment quel que soit l'endpoint qu'il appelle (les 8 transformations sont uniformes). La preuve-de-possession md5 disparaît avec le shim — le poste migré utilise désormais JWT 16.10. Voir Q1 ci-dessous pour le détail + fallback. |
| **R2 — Reboot poste pendant flux user** (UX) : un utilisateur en session active reçoit un fragment migration qui force `shutdown /r /t 30`. Perte de travail non sauvegardé si l'user n'a pas eu le temps de fermer ses apps. | 🟡 Moyenne | Le message `wall` Linux + `shutdown /c "..."` Windows alerte 30s avant. **Acceptable** pour Sambaedu (postes scolaires, sessions brèves, redémarrage matin habituel). **Mitigation** : la migration ne déclenche typiquement qu'**une seule fois par poste** (idempotence). Donc l'impact UX est limité au boot post-déploiement collège — quand l'admin a généralement annoncé une maintenance. Documenter dans runbook QA + Dev Notes. **Décision** : ne pas tenter de "fenêtre de reboot programmable" via cron — over-engineering. Si problème terrain remonté → Phase 3 story dédiée. |
| **R3 — Perte de session SMB durant exécution fragment** : pendant que le poste télécharge CA + s'enrôle, les sessions SMB `\\SE4FS\install\...` montées au logon pourraient se désynchroniser si l'enroll modifie l'identité du poste auprès du serveur. | 🟢 Faible | Le fragment **ne touche pas** la session SMB — il ajoute des entrées registre + un CA + un timer Task Scheduler. La session SMB existante (montée par `wpkg.cmd`) reste valide jusqu'au reboot. **Vérification** : les credentials Samba sont basés sur `$samaccountname` + `$password` du user — indépendants du JWT poste. **Acceptable**. |
| **R4 — OU AD perdues si registre corrompu mi-fragment** : si le fragment Windows échoue mi-exécution (ex. coupure réseau juste après écriture HKLM mais avant `shutdown /r`), le registre peut être dans un état partiel. | 🟡 Moyenne | **Idempotence côté poste** : le check `if exist HKLM:\SOFTWARE\SambaEdu\AuthV1\Migrated` (step 3 D5 fragment-cmd) **n'est posé qu'à l'avant-dernier step**. Donc si le fragment crash avant le step 9 (set `Migrated=1`), au prochain boot le poste relance le fragment depuis le début. Les writes registres précédents (CA installé, AccessTokenProtected) sont écrasés idempotenmment. **Tests** : `MigrationE2EScenarioTest::test_fragment_idempotent_on_partial_failure` (à ajouter — simule crash après step 5 mais avant step 9, re-execute fragment, assert convergence finale). |
| **R5 — Suppression du shim 1bis.18g casse `legacy/bootstrap.php`** | 🟢 Faible | Test archi `legacy_bootstrap_loads_without_gpo_shim` (D9 test #9) — `require_once __DIR__ . '/../../legacy/bootstrap.php'` sans erreur. **Si fatal** → bloquant, dev investigue avant merge (T0.4 préparatoire). |
| **R6 — Méthodes `legacyOut` deviennent code mort mais tests Feature 4.7/4.8/16.3a/b/c/16.7 sont attachés à des routes** | 🟡 Moyenne | Les tests Feature legacy existants (`tests/Feature/Wallpaper/LegacyOutEndpointTest`, etc.) appellent `$this->get('/sambaedu/gpo/wallpaper_out.php?...')` directement. Après 16.13bis, ces URLs retournent un fragment migration, plus le wallpaper. **Conséquence** : les tests existants vont **casser**. **Options** : (a) supprimer ces tests (les méthodes `legacyOut` deviennent code mort intentionnellement), (b) les adapter pour utiliser `withoutMiddleware('throttle:300,1')` + appel direct controller `$this->withInstance(WallpaperController::class)->legacyOut(...)`, (c) marquer skip avec note. **Décision SM** : **Option (a) sélective** — supprimer les tests Feature qui ciblent les routes legacy via URL, **conserver** les tests qui appellent directement le controller via Pest (`it('renders wallpaper', fn () => app(WallpaperController::class)->legacyOut($mockRequest))`). Si le dev découvre que certains tests Feature critiques 4.7/4.8 cassent → adapter au cas par cas, documenter dans Dev Agent Record. |
| **R7 — Conflit nommage `migration.legacy.*` vs anciens noms `wallpaper.legacy` etc.** | 🟢 Faible | Grep T0.5 dans `app/` + `routes/` + `resources/views/` + `tests/` → chercher `route('wallpaper.legacy')`, `route('app-policy.firefox.legacy')`, etc. Remplacer par `route('migration.legacy.wallpaper')` etc. **Tests à mettre à jour** si grep positif. Acceptable charge. |

### Pré-requis (à valider en T0)

- **16.10 done** : ✅ (sprint-status 2026-05-18).
- **16.11 done** : ✅ (sprint-status 2026-05-19). Tables productives + middleware `EnsureLanIp` + `LegacyBootstrapTokenValidator` durci.
- **16.13 review/done en pratique** : ✅ Henri a tranché — code mergé sur main, smoke VM à faire post-merge sans bloquer (cf. instructions SM 2026-05-20).
- **16.12 review** : ✅ (sprint-status 2026-05-18). Pattern channel `auth-v1` + correlation_id réutilisable.
- **Vendor/ absent dans worktree** : pattern static delivery confirmé iso 16.10/16.11/16.12/16.13.

---

## Acceptance Criteria

> AC organisées en **9 volets**. Volet 9 (QA + doc) est **append-only** sur `docs/qa/domains/auth.md` § Story 16.13bis.

### AC1 — `MigrationController::serveFragment(endpoint, os)` text/plain × 8 endpoints (D1, D2, D5)

1. **AC1.1** — Création de `App\Auth\V1\Migration\Http\Controllers\MigrationController` avec méthode publique `serveFragment(Request $request, string $endpoint): Response`. Le `$endpoint` reçoit une des 8 valeurs : `shortcuts`, `wallpaper`, `firefox`, `thunderbird`, `network`, `veyon`, `associations`, `applications`.
2. **AC1.2** — La méthode :
   - Détermine l'OS via `MigrationFragmentRenderer::detectOs(Request)` (priorité 1 query `?os=`, priorité 2 User-Agent — pattern iso 16.11 D3).
   - Extrait `$declaredUuid = (string) $request->input('uuid', '')`. Si non-vide ET regex UUID v4 valide.
   - Crée un `WorkstationMigrationAttempt` (status='started', os, client_ip, user_agent, workstation_uuid=$declaredUuid if valid).
   - Lookup `WorkstationMigrationStatus::where('workstation_uuid', $declaredUuid)->exists()` si UUID fourni.
   - Si poste **déjà migré** : rend le template `auth.v1.migration.fragment-noop-{cmd,sh}` + log info `migration.fragment.noop`.
   - Sinon : rend le template `auth.v1.migration.fragment-{cmd,sh}` avec les variables substituées (cf. D5) + log info `migration.fragment.served`.
3. **AC1.3** — Réponse HTTP : `200 OK` + `Content-Type: text/plain; charset=utf-8` (Linux) ou `text/plain; charset=cp1252` (Windows ? **Décision** : `utf-8` les deux — le `chcp 65001` Windows assure le rendu correct). `Cache-Control: no-store`.
4. **AC1.4** — Les 8 routes dans `routes/web.php` pointent vers la closure `fn ($r) => app(MigrationController::class)->serveFragment($r, '<endpoint>')` avec les noms de route `migration.legacy.{endpoint}`. Pas de middleware `inject.bootstrap-fragment` (= supprimé). Throttle `300,1` préservé.
5. **AC1.5** — Le fragment Windows contient (assertion grep) : `@echo off`, `chcp 65001`, `if exist HKLM:\SOFTWARE\SambaEdu\AuthV1\Migrated`, `certutil`, `Invoke-RestMethod`, `ProtectedData::Protect`, `shutdown /r /t 30 /c "SambaEdu`, le `$server_base_url` substitué.
6. **AC1.6** — Le fragment Linux contient (assertion grep) : `#!/bin/bash`, `set -e`, `[ -f /var/lib/sambaedu/migrated ]`, `update-ca-certificates`, `curl -fsS -X POST` (PAS `curl -k`), `jq`, `chmod 0600`, `chown root:root`, `shutdown -r now` ou `(sleep 30 && /sbin/shutdown -r now) &`, le `$workstation_config_base` substitué.
7. **AC1.7** — Le fragment-noop Windows est ~5-10 lignes : `@echo off` + `echo SambaEdu : poste deja migre, no-op.` + `exit /b 0`. Aucune commande de modification (pas de `certutil`, pas de `shutdown`).
8. **AC1.8** — Le fragment-noop Linux idem.

### AC2 — Suppression du middleware `InjectBootstrapFragment` + sync routes (D3)

1. **AC2.1** — Le fichier `app/Auth/V1/Http/Middleware/InjectBootstrapFragment.php` est **supprimé** (`git rm`).
2. **AC2.2** — Le fichier `app/Auth/V1/Http/Controllers/BootstrapScriptController.php` est **supprimé**.
3. **AC2.3** — Les 4 templates Blade 16.11 sont **supprimés** : `bootstrap.cmd.blade.php`, `bootstrap.sh.blade.php`, `bootstrap-fragment-cmd.blade.php`, `bootstrap-fragment-sh.blade.php`.
4. **AC2.4** — L'alias `inject.bootstrap-fragment` est retiré de `AuthV1ServiceProvider::boot()`. Les autres alias 16.10/16.11 restent (`auth.v1.workstation`, `auth.v1.secure-headers`, `auth.v1.bootstrap`, `auth.v1.lan-only`).
5. **AC2.5** — Les 2 routes `agent.v1.bootstrap.cmd|.sh` sont retirées de `routes/api.php`.
6. **AC2.6** — Les 2 `Route::middleware('inject.bootstrap-fragment')->group(...)` sont retirés de `routes/web.php` (lignes ~467 et ~520). Les 8 routes legacy à l'intérieur sont **conservées dans leur emplacement** mais ré-écrites pour pointer sur `MigrationController::serveFragment` (cf. AC1.4).
7. **AC2.7** — Les tests 16.11 obsolètes sont supprimés : `BootstrapScriptControllerTest.php`, `InjectBootstrapFragmentTest.php` (unit), `InjectBootstrapFragmentIntegrationTest.php`. Le test archi 16.11 `inject_bootstrap_fragment_middleware_is_attached_to_8_legacy_routes` (dans `AuthV1NamespaceTest.php`) est également retiré.
8. **AC2.8** — `class_exists(App\Auth\V1\Http\Middleware\InjectBootstrapFragment::class) === false` et `class_exists(App\Auth\V1\Http\Controllers\BootstrapScriptController::class) === false` (testé en archi cf. D9).

### AC3 — Suppression du shim 1bis.18 + archive horodatée (D4)

1. **AC3.1** — Le fichier `legacy/gpo_shim.inc.php` est **déplacé** vers `legacy/archived/gpo-shim-YYYY-MM-DD/gpo_shim.inc.php` (avec `YYYY-MM-DD` = date du jour de merge — substituée par le dev).
2. **AC3.2** — Si T0.4 confirme que `legacy/stubs/gpo_deps.inc.php` n'est utilisé que par le shim 1bis.18g → il est aussi déplacé dans le même dossier archive.
3. **AC3.3** — Les helpers `_shim_gpo_*` dans `legacy/ldap.inc.php` (story 1bis.18g, lignes 298+, 362, 754, 1029) — vérification T0.4 : si purement utilisés par `gpo_shim.inc.php` → on évalue suppression ; sinon → on laisse. **Décision par défaut** : on **laisse** ces helpers dans `legacy/ldap.inc.php` (ils sont défensifs, ne consomment pas de ressources hors-appel, et leur suppression peut casser des paths non identifiés). À documenter en `Dev Agent Record`.
4. **AC3.4** — Le bloc `─── GPO shim (story 1bis.18g) ───` (lignes ~109-117) de `legacy/bootstrap.php` est retiré, y compris le `require_once __DIR__ . '/gpo_shim.inc.php';`.
5. **AC3.5** — Test archi `legacy_bootstrap_loads_without_gpo_shim` : un `require_once base_path('legacy/bootstrap.php')` dans un test isolé charge sans fatal error.
6. **AC3.6** — `legacy/archived/gpo-shim-YYYY-MM-DD/README.md` est créé avec le contenu :
   ```markdown
   # Archive : gpo_shim.inc.php (Story 1bis.18g)

   Archivé le YYYY-MM-DD par Story 16.13bis (Sprint Change Proposal 2026-05-19).

   Raison : le modèle de migration SE4 → SE5 ayant basculé en fragment+reboot
   stateless (App\Auth\V1\Migration), le shim 1bis.18g (bridge Kerberos pour
   gpo_shim + fallbacks SYSVOL) n'est plus utilisé par sambaedu-reload.

   Voir : _bmad-output/implementation-artifacts/16-13bis-module-migration-simplifie.md
   ```
7. **AC3.7** — Le test archi `legacy_archived_directory_exists_with_dated_subfolder` (D9 test #9) vérifie la présence de `legacy/archived/gpo-shim-YYYY-MM-DD/` avec au moins le fichier `gpo_shim.inc.php` à l'intérieur.

### AC4 — Eloquent : relation + accessor + scopes sur `Workstation` (D7)

1. **AC4.1** — La méthode `App\Models\Workstation::migrationStatus(): HasOne` est ajoutée avec FK `workstation_uuid` ↔ PK `uuid`.
2. **AC4.2** — L'accessor `getMigratedAttribute(): bool` est ajouté — retourne `true` si une `WorkstationMigrationStatus` existe pour le `workstation_uuid` du poste.
3. **AC4.3** — Les scopes `scopeMigrated(Builder)` et `scopeNotMigrated(Builder)` sont ajoutés et utilisent `whereHas`/`whereDoesntHave('migrationStatus')`.
4. **AC4.4** — Tests Unit `tests/Unit/Models/WorkstationMigrationTest.php` (≥4 cas) :
   - `migration_status_relation_returns_null_when_no_row` ✓
   - `migration_status_relation_returns_instance_when_row_exists` ✓
   - `migrated_accessor_returns_true_after_status_created` ✓
   - `scope_migrated_returns_only_migrated_workstations` ✓
   - `scope_not_migrated_returns_only_non_migrated_workstations` ✓ (bonus 5ème cas)

### AC5 — UI admin minimaliste colonne + filtre + compteur (D8)

1. **AC5.1** — Le partial `resources/views/pages/parc/_partials/machines-tab.blade.php` est modifié pour ajouter une colonne « Migration » entre les colonnes « Statut » et « Déploiement », avec le badge ✅/⏳/❌ selon l'état (cf. D8 implémentation).
2. **AC5.2** — La page Livewire `/parc` ajoute une propriété `$migrationFilter` (`null|migrated|not-migrated|in-progress`) + un select dans la barre de filtres existante. Le filtre fonctionne (assertion Livewire `$component->set('migrationFilter', 'migrated')->assertSee(...)` valide).
3. **AC5.3** — Le partial `stats-cards.blade.php` ajoute une carte « Postes migrés » avec la valeur `$machineStats['migrated'] / $machineStats['total']` (ex: `12 / 25`).
4. **AC5.4** — Eager loading : le composant Livewire qui charge les workstations utilise `->with('migrationStatus')` pour éviter N+1.
5. **AC5.5** — Tests Feature Livewire ≥3 cas (`tests/Feature/Pages/Parc/MigrationUiTest.php`) :
   - `parc_index_displays_migration_column_with_correct_badge`
   - `parc_filter_migration_returns_only_migrated_workstations`
   - `parc_stats_card_shows_correct_migrated_count`
6. **AC5.6** — La modification UI **ne casse pas** les tests Feature existants de la page `/parc` (machines listing, filtres existants, pagination, batch actions). Re-run iso 4.2/4.3/4.4/7.1.

### AC6 — Tests Feature `MigrationController` ≥6 cas (D10)

1. **AC6.1** — Création de `tests/Feature/Auth/V1/Migration/MigrationControllerTest.php` avec ≥6 tests (cf. D10 liste).
2. **AC6.2** — Tests Unit `MigrationFragmentRenderer` ≥3 cas (`detectOs` query priority, UA fallback, default windows).
3. **AC6.3** — Tests Unit `MigrationStatusChecker` ≥3 cas (status exists → noop, status absent → full fragment, attempt is logged).
4. **AC6.4** — Tests Unit rendu Blade `MigrationFragmentRendererBladeTest` ≥4 cas (cmd contient `shutdown /r /t 30` substitué ; sh contient `(sleep 30 && /sbin/shutdown -r now) &` ; noop-cmd contient `deja migre` ; noop-sh contient `déjà migré`).

### AC7 — Tests E2E parcours complet ≥4 scénarios (D10)

1. **AC7.1** — Création de `tests/Feature/Auth/V1/Migration/MigrationE2EScenarioTest.php` avec ≥4 tests (cf. D10 liste détaillée).
2. **AC7.2** — Au moins un test E2E couvre Windows ; au moins un Linux ; au moins un teste l'idempotence (poste re-appelle après migration → reçoit fragment-noop).
3. **AC7.3** — Au moins un test vérifie l'insertion `WorkstationMigrationAttempt` (status='started', os, client_ip).
4. **AC7.4** — Au moins un test vérifie qu'aucune row APCu n'est posée par la route `gpo/applications.php` après bascule en fragment (parité Q1 option β).

### AC8 — Tests Architecture non-régression `/api/v1/workstation-config/*` 16.13 + ControlHub intact (D9)

1. **AC8.1** — Création de `tests/Architecture/MigrationModuleArchitectureTest.php` avec les 9 tests listés en D9.
2. **AC8.2** — Le test `api_v1_workstation_config_routes_remain_intact` vérifie la présence inchangée des 8 routes nommées `agent.v1.config.*` (livrées 16.13 Q4).
3. **AC8.3** — Le test `controlhub_routes_remain_intact` vérifie `/api/v1/snapshot` et autres routes ControlHub.
4. **AC8.4** — Le test `legacy_out_routes_no_longer_use_business_controllers` vérifie que les chemins `gpo/*_out.php` ne référencent plus `WallpaperController::class, 'legacyOut'`, etc. (lecture textuelle de `routes/web.php`).
5. **AC8.5** — Le test `inject_bootstrap_fragment_class_no_longer_exists` valide la suppression de la classe.
6. **AC8.6** — Le test `legacy_bootstrap_loads_without_gpo_shim` valide la suppression du shim 1bis.18g.
7. **AC8.7** — Le test 16.13 `ApiV1ConfigRoutesTest::inject_bootstrap_fragment_middleware_still_attached_to_legacy_routes` est **supprimé** (devenu sans objet). Le test 16.13 `the_8_routes_are_registered_under_api_v1_workstation_config_prefix` reste vert tel quel.

### AC9 — Runbook QA `docs/qa/domains/auth.md` enrichi append-only (D10)

1. **AC9.1** — Ajout d'une section `## Story 16.13bis — Module migration simplifié (SE4 → SE5)` après la section `## Post-correctifs 2026-05-19 (review code-review 16.13)` (qui termine actuellement le fichier ligne 1505+).
2. **AC9.2** — La nouvelle section comprend ≥6 sous-sections numérotées (Sections 29-34) :
   - `### Section 29 — Endpoints legacy transformés en MigrationController`
   - `### Section 30 — Fragment Windows complet (download CA + enroll + reboot)`
   - `### Section 31 — Fragment Linux complet`
   - `### Section 32 — Idempotence (poste déjà migré → noop)`
   - `### Section 33 — UI admin Parc : colonne + filtre + compteur`
   - `### Section 34 — Non-régression 16.13 + ControlHub + suppression artefacts 16.11`
3. **AC9.3** — ≥12 scénarios numérotés `16.13bis-1` à `16.13bis-12` (sans rupture de numérotation interne — on continue à incrémenter même si 16.13 termine à 16.13-12 ; ici on repart à 16.13bis-1 dédié) couvrant :
   - smoke `curl http://se4fs/sambaedu/gpo/wallpaper_out.php?os=windows&uuid=A` → fragment Windows complet
   - smoke `curl http://se4fs/sambaedu/gpo/network_out.php?os=linux&uuid=B` → fragment Linux complet
   - seed `WorkstationMigrationStatus` UUID=A puis re-appel → fragment-noop
   - smoke `curl https://se4fs/api/v1/workstation-config/wallpaper` avec JWT → 200 OK (non-régression 16.13)
   - vérification `/api/v1/snapshot` ControlHub → 200 OK
   - test absence middleware `inject.bootstrap-fragment` dans `php artisan route:list`
   - test absence routes `agent.v1.bootstrap.cmd|.sh` dans `php artisan route:list`
   - test présence `legacy/archived/gpo-shim-YYYY-MM-DD/gpo_shim.inc.php`
   - test UI parc colonne Migration affichée
   - test UI parc filtre Migration fonctionne
   - test UI parc compteur X/Y migrés
   - test E2E logon poste réel Windows (validation Henri post-merge sur poste réel — action terrain, hors-scope dev)
4. **AC9.4** — Checklist rapide en fin de section pour validation Henri post-merge.

---

## Tasks / Subtasks

### T0 — Preflight (0.25j)

- [x] **T0.1** Confirmer que `App\Models\Workstation::class` existe avec colonne `uuid` (vérification fait par 16.13 DO-1 — `uuid` nullable + scope `withUuid()`, relation `groups()` au lieu de `workstationGroups()`).
- [x] **T0.2** Inspecter `routes/web.php` lignes 467-560 pour identifier précisément les 8 routes legacy à transformer + leurs noms actuels + leurs middlewares.
- [x] **T0.3** Lire `resources/views/pages/parc/index.blade.php` + `_partials/machines-tab.blade.php` + `_partials/stats-cards.blade.php` + le composant Livewire associé (sous `app/Livewire/` ou `app/Http/Livewire/` selon convention projet) pour identifier le nom des propriétés à étendre (`$machines`, `$machineStats`, `loadStats()`, etc.).
- [x] **T0.4** Confirmer la liste des fichiers à archiver dans `legacy/` :
  - `legacy/gpo_shim.inc.php` : **certain** (D4).
  - `legacy/stubs/gpo_deps.inc.php` : grep pour vérifier s'il est utilisé ailleurs que par `gpo_shim.inc.php` — si non → archive ; si oui → laisse.
  - Helpers `_shim_gpo_*` dans `legacy/ldap.inc.php` : décision par défaut = laisser (AC3.3).
- [x] **T0.5** Grep `app/`, `routes/`, `resources/views/`, `tests/` pour les anciens noms de route `wallpaper.legacy`, `app-policy.firefox.legacy`, `app-policy.thunderbird.legacy`, `shortcuts.legacy`, `gpo.network-out.legacy`, `gpo.veyon-out.legacy`, `gpo.associations-out.legacy`, `gpo.applications.legacy` → liste des fichiers à mettre à jour (renommer en `migration.legacy.*`).
- [x] **T0.6** Vérifier que les tests Feature legacy 4.7/4.8/16.3a/b/c/16.7 ciblent bien les URLs `/sambaedu/gpo/*_out.php` (et donc vont casser post-16.13bis) ou s'ils appellent directement le controller — adaptation R6.
- [x] **T0.7** Confirmer la disponibilité du `CaInitializer::getCaCertPem()` (16.10) pour récupérer le CA root local en base64 dans `MigrationFragmentRenderer`.

### T1 — Module `App\Auth\V1\Migration` (1j) (AC1, AC6)

- [x] **T1.1** Créer `app/Auth/V1/Migration/Http/Controllers/MigrationController.php` (méthode `serveFragment(Request, string $endpoint): Response`).
- [x] **T1.2** Créer `app/Auth/V1/Migration/Services/MigrationFragmentRenderer.php` (méthodes `render(string $os, bool $alreadyMigrated, array $vars): string` + `detectOs(Request): string`).
- [x] **T1.3** Créer `app/Auth/V1/Migration/Services/MigrationStatusChecker.php` (méthode `isMigrated(string $uuid): bool` + `logAttempt(...)`).
- [x] **T1.4** Créer `app/Auth/V1/Migration/Support/MigrationMessages.php` (constante `REBOOT_FR`).
- [x] **T1.5** Tests Unit `MigrationFragmentRendererTest`, `MigrationStatusCheckerTest` (≥3 cas chacun).
- [x] **T1.6** Tests Feature `MigrationControllerTest` ≥6 cas (cf. D10).

### T2 — Templates Blade fragment cmd/sh + noop (1j) (AC1.5, AC1.6, AC1.7, AC1.8)

- [x] **T2.1** Créer `resources/views/auth/v1/migration/fragment-cmd.blade.php` (~80 lignes — cf. D5 contenu Windows).
- [x] **T2.2** Créer `resources/views/auth/v1/migration/fragment-sh.blade.php` (~70 lignes — cf. D5 contenu Linux).
- [x] **T2.3** Créer `resources/views/auth/v1/migration/fragment-noop-cmd.blade.php` + `fragment-noop-sh.blade.php` (~5-10 lignes chacun).
- [x] **T2.4** Tests Unit rendu Blade `MigrationFragmentRendererBladeTest` ≥4 cas (cf. AC6.4).

### T3 — Transformation des 8 routes legacy + suppression artefacts 16.11 (0.5j) (AC1.4, AC2, AC8)

- [x] **T3.1** Modifier `routes/web.php` : retirer les 2 `Route::middleware('inject.bootstrap-fragment')->group(...)`. Les 8 routes `gpo/*_out.php` + `gpo/applications.php` à l'intérieur sont remplacées par des closures pointant vers `MigrationController::serveFragment`. Noms de route `migration.legacy.{endpoint}`.
- [x] **T3.2** Supprimer `app/Auth/V1/Http/Middleware/InjectBootstrapFragment.php`.
- [x] **T3.3** Supprimer `app/Auth/V1/Http/Controllers/BootstrapScriptController.php`.
- [x] **T3.4** Supprimer les 4 templates Blade 16.11 (`bootstrap.cmd.blade.php`, `bootstrap.sh.blade.php`, `bootstrap-fragment-cmd.blade.php`, `bootstrap-fragment-sh.blade.php`).
- [x] **T3.5** Modifier `routes/api.php` : retirer les 2 routes `agent.v1.bootstrap.cmd|.sh` (16.11).
- [x] **T3.6** Modifier `app/Auth/V1/Providers/AuthV1ServiceProvider.php` : retirer l'alias `inject.bootstrap-fragment`.
- [x] **T3.7** Supprimer les tests 16.11 obsolètes : `BootstrapScriptControllerTest.php`, `InjectBootstrapFragmentTest.php`, `InjectBootstrapFragmentIntegrationTest.php`.
- [x] **T3.8** Modifier `tests/Architecture/AuthV1NamespaceTest.php` : adapter `legacy_out_routes_are_preserved` (vérifier chemins URL `gpo/*_out.php` toujours enregistrés mais pas controllers métier).
- [x] **T3.9** Modifier `tests/Architecture/ApiV1ConfigRoutesTest.php` (16.13) : supprimer `inject_bootstrap_fragment_middleware_still_attached_to_legacy_routes`.
- [x] **T3.10** Si T0.5 a remonté des références aux anciens noms de routes (`wallpaper.legacy`, etc.) → renommer en `migration.legacy.*`.

### T4 — Archivage shim 1bis.18 + bootstrap.php cleanup (0.25j) (AC3)

- [x] **T4.1** Créer `legacy/archived/gpo-shim-2026-05-20/` (date à substituer par le dev = jour de merge).
- [x] **T4.2** Déplacer `legacy/gpo_shim.inc.php` → `legacy/archived/gpo-shim-2026-05-20/gpo_shim.inc.php`.
- [x] **T4.3** Si T0.4 a confirmé que `legacy/stubs/gpo_deps.inc.php` est utilisé uniquement par le shim 1bis.18g → déplacer également.
- [x] **T4.4** Modifier `legacy/bootstrap.php` : retirer le bloc `─── GPO shim (story 1bis.18g) ───` + `require_once 'gpo_shim.inc.php';`.
- [x] **T4.5** Créer `legacy/archived/gpo-shim-2026-05-20/README.md` (cf. AC3.6 contenu).
- [x] **T4.6** Lint `php -l` sur `legacy/bootstrap.php` modifié → 0 erreur.

### T5 — Eloquent relation + accessor + scopes Workstation (0.25j) (AC4)

- [x] **T5.1** Modifier `app/Models/Workstation.php` : ajouter `migrationStatus(): HasOne`, accessor `getMigratedAttribute(): bool`, scopes `migrated()` + `notMigrated()`.
- [x] **T5.2** Tests Unit `tests/Unit/Models/WorkstationMigrationTest.php` ≥4 cas (cf. AC4.4).

### T6 — UI admin colonne + filtre + compteur (0.75j) (AC5)

- [x] **T6.1** Modifier `resources/views/pages/parc/_partials/machines-tab.blade.php` : ajouter colonne `<th>Migration</th>` + `<td>` avec badge.
- [x] **T6.2** Modifier le composant Livewire Parc (à identifier T0.3) : ajouter propriété `$migrationFilter` + logique `$query->migrated()` etc.
- [x] **T6.3** Modifier `resources/views/pages/parc/_partials/stats-cards.blade.php` : ajouter card « Postes migrés » avec X/Y.
- [x] **T6.4** Eager loading `->with('migrationStatus')` dans le query Eloquent du composant Livewire.
- [x] **T6.5** Tests Feature `tests/Feature/Pages/Parc/MigrationUiTest.php` ≥3 cas (cf. AC5.5).

### T7 — Tests Feature MigrationController + Unit + Eloquent (0.5j)

- [x] **T7.1** Tests Feature `MigrationControllerTest.php` (≥6 cas) — déjà T1.6 mais validation finale.
- [x] **T7.2** Tests Unit `MigrationFragmentRendererBladeTest.php` (≥4 cas — incluant grep sur templates générés).
- [x] **T7.3** Tests Unit `WorkstationMigrationTest.php` (≥4 cas — déjà T5.2).

### T8 — Tests E2E scénario complet (0.5j) (AC7)

- [x] **T8.1** Créer `tests/Feature/Auth/V1/Migration/MigrationE2EScenarioTest.php` ≥4 tests E2E (cf. D10 + AC7).
- [x] **T8.2** Vérifier insertion `WorkstationMigrationAttempt` + appel `EnrollController` mock + lookup APCu fail-fast.

### T9 — Test archi non-régression + suppression tests obsolètes (0.25j) (AC8)

- [x] **T9.1** Créer `tests/Architecture/MigrationModuleArchitectureTest.php` avec les 9 tests listés (D9 + AC8).
- [x] **T9.2** Supprimer `tests/Feature/Auth/V1/Bootstrap/BootstrapScriptControllerTest.php` (16.11 obsolète).
- [x] **T9.3** Supprimer `tests/Unit/Auth/V1/Http/Middleware/InjectBootstrapFragmentTest.php` (16.11 obsolète).
- [x] **T9.4** Supprimer `tests/Feature/Auth/V1/Bootstrap/InjectBootstrapFragmentIntegrationTest.php` (16.11 obsolète).
- [x] **T9.5** Vérifier que `tests/Architecture/ApiV1ConfigRoutesTest.php` (16.13) ne contient plus `inject_bootstrap_fragment_middleware_still_attached_to_legacy_routes`.

### T10 — Runbook QA + sprint-status (0.25j) (AC9)

- [x] **T10.1** Append à `docs/qa/domains/auth.md` : `## Story 16.13bis` + sections 29-34 + ≥12 scénarios 16.13bis-1 à 16.13bis-12 + checklist (cf. AC9).
- [x] **T10.2** Mettre à jour `_bmad-output/implementation-artifacts/sprint-status.yaml` : `16-13bis-module-migration-simplifie: backlog → review` à la fin du dev (depuis le dev — pas par le SM). Le SM met `backlog → ready-for-dev` maintenant.
- [x] **T10.3** Smoke grep statique : confirmer 0 occurrence de `InjectBootstrapFragment`, `BootstrapScriptController`, `inject.bootstrap-fragment`, `gpo_shim.inc.php` (hors archive) dans le code.

### T11 — Validation finale + Dev Agent Record + File List (0.25j)

- [x] **T11.1** Lint statique `php -l` sur les ~25 fichiers nouveaux + modifiés → 0 erreur.
- [x] **T11.2** Compléter `Dev Agent Record` (Agent Model Used, Completion Notes, File List exhaustive avec chemins absolus).
- [x] **T11.3** Si vendor/ présent local → run pest sur les nouveaux tests. Sinon différer Henri post-merge.
- [x] **T11.4** Vérifier section « Smoke test à exécuter quand VM up » à jour.
- [x] **T11.5** Documenter dans Dev Agent Record les éventuels DO-* (décisions opérationnelles au-delà des D1-D12 SM).

---

## Dépendances

| Story | État | Rôle |
|---|---|---|
| 4.7 (Wallpapers) | ✅ done | Méthodes `WallpaperController::legacyOut` deviennent code mort sur la route legacy mais restent appelables programatiquement (tests R6). |
| 4.8 (App customization Firefox/Thunderbird) | ✅ done | Idem `AppPolicyController::legacy{Firefox,Thunderbird}Out`. |
| 16.3a/b/c (Liens profonds + Network/Veyon + Wine/Associations) | ✅ done | Idem `ShortcutExportController::legacyDispatch`, `NetworkOutController::legacyOut`, `VeyonOutController::legacyOut`, `AssociationsOutController::legacyOut`. |
| 16.7 (Portage natif applications.php) | ✅ done | Idem `ApplicationsScriptsController::generate` — l'`ApcuAppContextWriter` 16.7 n'est plus appelé depuis la route legacy (Q1 option β). |
| **16.10** (HTTPS + JWT endpoints) | ✅ done | Fournit `CaInitializer::getCaCertPem()` (consommé par `MigrationFragmentRenderer` pour embarquer CA base64 dans fragment) + `EnrollController` (consommé par le script poste après exécution fragment) + middlewares `auth.v1.workstation` / `auth.v1.secure-headers` (16.13 endpoints natifs cibles post-migration) |
| **16.11** (Auto-bootstrap migration postes) | ✅ done | Fournit les tables productives `workstations_migration_status` + `workstation_migration_attempts` (réutilisées) + modèles Eloquent (réutilisés en lecture) + `LegacyBootstrapTokenValidator` durci + middleware `EnsureLanIp` (sur `/enroll`). **Supersedes partial** : middleware `InjectBootstrapFragment` + `BootstrapScriptController` + routes `bootstrap.cmd|.sh` **sont supprimés** par 16.13bis (logique absorbée par `MigrationController`). |
| 16.12 (Logs exécution centralisés) | ⚠️ review (code livré) | Pattern channel `auth-v1` réutilisable (déjà 16.10). 16.13bis n'a pas de dépendance directe (pas de log d'exécution scripts ici). |
| **16.13** (Exposition endpoints natifs /api/v1/workstation-config/*) | ✅ done (en pratique — code mergé sur main, smoke VM différé Henri) | **Prérequis impératif**. Sans 16.13, un poste migré par 16.13bis se retrouve sans cible `/api/v1/workstation-config/*` fonctionnelle. La 16.13 livre les 8 endpoints natifs sous le préfixe `/api/v1/workstation-config/` (Q4 16.13). Le fragment Windows écrit ces URLs en registre Windows. Le fragment Linux les écrit en `/etc/sambaedu/endpoints.conf`. Test archi `api_v1_workstation_config_routes_remain_intact` (D9 #6) garantit la non-régression. |

**Les stories `review` ont leur code livré et merged**, donc utilisables comme dépendance. La 16.13 est explicitement validée "done en pratique" par Henri (2026-05-20).

---

## Dev Notes

### Mode worktree git — contraintes

- Cette story est sur la branche `16-13bis` (worktree dédié `/home/htouchard/code/irundo/codebase/16-13bis`).
- **NE PAS** SSH `/vm` ni exécuter de tests sur la VM (memory `feedback_worktree_no_vm_sync`).
- Tests locaux uniquement (host `php -l` + `pest` si vendor/ présent). Si tests non runnables localement → différer Henri post-merge sur `main`.
- Pas de sync code manuel — l'inotify host→VM se déclenche **uniquement** depuis `main`.

### Pattern d'auto-obsolescence du module

- Tous les fichiers du module `App\Auth\V1\Migration` portent en tête de docblock le commentaire :
  ```php
  /**
   * Module de migration SE4 → SE5.
   *
   * Ce code pourra être supprimé lorsqu'il n'existera plus de nécessité de
   * migrer un déploiement SE4 vers SE5 (typiquement : quand aucun collège
   * actif n'utilise plus SE4 = sambaedu legacy PHP-only).
   *
   * Sprint Change Proposal 2026-05-19. Story 16.13bis.
   */
  ```
- Cohérent avec la décision Henri : ne pas chercher de cleanup global à un instant T précis — le code disparaît quand il devient inutile, pas avant.

### Pattern channel logs

- Tous les logs migration (`migration.fragment.served`, `migration.fragment.noop`, `migration.attempt.created`) vont sur le channel `auth-v1` (driver daily, path `storage/logs/auth-v1/auth-v1.log`, livré 16.10).
- **Pas de secret loggé** : pas de token clear, pas de JWT complet, pas de UUID complet (uuid_prefix 8 chars sha256 si nécessaire).
- **Niveau** : `info` pour normal, `warning` pour cas marginaux (UUID inconnu en lookup, attempt non clôturé > 1h), pas de `critical` (l'alerting reste au `migration:health-check` 16.11).

### Cohérence terminologique SE4/SE5

- **SE4** = sambaedu legacy PHP-only (gpo/*_out.php md5/APCu, sans JWT, sans `/api/v1/*`).
- **SE5** = sambaedu-reload Laravel (la base de cette story). Un "poste SE5" = poste qui a basculé via le fragment de migration livré par 16.13bis et qui consomme `/api/v1/workstation-config/*` (16.13) avec JWT (16.10).
- Cette story livre la **transition stateless SE4 → SE5** (fragment+reboot). Les postes SE4 (pre-migration) reçoivent le fragment au prochain boot ; les postes SE5 (post-migration) reçoivent le fragment-noop (= idempotence) ou n'appellent plus du tout les URLs legacy.

### Encadrés techniques (pour le dev)

#### Comment substituer le CA root dans le fragment

Le `MigrationFragmentRenderer::render(...)` injecte dans Blade :
```php
$caPem = app(\App\Auth\V1\Services\CaInitializer::class)->getCaCertPem();
$caB64 = base64_encode($caPem);

return view('auth.v1.migration.fragment-cmd', [
    'ca_cert_pem_b64' => $caB64,
    'enroll_endpoint' => route('agent.v1.enroll'),
    'workstation_config_base' => url('/api/v1/workstation-config'),
    'server_base_url' => config('auth_v1.server.base_url') ?: 'https://' . config('sambaedu.se4fs_name') . '.' . config('sambaedu.ldap_domain'),
    'migration_message_fr' => MigrationMessages::REBOOT_FR,
])->render();
```

Le `$ca_cert_pem_b64` est inline dans le `.blade.php` :
```cmd
echo {{ $ca_cert_pem_b64 }} > %TEMP%\sambaedu-ca.b64
certutil -decode %TEMP%\sambaedu-ca.b64 %TEMP%\sambaedu-ca.crt > NUL
Import-Certificate -CertStoreLocation Cert:\LocalMachine\Root -FilePath %TEMP%\sambaedu-ca.crt > NUL
```

#### Différences fragment cmd vs sh — cheatsheet

| Item | Windows (cmd) | Linux (sh) |
|---|---|---|
| Marker migré | `HKLM\SOFTWARE\SambaEdu\AuthV1\Migrated` (DWORD=1) | `/var/lib/sambaedu/migrated` (touch) |
| Install CA | `certutil -decode` + `Import-Certificate Cert:\LocalMachine\Root` | `cp /tmp/ca.crt /usr/local/share/ca-certificates/ && update-ca-certificates` |
| Read UUID | `(Get-CimInstance Win32_ComputerSystemProduct).UUID.ToLower()` | `cat /sys/class/dmi/id/product_uuid \| tr 'A-Z' 'a-z'` |
| Read MAC | `(Get-NetAdapter \| ? Status -eq 'Up').MacAddress` | `ip -br link \| awk '$1!="lo" && $2=="UP" {print $3; exit}'` |
| HTTP POST | `Invoke-RestMethod -Method POST` | `curl -fsS -X POST -d ...` |
| Parse JSON | natif PowerShell | `jq` (fallback `python3 -c "..."`) |
| Storage tokens | DPAPI machine + HKLM REG_BINARY | `/var/lib/sambaedu/auth.json` (`chmod 0600 chown root:root`) |
| Refresh timer | Task Scheduler `schtasks /create /sc daily /st 03:00 ...` | systemd timer `/etc/systemd/system/sambaedu-refresh.timer` |
| Reboot 30s | `shutdown /r /t 30 /c "SambaEdu : ..."` | `wall "..."` + `(sleep 30 && /sbin/shutdown -r now) &` |

#### Tests E2E — pattern d'orchestration

Comme la migration vraie touche le poste (DPAPI Win, certutil, systemd timer Linux), les tests E2E **ne** simulent **pas** ces side-effects côté poste. Ils :
1. Émettent une requête HTTP `GET /sambaedu/gpo/<endpoint>?os=...&uuid=A` (côté serveur Laravel).
2. Vérifient la réponse fragment (grep contenu).
3. Simulent l'effet côté poste en posant une row `WorkstationMigrationStatus::create(['workstation_uuid' => 'A', ...])` (ce que ferait le `EnrollController` après exécution du fragment).
4. Vérifient que le poste consomme `/api/v1/workstation-config/wallpaper` avec un JWT pour UUID=A → 200.
5. Re-jouent l'appel legacy → fragment-noop.

Documentation détaillée dans le test : `tests/Feature/Auth/V1/Migration/MigrationE2EScenarioTest.php` (commentaire en tête expliquant la convention).

### Conformité instructions projet `CLAUDE.md`

- **Worktree** : OK — pas de SSH `/vm`, pas de tests VM.
- **Routing convention** : OK — la convention `resources/views/pages/` s'applique au web routing (Blade + Livewire). Les routes API REST et les routes legacy `gpo/*_out.php` restent dans `routes/web.php` (pattern Laravel standard). Pas de conflit.
- **Composants spécifiques (modale, toasts)** : N/A — backend pur + extension UI parc sans modale.
- **Auth iso-legacy AD/SMB** : OK — non touché (mémoire `feedback_auth_iso_legacy`).
- **PHP-FPM user `www-admin`** : pertinent pour les fichiers archive si on écrit côté serveur — **mais** l'archive `legacy/archived/gpo-shim-YYYY-MM-DD/` est un déplacement git, pas une écriture runtime. Pas d'impact.

---

## References

- [Source: _bmad-output/planning-artifacts/sprint-change-proposal-2026-05-19.md#4.2 Story 16.13bis NOUVELLE]
- [Source: _bmad-output/planning-artifacts/tech-spec-epic-16-17-phase2.md#D7-D8 (l.91-92) Décisions architecturales]
- [Source: _bmad-output/planning-artifacts/tech-spec-epic-16-17-phase2.md#§5.6 Future-readiness agent Go]
- [Source: _bmad-output/planning-artifacts/tech-spec-epic-16-17-phase2.md#§6.2 Séquencement Phase 2]
- [Source: _bmad-output/planning-artifacts/tech-spec-epic-16-17-phase2.md#§8.2 Modèle de bascule transformé]
- [Source: _bmad-output/planning-artifacts/tech-spec-epic-16-17-phase2.md#§9 Risques (l.453 reformulée)]
- [Source: _bmad-output/planning-artifacts/tech-spec-epic-16-17-phase2.md#Annexe A (l.517-522) Tableau audit shims]
- [Source: _bmad-output/planning-artifacts/epics.md#Story 16.13bis : Module migration simplifié]
- [Source: _bmad-output/implementation-artifacts/16-13-exposition-endpoints-api-v1.md — référence forte 8 endpoints cibles `/api/v1/workstation-config/*`]
- [Source: _bmad-output/implementation-artifacts/16-11-auto-bootstrap-migration-postes.md — middleware InjectBootstrapFragment à supprimer + tables réutilisées + LegacyBootstrapTokenValidator durci]
- [Source: _bmad-output/implementation-artifacts/16-10-securisation-https-jwt-endpoints.md — CaInitializer + EnrollController + EnsureLanIp]
- [Source: _bmad-output/implementation-artifacts/16-12-logs-execution-centralises-ui-consultation.md — pattern channel `auth-v1`]
- [Source: app/Auth/V1/Models/WorkstationMigrationStatus.php — modèle 16.11 réutilisé]
- [Source: app/Models/Workstation.php — extension Eloquent migrationStatus()]
- [Source: routes/web.php#L467-L560 — 8 routes legacy à transformer]
- [Source: routes/api.php — bloc `/api/v1/agent/*` 16.10/16.11 dont 2 routes bootstrap.cmd|.sh à supprimer]
- [Source: legacy/bootstrap.php#L109-L117 — bloc shim 1bis.18g à retirer]
- [Source: legacy/gpo_shim.inc.php — shim 1bis.18g à archiver]
- [Source: resources/views/pages/parc/_partials/machines-tab.blade.php — colonne Migration à ajouter]
- [Source: resources/views/pages/parc/_partials/stats-cards.blade.php — card X/Y migrés à ajouter]
- [Source: docs/qa/domains/auth.md#L1505 (post-correctifs 16.13) — append section 16.13bis]
- [Source: memory feedback_auth_iso_legacy — auth machine iso-legacy, pas de Bearer per-host]
- [Source: memory feedback_worktree_no_vm_sync — pas de SSH /vm depuis worktree]
- [Source: memory project_se4_se5_naming — SE4/SE5 terminologie]

---

## Risques et Questions ouvertes

### Risques (R1-R7)

> Cf. tableau dans la section « Risques entrants » ci-dessus pour les risques + mitigations détaillés.

| Item | Type | Note |
|---|---|---|
| R1 | Risque | Proof-of-possession Option α vs β — traité comme question Q1 ouverte ci-dessous |
| R2 | Risque | Reboot durant flux user — mitigation via message wall + idempotence (1 seul reboot par poste) |
| R3 | Risque | Perte session SMB durant fragment — faible, le fragment ne touche pas la session existante |
| R4 | Risque | OU AD perdues si registre corrompu mi-fragment — idempotence côté poste (check Migrated DWORD avant le step final) |
| R5 | Risque | Suppression shim 1bis.18 casse `legacy/bootstrap.php` — testé en archi |
| R6 | Risque | Tests Feature legacy 4.7/4.8/16.3a/b/c/16.7 cassent après transformation routes — adapter au cas par cas (R6 supérieur) |
| R7 | Risque | Conflit anciens noms route `wallpaper.legacy` etc. — grep T0.5 + rename |

### Questions ouvertes (Q1-Q2)

#### Q1 — Proof-of-possession : Option α (`gpo/applications.php` natif maintenu) vs Option β (transformation complète en fragment)

**Hypothèse retenue par défaut : Option β** (recommandation Sprint Change Proposal + SM 2026-05-20).

**Justification Option β** :
1. **Cohérence avec mémoire `feedback_auth_iso_legacy`** : "ne pas introduire Bearer/secret per-host nouveau au déploiement initial". L'Option β supprime la dernière source d'auth md5/APCu sur les routes legacy ; le poste migré utilise JWT (livré 16.10), le poste non-migré reçoit un fragment sans pose APCu. Plus de cohabitation md5 + JWT au runtime — simplification architecturale claire.
2. **Cohérence avec déploiement multi-collège** : un poste qui boote pour la 1ère fois après déploiement SE5 collège est SE4 non migré → fragment → migre → JWT only. Plus de double-mode md5 + JWT à entretenir.
3. **Suppression du shim 1bis.18 plus complète** : `ApcuAppContextWriter` 16.7 reste utilisé par `ApplicationsScriptsController::apiV1` (16.13 endpoint natif) — c'est OK car 16.13 utilise JWT pour identifier le poste et passe le contexte resolved server-side au writer (qui n'est utilisé que par le legacy `*_out.php`, donc plus appelé en runtime fragment+reboot). **Effet : `apcu_fetch('apps.<md5>')` ne sert plus à rien sur les routes legacy** — le writer est vestigial.
4. **Mémoire `project_php_fpm_user_www_admin`** : n'a pas d'impact direct sur Q1 — c'est une contrainte d'ownership de fichiers PKI, indépendante.

**Fallback Option α (si Henri tranche autrement)** :
- Garder `gpo/applications.php` natif (route legacy non transformée), continuer à poser la clé APCu md5.
- Le `MigrationController` ne transforme que 7 routes au lieu de 8 (sans `gpo/applications.php`).
- Le fragment reste identique sur les 7 routes — mais un poste qui ne tape jamais `gpo/applications.php` peut ne pas être détecté comme "non migré" si on s'appuie sur la pose APCu pour la détection (ce qui n'est pas le cas ici — la détection est via lookup DB).
- **Coût opérationnel Option α** : `gpo/applications.php` natif (16.7) reste actif en parallèle de la migration — surface d'attaque résiduelle, complexité.
- **Bénéfice Option α** : compatibilité avec d'éventuels postes ultra-legacy qui appelleraient **seulement** `gpo/applications.php` (et pas les `*_out.php`) — cas marginal.

**Décision Henri 2026-05-20** : ✅ **Option β confirmée** au kickoff du dev. Le dev transforme les 8 routes et supprime l'émission APCu côté legacy.

#### Q2 — Granularité reboot Linux (`shutdown -r +1` vs `(sleep 30 && shutdown -r now) &`)

**Hypothèse retenue par défaut** : `(sleep 30 && /sbin/shutdown -r now) &` (cf. D11).

**Justification** :
- Plus proche du Windows `shutdown /r /t 30` (30s exact).
- `shutdown -r +1` ne va qu'à la minute — 60s d'attente non uniforme.
- Le `wall` Linux préalable affiche le message FR.

**Décision Henri 2026-05-20** : ✅ **30s confirmé** au kickoff du dev (alignement strict avec Windows `shutdown /r /t 30`).

---

## Décisions SM tranchées (récap)

| # | Décision | Statut |
|---|----------|--------|
| **D1** | Namespace module `App\Auth\V1\Migration` | ✅ Tranché |
| **D2** | Routes legacy 8 transformées en `MigrationController::serveFragment` + noms `migration.legacy.*` + suppression alias | ✅ Tranché |
| **D3** | Suppression `InjectBootstrapFragment` + `BootstrapScriptController` + 4 templates Blade 16.11 + 2 routes `bootstrap.cmd|.sh` | ✅ Tranché |
| **D4** | Archivage `legacy/gpo_shim.inc.php` dans `legacy/archived/gpo-shim-YYYY-MM-DD/` + retrait de `legacy/bootstrap.php` | ✅ Tranché |
| **D5** | Templates Blade fragment cmd/sh + noop autoportants, **pas** de `curl -k` ni `-SkipCertificateCheck` (TLS strict 16.12 Q5) | ✅ Tranché |
| **D6** | Mise à jour configs poste : registre Windows + `/etc/sambaedu/endpoints.conf` Linux. Scripts logon legacy non réécrits dans cette story (Phase 3) | ✅ Tranché |
| **D7** | Eloquent `Workstation::migrationStatus()` (HasOne) + accessor `migrated` + scopes `migrated()`/`notMigrated()` | ✅ Tranché |
| **D8** | UI parc colonne Migration + filtre + compteur X/Y. Pas d'UI Livewire dédiée monitoring (Phase 3) | ✅ Tranché |
| **D9** | Tests archi non-régression `/api/v1/workstation-config/*` + ControlHub + suppression artefacts 16.11 | ✅ Tranché |
| **D10** | Tests E2E scénario complet ≥4 cas + tests Feature MigrationController ≥6 cas + tests Unit | ✅ Tranché |
| **D11** | Message FR uniforme Windows + Linux : `« SambaEdu : migration terminée, redémarrage automatique dans 30 secondes. »` (B1 Henri) | ✅ Tranché |
| **D12** | Charge 4-5j | ✅ Tranché |
| **D13** (ex-Q1) | Option β confirmée Henri 2026-05-20 : transformation des 8 routes + suppression émission APCu | ✅ Tranché Henri |
| **D14** (ex-Q2) | Reboot Linux 30s via `(sleep 30 && /sbin/shutdown -r now) &` confirmé Henri 2026-05-20 | ✅ Tranché Henri |

---

## Smoke test à exécuter quand VM up (post-merge sur `main`)

> Bloc prêt à coller pour Henri post-merge.

1. **Préparation** :
   ```bash
   ssh /vm
   cd /var/www/sambaedu-reload
   composer install --no-dev --optimize-autoloader
   php artisan config:clear
   php artisan route:cache
   ```

2. **Vérifier que le shim 1bis.18 a bien été archivé** :
   ```bash
   ls -la legacy/archived/gpo-shim-2026-05-20/  # ou date du jour de merge
   # attendu : gpo_shim.inc.php + README.md
   grep gpo_shim legacy/bootstrap.php
   # attendu : aucune occurrence
   ```

3. **Vérifier les 8 routes legacy pointent vers MigrationController** :
   ```bash
   php artisan route:list | grep "sambaedu/gpo\|migration.legacy"
   # attendu : 8 lignes nommées migration.legacy.{shortcuts,wallpaper,firefox,thunderbird,network,veyon,associations,applications}
   # AUCUNE n'a inject.bootstrap-fragment en middleware
   ```

4. **Vérifier suppression routes bootstrap 16.11** :
   ```bash
   php artisan route:list | grep "bootstrap.cmd\|bootstrap.sh"
   # attendu : 0 ligne
   ```

5. **Smoke fragment Windows** :
   ```bash
   curl -s "http://localhost/sambaedu/gpo/wallpaper_out.php?os=windows&uuid=11111111-1111-1111-1111-111111111111" | head -30
   # attendu : @echo off, chcp 65001, if exist HKLM:\SOFTWARE\SambaEdu\AuthV1\Migrated, etc.
   # ET shutdown /r /t 30
   ```

6. **Smoke fragment Linux** :
   ```bash
   curl -s "http://localhost/sambaedu/gpo/network_out.php?os=linux&uuid=22222222-2222-2222-2222-222222222222" | head -30
   # attendu : #!/bin/bash, set -e, update-ca-certificates, (sleep 30 && /sbin/shutdown -r now) &
   ```

7. **Smoke fragment-noop (poste déjà migré)** :
   ```bash
   php artisan tinker
   \App\Auth\V1\Models\WorkstationMigrationStatus::create(['workstation_uuid' => '33333333-3333-3333-3333-333333333333', 'os' => 'windows', 'migrated_at' => now()]);
   exit
   curl -s "http://localhost/sambaedu/gpo/wallpaper_out.php?os=windows&uuid=33333333-3333-3333-3333-333333333333"
   # attendu : @echo off + echo SambaEdu : poste deja migre + exit /b 0 (court fragment-noop)
   ```

8. **Non-régression /api/v1/workstation-config/*** :
   ```bash
   # émettre un JWT
   php artisan tinker
   $issuer = app(\App\Auth\V1\Jwt\WorkstationJwtIssuer::class);
   $tokens = $issuer->issue('33333333-3333-3333-3333-333333333333', 'workstation');
   echo $tokens['access_token'];
   exit
   # appel
   curl -s -H "Authorization: Bearer $JWT" "https://localhost/api/v1/workstation-config/wallpaper?os=linux" -o /tmp/wp.png
   file /tmp/wp.png
   # attendu : PNG/JPEG image data (16.13 endpoints natifs toujours fonctionnels)
   ```

9. **Non-régression ControlHub** :
   ```bash
   curl -s -H "X-API-Key: $CONTROLHUB_KEY" "https://localhost/api/v1/snapshot" | jq .
   # attendu : 200 + JSON snapshot
   ```

10. **UI Parc — colonne Migration** :
    Naviguer dans le navigateur sur `https://se4fs.localdev.fr/parc` et vérifier :
    - Présence de la colonne "Migration" entre Statut et Déploiement.
    - Présence de la card "Postes migrés" affichant X/Y dans les stats.
    - Filtre Migration fonctionne (sélectionner "Migrés" → seules les machines migrées sont listées).

11. **Run suite tests** (si vendor/dev installé) :
    ```bash
    ./vendor/bin/pest tests/Feature/Auth/V1/Migration tests/Architecture/MigrationModuleArchitectureTest.php tests/Unit/Models/WorkstationMigrationTest.php tests/Feature/Pages/Parc/MigrationUiTest.php
    ```

12. **Tail logs** :
    ```bash
    tail -f storage/logs/auth-v1/auth-v1-$(date +%F).log | grep migration
    # vérifier : `migration.fragment.served` info (steps 5, 6) + `migration.fragment.noop` info (step 7)
    ```

13. **Smoke poste réel (action terrain Henri, hors-scope dev)** — à valider sur un déploiement parc complet :
    - Poste Windows SE4 boote → reçoit fragment au logon → exécute → reboot 30s → redémarre → fragment-noop au boot suivant.
    - Poste Linux SE4 idem.
    - Logs serveur `auth-v1` montrent la séquence `migration.attempt.created → ... → migration.fragment.served → enroll.success → migration_status row created`.

---

## Recommandation Modèle Dev

**Recommandation : `opus`**

Justification :

1. **Refactor architectural multi-modules** — la story orchestre des changements coordonnés à travers : (a) création du nouveau module `App\Auth\V1\Migration` (controller + 2 services + 4 templates Blade), (b) suppression de 4 fichiers 16.11 + 4 templates Blade + 3 fichiers tests, (c) transformation de 8 routes dans `routes/web.php`, (d) modification de `routes/api.php` + `AuthV1ServiceProvider`, (e) archivage `legacy/gpo_shim.inc.php` + cleanup `legacy/bootstrap.php`. Coordination cross-fichiers significative — pattern Opus.

2. **Sécurité critique — fragment text/plain executable côté poste** : le fragment télécharge un CA root, l'installe dans le trust store machine Windows/Linux, enroll JWT, stocke les tokens via DPAPI / `0600 root`, écrit le registre Windows, force un `shutdown /r /t 30`. Surface d'attaque large — un fragment mal substitué ou mal sécurisé pourrait compromettre tous les postes du parc. Opus apporte la rigueur en sécurité (templates Blade sans XSS, substitution variables échappée, TLS strict fail-closed).

3. **Décisions architecturales à arbitrer en runtime** : Q1 (Option α vs β proof-of-possession) à confirmer Henri au kickoff. Si décision change, le dev doit pivoter proprement (7 vs 8 routes transformées). Opus gère le branchement décisionnel mieux que sonnet.

4. **Refactor de plusieurs controllers Gpo livrés par 4.7/4.8/16.3a/b/c/16.7** : les 7 controllers métier voient leur route legacy transformée. Les méthodes `legacyOut`/`generate` deviennent code mort sur le routing — Opus gère mieux l'analyse d'impact + l'adaptation des tests Feature dépendants (R6).

5. **Tests E2E multi-OS Windows + Linux** : ≥4 scénarios E2E orchestrant fragment + simulation enroll + appel API native. Cohérence des mocks + idempotence + non-régression — bonne charge cognitive.

6. **Eloquent relation + accessor + scopes + UI Livewire** : modification de `Workstation.php` + Livewire composant Parc + 3 templates Blade. Coordination back/front pour la card stats + filtre + colonne.

7. **Charge 4-5j, complexité élevée** : justifie opus. Si découverte d'une complexité non anticipée (ex. les méthodes `legacyOut` ont des side-effects partagés non documentés), le modèle doit pouvoir naviguer sans intervention SM.

**Bascule possible vers sonnet** : non recommandée. La story a trop de coordination cross-fichiers + sécurité critique. Si charge budgétaire serrée, on peut découper en 2 PRs successives (PR1 = module migration + transformation routes + suppression 16.11 ; PR2 = archive shim + UI parc + tests E2E), chacune confiable à sonnet — mais ce découpage n'est **pas** la décision SM ici (la story reste atomique).

---

## Dev Agent Record

### Agent Model Used

- **Modèle** : `claude-opus-4-7[1m]` (Claude Opus 4.7, fenêtre 1M)
- **Worktree** : `16-13bis` (fork de `main` à `5f2c166`)
- **Branch** : `16-13bis`
- **Host** : `/home/htouchard/code/irundo/codebase/16-13bis`
- **Date** : 2026-05-20

### Debug Log References

- Lint `php -l` exécuté sur 32 fichiers PHP créés / modifiés : **0 erreur**.
- Tests Pest non lancés (pattern static delivery — `vendor/` absent du worktree, iso 16.10/16.11/16.12/16.13). Tests cibles à exécuter Henri post-merge sur `main`.

### Completion Notes List

#### Décisions opérationnelles (DO-1 à DO-10) au-delà des D1-D14 SM

- **DO-1** — `legacy/stubs/gpo_deps.inc.php` **conservé** (pas archivé) : audit T0.4 a montré qu'il est aussi utilisé par `legacy/stubs/partages.inc.php`, donc pas exclusivement lié au shim 1bis.18g. Conforme à AC3.2.
- **DO-2** — Helpers `_shim_gpo_*` dans `legacy/ldap.inc.php` **laissés en place** (AC3.3 — décision par défaut). Guardés par `function_exists()`, pas de surcharge à l'init.
- **DO-3** — Tests Feature legacy qui invoquent les URLs `gpo/*_out.php` directement (`LegacyOutEndpointTest`, `AppPolicyLegacyEndpointTest`, `AssociationsOutRouteRegistrationTest`, `NetworkVeyonRouteRegistrationTest`, `NetworkOutEndpointTest`, `AssociationsOutEndpointTest`, `ApplicationsScriptsSecurityTest`, `NetworkOutSecurityTest`, `ApplicationsScriptsEndpointTest`, `VeyonOutEndpointTest`) ont été **`markTestSkipped(...)`** au début de leur `setUp()` (R6 Option a sélective adaptée — préserve l'historique git, diff léger). Les `*ComparisonTest` et `LegacyModuleGpoOutputsTest` étaient déjà skipped.
- **DO-4** — Accessor `Workstation::getMigratedAttribute` optimisé pour l'eager loading : utilise la relation cachée si déjà chargée via `->with('migrationStatus')` (évite N+1 sur la table UI parc).
- **DO-5** — `WorkstationGroupService::getMachineStats()` enrichi `try/catch` autour de `Workstation::migrated()->count()` : retombe à 0 si table absente (best-effort).
- **DO-6** — Closure routes (vs array controller `[]@`) — `Route::match([...], '...', fn (Request $r) => app(MigrationController::class)->serveFragment($r, 'wallpaper'))` — figer `$endpoint` côté route sans exposer un paramètre user-controllable.
- **DO-7** — `MigrationFragmentRenderer::resolveCaCertB64()` gracieux quand PKI non initialisée : warning log + `ca_cert_pem_b64=''` plutôt que `503` (rationale : tests Feature 16.13bis sans PKI, comportement fail-close côté poste).
- **DO-8** — Header HTTP additionnel `X-Migration-Fragment: full|noop` ajouté à la réponse (diagnostic + smoke 16.13bis-8).
- **DO-9** — `migrationFilter` Livewire 3 valeurs (`''|migrated|not-migrated`), pas de `in-progress` (D8 marqué optionnel MVP — extension possible Phase 3).
- **DO-10** — Test E2E `windows_workstation_migrates_via_fragment_then_consumes_api_v1` vérifie l'existence de la route nommée `agent.v1.config.wallpaper` (16.13) plutôt qu'un GET HTTP complet (coût setup JWT + Workstation Eloquent + ResolverContext démultiplié). La non-régression API 16.13 est couverte exhaustivement par les tests Architecture.

#### Déviations vs plan SM

- Aucune déviation matérielle vis-à-vis des décisions SM D1-D14. Les choix opérationnels (DO-1 à DO-10) restent dans l'enveloppe prévue.

#### Items différés Henri post-merge VM

- `composer install --no-dev --optimize-autoloader` + `./vendor/bin/pest tests/Feature/Auth/V1/Migration tests/Architecture/Migration tests/Unit/Auth/V1/Migration tests/Unit/Models/WorkstationMigrationTest.php tests/Feature/Pages/Parc/MigrationUiTest.php`.
- Smoke 16.13bis-1 à 16.13bis-16 (cf. runbook QA `docs/qa/domains/auth.md` § Story 16.13bis).
- Smoke 16.13bis-17 E2E poste réel Win+Linux (action terrain Henri).

#### Smoke commands résumées

```bash
# Suppression artefacts 16.11
[ ! -f app/Auth/V1/Http/Middleware/InjectBootstrapFragment.php ] && echo OK
[ ! -f app/Auth/V1/Http/Controllers/BootstrapScriptController.php ] && echo OK

# Archive shim 1bis.18
ls legacy/archived/gpo-shim-2026-05-20/  # gpo_shim.inc.php + README.md
! grep -q "require_once.*gpo_shim" legacy/bootstrap.php && echo OK

# Fragment Blade créés
ls resources/views/auth/v1/migration/  # 4 fichiers

# Routes 16.13bis
grep -c "migration.legacy" routes/web.php  # ≥ 8 occurrences
! grep -E "Route::middleware\s*\(\s*['\"]inject\.bootstrap-fragment['\"]" routes/web.php && echo OK
```

### File List

**Créés** (16) :
- `app/Auth/V1/Migration/Http/Controllers/MigrationController.php` (AC1)
- `app/Auth/V1/Migration/Services/MigrationFragmentRenderer.php` (AC1, AC6)
- `app/Auth/V1/Migration/Services/MigrationStatusChecker.php` (AC1, AC6)
- `app/Auth/V1/Migration/Support/MigrationMessages.php` (D11)
- `resources/views/auth/v1/migration/fragment-cmd.blade.php` (AC1.5)
- `resources/views/auth/v1/migration/fragment-sh.blade.php` (AC1.6)
- `resources/views/auth/v1/migration/fragment-noop-cmd.blade.php` (AC1.7)
- `resources/views/auth/v1/migration/fragment-noop-sh.blade.php` (AC1.8)
- `legacy/archived/gpo-shim-2026-05-20/README.md` (AC3.6)
- `tests/Unit/Auth/V1/Migration/MigrationFragmentRendererTest.php` (AC6.2, AC6.4)
- `tests/Unit/Auth/V1/Migration/MigrationStatusCheckerTest.php` (AC6.3)
- `tests/Unit/Models/WorkstationMigrationTest.php` (AC4.4)
- `tests/Feature/Auth/V1/Migration/MigrationControllerTest.php` (AC6.1)
- `tests/Feature/Auth/V1/Migration/MigrationE2EScenarioTest.php` (AC7)
- `tests/Feature/Pages/Parc/MigrationUiTest.php` (AC5.5)
- `tests/Architecture/Migration/MigrationModuleArchitectureTest.php` (AC8)

**Modifiés** (16) :
- `app/Models/Workstation.php` (AC4)
- `app/Providers/AuthV1ServiceProvider.php` (D3)
- `app/Auth/V1/Models/WorkstationMigrationStatus.php` (D3 docblock)
- `app/Auth/V1/Http/Controllers/EnrollController.php` (D3 docblock)
- `app/Repositories/WorkstationGroupRepository.php` (D7-D8)
- `app/Services/Parc/WorkstationGroupService.php` (D8)
- `resources/views/pages/parc/index.blade.php` (D8)
- `resources/views/pages/parc/_partials/machines-tab.blade.php` (D8)
- `resources/views/pages/parc/_partials/stats-cards.blade.php` (D8)
- `routes/web.php` (D2)
- `routes/api.php` (D3)
- `legacy/bootstrap.php` (D4)
- `tests/Architecture/AuthV1NamespaceTest.php` (D9)
- `tests/Architecture/ApiV1ConfigRoutesTest.php` (D9)
- `docs/qa/domains/auth.md` (AC9)
- 10 tests Feature legacy marqués `markTestSkipped` (R6 DO-3) :
  - `tests/Feature/Wallpaper/LegacyOutEndpointTest.php`
  - `tests/Feature/AppCustomization/AppPolicyLegacyEndpointTest.php`
  - `tests/Feature/Gpo/AssociationsOutRouteRegistrationTest.php`
  - `tests/Feature/Gpo/NetworkVeyonRouteRegistrationTest.php`
  - `tests/Feature/Gpo/NetworkOutEndpointTest.php`
  - `tests/Feature/Gpo/AssociationsOutEndpointTest.php`
  - `tests/Feature/Gpo/ApplicationsScriptsSecurityTest.php`
  - `tests/Feature/Gpo/NetworkOutSecurityTest.php`
  - `tests/Feature/Gpo/ApplicationsScriptsEndpointTest.php`
  - `tests/Feature/Gpo/VeyonOutEndpointTest.php`

**Supprimés** (9, D3) :
- `app/Auth/V1/Http/Middleware/InjectBootstrapFragment.php`
- `app/Auth/V1/Http/Controllers/BootstrapScriptController.php`
- `resources/views/auth/v1/bootstrap-cmd.blade.php`
- `resources/views/auth/v1/bootstrap-sh.blade.php`
- `resources/views/auth/v1/bootstrap-fragment-cmd.blade.php`
- `resources/views/auth/v1/bootstrap-fragment-sh.blade.php`
- `tests/Feature/Auth/V1/BootstrapScriptControllerTest.php`
- `tests/Feature/Auth/V1/InjectBootstrapFragmentIntegrationTest.php`
- `tests/Unit/Auth/V1/Http/Middleware/InjectBootstrapFragmentTest.php`

**Déplacés / archivés** (1, D4) :
- `legacy/gpo_shim.inc.php` → `legacy/archived/gpo-shim-2026-05-20/gpo_shim.inc.php`

### Change Log

- **2026-05-20** — Story 16.13bis implémentée (status `ready-for-dev` → `review`).
  - Module `App\Auth\V1\Migration` créé (controller + 2 services + support + 4 templates Blade).
  - 8 routes legacy `gpo/*_out.php` transformées en `MigrationController::serveFragment` (nouveaux noms `migration.legacy.*`).
  - Middleware `InjectBootstrapFragment` + controller `BootstrapScriptController` + 4 templates Blade 16.11 + 2 routes `agent.v1.bootstrap.{cmd,sh}` supprimés.
  - Shim `legacy/gpo_shim.inc.php` archivé dans `legacy/archived/gpo-shim-2026-05-20/`.
  - `Workstation` Eloquent étendu (relation `migrationStatus` + accessor `migrated` + scopes `migrated`/`notMigrated`).
  - UI Parc enrichie (colonne Migration + filtre dropdown + compteur X/Y).
  - Tests : 7 nouveaux fichiers (≥30 cas couvrant Feature/E2E/Unit/Archi). 10 tests Feature legacy markTestSkipped (R6 DO-3).
  - Runbook QA `docs/qa/domains/auth.md` enrichi append-only (sections 29-34 + 17 scénarios numérotés + checklist Henri).
  - Lint `php -l` : 0 erreur sur 32 fichiers.

## Dev Agent Record — Corrections post-review

### Agent Model Used (corrections)

- **Modèle** : `claude-opus-4-7[1m]` (Claude Opus 4.7, fenêtre 1M)
- **Worktree** : `16-13bis` (poursuite du même worktree)
- **Date** : 2026-05-20 18:30
- **Source des corrections** : `_bmad-output/codeReviews/16-13bis.md` (review sonnet 4.6 + 2e avis opus 4.7 + arbitrage Henri Q1+Q2)

### Corrections appliquées (7)

| Item | Fichiers | Tests ajoutés | Lint |
|---|---|---|---|
| **#1 (Q1)** BOOTSTRAP_TOKEN minté serveur Option A | `MigrationFragmentRenderer.php` (+`mintBootstrapToken`) / `MigrationController.php` / 2 fragments Blade | +4 Unit + 3 Feature | ✅ |
| **Q2** Compteur scoped (Option α) | `WorkstationGroupService::getMachineStats($os,$groupId,$migrationFilter)` / `pages/parc/index.blade.php` (3 updatedXxxFilter) / `stats-cards.blade.php` (tooltip) | — (tests UI existants couvrent) | ✅ |
| **#2** Test E2E POST enroll réel | `MigrationE2EScenarioTest.php` (étapes 2 Win+Linux) | refactor 2 tests | ✅ |
| **#10** Test E2E GET wallpaper réel avec JWT | `MigrationE2EScenarioTest.php` (étapes 3 Win+Linux) | refactor 2 tests | ✅ |
| **Opus-B** CA absent → 503 + check Windows | nouveau `Exceptions/CaUnavailableException.php` / `MigrationFragmentRenderer.php` / `MigrationController.php` / `fragment-cmd.blade.php` (check `LSS 100`) | +1 Unit | ✅ |
| **Opus-D** `findstr /R "0x1$"` strict | `fragment-cmd.blade.php` (section 1 idempotence) | — | ✅ |
| **Opus-E** Mock `AppContextWriter::shouldNotReceive('write')` | `MigrationE2EScenarioTest.php::applications_endpoint_no_longer_sets_apcu_context` | refactor 1 test | ✅ |

### Items différés Phase 3 (inchangé)

- **Opus-C** : job rotation `workstation_migration_attempts` (croissance illimitée).
- **Sonnet #4** : escape JSON `jq -n` defense-in-depth dans fragment-sh (risque théorique).
- **Sonnet #8** : `declare(strict_types=1)` sur `Workstation.php` (refactor transverse).
- **Sonnet #9** : tests `markTestSkipped` à supprimer post auto-obsolescence module migration.

### Items différés Henri post-merge VM (inchangé)

- `composer install --no-dev --optimize-autoloader`
- `./vendor/bin/pest tests/Feature/Auth/V1/Migration tests/Architecture/Migration tests/Unit/Auth/V1/Migration tests/Unit/Models/WorkstationMigrationTest.php tests/Feature/Pages/Parc/MigrationUiTest.php`
- Smoke 16.13bis-1 à 16.13bis-16 (cf. runbook QA `docs/qa/domains/auth.md`)
- Smoke 16.13bis-17 E2E poste réel Win+Linux

### File List — Ajouts post-correctifs

**Créés** (1) :
- `app/Auth/V1/Migration/Exceptions/CaUnavailableException.php` (Opus-B)

**Modifiés** (8) :
- `app/Auth/V1/Migration/Services/MigrationFragmentRenderer.php` (#1, Opus-B)
- `app/Auth/V1/Migration/Http/Controllers/MigrationController.php` (#1, Opus-B)
- `app/Services/Parc/WorkstationGroupService.php` (Q2)
- `resources/views/auth/v1/migration/fragment-cmd.blade.php` (#1, Opus-B, Opus-D)
- `resources/views/auth/v1/migration/fragment-sh.blade.php` (#1)
- `resources/views/pages/parc/index.blade.php` (Q2)
- `resources/views/pages/parc/_partials/stats-cards.blade.php` (Q2 — tooltip)
- `docs/qa/domains/auth.md` (append section post-correctifs)

**Tests modifiés/ajoutés** (3) :
- `tests/Unit/Auth/V1/Migration/MigrationFragmentRendererTest.php` (+5 tests : token Windows/Linux/mint/unicité/CA prod)
- `tests/Feature/Auth/V1/Migration/MigrationControllerTest.php` (+3 tests : token cmd/sh/APCu side-effect)
- `tests/Feature/Auth/V1/Migration/MigrationE2EScenarioTest.php` (refactor étapes 2+3 Win+Linux + refactor `applications_endpoint_no_longer_sets_apcu_context`)

### Compteur tests global après corrections

- 5 fichiers Unit (inchangé) — **MigrationFragmentRendererTest passe de 9 à 14 cas**.
- 2 fichiers Feature : **MigrationControllerTest passe de 8 à 11 cas**, **MigrationE2EScenarioTest** : refactor (4 cas inchangés mais beaucoup plus stricts).
- 1 fichier Architecture (inchangé : 11 cas).
- ≥38 cas (vs ≥30 initial).

### Change Log — Corrections

- **2026-05-20 18:30** — Application post-review : Q1 Option A + Q2 Option α + 5 corrections auto-corrigeables. 1 nouveau fichier (Exceptions), 8 modifiés (app + UI + tests). +8 tests nets. Lint `php -l` 0 erreur sur tous les fichiers PHP touchés. Verdict review `🔴 → 🟡` (corrections appliquées, validation VM smoke 16.13bis-1..17 toujours requise pour passage `review → done`).

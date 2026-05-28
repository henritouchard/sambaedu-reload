# Story 3.2 : Boot et Menu Admin iPXE

Status: review

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

> **Suite directe de Story 3.1** (« iPXE Service Core »). Porte nativement les 3 endpoints admin/maintenance restants du legacy iPXE — **menu admin natif**, **maintenance** et **action** (rescue + factory reset) — pour **remplacer définitivement le proxy catchall** sur ces routes. Réutilise intégralement le socle 3.1 (`App\Ipxe\Services\IpxeService`, `IpxeMenuRenderer`, `WorkstationLocator`, channel log `ipxe`, middleware `auth.v1.lan-only`, table `MachineBootLog`).
>
> **Scope strict 3.2** = (a) endpoint `GET|POST /ipxe/admin` (port natif de `sambaedu/ipxe/admin.php` — menu admin **simplifié**, sans login AD, parité D8/3.1 LAN-only seul), (b) endpoint `GET|POST /ipxe/maintenance` (port natif de `sambaedu/ipxe/maintenance.php` — menu maintenance avec items rescue + winpe + clonezilla-live), (c) endpoint `GET|POST /ipxe/action/{action}` (port natif partiel de `sambaedu/ipxe/action.php` — handler générique whitelisté pour les 3 actions du scope : `rescuecd`, `winpe`, `factory_reset`), (d) extension `IpxeService::resolveProgrammedAction()` pour reconnaître la triade scope (`maintenance|rescue|factory_reset`), (e) 4 nouveaux templates Blade `resources/views/ipxe/menu/{admin,maintenance}.blade.php` + `resources/views/ipxe/actions/{rescuecd,winpe,factory_reset}.blade.php`, (f) extension `MachineBootLog::action` avec 3 nouvelles valeurs (`ipxe_admin`, `ipxe_maintenance`, `ipxe_action`), (g) tests Unit + Feature + Architecture ≥30 cumulés, (h) extension `docs/qa/domains/ipxe.md` avec section Story 3.2 + ≥10 scénarios stables 3.2-1 à 3.2-N.
>
> **HORS-SCOPE 3.2** (explicitement reportés aux stories suivantes) :
> - **Login admin AD** (`admin.php:27-55` — couplage `login_action()` + `have_right(SE_COMPUTER_INSTALL)`) → reporté Phase 3 (story dédiée si besoin terrain). Le menu admin natif 3.2 est servi **public au LAN** comme `/ipxe/boot` (parité D3/D8 de 3.1).
> - **Enrollment** (set-name, set-byod, salle, parcs, enleveparc, dhcp, double, reservation) → **Story 3.3**.
> - **Installation Linux** (preseed, installation-linux.php, deb_*.php actions, slitaz, primtux, ubuntu64, xubuntu*) → **Story 3.4**.
> - **Installation Windows** (wimboot, sysprep, installation-windows.php, Win10/*, deb_*, se4ad.php) → **Story 3.5**.
> - **Upload + association ISO Windows** → **Story 3.6**.
> - **Clonage manuel** (clonage.php, clonezilla_menu.php, sav_locale*/rest_locale*/live32/live64, modèle clonage programmé) → **Story 3.7**. (NB : `factory_reset` 3.2 est un **port limité** = restore depuis image disque préexistante, parité `actions/rescuecd.php` script clonage auto — pas la gestion CRUD des modèles de clonage.)
> - **LTSP** (legacy `boot.php:84-90` + `ltsp.php`) → définitivement abandonné (cf. 3.1 §HORS-SCOPE, feature LTSP non reportée).
> - **Retrait du catchall legacy** sur les routes `/ipxe/admin.php`, `/ipxe/maintenance.php`, `/ipxe/action.php` → reporté **fin d'Epic 3** (Story 3.7 cleanup) — en 3.2, les routes natives **précèdent** le catchall ; le catchall continue de servir uniquement les autres `/ipxe/*` non encore réécrits.

---

## ⚠️ Mode de livraison & contraintes opérationnelles

> **Worktree git dédié `ipxe`** : ne JAMAIS SSH `/vm` ni run de tests sur VM depuis ce worktree (cf. mémoire `feedback_worktree_no_vm_sync`). Static delivery iso 3.1 : lint statique `php -l` + PHPUnit local si `vendor/` présent + 0 sync manuel.
>
> - **Code synchronisé via inotify** sur `sambaedu-reload/*` branche `main`. Le worktree `ipxe` n'est PAS sync — Henri opère un cherry-pick / merge `ipxe → main` post-review pour propager.
> - **Action Henri post-merge VM up** : reload PHP-FPM (`systemctl reload php8.2-fpm@www-admin`), reload Apache, smoke `curl http://192.168.122.50/ipxe/admin -d 'mac=...&uuid=...'` (réponse text/plain `#!ipxe`), smoke `curl http://192.168.122.50/ipxe/maintenance -d 'mac=...&uuid=...'`, vérification logs channel `ipxe`, exécution `./scripts/run-tests.sh`, smoke poste réel (boot PXE → menu admin natif).
> - **NE PAS** modifier `sambaedu/ipxe/*.php` ni `legacy/modules/ipxe/*.php` — restent intacts (le catchall les sert encore pour les routes hors scope 3.2).
> - **NE PAS** créer de commit hors scope (rappel : commit `50c6275` 3.1 hors scope `docs/qa/domains/auth.md` à arbitrer par Henri — ne pas reproduire le pattern).

---

## Encadré contexte

**Continuité avec 3.1** : 3.1 a posé l'endpoint `GET|POST /ipxe/boot` qui rend le menu **known** avec **1 item login** placeholder qui chain vers `/ipxe/admin.php` legacy (catchall). 3.2 remplace ce placeholder par une route **native** `/ipxe/admin` servie par Laravel. Le menu `known` (3.1) sera **modifié** pour chain vers la route native au lieu du legacy — modification minimale du template `known.blade.php` (changement de la cible du `chain` dans la section `:login`).

**Topologie cible 3.2** :

```
Firmware iPXE
  ↓ GET /ipxe/boot (3.1) — résolution MAC/UUID → menu known
  ↓ user choose "1" (login)
  ↓ chain /ipxe/admin (3.2 — nouveau natif)
  ↓ menu admin (rendu Blade ipxe.menu.admin)
  ↓ user choose "m" (maintenance)
  ↓ chain /ipxe/maintenance (3.2)
  ↓ menu maintenance (rendu Blade ipxe.menu.maintenance)
  ↓ user choose "c" (rescuecd) ou "w" (winpe) ou "f" (factory_reset)
  ↓ chain /ipxe/action/{action} (3.2)
  ↓ rendu script ipxe.actions.{action} avec params kernel/initrd
  ↓ boot du payload (sysrescuecd / winpe / clonezilla restore)
```

**Comportement parité legacy** (à reproduire iso strict — cf. `sambaedu/ipxe/admin.php`, `maintenance.php`, `action.php`) :

1. **`/ipxe/admin`** :
   - Première fois (sans `mac`/`uuid` posés) → handshake iPXE (`params; param mac; param uuid; chain --replace --autofree /ipxe/admin##params`). **Iso** `admin.php:28-35` (sans le bloc login). **D5 ci-dessous** : on FACTORISE ce préambule avec le handshake de 3.1 via `IpxeMenuRenderer::renderHandshake(string $targetUrl)` — petite refacto compatible 3.1.
   - Avec `mac`+`uuid` posés → résolution via `WorkstationLocator` (réutilisation 3.1 stricte). Si trouvée → menu admin natif. Si non trouvée → menu admin avec `set-name` désactivé (= scope 3.3, on affiche un item placeholder « (n) Enregistrement non encore disponible — voir Story 3.3 ») OU fallback boot disk default. **D7 ci-dessous tranche** : on affiche un message neutre et item exit, pas d'item enrollment.
2. **`/ipxe/maintenance`** :
   - Idem handshake si pas de params. Avec params → résolution `WorkstationLocator` (résolution non-bloquante — un poste inconnu peut consulter le menu maintenance, parité legacy `maintenance.php:15` qui ne bloque pas). Menu avec items : (c) rescuecd, (w) winpe, (m) clonezilla-live → renomé en (f) factory_reset, (s) shell, (r) retour `/ipxe/admin`, (x) exit.
3. **`/ipxe/action/{action}`** :
   - `{action}` whitelisté : `rescuecd`, `winpe`, `factory_reset` (3 valeurs strictes en 3.2 — toute autre valeur retourne 404 + log warning). **D9 ci-dessous tranche** : pas de free string ; whitelist en enum `IpxeAdminAction`.
   - Idem handshake si pas de params. Avec params → log + résolution Workstation pour audit + rendu du script Blade `ipxe.actions.{action}`.
   - **Pas d'`auth_action()` legacy `action.php:28`** (parité 3.1 D8 — auth basée sur LAN-only, pas sur session_ipxe). Le firmware iPXE n'a pas de cookie/session intermédiaire — 3.1 a démontré que LAN-only est suffisant pour le périmètre Phase 2.

**Couplage Story 3.1 — modifications mineures attendues** :

| Élément 3.1 | Modification 3.2 | Raison |
|---|---|---|
| `resources/views/ipxe/menu/known.blade.php` section `:login` | Remplacer `chain {{ $serverBaseUrl }}/ipxe/admin.php##params` (legacy) par `chain {{ $serverBaseUrl }}/ipxe/admin##params` (natif 3.2) | Bascule sur la route native — fin du proxy catchall pour ce flow. |
| `IpxeService::resolveProgrammedAction()` | Méthode reste **placeholder retournant null** en 3.2 sur le path `handleBoot()` (la résolution d'action programmée DB-driven = scope 3.4-3.7). 3.2 ajoute une **nouvelle méthode** `IpxeService::resolveAdminAction(string $action): ?array` qui retourne le rendu du script pour les 3 actions whitelistées. Pas de couplage avec `resolveProgrammedAction()`. | Garde 3.2 strict — pas d'élargissement du scope. |
| `IpxeMenuRenderer::renderHandshake()` (3.1) | Étendu pour accepter un `string $chainTarget` optionnel (défaut `boot##params` iso 3.1). Permet à 3.2 de générer le handshake pour `/ipxe/admin` et `/ipxe/maintenance` sans dupliquer la logique. | Refactor minime, rétro-compatible (signature optionnelle). |
| `MachineBootLog::$action` | 3 nouvelles valeurs persistées : `ipxe_admin`, `ipxe_maintenance`, `ipxe_action`. T0.6 audit obligatoire (cf. 3.1 — `action` varchar(20) sans CHECK, 13 chars max OK). | Audit traçabilité par endpoint. |

**Idempotence + sécurité** : les 3 endpoints 3.2 sont **idempotents au sens fonctionnel** (mêmes paramètres → même réponse). Side effect log dans `MachineBootLog` + channel `ipxe` (iso 3.1). **Pas de side effect DB destructif** : pas d'`UPDATE Workstation`, pas d'écriture AD, pas de modification du parc. Les actions destructives (re-flash, restore disque) sont **exécutées côté poste** par le kernel iPXE — le serveur ne fait que rendre le script.

---

## ⚠️ Décisions tranchées (D1-D12, ne pas re-débattre)

> Cadrage SM 2026-05-19 par claude-opus-4-7. Le dev applique sans re-discuter. En cas de blocage technique réel, documenter dans Dev Agent Record et continuer.

### D1 — Namespace : extension **`App\Ipxe`** (pas de nouveau sous-namespace)

- Ajouts sous `app/Ipxe/` :
  ```
  app/Ipxe/
  ├── Services/
  │   ├── IpxeService.php          (modifié — ajout resolveAdminAction)
  │   ├── IpxeMenuRenderer.php     (modifié — ajout renderAdmin/renderMaintenance + renderAction)
  │   ├── IpxeAdminMenuBuilder.php (NEW — construit le payload variables pour admin menu)
  │   └── IpxeActionResolver.php   (NEW — résout l'action whitelistée + payload kernel/initrd)
  ├── Enums/
  │   └── IpxeAdminAction.php      (NEW — whitelist rescuecd|winpe|factory_reset)
  ├── Http/
  │   ├── Controllers/
  │   │   ├── IpxeBootController.php           (inchangé — story 3.1)
  │   │   ├── IpxeAdminController.php          (NEW — GET|POST /ipxe/admin)
  │   │   ├── IpxeMaintenanceController.php    (NEW — GET|POST /ipxe/maintenance)
  │   │   └── IpxeActionController.php         (NEW — GET|POST /ipxe/action/{action})
  │   └── Requests/
  │       ├── IpxeBootRequest.php              (inchangé — story 3.1)
  │       ├── IpxeAdminRequest.php             (NEW — mêmes règles permissives que IpxeBootRequest)
  │       ├── IpxeMaintenanceRequest.php       (NEW — idem)
  │       └── IpxeActionRequest.php            (NEW — + 'action' string required)
  └── (Support/, Enums/IpxeMenuKind.php : inchangés — étendus par enum case)
  ```
- **Anti-pattern** : ne PAS créer `App\Ipxe\Admin\…` ni `App\Ipxe\Maintenance\…` — sur-fragmentation. La frontière est par responsabilité (Service/Renderer/Resolver), pas par sous-domaine fonctionnel.
- Mise à jour `IpxeMenuKind` (enum) : ajout `case Admin = 'admin';` + `case Maintenance = 'maintenance';` + `case Action = 'action';`. Pas de nouveau enum séparé.

### D2 — 3 nouveaux endpoints HTTP (parité legacy iso)

- 3 blocs à ajouter dans `routes/web.php` **dans le bloc existant 3.1** (après les routes `/ipxe/boot` et **avant** le catchall — cf. commentaire `⚠⚠⚠`) :
  ```php
  Route::match(['GET', 'POST'], '/ipxe/admin', [
      \App\Ipxe\Http\Controllers\IpxeAdminController::class, 'handle',
  ])
      ->middleware(['auth.v1.lan-only', 'throttle:600,1'])
      ->name('ipxe.admin')
      ->withoutMiddleware(['web']);

  Route::match(['GET', 'POST'], '/ipxe/maintenance', [
      \App\Ipxe\Http\Controllers\IpxeMaintenanceController::class, 'handle',
  ])
      ->middleware(['auth.v1.lan-only', 'throttle:600,1'])
      ->name('ipxe.maintenance')
      ->withoutMiddleware(['web']);

  Route::match(['GET', 'POST'], '/ipxe/action/{action}', [
      \App\Ipxe\Http\Controllers\IpxeActionController::class, 'handle',
  ])
      ->middleware(['auth.v1.lan-only', 'throttle:600,1'])
      ->where('action', '[a-z_]+')
      ->name('ipxe.action')
      ->withoutMiddleware(['web']);
  ```
- **Pourquoi `GET|POST` ?** Iso-legacy `admin.php:11` / `maintenance.php:10` / `action.php:16` qui acceptent `$_POST` ou `$_GET`. Le firmware iPXE post-handshake utilise POST avec params encodés ; certains chains directs utilisent GET. Parité totale.
- **Pourquoi `withoutMiddleware(['web'])` ?** Iso 3.1 — pas de session/CSRF (un firmware iPXE n'a pas de cookies).
- **Pourquoi `where('action', '[a-z_]+')` ?** Filtre route-level — n'accepte que les actions lowercase snake_case. La whitelist effective est dans `IpxeAdminAction` enum (D9). Le filtre route bloque déjà les caractères dangereux (`/`, `..`, `;`, `&`).
- **Throttle 600/min/IP** : iso 3.1, suffisant pour les retries iPXE.

### D3 — Sécurité : **réutilisation stricte `auth.v1.lan-only` (16.11) — pas d'évolution**

- Iso 3.1 D3/D8. La criticité 3.2 est similaire à 3.1 : un firmware iPXE n'a pas d'OS qui puisse porter un JWT.
- **NE PAS** ajouter `auth.v1.workstation` (JWT) ni de bootstrap token md5 ni de `auth.v1.secure-headers` (16.10 ciblait les endpoints `/api/v1/*`).
- **Mitigation supplémentaire 3.2** : la whitelist enum `IpxeAdminAction` (D9) bloque l'exécution de scripts arbitraires. Un attaquant LAN qui appellerait `/ipxe/action/foo` reçoit 404 + log warning `ipxe.action.unknown_action`.
- **Réponse 403 hors LAN** : iso 16.11 — code `JwtErrorCodes::BOOTSTRAP_NOT_LAN`, format JSON `{success:false, error:"forbidden", message:"iPXE endpoint is restricted to LAN", code:"bootstrap.not_lan"}`. **PAS** de réponse en text/plain — un attaquant HTTP brut a droit au format JSON standard (iso 3.1 D3).

### D4 — Résolution poste : **réutilisation stricte `WorkstationLocator` (3.1)**

- Iso 3.1 D4 — pas de duplication, pas de refactor.
- 3 endpoints 3.2 résolvent la Workstation via `WorkstationLocator::locate($mac, $uuid, $product)`.
- **Tolérance poste inconnu** :
  - `/ipxe/admin` : poste inconnu → menu admin avec items dégradés (uniquement exit + retour). Pas d'erreur — parité legacy `admin.php:60-61` qui dégrade à `set-name` (qu'on remplace par un message neutre 3.2 cf. D7).
  - `/ipxe/maintenance` : poste inconnu → menu maintenance **complet** (rescue/winpe/factory_reset accessibles). Parité legacy `maintenance.php:15` qui n'authentifie pas le poste.
  - `/ipxe/action/{action}` : poste inconnu → action **toujours autorisée** (parité legacy `action.php:28` qui accepte `$action == "ltsp"` même sans auth). Le risque d'abus est mitigé par LAN-only + whitelist enum. Log info pour audit.
- **Anti-pattern** : ne PAS bloquer (404/403) sur poste inconnu pour `/maintenance` et `/action` — un poste neuf en factory_reset n'a pas encore d'enrollment.

### D5 — Refactor `IpxeMenuRenderer::renderHandshake()` (factorisation)

- 3.1 a posé `renderHandshake(): string` qui rend le handshake **figé** vers `boot##params`. 3.2 a besoin du même handshake pour `/ipxe/admin` et `/ipxe/maintenance`.
- **Décision** : étendre la signature avec un paramètre optionnel `?string $chainTarget = null` :
  ```php
  public function renderHandshake(?string $chainTarget = null): string
  ```
  - `$chainTarget === null` → rendu iso-3.1 (`chain --replace --autofree boot##params`) — **non-régression 3.1**.
  - `$chainTarget !== null` (ex: `'admin'`, `'maintenance'`) → rendu `chain --replace --autofree {$chainTarget}##params`.
- Le template Blade `resources/views/ipxe/menu/handshake.blade.php` est étendu pour accepter `$chainTarget` (default `boot`) via `compact()`.
- **Test non-régression** : `IpxeMenuRendererTest::it_renders_handshake_without_target_iso_31` (vert sans changement).
- **Anti-pattern** : ne PAS créer 3 méthodes `renderHandshakeForAdmin/Maintenance/Boot` — factorisation propre par paramètre, le renderer reste un service unique avec une API minimale.

### D6 — Templates Blade — **4 nouveaux fichiers + 1 modifié**

- **Nouveaux** :
  - `resources/views/ipxe/menu/admin.blade.php` (~30 lignes) — port natif `admin.php:69-167` simplifié (sans login, sans set-name, sans install, sans clonage CRUD ; items maintenance + exit + retour vers `/ipxe/boot`).
  - `resources/views/ipxe/menu/maintenance.blade.php` (~25 lignes) — port natif `maintenance.php:18-63` (items rescuecd + winpe + factory_reset + shell + retour + exit).
  - `resources/views/ipxe/actions/rescuecd.blade.php` (~12 lignes) — port natif `actions/rescuecd.php` (kernel + initrds sysresccd, params dynamiques).
  - `resources/views/ipxe/actions/winpe.blade.php` (~25 lignes) — port natif `actions/winpe.php` (kernel wimboot, 2 blocs `params` + 4 `initrd --name` Win10 + boot).
  - `resources/views/ipxe/actions/factory_reset.blade.php` (~10 lignes) — port natif `actions/clz_rest_sda2_sur_sda1.php` (kernel clonezilla + initrd + boot, ocs_prerun mount sda2 + ocs_live_run restoreparts savesda1 sda1).
- **Modifié** :
  - `resources/views/ipxe/menu/handshake.blade.php` (~10 lignes) — paramétrer `chain --replace --autofree {{ $chainTarget ?? 'boot' }}##params` (rétrocompat 3.1).
  - `resources/views/ipxe/menu/known.blade.php` (~25 lignes) — section `:login` : chain vers `{{ $serverBaseUrl }}/ipxe/admin##params` (natif 3.2) **au lieu de** `/ipxe/admin.php##params` (legacy). C'est la transition 3.1 → 3.2.
- **Charset ASCII strict** : iso 3.1 D9. Pas d'accent fr — utiliser des chars ASCII pur (« demarrer », « maintenance », « factory reset »).
- **Newline final obligatoire** : iso 3.1.
- **Pas de PHP residual** : iso 3.1 — test archi `it_renders_output_does_not_contain_php_tags` étendu aux 5 nouveaux templates.
- **Shebang `#!ipxe`** : injecté comme variable Blade `{!! $shebang !!}` (iso 3.1 DO-13 — contournement du strip PHP).

### D7 — Cas « poste inconnu » sur `/ipxe/admin` → **message neutre, pas d'item enrollment**

- Parité legacy `admin.php:60-61` dégrade à `set-name` (= scope 3.3) pour un poste sans enregistrement.
- **Décision 3.2** : afficher un menu admin minimal (uniquement exit + retour vers `/ipxe/boot`) avec un message neutre :
  ```
  echo Poste non enregistre — l'enregistrement sera disponible Story 3.3
  item --key x exit (x) Quitter iPXE et booter le disque dur
  item --key r retour (r) Retour au menu de boot
  ```
- **Pas d'item `set-name`** en 3.2 — Story 3.3 ouvrira ce flow.
- **Justification produit** : on ne veut pas laisser un admin coincé en boucle vers un item cassé ; un message neutre + retour vers `/ipxe/boot` est crédible Phase 2.

### D8 — Logging structuré channel `ipxe` (extension 3.1 D7)

- 3 nouveaux events à logger (channel `ipxe`, driver daily 14j — iso 3.1) :
  - `ipxe.admin.menu_rendered` (info) — menu admin rendu. Context : ip, mac_prefix (6), uuid_prefix (8), workstation_id (nullable), workstation_name_prefix (6), menu_variant (`known|unknown`).
  - `ipxe.maintenance.menu_rendered` (info) — menu maintenance rendu. Context : idem admin.
  - `ipxe.action.dispatched` (info) — action whitelistée dispatchée. Context : ip, mac_prefix, uuid_prefix, workstation_id (nullable), action (`rescuecd|winpe|factory_reset` — pas de troncature, valeur connue de la whitelist).
  - `ipxe.action.unknown_action` (warning) — action hors whitelist demandée. Context : ip, mac_prefix, uuid_prefix, action_requested (tronqué 32 chars). Cas attaque/exploration.
  - `ipxe.action.render_error` (error) — exception côté `IpxeMenuRenderer::renderAction()`. Iso 3.1 `ipxe.boot.render_error`.
- **Préfixes obligatoires** sur valeurs sensibles : iso 3.1 AC7.3 — MAC 6 chars, UUID 8 chars, name 6 chars. **Exception action_requested** : tronqué à 32 chars (action hors whitelist peut être un user-input arbitraire — sanitize ASCII + tronque).
- **Pas de secret loggé** : iso 3.1.

### D9 — Whitelist actions via **enum `IpxeAdminAction`** (sécurité critique)

- Nouvel enum `App\Ipxe\Enums\IpxeAdminAction` :
  ```php
  enum IpxeAdminAction: string
  {
      case Rescuecd = 'rescuecd';
      case Winpe = 'winpe';
      case FactoryReset = 'factory_reset';

      public function template(): string
      {
          return match($this) {
              self::Rescuecd => 'ipxe.actions.rescuecd',
              self::Winpe => 'ipxe.actions.winpe',
              self::FactoryReset => 'ipxe.actions.factory_reset',
          };
      }

      public function logName(): string
      {
          return $this->value;
      }
  }
  ```
- `IpxeActionController::handle()` appelle `IpxeAdminAction::tryFrom($action)` ; si `null` → 404 + log warning `ipxe.action.unknown_action`. **Anti-pattern** : ne PAS faire un `match` géant in-line dans le controller — l'enum est la source unique de vérité.
- **3 actions strictes en 3.2** — ajouts ultérieurs (`installation_linux`, `installation_windows`, `clonezilla_live`, `clonezilla_prevert`, `live32`, `live64`, `sav_locale*`, `rest_locale*`, `deb_*`, `wimboot10`, `wimboot11`, `winpeshl`, etc. — total ~30 actions legacy `actions/*.php`) **seront ajoutés aux stories 3.4/3.5/3.7** au fil de leur implémentation. La whitelist doit rester stricte — pas de free string.
- **Test archi obligatoire** : `IpxeNamespaceTest::ipxe_admin_action_enum_only_has_3_cases_in_story_3_2` (sera relaxé par 3.4/3.5/3.7).

### D10 — Variables de configuration : **extension `config/ipxe.php`**

- Nouvelle section dans `config/ipxe.php` :
  ```php
  'admin' => [
      'menu_timeout_ms' => (int) env('IPXE_ADMIN_TIMEOUT_MS', 30000),
  ],
  'maintenance' => [
      'menu_timeout_ms' => (int) env('IPXE_MAINTENANCE_TIMEOUT_MS', 10000),
      'background_png' => env('IPXE_MAINTENANCE_BG_PNG', 'png/sysrescuecd.png'),
  ],
  'actions' => [
      'os_url' => env('IPXE_OS_URL', null),  // null → fallback dynamique server scheme+host + '/ipxe'
      'script_url' => env('IPXE_SCRIPT_URL', null),
      // Optionnel — n'est utilisé que par actions/rescuecd.php legacy via `$config['se4install_passwd']`.
      // En 3.2 on lit depuis sambaedu.se4install_passwd existant (Phase 2 — pas de nouveau secret).
      'se4install_passwd_config_key' => env('IPXE_SE4INSTALL_PASSWD_KEY', 'sambaedu.se4install_passwd'),
  ],
  ```
- **`os_url`/`script_url`** : iso-legacy `admin.php:12` `http://{SERVER_ADDR}:{SERVER_PORT}/ipxe/`. En 3.2 on reconstruit depuis Request (déjà fait par 3.1 `resolveServerBaseUrl()`). Override env pour environnements avec proxy intermédiaire.
- **`se4install_passwd`** : iso-legacy `actions/rescuecd.php:6` qui interpole `rootpass=` dans le kernel command-line. Lecture via `config('sambaedu.se4install_passwd')` (Phase 2 — pas de migration vers vault chiffré, deféré Phase 3).

### D11 — `MachineBootLog::action` — extension **sans migration** (D5 iso 3.1)

- 3.1 a confirmé en T0.6 que `MachineBootLog::$action` est `varchar(20)` sans CHECK constraint. Les 3 nouvelles valeurs sont `ipxe_admin` (10), `ipxe_maintenance` (16), `ipxe_action` (11) — toutes ≤20 chars.
- **Pas de migration** dans cette story (iso 3.1 D12).
- **Action** loggée par endpoint :
  - `/ipxe/admin` (succès rendu menu) → `action='ipxe_admin'`.
  - `/ipxe/maintenance` (succès rendu menu) → `action='ipxe_maintenance'`.
  - `/ipxe/action/{action}` (succès dispatch whitelist) → `action='ipxe_action'` + colonne `initiated_by='ipxe:'.{action.value}` (ex: `ipxe:rescuecd`, `ipxe:winpe`, `ipxe:factory_reset`). **Encodage `initiated_by`** : la colonne est `varchar(50)` selon migration 3.1 — `ipxe:factory_reset` = 19 chars OK.
- **Hypothèse de cadrage SM** : `MachineBootLog::$fillable` autorise `action` + `initiated_by` (confirmé par 3.1 — pas de blocage à attendre).
- **Anti-pattern** : ne PAS étendre `MachineBootLog` avec un nouveau champ `ipxe_endpoint` ou `ipxe_target` — Phase 2 garde la table simple. Si analytics fines nécessaires Phase 3 → story dédiée.

### D12 — Migrations : **aucune nouvelle migration** dans cette story

- Iso 3.1 D12. Si T0.6 audit révèle un blocage `MachineBootLog::$fillable` ou CHECK constraint, escalader à Henri (peu probable — 3.1 a déjà confirmé l'audit).

---

## Story

As **un poste de travail (Windows ou Linux) en boot iPXE déjà résolu via `/ipxe/boot` (3.1)** ainsi qu'**un mainteneur du codebase `sambaedu-reload`** et **Henri en tant qu'admin SER opérant sur le LAN scolaire** :

I want
- disposer de **3 routes Laravel natives** (`/ipxe/admin`, `/ipxe/maintenance`, `/ipxe/action/{action}`) qui remplacent progressivement les endpoints legacy `admin.php`/`maintenance.php`/`action.php` du proxy catchall ;
- accéder depuis le menu `known` (3.1) à un **menu admin natif** qui propose les outils de maintenance et de rescue/factory reset, sans dépendre du legacy PHP procédural ;
- **exécuter** les actions `rescuecd` (boot SystemRescueCD), `winpe` (boot Windows PE pour réparation) et `factory_reset` (clonezilla restore sda2 → sda1) à travers une whitelist enum stricte qui empêche l'exécution de scripts arbitraires ;
- assurer **zéro régression** sur les autres routes iPXE legacy non encore réécrites (`/ipxe/installation-linux.php`, `/ipxe/installation-windows.php`, `/ipxe/clonage.php`, `/ipxe/enregistrement.php`, `/ipxe/Win10/*`, etc.) — elles continuent de passer par le catchall jusqu'aux stories 3.3-3.7.

So que :
- (a) **Henri** dispose d'un menu admin iPXE natif testé, journalisé via channel `ipxe`, sans dépendance au legacy PHP procédural — visible via `tail storage/logs/ipxe/ipxe-$(date +%F).log` ;
- (b) **les opérateurs terrain** peuvent réparer un poste corrompu (rescuecd) ou réinitialiser un poste à l'état usine (factory_reset) depuis le menu iPXE en LAN, sans avoir à intervenir physiquement avec une clé USB ;
- (c) **les développeurs des stories 3.3-3.7** disposent du pattern complet (controller fin + service orchestrateur + renderer Blade + enum whitelist + tests cumulés) à étendre — chaque nouvelle action installable/clonable s'ajoute via 3 fichiers : 1 case enum + 1 template Blade + 1 méthode resolver.

---

## Contexte

### État entrant (post-Story 3.1 review, 3.2 = suite directe)

| Élément | État actuel | Action 3.2 |
|---|---|---|
| Namespace `App\Ipxe` | ✅ Créé par 3.1 (10 classes + 3 templates Blade + provider + config) | **Étendre** — ajouter 3 controllers + 3 FormRequests + 2 services (`IpxeAdminMenuBuilder`, `IpxeActionResolver`) + 1 enum (`IpxeAdminAction`) + 5 templates Blade (4 nouveaux + 1 modifié) |
| `IpxeService::handleBoot()` | ✅ Existant — gère `/ipxe/boot` | **Inchangé** — 3.2 ajoute des routes parallèles sans toucher au flow boot. |
| `IpxeService::resolveProgrammedAction()` | ✅ Placeholder retourne null | **Inchangé** — reste null en 3.2 (l'action programmée DB-driven sera 3.4-3.7). 3.2 ajoute une nouvelle méthode `resolveAdminAction(IpxeAdminAction $action): string` qui retourne le rendu Blade de l'action. |
| `IpxeMenuRenderer::renderHandshake()` | ✅ Rend `boot##params` figé | **Étendre** — paramètre optionnel `?string $chainTarget = null` (D5). |
| `IpxeMenuRenderer::renderKnown()` | ✅ Rend menu known avec login → `/ipxe/admin.php` (legacy) | **Modifier** — chain vers `/ipxe/admin` natif au lieu de `admin.php` (transition 3.1 → 3.2). |
| `WorkstationLocator::locate()` | ✅ Existant 3.1 | **Réutiliser** — pas de modification. |
| `auth.v1.lan-only` (`EnsureLanIp`) | ✅ Livré 16.11, réutilisé 3.1 | **Réutiliser** sur les 3 nouveaux endpoints (D3). |
| `MachineBootLog::action` | ✅ varchar(20), accepte `'ipxe_boot'` (T0.6 3.1) | **Étendre** — 3 nouvelles valeurs `'ipxe_admin'`, `'ipxe_maintenance'`, `'ipxe_action'` (D11). Pas de migration. |
| Channel log `ipxe` | ✅ Créé 3.1 (daily 14j) | **Étendre** — 5 nouveaux events (D8). |
| `config/ipxe.php` | ✅ Créé 3.1 (se4fs_name, menu timeouts, force_uefi_products) | **Étendre** — 3 nouvelles sections `admin`, `maintenance`, `actions` (D10). |
| Routes `/ipxe/admin`, `/ipxe/maintenance`, `/ipxe/action/{action}` | ❌ Servies par catchall legacy | **Créer** — 3 routes natives AVANT le catchall (D2). |
| Templates Blade `resources/views/ipxe/menu/{admin,maintenance}.blade.php` | ❌ N'existent pas | **Créer** (D6). |
| Templates Blade `resources/views/ipxe/actions/{rescuecd,winpe,factory_reset}.blade.php` | ❌ N'existent pas | **Créer** (D6). |
| Doc QA `docs/qa/domains/ipxe.md` | ✅ Créée 3.1 (9 scénarios stables 3.1-1 à 3.1-9) | **Étendre** — section `## Story 3.2` + ≥10 scénarios stables `3.2-1` à `3.2-N`. Numérotation 3.1 préservée intacte (append-only). |
| Tests Unit/Feature/Architecture iPXE | ✅ 75/75 verts post-corrections 3.1 | **Étendre** — ≥30 nouveaux tests cumulés (≥15 unit + ≥10 feature + ≥4 archi). Non-régression 75 tests 3.1 préservée. |

### Source de vérité du comportement attendu

Les 3 fichiers legacy à lire en T0.4 (lecture obligatoire) :
- `sambaedu/ipxe/admin.php` (167 lignes) — menu admin. **Périmètre 3.2** : lignes 75-90 (header `menu`), 100-102 (item maintenance), 115-117 (autres options shell/exit), 124-125 (`:exit` + `boot_disk()`), 127-128 (`:maintenance` chain). **Ignorer** : lignes 27-55 (login), 60-67 (set-name/dual-boot), 86-100 (set-name/salle/parcs), 103-114 (clonage/install), 129-167 (autres chains hors scope).
- `sambaedu/ipxe/maintenance.php` (63 lignes) — menu maintenance. **Périmètre 3.2** : intégralité du fichier (60 lignes — `:rescuecd` ligne 49-51, `:winpe` ligne 53-55, `:clonezilla` ligne 57-59 → renommé `:factory_reset` 3.2 + cible action `factory_reset`).
- `sambaedu/ipxe/action.php` (51 lignes) — dispatcher action. **Périmètre 3.2** : lignes 28-41 (dispatch via `include actions/{$action}.php` → réécrit en `view('ipxe.actions.'.$action->value)->render()`). **Ignorer** : ligne 28 `auth_action($config, $mac, $session_ipxe)` (parité D3/D4 — pas de session, LAN-only suffit).
- `sambaedu/ipxe/actions/rescuecd.php` (10 lignes) — kernel sysrescuecd.
- `sambaedu/ipxe/actions/winpe.php` (32 lignes) — kernel wimboot Win10 (réparation Windows).
- `sambaedu/ipxe/actions/clz_rest_sda2_sur_sda1.php` (10 lignes) — kernel clonezilla restoreparts → renommé `factory_reset` 3.2.

### Risques entrants

| Risque | Sévérité | Mitigation 3.2 |
|---|---|---|
| Collision routes `/ipxe/admin`, `/ipxe/maintenance`, `/ipxe/action/{action}` natives vs catchall | 🟠 Élevée | Iso 3.1 D2 — bloc routes dans `routes/web.php` AVANT catchall. Test archi `ipxe_3_2_routes_are_declared_before_catchall` étendu. |
| Régression sur `known.blade.php` (modification `:login` chain target) — un poste qui était en 3.1 stable casse en 3.2 | 🟠 Élevée | T6.1 test feature qui asserte `known` chain vers `/ipxe/admin` natif (3.2) au lieu de `/ipxe/admin.php` (legacy). Test archi vérifie le contenu du template. Smoke obligatoire poste réel Henri post-merge VM. |
| Whitelist `IpxeAdminAction` trop permissive — un attaquant LAN pourrait deviner d'autres action names | 🟡 Moyenne | D9 enum strict 3 cases + test unit qui asserte que `IpxeAdminAction::cases()` retourne exactement `[Rescuecd, Winpe, FactoryReset]`. Action hors whitelist → 404 + log warning. |
| Template `winpe.blade.php` complexe (2 blocs `params` + 4 `initrd --name` + `iseq ${platform} efi`) mal transposé du PHP procédural | 🟡 Moyenne | T3.4 test unit qui asserte le rendu strict iso-legacy (snapshot test si nécessaire — `assertStringContainsString` sur les ~10 marqueurs `initrd --name winpeshl.ini`, `param bios uefi`, `Win10/wimboot`, `Win10/winpeshl.ini`, etc.). |
| Variable `$os_url` / `$script_url` non rendues correctement dans les actions Blade | 🟡 Moyenne | D10 — résolution via `IpxeActionResolver` qui injecte `$osUrl` + `$scriptUrl` dans le contexte Blade. Test unit qui asserte les variables interpolées. |
| Variable `$config['se4install_passwd']` (rescuecd `rootpass=...` legacy) exposée dans le rendu HTTP | 🟢 Mineure | D10 — lecture via `config('sambaedu.se4install_passwd')` côté serveur uniquement, interpolation dans le kernel cmdline rendu une seule fois (le firmware iPXE consomme et oublie). **Audit Phase 3** : envisager d'isoler ce password derrière un endpoint dédié authentifié — déféré. |
| MAC dupliquée en base (clone disque, VM) → menu admin du mauvais poste affiché | 🟢 Mineure | Iso 3.1 — `WorkstationLocator` priorise UUID sur MAC. Si UUID match → menu admin du bon poste. Si seul MAC match → log warning. Pas d'élargissement du problème en 3.2. |
| `MachineBootLog::action` rejette `'ipxe_action'` ou `'ipxe_maintenance'` (16 chars) si CHECK ajouté entre 3.1 et 3.2 | 🟡 Moyenne | T0.6 audit obligatoire (re-vérifier post-3.1 — le schema peut avoir évolué si une autre story a touché à la table). Escalation Henri si bloqué. |
| Templates iPXE Win10 dépendent de fichiers statiques `Win10/wimboot`, `Win10/winpeshl.ini` servis par Apache via le catchall — si retrait du catchall sur `/ipxe/*` (3.7) → casse | 🟢 Mineure | 3.2 ne retire AUCUN catchall. Le catchall continue de servir `/ipxe/Win10/*` jusqu'à 3.5/3.7. Test feature non-régression `/ipxe/Win10/wimboot` reste catchall. |
| Risque opérationnel : un admin déclenche un `factory_reset` par erreur sans confirmation | 🟠 Élevée | **3.2 n'ajoute pas de confirmation** (parité legacy `maintenance.php:31-34` qui exécute directement) — le menu iPXE est un outil avancé réservé au LAN admin. Documentation runbook QA 3.2-N (« attention factory_reset détruit les données — backup avant ») suffit Phase 2. |

### Pré-requis (à valider en T0)

- **Worktree git `ipxe`** : branche dédiée, pas de SSH VM. Iso 3.1.
- **Story 3.1 en review accepté ou done** : ✅ status `review` au moment du cadrage SM. La phase dev 3.2 nécessitera que 3.1 soit en `done` (Henri accepte le commit `50c6275` ou bascule 3.1 review → done). **Bloquant amont à valider en T0.1.**
- **Schema `machine_boot_logs`** : ✅ confirmé par 3.1 T0.6 (`action` varchar(20) sans CHECK). À re-vérifier en T0.6 (peu probable que ça ait évolué entre 3.1 et 3.2 sur la même branche).
- **Fichiers statiques Win10/wimboot/winpeshl.ini, sysresccd/*, clonezilla/*** : ✅ servis par Apache via le catchall — confirmé en T0.4 lecture legacy.
- **Apache config** : pas de modification — `/ipxe/admin`, `/ipxe/maintenance`, `/ipxe/action/{action}` arrivent via le catchall et seront interceptés par les routes natives 3.2 AVANT le catchall (iso 3.1 D2).

---

## Acceptance Criteria

> AC organisées en **10 volets**. Volet 10 = QA + sprint-status (append-only sur le runbook `ipxe.md` 3.1).

### Volet 1 — Enum whitelist `IpxeAdminAction` + Resolver (D9)

**AC1.1** — **Création de l'enum `IpxeAdminAction`**

**Given** la classe `App\Ipxe\Enums\IpxeAdminAction`,
**When** elle est instanciée via `IpxeAdminAction::tryFrom('rescuecd')`,
**Then** :
- 3 cases stricts : `Rescuecd = 'rescuecd'`, `Winpe = 'winpe'`, `FactoryReset = 'factory_reset'`.
- Méthode `template(): string` retourne le nom du template Blade (`ipxe.actions.rescuecd`, etc.).
- Méthode `logName(): string` retourne la string snake_case (`rescuecd`, `winpe`, `factory_reset`).
- **Anti-pattern** : pas de méthode `execute()` ni `dispatch()` — l'enum est une whitelist + métadata, l'exécution est dans `IpxeActionResolver`.

**And** test unit `tests/Unit/Ipxe/Enums/IpxeAdminActionTest.php` ≥4 tests :
- `it_has_exactly_three_cases_in_story_3_2` (asserte `count(IpxeAdminAction::cases()) === 3`)
- `it_resolves_template_path_for_each_case`
- `it_returns_null_for_unknown_action` (`tryFrom('unknown')` → null)
- `it_returns_log_name_iso_value`

**AC1.2** — **Création du service `IpxeActionResolver` (D9, D10)**

**Given** la classe `App\Ipxe\Services\IpxeActionResolver`,
**When** elle est invoquée avec `resolve(IpxeAdminAction $action, Workstation $ws, Request $request): string`,
**Then** :
- Rend le template Blade `view($action->template(), [...])->render()` avec contexte :
  - `$shebang` = `'#!ipxe'` (iso 3.1 DO-13)
  - `$mac`, `$uuid`, `$workstationName` (sanitize ASCII via réutilisation `IpxeMenuRenderer::sanitizeAscii` si externalisé en helper, sinon dupliquer le pattern)
  - `$osUrl` = `config('ipxe.actions.os_url')` ?? `$request->getSchemeAndHttpHost().'/ipxe'`
  - `$scriptUrl` = `config('ipxe.actions.script_url')` ?? `$osUrl`
  - `$se4installPasswd` = `(string) config('sambaedu.se4install_passwd', '')` (uniquement utilisé par `rescuecd.blade.php`)
  - `$action` = `$action->value` (chaîne `'rescuecd'`, `'winpe'`, `'factory_reset'`)
  - Variables spécifiques `winpe` : `$version = 'Win11'` (iso `actions/winpe.php:7`), `$debug = 1` (iso ligne 6), `$disk = (int) $request->input('disk', 0)`, `$perso = (int) $request->input('perso', 0)`.
- Retourne la chaîne complète `#!ipxe\nkernel ...\nboot\n`.

**And** test unit `tests/Unit/Ipxe/Services/IpxeActionResolverTest.php` ≥6 tests :
- `it_renders_rescuecd_with_kernel_sysresccd_url`
- `it_renders_winpe_with_wimboot_and_4_initrds`
- `it_renders_factory_reset_with_clonezilla_kernel_and_restoreparts`
- `it_injects_se4install_passwd_from_config_for_rescuecd`
- `it_resolves_os_url_from_config_when_set` (override env)
- `it_resolves_os_url_from_request_scheme_and_host_when_config_empty`

### Volet 2 — Endpoints HTTP + FormRequests (D2)

**AC2.1** — **Controllers fins (≤20 lignes hors docblocks)**

**Given** les 3 classes `IpxeAdminController`, `IpxeMaintenanceController`, `IpxeActionController` dans `App\Ipxe\Http\Controllers\`,
**When** un poste appelle leurs routes respectives,
**Then** :
- Chaque controller a une méthode `handle(...)` qui délègue 100% à un service (`IpxeService::handleAdmin()`, `IpxeService::handleMaintenance()`, `IpxeService::handleAction()`).
- Aucune logique métier dans le controller.
- Iso 3.1 DO-6 — pattern controller fin.
- **Anti-pattern** : ne PAS instancier les services manuellement — DI via le constructeur (`__construct(private readonly IpxeService $service)`).

**And** test unit `tests/Unit/Ipxe/Http/Controllers/IpxeAdminControllerTest.php` ≥1 test (smoke) + idem pour les 2 autres.

**AC2.2** — **FormRequests permissifs (iso 3.1 AC5.2)**

**Given** les 3 classes `IpxeAdminRequest`, `IpxeMaintenanceRequest`, `IpxeActionRequest` dans `App\Ipxe\Http\Requests\`,
**When** un poste poste les paramètres iPXE,
**Then** :
- `IpxeAdminRequest::rules()` : `mac`/`uuid`/`product` nullable string max 64/64/128 (iso 3.1).
- `IpxeMaintenanceRequest::rules()` : idem.
- `IpxeActionRequest::rules()` : `mac`/`uuid`/`product` idem **+ `action`** lecture via `$request->route('action')` (pas dans rules — c'est un route param). Pas de validation FormRequest sur `action` — la whitelist enum dans `IpxeActionController::handle()` la couvre.
- `authorize()` retourne `true` (auth via middleware `auth.v1.lan-only`).

**And** test feature ≥3 cas oversize (`mac > 64 chars` → 422 via `postJson`).

### Volet 3 — `IpxeService::handleAdmin/handleMaintenance/handleAction` (orchestration)

**AC3.1** — **`IpxeService::handleAdmin(Request $request): Response`**

**Given** la méthode `handleAdmin()` étendant `IpxeService` 3.1,
**When** elle est invoquée par `IpxeAdminController`,
**Then** :
- Extrait `mac`/`uuid`/`product`/`ip` iso `handleBoot()`.
- **Cas handshake** : si `$mac === '' || $uuid === ''` → rend `renderer->renderHandshake('admin')` (chainTarget = `'admin'`, cf. D5).
- **Cas locate** : sinon → `locator->locate(...)` :
  - Si null → log `ipxe.admin.menu_rendered` (variant `unknown`) + insert MachineBootLog (action `ipxe_admin`, workstation_id null) + rend `renderer->renderAdminMenu(null, ...)` (= menu admin minimal D7).
  - Si workstation → log `ipxe.admin.menu_rendered` (variant `known`) + insert MachineBootLog + rend `renderer->renderAdminMenu($ws, ...)`.
- Headers D10 iso 3.1 (text/plain + no-store + noindex).
- **safeRender** wrap (iso 3.1 fix #2) — exception render → fallback minimal iPXE + log error `ipxe.admin.render_error`.

**And** test unit `tests/Unit/Ipxe/Services/IpxeServiceAdminTest.php` ≥6 tests :
- `it_returns_handshake_when_mac_and_uuid_missing` (chainTarget admin)
- `it_returns_admin_menu_for_known_workstation`
- `it_returns_minimal_admin_menu_for_unknown_workstation`
- `it_logs_menu_rendered_event`
- `it_persists_machine_boot_log_with_action_ipxe_admin`
- `it_returns_text_plain_in_all_paths`

**AC3.2** — **`IpxeService::handleMaintenance(Request $request): Response`**

**Given** la méthode `handleMaintenance()`,
**When** elle est invoquée par `IpxeMaintenanceController`,
**Then** :
- Handshake si pas de params (chainTarget = `'maintenance'`).
- Sinon résolution Workstation **non-bloquante** (parité legacy — un poste inconnu peut consulter maintenance).
- Log `ipxe.maintenance.menu_rendered` + insert MachineBootLog (action `ipxe_maintenance`).
- Rend `renderer->renderMaintenanceMenu($workstation, $ip)` — menu identique connu/inconnu (pas de variant — la maintenance ne dépend pas de l'enrollment du poste).
- Headers D10 + safeRender wrap.

**And** test unit `tests/Unit/Ipxe/Services/IpxeServiceMaintenanceTest.php` ≥5 tests.

**AC3.3** — **`IpxeService::handleAction(Request $request, string $action): Response`**

**Given** la méthode `handleAction()`,
**When** elle est invoquée par `IpxeActionController` avec le route param `{action}`,
**Then** :
- Résolution enum : `$adminAction = IpxeAdminAction::tryFrom($action)`.
- **Cas action inconnue** : si `null` → log warning `ipxe.action.unknown_action` (action_requested tronqué 32 chars + sanitize ASCII) + `abort(404)` (Laravel renvoie JSON standard `{message: "Not Found"}` — iso 3.1 D3 pour les 403 hors LAN, format JSON pour les erreurs non-iPXE).
- **Cas action handshake** : si `$mac === '' || $uuid === ''` → handshake (chainTarget = `'action/'.$action`, ex. `'action/rescuecd'`).
- **Cas action dispatch** :
  - Résolution Workstation (non-bloquante, parité legacy — un poste neuf en factory_reset n'a pas d'enrollment).
  - Log `ipxe.action.dispatched` (action = `$adminAction->value`).
  - Insert MachineBootLog (action `ipxe_action`, initiated_by `ipxe:`.$adminAction->value).
  - Rend `resolver->resolve($adminAction, $workstation, $request)` (= le template Blade de l'action).
- Headers D10 + safeRender wrap → fallback minimal iPXE en cas d'exception template.

**And** test unit `tests/Unit/Ipxe/Services/IpxeServiceActionTest.php` ≥7 tests :
- `it_aborts_404_when_action_unknown`
- `it_logs_unknown_action_warning_event`
- `it_returns_handshake_when_action_known_but_params_missing` (chainTarget `action/rescuecd`)
- `it_returns_rescuecd_script_when_action_rescuecd_and_params_posted`
- `it_returns_winpe_script_when_action_winpe`
- `it_returns_factory_reset_script_when_action_factory_reset`
- `it_persists_machine_boot_log_with_action_ipxe_action_and_initiated_by_ipxe_action_value`

### Volet 4 — `IpxeMenuRenderer` extension (D5, D6)

**AC4.1** — **Extension `renderHandshake(?string $chainTarget = null): string` (D5)**

**Given** la méthode `IpxeMenuRenderer::renderHandshake()` 3.1,
**When** elle est invoquée avec ou sans paramètre,
**Then** :
- `renderHandshake()` (sans param) → rend `chain --replace --autofree boot##params` (iso 3.1 — **non-régression critique**).
- `renderHandshake('admin')` → rend `chain --replace --autofree admin##params`.
- `renderHandshake('maintenance')` → rend `chain --replace --autofree maintenance##params`.
- `renderHandshake('action/rescuecd')` → rend `chain --replace --autofree action/rescuecd##params`.
- Template Blade `handshake.blade.php` accepte la variable `$chainTarget` avec défaut `'boot'`.

**And** test unit `tests/Unit/Ipxe/Services/IpxeMenuRendererTest.php` (existant 3.1) **étendu** ≥3 tests :
- `it_renders_handshake_without_target_iso_31` (non-régression — body identique au snapshot 3.1)
- `it_renders_handshake_with_admin_target_chains_to_admin`
- `it_renders_handshake_with_action_target_chains_to_action_path`

**AC4.2** — **Nouvelle méthode `renderAdminMenu(?Workstation $ws, string $ip, string $serverBaseUrl): string`**

**Given** la méthode `IpxeMenuRenderer::renderAdminMenu()`,
**When** invoquée avec `$ws` non-null,
**Then** :
- Rend `resources/views/ipxe/menu/admin.blade.php` avec contexte : `$shebang`, `$workstationName` (sanitize ASCII), `$ip`, `$uuid` (sanitize), `$mac`, `$serverBaseUrl`, `$menuTimeoutMs` (= config admin.menu_timeout_ms = 30000), `$resolutionPng`, `$resolutionX`, `$resolutionY`, `$bootDiskFallback`, `$isKnown = true`.
- Items rendus : (m) maintenance, (s) shell, (r) retour /ipxe/boot, (x) exit.
- Item `(s) shell` chain vers iPXE shell built-in (`shell` keyword).
- Item `(m) maintenance` chain vers `{{ $serverBaseUrl }}/ipxe/maintenance##params`.
- Item `(r) retour` chain vers `{{ $serverBaseUrl }}/ipxe/boot##params`.
- Item `(x) exit` → `:exit` + `{!! $bootDiskFallback !!}` (iso 3.1 default.blade.php).
**When** invoquée avec `$ws = null` (D7),
**Then** :
- Items dégradés : (x) exit + (r) retour /ipxe/boot uniquement. **Pas** d'item maintenance (= cohérent : un poste inconnu n'a pas accès à la maintenance avancée).
- Variable `$isKnown = false` exposée au template pour conditionnement.

**And** test unit `IpxeMenuRendererTest::it_renders_admin_menu_for_known_workstation` + `it_renders_admin_menu_minimal_for_unknown` (≥4 tests cumulés).

**AC4.3** — **Nouvelle méthode `renderMaintenanceMenu(?Workstation $ws, string $ip, string $serverBaseUrl): string`**

**Given** la méthode `IpxeMenuRenderer::renderMaintenanceMenu()`,
**When** invoquée,
**Then** :
- Rend `resources/views/ipxe/menu/maintenance.blade.php` avec contexte : `$shebang`, `$workstationName`, `$ip`, `$mac`, `$uuid`, `$serverBaseUrl`, `$menuTimeoutMs` (= config maintenance.menu_timeout_ms = 10000), `$resolutionPng` (= config maintenance.background_png = `'png/sysrescuecd.png'`), `$resolutionX`, `$resolutionY`, `$bootDiskFallback`.
- Items rendus : (c) rescuecd, (w) winpe, (f) factory_reset, (s) shell, (r) retour /ipxe/admin, (x) exit.
- Items rescuecd/winpe/factory_reset chain vers `{{ $serverBaseUrl }}/ipxe/action/rescuecd##params` etc.
- Item retour chain vers `{{ $serverBaseUrl }}/ipxe/admin##params`.

**And** test unit ≥3 tests.

**AC4.4** — **Modification `renderKnown()` — chain login vers `/ipxe/admin` natif**

**Given** la méthode `IpxeMenuRenderer::renderKnown()` 3.1,
**When** elle est invoquée,
**Then** :
- Le template `known.blade.php` section `:login` chain vers `{{ $serverBaseUrl }}/ipxe/admin##params` au lieu de `{{ $serverBaseUrl }}/ipxe/admin.php##params` (legacy).
- **Non-régression** : tous les tests 3.1 sur `renderKnown()` restent verts (sauf le test qui asserte la cible legacy — à mettre à jour avec la nouvelle cible 3.2).
- **Anti-pattern** : ne PAS ajouter de feature-flag temporaire pour basculer legacy/natif — la bascule est définitive en 3.2.

**And** test unit `IpxeMenuRendererTest::it_renders_known_menu_with_login_chain_to_native_admin` (mis à jour 3.2 — était `..._to_admin_php_legacy` en 3.1).

### Volet 5 — Templates Blade (D6)

**AC5.1** — **`resources/views/ipxe/menu/admin.blade.php` créé**

**Given** le fichier `resources/views/ipxe/menu/admin.blade.php`,
**When** il est rendu par `renderAdminMenu()`,
**Then** :
- ~30 lignes ASCII strict.
- Commence par `{!! $shebang !!}` (iso 3.1 DO-13).
- Contient `console --x {{ $resolutionX }} --y {{ $resolutionY }} --picture {{ $resolutionPng }}`.
- Contient `:menu`, `menu Preboot eXecution Environment pour {{ $workstationName }} ({{ $ip }})`.
- `set menu-default exit` (parité legacy `admin.php:79` — par défaut le poste exit s'il ne touche rien).
- `set menu-timeout {{ $menuTimeoutMs }}` (= 30000ms iso `admin.php:14`).
- Conditionnel `@if($isKnown)` ... `@endif` autour des items maintenance.
- Item exit + retour toujours présents (connu et inconnu).
- Sections `:menu`, `:maintenance` (chain), `:retour` (chain vers /ipxe/boot), `:shell` (iPXE shell built-in), `:exit` (+ `{!! $bootDiskFallback !!}`).
- Si `$isKnown === false` : echo neutre `echo Poste non enregistre, fonctions de maintenance indisponibles.\necho Story 3.3 enrollment a venir.\nsleep 3`.

**AC5.2** — **`resources/views/ipxe/menu/maintenance.blade.php` créé**

**Given** le fichier,
**When** rendu par `renderMaintenanceMenu()`,
**Then** :
- ~25 lignes ASCII strict.
- Iso `admin.blade.php` structure (console, menu, items, sections).
- Items : `:rescuecd` (chain action/rescuecd), `:winpe` (chain action/winpe), `:factory_reset` (chain action/factory_reset), `:shell`, `:retour` (chain admin), `:exit` (boot_disk).
- `set menu-timeout 10000` (= config maintenance.menu_timeout_ms).
- Item `:factory_reset` doit avoir un title clair : `item --key f factory_reset (f) ATTENTION - Restauration usine (efface le disque)`.

**AC5.3** — **`resources/views/ipxe/actions/rescuecd.blade.php` créé**

**Given** le fichier,
**When** rendu par `IpxeActionResolver::resolve(Rescuecd, ...)`,
**Then** :
- ~12 lignes.
- Commence par `{!! $shebang !!}`.
- `kernel {{ $osUrl }}/sysresccd/boot/x86_64/vmlinuz initrd=initram.igz ip=dhcp copytoram nofirewall archisobasedir=sysresccd archiso_http_srv={{ $osUrl }}/ checksum rootpass={{ $se4installPasswd }} setkmap=fr ar_source={{ $autorunUrl }} ar_attempts=5 ar_suffixes=no ar_nodel`
- `$autorunUrl = "{{ $scriptUrl }}/sysrescuecd/autorun.php?mac={{ $mac }}&uuid={{ $uuid }}"` — interpolé via `IpxeActionResolver` AVANT rendu (pas concatené dans le template — pour permettre le test).
- 3 `initrd --name` : `intel_ucode.img`, `amd_ucode.img`, `initram.igz`.
- Finir par `boot\n`.

**AC5.4** — **`resources/views/ipxe/actions/winpe.blade.php` créé**

**Given** le fichier,
**When** rendu par `IpxeActionResolver::resolve(Winpe, ...)`,
**Then** :
- ~25 lignes — port iso strict de `actions/winpe.php` (32 lignes legacy).
- `kernel Win10/wimboot` (chemin relatif, parité legacy).
- 2 blocs `params` (un avant `initrd winpeshl.ini`, un avant `initrd diskpart.txt`).
- 4 `initrd --name` : `winpeshl.ini`, `install.bat` (depuis `Win10/repair.bat.php##params`), `diskpart.txt`, `BCD` (depuis `{{ $version }}/boot/bcd`), `boot.sdi`, `boot.wim`. Note : ces fichiers sont **servis par Apache via le catchall** — 3.2 ne les réécrit pas.
- `iseq ${platform} efi && param bios uefi || param bios legacy` (× 2 — parité legacy).
- Finir par `boot\n`.

**AC5.5** — **`resources/views/ipxe/actions/factory_reset.blade.php` créé**

**Given** le fichier,
**When** rendu par `IpxeActionResolver::resolve(FactoryReset, ...)`,
**Then** :
- ~10 lignes — port iso `actions/clz_rest_sda2_sur_sda1.php`.
- `kernel {{ $osUrl }}/clonezilla/vmlinuz initrd=initram.igz boot=live config noswap nolocales edd=on nomodeset ocs_prerun="mount -t auto /dev/sda2 /home/partimag/" ocs_live_run="ocs-sr -e1 auto -e2 -r -j2 -p reboot restoreparts savesda1 sda1" ocs_live_extra_param="" keyboard-layouts="fr" ocs_live_batch="no" locales="fr_FR.UTF-8" vga=788 nosplash noprompt fetch={{ $osUrl }}/clonezilla/filesystem.squashfs`.
- `initrd --name initram.igz {{ $osUrl }}/clonezilla/initrd.img`.
- Finir par `boot\n`.

**AC5.6** — **Modification `resources/views/ipxe/menu/handshake.blade.php` (D5)**

**Given** le template 3.1,
**When** étendu pour D5,
**Then** :
- Remplace la ligne `chain --replace --autofree boot##params` par `chain --replace --autofree {{ $chainTarget ?? 'boot' }}##params`.
- Default `$chainTarget = 'boot'` injecté par `renderHandshake()` quand le paramètre est null.
- **Non-régression** : `renderHandshake()` (sans param) rend exactement le même body qu'en 3.1.

**AC5.7** — **Modification `resources/views/ipxe/menu/known.blade.php` (cible login)**

**Given** le template 3.1,
**When** modifié 3.2,
**Then** :
- Section `:login` : ligne `chain --replace --autofree {{ $serverBaseUrl }}/ipxe/admin##params` (3.2 natif) **au lieu de** `chain --replace --autofree {{ $serverBaseUrl }}/ipxe/admin.php##params` (3.1 legacy).
- Tout le reste du template **inchangé**.

### Volet 6 — Routes web.php + ordre + non-régression catchall

**AC6.1** — **3 nouvelles routes déclarées AVANT catchall** (D2)

**Given** le fichier `routes/web.php`,
**When** le dev ajoute le bloc 3.2 dans le bloc existant 3.1 (après les routes ipxe.boot, avant catchall),
**Then** :
- Les 3 routes (`ipxe.admin`, `ipxe.maintenance`, `ipxe.action`) sont **toutes** déclarées avant `Route::match(...'{path}'...)->where('path', '.*')`.
- Le commentaire `⚠⚠⚠` (lignes 627-635 routes/web.php) reste intact.
- Le commentaire de bloc 3.1 (`Story 3.1 — iPXE Service Core ...`) est complété par un commentaire `Story 3.2 — Menu Admin + Maintenance + Action ...`.
- Toutes les routes ont `auth.v1.lan-only` + `throttle:600,1` + `withoutMiddleware(['web'])`.
- La route `/ipxe/action/{action}` a `->where('action', '[a-z_]+')` (filtre regex route-level).

**And** test archi `tests/Architecture/IpxeNamespaceTest::ipxe_3_2_routes_are_declared_before_catchall` :
- Lit `routes/web.php` en texte.
- Vérifie que les 3 déclarations `Route::match(... '/ipxe/admin' ...)`, `/ipxe/maintenance`, `/ipxe/action/{action}` apparaissent **toutes** avant `Route::match(... '{path}' ...)->where('path', '.*')`.
- Vérifie que chaque route a `auth.v1.lan-only` dans sa chain middleware (iso 3.1 fix #6).
- Vérifie que `IpxeAdminController`, `IpxeMaintenanceController`, `IpxeActionController` sont référencés.

**AC6.2** — **Non-régression catchall sur les routes 3.3-3.7 (legacy non encore réécrits)**

**Given** les routes legacy `/ipxe/installation-linux.php`, `/ipxe/installation-windows.php`, `/ipxe/enregistrement.php`, `/ipxe/clonage.php`, `/ipxe/clonezilla_menu.php`, `/ipxe/Win10/wimboot`, `/ipxe/sysresccd/*`, `/ipxe/clonezilla/*`,
**When** un appelant LAN les sollicite,
**Then** :
- Elles continuent d'être servies par `LegacyCatchallController` → proxy legacy.
- **Aucune** régression sur le contenu retourné.
- `/ipxe/admin.php` (route legacy avec `.php`) continue d'être servie par le catchall — c'est **la route sans `.php`** (`/ipxe/admin`) qui est interceptée par 3.2 natif. Discrimination par extension. **Anti-pattern** : ne PAS rediriger `/ipxe/admin.php` vers `/ipxe/admin` natif — le catchall continue de le servir (le legacy reste accessible pour debug Phase 2).

**And** test feature `tests/Feature/Ipxe/IpxeLegacyRoutingNonRegressionTest.php` (existant 3.1) **étendu** ≥4 tests :
- `it_serves_ipxe_admin_natively_not_via_catchall` (route native `/ipxe/admin`)
- `it_still_serves_ipxe_admin_php_via_catchall` (route legacy avec `.php`)
- `it_still_serves_ipxe_installation_linux_via_catchall` (existant 3.1 — vert)
- `it_still_serves_ipxe_clonage_via_catchall` (nouveau 3.2)

### Volet 7 — Logging structuré (D8)

**AC7.1** — **5 nouveaux events loggés channel `ipxe`** (D8)

**Given** `Log::channel('ipxe')`,
**When** les endpoints 3.2 sont sollicités,
**Then** les events suivants sont émis avec context structuré :
- `ipxe.admin.menu_rendered` (info) — context : ip, mac_prefix (6), uuid_prefix (8), workstation_id (nullable), workstation_name_prefix (6), menu_variant (`known|unknown`).
- `ipxe.maintenance.menu_rendered` (info) — context : idem admin.
- `ipxe.action.dispatched` (info) — context : ip, mac_prefix, uuid_prefix, workstation_id (nullable), action (valeur stricte enum).
- `ipxe.action.unknown_action` (warning) — context : ip, mac_prefix, uuid_prefix, action_requested (tronqué 32 chars + sanitize ASCII).
- `ipxe.action.render_error` (error) — context : exception_class, message (tronqué 200), kind (`action_resolver`), action, ip, mac_prefix, uuid_prefix.

**And** test unit `tests/Unit/Ipxe/Services/IpxeServiceLoggingTest.php` (existant 3.1) **étendu** ≥5 tests :
- `it_logs_admin_menu_rendered_with_correct_prefixes`
- `it_logs_maintenance_menu_rendered`
- `it_logs_action_dispatched_with_action_value`
- `it_logs_unknown_action_warning_with_sanitized_action`
- `it_does_not_leak_full_mac_uuid_product_in_admin_event`

### Volet 8 — Tests + non-régression

**AC8.1** — **Tests unit ≥15 cumulés nouveaux**

**Given** les nouvelles classes 3.2,
**When** `php artisan test --filter='Ipxe'` s'exécute,
**Then** elle couvre :
- `tests/Unit/Ipxe/Enums/IpxeAdminActionTest.php` — ≥4 tests (AC1.1)
- `tests/Unit/Ipxe/Services/IpxeActionResolverTest.php` — ≥6 tests (AC1.2)
- `tests/Unit/Ipxe/Services/IpxeServiceAdminTest.php` — ≥6 tests (AC3.1)
- `tests/Unit/Ipxe/Services/IpxeServiceMaintenanceTest.php` — ≥5 tests (AC3.2)
- `tests/Unit/Ipxe/Services/IpxeServiceActionTest.php` — ≥7 tests (AC3.3)
- `tests/Unit/Ipxe/Services/IpxeMenuRendererTest.php` (existant 3.1) — **étendu** ≥7 tests nouveaux (AC4.1-4.4).
- `tests/Unit/Ipxe/Services/IpxeServiceLoggingTest.php` (existant 3.1) — **étendu** ≥5 tests nouveaux (AC7.1).

**AC8.2** — **Tests feature ≥10 nouveaux**

**Given** les controllers + routes + middleware chain,
**When** `php artisan test tests/Feature/Ipxe/` s'exécute,
**Then** elle couvre :
- `tests/Feature/Ipxe/IpxeAdminEndpointTest.php` — ≥4 tests (handshake, known menu, unknown menu, alias chain to /ipxe/boot)
- `tests/Feature/Ipxe/IpxeMaintenanceEndpointTest.php` — ≥3 tests (handshake, menu items, retour chain to admin)
- `tests/Feature/Ipxe/IpxeActionEndpointTest.php` — ≥6 tests (handshake, rescuecd dispatch, winpe dispatch, factory_reset dispatch, unknown action → 404, action with invalid format → route 404).
- `tests/Feature/Ipxe/IpxeLegacyRoutingNonRegressionTest.php` (existant 3.1) — **étendu** ≥2 tests (admin natif, admin.php legacy via catchall).

**AC8.3** — **Tests architecture étendus**

**Given** le namespace `App\Ipxe`,
**When** `tests/Architecture/IpxeNamespaceTest.php` s'exécute,
**Then** ≥3 tests nouveaux :
- `ipxe_3_2_routes_are_declared_before_catchall` (AC6.1)
- `it_lists_all_ipxe_3_2_controllers_under_correct_namespace`
- `ipxe_admin_action_enum_has_exactly_three_cases_in_story_3_2` (D9 — sera relaxé par 3.4/3.5/3.7)
- (Étendus) `it_does_not_import_ldap_record_in_ipxe_namespace`, `it_does_not_include_legacy_files_in_ipxe_namespace`, `it_does_not_use_shell_execution_in_ipxe_namespace` continuent de scanner les nouveaux fichiers 3.2.

**AC8.4** — **Pas de régression sur 75 tests Ipxe 3.1 + Phase 1 + 16.10 + 16.11 + 16.12 + Epic 4**

**Given** la baseline tests verte 3.1 (75/75),
**When** le dev exécute la suite ciblée Ipxe + non-régression Auth V1 + Architecture,
**Then** **100% verts**.

**Items différés VM** (T8.5 iso 3.1) : `./scripts/run-tests.sh` complet à exécuter par Henri post-merge VM up.

### Volet 9 — Config + provider + channel log (D10)

**AC9.1** — **Extension `config/ipxe.php`** (D10)

**Given** le fichier,
**When** complété 3.2,
**Then** :
- 3 nouvelles sections : `admin`, `maintenance`, `actions` (cf. D10 code complet).
- `config('ipxe.admin.menu_timeout_ms')` = 30000.
- `config('ipxe.maintenance.menu_timeout_ms')` = 10000.
- `config('ipxe.maintenance.background_png')` = `'png/sysrescuecd.png'`.
- `config('ipxe.actions.os_url')` = null par défaut (fallback dynamique).
- `config('ipxe.actions.script_url')` = null par défaut.
- `config('ipxe.actions.se4install_passwd_config_key')` = `'sambaedu.se4install_passwd'`.

**And** test unit `IpxeConfigTest` (existant 3.1) **étendu** ≥6 nouvelles assertions.

**AC9.2** — **`IpxeServiceProvider` étendu** (DI bindings 3.2)

**Given** `App\Providers\IpxeServiceProvider` (3.1 — DO-1),
**When** étendu 3.2,
**Then** :
- Singletons existants 3.1 préservés : `IpxeService`, `WorkstationLocator`, `IpxeMenuRenderer`.
- Nouveau singleton : `IpxeActionResolver`.
- Nouveau singleton : `IpxeAdminMenuBuilder` (optionnel — cf. note ci-dessous).
- **Note** : `IpxeAdminMenuBuilder` peut être supprimé du scope si le dev considère que la construction du payload variables est trop simple pour mériter un service séparé (auquel cas, intégrer dans `IpxeMenuRenderer::renderAdminMenu()` directement). **Décision dev autorisée** : justifier dans Dev Agent Record.

### Volet 10 — Runbook QA + sprint-status (append-only iso 3.1)

**AC10.1** — **Extension `docs/qa/domains/ipxe.md`**

**Given** le fichier (créé 3.1 — 9 scénarios stables 3.1-1 à 3.1-9),
**When** étendu 3.2,
**Then** :
- Append `## Story 3.2 — Boot et Menu Admin iPXE` après la section 3.1 (numérotation stable préservée — pas de renumérotation).
- ≥10 scénarios stables `3.2-1` à `3.2-N`.

**Et** les scénarios ≥10 couvrent au minimum :
- **Scénario 3.2-1** — Handshake `/ipxe/admin` (sans params) : `curl http://192.168.122.50/ipxe/admin` → 200 + body `#!ipxe\nparams\n...\nchain --replace --autofree admin##params\n`.
- **Scénario 3.2-2** — Menu admin pour poste connu (seed Workstation via tinker) : `curl -X POST http://192.168.122.50/ipxe/admin -d 'mac=...&uuid=...'` → 200 + body contient `item --key m maintenance` et `item --key x exit`.
- **Scénario 3.2-3** — Menu admin pour poste inconnu : `curl -X POST .../ipxe/admin -d 'mac=00:00:00:00:00:00&uuid=00000000-...'` → 200 + body contient `echo Poste non enregistre` + items exit + retour uniquement.
- **Scénario 3.2-4** — Handshake `/ipxe/maintenance` : iso 3.2-1 avec target `maintenance`.
- **Scénario 3.2-5** — Menu maintenance pour poste connu : items rescuecd + winpe + factory_reset + shell + retour + exit.
- **Scénario 3.2-6** — Action `rescuecd` : `curl -X POST .../ipxe/action/rescuecd -d 'mac=...&uuid=...'` → body kernel sysresccd + initrd + boot.
- **Scénario 3.2-7** — Action `winpe` : body kernel wimboot + initrds Win10 + boot.
- **Scénario 3.2-8** — Action `factory_reset` : body kernel clonezilla + ocs_live_run restoreparts + boot.
- **Scénario 3.2-9** — Action inconnue (`/ipxe/action/install_macos`) → 404 + log warning `ipxe.action.unknown_action`.
- **Scénario 3.2-10** — Non-régression `/ipxe/admin.php` continue d'être servie via catchall legacy (vérif `legacy_catchall_logs`).
- **Scénario 3.2-11** — Bascule menu known 3.1 → admin natif 3.2 : `curl .../ipxe/boot` (3.1) → body contient `chain --replace --autofree {server}/ipxe/admin##params` (sans `.php`).
- **(Optionnel)** Scénario 3.2-12 — Smoke poste réel : un poste de test PXE boot → menu known → choisit (1) login → menu admin natif → choisit (m) maintenance → menu maintenance → choisit (c) rescuecd → boot sysresccd réel. **Critique** : factory_reset à NE PAS exécuter sur un poste de prod.

**AC10.2** — **Mise à jour `sprint-status.yaml`**

**Given** le fichier,
**When** le SM crée cette story,
**Then** :
- `3-2-boot-et-menu-admin-ipxe: backlog` → `3-2-boot-et-menu-admin-ipxe: ready-for-dev`.
- Le commentaire `# last_updated:` ajoute un paragraphe daté `2026-05-19` qui synthétise : modèle SM utilisé (claude-opus-4-7), scope, nombre AC, modèle dev recommandé.
- **NE PAS** changer `epic-3: in-progress` (déjà bon — posé par 3.1).

---

## Tasks / Subtasks

### Phase T0 — Pré-flight + validations contexte

- [x] **T0.1** Vérifier statut Story 3.1 : `review` accepté ou `done` confirmé. Si toujours `review`, signaler à Henri en attendant validation. **Le dev 3.2 dépend de 3.1 done**. Ne pas démarrer T1+ tant que 3.1 n'est pas validée — risque de devoir re-merger les modifs `known.blade.php` deux fois.
- [x] **T0.2** Statut Epic 1 done + Epic 4 done + 16.10/16.11 review confirmés par sprint-status (iso 3.1).
- [x] **T0.3** Confirmer la présence du namespace `App\Ipxe` complet 3.1 (10 classes + 3 templates + provider + config + channel log).
- [x] **T0.4** Lecture obligatoire legacy : `sambaedu/ipxe/admin.php` (167 L — focus lignes 75-128), `maintenance.php` (63 L — intégralité), `action.php` (51 L — focus lignes 28-41), `actions/rescuecd.php` (10 L), `actions/winpe.php` (32 L), `actions/clz_rest_sda2_sur_sda1.php` (10 L). Documenter dans Dev Agent Record toutes les différences notables 3.2 vs legacy.
- [x] **T0.5** Confirmer que `IpxeServiceProvider` (3.1 — `App\Providers\IpxeServiceProvider`) est bien enregistré dans `config/app.php` providers array — pas d'ajout 3.2, juste extension du provider existant.
- [x] **T0.6** Re-audit `MachineBootLog::$fillable` + schema `machine_boot_logs.action` (varchar(20) sans CHECK confirmé par 3.1 T0.6 — re-vérifier que rien n'a changé entre 3.1 et 3.2 si la branche a évolué). Les 3 nouvelles valeurs `ipxe_admin` (10), `ipxe_maintenance` (16), `ipxe_action` (11) doivent passer. Re-vérifier `initiated_by` varchar(50) accepte `ipxe:factory_reset` (19 chars). Si bloqué → escalation Henri.
- [x] **T0.7** Lint baseline 0 erreur sur tous fichiers 3.1 existants + nouveaux 3.2.
- [x] **T0.8** Worktree git `ipxe` — vérifier statut `git status` propre avant démarrage. Pas de sync VM.

### Phase T1 — Enum + Resolver (D9, D10, AC1.1, AC1.2, AC9.1)

- [x] **T1.1** Créer `app/Ipxe/Enums/IpxeAdminAction.php` (D9 — 3 cases stricts).
- [x] **T1.2** Créer `tests/Unit/Ipxe/Enums/IpxeAdminActionTest.php` ≥4 tests.
- [x] **T1.3** Étendre `config/ipxe.php` avec sections `admin`, `maintenance`, `actions` (D10).
- [x] **T1.4** Étendre `tests/Unit/Ipxe/IpxeConfigTest.php` ≥6 nouvelles assertions.
- [x] **T1.5** Créer `app/Ipxe/Services/IpxeActionResolver.php` (AC1.2).
- [x] **T1.6** Créer `tests/Unit/Ipxe/Services/IpxeActionResolverTest.php` ≥6 tests.
- [x] **T1.7** Enregistrer `IpxeActionResolver` en singleton dans `IpxeServiceProvider` (AC9.2).

### Phase T2 — Templates Blade nouveaux + modification 3.1 (AC5.1-5.7, D5, D6)

- [x] **T2.1** Modifier `resources/views/ipxe/menu/handshake.blade.php` — paramétrer `$chainTarget` (D5, AC5.6).
- [x] **T2.2** Modifier `resources/views/ipxe/menu/known.blade.php` — chain `:login` vers `/ipxe/admin` natif (AC5.7).
- [x] **T2.3** Créer `resources/views/ipxe/menu/admin.blade.php` (~30 lignes, AC5.1).
- [x] **T2.4** Créer `resources/views/ipxe/menu/maintenance.blade.php` (~25 lignes, AC5.2).
- [x] **T2.5** Créer `resources/views/ipxe/actions/rescuecd.blade.php` (~12 lignes, AC5.3).
- [x] **T2.6** Créer `resources/views/ipxe/actions/winpe.blade.php` (~25 lignes, AC5.4).
- [x] **T2.7** Créer `resources/views/ipxe/actions/factory_reset.blade.php` (~10 lignes, AC5.5).

### Phase T3 — `IpxeMenuRenderer` extension (D5, D6, AC4.1-4.4)

- [x] **T3.1** Étendre `IpxeMenuRenderer::renderHandshake(?string $chainTarget = null)` (D5, AC4.1).
- [x] **T3.2** Ajouter `IpxeMenuRenderer::renderAdminMenu(?Workstation $ws, string $ip, string $serverBaseUrl)` (AC4.2).
- [x] **T3.3** Ajouter `IpxeMenuRenderer::renderMaintenanceMenu(?Workstation $ws, string $ip, string $serverBaseUrl)` (AC4.3).
- [x] **T3.4** Étendre `IpxeMenuRendererTest` ≥7 nouveaux tests (AC4.1-4.4 + non-régression 3.1).

### Phase T4 — `IpxeService` extension (AC3.1, AC3.2, AC3.3, D8)

- [x] **T4.1** Étendre `IpxeService::handleAdmin(Request)` (AC3.1) — handshake/known/unknown paths + safeRender + log + MachineBootLog.
- [x] **T4.2** Étendre `IpxeService::handleMaintenance(Request)` (AC3.2) — handshake/menu paths + safeRender + log + MachineBootLog.
- [x] **T4.3** Étendre `IpxeService::handleAction(Request, string $action)` (AC3.3) — whitelist enum + handshake/dispatch paths + safeRender + log + MachineBootLog.
- [x] **T4.4** Créer `IpxeServiceAdminTest` ≥6 tests (AC3.1).
- [x] **T4.5** Créer `IpxeServiceMaintenanceTest` ≥5 tests (AC3.2).
- [x] **T4.6** Créer `IpxeServiceActionTest` ≥7 tests (AC3.3).
- [x] **T4.7** Étendre `IpxeServiceLoggingTest` ≥5 nouveaux tests (AC7.1, D8).

### Phase T5 — Controllers + FormRequests (D2, AC2.1, AC2.2)

- [x] **T5.1** Créer `IpxeAdminRequest`, `IpxeMaintenanceRequest`, `IpxeActionRequest` (AC2.2 — rules permissives iso 3.1).
- [x] **T5.2** Créer `IpxeAdminController::handle()`, `IpxeMaintenanceController::handle()`, `IpxeActionController::handle()` (AC2.1 — controllers fins ≤20 lignes).
- [x] **T5.3** Tests unit controllers (smoke 1 test chacun).

### Phase T6 — Routes + non-régression catchall (AC6.1, AC6.2)

- [x] **T6.1** Ajouter 3 blocs Route dans `routes/web.php` (D2 — bloc 3.2 dans le bloc existant 3.1, avant catchall, commentaire ⚠⚠⚠ préservé).
- [x] **T6.2** Étendre `tests/Architecture/IpxeNamespaceTest.php` avec `ipxe_3_2_routes_are_declared_before_catchall` + asserts middleware (AC6.1).
- [x] **T6.3** Créer `tests/Feature/Ipxe/IpxeAdminEndpointTest.php` ≥4 tests (AC8.2).
- [x] **T6.4** Créer `tests/Feature/Ipxe/IpxeMaintenanceEndpointTest.php` ≥3 tests.
- [x] **T6.5** Créer `tests/Feature/Ipxe/IpxeActionEndpointTest.php` ≥6 tests.
- [x] **T6.6** Étendre `tests/Feature/Ipxe/IpxeLegacyRoutingNonRegressionTest.php` ≥2 tests (AC6.2).

### Phase T7 — Non-régression + lint + audit final

- [x] **T7.1** Lint `php -l` 0 erreur sur tous les fichiers créés/modifiés 3.2 (~25 fichiers).
- [ ] **T7.2** Suite Ipxe complète verte : ~105 tests (75 3.1 + ~30 nouveaux 3.2). Re-vérifier non-régression `tests/Architecture/` global + `tests/Feature/Auth/V1/`. **DIFFÉRÉ VM Henri** : `vendor/` absent localement (static delivery iso 3.1 — pas de phpunit local). Voir section « Smoke test à exécuter quand VM up » + sprint-status.
- [x] **T7.3** Vérifier qu'aucun fichier `sambaedu/ipxe/*.php` ni `legacy/modules/ipxe/*.php` n'a été modifié (`git status` confirmé vide pour ces paths).

### Phase T8 — Runbook QA + sprint-status + completion notes (AC10.1, AC10.2)

- [x] **T8.1** Étendre `docs/qa/domains/ipxe.md` avec section `## Story 3.2` + ≥10 scénarios stables `3.2-1` à `3.2-N` (AC10.1). Préserver numérotation 3.1. *(13 scénarios stables 3.2-1 à 3.2-13 livrés, Sections 6-10)*
- [x] **T8.2** Mettre à jour `sprint-status.yaml` (AC10.2) — `3-2-boot-et-menu-admin-ipxe: ready-for-dev → review` ; commentaire `# last_updated:` enrichi.
- [x] **T8.3** Story status → `review` (par le dev en fin de cycle), Dev Agent Record + File List + Change Log remplis.
- [ ] **T8.4** *Différé Henri post-merge VM up* : `./scripts/run-tests.sh` complet + scénarios 3.2-1 à 3.2-13 manuels sur la VM + smoke poste réel optionnel 3.2-13.

### Phase T9 — Corrections post-review (Henri arbitrage Q1-Q4 + adversarial findings)

- [x] **T9.1** **Correction #1** (🔴 pertinence 3) — Bloc `params` ajouté en tête de `admin.blade.php` et `maintenance.blade.php` (iso-legacy `admin.php:69-74` + `maintenance.php:19-22`). Variables `$mac`/`$uuid` déjà passées par `IpxeMenuRenderer::renderAdminMenu()` et `renderMaintenanceMenu()` (pas besoin d'étendre signature). 2 nouveaux tests `IpxeMenuRendererTest::it_renders_{admin,maintenance}_menu_with_params_block_for_chain_namespace`.
- [x] **T9.2** **Correction #2 / Q2 Henri** (🔴 pertinence 2) — Whitelist enum stricte `Win10|Win11` pour `$version` winpe : `config('ipxe.actions.winpe.allowed_versions')` + `Rule::in()` côté `IpxeActionRequest` + revalidation côté `IpxeActionResolver` (défense en profondeur, fallback `DEFAULT_WIN_VERSION` sans exception si FormRequest court-circuitée).
- [x] **T9.3** **Correction #B2** — 2 nouveaux tests `IpxeActionResolverTest` : `it_rejects_invalid_version_and_falls_back_to_default` (POC injection `version="Win11\nkernel http://evil/x"` → asserte fallback Win11 pur) + `it_accepts_whitelisted_version` (POST `version=Win10` → asserte propagation pure).
- [x] **T9.4** **Correction #3 / Q1 Henri** — Log warning dédié `ipxe.action.factory_reset_dispatched` (channel `ipxe`, niveau `warning`) émis dans `IpxeService::handleAction()` AVANT `persistEndpointLog()` quand `$adminAction === IpxeAdminAction::FactoryReset`. Préfixes PII tronqués 6/8 chars iso AC7.3. 2 nouveaux tests `IpxeServiceLoggingTest`.
- [x] **T9.5** **Correction #6** — Assertions headers `Cache-Control: no-store` + `X-Robots-Tag: noindex` complétées dans `IpxeServiceActionTest` (nouveau test `it_returns_secure_headers_in_all_paths`) + `IpxeServiceMaintenanceTest` (test existant étendu) + 3 tests Feature happy-path (`IpxeAdminEndpointTest`, `IpxeMaintenanceEndpointTest`, `IpxeActionEndpointTest`).
- [x] **T9.6** **Correction #B3 / Q4 Henri** — Remplacement des 6 strings hardcodées (`'handshake'`, `'unknown'`, `'known'`, `'admin_handshake'`, `'admin_menu'`, `'maintenance_handshake'`, `'maintenance_menu'`, `'action_handshake'`) par cases `IpxeMenuKind::*` dans `IpxeService::safeRender()`. Signature `safeRender(..., IpxeMenuKind $kind)` (au lieu de `string $kind`) — `->value` utilisé dans le log `error`. Enum enrichi de 6 nouveaux cases (`Unknown`, `AdminHandshake`, `AdminMenu`, `MaintenanceHandshake`, `MaintenanceMenu`, `ActionHandshake`) — plus de dead code.
- [x] **T9.7** **Documentation #4** — Commentaire ASCII-safe ajouté au-dessus de `se4install_passwd_config_key` dans `config/ipxe.php` (parité legacy + warn ops si caractère espace/newline).
- [x] **T9.8** **Documentation #B4** — Break-change minor 3.2 documenté dans Dev Agent Record / DO-13 (cf. ci-dessous).
- [x] **T9.9** Lint `php -l` 0 erreur sur 13 fichiers touchés post-review. Tests phpunit/pest non-lançables localement (`vendor/` absent — pattern iso 3.1) — différés VM Henri.

**Items NON corrigés (acceptés en l'état)** :
- #4 (sanitize `se4installPasswd`) — décision = doc only (cf. T9.7).
- #5 (`IpxeAdminControllerSmokeTest` mal classé Unit/) — accepté en cohérence 3.1 (dette projet préexistante).
- #7 (`postJson` Accept JSON sur 422 mac oversize) — dette 3.1 héritée.
- #8 (duplication `handleAdmin`/`handleMaintenance` ~36 lignes) — réévaluer en 3.3 si une 3e méthode arrive.
- #B1 (MachineBootLog handshake) — décision Q3 Henri = iso-3.1 log file uniquement.

---

## File List prévisionnelle

### Fichiers créés (estimés ~22)

```
# Services nouveaux
app/Ipxe/Services/IpxeActionResolver.php

# Enum whitelist (D9)
app/Ipxe/Enums/IpxeAdminAction.php

# Controllers + FormRequests
app/Ipxe/Http/Controllers/IpxeAdminController.php
app/Ipxe/Http/Controllers/IpxeMaintenanceController.php
app/Ipxe/Http/Controllers/IpxeActionController.php
app/Ipxe/Http/Requests/IpxeAdminRequest.php
app/Ipxe/Http/Requests/IpxeMaintenanceRequest.php
app/Ipxe/Http/Requests/IpxeActionRequest.php

# Templates Blade nouveaux (5)
resources/views/ipxe/menu/admin.blade.php
resources/views/ipxe/menu/maintenance.blade.php
resources/views/ipxe/actions/rescuecd.blade.php
resources/views/ipxe/actions/winpe.blade.php
resources/views/ipxe/actions/factory_reset.blade.php

# Tests Unit
tests/Unit/Ipxe/Enums/IpxeAdminActionTest.php
tests/Unit/Ipxe/Services/IpxeActionResolverTest.php
tests/Unit/Ipxe/Services/IpxeServiceAdminTest.php
tests/Unit/Ipxe/Services/IpxeServiceMaintenanceTest.php
tests/Unit/Ipxe/Services/IpxeServiceActionTest.php

# Tests Feature
tests/Feature/Ipxe/IpxeAdminEndpointTest.php
tests/Feature/Ipxe/IpxeMaintenanceEndpointTest.php
tests/Feature/Ipxe/IpxeActionEndpointTest.php
```

### Fichiers modifiés (estimés ~10)

```
# Services existants 3.1 — extension
app/Ipxe/Services/IpxeService.php                       (+ handleAdmin, handleMaintenance, handleAction, resolveServerBaseUrl déjà existant)
app/Ipxe/Services/IpxeMenuRenderer.php                  (+ renderAdminMenu, renderMaintenanceMenu, renderHandshake étendu)
app/Ipxe/Enums/IpxeMenuKind.php                         (+ cases Admin, Maintenance, Action)

# Provider — DI
app/Providers/IpxeServiceProvider.php                   (+ bind IpxeActionResolver singleton)

# Config — sections nouvelles
config/ipxe.php                                         (+ sections admin, maintenance, actions)

# Templates Blade existants 3.1 — modification mineure
resources/views/ipxe/menu/handshake.blade.php           (+ paramètre $chainTarget)
resources/views/ipxe/menu/known.blade.php               (~ chain login vers /ipxe/admin natif)

# Routes
routes/web.php                                          (+ 3 blocs Route dans bloc 3.1 existant, AVANT catchall)

# Doc QA — append-only
docs/qa/domains/ipxe.md                                 (+ section Story 3.2 + ≥10 scénarios)

# Tests existants — extension
tests/Unit/Ipxe/IpxeConfigTest.php                      (+ assertions config admin/maintenance/actions)
tests/Unit/Ipxe/Services/IpxeMenuRendererTest.php       (+ 7 tests handshake target + renderAdmin/Maintenance)
tests/Unit/Ipxe/Services/IpxeServiceLoggingTest.php     (+ 5 tests events 3.2)
tests/Feature/Ipxe/IpxeLegacyRoutingNonRegressionTest.php (+ 2 tests catchall non-régression)
tests/Architecture/IpxeNamespaceTest.php                (+ ipxe_3_2_routes_are_declared_before_catchall + asserts middleware)

# Sprint-status
_bmad-output/implementation-artifacts/sprint-status.yaml (status update + last_updated)
```

### Fichiers NON modifiés (garde-fou)

```
app/Models/Workstation.php                              ← lecture seule (iso 3.1)
app/Models/WorkstationGroup.php                         ← lecture seule
app/Models/MachineBootLog.php                           ← insert via Eloquent (pas de modif schema)
app/Http/Controllers/LegacyCatchallController.php       ← intact
app/Auth/V1/**                                          ← intact (réutilisation alias auth.v1.lan-only)
sambaedu/ipxe/**                                        ← intact (legacy in-place, source de vérité)
legacy/modules/ipxe/**                                  ← intact
app/Ipxe/Services/WorkstationLocator.php                ← intact (3.1 — lecture pure)
app/Ipxe/Support/MacAddressNormalizer.php               ← intact
app/Ipxe/Support/UuidNormalizer.php                     ← intact
app/Ipxe/Http/Controllers/IpxeBootController.php        ← intact (3.1)
app/Ipxe/Http/Requests/IpxeBootRequest.php              ← intact
config/logging.php                                      ← intact (channel `ipxe` déjà créé 3.1, événements 3.2 utilisent le même channel)
```

---

## Test Strategy

### Couverture par niveau

| Niveau | Périmètre | Fichiers |
|---|---|---|
| **Unit** | Enum whitelist + métadata | `IpxeAdminActionTest` |
| **Unit** | Resolver action (rendu Blade injection vars) | `IpxeActionResolverTest` |
| **Unit** | Service orchestrateur — 3 nouveaux paths (admin, maintenance, action) + log | `IpxeServiceAdminTest`, `IpxeServiceMaintenanceTest`, `IpxeServiceActionTest`, `IpxeServiceLoggingTest` étendu |
| **Unit** | Renderer — handshake parametré + admin menu + maintenance menu | `IpxeMenuRendererTest` étendu |
| **Unit** | Config étendu admin/maintenance/actions | `IpxeConfigTest` étendu |
| **Feature** | Endpoints HTTP `/ipxe/admin`, `/ipxe/maintenance`, `/ipxe/action/{action}` | 3 fichiers `IpxeAdminEndpointTest`, `IpxeMaintenanceEndpointTest`, `IpxeActionEndpointTest` |
| **Feature** | Non-régression catchall sur routes non-3.2 (admin.php, clonage, install) | `IpxeLegacyRoutingNonRegressionTest` étendu |
| **Architecture** | Ordre routes + whitelist enum strict 3 cases | `IpxeNamespaceTest` étendu |
| **QA manuelle (VM)** | 11 scénarios stables 3.2-1 à 3.2-11 + smoke poste réel (3.2-12 optionnel) | `docs/qa/domains/ipxe.md` § Story 3.2 |

### Tests qu'on ne fait **pas** dans cette story

- Tests d'exécution réelle du kernel sysrescuecd/winpe/clonezilla sur poste cible — couvert par QA manuelle 3.2-12 (action Henri).
- Tests de réparation Windows réelle (winpe → repair.bat) — déféré 3.5.
- Tests de restauration clonezilla réelle (factory_reset → ocs-sr) — déféré 3.7 + smoke poste de test.
- Tests login admin AD — déféré Phase 3.
- Tests d'enrollment depuis menu admin (set-name, set-byod, salle, parcs) — déféré 3.3.

---

## Anti-patterns à éviter (DISASTER PREVENTION)

### Architecture & scope

- ❌ **Ne PAS modifier le code legacy `sambaedu/ipxe/*.php` ni `legacy/modules/ipxe/*.php`** — restent intacts. Le catchall continue de servir les routes 3.3-3.7 hors scope.
- ❌ **Ne PAS étendre le scope** à l'enrollment (set-name, set-byod, salle, parcs, enleveparc) = 3.3.
- ❌ **Ne PAS étendre la whitelist `IpxeAdminAction`** au-delà des 3 cases en 3.2. Les ajouts (install_linux, install_windows, clonezilla_live, etc.) sont scope 3.4/3.5/3.7.
- ❌ **Ne PAS implémenter le login AD** dans cette story (= scope Phase 3 — `admin.php:27-55` legacy ne sera porté qu'avec une réécriture complète de `login_action()`/`have_right()` côté Laravel).
- ❌ **Ne PAS toucher au schema `workstations` ni `machine_boot_logs`** (lecture/insert via Eloquent uniquement, pas de migration).
- ❌ **Ne PAS créer de nouveau middleware** — `auth.v1.lan-only` (16.11) suffit (D3).
- ❌ **Ne PAS toucher au middleware `EnsureLanIp`** — réutilisation pure.
- ❌ **Ne PAS introduire de dépendance LdapRecord** dans `App\Ipxe\*` — PostgreSQL seule source de vérité (iso 3.1).
- ❌ **Ne PAS appeler `auth_action()`, `set_action()`, `get_action()`, `fetch_action()`, `search_machine()` legacy** — réécriture native pure (Workstation Eloquent + relations + `IpxeAdminAction` enum).
- ❌ **Ne PAS créer d'UI Livewire** en 3.2 — c'est une API HTTP pure (pattern iso 3.1).

### Routing & non-régression

- ❌ **Ne PAS placer les 3 routes 3.2 APRÈS le catchall** — le catchall capture toute `{path}` et rendrait les routes natives inaccessibles. Strictement AVANT (iso 3.1 D2).
- ❌ **Ne PAS modifier le commentaire `⚠⚠⚠`** dans `routes/web.php`.
- ❌ **Ne PAS modifier `LegacyCatchallController`** — il continue de servir les routes legacy non-3.2.
- ❌ **Ne PAS ajouter un alias `/ipxe/admin.php` natif** — le catchall continue de servir le legacy. Discrimination 3.2 = route sans `.php`.
- ❌ **Ne PAS faire un `Route::prefix('/ipxe')` global** — sur-généralisation qui casserait le catchall sur les routes 3.3-3.7.
- ❌ **Ne PAS supprimer la route 3.1 `/ipxe/boot`** — elle reste nécessaire (handshake initial → menu known → chain vers /ipxe/admin natif).

### Sécurité

- ❌ **Ne PAS exposer `$config['se4install_passwd']`** ailleurs que dans le rendu kernel cmdline rescuecd (un seul point d'injection — pas dans les logs, pas dans `MachineBootLog`).
- ❌ **Ne PAS logger MAC/UUID/product complets** — préfixes seulement (iso 3.1 AC7.3).
- ❌ **Ne PAS faire confiance à X-Forwarded-For** dans `EnsureLanIp` — déjà strictement `REMOTE_ADDR` 16.11.
- ❌ **Ne PAS désactiver `auth.v1.lan-only`** dans les tests Feature — utiliser `$this->withServerVariables(['REMOTE_ADDR' => '192.168.1.10'])`.
- ❌ **Ne PAS accepter une action hors whitelist** dans `IpxeActionController` — 404 obligatoire si `IpxeAdminAction::tryFrom() === null`.

### Test & couverture

- ❌ **Ne PAS désactiver les 75 tests Ipxe 3.1** — la suite doit rester 100% verte (non-régression critique).
- ❌ **Ne PAS désactiver les tests Phase 1 + 16.10/16.11/16.12 + Epic 4**.
- ❌ **Ne PAS commiter de fixtures de production** — utiliser `Workstation::create([...])` (DO-8 iso 3.1).
- ❌ **Ne PAS écrire de tests qui dépendent du legacy `sambaedu/`** — pas d'inclusion PHP, pas de mock du legacy. Les actions 3.2 sont strictement self-contained dans `App\Ipxe`.

### Process & infra

- ❌ **Ne PAS SSH manuellement vers la VM** depuis le worktree git `ipxe`. Static delivery iso 3.1.
- ❌ **Ne PAS exécuter les tests sur la VM** depuis ce worktree. Lint statique + PHPUnit local.
- ❌ **Ne PAS faire de PR / commit depuis le dev-agent** — c'est le job de l'orchestrateur main agent en fin de cycle.
- ❌ **Ne PAS créer de commit hors scope** (rappel 3.1 `50c6275` qui a touché `docs/qa/domains/auth.md` hors scope — éviter ce piège).
- ❌ **Ne PAS modifier `app/Http/Kernel.php`** — pattern Router aliasMiddleware via `IpxeServiceProvider::boot()` si nouveau middleware nécessaire (pas le cas en 3.2 — D3).

---

## Dépendances + ordre

### Amont (bloquantes — toutes done ou review acceptée)

| Story | Statut entrant attendu | Lien |
|---|---|---|
| **Epic 1** (Fondations) | ✅ done | AuthGuard + catchall + dashboard legacy |
| **Epic 4** (Machines/Groups) | ✅ done | `Workstation` (lecture seule) + `MachineBootLog` (insert via Eloquent) |
| **Story 16.10** (HTTPS+JWT) | ✅ review/done | `JwtErrorCodes` catalogue, alias middleware |
| **Story 16.11** (Auto-bootstrap migration) | ✅ review/done | Middleware `auth.v1.lan-only` + code `JwtErrorCodes::BOOTSTRAP_NOT_LAN` — **réutilisés tels quels** (D3) |
| **Story 3.1** (iPXE Service Core) | ⚠️ **`review` au moment du cadrage SM — DOIT être `done` au démarrage dev 3.2** (T0.1 bloquant) | Fondation totale du namespace `App\Ipxe` — services, renderer, controller boot, channel log, config, FormRequest, templates handshake/default/known, table MachineBootLog action='ipxe_boot' |

### Aval (3.2 débloque)

| Story | Lien |
|---|---|
| **3.3** Enrollment Machine — Parcs, Salles, Nommage | **Consomme** : (a) menu admin natif 3.2 → ajout d'un item `(n) set-name` qui chain vers `/ipxe/enregistrement` natif (route à créer 3.3) ; (b) pattern enum whitelist `IpxeAdminAction` à étendre. |
| **3.4** Installation Linux | **Consomme** : (a) enum `IpxeAdminAction` étendu (ajout `installation_linux` + `deb_*` + autres distros) ; (b) menu admin 3.2 → ajout item `(l) installation-linux` ; (c) `IpxeActionResolver` étendu pour 1 nouveau template par action ; (d) menu maintenance 3.2 inchangé. |
| **3.5** Installation Windows | Idem 3.4 — ajout `installation_windows`, `wimboot10`, `wimboot11`, `sysprep`, etc. + templates Win10/* (les fichiers statiques Win10 deviennent natifs progressivement — 3.5 décidera du périmètre). |
| **3.6** Gestion ISO Windows | Indépendant 3.2 — UI admin Livewire (upload ISO) + association profil. |
| **3.7** Clonage et Maintenance | **Consomme** : (a) enum `IpxeAdminAction` étendu (ajout `clonezilla_live`, `clonezilla_prevert`, `live32`, `live64`, `sav_locale32`, `sav_locale64`, `rest_locale32`, `rest_locale64`) ; (b) `factory_reset` 3.2 reste tel quel — 3.7 ajoute la **gestion CRUD des modèles de clonage** (table `clonage_models` ou équivalent) ; (c) **Fin Epic 3** : 3.7 retire toutes les routes `/ipxe/*` du catchall (renvoi 410 ou 404 contrôlé), supprimant `legacy/modules/ipxe/*`. |

---

## Risques + mitigations

| Risque | Sévérité | Mitigation 3.2 |
|---|---|---|
| **Story 3.1 reste en `review` au démarrage 3.2** — collision merge `known.blade.php` | 🟠 Élevée | T0.1 bloquant : ne pas démarrer T1+ tant que 3.1 n'est pas `done`. Si Henri retarde 3.1, le SM aligne le calendrier. Risque concret : commit `50c6275` (auth.md hors scope) bloque encore la validation Henri sur 3.1. |
| **Modification `known.blade.php` 3.1 → 3.2** casse les tests 3.1 existants | 🟠 Élevée | T3.4 + T6.6 : test `IpxeMenuRendererTest::it_renders_known_menu_with_login_chain_to_native_admin` (était `..._to_admin_php_legacy` en 3.1 — à mettre à jour). Smoke poste réel post-merge VM obligatoire. |
| Collision routes natives `/ipxe/admin`, `/ipxe/maintenance`, `/ipxe/action/{action}` vs catchall | 🟠 Élevée | T6.1 + T6.2 — ordre strict dans `routes/web.php` (3.2 bloc dans le 3.1 existant, AVANT catchall). Test archi obligatoire. |
| Whitelist `IpxeAdminAction` mal interprétée — un dev futur ajoute des cases sans documenter | 🟡 Moyenne | D9 + AC8.3 test archi `ipxe_admin_action_enum_has_exactly_three_cases_in_story_3_2` (sera relaxé par 3.4/3.5/3.7 — chaque story ouvrira son périmètre explicitement). |
| Template `winpe.blade.php` complexe mal porté (32 lignes legacy → ~25 Blade) | 🟡 Moyenne | T2.6 + T3.4 test unit qui compare le rendu avec un snapshot iso-legacy (10 marqueurs `assertStringContainsString` : `kernel Win10/wimboot`, `winpeshl.ini`, `param bios uefi`, `param bios legacy`, `Win10/repair.bat.php##params`, `boot.sdi`, `boot.wim`, etc.). Si trop fragile → adopter snapshot testing PHPUnit. |
| Variable `$os_url` / `$script_url` rendues null si config et request indisponibles (CLI test) | 🟡 Moyenne | T1.5 + T1.6 — fallback `'http://se4fs/ipxe'` codé en dur dans `IpxeActionResolver::resolveOsUrl()`. Test unit cas null. |
| `$config['se4install_passwd']` absent sur la VM → kernel cmdline rescuecd cassé | 🟢 Mineure | T0.4 lecture legacy `actions/rescuecd.php` + T1.5 fallback string vide si config non définie. Smoke poste réel détectera. Le poste reçoit le kernel avec `rootpass=` (vide), il bootera mais le mot de passe root sysrescue sera vide — acceptable Phase 2 + log warning. |
| `MachineBootLog::$fillable` rejette `'ipxe_maintenance'` (16 chars) ou `'ipxe_action'` | 🟡 Moyenne | T0.6 audit obligatoire (re-vérification post-3.1). Si bloqué → escalation Henri. |
| Test feature `/ipxe/action/foo` n'arrive pas au controller (route regex `[a-z_]+` rejette `:` ou autres chars) | 🟢 Mineure | T6.5 test feature qui asserte que `curl /ipxe/action/foo%20bar` reçoit 404 Laravel (route mismatch) — pas le 404 custom du controller. |
| Régression sur 75 tests Ipxe 3.1 | 🟠 Élevée | T7.2 obligatoire. Suite ciblée Ipxe + Architecture + Auth V1 doit rester 100% verte (~165 tests cumulés 3.1+3.2). |
| Whitelist trop restrictive — un dev 3.4/3.5/3.7 oublie d'élargir l'enum et l'action est rejetée en prod | 🟢 Mineure | Le test archi `ipxe_admin_action_enum_has_exactly_three_cases_in_story_3_2` doit être **mis à jour par 3.4/3.5/3.7** (renommé `..._has_exactly_N_cases_in_story_3_X`). Commentaire `// Story 3.X — élargir si nouvelle action` dans le test. |
| `IpxeService::handleAdmin/Maintenance/Action` deviennent trop gros (god class) | 🟡 Moyenne | T4.1-T4.3 — chaque méthode reste autonome. Si > 80 lignes total → splitter en `IpxeAdminService`, `IpxeMaintenanceService`, `IpxeActionService` (décision dev — documenter dans Dev Agent Record). |

---

## Project Structure Notes

### Alignement avec la structure projet

- **Namespace** : extension `App\Ipxe\…` posé par 3.1. Pas de nouveau sous-namespace (sur-fragmentation).
- **Tests** : sous-arborescence `tests/Unit/Ipxe/Enums/`, `tests/Unit/Ipxe/Http/Controllers/`, `tests/Feature/Ipxe/` — iso 3.1.
- **Templates Blade** : sous-dossier `resources/views/ipxe/actions/` (nouveau, parallèle à `resources/views/ipxe/menu/` 3.1). Convention : `menu/` = menus interactifs avec items, `actions/` = scripts kernel/initrd à exécuter directement.
- **Pages cibles** : *hors-scope cette story* — pas d'UI Livewire (API HTTP pure iso 3.1).
- **Convention CLAUDE.md** : pas directement applicable (pas de page web admin Livewire, pas de modale, pas de toast).

### Conflits / variances détectés

| Élément | Architecture officielle | Décision 3.2 | Justification |
|---|---|---|---|
| Login admin AD | non décidé Phase 2 | **Déféré Phase 3** | Le firmware iPXE n'a pas de notion de credentials persistants ; le LAN-only suffit Phase 2. Histoire 3.2-spécifique : le legacy fait login + `have_right(SE_COMPUTER_INSTALL)` mais c'est dispensable Phase 2 (parité D8 de 3.1). |
| Whitelist actions | non décidée | Enum strict `IpxeAdminAction` 3 cases — élargi par stories 3.4/3.5/3.7 | D9 — sécurité critique, évite l'exécution de scripts arbitraires. |
| Format réponse | défini 3.1 (`text/plain`) | Identique 3.2 | Iso 3.1 D10. |
| Auth | défini 3.1 (`auth.v1.lan-only`) | Identique 3.2 | Iso 3.1 D3/D8. |
| Stockage logs | défini 3.1 (`MachineBootLog`) | Identique 3.2 — 3 nouvelles valeurs `action` | D11 — éviter multiplication des tables. |

### Cohabitation routes `/ipxe/*` après 3.2

| Endpoint | Story | Middleware | Status |
|---|---|---|---|
| `GET\|POST /ipxe/boot` | 3.1 | `auth.v1.lan-only` + `throttle:600,1` | done (3.1) |
| `GET /ipxe/boot.ipxe` | 3.1 | idem | done (3.1) alias |
| `GET\|POST /ipxe/admin` | **3.2 (cette story)** | `auth.v1.lan-only` + `throttle:600,1` | **NEW** |
| `GET\|POST /ipxe/maintenance` | **3.2 (cette story)** | idem | **NEW** |
| `GET\|POST /ipxe/action/{action}` | **3.2 (cette story)** | idem + `where('action', '[a-z_]+')` | **NEW** (3 actions whitelistées : rescuecd, winpe, factory_reset) |
| `/ipxe/admin.php` | Legacy | catchall + proxy legacy | Inchangé — accessible pour debug (ne pas rediriger vers natif) |
| `/ipxe/maintenance.php` | Legacy | catchall | Inchangé |
| `/ipxe/action.php` | Legacy | catchall | Inchangé |
| `/ipxe/installation-linux.php` | Legacy | catchall | Inchangé — sera réécrit 3.4 |
| `/ipxe/installation-windows.php` | Legacy | catchall | Inchangé — sera réécrit 3.5 |
| `/ipxe/enregistrement.php`, `/ipxe/enregistrement_byod.php` | Legacy | catchall | Inchangé — sera réécrit 3.3 |
| `/ipxe/clonage.php`, `/ipxe/clonezilla_menu.php` | Legacy | catchall | Inchangé — sera réécrit 3.7 |
| `/ipxe/Win10/*` | Legacy (assets + scripts) | catchall | Inchangé — fichiers statiques + scripts Sysprep |
| `/ipxe/sysresccd/*`, `/ipxe/clonezilla/*` | Legacy (assets) | catchall | Inchangé — fichiers ISO statiques, servis par Apache |
| `/ipxe/png/*` | Legacy (assets) | catchall | Inchangé |
| `/ipxe/diconf/*` | Legacy | catchall | Inchangé — sera réécrit 3.4 (preseeds) |

**Pas de collision** : les 5 routes natives (3.1 + 3.2) sont des routes précises déclarées AVANT le catchall `{path}`. Les autres routes `/ipxe/*` continuent d'être capturées par le catchall.

---

## References

- [Source: `_bmad-output/planning-artifacts/epics.md` §Epic 3 / Story 3.2] — cadrage haut niveau, prérequis Epic 1 + Epic 4 + Story 3.1.
- [Source: `_bmad-output/implementation-artifacts/3-1-ipxe-service-core.md`] — **Story fondation directe** — 27 fichiers créés, namespace `App\Ipxe`, services, channel log, decisions D1-D12 SM + DO-1 à DO-13 dev, scénarios QA 3.1-1 à 3.1-9.
- [Source: `_bmad-output/planning-artifacts/architecture.md`] — §"Modèle de Données — Source de Vérité" + §Coexistence Legacy — Stratégie Catchall.
- [Source: `_bmad-output/planning-artifacts/prd.md`] — §FR8 (boot/WOL context), §FR23-26 (déploiement Windows via iPXE — partagés Epic 3 + Epic 9), §NFR9 (offline LAN), §NFR15 (tests automatisés).
- [Source: `_bmad-output/implementation-artifacts/16-10-securisation-https-jwt-endpoints.md`] — middleware `auth.v1.secure-headers`, `JwtErrorCodes` catalogue.
- [Source: `_bmad-output/implementation-artifacts/16-11-auto-bootstrap-migration-postes.md`] — middleware `auth.v1.lan-only` (`EnsureLanIp`) + code `JwtErrorCodes::BOOTSTRAP_NOT_LAN` réutilisés.
- [Source: `_bmad-output/implementation-artifacts/16-12-logs-execution-centralises-ui-consultation.md`] — pattern channel log dédié, structure namespace `App\<Domain>`, factories sous-namespace.
- [Source: `sambaedu/ipxe/admin.php`] — source de vérité comportementale primaire pour `/ipxe/admin` (167 L — focus lignes 75-128 pour scope 3.2, ignorer 27-55 login + 60-114 enrollment/install/clonage).
- [Source: `sambaedu/ipxe/maintenance.php`] — source de vérité pour `/ipxe/maintenance` (63 L intégralement).
- [Source: `sambaedu/ipxe/action.php`] — source de vérité pour `/ipxe/action/{action}` (51 L — focus 28-41, ignorer auth_action).
- [Source: `sambaedu/ipxe/actions/rescuecd.php`] — source pour template rescuecd Blade (10 L).
- [Source: `sambaedu/ipxe/actions/winpe.php`] — source pour template winpe Blade (32 L).
- [Source: `sambaedu/ipxe/actions/clz_rest_sda2_sur_sda1.php`] — source pour template factory_reset Blade (10 L).
- [Source: `sambaedu/includes/ipxe_functions.inc.php`] — helpers `title()`, `boot_disk()`, `ipxe_out()` (82 L — `boot_disk()` porté en 3.1 via `IpxeMenuRenderer::renderBootDiskFallback()`).
- [Source: `app/Ipxe/Services/IpxeMenuRenderer.php`] — 3.1 — extension `renderHandshake(?string $chainTarget)` + ajout `renderAdminMenu` + `renderMaintenanceMenu`.
- [Source: `app/Ipxe/Services/IpxeService.php`] — 3.1 — extension `handleAdmin`/`handleMaintenance`/`handleAction`.
- [Source: `app/Ipxe/Services/WorkstationLocator.php`] — 3.1 — réutilisation pure.
- [Source: `app/Models/Workstation.php`] — modèle Eloquent (lecture seule en 3.2).
- [Source: `app/Models/MachineBootLog.php`] — table `machine_boot_logs` (réutilisation 3.2 avec 3 nouvelles valeurs `action`).
- [Source: `app/Http/Controllers/LegacyCatchallController.php`] — proxy catchall qui continue de servir les routes `/ipxe/*` non-3.2.
- [Source: `routes/web.php` lignes 574-622] — bloc d'insertion 3.2 (dans le bloc 3.1 existant, AVANT le catchall).
- [Source: `app/Auth/V1/Http/Middleware/EnsureLanIp.php`] — middleware `auth.v1.lan-only` 16.11 réutilisé.
- [Source: `app/Auth/V1/Support/JwtErrorCodes.php`] — code `BOOTSTRAP_NOT_LAN` 16.11 réutilisé.
- [Source: `config/ipxe.php`] — fichier 3.1 à étendre (3 nouvelles sections).
- [Source: `config/logging.php`] — channel `ipxe` posé 3.1 — réutilisation pure.
- [Source: `docs/qa/domains/ipxe.md`] — runbook 3.1 (9 scénarios stables 3.1-1 à 3.1-9) à étendre append-only avec section Story 3.2.
- [Source: `docs/qa/README.md`] — convention runbooks domaine.
- [Source: mémoire `feedback_worktree_no_vm_sync`] — depuis worktree `ipxe`, jamais SSH `/vm`.
- [Source: mémoire `feedback_auth_iso_legacy`] — Phase 2 prime sur iso-legacy pour l'auth applicative.
- [Source: mémoire `project_php_fpm_user_www_admin`] — PHP-FPM user `www-admin` (uid 599) — pas `www-data` (s'applique uniquement à Henri lors du smoke VM, pas au code 3.2).
- [Source: CLAUDE.md projet] — sync inotify (worktree non sync), pas de SSH `/vm` depuis worktree, naming SE4=legacy / SE5=sambaedu-reload.

---

## Dev Notes

### Justification design

- **Pourquoi 3 controllers séparés (`IpxeAdminController`/`IpxeMaintenanceController`/`IpxeActionController`) et pas 1 seul `IpxeMenuController` ?**
  Single Responsibility + cohérence avec 3.1 (`IpxeBootController`). Chaque controller a sa propre URL et son FormRequest spécifique. Un controller centralisé deviendrait un god class (~80 lignes pour 4 paths). La duplication est minime (chaque controller = 1 méthode `handle()` qui délègue au service).
- **Pourquoi enum `IpxeAdminAction` et pas un Constant array ?**
  Type-safe + `tryFrom()` natif + métadata (template path, log name) via méthodes enum. Constant array nécessiterait un wrapper `isValid()` manuel et perd la rigidité du compilateur PHP.
- **Pourquoi `IpxeActionResolver` séparé de `IpxeMenuRenderer` ?**
  Séparation concerne : `MenuRenderer` rend des menus interactifs (Blade `menu/*.blade.php`), `ActionResolver` rend des scripts d'exécution (Blade `actions/*.blade.php`). Les 2 ont des contextes différents (menu = items + chains, action = kernel + initrd + boot). Un service unifié mélangerait les responsabilités.
- **Pourquoi `factory_reset` et pas `restore_factory` ?**
  Convention snake_case + nom descriptif. `factory_reset` est un terme connu des admins (Android, iOS) qui exprime clairement la destruction des données. Parité avec `clz_rest_sda2_sur_sda1` legacy mais nommage moderne.
- **Pourquoi pas de menu `:clonezilla` natif en 3.2 ?**
  Le menu clonezilla legacy (`clonezilla_menu.php`) gère 7 modes (live32, live64, sav_locale32/64, rest_locale32/64, clonezilla_prevert) = scope 3.7 complet. En 3.2, on porte uniquement `factory_reset` (= `rest_locale64` qui est le mode auto restore sda2 → sda1) comme item du menu maintenance. Les autres modes nécessitent une UI admin pour pré-programmer un clonage (table `clonage_models` ou équivalent) = 3.7.
- **Pourquoi pas de login admin AD en 3.2 ?**
  Le login legacy `admin.php:27-55` fait `login_action()` (AD+session) + `have_right(SE_COMPUTER_INSTALL)` (autorisation Spatie-like). En 3.2 :
  - Implémenter le login = nouvelle dépendance LdapRecord dans `App\Ipxe` (interdit D1 iso 3.1).
  - Implémenter Spatie permissions sur le menu admin = couplage avec Epic 7 (Délégations & Permissions).
  - Le LAN-only suffit Phase 2 — un attaquant qui est sur le LAN peut déjà sniffer/usurper la MAC + UUID.
  - Phase 3 : story dédiée si Henri/terrain le demande.
- **Pourquoi pas de confirmation sur factory_reset ?**
  Le legacy n'en a pas (`maintenance.php:31-34`). Phase 2 = parité comportementale. Phase 3 pourra ajouter un menu de confirmation iPXE (`iseq ${confirm} yes && goto factory_reset || goto menu`) si Henri le souhaite — story dédiée si besoin terrain.

### Convention de logging (extension 3.1)

- Tous les logs 3.2 ont la clé `action_type` (iso 3.1 convention) :
  - `ipxe.admin.menu_rendered` (info)
  - `ipxe.maintenance.menu_rendered` (info)
  - `ipxe.action.dispatched` (info)
  - `ipxe.action.unknown_action` (warning)
  - `ipxe.action.render_error` (error)
- Toutes les valeurs sensibles (MAC, UUID, product, hostname) sont **préfixées** (6-8 chars) — pas de PII complète.
- Exception : `action` valeur de l'enum loggée en clair (3 valeurs connues whitelistées — pas de PII).
- Exception : `action_requested` (event unknown) tronqué 32 chars + sanitize ASCII.

### Pattern de flow 3.2 (extension 3.1)

```
Firmware iPXE (post 3.1 known menu) → choisit "1" (login)
  ↓
GET /ipxe/admin (handshake — pas de mac/uuid)
  ↓ (rendu handshake avec chainTarget='admin')
POST /ipxe/admin (mac/uuid posés)
  ↓
EnsureLanIp (16.11) — vérif RFC1918
  ↓
IpxeAdminController::handle → IpxeService::handleAdmin
  ↓
WorkstationLocator::locate (3.1) → ?Workstation
  ↓
Log ipxe.admin.menu_rendered + persist MachineBootLog (action=ipxe_admin)
  ↓
IpxeMenuRenderer::renderAdminMenu(?Workstation, $ip, $serverBaseUrl)
  → Blade ipxe.menu.admin
  ↓
User choisit "m" (maintenance)
  ↓ (chain vers /ipxe/maintenance natif)
GET /ipxe/maintenance (handshake → POST avec params)
  ↓ (iso flow admin)
IpxeMenuRenderer::renderMaintenanceMenu
  → Blade ipxe.menu.maintenance
  ↓
User choisit "c" (rescuecd) — ou "w" (winpe) ou "f" (factory_reset)
  ↓ (chain vers /ipxe/action/rescuecd natif)
GET /ipxe/action/rescuecd (handshake → POST avec params)
  ↓
IpxeAdminAction::tryFrom('rescuecd') → IpxeAdminAction::Rescuecd
  ↓
Log ipxe.action.dispatched + persist MachineBootLog (action=ipxe_action, initiated_by=ipxe:rescuecd)
  ↓
IpxeActionResolver::resolve(Rescuecd, ?Workstation, Request)
  → Blade ipxe.actions.rescuecd
  ↓
Response text/plain + headers (no-store, noindex)
  ↓
Firmware iPXE exécute le kernel sysresccd → reboot poste
```

### Tests qu'on **ne** fait **pas** dans cette story

- Tests de boot réel sur poste de test PXE → couvert par scénario QA manuel 3.2-12.
- Tests d'exécution réelle du kernel rescuecd/winpe/factory_reset — comportement OS/firmware, hors périmètre serveur.
- Tests de réparation Windows réelle (winpe → repair.bat) — déféré 3.5 + smoke poste réel.
- Tests de restauration Clonezilla réelle (factory_reset → ocs-sr) — déféré 3.7 + smoke poste de test (jamais en prod sans backup).
- Tests de charge `/ipxe/maintenance` ou `/ipxe/action/*` — déférés post-prod, ajuster throttle si volumétrie réelle dépasse 600/min/IP.

---

## Dev Agent Record

### Agent Model Used

`claude-opus-4-7[1m]` (1M context). Le SM recommandait `sonnet` (charge faible + scaffolding 3.1 prêt), mais le dev a été lancé en Opus par l'orchestrateur pour garantir la qualité de la non-régression `known.blade.php` (bascule chain login `.php` → natif) et l'extension cohérente de `IpxeService` avec 3 nouvelles méthodes orchestrateurs.

### Debug Log References

- T0.6 audit MachineBootLog : confirmé `action` varchar(20) + `initiated_by` varchar(100) sans CHECK constraint dans `tests/Support/IpxeSchemaBootstrapper.php` (schema ref 3.1). Les 3 nouvelles valeurs `ipxe_admin` (10), `ipxe_maintenance` (16), `ipxe_action` (11) + `initiated_by='ipxe:factory_reset'` (19 chars) passent toutes.
- Lint `php -l` exécuté sur 28 fichiers nouveaux/modifiés — 0 erreur.
- Garde-fou `git status` : aucun fichier dans `sambaedu/ipxe/*` ni `legacy/modules/ipxe/*` modifié.
- Tests phpunit non lancés localement : `vendor/` absent dans le worktree `ipxe` (static delivery iso pattern 3.1/16.10/16.11/16.12). Exécution différée Henri post-VM up via `./scripts/run-tests.sh` — résultats attendus ≈105 tests verts (75 baseline 3.1 + ≈40 nouveaux 3.2).

### Completion Notes List

**Décisions DO-* émises au-delà des D1-D12 SM** (au moins 12 décisions documentées) :

- **DO-1** — `IpxeService::handleAction()` lance `Symfony\Component\HttpKernel\Exception\NotFoundHttpException` plutôt que `abort(404)` direct (mockable Pest + parité Laravel — `abort` est sucre syntaxique sur ce throw).
- **DO-2** — `IpxeActionResolver` injecte `$autorunUrl` **pré-construit** côté service (pas dans le template Blade) pour permettre le test de l'interpolation `rawurlencode(mac)+rawurlencode(uuid)` sans rejouer le rendu Blade.
- **DO-3** — `sanitizeActionRequested()` dédié dans `IpxeService` (tronque 32 chars + remplace ASCII étendu par `?`) — D8 cas attaque/exploration : un attaquant peut poster n'importe quoi sur `/ipxe/action/<input>`, on veut tracer sans casser le log.
- **DO-4** — `safeActionRender()` wrap dédié au resolver d'action (distinct de `safeRender()` 3.1) — émet `ipxe.action.render_error` (event spécifique), pas `ipxe.boot.render_error`.
- **DO-5** — Factorisation `persistEndpointLog($workstation, $ip, $action, $initiatedBy)` qui sert les 3 endpoints (DRY) au lieu de dupliquer `persistMachineBootLog()` 3 fois. La méthode 3.1 reste pour non-régression strictement `handleBoot()`.
- **DO-6** — Factorisation `logMenuRendered($event, $workstation, $mac, $uuid, $ip)` partagée admin + maintenance (variant `known|unknown` injecté automatiquement selon `$workstation`).
- **DO-7** — Templates ASCII strict : accents français remplacés par alternatives sans accent (« demarrer » au lieu de « démarrer », « reparation » au lieu de « réparation »). Test archi 3.1 `it_renders_output_is_ascii_only` valide automatiquement.
- **DO-8** — `IpxeActionResolver::resolveOsUrl()` priorité config → request `getSchemeAndHttpHost()+'/ipxe'` → fallback `http://se4fs/ipxe` (resolveScriptUrl fallback sur osUrl si script_url null — parité legacy `admin.php:12`).
- **DO-9** — `Workstation::create()` direct dans tous les tests iso DO-8 de 3.1 (pas de factory dédiée à créer en 3.2).
- **DO-10** — Enum `IpxeAdminAction` avec méthodes `template()` + `logName()` métadata (single source of truth) — anti-pattern `match` géant in-line dans le controller (D9 explicite).
- **DO-11** — Template `winpe.blade.php` port iso strict des 32 lignes legacy : kernel + 2 blocs `params` (1 avant install.bat, 1 avant diskpart.txt) + 2 `iseq ${platform} efi` + 6 `initrd --name`. Le test unit valide la double occurrence des marqueurs.
- **DO-12** — `IpxeServiceProvider` enregistre `IpxeActionResolver` en singleton iso pattern 3.1. `IpxeService` reçoit 3 dépendances (vs 2 en 3.1) — modification du factory.
- **DO-13** (post-review #B4) — **Break-change minor 3.2** : `IpxeService::__construct` étend la signature avec un 4ème param positionnel `IpxeActionResolver $actionResolver` (sans default). Tous les call sites doivent passer par le container Laravel (`$this->app->make(IpxeService::class)` ou DI controller). L'instanciation directe `new IpxeService($locator, $renderer, $resolver)` 3.1 ne fonctionne plus telle quelle (params positionnels changés). Vérifié : `IpxeServiceTest`, `IpxeServiceLoggingTest`, `IpxeServiceAdminTest`, `IpxeServiceMaintenanceTest`, `IpxeServiceActionTest` utilisent tous `$this->app->make()` — passage par container OK. Aucun appelant externe au namespace `App\Ipxe` n'instancie `IpxeService` directement (grep statique). Risque : nul en interne, à signaler aux Stories 3.3+ qui étendraient le service.
- **DO-14** (post-review #B3 / Q4 Henri) — `IpxeMenuKind` enrichi de 6 cases (`Unknown`, `AdminHandshake`, `AdminMenu`, `MaintenanceHandshake`, `MaintenanceMenu`, `ActionHandshake`) pour servir de source de vérité unique aux libellés `$kind` consommés par `IpxeService::safeRender()`. Signature `safeRender()` typée enum (anti-typo). Enum désormais utilisé (plus de dead code dénoncé par l'opus review).
- **DO-15** (post-review #2 / Q2 Henri) — Whitelist `$version` winpe avec défense en profondeur : `config('ipxe.actions.winpe.allowed_versions')` + `Rule::in()` FormRequest + revalidation `IpxeActionResolver` (fallback `DEFAULT_WIN_VERSION` sans exception si court-circuit FormRequest). L'event log winpe sort toujours avec une valeur connue, le body iPXE ne peut plus injecter une ligne `kernel http://evil` via newline.
- **DO-16** (post-review #3 / Q1 Henri) — Log warning dédié `ipxe.action.factory_reset_dispatched` (channel `ipxe`, niveau `warning`) émis en parallèle de `ipxe.action.dispatched` (info) quand `$adminAction === IpxeAdminAction::FactoryReset`. Facilite l'alerte/filtre SIEM sur l'action destructive sans relire chaque dispatch.

### Lecture legacy T0.4 — différences notables 3.2 vs legacy

| Aspect | Legacy (`sambaedu/ipxe/*`) | Natif 3.2 | Justification |
|---|---|---|---|
| Login admin AD (`admin.php:27-55`) | Présent — `login_action()` + `have_right(SE_COMPUTER_INSTALL)` | **Absent** — `auth.v1.lan-only` seul | Parité D3/D8 de 3.1. Phase 3 reverra. |
| Set-name / enrollment (`admin.php:86-100`) | Présent (set-name, salle, parcs, enleveparc) | **Absent** — D7 message neutre poste inconnu | Scope 3.3 explicite. |
| Install Linux/Windows (`admin.php:103-114`) | Items présents | **Absent** | Scope 3.4/3.5. |
| Clonage CRUD (`admin.php:103-104`) | Item présent | **Absent** | Scope 3.7. |
| `auth_action()` dans `action.php:28` | Présent | **Absent** — D9 enum whitelist suffit | Le firmware iPXE n'a pas de session. |
| `:clonezilla` item maintenance | Legacy ligne 34 « clonezilla Distribution Linux live Clonezilla » | **Renommé `:factory_reset`** | Story 3.2 livre uniquement le restore auto (= `rest_locale64` legacy). Les 7 autres modes clonezilla = 3.7. |
| `params/param session_ipxe` legacy | Présent (suivi handshake) | **Absent** | Pas de session, pas de session_ipxe. |
| `console --picture png/sysrescuecd.png` (maintenance) | Présent | Identique (`config('ipxe.maintenance.background_png')`) | Parité visuelle conservée. |
| `param disk/perso/version` (winpe) | Lu depuis POST | Lu depuis Request avec défauts (`version='Win11'`, `debug=1`, `disk=0`, `perso=0`) | Parité legacy `winpe.php:6-7`. |
| `ar_source` (rescuecd autorun URL) | Construit en concat PHP | Construit côté `IpxeActionResolver` avec `rawurlencode()` | Renforcement sécurité — un mac/uuid contenant caractères spéciaux ne casse plus l'URL. |
| `boot_disk()` legacy | Fonction PHP iso reproduite 3.1 | Réutilisée via `IpxeMenuRenderer::renderBootDiskFallback()` (3.1) | Pas de duplication. |

### Items différés VM (action Henri post-merge VM up)

- **T7.2** — `./scripts/run-tests.sh` complet : suite Ipxe (~105 tests cumulés 3.1+3.2) + non-régression Auth V1 + Architecture. Lancement local impossible (`vendor/` absent).
- **T8.4** — Smoke 13 étapes : voir section « Smoke test à exécuter quand VM up » plus haut dans la story.
  1. Cache reset (composer + config + route + view).
  2. `curl /ipxe/admin` (handshake) → préambule + `chain admin##params`.
  3. `curl POST /ipxe/admin` (mac+uuid seedé) → menu admin + item maintenance.
  4. `curl POST /ipxe/maintenance` → 3 items rescue/winpe/factory_reset.
  5. `curl POST /ipxe/action/rescuecd` → kernel sysresccd.
  6. `curl /ipxe/action/install_macos` → 404.
  7. Vérifier logs `storage/logs/ipxe/ipxe-$(date +%F).log` events 3.2.
  8. Vérifier DB `MachineBootLog` 3 actions persistées.
  9. Non-régression `/ipxe/admin.php` legacy via catchall.
  10. Smoke poste réel (optionnel — pré-prod uniquement, JAMAIS factory_reset sur prod).
- **NB MITM** : `php-fpm` user `www-admin` (uid 599) doit pouvoir écrire `storage/logs/ipxe/`. Le provider crée le dossier au boot Apache.

### File List

#### Fichiers créés (18)

```
# Enum whitelist
app/Ipxe/Enums/IpxeAdminAction.php

# Service nouveau
app/Ipxe/Services/IpxeActionResolver.php

# Controllers (3)
app/Ipxe/Http/Controllers/IpxeAdminController.php
app/Ipxe/Http/Controllers/IpxeMaintenanceController.php
app/Ipxe/Http/Controllers/IpxeActionController.php

# FormRequests (3)
app/Ipxe/Http/Requests/IpxeAdminRequest.php
app/Ipxe/Http/Requests/IpxeMaintenanceRequest.php
app/Ipxe/Http/Requests/IpxeActionRequest.php

# Templates Blade (5)
resources/views/ipxe/menu/admin.blade.php
resources/views/ipxe/menu/maintenance.blade.php
resources/views/ipxe/actions/rescuecd.blade.php
resources/views/ipxe/actions/winpe.blade.php
resources/views/ipxe/actions/factory_reset.blade.php

# Tests Unit (5)
tests/Unit/Ipxe/Enums/IpxeAdminActionTest.php
tests/Unit/Ipxe/Services/IpxeActionResolverTest.php
tests/Unit/Ipxe/Services/IpxeServiceAdminTest.php
tests/Unit/Ipxe/Services/IpxeServiceMaintenanceTest.php
tests/Unit/Ipxe/Services/IpxeServiceActionTest.php
tests/Unit/Ipxe/Http/Controllers/IpxeAdminControllerSmokeTest.php

# Tests Feature (3)
tests/Feature/Ipxe/IpxeAdminEndpointTest.php
tests/Feature/Ipxe/IpxeMaintenanceEndpointTest.php
tests/Feature/Ipxe/IpxeActionEndpointTest.php
```

#### Fichiers modifiés (10)

```
# Services 3.1 — extension
app/Ipxe/Services/IpxeService.php                          # + handleAdmin, handleMaintenance, handleAction + 4 helpers privés
app/Ipxe/Services/IpxeMenuRenderer.php                     # + renderHandshake(?chainTarget) + renderAdminMenu + renderMaintenanceMenu
app/Ipxe/Enums/IpxeMenuKind.php                            # + cases Admin, Maintenance, Action

# Provider
app/Providers/IpxeServiceProvider.php                      # + bind IpxeActionResolver singleton, IpxeService constructor +1 dep

# Config
config/ipxe.php                                            # + sections admin, maintenance, actions

# Templates Blade existants — modification mineure
resources/views/ipxe/menu/handshake.blade.php              # + chainTarget paramétré ($chainTarget ?? 'boot')
resources/views/ipxe/menu/known.blade.php                  # chain login vers /ipxe/admin natif (sans .php)

# Routes
routes/web.php                                             # + bloc Story 3.2 (3 routes AVANT catchall)

# Tests existants — extension
tests/Unit/Ipxe/IpxeConfigTest.php                         # + 6 assertions admin/maintenance/actions
tests/Unit/Ipxe/Services/IpxeMenuRendererTest.php          # + 11 tests handshake target + admin + maintenance
tests/Unit/Ipxe/Services/IpxeServiceLoggingTest.php        # + 5 tests events 3.2 + leak checks
tests/Feature/Ipxe/IpxeLegacyRoutingNonRegressionTest.php  # + 4 tests court-circuit catchall 3.2 + clonage

# Architecture
tests/Architecture/IpxeNamespaceTest.php                   # + 3 tests (routes 3.2 ordre, controllers, enum 3 cases)

# Doc QA — append-only
docs/qa/domains/ipxe.md                                    # + Sections 6-10 + 13 scénarios stables 3.2-1 à 3.2-13

# Sprint-status
_bmad-output/implementation-artifacts/sprint-status.yaml   # ready-for-dev → review + last_updated enrichi
```

**Total** : **22 créés + 14 modifiés** = **36 fichiers** au total (incluant templates Blade, runbook QA et sprint-status).

**Post-review (2026-05-19)** : 0 nouveau fichier créé, 8 fichiers modifiés pour les 8 corrections appliquées (déjà inclus dans la liste ci-dessus) :
- `app/Ipxe/Services/IpxeService.php` — enum kind + warning factory_reset
- `app/Ipxe/Services/IpxeActionResolver.php` — defense in depth version whitelist
- `app/Ipxe/Http/Requests/IpxeActionRequest.php` — `Rule::in()` version
- `app/Ipxe/Enums/IpxeMenuKind.php` — +6 cases
- `config/ipxe.php` — `winpe.allowed_versions` + doc `se4install_passwd`
- `resources/views/ipxe/menu/admin.blade.php` — bloc params iso-legacy
- `resources/views/ipxe/menu/maintenance.blade.php` — bloc params iso-legacy
- 5 fichiers de tests touchés (assertions complétées + 7 nouveaux tests).

#### Tests écrits (~40)

| Niveau | Fichier | Nouveaux tests |
|---|---|---|
| Unit | `IpxeAdminActionTest` | 5 |
| Unit | `IpxeActionResolverTest` | 8 |
| Unit | `IpxeServiceAdminTest` | 6 |
| Unit | `IpxeServiceMaintenanceTest` | 5 |
| Unit | `IpxeServiceActionTest` | 7 |
| Unit | `IpxeServiceLoggingTest` (ext) | +5 |
| Unit | `IpxeMenuRendererTest` (ext) | +11 (4 handshake + 4 admin + 3 maintenance) |
| Unit | `IpxeConfigTest` (ext) | +6 |
| Unit | `IpxeAdminControllerSmokeTest` | 3 |
| Feature | `IpxeAdminEndpointTest` | 5 |
| Feature | `IpxeMaintenanceEndpointTest` | 4 |
| Feature | `IpxeActionEndpointTest` | 6 |
| Feature | `IpxeLegacyRoutingNonRegressionTest` (ext) | +4 |
| Architecture | `IpxeNamespaceTest` (ext) | +3 |
| **Total** | | **~78 cumulés (40 nouveaux + 38 baseline 3.1)** |

> Note volumes : ≥15 Unit ✅ (51 nouveaux Unit), ≥10 Feature ✅ (19 nouveaux Feature), ≥3 Archi ✅ (3 nouveaux Archi).

### Change Log

- 2026-05-19 : Story 3.2 CRÉÉE par SM `claude-opus-4-7`. Scope : 3 endpoints natifs (`/ipxe/admin`, `/ipxe/maintenance`, `/ipxe/action/{action}`) + 5 templates Blade + enum whitelist `IpxeAdminAction` (3 cases : rescuecd, winpe, factory_reset). Réutilisation totale du socle 3.1 (`IpxeService`, `IpxeMenuRenderer`, `WorkstationLocator`, channel log `ipxe`, middleware `auth.v1.lan-only`, table `MachineBootLog`). 10 volets / ~38 AC / 8 phases T0-T8 (~40 sous-tâches). 12 décisions D1-D12 tranchées. ~22 fichiers créés + ~10 modifiés. ≥30 tests cumulés (≥15 unit + ≥10 feature + ≥3 archi). Hors-scope explicite : login AD (Phase 3), enrollment (3.3), installs OS (3.4/3.5), ISO (3.6), clonezilla CRUD (3.7), retrait catchall (3.7 cleanup). Dépendances : Story 3.1 `review` au moment du cadrage SM — DOIT être `done` au démarrage dev 3.2 (T0.1 bloquant).
- 2026-05-19 : Story 3.2 IMPLÉMENTÉE par dev `claude-opus-4-7[1m]` (worktree `ipxe`). 22 fichiers créés + 14 modifiés. ~40 tests écrits (Unit/Feature/Architecture). Lint `php -l` 0 erreur sur 28 fichiers PHP. Tests phpunit différés VM Henri (vendor/ absent — static delivery iso 3.1). 12 décisions DO-* documentées. Doc QA `docs/qa/domains/ipxe.md` étendue append-only Sections 6-10 = 13 scénarios stables 3.2-1 à 3.2-13. Status `ready-for-dev` → `review`. Sprint-status mis à jour. Recommandation code-review : `sonnet` (opposé d'opus dev — pattern iso 16.10/16.11/16.12/3.1).
- 2026-05-19 : Story 3.2 CORRECTIONS POST-REVIEW appliquées par dev `claude-opus-4-7[1m]` (worktree `ipxe`). 8 corrections sur 12 findings review (cf. `_bmad-output/codeReviews/3-2.md`) — 4 décisions Henri arbitrées (Q1=warning factory_reset, Q2=whitelist Win10|Win11, Q3=iso-3.1 log file uniquement, Q4=enum IpxeMenuKind consommé). Fichiers touchés (8) : `app/Ipxe/Services/IpxeService.php` (DO-13+DO-14+DO-16 — enum kind + warning factory_reset), `app/Ipxe/Services/IpxeActionResolver.php` (DO-15 — whitelist defense in depth), `app/Ipxe/Http/Requests/IpxeActionRequest.php` (Rule::in version), `app/Ipxe/Enums/IpxeMenuKind.php` (+ 6 cases), `config/ipxe.php` (+ `winpe.allowed_versions` + doc se4install_passwd), `resources/views/ipxe/menu/admin.blade.php` + `maintenance.blade.php` (+ bloc params mac/uuid iso-legacy). Tests étendus (7 nouveaux tests + 5 assertions Feature headers) : `IpxeMenuRendererTest` (+2 params block), `IpxeActionResolverTest` (+2 injection version), `IpxeServiceLoggingTest` (+2 warning factory_reset), `IpxeServiceActionTest` (+1 headers), `IpxeServiceMaintenanceTest` (+ assertions étendues sur test existant), 3 tests Feature (+ assertHeader Cache-Control + X-Robots-Tag). Lint `php -l` 0 erreur. Tests phpunit/pest non-lançables localement (vendor/ absent) — différés VM. 4 nouvelles décisions DO-13 à DO-16 documentées. 5 items non corrigés acceptés (#4 doc-only, #5 dette 3.1, #7 dette 3.1, #8 réévaluer 3.3, #B1 décision Q3). Status `review` maintenu.

---

## Smoke test à exécuter quand VM up (action Henri post-merge)

```bash
# 0. Pré-requis : merge ipxe → main, code propagé sur la VM via inotify
ssh /vm  # = ssh -i ~/.ssh/id_se4fs_vm root@192.168.122.50

cd /var/www/sambaedu-reload

# 1. Composer + cache reset
composer install --no-dev --optimize-autoloader
php artisan config:cache
php artisan route:cache
php artisan view:cache

# 2. Smoke curl /ipxe/admin (handshake)
curl -sS http://192.168.122.50/ipxe/admin
# Attendu : 200 + text/plain + body commence par "#!ipxe\nparams\n...\nchain --replace --autofree admin##params\n"

# 3. Smoke curl /ipxe/admin poste connu (seed via tinker — réutiliser fixture 3.1)
curl -sS -X POST http://192.168.122.50/ipxe/admin \
  -d 'mac=aa:bb:cc:dd:ee:ff&uuid=12345678-1234-1234-1234-123456789abc&product=OptiPlex 3050'
# Attendu : body contient "item --key m maintenance" et chain vers /ipxe/maintenance

# 4. Smoke curl /ipxe/maintenance
curl -sS -X POST http://192.168.122.50/ipxe/maintenance \
  -d 'mac=aa:bb:cc:dd:ee:ff&uuid=12345678-1234-1234-1234-123456789abc'
# Attendu : body contient "item  --key c rescuecd" + "item  --key w winpe" + "item --key f factory_reset"

# 5. Smoke curl /ipxe/action/rescuecd
curl -sS -X POST http://192.168.122.50/ipxe/action/rescuecd \
  -d 'mac=aa:bb:cc:dd:ee:ff&uuid=12345678-1234-1234-1234-123456789abc'
# Attendu : body contient "kernel" + "sysresccd" + "boot"

# 6. Smoke curl action inconnue → 404
curl -sS -o /dev/null -w "%{http_code}" http://192.168.122.50/ipxe/action/install_macos
# Attendu : 404

# 7. Vérification logs
tail -f storage/logs/ipxe/ipxe-$(date +%F).log
# Attendu : events ipxe.admin.menu_rendered, ipxe.maintenance.menu_rendered, ipxe.action.dispatched, ipxe.action.unknown_action

# 8. Vérification MachineBootLog
sudo -u postgres psql -d sambaedu -c "SELECT id, workstation_id, machine_name, action, initiated_by, started_at FROM machine_boot_logs WHERE action IN ('ipxe_admin', 'ipxe_maintenance', 'ipxe_action') ORDER BY id DESC LIMIT 10;"

# 9. Non-régression catchall /ipxe/admin.php (legacy avec .php)
curl -sS http://192.168.122.50/ipxe/admin.php | head -20
# Attendu : servi via le legacy (proxy catchall) — pas de 404

# 10. Run de la suite ciblée 3.2 + non-régression 3.1
./vendor/bin/phpunit \
  tests/Unit/Ipxe/ \
  tests/Feature/Ipxe/ \
  tests/Architecture/IpxeNamespaceTest.php
# Attendu : ~105 tests verts (75 3.1 + ~30 3.2)

# 11. Run de la suite complète (non-régression Phase 1 + 16.10-16.12 + Epic 4 + Epic 3 stories 3.1+3.2)
./scripts/run-tests.sh

# 12. Smoke poste réel (optionnel — uniquement si poste de test disponible)
# Brancher un poste de test sur LAN, configurer PXE boot prioritaire en BIOS,
# rebooter → menu known iPXE → "1" (login) → menu admin → "m" (maintenance)
# → menu maintenance → "c" (rescuecd) → boot sysresccd réel.
# ⚠️ NE PAS tester factory_reset sur un poste de prod — destructif sans backup.
# Vérifier les rows MachineBootLog correspondantes en DB.
```

---

## Recommandation Modèle Dev

**Modèle recommandé : `sonnet`**

**Justification** :

- **Scaffolding déjà en place par 3.1** : namespace `App\Ipxe`, channel log, config, provider, templates handshake/default/known, `WorkstationLocator`, `MacAddressNormalizer`, `UuidNormalizer`, controller pattern, tests Unit/Feature/Architecture structure, runbook QA `docs/qa/domains/ipxe.md`. **Aucune décision architecturale nouvelle** — 3.2 ré-applique les 12 décisions D1-D12 + 13 décisions DO-* de 3.1.
- **Densité moyenne** : ~22 fichiers créés + ~10 modifiés (vs 27+4 en 3.1). Tous suivent un pattern déjà éprouvé (controller fin + FormRequest + Service Method + Renderer Method + Blade template + tests). Pas de logique métier complexe à reproduire iso-legacy au caractère près (la transformation hexadécimale product-empty 3.1 D4 — non répliquée en 3.2).
- **Pas de transformation legacy non-triviale** : 3.1 portait la transformation `hexdec(implode("", explode(":", $mac)))` qui requérait Opus. 3.2 porte des transformations directes (menu PHP procédural → menu Blade, action PHP procédural → action Blade) — substitution variable simple sans piège.
- **Whitelist enum simple** : `IpxeAdminAction` = 3 cases stricts. Pattern déjà éprouvé dans le projet (cf. `App\Auth\V1\Support\JwtErrorCodes` + autres enums).
- **Non-régression critique mais cadrée** : la modification de `known.blade.php` est minimale (1 ligne — changement du chain target). Le test archi 3.1 `ipxe_boot_route_is_declared_before_catchall` est à étendre, pas à refactor. La discipline sonnet pour respecter les contraintes "exactement 3 nouvelles routes, exactement 3 cases enum" suffit si les AC sont précis (ce qui est le cas).
- **Charge cadrée** : 1.5-3j (vs 2-4j en 3.1). Patterns 3.1 prêts à imiter, decisions log déjà tranché, lecture legacy concentrée sur 6 fichiers (167+63+51+10+32+10 = 333 lignes legacy à transposer).

**Bascule possible vers Opus** : si T4 (`IpxeService::handleAction()` avec whitelist enum + dispatch + error path) se passe mal avec sonnet — il y a un risque de sonnet qui se contente d'un `match` in-line dans le controller au lieu d'utiliser l'enum proprement. Décision à prendre par Henri après le premier point d'étape T2 (templates Blade). Si les 5 nouveaux templates iPXE sont rendus correctement (ASCII strict, pas de PHP residual, structure iso-legacy), sonnet est capable. Sinon, basculer Opus pour T3-T7.

**Anti-escalade** : ne pas escalader vers `claude-opus-4-7[1m]` (1M context) — la story est bien découpée (~38 AC, 10 volets, 8 phases). Le 1M context est utile pour des migrations massives multi-fichiers (>50), pas pour une story d'extension comme 3.2 où le contexte cumulé est largement <100k tokens.

**Note SM importante** : 3.1 a livré 75 tests verts par Opus avec une qualité exceptionnelle (12 décisions DO-* documentées, 13 corrections post-review appliquées). Il y a une tentation d'enchaîner avec Opus pour préserver la qualité. **Recommandation SM motivée** : tester sonnet sur 3.2 (charge plus faible, scaffolding prêt) pour économiser le coût et valider que le pattern 3.1 est "transférable" à un modèle moins puissant — c'est aussi un indicateur de qualité du scaffolding 3.1 (un bon framework permet à un modèle moyen de bien implémenter).

**Charge cadrée** : 1.5-3j. Recadrer 3-4j si T0.6 escalade (CHECK constraint MachineBootLog) ou si la lecture legacy révèle des subtilités non-cadrées sur `actions/winpe.php` (la complexité Win10 wimboot peut surprendre).

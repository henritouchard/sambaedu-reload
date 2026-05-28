# Story 3.1 : iPXE Service Core

Status: review

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

> Story **fondation** de l'Epic 3 (Système iPXE — Boot réseau & Déploiement OS). Pose le **socle Services Laravel `IpxeService`** : génération dynamique du **menu iPXE de premier appel** (étape `boot.php` du legacy), routage par **MAC/UUID**, lecture **PostgreSQL** (Workstation/WorkstationGroup/AppProfile) comme source de vérité — **sans appel LDAP/AD direct, sans dépendance au proxy legacy catchall**.
>
> **Scope strict 3.1** = (a) namespace `App\Ipxe` (modèle parallèle à `App\Auth\V1`, `App\Gpo`, `App\Wpkg`, `App\ScriptsOs`), (b) `IpxeService` qui génère un menu iPXE valide (texte plain `#!ipxe ...`) en fonction de la résolution MAC/UUID → Workstation, (c) `IpxeMenuRenderer` (templates Blade `resources/views/ipxe/menu/*.blade.php` — pattern iso ScriptsOs/16.12 wrapper), (d) `WorkstationLocator` (résolution par UUID prio + fallback MAC + normalisation, source `Workstation` Eloquent uniquement), (e) endpoint `GET|POST /ipxe/boot` (controller fin qui délègue au service) + alias `GET /ipxe/boot.ipxe` pour parité legacy `boot.ipxe`, (f) gestion premier appel iPXE (params manquants → renvoi du préambule `params/param mac/param uuid` + chain vers `boot.ipxe` — iso comportement `boot.php:26-35`), (g) logging dédié channel `ipxe`, (h) tests Unit + Feature + Architecture, (i) extension runbook QA — création `docs/qa/domains/ipxe.md` (premier domaine iPXE).
>
> **HORS-SCOPE 3.1** (explicitement reportés aux stories suivantes Epic 3 — cf. tableau dépendances aval) :
> - **Menu admin** iPXE (login admin, maintenance, rescue, factory reset, accès clonezilla/sysrescuecd/memtest) → **Story 3.2** (consomme `IpxeMenuRenderer`).
> - **Enrollment** d'un poste inconnu (saisie nom/parc/salle, persist Postgres + AD local via LdapRecord) → **Story 3.3**.
> - **Install Linux** (preseed dynamique, partitionnement, post-install Debian/Ubuntu) → **Story 3.4**.
> - **Install Windows** (wimboot, Sysprep, domain join) → **Story 3.5**.
> - **Upload / stockage / association ISO Windows** → **Story 3.6**.
> - **Clonezilla / mode maintenance** → **Story 3.7**.
> - **Réécriture des hooks `actions/*.php` + dossier `Win10/`** → réparti sur 3.4/3.5/3.7 (cf. epics.md §Epic 3 note implémentation).
> - **Retrait des routes legacy `legacy/modules/ipxe/*.php` du catchall** → reporté à la fin de l'Epic 3 (cf. dépendance aval 3.2-3.7 toutes done).
> - **UI admin GPO de pilotage des actions iPXE** (lancer install sur un parc, choisir un profil OS, etc.) → couvert par stories 3.4-3.7 + une future story Epic 4 dédiée si nécessaire.
> - **Authentification du poste appelant via JWT (`auth.v1.workstation`)** → **délibérément hors scope 3.1** (cf. D8 ci-dessous) — `boot.php` legacy est public et `boot` iPXE est appelé AVANT que le poste ait un OS qui puisse porter un JWT. Sécurité = IP allowlist LAN + middleware `EnsureLanIp` (réutilisé de 16.11).

---

## ⚠️ Mode de livraison & contraintes opérationnelles

> **Code synchronisé via inotify host→VM** sur `sambaedu-reload/*` (branche `main`). Si VM HS au moment du dev → static delivery, lint statique `php -l` + PHPUnit local si `vendor/` présent. Confirmer statut VM avec Henri en T0.1.
>
> - **NE PAS** sync manuellement le code sur la VM.
> - **NE PAS** SSH `/vm` depuis un worktree git. Cette story se fait sur la branche `main` (créée en local, dev direct).
> - **NE PAS** run les tests sur la VM si HS. Lint statique + PHPUnit local. Différer smoke complet à Henri post-reboot.
> - **Actions Henri post-dev** (à exécuter au reboot VM) — listées dans la section « Smoke test à exécuter quand VM up » : `composer install`, `php artisan migrate` (si nouvelle table créée — cf. D5), reload Apache si nécessaire, smoke `curl -X POST http://192.168.122.50/ipxe/boot -d 'mac=...&uuid=...'` (réponse text/plain `#!ipxe ...`), vérification logs channel `ipxe`, exécution `./scripts/run-tests.sh`, smoke poste réel (boot PXE d'un poste de test pointant sur SE4FS).

---

## Encadré contexte

**Topologie cible** (cf. architecture.md + epics.md §Epic 3) : un poste qui démarre en boot PXE/iPXE reçoit du DHCP/proxyDHCP/TFTP une URL `http://<se4fs>/ipxe/boot` (équivalent moderne du legacy `boot.php`). Cette URL est servie aujourd'hui par le proxy catchall legacy (`LegacyCatchallController` → `legacy/modules/ipxe/boot.php`). **3.1 introduit une route Laravel native `/ipxe/boot`** qui **précède** le catchall et la remplace progressivement.

**Comportement du premier appel iPXE** (parité iso-legacy stricte — cf. `sambaedu/ipxe/boot.php:26-35`) :

1. Le firmware iPXE appelle `GET /ipxe/boot` **sans paramètres** (le menu iPXE par défaut ne pose pas encore `mac`/`uuid`).
2. Le serveur détecte l'absence de `mac` ET `uuid` → renvoie un **préambule iPXE** :
   ```
   #!ipxe
   params
   param mac ${net0/mac}
   param uuid ${uuid}
   param product ${product}
   chain --replace --autofree boot.php##params
    || sleep 10
   ```
3. iPXE ré-appelle `POST /ipxe/boot` avec `mac`/`uuid`/`product` posés depuis les variables iPXE locales.
4. Le serveur résout le poste via `WorkstationLocator` (UUID prio, fallback MAC), génère et renvoie le menu adapté.

**Couplage avec Epic 4** : la résolution `WorkstationLocator` lit `App\Models\Workstation` (uuid, mac, name, status, physical_room_id). Les groupes (`WorkstationGroup`) et les profils d'application (`AppProfile`) sont chargés via eager loading pour usage par les stories 3.2+ (les hooks d'action — type d'action programmée — viendront en 3.2). 3.1 livre **la structure d'accès** mais ne pilote aucune action programmée (`actions` array vide ou neutre).

**Cohabitation legacy/native (D8 16.10 / D2 16.11 — pattern iso)** : le catchall legacy `LegacyCatchallController` continue de répondre à toutes les routes `/ipxe/*` non-spécifiques. 3.1 ajoute **uniquement** une route précise `/ipxe/boot` (+ alias `/ipxe/boot.ipxe`) dans `routes/web.php` **AVANT** le catchall. Les autres routes `/ipxe/admin.php`, `/ipxe/installation-linux.php`, etc. restent servies par le legacy jusqu'aux stories 3.2-3.7. **Aucune régression sur le legacy** : test archi à ajouter qui vérifie que toutes les autres URLs `/ipxe/*` sont encore traitées par le catchall.

**Format de réponse** : strictement `Content-Type: text/plain; charset=utf-8` (parité iso-legacy `ipxe_out()` — `sambaedu/includes/ipxe_functions.inc.php:59-62`). **PAS** d'`application/octet-stream`, **PAS** d'HTML, **PAS** de JSON. Le body commence **toujours** par `#!ipxe\n`.

**Idempotence + sécurité** : `GET|POST /ipxe/boot` est idempotent au sens fonctionnel (mêmes paramètres → même réponse), mais il a un **side effect log** (insertion `MachineBootLog` ou nouvelle table `ipxe_boot_logs` — D5 ci-dessous). Le side effect est tolérant aux retries iPXE (un même poste peut appeler plusieurs fois si timeout réseau, cf. ligne `|| sleep 10` du préambule — c'est attendu).

---

## ⚠️ Décisions tranchées (D1-D12, ne pas re-débattre)

> Cadrage SM 2026-05-19. Le dev applique sans re-discuter ; en cas de blocage technique réel, il documente la difficulté dans Dev Agent Record et continue.

### D1 — Namespace : **`App\Ipxe`** (parallélisme `App\Auth\V1`, `App\Gpo`, `App\Wpkg`, `App\Winscripts`, `App\ScriptsOs`)

- Sous-arborescence :
  ```
  app/Ipxe/
  ├── Services/
  │   ├── IpxeService.php
  │   ├── WorkstationLocator.php
  │   └── IpxeMenuRenderer.php
  ├── Http/
  │   ├── Controllers/
  │   │   └── IpxeBootController.php
  │   └── Requests/
  │       └── IpxeBootRequest.php
  ├── Enums/
  │   ├── IpxeMenuKind.php     (handshake|default|action — extensible 3.2)
  │   └── IpxePlatform.php     (legacy|uefi)
  ├── Support/
  │   ├── MacAddressNormalizer.php
  │   └── UuidNormalizer.php
  └── IpxeServiceProvider.php  (binding singletons + alias middleware si besoin)
  ```
- **Anti-pattern** : ne **pas** mettre dans `App\Services\Parc\` (couplage trop large avec le domaine machines/groupes). iPXE est un sous-domaine **réseau/boot** propre, comparable à `App\Auth\V1` qui n'est pas dans `App\Services\Auth`.
- **Pas de circular dep** : `App\Ipxe\Services\WorkstationLocator` lit `App\Models\Workstation` uniquement. Pas d'import depuis `App\Services\Parc\*`. Le futur Story 3.3 (enrollment) écrira dans `Workstation` via un nouveau service `App\Ipxe\Services\WorkstationEnrollmentService` — pas via 3.1.
- **`IpxeServiceProvider`** enregistré dans `config/app.php` providers array OU via auto-discover composer (à vérifier dans `composer.json` — pattern iso ScriptsOs/16.12).

### D2 — Endpoint HTTP : **`GET|POST /ipxe/boot` + alias `GET /ipxe/boot.ipxe`** (parité iso-legacy)

- 2 routes natives dans `routes/web.php`, **AVANT** le catchall legacy `Route::match(...)->where('path', '.*')` ligne 584 (cf. encadré « catch-all legacy DOIT ÊTRE EN DERNIER »).
- Bloc à ajouter dans `routes/web.php` **après le bloc 16.10 endpoints legacy `gpo/*_out.php`** et **avant** la route catchall :
  ```php
  /*
  |--------------------------------------------------------------------------
  | Story 3.1 — iPXE Service Core (endpoint natif de premier boot iPXE)
  |--------------------------------------------------------------------------
  | Remplace le legacy `/ipxe/boot.php` pour le menu de premier appel.
  | Les autres URLs `/ipxe/*` continuent à passer par le catchall legacy
  | jusqu'aux stories 3.2-3.7.
  */
  Route::match(['GET', 'POST'], '/ipxe/boot', [
      \App\Ipxe\Http\Controllers\IpxeBootController::class,
      'handle',
  ])
      ->middleware(['auth.v1.lan-only', 'throttle:600,1'])
      ->name('ipxe.boot');

  Route::get('/ipxe/boot.ipxe', [
      \App\Ipxe\Http\Controllers\IpxeBootController::class,
      'handle',
  ])
      ->middleware(['auth.v1.lan-only', 'throttle:600,1'])
      ->name('ipxe.boot.alias');
  ```
- **Méthodes acceptées** : `GET` (premier appel iPXE sans params, le firmware utilise GET par défaut) + `POST` (re-call avec `mac`/`uuid`/`product` posés via `param`+`chain##params` — iPXE encode en POST `application/x-www-form-urlencoded`). Parité legacy `boot.php:22-25` qui lit `$_POST['mac'] ?? $_GET['mac']`.
- **Pourquoi 2 noms d'URL ?** : iso-legacy. `boot.php` est servi par Apache+PHP avec l'extension `.php` masquée par mod_rewrite ; côté DHCP/proxyDHCP, certaines configs pointent sur `boot.php` (legacy) tandis que d'autres pointent sur `boot.ipxe` (fichier statique iPXE — cf. `sambaedu/ipxe/boot.ipxe`). 3.1 sert les **deux** noms canoniques, le futur Story 3.7 (cleanup) supprimera `boot.ipxe` statique.
- **Pas de middleware `web`** : `withoutMiddleware(['web'])` pour ne pas dépendre de la session/CSRF (un poste iPXE n'a pas de cookies). Pattern iso WPKG hosts.xml/profiles.xml (routes/web.php:567-572).
- **Throttle 600/min/IP** : un poste qui retry iPXE peut générer 5-10 calls en 10s ; on est large. Ajustable post-prod si besoin.

### D3 — Sécurité : **`auth.v1.lan-only` réutilisé (16.11)** — pas de nouveau middleware, pas de JWT

- Réutilisation stricte du middleware `App\Auth\V1\Http\Middleware\EnsureLanIp` posé par 16.11 (alias `auth.v1.lan-only`).
- **Justification** : un poste iPXE n'a **pas d'OS qui puisse porter un JWT** au moment du `/ipxe/boot` (l'OS n'est même pas encore booté). La seule protection raisonnable est la restriction LAN (RFC1918 + localhost). Le SE4FS est strictement LAN par design (cf. mémoire `feedback_auth_iso_legacy` + Encadré contexte 16.11).
- **Réponse 403 hors LAN** : format `{success: false, error: "forbidden", message: "iPXE endpoint is restricted to LAN", code: "bootstrap.not_lan"}` — réutilise le code existant `JwtErrorCodes::BOOTSTRAP_NOT_LAN` posé par 16.11 (**ne pas en créer de nouveau**). **Anti-pattern** : ne pas renvoyer 403 en text/plain — un attaquant HTTP brut a droit au format JSON standard.
- **NE PAS** appliquer `auth.v1.workstation` (JWT) — ce middleware exige un Authorization header Bearer JWT que iPXE ne sait pas porter.
- **NE PAS** créer de nouveau middleware spécifique iPXE — réutilisation totale.

### D4 — Résolution poste : **UUID prio (lowercase) + fallback MAC + composition uuid si product vide** (parité iso-legacy stricte)

- Algorithme `WorkstationLocator::locate(?string $mac, ?string $uuid, ?string $product = null): ?Workstation` :
  1. Normalisation UUID : `trim(strtolower($uuid))`. Si vide après normalisation → null.
  2. Normalisation MAC : convertir vers le format canonique `xx:xx:xx:xx:xx:xx` lowercase. Accepte les variantes legacy (`XX-XX-XX-XX-XX-XX`, `XXXXXXXXXXXX`, `xx:xx:xx:xx:xx:xx`, `Xx:Xx:Xx:Xx:Xx:Xx`). Si format invalide → null.
  3. **Cas `product` vide** : appliquer la transformation legacy `boot.php:36-41` :
     ```php
     $uuids = explode("-", $uuid);
     $dm = hexdec(implode("", explode(":", $mac)));
     $finx = dechex($dm);
     $uuid = $uuids[0]."-".$uuids[1]."-".$uuids[2]."-".$uuids[3]."-".$finx;
     ```
     Cette transformation est appliquée **uniquement** dans le cas `product` non fourni — comportement iso-legacy. Documenter dans le docblock du locator.
  4. Lookup PostgreSQL :
     - Étape 1 : `Workstation::where('uuid', $normalizedUuid)->whereNotNull('uuid')->first()`
     - Étape 2 (si null) : `Workstation::where('mac', $normalizedMac)->first()` (fallback MAC iso-legacy `get_action()`).
     - Étape 3 (si toujours null) : retourner `null` → menu **default** (poste inconnu, hors scope enrollment 3.3 — voir D6).
  5. Si trouvé : eager load `physicalRoom` + `groups` + `appProfiles` pour usage 3.2.
- **Anti-pattern** :
  - ❌ Ne **pas** appeler LdapRecord / `search_machine()` legacy. PostgreSQL est seule source de vérité (cf. architecture.md §"Modèle de Données — Source de Vérité").
  - ❌ Ne **pas** créer de Workstation à la volée si non trouvé (= scope 3.3 enrollment).
  - ❌ Ne **pas** updater la Workstation trouvée (pas d'effet de bord sur `uuid` même si différent du payload — l'update via merge UUID/MAC sera traité dans une story dédiée si nécessaire).
- **Tests unit** ≥10 cas : UUID match, MAC fallback, UUID empty, MAC empty, UUID malformé, MAC malformée (5 variantes acceptées + 1 rejetée), UUID uppercase normalisé, transformation `product` vide, poste introuvable → null, poste trouvé eager-load groups.

### D5 — Table de log iPXE : **réutiliser `MachineBootLog` existant** — pas de nouvelle table

- Le legacy `boot.php:69` appelle `log_connexion($config, "", $name, "ipxe", "startup")` qui écrit dans une table legacy équivalente.
- **Décision** : réutiliser `App\Models\MachineBootLog` (existe déjà — pivot Story 4.2) en lui ajoutant un type d'action `ipxe_boot` au lieu de créer une 2ème table.
- **Pas de migration nouvelle** dans cette story. Si `MachineBootLog::$fillable` ou le schema interdit `action='ipxe_boot'`, **escalader à Henri** plutôt que de modifier la table pour 3.1 — il est probable que `action` soit déjà un free string.
- **Schéma logique de la row insérée** par `IpxeService::logBootAttempt()` :
  - `workstation_id` (nullable — null si poste inconnu)
  - `machine_name` (string, lowercase — = `Workstation::name` ou `'unknown:' . $ip` si poste inconnu)
  - `action` = `'ipxe_boot'`
  - `initiated_by` = `'ipxe'` (string)
  - `success` = `true` (le boot iPXE est toujours considéré comme succès au sens fonctionnel — même un poste inconnu reçoit un menu default ; le succès du **boot OS** est tracké séparément)
  - `os` = null (inconnu au moment du boot iPXE — sera updaté par 3.4/3.5/4.2 quand l'OS bootera)
  - `started_at` = `now()`
  - `stopped_at` = `now()` (action instantanée — pas de fermeture différée)
- **Log Laravel** : en plus de la row DB, log info dans channel `ipxe` (config à créer — D7) avec : `mac_prefix` (6 premiers chars), `uuid_prefix` (8 premiers chars), `menu_kind` (`handshake|default|action`), `workstation_id` (nullable), `ip`. Pas de log du `product` complet (potentiellement PII matériel).
- **Idempotence des retries iPXE** : un même poste peut générer 3-5 rows `ipxe_boot` en 30s lors d'un retry réseau. Acceptable Phase 2 — pas de dédup. Le bandeau d'analytics ScriptsOs (16.12) ou un futur cleanup pourra agréger.

### D6 — Cas « poste inconnu » : **menu default minimal** — pas d'enrollment ici

- Si `WorkstationLocator::locate()` retourne `null` → le service génère un menu **default** :
  - Timeout `menu-timeout 10000` (10s — iso-legacy `boot.php:58`).
  - Item `(0) Quitter iPXE et booter le disque dur` (uniquement — pas d'option admin/install pour un poste inconnu).
  - `:default` → appel `boot_disk()` (helper qui décide UEFI vs legacy + sanboot drive 0x80 — réécrit en méthode `IpxeMenuRenderer::renderBootDiskFallback(): string`).
- **Pas d'item enrollment** (= scope 3.3). Le poste inconnu boot tout simplement sur son disque local.
- **Justification produit** : un poste neuf branché sur le LAN scolaire sans avoir été enregistré doit pouvoir continuer son boot disque normal — pas de blocage en attente d'enrollment admin. L'enrollment (3.3) sera **opt-in admin** : depuis l'UI admin SER, on programme un enrollment pour un MAC/UUID donné, et le menu enrollment ne s'affichera que pour ce poste pré-programmé.
- **Anti-pattern** : ne pas afficher de message d'erreur visible côté iPXE ("Poste non enregistré") — c'est du bruit pour le user final qui boot juste son PC. Le log info `ipxe.boot.unknown_workstation` est suffisant pour Henri.

### D7 — Channel logs : **`ipxe`** (nouveau) — driver `daily`, level `debug`

- Nouveau channel dans `config/logging.php`, pattern iso `auth-v1` (16.10) et `scriptsos` (16.12) :
  ```php
  'ipxe' => [
      'driver' => 'daily',
      'path' => storage_path('logs/ipxe/ipxe.log'),
      'level' => env('IPXE_LOG_LEVEL', 'debug'),
      'days' => 14,
      'replace_placeholders' => true,
  ],
  ```
- **Events à logger** :
  - `ipxe.boot.handshake` (info) — premier appel sans params, préambule renvoyé. Context : ip, user_agent.
  - `ipxe.boot.known_workstation` (info) — poste résolu. Context : ip, mac_prefix, uuid_prefix, workstation_id, workstation_name_prefix, menu_kind.
  - `ipxe.boot.unknown_workstation` (info) — poste non trouvé. Context : ip, mac_prefix, uuid_prefix, product_prefix.
  - `ipxe.boot.invalid_input` (warning) — mac/uuid format invalide. Context : ip, raw_mac_prefix, raw_uuid_prefix, reason.
  - `ipxe.boot.render_error` (error) — exception côté `IpxeMenuRenderer`. Context : exception_class, message, ip, mac_prefix, uuid_prefix.
- **Pas de secret loggé** : pas de password (pas applicable iPXE 3.1), pas d'UUID complet (PII matériel), pas de MAC complète. Maximum = préfixe 6-8 chars.
- **Niveau** : `info` pour les events normaux, `warning` pour input invalide, `error` pour les exceptions render (qui ne devraient jamais arriver — un template Blade qui échoue est un bug à fixer).

### D8 — Pas d'authentification JWT — **`auth.v1.lan-only` seul** suffit

- Cf. D3. La route `/ipxe/boot` est **publique au LAN** par construction (un firmware iPXE n'a pas de credentials).
- **Pas** de bootstrap token md5 (Phase 1 16.7 — appliqué uniquement aux endpoints legacy `gpo/*_out.php`).
- **Pas** de JWT (16.10 — appliqué uniquement aux endpoints `/api/v1/*` post-bootstrap d'un poste déjà migré).
- **Mitigation** : `auth.v1.lan-only` exclut tout appelant hors RFC1918. Acceptable pour Phase 2 vu le périmètre strict LAN scolaire de SE4FS.
- **Phase 3+** : si besoin de durcir, on pourra ajouter un mécanisme d'attestation HMAC basé sur un secret partagé poste/serveur (= Phase 3, story dédiée). Hors scope 3.1.

### D9 — Templates Blade dans `resources/views/ipxe/` — pas de string concat in-line

- 3 templates Blade à créer (pattern iso 16.10/16.11/16.12 `resources/views/auth/v1/*.blade.php`) :
  - `resources/views/ipxe/menu/handshake.blade.php` — préambule iPXE de premier appel (sans params).
  - `resources/views/ipxe/menu/default.blade.php` — menu pour poste inconnu (boot disk only).
  - `resources/views/ipxe/menu/known.blade.php` — menu pour poste connu (action + login + ltsp si dispo + default + exit). 3.1 livre la **structure minimale** : item `default` (boot disk) + item `login` (placeholder qui chain vers `/ipxe/admin.php` legacy — 3.2 le remplacera) + item `action` conditionnel (si une action est programmée — mécanisme inactif en 3.1 mais le template doit accepter `$action` nullable).
- Variables injectées : `$mac`, `$uuid`, `$product`, `$ip`, `$workstation` (model nullable), `$serverBaseUrl`, `$bootDiskFallback`, `$menuTimeoutMs`, `$resolutionPng` (URL relative vers `png/ipxe-se4.png` legacy — réutilisé via le proxy catchall ou copié dans `public/ipxe/png/` cf. T0.5).
- **Charset** : `text/plain; charset=utf-8` (iso-legacy `ipxe_out()`).
- **Newline final** : terminer chaque template par `\n` (iPXE est strict — un fichier sans newline final peut foirer la dernière commande).
- **PAS de syntaxe PHP résiduelle** : test unit qui vérifie que le rendu final ne contient ni `<?php` ni `?>`.
- **Pas de string concat dans `IpxeService`** : tout passage de variable se fait via le contexte Blade `view('ipxe.menu.known', compact(...))->render()`. Pattern iso ScriptsOs `WrapperScriptRenderer`.

### D10 — Format de réponse + headers

- `Content-Type: text/plain; charset=utf-8`
- `Cache-Control: no-store` (un menu iPXE dépend du `mac`/`uuid` + d'actions programmées dynamiques — pas de cache intermédiaire)
- `X-Robots-Tag: noindex` (pas de crawl par des bots si jamais le LAN est exposé par accident)
- `Content-Length` : laissé à Symfony Response (auto-calc)
- **Pas** de `Set-Cookie` (un firmware iPXE n'a pas de cookie store)
- **Pas** de `X-Content-Type-Options: nosniff` (pas applicable — text/plain pur)

### D11 — Variables de configuration : **`config/ipxe.php`** (nouveau)

- Nouveau fichier `config/ipxe.php` (pattern iso `config/auth_v1.php`, `config/scriptsos.php`, `config/parc.php`) :
  ```php
  return [
      'se4fs_name' => env('IPXE_SE4FS_NAME', config('sambaedu.se4fs_name', 'se4fs')),
      'menu' => [
          'default_timeout_ms' => (int) env('IPXE_DEFAULT_TIMEOUT_MS', 5000),
          'unknown_timeout_ms' => (int) env('IPXE_UNKNOWN_TIMEOUT_MS', 10000),
          'resolution_x' => (int) env('IPXE_RESOLUTION_X', 1024),
          'resolution_y' => (int) env('IPXE_RESOLUTION_Y', 768),
          'background_png' => env('IPXE_BACKGROUND_PNG', 'png/ipxe-se4.png'),
      ],
      'boot_disk' => [
          // Liste de products forcés UEFI iso-legacy boot_disk()
          'force_uefi_products' => (array) env('IPXE_FORCE_UEFI_PRODUCTS', [
              'Precision T1700',
              'Precision Tower 3620',
              'Precision Tower 3420',
              'OptiPlex 3050',
              'OptiPlex 3010',
              'OptiPlex 3020',
              'OptiPlex 3030',
              'OptiPlex 3040',
              'HP Z240 Tower Workstation',
              'HP 280 G2 SFF',
              'HP EliteBook 850 G1',
              '10M8S1B000',
              '30AT0025FR',
          ]),
      ],
      'log' => [
          'channel' => 'ipxe',
      ],
  ];
  ```
- **Valeurs par défaut** : iso-legacy. Henri peut override via `.env` sans toucher au code.
- **Test** : `config('ipxe.menu.default_timeout_ms')` retourne 5000 par défaut.

### D12 — Migrations : **aucune nouvelle migration** dans cette story

- D5 décide explicitement de réutiliser `MachineBootLog` existant.
- Si T1.1 audit révèle que `MachineBootLog.$fillable` ou un check constraint DB bloque `action='ipxe_boot'` → escalader à Henri pour décision (option A : étendre `$fillable`, option B : créer nouvelle table `ipxe_boot_logs`). **Hypothèse de cadrage SM** : `$fillable` autorise déjà (à vérifier — cf. T1.1).
- Aucune migration, aucun seeder, aucun ALTER TABLE.

---

## Story

As **un poste de travail du parc scolaire (Windows ou Linux) en boot PXE/iPXE** ainsi qu'**un mainteneur du codebase `sambaedu-reload`** et **Henri en tant qu'admin SER** :

I want
- disposer d'une **route Laravel native `/ipxe/boot`** qui remplace progressivement le legacy `boot.php` PHP procédural, et qui sert un menu iPXE valide (texte plain `#!ipxe ...`) en fonction de l'identité matérielle du poste (MAC + UUID) ;
- garantir que la **résolution du poste** se fait **exclusivement** depuis PostgreSQL (modèle `Workstation` + relations `WorkstationGroup`/`AppProfile`), sans appel LDAP/AD direct, sans dépendance au proxy legacy catchall ;
- gérer le **premier appel iPXE** (sans paramètres) en renvoyant le préambule iPXE qui demande au firmware de poser `mac`/`uuid`/`product`, puis le re-call avec params posés ;
- assurer **zéro régression** sur les routes iPXE legacy non encore réécrites (`/ipxe/admin.php`, `/ipxe/installation-linux.php`, etc.) — elles continuent de passer par le catchall ;
- poser les **fondations** (services + templates Blade + structure du menu) que les stories 3.2-3.7 viendront enrichir (menu admin, enrollment, installs OS, clonezilla).

So que :
- (a) **les développeurs des stories 3.2-3.7** disposent d'un `IpxeService` propre et testable comme socle, plutôt que de devoir réécrire 32 fichiers PHP legacy en parallèle ;
- (b) **les opérateurs Henri** ont un point d'entrée Laravel native avec logging structuré (`channel ipxe`) pour débugger les problèmes de boot iPXE — visible via `tail storage/logs/ipxe/ipxe-$(date +%F).log` ;
- (c) **la transition vers le natif** est progressive et **réversible** — on peut désactiver la route `/ipxe/boot` via un feature-flag temporaire (= renommer la route en attendant 3.2 si nécessaire) sans casser le legacy.

---

## Contexte

### État entrant (post-Epic 1 done + Epic 4 done + 16.10/16.11 review)

| Élément | État actuel | Action 3.1 |
|---|---|---|
| Routes `/ipxe/*` | Toutes servies par `LegacyCatchallController` → `legacy/modules/ipxe/*.php` | **Ajouter** route native `/ipxe/boot` (+ alias) **avant** le catchall |
| `App\Models\Workstation` | ✅ Existant (Epic 4, story 4.1 done) — colonnes `id, name, os, ip, mac, uuid, status, physical_room_id, ad_dn, ad_guid, ...` | **Consommer** via lecture (where uuid + where mac) — pas de modification du schema |
| `App\Models\WorkstationGroup` | ✅ Existant — pivot `workstation_group_workstation` + `physical_room_id` | **Consommer** via eager load (pour usage 3.2) |
| `App\Models\AppProfile` | ✅ Existant — pivot `app_profile_workstation` | **Consommer** via eager load (pour usage 3.2 sur les actions programmées) |
| `App\Models\MachineBootLog` | ✅ Existant (story 4.2) — colonnes `workstation_id, machine_name, action, initiated_by, success, os, started_at, stopped_at, error_flags` | **Réutiliser** avec `action='ipxe_boot'` (D5) — pas de migration |
| Middleware `auth.v1.lan-only` (`EnsureLanIp`) | ✅ Livré 16.11 — RFC1918 + localhost par défaut | **Réutiliser** sur `/ipxe/boot` (D3) — pas de modification |
| Channel log `ipxe` | ❌ N'existe pas | **Créer** dans `config/logging.php` (D7) |
| `config/ipxe.php` | ❌ N'existe pas | **Créer** (D11) |
| Vues Blade `resources/views/ipxe/` | ❌ N'existe pas (premier usage côté views) | **Créer** dossier + 3 templates handshake/default/known (D9) |
| Namespace `App\Ipxe` | ❌ N'existe pas | **Créer** (D1) |
| Runbook QA `docs/qa/domains/ipxe.md` | ❌ N'existe pas (premier domaine iPXE) | **Créer** avec section Story 3.1 + ≥8 scénarios numérotés stables 3.1-1 à 3.1-N |
| Tests Unit/Feature/Architecture iPXE | ❌ N'existent pas | **Créer** la couverture initiale (≥20 tests cumulés) |

### Source de vérité du comportement attendu

Le fichier `sambaedu/ipxe/boot.php` (153 L) et son helper `sambaedu/includes/ipxe_functions.inc.php` (82 L) sont la source de vérité fonctionnelle de 3.1. Le dev **doit lire ces 2 fichiers en T0.4** avant d'implémenter le rendu Blade. Les autres fichiers `sambaedu/ipxe/*.php` (admin.php, installation-linux.php, etc.) sont hors scope 3.1 (= stories 3.2-3.7).

### Risques entrants

| Risque | Sévérité | Mitigation 3.1 |
|---|---|---|
| La route native `/ipxe/boot` rentre en collision avec le catchall legacy → 2 réponses possibles selon l'ordre | 🟠 Élevée | Test archi à ajouter : `it_serves_ipxe_boot_natively_and_not_via_catchall` + ordre dans `routes/web.php` strict (ipxe.boot AVANT catchall — préserver le commentaire `⚠⚠⚠ La route catch-all legacy doit rester la dernière route définie ⚠⚠⚠`). |
| Modification accidentelle de la signature `MachineBootLog::$fillable` qui casserait 4.2/4.3 | 🟡 Moyenne | T1.1 audit obligatoire — si `$fillable` rejette `action='ipxe_boot'`, escalader à Henri. Pas de modif unilatérale. |
| Comportement legacy mal reproduit (transformation `product` vide) → un poste qui aurait dû être trouvé reste inconnu | 🟡 Moyenne | T2.4 test unit qui replique la transformation hexadécimal exacte avec 3 fixtures iso-legacy. Documenter dans docblock du locator. |
| Le proxy catchall réécrit les URLs dans le body de la réponse iPXE (cf. `LegacyCatchallController:199-209`) → si on hérite involontairement, on casse le menu | 🟢 Mineure | La route native court-circuite totalement le proxy. Test feature qui asserte que `$response->getContent()` ne contient pas de URL réécrite. |
| Format MAC incompatible entre iPXE firmware et stockage Postgres (case + séparateur) | 🟡 Moyenne | D4 `MacAddressNormalizer` couvre 5 variantes. Tests unit ≥6 cas + 1 cas rejeté. |
| Charset mismatch (iPXE attend ASCII strict — pas d'accent dans le menu) | 🟢 Mineure | Templates Blade ASCII pur (pas d'accent fr) — le menu legacy `boot.php` utilise déjà des chars sans accents (cf. ligne 87 "Booter un client reseau LTSP"). À répliquer iso. |
| Throttle 600/min trop bas en rentrée scolaire (500 postes qui boot en parallèle) | 🟢 Mineure | 600/min/IP couvre 10 retries par poste sur 1 min même pour 60 postes simultanés. Si volumétrie réelle dépasse → ajuster post-prod. |
| Le test archi ne détecte pas tous les imports illégaux (`LdapRecord`, `legacy/*`) | 🟢 Mineure | Test `story_3_1_files_do_not_import_legacy_or_ldap` qui scanne `app/Ipxe/**/*.php` pour les chaînes interdites. Pattern iso 16.10/16.11. |

### Pré-requis (à valider en T0)

- **Code à jour sur la VM via inotify** : commit `main` actuel réfléchi sur `/var/www/sambaedu-reload`. À valider en T0.1.
- **Epic 1 done** : ✅ confirmé (AuthGuard + catchall + dashboard legacy — tous done).
- **Epic 4 done** : ✅ confirmé sprint-status (4.1 à 4.7 done, 4.8 review). Workstation/WorkstationGroup/AppProfile sont stables.
- **16.10 review accepté** : `auth.v1.lan-only` (`EnsureLanIp`) disponible. ✅
- **16.11 review accepté** : `JwtErrorCodes::BOOTSTRAP_NOT_LAN` disponible. ✅
- **Apache / nginx config** : la route `/ipxe/boot` doit être servie par Laravel (pas par Apache directement). À confirmer en T0.6 — `legacy/modules/ipxe/boot.php` est servi via le proxy catchall, donc Laravel intercepte déjà toutes les requêtes `/ipxe/*` et délègue. Le **nouvel** endpoint `/ipxe/boot` court-circuite simplement le catchall en se déclarant avant.

---

## Acceptance Criteria

> AC organisées en **9 volets**. Volet 9 (QA + doc) est **création** (`docs/qa/domains/ipxe.md` n'existe pas encore — voir README.md ligne 20).

### Volet 1 — Structure namespace + service provider

**AC1.1** — **Création du namespace `App\Ipxe` + service provider**

**Given** la racine `app/`,
**When** le dev crée le sous-namespace `App\Ipxe` selon l'arborescence D1,
**Then** :
- Les sous-dossiers `app/Ipxe/{Services,Http/Controllers,Http/Requests,Enums,Support}` existent.
- Le `App\Ipxe\IpxeServiceProvider` est créé et enregistré dans `config/app.php` `providers` array (OU auto-discovered via `composer.json` `extra.laravel.providers` — à confirmer en T0.5).
- Le provider binde `IpxeService`, `WorkstationLocator`, `IpxeMenuRenderer` en singleton via `$this->app->singleton(IpxeService::class, fn() => new IpxeService(...))` etc.
- **Anti-pattern** : pas de bind dans `App\Providers\AppServiceProvider` (cohérence avec 16.10/16.11/16.12 qui ont leur propre provider par domaine).

**And** un test archi `tests/Architecture/IpxeNamespaceTest.php` créé avec ≥3 méthodes :
- `it_lists_all_ipxe_services_under_correct_namespace` (vérifie présence des 3 services principaux).
- `it_does_not_import_ldap_record_in_ipxe_namespace` (interdit `LdapRecord\*` partout dans `app/Ipxe/**/*.php`).
- `it_does_not_import_legacy_modules_in_ipxe_namespace` (interdit `legacy/modules/*` partout dans `app/Ipxe/**`).

### Volet 2 — `WorkstationLocator` (D4)

**AC2.1** — **`WorkstationLocator::locate(?string $mac, ?string $uuid, ?string $product = null): ?Workstation`**

**Given** la classe `App\Ipxe\Services\WorkstationLocator`,
**When** elle est invoquée avec des `mac`/`uuid` posés,
**Then** :
- Normalise UUID via `App\Ipxe\Support\UuidNormalizer::normalize($uuid)` (trim + strtolower).
- Normalise MAC via `App\Ipxe\Support\MacAddressNormalizer::normalize($mac)` (accepte `xx:xx:...`, `XX-XX-...`, `xxxxxxxxxxxx`, mixed case — renvoie null si format invalide).
- Si `product` est vide ET les 2 (mac, uuid) sont valides : applique la transformation hexadécimal iso-legacy `boot.php:36-41` avant lookup.
- Lookup priorité UUID : `Workstation::query()->where('uuid', $normalizedUuid)->whereNotNull('uuid')->first()`.
- Fallback MAC : `Workstation::query()->where('mac', $normalizedMac)->first()`.
- Si trouvé : eager load `physicalRoom`, `groups`, `appProfiles`.
- Si non trouvé : retourner `null` (pas d'exception, pas de log error).

**And** un test unit `tests/Unit/Ipxe/Services/WorkstationLocatorTest.php` avec ≥10 tests :
- `it_resolves_by_uuid_when_uuid_matches`
- `it_falls_back_to_mac_when_uuid_does_not_match`
- `it_returns_null_when_both_unknown`
- `it_normalises_mac_with_colons_dashes_no_separator_variants` (4 sous-cas)
- `it_rejects_invalid_mac_format` (chars non-hex)
- `it_normalises_uuid_to_lowercase`
- `it_returns_null_when_uuid_and_mac_both_empty`
- `it_applies_legacy_product_empty_transformation_when_product_omitted` (fixture iso-legacy)
- `it_does_not_apply_legacy_transformation_when_product_provided`
- `it_eager_loads_relations_when_workstation_found` (asserte `$ws->relationLoaded('physicalRoom')` etc.)

**AC2.2** — **Normalizers indépendants et testables**

**Given** les classes `MacAddressNormalizer` et `UuidNormalizer`,
**When** elles sont invoquées en isolation,
**Then** :
- `MacAddressNormalizer::normalize(string $raw): ?string` renvoie le format canonique `xx:xx:xx:xx:xx:xx` lowercase OU `null` si format invalide.
- `UuidNormalizer::normalize(string $raw): ?string` renvoie `trim(strtolower($raw))` OU `null` si vide après normalisation. **Pas** de validation regex stricte UUID v4 — le legacy accepte des UUIDs malformés (cas `boot.php:39` qui reconstruit l'UUID via MAC) ; on est tolérant.

**And** tests unit ≥6 cas par classe (variantes valides + 2 invalides).

### Volet 3 — `IpxeMenuRenderer` (D9)

**AC3.1** — **Création du service `IpxeMenuRenderer` + 3 templates Blade**

**Given** la classe `App\Ipxe\Services\IpxeMenuRenderer`,
**When** elle est invoquée par `IpxeService`,
**Then** elle expose :
- `renderHandshake(): string` — rend `resources/views/ipxe/menu/handshake.blade.php`. Pas de variables (le préambule est statique).
- `renderUnknown(string $ip): string` — rend `resources/views/ipxe/menu/default.blade.php` avec `$ip`, `$bootDiskFallback`, `$menuTimeoutMs` (= `config('ipxe.menu.unknown_timeout_ms')`), `$resolutionPng`.
- `renderKnown(Workstation $ws, ?array $action, string $serverBaseUrl): string` — rend `resources/views/ipxe/menu/known.blade.php` avec `$ws` (modèle), `$action` (nullable — pour 3.2), `$serverBaseUrl`, `$bootDiskFallback`, `$menuTimeoutMs`, `$resolutionPng`.
- `renderBootDiskFallback(?string $product = null): string` — helper privé (ou public si test direct souhaité) qui rend la chaîne `iseq ${platform} efi ...` iso `boot_disk()` legacy. Utilise `config('ipxe.boot_disk.force_uefi_products')`.

**And** test unit `tests/Unit/Ipxe/Services/IpxeMenuRendererTest.php` ≥8 tests :
- `it_renders_handshake_starts_with_shebang_ipxe`
- `it_renders_handshake_contains_param_mac_and_uuid`
- `it_renders_unknown_contains_only_default_item`
- `it_renders_known_contains_login_and_default_items`
- `it_renders_boot_disk_fallback_with_uefi_branches`
- `it_renders_boot_disk_fallback_with_legacy_branches`
- `it_renders_output_starts_with_shebang_ipxe` (général, tous les rendus)
- `it_renders_output_does_not_contain_php_tags` (anti-régression PHP residual).

**AC3.2** — **Templates Blade créés et testables**

**Given** 3 nouveaux fichiers Blade `resources/views/ipxe/menu/{handshake,default,known}.blade.php`,
**When** ils sont rendus par `IpxeMenuRenderer` avec les variables documentées,
**Then** :
- **handshake.blade.php** (~8 lignes) :
  ```
  #!ipxe
  params
  param mac ${net0/mac}
  param uuid ${uuid}
  param product ${product}
  chain --replace --autofree boot##params
   || sleep 10
  ```
  Newline final obligatoire.
- **default.blade.php** (~12 lignes) — menu pour poste inconnu :
  - `#!ipxe`
  - `console --x {{ $resolutionX }} --y {{ $resolutionY }} --picture {{ $resolutionPng }}`
  - `:menu`
  - `menu Preboot eXecution Environment for {{ $ip }}`
  - `set menu-default exit`
  - `set menu-timeout {{ $menuTimeoutMs }}`
  - 1 item `--key 0 exit (0) Quitter iPXE et booter le disque dur`
  - `choose --default ${menu-default} --timeout ${menu-timeout} selected && goto ${selected} || exit 0`
  - `:exit` + `{!! $bootDiskFallback !!}` (raw injection pour éviter l'échappement HTML qui casserait `${` iPXE)
- **known.blade.php** (~25 lignes) — menu pour poste connu :
  - Idem + items `:login` (placeholder qui chain vers `/ipxe/admin.php` = legacy proxy en 3.1, sera remplacé par 3.2) et `:default` (bootDiskFallback).
  - Item `--key 2 action ...` conditionnel `@if($action)` (inactif en 3.1 mais le template est prêt).
  - **PAS d'item `:ltsp`** dans la 3.1 — feature LTSP déférée (cf. legacy `boot.php:88` qui dépend de `dpkg -l sambaedu-ltsp`).

**And** chaque template **commence par `#!ipxe\n`** et **termine par `\n`**.

**And** test feature `IpxeBootEndpointTest::it_renders_known_menu_with_login_and_default_items` vérifie le body complet via `$response->assertSee(':login', false)` (escape=false pour iPXE syntax).

### Volet 4 — `IpxeService` orchestrateur

**AC4.1** — **`IpxeService::handleBoot(Request $request): Response`**

**Given** la classe `App\Ipxe\Services\IpxeService`,
**When** elle est invoquée par `IpxeBootController::handle()`,
**Then** :
- Extrait `$mac = (string) $request->input('mac', '')`, `$uuid = (string) $request->input('uuid', '')`, `$product = (string) $request->input('product', '')`, `$ip = $request->ip()`, `$ua = (string) $request->userAgent()`.
- **Cas handshake** : si `$mac === '' && $uuid === ''` :
  - Log info `ipxe.boot.handshake` (channel `ipxe`).
  - Retourne `response($renderer->renderHandshake(), 200)->header('Content-Type', 'text/plain; charset=utf-8')->header('Cache-Control', 'no-store')`.
- **Cas locate** : sinon, appelle `$locator->locate($mac, $uuid, $product)` :
  - Si `$workstation === null` :
    - Log info `ipxe.boot.unknown_workstation` (mac_prefix 6, uuid_prefix 8, product_prefix 8, ip).
    - Insère `MachineBootLog` avec `workstation_id=null`, `machine_name='unknown:'.$ip`, `action='ipxe_boot'`, `initiated_by='ipxe'`, `success=true`, `started_at=now()`, `stopped_at=now()`.
    - Retourne `renderer->renderUnknown($ip)`.
  - Si `$workstation !== null` :
    - Log info `ipxe.boot.known_workstation` (workstation_id, name_prefix 6, mac_prefix, uuid_prefix, ip).
    - Insère `MachineBootLog` avec `workstation_id=$ws->id`, `machine_name=strtolower($ws->name)`, `action='ipxe_boot'`, `initiated_by='ipxe'`, `success=true`, `started_at=now()`, `stopped_at=now()`.
    - `$action = null` (mécanisme `action` programmée = scope 3.2 — `IpxeService` retourne null pour l'instant via méthode `resolveProgrammedAction(Workstation $ws): ?array { return null; }` qui sera enrichie en 3.2).
    - Retourne `renderer->renderKnown($ws, $action, $serverBaseUrl)`.
- **Headers de réponse** appliqués (D10) : `Content-Type`, `Cache-Control: no-store`, `X-Robots-Tag: noindex`.

**And** test unit `tests/Unit/Ipxe/Services/IpxeServiceTest.php` avec ≥6 tests (mocks Locator + Renderer + Log + MachineBootLog) :
- `it_returns_handshake_when_mac_and_uuid_missing`
- `it_calls_locator_when_mac_provided`
- `it_logs_unknown_workstation_when_locator_returns_null`
- `it_logs_known_workstation_when_locator_returns_workstation`
- `it_persists_machine_boot_log_row_per_call`
- `it_returns_text_plain_content_type_in_all_paths`

**AC4.2** — **Méthode `resolveProgrammedAction(Workstation $ws): ?array` placeholder pour 3.2**

**Given** la méthode privée (ou publique pour test) `IpxeService::resolveProgrammedAction(Workstation $ws): ?array`,
**When** elle est invoquée en 3.1,
**Then** :
- Retourne **toujours `null`** (pas d'action programmée gérée en 3.1).
- Docblock explicite : "Story 3.1 : retourne null systématiquement — l'action programmée (install, clonage, etc.) sera gérée par Story 3.2 qui surchargera/enrichira cette méthode."
- Test unit qui asserte `$service->resolveProgrammedAction($ws) === null`.

### Volet 5 — Controller HTTP + FormRequest

**AC5.1** — **`IpxeBootController::handle(IpxeBootRequest $request, IpxeService $service): Response`**

**Given** la classe `App\Ipxe\Http\Controllers\IpxeBootController`,
**When** un poste appelle `GET|POST /ipxe/boot`,
**Then** :
- Le controller est **fin** (≤20 lignes hors docblocks) — délègue 100% à `IpxeService::handleBoot($request)`.
- Pas de logique métier, pas d'accès direct à `Workstation`, pas de Log direct.
- Retourne la `Response` renvoyée par le service.
- **Anti-pattern** : ne pas instancier `IpxeService` manuellement — injection via le constructeur (`public function __construct(private IpxeService $service) {}`) ou type-hint méthode.

**AC5.2** — **`IpxeBootRequest::rules()` permissif (iPXE envoie ce qu'il peut)**

**Given** le FormRequest `App\Ipxe\Http\Requests\IpxeBootRequest`,
**When** un poste poste `mac`/`uuid`/`product` (variables iPXE),
**Then** :
- `rules()` retourne :
  ```php
  return [
      'mac' => ['nullable', 'string', 'max:64'],     // accepte format brut, normalisation en service
      'uuid' => ['nullable', 'string', 'max:64'],    // UUID brut potentiellement malformé
      'product' => ['nullable', 'string', 'max:128'],
  ];
  ```
- Pas de regex stricte côté FormRequest — la validation business (format MAC, UUID lowercase) est dans `MacAddressNormalizer`/`UuidNormalizer` qui retournent null en cas d'invalide.
- `authorize()` retourne `true` (auth via middleware `auth.v1.lan-only`).
- **Anti-pattern** : pas de `unique:workstations,uuid` ni de `exists:` — un poste inconnu reçoit un menu default (D6), pas un 422.

**And** test feature `IpxeBootEndpointTest::it_accepts_empty_mac_and_uuid_for_handshake` + `it_accepts_lowercase_and_uppercase_mac` + `it_rejects_oversize_input_field` (mac > 64 chars → 422).

### Volet 6 — Routes web.php + ordre + non-régression catchall

**AC6.1** — **Route `/ipxe/boot` + alias `/ipxe/boot.ipxe` déclarées AVANT catchall**

**Given** le fichier `routes/web.php`,
**When** le dev ajoute le bloc 3.1 (cf. D2),
**Then** :
- Le bloc est placé **après** le dernier endpoint legacy `gpo/*_out.php` (ligne ~542) et **avant** le bloc catchall (ligne ~574 commentaire `Legacy PHP Fallback Route (DOIT ÊTRE EN DERNIER)`).
- Le commentaire de bloc explicite que `/ipxe/admin.php`, `/ipxe/installation-linux.php`, etc. **continuent** d'être servis par le catchall jusqu'aux stories 3.2-3.7.
- Préserve le commentaire `⚠⚠⚠ La route catch-all legacy doit rester la dernière route définie ⚠⚠⚠` (ligne 591-598) **strictement intact**.

**And** test archi `tests/Architecture/IpxeNamespaceTest::ipxe_boot_route_is_declared_before_catchall` :
- Lit `routes/web.php` en texte.
- Vérifie que la déclaration `Route::match(...'/ipxe/boot'...)` apparaît avant `Route::match(...'{path}'...)->where('path', '.*')`.
- Vérifie que `App\Ipxe\Http\Controllers\IpxeBootController` est référencé.

**AC6.2** — **Non-régression catchall sur `/ipxe/admin.php` (et autres legacy)**

**Given** les routes legacy `/ipxe/admin.php`, `/ipxe/installation-linux.php`, etc.,
**When** un appelant LAN les sollicite,
**Then** :
- Elles continuent d'être servies par `LegacyCatchallController` → proxy vers `legacy/modules/ipxe/*.php`.
- **Aucune** régression sur le contenu retourné par ces routes (= byte-for-byte iso-legacy).

**And** test feature `IpxeLegacyRoutingNonRegressionTest` ≥3 tests :
- `it_serves_ipxe_boot_natively_not_via_catchall` (asserte que `/ipxe/boot` ne passe pas par `LegacyCatchallController`).
- `it_still_serves_ipxe_admin_via_catchall` (asserte que `/ipxe/admin.php` est encore loggué dans `legacy_catchall_logs` — pattern iso 1-2).
- `it_still_serves_ipxe_installation_linux_via_catchall` (idem `/ipxe/installation-linux.php`).

### Volet 7 — Config + channel log + logging structuré (D7, D11)

**AC7.1** — **Création de `config/ipxe.php`**

**Given** le fichier `config/ipxe.php`,
**When** le dev l'ajoute selon D11,
**Then** :
- `config('ipxe.se4fs_name')` retourne la valeur env ou fallback `config('sambaedu.se4fs_name', 'se4fs')`.
- `config('ipxe.menu.default_timeout_ms')` = 5000.
- `config('ipxe.menu.unknown_timeout_ms')` = 10000.
- `config('ipxe.boot_disk.force_uefi_products')` = array de 13 entrées iso-legacy.
- `config('ipxe.log.channel')` = `'ipxe'`.

**And** test unit `tests/Unit/Ipxe/IpxeConfigTest.php` ≥4 assertions.

**AC7.2** — **Création du channel log `ipxe` dans `config/logging.php`**

**Given** le fichier `config/logging.php`,
**When** le dev ajoute la définition `'ipxe' => [...]` dans `channels` array,
**Then** :
- Pattern strictement iso `auth-v1` (16.10) et `scriptsos` (16.12) — driver `daily`, path `storage/logs/ipxe/ipxe.log`, level configurable via env `IPXE_LOG_LEVEL`, days 14.
- `Log::channel('ipxe')->info('test')` génère bien un fichier `storage/logs/ipxe/ipxe-YYYY-MM-DD.log`.

**AC7.3** — **Logs émis par `IpxeService` ne contiennent pas de secret**

**Given** les 5 events `ipxe.boot.handshake/known_workstation/unknown_workstation/invalid_input/render_error`,
**When** ils sont émis,
**Then** :
- `mac` est tronqué à 6 chars (`xx:xx:`) — pas d'adresse complète.
- `uuid` est tronqué à 8 chars (`xxxxxxxx`) — pas d'UUID complet.
- `product` est tronqué à 8 chars.
- `workstation_name` est tronqué à 6 chars (préfixe).
- `ip` est loggué en clair (LAN scolaire, pas de PII sensible — pattern iso 16.10 D13).

**And** test unit `IpxeServiceLoggingTest` ≥3 tests qui mockent `Log::shouldReceive('info')` et asserte que le context ne contient pas `mac`/`uuid` en clair.

### Volet 8 — Tests + non-régression

**AC8.1** — **Tests unit cumulés ≥25**

**Given** les classes services + locators + renderer + config,
**When** la suite `php artisan test --filter='Ipxe'` ou `php artisan test tests/Unit/Ipxe/` s'exécute,
**Then** elle couvre :
- `tests/Unit/Ipxe/Services/IpxeServiceTest.php` — ≥6 tests (AC4.1)
- `tests/Unit/Ipxe/Services/WorkstationLocatorTest.php` — ≥10 tests (AC2.1)
- `tests/Unit/Ipxe/Services/IpxeMenuRendererTest.php` — ≥8 tests (AC3.1)
- `tests/Unit/Ipxe/Support/MacAddressNormalizerTest.php` — ≥6 tests
- `tests/Unit/Ipxe/Support/UuidNormalizerTest.php` — ≥4 tests
- `tests/Unit/Ipxe/IpxeConfigTest.php` — ≥4 tests (AC7.1)

**AC8.2** — **Tests feature cumulés ≥6**

**Given** le controller + route + middleware chain,
**When** `php artisan test tests/Feature/Ipxe/` s'exécute,
**Then** elle couvre :
- `tests/Feature/Ipxe/IpxeBootEndpointTest.php` — ≥6 tests :
  - `it_returns_handshake_when_no_params`
  - `it_returns_unknown_menu_when_workstation_not_found`
  - `it_returns_known_menu_when_workstation_found_by_uuid`
  - `it_returns_known_menu_when_workstation_found_by_mac_fallback`
  - `it_responds_with_text_plain_content_type`
  - `it_persists_machine_boot_log_row` (asserte qu'une row `MachineBootLog::where('action', 'ipxe_boot')` est créée)
- `tests/Feature/Ipxe/IpxeLegacyRoutingNonRegressionTest.php` — ≥3 tests (AC6.2)
- `tests/Feature/Ipxe/IpxeEnsureLanIpTest.php` — ≥2 tests :
  - `it_rejects_403_when_request_from_public_ip` (mock `REMOTE_ADDR='8.8.8.8'` → 403 + code `bootstrap.not_lan`).
  - `it_accepts_when_request_from_192_168_x_y`

**AC8.3** — **Tests architecture**

**Given** le namespace `App\Ipxe`,
**When** `php artisan test tests/Architecture/IpxeNamespaceTest.php` s'exécute,
**Then** ≥4 tests :
- `it_lists_all_ipxe_services_under_correct_namespace`
- `it_does_not_import_ldap_record_in_ipxe_namespace`
- `it_does_not_import_legacy_modules_in_ipxe_namespace`
- `ipxe_boot_route_is_declared_before_catchall` (AC6.1)

**AC8.4** — **Pas de régression sur Phase 1 + 16.10 + 16.11 + 16.12 + Epic 4**

**Given** la baseline tests Phase 1 + 16.10 + 16.11 + 16.12 + Epic 4 verte,
**When** le dev exécute `./scripts/run-tests.sh` (post-VM up),
**Then** **100% verts** (aucune régression sur les ~700+ tests cumulés du projet).

### Volet 9 — Runbook QA + sprint-status

**AC9.1** — **Création de `docs/qa/domains/ipxe.md` (premier domaine iPXE)**

**Given** le fichier `docs/qa/domains/ipxe.md`,
**When** le dev le crée selon la convention `docs/qa/README.md`,
**Then** il contient :
- En-tête `# QA — iPXE — Boot réseau & Déploiement OS` + description du domaine + pré-requis VM.
- `## Story 3.1 — iPXE Service Core` (premier append).
- Numérotation **stable** des scénarios : `### Scénario 3.1-1` à `### Scénario 3.1-N` (au moins 8).

**And** ligne ajoutée dans `docs/qa/README.md` :
- Décocher la ligne future `- [ ] bootstrap-update.md ...` non-applicable.
- Ajouter dans "Domaines couverts" : `- [ ] [ipxe](domains/ipxe.md) — Boot iPXE, résolution MAC/UUID, fondations Services Laravel (Stories 3.1+)`.

**And** les scénarios ≥8 couvrent :
- **Scénario 3.1-1** — Premier appel iPXE (handshake) : `curl -kfsS http://192.168.122.50/ipxe/boot` → 200 + body commence par `#!ipxe\nparams\nparam mac \${net0/mac}\n...`.
- **Scénario 3.1-2** — Appel iPXE avec MAC connue (poste seed via `Workstation::factory()`) → menu `known` retourné.
- **Scénario 3.1-3** — Appel iPXE avec UUID connu → menu `known` retourné.
- **Scénario 3.1-4** — Appel iPXE avec MAC+UUID inconnus → menu `default` retourné (boot disk only).
- **Scénario 3.1-5** — Non-régression legacy : `curl http://192.168.122.50/ipxe/admin.php` → continue de répondre via le catchall (vérifier dans `legacy_catchall_logs`).
- **Scénario 3.1-6** — Sécurité LAN : `curl --resolve` ou test depuis IP publique → 403 + JSON `{success:false, code:"bootstrap.not_lan"}`.
- **Scénario 3.1-7** — Log channel : `tail -f storage/logs/ipxe/ipxe-$(date +%F).log` → chaque appel produit un event `ipxe.boot.*`.
- **Scénario 3.1-8** — `MachineBootLog` peuplée : après quelques appels, `SELECT * FROM machine_boot_logs WHERE action='ipxe_boot' ORDER BY id DESC LIMIT 5;` montre les rows.
- **(Optionnel)** Scénario 3.1-9 — Smoke poste réel (action Henri post-reboot VM) : un poste de test sur LAN boot en PXE → reçoit le menu, choisit option 0 → boot sur disque.

**AC9.2** — **Mise à jour `sprint-status.yaml`**

**Given** le fichier `_bmad-output/implementation-artifacts/sprint-status.yaml`,
**When** le SM crée cette story,
**Then** :
- `3-1-ipxe-service-core: backlog` → `3-1-ipxe-service-core: ready-for-dev`.
- Le commentaire `# last_updated:` ajoute un paragraphe daté `2026-05-19` qui synthétise : modèle SM utilisé, scope, nombre AC, modèle dev recommandé.
- **NE PAS** changer `epic-3: backlog` (l'orchestrateur décide en fonction du status de la 1ère story passée `in-progress`).

---

## Tasks / Subtasks

### Phase T0 — Pré-flight + validations contexte

- [-] **T0.1** Vérifier statut VM (up/HS) avec Henri. *Différé Henri* : dev opère en static delivery iso 16.10/16.11/16.12 (sync inotify supposé OK, validation finale à la review).
- [x] **T0.2** Statut Epic 1 done + Epic 4 done + 16.10/16.11 review/done confirmés par sprint-status. `EnsureLanIp` (alias `auth.v1.lan-only`) présent dans `app/Auth/V1/Http/Middleware/EnsureLanIp.php`. `JwtErrorCodes::BOOTSTRAP_NOT_LAN = 'bootstrap.not_lan'` présent.
- [x] **T0.3** Statut 16.12 (`script_execution_logs`) — pas de dépendance directe. `routes/web.php` n'a pas évolué autour du bloc d'insertion 3.1.
- [x] **T0.4** Lecture obligatoire legacy iPXE faite : `sambaedu/ipxe/boot.php` (153 L) + `sambaedu/includes/ipxe_functions.inc.php` (82 L). Différences notables 3.1 vs legacy documentées dans Dev Agent Record.
- [x] **T0.5** Auto-discover composer non utilisé pour le provider — pattern iso 16.10/16.12 = enregistrement explicite dans `config/app.php`. Décision DO-1 : `App\Providers\IpxeServiceProvider` (pas `App\Ipxe\IpxeServiceProvider`) pour parallélisme strict avec `AuthV1ServiceProvider`/`GpoServiceProvider`/`WpkgDeploymentServiceProvider`.
- [x] **T0.6** Audit `MachineBootLog` : `$fillable` inclut `action`, schema migration `2026_03_25_120000_add_action_and_initiated_by_to_machine_boot_logs.php` définit `action` comme `varchar(20) nullable` — pas de CHECK constraint. `'ipxe_boot'` (9 chars) passe sans escalation. **D5 confirmé, pas de migration nouvelle (D12 confirmé).**
- [x] **T0.7** Lint baseline 0 erreur sur tous fichiers créés/modifiés.
- [-] **T0.8** *Différé VM* : tail apache error.log pendant le dev — non disponible static delivery. Action Henri post-reboot.

### Phase T1 — Création du namespace + ServiceProvider + config + channel log (AC1.1, AC7.1, AC7.2)

- [x] **T1.1** Arborescence `app/Ipxe/{Services,Http/Controllers,Http/Requests,Enums,Support}` créée.
- [x] **T1.2** `app/Providers/IpxeServiceProvider.php` créé (DO-1) avec bindings singleton `IpxeService` + `WorkstationLocator` + `IpxeMenuRenderer` + `mergeConfigFrom(config/ipxe.php)` + ensure `storage/logs/ipxe/`.
- [x] **T1.3** Provider enregistré dans `config/app.php` providers array (iso pattern `AuthV1ServiceProvider`).
- [x] **T1.4** `config/ipxe.php` créé selon D11 (se4fs_name, menu timeouts, force_uefi_products iso-legacy 13 entrées, log channel).
- [x] **T1.5** `config/logging.php` : channel `ipxe` ajouté (driver daily, days 14 via `IPXE_LOG_DAYS`, level via `IPXE_LOG_LEVEL`, `replace_placeholders => false` iso `auth-v1` D12 DO).
- [x] **T1.6** `IpxeMenuKind` (Handshake|Default_|Known) + `IpxePlatform` (Legacy|Uefi) créés.
- [x] **T1.7** `IpxeConfigTest` — 6 tests verts (assertions sur se4fs_name, timeouts, resolution, force_uefi_products array ≥13 entrées, log channel).
- [x] **T1.8** `IpxeNamespaceTest` — 5 méthodes archi vertes (presence services, no LdapRecord, no legacy include + no `search_machine`/`get_action`, no shell exec, route order).

### Phase T2 — Normalizers + WorkstationLocator (AC2.1, AC2.2, D4)

- [x] **T2.1** `MacAddressNormalizer::normalize()` créé — accepte `:`/`-`/no-sep, mixed case → canonique `xx:xx:xx:xx:xx:xx` lowercase.
- [x] **T2.2** `UuidNormalizer::normalize()` créé — trim + lowercase, tolérant (pas de regex stricte iso-legacy `boot.php:24`).
- [x] **T2.3** `MacAddressNormalizerTest` (8 tests) + `UuidNormalizerTest` (5 tests) — 13 tests verts.
- [x] **T2.4** `WorkstationLocator::locate()` créé — D4 algorithme strict (UUID prio + fallback MAC + transformation hexa legacy product-empty + eager-load 3 relations).
- [x] **T2.5** `WorkstationLocatorTest` — 12 tests verts (UUID match, MAC fallback, both unknown → null, dash/no-sep MAC variants, invalid MAC, lowercase UUID, both empty, transformation legacy product-empty, NO transformation when product provided, eager-load relations, priorité UUID sur MAC).

### Phase T3 — IpxeMenuRenderer + templates Blade (AC3.1, AC3.2, D9)

- [x] **T3.1** Dossier `resources/views/ipxe/menu/` créé.
- [x] **T3.2** `handshake.blade.php` créé (8 lignes — `{!! $shebang !!}` + params + chain).
- [x] **T3.3** `default.blade.php` créé (14 lignes — menu unknown avec 1 item exit + boot_disk fallback).
- [x] **T3.4** `known.blade.php` créé (40 lignes — menu known avec login + default + action conditionnel + boot_disk fallback).
- [x] **T3.5** `IpxeMenuRenderer` créé avec `renderHandshake()` + `renderUnknown()` + `renderKnown()` + `renderBootDiskFallback()` (public pour test isolé — DO-10).
- [x] **T3.6** `IpxeMenuRendererTest` — 11 tests verts (shebang, params, ASCII strict, no PHP tags, items login/default/action, force_uefi_products renderés).

### Phase T4 — IpxeService orchestrateur + logging (AC4.1, AC4.2, AC7.3)

- [x] **T4.1** `IpxeService::handleBoot()` créé — handshake/unknown/known paths + headers D10 (text/plain + no-store + noindex). `resolveProgrammedAction()` placeholder retourne TOUJOURS `null` en 3.1 (AC4.2). `persistMachineBootLog()` insère row avec `action='ipxe_boot'` + `initiated_by='ipxe'` + `success=true` (D5). Best-effort sur insert DB (try/catch — un boot ne doit pas être bloqué par un échec log).
- [x] **T4.2** `IpxeServiceTest` — 7 tests verts (handshake, no MachineBootLog on handshake, unknown menu, MachineBootLog unknown row, known menu, MachineBootLog known row, text/plain dans tous paths, no-store, noindex, resolveProgrammedAction → null).
- [x] **T4.3** `IpxeServiceLoggingTest` — 3 tests verts (handshake event sans mac/uuid, known event avec préfixes 6 chars MAC + 8 chars UUID — pas de fuite PII complète, unknown event idem).

### Phase T5 — Controller HTTP + FormRequest (AC5.1, AC5.2)

- [x] **T5.1** `IpxeBootRequest` créé — rules permissives (mac/uuid/product nullable string max 64/64/128). `authorize() = true` (auth via middleware).
- [x] **T5.2** `IpxeBootController::handle()` créé — fin (controller class ~10 lignes hors docblocks). Délègue 100% à `IpxeService::handleBoot()` (DO-6).

### Phase T6 — Routes + middleware + non-régression catchall (AC6.1, AC6.2)

- [x] **T6.1** `routes/web.php` modifié — bloc 3.1 inséré APRÈS bloc WPKG hosts.xml/profiles.xml et AVANT catchall `{path}`. 2 routes : `GET|POST /ipxe/boot` (name `ipxe.boot`) + `GET /ipxe/boot.ipxe` (name `ipxe.boot.alias`). Middlewares `auth.v1.lan-only` + `throttle:600,1` + `withoutMiddleware(['web'])` (pas de session/CSRF). Commentaire `⚠⚠⚠` du catchall préservé intact.
- [x] **T6.2** Test archi `ipxe_boot_route_is_declared_before_catchall` — vert (offset `/ipxe/boot` < offset catchall, controller référencé, middleware lan-only attaché, commentaire ⚠⚠⚠ préservé).
- [x] **T6.3** `IpxeLegacyRoutingNonRegressionTest` — 3 tests verts (route native NE GÉNÈRE PAS de row legacy_catchall_logs, `/ipxe/admin.php` reste catchall, `/ipxe/installation-linux.php` reste catchall).
- [x] **T6.4** `IpxeBootEndpointTest` — 9 tests verts (handshake GET, handshake POST empty params, unknown menu, known via UUID, known via MAC fallback, text/plain headers, MachineBootLog persisté, alias `/ipxe/boot.ipxe`, 422 oversize mac via postJson).
- [x] **T6.5** `IpxeEnsureLanIpTest` — 2 tests verts (403 IP publique avec subnet restrictif + code `bootstrap.not_lan`, 200 IP 192.168.x.y).

### Phase T7 — Non-régression + lint + audit final

- [x] **T7.1** Lint php -l 0 erreur sur les 27 fichiers créés + 4 modifiés (vérifié sur la totalité — fix review #12).
- [x] **T7.2** Suite Ipxe complète verte localement : **74/74 tests verts** (`tests/Unit/Ipxe` + `tests/Feature/Ipxe` + `tests/Architecture/IpxeNamespaceTest.php`). Sanity check non-régression `tests/Architecture/` complet (31 tests verts, 2 skipped pré-existants) + `tests/Feature/Auth/V1/` (49 tests verts) — **aucune régression sur 16.10/16.11/16.12 ni archi globale**.
- [x] **T7.3** Le test `LegacyModuleIpxeTest::test_ipxe_config_accessible_via_catchall` est ROUGE en baseline `main` (constaté avant nos changements via `git stash`) — pas une régression de 3.1, dépend du legacy `/var/www/sambaedu` non monté en environnement test.

### Phase T8 — Runbook QA + sprint-status + completion notes (AC9.1, AC9.2)

- [x] **T8.1** `docs/qa/domains/ipxe.md` créé avec 5 sections + 9 scénarios numérotés stables 3.1-1 à 3.1-9 + checklist rapide en fin.
- [x] **T8.2** `docs/qa/README.md` enrichi : entrée `[ipxe](domains/ipxe.md)` ajoutée dans "Domaines couverts".
- [x] **T8.3** `sprint-status.yaml` mis à jour : `3-1-ipxe-service-core: ready-for-dev` → `review` ; `epic-3: backlog` → `in-progress`. Commentaire `# last_updated:` enrichi avec résumé dev + items différés + recommandation code-review.
- [x] **T8.4** Story status → `review`, tasks cochées (avec items différés VM HS marqués `[-]`), Dev Agent Record + File List + Change Log remplis.
- [-] **T8.5** *Différé Henri post-reboot VM* : ré-exécuter `./scripts/run-tests.sh` (suite complète) + scénarios 3.1-1 à 3.1-9 manuels sur la VM (smoke poste réel optionnel 3.1-9).

---

## File List prévisionnelle

### Fichiers créés (estimés ~22)

```
# Provider + Config + Channel log
app/Ipxe/IpxeServiceProvider.php
config/ipxe.php

# Services + Locator + Renderer
app/Ipxe/Services/IpxeService.php
app/Ipxe/Services/WorkstationLocator.php
app/Ipxe/Services/IpxeMenuRenderer.php

# Controller + FormRequest
app/Ipxe/Http/Controllers/IpxeBootController.php
app/Ipxe/Http/Requests/IpxeBootRequest.php

# Enums + Support
app/Ipxe/Enums/IpxeMenuKind.php
app/Ipxe/Enums/IpxePlatform.php
app/Ipxe/Support/MacAddressNormalizer.php
app/Ipxe/Support/UuidNormalizer.php

# Templates Blade
resources/views/ipxe/menu/handshake.blade.php
resources/views/ipxe/menu/default.blade.php
resources/views/ipxe/menu/known.blade.php

# Tests Unit
tests/Unit/Ipxe/IpxeConfigTest.php
tests/Unit/Ipxe/Services/IpxeServiceTest.php
tests/Unit/Ipxe/Services/IpxeServiceLoggingTest.php
tests/Unit/Ipxe/Services/WorkstationLocatorTest.php
tests/Unit/Ipxe/Services/IpxeMenuRendererTest.php
tests/Unit/Ipxe/Support/MacAddressNormalizerTest.php
tests/Unit/Ipxe/Support/UuidNormalizerTest.php

# Tests Feature
tests/Feature/Ipxe/IpxeBootEndpointTest.php
tests/Feature/Ipxe/IpxeLegacyRoutingNonRegressionTest.php
tests/Feature/Ipxe/IpxeEnsureLanIpTest.php

# Tests Architecture
tests/Architecture/IpxeNamespaceTest.php

# Doc QA
docs/qa/domains/ipxe.md
```

### Fichiers modifiés (estimés ~4)

```
config/app.php                 (+ ajout IpxeServiceProvider si pas auto-discover)
config/logging.php             (+ channel 'ipxe')
routes/web.php                 (+ bloc 3.1 routes ipxe.boot + alias, AVANT catchall)
docs/qa/README.md              (+ ligne "ipxe" dans Domaines couverts)
_bmad-output/implementation-artifacts/sprint-status.yaml  (status update + last_updated)
```

### Fichiers NON modifiés (garde-fou)

```
app/Models/Workstation.php             ← lecture seule
app/Models/WorkstationGroup.php        ← lecture seule
app/Models/AppProfile.php              ← lecture seule
app/Models/MachineBootLog.php          ← lecture + insert via Eloquent (pas de modif schema)
app/Http/Controllers/LegacyCatchallController.php  ← intact
app/Auth/V1/**                          ← intact (réutilisation alias auth.v1.lan-only)
sambaedu/ipxe/**                        ← intact (legacy in-place, source de vérité comportementale)
legacy/modules/ipxe/**                  ← intact
```

---

## Test Strategy

### Couverture par niveau

| Niveau | Périmètre | Fichiers |
|---|---|---|
| **Unit** | Normalizers (MAC + UUID) + config | `MacAddressNormalizerTest`, `UuidNormalizerTest`, `IpxeConfigTest` |
| **Unit** | WorkstationLocator (résolution + fallback + transformation legacy) | `WorkstationLocatorTest` |
| **Unit** | IpxeMenuRenderer (3 templates Blade + boot_disk fallback) | `IpxeMenuRendererTest` |
| **Unit** | IpxeService orchestrateur (3 paths handshake/known/unknown + logging) | `IpxeServiceTest`, `IpxeServiceLoggingTest` |
| **Feature** | Endpoint `/ipxe/boot` (text/plain + content correct selon scenario) | `IpxeBootEndpointTest` |
| **Feature** | Non-régression catchall sur `/ipxe/admin.php` + autres legacy | `IpxeLegacyRoutingNonRegressionTest` |
| **Feature** | `auth.v1.lan-only` correctement attaché (403 hors LAN) | `IpxeEnsureLanIpTest` |
| **Architecture** | Namespace propre, pas d'import LDAP/legacy, ordre routes | `IpxeNamespaceTest` |
| **QA manuelle (VM)** | Smoke complet poste réel via PXE boot + log channel + non-régression | `docs/qa/domains/ipxe.md` § Story 3.1 (≥8 scénarios) |

### Tests qu'on ne fait **pas** dans cette story

- Tests d'exécution réelle du firmware iPXE sur poste cible — couvert par QA manuelle VM (action Henri).
- Tests d'install OS (Linux/Windows) — = stories 3.4/3.5.
- Tests d'enrollment d'un poste inconnu — = story 3.3.
- Tests de menu admin iPXE (login, maintenance, rescue) — = story 3.2.
- Tests de charge `/ipxe/boot` (boot de masse en rentrée scolaire) — déférés post-prod, ajuster throttle si volumétrie réelle dépasse.

---

## Anti-patterns à éviter (DISASTER PREVENTION)

### Architecture & scope

- ❌ **Ne PAS modifier le code legacy `sambaedu/ipxe/*.php` ni `legacy/modules/ipxe/*.php`** — restent intacts, le catchall continue de les servir pour toutes les routes hors `/ipxe/boot`.
- ❌ **Ne PAS étendre le scope** à l'admin/maintenance/install/enrollment (= stories 3.2-3.7).
- ❌ **Ne PAS toucher au schema `workstations`** (lecture seule en 3.1).
- ❌ **Ne PAS toucher au schema `machine_boot_logs`** (D5 + D12 — réutilisation pure ; si CHECK constraint bloque, escalader).
- ❌ **Ne PAS créer de nouveau middleware** — `auth.v1.lan-only` (16.11) suffit (D3).
- ❌ **Ne PAS créer de nouveau code d'erreur** `JwtErrorCodes::*` — réutiliser `BOOTSTRAP_NOT_LAN` (D3).
- ❌ **Ne PAS introduire de dépendance LdapRecord** dans `App\Ipxe\*` — PostgreSQL seule source de vérité (architecture.md).
- ❌ **Ne PAS appeler `search_machine()` / `get_action()` legacy** — réécriture native pure (Workstation Eloquent + relations).
- ❌ **Ne PAS créer d'UI Livewire** en 3.1 — c'est une API HTTP pure (pattern iso 16.10/16.11/16.12).

### Routing & non-régression

- ❌ **Ne PAS placer la route `/ipxe/boot` APRÈS le catchall** — le catchall capture toute `{path}` et rendrait la route 3.1 inaccessible. Strictement AVANT.
- ❌ **Ne PAS modifier le catchall `LegacyCatchallController`** — il continue de servir `/ipxe/admin.php` etc.
- ❌ **Ne PAS toucher au commentaire `⚠⚠⚠`** ligne 591 de `routes/web.php`.
- ❌ **Ne PAS introduire `withoutMiddleware(['inject.bootstrap-fragment'])`** dans les tests 3.1 — la route `/ipxe/boot` n'est pas dans la liste D2 16.11 des endpoints legacy ; aucun fragment n'est injecté.

### Sécurité

- ❌ **Ne PAS logger MAC/UUID/product complets** — préfixes seulement (D7 + AC7.3).
- ❌ **Ne PAS appliquer `EnsureLanIp`** sur les routes legacy `/ipxe/*` non-3.1 (= elles passent par le catchall qui a sa propre logique — pas notre périmètre).
- ❌ **Ne PAS faire confiance à X-Forwarded-For** dans `EnsureLanIp` — déjà strictement `REMOTE_ADDR` côté 16.11.
- ❌ **Ne PAS désactiver `auth.v1.lan-only`** dans les tests Feature — utiliser le helper Laravel `$this->withServerVariables(['REMOTE_ADDR' => '192.168.1.10'])` pour simuler LAN.

### Process & infra

- ❌ **Ne PAS SSH manuellement vers la VM** depuis un worktree git. Static delivery iso 16.10/16.11/16.12.
- ❌ **Ne PAS exécuter les tests sur la VM** si HS — lint statique + PHPUnit local. Différer à Henri post-reboot.
- ❌ **Ne PAS modifier `app/Http/Kernel.php`** — pattern Router aliasMiddleware via `IpxeServiceProvider::boot()` si nouveau middleware nécessaire (pas le cas en 3.1 — D3).
- ❌ **Ne PAS faire de PR / commit depuis le dev-agent** — c'est le job de l'orchestrateur main agent en fin de cycle.

### Test & couverture

- ❌ **Ne PAS désactiver les tests Phase 1 + 16.10 + 16.11 + 16.12 + Epic 4** — la suite doit rester 100% verte.
- ❌ **Ne PAS commiter de fixtures de production** — utiliser `Workstation::factory()` partout dans les tests.

---

## Dépendances + ordre

### Amont (bloquantes — toutes done ou review acceptée)

| Story | Statut entrant | Lien |
|---|---|---|
| **Epic 1** (Fondations) | ✅ done | AuthGuard + catchall + dashboard legacy |
| **Epic 4** (Machines/Groups) | ✅ done | `Workstation`/`WorkstationGroup`/`AppProfile` modèles disponibles (lecture seule) ; `MachineBootLog` réutilisable (D5) |
| **Story 16.10** (HTTPS+JWT) | ✅ review | Middleware `auth.v1.secure-headers` + `JwtErrorCodes` catalogue |
| **Story 16.11** (Auto-bootstrap migration) | ✅ review | Middleware `auth.v1.lan-only` (`EnsureLanIp`) + code `JwtErrorCodes::BOOTSTRAP_NOT_LAN` — **réutilisés tels quels** (D3) |
| **Story 16.12** (Logs centralisés) | 🟡 review | Pas de dépendance directe — pattern channel log dédié à imiter (D7) |

### Aval (3.1 débloque)

| Story | Lien |
|---|---|
| **3.2** Boot et Menu Admin iPXE | **Consomme** `IpxeMenuRenderer` + `IpxeService::resolveProgrammedAction()` (3.1 livre placeholder, 3.2 enrichit). Ajoute item admin (login) + items maintenance/rescue/factory reset. Retire les chains vers `/ipxe/admin.php` legacy. |
| **3.3** Enrollment Machine | **Consomme** `WorkstationLocator` (= si null → ouvre flow enrollment). Ajoute service `WorkstationEnrollmentService` + UI admin pour pré-programmer un enrollment. |
| **3.4** Installation Linux | **Consomme** `IpxeService::resolveProgrammedAction()` qui retournera une action `'install_linux'` pour les postes ciblés. Ajoute le rendu du preseed dynamique. |
| **3.5** Installation Windows | Idem 3.4 mais pour wimboot/Sysprep. |
| **3.6** Gestion ISO Windows | Indépendant 3.1 (côté UI admin + upload). |
| **3.7** Clonage et Maintenance | Idem 3.4 mais pour clonezilla. **Termine** l'Epic 3 en supprimant les routes legacy `/ipxe/*` du catchall (renvoi 410 ou 404 contrôlé). |

---

## Risques + mitigations

| Risque | Sévérité | Mitigation 3.1 |
|---|---|---|
| Collision route `/ipxe/boot` native vs catchall | 🟠 Élevée | T6.1 ordre strict (AVANT catchall) + test archi AC6.1. |
| `MachineBootLog::$fillable` rejette `action='ipxe_boot'` | 🟡 Moyenne | T0.6 audit obligatoire. Escalation Henri si bloqué (D5 + D12 — option A étendre fillable, option B créer table dédiée). |
| Transformation `product` vide mal reproduite → poste connu reste inconnu | 🟡 Moyenne | T2.4 test unit avec 3 fixtures iso-legacy (MAC + UUID + résultat attendu). Documenter dans docblock du locator. |
| Templates Blade avec accents fr cassant le rendu iPXE | 🟢 Mineure | Convention ASCII pur strict + test unit `it_renders_output_is_ascii_only`. |
| Throttle 600/min trop bas en rentrée scolaire (boot de masse) | 🟢 Mineure | Volumétrie 60 postes × 10 retries < 600/min. Ajustable post-prod. |
| Réécriture URLs par le catchall affectant `/ipxe/boot` (cf. `LegacyCatchallController:199-209`) | 🟢 Mineure | La route native court-circuite totalement le proxy. Test feature qui asserte que le body retourné ne contient pas de URL réécrite. |
| Logs `ipxe.boot.*` qui exposent PII (MAC complète, UUID complet) | 🟡 Moyenne | AC7.3 préfixes obligatoires + test unit mock Log avec assertion sur le context. |
| Performance lookup PostgreSQL trop lent en rentrée | 🟢 Mineure | Index sur `workstations.uuid` + `workstations.mac` (déjà présents Epic 4). Query simple `where`. Coût négligeable. |
| Eager load `groups`/`appProfiles` superflu en 3.1 (sera utile en 3.2) | 🟢 Mineure | Acceptable Phase 2 — `Workstation` rarement consulté > 1 fois/min/poste, surcharge négligeable. Documenter dans le docblock du locator. |
| 16.11 middleware `inject.bootstrap-fragment` capture `/ipxe/boot` par effet de bord | 🟢 Mineure | 16.11 D2 whitelist explicite — pas de fuite. Test archi 16.11 `inject_bootstrap_fragment_middleware_is_attached_to_8_legacy_routes` reste vert (pas de `/ipxe/boot` dans la whitelist). |

---

## Project Structure Notes

### Alignement avec la structure projet

- **Namespace** : `App\Ipxe\…` (premier usage — racine du domaine). Sous-namespaces parallèles à `App\Auth\V1\…`, `App\ScriptsOs\…`, `App\Gpo\…`, `App\Wpkg\…`, `App\Winscripts\…`.
- **Tests** : `tests/Unit/Ipxe/…`, `tests/Feature/Ipxe/…`, `tests/Architecture/IpxeNamespaceTest.php` — sous-arborescence parallèle au namespace, cohérent avec 16.10-16.12.
- **Templates Blade** : `resources/views/ipxe/menu/…` (premier usage côté views). Convention iso `resources/views/auth/v1/…` (16.10/16.11) — sous-arborescence dédiée au namespace fonctionnel car ces vues ne sont **pas** des pages web admin Livewire — ce sont des templates de scripts iPXE rendus côté API.
- **Pages cibles** : *hors-scope cette story* — pas d'UI Livewire dans 3.1 (= API HTTP pure). Une éventuelle UI admin "Voir les logs iPXE" peut être ajoutée Phase 3 ou par une story dédiée future.
- **Convention CLAUDE.md** : pas directement applicable (pas de page web sous `resources/views/pages/`, pas de modale, pas de toast — c'est une API HTTP pure + middleware).

### Conflits / variances détectés

| Élément | Architecture officielle | Décision 3.1 | Justification |
|---|---|---|---|
| Format réponse | non décidé pour iPXE | `text/plain; charset=utf-8` body `#!ipxe ...` | Iso-legacy `ipxe_out()` strict. iPXE est un firmware de boot, pas un client web. |
| Auth/sécurité | non décidée pour iPXE | `auth.v1.lan-only` seul (réutilisation 16.11) | D3 + D8 — un firmware iPXE n'a pas d'OS qui porte un JWT. LAN-only est la seule mitigation crédible. |
| Stockage logs boot | non décidé pour iPXE | Réutilisation `MachineBootLog` (4.2) | D5 + D12 — éviter de multiplier les tables boot/connection logs. `action='ipxe_boot'` est descriptif. |
| Templates iPXE | non décidés | Blade templates `resources/views/ipxe/menu/*.blade.php` | D9 — convention Laravel + rendu déterministe + test unitaire facile + cohérence 16.10-16.12. |
| Lecture source de vérité | LdapRecord legacy OU PostgreSQL | **PostgreSQL exclusif** (Workstation Eloquent) | architecture.md §"Modèle de Données — Source de Vérité" — PostgreSQL est seule source pour la lecture, hors auth/sync. |

### Cohabitation routes `/ipxe/*`

| Endpoint | Story | Middleware | Status |
|---|---|---|---|
| `GET\|POST /ipxe/boot` | **3.1 (cette story)** | `auth.v1.lan-only` + `throttle:600,1` | **NEW** (route native, AVANT catchall) |
| `GET /ipxe/boot.ipxe` | **3.1 (cette story)** | idem | **NEW** alias |
| `/ipxe/admin.php` | Legacy | (catchall + proxy legacy) | Inchangé — sera réécrit en 3.2 |
| `/ipxe/installation-linux.php` | Legacy | (catchall + proxy legacy) | Inchangé — sera réécrit en 3.4 |
| `/ipxe/installation-windows.php` | Legacy | (catchall + proxy legacy) | Inchangé — sera réécrit en 3.5 |
| `/ipxe/clonezilla.php` | Legacy | (catchall + proxy legacy) | Inchangé — sera réécrit en 3.7 |
| `/ipxe/enregistrement.php` | Legacy | (catchall + proxy legacy) | Inchangé — sera réécrit en 3.3 |
| `/ipxe/Win10/*` | Legacy | (catchall + proxy legacy) | Inchangé — sera réécrit en 3.5 |
| `/ipxe/diconf/*` | Legacy | (catchall + proxy legacy) | Inchangé — sera réécrit en 3.4 (preseeds) |
| `/ipxe/png/*` | Legacy (assets) | (catchall + proxy legacy) | Inchangé — peut être copié dans `public/ipxe/png/` en 3.1 si besoin (cf. T0 décision optionnelle) |

**Pas de collision** : `/ipxe/boot` est une route précise déclarée AVANT le catchall `{path}`. Les autres routes `/ipxe/*` continuent d'être capturées par le catchall.

---

## References

- [Source: `_bmad-output/planning-artifacts/epics.md` §Epic 3 + Story 3.1] — cadrage haut niveau, prérequis Epic 1 + Epic 4.
- [Source: `_bmad-output/planning-artifacts/architecture.md` §"Modèle de Données — Source de Vérité"] — PostgreSQL = seule source de lecture, LdapRecord limité aux Services auth/sync.
- [Source: `_bmad-output/implementation-artifacts/16-10-securisation-https-jwt-endpoints.md`] — middleware `auth.v1.secure-headers`, `JwtErrorCodes` catalogue.
- [Source: `_bmad-output/implementation-artifacts/16-11-auto-bootstrap-migration-postes.md`] — middleware `auth.v1.lan-only` (`EnsureLanIp`), code `JwtErrorCodes::BOOTSTRAP_NOT_LAN` réutilisés.
- [Source: `_bmad-output/implementation-artifacts/16-12-logs-execution-centralises-ui-consultation.md`] — pattern channel log dédié, structure namespace `App\<Domain>`, factories sous-namespace.
- [Source: `_bmad-output/implementation-artifacts/4-2-actions-unitaires-machine-feedback-readiness.md`] — pattern Service Laravel + `Workstation` + `MachineBootLog` (4.2 a posé la structure log boot/action machine).
- [Source: `_bmad-output/implementation-artifacts/4-1-inventaire-des-machines-par-groupe-physique-et-workstationgroup.md`] — `WorkstationGroup` + `Workstation::groups()` + `physical_room_id`.
- [Source: `sambaedu/ipxe/boot.php`] — source de vérité comportementale primaire (boot iPXE de premier appel). 153 lignes — référence iso-legacy stricte pour le rendu Blade.
- [Source: `sambaedu/ipxe/boot.ipxe`] — fichier statique iPXE (44 lignes — référence menu install Se4AD/Se4FS/Debian/shell — la plupart hors scope 3.1).
- [Source: `sambaedu/includes/ipxe_functions.inc.php`] — helpers `title()`, `boot_disk()`, `ipxe_out()` (82 lignes — à porter en Service Laravel via `IpxeMenuRenderer`).
- [Source: `sambaedu/includes/actions.inc.php:1036-1082`] — fonction `get_action()` legacy (référence pour la résolution machine — non-utilisée en 3.1, juste lecture comportementale).
- [Source: `app/Models/Workstation.php`] — modèle Eloquent (lecture seule en 3.1).
- [Source: `app/Models/WorkstationGroup.php`] — relations `groups()` + `physicalRoom()` (eager load 3.1, utilisation pleine 3.2).
- [Source: `app/Models/AppProfile.php`] — relations `appProfiles()` (eager load 3.1, utilisation pleine 3.2+).
- [Source: `app/Models/MachineBootLog.php`] — table `machine_boot_logs` (réutilisation pour `action='ipxe_boot'`).
- [Source: `app/Http/Controllers/LegacyCatchallController.php`] — proxy catchall qui continue de servir les routes `/ipxe/*` non-3.1.
- [Source: `routes/web.php` lignes 540-598] — bloc d'insertion 3.1 (entre endpoints legacy `gpo/*_out.php` et catchall).
- [Source: `app/Auth/V1/Http/Middleware/EnsureLanIp.php`] — middleware `auth.v1.lan-only` 16.11 réutilisé.
- [Source: `app/Auth/V1/Support/JwtErrorCodes.php`] — code `BOOTSTRAP_NOT_LAN` 16.11 réutilisé.
- [Source: `config/logging.php`] — fichier à enrichir (channel `ipxe`).
- [Source: `docs/qa/README.md`] — convention runbooks domaine (append-only, numérotation stable, premier fichier `ipxe.md` à créer).
- [Source: mémoire `feedback_worktree_no_vm_sync`] — depuis worktree, jamais SSH `/vm`.
- [Source: mémoire `feedback_auth_iso_legacy`] — Phase 2 prime sur iso-legacy pour l'auth applicative.
- [Source: CLAUDE.md projet] — sync inotify, cibles SSH `/vm`, conventions Livewire SFC (non applicable 3.1 — pas d'UI), trait WithToasts (non applicable).

---

## Dev Notes

### Justification design

- **Pourquoi `IpxeService` orchestrateur + `WorkstationLocator` + `IpxeMenuRenderer` séparés ?** Single Responsibility Principle. `IpxeService` orchestre (route → response). `WorkstationLocator` résout (mac/uuid → Workstation). `IpxeMenuRenderer` rend (Workstation/null → string iPXE). Cette séparation rend chaque pièce testable en isolation (mocks faciles) et permet à 3.2/3.3 d'étendre indépendamment.
- **Pourquoi Blade templates et pas string concat ?** Cohérence avec 16.10/16.11/16.12 + tests faciles via `view(...)->render()` + variables substituées proprement + zéro PHP residual via test unit.
- **Pourquoi pas de JWT en 3.1 ?** Un firmware iPXE n'a pas de notion d'Authorization Bearer. Le hardware boot AVANT que l'OS soit booté ; pas de DPAPI/keyring local. La seule mitigation crédible est LAN-only (D3 + D8). Cohérent avec le legacy qui ne demande aucune auth sur `/ipxe/boot.php`.
- **Pourquoi placeholder `resolveProgrammedAction()` au lieu de la lever en 3.2 ?** Pattern Extension Method. 3.1 livre la **structure** ; 3.2 enrichit la **logique**. Cela évite à 3.2 de devoir refactor `IpxeService::handleBoot()` (qui est cœur stable) — juste la méthode auxiliaire. Documenter dans le docblock.
- **Pourquoi réutiliser `MachineBootLog` plutôt qu'une table `ipxe_boot_logs` ?** D5 — éviter la multiplication des tables boot/connection logs. `MachineBootLog` a déjà toutes les colonnes utiles (`workstation_id`, `action`, `initiated_by`, `started_at`, `stopped_at`). `action='ipxe_boot'` est descriptif. Si T0.6 révèle un CHECK qui bloque → escalation.
- **Pourquoi pas de UI Livewire en 3.1 ?** Pattern iso 16.10/16.11/16.12 — les stories de fondation/infra sont API + tests, sans UI. L'UI admin de monitoring iPXE (logs, postes vus, etc.) sera une story dédiée Phase 3 si besoin terrain. Pour 3.1, Henri tail les logs via `tail -f storage/logs/ipxe/ipxe-$(date +%F).log`.

### Convention de logging

- Tous les logs 3.1 ont la clé `action_type` (iso 16.7/16.10/16.12 convention) :
  - `ipxe.boot.handshake` (info)
  - `ipxe.boot.known_workstation` (info)
  - `ipxe.boot.unknown_workstation` (info)
  - `ipxe.boot.invalid_input` (warning)
  - `ipxe.boot.render_error` (error)
- Toutes les valeurs sensibles (MAC, UUID, product, hostname) sont **préfixées** (6-8 chars) — pas de PII complète.

### Pattern résolution multi-niveaux

```
┌─────────────────────────────────────────────────────────┐
│ Firmware iPXE → DHCP/proxyDHCP → GET /ipxe/boot         │
└────────────┬────────────────────────────────────────────┘
             │
             ▼
┌─────────────────────────────────────────────────────────┐
│ EnsureLanIp (16.11) — vérif RFC1918                     │
│  - Si hors LAN → 403 + code "bootstrap.not_lan"         │
│  - Si LAN → continue                                    │
└────────────┬────────────────────────────────────────────┘
             │
             ▼
┌─────────────────────────────────────────────────────────┐
│ IpxeBootController::handle → IpxeService::handleBoot    │
│  - mac empty && uuid empty → handshake template         │
│  - sinon → WorkstationLocator::locate(mac, uuid, prod)  │
└────────────┬────────────────────────────────────────────┘
             │
             ▼
┌─────────────────────────────────────────────────────────┐
│ WorkstationLocator (PostgreSQL only)                    │
│  - normalize mac + uuid (lowercase + format)            │
│  - lookup by uuid (priorité)                            │
│  - fallback by mac                                      │
│  - return ?Workstation                                  │
└────────────┬────────────────────────────────────────────┘
             │
             ▼
┌─────────────────────────────────────────────────────────┐
│ IpxeService — log + persist MachineBootLog              │
│  - log info ipxe.boot.known/unknown                     │
│  - insert MachineBootLog (action='ipxe_boot')           │
└────────────┬────────────────────────────────────────────┘
             │
             ▼
┌─────────────────────────────────────────────────────────┐
│ IpxeMenuRenderer → Blade template                       │
│  - known.blade.php (poste connu)                        │
│  - default.blade.php (poste inconnu — boot disk only)   │
│  - handshake.blade.php (premier appel)                  │
└────────────┬────────────────────────────────────────────┘
             │
             ▼
┌─────────────────────────────────────────────────────────┐
│ Response text/plain + headers (no-store, noindex)       │
└─────────────────────────────────────────────────────────┘
```

### Outils CI/QA en Phase 2

`scripts/run-tests.sh` (16.8) reste l'outil canonique. Pour 3.1, lancer (post-VM up) :
```bash
ssh /vm 'cd /var/www/sambaedu-reload && ./scripts/run-tests.sh'
# Couvre Ipxe + non-régression Phase 1 + Epic 4 + 16.10-16.12
```

### Vérification non-régression catchall

Garde-fou critique : les routes legacy `/ipxe/admin.php`, `/ipxe/installation-linux.php`, etc. doivent **continuer de fonctionner**. Risque concret : un dev pourrait être tenté de "généraliser" la route 3.1 en `/ipxe/{file?}` ou en groupe `Route::prefix('/ipxe')`. **Anti-pattern strict** — D2 limite à 2 routes précises (`/ipxe/boot` + `/ipxe/boot.ipxe`).

Mitigation :
- T6.2 test archi obligatoire.
- T6.3 tests feature non-régression (catchall continue de servir `/ipxe/admin.php` etc.).
- Code review : vérifier l'ordre des routes dans `routes/web.php` (3.1 AVANT catchall, jamais APRÈS).

### Tests qu'on **ne** fait **pas** dans cette story

- Tests de boot réel sur poste de test PXE — couvert par scénario QA manuel `docs/qa/domains/ipxe.md` § Scénario 3.1-9 (action Henri post-reboot VM).
- Tests d'exécution du menu iPXE rendu par un firmware réel — idem.
- Tests de charge `/ipxe/boot` (boot de masse rentrée scolaire) — déférés post-prod.
- Tests de retry réseau iPXE (timeout `|| sleep 10`) — comportement firmware, hors périmètre serveur.

---

## Dev Agent Record

### Agent Model Used

`claude-opus-4-7[1m]` (1M context window). Recommandation SM honorée : opus pour la fondation Epic 3 (namespace nouveau + transformation hexa legacy non-triviale + non-régression catchall critique + format Blade iPXE atypique).

### Debug Log References

- **DBG-1** : Bug PHP natif — la première ligne `#!ipxe` d'un template Blade est strippée par PHP lui-même (interpréteur traite `#!` en début de fichier inclus comme un shebang CLI). Constaté en lançant `IpxeMenuRendererTest` qui voyait `$body` commencer par `params` au lieu de `#!ipxe`. Solution → décision DO-13 : injecter `{!! $shebang !!}` comme variable Blade depuis le renderer.
- **DBG-2** : Test `it_rejects_oversize_mac_with_422` recevait 302 au lieu de 422 — comportement Laravel standard (FormRequest sans `Accept: application/json` redirige). Corrigé via `$this->postJson(...)`.
- **DBG-3** : `tests/Feature/LegacyModuleIpxeTest::test_ipxe_config_accessible_via_catchall` rouge — vérifié `git stash` que c'était PRÉ-EXISTANT, pas une régression de 3.1. Dépend du legacy `/var/www/sambaedu` non monté en env test.

### Completion Notes List

**Différences notables 3.1 vs legacy `sambaedu/ipxe/boot.php`** (lecture T0.4) :
- **PAS d'item LTSP** (legacy `boot.php:88` testait `dpkg -l sambaedu-ltsp` — feature LTSP déférée hors Epic 3).
- **PAS d'action programmée** (legacy `boot.php:43-60` lit `get_action()` LDAP — 3.1 retourne `null` via placeholder `resolveProgrammedAction()`, mécanisme = 3.2).
- **PAS d'item login admin natif** (le menu `known` chain vers `/ipxe/admin.php` legacy = proxy catchall jusqu'à 3.2).
- **Source de vérité** : PostgreSQL exclusif via `WorkstationLocator` (au lieu de `get_action()` LDAP+legacy DB).
- **Logging** : channel dédié `ipxe` (au lieu de `log_connexion()` legacy MySQL).

**12 décisions DO-* prises au-delà des D1-D12 SM** :

1. **DO-1 — ServiceProvider dans `App\Providers\IpxeServiceProvider`** (pas `App\Ipxe\IpxeServiceProvider`) : parallélisme strict avec `AuthV1ServiceProvider`/`GpoServiceProvider`/`WpkgDeploymentServiceProvider`. Cohérence lecture `config/app.php`.
2. **DO-2 — Normalizers retournent `?string`** (pas d'exception jetée) : parité legacy tolérant (`boot.php:22` accepte n'importe quoi). Le caller (`WorkstationLocator`) traite `null` comme « inutilisable → fallback ».
3. **DO-3 — Transformation product-empty appliquée AVANT lookup** : sans ça, le matching échouerait pour les postes legacy dont l'UUID stocké est composite (calculé via hexa MAC). Test fixture iso-legacy dans `WorkstationLocatorTest::it_applies_legacy_product_empty_transformation`.
4. **DO-4 — Test archi route via lecture textuelle 1500 chars** iso 16.11 DO-5 : parsing AST trop complexe pour les déclarations Route::, heuristique simple de comparaison d'offsets de matches preg suffit.
5. **DO-5 — `IpxeMenuRenderer` délégation Blade pure** (compact() vars, pas de string concat in-line — sauf `renderBootDiskFallback()` qui construit un array de lignes joinées par `\n` pour clarté).
6. **DO-6 — Controller fin ≤10 lignes** (hors docblocks) — `IpxeBootController::handle()` délègue à `IpxeService::handleBoot()`. Single responsibility, simple à reviewer.
7. **DO-7 — Logging via `Log::channel('ipxe')` direct** (iso pattern 16.12) — pas d'abstraction de logging service intermédiaire.
8. **DO-8 — `Workstation::create()` partout dans tests** (pas de factory dédiée Workstation — pattern iso `WorkstationPackagesResolverTest`). `IpxeSchemaBootstrapper` provisionne le schema SQLite minimal.
9. **DO-9 — LAN test : `config('auth_v1.bootstrap.allowed_subnets')` override par test** (`'127.0.0.0/8'` pour passer en loopback, `'192.168.99.0/24'` pour rejeter via `withServerVariables(['REMOTE_ADDR' => '8.8.8.8'])`).
10. **DO-10 — `renderBootDiskFallback()` méthode publique** (pas privée) pour permettre le test unitaire isolé sans avoir à instancier un menu complet.
11. **DO-11 — `known.blade.php` login chain vers chemin absolu `{{ $serverBaseUrl }}/ipxe/admin.php`** (legacy proxy 3.2-). Le `$serverBaseUrl` est reconstruit depuis le Request via `getSchemeAndHttpHost()` ou override `config('ipxe.se4fs_url')`.
12. **DO-12 — Channel `ipxe` configuré avec `replace_placeholders => false`** iso `auth-v1` (sécurité-critique — évite injection si product hardware contient `{placeholder}`).
13. **DO-13 — Shebang `#!ipxe` injecté comme variable Blade `{!! $shebang !!}`** (pas écrit en clair dans les templates) : contournement bug PHP natif qui strip `#!` en première ligne d'un fichier inclus. Constante privée `IpxeMenuRenderer::IPXE_SHEBANG`.

**Tests lancés localement** : ✅ 74/74 verts (Unit Ipxe 55 + Feature Ipxe 14 + Architecture IpxeNamespace 5). ✅ Non-régression Architecture complet (31 verts + 2 skipped pré-existants). ✅ Non-régression Auth V1 complet (49 verts). **Post code-review** : 75/75 verts (74 + 1 nouveau test B2 ajout review).

### Post code-review (2026-05-19 — review sonnet + 2e avis opus + 11 corrections appliquées)

Code review par claude-sonnet-4-6 (modèle opposé) + second avis claude-opus-4-7. 12 problèmes identifiés + 3 bonus opus = 15 items au total. Voir document complet `_bmad-output/codeReviews/3-1.md`.

**11 corrections appliquées dans cette branche** :

- **#1 + #10 (Q1 Henri = `||`)** — Condition handshake `$mac === '' || $uuid === ''` (parité iso-legacy stricte `boot.php:26`). Commentaire docblock détaillé sur les motivations sécurité (firmware ancien, MAC dupliquée, usurpation). Commentaire test mis à jour. `app/Ipxe/Services/IpxeService.php` + `tests/Feature/Ipxe/IpxeBootEndpointTest.php`.
- **#2 (auto)** — `safeRender()` wrap try/catch sur les 3 appels render (handshake/unknown/known). Fallback iPXE minimal `#!ipxe\necho Erreur serveur SE4FS\nsleep 10\nexit 0\n` + log `ipxe.boot.render_error` channel `ipxe` niveau error avec préfixes 6/8 chars. Garantit D10 (`text/plain`) même en cas d'exception Blade. `app/Ipxe/Services/IpxeService.php`.
- **#3 (auto)** — Détection input malformé + émission event D7 `ipxe.boot.invalid_input` (warning) quand MAC non-vide retourne `null` après `MacAddressNormalizer::normalize()` (idem UUID). Distingue désormais en logs "input corrompu" vs "poste inconnu". `app/Ipxe/Services/IpxeService.php`.
- **#5 (auto)** — Isolation test `it_persists_machine_boot_log_row` via `machine_name` unique (random hex 8 chars) — élimine la dépendance d'ordre PHPUnit sur SQLite `:memory:` partagée. `tests/Feature/Ipxe/IpxeBootEndpointTest.php`.
- **#6 (auto)** — Renforcement test archi : split `routes/web.php` par `;` + boucle de vérification individuelle de chaque route iPXE (`/ipxe/boot` + `/ipxe/boot.ipxe`) contient `auth.v1.lan-only`. Protège contre régression future qui retirerait le middleware sur un seul alias. `tests/Architecture/IpxeNamespaceTest.php`.
- **#7 (Q2 Henri = doc only)** — Retrait de `(array) env('IPXE_FORCE_UEFI_PRODUCTS', ...)` qui castait une string CSV en singleton inutilisable. La liste reste éditable dans `config/ipxe.php` (source de vérité unique). Docblock mis à jour. `config/ipxe.php`.
- **#8 (Q5 Henri = sanitize maintenant)** — Sanitisation préventive ASCII de `$action['label']` et `$action['name']` dans `IpxeMenuRenderer::renderKnown()` (3.1 = code mort car `$action === null`, mais blinde 3.2+). `app/Ipxe/Services/IpxeMenuRenderer.php`.
- **#9 (auto)** — Scénario QA 3.1-5 (rejet IP publique) : remplacement du snippet tinker non-fonctionnel par redirection vers le test Feature automatisé `IpxeEnsureLanIpTest`. Ajout méthode alternative smoke réel (curl depuis VPS externe). `docs/qa/domains/ipxe.md`.
- **#12 (auto)** — File List Dev Record : recompte vérifié à 27 (vs 28 erroné). 3 occurrences corrigées (titre, T7.1, Change Log).
- **B2 (auto)** — Test unit `it_applies_legacy_transformation_for_max_value_mac_ffffffffffff` ajouté : vérifie parité iso-legacy pour MAC limite haute `ff:ff:ff:ff:ff:ff` (valeur hexdec max = 281474976710655 < PHP_INT_MAX 64-bit, donc OK). `tests/Unit/Ipxe/Services/WorkstationLocatorTest.php`.
- **B3 (Q3 Henri confirmé)** — Throttle `throttle:600,1` keyé par IP conservé : Henri confirme que chaque poste du LAN a son IP RFC1918 individuelle visible côté Laravel (pas de NAT pré-Laravel) → 600/IP suffisant.

**4 items non corrigés (acceptés)** :

- **#4** (HTML encoding `$serverBaseUrl`) — pertinence opus 1. Vector quasi-théorique (Symfony rejette les `Host` avec `&`). Risque accepté en l'état.
- **#11** (test ASCII partiel) — pertinence opus 1. `renderUnknown`/`renderHandshake` n'ont pas de chemin user-input réaliste (IP IPv4/v6 ASCII-safe).
- **B1** (`MachineBootLog::create` sans usage explicite fillable) — pertinence opus 1. L'insert passe en test, indice suffisant.

**Suite test post-corrections** : ✅ **75/75 verts** (74 originaux + 1 ajout B2), 0 régression Architecture/Auth V1.

### Branche / worktree

- Branche dédiée **`ipxe`** (worktree `/home/htouchard/code/irundo/codebase/ipxe`). 31 fichiers stagés non commités. Migration depuis main effectuée pour isoler le travail.
- ⚠️ **Commit `50c6275`** (`docs(qa-16.11): corrige 3 anomalies runbook auth.md détectées au smoke`, 107 lignes modifiées `docs/qa/domains/auth.md`) — créé par le dev agent **hors scope 3.1** directement sur main avec Co-Authored-By Claude (contraire à la mémoire `feedback_no_coauthor.md`). Sur les 2 branches `main` et `ipxe` au commit shared HEAD. Décision Henri requise : conserver / revert / déplacer sur branche dédiée.

**Items différés VM HS si applicable** :
- T0.1 (statut VM Henri)
- T0.8 (tail apache error.log)
- T7.2 (`./scripts/run-tests.sh` complet — Phase 1 + Epic 4 régressions globales pas vérifiées localement, seulement Architecture + Auth V1 vérifiés)
- T8.5 — Smoke 9 scénarios `docs/qa/domains/ipxe.md` 3.1-1 à 3.1-9 :
  1. handshake curl
  2. mac connue
  3. uuid connu
  4. mac+uuid inconnus
  5. rejet IP publique
  6. catchall `/ipxe/admin.php`
  7. court-circuit catchall sur `/ipxe/boot`
  8. machine_boot_logs DB
  9. (optionnel) smoke poste réel PXE

### File List

**Fichiers créés (27)** :  <!-- fix review #12 : recompte vérifié `grep -c '^sambaedu-reload/' = 27` -->


```
sambaedu-reload/app/Ipxe/Enums/IpxeMenuKind.php
sambaedu-reload/app/Ipxe/Enums/IpxePlatform.php
sambaedu-reload/app/Ipxe/Support/MacAddressNormalizer.php
sambaedu-reload/app/Ipxe/Support/UuidNormalizer.php
sambaedu-reload/app/Ipxe/Services/WorkstationLocator.php
sambaedu-reload/app/Ipxe/Services/IpxeMenuRenderer.php
sambaedu-reload/app/Ipxe/Services/IpxeService.php
sambaedu-reload/app/Ipxe/Http/Controllers/IpxeBootController.php
sambaedu-reload/app/Ipxe/Http/Requests/IpxeBootRequest.php
sambaedu-reload/app/Providers/IpxeServiceProvider.php
sambaedu-reload/config/ipxe.php
sambaedu-reload/resources/views/ipxe/menu/handshake.blade.php
sambaedu-reload/resources/views/ipxe/menu/default.blade.php
sambaedu-reload/resources/views/ipxe/menu/known.blade.php
sambaedu-reload/tests/Support/IpxeSchemaBootstrapper.php
sambaedu-reload/tests/Unit/Ipxe/IpxeConfigTest.php
sambaedu-reload/tests/Unit/Ipxe/Support/MacAddressNormalizerTest.php
sambaedu-reload/tests/Unit/Ipxe/Support/UuidNormalizerTest.php
sambaedu-reload/tests/Unit/Ipxe/Services/WorkstationLocatorTest.php
sambaedu-reload/tests/Unit/Ipxe/Services/IpxeMenuRendererTest.php
sambaedu-reload/tests/Unit/Ipxe/Services/IpxeServiceTest.php
sambaedu-reload/tests/Unit/Ipxe/Services/IpxeServiceLoggingTest.php
sambaedu-reload/tests/Feature/Ipxe/IpxeBootEndpointTest.php
sambaedu-reload/tests/Feature/Ipxe/IpxeEnsureLanIpTest.php
sambaedu-reload/tests/Feature/Ipxe/IpxeLegacyRoutingNonRegressionTest.php
sambaedu-reload/tests/Architecture/IpxeNamespaceTest.php
sambaedu-reload/docs/qa/domains/ipxe.md
```

(Plus du dossier `sambaedu-reload/storage/logs/ipxe/` créé automatiquement au boot par `IpxeServiceProvider` — git-ignored.)

**Fichiers modifiés (4)** :

```
sambaedu-reload/config/app.php                              (+1 ligne : App\Providers\IpxeServiceProvider::class)
sambaedu-reload/config/logging.php                          (+15 lignes : channel 'ipxe' driver daily 14j)
sambaedu-reload/routes/web.php                              (+37 lignes : bloc 3.1 routes ipxe.boot + alias, AVANT catchall, commentaire ⚠⚠⚠ préservé)
sambaedu-reload/docs/qa/README.md                           (+1 ligne : entrée ipxe dans Domaines couverts)
```

**Fichiers métadonnées BMAD modifiés (2)** :

```
_bmad-output/implementation-artifacts/3-1-ipxe-service-core.md          (Dev Agent Record + File List + Change Log + tasks T0-T8 cochées + status ready-for-dev → review)
_bmad-output/implementation-artifacts/sprint-status.yaml                (3-1-ipxe-service-core: ready-for-dev → review ; epic-3: backlog → in-progress ; commentaire last_updated enrichi)
```

**Fichiers supprimés** : aucun.

### Change Log

- 2026-05-19 : Implémentation complète Story 3.1 par dev claude-opus-4-7[1m].
  - Création du namespace `App\Ipxe` (10 classes + 3 templates Blade).
  - Création de `App\Providers\IpxeServiceProvider` (DO-1).
  - Création de `config/ipxe.php` (D11) + channel log `ipxe` dans `config/logging.php` (D7).
  - Ajout 2 routes natives `GET|POST /ipxe/boot` + `GET /ipxe/boot.ipxe` dans `routes/web.php` AVANT catchall (D2 critique) avec middleware `auth.v1.lan-only` réutilisé de 16.11 (D3).
  - Réutilisation `MachineBootLog` avec `action='ipxe_boot'` (D5, T0.6 confirmé pas de CHECK constraint).
  - 27 fichiers créés + 4 modifiés + 0 supprimé (recompte vérifié — fix review #12).
  - 13 décisions DO-1 à DO-13 documentées (1 ajoutée pour résoudre le bug PHP shebang strip — DBG-1).
  - **Tests** : 74 nouveaux tests Ipxe (Unit 55 + Feature 14 + Architecture 5) — **100% verts localement** + non-régression Auth V1 (49 verts) et Architecture globale (31 verts) confirmées. Lint php -l 0 erreur.
  - Doc QA `docs/qa/domains/ipxe.md` créée (premier domaine iPXE — 9 scénarios stables 3.1-1 à 3.1-9 + checklist) + entrée README ajoutée.
  - Items différés VM : 5 (T0.1, T0.8, T7.2, T8.5 smoke 9 scénarios). Recommandation modèle code-review : sonnet (opposé d'opus dev).

Recommandation modèle code-review : sonnet (opposé d'opus dev).

---

## Smoke test à exécuter quand VM up (action Henri post-reboot)

```bash
# 1. SSH VM + sync inotify confirmé
ssh /vm  # = ssh -i ~/.ssh/id_se4fs_vm root@192.168.122.50

cd /var/www/sambaedu-reload

# 2. Composer + migrations
composer install --no-dev --optimize-autoloader
# Pas de migration nouvelle 3.1 (D12) — sauf escalation T0.6
# php artisan migrate (si table créée)

# 3. Cache reset
php artisan config:cache
php artisan route:cache
php artisan view:cache

# 4. Smoke curl handshake
curl -sS http://192.168.122.50/ipxe/boot
# Attendu : 200 + text/plain + body commence par "#!ipxe\nparams\nparam mac"

# 5. Smoke curl poste connu (seed factory si besoin via tinker)
curl -sS -X POST http://192.168.122.50/ipxe/boot \
  -d 'mac=00:11:22:33:44:55&uuid=12345678-1234-1234-1234-123456789012&product=OptiPlex 3050'
# Attendu : 200 + body avec :menu + :default + :login + :exit

# 6. Smoke LAN whitelist (depuis IP publique simulée — impossible sans iptables magic, doc-only)
# Vérifier dans le log que tous les calls 192.168.x.y passent

# 7. Vérification logs
tail -f storage/logs/ipxe/ipxe-$(date +%F).log
# Attendu : events ipxe.boot.handshake | known_workstation | unknown_workstation

# 8. Vérification MachineBootLog
sudo -u postgres psql -d sambaedu -c "SELECT id, workstation_id, machine_name, action, started_at FROM machine_boot_logs WHERE action='ipxe_boot' ORDER BY id DESC LIMIT 10;"

# 9. Non-régression catchall
curl -sS http://192.168.122.50/ipxe/admin.php | head -20
# Attendu : continue d'être servi via le legacy (proxy catchall)

# 10. Run de la suite ciblée 3.1
./vendor/bin/phpunit \
  tests/Unit/Ipxe/ \
  tests/Feature/Ipxe/ \
  tests/Architecture/IpxeNamespaceTest.php

# 11. Run de la suite complète (non-régression Phase 1 + 16.10-16.12 + Epic 4)
./scripts/run-tests.sh

# 12. Smoke poste réel (optionnel — si poste de test disponible)
# Brancher un poste de test sur LAN, configurer PXE boot prioritaire en BIOS,
# rebooter → constater le menu iPXE qui s'affiche, choisir option 0 (boot disk).
# Vérifier la row MachineBootLog correspondante en DB.
```

---

## Recommandation Modèle Dev

**Modèle recommandé : `opus`**

**Justification** :

- **Domaine nouveau dans le projet** : iPXE n'a jamais été touché côté Laravel — premier namespace `App\Ipxe`, premier dossier `resources/views/ipxe/`, premier channel log `ipxe`, premier `docs/qa/domains/ipxe.md`. Opus a la culture plus riche pour générer la structure cohérente du premier coup (iso conventions 16.10/16.11/16.12 sans qu'on doive itérer 3 fois).
- **Lecture comportementale legacy non-triviale** : 153 lignes de `sambaedu/ipxe/boot.php` + 82 de `ipxe_functions.inc.php` à transposer correctement. Notamment la transformation hexadécimale `product` vide (D4 étape 3 — `dechex(hexdec(implode("", explode(":", $mac))))`) est un pattern subtil que sonnet a tendance à mal reproduire (substr/regex au lieu de la chaîne exacte). Test unit fixture iso-legacy obligatoire (T2.4).
- **Coordination 5 services + 3 templates + 1 controller + 1 FormRequest + 1 provider** : densité moyenne (≤22 fichiers), pas la plus complexe rencontrée jusqu'ici, mais la justesse de l'orchestration `IpxeService` (3 paths handshake/known/unknown + logging + insert MachineBootLog) demande de tenir simultanément la chaîne entière en tête. Opus mieux armé pour la cohérence end-to-end.
- **Non-régression catchall critique** : 3.1 doit **rigoureusement** ne pas casser les ~30 routes legacy `/ipxe/*` servies par le catchall. Test archi + 3 tests feature de non-régression obligatoires (AC6.1, AC6.2). Sonnet aurait tendance à généraliser la route 3.1 en `prefix('/ipxe')` ou en `match` permissif, ce qui casserait silencieusement le legacy. Opus respecte mieux les contraintes "exactement 2 routes précises".
- **Templates Blade iPXE = format atypique** : la syntaxe iPXE (`${menu-default}`, `--key`, `:label`, `chain --replace --autofree`) n'est pas du code applicatif standard. Opus a une meilleure mémoire des cas d'usage iPXE/PXE/wimboot que sonnet (qui aurait tendance à "améliorer" en HTML ou en bash).
- **Sécurité LAN-only crédible mais non-bloquante** : pas la criticité 16.11 (où la fixation UUID était une vraie vulnérabilité) — donc pas un point d'escalade. Mais la cohérence du réemploi `auth.v1.lan-only` (D3) + code erreur `BOOTSTRAP_NOT_LAN` (D8) doit être faite proprement.
- **Decision-log déjà cadré** : 12 décisions D1-D12 tranchées. Le dev n'a pas à itérer dessus — il implémente. Cela compense partiellement le coût Opus.

**Bascule possible vers Sonnet** : si la suite Phase T1-T3 (provider + config + locator + normalizers) se passe sans accroc et que le dev produit une couverture unit verte en T4, la phase T5-T8 (controller + routes + tests feature + doc QA) pourrait passer en Sonnet pour économiser le coût. Décision à prendre par Henri après le premier point d'étape T4.

**Anti-escalade** : ne pas escalader vers `claude-opus-4-7[1m]` (1M context) — la story est bien découpée et reste largement dans la fenêtre 200k tokens d'Opus standard. Le 1M context est utile pour des migrations massives multi-fichiers ou des refactorings cross-cutting, pas pour une story fondation comme 3.1.

**Charge cadrée** : 2-4j (estimation SM) — premier domaine du projet à monter, 22 fichiers créés, ~30 tests à écrire, doc QA à initialiser, mais decisions log déjà tranché et patterns 16.10-16.12 prêts à imiter. Si T0.6 escalade vers Henri (CHECK constraint MachineBootLog) → recadrer à 4-5j (ajout migration + ajustement table). Si la lecture legacy iPXE révèle des subtilités non-cadrées → recadrer à 3-5j.

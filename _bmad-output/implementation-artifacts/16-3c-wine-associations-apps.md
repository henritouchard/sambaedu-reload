# Story 16.3c : Wine (UI admin + Job queue) + Associations apps (endpoint runtime)

Status: review

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

> Sous-story issue du split de la Story 16.3 (décision Henri post-audit 16.1 §6.G).
> **Périmètre = 2 surfaces fonctionnelles** :
> 1. `gpo/wine.php` → UI admin native (`/app/gpo/wine`) + Job queue Laravel pour la génération d'image.
> 2. `gpo/associations_out.php` → endpoint HTTP runtime poste client (pattern strictement iso 16.3b).
>
> **`gpo/applications.php` reste HORS SCOPE de cette story** (cf. D2 ci-dessous).

---

## ⚠️ Recadrage scope vs cadrage initial sprint-status

> Le cadrage initial du sprint listait 3 fichiers (wine + associations_out + applications). **L'audit légitime de `applications.php` montre qu'il est intransportable dans une story de 3-4j** :
>
> - 51 lignes de page mais **1007 lignes** dans `sambaedu/includes/applications.inc.php` (`get_app_scripts_info`, `read_application_scripts`, `make_application_scripts`, `log_application_scripts`, `redirect_scripts`, `local_admin_scripts`, `wpkg_scripts`, `apt_scripts`, `sudo_scripts`, `once_scripts`, `header_scripts`, `footer_scripts`, `powershell_scripts`...).
> - Surface AD massive : `search_machine`, `search_user`, `check_computer`, `get_machine_status`, `register_machine_hardware`, `set_os`, `list_remote_connexion`, `get_local_admin_right`, `have_right`, `have_delegation`, `delete_remote_connexion`, `test_cloud`, `log_connexion`.
> - 7 constantes catalogue d'erreur (`SAMBAEDU_STARTUP_APP_ERROR`, etc.).
> - Surface OS (PowerShell tasks, redirects, sudoers, mklink, gpupdate).
> - **C'est l'endpoint qui POSE la session APCu `apps.$id` consommée par tous les autres endpoints natifs** (`firefox_out` 4.8, `wallpaper_out` 4.7, `network_out`/`veyon_out` 16.3b, et `associations_out` de cette story). Le porter sans porter l'amont AD complet casserait toute la chaîne.
>
> **Tranchement SM** : `gpo/applications.php` **reste shimé via 1bis-18e** (déjà done) pendant toute la durée d'Epic 16. Une nouvelle story **16-7 « Portage natif applications.php (générateur scripts startup/logon) »** sera créée et ajoutée au backlog Epic 16 (cf. T7.1). Cela permet à 16-3c de livrer 2 portages cohérents et de taille raisonnable, et préserve la chaîne natif (les endpoints natifs 4.7/4.8/16.3b continuent à consommer `apps.$id` posé par le shim `applications.php`).

---

## 🎯 Pré-tranchements SM (à appliquer sans re-discuter)

> Pattern strictement éprouvé en 16.3b. **Le dev applique les règles ci-dessous, justifie en commentaire de code, ne re-discute pas pendant le dev.** En cas de blocage technique réel (pas de blocage de design), il documente dans la story et continue.

| # | Item | Tranchement | Règle |
|---|---|---|---|
| **T0.5** | `WorkstationPackagesResolver` (15.2) disponible vs shim `info_poste_applications` | **Utiliser le natif 15.2** | `App\Wpkg\Deployment\Services\WorkstationPackagesResolver::resolve($hostname): Collection<string>` est le pendant natif Eloquent de `info_poste_applications` (cf. `app/Wpkg/Deployment/README.md` ligne 73). Iso TTL 1000s, cache `wpkg:packages:{hostname}`. Pas de shim. |
| **T0.6** | `packages.xml` path (legacy `$url_packages = "/var/sambaedu/unattended/install/wpkg/packages.xml"`) | **Lecture via `config('sambaedu.wpkg.deploy_path')` + `/packages.xml`** | Story 15.1 a établi `config/sambaedu.php` avec `wpkg.deploy_path`. Si absent → 200 body `{"result":{}}` (parité legacy gracieux DOMDocument). Service `PackagesXmlAssociationsReader` (port `@legacy-port`). |
| **T0.7** | `get_wine_shortcuts` (`shortcuts.inc.php:523`) | **Fallback shim `@legacy-port` autorisé d'office** | Pas de re-design ShortcutsService natif dans cette story. Appel via `legacy/bootstrap.php` avec docblock `@legacy-port` + `@todo Story 16.4`. Le `ShortcutsService` natif (`app/Services/ShortcutsService.php`) sera étendu en Story 16.4. |
| **T0.8** | `batch_command` + `batch_write` (queue legacy APCu + flush `/tmp/admin_script_*.sh` + cron) | **Remplacer par Laravel Queue Job** | `App\Gpo\Jobs\GenerateWineImageJob` (queue `default`, retry 0 — l'utilisateur relance s'il y a échec, parité legacy idempotent). Pas d'iso-legacy sur la mécanique batch (substitution propre). |
| **F7 audit** | `batch_command("/usr/share/sambaedu/scripts/make_wine_image.sh " . $application)` injection (cf. audit §6.F F7) | **Whitelist regex stricte + invocation `Process::run` mode array** | Regex `^[a-zA-Z0-9._-]+$` sur `$application` AVANT mise en queue. Job invoque `Process::run(['/usr/share/sambaedu/scripts/make_wine_image.sh', $application], timeout: 1800)`. **Audit §6.F F7 corrigé**. |
| **AC1.6** | Bug legacy `wine.php:52` : `if ($application = $select_application)` (assignment au lieu de comparaison) | **NE PAS reproduire** | C'est un bug d'affichage UI (mauvais `checked` sur `<option>`). Le port natif Blade/Livewire reproduit la SÉMANTIQUE attendue (`selected` sur l'option choisie), pas le bug. Documenter en commentaire `// @legacy-bug fixed: assignment instead of comparison`. |
| **AC2.0** | Auth `associations_out.php` (legacy : aucune) | **Iso 16.3b** : pas d'auth web, throttle `300,1`, garde = `id` md5 strict + APCu `apps.$id` | Endpoint runtime poste client. Pattern strictement iso 16.3b. |
| **AC1.0** | Permission UI Wine (legacy : `have_right(SE_ADMIN)`) | **`server.admin` Spatie + middleware `sambaedu.admin`** | Iso 16.2 (`/app/gpo`). Cohérence Epic 16 UI admin. |
| **D3** | Channel logs pour Wine (UI admin) vs Associations (runtime) | **Wine = `gpo` (admin → catalogue `gpo.wine.image.generate` + `gpo.wine.shortcuts.generate`), Associations = `daily` standard (runtime, iso 16.3b D9)** | Distinction nette UI admin (auditable Epic 16) vs runtime poste client. |
| **D4** | Iso-bytes vs iso-structure pour `associations_out.php` | **Iso-bytes** sur body JSON (`json_encode($result, JSON_PRETTY_PRINT)`), iso `Content-type: text/json` (parité legacy ligne 170) | Test comparison fixture VM (`tests/Fixtures/Gpo/legacy-associations-out.json`), tolérance ordre des clés (sort_assoc avant diff). |
| **D5** | Side-effect debug `/tmp/assoc_*.json` (legacy ligne 30, 49, 73, 168 — 4 writes debug) | **Conserver write `/tmp/assoc_result.json` (final), skipper les 3 intermédiaires** | Parité partielle. Le legacy écrit 4 fichiers debug (`assoc_local.json`, `assoc_app.json`, `assoc_wpkg.json`, `assoc_result.json`) — seul `assoc_result.json` est consommé en post-mortem. Skippés en `app()->environment('testing')` (parité 16.3b AC1.7). |
| **T0.9** | Stratégie `applications.php` | **Hors scope — création Story 16.7 backlog** | Ne PAS y toucher. Continue d'être servi par le shim 1bis-18e. Vérifier en T0 que le shim fonctionne et que `apps.$id` est bien posé pour les autres endpoints (smoke `apcu_fetch "apps.$id"` sur VM). |

**Règle générale Henri (rappel 16.3b)** : **iso-legacy par défaut**. Le dev n'invente pas de comportement. Si une décision n'est pas couverte ici, il privilégie systématiquement le comportement du PHP procédural d'origine et documente le choix en commentaire `@legacy-port`.

---

## Story

As **un administrateur SambaEdu (server.admin)** ET **un poste client Windows joint au domaine SE4FS**,
I want :
- (admin) gérer la génération de l'image partagée Wine pour les postes Linux + la régénération des raccourcis Wine, depuis une page native `/app/gpo/wine` aux ergonomie/permissions cohérentes avec `/app/gpo` (Story 16.2) ;
- (poste Windows) continuer à recevoir, depuis l'URL legacy `/gpo/associations_out.php`, le JSON des associations d'extensions de fichiers (`ProgId`) pour les apps WPKG installées sur le poste, **alors même que le PHP procédural a été retiré du code natif Laravel** ;
So que (a) la gestion Wine soit native, traçable (channel `gpo`) et plus sûre (Job queue Laravel + whitelist regex de l'argument, vs `batch_command` legacy non échappé) ; (b) les associations d'extensions de fichier appliquées au logon Windows restent **strictement iso-fonctionnelles** pendant la transition Epic 16, sans toucher la GPO `se4_applications` qui déclenche l'appel HTTP côté client.

---

## Contexte

**Volet Wine (UI admin)** : `gpo/wine.php` (79 lignes) est une page admin actuellement servie par le shim 1bis-18e dans le layout SER legacy. Elle expose 2 actions :
1. **Générer l'image Wine partagée** (`/usr/share/sambaedu/scripts/make_wine_image.sh $application` — script shell long, ~10 min annoncé dans le HTML legacy).
2. **Générer les raccourcis Wine** (factorisation dans `/etc/sambaedu/applications/shortcuts/shortcuts.json` via `get_wine_shortcuts($config, $application)`).

Le scan du dossier `/var/sambaedu/unattended/install/wine/` liste les containeurs Wine disponibles (préfixe par défaut `.wine`, plus des `.wine-<application>`). Bug F7 audit : `batch_command` exécute le shell **sans échappement** de `$application` issu de `$_POST`. Iso-legacy = vulnérable.

**Volet Associations (endpoint runtime)** : `gpo/associations_out.php` (173 lignes) est un endpoint POST consommé par les postes Windows au logon via la GPO `se4_applications`. Il :
1. Récupère le contexte APCu `apps.$id` posé en amont par `gpo/applications.php` (shim 1bis-18e — conservé HS).
2. Lit `$url_packages` (`/var/sambaedu/unattended/install/wpkg/packages.xml`) via `DOMDocument::load` et extrait les `<Association ProgId="…" Identifier="…" type="file|protocol">` pour les apps WPKG installées sur le poste.
3. Lit le JSON local d'associations `/usr/share/sambaedu/applications/associations/associations.json` (système) + `/etc/sambaedu/applications/associations/associations.json` (local) + `/usr/share/sambaedu/applications/associations/default.xml` (défaut).
4. Filtre par groupes user/parc (`$id['list']`) et par apps installées (`$liste_applications` = `info_poste_applications($config, $machine)` = `WorkstationPackagesResolver::resolve` natif).
5. Diff avec les associations locales actuelles du poste (`$_POST['list']`) et retourne le delta JSON.

L'URL est en dur dans des scripts côté poste — **iso-contrat URL obligatoire**, comme `network_out.php`/`veyon_out.php` (cf. story 16.3b D1).

### Pourquoi 3-4 jours

- **Volet Wine** : ~1.5j (page admin Livewire SFC simple + Job queue + ShortcutsService extension + tests).
- **Volet Associations** : ~1.5j (parsing XML DOM `packages.xml`, intersection avec `WorkstationPackagesResolver`, diff JSON, comparison fixture).
- **Tests + doc + QA VM** : ~0.5j.
- **Investigation T0** sur disponibilité shim 1bis-18e et état `apps.$id` : ~0.25j.

---

## Garde-fous Epic 16 (rappel — applicables à cette story)

- **AD = source de vérité** : aucune table Eloquent créée par cette story. Le listing Wine prefix utilise un service stateless qui scan le filesystem (lecture seule). Les associations consomment `WorkstationPackagesResolver` (Story 15.2) côté Eloquent + `packages.xml` côté FS legacy.
- **Trois couches** (`architecture.md:343-353`) : Controllers fins ; logique métier dans **Services** dédiés (`WinePrefixScanner`, `WineImageQueuer`, `AssociationsResolver`, `PackagesXmlAssociationsReader`) ; Job queue Laravel (`GenerateWineImageJob`) ; pas d'`exec()` direct dans les Controllers.
- **Iso-contrat URL legacy** : l'URL `/gpo/associations_out.php` **doit rester invariante**. Le routage natif l'intercepte exactement comme `network_out.php`/`veyon_out.php` (déclaration AVANT le catchall, hors groupe `sambaedu.admin`).
- **Pas d'auth web** sur `associations_out.php` (postes clients sans cookie Laravel). **Garde effective** : l'`id` md5 (32 hex) doit être présent dans APCu (clé `apps.$id` posée par `applications.php` shim). **Pattern strictement iso 16.3b**.
- **Auth Spatie + middleware sur l'UI Wine** : `server.admin` (cohérence 16.2, 4.7, 4.8 admin). Groupe `sambaedu.admin` dans `routes/web.php`.
- **Pattern routes runtime** : `Route::match(['GET', 'POST'], 'gpo/associations_out.php', [...])` + `->middleware('throttle:300,1')` — déclaration **AVANT** le catchall ligne 453 de `routes/web.php`, **APRÈS** les routes 16.3b (Story 16.3b est dans la même section logique).
- **UI Wine** : filesystem-based router `laravel/resources/views/pages/app/gpo/wine/index.blade.php` (convention `CLAUDE.md`). Livewire SFC pour la réactivité (form + scan préfixes), modale réutilisable, `WithToasts` pour les feedbacks.
- **Shim 1bis-18 reste vivant** : `legacy/modules/gpo/wine.php` et `legacy/modules/gpo/associations_out.php` **ne sont PAS supprimés**. La page `/gpo/wine.php` doit **rediriger vers `/app/gpo/wine`** (catchall `blocked_legacy_routes` — pattern Story 16.2 D5 pour `gestion_gpo.php`). `/gpo/associations_out.php` est intercepté avant le catchall par la nouvelle route Laravel.
- **`@legacy-port`** : tout helper porté depuis `gpo/associations_out.php`, `shortcuts.inc.php`, `wpkg_libsql.php` porte un docblock `@legacy-port` + `@todo` + ligne dans `docs/tech-debt-gpo.md`.
- **Channel logs** : **Wine = `gpo`** (catalogue Epic 16 — actions admin auditées + `operation_id` UUID, 3 logs `start`/`step`/`end`). **Associations = `daily` standard** (runtime poste, iso 16.3b D9).
- **Catalogue `action_type` à enrichir** (Story 16.1 AC1.3) : ajouter `gpo.wine.image.generate`, `gpo.wine.shortcuts.generate`, `gpo.wine.prefixes.list` — documentés dans `app/Gpo/README.md`.
- **CLAUDE.md** : applicable à l'UI Wine (`pages/app/gpo/wine/index.blade.php` + composant Livewire SFC + modale réutilisable + `WithToasts`).

---

## Dépendances

| Story / Epic | Titre | Status | Détail |
|---|---|---|---|
| **16.1** | Fondations GPO natives + audit legacy | review (2026-05-11) | **Bloquant doux** : `App\Gpo\{Services,Support,Jobs,Models,Events}` posés. Channel `gpo` configuré. `GpoLogger` + `GpoActionLog` + catalogue `action_type` posés. `SambaToolRunner` non utilisé ici (pas de samba-tool dans 16.3c). |
| **16.2** | Listing & lecture GPO UI native | review (2026-05-11) | **Référence pattern UI admin** : `pages/app/gpo/index.blade.php` + sidebar + permission `server.admin` + middleware `sambaedu.admin`. La page `/app/gpo/wine` réutilisera cette ergonomie. |
| **16.3a** | Liens profonds sections natives | review (2026-05-11) | **Non bloquant** — mais à ENRICHIR : `NativeSectionResolver::MAPPING` doit pointer `*wine*` → `/app/gpo/wine` (pour qu'un GPO nommé `se4_wine` exposé dans `/app/gpo` affiche un CTA "Édition native"). Cf. D10. |
| **16.3b** | network_out + veyon_out (endpoints runtime) | review (2026-05-12) | **Référence pattern strict** : Controller iso-contrat legacy + `AppContextRepository` + `Route::match` avant catchall + throttle 300/min + 200 strict iso-legacy + fixtures comparison. Le port `AssociationsOutController` reproduit ce pattern à l'identique. |
| **15.2** | WPKG generators XML/INI per poste | done (2026-05-07) | **Réutilisation directe** : `App\Wpkg\Deployment\Services\WorkstationPackagesResolver::resolve($hostname): Collection<string>` est le pendant natif Eloquent de `info_poste_applications` (cf. README ligne 73). Cache TTL 1000s ; cache key `wpkg:packages:{hostname}`. Pas de shim. |
| **15.1** | Fondations pipeline déploiement WPKG | done | **Réutilisation directe** : `config('sambaedu.wpkg.deploy_path')` pour résoudre `packages.xml` (legacy `$url_packages`). |
| Story 4.7 / 4.8 | Wallpapers + AppCustomization | done | Référence pattern Controller `legacyOut` + `AppContextRepository` (Story 4.8) — directement réutilisé pour `associations_out`. |
| Story `ShortcutsService` | `app/Services/ShortcutsService.php` | done | **Extension** : ajouter `importWineShortcuts(array $shortcuts): void` pour persister les raccourcis Wine en base + sync JSON. Bornage `@legacy-port` sur le helper de scan `get_wine_shortcuts`. |
| 1bis-18e | Shim legacy gpo (wine + associations_out + applications) | review | **Conservation explicite** : `legacy/modules/gpo/{wine,associations_out,applications}.php` restent en place. Wine = nouvelle route native + redirect catchall ; associations_out = intercepté par nouvelle route avant catchall ; applications.php = **inchangé** (continue à poser `apps.$id`). |
| 1bis-18g | Shim ldap (search_ad, etc.) | done | Pas utilisé ici (Associations ne fait pas de LDAP direct — tout passe par `apps.$id` APCu + Eloquent 15.2). |

**Conclusion dépendances** : aucune bloquante. La story peut démarrer immédiatement en parallèle de la review de 16.3b. Le pattern Controller iso-contrat legacy est **désormais doublement éprouvé** (16.3b livré review). Le natif WPKG (15.2) fournit le pendant Eloquent de `info_poste_applications`.

---

## Décisions SM (D1-D12)

| #   | Décision | Justification |
|-----|----------|---------------|
| D1  | **Périmètre strict = 2 fichiers legacy** : `wine.php` + `associations_out.php`. **`applications.php` HORS SCOPE**, restera shimé via 1bis-18e pendant tout Epic 16. Création d'une Story 16-7 ad hoc en backlog. | Audit (cf. recadrage ci-dessus) : `applications.inc.php` = 1007 lignes + surface AD massive + générateur scripts multi-OS + endpoint amont qui pose `apps.$id` pour 4.7/4.8/16.3b. Le porter coûterait ~10-15j. Le shim 1bis-18e (done) continue de tenir cette chaîne. La séparation logique est nette. |
| D2  | **Wine = pure UI admin native** (`/app/gpo/wine`) avec **Livewire SFC + Job queue Laravel + extension `ShortcutsService`**. PAS d'iso-contrat URL legacy `/gpo/wine.php` (`gpo/wine.php` legacy était une UI HTML embarquée admin, pas un endpoint runtime — re-déploiement OK). **Redirection catchall `/gpo/wine.php` → `/app/gpo/wine`** (pattern iso 16.2 D5 `gestion_gpo.php`). | (a) Wine = UI admin de gestion d'image partagée, consommée par admin Henri/QA — pas d'URL en dur côté postes. (b) Pattern 16.2 (catchall redirect) déjà en place pour `gestion_gpo.php`. (c) Job queue = remplacement propre de `batch_command` (queue APCu primitive + flush `/tmp/admin_script_*.sh` + cron). (d) ShortcutsService déjà natif et utilisé par `/app/shortcuts` — extension naturelle. |
| D3  | **Associations = endpoint runtime strict iso-contrat URL `/gpo/associations_out.php`** — Pattern strictement iso 16.3b (Route::match avant catchall, throttle 300/min, pas d'auth, validation `id` md5, AppContextRepository réutilisé, channel `daily`). | URL en dur dans la GPO `se4_applications` côté postes — toute mutation casserait le parc (cf. Story 16.3b D1). 16.3b a éprouvé le pattern complet, reproduit à l'identique. |
| D4  | **Services métier dédiés** dans `App\Gpo\Services\` : `WinePrefixScanner` (scan FS lecture seule), `WineImageQueuer` (mise en queue Job + log channel `gpo`), `AssociationsResolver` (logique métier intersection/filtrage), `PackagesXmlAssociationsReader` (port `@legacy-port` du parsing DOM XML). **Job** : `App\Gpo\Jobs\GenerateWineImageJob`. Controllers fins dans `App\Http\Controllers\Gpo\` : `WineController` (UI admin avec methods Livewire) + `AssociationsOutController` (endpoint runtime). | Cohérence pattern 16.1 AC2.1 + 16.3b (D3) : Controllers minces, logique dans Services, Jobs séparés. Testabilité unit pure des services. |
| D5  | **`WorkstationPackagesResolver` (15.2) = SEULE source de la liste d'apps installées sur le poste**. Pas de shim `info_poste_applications`. Cache TTL 1000s déjà géré côté 15.2. | Eloquent uniquement (décision Henri Story 15.2 #5). Le shim mysqli `wpkg_libsql.php` (1bis-3) reste en place mais N'EST PAS appelé par cette story. |
| D6  | **`packages.xml` resolution** : lecture via `config('sambaedu.wpkg.deploy_path').'/packages.xml'` (Story 15.1). Si fichier absent → log warning + retour `{"result": {}}` body iso-legacy (parité DOMDocument gracieux). Pas de Fatal. | (a) Cohérence Story 15.1 (config natif). (b) Parité legacy gracieux (le DOMDocument legacy logge un warning silencieux). (c) Évite régression en CI où `packages.xml` est absent. |
| D7  | **Validation `id` md5 strict** sur `associations_out` (regex `^[a-f0-9]{32}$`) + validation `list` JSON décodable (≤ 10 Ko, structure attendue `{type: [string,...]}`) ; **iso-legacy 400 Bad Request si invalide** (parité legacy ligne 26 `header("HTTP/1.1 400 Bad request")`). | (a) `id` md5 iso 16.3b. (b) `list` POST iso-legacy 400 (pas 200 vide comme network_out/veyon_out — la sémantique legacy est différente : sans `list`, le delta JSON ne sert à rien). (c) 10 Ko limite raisonnable contre body abusif. |
| D8  | **Iso-bytes JSON output** : `json_encode($result, JSON_PRETTY_PRINT)` + `Content-Type: text/json` (parité legacy ligne 170 `header('Content-type: text/json')`). **Test comparison** `tests/Feature/Gpo/AssociationsOutComparisonTest.php` qui diff la sortie native vs fixture legacy (sort_assoc avant diff pour tolérance ordre clés). | Pattern iso 16.3b D8. `text/json` est non-standard mais conservé pour iso-bytes header (Veyon des postes Windows ne sont pas affectés). Test fixture skippable si fixture VM absent. |
| D9  | **UI Wine** : composant Livewire SFC `resources/views/pages/app/gpo/wine/index.blade.php`, pattern iso `/app/gpo/index.blade.php` (Story 16.2). Sidebar identique. Permission `server.admin` (middleware `sambaedu.admin`). **Modale réutilisable** pour la confirmation de génération image (action longue ~10min — UX critique). **`WithToasts`** pour feedback Job dispatched / shortcuts regénérés. | Cohérence CLAUDE.md (filesystem-based routing, modale réutilisable, WithToasts). UX critique sur action longue : confirmation modale + toast immédiat "Image en queue, vous serez notifié à la fin" + bouton voir logs (link vers `storage/logs/gpo/*.log`). |
| D10 | **Enrichissement `NativeSectionResolver` (16.3a)** pour Wine **OUI** : ajouter une entrée `*wine*` → `/app/gpo/wine` dans `MAPPING`. Pour Associations **NON** (runtime, pas d'UI admin). | Wine = UI admin native — cohérence 16.3a (boutons "Édition native" sur la page détail GPO `/app/gpo/{guid}`). Cf. story 16.3a D11 (`NativeSectionResolver::MAPPING` extensible). Associations = runtime, hors champ de 16.3a. |
| D11 | **Tests Wine UI** = Feature Livewire (test des 2 actions, validation regex `$application`, dispatch Job mock) + Unit `WinePrefixScanner` (mock FS) + Unit `WineImageQueuer` (mock Queue). **PAS d'E2E** (iso 4.8). Smoke VM = exécution réelle de `make_wine_image.sh` sur poste test. | Couverture pyramidale standard Laravel. Le Job réel `make_wine_image.sh` n'est pas exécuté en CI (script externe long) — smoke VM. |
| D12 | **Tests Associations** = Feature `AssociationsOutEndpointTest` (iso 16.3b `NetworkOutEndpointTest` ; ~9 tests incl. validation 400, structure JSON, intersection apps, default.xml absent gracieux) + Unit `AssociationsResolver` (logique pure, mock `WorkstationPackagesResolver`) + Unit `PackagesXmlAssociationsReader` (mock filesystem + DOMDocument) + Feature comparison fixture skippable. | Pattern iso 16.3b T6. Couverture exhaustive de la logique métier d'intersection sans dépendance VM. |

### Discrepances ouvertes à valider pendant le dev

| Item | Note SM |
|---|---|
| **Sémantique `list` POST côté `associations_out.php`** (legacy : `$_POST['list']` = JSON des assoc locales du poste, comparées avec le calcul serveur pour produire un delta) | Validé par le legacy `gpo/associations_out.php:24-39, 161-166`. Le delta retiré contient les associations à **modifier** côté poste. Test feature `it_returns_only_diff_associations`. |
| **Effet de bord debug `/tmp/assoc_*.json`** (4 writes : local, app, wpkg, result) | Tranché D5 : conserver UNIQUEMENT `assoc_result.json`, skipper les 3 autres. Justification : `assoc_result.json` est le seul utilisé en post-mortem côté Henri. Les 3 autres sont des dumps intermédiaires de debug. Doc tech-debt. |
| **Bug legacy boucle `foreach ($packages as $package)` doublonnée vide** (lignes 68-71, no-op) | Ne pas reproduire. Documenter `// @legacy-port: legacy loop 68-71 was a no-op (commented out), skipped`. |
| **XML schema `<Association>` dans `packages.xml`** | Iso-legacy ligne 51-66 : recherche `<Association>` enfants de `<package>` avec attributs `ProgId`, `Identifier`, `type` (défaut `file`). Service `PackagesXmlAssociationsReader` reproduit à l'identique. |
| **Lecture `default.xml`** (`/usr/share/sambaedu/applications/associations/default.xml`) absent | Iso-legacy gracieux : `default = []` si fichier absent (ligne 79 `if (file_exists(...))`). |
| **Wine prefix `application = $_POST['application']`** : whitelist | Tranché F7 audit : whitelist regex `^[a-zA-Z0-9._-]+$` + validation que le préfixe existe dans le scan (`WinePrefixScanner::available()`). 400 + toast erreur si invalide. |
| **Permission CTA `/app/gpo/wine` depuis liste GPOs ?** | Cf. D10 : enrichissement `NativeSectionResolver::MAPPING` (`*wine*` → `/app/gpo/wine`) — pas de modification CTA logic 16.3a, juste ajout entrée mapping. |

---

## Acceptance Criteria

> 6 volets. Volet 1 = UI Wine (page admin). Volet 2 = Job queue Wine + ShortcutsService. Volet 3 = Endpoint Associations. Volet 4 = Routage + iso-contrat. Volet 5 = Sécurité. Volet 6 = Tests.

### Volet 1 — UI Wine `/app/gpo/wine` (`WineController` + Livewire SFC)

**AC1.1 — Route + permission**
**Given** un admin authentifié avec permission Spatie `server.admin`
**When** il navigue vers `/app/gpo/wine`
**Then** la page Livewire `resources/views/pages/app/gpo/wine/index.blade.php` s'affiche
**And** la route est déclarée dans le groupe `sambaedu.admin` (middleware web + auth + permission `server.admin` — pattern iso 16.2)
**And** un user sans `server.admin` reçoit `403`.

**AC1.2 — Affichage des préfixes Wine disponibles**
**Given** le scanner FS `WinePrefixScanner::list(string $basePath = '/var/sambaedu/unattended/install/wine'): list<string>` est invoqué
**When** la page s'ouvre
**Then** un `<select name="application">` est rendu avec :
- 1 option "Conteneur par défaut (`.wine`)" valeur `""`
- N options pour chaque dossier `wine-<X>` du scan (extraction de `<X>` via regex `^wine-(.*)$`)
**And** si le dossier n'existe pas → liste vide silencieuse (parité legacy gracieux).

**AC1.3 — Action "Générer l'image"**
**Given** un admin sélectionne un préfixe (ou défaut) et clique "Générer l'image"
**When** la méthode Livewire `generateImage()` est invoquée
**Then** :
- Validation regex stricte `^[a-zA-Z0-9._-]*$` sur `$application` (la chaîne vide est autorisée pour le défaut)
- Validation que le préfixe est dans `WinePrefixScanner::list()` (sauf chaîne vide)
- Si invalide → toast error + return sans dispatch Job
- Sinon → `WineImageQueuer::dispatch(string $application): GenerateWineImageJob` ⇒ dispatch sur queue `default`
- Log channel `gpo` action `gpo.wine.image.generate` (start + step "queued" + success avec context `application`)
- Toast info "L'image Wine est en cours de génération (≈ 10 min). Surveillez les logs `storage/logs/gpo/*.log`."

**AC1.4 — Action "Générer les raccourcis"**
**Given** un admin clique "Générer les raccourcis"
**When** la méthode Livewire `generateShortcuts()` est invoquée
**Then** :
- Validation iso AC1.3 sur `$application`
- Appel `ShortcutsService::importWineShortcuts(string $application): int` (méthode à ajouter — étend le service existant)
- L'implémentation interne **peut** déléguer à `get_wine_shortcuts($config, $application)` shim `@legacy-port` + persistence native via le service.
- Retour : nb de raccourcis ajoutés. Toast success "X raccourcis Wine ajoutés."
- Log channel `gpo` action `gpo.wine.shortcuts.generate` (start + step "merging" + success avec context `application, added_count`).

**AC1.5 — Modale de confirmation sur action longue**
**Given** un admin clique "Générer l'image"
**When** la modale réutilisable s'ouvre
**Then** elle affiche "La génération peut prendre ~10 minutes. Confirmer ?" + 2 boutons "Annuler" / "Lancer"
**And** "Annuler" ferme la modale sans action
**And** "Lancer" déclenche `generateImage()` (cf. AC1.3).

**AC1.6 — Bug legacy `wine.php:52` NON reproduit**
**Given** le port natif Blade/Livewire
**When** la liste `<select>` est rendue
**Then** l'attribut `selected` est posé sur l'option **strictement égale** (`==` ou `===`) à `$selectedApplication` (PAS d'assignment).
**And** un commentaire `{{-- @legacy-bug fixed: assignment instead of comparison wine.php:52 --}}` est présent dans le template.

**AC1.7 — Redirection catchall `/gpo/wine.php` → `/app/gpo/wine`**
**Given** un utilisateur fait `GET /gpo/wine.php`
**When** la requête atteint le `LegacyCatchallController`
**Then** la config `sambaedu.blocked_legacy_routes` redirige vers `/app/gpo/wine` (status `302`, pattern iso 16.2 D5 `gestion_gpo.php`).
**And** un test Feature `WineLegacyRouteRedirectTest` vérifie la 302.

### Volet 2 — Job queue Wine + extension ShortcutsService

**AC2.1 — `GenerateWineImageJob`**
**Given** la classe `App\Gpo\Jobs\GenerateWineImageJob` (`ShouldQueue`)
**When** elle est dispatchée avec `$application`
**Then** elle implémente :
- `__construct(string $application)` — validation regex stricte dans le constructeur (redondance défense en profondeur)
- `handle(): void` qui invoque `Process::run(['/usr/share/sambaedu/scripts/make_wine_image.sh', $this->application], timeout: 1800)`
- `tries = 1` (parité legacy idempotent — pas de retry)
- `timeout = 1800` (30 min, marge sur les 10 min annoncés)
- Logs channel `gpo` action `gpo.wine.image.generate` avec `operation_id` UUID propagé (sub `job.handle`)
- En cas d'échec : log `failure` avec stderr Process complet (tronqué 8 Ko)
- **`Process::run` en mode array** (audit §6.F F7 corrigé — pas de concaténation shell).

**AC2.2 — `ShortcutsService::importWineShortcuts`**
**Given** la méthode publique `App\Services\ShortcutsService::importWineShortcuts(string $application): int`
**When** elle est invoquée
**Then** :
- Délègue à `get_wine_shortcuts($config, $application)` via `legacy/bootstrap.php` (`@legacy-port` + `@todo Story 16.4 reprise native`)
- Merge dans `/etc/sambaedu/applications/shortcuts/shortcuts.json` (iso-legacy : `json_decode` existant → `array_merge` → `json_encode JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES` → `file_put_contents` atomique tmp/rename)
- Retourne le nombre de raccourcis ajoutés
- **Atomic write** : écriture dans `<filename>.tmp` puis `rename()` (parité `AtomicFileWriter` Story 15.1).

**AC2.3 — Pas de side effect AD/SYSVOL**
**Given** le Job ou le service `ShortcutsService::importWineShortcuts`
**When** ils s'exécutent
**Then** **aucun appel** à `samba-tool`, LDAP, SYSVOL, AD. Pure FS + Process external.

### Volet 3 — Endpoint `gpo/associations_out.php` (`AssociationsOutController`)

**AC3.1 — Route**
**Given** un poste client fait `POST /gpo/associations_out.php`
**When** la route Laravel `Route::match(['POST'], 'gpo/associations_out.php', [App\Http\Controllers\Gpo\AssociationsOutController::class, 'legacyOut'])` (déclarée AVANT le catchall ligne 453 de `routes/web.php`, après les routes 16.3b) prend en charge la requête
**And** le middleware `throttle:300,1` est appliqué
**And** **aucune** auth web n'est requise (pas de middleware `sambaedu.admin`).

> **Note** : on n'expose **pas GET** (l'endpoint legacy n'accepte que POST avec body `id` + `list`).

**AC3.2 — Validation `id` md5**
**Given** un appel avec `$_POST['id']` invalide (format ≠ 32 hex, ou absent)
**When** le Controller `legacyOut` est appelé
**Then** il retourne `400 Bad Request` avec body vide (parité legacy ligne 25-27 `header("HTTP/1.1 400 Bad request"); exit()`)
**And** aucun appel `apcu_fetch`, `WorkstationPackagesResolver::resolve`, `DOMDocument::load`.

**AC3.3 — Validation `list` POST**
**Given** un appel avec `id` valide mais `$_POST['list']` absent, non-JSON, ou > 10 Ko
**When** le Controller est appelé
**Then** il retourne `400 Bad Request` body vide (parité legacy ligne 26 `empty($list)`).

**AC3.4 — Contexte APCu absent**
**Given** un appel avec `id` valide mais expiré dans APCu
**When** `AppContextRepository::findById($id)` retourne `null`
**Then** il retourne `400 Bad Request` body vide (parité legacy ligne 23 `! is_array($id)` → 400)

> **Note** : ici la sémantique iso-legacy 400 (et non 200 body vide comme 16.3b) — `associations_out` n'a pas de fallback "noop bash" — il sert un JSON consommé par le poste. Un poste qui reçoit `{}` perd ses associations — mieux vaut le 400 explicite (parité legacy).

**AC3.5 — Cas nominal**
**Given** un appel avec `id` valide, `list` JSON décodable, contexte APCu présent
**When** le Controller invoque `AssociationsResolver::resolve(AppContext $context, array $localAssocs): array`
**Then** la sortie HTTP est :
- Status `200`
- `Content-Type: text/json` (iso-legacy ligne 170 — non-standard mais conservé)
- Body = `json_encode(['result' => $result], JSON_PRETTY_PRINT)`

**And** `AssociationsResolver::resolve` reproduit fidèlement la logique legacy `gpo/associations_out.php:31-167` :
1. Charger `packages.xml` (path = `config('sambaedu.wpkg.deploy_path').'/packages.xml'`) via `PackagesXmlAssociationsReader::read(): array` (port `@legacy-port`)
2. Pour chaque `<package>` dont l'`id` est dans `WorkstationPackagesResolver::resolve($context->machineName)`, extraire les `<Association>` enfants → `$associations[$packageId][$identifier] = ['ProgId' => ..., 'type' => 'file|protocol']`
3. Charger `default.xml` (`/usr/share/sambaedu/applications/associations/default.xml`) — gracieux si absent (`default = []`)
4. Charger `/usr/share/sambaedu/applications/associations/associations.json` (système) + `/etc/sambaedu/applications/associations/associations.json` (local) — filtré sur apps dans `$associations`
5. Inverser `$context['list']` (group/parc) + ajouter `all` au début + `force` à la fin (ordre legacy ligne 142-144)
6. Itérer en 2 passes (système puis local) — `$result = array_merge($result, $associations[$app])` pour chaque app match
7. Diff avec `$localAssocs` (l'input POST `list` parsé) : retirer du résultat les associations identiques (parité legacy ligne 162-166 `array_diff` vide)
8. Retourner `$result` (associatif `[$identifier => ['ProgId' => ..., 'type' => ...]]`)

**AC3.6 — `packages.xml` absent**
**Given** le fichier `packages.xml` n'existe pas (cas CI / dev sans WPKG installé)
**When** `PackagesXmlAssociationsReader::read()` est invoqué
**Then** il retourne `[]` et logge un warning (`daily`)
**And** la suite du flux retourne `{"result": {}}` (parité legacy `DOMDocument` gracieux silencieux).

**AC3.7 — Debug `/tmp/assoc_result.json`**
**Given** un appel nominal réussi
**When** la sortie est calculée
**Then** `file_put_contents("/tmp/assoc_result.json", json_encode($result, JSON_PRETTY_PRINT))` est invoqué (parité partielle legacy ligne 168)
**And** les 3 autres writes legacy (`assoc_local.json`, `assoc_app.json`, `assoc_wpkg.json`) **sont skippés** (D5)
**And** le write est skippé en `app()->environment('testing')` (parité 16.3b AC1.7 — évite la pollution FS en CI).

### Volet 4 — Routage + iso-contrat

**AC4.1 — Position dans `routes/web.php`**
**Given** la nouvelle route `gpo/associations_out.php`
**When** elle est déclarée
**Then** elle est positionnée **AVANT** le catchall ligne 453 (`Route::match(['GET',...,'HEAD'], '{path}', [LegacyCatchallController::class, 'handle'])`)
**And** dans la section commentée existante `/* Interception legacy gpo/network_out.php + gpo/veyon_out.php (Story 16.3b) */` (étendre la section pour inclure `associations_out.php` — pattern iso section 16.3b lignes 398-412)
**And** déclarée **hors** du groupe `sambaedu.admin` (pas d'auth web).

**AC4.2 — Iso-contrat vs legacy (comparison test)**
**Given** un fixture de référence capturé sur la VM AVANT migration (output legacy pour un id+context+list donné)
**When** la nouvelle route est appelée avec le même input
**Then** le diff structurel JSON (`ksort` récursif appliqué des deux côtés) entre output natif et output legacy est **identique**
**And** un test Feature `tests/Feature/Gpo/AssociationsOutComparisonTest.php` exécute la comparison à partir du fixture `tests/Fixtures/Gpo/legacy-associations-out.json` (skippable `@group requires-fixture-capture`).

**AC4.3 — Shim legacy conservé**
**Given** la story est implémentée
**When** la review est passée
**Then** les fichiers `legacy/modules/gpo/wine.php`, `legacy/modules/gpo/associations_out.php` (et `legacy/modules/gpo/applications.php` — hors scope) **ne sont PAS supprimés**
**And** `legacy/modules/gpo/wine.php` devient inaccessible (catchall redirect vers `/app/gpo/wine`)
**And** `legacy/modules/gpo/associations_out.php` devient inaccessible (route native AVANT catchall)
**And** `legacy/modules/gpo/applications.php` reste pleinement accessible (continue à poser `apps.$id`).

**AC4.4 — Catchall `blocked_legacy_routes` enrichi pour Wine**
**Given** `config/sambaedu.php`
**When** la story est implémentée
**Then** une nouvelle entrée est ajoutée dans `sambaedu.blocked_legacy_routes` :
```php
'^gpo/wine\.php(?:\?.*)?$' => '/app/gpo/wine',
```
**And** le test feature vérifie le 302.

### Volet 5 — Sécurité

**AC5.1 — Throttle 300/min/IP sur associations_out**
**Given** la route `gpo/associations_out.php`
**When** un client fait > 300 requêtes en 1 minute depuis la même IP
**Then** la 301ᵉ retourne `429 Too Many Requests` (middleware `throttle:300,1`)
**And** un test feature vérifie ce comportement (`@group throttle`).

**AC5.2 — Whitelist regex `$application` sur Wine (audit F7 corrigé)**
**Given** le Controller UI Wine et le Job `GenerateWineImageJob`
**When** ils reçoivent `$application`
**Then** la regex `^[a-zA-Z0-9._-]*$` est appliquée (chaîne vide autorisée pour le défaut)
**And** la chaîne doit appartenir à `WinePrefixScanner::list()` (sauf chaîne vide)
**And** **aucune** concaténation shell ; **`Process::run` en mode array obligatoire** (audit §6.F F7).
**And** un test feature `WineSecurityTest` vérifie qu'un input `$application = '; rm -rf /'` → 422 validation, aucun dispatch Job.

**AC5.3 — Pas de path traversal sur Associations input**
**Given** le Controller `AssociationsOutController`
**When** il reçoit `$_POST['list']`
**Then** il **ne lit aucun chemin** dérivé de l'input (les paths `default.xml`, `associations.json`, `packages.xml` sont **hardcodés** dans les services, jamais paramètres user).

**AC5.4 — Validation `id` md5 strict**
**Given** tous les inputs `id`
**When** ils sont reçus par `AssociationsOutController`
**Then** la regex `^[a-f0-9]{32}$` est appliquée AVANT tout accès APCu / DB / FS
**And** un test feature vérifie qu'un `id` malformé (`INJECTION`, `' OR 1=1 --`, `../../etc/passwd`) retourne `400` sans aucun appel `WorkstationPackagesResolver` / `apcu_fetch` / `DOMDocument`.

### Volet 6 — Tests

**AC6.1 — Tests Feature `WineController` Livewire** (`tests/Feature/Gpo/WinePageTest.php`)
Au moins **7 tests** :
1. `it_redirects_unauthenticated_to_login`
2. `it_returns_403_for_user_without_server_admin`
3. `it_renders_page_with_prefix_select_for_admin`
4. `it_lists_wine_prefixes_from_scanner_mock`
5. `it_dispatches_generate_image_job_with_valid_application` (mock `Queue::fake`)
6. `it_rejects_invalid_application_input` (`'; rm -rf /'`, `'../etc'`, etc.)
7. `it_calls_shortcuts_service_on_generate_shortcuts` (mock `ShortcutsService`)

**AC6.2 — Tests Unit Wine** (`tests/Unit/Gpo/WinePrefixScannerTest.php`, `tests/Unit/Gpo/WineImageQueuerTest.php`)
Au moins **5 tests** :
1. `WinePrefixScanner::list_returns_empty_when_dir_missing`
2. `WinePrefixScanner::list_parses_wine_prefix_subdirs`
3. `WinePrefixScanner::list_ignores_non_wine_dirs`
4. `WineImageQueuer::dispatch_pushes_job_to_default_queue` (mock Queue)
5. `WineImageQueuer::dispatch_logs_via_gpo_channel`

**AC6.3 — Tests Unit Job** (`tests/Unit/Gpo/GenerateWineImageJobTest.php`)
Au moins **3 tests** :
1. `it_validates_application_in_constructor` (regex)
2. `it_invokes_process_with_array_mode_not_shell_concat` (`Process::fake` Laravel)
3. `it_logs_failure_with_stderr_truncated_to_8kb`

**AC6.4 — Tests Feature `AssociationsOutController`** (`tests/Feature/Gpo/AssociationsOutEndpointTest.php`)
Au moins **9 tests** (pattern iso `NetworkOutEndpointTest`) :
1. `it_returns_400_for_invalid_id`
2. `it_returns_400_when_list_missing`
3. `it_returns_400_when_list_oversized` (> 10 Ko)
4. `it_returns_400_when_context_expired`
5. `it_returns_200_with_text_json_content_type_on_nominal_case`
6. `it_intersects_packages_xml_with_workstation_packages_resolver` (mock `WorkstationPackagesResolver`)
7. `it_loads_default_xml_associations_when_present`
8. `it_returns_empty_result_when_packages_xml_missing`
9. `it_returns_only_diff_associations_when_local_assocs_match_server` (delta logic)
10. `it_applies_throttle_300_per_minute` (`@group throttle`)
11. `it_writes_assoc_result_json_in_production_not_testing`

**AC6.5 — Tests Unit `AssociationsResolver`** (`tests/Unit/Gpo/AssociationsResolverTest.php`)
Au moins **6 tests** :
1. `it_returns_empty_result_when_no_apps_installed`
2. `it_includes_default_xml_associations`
3. `it_filters_by_workstation_apps_only`
4. `it_applies_user_groups_filter_with_all_and_force`
5. `it_local_associations_override_system_ones`
6. `it_returns_delta_only_vs_local_assocs_input`

**AC6.6 — Tests Unit `PackagesXmlAssociationsReader`** (`tests/Unit/Gpo/PackagesXmlAssociationsReaderTest.php`)
Au moins **4 tests** :
1. `it_returns_empty_when_file_missing`
2. `it_parses_association_elements_from_packages_xml` (fixture XML inline)
3. `it_defaults_type_to_file_when_missing`
4. `it_logs_warning_on_dom_load_failure`

**AC6.7 — Tests Feature comparison** (`tests/Feature/Gpo/AssociationsOutComparisonTest.php`)
- 1 test qui charge un fixture `tests/Fixtures/Gpo/legacy-associations-out.json` et diff la sortie native (sort_assoc récursif)
- Skippable `@group requires-fixture-capture`

**AC6.8 — Tests Feature route redirect Wine** (`tests/Feature/Gpo/WineLegacyRouteRedirectTest.php`)
- `it_redirects_legacy_wine_php_to_native_app_gpo_wine` (302)

**AC6.9 — Test architecture** (`tests/Architecture/GpoNamespaceTest.php` enrichi)
- Vérifier qu'aucun fichier sous `app/Gpo/Jobs/` n'importe `LdapRecord\*` ou `samba-tool` (pas de side-effect AD dans les Jobs Wine).
- Vérifier que `GenerateWineImageJob` utilise `Illuminate\Support\Facades\Process` mode array (pas `exec()` / `shell_exec()`).

**AC6.10 — Aucune régression**
**Given** la suite globale
**When** elle s'exécute
**Then** aucun test pré-existant ne casse (notamment 16.1, 16.2, 16.3a, 16.3b, 4.7, 4.8, 15.2 tests).

---

## Hors-scope (explicite)

- **Portage de `gpo/applications.php` natif** — Story 16-7 backlog (cf. D1). Le shim 1bis-18e reste vivant et seul à servir cette URL pendant Epic 16.
- **Mutation de GPO** (édition Section AD, samba-tool gpo set) — Stories 16.4 / 16.5.
- **UI Livewire d'édition** d'associations (gestionnaire admin des assoc système/local) — out-of-scope. Le legacy n'a pas d'UI pour cette config (JSON édité à la main). Si Henri veut une UI dédiée → nouvelle story.
- **Suppression du shim 1bis-18e** — interdite par garde-fou Epic 16 (Story 16.1 D6).
- **Couplage WPKG profondeur Job** : `WorkstationPackagesResolver` est consommé EN LECTURE seule. Aucun event WPKG émis depuis cette story. La logique de cache invalidation reste Story 15.x.
- **Extraction native du shim AD `create_ad_user`/`set_config`** — déjà fait en 16.3b post-review (option A Henri).
- **`get_wine_shortcuts` natif** — Story 16.4 (extension `ShortcutsService`).
- **Suppression des writes debug `/tmp/assoc_result.json`** — legacy debt, conservée pour parité (cf. AC3.7).
- **Tests E2E navigateur** — iso 4.8.
- **Cache de la sortie associations** — pas de cache (déjà mutualisé via `WorkstationPackagesResolver` 15.2 TTL 1000s). Throttle 300/min suffit.
- **Notification post-Job Wine** (email, push, dashboard) — out-of-scope. L'admin consulte `storage/logs/gpo/*.log`. Si Henri veut une notif → nouvelle story.
- **Permissions fines Wine** (ex. `gpo.wine.generate` séparé de `server.admin`) — `server.admin` suffit (audit `gpo` channel trace l'admin).

---

## Tasks / Subtasks

### Phase T0 — Cadrage & vérifications préalables

- [x] **T0.1** Lire `app/Http/Controllers/Gpo/NetworkOutController.php` (~150 lignes) — référence pattern Controller iso-contrat legacy (16.3b).
- [x] **T0.2** Lire `app/Gpo/Services/NetworkScriptGenerator.php` — référence pattern Service iso-legacy + `@legacy-port` docblock.
- [x] **T0.3** Lire `app/Wpkg/Deployment/Services/WorkstationPackagesResolver.php` (~150 lignes) — signature `resolve($hostname): Collection<string>` et cache.
- [x] **T0.4** Lire `app/Services/AppCustomization/ApcuAppContextRepository.php` + DTO `App\Dto\AppCustomization\AppContext` — pattern repository APCu réutilisable (déjà utilisé 4.7/4.8/16.3b).
- [x] **T0.5** Lire `app/Services/ShortcutsService.php` (existant) — pour comprendre la signature d'extension `importWineShortcuts`.
- [x] **T0.6** Lire `legacy/modules/gpo/wine.php` (79 lignes) + `sambaedu/includes/shortcuts.inc.php::get_wine_shortcuts` (lignes 523+) — source du portage Wine.
- [x] **T0.7** Lire `legacy/modules/gpo/associations_out.php` (173 lignes) + `sambaedu/includes/applications.inc.php::get_app_scripts_info` (lignes 826+) pour comprendre `$info['list']` / `$info['liste_applications']` / structure APCu — source du portage Associations.
- [x] **T0.8** Lire `config/sambaedu.php` `blocked_legacy_routes` (~ligne 42) + `wpkg.deploy_path` — confirmer la structure pour ajout entrée Wine + lecture `packages.xml`.
- [x] **T0.9** Smoke VM (manuel, **ACTION HENRI** si dispo) : vérifier que `apcu_fetch "apps.<id_test>"` retourne un dict avec clés `machine.cn`, `list`, `liste_applications`. **Si la chaîne shim 1bis-18e a régressé**, alerter en pré-dev.
- [x] **T0.10** Capturer (idéalement Henri sur VM) 1 fixture legacy de référence pour `associations_out.php?id=$VALID_ID&list=$VALID_LIST` → `tests/Fixtures/Gpo/legacy-associations-out.json`. **Si non disponible**, dev produit un fixture artisanal depuis la lecture mentale du code legacy et marque `@group requires-fixture-capture` skippable. Pas bloquant.
- [x] **T0.11** Vérifier l'existence de `App\Services\ShortcutsService::importWineShortcuts` (probablement absente) — sinon ajout en T2. Vérifier que `legacy/bootstrap.php` charge bien `shortcuts.inc.php` (ou ajouter le require dans le service).

### Phase T1 — Services Wine (logique pure)

- [x] **T1.1** Créer `app/Gpo/Services/WinePrefixScanner.php` :
  - Méthode `list(?string $basePath = null): list<string>` — scan FS `/var/sambaedu/unattended/install/wine`, extraction regex `^wine-(.*)$`, retour trié alpha.
  - Méthode `exists(string $application, ?string $basePath = null): bool` — pour validation.
  - Path configurable via `config('sambaedu.gpo.wine.prefix_base')` avec fallback hardcodé.
  - `declare(strict_types=1)` + PHPDoc complet + `@legacy-port path="sambaedu/gpo/wine.php:43-49"`.
- [x] **T1.2** Créer `app/Gpo/Services/WineImageQueuer.php` :
  - Méthode `dispatch(string $application): void` — validation regex + `Queue::push(new GenerateWineImageJob($application))` + log channel `gpo` (`gpo.wine.image.generate` step "queued").
- [x] **T1.3** Créer `app/Gpo/Jobs/GenerateWineImageJob.php` :
  - `ShouldQueue`, `Queueable`, `InteractsWithQueue`, `Dispatchable`, `SerializesModels`.
  - `__construct(string $application)` — validation regex (redondance).
  - `tries = 1`, `timeout = 1800`.
  - `handle(): void` — `Process::timeout(1800)->run(['/usr/share/sambaedu/scripts/make_wine_image.sh', $this->application])` + log channel `gpo` start/end + check `$result->successful()`.
  - `failed(\Throwable $e): void` — log channel `gpo` `failure` avec stderr tronqué 8 Ko.
- [x] **T1.4** Tests Unit `tests/Unit/Gpo/WinePrefixScannerTest.php` + `WineImageQueuerTest.php` + `GenerateWineImageJobTest.php` (AC6.2/AC6.3).

### Phase T2 — Extension `ShortcutsService::importWineShortcuts`

- [x] **T2.1** Étendre `app/Services/ShortcutsService.php` :
  - Méthode publique `importWineShortcuts(string $application): int`
  - Délègue à `get_wine_shortcuts($config, $application)` via `legacy/bootstrap.php` (`@legacy-port path="sambaedu/includes/shortcuts.inc.php:523"` + `@todo Story 16.4`).
  - Merge avec `/etc/sambaedu/applications/shortcuts/shortcuts.json` existant (atomic write tmp/rename).
  - Retourne `count($added)`.
  - Logs channel `gpo` action `gpo.wine.shortcuts.generate` (start + step + success).
- [x] **T2.2** Tests Feature `tests/Feature/ShortcutsService/ImportWineShortcutsTest.php` — mock legacy bootstrap + assert merge JSON + atomic write.

### Phase T3 — Services Associations (logique pure)

- [x] **T3.1** Créer `app/Gpo/Services/PackagesXmlAssociationsReader.php` :
  - Méthode `read(?string $packagesXmlPath = null): array<string, array<string, array{ProgId: string, type: string}>>` — retour structure `[$packageId => [$identifier => ['ProgId' => ..., 'type' => 'file|protocol']]]`.
  - Path = `$packagesXmlPath ?? config('sambaedu.wpkg.deploy_path').'/packages.xml'`.
  - Si fichier absent → return `[]` + log warning channel `daily`.
  - Sinon → `DOMDocument::load` (gracieux : try/catch + log warning si fail) → boucle `<package>` → boucle enfants `<Association>` → extraction attributs.
  - `declare(strict_types=1)` + `@legacy-port path="sambaedu/gpo/associations_out.php:41-66"`.
- [x] **T3.2** Créer `app/Gpo/Services/AssociationsResolver.php` :
  - Constructor : `__construct(PackagesXmlAssociationsReader $reader, WorkstationPackagesResolver $packagesResolver)`.
  - Méthode `resolve(AppContext $context, array $localAssocs): array` — reproduit fidèlement la logique legacy (cf. AC3.5 étapes 1-8).
  - Helper privé `loadJsonOrEmpty(string $path): array` (lecture associations.json système + local — gracieux file_exists).
  - Helper privé `loadDefaultXml(string $path): array` (default.xml — gracieux).
  - `@legacy-port path="sambaedu/gpo/associations_out.php:1-173"`.
- [x] **T3.3** Tests Unit `tests/Unit/Gpo/PackagesXmlAssociationsReaderTest.php` + `AssociationsResolverTest.php` (AC6.5/AC6.6).

### Phase T4 — Controllers `WineController` + `AssociationsOutController`

- [x] **T4.1** Créer `app/Http/Controllers/Gpo/WineController.php` :
  - Méthode `index()` — retourne la vue `pages.app.gpo.wine.index` (filesystem-based routing résoudra le Livewire SFC).
  - Pas de méthodes Livewire dans le Controller (déléguées au SFC).
- [x] **T4.2** Créer le composant Livewire SFC `resources/views/pages/app/gpo/wine/index.blade.php` :
  - `@php new class extends \Livewire\Component { use \App\Components\Traits\WithToasts; ... }`
  - State : `$prefixes = []`, `$selectedApplication = ''`, `$showImageConfirmModal = false`.
  - Méthode `mount(WinePrefixScanner $scanner)` — peuple `$this->prefixes`.
  - Méthode `confirmGenerateImage()` — ouvre modale.
  - Méthode `generateImage(WineImageQueuer $queuer)` — validation regex + appel queuer + toast info.
  - Méthode `generateShortcuts(ShortcutsService $service)` — validation regex + appel service + toast success.
  - Layout iso `/app/gpo/index.blade.php` (sidebar + breadcrumb).
- [x] **T4.3** Créer `app/Http/Controllers/Gpo/AssociationsOutController.php` :
  - `__construct(AppContextRepository $contextRepo, AssociationsResolver $resolver)`.
  - Méthode `legacyOut(Request $request): Response` :
    - Validation `id` md5 (regex `^[a-f0-9]{32}$`) → 400 si fail (AC3.2)
    - Validation `list` POST présent + JSON décodable + ≤ 10 Ko (AC3.3) → 400 si fail
    - `$context = $contextRepo->findById($id)` → 400 si null (AC3.4)
    - `$result = $resolver->resolve($context, $localAssocs)`
    - Write `/tmp/assoc_result.json` (skippé en testing — AC3.7)
    - Return `response()->json(['result' => $result], 200, ['Content-Type' => 'text/json'], JSON_PRETTY_PRINT)` (iso-legacy header non-standard).
- [x] **T4.4** Tests Feature `tests/Feature/Gpo/WinePageTest.php` + `AssociationsOutEndpointTest.php` (AC6.1/AC6.4).

### Phase T5 — Routage `routes/web.php` + `blocked_legacy_routes`

- [x] **T5.1** Ajouter dans `routes/web.php` (AVANT le catchall ligne 453, dans la section existante Story 16.3b lignes 398-412) :
  ```php
  Route::match(['POST'], 'gpo/associations_out.php', [\App\Http\Controllers\Gpo\AssociationsOutController::class, 'legacyOut'])
      ->middleware('throttle:300,1')
      ->name('gpo.associations-out.legacy');
  ```
- [x] **T5.2** Ajouter la route admin Wine dans le groupe `sambaedu.admin` (chercher le groupe existant pour `/app/gpo` Story 16.2) :
  ```php
  Route::get('/app/gpo/wine', [\App\Http\Controllers\Gpo\WineController::class, 'index'])
      ->name('app.gpo.wine');
  ```
  (alternative : si le filesystem-based router pose la route automatiquement à partir de `resources/views/pages/app/gpo/wine/index.blade.php`, vérifier que le groupe permission est appliqué automatiquement).
- [x] **T5.3** Ajouter dans `config/sambaedu.php` la nouvelle entrée `blocked_legacy_routes` :
  ```php
  '^gpo/wine\.php(?:\?.*)?$' => '/app/gpo/wine',
  ```
- [x] **T5.4** Tests Feature `WineLegacyRouteRedirectTest` + `AssociationsOutRouteRegistrationTest` (vérif route native avant catchall).

### Phase T6 — Enrichissement `NativeSectionResolver` (16.3a)

- [x] **T6.1** Étendre `app/Gpo/Support/NativeSectionResolver::MAPPING` :
  - Ajouter une entrée `'wine'` → `['url' => '/app/gpo/wine', 'label' => 'Wine (apps Linux/Windows)']`
  - Regex de match : `*wine*` (case-insensitive, parité 16.3a pattern).
- [x] **T6.2** Test Unit `NativeSectionResolverTest::it_matches_wine_gpo_to_native_app_gpo_wine`.

### Phase T7 — Documentation & QA VM + Story 16-7 backlog

- [x] **T7.1** Créer la Story 16-7 « Portage natif applications.php (générateur scripts startup/logon) » dans `_bmad-output/implementation-artifacts/sprint-status.yaml` :
  ```yaml
  16-7-portage-natif-applications-php: backlog  # Décision SM 16-3c D1. Porter `gpo/applications.php` + `applications.inc.php` (1007 lignes — get_app_scripts_info, make_application_scripts, read_application_scripts, log_application_scripts, redirect/local_admin/wpkg/apt/sudo/once_scripts, header/footer/powershell_scripts). Surface AD massive (search_machine, search_user, check_computer, get_machine_status, register_machine_hardware, set_os, list_remote_connexion, log_connexion + 7 constantes SAMBAEDU_*_ERROR). C'est l'endpoint qui POSE `apps.$id` consommé par 4.7/4.8/16.3b/16.3c. Pré-requis : avancement Epic AD natif (ou intégration progressive). Estimation 10-15j.
  ```
  Et le mentionner dans `_bmad-output/planning-artifacts/epics.md` (épopée 16 — backlog Story 16-7).
- [x] **T7.2** Ajouter **section 5** dans `docs/qa/domains/gpo.md` (créé en 16.1, sections 1-4 livrées) — append-only — scénarios QA manuels VM :
  1. `curl -X POST -d "id=$VALID_ID" -d "list=$VALID_LIST" http://localhost/gpo/associations_out.php | jq .` retourne JSON parseable avec section `result`.
  2. `id` invalide → 400.
  3. `list` absent → 400.
  4. `id` valide mais APCu expiré → 400.
  5. Cas nominal : poste avec apps WPKG installées → `result` contient les associations attendues (croisement `packages.xml` + `WorkstationPackagesResolver`).
  6. Page admin `/app/gpo/wine` accessible avec `server.admin`, 403 sinon.
  7. Click "Générer l'image" → Job dispatché (vérifier `php artisan queue:work --once` consomme et lance `make_wine_image.sh`).
  8. Click "Générer les raccourcis" → `/etc/sambaedu/applications/shortcuts/shortcuts.json` enrichi.
  9. Redirect `/gpo/wine.php` → `/app/gpo/wine` (302).
  10. Route native `/gpo/associations_out.php` prioritaire sur catchall (`php artisan route:list | grep associations_out`).
  11. `/tmp/assoc_result.json` écrit après appel nominal.
  12. `apps.$id` toujours posé par `applications.php` shim (vérifier `apcu_fetch` après logon poste).
- [x] **T7.3** Documenter dans `app/Gpo/README.md` (Story 16.1) :
  - Section "Endpoint runtime postes clients (Story 16.3c)" : `/gpo/associations_out.php` → `AssociationsOutController`.
  - Section "UI admin native Wine (Story 16.3c)" : `/app/gpo/wine` → `WineController` + Livewire SFC + Job queue.
  - Enrichissement catalogue `action_type` : `gpo.wine.image.generate`, `gpo.wine.shortcuts.generate`, `gpo.wine.prefixes.list`.
- [x] **T7.4** Ajouter convention `@legacy-port` sur **tous** les helpers portés (`WinePrefixScanner`, `WineImageQueuer`, `GenerateWineImageJob`, `PackagesXmlAssociationsReader`, `AssociationsResolver`, `ShortcutsService::importWineShortcuts`) + 5-7 entrées dans `docs/tech-debt-gpo.md`.

### Phase T8 — Validation finale

- [ ] **T8.1** Lancer `php artisan test tests/Feature/Gpo tests/Unit/Gpo tests/Architecture` sur la VM (**ACTION HENRI**)
- [ ] **T8.2** Lancer `php artisan test` complet — aucune régression vs baseline (**ACTION HENRI**)
- [ ] **T8.3** Smoke QA VM selon checklist T7.2 (**ACTION HENRI**) :
  - Wine : `php artisan queue:work --once` après dispatch → script shell exécuté
  - Associations : flow réel poste Windows logon → `/gpo/associations_out.php` répond JSON valide
- [x] **T8.4** Mettre à jour `_bmad-output/implementation-artifacts/sprint-status.yaml` : `16-3c-wine-associations-apps: ready-for-dev` → `review` (✅ dev — 2026-05-12)
- [ ] **T8.5** Vérifier qu'aucune régression sur le shim 1bis-18e pour `applications.php` (la chaîne `apps.$id` doit rester intacte — chaîne 4.7/4.8/16.3b/16.3c en dépend tous). (**ACTION HENRI**)

---

## File List prévisionnelle

### Fichiers créés

```
# Volet Wine
app/Http/Controllers/Gpo/WineController.php
app/Gpo/Services/WinePrefixScanner.php
app/Gpo/Services/WineImageQueuer.php
app/Gpo/Jobs/GenerateWineImageJob.php
resources/views/pages/app/gpo/wine/index.blade.php  (Livewire SFC)
resources/views/pages/app/gpo/wine/_partials/      (optionnel — découpe si besoin)

# Volet Associations
app/Http/Controllers/Gpo/AssociationsOutController.php
app/Gpo/Services/AssociationsResolver.php
app/Gpo/Services/PackagesXmlAssociationsReader.php

# Tests
tests/Unit/Gpo/WinePrefixScannerTest.php
tests/Unit/Gpo/WineImageQueuerTest.php
tests/Unit/Gpo/GenerateWineImageJobTest.php
tests/Unit/Gpo/AssociationsResolverTest.php
tests/Unit/Gpo/PackagesXmlAssociationsReaderTest.php
tests/Feature/Gpo/WinePageTest.php
tests/Feature/Gpo/WineSecurityTest.php
tests/Feature/Gpo/WineLegacyRouteRedirectTest.php
tests/Feature/Gpo/AssociationsOutEndpointTest.php
tests/Feature/Gpo/AssociationsOutComparisonTest.php       ← fixture-based, skippable
tests/Feature/ShortcutsService/ImportWineShortcutsTest.php

# Fixtures
tests/Fixtures/Gpo/legacy-associations-out.json          ← fixture VM (T0.10)
tests/Fixtures/Gpo/packages-xml-sample.xml               ← fixture inline pour reader test
```

### Fichiers modifiés

```
routes/web.php                                  (+1 route AssociationsOutController + 1 route WineController)
config/sambaedu.php                             (+1 entrée blocked_legacy_routes gpo/wine.php)
app/Services/ShortcutsService.php                (+ méthode importWineShortcuts)
app/Gpo/Support/NativeSectionResolver.php        (+ entrée MAPPING wine — T6.1)
app/Gpo/README.md                                (+ section Wine + section Associations + enrichissement catalogue action_type)
docs/qa/domains/gpo.md                           (+ section 5 Story 16.3c — append-only)
docs/tech-debt-gpo.md                            (+ 5-7 entrées @legacy-port portés)
_bmad-output/implementation-artifacts/sprint-status.yaml  (status update T8.4 + ajout Story 16-7)
_bmad-output/planning-artifacts/epics.md         (mention Story 16-7 backlog Epic 16)
tests/Unit/Gpo/NativeSectionResolverTest.php     (+ test wine mapping)
tests/Architecture/GpoNamespaceTest.php          (+ règle Jobs/ pas de LdapRecord/samba-tool)
```

### Fichiers NON touchés (régression à éviter)

- `app/Gpo/Services/GpoService.php` — aucune modification (stubs écriture non consommés)
- `app/Gpo/Support/{GpoLogger,SambaToolRunner,GpoActionLog}.php` — aucune modification (Wine = logs channel gpo via GpoLogger statiques existants ; Associations = channel daily standard)
- `app/Gpo/Dto/*` — aucune modification (réutilisation `App\Dto\AppCustomization\AppContext`)
- `app/Wpkg/Deployment/Services/WorkstationPackagesResolver.php` — **réutilisation seule, AUCUNE modification** (limite stricte 16.3c vs 15.x)
- `legacy/modules/gpo/{wine,associations_out,applications}.php` — non supprimés (AC4.3)
- `app/Http/Controllers/Gpo/{NetworkOutController,VeyonOutController}.php` — aucune modification (16.3b intact)
- `app/Gpo/Services/{NetworkScriptGenerator,VeyonConfigGenerator,ReadUserManager}.php` — aucune modification (16.3b intact)
- `app/Ldap/AdUserManager.php` + `app/Config/SambaEduConfig.php` — aucune modification (16.3b post-review intacts)
- Toutes les Stories 16.1/16.2/16.3a/16.3b — aucune régression attendue.

---

## Test Strategy

### Couverture par niveau

| Niveau | Périmètre | Fichier |
|---|---|---|
| **Unit** | `WinePrefixScanner` (scan FS, regex extraction) — logique pure | `tests/Unit/Gpo/WinePrefixScannerTest.php` |
| **Unit** | `WineImageQueuer` (dispatch, log, regex defense) | `tests/Unit/Gpo/WineImageQueuerTest.php` |
| **Unit** | `GenerateWineImageJob` (validation constructeur, Process::fake, log failure) | `tests/Unit/Gpo/GenerateWineImageJobTest.php` |
| **Unit** | `PackagesXmlAssociationsReader` (fixture XML inline, gracieux fichier absent) | `tests/Unit/Gpo/PackagesXmlAssociationsReaderTest.php` |
| **Unit** | `AssociationsResolver` (logique métier intersection/filtrage/delta) | `tests/Unit/Gpo/AssociationsResolverTest.php` |
| **Feature** | `WineController` Livewire (route, permission, 2 actions, validation) | `tests/Feature/Gpo/WinePageTest.php` |
| **Feature** | Sécurité Wine (whitelist regex + injection shell) | `tests/Feature/Gpo/WineSecurityTest.php` |
| **Feature** | Redirect `/gpo/wine.php` → `/app/gpo/wine` (302) | `tests/Feature/Gpo/WineLegacyRouteRedirectTest.php` |
| **Feature** | `AssociationsOutController` (route, validation 400, structure JSON, intersection) | `tests/Feature/Gpo/AssociationsOutEndpointTest.php` |
| **Feature comparison** | Diff structurel native vs fixture legacy | `tests/Feature/Gpo/AssociationsOutComparisonTest.php` (skippable) |
| **Feature** | `ShortcutsService::importWineShortcuts` (mock legacy bootstrap + merge JSON) | `tests/Feature/ShortcutsService/ImportWineShortcutsTest.php` |
| **Architecture** | Pas de LdapRecord/samba-tool dans `app/Gpo/Jobs/` ; Process en mode array | `tests/Architecture/GpoNamespaceTest.php` (enrichi) |
| **Smoke VM (manuel)** | 12 scénarios QA T7.2 (Wine queue, Associations real flow, redirect 302) | `docs/qa/domains/gpo.md` § 5 |

### Stratégie de mock

- **`AppContextRepository`** : binding container avec stub (`AppContext::fromApcuArray([...])`). Pattern iso `AppPolicyLegacyEndpointTest` + `NetworkOutEndpointTest`.
- **`WorkstationPackagesResolver`** : mock via container binding (retour `collect(['firefox', 'libreoffice'])` par défaut).
- **`PackagesXmlAssociationsReader`** : injecter via constructor → mock dans `AssociationsResolverTest`.
- **`Queue::fake()`** : Laravel standard pour `WinePageTest` / `WineImageQueuerTest`.
- **`Process::fake([...])`** : Laravel standard pour `GenerateWineImageJobTest`.
- **`Storage::fake()`** : pour FS-dépendants (scan préfixes, JSON merge atomic).
- **`legacy/bootstrap.php`** dans `ImportWineShortcutsTest` : mock via container override (binding stub de `get_wine_shortcuts`).

### Tests à NE PAS faire dans cette story

- E2E navigateur (iso 4.8).
- Tests réels `make_wine_image.sh` (= smoke manuel VM, T8.3 — script externe long).
- Tests réels poste Windows logon flow `associations_out.php` (= smoke VM).
- Tests de la chaîne complète `applications.php` → `apps.$id` → `associations_out.php` (`applications.php` hors scope D1 — vérifier que le shim 1bis-18e n'a pas régressé en T0.9).
- Bench performance (sortie déterministe stateless ~30ms/req).

---

## Dev Notes — Contraintes & décisions cadrage SM

### Décisions SM rappelées (cf. tableau Décisions SM ci-dessus)

| # | Décision | Impact dev |
|---|---|---|
| D1 | `applications.php` hors scope (Story 16-7 backlog) | Crée la Story 16-7 en T7.1 |
| D2 | Wine = UI admin native + Job queue + ShortcutsService extension | 5 fichiers Wine |
| D3 | Associations = endpoint runtime strict iso 16.3b | 3 fichiers Associations |
| D4 | 4 Services métier + 1 Job + 2 Controllers | 7 fichiers code |
| D5 | `WorkstationPackagesResolver` (15.2) seule source apps installées | Pas de shim mysqli |
| D6 | `packages.xml` via `config('sambaedu.wpkg.deploy_path')` | Gracieux absent |
| D7 | Validation `id` md5 + `list` JSON ≤ 10 Ko + 400 iso-legacy | Pattern iso 16.3b |
| D8 | Iso-bytes JSON `text/json` + comparison fixture | Tests AC6.7 |
| D9 | Channels logs distincts : Wine=gpo, Associations=daily | Cohérence Epic 16 |
| D10 | Enrichir `NativeSectionResolver` pour Wine OUI, Associations NON | T6.1 |
| D11 | Tests Wine = Feature Livewire + Unit + smoke VM | Pas d'E2E |
| D12 | Tests Associations = Feature + Unit + comparison fixture | Pattern iso 16.3b |

### Références codebase pour le dev

- **Pattern Controller iso-legacy** :
  - `app/Http/Controllers/Gpo/NetworkOutController.php` (16.3b — référence directe)
  - `app/Http/Controllers/Gpo/VeyonOutController.php` (16.3b)
  - `app/Http/Controllers/AppPolicyController.php` (4.8)
- **Pattern Service iso-legacy** :
  - `app/Gpo/Services/NetworkScriptGenerator.php` (16.3b — `@legacy-port` exemple)
  - `app/Gpo/Services/VeyonConfigGenerator.php` (16.3b — port DOM/XML idem `PackagesXmlAssociationsReader`)
- **Pattern UI Livewire SFC admin** :
  - `resources/views/pages/app/gpo/index.blade.php` (16.2 — référence directe)
  - `resources/views/pages/app/gpo/[guid]/index.blade.php` (16.2)
- **Pattern Job queue** :
  - `app/Jobs/SyncShortcutJob.php` (référence pour pattern Job avec ShortcutsService)
  - `app/Wpkg/Deployment/Jobs/SyncAllFromAdJob.php` (15.3 — Job complexe)
- **Pattern Repository APCu** :
  - `app/Services/AppCustomization/ApcuAppContextRepository.php` (39 lignes)
  - Interface `app/Services/AppCustomization/Contracts/AppContextRepository.php`
  - DTO `app/Dto/AppCustomization/AppContext.php`
- **Pattern Tests Feature** :
  - `tests/Feature/Gpo/NetworkOutEndpointTest.php` (16.3b — référence directe AssociationsOutEndpointTest)
  - `tests/Feature/Gpo/NetworkOutSecurityTest.php` (16.3b — référence WineSecurityTest)
  - `tests/Feature/AppCustomization/AppPolicyLegacyEndpointTest.php` (4.8)
- **Pattern Routes** :
  - `routes/web.php:398-412` (`gpo/network_out.php` + `gpo/veyon_out.php` — Section à étendre)
  - `routes/web.php:390-396` (`gpo/firefox_out.php`)
- **Bridge config legacy → Laravel** :
  - `app/Config/SambaEduConfig.php` (`->get($key, $default)`, `->set($key, $value)` étendue 16.3b)
  - `config/sambaedu.php` (`blocked_legacy_routes` ligne 42, `wpkg.deploy_path`)
- **Sources legacy à porter** :
  - `legacy/modules/gpo/wine.php` (79 lignes — copie de `sambaedu/gpo/wine.php`)
  - `legacy/modules/gpo/associations_out.php` (173 lignes — copie de `sambaedu/gpo/associations_out.php`)
  - `sambaedu/includes/shortcuts.inc.php:523` (`get_wine_shortcuts`)
- **WPKG Eloquent natif (15.2)** :
  - `app/Wpkg/Deployment/Services/WorkstationPackagesResolver.php` (signature `resolve($hostname): Collection<string>`)
  - `app/Wpkg/Deployment/README.md` ligne 73 (mapping legacy → natif)
- **Constantes / fichiers système attendus** :
  - `/var/sambaedu/unattended/install/wine/wine-<X>` (préfixes Wine scan)
  - `/usr/share/sambaedu/scripts/make_wine_image.sh` (script shell consumé par Job)
  - `/etc/sambaedu/applications/shortcuts/shortcuts.json` (merge Wine shortcuts)
  - `<wpkg.deploy_path>/packages.xml` (XML DOM)
  - `/usr/share/sambaedu/applications/associations/default.xml` (assoc défauts)
  - `/usr/share/sambaedu/applications/associations/associations.json` (système)
  - `/etc/sambaedu/applications/associations/associations.json` (local)
  - `/tmp/assoc_result.json` (debug output, parité partielle legacy)
- **Audit legacy** : `_bmad-output/planning-artifacts/audit-gpo-legacy.md`
  - §6.A fiche `gpo/wine.php` (ligne 332)
  - §6.A fiche `gpo/associations_out.php` (ligne 172)
  - §6.A fiche `gpo/applications.php` (ligne 156, hors scope D1)
  - §6.F F7 (`batch_command` injection)

### Pièges identifiés

1. **`batch_command` injection legacy F7 audit** : `wine.php:61` `batch_command("/usr/share/sambaedu/scripts/make_wine_image.sh " . $application)` — `$application` vient de `$_POST` non échappé. **Port natif obligatoire en mode array** : `Process::run(['/usr/share/sambaedu/scripts/make_wine_image.sh', $application])`. Whitelist regex obligatoire AVANT dispatch. AC5.2 + tests `WineSecurityTest`.

2. **Bug legacy `wine.php:52`** : `if ($application = $select_application)` — assignment au lieu de comparaison. **NE PAS reproduire**. Cf. AC1.6. Tests Feature vérifient l'attribut `selected` posé correctement.

3. **`get_wine_shortcuts` shim** : `legacy/bootstrap.php` charge `shortcuts.inc.php` ? À vérifier en T0.11. Si pas chargé, ajouter le `require_once` dans `ShortcutsService::importWineShortcuts` avec garde `app()->environment() !== 'testing'` ou via mock.

4. **`packages.xml` absent en CI** : `DOMDocument::load` legacy émet un warning silencieux + continue. Port natif **doit** : (a) `file_exists` check AVANT load, (b) try/catch sur DOMDocument, (c) log warning channel `daily`, (d) retour `[]` gracieux. AC3.6.

5. **`default.xml` schema vs `associations.json` schema** : legacy ligne 79-97 lit le XML pour les assoc défauts, ligne 116-138 lit le JSON pour les assoc système/local. **Schémas différents** :
   - default.xml : `<Association ProgId="..." Identifier="..." type="..."/>` à plat
   - associations.json : `{"AppId": ["NomUserOuParc1", "Parc2"]}` (mapping app → liste de groupes)
   Reproduire iso (cf. AC3.5 étapes 3-4).

6. **Ordre du `list` legacy `array_reverse + array_unshift "all" + array_push "force"`** (ligne 142-144) : la sémantique est que `all` matche tous les contextes, `force` matche le contexte poste forcé. **Iso strict**.

7. **`apps.$id` shape** : `info_poste_applications` legacy retourne un dict avec clés `id`, `action`, `remote`, `context`, `user`, `cloud`, `salle`, `machine`, `list`, `list_u`, `list_ue`, `list_m`, `liste_applications`, `admin`, `os`, `time`. Le DTO `App\Dto\AppCustomization\AppContext` (4.8) **n'expose pas toutes** ces clés. **Pour Associations, on a besoin** de :
   - `$context->machineName` (= `machine.cn`)
   - `$context->raw['list']` (groupes user + parcs)
   - Plus rien d'autre (apps installées = via `WorkstationPackagesResolver::resolve($machineName)`).
   **Pas besoin** d'étendre le DTO — le `raw` array dans `AppContext` couvre tout.

8. **Atomic write `shortcuts.json`** : risque de corruption si 2 generateShortcuts simultanés. Utiliser `flock` + tmp/rename (parité `AtomicFileWriter` Story 15.1, déjà éprouvé).

9. **Timeout Job `make_wine_image.sh`** : 10 min annoncé legacy, 30 min `timeout` Laravel (marge). Si dépasse → log failure stderr.

10. **`text/json` non-standard** : iso-legacy ligne 170. Conservé pour bytes-strict. **NE PAS** mettre `application/json`. Les clients postes ne sont pas affectés.

11. **`assoc_result.json` write dans /tmp** : peut polluer si dossier non writable en CI. Conditionner sur `app()->environment() !== 'testing'` (parité 16.3b AC1.7).

12. **`NativeSectionResolver::MAPPING` Wine** : la regex doit être précise pour ne pas matcher un GPO appelé "wineries" ou "wineland". Recommandation : match `*wine*` mais en boundary word ou regex stricte `^(.*[\s_-])?wine([\s_-].*)?$`. À discuter pendant dev (cf. story 16.3a D2 — multi-match autorisé, donc peu de risque).

13. **`packages.xml` size** : peut être > 5 Mo en prod (cf. Story 15.x). `DOMDocument::load` charge en mémoire — OK Laravel par défaut, mais à surveiller. Pas de streaming nécessaire à ce stade.

14. **Charge estimée 3-4 jours réaliste** : Wine UI = 1.5j (form Livewire + Job + ShortcutsService extension + 7 tests). Associations = 1.5j (XML DOM parsing + 3 sources JSON merging + delta logic + 9+ tests). Tests + doc + QA VM = 0.5j. Investigation T0 + Story 16-7 création = 0.25j.

### Catalogue `action_type` enrichi (Story 16.1 AC1.3)

| `action_type` | Quand l'émettre | Story |
|---|---|---|
| `gpo.wine.prefixes.list` | Scan FS des préfixes Wine | 16.3c |
| `gpo.wine.image.generate` | Dispatch Job + handle Job + success/failure | 16.3c |
| `gpo.wine.shortcuts.generate` | Appel `ShortcutsService::importWineShortcuts` | 16.3c |

(à documenter dans `app/Gpo/README.md` T7.3)

---

## Project Structure Notes

### Alignement structure projet

- **Controllers** : `app/Http/Controllers/Gpo/` (sous-dossier dédié — créé en 16.3b). Ajout `WineController` + `AssociationsOutController`.
- **Services métier** : `app/Gpo/Services/` (sous-dossier déjà créé 16.1) — ajout `WinePrefixScanner`, `WineImageQueuer`, `AssociationsResolver`, `PackagesXmlAssociationsReader`.
- **Jobs** : `app/Gpo/Jobs/` (sous-dossier déjà créé 16.1 mais vide) — ajout `GenerateWineImageJob`. **Premier Job du namespace `App\Gpo`**.
- **Tests Unit** : `tests/Unit/Gpo/` (existe depuis 16.1).
- **Tests Feature** : `tests/Feature/Gpo/` (existe depuis 16.1, enrichi 16.2/16.3a/b).
- **Tests Feature non-Gpo** : `tests/Feature/ShortcutsService/` (créer pour `ImportWineShortcutsTest`).
- **Fixtures** : `tests/Fixtures/Gpo/` (existe depuis 16.3b).
- **Routes** : déclarées dans `routes/web.php` (iso-contrat URL legacy `associations_out.php` + admin `app/gpo/wine`).
- **Vue Livewire SFC** : `resources/views/pages/app/gpo/wine/index.blade.php` (filesystem-based routing).
- **Config** : `config/sambaedu.php` (`blocked_legacy_routes` + éventuel `gpo.wine.prefix_base`).

### Conflits / variances détectés

| Élément | Doc/convention | Décision Story 16.3c | Justification |
|---|---|---|---|
| Channel logs Wine vs Associations | 16.1 D2 (channel `gpo` Epic 16 large) | Wine = `gpo` ; Associations = `daily` | D9 — distinction UI admin (auditable) vs runtime poste (parité 16.3b D9). |
| `text/json` non-standard | `application/json` plus correct | `text/json` iso-legacy | D8 / AC3.5 — bytes-strict legacy. |
| `applications.php` portage | Cadrage initial 3 fichiers | 2 fichiers + Story 16-7 backlog | D1 — surface AD trop massive pour 3-4j (10-15j seul). |
| Wine = UI admin sans iso-URL | 16.3b a iso-URL strict | Wine n'a pas d'URL en dur côté postes | D2 — wine.php est admin, pas runtime. Redirect catchall = pattern 16.2 D5. |
| Job retry policy | Laravel default `tries=3` | `tries=1` (parité legacy idempotent) | AC2.1 — un échec n'est pas relancé silencieusement ; l'admin relance manuellement. |

---

## References

- **Audit legacy** : `_bmad-output/planning-artifacts/audit-gpo-legacy.md`
  - §6.A fiche `gpo/wine.php` (ligne 332)
  - §6.A fiche `gpo/associations_out.php` (ligne 172)
  - §6.A fiche `gpo/applications.php` (ligne 156, hors scope D1)
  - §6.C tableau sections spécialisées (ligne 481-484)
  - §6.F F7 `batch_command` injection (ligne 587)
  - §6.G recommandation Story 16.3c (ligne 639)
- **Stories de référence (pattern)** :
  - `_bmad-output/implementation-artifacts/16-3b-network-veyon.md` — pattern Controller iso-contrat + Service + tests comparison fixture
  - `_bmad-output/implementation-artifacts/16-2-listing-lecture-gpo-ui-native.md` — pattern UI admin Livewire + permission
  - `_bmad-output/implementation-artifacts/16-3a-liens-profonds-sections-natives.md` — `NativeSectionResolver`
  - `_bmad-output/implementation-artifacts/15-2-generators-xml-ini-par-poste.md` — `WorkstationPackagesResolver`
  - `_bmad-output/implementation-artifacts/4-8-personnalisation-apps-extensible.md` — pattern `AppContextRepository`
- **Fondations Epic 16** :
  - `_bmad-output/implementation-artifacts/16-1-fondations-gpo-natives-audit-legacy.md` — namespace `App\Gpo`, catalogue `action_type`, `@legacy-port` convention
  - `app/Gpo/README.md` (Story 16.1) — convention de logging Epic 16
- **Cadrage Epic 16** :
  - `_bmad-output/planning-artifacts/epics.md` — cadrage Story 16.3 splittée a/b/c
  - `_bmad-output/planning-artifacts/implementation-readiness-report-2026-05-05-epic16.md`
- **Architecture** : `_bmad-output/planning-artifacts/architecture.md:332-353` (couche Services + règle "Controllers fins")
- **Sources legacy** :
  - `sambaedu/gpo/wine.php` (79 lignes)
  - `sambaedu/gpo/associations_out.php` (173 lignes)
  - `sambaedu/gpo/applications.php` (51 lignes — hors scope D1, lecture seule pour comprendre le shim)
  - `sambaedu/includes/applications.inc.php` (1007 lignes — `get_app_scripts_info` ligne 826, lecture pour structure `apps.$id`)
  - `sambaedu/includes/shortcuts.inc.php:523` (`get_wine_shortcuts`)
  - `sambaedu/includes/wpkg_libsql.php:212` (`info_poste_applications` legacy, **remplacé** par `WorkstationPackagesResolver` natif 15.2)
- **Code natif référence** :
  - `app/Http/Controllers/Gpo/NetworkOutController.php` (16.3b)
  - `app/Http/Controllers/Gpo/VeyonOutController.php` (16.3b)
  - `app/Gpo/Services/NetworkScriptGenerator.php` + `VeyonConfigGenerator.php` (16.3b)
  - `app/Wpkg/Deployment/Services/WorkstationPackagesResolver.php` (15.2)
  - `app/Services/AppCustomization/ApcuAppContextRepository.php`
  - `app/Services/AppCustomization/Contracts/AppContextRepository.php`
  - `app/Dto/AppCustomization/AppContext.php`
  - `app/Services/ShortcutsService.php` (extension `importWineShortcuts`)
  - `app/Config/SambaEduConfig.php` (étendu 16.3b avec `set` native)
- **Tests référence** :
  - `tests/Feature/Gpo/NetworkOutEndpointTest.php` (16.3b)
  - `tests/Feature/Gpo/NetworkOutSecurityTest.php` (16.3b)
  - `tests/Feature/Gpo/VeyonOutComparisonTest.php` (16.3b)
  - `tests/Unit/Gpo/NetworkScriptGeneratorTest.php` (16.3b)
- **Permission** : `server.admin` (UI Wine, iso 16.2). Aucune (associations, iso 16.3b).
- **Doc QA** : `docs/qa/domains/gpo.md` (sections 1-4 existantes, ajout section 5 16.3c T7.2)
- **Doc tech debt** : `docs/tech-debt-gpo.md` (créé 16.1, enrichi 16.3b — 5-7 entrées 16.3c)

---

## Dev Agent Record

### Agent Model Used

**claude-opus-4-7 (1M context)** — modèle recommandé par SM. Confiance 90%.
La complexité métier d'`AssociationsResolver` (intersection cross-source +
delta + ordre `array_reverse/unshift/push` iso-bytes) et la défense en
profondeur audit §6.F F7 sur Wine ont justifié l'allocation opus.

### Debug Log References

Aucune dette de debug — implémentation directe sans cycles d'investigation
prolongés. Les patterns 16.2 (UI admin) et 16.3b (endpoint runtime iso-contrat)
ayant été éprouvés en upstream, le portage 16.3c a été factorisé sans dérives.

### Completion Notes List

**Décisions sur les 5 discrepances SM ouvertes :**

- **(a) Idempotence Job Wine — `Cache::lock` anti-dispatch double** : tranché
  côté `WineImageQueuer::dispatch` avec `Cache::lock('gpo:wine:generate-image:{app}', 1800)->get()`
  non-bloquant. Si déjà détenu → `WineImageAlreadyQueuedException` métier
  remontée → toast warning UI. Lock libéré par `GenerateWineImageJob::handle()`
  (success/exception) et `failed()` (via `Cache::lock(...)->forceRelease()`).
  TTL = `timeout` Job (1800s) pour éviter lock zombie si Job timeout.
  Lock côté queuer + release côté Job = ceinture + bretelles.

- **(b) Regex `*wine*` `NativeSectionResolver::MAPPING`** : pattern
  `['wine']` substring (case-insensitive) conservé. Cohérence avec entrées
  existantes (`firefox` matche `firefoxxxxx`, `lockscreen` matche `lockscreens`).
  Faux positifs marginaux (GPO `wineries`, `wineland`) acceptés — peu probable
  sur SE4FS. **Entrée tech-debt** : migration vers boundary regex si faux
  positif rencontré en prod.

- **(c) Chargement `legacy/bootstrap.php` pour `get_wine_shortcuts`** :
  `ShortcutsService::importWineShortcuts` utilise un binding container hook
  `legacy.get_wine_shortcuts` (mockable en tests) — fallback `require_once`
  conditionnel `{legacy_path}/includes/shortcuts.inc.php` à la demande. Si la
  fonction reste indisponible (cas testing sans shim), exception explicite.
  Pas de modification de `legacy/bootstrap.php` (l'include est isolé à
  l'appel de la méthode = minimal blast radius).

- **(d) Atomic write `shortcuts.json`** : pattern `flock(LOCK_EX)` sur le
  fichier final ouvert en `c+`, puis écriture dans `<filename>.tmp.<pid>` +
  `rename()` atomique. Lecture re-effectuée SOUS lock (anti-race entre 2
  admins lançant simultanément `generateShortcuts`). Iso-pattern Story 15.1
  `AtomicFileWriter` + cohérence `SambaEduConfig::set` (16.3b review fixes).

- **(e) `/tmp/assoc_result.json` permissions CI** : write skippé en
  `app()->environment('testing')` (parité 16.3b AC1.7 `NetworkOutController`).
  Les 3 autres writes legacy (`assoc_local.json`, `assoc_app.json`,
  `assoc_wpkg.json`) ne sont **pas** portés (debug intermédiaire inutile —
  conformément à D5).

**Résumé implémentation :**

- **Volet 1 (UI Wine)** : Controller mince + Livewire SFC + modale réutilisable
  + `WithToasts`. Whitelist regex `^[a-zA-Z0-9._\-]*$` côté Livewire +
  `WineImageQueuer::dispatch` + `GenerateWineImageJob::__construct` (triple
  défense F7). Bug `wine.php:52` non reproduit (`@selected` Blade strict).
- **Volet 2 (Job queue + ShortcutsService)** : `GenerateWineImageJob`
  ShouldQueue, `tries=1`, `timeout=1800`, `Process::run(array, timeout)`,
  logs gpo channel start/step/end avec `operation_id` propagé depuis le
  queuer. `ShortcutsService::importWineShortcuts` étend le service existant
  avec atomic flock+tmp+rename.
- **Volet 3 (endpoint Associations)** : `AssociationsOutController` strict
  POST only, validation `id` md5 + `list` JSON ≤ 10 Ko + APCu présent →
  **400 body vide** (différent du 200 iso-legacy 16.3b — `associations_out.php`
  legacy fait `exit()` après 400). `text/json` iso-bytes. Logique métier
  factorisée dans `AssociationsResolver` (8 étapes iso-legacy).
- **Volet 4 (routage)** : `Route::match(['POST'], 'gpo/associations_out.php',
  ...)` AVANT catchall ligne 453 + `blocked_legacy_routes` enrichi pour
  redirect `/gpo/wine.php` → `/app/gpo/wine`.
- **Volet 5 (sécurité)** : tests dédiés `WineSecurityTest` avec dataProvider
  10 inputs malicieux. Architecture test enrichi (`it_uses_process_in_array_mode_in_generate_wine_image_job`,
  `jobs_directory_has_no_ldap_or_samba_tool_references`).
- **Volet 6 (tests)** : 13 fichiers tests, ~58 tests cumulés. Comparison
  fixture artisanal `legacy-associations-out.json` (skippable `@group
  requires-fixture-capture` — capture VM réelle = action Henri T0.10).

**Story 16-7 backlog** : déjà créée par le SM dans `sprint-status.yaml` (cf.
T7.1 — entrée existante). Pas de modification supplémentaire requise.

### File List

**Créés** :
- `app/Http/Controllers/Gpo/WineController.php`
- `app/Http/Controllers/Gpo/AssociationsOutController.php`
- `app/Gpo/Services/WinePrefixScanner.php`
- `app/Gpo/Services/WineImageQueuer.php`
- `app/Gpo/Services/WineImageAlreadyQueuedException.php`
- `app/Gpo/Services/PackagesXmlAssociationsReader.php`
- `app/Gpo/Services/AssociationsResolver.php`
- `app/Gpo/Jobs/GenerateWineImageJob.php`
- `resources/views/pages/app/gpo/wine/index.blade.php`
- `tests/Unit/Gpo/WinePrefixScannerTest.php`
- `tests/Unit/Gpo/WineImageQueuerTest.php`
- `tests/Unit/Gpo/GenerateWineImageJobTest.php`
- `tests/Unit/Gpo/PackagesXmlAssociationsReaderTest.php`
- `tests/Unit/Gpo/AssociationsResolverTest.php`
- `tests/Feature/Gpo/WinePageTest.php`
- `tests/Feature/Gpo/WineSecurityTest.php`
- `tests/Feature/Gpo/WineLegacyRouteRedirectTest.php`
- `tests/Feature/Gpo/AssociationsOutEndpointTest.php`
- `tests/Feature/Gpo/AssociationsOutRouteRegistrationTest.php`
- `tests/Feature/Gpo/AssociationsOutComparisonTest.php`
- `tests/Feature/ShortcutsService/ImportWineShortcutsTest.php`
- `tests/Fixtures/Gpo/legacy-associations-out.json`
- `tests/Fixtures/Gpo/packages-xml-sample.xml`

**Modifiés** :
- `app/Services/ShortcutsService.php` (+ méthode `importWineShortcuts` +
  helpers privés `fetchWineShortcutsLegacy` / `loadLegacyShortcuts` /
  `resolveLegacyConfig` / `atomicMergeShortcuts`)
- `app/Gpo/Support/NativeSectionResolver.php` (+ entrée `MAPPING['wine']`)
- `routes/web.php` (+ route `app/gpo/wine` permission `server.admin` AVANT
  `gpo/{guid}`, + route `gpo/associations_out.php` POST AVANT catchall)
- `config/sambaedu.php` (+ entrée `blocked_legacy_routes` Wine redirect,
  + sous-config `gpo.wine.{prefix_base,image_script}`)
- `app/Gpo/README.md` (+ catalogue `action_type` enrichi 3 entrées,
  + section "UI admin native Wine", + endpoint Associations dans tableau
  endpoints runtime)
- `docs/qa/domains/gpo.md` (+ section 5 Story 16.3c, 16 scénarios 5.1-5.16
  + checklist rapide)
- `docs/tech-debt-gpo.md` (+ 6 entrées 16.3c : importWineShortcuts shim,
  assoc_result.json, AssociationsResolver iso-legacy, PackagesXmlAssociationsReader
  full load, applications.php hors scope D1, NativeSectionResolver wine pattern)
- `tests/Architecture/GpoNamespaceTest.php` (+ `GenerateWineImageJob.php`
  dans `SHELL_WHITELIST_FILES`, + 2 tests
  `jobs_directory_has_no_ldap_or_samba_tool_references` et
  `it_uses_process_in_array_mode_in_generate_wine_image_job`)
- `tests/Unit/Gpo/NativeSectionResolverTest.php` (+ 5 cas dataProvider wine,
  + 2 tests dédiés `it_matches_wine_gpo_to_native_app_gpo_wine` et
  `it_builds_native_url_with_from_gpo_param_for_wine`)
- `_bmad-output/implementation-artifacts/sprint-status.yaml` (status
  `ready-for-dev` → `review` + commentaire daté + last_updated header)

**Non touchés (régression évitée)** :
- `app/Gpo/Services/{GpoService,NetworkScriptGenerator,VeyonConfigGenerator,ReadUserManager}.php`
- `app/Http/Controllers/Gpo/{NetworkOutController,VeyonOutController}.php`
- `app/Ldap/AdUserManager.php` + `app/Config/SambaEduConfig.php` (16.3b)
- `app/Wpkg/Deployment/Services/WorkstationPackagesResolver.php` (15.2)
- `legacy/modules/gpo/{wine,associations_out,applications}.php`

### Change Log

| Date       | Auteur               | Changement                                                            |
|------------|----------------------|-----------------------------------------------------------------------|
| 2026-05-12 | claude-opus-4-7 (review fixes) | Application des correctifs post-review (cf. `_bmad-output/codeReviews/16-3c.md`). **Corrigés** : #1 `WineController.php` supprimé (dead code — route Livewire filesystem-router), README.md mis à jour. #2 test `it_applies_throttle_300_per_minute` ajouté dans `AssociationsOutEndpointTest`. #3+#M2 fixture `legacy-associations-out.json` régénéré (5 entries iso `packages-xml-sample.xml` : `.jpg`/`.html`/`.htm`/`http`/`https`) → test comparison passe sans skip. #5 `generateShortcuts()` aligné sur `generateImage()` (message générique + `Log::channel('gpo')->error`). #M1 regex `parseLocalAssocs` passée en greedy iso-legacy (`/^\s*(.*)\s*,\s*(.*)$/`) + test unit `parse_local_assocs_uses_greedy_split_on_last_comma_iso_legacy`. #M4 docblock `loadDefaultXml` corrigée (récursif `getElementsByTagName` iso-legacy). **Tech-debt** (6 entrées ajoutées dans `docs/tech-debt-gpo.md`) : #6 strict_types ShortcutsService, #8 test write `/tmp/assoc_result.json` prod, #9 cleanup `.tmp.<pid>` SIGKILL, #M3 regex `APPLICATION_REGEX` autorise `..`, #M5 test `list` scalar JSON, #M7 `forceRelease` sans owner. **Ignorés** : #4 regex md5 `/i` (faux positif Sonnet, cohérence inter-controllers 16.3b), #7 double `releaseLock` (design intentionnel documenté). Doc `docs/qa/domains/gpo.md` enrichi (sous-section Post-correctifs & non-régressions Story 16.3c). |
| 2026-05-12 | claude-opus-4-7 (dev) | Story implémentée, status `ready-for-dev` → `review`. 8 fichiers métier + extension `ShortcutsService` + vue Livewire SFC + enrichissement `NativeSectionResolver`. 13 fichiers tests (~58 tests). 5 discrepances SM tranchées : (a) Cache::lock idempotence côté queuer + release Job ; (b) regex MAPPING wine substring conservé ; (c) require_once conditionnel + binding test ; (d) flock+tmp+rename atomic write ; (e) /tmp/assoc_result.json skip testing. Audit F7 corrigé (triple défense whitelist regex + Process::run mode array + test architecture). Bug `wine.php:52` non reproduit. Pattern strictement iso 16.3b avec sémantique 400 différente pour associations. Section 5 `docs/qa/domains/gpo.md` ajoutée (16 scénarios). 6 entrées tech-debt-gpo.md. Action Henri = T8.1-T8.3 + T8.5 smoke VM. |
| 2026-05-12 | claude-opus-4-7 (SM) | Story créée, status `ready-for-dev`. **Recadrage scope** : `gpo/applications.php` HORS SCOPE (D1, Story 16-7 backlog créée — 1007 lignes legacy + surface AD massive non portable en 3-4j). Scope final = `gpo/wine.php` (UI admin → `/app/gpo/wine` + Job queue) + `gpo/associations_out.php` (endpoint runtime iso 16.3b). 12 décisions SM (D1-D12). 6 volets ACs (UI Wine / Job queue + ShortcutsService / Endpoint Associations / Routage iso-contrat / Sécurité / Tests). 8 phases T0-T8. Pattern strictement iso 4.7/4.8/16.3b. 7 fichiers métier créés (1 Controller Wine + 1 Controller Associations + 4 Services + 1 Job + 1 vue Livewire SFC) + 1 extension `ShortcutsService` + 11 fichiers tests (~50 tests) + 2 fixtures. Réutilisation directe `AppContextRepository` (4.8) + `WorkstationPackagesResolver` (15.2). Channels logs distincts : Wine=`gpo` (admin audit), Associations=`daily` (runtime poste). Enrichissement `NativeSectionResolver` pour Wine (T6.1). Audit F7 `batch_command` injection corrigé (Process::run mode array + whitelist regex stricte). Bug legacy `wine.php:52` NON reproduit (AC1.6). Création Story 16-7 backlog en T7.1. |

---

## Recommandation Modèle Dev

**Modèle recommandé : opus**

Raison :

1. **2 surfaces fonctionnelles distinctes** (Wine UI admin + Associations runtime endpoint) avec des patterns différents (Livewire SFC + Job queue vs Controller iso-contrat HTTP). Coordination cross-stack obligatoire (Controllers + Services + Jobs + Livewire + tests Feature + Unit + Architecture). Sonnet sur cette charge produirait probablement des inconsistances de pattern (1 fichier suit 16.3b, un autre dévie).

2. **Logique métier `AssociationsResolver` complexe et iso-bytes obligatoire** — la logique legacy (173 lignes) intersection `packages.xml` ↔ `WorkstationPackagesResolver` ↔ JSON système/local ↔ default.xml ↔ filtrage groupes user/parc avec ordre `array_reverse + unshift "all" + push "force"` ↔ delta avec local input. **Un seul ordre incorrect** dans la logique → résultat différent → poste Windows reçoit de mauvaises associations d'extensions → chaque user du parc se retrouve sans application par défaut pour ouvrir ses fichiers. **Coût de bug très élevé** parc-wide.

3. **XML DOM parsing iso-legacy** — `<package>` enfants `<Association>` avec attributs `ProgId` / `Identifier` / `type` (défaut `file`). Sonnet pourrait simplifier ou rater le default `type=file` quand l'attribut est absent. Test fixture comparison obligatoire.

4. **Sécurité F7 audit `batch_command` injection** — le whitelist regex strict + `Process::run` mode array + validation au constructeur du Job (défense en profondeur) doit être implémenté **proprement**. Un dev sonnet pourrait skipper la validation au constructeur (single source of truth = Controller) et créer une faille si le Job est dispatché ailleurs. Opus traite la défense en profondeur naturellement.

5. **UX action longue (génération image Wine ~10min)** — modale de confirmation + toast + lien vers logs + pas de feedback temps réel (Job queue async). Si l'admin clique 2x, on dispatch 2 Jobs → idempotence à gérer côté Job (vérifier si une instance déjà en cours via `Cache::lock`). À discuter pendant dev. Sonnet pourrait simplifier en ignorant la race.

6. **Création Story 16-7 backlog correctement** — D1 demande une nouvelle entrée dans `sprint-status.yaml` + mention dans `epics.md`. Sonnet pourrait oublier l'`epics.md` ou rédiger un cadrage trop léger. Opus rédige un cadrage utilisable.

7. **Précédent 16.3b — modèle opus** : Story 16.3b a été livrée par opus (réussite — review fixes minimales, 5 corrections seulement, peer review confirme la qualité). Cette story 16.3c est dans la **même ligne** (même pattern iso, même surface AD lecture seule, même channels, même tests) — légère réduction de complexité (pas de crypto, pas de side effect AD), mais ajout du volet UI Livewire et Job queue.

8. **Volume modéré, complexité hétérogène** — ~250 lignes legacy à porter, mais sur 2 surfaces (UI + runtime) avec patterns distincts. Pattern sonnet (reproduction mécanique) sous-performant ici.

**Pour mémoire** : un dev sonnet **peut** faire le volet 1 (UI Wine — pattern iso 16.2 bien établi) et le volet 6 (tests pattern existant). Mais le volet 3 (`AssociationsResolver` logique métier + iso-bytes) et le volet 5 (sécurité `batch_command` audit F7) demandent opus pour ne pas créer de dette ou de bug latent côté parc Windows.

**Confiance opus** : 90%.

**Charge estimée** : 3-4 jours dev + 0.5j QA VM Henri.

---

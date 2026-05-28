# Story 9.4 : Logs WPKG et Rapports d'Installation

Status: review

## Story

As a **responsable de college**,
I want consulter les logs WPKG et les rapports d'installation des packages sur les postes,
So that je sache quels logiciels ont ete installes, lesquels ont echoue, et sur quelles machines.

## Contexte

La donnee des rapports WPKG est deja presente en base grace au shim Eloquent (`wpkg_libsql.php`) qui bridge les fonctions legacy vers les modeles Laravel. Le cron legacy `wpkg_rapport.php` (dans `sambaedu/`) parse les fichiers `HOSTNAME.txt` deposes par les postes Windows sur le partage SMB `\\SE4FS\rapports` et ecrit dans PostgreSQL via le shim.

**Decision utilisateur : le shim 1bis-11 (module WPKG complet) ne sera pas fait.** Le cron legacy continue de tourner tel quel. Cette story se concentre sur :
1. Un **endpoint API** `POST /api/wpkg/reports/{hostname}` + service d'ingestion qui parse et persiste
2. Un **worker local** (commande artisan) qui lit les fichiers `.txt` du partage SMB et POST vers l'API
3. L'interface Livewire de consultation des logs et rapports
4. Le filtrage et la visualisation des statuts d'installation

**Architecture decouplee (phase 1 → phase 2) :**
- **Phase 1 (cette story)** : worker local lit fichiers SMB → POST vers API locale → ingestion. Le cron legacy continue en parallele.
- **Phase 2 (future)** : les clients Windows POSTent directement au meme endpoint — seul l'emetteur change, l'API et la logique d'ingestion sont identiques.

Cette indirection API permet de ne rien reecrire cote serveur lors de la migration vers le push direct depuis Windows.

## Acceptance Criteria

1. **Consultation des rapports par poste** — Given des postes ont execute WPKG au demarrage, When je consulte la section logs WPKG, Then je vois les rapports par poste avec la liste des packages traites, leur statut (installe, echoue, deja present) et l'horodatage.

2. **Filtrage par machine ou par package** — Given je filtre les logs par machine ou par package, When j'applique le filtre, Then seuls les logs correspondants sont affiches.

3. **Identification des echecs** — Given un package a echoue sur plusieurs postes, When je consulte le rapport, Then les machines en echec sont clairement identifiees avec le message d'erreur associe.

4. **Endpoint API d'ingestion** — Given un rapport WPKG au format texte legacy est POST vers `/api/wpkg/reports/{hostname}` (Content-Type `text/plain`), When l'endpoint recoit la requete, Then le service `WpkgReportIngestionService` parse le contenu et persiste (mise a jour de `workstations.last_report_at`, `report_sha`, `log_path`, `report_path` + delete/bulk-insert des `WorkstationApplicationStatus`), And l'endpoint retourne HTTP 200 avec `{ status: 'processed', packages_count: N }` ou HTTP 304 si le SHA est inchange (idempotence).

5. **Restriction acces API** — Given l'endpoint `/api/wpkg/reports/*` est expose, When une requete provient d'une IP autre que `127.0.0.1` ou du reseau local configure, Then la requete est rejetee avec HTTP 403. (Auth par token machine = Phase 2, hors scope.)

6. **Worker local fichiers → API** — Given des fichiers rapport existent sur `/var/sambaedu/unattended/install/wpkg/rapports/`, When `php artisan wpkg:process-reports` est execute, Then chaque fichier `.txt` est lu et POST vers `/api/wpkg/reports/{hostname}` via un client HTTP local, And les fichiers dont le POST retourne 200 sont archives (ou marques traites), And les erreurs sont loggees sans bloquer les autres fichiers.

## Tasks / Subtasks

### Phase 1 : Endpoint API + service d'ingestion (AC: #4, #5)

- [x] **T1.1** Creer `app/Services/Windows/WpkgReportIngestionService.php` avec :
  - `ingest(string $hostname, string $rawReport): IngestionResult` — methode unique point d'entree (utilisee par le controller)
  - Prive : `parseReport(string $content): array` — parse le format texte legacy (voir format section Format ci-dessous)
  - Prive : `updateWorkstationReport(Workstation $ws, array $parsedData)` — met a jour `workstations` + delete/bulk-insert `WorkstationApplicationStatus`
  - Calcul SHA256 du raw, skip (retourne `IngestionResult::unchanged()`) si identique a `workstations.report_sha`
  - Utilise `Cache::lock("wpkg-report:{$hostname}", 60)` pour serialiser l'ingestion d'un meme poste
  - Transaction DB autour de delete+insert (pattern shim `insert_mass_info_app_poste` lignes 1012-1039)
  - (AC: #4)

- [x] **T1.2** Creer le controller `app/Http/Controllers/Api/WpkgReportController.php` :
  - Route : `POST /api/wpkg/reports/{hostname}` (Content-Type `text/plain`)
  - Recupere le body brut via `$request->getContent()`
  - Valide que le hostname correspond a un `Workstation` existant (sinon 404)
  - Delegue a `WpkgReportIngestionService::ingest()`
  - Retourne `200 { status: 'processed', packages_count: N }`, `304 { status: 'unchanged' }`, ou `422` si parse fail
  - (AC: #4)

- [x] **T1.3** Middleware de restriction IP `EnsureLocalRequest` :
  - Verifier IP dans la whitelist : `127.0.0.1`, `::1`, + config `sambaedu.wpkg.report_ingestion_allowed_ips` (CIDR support via `Symfony\Component\HttpFoundation\IpUtils`)
  - 403 sinon
  - Applique a la route API via group ou middleware direct
  - (AC: #5)

- [x] **T1.4** Enregistrer la route dans `routes/api.php` :
  - Groupe middleware `['api', 'local.request']`
  - Pas de CSRF (API stateless)
  - Pas d'auth Phase 1 (auth token = Phase 2, cf. note architecture)
  - (AC: #4, #5)

- [x] **T1.5** Tests Feature de l'endpoint :
  - POST rapport valide → 200, SHA + statuts persistes
  - POST rapport identique (meme SHA) → 304, pas de double insert
  - POST depuis IP non-locale (simuler via `$request->server`) → 403
  - POST rapport malforme → 422
  - POST pour hostname inconnu → 404
  - Convention : `DatabaseTransactions` + fichiers fixture dans `tests/fixtures/wpkg/reports/`
  - (AC: #4, #5)

### Phase 2 : Worker local fichiers → API (AC: #6)

- [x] **T2.1** Creer `app/Console/Commands/WpkgProcessReportsCommand.php` :
  - Signature : `wpkg:process-reports {--path= : override du chemin rapports}`
  - Chemin defaut : `config('sambaedu.wpkg.reports_path')` (a ajouter dans le config)
  - Scan du repertoire : chaque fichier `.txt` → extrait hostname du nom de fichier (`HOSTNAME.txt` → `HOSTNAME`)
  - POST raw text vers `http://127.0.0.1/api/wpkg/reports/{hostname}` via `Http::withHeaders(['Content-Type' => 'text/plain'])->send('POST', $url, ['body' => $content])`
  - Si 200 ou 304 : fichier traite avec succes — deplacer vers `rapports/processed/` (archive)
  - Si autre statut ou timeout : log l'erreur, laisser le fichier en place (retry au prochain run)
  - Log compteurs : traites, inchanges, erreurs
  - (AC: #6)

- [x] **T2.2** Ajouter la relation manquante dans `Workstation` :
  ```php
  public function applicationStatuses(): HasMany
  {
      return $this->hasMany(WorkstationApplicationStatus::class);
  }
  ```
  (AC: #1)

- [x] **T2.3** Tests du worker :
  - Test : fichier rapport present → POST appele → archive
  - Test : API retourne 304 → fichier archive sans warning
  - Test : API retourne 500 → fichier PAS archive, warning logge
  - Test : repertoire vide → commande termine sans erreur
  - Convention : `Http::fake()` pour simuler l'API locale
  - (AC: #6)

### Phase 3 : Interface Livewire consultation (AC: #1, #2, #3)

- [x] **T3.1** Creer la page `resources/views/pages/windows-deploy/reports/index.blade.php` — Livewire SFC :
  - Tableau principal : colonnes Poste, OS, Dernier rapport, Nb packages, Statut global (pastille couleur)
  - Clic sur un poste → expansion ou sous-page avec detail des packages
  - Pagination
  - (AC: #1)

- [x] **T3.2** Implementer le filtrage :
  - Champ recherche texte libre (hostname)
  - Select package (recherche dans les noms d'applications)
  - Filtre par statut : Tous / OK / Echec / En attente
  - Les filtres sont reactifs (Livewire wire:model.live)
  - (AC: #2)

- [x] **T3.3** Vue detail par poste : `resources/views/pages/windows-deploy/reports/[workstation]/index.blade.php`
  - Liste des `WorkstationApplicationStatus` du poste
  - Colonnes : Application, Version installee, Statut, Reboot requis, Date rapport
  - Surbrillance rouge pour les statuts `error` / `not-installed`
  - (AC: #1, #3)

- [x] **T3.4** Vue agregee par package (onglet ou toggle) :
  - Pour chaque application : combien de postes OK, combien en echec
  - Clic sur une app → liste des postes avec leur statut pour cette app
  - Les postes en echec en premier, avec le message d'erreur
  - (AC: #3)

### Phase 4 : Route et navigation (AC: #1)

- [x] **T4.1** Ajouter la route dans `web.php` : `/app/windows-deploy/reports`
  - Sous-route : `/app/windows-deploy/reports/{workstation}` (detail poste)
  - (AC: #1)

- [x] **T4.2** Ajouter le lien dans la navigation sidebar (section Deploiement Windows ou equivalent)
  - (AC: #1)

### Phase 5 : Tests Livewire (AC: #1, #2, #3)

- [x] **T5.1** Tests Feature pour la page reports :
  - Test affichage liste postes avec rapports
  - Test filtrage par hostname
  - Test filtrage par application
  - Test filtrage par statut
  - (AC: #1, #2)

- [x] **T5.2** Test vue detail poste :
  - Test affichage des statuts application pour un poste
  - Test surbrillance echecs
  - (AC: #1, #3)

## Dev Notes

### Infrastructure existante (NE PAS recreer)

**Modeles deja en place :**
- `app/Models/WorkstationApplicationStatus.php` — table `workstation_application_status`, scopes `installed()`, `notInstalled()`, `needsReboot()`, attribut `status_label`
- `app/Models/Workstation.php` — colonnes `last_report_at`, `report_sha`, `log_path`, `report_path` (fillable + casts)
- `app/Models/Application.php` — reference via `application_id` FK

**Migration existante :**
- `workstation_application_status` — FK vers `workstations` et `applications`, unique `[workstation_id, application_id]`, index sur `status` et `reported_at`
- Colonnes workstations : `last_report_at` (timestamp), `report_sha` (string 64), `log_path` (text), `report_path` (text)

**Shim existant (reference comportementale, ne pas modifier) :**
- `legacy/wpkg_libsql.php` lignes 931-1050 : `update_poste_info_wpkg()`, `insert_mass_info_app_poste()`, `delete_info_app_poste()` — ces fonctions montrent le pattern exact : delete all statuts du poste puis bulk insert. Le service natif doit reproduire cette logique.

**Relation manquante :** `Workstation` n'a pas de relation `applicationStatuses()` vers `WorkstationApplicationStatus` — a ajouter (T2.2).

### Format du rapport client WPKG (reference)

Le poste Windows depose un fichier `HOSTNAME.txt` sur `\\SE4FS\rapports`. Format :

```
DATE TIME HOSTNAME MAC_ADDRESS [IP]
ID: application-id
Revision: version
Reboot: true|false
Status: Installed|Not Installed
---
ID: autre-application
Revision: version
Reboot: false
Status: Installed
---
```

**Parsing :** ligne 1 = metadata (date, hostname, MAC, IP), puis blocs separes par `---` contenant `ID:`, `Revision:`, `Reboot:`, `Status:`.

### Calcul des statuts (logique metier)

| Statut | Code legacy | Signification | Mapping PostgreSQL |
|--------|-------------|---------------|--------------------|
| OK | 0 | Version installee = version attendue | `installed` |
| MaJ necessaire | 1 | Installe mais version differente | `upgrading` |
| Non installe (manquant) | 2 | Assigne mais absent du rapport | `not-installed` |
| Non installe (en trop) | 2 | Present dans le rapport mais non assigne | `not-installed` |
| Erreur | — | Echec d'installation | `error` |

### Chemin du repertoire rapports

`config('sambaedu.wpkg.storage_path')` pointe vers `/var/sambaedu/unattended/install/`. Les rapports sont dans le sous-repertoire `wpkg/rapports/`. Verifier la config exacte via SSH sur la VM. Le chemin historique est `\\SE4FS\rapports` (partage Samba), cote serveur c'est `/var/sambaedu/unattended/install/wpkg/rapports/`.

### Architecture API-first — pourquoi ?

**Principe :** separer la source du rapport (fichier SMB aujourd'hui, push HTTP demain) de sa logique d'ingestion. Le worker local n'est qu'un adaptateur.

```
Phase 1 (cette story) :
  poste Windows → ecrit .txt sur SMB → worker Laravel lit → POST /api/wpkg/reports/{hostname}
                                                                      ↓
                                                      WpkgReportIngestionService

Phase 2 (future, hors scope) :
  poste Windows → POST direct /api/wpkg/reports/{hostname}
                                    ↓
                    WpkgReportIngestionService  (INCHANGE)
```

**Le controller + le service d'ingestion sont ecrits une seule fois.** Le worker est jetable quand la Phase 2 arrivera.

### Pattern service — reproduire le comportement du shim

Le cron legacy fait (pour chaque fichier rapport) :
1. Lire le fichier, calculer SHA256
2. Comparer avec `workstations.report_sha` — si identique, skip
3. Si different : `update_poste_info_wpkg()` (met a jour IP, MAC, OS, date, SHA, paths)
4. `delete_info_app_poste()` (supprime tous les `WorkstationApplicationStatus` du poste)
5. `insert_mass_info_app_poste()` (insere en bulk les nouveaux statuts)
6. Utilise un verrou APCu `rapports_wpkg_lock` pour eviter le traitement concurrent

**Dans l'architecture API-first :** steps 1-5 migrent dans `WpkgReportIngestionService::ingest()` (cote controller, meme pour le worker et pour le push direct futur). Step 2 (SHA check) retourne 304 au client. Verrou remplace par `Cache::lock("wpkg-report:{$hostname}", 60)` (Laravel cache lock), granularite par hostname (permet l'ingestion concurrente de postes differents).

### Securite API (Phase 1 vs Phase 2)

**Phase 1 (cette story) :** middleware `EnsureLocalRequest` — whitelist IP stricte (`127.0.0.1` + reseau local configurable). Le worker tourne sur la meme VM, pas d'exposition externe. Pas d'auth token. Simple et suffisant.

**Phase 2 (future, hors scope) :** passer a un mecanisme de token machine :
- Option A : token stocke sur `workstations.api_token` (nullable), emis a l'enrolement
- Option B : certificats clients TLS (plus lourd, meilleure securite)
- Le choix dependra du contexte reseau des SER quand on y arrivera

La story conservera le middleware `EnsureLocalRequest` et y ajoutera un second check token. Le controller et le service ne bougent pas.

### Config a ajouter

Dans `config/sambaedu.php` (creer si absent) ou equivalent :

```php
'wpkg' => [
    'reports_path' => env('WPKG_REPORTS_PATH', '/var/sambaedu/unattended/install/wpkg/rapports'),
    'reports_archive_path' => env('WPKG_REPORTS_ARCHIVE_PATH', '/var/sambaedu/unattended/install/wpkg/rapports/processed'),
    'report_ingestion_allowed_ips' => env('WPKG_REPORT_INGESTION_ALLOWED_IPS', '127.0.0.1,::1'),
    'storage_path' => env('WPKG_STORAGE_PATH', '/var/sambaedu/unattended/install'),
],
```

### Arborescence cible

```
app/Services/Windows/WpkgReportIngestionService.php  # Parse + persiste (utilise par l'API)
app/Http/Controllers/Api/WpkgReportController.php    # Endpoint POST /api/wpkg/reports/{hostname}
app/Http/Middleware/EnsureLocalRequest.php           # Whitelist IP locale (Phase 1)
app/Console/Commands/WpkgProcessReportsCommand.php   # Worker local fichiers → API

routes/api.php                                       # Route POST /api/wpkg/reports/{hostname}
config/sambaedu.php                                  # Config chemins + IPs autorisees

resources/views/pages/windows-deploy/
  reports/
    index.blade.php                                  # Livewire SFC — liste postes + filtres
    [workstation]/
      index.blade.php                                # Livewire SFC — detail poste
    _partials/
      report-filters.blade.php                       # Composant filtres (optionnel)
      package-summary.blade.php                      # Vue agregee par package (optionnel)
```

### Conventions a suivre (issues du projet)

- **Routing filesystem-based** : `pages/windows-deploy/reports/index.blade.php` → route `/app/windows-deploy/reports`
- **Livewire SFC** pour les parties reactives (filtrage, pagination)
- **WithToasts** pour les notifications (`app/Components/Traits/WithToasts.php`)
- **Modale reutilisable** si besoin de detail popup
- **$this->withoutVite()** dans `setUp()` des tests

### Absence du shim 1bis-11

Le module WPKG legacy (`sambaedu/wpkg/`) n'a PAS ete cloisonne dans `legacy/modules/wpkg/`. Le cron `wpkg_rapport.php` continue de tourner depuis le legacy original. Cela signifie :
- Les donnees en base sont alimentees par le cron legacy via le shim `wpkg_libsql.php`
- Le service natif `WpkgReportService` est une **alternative** au cron, pas un remplacement immediat
- Les deux peuvent coexister : le cron legacy ecrit via le shim, le service natif peut etre utilise en parallele ou en remplacement progressif
- **Ne pas casser la compatibilite avec le cron legacy** — les modeles et la structure de donnees doivent rester coherents

### Learnings stories precedentes (8.2.x)

- **8.2.6** : le code AppStore est dans `sambaedu-reload/` (aka `w1bis/`). Les services sont dans `app/Services/AppStore/`.
- **8.2.6** : convention tests Feature : `DatabaseTransactions` + `Http::fake()` pour les appels HTTP
- **8.2.1** : refactoring en services specialises — un service par responsabilite
- **Convention globale** : pas de `Services/Legacy/` pour du code natif — `Services/Windows/` est le namespace cible (architecture.md)

### Project Structure Notes

- Views dans `resources/views/pages/windows-deploy/reports/` — conforme au routing filesystem-based et a l'architecture.md (FR23-26 → `pages/windows-deploy/`)
- Service dans `app/Services/Windows/` — premier service dans ce namespace, suivre le pattern des services existants (`AppStore/`, `ControlHub/`, etc.)
- Commande artisan dans `app/Console/Commands/` — pattern standard Laravel

### References

- [Source: wpkg-architecture.md#Section 7] Format rapport, flux de remontee, calcul des statuts
- [Source: wpkg-architecture.md#Section 12] Services suggeres — ReportService
- [Source: _bmad-output/planning-artifacts/epics.md#Story 8.4] AC originaux
- [Source: _bmad-output/planning-artifacts/architecture.md#lignes 453-455] Services Windows a creer
- [Source: _bmad-output/planning-artifacts/architecture.md#ligne 486] Views `pages/windows-deploy/`
- [Source: w1bis/legacy/wpkg_libsql.php#lignes 931-1050] Pattern de mise a jour rapports
- [Source: w1bis/app/Models/WorkstationApplicationStatus.php] Modele existant
- [Source: w1bis/database/migrations/2026_03_16_100200] Migration table statuts
- [Source: _bmad-output/implementation-artifacts/8-2.6-orchestration-complete-nettoyage.md] Patterns dev et conventions tests

## Recommandation Modele Dev

**Sonnet** — Story CRUD/UI + endpoint API + worker. Pas de complexite d'integration critique (pas d'exec, pas de LDAP, pas de shim). Le modele de donnees est deja en place. L'architecture API-first est simple (un controller, un service, un middleware IP). Les patterns sont bien documentes dans les stories 8.2.x et le shim existant. Seule vigilance : le middleware `EnsureLocalRequest` doit utiliser `IpUtils::checkIp()` pour supporter le CIDR correctement.

## Dev Agent Record

### Agent Model Used

claude-sonnet-4-6

### Debug Log References

- **Livewire implicit route model binding (404 sur page detail)** : La propriete publique typee `public Workstation $workstation` dans le composant Livewire declenchait une resolution implicite du modele par `Livewire\Drawer\ImplicitRouteBinding::resolveComponentProps`. Quand le parametre de route `{workstation}` avait le meme nom que la propriete typee, Livewire appelait `resolveRouteBinding()` avant meme que `mount()` ne soit execute. La correction : supprimer le `mount()` qui faisait un `findOrFail` manuel et laisser Livewire injecter le modele automatiquement via le binding implicite. Cela fonctionne correctement avec `DatabaseTransactions` car la transaction est visible sur la meme connexion DB.

- **`getPackageSummaryProperty` — erreur PostgreSQL HAVING avec alias** : `->having('total_count', '>', 0)` apres `->withCount(['workstationStatuses as total_count'])` echouait car PostgreSQL ne reconnait pas les alias de colonnes dans `HAVING`. Corrige en utilisant `->whereHas('workstationStatuses')` qui genere un sous-requete `EXISTS` correcte.

- **Default parameter interpolation PHP** : `createReportFile(string $hostname, string $content = "...{$hostname}...")` cause une erreur fatale PHP "Constant expression contains invalid operations". Corrige en utilisant `?string $content = null` avec affectation dans le corps de la methode.

### Completion Notes List

- L'architecture API-first est completement implementee : worker local `wpkg:process-reports` → POST `/api/wpkg/reports/{hostname}` → `WpkgReportIngestionService`
- Le middleware `EnsureLocalRequest` utilise `Symfony\Component\HttpFoundation\IpUtils::checkIp()` avec support CIDR
- Idempotence SHA256 : retourne HTTP 200 `{status: unchanged}` si `workstations.report_sha` inchange (Fix #10 : 304 remplace par 200)
- `Cache::lock("wpkg-report:{$hostname}", 60)` pour serialiser l'ingestion par poste
- DB transaction autour du delete+bulk-insert des `WorkstationApplicationStatus`
- 24/24 tests passent (7 API + 7 worker + 10 pages Livewire) + 3 nouveaux tests (Fix #9)
- La vue par package utilise `->whereHas('workstationStatuses')` au lieu de `having()` pour compatibilite PostgreSQL
- La page detail utilise le binding implicite Livewire v3 (pas de `mount()` manuel)

### Code Review Fixes (post-review 2026-04-14)

- **Fix #1** : `os`, `log_path`, `report_path` desormais renseignes dans `updateWorkstationReport()`.
  - `os` detecte depuis la premiere ligne du rapport (logique legacy `wpkg_rapport.php:79-86`) — Windows 7/10/11/XP/Autre.
  - `log_path` = `"{hostname}.log"`, `report_path` = `"{hostname}.txt"` (convention legacy).
  - Validation du header ajoutee dans `parseReport()` : si date non parsable ou hostname vide → `null` → `parseFailed` → HTTP 422.

- **Fix #3** : Warning `Log::warning` si encodage != UTF-8 valide apres strip BOM (detection via `mb_detect_encoding`).

- **Fix #8** (documente, non bascule) : les tests Windows/ tournent sur SQLite car la bascule globale vers PostgreSQL casserait les autres tests Feature qui ont aussi des `Schema::create()` manuels. Fichier `.env.testing` cree avec instructions commentees pour activer PostgreSQL sur sambaedu_test. Pour activer : `sudo -u postgres createdb sambaedu_test && sudo -u postgres psql -c "GRANT ALL ON DATABASE sambaedu_test TO sambaedu"`, puis decommenter dans `.env.testing` et remplacer `DatabaseTransactions` par `RefreshDatabase` dans les tests Windows/.

- **Fix #9** : 3 nouveaux tests dans `WpkgReportApiTest` :
  - `test_post_identical_report_returns_200_unchanged` (renomme + strict : assertStatus(200) + assertJson unchanged)
  - `test_malformed_report_returns_422` (utilise le fixture `malformed_report.txt`)
  - `test_os_windows10_detected_from_header` (verifie `$ws->os === 'Windows 10'` + log_path/report_path)

- **Fix #10** : HTTP 304 remplace par HTTP 200 avec body `{status: 'unchanged', packages_count: 0}`. Le worker `WpkgProcessReportsCommand` distingue processed vs unchanged via `$response->json('status')` (plus via le code HTTP).

- **Fix #13** : `->with(['applicationStatuses'])` remplace par `->withCount([...])` (4 sous-requetes aggreegees). `globalStatus()` utilise les counts (`total_apps`, `error_apps`, `missing_apps`) au lieu d'iterer la collection. Debounce passe de 300ms a 500ms sur les deux champs de recherche texte.

### File List

- `app/Services/Windows/IngestionResult.php` (cree)
- `app/Services/Windows/WpkgReportIngestionService.php` (cree)
- `app/Http/Controllers/Api/WpkgReportController.php` (cree)
- `app/Http/Middleware/EnsureLocalRequest.php` (cree)
- `app/Console/Commands/WpkgProcessReportsCommand.php` (cree)
- `app/Models/Workstation.php` (modifie — relation `applicationStatuses`)
- `app/Models/Application.php` (modifie — relation `workstationStatuses`)
- `app/Http/Kernel.php` (modifie — enregistrement middleware `local.request`)
- `routes/api.php` (modifie — route POST `/api/wpkg/reports/{hostname}`)
- `routes/web.php` (modifie — routes `/app/windows-deploy/reports` et `/{workstation}`)
- `config/sambaedu.php` (modifie — config `wpkg.reports_path`, `reports_archive_path`, `report_ingestion_allowed_ips`)
- `resources/views/pages/windows-deploy/reports/index.blade.php` (cree — Livewire SFC liste)
- `resources/views/pages/windows-deploy/reports/[workstation]/index.blade.php` (cree — Livewire SFC detail)
- `resources/views/components/organisms/sidebar.blade.php` (modifie — lien Rapports WPKG)
- `tests/Feature/Windows/WpkgReportApiTest.php` (cree — 7 tests)
- `tests/Feature/Windows/WpkgProcessReportsCommandTest.php` (cree — 7 tests)
- `tests/Feature/Windows/WpkgReportsPageTest.php` (cree — 10 tests)

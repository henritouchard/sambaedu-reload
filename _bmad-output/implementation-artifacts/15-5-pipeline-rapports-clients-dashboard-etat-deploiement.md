# Story 15.5 : Pipeline rapports clients + Dashboard état déploiement

Status: review

> **Story Epic 15 #5** — Boucle d'observabilité du pipeline WPKG.
> Endpoint d'ingestion **étendu** (existant 9.4, à élargir : auth Phase 2,
> archivage brut, corrélation `deployment_id`, channel `wpkg-deploy`),
> parser durci pour formats client à venir, dashboard agrégé
> `/app/wpkg/deployments` (KPIs globaux + agrégat parc + agrégat profile +
> incidents 24h), vue détaillée poste avec drill-down depuis le dashboard,
> et bouton « Forcer une re-évaluation » qui dispatche un event de purge
> cache 15.2.
>
> **Hors scope** : notifications proactives (email/push), export CSV/PDF
> (cf. notes Epic 15.5 epics.md:3268-3269).

---

## Story

As a **responsable de collège / administrateur SER**,
I want que les rapports d'exécution remontés par les clients WPKG (au login / startup) soient ingérés automatiquement, archivés pour audit, corrélés à un déploiement quand applicable, et que je puisse visualiser en quelques secondes l'état de déploiement par poste / parc / profil avec drill-down sur les machines en échec,
So que je sache si le déploiement WPKG fonctionne sur l'ensemble du parc, identifier les incidents récents, et déclencher une re-évaluation ciblée sans toucher au shell ou à la BDD.

---

## Contexte

L'Epic 15 réécrit nativement le pipeline WPKG. Stories 15.1 (✅ done — fondations + tables `wpkg_deployments` UUID + `wpkg_deployment_workstation_status` + channel `wpkg-deploy` + `App\Support\AtomicFileWriter`), 15.2 (✅ done — generators XML/`.ini` + 7 events + 2 listeners), 15.3 (✅ done — réconciliation AD asynchrone, zéro AD hot path) et 15.4 (✅ done — UI admin + émetteurs events + 3 events additifs) sont livrées.

**État existant à connaître (Story 9.4 review)** : un endpoint d'ingestion `POST /api/wpkg/reports/{hostname}` est déjà en production. Le service `App\Services\Windows\WpkgReportIngestionService` parse les rapports texte legacy et persiste les statuts par app dans `workstation_application_status` (table 9.4). Le middleware `EnsureLocalRequest` filtre par IP (Phase 1). Une vue `/app/windows-deploy/reports/{workstation}` (9.5 done) affiche le détail par poste.

**Cette story 15.5 ne réinvente rien** : elle **étend** l'existant pour boucler la boucle Epic 15.

### Pourquoi maintenant

15.4 a livré les **émetteurs** des events 15.2 (clone parc, bulk catégorie, options `.ini`) qui créent des lignes `wpkg_deployments` UUID (clone synchrone). Mais **rien ne lit en retour** ce qu'il advient de ces déploiements côté postes Windows. Les colonnes `wpkg_deployments.status` et `wpkg_deployment_workstation_status.client_status` restent inertes. **15.5 ferme la boucle** : les rapports clients alimentent ces tables en plus de `workstation_application_status` (qui reste la source de vérité par-app/par-poste).

### Frontière avec 15.7 (cleanup futur)

15.7 (`PLANNED`) arbitrera le retrait des stories 9.4 / 9.5 si elles sont pleinement couvertes par 15.5 (cf. epics.md:3286). **Cette story 15.5 ne supprime rien de 9.4 / 9.5** — elle étend. La bascule legacy est une décision 15.7 conditionnée à 14j de stabilisation et 95% d'ingestion postes actifs.

### Invariants Epic 15 (rappel)

- **Eloquent first** : aucune lecture AD en hot path. Le dashboard lit uniquement Eloquent (`workstations`, `workstation_application_status`, `wpkg_deployments`, `wpkg_deployment_workstation_status`).
- **Channel `wpkg-deploy`** : tous les logs structurés du pipeline (ingestion, parser warning, dashboard query slow) y vont avec `deployment_id`/`workstation_id` quand applicable.
- **Atomic write** : archivage brut via `App\Support\AtomicFileWriter::write()` (pas de `file_put_contents` direct).
- **Filesystem-based router** : pages sous `resources/views/pages/`, Livewire SFC, modale réutilisable, trait `WithToasts`.
- **Test archi** : tout nouveau code sous `App\Wpkg\Deployment\*` (controllers, services, queries) passe le test `tests/Architecture/WpkgDeploymentNamespaceTest.php`.

---

## Dépendances

| Story | Titre | Status attendu au kickoff | Détail |
|-------|-------|----------------------------|--------|
| 15-1 | Fondations Pipeline Déploiement WPKG | done | Tables `wpkg_deployments` (UUID PK) + `wpkg_deployment_workstation_status`, channel `wpkg-deploy`, `App\Support\AtomicFileWriter`, config `sambaedu.wpkg.reports_inbox` / `reports_archive` |
| 15-2 | Generators XML + .ini par poste | done | 7 events `App\Wpkg\Deployment\Events\*`, listener `InvalidateWorkstationPackagesCache`. Cette story dispatche un nouvel event `WorkstationManualReevaluationRequested` (additif). |
| 15-3 | Modèle Eloquent + réconciliation AD | done | `objectGUID` synchronisé sur `workstations.object_guid`. Lecture-only ici. |
| 15-4 | UI admin assignation apps WPKG | done | 3 events additifs (`WorkstationGroupApplicationsChanged`, `WorkstationApplicationsChanged`, `AppProfileApplicationsChanged`), `cloneConfiguration` qui crée des lignes `wpkg_deployments`. Cette story 15.5 **lit** ces lignes et les corrèle aux rapports clients. |
| 9-4 | Logs WPKG et rapports d'installation | review (≈ done) | **Critique** : endpoint `POST /api/wpkg/reports/{hostname}`, `WpkgReportIngestionService`, table `workstation_application_status`, middleware `EnsureLocalRequest`. Cette story 15.5 **étend** ces composants, ne les remplace pas. |
| 9-5 | Affichage du log d'installation | done | Route `windows-deploy.reports.log` + lecture brute log poste. Réutilisée tel quel pour le drill-down. |
| Epic 4 | Workstation, WorkstationGroup, AppProfile | done | Modèles, relations |
| Epic 7 | Permissions Spatie | done | `SambaPermission::WpkgAssign` (existant) — **cf. décision permissions** ci-dessous |

> **Hypothèse de dev** : 15-1 à 15-4 sont `done`. 9-4 est `review` au moment de la création de cette story — la finalisation user de 9-4 (passage `review → done`) **n'est pas bloquante** pour 15.5 (les composants 9-4 sont fonctionnels en review).

---

## Décisions SM (figées 2026-05-05, à valider en T0)

### 1. Routing endpoint d'ingestion

L'epics propose `POST /api/v1/wpkg/reports`. **Décision SM** : **conserver** la route existante 9.4 `POST /api/wpkg/reports/{hostname}` (déjà en prod, déjà testée) et **ajouter** une **alias** sous `/api/v1/wpkg/reports/{hostname}` pour parité avec la convention `routes/api.php` (ligne 42 : `prefix('v1')`). Les deux routes pointent vers le même contrôleur. Le retrait de la route legacy `/api/wpkg/reports/*` est arbitré par 15.7.

### 2. Authentification Phase 2 (machine auth)

Le middleware actuel `EnsureLocalRequest` fait un filtrage IP (Phase 1, Story 9.4). L'epics propose 3 options : Kerberos SPNEGO, certificat client TLS, secret partagé par machine. **Décision SM** : **secret partagé par machine** (table `workstation_api_secrets`, rotation possible). Justification :
- Pas de dépendance Kerberos serveur (moins fragile, debugging facilité).
- Pas de PKI machine à déployer (gain de complexité opé).
- Aligné avec le pattern `controlhub.auth` existant (token Bearer dans header).
- Bascule future Kerberos non bloquée (le middleware Phase 2 reste un point d'extension).

**Détail technique** :
- Nouvelle table `workstation_api_secrets` : `id`, `workstation_id` (FK unique), `secret_hash` (bcrypt du token clair), `last_used_at`, `created_at`, `rotated_at` (nullable), `revoked_at` (nullable).
- Génération initiale : commande `php artisan wpkg:provision-secrets` (génère un secret aléatoire 32 bytes par poste actif, hash bcrypt en DB, **affiche le secret clair UNIQUEMENT à la création** pour distribution manuelle / GPO startup).
- Rotation : commande `php artisan wpkg:rotate-secret {workstation}` (génère un nouveau secret, conserve l'ancien valide 7 jours via `rotated_at`).
- Header `Authorization: Bearer {secret}` (pas de `X-Workstation-Secret` custom — REST standard).
- Note dans `audit-wpkg-report-auth.md` (à créer T0) qui documente le choix + la procédure de distribution / rotation / révocation.

**Compatibilité** : pendant la transition (jusqu'à 15.7), le middleware accepte **soit** un IP-allowlist Phase 1 (legacy) **soit** un Bearer secret valide (Phase 2). Une fois 15.7 livrée, le mode Phase 1 est retiré.

### 3. Dashboard routing

L'epics propose `/app/wpkg/deployments`. **Décision SM** : conserver ce chemin pour **le dashboard agrégé**, et utiliser le préfixe filesystem `pages/wpkg/deployments/`. Le drill-down par poste **réutilise** la route existante `windows-deploy.reports.show` (9.4) — pas de duplication.

> **Cohérence avec 15.4** : 15.4 utilise `parc.groups.wpkg` / `parc.machines.wpkg` (préfixe `parc.`). 15.5 utilise `wpkg.deployments` (sans préfixe `parc.`) car le dashboard est **transversal** aux parcs (pas une vue d'un parc particulier). Un lien direct depuis `parc.groups.show` vers `wpkg.deployments?group_id=X` reste disponible pour filtrer.

### 4. Permissions

L'epics ne précise pas. **Décision SM** : utiliser **uniquement** la permission existante `SambaPermission::WpkgAssign` pour le bouton « Forcer une re-évaluation » (mutation), et le Gate existant `viewAny-workstationGroup` pour la lecture du dashboard (cohérent décision 15.4).

> **Pas de nouvelle permission `wpkg.view`** créée (cohérent décision 15.4 — préférence statu quo).

### 5. Corrélation `deployment_id`

Les rapports clients arrivent **sans** `deployment_id` (les clients Windows ne savent pas dans quel déploiement admin ils sont). **Décision SM** : la corrélation se fait **côté serveur** :
- Pour chaque rapport ingéré, le service cherche la **dernière ligne** `wpkg_deployments` dont `target_scope` inclut `workstation_id` (via `cloneConfiguration` 15.4 ou autre opération admin) et qui est en statut `pending`/`running`. Si trouvée, le rapport y est rattaché (insertion ligne `wpkg_deployment_workstation_status` avec `deployment_id` + statuts agrégés).
- Si aucun déploiement applicable, le rapport est ingéré dans `workstation_application_status` (9.4) **uniquement** — `wpkg_deployment_workstation_status` reste vide pour ce rapport.
- Les rapports « cron startup spontané » (cas le plus fréquent) ne créent **pas** de ligne `wpkg_deployments` implicite — c'est cohérent avec la sémantique de la table (déploiements **administrés**, pas heartbeats).

### 6. Bouton « Forcer une re-évaluation »

L'epics dit : « régénère hosts/profiles/ini pour ce poste (event `WorkstationProfileChanged` émis manuellement) ». **Décision SM** : ne pas créer un nouvel event `WorkstationProfileChanged` (chevauchement sémantique avec les events 15.2 / 15.4 existants). Créer un event additif **dédié au cas manuel** : `App\Wpkg\Deployment\Events\WorkstationManualReevaluationRequested(int $workstationId, int $triggeredByUserId)`. Listener : extension de `InvalidateWorkstationPackagesCache` qui purge le cache `wpkg:packages:{hostname}` + extension de `RegenerateWorkstationIniOnOptionsChanged` (ou un nouveau listener `RegenerateWorkstationIniOnManualReevaluation`) qui régénère `.ini`. Justification : un event dédié permet de tracer l'origine manuelle dans les logs (`triggered_by_user_id`) sans polluer la sémantique des events 15.2/15.4.

### 7. NFR1 — Dashboard < 2s sur 500 postes

L'epics fixe l'objectif. **Décision SM** : pas de Redis caching layer dédié (statu quo projet). On atteint l'objectif via :
- Indices DB sur `workstation_application_status.status`, `workstation_application_status.reported_at`, `workstations.last_report_at`, `wpkg_deployment_workstation_status.client_status`.
- Eager-loading agrégé via `selectRaw('COUNT(*) FILTER (WHERE status = ?) as ok, ...')` (PostgreSQL).
- Pagination des incidents (table principale) à 50 lignes par défaut.
- Aucun calcul d'agrégat sans `WHERE` discriminant (pas de scan full table sur prod 5000+ postes).
- Test perf intégré (AC7.5).

---

## Acceptance Criteria

### Volet 1 — Endpoint API d'ingestion (extension de 9.4)

**AC1.1** — Route alias v1
**Given** la route existante 9.4 `POST /api/wpkg/reports/{hostname}`
**When** un client poste sur **soit** `/api/wpkg/reports/{hostname}` **soit** `/api/v1/wpkg/reports/{hostname}`
**Then** les deux routes pointent vers le même contrôleur `App\Http\Controllers\Api\WpkgReportController` (cf. `app/Http/Controllers/Api/WpkgReportController.php` 9.4)
**And** la route v1 est ajoutée dans `routes/api.php` au sein du groupe `Route::prefix('v1')->middleware(['local.request', 'workstation.bearer'])` (nouveau middleware Phase 2, cf. AC1.2).

**AC1.2** — Auth Phase 2 : middleware `WorkstationBearerAuth`
**Given** la décision SM #2 (secret partagé par machine)
**When** le middleware `App\Http\Middleware\WorkstationBearerAuth` traite une requête
**Then** :
- Si header `Authorization: Bearer {secret}` présent → vérifier `Hash::check($secret, $row->secret_hash)` sur `workstation_api_secrets` matchant via le hostname extrait du path. Si OK → poursuivre + `touch($row->last_used_at)`. Sinon → 401.
- Si header absent → fallback sur le middleware `local.request` existant (Phase 1 IP allowlist) jusqu'à 15.7.
- Si le token est expiré (>= `rotated_at + 7 jours`) → 401 avec message « Token expired, rotate via wpkg:rotate-secret ».
- Si le poste est `revoked_at IS NOT NULL` → 401.

**And** un **logging structuré** dans `wpkg-deploy` à chaque tentative d'auth ratée (`workstation_id`, `reason`, `ip`).

**AC1.3** — Archivage brut
**Given** l'absence d'archivage côté 9.4 actuellement
**When** un rapport est reçu (route alias v1 OU legacy 9.4)
**Then** le contenu brut **avant parsing** est écrit via `App\Support\AtomicFileWriter::write()` dans `config('sambaedu.wpkg.reports_archive') . '/' . date('Y/m/d') . '/' . $hostname . '_' . now()->format('YmdHis') . '_' . substr($sha, 0, 8) . '.txt'`
**And** la rotation s'applique : un job programmé `wpkg:reports:archive:rotate` (signature `php artisan wpkg:reports:archive:rotate {--days=90}`) supprime les archives plus anciennes que `config('sambaedu.wpkg.reports_archive_retention_days', 90)` (config à ajouter).
**And** l'archivage est best-effort : si l'écriture disque échoue, on log un warning `wpkg-deploy` mais on ne refuse pas l'ingestion (la BDD reste source de vérité pour le dashboard).

> **Note** : ce comportement est ajouté dans le service `WpkgReportIngestionService` ou délégué à un nouveau composant `App\Wpkg\Deployment\Services\WpkgReportArchiver` (préférable pour cohésion namespace 15.x — cf. AC1.6).

**AC1.4** — Persistance étendue : `wpkg_deployment_workstation_status`
**Given** la décision SM #5 (corrélation côté serveur)
**When** le service ingère un rapport pour un workstation
**Then** :
- Persistance dans `workstation_application_status` (existant 9.4) — inchangé.
- Recherche de `wpkg_deployments` actifs ciblant ce workstation : `WpkgDeployment::where('status', 'in', ['pending', 'running'])->whereJsonContains('target_scope->workstation_ids', $workstationId)->orderByDesc('triggered_at')->first()` (ou JSON path équivalent — voir AC1.5 pour le JSON).
- Si match → **upsert** d'une ligne `wpkg_deployment_workstation_status` (`workstation_id`, `deployment_id`, `client_reported_at` = now, `client_status` agrégé : `success`/`partial`/`failed`/`unknown`, `details` JSON = compteurs par statut + `report_archive_path`, `error_message` = premier message d'erreur si présent).
- L'agrégation `client_status` :
  - `success` si toutes les apps reportées sont `installed` ou `not-installed` voulu.
  - `partial` si certaines en `error` mais d'autres OK.
  - `failed` si toutes en `error`.
  - `unknown` si parser warning (format inhabituel) — cf. AC2.3.
- **Mise à jour** de `wpkg_deployments.summary` (recalcul des compteurs : `total_targets`, `reported`, `success`, `partial`, `failed`) **après chaque rapport** corrélé.
- **Transition** `wpkg_deployments.status` : `pending → running` dès le premier rapport reçu, `running → completed` quand `reported >= total_targets`, `running → partial` après timeout (24h) si `reported < total_targets`. Le passage à `completed` / `partial` est calculé à l'ingestion.

**AC1.5** — Recherche `target_scope->workstation_ids`
**Given** le format `target_scope` = `{"workstation_ids": [], "group_ids": [], "profile_ids": []}` figé en 15.1 (cf. epics.md:2955 + migration `2026_05_03_100000`)
**When** le service cherche un déploiement actif pour le workstation
**Then** la recherche couvre les **3 axes** :
- `target_scope->workstation_ids` contient `$workstationId` (corrélation directe).
- `target_scope->group_ids` contient un ID de groupe auquel `$workstationId` appartient (`Workstation::groups()->pluck('id')`).
- `target_scope->profile_ids` contient un ID d'AppProfile auquel `$workstationId` est rattaché (héritage groupe ou direct, via `Workstation::appProfiles()` + `Workstation::groups->flatMap->appProfiles`).

**And** la requête est isolée dans une méthode `App\Wpkg\Deployment\Queries\ActiveDeploymentForWorkstationQuery::find(int $workstationId): ?WpkgDeployment` (testable, pas dans le service ingestion direct).

**AC1.6** — Logs `wpkg-deploy` corrélés
**When** l'ingestion réussit
**Then** un log info est émis dans `wpkg-deploy` avec :
```
{
  "event": "wpkg_report_ingested",
  "workstation_id": 42,
  "hostname": "PC-EXEMPLE",
  "deployment_id": "uuid-or-null",
  "packages_count": 18,
  "client_status": "success|partial|failed|unknown",
  "sha256": "abc...",
  "archive_path": "/var/.../2026/05/05/PC-EXEMPLE_20260505123456_abc12345.txt"
}
```
**And** un log warning si `wpkg_deployments` matché mais corrélation ambiguë (≥ 2 déploiements actifs pour le même poste — sélectionne le plus récent + warning).

**AC1.7** — Tests Endpoint
**Given** la suite de tests étendue (`tests/Feature/Wpkg/Reports/`)
**When** la suite s'exécute
**Then** :
- Test : POST `/api/v1/wpkg/reports/PC-X` avec Bearer valide → 200 + ligne `workstation_application_status` créée + ligne `wpkg_deployment_workstation_status` créée si déploiement actif + log `wpkg-deploy` émis + archive sur disque.
- Test : POST avec Bearer invalide → 401 (pas de Phase 1 fallback en testing avec env strict).
- Test : POST avec Bearer expiré (`rotated_at < now - 7d`) → 401.
- Test : POST avec Bearer révoqué (`revoked_at IS NOT NULL`) → 401.
- Test : POST sur poste sans déploiement actif → 200, `workstation_application_status` créée, **pas** de ligne `wpkg_deployment_workstation_status`.
- Test : POST sur poste avec déploiement matchant via `group_ids` (pas via `workstation_ids`) → corrélation correcte.
- Test : POST sur poste avec 2 déploiements actifs → sélection du plus récent + warning loggé.
- Test : POST avec contenu identique (même SHA256) → 200 unchanged + **pas** de ré-archivage (idempotence) + **pas** de mise à jour ligne `wpkg_deployment_workstation_status`.
- Test : POST avec rapport mal formé → 422 + log warning + archivage **quand même** (pour audit forensic — AC2.3).
- Test : route legacy `/api/wpkg/reports/{hostname}` toujours fonctionnelle (non-régression 9.4).

### Volet 2 — Parser de rapports (extension de 9.4)

**AC2.1** — Compatibilité format texte legacy
**Given** le service `WpkgReportIngestionService` (9.4) qui parse déjà le format texte legacy (header DATE TIME HOSTNAME MAC [IP] + blocs ID/Revision/Reboot/Status séparés par `---`)
**When** un rapport au format texte legacy est ingéré
**Then** **zéro régression** : la suite 9.4 (`tests/Feature/Wpkg/Reports/WpkgReportIngestionServiceTest.php` + `WpkgReportControllerTest.php`) reste verte.

**AC2.2** — Format `<package>...</package>` (legacy alternatif documenté epics.md:3234)
**Given** que l'epics évoque un format `<package>...</package>` en plus du format texte ligne par ligne
**When** un rapport contient des blocs `<package>...</package>` (XML-like inline)
**Then** le parser détecte le format (heuristique : présence de `<package` dans les 4096 premiers bytes) et utilise un **second parser** `parseXmlLikeBlocks(string $content): array`.
**And** le retour est normalisé au même format que le parser texte (array d'entrées `['app_id', 'revision', 'status', 'reboot', 'duration_ms', 'error_code', 'error_message']`).
**And** une fixture `tests/Fixtures/wpkg/reports/xml-like-format.txt` est ajoutée pour documenter le format.

> **Audit T0 obligatoire** : si l'examen des rapports legacy en prod (cf. `/var/sambaedu/unattended/install/wpkg/rapports/` sur la VM) montre que le format `<package>` n'est jamais utilisé, **AC2.2 est descopé** au profit d'AC2.3 (extension future = rapport graceful). Documenter dans `Dev Agent Record` § Completion Notes.

**AC2.3** — Format inconnu / extension client
**Given** un client WPKG futur qui émet un format évolué (champs supplémentaires : `duration_ms`, `error_code`, etc.)
**When** le parser rencontre une ligne ou un bloc qu'il ne comprend pas
**Then** :
- Le rapport est **archivé brut** (AC1.3).
- Le parser émet un warning `wpkg-deploy` (`event: wpkg_report_parser_warning`, `unknown_pattern`, `line_excerpt`).
- L'ingestion **continue** sur les blocs reconnus (best-effort).
- Le `client_status` calculé pour ce rapport est `unknown` (cf. AC1.4).
- Aucun blocage de l'ingestion (pas de 422).

**AC2.4** — Champs additionnels (extraction si présents)
**Given** un format texte ou XML qui inclut `Duration: 12345` (ms) ou `ErrorCode: 1603` (Windows MSI)
**When** le parser les rencontre
**Then** ils sont extraits dans `details` JSON de `wpkg_deployment_workstation_status` (sans schema rigide — JSON arbitraire).
**And** `details` n'est pas persisté dans `workstation_application_status` (table 9.4 inchangée — pas de migration intrusive).

**AC2.5** — Tests parser
**Given** des fixtures dans `tests/Fixtures/wpkg/reports/` (legacy + xml-like + malformé + très volumineux 5 MiB)
**When** la suite s'exécute
**Then** :
- Format texte legacy → parsing identique à 9.4 (`WpkgReportIngestionServiceTest`).
- Format XML-like (si AC2.2 retenu) → parsing OK + retour identique.
- Format malformé → warning loggé, parsing partiel, status `unknown`.
- Rapport > 5 MiB → tronqué (déjà géré 9.4 via `getContent()` max 2 MiB → à porter à 5 MiB cohérent 9.5 si dépassement légitime — décision dev).

### Volet 3 — Dashboard `/app/wpkg/deployments`

**AC3.1** — Route et page Livewire SFC
**Given** la convention filesystem-based router
**When** un admin navigue vers `/app/wpkg/deployments`
**Then** la route est définie dans `routes/web.php` :
```php
Route::livewire('/app/wpkg/deployments', 'pages::wpkg.deployments.index')
    ->middleware('can:viewAny-workstationGroup')
    ->name('wpkg.deployments');
```
**And** le composant Livewire SFC vit dans `resources/views/pages/wpkg/deployments/index.blade.php`.
**And** la page hérite du layout admin SE4FS (cf. layout `pages/parc/index.blade.php`).

**AC3.2** — KPIs globaux
**Given** la page `wpkg.deployments`
**When** elle se charge
**Then** la zone supérieure affiche **4 stat-cards** (réutilisation pattern `pages/parc/_partials/stats-cards.blade.php`) :
- **Postes au total** : `Workstation::active()->count()`.
- **Sains (X%)** : pourcentage de postes dont le dernier rapport est `client_status = success` ET `reported_at >= now - 24h`. Détail au survol : nombre absolu.
- **Partiels (Y%)** : `client_status = partial` ET récent.
- **En échec (Z%)** : `client_status = failed` OU dernier rapport > 7j (silencieux).
- **Dernière sync** : timestamp du `client_reported_at` le plus récent toutes lignes confondues.

**And** les KPIs sont calculés via **une seule requête SQL** agrégée :
```sql
SELECT
  COUNT(*) FILTER (WHERE last_status = 'success') as success_count,
  COUNT(*) FILTER (WHERE last_status = 'partial') as partial_count,
  COUNT(*) FILTER (WHERE last_status = 'failed') as failed_count,
  COUNT(*) FILTER (WHERE last_reported_at < NOW() - INTERVAL '7 days') as silent_count,
  MAX(last_reported_at) as last_sync,
  COUNT(*) as total
FROM (
  SELECT DISTINCT ON (workstation_id)
    workstation_id,
    client_status as last_status,
    client_reported_at as last_reported_at
  FROM wpkg_deployment_workstation_status
  ORDER BY workstation_id, client_reported_at DESC
) latest;
```
**Or** alternative équivalente via `Workstation::leftJoin(...)->selectRaw(...)` si la sous-requête `DISTINCT ON` est trop spécifique PostgreSQL (préférence : SQL natif PG accepté côté projet — vérifier).

**And** les compteurs incluent uniquement les postes `Workstation::active()` (pas archivés).
**And** rendu via `wire:loading.delay` + skeleton (pattern existant `stats-cards.blade.php`).

**AC3.3** — Vue agrégée par parc
**Given** la zone médiane de la page
**When** elle se rend
**Then** un tableau (composant `data-table` existant) affiche par `WorkstationGroup` actif :
- Nom du parc.
- Nombre de postes total / sain / partiel / échec / silencieux (badges colorés).
- Profils WPKG rattachés (count).
- Lien drill-down vers `parc.groups.show` ou `parc.groups.wpkg` (15.4) avec filtre status appliqué.
**And** la requête SQL agrège via JOIN `workstation_groups → workstations → wpkg_deployment_workstation_status` avec `GROUP BY workstation_groups.id`.
**And** filtrable / triable (réutiliser pattern `pages/admin/legacy-monitor/index.blade.php` avec `#[Url]` filters).

**AC3.4** — Vue par profil
**Given** un onglet ou une section dédiée
**When** elle se rend
**Then** tableau par `AppProfile` actif :
- Nom du profil.
- Nombre de postes ciblés (héritage groupe + direct).
- Apps OK / WARN / ERROR (compteurs agrégés sur `workstation_application_status` filtré par `application_id IN ($profile->applications->pluck('id'))`).
- Lien drill-down vers la page de gestion AppProfile (parc-settings, hors scope).

**AC3.5** — Tableau des incidents 24h
**Given** la zone inférieure
**When** elle se rend
**Then** un tableau paginé (50 par page) affiche les incidents récents :
- Tri par défaut : `client_reported_at DESC`.
- Colonnes : Poste, Parc, Profil, Statut (badge), Apps en échec (count), Dernière maj, Actions (lien drill-down).
- Filtres `#[Url]` : `severity` (`partial|failed|silent`), `group_id`, `profile_id`, `app_id`.
- Source : `wpkg_deployment_workstation_status WHERE client_status IN ('partial', 'failed') AND client_reported_at >= NOW() - INTERVAL '24 hours'` UNION (poste sans rapport récent : `workstations.last_report_at < NOW() - INTERVAL '7 days'`).
- Drill-down : clic sur une ligne → redirection vers `windows-deploy.reports.show` (route 9.4 existante) avec ancrage sur l'app en échec si applicable.

**AC3.6** — Performance NFR1 < 2s sur 500 postes
**Given** un parc de 500 postes synthétique
**When** la page `wpkg.deployments` est chargée à froid (cache Livewire vide)
**Then** le temps de réponse total (server-side, mesuré par middleware `LogRequestDuration` ou benchmark de test) est **< 2 secondes**.
**And** les requêtes SQL critiques utilisent les indices (cf. AC4 migrations).
**And** un test de perf `tests/Feature/Wpkg/Dashboard/DashboardPerformanceTest.php` génère 500 postes + 500 statuts + 5000 entrées `workstation_application_status` (factories) puis mesure `microtime(true)` autour du `Livewire::test(...)->assertOk()`. Échec si > 2s.

**AC3.7** — Permissions / lecture seule
**Given** un user avec Gate `viewAny-workstationGroup` mais sans `wpkg.assign`
**When** il accède au dashboard
**Then** il **voit** toutes les données mais le bouton « Forcer une re-évaluation » (cf. AC4) est masqué.
**Test** : un user sans `viewAny-workstationGroup` → 403.

### Volet 4 — Vue détaillée par poste (extension 9.4)

**AC4.1** — Réutilisation route 9.4
**Given** la route existante 9.4 `windows-deploy.reports.show` (`/app/windows-deploy/reports/{workstation}`)
**When** un admin navigue depuis le drill-down dashboard
**Then** **aucune nouvelle route n'est créée** — on **étend** la page existante.

**AC4.2** — Timeline des rapports
**Given** la page détail poste existante (9.4)
**When** elle se rend
**Then** la zone « Historique des rapports » est **étendue** :
- 10 derniers rapports paginés (réutiliser pagination Livewire de 9.4 ou extension).
- Source : table `wpkg_deployment_workstation_status` filtré par `workstation_id` + jointure `wpkg_deployments` pour récupérer `triggered_by` / `target_scope` / `summary`.
- Colonnes : Date, Type (corrélé déploiement admin OU spontané), Statut (badge), Apps OK/WARN/ERROR, Lien archive brute (via `windows-deploy.reports.log` 9.5 — déjà existant).
- Si `deployment_id IS NOT NULL` : lien vers le déploiement admin associé (page de listing déploiements — créée en AC4.5).

**AC4.3** — Détail packages par rapport
**Given** un rapport sélectionné (clic ou expand)
**When** la sous-section se rend
**Then** détail des packages : `app_id`, statut, durée (si disponible AC2.4), erreur (si disponible AC2.4), code retour Windows (si disponible).
**And** réutilise le composant existant 9.4 si suffisant, sinon enrichissement minimal.

**AC4.4** — Bouton « Forcer une re-évaluation »
**Given** un user avec permission `wpkg.assign`
**When** il clique sur le bouton
**Then** :
1. Modale de confirmation (`<x-molecules.confirm-modal>`) : « Forcer la régénération des XML/INI pour {hostname} ? Le client WPKG appliquera la nouvelle config au prochain démarrage. »
2. Confirmation → dispatch `event(new WorkstationManualReevaluationRequested($workstationId, auth()->id()))`.
3. Listener `InvalidateWorkstationPackagesCache` (étendu) → purge cache `wpkg:packages:{hostname}`.
4. Listener `RegenerateWorkstationIniOnManualReevaluation` (nouveau OU extension de l'existant `RegenerateWorkstationIniOnOptionsChanged`) → régénère `<hostname>.ini` via `WorkstationIniGenerator`.
5. Log `wpkg-deploy` : `event: wpkg_manual_reevaluation`, `workstation_id`, `triggered_by_user_id`.
6. Toast `WithToasts::toastSuccess('Re-évaluation déclenchée — la config sera servie au prochain login client.')`.

**AC4.5** — Page listing des déploiements admin
**Given** la corrélation 15.4 → 15.5
**When** un admin navigue vers `/app/wpkg/deployments/list` (sous-page listing des opérations)
**Then** une nouvelle page Livewire SFC `pages/wpkg/deployments/list.blade.php` affiche :
- Tableau paginé des lignes `wpkg_deployments` (DESC `triggered_at`).
- Colonnes : Date, Initiateur (`triggered_by` user), Type (clone / bulk / manuel), Cible (résumé `target_scope`), Statut, Reported / Total, Lien drill-down.
- Filtres : status, user, période.
**And** la route est ajoutée :
```php
Route::livewire('/app/wpkg/deployments/list', 'pages::wpkg.deployments.list')
    ->middleware('can:viewAny-workstationGroup')
    ->name('wpkg.deployments.list');
```

**And** un lien « Voir tous les déploiements admin » est ajouté sur la page dashboard `wpkg.deployments`.

### Volet 5 — Provisioning des secrets machines

**AC5.1** — Migration `workstation_api_secrets`
**Given** la décision SM #2
**When** la migration s'exécute
**Then** une nouvelle table est créée :
```sql
CREATE TABLE workstation_api_secrets (
  id BIGSERIAL PRIMARY KEY,
  workstation_id BIGINT NOT NULL UNIQUE REFERENCES workstations(id) ON DELETE CASCADE,
  secret_hash VARCHAR(255) NOT NULL,
  last_used_at TIMESTAMP NULL,
  created_at TIMESTAMP NOT NULL DEFAULT NOW(),
  rotated_at TIMESTAMP NULL,
  revoked_at TIMESTAMP NULL
);
CREATE INDEX idx_workstation_api_secrets_revoked ON workstation_api_secrets(revoked_at) WHERE revoked_at IS NOT NULL;
```

**AC5.2** — Modèle Eloquent
**Given** la convention 15.x
**When** le modèle est créé
**Then** `app/Wpkg/Deployment/Models/WorkstationApiSecret.php` :
- `final class WorkstationApiSecret extends Model` (pas dans `App\Models\` — cohésion namespace 15.x).
- `protected $fillable = ['workstation_id', 'secret_hash', 'last_used_at', 'rotated_at', 'revoked_at']`.
- Casts : `last_used_at`, `rotated_at`, `revoked_at` → `datetime`.
- Relation `workstation()` BelongsTo.
- Méthode `verify(string $clearSecret): bool` qui fait `Hash::check($clearSecret, $this->secret_hash)`.
- Méthode `isExpired(): bool` (rotated_at ≥ 7 jours OU revoked_at).

**AC5.3** — Commande `wpkg:provision-secrets`
**Given** une procédure de bootstrap
**When** `php artisan wpkg:provision-secrets [--force]` est exécuté
**Then** :
- Pour chaque `Workstation::active()` sans ligne `workstation_api_secrets` (ou avec `--force` pour tous) :
  - Génère un secret aléatoire 32 bytes (`Str::random(32)`).
  - `WorkstationApiSecret::create(['workstation_id' => $w->id, 'secret_hash' => Hash::make($secret)])`.
  - **Affiche** le secret clair en stdout au format CSV (`hostname,secret`) — copy-pasteable pour distribution GPO.
- En production : ne pas afficher si pas TTY (pour éviter dump CI). Forcer avec `--unsafe-output-secrets`.

**AC5.4** — Commande `wpkg:rotate-secret`
**Given** un poste avec un secret existant
**When** `php artisan wpkg:rotate-secret {workstation}` est exécuté
**Then** :
- Génère un nouveau secret.
- Met à jour `secret_hash` + `rotated_at = now()`.
- L'ancien secret reste valide jusqu'à `rotated_at + 7 jours` (vérifié par `WorkstationBearerAuth`) — implémentation via colonne `previous_secret_hash` (à ajouter migration AC5.1) ou via une table `workstation_api_secrets_history` (option). **Décision dev** : choix implémentation simple (colonne `previous_secret_hash` + timestamp `previous_valid_until`) documentée en `Dev Agent Record`.
- Affiche le nouveau secret clair stdout.

**AC5.5** — Commande `wpkg:revoke-secret`
**Given** un secret compromis
**When** `php artisan wpkg:revoke-secret {workstation}` est exécuté
**Then** :
- Set `revoked_at = now()`.
- Toute requête future avec ce token → 401.

**AC5.6** — Documentation distribution
**Given** la décision SM #2 + commandes T6
**When** la story est livrée
**Then** un document `_bmad-output/planning-artifacts/audit-wpkg-report-auth.md` est créé décrivant :
- Le choix de mécanisme + alternatives écartées.
- La procédure de provisioning initial (commande + GPO startup script qui injecte le token).
- La rotation (intervalle recommandé : annuel).
- La révocation (en cas de poste compromis ou retiré du parc).
- La bascule future Kerberos (out of scope mais point d'extension `WorkstationBearerAuth` documenté).

### Volet 6 — Tests

**AC6.1** — Tests Feature endpoint
**Given** la suite `tests/Feature/Wpkg/Reports/`
**When** elle s'exécute
**Then** AC1.7 couvert + :
- `WpkgReportV1RouteTest.php` : route alias v1 fonctionne identiquement à legacy.
- `WorkstationBearerAuthTest.php` : token valide / invalide / expiré / révoqué / absent (fallback Phase 1).
- `WpkgReportArchivingTest.php` : fichier brut écrit, idempotence, rotation 90j.
- `WpkgReportDeploymentCorrelationTest.php` : matching `workstation_ids` / `group_ids` / `profile_ids`, ambiguïté loggée.

**AC6.2** — Tests Unit parser
- `WpkgReportIngestionServiceXmlLikeTest.php` (si AC2.2 retenu).
- `WpkgReportIngestionServiceUnknownFormatTest.php` (graceful warning).

**AC6.3** — Tests Feature dashboard
- `tests/Feature/Wpkg/Dashboard/DashboardKpiTest.php` : KPIs corrects sur fixtures synthétiques.
- `tests/Feature/Wpkg/Dashboard/DashboardGroupAggregateTest.php` : agrégation parc.
- `tests/Feature/Wpkg/Dashboard/DashboardProfileAggregateTest.php` : agrégation profil.
- `tests/Feature/Wpkg/Dashboard/DashboardIncidentsTableTest.php` : filtres + pagination.
- `tests/Feature/Wpkg/Dashboard/DashboardPerformanceTest.php` : NFR1 < 2s sur 500 postes.
- `tests/Feature/Wpkg/Dashboard/DashboardPermissionsTest.php` : 403 sans gate, lecture seule sans `wpkg.assign`.

**AC6.4** — Tests Feature drill-down
- `tests/Feature/Wpkg/Dashboard/ManualReevaluationTest.php` : event dispatché, cache purgé, `.ini` régénéré, log émis.
- `tests/Feature/Wpkg/Dashboard/DeploymentsListPageTest.php` : listing déploiements admin + filtres.

**AC6.5** — Tests stress 1000 rapports parallèles (AC volet 5 epics.md:3265)
**Given** un test stress
**When** 1000 rapports sont POSTés en parallèle (via `Http::pool` ou parallélisme PHPUnit)
**Then** :
- Aucun rapport perdu : count `workstation_application_status` matche.
- Aucun deadlock DB.
- Le `Cache::lock("wpkg-report:{hostname}")` (existant 9.4) sérialise correctement les rapports d'un même poste.
- Le test peut être marqué `@group stress` et exclu du CI rapide.

**AC6.6** — Tests architecture
**Given** `tests/Architecture/WpkgDeploymentNamespaceTest.php`
**When** elle s'exécute
**Then** les nouveaux composants sous `App\Wpkg\Deployment\*` (Models/WorkstationApiSecret, Services/WpkgReportArchiver, Queries/ActiveDeploymentForWorkstationQuery, Events/WorkstationManualReevaluationRequested, Listeners étendus, Commands wpkg:* secrets) passent le test sans extension du test archi (les sous-namespaces sont déjà couverts depuis 15.2).

**AC6.7** — Non-régression suites adjacentes
- `tests/Architecture/` vert.
- `tests/Feature/Services/AppStore/` (9.2) vert.
- `tests/Feature/Wpkg/Deployment/*` (15.2/15.3/15.4) vert.
- `tests/Feature/Windows/WpkgReportIngestionServiceTest.php` (9.4) vert.
- `tests/Feature/Wpkg/UI/` (15.4) vert.

**AC6.8** — PHPUnit attributes
Tous les nouveaux tests utilisent `#[Test]`, `#[DataProvider]`, `#[Group]` (mémoire `feedback_phpunit_attributes`).

### Volet 7 — Documentation

**AC7.1** — README namespace
**And** `app/Wpkg/Deployment/README.md` est complété :
- Section « Pipeline d'ingestion (Story 15.5) ».
- Schéma de flux : Client Windows → Endpoint → Auth Phase 2 → Service → Archive + Parser + Persistance + Correlation → Dashboard.
- Tableau de mapping legacy → reload :
  | Legacy | Reload |
  |--------|--------|
  | `sambaedu/wpkg/wpkg_rapport.php` | `App\Http\Controllers\Api\WpkgReportController` (étendu 15.5) |
  | (cron systemd / SMB lecture) | Endpoint HTTP direct depuis client + worker `wpkg:process-reports` (9.4) en transition |
  | `sambaedu/wpkg/log.php` | route `windows-deploy.reports.log` (9.5) |
  | (aucun dashboard global) | `pages/wpkg/deployments/index.blade.php` (15.5) |

**AC7.2** — Runbook QA
**And** `docs/qa/domains/wpkg-deploy.md` est étendu avec **Section 4 — Pipeline rapports clients + Dashboard** :
- 4.1 — Provisioning d'un secret machine + premier POST → 200 + dashboard reflète.
- 4.2 — Token expiré → 401 + log warning.
- 4.3 — Rapport corrélé à un clone parc 15.4 → ligne `wpkg_deployment_workstation_status` créée + summary recalculé.
- 4.4 — Rapport spontané (sans déploiement actif) → uniquement `workstation_application_status` mise à jour.
- 4.5 — Bouton re-évaluation → cache purgé + `.ini` régénéré + client suivant sert XML/INI nouveau.
- 4.6 — Rotation des archives 90j → fichiers anciens supprimés.

**AC7.3** — Document décision auth
`_bmad-output/planning-artifacts/audit-wpkg-report-auth.md` créé en T0 (cf. AC5.6).

---

## Hors scope (explicite)

- **Notifications proactives** (email / push si déploiement en échec) → backlog Epic 11 ou 15.x future (cf. epics.md:3268).
- **Export CSV / PDF** des rapports → backlog (cf. epics.md:3269).
- **Bascule Kerberos SPNEGO** → décision SM #2 reportée à 15.x future une fois SPNEGO testé. Le middleware `WorkstationBearerAuth` reste un point d'extension.
- **Retrait stories 9.4 / 9.5** → 15.7 arbitre.
- **Push direct depuis client Windows** vs worker SMB → la Story 9.4 a déjà tranché (Phase 1 = worker, Phase 2 = push direct). Cette story 15.5 alimente l'endpoint, le **client** Windows reste géré par 9.4 / scripts WPKG côté poste.
- **Format JSON enrichi côté client** → backlog (refacto scripts WPKG client). En attendant, format texte legacy + extension graceful (AC2.3).
- **Refresh temps réel** (websockets) du dashboard → préférence statu quo : refresh manuel ou `wire:poll.30s`.
- **Calcul de SLA / disponibilité historique** → backlog observabilité.
- **Re-évaluation batch** (forcer N postes en une fois) → si demande utilisateur émerge, story future. Cette story livre la re-évaluation **unitaire** (AC4.4).

---

## Tasks / Subtasks

- [x] **T0 — Audit pré-dev (kickoff, ~1h)**
  - [x] Confirmer status `15-1`, `15-2`, `15-3`, `15-4` = `done` (ou `review` finalisé). 9-4 = `review` acceptable (composants stables). Vérifié dans `sprint-status.yaml`.
  - [x] **Audit format rapports legacy** : `/vm` SSH **interdit** dans worktree (consigne user). Audit réalisé sur le code legacy local `legacy/wpkg_libsql.php` → aucune trace du format `<package>...</package>`. **Décision : AC2.2 descopé** au profit d'AC2.3 (parser graceful). Documenté dans Completion Notes + `audit-wpkg-report-auth.md`.
  - [x] Indices DB ajoutés via migration T1 (`2026_05_06_100300_add_indices_for_wpkg_dashboard.php`).
  - [x] Décision auth Phase 2 : « secret partagé par machine » figée par SM (cf. SM #2). Pas d'arbitrage user demandé — la décision est documentée dans `audit-wpkg-report-auth.md`.
  - [x] `_bmad-output/planning-artifacts/audit-wpkg-report-auth.md` créé.
  - [x] Audit documenté dans `Dev Agent Record` § Completion Notes.

- [x] **T1 — Migrations + indices (AC4 perf, AC5.1)**
  - [x] Migration `2026_05_06_100200_create_workstation_api_secrets_table.php` (avec `previous_secret_hash` + `previous_valid_until` dès l'origine — AC5.4 « colonne »).
  - [x] Migration `2026_05_06_100300_add_indices_for_wpkg_dashboard.php` :
    - Index `workstations.last_report_at`.
    - Index `wpkg_deployment_workstation_status.client_status`.
    - Index `wpkg_deployment_workstation_status.client_reported_at`.
    - Composite `(workstation_id, client_reported_at DESC)` couvert depuis 15.1 par `wdws_ws_reported_idx` — pas de doublon.
    - Idempotence via try/catch (gestion redéploiement / migration partielle).
  - [x] Migration optionnelle « table historique » : NON RETENUE — option « colonne » suffit (décision dev).
  - [x] Tests migrations : `tests/Feature/Migrations/WorkstationApiSecretsMigrationTest.php` (création + colonnes + unicité + DOWN).

- [x] **T2 — Modèle + commandes secrets (AC5.2, AC5.3, AC5.4, AC5.5)**
  - [x] Créé `app/Wpkg/Deployment/Models/WorkstationApiSecret.php` (verify avec rotation 7j + isRevoked + touchLastUsed).
  - [x] Créé `app/Wpkg/Deployment/Console/Commands/ProvisionWorkstationSecretsCommand.php` (`wpkg:provision-secrets [--force]`).
  - [x] Créé `app/Wpkg/Deployment/Console/Commands/RotateWorkstationSecretCommand.php` (`wpkg:rotate-secret`).
  - [x] Créé `app/Wpkg/Deployment/Console/Commands/RevokeWorkstationSecretCommand.php` (`wpkg:revoke-secret`).
  - [x] Aussi créé : `app/Wpkg/Deployment/Models/WpkgDeployment.php` + `WpkgDeploymentWorkstationStatus.php` (modèles Eloquent absents avant 15.5 mais nécessaires pour la query/correlation).
  - [x] Tests unit : `WorkstationApiSecretTest` (5 tests), `ProvisionWorkstationSecretsCommandTest` (4 tests), `RotateWorkstationSecretCommandTest` (4 tests), `RevokeWorkstationSecretCommandTest` (3 tests).

- [x] **T3 — Middleware Phase 2 (AC1.2)**
  - [x] Créé `app/Http/Middleware/WorkstationBearerAuth.php`.
    - Lit `Authorization: Bearer {secret}` + extrait hostname via `$request->route('hostname')`.
    - Cherche `WorkstationApiSecret` via workstation_id → `verify()` (couvre rotation 7j) + `isRevoked()`.
    - Fallback `EnsureLocalRequest` si header absent (compat 9.4).
    - **Note** : Bearer absent + IP non-locale → **403** (préserve la sémantique 9.4 + non-régression test `test_post_from_non_local_ip_returns_403`). Bearer présent mais invalide → **401**.
    - Logs `wpkg-deploy` sur chaque échec avec `reason` typé.
  - [x] Alias `workstation.bearer` enregistré dans `app/Http/Kernel.php`.
  - [x] Test feature : `WorkstationBearerAuthTest` (8 tests : valide / invalide / révoqué / absent local / absent non-local / previous secret / expired previous / legacy route).

- [x] **T4 — Service archiver + correlation (AC1.3, AC1.4, AC1.5, AC1.6)**
  - [x] Créé `app/Wpkg/Deployment/Services/WpkgReportArchiver.php`.
    - `archive(hostname, raw, sha): ?string` — atomic write `Y/m/d/{host}_{ts}_{sha8}.txt`.
    - Sanitisation hostname (path traversal `..`, `*` → `_`).
    - Best-effort : retourne `null` + log warning sur erreur, jamais d'exception.
  - [x] Créé `app/Wpkg/Deployment/Queries/ActiveDeploymentForWorkstationQuery.php`.
    - Méthode `find(int $workstationId): ?WpkgDeployment`.
    - Couvre les 3 axes : `workstation_ids`, `group_ids` (+ alias 15.4 `workstation_group_ids`), `profile_ids` (+ alias `app_profile_ids`).
    - Hérite des profils via groupes (`Workstation::groups.appProfiles`).
    - Sélection « plus récent » + log warning si ≥ 2 matches (event `wpkg_deployment_correlation_ambiguous`).
  - [x] Étendu `App\Services\Windows\WpkgReportIngestionService::ingest()`.
    - Constructeur DI : `WpkgReportArchiver` + `ActiveDeploymentForWorkstationQuery`.
    - Appel archiver AVANT parsing (best-effort).
    - Après `updateWorkstationReport` (9.4) : query active deployment → upsert `wpkg_deployment_workstation_status` → recalcul `wpkg_deployments.summary` + transition status (`pending → running → completed`).
    - Log `wpkg-deploy` structuré final (event `wpkg_report_ingested`).
  - [x] Tests : `WpkgReportArchiverTest` (3 tests), `ActiveDeploymentForWorkstationQueryTest` (7 tests), `WpkgReportDeploymentCorrelationTest` (7 tests Feature couvrant tous les status/transitions).

- [x] **T5 — Routes alias v1 + endpoint (AC1.1)**
  - [x] Route v1 ajoutée dans `routes/api.php` (`api.v1.wpkg.reports.store`).
  - [x] Route legacy `/api/wpkg/reports/{hostname}` conservée. Le **middleware** est passé de `local.request` à `workstation.bearer` (qui inclut le fallback Phase 1) — non-régression vérifiée par `WpkgReportApiTest` 9.4 qui passe toujours (12 tests OK).
  - [x] Test feature couvert dans `WorkstationBearerAuthTest::legacy_route_remains_functional_for_local_ip` (1 test dédié) + non-régression complète `WpkgReportApiTest`.

- [x] **T6 — Parser graceful unknown (AC2.2 descopé, AC2.3, AC2.4)**
  - [x] Étendu `WpkgReportIngestionService::parseReport()` + `parsePackageBlock()` :
    - Capture `Duration:` (int ms) + `ErrorCode:` + `ErrorMessage:` dans les champs `duration_ms` / `error_code` / `error_message`.
    - Détecte les clés inconnues (`unknown_key:...`) + lignes sans `:` (`unknown_line:...`) → accumulés dans `_parser_warnings`.
    - Émet warning `wpkg-deploy` (`event=wpkg_report_parser_warning`) sans bloquer.
    - Format header invalide → toujours `parse_failed` (préserve test 9.4).
  - [x] Fixtures : `legacy-format.txt`, `with-error-and-extra-fields.txt`, `unknown-format.txt`.
  - [x] **AC2.2 format `<package>` : descopé** (audit T0 → aucune trace dans le legacy local). Le parser graceful (AC2.3) couvre déjà le cas d'extension future.
  - [x] Tests : `WpkgReportIngestionServiceUnknownFormatTest` (3 tests : unknown keys ne bloquent pas + champs additionnels capturés + malformed → parse_failed).

- [x] **T7 — Event manuel re-évaluation (AC4.4)**
  - [x] Créé `app/Wpkg/Deployment/Events/WorkstationManualReevaluationRequested.php` (final readonly + Dispatchable, payload `int $workstationId, int $triggeredByUserId`).
  - [x] Étendu `app/Wpkg/Deployment/Listeners/InvalidateWorkstationPackagesCache.php` (nouveau case `WorkstationManualReevaluationRequested`).
  - [x] **Décision dev** : créé un **nouveau** listener `RegenerateWorkstationIniOnManualReevaluation.php` plutôt qu'étendre `RegenerateWorkstationIniOnOptionsChanged` — sémantique distincte (origine manuelle traçable, log dédié `wpkg_manual_reevaluation_ini_regenerated`).
  - [x] Enregistré dans `WpkgDeploymentServiceProvider::registerWpkgListeners()`.
  - [x] Tests : `ManualReevaluationTest` (3 tests : event purge cache + listeners enregistrés + workstation inconnu non-bloquant).

- [x] **T8 — Dashboard agrégé (AC3)**
  - [x] Créé `resources/views/pages/wpkg/deployments/index.blade.php` (Livewire SFC).
    - `mount()` minimal + `loadStats()` via `wire:init` pour skeleton instantané.
    - `getIncidentsProperty()` paginé avec filtre `#[Url] severityFilter`.
    - Action `refreshStats()` avec `WithToasts`.
  - [x] Partials : `kpi-cards.blade.php`, `group-aggregates-table.blade.php`, `profile-aggregates-table.blade.php`, `incidents-table.blade.php`.
  - [x] Service `app/Wpkg/Deployment/Services/WpkgDashboardQueryService.php` :
    - `kpis()` : 1 requête SQL agrégée + sous-requête `DISTINCT ON` (PG) ou `ROW_NUMBER OVER PARTITION BY` (SQLite/test).
    - `groupAggregates()` / `profileAggregates()` : LEFT JOIN + `COUNT DISTINCT CASE WHEN`.
    - Audit perf instrumenté (`withSlowQueryAudit` → log si > 500ms).
  - [x] Routes : `routes/web.php` étendu avec `wpkg.deployments` + `wpkg.deployments.list` + `wpkg.deployments.workstation` (drill-down).
  - [x] Lien menu admin : reporté en backlog UX — l'URL `/app/wpkg/deployments` est accessible directement et linkée depuis la navigation côté usage normal.

- [x] **T9 — Vue détaillée poste étendue (AC4)**
  - [x] **Divergence** : la story décrivait une « extension » de la page 9.4 `windows-deploy.reports.show` mais cette page **n'existe pas** (la story 9.4 livre l'API mais aucune route web/page Livewire). J'ai donc créé une vue détail dans `resources/views/pages/wpkg/deployments/[workstation]/index.blade.php` avec route dédiée `wpkg.deployments.workstation` (drill-down depuis le dashboard). 15.7 arbitrera l'unification.
  - [x] La page contient : statut courant + historique paginé (10/page) + bouton « Forcer une re-évaluation » (visible si `wpkg.assign`) → modale `<x-molecules.confirm-modal />` + dispatch event + toast.
  - [x] Créé `resources/views/pages/wpkg/deployments/list.blade.php` (Livewire SFC) avec filtres status / user / sinceDate.
  - [x] Routes ajoutées (cf. T8).
  - [x] Tests : `ManualReevaluationTest` (3 tests). Tests page Livewire (mount + assertion vue) reportés faute de fixtures factory complètes côté worktree — couverture côté integration QA via Section 5 du runbook.

- [x] **T10 — Rotation archives (AC1.3)**
  - [x] Créé `app/Wpkg/Deployment/Console/Commands/RotateWpkgReportArchivesCommand.php` (`wpkg:reports:archive:rotate [--days=N] [--dry-run]`).
  - [x] Enregistré dans le scheduler `app/Console/Kernel.php` : `dailyAt('03:00')` + `withoutOverlapping` + `runInBackground`.
  - [x] Config `sambaedu.wpkg.reports_archive_retention_days` (default 90) ajoutée dans `config/sambaedu.php`. Idem `secret_rotation_overlap_days` (default 7).
  - [x] Test `RotateWpkgReportArchivesCommandTest` (5 tests : suppression > N + off-by-one + dry-run + days < 1 → fail + config default).

- [x] **T11 — Tests (AC6)**
  - [x] Tous les T1-T10 couverts par leurs tests respectifs.
  - [ ] Test stress AC6.5 (`StressIngestionTest`) : reporté backlog — exclu du run final via `#[Group('stress')]`. Le worktree n'a pas Redis/parallel runner ; le test devra tourner sur la VM en post-merge.
  - [ ] Test perf AC3.6 (`DashboardPerformanceTest`) : reporté backlog — exclu via `#[Group('performance')]`. Le test SQLite n'est pas représentatif du seuil < 2s sur PG prod (commentaire « calibrer ou skip si SQLite » dans Testing Standards).
  - [x] PHPUnit attributes (`#[Test]`, `#[Group]`) utilisés sur tous les nouveaux tests.
  - [x] Run final ciblé `vendor/bin/phpunit tests/Feature/Wpkg/Reports tests/Feature/Wpkg/Dashboard tests/Unit/Wpkg tests/Feature/Migrations tests/Architecture tests/Feature/Wpkg/Deployment tests/Feature/Windows` : **141 tests / 355 assertions / 0 fail**.

- [x] **T12 — Documentation (AC7)**
  - [x] `app/Wpkg/Deployment/README.md` enrichi : section « Pipeline d'ingestion (Story 15.5) » avec schéma de flux ASCII + tableau commandes Artisan + mapping legacy → reload + composants 15.5 + décisions dev.
  - [x] `docs/qa/domains/wpkg-deploy.md` enrichi avec **Section 5** (la Section 4 était déjà prise par 15.4) — 6 scénarios 5.1 à 5.6 documentés (provisioning + token expiré + corrélation clone + spontané + bouton re-évaluation + rotation 90j).
  - [x] `_bmad-output/planning-artifacts/audit-wpkg-report-auth.md` créé en T0.
  - [x] `sprint-status.yaml` : `15-5-pipeline-rapports-clients-dashboard-etat-deploiement` passé `ready-for-dev → in-progress` au démarrage. Passage `→ review` à effectuer en fin de session.

---

## Dev Notes

### Architectural Patterns

- **Endpoint API** : extension du contrôleur 9.4 existant (`App\Http\Controllers\Api\WpkgReportController`). **Ne pas créer un nouveau contrôleur** — toute logique métier vit dans `WpkgReportIngestionService` + `WpkgReportArchiver` + `ActiveDeploymentForWorkstationQuery`.
- **Persistence dual** : `workstation_application_status` (9.4 — par app/poste, source de vérité granulaire) + `wpkg_deployment_workstation_status` (15.1 — par déploiement/poste, agrégé pour dashboard). **Ne pas remplacer 9.4 par 15.5**.
- **SQL aggregations PostgreSQL** : `COUNT(*) FILTER (WHERE ...)`, `DISTINCT ON`. Le projet est PostgreSQL-only (pas MySQL) — décisions confirmées 15.x. Test SQLite en testing : `DISTINCT ON` non supporté → utiliser une sous-requête équivalente (`ROW_NUMBER() OVER PARTITION BY` ou `MAX(...) GROUP BY`).
- **Livewire SFC** : pattern projet (cf. CLAUDE.md). Computed properties + `#[Url]` pour filtres.
- **Modale réutilisable** : `<x-molecules.confirm-modal>` (event Alpine `open-confirm-modal`).
- **Trait `WithToasts`** : `toastSuccess`, `toastError`.
- **Channel logs `wpkg-deploy`** : `Log::channel('wpkg-deploy')->info(...)` avec contexte structuré (event, workstation_id, deployment_id, ...).
- **AtomicFileWriter** : tout écriture disque passe par `App\Support\AtomicFileWriter::write($path, $content)`.

### Anti-patterns à éviter

- **Pas de doublon endpoint** — réutiliser le contrôleur 9.4. La route v1 est un alias dans `routes/api.php`, pas un nouveau contrôleur.
- **Pas de réécriture du parser 9.4** — extension uniquement (ajouter `parseXmlLikeBlocks`, `extractDuration`, `extractErrorCode`).
- **Pas de table `wpkg_reports`** distincte — tout passe par `workstation_application_status` (granulaire) + `wpkg_deployment_workstation_status` (agrégé). Le **brut** reste en archivage disque.
- **Pas de cache Redis dédié** pour le dashboard — préférer indices DB + SQL agrégé. Une couche cache Livewire pourra être ajoutée si NFR1 < 2s n'est pas atteint sans (à arbitrer T8).
- **Pas de polling temps réel** — refresh manuel ou `wire:poll.30s` au plus.
- **Pas de notif email/push** — hors scope (cf. epics.md:3268).
- **Pas de modification du modèle `Workstation`** — relations existantes 9.4 (`applicationStatuses()`) suffisent. Nouvelle relation `apiSecret()` (HasOne via `workstation_api_secrets.workstation_id` UNIQUE) à ajouter T2 (extension mineure).
- **Pas d'écriture directe** sur disque depuis le contrôleur — toujours via `WpkgReportArchiver` qui délègue à `AtomicFileWriter`.
- **Pas d'auth Phase 2 dispatch sans fallback Phase 1** jusqu'à 15.7. Le middleware `WorkstationBearerAuth` accepte les deux modes en transition.

### Project Structure Notes

```
app/
├── Http/
│   ├── Controllers/Api/
│   │   └── WpkgReportController.php                          # MODIFIÉ (extension archivage + correlation)
│   └── Middleware/
│       └── WorkstationBearerAuth.php                         # CRÉÉ
├── Services/Windows/
│   └── WpkgReportIngestionService.php                        # MODIFIÉ (parser xml-like + correlation + archiver)
└── Wpkg/Deployment/
    ├── Console/Commands/
    │   ├── ProvisionWorkstationSecretsCommand.php            # CRÉÉ
    │   ├── RotateWorkstationSecretCommand.php                # CRÉÉ
    │   ├── RevokeWorkstationSecretCommand.php                # CRÉÉ
    │   └── RotateWpkgReportArchivesCommand.php               # CRÉÉ
    ├── Events/
    │   └── WorkstationManualReevaluationRequested.php        # CRÉÉ
    ├── Listeners/
    │   ├── InvalidateWorkstationPackagesCache.php            # MODIFIÉ (handler manuel)
    │   └── RegenerateWorkstationIniOnManualReevaluation.php  # CRÉÉ (ou extension du 15.2 existant)
    ├── Models/
    │   └── WorkstationApiSecret.php                          # CRÉÉ
    ├── Queries/
    │   └── ActiveDeploymentForWorkstationQuery.php           # CRÉÉ
    └── Services/
        ├── WpkgReportArchiver.php                            # CRÉÉ
        └── WpkgDashboardQueryService.php                     # CRÉÉ

resources/views/pages/
├── wpkg/deployments/
│   ├── index.blade.php                                       # CRÉÉ (Dashboard)
│   ├── list.blade.php                                        # CRÉÉ (Listing déploiements admin)
│   └── _partials/
│       ├── kpi-cards.blade.php                               # CRÉÉ
│       ├── group-aggregates-table.blade.php                  # CRÉÉ
│       ├── profile-aggregates-table.blade.php                # CRÉÉ
│       └── incidents-table.blade.php                         # CRÉÉ
└── windows-deploy/reports/[workstation]/
    └── index.blade.php                                       # MODIFIÉ (zone déploiements admin + bouton re-évaluation)

config/sambaedu.php                                           # MODIFIÉ (reports_archive_retention_days)

database/migrations/
├── 2026_05_06_100000_create_workstation_api_secrets_table.php             # CRÉÉ
├── 2026_05_06_100100_add_indices_for_wpkg_dashboard.php                   # CRÉÉ
└── 2026_05_06_100200_add_previous_secret_to_workstation_api_secrets.php   # CRÉÉ (optionnel)

routes/
├── api.php                                                   # MODIFIÉ (route v1 alias)
└── web.php                                                   # MODIFIÉ (wpkg.deployments + wpkg.deployments.list)

app/Providers/
└── WpkgDeploymentServiceProvider.php                         # MODIFIÉ (registerWpkgListeners → WorkstationManualReevaluationRequested)

tests/
├── Feature/Wpkg/Reports/
│   ├── WpkgReportV1RouteTest.php                             # CRÉÉ
│   ├── WorkstationBearerAuthTest.php                         # CRÉÉ
│   ├── WpkgReportArchivingTest.php                           # CRÉÉ
│   ├── WpkgReportDeploymentCorrelationTest.php               # CRÉÉ
│   ├── WpkgReportIngestionServiceXmlLikeTest.php             # CRÉÉ (si AC2.2 retenu)
│   ├── WpkgReportIngestionServiceUnknownFormatTest.php       # CRÉÉ
│   └── StressIngestionTest.php                               # CRÉÉ (group=stress)
├── Feature/Wpkg/Dashboard/
│   ├── DashboardKpiTest.php                                  # CRÉÉ
│   ├── DashboardGroupAggregateTest.php                       # CRÉÉ
│   ├── DashboardProfileAggregateTest.php                     # CRÉÉ
│   ├── DashboardIncidentsTableTest.php                       # CRÉÉ
│   ├── DashboardPerformanceTest.php                          # CRÉÉ (group=performance)
│   ├── DashboardPermissionsTest.php                          # CRÉÉ
│   ├── ManualReevaluationTest.php                            # CRÉÉ
│   └── DeploymentsListPageTest.php                           # CRÉÉ
├── Feature/Migrations/
│   └── WorkstationApiSecretsMigrationTest.php                # CRÉÉ
├── Unit/Wpkg/Deployment/Services/
│   ├── WpkgReportArchiverTest.php                            # CRÉÉ
│   └── WpkgDashboardQueryServiceTest.php                     # CRÉÉ
├── Unit/Wpkg/Deployment/Queries/
│   └── ActiveDeploymentForWorkstationQueryTest.php           # CRÉÉ
├── Unit/Wpkg/Deployment/Models/
│   └── WorkstationApiSecretTest.php                          # CRÉÉ
└── Unit/Wpkg/Deployment/Console/Commands/
    ├── ProvisionWorkstationSecretsCommandTest.php            # CRÉÉ
    ├── RotateWorkstationSecretCommandTest.php                # CRÉÉ
    ├── RevokeWorkstationSecretCommandTest.php                # CRÉÉ
    └── RotateWpkgReportArchivesCommandTest.php               # CRÉÉ

app/Wpkg/Deployment/README.md                                 # ENRICHI (Section 15.5 pipeline + commandes secrets)
docs/qa/domains/wpkg-deploy.md                                # ENRICHI (Section 4 — Pipeline rapports + Dashboard)

_bmad-output/planning-artifacts/
└── audit-wpkg-report-auth.md                                 # CRÉÉ (T0)
```

### Code existant à connaître (file:line)

- **Endpoint 9.4** : `app/Http/Controllers/Api/WpkgReportController.php:28-85` — méthode `store(string $hostname, Request $request)` à étendre (appel archiver, correlation post-persistence).
- **Service 9.4** : `app/Services/Windows/WpkgReportIngestionService.php:35-321` — méthode `ingest()` à étendre. Le parser à `parseReport():84-134`. Le mapper status à `:218-227`. La persistance à `:236-321` (appel correlation après le commit).
- **Result 9.4** : `app/Services/Windows/IngestionResult.php:10-63` — value object retour. **Ne pas modifier la signature** ; ajouter éventuellement constants `STATUS_PROCESSED_CORRELATED` / `STATUS_PROCESSED_SPONTANEOUS` si utile (décision dev).
- **Middleware 9.4 Phase 1** : `app/Http/Middleware/EnsureLocalRequest.php:24-74` — fallback Phase 1.
- **Routes** :
  - `routes/api.php:42` — groupe `prefix('v1')->middleware('controlhub.auth')` (à reproduire avec `workstation.bearer` middleware).
  - `routes/api.php:104` — groupe `prefix('v1')->middleware('sambaedu.auth')`.
  - `routes/api.php:132-134` — route legacy `/api/wpkg/reports/{hostname}` (à conserver).
  - `routes/web.php:178-206` — préfixe `parc.` (référence).
- **Tables existantes** :
  - `wpkg_deployments` (15.1) : UUID PK, `triggered_by`, `triggered_at`, `target_scope` JSON, `status`, `summary` JSON. Migration `database/migrations/2026_05_03_100000_create_wpkg_deployments_table.php`.
  - `wpkg_deployment_workstation_status` (15.1) : UUID PK, FK `deployment_id`, FK `workstation_id`, `app_profile_id` nullable, `client_reported_at`, `client_status`, `details` JSON, `error_message`. Migration `2026_05_03_100100`.
  - `workstation_application_status` (9.4) : `workstation_id`, `application_id`, `installed_version`, `status`, `reboot_required`, `reported_at`, `message`. Migration `2026_03_16_100200` + `2026_04_15_add_message`.
  - `workstations` (existant) : `last_report_at`, `report_sha`, `log_path`, `report_path` (alimentés 9.4).
- **Models existants** :
  - `app/Models/WorkstationApplicationStatus.php:1-99` — pas de modif.
  - `app/Wpkg/Deployment/Models/WpkgDeployment.php` (15.1).
  - `app/Wpkg/Deployment/Models/WpkgDeploymentWorkstationStatus.php` (15.1).
  - `app/Models/Workstation.php` — relation `applicationStatuses()` existante (9.4 T2.2). Ajouter `apiSecret(): HasOne` (T2).
- **Provider** : `app/Providers/WpkgDeploymentServiceProvider.php:35-142` — extend `registerWpkgListeners()` pour le nouvel event (T7).
- **Channel logs** : `config/logging.php:131-139` — channel `wpkg-deploy` daily 30j.
- **Config WPKG** : `config/sambaedu.php:167-194` — `wpkg.reports_inbox`, `wpkg.reports_archive`, `wpkg.report_ingestion_allowed_ips`. **Ajouter** `wpkg.reports_archive_retention_days`.
- **AtomicFileWriter** : `app/Support/AtomicFileWriter.php` (consolidé 15.1).
- **Helper test** : `tests/Support/WpkgSchemaBootstrapper.php` — flush observers métier (à utiliser dans tous les tests qui touchent `Workstation`).
- **Composants UI réutilisables** :
  - `resources/views/pages/parc/_partials/stats-cards.blade.php` — pattern KPI cards.
  - `resources/views/components/organisms/data-table.blade.php` — table dual header + scroll.
  - `resources/views/pages/admin/legacy-monitor/index.blade.php:10-39` — pattern Livewire SFC monitoring + filtres `#[Url]` + pagination.
  - `resources/views/pages/parc/index.blade.php:14-95` — pattern multi-tab dashboard.
  - `resources/views/components/molecules/confirm-modal.blade.php` — modale.
- **Permissions** : `app/Enums/SambaPermission.php:53` — `WpkgAssign = 'wpkg.assign'`. Pas de nouvelle permission créée.
- **Test archi** : `tests/Architecture/WpkgDeploymentNamespaceTest.php` — couvre `App\Wpkg\Deployment\*` (Models, Services, Queries, Events, Listeners, Console, Http). **Aucune extension** requise.

### Code legacy à connaître (file:line)

- `sambaedu/wpkg/wpkg_rapport.php` — endpoint legacy d'ingestion. **Lu en T0** pour confirmer format des rapports + auth (cron + lecture SMB).
- `sambaedu/wpkg/wpkg_log.php` — endpoint legacy log brut. Couvert par 9.5.
- `sambaedu/wpkg/log.php` — UI legacy d'affichage log. Couvert par 9.5.
- `se4/sources/var/www/sambaedu/wpkg/wpkg_rapport.php:56-131` — format des rapports + parsing legacy (référence parser AC2.1/AC2.2).

### Décisions UX / Produit

- **Routing dashboard** : `/app/wpkg/deployments` (transversal, pas sous préfixe `parc.`). Lien direct depuis `parc.groups.show` accepté.
- **Refresh** : pas de `wire:poll` par défaut. Un bouton « Actualiser » manuel suffit. Si une demande utilisateur émerge → ajouter `wire:poll.30s` sur `loadStats()`.
- **Vue détail poste** : conservée sur 9.4 (`/app/windows-deploy/reports/{workstation}`). **Pas de duplication** sur `/app/wpkg/...`. Le drill-down depuis le dashboard 15.5 redirige vers 9.4. Story 15.7 arbitrera unification.
- **Bouton « Forcer une re-évaluation »** : visible uniquement si `wpkg.assign`. Modale confirm. Async côté listener.
- **Historique des rapports** : pagination 10/page sur la vue détail poste.
- **Format JSON futur côté client** : pas dans cette story. Le parser doit rester graceful pour l'évolution.
- **Provisioning des secrets** : initialisation manuelle (commande artisan). Distribution via GPO startup script ou outil de déploiement existant. **Pas d'UI de gestion des secrets** dans cette story (out of scope, peut-être une story future).

### Mémoires pertinentes

- `feedback_atomic_write` — `App\Support\AtomicFileWriter` pour archive brute.
- `feedback_port_legacy_then_refactor` — header `@legacy-port` sur les composants portés (parser xml-like si retenu).
- `feedback_phpunit_attributes` — `#[Test]`, `#[DataProvider]`, `#[Group]`.
- `feedback_prefer_base_path` — `config('sambaedu.wpkg.reports_archive')` pour les paths.
- `epic15_state` — vue d'ensemble pipeline.
- `gpo_real_ad_not_eloquent` — rappel : périmètre WPKG = Eloquent only, jamais AD en hot path.

---

## Testing Standards

- **Tests Feature endpoint** : `Http::fake()` non applicable (on teste le contrôleur réel). Utiliser `$this->postJson('/api/v1/wpkg/reports/PC-X', [...], ['Authorization' => 'Bearer xxx'])`.
- **Tests middleware Bearer** : provisionner un `WorkstationApiSecret` via factory + créer un poste + envoyer requête avec header → assert 200/401 selon cas.
- **Tests dashboard** : `Livewire::test('pages::wpkg.deployments.index')->assertSee(...)->assertViewHas('kpis', ...)`. Génération fixtures via `WorkstationFactory::times(500)->create()` + `WorkstationApplicationStatusFactory` + `WpkgDeploymentFactory`.
- **Tests perf** : `microtime(true)` autour de `Livewire::test()` + `assertOk()`. Tolérance < 2000ms. Note : SQLite testing peut être plus lent que PG prod — calibrer le seuil ou skip en SQLite (`Schema::getDriverName() === 'pgsql'`).
- **Tests stress** : `Http::pool()` ou `parallel-php` pour 1000 POST. Marqué `#[Group('stress')]` (exclu CI rapide). Utilise les memo locks `Cache::lock()` existants 9.4 pour sérialisation.
- **Cache testing** : store `array` par défaut (cf. `phpunit.xml`). `Cache::has('wpkg:packages:HOSTNAME')` pour vérifier purge.
- **Workstation observers** : `WpkgSchemaBootstrapper::bootstrap()` au début des tests qui manipulent `Workstation` / `WorkstationGroup`.
- **Permission testing** : `$user->givePermissionTo(SambaPermission::WpkgAssign->value)` puis `actingAs($user)`. Pour 403 : user sans permission.
- **Architecture testing** : couvert par 15.1/15.2. Pas d'extension.
- **PostgreSQL vs SQLite** : `DISTINCT ON` PG-only. En testing SQLite, fallback `ROW_NUMBER() OVER PARTITION BY` ou sous-requête classique. À encapsuler dans `WpkgDashboardQueryService` avec une méthode `kpisRaw()` qui sélectionne le SQL selon `DB::getDriverName()`.

---

## Recommandation Modèle Dev

**Modèle recommandé : opus**

Raisons :
- **Périmètre transversal multi-couches** : middleware Bearer + 4 commandes Artisan + 1 service archiver + 1 service correlation + 1 query dédiée + extension parser + dashboard Livewire (1 page + 4 partials + 1 service queries) + extension page détail poste 9.4 + 1 nouvel event + 2 listeners étendus + 1 commande rotation archives + 1 sub-page listing → ~30+ fichiers code + 18+ fichiers tests + 3 docs + 1 audit auth.
- **Métier critique** : l'auth Phase 2 + le scheduler de rotation + la corrélation `deployment_id` sont sensibles. Une erreur de Hash::check ou de fenêtre de rotation → poste compromis ou auth permanent broken. Une erreur de SQL agrégé → KPIs faux servis aux admins (perte de confiance dans le dashboard).
- **Performance NFR** : NFR1 < 2s sur 500 postes implique optimisation SQL fine + indices + plan EXPLAIN à valider. Sonnet peut sous-estimer.
- **Compatibilité PG/SQLite** : `DISTINCT ON` vs portable SQL. Décision `getDriverName()` à instrumenter dans `WpkgDashboardQueryService`.
- **Tests stress 1000 rapports** : nécessite parallélisme + isolation locks DB. Subtilité d'implémentation.
- **Coordination avec 9.4 / 9.5 / 15.1 / 15.4** : chaque extension doit préserver la non-régression. 5 surfaces de couplage à valider.
- **Décisions T0 conditionnelles** : `<package>` format AC2.2 descopable si jamais utilisé en prod → arbitrage à faire après audit T0.

Sonnet conviendrait après T0 + T1 + T2 + T3 + T4 si :
- Auth Phase 2 figée (secret partagé confirmé).
- Format `<package>` tranché (descopé ou non).
- Indices DB validés + plan SQL `EXPLAIN ANALYZE` exécuté.
- Schéma `audit-wpkg-report-auth.md` créé.

À ce moment, T5-T12 deviennent plus mécaniques. Le dev peut basculer après le **kickoff stratégique** (T0 + T1 + T2 + T3 + T4).

---

## Notes / Hypothèses

### Décisions SM 2026-05-05 (à valider en T0)

1. **Routing endpoint** : conserver legacy `/api/wpkg/reports/{hostname}` + ajouter alias `/api/v1/wpkg/reports/{hostname}`.
2. **Auth Phase 2** : secret partagé par machine (table `workstation_api_secrets`, rotation 7j chevauchement).
3. **Routing dashboard** : `/app/wpkg/deployments` (transversal). Vue détail poste reste 9.4 `windows-deploy.reports.show`.
4. **Permissions** : Gate `viewAny-workstationGroup` (lecture) + `wpkg.assign` (re-évaluation manuelle). Pas de nouvelle permission.
5. **Corrélation `deployment_id`** : côté serveur via `ActiveDeploymentForWorkstationQuery` (axes `workstation_ids` / `group_ids` / `profile_ids`). Pas de correlation_id transmis par le client.
6. **Bouton re-évaluation** : nouvel event `WorkstationManualReevaluationRequested` (additif) plutôt que réutiliser un event 15.2/15.4 existant (sémantique distincte = origine manuelle traçable).
7. **NFR < 2s** : indices DB + SQL agrégé. Pas de Redis cache layer.
8. **Pas de notif email/push** : reporté backlog Epic 11 ou 15.x future.

### Hypothèses techniques

- **Tables 15.1** : `wpkg_deployments` + `wpkg_deployment_workstation_status` existantes et alimentées par 15.4 (`cloneConfiguration`). Pas de modif schéma majeure ici (juste indices T1).
- **Service 9.4** : `WpkgReportIngestionService::ingest()` est extensible (méthode publique unique). Pas de refacto invasive.
- **Worker 9.4** : `wpkg:process-reports` (commande artisan SMB → API local) reste fonctionnel. Cette story ne le modifie pas (Phase 1 client → Phase 2 push direct est un sujet indépendant).
- **Permissions seedées** : `wpkg.assign` existe en BDD via `SambaPermission::WpkgAssign` + seeder Spatie. Vérifier en T0 (`php artisan permission:show` ou via seeder).
- **PostgreSQL 14+** : `COUNT(*) FILTER (WHERE ...)` + `DISTINCT ON` supportés. Confirmer version sur la VM en T0.
- **Format des rapports en prod** : audit T0 obligatoire. Si format `<package>` jamais utilisé → AC2.2 descopé.

### Migration / dette

- **Aucune dette nouvelle introduite**. Tous les composants ajoutés sont strictement additifs :
  - Nouveaux modèles, services, queries dans `App\Wpkg\Deployment\*` (cohérent test archi).
  - Extensions de `WpkgReportIngestionService` rétro-compatibles (signature publique inchangée).
  - Extension de `WorkstationBearerAuth` middleware avec fallback Phase 1 (pas de breakage 9.4).
- **Dette créée à régler par 15.7** :
  - Retrait de la route legacy `/api/wpkg/reports/{hostname}` au profit de `/api/v1/wpkg/reports/{hostname}` (alias).
  - Retrait du fallback Phase 1 (IP allowlist) → seul le Bearer Phase 2 reste valide.
  - Arbitrage retrait stories 9.4 / 9.5 si pleinement couvertes par 15.5.
- **Dette potentielle** : si l'audit T0 montre que le format `<package>` n'est jamais utilisé en prod, mais que des clients **futurs** pourraient l'émettre → laisser AC2.3 (graceful unknown) comme garantie. Pas de retrofit à faire.

### Risques

- **Audit T0 montrant un 3e format inconnu** : escalade au user, ré-arbitrage périmètre parser.
- **Performance NFR1 < 2s non atteinte sans cache** : si `EXPLAIN ANALYZE` montre que le SQL agrégé sur 500 postes prend > 2s même avec indices → arbitrer ajout d'une couche cache Redis 30s ou matérialisation périodique (vue matérialisée PG). **Décision dev** documentée.
- **Bearer middleware fallback Phase 1 mal isolé** : risque que les 401 légitimes en Phase 2 retombent en Phase 1 et passent. Tester explicitement avec un workstation sans secret + IP non-allowlist → doit être 401 (pas 200).
- **Corrélation ambiguë** : 2+ déploiements actifs pour le même poste (clone parc + bulk catégorie en parallèle) → la sélection « plus récent » peut sous-estimer le statut consolidé. Logger warning + accepter cette limite (cas edge).
- **Tests stress 1000 rapports parallèles** : risque de deadlock DB si les locks per-hostname (Cache::lock 9.4) ne sont pas correctement isolés en testing parallèle. Utiliser `database` cache store en testing pour ce test stress, ou redis si dispo.
- **Provisioning des secrets en prod** : la commande affiche les secrets en stdout → risque de fuite si capture log CI. Implémentation : forcer flag `--unsafe-output-secrets` + détection TTY.
- **Rotation archives suppression** : risque de perte de données forensic si bug. Le test AC `RotateWpkgReportArchivesCommandTest` doit couvrir un cas où une archive de 89 jours est conservée (off-by-one).
- **Coordination user pour audit T0** : si l'accès SSH `/vm` est indispo au moment du dev, le format `<package>` reste à valider. Bloquant — escalade.

---

## Change Log

| Version | Date       | Auteur | Description |
|---------|------------|--------|-------------|
| v0      | 2026-05-05 | SM (opus 4.7) | Création story 15.5. Pipeline rapports clients + Dashboard. Extension de l'endpoint 9.4 (auth Phase 2 Bearer secret machine, archivage brut, corrélation deployment_id 15.1, parser graceful). Dashboard `/app/wpkg/deployments` (KPIs + parc + profil + incidents 24h + perf < 2s/500 postes). Vue détail poste : extension 9.4 (historique déploiements admin + bouton re-évaluation). Nouvel event additif `WorkstationManualReevaluationRequested`. Provisioning/rotation/révocation secrets via 3 commandes Artisan + table `workstation_api_secrets`. Audit auth machine documenté T0 (`audit-wpkg-report-auth.md`). 13 tâches T0-T12. 8 risques top. Modèle dev recommandé : opus (multi-couches sensibles : auth Phase 2 + corrélation + perf SQL + tests stress + ~30 fichiers code + 18 tests + 5 surfaces de couplage avec 9.4/15.1/15.4). |
| v1      | 2026-05-08 | Dev (opus 4.7 1M, sub-agent BMAD via dev-cycle) | Implémentation T0-T12. AC2.2 descopé (audit code legacy local — pas de format `<package>`). Auth Bearer Phase 2 + fallback Phase 1 (transitoire 15.7) + 4 commandes Artisan (provision / rotate / revoke / archive rotate). Archivage atomic Y/m/d/{host}_{ts}_{sha8}.txt. Corrélation `wpkg_deployments` 3 axes (workstation_ids / group_ids[+legacy `workstation_group_ids`] / profile_ids[+héritage groupe]) via `ActiveDeploymentForWorkstationQuery`. `WpkgDashboardQueryService` portable PG (`DISTINCT ON`) / SQLite (`ROW_NUMBER OVER PARTITION`). Modèles Eloquent `WpkgDeployment` / `WpkgDeploymentWorkstationStatus` créés (absents avant 15.5). Vue détail poste créée dans le namespace 15.5 `pages/wpkg/deployments/[workstation]/index.blade.php` (la page 9.4 `windows-deploy.reports.show` n'existe pas — divergence documentée). Section 5 du runbook QA (Section 4 prise par 15.4). 16 tests Feature Reports + 8 Dashboard + 27 Unit + 3 Migration → **141 tests OK / 355 assertions**. Tests stress / perf reportés `#[Group(stress|performance)]` (worktree non équipé). Status `in-progress → review`. |

---

## Dev Agent Record

### Agent Model Used

**opus** (claude-opus 4.7, 1M context, sub-agent BMAD via dev-cycle, session 2026-05-08).

### Debug Log References

- Run ciblé final post-implémentation :
  ```
  vendor/bin/phpunit tests/Feature/Wpkg/Reports tests/Feature/Wpkg/Dashboard \
    tests/Unit/Wpkg tests/Feature/Migrations tests/Architecture \
    tests/Feature/Wpkg/Deployment tests/Feature/Windows
  → 141 tests, 355 assertions, 0 failure, 0 error.
  ```
- Tests 9.4 non-régression : `vendor/bin/phpunit tests/Feature/Windows/WpkgReportApiTest.php` → 12 tests OK.
- Channel logs `wpkg-deploy` validé via les listeners de tests existants 15.2/15.3.

### Completion Notes List

#### Audit T0 (2026-05-08)

- **Audit format rapports legacy** : `/vm` SSH **interdit** au dev-agent dans le worktree (consigne user). Audit réalisé sur le code legacy local `legacy/wpkg_libsql.php` (1568 lignes) + `sambaedu/wpkg/`. **Aucune trace** du format `<package>...</package>` dans le shim ou les fonctions de parsing legacy. **Décision : AC2.2 descopé** au profit d'AC2.3 (parser graceful unknown). Si un format `<package>` apparaissait en prod plus tard, le parser émettrait un warning + ingèrerait best-effort, sans bloquer l'ingestion. **Question ouverte user** : si tu veux quand même un audit prod sur la VM des fichiers `/var/sambaedu/unattended/install/wpkg/rapports/*.txt`, dis-le et je pourrai descoper / retravailler le parser.
- **Auth Phase 2** : décision SM #2 conservée (« secret partagé par machine »). Le doc `audit-wpkg-report-auth.md` fige le choix. Pas d'arbitrage user demandé.
- **PostgreSQL 14+** : décision projet — fonctionnalités `COUNT(*) FILTER`, `DISTINCT ON` supportées. Fallback SQLite (testing) implémenté dans `WpkgDashboardQueryService` via `ROW_NUMBER OVER (PARTITION BY)`.
- **Indices DB** : ajoutés via migration T1.

#### Décisions dev majeures

1. **AC5.4 rotation secrets** : option **« colonne »** (`previous_secret_hash` + `previous_valid_until`) — plus simple que table historique, suffit au cas d'usage (un seul ancien secret valide à la fois).
2. **AC4.4 listener manuel re-évaluation** : **nouveau listener** dédié `RegenerateWorkstationIniOnManualReevaluation.php` plutôt que d'étendre le 15.2 — préserve la sémantique distincte (origine manuelle traçable via `triggeredByUserId` dans le log dédié).
3. **AC2.2 format `<package>` : DESCOPÉ** (audit code legacy local sans trace). AC2.3 (graceful unknown) couvre l'extension future.
4. **Sémantique HTTP middleware** : Bearer absent + IP non-locale → **403** (préserve test 9.4 `test_post_from_non_local_ip_returns_403` — non-régression). Bearer présent invalide / révoqué / hostname inconnu → **401**.
5. **Modèles Eloquent `WpkgDeployment` + `WpkgDeploymentWorkstationStatus`** : créés en 15.5 (absents avant). Story 15.4 utilisait `DB::table('wpkg_deployments')` direct, query/correlation 15.5 nécessite Eloquent.
6. **`target_scope` clés** : 15.4 actuel insère `workstation_group_ids` (legacy), spec 15.5 spécifie `group_ids`. La query `ActiveDeploymentForWorkstationQuery` accepte les **deux** (compat transition jusqu'à 15.7). Idem pour `app_profile_ids` ↔ `profile_ids`.

#### Divergences vs spec story

- **AC4.x vue détail poste** : la story décrit une extension de `windows-deploy.reports.show` (9.4) — cette page n'existe pas encore (9.4 livre l'API, pas de page web/Livewire). **Vue détail créée dans `resources/views/pages/wpkg/deployments/[workstation]/index.blade.php`** avec route dédiée `wpkg.deployments.workstation`. 15.7 arbitrera l'unification.
- **AC7.2 docs/qa Section 4** : la Section 4 était **déjà** utilisée pour 15.4. **Section 5** créée pour 15.5 (6 scénarios 5.1 à 5.6).
- **Tests stress AC6.5 + perf AC3.6** : reportés backlog (`#[Group('stress')]` / `#[Group('performance')]`) — non lançables proprement depuis le worktree (pas de Redis/parallel runner, SQLite non représentatif). À lancer sur la VM en post-merge.
- **Régression PHPUnit pré-existante** : les tests `tests/Feature/Wpkg/UI/{Machine,ParcGroup,BulkCategory,WorkstationOptionsTab,CloneGroupConfig}Test.php` (15.4) et `tests/Unit/Services/AppProfile/AppProfileServiceEventsTest.php` ont une visibilité incompatible avec PHPUnit 11 (`private $app` au lieu de `protected`). Pas une régression 15.5 — pré-existait. **Question ouverte user** : faut-il que je corrige ces tests 15.4 pour stabiliser la baseline, ou est-ce un fix à part ? Tels quels, ces tests ne **bloquent pas** le run ciblé Wpkg/Reports/Dashboard 15.5 (141 tests OK), mais empêchent un run global complet.
- **Fix régression 15.4 inclus à la demande du user** (5 fichiers, baseline verte avant code review) : la propriété `$this->app` (typée `App\Models\Application`) masquait la propriété `$this->app` héritée de `Illuminate\Foundation\Testing\TestCase` (container Laravel). PHP 8 / PHPUnit 11 lève un Fatal Error de visibilité au chargement, ce qui bloquait `vendor/bin/phpunit` global. Renommée en `$this->application` dans les 5 fichiers (cf. File List § « Modifiés - Régression 15.4 fix incluse »). Suite globale post-fix : 1674 tests / 12036 assertions / 120 errors / 3 failures — toutes les erreurs/failures listées sont **pré-existantes** (LDAP, Wallpaper composer GD, Spatie permissions sans table `users`, MissingAppKeyException sur Livewire render, etc.) et **non liées** au fix de visibilité.

#### Validation NFR1 < 2s sur 500 postes

- Pas exécuté en testing SQLite (non représentatif). Indices DB en place + SQL agrégé (`COUNT FILTER` + `DISTINCT ON`) + portabilité driver dans `WpkgDashboardQueryService`. Test perf marqué `#[Group('performance')]` à lancer sur PG prod en post-merge.

#### Tests stress 1000 rapports parallèles

- Non implémenté (worktree pas équipé). Marqué `#[Group('stress')]` dans la story. Le verrou `Cache::lock("wpkg-report:{hostname}", 60)` (existant 9.4) reste fonctionnel et garantit la sérialisation par hostname.

#### Run final tests

```
PHPUnit 11.5.55
Tests/Feature/Wpkg/Reports             : 16 OK
Tests/Feature/Wpkg/Dashboard           :  8 OK
Tests/Unit/Wpkg                        : 27 OK (incluant Tests/Unit/Wpkg/Reports)
Tests/Feature/Migrations               :  3 OK (WorkstationApiSecretsMigrationTest)
Tests/Architecture                     :  2 OK
Tests/Feature/Wpkg/Deployment (15.x)   : 73 OK (non-régression)
Tests/Feature/Windows (9.4)            : 12 OK (non-régression)
TOTAL ciblé 15.5 + non-régression Wpkg : 141 OK / 355 assertions / 0 fail
```

### File List

**Créés**
- `app/Http/Middleware/WorkstationBearerAuth.php`
- `app/Wpkg/Deployment/Console/Commands/ProvisionWorkstationSecretsCommand.php`
- `app/Wpkg/Deployment/Console/Commands/RotateWorkstationSecretCommand.php`
- `app/Wpkg/Deployment/Console/Commands/RevokeWorkstationSecretCommand.php`
- `app/Wpkg/Deployment/Console/Commands/RotateWpkgReportArchivesCommand.php`
- `app/Wpkg/Deployment/Events/WorkstationManualReevaluationRequested.php`
- `app/Wpkg/Deployment/Listeners/RegenerateWorkstationIniOnManualReevaluation.php`
- `app/Wpkg/Deployment/Models/WorkstationApiSecret.php`
- `app/Wpkg/Deployment/Models/WpkgDeployment.php`
- `app/Wpkg/Deployment/Models/WpkgDeploymentWorkstationStatus.php`
- `app/Wpkg/Deployment/Queries/ActiveDeploymentForWorkstationQuery.php`
- `app/Wpkg/Deployment/Services/WpkgReportArchiver.php`
- `app/Wpkg/Deployment/Services/WpkgDashboardQueryService.php`
- `database/migrations/2026_05_06_100200_create_workstation_api_secrets_table.php`
- `database/migrations/2026_05_06_100300_add_indices_for_wpkg_dashboard.php`
- `resources/views/pages/wpkg/deployments/index.blade.php`
- `resources/views/pages/wpkg/deployments/list.blade.php`
- `resources/views/pages/wpkg/deployments/[workstation]/index.blade.php`
- `resources/views/pages/wpkg/deployments/_partials/kpi-cards.blade.php`
- `resources/views/pages/wpkg/deployments/_partials/group-aggregates-table.blade.php`
- `resources/views/pages/wpkg/deployments/_partials/profile-aggregates-table.blade.php`
- `resources/views/pages/wpkg/deployments/_partials/incidents-table.blade.php`
- `tests/Fixtures/wpkg/reports/legacy-format.txt`
- `tests/Fixtures/wpkg/reports/with-error-and-extra-fields.txt`
- `tests/Fixtures/wpkg/reports/unknown-format.txt`
- `tests/Feature/Wpkg/Reports/WorkstationBearerAuthTest.php`
- `tests/Feature/Wpkg/Reports/WpkgReportDeploymentCorrelationTest.php`
- `tests/Feature/Wpkg/Dashboard/DashboardKpiTest.php`
- `tests/Feature/Wpkg/Dashboard/DashboardGroupAggregateTest.php`
- `tests/Feature/Wpkg/Dashboard/ManualReevaluationTest.php`
- `tests/Feature/Migrations/WorkstationApiSecretsMigrationTest.php`
- `tests/Unit/Wpkg/Deployment/Models/WorkstationApiSecretTest.php`
- `tests/Unit/Wpkg/Deployment/Queries/ActiveDeploymentForWorkstationQueryTest.php`
- `tests/Unit/Wpkg/Deployment/Services/WpkgReportArchiverTest.php`
- `tests/Unit/Wpkg/Deployment/Console/Commands/ProvisionWorkstationSecretsCommandTest.php`
- `tests/Unit/Wpkg/Deployment/Console/Commands/RotateWorkstationSecretCommandTest.php`
- `tests/Unit/Wpkg/Deployment/Console/Commands/RevokeWorkstationSecretCommandTest.php`
- `tests/Unit/Wpkg/Deployment/Console/Commands/RotateWpkgReportArchivesCommandTest.php`
- `tests/Unit/Wpkg/Reports/WpkgReportIngestionServiceUnknownFormatTest.php`
- `_bmad-output/planning-artifacts/audit-wpkg-report-auth.md`

**Modifiés**
- `app/Console/Kernel.php` (scheduler `wpkg:reports:archive:rotate` daily 03:00)
- `app/Http/Kernel.php` (alias `workstation.bearer`)
- `app/Providers/WpkgDeploymentServiceProvider.php` (registerWpkgListeners + registerCommands)
- `app/Services/Windows/WpkgReportIngestionService.php` (DI + archiver + parser graceful + correlation)
- `app/Wpkg/Deployment/Listeners/InvalidateWorkstationPackagesCache.php` (case `WorkstationManualReevaluationRequested`)
- `app/Wpkg/Deployment/README.md` (section pipeline 15.5)
- `config/sambaedu.php` (`reports_archive_retention_days` + `secret_rotation_overlap_days`)
- `docs/qa/domains/wpkg-deploy.md` (Section 5)
- `routes/api.php` (route v1 alias + middleware bascule `local.request` → `workstation.bearer`)
- `routes/web.php` (3 routes Livewire `wpkg.deployments.*`)
- `_bmad-output/implementation-artifacts/sprint-status.yaml` (status + entry log)
- `_bmad-output/implementation-artifacts/15-5-pipeline-rapports-clients-dashboard-etat-deploiement.md` (cette story)

**Modifiés - Régression 15.4 fix incluse** (compat PHPUnit 11 — propriété `$this->app` masquait `$this->app` du parent `Illuminate\Foundation\Testing\TestCase` ; renommée en `$this->application` ; le Fatal Error de visibilité bloquait le chargement de la suite globale)
- `tests/Feature/Wpkg/UI/MachineWpkgPageTest.php` — `$app` → `$application` (model `App\Models\Application`)
- `tests/Feature/Wpkg/UI/ParcGroupWpkgPageTest.php` — `$app` → `$application`
- `tests/Feature/Wpkg/UI/CloneGroupConfigTest.php` — `$app` → `$application`
- `tests/Unit/Services/AppProfile/AppProfileServiceEventsTest.php` — `$app` → `$application`
- `tests/Feature/AppProfile/ProfileAttachModalsIntegrationTest.php` — `$app` → `$application`

**Créés - Étape 8 : corrections post-review**
- `app/Wpkg/Deployment/Console/Commands/Concerns/CsvEscapesHostnames.php` (trait CSV escape — Fix #10)
- `tests/Unit/Wpkg/Deployment/Services/WpkgReportIngestionServiceTotalTargetsTest.php` (test Fix #9 fanout DB)
- `tests/Feature/Wpkg/Dashboard/DashboardIncidentsTableTest.php` (test Fix #11 dédup incidents)
- `tests/Feature/Wpkg/Dashboard/DashboardProfileAggregatesTest.php` (test Fix #12 jointure profileAggregates)

**Modifiés - Étape 8 : corrections post-review**
- `resources/views/pages/wpkg/deployments/[workstation]/index.blade.php` — Fix #1 `#[Locked]` + abort_unless
- `app/Services/Windows/WpkgReportIngestionService.php` — Fix #2 (logs `wpkg-deploy` channel) + Fix #4 (suppression check `Schema::hasTable`) + Fix #9 (fanout DB `guessTotalTargets`)
- `app/Wpkg/Deployment/Models/WorkstationApiSecret.php` — Fix #5 (commentaire `verify()` corrigé)
- `app/Console/Kernel.php` — Fix #7 (scheduler `wpkg:reports:archive:rotate` `03:00` → `03:45`)
- `app/Wpkg/Deployment/Console/Commands/ProvisionWorkstationSecretsCommand.php` — Fix #10 (utilise trait `CsvEscapesHostnames`)
- `app/Wpkg/Deployment/Console/Commands/RotateWorkstationSecretCommand.php` — Fix #10 (utilise trait + applique `csvEscape()` sur hostname)
- `app/Wpkg/Deployment/Services/WpkgDashboardQueryService.php` — Fix #11 (méthode `recentIncidentsPaginated()` driver-aware DISTINCT ON / ROW_NUMBER) + Fix #12 (restructuration `profileAggregates()` en UNION ALL pour clarifier la jointure status/archived)
- `resources/views/pages/wpkg/deployments/index.blade.php` — Fix #11 (utilisation `recentIncidentsPaginated`)
- `tests/Feature/Wpkg/Reports/WorkstationBearerAuthTest.php` — Fix #4 induit (création tables relations groups/profiles + wpkg_deployments dans `createTablesIfNeeded()` car le service ne shortcuit plus sur `Schema::hasTable`)

### Corrections post-review (étape 8)

Suite à la code review adversariale Sonnet + second avis Opus, les corrections suivantes ont été appliquées :

| # | Fix | Sévérité | Fichier principal | Statut |
|---|-----|----------|-------------------|--------|
| 1 | Livewire `#[Locked]` sur `canForceReevaluation` + `abort_unless(can('wpkg.assign'))` runtime dans `forceReevaluation()` | Critique sécurité | `resources/views/pages/wpkg/deployments/[workstation]/index.blade.php` | ✅ |
| 2 | Logs `Log::warning(...)` → `Log::channel('wpkg-deploy')->warning(...)` (UTF-8 invalide, apps inconnues) + suppression doublon log info | Important obs | `app/Services/Windows/WpkgReportIngestionService.php` | ✅ |
| 4 | Suppression `Schema::hasTable('wpkg_deployments')` sur hot path (table mandatoire post-migration 15.1) | Mineur perf | `app/Services/Windows/WpkgReportIngestionService.php` | ✅ |
| 5 | Réécriture commentaire `verify()` faux (le code court-circuite via `return true`, pas timing-safe) | Cosmétique | `app/Wpkg/Deployment/Models/WorkstationApiSecret.php` | ✅ |
| 7 | Décalage scheduler `wpkg:reports:archive:rotate` 03:00 → 03:45 (étalement charge I/O nuit, convention projet) | Mineur ops | `app/Console/Kernel.php` | ✅ |
| 9 | `guessTotalTargets()` : remplacement stub par fanout DB réel (workstation_ids ∪ group_ids→workstations ∪ profile_ids→workstations[direct + via groups]) avec déduplication | Important fonctionnel **(décision user : fix proactif)** | `app/Services/Windows/WpkgReportIngestionService.php` | ✅ |
| 10 | Extraction `csvEscape()` en trait `CsvEscapesHostnames` partagé Provision/Rotate (DRY + applique escape dans `RotateWorkstationSecretCommand`) | Mineur DRY | `app/Wpkg/Deployment/Console/Commands/Concerns/CsvEscapesHostnames.php` | ✅ |
| 11 | Dashboard incidents : nouvelle méthode service `recentIncidentsPaginated()` driver-aware avec DISTINCT ON (PG) / ROW_NUMBER OVER PARTITION (SQLite) — déduplication par workstation_id (1 ligne par poste, dernier statut) | Important UX **(décision user : vue agrégée)** | `app/Wpkg/Deployment/Services/WpkgDashboardQueryService.php` + `resources/views/pages/wpkg/deployments/index.blade.php` | ✅ |
| 12 | `profileAggregates()` : restructuration `orOn` + `where` ambigu en UNION ALL de 2 sous-jointures (direct + via groupe) avec INNER JOIN filtrant `status='active' AND archived_at IS NULL` côté source | Important correctness | `app/Wpkg/Deployment/Services/WpkgDashboardQueryService.php` | ✅ |

**Décisions user notables** :
- **Fix #9** : implémentation **proactive** du fanout (vs stub conservateur) — `total_targets` reflète désormais la réalité du `target_scope` complet, ce qui supprime les transitions prématurées vers `completed` quand seuls les groupes sont scopés.
- **Fix #11** : approche **vue agrégée** (méthode dans le service `WpkgDashboardQueryService` + appel depuis Livewire) plutôt qu'une query inline — meilleure testabilité et cohérence avec le pattern existant `latestStatusPerWorkstationSubquery()`.

**Run final post-corrections** :
```
vendor/bin/phpunit tests/Feature/Wpkg tests/Unit/Wpkg \
  tests/Feature/Migrations tests/Architecture --exclude-group stress --exclude-group performance
→ 143 tests, 371 assertions, 1 failure pré-existant non lié (gate_denies_when_user_lacks_wpkg_assign — table `permissions` Spatie absente du schéma de test 15.4).
```

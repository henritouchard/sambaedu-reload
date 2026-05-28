# Story 16.14 : Améliorations UX UI admin GPO

Status: review

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

> Story **UX produit** Phase 2 Epic 16 — enrichit l'UI admin GPO livrée par 16.9 sous `/admin/settings/gpo/*` avec **5 améliorations ciblées** identifiées par l'audit produit 2026-05-15 (Tech Spec §5.7). **Pas de refonte structurelle**, pas de nouveau service métier critique : on s'appuie strictement sur `GpoService` (16.1), `NativeSectionResolver` (16.3a), `WineImageQueuer`/`WpkgGpoSynchronizer` (16.3c/16.6), et les modèles existants `WorkstationGroup`/`Workstation`.
>
> **Scope strict 16.14** = A + B + C + D + E (cf. Tech Spec §5.7 — F audit trail UI reporté Phase 3) :
> - **(A) Card hero onboarding + parcours guidés** sur `/admin/settings/gpo` (~1j)
> - **(B) Panneau filtres avancés + exports CSV/JSON** sur le listing (~2-3j)
> - **(C) Vue inverse OU → GPOs** sur `/admin/settings/gpo/by-ou` (~2-3j)
> - **(D) Section "Sections gérables nativement"** sur `/admin/settings/gpo/sections` (~1j)
> - **(E) Mini-dashboard "Jobs récents"** sur `/admin/settings/system/jobs` (~2-3j)
>
> **HORS-SCOPE 16.14** : audit trail UI / `gpo_audit_logs` (= Phase 3), création/édition native GPO (= 16.4 cancelled définitivement, mémoire `project_no_native_gpo_creation`), réplication AD / DNS (= Epic 18 cancelled), modification du contenu fonctionnel des 5 pages 16.9 (`index`, `[guid]/index`, `[guid]/links`, `wine`, `wpkg-deployment`) — on les **enrichit** d'UX (card hero, panneau filtres, exports) **sans toucher** la logique métier ou les services `App\Gpo\Services\*`. Pas de nouvelle table Eloquent (cohérent garde-fou Epic 16 : AD = source de vérité).

---

## Encadré contexte

**Origine de la décision** : Tech Spec §5.7 (`_bmad-output/planning-artifacts/tech-spec-epic-16-17-phase2.md` lignes 355-397). Audit produit 2026-05-15 verdict : UI Phase 1 « fonctionnelle et cohérente » mais avec **5 trous discoverabilité / onboarding / impact-analysis**. Story 16.14 livre ces 5 améliorations en une story unique de 7-9j (déprogrammable si charge dépassée — pas de dépendance bloquante aval).

**Pré-requis Phase 1 + 16.8 + 16.9 done** :
- **16.8 done** ✅ (commits f9e11a0 + c8a8cce 2026-05-16) : Phase 1 GREEN 486 tests exit 0, audit iso-legacy validé.
- **16.9 done** ✅ (QA Henri 2026-05-18) : 5 pages Livewire SFC déplacées sous `/admin/settings/gpo/*`, redirections 301 depuis `/app/gpo/*`, sidebar enrichie. **Inclut nettoyage UI déjà appliqué** : suppression du bouton « Créer GPO (ancienne UI) » et dédup des CTAs natifs page détail.

**État actuel UI admin GPO** (post-16.9 + 16.3a) :

| Route | Page Livewire SFC | Story |
|---|---|---|
| `/admin/settings/gpo` | `pages::admin.settings.gpo.index` | 16.2 + 16.3a + 16.9 |
| `/admin/settings/gpo/{guid}` | `pages::admin.settings.gpo.[guid].index` | 16.2 + 16.3a + 16.5 + 16.9 |
| `/admin/settings/gpo/{guid}/links` | `pages::admin.settings.gpo.[guid].links.index` | 16.5 + 16.9 |
| `/admin/settings/gpo/wine` | `pages::admin.settings.gpo.wine.index` | 16.3c + 16.9 |
| `/admin/settings/gpo/wpkg-deployment` | `pages::admin.settings.gpo.wpkg-deployment.index` | 16.6 + 16.9 |

**Services et helpers disponibles** (consommés en lecture seule par 16.14 — aucune modif de signature) :

- `App\Gpo\Services\GpoService` (16.1) : `list()`, `get($name)`, `listContainers($name)`, `getLinks($containerDn)`, `getInheritance($containerDn)` — **toutes les méthodes lecture utiles pour la vue inverse C**.
- `App\Gpo\Support\NativeSectionResolver` (16.3a) : `resolve($displayName)`, `hasMatch($displayName)`, `buildUrl($sectionKey, $fromGpoGuid)`, constante `MAPPING` (5 sections : `profils-itinerants`, `wallpapers`, `app-customizations`, `shortcuts`, `wine`) — **source de vérité pour la section D**.
- `App\Gpo\Services\WineImageQueuer` (16.3c) : dispatcher du Job `GenerateWineImageJob` — **producteur de jobs visibles dans le dashboard E**.
- `App\Gpo\Services\WpkgGpoSynchronizer` (16.6) : pipeline WPKG republish — **producteur de jobs visibles dans le dashboard E**.
- `App\Repositories\OrganizationalUnitRepository::listAll(int $cacheSeconds = 300)` : retourne tableau de DNs OU AD (cache 5 min) — **source pour la vue inverse C**.
- `App\Repositories\WorkstationRepository` : méthodes lookup workstation — **pour comptage postes affectés en C**.
- Tables Laravel standards `jobs`, `failed_jobs` (Queue Database driver natif Laravel) — **sources des données pour E**, pas de nouvelle table.

---

## Décisions tranchées (D1-D10, ne pas re-débattre)

> Cadrage SM 2026-05-20 (Henri absent — SM tranche autant que possible pour permettre dev autonome). Le dev applique sans re-discuter ; en cas de blocage technique réel (pas de blocage de design), il documente la difficulté dans Dev Agent Record et continue.

### D1 — Topologie des routes : 4 ajouts + enrichissements in-place de l'index existant

- **A (card hero)** + **B (panneau filtres + exports)** = enrichissent **in-place** la page existante `/admin/settings/gpo` (`pages::admin.settings.gpo.index`). Aucune nouvelle route, on modifie le SFC existant.
- **C (vue inverse OU → GPOs)** = nouvelle route `/admin/settings/gpo/by-ou` → nouveau fichier `resources/views/pages/admin/settings/gpo/by-ou/index.blade.php` (SFC Livewire). Route Livewire ajoutée dans le groupe `Route::prefix('settings/gpo')` existant (routes/web.php ~ligne 353), **AVANT** la route paramétrée `/{guid}` pour éviter conflit (iso-pattern Story 16.9 D2, piège 1).
- **D (sections natives)** = nouvelle route `/admin/settings/gpo/sections` → nouveau fichier `resources/views/pages/admin/settings/gpo/sections/index.blade.php` (Blade pur, peu d'état). Route ajoutée dans le même groupe `settings/gpo`, **AVANT** `/{guid}`.
- **E (dashboard jobs)** = nouvelle route `/admin/settings/system/jobs` → nouveau fichier `resources/views/pages/admin/settings/system/jobs/index.blade.php` (SFC Livewire). **Nouveau groupe** `Route::prefix('settings/system')->name('system.')->group(...)` dans le groupe admin existant (cohérent pattern 16.9 / 16.12). Sous-route nommée `admin.system.jobs.index`.
- **Pas d'exports comme routes dédiées** : les exports CSV/JSON sont déclenchés par un bouton sur la page index qui appelle une méthode Livewire `exportCsv()` / `exportJson()` retournant un `StreamedResponse` (cf. D3).
- Rationale : on respecte la convention filesystem-based router (CLAUDE.md) ; on évite de multiplier les controllers (pattern projet = SFC Livewire) ; on garde la cohérence avec `/admin/settings/scripts-logs/*` (16.12) qui suit exactement ce pattern.

### D2 — Composants Livewire SFC vs Blade pur

- **SFC Livewire** : `index.blade.php` (enrichi par A+B), `by-ou/index.blade.php` (C, état réactif filtre OU + auto-complete), `system/jobs/index.blade.php` (E, polling 5s + actions retry/cancel).
- **Blade pur (composant inclus dans SFC)** : `sections/index.blade.php` (D, statique — pas d'état réactif, juste affichage du catalogue des 5 sections).
- **Pas de composants Livewire dans `app/Livewire/`** : conformément à la convention projet (SFC inline `new #[Title] class extends Component`), pas de Volt class component, pas de classe dans `app/Livewire/`. Cf. Story 16.9 D6.
- **Composants Blade réutilisables** : si un sous-bloc dépasse 50 lignes (ex. card hero, item de jobs récents), extraire en `resources/views/components/molecules/gpo-onboarding-card.blade.php` / `gpo-job-item.blade.php` (iso-pattern `<x-molecules.gpo-back-link>` 16.3a).

### D3 — Export CSV/JSON : `StreamedResponse` Laravel, pas de package externe

- Implémentation : méthode Livewire `exportCsv()` / `exportJson()` retourne `Response::streamDownload(closure, filename, headers)` (Laravel natif). Closure utilise `fopen('php://output', 'w')` + `fputcsv` pour CSV, `echo json_encode(...)` pour JSON.
- **Schéma colonnes CSV** (cf. AC2.4) :
  - `display_name` (string), `guid` (string sans accolades), `version_major` (int), `version_minor` (int), `path_sysvol` (string), `ou_links_count` (int), `health_status` (enum : `healthy|orphaned|conflicting|stale`), `native_sections_count` (int).
- **Schéma JSON** : tableau d'objets avec les mêmes clés (snake_case), pretty-printed (`JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE`).
- **Périmètre export** : strictement le listing courant **après application des filtres** (l'utilisateur exporte ce qu'il voit, pas l'intégralité du parc GPO si filtré).
- **Nom de fichier** : `gpo-export-YYYY-MM-DD-HHMMSS.csv` / `.json` (UTC).
- **Pas de pagination dans l'export** : tous les résultats filtrés en un seul fichier (acceptable car parc typique < 200 GPOs).
- **Headers HTTP** : `Content-Type: text/csv; charset=utf-8` ou `application/json`, `Content-Disposition: attachment; filename="..."`.
- Rationale : `StreamedResponse` évite l'allocation mémoire d'un gros buffer, pas de dépendance Composer (Maatwebsite/Excel) — coût minimal pour une volumétrie petite, iso-pattern `PasswordResetExportController` (déjà dans `app/Http/Controllers/`).

### D4 — Filtre "statut santé" : définitions précises

- **`healthy`** : GPO `versionNumber > 0` ET au moins 1 OU liée détectée par scan de `OrganizationalUnitRepository::listAll()` + cross-check `GpoService::getLinks($dn)`. Cas nominal.
- **`orphaned`** : GPO sans aucune OU liée (aucun `getLinks` ne mentionne le GUID/displayName). Détection : scan des OUs (`listAll`) + comptage des liens — si total liens = 0 pour cette GPO, alors `orphaned`.
- **`conflicting`** : **détection best-effort** — au moins 2 GPOs s'appliquent au même `containerDn` ET au moins 2 d'entre elles matchent une même section native (via `NativeSectionResolver::resolve`). Acceptable approximation produit. Cap par sécurité : ne pas faire plus de N appels `samba-tool` (cf. D6 cap performance).
- **`stale`** : GPO dont `versionNumber === 0` OU dont le `versionNumber` n'a pas évolué depuis ≥ 90 jours. **Limite acceptée** : `samba-tool` ne fournit pas l'horodatage de dernière modif de la version → on se contente du `versionNumber === 0` comme proxy "stale" pour la Phase 2. Une vraie détection temporelle est différée (nécessite cache table avec snapshots).
- Le filtre statut santé peut être combiné aux autres filtres (type, OU, version). Si plusieurs sélectionnés en simultané, **AND logique**.

### D5 — Vue inverse OU → GPOs : algorithme et données

- **Algorithme** (méthode Livewire `loadGposForOu(string $containerDn)`) :
  1. Appeler `GpoService::getLinks($containerDn)` → tableau de GPOs liées avec ordre AD natif (parité `samba-tool gpo listlinks`).
  2. Appeler `GpoService::getInheritance($containerDn)` → bool (héritage bloqué ou non).
  3. **Si héritage non bloqué** : remonter les parents OU jusqu'à la racine du domaine en splittant le DN (`OU=A,OU=B,DC=…` → `OU=B,DC=…` → `DC=…`). Pour chaque parent, refaire `getLinks` et merger en respectant l'ordre AD (enfant > parent + enforced beats normal).
  4. Comptage des postes affectés : `Workstation::query()->where(...)` en se basant sur la colonne `ou` ou `dn` (à confirmer T0.5 selon le schéma `workstations` actuel). Si la colonne n'expose pas le DN OU brut → cross-check via `WorkstationGroup` qui pointe vers une OU. Fallback : afficher "N/A" si pas de mapping disponible. Documentation expliquant le best-effort dans le dev-notes de la SFC.
- **Liste des OUs présentables** : récupérée via `OrganizationalUnitRepository::listAll()` (cache 5 min déjà géré par le repo). Filtrer pour ne garder que les OUs qui contiennent des Workstations (sinon listing pollué).
- **Auto-complete OU** : `<input>` avec `wire:model.live.debounce.300ms="ouSearch"`, filtre côté serveur sur la liste de DNs.
- **Cap performance** : ne pas appeler `getLinks` × N pour les 500+ OUs d'un parc — l'utilisateur sélectionne **1 OU à la fois** dans le sélecteur. Les appels `samba-tool` ne sont déclenchés qu'au choix d'une OU précise. Toujours < 5 appels `samba-tool` par render.

### D6 — Sections natives auto-découverte : utiliser `NativeSectionResolver::MAPPING` comme source de vérité

- La page D affiche **toutes les sections du catalogue `MAPPING`** (5 entrées au 2026-05-20 : `profils-itinerants`, `wallpapers`, `app-customizations`, `shortcuts`, `wine`).
- Pour chaque section, afficher : icône (FontAwesome class), label (humain), URL cible (lien direct), **compteur d'entités gérées** (best-effort, voir détail ci-dessous).
- **Compteur d'entités gérées** (best-effort, ne pas bloquer si appel échoue, afficher "—") :
  - `profils-itinerants` : count via `RoamingProfileService::countActiveOverrides()` si méthode existe, sinon `—`.
  - `wallpapers` : count via `Wallpaper::count()` (modèle Eloquent 4.7).
  - `app-customizations` : count via `AppProfile::count()` ou équivalent 4.8.
  - `shortcuts` : count via `Shortcut::count()` (si modèle Eloquent ; sinon `—`).
  - `wine` : count via `WinePrefixScanner::scan()->count()` (16.3c) — scan filesystem.
- **Pas de catalogue dur en code 16.14** : le SFC lit directement `NativeSectionResolver::MAPPING` via une nouvelle méthode publique `NativeSectionResolver::all(): array` à ajouter (retourne le MAPPING complet). **Minimale modif de surface** sur 16.3a — pas de breaking change, juste un getter public.
- Anti-pattern : **ne pas** dupliquer le catalogue dans une config Laravel séparée. Source unique = `NativeSectionResolver`.

### D7 — Jobs dashboard : sources `jobs` + `failed_jobs`, pas de nouvelle table

- **Source pending/running** : table `jobs` (driver `database` Laravel) — colonnes `id`, `queue`, `payload`, `attempts`, `reserved_at`, `available_at`, `created_at`. Le `payload` est un JSON encodé qui contient la classe du job (`displayName` ou `data.commandName`).
- **Source échecs** : table `failed_jobs` — colonnes `id`, `uuid`, `connection`, `queue`, `payload`, `exception`, `failed_at`.
- **Filtre par classe de job** : on ne montre que les jobs GPO/WPKG, donc filtre `payload like '%GenerateWineImageJob%'`, `payload like '%WpkgRepublishJob%'` (ou équivalent — à confirmer T0.6 selon classes existantes).
- **Retry pour échecs** : utiliser `Artisan::call('queue:retry', ['id' => $uuid])` (commande Laravel native), pas de `Bus::dispatch` ad-hoc qui re-créerait un nouveau Job depuis 0.
- **Cancel pour pending** : `DB::table('jobs')->where('id', $jobId)->delete()` (si Job pas encore picked — i.e. `reserved_at` null). Si le job est déjà running, refus + toast "Impossible d'annuler un job en cours".
- **Polling UI** : `wire:poll.5s="refreshJobs"` (rafraîchissement liste toutes les 5 s). Pas d'événement broadcast / WebSocket (over-engineering pour le périmètre 16.14).
- **Pagination** : 20 jobs par page, défaut tri `failed_at desc` puis `created_at desc`.
- **Pas de nouvelle table dédiée** au tracking 16.14. Si une future story (16-15+) veut un historique long terme, c'est un sujet séparé.

### D8 — Permissions : `server.admin` uniforme, iso-Phase 1

- Toutes les nouvelles routes 16.14 sont gardées par `->middleware('can:server.admin')`, iso-pattern 16.9.
- Le panneau filtres avancés (B) et la card hero (A) sont sur la page index existante déjà protégée par `can:server.admin` (16.9). Aucune nouvelle permission à créer.
- Le dashboard jobs (E) : `can:server.admin` (les jobs peuvent toucher tout le parc — éviter exposition à un user lambda).
- Pas de policy nouvelle, pas de gate custom. **Anti-pattern** : ne pas créer une permission `gpo.export` dédiée pour le bouton CSV/JSON — on reste sur `server.admin` comme proxy unique.

### D9 — Tests : ≥30 tests cibles

- **Feature Livewire** (au moins 18) :
  - `GpoIndexAdvancedFiltersTest.php` (≥6 tests) : filtre par type, par OU, par version range, par statut santé, AND multiple, reset filters.
  - `GpoIndexExportTest.php` (≥3 tests) : export CSV happy path, export JSON happy path, export filtré.
  - `GpoByOuPageTest.php` (≥4 tests) : sélecteur OU auto-complete, listing GPOs avec héritage, comptage postes affectés, permission 403.
  - `GpoSectionsPageTest.php` (≥2 tests) : rendu des 5 cards section, permission 403.
  - `SystemJobsDashboardTest.php` (≥3 tests) : rendu liste jobs récents, retry job échoué, cancel job pending.
- **Unit** (au moins 8) :
  - `GpoHealthStatusCalculatorTest.php` (≥5 tests) : healthy / orphaned / conflicting / stale + edge case (GPO version 0 sans OU).
  - `GpoExportSerializerTest.php` (≥3 tests) : génération CSV avec valeurs Unicode, JSON pretty-print, dates UTC.
- **Architecture** (au moins 3) :
  - `Story1614RoutesTest.php` : routes /admin/settings/gpo/by-ou, /admin/settings/gpo/sections, /admin/settings/system/jobs déclarées avec `can:server.admin`, ordre des routes statiques avant `/{guid}` (anti-régression piège 1 de 16.9).
- **Pas d'E2E navigateur** (iso-pattern 16.2/16.3a — pas d'infra Playwright/Cypress).
- Critère vert : exit 0, ≥ 30 nouveaux tests ajoutés, 0 régression sur les 486 tests Phase 1 GREEN baseline (post-16.9).

### D10 — i18n : libellés en français inline, pas de fichier lang dédié

- Iso-pattern 16.2/16.9/16.12 : les libellés UI sont en français hardcodés dans les Blade (`Filtres avancés`, `Statut santé`, `Vue inverse OU → GPOs`, `Exporter en CSV`, etc.).
- Pas de fichier `lang/fr/gpo.php` créé dans 16.14 (sauf si la story trouve qu'un fichier existe déjà pour 16.2/16.9 — auquel cas réutiliser).
- Anti-pattern : ne pas introduire `__('gpo.filters.health')` pour 16.14 — pas le contexte de l'epic.

---

## Story

As an **administrateur Sambaedu (`server.admin`)**,
I want :
- **un parcours d'onboarding clair** (card hero) sur l'index `/admin/settings/gpo` pour comprendre immédiatement les 3 chemins clés (consulter une GPO, lier une OU, éditer une section native) ;
- **des filtres avancés** sur le listing (par type Machine/User/Script-logon, par OU liée, par range de version, par statut santé orphelin/conflit/obsolète) avec **export CSV/JSON** du listing filtré ;
- **une vue d'impact inverse** "Quelles GPOs s'appliquent à cette OU et dans quel ordre ?" avec comptage des postes affectés ;
- **un catalogue visible des sections natives** déjà refondues (Firefox, Wallpaper, Shortcuts, Veyon, Network, Wine, Associations) — pour sortir de l'invisibilité ce que la heuristique 16.3a sait détecter ;
- **un mini-dashboard "Jobs récents"** sur `/admin/settings/system/jobs` qui me montre les Jobs Wine generation et WPKG republish en cours / échoués / réussis avec actions retry/cancel ;

so that **je gagne en lisibilité produit, en discoverabilité (Firefox/Wallpaper/Shortcuts ne sont plus invisibles), en capacité d'audit/impact-analysis (vue inverse + filtres + exports), et en feedback opérationnel (visibilité jobs async)** — sans refonte structurelle ni nouvelle table Eloquent, sur la base UI livrée par 16.9.

---

## Contexte

### État entrant (post-16.9 done)

- **5 pages Livewire SFC** sous `/admin/settings/gpo/*` opérationnelles (16.9 done 2026-05-18 — QA Henri validée 6 scénarios + 486 tests Phase 1 GREEN).
- **`NativeSectionResolver`** (16.3a) opérationnel avec 5 sections cataloguées (`profils-itinerants`, `wallpapers`, `app-customizations`, `shortcuts`, `wine`).
- **`GpoService`** (16.1) avec 5 méthodes lecture (`list`, `get`, `listContainers`, `getLinks`, `getInheritance`) toutes utilisables sans modification.
- **Tables `jobs` + `failed_jobs`** : driver `database` Laravel (à confirmer T0.6 — sinon Redis, auquel cas adapter le dashboard E pour interroger Redis via `Redis::lrange('queues:default', 0, -1)`).
- **`OrganizationalUnitRepository::listAll()`** : cache 5 min, retourne tableau de DNs OU AD.

### Topologie cible (post-16.14)

- **`/admin/settings/gpo`** (enrichi) : card hero onboarding (A) + panneau filtres avancés repliable (B) + boutons export CSV/JSON (B).
- **`/admin/settings/gpo/by-ou`** (nouveau, C) : sélecteur OU + listing GPOs appliquées avec ordre AD + comptage postes affectés.
- **`/admin/settings/gpo/sections`** (nouveau, D) : catalogue card-grid des 5 sections natives.
- **`/admin/settings/system/jobs`** (nouveau, E) : dashboard jobs récents avec retry/cancel.
- **Sidebar** enrichie : ajout des 3 nouveaux liens dans le bloc collapse « GPO » (after `Toutes les GPOs`) + nouveau bloc « Système » sous « Réglages » pour le dashboard jobs (ou regroupement dans le bloc GPO existant si Henri préfère — D11 ouverte).

### Pré-requis (à valider en T0)

- **16.8 done** ✅ (commits f9e11a0 + c8a8cce 2026-05-16).
- **16.9 done** ✅ (QA Henri 2026-05-18 + 3 commits diversion af7df75 + ac5afb5 + 124b957).
- **Phase 1 GREEN baseline** : `scripts/run-tests.sh phase1` doit retourner exit 0 (point de comparaison pour l'AC tests).
- **Tables `jobs` + `failed_jobs`** vérifiées présentes dans la migration Laravel par défaut (T0.6).

---

## Acceptance Criteria

### Volet 1 — A. Card hero d'onboarding + parcours guidés

**AC1.1** — **Card hero affichée en haut de `/admin/settings/gpo`**
**Given** un utilisateur `server.admin` connecté
**When** il accède à `GET /admin/settings/gpo`
**Then** une card hero (Daisy `card bg-base-200` ou équivalent) est rendue **en haut** de la vue, avant le panneau de filtres et avant le tableau
**And** elle contient un titre `H2` "Gestion des GPO Active Directory", un sous-titre explicatif (1-2 lignes) et **3 cards d'action** (parcours guidés) :
- **Card 1 "Consulter / Inspecter"** → lien vers `#listing-gpos` (ancre sur le tableau)
- **Card 2 "Lier une GPO à une OU"** → lien vers `/admin/settings/gpo/by-ou` (vue inverse C)
- **Card 3 "Éditer une section native"** → lien vers `/admin/settings/gpo/sections` (catalogue D)

**AC1.2** — **La card hero peut être masquée**
**Given** la card hero affichée
**When** l'utilisateur clique sur un bouton "Masquer" (croix en haut à droite)
**Then** la card hero disparaît pour la session (`session()->put('gpo.hero.dismissed', true)`) — pas de persistance en DB
**And** un mini-bouton "Afficher l'aide" apparaît en haut du listing pour la réafficher
**And** la décision est **per-session** : nouvelle session → card hero ré-affichée.

**AC1.3** — **Suppression / encadrement du bouton legacy "Créer GPO"**
**Given** la situation post-16.9 (le bouton "Créer GPO (ancienne UI)" a déjà été supprimé par 16.9 diversion — voir commit ac5afb5)
**When** la vue est rendue
**Then** **aucun** bouton ou lien vers `/gpo/gpo-maj.php` ou `/gpo/gestion_gpo.php?action=create` n'apparaît sur la page index
**And** un encart d'information discret (alert info, fermeture possible) mentionne : "La création de GPO native est volontairement non exposée — utilisez `samba-tool gpo create` en SSH ou contactez votre support si besoin." Référence mémoire `project_no_native_gpo_creation` (Story 16-4 cancelled 2026-05-18).

### Volet 2 — B. Panneau filtres avancés + exports CSV/JSON

**AC2.1** — **Panneau filtres compact replié par défaut**
**Given** l'utilisateur sur `/admin/settings/gpo`
**When** la page est rendue
**Then** un **panneau filtres** (DaisyUI `collapse collapse-arrow`) est rendu **sous** la card hero, **au-dessus** du tableau de listing
**And** il est **replié par défaut** (état `collapse` sans `collapse-open`)
**And** un compteur dans le header "Filtres avancés (N actifs)" indique le nombre de filtres actuellement appliqués (excluant la recherche déjà gérée par 16.2)
**And** un bouton "Réinitialiser" est présent et **vide tous les filtres avancés en 1 clic**.

**AC2.2** — **5 critères de filtres avancés**
**Given** le panneau filtres ouvert
**When** l'utilisateur interagit avec
**Then** les 5 critères suivants sont disponibles :
- **Type** : `<select>` avec valeurs `[Toutes | Machine | User | Script logon]` (heuristique sur `displayName` : préfixe `se4_app`/`se4_machine` = Machine, `se4_user` = User, `se4_logon` = Script logon — cf. parité avec audit GPO legacy).
- **OU liée** : `<input>` avec auto-complete (`wire:model.live.debounce.300ms`) sur la liste OUs récupérée via `OrganizationalUnitRepository::listAll()`. Suggestions dropdown DaisyUI.
- **Version range** : 2 champs `<input type="number">` `[min, max]` sur `versionNumber` (champ `major` du décodage 16.2 D10).
- **Statut santé** : `<select multiple>` avec valeurs `[healthy | orphaned | conflicting | stale]` (définitions D4).
- **Avec sections natives uniquement** : `<input type="checkbox">` — si coché, filtre via `NativeSectionResolver::hasMatch($displayName)` true.
**And** chaque filtre est synchronisé avec l'URL via `#[Url(keep: true)]` (parité shortcuts) pour permettre le partage de liens.

**AC2.3** — **Combinaison AND des filtres**
**Given** plusieurs filtres avancés actifs simultanément (par ex. Type=Machine + Statut=orphaned)
**When** l'utilisateur les applique
**Then** le listing affiche **uniquement** les GPOs qui matchent **TOUS** les critères simultanément (AND logique)
**And** le compteur "X GPO(s) sur Y" reflète le filtrage.

**AC2.4** — **Export CSV**
**Given** le listing affiché avec ou sans filtres
**When** l'utilisateur clique le bouton "Exporter en CSV"
**Then** un fichier `gpo-export-YYYY-MM-DD-HHMMSS.csv` est téléchargé via `Response::streamDownload(...)`
**And** son contenu inclut **uniquement les GPOs visibles après filtrage** (pas tout le parc)
**And** les colonnes sont exactement : `display_name`, `guid`, `version_major`, `version_minor`, `path_sysvol`, `ou_links_count`, `health_status`, `native_sections_count`
**And** le header est en première ligne, encodage UTF-8 BOM (pour ouverture Excel sans corruption)
**And** un toast `WithToasts::toastSuccess('Export CSV généré (N GPOs)')` est émis.

**AC2.5** — **Export JSON**
**Given** le listing affiché avec ou sans filtres
**When** l'utilisateur clique le bouton "Exporter en JSON"
**Then** un fichier `gpo-export-YYYY-MM-DD-HHMMSS.json` est téléchargé
**And** son contenu est un tableau JSON d'objets, mêmes clés snake_case que le CSV
**And** le formatage est pretty-printed (`JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE`)
**And** un toast `WithToasts::toastSuccess('Export JSON généré (N GPOs)')` est émis.

### Volet 3 — C. Vue inverse OU → GPOs

**AC3.1** — **Route et accessibilité `/admin/settings/gpo/by-ou`**
**Given** un utilisateur `server.admin`
**When** il accède à `GET /admin/settings/gpo/by-ou`
**Then** la page Livewire SFC `pages::admin.settings.gpo.by-ou.index` (fichier `resources/views/pages/admin/settings/gpo/by-ou/index.blade.php`) est servie (HTTP 200)
**And** la route est définie dans `routes/web.php` sous le groupe `Route::prefix('settings/gpo')->name('gpo.')->group(...)` existant (16.9) **AVANT** la route paramétrée `/{guid}` (cf. piège 1 de 16.9 — ordre critique)
**And** elle est gardée par `->middleware('can:server.admin')`
**And** son nom est `admin.gpo.by-ou`
**And** un utilisateur sans permission `server.admin` reçoit HTTP 403.

**AC3.2** — **Sélecteur d'OU avec auto-complete**
**Given** la page est mountée
**When** la SFC se rend
**Then** un `<input>` "Sélectionnez une OU" avec auto-complete est affiché
**And** il consomme la liste retournée par `OrganizationalUnitRepository::listAll()` (cache 5 min)
**And** la saisie est debouncée (`wire:model.live.debounce.300ms="ouSearch"`)
**And** les suggestions filtrent par substring case-insensitive sur le DN.

**AC3.3** — **Listing des GPOs appliquées avec héritage**
**Given** l'utilisateur a sélectionné une OU dans le sélecteur (le DN est stocké en `#[Url] public string $selectedOu`)
**When** la méthode `loadGposForOu()` est déclenchée
**Then** la SFC appelle `GpoService::getLinks($selectedOu)` puis `GpoService::getInheritance($selectedOu)`
**And** si héritage non bloqué, remonte les parents OU et appelle `getLinks` sur chacun (cap à 5 niveaux de profondeur)
**And** affiche un tableau ordonné : Nom GPO | Origine (Directe / Héritée de "OU=X,…") | Enforced (badge) | Disabled (badge) | Actions (lien vers détail `/admin/settings/gpo/{guid}`)
**And** une badge "Héritage bloqué" est affichée en haut si `getInheritance` retourne false.

**AC3.4** — **Comptage des postes affectés (best-effort)**
**Given** une OU sélectionnée
**When** la SFC calcule le comptage
**Then** elle interroge le modèle `Workstation` pour compter les postes appartenant à cette OU (via `WorkstationGroup` ou via une colonne `ou_dn` si présente — à confirmer T0.5)
**And** affiche en haut de la liste : "N postes affectés par les GPOs appliquées à cette OU"
**And** si le mapping Workstation ↔ OU n'est pas disponible nativement → affiche "N/A (mapping OU non disponible)" sans bloquer la page
**And** une note discrete explique : "Comptage best-effort basé sur le rattachement WorkstationGroup ↔ OU."

**AC3.5** — **État vide**
**Given** une OU sélectionnée qui n'a aucune GPO liée
**When** la SFC charge les résultats
**Then** un état vide explicite est affiché ("Aucune GPO appliquée à cette OU.")
**And** aucun appel `samba-tool` n'est effectué côté héritage si la sélection est vide.

### Volet 4 — D. Section "Sections gérables nativement"

**AC4.1** — **Route et accessibilité `/admin/settings/gpo/sections`**
**Given** un utilisateur `server.admin`
**When** il accède à `GET /admin/settings/gpo/sections`
**Then** la page Blade `pages::admin.settings.gpo.sections.index` (fichier `resources/views/pages/admin/settings/gpo/sections/index.blade.php`) est servie (HTTP 200)
**And** la route est dans le groupe `settings/gpo` 16.9, **AVANT** la route paramétrée `/{guid}` (piège 1 de 16.9)
**And** elle est gardée par `->middleware('can:server.admin')`
**And** son nom est `admin.gpo.sections`.

**AC4.2** — **Catalogue card-grid des 5 sections**
**Given** la page est rendue
**When** elle compose la grille
**Then** **5 cards** sont affichées (1 par entrée de `NativeSectionResolver::MAPPING`) :
- `profils-itinerants` → label "Profils itinérants", URL `/admin/settings?tab=profils-itinerants`, icône `fa-users-gear`
- `wallpapers` → label "Fonds d'écran", URL `/app/parc-settings/wallpapers`, icône `fa-image`
- `app-customizations` → label "Personnalisation apps (Firefox/Thunderbird)", URL `/app/parc-settings/app-customizations`, icône `fa-puzzle-piece`
- `shortcuts` → label "Raccourcis", URL `/app/shortcuts`, icône `fa-link`
- `wine` → label "Apps Wine (Linux)", URL `/admin/settings/gpo/wine`, icône `fa-wine-glass`
**And** chaque card est cliquable et navigue vers l'URL cible (sans `from_gpo=` car pas de contexte GPO source ici)
**And** chaque card affiche un compteur d'entités gérées (best-effort selon D6) — affichage `—` si appel impossible.

**AC4.3** — **Source de vérité = `NativeSectionResolver::MAPPING`**
**Given** la classe `NativeSectionResolver`
**When** le dev applique 16.14
**Then** une nouvelle méthode publique `NativeSectionResolver::all(): array` est ajoutée qui retourne le `MAPPING` complet
**And** la page D consomme cette méthode (pas de duplication du catalogue)
**And** **aucune autre modification** de `NativeSectionResolver` (pas de changement de signature `resolve`, `hasMatch`, `buildUrl`, pas d'ajout/retrait d'entrée de MAPPING).

### Volet 5 — E. Mini-dashboard "Jobs récents"

**AC5.1** — **Route et accessibilité `/admin/settings/system/jobs`**
**Given** un utilisateur `server.admin`
**When** il accède à `GET /admin/settings/system/jobs`
**Then** la page Livewire SFC `pages::admin.settings.system.jobs.index` (fichier `resources/views/pages/admin/settings/system/jobs/index.blade.php`) est servie (HTTP 200)
**And** la route est définie dans un **nouveau groupe** `Route::prefix('settings/system')->name('system.')->group(...)` au sein du groupe admin existant
**And** elle est gardée par `->middleware('can:server.admin')`
**And** son nom est `admin.system.jobs.index`.

**AC5.2** — **Liste des jobs récents (pending + running + failed)**
**Given** la page est mountée
**When** la SFC charge les données
**Then** elle interroge la table `jobs` (pending/running) et `failed_jobs` (échoués) filtrant **uniquement** les jobs dont la classe contient `GenerateWineImageJob`, `WpkgRepublishJob`, ou les classes émises par `WpkgGpoSynchronizer` (à lister T0.6)
**And** affiche un tableau paginé (20 par page) avec colonnes : `Job` (nom de classe humanisé) | `Queue` | `Statut` (pending/running/failed) | `Attempts` | `Failed at / Created at` | `Actions` (retry / cancel selon contexte)
**And** un compteur global "X jobs récents" est affiché.

**AC5.3** — **Polling 5s automatique**
**Given** la page chargée
**When** l'utilisateur reste sur la page
**Then** la liste est rafraîchie via `wire:poll.5s="refreshJobs"` toutes les 5 secondes
**And** un indicateur visuel discret (puce verte clignotante) confirme le polling actif.

**AC5.4** — **Action "Retry" sur job échoué**
**Given** un job listé en statut `failed`
**When** l'utilisateur clique "Retry"
**Then** la SFC exécute `Artisan::call('queue:retry', ['id' => $jobUuid])` (commande Laravel native)
**And** un toast `WithToasts::toastSuccess('Job remis en queue')` est émis
**And** le job disparaît de `failed_jobs` (consommé par la commande artisan) et apparaît dans `jobs` au prochain refresh.

**AC5.5** — **Action "Cancel" sur job pending**
**Given** un job listé en statut `pending` (présent dans `jobs` avec `reserved_at IS NULL`)
**When** l'utilisateur clique "Cancel"
**Then** la SFC exécute `DB::table('jobs')->where('id', $jobId)->whereNull('reserved_at')->delete()`
**And** si l'opération supprime ≥ 1 ligne, toast succès `'Job annulé'`
**And** si 0 ligne (job déjà pické entre-temps), toast warning `'Le job était déjà en cours, annulation impossible'`.

### Volet 6 — Sidebar et navigation

**AC6.1** — **Bloc « GPO » sidebar enrichi**
**Given** la sidebar (`resources/views/components/organisms/sidebar.blade.php`)
**When** le dev applique 16.14
**Then** le bloc collapse « GPO » sous « Réglages » (créé par 16.9) reçoit **2 liens supplémentaires** :
- « Vue par OU » → `route('admin.gpo.by-ou')`
- « Sections natives » → `route('admin.gpo.sections')`
**And** ces liens sont gardés par `@can('server.admin')` iso-pattern 16.9
**And** ils apparaissent dans cet ordre : Toutes les GPOs → Vue par OU → Sections natives → Wine — Apps Linux → WPKG — Pipeline.

**AC6.2** — **Nouveau lien « Jobs système » dans la sidebar**
**Given** la sidebar
**When** le dev applique 16.14
**Then** un lien « Jobs système » est ajouté sous « Réglages » (en dehors du bloc GPO — soit nouveau bloc collapse « Système » soit lien direct sous Réglages selon D11)
**And** il pointe vers `route('admin.system.jobs.index')`
**And** il est gardé par `@can('server.admin')`.

### Volet 7 — Tests Feature, Unit, Architecture

**AC7.1** — **≥ 18 tests Feature Livewire**
**Given** les nouvelles surfaces UI 16.14
**When** le dev crée les fichiers tests
**Then** les fichiers suivants existent et passent :
- `tests/Feature/Gpo/GpoIndexAdvancedFiltersTest.php` (≥ 6 tests, cf. D9)
- `tests/Feature/Gpo/GpoIndexExportTest.php` (≥ 3 tests)
- `tests/Feature/Gpo/GpoByOuPageTest.php` (≥ 4 tests)
- `tests/Feature/Gpo/GpoSectionsPageTest.php` (≥ 2 tests)
- `tests/Feature/System/SystemJobsDashboardTest.php` (≥ 3 tests)
**And** chacun couvre au moins permission 403, rendu happy path, et 1 cas d'erreur.

**AC7.2** — **≥ 8 tests Unit**
**Given** les services calculs ajoutés
**When** le dev crée les tests
**Then** les fichiers suivants existent et passent :
- `tests/Unit/Gpo/GpoHealthStatusCalculatorTest.php` (≥ 5 tests : healthy / orphaned / conflicting / stale + edge case)
- `tests/Unit/Gpo/GpoExportSerializerTest.php` (≥ 3 tests : CSV avec Unicode, JSON pretty-print, dates UTC)
**And** ils restent purement unitaires (pas de bootstrap Spatie ni accès DB).

**AC7.3** — **≥ 3 tests Architecture**
**Given** les nouvelles routes 16.14
**When** le dev crée `tests/Architecture/Story1614RoutesTest.php`
**Then** au moins 3 tests passent :
- Les 3 routes nouvelles (`admin.gpo.by-ou`, `admin.gpo.sections`, `admin.system.jobs.index`) sont déclarées
- Toutes 3 sont gardées par `can:server.admin`
- Les routes `by-ou` et `sections` sont déclarées **AVANT** la route paramétrée `/{guid}` dans le groupe `settings/gpo` (anti-régression piège 1 de 16.9).

**AC7.4** — **Aucune régression Phase 1 + 16.9**
**Given** la baseline 486 tests verts (post-16.9 done)
**When** le dev exécute `scripts/run-tests.sh phase1` post-16.14
**Then** exit code 0
**And** ≥ 486 + 30 = 516 tests passent (les ajouts AC7.1-7.3 créent au moins 30 tests supplémentaires)
**And** 0 régression sur la baseline existante.

### Volet 8 — Logs audit `gpo` channel

**AC8.1** — **Actions UI 16.14 loggées dans channel `gpo`**
**Given** les actions UI suivantes : export CSV, export JSON, retry job, cancel job
**When** l'utilisateur les déclenche
**Then** chaque action émet un log `action_type` dans le channel `gpo` (16.1 convention) :
- `gpo.export.csv` (avec compteur N GPOs exportées)
- `gpo.export.json` (avec compteur N GPOs)
- `gpo.job.retry` (avec job uuid + classe job)
- `gpo.job.cancel` (avec job id + succès/échec)
**And** chaque log contient `actor_user_id`, `outcome` (`success`/`failure`), et un timestamp UTC ISO 8601
**And** la SFC **n'utilise jamais** `\Log::*` directement — elle passe par le `GpoLogger` (16.1).

### Volet 9 — Documentation QA

**AC9.1** — **`docs/qa/domains/gpo.md`** est enrichi d'une nouvelle section append-only (pattern iso-16.9) « Story 16.14 — Améliorations UX UI admin GPO » qui documente :
- Liste des 5 améliorations livrées + URLs des nouvelles routes
- 8 scénarios smoke QA numérotés stables : 16.14-1 (card hero affichée + dismiss), 16.14-2 (filtres avancés type+OU), 16.14-3 (export CSV), 16.14-4 (export JSON), 16.14-5 (vue inverse by-ou), 16.14-6 (catalogue sections), 16.14-7 (dashboard jobs polling), 16.14-8 (retry/cancel job).

---

## Tasks / Subtasks

### Phase T0 — Préflight + cadrage (≈ 0.5j)

- [x] **T0.1** Vérifier que 16.9 est `done` dans `sprint-status.yaml` (ligne ~264).
- [x] **T0.2** Exécuter `scripts/run-tests.sh phase1` pour obtenir la baseline GREEN (logger nombre exact de tests passants — point de comparaison AC7.4).
- [x] **T0.3** Lire `routes/web.php` lignes 343-385 pour comprendre la structure du groupe `settings/gpo` 16.9 + le piège 1 (ordre routes statiques avant `/{guid}`).
- [x] **T0.4** Lire `resources/views/pages/admin/settings/gpo/index.blade.php` (post-16.9 + diversion ac5afb5) pour comprendre la structure des filtres existants (16.2) et le placement actuel des éléments.
- [x] **T0.5** Lire `app/Models/Workstation.php` + `app/Models/WorkstationGroup.php` pour confirmer le mapping vers OU (colonne `ou_dn`, relation polymorphique, ou besoin de fallback "N/A").
- [x] **T0.6** Vérifier le driver queue actif (`config('queue.default')`) — confirmer que c'est `database` (tables `jobs` + `failed_jobs` présentes). Lister les classes de jobs GPO/WPKG existantes (`grep -r "extends.*Job" app/Gpo/Jobs/ app/Wpkg/`). Documenter en commentaire de la SFC E.
- [x] **T0.7** Vérifier sidebar `resources/views/components/organisms/sidebar.blade.php` — repérer le bloc collapse « GPO » créé par 16.9 + le bloc « Réglages ».

### Phase T1 — A. Card hero d'onboarding (≈ 0.5-1j) — AC1.x

- [x] **T1.1** Créer le composant Blade `resources/views/components/molecules/gpo-onboarding-card.blade.php` qui rend la card hero avec 3 sous-cards (parcours guidés). Props : `dismissible` (bool), `dismissed` (bool).
- [x] **T1.2** Modifier `resources/views/pages/admin/settings/gpo/index.blade.php` (post-16.9) pour inclure `<x-molecules.gpo-onboarding-card />` en haut du body, avant le panneau filtres.
- [x] **T1.3** Ajouter la propriété SFC `public bool $heroDismissed = false;` initialisée depuis la session (`mount()`) + méthode `dismissHero()` qui set la session.
- [x] **T1.4** Vérifier qu'aucun bouton "Créer GPO (ancienne UI)" n'apparaît dans la vue (déjà supprimé par 16.9 diversion ac5afb5 — confirmer en grep `gpo-maj.php` dans la vue).
- [x] **T1.5** Ajouter l'encart d'information "Création de GPO native volontairement non exposée" (référence mémoire `project_no_native_gpo_creation`).

### Phase T2 — B. Panneau filtres avancés + exports (≈ 2-3j) — AC2.x

- [x] **T2.1** Créer le service `App\Gpo\Support\GpoHealthStatusCalculator` (classe stateless avec méthode `calculate(GpoSummary $gpo, array $allLinks): GpoHealthStatus`). Retourne enum `App\Gpo\Enums\GpoHealthStatus` (`Healthy`, `Orphaned`, `Conflicting`, `Stale`).
- [x] **T2.2** Ajouter les propriétés SFC pour les 5 filtres avancés : `#[Url(keep: true)] public ?string $filterType`, `#[Url(keep: true)] public ?string $filterOu`, `#[Url(keep: true)] public ?int $filterVersionMin/Max`, `#[Url(keep: true)] public array $filterHealthStatuses`, `#[Url(keep: true)] public bool $filterNativeOnly`.
- [x] **T2.3** Implémenter la méthode `applyAdvancedFilters(Collection $gpos): Collection` qui applique AND logique sur les 5 critères.
- [x] **T2.4** Implémenter `resetAdvancedFilters()` qui reset les 5 propriétés à leurs defaults.
- [x] **T2.5** Composer le partial `_partials/advanced-filters-panel.blade.php` (DaisyUI `collapse collapse-arrow`) avec les 5 contrôles et le compteur "N actifs".
- [x] **T2.6** Créer le service `App\Gpo\Support\GpoExportSerializer` avec méthodes `toCsvRows(Collection $gpos): array` et `toJsonArray(Collection $gpos): array`.
- [x] **T2.7** Implémenter les méthodes Livewire `exportCsv()` et `exportJson()` qui retournent `Response::streamDownload(...)`. Émettre les logs `gpo.export.csv` / `gpo.export.json` via `GpoLogger`.
- [x] **T2.8** Ajouter les 2 boutons "Exporter en CSV" / "Exporter en JSON" dans la vue index (à côté du bouton "Rafraîchir" existant 16.2).

### Phase T3 — C. Vue inverse OU → GPOs (≈ 2-3j) — AC3.x

- [x] **T3.1** Créer `resources/views/pages/admin/settings/gpo/by-ou/index.blade.php` (SFC Livewire) avec : sélecteur OU auto-complete, listing GPOs résultantes, comptage postes.
- [x] **T3.2** Ajouter la route dans `routes/web.php` (groupe `settings/gpo` 16.9, **AVANT** `/{guid}`) : `Route::livewire('/by-ou', 'pages::admin.settings.gpo.by-ou.index')->middleware('can:server.admin')->name('by-ou');`.
- [x] **T3.3** Implémenter la méthode `loadGposForOu(string $ouDn)` : appel `GpoService::getLinks`, `GpoService::getInheritance`, remontée parents si pas bloqué (cap 5 niveaux).
- [x] **T3.4** Implémenter `countWorkstationsForOu(string $ouDn): ?int` (best-effort selon T0.5 — fallback "N/A").
- [x] **T3.5** Composer le tableau résultats avec colonnes : Nom GPO | Origine (Directe / Héritée) | Enforced | Disabled | Actions.

### Phase T4 — D. Sections natives (≈ 0.5-1j) — AC4.x

- [x] **T4.1** Ajouter la méthode publique `NativeSectionResolver::all(): array` qui retourne `self::MAPPING`. **Pas d'autre modification** sur cette classe (D6 anti-pattern).
- [x] **T4.2** Créer `resources/views/pages/admin/settings/gpo/sections/index.blade.php` (Blade pur, pas Livewire — pas d'état réactif).
- [x] **T4.3** Ajouter la route dans `routes/web.php` (groupe `settings/gpo`, AVANT `/{guid}`) : `Route::view('/sections', 'pages::admin.settings.gpo.sections.index')->middleware('can:server.admin')->name('sections');`. **Note** : `Route::view` car Blade pur. Si Livewire requis pour compteurs dynamiques, basculer en `Route::livewire`.
- [x] **T4.4** Implémenter la grid card avec les 5 sections du MAPPING + compteurs best-effort (D6).

### Phase T5 — E. Dashboard jobs (≈ 2-3j) — AC5.x

- [x] **T5.1** Créer un nouveau groupe `Route::prefix('settings/system')->name('system.')->group(...)` dans le groupe admin existant de `routes/web.php`.
- [x] **T5.2** Créer `resources/views/pages/admin/settings/system/jobs/index.blade.php` (SFC Livewire).
- [x] **T5.3** Implémenter `loadJobs()` qui interroge `DB::table('jobs')` et `DB::table('failed_jobs')` filtrant sur les classes GPO/WPKG (cf. T0.6).
- [x] **T5.4** Composer la vue tableau paginé (20 par page) avec colonnes Job/Queue/Statut/Attempts/Failed at/Actions.
- [x] **T5.5** Implémenter `retryJob(string $uuid)` (commande `queue:retry`) + log `gpo.job.retry` + toast.
- [x] **T5.6** Implémenter `cancelJob(int $jobId)` (`DB::delete`) + log `gpo.job.cancel` + toast adapté (succès / déjà running).
- [x] **T5.7** Ajouter `wire:poll.5s="refreshJobs"` dans la vue + puce indicateur polling actif.

### Phase T6 — Sidebar (≈ 0.25j) — AC6.x

- [x] **T6.1** Modifier `resources/views/components/organisms/sidebar.blade.php` : ajouter les 2 liens nouveaux dans le bloc collapse « GPO » (Vue par OU + Sections natives), conformément à AC6.1.
- [x] **T6.2** Ajouter le lien "Jobs système" sous « Réglages » (nouveau bloc collapse ou lien direct selon D11 — par défaut **bloc collapse « Système »**).

### Phase T7 — Tests Feature + Unit + Architecture (≈ 1-1.5j) — AC7.x

- [x] **T7.1** Créer `tests/Feature/Gpo/GpoIndexAdvancedFiltersTest.php` (≥ 6 tests : filtre type, OU, version range, statut santé, AND multiple, reset).
- [x] **T7.2** Créer `tests/Feature/Gpo/GpoIndexExportTest.php` (≥ 3 tests : CSV happy, JSON happy, filtré).
- [x] **T7.3** Créer `tests/Feature/Gpo/GpoByOuPageTest.php` (≥ 4 tests : sélecteur, listing+héritage, comptage postes, 403).
- [x] **T7.4** Créer `tests/Feature/Gpo/GpoSectionsPageTest.php` (≥ 2 tests : rendu 5 cards, 403).
- [x] **T7.5** Créer `tests/Feature/System/SystemJobsDashboardTest.php` (≥ 3 tests : rendu liste, retry, cancel).
- [x] **T7.6** Créer `tests/Unit/Gpo/GpoHealthStatusCalculatorTest.php` (≥ 5 tests).
- [x] **T7.7** Créer `tests/Unit/Gpo/GpoExportSerializerTest.php` (≥ 3 tests).
- [x] **T7.8** Créer `tests/Architecture/Story1614RoutesTest.php` (≥ 3 tests).
- [x] **T7.9** Exécuter `scripts/run-tests.sh phase1` complet — vérifier exit 0 + ≥ 30 tests ajoutés + 0 régression.

### Phase T8 — Doc QA + sprint-status + backlog.html (≈ 0.25j)

- [x] **T8.1** Enrichir `docs/qa/domains/gpo.md` avec section "Story 16.14" + 8 scénarios smoke numérotés (AC9.1).
- [x] **T8.2** Mettre à jour `_bmad-output/implementation-artifacts/sprint-status.yaml` : `16-14-ameliorations-ux-ui-admin-gpo: ready-for-dev` → `review` (en fin de dev, **pas par SM**).
- [x] **T8.3** Mettre à jour `_bmad-output/backlog.html` : status 16-14 → review (en fin de dev).

---

## Dépendances

| Story / Epic | Titre | Status | Détail |
|---|---|---|---|
| **16.8** | Stabilisation Phase 1 — tests + audit | ✅ done (2026-05-16) | Baseline 486 tests GREEN, audit iso-legacy validé. |
| **16.9** | Exposition UI admin GPO sous `/admin/settings/gpo/*` | ✅ done (2026-05-18) | Toutes les pages GPO sous `/admin/settings/gpo/*`, sidebar enrichie, 301 depuis `/app/gpo/*`. **Inclut nettoyage** : bouton "Créer GPO" déjà supprimé + dédup CTAs natifs page détail. |
| **16.3a** | Liens profonds sections natives | ✅ done (2026-05-12) | Fournit `App\Gpo\Support\NativeSectionResolver` (5 sections MAPPING). 16.14 ajoute `NativeSectionResolver::all()` (getter public, pas de breaking change). |
| **16.1** | Fondations GPO natives | ✅ done | Fournit `App\Gpo\Services\GpoService` (5 méthodes lecture). 16.14 consomme sans modification. |
| **16.3c** | Wine UI + Job queue | ✅ done | Fournit `WineImageQueuer` + `GenerateWineImageJob` — producteurs de jobs visibles dans dashboard E. |
| **16.6** | WPKG-GPO pipeline | ✅ done | Fournit `WpkgGpoSynchronizer` — producteur de jobs visibles dans dashboard E. |
| **4.7 / 4.8** | Wallpapers + AppProfile | ✅ done | Modèles Eloquent `Wallpaper` + `AppProfile` — pour compteurs section D (best-effort). |

**Conclusion** : aucune dépendance bloquante. Toutes les fondations sont en place.

---

## Décisions ouvertes (à arbitrer par Henri ou trancher pragmatiquement par dev si bloquant)

| # | Question | Hypothèse SM (à confirmer) | Impact si non confirmé |
|---|---|---|---|
| **D11** | Bloc sidebar pour "Jobs système" : nouveau bloc collapse « Système » sous « Réglages » OU lien direct sous « Réglages » ? | **Nouveau bloc collapse « Système »** (extensible si futures pages système 16.12 logs / 16.13bis migration tracking y rejoignent). | Si Henri préfère lien direct → trivial à modifier (1 ligne sidebar). |
| **D12** | Heuristique de filtrage "Type" (Machine/User/Script-logon) : prefix `displayName` (`se4_app*`, `se4_machine*`, etc.) — quelle est la convention exacte du parc ? | **Préfixes pragmatiques** : `se4_app*`/`se4_machine*` → Machine ; `se4_user*` → User ; `se4_logon*` → Script logon. Si pas de prefix recognized → "Autre" (4ème catégorie pour ne pas exclure des GPOs custom). | Si convention différente (en cas de parc mixte ancien/nouveau), le filtre peut afficher 0 résultat pour Machine — documenter le pattern dans dev-notes et permettre une exclusion. |
| **D13** | Mapping Workstation ↔ OU pour comptage postes affectés (C AC3.4) : la colonne `ou_dn` existe-t-elle sur le modèle `Workstation` ? | **Best-effort** : si oui → `Workstation::where('ou_dn', $ouDn)->count()` ; sinon → afficher "N/A (mapping non disponible)". | Si la colonne n'existe pas, le compteur n'est pas critique pour le go/no-go 16.14 — afficher "N/A" est acceptable. |
| **D14** | Périmètre exact des classes de jobs surveillées dans le dashboard E : uniquement `GenerateWineImageJob` + `WpkgGpoSynchronizer`-emitted jobs, ou plus large (tous les `App\Gpo\Jobs\*` + `App\Wpkg\Jobs\*`) ? | **Plus large** : tous les `App\Gpo\Jobs\*` + `App\Wpkg\Jobs\*` détectés par grep T0.6. La vue est admin, plus c'est exhaustif mieux c'est. | Si filter trop large pollue, restreindre au commit suivant. |
| **D15** | Driver queue actif : si ce n'est pas `database` mais `redis` ou `sync` (en local dev), comment le dashboard E gère ? | **Si non-database** : afficher un encart "Driver queue actuel = X — le dashboard nécessite le driver database pour fonctionner pleinement" + désactiver les actions retry/cancel. | Story n'est pas bloquée si dev en local — c'est la VM prod qui doit être `database`. À vérifier T0.6. |

---

## Anti-patterns / Pièges connus

1. **Ordre des routes** : `by-ou` et `sections` **DOIVENT** être déclarées **AVANT** la route paramétrée `/{guid}` dans le groupe `settings/gpo` — sinon `/by-ou` est matché comme un GUID malformé et retourne 404. Cf. Story 16.6 fix #2 + 16.9 piège 1. Test archi anti-régression `Story1614RoutesTest`.
2. **Pas de modification de signature `NativeSectionResolver::resolve/hasMatch/buildUrl`** : on **ajoute** seulement `all()`. Toute autre modification casserait la base de tests 16.3a.
3. **Pas de nouveau Repository LDAP** : pour la vue inverse C, on consomme `OrganizationalUnitRepository::listAll()` existant — pas de nouveau repo.
4. **Pas de table Eloquent nouvelle** : conformément au garde-fou Epic 16 (AD = source de vérité). Le dashboard jobs interroge `jobs` + `failed_jobs` (tables Laravel standards).
5. **Pas de modification du contenu fonctionnel des pages 16.9** : on **enrichit** par du markup (card hero, panneau filtres, exports) — on ne **touche pas** à la logique métier des SFC existantes (services `App\Gpo\Services\*` intacts).
6. **Pas d'introduction de package Composer** : tout reste natif Laravel. Pas de `Maatwebsite/Excel`, pas de Spatie autre que ceux déjà installés.
7. **Pas de E2E navigateur** : iso-pattern 16.2/16.3a (pas d'infra Playwright).
8. **Pas de log via `\Log::*` direct** : passer par `GpoLogger` (16.1) pour tous les logs `gpo` channel.
9. **Pas de retrait du bouton "Éditer dans l'ancienne UI"** sur les pages détail GPO — il reste en cohabitation jusqu'à 16.13bis (refonte module migration).
10. **Pas de modification de la création GPO** : la création reste volontairement non exposée nativement (mémoire `project_no_native_gpo_creation` — Story 16-4 cancelled 2026-05-18).

---

## Risques

| # | Description | Sévérité | Mitigation |
|---|---|---|---|
| R1 | `OrganizationalUnitRepository::listAll()` retourne une liste vide en VM de test si l'AD n'est pas peuplé → vue inverse C inutilisable | 🟡 moyenne | Fallback : afficher un message "Aucune OU découverte dans l'AD. Vérifiez la sync AD." + lien vers `/admin/settings?tab=ad-sync`. |
| R2 | Driver queue `sync` en local dev → la table `jobs` est vide → dashboard E paraît cassé | 🟡 moyenne | T0.6 doit confirmer le driver. Si `sync`, afficher un encart explicatif. |
| R3 | Comptage postes affectés (C AC3.4) ne trouve pas de mapping OU dans `Workstation`/`WorkstationGroup` | 🟡 moyenne | Fallback "N/A" + documentation dans la vue. Ne bloque pas la story. |
| R4 | Le filtre "Statut santé" → `conflicting` peut faire N×N appels `samba-tool` si mal codé sur gros parc | 🟠 élevée | Cap performance : ne calculer `conflicting` que sur un sous-ensemble pré-filtré, ou limiter à un seuil (`if count($gpos) > 100, skip conflict detection + warning UI`). |
| R5 | Auto-complete OU sur parc 500+ OUs → latence UX | 🟡 moyenne | Debounce `300ms` + cap suggestions à 20 résultats max. Cache `listAll` 5 min déjà géré par le repo. |
| R6 | Export CSV avec caractères Unicode mal encodés ouverts dans Excel | 🟢 faible | BOM UTF-8 en début de fichier (AC2.4) — pattern éprouvé. |
| R7 | Polling 5s du dashboard E surcharge le DB en cas de N admins connectés simultanément | 🟢 faible | Acceptable pour le périmètre SambaEdu (≤ 5 admins simultanés réel). |

---

## File List prévisionnelle

### Fichiers créés

```
app/Gpo/Enums/GpoHealthStatus.php                                              ← AC2.x (enum statut santé)
app/Gpo/Support/GpoHealthStatusCalculator.php                                  ← AC2.x (service calcul statut)
app/Gpo/Support/GpoExportSerializer.php                                        ← AC2.4/2.5 (serializer CSV + JSON)
resources/views/components/molecules/gpo-onboarding-card.blade.php             ← AC1.x (card hero composant)
resources/views/pages/admin/settings/gpo/by-ou/index.blade.php                 ← AC3.x (SFC vue inverse)
resources/views/pages/admin/settings/gpo/sections/index.blade.php              ← AC4.x (catalogue sections)
resources/views/pages/admin/settings/system/jobs/index.blade.php               ← AC5.x (SFC dashboard jobs)
resources/views/pages/admin/settings/gpo/_partials/advanced-filters-panel.blade.php ← AC2.1-2.3 (partial filtres)
tests/Feature/Gpo/GpoIndexAdvancedFiltersTest.php                              ← AC7.1
tests/Feature/Gpo/GpoIndexExportTest.php                                       ← AC7.1
tests/Feature/Gpo/GpoByOuPageTest.php                                          ← AC7.1
tests/Feature/Gpo/GpoSectionsPageTest.php                                      ← AC7.1
tests/Feature/System/SystemJobsDashboardTest.php                               ← AC7.1
tests/Unit/Gpo/GpoHealthStatusCalculatorTest.php                               ← AC7.2
tests/Unit/Gpo/GpoExportSerializerTest.php                                     ← AC7.2
tests/Architecture/Story1614RoutesTest.php                                     ← AC7.3
```

### Fichiers modifiés

```
app/Gpo/Support/NativeSectionResolver.php                                      ← +méthode all() AC4.3
resources/views/pages/admin/settings/gpo/index.blade.php                       ← +card hero AC1.x + panneau filtres AC2.x + exports AC2.4/2.5
resources/views/components/organisms/sidebar.blade.php                         ← +2 liens GPO + lien Jobs système AC6.x
routes/web.php                                                                  ← +3 routes (by-ou, sections, system/jobs) AC3.1/4.1/5.1
docs/qa/domains/gpo.md                                                         ← +section Story 16.14 (8 scénarios smoke) AC9.1
_bmad-output/implementation-artifacts/sprint-status.yaml                       ← status ready-for-dev → review (en fin de dev)
_bmad-output/backlog.html                                                      ← status 16-14 → review (en fin de dev)
```

### Fichiers NON touchés (régression à éviter)

- `app/Gpo/Services/*` (tous les services métier intacts — pas de changement de signature `GpoService`, pas de modification `WineImageQueuer`/`WpkgGpoSynchronizer`/`AssociationsResolver`/etc.)
- `app/Gpo/Models/*`, `app/Gpo/Dto/*`, `app/Gpo/Events/*`, `app/Gpo/Jobs/*` (entités métier intactes)
- `resources/views/pages/admin/settings/gpo/[guid]/index.blade.php` (page détail 16.2/16.3a/16.5 — pas de modification, 16.14 ne touche que l'index + 3 nouvelles pages)
- `resources/views/pages/admin/settings/gpo/[guid]/links/index.blade.php` (page links 16.5 inchangée)
- `resources/views/pages/admin/settings/gpo/wine/index.blade.php` (page Wine 16.3c inchangée)
- `resources/views/pages/admin/settings/gpo/wpkg-deployment/index.blade.php` (page WPKG 16.6 inchangée)
- `config/sambaedu.php` (pas de modification `blocked_legacy_routes`)
- `legacy/modules/gpo/*` (pas de suppression)
- Migrations de DB (aucune nouvelle migration créée)

---

## Test Strategy

### Couverture par niveau

| Niveau | Périmètre | Fichier |
|---|---|---|
| **Unit** | Calcul statut santé GPO (healthy/orphaned/conflicting/stale) | `tests/Unit/Gpo/GpoHealthStatusCalculatorTest.php` (≥5 tests) |
| **Unit** | Serialization CSV/JSON exports | `tests/Unit/Gpo/GpoExportSerializerTest.php` (≥3 tests) |
| **Feature Livewire** | Filtres avancés index | `tests/Feature/Gpo/GpoIndexAdvancedFiltersTest.php` (≥6 tests) |
| **Feature Livewire** | Exports CSV/JSON | `tests/Feature/Gpo/GpoIndexExportTest.php` (≥3 tests) |
| **Feature Livewire** | Vue inverse by-ou | `tests/Feature/Gpo/GpoByOuPageTest.php` (≥4 tests) |
| **Feature Livewire** | Catalogue sections | `tests/Feature/Gpo/GpoSectionsPageTest.php` (≥2 tests) |
| **Feature Livewire** | Dashboard jobs | `tests/Feature/System/SystemJobsDashboardTest.php` (≥3 tests) |
| **Architecture** | Routes + ordre + permissions | `tests/Architecture/Story1614RoutesTest.php` (≥3 tests) |
| **Smoke VM (manuel)** | 8 scénarios QA listés en AC9.1 | `docs/qa/domains/gpo.md` § Story 16.14 |

### Outils

- **Pest** (déjà projet) pour Feature/Unit/Architecture.
- **Livewire::test()** pour tests Feature SFC (iso-pattern 16.2/16.9).
- **Queue::fake()** pour tester les dispatchs de jobs dans le dashboard E si besoin (sans toucher la DB).
- **Pas de Mockery** sauf si nécessaire pour `GpoService` lourd à instancier.

---

## Dev Agent Record

### Agent Model Used

`claude-sonnet-4-6` — worktree `16-14`, date 2026-05-20.

### Debug Log References

- T0.5 : colonne `ad_dn` confirmée présente sur `Workstation` (modèle Eloquent). Comptage via `Workstation::where('ad_dn', 'like', '%,' . $ouDn)->count()`. Fallback `N/A` implémenté via `Schema::hasColumn('workstations', 'ad_dn')`.
- T0.6 : driver queue = `sync` par défaut (`config/queue.php` — `env('QUEUE_CONNECTION', 'sync')`). Tables `jobs` / `failed_jobs` = non garanties (créées dynamiquement dans les tests Feature E). Dashboard E gère proprement via flag `driverUnsupported` + alerte D15.
- WinePrefixScanner : méthode `list()` (pas `scan()`) — corrigé dans `sections/index.blade.php` lors de la vérification de l'API réelle.
- Tests : pas de vendor/ dans le worktree 16-14 → tests vérifiés via `php -l` uniquement, exécution réelle déportée à la VM (iso-pattern 16.10/16.11/16.12/16.13).

### Completion Notes List

- **D11 (bloc Système sidebar)** → implémenté en nouveau bloc collapse « Système » avec fa-gears, `@checked(request()->is('admin/settings/system*'))`, lien "Jobs système" → `route('admin.system.jobs.index')`.
- **D12 (heuristique type GPO)** → préfixes pragmatiques : `se4_app`, `se4_machine` → Machine ; `se4_user` → User ; `se4_logon` → Script logon. Sinon → catégorie non filtrée (pas de coupure).
- **D13 (mapping Workstation ↔ OU)** → colonne `ad_dn` présente, comptage implémenté avec fallback `N/A` via `Schema::hasColumn`.
- **D14 (périmètre classes jobs)** → tous les `App\Gpo\Jobs\*` + `App\Wpkg\*` (préfixes larges), filtrés via `isWatchedJob()` dans la SFC E.
- **D15 (driver queue non-database)** → alerte `data-testid="driver-unsupported-alert"` affichée si driver != `database` / `beanstalkd`. Actions retry/cancel désactivées. Vérifié dans le test Feature `it_shows_driver_unsupported_alert_when_not_database`.
- Déviation mineure : D4 `stale` basé uniquement sur `versionNumber === 0` (pas de delta 90j — samba-tool ne fournit pas l'horodatage). Documenté dans `GpoHealthStatusCalculator`.
- Déviation mineure : `sections/index.blade.php` implémenté en SFC Livewire (pas Blade pur D2) car les compteurs dynamiques best-effort nécessitent la résolution de service dans `mount()` — pas de breaking change.
- `GpoLogger` localisé dans `app/Gpo/Support/GpoLogger.php` (pas `app/Gpo/GpoLogger.php`). API : `GpoLogger::action($type, $operationId, $context)` → `GpoActionLog::success()/failure()`.

### File List

#### Créés

```
app/Gpo/Enums/GpoHealthStatus.php
app/Gpo/Support/GpoHealthStatusCalculator.php
app/Gpo/Support/GpoExportSerializer.php
resources/views/components/molecules/gpo-onboarding-card.blade.php
resources/views/pages/admin/settings/gpo/_partials/advanced-filters-panel.blade.php
resources/views/pages/admin/settings/gpo/by-ou/index.blade.php
resources/views/pages/admin/settings/gpo/sections/index.blade.php
resources/views/pages/admin/settings/system/jobs/index.blade.php
tests/Feature/Gpo/GpoIndexAdvancedFiltersTest.php
tests/Feature/Gpo/GpoIndexExportTest.php
tests/Feature/Gpo/GpoByOuPageTest.php
tests/Feature/Gpo/GpoSectionsPageTest.php
tests/Feature/System/SystemJobsDashboardTest.php
tests/Unit/Gpo/GpoHealthStatusCalculatorTest.php
tests/Unit/Gpo/GpoExportSerializerTest.php
tests/Architecture/Story1614RoutesTest.php
```

#### Modifiés

```
app/Gpo/Support/NativeSectionResolver.php           ← +méthode all() AC4.3
resources/views/pages/admin/settings/gpo/index.blade.php  ← +card hero A + panneau filtres B + exports B + resetAllFilters() (#15)
resources/views/components/organisms/sidebar.blade.php    ← +Vue par OU + Sections natives + bloc Système AC6.x
routes/web.php                                       ← +3 routes (by-ou, sections, system/jobs) AC3.1/4.1/5.1
docs/qa/domains/gpo.md                              ← +section Story 16.14 (10 scénarios smoke) AC9.1
```

#### Modifiés post-review (2026-05-20)

```
app/Gpo/Support/GpoExportSerializer.php                                        ← +escapeCsvCell() CSV injection (#2)
resources/views/pages/admin/settings/gpo/by-ou/index.blade.php                 ← @js() wire:click (#3) + whitelist selectedOu (#17) + escape LIKE (#8)
resources/views/pages/admin/settings/gpo/_partials/advanced-filters-panel.blade.php ← @js() wire:click (#3) + bouton "Tout effacer" (#15)
resources/views/pages/admin/settings/system/jobs/index.blade.php               ← isDatabaseDriver() (#6) + WATCHED_CLASS_PREFIXES (#7) + Carbon timestamps (#10) + log failure cancel (#11)
resources/views/components/molecules/gpo-onboarding-card.blade.php             ← encart info hors conditionnel dismissed (#14)
resources/views/pages/admin/settings/gpo/index.blade.php                       ← +resetAllFilters() (#15)
tests/Unit/Gpo/GpoExportSerializerTest.php                                     ← +5 tests CSV injection (#2)
tests/Feature/Gpo/GpoIndexExportTest.php                                       ← vrais tests fonctionnels export (#4) : BOM, filtré, logs
tests/Feature/System/SystemJobsDashboardTest.php                               ← 2 branches cancel distinctes (#12) : pending→success, running→failure
```

## Corrections post-review (2026-05-20)

12 findings auto-corrigés par claude-sonnet-4-6 post-review opus :

1. **#2 CSV injection** — `GpoExportSerializer::escapeCsvCell()` préfixe `'` toute cellule string commençant par `=`, `+`, `-`, `@`, `\t`, `\r`. Appliqué à `displayName`, GUID, `path`, `health_status` dans `toCsvRows()`. +5 tests Unit.
2. **#3 XSS wire:click** — `wire:click="selectOu('{{ $suggestion }}')"` → `wire:click="selectOu(@js($suggestion))"` dans `by-ou/index.blade.php` et `advanced-filters-panel.blade.php`.
3. **#4 Tests export creux** — `GpoIndexExportTest.php` : 4 nouveaux tests fonctionnels (BOM UTF-8, export filtré, toast JSON, logs CSV+JSON via `Log::spy()`). Tests assertMethodExists conservés.
4. **#6 isDatabaseDriver() beanstalkd** — `return $this->queueDriver === 'database'` (comparaison stricte, suppression de beanstalkd et du `in_array` redondant).
5. **#7 WATCHED_CLASS_PREFIXES** — `App\Wpkg\` → commenté + note "à décommenter quand les jobs WPKG natifs existeront (`App\Wpkg\Jobs\`)". Aucun job WPKG natif confirmé.
6. **#8 + #17 LIKE injection + selectedOu whitelist** — Validation par whitelist (`in_array($ouDn, $this->allOus)`) en amont de tout call samba-tool/DB. Escape LIKE : `str_replace(['%', '_', '\\'], ...)` avant concaténation dans `countWorkstationsForOu()`.
7. **#10 Timestamps incohérents** — `refreshJobs()` : `created_at` (int Unix) → `Carbon::createFromTimestamp()->format('d/m/Y H:i:s')` stocké en `formatted_created_at` ; `failed_at` (SQL) → `Carbon::parse()->format(...)` stocké en `formatted_failed_at`. Vue utilise les champs pré-formatés.
8. **#11 Log cancel sémantique** — `$deleted === 0` : `$log->success(['outcome' => 'already_running'])` → `$log->failure(new \RuntimeException('Job already reserved by worker, cannot cancel'))`. Toast warning UX conservé.
9. **#12 Test cancel précision** — Séparé en 2 tests : `cancel_job_succeeds_when_job_is_pending` (asserte suppression DB + `Log::spy()`) et `cancel_job_emits_warning_when_already_running` (asserte non-suppression + log failure). Helper `ensureJobTables()` extrait.
10. **#14 Encart info dismissed** — Encart "Création GPO non exposée" sorti du `@if/$else` `dismissed` → toujours rendu en bas du composant `gpo-onboarding-card.blade.php`.
11. **#15 clearFilters() incomplet** — `resetAllFilters()` ajoutée (appelle `clearFilters()` + `resetAdvancedFilters()`). Bouton "Tout effacer" ajouté dans `advanced-filters-panel.blade.php` à côté de "Réinitialiser".

## Implémentation Q1-Q5 (post-arbitrage Henri 2026-05-20)

> Q1-Q5 tranchées par Henri 2026-05-20 → implémentation par claude-opus-4-7[1m] (worktree `16-14`). 5 décisions résolues, ≈ 540 LOC dont 280 de code + 260 de tests.

### Q1 — Filtre santé MULTI-valeur (checkbox group) — résolu

- `index.blade.php` : `string $filterHealthStatus = ''` → `array $filterHealthStatuses = []` (Url(keep:true)).
- `_partials/advanced-filters-panel.blade.php` : `<select>` remplacé par 4 checkboxes `data-testid="filter-health-{healthy|orphaned|conflicting|stale}"`.
- `applyFiltersAndPagination` : `in_array($status, $this->filterHealthStatuses, true)` (OR logique entre statuts cochés).
- `resetAdvancedFilters()` et le compteur `advancedFiltersCount` adaptés.
- Test ajouté : `GpoIndexHealthStatusTest::multiple_health_statuses_filter_simultaneously` (coche 2 statuts → 2 GPOs sur 3 attendues).

### Q2 — Cache 24h + warm-up 22h + invalidation lazy + bouton refresh — résolu

**Création** : `app/Gpo/Support/CachedGpoLookups.php` (singleton AppServiceProvider) — wrappe `GpoService::get` + `listContainers/getLinks` derrière `Cache::remember(... addHours(24))`. Driver portable via clé index `gpo:cache:index` (pas de `Cache::tags()` — incompatible `apc`/`file`/`database`).

API exposée :
- `getLinksFor(string $guid): list<GpoLink>` (cache 24h)
- `getVersionNumberFor(string $guid): ?int` (cache 24h)
- `forgetGpo(string $guid): void` (invalidation ciblée + log `gpo.cache.invalidate scope=single`)
- `forgetAll(): void` (flush via index + log `gpo.cache.invalidate scope=all`)
- `warmAll(): array{count, duration_ms, errors[]}` (itère `list()` + précharge)

**Commande** : `app/Console/Commands/GpoWarmCacheCommand.php` — signature `gpo:warm-cache {--force}`. Schedule `app/Console/Kernel.php` : `dailyAt('22:00')->withoutOverlapping()->runInBackground()`.

**Hooks d'invalidation câblés** :
- `GpoService::setLink` → `forgetGpo($gpoName)` post-succès (et idempotent).
- `GpoService::removeLink` → `forgetGpo($gpoName)` post-succès (skip si idempotent no-op).
- `GpoService::setInheritance` → `forgetAll()` (le DN OU peut impacter N GPOs).
- `GpoService::reorderLinks` → `forgetGpo($guid)` × N GUIDs réordonnés.
- `WpkgGpoSynchronizer::publish` → `forgetGpo($post->gpoGuid)` post-succès `import_gpo`.
- `RoamingProfileService::setExclusions($values, applyVersionBump=true)` → `forgetAll()` (legacy ne fournit pas le GUID exploitable).
- `GenerateWineImageJob::handle()` post-succès → `forgetAll()` (script shell peut bumper `se4_wine`).

**Bouton UI** : `<button wire:click="refreshHealthCache">` ajouté dans le slot `actions` de `index.blade.php` — appelle `forgetAll()` + `loadGpos()` + log `gpo.cache.flush actor_user_id`. Permission iso `server.admin`. Spinner via `wire:loading wire:target="refreshHealthCache"`.

**Précompute UI** : `index.blade.php::loadGpos()` appelle `precomputeHealthAndLinks()` qui peuple `$linksCountByGuid`, `$linkedContainersByGuid`, `$versionByGuid`, `$healthStatusByGuid`. Le filtre santé voit désormais les **vraies données** (finding #1 résolu).

**Tests** :
- `tests/Unit/Gpo/CachedGpoLookupsTest.php` — 6 tests (miss/hit, forgetGpo, forgetAll, warmAll, version cache).
- `tests/Feature/Console/GpoWarmCacheCommandTest.php` — 3 tests (zéro GPO, N GPOs, --force).
- `tests/Feature/Gpo/GpoCacheInvalidationTest.php` — 2 tests (setLink/removeLink → invalidation).
- `tests/Feature/Gpo/GpoIndexHealthStatusTest.php` — 5 tests (healthy / orphaned / stale / multi / refresh).

### Q3 — Filtre OU listing principal via cache Q2 — résolu

- `getFilteredCollection()` : remplacement de `str_contains($dn, $ou)` (faux) par lookup `$this->linkedContainersByGuid[$guid]` (vraies données cache).
- Match exact `containerDn === $ouFilter` OU sous-OU (`str_ends_with($containerDn, ',' . $ouFilter)`) OU parent (`str_ends_with($ouFilter, ',' . $containerDn)`).
- Validation : `in_array($this->filterOu, $this->allOus, true)` — sinon filtre ignoré (anti-injection URL).

### Q4 — Pagination dashboard jobs 20/page — résolu

- `system/jobs/index.blade.php` : ajout `$pendingPage`, `$failedPage`, `$totalPending`, `$totalFailed`. Méthodes `goToPendingPage(int)` / `goToFailedPage(int)` + propriétés computed `getPendingLastPageProperty()` / `getFailedLastPageProperty()`.
- `refreshJobs()` : slice via `array_slice(..., perPage)` ; cap `take(500)` remonté pour failed.
- Vue : 2 blocs `<x-molecules.pagination>` (composant réutilisable existant) — `data-testid="pending-pagination"` / `data-testid="failed-pagination"`.
- Polling Livewire préserve `$pendingPage`/`$failedPage` automatiquement.
- Test ajouté : `SystemJobsDashboardTest::paginates_jobs_dashboard_at_20_per_page` (25 jobs → page1=20, page2=5, page99→clamp à 2).

### Q5 — Exports `version_major`/`version_minor` via cache Q2 — résolu

- `GpoExportSerializer` : ajout colonne `version_status` (entre `version_minor` et `path_sysvol`) — valeurs `known` / `unknown`. Si `$gpo['versionNumber'] === null` → `version_major=0`, `version_minor=0`, `version_status='unknown'`.
- `index.blade.php::exportCsv/exportJson` : passent désormais `$linksCountByGuid` et `$healthStatusByGuid` (en `GpoHealthStatus` enum via `buildHealthEnumMap()`) au sérialiseur.
- Tests adaptés : index décalés (anciens [5][6][7] → [6][7][8]), 3 nouveaux tests sur `version_status` (`known` / `unknown` CSV + JSON).

### Fichiers Q1-Q5 (créés)

```
app/Gpo/Support/CachedGpoLookups.php
app/Console/Commands/GpoWarmCacheCommand.php
tests/Unit/Gpo/CachedGpoLookupsTest.php
tests/Feature/Console/GpoWarmCacheCommandTest.php
tests/Feature/Gpo/GpoCacheInvalidationTest.php
tests/Feature/Gpo/GpoIndexHealthStatusTest.php
```

### Fichiers Q1-Q5 (modifiés)

```
app/Console/Kernel.php                                    ← +schedule daily 22:00 gpo:warm-cache
app/Providers/AppServiceProvider.php                      ← +singleton CachedGpoLookups
app/Gpo/Services/GpoService.php                           ← +4 hooks invalidation (setLink/removeLink/setInheritance/reorderLinks)
app/Gpo/Services/WpkgGpoSynchronizer.php                  ← +1 hook invalidation post-publish
app/Gpo/Jobs/GenerateWineImageJob.php                     ← +1 hook invalidation post-handle (forgetAll)
app/Services/RoamingProfileService.php                    ← +1 hook invalidation post-setExclusions(applyVersionBump)
app/Gpo/Support/GpoExportSerializer.php                   ← +colonne version_status (Q5)
resources/views/pages/admin/settings/gpo/index.blade.php  ← Q1 multi + Q2 cache+bouton refresh + Q3 filtre OU + Q5 exports
resources/views/pages/admin/settings/gpo/_partials/advanced-filters-panel.blade.php  ← Q1 checkbox group santé
resources/views/pages/admin/settings/system/jobs/index.blade.php  ← Q4 pagination 20/page + 2 paginateurs
tests/Unit/Gpo/GpoExportSerializerTest.php                ← Q5 ajout 3 tests + adapt index décalés
tests/Feature/Gpo/GpoIndexAdvancedFiltersTest.php         ← Q1 adapt resetAdvancedFilters
tests/Feature/System/SystemJobsDashboardTest.php          ← Q4 ajout test pagination
```

### D11-D15 — statut post-Q1-Q5

| # | Décision | Statut | Note |
|---|---|---|---|
| D11 | Bloc sidebar Système | ✅ Résolue (impl initial) | Bloc collapse `<details>` fa-gears. |
| D12 | Heuristique type GPO | ✅ Résolue (impl initial) | Préfixes `se4_app/machine/user/logon`. |
| D13 | Mapping Workstation ↔ OU | ✅ Résolue (impl initial) | Colonne `ad_dn` + fallback N/A. |
| D14 | Périmètre classes jobs | ✅ Résolue post-review | `App\Gpo\Jobs\*` uniquement (Wpkg commenté — pas de jobs natifs). |
| D15 | Driver queue non-database | ✅ Résolue (impl initial) | Alerte `driver-unsupported-alert` + actions disabled. |
| Q1 | Filtre santé multi | ✅ Résolue 2026-05-20 (Henri) | Implémenté checkbox group + tests. |
| Q2 | Cache 24h + warm 22h | ✅ Résolue 2026-05-20 (Henri) | CachedGpoLookups + schedule + 7 hooks invalidation. |
| Q3 | Filtre OU listing | ✅ Résolue 2026-05-20 (Henri) | Précompute via cache Q2 + whitelist. |
| Q4 | Pagination jobs 20/page | ✅ Résolue 2026-05-20 (Henri) | 2 paginateurs via `<x-molecules.pagination>`. |
| Q5 | Exports version cache | ✅ Résolue 2026-05-20 (Henri) | Colonne `version_status` + cache. |

---

## Change Log

| Date | Auteur | Action |
|---|---|---|
| 2026-05-20 | SM (claude-opus-4-7[1m] worktree `16-14`) | Création de la story `16-14-ameliorations-ux-ui-admin-gpo.md`. 10 décisions D1-D10 tranchées au kickoff (Henri absent — SM tranche pour permettre dev autonome). 5 décisions ouvertes D11-D15 documentées (impact faible, fallbacks définis). 9 volets d'AC structurés (A onboarding + B filtres/exports + C vue inverse + D sections + E jobs + sidebar + tests + logs + doc QA). 8 phases de tasks T0-T8 ≈ 39 sous-tâches. Charge estimée 7-9j (cf. Tech Spec §5.7). Modèle dev recommandé : **sonnet** (cf. section finale). |
| 2026-05-20 | Dev Agent (claude-sonnet-4-6 worktree `16-14`) | Implémentation complète Story 16.14. 16 fichiers créés + 5 modifiés. 39/39 sous-tâches T0-T8 cochées. ≥30 tests écrits (7 Unit + 24 Feature Livewire + 6 Architecture). D11-D15 résolues : D11=nouveau bloc collapse « Système » sidebar, D12=préfixes se4_app/se4_machine/se4_user/se4_logon, D13=colonne ad_dn présente + fallback N/A, D14=App\Gpo\Jobs\*+App\Wpkg\* (large), D15=alerte driver-unsupported. Déviations : stale basé versionNumber=0 uniquement (pas de delta 90j, samba-tool sans horodatage) ; sections/index.blade.php en SFC Livewire (compteurs dynamiques nécessitent mount()), WinePrefixScanner::list() (pas scan()). Tests déportés VM (pas de vendor/ en worktree). Status → review. |
| 2026-05-20 | Code Review opus (claude-opus-4-7[1m]) | Code review adversariale. 23 findings : 3 critiques, 6 importants, 14 mineurs. 5 questions Q1-Q5 en attente Henri. |
| 2026-05-20 | Dev Agent post-review (claude-sonnet-4-6 worktree `16-14`) | 12/23 findings auto-corrigés (sans ambiguïté). 5 en attente Q1-Q5 (filtre santé cassé, select multiple, filtre OU listing, pagination jobs, version 0/0). 6 reportés/ignorés (mocks DB/Wine, test couplage interne, GpoLogger actor_user_id, tests archi superficiels, extractClassName fallback). 9 fichiers modifiés. Status maintenu `review` (Q1-Q5 ouvertes). |
| 2026-05-20 | Dev Agent Q1-Q5 (claude-opus-4-7[1m] worktree `16-14`) | Implémentation Q1-Q5 post-arbitrage Henri. Q1 = filtre santé checkbox-multi. Q2 = `CachedGpoLookups` 24h + cmd `gpo:warm-cache` + schedule 22:00 + 7 hooks invalidation (setLink/removeLink/setInheritance/reorderLinks + WpkgGpoSynchronizer + RoamingProfile + WineJob). Q3 = filtre OU listing via cache + whitelist. Q4 = pagination 20/page (pending + failed). Q5 = colonne `version_status` known/unknown. **2 fichiers créés** (CachedGpoLookups, GpoWarmCacheCommand) + **11 modifiés**. **4 fichiers tests créés** (CachedGpoLookupsTest, GpoWarmCacheCommandTest, GpoCacheInvalidationTest, GpoIndexHealthStatusTest) + **3 adaptés** = **16 nouveaux tests** ajoutés. Tests déportés VM (pas vendor/). Status maintenu `review` (validation manuelle VM requise — pas de transition `done` automatique). |

---

## Recommandation Modèle Dev

**Modèle recommandé : `sonnet` (claude-sonnet-4-6 ou équivalent)**

Justification (5 facteurs cumulés) :

1. **Patterns établis abondants** : les 5 améliorations s'appuient strictement sur des fondations livrées (16.1 GpoService, 16.3a NativeSectionResolver, 16.6 WpkgGpoSynchronizer, 16.9 routing UI admin). Aucun design architectural nouveau à inventer — c'est de la composition UI Livewire/Blade selon les conventions projet.
2. **Décisions pré-tranchées (D1-D10)** : 10 décisions de design verrouillées dans la story (routes, composants SFC vs Blade, schémas export, définitions statut santé, sources jobs, permissions, tests, i18n). Le dev applique sans re-débattre — terrain idéal pour sonnet rapide.
3. **Peu de logique métier critique** : pas de samba-tool écriture, pas d'AD mutation, pas de PKI, pas de cryptographie. C'est de la lecture (GpoService, NativeSectionResolver) + UI + exports. Pas de surface AD writable touchée.
4. **Tests bien cadrés** : ≥ 30 tests cibles avec patterns Pest/Livewire éprouvés (iso 16.2/16.9). Pas de fixtures complexes (DC AD non requis pour la majorité des tests — mocks GpoService suffisent).
5. **Charge cumulée 7-9j cohérente avec sonnet** : 5 sous-features de 1-3j chacune, chacune autonome (parallélisables conceptuellement même si livrées en séquence). Pas de blocage cascade entre les volets.

**Conditions de bascule vers opus** :
- Si T0.6 révèle que le driver queue est exotique (Redis cluster, SQS) → impact archi sur E + investigation → opus pour décider du fallback.
- Si T0.5 montre que le mapping Workstation ↔ OU nécessite une migration de schéma DB → escalade Henri obligatoire (hors scope 16.14).
- Si la détection `conflicting` en filtre statut santé impose un cache table (calcul N×N coûteux sur parc 200+ GPOs) → discussion D4 à rouvrir, possiblement opus.

**Charge attendue avec sonnet** : 7-9j (cf. Tech Spec §5.7) — dans la fenêtre confortable.

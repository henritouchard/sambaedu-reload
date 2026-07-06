# Story 40.2 : Domaine Guide « Machines » (catégorie `computer`)

Status: review

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Contexte epic

**Epic 40 — « Guide des fonctionnalités »** (en cours). La story **40.1 (socle)** a livré : le hub `/app/guide`, le composant réutilisable `x-molecules.feature-guide-item` (gating « afficher + verrouiller », décision d'accès injectable), le registre `App\Support\Help\FeatureGuideRegistry`, et le domaine pilote « Utilisateurs ». **Cette story 40.2 est un incrément qui RÉUTILISE ce socle sans le refondre** : elle ajoute le **2e domaine documenté = « Machines »** (catégorie `computer` de `SambaPermission`).

**Dépendances** : 40.1 (`to-validate` / socle figé, commit branche `guides`). Travaille sur la même branche `guides`.

Décisions de cadrage (Henri, 2026-07-06) :
- **Gating du guide = `can()` GLOBAL**, même si les permissions `computer.*` sont **délégables scopées WorkstationGroup** (`PermissionService::canOnWorkstationGroup`). Un guide de documentation répond à « as-tu ce droit *quelque part* ? », pas « sur quel parc ». Le mode scopé reste **hors-sujet** ici : on garde la prop `unlocked` du composant à son défaut (`can()`), on ne l'injecte pas. (Le socle a été conçu pour permettre ce mode plus tard sans réécriture — on ne l'active pas.)
- Le domaine « Machines » = **catégorie `computer` uniquement** (5 permissions). La catégorie `wpkg` (« Applications WPKG ») est un **domaine distinct** → story 40.3 séparée, hors périmètre.

## Story

En tant qu'**utilisateur connecté (technicien, admin machines, référent numérique…)**,
je veux **un guide how-to du domaine « Machines » listant les fonctionnalités de gestion du parc, avec celles auxquelles je n'ai pas droit affichées mais verrouillées**,
afin de **savoir comment piloter les postes pas-à-pas et voir d'un coup d'œil ce que je peux faire sur le parc**.

## Acceptance Criteria

1. **Route page domaine** — Ajouter `Route::livewire('/guide/machines', 'pages::guide.machines.index')->name('guide.machines')` dans le groupe `Route::prefix('app')->…->name('app.')` de `routes/web.php`, **à côté** de `app.guide.utilisateurs`, **sans** middleware `can:` (gating intra-page). Nom final : `app.guide.machines`.

2. **Page domaine `/app/guide/machines`** — SFC `resources/views/pages/guide/machines/index.blade.php` calquée sur `pages/guide/utilisateurs/index.blade.php` : rend les **5 permissions de la catégorie `computer`** (`computer.view`, `computer.control`, `computer.elevate`, `computer.install`, `computer.remote.rdp`) via `x-molecules.feature-guide-item`, avec bouton retour vers `route('app.guide')`. La catégorie est **ancrée sur l'enum** (`SambaPermission::ComputerView->category()`), pas de littéral `'computer'`. Réutilise la garde `Route::has()` déjà en place dans la page utilisateurs.

3. **Contenu how-to des 5 permissions `computer`** — Enrichir `App\Support\Help\FeatureGuideRegistry::all()` avec une section « Machines » : pour chacune des 5 permissions, `{objective, steps[], route, routeLabel}`. Étapes numérotées concrètes + lien vers la vraie page (vérifier les noms de route réels dans `routes/web.php` : `app.parc.index` = liste/gestion du parc ; pages groupes/machines sous le groupe `app.parc.*` ; installation via le parcours parc). **Ne duplique aucun `label()`/`category()`** (portés par l'enum). Le fallback « Guide à venir » du socle couvre toute permission non rédigée — ne pas le casser.

4. **Hub : rendre le domaine « Machines » cliquable** — Aujourd'hui, `resources/views/pages/guide/index.blade.php` calcule `'available' => $category === SambaPermission::UserRead->category()` (un seul domaine documenté en dur). **Généraliser** cette logique en une source de vérité **data-driven** des domaines documentés : une correspondance `catégorie → nom de route de la page domaine` (p. ex. `user → app.guide.utilisateurs`, `computer → app.guide.machines`). Un domaine est `available` s'il figure dans cette table **et** que sa route existe (`Route::has(...)`). La carte cliquable doit pointer vers la bonne route (plus de `route('app.guide.utilisateurs')` codé en dur). Emplacement de cette table au choix (méthode `protected` du composant hub, ou méthode statique sur `FeatureGuideRegistry` type `documentedDomains(): array<string,string>` — préférer cette dernière pour que 40.3+ n'aient qu'à l'étendre). Les domaines non documentés restent en « Bientôt disponible ».

5. **Gating inchangé** — Les 5 items de la page « Machines » utilisent le comportement par défaut du composant (`unlocked` non fourni → `auth()->user()->can($permission->value)`, **global**). Aucune logique scopée WorkstationGroup n'est ajoutée. Non-masquage strict : les 5 items sont TOUJOURS rendus.

6. **Tests** — `tests/Feature/Guide/GuideMachinesTest.php` (ou étendre `GuideTest.php` — préférer un fichier dédié au domaine, cohérent avec le style) couvrant :
   - `/app/guide/machines` = 200 pour un utilisateur authentifié sans droit ; invité → redirect `auth.login`.
   - La page liste **exactement 5 fonctionnalités** `computer` quel que soit le rôle (non-masquage).
   - Un **`technicien`** (a `computer.view` + `computer.control`) → **2 déverrouillées / 3 verrouillées**.
   - Un **`computer-admin`** (a les 5 perms `computer` : view, control, elevate, install, remote.rdp) → **5 déverrouillées / 0 verrouillée**.
   - Un **`eleve`** → **5 verrouillées**.
   - Hub : pour un `technicien`, la carte « Machines » (`data-testid="domain-computer"`) est **cliquable** (porte un `href` vers `route('app.guide.machines')`) et affiche le compteur « 2 / 5 accessibles ». Vérifier aussi que la carte « Utilisateurs » reste cliquable (non-régression 40.1).
   - Réutiliser le pattern de seeding Spatie + `forgetCachedPermissions` de `GuideTest.php`.

7. **Runbook QA** — Enrichir `docs/qa/domains/guide.md` en **append-only** : nouvelle section « Machines » avec scénarios numérotés (vérification du verrouillage par rôle technicien/computer-admin, navigation vers la vraie page parc depuis un item déverrouillé, non-masquage, carte hub cliquable). Ne PAS créer de fichier par story.

## Tasks / Subtasks

- [x] **Task 1 — Route** (AC1) : ajouter `app.guide.machines` dans le groupe `app.` de `routes/web.php`.
- [x] **Task 2 — Registre how-to `computer`** (AC3) : 5 entrées dans `FeatureGuideRegistry` (objectif + étapes + route réelle vérifiée). Si tu ajoutes `documentedDomains()` (Task 4), place-la ici.
- [x] **Task 3 — Page domaine** (AC2, AC5) : `pages/guide/machines/index.blade.php`, calquée sur la page utilisateurs, catégorie ancrée sur l'enum, garde `Route::has()`.
- [x] **Task 4 — Généraliser le hub** (AC4) : remplacer le `=== UserRead->category()` codé en dur par une table `catégorie → route` (idéalement `FeatureGuideRegistry::documentedDomains()`), carte cliquable pointant vers la route du domaine. Non-régression du domaine « Utilisateurs ».
- [x] **Task 5 — Tests** (AC6) : `GuideMachinesTest` (technicien 2/5, computer-admin 5/5, eleve 5/5 verrouillé, invité refusé, hub cliquable) + non-régression carte Utilisateurs.
- [x] **Task 6 — Runbook QA** (AC7) : section « Machines » append-only dans `docs/qa/domains/guide.md`.
- [x] **Task 7 — Finalisation** : cocher les tâches, remplir Dev Agent Record (File List), passer la story en `review`, mettre à jour `sprint-status.yaml` (`40-2-domaine-guide-machines: review`) sans casser le YAML.

## Dev Notes

### Socle 40.1 à réutiliser (NE PAS réimplémenter)
- **Composant** : `resources/views/components/molecules/feature-guide-item.blade.php` — props `permission` (SambaPermission), `objective`, `steps`, `link`, `linkLabel`, `unlocked` (défaut `null → can()`). Rend toujours ; verrouillé = `<span>` sans href + cadenas + « Droit requis : {label} ». `data-testid` = `feature-{perm}` et `feature-lock-{perm}`. **Ne le modifie pas** sauf nécessité prouvée.
- **Registre** : `app/Support/Help/FeatureGuideRegistry.php` — `all()` (cache statique), `forPermission()`, `has()`. Tu AJOUTES les 5 entrées `computer` dans `all()`.
- **Page modèle** : `resources/views/pages/guide/utilisateurs/index.blade.php` — copie sa structure (`features()` `#[Computed]`, ancrage catégorie enum, garde `Route::has()`, wrapper `x-organisms.page` avec `back`).
- **Hub** : `resources/views/pages/guide/index.blade.php` — `domains()` itère `groupedByCategory()` et calcule `accessible`/`total` via `can()`. C'est le `available` qu'il faut généraliser (Task 4).

### Enum de référence
- `app/Enums/SambaPermission.php` — catégorie `computer` = 5 cases : `ComputerView` (`computer.view`, « Voir les machines »), `ComputerControl` (`computer.control`, « Contrôle à distance »), `ComputerElevate` (`computer.elevate`, « Admin de poste »), `ComputerInstall` (`computer.install`, « Installer un poste »), `ComputerRemoteRdp` (`computer.remote.rdp`, « Bureau à distance (RDP) »). `categoryLabel('computer')` = « Machines ».
- `app/Enums/SambaRole.php` — rôles pour les tests : `technicien` → `computer.view` + `computer.control` + `wpkg.assign` (⇒ **2/5** sur la catégorie `computer`) ; `computer-admin` → les 5 `computer.*` (⇒ **5/5**) ; `eleve` → aucune (⇒ **0/5**).

### Routes réelles machines (à vérifier avant d'écrire le registre)
- `app.parc.index` (`routes/web.php`, groupe `Route::prefix('parc')->name('parc.')`, `/` → `pages::parc.index`) = gestion du parc (liste machines/groupes).
- Détail machine : `pages::parc.machines.[id].index` (`/parc/machines/{id}`) — vérifie son nom exact (`app.parc.machines.show` ou équivalent) avant de l'utiliser ; sinon pointe la liste `app.parc.index`.
- Installation de poste : parcours parc (pas de page dédiée simple) → pointer `app.parc.index` et décrire l'action dans les étapes. En cas de doute sur un nom de route, la garde `Route::has()` évite le 500, mais **préfère une route existante** (vérifie avec `grep -n "->name(" routes/web.php`).

### Gating — pourquoi global
Les permissions `computer.*` sont `isDelegatable()` (scopées WorkstationGroup dans le reste de l'app). **Pour le guide, on reste sur `can()` global** : le composant `feature-guide-item` sans prop `unlocked` fait exactement ça. Ne PAS appeler `PermissionService::canOnWorkstationGroup()` ici. C'est un choix produit (un guide n'est pas contextualisé à un parc). Le socle garde la porte ouverte (prop injectable) pour un éventuel besoin futur, non requis ici.

### Contraintes projet
- Tests sur l'HÔTE (php8.4 + pdo_sqlite) — **mais aucun runtime PHP n'est actuellement dispo sur cet hôte** : écris les tests sur les patterns verts de `GuideTest.php`, ils seront rejoués sur un env PHP (`php artisan test --filter=Guide`). Ne bloque pas la livraison là-dessus.
- Aucun commit/git add (l'orchestrateur gère). Pas de `route:cache`/`config:cache` (VM).
- Rappel Completion Notes : la nouvelle route nécessitera `route:cache` + `chown www-admin` côté VM.
- Conventions : SFC in-line Livewire 4 (`#[Computed]`, `#[Title]`), Tailwind/daisyUI, français.

### Project Structure Notes
- Nouveaux fichiers : `resources/views/pages/guide/machines/index.blade.php`, `tests/Feature/Guide/GuideMachinesTest.php`.
- Modifs : `routes/web.php` (+1 route), `app/Support/Help/FeatureGuideRegistry.php` (+5 entrées computer, éventuellement +`documentedDomains()`), `resources/views/pages/guide/index.blade.php` (généralisation `available`), `docs/qa/domains/guide.md` (append).
- Aucune modif du composant `feature-guide-item` ni de la sidebar attendue.

### References
- [Source: _bmad-output/implementation-artifacts/40-1-socle-guide-et-domaine-pilote-utilisateurs.md] — story du socle (contrat du composant, du registre, du hub).
- [Source: app/Enums/SambaPermission.php] — catégorie `computer` (5 perms), `category()`, `categoryLabel()`, `groupedByCategory()`.
- [Source: app/Enums/SambaRole.php] — technicien / computer-admin / eleve.
- [Source: resources/views/pages/guide/utilisateurs/index.blade.php] — page domaine modèle.
- [Source: resources/views/pages/guide/index.blade.php] — hub à généraliser (`available`).
- [Source: app/Support/Help/FeatureGuideRegistry.php] — registre à enrichir.
- [Source: routes/web.php] — groupe `app.parc.*` (routes machines réelles).
- [Source: docs/qa/domains/guide.md] — runbook QA à enrichir (append-only).

## Dev Agent Record

### Agent Model Used

claude-opus-4-8[1m]

### Debug Log References

Aucun runtime PHP disponible sur l'hôte : tests écrits sur les patterns verts de
`GuideTest.php`, **non exécutés localement**. À rejouer sur env PHP :
`php artisan test --filter=Guide`.

### Completion Notes List

- **AC1** — Route `app.guide.machines` (`/guide/machines`) ajoutée dans le groupe
  `app.` de `routes/web.php`, immédiatement après `app.guide.utilisateurs`, sans
  middleware `can:` (gating intra-page).
- **AC2/AC5** — Page `resources/views/pages/guide/machines/index.blade.php`
  calquée sur la page utilisateurs : `features()` `#[Computed]`, catégorie ancrée
  sur l'enum (`SambaPermission::ComputerView->category()`, pas de littéral
  `'computer'`), garde `Route::has()`, wrapper `x-organisms.page` avec
  `back=route('app.guide')`. Gating = défaut du composant (`can()` GLOBAL) —
  aucune prop `unlocked`, aucune logique scopée WorkstationGroup, aucun appel à
  `PermissionService::canOnWorkstationGroup()`.
- **AC3** — 5 entrées `computer` (`view`, `control`, `elevate`, `install`,
  `remote.rdp`) ajoutées à `FeatureGuideRegistry::all()` : objectif + étapes
  numérotées + lien. **Toutes** pointent `app.parc.index` (route sans paramètre,
  confirmée dans `routes/web.php` groupe `parc.`). Choix : la fiche machine
  `app.parc.machines.show` exige un `{id}` → inutilisable en lien générique
  (`route()` lèverait même avec `Route::has()` vrai). Aucun `label()`/`category()`
  dupliqué ; fallback « Guide à venir » intact.
- **AC4** — Hub généralisé : nouvelle méthode statique
  `FeatureGuideRegistry::documentedDomains(): array<string,string>` (table
  `catégorie → nom de route`, clés ancrées via `SambaPermission::…->category()` :
  `user → app.guide.utilisateurs`, `computer → app.guide.machines`). `domains()`
  calcule `available = isset(table[cat]) && Route::has(route)` et expose la clé
  `route` (URL résolue). La vue suit `$domain['route']` (plus de
  `route('app.guide.utilisateurs')` codé en dur). Domaines non documentés →
  « Bientôt disponible ». Non-régression Utilisateurs préservée.
- **AC6** — `tests/Feature/Guide/GuideMachinesTest.php` (accès 200 sans droit,
  invité redirigé, 5 features quel que soit le rôle, technicien 2/3,
  computer-admin 5/0, eleve 0/5, hub carte Machines cliquable + « 2 / 5 » +
  non-régression carte Utilisateurs). Comptage propre : `feature-computer.` vs
  `feature-lock-computer.` (pas de double-comptage). **Régression corrigée dans
  `GuideTest.php`** : le test `hub_users_domain_is_clickable_and_others_are_coming_soon`
  ciblait `domain-computer` comme « Bientôt disponible » — désormais documenté,
  bascule sur `domain-share` (encore non documenté).
- **AC7** — `docs/qa/domains/guide.md` enrichi en append-only : Section 4
  « Machines » (Sc. 4.1→4.7) + entrées de checklist. Aucun fichier par story.
- **Rappel VM** : la nouvelle route `app.guide.machines` nécessitera, côté VM,
  `php artisan route:cache` **puis** `chown www-admin:www-admin bootstrap/cache/*.php`
  (sinon catch-all legacy → 404). Aucun `route:cache`/`config:cache` exécuté ici.
- **Tests non rejoués** (pas de PHP sur l'hôte) : `php artisan test --filter=Guide`
  à lancer sur env PHP avant merge.

### File List

**Créés :**
- `resources/views/pages/guide/machines/index.blade.php`
- `tests/Feature/Guide/GuideMachinesTest.php`

**Modifiés :**
- `routes/web.php` (+1 route `app.guide.machines`)
- `app/Support/Help/FeatureGuideRegistry.php` (+5 entrées `computer`, +méthode `documentedDomains()`)
- `resources/views/pages/guide/index.blade.php` (généralisation `available` data-driven + carte cliquable dynamique)
- `tests/Feature/Guide/GuideTest.php` (régression : cible `domain-share` au lieu de `domain-computer` désormais documenté)
- `docs/qa/domains/guide.md` (Section 4 « Machines » append-only + checklist)
- `_bmad-output/implementation-artifacts/40-2-domaine-guide-machines.md` (tasks cochées, Dev Agent Record, Status → review)
- `_bmad-output/implementation-artifacts/sprint-status.yaml` (`40-2-domaine-guide-machines: review`)

# Story 40.1 : Socle du Guide des fonctionnalités (documentation how-to gatée) + domaine pilote « Utilisateurs »

Status: review

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Contexte epic (Epic 40 — nouveau)

**Epic 40 — « Guide des fonctionnalités : documentation fonctionnelle gatée par permissions »**

Objectif métier : exposer à chaque utilisateur connecté une documentation **how-to** (guides pas-à-pas) des fonctionnalités de SambaEdu, **classée par domaine fonctionnel**, où chaque fonctionnalité est affichée mais **verrouillée** si l'utilisateur ne dispose pas de la permission Spatie requise. But : rendre l'outil auto-explicatif et donner à chaque profil une vue « ce que je peux faire / ce qui existe mais m'est fermé », sans jamais masquer l'existence d'une fonctionnalité.

Décisions de cadrage actées avec Henri (2026-07-06) :
- **Axe de classement** = **domaine fonctionnel** (= les catégories de `SambaPermission` : Utilisateurs, Partages, Lecteurs réseau, Règles d'accès dossiers, Machines, WPKG, Serveur, Fonds d'écran, Personnalisation apps). Contenu au **format how-to**.
- **Comportement du gating** = **afficher + verrouiller** (tout visible ; fonctionnalité non accessible = grisée + badge « Verrouillé » + rappel du droit requis). **Jamais de masquage.**
- **Périmètre de CETTE story (40.1)** = fondation réutilisable (hub + mécanisme de gating + navigation) **+ 1 domaine pilote entièrement documenté : « Utilisateurs »**. Les autres domaines suivront en stories 40.2, 40.3… (une par domaine), qui réutiliseront le socle livré ici.

Cette story est la **première** de l'Epic 40 : elle fait passer `epic-40` à `in-progress` dans `sprint-status.yaml`.

## Story

En tant qu'**utilisateur connecté de SambaEdu (quel que soit son rôle : élève, professeur, technicien, référent numérique, administrateur…)**,
je veux **un Guide des fonctionnalités accessible depuis la navigation, présentant les fonctionnalités regroupées par domaine sous forme de guides how-to, avec les fonctionnalités que je ne peux pas utiliser clairement marquées « verrouillées »**,
afin de **comprendre ce que l'outil permet, comment réaliser chaque action pas-à-pas, et voir d'un coup d'œil ce à quoi j'ai droit et ce qui existe mais m'est fermé**.

## Acceptance Criteria

1. **Route + page hub `/app/guide`** — Une route `Route::livewire('/guide', 'pages::guide.index')->name('guide')` est ajoutée **à l'intérieur** du groupe `Route::prefix('app')->middleware(['sambaedu.auth', 'federated.audit'])->name('app.')` de `routes/web.php`. La page est accessible à **tout utilisateur authentifié** : **aucun** middleware `can:` ni guard `Gate::allows()` bloquant dans `mount()` (le Guide des fonctionnalités n'est jamais fermé — c'est son contenu qui est gaté). Nom de route final : `app.guide`.

2. **Entrée de navigation** — Un lien « Guide » (icône `fa-solid fa-circle-question` ou équivalent) est ajouté dans `resources/views/components/organisms/sidebar.blade.php`, **visible pour tous** (donc **hors** de tout bloc `@can`), pointant vers `route('app.guide')`, avec l'état actif géré via `request()->is('app/guide*')` comme les autres entrées.

3. **Hub = domaines fonctionnels** — La page `/app/guide` liste les **domaines fonctionnels**, un par catégorie retournée par `SambaPermission::groupedByCategory()` (libellé via `SambaPermission::categoryLabel()`). Chaque domaine est rendu sous forme de carte (réutiliser `x-molecules.settings-section` / `x-molecules.settings-card`, ou un composant dédié cohérent avec l'existant). Chaque carte affiche, **pour l'utilisateur connecté**, un compteur « X accessibles / Y au total » (nombre de fonctionnalités du domaine que l'utilisateur peut utiliser vs total). Le domaine pilote « Utilisateurs » pointe vers `route('app.guide.utilisateurs')` ; les autres domaines (non encore documentés) sont présents mais leur carte est marquée « Bientôt disponible » (désactivée, sans lien mort) — le socle reste extensible sans les faire disparaître.

4. **Mécanisme de gating réutilisable** — Un composant Blade réutilisable (ex. `x-molecules.feature-guide-item` sous `resources/views/components/molecules/`) rend **une fonctionnalité documentée** à partir de : son intitulé, son objectif, ses étapes how-to, le lien vers la vraie page, et la `SambaPermission` requise. Il rend **deux états** :
   - **Déverrouillé** si `auth()->user()->can($permission->value)` est vrai : contenu how-to complet + lien actif vers la fonctionnalité réelle.
   - **Verrouillé** sinon : carte **grisée** (opacité réduite), badge « Verrouillé » (icône cadenas), mention explicite du droit requis (via `$permission->label()`), lien vers la fonctionnalité réelle **désactivé**. Le contenu how-to reste lisible (pédagogique). **Aucun masquage.**
   Ce composant est le point de réutilisation pour toutes les futures stories 40.x.

5. **Domaine pilote « Utilisateurs »** — Une page `/app/guide/utilisateurs` (`Route::livewire('/guide/utilisateurs', 'pages::guide.utilisateurs.index')->name('guide.utilisateurs')`, nom final `app.guide.utilisateurs`) documente en **format how-to** les **6 permissions de la catégorie `user`** : `user.read`, `user.password.init`, `user.modify`, `user.create.temp`, `user.assign.right`, `user.delegate`. Chaque fonctionnalité est rendue via le composant de l'AC4 avec : un titre, un objectif (1 phrase), des **étapes numérotées** concrètes, et un lien vers la page réelle correspondante (ex. `route('app.users')`, `route('app.rights-management')`). L'état verrouillé/déverrouillé est appliqué par fonctionnalité selon la permission Spatie de l'utilisateur connecté.

6. **Catalogue ancré sur l'enum, pas de duplication de libellés** — La source des fonctionnalités documentées s'appuie sur `SambaPermission` (`label()`, `category()`, `categoryLabel()`, `groupedByCategory()`) : les intitulés et le rattachement au domaine **ne sont pas ré-écrits en dur**. Seul le **contenu how-to** (objectif + étapes + lien) est rédigé, dans une structure dédiée (au choix du dev, cohérent avec l'existant : registre PHP — p. ex. une classe `App\Support\Help\FeatureGuideRegistry` ou un enum/array — mappant chaque `SambaPermission` vers son how-to). Une permission **sans** how-to rédigé doit rester listée (fallback : intitulé + « Guide à venir »), pour que le hub reste exhaustif sur les 24 permissions.

7. **Gating = autorisation Spatie globale** — Le verrouillage utilise l'autorisation Spatie globale (`$user->can()` / `Gate::allows()`), cohérente avec le gating existant (`@can` dans le reste de l'UI). Pour le pilote « Utilisateurs », les permissions sont **globales** (non scopées WorkstationGroup) : `$user->can('user.read')` suffit. **Note pour les stories futures** : les domaines `computer`/`wpkg` portent des permissions **délégables scopées WorkstationGroup** (`PermissionService::canOnWorkstationGroup()`) ; le composant de l'AC4 doit rester compatible avec un futur mode « scopé » (prévoir que la décision d'accès soit injectée/paramétrable plutôt que codée en dur sur `can()` uniquement) — sans l'implémenter dans cette story.

8. **Tests** — Des tests Feature/Livewire couvrent au minimum :
   - `/app/guide` répond **200 pour tout utilisateur authentifié** sans aucune permission (ex. un `eleve`), et **redirige/refuse** un invité non authentifié (middleware `sambaedu.auth`).
   - La page `/app/guide/utilisateurs` liste **exactement 6 fonctionnalités** (catégorie `user`) et **aucune n'est masquée** quel que soit le rôle.
   - Un **`prof`** (permissions `user.read` + `user.password.init`) voit **2 fonctionnalités déverrouillées** et **4 verrouillées** (badge « Verrouillé » présent).
   - Un **`eleve`** (0 permission) voit **6 verrouillées**.
   - Un **`super-admin`** voit **6 déverrouillées**.
   - Le hub `/app/guide` affiche un compteur cohérent par domaine pour un rôle donné (ex. pour `prof`, domaine « Utilisateurs » = « 2 / 6 »).

## Tasks / Subtasks

- [x] **Task 1 — Route & navigation du hub** (AC: 1, 2)
  - [x] Ajouter `Route::livewire('/guide', 'pages::guide.index')->name('guide')` dans le groupe `app.` de `routes/web.php` (sans middleware `can:`).
  - [x] Ajouter l'entrée « Guide » (hors `@can`) dans `sidebar.blade.php` avec état actif `request()->is('app/guide*')`.
  - [x] Note VM : après ajout de route, penser `route:cache` + `chown www-admin` (cf. contrainte projet routes cachées) — ne PAS sync manuellement.
- [x] **Task 2 — Catalogue how-to ancré sur l'enum** (AC: 6)
  - [x] Créer la structure de contenu how-to (ex. `App\Support\Help\FeatureGuideRegistry`) mappant `SambaPermission` → { objectif, étapes[], lien route }. Ne PAS dupliquer `label()`/`category()`.
  - [x] Rédiger le how-to des **6 permissions `user`** (objectif + étapes numérotées + lien vers la vraie page).
  - [x] Prévoir un fallback « Guide à venir » pour toute permission sans how-to (les 24 doivent rester listables).
- [x] **Task 3 — Composant de gating réutilisable** (AC: 4, 7)
  - [x] Créer `x-molecules.feature-guide-item` : props (permission `SambaPermission`, objectif, étapes, lien). Rend état déverrouillé (`can()` vrai) vs verrouillé (grisé + badge cadenas + droit requis via `label()` + lien désactivé).
  - [x] Garder la décision d'accès **paramétrable** (injectable) pour compat future avec le mode scopé WorkstationGroup — sans l'implémenter.
- [x] **Task 4 — Page hub `/app/guide`** (AC: 3)
  - [x] SFC `pages/guide/index.blade.php` : itérer `SambaPermission::groupedByCategory()`, une carte par domaine, compteur « accessibles / total » calculé pour `auth()->user()`.
  - [x] Domaine « Utilisateurs » → lien `route('app.guide.utilisateurs')` ; autres domaines → carte « Bientôt disponible » désactivée.
- [x] **Task 5 — Page domaine pilote `/app/guide/utilisateurs`** (AC: 5)
  - [x] Route `Route::livewire('/guide/utilisateurs', 'pages::guide.utilisateurs.index')->name('guide.utilisateurs')`.
  - [x] SFC `pages/guide/utilisateurs/index.blade.php` : rendre les 6 fonctionnalités `user` via `x-molecules.feature-guide-item`, alimentées par le registre (Task 2).
  - [x] Bouton retour vers `/app/guide` (prop `back` du `x-organisms.page`).
- [x] **Task 6 — Tests** (AC: 8)
  - [x] Feature tests : accès hub (authentifié sans droit = 200 ; invité = refus).
  - [x] Tests de gating par rôle : `eleve` (0/6), `prof` (2/6), `super-admin` (6/6), aucun item masqué.
  - [x] Test compteur hub par domaine pour un rôle donné.

## Dev Notes

### Modèle d'autorisation (socle, déjà livré — NE PAS réinventer)

- **Package** : `spatie/laravel-permission` v6.24. Config `config/permission.php` (wildcard OFF, cache 24h).
- **`app/Enums/SambaPermission.php`** — 24 permissions, 9 catégories. Méthodes à réutiliser telles quelles :
  - `label(): string` (libellé FR par permission), `category(): string`, `categoryLabel(string): string`,
  - `groupedByCategory(): array<string,{label, permissions: SambaPermission[]}>` ← **source du hub**,
  - `value` = nom dot-notation (`'user.read'`, …) à passer à `$user->can()`.
  - Catégories : `user` (6), `share` (3), `network-share` (2), `folder-rule` (2), `computer` (5), `wpkg` (3), `server` (1), `wallpaper` (1), `app-customization` (1).
- **`app/Enums/SambaRole.php`** — rôles seedés + `permissions()`. Utile pour les tests (assigner un rôle à un user de test donne le bon set de permissions). Rappels pilote :
  - `prof` → `user.read`, `user.password.init` (⇒ 2/6 sur le domaine Utilisateurs).
  - `eleve` → aucune permission (⇒ 0/6).
  - `super-admin` → toutes (⇒ 6/6).
- **`$user->can('permission.value')`** (trait Spatie `HasRoles` sur `App\Models\User`) est le check global. `Gate::allows()` équivalent côté Blade/PHP.
- **Permissions délégables scopées** (`computer`/`wpkg`) : `App\Services\PermissionService::canOnWorkstationGroup(User, string $perm, WorkstationGroup)` — **hors périmètre 40.1** (pilote = domaine `user`, global). Garder le composant AC4 compatible (accès injectable) pour les stories 40.x « machines/wpkg ».

### Routing & pages (convention projet)

- Le routeur n'est **pas** un scanner filesystem : routes **explicites** dans `routes/web.php`. L'arborescence `resources/views/pages/` est une **convention** résolue par Livewire (`config/livewire.php:34` mappe `pages::` → `resources/views/pages/`).
- Pattern d'ajout : `Route::livewire('/segment', 'pages::dossier.index')->name('nom')` **dans** le groupe `Route::prefix('app')->middleware(['sambaedu.auth','federated.audit'])->name('app.')` (`routes/web.php:67`). Le nom devient `app.nom`.
- Convention SFC in-line (pattern universel du projet) :
  ```php
  <?php
  use Livewire\Component;
  use Livewire\Attributes\Title;
  new #[Title('Guide')] class extends Component {
      // pas de guard bloquant pour le hub
  };
  ?>
  <x-organisms.page title="Guide" icon="fa-solid fa-circle-question" description="…">
     …
  </x-organisms.page>
  ```
- **Wrapper de page** : `resources/views/components/organisms/page.blade.php` — props `title`, `description`, `icon`, `back`/`backUrl`, `actions`. À utiliser pour le hub ET la page domaine (avec `back="{{ route('app.guide') }}"` sur la page domaine).
- **Landing en cartes** (modèle à copier pour le hub) : `resources/views/pages/admin/settings/index.blade.php` utilise `x-molecules.settings-section` + `x-molecules.settings-card` (props : `href`, `icon`, `iconColor`, `title`, `description`, `badge`, `testid`). Réutiliser ces molecules pour les cartes de domaine.
- **Sidebar** : `resources/views/components/organisms/sidebar.blade.php` — menu **statique**. Le bloc « Réglages » (l.54) montre le pattern `@can('server.admin')` : notre entrée « Guide » doit être **hors** de tout `@can` (visible pour tous).

### Gating « afficher + verrouiller » (le cœur de la story)

- Ne PAS utiliser la directive `@can(...) … @endcan` autour d'une fonctionnalité (elle **masque**). Ici on veut **toujours rendre** et calculer un booléen `$unlocked = auth()->user()->can($permission->value)` pour piloter le style (grisé/badge) et l'activation du lien.
- Verrouillé = `opacity-50` (ou `opacity-60`) + badge daisyUI `badge` avec icône `fa-solid fa-lock` + phrase « Droit requis : {{ $permission->label() }} » + lien réel rendu `pointer-events-none`/`disabled` (ou remplacé par un `<span>`).
- Ajouter des `data-testid` (ex. `feature-{permission-value}`, `feature-lock-{permission-value}`) pour cibler les assertions de test.

### Composants transverses (règles projet)

- **Toasts** : `App\Components\Traits\WithToasts` (`toastSuccess/Error/…`) si un composant Livewire a besoin de notifier — probablement inutile ici (pages en lecture).
- **Modale réutilisable** : `resources/views/components/molecules/confirm-modal.blade.php` (Alpine + `<dialog>`) — non requise ici a priori.

### Nommage / périmètre

- Le libellé « Guide » et le chemin `/guide` sont proposés ; renommables trivialement (ex. « Guide », « Fonctionnalités ») sans impact structurel. Le dev peut retenir « Guide ».
- **Ne PAS** ajouter de route/middleware `can:` sur le hub ni la page domaine : le gating est **intra-page**, pas au niveau route.
- **Ne PAS** stocker le contenu how-to en base : « pages statiques » ⇒ contenu authored dans le code (registre PHP/Blade), versionné.

### Project Structure Notes

- Nouveaux fichiers attendus :
  - `resources/views/pages/guide/index.blade.php` (hub)
  - `resources/views/pages/guide/utilisateurs/index.blade.php` (domaine pilote)
  - `resources/views/components/molecules/feature-guide-item.blade.php` (composant de gating réutilisable)
  - `app/Support/Help/FeatureGuideRegistry.php` (ou structure équivalente pour le contenu how-to) — emplacement à confirmer selon les conventions `app/Support/*` existantes ; sinon, un data-provider dédié.
  - Tests sous `tests/Feature/` (ex. `tests/Feature/Guide/GuideTest.php`).
- Modifs : `routes/web.php` (2 routes), `resources/views/components/organisms/sidebar.blade.php` (1 entrée).
- Cohérence : suivre l'Atomic Design existant (`components/{atoms,molecules,organisms}`) et le style Tailwind/daisyUI des autres pages.

### Tests — environnement (contraintes projet connues)

- Les tests PHPUnit tournent **sur l'hôte** (php8.4 + pdo_sqlite) ; la VM n'a pas `pdo_sqlite`. Valider en local via filtres ciblés.
- SQLite de test : rôles/permissions Spatie doivent être seedés dans le test (utiliser `PermissionSeeder` ou assigner directement via `SambaRole::…->permissionNames()`), et penser au reset du cache de permissions Spatie entre assignations (`app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions()`).
- Reprendre les gardes systémiques de la suite (withoutVite, etc.) si présentes dans le `TestCase` de base.

### References

- [Source: app/Enums/SambaPermission.php] — enum permissions, `label()`, `category()`, `categoryLabel()`, `groupedByCategory()`, catégorie `user` (6 permissions).
- [Source: app/Enums/SambaRole.php] — rôles seedés, `permissions()` (prof=2 perms user, eleve=0, super-admin=toutes).
- [Source: app/Models/User.php] — trait `HasRoles`, `$user->can()`, `getAllPermissions()`.
- [Source: app/Services/PermissionService.php#canOnWorkstationGroup] — accès scopé WorkstationGroup (référence pour compat future, hors 40.1).
- [Source: routes/web.php:67-141] — groupe `Route::prefix('app')…->name('app.')`, pattern `Route::livewire(...)->name(...)`.
- [Source: config/livewire.php:34] — mapping namespace `pages::` → `resources/views/pages/`.
- [Source: resources/views/components/organisms/page.blade.php] — wrapper de page (props title/icon/description/back/actions).
- [Source: resources/views/pages/admin/settings/index.blade.php] — modèle de landing en cartes (`x-molecules.settings-section` / `settings-card`).
- [Source: resources/views/components/organisms/sidebar.blade.php:54-64] — pattern `@can('server.admin')` (notre entrée « Guide » doit être hors `@can`).
- [Source: app/Components/Traits/WithToasts.php] — trait toasts (si besoin).
- [Source: _bmad-output/planning-artifacts/profiles-rights-matrix.md] — matrice profils × droits (contexte métier des rôles).

## Dev Agent Record

### Agent Model Used

Recommandation modèle de dev : **claude-opus-4-8[1m]** (story de socle transverse touchant routing, autorisation, composant réutilisable et tests — précision requise ; le contenu how-to demande de la rigueur métier).

### Debug Log References

- Impossible de lancer la suite PHPUnit : **aucun runtime PHP n'est installé sur
  l'hôte de dev** (`php`/`php8.4` absents de `/usr/bin`, `/usr/local/bin`, snap,
  version managers). Les tests n'ont donc PAS pu être exécutés localement. Le
  fichier `tests/Feature/Guide/GuideTest.php` a été rédigé en réutilisant
  strictement les patterns éprouvés de la suite (`Livewire::test()->assertOk()->html()`,
  `substr_count()` sur `data-testid`, `assertSeeHtml`, `RefreshDatabase`,
  `PermissionSeeder`, `forgetCachedPermissions`, `#[Computed]`). À rejouer sur un
  hôte disposant de php8.4 + pdo_sqlite : `php artisan test --filter=Guide`.

### Completion Notes List

- **AC1** — Route `Route::livewire('/guide', 'pages::guide.index')->name('guide')`
  ajoutée dans le groupe `app.` de `routes/web.php`, **sans middleware `can:`**.
  Le hub n'a **aucun guard** dans `mount()` : ouvert à tout utilisateur authentifié.
- **AC2** — Entrée « Guide » (icône `fa-solid fa-circle-question`) ajoutée dans
  `sidebar.blade.php` **hors** de tout bloc `@can`, état actif via
  `request()->is('app/guide*')`.
- **AC3** — Hub itère `SambaPermission::groupedByCategory()`, une carte par
  domaine, compteur « X / Y accessibles » calculé pour l'utilisateur connecté.
  Domaine « Utilisateurs » cliquable → `route('app.guide.utilisateurs')` ; autres
  domaines = carte grisée « Bientôt disponible » sans lien mort.
- **AC4/AC7** — Composant `x-molecules.feature-guide-item` : rend TOUJOURS la
  fonctionnalité (jamais de masquage). État déverrouillé si `can()` vrai, sinon
  verrouillé (grisé `opacity-50` + badge cadenas « Verrouillé » + « Droit requis :
  … » + bouton désactivé `pointer-events-none`). La décision d'accès est
  **injectable** via la prop `unlocked` (par défaut dérivée de `can()`), prête
  pour le mode scopé WorkstationGroup des stories 40.x — sans l'implémenter.
- **AC5** — Page `/app/guide/utilisateurs` rend les 6 permissions `user` via le
  composant, alimentées par `FeatureGuideRegistry`. Bouton retour vers le hub
  (prop `back`).
- **AC6** — `App\Support\Help\FeatureGuideRegistry` : contenu how-to authored
  (objectif + étapes numérotées + nom de route), **ancré sur `SambaPermission`**
  (aucune duplication de `label()`/`category()`). Les 6 permissions `user` sont
  rédigées ; toute permission sans entrée retombe sur le fallback « Guide à venir »
  (les 24 restent listables).
- **AC8** — `tests/Feature/Guide/GuideTest.php` (7 tests) : accès hub (élève 200 /
  invité redirigé login), 6 fonctionnalités toujours listées (eleve/prof/super-admin),
  gating prof 2/6, eleve 6/6 verrouillées, super-admin 6/6 déverrouillées, compteur
  hub « 2 / 6 » pour le prof, domaines « Bientôt disponible ». **Non exécutés**
  faute de PHP sur l'hôte (cf. Debug Log).
- **Convention Livewire 4** : les propriétés dérivées utilisent `#[Computed]`
  (`domains()` / `features()` accédées via `$this->domains` / `$this->features`),
  pas le magic `getXxxProperty` (déprécié).
- **Note VM (déploiement)** : les nouvelles routes `app.guide*` nécessiteront un
  `php artisan route:cache` suivi d'un `chown www-admin:www-admin bootstrap/cache/*.php`
  côté VM (sinon catch-all legacy → 404). À faire par l'utilisateur — **ne pas**
  sync/cacher manuellement depuis l'hôte.

### File List

**Créés :**

- `app/Support/Help/FeatureGuideRegistry.php`
- `resources/views/components/molecules/feature-guide-item.blade.php`
- `resources/views/pages/guide/index.blade.php`
- `resources/views/pages/guide/utilisateurs/index.blade.php`
- `tests/Feature/Guide/GuideTest.php`
- `docs/qa/domains/guide.md`

**Modifiés :**

- `routes/web.php` (2 routes `app.guide` + `app.guide.utilisateurs`)
- `resources/views/components/organisms/sidebar.blade.php` (entrée « Guide » hors `@can`)
- `docs/qa/README.md` (ligne domaine « guide » ajoutée)
- `_bmad-output/implementation-artifacts/40-1-socle-guide-et-domaine-pilote-utilisateurs.md` (statut, tâches, Dev Agent Record)
- `_bmad-output/implementation-artifacts/sprint-status.yaml` (40-1 → review)

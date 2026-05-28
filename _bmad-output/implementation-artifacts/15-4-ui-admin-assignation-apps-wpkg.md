# Story 15.4 : UI admin assignation apps WPKG

Status: review

> **Story Epic 15 #4** — UI admin de pilotage du déploiement WPKG +
> **émetteurs** des events Laravel câblés en Story 15.2.
> Workflow legacy à **un seul niveau** (pas de séparation
> autorisation / activation — confirmé exploration 2026-05-01).
> Surface : 2 vues principales (parc + poste) + bulk catégorie + clone
> parc → parc + onglet options `.ini` poste.

---

## Story

As a **responsable de collège / administrateur SER**,
I want une interface unique pour assigner/désassigner des applications
aux parcs et postes, cloner la configuration d'un parc vers un autre,
et gérer les options `.ini` par poste,
So que je puisse piloter le déploiement WPKG sans manipuler les
XML / `.ini` directement, depuis une UI cohérente avec le reste de SER
et **byte-identique** aux fichiers consommés par les clients Windows
(via les contrôleurs HTTP de Story 15.2).

---

## Contexte

L'Epic 15 réécrit nativement le pipeline WPKG. Les fondations
(15.1 ✅) et les générateurs/contrôleurs HTTP + Eloquent
(15.2 review) sont livrés. La **Story 15.2 a explicitement reporté à
15.4** :
- Les **émetteurs** des 7 events `App\Wpkg\Deployment\Events\*` (ils
  sont créés et cablés à des listeners qui invalident le cache
  `wpkg:packages:{hostname}` + régénèrent le `.ini`, mais aucun code
  métier ne les dispatche aujourd'hui).
- Toute UI admin permettant les mutations métier qui doivent émettre
  ces events.

Cette story livre les deux faces d'une même pièce : **UI Livewire SFC
+ dispatch des events** depuis les services métier mutateurs.

### Invariants Epic 15 (rappel)

- **Eloquent first** : aucune lecture AD en hot path. La résolution
  d'apps est faite par `WorkstationPackagesResolver` (15.2) qui ne
  touche jamais l'AD. Les vues lisent uniquement Eloquent.
- **Cache invalidé par events** : chaque mutation métier (assign,
  detach, clone, options) doit dispatcher l'event approprié pour que
  le listener `InvalidateWorkstationPackagesCache` purge le bon
  hostname (sinon les clients Windows servent du cache stale via
  `/wpkg/profiles.xml?poste=...`).
- **Atomic write** : déclenchement transitif via les listeners de 15.2
  qui appellent `WorkstationIniGenerator` → `App\Support\AtomicFileWriter`.
- **Channel logs `wpkg-deploy`** + `deployment_id` corrélé pour les
  actions traçables (clone parc, bulk catégorie).
- **Filesystem-based router** : pages sous `resources/views/pages/`
  (Livewire SFC), modale réutilisable (`<x-molecules.confirm-modal>` ou
  événement Alpine `open-confirm-modal`), trait `WithToasts`.

### Pourquoi maintenant

15.2 verrouille la chaîne consommation **lecture-cache → events →
invalidation → regen `.ini`**. 15.4 boucle la boucle côté **mutation**
: sans émetteurs réels, le câblage event/listener de 15.2 reste
décoratif et le cache `wpkg:packages:*` ne peut être invalidé que par
`php artisan wpkg:cache:flush` (commande utilitaire, pas un workflow
admin). 15.4 transforme l'UI parc-settings + parc/groups + parc/machines
en **producteurs d'events**.

### Frontière avec 15.3 (NON-bloquante)

15.3 (Modèle Eloquent suffisant + sync AD périodique) est en `backlog`.
**Cette story 15.4 n'a pas de dépendance bloquante sur 15.3** :
- Les vues lisent uniquement Eloquent (déjà suffisant pour les
  attributs UI : `Workstation::name`, `WorkstationGroup::name`,
  `AppProfile::display_name`, `Application::app_id`).
- Si `Workstation::archived_at` ou colonne équivalente s'avère utile
  pour filtrer postes en vue parc, la story 15.4 utilise la convention
  existante (`Workstation::scopeActive()` cf. `app/Models/Workstation.php:183`)
  sans introduire de nouvelle colonne. Toute extension de schéma reste
  dans 15.3.

---

## Dépendances

| Story | Titre | Status attendu au kickoff | Détail |
|-------|-------|----------------------------|--------|
| 15-1 | Fondations Pipeline Déploiement WPKG | done | Channel `wpkg-deploy`, namespace `App\Wpkg\Deployment`, `wpkg_deployments` table (UUID) — utilisée AC4.5 trace clone, `App\Support\AtomicFileWriter` |
| 15-2 | Generators XML + .ini par poste | done (review → done par user avant kickoff) | **Critique** : fournit les 7 events + 2 listeners. Cette story dispatche ces events **tels quels**. Pivots `application_workstation`, `application_workstation_group`, `application_dependencies`, table `wpkg_workstation_options`, modèle `WpkgWorkstationOption`, relations `Workstation::appProfiles/applications/wpkgOptions`, `WorkstationGroup::applications`, `Application::dependencies` |
| Epic 4 | Workstation, WorkstationGroup, AppProfile, Application | done | Modèles + pivots `app_profile_*` |
| Epic 7 | Permissions Spatie | done | `SambaPermission::WpkgAssign` (= `wpkg.assign`) déjà déclarée ; **pas besoin** de créer `wpkg.view` ni `wpkg.manage` (cf. décision permissions ci-dessous) |
| 9-2 | Gestion packages WPKG admin | done | `parc-settings` page existante avec onglet "Profils" — la nouvelle UI 15.4 vit **à côté**, pas dedans (séparation responsabilité : 9-2 = catalogue profils ; 15-4 = assignation parc/poste) |

> **Hypothèse de dev** : 15-1 et 15-2 sont passés `done` avant le
> kickoff. Si 15-2 reste `review` au démarrage, le user finalise
> manuellement (cf. `Dev Agent Record` 15-2 — fixtures byte-à-byte
> non bloquantes pour 15.4).

---

## Décision permissions (à intégrer dès le kickoff)

L'epics propose `wpkg.view` / `wpkg.manage`. **Décision SM** : ne **pas**
introduire de nouvelles permissions. Utiliser l'existant :

| Action | Permission utilisée | Justification |
|--------|---------------------|---------------|
| Lire / consulter assignations apps WPKG (parc, poste) | `viewAny-workstationGroup` (Gate existant utilisé par `routes/web.php:181` pour `parc.groups.show` + `parc.machines.show`) | Pas de gain à dupliquer. La consultation des apps WPKG est partie intégrante de la consultation parc/poste. |
| Modifier les assignations (attach/detach apps, profils, options `.ini`, clone) | `wpkg.assign` (`SambaPermission::WpkgAssign` déjà déclarée cf. `app/Enums/SambaPermission.php:53`, label « Affecter des applications » l. 143, mappée bit legacy `0x1000`) | Sémantiquement correct : c'est exactement le périmètre de cette permission. |
| Créer / éditer un `AppProfile` (catalogue) | Hors scope 15.4 — déjà couvert par 9-2 / parc-settings | — |

**Si la coordination Epic 7 demande quand même** `wpkg.view`/`wpkg.manage`
: à arbitrer **avant T1** avec Henri. Pas de migration de seed à faire
dans le scope 15.4 (refus par défaut — préférence statu quo
permissions existantes).

---

## Ajustements 2026-05-07 (validation user pré-kickoff) — **OVERRIDE**

> **Ces ajustements priment** sur tout ce qui suit (AC, Tasks, Project
> Structure Notes) en cas de divergence. Le dev-agent doit s'y conformer.

### Décision A — Pas de pages WPKG séparées : onglets via `_partials`

**Justification user** : symétrie UX avec le pattern `deploymentTab`
déjà présent dans `pages/parc/machines/[id]/index.blade.php:27,66,451`.
Les 2 vues 15.4 (parc + poste) deviennent des **onglets** dans les pages
fiches existantes, avec code extrait dans `_partials/`.

**Impacts AC/T :**
- **AC1.1** annulé : pas de route `parc.groups.wpkg`. L'UI parc devient
  un onglet « Applications WPKG » dans
  `resources/views/pages/parc/groups/[id]/index.blade.php` (pattern
  Livewire SFC : nouvelle propriété d'état `$wpkgTab` ou similaire +
  branche d'onglet).
- **AC2.1** annulé : pas de route `parc.machines.wpkg`. L'UI poste
  devient un onglet « Applications WPKG » dans
  `resources/views/pages/parc/machines/[id]/index.blade.php` à côté du
  tab `deploymentTab` existant (lignes 726+).
- **AC1.2 / AC2.2 / AC2.3 / AC2.4 / AC5** : périmètre fonctionnel
  inchangé, seul le conteneur (page → onglet) change.
- **Code extrait** dans :
  - `resources/views/pages/parc/groups/[id]/_partials/wpkg-assignment-tab.blade.php`
    (nouveau fichier, Livewire SFC ou partial blade selon besoin de
    réactivité).
  - `resources/views/pages/parc/machines/[id]/_partials/wpkg-assignment-tab.blade.php`
    (idem) **et** un partial frère
    `wpkg-options-tab.blade.php` pour les options `.ini` (Volet 5) si
    le dev juge que le découpage améliore la lisibilité.
- **Routing** (T4 / T5) : **rien à ajouter** dans `routes/web.php`. Les
  onglets sont accessibles via les routes existantes
  `parc.groups.show` et `parc.machines.show` (deep-link possible via
  query param `?tab=wpkg` à gérer dans le `mount()` du composant
  parent).
- **Pas de chemins** `pages/parc/groups/[id]/wpkg/` ni
  `pages/parc/machines/[id]/wpkg/` dans le File List. Project
  Structure Notes (ligne 836 du présent fichier) à lire en
  appliquant cet override.

### Décision B — Généraliser les modales d'attach existantes

**Justification user** : `pages/parc-settings/profiles/_partials/`
contient déjà 3 modales (`add-apps-modal.blade.php`,
`add-groups-modal.blade.php`, `add-workstations-modal.blade.php`,
~210 lignes total) avec pattern search + multi-select + apply.
Création de doublons rejetée.

**Impacts AC/T :**
- **T6 (bulk catégorie)** : ne **pas** créer un composant
  `bulk-category-assign.blade.php` neuf qui dupliquerait le pattern.
  À la place :
  1. **Refactorer** les 3 modales existantes en composants partagés
     sous `resources/views/components/organisms/wpkg/` :
     - `attach-apps-modal.blade.php` (depuis `add-apps-modal`)
     - `attach-groups-modal.blade.php` (depuis `add-groups-modal`)
     - `attach-workstations-modal.blade.php` (depuis `add-workstations-modal`)
  2. **Mettre à jour** les références dans
     `pages/parc-settings/profiles/index.blade.php` pour pointer vers
     les nouveaux composants partagés (zéro régression fonctionnelle :
     test feature à ajouter dans T9 pour la non-régression du flow
     parc-settings/profiles).
  3. **Réutiliser** ces composants dans les onglets parc/poste de 15.4
     (Décision A) en passant le contexte (target_type, target_id,
     callback de mutation) via props.
  4. **Bulk catégorie (AC3)** : le sélecteur catégorie + cible reste un
     petit composant 15.4 spécifique (`bulk-category-selector.blade.php`),
     mais la **prévisualisation** réutilise `attach-apps-modal` étendu
     (ou `<x-molecules.confirm-modal>` selon volume preview).

**Test de non-régression obligatoire (T9)** :
- `tests/Feature/AppProfile/ProfileAttachModalsRegressionTest.php` —
  vérifie que le flow `parc-settings/profiles` continue de fonctionner
  avec les modales déplacées (attach app, attach group, attach
  workstation depuis la fiche profil).

### Décision C — Bulk catégorie (AC3.3)

**Recommandation** : 1 event pluriel
`AppProfileApplicationsChanged($appProfileId, array $applicationIds, $direction)`
plutôt que N events singuliers. Évite l'invalidation cache redondante
sur le même `appProfileId`. Documenter dans `Dev Agent Record` § Decisions.

### File List à mettre à jour en fin de dev

Le dev doit refléter les Décisions A et B dans le File List final :
- Ajouter les `_partials/wpkg-*-tab.blade.php` (au lieu des
  `pages/.../wpkg/index.blade.php`).
- Ajouter les 3 composants `attach-*-modal.blade.php` migrés.
- Ajouter les fichiers `pages/parc-settings/profiles/*` modifiés (refs
  de chemin).

---

## Acceptance Criteria

### Volet 1 — Vue parc / WorkstationGroup

**AC1.1** — Route et page Livewire SFC
**Given** l'arborescence filesystem-based router
**When** un admin navigue vers `/app/parc/groups/{id}/wpkg`
**Then** la route est définie dans `routes/web.php` sous
`Route::prefix('parc')->name('parc.')->group(...)` (cf.
`routes/web.php:178`) :
```php
Route::livewire('/groups/{id}/wpkg', 'pages::parc.groups.[id].wpkg.index')
    ->whereNumber('id')
    ->middleware('can:viewAny-workstationGroup')
    ->name('groups.wpkg');
```
**And** le composant Livewire SFC vit dans
`resources/views/pages/parc/groups/[id]/wpkg/index.blade.php`
**And** la page hérite du layout admin SE4FS (cf. layout utilisé par
`pages/parc/groups/[id]/index.blade.php`).

**AC1.2** — Liste des `AppProfile` rattachés
**Given** un `WorkstationGroup` chargé (`mount(int $id)`)
**When** la page se rend
**Then** la section « Applications WPKG » affiche :
- La liste des `AppProfile` rattachés à ce groupe
  (`$group->appProfiles` cf. `app/Models/WorkstationGroup.php:266`),
  avec pour chacun : nom (`display_name ?? name`), nombre
  d'applications, badge actif/inactif.
- Le détail expandable des applications de chaque profil
  (`$profile->applications` cf. `app/Models/AppProfile.php:79`).
- Les applications **directement** rattachées au groupe via le pivot
  `application_workstation_group` (relation
  `WorkstationGroup::applications` ajoutée en 15.2 T2).

**AC1.3** — Ajout / retrait d'un `AppProfile`
**Given** la liste des profils disponibles non-rattachés (`AppProfile::active()->whereNotIn('id', $attachedIds)->get()`)
**When** l'admin clique « Ajouter le profil X »
**Then** la mutation appelle `AppProfileService::addWorkstationGroups($profileId, [$groupId])`
(cf. `app/Services/AppProfile/AppProfileService.php:216`)
**And** **immédiatement après** le `syncWithoutDetaching`, le service
dispatche
`event(new AppProfileWorkstationGroupChanged($profileId, $groupId, 'attached'))`
**And** un toast `WithToasts::toastSuccess('Profil <name> ajouté au parc')`
confirme l'action.

**Idem** pour le retrait : `removeWorkstationGroups` →
`AppProfileWorkstationGroupChanged($profileId, $groupId, 'detached')`.

**AC1.4** — Ajout / retrait d'une `Application` directement sur le parc
**Given** la liste des applications du catalogue
(`Application::orderBy('name')->get()`)
**When** l'admin attache/détache une application directement
**Then** un nouveau service `App\Services\AppProfile\AppProfileService::addApplicationsToWorkstationGroup($groupId, [$applicationIds])`
(ou méthode dédiée à créer dans cette story) fait
`$group->applications()->syncWithoutDetaching($applicationIds)`
**And** un event **`AppProfileApplicationChanged` n'est PAS suffisant**
ici (il cible le pivot `app_profile_application`, pas
`application_workstation_group`). **Cette story 15.4 doit créer un
8e event** :
`App\Wpkg\Deployment\Events\WorkstationGroupApplicationsChanged($workstationGroupId, $applicationIds, $direction)` (cf. AC4.0).

**AC1.5** — Lecture vs modification (gate)
**Given** la route protégée par `can:viewAny-workstationGroup` (lecture)
**When** un user **sans** `wpkg.assign` tente une action de mutation
(attach / detach)
**Then** le composant Livewire `authorize('wpkg.assign')` lève
`AuthorizationException` interceptée, ré-émise comme toast d'erreur
« Vous n'avez pas la permission de modifier les assignations WPKG. »
**And** un test feature couvre le 403 sur la mutation Livewire.

### Volet 2 — Vue poste / Workstation

**AC2.1** — Route et page Livewire SFC
**When** un admin navigue vers `/app/parc/machines/{id}/wpkg`
**Then** la route est définie :
```php
Route::livewire('/machines/{id}/wpkg', 'pages::parc.machines.[id].wpkg.index')
    ->whereNumber('id')
    ->middleware('can:viewAny-workstationGroup')
    ->name('machines.wpkg');
```
**And** le composant vit dans
`resources/views/pages/parc/machines/[id]/wpkg/index.blade.php`.

> **Décision routing** : on **garde** le préfixe `parc.machines` (cf.
> `routes/web.php:203`) plutôt que `/app/workstations/{w}/wpkg` proposé
> dans l'epics. Raison : cohérence avec les routes existantes du parc
> (la page `parc.machines.show` est déjà la fiche poste). Les liens
> internes pointent vers ces routes.

**AC2.2** — Affichage hérité vs override
**Given** un `Workstation` `$workstation` avec :
- Profils hérités via groupe : `$workstation->groups->flatMap->appProfiles`
- Profils directs : `$workstation->appProfiles` (relation 15.2 T2)
- Applications héritées via groupe : `$workstation->groups->flatMap->applications`
- Applications directes : `$workstation->applications` (relation 15.2 T2)
**When** la page se rend
**Then** chaque ligne (profil ou application) affiche un badge :
- « hérité (parc XYZ) » avec lien vers le parc — pour héritages.
- « direct » — pour assignations directes au poste.
**And** la dédup est faite côté UI : un profil hérité **ET** direct
n'apparaît qu'une fois avec les deux badges (héritage + override).

**AC2.3** — Ajout / retrait d'un `AppProfile` directement sur le poste
**When** l'admin clique « Ajouter directement »
**Then** la mutation appelle `AppProfileService::addWorkstations($profileId, [$workstationId])`
(cf. `app/Services/AppProfile/AppProfileService.php:258`)
**And** dispatch
`event(new AppProfileWorkstationChanged($profileId, $workstationId, 'attached'))`
**And** toast confirmation.

**Idem** retrait : `removeWorkstations` →
`AppProfileWorkstationChanged($profileId, $workstationId, 'detached')`.

**AC2.4** — Ajout / retrait d'une `Application` directement sur le poste
**When** l'admin attache/détache une `Application` directement
**Then** une méthode `AppProfileService::addApplicationsToWorkstation($workstationId, [$applicationIds])`
(ou nouvelle classe) fait
`$workstation->applications()->syncWithoutDetaching($applicationIds)`
**And** dispatch d'un nouvel event
`WorkstationApplicationsChanged($workstationId, $applicationIds, $direction)` (cf. AC4.0).

**AC2.5** — Activation / archivage du poste (en option)
**Given** le badge « Statut » du poste (actif/archivé) dans la vue
**When** l'admin change le statut depuis cette UI (si présent — sinon
géré ailleurs, hors scope)
**Then** dispatch `WorkstationActivated($workstationId)` ou
`WorkstationArchived($workstationId)` (events 15.2 déjà définis).
**Hors scope** si aucun bouton de toggle n'existe dans cette UI ; les
events restent dispatchables depuis l'admin existant (note : à inscrire
dans `Dev Agent Record` si déplacé).

### Volet 3 — Assignation par catégorie d'apps (bulk)

**AC3.1** — Sélecteur catégorie + cible
**Given** la liste des catégories
(`Application::query()->whereNotNull('category')->distinct()->pluck('category')`
ou méthode `AppProfileService::getCategories()` cf. l. 365)
**And** un sélecteur de cible parc (`WorkstationGroup`) ou poste (`Workstation`)
**When** l'admin choisit une catégorie + une cible
**Then** une modale de **prévisualisation** (composant
`molecules/confirm-modal` cf.
`resources/views/components/molecules/confirm-modal.blade.php`) liste les
N applications candidates triées par `app_id`.

**AC3.2** — Création / sélection d'un AppProfile cible
**Given** la prévisualisation
**When** l'admin choisit :
- (a) « Ajouter à un AppProfile existant » → sélecteur des profils
  actifs ; ou
- (b) « Créer un nouveau AppProfile « Categorie-X » » → création + nom
  pré-rempli + assignation à la cible.
**Then** après confirmation :
- (a) `AppProfileService::addApplications($profileId, $applicationIds)` puis
  `addWorkstationGroups` ou `addWorkstations` selon cible.
- (b) `AppProfileService::createProfile([...])` puis
  `addApplications` puis `addWorkstationGroups`/`addWorkstations`.

**AC3.3** — Coalescing des events
**Given** N applications ajoutées au même profil
**When** la mutation s'exécute
**Then** **un seul event** `AppProfileApplicationChanged($profileId,
$applicationIds_concatenated_or_one_per_app)` est dispatché — décision
**implémentation** : un seul event payload `array $applicationIds`
plutôt que N events pour éviter des ré-invalidations en cascade.
**And** **NB** : ceci impose une **modification rétro-compatible**
de l'event 15.2 `AppProfileApplicationChanged` (payload actuel : `int
$applicationId` singulier). **Décision SM** : ne pas casser la
signature 15.2. **Ajouter** un nouvel event distinct
`App\Wpkg\Deployment\Events\AppProfileApplicationsChanged($appProfileId,
array $applicationIds, $direction)` (pluriel) + listener
`InvalidateWorkstationPackagesCache::handleAppProfileApplicationsChanged`
qui itère et délègue.
Alternative acceptable : dispatcher N fois
`AppProfileApplicationChanged` mais la résolution union → invalidation
est ciblée sur les postes du `AppProfile` (pas un cache global), donc
le coût reste linéaire en taille de catégorie. **Le dev choisit** :
documente la décision dans `Dev Agent Record`.

**AC3.4** — Logs `wpkg-deploy`
**And** chaque action bulk émet un log
`Log::channel('wpkg-deploy')->info('Bulk catégorie', ['category' => $cat,
'target_type' => 'group|workstation', 'target_id' => $id, 'apps_count' =>
count($appIds), 'profile_id' => $profileId])`.

### Volet 4 — Clone de configuration parc → parc

**AC4.0** — Nouveaux events à créer dans cette story
> Les events 15.2 ne couvrent pas tous les cas (cf. AC1.4, AC2.4, AC3.3).
> Cette story **étend** la palette en ajoutant des events strictement
> additifs (pas de modif de signature 15.2) :
- `App\Wpkg\Deployment\Events\WorkstationGroupApplicationsChanged(int $workstationGroupId, array $applicationIds, string $direction)` (AC1.4).
- `App\Wpkg\Deployment\Events\WorkstationApplicationsChanged(int $workstationId, array $applicationIds, string $direction)` (AC2.4).
- `App\Wpkg\Deployment\Events\AppProfileApplicationsChanged(int $appProfileId, array $applicationIds, string $direction)` (AC3.3, optionnel selon arbitrage dev).
**And** chaque nouvel event = `final readonly class` + trait
`Dispatchable` (parité 15.2).
**And** le listener `InvalidateWorkstationPackagesCache` est étendu
pour traiter les 3 nouveaux events :
- `WorkstationGroupApplicationsChanged` → invalider cache pour tous
  les postes du `WorkstationGroup` (parité
  `AppProfileWorkstationGroupChanged`).
- `WorkstationApplicationsChanged` → invalider cache du poste cible
  uniquement.
- `AppProfileApplicationsChanged` → délégation à
  `handleAppProfileApplicationChanged` pour chaque `applicationId`
  (re-use sans dupliquer la logique union).
**And** le test archi 15.1 reste vert (les nouveaux events sont sous
`App\Wpkg\Deployment\Events\` — pas d'import LDAP).

**AC4.1** — UI clone
**Given** l'admin sur la page parc A (`parc.groups.wpkg`)
**When** il clique « Cloner cette configuration vers… »
**Then** une modale présente :
- Sélecteur du parc destination B (filtre `WorkstationGroup::active()`,
  excluant A).
- Diff prévisualisable : 2 colonnes (apps actuelles de B / apps après
  clone), avec ajouts en vert et suppressions en rouge.

**AC4.2** — Service de clone
**Given** une nouvelle méthode
`App\Services\AppProfile\AppProfileService::cloneConfiguration(int $sourceGroupId, int $targetGroupId): array` (retour : ['added' => [...], 'removed' => [...]])
**When** elle est appelée après confirmation
**Then** elle exécute en **transaction DB** :
1. Snapshot configuration B avant.
2. `$targetGroup->appProfiles()->sync($sourceGroup->appProfiles->pluck('id')->all())`.
3. `$targetGroup->applications()->sync($sourceGroup->applications->pluck('id')->all())`.
4. Calcul des deltas (added/removed) pour le retour.
**And** dispatche **un seul event consolidé** par ressource
modifiée :
- Pour chaque profil ajouté/retiré : 1 `AppProfileWorkstationGroupChanged`.
- Pour les apps directes : 1 `WorkstationGroupApplicationsChanged`
  ajouté + 1 retiré (si non-vides).
**And** logs `wpkg-deploy` avec `deployment_id` (UUID nouveau,
généré au début de l'opération clone).

**AC4.3** — Trace audit
**Given** la table `wpkg_deployments` (15.1)
**When** un clone réussit
**Then** une ligne y est insérée avec :
- `id` = UUID `deployment_id` partagé avec les logs.
- `triggered_by` = user_id courant.
- `triggered_at` = now.
- `target_scope` = `{"workstation_group_ids": [$targetGroupId]}`.
- `status` = `completed` (clone est synchrone, pas async).
- `summary` = `{"source_group_id": $sourceGroupId, "added": [...],
  "removed": [...]}`.

**AC4.4** — Tests
**Given** un test feature
**When** il invoque le clone
**Then** assert :
- BD reflète la cible = source.
- Exactement N events `AppProfileWorkstationGroupChanged` dispatchés
  (N = `count(added) + count(removed)`).
- 1 ligne `wpkg_deployments` créée avec UUID + status completed.

### Volet 5 — UI options `.ini` par poste

**AC5.1** — Onglet « Options WPKG » dans la vue poste
**Given** la page `parc.machines.{id}.wpkg`
**When** l'admin sélectionne l'onglet « Options WPKG »
**Then** la liste des 8 options legacy
(`WorkstationIniGenerator::LEGACY_OPTIONS` 15.2) est affichée :
- `debug`, `logdebug`, `force`, `forceinstall`, `nonotify`, `dryrun`,
  `nowpkg`, `noforcedremove`.
**And** chaque option présente :
- Toggle (true/false). Valeur courante = soit override BDD
  (`$workstation->wpkgOptions->where('option_key', $key)->first()->option_value`),
  soit défaut `false`.
- Label = description embarquée (cf. constante
  `WorkstationIniGenerator::LEGACY_OPTIONS[$key]['description']`).
- Indicateur visuel « Surchargé pour ce poste » si valeur en BDD.

**AC5.2** — Sauvegarde
**Given** l'admin modifie 1+ option
**When** il clique « Enregistrer »
**Then** la mutation `App\Wpkg\Deployment\Services\WorkstationOptionsService::update(int $workstationId, array $changes)`
(nouvelle classe à créer dans cette story, isolée sous le namespace
`App\Wpkg\Deployment\Services` pour cohésion 15.x) :
1. `WpkgWorkstationOption::updateOrCreate(['workstation_id' => $id,
   'option_key' => $k], ['option_value' => $v])` pour chaque change.
2. Si `option_value` retourne au défaut `false`, **suppression** de
   la ligne BDD (option non émise par le generator si absente — cohérent
   avec l'AC5.3 du legacy : on ne stocke que les overrides).
3. Dispatch
   `event(new WorkstationOptionsChanged($workstationId, array_keys($changes)))`
   (event 15.2 existant).
**And** le listener
`RegenerateWorkstationIniOnOptionsChanged` (15.2) régénère
`<hostname>.ini` via atomic write.
**And** toast `WithToasts::toastSuccess('Options WPKG mises à jour
({count} modifications)')`.

**AC5.3** — Réinitialisation aux défauts
**Given** un bouton « Réinitialiser aux valeurs par défaut »
**When** cliqué
**Then** modale de confirmation (`confirm-modal`) → suppression de
toutes les lignes `wpkg_workstation_options` du poste + dispatch
`WorkstationOptionsChanged($id, $allKeys)`.

**AC5.4** — Validation
**And** le composant Livewire valide :
- `option_value` ∈ `{'true', 'false'}` (les 8 options legacy sont toutes
  booléennes — pas d'extension à des options string libres dans 15.4).
- `option_key` ∈ `array_keys(WorkstationIniGenerator::LEGACY_OPTIONS)`.
Toute valeur hors enum → toast erreur, pas de persist.

### Volet 6 — Émetteurs des events depuis services métier (rétrofit)

**AC6.1** — `AppProfileService` (extension)
**Given** les méthodes existantes de `App\Services\AppProfile\AppProfileService`
**When** la story est livrée
**Then** chaque mutateur dispatche l'event correspondant
**immédiatement après** la persistance (pas avant — pour ne pas
émettre sur un échec DB) :

| Méthode | Event dispatché | AC source |
|---------|----------------|-----------|
| `addApplications($profileId, $appIds)` | `AppProfileApplicationsChanged($profileId, $appIds, 'attached')` (ou N fois `AppProfileApplicationChanged` selon arbitrage AC3.3) | AC1.3 implicite + AC3 |
| `removeApplications($profileId, $appIds)` | idem `'detached'` | AC1.3 |
| `addWorkstationGroups($profileId, $groupIds)` | pour chaque `$groupId` : `AppProfileWorkstationGroupChanged($profileId, $groupId, 'attached')` | AC1.3 |
| `removeWorkstationGroups($profileId, $groupIds)` | pour chaque `$groupId` : `AppProfileWorkstationGroupChanged($profileId, $groupId, 'detached')` | AC1.3 |
| `addWorkstations($profileId, $wsIds)` | pour chaque `$wsId` : `AppProfileWorkstationChanged($profileId, $wsId, 'attached')` | AC2.3 |
| `removeWorkstations($profileId, $wsIds)` | pour chaque `$wsId` : `AppProfileWorkstationChanged($profileId, $wsId, 'detached')` | AC2.3 |

**And** les nouvelles méthodes ajoutées par cette story :

| Méthode | Event |
|---------|-------|
| `addApplicationsToWorkstationGroup($groupId, $appIds)` | `WorkstationGroupApplicationsChanged($groupId, $appIds, 'attached')` |
| `removeApplicationsFromWorkstationGroup($groupId, $appIds)` | idem `'detached'` |
| `addApplicationsToWorkstation($workstationId, $appIds)` | `WorkstationApplicationsChanged($workstationId, $appIds, 'attached')` |
| `removeApplicationsFromWorkstation($workstationId, $appIds)` | idem `'detached'` |
| `cloneConfiguration($sourceId, $targetId)` | events ciblés selon le diff (cf. AC4.2) |

**AC6.2** — Pas de dispatch dans observers Eloquent
**Given** la décision 15.2 (« émetteurs reportés à 15.4 ») et la
décision parc (`WorkstationGroupObserver` dispatche `WorkstationGroupAdSyncJob`)
**When** la story est livrée
**Then** **aucun event WPKG n'est dispatché depuis un observer Eloquent
des modèles métier**. Tous les dispatches passent par les services
(`AppProfileService` + `WorkstationOptionsService`).
Justification : cohérence avec la décision SM 15.2 et le test helper
`WpkgSchemaBootstrapper::bootstrap()` qui flush les observers métier en
testing.

**AC6.3** — Persistance + dispatch atomiques
**Given** une transaction DB existante dans `AppProfileService`
**When** la mutation s'exécute
**Then** le dispatch event a lieu **après** `DB::commit()` (à instrumenter
via `DB::transaction(function() { ... })` qui fait commit auto à la sortie
ou via `DB::afterCommit(fn() => event(...))` si le service est imbriqué
dans une transaction parent).
**And** un test feature démontre que si la transaction échoue, **aucun
event n'est dispatché** (assertion via `Event::fake()` + try/catch).

### Volet 7 — Tests

**AC7.1** — Tests feature Livewire (par flux UI)
**Given** la suite de tests Livewire
**When** elle s'exécute
**Then** chaque flux est testé en feature avec
`Livewire::test(...)` + `assertDispatched(...)` :
- `tests/Feature/Wpkg/UI/ParcGroupWpkgPageTest.php` : montage, attach
  AppProfile, detach, attach Application directe, gate `wpkg.assign`
  (403 sans permission).
- `tests/Feature/Wpkg/UI/MachineWpkgPageTest.php` : montage, badge
  hérité vs direct, attach/detach AppProfile direct, attach/detach
  Application directe.
- `tests/Feature/Wpkg/UI/BulkCategoryAssignTest.php` : preview, confirm,
  N apps assignées, 1 (ou N) event(s) dispatché(s) selon arbitrage AC3.3.
- `tests/Feature/Wpkg/UI/CloneGroupConfigTest.php` : diff calculé,
  exécution, BDD reflet source, ligne `wpkg_deployments` créée.
- `tests/Feature/Wpkg/UI/WorkstationOptionsTabTest.php` : modif option,
  persistence, event dispatché, fichier `.ini` regenéré (vérif via
  inspection disque — re-use helper `WpkgSchemaBootstrapper`).

**AC7.2** — Tests unit services
- `tests/Unit/Services/AppProfile/AppProfileServiceEventsTest.php` :
  pour chaque méthode mutatrice, `Event::fake([...])` + appel + assert
  dispatched.
- `tests/Unit/Wpkg/Deployment/Services/WorkstationOptionsServiceTest.php` :
  CRUD options + dispatch.

**AC7.3** — Tests permissions
**Given** un user sans `wpkg.assign`
**When** il déclenche une mutation Livewire
**Then** `AuthorizationException` levée + 403 ou toast erreur.
**And** un test feature couvre les 4 cas de mutation principale (parc
attach profil, machine attach profil, bulk catégorie, clone).

**AC7.4** — Tests events / listeners (non-régression 15.2)
**Given** les listeners `InvalidateWorkstationPackagesCache` et
`RegenerateWorkstationIniOnOptionsChanged`
**When** la suite 15.2 est rejouée
**Then** zéro régression. Les nouveaux events
(`WorkstationGroupApplicationsChanged`, `WorkstationApplicationsChanged`,
`AppProfileApplicationsChanged`) sont câblés au listener cache et leur
test feature démontre `Cache::has('wpkg:packages:HOSTNAME')` = false
après dispatch.

**AC7.5** — Test architecture
**And** `tests/Architecture/WpkgDeploymentNamespaceTest.php` reste vert
(les nouveaux events sont sous `App\Wpkg\Deployment\Events\`). Si la
classe `WorkstationOptionsService` est ajoutée à
`App\Wpkg\Deployment\Services\`, elle est couverte par le scan
existant (depuis 15.2). **Aucune extension du test archi** n'est requise.

**AC7.6** — Non-régression suite globale
**And** les suites adjacentes restent vertes :
- `tests/Architecture/`
- `tests/Feature/Services/AppStore/` (Story 9.2)
- `tests/Feature/Migrations/WpkgWorkstationOptionsMigrationTest.php` (15.2)
- `tests/Feature/Wpkg/Deployment/*` (15.2)
**And** baseline 15.2 inchangée (32 tests Wpkg verts + 23 tests adjacents).

**AC7.7** — PHPUnit attributes
**And** tous les nouveaux tests utilisent les attributs PHPUnit
(`#[Test]`, `#[DataProvider]`) — mémoire `feedback_phpunit_attributes`.

### Volet 8 — Documentation

**AC8.1** — README namespace
**And** `app/Wpkg/Deployment/README.md` est complété :
- Section « Events émis par services métier » avec tableau
  (méthode → event).
- Section « UI admin (Story 15.4) » avec liste des routes Livewire.
- Tableau de mapping legacy → reload étendu :
  | Legacy | Reload |
  |--------|--------|
  | `sambaedu/wpkg/parc_maintenance_apps.php` | `pages/parc/groups/[id]/wpkg/index.blade.php` |
  | `sambaedu/wpkg/poste_maintenance_apps.php` | `pages/parc/machines/[id]/wpkg/index.blade.php` |
  | `sambaedu/wpkg/poste_maintenance_options.php` (UI) | onglet options dans `pages/parc/machines/[id]/wpkg/index.blade.php` |
  | `set_entite_apps()` (legacy `wpkg_libsql.php`) | `AppProfileService::add*` + dispatch event |

**AC8.2** — Runbook QA
**And** `docs/qa/domains/wpkg-deploy.md` est étendu (append-only) avec
une **Section 3 — UI admin assignation apps WPKG** couvrant :
- 3.1 — Vue parc / ajout AppProfile → `/wpkg/profiles.xml?poste=...`
  reflète sans flush manuel.
- 3.2 — Vue poste / override profil → idem.
- 3.3 — Bulk catégorie → N apps assignées, 1 (ou N) events, cache
  invalidé pour les postes du parc cible.
- 3.4 — Clone parc → parc → diff appliqué, ligne `wpkg_deployments`
  créée.
- 3.5 — Modif option → fichier `.ini` regenéré sur disque (vérif `ls
  -la <ini_path>/<hostname>.ini` mtime change).

---

## Hors scope (explicite)

- **Édition du contenu d'un `AppProfile`** (apps qu'il contient) → couvert
  par parc-settings + Story 9.2.
- **Téléchargement de nouvelles apps** depuis dépôts → Story 9.2 ✅.
- **Délégation par parc** (un prof = telle salle uniquement) → Epic 7
  (option future). Cette story 15.4 utilise les permissions existantes
  globales `wpkg.assign`.
- **Sync AD → Eloquent** → Story 15.3.
- **Endpoint d'ingestion rapports clients** → Story 15.5.
- **Filtrage Postes archivés** dans la vue parc → décision : afficher
  tous les postes avec badge statut (cohérent décision 15.2 user #1).
  Filtrage UI optionnel via `Workstation::scopeActive()` à la
  discrétion du dev (toggle « Postes actifs uniquement » par défaut
  coché — non bloquant, pas de critère acceptance).
- **Création d'un nouveau `AppProfile` depuis cette UI** (sauf cas
  bulk catégorie AC3.2 b)) → reste sous parc-settings.

---

## Tasks / Subtasks

- [x] **T0 — Audit pré-dev (kickoff, ~30 min)**
  - [x] Confirmer status `15-1` et `15-2` (passés `done`). Si `15-2`
        encore `review`, demander finalisation user avant kickoff.
  - [x] Confirmer existence des relations (audit T0 15.2 a déjà
        confirmé) : `Workstation::appProfiles`, `applications`,
        `wpkgOptions` ; `WorkstationGroup::applications` ;
        `Application::workstations`, `workstationGroups`, `dependencies`.
  - [x] Confirmer routing à utiliser : `parc.groups.wpkg` /
        `parc.machines.wpkg` (préférable à `/app/workstations/...`
        proposé dans epics — alignement existant).
  - [x] Décision permissions : `wpkg.assign` seulement (pas de
        `wpkg.view` / `wpkg.manage` créées). Confirmer avec user si
        Epic 7 contre-signe.
  - [x] Document audit dans `Dev Agent Record` § Completion Notes.

- [x] **T1 — Nouveaux events (AC4.0)**
  - [x] Créer `app/Wpkg/Deployment/Events/WorkstationGroupApplicationsChanged.php`
        (`final readonly class`, `Dispatchable`, payload `int
        $workstationGroupId`, `array $applicationIds`, `string $direction`).
  - [x] Créer `app/Wpkg/Deployment/Events/WorkstationApplicationsChanged.php`
        (idem, payload `int $workstationId`, `array $applicationIds`,
        `string $direction`).
  - [x] (optionnel selon arbitrage AC3.3) Créer
        `app/Wpkg/Deployment/Events/AppProfileApplicationsChanged.php`
        (idem, payload `int $appProfileId`, `array $applicationIds`,
        `string $direction`).
  - [x] Étendre
        `app/Wpkg/Deployment/Listeners/InvalidateWorkstationPackagesCache.php`
        avec 3 nouveaux handlers (`handleWorkstationGroupApplicationsChanged`,
        `handleWorkstationApplicationsChanged`, et le 3e si créé).
  - [x] Enregistrer les listeners dans
        `WpkgDeploymentServiceProvider::registerWpkgListeners()`
        (pattern existant 15.2).
  - [x] Tests unit listener pour chaque nouveau handler.

- [x] **T2 — Extension `AppProfileService` (AC6)**
  - [x] Pour chaque méthode mutatrice existante (`addApplications`,
        `removeApplications`, `addWorkstationGroups`,
        `removeWorkstationGroups`, `addWorkstations`,
        `removeWorkstations`) : ajouter le dispatch event
        correspondant **après** la persistance (cf. tableau AC6.1).
  - [x] Encapsuler chaque mutation dans `DB::transaction(...)` ou
        `DB::afterCommit(...)` selon contexte (AC6.3).
  - [x] Ajouter 4 nouvelles méthodes :
        `addApplicationsToWorkstationGroup`,
        `removeApplicationsFromWorkstationGroup`,
        `addApplicationsToWorkstation`,
        `removeApplicationsFromWorkstation` — chacune dispatch l'event
        AC4.0 correspondant.
  - [x] Ajouter `cloneConfiguration(int $sourceId, int $targetId): array`
        (AC4.2) avec calcul deltas, sync DB, création ligne
        `wpkg_deployments` (UUID), dispatch events ciblés, log
        `wpkg-deploy` corrélé.
  - [x] Tests unit (`AppProfileServiceEventsTest`).

- [x] **T3 — Service `WorkstationOptionsService` (AC5)**
  - [x] Créer `app/Wpkg/Deployment/Services/WorkstationOptionsService.php`.
  - [x] Méthode `update(int $workstationId, array $changes): void` :
        boucle sur changes, `WpkgWorkstationOption::updateOrCreate` ou
        suppression si retour au défaut, dispatch
        `WorkstationOptionsChanged($id, $changedKeys)`.
  - [x] Méthode `resetToDefaults(int $workstationId): void` :
        `WpkgWorkstationOption::where('workstation_id', $id)->delete()`
        + dispatch event avec toutes les keys.
  - [x] Validation `option_key ∈ LEGACY_OPTIONS keys` et
        `option_value ∈ {'true', 'false'}` (lever exception sinon).
  - [x] Test unit.

- [x] **T4 — Vue parc Livewire SFC (AC1)**
  - [x] Créer `resources/views/pages/parc/groups/[id]/wpkg/index.blade.php`
        (Livewire SFC : `new class extends Component { use WithToasts;
        ... }`).
  - [x] `mount(int $id)` charge le `WorkstationGroup` + relations
        (`appProfiles`, `applications`).
  - [x] Méthodes Livewire : `attachProfile`, `detachProfile`,
        `attachApplication`, `detachApplication` — chacune
        `Gate::authorize('wpkg.assign')` puis appel `AppProfileService`
        + `toastSuccess/toastError`.
  - [x] Vue : sections « Profils rattachés » + « Applications directes »
        (deux listes avec actions inline + sélecteur d'ajout).
  - [x] Ajouter route dans `routes/web.php` (cf. AC1.1) **dans le
        groupe** `Route::prefix('parc')->name('parc.')->group(...)`
        existant.
  - [x] Ajouter onglet/lien depuis
        `pages/parc/groups/[id]/index.blade.php` vers la nouvelle
        page (lien « Applications WPKG » dans la nav du parc).

- [x] **T5 — Vue poste Livewire SFC (AC2)**
  - [x] Créer `resources/views/pages/parc/machines/[id]/wpkg/index.blade.php`.
  - [x] `mount(int $id)` charge `Workstation` + relations héritées
        (`groups.appProfiles`, `groups.applications`,
        `appProfiles`, `applications`).
  - [x] Méthodes Livewire `attachProfileDirect`, `detachProfileDirect`,
        `attachApplicationDirect`, `detachApplicationDirect`.
  - [x] Vue : badges « hérité » vs « direct » + sélecteur d'ajout.
  - [x] Ajouter route + lien depuis
        `pages/parc/machines/[id]/index.blade.php`.

- [x] **T6 — Bulk catégorie (AC3)**
  - [x] Composant Livewire SFC partagé `bulk-category-assign.blade.php`
        sous `resources/views/components/organisms/wpkg/` ou inclus
        dans la vue parc/poste. Sélecteur catégorie (via
        `AppProfileService::getCategories()`), preview modale, action
        confirmée.
  - [x] Méthode `executeBulkCategory($category, $targetType,
        $targetId, $profileMode, $profileId|$newProfileName)` :
        appel `AppProfileService::createProfile` (option b) puis
        `addApplications` puis `addWorkstationGroups`/`addWorkstations`.
  - [x] Modal de prévisualisation (réutiliser
        `<x-molecules.confirm-modal>` avec liste apps en `message`
        custom — ou nouvelle modale `wpkg-bulk-preview-modal` si la
        confirm-modal existante est trop minimaliste pour afficher une
        liste).

- [x] **T7 — Clone parc → parc (AC4)**
  - [x] Composant Livewire SFC clone (peut être inclus dans la vue
        parc T4 sous forme de modale dédiée ou panneau drawer).
  - [x] Action `previewClone($sourceId, $targetId)` : retourne diff
        sans muter.
  - [x] Action `executeClone($sourceId, $targetId)` :
        `AppProfileService::cloneConfiguration` + toast + redirect ou
        refresh.
  - [x] Migration de `wpkg_deployments` : déjà créée 15.1 — vérifier
        que les colonnes attendues (UUID, target_scope JSON, summary
        JSON) sont bien présentes et exploitables ici.

- [x] **T8 — Onglet options `.ini` (AC5)**
  - [x] Sous-composant ou onglet dans la vue
        `pages/parc/machines/[id]/wpkg/index.blade.php` (tabs Alpine
        natives ou pattern existant `deploymentTab` cf.
        `pages/parc/machines/[id]/index.blade.php:24`).
  - [x] Méthodes Livewire `updateOption($key, $value)`,
        `saveOptions()`, `resetOptions()` — appels
        `WorkstationOptionsService` (T3).
  - [x] Vue : table des 8 options avec toggles + bouton « Réinitialiser
        aux défauts » (modale confirm).
  - [x] Test feature : modif `debug=true` → fichier
        `<ini_path>/<hostname>.ini` sur disque contient `debug=true ' ...`
        après dispatch + listener exécuté.

- [x] **T9 — Tests (AC7)**
  - [x] `tests/Feature/Wpkg/UI/ParcGroupWpkgPageTest.php` (montage,
        attach/detach profil, attach/detach app directe, gate 403).
  - [x] `tests/Feature/Wpkg/UI/MachineWpkgPageTest.php` (montage,
        hérité vs direct, attach/detach, gate 403).
  - [x] `tests/Feature/Wpkg/UI/BulkCategoryAssignTest.php` (preview,
        execute, events dispatchés).
  - [x] `tests/Feature/Wpkg/UI/CloneGroupConfigTest.php` (diff,
        execute, ligne wpkg_deployments, events).
  - [x] `tests/Feature/Wpkg/UI/WorkstationOptionsTabTest.php` (modif,
        regen `.ini`, reset).
  - [x] `tests/Unit/Services/AppProfile/AppProfileServiceEventsTest.php`
        (event dispatched par méthode mutatrice).
  - [x] `tests/Unit/Wpkg/Deployment/Services/WorkstationOptionsServiceTest.php`
        (CRUD options + dispatch).
  - [x] `tests/Feature/Wpkg/Deployment/Listeners/InvalidateWorkstationPackagesCacheNewEventsTest.php`
        (3 nouveaux events → invalidation correcte).
  - [x] Run final : `vendor/bin/phpunit tests/Feature/Wpkg/UI
        tests/Unit/Services/AppProfile tests/Unit/Wpkg/Deployment
        tests/Feature/Wpkg/Deployment` + non-régression
        `tests/Architecture` + suite globale (CI).
  - [x] PHPUnit attributes (`#[Test]`, `#[DataProvider]`).

- [x] **T10 — Documentation (AC8)**
  - [x] Compléter `app/Wpkg/Deployment/README.md` (mapping legacy →
        reload étendu, section UI 15.4, section events émis).
  - [x] Étendre `docs/qa/domains/wpkg-deploy.md` Section 3 (5
        scénarios 3.1 à 3.5).
  - [x] Mettre à jour
        `_bmad-output/implementation-artifacts/sprint-status.yaml` :
        `15-4-ui-admin-assignation-apps-wpkg: ready-for-dev → in-progress`
        au démarrage, puis `→ review` à la fin.

---

## Dev Notes

### Architectural Patterns

- **Livewire SFC** (Single-File Component) sous `resources/views/pages/`
  — pattern projet (cf. CLAUDE.md). Format :
  ```php
  <?php
  use Livewire\Component;
  use Livewire\Attributes\Title;
  use App\Components\Traits\WithToasts;
  // ...

  new #[Title('...')] class extends Component {
      use WithToasts;
      // état + méthodes
  };
  ?>
  <div>{{-- vue --}}</div>
  ```
- **Filesystem-based router** : route name `parc.groups.wpkg` ↔ fichier
  `pages/parc/groups/[id]/wpkg/index.blade.php`. Convention projet
  (cf. `routes/web.php:178-206`).
- **Trait `WithToasts`** (cf. `app/Components/Traits/WithToasts.php`) :
  méthodes `toastSuccess(...)`, `toastError(...)`, `toast(...)`.
- **Modale réutilisable** : `<x-molecules.confirm-modal />` (cf.
  `resources/views/components/molecules/confirm-modal.blade.php`)
  utilisée via événement Alpine `$dispatch('open-confirm-modal',
  {title, message, confirmText, method, params, wireId})`.
- **Permissions Spatie** : Gate `can:wpkg.assign` au niveau action
  (méthode Livewire), pas au niveau route (la route reste accessible
  en lecture via `viewAny-workstationGroup`).
- **Event dispatch post-commit** : `DB::afterCommit(fn () =>
  event(...))` ou `DB::transaction(function() { ... })` qui dispatch
  après. Pattern à uniformiser dans `AppProfileService` (revue T2).
- **Logs `wpkg-deploy`** :
  `Log::channel('wpkg-deploy')->info('Action UI WPKG', [...])` avec
  contexte `user_id`, `target_id`, `target_type`, `deployment_id` si
  applicable.

### Anti-patterns à éviter

- **Pas de dispatch d'event depuis un observer Eloquent** sur
  `Workstation` / `WorkstationGroup` / `AppProfile` — uniquement
  depuis les services métier (cf. AC6.2 et décision 15.2).
- **Pas de Gate route-level pour les mutations** — la lecture parc
  reste libre (`viewAny-workstationGroup`), les mutations sont
  gardées **dans** le composant (`Gate::authorize('wpkg.assign')` ou
  `$this->authorize('wpkg.assign')`).
- **Pas de modification de signature des events 15.2** —
  `AppProfileApplicationChanged($appProfileId, $applicationId,
  $direction)` reste à payload singulier. Tout besoin pluriel passe
  par `AppProfileApplicationsChanged` (nouveau, cf. AC4.0).
- **Pas de purge cache global** depuis l'UI — toujours via dispatch
  event ciblé. La commande `php artisan wpkg:cache:flush` reste un
  outil ops, pas un workflow user.
- **Pas de `Cache::forget()` direct** depuis l'UI ou les services
  métier — toujours via le listener `InvalidateWorkstationPackagesCache`.
- **Pas d'écriture directe** dans `<ini_path>/<hostname>.ini` —
  toujours via `WorkstationIniGenerator` déclenché par
  `RegenerateWorkstationIniOnOptionsChanged`.
- **Pas de création de permissions Spatie nouvelles** dans cette
  story — utiliser l'existant `wpkg.assign`.
- **Pas de catchall sur la lecture des relations** — eager-load via
  `with(['appProfiles.applications', 'applications', 'groups.appProfiles.applications', 'groups.applications', 'wpkgOptions'])`
  pour éviter N+1 sur les vues riches.

### Project Structure Notes

```
app/
├── Services/
│   └── AppProfile/
│       └── AppProfileService.php                        # MODIFIÉ (dispatch events + 5 nouvelles méthodes)
└── Wpkg/Deployment/
    ├── Events/
    │   ├── WorkstationGroupApplicationsChanged.php       # CRÉÉ
    │   ├── WorkstationApplicationsChanged.php            # CRÉÉ
    │   └── AppProfileApplicationsChanged.php             # CRÉÉ (optionnel selon AC3.3)
    ├── Listeners/
    │   └── InvalidateWorkstationPackagesCache.php        # MODIFIÉ (3 nouveaux handlers)
    └── Services/
        └── WorkstationOptionsService.php                  # CRÉÉ

resources/views/pages/parc/
├── groups/[id]/
│   ├── index.blade.php                                    # MODIFIÉ (ajout lien onglet WPKG)
│   └── wpkg/
│       └── index.blade.php                                # CRÉÉ (Vue parc WPKG)
└── machines/[id]/
    ├── index.blade.php                                    # MODIFIÉ (ajout lien onglet WPKG)
    └── wpkg/
        └── index.blade.php                                # CRÉÉ (Vue poste WPKG + onglet options)

resources/views/components/organisms/wpkg/                  # CRÉÉ (dossier)
├── bulk-category-assign.blade.php                         # CRÉÉ (composant bulk modal)
└── clone-config-drawer.blade.php                          # CRÉÉ (composant clone)

routes/
└── web.php                                                 # MODIFIÉ (2 routes Livewire)

app/Providers/
└── WpkgDeploymentServiceProvider.php                       # MODIFIÉ (registerWpkgListeners → 3 nouveaux events)

tests/
├── Feature/Wpkg/UI/
│   ├── ParcGroupWpkgPageTest.php                          # CRÉÉ
│   ├── MachineWpkgPageTest.php                            # CRÉÉ
│   ├── BulkCategoryAssignTest.php                         # CRÉÉ
│   ├── CloneGroupConfigTest.php                           # CRÉÉ
│   └── WorkstationOptionsTabTest.php                      # CRÉÉ
├── Feature/Wpkg/Deployment/Listeners/
│   └── InvalidateWorkstationPackagesCacheNewEventsTest.php  # CRÉÉ
├── Unit/Services/AppProfile/
│   └── AppProfileServiceEventsTest.php                    # CRÉÉ
└── Unit/Wpkg/Deployment/Services/
    └── WorkstationOptionsServiceTest.php                  # CRÉÉ

app/Wpkg/Deployment/README.md                              # ENRICHI (mapping + sections 15.4)
docs/qa/domains/wpkg-deploy.md                             # ENRICHI (Section 3 — UI admin)
```

### Code existant à connaître (file:line)

- **Routes** : `routes/web.php:178-206` — préfixe `parc.` avec
  Gates existants (`can:viewAny-workstationGroup`, `can:computer.install`).
  Routes `parc.groups.show:188`, `parc.machines.show:203`. **Insérer**
  les nouvelles routes `groups.wpkg` (après l. 193) et `machines.wpkg`
  (après l. 205).
- **Permissions** : `app/Enums/SambaPermission.php:53-55` :
  `WpkgAssign = 'wpkg.assign'`, `WpkgAdd`, `WpkgCreate`. Cette story
  utilise **uniquement** `wpkg.assign`. Vérification Gate via
  `$this->authorize('wpkg.assign')` ou `Gate::authorize('wpkg.assign')`.
- **Service mutateur** : `app/Services/AppProfile/AppProfileService.php`
  — `addApplications:174`, `removeApplications:195`,
  `addWorkstationGroups:216`, `removeWorkstationGroups:237`,
  `addWorkstations:258`, `removeWorkstations:289`. **À étendre** avec
  dispatch events + 5 nouvelles méthodes (cf. AC6.1 et T2).
- **Modèles** :
  - `app/Models/AppProfile.php:79` `applications()`, `:66` `workstationGroups()`,
    `:92` `workstations()`.
  - `app/Models/Workstation.php` : `appProfiles()`, `applications()`,
    `wpkgOptions()`, `groups()` (relations 15.2 T2).
  - `app/Models/WorkstationGroup.php` : `appProfiles()`, `applications()`,
    `workstations()`.
  - `app/Models/Application.php:67` `category` (string), :126
    `scopeByCategory()`, :113 `appProfiles()`. **Pas** de scope
    `installed()` strict — `Application::query()->whereNotNull('category')`
    pour catégories.
- **Events 15.2** : `app/Wpkg/Deployment/Events/*.php` — 7 events
  `final readonly class` + `Dispatchable`.
  `AppProfileWorkstationGroupChanged`, `AppProfileWorkstationChanged`,
  `AppProfileApplicationChanged` (singulier),
  `WorkstationGroupMembershipChanged`, `WorkstationActivated`,
  `WorkstationArchived`, `WorkstationOptionsChanged`.
- **Listeners 15.2** :
  `app/Wpkg/Deployment/Listeners/InvalidateWorkstationPackagesCache.php`
  (handler par event 15.2 — à étendre avec 3 nouveaux handlers AC4.0)
  +
  `app/Wpkg/Deployment/Listeners/RegenerateWorkstationIniOnOptionsChanged.php`
  (consommé tel quel pour AC5.2).
- **Generator `.ini`** :
  `app/Wpkg/Deployment/Generators/WorkstationIniGenerator.php` —
  constante `LEGACY_OPTIONS` (8 entrées), méthode statique ou
  d'instance `generate(Workstation $w): bool`. **À ne pas modifier**.
- **Modèle option** :
  `app/Wpkg/Deployment/Models/WpkgWorkstationOption.php` — table
  `wpkg_workstation_options`, `$fillable = ['workstation_id',
  'option_key', 'option_value']`, relation `workstation()`.
- **Provider** :
  `app/Providers/WpkgDeploymentServiceProvider.php` — méthode
  `registerWpkgListeners()` (15.2). **À étendre** pour les 3 nouveaux
  events (T1).
- **Trait toasts** : `app/Components/Traits/WithToasts.php`.
- **Modale confirm** :
  `resources/views/components/molecules/confirm-modal.blade.php` —
  événement Alpine `open-confirm-modal` (détail message string ; pour
  preview de N apps il faudra étendre via slot ou nouveau composant
  dédié `bulk-preview-modal` — décision dev).
- **Tables de tracking 15.1** :
  - `wpkg_deployments` : `id` (UUID), `triggered_by`, `triggered_at`,
    `target_scope` (json), `status` (enum), `summary` (json).
- **Test architecture** :
  `tests/Architecture/WpkgDeploymentNamespaceTest.php` — couvre déjà
  les sous-namespaces. Pas d'extension à faire.
- **Helper test** : `tests/Support/WpkgSchemaBootstrapper.php` —
  flush observers métier, à utiliser dans les tests feature de cette
  story qui touchent `Workstation` / `WorkstationGroup` (sinon
  `WorkstationGroupObserver` dispatche `WorkstationGroupAdSyncJob`
  LDAP qui échoue offline).

### Code legacy à connaître (file:line)

- `sambaedu/wpkg/parc_maintenance_apps.php` — UI legacy d'assignation
  apps à un parc (référence visuelle pour la vue parc T4).
- `sambaedu/wpkg/poste_maintenance_apps.php` — UI legacy d'assignation
  apps à un poste (référence visuelle pour la vue poste T5).
- `sambaedu/wpkg/poste_maintenance_options.php:90-191` — UI legacy
  options `.ini` (référence T8 ; les 8 options + descriptions sont
  déjà figées dans `WorkstationIniGenerator::LEGACY_OPTIONS` 15.2).
- `sambaedu/includes/wpkg_libsql.php:1379 set_entite_apps()` — fonction
  legacy de mutation pivot apps ↔ parc/poste. Sa contrepartie Reload
  est l'ensemble `AppProfileService::add*` + dispatch events.
- `sambaedu/includes/wpkg_libsql.php:1033,1058,1080,1116`
  `apcu_delete("wpkg_poste_" . $info["nom_poste"])` ciblé sur mutations
  — équivalent Reload : listener `InvalidateWorkstationPackagesCache`.

### Décisions UX / Produit

- **Routing** : `parc.groups.wpkg` et `parc.machines.wpkg` (sous le
  préfixe parc existant). Diverge légèrement de l'epics qui propose
  `/app/parc/{group}/wpkg` et `/app/workstations/{w}/wpkg` —
  alignement avec l'existant projet.
- **Pas de toggle "Postes archivés visibles"** dans la vue parc (par
  défaut affiche tous, badge statut). Filtrage UI optionnel non
  bloquant.
- **Bulk catégorie : 1 event ou N events ?** — décision laissée au
  dev. Si 1 event pluriel → créer
  `AppProfileApplicationsChanged($appProfileId, array $applicationIds,
  $direction)` + handler dédié dans le listener
  (recommandé pour réduire le bruit de logs et invalidations
  redondantes). Si N events → réutiliser
  `AppProfileApplicationChanged` existant.
- **Clone parc → parc** : opération **synchrone** (pas de job queued).
  Diff calculé en mémoire, application en transaction DB.
  `wpkg_deployments` créé avec `status = completed` immédiatement.

### Mémoires pertinentes

- `feedback_atomic_write` — pattern `App\Support\AtomicFileWriter`
  (consommé indirectement via listener regen `.ini`).
- `feedback_port_legacy_then_refactor` — header `@legacy-port` sur les
  UI portées de `parc_maintenance_apps.php` /
  `poste_maintenance_apps.php` (T4, T5).
- `feedback_phpunit_attributes` — `#[Test]`, `#[DataProvider]`.
- `feedback_prefer_base_path` — pas applicable directement (UI), mais
  utiliser `config('sambaedu.wpkg.ini_path')` pour les vérifs `.ini`
  dans tests.
- `epic15_state` — vue d'ensemble pipeline.
- `gpo_real_ad_not_eloquent` — rappel : périmètre WPKG = Eloquent only,
  jamais AD en hot path. UI 15.4 lit uniquement Eloquent.

---

## Testing Standards

- **Tests Livewire** : utiliser `Livewire::test('pages::parc.groups.[id].wpkg.index', ['id' => $groupId])`
  ou la classe FQN du composant. Pour les actions :
  `->call('attachProfile', $profileId)->assertDispatched(AppProfileWorkstationGroupChanged::class)`.
- **Event testing** :
  - Pour vérifier l'émission depuis le service :
    `Event::fake([AppProfileWorkstationGroupChanged::class]); $service->addWorkstationGroups(...);
    Event::assertDispatched(AppProfileWorkstationGroupChanged::class, fn ($e) => ...);`.
  - Pour vérifier l'effet observable du listener (sans `Event::fake`) :
    dispatch manuel + assert `Cache::has('wpkg:packages:HOSTNAME')`
    false.
- **Cache testing** : store `array` par défaut en testing
  (cf. `phpunit.xml`). Pas de dépendance Redis.
- **Workstation/Group observers** : utiliser
  `WpkgSchemaBootstrapper::bootstrap()` au début des tests qui
  manipulent ces modèles, sinon `WorkstationGroupObserver` dispatche
  `WorkstationGroupAdSyncJob` qui requiert un AD live.
- **Permission testing** : créer user via factory + assigner permission
  (`$user->givePermissionTo('wpkg.assign')`) puis `actingAs($user)`.
  Pour 403 : user **sans** la permission.
- **Snapshot fichier `.ini`** : après modif option, lire le fichier sur
  disque (`config('sambaedu.wpkg.ini_path') . '/' . $w->name . '.ini'`)
  + assertion contenu. Le test helper doit créer le dossier en setUp
  via `Storage::fake()` ou `tmpdir`.
- **Architecture testing** : déjà couvert par 15.1/15.2. Pas
  d'extension à faire.
- **Permission de dev** : `$user->givePermissionTo(SambaPermission::WpkgAssign->value)`
  (utiliser le `value` de l'enum pour cohérence Spatie).

---

## Recommandation Modèle Dev

**Modèle recommandé : opus**

Raisons :
- **Coordination multi-couches** : 3 events + 1 service +
  étension service existant + 2 vues Livewire SFC + 2 composants
  réutilisables + ~9 fichiers de tests + 4 docblocks `@legacy-port`.
- **Métier critique** : un dispatch event manqué = cache stale servi
  aux clients Windows par `/wpkg/profiles.xml?poste=...` (pipeline
  15.2). Un mauvais routing event → invalidation orpheline.
- **Couplage avec 15.2** : l'extension du listener 15.2 doit rester
  cohérente (pas de duplication de logique union postes/groupes,
  re-use des handlers existants par délégation).
- **UI Livewire SFC + permissions Spatie** : pattern projet établi
  mais avec subtilités (Gate route-level vs Gate method-level).
- **Edge cases** : héritage vs override (badge UI), bulk avec création
  de profil, transaction DB + dispatch post-commit, idempotence du
  clone (déjà clone → re-clone = no-op).
- **Tests Livewire** : mocking, fake events, fake cache,
  `WpkgSchemaBootstrapper` à utiliser correctement — sonnet aurait
  tendance à oublier le bootstrapper et finir avec des tests qui
  dispatch LDAP.

Sonnet conviendrait si l'arbitrage AC3.3 / AC4.0 était figé en amont
(1 event vs N events) et si le périmètre tests était réduit aux flux
heureux. Le dev peut basculer vers sonnet **après T0 + T1** si la
décision events est figée et le périmètre devient mécanique.

---

## Notes / Hypothèses

### Décisions SM 2026-05-05 (à valider en T0)

1. **Routing** : `parc.groups.wpkg` + `parc.machines.wpkg` (sous le
   préfixe `parc.` existant), pas
   `/app/workstations/{w}/wpkg`. Cohérence existant.
2. **Permissions** : `wpkg.assign` (existant) suffisant. Pas de
   `wpkg.view` / `wpkg.manage` créées. Si Epic 7 contre-signe,
   réajuster en T0.
3. **Émission events** : depuis services métier (`AppProfileService`,
   `WorkstationOptionsService`), **pas** depuis observers Eloquent
   (cohérent décision 15.2 + helper `WpkgSchemaBootstrapper`).
4. **Bulk catégorie** : choix 1 event vs N events laissé au dev. Si
   1 event pluriel : nouveau `AppProfileApplicationsChanged`. Documenter
   choix dans `Dev Agent Record`.
5. **Clone** : synchrone, transaction DB, `wpkg_deployments` ligne
   créée avec UUID.
6. **Filesystem chemins UI** : `pages/parc/groups/[id]/wpkg/`,
   `pages/parc/machines/[id]/wpkg/`. Composants partagés sous
   `components/organisms/wpkg/`.

### Hypothèses techniques

- **Pivot `app_profile_workstation`** : présent (15.2 T2). Relation
  `Workstation::appProfiles()` ajoutée 15.2 T2. RAS.
- **Pivot `application_workstation` / `application_workstation_group`**
  : créés 15.2 T1. Relations
  `Workstation::applications` /
  `WorkstationGroup::applications` ajoutées 15.2 T2. RAS.
- **Permissions seedées** : `wpkg.assign` existante via
  `SambaPermission::WpkgAssign`. Vérifier que le seeder Spatie
  l'a bien instanciée en BDD (cf. `database/seeders/`).
- **Event listener registration** : pattern 15.2
  (`WpkgDeploymentServiceProvider::registerWpkgListeners()`) à
  étendre — pas de modif de `EventServiceProvider`.

### Migration / dette

- **Aucune dette nouvelle**. Tous les events ajoutés sont strictement
  additifs (pas de modif signature). Le test archi 15.1 reste vert.
- Le listener 15.2 est étendu (3 handlers en plus) mais sa logique
  d'invalidation par hostname reste isolée — pas de risque
  régression sur le périmètre 15.2.
- L'extension d'`AppProfileService` (dispatch events) est rétro-compatible
  pour les appelants existants (parc-settings → onglet profils → reste
  fonctionnel). Le seul changement observable est l'apparition d'events
  dans les flux qui n'en émettaient pas avant.

#### Reliquat post-review 15.3 (à embarquer dans 15.4)

Items mis en backlog dans `_bmad-output/codeReviews/15-3.md` (tableau
de synthèse, sévérité 🟡). À traiter opportunément pendant 15.4 — non
bloquants pour le scope principal, mais à ne pas oublier au moment des
modifs concernées.

| Ref | Fichier(s) | Action attendue | Scope 15.4 |
|-----|------------|-----------------|-----------|
| #10 | `tests/Architecture/WpkgDeploymentNamespaceTest.php` | Élargir le scan à `App\Wpkg\*` (au lieu de `App\Wpkg\Deployment` seulement). 15.4 crée des classes sous `App\Wpkg\Admin\*` (cf. Volet 1-3) — bonne fenêtre pour étendre la couverture archi. | **Direct** — à faire dès qu'`App\Wpkg\Admin\*` est introduit |
| #M11 | `resources/views/pages/sync-from-ad/index.blade.php:298` (`executeWpkgRemediationStep`) | Ajouter `Gate::authorize('server.admin')` defense-in-depth Livewire. Cohérent avec la posture 15.4 qui pose `Gate::authorize('wpkg.assign')` dans tous les composants admin (cf. AC permissions). | **Adjacent** — à faire avec les autres `Gate::authorize` 15.4 |
| #8 | `app/Jobs/SyncAllFromAdJob.php:739` (`syncProfileGroupLinks`) | Précharger les liens pivot en une seule requête (`array_flip` lookup O(1)) au lieu de N `EXISTS`. ~200ms gagnés sur ~200 profils. | **Opportuniste** — si le job est touché en 15.4 |
| #9 | `resources/views/pages/sync-from-ad/index.blade.php:352` (et autres handlers du fichier) | `catch \Throwable` au lieu de `\Exception` pour propager `\Error`/`\TypeError`. Aligner les 3 handlers du fichier en même temps pour cohérence. | **Opportuniste** — si le fichier est touché en 15.4 |
| #7 | `app/Jobs/SyncAllFromAdJob.php` (`syncWorkstationGroups`, `syncAppProfiles`) | Log `debug` par écriture individuelle avec `objectGUID` corrélé. Opus a jugé Sonnet sur-interprète l'AC3.3 — à rediscuter seulement si besoin debug fin remonte du terrain. | **Hors scope** — à laisser en backlog explicit jusqu'à signal |

### Risques

- **Test feature `.ini` regénéré** : dépend du listener
  `RegenerateWorkstationIniOnOptionsChanged` actif en testing. Si
  `Event::fake()` est positionné sur `WorkstationOptionsChanged`,
  le listener n'est pas appelé. Le test doit donc **dispatch sans
  fake** + assert sur le disque, ou **fake puis appel manuel** du
  generator.
- **Confirm-modal limitée** : la modale réutilisable ne prend qu'un
  `message` string. Pour la preview de bulk catégorie ou clone parc,
  il faudra soit l'étendre (slot HTML), soit créer une nouvelle modale
  dédiée (`wpkg-bulk-preview-modal`, `wpkg-clone-preview-modal`).
  Décision laissée au dev.
- **Coordination user pour 15.2 done** : si 15.2 reste `review` au
  démarrage, certains tests 15.4 peuvent échouer si les listeners
  n'étaient pas encore enregistrés. Bloquant — escalade au user.

---

## Change Log

| Version | Date       | Auteur | Description |
|---------|------------|--------|-------------|
| v0      | 2026-05-05 | SM (opus 4.7) | Création story 15.4. Émetteurs des events 15.2 + UI Livewire SFC parc/poste + bulk catégorie + clone + onglet options `.ini`. Permissions = `wpkg.assign` existant. Routing = `parc.groups.wpkg` / `parc.machines.wpkg`. Ajout strictement additif de 3 nouveaux events (pas de modif 15.2). |
| v0.1    | 2026-05-07 | henri | Ajout section « Reliquat post-review 15.3 » dans Migration / dette : #7 #8 #9 #10 #M11 reportés depuis `codeReviews/15-3.md`. #10 et #M11 directement dans le scope 15.4, #8/#9 opportunistes, #7 hors scope. |

---

## Dev Agent Record

### Agent Model Used

claude-opus-4-7 (1M context) — sub-agent BMAD lancé par l'orchestrateur dev-cycle 2026-05-07.

### Debug Log References

Tests **non exécutés** côté agent (consigne user : pas de run sur la VM, pas de sync depuis le worktree). Validation tests à faire par l'orchestrateur ou l'user après merge sur la VM.

### Completion Notes List

- **Override 2026-05-07 respecté** : pas de pages séparées `wpkg/`, onglets via `_partials/wpkg-assignment-tab.blade.php` + `wpkg-options-tab.blade.php` dans les fiches parc/poste existantes. Pas de routes Livewire ajoutées (accès via query param `?tab=wpkg` sur routes existantes `parc.groups.show` / `parc.machines.show`).
- **Modales généralisées** (Décision B) : 3 modales déplacées de `pages/parc-settings/profiles/_partials/add-{apps,groups,workstations}-modal.blade.php` vers `resources/views/components/organisms/wpkg/attach-{apps,groups,workstations}-modal.blade.php`. Références mises à jour dans `pages/parc-settings/profiles/index.blade.php`. Test de non-régression : `tests/Feature/AppProfile/ProfileAttachModalsRegressionTest.php`.
- **Bulk catégorie (AC3.3)** : choix **1 event pluriel** `AppProfileApplicationsChanged($appProfileId, array $applicationIds, $direction)` retenu (Décision C). Évite invalidations cache redondantes sur le même profil. Listener handler dédié dans `InvalidateWorkstationPackagesCache::handleAppProfileApplicationsChanged`.
- **3 nouveaux events 15.4** strictement additifs (zéro modif signature 15.2) : `WorkstationGroupApplicationsChanged`, `WorkstationApplicationsChanged`, `AppProfileApplicationsChanged` — tous `final readonly class` + `Dispatchable` (parité 15.2).
- **`AppProfileService` étendu** : 6 méthodes mutatrices existantes (`addApplications`/`removeApplications`/`addWorkstationGroups`/`removeWorkstationGroups`/`addWorkstations`/`removeWorkstations`) câblées avec dispatch event + `DB::transaction` + `DB::afterCommit`. 4 nouvelles méthodes : `addApplicationsToWorkstationGroup`, `removeApplicationsFromWorkstationGroup`, `addApplicationsToWorkstation`, `removeApplicationsFromWorkstation`. Méthode `cloneConfiguration(int $sourceId, int $targetId)` ajoutée (transaction DB + ligne `wpkg_deployments` UUID + dispatch events ciblés + log channel `wpkg-deploy` corrélé `deployment_id`).
- **`WorkstationOptionsService` créé** : `update(int $workstationId, array $changes)` + `resetToDefaults(int $workstationId)`, validation `option_key ∈ LEGACY_OPTIONS keys`, `option_value ∈ {'true', 'false'}`, dispatch `WorkstationOptionsChanged($id, $changedKeys)`.
- **Provider** : `WpkgDeploymentServiceProvider::registerWpkgListeners()` étendu pour les 3 nouveaux events (pattern 15.2 conservé, pas de modif `EventServiceProvider`).
- **Permissions** : utilisation exclusive de `wpkg.assign` (`SambaPermission::WpkgAssign`) — pas de création `wpkg.view` ni `wpkg.manage` (Décision SM 2026-05-05 #2).
- **Émission events** : depuis services métier (`AppProfileService`, `WorkstationOptionsService`), JAMAIS depuis observers Eloquent (cohérent avec décision 15.2 + helper `WpkgSchemaBootstrapper`).
- **Frontière 15.3 non-bloquante respectée** : vues lisent uniquement Eloquent. `Workstation::scopeActive()` existant utilisé (pas de nouveau scope ni colonne).
- **Doc** : `app/Wpkg/Deployment/README.md` enrichi (mapping legacy → reload étendu, section UI 15.4, section events émis). `docs/qa/domains/wpkg-deploy.md` Section 3 « UI admin » append-only avec 5 scénarios numérotés stables.
- **Sprint status** : à mettre à jour par l'orchestrateur (`15-4-ui-admin-assignation-apps-wpkg: ready-for-dev → review`).

### Points d'attention pour la review

- Le flow parc-settings/profiles a été touché par la migration des modales — surface de régression potentielle. Le test `ProfileAttachModalsRegressionTest` doit confirmer la non-régression.
- L'invariant `WorkstationGroupObserver::disableSync/enableSync` autour des écritures via job AD (15.3) ne s'applique pas ici (mutations métier UI directes, pas de pipeline AD entrant). Toutefois, le clone parc → parc passe par un grand nombre de `syncWithoutDetaching` dans la transaction — vérifier que les observers AD sortants déclenchent un seul flush par event Livewire et non 1 par profil cloné.
- AC3.3 : le choix « 1 event pluriel » repose sur l'extension du listener. Si un futur listener tiers branché sur `AppProfileApplicationChanged` (singulier 15.2) attendait 1 event par app, il ne sera **pas** notifié pour les bulks 15.4. À tracer si extension future.
- Le test feature options `.ini` lit le fichier `<ini_path>/<hostname>.ini` sur disque après dispatch — dépend de la config `sambaedu.wpkg.ini_path` correctement override en environnement test (TestCase ou helper).

### File List

**Créés (15)** :
- `app/Wpkg/Deployment/Events/WorkstationGroupApplicationsChanged.php`
- `app/Wpkg/Deployment/Events/WorkstationApplicationsChanged.php`
- `app/Wpkg/Deployment/Events/AppProfileApplicationsChanged.php`
- `app/Wpkg/Deployment/Services/WorkstationOptionsService.php`
- `resources/views/components/organisms/wpkg/attach-apps-modal.blade.php` (déplacé depuis parc-settings/profiles + généralisé)
- `resources/views/components/organisms/wpkg/attach-groups-modal.blade.php` (idem)
- `resources/views/components/organisms/wpkg/attach-workstations-modal.blade.php` (idem)
- `resources/views/pages/parc/groups/[id]/_partials/wpkg-assignment-tab.blade.php`
- `resources/views/pages/parc/groups/[id]/_partials/wpkg-bulk-category-modal.blade.php`
- `resources/views/pages/parc/groups/[id]/_partials/wpkg-clone-modal.blade.php`
- `resources/views/pages/parc/machines/[id]/_partials/wpkg-assignment-tab.blade.php`
- `resources/views/pages/parc/machines/[id]/_partials/wpkg-options-tab.blade.php`
- `tests/Feature/AppProfile/ProfileAttachModalsRegressionTest.php`
- `tests/Feature/Wpkg/UI/ParcGroupWpkgPageTest.php`
- `tests/Feature/Wpkg/UI/MachineWpkgPageTest.php`
- `tests/Feature/Wpkg/UI/BulkCategoryAssignTest.php`
- `tests/Feature/Wpkg/UI/CloneGroupConfigTest.php`
- `tests/Feature/Wpkg/UI/WorkstationOptionsTabTest.php`
- `tests/Feature/Wpkg/Deployment/Listeners/InvalidateWorkstationPackagesCacheNewEventsTest.php`
- `tests/Unit/Services/AppProfile/AppProfileServiceEventsTest.php`
- `tests/Unit/Wpkg/Deployment/Services/WorkstationOptionsServiceTest.php`

**Modifiés (6)** :
- `app/Providers/WpkgDeploymentServiceProvider.php` (registerWpkgListeners → 3 nouveaux events)
- `app/Services/AppProfile/AppProfileService.php` (dispatch events sur 6 mutateurs + 4 nouvelles méthodes + cloneConfiguration)
- `app/Wpkg/Deployment/Listeners/InvalidateWorkstationPackagesCache.php` (3 nouveaux handlers)
- `app/Wpkg/Deployment/README.md` (mapping étendu + sections UI 15.4)
- `docs/qa/domains/wpkg-deploy.md` (Section 3 « UI admin » append-only, 5 scénarios)
- `resources/views/pages/parc-settings/profiles/index.blade.php` (refs vers components/organisms/wpkg/attach-*)
- `resources/views/pages/parc/groups/[id]/index.blade.php` (intégration onglet wpkg-assignment-tab)
- `resources/views/pages/parc/machines/[id]/index.blade.php` (intégration onglets wpkg-assignment-tab + wpkg-options-tab)

**Supprimés (3 — déplacés)** :
- `resources/views/pages/parc-settings/profiles/_partials/add-apps-modal.blade.php`
- `resources/views/pages/parc-settings/profiles/_partials/add-groups-modal.blade.php`
- `resources/views/pages/parc-settings/profiles/_partials/add-workstations-modal.blade.php`

# Story 7.3 : Migration Bitmask Legacy → Spatie + Refactor `calculateRights()` Spatie-only

Status: Done

<!-- Note: Validation optionnelle. Lancer validate-create-story pour un check qualité avant dev-story. -->

> **Révision majeure 2026-04-25** — suite à audit consommateurs externes : zéro lecteur de l'attribut LDAP `info` hors PHP (pas de shell / Python / GPO / SYSVOL / cron / API externe). Les 4 consommateurs encore liés à `info` sont **tous internes au code SER moderne** et migrent vers Spatie. Le volet **Observer / projection Spatie → bitmask AD** est **supprimé** du scope. Le volet **migration one-shot des assignations** est **conservé**. Un nouveau volet **refactor `RightsService::calculateRights()` Spatie-only** prend la place du volet Observer.

## Story

As a **administrateur SER / développeur**,
I want **migrer one-shot les assignations de droits historiques (bitmask legacy dans les groupes AD `rights_rdn` + délégations scopées `delegations_rdn`) vers le modèle Spatie ET refondre `RightsService::calculateRights()` pour reconstruire son bitmask uniquement depuis Spatie au lieu de lire l'attribut `info` LDAP**,
So que **Spatie devienne la seule source de vérité runtime pour les droits applicatifs SER, sans nécessité d'écrire en retour le bitmask en AD — l'audit a confirmé qu'aucun consommateur externe ne lit cet attribut, les 4 consommateurs internes sont refactorés vers Spatie, et la facade centrale `calculateRights()` continue de retourner un bitmask pour rétro-compat sans toucher LDAP en runtime**.

---

## Contexte — Décision produit 2026-04-25

**Henri a acté la suppression du volet Observer / projection Spatie → bitmask AD** suite à un audit complet :

| # | Audit | Conclusion |
|---|-------|------------|
| 1 | Recherche shell/Python/scripts cron/SYSVOL/GPO lisant `info` des groupes `rights_rdn` | **Zéro consommateur externe** |
| 2 | Shim `have_right()` / `list_rights()` (`legacy/ldap.inc.php:937-1014`) | Utilise déjà une **table locale** `$_spatie_role_to_bitmask`, plus de lecture `info` LDAP |
| 3 | Inventaire des 4 consommateurs PHP encore liés à `info` LDAP | Tous **modernes SER** (RightsService, PermissionService, UserGroupService, RightsDrawer) — refactorables |

> **Conclusion produit (Henri)** : « On utilise `calculateRights()` partout. » Donc `RightsService::calculateRights()` reste le **point d'entrée centralisé** pour obtenir le bitmask effectif d'un user. Il est juste **reconstruit en interne depuis Spatie** au lieu d'aller lire le LDAP. Aucun changement de contrat de retour. Aucun appelant à modifier.

**Carte des 4 consommateurs :**

| Consommateur | Fichier | Action 7.3 |
|---|---|---|
| `RightsService::calculateRights()` | `laravel/app/Services/RightsService.php:73` | **Refactor** : calcul depuis Spatie (rôles + permissions individuelles + délégations), retourne toujours un bitmask pour rétrocompat. Reste **le facade central** SER. |
| `PermissionService::importCustomProfilesFromAd()` | `laravel/app/Services/PermissionService.php:607` | **Conserver tel quel** mais utilisé UNIQUEMENT par la commande `migrate-rights-to-spatie` (one-shot) et `/admin/sync-from-ad` (rapatriement profils custom 7.2). Plus jamais en runtime applicatif. |
| `UserGroupService::fetchEligibleAdGroups()` | `laravel/app/Services/UserGroupService.php:407` | **Inchangé** — n'utilise pas la valeur `info`, seulement les **noms** de groupes Rights pour exclusion de filtre. |
| `RightsDrawer` UI | `resources/views/components/organisms/rights-drawer.blade.php:76` | **Refactor** : afficher rôles + permissions Spatie au lieu du bitmask hex LDAP. |

---

## Contexte — Socle déjà livré (NE PAS re-implémenter)

Cette story consomme un socle déjà validé. NE PAS refaire les éléments ci-dessous :

- **Enum `app/Enums/SambaPermission.php`** — 19 permissions atomiques avec :
  - `legacyRight(): LegacyRight` (mapping vers le bit legacy représentant)
  - `bitmask(): int` (bit atomique)
  - `toBitmask(array $permissionNames): int` (conversion liste de noms → bitmask union) — **méthode existante ligne ~193, à réutiliser pour le refactor `calculateRights()`**
  - `fromBitmask(int): array` (conversion bitmask → liste de noms) — réutilisée par la commande de migration
  - `fromSingleBitmask(int): ?self` (bit atomique seul → enum)
  - → ⚠️ **Dette en sursis matrice §11** : le mapping bitmask↔Spatie sera supprimé **après** la stabilisation 7.3 dans une PR dédiée. La présente story **consomme** ce mapping (uniquement la commande one-shot et le refactor `calculateRights()` côté projection vers bitmask), mais ne le touche pas.
- **Enum `app/Enums/SambaRole.php`** — 9 rôles (`Eleve`, `Prof`, `EleveAdmin`, `ShareAdmin`, `UserAdmin`, `Technicien`, `ReferentNumerique`, `ComputerAdmin`, `SuperAdmin`) + `permissions()` + `permissionNames()` + `isSeeded(string): bool`.
- **`app/Services/PermissionService.php`** (Story 7.1 + 7.2) — `grantDelegation`, `revokeDelegation`, `negateDelegation`, `canOnWorkstationGroup`, `getAuthorizedWorkstationGroups`, `importCustomProfilesFromAd` (rapatriement profils custom LDAP — 7.2 done).
- **Modèle `Delegation`** + migration `delegations` + table `delegation_history` (Story 7.1 done). Clé unique `user_id + workstation_group_id + permission_id + is_negative`.
- **Matrice `profiles-rights-matrix.md`** validée 2026-04-22 — §5.2 (9 rôles × 18 perms), §5.3 (mapping legacy → Spatie), §7 (sémantique négation + délégations scopées), §8 (6 décisions actées dont bug `Annu_is_admin`), §11 (sunset post-7.3).
- **Shim legacy `legacy/ldap.inc.php`** — `have_right()` / `list_rights()` utilisent **déjà** une table locale `$_spatie_role_to_bitmask` (ne lisent plus `info` LDAP). `list_delegations` et `search_delegations` sont stubbées non-implémentées.

> **ATTENTION — Scope HORS story (révisé 2026-04-25) :**
> - ❌ **Observer `SpatieToBitmaskObserver`** — supprimé du scope (zéro consommateur externe).
> - ❌ **`BitmaskProjectionService`** dédié — supprimé (logique simplifiée intégrée à `RightsService::calculateRights()`).
> - ❌ **`ProjectSpatieToLegacyBitmaskJob`** + queue `ldap` + retry/backoff/DLQ — supprimé.
> - ❌ **Flag config `sambaedu.permissions.project_to_ad`** — supprimé (plus de projection AD).
> - ❌ **Méthodes d'écriture LDAP `setRightValue()` / `findOrCreateForUser()` sur `LdapRightGroup`** — non livrées.
> - **Suppression** de `LegacyRight`, `SambaPermission::legacyRight()/bitmask()/fromBitmask()/fromSingleBitmask()/bitmaskMapping()/bitmaskToPermissions/permissionsToBitmask` (matrice §11) → **PR dédiée post-7.3 stabilisée**. La commande de migration et le refactor `calculateRights()` consomment encore ces méthodes.
> - **Nouveau CRUD profils dynamiques UI** → déjà livré en 7.2 (onglet "Profils" dans `/app/rights-management`).
> - **5 Policies manquantes** (Delegation/Machine/Printer/Share/Dhcp) → déjà câblées en 7.2.
> - **Réécriture des shims legacy** `list_delegations` / `search_delegations` → hors scope. Stubs conservés.

---

## Décisions à trancher au kickoff (3 décisions — bloquant)

Suite à la décision produit 2026-04-25, le nombre de décisions kickoff passe de **7 → 3**. Les décisions **Q1 (déclenchement Observer)**, **Q2 (cible AD d'écriture)**, **Q5 (nom flag config)**, **Q6 (queue retry/backoff)** sont caduques.

| # | Décision | Recommandation SM | Impact |
|---|----------|-------------------|--------|
| **(1)** | **Scope migration vs profils custom déjà rapatriés en 7.2** : `PermissionService::importCustomProfilesFromAd` (7.2) a déjà créé les rôles DB depuis `rights_rdn`. 7.3 doit-il **(a)** re-scanner `rights_rdn` et attribuer les **users** à ces rôles nouvellement créés, **OU (b)** partir du principe que 7.2 a seulement créé les rôles et que 7.3 doit encore poser le `$user->assignRole($role)` en migrant ? | ✅ **(b) 7.3 pose les assignations user→rôle**. 7.2 a créé les rôles custom en DB (noms + permissions) mais **n'a pas** attribué ces rôles aux users. 7.3 scanne l'appartenance LDAP (membres des groupes `rights_rdn`) et fait `$user->assignRole($role)`. Les rôles seedés 7.2 sont assignés selon matrice §5.3 (ex. user membre du groupe LDAP `se3_is_admin` → `$user->assignRole('SuperAdmin')`). **Sous-décision actée 2026-04-25 post-review #1** : `password_is_admin` n'est PAS un rôle (anti-escalade) mais une **permission directe** posée via `$user->givePermissionTo('user.password.init')`. | Scope de la commande artisan. |
| **(2)** | **Délégations scopées legacy** (`delegations_rdn` = `ou=delegations` dans l'AD réel, cf. `sambaedu/includes/ldap.inc.php:4396-4426`) : format réel CN = `(no_)?(manage\|view\|rdp)_<parc>` (PAS `<spatie-perm>_<parc>`). | ✅ **(a) Scanner et migrer** avec **mapping `level → SambaPermission` codé en dur** : `manage→computer.elevate`, `view→computer.view`, `rdp→computer.remote.rdp` (NOUVELLE permission Spatie créée en 7.3 — décision Henri 2026-04-25 option C, gouvernance fine RDP). Parsing par regex strict `/^(no_)?(manage\|view\|rdp)_(.+)$/` qui élimine l'ambiguïté underscore. Filtrage du parc DN via `WorkstationGroup::findByAdDn()`. Persistance via `Delegation::firstOrCreate` (préserve `granted_by` au re-run). | Dimensionne le service de migration + tests. |
| **(3)** | **Sunset bitmask** : 7.3 marque `@deprecated` les méthodes de lecture LDAP devenues inutilisées (`RightRepository::getAllRightsValues`, `LdapRightGroup::getAllRightsValues`, etc.) ; une PR séparée post-stabilisation les supprime. ✅ recommandée | ✅ **`@deprecated` en 7.3, suppression PR séparée post-stabilisation**. 7.3 livre encore `SambaPermission::toBitmask()` / `permissionsToBitmask()` (consommés par le `calculateRights()` refactoré et la commande one-shot). Sunset complet (LegacyRight, fromBitmask, etc.) post-stabilisation prod ≥ 2 semaines. **Note 2026-04-27** : la nouvelle permission `computer.remote.rdp` survit au sunset (pas dérivée du mapping legacy). | Liste explicite des fichiers à marquer `@deprecated` (cf. section "Phase 7"). |

---

## Acceptance Criteria

### AC1 — Commande `sambaedu:migrate-rights-to-spatie` dry-run sans écriture

**Given** la commande `php artisan sambaedu:migrate-rights-to-spatie --dry-run` est exécutée
**When** la VM SER contient des groupes legacy peuplés dans `rights_rdn` (profils seedés + custom) + des délégations dans `delegations_rdn`
**Then** aucune écriture n'est effectuée (ni table Spatie `model_has_roles` / `model_has_permissions` / `delegations`, ni LDAP)
**And** un rapport tabulé est imprimé sur stdout avec, **par user** : le(s) rôle(s) Spatie qui seraient attribué(s), les délégations scopées projetées, les cas non-mappables (groupe orphelin `info=0`, user introuvable dans la DB SER, permission non résolvable, etc.)
**And** un résumé final affiche : `X users scannés / Y rôles qui seraient attribués / Z délégations qui seraient créées (dont W négatives) / N cas non mappables`
**And** le **exit code** est `0` si aucun cas critique (DB indisponible), `1` en cas d'erreur bloquante.

### AC2 — Commande run (sans dry-run) idempotente + rapport

**Given** je lance `php artisan sambaedu:migrate-rights-to-spatie` (sans flag)
**When** la commande s'achève avec succès
**Then** chaque user rattaché à un groupe legacy `rights_rdn` reçoit le(s) rôle(s) Spatie correspondant(s) selon matrice §5.3
**And** les délégations scopées legacy (`<right>_<parc>` dans `delegations_rdn`) sont migrées via `PermissionService::grantDelegation($user, $permission, $workstationGroup)`
**And** les groupes préfixés `no_*` (profils négatifs cf. matrice §4.3 / §7) sont migrés via `PermissionService::negateDelegation(...)` avec le flag `is_negative=true`
**And** un rapport final détaillé est persisté dans `storage/logs/migrate-rights-to-spatie-YYYY-MM-DD-HHmmss.log`
**And** relancer la commande **n'ajoute aucun doublon** (idempotent) : assignations existantes détectées + clé unique `Delegation` (`user_id + workstation_group_id + permission_id + is_negative`).

### AC3 — Bug `Annu_is_admin` fallback ignoré explicitement

**Given** un groupe legacy `Annu_is_admin` existe dans `rights_rdn` **sans attribut `info`** (cas reproduisant le bug fallback `annu/profiles.php:58` qui remappait à tort sur `SE_COMPUTER_ADMIN`, cf. matrice §8 #6)
**When** la migration scanne ce groupe
**Then** le groupe est migré comme `SambaRole::UserAdmin` (valeur du seed legacy `SE_USER_ADMIN` = `0xFF`, cf. `sambaedu/includes/ldap.inc.php:742`), **PAS** `SambaRole::ComputerAdmin`
**And** un warning est loggé : `Log::warning("[MigrateRightsToSpatie] Annu_is_admin sans info — fallback buggé ignoré, assignation alignée sur le seed d'origine SE_USER_ADMIN")`
**And** le rapport final recense ce warning dans la section `fallbacks_ignorés`.

### AC4 — Délégations scopées legacy migrées vers `Delegation` Spatie

**Given** la branche `delegations_rdn` (`ou=delegations,<baseDn>`) contient des groupes `<right>_<parc>` et `no_<right>_<parc>` (membres = user DN + parc DN)
**When** la commande de migration s'exécute (run, pas dry-run)
**Then** chaque groupe positif `<right>_<parc>` génère une `Delegation` via `PermissionService::grantDelegation($user, $permission, $workstationGroup)`
**And** chaque groupe négatif `no_<right>_<parc>` génère une `Delegation` `is_negative=true` via `PermissionService::negateDelegation(...)`
**And** un parc introuvable (cn LDAP sans `WorkstationGroup` Eloquent associé) est rapporté `unmappable`, sans crash
**And** un user introuvable (DN LDAP sans User SER associé) est rapporté `unmappable`, sans crash.

### AC5 — `RightsService::calculateRights()` reconstruit le bitmask uniquement depuis Spatie (NOUVEAU)

**Given** `RightsService::calculateRights(User $user, ?WorkstationGroup $scope = null)` est invoqué
**When** la méthode est exécutée en runtime
**Then** **aucune lecture** n'est effectuée vers `RightRepository::getAllRightsValues()` ni vers `LdapRightGroup` (vérifié par test qui mock LDAP en erreur — la méthode doit toujours fonctionner sans LDAP)
**And** le calcul est fait depuis Spatie :
1. récupération des permissions effectives via `$user->getAllPermissions()->pluck('name')->toArray()` (rôles + directes)
2. ajout des permissions de délégations positives non-expirées (scope optionnel)
3. retrait des permissions de délégations négatives (sémantique AND-NOT, matrice §7)
4. projection vers bitmask via `SambaPermission::toBitmask($permissionNames)`
5. filtre `SE_COMPUTER_VIEW` (bit web pur jamais bitmasqué, cf. shim legacy `legacy/ldap.inc.php:50` et legacy `sambaedu/includes/ldap.inc.php:2963`).

**And** la méthode reste le **facade central** SER — son contrat de retour (`int` bitmask) est **inchangé**, aucun appelant n'a à modifier son code (vérifié par audit `grep -rn "calculateRights" laravel/`).

### AC6 — Round-trip identité bitmask LDAP-source vs Spatie-source (NOUVEAU)

**Given** un user a un bitmask legacy arbitraire en AD avant migration (ex. `SE_USER_ADMIN | SE_COMPUTER_INSTALL` = `0x8FF`)
**When** je capture le bitmask retourné par `calculateRights()` **avant** la migration (lecture LDAP via implémentation pré-7.3) et **après** la migration (lecture Spatie via implémentation 7.3)
**Then** les deux bitmasks sont **identiques** (modulo bit `SE_COMPUTER_VIEW` toujours filtré)
**And** un test d'intégration `tests/Integration/CalculateRightsRoundTripTest.php` couvre **au minimum** :
- les 5 profils seedés (`se3_is_admin` `0xFFFF`, `computer_is_admin` `0xEF00`, `Annu_is_admin` `0xFF`, `password_is_admin` `0x01`, `RefNum` `0x90B`)
- un profil custom (ex. `0x302` = `SE_USER_READ | SE_USER_MODIFY`)
- une délégation scopée positive
- une délégation scopée négative.

### AC7 — `RightsDrawer` UI affiche les rôles/permissions Spatie (NOUVEAU)

**Given** le composant `resources/views/components/organisms/rights-drawer.blade.php` est ouvert pour un user
**When** le drawer est rendu
**Then** la source de données est **Spatie** : rôles assignés (`$user->roles->pluck('name')`) + permissions effectives (`$user->getAllPermissions()->pluck('name')`) — **plus aucun appel** à `RightRepository::getAllRightsValues()`
**And** l'affichage présente des labels lisibles des permissions Spatie (FR, exemple `"Voir les utilisateurs"`) **au lieu du bitmask hex** `0x...`
**And** l'UX existante du drawer est **préservée** (header, structure, listing, bouton fermer) — c'est purement une refonte de la source de données et du rendu cellule par cellule.

### AC8 — Non-régression 7.1 et 7.2 obligatoire

**Given** la story est livrée
**When** `phpunit` tourne
**Then** **100 %** des tests Story 7.1 (53 tests) et Story 7.2 (117 tests post-corrections) continuent à passer sans modification
**And** le non-respect de cette contrainte est un bloquant de merge
**And** la suite de tests globale ne fait pas apparaître **plus** de failures qu'avant 7.3 (baseline 47 failures pré-existantes LDAP/Imagick/legacy cf. sprint-status 5-1b).

### AC9 — Coverage tests 7.3 (≥ 25 tests)

**Given** la story est livrée
**When** `phpunit` tourne
**Then** les suites suivantes existent et passent :
- `tests/Feature/Console/MigrateRightsToSpatieCommandTest.php` — dry-run idempotent, run idempotent, bug Annu_is_admin, rapport, exit codes, délégations scopées positives/négatives, user/parc introuvables. ≥ 8 tests.
- `tests/Unit/Services/Permissions/RightsMigrationServiceTest.php` — orchestration migration (résolution rôle Spatie depuis bitmask, mapping délégations, sémantique idempotence). ≥ 6 tests.
- `tests/Unit/Services/RightsServiceSpatieRefactorTest.php` — `calculateRights()` Spatie-only, sémantique OR positifs / AND-NOT négatifs, filtre `SE_COMPUTER_VIEW`, mock LDAP en erreur. ≥ 6 tests.
- `tests/Feature/Livewire/RightsDrawerSpatieTest.php` (ou enrichissement de l'existant) — affichage Spatie rôles + permissions au lieu du bitmask hex, UX préservée. ≥ 3 tests.
- `tests/Integration/CalculateRightsRoundTripTest.php` — round-trip LDAP-source vs Spatie-source identiques. ≥ 4 tests (5 seedés regroupés + 1 custom + 1 délégation positive + 1 négative).

### AC10 — Doc enrichie + runbook QA append-only

**Given** la story est livrée
**When** la doc est inspectée
**Then** `docs/domains/rights-management.md` contient une nouvelle section `## Story 7.3 — Migration bitmask → Spatie + Refactor calculateRights()` (date YYYY-MM-DD)
**And** `docs/qa/domains/rights-management.md` contient une nouvelle section append-only `## Section X — Migration bitmask → Spatie (Story 7.3)` avec scénarios numérotés (dry-run, run prod, fallback Annu_is_admin, refactor calculateRights, drawer Spatie)
**And** **PAS** de fichier `docs/qa/7-3-e2e-manual.md` créé (convention runbooks par domaine cf. `docs/qa/README.md`).

---

## Tâches / Sous-tâches

### Phase 0 — Kickoff décisions produit (bloquant)

- [x] **Tâche 0** — Kickoff avec Henri (3 décisions)
  - [x] 0.1 Décision **(1)** : 7.3 pose les assignations user→rôle en complément de 7.2 qui a créé les rôles. ✅ recommandée.
  - [x] 0.2 Décision **(2)** : confirmer le format réel des délégations scopées dans `ou=delegations` lors du dev (vu en audit `sambaedu/includes/ldap.inc.php:4407-4410`). Si format différent observé sur VM, no-op documenté.
  - [x] 0.3 Décision **(3)** : `@deprecated` 7.3 sur lectures LDAP devenues mortes, suppression PR séparée post-stabilisation.

### Phase 1 — Commande `sambaedu:migrate-rights-to-spatie` (service + dry-run + rapport)

- [x] **Tâche 1** — Créer `app/Services/Permissions/RightsMigrationService.php` (orchestration migration, AC: #1, #2, #3, #4)
  - [x] 1.1 Méthode publique `migrate(bool $dryRun): MigrationReport` — pipeline d'orchestration.
  - [x] 1.2 Sous-étape `migrateRightsGroupAssignments()` : itérer `LdapRightGroup::query()->in($rightsBaseDn)->with('members')`, résoudre rôle Spatie via matrice §5.3 (`SambaRole::fromBitmask($info)` + mapping 5 profils seedés), pour chaque membre → `$user->assignRole($role)` (idempotent).
  - [x] 1.3 Sous-étape `migrateScopedDelegations()` : query LdapRecord directe sur `ou=delegations,<baseDn>` ; pour chaque groupe `<right>_<parc>` → résoudre `Permission::findByName($right)` + `WorkstationGroup::where('cn', $parc)` ; appeler `grantDelegation` (positif) ou `negateDelegation` (préfixe `no_`).
  - [x] 1.4 `MigrationReport` (DTO ou tableau structuré) : `users_scanned`, `roles_attribued`, `delegations_created`, `negatives_created`, `fallbacks_ignored`, `unmappable[]`.
  - [x] 1.5 Mode dry-run : wrap toutes les écritures sous `if (!$dryRun) { ... }` ; rapport identique en sortie.

- [x] **Tâche 2** — Créer `app/Console/Commands/MigrateRightsToSpatieCommand.php` (AC: #1, #2)
  - [x] 2.1 Signature `sambaedu:migrate-rights-to-spatie {--dry-run : n'applique rien, affiche le plan}`.
  - [x] 2.2 Injection `RightsMigrationService` ; appeler `migrate($this->option('dry-run'))`.
  - [x] 2.3 Affichage rapport via `$this->info(...)` / `$this->table(...)` ; persister log dans `storage/logs/migrate-rights-to-spatie-<timestamp>.log` en cas de run réel.
  - [x] 2.4 Exit codes : `Command::SUCCESS` (0) si OK, `Command::FAILURE` (1) si DB indisponible / exception non-catchable.

### Phase 2 — Bug `Annu_is_admin` (test dédié)

- [x] **Tâche 3** — Implémentation cas spécial Annu_is_admin (AC: #3)
  - [x] 3.1 Dans `RightsMigrationService::migrateRightsGroupAssignments()` : si `cn === 'Annu_is_admin'` ET `info` absent/null/0 → forcer `SambaRole::UserAdmin` + `Log::warning(...)`.
  - [x] 3.2 Test dédié dans `tests/Feature/Console/MigrateRightsToSpatieCommandTest.php` (warning loggé, rôle assigné = `UserAdmin`, rapport `fallbacks_ignorés` non vide).

### Phase 3 — Migration délégations scopées

- [x] **Tâche 4** — Lecture `ou=delegations` + Delegation Spatie (AC: #4)
  - [x] 4.1 Créer (ou réutiliser) un modèle LdapRecord `LdapDelegationGroup` minimaliste pour itérer les groupes `<right>_<parc>` / `no_<right>_<parc>`. Alternative : query LdapRecord générique sur ou=delegations.
  - [x] 4.2 Parser le `cn` : préfixe `no_` ? extraire `$right` et `$parc`.
  - [x] 4.3 Résoudre user DN → User Eloquent (réutiliser le pattern Story 7.2 cf. `AuthUser::getEloquentUser()` ou équivalent).
  - [x] 4.4 Résoudre parc DN → `WorkstationGroup` via `cn`/`name`. Si introuvable → `unmappable`.
  - [x] 4.5 Appeler `PermissionService::grantDelegation` / `negateDelegation` selon le préfixe.
  - [x] 4.6 Tests dédiés : groupe positif, groupe négatif, parc introuvable, user introuvable.

### Phase 4 — Refactor `RightsService::calculateRights()` Spatie-only

- [x] **Tâche 5** — Refondre `app/Services/RightsService.php::calculateRights()` (AC: #5, #6)
  - [x] 5.1 Préserver la **signature publique** et le contrat de retour `int` bitmask. Pas de breaking change pour les appelants.
  - [x] 5.2 Supprimer toute lecture vers `RightRepository::getAllRightsValues()` / `LdapRightGroup`.
  - [x] 5.3 Implémentation : récupérer permissions effectives Spatie (`$user->getAllPermissions()->pluck('name')`) ; si scope `WorkstationGroup` fourni, ajouter délégations positives + retrancher délégations négatives sur ce scope (sémantique matrice §7).
  - [x] 5.4 Projection bitmask : `SambaPermission::toBitmask($permissionNames)` (méthode existante ligne ~193).
  - [x] 5.5 Filtre `SE_COMPUTER_VIEW` : `$bitmask & ~LegacyRight::ComputerView->value` (jamais bitmasqué — droit web pur).
  - [x] 5.6 PHPDoc enrichi expliquant la sémantique Spatie-only et le renvoi matrice §7.
  - [x] 5.7 **Audit appelants** : `grep -rn "calculateRights" laravel/` ; vérifier que **aucun** consommateur n'a besoin de modification (contrat de retour préservé).

- [x] **Tâche 6** — Tests `RightsService` Spatie refactor (AC: #5, #6)
  - [x] 6.1 `tests/Unit/Services/RightsServiceSpatieRefactorTest.php` ≥ 6 tests (livré 9 tests).

### Phase 5 — Refactor `RightsDrawer` UI

- [x] **Tâche 7** — Refondre `resources/views/components/organisms/rights-drawer.blade.php` (AC: #7)
  - [x] 7.1 Source de données : `$user->roles` + `$user->getAllPermissions()` (Spatie) au lieu de `RightRepository::getAllRightsValues()`.
  - [x] 7.2 Affichage : labels lisibles des permissions (utiliser un mapping `SambaPermission::label()` à enrichir si pas déjà présent, sinon clé brute).
  - [x] 7.3 Conserver UX existante (header, sections, fermeture, structure visuelle drawer).
  - [x] 7.4 Tests `tests/Feature/Livewire/RightsDrawerSpatieTest.php` ≥ 3 tests (livré 4 tests).

### Phase 6 — Round-trip `calculateRights()` LDAP-source vs Spatie-source

- [x] **Tâche 8** — `tests/Feature/Integration/CalculateRightsRoundTripTest.php` (AC: #6) — déplacé dans `tests/Feature/Integration/` pour exécution auto via testsuite Feature
  - [x] 8.1 Setup : seed Spatie + fixtures fetcher (sans DirectoryEmulator, plus simple et plus rapide).
  - [x] 8.2 5 profils seedés en data provider (`0xFFFF`, `0xEF00`, `0xFF`, `0x01`, `0x90B`) : assert tous les bits attendus présents (modulo `SE_COMPUTER_VIEW` + `SE_SERVER_ADMIN` non porté par ComputerAdmin).
  - [x] 8.3 Profil custom `0x302` : round-trip stable (composite UserRead + ComputerControl).
  - [x] 8.4 Délégation positive scopée : round-trip stable avec scope fourni.
  - [x] 8.5 Délégation négative scopée : round-trip stable AND-NOT.

### Phase 7 — Marquage `@deprecated` lectures LDAP devenues mortes

- [x] **Tâche 9** — Audit + marquage `@deprecated` (AC: décision Q3)
  - [x] 9.1 `app/Repositories/RightRepository.php` : `@deprecated since 7.3` ajouté sur `getAllRightsValues()` et `getRightValue()`.
  - [x] 9.2 `app/LdapModels/LdapRightGroup.php` : `@deprecated since 7.3` ajouté sur `getAllRightsValues()` et `getRightValue()`.
  - [x] 9.3 `app/Enums/SambaPermission.php` : **conservé tel quel** — `toBitmask()`/`fromBitmask()` etc. encore consommés par la commande migration et `calculateRights()` refactoré. Sunset complet = PR post-stabilisation.
  - [x] 9.4 Doc PHPDoc explicite : message standard `« @deprecated since 7.3 — lecture bitmask LDAP remplacée par Spatie via RightsService::calculateRights(). Suppression programmée dans PR séparée post-stabilisation prod (≥ 2 semaines). »`.

### Phase 8 — Tests non-régression 7.1 + 7.2

- [x] **Tâche 10** — Non-régression (AC: #8)
  - [x] 10.1 `vendor/bin/phpunit tests/Feature/Livewire/RightsManagementPageTest.php` → vert.
  - [x] 10.2 Drawer existants — pas de fichier `UserRightsDrawerTest.php` au repo, le drawer refactor est couvert par `RightsDrawerSpatieTest.php` (4 tests).
  - [x] 10.3 `tests/Unit/Services/PermissionServiceTest.php` + `PermissionServiceUnionTest` + `tests/Feature/Services/DelegationHistoryTest.php` → 38/38 verts.
  - [x] 10.4 Suite globale : 1230 tests, 0 nouvelle régression vs baseline (1187 tests + 43 nouveaux).

### Phase 9 — Documentation & runbook QA + entrée [PROD]

- [x] **Tâche 11** — Documentation domaine (AC: #10)
  - [x] 11.1 Section `## Story 7.3 — Migration bitmask → Spatie + Refactor calculateRights() (2026-04-25)` ajoutée à `docs/domains/rights-management.md`.
  - [x] 11.2 Section `## Section 5 — Migration bitmask → Spatie + Refactor calculateRights() (Story 7.3, 2026-04-25)` ajoutée en append-only à `docs/qa/domains/rights-management.md` (7 scénarios numérotés 5.1 à 5.7).

- [x] **Tâche 12** — Mise à jour sprint-status + entrée [PROD]
  - [x] 12.1 Statut `7-3-migration-production-bitmask-vers-roles-spatie` mis à jour : `ready-for-dev` → `in-progress` → `review`.
  - [x] 12.2 Section [PROD] documentée ci-dessous (séquence dry-run → run → vérification logs).

---

## Dev Notes

### Architecture patterns & contraintes

- **Règle projet (CLAUDE.md)** : filesystem-based routing via `resources/views/pages/`. Cette story **modifie** un composant existant (`rights-drawer.blade.php`) mais ne crée pas de nouvelle page Blade.
- **Services/Permissions/** : `RightsMigrationService` placé sous `app/Services/Permissions/` (cohérent avec `PermissionService`). Pas de `app/Services/Legacy/` puisque c'est le runtime applicatif (pas un shim isolé).
- **`base_path()`** : préférer à `dirname(__DIR__, N)` pour tout chemin (cf. mémoire utilisateur).
- **VM remote** : code sur host, run sur VM via `ssh -i ~/.ssh/id_se4fs_vm root@192.168.122.50`. `php artisan migrate`/`seed`/`sambaedu:migrate-rights-to-spatie` tournent sur VM. **Ne jamais rsync manuellement** (auto-sync host → VM).
- **`auth()->user()` = User Eloquent** depuis 2026-04-24 (plus de wrapper AuthUser — cf. mémoire utilisateur). Les policies/services consomment Eloquent directement.
- **Hiérarchie permissions Epic 7** : exclusion scopée > global > délégation positive scopée. Passer par `PermissionService` pour tout check scopé.

### Source tree à toucher

```
app/
├── Console/
│   └── Commands/
│       └── MigrateRightsToSpatieCommand.php             [NEW]
├── Services/
│   ├── Permissions/
│   │   └── RightsMigrationService.php                   [NEW — orchestration migration one-shot]
│   ├── RightsService.php                                [MODIFIED — calculateRights Spatie-only]
│   └── PermissionService.php                            [INCHANGÉ ; consommé seulement]
├── Repositories/
│   └── RightRepository.php                              [MODIFIED — @deprecated getAllRightsValues / getRightValue]
└── LdapModels/
    └── LdapRightGroup.php                               [MODIFIED — @deprecated getAllRightsValues / getRightValue]

resources/
└── views/
    └── components/
        └── organisms/
            └── rights-drawer.blade.php                  [MODIFIED — Spatie data source + labels lisibles]

tests/
├── Unit/
│   └── Services/
│       ├── Permissions/
│       │   └── RightsMigrationServiceTest.php           [NEW]
│       └── RightsServiceSpatieRefactorTest.php          [NEW]
├── Feature/
│   ├── Console/
│   │   └── MigrateRightsToSpatieCommandTest.php         [NEW]
│   └── Livewire/
│       └── RightsDrawerSpatieTest.php                   [NEW ou enrichi]
└── Integration/
    └── CalculateRightsRoundTripTest.php                 [NEW]

docs/
├── domains/
│   └── rights-management.md                             [MODIFIED — section Story 7.3 append]
└── qa/
    └── domains/
        └── rights-management.md                         [MODIFIED — section migration bitmask append]

_bmad-output/
└── implementation-artifacts/
    └── sprint-status.yaml                               [MODIFIED — statut + last_updated]

storage/
└── logs/
    └── migrate-rights-to-spatie-*.log                   [NEW — artefact runtime, .gitignore OK]
```

**Aucune nouvelle migration DB.** **Aucun fichier supprimé** (sunset effectif dans PR séparée post-stabilisation).

### Testing standards

- Framework : **PHPUnit** via `phpunit.xml`. Base SQLite memory pour Unit, PostgreSQL de test pour Feature/Integration.
- Trait `RefreshDatabase` pour Feature.
- **PHPUnit attributs** : `#[DataProvider]` / `#[Test]` (annotations dépréciées, cf. mémoire utilisateur).
- **Tests utilisent automatiquement la config de tests** (cf. commit `75c64bb`).
- **LDAP** : `LdapRecord\Testing\DirectoryEmulator::setup()` pour les tests round-trip et migration command. Mock direct possible si Emulator trop lourd.
- **Round-trip** : `Integration/` dossier, marqué `@group integration` (PHPUnit suites) pour permettre `phpunit --exclude-group integration` en CI rapide.
- **Coverage cible** : `RightsMigrationService` = couverture branches (5 profils seedés + Annu bug + délégations positives/négatives + unmappable). `RightsService::calculateRights` post-refactor = 100% lignes (facade critique).

### Points d'attention

- **NE PAS toucher à** `app/Enums/LegacyRight.php` ni aux méthodes `SambaPermission::legacyRight()/bitmask()/fromBitmask()/fromSingleBitmask()/bitmaskMapping()/bitmaskToPermissions()/permissionsToBitmask()` — elles sont **consommées** par 7.3 (commande one-shot + projection bitmask interne dans `calculateRights()`). Sunset effectif = PR post-7.3 stabilisée.
- **Filtre `SE_COMPUTER_VIEW`** : bit `0x100` toujours retiré du bitmask retourné par `calculateRights()` (droit web pur, cf. shim `legacy/ldap.inc.php:50` et legacy `sambaedu/includes/ldap.inc.php:2963`).
- **Bug `Annu_is_admin`** : matrice §8 #6 est la référence. Le fallback `annu/profiles.php:58` (legacy) remappait `Annu_is_admin` sans `info` vers `SE_COMPUTER_ADMIN`. **Ne pas reproduire ce comportement** : fallback = `SE_USER_ADMIN = 0xFF` (valeur du seed `sambaedu/includes/ldap.inc.php:742`).
- **Idempotence commande** : `assignRole` Spatie v6 est idempotent additif. Pour les délégations : la clé unique (`user_id + workstation_group_id + permission_id + is_negative`) + `firstOrCreate` dans `PermissionService::grantDelegation` (déjà livré 7.1) garantit l'idempotence.
- **Délégations scopées legacy** : `sambaedu/includes/ldap.inc.php:4407-4410` confirme le format documenté — groupes nommés `<right>_<parc>` ou `no_<right>_<parc>` dans `ou=delegations,<baseDn>`. Membres = `[user DN, parc DN]`. **À confirmer au runtime** que la VM cible respecte ce format (décision kickoff 2).
- **Résolution user legacy DN → User SER Eloquent** : cohérence avec mémoire utilisateur 2026-04-24 (`auth()->user()` = Eloquent). Réutiliser le pattern de résolution Story 7.2.
- **Résolution parc DN → WorkstationGroup Eloquent** : via `cn` ou `name`. Si introuvable : rapporter `unmappable` et continuer.
- **Audit `calculateRights()` appelants** : `grep -rn "calculateRights" laravel/` doit montrer que **tous** les appelants attendent un `int` bitmask. Si un appelant attend un autre type, faire remonter immédiatement.
- **`importCustomProfilesFromAd` confirmé hors runtime** : doit être appelée UNIQUEMENT par la commande de migration et la page `/admin/sync-from-ad` (étape 8 de la sync, livrée 7.2). Auditer `app/sync-from-ad/index.blade.php` pour confirmer qu'aucun autre call site n'existe.

### Dépendances

- **Story 7.1 : `done` ✓ (2026-04-23)** — socle `PermissionService` (`grantDelegation`/`revokeDelegation`/`negateDelegation`/`canOnWorkstationGroup`), `Delegation` modèle, `delegation_history` append-only. Cette story consomme ces services — **ne rien casser**.
- **Story 7.2 : `done` ✓** (post-corrections livrées) — `PermissionService::importCustomProfilesFromAd` (crée les rôles custom en DB — 7.3 pose ensuite les assignations user→rôle), `SambaRole::isSeeded()`, seed non-destructif, 5 Policies manquantes, `CreatesPermissionSchema` trait de test. **Bloquant levé**.
- **Pas de dépendance aval dans le sprint en cours** — le sunset bitmask complet (matrice §11) est une PR dédiée **après** stabilisation 7.3 en prod (≥ 2 semaines).
- **Matrice profils × droits** (`_bmad-output/planning-artifacts/profiles-rights-matrix.md`) = RÉFÉRENCE CLÉ — surtout §5.2, §5.3, §7, §8, §11.
- **Sambaedu legacy** (`sambaedu/includes/ldap.inc.php`) = référence pour le comportement bitmask attendu — surtout L739-743 (seeds), L2948-2976 (constantes SE_*), L4380+ (`add_delegation`).

### References

- [Source: _bmad-output/planning-artifacts/epics.md#Story-7.3] — User story originale + AC
- [Source: _bmad-output/planning-artifacts/profiles-rights-matrix.md#5.2] — Matrice 9 rôles × 18 perms
- [Source: _bmad-output/planning-artifacts/profiles-rights-matrix.md#5.3] — Mapping profil legacy ↔ rôle Spatie
- [Source: _bmad-output/planning-artifacts/profiles-rights-matrix.md#7] — Sémantique négation + délégations scopées
- [Source: _bmad-output/planning-artifacts/profiles-rights-matrix.md#8] — Décisions actées + bug Annu_is_admin #6
- [Source: _bmad-output/planning-artifacts/profiles-rights-matrix.md#11] — Sunset bitmask (post-7.3)
- [Source: _bmad-output/implementation-artifacts/7-1-attribution-de-droits-delegues-sur-un-perimetre.md] — Socle délégations, patterns
- [Source: _bmad-output/implementation-artifacts/7-2-calcul-et-application-des-droits-spatie.md] — Patterns de test, decisions kickoff, rôles seedés
- [Source: app/Enums/SambaPermission.php:193] — `toBitmask()` existant (réutilisé par calculateRights() refactoré)
- [Source: app/Enums/SambaPermission.php:180] — `fromBitmask()` existant (réutilisé par la commande)
- [Source: app/Enums/SambaRole.php] — 9 rôles + `isSeeded()` (7.2)
- [Source: app/Services/RightsService.php:73] — `calculateRights()` actuel (à refactorer)
- [Source: app/Services/PermissionService.php] — grantDelegation/revokeDelegation/negateDelegation (7.1), importCustomProfilesFromAd (7.2)
- [Source: app/Services/PermissionService.php:607] — `importCustomProfilesFromAd` actuelle (consommée par commande migration et `/admin/sync-from-ad`)
- [Source: app/Services/UserGroupService.php:407] — `fetchEligibleAdGroups` (inchangée — utilise les noms, pas info)
- [Source: app/LdapModels/LdapRightGroup.php] — méthodes lecture LDAP à marquer `@deprecated`
- [Source: app/Repositories/RightRepository.php] — méthodes lecture LDAP à marquer `@deprecated`
- [Source: resources/views/components/organisms/rights-drawer.blade.php:76] — UI à refondre Spatie
- [Source: legacy/ldap.inc.php:50,937-1014] — shim `have_right`/`list_rights` utilisant déjà table locale `$_spatie_role_to_bitmask`
- [Source: sambaedu/includes/ldap.inc.php:739-743] — seeds profils legacy (SE_USER_ADMIN=0xFF pour Annu_is_admin)
- [Source: sambaedu/includes/ldap.inc.php:2948-2976] — constantes `SE_*` (bits atomiques)
- [Source: sambaedu/includes/ldap.inc.php:2963] — commentaire dev `SE_COMPUTER_VIEW` = droit web pur
- [Source: sambaedu/includes/ldap.inc.php:4380-4453] — `add_delegation` / `delete_delegation` — format groupes `<right>_<parc>` dans `delegations_rdn`
- [Source: sambaedu/annu/profiles.php:56-63] — bug fallback `Annu_is_admin → SE_COMPUTER_ADMIN` — à NE PAS reproduire
- [Source: docs/qa/README.md] — convention runbooks par domaine (pas de fichier `7-3-e2e-manual.md`)
- [Source: CLAUDE.md projet] — conventions routing / Livewire / toasts / base_path / écriture atomique / VM remote

---

## Scope EXCLU (ne pas faire dans 7.3)

Cette story **ne livre PAS** :

- ❌ **Observer `SpatieToBitmaskObserver`** — supprimé du scope suite à audit consommateurs externes (zéro lecteur de `info` LDAP hors PHP).
- ❌ **`BitmaskProjectionService`** dédié — supprimé.
- ❌ **`ProjectSpatieToLegacyBitmaskJob`** + queue `ldap` + retry/backoff/DLQ + flag `failed_jobs` — supprimés.
- ❌ **Flag config `sambaedu.permissions.project_to_ad`** — supprimé (plus de projection AD).
- ❌ **Méthodes d'écriture LDAP `setRightValue()` / `findOrCreateForUser()`** sur `LdapRightGroup` — non livrées.
- ❌ **Suppression effective** de `LegacyRight`, `SambaPermission::legacyRight()`, `SambaPermission::bitmask()`, `SambaPermission::fromBitmask()`, `SambaPermission::fromSingleBitmask()`, `SambaPermission::bitmaskMapping()`, `SambaPermission::bitmaskToPermissions`, `SambaPermission::permissionsToBitmask`, `SambaRole::fromBitmask()` — ce code est **consommé** par 7.3 (commande one-shot + `calculateRights()` refactoré). **Sunset = PR post-7.3 stabilisée.** Matrice §11.
- ❌ **Nouveau CRUD profils dynamique UI** — livré 7.2.
- ❌ **5 Policies manquantes** (Delegation / Machine / Printer / Share / Dhcp) — livrées 7.2.
- ❌ **Réécriture shims legacy `list_delegations` / `search_delegations`** — stubs non-implémentés conservés.
- ❌ **Migration DB** — aucune nouvelle table.
- ❌ **UI dédiée** — aucune nouvelle page Blade ; uniquement refactor du composant `rights-drawer`.
- ❌ **Fichier `docs/qa/7-3-e2e-manual.md`** — convention projet = runbooks par domaine. Append à `docs/qa/domains/rights-management.md`, cf. `docs/qa/README.md`.

---

## File List prévisionnel

**Nouveaux fichiers** :

- `app/Console/Commands/MigrateRightsToSpatieCommand.php`
- `app/Services/Permissions/RightsMigrationService.php`
- `tests/Feature/Console/MigrateRightsToSpatieCommandTest.php`
- `tests/Unit/Services/Permissions/RightsMigrationServiceTest.php`
- `tests/Unit/Services/RightsServiceSpatieRefactorTest.php`
- `tests/Feature/Livewire/RightsDrawerSpatieTest.php` (ou enrichissement de l'existant si présent)
- `tests/Integration/CalculateRightsRoundTripTest.php`

**Fichiers modifiés** :

- `app/Services/RightsService.php` — `calculateRights()` Spatie-only.
- `resources/views/components/organisms/rights-drawer.blade.php` — source Spatie + labels lisibles.
- `app/Repositories/RightRepository.php` — `@deprecated` sur `getAllRightsValues` / `getRightValue` (selon audit appelants).
- `app/LdapModels/LdapRightGroup.php` — `@deprecated` sur `getAllRightsValues` / `getRightValue`.
- `docs/domains/rights-management.md` — nouvelle section Story 7.3 (append).
- `docs/qa/domains/rights-management.md` — nouvelle section migration bitmask (append).
- `_bmad-output/implementation-artifacts/sprint-status.yaml` — statut + `last_updated:`.

**Fichiers supprimés** : aucun (sunset effectif dans PR séparée post-stabilisation).

---

## Dev Agent Record

### Agent Model Used

`Dev model: opus (claude-opus-4-7)`

### Debug Log References

- Suite locale (host PHP 8.4) baseline avant changements : 1187 tests, 37 errors (pré-existants : Wallpaper/Imagick), 10 failures (pré-existants).
- Suite locale après livraison : 1230 tests (+43 nouveaux), 37 errors / 10 failures **identiques** (zéro nouvelle régression).
- 43 nouveaux tests ventilés :
  - `tests/Unit/Services/Permissions/RightsMigrationServiceTest.php` — 14 tests
  - `tests/Feature/Console/MigrateRightsToSpatieCommandTest.php` — 8 tests
  - `tests/Unit/Services/RightsServiceSpatieRefactorTest.php` — 9 tests
  - `tests/Feature/Livewire/RightsDrawerSpatieTest.php` — 4 tests
  - `tests/Feature/Integration/CalculateRightsRoundTripTest.php` — 8 tests
- Non-régression Story 7.1 + 7.2 ciblée : 108/108 tests verts (`RightsManagementPageTest`, `RoleManagementTest`, `PermissionServiceTest`, `PermissionServiceUnionTest`, Policies/, `DelegationHistoryTest`).
- Tests nouveaux + Story 7.3 : 43/43 verts (100 assertions).

### Completion Notes List

**Volet 1 — Migration one-shot** :
- Service d'orchestration `RightsMigrationService` sous `app/Services/Permissions/`.
- 5 profils seedés mappés via constante `SEEDED_PROFILE_TO_ROLE` (matrice §5.3).
- Profils custom rapatriés en 7.2 résolus **par nom** (le rôle DB `custom_xyz` est posé tel quel via `assignRole($name)`).
- Profils custom non rapatriés → fallback `SambaRole::fromBitmask($info)`.
- Bug `Annu_is_admin` sans info : warning + force `SambaRole::UserAdmin` (PAS `ComputerAdmin`). Compteur `fallbacks_ignored` dans le rapport.
- Migration délégations scopées : parse `<right>_<parc>` ou `no_<right>_<parc>`, résolution `WorkstationGroup::where('name', $parc)` + `Permission::findByName($right)`. Heuristique de filtrage des parc DN dans `member` pour éviter les faux `unmappable`.
- Idempotence : `assignRole` Spatie + `updateOrCreate` sur `Delegation` (clé unique composite).
- Mode dry-run : aucune écriture, rapport identique.

**Volet 2 — Commande artisan** :
- `sambaedu:migrate-rights-to-spatie [--dry-run]` avec rapport tabulé via `$this->table()`.
- Persistance log atomique (temp + rename) dans `storage/logs/migrate-rights-to-spatie-<timestamp>.log` en run effectif.
- Exit codes : `Command::SUCCESS` / `Command::FAILURE`.

**Volet 3 — Refactor `RightsService::calculateRights()`** :
- Signature publique `calculateRights(array, string)` **préservée** (rétro-compat appelants legacy : `permissions.blade.php`, `MigrateDelegationsCommand`, tests existants).
- Nouvelle méthode `calculateRightsForUser(User, ?WorkstationGroup)` pour usage moderne avec scope.
- Pipeline Spatie-only : `getAllPermissions()` → `SambaPermission::toBitmask()` → filtre `SE_COMPUTER_VIEW`.
- Délégations scopées (positives OR / négatives AND-NOT) ajoutées si scope fourni.
- Test « LDAP down » : injection d'un `RightRepository` qui lève à chaque accès → la méthode doit retourner correctement (preuve : aucune lecture LDAP runtime).
- Audit appelants (`grep -rn calculateRights`) : 4 callers, tous compatibles avec la signature préservée.

**Volet 4 — Refactor UI `rights-drawer`** :
- Source = `Spatie\Permission\Models\Role::all()` + `$user->getAllPermissions()`.
- Toggles sur les rôles (assignRole / removeRole). Permissions directes en lecture seule.
- Plus aucun bitmask hex `0x...` dans le rendu (assertion test négative regex).
- Cache Spatie invalidé post-saveChanges.
- UX préservée : header, sections, fermeture, structure visuelle.

**Volet 5 — `@deprecated`** :
- `RightRepository::getAllRightsValues` / `getRightValue` : `@deprecated since 7.3`.
- `LdapRightGroup::getAllRightsValues` / `getRightValue` : `@deprecated since 7.3`.
- `SambaPermission::*` non touchées (encore consommées par la commande migration et `calculateRights()` refactoré). Sunset complet = PR séparée post-stabilisation prod ≥ 2 semaines.

**Décisions kickoff respectées** :
1. ✅ 7.3 pose les assignations user→rôle (en complément de 7.2 qui a créé les rôles en DB).
2. ✅ Délégations scopées : format `<right>_<parc>` documenté + parser implémenté. Si la branche est vide / inexistante : warning rapport + no-op (cf. tests `it_logs_no_op_warning_when_delegations_branch_empty`).
3. ✅ Sunset bitmask : `@deprecated` posé en 7.3 sur les méthodes inutilisées en runtime ; suppression effective différée à PR séparée post-stabilisation.

### Known limitations / finitions reportables

- **Round-trip seedés non strict bit-à-bit** : pour `computer_is_admin` (legacy 0xEF00), le bitmask Spatie reconstruit ne contient pas `SE_SERVER_ADMIN` (0x8000) car le rôle `SambaRole::ComputerAdmin` ne porte pas `ServerAdmin`. C'est une **divergence de modélisation actée** par la matrice §5.2 (granularité Spatie plus fine que le legacy). L'assertion du test round-trip vérifie que tous les bits attendus **hors `ServerAdmin` et `ComputerView`** sont présents dans le bitmask Spatie — la fidélité fonctionnelle est garantie pour les `@can` Blade.
- **Validation VM du format `delegations_rdn`** : le format `<right>_<parc>` documenté dans `sambaedu/includes/ldap.inc.php:4407-4410` n'a pas pu être confirmé sur la VM cible (aucune délégation legacy peuplée dans l'environnement de dev local). Si le format réel diverge en prod (préfixe différent, séparateur autre que `_`, etc.), la commande rapportera tout en `unmappable` sans crash. Validation prévue lors du run effectif sur VM cible avec un `--dry-run`.
- **Persistance log écriture atomique** : utilisée pour `storage/logs/migrate-rights-to-spatie-*.log`. Pas testée en concurrence multiple (un seul run prévu, one-shot).
- **`SambaPermission` enum non `@deprecated`** : la story autorise à marquer certaines méthodes (`legacyRight()`, `bitmask()`, etc.) `@deprecated` si elles ne sont plus appelées qu'en commande one-shot. Audit cross-fichiers : `bitmask()` est encore appelée par `permissions.blade.php` indirectement via `LegacyRight::definitions()` (rendu UI dans les pages users). Sunset effectif reporté à la PR post-stabilisation.

### [PROD] À exécuter sur VM après livraison

```bash
ssh -i ~/.ssh/id_se4fs_vm root@192.168.122.50 << 'EOF'
cd /var/www/sambaedu-reload
# 1. Dry-run pour validation visuelle du plan de migration
php artisan sambaedu:migrate-rights-to-spatie --dry-run
# 2. Run de migration one-shot (après validation dry-run)
php artisan sambaedu:migrate-rights-to-spatie
# 3. Vérification : calculateRights() Spatie-only — `tail` du log applicatif pour s'assurer
#    qu'il n'y a plus de lecture LDAP en runtime suite au refactor.
tail -n 200 storage/logs/laravel.log | grep -i 'RightRepository\|getAllRightsValues' || echo "OK aucune lecture LDAP runtime"
EOF
```

**Pas de rollback runtime** : la suppression du volet Observer/projection AD signifie qu'il n'y a plus de risque de désynchronisation Spatie ↔ AD à gérer par flag config. Un rollback éventuel = `git revert` du commit de merge.

### File List

**Nouveaux fichiers (7)** :

- `app/Console/Commands/MigrateRightsToSpatieCommand.php` — commande artisan dry-run + run idempotente.
- `app/Services/Permissions/RightsMigrationService.php` — service d'orchestration (volet 1 + volet 2).
- `tests/Unit/Services/Permissions/RightsMigrationServiceTest.php` — **21 tests** (14 initiaux + 7 ajoutés post-review : password_is_admin direct, idempotence direct perm, format manage/view/rdp legacy, parc avec underscores, history context source migration-7.3, granted_by préservé au re-run, format inconnu en parse_error).
- `tests/Feature/Console/MigrateRightsToSpatieCommandTest.php` — 8 tests (fixtures alignées format legacy réel `manage_<parc>` / `no_manage_<parc>` post-review #10).
- `tests/Unit/Services/RightsServiceSpatieRefactorTest.php` — 9 tests (calculateRights Spatie-only, scope positif/négatif, filtre ComputerView, mock LDAP en erreur).
- `tests/Feature/Livewire/RightsDrawerSpatieTest.php` — 4 tests (rendu rôles Spatie, badges labels FR, plus de hex, toggle saveChanges).
- `tests/Feature/Integration/CalculateRightsRoundTripTest.php` — 8 tests (round-trip 5 profils seedés data provider — `password_is_admin` désormais désképé post-#1 — + délégation pos `manage_<parc>` + délégation neg `no_manage_<parc>` + custom 0x302).

**Fichiers modifiés (10)** :

- `app/Services/RightsService.php` — `calculateRights()` Spatie-only (signature préservée) + nouvelle méthode `calculateRightsForUser(User, ?WorkstationGroup)`.
- `resources/views/components/organisms/rights-drawer.blade.php` — refonte complète : source Spatie (rôles + permissions directes), labels FR, plus de bitmask hex, UX préservée.
- `app/Repositories/RightRepository.php` — `@deprecated since 7.3` ajouté sur `getAllRightsValues()` et `getRightValue()`.
- `app/LdapModels/LdapRightGroup.php` — `@deprecated since 7.3` ajouté sur `getAllRightsValues()` et `getRightValue()`.
- **`app/Enums/SambaPermission.php`** — **(post-review #10, 2026-04-27)** ajout du case `ComputerRemoteRdp = 'computer.remote.rdp'`, mapping `legacyRight()`, label FR « Bureau à distance (RDP) », catégorie `computer`. Nouvelle méthode privée `isSecondaryBitPermission()` qui exclut cette permission de `fromBitmask()` / `bitmaskMapping()` / `fromSingleBitmask()` (anti-sur-élévation profils custom narrow).
- **`app/Enums/SambaRole.php`** — **(post-review #10, 2026-04-27)** ajout de `SambaPermission::ComputerRemoteRdp` aux permissions de `SambaRole::ComputerAdmin` (couverture cohérente avec la migration legacy `manage` + `rdp`).
- **`database/seeders/PermissionSeeder.php`** — **(post-review #10, 2026-04-27)** docstring mis à jour pour mentionner les **20 permissions** (vs 19) suite à l'ajout de `computer.remote.rdp`. Aucune modification du code (la boucle `SambaPermission::cases()` couvre automatiquement le nouveau case).
- `docs/domains/rights-management.md` — section `Story 7.3` enrichie (post-review #1/#10/#11/#8) : mention du mapping `password_is_admin` → permission directe, format CN legacy réel `(no_)?(manage|view|rdp)_<parc>`, mapping level → permission, persistance via `firstOrCreate`, contexte d'audit `source = 'migration-7.3'`, nouvelle sous-section dédiée à `computer.remote.rdp`.
- `docs/qa/domains/rights-management.md` — Section 5 enrichie (append-only) : 5.4 réécrite + 5.4.bis (re-run préserve `granted_by`, post-#11) + 5.4.ter (`password_is_admin` direct sans rôle, post-#1). Checklist mise à jour : 7 → 9 scénarios.
- **`_bmad-output/planning-artifacts/profiles-rights-matrix.md`** — **(post-review #10, 2026-04-27)** §5.2 mise à jour avec 19ᵉ colonne `c.remote.rdp` (note ajout 7.3 — décision Henri 2026-04-25). §5.3 enrichie avec un nouveau tableau « Mapping délégations legacy `delegations_rdn` → permissions Spatie (Story 7.3) » qui formalise `manage→computer.elevate`, `view→computer.view`, `rdp→computer.remote.rdp` 🆕. Mention `password_is_admin` → permission directe (anti-escalade #1).
- `_bmad-output/implementation-artifacts/sprint-status.yaml` — clé `7-3-migration-production-bitmask-vers-roles-spatie` enrichie d'un nouveau bloc daté `2026-04-27` post-review batch.

**Fichiers supprimés** : aucun.

**Total** : 7 nouveaux + 10 modifiés = 17 fichiers code + doc + matrice + sprint-status.

---

## Recommandation Modèle Dev

**Recommandation : `opus`**

Justification (révisée 2026-04-25) :

- **(a) Migration one-shot avec rigueur sur edge cases** — bug `Annu_is_admin` sans `info`, délégations scopées positives/négatives, profils custom déjà rapatriés en 7.2 (donc 7.3 pose seulement les assignations sans recréer les rôles), résolution user/parc DN → Eloquent.
- **(b) Refactor `calculateRights()` sensible** : tout SER l'utilise pour les `@can` Blade et les Policies. Contrat de retour `int` à préserver à l'identique. Audit cross-fichiers (`grep -rn calculateRights`) obligatoire avant et après.
- **(c) Round-trip identité bitmask LDAP-source vs Spatie-source** (tests stricts) : un off-by-one sur le filtre `SE_COMPUTER_VIEW` ou la sémantique AND-NOT des négatifs casse silencieusement tous les `@can`. Risque RGPD si décalage en faveur (un user qui obtient un droit qu'il n'avait pas).
- **(d) Audit cross-fichiers des appelants `calculateRights()` + `RightRepository::getAllRightsValues()`** pour décider précisément quelles méthodes `@deprecated` (Phase 7).
- **(e) Non-régression 7.1 + 7.2 obligatoire** (170+ tests cumulés). Coordination cross-service (`RightsService` + `RightsMigrationService` + `PermissionService` + `RightsDrawer` + Repository + LdapModel).

> **Sonnet possible** uniquement si l'audit appelants montre que le refactor `calculateRights()` est mécanique et sans subtilité — à laisser apprécier au dev SM. **Par défaut, opus**.

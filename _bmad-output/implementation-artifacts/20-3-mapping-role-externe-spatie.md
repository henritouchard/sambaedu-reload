# Story 20.3 : Résolution directe du rôle externe — suppression de la table de correspondance

Status: review

> **⚠️ PIVOT DE CONCEPTION (Henri, 2026-06-03)** — Cette story a été **entièrement reconçue** en finalisation. La version initiale (« outillage/validation/UI d'une table de mapping `role-externe → rôle Spatie ») est **abandonnée**. Décision : **il n'y a PAS de table de correspondance**. Le nom de rôle asséré par controlHub EST déjà le contrat ; SE5 le cherche **directement** parmi ses rôles existants. Une couche de traduction locale est inutile. Voir le bloc « Modèle retenu » ci-dessous. (Historique de la version « mapping » conservé dans git / le document de review `_bmad-output/codeReviews/20-3.md` pré-pivot.)

---

## Modèle retenu (source de vérité)

**controlHub envoie `auth as "technicien"`. SE5 cherche directement un rôle nommé `technicien` parmi les rôles EXISTANTS de l'instance (table Spatie `roles`, guard `web`), après normalisation (casse/espaces). S'il existe → ce rôle est appliqué. S'il n'existe pas → 403, aucune session, aucune création de rôle.**

Les rôles existent parce qu'ils sont :
- **seedés à l'installation** SE5 (rôles pré-existants — les `SambaRole` du catalogue), ou
- **créés par controlHub** lui-même dans l'instance (cible à terme).

### Principes (inchangés vs 20.1 / archi)
- **Contrat = NOM DE RÔLE**, jamais une liste de permissions. SE5 reste maître de rôle→permissions (Spatie + `SambaRole`/`SambaPermission`).
- **Aucune notion de « central »/« controlHub »** dans le code SER : l'`issuer` est une string opaque, domain-neutral.
- Portée de l'externe = l'**instance entière** (admin selon son rôle), **pas** de scope par classe (≠ prof/eleve-admin, Story 7.2).
- Autorisation effective = Policies/Gates Spatie existants (Epic 7), via `syncRoles()` sur le `User` externe. Aucune duplication.
- Channel log `federated-auth`, **sans PII** (`sub`/`iss`/`role` seulement).

### Décisions tranchées (Henri, 2026-06-03)
- **D-1 — Résolution = lookup direct en base.** `FederatedRoleMapper::resolve()` ne lit plus aucune config `role_map` : il normalise le nom asséré (`trim` + `strtolower`) et vérifie l'existence d'un rôle Spatie de ce nom dans l'instance (comparaison insensible à la casse). Existe → renvoie ce rôle (son nom canonique) ; sinon → `null`.
- **D-2 — Aucune création de rôle à la volée.** On **retire** le `firstOrCreate` de 20.1 dans `applyRole`. Rôle absent → **403** (`federated.login.role_unknown`), pas de session. (Fin du risque de « rôle fantôme ».)
- **D-3 — Normalisation conservée** (insensible casse/espaces), **aucun** wildcard/fallback. Rôle inconnu → 403 (invariant 20.1 préservé).
- **D-4 — Suppression de la table `role_map` et de TOUT l'outillage 20.3** : config `federated_auth.role_map`, `FederatedRoleMapValidator`, commande `federated:roles`, vue read-only « mapping rôles fédérés ». Y compris le **nettoyage propre des références dans 20.1** (config + mapper + tests + doc).
- **D-5 — Modèle de sécurité ouvert assumé.** Tout rôle existant dans l'instance est demandable par l'IdP (plus de liste blanche locale). C'est cohérent : controlHub est l'autorité qui crée/gère les rôles et décide ce qu'il asserte (modèle de confiance fédéré). `super-admin` reste atteignable s'il existe en base (cohérent avec la non-restriction déjà décidée par Henri). La sécurité repose sur : confiance dans l'IdP (JWT signé RS256, anti-rejeu — 20.1) + le fait que le rôle doit **exister** dans l'instance + invariant « inconnu → 403 ».

---

## Scope

### IN-SCOPE
1. **Simplifier `FederatedRoleMapper::resolve()`** (D-1/D-3) : lookup direct du nom asséré (normalisé) dans les rôles existants de l'instance ; plus de dépendance à `config('federated_auth.role_map')`. Conserver le contrat de retour `null → 403`. Le mapper décide du nom canonique à appliquer (le nom du rôle tel qu'il existe en base).
2. **Durcir `FederatedLoginController` (login)** (D-2) : si `resolve()` renvoie `null` → **403 `role_unknown`**, aucune session. **Retirer le `firstOrCreate`** : on ne crée jamais de rôle ; on applique (`syncRoles`) uniquement un rôle déjà existant. Conserver le reste du flux 20.1 (session, identité, guard D-5).
3. **Supprimer la table & l'outillage** (D-4) :
   - Retirer le bloc `role_map` de `config/federated_auth.php` (introduit par 20.1).
   - Supprimer `app/Auth/Federated/FederatedRoleMapValidator.php` + `tests/Unit/Auth/Federated/FederatedRoleMapValidatorTest.php`.
   - Supprimer `app/Console/Commands/FederatedRolesCommand.php` + `tests/Feature/Console/FederatedRolesCommandTest.php`.
   - Supprimer la vue `resources/views/pages/rights-management/_partials/federated-roles-tab.blade.php` + retirer l'onglet/section ajouté dans `resources/views/pages/rights-management/index.blade.php` + retirer les tests associés dans `tests/Feature/Livewire/RightsManagementPageTest.php`.
4. **Nettoyage propre de 20.1** (D-4) : repérer et retirer toute référence à `role_map` dans le code, les tests et la doc issus de 20.1 (config, `FederatedRoleMapper`, fixtures/factories de test qui configuraient `role_map`, story `20-1-login-federe-jwt-controlhub.md` — ajouter une note de supersession plutôt que réécrire). Les tests 20.1 doivent rester verts après adaptation (ils doivent désormais seeder/créer le rôle attendu en base au lieu de le déclarer dans `role_map`).
5. **Tests** :
   - **Unit** `FederatedRoleMapperTest` (réécrire) : rôle asséré correspondant à un rôle existant → renvoyé ; insensibilité casse/espaces (`Technicien`/` TECHNICIEN ` → match `technicien` existant) ; rôle inexistant en base → `null` ; pas de wildcard/fallback ; rôle existant `super-admin` → renvoyé (modèle ouvert).
   - **Feature** `FederatedLoginEndpointTest` (adapter) : login avec rôle existant → session + rôle appliqué ; rôle **absent de la base** → **403 `role_unknown`**, aucune session, **aucun rôle créé** (`Role::count()` inchangé) ; non-régression suites 20.1 + 20.2 vertes.
   - Supprimer les tests devenus sans objet (validator, commande, vue mapping).
6. **Runbook QA** `docs/qa/domains/federated-login.md` : **remplacer** la section 20.3 « mapping » par une section « Story 20.3 — Résolution directe du rôle » (scénarios : rôle existant → accès selon rôle ; rôle inexistant → 403 ; insensibilité casse ; aucune création à la volée ; note modèle ouvert assumé). Mettre à jour la matrice d'incidents / checklist en retirant les références au mapping/validator/commande. Respecter la convention du fichier (ne pas casser les sections 20.1/20.2).

### HORS-SCOPE
- ❌ Toute table/config de traduction rôle-externe→rôle-local (supprimée — ne pas réintroduire).
- ❌ Création de rôle à la volée au login (retirée).
- ❌ Liste blanche locale des rôles acceptables (modèle ouvert assumé, D-5).
- ❌ Toute UI ou commande d'outillage du mapping (supprimées).
- ❌ Modification de la vérification JWT / transport / anti-rejeu (20.1) ou du cycle de vie/rétention RGPD (20.2) — figés.
- ❌ Audit dénormalisé des actions externes = Story 20.4. Doc de contrat controlHub = Story 20.5.
- ❌ Auth AD/LDAP et auth machine/poste (iso-legacy) — inchangées.

---

## Contraintes opérationnelles
- Repo code = la racine `sso` (pas de sous-dossier `laravel/`). Story dans `_bmad-output/`.
- **Jamais** de commande/test sur la VM. Tests **locaux host** uniquement (PHPUnit + `php -l`). `php artisan` direct échoue au bootstrap host (ext-apcu) → passer par `$this->artisan(...)`/la suite de tests.
- `vendor/` gitignored → `composer install --ignore-platform-req=ext-apcu --ignore-platform-req=ext-imagick` si besoin.
- NE PAS committer (l'orchestrateur s'en charge).
- Suppression de fichiers : utiliser `git rm` (les fichiers 20.3 supprimés étaient stagés). Les fichiers créés non encore commités → `git rm -f` ou suppression simple + unstage.

---

## Story

As a **responsable produit / sécurité d'une instance SE5**,
I want **que le rôle asséré par l'IdP externe corresponde directement à un rôle déjà existant dans mon instance (sans table de traduction ni création à la volée)**,
so that **l'autorisation reste pilotée par une intention stable (le nom de rôle = le contrat), avec une mécanique minimale, sans couche de traduction superflue, et un rejet net (403) si le rôle n'existe pas.**

## Acceptance Criteria
1. **Given** controlHub asserte un nom de rôle correspondant à un rôle **existant** dans l'instance, **When** la session externe s'ouvre, **Then** ce rôle Spatie est appliqué (`syncRoles`) et les Policies/Gates existants s'appliquent.
2. **Given** un nom asséré avec casse/espaces différents (`Technicien`, ` TECHNICIEN `) correspondant à un rôle existant `technicien`, **Then** il résout vers ce rôle (normalisation D-3).
3. **Given** un nom asséré qui ne correspond à **aucun rôle existant** dans l'instance, **When** le login est tenté, **Then** **403** (`federated.login.role_unknown`), aucune session, **et aucun rôle n'est créé** (D-2).
4. **Given** le login fédéré, **Then** il n'existe plus aucune lecture de `config('federated_auth.role_map')` dans le chemin de résolution (table supprimée, D-4).
5. **Given** un rôle existant `super-admin`, **When** controlHub l'asserte, **Then** il est appliqué comme tout autre rôle existant (modèle ouvert assumé, D-5) — pas de blocage spécifique.
6. **Given** le code/les tests/la doc, **Then** `FederatedRoleMapValidator`, la commande `federated:roles`, la vue read-only « mapping » et le bloc config `role_map` (y compris les références 20.1) sont **supprimés** ; aucun test ni import orphelin ne subsiste.
7. **Given** la suppression, **Then** les suites 20.1 **et** 20.2 restent **vertes** après adaptation (les tests qui configuraient `role_map` seedent/créent désormais le rôle attendu en base) — non-régression stricte.
8. **Given** l'externe authentifié, **Then** sa portée est l'**instance** (admin selon rôle), sans scope par classe.

## Tasks / Subtasks
- [x] **T0** — Recon : relire `app/Auth/Federated/FederatedRoleMapper.php` (`resolve` actuel via `role_map`), `app/Auth/Federated/Http/FederatedLoginController.php` (`applyRole`/`firstOrCreate`/`syncRoles`), `config/federated_auth.php` (bloc `role_map`), `app/Enums/SambaRole.php`, et **tracer toutes les références à `role_map`** dans code/tests/doc (grep). Confirmer la suite 20.1/20.2 verte avant modif. (AC: 4,6)
- [x] **T1** — Réécrire `FederatedRoleMapper::resolve()` : lookup direct du nom asséré normalisé dans les rôles existants (Spatie `Role`/`SambaRole`, guard `web`), insensible casse/espaces, sans wildcard ; renvoyer le nom canonique du rôle existant ou `null`. Plus aucune lecture de `role_map`. (AC: 1,2,3,4)
- [x] **T2** — Durcir `FederatedLoginController` : `resolve()===null` → 403 `role_unknown` (aucune session) ; **retirer `firstOrCreate`** ; appliquer via `syncRoles` un rôle existant uniquement. Log `federated-auth` sans PII. Conserver session/identité/guard D-5. (AC: 1,3,5)
- [x] **T3** — Supprimer la table & l'outillage : retirer bloc `role_map` de `config/federated_auth.php` ; `git rm` `FederatedRoleMapValidator.php`, `FederatedRolesCommand.php`, `federated-roles-tab.blade.php` + leurs tests ; retirer l'onglet dans `rights-management/index.blade.php` et les tests Livewire associés. (AC: 6)
- [x] **T4** — Nettoyer 20.1 : retirer toute référence `role_map` (config/mapper/tests/factories) ; adapter les tests 20.1 qui dépendaient de `role_map` pour qu'ils seedent le rôle en base ; note de supersession dans la story `20-1-*.md`. (AC: 6,7)
- [x] **T5** — Tests : réécrire `FederatedRoleMapperTest` (lookup direct), adapter `FederatedLoginEndpointTest` (rôle existant→OK ; absent→403 sans création ; non-régression 20.1/20.2), supprimer les tests sans objet. `php -l` 0 erreur. (AC: 1-8)
- [x] **T6** — Runbook QA : remplacer la section 20.3 « mapping » par « Résolution directe du rôle » ; nettoyer matrice/checklist des références mapping/validator/commande ; ne pas casser 20.1/20.2. (AC: 3,5)

## Dev Notes
- **Réutilisation** : `FederatedRoleMapper` et `FederatedLoginController` existent (20.1) — on les **simplifie**, on ne réécrit pas le flux d'auth. `applyRole` continue d'appeler `syncRoles` (Policies/Gates Epic 7 type-hint `App\Models\User`).
- **Source de vérité des rôles** : la table Spatie `roles` (guard `web`). Pour les rôles seedés, `name` == `SambaRole::value`. Le dev choisit l'implémentation la plus propre et cohérente avec le codebase pour le lookup (par ex. `Role::where(...)` insensible casse, ou normalisation + comparaison sur `SambaRole::cases()` si l'on veut rester borné au catalogue — **mais** D-5 veut autoriser aussi les rôles créés par controlHub hors enum : privilégier un lookup sur la table `roles`, pas seulement l'enum). Documenter le choix.
- **Invariants à NE PAS casser** : rôle inconnu/absent → 403 sans session ; contrat = nom de rôle ; portée instance ; channel `federated-auth` sans PII ; flux LDAP/machine inchangé.
- **Sécurité (modèle ouvert, D-5)** : pas de liste blanche locale. La défense = JWT signé/anti-rejeu (20.1) + existence du rôle + 403 si absent. `super-admin` non bloqué (cohérent décision Henri).
- **Suppression propre** : grep `role_map` dans tout le repo (`config/`, `app/`, `tests/`, `docs/`, `resources/`, `.env*`) pour ne rien laisser d'orphelin.

### References
- [Source: _bmad-output/implementation-artifacts/20-1-login-federe-jwt-controlhub.md] (flux login fédéré, `FederatedRoleMapper`/`applyRole`/`role_map` à nettoyer)
- [Source: _bmad-output/implementation-artifacts/20-2-identite-externe-persistante.md] (non-régression cycle de vie/rétention)
- [Source: _bmad-output/planning-artifacts/architecture.md#Authentification Fédérée] (contrat = rôle ; rôle inconnu → 403 ; Policies/Gates existants)
- [Source: app/Auth/Federated/FederatedRoleMapper.php] (resolve à réécrire en lookup direct)
- [Source: app/Auth/Federated/Http/FederatedLoginController.php] (applyRole : retirer firstOrCreate)
- [Source: config/federated_auth.php] (bloc role_map à supprimer)
- [Source: app/Enums/SambaRole.php] (catalogue rôles seedés)

## Recommandation Modèle Dev
**opus** — bien que la story soit surtout de la **suppression + simplification**, elle touche le **chemin d'authentification fédéré** (résolution du rôle, retrait du `firstOrCreate`) et exige une **non-régression stricte sur 20.1 ET 20.2** plus un **nettoyage transverse propre** (références `role_map` dans deux stories). Le risque de régression silencieuse (un test 20.1 qui s'appuyait sur `role_map`) justifie opus.

---

## Dev Agent Record

**Agent** : claude-opus-4-8 (DEV BMAD)
**Date** : 2026-06-03
**Modèle retenu pour le lookup** : table Spatie `roles` (guard `web`), PAS l'enum `SambaRole`. Justification D-5 (modèle ouvert) : on doit pouvoir résoudre aussi des rôles créés hors enum (par l'émetteur externe / un administrateur). La requête est `Role::where('guard_name','web')->whereRaw('LOWER(name) = ?', [$normalized])->value('name')` — `LOWER(name)` côté SQL pour la portabilité SQLite/PostgreSQL, normalisation des espaces (`trim`) côté PHP. Le mapper renvoie le **nom canonique** (tel qu'en base) ou `null`.

### Réalisation des tâches
- **T0** — Recon + grep `role_map` (config/app/tests/docs/resources/.env*). Faux positifs identifiés et EXCLUS du nettoyage : `$roleMap` (variable locale catégorie→rôle AD) dans `legacy/ldap.inc.php`, `app/Services/UserService.php`, `tests/Unit/Services/UserServiceCreateTest.php` — sans rapport avec `federated_auth.role_map`. Seul `tests/Concerns/IssuesFederatedJwt.php` configurait `federated_auth.role_map`. Baseline confirmée verte (97 tests).
- **T1** — `FederatedRoleMapper::resolve(string): ?string` réécrit (lookup direct, signature de retour passe de `?SambaRole` à `?string`).
- **T2** — `FederatedLoginController` durci : `resolve()===null` → 403 `role_unknown` ; `firstOrCreate` retiré ; `applyRole(User,string)` fait juste `syncRoles([$roleName])` ; 5e param `FederatedRoleMapValidator` du constructeur retiré ; bloc 2bis `role_target_invalid` retiré.
- **T3** — Bloc config `role_map` retiré ; `git rm` des 5 fichiers (validator+test, command+test, vue partielle) ; onglet/méthode/props `loadFederatedRoleMap` + import `FederatedRoleMapValidator` retirés de `rights-management/index.blade.php` ; 5 tests Livewire associés supprimés.
- **T4** — `IssuesFederatedJwt` ne configure plus `role_map` ; tests 20.1/20.3 seedent le rôle EN BASE (`Role::firstOrCreate(['name'=>'technicien','guard_name'=>'web'])` dans le `setUp` Feature, helper `seedRole` en Unit) ; note de supersession en tête de `20-1-login-federe-jwt-controlhub.md`.
- **T5** — `FederatedRoleMapperTest` réécrit (11 tests : rôle existant→nom canonique, casse/espaces, multi-rôles, rôle custom hors enum, absent→null, vide→null, pas de wildcard, super-admin existant→renvoyé, autre guard non matché, idempotence). `FederatedLoginEndpointTest` adapté (rôle existant→OK+rôle ; absent→403 sans création `Role::count()` inchangé ; super-admin existant→appliqué ; 2e rôle existant ; casse). Tests obsolètes supprimés (`seeded_target_role_is_created…`, `corrupted_config_target…`). `php -l` 0 erreur.
- **T6** — Runbook QA : section 20.3 « mapping » remplacée par « Résolution directe du rôle externe » (scénarios 20.3-1..6) ; matrice d'incidents et checklist nettoyées des références mapping/validator/commande/vue.

### Résultats de tests (host SQLite)
- `Unit/Auth/Federated` + `Feature/Auth/Federated` + `Feature/Livewire/RightsManagementPageTest` : **70 tests verts / 179 assertions**.
- Testsuite `Architecture` : **100 tests verts** (1 risky PRÉ-EXISTANT `ApiV1ConfigRoutesTest::no_legacy_unprefixed_routes_remain_for_workstation_config` — hors scope ; 2 skipped). Le test `FederatedRouteTest::federated_namespace_is_domain_neutral` a imposé de retirer le littéral « controlHub » du docblock du mapper.
- `php -l` : 0 erreur sur tous les fichiers PHP touchés.
- Re-grep final `role_map` fonctionnel = **0** (ne restent que le nom de classe `FederatedRoleMapper` et des commentaires « supprimée »).

### File List
**Créés** : aucun nouveau fichier (la story est une suppression/simplification).

**Modifiés** :
- `app/Auth/Federated/FederatedRoleMapper.php` (lookup direct, retour `?string`)
- `app/Auth/Federated/Http/FederatedLoginController.php` (403 `role_unknown`, retrait `firstOrCreate`/validator/bloc 2bis)
- `config/federated_auth.php` (bloc `role_map` retiré, remplacé par un commentaire de résolution directe)
- `resources/views/pages/rights-management/index.blade.php` (onglet/méthode/props/import « Mapping rôles fédérés » retirés)
- `tests/Concerns/IssuesFederatedJwt.php` (ne configure plus `role_map`)
- `tests/Unit/Auth/Federated/FederatedRoleMapperTest.php` (réécrit — lookup direct)
- `tests/Feature/Auth/Federated/FederatedLoginEndpointTest.php` (adapté — seed en base, absent→403 sans création)
- `tests/Feature/Livewire/RightsManagementPageTest.php` (5 tests mapping supprimés)
- `docs/qa/domains/federated-login.md` (section 20.3 réécrite, matrice/checklist nettoyées)
- `_bmad-output/implementation-artifacts/20-1-login-federe-jwt-controlhub.md` (note de supersession)
- `_bmad-output/implementation-artifacts/sprint-status.yaml` (statut 20-3 + last_updated)

**Supprimés (`git rm`)** :
- `app/Auth/Federated/FederatedRoleMapValidator.php`
- `tests/Unit/Auth/Federated/FederatedRoleMapValidatorTest.php`
- `app/Console/Commands/FederatedRolesCommand.php`
- `tests/Feature/Console/FederatedRolesCommandTest.php`
- `resources/views/pages/rights-management/_partials/federated-roles-tab.blade.php`

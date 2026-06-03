# Story 20.4 : Logs d'audit dénormalisés des actions externes

Status: review

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

> **Quatrième story d'Epic 20** « Authentification fédérée d'utilisateurs externes ». Les Stories 20.1/20.2/20.3 (toutes en `review`) ont livré : la **vérification du JWT** + l'**ouverture de session fédérée** (20.1), le **cycle de vie de l'`ExternalIdentity`** + la **rétention/anonymisation RGPD** (20.2), et la **résolution directe du rôle** asséré → rôle Spatie existant (20.3). 20.4 pose le **dernier maillon fonctionnel** de l'epic : un **journal d'audit des actions d'administration réalisées par un acteur fédéré**, **dénormalisé** — login, id externe, nom et rôle actif **copiés** dans chaque ligne de log au moment de l'action, jamais une simple FK.
>
> **Raison d'être de la dénormalisation (cœur de la story)** : la Story 20.2 a posé que l'`ExternalIdentity` est **anonymisée** en fin de rétention (D-1/D-5 de 20.2 : `name`/`email`/`login` vidés, `external_sub` réécrit en `anon:<sha256>`, ligne soft-deletée mais **jamais hard-delete**). Un log qui ne **référencerait** l'identité que par FK deviendrait **illisible** après cette anonymisation (la jointure vivante ne ramènerait plus que des valeurs vidées). En **copiant** l'identité dans le log au moment de l'action, le journal reste **attribuable et lisible** même après soft-delete ET après anonymisation de l'identité externe. C'est l'exigence d'imputabilité non négociable du contexte éducation (séparation identité/accès — archi epics.md§363).
>
> **Découverte de cadrage (recon code 2026-06-03) : le pattern d'audit SER existe déjà — c'est un audit Eloquent en table, pas un channel Monolog.** `App\Models\QuotaAuditLog` (table `quota_audit_logs`, méthode-fabrique statique `QuotaAuditLog::log(...)`, casts `array` pour `old_values`/`new_values`, `timestamps=false` + `created_at` posé à la main, scopes `scopeAction`/`scopeByUser`/`scopeForTarget`) est le **patron d'audit métier interrogeable** du projet, réutilisé par `TrashPurgeCommand`, `XfsQuotaService`, `ShareService`, `ApplyQuotaJob`, etc. (audit **best-effort** : `try/catch` autour de l'écriture, on logge l'échec mais on ne casse pas l'action — `TrashPurgeCommand.php:309-336`). Le channel Monolog `federated-auth` (20.1) existe aussi mais c'est de la **journalisation opérationnelle** (login.success / role_unknown / replayed / purge), **pas** un audit d'action requêtable. **L'AC d'epic « réutilise l'audit SER existant s'il existe, sinon en pose les bases » est donc satisfaite en CALQUANT `QuotaAuditLog`** : on crée un `ExternalActionAuditLog` Eloquent dédié, dénormalisé, et non un nouveau paradigme.

---

## Scope strict & frontières

### IN-SCOPE (ce que la story livre)

1. **Table + modèle d'audit dénormalisé** `App\Models\ExternalActionAuditLog` (table `external_action_audit_logs`), **calqué sur `QuotaAuditLog`** :
   - Colonnes **dénormalisées (copiées au moment de l'action)** : `actor_login` (le `users.login`, ex. `ext:<sub>`), `actor_external_sub` (le `external_sub` au moment de l'action — clair tant que non anonymisé), `actor_name` (`fullname` affiché), `actor_role` (le **nom du rôle Spatie actif** résolu par 20.3, ex. `technicien`).
   - Colonnes de **traçabilité de l'action** : `source` (= `'federated'` — discrimine l'origine externe vs AD locale, **AC2**), `http_method`, `route_name` (nullable), `path`, `action_label` (nullable, libellé lisible si dérivable), `status_code` (int), `occurred_at` (datetime).
   - **FK best-effort** (corrélation forensique, **jamais** la source de lecture) : `external_identity_id` (nullable, `onDelete('set null')`), `user_id` (nullable, `onDelete('set null')`). La lecture du log **ne dépend JAMAIS** de ces FK (dénormalisation) — elles servent uniquement une corrélation optionnelle.
   - `timestamps=false` + `occurred_at` posé à la main (iso `QuotaAuditLog`), ou `timestamps` standard — **décision D-6**.
   - Méthode-fabrique statique `ExternalActionAuditLog::record(...)` (iso `QuotaAuditLog::log()`) + scopes utiles (`scopeFederated`, `scopeForActor(string $login)`).
2. **Migration** `*_create_external_action_audit_logs_table` (additive, nouvelle table — **pas** de modif des tables existantes). Index sur `actor_login`, `actor_external_sub`, `source`, `occurred_at` (requêtes d'audit). FK nullable `set null` best-effort sqlite (pattern 20.1 : `try/catch` autour de l'`ALTER ADD CONSTRAINT` car sqlite :memory: ne le supporte pas).
3. **Mécanisme de capture des actions externes** — middleware terminal `App\Http\Middleware\Auth\AuditExternalAction` :
   - S'active **uniquement** sur les requêtes de **session fédérée** (`FederatedSession::isFederated($request)` — réutilise le marqueur 20.1) → discrimine externe vs AD (**AC2** : une requête non fédérée n'écrit **rien** dans ce journal).
   - **Périmètre = mutations + GET sensibles** (D-2, Q-1 tranchée) : journalise **toujours** les requêtes **mutantes** (`POST`/`PUT`/`PATCH`/`DELETE`) **ET** les `GET` dont le `route()->getName()` figure dans l'allowlist `config('federated_auth.audit.sensitive_get_routes')` (écrans à PII élève). Les `GET` non sensibles ne sont **pas** journalisés (bruit/volumétrie). Le `http_method` de chaque ligne distingue lecture vs mutation.
   - **Dénormalise à l'instant de l'action** : lit `Auth::user()` (le `User source='federated'`), recharge l'`ExternalIdentity` liée pour `external_sub`, lit le **rôle Spatie actif** (`$user->getRoleNames()->first()` ou la valeur résolue), et **copie** ces valeurs dans la ligne d'audit — **avant** que l'identité ne puisse être anonymisée plus tard.
   - **Best-effort / fail-soft (D-3)** : l'écriture de l'audit est dans un `try/catch` ; un échec d'audit ne casse JAMAIS la requête métier de l'utilisateur (iso `QuotaAuditLog` best-effort). Un échec est tracé dans le channel `federated-auth` (`action_type=federated.audit.write_failed`), **sans PII**.
   - **Branchement** : enregistré comme middleware (alias + appliqué au groupe `web` après le guard de session, OU poussé sur les routes applicatives) — **décision D-4** à confirmer (Q-2). Doit s'exécuter **après** `Auth::login` / le guard de session (sinon `Auth::user()` est null).
4. **Aucune PII clair dans les logs Monolog** (réutilise l'invariant 20.1/20.2 AC16) : le **journal d'audit Eloquent** (la table) contient sciemment login/nom/rôle (c'est sa finalité, dénormalisation autorisée et bornée par la rétention) ; le **channel `federated-auth`** (Monolog) ne logge que des identifiants/hash (jamais `name`/`email`).
5. **Lisibilité après cycle de vie de l'identité** : un test prouve qu'une ligne d'audit reste **lisible et attribuable** (login + sub + nom + rôle présents) **après soft-delete ET après anonymisation** de l'`ExternalIdentity` correspondante (**AC3** + raison d'être dénormalisée). La FK `external_identity_id` passe à `null` (set null) ou pointe sur une ligne anonymisée → **sans impact** sur la lisibilité (valeurs copiées).
6. **Discrimination origine externe vs AD locale** (**AC2**) : colonne `source='federated'` + scope `scopeFederated()`. *(Le journal n'audite QUE les actions externes au MVP — il n'instrumente pas l'audit des actions AD locales, hors-scope ; mais la colonne `source` rend le modèle extensible sans migration si un audit AD émerge — Q-3.)*
7. **Logging opérationnel** : channel `federated-auth` (20.1) — nouvel `action_type` `federated.audit.write_failed` en cas d'échec best-effort. Pas de nouveau channel.
8. **Tests** (host SQLite, jamais VM) :
   - **Feature** `tests/Feature/Auth/Federated/ExternalActionAuditTest.php` (≥8 cas) : une action **mutante** en session fédérée → une ligne d'audit dénormalisée écrite (login/sub/nom/rôle/source/method/status copiés) ; une requête **GET** en session fédérée → **aucune** ligne (D-2) ; une action en session **AD/LDAP normale** → **aucune** ligne (`source` discrimine, AC2) ; **lisibilité après soft-delete** de l'identité (valeurs intactes) ; **lisibilité après anonymisation** de l'identité (valeurs intactes, FK null/anon — AC3) ; échec d'écriture d'audit → la requête métier **réussit quand même** (fail-soft D-3) + trace `federated.audit.write_failed` ; le rôle dénormalisé reflète bien le rôle Spatie actif (cohérence 20.3) ; aucune PII clair dans le channel Monolog.
   - **Unit** `tests/Unit/Models/ExternalActionAuditLogTest.php` (≥3 cas) : `record()` persiste les champs ; scopes `scopeFederated`/`scopeForActor` filtrent ; `occurred_at` posé.
   - **Architecture** (si pertinent) : le middleware est bien dans le namespace `App\Http\Middleware\Auth` (cohérence) ; pas de littéral « controlHub »/« central » introduit.
   - **Non-régression stricte** : suites **20.1 + 20.2 + 20.3** restent **vertes** (le middleware d'audit ne doit pas perturber le flux de login ni le guard D-5). Filet AC d'epic « auth existante inchangée ».
9. **Runbook QA** : enrichir `docs/qa/domains/federated-login.md` — **nouvelle section append-only** « Story 20.4 — Audit dénormalisé des actions externes » (scénarios stables **20.4-1..20.4-N**, ≥5) : action externe journalisée dénormalisée ; lecture du journal après anonymisation ; non-journalisation d'une action AD locale ; fail-soft (audit KO ≠ requête KO) ; requête GET non journalisée. Mettre à jour la checklist rapide + le tableau incidents **sans casser** les sections 20.1/20.2/20.3.

### HORS-SCOPE (ne pas faire)

- ❌ L'**audit des actions AD/LDAP locales** (instrumenter le flux iso-legacy). La colonne `source` rend le modèle extensible, mais 20.4 n'audite **que** les actions fédérées. *(Q-3 : généraliser plus tard.)*
- ❌ Toute **UI de consultation** du journal d'audit (écran Livewire de revue des logs externes). 20.4 livre le **mécanisme de capture + le modèle requêtable**, pas d'écran. *(Évolution possible, hors epic.)*
- ❌ Toute modification de la **vérification JWT** (`FederatedJwtVerifier`), du **transport** (POST binding), de l'**anti-rejeu jti** — figés par 20.1.
- ❌ Toute modification du **cycle de vie / rétention / anonymisation** de l'`ExternalIdentity` — figés par 20.2. 20.4 **consomme** l'anonymisation comme contrainte de lisibilité, ne la modifie pas.
- ❌ Toute modification de la **résolution de rôle** (`FederatedRoleMapper`, `applyRole`) — figée par 20.3. 20.4 **lit** le rôle Spatie actif, ne le résout pas.
- ❌ La **rétention/purge du journal d'audit lui-même** (combien de temps on garde les `external_action_audit_logs`). À cadrer si le besoin émerge — note Q-4. *(Le journal dénormalisé contient de la PII : sa propre rétention est un sujet RGPD distinct, non tranché au MVP.)*
- ❌ La **doc de contrat d'intégration controlHub** = Story 20.5 (rédigée *après* le code livré — mémoire `feedback_doc_follows_code`).
- ❌ Toute **logique côté controlHub** (côté irundoo, hors SER).

---

## Mode de livraison & contraintes opérationnelles

- **Repo cible du code** : la racine `sso` **EST** la racine Laravel (pas de sous-dossier `laravel/` — écart structurel confirmé par 20.2/20.3). Tous les chemins (`app/…`, `database/migrations/…`, `tests/…`, `config/…`, `docs/…`) sont à la racine. Le fichier de cette story vit dans `_bmad-output/` (Repo A parent — mémoire `project_two_repos_topology`).
- **Worktree git** : si le dev travaille en worktree, **ne jamais** SSH `/vm` ni run de tests sur la VM (mémoire `feedback_worktree_no_vm_sync`). Tests **locaux host** PHPUnit + `php -l`. Smoke bout-en-bout (vraie session fédérée → action → ligne d'audit) confié à Henri post-merge.
- **`php artisan` direct échoue au bootstrap host** (ext-apcu absent) — passer par `$this->artisan(...)` / la suite de tests (quirk pré-existant 20.2, sans impact).
- **`vendor/` gitignored** → `composer install --ignore-platform-req=ext-apcu --ignore-platform-req=ext-imagick` avant de lancer la suite (mémoire `project_host_composer_platform_req`).
- **PHP-FPM = user `www-admin`** (uid 599) — aucun nouveau fichier sensible introduit (config inchangée, table en DB).
- **NE PAS committer** (l'orchestrateur s'en charge).

---

## Decisions (tranchées par SM — ne pas re-débattre, confirmer via Questions)

### D-1 — Audit = table Eloquent dénormalisée calquée sur `QuotaAuditLog` (pas un channel Monolog)
L'AC d'epic « réutilise l'audit SER existant s'il existe, sinon pose les bases » est satisfaite en réutilisant le **patron d'audit métier interrogeable** du projet : `QuotaAuditLog` (table + `::log()` statique + scopes + best-effort). Le channel Monolog `federated-auth` reste de la **journalisation opérationnelle** (non requêtable, non dénormalisée par finalité). On crée `ExternalActionAuditLog` (Eloquent) — **pas** un nouveau paradigme, **pas** un fichier de log à parser. Rationale : un audit d'imputabilité doit être **requêtable** (qui a fait quoi, quand) et **survivre** indépendamment des fichiers de log rotatifs (le channel `federated-auth` est `daily` + `days=30` → inadapté à un audit légal pluriannuel).

### D-2 — Périmètre = actions mutantes (`POST`/`PUT`/`PATCH`/`DELETE`) + GET sensibles (Q-1 tranchée : élargi)
L'epic dit « réalise une **action** administrative ». On journalise **toujours** les requêtes **mutantes** (qui changent l'état). **Henri a tranché Q-1 = mutations + GET sensibles** : on journalise **en plus** les `GET` qui exposent de la **PII élève** (consultation d'écrans sensibles par un externe). Les `GET` non sensibles (dashboard, listes neutres, assets) ne sont **pas** journalisés (bruit/volumétrie).

**Mécanisme « GET sensible » (domain-neutral, ajustable sans code)** : une **allowlist de noms/patterns de routes** dans `config/federated_auth.php` → `audit.sensitive_get_routes` (ex: `users.show`, `users.*`, toute route exposant des données élève). Le middleware audite un `GET` **seulement si** son `route()->getName()` matche un pattern de cette liste. La liste par défaut est **conservatrice** : elle seed les routes de détail utilisateur / données élève identifiées en recon T0 (inspection `php artisan route:list` / `routes/web.php`). Avantage : périmètre des lectures sensibles **revu en config** (pas de redéploiement code), testable, et le `http_method` de la ligne d'audit distingue nativement lecture (`GET`) vs mutation. La détection « session fédérée » via `FederatedSession::isFederated()` garantit qu'on ne touche **jamais** le flux AD (AC2).

### D-3 — Écriture d'audit best-effort / fail-soft
L'écriture de la ligne d'audit est **non bloquante** pour la requête métier (iso `QuotaAuditLog` best-effort, `TrashPurgeCommand.php:309-336`) : un `try/catch` enveloppe le `record()` ; un échec → trace `federated.audit.write_failed` dans `federated-auth` (sans PII) mais la réponse de l'utilisateur n'est **pas** dégradée. Rationale : un audit cassé ne doit pas empêcher d'administrer (disponibilité) — mais l'échec est tracé pour détection. *(Si Henri veut un audit BLOQUANT — pas d'action sans trace — c'est une décision forte à arbitrer : Q-5.)*

### D-4 — Capture par middleware terminal sur la session fédérée
La capture est un **middleware** (`AuditExternalAction`) exécuté **après** le guard de session (sinon `Auth::user()` est null), qui dénormalise à l'instant de l'action. C'est le point le moins intrusif (pas de modif des controllers métier, pas de couplage à chaque feature). Il s'active uniquement si `FederatedSession::isFederated()`. *(Le branchement exact — alias + groupe `web` vs middleware global après auth — est Q-2 ; le dev choisit le point qui garantit `Auth::user()` peuplé et n'altère pas le flux LDAP.)*

### D-5 — Dénormalisation à l'instant de l'action (copie, jamais FK seule)
Les 4 champs d'identité (`actor_login`, `actor_external_sub`, `actor_name`, `actor_role`) sont **copiés** dans la ligne au moment de l'écriture (valeurs lues sur `Auth::user()` + `ExternalIdentity` + rôle Spatie actif). Les FK `external_identity_id`/`user_id` sont **best-effort `set null`** et ne servent **jamais** la lecture. C'est la garantie de lisibilité post-anonymisation (raison d'être de l'epic) : la 20.2 réécrit `external_sub`→`anon:<sha256>` et vide la PII **sur l'identité**, mais le log a déjà sa **copie** au moment de l'action. *(Conséquence assumée : le log conserve de la PII même après anonymisation de l'identité — c'est voulu pour l'imputabilité ; la rétention propre du journal est Q-4, hors-scope MVP.)*

### D-6 — `occurred_at` explicite, `timestamps=false` (iso `QuotaAuditLog`)
On suit le pattern `QuotaAuditLog` : `public $timestamps = false;` + colonne `occurred_at` posée à la main dans `record()`. Cohérence avec l'audit existant du projet. *(Alternative `timestamps` standard acceptable si le dev juge `created_at` suffisant — choix mineur, garder la cohérence `QuotaAuditLog`.)*

### D-7 — Migration additive, nouvelle table uniquement
On crée `external_action_audit_logs`. **Aucune** modif des tables existantes (`users`, `external_identities`, `quota_audit_logs`). FK nullable `set null` best-effort sqlite (pattern 20.1). Compatible avec le schéma déjà livré par 20.1/20.2.

### D-8 — Domain-neutral strict
Aucun littéral « controlHub »/« central » dans le code 20.4. `actor_external_sub`/`issuer` restent des strings opaques. Le journal ne porte aucune notion d'IdP nommé (principe fondateur PRD).

---

## Story

As a **DPO / responsable du traitement (contexte éducation)**,
I want **que chaque action d'administration réalisée par un utilisateur externe fédéré soit journalisée avec son identité dénormalisée (login + id externe + nom + rôle actif, copiés au moment de l'action)**,
so that **le journal reste lisible et attribuable même après que l'enregistrement d'identité externe a été soft-deleté ou anonymisé en fin de rétention, sans dépendre d'une jointure vivante**.

## Acceptance Criteria

1. **Given** un utilisateur externe authentifié (session fédérée) réalise une **action mutante** (`POST`/`PUT`/`PATCH`/`DELETE`) **ou un `GET` sur une route sensible** (allowlist `audit.sensitive_get_routes` — D-2), **When** l'action est traitée, **Then** une ligne `ExternalActionAuditLog` est écrite contenant `actor_login`, `actor_external_sub`, `actor_name`, `actor_role` (le rôle Spatie actif), `http_method`, `status_code` et `occurred_at` — toutes **copiées** dans le log (dénormalisées), **pas** seulement référencées par FK.
2. **Given** une action réalisée en **session AD/LDAP normale** (non fédérée), **When** elle est traitée, **Then** **aucune** ligne `external_action_audit_logs` n'est écrite (le journal distingue l'origine **externe** de l'AD locale via `source='federated'` + le marqueur `FederatedSession`).
3. **Given** une ligne d'audit existante, **When** l'`ExternalIdentity` correspondante est **soft-deletée** **puis anonymisée** (`name`/`email`/`login` vidés, `external_sub`→`anon:<sha256>`, ligne soft-deletée — 20.2 D-1/D-5), **Then** la ligne d'audit reste **lisible et attribuable** : `actor_login`/`actor_external_sub`/`actor_name`/`actor_role` conservent les valeurs copiées au moment de l'action (la lisibilité ne dépend d'aucune jointure vivante).
4. **Given** une requête **`GET` sur une route NON sensible** (absente de l'allowlist) en session fédérée, **When** elle est traitée, **Then** **aucune** ligne d'audit n'est écrite (bruit/volumétrie évités). **And Given** une requête **`GET` sur une route SENSIBLE** (présente dans `audit.sensitive_get_routes` — PII élève) en session fédérée, **Then** **une** ligne d'audit dénormalisée est écrite (`http_method='GET'`) — Q-1 tranchée.
5. **Given** l'écriture de la ligne d'audit **échoue** (panne DB / contrainte), **When** la requête métier de l'utilisateur s'exécute, **Then** elle **réussit quand même** (best-effort / fail-soft — D-3) **And** l'échec est tracé dans le channel `federated-auth` (`action_type=federated.audit.write_failed`) **sans PII**.
6. **Given** le rôle Spatie actif de l'utilisateur externe (résolu par 20.3), **When** une action est journalisée, **Then** `actor_role` reflète **exactement** ce rôle (cohérence avec la résolution 20.3).
7. **Given** n'importe quel log **Monolog** (`federated-auth`), **Then** aucune PII clair (`name`/`email`) n'y apparaît — seul le **journal d'audit Eloquent** porte sciemment l'identité dénormalisée (c'est sa finalité bornée).
8. **Given** l'introduction du middleware d'audit, **Then** les suites **20.1 + 20.2 + 20.3** restent **vertes** (le flux de login, le guard D-5 et le flux AD/LDAP sont strictement inchangés — non-régression).
9. **Given** la table `external_action_audit_logs`, **Then** elle est requêtable (scopes `scopeFederated`/`scopeForActor`) et indexée sur `actor_login`/`actor_external_sub`/`source`/`occurred_at`.

## Tasks / Subtasks

- [x] **T0** — Recon (no code) : relire le code livré 20.1/20.2/20.3 (`FederatedLoginController`, `FederatedSession`, `ExternalIdentity` 4 états, `SambaEduAuthGuard::handleFederatedSession`, `FederatedRoleMapper`), confirmer le patron `QuotaAuditLog` (`app/Models/QuotaAuditLog.php` + `database/migrations/2026_02_20_100000_create_quota_tables.php` table `quota_audit_logs`) + son usage best-effort (`TrashPurgeCommand.php:309-336`), confirmer que `Auth::user()` est peuplé pour une session fédérée après le guard, confirmer comment lire le rôle Spatie actif (`$user->getRoleNames()`). **Inventorier (`php artisan route:list` / `routes/web.php`) les routes `GET` exposant de la PII élève** (détail utilisateur, données élève) pour seeder l'allowlist `audit.sensitive_get_routes` par défaut (D-2 / Q-1). Confirmer la suite federated verte localement. Noter tout écart. (AC: 1,4,6,8)
- [x] **T1** — Migration `*_create_external_action_audit_logs_table` (additive) + modèle `App\Models\ExternalActionAuditLog` calqué sur `QuotaAuditLog` : colonnes dénormalisées (`actor_login`/`actor_external_sub`/`actor_name`/`actor_role`), traçabilité (`source`/`http_method`/`route_name`/`path`/`action_label`/`status_code`/`occurred_at`), FK nullable best-effort `set null` (`external_identity_id`/`user_id`), `timestamps=false` (D-6), index, `record()` statique, scopes `scopeFederated`/`scopeForActor`. (AC: 1,2,3,9)
- [x] **T2** — Middleware `App\Http\Middleware\Auth\AuditExternalAction` : actif seulement si `FederatedSession::isFederated($request)` (AC2) ET (méthode **mutante** OU **`GET` sur route sensible** matchant `config('federated_auth.audit.sensitive_get_routes')` — D-2/AC4) ; dénormalise `Auth::user()` + `ExternalIdentity` liée + rôle Spatie actif → `ExternalActionAuditLog::record(...)` après réponse (status connu) ; best-effort `try/catch` + trace `federated.audit.write_failed` sans PII (D-3/AC5,7). **Ajouter le bloc `audit.sensitive_get_routes` à `config/federated_auth.php`** (allowlist conservatrice seedée en T0, commentée domain-neutral). (AC: 1,2,4,5,6,7)
- [x] **T3** — Branchement du middleware (D-4) : alias + application au point qui garantit `Auth::user()` peuplé sans altérer le flux LDAP (groupe `web` après guard de session OU sur les routes app). Vérifier la non-régression du guard. (AC: 1,8)
- [x] **T4** — Tests Feature `ExternalActionAuditTest` (≥9 : action mutante fédérée → ligne dénormalisée ; **GET route sensible fédéré → ligne dénormalisée `http_method='GET'`** ; **GET route NON sensible fédéré → rien** ; action AD → rien ; lisibilité post-soft-delete ; lisibilité post-anonymisation AC3 ; fail-soft AC5 ; rôle actif AC6 ; pas de PII Monolog AC7) + Unit `ExternalActionAuditLogTest` (≥3 : record/scopes/occurred_at). (AC: 1-7,9)
- [x] **T5** — Non-régression : suites 20.1 + 20.2 + 20.3 vertes (login/reconnexion/révocation/guard D-5/résolution rôle/purge). `php -l` 0 erreur sur les fichiers créés/modifiés. (AC: 8)
- [x] **T6** — Runbook QA `docs/qa/domains/federated-login.md` : section **append-only** « Story 20.4 — Audit dénormalisé » (≥5 scénarios 20.4-1..N) + checklist + tableau incidents, sans casser 20.1/20.2/20.3. (AC: 1,2,3,4,5)

## Dev Notes

- **Réutilisation > réécriture (impératif)** :
  - **Audit** : `app/Models/QuotaAuditLog.php` est le **patron exact** (table Eloquent, `::log()` statique, `$casts` array, `timestamps=false` + `created_at` manuel, scopes). **Calquer** sa structure pour `ExternalActionAuditLog::record()`. Schéma de table : `database/migrations/2026_02_20_100000_create_quota_tables.php:52+` (`quota_audit_logs`).
  - **Best-effort** : `app/Console/Commands/TrashPurgeCommand.php:309-336` montre le pattern `try { QuotaAuditLog::log(...) } catch { Log::warning(...) }` — **reproduire** pour ne jamais casser la requête métier (D-3/AC5).
  - **Détection session fédérée** : `App\Auth\Federated\Session\FederatedSession::isFederated($request)` + `externalIdentityId($request)` (20.1) — **réutiliser**, ne pas réinventer le marqueur.
  - **Rôle actif** : `App\Models\User` porte le trait `HasRoles` (Spatie, `app/Models/User.php:49`) → `$user->getRoleNames()->first()` donne le rôle Spatie courant (celui appliqué par `applyRole`/`syncRoles` en 20.3, `FederatedLoginController.php:204-207`). C'est la source de vérité de `actor_role`.
- **Principal & identité** (20.1 D-4) : le `User source='federated'` a `login='ext:<external_sub>'`, `fullname` = nom affiché, `external_identity_id` FK (`FederatedLoginController.php:157-183`). Le `external_sub` clair se lit sur l'`ExternalIdentity` liée (`app/Models/ExternalIdentity.php`). Recharger l'identité au moment de l'audit (ou la garder via `FederatedSession::externalIdentityId()`).
- **Anonymisation 20.2 (raison d'être de la dénormalisation)** : `ExternalIdentityLifecycleService::anonymize()` vide la PII + réécrit `external_sub`→`anon:<sha256>` + soft-delete + `is_active=false`, **jamais hard-delete** (20.2 D-1/D-5, `ExternalIdentity.php:41-49`). Le log doit avoir **copié** login/sub/nom/rôle **avant** ce moment → test AC3 = anonymiser après coup et vérifier que la ligne d'audit est intacte. La FK `external_identity_id` pointe alors sur une ligne anonymisée (ou `set null`) → **sans impact** sur la lecture (valeurs copiées).
- **Guard D-5 (ne pas régresser)** : `app/Http/Middleware/Auth/SambaEduAuthGuard.php` revérifie l'identité par requête (`handleFederatedSession` valide `ExternalIdentity.is_active`). Le middleware d'audit **s'exécute après** ce guard et **ne le modifie pas**. Un test de non-régression du guard (AC8) doit rester vert.
- **Discrimination AC2** : ne PAS auditer une requête où `FederatedSession::isFederated()` est faux → garantit qu'aucune action AD/LDAP n'entre dans `external_action_audit_logs`. `source='federated'` en dur sur chaque ligne (le journal n'a au MVP qu'une seule origine).
- **FK best-effort sqlite** (20.1) : sqlite :memory: ne supporte pas l'`ALTER ADD CONSTRAINT` → poser les FK dans un `try/catch` (ou `Schema::create` avec `foreignId(...)->nullable()->constrained()->nullOnDelete()` qui passe en sqlite récent). Suivre exactement le pattern des migrations 20.1.
- **Volumétrie / index** : un audit d'action peut grossir → index sur les colonnes de requête (`actor_login`, `actor_external_sub`, `source`, `occurred_at`). La rétention du journal lui-même est **hors-scope** (Q-4).
- **Domain-neutral** : aucun littéral « controlHub »/« central ». `issuer`/`sub` opaques.

### Project Structure Notes

- Modèle : `app/Models/ExternalActionAuditLog.php` (à côté de `QuotaAuditLog.php`, cohérence audit).
- Middleware : `app/Http/Middleware/Auth/AuditExternalAction.php` (à côté de `SambaEduAuthGuard.php`/`SambaEduAuth.php`, namespace `App\Http\Middleware\Auth`).
- Migration : `database/migrations/YYYY_MM_DD_HHMMSS_create_external_action_audit_logs_table.php` (additive, nouvelle table).
- Tests : `tests/Feature/Auth/Federated/ExternalActionAuditTest.php`, `tests/Unit/Models/ExternalActionAuditLogTest.php`. Réutiliser le concern `tests/Concerns/IssuesFederatedJwt.php` (schéma SQLite federated de test — **y ajouter** la nouvelle table si le concern crée les tables à la main).
- Config/logging : channel `federated-auth` déjà présent (`config/logging.php:201-210`) — ajouter seulement un nouvel `action_type`, pas de nouveau channel.
- Config audit : ajouter le bloc `audit.sensitive_get_routes` (allowlist de noms/patterns de routes GET sensibles — Q-1) à `config/federated_auth.php`, commenté domain-neutral, seedé conservativement en T0 (routes PII élève). `.env.example` inchangé (pas de toggle env requis ; liste statique config).
- Runbook : `docs/qa/domains/federated-login.md` (append-only, après la section 20.3 ligne ~388).

### References

- [Source: _bmad-output/planning-artifacts/epics.md#Story 20.4 : Logs d'audit dénormalisés des actions externes] (4 AC d'epic + « Séparation identité / accès » + « filet : logs d'audit dénormalisés »)
- [Source: _bmad-output/planning-artifacts/architecture.md#Authentification Fédérée — Phase 2 IdP externe (Epic 20)] (« Audit dénormalisé (Story 20.4) : login + id externe + nom + rôle actif copiés dans le log, pas une simple FK, lisible après soft-delete, origine externe distinguée de l'AD », architecture.md:343 + flux:357)
- [Source: _bmad-output/implementation-artifacts/20-1-login-federe-jwt-controlhub.md] (User externe `source='federated'`/`login=ext:<sub>`, `FederatedSession`, channel `federated-auth`, FK best-effort sqlite)
- [Source: _bmad-output/implementation-artifacts/20-2-identite-externe-persistante.md] (anonymisation D-1/D-5 = raison d'être de la dénormalisation : ligne survit hashée pour que l'audit 20.4 reste corrélable ; AC16 pas de PII Monolog)
- [Source: _bmad-output/implementation-artifacts/20-3-mapping-role-externe-spatie.md] (résolution directe du rôle → `actor_role` = rôle Spatie actif via `syncRoles`)
- [Source: app/Models/QuotaAuditLog.php] (PATRON d'audit Eloquent à calquer : table + `::log()` statique + casts array + scopes + `timestamps=false`)
- [Source: database/migrations/2026_02_20_100000_create_quota_tables.php:52] (schéma `quota_audit_logs` à calquer)
- [Source: app/Console/Commands/TrashPurgeCommand.php:309-336] (pattern audit best-effort `try/catch` autour de `QuotaAuditLog::log`)
- [Source: app/Auth/Federated/Session/FederatedSession.php] (`isFederated()`/`externalIdentityId()` — discrimine externe vs AD)
- [Source: app/Auth/Federated/Http/FederatedLoginController.php:157-207] (provisionUser : `User source='federated'`, `login=ext:<sub>`, `fullname` ; applyRole `syncRoles`)
- [Source: app/Models/ExternalIdentity.php:41-49] (état « Anonymisée » : PII vidée + `external_sub`→`anon:<sha256>`, jamais hard-delete)
- [Source: app/Http/Middleware/Auth/SambaEduAuthGuard.php] (guard D-5 : ne pas régresser, middleware d'audit s'exécute après)
- [Source: config/logging.php:201-210] (channel `federated-auth`)

## Questions pour Henri — TRANCHÉES (2026-06-03)

> Les 5 questions ont été arbitrées par Henri au kickoff. Périmètre figé ci-dessous.

- **Q-1 (périmètre des actions)** → **TRANCHÉ : mutations + GET sensibles.** On journalise les actions mutantes (`POST`/`PUT`/`PATCH`/`DELETE`) **ET** les `GET` exposant de la PII élève (allowlist `audit.sensitive_get_routes`, D-2). Les GET non sensibles restent exclus. *(Divergence du défaut SM « mutantes seules » — élargissement assumé.)*
- **Q-2 (branchement middleware)** → **TRANCHÉ : OK middleware terminal** après le guard de session (D-4), n'auditant que les sessions fédérées. Le dev choisit le point exact garantissant `Auth::user()` peuplé sans toucher le flux LDAP.
- **Q-3 (extensibilité audit AD)** → **TRANCHÉ : hors-scope.** 20.4 n'audite **que** les actions fédérées. La colonne `source` reste extensible sans migration si un audit AD émerge plus tard (sujet distinct).
- **Q-4 (rétention du journal d'audit lui-même)** → **TRANCHÉ : hors-scope MVP.** Pas de purge des `external_action_audit_logs` au MVP. À cadrer comme la rétention RGPD de 20.2 (calquer `federated:purge-identities`) si le besoin juridique émerge.
- **Q-5 (audit bloquant ?)** → **TRANCHÉ : best-effort / fail-soft** (D-3). Un audit KO ne casse pas l'action métier (disponibilité > strictness) ; l'échec est tracé sans PII (`federated.audit.write_failed`).

## Recommandation Modèle Dev

**Modèle recommandé : `opus`.**

Justification : bien que la surface (une table + un modèle + un middleware) paraisse modeste, la story touche le **chemin d'authentification fédérée** avec une **exigence de non-régression stricte** sur trois stories non mergées (20.1/20.2/20.3, dont le guard D-5 sujet à régression), et porte une **logique RGPD/sécurité subtile** : dénormalisation correcte au **bon instant** (avant anonymisation), discrimination externe vs AD sans fuite, fail-soft sans casser l'action, **invariant « pas de PII dans le Monolog »** alors que la table en porte sciemment. La combinaison « auth sensible + RGPD/imputabilité + non-régression sur 3 stories + audit-de-sécurité » dépasse le périmètre confortable de sonnet et reste cohérente avec le choix `opus` de 20.1/20.2/20.3.

---

## Dev Agent Record

### Modèle

`claude-opus-4-8[1m]` (dev-agent BMAD).

### Décisions d'implémentation

- **T0 — Inventaire allowlist GET sensibles** : `php artisan route:list` échoue au bootstrap host (ext-apcu absent, quirk pré-existant) → inventaire fait par lecture directe de `routes/web.php`. Routes GET exposant de la PII élève identifiées dans le groupe `app` : `app.users` (liste), `app.user.show` (détail utilisateur), `app.users.groups.edit` (membres de groupe). Allowlist par défaut **conservatrice** seedée avec ces 3 noms + le filet wildcard `app.users.*` (couvre les sous-écrans utilisateur). Confirmé : `Auth::user()` est peuplé après le guard (`SambaEduAuthGuard::handleFederatedSession` fait `Auth::login($user)`), et le rôle Spatie actif se lit via `getRoleNames()->first()`.
- **T1 — Modèle/migration calqués `QuotaAuditLog`** : `timestamps=false` + `occurred_at` posé à la main dans `record()` (D-6) ; `record()` statique iso `QuotaAuditLog::log()` ; scopes `scopeFederated`/`scopeForActor` ; index `actor_login`/`actor_external_sub`/`source`/`occurred_at` (+ FK). FK best-effort `nullOnDelete()` enveloppées d'un `try/catch` (pattern 20.1 — non bloquant si la plateforme refuse l'ajout de contrainte).
- **T2 — Périmètre** : mutations (POST/PUT/PATCH/DELETE) **toujours** auditées ; `GET` audité **seulement** si `route()->getName()` matche un pattern de `federated_auth.audit.sensitive_get_routes` (matching `Str::is` → support wildcard). Middleware **terminal** : exécute `$next($request)` d'abord (status code connu), puis audite — l'audit n'est jamais sur le chemin critique de la réponse.
- **T2 — ÉCART SÉCURITÉ corrigé (important pour la review)** : la 1re version loggait `$e->getMessage()` dans la trace `federated.audit.write_failed`. Un test a révélé que le message d'une `QueryException` **ré-imprime le SQL avec les valeurs liées** (donc `actor_login`, `actor_name` = **PII**) → violation AC7 (« pas de PII dans le Monolog »). Corrigé : on ne logge que **`$e::class`** (classe d'exception), suffisant pour le diagnostic, jamais ré-identifiant. Le test `audit_write_failure_does_not_break_request_and_is_traced` asserte explicitement l'absence de `Tech Externe`/`tech@example.org` dans le contexte loggé.
- **T2 — Recharge identité via `withTrashed()`** : `ExternalIdentity::withTrashed()->find(...)` pour récupérer le `external_sub` même si l'identité vient d'être soft-deletée entre deux requêtes (corrélation robuste). La lecture du journal ne dépend de toute façon **jamais** de cette FK (D-5, valeurs copiées).
- **T3 — Branchement** : alias Kernel `federated.audit` → appliqué **après** `sambaedu.auth` sur les groupes de routes `app` (`['sambaedu.auth', 'federated.audit']`) et `admin` (`['sambaedu.auth', 'sambaedu.admin', 'federated.audit']`). Point garantissant `Auth::user()` peuplé (le guard `sambaedu.auth` a déjà fait `Auth::login`), sans toucher le flux LDAP (le middleware no-op si `!isFederated()`).
- **T4 — Stratégie de test** : middleware exercé **directement** (parité avec `FederatedLoginEndpointTest` qui exerce guard/controller directement), pour rester déterministe sur le host sans LDAP/PG. Route nommée simulée via `Illuminate\Routing\Route` + `setRouteResolver`. 10 Feature + 4 Unit = 14 tests, 41 assertions.

### Écarts rencontrés

- **`route:list` host KO** (ext-apcu) — contourné par lecture de `routes/web.php` (prévu par la story / mémoire `project_host_composer_platform_req`).
- **Test Architecture `IpxeNamespaceTest::ipxe_3_6_iso_windows_route_is_in_admin_group_with_strict_middlewares`** : assertion **brittle** sur le littéral exact `prefix('admin')->middleware(['sambaedu.auth', 'sambaedu.admin']` (fin de liste épinglée). L'ajout de `'federated.audit'` au groupe admin cassait ce `strpos` exact. **Fix minimal** : assertion rendue robuste (regex sur le préfixe `prefix('admin')->middleware(['sambaedu.auth', 'sambaedu.admin'` sans épingler la fin de liste) — la **propriété de sécurité vérifiée** (groupe admin protégé par auth+admin) est préservée. À signaler en review : 1 fichier de test d'une autre story modifié, justifié et non sécurité-régressif. *(Note : ce test ne tombait qu'en exécution de la suite Architecture complète, pas en isolation — mais le déclencheur est bien le changement 20.4.)*

### Post-review (arbitrages Henri — 2026-06-03)

- **P-1 / P-2 — refactor `terminate()`** : `AuditExternalAction` passe en
  middleware **terminable**. `handle()` se réduit à `return $next($request)` ;
  toute la logique d'audit migre dans `terminate(Request, Response)` (exécuté par
  le Kernel après l'envoi de la réponse, qui re-résout le middleware via le
  container — donc **aucun état d'instance** entre `handle()` et `terminate()`,
  tout dérive des 2 arguments + `Auth::user()`/`config()`). Bénéfices : (P-2)
  l'INSERT sort de la pile de réponse → zéro latence TTFB ; (P-1) une exception
  non catchée est convertie en réponse 500 par le handler **avant** `terminate()`
  → l'action en erreur est désormais auditée (`status_code=500`). Commentaire P-1
  du code mis à jour (limite supprimée, plus « assumée »). `Auth::user()` confirmé
  résolvable en `terminate()` → **pas de fallback** `FederatedSession` requis
  (test `auth_user_is_resolvable_in_terminate`). Helper de test `runMiddleware`
  déclenche désormais `handle()` puis `terminate()` (via instance distincte =
  preuve de l'indépendance d'état). +3 tests : 500 audité, Auth résolu en
  terminate (le 3e étant le helper qui re-route tous les cas existants par
  terminate). **17 Feature verts** (était 14).
- **P-5 — `/test-auth` + test d'archi** : ajout de `federated.audit` à
  `/test-auth` (no-op fonctionnel, GET debug). Nouveau
  `tests/Architecture/FederatedAuditCoverageTest` qui vérifie l'invariant TOTAL
  « toute route `sambaedu.auth` ⇒ `federated.audit` » sur la pile RÉELLE
  (`Route::gatherMiddleware()`, groupes inclus). **Découverte** : 2 routes
  pré-existantes `sambaedu.auth` SANS audit (`livewire/update` POST programmatique
  Livewire ; `api/v1/health/detailed` GET API privée legacy) → **non modifiées**
  (hors scope, non triviales), placées en allowlist d'exceptions tracée dans le
  test pour décision Henri. Toute nouvelle route non auditée fera échouer le test.

### Résultats de tests (host SQLite, réels)

- Unit `ExternalActionAuditLogTest` + Feature `ExternalActionAuditTest` : **14 verts, 41 assertions**.
- Non-régression federated (20.1/20.2/20.3) : `FederatedLoginEndpointTest` + `tests/Unit/Auth/Federated/*` = **62 verts, 154 assertions** ; + `AuthGuardInterfaceTest` + `FederatedPurgeIdentitiesCommandTest` + `KernelScheduleTest` + `FederatedRouteTest` = ensemble **111 verts, 291 assertions**.
- Architecture (suite complète) : **100 verts, 592 assertions**, 2 skipped, 1 risky **pré-existant** (`ApiV1ConfigRoutesTest::no_legacy_unprefixed_routes…` — aucune assertion, hors scope 20.4).
- `php -l` : 0 erreur sur tous les fichiers créés/modifiés.
- Domain-neutral : aucun littéral « controlHub »/« central » dans le code 20.4 (seules occurrences = commentaires pédagogiques pré-existants de la config 20.1).

## File List

### Créés

- `app/Models/ExternalActionAuditLog.php` — modèle d'audit dénormalisé (calqué `QuotaAuditLog`).
- `app/Http/Middleware/Auth/AuditExternalAction.php` — middleware de capture (session fédérée + mutation/GET sensible, best-effort fail-soft).
- `database/migrations/2026_06_03_120000_create_external_action_audit_logs_table.php` — table `external_action_audit_logs` (additive, FK best-effort).
- `tests/Unit/Models/ExternalActionAuditLogTest.php` — 4 cas (record/scopes/occurred_at).
- `tests/Feature/Auth/Federated/ExternalActionAuditTest.php` — 10 cas initiaux (AC1..AC7, AC9) + 3 post-review (500 audité, Auth résolu en terminate, helper terminate).
- `tests/Architecture/FederatedAuditCoverageTest.php` — (post-review P-5) invariant d'archi `sambaedu.auth ⇒ federated.audit` (pile réelle `gatherMiddleware`) + allowlist d'exceptions tracée.

### Modifiés

- `config/federated_auth.php` — ajout bloc `audit.sensitive_get_routes` (allowlist conservatrice, domain-neutral).
- `app/Http/Kernel.php` — alias middleware `federated.audit`.
- `routes/web.php` — `federated.audit` après `sambaedu.auth` sur les groupes `app` et `admin` ; + (post-review P-5) `/test-auth` reçoit aussi `federated.audit`.
- `tests/Concerns/IssuesFederatedJwt.php` — création de la table `external_action_audit_logs` en SQLite de test.
- `tests/Architecture/IpxeNamespaceTest.php` — assertion brittle rendue robuste (fin de liste de middlewares du groupe admin non épinglée).
- `docs/qa/domains/federated-login.md` — section append-only « Story 20.4 » (scénarios 20.4-1..8, incidents, checklist).
- `_bmad-output/implementation-artifacts/20-4-audit-denormalise-actions-externes.md` — tasks cochées, status → review, ce Dev Agent Record.
- `_bmad-output/implementation-artifacts/sprint-status.yaml` — `20-4-…` → `review`.

## Change Log

- 2026-06-03 — Implémentation Story 20.4 (claude-opus-4-8[1m]) : journal d'audit dénormalisé des actions externes. Table + modèle calqués `QuotaAuditLog`, middleware terminal `AuditExternalAction` (session fédérée + mutations/GET sensibles, best-effort fail-soft), allowlist config `audit.sensitive_get_routes`. Correctif sécurité : trace d'échec d'audit = classe d'exception uniquement (jamais `$e->getMessage()` qui fuiterait la PII des valeurs liées SQL — AC7). 14 tests host verts (41 assertions) + non-régression 20.1/20.2/20.3 verte (111/291). Status → review.
- 2026-06-03 — Post-review (arbitrages Henri, claude-opus-4-8[1m]) : refactor `AuditExternalAction` en middleware **terminable** (audit dans `terminate()`) → P-1 (500 désormais auditée) + P-2 (zéro latence TTFB) corrigés. `/test-auth` reçoit `federated.audit` + nouveau test d'archi `FederatedAuditCoverageTest` (invariant `sambaedu.auth ⇒ federated.audit`) → P-5 corrigé. Découverte de 2 routes pré-existantes non auditées (`livewire/update`, `api/v1/health/detailed`) en allowlist tracée, NON modifiées (décision Henri en attente). Tests : ExternalActionAudit **17 verts** (était 14), FederatedAuditCoverage **2 verts**, non-régression federated **107 verts**, Architecture **102 verts** (1 risky pré-existant). `php -l` 0 erreur.

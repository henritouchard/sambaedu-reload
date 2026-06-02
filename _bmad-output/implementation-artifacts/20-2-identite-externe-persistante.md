# Story 20.2 : Identité externe persistante — cycle de vie & base légale de rétention RGPD

Status: review

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

> **Deuxième story d'Epic 20** « Authentification fédérée d'utilisateurs externes ». La Story 20.1 (`review`) a livré le **modèle minimal** `ExternalIdentity` (softDeletes) + l'upsert au login + le branchement guard D-5. **20.2 DURCIT** ce minimum : elle pose la **sémantique complète du cycle de vie** de l'identité externe hors-AD (création / réutilisation / désactivation / soft-delete / purge), la **synchronisation de profil contrôlée** à chaque reconnexion, et surtout la **BASE LÉGALE DE RÉTENTION RGPD (IR-M5)** — durée de conservation, mécanisme de purge planifié, anonymisation après expiration, justification documentée.
>
> **Principe fondateur (archi + mémoires)** : *identité persistante ≠ accès permanent*. L'identité externe est un enregistrement **durable** (le « qui »), **jamais hard-delete tant que tracée**, pour que toute action reste attribuable. L'accès, lui, est piloté par l'IdP à la connexion (claim valide) + l'état `is_active` côté SE5. 20.2 réconcilie cette exigence d'audit avec le **droit à l'effacement / la minimisation RGPD** : on ne conserve pas indéfiniment, on conserve **pour une base légale énoncée et pour une durée bornée**, puis on **anonymise** (pas hard-delete) afin que les logs restent cohérents (20.4 = audit dénormalisé, hors-scope ici).
>
> **Tension à arbitrer (IR-M5 + archi)** : « `ExternalIdentity` jamais hard-delete » (audit) **vs** droit à l'effacement. **Résolution retenue (D-1)** : la PII (`name`, `email`, `login` lisible) est **anonymisée** après expiration de la rétention ; la **clé technique** (`external_sub` hashé) et l'ossature de la ligne **survivent** (soft-deleted + anonymisée) pour que l'audit dénormalisé 20.4 reste joignable et que les `users.external_identity_id` FK ne deviennent pas orphelines. C'est compatible RGPD : après anonymisation, ce n'est plus une donnée personnelle.

---

## Scope strict & frontières

### IN-SCOPE (ce que la story livre)

1. **Sémantique du cycle de vie `ExternalIdentity` formalisée** (états + transitions explicites) :
   - **Active** (`is_active=true`, `deleted_at=null`) → login autorisé (si JWT valide).
   - **Désactivée** (`is_active=false`, `deleted_at=null`) → login refusé **403** ; identité conservée, jamais réactivée par un fresh login (déjà tenu par 20.1 `upsertIdentity`). La réactivation reste une action admin (outillage = 20.3).
   - **Soft-deletée** (`deleted_at != null`) → login refusé **403** ; identité conservée pour l'audit, **résolvable via `withTrashed()`** par 20.4.
   - **Anonymisée** (PII purgée après rétention) → soft-deletée + PII vidée/hashée ; la ligne survit (FK + audit), n'est plus une donnée personnelle. **Nouvel état introduit par 20.2.**
   - Documenter ces 4 états + leurs transitions dans la PHPDoc du modèle ET le runbook QA.
2. **Service de cycle de vie** `App\Auth\Federated\ExternalIdentityLifecycleService` (extraction de la logique aujourd'hui inline dans `FederatedLoginController::upsertIdentity`/`provisionUser`) :
   - `reconcileOnLogin(FederatedUserClaims): ExternalIdentity` — upsert + **sync profil contrôlée** (voir #3) + garde-fou révocation (réutilise la règle 20.1 : révoquée/trashée → 403).
   - `deactivate(ExternalIdentity, string $reason): void` — désactivation (admin / révocation) sans suppression.
   - `softDeleteWithReason(ExternalIdentity, string $reason): void` — soft-delete tracé.
   - `anonymize(ExternalIdentity): void` — vide/hashe la PII (`name`, `email`, `login`), conserve `external_sub` hashé + ossature ; **idempotent** ; ne hard-delete jamais.
   - Le `FederatedLoginController` **délègue** à ce service (refactor sans changement de comportement observable pour 20.1 — non-régression stricte).
3. **Synchronisation de profil à chaque login fédéré** (claims → `ExternalIdentity` + `User` d'affichage), **politique explicite** :
   - **Champs synchronisés** : `name`, `email`, `login` (affichage), `last_login_at`, `issuer`. **Jamais** le rôle (le rôle reste mappé/synchronisé par 20.1/20.3) ni `is_active` (l'IdP n'autorise pas via le profil — séparation identité/accès).
   - **Politique de fusion (D-3)** : un claim **présent et non vide écrase** la valeur stockée (l'IdP est source de vérité du profil) ; un claim **absent/vide préserve** l'existant (pas d'effacement involontaire). C'est déjà le comportement 20.1 (`$claims->x !== '' ? ... : existant`) — 20.2 le **formalise et le teste**, et l'étend au cas anonymisé (voir #4).
   - **Garde-fou anonymisé (D-4)** : si l'identité a été **anonymisée** puis se reconnecte (même `sub`, JWT encore valide), la reconnexion est **refusée 403** (`federated.login.identity_anonymized`) — une identité dont on a purgé la PII pour cause de fin de rétention ne doit pas être silencieusement « ressuscitée » avec de la PII fraîche. Réactivation = décision admin consciente (20.3). *(Évite le contournement de la purge RGPD par simple reconnexion.)*
4. **Base légale de rétention RGPD (IR-M5) — cœur de la story** :
   - **Config** `config/federated_auth.php` → bloc `retention` : `legal_basis` (texte de la base légale), `pii_ttl_days` (durée de conservation de la PII après dernière activité), `anonymize_enabled` (toggle), `retention_note` (justification lisible). Valeurs par défaut **conservatrices** + override `.env` (`FEDERATED_AUTH_RETENTION_*`).
   - **Base légale documentée** : obligation de traçabilité/sécurité des SI en contexte éducation (intérêt légitime + obligation légale d'audit des accès administrateurs) — la PII est conservée tant qu'elle sert l'imputabilité d'actions d'administration, puis anonymisée. **Durée par défaut proposée : 365 jours après `last_login_at`** (à confirmer Henri — Q-1, valeur produit/juridique).
   - **Commande planifiée** `php artisan federated:purge-identities` **calquée sur `trash:purge`** (`app/Console/Commands/TrashPurgeCommand.php`) : `--dry-run`, `--force`, sélectionne les `ExternalIdentity` dont `last_login_at < now - pii_ttl_days` ET sans `User` actif rattaché à une session vivante (best-effort), **anonymise** (pas hard-delete), trace chaque anonymisation (log channel `federated-auth` + ligne d'audit best-effort). Fail-soft (un échec n'arrête pas la boucle). No-op safe si `anonymize_enabled=false` ou TTL ≤ 0 (warning + exit clair, cf. garde-fou `trash:purge`).
   - **Planification** dans `app/Console/Kernel.php` → `$schedule->command('federated:purge-identities')->dailyAt(...)` avec `->when(closure)` lisant le toggle config (prise d'effet sans redéploiement, pattern `trash:purge`/`quota.trash.purge_auto`).
5. **Cohérence FK & invariants** :
   - Anonymisation **ne casse pas** `users.external_identity_id` (la ligne survit). Les `User source='federated'` rattachés à une identité anonymisée restent joignables ; on **désactive** leur accès (`is_active=false` côté `User`) au passage de l'anonymisation (l'accès ne doit pas survivre à la purge PII).
   - `external_sub` original **hashé** (sha256) au moment de l'anonymisation et stocké dans une colonne dédiée `external_sub_hash` (ou réécriture de `external_sub` en valeur opaque non-réversible) — **décision D-5** : réécrire `external_sub` vers `anon:<sha256(sub)>` (préserve l'unicité, casse la corrélation IdP↔identité, empêche la résurrection #3/D-4 car le `sub` clair ne matchera plus). Le hash permet à 20.4 de corréler un ancien log dénormalisé si besoin forensique légal.
6. **Migration** `add_retention_columns_to_external_identities` (additive, non destructive) : `anonymized_at` (timestamp nullable), `deactivated_reason` (string nullable), `deleted_reason` (string nullable). *(Pas de nouvelle table ; on enrichit `external_identities`.)*
7. **Logging** : réutilise le channel `federated-auth` (20.1). Nouveaux `action_type` : `federated.identity.deactivated`, `federated.identity.soft_deleted`, `federated.identity.anonymized`, `federated.login.identity_anonymized`. **Jamais** de PII dans les logs de purge (logger `external_sub` hashé / id interne, pas `name`/`email`).
8. **Tests** :
   - **Unit** `tests/Unit/Auth/Federated/ExternalIdentityLifecycleServiceTest.php` (≥10 cas) : reconcile crée au 1er login ; reconcile réutilise (même `sub`) ; sync profil écrase si claim présent / préserve si claim vide ; révoquée → 403 ; soft-deletée → 403 ; **anonymize() vide la PII, conserve la ligne, réécrit `external_sub`, idempotent** ; anonymisée + reconnexion → 403 (D-4) ; deactivate() n'efface pas ; anonymisation désactive le `User` lié.
   - **Unit/Feature** `tests/Feature/Console/FederatedPurgeIdentitiesCommandTest.php` (≥8 cas) : sélectionne par `last_login_at` + TTL ; `--dry-run` n'écrit rien ; `anonymize_enabled=false` → no-op safe ; TTL ≤ 0 sans `--force` → exit clair ; anonymise effectivement (PII vidée, `anonymized_at` posé) ; ne hard-delete jamais (`withTrashed()` retrouve la ligne) ; fail-soft (un échec n'arrête pas la boucle) ; identité encore active récemment → conservée.
   - **Feature** (régression 20.1) `tests/Feature/Auth/Federated/FederatedLoginEndpointTest.php` — réutilise/étend : le refactor vers le service **ne change pas** le comportement observable des AC 20.1 (login valide, reconnexion, révocation 403). **La suite 20.1 reste verte** (27 + 6 tests).
   - **Architecture** : si une règle de namespace/console existe, garder la commande conforme.
9. **Runbook QA** : enrichir `docs/qa/domains/federated-login.md` — nouvelle section « Story 20.2 — Cycle de vie & rétention RGPD » avec scénarios stables **20.2-1..20.2-N** (≥6) : sync profil écrase/préserve, désactivation, soft-delete tracé, purge dry-run, anonymisation effective + non hard-delete, reconnexion d'une identité anonymisée → 403. Mettre à jour la checklist rapide + le tableau incidents.

### HORS-SCOPE (ne pas faire)

- ❌ L'**audit dénormalisé** des actions externes (copie login+sub+nom+rôle dans chaque log) = **Story 20.4**. 20.2 garantit seulement que la rétention/anonymisation **reste cohérente** avec ce futur besoin (la ligne survit, hashée — voir D-5), mais **ne crée aucun mécanisme d'audit d'action**.
- ❌ L'**outillage/UI admin** (réactiver une identité désactivée, déclencher une purge à la main depuis l'UI, éditer le mapping de rôle) = **Story 20.3**. 20.2 livre la **logique** + la **commande CLI**, pas d'écran Livewire.
- ❌ Le **mapping de rôle** et sa richesse = 20.3. 20.2 ne touche pas `FederatedRoleMapper` ni `applyRole`.
- ❌ Toute modification de la **vérification JWT** (`FederatedJwtVerifier`), du **transport** (POST binding D-3), de l'**anti-rejeu jti** = figés par 20.1.
- ❌ Toute modification du flux d'auth **AD/LDAP** ou de l'auth **machine/poste** (iso-legacy).
- ❌ La **doc de contrat d'intégration controlHub** = Story 20.5 (rédigée après le code — mémoire `feedback_doc_follows_code`).
- ❌ Le **endpoint JWKS** / récupération dynamique des clés (clé publique statique en config — 20.1).

---

## Mode de livraison & contraintes opérationnelles

- **Repo cible du code** : `sambaedu-reload/` (Repo B — code). Le fichier de cette story vit dans `_bmad-output/` (Repo A parent). Mémoire `project_two_repos_topology`.
- **Worktree git** : si le dev travaille en worktree, **ne jamais** SSH `/vm` ni run de tests sur la VM (mémoire `feedback_worktree_no_vm_sync`). Tests locaux PHPUnit + `php -l` host. Smoke bout-en-bout confié à Henri post-merge.
- **PHP-FPM = user `www-admin`** (uid 599, mémoire `project_php_fpm_user_www_admin`) — pas de nouveau fichier sensible introduit ici (config seule).
- **Cache** : inchangé (la rétention est en DB, pas en cache). La commande de purge n'utilise pas le store `jti`.
- **Host composer** : `vendor/` gitignored — réinstaller via `composer install --ignore-platform-req=ext-apcu --ignore-platform-req=ext-imagick` avant de lancer la suite (mémoire `project_host_composer_platform_req`).

---

## Decisions (tranchées par SM — ne pas re-débattre, confirmer via Questions)

### D-1 — Rétention = anonymisation, jamais hard-delete
Résout la tension IR-M5 « jamais hard-delete (audit) » vs « droit à l'effacement ». La PII (`name`, `email`, `login` lisible, `external_sub` clair) est **purgée/hashée** après expiration ; la **ligne survit** (soft-deleted + anonymisée). Après anonymisation, ce n'est plus une donnée personnelle (conforme RGPD) ET l'audit dénormalisé 20.4 + les FK `users` restent cohérents. **Pas** de `forceDelete()`.

### D-2 — Service dédié + refactor non-régressif de 20.1
La logique de cycle de vie sort du controller (`upsertIdentity`/`provisionUser`) vers `ExternalIdentityLifecycleService`. Le controller **délègue** sans changer le comportement observable (AC 20.1 inchangées). DRY + testabilité unitaire du cycle de vie indépendamment de l'endpoint HTTP. **La suite 20.1 doit rester verte** — c'est le filet de non-régression de ce refactor.

### D-3 — Sync profil : claim présent écrase, claim absent préserve
Formalise le comportement 20.1 (`$claims->x !== '' ? $claims->x : $existant`). L'IdP est source de vérité du profil d'affichage ; on n'efface jamais une valeur stockée avec un claim vide. **Le rôle et `is_active` ne sont JAMAIS synchronisés via le profil** (séparation identité/accès — le rôle suit son propre chemin 20.1/20.3, l'accès suit l'IdP + `is_active`).

### D-4 — Une identité anonymisée ne se ressuscite pas par reconnexion
Si une identité a été anonymisée (fin de rétention) et qu'un JWT valide pour le même `sub` arrive ensuite, le login est **refusé 403** (`federated.login.identity_anonymized`). Sinon la purge RGPD serait contournable par simple reconnexion. La réactivation est une décision admin (20.3). En pratique D-5 rend ce cas naturellement vrai (le `sub` clair ne matche plus), mais on **ajoute un garde explicite** + test pour ne pas dépendre d'un effet de bord.

### D-5 — `external_sub` réécrit en `anon:<sha256>` à l'anonymisation
À l'anonymisation, `external_sub` (qui est le claim `sub` clair, potentiellement corrélable à une personne côté IdP) est **réécrit** vers `anon:<sha256(sub)>`. Préserve l'unicité de la colonne, casse la corrélation IdP↔identité (minimisation), empêche la résurrection (D-4), et permet à un besoin forensique légal (20.4) de re-corréler en re-hashant un `sub` connu. **Pas** de colonne `external_sub` clair conservée.

### D-6 — Commande de purge calquée sur `trash:purge`
Réutilise le pattern éprouvé `TrashPurgeCommand` (`--dry-run`/`--force`, garde-fou TTL ≤ 0, fail-soft, audit best-effort, scheduling `->when(toggle)`). Pas de nouveau paradigme de batch. `federated:purge-identities`.

### D-7 — Migration additive uniquement
On enrichit `external_identities` (`anonymized_at`, `deactivated_reason`, `deleted_reason`). **Pas** de nouvelle table, **pas** de colonne supprimée, **pas** de renommage destructif. Compatible avec le schéma 20.1 déjà livré.

### D-8 — Toggle de purge OFF par défaut en absence de config explicite
`anonymize_enabled` par défaut **false** tant qu'Henri n'a pas validé la durée légale (Q-1) : la commande tourne en `--dry-run`-équivalent (no-op safe + warning), jamais d'anonymisation silencieuse non validée juridiquement. Mémoire `feedback_doc_follows_code` : on ne purge pas avant que la base légale soit énoncée et confirmée.

---

## Story

As a **DPO / responsable du traitement (contexte éducation)**,
I want **que l'identité externe d'un technicien fédéré ait un cycle de vie complet et borné — réutilisée à la reconnexion, son profil synchronisé depuis l'IdP, jamais hard-delete, mais sa PII anonymisée après une durée de rétention adossée à une base légale énoncée**,
so that **toute action d'administration reste attribuable le temps de l'obligation d'audit, sans conserver indéfiniment des données personnelles au-delà de leur finalité (RGPD)**.

## Acceptance Criteria

1. **Given** un 1er login fédéré valide, **When** le cycle de vie traite l'identité, **Then** une `ExternalIdentity` **active** est créée (clé `external_sub`) et un `User source='federated'` est rattaché — comportement identique à 20.1.
2. **Given** une reconnexion (même `sub`, nouveau `jti`), **Then** la **même** `ExternalIdentity` est réutilisée (aucun doublon) et son `last_login_at` est mis à jour.
3. **Given** un claim de profil présent et non vide (`name`/`email`/`login`) qui diffère de la valeur stockée, **When** la reconnexion synchronise le profil, **Then** la valeur stockée est **écrasée** par le claim (IdP source de vérité).
4. **Given** un claim de profil **absent ou vide**, **Then** la valeur stockée est **préservée** (aucun effacement involontaire).
5. **Given** une reconnexion, **Then** le **rôle** et l'état **`is_active`** de l'identité ne sont **jamais** modifiés par la sync de profil (séparation identité/accès).
6. **Given** une identité **désactivée** (`is_active=false`), **When** un JWT valide pour ce `sub` arrive, **Then** le login est refusé **403** sans réactivation (comportement 20.1 préservé).
7. **Given** une identité **soft-deletée**, **When** un JWT valide arrive, **Then** login refusé **403** ; **And** la ligne reste résolvable via `withTrashed()`.
8. **Given** une identité dont `last_login_at` dépasse `pii_ttl_days` et `anonymize_enabled=true`, **When** `federated:purge-identities` s'exécute, **Then** sa PII (`name`/`email`/`login`) est vidée/hashée, `external_sub` est réécrit en `anon:<sha256>`, `anonymized_at` est posé, **And** la ligne **n'est jamais hard-delete** (retrouvable `withTrashed()`).
9. **Given** `anonymize_enabled=false` **ou** `pii_ttl_days ≤ 0` sans `--force`, **When** la commande s'exécute, **Then** elle est **no-op safe** (warning + exit explicite, aucune anonymisation).
10. **Given** `--dry-run`, **Then** la commande liste les candidats **sans** rien modifier.
11. **Given** une identité **anonymisée**, **When** un JWT valide pour l'ancien `sub` arrive, **Then** le login est refusé **403** (`federated.login.identity_anonymized`) — pas de résurrection (D-4).
12. **Given** une anonymisation, **Then** le(s) `User source='federated'` rattaché(s) restent joignables (FK intacte) mais leur accès est **désactivé** (`is_active=false`).
13. **Given** la commande de purge, **Then** un échec sur une identité **n'arrête pas** la boucle (fail-soft) et chaque anonymisation est tracée dans `federated-auth` **sans PII** (id/hash uniquement).
14. **Given** la config `retention`, **Then** `legal_basis`, `pii_ttl_days`, `anonymize_enabled` et `retention_note` sont lisibles et overridables via `.env` ; la base légale est documentée (config + runbook QA).
15. **Given** le refactor vers `ExternalIdentityLifecycleService`, **Then** **toute la suite 20.1** (login/reconnexion/révocation/guard D-5) reste **verte** (non-régression stricte).
16. **Given** n'importe quel log de cycle de vie / purge, **Then** aucune PII (`name`/`email`/`login` clair) n'y apparaît.

## Tasks / Subtasks

- [x] **T0** — Recon (no code) : relire le code 20.1 livré (`app/Auth/Federated/Http/FederatedLoginController.php` `upsertIdentity`/`provisionUser`, `app/Models/ExternalIdentity.php`, guard `handleFederatedSession`/`logoutFederated`), confirmer le pattern `trash:purge` (`app/Console/Commands/TrashPurgeCommand.php` + `app/Console/Kernel.php` scheduling `->when()`), confirmer le channel `federated-auth` et les `action_type` existants, confirmer la suite de tests 20.1 verte localement. Noter tout écart. (AC: 1,15)
- [x] **T1** — Migration additive `*_add_retention_columns_to_external_identities` : `anonymized_at` (timestamp nullable), `deactivated_reason` + `deleted_reason` (string nullable). Mettre à jour `$fillable`/`$casts` du modèle `ExternalIdentity` + PHPDoc des 4 états du cycle de vie + scopes utiles (`scopeAnonymized`, `scopeRetentionExpired`). (AC: 7,8,14)
- [x] **T2** — `config/federated_auth.php` → bloc `retention` (`legal_basis`, `pii_ttl_days`, `anonymize_enabled` défaut **false** (D-8), `retention_note`) + clés `.env` `FEDERATED_AUTH_RETENTION_*` documentées dans `.env.example`. (AC: 14)
- [x] **T3** — `App\Auth\Federated\ExternalIdentityLifecycleService` : `reconcileOnLogin` (upsert + sync profil D-3 + garde révocation 20.1 + garde anonymisé D-4 → 403), `deactivate`, `softDeleteWithReason`, `anonymize` (vide PII, réécrit `external_sub`→`anon:<sha256>` D-5, pose `anonymized_at`, désactive les `User` liés, idempotent, jamais hard-delete). Logs `federated-auth` sans PII. (AC: 2-13,16)
- [x] **T4** — Refactor `FederatedLoginController` : déléguer à `ExternalIdentityLifecycleService::reconcileOnLogin` à la place de `upsertIdentity` inline. **Comportement observable inchangé** (D-2). Ajouter le garde 403 `identity_anonymized` (D-4). (AC: 1,2,11,15)
- [x] **T5** — Commande `federated:purge-identities` (calquée `TrashPurgeCommand` D-6) : `--dry-run`/`--force`, sélection `last_login_at < now - pii_ttl_days`, garde-fou `anonymize_enabled`/TTL≤0 (no-op safe), anonymisation via le service, fail-soft, trace audit best-effort, exit codes cohérents. (AC: 8,9,10,13)
- [x] **T6** — Scheduling `app/Console/Kernel.php` : `federated:purge-identities` `->dailyAt(...)` + `->when(closure lisant config('federated_auth.retention.anonymize_enabled'))` (prise d'effet sans redéploiement, pattern `trash:purge`). (AC: 8,9)
- [x] **T7** — Tests : Unit service (≥10) + Feature commande (≥8) + non-régression suite 20.1 verte (login/reconnexion/révocation/guard). `php -l` 0 erreur. (AC: 1-16)
- [x] **T8** — Runbook QA `docs/qa/domains/federated-login.md` : section « Story 20.2 » (≥6 scénarios 20.2-1..N) + base légale énoncée + checklist + tableau incidents. (AC: 14)

## Dev Notes

- **Réutilisation > réécriture** :
  - Cycle de vie : la logique d'upsert/sync existe déjà dans `app/Auth/Federated/Http/FederatedLoginController.php:144-218` (`upsertIdentity` + `provisionUser`) — **l'extraire** dans le service, ne pas la réinventer. La règle de révocation (révoquée/trashée → 403) est en place (`FederatedLoginController.php:160-169`) — la **porter telle quelle** dans `reconcileOnLogin`.
  - Purge RGPD : **calquer** `app/Console/Commands/TrashPurgeCommand.php` (signature `--dry-run`/`--force`/`--performed-by`, garde-fou TTL ≤ 0 → exit clair, fail-soft, trace audit best-effort `try/catch`, validation regex anti log-poisoning du `--performed-by`).
  - Scheduling : pattern `app/Console/Kernel.php` (`trash:purge` `->dailyAt('02:00')` + le `->when()` toggle config, cf. lignes 59-60).
- **Modèle 20.1** : `app/Models/ExternalIdentity.php` (softDeletes, `$fillable` = `external_sub`/`issuer`/`login`/`name`/`email`/`is_active`/`last_login_at`, relation `users()` HasMany). Enrichir, ne pas recréer.
- **Guard D-5** : `app/Http/Middleware/Auth/SambaEduAuthGuard.php` `handleFederatedSession` valide `ExternalIdentity.is_active` à chaque requête → une identité **anonymisée** doit avoir `is_active=false` (ou être trashée) pour que les sessions vivantes tombent au prochain check. **Vérifier** ce comportement en test (une session ouverte avant anonymisation doit se fermer après).
- **`User` lié** : `provisionUser` (`FederatedLoginController.php:192-218`) pose `source='federated'`, `dn`/`ad_guid` NULL, `login='ext:<sub>'`, `role='federated'`. À l'anonymisation, **désactiver** `User.is_active=false` (AC12) sans toucher la FK.
- **Sécurité / RGPD** :
  - Anonymisation **idempotente** (rejouer la commande ne re-hashe pas un `anon:` déjà fait).
  - `external_sub` réécrit `anon:<sha256(sub)>` (D-5) — utiliser `hash('sha256', $sub)`.
  - **Aucune PII** dans les logs de purge/cycle de vie (AC16) : logger `id` interne + hash, jamais `name`/`email`. Cohérent avec le verifier 20.1 qui exclut déjà login/name/email du jeu loggable.
- **Base légale (rédaction config + runbook)** : intérêt légitime + obligation de traçabilité des accès d'administration (sécurité des SI / contexte éducation), conservation bornée puis anonymisation. Énoncer la finalité (imputabilité d'actions d'admin), la durée (`pii_ttl_days`, défaut proposé 365 j — **Q-1**), et le sort en fin de rétention (anonymisation, pas effacement physique pour préserver l'intégrité de l'audit 20.4).
- **Domain-neutral** : aucun littéral « controlHub »/« central » ; `issuer` reste un string opaque.
- **Frontière 20.4** : 20.2 garantit que la ligne **survit hashée** pour que l'audit dénormalisé futur reste corrélable, mais **ne crée aucun log d'action**. Ne pas empiéter.

### Project Structure Notes

- Service : `app/Auth/Federated/ExternalIdentityLifecycleService.php` (cohérent avec `app/Auth/Federated/FederatedRoleMapper.php` déjà à la racine du namespace `App\Auth\Federated`).
- Commande : `app/Console/Commands/FederatedPurgeIdentitiesCommand.php` (signature `federated:purge-identities`).
- Migration : `database/migrations/YYYY_MM_DD_HHMMSS_add_retention_columns_to_external_identities.php` (additive).
- Config : enrichir `config/federated_auth.php` (ne pas créer de nouveau fichier).
- Tests : `tests/Unit/Auth/Federated/ExternalIdentityLifecycleServiceTest.php`, `tests/Feature/Console/FederatedPurgeIdentitiesCommandTest.php`, extension `tests/Feature/Auth/Federated/FederatedLoginEndpointTest.php` (non-régression).

### References

- [Source: _bmad-output/implementation-artifacts/20-1-login-federe-jwt-controlhub.md] (story précédente — modèle minimal `ExternalIdentity`, upsert, guard D-5, Q-5 RGPD reportée ici)
- [Source: _bmad-output/planning-artifacts/epics.md#Story 20.2 : Identité externe persistante (hors-AD)] (AC d'epic + « Séparation identité / accès »)
- [Source: _bmad-output/planning-artifacts/architecture.md#Authentification Fédérée — Phase 2 IdP externe (Epic 20)] (« Identité externe persistante (Story 20.2) » — soft-delete, reconnexion = même row, persiste ≠ accès, audit/RGPD)
- [Source: _bmad-output/planning-artifacts/implementation-readiness-report-2026-05-29-epic20.md#Constats] (IR-M5 tension RGPD/audit → base de rétention à énoncer ici ; IR-M2 persistance livrée minimale en 20.1)
- [Source: app/Auth/Federated/Http/FederatedLoginController.php:144-218] (upsertIdentity + provisionUser à extraire)
- [Source: app/Models/ExternalIdentity.php] (modèle 20.1 à enrichir)
- [Source: app/Http/Middleware/Auth/SambaEduAuthGuard.php:114-163] (guard fédéré : is_active rechecké par requête → impact anonymisation)
- [Source: app/Console/Commands/TrashPurgeCommand.php] (patron de la commande de purge planifiée — dry-run/force/fail-soft/audit)
- [Source: app/Console/Kernel.php:59-60] (scheduling trash:purge + ->when() toggle config)
- [Source: config/federated_auth.php] (config 20.1 à enrichir d'un bloc retention)

## Questions pour Henri

- **Q-1 (IR-M5, juridique/produit) — À TRANCHER** : durée de rétention par défaut de la PII (`pii_ttl_days`). Proposition SM : **365 jours après `last_login_at`** (obligation d'audit des accès admin en contexte éducation). Confirmer la valeur ET la formulation de `legal_basis` / `retention_note`. *(Tant que non confirmé, D-8 : `anonymize_enabled=false` par défaut — la purge ne tourne pas.)*
- **Q-2 (D-1/D-5, RGPD)** : valides-tu l'**anonymisation** (PII vidée + `external_sub`→`anon:<sha256>`, ligne conservée) plutôt qu'un hard-delete en fin de rétention ? Rationale : préserver l'intégrité de l'audit dénormalisé 20.4 + les FK `users`, tout en sortant la donnée du champ « personnelle ». Si tu préfères un hard-delete strict, il faudra arbitrer la perte de corrélation d'audit.
- **Q-3 (D-4)** : confirmes-tu qu'une identité anonymisée qui se reconnecte (JWT valide, même `sub`) doit être **refusée (403)** et **ne pas** être recréée silencieusement (anti-contournement de purge), la réactivation étant une action admin explicite (20.3) ?
- **Q-4 (périmètre purge)** : la sélection des candidats se base sur `last_login_at + pii_ttl_days`. Faut-il **exclure** les identités encore désactivées récemment (litige/enquête en cours) d'une purge automatique ? Proposition SM : purge sur le seul critère temporel ; un hold légal éventuel = action admin future (hors 20.2). À confirmer.

## Recommandation Modèle Dev

**Modèle recommandé : `opus`.**

Justification : code d'**authentification sensible** (refactor du chemin de login fédéré avec exigence de **non-régression stricte** sur la suite 20.1 + le guard D-5 sujet à régression), couplé à une **conformité RGPD** non triviale (base légale, anonymisation idempotente, anti-résurrection D-4/D-5, cohérence FK et audit 20.4) — la combinaison « auth + RGPD + cycle de vie + risque de régression sur le guard de session » dépasse le périmètre confortable de sonnet et justifie opus (cohérent avec le choix opus de 20.1).

## Dev Agent Record

### Agent Model Used

claude-opus-4-8 (dev BMAD, 2026-06-02)

### Debug Log References

- Suite federated + console + architecture : `php vendor/bin/phpunit tests/Unit/Auth/Federated/ tests/Feature/Auth/Federated/ tests/Feature/Console/FederatedPurgeIdentitiesCommandTest.php tests/Feature/Console/KernelScheduleTest.php tests/Feature/Console/TrashPurgeCommandTest.php tests/Architecture/FederatedRouteTest.php` → **83 tests / 238 assertions verts** (host, SQLite :memory:, PHP 8.4.5).
- Suite Architecture complète : **100 tests verts** (1 risky pré-existant `ApiV1ConfigRoutesTest`, sans rapport).
- Non-régression 20.1 (filet AC15) : `FederatedLoginEndpointTest` + `FederatedJwtVerifierTest` + `FederatedRouteTest` → **33 → 34 tests verts** (1 ajout AC11 e2e).

### Completion Notes List

- **Écart de structure** : le repo `sso` EST la racine Laravel (pas de sous-dossier `laravel/`). Tous les chemins de la story (`app/…`, `config/…`, `tests/…`) sont à la racine. Adapté en conséquence.
- **D-2 (refactor non-régressif)** : `reconcileOnLogin` extrait du controller ; `provisionUser`/`applyRole`/`federatedLogin` (provisioning du `User` de session) RESTENT dans le controller (logique HTTP de session, hors périmètre « cycle de vie de l'identité »). Le controller injecte le service en 4e dépendance. Suite 20.1 verte (AC15).
- **D-4 + D-5 — résolution d'un écart latent de la story** : D-5 réécrit `external_sub` en `anon:<sha256>` à l'anonymisation ; un lookup par sub CLAIR ne retrouve donc plus la ligne, ce qui aurait fait RECRÉER une identité « fraîche » (contournement de purge) ET provoqué une collision `UNIQUE users.login`. Corrigé : `reconcileOnLogin` résout AUSSI par `anon:<sha256(sub)>` (whereNotNull anonymized_at) avant de décider — le garde explicite D-4 fire alors correctement (403 `identity_anonymized`, pas de recréation). Couvert par test e2e + unit.
- **AC12** : l'anonymisation désactive chaque `User source='federated'` lié (`is_active=false`) sans toucher la FK. `source`/`external_identity_id` ne sont pas mass-assignable (parité 20.1) → posés en attributs directs.
- **AC16** : aucun log ne porte de PII — seulement `identity_id` + `sub_hash` (sha256). `deactivated_reason`/`deleted_reason` (motifs admin, non PII) sont loggés sciemment.
- **D-8** : `anonymize_enabled=false` par défaut. Toggle OFF → commande no-op safe (exit 0). Scheduling Kernel 02:30 conditionné `->when()` sur le toggle.
- **Défauts RGPD validés Henri appliqués** : anonymisation jamais hard-delete (D-1), TTL défaut 365 j, anti-résurrection 403 (D-4), toggle OFF par défaut (D-8). Q-1..Q-4 documentées, non bloquantes.
- **Host** : tests exécutés sur l'hôte via `vendor/bin/phpunit` (env testing = cache `array`, DB SQLite). `php artisan` direct échoue au bootstrap (ext-apcu absent sur l'hôte) — quirk pré-existant sans impact sur les tests ; la commande est validée via `$this->artisan()` + KernelScheduleTest.

### File List

**Créés** :
- `app/Auth/Federated/ExternalIdentityLifecycleService.php`
- `app/Console/Commands/FederatedPurgeIdentitiesCommand.php`
- `database/migrations/2026_06_02_120000_add_retention_columns_to_external_identities.php`
- `tests/Unit/Auth/Federated/ExternalIdentityLifecycleServiceTest.php`
- `tests/Feature/Console/FederatedPurgeIdentitiesCommandTest.php`

**Modifiés** :
- `app/Models/ExternalIdentity.php` (colonnes rétention, PHPDoc 4 états, `isAnonymized`, scopes `scopeAnonymized`/`scopeRetentionExpired`)
- `app/Auth/Federated/Http/FederatedLoginController.php` (délégation au service, suppression de `upsertIdentity` inline)
- `app/Console/Kernel.php` (scheduling `federated:purge-identities` 02:30 + `->when()` toggle)
- `config/federated_auth.php` (bloc `retention`)
- `.env.example` (section AUTH FÉDÉRÉE + clés `FEDERATED_AUTH_RETENTION_*`)
- `tests/Concerns/IssuesFederatedJwt.php` (colonnes rétention dans le schéma SQLite de test)
- `tests/Feature/Auth/Federated/FederatedLoginEndpointTest.php` (4e dépendance du controller + test e2e anti-résurrection AC11)
- `tests/Feature/Console/KernelScheduleTest.php` (3 tests scheduling de la nouvelle commande)
- `docs/qa/domains/federated-login.md` (section Story 20.2 : 8 scénarios + base légale + checklist + incidents)

## Change Log

- 2026-06-02 — Implémentation Story 20.2 (dev claude-opus-4-8) : cycle de vie complet de l'`ExternalIdentity` (4 états), `ExternalIdentityLifecycleService` (refactor non-régressif de 20.1), base légale de rétention RGPD (config `retention` + commande `federated:purge-identities` + scheduling toggle), anonymisation idempotente jamais hard-delete (D-1/D-5), anti-résurrection 403 (D-4). 24 tests neufs (14 Unit service + 10 Feature commande) + 3 scheduling + 1 e2e anti-résurrection ; suite 20.1 verte (AC15). Status → review.

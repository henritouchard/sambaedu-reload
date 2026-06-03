# QA manuel — Domaine Login fédéré (IdP externe de confiance)

> Runbook E2E pour les stories du domaine **Login fédéré** (Epic 20 —
> authentification d'utilisateurs externes hors-AD via JWT signé). Append-only :
> chaque story ajoute une section avec ses scénarios numérotés stables.
>
> **Principe domain-neutral** : SE5 ne connaît qu'un « émetteur externe de
> confiance » identifié par config (`expected_iss`). controlHub en est *une*
> instance — aucune notion de « central » dans le produit. Le rendu du
> formulaire POST auto-soumis (transport du JWT) est **côté IdP** (hors SER,
> documenté en Story 20.5).

**Pré-requis communs** :

- VM accessible : `ssh -i ~/.ssh/id_se4fs_vm root@192.168.122.50`
- Migrations à jour : `cd /var/www/sambaedu-reload && php artisan migrate`
  (tables `external_identities`, `federated_jwt_consumptions` + colonnes
  `users.source` / `users.external_identity_id`).
- Cache Spatie reset : `php artisan permission:cache-reset`.
- Composer à jour (lib `firebase/php-jwt` installée).
- APCu CLI activé (anti-rejeu `jti`) : `php -r 'var_dump(apcu_enabled());'`.
- **Clé publique PEM de l'émetteur** configurée dans `config/federated_auth.php`
  (`FEDERATED_AUTH_JWT_PUBLIC_KEY_PATH`), lisible par `www-admin` :
  `ls -l <path> && sudo -u www-admin cat <path> >/dev/null && echo OK`.
- `.env` : `FEDERATED_AUTH_EXPECTED_ISS`, `FEDERATED_AUTH_EXPECTED_AUD`
  (= identifiant de CETTE instance), `FEDERATED_AUTH_JWT_KID`.
- Outil pour forger un JWT de test signé avec la clé **privée** de l'IdP
  (script controlHub de QA, ou snippet `openssl`/php-jwt côté poste de test).

---

## Story 20.1 — Login fédéré : validation du JWT & ouverture de session externe

**Date livraison** : 2026-06-01
**Migrations à appliquer** :
`2026_06_01_120000_create_external_identities_table.php` +
`2026_06_01_120100_add_source_and_external_identity_to_users_table.php` +
`2026_06_01_120200_create_federated_jwt_consumptions_table.php`
**Endpoint** : `POST /auth/federated/callback` (exempté CSRF — POST binding
cross-site ; la preuve d'authenticité est le JWT signé).

### Section 1 — Login nominal

#### Scénario 20.1-1 — Login fédéré valide → session + rôle

**Préparation** : forger côté IdP un JWT signé `RS256` avec :
`iss = <expected_iss>`, `aud = <expected_aud>`, `sub = ext-qa-001`,
`tier = federated-user`, `role = technicien`, `login`, `name`, `email`,
`jti` unique, `iat`/`nbf` = maintenant, `exp` = maintenant + 5 min.

**Action** : soumettre le jeton :

```bash
curl -i -X POST https://<instance>/auth/federated/callback \
  -c /tmp/fed_cookies.txt \
  --data-urlencode "token=<JWT_RS256>"
```

**Attendu** :

- Réponse **302** vers `/app/dashboard` (ou l'URL « intended »).
- Un cookie de session est posé (`/tmp/fed_cookies.txt`).
- En DB :
  - `external_identities` contient une row `external_sub = ext-qa-001`,
    `is_active = true`, `last_login_at` renseigné.
  - `users` contient un user `source = federated`,
    `external_identity_id` = id de l'identité, `dn` NULL, `ad_guid` NULL.
  - `model_has_roles` : le user porte le rôle Spatie `technicien`.
- Channel `federated-auth` : événement `federated.login.success`
  (`sub`/`jti`/`iss`/`role` uniquement, **jamais** le JWT ni de PII).

#### Scénario 20.1-2 — Identité réutilisée à la reconnexion (clé `external_sub`)

**Action** : rejouer le 20.1-1 avec le **même `sub`** mais un **nouveau `jti`**.

**Attendu** :

- **Aucune** nouvelle row `external_identities` (toujours 1 pour `ext-qa-001`).
- **Aucun** nouveau `users` `source=federated` pour cette identité.
- `last_login_at` mis à jour.

### Section 2 — Rejets de sécurité

#### Scénario 20.1-3 — Rôle inconnu → 403, aucune session

**Préparation** : forger un JWT valide mais `role = role-inexistant`.

**Action** : POST sur l'endpoint.

**Attendu** :

- Réponse **403**.
- **Aucune** session ouverte, **aucun** `users` créé.
- Channel `federated-auth` : `federated.login.role_unknown`.

#### Scénario 20.1-4 — Jeton invalide / forgé → 401, aucune session

Soumettre successivement (chacun doit renvoyer **401**, aucune session,
événement `federated.jwt.rejected` avec le `code` adéquat, **jamais** le JWT
brut dans les logs) :

1. JWT `alg:none` (header `{"alg":"none"}`, signature vide) → `signature_invalid`.
2. JWT signé `HS256` avec la **clé publique** comme secret (confusion d'algo)
   → `signature_invalid`.
3. JWT `exp` dépassé de > 60 s → `expired`.
4. JWT `nbf` dans le futur de > 60 s → `not_yet_valid`.
5. JWT `aud` = identifiant d'une **autre** instance → `aud_mismatch`
   (anti-rejeu inter-instance).
6. JWT `iss` inconnu → `iss_mismatch`.
7. JWT `kid` absent de la config → `signature_invalid`.
8. JWT auquel manque `sub`/`jti`/`role`/`exp`/`aud` → `missing_claim`.

#### Scénario 20.1-5 — Anti-rejeu `jti` à usage unique

**Action** : soumettre **deux fois** le **même** JWT valide (même `jti`).

**Attendu** :

- 1er POST → 302 (session ouverte).
- 2e POST (même jeton) → **401**, événement `federated.jwt.rejected`
  code `replayed`. **Aucune** seconde session.

#### Scénario 20.1-6 — Tolérance d'horloge ±60 s

**Action** : forger un JWT dont `exp` est dépassé de **~30 s** (dans le leeway).

**Attendu** : login **accepté** (302). (Au-delà de 60 s → rejet `expired`,
cf. 20.1-4.3.)

### Section 3 — Réconciliation du guard de session (régression D-5)

#### Scénario 20.1-7 — Session fédérée NON déconnectée à la requête suivante

**Action** : après un login 20.1-1 réussi, naviguer dans l'app avec le cookie :

```bash
curl -i https://<instance>/app/dashboard -b /tmp/fed_cookies.txt
```

**Attendu** :

- **Pas** de redirection vers `/authentication/login` (pas de 401/302 login).
- La page se charge selon le rôle `technicien` (Policies/Gates Spatie
  appliqués). La vérif LDAP est **sautée** pour la session fédérée.

#### Scénario 20.1-8 — Désactivation de l'identité externe → déconnexion effective

**Action** : pendant une session fédérée active, désactiver l'identité en DB :

```sql
UPDATE external_identities SET is_active = false WHERE external_sub = 'ext-qa-001';
```

puis refaire une requête authentifiée (`/app/dashboard` avec le cookie).

**Attendu** :

- L'utilisateur est **déconnecté** (redirection login / 401), événement
  `federated.session.deactivated`. Révocation effective côté SE5,
  indépendante de l'AD.

#### Scénario 20.1-9 — Utilisateur AD/LDAP : flux strictement inchangé (non-régression)

**Action** : se connecter normalement avec un compte **AD** (login/mot de passe
LDAP), naviguer, se déconnecter.

**Attendu** :

- Login, session et navigation AD **identiques** à avant Story 20.1
  (la branche fédérée du guard ne s'active **que** pour les sessions marquées
  fédérées). Aucun `federated.*` dans les logs pour un user AD.

#### Scénario 20.1-10 — Identité révoquée : pas de réactivation au fresh login (post-review #1)

**Contexte** : régression de révocation détectée en review — un nouveau JWT
valide réarmait silencieusement une identité désactivée/supprimée.

**Action** : après un 1er login réussi d'un externe, le révoquer en DB :

```sql
-- variante désactivation
UPDATE external_identities SET is_active = false WHERE external_sub = 'ext-qa-001';
-- variante soft-delete
UPDATE external_identities SET deleted_at = NOW() WHERE external_sub = 'ext-qa-001';
```

puis re-soumettre un **nouveau** JWT valide (jti différent) pour ce même `sub`
(`POST /auth/federated/callback`).

**Attendu** :

- Réponse **403**, aucune session ouverte, événement
  `federated.login.identity_revoked`.
- L'identité reste **désactivée / soft-deletée** : ni `is_active=true`, ni
  `restore()` silencieux. La réactivation n'est possible que par une action
  d'admin (story d'outillage admin des identités à venir, Epic 20).

#### Scénario 20.1-11 — `jti` non brûlé si le provisioning échoue (post-review M1)

**Contexte** : le `jti` est consommé **en dernier**, après le provisioning
réussi. Un échec amont ne doit pas bloquer un retry légitime du même jeton.

**Action** (test de robustesse, à provoquer en pré-prod) : forcer un échec de
provisioning (ex. contrainte DB temporaire) pendant un login, observer le rejet,
puis **rejouer le même JWT** (encore valide, non expiré).

**Attendu** :

- Le 1er essai échoue (5xx/erreur) **sans** marquer le `jti` consommé (table
  `federated_jwt_consumptions` ne contient pas le `jti` ; pas de clé cache
  `federated:jti:<jti>`).
- Le retry du **même** JWT aboutit normalement (302 + session) — il n'est PAS
  rejeté en `replayed`. En régime nominal (sans panne), un 2e POST du même
  jeton reste bien rejeté 401 (cf. 20.1-5).

---

## Story 20.2 — Cycle de vie & rétention RGPD de l'identité externe

**Date livraison** : 2026-06-02
**Migration à appliquer** :
`2026_06_02_120000_add_retention_columns_to_external_identities.php`
(colonnes `anonymized_at`, `deactivated_reason`, `deleted_reason` — additive).
**Nouveaux artefacts** :
- Service `App\Auth\Federated\ExternalIdentityLifecycleService` (extraction
  non-régressive de la logique d'upsert/sync inline 20.1 — D-2).
- Commande `php artisan federated:purge-identities` (`--dry-run` / `--force`).
- Bloc config `federated_auth.retention` + clés `.env`
  `FEDERATED_AUTH_RETENTION_*`.

### Base légale de rétention (RGPD — à valider DPO)

- **Finalité** : imputabilité des actions d'administration réalisées par un
  acteur externe fédéré (technicien flotte hors-AD).
- **Base légale** : intérêt légitime + obligation légale de traçabilité et de
  sécurité des SI (RGPD art. 6-1-c et 6-1-f).
- **Durée** : `pii_ttl_days` (défaut **365 j** après `last_login_at` — **Q-1, à
  confirmer Henri/juridique**).
- **Sort en fin de rétention** : **anonymisation**, JAMAIS d'effacement physique
  (D-1) — la PII (`name`/`email`/`login`) est vidée, `external_sub` réécrit en
  `anon:<sha256>` (D-5), la ligne survit (soft-deletée + `anonymized_at`) pour
  l'intégrité de l'audit (Story 20.4) et les FK `users`. Après anonymisation, ce
  n'est plus une donnée personnelle.
- **Interrupteur** : `anonymize_enabled` = **false par défaut** (D-8) tant que
  la durée n'est pas validée juridiquement → la purge est no-op safe.

### Les 4 états du cycle de vie

| État | `is_active` | `deleted_at` | `anonymized_at` | Login |
|---|---|---|---|---|
| Active | true | null | null | autorisé (si JWT valide) |
| Désactivée | false | null | null | 403 `identity_revoked` |
| Soft-deletée | (any) | non-null | null | 403 `identity_revoked` |
| Anonymisée | false | non-null | non-null | 403 `identity_anonymized` |

### Section 4 — Sync de profil à la reconnexion (D-3)

#### Scénario 20.2-1 — Claim présent écrase / claim absent préserve

**Action** : login 20.1-1 avec `name="Ancien"`, `email="a@x.fr"`. Puis
reconnexion (même `sub`, nouveau `jti`) avec `name="Nouveau"` et **sans** champ
`email` (ou `email=""`).

**Attendu** :

- `external_identities.name` = `"Nouveau"` (claim présent → écrase).
- `external_identities.email` = `"a@x.fr"` (claim absent/vide → **préservé**,
  pas d'effacement involontaire).
- Le **rôle** et `is_active` ne sont **pas** modifiés par la sync de profil
  (séparation identité/accès — vérifier `model_has_roles` inchangé).

### Section 5 — Désactivation & soft-delete tracés

#### Scénario 20.2-2 — Désactivation administrative (sans suppression)

**Action** : sur une identité active, appeler la transition `deactivate` (via
l'outillage admin des identités à venir, ou en DB pour le QA :
`UPDATE external_identities SET is_active=false, deactivated_reason='litige' …`).

**Attendu** :

- `is_active=false`, `deactivated_reason` renseigné, **PII intacte**,
  `deleted_at` NULL, `anonymized_at` NULL.
- Un fresh login pour ce `sub` → **403** (pas de réactivation — cf. 20.1-10).
- Event `federated.identity.deactivated` (channel `federated-auth`, **sans PII**).

#### Scénario 20.2-3 — Soft-delete tracé, résolvable `withTrashed()`

**Action** : soft-delete d'une identité (`softDeleteWithReason`).

**Attendu** :

- `deleted_at` non-null, `deleted_reason` renseigné, ligne **toujours
  résolvable** via `ExternalIdentity::withTrashed()` (audit 20.4).
- Login refusé **403**. Event `federated.identity.soft_deleted` (sans PII).

### Section 6 — Purge RGPD planifiée (`federated:purge-identities`)

#### Scénario 20.2-4 — Dry-run liste les candidats sans rien modifier

**Pré-requis** : `FEDERATED_AUTH_RETENTION_ANONYMIZE_ENABLED=true`,
`FEDERATED_AUTH_RETENTION_PII_TTL_DAYS=365`. Une identité dont
`last_login_at` > 365 j.

**Action** : `php artisan federated:purge-identities --dry-run`

**Attendu** :

- Sortie `[DRY-RUN] Candidats à anonymiser : N`, tableau (ID, sub **sha256**,
  `last_login_at`, état) — **jamais** de PII (ni `name`/`email`/`login`, ni sub
  clair).
- **Aucune** modification DB (`anonymized_at` toujours NULL, PII intacte).

#### Scénario 20.2-5 — Anonymisation effective (jamais hard-delete)

**Action** : `php artisan federated:purge-identities` (sans `--dry-run`).

**Attendu** :

- L'identité expirée est anonymisée : `name`/`email`/`login` **vidés**,
  `external_sub` = `anon:<sha256(sub)>`, `anonymized_at` posé,
  `is_active=false`, `deleted_at` posé.
- La ligne **survit** : `SELECT … FROM external_identities` (sans
  `withTrashed`) ne la retourne plus, mais `withTrashed()` la retrouve.
  **Jamais** de `forceDelete`.
- Le(s) `User source='federated'` lié(s) : **toujours présents** (FK intacte),
  mais `is_active=false` (accès coupé — AC12).
- Une identité **active récemment** (< TTL) est **conservée** (non anonymisée).
- Event `federated.identity.anonymized` (sans PII).

#### Scénario 20.2-6 — No-op safe si toggle OFF ou TTL ≤ 0

**Action** :

1. `FEDERATED_AUTH_RETENTION_ANONYMIZE_ENABLED=false` →
   `php artisan federated:purge-identities` → sortie « Rétention désactivée »,
   exit **0**, **aucune** anonymisation.
2. `pii_ttl_days=0` sans `--force` → sortie « pii_ttl_days non configuré »,
   exit **0**, aucune anonymisation. (Avec `--force` → purge consciente.)

#### Scénario 20.2-6bis — Audit DPO pré-activation : `--dry-run` énumère même toggle OFF (P-1)

**Contexte** : P-1 (review 20.2) — un `--dry-run` est sans effet de bord ; le DPO
doit pouvoir visualiser l'impact d'une future purge **avant** d'activer la
rétention (`anonymize_enabled` reste `false` par défaut tant que la base légale
n'est pas validée — D-8).

**Pré-requis** : `FEDERATED_AUTH_RETENTION_ANONYMIZE_ENABLED=false`,
`FEDERATED_AUTH_RETENTION_PII_TTL_DAYS=365`. Une identité dont `last_login_at`
> 365 j.

**Action** : `php artisan federated:purge-identities --dry-run`

**Attendu** :

- Avertissement « **SIMULATION** uniquement » + tableau `[DRY-RUN] Candidats à
  anonymiser : N` (ID, sub sha256, `last_login_at`, état) — jamais de PII.
- **Aucune** modification DB. Hors `--dry-run`, toggle OFF reste no-op (20.2-6).

#### Scénario 20.2-7 — Reconnexion d'une identité anonymisée → 403 (anti-résurrection)

**Contexte** : D-4 — une identité dont la PII a été purgée ne doit pas être
« ressuscitée » par une simple reconnexion (contournement de la purge RGPD).

**Action** : après anonymisation (20.2-5), re-soumettre un **nouveau** JWT valide
pour le **même `sub` clair** d'origine (`POST /auth/federated/callback`).

**Attendu** :

- Réponse **403**, event `federated.login.identity_anonymized`, **aucune**
  session, **aucune** identité « fraîche » recréée pour ce sub clair (la forme
  `anon:<sha256>` est résolue et bloque la recréation).
- La réactivation reste une décision **admin** explicite (story d'outillage admin des identités à venir, Epic 20).

### Section 7 — Non-régression du refactor (D-2 / AC15)

#### Scénario 20.2-8 — La suite 20.1 reste verte après extraction du service

**Action** : rejouer intégralement les scénarios **20.1-1 → 20.1-11** (le
controller délègue désormais à `ExternalIdentityLifecycleService` ; le
comportement observable doit être **identique**).

**Attendu** : aucun changement de comportement (login, reconnexion, révocation
403, guard D-5, anti-rejeu jti). Filet de non-régression du refactor.

---

## Story 20.3 — Résolution directe du rôle externe

**Date livraison** : 2026-06-03
**Migration** : aucune (code + config nettoyée — aucune nouvelle table).

> **⚠️ PIVOT DE CONCEPTION (Henri, 2026-06-03)** — La version « mapping »
> initiale (table `role_map` + validator + commande `federated:roles` + onglet
> read-only) est **abandonnée et supprimée**. Le nom de rôle asséré par l'IdP
> EST le contrat ; SE5 le résout par **lookup direct** parmi ses rôles
> EXISTANTS. Toute référence à `role_map`/`federated:roles`/onglet « Mapping
> rôles fédérés » dans une procédure antérieure est **caduque**.

**Modèle retenu** :

> L'IdP asserte un nom de rôle (claim `role`, ex. `technicien`). SE5 cherche
> DIRECTEMENT un rôle Spatie de ce nom parmi ses rôles EXISTANTS (table `roles`,
> guard `web`), après normalisation casse/espaces (`trim` + `strtolower`).
> Existe → appliqué (`syncRoles`). Absent → **403**, aucune session, **AUCUNE
> création de rôle à la volée**.

**Artefacts modifiés / supprimés** :
- `FederatedRoleMapper::resolve(string): ?string` — lookup direct insensible à
  la casse dans la table `roles` (guard `web`) ; renvoie le **nom canonique** du
  rôle existant ou `null`. Plus aucune lecture de config.
- `FederatedLoginController` — `resolve()===null` → 403 `role_unknown`, aucune
  session ; **`firstOrCreate` retiré** (jamais de rôle fantôme) ; `syncRoles`
  d'un rôle EXISTANT uniquement.
- **SUPPRIMÉS** : bloc config `federated_auth.role_map`,
  `App\Auth\Federated\FederatedRoleMapValidator`, commande
  `php artisan federated:roles`, onglet read-only « Mapping rôles fédérés » de
  `/app/rights-management`.

### Modèle de sécurité ouvert assumé (D-5)

Pas de liste blanche locale : **tout rôle existant** dans l'instance est
demandable. C'est cohérent — l'émetteur externe de confiance est l'autorité qui
crée/gère les rôles et décide ce qu'il asserte. `super-admin` n'est PAS bloqué
(s'il existe en base, il est demandable comme tout autre rôle). La défense
repose sur : JWT signé RS256 + anti-rejeu `jti` (20.1) + le rôle doit **exister**
en base + invariant « inconnu → 403 ».

> Conséquence opérationnelle : pour qu'un rôle externe soit utilisable, il faut
> qu'un rôle Spatie de ce nom **existe** dans l'instance — soit seedé à
> l'installation (`SambaRole`), soit créé par l'IdP/l'administrateur. Aucune
> entrée de config à éditer.

### Section 8 — Résolution directe du rôle

#### Scénario 20.3-1 — Rôle existant → accès selon le rôle

**Préparation** : le rôle Spatie `technicien` **existe** dans l'instance
(seedé / présent en table `roles`).

**Action** : login fédéré (20.1-1) avec `role = technicien`.

**Attendu** :

- Réponse **302**, session ouverte.
- Le user `source=federated` porte le rôle Spatie `technicien`
  (`computer.view`/`computer.control`/`wpkg.assign`) — Policies/Gates Epic 7.
- Channel `federated-auth` : `federated.login.success` avec le `role` appliqué.
- Variante : un autre rôle existant (ex. `referent-numerique`) asséré → le user
  porte ce rôle (inclut `computer.install`).

#### Scénario 20.3-2 — Insensibilité casse/espaces

**Action** : login avec `role = " TECHNICIEN "` (casse mixte + espaces de bord),
le rôle `technicien` existant en base.

**Attendu** : login **accepté** (302), rôle `technicien` appliqué (résolution
insensible casse/espaces). Aucun wildcard : `role = "tech"` ou un rôle absent
reste **403** (cf. 20.3-3).

#### Scénario 20.3-3 — Rôle inexistant → 403, aucune création à la volée

**Action** : login avec `role = role-inexistant` (aucun rôle Spatie de ce nom en
base).

**Attendu** :

- **403**, aucune session ouverte.
- **AUCUN rôle créé** : `SELECT count(*) FROM roles` inchangé, pas de ligne
  `role-inexistant`.
- Événement `federated.login.role_unknown` (channel `federated-auth`,
  `sub`/`iss`/`role`, **sans PII**).
- Aucun fallback/capture-all.

#### Scénario 20.3-4 — `super-admin` existant → appliqué (modèle ouvert, D-5)

**Préparation** : le rôle `super-admin` **existe** en base.

**Action** : login avec `role = super-admin`.

**Attendu** : login **accepté** (302), rôle Spatie `super-admin` appliqué (toutes
les permissions). Aucun garde-fou spécifique : super-admin n'est pas bloqué.
(Si `super-admin` n'existe PAS en base → 403 `role_unknown` comme tout rôle
absent.)

#### Scénario 20.3-5 — Portée = instance, pas de scope par classe (AC8)

**Action** : un externe `technicien` authentifié navigue dans l'app.

**Attendu** : il est admin de **l'instance** selon son rôle (Policies/Gates
Spatie Epic 7), **sans** scope par classe (contrairement à prof/eleve-admin,
Story 7.2).

### Section 9 — Non-régression 20.1 / 20.2

#### Scénario 20.3-6 — Suites 20.1 + 20.2 restent vertes

**Action** : rejouer intégralement **20.1-1 → 20.1-11** et **20.2-1 → 20.2-8**.

**Attendu** : aucun changement de comportement (login nominal, reconnexion,
révocation 403, guard D-5, anti-rejeu jti, cycle de vie, rétention RGPD). La
résolution directe est **iso-comportement** pour les cas valides 20.1/20.2 (le
rôle attendu existe en base) ; le seul durcissement est le retrait de la
création à la volée (un rôle absent → 403 au lieu d'être créé).

---

## Story 20.4 — Audit dénormalisé des actions externes

> **Append-only.** Ne modifie aucun scénario 20.1/20.2/20.3 ci-dessus. Couvre le
> **journal d'audit dénormalisé** des actions d'administration réalisées par un
> acteur fédéré : `actor_login` + `actor_external_sub` + `actor_name` +
> `actor_role` **copiés** dans chaque ligne `external_action_audit_logs` au
> moment de l'action (jamais une simple FK). Raison d'être : le journal reste
> **lisible et attribuable** même après soft-delete ET anonymisation de
> l'`ExternalIdentity` (Story 20.2).

### Modèle d'audit (rappel implémentation)

- **Table** `external_action_audit_logs` (Eloquent `App\Models\ExternalActionAuditLog`,
  calqué sur `QuotaAuditLog` : `record()` statique, `timestamps=false` +
  `occurred_at` manuel, scopes `scopeFederated`/`scopeForActor`, indexée
  `actor_login`/`actor_external_sub`/`source`/`occurred_at`).
- **Capture** = middleware `App\Http\Middleware\Auth\AuditExternalAction`,
  appliqué **après** `sambaedu.auth` (guard de session) sur les groupes de routes
  `app` et `admin`. N'écrit que si `FederatedSession::isFederated()` (discrimine
  externe vs AD — AC2). Périmètre (D-2/Q-1) : **mutations** (POST/PUT/PATCH/DELETE)
  **ET** `GET` sur route sensible (allowlist `federated_auth.audit.sensitive_get_routes` :
  `app.users`, `app.user.show`, `app.users.groups.edit`, `app.users.*`).
- **Best-effort / fail-soft** (D-3) : l'écriture est dans un `try/catch` ; un
  échec ne dégrade **jamais** la requête métier et est tracé sans PII
  (`federated.audit.write_failed` — **classe d'exception uniquement**, jamais le
  message DB qui ré-imprimerait les valeurs liées).

### Section 10 — Audit dénormalisé des actions externes

#### Scénario 20.4-1 — Action mutante fédérée → ligne dénormalisée

**Pré-requis** : session fédérée active (technicien externe loggué), rôle Spatie
`technicien` actif.

**Action** : POST sur une route applicative mutante (ex. mise à jour de quota
`/app/users/{login}/quota`).

**Attendu** : **une** ligne `external_action_audit_logs` écrite, contenant
`actor_login=ext:<sub>`, `actor_external_sub=<sub clair>`, `actor_name`,
`actor_role=technicien`, `source=federated`, `http_method=POST`, `status_code`,
`occurred_at`, + FK best-effort `external_identity_id`/`user_id`. Toutes les
valeurs d'identité sont **copiées** (pas une jointure).

#### Scénario 20.4-2 — Lecture du journal après anonymisation (raison d'être)

**Action** : après l'action 20.4-1, déclencher l'anonymisation de l'identité
(`federated:purge-identities` ou `anonymize()`) → PII vidée, `external_sub` →
`anon:<hmac>`, identité soft-deletée.

**Attendu** : la ligne d'audit reste **intacte et lisible** : `actor_login`,
`actor_external_sub` (sub clair d'origine), `actor_name`, `actor_role` inchangés.
La lisibilité ne dépend d'**aucune jointure vivante** (AC3).

#### Scénario 20.4-3 — Action AD locale → non journalisée (AC2)

**Action** : un administrateur **AD/LDAP normal** (session non fédérée) réalise
une mutation (ex. POST quota).

**Attendu** : **aucune** ligne `external_action_audit_logs`. Le marqueur
`FederatedSession` est absent → le middleware ne touche rien. Flux LDAP
strictement inchangé.

#### Scénario 20.4-4 — Fail-soft : audit KO ≠ requête KO (AC5)

**Action** : provoquer un échec d'écriture d'audit (panne DB / contrainte) lors
d'une action fédérée mutante.

**Attendu** : la requête métier **réussit quand même** (réponse non dégradée),
et l'échec est tracé dans `federated-auth` (`action_type=federated.audit.write_failed`)
**sans PII** (classe d'exception, pas le message DB).

#### Scénario 20.4-5 — GET non sensible → non journalisé (AC4)

**Action** : un externe loggué fait un `GET` sur une route **non sensible**
(ex. `/app/dashboard`).

**Attendu** : **aucune** ligne d'audit (bruit/volumétrie évités).

#### Scénario 20.4-6 — GET sensible (PII élève) → journalisé (AC4)

**Action** : un externe loggué fait un `GET` sur une route **sensible** de
l'allowlist (ex. `/app/users/{login}` → `app.user.show`).

**Attendu** : **une** ligne d'audit dénormalisée, `http_method=GET`.

#### Scénario 20.4-7 — Cohérence du rôle dénormalisé (AC6)

**Action** : changer le rôle Spatie actif de l'externe (ex. `technicien` →
`referent-numerique`), puis réaliser une action.

**Attendu** : `actor_role` de la nouvelle ligne reflète **exactement** le rôle
Spatie actif (source de vérité `getRoleNames()`, cohérence 20.3).

### Section 11 — Non-régression 20.1 / 20.2 / 20.3

#### Scénario 20.4-8 — Suites 20.1 + 20.2 + 20.3 restent vertes

**Action** : rejouer **20.1-1 → 20.1-11**, **20.2-1 → 20.2-8**, **20.3-1 →
20.3-6**.

**Attendu** : aucun changement de comportement. Le middleware d'audit s'exécute
**après** le guard D-5 et ne modifie ni le login, ni la réconciliation du guard,
ni le flux AD. (Host : `FederatedLoginEndpointTest` + `Unit/Auth/Federated/*` +
`AuthGuardInterfaceTest` + suites Console purge = verts.)

### Limites connues 20.4

> Limites **assumées** du périmètre 20.4, à surveiller si les besoins
> d'imputabilité évoluent. Ne remettent pas en cause les scénarios ci-dessus.

- **(P-1 — RÉSOLU post-review)** ~~Erreur 500 non catchée → action non auditée.~~
  Le middleware est désormais **terminable** : l'audit est écrit dans
  `terminate(Request, Response)` (après l'envoi de la réponse). Quand la requête
  métier lève une exception non catchée, le handler d'exceptions la convertit en
  **réponse 500 AVANT** que `terminate()` ne soit appelé → l'action en erreur
  **est auditée** (`status_code=500`). Couvert par le test
  `mutating_federated_action_returning_500_is_audited`. Ce n'est donc plus une
  limite.
- **Note (perf)** : l'audit en `terminate()` s'exécute **après** l'envoi de la
  réponse au client → **zéro latence perçue** sur le TTFB (l'INSERT sort de la
  pile de réponse — résout aussi P-2).
- **(M-2) Retrait de rôle en cours de session → `actor_role=null`.** Si un admin
  retire le rôle d'un externe pendant sa session (`syncRoles([])`), une action
  ultérieure est auditée avec `actor_role=null` (lecture `getRoleNames()->first()`
  à l'instant de l'action). L'imputabilité **login / sub / nom demeure** intacte
  dans la ligne dénormalisée ; seul le **rôle** est perdu pour cette action.
- **(LIMITE MAJEURE — canal Livewire NON audité, suivi Story 20.6).** Le projet
  est **Livewire-first** : la majorité des **mutations admin natives** (CRUD
  utilisateur, reset mot de passe, attribution de droits via `/rights-management`,
  etc.) sont des **méthodes de composants Livewire** qui POSTent sur l'endpoint
  unique `livewire/update`, **hors** des groupes de routes `app`/`admin` où le
  middleware `federated.audit` est branché. **Conséquence : ces mutations ne sont
  PAS journalisées par 20.4.** Ce que 20.4 couvre : (a) l'**accès aux écrans à PII
  élève** via les `GET` sensibles (allowlist — objectif central de l'epic/Q-1), et
  (b) les **mutations passant par une route HTTP classique** (quota, mass-action
  parc, import CSV, change-password). `livewire/update` n'est **volontairement
  pas** branché sur le middleware : un audit HTTP de cet endpoint produirait des
  lignes `POST /livewire/update` **sans libellé d'action exploitable** (chaque
  interaction Livewire, même non mutante, est un POST) → bruit > signal. Un audit
  **signifiant** des actions Livewire nécessite une instrumentation au niveau du
  **cycle de vie Livewire** (composant + méthode + arguments) → objet de la
  **Story 20.6 « Audit des actions Livewire fédérées »** (backlog). Le test
  d'architecture `FederatedAuditCoverageTest` trace explicitement `livewire/update`
  (et `api/v1/health/detailed`, lecture seule) en allowlist d'exceptions : toute
  **nouvelle** route `sambaedu.auth` non auditée fera échouer ce test.

---

## Post-correctifs & non-régressions

- **20.1-7 / 20.1-9** couvrent la **régression critique D-5** : le guard de
  session `SambaEduAuthGuard` revérifie le LDAP à chaque requête ; sans le
  branchement, un externe (absent du LDAP) serait éjecté à la requête
  suivante. Ces deux scénarios garantissent (a) que l'externe survit et
  (b) que le flux AD reste intact.
- **20.1-4 / 20.1-5** couvrent le durcissement IR (H1/M4) : surface d'attaque
  JWT (alg pinné, rejet `alg:none`/confusion d'algo, `aud`/`iss`/`exp`/`nbf`,
  anti-rejeu `jti`). À rejouer intégralement après toute modif du verifier.

| Incident (review 20.1) | Sévérité | Couvert par |
|---|---|---|
| #1 — révocation `is_active`/soft-delete réarmée par un fresh login | 🟠 | Scénario 20.1-10 |
| M1 — `jti` brûlé avant provisioning (retry légitime bloqué) | 🟠 | Scénario 20.1-11 |
| #3 — `aud` array sans match → mauvais code d'erreur | 🟡 | Tests unit (`aud_array_without_match…`) |
| #4 — fallback Bearer (déviation D-3) | 🟠 | Retiré (POST strict) ; vérifié par 20.1-1 |
| #6 — `login` loggé (AC16) | 🟡 | Vérif « aucun PII » de la checklist |
| 20.2 — résurrection d'une identité anonymisée par reconnexion (D-4) | 🟠 | Scénario 20.2-7 + Unit/Feature (`anonymized_identity_is_refused…`) |
| 20.2 — `external_sub` réécrit (D-5) casse le lookup clair → recréation/collision | 🟠 | Résolu : reconcile résout aussi `anon:<sha256>` ; couvert par 20.2-7 |
| 20.2 — anonymisation non idempotente (`anon:anon:…`) | 🟡 | Unit (`anonymize_is_idempotent`) |
| 20.2 — purge silencieuse sans base légale validée (D-8) | 🟠 | Toggle OFF par défaut ; Scénario 20.2-6 + KernelSchedule `->when()` |
| 20.2 — PII dans les logs de purge (AC16) | 🟠 | id + sub sha256 uniquement ; Unit (`logs_carry_no_pii…`) |
| 20.2 P-2 — `anonymize()` non atomique → Users actifs orphelins d'une identité anonymisée (état irréparable par rejeu) | 🟠 | `DB::transaction()` autour du corps d'`anonymize()`/`softDeleteWithReason()` ; Unit existants (`anonymize_deactivates_linked_user…`) |
| 20.2 P-1 — `--dry-run` impossible tant que `anonymize_enabled=false` (pas d'audit DPO pré-activation) | 🟠 | dry-run énumère malgré toggle OFF (simulation) ; Feature (`dry_run_lists_candidates_even_when_anonymize_disabled`) |
| 20.2 P-8 — motif > 255 → `QueryException` MySQL non vue en SQLite | 🟡 | troncature `mb_substr(…,0,255)` ; Unit (`deactivate_truncates_overlong_reason…`) |
| 20.2 P-4 — `sha256(sub)` nu → sub faible entropie ré-identifiable (anon. pseudonyme, pas anonyme RGPD) | 🟡 | HMAC-SHA256 clé dédiée `retention.hash_key` ; Unit (`hash_sub_is_salted_not_raw_sha256`) |
| 20.2 M-2 / P-9 — guard 20.1 logge `external_sub` en clair (+ double-hash latent si anonymisé) | 🟡 | `SambaEduAuthGuard::subForLog()` hashe + gère préfixe `anon:` ; couvert par `AuthGuardInterfaceTest` + suite federated |
| 20.3 — rôle asséré inexistant créerait un rôle « fantôme » à la volée | 🟠 | Aucune création : `resolve()` ne renvoie qu'un rôle EXISTANT, sinon `null` → 403 `role_unknown` ; `firstOrCreate` retiré du controller. Scénario 20.3-3 + Feature (`role_absent_from_db_returns_403_and_creates_no_role`, `unknown_role_returns_403_and_opens_no_session`) + Unit (`role_absent_from_db_returns_null`) |
| 20.3 — IdP asserte une casse/espaces différents → faux 403 | 🟡 | Normalisation `trim`+`strtolower` (D-3) ; Scénario 20.3-2 + Unit mapper (`resolution_is_case_insensitive`, `resolution_trims_surrounding_whitespace`) |
| 20.3 — un nom asséré arbitraire (`*`/`default`) capture-all (régression invariant 20.1) | 🟠 | Aucun wildcard/fallback : seule l'existence en base résout ; Unit (`no_wildcard_or_default_fallback`) |
| 20.3 — `super-admin` non bloqué (modèle ouvert assumé, D-5) | 🟡 | Comportement voulu : super-admin existant → appliqué ; Scénario 20.3-4 + Unit (`super_admin_resolves_when_it_exists`) + Feature (`super_admin_is_applied_when_it_exists`) |
| 20.4 — log par FK seule deviendrait illisible après anonymisation (D-5, raison d'être) | 🟠 | Dénormalisation : 4 colonnes `actor_*` copiées au moment de l'action ; Scénario 20.4-2 + Feature (`log_remains_readable_after_anonymisation_of_identity`, `…after_soft_delete…`) |
| 20.4 — action AD locale journalisée par erreur (fuite de périmètre, AC2) | 🟠 | Garde `FederatedSession::isFederated()` ; Scénario 20.4-3 + Feature (`non_federated_session_writes_nothing`) |
| 20.4 — `$e->getMessage()` DB ré-imprime le SQL avec valeurs liées (login/nom = PII dans Monolog, AC7) | 🟠 | On logge la **classe d'exception** uniquement, jamais le message ; Scénario 20.4-4 + Feature (`audit_write_failure_does_not_break_request_and_is_traced` assert no PII) |
| 20.4 — audit bloquant casserait l'action si DB KO (disponibilité, D-3) | 🟠 | Best-effort `try/catch` ; requête réussit malgré audit KO ; Feature (`audit_write_failure_does_not_break_request_and_is_traced`) |
| 20.4 — volumétrie des GET non sensibles (bruit) | 🟡 | Allowlist `sensitive_get_routes` : GET non listé → rien ; Scénarios 20.4-5/20.4-6 + Feature (`non_sensitive_get_…writes_nothing`, `sensitive_get_…writes_log`) |
| 20.4 — `actor_role` divergent du rôle Spatie actif (AC6) | 🟡 | Lecture `getRoleNames()->first()` à l'instant de l'action ; Scénario 20.4-7 + Feature (`denormalised_role_reflects_active_spatie_role`) |

## Checklist rapide

- [ ] 20.1-1 Login valide → 302 + identité + user `federated` + rôle Spatie
- [ ] 20.1-2 Reconnexion réutilise l'identité (clé `external_sub`)
- [ ] 20.1-3 Rôle inconnu → 403, aucune session
- [ ] 20.1-4 Jetons forgés (alg:none, HS256, exp, nbf, aud, iss, kid, claim) → 401
- [ ] 20.1-5 Rejeu du même `jti` → 401
- [ ] 20.1-6 Skew ±60 s accepté
- [ ] 20.1-7 Session fédérée non déconnectée (D-5)
- [ ] 20.1-8 Désactivation identité → déconnexion effective
- [ ] 20.1-9 Flux AD/LDAP strictement inchangé (non-régression)
- [ ] 20.1-10 Identité révoquée → 403 au fresh login, pas de réactivation (post-review #1)
- [ ] 20.1-11 `jti` non brûlé si provisioning échoue → retry possible (post-review M1)
- [ ] 20.2-1 Sync profil : claim présent écrase / absent préserve ; rôle & is_active jamais touchés
- [ ] 20.2-2 Désactivation tracée (PII intacte), fresh login → 403
- [ ] 20.2-3 Soft-delete tracé, résolvable `withTrashed()`, login → 403
- [ ] 20.2-4 Purge `--dry-run` liste sans rien modifier (sub hashé, pas de PII)
- [ ] 20.2-5 Anonymisation effective : PII vidée, `anon:<sha256>`, jamais hard-delete, User lié désactivé (FK intacte)
- [ ] 20.2-6 No-op safe si `anonymize_enabled=false` ou TTL ≤ 0 sans `--force` (exit 0)
- [ ] 20.2-7 Reconnexion d'une identité anonymisée → 403 `identity_anonymized`, pas de recréation
- [ ] 20.2-8 Suite 20.1 inchangée après extraction du service (non-régression D-2)
- [ ] 20.3-1 Rôle existant → accès selon le rôle (lookup direct)
- [ ] 20.3-2 Insensibilité casse/espaces, sans wildcard
- [ ] 20.3-3 Rôle inexistant → 403 `role_unknown`, AUCUNE création à la volée (Role::count() inchangé)
- [ ] 20.3-4 `super-admin` existant → appliqué (modèle ouvert D-5, pas de blocage)
- [ ] 20.3-5 Portée = instance, pas de scope par classe (AC8)
- [ ] 20.3-6 Suites 20.1 + 20.2 restent vertes (non-régression stricte)
- [ ] 20.4-1 Action mutante fédérée → ligne dénormalisée (login+sub+nom+rôle+method+status copiés)
- [ ] 20.4-2 Journal lisible après soft-delete ET anonymisation de l'identité (raison d'être)
- [ ] 20.4-3 Action AD locale (non fédérée) → aucune ligne d'audit (AC2)
- [ ] 20.4-4 Audit KO (DB) → requête métier OK + trace `federated.audit.write_failed` sans PII
- [ ] 20.4-5 GET non sensible fédéré → aucune ligne
- [ ] 20.4-6 GET sensible (PII élève, allowlist) fédéré → une ligne `http_method=GET`
- [ ] 20.4-7 `actor_role` = rôle Spatie actif (cohérence 20.3)
- [ ] 20.4-8 Suites 20.1 + 20.2 + 20.3 restent vertes (non-régression stricte)
- [ ] Aucun JWT brut / clé / PII dans le channel `federated-auth` (y c. logs de purge **et** audit KO)

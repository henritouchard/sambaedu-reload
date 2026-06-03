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
- [ ] Aucun JWT brut / clé / PII dans le channel `federated-auth` (y c. logs de purge)

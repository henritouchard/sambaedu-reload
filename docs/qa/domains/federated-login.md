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
  d'admin (Story 20.3).

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
- [ ] Aucun JWT brut / clé / PII dans le channel `federated-auth`

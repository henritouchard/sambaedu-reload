# Contrat d'intégration — Fédération d'un utilisateur externe vers une instance SambaEdu (SE5)

> **Public visé** : développeur de l'IdP externe (côté irundoo / controlHub) qui veut fédérer un technicien de flotte vers une instance SE5.
> **Statut** : contrat **réellement implémenté** par les Stories 20.1 → 20.4 (Epic 20). Dérivé du **code livré**, pas de l'épure de l'epic (voir §10 « Écarts epic ↔ code »).
> **Story source** : 20.5 — « Contrat d'intégration controlHub (doc) ».
> **Date** : 2026-06-03.

---

## 0. Comment lire ce document

Ce document décrit le **contrat de fédération** côté SE5 : ce qu'un IdP externe de confiance doit produire (un JWT signé + un POST) pour qu'une instance SE5 ouvre une session pour un acteur humain externe (technicien de flotte hors annuaire AD local).

Deux principes structurent tout le reste :

1. **SE5 reste *domain-neutral*.** Le code SE5 ne porte **aucune** notion de « central », « controlHub » ou « irundoo ». L'émetteur de confiance est une **string opaque** (`expected_iss`) configurée par instance. controlHub n'est qu'**une** instance possible d'IdP externe de confiance. Ce document est un *guide d'intégration pour* controlHub — il **ne demande jamais** d'introduire un couplage « controlHub » dans le code SE5.

2. **Le contrat transporte un *nom de rôle*, jamais des permissions.** controlHub asserte l'**intention** (« ce technicien doit avoir le rôle `X` »). SE5 traduit ce nom en permissions via son propre framework Spatie (Epic 7). controlHub n'a aucune connaissance du catalogue de permissions SE5.

Un corollaire fondateur (Story 20.2) : **identité persistante ≠ accès permanent.** L'identité externe (le « qui ») est durable et imputable ; l'accès est ré-évalué à **chaque** connexion par le JWT de l'IdP + l'état `is_active` côté SE5.

---

## 1. Vue d'ensemble du flux

```
┌────────────┐   1. forge JWT RS256 (claims ci-dessous)         ┌──────────────────────┐
│  IdP        │   2. rend une page HTML avec un <form> auto-     │  Instance SE5         │
│ (controlHub)│      soumis en POST vers l'instance cible        │  (SambaEdu-reload)    │
│             │ ───────────────────────────────────────────────▶│                       │
│             │      POST /auth/federated/callback               │  FederatedJwtVerifier │
│             │      body: token=<jwt>                           │  → vérif RS256+claims │
│             │                                                  │  → résolution rôle     │
│             │                                                  │  → upsert identité     │
│             │                                                  │  → Auth::login()       │
│             │◀─────────────────────────────────────────────── │  302 → /app/dashboard  │
└────────────┘   3. session SE5 standard ouverte                └──────────────────────┘
```

Étapes côté SE5 (`FederatedLoginController::callback`) :

1. **Extraction** du JWT depuis le champ POST `token` (sinon **400**).
2. **Vérification** du JWT : signature RS256, `iss`/`aud`/`tier`, `exp`/`nbf`/`iat`, présence des claims requis (sinon **401**).
3. **Résolution du rôle** : le nom asséré (`role`) doit correspondre à un rôle **existant** de l'instance (sinon **403**, aucune session).
4. **Upsert** de l'`ExternalIdentity` + provisioning du `User`, avec gardes de cycle de vie (révocation / anonymisation). Étapes 4 et 5 sont **dans une même transaction** (`DB::transaction`).
5. **Consommation du `jti`** à usage unique, **en dernier** (après le provisioning réussi). Sinon **401** si rejeu — et comme c'est dans la transaction, un rejeu **rollbacke le provisioning** : le `jti` n'est pas « brûlé » et un retry légitime du même jeton (encore valide) reste possible.
6. **`Auth::login()`** + marquage de la session « fédérée » + redirection vers `app.dashboard` (ou l'URL `intended` si une était mémorisée).

> Le rendu du formulaire HTML auto-soumis (étape 2 du diagramme) est **entièrement côté controlHub**. SE5 ne fournit pas de page d'amorçage.

---

## 2. Contrat JWT

Le JWT est signé **RS256** (asymétrique) : controlHub signe avec sa clé **privée**, SE5 vérifie avec la clé **publique** correspondante. SE5 ne détient **jamais** de clé privée et ne signe **jamais** de JWT fédéré.

> Source : `app/Auth/Federated/Jwt/FederatedJwtVerifier.php`, `FederatedUserClaims.php`.

### 2.1 Header JWT

| Champ | Valeur | Rôle |
|-------|--------|------|
| `alg` | `RS256` | **Imposé.** La key-map SE5 ne contient que des `Key($pem, 'RS256')` → `alg:none` et tout algo symétrique (confusion HS256/clé-publique) sont rejetés **avant** toute vérification cryptographique. SE5 ne déduit jamais l'algo du header. |
| `kid` | identifiant de clé | Utilisé par la lib (`firebase/php-jwt`) pour **sélectionner** la clé publique dans la map `kid → clé`. Un `kid` absent de la config SE5 → `signature_invalid`. |

### 2.2 Claims du payload

Tous les claims ci-dessous marqués **requis** doivent être présents et non vides, sinon le JWT est rejeté (`missing_claim`, **401**).

| Claim | Requis | Type | Rôle / contrainte | Comportement si absent / non conforme |
|-------|:------:|------|-------------------|----------------------------------------|
| `sub` | ✅ | string | **Identifiant externe stable** du technicien. Clé primaire de l'`ExternalIdentity` côté SE5 (réutilisée à chaque reconnexion). Doit être **stable dans le temps** et **opaque** (pas un email réassignable). | `missing_claim` → 401 |
| `login` | ✅ | string | Login d'affichage. SE5 génère en interne un login local `ext:<sub>` (jamais en collision avec l'AD) ; ce claim alimente le profil. | `missing_claim` → 401 |
| `name` | ⬜ | string | Nom d'affichage. Synchronisé à chaque login : **présent → écrase**, **vide/absent → préserve** la valeur existante. | toléré vide |
| `email` | ⬜ | string | Email d'affichage. Même règle de sync que `name`. | toléré vide |
| `role` | ✅ | string | **Le contrat.** Nom du rôle SE5 demandé (l'intention, jamais des permissions). Doit correspondre à un rôle **existant** dans l'instance cible (voir §6). | `missing_claim` → 401 ; ou si inconnu en base → `role_unknown` → 403 |
| `iss` | ✅ | string | Émetteur. Comparé en `hash_equals` à `config('federated_auth.expected_iss')`. String **opaque** (domain-neutral). | `iss_mismatch` → 401 |
| `aud` | ✅ | string ou array | **Identifiant de l'instance SE5 cible** (= `expected_aud`, fallback `sambaedu.se4fs_name`). Lie le jeton à **cette** instance → un JWT forgé pour le collège A ne passe pas sur le collège B (anti-rejeu inter-instance). Supporte string OU array (RFC 7519). | absent → `missing_claim` (401) ; présent mais ≠ instance → `aud_mismatch` (401) |
| `tier` | ✅ | string | Défense en profondeur. Doit valoir `federated-user` (= `expected_tier`). Empêche un JWT d'un autre tier (p. ex. « workstation ») d'ouvrir une session humaine. | `wrong_tier` → 401 |
| `exp` | ✅ | int (epoch) | Expiration. Validée par la lib avec `leeway` ±60 s. **TTL court recommandé** (le jeton est à usage unique). | absent (=0) → `missing_claim` (401) ; dépassé → `expired` (401) |
| `nbf` | ⬜ | int (epoch) | Not-before. Validé par la lib (`leeway` ±60 s). | si futur → `not_yet_valid` (401) |
| `iat` | ⬜ | int (epoch) | Issued-at. Validé par la lib (`leeway` ±60 s). | — |
| `jti` | ✅ | string | Identifiant unique du jeton. **Anti-rejeu à usage unique** : un `jti` déjà consommé est refusé. Doit être unique par jeton émis. | `missing_claim` (401) ; rejeu → 401 |
| `kid` | ✅ | string | **Exigé aussi dans le payload**, en plus du header. SE5 valide la présence du claim `kid` parmi les claims requis. → Mettre la **même** valeur dans le header (sélection de clé) **et** dans le payload (claim requis). | `missing_claim` → 401 |

### 2.3 Exemple de payload (valeurs fictives, domain-neutral)

```json
{
  "iss":   "https://idp.exemple.test/",
  "aud":   "se5-college-victor-hugo",
  "tier":  "federated-user",
  "kid":   "idp-2026-key-1",
  "sub":   "tech-7f3a9c20-stable-opaque-id",
  "login": "j.martin.flotte",
  "name":  "Jeanne Martin",
  "email": "j.martin@prestataire.exemple.test",
  "role":  "technicien",
  "iat":   1717400000,
  "nbf":   1717400000,
  "exp":   1717400180,
  "jti":   "01J9Z4K7Q8B2N3M4P5R6S7T8U9"
}
```

> `iss`, `aud`, `tier`, `kid` et `role` doivent correspondre à la configuration de l'instance cible. Voir §4 (confiance) et §6 (rôle).

---

## 3. Endpoint & transport

> Source : `routes/web.php` (route `auth.federated.callback`), `FederatedLoginController::extractToken`.

| Élément | Valeur |
|---------|--------|
| Méthode + chemin | `POST /auth/federated/callback` |
| Nom de route | `auth.federated.callback` |
| Champ du body | `token` = le JWT compact (`xxxxx.yyyyy.zzzzz`) |
| Content-type | formulaire (`application/x-www-form-urlencoded`), façon **SAML POST binding** |
| CSRF | route **exemptée** (`auth/federated/*`) : c'est un POST cross-site auto-soumis par l'IdP ; la preuve d'authenticité est le **JWT signé + anti-rejeu `jti`**, pas un cookie CSRF |
| Ordre de déclaration | la route est déclarée **avant le catchall** (garde-fou testé : `FederatedRouteTest`) |

### Règles de transport strictes

- ✅ **POST binding uniquement.** Le JWT arrive dans le champ de formulaire `token`.
- ❌ **Pas de query string** `?token=…` : un jeton en URL fuiterait dans les logs d'accès, l'historique du navigateur et l'en-tête `Referer`.
- ❌ **Pas de fallback `Authorization: Bearer`.** (Voir §10 — retiré en revue de la Story 20.1 ; toute tolérance Bearer future serait une décision explicite documentée.)
- **Token absent / vide → `400` `Missing federated token`.**

### Côté controlHub

L'IdP rend une page HTML avec un formulaire auto-soumis :

```html
<form id="f" method="POST" action="https://<instance-se5>/auth/federated/callback">
  <input type="hidden" name="token" value="<JWT_RS256>">
</form>
<script>document.getElementById('f').submit();</script>
```

> Ce rendu est **hors scope SE5** : SE5 ne fournit pas de page d'amorçage. C'est à controlHub de produire ce POST (directement ou via le navigateur du technicien).

En cas de succès, SE5 répond par une **redirection 302** vers `app.dashboard` (ou vers l'URL `intended` si une était mémorisée avant l'authentification) et la session SE5 standard est ouverte (cookie de session). Les requêtes suivantes du technicien sont des requêtes SE5 normales.

---

## 4. Mécanisme de confiance

> Source : `config/federated_auth.php`, `FederatedJwtVerifier::buildKeyMap()`.

La confiance repose **exclusivement** sur la **clé publique** de l'émetteur. **Aucun secret n'est partagé par établissement.**

### Configuration côté instance SE5

| Clé de config | `.env` | Rôle |
|---------------|--------|------|
| `federated_auth.jwt.keys` | `FEDERATED_AUTH_JWT_KID` + `FEDERATED_AUTH_JWT_PUBLIC_KEY_PATH` | Map `kid → ['public' => chemin PEM]`. **⚠️ Au MVP, cette map est construite à partir d'une SEULE paire de variables `.env` → une seule entrée `kid`.** Le multi-`kid` (rotation) n'est pas atteignable par `.env` seul (voir note ci-dessous). |
| `federated_auth.jwt.active_kid` | `FEDERATED_AUTH_JWT_KID` | **Informatif côté SE5.** Le verifier accepte **tout** `kid` présent dans `keys` (sélection de la clé par présence dans la map) ; le `kid` du JWT n'a pas à égaler `active_kid`. |
| `federated_auth.jwt.algorithm` | — | `RS256` (figé). |
| `federated_auth.expected_iss` | `FEDERATED_AUTH_EXPECTED_ISS` | `iss` attendu. **String opaque** (domain-neutral). |
| `federated_auth.expected_aud` | `FEDERATED_AUTH_EXPECTED_AUD` | `aud` attendu = identifiant de **cette** instance. Vide → fallback sur `sambaedu.se4fs_name`. |
| `federated_auth.expected_tier` | `FEDERATED_AUTH_EXPECTED_TIER` | `tier` attendu (défaut `federated-user`). |
| `federated_auth.jwt.leeway` | `FEDERATED_AUTH_JWT_LEEWAY` | Tolérance d'horloge ±60 s. |
| `federated_auth.safety.forbid_test_keys_in_production` | `FEDERATED_AUTH_FORBID_TEST_KEYS_IN_PROD` | Refuse une clé pointant sur `tests/fixtures/*` hors env `testing`/`local`. |

### Points d'attention pour controlHub

- **Une clé publique par instance** (= choix de provisioning côté controlHub). Le périmètre « quelles instances un jeton autorise » est une décision controlHub, pas une notion SE5.
- **Approvisionnement & rotation de la clé — direction cible** : la clé publique de controlHub est destinée à être **transmise via le handshake** (le canal d'appel central↔local déjà établi entre controlHub et l'instance), et le **renouvellement** passera par un **endpoint dédié** côté SE5 (à implémenter — hors périmètre actuel). Ce mécanisme remplacera, à terme, le dépôt manuel du fichier PEM.
- **Rotation de clé — état MVP (transitoire)** : aujourd'hui la map `jwt.keys` est construite à partir d'une **seule** paire de variables `.env` (`FEDERATED_AUTH_JWT_KID` / `FEDERATED_AUTH_JWT_PUBLIC_KEY_PATH`), donc une seule entrée. Le verifier **accepte techniquement tout `kid` présent dans la map** (le multi-`kid` est supporté côté vérification), mais tant que l'endpoint de renouvellement n'existe pas, une **rotation sans coupure** (ancienne + nouvelle clé valides simultanément) impose d'**éditer directement `config/federated_auth.php`** pour y ajouter une seconde entrée — ce n'est **pas** pilotable par simple `.env`. Une fois les deux `kid` présents, controlHub bascule l'émission vers le nouveau `kid`, puis la vieille entrée est retirée après recouvrement.
- **Lisibilité du fichier PEM** : tant que la clé est déposée en fichier (état MVP), elle doit être lisible par le user PHP-FPM `www-admin` (uid 599, pool custom Sambaedu).
- **Pas d'endpoint JWKS** : la confiance ne repose pas sur un fetch JWKS. Au MVP la clé est statique en config ; la cible est l'approvisionnement via handshake + endpoint de renouvellement (voir ci-dessus).

---

## 5. Résolution de rôle  ⚠️ pivot — lire attentivement

> Source : `app/Auth/Federated/FederatedRoleMapper.php`, `FederatedLoginController::applyRole`.

**Il n'existe PAS de table de correspondance (`role_map`).** (Supprimée par le pivot de la Story 20.3 — voir §10. L'épure de l'epic qui mentionnait une « table de mapping de rôle » est **périmée**.)

Le nom de rôle asséré dans le claim `role` **est** le contrat. SE5 le résout par un **lookup direct** :

1. **Normalisation** du nom asséré : `trim` + `strtolower` (insensible à la casse et aux espaces de bord).
2. **Lookup direct** parmi les rôles Spatie **existants** de l'instance (table `roles`, guard `web`), via `LOWER(name) = <normalisé>`.
3. **Existe** → le rôle (nom canonique tel qu'en base) est appliqué à l'utilisateur via `syncRoles` (ré-évalué à **chaque** login).
4. **Absent** → **403 `role_unknown`**, **aucune session ouverte**, **aucune création de rôle** à la volée.

### Conséquences pour controlHub

- **Asserter un `role` qui existe déjà dans l'instance cible.** Soit un rôle seedé par SE5 (catalogue `SambaRole`), soit un rôle préalablement créé dans l'instance.
- **Modèle ouvert assumé** (décision Story 20.3 D-5) : tout rôle existant est demandable, y compris `super-admin` s'il existe. **Aucune liste blanche locale.** La sécurité repose sur : la confiance dans l'IdP (JWT signé + anti-rejeu) + l'existence du rôle + l'invariant « inconnu → 403 ».
- **Pas de wildcard, pas de fallback `default`.** Un rôle non résolu ne donne jamais d'accès dégradé.

---

## 6. Erreurs normalisées

> Source : `FederatedJwtErrorCodes`, `InvalidFederatedJwtException`, `FederatedLoginController`.

Convention : **aucun message technique brut n'est exposé au client** ; le détail (avec un `code` stable et un préfixe de hash du jeton, jamais le JWT ni de PII) est journalisé dans le channel `federated-auth`.

| Code (`FederatedJwtErrorCodes`) | Statut HTTP | Cause | Ce que controlHub doit corriger |
|---------------------------------|:----------:|-------|----------------------------------|
| *(token POST absent)* ¹ | **400** | Champ `token` absent/vide dans le POST | Envoyer le JWT dans le champ de formulaire `token` |
| `federated.jwt.malformed` | **401** | JWT non décodable / segment invalide | Vérifier la sérialisation compacte du JWT |
| `federated.jwt.signature_invalid` | **401** | Signature invalide, `alg:none`/algo symétrique, **`kid` inconnu**, ou **aucune clé configurée** côté SE5 | Signer en RS256 avec la bonne clé privée ; aligner le `kid` ; faire provisionner la clé publique côté SE5 |
| `federated.jwt.expired` | **401** | `exp` dépassé (au-delà du leeway) | Réémettre un jeton frais (TTL court) |
| `federated.jwt.not_yet_valid` | **401** | `nbf` dans le futur (au-delà du leeway) | Synchroniser l'horloge de l'IdP |
| `federated.jwt.iss_mismatch` | **401** | `iss` ≠ `expected_iss` de l'instance | Aligner `iss` sur la config de l'instance |
| `federated.jwt.aud_mismatch` | **401** | `aud` présent mais ≠ identifiant de l'instance | Mettre l'identifiant de **cette** instance dans `aud` |
| `federated.jwt.wrong_tier` | **401** | `tier` ≠ `federated-user` | Mettre `tier: "federated-user"` |
| `federated.jwt.missing_claim` | **401** | Claim requis absent/vide (`sub`, `jti`, `kid`, `iss`, `tier`, `role`, `login`, `exp`, **`aud` absent**) | Compléter les claims requis (§2.2) |
| `federated.jwt.replayed` ² | **401** | Même `jti` déjà consommé (rejeu / course concurrente) | Émettre un `jti` unique par jeton ; ne jamais rejouer un jeton |
| `federated.role_unknown` ³ | **403** | `role` valide mais inexistant dans l'instance | Asserter un rôle **existant** dans l'instance (§5) |

> ¹ Le token POST absent est levé directement par le controller (`HttpException(400, 'Missing federated token')`) ; le code catalogue `federated.jwt.missing` existe mais le chemin réel renvoie un 400 brut.
> ² `replayed` est levé par le controller après tentative de consommation du `jti` (`HttpException(401, 'Federated token already used')`).
> ³ `role_unknown` est levé par le controller (`HttpException(403, 'Federated role not authorized on this instance')`).

**Règle mnémotechnique** : tout problème de **jeton** (signature, claims, expiration, rejeu) = **401** ; **token POST manquant** = **400** ; **rôle inconnu** (jeton par ailleurs valide) = **403**.

---

## 7. Audit & limites observées

> Source : `app/Models/ExternalActionAuditLog.php`, `app/Http/Middleware/Auth/AuditExternalAction.php`, `config/federated_auth.php` (bloc `audit`).

### Ce qui est audité

Les actions réalisées **en session fédérée** sont journalisées dans la table `external_action_audit_logs`, de façon **dénormalisée** : `actor_login`, `actor_external_sub`, `actor_name`, `actor_role` sont **copiés au moment de l'action** (jamais une simple FK). Conséquence : le journal **reste lisible même après** soft-delete **ou anonymisation** de l'identité externe (cohérence RGPD/audit — §8).

Périmètre audité :

- **Toujours** les requêtes **mutantes** : `POST` / `PUT` / `PATCH` / `DELETE`.
- **En plus** les `GET` dont le nom de route figure dans l'allowlist `config('federated_auth.audit.sensitive_get_routes')` (écrans exposant de la PII élève — révisable en config).

L'audit s'exécute en middleware **terminable** (`terminate()`, après envoi de la réponse) : zéro latence ajoutée au TTFB, et une action qui se solde par une **500 est tout de même auditée**. L'écriture est **best-effort / fail-soft** : un échec d'audit ne dégrade jamais la réponse métier.

### ⚠️ Limite connue — canal Livewire non audité

SE5 est un projet **Livewire-first** : une grande partie des mutations d'administration natives passe par l'endpoint **`POST /livewire/update`**, et **non** par des routes HTTP classiques. **L'audit décrit ci-dessus (middleware HTTP) ne capture donc PAS** ces mutations Livewire (les auditer en middleware HTTP produirait du bruit — des `POST /livewire/update` sans libellé d'action signifiant).

Cette limite est **assumée et tracée** : elle est adressée par la **Story 20.6 (statut `backlog`)** — audit *signifiant* des actions Livewire fédérées (composant + méthode + arguments, mutations seulement) via un hook du cycle de vie Livewire, réutilisant le même modèle `ExternalActionAuditLog`.

**Implication pour controlHub** : au MVP, la couverture d'audit des actions d'un technicien externe est **partielle** (routes HTTP + GET sensibles, hors canal Livewire). À garder en tête pour toute exigence de traçabilité exhaustive.

### PII dans le journal

`actor_external_sub` est stocké **en clair** dans le journal (PII assumée pour l'imputabilité). La rétention/purge du **journal d'audit lui-même** est **hors scope MVP** (distincte de la rétention de l'identité, §8).

---

## 8. Cycle de vie de l'identité externe & RGPD

> Source : `app/Models/ExternalIdentity.php`, `app/Auth/Federated/ExternalIdentityLifecycleService.php`, `config/federated_auth.php` (bloc `retention`).

Principe cardinal : **identité persistante ≠ accès permanent.** L'`ExternalIdentity` (le « qui ») est durable et **jamais hard-delete** (intégrité de l'audit dénormalisé + FK `users.external_identity_id`). L'**accès** est piloté à chaque connexion.

### Les 4 états

| État | Marqueurs | Login fédéré entrant |
|------|-----------|----------------------|
| **Active** | `is_active=true`, `deleted_at=null`, `anonymized_at=null` | autorisé (sous réserve du JWT + rôle) |
| **Désactivée** | `is_active=false`, `deleted_at=null` | **403** (pas de réactivation silencieuse) |
| **Soft-deletée** | `deleted_at != null` | **403** ; reste résolvable via `withTrashed()` (corrélation audit) |
| **Anonymisée** | `anonymized_at != null` (+ soft-deletée + `is_active=false`) | **403** `identity_anonymized` (anti-résurrection) |

### Réutilisation & synchronisation de profil

- Clé de réutilisation à la reconnexion = `sub`.
- Sync de profil à chaque login : claim **présent → écrase**, **vide/absent → préserve**. **Jamais** le rôle ni `is_active` ne sont pilotés par le profil (séparation identité/accès).

### Rétention RGPD & anonymisation

- **Base légale** énoncée en config (`retention.legal_basis`) : imputabilité des actions d'administration d'un acteur externe (RGPD art. 6-1-c et 6-1-f).
- **TTL PII** : `retention.pii_ttl_days` (défaut **365 j** après `last_login_at`). Au-delà, l'identité devient candidate à l'anonymisation.
- **Anonymisation** (commande planifiée `federated:purge-identities`) : vide `name`/`email`/`login` lisibles et **réécrit `external_sub` en `anon:<hmac-sha256>`** — un **HMAC-SHA256 salé** par une clé dédiée (`retention.hash_key`), pas un hash nu, pour empêcher la ré-identification par bruteforce/rainbow. **Jamais de hard-delete** : la ligne survit pour l'audit et les FK.
- **Interrupteur** `retention.anonymize_enabled` (défaut **`false`**) : tant que la durée/base légale n'est pas validée, la commande tourne en **no-op safe** (aucune suppression silencieuse de PII).
- **Anti-résurrection** : une identité anonymisée qui se reconnecte (même `sub`, JWT valide) reçoit **403** — la purge RGPD n'est pas contournable par reconnexion. La réactivation est une action **admin** explicite.

**Implication pour controlHub** : une fédération réussie n'implique pas un accès permanent. Un technicien dont l'identité a été désactivée/soft-deletée/anonymisée côté SE5 sera **refusé (403)** au prochain login fédéré, même avec un JWT par ailleurs parfaitement valide. **Sessions vivantes** : une désactivation/anonymisation côté SE5 fait également tomber une session déjà ouverte au prochain contrôle du guard de session (l'externe n'est pas maintenu connecté indéfiniment). La (ré)ouverture d'accès est pilotée côté SE5.

---

## 9. Rappel domain-neutral

- Le code SE5 ne contient **aucun** littéral « controlHub » / « central ». L'émetteur est identifié par la string opaque `expected_iss`.
- Le contrat transporte un **nom de rôle**, jamais des permissions.
- Ce document est un guide d'intégration **pour** controlHub ; il **ne recommande pas** d'introduire un couplage controlHub dans le code SE5. controlHub doit s'adapter au contrat générique décrit ici, pas l'inverse.

---

## 10. Écarts epic ↔ code (rédigé après implémentation)

Ce contrat reflète le **code livré** (Stories 20.1-20.4), qui a divergé de l'épure de l'epic sur plusieurs points. Écarts notables :

| # | Épure de l'epic | Code réellement implémenté | Référence |
|---|-----------------|----------------------------|-----------|
| a | « table de mapping de rôle » | **Supprimée.** Résolution = **lookup direct** du nom asséré parmi les rôles Spatie existants (pivot Story 20.3). | §5 |
| b | claims `sub`/`login`/`name`/`email`/`role`/`iss`/`exp`/`signature` | **+ claim `tier`** (= `federated-user`, défense en profondeur) requis, **non prévu** par l'épure. | §2.2 |
| c | (transport non tranché) | **POST binding strict** (champ `token`), **pas** de query string, **pas** de fallback `Authorization: Bearer` (retiré en revue 20.1). | §3 |
| d | « exigences d'audit (identité dénormalisée) » | Audit dénormalisé **présent**, mais **le canal Livewire (`livewire/update`) n'est pas audité** — limite observée, adressée par la Story 20.6 (backlog). | §7 |

### Imprécisions de commentaire repérées (signalées, non corrigées)

- L'anonymisation réécrit `external_sub` via un **HMAC-SHA256 salé** (`hash_hmac('sha256', $sub, retention.hash_key)` — correctif P-4 de la Story 20.2) : la valeur réelle est `anon:<hmac-sha256>`, **pas** un SHA-256 nu (le sel empêche la ré-identification par bruteforce/rainbow). *Note de traçabilité : plusieurs commentaires/docblocks SE5 décrivaient encore l'opération comme `anon:<sha256>` (formulation antérieure à P-4) ; ils ont été alignés sur `anon:<hmac-sha256>` (2026-06-03) dans `config/federated_auth.php`, `app/Auth/Federated/ExternalIdentityLifecycleService.php`, `app/Models/ExternalIdentity.php` et `app/Console/Commands/FederatedPurgeIdentitiesCommand.php` — correction de commentaires uniquement, aucune logique modifiée.*

---

## 11. Checklist d'intégration controlHub

- [ ] Générer une paire de clés RSA ; transmettre la **clé publique PEM** + un `kid` à l'instance SE5 (provisioning hors-bande).
- [ ] Forger le JWT **RS256** avec, dans le **header**, `alg=RS256` et `kid=<kid>`.
- [ ] Renseigner les claims requis (§2.2) : `sub` (stable, opaque), `login`, `role` (existant dans l'instance), `iss` (= `expected_iss`), `aud` (= identifiant de l'instance), `tier=federated-user`, `exp` (TTL court), `jti` (unique), **`kid` aussi dans le payload**.
- [ ] Rendre une page HTML auto-soumise en **POST** vers `https://<instance>/auth/federated/callback`, champ `token=<jwt>`.
- [ ] Ne **jamais** rejouer un jeton (un `jti` est à usage unique).
- [ ] Asserter un `role` qui **existe** dans l'instance cible (sinon 403).
- [ ] Tenir compte de la **rétention/anonymisation** côté SE5 : l'accès n'est pas permanent (gérer la (ré)activation côté SE5).
- [ ] Garder en tête la **couverture d'audit partielle** au MVP (canal Livewire non audité — Story 20.6).

---

## Références code (instance SE5)

- `app/Auth/Federated/Jwt/FederatedJwtVerifier.php` — vérification RS256 + claims + ordre de validation + codes.
- `app/Auth/Federated/Jwt/FederatedUserClaims.php` — DTO des claims.
- `app/Auth/Federated/Http/FederatedLoginController.php` — extraction token POST, flux, 403 `role_unknown`, consommation `jti` en dernier.
- `app/Auth/Federated/FederatedRoleMapper.php` — lookup direct du rôle (pivot 20.3).
- `app/Auth/Federated/Support/FederatedJwtErrorCodes.php` + `app/Auth/Federated/Jwt/Exceptions/InvalidFederatedJwtException.php` — catalogue de codes ↔ statuts HTTP.
- `app/Auth/Federated/ExternalIdentityLifecycleService.php` + `app/Models/ExternalIdentity.php` — cycle de vie, anonymisation HMAC, anti-résurrection.
- `app/Http/Middleware/Auth/AuditExternalAction.php` + `app/Models/ExternalActionAuditLog.php` — audit dénormalisé + limite Livewire.
- `config/federated_auth.php` — configuration de confiance, rétention, audit.
- `routes/web.php` — route `auth.federated.callback` (avant catchall).
- `docs/qa/domains/federated-login.md` — runbook QA des stories 20.1-20.4.

# `App\Auth\Oidc` — SE5 fournisseur d'identité OIDC

**Stories 55.1 et 55.2** (Epic 55 — SSO des extensions). Document d'accueil pour 55.3 et l'Epic 56.

## Où ce namespace se situe

SE5 a trois rôles d'authentification, un namespace chacun, mêmes règles :

| Namespace | Rôle | Interlocuteur |
|---|---|---|
| `App\Auth\V1` | **émetteur** de JWT RS256 | les postes du parc |
| `App\Auth\Federated` | **consommateur** de JWT | un IdP amont de confiance |
| `App\Auth\Oidc` | **fournisseur OIDC** | les navigateurs, au profit d'extensions |

Règles communes : frontière d'import `Firebase\JWT`, channel de log dédié, aucune inclusion `legacy/*`, aucun secret en clair ni en base ni en journal.

## Ce que 55.1 et 55.2 livrent (et ce qu'elles ne livrent pas)

**55.1** : registre de clients confidentiels, flux **Authorization Code + PKCE (S256 obligatoire)**, discovery, JWKS, id_token RS256, access_token opaque, refus journalisés fail-closed.

**55.2** : le **contrat de claims v1** (`name`, `role`, `groups`, scope-gatés), `GET|POST /oidc/userinfo`, l'ensemble **fermé** des scopes (`openid`, `profile`, `groups` — l'inconnu est refusé), `userinfo_endpoint` à la discovery.

Non livré, volontairement :

- app-témoin et suite de tests d'attaque → **Story 55.3** ;
- scopes consentis, `client_credentials`, provisioning automatique du client à l'installation d'une extension `app`, UI admin → **Epic 56** ;
- écran de consentement utilisateur : **il n'y en a pas**. Le consentement des scopes est un acte admin à l'installation, pas un dialogue.

## Pourquoi fait main et pas `laravel/passport`

Le périmètre est minuscule et fermé : **un** grant, des clients déclarés par nous, un id_token RS256. Passport apporterait des dizaines de tables, d'endpoints et de grants à désactiver, plus une dépendance structurante à suivre — et il n'est pas OIDC-complet nativement. Le projet possédait déjà les deux moitiés du problème (`App\Auth\V1` émet du RS256 durci, `App\Auth\Federated` consomme du JWT durci). `league/oauth2-client`, déjà en dépendance, est une bibliothèque **cliente** : elle servira à l'app-témoin 55.3, pas ici.

Toute réouverture de ce choix est un arbitrage utilisateur, pas une décision de développement.

## Topologie

```
app/Auth/Oidc/
├── Http/Controllers/
│   ├── DiscoveryController.php   GET /.well-known/openid-configuration, GET /oidc/jwks  (publics, stateless)
│   ├── AuthorizeController.php   GET /oidc/authorize   (navigateur, derrière `sambaedu.auth`)
│   ├── TokenController.php       POST /oidc/token      (serveur-à-serveur, stateless)
│   └── UserinfoController.php    GET|POST /oidc/userinfo  (Bearer en en-tête UNIQUEMENT — 55.2)
├── Jwt/OidcIdTokenIssuer.php     ⚠️ SEUL fichier autorisé à importer Firebase\JWT
├── Keys/OidcKeyManager.php       génération/lecture de la paire RS256 dédiée + export JWKS
├── Services/
│   ├── OidcClientRegistry.php        register / authenticate / revoke  ← point d'accroche Epic 56
│   ├── OidcAuthorizationService.php  validation, émission et consommation des codes
│   └── OidcAccessTokenValidator.php  verdict sur un Bearer opaque (55.2)  ← réutilisé par Epic 56
└── Support/
    ├── OidcErrorCodes.php        codes INTERNES (journal uniquement)
    ├── OidcSubjectResolver.php   ⚠️ POINT UNIQUE de résolution du claim `sub`
    └── OidcClaimsResolver.php    ⚠️ LE CONTRAT DE CLAIMS v1 (55.2) — gelé, additif seulement
```

Modèles : `App\Models\{OidcClient, OidcAuthorizationCode, OidcAccessToken}` — cohérence avec `Extension*`, l'UI admin de l'Epic 56 les lira.

## Les invariants à ne pas casser

1. **On ne redirige jamais vers une `redirect_uri` non validée.** Client inconnu ou URI non déclarée ⇒ page 400 locale. Rediriger ferait de SE5 un open-redirector et enverrait le refus à l'attaquant.
2. **PKCE obligatoire, S256 seul.** L'absence de `code_challenge` et la méthode `plain` sont refusées. `code_challenge_method` absent vaut `plain` selon la RFC 7636 : c'est donc un refus, pas un défaut implicite.
3. **Correspondance EXACTE des `redirect_uris`** — à l'autorisation ET à l'échange. Ni préfixe, ni wildcard, ni normalisation.
4. **Usage unique du code, sous `lockForUpdate`.** Un échec de vérification (`redirect_uri` divergente, `code_verifier` faux) consomme le code : il n'y a pas de seconde chance. Pas de `Cache::lock()` — APCu n'a pas de verrou dans ce projet.
5. **Aucun secret en clair.** Secrets clients, codes et access tokens sont stockés en sha256 ; les colonnes de hash sont dans `$hidden`. Le secret client n'est affiché qu'une fois, par l'artisan.
6. **Codes d'erreur fins dans le journal, codes OAuth standard dans la réponse.** La réponse ne distingue pas « code inconnu », « expiré » et « déjà consommé ».
7. **Fail-closed.** Clé absente ⇒ exception explicite, jamais d'émission dégradée. JWKS non initialisé ⇒ 503, jamais un `{"keys": []}` en 200 (qui serait mis en cache par les clients).
8. **Frontière crypto** : seul `OidcIdTokenIssuer` importe `Firebase\JWT` — verrouillé par `tests/Architecture/OidcRoutesTest`.
9. **Clé de signature DÉDIÉE.** Jamais celle d'`auth_v1` (non publiée, alors que le JWKS est public), jamais `APP_KEY`.
10. **Les claims standards sont inécrasables** (55.2). `array_merge($claimsMétier, $standard)` dans l'émetteur : l'ordre EST la garantie. Inverser les arguments ouvrirait une usurpation d'identité par le résolveur de claims.
11. **L'ensemble des scopes est FERMÉ** (55.2). Un scope hors `OidcClaimsResolver::supportedScopes()` est refusé (`invalid_scope`), jamais ignoré : l'ignorer laisserait croire à un accord, et le jour où ce nom deviendrait un vrai scope il serait accordé rétroactivement.
12. **L'utilisateur se résout par `user_id`, jamais par le `sub`** (55.2). Le `sub` est une valeur PUBLIÉE, pas une clé de jointure — d'où `oidc_access_tokens.user_id`.

## Le contrat de claims v1 (Story 55.2 — GELÉ, NFR11)

| Scope | Claims produits | Source (SQL uniquement) |
|---|---|---|
| `openid` (obligatoire) | `sub` | `OidcSubjectResolver::for()` |
| `profile` | `name`, `role` | `users.display_name` ; `User::businessRoles()[0]` — **clé absente** si non résoluble |
| `groups` | `groups` | `user_groups` de types `classe` + `equipe`, noms nus triés, `[]` possible |

- **Vocabulaire fermé de `role`** : `prof`, `eleve`, `administratif`, `admin`. Jamais `autre`, jamais `federated`, jamais un rôle Spatie brut. **Scalaire**, jamais un tableau.
- **Règle d'évolution** : on **AJOUTE**, on ne retire ni ne renomme jamais, et on ne change jamais le type d'un claim. Un claim `roles` (ensemble complet) ou un scope supplémentaire sont des évolutions additives légitimes sur besoin démontré.
- **Pas d'`email`, même sous `profile`** — dérogation ASSUMÉE au scope OIDC standard. La population contient des élèves mineurs et NFR5 dit « identité, rôle, groupes du contexte — rien d'autre ». Ni `given_name`/`family_name`, ni attribut d'annuaire (`ad_guid`, `dn`, `memberOf`), ni permission Spatie. **C'est un choix, pas un oubli.**
- **Un claim est une DONNÉE, pas une autorisation.** `role=prof` n'ouvre aucun droit — ni dans SE5, ni dans l'extension. Même distinction que la tuile du lanceur (FR14 : afficher n'est pas protéger). L'autorisation réelle reste côté extension, sur la base de SES règles.
- **`/userinfo`** rend exactement `{sub}` + les claims du scope DU JETON, recalculés depuis l'état SQL courant. Bearer en **en-tête uniquement** (un jeton en query est ignoré, donc refusé). Refus : 401 `invalid_token` INDISTINCT entre jeton inconnu / expiré / client révoqué / utilisateur disparu.

## Catalogue `action_type` (channel `oidc`)

| `action_type` | Émis par |
|---|---|
| `oidc.keys.init.start` / `.success` / `.skipped` | `OidcKeyManager` |
| `oidc.client.registered` / `oidc.client.revoked` | `OidcClientRegistry` |
| `oidc.authorize.granted` / `oidc.authorize.rejected` | `AuthorizeController` |
| `oidc.token.issued` | `OidcIdTokenIssuer` |
| `oidc.token.rejected` | `TokenController`, `OidcClientRegistry` |
| `oidc.userinfo.served` / `oidc.userinfo.rejected` | `UserinfoController` (55.2) |

**Jamais loggés** : secret client (clair ou hash), code d'autorisation clair, access_token clair, id_token complet, clé privée — et, depuis 55.2, **toute PII** : `sub`, `name`, `groups`. Pour corréler une émission et un échange, utiliser un préfixe de hash de 8 caractères (patron `WorkstationJwtVerifier::logRejection()`).

## Exploitation

```bash
php artisan oidc:keys:init                     # idempotent — rejouable à chaque déploiement
php artisan oidc:client:register "Mon extension" \
    --redirect-uri=https://ext.exemple.fr/callback \
    --extension=doc                            # secret affiché UNE SEULE FOIS
php artisan oidc:client:revoke <client_id>     # idempotent
```

## Le claim `sub` — arbitrage en cours

`OidcSubjectResolver::for()` retourne aujourd'hui `users.login` (doctrine « un utilisateur SE5 EST son login »). Les alternatives (`ad_guid`, `users.id`) et leurs conséquences sont documentées dans le docblock de la classe. **Le contrat se gèle en 55.2** : après publication, changer `sub` casse les données de toutes les extensions. La bascule coûte une méthode — et, accessoirement, une migration de renommage de la colonne `oidc_authorization_codes.user_login`.

## Trajectoire Keycloak (NFR12)

Chaque choix « standard vs maison » de ce namespace a été tranché en faveur du standard : endpoints et noms de paramètres OIDC, erreurs RFC 6749, JWKS RFC 7517. Une extension écrite contre ce fournisseur ne verra aucune différence le jour où l'émetteur changera.

# `App\Auth\Oidc` — SE5 fournisseur d'identité OIDC

**Stories 55.1, 55.2 et 55.3** (Epic 55 — SSO des extensions, **CLOS**), étendues par **56.2** (provisioning automatique) et **56.4** (scopes accordés, révocation, API extensions v1).

## Où ce namespace se situe

SE5 a trois rôles d'authentification, un namespace chacun, mêmes règles :

| Namespace | Rôle | Interlocuteur |
|---|---|---|
| `App\Auth\V1` | **émetteur** de JWT RS256 | les postes du parc |
| `App\Auth\Federated` | **consommateur** de JWT | un IdP amont de confiance |
| `App\Auth\Oidc` | **fournisseur OIDC** | les navigateurs, au profit d'extensions |

Règles communes : frontière d'import `Firebase\JWT`, channel de log dédié, aucune inclusion `legacy/*`, aucun secret en clair ni en base ni en journal.

## Ce que les stories livrent (et ce qu'elles ne livrent pas)

**55.1** : registre de clients confidentiels, flux **Authorization Code + PKCE (S256 obligatoire)**, discovery, JWKS, id_token RS256, access_token opaque, refus journalisés fail-closed.

**55.2** : le **contrat de claims v1** (`name`, `role`, `groups`, scope-gatés), `GET|POST /oidc/userinfo`, l'ensemble **fermé** des scopes (`openid`, `profile`, `groups` — l'inconnu est refusé), `userinfo_endpoint` à la discovery.

**55.3** : l'**app-témoin** — un client OIDC honnête, en quarantaine, qui ne consomme QUE ce contrat public par HTTP. Elle vit dans `app/OidcWitness/` (voir son README : la charte de quarantaine, ce qu'elle s'interdit et pourquoi), s'atteint par sa tuile « Démo SSO » du lanceur, et s'accompagne de la suite d'attaque cliente (NFR1) plus du test d'architecture FR24. **Ce namespace-ci est resté à ZÉRO diff** — c'est précisément ce qui donne sa valeur à la démonstration : le contrat n'a pas eu besoin d'être élargi pour qu'un client honnête l'utilise.

**56.2 / 56.4** (Epic 56) : le client d'une extension `app` est désormais **provisionné automatiquement** à son installation (56.2), avec les **scopes qu'elle demande** — persistés en `oidc_clients.granted_scopes` (56.4), révocables un par un depuis sa fiche, et consommés par l'**API extensions `/api/ext/v1/`** (voir plus bas).

Non livré, volontairement :

- **`client_credentials`** : pas de grant machine. Les deux endpoints du v1 portent sur l'utilisateur courant, qu'un jeton machine n'a pas — et le validateur refuserait d'ailleurs un jeton sans `user_id` (fail-closed). Le « token de service » de FR22, c'est l'**access token opaque par-extension** émis à l'échange : lié au client, borné par un scope, révocable. L'ajout d'un grant machine sera ADDITIF le jour où un endpoint sans utilisateur existera (il faudra alors un discriminant sur `oidc_access_tokens`) ;
- écran de consentement utilisateur : **il n'y en a pas**. Le consentement des scopes est un acte admin à l'installation, pas un dialogue ;
- **ré-octroi d'un scope** : la révocation est à sens unique. Re-consentir = désinstaller puis réinstaller l'extension (même doctrine que `redirect_paths_changed`).

## Pourquoi fait main et pas `laravel/passport`

Le périmètre est minuscule et fermé : **un** grant, des clients déclarés par nous, un id_token RS256. Passport apporterait des dizaines de tables, d'endpoints et de grants à désactiver, plus une dépendance structurante à suivre — et il n'est pas OIDC-complet nativement. Le projet possédait déjà les deux moitiés du problème (`App\Auth\V1` émet du RS256 durci, `App\Auth\Federated` consomme du JWT durci). `league/oauth2-client`, déjà en dépendance, est une bibliothèque **cliente** : elle sert à l'app-témoin 55.3 (`app/OidcWitness/`), pas ici.

Toute réouverture de ce choix est un arbitrage utilisateur, pas une décision de développement.

## Topologie

```
app/Auth/Oidc/
├── Http/Controllers/
│   ├── DiscoveryController.php   GET /.well-known/openid-configuration, GET /oidc/jwks  (publics, stateless)
│   ├── AuthorizeController.php   GET /oidc/authorize   (navigateur, derrière `sambaedu.auth`)
│   ├── TokenController.php       POST /oidc/token      (serveur-à-serveur, stateless)
│   └── UserinfoController.php    GET|POST /oidc/userinfo  (Bearer en en-tête UNIQUEMENT — 55.2)
├── Http/Middleware/
│   └── EnsureExtensionApiToken.php   alias `ext.token:<scope>` — la porte de /api/ext/v1/ (56.4)
├── Jwt/OidcIdTokenIssuer.php     ⚠️ SEUL fichier autorisé à importer Firebase\JWT
├── Keys/OidcKeyManager.php       génération/lecture de la paire RS256 dédiée + export JWKS
├── Services/
│   ├── OidcClientRegistry.php        register / authenticate / revoke / revokeScope (56.4)
│   ├── OidcAuthorizationService.php  validation, émission et consommation des codes
│   └── OidcAccessTokenValidator.php  verdict sur un Bearer opaque (55.2)  ← réutilisé par Epic 56
└── Support/
    ├── OidcErrorCodes.php        codes INTERNES (journal uniquement)
    ├── OidcBearer.php            ⚠️ POINT UNIQUE d'extraction du Bearer (en-tête SEUL)
    ├── OidcSubjectResolver.php   ⚠️ POINT UNIQUE de résolution du claim `sub`
    └── OidcClaimsResolver.php    ⚠️ LE CONTRAT DE CLAIMS v1 (55.2) — gelé, additif seulement
```

Modèles : `App\Models\{OidcClient, OidcAuthorizationCode, OidcAccessToken}` — cohérence avec `Extension*`. `OidcClient` porte en plus le **point unique du scope effectif** (56.4).

Hors de ce namespace, mais partie du même canal : `App\Http\Controllers\Api\Ext\V1\MeController` (les deux endpoints de l'API extensions) et `App\Services\Extensions\ExtensionScopeService` (lecture et révocation des scopes accordés, côté registre d'extensions). Ce placement n'est pas cosmétique : c'est lui qui laisse `routes/api.php` sans aucune référence à ce namespace.

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
13. **Le scope EFFECTIF a UN SEUL énoncé** (56.4) : `OidcClient::effectiveScopeFor()` = `scope du jeton ∩ (granted_scopes + openid)`, recalculé à CHAQUE usage. Trois consommateurs — l'émission, `/userinfo`, l'API extensions. Un seul qui lirait le scope stocké ferait mentir la révocation pendant toute la vie du jeton.
14. **`openid` n'est jamais accordé ni révoqué** (56.4). C'est le plancher du protocole : il ne figure pas dans `granted_scopes`, et une tentative de l'accorder ou de le retirer est refusée. Retirer l'identité, c'est désinstaller l'extension.

## Scopes accordés et révocation (Story 56.4 — FR23)

- **Où** : `oidc_clients.granted_scopes` (json, sous-ensemble de `{profile, groups}`). Défaut `[]` = **fail-closed** : un client sans octroi n'obtient que `sub`.
- **Quand** : à l'installation d'une extension `app`, l'octroi vaut EXACTEMENT les `scopes` de son manifest. Un manifest qui demande un scope non supporté fait échouer l'installation (`ERROR_UNSUPPORTED_SCOPES`) plutôt que d'obtenir un octroi tronqué. `ext:update` n'y touche jamais ; `ext:remove` révoque le client, qui emporte tout.
- **Révocation** : `ExtensionScopeService::revokeScope()` (UI de la fiche) → `OidcClientRegistry::revokeScope()` sur **tous** les clients actifs de la clé, en transaction avec sa ligne d'audit `scope_revoke`. Idempotente.
- **Effet immédiat, sans purge** : le scope effectif étant recalculé à chaque usage, `/userinfo` et l'API cessent DANS LA SECONDE de servir la donnée, et un nouveau flux est **downscopé** (RFC 6749 §3.3 : la réduction est annoncée par le paramètre `scope` de la réponse token).
- **Résidu ASSUMÉ** : les **id_tokens déjà émis** portent leurs claims jusqu'à leur `exp` — un JWT est auto-porteur, il ne se rappelle pas. La fenêtre est bornée par le TTL de 300 s (NFR1). Les access tokens, eux, sont opaques : ils sont réduits immédiatement.

## API extensions v1 (Story 56.4 — FR21/FR22, AR6)

| Route | Scope requis | Réponse (clés EXACTES) |
|---|---|---|
| `GET /api/ext/v1/me` | `profile` | `success`, `message`, `sub`, `name`, `role` *(absent si non résoluble)* |
| `GET /api/ext/v1/me/groups` | `groups` | `success`, `message`, `sub`, `groups` *(liste, éventuellement vide)* |

- **Authentification** : `Authorization: Bearer <access_token>` — en-tête UNIQUEMENT (query et corps ignorés). Middleware aliasé `ext.token:<scope>` ; le scope requis est déclaré sur la route.
- **Format MAISON** (`{success, message, clés métier à la racine}`), contrairement à `/oidc/token` et `/oidc/userinfo` : l'interlocuteur ici est le SDK SE5 (AR6), pas un client OIDC générique.
- **Refus** : `401 invalid_token` INDISTINCT (absent / inconnu / expiré / client révoqué / utilisateur disparu ou désactivé) ; `403 insufficient_scope` générique, sans énumérer les scopes détenus. Codes fins au journal seul.
- **Valeurs** : celles du contrat de claims v1, VERBATIM (`OidcClaimsResolver`). L'API n'élargit RIEN — `sub` est le `user_login` du jeton, jamais une re-résolution.
- **Évolution (NFR11)** : `/v1/` est **gelé**. On ajoute une clé ou une route ; on ne retire, ne renomme ni ne retype jamais. Une rupture se livre en `/api/ext/v2/` À CÔTÉ, le v1 restant servi.

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
| `oidc.client.scope_revoked` | `OidcClientRegistry` (56.4) |
| `oidc.authorize.granted` / `oidc.authorize.rejected` | `AuthorizeController` |
| `oidc.token.issued` | `OidcIdTokenIssuer` |
| `oidc.token.rejected` | `TokenController`, `OidcClientRegistry` |
| `oidc.userinfo.served` / `oidc.userinfo.rejected` | `UserinfoController` (55.2) |
| `oidc.ext_api.served` | `Api\Ext\V1\MeController` (56.4) |
| `oidc.ext_api.rejected` | `EnsureExtensionApiToken` (56.4) |

**Jamais loggés** : secret client (clair ou hash), code d'autorisation clair, access_token clair, id_token complet, clé privée — et, depuis 55.2, **toute PII** : `sub`, `name`, `groups`. Pour corréler une émission et un échange, utiliser un préfixe de hash de 8 caractères (patron `WorkstationJwtVerifier::logRejection()`).

## Exploitation

```bash
php artisan oidc:keys:init                     # idempotent — rejouable à chaque déploiement
php artisan oidc:client:register "Mon extension" \
    --redirect-uri=https://ext.exemple.fr/callback \
    --extension=doc                            # secret affiché UNE SEULE FOIS
php artisan oidc:client:register "Mon extension" \
    --redirect-uri=https://ext.exemple.fr/callback \
    --scope=profile                            # 56.4 — défaut : profile ET groups
php artisan oidc:client:revoke <client_id>     # idempotent
```

## Le claim `sub` — arbitrage en cours

`OidcSubjectResolver::for()` retourne aujourd'hui `users.login` (doctrine « un utilisateur SE5 EST son login »). Les alternatives (`ad_guid`, `users.id`) et leurs conséquences sont documentées dans le docblock de la classe. **Le contrat se gèle en 55.2** : après publication, changer `sub` casse les données de toutes les extensions. La bascule coûte une méthode — et, accessoirement, une migration de renommage de la colonne `oidc_authorization_codes.user_login`.

## Trajectoire Keycloak (NFR12)

Chaque choix « standard vs maison » de ce namespace a été tranché en faveur du standard : endpoints et noms de paramètres OIDC, erreurs RFC 6749, JWKS RFC 7517. Une extension écrite contre ce fournisseur ne verra aucune différence le jour où l'émetteur changera.

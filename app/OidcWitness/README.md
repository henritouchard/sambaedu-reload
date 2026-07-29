# `App\OidcWitness` — l'app-témoin SSO (Story 55.3)

Une page. Elle affiche « Bonjour {name}, rôle {role}, groupes {groups} » après un
SSO complet contre le fournisseur OIDC de SE5 (`app/Auth/Oidc/`).

Ce n'est **pas** une fonctionnalité produit, ni un prototype de BigBlueButton
(Epic 57), ni le SDK des extensions (Epic 58). C'est une **sonde de contrat** :
la seule question qu'elle répond est « le contrat public suffit-il à une
application qui n'a QUE le contrat ? ».

## La charte de quarantaine

Tout ce que ce namespace sait de l'utilisateur arrive par **HTTP**, depuis les
endpoints publics du fournisseur : discovery, autorisation (navigateur), échange
de code (serveur-à-serveur), JWKS.

Ce namespace **s'interdit**, et un test d'architecture le lui interdit
mécaniquement (`tests/Architecture/ExtensionIsolationTest.php`, FR24) :

| Interdit | Pourquoi |
|---|---|
| Modèles Eloquent de SE5 | lire un utilisateur en base court-circuiterait tout le protocole |
| Services applicatifs de SE5 | idem, un cran plus haut |
| Les internes du fournisseur (`app/Auth/Oidc/`) | valider avec le code qui a émis ne prouve rien |
| La façade de base de données / le query builder | une extension n'a pas la base |
| L'annuaire (LDAP) | une extension n'a pas l'annuaire |
| L'utilisateur connecté (`auth()`, `Auth::`) | ce serait valider la connexion SE5, pas le SSO |
| Le magasin d'état côté serveur de SE5 (`session()`) | c'est le magasin de SE5, pas celui de l'extension |

**Un témoin qui triche ne prouve rien.** Si un jour l'implémentation d'ici
devenait impossible sans franchir l'une de ces lignes, ce ne serait pas un
problème de témoin : ce serait la démonstration que le contrat public est
insuffisant — et c'est exactement ce que cette sonde est censée révéler.

## Ce qu'il utilise, et pourquoi ce n'est pas de la triche

Routage, cookies chiffrés (`APP_KEY`), cache, journal, Blade : c'est de
l'**infrastructure d'hébergement**, pas de la **donnée**. Une vraie extension
aurait la sienne. Le témoin partage l'hébergement de SE5 (même processus PHP),
pas ses contrats.

Corollaire assumé : **le témoin ne prouve que l'isolation par CONTRAT** (FR24).
L'isolation par **processus** (NFR4 complet) appartient aux vraies extensions de
type `app` — Epics 56 et 57.

## Topologie

```
app/OidcWitness/
├── Http/Controllers/WitnessController.php   GET /sso-demo, GET /sso-demo/callback
├── Jwt/
│   ├── WitnessIdTokenVerifier.php           vérification cliente durcie (RS256 pinné, iss/aud/exp/nbf/nonce)
│   ├── WitnessJtiReplayGuard.php            usage unique du `jti`, cache seul, fail-closed
│   └── Exceptions/InvalidWitnessIdTokenException.php
└── Support/
    ├── WitnessCredentials.php               le fichier 0600 que l'opérateur a posé
    ├── WitnessErrorCodes.php                codes internes (journal + page d'erreur sobre)
    ├── WitnessHttpClient.php                LE canal de données — point d'injection en test
    └── WitnessProviderMetadata.php          discovery + JWKS, découverts par HTTP
```

Vues autonomes : `resources/views/oidc-witness/{claims,error}.blade.php` (aucun
layout SE5 — une extension n'a pas la navbar de SE5).

## Exploitation

```bash
php artisan oidc:witness:enable            # idempotente ; --rotate renouvelle le secret
php artisan db:seed --class=BundledExtensionSeeder --force
# puis intégrer « Démo SSO » depuis /admin/extensions
php artisan oidc:witness:disable           # idempotente
```

Sans provisioning, `/sso-demo` répond une page d'erreur explicite en 503 —
jamais une 500 brute, jamais un contournement.

## Limites connues, assumées

1. **L'anti-rejeu `jti` s'appuie sur le cache `file`**, local au serveur. Cela
   suffit à une sonde mono-instance ; cela ne suffirait pas à une extension
   répartie, qui aura son propre stockage. Lui donner ici un filet partagé (base,
   Redis) reviendrait à lui prêter une capacité qu'une extension n'a pas : la
   sonde mentirait.
2. **Le témoin n'appelle pas `/userinfo`** : l'id_token porte déjà les claims du
   contrat v1. `/userinfo` reste couvert par les tests de 55.2.
3. **Germe du SDK, pas le SDK.** `WitnessIdTokenVerifier` et
   `WitnessJtiReplayGuard` sont la référence de ce qu'une vérification cliente
   doit faire — rien n'en est extrait, publié ni rendu générique. Le kit sera
   extrait de BigBlueButton le moment venu (AR10, Epic 58).
4. **La tuile « Démo SSO » existe sur toute instance**, mais à l'état
   `available` : elle n'atteint aucun utilisateur sans une intégration explicite
   par l'administrateur. Pour la réserver aux instances de développement, le
   retrait de `resources/extensions/sso-demo/manifest.json` suffit — aucun code
   à toucher.
5. **La page d'erreur du témoin nomme la cause du refus** (`STATE_MISMATCH`,
   `NONCE_MISMATCH`, `JTI_REPLAYED`, `AUD_MISMATCH`…), à l'inverse de la
   doctrine « pas d'oracle » que le **fournisseur** applique (55.1 #1, 55.2 #1 :
   réponse indistincte, diagnostic au journal seulement). Divergence **assumée**,
   relevée en review 55.3 (#2) :
   - le témoin n'est pas un fournisseur d'identité, c'est un **outil de
     diagnostic d'intégration** : une page qui afficherait « erreur » sans plus
     ne servirait à rien, et l'admin n'a pas accès aux journaux du témoin ;
   - il ne protège **aucun secret** — il ne détient que ses propres credentials,
     et aucun code ne dépend de l'opacité de ses messages ;
   - le seul bucket réellement sensible est déjà **fusionné** : `alg: none`,
     confusion d'algorithme, clé étrangère et `kid` inconnu rendent tous
     `ID_TOKEN_SIGNATURE_INVALID`, jamais le détail de ce qui a échoué.

   Si une extension réelle copie ce patron, c'est cette dernière règle qu'elle
   doit reprendre — pas la granularité du reste. Le SDK (Epic 58) tranchera pour
   les extensions distribuées.

## Les tests qui font foi

| Fichier | Ce qu'il prouve |
|---|---|
| `tests/Unit/OidcWitness/WitnessIdTokenVerifierTest.php` | la suite d'attaque NFR1 — un test par vecteur, chacun adossé à un contrôle positif |
| `tests/Feature/OidcWitness/WitnessFlowTest.php` | le parcours complet, par HTTP, sans re-saisie d'identifiants |
| `tests/Feature/OidcWitness/OidcWitnessCommandsTest.php` | provisioning idempotent, 0600, secret jamais affiché |
| `tests/Feature/OidcWitness/ExtensionIdentityLeakTest.php` | aucun identifiant de base ni d'annuaire dans le canal extensions |
| `tests/Architecture/ExtensionIsolationTest.php` | la quarantaine ci-dessus, et l'absence d'exécution de code d'extension |

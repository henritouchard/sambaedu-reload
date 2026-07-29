# QA Manuel — Extensions

**Domaine** : système d'extensions SE5 — registre local multi-sources, manifest déclaratif (contrat public), bibliothèque d'administration et fiches d'extension.

**Stories couvertes** (mise à jour 55.3 — **Section 15** : app-témoin SSO en quarantaine, suite d'attaque cliente NFR1, test d'architecture FR24, provisioning artisan idempotent — **DERNIÈRE story, CLÔT l'Epic 55** ; 55.2 — **Section 13** : contrat de claims v1 `name`/`role`/`groups` scope-gatés, `GET|POST /oidc/userinfo`, ensemble FERMÉ des scopes, fail-closed sur rôle et utilisateur irrésolus, discovery enrichie **additivement**) : 54.1 (socle : tables `extension_sources` + `extensions`, enums, validation du manifest v1, synchro de la source embarquée, pages `/admin/extensions` et `/admin/extensions/{id}`, frontière NFR14 avec la sync amont) ; 54.2 (intégrer/désinstaller le type `link` en un clic + confirmation par modale, journal d'audit `extension_audit_logs` FR36 socle, frontière NFR14 étendue à la 3ᵉ table) ; **54.3 (lanceur « gaufre » navbar : tuiles filtrées par rôle métier `User::businessRoles()`, ouverture nouvel onglet, état vide propre, NFR9 — 1 requête SQL / 0 HTTP) — DERNIÈRE story, clôt l'Epic 54**. **55.1 (SE5 fournisseur OIDC : registre des clients confidentiels, flux Authorization Code + PKCE S256, discovery et JWKS, id_token RS256, refus fail-closed journalisés, reprise du flux après login) — OUVRE l'Epic 55 (SSO)** ; 55.2 (contrat de claims v1 + `/userinfo`) ; **55.3 (app-témoin `app/OidcWitness/` atteinte par sa tuile, vérificateur client durci + anti-rejeu `jti`, suite d'attaque NFR1, quarantaine FR24, `oidc:witness:enable`/`disable`) — DERNIÈRE story, CLÔT l'Epic 55**. _Epic 56 (scopes consentis, sources distantes, type `app`, provisioning automatique des clients) à ajouter en sections suivantes quand livré._

**Code de référence** :
- `database/migrations/2026_07_28_100000_create_extension_registry_tables.php` — les 2 tables, branches `jsonb`/`json` et `timestampTz`/`timestamp`, clé naturelle `ext_natural_key`
- `database/migrations/2026_07_28_200000_create_extension_audit_logs_table.php` — table d'audit append-only (54.2)
- `app/Enums/{ExtensionType,ExtensionStatus,ExtensionSourceKind}.php`
- `app/Models/{Extension,ExtensionSource}.php` — `status` volontairement HORS `$fillable`
- `app/Models/ExtensionAuditLog.php` — journal append-only du cycle de vie (54.2, calque `CapabilityOverrideAuditLog`)
- `app/Services/Extensions/ExtensionManifestValidator.php` — validation PURE du manifest v1 (version stricte d'abord)
- `app/Services/Extensions/ExtensionCatalogService.php` — `syncBundled()` / `library()` / `find()`
- `app/Services/Extensions/ExtensionLifecycleService.php` — `integrate()` / `uninstall()`, seul écrivain de `extensions.status` (54.2)
- `app/Exceptions/InvalidExtensionManifestException.php` — porte le champ fautif
- `app/Exceptions/ExtensionLifecycleException.php` — id inconnu / type non pris en charge (54.2, fail-closed)
- `config/extensions.php` — `bundled_path` (chemin de découverte surchargeable)
- `resources/extensions/doc/manifest.json` — manifest de la tuile Documentation (`link` → `/doc`)
- `database/seeders/BundledExtensionSeeder.php` (+ enregistrement dans `DatabaseSeeder`)
- `resources/views/pages/admin/extensions/index.blade.php`, `resources/views/pages/admin/extensions/[id]/index.blade.php` — boutons Intégrer/Désinstaller + modale de confirmation depuis 54.2
- `resources/views/components/organisms/sidebar.blade.php` — entrée « Extensions » du bloc Serveur
- `routes/web.php` — `admin.extensions` et `admin.extensions.show` (groupe admin + `can:server.admin`)
- `tests/Feature/ControlHub/UpstreamSyncExtensionsBoundaryTest.php` — frontière NFR14 (3 tables depuis 54.2)
- `tests/Feature/Extensions/ExtensionLifecycleServiceTest.php` — transitions, no-op, atomicité, append-only (54.2)
- `app/Models/User.php` — `businessRoles()` : résolution canonique du rôle métier, 100 % Postgres (54.3)
- `app/Services/Extensions/ExtensionLauncherService.php` — `tilesFor()` : tuiles d'un utilisateur, lecture seule, 1 requête SQL (54.3)
- `resources/views/components/organisms/app-launcher.blade.php` — SFC Livewire du lanceur « gaufre » (54.3)
- `resources/views/components/organisms/navbar.blade.php` — insertion `<livewire:organisms.app-launcher />` (54.3)
- `tests/Unit/Models/UserBusinessRolesTest.php`, `tests/Feature/Extensions/ExtensionLauncherServiceTest.php`, `tests/Feature/Livewire/AppLauncherTest.php` — matrice rôles×visibilités, fail-closed `app`/`available`, NFR9, FR14 (54.3)
- `app/Auth/Oidc/README.md` — topologie du namespace, invariants, catalogue `action_type` (55.1)
- `database/migrations/2026_07_28_300000_create_oidc_provider_tables.php` — `oidc_clients`, `oidc_authorization_codes`, `oidc_access_tokens` (55.1)
- `app/Models/{OidcClient,OidcAuthorizationCode,OidcAccessToken}.php` — colonnes de hash en `$hidden` (NFR3)
- `app/Auth/Oidc/Keys/OidcKeyManager.php` + `app/Console/Commands/OidcKeysInit.php` — paire RS256 **dédiée**, génération idempotente, export JWKS
- `app/Auth/Oidc/Services/OidcClientRegistry.php` — `register`/`authenticate`/`revoke` ; point d'accroche du provisioning Epic 56
- `app/Auth/Oidc/Services/OidcAuthorizationService.php` — ordre de validation, émission et consommation des codes sous `lockForUpdate`
- `app/Auth/Oidc/Jwt/OidcIdTokenIssuer.php` — **seul** fichier du namespace important `Firebase\JWT`
- `app/Auth/Oidc/Support/OidcSubjectResolver.php` — **point UNIQUE** de résolution du claim `sub` (arbitrage en cours)
- `app/Auth/Oidc/Support/OidcErrorCodes.php` — codes internes, journal uniquement (jamais dans une réponse HTTP)
- `app/Auth/Oidc/Support/OidcClaimsResolver.php` — **le contrat de claims v1** (55.2) : `CLAIMS_BY_SCOPE`, `supportedScopes()`, vocabulaire fermé de `role`, zéro LDAP
- `app/Auth/Oidc/Services/OidcAccessTokenValidator.php` — verdict sur un Bearer opaque (55.2), réutilisé par l'Epic 56
- `app/Auth/Oidc/Http/Controllers/UserinfoController.php` — `GET|POST /oidc/userinfo`, Bearer en en-tête uniquement, 401 indistincts (55.2)
- `database/migrations/2026_07_28_310000_add_user_id_to_oidc_access_tokens.php` — clé de résolution de l'utilisateur, additive (55.2)
- `tests/Feature/Oidc/{OidcIdTokenClaimsTest,OidcUserinfoTest}.php` — liste EXACTE des clés de claims par scope, refus adossés à un contrôle positif (55.2)
- `app/Auth/Oidc/Http/Controllers/{Discovery,Authorize,Token}Controller.php`
- `app/Console/Commands/{OidcClientRegister,OidcClientRevoke}.php`
- `app/Http/Middleware/Auth/SambaEduAuthGuard.php` — `url.intended` passe de `path()` à `fullUrl()` (55.1, piège n°1)
- `config/oidc.php`, `config/logging.php` (channel `oidc`), `resources/views/oidc/authorize-error.blade.php`
- `tests/Feature/Oidc/*`, `tests/Architecture/OidcRoutesTest.php` — flux, refus, discovery/JWKS, commandes, reprise post-login, garde-fous d'ordre et de frontière crypto (55.1)
- `app/OidcWitness/README.md` — **la charte de quarantaine** de l'app-témoin : ce qu'elle s'interdit, pourquoi, et ses limites assumées (55.3)
- `app/OidcWitness/Http/Controllers/WitnessController.php` — `GET /sso-demo` + `GET /sso-demo/callback`, état par cookie chiffré dédié (55.3)
- `app/OidcWitness/Jwt/WitnessIdTokenVerifier.php` — vérificateur client durci : RS256 pinné par key-map construite depuis le JWKS, `iss`/`aud`/`exp`/`nbf`/`nonce`, **germe du SDK Epic 58** (55.3)
- `app/OidcWitness/Jwt/WitnessJtiReplayGuard.php` — anti-rejeu `jti` CLIENT, cache seul (jamais la base — FR24), fail-closed (55.3)
- `app/OidcWitness/Support/{WitnessCredentials,WitnessProviderMetadata,WitnessHttpClient,WitnessErrorCodes}.php` — fichier 0600, discovery/JWKS par HTTP, unique canal de données (55.3)
- `app/Console/Commands/{OidcWitnessEnable,OidcWitnessDisable}.php` — provisioning idempotent ; le secret n'est **jamais** affiché (55.3)
- `resources/extensions/sso-demo/manifest.json` — la tuile « Démo SSO » (`link` → `/sso-demo`, scopes `profile`+`groups`)
- `resources/views/oidc-witness/{claims,error}.blade.php` — vues autonomes, sans layout SE5
- `config/oidc.php` § `witness` — chemin des credentials, store d'anti-rejeu, timeout HTTP, TTL du cookie d'état
- `tests/Unit/OidcWitness/WitnessIdTokenVerifierTest.php` — **la suite d'attaque NFR1** + sa table de traçabilité (ce qui est déjà couvert par 55.1/55.2 n'y est PAS dupliqué)
- `tests/Feature/OidcWitness/WitnessFlowTest.php` + `Concerns/ReentersTheTestKernel.php` — parcours complet par HTTP (transport substitué, protocole intact)
- `tests/Feature/OidcWitness/{OidcWitnessCommandsTest,ExtensionIdentityLeakTest}.php` — provisioning, et « aucun identifiant de base ni d'annuaire ne fuit »
- `tests/Architecture/ExtensionIsolationTest.php` — **FR24** : quarantaine du témoin (avec méta-test anti-tautologie), manifest sans champ exécutable, autoload sans répertoire d'extensions

---

## Pré-requis communs

- VM SER accessible : `ssh -i ~/.ssh/id_se4fs_vm root@192.168.122.50`
- Migrations à jour : `cd /var/www/sambaedu-reload && php artisan migrate`
- Registre peuplé : `php artisan db:seed --class=BundledExtensionSeeder --force`
- Cache Spatie reset : `php artisan permission:cache-reset`
- Deux comptes :
  - `admin` — détenteur de `server.admin`
  - `enseignant.test` — rôle `prof`, **sans** `server.admin`
- Le site de documentation `/doc` doit être publié (Story 52.1, `bash scripts/update.sh`) pour que la cible de la tuile Documentation réponde.

> **Rappel de périmètre 54.1** : les pages étaient en **lecture seule** à l'origine. **Depuis la Story 54.2**, les boutons « Intégrer » / « Désinstaller » existent pour le type `link` (cartes de la bibliothèque + fiche) — voir Section 7. **Depuis la Story 54.3**, le lanceur « gaufre » de la navbar rend les tuiles des extensions intégrées de type `link`, filtrées par rôle métier — voir Section 9. **L'Epic 54 est désormais complet.** Reste hors périmètre : aucune UI d'ajout de source distante, aucun cycle du type `app`, aucune santé/indisponibilité de tuile (FR35), aucun SSO/claims (Epics 55/56).

---

## Section 1 — Registre et source embarquée (Story 54.1)

### Scénario 1.1 — Seed initial de la source embarquée

1. Sur la VM : `php artisan db:seed --class=BundledExtensionSeeder --force`.
2. `php artisan tinker` puis :
   ```php
   \App\Models\ExtensionSource::all(['key','name','kind','url','is_official','enabled'])->toArray();
   \App\Models\Extension::all(['key','name','version','type','status'])->toArray();
   ```

**Attendu** :
- Exactement **une** source : `key = bundled`, `name = "Embarquée (SambaEdu)"`, `kind = bundled`, `url = ""` (chaîne vide, **jamais** `null`), `is_official = true`, `enabled = true`.
- Exactement **une** extension : `key = doc`, `name = Documentation`, `type = link`, `status = available`.
- Aucune erreur, aucun warning `[Extensions]` dans `storage/logs/laravel.log`.

### Scénario 1.2 — Re-seed idempotent (aucun doublon, aucune écriture)

1. Noter `updated_at` de l'extension `doc` :
   `php artisan tinker --execute="echo \App\Models\Extension::where('key','doc')->value('updated_at');"`
2. Rejouer `php artisan db:seed --class=BundledExtensionSeeder --force`.
3. Re-lire `updated_at` et compter les lignes.

**Attendu** :
- Toujours 1 source et 1 extension (pas de doublon).
- `updated_at` **inchangé** : un manifest identique n'écrit rien.
- Log `[Extensions] Synchro de la source embarquée terminée` avec `created: 0`, `updated: 0`, `skipped: 0`, `pruned: 0`.

### Scénario 1.3 — Le `status` n'est jamais réécrit par la synchro

1. Marquer l'extension comme intégrée (54.2 le fera depuis l'UI) :
   ```php
   $e = \App\Models\Extension::where('key','doc')->first();
   $e->status = \App\Enums\ExtensionStatus::Integrated; $e->save();
   ```
2. Rejouer `php artisan db:seed --class=BundledExtensionSeeder --force`.
3. Relire `status`.

**Attendu** :
- `status` reste `integrated`.
- **C'est l'invariant fondateur** : un simple rechargement de catalogue ne doit JAMAIS dé-intégrer une extension que l'admin a intégrée.

### Scénario 1.4 — Manifest disparu : prune borné

1. Créer un second manifest de test sur la VM :
   ```
   mkdir -p /var/www/sambaedu-reload/resources/extensions/demo
   ```
   y écrire un `manifest.json` valide (`id: demo`, `type: link`, `entry_url: /doc`, `visibility.roles: ["admin"]`).
2. `php artisan db:seed --class=BundledExtensionSeeder --force` → l'extension `demo` apparaît (`status = available`).
3. Supprimer le dossier `demo` (`trash` ou `rm -r` selon l'outillage local), puis re-seeder.

**Attendu** :
- L'extension `demo` (`available`) disparaît du registre — log `[Extensions] Extension retirée du catalogue (manifest disparu)`.
- L'extension `doc` est **intacte**.
- Variante : si `demo` avait été passée à `integrated` avant suppression du dossier, elle est **CONSERVÉE** avec un log `[Extensions] Manifest disparu pour une extension INTÉGRÉE — conservée`.

---

## Section 2 — Validation du manifest (Story 54.1, AC2)

> Ces scénarios manipulent des manifests volontairement fautifs sous `resources/extensions/`. **Toujours retirer le manifest de test après le scénario** et re-seeder pour revenir à l'état nominal.

### Scénario 2.1 — Champ obligatoire manquant : rejet nommant le champ

1. Créer `resources/extensions/ko1/manifest.json` sans la clé `entry_url` (le reste valide).
2. `php artisan db:seed --class=BundledExtensionSeeder --force`.
3. Lire `storage/logs/laravel.log`.

**Attendu** :
- Log `warning` `[Extensions] Manifest rejeté — extension ignorée` avec `field: entry_url`, `reason: champ obligatoire manquant`, et le `path` du fichier.
- Aucune ligne `ko1` créée en base.
- **L'extension `doc` est chargée normalement** — un manifest fautif n'en casse aucun autre.

### Scénario 2.2 — Type inconnu

1. Créer `resources/extensions/ko2/manifest.json` avec `"type": "widget"`.
2. Re-seeder, lire les logs.

**Attendu** :
- `field: type`, message citant `widget` **et** la liste des types connus (`link, app`).
- Aucune ligne `ko2` créée ; les autres extensions chargées.

### Scénario 2.3 — Version de manifest non supportée (rejet STRICT)

1. Créer `resources/extensions/ko3/manifest.json` avec `"manifest_version": 2` (tout le reste valide).
2. Re-seeder.
3. Recommencer avec `"manifest_version": "1.0"` puis `"manifest_version": "v1"`.

**Attendu** :
- Les **trois** variantes sont rejetées avec `field: manifest_version` et un message citant la version reçue et les versions supportées.
- **Aucun repli tolérant** : `"1.0"` n'est PAS interprété comme la version 1.
- Seul `1` (entier) ou `"1"` (chaîne numérique) est accepté.

### Scénario 2.4 — La version prime sur le contenu

1. Créer un manifest à la fois hors version (`"manifest_version": 99`) **et** hors domaine (`"type": "widget"`).
2. Re-seeder, lire le log.

**Attendu** :
- La cause rapportée est `manifest_version`, **pas** `type`. Un manifest émis sous une version future ne doit pas être interprété selon les règles de la v1 — sinon la vraie cause est masquée.

### Scénario 2.5 — JSON illisible

1. Créer `resources/extensions/ko4/manifest.json` contenant `{ ceci n'est pas du json`.
2. Re-seeder.

**Attendu** :
- Log `[Extensions] Manifest JSON invalide — extension ignorée` avec le `json_error`.
- Les autres manifests sont chargés ; le seed sort en succès (pas de 500, pas d'exception remontée).

### Scénario 2.6 — Identifiant non-slug

1. Créer un manifest avec `"id": "Mon Ext"` (majuscules + espace).
2. Re-seeder.

**Attendu** :
- Rejet `field: id`, message expliquant la règle de slug (minuscules, chiffres, `_`, `-`).

---

## Section 3 — Bibliothèque `/admin/extensions` (Story 54.1, AC1)

### Scénario 3.1 — Accès et entrée de menu

1. Se connecter en `admin`.
2. Vérifier la sidebar → bloc « Serveur ».
3. Cliquer « Extensions ».

**Attendu** :
- L'entrée « Extensions » (icône pièce de puzzle) est présente sous « Réglages ».
- Elle est **active** (surbrillance) une fois sur `/admin/extensions` et le reste sur `/admin/extensions/{id}`.
- La page s'affiche en 200 avec le titre « Extensions ».

### Scénario 3.2 — Contenu de la bibliothèque

1. Sur `/admin/extensions`.

**Attendu** :
- Une carte « Documentation » avec :
  - l'icône du manifest (`fa-book-open`),
  - l'éditeur « SambaEdu »,
  - un badge de type « Lien »,
  - un badge de source « Embarquée (SambaEdu) »,
  - un badge d'état « Disponible »,
  - la version (`v1.0.0`).
- Un compteur « 1 extension(s) au catalogue — 0 intégrée(s) ».
- La carte est **cliquable** et mène à la fiche.

### Scénario 3.3 — État intégré affiché

1. En base : passer `doc` à `status = integrated` (cf. scénario 1.3).
2. Recharger `/admin/extensions`.

**Attendu** :
- Le badge passe à « Intégrée » (vert) et le compteur à « 1 intégrée(s) ».
- **Aucun bouton d'action** n'apparaît (54.1 est en lecture seule).
- Remettre `status = available` avant de continuer.

### Scénario 3.4 — État vide propre

1. Vider temporairement le registre : `php artisan tinker --execute="\App\Models\Extension::query()->delete();"`.
2. Recharger `/admin/extensions`.

**Attendu** :
- Bloc centré « Aucune extension » avec un texte d'explication — **pas** de grille cassée, pas de section vide, pas d'erreur.
- Re-seeder pour revenir à l'état nominal.

### Scénario 3.5 — Refus sans `server.admin`

1. Se déconnecter, se connecter en `enseignant.test`.
2. Vérifier la sidebar.
3. Taper `/admin/extensions` dans la barre d'URL.

**Attendu** :
- L'entrée « Extensions » n'apparaît **pas** dans la sidebar (bloc Serveur masqué).
- L'accès direct à l'URL est refusé (403 / redirection admin selon le middleware du groupe) — jamais un rendu partiel de la page.

---

## Section 4 — Fiche d'extension `/admin/extensions/{id}` (Story 54.1, AC1)

### Scénario 4.1 — Fiche de la tuile Documentation

1. En `admin`, depuis `/admin/extensions`, cliquer la carte « Documentation ».

**Attendu** :
- Titre « Documentation », flèche de retour vers la bibliothèque (infobulle « Retour à la bibliothèque »).
- Bloc identité : Version `1.0.0`, Éditeur `SambaEdu`, Source « Embarquée (SambaEdu) » + badge « Embarquée » + badge « Officielle », Identifiant `doc`, Cible `/doc`.
- Description issue du manifest.
- Badges « Lien » et « Disponible ».

### Scénario 4.2 — Listes vides rendues proprement

1. Sur la fiche « Documentation » (`scopes: []`, `dependencies: []`).

**Attendu** :
- Section « Autorisations demandées » : compteur `0` + phrase « Aucun scope demandé. »
- Section « Dépendances » : compteur `0` + phrase « Aucune dépendance. »
- **Aucune** section cassée, aucune liste à puces vide, aucun `[]` affiché brut.

### Scénario 4.3 — Scopes et dépendances non vides

1. Créer un manifest de test `resources/extensions/demo2/manifest.json` avec
   `"scopes": ["profile","groups"]` et `"dependencies": ["doc"]`.
2. Re-seeder, ouvrir la fiche de `demo2`.

**Attendu** :
- Les 2 scopes et la dépendance apparaissent en badges monospace.
- Le texte de la section rappelle que **rien n'est accordé aujourd'hui** (information de transparence).
- Nettoyer le manifest de test et re-seeder.

### Scénario 4.4 — Public visé (rôles métier)

1. Sur la fiche « Documentation ».

**Attendu** :
- Section « Public visé » listant `admin`, `prof`, `eleve` — les rôles **métier** du manifest, jamais des permissions applicatives.
- Le texte précise que l'autorisation réelle reste du ressort de l'extension.
- ⚠️ 54.1 **stocke** cette visibilité ; c'est la Story 54.3 qui la **résout** dans le lanceur.

### Scénario 4.5 — Identifiant inconnu → 404

1. Ouvrir `/admin/extensions/999999`.

**Attendu** : page 404 SE5 (pas une 500, pas une fiche vide).

### Scénario 4.6 — Identifiant non numérique → 404 de routage

1. Ouvrir `/admin/extensions/abc`.

**Attendu** : 404 — la route est bornée par `whereNumber('id')`, la requête n'atteint jamais le composant.

### Scénario 4.7 — Refus sans `server.admin`

1. En `enseignant.test`, ouvrir directement `/admin/extensions/1`.

**Attendu** : refus (403 / redirection admin), jamais l'affichage de la fiche.

---

## Section 5 — Frontière avec la sync amont (Story 54.1, AC3 / NFR14)

> **Pourquoi cette section existe** : le catalogue applicatif LOCAL a déjà été effacé par la sync amont sur ce projet. Le registre d'extensions doit rester hors de portée de cette chaîne. L'isolement est **par construction** (aucune FK, aucun listener, aucun service commun) et verrouillé par `tests/Feature/ControlHub/UpstreamSyncExtensionsBoundaryTest.php` — cette section est le contrôle manuel de dernier recours avant mise en production.

### Scénario 5.1 — Ingestion d'un contrat amont : registre intact

1. Noter l'état du registre :
   ```php
   \App\Models\ExtensionSource::all(['id','key','updated_at'])->toArray();
   \App\Models\Extension::all(['id','key','status','updated_at'])->toArray();
   ```
2. Déclencher une réception de contrat amont (canal controlHub réel ou
   `ControlHubContractIngestionService::ingest($payload)` en tinker), avec un
   catalogue d'apps **ne contenant pas** les clés locales.
3. Re-lire l'état du registre.

**Attendu** :
- Lignes, `status` et `updated_at` **strictement identiques**.
- La cascade amont a bien tourné par ailleurs (groupes imposés créés / apps hors catalogue retirées, selon le contrat) — c'est ce qui rend l'observation significative.

### Scénario 5.2 — Rupture du lien amont : registre intact

1. Noter l'état du registre.
2. `php artisan controlhub:sever-link` (ou le canal API de rupture).
3. Re-lire l'état du registre.

**Attendu** : registre inchangé ; le lien est bien passé à `severed` et la transition est tracée dans `controlhub_link_audit_logs`.

### Scénario 5.3 — Application d'un manifeste de sync : registre intact

1. Noter l'état du registre.
2. Appliquer un manifeste de sync **vide** (branche `pass3Cleanup`, la plus destructive).
3. Re-lire l'état du registre.

**Attendu** : registre inchangé, y compris les `updated_at`.

---

## Section 6 — Correctifs de review 54.1

> Ajoutée après la review de la Story 54.1. Deux durcissements du chargement de la source embarquée.

### Scénario 6.1 — Racine des manifests introuvable : le catalogue est PRÉSERVÉ

**Contexte** : accident de déploiement — `resources/extensions/` absent de l'arbre livré, `EXTENSIONS_BUNDLED_PATH` mal résolu, ou `config:cache` figé sur un chemin d'une autre machine.

1. Partir d'un registre peuplé (`php artisan db:seed --class=BundledExtensionSeeder`), vérifier la bibliothèque non vide sur `/admin/extensions`.
2. Pointer la config sur un chemin inexistant : `EXTENSIONS_BUNDLED_PATH=/tmp/nexistepas` puis `php artisan config:clear`.
3. Rejouer le seed.

**Attendu** : la synchro sort en **no-op** — `pruned: 0`, `loaded: 0`. Un `Log::warning` « Racine des manifests embarqués introuvable — synchro ignorée (catalogue PRÉSERVÉ) » cite le chemin fautif. **La bibliothèque affiche toujours ses extensions.**

**Pourquoi ce scénario existe** : avant correctif, une racine absente et une racine vide étaient indistinguables (`[]` dans les deux cas) — le prune ne voyait aucune clé « vue » et **supprimait tout le catalogue embarqué `available`**. C'est le sinistre déjà vécu sur le catalogue applicatif local, rejoué sur le registre d'extensions.

### Scénario 6.2 — Racine présente mais vidée : le prune s'applique bien

**Contre-épreuve du 6.1** : la garde ne doit pas neutraliser le prune légitime.

1. Registre peuplé, puis supprimer les dossiers de manifests **en gardant** `resources/extensions/`.
2. Rejouer le seed.

**Attendu** : les lignes `available` disparues sont bien supprimées (`pruned` > 0), les `integrated` conservées (cf. 1.4).

### Scénario 6.3 — Un objet JSON n'est pas une liste

Déposer un manifest avec `"visibility": {"roles": {"a": "admin"}}` (objet au lieu de tableau), puis un autre avec `"scopes": {"x": "profile"}`.

**Attendu** : les deux sont **rejetés**, log nommant `visibility.roles` / `scopes`. Avant correctif ils étaient acceptés et ré-indexés silencieusement en `["admin"]` / `["profile"]`.

**Portée réelle** : sans effet sur la source embarquée (dépôt contrôlé) — décisif dès l'**Epic 56**, quand des sources distantes fourniront des manifests non contrôlés.

---

## Section 7 — Intégrer / désinstaller une extension link (Story 54.2)

**Contexte** : depuis cette story, les cartes de la bibliothèque et la fiche d'extension portent des boutons d'action pour le type `link` — la carte de 54.1 (un `<a href>` unique) a été restructurée en `<div>` racine + zone titre cliquable + pied `card-actions` hors du lien. ⚠️ Le scénario 3.3 (Section 3) décrit l'état AVANT 54.2 (« aucun bouton d'action ») — il reste vrai pour un type `app` (aucun bouton avant l'Epic 56), mais plus pour un type `link`. Chaque geste écrit une ligne dans `extension_audit_logs` (FR36 socle) DANS LA MÊME transaction que la mutation de `status`.

> Repartir de l'état nominal avant/après chaque scénario : `status = available` pour `doc`, table `extension_audit_logs` vidée si besoin (`php artisan tinker --execute="\App\Models\ExtensionAuditLog::query()->delete();"`).

### Scénario 7.1 — Intégrer depuis la bibliothèque

1. En `admin`, sur `/admin/extensions`, repérer la carte « Documentation » (`status = available`).
2. Cliquer le bouton « Intégrer » du pied de carte (PAS la zone titre — celle-ci mène toujours à la fiche).

**Attendu** :
- La transition est **immédiate** : pas de spinner de progression, pas d'installation de composants.
- Le badge passe à « Intégrée », le bouton « Intégrer » est remplacé par « Désinstaller » au re-render.
- Un toast de succès confirme l'opération.
- Le compteur d'en-tête (« N intégrée(s) ») s'incrémente.

### Scénario 7.2 — Intégrer depuis la fiche

1. Remettre `doc` à `available` (tinker, cf. scénario 1.3).
2. Ouvrir la fiche `/admin/extensions/{id}`.
3. Cliquer « Intégrer » dans le bandeau d'actions en haut de page (`<x-slot:actions>`).

**Attendu** :
- Même comportement que 7.1 : transition immédiate, badge d'état mis à jour, toast de succès, le bouton devient « Désinstaller ».

### Scénario 7.3 — Désinstaller avec confirmation par modale

1. Partir d'une extension `doc` `integrated` (via 7.1 ou 7.2).
2. Cliquer « Désinstaller » (carte OU fiche).
3. Vérifier le contenu de la modale : titre « Désinstaller l'extension », texte expliquant qu'aucun composant n'est installé pour une extension lien (rien à nettoyer), **aucun champ de saisie de confirmation texte**.
4. Cliquer le bouton rouge « Désinstaller » du footer de la modale.

**Attendu** :
- L'extension redevient `available` : badge « Disponible », bouton redevenu « Intégrer ».
- Toast de succès.
- La modale se ferme.

### Scénario 7.4 — Annulation (bouton Annuler ou fermeture de la modale)

1. Extension `doc` `integrated`. Cliquer « Désinstaller » pour ouvrir la modale.
2. Cliquer « Annuler » (ou le bouton ✕, ou cliquer hors de la modale si le comportement le permet).

**Attendu** :
- La modale se ferme.
- **Rien ne change** : badge toujours « Intégrée », aucun toast, `status` inchangé en base.
- Vérification tinker : `\App\Models\ExtensionAuditLog::query()->count()` inchangé (aucune ligne créée par l'annulation).

### Scénario 7.5 — No-op : double-clic / re-jeu de l'opération

1. Extension `doc` `available`. Cliquer « Intégrer » une première fois (elle passe `integrated`).
2. Recliquer « Intégrer » sur la même carte/fiche (double-clic, onglet dupliqué, ou second admin concurrent).

**Attendu** :
- Le second clic est un **no-op propre** : le toast affiché est un **toast d'information** (« déjà intégrée »), pas un toast de succès.
- `status` reste `integrated`, **aucune écriture** (`updated_at` de l'extension inchangé).
- **Aucune nouvelle ligne** dans `extension_audit_logs` — un seul enregistrement `integrate` existe pour cette extension malgré les deux clics.
- Symétrique pour « Désinstaller » sur une extension déjà `available`.

### Scénario 7.6 — Vérification tinker des lignes `extension_audit_logs`

1. Après avoir intégré PUIS désinstallé `doc` (7.1 puis 7.3) :
   ```php
   \App\Models\ExtensionAuditLog::orderBy('id')->get([
       'id', 'extension_id', 'extension_key', 'extension_name', 'action', 'actor_user_id', 'actor_login', 'created_at',
   ])->toArray();
   ```

**Attendu** :
- Exactement 2 lignes, dans l'ordre : `action = integrate` puis `action = uninstall`.
- Chaque ligne porte `extension_key = 'doc'`, `extension_name = 'Documentation'`, `actor_login` = le login de l'admin connecté, `created_at` non nul.
- Aucune colonne `updated_at` (table append-only, `public $timestamps = false`).
- Tentative de modification d'une ligne existante (`$log = \App\Models\ExtensionAuditLog::first(); $log->action = 'x'; $log->save();`) lève une `LogicException` — le journal est bien append-only.

### Scénario 7.7 — Type `app` : aucun bouton, refus fail-closed si forcé

1. S'il existe une extension de type `app` au registre (sinon en créer une en tinker pour le test), ouvrir sa carte et sa fiche.

**Attendu** :
- **Aucun bouton** « Intégrer » ni « Désinstaller » n'apparaît (ni carte, ni fiche) — rien à proposer avant l'Epic 56.
- (Contrôle développeur, pas un geste UI) : un appel direct au service (`app(\App\Services\Extensions\ExtensionLifecycleService::class)->integrate($id, $admin)`) sur cette extension lève une exception explicite, sans mutation ni ligne d'audit.

### Scénario 7.8 — Refus sans `server.admin`

1. En `enseignant.test` (sans `server.admin`), tenter d'atteindre les actions (l'entrée de menu et les boutons ne sont de toute façon pas visibles sans la permission, cf. Section 3.5 / 4.7).

**Attendu** : comportement identique à 54.1 — accès refusé avant même d'atteindre un bouton d'action.

---

## Section 8 — Correctifs de review 54.2

> Ajoutée après la review opus de la Story 54.2. Trois durcissements observables en QA.

### Scénario 8.1 — Double-clic sur « Désinstaller » depuis la bibliothèque

1. Extension `link` `integrated`. Cliquer « Désinstaller », puis **cliquer deux fois** rapidement sur le bouton de confirmation de la modale (le bouton reste cliquable tant que la première réponse n'est pas revenue).

**Attendu** : un toast de **succès** puis un toast d'**information** (« déjà disponible »). **Jamais** un toast d'erreur, et **jamais** un message citant « Extension #0 ». Une seule ligne `uninstall` dans `extension_audit_logs`.

**Pourquoi ce scénario existe** : la confirmation remettait la cible à `0` avant d'appeler le service ; le second clic partait donc avec un identifiant bidon et produisait une erreur technique, là où l'AC3 exige explicitement un no-op propre pour le double-clic. Le scénario 7.5 documentait ce comportement — le code ne l'avait pas.

### Scénario 8.2 — Écran périmé : le no-op rafraîchit au lieu de contredire

1. Ouvrir `/admin/extensions` dans **deux onglets** (ou deux sessions admin).
2. Dans l'onglet A, intégrer `doc`. **Ne pas recharger l'onglet B.**
3. Dans l'onglet B, cliquer « Intégrer » sur la même carte.

**Attendu** : toast d'information « déjà intégrée » **et** la carte de l'onglet B bascule immédiatement sur le badge « Intégrée » avec le bouton « Désinstaller » ; le compteur d'en-tête « N intégrée(s) » se corrige.

**Pourquoi ce scénario existe** : c'est le seul cas réel où le no-op survient. Sans rafraîchissement, l'application affirmait « déjà intégrée » tout en continuant d'afficher « Disponible / Intégrer » — le message et l'écran se contredisaient, et l'admin n'avait aucun moyen de voir la réalité sans recharger la page.

4. **Variante fiche** : sur `/admin/extensions/{id}`, faire disparaître l'extension du registre (retirer son manifest puis re-seeder) pendant que la fiche est ouverte, puis cliquer une action.
   **Attendu** : retour automatique à la bibliothèque (la fiche n'a plus d'objet), pas de 404 brutal ni de fiche figée sur un état mort.

### Scénario 8.3 — La trace d'audit survit à la disparition de son extension

1. Intégrer puis désinstaller une extension de test (2 lignes d'audit, `status = available`).
2. Retirer son manifest de `resources/extensions/`, re-seeder (le prune emporte la ligne `available`).
3. `\App\Models\ExtensionAuditLog::orderBy('id')->get(['extension_id','extension_key','extension_name','action'])->toArray();`

**Attendu** : les **2 lignes subsistent**, `extension_id` à `null` (FK dénouée par `ON DELETE SET NULL`), `extension_key`/`extension_name` toujours lisibles. Le re-seed ne lève **aucune** `QueryException`.

**Pourquoi ce scénario existe** : c'est le seul endroit où 54.2 peut casser un comportement de 54.1 — `pruneDisappeared()` supprime sans `try/catch`, et une extension intégrée puis désinstallée est prunable tout en portant un historique. Une FK mal émise ou réécrite en `restrict` ferait échouer `syncBundled()`, donc `db:seed` et `scripts/update.sh`, sur toute extension ayant un passé.

---

## Section 9 — Lanceur d'applications navbar (Story 54.3)

> **DERNIÈRE section de l'Epic 54** — le lanceur « gaufre » de la navbar, filtré par rôle métier, clôt l'epic. Composant : `resources/views/components/organisms/app-launcher.blade.php` (SFC Livewire `<livewire:organisms.app-launcher />`, inséré en tête du groupe d'icônes de droite de `navbar.blade.php`, visible sur **toutes** les pages de l'application). Résolution du rôle : `App\Models\User::businessRoles()`.

### Scénario 9.1 — Tuile Documentation visible selon le rôle, après intégration

**Pré-requis** : extension `doc` (`link` → `/doc`) **intégrée** (Section 7, scénario 7.1) avec `visibility.roles` couvrant au moins `prof`/`eleve`/`admin` selon le manifest livré.

1. Se connecter en `enseignant.test` (rôle `prof`, sans `server.admin`) et ouvrir la gaufre (icône `fa-table-cells`) dans la navbar, sur n'importe quelle page de l'application.

**Attendu** :
- La gaufre est présente et cliquable sur toute page (elle ne dépend d'aucun droit `server.admin`).
- Le panneau affiche une grille de tuiles avec au moins la tuile Documentation (icône + nom « Documentation »).
- Cliquer la tuile ouvre `/doc` dans un **nouvel onglet** — l'onglet SE5, et donc le lanceur, reste ouvert (FR16).

2. Répéter en `admin` (`super-admin`) et en un compte élève de test : la tuile Documentation doit apparaître pour chaque rôle couvert par `visibility.roles` du manifest.

### Scénario 9.2 — Tuile absente pour un rôle hors visibilité

1. Créer (tinker ou fixture) une extension `link` intégrée dont `manifest.visibility.roles = ["admin"]` uniquement.
2. Se connecter en `enseignant.test` (rôle `prof`, pas `super-admin`) et ouvrir la gaufre.

**Attendu** : la tuile de cette extension **n'apparaît pas** dans la grille — seule une tuile dont `visibility.roles` intersecte les rôles métier de l'utilisateur (`prof` ici) est affichée. Aucune erreur, aucune grille cassée.

### Scénario 9.3 — La tuile masquée n'est PAS une protection (FR14)

1. Reprendre l'extension de 9.2 (tuile masquée pour `enseignant.test`).
2. Toujours en `enseignant.test`, taper directement l'URL cible de l'extension (`entry_url` du manifest, ex. `/doc`) dans le navigateur.

**Attendu** : l'accès direct **fonctionne exactement comme si la tuile avait été cliquée** — masquer une tuile au lanceur ne bloque **rien** côté SE5 : aucune route, aucun middleware, aucune garde n'a été ajoutée devant `entry_url` par cette story. L'autorisation réelle appartient à la cible elle-même (les extensions `app` la feront par claims SSO, Epics 55+). Ce comportement est **voulu**, pas un bug — c'est la doctrine FR14 : le lanceur est un affichage, pas un contrôle d'accès.

### Scénario 9.4 — Disparition de la tuile après désinstallation (solde l'AC d'epic 54.2)

1. Extension `doc` `integrated`, tuile visible en 9.1.
2. En `admin`, aller sur `/admin/extensions` et cliquer « Désinstaller » (Section 7, scénario 7.3).
3. Recharger n'importe quelle page (ou rouvrir la gaufre sans recharger — un nouveau rendu du composant suffit).

**Attendu** : la tuile Documentation **disparaît** du lanceur au rendu suivant. C'est l'AC d'epic 54 « sa tuile disparaît du lanceur », différé par 54.2 et vérifié ici pour de bon.

### Scénario 9.5 — État vide propre

1. En un utilisateur dont les rôles métier n'intersectent aucune tuile intégrée (ex. `role = 'autre'` sans `super-admin`), **ou** sur une instance sans aucune extension intégrée, ouvrir la gaufre.

**Attendu** :
- La gaufre reste présente et cliquable (elle ne disparaît jamais).
- Le panneau affiche un message explicite (« Aucune application disponible. ») — jamais une grille vide silencieuse, jamais une erreur, jamais une page blanche.

### Scénario 9.6 — Nouvel onglet, le lanceur reste ouvert (FR16)

1. Depuis n'importe quelle page, ouvrir la gaufre et cliquer une tuile `link`.

**Attendu** : la cible s'ouvre dans un **nouvel onglet** du navigateur (`target="_blank" rel="noopener"`). L'onglet d'origine (SE5, avec le lanceur) reste ouvert sur la même page — « revenir au lanceur » = revenir à cet onglet, sans chrome de retour à construire (réservé aux extensions `app`, starter kit Epics 56-58).

### Scénario 9.7 — L'icône d'aide 52.8 coexiste avec la gaufre (décision documentée)

1. Sur une page quelconque, observer le groupe d'icônes de droite de la navbar : icône d'aide « ? » (`/doc`, rendue seulement si la doc est publiée), puis la gaufre du lanceur, l'une à côté de l'autre.

**Attendu** :
- Les deux affordances coexistent, **sans conflit ni doublon fonctionnel visible** — l'icône d'aide est l'aide contextuelle du produit (52.8, indépendante d'un acte d'intégration) ; la gaufre est le lanceur d'applications intégrées (piloté par le registre).
- Désinstaller l'extension Documentation (scénario 9.4) fait disparaître la tuile du lanceur, mais **ne touche pas** l'icône d'aide, qui reste fonctionnelle : ce sont deux mécanismes distincts, décision tranchée à la clôture de l'epic (point hérité de la review 54.1, `codeReviews/54-1.md#3`).
- Aucun diff sur l'icône d'aide elle-même n'a été introduit par cette story.

---

## Section 10 — Correctifs de review 54.3

> Ajoutée après la review opus de la Story 54.3. **Le scénario 10.1 est le plus important de tout ce runbook** : il porte sur une indisponibilité totale du produit.

### Scénario 10.1 — Mise à jour en cours : SE5 reste debout sans la table `extensions`

**Contexte** : `scripts/update.sh` sert le code neuf pendant tout `composer` + `npm` + build VitePress **avant** de lancer `migrate --force`. La release qui livre l'Epic 54 traverse donc forcément une fenêtre de plusieurs minutes où la table `extensions` n'existe pas encore, alors que la navbar — rendue sur **toutes** les pages — l'interroge.

1. Sur une VM de test, renommer temporairement la table : `ALTER TABLE extensions RENAME TO extensions_bak;`
2. Se connecter à SE5 et naviguer sur **plusieurs pages sans rapport avec les extensions** : `/app/users`, `/app/parc`, `/admin/settings`, une page legacy embarquée.
3. Restaurer : `ALTER TABLE extensions_bak RENAME TO extensions;`

**Attendu** :
- **Toutes les pages répondent normalement (200)**, jamais une 500.
- Le lanceur « gaufre » reste présent dans la navbar et affiche « Aucune application disponible. »
- L'exception est **journalisée** dans `storage/logs/laravel.log` (jamais silencieuse).

**Pourquoi ce scénario existe** : sans garde, une table absente faisait tomber l'intégralité de SE5 — y compris des pages sans aucun lien avec les extensions. Le symptôme avait d'ailleurs été observé en test (une page d'administration ISO Windows cessait de se rendre) et d'abord traité comme un problème de test, en recopiant la table dans le test concerné. C'était masquer la cause : le correctif est la dégradation gracieuse du lanceur, et ce scénario est ce qui la vérifie.

### Scénario 10.2 — L'état vide se masque réellement quand il y a des tuiles

1. Registre vide (ou rôle sans tuile visible) : ouvrir la gaufre → « Aucune application disponible. » **visible**.
2. Intégrer la Documentation, recharger, rouvrir la gaufre.

**Attendu** : la tuile apparaît **et** le message d'état vide **disparaît**. Les deux blocs sont toujours dans le DOM (c'est ce qui évite un `@if` de premier niveau et donc un 500 au re-render) — c'est la classe `hidden` qui bascule, pas la présence.

**Pourquoi ce scénario existe** : le bloc étant rendu inconditionnellement, tester sa seule présence était tautologique. Retirer le ternaire — donc afficher « Aucune application disponible. » **sous** les tuiles de tous les utilisateurs qui en ont — laissait la suite de tests entièrement verte.

### Scénario 10.3 — Un administratif voit la Documentation

Se connecter avec un compte dont `users.role` vaut `administratif` (ou `administratifs`) et ouvrir la gaufre.

**Attendu** : la tuile Documentation est présente.

**Pourquoi ce scénario existe** : le contrat manifest v1 documente `admin`/`prof`/`eleve`, et le manifest livré ne visait que ces trois rôles — une population réelle, écrite telle quelle par la sync, ouvrait donc une gaufre systématiquement vide le jour de la clôture de l'epic. Le rôle a été ajouté au manifest (une chaîne, aucun code).

### Scénario 10.4 — Une cible de manifest à schéma dangereux est refusée

Déposer un manifest de test avec `"entry_url": "javascript:alert(1)"` (puis `data:text/html,…`, puis `//evil.example`), re-seeder.

**Attendu** : chaque manifest est **rejeté**, log nommant `entry_url`. Les chemins absolus (`/doc`) et les URL `http(s)` restent acceptés.

**Portée réelle** : sans effet sur la source embarquée (dépôt contrôlé) — décisif dès l'**Epic 56**, quand des sources distantes fourniront des manifests non contrôlés. La Story 54.3 est celle qui a fait d'`entry_url` un `href` cliquable exposé à tous les rôles visés.

---

## Section 11 — Fournisseur OIDC : registre des clients et flux d'autorisation (Story 55.1)

> **Première story de l'Epic 55 (SSO).** SE5 devient **fournisseur d'identité** : une extension enregistrée obtient un jeton d'identité pour l'utilisateur SE5 courant, sans re-login ni secret partagé. Cette section couvre l'exploitation (clés, clients) et les quatre familles de comportement : flux nominal, découverte, refus, reprise après login.
>
> **Ce qui n'est PAS ici** : les claims métier `name`/`role`/`groups` et `/userinfo` (Story 55.2), l'app-témoin et la suite d'attaque (Story 55.3), les scopes consentis et le provisioning automatique du client à l'installation d'une extension (Epic 56).

**Prérequis spécifiques à cette section**

- `php artisan migrate` a joué `2026_07_28_300000_create_oidc_provider_tables.php` (3 tables : `oidc_clients`, `oidc_authorization_codes`, `oidc_access_tokens`).
- `OIDC_ISSUER` aligné sur l'URL réellement servie par le vhost (sans slash final). À défaut, `APP_URL` est utilisée — une divergence casse la validation côté client.
- Un outil capable de fabriquer un couple PKCE. En ligne de commande :
  ```bash
  VERIFIER=$(openssl rand -hex 32)
  CHALLENGE=$(printf '%s' "$VERIFIER" | openssl dgst -binary -sha256 | openssl base64 | tr '+/' '-_' | tr -d '=')
  echo "verifier=$VERIFIER"; echo "challenge=$CHALLENGE"
  ```

### Scénario 11.1 — Initialisation des clés : idempotence et permissions

1. `php artisan oidc:keys:init` → statut `initialized`.
2. Relancer la même commande **sans option**.
3. `ls -l storage/keys/oidc/`
4. `php artisan oidc:keys:init --force` (répondre `non` à la confirmation, puis rejouer et répondre `oui`).

**Attendu** :
- 1er passage : `storage/keys/oidc/private.pem` et `public.pem` créés.
- 2e passage : `already_initialized`, **fichiers strictement inchangés** (comparer `ls --full-time`).
- Permissions : `private.pem` en `0600`, `public.pem` en `0644`, propriétaire = utilisateur du pool PHP-FPM (`www-admin` par défaut).
- `--force` refusé → rien ne bouge. `--force` accepté → nouvelle paire **et** sauvegarde `private.pem.bak-<horodatage>`.

**Pourquoi ce scénario existe** : `scripts/update.sh` rejoue les commandes d'initialisation à **chaque** déploiement, sur **chaque** instance. Si `oidc:keys:init` écrasait la paire, tous les id_tokens en circulation deviendraient invérifiables et toutes les extensions perdraient le SSO — silencieusement, à chaque mise à jour. L'idempotence n'est pas un confort, c'est la condition de survie de la fonctionnalité.

Le chown importe autant : la commande est lancée en **root**, une clé `0600 root:root` est **illisible par PHP-FPM**, et le symptôme serait « le SSO ne marche pas » sans aucune trace évidente.

### Scénario 11.2 — Enregistrement d'un client : le secret n'est affiché qu'une fois

```bash
php artisan oidc:client:register "App de test" \
  --redirect-uri=https://exemple.test/callback \
  --extension=doc
```

**Attendu** :
- Sortie : `client_id` (32 hexadécimaux), `client_secret` sous un avertissement explicite, liste des URI déclarées, rappel de la configuration côté extension.
- En base : `SELECT client_id, client_secret_hash, extension_key, enabled FROM oidc_clients;` — la colonne `client_secret_hash` contient **64 caractères hexadécimaux** (un sha256), et **le secret affiché n'apparaît nulle part**.
- `--extension=inexistante` → commande en échec, **aucune ligne créée**.
- `--redirect-uri=javascript:alert(1)` (idem `data:…`, `//hote/cb`) → refusée. `https://…` et `/chemin-interne` acceptés.
- Sans aucun `--redirect-uri` → refusée.

**Pourquoi ce scénario existe** : NFR3 — un secret stocké en clair transforme un accès en lecture à la base en compromission de l'identité de tous les utilisateurs de l'extension. Le bornage des schémas d'URI reprend le correctif `entry_url` de la review 54.3 : une `redirect_uri` en `javascript:` ou `//hôte` placée dans un en-tête `Location:` détournerait l'utilisateur — **et le code d'autorisation avec lui**.

### Scénario 11.3 — Découverte : discovery et JWKS

```bash
curl -s https://<host>/.well-known/openid-configuration | jq
curl -s https://<host>/oidc/jwks | jq
```

**Attendu (discovery)** : `issuer` identique à `OIDC_ISSUER`, `authorization_endpoint`, `token_endpoint`, `jwks_uri`, `response_types_supported: ["code"]`, `grant_types_supported: ["authorization_code"]`, `id_token_signing_alg_values_supported: ["RS256"]`, `code_challenge_methods_supported: ["S256"]`, `token_endpoint_auth_methods_supported: ["client_secret_basic","client_secret_post"]`.

**Attendu (JWKS)** : une clé, `kty: "RSA"`, `use: "sig"`, `alg: "RS256"`, `kid` égal à `OIDC_JWT_KID`, `n` et `e` en base64url **sans `=` ni `+` ni `/`**. Aucun champ `d`, aucune occurrence de `PRIVATE KEY`.

**Les deux répondent sans authentification** (ils ne contiennent que des métadonnées de protocole et une clé publique).

**Vérifications négatives** :
- `userinfo_endpoint` est **absent** de la discovery. Il arrivera avec la Story 55.2 ; l'annoncer avant qu'il existe ferait échouer tout client qui suit la discovery à la lettre.
- Renommer temporairement `storage/keys/oidc/public.pem` puis rappeler `/oidc/jwks` → **503**, jamais un `{"keys": []}` en 200. Un JWKS vide servi en 200 serait mis en cache par les clients et casserait les vérifications longtemps après la remise en place de la clé. Restaurer le fichier.

### Scénario 11.4 — Flux complet avec un client de test

1. Se connecter à SE5 dans le navigateur (session ouverte).
2. Ouvrir l'URL suivante en remplaçant `<CLIENT_ID>` et `<CHALLENGE>` :
   ```
   https://<host>/oidc/authorize?response_type=code&client_id=<CLIENT_ID>
     &redirect_uri=https://exemple.test/callback&scope=openid&state=abc123
     &code_challenge=<CHALLENGE>&code_challenge_method=S256&nonce=xyz789
   ```
3. Le navigateur est redirigé vers `https://exemple.test/callback?code=…&state=abc123` (la cible n'existe pas : relever le `code` dans la barre d'adresse).
4. Échanger le code :
   ```bash
   curl -s -u '<CLIENT_ID>:<CLIENT_SECRET>' https://<host>/oidc/token \
     -d grant_type=authorization_code \
     -d code=<CODE> \
     -d redirect_uri=https://exemple.test/callback \
     -d code_verifier=<VERIFIER> | jq
   ```
5. Décoder l'`id_token` sur https://jwt.io **ou** localement :
   `echo <ID_TOKEN> | cut -d. -f2 | base64 -d 2>/dev/null | jq`

**Attendu** :
- **Aucun formulaire de login** n'apparaît à l'étape 2 (c'est FR17 : le SSO, pas une seconde authentification).
- Le `state` d'origine est relayé tel quel.
- Réponse d'échange : `access_token`, `token_type: "Bearer"`, `expires_in: 600`, `id_token`, `scope`. En-tête `Cache-Control: no-store`.
- **Header** de l'id_token : `alg: "RS256"`, `typ: "JWT"`, `kid` présent et égal à celui du JWKS.
- **Claims** : `iss` (= issuer), `sub` (= login SE5 de l'utilisateur connecté), `aud` (= `client_id`), `iat`/`exp` espacés de **300 s au plus**, `nonce: "xyz789"`, `jti`.
- **Et rien d'autre** : ni `name`, ni `role`, ni `groups` — ils appartiennent à la Story 55.2.
- Journal : `storage/logs/oidc/oidc-<date>.log` contient `oidc.authorize.granted` puis `oidc.token.issued`. **Aucune ligne ne contient le code clair, l'access_token, l'id_token complet ni un secret** — seulement `client_id`, `kid`, `jti` et un `code_hash_prefix` de 8 caractères.

**Pourquoi ce scénario existe** : c'est le contrat que toutes les extensions liront. Le PRD nomme le SSO « le risque n°1 » du système d'extensions ; la présence du `kid`, la brièveté du TTL et l'absence de claims non décidés sont les trois points qu'une régression casserait en silence.

### Scénario 11.5 — Le rejeu d'un code est refusé

Rejouer **exactement** la commande `curl` de l'étape 4 du scénario 11.4.

**Attendu** : `HTTP 400`, corps `{"error":"invalid_grant", …}`, aucun nouveau jeton. Journal : `oidc.token.rejected` avec `code: oidc.code_consumed`.

**Variantes à dérouler** (chacune doit échouer, et chacune écrit sa ligne de journal avec un code interne distinct) :

| Variante | Réponse attendue | Code au journal |
|---|---|---|
| Attendre 60 s avant l'échange | `invalid_grant` 400 | `oidc.code_expired` |
| `code_verifier` incorrect | `invalid_grant` 400 | `oidc.code_verifier_mismatch` |
| Re-tenter ensuite avec le **bon** verifier | `invalid_grant` 400 | `oidc.code_consumed` |
| `redirect_uri` différente de celle de l'autorisation | `invalid_grant` 400 | `oidc.redirect_uri_mismatch` |
| Mauvais `client_secret` | `invalid_client` **401** + `WWW-Authenticate: Basic` | `oidc.client_auth_failed` |
| `grant_type=client_credentials` | `unsupported_grant_type` 400 | `oidc.unsupported_grant_type` |

**Pourquoi ce scénario existe** : deux invariants s'y vérifient. D'abord l'usage unique — un code rejouable annulerait tout l'intérêt du TTL court. Ensuite l'**asymétrie assumée** entre le journal et la réponse : le corps HTTP dit toujours la même chose (`The authorization code is invalid, expired or already used.`), parce que distinguer « inconnu » de « expiré » indiquerait à un attaquant qu'il a mis la main sur un code réel. Le diagnostic fin, lui, est dans le journal.

Noter la 3ᵉ ligne du tableau : un `code_verifier` faux **brûle le code**. Il a été présenté par quelqu'un qui le possède — il n'y a pas de seconde chance.

### Scénario 11.6 — Refus non redirigeables : SE5 n'est pas un open-redirector

Depuis un navigateur avec une session SE5 active, ouvrir successivement :

1. `…/oidc/authorize?response_type=code&client_id=INEXISTANT&redirect_uri=https://attaquant.example/collecte&scope=openid&state=s&code_challenge=x&code_challenge_method=S256`
2. La même URL avec un `client_id` **valide** mais `redirect_uri=https://attaquant.example/collecte`
3. La même URL avec un `client_id` **révoqué** (`php artisan oidc:client:revoke <client_id>`)

**Attendu pour les trois** :
- **HTTP 400**, page « Connexion impossible » sobre, en français.
- **Aucune redirection** : `curl -sI` ne renvoie **pas** d'en-tête `Location`.
- La page **ne divulgue ni** la liste des `redirect_uris` déclarées, **ni** l'existence du client, **ni** le nom de l'extension. Elle affiche uniquement le code d'erreur normalisé.
- `SELECT count(*) FROM oidc_authorization_codes;` inchangé — **aucun code émis**.
- Journal : `oidc.authorize.rejected` avec `kind: local` et le code fin (`oidc.client_unknown`, `oidc.redirect_uri_mismatch`, `oidc.client_disabled`).

**Pourquoi ce scénario existe** : c'est la règle cardinale d'OAuth. Rediriger vers une `redirect_uri` non validée — **même pour annoncer une erreur** — ferait de SE5 un open-redirector réputé de confiance, et enverrait le message de refus (donc l'information) à celui qui a fabriqué l'URL. Le cas 2 est le plus important : le client est parfaitement valide, seule l'URI est falsifiée ; c'est le scénario d'attaque réel.

### Scénario 11.7 — Refus redirigeables : le client mal configuré reçoit une réponse exploitable

Toujours avec une session active et un client **valide**, avec la `redirect_uri` **déclarée** :

| Variante de l'URL | Attendu dans la redirection |
|---|---|
| `code_challenge` retiré | `?error=invalid_request&state=…` |
| `code_challenge_method=plain` | `?error=invalid_request&state=…` |
| `code_challenge_method` retiré | `?error=invalid_request&state=…` |
| `response_type=token` | `?error=unsupported_response_type&state=…` |
| `scope=profile` (sans `openid`) | `?error=invalid_scope&state=…` |

**Attendu pour toutes** :
- **HTTP 302 vers la `redirect_uri` DÉCLARÉE** (pas celle fournie arbitrairement — elles coïncident ici, c'est le point).
- `state` relayé, **aucun paramètre `code`**, aucune ligne dans `oidc_authorization_codes`.
- Journal : `oidc.authorize.rejected` avec `kind: redirect`.

**Contrôle positif à ne pas oublier** : `scope=openid profile` **fonctionne** (le scope composé est légitime), et `scope=openidx` est **refusé**. Sans ces deux vérifications, on ne saurait pas si la validation découpe la liste ou fait une bête recherche de sous-chaîne.

**Pourquoi ce scénario existe** : PKCE est obligatoire (NFR1) et `plain` est explicitement refusé — il transmet le secret en clair dès l'autorisation et ne protège de rien. La 3ᵉ ligne est subtile : la RFC 7636 dit qu'une méthode **absente** vaut `plain`. L'interpréter silencieusement comme S256 « pour être conciliant » reviendrait à ne rien vérifier du tout.

### Scénario 11.8 — Reprise du flux après login : la query string survit

1. Se **déconnecter** de SE5 (ou utiliser une fenêtre de navigation privée).
2. Coller l'URL complète `/oidc/authorize?…` du scénario 11.4.
3. Le formulaire de login SE5 s'affiche : s'authentifier normalement.

**Attendu** :
- Après authentification, **aucune action supplémentaire n'est nécessaire** : le navigateur repart directement vers `https://exemple.test/callback?code=…&state=abc123`.
- Le `nonce` d'origine se retrouve dans l'id_token après échange (preuve que **tous** les paramètres ont survécu, pas seulement le chemin).

**Pourquoi ce scénario existe** : c'est le **piège n°1** de la story. `SambaEduAuthGuard::unauthorized()` mémorisait `$request->path()` — **sans la query string**. Or tout le flux OIDC vit dans la query. Un utilisateur sans session était donc renvoyé au login puis « repris » sur `/oidc/authorize` **nu**, c'est-à-dire une page 400 — à **chaque première connexion de la journée**, le cas le plus fréquent qui soit. Le correctif (`fullUrl()`) répare le mécanisme standard du projet (`url.intended` + `redirect()->intended()`) au lieu d'inventer un canal parallèle.

**Effet de bord bénéfique à vérifier au passage** : ouvrir `/admin/settings?tab=fichiers` sans session, se connecter → on revient bien sur l'onglet demandé. Avant le correctif, tout paramètre d'onglet était perdu au re-login.

### Scénario 11.9 — Frontière avec la sync amont (NFR14) étendue à l'OIDC

1. Enregistrer un client lié à une extension : `php artisan oidc:client:register "Doc" --redirect-uri=… --extension=doc`.
2. Dérouler un flux complet (scénario 11.4) pour laisser un code et un access token en base.
3. Déclencher une ingestion de contrat amont, puis une rupture de lien (cf. Section 5 pour les commandes).
4. Ré-inspecter : `SELECT client_id, enabled FROM oidc_clients;` et le nombre de lignes des deux autres tables.

**Attendu** : **strictement rien n'a changé**. Le client reste actif, le code et le jeton restent en place.

**Pourquoi ce scénario existe** : le registre des clients est un prolongement du registre d'extensions, et l'incident fondateur du projet (catalogue applicatif local effacé par la sync amont) montre que la frontière ne va pas de soi. La conséquence serait ici pire qu'un catalogue vidé : **plus personne ne pourrait se connecter à aucune extension**, et la cause serait cherchée très loin de la sync. Verrouillé automatiquement par `UpstreamSyncExtensionsBoundaryTest`, désormais étendu à **6 tables**.

### Scénario 11.10 — Révocation d'un client

1. `php artisan oidc:client:revoke <client_id>` → succès.
2. Rejouer la commande → message « déjà révoqué », code retour **0**.
3. `php artisan oidc:client:revoke inconnu` → erreur, code retour **1**.
4. Retenter un `/oidc/authorize` avec ce client, puis un `/oidc/token`.

**Attendu** :
- La ligne existe toujours en base avec `enabled = false` : **révoquer n'est pas supprimer**, l'historique du registre est conservé.
- `/oidc/authorize` → page 400 (refus non redirigeable).
- `/oidc/token` → `invalid_client` 401, **y compris avec un code émis avant la révocation**.

**Pourquoi ce scénario existe** : l'idempotence est la doctrine d'exploitation du projet (rejouable sans risque), mais un client **inconnu** doit échouer bruyamment : sur une faute de frappe, un succès silencieux laisserait croire à une révocation qui n'a jamais eu lieu — un faux sentiment de sécurité au pire moment.

---

## Section 12 — Correctifs de review 55.1

> Ajoutée après la review sonnet de la Story 55.1 (dev opus), findings évalués par l'orchestrateur. Les trois scénarios portent sur des garanties que la story **affirmait** fournir et qui n'étaient pas tenues.

### Scénario 12.1 — L'émission d'un jeton pour un acteur fédéré laisse une trace durable

**Contexte** : `/oidc/authorize` porte `federated.audit`, mais ce middleware n'audite un **GET** que si le nom de la route figure dans `federated_auth.audit.sensitive_get_routes`. Sans cette entrée, l'alias était un **no-op silencieux**, et rien d'autre ne rattrapait l'imputabilité : les logs du channel `oidc` omettent volontairement le `sub`, et `oidc_authorization_codes` est purgée au fil de l'eau.

1. Se connecter à SE5 via le **login fédéré** (technicien externe, controlHub).
2. Déclencher un flux SSO complet vers une extension cliente (`/oidc/authorize?…`).
3. En base : `SELECT route_name, http_method, status_code, actor_login, actor_external_sub FROM external_action_audit_logs ORDER BY occurred_at DESC LIMIT 5;`

**Attendu** :
- Une ligne `route_name = 'oidc.authorize'`, `http_method = 'GET'`, portant le login `ext:<sub>` et le `sub` externe de l'acteur.
- Refaire le même flux avec un compte **AD local** : **aucune** ligne ajoutée (ce journal est réservé aux acteurs externes).

**Pourquoi ce scénario existe** : passé le délai de purge des codes, plus rien ne disait qui avait obtenu un jeton, pour quelle extension, ni quand. Le test qui « couvrait » le sujet n'inspectait qu'une chaîne de caractères dans la déclaration de route — il aurait continué de passer avec le middleware muet.

### Scénario 12.2 — Un `Host` détourné n'emmène personne hors de l'instance

**Contexte** : `SambaEduAuthGuard` mémorise l'URL demandée dans `url.intended`, que `redirect()->intended()` suit ensuite **sans vérifier aucun hôte**. `TrustHosts` est désactivé dans le Kernel et le vhost Apache répond à n'importe quel `Host`.

1. Émettre une requête vers l'instance **sans session**, en forçant un en-tête `Host` étranger :
   `curl -sD- -H 'Host: attaquant.example' 'https://<se4fs>/oidc/authorize?client_id=abc&state=xyz' -o /dev/null`
2. Inspecter la session créée (ou rejouer le parcours dans un navigateur avec un DNS pointant un nom tiers vers l'IP du serveur), puis s'authentifier.

**Attendu** :
- La valeur mémorisée est un chemin **relatif** (`/oidc/authorize?…`) : ni schéma, ni hôte.
- Après login, l'utilisateur revient sur **l'instance**, jamais sur le domaine injecté — et la query OIDC (`client_id`, `state`, `nonce`, `code_challenge`) est intacte.

**Pourquoi ce scénario existe** : le correctif initial de la story utilisait `fullUrl()`, qui reconstruit une URL **absolue** à partir du `Host` entrant. C'était exactement la classe d'attaque (open-redirect) que la validation de `redirect_uri` s'échine à empêcher, réintroduite par la porte d'à côté — sur le point d'entrée de l'IdP lui-même.

### Scénario 12.3 — Une valeur trop longue est refusée proprement, pas en 500

**Contexte** : `redirect_uri` (512), `nonce` (255), `scope` (255) et `code_challenge` (128) sont écrits dans `oidc_authorization_codes`. **PostgreSQL refuse** un dépassement ; **SQLite — driver de toute la suite de tests — ne l'applique jamais**. La divergence est donc invisible aux tests automatisés tant que la borne n'est pas dans le code.

1. `php artisan oidc:client:register --name "Trop long" --redirect-uri "https://ext.example.test/cb?j=$(python3 -c 'print("a"*520)')"`
2. Sur un client valide, appeler `/oidc/authorize?…&nonce=<300 caractères>`.

**Attendu** :
- (1) La commande **échoue** avec un message nommant la longueur et la borne ; **aucun client créé**.
- (2) Redirection 302 vers l'URI **déclarée** avec `error=invalid_request`, `state` relayé, **aucun code émis** — et une ligne `oidc.parameter_too_long` au journal `oidc`. Jamais une page 500.

**Pourquoi ce scénario existe** : sans borne applicative, un client parfaitement légitime était accepté à l'enregistrement puis échouait à **chaque** flux sur une exception SQL convertie en 500 générique — hors du journal métier, donc indiagnosticable depuis les logs `oidc` (FR20 non tenu).

---

## Section 13 — Claims d'identité minimisés et `/userinfo` (Story 55.2)

> **Deuxième story de l'Epic 55.** L'id_token cesse d'être une simple preuve d'authentification : il porte désormais **l'identité, le rôle métier et les groupes du contexte** — et **rien d'autre** (NFR5). Une extension applique ses règles métier (salons BBB par classe) **sans jamais accéder à l'annuaire ni à la base SE5**.
>
> ⚠️ **Ce que ces scénarios vérifient est un CONTRAT PUBLIC GELÉ (NFR11).** À partir de la première extension intégrée, un claim ne peut plus être retiré ni renommé sans casser toutes les intégrations. Les vérifications « rien d'autre » ne sont donc pas du zèle : une clé émise par erreur devient une dette permanente **et** une fuite de PII sur une population qui contient des élèves mineurs.
>
> **Ce qui n'est PAS ici** : l'app-témoin et la suite d'attaque (`alg:none`, HS256-confusion, `aud` étrangère, rejeu `jti`) → Story 55.3 ; les scopes **consentis par l'admin et révocables en UI**, les tokens de service `client_credentials`, l'API `/api/ext/v1/*` → Epic 56.

**Prérequis spécifiques à cette section**

- `php artisan migrate` a joué `2026_07_28_310000_add_user_id_to_oidc_access_tokens.php` (colonne additive `user_id`). Vérifier : `\d oidc_access_tokens` en `psql` doit montrer `user_id` nullable.
- Les mêmes prérequis que la Section 11 (clés, client déclaré, couple PKCE).
- **Trois comptes de test** dans SE5 : un prof membre d'au moins deux classes et d'une équipe, un élève d'une classe, et un compte dont `users.role` vaut `autre` (ou une identité fédérée `ext:…`).
- Un décodeur d'id_token local (aucun secret ne quitte le poste) :
  ```bash
  decode() { echo "$1" | cut -d. -f2 | tr '_-' '/+' | base64 -d 2>/dev/null | jq; }
  ```

### Scénario 13.1 — Un prof obtient nom, rôle et groupes — et RIEN d'autre

1. Dérouler le flux complet du scénario 11.4 **en changeant le scope** : `&scope=openid%20profile%20groups`.
2. Décoder l'`id_token` obtenu : `decode <ID_TOKEN>`.

**Attendu** :
- `name` = le nom d'affichage du prof (`fullname`, à défaut son login).
- `role` = **`"prof"`**, une **chaîne** — jamais un tableau, jamais `["prof","admin"]`.
- `groups` = la liste de ses classes **et** de ses équipes, en **noms nus** (`"4B"`, jamais `"Classe_4B"`), **triés alphabétiquement** et sans doublon.
- **La liste EXACTE des clés est** : `iss, sub, aud, exp, iat, jti, nonce, name, role, groups`. Compter les clés (`decode <ID_TOKEN> | keys`) : **rien d'autre ne doit apparaître**, en particulier ni `email`, ni `given_name`/`family_name`, ni `picture`, ni `locale`, ni `ad_guid`, ni `dn`, ni `memberOf`, ni permission Spatie.
- Journal `storage/logs/oidc/` : `oidc.authorize.granted` puis `oidc.token.issued`. **Aucune ligne ne contient le nom de l'utilisateur, un nom de groupe, ni son `sub`.** Vérifier explicitement :
  ```bash
  grep -c "<NOM DU PROF>" storage/logs/oidc/oidc-$(date +%F).log   # doit rendre 0
  ```

**Pourquoi ce scénario existe** : c'est le contrat que 55.3 puis BBB (Epic 57) consommeront. La vérification par **liste exacte** — et non par « contient bien `name` » — est la seule qui empêche un claim auquel personne n'a pensé d'entrer dans un contrat qu'on ne pourra plus réduire.

### Scénario 13.2 — Un prof délégué super-admin reste `prof`

1. Attribuer le rôle Spatie `super-admin` au compte prof de test.
2. Rejouer le flux avec `scope=openid profile`.

**Attendu** : `role` = **`"prof"`**, pas `"admin"`. Le profil métier prime sur la délégation d'administration.

**Pourquoi ce scénario existe** : `User::businessRoles()` rend l'ENSEMBLE (`["prof","admin"]`) ; le claim en prend le **premier**. C'est le bon comportement pour une extension pédagogique — un prof qui administre le serveur reste un prof pour ses salons de classe. Une extension d'administration qui aurait besoin de l'ensemble complet justifierait un claim `roles` **ajouté**, jamais un changement de type de `role`.

### Scénario 13.3 — L'élève, et la minimisation par scopes

Dérouler **trois** flux avec le compte élève, en ne changeant QUE le scope.

| Scope demandé | Claims métier attendus dans l'id_token |
|---|---|
| `openid profile groups` | `name`, `role: "eleve"`, `groups: ["4B"]` |
| `openid profile` | `name`, `role` — **`groups` ABSENT** |
| `openid` | **AUCUN** : exactement les claims de la Section 11 (`iss, sub, aud, exp, iat, jti, nonce`) |

**Attendu** : dans le deuxième cas, `groups` est absent **alors que l'élève a bien une classe** — l'absence vient du scope, pas d'une base vide. Dans le troisième, la liste de clés est **identique** à celle observée en 11.4 avant cette story.

**Pourquoi ce scénario existe** : c'est NFR5 rendu observable. Un scope non demandé ne produit **rien** ; si un jour un claim apparaissait sans son scope, la fuite serait silencieuse — aucun client ne s'en plaindrait, et la donnée serait pourtant partie.

### Scénario 13.4 — Rôle non résoluble : le claim est ABSENT, jamais inventé

1. Dérouler le flux avec le compte dont `users.role = 'autre'` (ou l'identité fédérée), `scope=openid profile`.
2. Décoder l'id_token.

**Attendu** :
- `name` **présent**, `sub` présent.
- **`role` totalement absent** de l'objet JSON — pas `"autre"`, pas `null`, pas `""`.

**Pourquoi ce scénario existe** : c'est le fail-closed du PRD (« signature, scopes, **rôle inconnu** »). Une valeur sentinelle serait interprétée par une extension comme un rôle réel ; une clé absente est testable par tout client OIDC et le laisse fail-closed — il n'habilite pas. La limite est **connue et assumée** : les identités fédérées et les délégations non-`super-admin` ne résolvent pas de rôle métier (raffinement en 49.1/49.2, pas dans le SSO).

### Scénario 13.5 — Un scope inconnu est REFUSÉ, jamais ignoré

Rejouer l'URL d'autorisation avec `&scope=openid%20profile%20foo`.

**Attendu** :
- Redirection 302 vers la `redirect_uri` **déclarée**, avec `error=invalid_scope` et le `state` relayé.
- **Aucun code émis** : `SELECT count(*) FROM oidc_authorization_codes;` inchangé.
- Journal : `oidc.authorize.rejected` avec `code: oidc.scope_unsupported`.
- Contrôle positif à enchaîner : `&scope=openid%20profile%20groups` est bien **accepté**.

**Pourquoi ce scénario existe** : un scope qui n'existe pas ne peut pas être consenti. L'ignorer laisserait le client croire qu'il a obtenu quelque chose — et le jour où `foo` deviendrait un vrai scope, il serait accordé **rétroactivement** à tous ceux qui le demandaient déjà. L'ensemble annoncé par la discovery (`scopes_supported`) et l'ensemble accepté au flux sont la **même source** : les comparer est un test à part entière.

### Scénario 13.6 — `/userinfo` nominal, et le `sub` identique à l'id_token

1. Dérouler le flux complet avec `scope=openid profile groups` et conserver **l'`access_token`** de la réponse d'échange.
2. ```bash
   curl -sD- -H "Authorization: Bearer <ACCESS_TOKEN>" https://<host>/oidc/userinfo | jq
   ```
3. Refaire l'appel en **POST** : `curl -s -X POST -H "Authorization: Bearer <ACCESS_TOKEN>" https://<host>/oidc/userinfo | jq`

**Attendu** :
- `{ "sub": …, "name": …, "role": …, "groups": [ … ] }` — les **mêmes valeurs** que dans l'id_token du même flux, et un `sub` **strictement identique** (OIDC Core §5.3.2).
- **GET et POST rendent exactement la même chose** (les deux sont imposés par la spécification).
- En-têtes : `Cache-Control: no-store`.
- Journal : `oidc.userinfo.served`, portant le `client_id` et un préfixe de hash — **ni `sub`, ni `name`, ni `groups`**.
- Avec un jeton obtenu en `scope=openid` **seul** : la réponse est **`{"sub": "…"}` et rien d'autre**.

**Pourquoi ce scénario existe** : `/userinfo` est le canal de repli du contrat — il doit dire **exactement** la même chose que l'id_token, sinon une extension qui utilise l'un et une autre qui utilise l'autre divergeraient sur la même personne. Le filtrage par scope s'y applique aussi : ce canal ne peut pas être une porte dérobée aux claims.

### Scénario 13.7 — `/userinfo` fail-closed : cinq causes, une seule réponse

Dérouler chaque variante et comparer **mot pour mot** les réponses.

| Variante | Comment la produire |
|---|---|
| Bearer absent | `curl -sD- https://<host>/oidc/userinfo` |
| Jeton inconnu | `Authorization: Bearer $(openssl rand -hex 32)` |
| Jeton expiré | attendre **plus de 600 s** après l'échange, puis rappeler |
| Client révoqué | `php artisan oidc:client:revoke <CLIENT_ID>` puis rappeler avec un jeton **encore valide** |
| Utilisateur supprimé | supprimer le compte SE5 puis rappeler avec son jeton |
| Jeton en query | `curl -sD- "https://<host>/oidc/userinfo?access_token=<ACCESS_TOKEN>"` |

**Attendu** :
- **Toutes** : `HTTP 401`, en-tête `WWW-Authenticate: Bearer …`, **aucune donnée d'identité** dans le corps (pas de `sub`, pas de `name`, pas de `groups`), `Cache-Control: no-store`.
- Les quatre variantes « jeton présenté » rendent **exactement** `{"error":"invalid_token","error_description":"The access token is invalid or expired."}` — **rigoureusement indistinctes**.
- Le Bearer **absent** (et le jeton en **query**, qui est ignoré donc équivaut à absent) rend le challenge **sans** code d'erreur (RFC 6750 §3).
- Le journal, lui, **distingue** : `oidc.access_token_missing`, `oidc.access_token_invalid`, `oidc.access_token_expired`, `oidc.client_disabled`, `oidc.user_missing`.
- Contrôle positif **obligatoire avant chaque variante** : le même appel, avec un jeton valide et l'en-tête correct, rend bien `200`.

**Pourquoi ce scénario existe** : un endpoint qui répondrait 401 à tout passerait ces vérifications sans rien garantir — d'où le contrôle positif. Et une réponse qui distinguerait « expiré » de « inconnu » offrirait un **oracle** à qui teste des jetons : il saurait qu'il en a trouvé un vrai. Le jeton en query est refusé par principe : il finirait dans les logs du serveur, l'historique et le `Referer`.

### Scénario 13.8 — Révoquer une extension tue ses jetons déjà émis

1. Dérouler un flux complet, garder l'`access_token`, vérifier que `/userinfo` rend `200`.
2. `php artisan oidc:client:revoke <CLIENT_ID>`
3. Rappeler `/userinfo` avec le **même** jeton.

**Attendu** : `401`, immédiatement — **sans attendre l'expiration des 600 s**.

**Pourquoi ce scénario existe** : c'est la promesse de la Section 11.10 qui devient enfin observable. Elle n'est tenable que parce que l'access_token est **opaque** (une clé de ligne), et non un JWT auto-porteur : un JWT resterait valable jusqu'à son `exp`, quoi qu'on fasse au registre. Désinstaller une extension doit couper l'accès **maintenant**.

### Scénario 13.9 — La discovery a évolué ADDITIVEMENT

```bash
curl -s https://<host>/.well-known/openid-configuration | jq
```

**Attendu** :
- **Nouveau** : `userinfo_endpoint` (= `https://<host>/oidc/userinfo`), `scopes_supported: ["openid","profile","groups"]`, `claims_supported` enrichi de `name`, `role`, `groups`.
- **Inchangé** : `issuer`, `authorization_endpoint`, `token_endpoint`, `jwks_uri`, `response_types_supported`, `grant_types_supported`, `response_modes_supported`, `subject_types_supported`, `id_token_signing_alg_values_supported`, `code_challenge_methods_supported`, `token_endpoint_auth_methods_supported` — **aucune clé retirée, aucune renommée, aucune valeur modifiée**.
- `claims_supported` ne mentionne **pas** `email` : un intégrateur lirait ce document et demanderait le claim.

**Pourquoi ce scénario existe** : une extension déployée lit la discovery **une fois** et la met en cache. Retirer une clé la casse sans qu'aucun message d'erreur ne l'explique. C'est aussi pourquoi `userinfo_endpoint` était volontairement absent en 55.1 : annoncer un endpoint inexistant fait échouer tout client qui suit la discovery à la lettre.

### Scénario 13.10 — Un claim n'autorise rien (miroir de 9.3)

1. Obtenir un id_token portant `role: "prof"` pour un compte prof.
2. Avec ce même compte, tenter d'atteindre une page d'administration SE5 (`/admin/extensions`).
3. Inversement : sur un compte `role='autre'` (donc **sans** claim `role`), vérifier que ses accès SE5 habituels sont **inchangés**.

**Attendu** : le claim `role` n'ouvre **aucun** droit dans SE5 (2 → refus, comme avant), et son absence n'en **retire aucun** (3 → rien ne change). L'autorisation réelle reste portée par les permissions Spatie côté SE5, et par ses propres règles côté extension.

**Pourquoi ce scénario existe** : c'est exactement la distinction du scénario 9.3 (« masquer n'est pas protéger », FR14), vue depuis l'autre bout. Un claim est une **donnée transportée**, pas une décision d'autorisation. Une extension qui traiterait la présence d'un claim comme une permission se tromperait de contrat — et une régression qui ferait de `role` une source d'autorisation dans SE5 créerait une escalade silencieuse.

### Scénario 13.11 — Volumétrie : un utilisateur à quarante groupes

1. Rattacher le compte prof de test à ~40 classes (base de test uniquement).
2. Dérouler le flux avec `scope=openid profile groups` et décoder l'id_token.
3. Appeler `/userinfo` avec l'access_token du même flux.

**Attendu** : l'id_token est **émis et vérifiable** (signature valide sur https://jwt.io ou via le JWKS), les 40 noms sont présents, triés. `/userinfo` rend la **même** liste.

**Pourquoi ce scénario existe** : le claim `groups` n'est persisté dans **aucune colonne** — il vit dans le JWT (transporté en corps POST de la réponse d'échange, jamais en query) et dans le JSON `/userinfo`. Il n'est donc pas concerné par la leçon 12.3 sur les bornes `VARCHAR`. Si un établissement pathologique faisait un jour exploser la taille du jeton, `/userinfo` est le canal de repli documenté : le contrat y est identique.

---

## Section 14 — Correctifs de review 55.2

> Ajoutée après la review sonnet de la Story 55.2 (dev opus), findings évalués par l'orchestrateur.

### Scénario 14.1 — Désactiver un compte coupe immédiatement son accès aux extensions

**Contexte** : `users.is_active` ne gardait **rien** dans la chaîne OIDC. `SambaEduAuthGuard` valide l'état du compte côté **LDAP/AD** (ou `ExternalIdentity` pour une session fédérée), jamais cette colonne PostgreSQL — et les endpoints `/oidc/token` et `/oidc/userinfo` partent d'un code ou d'un jeton, sans traverser aucune session. La révocation d'un **client** était vérifiée ; la désactivation d'un **utilisateur** ne l'était pas.

1. Faire un flux SSO complet vers une extension, conserver l'`access_token` (TTL 600 s par défaut).
2. Vérifier que `GET /oidc/userinfo` avec ce Bearer répond 200 avec les claims.
3. Désactiver le compte : `UPDATE users SET is_active = false WHERE login = '<login>';`
4. Rappeler `/oidc/userinfo` avec **le même** Bearer.
5. Variante token endpoint : relancer un `/oidc/authorize`, récupérer le `code`, désactiver le compte, **puis** échanger le code.

**Attendu** :
- (4) **401 `invalid_token`**, corps et en-têtes strictement identiques à ceux d'un jeton inconnu — aucun oracle sur l'état du compte. Journal `oidc` : `oidc.user_inactive`.
- (5) **400 `invalid_grant`**, message identique à celui d'un code inconnu. **Aucun** id_token, **aucun** access_token émis.
- Réactiver le compte et refaire un flux complet : tout refonctionne (le contrôle n'est pas un verrou définitif).

**Pourquoi ce scénario existe** : c'est le geste d'exploitation le plus courant lors d'un départ ou d'une mesure disciplinaire. Sans ce contrôle, un compte désactivé continuait de voir son nom, son rôle et ses classes servis à des applications tierces pendant toute la fenêtre restante du jeton.

### Scénario 14.2 — La clé étrangère `user_id` est bien posée en production

**Contexte** : la FK est ajoutée en best-effort (`try/catch`), parce que SQLite — driver de la suite de tests — ne sait pas ajouter une contrainte à une table existante. Le `catch` interceptait aussi, en silence, un échec **réel** en PostgreSQL.

1. Après `php artisan migrate` sur la VM, vérifier :
   `\d oidc_access_tokens` dans `psql`, ou
   `SELECT conname FROM pg_constraint WHERE conname = 'oidc_tokens_user_fk';`
2. Inspecter `storage/logs/laravel.log` à la recherche de `oidc.migration.foreign_key_skipped`.

**Attendu** : la contrainte existe, et **aucune** ligne `oidc.migration.foreign_key_skipped` n'a été écrite. Si cette ligne apparaît, la migration a réussi mais l'intégrité référentielle annoncée est absente — à traiter.

**Pourquoi ce scénario existe** : le fail-closed applicatif ne dépend pas de la FK (un `user_id` orphelin donne `User::find() → null` → refus), donc l'absence de contrainte **ne casse rien de visible**. C'est exactement ce qui la rendrait indétectable pendant des mois.

---

## Section 15 — App-témoin SSO et validation sécurité (Story 55.3) — **CLÔT l'Epic 55**

> **Ce que cette section valide, et qui n'est pas une fonctionnalité produit.** L'app-témoin est une **sonde de contrat** : la seule question qu'elle répond est « le contrat public OIDC suffit-il à une application qui n'a QUE le contrat ? ». Elle vit en quarantaine dans `app/OidcWitness/` et n'obtient RIEN de SE5 autrement que par les endpoints publics, appelés en HTTP — aucun modèle, aucune base, aucun annuaire, aucun accès à la session SE5. La quarantaine n'est pas une convention de style : elle est verrouillée par `tests/Architecture/ExtensionIsolationTest.php`, parce qu'**un témoin qui triche ne prouve rien**.
>
> **Dette worktree assumée, iso 55.1/55.2** : la story a été développée dans un worktree git non synchronisé vers la VM. Toute la suite automatisée tourne sur l'HÔTE ; les scénarios navigateur ci-dessous sont à jouer **au merge sur `main`**, comme les Sections 11 à 14.

### Pré-requis de la section

```bash
cd /var/www/sambaedu-reload
php artisan migrate
php artisan oidc:keys:init                       # idempotente (Section 11.1)
php artisan db:seed --class=BundledExtensionSeeder --force
php artisan oidc:witness:enable
```

Puis, en tant qu'admin : `/admin/extensions` → **Démo SSO** → « Intégrer ».

### Scénario 15.1 — Provisioning : idempotent, 0600, et le secret n'est nulle part

1. `php artisan oidc:witness:enable` sur une instance vierge.
2. `ls -l storage/app/oidc-witness.json` et `cat` du fichier.
3. Relancer **la même** commande.
4. `psql` : `SELECT name, client_id, extension_key, enabled FROM oidc_clients;`
5. Chercher le secret ailleurs : `grep -r "$(python3 -c "import json;print(json.load(open('storage/app/oidc-witness.json'))['client_secret'])")" storage/logs/ ; echo "code retour grep : $?"`

**Attendu** :
- (2) fichier en **`-rw------- (0600)`**, propriétaire = utilisateur qui a lancé la commande ; il contient `client_id`, `client_secret`, `issuer`, `redirect_uri` et **rien d'autre**.
- (1) et (3) : la sortie console affiche `client_id`, `redirect_uri`, `issuer` et le chemin du fichier — **jamais le secret** ; le message est explicite (`Le client_secret n'est PAS affiché`).
- (3) **no-op signalé** (« App-témoin déjà provisionnée — aucune action »), code retour 0, aucun second client, secret inchangé.
- (4) exactement **une** ligne, `name = App-témoin SSO`, `extension_key = sso-demo`, `enabled = t`.
- (5) `grep` ne trouve **rien** (code retour 1). Le journal `oidc` porte bien `oidc.witness.provisioned` — donc l'absence n'est pas celle d'un journal muet.

**Pourquoi cette différence de doctrine avec `oidc:client:register`** : celle-là AFFICHE le secret une fois, parce que son destinataire est un humain qui doit le recopier dans une configuration tierce. Ici le destinataire est un **fichier que la commande écrit elle-même** : afficher un secret que personne n'a besoin de lire, c'est l'exposer à l'historique du terminal et aux journaux d'exploitation pour rien.

### Scénario 15.2 — `--rotate` : l'ancien client meurt vraiment

1. Noter le `client_id` courant (`cat storage/app/oidc-witness.json`).
2. `php artisan oidc:witness:enable --rotate`.
3. Comparer l'ancien et le nouveau `client_id` / `client_secret`.
4. `SELECT client_id, enabled FROM oidc_clients ORDER BY id;`

**Attendu** : nouveau `client_id` ET nouveau secret ; l'ancienne ligne existe toujours mais `enabled = f` (révocation = désactivation, **jamais** suppression — la trace reste, patron 11.10) ; la nouvelle est active. Le parcours 15.4 refonctionne immédiatement avec les nouveaux credentials.

### Scénario 15.3 — Cas incohérent : fichier présent, client révoqué

1. Après un `enable` réussi, révoquer le client à la main : `php artisan oidc:client:revoke <client_id>`.
2. Relancer `php artisan oidc:witness:enable`.

**Attendu** : **échec bruyant** (code retour 1) nommant le remède (`--rotate` ou `oidc:witness:disable`), **aucun** client fantôme créé. Puis `php artisan oidc:witness:enable --rotate` répare.

**Pourquoi ce scénario existe** : réenregistrer en douce masquerait la cause (quelqu'un a révoqué ce client, et il avait peut-être une raison). Un provisioning idempotent doit rester idempotent sur l'état NOMINAL, pas « auto-réparant » sur un état que personne n'a expliqué.

### Scénario 15.4 — Le parcours complet : clic sur la tuile → claims, sans re-login

**Compte** : un **prof** authentifié, avec un `display_name` renseigné et au moins deux classes (`4B`, `3A`).

1. Se connecter à SE5 normalement.
2. Ouvrir la gaufre du lanceur (navbar) → cliquer **Démo SSO**.
3. Observer la barre d'adresse pendant la navigation (ou ouvrir les outils réseau).
4. Lire la page finale.

**Attendu** :
- Nouvel onglet (comportement 54.3, `target="_blank" rel="noopener"`).
- Enchaînement : `/sso-demo` → **302** vers `/oidc/authorize?...` portant `response_type=code`, `scope=openid profile groups`, `state`, `nonce`, `code_challenge` et `code_challenge_method=S256` — **jamais** de `code_verifier` dans l'URL.
- **Aucun formulaire de connexion** : la session SE5 est déjà active (FR17). `/oidc/authorize` → **302** vers `/sso-demo/callback?code=…&state=…`.
- Page finale : « **Bonjour Professeur Dupont** », rôle `prof`, groupes `3A` et `4B` en badges, lien « ← Retour au lanceur » qui ramène à `/` (FR16).
- Journal `oidc` : `oidc.witness.start`, `oidc.authorize.granted`, `oidc.token.issued`, `oidc.witness.verified` — **et aucune PII** (ni `name`, ni groupes, ni `sub`).
- Cookie `oidc_witness_state` : `HttpOnly`, `SameSite=Lax`, `Path=/sso-demo`, valeur **chiffrée** (illisible), disparu après le callback.

**Ce que ce scénario prouve, et que rien d'autre ne prouve** : que le contrat de claims v1 est réellement consommable par une application qui n'a pas accès à SE5. L'Epic 57 (BigBlueButton) s'écrira contre un contrat **démontré**, pas supposé.

### Scénario 15.5 — Un rôle non résolu s'affiche comme absent, jamais inventé

1. Choisir (ou créer) un compte dont `businessRoles()` est vide — typiquement `users.role = 'autre'` sans délégation `super-admin` (cf. Scénario 13.4).
2. Faire le parcours 15.4 avec ce compte.

**Attendu** : « Bonjour <nom> » s'affiche (contrôle positif : le `name`, lui, est bien transmis), et la ligne **Rôle** indique **« (non résolu) »**. Jamais `autre`, jamais `null`, jamais une case vide muette.

### Scénario 15.6 — `state` altéré au retour : refus AVANT tout échange

1. Faire le parcours 15.4 jusqu'à la redirection vers `/sso-demo/callback?code=…&state=…`.
2. Recopier l'URL en **modifiant le `state`**, puis la charger dans le même onglet (le cookie est toujours là).
3. Recharger ensuite l'URL **d'origine**, intacte.

**Attendu** :
- (2) page d'erreur sobre du témoin en **400**, code `witness.id_token`/`witness.state_mismatch` affiché ; **aucun appel au token endpoint** (rien dans le journal `oidc` côté `oidc.token.*` pour cet instant). Le code d'autorisation n'a **pas** été consommé.
- (3) contrôle **POSITIF** : le bon `state` passe et rend les claims. Sans lui, le refus (2) pourrait n'être que le symptôme d'une plomberie cassée.

**Ce que ce vecteur recouvre** : c'est le pendant CLIENT de la `redirect_uri` altérée (déjà couverte côté serveur en 55.1, Scénario 11.6). Une injection de code d'autorisation consiste à faire consommer à la victime un code obtenu par un tiers — c'est le `state`, et lui seul, qui l'attrape côté client.

### Scénario 15.7 — Rejeu du callback : le code est à usage unique

1. Faire un parcours 15.4 complet et **conserver** l'URL de callback.
2. Recharger exactement la même URL (cookie d'état toujours présent).

**Attendu** : **502** avec le code `witness.token_exchange_failed`, **aucune** ligne « Bonjour ». Journal `oidc` : `oidc.token.rejected` avec le code interne du code déjà consommé (Scénario 11.5). Le témoin n'affiche **jamais** de claims issus d'un échange raté.

### Scénario 15.8 — Client révoqué : le parcours devient impossible, explicitement

1. Parcours 15.4 nominal (contrôle positif).
2. `php artisan oidc:client:revoke <client_id du témoin>`.
3. Recliquer sur la tuile **Démo SSO**.

**Attendu** : `/sso-demo` redirige toujours (le témoin ne sait pas encore que son client est mort — c'est normal, il ne lit pas le registre), puis `/oidc/authorize` répond **400 local sans en-tête `Location`** (règle cardinale 11.6 : on ne redirige jamais vers une `redirect_uri` d'un client non validé). Aucun jeton n'est délivré.

### Scénario 15.9 — Témoin non provisionné : 503 explicite, jamais une 500 brute

1. `php artisan oidc:witness:disable` (ou instance neuve).
2. Ouvrir `/sso-demo` directement.

**Attendu** : **503**, page sobre du témoin portant le code `witness.not_provisioned` et la commande de remède (`php artisan oidc:witness:enable`) ; **aucun** appel sortant (rien de nouveau dans le journal `oidc` côté fournisseur) ; aucune trace d'exception non gérée dans `laravel.log`.

### Scénario 15.10 — `oidc:witness:disable` : idempotente, et elle ferme la porte

1. `php artisan oidc:witness:disable` après un `enable`.
2. Vérifier `ls storage/app/oidc-witness.json` et `SELECT enabled FROM oidc_clients WHERE name = 'App-témoin SSO';`
3. Relancer la commande.
4. Cas dégradé : `echo '{ pas du json' > storage/app/oidc-witness.json` puis relancer.

**Attendu** : (2) fichier supprimé, client `enabled = f` ; (3) « App-témoin déjà retirée — aucune action », code retour 0 ; (4) le fichier illisible est **supprimé** avec un avertissement — sinon il bloquerait `enable` indéfiniment.

### Scénario 15.11 — La quarantaine, vérifiée à la main

1. `grep -rnE "App.(Models|Services)|Illuminate.Database|LdapRecord|DB::|Auth::" app/OidcWitness/ --include='*.php'`
2. Sur l'HÔTE : `php vendor/bin/phpunit tests/Architecture/ExtensionIsolationTest.php`

**Attendu** : (1) **aucune correspondance** ; (2) suite verte, dont le **méta-test** `the_quarantine_scanner_actually_detects_a_violation` — qui prouve que le scan mordrait si quelqu'un franchissait la ligne.

**Pourquoi le méta-test compte autant que le test** : une assertion d'absence qui n'inspecte rien passe éternellement au vert. Le méta-test injecte les aiguilles dans une chaîne de test (jamais dans le code du témoin) et exige que **chaque** règle les détecte, plus un contrôle inverse sur du code honnête.

### Scénario 15.12 — La suite d'attaque NFR1 (hôte)

```bash
php vendor/bin/phpunit tests/Unit/OidcWitness/WitnessIdTokenVerifierTest.php
php vendor/bin/phpunit tests/Feature/OidcWitness
```

**Attendu** : tout vert. La suite couvre, **un test par vecteur nommé** : `alg: none` ; confusion d'algorithme symétrique (HS256 signé avec la clé publique comme secret) ; `aud` d'un autre client ; `iss` d'une autre instance ; signature par la clé d'une autre instance ; `kid` inconnu ; JWKS vide ou annonçant un algorithme symétrique ; jeton expiré au-delà de la tolérance **et** accepté dans la tolérance ; `nbf` futur ; `exp`/`sub`/`jti`/`aud` manquants ; `nonce` divergent ou absent ; `jti` rejoué ; chaîne malformée ou tronquée.

**Ce qui n'y est PAS, et pourquoi** : `redirect_uri` altérée, PKCE absent ou `plain`, `code_verifier` faux, code rejoué, secret client faux, scope inconnu, compte désactivé — **tous déjà couverts par les 102 tests OIDC de 55.1/55.2** (Sections 11 à 14). Ils sont **référencés** dans la table de traçabilité du docblock de la suite, jamais dupliqués : une seconde source de vérité sur les mêmes refus se désynchroniserait le jour où l'une des deux évoluerait.

### Scénario 15.13 — Aucun identifiant interne ne fuit dans le canal extensions (FR24)

1. Choisir un compte dont `ad_guid` et `dn` sont renseignés (`SELECT id, login, dn, ad_guid FROM users WHERE login = '<login>';`).
2. Faire un flux SSO complet, capturer l'`id_token` et l'`access_token` (Scénario 11.4 pour la méthode `curl`).
3. Décoder le **payload brut** de l'id_token (`echo <payload> | base64 -d`) et appeler `/oidc/userinfo` avec le Bearer.
4. Chercher dans les DEUX corps : la valeur de l'`ad_guid`, un fragment du `dn` (`OU=`, `DC=`), et la valeur de `users.id`.

**Attendu** : le `sub` vaut le **`login`** (jamais `users.id`) ; `name`, `role` et `groups` sont bien là (contrôle positif) ; **aucune** occurrence d'`ad_guid`, de `dn`, de `memberOf`, de `sAMAccountName`, ni d'une clé interne du registre (`extension_id`, `oidc_client_id`, `*_hash`).

---

## Post-correctifs & non-régressions

- **Section 14.1 — l'état du compte se vérifie à CHAQUE maillon, pas une fois pour toutes** : la chaîne OIDC part d'un code ou d'un jeton, jamais d'une session — aucun middleware d'authentification ne la protège. Toute donnée d'identité servie doit donc revérifier l'état du sujet (`users.is_active`) **et** celui du client (`oidc_clients.enabled`) au moment où elle est servie. Piège de fond : `SambaEduAuthGuard` contrôle l'état côté **LDAP/AD**, pas la colonne PostgreSQL — supposer que « le guard s'en occupe » est faux hors du web classique. La même vigilance vaudra pour les tokens de service de l'Epic 56.
- **Section 15.11 — une sonde qui triche ne prouve rien** : la valeur démonstrative de l'app-témoin repose ENTIÈREMENT sur le fait qu'elle n'a que le contrat public. Toute story future qui « simplifierait » le témoin en lui faisant lire un modèle, la base ou la session SE5 le transformerait en validation de la connexion SE5 — c'est-à-dire en rien. Corollaire pour les Epics 56/57 : le jour où une vraie extension ne pourrait pas être écrite sans franchir cette ligne, ce n'est pas la ligne qu'il faut déplacer, c'est le **contrat public** qu'il faut compléter (et documenter comme tel, NFR11 : additivement).
- **Section 15.12 — le contrôle POSITIF est la condition de validité de tous les refus** : chaque famille d'attaque ouvre sur un jeton nominal accepté, et la tolérance d'horloge est prouvée dans les DEUX sens (rejeté au-delà, accepté en deçà). Sans cela, un refus n'est peut-être que le symptôme d'une plomberie cassée — mauvais PEM, mauvais `kid`, JWKS mal reconstruit — et la suite entière passerait au vert en ne protégeant rien.
- **Section 15.12 — ne jamais dupliquer un refus déjà couvert** : la traçabilité NFR1 (« chaque cas = un test ») se satisfait d'un **renvoi documenté** vers le test qui l'exerce déjà. Re-tester côté client ce que le serveur refuse crée une seconde source de vérité qui se désynchronisera. La table cas→test vit dans le docblock de `WitnessIdTokenVerifierTest`.
- **Section 15.6 — le `state` est le pendant CLIENT de la `redirect_uri`** : le serveur ne peut rien contre une injection de code d'autorisation ; seul le client sait s'il a demandé ce retour. La règle qui en découle vaut pour toute extension future : **vérifier le `state` AVANT de présenter le code au token endpoint**, jamais après — sinon le code est consommé pour le compte de l'attaquant.
- **Section 15.1 — un secret dont personne n'a besoin ne s'affiche pas** : `oidc:client:register` affiche le sien parce qu'un humain doit le recopier ; `oidc:witness:enable` ne l'affiche pas parce que son destinataire est le fichier qu'il écrit. Généralisation pour le provisioning automatique de l'Epic 56 (56.2) : dès lors que le secret est posé par la machine, il ne doit apparaître ni à l'écran, ni au journal, ni en base.
- **Section 15.1 / 15.10 — l'anti-rejeu `jti` du témoin est volontairement LIMITÉ** : cache local (`file`), aucun filet en base — parce que le témoin n'a pas le droit d'y toucher (FR24). Lui donner un stockage partagé lui prêterait une capacité qu'une vraie extension n'a pas, et la sonde mentirait. Une extension répartie devra porter le sien (SDK, Epic 58).
- **Section 15.12 — la consommation du `jti` est le DERNIER geste de la vérification** : miroir inversé de la décision M1 de l'Epic 20. Là-bas, le contrôleur consommait après le provisioning pour ne pas brûler un `jti` sur un échec ultérieur ; ici il n'y a pas d'étape ultérieure faillible, mais la position reste la même — sinon un jeton contrefait portant le `jti` d'un jeton légitime le brûlerait par avance (déni de service silencieux sur le SSO d'un utilisateur précis).
- **Section 14.2 — un `catch` best-effort doit rester bavard hors du cas qu'il vise** : intercepter `\Throwable` pour absorber une limite connue de SQLite absorbe aussi les vraies erreurs PostgreSQL. La règle : restreindre au driver concerné, ou au minimum journaliser — sinon la migration se déclare réussie en laissant fausse une garantie annoncée.

- **Section 1.3 / 5.x — « le catalogue local effacé par la sync amont »** : incident réel du projet sur `applications`. Le registre d'extensions est isolé par construction ; les scénarios 1.3 (le `status` survit à un re-seed) et 5.1→5.3 (la sync amont ne touche pas les tables) sont les deux faces du même garde-fou. Toute story future qui ajouterait un listener ou une FK entre les deux mondes doit faire échouer `UpstreamSyncExtensionsBoundaryTest`.
- **Section 2.4 — la version prime sur le contenu** : décision reprise de la Story 33.2 (négociation du schéma d'échange amont). Un rejet de contenu sur un manifest de version future masquerait la vraie cause et ferait perdre du temps à l'admin.
- **Section 1.1 — `url = ""` et jamais `null`** : une colonne nullable participant à une clé ou une contrainte casse l'unicité (NULL distinct de NULL en PostgreSQL **comme** en SQLite). Même règle pour `publisher`, `icon`, `description`, `version`.
- **Section 6.1 — racine introuvable ≠ catalogue vide** : correctif de review 54.1. La distinction est portée par `discoverBundledManifestPaths()` qui renvoie désormais `null` (rien observé) au lieu de `[]` (observation légitime). Toute évolution du chargement de source — en particulier les sources **distantes** de l'Epic 56, où « source injoignable » est un cas nominal (NFR7) — doit reconduire cette distinction : **une source qu'on n'a pas pu lire ne prune rien**.
- **Section 6.3 — strictness du contrat manifest** : correctif de review 54.1. `array_is_list()` sur `visibility.roles`, `scopes`, `dependencies`. Le validateur affiche une philosophie de rejet strict (décision #1, iso-33.2) ; accepter un objet JSON ré-indexé la contredisait.
- **Section 4.2 — listes vides** : exigence explicite de l'AC1. Une section « Autorisations demandées » vide et muette laisserait penser à un bug d'affichage plutôt qu'à une extension sans scope.
- **Section 7 — no-op ⇒ zéro ligne d'audit (NFR8)** : décision tranchée de la Story 54.2. Le journal `extension_audit_logs` trace des TRANSITIONS RÉELLES, pas des clics — un double-clic ou un re-jeu sur un état déjà atteint ne doit produire ni écriture ni ligne d'audit, sinon l'historique mentirait sur le nombre réel d'actes.
- **Section 7 — atomicité acte ↔ trace** : la ligne d'audit s'écrit DANS la même transaction que la mutation de `status` ({@see \App\Services\Extensions\ExtensionLifecycleService}). Un acte sans sa trace ne peut pas exister — vérifié par un test automatisé qui simule la disparition de la table d'audit (`tests/Feature/Extensions/ExtensionLifecycleServiceTest.php`).
- **Section 11.6 — on ne redirige jamais vers une `redirect_uri` non validée** : règle cardinale d'OAuth, appliquée y compris pour annoncer une erreur. Toute évolution du flux d'autorisation doit conserver les DEUX familles de refus séparées (locale 400 vs 302 sur l'URI déclarée) — les fusionner « pour simplifier » ferait de SE5 un open-redirector réputé de confiance.
- **Section 11.8 / 12.2 — `url.intended` porte la query, et RIEN de plus** : le guard mémorisait `path()`, ce qui amputait la query string et rendait le SSO impossible à la première connexion de la journée. Le correctif retenu est **`getRequestUri()`** (chemin + query, relatif) et **surtout pas `fullUrl()`**, qui reconstruirait une URL absolue à partir du `Host` entrant — non filtré, `TrustHosts` étant désactivé — que `redirect()->intended()` suivrait sans contrôle. La règle générale : ce qui est mémorisé pour être suivi après login ne doit jamais porter d'hôte issu de la requête. Ne pas inventer de canal parallèle (session dédiée, cookie) — `url.intended` + `redirect()->intended()` est le mécanisme standard du projet. Dette connexe : `app/Http/Middleware/RequireAdminRights.php:145` porte encore le même motif `fullUrl()`.
- **Section 12.1 — déclarer `federated.audit` ne suffit pas sur un GET** : le middleware n'audite les lectures que par **allowlist** (`federated_auth.audit.sensitive_get_routes`). Toute route GET qui **émet une identité, un jeton ou un secret** doit être ajoutée à cette liste en même temps qu'elle est déclarée — sinon l'alias est un no-op silencieux. Et le test qui le vérifie doit observer une **ligne d'audit réellement écrite**, jamais la seule présence de l'alias dans `routes/web.php`.
- **Section 12.3 — les bornes de longueur sont applicatives, jamais déléguées au SGBD** : SQLite (tests) n'applique aucune limite sur un `VARCHAR`, PostgreSQL (prod) refuse. Toute valeur issue d'une requête entrante et persistée dans une colonne bornée doit être contrôlée dans le code, avec un refus normalisé — sinon la seule preuve du problème arrive en production, en 500 hors journal métier. Les constantes (`OidcClientRegistry::MAX_REDIRECT_URI_LENGTH`, `OidcAuthorizationService::MAX_*_LENGTH`) sont alignées sur la migration : élargir une colonne impose de les élargir.
- **Section 11.5 — le journal est fin, la réponse est muette** : les codes `oidc.*` distinguent code inconnu / expiré / consommé pour le diagnostic ; la réponse HTTP dit toujours `invalid_grant`. Fusionner les deux — dans un sens ou dans l'autre — casse soit l'exploitabilité, soit la sécurité.
- **Section 13.1 / 13.3 — la minimisation se prouve par LISTE EXACTE, jamais par « contient »** : le contrat de claims est public et gelé (NFR11) ; une clé émise par erreur ne pourra plus être retirée et sera une fuite de PII permanente sur une population qui contient des élèves mineurs. Toute story qui touche aux claims doit conserver l'assertion d'ensemble exact **par combinaison de scopes** — un `assertArrayNotHasKey` ne couvre que ce à quoi on a pensé, une liste exacte échoue pour un claim auquel personne n'a pensé. Corollaire d'exploitation : ne JAMAIS ajouter un claim « en passant » pour dépanner une extension ; l'ajout est définitif.
- **Section 13.4 — un rôle non résolu produit une CLÉ ABSENTE, jamais une sentinelle** : ni `"autre"`, ni `null`, ni `""`. Une valeur sentinelle serait lue par l'extension comme un rôle réel ; l'absence la laisse fail-closed. Même règle pour tout claim futur : l'inconnu ne s'invente pas. La limite (identités fédérées, délégations non-`super-admin`) est **connue et assumée** — son raffinement appartient à 49.1/49.2, pas au SSO.
- **Section 13.5 — un scope inconnu est refusé, jamais ignoré** : l'ignorer laisserait un client croire qu'il a obtenu quelque chose, et attribuerait ce nom **rétroactivement** le jour où il deviendrait un vrai scope. La discovery (`scopes_supported`) et le validateur du flux lisent la **même** source (`OidcClaimsResolver::CLAIMS_BY_SCOPE`) : deux listes divergentes annonceraient un contrat non tenu — c'est vérifié par un test dédié.
- **Section 13.6 / 13.7 — `/userinfo` : Bearer en en-tête UNIQUEMENT, réponses de refus indistinctes** : un jeton en query finirait dans les logs, l'historique et le `Referer` (doctrine D-3, iso token endpoint) — RFC 6750 l'autorise, SE5 ne le supporte pas. Et les quatre causes « jeton présenté et rejeté » rendent le MÊME corps : distinguer « expiré » de « inconnu » offrirait un oracle. Le détail va au journal, jamais à la réponse — exactement la règle 11.5.
- **Section 13.6 — l'égalité `sub` id_token ⇄ `sub` userinfo est garantie PAR CONSTRUCTION** : `/userinfo` rend la valeur STOCKÉE sur le jeton (le sujet résolu à l'émission), il ne la re-résout pas. Et l'utilisateur est retrouvé par `oidc_access_tokens.user_id`, **jamais par le `sub`** : un `sub` est une valeur publiée, pas une clé de jointure — s'en servir casserait silencieusement le jour où le sujet changerait de nature (`OidcSubjectResolver`).
- **Section 13.8 — l'access_token est opaque POUR pouvoir mourir avec son client** : un JWT auto-porteur resterait valable jusqu'à son `exp` quoi qu'on fasse au registre. Désinstaller une extension doit couper l'accès immédiatement — toute évolution vers un access token auto-porteur romprait cette promesse.
- **Section 13.10 — un claim est une DONNÉE, pas une autorisation** : miroir exact du scénario 9.3 (« masquer n'est pas protéger », FR14). `role=prof` n'ouvre aucun droit dans SE5 et son absence n'en retire aucun. Une régression qui ferait de `role` une source d'autorisation dans SE5 créerait une escalade silencieuse.
- **Section 13.11 — `groups` n'est persisté nulle part** : il vit dans le JWT et le JSON `/userinfo`, jamais en colonne bornée — la leçon 12.3 (bornes applicatives) ne s'y applique donc pas, et 55.2 n'ajoute **aucun** paramètre entrant persisté. `/userinfo` est le canal de repli documenté si la taille du jeton devenait un jour un problème.
- **Section 11.1 — l'idempotence d'`oidc:keys:init` est vitale, pas confortable** : `update.sh` la rejoue à chaque déploiement de chaque instance. Même règle que pour toute opération multi-instance du projet : une commande artisan rejouable, jamais une procédure manuelle.
- **Section 7 — carte 54.1 restructurée** : la carte de bibliothèque était un `<a href>` entier (54.1) ; 54.2 sépare la zone cliquable (titre → fiche) du pied d'actions (`card-actions`) pour permettre des boutons `wire:click` sans navigation parasite ni HTML invalide.

---

## Checklist rapide

- [ ] 1.1 Seed initial : 1 source `bundled` (`url` vide, pas `null`) + 1 extension `doc`
- [ ] 1.2 Re-seed idempotent : aucun doublon, `updated_at` inchangé
- [ ] 1.3 Re-seed : le `status = integrated` survit
- [ ] 1.4 Manifest disparu : prune du `available`, conservation de l'`integrated`
- [ ] 2.1 Champ manquant : log nommant le champ, les autres manifests chargés
- [ ] 2.2 Type inconnu : log citant le type reçu et les types connus
- [ ] 2.3 Version non supportée : rejet strict (`2`, `"1.0"`, `"v1"`)
- [ ] 2.4 Version rejetée AVANT le contenu
- [ ] 2.5 JSON illisible : ignoré, seed en succès
- [ ] 2.6 Identifiant non-slug : rejet `field: id`
- [ ] 3.1 Entrée sidebar « Extensions » présente et active
- [ ] 3.2 Carte complète (nom, icône, type, éditeur, source, état, version)
- [ ] 3.3 État « Intégrée » affiché, aucun bouton d'action (54.1 = lecture seule)
- [ ] 3.4 Registre vide : état vide propre
- [ ] 3.5 Sans `server.admin` : entrée masquée + accès direct refusé
- [ ] 4.1 Fiche complète alimentée par le manifest
- [ ] 4.2 « Aucun scope demandé. » / « Aucune dépendance. »
- [ ] 4.3 Scopes et dépendances non vides affichés en badges
- [ ] 4.4 Public visé = rôles métier
- [ ] 4.5 Identifiant inconnu → 404
- [ ] 4.6 Identifiant non numérique → 404 de routage
- [ ] 4.7 Fiche refusée sans `server.admin`
- [ ] 5.1 Ingestion de contrat amont : registre intact
- [ ] 5.2 Rupture de lien : registre intact
- [ ] 5.3 Manifeste de sync vide : registre intact
- [ ] 6.1 Racine des manifests introuvable : catalogue PRÉSERVÉ, warning explicite
- [ ] 6.2 Racine présente mais vidée : prune légitime toujours actif
- [ ] 6.3 `visibility.roles` / `scopes` en objet JSON : rejetés
- [ ] 7.1 Intégrer depuis la bibliothèque : transition immédiate, badge + toast
- [ ] 7.2 Intégrer depuis la fiche : même comportement
- [ ] 7.3 Désinstaller avec modale : retour à « Disponible », toast, pas de saisie texte
- [ ] 7.4 Annulation : rien ne change, aucune ligne d'audit
- [ ] 7.5 No-op double-clic : toast info, aucune écriture, aucune ligne d'audit dupliquée
- [ ] 7.6 Tinker `extension_audit_logs` : 2 lignes ordonnées, append-only vérifié
- [ ] 7.7 Type `app` : aucun bouton, refus fail-closed si forcé
- [ ] 7.8 Refus sans `server.admin`
- [ ] 8.1 Double-clic de confirmation : succès puis info, jamais « Extension #0 »
- [ ] 8.2 Écran périmé : le no-op rafraîchit la carte (+ variante fiche disparue → retour bibliothèque)
- [ ] 8.3 Trace d'audit survivant au prune : 2 lignes, `extension_id` null, clé lisible
- [ ] 9.1 Tuile Documentation visible selon le rôle, après intégration, ouverture nouvel onglet
- [ ] 9.2 Tuile absente pour un rôle hors visibilité
- [ ] 9.3 Tuile masquée ≠ protection : accès direct `entry_url` toujours possible (FR14)
- [ ] 9.4 Disparition de la tuile après désinstallation (solde l'AC d'epic 54.2)
- [ ] 9.5 État vide propre : gaufre toujours présente, message explicite
- [ ] 9.6 Nouvel onglet : `target="_blank" rel="noopener"`, le lanceur reste ouvert (FR16)
- [ ] 9.7 Icône d'aide 52.8 et gaufre coexistent, désinstallation de la tuile n'affecte pas l'aide
- [ ] **10.1 Table `extensions` absente : TOUTES les pages restent en 200, gaufre en état vide, exception journalisée**
- [ ] 10.2 État vide réellement masqué quand des tuiles existent
- [ ] 10.3 Un administratif voit la tuile Documentation
- [ ] 10.4 `entry_url` à schéma dangereux refusée (`javascript:`, `data:`, `//`)
- [ ] 11.1 `oidc:keys:init` idempotente, permissions 0600/0644, `--force` sauvegarde
- [ ] 11.2 `oidc:client:register` : secret affiché une fois, sha256 en base, schémas d'URI bornés
- [ ] 11.3 Discovery + JWKS publics, `userinfo_endpoint` absent, JWKS fail-closed en 503
- [ ] 11.4 Flux complet : aucun login, `kid` présent, claims exacts, aucun secret au journal
- [ ] 11.5 Rejeu, expiration, verifier faux (code brûlé), secret faux → refus normalisés
- [ ] 11.6 Client inconnu / URI non déclarée / client révoqué → 400 **sans** `Location`
- [ ] 11.7 PKCE absent ou `plain`, `response_type`, `scope` → 302 `error` sur l'URI déclarée
- [ ] 11.8 Reprise post-login : query string complète préservée
- [ ] 11.9 Sync amont : client, code et jeton OIDC intacts
- [ ] 11.10 Révocation idempotente ; client inconnu = échec bruyant
- [ ] 12.1 Acteur fédéré : ligne `oidc.authorize` dans `external_action_audit_logs` ; acteur AD local : aucune
- [ ] **12.2 `Host` détourné : `url.intended` reste relatif, la reprise post-login ne quitte jamais l'instance**
- [ ] 12.3 `redirect_uri` / `nonce` trop longs : refus normalisé et journalisé, jamais une 500
- [ ] **13.1 Prof, `scope=openid profile groups` : `name`/`role="prof"`/`groups` triés — LISTE EXACTE des clés, aucune PII au journal**
- [ ] 13.2 Prof délégué `super-admin` : `role` reste `"prof"`
- [ ] 13.3 Élève : matrice des 3 scopes (`groups` absent sans le scope, aucun claim métier avec `openid` seul)
- [ ] **13.4 Rôle non résoluble (`autre` / fédéré) : claim `role` ABSENT, jamais `"autre"`**
- [ ] 13.5 `scope=openid profile foo` : 302 `invalid_scope`, aucun code, journal `oidc.scope_unsupported` (+ contrôle positif)
- [ ] 13.6 `/userinfo` GET et POST : `sub` identique à l'id_token, claims scope-gatés, `no-store`, journal sans PII
- [ ] **13.7 `/userinfo` : Bearer absent / inconnu / expiré / client révoqué / user supprimé / jeton en query → 401 INDISTINCTS**
- [ ] 13.8 Révocation du client : jeton déjà émis mort immédiatement
- [ ] 13.9 Discovery : `userinfo_endpoint` + scopes/claims enrichis, **aucune clé 55.1 retirée ni renommée**
- [ ] 13.10 Un claim n'autorise rien : `role=prof` n'ouvre aucune page admin, son absence n'en ferme aucune
- [ ] 13.11 ~40 groupes : id_token émis et vérifiable, `/userinfo` rend la même liste
- [ ] **14.1 Compte désactivé : `/userinfo` en 401 indistinct et échange de code en `invalid_grant`, réactivation OK**
- [ ] 14.2 FK `oidc_tokens_user_fk` présente en PostgreSQL, aucun log `oidc.migration.foreign_key_skipped`
- [ ] 15.1 `oidc:witness:enable` idempotente : fichier **0600**, secret **jamais affiché ni journalisé**, un seul client
- [ ] 15.2 `--rotate` : nouveau `client_id` + nouveau secret, ancien client `enabled = f` (jamais supprimé)
- [ ] 15.3 Fichier présent + client révoqué : échec bruyant nommant `--rotate`, aucun client fantôme
- [ ] **15.4 Clic sur la tuile « Démo SSO » : PKCE S256, aucun formulaire de login, « Bonjour {name} » + rôle + groupes, retour au lanceur**
- [ ] 15.5 Rôle non résoluble : « (non résolu) » affiché, jamais une valeur inventée
- [ ] **15.6 `state` altéré : refus 400 SANS échange de code (+ contrôle positif avec le bon `state`)**
- [ ] 15.7 Rejeu du callback : 502 explicite, aucune ligne « Bonjour »
- [ ] 15.8 Client révoqué : `/oidc/authorize` en 400 local **sans** `Location`
- [ ] 15.9 Témoin non provisionné : 503 sobre citant `oidc:witness:enable`, aucun appel sortant
- [ ] 15.10 `oidc:witness:disable` idempotente, et nettoie un fichier illisible
- [ ] **15.11 Quarantaine : `grep` sans correspondance + `ExtensionIsolationTest` vert (méta-test compris)**
- [ ] 15.12 Suite d'attaque NFR1 verte : `alg:none`, HS256/clé publique, `aud`/`iss`/clé étrangère, `kid` inconnu, expiration (+ tolérance), `nbf`, `nonce`, `jti` rejoué, malformé
- [ ] **15.13 Ni `ad_guid`, ni `dn`, ni `users.id` dans l'id_token et `/userinfo` ; `sub` = `login`**

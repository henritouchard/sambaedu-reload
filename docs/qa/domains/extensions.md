# QA Manuel — Extensions

**Domaine** : système d'extensions SE5 — registre local multi-sources, manifest déclaratif (contrat public), bibliothèque d'administration et fiches d'extension.

**Stories couvertes** (mise à jour **56.5 — Section 21** : sonde de santé persistée `ext:health:check` toutes les 5 min, tuile dégradée « Indisponible » qui SIGNALE sans jamais bloquer FR35/FR14, trois checks doctor auto-découverts `--tag=extensions`, carte « Santé » + « Sonder maintenant » sur la fiche, journal d'audit FR36 enfin consultable `/admin/extensions/journal`, signal d'échec d'écriture d'audit — **DERNIÈRE story, CLÔT l'Epic 56** ; antérieur 55.3 — **Section 15** : app-témoin SSO en quarantaine, suite d'attaque cliente NFR1, test d'architecture FR24, provisioning artisan idempotent — **DERNIÈRE story, CLÔT l'Epic 55** ; 55.2 — **Section 13** : contrat de claims v1 `name`/`role`/`groups` scope-gatés, `GET|POST /oidc/userinfo`, ensemble FERMÉ des scopes, fail-closed sur rôle et utilisateur irrésolus, discovery enrichie **additivement**) : 54.1 (socle : tables `extension_sources` + `extensions`, enums, validation du manifest v1, synchro de la source embarquée, pages `/admin/extensions` et `/admin/extensions/{id}`, frontière NFR14 avec la sync amont) ; 54.2 (intégrer/désinstaller le type `link` en un clic + confirmation par modale, journal d'audit `extension_audit_logs` FR36 socle, frontière NFR14 étendue à la 3ᵉ table) ; **54.3 (lanceur « gaufre » navbar : tuiles filtrées par rôle métier `User::businessRoles()`, ouverture nouvel onglet, état vide propre, NFR9 — 1 requête SQL / 0 HTTP) — DERNIÈRE story, clôt l'Epic 54**. **55.1 (SE5 fournisseur OIDC : registre des clients confidentiels, flux Authorization Code + PKCE S256, discovery et JWKS, id_token RS256, refus fail-closed journalisés, reprise du flux après login) — OUVRE l'Epic 55 (SSO)** ; 55.2 (contrat de claims v1 + `/userinfo`) ; **55.3 (app-témoin `app/OidcWitness/` atteinte par sa tuile, vérificateur client durci + anti-rejeu `jti`, suite d'attaque NFR1, quarantaine FR24, `oidc:witness:enable`/`disable`) — DERNIÈRE story, CLÔT l'Epic 55**. **56.1 (sources tierces + catalogue signé Ed25519 : format de dépôt v1 contrat public, pin de clé TOFU, fail-closed NFR2, dégradation NFR7, provenance impossible à ignorer FR4/UX-DR4, audit des sources FR36, `ext:sources:sync`) — OUVRE l'Epic 56** — **Section 17** ; 56.2 (installation signée d'une `app` : moteur `ext:install`/`ext:remove`, seam privilégié unique, secret OIDC par stdin, compensations, NFR8) — **Section 18** ; 56.3 (installation, mise à jour et retrait depuis l'UI : tâche de fond, progression persistée, rollback vérifié avant d'agir, verrou global reflété par l'UI) — **Section 19** ; **56.4 (scopes ACCORDÉS `oidc_clients.granted_scopes` octroyés à l'installation, révocation individuelle à effet IMMÉDIAT sur les jetons vivants FR23, API extensions `/api/ext/v1/` au format maison FR21/FR22, refus 401/403 sans fuite, FR24 prouvé, contrat v1 GELÉ NFR11) — **Section 20**, avec une REMISE À NIVEAU obligatoire des clients OIDC existants (`granted_scopes` vide après migration ⇒ fail-closed)**. **56.5 (santé des extensions et tolérance aux pannes : colonnes `health_*` écrites par un service unique, commande planifiée `ext:health:check`, badge de tuile lu et jamais mesuré NFR9, checks doctor `ExtensionsReachable`/`ExtensionsAuditTrail`/`ExtensionsOidcClients`, page journal `/admin/extensions/journal` au rendu tolérant, marqueur d'échec d'écriture d'audit acquittable) — **Section 21**, qui CLÔT l'Epic 56**.

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
- `database/migrations/2026_08_07_100000_add_health_columns_to_extensions.php` — colonnes `health_*` additives, hors `$fillable` (56.5)
- `app/Services/Extensions/ExtensionHealthService.php` — sonde `127.0.0.1:<port>` + persistance, **écrivain unique** des colonnes de santé, zéro audit (56.5)
- `app/Console/Commands/ExtensionHealthCheck.php` + `routes/console.php` — `ext:health:check {key?}`, planifiée `everyFiveMinutes` (56.5)
- `app/Models/Extension.php` — `isHealthMonitored()` / `healthIsStale()` / `isFlaggedUnreachable()` : LA règle du badge, un seul énoncé (56.5)
- `app/Doctor/Checks/Extensions/{ExtensionsReachableCheck,ExtensionsAuditTrailCheck,ExtensionsOidcClientsCheck}.php` — tag `extensions`, read-only strict (56.5)
- `app/Services/Extensions/ExtensionAuditJournalService.php` + `resources/views/pages/admin/extensions/journal/index.blade.php` — lecture FR36, rendu tolérant, aucune purge (56.5)
- `app/Models/ExtensionAuditLog.php` — `recordWriteFailure()` / `writeFailureMarker()` / `acknowledgeWriteFailure()`, cache FICHIER (legs review 56.3 #4)
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
- `database/migrations/2026_07_30_100000_add_remote_catalog_columns_to_extension_tables.php` — clé pinnée, état de synchro, audit de source (additive, 56.1)
- `app/Enums/ExtensionSourceSyncStatus.php` — la table de sémantique `ok`/`unreachable`/`error` (ce qui est proposé, ce qui est prunable)
- `app/Services/Extensions/CatalogSignatureVerifier.php` — vérification Ed25519 **pure**, base64 strict, fail-closed sans exception
- `app/Services/Extensions/RemoteCatalogSyncService.php` — **l'ordre inviolable** octets → bornes → signature → décodage → version → manifests ; bornes HTTP, `allow_redirects => false`, `last_error` sans URL
- `app/Services/Extensions/ExtensionSourceService.php` — add/enable/disable/remove/refresh, pin TOFU, gardes bundled et « intégrée bloque le retrait », audit
- `app/Services/Extensions/ExtensionCatalogService.php` — `syncManifestsForSource()` extrait (invariants #1-#4 partagés) ; `library()`/`find()` filtrent l'état de la source
- `app/Exceptions/ExtensionSourceException.php` — refus explicites destinés à l'admin
- `app/Console/Commands/ExtensionSourcesSync.php` + `routes/console.php` — `ext:sources:sync {key?}`, planification 02:50 (moteur unique AR1)
- `resources/views/pages/admin/extensions/sources/index.blade.php` — page des sources (ajout par modale, actualiser, activer/désactiver, retirer)
- `config/extensions.php` § `remote` — timeouts et borne de taille de l'index (bornes de sécurité, pas de réglage métier)
- `tests/Unit/Extensions/CatalogSignatureVerifierTest.php`, `tests/Feature/Extensions/{RemoteCatalogSyncServiceTest,ExtensionSourceServiceTest,ExtensionSourcesSyncCommandTest}.php`, `tests/Feature/Livewire/Admin/ExtensionSourcesPageTest.php` — signature, fail-closed, pin non renégocié, gardes, commande (56.1)
- `tests/Feature/OidcWitness/{OidcWitnessCommandsTest,ExtensionIdentityLeakTest}.php` — provisioning, et « aucun identifiant de base ni d'annuaire ne fuit »
- `tests/Architecture/ExtensionIsolationTest.php` — **FR24** : quarantaine du témoin (avec méta-test anti-tautologie), manifest sans champ exécutable, autoload sans répertoire d'extensions
- `database/migrations/2026_08_01_100000_add_app_install_columns_to_extension_tables.php` — `installed_version`/`installed_port`/`installed_at` + `details` d'audit (additive, 56.2)
- `app/Services/Extensions/ExtensionManifestValidator.php` § bloc `install` — canal fermé, chemin de paquet relatif borné, sha256 canonique, `redirect_paths` bornés à `/ext/<id>/`, règle AR3 `entry_url` des `app` (56.2)
- `app/Services/Extensions/Contracts/ExtensionHelperRunner.php` — **le seul seam privilégié** du domaine (et pourquoi ce n'est pas `CommandRunner`) (56.2)
- `app/Services/Extensions/SudoExtensionHelperRunner.php` — `sudo -n` + `proc_open`, stdin fermé avant lecture, chaque argument échappé (56.2)
- `app/Services/Extensions/ExtensionInstallService.php` — **le moteur** : chaîne de confiance, ordre en 9 étapes, compensations inverses, verrou fichier global, allocation de port, contenu du fichier d'environnement (56.2)
- `app/Services/Extensions/ExtensionLifecycleService.php` § `markAppInstalled`/`markAppRemoved` — transitions `app` ; `integrate()`/`uninstall()` restent `link`-only verbatim (56.2)
- `app/Services/Extensions/ExtensionLauncherService.php` — levée BORNÉE du filtre `type = link` : une `app` n'est une tuile qu'avec un `installed_port` (56.2)
- `app/Exceptions/ExtensionInstallException.php` — refus de CONTRAT (clé inconnue/ambiguë, moteur occupé, `link`) ; tous les autres refus passent par l'audit `install_failed`
- `app/Console/Commands/{ExtensionInstall,ExtensionRemove}.php` — `ext:install {key} [--source=]` / `ext:remove {key}` (56.2)
- `scripts/system/sambaedu-ext-helper.sh` — **la frontière de privilège** : namespace `sambaedu-ext-*`, chemins dérivés, `dpkg-deb --field`, fragment Apache généré, `configtest` avant reload (56.2)
- `scripts/dev/build-test-extension.sh` — fabrique paquet + dépôt signé pour la QA, sans root (outil interne ; l'outillage éditeur est en 58.2)
- `scripts/{install,update}.sh` § `ensure_extension_engine` — helper + sudoers validé `visudo -cf` + `a2enmod` + répertoires (56.2)
- `scripts/setupApache.sh` + `config/apache/sambaedu.conf` — `IncludeOptional /etc/apache2/sambaedu-ext.d/*.conf` DANS le vhost :80, les deux en phase (56.2)
- `config/extensions.php` § `install` — staging, borne de taille, timeouts, chemin du helper, plage de ports (bornes de sécurité, pas de réglage métier)
- `tests/Support/FakeExtensionHelperRunner.php` — doublure enregistreuse : la séquence privilégiée devient une assertion
- `tests/Unit/Extensions/{ExtensionManifestValidatorInstallBlockTest,SudoExtensionHelperRunnerTest}.php`, `tests/Feature/Extensions/{ExtensionInstallServiceTest,ExtensionInstallCommandsTest}.php` — bloc `install`, échappement/stdin, fail-closed, compensations par étape, no-op, unicité, ports, désinstallation (56.2)
- `database/migrations/2026_08_05_100000_add_update_tracking_and_extension_install_runs.php` — `extensions.installed_sha256` (gage de rollback) + table `extension_install_runs` (additive, 56.3)
- `app/Models/ExtensionInstallRun.php` — l'ÉTAT d'une opération (pas un journal d'audit) ; `isStale()` calculé côté PHP, jamais un `now()` SQL (56.3)
- `app/Services/Extensions/ExtensionOperationRunner.php` — création du run + dispatch DANS la même transaction, garde de concurrence sous verrou fichier COURT, et **seul lecteur** des runs pour les deux pages (56.3)
- `app/Jobs/RunExtensionOperationJob.php` — `tries = 1`, timeout configuré, **pas de `WithoutOverlapping`** (piège APCu daté), acteur rechargé par identifiant, les trois chemins d'erreur du moteur traités (56.3)
- `app/Services/Extensions/ExtensionInstallService.php` § `update()` — périmètre minimal (paquet + service), `redirect_paths` et gage de rollback vérifiés AVANT d'agir, compensation par ré-installation du `.deb` antérieur (56.3)
- `app/Services/Extensions/ExtensionInstallService.php` § `stepLabels()` / `mark()` — libellés d'étapes à un SEUL énoncé (4 consommateurs), rapport de progression isolé (il ne peut jamais faire échouer une opération) (56.3)
- `app/Services/Extensions/ExtensionLifecycleService.php` § `markAppUpdated()` — version + empreinte, RIEN d'autre (`installed_port`/`installed_at` invariants de la clé) (56.3)
- `app/Services/Extensions/ExtensionCatalogService.php` § `hasUpdateAvailable()` / `isAppInstallable()` — détection par ÉCART (jamais un ordre), calculée dans `toListRow()` donc héritée par la fiche (56.3)
- `app/Console/Commands/ExtensionUpdate.php` — `ext:update {key}`, troisième façade sur le même moteur (AR1) (56.3)
- `app/Exceptions/ExtensionOperationException.php` — refus de l'ORCHESTRATEUR (déjà en cours, écran périmé, type `link`) : toast, jamais une 500 (56.3)
- `resources/views/pages/admin/extensions/_partials/app-operation-modal.blade.php` — LA modale à 3 usages, partagée par la bibliothèque et la fiche (avertissement tierce verbatim, scopes affichés et non accordés) (56.3)
- `config/extensions.php` § `install.job_timeout` — borne technique du Job **et** seuil de staleness (AR14 : pas un réglage métier)
- `tests/Feature/Extensions/{ExtensionInstallServiceUpdateTest,ExtensionOperationRunnerTest,RunExtensionOperationJobTest,ExtensionUpdateCommandTest}.php`, `tests/Feature/Livewire/Admin/ExtensionAppOperationsPageTest.php`, `tests/Unit/Extensions/ExtensionUpdateDetectionTest.php`, `tests/Unit/Models/ExtensionInstallRunTest.php` — update et ses refus, concurrence, couture Job/runs, UI et gardes (56.3)

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

## Section 16 — Correctifs de review 55.3

> Ajoutée après la review sonnet de la Story 55.3 (dev opus), findings évalués et **rejoués** par l'orchestrateur. Clôt l'Epic 55.

### Scénario 16.1 — La quarantaine de l'app-témoin détecte réellement une triche

**Contexte** : `ExtensionIsolationTest` verrouille la propriété centrale de l'Epic 55 — le témoin ne prouve que le contrat public **parce qu'il n'a accès à rien d'autre**. Ce verrou est un scan **textuel**, et trois de ses règles utilisaient un lookbehind qui excluait l'antislash de tête : `\auth()`, `\Illuminate\Support\Facades\Auth::`, un alias d'import et le conteneur (`app()`, `resolve()`) passaient sans être vus.

Ce scénario est un **test de mutation manuel** : on casse volontairement, on vérifie que le garde-fou crie, on remet en état.

1. Dans `app/OidcWitness/Http/Controllers/WitnessController.php`, ajouter une ligne : `$u = \Illuminate\Support\Facades\Auth::user();`
2. `php artisan test --filter=ExtensionIsolation`
3. Retirer la ligne. Recommencer avec `$db = app('db');`, puis avec `use Illuminate\Support\Facades\Auth as CurrentUser;`
4. Retirer la ligne et relancer.

**Attendu** :
- (2) et (3) : **échec** de `the_witness_namespace_cannot_reach_anything_but_the_public_contract`, message « QUARANTAINE ROMPUE (FR24) » nommant le fichier et la règle violée.
- (4) : suite **verte** à nouveau.

**Pourquoi ce scénario existe** : avant le correctif, les trois manipulations ci-dessus laissaient la suite **entièrement verte**. Le témoin pouvait lire la session SE5 et la conclusion de l'epic — « le contrat public suffit à un client honnête » — devenait fausse sans que rien ne le signale. Un test de preuve qui ne prouve pas est pire qu'aucun test : il rassure.

### Scénario 16.2 — La limite du scan textuel est connue, écrite, et assumée

Lire `tests/Architecture/ExtensionIsolationTest.php::the_textual_scan_has_a_documented_residual_limit` et `app/OidcWitness/README.md`.

**Attendu** : il est écrit noir sur blanc qu'un FQCN concaténé à l'exécution (`"App" . "\Models\User"`) **passera toujours**. Le scan ne rend pas la triche impossible — il la rend délibérée et visible en revue.

**Pourquoi ce scénario existe** : pour que personne ne prenne ce garde-fou pour une barrière hermétique et ne cesse de relire les diffs du témoin. Si ce test venait à être supprimé au motif que « la quarantaine est étanche », c'est le raisonnement qu'il faudrait rouvrir.

---

## Section 17 — Sources tierces et catalogue signé (Story 56.1) — **OUVRE l'Epic 56**

> **Ce que cette section valide.** SE5 sait désormais tirer des extensions d'un dépôt qui n'est pas le sien. Toute la sûreté de cette ouverture tient à trois propriétés, et ce sont les trois seules choses à vérifier vraiment :
>
> 1. **la signature du catalogue est vérifiée AVANT que quoi que ce soit du contenu ne soit lu** ;
> 2. **aucun chemin d'échec n'écrit ni ne supprime la moindre extension** (le registre EST le cache local — NFR7) ;
> 3. **la clé publique d'une source est pinnée à son ajout et n'est jamais renégociée** (modèle `known_hosts` / keyring apt).
>
> Le reste — badges, avertissements, boutons — sert à ce que l'admin ne puisse pas installer une extension tierce en croyant installer une extension officielle (FR4/UX-DR4).
>
> **Dette worktree assumée, iso 54.x/55.x** : story développée dans un worktree git non synchronisé vers la VM. La suite automatisée tourne sur l'HÔTE ; les scénarios ci-dessous sont à jouer **au merge sur `main`**, après `php artisan migrate`.

### Pré-requis de la section — fabriquer un dépôt de test signé

Aucun outillage de publication n'existe encore (c'est la Story 58.2) : on le fait à la main, ce qui a l'avantage de montrer à quel point le format est simple.

```bash
cd /var/www/sambaedu-reload
php artisan migrate
php artisan db:seed --class=BundledExtensionSeeder --force

# 1. La paire de la SOURCE (elle appartient à l'éditeur, jamais à SE5).
php -r '$k=sodium_crypto_sign_keypair();
  file_put_contents("/tmp/depot.sk", base64_encode(sodium_crypto_sign_secretkey($k)));
  file_put_contents("/tmp/depot.pub", base64_encode(sodium_crypto_sign_publickey($k)));'

# 2. Le dépôt statique : index.json + sa signature détachée + la clé publique.
mkdir -p /var/www/depot-test
cat > /var/www/depot-test/index.json <<'JSON'
{
  "index_version": 1,
  "name": "Dépôt de test",
  "publisher": "QA SambaEdu",
  "extensions": [
    { "manifest_version": 1, "id": "agenda-test", "type": "link", "name": "Agenda de test",
      "version": "1.0.0", "entry_url": "https://example.org/agenda", "publisher": "QA",
      "description": "Extension de test tierce.", "icon": "fa-solid fa-calendar",
      "scopes": [], "dependencies": [], "visibility": { "roles": ["admin", "prof"] } },
    { "manifest_version": 1, "id": "resa-test", "type": "app", "name": "Réservation (app)",
      "version": "0.1.0", "entry_url": "/ext/resa-test", "publisher": "QA",
      "scopes": ["profile"], "dependencies": [], "visibility": { "roles": ["prof"] } }
  ]
}
JSON

php -r 'file_put_contents("/var/www/depot-test/index.json.sig",
  base64_encode(sodium_crypto_sign_detached(
    file_get_contents("/var/www/depot-test/index.json"),
    base64_decode(file_get_contents("/tmp/depot.sk"), true))));'
cp /tmp/depot.pub /var/www/depot-test/source.pub
```

Publier ce dossier derrière un Alias Apache (ou n'importe quel serveur statique) : l'URL de BASE du dépôt est celle du **dossier**, jamais celle d'`index.json`.

### Scénario 17.1 — Ajouter une source avec sa clé collée

1. `/admin/extensions` → bouton **« Gérer les sources »** → `/admin/extensions/sources`.
2. **« Ajouter une source »** : Nom = `Dépôt de test`, Adresse = l'URL du dossier, Clé publique = contenu de `/tmp/depot.pub`.
3. Valider.

**Attendu** :
- toast de succès mentionnant **2 extensions** ; la carte de la source affiche le badge **« Tierce »** (icône + libellé), l'état **« Catalogue vérifié »**, la date de synchro et une empreinte abrégée de la clé ;
- `/admin/extensions` liste **Agenda de test** ET **Réservation (app)**, chacune badgée **« Tierce »** ;
- **Réservation (app)** n'a **aucun bouton d'action** : le type `app` s'affiche, il ne s'installe pas (Story 56.2) ;
- `SELECT key, kind, is_official, enabled, sync_status, last_error FROM extension_sources;` → `is_official = f`, `sync_status = ok`, `last_error` vide.

### Scénario 17.2 — Ajouter une source SANS coller la clé (TOFU https)

1. Retirer la source du 17.1 (Scénario 17.9).
2. La rajouter en laissant le champ **Clé publique VIDE**, avec une URL en **`https://`**.

**Attendu** : SE5 lit `<url>/source.pub` **une seule fois** et la pinne. Le contrôle décisif est côté **journal d'accès du serveur du dépôt** : `grep source.pub /var/log/apache2/access.log` doit montrer **exactement une** requête, à l'ajout. Aucune actualisation ultérieure ne doit en produire d'autre (revérifier après le 17.5).

### Scénario 17.3 — Un dépôt en `http://` sans clé collée est refusé

1. Ajouter une source dont l'URL commence par `http://` en laissant la clé vide.

**Attendu** : refus explicite (« un dépôt en http:// exige que vous colliez sa clé publique vous-même »), **la modale reste ouverte avec la saisie**, aucune ligne créée dans `extension_sources`, et **aucune requête sortante** (vérifiable au journal d'accès du dépôt).

**Pourquoi** : sur un canal en clair, n'importe quel intermédiaire réseau servirait SA clé et signerait SON catalogue. La signature ne prouverait alors plus rien du tout. La contre-épreuve fait partie du scénario : la même URL `http://` **avec** la clé collée doit, elle, être **acceptée** — le miroir LAN hors ligne (AR9) reste un cas d'usage légitime.

### Scénario 17.4 — Signature invalide ⇒ fail-closed, et rien n'est perdu

1. Partir d'une source en état `ok` avec ses 2 extensions.
2. Intégrer **Agenda de test** (voir 17.6) pour avoir une extension en service.
3. Altérer le catalogue **sans le re-signer** : `sed -i 's/Agenda de test/Agenda PIRATÉ/' /var/www/depot-test/index.json`
4. Sur `/admin/extensions/sources` → **« Actualiser »**.

**Attendu** :
- la source passe **« Catalogue refusé »** (badge rouge) avec un message court ;
- `/admin/extensions` : l'extension **`available`** de cette source **disparaît** ; l'extension **intégrée reste listée**, avec un badge d'état signalant la source ;
- la **tuile du lanceur de l'extension intégrée est intacte** (ouvrir la gaufre) ;
- `SELECT count(*) FROM extensions;` : **le compte n'a pas bougé** — rien n'a été supprimé, rien n'a été écrit ;
- le nom `Agenda PIRATÉ` n'apparaît **nulle part** : le contenu non vérifié n'a jamais été lu.

Puis re-signer (`php -r` du pré-requis) et **« Actualiser »** : la source repasse « Catalogue vérifié » et le nouveau nom apparaît. Sans cette contre-épreuve, on n'a prouvé qu'une chose : que le bouton ne marche pas.

### Scénario 17.5 — La clé pinnée ne se renégocie jamais

1. Générer une **nouvelle paire** pour le dépôt, re-signer `index.json` avec elle, et remplacer `source.pub` par la nouvelle clé publique.
2. **« Actualiser »**.

**Attendu** : la source passe **« Catalogue refusé »**. `SELECT public_key FROM extension_sources WHERE key='depot-de-test';` → **la clé d'origine, inchangée**. `grep source.pub` dans le journal d'accès du dépôt → **aucune nouvelle requête**.

**Le remède est un ACTE de l'admin** : retirer la source, la rajouter avec la nouvelle clé. Deux lignes d'audit, deux décisions humaines.

**Pourquoi c'est le scénario le plus important de la section** : c'est exactement ce qui se passerait si le dépôt était compromis. Un système qui re-téléchargerait la clé accepterait la substitution sans broncher, et la signature ne serait plus qu'une décoration.

### Scénario 17.6 — Provenance impossible à ignorer

1. Sur `/admin/extensions`, comparer une carte **officielle** (Documentation, source embarquée) et une carte **tierce**.
2. Cliquer **« Intégrer »** sur l'extension **tierce**.
3. Ouvrir la **fiche** de l'extension tierce.
4. Cliquer **« Intégrer »** sur l'extension **officielle**.

**Attendu** :
- (1) badge **« Officielle »** (certificat, vert) vs **« Tierce »** (triangle d'avertissement, orange) — **jamais** une simple différence de couleur ;
- (2) une **modale d'avertissement** s'ouvre : « **Source non officielle : `<hôte du dépôt>`** — vous installez sous votre responsabilité ». L'hôte affiché est bien celui de l'URL de la source. Annuler ⇒ rien ne se passe ; confirmer ⇒ l'extension est intégrée et la tuile apparaît au lanceur pour les rôles visés ;
- (3) la fiche porte le **même avertissement en encart** permanent ;
- (4) l'extension **officielle** s'intègre toujours **en un clic**, sans modale (comportement 54.2 inchangé).

Double-cliquer rapidement sur « Intégrer quand même » ne doit produire ni erreur ni double ligne d'audit (`SELECT action, count(*) FROM extension_audit_logs GROUP BY action;`).

### Scénario 17.7 — Dépôt injoignable : dégradation propre (NFR7)

1. Avec une extension tierce **intégrée** et en service, rendre le dépôt injoignable (arrêter le serveur statique, ou couper la résolution DNS de son hôte).
2. **« Actualiser »**, puis recharger `/admin/extensions` et le lanceur.

**Attendu** :
- la source passe **« Dépôt injoignable »** (badge orange) ; `last_synced_at` **conserve la date de la dernière synchro RÉUSSIE** ;
- **les extensions `available` restent proposées** : le dernier catalogue vérifié est toujours valable, le registre EST le cache local ;
- la tuile de l'extension intégrée fonctionne ;
- `SELECT count(*) FROM extensions;` inchangé.

Contre-épreuve : rétablir le dépôt, « Actualiser », la source repasse au vert.

**Différence à comprendre** : `unreachable` (réseau) ne masque rien ; `error` (signature) masque les `available`. C'est délibéré — un incident réseau ne doit rien changer pour l'admin, un contenu non authentifiable ne doit plus rien proposer.

### Scénario 17.8 — Une redirection n'est jamais suivie

1. Configurer le serveur du dépôt pour répondre `302` sur `index.json` vers une autre machine.
2. **« Actualiser »**.

**Attendu** : source **« Dépôt injoignable »** ; le journal d'accès de la machine CIBLE de la redirection ne montre **aucune** requête de SE5.

**Pourquoi** : ne pas suivre les redirections du tout est plus simple ET plus sûr qu'une liste blanche d'hôtes — un dépôt ne peut pas se servir de SE5 comme d'un client HTTP vers un serveur qu'il choisit.

### Scénario 17.9 — Désactiver, réactiver, retirer une source

1. **Désactiver** la source (une de ses extensions étant intégrée).
2. Recharger `/admin/extensions` et le lanceur.
3. Tenter de **Retirer** la source.
4. Désinstaller l'extension intégrée depuis la bibliothèque, puis **Retirer** à nouveau.
5. Vérifier la source embarquée.

**Attendu** :
- (2) les extensions **non intégrées** de la source disparaissent de la bibliothèque et leur fiche répond **404** (tester l'URL directe `/admin/extensions/<id>`) ; l'extension **intégrée reste visible**, signalée « Source désactivée », et **garde sa tuile** ;
- (3) **retrait REFUSÉ**, avec un message **nommant l'extension bloquante** ;
- (4) le retrait passe ; `SELECT count(*) FROM extensions WHERE extension_source_id = <id>;` → 0 (cascade FK) ;
- (5) la carte de la source **embarquée** n'expose **aucun bouton** (ni Actualiser, ni Désactiver, ni Retirer) et affiche pourquoi.

**Pourquoi le refus (3)** : retirer la source emporterait ses extensions par cascade, donc des tuiles **en service**, sans que personne ne l'ait décidé. On ne dé-intègre jamais silencieusement.

### Scénario 17.10 — Moteur unique : commande artisan et planification

```bash
php artisan ext:sources:sync                 # toutes les sources distantes ACTIVES
php artisan ext:sources:sync depot-de-test   # une seule
echo "code retour : $?"
php artisan schedule:list | grep ext:sources:sync
```

**Attendu** :
- tableau récapitulatif (source, statut, compteurs) ; **code retour 0** si tout est vérifié, **non-zéro** si au moins une source est en erreur ou injoignable ;
- une source **désactivée** nommée explicitement est **refusée** (« réactivez-la avant de la synchroniser ») — la commande ne contourne pas la décision de l'admin, et le journal d'accès du dépôt le confirme (aucune requête) ;
- `schedule:list` montre `ext:sources:sync` à **02:50** ;
- rejouer la commande deux fois de suite ne modifie **aucune** ligne (`updated_at` des `extensions` inchangés) : la synchro est idempotente.

### Scénario 17.11 — Le journal d'audit des sources (FR36)

```sql
SELECT action, source_key, actor_login, created_at
FROM extension_audit_logs
WHERE action LIKE 'source_%'
ORDER BY id;
```

**Attendu** :
- une ligne `source_add` / `source_enable` / `source_disable` / `source_remove` par acte **réel**, avec le **login de l'admin** ;
- **désactiver une source déjà désactivée n'écrit RIEN** (no-op = zéro ligne, discipline 54.2) ;
- `source_sync_failed` apparaît **une seule fois** par entrée en erreur, quel que soit le nombre de re-synchros ratées ensuite (rejouer `ext:sources:sync` trois fois sur un dépôt à signature invalide et recompter) ; l'acteur d'une synchro planifiée est **`system`** ;
- une synchro **réussie** n'écrit **aucune** ligne (c'est de la télémétrie, `last_synced_at` la porte), un dépôt **injoignable** non plus ;
- après le **retrait** d'une source, la ligne `source_remove` **subsiste** avec sa `source_key` lisible (`extension_source_id` passe à `NULL`).

### Scénario 17.12 — Aucun secret dans ce qui est persisté ou affiché

1. Enregistrer une source dont l'URL porterait un jeton — **elle doit être refusée à la saisie** (une URL avec `?` ou avec `user:pass@` n'est pas acceptée).
2. Provoquer une panne réseau sur une source normale, puis lire ce qui est stocké :

```sql
SELECT key, last_error FROM extension_sources;
```

**Attendu** : `last_error` est une **catégorie courte** (« dépôt injoignable (HTTP 503 sur index.json) »), **jamais** une URL, jamais un message d'exception Guzzle. Le détail complet — URL comprise — n'existe que dans le journal serveur (`storage/logs/laravel.log`), qui n'est pas exposé à l'admin dans l'UI.

**Pourquoi** : Guzzle suffixe systématiquement l'URI complète à ses messages d'erreur, et une URL de dépôt GitLab peut porter `?private_token=…`. Le piège est documenté depuis la review 39.4 #E11 d'`ArtifactPullService` ; il se rejoue à l'identique ici.

---

## Section 18 — Installation signée d'une extension `app` (Story 56.2)

> **Ce que cette section valide.** SE5 sait maintenant *installer* — c'est-à-dire écrire dans `/etc`, faire tourner `apt`, activer une unité systemd et recharger Apache — à partir d'un manifest publié par un tiers. Trois propriétés portent toute la sûreté de cette ouverture, et ce sont les trois seules à vérifier vraiment :
>
> 1. **rien de tiers ne s'exécute avant que son hash n'ait été vérifié.** La première exécution de code tiers, c'est le maintainer script d'apt (`preinst`/`postinst`, en root). Le sha256 du paquet — porté par l'index déjà signé Ed25519 de la 56.1 — est comparé AVANT le premier appel au helper. Un sha qui ne colle pas ⇒ apt n'est jamais invoqué ;
> 2. **un échec à mi-parcours ne laisse pas d'installation zombie.** Chaque étape a sa compensation, exécutées en ordre inverse ; la base est écrite en DERNIER ;
> 3. **www-admin n'exécute jamais rien en root directement.** Il appelle UN script racine, qui re-valide tout ce qu'il reçoit et génère lui-même le fragment Apache.
>
> Ce que la suite automatisée prouve déjà sur l'hôte (ordre des étapes, fail-closed, compensations par étape, idempotence, unicité des clés, allocation de port, contenu du fichier d'environnement, secret jamais en argv) n'a **pas** à être rejoué ici. Cette section couvre exactement ce qu'une doublure ne peut pas prouver : **le helper bash, le provisioning ops, et le parcours réel de bout en bout.**
>
> **Dette worktree assumée, iso 54.x/55.x/56.1** : story développée dans un worktree git non synchronisé vers la VM. À jouer **au merge sur `main`**, après `bash scripts/update.sh` (qui déploie helper + sudoers + vhost, puis migre).

### Pré-requis de la section — le dépôt de test et son paquet

```bash
cd /var/www/sambaedu-reload
bash scripts/update.sh          # déploie le helper, le sudoers, l'IncludeOptional, puis migre

# Fabrique le paquet ET le dépôt signé (aucun privilège requis)
bash scripts/dev/build-test-extension.sh /tmp/ext-hello

# Sert le dépôt (garder ce terminal ouvert : son journal d'accès sert aux scénarios 18.6 et 18.9)
cd /tmp/ext-hello/repo && python3 -m http.server 8099
```

Puis, dans SE5 (`/admin/extensions/sources`) → « Ajouter une source » :

- **URL** : `http://<ip du serveur>:8099`
- **Clé publique** : le contenu de `/tmp/ext-hello/repo/source.pub` — **obligatoire**, l'URL étant en `http://` (règle 17.3, inchangée).

```bash
php artisan ext:sources:sync
```

**Attendu** : l'extension « Hello (test) » apparaît dans la bibliothèque, badge **Tierce**, type **Application**, **sans aucun bouton d'action** (l'UI d'installation est la Story 56.3 ; en 56.2 le canal est la ligne de commande).

### Scénario 18.1 — Le provisioning ops a bien eu lieu

```bash
ls -l /usr/share/sambaedu/sbin/sambaedu-ext-helper.sh   # 0755 root:root
cat /etc/sudoers.d/sambaedu-ext                          # une seule ligne www-admin
ls -ld /etc/sudoers.d/sambaedu-ext                       # 0440
ls -ld /etc/apache2/sambaedu-ext.d /etc/sambaedu/extensions
apache2ctl -M | grep -E 'proxy_module|proxy_http_module|headers_module'
grep -n 'IncludeOptional /etc/apache2/sambaedu-ext.d' /etc/apache2/sites-available/sambaedu.conf
sudo -u www-admin sudo -n /usr/share/sambaedu/sbin/sambaedu-ext-helper.sh 2>&1 | head -3
```

**Attendu** : helper exécutable, sudoers en **0440**, `/etc/sambaedu/extensions` en **0700 root** (www-admin ne doit PAS pouvoir lire un secret de client OIDC), `/etc/apache2/sambaedu-ext.d` en 0755, les trois modules chargés, l'`IncludeOptional` présent **dans le vhost :80** et **pas** dans le vhost legacy 8082. Le dernier appel doit afficher l'usage du helper (donc `sudo -n` fonctionne pour www-admin) et **pas** « a password is required ».

**Rejouer `bash scripts/update.sh`** : tout doit être signalé « déjà à jour / déjà en place », sans réécriture.

### Scénario 18.2 — Installation réelle de bout en bout

```bash
sudo -u www-admin php artisan ext:install hello
```

**Attendu** — la commande liste les étapes dans cet ordre, puis :

```bash
systemctl is-active sambaedu-ext-hello.service      # active
systemctl is-enabled sambaedu-ext-hello.service     # enabled
ls -l /etc/sambaedu/extensions/hello.env            # -rw------- root root
cat /etc/apache2/sambaedu-ext.d/hello.conf          # ProxyPass /ext/hello -> 127.0.0.1:8600
ss -lntp | grep 8600                                # écoute sur 127.0.0.1 SEULEMENT, jamais 0.0.0.0
curl -s http://se4fs/ext/hello/                     # 200, « hello depuis l'extension de test SE5 »
```

**Attendu aussi** :

- la tuile « Hello (test) » apparaît au lanceur (gaufre) pour un admin et un prof, et pointe **`/ext/hello`** ;
- en base : `SELECT key, status, installed_version, installed_port, installed_at FROM extensions WHERE key='hello';` → `integrated`, `1.0.0`, `8600`, horodatage ;
- une ligne d'audit : `SELECT action, details, actor_login FROM extension_audit_logs ORDER BY id DESC LIMIT 1;` → `install`, `details` vide, acteur **`system`** ;
- un client OIDC actif : `SELECT client_id, extension_key, enabled FROM oidc_clients WHERE extension_key='hello';`.

**Le secret** : `grep SE5_OIDC_CLIENT_SECRET /etc/sambaedu/extensions/hello.env` en tant que **root** le montre ; en tant que www-admin la lecture doit être **refusée**. Il ne doit apparaître ni dans la sortie de la commande, ni dans `storage/logs/laravel.log`, ni dans `journalctl -u sambaedu-ext-hello`, ni dans le journal de sudo (`/var/log/auth.log` : la ligne `sudo` ne porte que `write-env hello`).

### Scénario 18.3 — Le no-op est vraiment un no-op

```bash
sudo -u www-admin php artisan ext:install hello ; echo "exit=$?"
```

**Attendu** : message « déjà installée », **exit 0**, **aucune** nouvelle ligne dans `extension_audit_logs`, **aucune** entrée `sudo` dans `/var/log/auth.log`, et aucune requête sur le journal du serveur de test (rien n'a été re-téléchargé).

### Scénario 18.4 — Le helper refuse ce qu'il doit refuser

À jouer **en root**, directement sur le helper : c'est la partie qu'aucune doublure ne peut valider.

```bash
H=/usr/share/sambaedu/sbin/sambaedu-ext-helper.sh
$H write-env '../evil'                    ; echo "exit=$?"   # clé invalide
$H write-env 'hello; id'                  ; echo "exit=$?"   # clé invalide
$H write-fragment hello 80                ; echo "exit=$?"   # port hors format
$H write-fragment hello 'x'               ; echo "exit=$?"   # port hors format
$H install-package hello /tmp/quelconque.deb ; echo "exit=$?" # hors staging
$H sous-commande-inconnue                 ; echo "exit=$?"
```

**Attendu** : `exit=2` à chaque fois, message explicite en **stderr**, et **aucun fichier créé** dans `/etc/sambaedu/extensions` ni `/etc/apache2/sambaedu-ext.d`.

Puis le contrôle qui protège les paquets système — un `.deb` parfaitement formé mais mal nommé :

```bash
cd /tmp && mkdir -p faux/DEBIAN && printf 'Package: openssh-server\nVersion: 9.9\nArchitecture: all\nMaintainer: x <x@x>\nDescription: faux\n' > faux/DEBIAN/control
dpkg-deb --build --root-owner-group faux /var/www/sambaedu-reload/storage/app/extensions/packages/hello/faux.deb
$H install-package hello /var/www/sambaedu-reload/storage/app/extensions/packages/hello/faux.deb ; echo "exit=$?"
```

**Attendu** : refus (`nom de paquet refusé : « openssh-server » (attendu « sambaedu-ext-hello »)`), **exit ≠ 0**, `apt-get` jamais invoqué. Nettoyer ensuite le faux `.deb`.

Enfin, le lien symbolique — le contournement le plus naturel du contrôle de staging :

```bash
ln -s /etc/shadow /var/www/sambaedu-reload/storage/app/extensions/packages/hello/lien.deb
$H install-package hello /var/www/sambaedu-reload/storage/app/extensions/packages/hello/lien.deb ; echo "exit=$?"
rm -f /var/www/sambaedu-reload/storage/app/extensions/packages/hello/lien.deb
```

**Attendu** : refus (le chemin est comparé **après** `readlink -f`).

### Scénario 18.5 — `configtest` protège Apache d'un fragment cassé

```bash
$H remove-fragment hello
printf 'ProxyPass "/ext/hello" "ceci-nest-pas-une-url\n' > /etc/apache2/sambaedu-ext.d/zz-casse.conf
$H reload-apache ; echo "exit=$?"
systemctl is-active apache2
rm -f /etc/apache2/sambaedu-ext.d/zz-casse.conf
$H reload-apache ; echo "exit=$?"
```

**Attendu** : le premier `reload-apache` échoue (**exit ≠ 0**, sortie de `apache2ctl configtest` en erreur) **sans recharger**, Apache reste **actif** et sert toujours l'application. Après retrait du fragment fautif, le reload réussit. C'est la garantie qu'un fragment invalide ne peut jamais mettre le serveur à terre : le moteur voit l'échec et compense.

### Scénario 18.6 — Signature invalide ⇒ fail-closed, sans la moindre trace système

Altérer le paquet du dépôt **sans re-signer l'index**, puis réinstaller depuis zéro :

```bash
sudo -u www-admin php artisan ext:remove hello
printf 'contenu-substitue' >> /tmp/ext-hello/repo/packages/sambaedu-ext-hello_1.0.0_all.deb
sudo -u www-admin php artisan ext:install hello ; echo "exit=$?"
```

**Attendu** :

- **exit 1**, message « sha256 du paquet non concordant » ;
- `journalctl -u apache2 --since '2 min ago'` : **aucun** reload ; `/etc/apache2/sambaedu-ext.d/` : **vide** ;
- `/etc/sambaedu/extensions/` : **vide** ; `dpkg -l | grep sambaedu-ext-hello` : rien ;
- `grep sambaedu-ext-helper /var/log/auth.log | tail -5` : **aucune** invocation postérieure au `remove` — c'est la preuve terrain de « zéro exécution privilégiée » ;
- `SELECT count(*) FROM oidc_clients WHERE extension_key='hello' AND enabled;` → **0** ;
- audit : une ligne `install_failed`, `details = 'sha256 du paquet non concordant'`, **sans URL** ;
- `storage/app/extensions/packages/hello/` : **aucun** fichier `.tmp` résiduel.

**Contre-épreuve indispensable** : re-générer le dépôt (`bash scripts/dev/build-test-extension.sh /tmp/ext-hello`), re-synchroniser (`ext:sources:sync`) puis réinstaller — l'installation doit réussir. Sans elle, le refus ci-dessus pourrait n'être que le symptôme d'un dépôt cassé.

### Scénario 18.7 — Échec à mi-parcours ⇒ état propre, relance sans intervention

Provoquer un échec **après** la vérification, du côté apt. Le plus simple est de casser la dépendance du paquet de test :

```bash
sudo -u www-admin php artisan ext:remove hello
# Rendre `apt-get install` impossible : verrou dpkg tenu par un autre processus
( flock -x 9 ; sleep 120 ) 9>/var/lib/dpkg/lock-frontend &
sudo -u www-admin php artisan ext:install hello ; echo "exit=$?"
```

**Attendu** :

- **exit 1**, message « échec à l'étape apt_install » ;
- `/etc/sambaedu/extensions/hello.env` : **absent** (compensation `remove-env` jouée) ;
- `/etc/apache2/sambaedu-ext.d/` : vide ; aucune unité `sambaedu-ext-hello` ;
- `SELECT status, installed_port FROM extensions WHERE key='hello';` → `available`, `NULL` — **aucune installation zombie** ;
- `SELECT enabled FROM oidc_clients WHERE extension_key='hello';` → **`f`** (révoqué, jamais supprimé — doctrine 55.1) ;
- **le paquet vérifié est CONSERVÉ** : `ls storage/app/extensions/packages/hello/` montre `<sha256>.deb`.

Puis, une fois le verrou relâché :

```bash
sudo -u www-admin php artisan ext:install hello ; echo "exit=$?"
```

**Attendu** : succès **sans aucune intervention manuelle**, et **aucune nouvelle requête** dans le journal du serveur de test (`python3 -m http.server`) : le paquet content-addressed déjà vérifié est réutilisé.

### Scénario 18.8 — Désinstallation, et ce qu'elle emporte

```bash
sudo -u www-admin php artisan ext:remove hello ; echo "exit=$?"
curl -s -o /dev/null -w '%{http_code}\n' http://se4fs/ext/hello/   # 404
systemctl status sambaedu-ext-hello.service 2>&1 | head -2          # unité inconnue
dpkg -l | grep sambaedu-ext-hello                                   # rien
ls /etc/sambaedu/extensions/ /etc/apache2/sambaedu-ext.d/           # vides
ls /var/www/sambaedu-reload/storage/app/extensions/packages/        # plus de dossier hello
```

**Attendu aussi** : la tuile disparaît du lanceur ; `SELECT status, installed_version, installed_port, installed_at FROM extensions WHERE key='hello';` → `available`, `''`, `NULL`, `NULL` ; audit `remove` ; `SELECT enabled FROM oidc_clients WHERE extension_key='hello';` → **tous `f`**.

**Idempotence** : rejouer `ext:remove hello` ⇒ message « n'est pas installée », **exit 0**, aucune ligne d'audit supplémentaire.

**Refus du type `link`** : `php artisan ext:remove doc` ⇒ **exit 1** et message pointant la bibliothèque (le volet `link` de FR10 est livré depuis la 54.2 ; il ne doit pas exister deux chemins d'audit pour le même acte).

### Scénario 18.9 — Les jetons de l'extension meurent avec elle

Avant la désinstallation, obtenir un `access_token` pour l'extension (mécanique 55.2, cf. scénario 13.8), puis :

```bash
sudo -u www-admin php artisan ext:remove hello
curl -s -o /dev/null -w '%{http_code}\n' -H "Authorization: Bearer <token>" http://se4fs/oidc/userinfo   # 401
```

**Attendu** : **401** immédiat. La révocation du client suffit — `ext:remove` n'a pas à purger les jetons, et c'est précisément pourquoi l'access token de SE5 est **opaque** et non auto-porteur.

### Scénario 18.10 — NFR16 : le reste du serveur n'a pas bougé

Pose du fragment (`ext:install hello`) puis retrait (`ext:remove hello`), en vérifiant **avant et après** :

```bash
curl -s -o /dev/null -w '/ipxe %{http_code}\n'  http://se4fs/ipxe/
curl -s -o /dev/null -w '/doc %{http_code}\n'   http://se4fs/doc/
curl -s -o /dev/null -w '/assets %{http_code}\n' http://se4fs/assets/wallpaper/
curl -s -o /dev/null -w '/legacy %{http_code}\n' http://127.0.0.1:8082/
curl -s -o /dev/null -w '/ext inexistante %{http_code}\n' http://se4fs/ext/nexiste-pas/
```

**Attendu** : codes **identiques** avant/après dans les deux sens. Vérifier aussi que `/etc/apache2/conf-enabled/` ne contient **rien** relatif aux extensions : l'inclusion est locale au vhost :80, jamais globale — une conf globale s'appliquerait aussi au vhost legacy 8082.

### Scénario 18.11 — Unicité globale, ambiguïté, ports

Publier le **même** `hello` depuis un second dépôt de test (`build-test-extension.sh /tmp/ext-hello-bis`, servi sur un autre port, ajouté comme seconde source) :

```bash
php artisan ext:sources:sync
sudo -u www-admin php artisan ext:install hello                      # exit 1 : ambiguïté, message citant --source
sudo -u www-admin php artisan ext:install hello --source=<clé A>     # exit 0
sudo -u www-admin php artisan ext:install hello --source=<clé B>     # exit 1 : déjà installée depuis <clé A>
```

**Attendu** : le troisième appel refuse **en nommant la source déjà installée**, sans toucher à quoi que ce soit du système. Puis, port : installer une seconde extension de test (clé différente) ⇒ elle obtient **8601** ; désinstaller la première ⇒ une troisième installation reprend **8600** (les trous sont comblés).

### Scénario 18.12 — Le catalogue ne réécrit jamais ce qui est installé

Avec `hello` installée en `1.0.0`, publier une `2.0.0` au dépôt de test (modifier `version` dans `index.json`, re-signer avec `/tmp/ext-hello/source.key`), puis :

```bash
php artisan ext:sources:sync
```

**Attendu** : `SELECT version, installed_version FROM extensions WHERE key='hello';` → **`2.0.0`** et **`1.0.0`**. La version publiée bouge, la version installée non — c'est cet écart qui permettra à la Story 56.3 de proposer une mise à jour, et c'est ce qui garantit qu'une simple re-synchro n'efface pas la trace de ce qui tourne réellement. Vérifier au passage que `installed_port`, `installed_at` et `status` sont **inchangés**.

## Section 19 — Installation, mise à jour et retrait depuis l'UI (Story 56.3)

> **Ce que cette section valide.** La 56.2 a livré le canal : `ext:install` / `ext:remove` en ligne de commande. La 56.3 pose le bouton — et *rien d'autre*. Le moteur est le MÊME (doctrine AR1) ; ce qui est neuf, c'est la **tâche de fond**, l'**état de progression persisté** et la **mise à jour**.
>
> Trois propriétés portent toute la valeur de cette story, et ce sont les trois seules à vérifier vraiment :
>
> 1. **la progression est un FAIT en base, pas un état d'écran.** Un rechargement de page, un second onglet, un second admin voient exactement le même run. C'est ce qui distingue un suivi d'un effet visuel ;
> 2. **la mise à jour sait revenir en arrière AVANT de partir.** Le `.deb` de la version installée est vérifié (présent, re-haché) *avant* qu'apt ne soit invoqué. Une mise à jour dont on ne sait pas revenir n'a pas le droit de commencer ;
> 3. **le verrou est global, et l'UI le reflète sans le remplacer.** Deux admins, un double-clic, un rechargement : un seul run. Un worker tué ne condamne pas la bibliothèque.
>
> Ce que la suite automatisée prouve déjà sur l'hôte (séquence privilégiée exacte de l'update, fail-closed du sha256, refus `redirect_paths_changed` et `rollback_package_missing` avant toute action, compensations, atomicité run⇒Job, trois chemins d'erreur du Job, matrice de détection de mise à jour, staleness, modale et gardes Livewire) n'a **pas** à être rejoué ici. Cette section couvre exactement ce qu'une doublure ne peut pas prouver : **la chaîne de file d'attente réelle, un apt réel qui rate à mi-parcours, deux navigateurs simultanés, et un worker qu'on arrête.**
>
> **Dette worktree assumée, iso 54.x/55.x/56.1/56.2** : story développée dans un worktree git non synchronisé vers la VM. À jouer **au merge sur `main`**, après `bash scripts/update.sh` (qui redéploie le helper — modifié — puis migre).

### Pré-requis de la section

La Section 18 doit avoir été jouée : dépôt de test servi, source ajoutée avec sa clé pinnée, extension `hello` visible au catalogue.

```bash
cd /var/www/sambaedu-reload
bash scripts/update.sh          # redéploie le helper (restart-service + --allow-downgrades), puis migre

# Le helper doit avoir été RÉÉCRIT (le `cmp` de ensure_extension_engine détecte le changement)
grep -c 'restart-service' /usr/share/sambaedu/sbin/sambaedu-ext-helper.sh   # ≥ 3
grep -c 'allow-downgrades' /usr/share/sambaedu/sbin/sambaedu-ext-helper.sh  # 1

# Les workers de file doivent tourner : c'est EUX qui exécuteront les installations
systemctl is-active laravel-queue-general laravel-queue-worker laravel-queue-sync
php artisan queue:monitor default
```

Repartir d'un état propre : `php artisan ext:remove hello` si l'extension est encore installée depuis la Section 18.

### Scénario 19.1 — Intégrer une `app` depuis la bibliothèque, progression observée

Dans `/admin/extensions`, la carte « Hello (test) » porte désormais un bouton **« Intégrer »** (elle n'en avait aucun en 56.2).

1. Cliquer sur **« Intégrer »**.

**Attendu — la modale de confirmation** : titre « Intégrer l'extension », **provenance** (nom de la source, hôte réel du dépôt, badge **Tierce**), **bloc d'avertissement** « Source non officielle : `<hôte>` — vous installez sous votre responsabilité » au texte **identique** à celui de la 56.1, et la liste des **scopes demandés** (`profile` pour le dépôt de test). Une seule modale — jamais deux enchaînées.

2. Cliquer **« Annuler »** : rien ne se passe.

```sql
SELECT count(*) FROM extension_install_runs;   -- 0
```

3. Rouvrir, puis **« Intégrer »**.

**Attendu** : toast « Intégration en cours », bandeau d'opération en haut de page avec un spinner, et la carte qui affiche l'étape courante. Les étapes défilent avec les **mêmes libellés** que la CLI (« paquet téléchargé et sha256 vérifié », « client OIDC enregistré », …).

```bash
# Le Job est réellement passé par la file d'attente
sudo -u www-admin php artisan tinker --execute="dump(DB::table('extension_install_runs')->latest('id')->first());"
journalctl -u laravel-queue-general -n 30 --no-pager | grep -i RunExtensionOperationJob
```

**Attendu** : un run `operation=install`, `status` passant `pending → running → success`, `steps` remplies dans l'ordre, `requested_by_login` = votre login (**jamais** `system` — un acte d'UI a un auteur), `started_at` et `finished_at` renseignés.

4. À la fin : toast de succès **une seule fois**, badge « Intégrée », tuile visible au lanceur, `curl -sI http://localhost/ext/hello` en 200.

```sql
SELECT action, actor_login, details FROM extension_audit_logs WHERE extension_key='hello' ORDER BY id DESC LIMIT 3;
```

**Attendu** : une ligne `install` avec **votre login** comme acteur.

### Scénario 19.2 — Le monitoring des workers voit passer le Job (gratuitement)

```
/admin/workers
```

**Attendu** : le Job apparaît dans le suivi générique `queue_task_runs` (hooks `Queue::before/after` d'`AppServiceProvider`) — **sans que la Story 56.3 n'y écrive quoi que ce soit**. Vérifier que la file consommée est bien `default`.

```sql
SELECT job_name, queue, status FROM queue_task_runs ORDER BY id DESC LIMIT 5;
```

### Scénario 19.3 — Deux admins, deux navigateurs : un seul run

Deux sessions simultanées (deux navigateurs, ou un mode privé), toutes deux sur `/admin/extensions`.

1. Admin A lance une **désinstallation** de `hello`.
2. **Sans attendre**, admin B tente une opération sur n'importe quelle extension.

**Attendu** :
- chez B, **tous** les boutons d'opération `app` sont **désactivés** — de toutes les cartes, pas seulement celle de `hello` (le verrou du moteur est global, la page le reflète) ;
- si B force malgré tout (double-clic rapide avant rafraîchissement), il reçoit le toast « **Une opération d'extension est déjà en cours** » et **aucun second run n'est créé** ;
- B voit **la même progression** que A, avec « Demandée par `<login de A>` ».

```sql
SELECT id, operation, status, requested_by_login FROM extension_install_runs ORDER BY id DESC LIMIT 3;
```

**Attendu** : **une seule** ligne active.

3. Recharger la page de A en pleine opération (F5) : la progression **reprend là où elle en est** — l'état vit en base, pas dans le composant.

### Scénario 19.4 — Zéro polling au repos

Console réseau du navigateur ouverte, sur `/admin/extensions`, **aucune opération en cours**.

**Attendu** : **aucune** requête Livewire périodique. Le `wire:poll` n'est rendu que lorsqu'il y a quelque chose à suivre — une page d'administration ouverte toute la journée ne doit pas marteler le serveur.

Relancer une opération : les requêtes reprennent toutes les 3 s, puis **cessent** à la fin du run.

### Scénario 19.5 — Publier une 1.1.0 et mettre à jour depuis l'UI

`hello` doit être installée en `1.0.0`.

```bash
# Republie dans LE MÊME dépôt, avec LA MÊME clé (le pin TOFU de la source reste valide)
bash scripts/dev/build-test-extension.sh --version 1.1.0 /tmp/ext-hello
# (le serveur http.server de la Section 18 continue de servir /tmp/ext-hello/repo)

php artisan ext:sources:sync
```

```sql
SELECT version, installed_version, installed_sha256 FROM extensions WHERE key='hello';
```

**Attendu** : `1.1.0` publiée, `1.0.0` installée, `installed_sha256` = le sha du `.deb` de la 1.0.0 (`sha256sum /tmp/ext-hello/repo/packages/sambaedu-ext-hello_1.0.0_all.deb`).

Dans `/admin/extensions` :

**Attendu** : badge **« Mise à jour disponible »** sur la carte et bouton **« Mettre à jour »**. Sur la fiche : ligne « Version installée : 1.0.0 (catalogue : 1.1.0) ».

Cliquer **« Mettre à jour »** → la modale nomme **les deux versions** et récapitule la provenance et les scopes du **nouveau** manifest.

Confirmer, puis observer :

```bash
journalctl -u sambaedu-ext-hello -n 20 --no-pager    # un RESTART, pas un reload
curl -s http://localhost/ext/hello                    # sert toujours
sudo -u www-admin ls -l /var/www/sambaedu-reload/storage/app/extensions/packages/hello/
```

**Attendu** :
- séquence privilégiée **minimale** : `install-package` puis `restart-service`, **rien d'autre** — pas de `write-env`, pas de `write-fragment`, pas de `reload-apache` (`grep sambaedu-ext-helper /var/log/auth.log | tail`) ;
- `installed_version = 1.1.0`, `installed_sha256` = sha du nouveau `.deb`, **`installed_port` et `installed_at` inchangés** ;
- **DEUX** `.deb` en staging (l'ancien reste : c'est le gage de rollback de la prochaine mise à jour) ;
- audit `update` avec votre login ;
- le client OIDC est le **même** (`SELECT client_id, enabled FROM oidc_clients WHERE extension_key='hello';`) — donc la session SSO déjà ouverte dans l'extension continue de fonctionner ;
- `/etc/sambaedu/extensions/hello.env` : `mtime` **inchangé**.

### Scénario 19.6 — Mise à jour déjà à jour : no-op signalé

Recliquer « Mettre à jour » n'est pas possible (le bouton a disparu). En ligne de commande :

```bash
php artisan ext:update hello ; echo "exit=$?"
```

**Attendu** : « déjà à la version publiée par sa source — aucune action », **exit 0**, **aucune** invocation du helper (`/var/log/auth.log`), **aucune** ligne d'audit.

### Scénario 19.7 — Rollback RÉEL : casser apt à mi-mise-à-jour

Republier une `1.2.0` dont le paquet est **installable mais dont le maintainer script échoue** :

```bash
bash scripts/dev/build-test-extension.sh --version 1.2.0 /tmp/ext-hello

# Saboter le postinst du paquet 1.2.0 puis re-signer l'index
cd /tmp/ext-hello
mkdir -p broken && dpkg-deb -R repo/packages/sambaedu-ext-hello_1.2.0_all.deb broken
printf '#!/bin/sh\nexit 1\n' > broken/DEBIAN/postinst && chmod 0755 broken/DEBIAN/postinst
dpkg-deb --build --root-owner-group broken repo/packages/sambaedu-ext-hello_1.2.0_all.deb
NEW_SHA=$(sha256sum repo/packages/sambaedu-ext-hello_1.2.0_all.deb | cut -d' ' -f1)
python3 - "$NEW_SHA" <<'PY'
import json, sys
p = "/tmp/ext-hello/repo/index.json"
d = json.load(open(p))
d["extensions"][0]["install"]["sha256"] = sys.argv[1]
json.dump(d, open(p, "w"), ensure_ascii=False, indent=2)
PY
php -r '
  $sk = base64_decode(trim(file_get_contents("/tmp/ext-hello/source.key")));
  $i  = file_get_contents("/tmp/ext-hello/repo/index.json");
  file_put_contents("/tmp/ext-hello/repo/index.json.sig", base64_encode(sodium_crypto_sign_detached($i, $sk)));
'
cd /var/www/sambaedu-reload && php artisan ext:sources:sync
```

Lancer la mise à jour **depuis l'UI**.

**Attendu** :
- le run termine **`failed`**, la carte affiche « Mise à jour en échec : échec à l'étape apt_install » ;
- `journalctl -u sambaedu-ext-hello` montre un **redémarrage** après la compensation ;
- `dpkg-query -W -f='${Version}\n' sambaedu-ext-hello` → **1.1.0** : l'ancienne version est **re-servie** ;
- `curl -s http://localhost/ext/hello` répond toujours ;
- en base : `installed_version = 1.1.0`, `installed_sha256` inchangé — **la base dit ce qui tourne** ;
- audit `update_failed` avec la catégorie d'étape, **sans URL** ;
- les boutons redeviennent cliquables, la mise à jour est **rejouable**.

> C'est le scénario le plus important de la section : sans lui, « rollback » n'est qu'une intention écrite dans un docblock.

### Scénario 19.8 — `--allow-downgrades` : republier une version antérieure

```bash
bash scripts/dev/build-test-extension.sh --version 1.0.0 /tmp/ext-hello
php artisan ext:sources:sync
```

**Attendu** : la bibliothèque propose une « Mise à jour disponible » vers `1.0.0` — la règle est un **écart**, pas un ordre (la source est l'autorité de sa fraîcheur, modèle apt). La mise à jour aboutit : sans `--allow-downgrades`, apt aurait refusé — et ce même drapeau est ce qui rend le rollback du 19.7 exécutable.

### Scénario 19.9 — `redirect_paths` modifiés : refus AVANT toute action

Republier une version dont le manifest déclare un `redirect_paths` différent :

```bash
python3 - <<'PY'
import json
p = "/tmp/ext-hello/repo/index.json"
d = json.load(open(p))
d["extensions"][0]["version"] = "1.3.0"
d["extensions"][0]["install"]["redirect_paths"] = ["/ext/hello/nouveau/callback"]
json.dump(d, open(p, "w"), ensure_ascii=False, indent=2)
PY
php -r '
  $sk = base64_decode(trim(file_get_contents("/tmp/ext-hello/source.key")));
  $i  = file_get_contents("/tmp/ext-hello/repo/index.json");
  file_put_contents("/tmp/ext-hello/repo/index.json.sig", base64_encode(sodium_crypto_sign_detached($i, $sk)));
'
cd /var/www/sambaedu-reload && php artisan ext:sources:sync
```

**Attendu** : la mise à jour est **refusée avant toute action** — carte en erreur « URI de redirection modifiées — désinstaller puis réinstaller », **aucune** invocation du helper dans `/var/log/auth.log`, **aucun** téléchargement dans le journal d'accès du dépôt, audit `update_failed`. L'extension tourne toujours dans sa version d'avant.

### Scénario 19.10 — Gage de rollback absent : la mise à jour ne démarre pas

```bash
sudo -u www-admin rm -f /var/www/sambaedu-reload/storage/app/extensions/packages/hello/*.deb
```

Republier une version supérieure, re-synchroniser, puis tenter la mise à jour depuis l'UI.

**Attendu** : refus immédiat, « paquet de la version installée absent ou corrompu — désinstaller puis réinstaller », **zéro** appel au helper, **zéro** téléchargement. Reproduire avec un `.deb` **corrompu** (`truncate -s 10 …/<sha>.deb`) : même refus — le nom du fichier ne fait jamais foi, il est re-haché.

### Scénario 19.11 — Worker arrêté : le run attend, puis cesse de bloquer

```bash
systemctl stop laravel-queue-general laravel-queue-worker laravel-queue-sync
```

Lancer une intégration depuis l'UI.

**Attendu** :
- le run reste **`pending`**, la page affiche « en attente », les boutons sont gelés ;
- **rien** n'est installé (aucune ligne helper dans `auth.log`).

```bash
systemctl start laravel-queue-general laravel-queue-worker laravel-queue-sync
```

**Attendu** : le Job est repris, l'opération se termine normalement, le toast de fin arrive.

**Variante « worker tué en plein travail »** : relancer une opération, puis `systemctl kill -s SIGKILL laravel-queue-general` pendant l'exécution. Le run reste `running`. Au-delà de `job_timeout + 300 s` (30 min + 5 min par défaut), l'UI l'affiche « **Interrompue** », les boutons **redeviennent cliquables**, et le run n'est **ni retraité ni réécrit**. Pour raccourcir l'attente en QA : `EXTENSIONS_INSTALL_JOB_TIMEOUT=60` dans `.env` + `php artisan config:clear`.

> ⚠️ Le verrou du **moteur** expire seul au bout de 600 s : c'est lui, et non la staleness, qui arbitre réellement. La staleness ne fait que libérer l'**interface**.

### Scénario 19.12 — Désinstaller une `app` depuis l'UI

Cliquer **« Désinstaller »** sur `hello`.

**Attendu — la modale** : elle dit que **le paquet, son service, l'exposition `/ext/hello` et le client SSO** seront retirés et que **les données de l'extension seront purgées**. Surtout **PAS** le texte du type `link` (« il n'y a rien à nettoyer »).

Confirmer, puis vérifier comme au 18.8 : unité disparue, paquet purgé, fragment retiré, staging nettoyé, tuile disparue du lanceur, badge « Disponible », audit `remove` avec **votre login**, run `operation=remove` en `success`.

### Scénario 19.13 — Le cycle `link` n'a pas bougé d'un pixel

Sur la tuile **Documentation** (`link`, source officielle) :

**Attendu** : « Intégrer » reste **un clic**, **synchrone et instantané** — aucune modale, aucun run, aucun Job. La modale de désinstallation `link` conserve son texte « il n'y a rien à nettoyer ».

```sql
SELECT count(*) FROM extension_install_runs WHERE extension_id = (SELECT id FROM extensions WHERE key='doc');
```

**Attendu** : `0`. Le canal de fond n'existe que pour le type `app`.

### Scénario 19.14 — Écran périmé : no-op propre, jamais une 500

Deux onglets sur `/admin/extensions`. Désinstaller `hello` dans l'onglet A. Dans l'onglet B (périmé), cliquer « Mettre à jour » puis confirmer.

**Attendu** : toast d'information ou d'erreur métier, **page rechargée et remise en phase**, aucune 500, aucune ligne d'audit parasite. Même contrôle depuis la fiche : si l'extension a été prunée entre-temps, retour à la bibliothèque.

---

## Section 20 — Scopes accordés et API extensions (Story 56.4)

> **Ce que cette section valide.** Les Sections 18/19 ont livré l'installation d'une extension `app` ; la Section 13 a livré le contrat de claims. La 56.4 relie les deux : ce que l'extension DEMANDE devient ce qu'elle REÇOIT, ce qu'elle reçoit devient **révocable**, et elle dispose enfin d'une **API** pour le consommer.
>
> Trois propriétés portent toute la valeur, et ce sont les seules à vérifier vraiment :
>
> 1. **la révocation est IMMÉDIATE sur les jetons déjà émis.** Pas de purge, pas de ré-authentification : le scope effectif est recalculé à chaque usage. C'est ce qu'il faut voir de ses yeux, avec un `curl` et le *même* Bearer avant et après ;
> 2. **le refus ne renseigne pas.** Cinq causes de 401, un seul corps. Un 403 qui ne nomme aucun scope. Les codes fins ne vivent que dans `storage/logs/oidc-*.log` ;
> 3. **rien de la base ni de l'annuaire ne sort** (FR24) : ni `ad_guid`, ni `dn`, ni `users.id`, ni email.
>
> Ce que la suite automatisée prouve déjà sur l'hôte (downscope à l'émission, liste exacte des clés de chaque réponse, 401 indistincts × 5 causes, 403 hors scope, jeton en query ignoré, octroi fail-closed à l'installation, idempotence de la révocation, invariance des grants à l'update, gardes Livewire) n'a **pas** à être rejoué ici. Cette section couvre ce qu'une doublure ne peut pas prouver : **Apache devant l'API, un vrai navigateur pour révoquer, le throttle réel, et la remise à niveau des clients OIDC déjà présents sur la VM.**
>
> **Dette worktree assumée, iso 54.x/55.x/56.1-56.3** : story développée dans un worktree git non synchronisé vers la VM. À jouer **au merge sur `main`**, après `bash scripts/update.sh` (qui migre — la colonne `granted_scopes` est ajoutée à ce moment-là).

### Pré-requis — ⚠️ REMISE À NIVEAU DES CLIENTS OIDC EXISTANTS

La migration ajoute `oidc_clients.granted_scopes` avec le défaut `[]`, **volontairement fail-closed** : les clients créés par les runbooks des Sections 11 à 19 (app-témoin, clients de test déclarés à la main, extensions installées avant la migration) se retrouvent **sans aucun scope accordé**. Symptôme attendu si l'on saute cette étape : le SSO fonctionne toujours, mais l'app-témoin n'affiche plus ni nom, ni rôle, ni groupes — et `/oidc/userinfo` ne rend que `{"sub": "..."}`.

Ce n'est **pas** une régression : c'est le fail-closed qui fait son travail. Un consentement que personne n'a donné ne s'hérite pas.

```bash
cd /var/www/sambaedu-reload
bash scripts/update.sh

# 1. Constater l'état après migration (tous les clients à `[]`)
sudo -u postgres psql -d sambaedu -c \
  "SELECT client_id, extension_key, enabled, granted_scopes FROM oidc_clients ORDER BY id;"

# 2. Ré-octroyer au témoin (rejoue l'enregistrement ET le fichier de credentials)
php artisan oidc:witness:disable
php artisan oidc:witness:enable          # affiche désormais « scopes : profile groups »

# 3. Ré-enregistrer les clients de test des Sections 11-13 au besoin
php artisan oidc:client:register "Client QA" --redirect-uri=https://qa.example.test/cb
#   → sans --scope : profile ET groups accordés (défaut opérateur)
#   → --scope=profile : restreint à profile
#   → --scope=directory ou --scope=openid : REFUSÉ, exit 1, aucun client créé

# 4. Une extension `app` déjà installée AVANT la migration n'a pas de grants :
#    la seule façon de les lui rendre est de la réinstaller (le consentement est
#    un acte d'installation). `ext:update` n'y touche pas — c'est voulu.
php artisan ext:remove hello && php artisan ext:install hello --source=<clé-source>
```

**Attendu** : après ces gestes, `granted_scopes` vaut `["groups","profile"]` pour le témoin, et `["profile","groups"]` (dans l'ordre du manifest, normalisé) pour `hello` si son manifest les demande.

### Scénario 20.1 — L'octroi à l'installation, observé

Servir depuis le dépôt de test une extension `hello` dont le `manifest.json` déclare `"scopes": ["profile", "groups"]` (`scripts/qa/build-test-extension.sh`, puis re-signer l'index). Synchroniser la source, puis installer depuis l'UI.

```bash
php artisan ext:sources:sync
php artisan ext:install hello --source=<clé-source>

sudo -u postgres psql -d sambaedu -c \
  "SELECT extension_key, granted_scopes FROM oidc_clients WHERE extension_key='hello';"
```

**Attendu** : `["groups", "profile"]` — trié, dédupliqué, exactement les scopes du manifest. Ni plus (pas d'`openid`, qui n'est jamais listé), ni moins.

### Scénario 20.2 — Un scope non supporté fait ÉCHOUER l'installation

Republier `hello` avec `"scopes": ["profile", "calendar"]` dans son manifest (index re-signé), re-synchroniser, puis tenter l'installation.

```bash
php artisan ext:install hello --source=<clé-source>; echo "exit=$?"

# Aucun composant système ne doit avoir été touché :
grep -c sambaedu-ext-helper /var/log/auth.log      # inchangé par rapport à avant la tentative
sudo -u postgres psql -d sambaedu -c "SELECT count(*) FROM oidc_clients WHERE extension_key='hello';"
```

**Attendu** : exit ≠ 0, message « scope demandé non supporté par SE5 — mettre à jour l'extension ou le serveur », **aucun appel au helper**, **aucun client créé**, et une ligne `install_failed` en base portant cette catégorie. Refuser vaut mieux qu'accorder à moitié : une extension installée avec `profile` seul échouerait à l'usage sans que rien ne l'explique.

### Scénario 20.3 — La fiche : demandées vs réellement accordées

Ouvrir `/admin/extensions/<id>` de `hello` (installée avec ses deux scopes) dans un vrai navigateur.

**Attendu** : la carte « Autorisations » montre **deux blocs distincts** — « Demandées par le manifest » (badges neutres) et « Réellement accordées » (badges verts, chacun avec sa croix de révocation). Sur une extension de type `link` (Documentation), le second bloc est **absent** : elle n'a aucun client OIDC.

### Scénario 20.4 — LE scénario : révoquer, et le constater sur un jeton VIVANT

C'est le cœur de la story. Il faut un access token réel, obtenu par un vrai flux SSO.

```bash
# 1. Se connecter à l'app-témoin dans un navigateur (tuile « Démo SSO »), puis
#    récupérer l'access token le plus récent de son client :
sudo -u postgres psql -d sambaedu -c \
  "SELECT id, scope, expires_at FROM oidc_access_tokens ORDER BY id DESC LIMIT 1;"
```

Le jeton CLAIR n'est jamais stocké (seul son sha256 l'est) : pour disposer du clair, dérouler le flux à la main (Section 11 / Scénario 11.6) ou instrumenter le témoin. Poser ensuite :

```bash
TOKEN='<access_token clair>'

# 2. AVANT — contrôle POSITIF, indispensable : sans lui, l'étape 4 ne prouverait rien
curl -sk -H "Authorization: Bearer $TOKEN" https://<serveur>/oidc/userinfo | jq
curl -sk -H "Authorization: Bearer $TOKEN" https://<serveur>/api/ext/v1/me | jq
curl -sk -H "Authorization: Bearer $TOKEN" https://<serveur>/api/ext/v1/me/groups | jq
```

**Attendu (avant)** : `/oidc/userinfo` rend `sub`, `name`, `role`, `groups` ; `me` rend `{success, message, sub, name, role}` ; `me/groups` rend `{success, message, sub, groups}` — clés métier **à la racine**, aucun wrapper `data`, en-tête `Cache-Control: no-store`.

```
3. Depuis le NAVIGATEUR, sur la fiche de l'extension : cliquer la croix de « groups »,
   lire la modale (« y compris pour ses jetons en cours », « irréversible »), confirmer.
```

**Attendu (modale + toast)** : toast vert « Autorisation « groups » révoquée. », le badge disparaît du bloc « accordées » et **reste** dans « demandées ».

```bash
# 4. APRÈS — le MÊME jeton, sans reconnexion, sans attendre
curl -sk -H "Authorization: Bearer $TOKEN" https://<serveur>/oidc/userinfo | jq
curl -sk -o /dev/null -w '%{http_code}\n' -H "Authorization: Bearer $TOKEN" \
  https://<serveur>/api/ext/v1/me/groups
curl -sk -o /dev/null -w '%{http_code}\n' -H "Authorization: Bearer $TOKEN" \
  https://<serveur>/api/ext/v1/me
```

**Attendu (après)** : `/oidc/userinfo` ne rend plus `groups` (mais toujours `sub`, `name`, `role`) ; `me/groups` → **403** ; `me` → **200**. On a révoqué une DONNÉE, pas l'accès.

```bash
# 5. La trace d'audit (FR36) et le journal
sudo -u postgres psql -d sambaedu -c \
  "SELECT action, details, actor_login, created_at FROM extension_audit_logs
   WHERE action='scope_revoke' ORDER BY id DESC LIMIT 3;"
grep -h 'oidc.client.scope_revoked\|oidc.ext_api.rejected' storage/logs/oidc-*.log | tail -5
```

**Attendu** : une ligne `scope_revoke` dont `details` vaut `groups` et `actor_login` l'admin connecté (jamais `system` pour un acte d'UI) ; au journal, `oidc.client.scope_revoked` puis `oidc.ext_api.rejected` avec le code `oidc.access_token_scope_insufficient`. **Aucun secret, aucun jeton, aucun nom d'utilisateur** dans ces lignes.

### Scénario 20.5 — Un nouveau flux est RÉDUIT, pas refusé

Après la révocation du 20.4, se reconnecter à l'app-témoin.

**Attendu** : la connexion **aboutit** (le SSO n'est pas cassé — c'est tout l'intérêt de réduire plutôt que de refuser), la page témoin affiche nom et rôle mais plus de groupes, et la réponse du token endpoint annonce la réduction :

```bash
grep -h 'oidc.token.issued' storage/logs/oidc-*.log | tail -1
sudo -u postgres psql -d sambaedu -c "SELECT scope FROM oidc_access_tokens ORDER BY id DESC LIMIT 1;"
```

**Attendu** : le jeton nouvellement émis porte `openid profile` — pas `openid profile groups`.

⚠️ **Résidu assumé, à ne pas prendre pour un bug** : l'**id_token** déjà délivré à un navigateur porte ses claims jusqu'à son `exp` (300 s) — un JWT est auto-porteur, rien ne peut le rappeler. Seuls les access tokens (opaques) sont réduits instantanément. Une page témoin ouverte peut donc encore afficher les groupes pendant moins de cinq minutes ; un rechargement après expiration ne les montre plus.

### Scénario 20.6 — `openid` n'est pas révocable

**Attendu** : aucune croix n'est proposée pour `openid` — il n'apparaît pas dans « accordées », puisqu'il n'est jamais accordé. Un appel forgé (console navigateur) ne le retire pas davantage : toast d'information, aucune ligne d'audit, `granted_scopes` inchangé. Retirer l'identité, c'est désinstaller l'extension (FR10), pas révoquer un scope.

### Scénario 20.7 — Les familles de refus, à travers Apache

```bash
API=https://<serveur>/api/ext/v1/me

curl -sk -i $API | head -5                                        # aucun jeton
curl -sk -i -H "Authorization: Bearer inconnu" $API | head -5      # jeton inconnu
curl -sk -i -H "Authorization: Bearer $TOKEN_EXPIRE" $API | head -5 # jeton périmé (> 600 s)
curl -sk -i "$API?access_token=$TOKEN" | head -5                   # jeton en query
```

**Attendu** : `401` dans les quatre cas, avec le **même corps** `{"success":false,"message":"Jeton d'accès absent, invalide ou expiré.","error":"invalid_token"}` et l'en-tête `WWW-Authenticate: Bearer realm="ext-api"` (avec `error="invalid_token"` dès qu'un jeton a été présenté). Le jeton passé en query est traité comme **absent** : il ne doit jamais atterrir dans les logs d'Apache comme un identifiant valide.

Les causes ne se distinguent QUE dans `storage/logs/oidc-*.log` :

```bash
grep -h 'oidc.ext_api.rejected' storage/logs/oidc-*.log | tail -6
```

**Attendu** : des codes `oidc.access_token_missing`, `oidc.access_token_invalid`, `oidc.access_token_expired`, avec un `token_hash_prefix` de 8 caractères pour corréler — jamais le jeton complet.

### Scénario 20.8 — Client révoqué : l'accès entier tombe

```bash
php artisan oidc:client:revoke <client_id>
curl -sk -o /dev/null -w '%{http_code}\n' -H "Authorization: Bearer $TOKEN" https://<serveur>/api/ext/v1/me
```

**Attendu** : `401` (et non 403) — un client révoqué n'a plus d'accès du tout, ses jetons meurent avec lui. Même corps que les autres 401. C'est aussi ce qui se passe après `ext:remove` : la désinstallation emporte le client, donc les grants et les jetons.

### Scénario 20.9 — Throttle réel

```bash
for i in $(seq 1 70); do
  curl -sk -o /dev/null -w '%{http_code} ' -H "Authorization: Bearer $TOKEN" \
    https://<serveur>/api/ext/v1/me
done; echo
```

**Attendu** : les premières requêtes en `200`, puis des `429` au-delà de 60 par minute. Le canal est ouvert à du code tiers : il est borné.

### Scénario 20.10 — FR24 : ce que l'API ne dira jamais

```bash
curl -sk -H "Authorization: Bearer $TOKEN" https://<serveur>/api/ext/v1/me > /tmp/me.json
curl -sk -H "Authorization: Bearer $TOKEN" https://<serveur>/api/ext/v1/me/groups > /tmp/groups.json
grep -Ei 'ad_guid|CN=|dc=|memberOf|@|"id"' /tmp/me.json /tmp/groups.json
```

**Attendu** : aucune correspondance. Et le contrôle POSITIF adossé, sans lequel deux fichiers vides passeraient aussi : `jq -r .name /tmp/me.json` rend le nom affiché, `jq -r '.groups | length' /tmp/groups.json` rend le nombre de classes. Le contrat v1 tient en cinq clés côté `me` et quatre côté `me/groups` — **rien d'autre ne doit y figurer**, jamais (NFR11 : ce qui entre dans v1 n'en sort plus).

### Scénario 20.11 — Le contrat v1 est versionné

```bash
curl -sk -o /dev/null -w '%{http_code}\n' -H "Authorization: Bearer $TOKEN" https://<serveur>/api/ext/v2/me
curl -sk -o /dev/null -w '%{http_code}\n' -X POST -H "Authorization: Bearer $TOKEN" https://<serveur>/api/ext/v1/me
```

**Attendu** : `404` pour `/v2` (il n'existe pas — il n'existera que le jour d'une rupture, et le v1 continuera alors d'être servi à côté) et `405` pour un POST sur `me` (le v1 est en lecture seule).

---

## Section 21 — Santé des extensions, tuile dégradée et journal d'audit (Story 56.5)

> **Ce que cette section valide.** Les Sections 17 à 20 ont livré les sources signées, le moteur d'installation, l'UI d'opération et les scopes. La 56.5 est la **dernière de l'Epic 56** : elle rend l'état des extensions OBSERVABLE, et elle prouve que la panne d'une extension n'emporte rien d'autre qu'elle-même.
>
> Trois propriétés portent toute la valeur :
>
> 1. **la sonde MESURE, la navbar LIT** (NFR9). L'état vit dans quatre colonnes de `extensions`, écrites par `ext:health:check` toutes les 5 minutes et par ce seul chemin. Aucune page de SE5 n'émet la moindre requête vers une extension pour se rendre — c'est vérifiable en coupant tous les backends et en constatant que rien ne ralentit ;
> 2. **la tuile SIGNALE, elle ne bloque jamais** (FR35 sous contrainte FR14). Une extension arrêtée porte un badge « Indisponible » et **reste cliquable** : l'état peut dater de 5 minutes, et bloquer transformerait un affichage en autorisation ;
> 3. **le journal d'audit FR36 est enfin LISIBLE** — et il ne montrera jamais une URL de dépôt, un secret ni une empreinte de paquet.
>
> Ce que la suite automatisée prouve déjà sur l'hôte (transitions de la sonde, incident écrit à la transition seulement et conservé au retour du backend, 4xx/5xx = joignable, catégorie sans URL ni message Guzzle, zéro ligne d'audit pour la santé, badge absent si l'état est périmé ou inconnu, 1 requête SQL / 0 HTTP au rendu du lanceur badgé, survie sans les colonnes `health_*`, verdicts des trois checks doctor, absence de side effect du check, carte « Santé » et `probeNow()`, pagination et filtres du journal, action inconnue rendue telle quelle, aucune URL en base rendue à l'écran, bandeau et acquittement du marqueur) n'a **pas** à être rejoué ici. Cette section couvre ce qu'une doublure ne peut pas prouver : **un vrai `systemctl stop`, un vrai cron Laravel, un vrai Apache en 503, et un parcours d'admin dans un navigateur.**
>
> **Dette worktree assumée, iso 54.x/55.x/56.1-56.4** : story développée dans un worktree git non synchronisé vers la VM. À jouer **au merge sur `main`**, après `bash scripts/update.sh` (qui joue la migration ajoutant les colonnes `health_*`).

### Pré-requis de la section

Les Sections 18 et 19 doivent avoir été jouées : il faut **au moins une extension `app` réellement installée** (`sambaedu-ext-hello` dans les runbooks précédents), avec son unité systemd, son fragment Apache et sa tuile visible pour un prof réel.

```bash
cd /var/www/sambaedu-reload
bash scripts/update.sh
php artisan tinker --execute="dump(\App\Models\Extension::where('type','app')->where('status','integrated')->pluck('installed_port','key')->all());"
```

**Attendu** : au moins une clé avec un port dans `8600-8699`. Sans cela, tous les scénarios de sonde rendent « Aucune extension `app` installée » — ce qui est un résultat correct, mais ne prouve rien.

### Scénario 21.1 — ⭐ LE scénario : une extension tombe, SE5 ne bouge pas (NFR6)

C'est le scénario le plus important de la section, et le pendant direct du **10.1** (« SE5 debout sans la table `extensions` »). L'incident fondateur de l'Epic 54 était un lanceur capable de mettre **toutes** les pages du produit en 500 ; on vérifie ici la même promesse face à une panne réelle, pas à une table absente.

```bash
systemctl stop sambaedu-ext-hello
systemctl is-active sambaedu-ext-hello        # inactive
```

1. **Le core répond, entièrement.** Dans un navigateur, en admin, parcourir au moins : `/app/dashboard`, `/app/users`, `/admin/extensions`, `/admin/settings/system-status`, et une page **legacy embarquée** (elle rend aussi le lanceur, via `layouts::legacy-embed`). Aucune 500, aucun ralentissement perceptible.
2. **La cible, elle, est franchement en erreur.** `curl -sk -o /dev/null -w '%{http_code} %{time_total}\n' https://<serveur>/ext/hello` doit rendre **503 en quelques millisecondes** — le `retry=0` du fragment Apache empêche toute attente. Un 503 lent serait un défaut de fragment, pas de cette story.
3. **Aucune autre extension n'est affectée** : si une seconde `app` est installée, sa tuile et sa cible fonctionnent normalement (NFR4/NFR6 — les extensions ne se couplent pas entre elles).

```bash
systemctl start sambaedu-ext-hello
```

### Scénario 21.2 — La sonde marque l'indisponibilité, et l'admin le voit

```bash
systemctl stop sambaedu-ext-hello
php artisan ext:health:check
```

**Attendu** : `1 extension(s) sondée(s) — 1 injoignable(s).`, puis `Injoignable(s) : hello` et la ligne de diagnostic `systemctl status sambaedu-ext-<clé>`. **Code retour 0** (`echo $?`) : la commande CONSTATE, elle ne porte pas de verdict — c'est le doctor qui le fait.

```bash
php artisan tinker --execute="\$e=\App\Models\Extension::where('key','hello')->first(); dump(\$e->health_status, (string)\$e->health_checked_at, (string)\$e->health_last_incident_at, \$e->health_last_incident_detail);"
```

**Attendu** : `unreachable`, un horodatage à l'instant, un incident daté, et une catégorie **courte** du type `backend injoignable (connexion refusée ou expirée)`. ⚠️ Vérifier de ses yeux qu'il n'y a **ni URL, ni port, ni message cURL** dans `health_last_incident_detail` : cette colonne s'affiche sur la fiche, lisible par tout admin.

Dans le navigateur :

- **fiche `/admin/extensions/<id>`** : la carte « Santé » porte le badge **Indisponible**, la date de mesure, la version installée, et le dernier incident ;
- **bibliothèque `/admin/extensions`** : la carte de l'extension porte le badge discret **Indisponible** ;
- **lanceur (gaufre de la navbar)** : la tuile porte le point d'alerte, avec l'infobulle « Indisponible actuellement ».

### Scénario 21.3 — ⭐ Le badge n'est pas une garde (FR14), et le prof le voit aussi

Deux vérifications à faire dans le **même** état (backend arrêté), avec un **compte prof réel** (pas un admin) :

1. la tuile de l'extension est présente et **badgée**, et elle **s'ouvre** au clic (elle mène à `/ext/hello`, qui rend 503 — c'est la cible qui est en panne, pas le lien qui est retiré) ;
2. la tuile n'affiche **aucun détail technique** : pas de catégorie d'incident, pas de port, pas d'horodatage. Un élève ou un professeur n'a pas à lire un diagnostic système.

⚠️ Le second point se contrôle par « affichage du code source de la page » sur la vue du prof : chercher `connexion refusée`, `8600`, `127.0.0.1` — **aucune** occurrence.

```bash
systemctl start sambaedu-ext-hello
php artisan ext:health:check hello
```

**Attendu** : `hello : joignable.` La fiche repasse au badge **Joignable** — et **le dernier incident reste affiché** : c'est sa raison d'être (« ça a été indisponible, voici quand »). Le badge de la tuile disparaît.

### Scénario 21.4 — La sonde tourne toute seule (scheduler réel)

```bash
php artisan schedule:list | grep ext:health:check
```

**Attendu** : `*/5 * * * *  php artisan ext:health:check`.

Puis, **sans jamais lancer la commande à la main** :

```bash
systemctl stop sambaedu-ext-hello
date; sleep 330; php artisan tinker --execute="\$e=\App\Models\Extension::where('key','hello')->first(); dump(\$e->health_status, (string)\$e->health_checked_at);"
```

**Attendu** : `unreachable`, horodaté dans les 5 dernières minutes — donc écrit par le cron Laravel de la VM, pas par vous. Si l'état ne bouge pas, le cron `schedule:run` n'est pas installé : c'est un défaut d'exploitation de la VM, et le doctor le dira au scénario suivant.

### Scénario 21.5 — Le doctor : trois checks, et un `warn` de scheduler mort

```bash
php artisan sambaedu:doctor --tag=extensions
php artisan sambaedu:doctor --tag=extensions --json | jq .
```

Trois lignes attendues : **Extensions (backends)**, **Extensions (journal d'audit)**, **Extensions (clients OIDC)**.

| État de la VM | Verdict attendu sur « backends » |
|---|---|
| tout tourne, cron actif | `ok` — « N extension(s) app installée(s), toutes joignables », plus l'écart de version s'il y en a un |
| `systemctl stop sambaedu-ext-hello` | `error` — nomme `hello`, et le `fix` donne `systemctl status sambaedu-ext-hello` |
| tout tourne mais cron arrêté depuis > 15 min | `warn` — « l'état persisté n'est pas exploitable », `fix` = vérifier le scheduler |
| extension installée à l'instant, jamais encore mesurée | **le même `warn`** — le libellé dit « jamais mesuré, **ou** mesuré il y a plus de 900 s », parce qu'affirmer « le scheduler est muet » serait faux ici. Se résout seul au passage suivant, ou tout de suite avec `php artisan ext:health:check` |
| aucune `app` installée | `ok` — « aucune extension app installée » |

Le `warn` de péremption se provoque proprement :

```bash
# Neutraliser temporairement le cron Laravel (selon l'installation : crontab -e, ou le timer systemd)
crontab -l | grep schedule:run
# … le commenter, attendre > 15 minutes, puis :
php artisan sambaedu:doctor --tag=extensions
```

**Attendu** : `warn` sur « backends » **alors que les services tournent**. C'est exactement l'information utile : ce n'est pas l'extension qui va mal, c'est la mesure. ⚠️ **Rétablir le cron** ensuite.

Deux contrôles complémentaires :

1. **le doctor n'écrit RIEN.** Noter `health_checked_at` avant, lancer le doctor trois fois, revérifier : la valeur **n'a pas bougé** (règle d'or : un check est read-only ; la persistance appartient à `ext:health:check` et au bouton de la fiche).
2. **le `fix` ne redémarre rien.** Aucun message du doctor ne propose `systemctl restart` : SE5 ne relance jamais un backend tout seul.

Même vérification dans l'UI : `/admin/settings/system-status`, section **Extensions**. Les checks s'exécutent **après** le premier rendu (`wire:init`) : la page doit s'afficher instantanément, backends arrêtés compris.

### Scénario 21.6 — « Sonder maintenant » : le seul chemin de mesure à la demande

Sur la fiche d'une `app` installée, avec le backend **arrêté** :

1. cliquer **Sonder maintenant** ⇒ toast rouge « Le backend ne répond pas : … », badge **Indisponible**, date de mesure = maintenant ;
2. `systemctl start sambaedu-ext-hello`, cliquer de nouveau ⇒ toast vert « Le backend répond. », badge **Joignable**, incident **conservé**.

Contrôle négatif : une extension de type **`link`** (la tuile Documentation) et une `app` **non installée** n'affichent **aucune** carte « Santé » — il n'y a rien à sonder, et une carte vide serait un artefact.

### Scénario 21.7 — Le journal d'audit, en vrai

`/admin/extensions` → bouton **Journal** (ou `/admin/extensions/journal`).

**Attendu** : le journal reflète **tout ce que les Sections 17 à 20 ont réellement joué** — sources ajoutées/activées/retirées, catalogues refusés, intégrations, installations et leurs échecs, mises à jour, retraits, révocations de scope. Vérifier :

1. **tri du plus récent au plus ancien**, pagination par 25 ;
2. **filtre par action** et **filtre par extension**, combinables ; le compteur d'entrées suit ;
3. les lignes de **source** (acteur `system` pour la synchro planifiée) et les lignes d'**extension** coexistent proprement ;
4. **lien depuis la fiche** : « Journal de cette extension » ⇒ le filtre extension est déjà positionné (`?ext=<clé>`) ;
5. ⚠️ **aucune URL de dépôt, aucun jeton, aucune empreinte de paquet.** Contrôle par affichage du code source de la page, en cherchant l'hôte de votre dépôt tiers et `private_token`. **Aucune** occurrence — y compris sur les lignes `source_sync_failed`, dont la cible est justement ce dépôt.

**Rétention** : aucune purge automatique — décision assumée, documentée dans `ExtensionAuditJournalService`. Le volume est structurellement borné (actes humains + échecs par tentative + transitions dédupliquées ; la santé, elle, n'écrit rien). Rien à vérifier ici, sinon qu'aucune tâche planifiée ne touche à cette table (`php artisan schedule:list | grep -i audit` ⇒ aucune ligne).

### Scénario 21.8 — Le signal « journal d'audit incomplet » (legs review 56.3 #4)

Ce chemin nécessite une base en échec : il est **prouvé sur l'hôte** (table d'audit supprimée + refus d'installation ⇒ marqueur posé, refus toujours rapporté ; et la contre-épreuve : refus normal ⇒ ligne écrite, aucun marqueur). Sur la VM, on valide seulement la **surface d'exploitation**, en posant le marqueur à la main :

```bash
php artisan tinker --execute="\App\Models\ExtensionAuditLog::recordWriteFailure();"
php artisan sambaedu:doctor --tag=extensions
```

**Attendu** : « Extensions (journal d'audit) » passe en **error** — « le journal d'audit peut être INCOMPLET : 1 écriture(s) perdue(s) depuis le … », avec un `fix` qui nomme les logs Laravel **et** la page journal.

Dans l'UI : `/admin/extensions/journal` affiche un **bandeau rouge** avec le compteur et les deux dates. Cliquer **Acquitter** ⇒ modale de confirmation ⇒ le bandeau disparaît, toast de confirmation.

```bash
php artisan sambaedu:doctor --tag=extensions
php artisan tinker --execute="dump(\App\Models\ExtensionAuditLog::where('action','like','%')->count());"
```

**Attendu** : le check est revenu à `ok`, et le **nombre de lignes du journal n'a pas changé** — l'acquittement n'écrit **aucune** ligne d'audit (c'est un signal d'exploitation, pas une donnée de conformité ; l'auditer créerait une boucle).

⚠️ Le marqueur vit dans le cache **fichier** (`storage/framework/cache`), délibérément : si une écriture d'audit échoue, la cause plausible est la base — un signal stocké en base coulerait avec elle. Corollaire d'exploitation : un `php artisan cache:clear` efface le marqueur sans acquittement. Acceptable (le doctor et les logs restent), mais à savoir.

### Scénario 21.9 — Clients OIDC fantômes (legs review 56.4 #4)

Le check « Extensions (clients OIDC) » détecte un état anormal que l'UI des scopes ne peut pas montrer : **plusieurs clients OIDC actifs pour la même extension**, dont un porterait des scopes que la fiche n'affiche pas (la fiche montre le plus récent, la révocation agit sur tous).

```bash
php artisan sambaedu:doctor --tag=extensions | grep -i 'clients OIDC'
```

**Attendu en régime normal** : `ok` — « un seul client OIDC actif par extension (aucun fantôme) ». ⚠️ **Y compris avec l'app-témoin `sso-demo` activée** : c'est une extension `link` avec un client légitime, et la signaler serait un faux positif permanent.

Provocation contrôlée (VM de test uniquement), pour voir le verdict `error` :

```bash
php artisan tinker
>>> $c = \App\Models\OidcClient::where('extension_key','hello')->where('enabled',true)->first();
>>> $ghost = $c->replicate(); $ghost->client_id = $c->client_id.'-ghost'; $ghost->granted_scopes = ['profile','groups']; $ghost->save();
```

**Attendu** : `error` nommant `hello` et le scope **invisible**, avec un `fix` qui renvoie vers `ext:remove` puis `ext:install` (le retrait révoque **tous** les clients). ⚠️ Le détail ne doit **jamais** contenir un `client_id` (NFR3). **Supprimer le fantôme** ensuite : `\App\Models\OidcClient::where('client_id','like','%-ghost')->delete();`

### Scénario 21.10 — Fenêtre `update.sh` : observation au déploiement

Rien à provoquer — c'est une **observation** à faire au moment du merge, pendant que `scripts/update.sh` enchaîne composer, npm et le build VitePress **avant** `migrate --force` :

- pendant toute cette fenêtre (plusieurs minutes), les pages de SE5 continuent de répondre et **le lanceur affiche ses tuiles**, alors que les colonnes `health_*` n'existent pas encore ;
- aucun badge « Indisponible » n'apparaît pendant la fenêtre (l'état est lu comme inconnu, et on ne signale que ce qu'on sait).

C'est la raison pour laquelle `tilesFor()` fait un `SELECT *` et ne nomme **aucune** colonne de santé : un `->select([...])` échouerait en SQL pendant toute la fenêtre. Le `try/catch` du `mount()` reste le filet — pas le plan.

### Scénario 21.11 — Aucune surface privilégiée nouvelle

Contrôle de non-régression, à faire une fois :

```bash
sudo -l -U www-admin | grep -i sambaedu-ext
grep -c '' /etc/sudoers.d/sambaedu-ext
/usr/share/sambaedu/sbin/sambaedu-ext-helper.sh 2>&1 | head -5
```

**Attendu** : la ligne sudoers est **exactement** celle de la Section 18, et le helper expose **exactement** les mêmes sous-commandes qu'en 56.2 — `write-env`, `remove-env`, `install-package`, `remove-package`, `enable-service`, `disable-service`, `restart-service`, `write-fragment`, `remove-fragment`, `reload-apache`. **Aucune** sous-commande de lecture d'état n'a été ajoutée : la sonde de santé est un `GET http://127.0.0.1:<port>/` émis par `www-admin`, sans le moindre privilège. C'est la décision n° 1 de la story, et elle se vérifie ici en une commande.

---

## Post-correctifs & non-régressions

- **Section 19.7 / 19.10 — un rollback n'est utile que s'il est GARANTI D'AVANCE** : le `.deb` de la version installée est exigé présent ET re-haché **avant** qu'apt ne soit invoqué (`installed_sha256` le désigne dans un staging content-addressed). Absent ou corrompu ⇒ refus, sans avoir rien entrepris. La formulation compte : ce n'est pas « on essaiera de revenir en arrière », c'est « on ne part pas sans savoir revenir ». Corollaire d'exploitation : le helper pose `--allow-downgrades`, sans quoi apt refuserait précisément la commande qui constitue la compensation. Toute story future qui remplacerait un artefact déployé (agent, paquet, image) doit vérifier sa réversibilité **avant** l'acte, pas après.
- **Section 19.5 — la mise à jour ne touche QUE ce qui change d'une version à l'autre** : le port, le fragment Apache, le fichier d'environnement et le client OIDC sont des invariants de la **clé**, pas de la version. Les régénérer serait du churn à risque — le `client_secret` OIDC n'est pas récupérable (seul son sha256 est en base), donc re-enregistrer le client imposerait de réécrire l'env ET de redémarrer, avec une compensation impossible à garantir si l'une des deux échoue. L'unique cas où un invariant devrait bouger — des `redirect_paths` différents — est refusé fail-closed (19.9), chemin de secours documenté dans le message lui-même.
- **Section 19.1 / 19.3 — une progression qui vit dans le composant n'est pas une progression** : rechargement de page, second onglet, second admin, consultation après coup — quatre exigences qu'aucun état d'écran ni cache ne couvre. D'où la table `extension_install_runs`. Elle n'est pas un journal d'audit pour autant : `extension_audit_logs` répond à « qui a décidé quoi » (append-only, conservé), les runs à « où en est-on » (mutable, lu quelques minutes). Confondre les deux ferait soit un journal pollué par des états transitoires, soit un suivi qu'on n'ose plus purger.
- **Section 19.1 — le moteur reste MUET sur les runs** : le seul pont est un callback `?callable $onStep` additif. C'est ce qui garde `ext:install`/`ext:update`/`ext:remove` fonctionnels sans aucune ligne de run, et le moteur testable sans base de runs. ⚠️ Ce callback écrit en base : son échec est **isolé et journalisé**, jamais propagé — sinon une panne du canal d'AFFICHAGE déclencherait les compensations d'une installation pourtant réussie, et désinstallerait ce qui vient d'être posé.
- **Section 19.3 / 19.11 — trois couches de concurrence, chacune à sa place** : (1) l'UI gèle les boutons — confort, ne garantit rien ; (2) l'orchestrateur re-vérifie sous verrou fichier COURT avant de créer le run — intégrité des runs ; (3) le verrou global du moteur — **la vérité**. L'UI REFLÈTE ce verrou (désactivation de TOUTES les cartes, puisqu'il est global), elle ne le remplace ni ne le contourne. Et « il y a un run actif » n'a qu'UNE définition, partagée par la garde de l'orchestrateur et par l'affichage : deux définitions finiraient par se contredire, et l'écran dirait alors le contraire de ce que le serveur applique (leçon review 56.1 #1).
- **Section 19.11 — un worker mort ne doit pas condamner la bibliothèque** : passé `job_timeout + marge`, un run resté actif est affiché « Interrompue » et cesse de bloquer l'UI. On ne le « répare » pas et on ne le retraite pas — pas de janitor, ce serait de la sur-conception pour un cas rare. La staleness libère l'**interface** ; l'arbitre réel reste le verrou du moteur, qui expire seul à 600 s. La comparaison se fait **côté PHP** : les sessions PostgreSQL du projet sont en UTC alors que l'application vit à Paris, et un `now()` SQL décalerait le verdict de deux heures.
- **Section 19.5 / 19.8 — la détection de mise à jour est un ÉCART, jamais un ORDRE** : `version` est une chaîne LIBRE du manifest (le validateur ne lui impose aucun format) — inventer un tri sémantique mentirait sur un « 2024-annexe-b ». Une republication antérieure est donc proposée comme un changement : c'est voulu, la source est l'autorité de sa propre fraîcheur (modèle apt). La règle vit dans `toListRow()`, dont `toDetail()` hérite par construction : la liste et la fiche ne PEUVENT pas diverger.
- **Section 19.1 — l'acteur d'un acte d'UI n'est jamais `system`** : le Job recharge l'admin **par identifiant** (jamais un modèle sérialisé dans le payload : un admin supprimé entre le clic et le pickup ferait exploser le `unserialize`, hors de tout filet applicatif). S'il a disparu, on retombe sur `system` — ce qui reste vrai, faute d'humain à qui attribuer l'acte.
- **Section 19.4 — un `wire:poll` inconditionnel est une fuite silencieuse** : une page d'administration reste ouverte des heures. Le poll n'est **rendu** que lorsqu'il y a quelque chose à suivre, et l'intervalle est de 3 s (une installation dure des minutes ; 1 s martèlerait le serveur pour rien). Patron repris de `iso-windows`.
- **Section 19.11 — `WithoutOverlapping` est INTERDIT sur ce Job** : le middleware s'appuie sur le cache PAR DÉFAUT (APCu ici), qui n'implémente pas `lock()` — il lève « undefined method ApcStore::lock() » **au pickup**, et n'expose aucune API pour lui passer un store lock-capable. Il a été retiré de `DownloadWindowsIsoJob` pour cette raison exacte le 2026-06-22. Tout verrou de ce projet passe par `Cache::store('file')->lock()`.
- **Section 19.13 — ouvrir un cycle ne doit pas en modifier un autre** : le type `link` reste synchrone, un clic, sans run ni Job ; ses modales et ses textes sont inchangés. La preuve n'est pas une relecture mais le fait que les suites 54.2/56.1 passent **sans qu'une seule assertion ait été touchée** — les tests de la 56.3 vivent dans des fichiers séparés, précisément pour que cette preuve reste lisible.

- **Section 18.6 — « avant toute exécution » n'est pas une figure de style, c'est un point précis dans le temps** : pour un canal `deb`, la première exécution de code tiers est le maintainer script d'apt, en root. La vérification du hash doit donc précéder le **premier appel au helper**, pas seulement l'appel à `apt`. La preuve terrain est l'absence de toute ligne `sambaedu-ext-helper` dans `/var/log/auth.log` après un refus ; la preuve automatisée est `assertSame([], $helper->calls)`. Toute story qui ajouterait un canal d'installation (Epic 57/58) devra identifier SON premier point d'exécution et placer la vérification avant.
- **Section 18.4 — la frontière de privilège valide, elle ne fait pas confiance** : le code PHP valide déjà tout ce qu'il envoie, et le helper re-valide tout comme si l'appelant était hostile. Trois contrôles portent l'essentiel : la clé est re-validée par regex, les chemins (unité, env, fragment) sont **dérivés** de la clé au lieu d'être reçus, et le nom de paquet déclaré dans le `.deb` doit appartenir au namespace `sambaedu-ext-<clé>`. Ce dernier est ce qui empêche un manifest tiers — même parfaitement signé — de faire installer ou écraser un paquet système. Le contrôle de staging est fait **après `readlink -f`** : sans ça, un lien symbolique posé dans le staging le contournerait.
- **Section 18.4 / 18.5 — le fragment Apache est GÉNÉRÉ par le helper, jamais reçu** : accepter du contenu de configuration arbitraire depuis www-admin serait un équivalent-root (un `SetHandler` ou un `Alias` suffirait). Le helper ne reçoit que `<clé>` et `<port>`, tous deux re-validés, et compose lui-même le fragment. Corollaire opérationnel : `reload-apache` fait `apache2ctl configtest` **d'abord** et ne fait jamais de `restart` — un fragment invalide fait échouer l'étape (le moteur compense) au lieu d'empêcher Apache de redémarrer plus tard, éventuellement des jours après, sans lien visible avec la cause.
- **Section 18.2 — un secret transmis par argument est un secret publié** : `argv` est lisible dans `/proc/<pid>/cmdline`, dans un `ps` de n'importe quel utilisateur de la machine, et sudo journalise la commande complète. Le `client_secret` du client OIDC d'une extension ne transite donc que par le **stdin** de `write-env`. C'est aussi la raison pour laquelle le seam privilégié des extensions n'est pas `CommandRunner` (6.1), qui prend une chaîne déjà composée et n'a pas de stdin. Généralisation : tout futur canal d'exploitation qui doit convoyer un secret doit prévoir stdin dès sa conception, pas l'ajouter après.
- **Section 18.2 — le fichier d'environnement est le canal par lequel une extension apprend son issuer** : c'est la réponse à la friction n° 3 relevée en clôture de l'Epic 55 (« rien ne dit comment une extension apprend son issuer »). Sept variables, un contrat, un seul canal — sinon chaque éditeur en inventerait un. Permissions **0600 root:root** : systemd lit le fichier en root **avant** le drop de privilèges, donc l'utilisateur de service n'a pas besoin d'y accéder, et www-admin — qui a pourtant provoqué son écriture — ne peut pas le relire.
- **Section 18.7 — la base s'écrit en DERNIER, et c'est ce qui interdit le zombie** : l'ordre est « réversible-et-local d'abord, privilégié ensuite, base à la fin ». Si la base échoue, les compensations ramènent le système à l'état propre ; si l'ordre était inversé, on obtiendrait une extension marquée installée avec un système en vrac — exactement ce que NFR8 interdit. Corollaire moins évident : le fragment Apache est le **dernier geste système**, pour qu'on n'expose jamais `/ext/<clé>` avant que son backend ne tourne (pas de 502 provisionné).
- **Section 18.7 — une compensation qui échoue ne doit pas arrêter les suivantes** : chaque `undo` a son propre `try/catch` et journalise. Interrompre la chaîne au premier échec de compensation garantirait précisément l'état résiduel qu'on cherche à éviter. Best effort **explicite**, pas par accident.
- **Section 18.7 — le paquet vérifié survit à l'échec, pas à la désinstallation** : après un échec, le `.deb` content-addressed déjà vérifié épargne le re-téléchargement à la relance (NFR8 : « relancer réussit sans intervention ») ; après un `remove`, le conserver ferait un cache orphelin. Et un paquet trouvé en cache est **re-haché** avant réutilisation : un fichier corrompu est re-téléchargé, jamais refusé et jamais fait confiance sur son seul nom.
- **Section 18.8 — `ext:remove` est à la fois la désinstallation nominale et l'outil de nettoyage** : chaque sous-commande de retrait du helper est idempotente (absent ⇒ exit 0), et un échec en cours de route laisse l'extension marquée installée pour que la commande se rejoue. C'est délibéré : un `remove` qui « réussit » à moitié en marquant l'extension désinstallée abandonnerait des composants système sans plus aucun outil pour les retirer.
- **Section 18.8 — révoquer TOUS les clients de la clé, pas le dernier connu** : une installation avortée peut laisser un client actif que plus personne ne référence (register réussi, étape suivante en échec, compensation elle-même en échec). `remove()` révoque tous les clients `enabled` de l'`extension_key` — l'état final est sûr même après plusieurs échecs partiels. Et rien n'est jamais supprimé : la révocation est un `enabled = false` (doctrine 55.1).
- **Section 18.11 — le port est assigné par SE5, jamais déclaré par le manifest** : un éditeur tiers qui choisirait son port garantirait les collisions inter-éditeurs et ouvrirait le squat d'un port système. La colonne `installed_port` EST le registre d'allocation, et l'allocation se fait sous le **verrou fichier global** du moteur (`Cache::store('file')->lock()` — jamais `Cache::lock()`, APCu n'ayant pas de support de lock dans ce projet). Le verrou est global plutôt que par clé : les installations sont des actes d'administration rares, et un verrou unique rend l'allocation de port et l'unicité des clés triviales, sans course.
- **Section 18.11 — une clé publiée par plusieurs sources est une AMBIGUÏTÉ, pas un choix à faire** : la collision de clés est tolérée au catalogue (décision 56.1) parce que chaque carte affiche sa provenance ; à l'installation, elle doit être tranchée par l'opérateur (`--source`). Arbitrer en silence, c'est installer le paquet d'une source que personne n'a choisie — l'exact contraire de ce à quoi sert la chaîne de confiance.
- **Section 18.12 — `version` (publiée) et `installed_version` (posée) sont deux colonnes parce que ce sont deux faits différents** : les confondre rendrait la détection de mise à jour impossible (56.3) et ferait effacer la trace de ce qui tourne par une simple re-synchro. Les trois colonnes `installed_*` sont **hors `$fillable`**, comme `status` : le `fill()` de l'upsert de catalogue reçoit un manifest de source tierce, et une clé `installed_port` parasite ferait passer une extension pour installée — donc afficher une tuile — sans qu'aucun paquet n'ait jamais été vérifié.
- **Section 18.2 — la tuile d'une `app` ne s'affiche qu'avec un `installed_port`** : c'est la levée **bornée** du filtre `type = link` de la 54.3. Le port n'est écrit que par `markAppInstalled()`, en dernière étape d'une installation dont l'avant-dernière a posé le `ProxyPass`. Le tester revient à exiger que l'exposition ait été réellement provisionnée avant d'afficher un lien vers elle : une `app` marquée `integrated` à la main n'a aucun backend derrière `/ext/<clé>`, sa tuile serait morte. La règle AR3 (`entry_url === /ext/<id>`, imposée par le validateur) garantit le reste : la tuile pointe le chemin que l'installation provisionne, et pas un autre.
- **Section 18.1 / 18.10 — `IncludeOptional` DANS le vhost, jamais un `a2enconf`** : une conf globale s'appliquerait aussi au vhost legacy 8082, qui doit rester strictement inchangé (NFR16). « Optional » : aucune extension installée ⇒ aucun fichier ⇒ Apache démarre normalement. Et `config/apache/sambaedu.conf` (fallback) doit rester **en phase** avec `scripts/setupApache.sh` — exigence inscrite dans l'en-tête du fallback, à revérifier à chaque modification de vhost.
- **Section 18.1 — un fragment sudoers se valide AVANT d'être posé** : `visudo -cf` sur un fichier temporaire, puis `install -m 0440`. Un `/etc/sudoers.d/*` invalide casse `sudo` pour **toute la machine**, y compris pour l'administrateur qui vient de lancer l'update — c'est-à-dire au pire moment possible. Vaut pour tout futur fragment sudoers du projet.
- **Section 18.6 / 18.7 — `details` d'audit = une catégorie, jamais un message** : troisième déclinaison de la règle `last_error` (39.4 #E11, puis 56.1). Le journal d'audit est lisible par tout admin ; un message d'exception Guzzle porte l'URI complète, qui peut porter un jeton. Nuance propre à 56.2 : contrairement à `source_sync_failed`, il n'y a **pas** de dédoublonnage à la transition — une synchro planifiée se répète toute seule, une installation est un acte volontaire de l'opérateur, et chaque tentative mérite sa ligne.
- **Section 18 — la chaîne de confiance du paquet ne crée AUCUN second format de signature** : le `sha256` du paquet est porté par l'index déjà signé Ed25519 (56.1), donc transitivement couvert par cette signature. Le vérifier EST la vérification « contre la clé déclarée de sa source » (NFR2) — modèle apt `Release` → `Packages` → `.deb`. Une signature détachée par paquet aurait été un second vérificateur, pour la même clé, à zéro gain de sécurité et à coût non nul pour chaque éditeur. Corollaire : le paquet se télécharge **depuis l'URL de base de la source**, chemin relatif validé, redirections jamais suivies — même hôte que l'index, par construction.
- **Section 18 — l'exemption d'`ExtensionIsolationTest` est nommée et compensée** : la règle FR24 « aucune exécution système dans `app/Services/Extensions` » a exactement une exception, `SudoExtensionHelperRunner`, qui gagne en échange des contraintes plus strictes que la règle générale (il ne connaît ni `manifest`, ni le modèle `Extension` ; son binaire vient de la configuration ; chaque argument est échappé ; un seul `proc_open`). Exempter sans compenser aurait transformé un garde-fou en formalité.

- **Section 17.4 / 17.5 — l'ordre « vérifier PUIS lire » est la seule chose qui rende une source tierce acceptable** : la signature se vérifie sur les octets **verbatim** téléchargés, avant tout `json_decode`. Un test le prouve par la négative (index à la fois mal signé ET malformé : le refus est motivé par la SIGNATURE, jamais par le JSON). Toute story future qui aurait besoin de « jeter un œil » au contenu avant de le vérifier — pour choisir un parseur, deviner une version, journaliser un nom — rouvrirait la faille : c'est le contenu vérifié qui décide, jamais l'inverse.
- **Section 17.4 / 17.7 — aucun chemin d'échec ne prune, jamais** : c'est l'invariant #5 de 54.1 (« racine introuvable ≠ catalogue vide ») étendu au réseau. Un dépôt injoignable ou un catalogue refusé ne sont pas des observations : on ne peut rien conclure de ce qu'on n'a pas lu. Le sinistre de référence du projet reste le catalogue applicatif local effacé par une synchro amont ; la règle vaut pour tout futur canal de catalogue (56.2 et au-delà).
- **Section 17.5 — pinner, c'est refuser la renégociation** : la clé d'une source est lue AU PLUS UNE fois, à l'ajout, et jamais réécrite par une synchro. Un dépôt qui change de clé DOIT tomber en erreur — c'est le comportement, pas un défaut. Corollaire pour 56.2 (signature des paquets) : le paquet se vérifie contre la clé **déjà pinnée de sa source**, jamais contre une clé livrée avec lui.
- **Section 17.3 — le TOFU réseau n'a de sens que sous TLS** : récupérer `source.pub` en clair reviendrait à demander à l'attaquant potentiel de fournir la clé qui l'authentifiera. D'où la règle asymétrique : `http://` reste permis (miroir LAN, AR9) mais impose la clé collée à la main. Ne pas « assouplir » cette règle pour simplifier une installation.
- **Section 17.7 — `unreachable` et `error` ne sont pas deux nuances du même échec** : le premier est un incident réseau (rien ne change pour l'admin, NFR7), le second un refus de contenu (fail-closed, NFR2). Les fusionner en un seul état « KO » ferait soit disparaître un catalogue valide sur une coupure réseau, soit continuer à proposer un catalogue non authentifiable. La table de sémantique est dans le docblock de `ExtensionSourceSyncStatus`.
- **Section 17.9 — désactiver GÈLE, ça ne dé-intègre pas** : les `available` d'une source désactivée disparaissent (et leur fiche répond 404, pour qu'une URL directe ne les rende pas intégrables), mais une extension déjà intégrée garde sa place ET sa tuile. `ExtensionLauncherService` n'a donc **aucun** filtre de source — décision explicite qui solde le report de 54.3, verrouillée par un test de régression. Faire disparaître une tuile parce qu'un dépôt distant est tombé transformerait un incident de catalogue en panne visible pour les profs et les élèves.
- **Section 17.8 — ne pas suivre les redirections vaut mieux qu'une liste blanche** : `allow_redirects => false` rend structurellement impossible qu'un dépôt oriente les requêtes sortantes de SE5 vers un hôte de son choix. Une allowlist d'hôtes aurait la même intention et beaucoup plus de façons d'être contournée.
- **Section 17.11 — `source_sync_failed` trace une TRANSITION, pas un symptôme** : une synchro planifiée quotidienne sur un dépôt cassé empilerait 365 lignes identiques par an et noierait les vrais actes. Même discipline que le no-op de 54.2. Corollaire : la synchro réussie n'est pas auditée du tout (`last_synced_at` suffit) — le journal d'audit répond à « qui a décidé quoi », pas à « que s'est-il passé cette nuit ».
- **Section 17.12 — un message d'erreur persisté est une surface d'exposition** : ce qui est écrit en base est lu par une UI ; ce qui vient d'une exception HTTP porte l'URI complète. Toute colonne `last_error`/`*_error` du projet doit recevoir une **catégorie** produite par le code, jamais un `$e->getMessage()`. Troisième occurrence de ce piège (39.4 #E11, puis ici) : il mérite d'être vérifié par défaut en review.
- **Section 17.1 — le format de catalogue v1 est un CONTRAT PUBLIC (NFR11)** : `index.json` + `index.json.sig` + `source.pub`, manifests embarqués inline, `index_version` strict. Des éditeurs tiers vont l'implémenter ; il n'évoluera qu'**additivement** (56.2 y ajoutera un bloc `install` par manifest). Le durcir après publication casserait ses consommateurs — c'est la raison pour laquelle `ExtensionManifestValidator` avait déjà été durci en 54.1/54.3 *avant* qu'aucune source distante n'existe.

- **Section 16.1 — un garde-fou par scan textuel doit embarquer ses formes d'évasion connues** : une regex qui mord sur la syntaxe canonique (`auth(`) ne dit rien des variantes légales que PHP autorise — antislash de résolution globale, FQCN inline, alias d'import, conteneur. Sans un jeu de contre-exemples **figé en dur** dans le test, un tel garde-fou ne verrouille qu'une syntaxe, pas une propriété. Règle dérivée : toute règle portant sur un **site d'appel** doit être doublée d'une règle sur l'**import FQCN**, seule façon de fermer la voie de l'alias. Vaut pour tout futur test d'architecture du projet, pas seulement pour la quarantaine des extensions.
- **Section 16.2 — écrire les résidus plutôt que les taire** : troisième occurrence sur cet epic (canal de timing en 55.2, limite du scan ici, granularité d'erreur du témoin). Un écart connu et documenté vaut mieux qu'une promesse absolue démentie par le code — et c'est ce qui permet à la revue suivante de ne pas le redécouvrir comme s'il était neuf.

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
- [ ] **16.1 Test de mutation : injecter `\…\Auth::user()`, `app('db')` puis un alias dans le témoin ⇒ quarantaine ROMPUE à chaque fois, verte après retrait**
- [ ] 16.2 La limite résiduelle du scan textuel est écrite (test dédié + README du témoin)
- [ ] **17.1 Ajout d'une source avec clé collée : 2 extensions au catalogue, badge « Tierce », `is_official = f`**
- [ ] 17.2 Ajout TOFU https : `source.pub` lu **exactement une fois** (journal d'accès du dépôt)
- [ ] **17.3 `http://` sans clé collée : REFUSÉ, aucune requête sortante — et accepté AVEC la clé collée**
- [ ] **17.4 Catalogue altéré non re-signé : source « Catalogue refusé », `available` masquées, intégrée + tuile intactes, `count(extensions)` inchangé, nom piraté nulle part**
- [ ] **17.5 Dépôt qui change de clé : erreur, `public_key` inchangée, aucun nouveau GET sur `source.pub`**
- [ ] 17.6 Badges Officielle/Tierce (icône + libellé) ; modale d'avertissement nommant l'hôte ; un-clic conservé pour l'officielle ; double-clic sans double audit
- [ ] **17.7 Dépôt injoignable : `available` toujours proposées, tuile intacte, `last_synced_at` conservé, aucun prune**
- [ ] 17.8 Redirection 302 : jamais suivie, aucune requête vers la cible
- [ ] **17.9 Désactivation : `available` masquées + fiche 404, intégrée conservée avec sa tuile ; retrait refusé tant qu'une intégrée existe ; source embarquée sans aucune action**
- [ ] 17.10 `ext:sources:sync` (une / toutes), code retour non-zéro en échec, planification à 02:50, idempotence
- [ ] **17.11 Audit : une ligne par acte réel, no-op muet, `source_sync_failed` UNE seule fois par transition, acteur `system` en planifié, trace survivant au retrait**
- [ ] 17.12 `last_error` sans URL ni jeton ; URL avec query ou identifiants refusée à la saisie
- [ ] **18.1 Provisioning ops : helper 0755, sudoers 0440 validé, modules proxy/headers, `/etc/sambaedu/extensions` en 0700 root, `IncludeOptional` dans le vhost :80 SEULEMENT — et `update.sh` rejouable en no-op**
- [ ] **18.2 Installation réelle : unité active, env 0600 root, ProxyPass posé, `curl /ext/hello` en 200, tuile au lanceur, DB + audit `install` (acteur `system`), secret nulle part sauf dans le fichier**
- [ ] 18.3 No-op : « déjà installée », exit 0, zéro `sudo`, zéro audit, zéro requête au dépôt
- [ ] **18.4 Le helper refuse : clé invalide, port hors format, `.deb` hors staging, `.deb` au nom d'un paquet système, lien symbolique — exit ≠ 0, aucun fichier créé**
- [ ] **18.5 `configtest` : un fragment invalide fait échouer `reload-apache` SANS recharger ; Apache reste actif**
- [ ] **18.6 sha256 altéré : exit 1, AUCUNE invocation du helper dans `auth.log`, rien dans `/etc`, aucun client OIDC actif, audit `install_failed` sans URL, aucun `.tmp` résiduel — + contre-épreuve après régénération du dépôt**
- [ ] **18.7 Échec apt à mi-parcours : compensations jouées, `status=available`, client révoqué, paquet vérifié conservé, relance réussie SANS re-téléchargement**
- [ ] 18.8 `ext:remove` : 404, unité disparue, paquet purgé, env et fragment retirés, staging nettoyé, tuile disparue, audit `remove` ; rejeu = no-op exit 0 ; `link` refusée
- [ ] 18.9 Jeton déjà émis mort immédiatement après `ext:remove` (401 sur `/userinfo`)
- [ ] **18.10 NFR16 : `/ipxe`, `/doc`, `/assets/*` et le vhost legacy 8082 identiques avant/après pose ET retrait du fragment ; rien dans `conf-enabled/`**
- [ ] 18.11 Ambiguïté ⇒ `--source` ; clé déjà installée depuis une autre source refusée en la nommant ; ports 8600/8601 et trous comblés
- [ ] **18.12 Re-synchro du catalogue : `version` passe à 2.0.0, `installed_version` reste 1.0.0, `installed_port`/`installed_at`/`status` inchangés**
- [ ] **19.1 Intégration depuis l'UI : modale (provenance + scopes + avertissement tierce), run `pending→running→success` en base, étapes aux libellés de la CLI, audit `install` à VOTRE login**
- [ ] 19.2 Le Job apparaît dans `/admin/workers` (`queue_task_runs`), file `default`, sans que 56.3 n'y écrive rien
- [ ] **19.3 Deux navigateurs : un seul run, tous les boutons gelés chez l'autre, même progression, F5 sans perte**
- [ ] 19.4 Aucune requête périodique au repos ; polling 3 s uniquement pendant un run
- [ ] **19.5 Update 1.0.0 → 1.1.0 : `install-package` + `restart-service` SEULEMENT, port/env/fragment/client OIDC intacts, deux `.deb` en staging, audit `update`**
- [ ] 19.6 `ext:update` sur une extension à jour : exit 0, zéro helper, zéro audit
- [ ] **19.7 apt cassé à mi-update : ancienne version RE-SERVIE (`dpkg-query` + `curl`), base inchangée, audit `update_failed`, opération rejouable**
- [ ] 19.8 Republication d'une version antérieure : proposée comme changement et exécutée (`--allow-downgrades`)
- [ ] **19.9 `redirect_paths` modifiés : refus AVANT toute action — aucun appel helper, aucun téléchargement**
- [ ] **19.10 Gage de rollback absent ou corrompu : la mise à jour ne démarre pas**
- [ ] **19.11 Workers arrêtés : run `pending`, rien d'installé, reprise au redémarrage ; worker tué ⇒ « Interrompue » et boutons libérés après le seuil**
- [ ] 19.12 Désinstallation `app` depuis l'UI : texte de purge des composants système (jamais le texte `link`), retrait complet, audit `remove`
- [ ] **19.13 Cycle `link` inchangé : un clic, synchrone, aucun run créé**
- [ ] 19.14 Écran périmé : no-op propre, page remise en phase, jamais une 500

---

## Section 22 — Extension « Visioconférences » (BBB) : première extension `app` réelle (Story 57.1)

> **Ce que cette section valide.** Les Sections 17 à 21 ont éprouvé le canal d'extensions avec une extension de TEST (`hello`, un serveur Python de vingt lignes). La 57.1 y fait passer une **vraie application** : un backend PHP autonome, client OIDC, avec son état, sa page d'administration et ses appels sortants vers des serveurs tiers. C'est le critère d'acceptation final du système d'extensions — s'il ne suffit pas ici, il ne suffit nulle part.
>
> Quatre propriétés portent la valeur de cette section, et aucune n'est démontrable sur l'hôte :
>
> 1. **l'installation de bout en bout par le canal STANDARD** — même `index.json` signé, même `ext:install`, même helper root, même fragment Apache. Aucune procédure spéciale, aucun script d'extension privilégié ;
> 2. **`StateDirectory=` + `DynamicUser=yes`** — l'UID du service est volatil. La base SQLite doit survivre à un `systemctl restart` ET à un reboot. C'est l'amendement instruit par la story à la lettre de la décision D3 (le postinst ne crée plus rien), et il ne se prouve que sur une machine qui redémarre ;
> 3. **le SSO d'un compte RÉEL** — un prof, un élève, un admin, avec leurs vraies classes dans le claim `groups` ; et le refus d'un compte dont le rôle n'est pas résoluble ;
> 4. **le test de connexion contre un vrai serveur BigBlueButton** — succès, mauvais secret, timeout.
>
> Ce que la suite automatisée prouve déjà, et qui n'a **pas** à être rejoué ici : la suite d'attaque du vérificateur d'id_token (`alg: none`, confusion HS256, clé étrangère, `kid` inconnu, `iss`/`aud`/`exp`/`nbf`/`nonce`, rejeu `jti`, tous les échecs de signature fusionnés en un code), la migration idempotente et le 0600 du fichier SQLite, le gating strict `role=admin` de la page serveurs, le secret jamais rendu dans une page, le mapping des quatre retours du test de connexion, la conformité du manifest v1, et la quarantaine FR33 avec ses méta-tests. Deux suites, deux commandes :
>
> ```bash
> cd /var/www/sambaedu-reload/extensions/bbb && composer install && vendor/bin/phpunit   # 123 tests
> cd /var/www/sambaedu-reload && php artisan test --filter ExtensionBbbIsolationTest     # 14 tests
> ```
>
> **Dette worktree assumée, iso 54.x/55.x/56.x** : story développée dans un worktree git non synchronisé vers la VM. À jouer **au merge sur `main`**, après `bash scripts/update.sh`.

### Pré-requis de la section

Le provisionnement ops de la Section 18.1 doit être en place (helper root, sudoers, `a2enmod proxy proxy_http headers`, `IncludeOptional /etc/apache2/sambaedu-ext.d/*.conf` dans le vhost `:80`). En plus, la cible doit disposer de PHP en ligne de commande avec SQLite — c'est ce que le paquet déclare en `Depends` :

```bash
dpkg -l | grep -E 'php-cli|php-sqlite3|php-curl|php-xml|php-mbstring'
php -m | grep -E 'pdo_sqlite|curl|simplexml|openssl'
```

**Attendu** : `pdo_sqlite` présent **pour le PHP CLI**. Le PHP du core SE5 ne l'a pas — c'est précisément la dépendance `php-sqlite3` du paquet qui l'apporte, et `apt` l'installera si besoin au moment de l'intégration (scénario 22.2). S'il manque encore APRÈS l'installation du paquet, c'est un bug de `Depends`, pas une manipulation à faire à la main.

### Scénario 22.1 — Publier l'extension dans un dépôt signé et l'ajouter comme source

```bash
cd /var/www/sambaedu-reload/extensions/bbb
bash packaging/publish-test-repo.sh /tmp/ext-bbb
# → paquet, sha256 réel, index.json + index.json.sig + source.pub, clé jetable

# Servir le dépôt (garder ce terminal ouvert : son journal d'accès sert au 22.2)
cd /tmp/ext-bbb/repo && python3 -m http.server 8099
```

Puis, dans SE5 : `/admin/extensions/sources` → « Ajouter une source », URL `http://<ip>:8099`, clé publique collée depuis `repo/source.pub` (**obligatoire en `http://`** — le TOFU ne s'applique qu'en https).

```bash
php artisan ext:sources:sync
php artisan tinker --execute="dump(\App\Models\Extension::where('key','bbb')->first(['key','name','type','version','status'])?->toArray());"
```

**Attendu** :

- le paquet s'appelle **exactement** `sambaedu-ext-bbb_1.0.0_all.deb`, et `dpkg-deb --field <deb> Package` vaut **exactement** `sambaedu-ext-bbb` (c'est ce que le helper root re-vérifie ; un écart = refus root, après téléchargement) ;
- `install.sha256` dans `index.json` est celui du `.deb` réellement construit, en **64 hexadécimaux minuscules** — et surtout **pas** les 64 zéros du manifest commité (le remplissage est remplacé à la publication ; publier le manifest tel quel doit faire échouer l'installation à la frontière fail-closed, jamais après) ;
- l'extension apparaît au catalogue en `available`, type `app`, avec les scopes `profile` **et** `groups` affichés dans la modale d'intégration ;
- `entry_url` vaut `/ext/bbb`.

**Contre-épreuve (2 minutes, elle vaut le coup)** : republier une version (`bash packaging/publish-test-repo.sh --version 1.0.1 /tmp/ext-bbb`) et vérifier que `source.pub` est **inchangé** — la clé est réutilisée, le pin TOFU de la source n'est pas invalidé.

### Scénario 22.2 — ⭐ Installation de bout en bout par le canal standard (AC1)

```bash
php artisan ext:install bbb
```

**Attendu, dans cet ordre** (le journal de la commande nomme chaque étape) : sha256 vérifié → client OIDC enregistré → `write-env` → `apt` → `enable-service` → fragment + `reload-apache` → base + audit.

Contrôles système :

```bash
systemctl is-active sambaedu-ext-bbb ; systemctl is-enabled sambaedu-ext-bbb
ls -l /etc/sambaedu/extensions/bbb.env          # 0600 root:root
cat /etc/apache2/sambaedu-ext.d/bbb.conf        # ProxyPass /ext/bbb → 127.0.0.1:<port>
ss -lntp | grep 86                              # écoute 127.0.0.1 EXCLUSIVEMENT
curl -sS -o /dev/null -w '%{http_code}\n' http://127.0.0.1:<port>/
curl -sS -o /dev/null -w '%{http_code}\n' http://<ip-serveur>/ext/bbb
```

**Attendu** :

- l'unité est **active et enabled** — mais c'est SE5 qui l'a activée, pas le paquet. Vérification : `dpkg -e` du paquet montre un `postinst` sans le moindre `systemctl` ;
- le fichier d'environnement contient **exactement 7 lignes** `SE5_*`, dont `SE5_EXT_BASE_PATH=/ext/bbb` et `SE5_OIDC_REDIRECT_URI=/ext/bbb/oidc/callback` (un **chemin**, pas une URL absolue) ;
- l'écoute est sur `127.0.0.1`, **jamais** `0.0.0.0` ;
- les deux `curl` rendent `200` : le backend répond sur `/` (chemin **nu**, le proxy a retiré le préfixe) et l'exposition publique fonctionne ;
- **le secret client OIDC n'apparaît nulle part** sauf dans `/etc/sambaedu/extensions/bbb.env` : `grep -r "$(grep SE5_OIDC_CLIENT_SECRET /etc/sambaedu/extensions/bbb.env | cut -d= -f2)" /var/log /var/www/sambaedu-reload/storage/logs 2>/dev/null` doit ne rien rendre.

**Contrôle de non-régression NFR16** : `/ipxe`, `/doc`, `/assets/*` et le vhost legacy `:8082` répondent exactement comme avant la pose du fragment.

### Scénario 22.3 — La tuile « Visioconférences » (AC1)

Se connecter à SE5 successivement en **prof**, en **élève**, en **administratif** et en **admin**, puis ouvrir le lanceur d'applications.

**Attendu** : la tuile « Visioconférences » (icône `fa-solid fa-video`) est visible pour les **quatre** rôles — `visibility.roles` les déclare tous. Elle pointe `/ext/bbb`. Le lanceur n'émet **aucune** requête HTTP vers l'extension pour se rendre (l'état vient des colonnes `health_*`, cf. Section 21).

### Scénario 22.4 — ⭐ SSO d'un compte réel, et ce que l'extension en apprend (AC3)

Cliquer la tuile en tant que **prof réel**, puis « Se connecter ».

**Attendu** :

- redirection vers `/oidc/authorize` **avec** `code_challenge_method=S256` et `redirect_uri=/ext/bbb/oidc/callback` (visible dans la barre d'adresse ou l'inspecteur réseau) ;
- après consentement implicite, retour sur `/ext/bbb/` : la page affiche le **nom d'affichage**, le **rôle** et **les classes réelles** du professeur, sous forme de puces ;
- le cookie de session s'appelle `se5_ext_bbb` et son `Path` est `/ext/bbb` — il n'est **jamais** envoyé aux URL de SE5 (vérifiable dans l'inspecteur, onglet Application) ;
- **aucune requête à la base ni à l'annuaire de SE5** : `tcpdump`/journal Postgres inutiles ici, c'est le test d'architecture qui le prouve — mais on vérifie l'équivalent observable, à savoir que l'extension fonctionne alors qu'elle n'a **aucun** identifiant de base dans son environnement (`grep -i 'db\|pgsql\|ldap' /etc/sambaedu/extensions/bbb.env` ne rend rien).

Répéter en **élève** : la page affiche « sa » classe, et **aucun lien « Serveurs BBB »** n'apparaît. Répéter en **admin** : le lien apparaît.

Sur toutes les pages, le pied de page porte « ← Retour à SambaEdu » (FR16), pointant l'issuer.

### Scénario 22.5 — Un rôle non résoluble ne connecte personne

Prendre un compte dont le profil métier ne se résout pas (le fournisseur omet alors le claim `role` — contrat 55.2 : « non résoluble ⇒ clé ABSENTE »), puis tenter la connexion à l'extension.

**Attendu** : page d'erreur sobre, HTTP **403**, message « Votre profil ne permet pas d'utiliser cette extension », code de diagnostic `bbb.claims.role_unsupported`. **Aucune session ouverte** : revenir sur `/ext/bbb/` propose de nouveau « Se connecter ». Aucun repli sur un rôle par défaut, dans un sens comme dans l'autre.

### Scénario 22.6 — Configuration des serveurs BBB (AC2)

En **admin**, ouvrir « Serveurs BBB ».

1. Ajouter un serveur : URL `https://<votre-bbb>/bigbluebutton/api`, secret partagé (valeur de `bbb_secret` sur le serveur BBB).
2. Recharger la page.

**Attendu** :

- le secret est **masqué** en liste (`••••••••` + les 4 derniers caractères), et n'apparaît **nulle part** dans la source HTML — `Ctrl+U`, puis rechercher le secret : zéro occurrence ;
- « Modifier » ne pré-remplit **jamais** le champ secret, et l'aide dit explicitement que le laisser vide conserve la valeur actuelle. Modifier l'URL seule et vérifier que le test de connexion passe **toujours** (le secret n'a pas été effacé) ;
- saisir une URL en `http://` : l'ajout est accepté, mais un avertissement **affiché** signale que le secret circulera en clair. Il n'est jamais silencieux ;
- saisir `pas-une-url`, `ftp://…`, ou une URL avec `?x=1` : refus explicite, rien n'est écrit ;
- cocher « Scalelite » sans seuil, ou avec `0` : refus. Avec `120` : accepté, la liste affiche « Scalelite · seuil 120 ».

**Contrôle du bug legacy** : déclarer trois serveurs, supprimer **celui du milieu**, en ajouter un quatrième, puis tester la connexion de chacun. Chaque serveur doit rester associé à SON secret. (SE4 tenait trois listes CSV indexées par position ; la suppression décalait les index et un serveur héritait du secret d'un autre.)

### Scénario 22.7 — ⭐ Test de connexion contre un vrai serveur BBB (AC2)

Pour chaque cas, cliquer « Tester » sur la ligne du serveur.

| Situation | Attendu |
|---|---|
| URL et secret corrects | « Connexion réussie : URL et secret acceptés, N réunion(s) en cours. » |
| Secret volontairement faux | « **Secret invalide** : le serveur a rejeté la signature de la requête (checksumError). » |
| Hôte inexistant / port fermé | « **Serveur injoignable** : aucune réponse dans le délai imparti. » — et la réponse revient en **moins de 10 secondes** |
| URL pointant une page web quelconque | « **Réponse inattendue** : l'adresse répond, mais ce n'est pas une API BigBlueButton. » |

**Attendu, en plus** : le message nomme l'**hôte** du serveur testé, jamais son secret ni l'URL signée (qui porte le `checksum`). Et **aucun appel n'est émis au simple affichage de la page** — vérifiable en coupant le serveur BBB puis en rechargeant `/ext/bbb/admin/servers` : la page se rend instantanément.

**Certificat auto-signé** : si votre BBB de test en porte un, le test doit rendre « Serveur injoignable ». C'est **correct et voulu** — le legacy désactivait la vérification TLS sur tous ses appels, ce qui exposait le secret partagé à n'importe quel intermédiaire. La correction est d'ajouter l'autorité au magasin du système, jamais de désactiver la vérification.

### Scénario 22.8 — ⭐ `StateDirectory` + `DynamicUser` : l'état survit à l'UID volatil

```bash
ls -ld /var/lib/sambaedu-ext-bbb                 # 0700, propriétaire = utilisateur dynamique
ls -l  /var/lib/sambaedu-ext-bbb/database.sqlite # 0600
systemctl show sambaedu-ext-bbb -p DynamicUser -p StateDirectory
stat -c '%U:%G' /var/lib/sambaedu-ext-bbb

systemctl restart sambaedu-ext-bbb && sleep 2
stat -c '%U:%G' /var/lib/sambaedu-ext-bbb        # peut avoir CHANGÉ : c'est normal
```

Puis **recharger la page des serveurs** : les serveurs déclarés doivent tous être là.

**Enfin, le contrôle qui compte** : `reboot` la VM, attendre le démarrage, recharger la page.

**Attendu** : `DynamicUser=yes`, `StateDirectory=sambaedu-ext-bbb`, et **les données présentes après le reboot**. Si elles ont disparu ou si le service tombe en boucle sur un `SQLSTATE[HY000] [14] unable to open database file`, c'est que la gestion du répertoire d'état a régressé vers un `chown` figé — l'exact scénario que l'amendement à D3 évite. Le `postinst` doit rester vide : `dpkg-deb -I <deb> postinst` ne contient ni `mkdir`, ni `chown`, ni `systemctl`.

### Scénario 22.9 — Santé : la sonde voit l'extension, et la voit tomber

```bash
php artisan ext:health:check bbb
php artisan tinker --execute="dump(\App\Models\Extension::where('key','bbb')->first(['health_status','health_checked_at'])?->toArray());"

systemctl stop sambaedu-ext-bbb
php artisan ext:health:check bbb
# … puis regarder la tuile dans le lanceur, en prof

systemctl start sambaedu-ext-bbb
php artisan ext:health:check bbb
```

**Attendu** : `ok` → `unreachable` → `ok`. Extension arrêtée, la tuile porte le badge « Indisponible » et **reste cliquable** (FR35 sous contrainte FR14). Aucune page de SE5 ne ralentit pendant l'arrêt.

**Contrôle spécifique à cette extension** : couper le **serveur BBB** (pas l'extension) et relancer `ext:health:check bbb`. L'état doit rester **`ok`** — la racine `/` ne dépend d'aucun tiers. Si elle passait à `unreachable`, c'est qu'un appel sortant a été introduit au rendu de la page d'accueil, et le serveur intégré de PHP étant mono-processus, il gèlerait pour tout le monde.

### Scénario 22.10 — Comportement sous charge légère (la faiblesse assumée de D2)

```bash
# 20 requêtes concurrentes sur la racine, pendant qu'un test de connexion tourne
# vers un serveur BBB volontairement injoignable (blackhole : IP non routée)
for i in $(seq 1 20); do curl -s -o /dev/null -w '%{http_code} %{time_total}\n' http://127.0.0.1:<port>/ & done; wait
```

**Attendu** : toutes les requêtes rendent `200` en moins de deux secondes. Le test de connexion en cours ne doit pas les bloquer — c'est ce que garantissent `PHP_CLI_SERVER_WORKERS=4` et surtout la borne totale de 8 s sur l'appel BBB. Si l'ensemble se fige, la conclusion n'est pas « augmenter les workers » : c'est qu'un appel sortant a perdu sa borne.

### Scénario 22.11 — Retrait propre

```bash
php artisan ext:remove bbb
systemctl status sambaedu-ext-bbb ; dpkg -l | grep sambaedu-ext-bbb
ls /etc/sambaedu/extensions/ /etc/apache2/sambaedu-ext.d/
curl -sS -o /dev/null -w '%{http_code}\n' http://<ip-serveur>/ext/bbb
```

**Attendu** : unité disparue, paquet purgé, `bbb.env` et `bbb.conf` retirés, `/ext/bbb` en **404**, tuile disparue du lanceur, audit `remove`. Rejouer `ext:remove bbb` : no-op, sortie 0.

**Point d'attention, à consigner** : `/var/lib/sambaedu-ext-bbb` est géré par systemd via `StateDirectory=`. Vérifier ce qu'il devient après le retrait, et **le noter dans la review** — la conservation des données (serveurs déclarés, et à partir de 57.2 les salons) après une désinstallation est une décision produit qui n'a pas encore été prise, pas un détail d'implémentation.

### Checklist rapide — Section 22

- [ ] **22.1 Dépôt signé publié : `Package` = `sambaedu-ext-bbb`, sha256 RÉEL (pas les 64 zéros), scopes `profile`+`groups`, `entry_url` `/ext/bbb` — et republication à clé CONSERVÉE**
- [ ] **22.2 `ext:install bbb` de bout en bout : unité active (activée par SE5, pas par le paquet), env 0600 à 7 lignes, ProxyPass posé, écoute 127.0.0.1 seule, `/ext/bbb` en 200, secret nulle part ailleurs que dans l'env**
- [ ] 22.2b Non-régression NFR16 : `/ipxe`, `/doc`, `/assets/*`, vhost legacy 8082 inchangés
- [ ] 22.3 Tuile « Visioconférences » visible pour prof, élève, administratif et admin
- [ ] **22.4 SSO réel : PKCE S256, `redirect_uri` = chemin, nom/rôle/classes réels affichés, cookie `se5_ext_bbb` limité au Path `/ext/bbb`, aucun identifiant de base ni d'annuaire dans l'environnement**
- [ ] **22.5 Rôle non résoluble : 403, `bbb.claims.role_unsupported`, AUCUNE session ouverte, aucun repli**
- [ ] **22.6 Page serveurs : secret jamais dans le HTML, édition qui ne l'efface pas, avertissement `http` affiché, URL invalides refusées, seuil Scalelite exigé — et suppression du serveur du milieu SANS décalage des secrets**
- [ ] **22.7 Test de connexion réel : succès avec décompte, « Secret invalide » sur checksumError, « injoignable » sous 10 s, « réponse inattendue » sur une URL quelconque — et zéro appel au rendu de la page**
- [ ] **22.8 `StateDirectory` + `DynamicUser` : base 0600 dans un répertoire 0700, données intactes après `restart` ET après reboot, postinst vide de tout mkdir/chown/systemctl**
- [ ] **22.9 Santé : `ok` → `unreachable` → `ok` ; serveur BBB coupé ⇒ l'extension reste `ok` (la racine ne dépend de rien)**
- [ ] 22.10 20 requêtes concurrentes en 200 pendant un appel BBB vers un trou noir
- [ ] **22.11 `ext:remove bbb` : 404, unité et paquet partis, env et fragment retirés, tuile disparue, rejeu no-op — et sort du répertoire d'état CONSIGNÉ**

---

## Section 23 — Salons BBB : création, visibilité et jonction (Story 57.2)

> **Ce que cette section valide, et pourquoi elle ne ressemble à aucune autre.** La 57.2 déplace l'autorisation **du client vers le serveur**. SE4 n'en avait aucune : son formulaire de jonction portait `meetingId`, `attendedPW` **et** `moderatorPW` en champs cachés dans le HTML servi à tout le monde, et son lancement donnait le mot de passe modérateur à **tout non-élève**, sur n'importe quel salon — y compris celui d'un collègue. Il suffisait d'un `Ctrl+U`.
>
> Trois propriétés portent donc cette section, et **deux d'entre elles se vérifient en regardant ce qui N'ARRIVE PAS** :
>
> 1. **le rôle observé DANS la conférence** — le créateur est modérateur, tout le monde d'autre est participant, y compris un professeur co-membre de la classe et un administrateur. Cela ne se lit que dans BigBlueButton, sur un vrai serveur ;
> 2. **l'élève d'une autre classe ne voit rien et n'entre pas**, même en rejouant la requête à la main ;
> 3. **la migration v1 → v2 sur la base RÉELLE de l'instance** : les serveurs déclarés en 57.1 doivent survivre à la mise à jour du paquet.
>
> Ce que la suite automatisée prouve déjà, et qui n'a **pas** à être rejoué ici : la matrice d'autorisation complète au niveau contrôleur (élève de la classe / élève d'une autre classe / collègue / administratif / administrateur / créateur, sur les trois visibilités), le refus **indistinct** entre jeton inconnu et salon interdit, la garde « groupes soumis ⊆ claim » avec son contournement de formulaire simulé, l'absence de tout mot de passe dans le HTML de chaque page, l'absence de tout appel sortant au rendu, l'anti-CSRF, le schéma v2 avec sa cascade, et le mapping des réponses `createMeeting` / `isMeetingRunning` sur du vrai XML.
>
> ```bash
> cd /var/www/sambaedu-reload/extensions/bbb && vendor/bin/phpunit        # 213 tests
> cd /var/www/sambaedu-reload && php artisan test --testsuite=Architecture # 145 tests
> ```
>
> **Dette worktree assumée, iso 54.x/55.x/56.x/57.1** : story développée dans un worktree git non synchronisé vers la VM. À jouer **au merge sur `main`**, après `bash scripts/update.sh`.

### Pré-requis de la section

La Section 22 doit être passée : extension installée par le canal standard, SSO fonctionnel, **et au moins un serveur BigBlueButton déclaré et testé vert** (scénario 22.7). Sans serveur actif, seuls les scénarios 23.1, 23.2 et 23.7 sont jouables.

Prévoir **quatre comptes réels** : une professeure de 4ᵉB (`prof.martin` ci-dessous), un second professeur de la même 4ᵉB, un élève de 4ᵉB, un élève de 5ᵉA. Plus un compte administratif et un compte `admin` pour les contre-épreuves.

### Scénario 23.1 — ⭐ Migration v1 → v2 sur la base de l'instance

À jouer **avant** toute autre chose, sur une instance où la 57.1 tournait déjà avec ses serveurs configurés.

```bash
# AVANT la mise à jour du paquet
sudo -u \#$(stat -c '%u' /var/lib/sambaedu-ext-bbb) sqlite3 /var/lib/sambaedu-ext-bbb/database.sqlite \
  "PRAGMA user_version; SELECT id, base_url, enabled FROM servers;"

php artisan ext:update bbb     # ou ext:install si l'extension n'était pas posée

# APRÈS
sudo -u \#$(stat -c '%u' /var/lib/sambaedu-ext-bbb) sqlite3 /var/lib/sambaedu-ext-bbb/database.sqlite \
  "PRAGMA user_version; SELECT id, base_url, enabled FROM servers; .tables"
```

**Attendu** : `user_version` passe de `1` à `2` ; la table `servers` est **inchangée, ligne pour ligne, secret pour secret** ; les tables `rooms` et `room_groups` apparaissent, vides. Redémarrer le service (`systemctl restart sambaedu-ext-bbb`) et relire : `user_version` vaut toujours `2` et rien n'a bougé — la migration est rejouable, et le service redémarre à chaque mise à jour de paquet.

> Si `sqlite3` n'est pas installé, `php -r` avec PDO fait l'affaire ; l'important est de **lire la base avant et après**, pas l'outil.

### Scénario 23.2 — Création d'un salon par une vraie professeure (AC1)

Se connecter en **prof.martin**, ouvrir la tuile, cliquer « Voir les salons ».

**Attendu sur la page d'accueil** : une carte « Salons » avec un **lien** vers `/ext/bbb/rooms` — jamais une liste incorporée. C'est délibéré : la racine est la sonde de santé, elle n'ouvre pas la base (contrôle en 23.8).

Sur `/ext/bbb/rooms` :

1. le formulaire « Créer un salon » est présent ;
2. la liste des cases à cocher « Mes classes et équipes » contient **exactement les classes et équipes réelles** de cette professeure — celles de son claim `groups`, ni plus ni moins. Aucune classe d'un collègue, aucun balayage d'annuaire ;
3. créer un salon « Cours de mathématiques », visibilité « une ou plusieurs de mes classes », case 4ᵉB.

**Attendu** : retour à la liste, message « Salon créé », le salon apparaît sous « Mes salons » avec « Visible par : 4B » et « Dernière ouverture : jamais ouvert ». **Aucun meeting n'a été créé côté BigBlueButton** — `créer ≠ démarrer` (vérifiable : la liste des réunions du serveur BBB est inchangée).

**Contre-épreuves à faire dans la foulée** :

- se connecter en **élève**, en **administratif**, en **admin** : le formulaire de création n'apparaît pour aucun des trois. Seuls les professeurs créent ;
- en prof.martin, soumettre le formulaire avec un nom vide, puis avec « classes » sans cocher de case : refus explicite, la saisie est conservée, rien n'est écrit.

### Scénario 23.3 — ⭐ Le contournement du formulaire, joué pour de vrai

C'est **le** scénario de la story. Le `<select>` ne protège rien ; ce qui protège est la comparaison faite au serveur.

Depuis la console du navigateur, connecté en **prof.martin**, sur `/ext/bbb/rooms` :

```js
// On fabrique une soumission portant une classe qui n'est PAS la sienne.
const f = document.querySelector('form[action$="/rooms"]');
const i = document.createElement('input');
i.type = 'hidden'; i.name = 'groups[]'; i.value = '6C';   // ← classe d'un collègue
f.appendChild(i);
f.querySelector('[name=name]').value = 'Salon volé';
f.querySelector('[value=classe]').checked = true;
f.submit();
```

**Attendu** : la page revient en **422** avec « Vous ne pouvez ouvrir un salon que pour vos propres classes et équipes. » **Rien n'est écrit** — ni le salon, ni sa partie légitime. Vérifier en base :

```bash
sqlite3 /var/lib/sambaedu-ext-bbb/database.sqlite "SELECT name FROM rooms; SELECT * FROM room_groups;"
```

**Attendu** : aucune trace de « Salon volé ». Le refus est **explicite**, pas un filtrage silencieux : une valeur qu'un utilisateur légitime ne peut pas produire est une tentative, et elle se voit.

### Scénario 23.4 — ⭐ Démarrage, et le rôle observé DANS la conférence (AC1)

En **prof.martin**, cliquer « Démarrer ou entrer » sur « Cours de mathématiques ».

**Attendu** :

- redirection immédiate vers le serveur BigBlueButton, la conférence s'ouvre ;
- **dans la conférence**, la professeure est **modérateur** : elle a l'icône de modération, peut couper les micros, gérer la présentation, créer des groupes (« breakout rooms ») ;
- l'enregistrement est **possible** (bouton présent — `record=true` + `allowStartStopRecording=true`, iso-legacy) ;
- le **chat privé est désactivé** (`lockSettingsDisablePrivateChat`, iso-legacy) ;
- en quittant la conférence, le retour se fait sur **`/ext/bbb/rooms`** (`logoutUrl`).

**Ce qu'il faut regarder dans la barre d'adresse** : l'URL de jonction contient bien un `password=` et un `checksum=`. **C'est normal, et c'est le seul endroit où un mot de passe apparaît.** Il a été fabriqué par le serveur, dans un `Location:`, et il n'est écrit nulle part ailleurs.

**Le contrôle qui compte, à faire juste après** : revenir sur `/ext/bbb/rooms`, faire `Ctrl+U`, chercher le mot de passe relevé dans l'URL. **Zéro occurrence.** Chercher aussi `moderatorPW`, `attendeePW`, `password` : rien. C'est exactement ce que SE4 servait à tout le monde.

Vérifier enfin que la liste affiche maintenant une date sous « Dernière ouverture ».

### Scénario 23.5 — ⭐ L'élève de 4ᵉB entre en participant, celui de 5ᵉA n'entre pas (AC2)

**Élève de 4ᵉB** — ouvrir la tuile, « Voir les salons ».

**Attendu** : « Cours de mathématiques » apparaît sous « Salons accessibles », avec le nom de la professeure et « Visible par : 4B ». Cliquer « Rejoindre ».

- la conférence s'ouvre et l'élève y est **participant** : aucune icône de modération, pas de gestion de présentation, pas de coupure de micro d'autrui ;
- il n'a saisi **aucun mot de passe**, et n'en a jamais vu passer un.

**Élève de 5ᵉA** — même parcours.

**Attendu** : « Cours de mathématiques » **n'apparaît pas** dans sa liste. Puis, le contrôle réel — récupérer le jeton du salon (visible dans la source de la page de l'élève de 4ᵉB, champ `token`) et le rejouer depuis le compte de l'élève de 5ᵉA :

```js
// Console du navigateur, connecté en élève de 5eA, sur /ext/bbb/rooms
const t = document.querySelector('[name=_token]').value;
fetch('/ext/bbb/rooms/join', {
  method: 'POST',
  headers: {'Content-Type': 'application/x-www-form-urlencoded'},
  body: '_token=' + t + '&token=<LE-JETON-DU-SALON-4B>',
  redirect: 'manual',
}).then(r => console.log(r.status));
```

**Attendu** : **404**, page « Ce salon n'existe pas, ou ne vous est pas accessible », code `bbb.rooms.not_found`. Recommencer avec un jeton **totalement inventé** (32 caractères au hasard) : **exactement la même réponse**, même code, même texte. C'est voulu — deux réponses différentes diraient « ce salon existe, mais pas pour vous ».

**Contrôle du bug legacy `[0]`** : si vous disposez d'un élève inscrit dans **plusieurs** classes dont la 4ᵉB, vérifier qu'il voit le salon même si la 4ᵉB n'est pas sa première classe. SE4 ne comparait que la première et l'excluait à tort.

### Scénario 23.6 — ⭐ Le « non-élève = modérateur » du legacy est bien mort

Le défaut §9.2 de la carte du legacy, éprouvé de face. Pour chacun de ces comptes, rejoindre « Cours de mathématiques » et **regarder le rôle dans la conférence** :

| Compte | Attendu DANS BigBlueButton |
|---|---|
| **prof.martin** (créatrice) | **modérateur** |
| Second professeur, co-membre de la 4ᵉB | **participant** |
| Compte `administratif` (sur un salon `Tout l'établissement`) | **participant** |
| Compte `admin` de SambaEdu (sur un salon `Tout l'établissement`) | **participant** |

**Attendu** : une seule personne est modérateur, et c'est celle qui a créé le salon. Un compte `admin` administre SambaEdu et la configuration des serveurs BBB ; il ne modère pas le cours d'un professeur. Si l'un de ces trois comptes arrive avec les droits de modération, **la story a échoué** — c'est très exactement le comportement de SE4.

**Salon privé** : créer en prof.martin un salon « Entretien » en visibilité « Privé », le démarrer. Vérifier qu'il **n'apparaît chez personne d'autre** (ni le collègue, ni l'élève, ni l'admin) et que rejouer son jeton depuis un autre compte rend 404. Le `private` de SE4 signifiait « tous les personnels » — ici il veut dire ce qu'il dit.

### Scénario 23.7 — Salon fermé, meeting expiré, serveur absent

Quatre situations, quatre messages, et aucune page blanche.

1. **Salon jamais démarré** — créer un salon « Atelier » en visibilité établissement, ne PAS le démarrer, puis cliquer « Rejoindre » depuis un autre compte.
   **Attendu** : page « Ce salon n'est pas ouvert », qui nomme le salon et son créateur, avec un retour vers la liste. Ce n'est **pas** une erreur : ni 4xx, ni 5xx, c'est un état normal. **Aucun appel n'est émis vers BigBlueButton** (vérifiable en coupant le serveur BBB : la page reste instantanée).

2. **Meeting terminé** — sur un salon démarré, terminer la conférence côté BigBlueButton (`endMeeting` par la modératrice, ou attendre la fin des **quatre heures** de `duration`), puis « Rejoindre » depuis un compte élève.
   **Attendu** : même page « Ce salon n'est pas ouvert ». Le salon (durable) a survécu au meeting (éphémère), et rien n'a eu besoin d'être nettoyé — c'est ce qui remplace le cache et son ramasse-miettes de SE4.

3. **Re-démarrage par la créatrice** — cliquer de nouveau « Démarrer ou entrer ».
   **Attendu** : la conférence se ré-ouvre, en modérateur, **sans erreur de doublon**. `createMeeting` est idempotent : le même bouton crée, re-crée ou rejoint.

4. **Aucun serveur actif** — désactiver tous les serveurs sur `/ext/bbb/admin/servers`, puis « Démarrer ou entrer ».
   **Attendu** : « Aucun serveur de visioconférence configuré — prévenez l'administrateur ». Et côté élève, « Rejoindre » rend « Ce salon n'est pas ouvert ». Réactiver ensuite.

5. **Serveur injoignable** — pointer un serveur actif vers une IP non routée, puis « Rejoindre » côté élève.
   **Attendu** : « Serveur de visioconférence injoignable » — **et surtout pas** « Ce salon n'est pas ouvert », qui enverrait une classe entière attendre pour rien. La réponse revient en **moins de 10 secondes**.

### Scénario 23.8 — La sonde de santé n'a pas bougé, et les actions restent des POST

```bash
# La racine ne dépend toujours de rien : serveur BBB coupé, elle reste verte.
systemctl stop <votre-bbb>   # ou couper le réseau vers lui
php artisan ext:health:check bbb
php artisan tinker --execute="dump(\App\Models\Extension::where('key','bbb')->first(['health_status'])?->toArray());"
```

**Attendu** : `health_status` reste **`ok`**. La page `/ext/bbb/` se rend instantanément et propose le lien « Voir les salons ». Si l'état bascule en `unreachable`, c'est qu'un appel sortant a été introduit au rendu de la racine.

```bash
# Les actions sont des POST, et rien d'autre.
curl -sS -o /dev/null -w '%{http_code}\n' 'http://<ip>/ext/bbb/rooms/start?token=xxx'
curl -sS -o /dev/null -w '%{http_code}\n' 'http://<ip>/ext/bbb/rooms/join?token=xxx'
curl -sS -o /dev/null -w '%{http_code}\n' 'http://<ip>/ext/bbb/rooms/delete?token=xxx'
```

**Attendu** : **405** sur les trois. Un GET mutateur serait préchargé au survol d'un lien par un navigateur ou un antivirus, et ouvrirait des conférences tout seul.

**Contrôle de fluidité (garde D2)** : pendant qu'un « Démarrer » tourne vers un serveur BBB injoignable, charger 20 fois `/ext/bbb/` et `/ext/bbb/rooms` en parallèle. Toutes les requêtes doivent rendre `200` rapidement — la liste se rend depuis SQLite, elle n'attend personne.

### Scénario 23.9 — Suppression, et ce qui part avec

En prof.martin, supprimer « Cours de mathématiques » (confirmation demandée).

**Attendu** : le salon disparaît de sa liste **et de celle de ses élèves**. En base :

```bash
sqlite3 /var/lib/sambaedu-ext-bbb/database.sqlite \
  "SELECT COUNT(*) FROM rooms; SELECT COUNT(*) FROM room_groups;"
```

**Attendu** : les lignes `room_groups` du salon sont parties avec lui (`ON DELETE CASCADE`, qui ne fonctionne que parce que `PRAGMA foreign_keys` est activé à l'ouverture — ce n'est pas le défaut de SQLite).

**Contre-épreuve** : depuis le compte du **second professeur**, rejouer une suppression sur le jeton d'un salon de prof.martin (même méthode qu'en 23.5). **Attendu** : 404 indistinct, et le salon **intact**.

### Scénario 23.10 — Point d'attention à consigner, hérité de la 22.11

`/var/lib/sambaedu-ext-bbb` porte désormais **les salons de l'établissement**, et plus seulement la liste des serveurs. Ce que devient ce répertoire après `php artisan ext:remove bbb` **n'est toujours pas spécifié** par le contrat du canal d'installation. Le vérifier et le **consigner dans la review** : c'est une décision produit (conserver ? purger ? demander ?), pas un détail d'implémentation, et elle pèse plus lourd qu'à la 57.1.

### Checklist rapide — Section 23

- [ ] **23.1 Migration v1 → v2 sur la base RÉELLE : `user_version` 1 → 2, serveurs intacts ligne pour ligne, `rooms`/`room_groups` créées, redémarrage sans effet**
- [ ] 23.2 Création par une vraie professeure : cases = ses classes réelles, salon créé, AUCUN meeting côté BBB
- [ ] 23.2b Formulaire de création absent pour élève, administratif et admin
- [ ] **23.3 Contournement du formulaire avec une classe étrangère : 422 explicite, RIEN en base**
- [ ] **23.4 Démarrage : modératrice DANS la conférence, retour sur `/rooms` en sortant, et zéro mot de passe dans la source de la page**
- [ ] **23.5 Élève 4ᵉB participant sans mot de passe ; élève 5ᵉA : salon absent + 404 sur rejeu de la requête, identique à un jeton inventé**
- [ ] 23.5b Élève multi-classes : le salon de sa 2ᵉ classe lui est bien visible
- [ ] **23.6 Collègue, administratif et admin rejoignent en PARTICIPANT — le « non-élève = modérateur » du legacy est mort**
- [ ] **23.6b Salon privé : invisible et injoignable pour tout autre que son créateur**
- [ ] 23.7 Salon jamais démarré / meeting expiré ⇒ « pas ouvert » sans appel inutile ; re-démarrage idempotent ; aucun serveur actif et serveur injoignable ⇒ deux messages DIFFÉRENTS
- [ ] **23.8 Serveur BBB coupé ⇒ santé toujours `ok` ; `/rooms/start|join|delete` en GET ⇒ 405 ; navigation fluide pendant un appel qui traîne**
- [ ] 23.9 Suppression par le créateur : cascade des groupes ; suppression par un tiers ⇒ 404 et salon intact
- [ ] **23.10 Sort de `/var/lib/sambaedu-ext-bbb` après `ext:remove` : CONSIGNÉ dans la review (il porte maintenant les salons)**

---

## Section 24 — Invités externes et enregistrements BBB (Story 57.3)

> **Ce que cette section valide, et pourquoi elle est la plus exposée de l'epic.** La 57.3 ouvre **la seule surface non authentifiée de l'extension** : `GET/POST /ext/bbb/visio`, hors SSO, atteignable par n'importe qui. Tout ce que la 57.2 a gagné en déplaçant l'autorisation côté serveur doit y tenir dans un contexte plus hostile, et **la moitié des propriétés se vérifient en regardant ce qui N'ARRIVE PAS**.
>
> Le contre-modèle est nommé : le `CONF_HASH` de SE4 était **le login du créateur, en clair**, dans l'URL publique — énumérable, un seul salon invitable par personne, aucune révocation, comparaison du secret en clair et **sans aucune limite de tentatives** (`visio/index.php:27`), plus une page d'attente en `Refresh: 15` qui faisait de chaque invité un générateur d'appels sortants. Rien de tout cela n'est porté.
>
> Trois choses ne se prouvent QUE sur une VM avec un vrai serveur BigBlueButton :
>
> 1. **la jonction effective d'un invité SANS COMPTE**, depuis un navigateur en navigation privée, à travers le proxy Apache réel (`/ext/bbb/visio` → chemin nu côté backend) — et son apparition **en participant**, nom suffixé « (invité) », dans la liste de la conférence ;
> 2. **le cycle réel d'un enregistrement** : une séance enregistrée par BigBlueButton, son passage par l'état `processing` puis `published`, son apparition dans l'onglet, sa lecture, sa suppression ;
> 3. **la migration v2 → v3 sur la base RÉELLE** de l'instance, qui porte désormais les salons ET leurs invitations.
>
> Ce que la suite automatisée prouve déjà, et qui n'a **pas** à être rejoué à la main : l'égalité **octet pour octet** des quatre refus de la route publique (jeton inconnu / invitation révoquée / mot de passe faux / fenêtre saturée), l'absence de tout appel sortant dans ces quatre cas, la comparaison en temps constant contre une valeur factice, l'absence structurelle de magasin d'état sur le parcours invité (prouvée par le typage du contrôleur ET par l'absence de tout `Set-Cookie`), le fait qu'`attendee_pw` soit le seul mot de passe atteignable depuis `/visio`, le filtrage puis le **re-filtrage** des enregistrements, le refus de suppression d'un `recordID` étranger **sans qu'aucun appel de suppression ne soit émis**, le schéma v3 et son idempotence, et le mapping `getRecordings` / `deleteRecordings` sur de vraies réponses XML — enregistrement sans bloc `playback` compris.
>
> ```bash
> cd /var/www/sambaedu-reload/extensions/bbb && vendor/bin/phpunit         # 296 tests
> cd /var/www/sambaedu-reload && php artisan test --testsuite=Architecture # 145 tests
> ```
>
> **Dette worktree assumée, iso 54.x/55.x/56.x/57.1/57.2** : story développée dans un worktree git non synchronisé vers la VM. À jouer **au merge sur `main`**, après `bash scripts/update.sh`.

### Pré-requis de la section

Les Sections 22 et 23 doivent être passées : extension installée par le canal standard, SSO fonctionnel, **au moins un serveur BigBlueButton déclaré et testé vert**, et **au moins un salon réel appartenant à une vraie professeure** (`prof.martin` ci-dessous), déjà démarré une fois — sans quoi il n'a pas de serveur mémorisé et rien n'est jouable.

Prévoir en plus : **un poste ou un navigateur SANS session SambaEdu** (fenêtre de navigation privée suffit, à condition de ne s'y connecter à rien), et un second professeur pour les contre-épreuves.

### Scénario 24.1 — ⭐ Migration v2 → v3 sur la base de l'instance

À jouer **avant** toute autre chose, sur une instance où la 57.2 tournait déjà avec ses salons.

```bash
# AVANT la mise à jour du paquet
sudo -u \#$(stat -c '%u' /var/lib/sambaedu-ext-bbb) sqlite3 /var/lib/sambaedu-ext-bbb/database.sqlite \
  "PRAGMA user_version; SELECT COUNT(*) FROM servers; SELECT id, name, owner_sub, server_id FROM rooms;"

php artisan ext:update bbb

# APRÈS
sudo -u \#$(stat -c '%u' /var/lib/sambaedu-ext-bbb) sqlite3 /var/lib/sambaedu-ext-bbb/database.sqlite \
  "PRAGMA user_version; SELECT COUNT(*) FROM servers; SELECT id, name, owner_sub, server_id, guest_token, guest_failures FROM rooms;"
```

**Attendu** : `user_version` passe de `2` à `3` ; `servers` **inchangée** ; `rooms` inchangée **ligne pour ligne**, avec quatre colonnes de plus — `guest_token` et `guest_password` à `NULL`, `guest_failures` à `0`, `guest_window_started_at` à `NULL`. Les salons et leurs groupes de visibilité sont intacts.

Puis `systemctl restart sambaedu-ext-bbb` et relire : `user_version` vaut toujours `3`, rien n'a bougé, **et le service est bien reparti**. C'est le point qui compte : `ALTER TABLE … ADD COLUMN` échoue si la colonne existe, et seule la garde `user_version` empêche le palier d'être rejoué. Un service qui ne redémarre pas après la deuxième mise à jour est le symptôme exact d'une migration non idempotente.

Vérifier enfin l'index unique :

```bash
sqlite3 /var/lib/sambaedu-ext-bbb/database.sqlite ".indexes rooms"
```

**Attendu** : `rooms_guest_token` figure dans la liste.

### Scénario 24.2 — ⭐ Un invité SANS COMPTE rejoint la conférence

C'est **le** scénario de la story.

En **prof.martin**, sur `/ext/bbb/rooms`, dans la colonne « Invitation externe » du salon, cliquer **« Activer l'invitation »**.

**Attendu** : la page affiche un **lien absolu** de la forme `https://<instance>/ext/bbb/visio?g=<32 caractères hexadécimaux>` **et** un **mot de passe de 8 caractères** en majuscules et chiffres, sans `0`, `O`, `1`, `I` ni `l` — il doit pouvoir se dicter au téléphone.

Contrôles immédiats :

- le lien est **absolu** et porte le nom d'hôte réel de l'instance, pas `127.0.0.1` ni le port interne — il dérive de l'issuer OIDC, jamais d'un en-tête `Host:` ;
- le jeton ne contient **ni le login de la professeure, ni le nom du salon, ni la classe**. Il ne veut rien dire, et c'est sa qualité.

Puis démarrer le salon (bouton « Démarrer ou entrer »), et **depuis un navigateur en navigation privée** :

1. ouvrir le lien. **Attendu** : un formulaire « Rejoindre une visioconférence », deux champs (nom, mot de passe du salon), **aucun bouton de connexion SambaEdu proéminent**, aucune barre de navigation de SE5 ;
2. saisir un nom (« Monsieur Durand ») et le mot de passe affiché à la professeure ;
3. **Attendu** : redirection directe **dans la conférence**, sans compte, sans inscription.

**Dans BigBlueButton, côté professeure** : le nouvel arrivant apparaît **en participant** (pas modérateur : ni micro forcé, ni contrôle de présentation, ni pouvoir d'éjection) et son nom est **« Monsieur Durand (invité) »**. Le suffixe n'est pas cosmétique : un externe ne doit pas pouvoir se présenter sous l'apparence d'un membre de l'établissement.

**Contre-épreuve à ne pas sauter** : dans le navigateur privé, `Ctrl+U` sur la page du formulaire **et** sur la page de refus. Aucun mot de passe BigBlueButton ne doit y figurer — ni participant, ni modérateur. Le seul secret que la page manipule est le mot de passe d'invitation, et elle le **demande**, elle ne le donne pas.

### Scénario 24.3 — ⭐ La route publique n'est pas un oracle

Toujours en navigation privée. Comparer **quatre** réponses, dans cet ordre :

```bash
BASE=https://<instance>/ext/bbb/visio
G=<le jeton affiché à la professeure>

# a) jeton inventé de toutes pièces
curl -sS -o /tmp/a.html -w '%{http_code}\n' -X POST "$BASE" -d "g=jeton-invente" -d "name=Intrus" -d "password=x"

# b) bon jeton, mauvais mot de passe
curl -sS -o /tmp/b.html -w '%{http_code}\n' -X POST "$BASE" -d "g=$G" -d "name=Intrus" -d "password=FAUXFAUX"

# c) dix échecs de plus, puis le BON mot de passe (fenêtre saturée)
for i in $(seq 1 10); do curl -sS -o /dev/null -X POST "$BASE" -d "g=$G" -d "name=Intrus" -d "password=ENCOREFX"; done
curl -sS -o /tmp/c.html -w '%{http_code}\n' -X POST "$BASE" -d "g=$G" -d "name=Intrus" -d "password=<LE BON>"

# d) après révocation depuis la page de la professeure
curl -sS -o /tmp/d.html -w '%{http_code}\n' -X POST "$BASE" -d "g=$G" -d "name=Intrus" -d "password=<LE BON>"

md5sum /tmp/a.html /tmp/b.html /tmp/c.html /tmp/d.html
```

**Attendu** : **403** quatre fois, et **quatre empreintes identiques**. Aucune des pages ne dit « lien inconnu », « trop de tentatives » ni « invitation révoquée » : un message distinct confirmerait l'existence du salon à quelqu'un qui n'a pas le mot de passe.

**Attendu aussi, et c'est la moitié du scénario** : dans le journal du serveur BigBlueButton, **aucune requête** n'est arrivée pendant ces quatorze appels. Un refus ne parle jamais au serveur distant — il ne se trahit donc pas par sa durée, et la route publique ne peut pas servir à faire travailler l'infrastructure.

Vérifier enfin qu'**aucun cookie** n'est posé sur ce parcours :

```bash
curl -sS -D - -o /dev/null "$BASE?g=$G" | grep -i set-cookie
curl -sS -D - -o /dev/null -X POST "$BASE" -d "g=$G" -d "name=X" -d "password=y" | grep -i set-cookie
```

**Attendu** : aucune ligne. Le parcours invité n'ouvre aucun état par visiteur, ni avant ni après la vérification.

### Scénario 24.4 — Fenêtre anti-bourrinage, observée en vrai

Après le scénario 24.3, l'invitation a été révoquée : la **régénérer** depuis la page de la professeure (bouton « Régénérer »), ce qui remet aussi les compteurs à zéro.

1. depuis le navigateur privé, saisir **dix fois** un mauvais mot de passe ;
2. saisir ensuite le **bon** : **refusé**, avec la même page que d'habitude ;
3. attendre **plus de quinze minutes** sans rien tenter ;
4. resaisir le bon mot de passe : **la jonction passe**.

**Attendu complémentaire** : pendant la période bloquée, la professeure, elle, **ne subit rien** — elle démarre, entre, régénère et révoque normalement. La fenêtre est **par salon et par invité**, elle ne ferme pas le salon.

**Et surtout** : aucune requête ne doit avoir mis 2, 5 ou 10 secondes à revenir. Il n'y a **pas** de temporisation : sur un serveur HTTP intégré mono-processus, faire attendre un attaquant reviendrait à bloquer tout l'établissement.

### Scénario 24.5 — Révocation et régénération, constatées depuis le poste invité

Deux allers-retours, à faire les deux :

- **Régénérer** : le poste invité recharge l'ancien lien et saisit l'ancien mot de passe ⇒ **refus immédiat**, la même page que d'habitude. Le **nouveau** lien avec le **nouveau** mot de passe fonctionne aussitôt. SE4 n'avait aucune régénération ;
- **Révoquer** : la colonne « Invitation externe » repasse à « Aucune invitation active », et l'ancien lien meurt **à la seconde**, sans attendre l'expiration de quoi que ce soit. SE4 n'avait aucune révocation non plus — le lien expirait tout seul au bout de quatre heures, ou disparaissait quand le meeting s'éteignait.

**Contre-épreuve d'étanchéité** : se connecter en **élève de la classe** puis en **second professeur**, ouvrir `/ext/bbb/rooms` et faire `Ctrl+U`. **Attendu** : ni le jeton d'invitation, ni le mot de passe d'invitation n'apparaissent dans la source. Ils ne sont lus, côté serveur, que pour les salons dont la personne connectée est le créateur.

### Scénario 24.6 — ⭐ Un enregistrement réel : liste, lecture, suppression

`record=true` est posé depuis la 57.2 : tout meeting démarré par l'extension est enregistrable.

1. en **prof.martin**, démarrer le salon, **lancer l'enregistrement** dans BigBlueButton, parler une minute, l'arrêter, puis **terminer la conférence** (bouton de fin, pas seulement fermer l'onglet — un meeting qui se referme tout seul met plus longtemps) ;
2. ouvrir **`/ext/bbb/recordings`** (lien « Enregistrements » sur la page des salons) **tout de suite**.

**Attendu à ce stade** : l'enregistrement **n'apparaît pas encore**, et la page ne montre **aucune erreur** — BigBlueButton le traite (`processing`), et seuls les enregistrements `published` sont demandés. C'est un contrôle en soi : un enregistrement en cours de traitement n'a pas de bloc de lecture, et il ne doit **ni apparaître, ni faire disparaître les autres**.

3. attendre la fin du traitement (de quelques minutes à un quart d'heure selon le serveur), recharger.

**Attendu** : une ligne avec le **nom du salon tel que la professeure l'a écrit** (pas le nom rapporté par BigBlueButton), la date et l'heure de la séance, sa durée, et deux boutons.

4. **« Lire »** : ouvre un **nouvel onglet** sur l'URL de lecture BigBlueButton, la séance se rejoue. L'extension ne rediffuse rien elle-même ;
5. **« Supprimer »** : confirmation demandée, puis message « Enregistrement supprimé », et la ligne disparaît.

**Attendu côté serveur BBB** : l'enregistrement a réellement disparu (`bbb-record --list` ou l'interface d'administration du serveur). Une suppression annoncée qui n'aurait pas eu lieu serait le pire des résultats.

### Scénario 24.7 — ⭐ « SES salons uniquement », et le bug legacy qu'on enterre

Le filtre du legacy lisait le segment n°2 d'un `meetingID` fabriqué par concaténation de hashes — position fausse **dès qu'un salon portait des classes**, c'est-à-dire pour les salons les plus courants.

1. faire enregistrer une séance par le **second professeur**, sur un salon **à lui**, portant lui aussi des classes ;
2. en **prof.martin**, ouvrir `/ext/bbb/recordings`.

**Attendu** : elle voit ses enregistrements, **et uniquement les siens** — y compris ceux de salons portant des classes, qui étaient précisément ceux que SE4 perdait. L'enregistrement du collègue n'apparaît nulle part.

3. tenter la suppression croisée. Récupérer un `recordID` du collègue (visible dans son propre onglet), et le poster depuis la session de prof.martin sur **son** salon à elle :

```bash
curl -sS -o /dev/null -w '%{http_code}\n' -X POST https://<instance>/ext/bbb/recordings/delete \
  -b cookies-prof-martin.txt \
  -d "_token=<le jeton du formulaire>" \
  -d "token=<jeton public d'un salon de prof.martin>" \
  -d "record=<recordID du collègue>"
```

**Attendu** : redirection, message « Cet enregistrement n'appartient pas à ce salon », **et l'enregistrement du collègue toujours présent** sur le serveur BBB. Le serveur a interrogé BigBlueButton pour vérifier l'appartenance, et **n'a émis aucune demande de suppression**.

4. rejouer la même requête avec le **jeton d'un salon du collègue** : **404**, indistinct, et **aucun appel** vers BigBlueButton.

### Scénario 24.8 — Rôles et surfaces des enregistrements

- **élève**, **administratif**, **admin** : `/ext/bbb/recordings` en accès direct ⇒ **403** propre, page d'erreur de l'extension. Le lien « Enregistrements » n'apparaît d'ailleurs pas sur leur page des salons — mais c'est la garde serveur qui compte, pas l'affichage ;
- **non connecté** : redirection vers `/ext/bbb/login` (parcours SSO), jamais la liste.

Et les méthodes :

```bash
curl -sS -o /dev/null -w '%{http_code}\n' 'https://<instance>/ext/bbb/recordings/delete?token=x&record=y'
curl -sS -o /dev/null -w '%{http_code}\n' 'https://<instance>/ext/bbb/rooms/guest/enable?token=x'
curl -sS -o /dev/null -w '%{http_code}\n' 'https://<instance>/ext/bbb/rooms/guest/revoke?token=x'
```

**Attendu** : **405** sur les trois — les actes mutants sont des POST, jetonnés.

### Scénario 24.9 — Pannes : ce qui doit rester debout

- **serveur BBB éteint** pendant que la professeure ouvre `/ext/bbb/recordings` ⇒ un message « injoignable », **la page se rend quand même**, et la santé de l'extension reste `ok` côté SambaEdu (`/admin/extensions`). Une extension vivante ne doit pas être déclarée morte parce qu'un tiers l'est ;
- **deux serveurs déclarés**, l'un injoignable : les enregistrements de l'autre **s'affichent quand même**, avec un message pour le serveur en panne ;
- **serveur désactivé par l'admin** : il n'est **pas interrogé du tout**, un message le dit, et les enregistrements réapparaissent tels quels après réactivation — rien n'a été perdu ni supprimé ;
- **salon jamais démarré** : il n'a pas de serveur mémorisé, aucune requête n'est émise pour lui ;
- **invité pendant que le salon est fermé** : après le bon mot de passe, page « La visioconférence n'est pas encore ouverte », avec un bouton « Réessayer ». **Vérifier qu'aucun rafraîchissement automatique n'a lieu** (laisser l'onglet ouvert cinq minutes et surveiller le journal d'accès Apache : une seule requête). Le `Refresh: 15` du legacy transformait chaque invité laissé sur un onglet en cron d'appels sortants.

**Contrôle de fluidité (garde D2)** : pendant qu'un `/ext/bbb/recordings` traîne vers un serveur lent, charger 20 fois `/ext/bbb/` et `/ext/bbb/rooms` en parallèle depuis **la même session** (mêmes cookies). Toutes doivent rendre `200` rapidement : le verrou d'état est relâché avant chaque appel sortant, y compris entre deux serveurs.

### Scénario 24.10 — Suppression d'un salon : ce que l'utilisateur a été prévenu de perdre

Supprimer un salon qui a **une invitation active** et **au moins un enregistrement**.

**Attendu** : la demande de confirmation dit explicitement que l'invitation cessera de fonctionner **et** que les enregistrements ne seront plus accessibles depuis SambaEdu. Après suppression :

- l'ancien lien d'invitation rend le refus indistinct ;
- l'enregistrement **disparaît de l'onglet** — c'est une conséquence, pas un filtre : sans ligne `rooms`, il n'existe plus de requête qui le trouve ;
- **l'enregistrement est TOUJOURS sur le serveur BigBlueButton** (`bbb-record --list`). C'est délibéré : supprimer les enregistrements d'un salon à sa suppression serait destructeur et irréversible.

> **À consigner** : reprendre un enregistrement devenu orphelin est un **acte d'exploitant**, qui passe par l'interface ou les outils du serveur BigBlueButton lui-même. L'extension n'offre pas de vue d'administration pour cela, et c'est une décision, pas un oubli.

### Scénario 24.11 — Point d'attention, hérité de la 22.11 et de la 23.10

`/var/lib/sambaedu-ext-bbb` porte désormais **les salons, leurs invitations et les mots de passe d'invitation en clair**. Deux choses à vérifier et à consigner :

1. les droits du fichier restent `0600`, et le répertoire `0700`, après la mise à jour du paquet et après un redémarrage (`DynamicUser=yes` ⇒ UID volatil, remap par `StateDirectory=`) ;
2. ce que devient ce répertoire après `php artisan ext:remove bbb` **n'est toujours pas spécifié** par le contrat du canal d'installation. La question pèse un cran de plus qu'à la 57.2.

### Checklist rapide — Section 24

- [ ] **24.1 Migration v2 → v3 sur la base RÉELLE : `user_version` 2 → 3, serveurs et salons intacts ligne pour ligne, 4 colonnes neuves à NULL/0, index `rooms_guest_token`, redémarrage sans effet**
- [ ] **24.2 Invité SANS COMPTE : lien absolu + mot de passe dictable, jonction effective, PARTICIPANT dans la conférence, nom suffixé « (invité) »**
- [ ] 24.2b `Ctrl+U` sur formulaire et refus : aucun mot de passe BigBlueButton
- [ ] **24.3 Les quatre refus (inconnu / faux / saturé / révoqué) : 403 et MÊME empreinte md5, zéro requête côté BBB**
- [ ] **24.3b Aucun `Set-Cookie` sur le parcours invité, ni au GET ni au POST**
- [ ] **24.4 Fenêtre : 10 échecs ⇒ refus même avec le bon mot de passe ; déblocage après 15 min ; aucune temporisation observée ; la professeure n'est jamais gênée**
- [ ] **24.5 Régénération et révocation constatées depuis le poste invité : ancien lien mort à la seconde**
- [ ] 24.5b Jeton et mot de passe d'invitation absents de la source pour un élève et pour un autre professeur
- [ ] **24.6 Enregistrement réel : invisible en `processing` SANS erreur, puis listé, lu, supprimé — et réellement disparu du serveur BBB**
- [ ] **24.7 Les enregistrements d'un collègue n'apparaissent jamais, y compris pour des salons portant des classes (le bug `explode[2]` du legacy)**
- [ ] **24.7b `recordID` étranger posté : refus, AUCUNE suppression émise, enregistrement du collègue intact ; salon d'autrui ⇒ 404 sans appel**
- [ ] 24.8 Élève / administratif / admin ⇒ 403 ; non connecté ⇒ `/login` ; GET sur les 3 routes mutantes ⇒ 405
- [ ] **24.9 Serveur éteint : page rendue, santé `ok` ; 2 serveurs dont 1 injoignable : l'autre s'affiche ; désactivé : pas d'appel ; invité sur salon fermé : AUCUN auto-rafraîchissement (journal Apache)**
- [ ] 24.9b Fluidité même-session pendant un `/recordings` qui traîne
- [ ] **24.10 Suppression d'un salon : confirmation qui prévient, invitation morte, enregistrement invisible côté SE5 mais TOUJOURS présent côté BBB**
- [ ] 24.11 Droits `0600`/`0700` après update et reboot ; sort de `/var/lib/sambaedu-ext-bbb` à `ext:remove` CONSIGNÉ (il porte maintenant des mots de passe d'invitation en clair)

## Section 25 — Équilibrage multi-serveurs et extinction du BBB legacy (Story 57.4)

> **Ce que cette section valide, et pourquoi elle clôt l'Epic 57.** La 57.4 a **deux volets étanches**. Le premier vit entièrement dans l'extension : le salon ne part plus sur « le premier serveur actif » mais sur **le moins chargé**, avec **bascule sur panne** — l'algorithme de SE4, repris tel quel (D5), y compris sa sémantique Scalelite. Le second vit dans le core et n'a rien à voir : il **éteint** la surface BBB legacy de SE5, ce qui est le critère d'acceptation final du système d'extensions (AR12) — « l'accès à la visioconférence passe exclusivement par la tuile ».
>
> Le contre-modèle est nommé, et il est instructif : SE4 répartissait sur la foi d'un `server_bbb_is_up()` qui n'était **qu'un GET sur l'URL de base** — il déclarait vivant un serveur dont le secret était faux, et vivant aussi un Scalelite éteint. Il gardait ses mesures dans un cache en mémoire partagée, avec un compteur d'échecs jamais purgé hors cron. Rien de tout cela n'est porté : la sonde est **signée**, elle est **bornée à 3 s**, elle n'a **aucune mémoire**, et ce qui protège vraiment d'un serveur tombé est la **bascule au moment de créer** — le seul instant qui compte.
>
> Quatre choses ne se prouvent QUE sur une VM avec **deux serveurs BigBlueButton réels** :
>
> 1. **la répartition observée** : le meeting apparaît dans la console du serveur le moins chargé, et pas dans l'autre ;
> 2. **la bascule réelle**, serveur coupé entre la sonde et le clic — le cours s'ouvre quand même, **sans que le professeur fasse quoi que ce soit** ;
> 3. **le budget de temps sous mono-processus** : pendant qu'un démarrage sonde un serveur mort, le reste de l'établissement navigue ;
> 4. **l'extinction traversée pour de vrai**, y compris sur une instance où `/var/www/sambaedu` existe encore — c'est là, et seulement là, que le repli vers le système de fichiers SE4 se vérifie fermé.
>
> Ce que la suite automatisée prouve déjà, et qui n'a **pas** à être rejoué à la main : toute la matrice du choix (moins chargé gagne, égalité départagée par le plus petit identifiant, Scalelite **jamais sondé** mais délégable, serveur désactivé ni sondé ni candidat, `SUCCESS` sans conférence = charge 0), la bascule bornée à **exactement deux** tentatives, l'absence de bascule sur secret refusé ou réponse inattendue, le verrou d'état relâché **avant la toute première sonde**, l'absence de sonde au rendu de la moindre page, le mapping de `measureLoad` sur de vraies réponses XML, les redirections `/bbb/*` et `/visio` (préfixe UAI compris), et le « grep = 0 » avec ses méta-tests.
>
> ```bash
> cd /var/www/sambaedu-reload/extensions/bbb && vendor/bin/phpunit         # 331 tests
> cd /var/www/sambaedu-reload && php artisan test --testsuite=Architecture # 153 tests
> ```
>
> **Dette worktree assumée, iso 54.x/55.x/56.x/57.1/57.2/57.3** : story développée dans un worktree git non synchronisé vers la VM. À jouer **au merge sur `main`**, après `bash scripts/update.sh`.

### Pré-requis de la section

Les Sections 22 à 24 doivent être passées. Prévoir en plus, et c'est le vrai coût de cette section : **deux serveurs BigBlueButton joignables** depuis l'instance, tous deux déclarés et testés verts sur `/ext/bbb/admin/servers`, avec un accès aux **consoles ou aux journaux des deux** (`bbb-conf --check`, ou la liste des conférences actives).

À défaut d'un second serveur BBB, les scénarios 25.1 à 25.3 restent jouables en **dégradé** : déclarer deux fois le même serveur ne prouverait rien, mais déclarer un serveur réel et une **adresse morte** (`https://bbb-absent.invalid/bigbluebutton/api`, secret quelconque) prouve l'exclusion et la bascule, qui sont l'essentiel.

### Scénario 25.1 — ⭐ Le meeting part sur le serveur le MOINS CHARGÉ

Ouvrir une conférence de charge sur le **premier** serveur (par exemple depuis une autre instance, ou en y faisant entrer deux ou trois participants), et laisser le **second** vide.

En **prof.martin**, sur `/ext/bbb/rooms`, cliquer **« Démarrer ou entrer »** sur un salon.

**Attendu** :

- la conférence apparaît dans la console du **second** serveur (le vide), **pas** dans celle du premier ;
- la page revient sur la conférence, la professeure y est **modérateur**, comme d'habitude ;
- côté base, le salon a mémorisé le serveur qui l'a réellement ouvert :

```bash
sudo -u \#$(stat -c '%u' /var/lib/sambaedu-ext-bbb) sqlite3 /var/lib/sambaedu-ext-bbb/database.sqlite \
  "SELECT r.name, r.server_id, s.base_url FROM rooms r JOIN servers s ON s.id = r.server_id;"
```

Inverser ensuite les charges (vider le premier, charger le second) et démarrer un **autre** salon : il doit partir sur le premier. C'est la mesure qui décide, à chaque démarrage, et rien d'autre.

**Contrôle qui compte** : dans le journal Apache du serveur BBB **le moins chargé**, on doit voir **deux** requêtes — un `getMeetings` (la sonde) puis un `create`. Sur l'autre, **un seul** `getMeetings`. La sonde interroge tout le monde ; la création ne s'adresse qu'au vainqueur.

### Scénario 25.2 — ⭐ Un serveur tombe entre la sonde et le clic : la bascule

C'est le scénario que la 57.4 existe pour rendre indolore.

1. laisser les deux serveurs joignables et **peu chargés**, le second étant le moins chargé ;
2. **couper le second** (`systemctl stop bbb-*`, ou couper le réseau vers lui) ;
3. démarrer un salon.

**Attendu** : le salon **s'ouvre quand même**, sur le premier serveur, et le professeur **n'a rien à faire** — pas de message d'erreur, pas de second clic. Le temps d'ouverture est simplement plus long (une sonde qui expire à 3 s, puis une création).

Deux vérifications qui distinguent les deux lignes de défense :

- si le serveur est coupé **avant** le clic, il est écarté dès la **sonde** — le journal du serveur vivant montre alors `getMeetings` puis `create`, et un seul `create` a été émis en tout ;
- si le serveur tombe **entre** la sonde et la création (le cas rare, à simuler en le coupant pendant l'ouverture), l'extension émet **deux** `create` : un vers le mort, un vers le vivant. C'est la bascule, et elle est **bornée à un seul réessai**.

Et dans les deux cas, le salon mémorise **le serveur qui a réellement ouvert** (requête SQL du 25.1) : c'est lui que la jonction des élèves suivra.

### Scénario 25.3 — ⭐ Tous les serveurs coupés : un message, jamais une page blanche

Couper **les deux** serveurs, puis démarrer un salon.

**Attendu** : retour sur `/ext/bbb/rooms` avec un message explicite — « Aucun serveur de visioconférence n'est joignable actuellement. Réessayez dans un instant, puis prévenez l'administrateur. » La page est **complète** : liste des salons, entête, pied de page. Jamais une page blanche, jamais une erreur 500, jamais un navigateur qui tourne indéfiniment.

Trois messages doivent être **distincts**, et c'est le point de ce scénario — trois causes, trois remèdes :

| Situation | Message attendu |
|---|---|
| Aucun serveur déclaré, ou tous désactivés | « Aucun serveur de visioconférence **configuré** — prévenez l'administrateur. » |
| Des serveurs déclarés, aucun ne répond | « Aucun serveur de visioconférence n'est **joignable actuellement**. » |
| Le ou les serveurs refusent le secret | « … le **secret enregistré a été refusé** — prévenez l'administrateur. » |

Pour le troisième : modifier le secret d'un serveur sur `/ext/bbb/admin/servers` en une valeur fausse, désactiver l'autre, et démarrer. Le message doit **nommer le secret** — un serveur mal configuré ne se signale jamais tout seul, et « injoignable » enverrait l'administrateur vérifier son réseau pendant des heures.

### Scénario 25.4 — ⭐ Délégation Scalelite : le seuil, jamais la mesure

À défaut d'un vrai Scalelite, un **serveur BBB ordinaire déclaré avec un seuil** le simule exactement : c'est la même sémantique, et c'est tout l'intérêt du contrat repris de SE4.

Déclarer le second serveur avec un **seuil de délégation à 5**, puis :

1. charger le premier serveur avec **moins de 5 participants** ⇒ démarrer un salon : il part sur le **premier** ;
2. charger le premier serveur à **plus de 5 participants** ⇒ démarrer un autre salon : il part sur le **serveur à seuil**.

**Le contrôle qui porte la sémantique** : dans le journal Apache du serveur à seuil, **aucun `getMeetings` n'apparaît jamais**, dans aucun des deux cas. Il n'est **pas sondé** — sa valeur n'est pas une mesure de sa charge, c'est un **point de délégation** choisi par l'administrateur. C'est exactement ce que faisait `load_server_bbb()` dans SE4, et c'est délibéré.

**Corollaire à vérifier une fois** : couper le serveur à seuil et démarrer un salon dans le cas 2. Comme il n'est jamais sondé, il **est** choisi, la création échoue, et **la bascule le rattrape** — le salon s'ouvre sur l'autre. C'est la seule protection qu'un Scalelite éteint puisse avoir, et c'est pour cela que la bascule existe.

### Scénario 25.5 — Le budget de temps, sous serveur mono-processus

Pendant qu'un démarrage sonde un serveur **mort** (donc ~3 s d'attente, plus la création), depuis **un autre onglet de la MÊME personne** et depuis **une autre session** :

```bash
for i in $(seq 1 20); do curl -sS -o /dev/null -w '%{http_code} %{time_total}\n' \
  -b "se5_ext_bbb=<cookie>" 'https://<instance>/ext/bbb/rooms'; done
```

**Attendu** : toutes les requêtes rendent `200` **rapidement** (quelques dizaines de millisecondes). C'est ce que garantit le relâchement du verrou d'état avant la première sonde : le professeur dont le démarrage traîne peut **quand même** recharger sa liste de salons dans un autre onglet. Un blocage ici signifie que le verrou est retenu pendant les appels sortants — la régression exacte que la revue 57.2 avait fait corriger.

Chronométrer aussi le pire cas assumé : trois serveurs déclarés dont deux morts ⇒ le démarrage peut prendre ~20 à 25 s. C'est **long, et normal** : il est payé sur un acte explicite, une fois par ouverture de cours. Si le parc réel montre que c'est trop, la réponse est une borne de sonde plus courte ou un sondage concurrent — **pas** un cache.

### Scénario 25.6 — Un salon démarré GARDE son serveur

Démarrer un salon (il part, disons, sur le serveur B). Puis, **sans le refermer** :

1. charger fortement le serveur B et laisser A vide ;
2. faire **rejoindre un élève** ⇒ il entre sur **B**, pas sur A. La jonction suit `rooms.server_id`, elle ne re-choisit jamais ;
3. ouvrir l'onglet **Enregistrements** ⇒ les enregistrements du salon sont demandés à **B**.

**Pourquoi c'est voulu** : BigBlueButton ne déplace pas une conférence en cours. Rééquilibrer un salon vivant reviendrait à envoyer la moitié de la classe dans une salle vide. Seul le **prochain démarrage** peut changer de serveur — y compris pour un salon dont le serveur a été supprimé ou désactivé depuis (comportement de la 57.2, conservé).

### Scénario 25.7 — ⭐ Extinction traversée : les anciennes URL ne mènent plus nulle part

Depuis un navigateur connecté à SE5, visiter **une par une** :

```
/bbb/config.php   /bbb/create.php   /bbb/join.php   /bbb/launch.php   /bbb/records.php   /bbb/refresh.php
/visio            /visio/           /visio/?salon=prof.martin
```

**Attendu, pour chacune** : une redirection **302 vers l'accueil SE5** (`/`), où vit le lanceur. Jamais l'interface legacy, jamais une page d'erreur, jamais un formulaire BigBlueButton.

Puis la **forme préfixée par l'UAI**, celle que le legacy fabriquait lui-même :

```
/<uai>/bbb/create.php      /<uai>/visio/
```

**Attendu** : même redirection. Le préfixe est retiré avant l'évaluation des routes bloquées.

**Le contrôle le plus important de la section**, et il ne se joue que sur une instance où **`/var/www/sambaedu` existe encore** (Epic 38 non débranché) :

```bash
ls -la /var/www/sambaedu/bbb/     # le code SE4 d'origine est bien là
curl -sS -o /dev/null -w '%{http_code} %{redirect_url}\n' 'https://<instance>/bbb/create.php'
```

**Attendu** : `302` vers `/`. **Surtout pas `200`.** Un `200` signifierait que le repli vers le système de fichiers SE4 s'est rouvert — c'est-à-dire l'interface SE4 d'origine ressuscitée, avec ses mots de passe en champs cachés et sa vérification TLS désactivée. C'est précisément ce que les deux entrées de `blocked_legacy_routes` empêchent, et ce qu'aucun test hors VM ne peut constater.

### Scénario 25.8 — La modale de recherche ne propose plus « Visioconférences »

Sur n'importe quelle page de SE5, ouvrir la **recherche globale** (la modale du bandeau) et taper `visio`, puis `salon`, puis `bbb`.

**Attendu** : **aucune** entrée « Créer un salon », « Rejoindre un salon » ni « Enregistrements ». La catégorie entière a disparu.

Et la contre-épreuve, tout aussi importante : le **lanceur d'applications** propose bien la tuile « Visioconférences », et c'est **le seul chemin** vers la visio depuis SE5. Vérifier aussi, sur une instance où l'extension **n'est pas installée**, qu'aucun lien mort ne subsiste nulle part — c'est la raison pour laquelle la catégorie n'a **pas** été remplacée par un lien en dur vers `/ext/bbb` : la tuile, elle, est conditionnée à l'installation réelle.

### Scénario 25.9 — Non-régression de l'Epic 38 : l'observation du canal legacy

```bash
php artisan se4:extinction-report      # ou l'équivalent 38.6 de l'instance
```

**Attendu** : la commande tourne, et son verdict n'est pas perturbé. Deux points à consigner :

1. les répertoires `bbb` et `visio` **restent** dans l'inventaire du canal legacy de l'Epic 38 : cette liste décrit le **système de fichiers SE4**, pas la surface SE5. Les en retirer fausserait le verdict sur une instance non débranchée ;
2. en revanche, les requêtes `/bbb/*` et `/visio/*` **cessent d'apparaître** dans `legacy_catchall_logs` — une route **migrée** est redirigée avant journalisation (comportement existant du catchall). C'est correct et assumé : une route migrée n'est plus un accès au canal legacy, et le verdict GO/NO-GO ne doit plus la compter.

```sql
SELECT path, COUNT(*) FROM legacy_catchall_logs WHERE path LIKE 'bbb%' OR path LIKE 'visio%' GROUP BY path;
```

**Attendu** : aucune ligne **nouvelle** après les visites du scénario 25.7 (les lignes historiques, elles, restent — c'est un journal).

### Scénario 25.10 — Le module legacy a bien disparu du disque

```bash
ls /var/www/sambaedu-reload/legacy/modules/          # plus de répertoire bbb
ls /var/www/sambaedu-reload/legacy/stubs/ | grep -i bbb   # rien
php artisan test --testsuite=Architecture            # dont LegacyBbbLinkExtinctionTest
```

**Attendu** : le répertoire `legacy/modules/bbb/` et les deux stubs dédiés ont disparu ; le module `dhcp` et les stubs **partagés** (`functions.inc.php`, `sites.inc.php`, `ent.inc.php`, `cloud.inc.php`, `fonc_parc.inc.php`) sont **intacts** — le module dhcp les consomme, et les supprimer le casserait.

Contrôle croisé : `/dhcp/baux.php` répond toujours comme avant.

### Checklist rapide — Section 25

- [ ] **25.1 Répartition observée : le meeting apparaît dans la console du serveur le MOINS chargé ; sonde sur tous, création sur un seul ; `rooms.server_id` mémorise le bon**
- [ ] **25.2 Bascule : serveur coupé ⇒ le salon s'ouvre quand même sans action de l'utilisateur ; exactement DEUX `create` quand la panne survient après la sonde**
- [ ] **25.3 Tous coupés ⇒ message clair, page complète, jamais blanche ; les TROIS messages (non configuré / non joignable / secret refusé) sont distincts**
- [ ] **25.4 Délégation Scalelite : choisi quand les mesurés dépassent son seuil, et JAMAIS sondé (zéro `getMeetings` dans son journal) ; s'il est mort, la bascule le rattrape**
- [ ] 25.5 Fluidité même-session pendant un démarrage qui sonde un serveur mort ; pire cas ~25 s chronométré et assumé
- [ ] **25.6 Un salon démarré garde son serveur : jonction et enregistrements suivent `server_id`, quelle que soit la charge**
- [ ] **25.7 `/bbb/*.php` et `/visio` (avec ET sans préfixe UAI) ⇒ 302 vers l'accueil SE5 — y compris sur une instance où `/var/www/sambaedu/bbb/` existe encore**
- [ ] **25.8 La recherche globale ne propose plus « Visioconférences » ; la tuile du lanceur est le seul chemin ; aucun lien mort là où l'extension n'est pas installée**
- [ ] 25.9 `se4:extinction-report` tourne ; `bbb`/`visio` restent dans l'inventaire du FS SE4 ; plus de hits `/bbb/*` dans `legacy_catchall_logs`
- [ ] 25.10 `legacy/modules/bbb/` et les 2 stubs dédiés absents ; stubs partagés et module dhcp intacts (`/dhcp/baux.php` répond)

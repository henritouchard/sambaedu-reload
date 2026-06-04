# Story 21.2 : Fake AD/Samba en environnement e2e

Status: review

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

> **Deuxième story d'Epic 21** « Tests E2E Playwright sur Postgres préseedé ». Elle **double l'intégralité de la surface AD/Samba** quand l'app tourne en env `e2e` : toute écriture AD (création user/machine, changement de salle, mots de passe…) est **capturée par un fake** (in-memory + **journal inspectable**) sans jamais atteindre `samba-ad-dc` ; l'**auth login/mot de passe** des users seedés fonctionne **sans bind LDAP réel** ; et le **client AD réel ne peut pas être instancié** en e2e (exception structurelle au boot). Les env **non-e2e restent strictement inchangés**.
>
> **L'agent dev n'a PAS lu l'epic** : tout le contexte nécessaire est dans ce fichier. Lire « Contexte critique » + « Inventaire des points d'entrée AD/Samba » + « Points de décision » AVANT de coder.
>
> **⚠️ Un point de décision structurant (DP-AUTH) doit être validé par henri avant l'implémentation** — voir la section dédiée. La recommandation y est argumentée à partir du code réel.

---

## Contexte critique (à lire avant de coder)

### Dépendance : socle 21.1 (livré, status `review`)
Cette story s'appuie sur le socle e2e posé par la **Story 21.1** :
- Env Laravel `e2e` (instance dédiée sur la VM, vhost/port + `.env.e2e`), DB Postgres `sambaedu_e2e` recréée depuis la template `sambaedu_e2e_template`.
- Commandes artisan `e2e:reset` / `e2e:build-template`, garde-fou structurel `app/Console/Commands/Concerns/GuardsE2eDatabase.php` (allowlist `APP_ENV=e2e` + suffixe `_e2e`, détection config cache via `app()->getCachedConfigPath()`).
- Harnais Playwright hôte (`playwright.config.ts`, `tests/e2e/`, `global-setup.ts` qui reset via SSH).
- Doc : `docs/qa/e2e-setup.md` (runbook provisioning henri) + `docs/qa/domains/e2e-infra.md` (runbook domaine, **append-only**).
- **Patterns retenus en 21.1 (review 21.1)** : garde-fou structurel = **code (exception levée), pas config** ; détection config cache via `app()->getCachedConfigPath()` (jamais de chemin hardcodé) ; tests host = **chemin de refus seul** (jamais d'effet de bord réel).

### Topologie & branche `playwrite` (PIÈGE MAJEUR — identique à 21.1)
- Le code est édité sur l'hôte et **synchronisé vers la VM par inotify UNIQUEMENT pour `main`**. La branche courante est **`playwrite`** → **ce code n'est PAS sur la VM**. La provision/exécution réelle sur la VM est une action de henri ; l'agent dev **ne touche jamais la VM/SSH**.
- `php` et `vendor/` sont **absents de l'hôte** → **`php -l` et PHPUnit NON exécutables localement** (cf. 21.1). L'agent relit la syntaxe manuellement ; **henri rejoue la suite PHPUnit sur la VM**.

### Interdits stricts pour l'agent dev
- ❌ **Aucune commande SSH vers la VM (`/vm`) ni lab1.** Travail local : code + revue de syntaxe.
- ❌ **Ne JAMAIS modifier le flux AD/auth réel des env non-e2e.** Auth machine = **iso-legacy** (AD + SMB ; mémoire projet `feedback_auth_iso_legacy`). Le fake ne s'active QUE si `APP_ENV === 'e2e'`. Zéro impact dev/prod = AC bloquant.
- ❌ Ne pas seeder d'utilisateurs ici : le **jeu de référence** (établissement, users par rôle avec identifiants connus) est la **Story 21.3**. 21.2 **pose le mécanisme d'auth fake** (résolution + validation du mot de passe sans LDAP) ; 21.3 fournira les données.
- ❌ Ne pas modifier `phpunit.xml` (canal SQLite `:memory:` séparé, non-régression).

### Précédent à RÉUTILISER (anti-réinvention)
- **Le binding swappable par interface existe déjà** : `app/Providers/AppServiceProvider.php:108-112` bind `AuthGuardInterface::class → SambaEduAuthGuard::class` avec le commentaire « *swap Phase 2 : remplacer par KeycloakAuthGuard* ». Il existe déjà `KeycloakAuthGuard` (impl alternative inutilisée). **C'est le pattern d'injection à suivre** pour swapper les composants AD/Samba en e2e (bind d'une fake impl derrière une interface, conditionné à `APP_ENV=e2e`).
- **Le pattern « skip en `testing` » est déjà partout** : `GpoServiceProvider`, `WpkgDeploymentServiceProvider`, `IpxeServiceProvider`, `AuthV1ServiceProvider`, `AppServiceProvider::boot` (observer Workstation) sautent leurs effets de bord AD/Samba en `app()->environment('testing')`. **`e2e` n'est PAS `testing`** → en e2e ces providers bootent comme en prod et **taperaient le vrai AD** : c'est exactement ce que cette story neutralise. Ne pas confondre `testing` (PHPUnit SQLite) et `e2e` (instance réelle + fake AD).
- **Garde-fou structurel** : calquer l'esprit de `GuardsE2eDatabase` (exception code, pas config) pour le garde « le client AD réel ne peut pas être instancié en e2e ».
- **Injection mockable déjà pratiquée** : `CommandRunner::class → RealCommandRunner` (Story 6.1, bind dans `AppServiceProvider`, mocké par `FakeCommandRunner` en tests). Même philosophie ici.

---

## Inventaire EXHAUSTIF des points d'entrée AD/Samba (cœur de la recon)

> SE5 parle à AD/Samba par **TROIS canaux physiques distincts**. Le fake doit couvrir les trois (ou les neutraliser structurellement). C'est l'inventaire de référence pour dimensionner le scope.

### Canal A — LdapRecord (`LdapRecord\Container`)
Lecture + certaines écritures LDAP via le SDK LdapRecord. La connexion est enregistrée par `app/Providers/LdapRecordServiceProvider.php:boot()` (`Container::addConnection($connection, 'default')` + `setDefaultConnection`). **Tous les modèles `LdapRecord` résolvent cette connexion par défaut.**

Consommateurs (lecture/écriture) — `grep "use LdapRecord"` :
- `app/LdapModels/*` : `LdapUser`, `MachineModel`, `SambaEduGroup`, `DeviceGroupModel`, `DeviceGroupTagModel`, `OrganizationalUnitModel`, `LdapRightGroup`. `LdapUser::findByLogin()` (`where('cn',…)->first()`) → **résolution d'identité pour l'auth** (`UserRepository::findLdapModelByLogin` → `AuthenticationService::validatePassword`).
- `app/Repositories/` : `GroupRepository`, `WorkstationGroupRepository` (+ `UserRepository` via les LdapModels).
- `app/Services/AdSync/AdSyncService.php` : **écriture LDAP directe**, va jusqu'à `$machineAd->getConnection()->getLdapConnection()` puis `@ldap_rename(...)` (cf. `moveMachineToSalle` L303-360) — **pertinent 21.6** (swap de salle). Aussi `AppProfileAdSyncService`.
- `app/Services/UserService.php` (`$ldapUser->delete()` — suppression LDAP), `app/Services/WorkstationService.php`, `app/Services/Permissions/RightsMigrationService.php`, `app/Services/Ldap/EstablishmentWorkstationScope.php`, `app/Ldap/AdMachineManager.php` (lecture via repo).
- `app/Console/Commands/AskAd.php`, `TestLdapConnection.php`.

### Canal B — php-ldap brut (`ldap_connect`/`ldap_bind`/`ldap_*`)
Appels directs à l'extension php-ldap, **hors SDK** :
- **`app/Services/AuthenticationService.php`** — ⭐ **LE point d'auth** : `attemptBind($userDn, $password)` fait `ldap_connect()` + `@ldap_bind()` (L419-464). C'est **la validation du mot de passe** (appelée par `validatePassword` ← `LdapUserProvider::validateCredentials` ← guard `web`). Le fake auth doit court-circuiter **ce bind** sans annuaire réel.
- `app/Ldap/AdUserManager.php`, `app/Services/ImportExportService.php`, `app/Doctor/Checks/Ad/LdapBindCheck.php`, `app/Constants/Ldap/LdapScope.php`.
- ⚠️ Note : `AdSyncService` (canal A) **descend aussi au niveau brut** via `getLdapConnection()` + `ldap_rename` — frontière A/B poreuse.

### Canal C — `samba-tool` CLI (via `SambaToolRunner`)
Écritures AD persistantes par invocation du binaire `samba-tool` (et apparentés) en sous-process. **Point d'exécution unique autorisé** : `app/Gpo/Support/SambaToolRunner.php` (utilise `Illuminate\Support\Facades\Process` ; garde-fou archi `tests/Architecture/GpoNamespaceTest.php` interdit `Process`/`exec` ailleurs dans `App\Gpo`). Possède **déjà un mode `dryRun`** (retourne la commande sans l'exécuter).

Consommateurs de `SambaToolRunner` :
- `app/Ldap/AdUserManager.php` (`samba-tool user create/setpassword/list`) — **création/maj de comptes AD** (pertinent 21.5).
- `app/Ldap/AdMachineManager.php` (`samba-tool computer …`, hardware, OS) — **machines** (pertinent 21.6).
- `app/Gpo/Services/*` (`GpoService`, `WpkgGpoSynchronizer`, `NetworkScriptGenerator`), `app/Services/GpoSyncService.php`, `app/Gpo/Support/CachedGpoLookups.php` — GPO/WPKG (**hors scope parcours e2e**, mais sur la même surface).
- `app/Jobs/AdSync/WorkstationAdSyncJob.php` — job de sync machine.
- Hors `SambaToolRunner` mais même famille `Process`/exec : `app/Services/Parc/MachinePowerService.php`, `app/Jobs/DispatchMachinePowerActionJob.php`, `app/Services/SE4/PowerShellRemoteService.php`, `app/Services/Print/PrintDriverService.php` — **hors scope** (pas exercés par les 4 parcours), à neutraliser seulement si un parcours les traverse.

### Orchestrateurs & dispatch asynchrone (chaîne déclenchée par les parcours UI)
- **Observers** (`app/Observers/`) enregistrés dans `AppServiceProvider::boot()` : `WorkstationObserver` (dispatch `WorkstationAdSyncJob` sur create/update/delete — **N.B. enregistré UNIQUEMENT hors `testing`** ; en `e2e` il EST actif), `WorkstationGroupObserver`, `UserGroupObserver`, `AppProfileObserver`, `ShortcutObserver`, `UserGroupUserPivotObserver`.
- **Jobs** `app/Jobs/AdSync/` : `WorkstationAdSyncJob`, `UserGroupAdSyncJob`, `AppProfileAdSyncJob`, `WorkstationGroupAdSyncJob`, `WorkstationMembershipAdSyncJob`. En e2e la queue est probablement `sync` → ces jobs s'exécutent **inline** et toucheraient le vrai AD via canaux A/C.
- **Services orchestrateurs** : `AdSyncService` (`createWorkstationGroup`, `moveMachineToSalle`, `renameWorkstationGroup`…), `UserGroupAdSyncService`, `AppProfileAdSyncService`, `WorkstationGroupLdapService`, `WorkstationGroupService` (le « swap service » du pivot memberships story 4-11).

### Bindings / injection (où brancher le fake)
- `app/Providers/AppServiceProvider.php:register()` — singletons + `bind(AuthGuardInterface → SambaEduAuthGuard)` (**point d'injection canonique**), `bind(CommandRunner → RealCommandRunner)`.
- `app/Providers/LdapRecordServiceProvider.php:boot()` — **enregistre la connexion LdapRecord** (canal A). **Point d'injection du fake annuaire / du garde-fou anti-instanciation.**
- `config/auth.php` — guard `web` driver `session` provider `sambaedu` → `LdapUserProvider` (driver custom `sambaedu`, enregistré ailleurs ; `retrieveByCredentials`/`validateCredentials` délèguent à `AuthenticationService`).
- `config/ldap.php` — config LdapRecord (hosts/base_dn remplis dynamiquement au boot).
- `SambaToolRunner` : **pas de binding explicite** (auto-wiring par le container) ; constructeur `(SambaEduConfig $config)`. Possède un mode `dryRun` activable.

### Chemin d'auth complet (à doubler sans bind réel)
```
POST login → AuthController → Auth::attempt(['login'=>…, 'password'=>…])
  → LdapUserProvider::retrieveByCredentials()  → resolveUser() → LdapUser::findByLogin()  [canal A]
  → LdapUserProvider::validateCredentials()    → AuthenticationService::authenticate()
        → validatePassword() → findLdapModelByLogin() [canal A] + attemptBind() [canal B: ldap_bind RÉEL]
  → session $_SESSION['login'] + Auth::login(User Eloquent)
Requêtes suivantes : middleware sambaedu.auth → SambaEduAuthGuard::handle()
  → UserRepository::findByLogin() [canal A] (vérifie l'existence LDAP à chaque requête)
```
**Conséquence pour le fake auth** : il faut neutraliser (a) la **résolution** d'identité (`findByLogin`/`findLdapModelByLogin`, canal A) ET (b) la **validation du mot de passe** (`attemptBind`, canal B) ET (c) la **revérification par requête** dans le guard (`findByLogin`, canal A). Sinon l'utilisateur seedé est soit introuvable, soit déconnecté à la requête suivante.

---

## Scope strict & frontières

### IN-SCOPE (ce que la story livre)

1. **Garde-fou structurel anti-AD-réel en e2e** : en `APP_ENV === 'e2e'`, toute tentative d'**instancier/utiliser le vrai client AD** lève une **exception explicite** (« AD réel interdit en e2e — utiliser le fake »). Concrètement : au boot du container (ex. `LdapRecordServiceProvider`), **ne PAS enregistrer la connexion LdapRecord réelle** en e2e (enregistrer la fake, ou aucune + exception au 1er accès), et garantir que `SambaToolRunner` n'exécute aucun binaire réel (fake/dry-run forcé). Garde-fou = **code (exception), pas config** (pattern 21.1 / review 21.1). C'est l'invariant de sécurité testé.
2. **Fake annuaire / capture des écritures AD** : un composant fake **in-memory** qui (a) répond aux **lectures** nécessaires aux parcours (résolution user/machine seedés depuis Postgres ou un store en mémoire), (b) **capture** les écritures (création user/machine, changement de salle, setpassword, membership…) dans un **journal inspectable** sans jamais sortir vers `samba-ad-dc`, (c) retourne des **réponses cohérentes** : **GUID factices STABLES** (déterministes par clé d'objet — ex. hash du samAccountName), statuts de succès, pour que les parcours aboutissent.
3. **Journal des écritures AD inspectable** : format et canal d'inspection **à trancher (DP-LOG)** — voir Points de décision. Doit être interrogeable par un test Playwright (fichier ? table Postgres ? endpoint e2e ?) ET par un test host (assertion in-memory). Chaque entrée : type d'action (`user.create`, `machine.move`, `setpassword`…), cible, payload pertinent, timestamp, GUID factice attribué.
4. **Auth fake (login/mot de passe sans bind LDAP)** : les utilisateurs **seedés en Postgres** s'authentifient via le fake — **stratégie à trancher (DP-AUTH)** : doublure derrière l'abstraction LdapRecord/`AuthenticationService` **vs** guard e2e dédié sur `AuthGuardInterface`. Le mécanisme doit couvrir **les 3 sous-chemins** identifiés (résolution, validation mdp, revérif par requête) pour qu'un user seedé reste connecté entre requêtes. **Les données users arrivent en 21.3** ; 21.2 pose le mécanisme et le valide avec un user de test minimal (host) ou un fixture jetable.
5. **Neutralisation des écritures asynchrones** : s'assurer que les **jobs/observers AD** (`WorkstationAdSyncJob` & co, actifs en e2e car non-`testing`) passent par le fake (capture) au lieu du vrai AD — soit parce qu'ils résolvent les canaux A/C doublés, soit par une garde dédiée e2e. **Vérifier la chaîne complète** observer → job → service → canal.
6. **Activation strictement conditionnée à e2e** : tout le doublage est **inerte hors `APP_ENV=e2e`**. **Zéro modification de comportement** en dev/prod/`testing` (AC bloquant). Le binding fake n'est enregistré QUE si `APP_ENV=e2e`.
7. **Tests host (SQLite :memory:)** : prouver (a) que le garde-fou anti-AD-réel **lève** en e2e simulé, (b) que le fake **capture** une écriture sans I/O réel, (c) que l'auth fake **valide** un user seedé sans bind, (d) **non-régression** : hors e2e, les bindings réels restent en place (le fake n'est pas enregistré). Aucun appel réseau/process réel dans les tests.
8. **Doc** : enrichir **`docs/qa/domains/e2e-infra.md`** (append-only) avec le fonctionnement du fake AD (composants, journal, comment un test l'interroge, comment activer/désactiver). **Ne PAS créer de nouveau doc QA par story.**

### HORS-SCOPE (ne pas faire)
- ❌ Le **jeu de données seedé** (établissement, users par rôle, identifiants connus, salles, machines) = **Story 21.3**. 21.2 pose le mécanisme d'auth/capture, pas les données.
- ❌ Les **4 parcours fonctionnels** (21.4-21.7). 21.2 rend leurs écritures AD inoffensives, mais n'écrit aucun `*.spec.ts` de parcours.
- ❌ Le **stub IdP fédéré** (jetons signés controlHub) = **Story 21.7** (autre surface, JWT, pas LDAP/samba-tool).
- ❌ Toute **validation d'intégration AD réelle** (le but est l'inverse : ne jamais toucher le vrai AD).
- ❌ Modifier le **flux AD/auth des env non-e2e**, `phpunit.xml`, ou les garde-fous existants (`DbSeedCommand`, `GuardsE2eDatabase`).
- ❌ Doubler les surfaces **non exercées par les parcours** (GPO/WPKG/iPXE/MachinePower/Print/PowerShell) au-delà du garde-fou anti-AD-réel global — y revenir seulement si un parcours 21.4-21.7 les traverse.
- ❌ **Exécution VM/SSH** par l'agent (provision, run réel) → henri.

---

## Points de décision — ✅ TRANCHÉS par henri (2026-06-04, pré-dev)

> **Henri a validé les 3 recommandations le 2026-06-04** : DP-AUTH = Option A (fake derrière l'abstraction LdapRecord/`AuthenticationService`, interface `AdCredentialValidator` injectable), DP-LOG = Option 1 (table Postgres `e2e_ad_writes` + endpoint e2e read-only gated), DP-SCOPE = A+B+C-core doublés fonctionnellement, le reste couvert par le seul garde-fou anti-AD-réel. L'agent dev applique ces choix sans re-débattre et les consigne dans le Dev Agent Record. Le détail des options ci-dessous est conservé pour mémoire.

### DP-AUTH (point n°3 de l'epic) — Stratégie de doublure de l'auth
**Comment doubler l'auth en e2e :**

- **Option A — Fake derrière l'abstraction LdapRecord / `AuthenticationService`** : on remplace, en e2e, (i) la **connexion LdapRecord** (canal A) par un annuaire fake servant les users seedés, et (ii) le **bind** (`AuthenticationService::attemptBind`, canal B) par une validation fake (ex. mot de passe seedé connu). Le reste du flux (`LdapUserProvider`, `SambaEduAuthGuard`, sessions, Spatie) **reste inchangé**.
  - ✅ **Le plus iso-prod** : le vrai guard, le vrai provider, le vrai cycle de session sont exercés par les e2e → on teste le code de prod, on ne le contourne pas. ✅ Couvre nativement la **revérif par requête** du guard (canal A doublé). ✅ Aligné avec « doubler la surface AD », pas « bypasser l'auth ».
  - ⚠️ Doit intercepter **deux canaux** (A : résolution `findByLogin`/`findLdapModelByLogin` ; B : `attemptBind`). `attemptBind` est `private` dans `AuthenticationService` → nécessite soit d'extraire un point d'injection (ex. une interface `AdCredentialValidator` injectable, fake en e2e), soit de fournir une fake connexion LdapRecord + un fake bind.
- **Option B — Guard e2e dédié branché sur `AuthGuardInterface` (story 1.4)** : un `E2eAuthGuard implements AuthGuardInterface`, bindé à la place de `SambaEduAuthGuard` **uniquement en e2e**, qui authentifie directement depuis Postgres (session + `Auth::login` du User Eloquent) sans aucun canal LDAP. Pattern **déjà cablé** (`AppServiceProvider:108` swap commenté « Phase 2 »).
  - ✅ **Simple et sûr** : un seul point de swap, zéro LDAP, pattern existant (`KeycloakAuthGuard`). ✅ Pas besoin de toucher `AuthenticationService`/`LdapUserProvider`.
  - ⚠️ **Contourne** `LdapUserProvider`/`AuthenticationService` → les e2e ne testent **pas** le vrai chemin d'auth (moins iso-prod). ⚠️ `AuthGuardInterface::handle` ne gère que la **revérif par requête** ; le **POST login initial** (`Auth::attempt` → `LdapUserProvider`) reste à doubler séparément → on retombe partiellement sur l'Option A pour le login.

- **🟢 Recommandation : Option A (fake derrière l'abstraction), via un point d'injection mince.** Argument : le flux d'auth réel (provider → guard → session → Spatie) est précisément ce que les parcours 21.4 doivent protéger contre les régressions — le bypasser (B) crée un angle mort. Concrètement : extraire la validation de credentials en une **interface injectable** (ex. `App\Contracts\Ad\AdCredentialValidator` avec `Real` = `attemptBind` actuel et `FakeE2e` = compare au mot de passe seedé), bindée fake **seulement en e2e** ; + fake connexion LdapRecord (ou résolution Postgres) pour `findByLogin`. Le pattern de bind par interface conditionné à l'env est **déjà la convention du repo** (`AuthGuardInterface`, `CommandRunner`). *(Si l'extraction d'`attemptBind` s'avère trop invasive, repli Option B pour le login + capture AD côté A/C — à acter avec henri.)*

### DP-LOG — Format & canal du journal inspectable
**Comment les écritures AD capturées sont exposées aux tests :**

- **Option 1 — Table Postgres dédiée** (ex. `e2e_ad_writes`) : le fake INSERT chaque écriture. Le test Playwright l'interroge via un **endpoint e2e read-only** (`GET /e2e/ad-writes`, actif uniquement si `APP_ENV=e2e`) ou via SSH ; le test host via Eloquent/DB.
  - ✅ Inspectable des deux mondes (host + Playwright). ✅ Reset automatique avec la DB (template). ✅ Persistant entre process (le job async écrit, le test lit).
  - ⚠️ Une migration e2e-only à gérer (créer la table en `e2e` sans polluer dev/prod — la template e2e est buildée par `migrate:fresh` ; soit migration conditionnée, soit table créée par le seed e2e).
- **Option 2 — Fichier journal** (ex. `storage/e2e/ad-writes.jsonl`) : le fake append du JSONL. Playwright lit le fichier (via SSH/scp) ; host lit le fichier.
  - ✅ Zéro schéma. ⚠️ Inspection Playwright = SSH/FS (couplage). ⚠️ Concurrence/reset à gérer à la main.
- **Option 3 — Store in-memory + endpoint e2e** : journal en mémoire (singleton), exposé par `GET /e2e/ad-writes`.
  - ✅ Pas de schéma. ⚠️ **Ne survit pas entre process** : un job `sync` partage le process de la requête (OK), mais un worker queue séparé non → fragile selon la config queue e2e.

- **🟢 Recommandation : Option 1 (table Postgres) + endpoint e2e read-only gated `APP_ENV=e2e`.** Inspectable uniformément par Playwright (HTTP) et par les tests host (DB), survit au cross-process (jobs), et **se reset gratuitement** avec la template (cohérent avec l'architecture 21.1). L'endpoint doit être **non enregistré hors e2e** (double garde, comme DP-1 option B de 21.1). *(à confirmer avec henri ; si on veut zéro surface HTTP, repli Option 2 + lecture SSH.)*

### DP-SCOPE — Périmètre exact des canaux à doubler
- **Recommandation** : doubler **A (LdapRecord)**, **B (bind auth)** et **C (samba-tool via `SambaToolRunner`)**, car les parcours 21.5 (users) et 21.6 (machines/salles) traversent les trois. Les surfaces GPO/WPKG/iPXE/Power/Print (canal C élargi + `Process` divers) sont **couvertes par le seul garde-fou anti-AD-réel global** (exception si invoquées), **pas** par un fake fonctionnel, tant qu'aucun parcours ne les exerce. **À valider** : confirme-t-on ce périmètre minimal (A+B+C-core) pour 21.2 ?

---

## Decisions (héritées / cadrage — ne pas re-débattre)

- **D-1 — Le fake ne s'active QUE si `APP_ENV='e2e'`.** Hérité de l'epic (« zéro impact dev/prod ») et de la doctrine auth iso-legacy. Tout binding fake est conditionné à l'env e2e ; `testing` (PHPUnit) et `local`/`production` n'enregistrent jamais le fake.
- **D-2 — Garde-fou = code (exception), pas config.** Pattern 21.1 / review 21.1 : impossible d'atteindre le vrai AD en e2e, même en cas de mécompréhension de config. Invariant testé (host).
- **D-3 — GUID factices déterministes & stables.** Même clé d'objet → même GUID factice (ex. dérivé du samAccountName/name). Permet aux parcours de réutiliser un GUID entre étapes (cf. `ad_guid` persisté en Postgres).
- **D-4 — Données seedées = 21.3.** 21.2 pose le mécanisme d'auth/capture ; les users/établissement/salles/machines de référence arrivent en 21.3. Valider 21.2 avec un fixture jetable, pas un seed de prod.
- **D-5 — Doc append-only dans `docs/qa/domains/e2e-infra.md`.** Pas de nouveau fichier QA par story.

---

## Story

As a **développeur**,
I want **que toutes les interactions AD/Samba (écritures ET authentification) passent par un driver factice quand l'app tourne en env `e2e` — capture in-memory/journal inspectable, GUID factices stables, auth des users seedés sans bind LDAP réel — et que le client AD réel ne puisse PAS être instancié en e2e**,
so that **les tests e2e soient déterministes, rapides et n'altèrent JAMAIS le vrai samba-ad-dc, sans aucun impact sur les environnements dev/prod**.

## Acceptance Criteria

1. **Given** l'instance e2e active (`APP_ENV=e2e`), **When** un parcours déclenche une écriture AD (création d'utilisateur, de machine, changement de salle, setpassword…), **Then** l'écriture est **capturée par le fake** (in-memory + journal inspectable) et **rien n'atteint `samba-ad-dc`** (aucun bind/process/I/O réel).
2. **Given** le fake actif, **When** un parcours écrit puis relit un objet AD, **Then** le fake retourne des **réponses cohérentes** : **GUID factices stables** (déterministes par clé d'objet) et **statuts de succès** permettant au parcours d'aboutir.
3. **Given** `APP_ENV=e2e`, **When** un chemin de code tente d'**instancier/utiliser le client AD réel** (connexion LdapRecord réelle, `attemptBind`, `samba-tool` réel), **Then** une **exception explicite** est levée (garde-fou **structurel = code**, pas config) — le vrai AD est inatteignable en e2e.
4. **Given** des utilisateurs seedés en Postgres, **When** ils s'authentifient par login/mot de passe en e2e, **Then** l'auth **réussit via le fake sans aucun bind LDAP réel**, et l'utilisateur **reste authentifié entre requêtes** (la revérif par-requête du guard passe par le fake, pas par le vrai LDAP).
5. **Given** les environnements **non-e2e** (dev, prod, `testing`), **Then** ils sont **strictement inchangés** : le fake n'est **pas enregistré**, le flux AD/auth réel et `phpunit.xml` sont intacts (zéro impact).
6. **Given** un test, **Then** le **journal des écritures AD est inspectable** par le canal retenu (DP-LOG) : un test Playwright peut vérifier qu'une écriture attendue a été capturée, et un test host peut l'asserter in-process.
7. **Given** la chaîne asynchrone (observers → jobs AD, actifs en e2e car non-`testing`), **When** un parcours la déclenche, **Then** les jobs AD passent par le fake (capture) sans toucher le vrai AD.
8. **Given** les tests host (SQLite :memory:), **Then** ils prouvent : (a) le garde-fou anti-AD-réel lève en e2e simulé ; (b) le fake capture une écriture sans I/O réel ; (c) l'auth fake valide un user sans bind ; (d) hors e2e, les bindings réels restent en place — **sans aucun appel réseau/process réel**.
9. **Given** le canal PHPUnit existant, **Then** il est **inchangé** (`phpunit.xml` non modifié, suite verte) et `docs/qa/domains/e2e-infra.md` est enrichi (append-only) du fonctionnement du fake AD.

## Tasks / Subtasks

- [x] **T0 — Recon + trancher DP-AUTH / DP-LOG / DP-SCOPE** (no code) : confirmer l'inventaire des 3 canaux (A LdapRecord via `LdapRecordServiceProvider`, B `AuthenticationService::attemptBind`, C `SambaToolRunner`), le pattern de bind par interface (`AppServiceProvider:108` `AuthGuardInterface`, `CommandRunner`), la garde « non-`testing` » qui laisse les observers/providers actifs en e2e, et le mode `dryRun` de `SambaToolRunner`. **Obtenir la validation henri sur DP-AUTH (reco Option A), DP-LOG (reco table + endpoint e2e), DP-SCOPE (A+B+C-core).** Consigner dans le Dev Agent Record. (AC: tous)
- [x] **T1 — Garde-fou structurel anti-AD-réel en e2e** : en `APP_ENV=e2e`, empêcher l'enregistrement/usage de la connexion LdapRecord réelle (`LdapRecordServiceProvider`) et l'exécution réelle de `samba-tool` (`SambaToolRunner` → fake/dry-run forcé) ; toute tentative d'usage du vrai client AD lève une **exception explicite** (code, calqué esprit `GuardsE2eDatabase`). Inerte hors e2e. (AC: 3,5)
- [x] **T2 — Fake annuaire + capture des écritures** : composant fake in-memory répondant aux lectures nécessaires (résolution user/machine seedés) et capturant les écritures (user.create, machine.create/move, setpassword, membership…) avec **GUID factices stables** (D-3) et statuts de succès. Bindé **uniquement en e2e** au point d'injection retenu. (AC: 1,2)
- [x] **T3 — Journal inspectable (DP-LOG)** : implémenter le journal selon l'option validée (reco : table Postgres `e2e_ad_writes` + endpoint e2e read-only `GET /e2e/ad-writes` gated `APP_ENV=e2e`, **non enregistré hors e2e**). Chaque entrée : action_type, cible, payload, GUID factice, timestamp. Reset avec la template. (AC: 1,6)
- [x] **T4 — Auth fake (DP-AUTH)** : selon l'option validée (reco A) — extraire un point d'injection pour la validation de credentials (ex. interface `AdCredentialValidator` : `Real` = `attemptBind` actuel, `FakeE2e` = compare au mdp seedé) bindé fake en e2e ; + doubler la **résolution** (`findByLogin`/`findLdapModelByLogin`, canal A) pour servir les users Postgres ; + garantir que la **revérif par requête** du guard (`UserRepository::findByLogin`) passe par le fake. Aucun bind réel. (AC: 4)
- [x] **T5 — Neutraliser la chaîne async** : vérifier que les jobs/observers AD (`WorkstationAdSyncJob` & co — actifs en e2e car non-`testing`) résolvent les canaux doublés et capturent au lieu d'écrire réellement ; ajouter une garde e2e dédiée si un chemin échappe au fake. (AC: 7)
- [x] **T6 — Conditionnement strict e2e** : tous les bindings fake conditionnés à `APP_ENV=e2e` ; audit que dev/prod/`testing` n'enregistrent jamais le fake (binding réel inchangé). (AC: 5)
- [x] **T7 — Tests host** (`tests/Feature/E2e/` ou `tests/Unit/E2e/`) : (a) garde-fou anti-AD-réel lève en e2e simulé ; (b) le fake capture une écriture sans I/O réel ; (c) auth fake valide un user seedé sans bind ; (d) hors e2e, bindings réels en place. Aucun appel réseau/process réel (mock/simulation env). (AC: 8)
- [x] **T8 — Doc append-only** : enrichir `docs/qa/domains/e2e-infra.md` (composants du fake, journal & canal d'inspection, activation/désactivation, comment un test Playwright l'interroge). Référence depuis `docs/qa/README.md` si pertinent. `phpunit.xml` intact. (AC: 9)
- [x] **T9 — Validation host** : revue syntaxe PHP des fichiers créés/modifiés (⚠️ `php -l`/PHPUnit **non exécutables sur l'hôte** — ni `php` ni `vendor`, cf. 21.1) ; confirmer `phpunit.xml` non modifié (AC9) ; **suite PHPUnit à rejouer sur la VM par henri**. Aucune commande VM/SSH. (AC: 5,8,9)

## Dev Notes

- **Réutilisation > réécriture** : le **swap par interface conditionné à l'env** est déjà la convention (`AppServiceProvider:108` `AuthGuardInterface → SambaEduAuthGuard` avec commentaire « Phase 2 » ; `CommandRunner → RealCommandRunner` Story 6.1 mocké par `FakeCommandRunner`). **Calquer ce pattern** pour brancher les fakes AD/auth — ne pas inventer un mécanisme parallèle.
- **`testing` ≠ `e2e`** (piège). Les providers `Gpo/Wpkg/Ipxe/AuthV1ServiceProvider` et `AppServiceProvider::boot` (observer Workstation) sautent leurs effets de bord **uniquement en `testing`**. En `e2e` ils bootent comme en prod → ils **taperaient le vrai AD**. C'est exactement la surface que 21.2 neutralise. Ne pas réutiliser bêtement la garde `environment('testing')` : il faut une garde `environment('e2e')` distincte (fake actif), tout en laissant `testing` se comporter comme avant.
- **Trois canaux à couvrir** (cf. inventaire) : A LdapRecord (`Container` enregistré dans `LdapRecordServiceProvider`), B `ldap_bind` brut (`AuthenticationService::attemptBind`, **private**), C `samba-tool` (`SambaToolRunner`, possède déjà `dryRun`). `AdSyncService::moveMachineToSalle` descend de A à B (`getLdapConnection()` + `ldap_rename`) — frontière poreuse, à doubler au niveau service plutôt qu'au niveau `ldap_rename`.
- **`SambaToolRunner` a déjà un `dryRun`** : l'activer/forcer en e2e est probablement la voie la plus simple pour le canal C, mais `dryRun` **retourne un faux succès sans capturer** — il faudra brancher la capture (journal) au-dessus, ou fournir un `FakeSambaToolRunner` qui capture. Vérifier que `dryRun` ne casse pas les parsers de sortie des consommateurs (`AdUserManager`, `AdMachineManager`).
- **Auth — 3 sous-chemins à neutraliser** (sinon login KO ou déconnexion à la requête suivante) : (a) résolution `LdapUser::findByLogin` (provider, canal A) ; (b) `attemptBind` (validation mdp, canal B) ; (c) `UserRepository::findByLogin` dans `SambaEduAuthGuard::handle` (revérif par requête, canal A). Le fake annuaire (Option A) couvre (a) et (c) d'un coup s'il sert la connexion LdapRecord.
- **GUID factices stables** : les objets persistent `ad_guid` en Postgres (résolution par GUID stable — mémoire `project_ad_sync_resolve_by_guid`). Le fake doit retourner un GUID **déterministe** (ex. `Guid` dérivé d'un hash du name) pour que rename/update/delete retrouvent l'objet.
- **Endpoint e2e (si DP-LOG option 1/3)** : enregistrer la route **uniquement si `APP_ENV=e2e`** (route non déclarée sinon — double garde, comme 21.1 DP-1 option B). Read-only.
- **Sécurité (cœur de la story)** : l'invariant le plus important = **AC3** (le vrai AD inatteignable en e2e). Une fuite = un test e2e qui crée un vrai compte/une vraie machine dans `samba-ad-dc`. Garde-fou **code testé** (T7a), pas un commentaire.
- **Non-régression (AC5/AC9)** : ne pas modifier `phpunit.xml`, ne pas toucher le flux AD/auth des env non-e2e, ne pas élargir les gardes existantes. Le binding fake doit être un **ajout conditionnel**, jamais une modification du chemin réel.
- **Données users = 21.3** : valider l'auth fake avec un fixture host jetable (ex. un `User` Eloquent + une entrée annuaire fake en mémoire), pas avec un seed de référence (qui n'existe pas encore).

### Project Structure Notes

- **Contrats/abstractions** : si Option A retenue, une interface type `App\Contracts\Ad\AdCredentialValidator` (ou voisin) — noter qu'il n'existe **pas** de dossier `app/Contracts/` aujourd'hui (`AuthGuardInterface` vit dans `app/Http/Middleware/Auth/`). Choisir un emplacement cohérent (ex. `app/Contracts/Ad/` nouveau, ou `app/Ldap/Contracts/`), à documenter.
- **Fakes** : `app/Ldap/Fakes/` ou `app/Services/AdSync/Fakes/` (ex. `FakeAdDirectory`, `FakeSambaToolRunner`, `FakeAdCredentialValidator`). Le journal : modèle Eloquent `App\Models\E2e\AdWriteLog` + migration (si DP-LOG option 1).
- **Bindings** : `app/Providers/AppServiceProvider::register()` (conditionnels `if ($this->app->environment('e2e'))`) et/ou ajustement de `LdapRecordServiceProvider::boot()` (ne pas enregistrer la connexion réelle en e2e).
- **Endpoint e2e** (si retenu) : `routes/web.php` (ou un fichier de routes e2e) gated `APP_ENV=e2e` ; contrôleur léger `app/Http/Controllers/E2e/`.
- **Tests** : `tests/Feature/E2e/` ou `tests/Unit/E2e/` (cohérent avec `E2eResetGuardTest` de 21.1).
- **Doc** : `docs/qa/domains/e2e-infra.md` (append-only).

### References

- [Source: _bmad-output/planning-artifacts/epics.md#Epic 21 : Tests E2E Playwright sur Postgres préseedé] (Story 21.2, ACs, point de décision n°3 DP-AUTH, contraintes transverses, prérequis & références)
- [Source: _bmad-output/implementation-artifacts/21-1-socle-playwright-environnement-e2e.md] (socle e2e : env, commandes `e2e:reset`/`e2e:build-template`, garde-fou `GuardsE2eDatabase`, `.env.e2e.example`, doc)
- [Source: _bmad-output/codeReviews/21-1.md] (patterns retenus : `getCachedConfigPath()`, garde-fou code-pas-config, tests = chemin de refus seul)
- [Source: app/Http/Middleware/Auth/AuthGuardInterface.php + SambaEduAuthGuard.php + KeycloakAuthGuard.php] (interface guard swappable ; revérif par requête `UserRepository::findByLogin` ; pattern Phase 2)
- [Source: app/Providers/AppServiceProvider.php:108-119] (bind `AuthGuardInterface → SambaEduAuthGuard`, `CommandRunner → RealCommandRunner` — point d'injection canonique)
- [Source: app/Providers/LdapUserProvider.php] (driver `sambaedu` : `retrieveByCredentials`/`validateCredentials` → `AuthenticationService`)
- [Source: app/Services/AuthenticationService.php:265-464] (`validateAdCredentials`, `validatePassword`, `attemptBind` = bind LDAP brut canal B à doubler)
- [Source: app/Providers/LdapRecordServiceProvider.php:30-75] (enregistrement de la connexion LdapRecord canal A — point d'injection du fake/garde-fou)
- [Source: config/auth.php + config/ldap.php] (guard `web`/provider `sambaedu` ; config LdapRecord)
- [Source: app/Gpo/Support/SambaToolRunner.php] (canal C `samba-tool` ; mode `dryRun` existant ; garde-fou archi `GpoNamespaceTest`)
- [Source: app/Ldap/AdUserManager.php + AdMachineManager.php] (écritures `samba-tool user/computer` — parcours 21.5/21.6)
- [Source: app/Services/AdSync/AdSyncService.php:303-360] (`moveMachineToSalle` : A→B poreux, `ldap_rename` — swap de salle 21.6)
- [Source: app/Observers/WorkstationObserver.php + app/Jobs/AdSync/WorkstationAdSyncJob.php] (chaîne async ; observer Workstation actif hors `testing` donc actif en `e2e`)
- [Source: app/LdapModels/LdapUser.php:70-74] (`findByLogin` via Container — résolution d'identité auth, canal A)
- [Source: database/seeders/DatabaseSeeder.php] (seed actuel sans users/établissement — données de référence = 21.3)
- [Mémoire: feedback_auth_iso_legacy] (auth machine iso-legacy AD+SMB — ne pas toucher le flux réel non-e2e)
- [Mémoire: project_ad_sync_resolve_by_guid] (résolution par `ad_guid` stable → GUID factices déterministes)
- [Mémoire: project_audit_http_misses_livewire / project_pivot_global_memberships] (canal Livewire = 21.5 ; swap service pivot = 21.6)
- [Mémoire: feedback_worktree_no_vm_sync / project_php_fpm_user_www_admin] (branche `playwrite` non syncée → pas de VM depuis l'agent ; `.env.e2e` chown www-admin)

## Questions pour Henri — ✅ toutes tranchées (2026-06-04)

- **DP-AUTH** : ✅ **Option A** — fake derrière l'abstraction LdapRecord/`AuthenticationService`, interface `AdCredentialValidator` injectable (iso-prod : les e2e exercent le vrai flux d'auth).
- **DP-LOG** : ✅ **Option 1** — table Postgres `e2e_ad_writes` + endpoint e2e read-only gated `APP_ENV=e2e`.
- **DP-SCOPE** : ✅ **A + B + C-core** doublés fonctionnellement ; GPO/WPKG/iPXE/Power/Print couverts par le seul garde-fou anti-AD-réel (doublés plus tard si un parcours les exerce).

## Dev Agent Record

### Agent Model Used

claude-opus-4-8[1m] (dev-story).

### Décisions T0 (recon + points de décision)

Inventaire des 3 canaux **confirmé sur le code réel** :
- **A — LdapRecord** : `LdapRecordServiceProvider::boot()` enregistre la connexion `default` du `Container`. Résolution d'auth via `LdapUser::findByLogin` (statique) appelé par `UserRepository::findLdapModelByLogin` (login) et `UserRepository::findByLogin` (revérif guard).
- **B — `ldap_bind` brut** : `AuthenticationService::attemptBind()` (`private`, + `buildLdapUrl()` `private`).
- **C — `samba-tool`** : `SambaToolRunner::run()` (point unique). Consommé par `AdUserManager`/`AdMachineManager` (parcours 21.5/21.6) et la chaîne async `WorkstationAdSyncJob` (via `AdMachineManager`).

Pattern d'injection confirmé : `AppServiceProvider::register()` binde déjà `AuthGuardInterface→SambaEduAuthGuard` et `CommandRunner→RealCommandRunner` — **réutilisé tel quel**.

**Décisions Henri (2026-06-04) appliquées sans re-débat** :
- **DP-AUTH = Option A** — extraction du bind derrière l'interface `App\Contracts\Ad\AdCredentialValidator` (`Real`=`attemptBind` historique ; `FakeE2e`=compare au mdp seedé). La **résolution** (canal A) extraite derrière `App\Contracts\Ad\AdDirectory` (`Real`=`LdapUser::findByLogin` ; `Fake`=Postgres). Les 3 sous-chemins d'auth sont ainsi doublés : résolution (A via AdDirectory), validation mdp (B via AdCredentialValidator), revérif guard (A via la même AdDirectory dans `UserRepository::findByLogin`). `LdapUserProvider`/`SambaEduAuthGuard`/sessions/Spatie **inchangés** → flux iso-prod exercé en e2e.
- **DP-LOG = Option 1** — table Postgres `e2e_ad_writes` (modèle `App\Models\E2e\AdWriteLog` + migration **e2e-only**) + endpoint read-only `GET /e2e/ad-writes` (route déclarée seulement si `APP_ENV=e2e`, + revérif env dans le contrôleur).
- **DP-SCOPE = A+B+C-core** doublés fonctionnellement. GPO/WPKG/iPXE/Power/Print : non doublés, couverts par le seul garde-fou anti-AD-réel (`ThrowingLdapConnection` pour A ; `FakeSambaToolRunner` capture sans exécuter pour C).

**Choix d'implémentation notables** :
- Garde-fou AC3 = `ThrowingLdapConnection extends LdapRecord\Connection` installée comme connexion `default` en e2e (`getLdapConnection()`/`connect()` lèvent). Toute opération LDAP réelle d'un chemin non-doublé échoue **bruyamment** au lieu d'écrire dans le vrai AD.
- `FakeAdDirectory` hydrate un `LdapUser` in-memory via `setRawAttributes`/`setDn` (aucune requête Container) ; DN canonique `CN=<login>,OU=e2e,DC=e2e,DC=local` re-parsé par le validateur fake pour retrouver le login → le mdp attendu.
- `AdSyncService::{moveMachineToSalle,createWorkstationGroup}` doublés **au niveau service** (e2e short-circuit additif) car ils descendent directement sur LdapRecord/`ldap_rename` (canal A/B poreux), hors `SambaToolRunner`. GUID factice stable via `FakeAdRecorder`.
- `FakeSambaToolRunner` construit son `ProcessResult` via `new FakeProcessResult(...)` (et NON la facade `Process`) pour respecter le garde-fou archi `LdapNamespaceTest` qui interdit la facade `Process` sous `app/Ldap/*`.

### Debug Log References

Aucun (pas d'exécution locale possible : `php`/`vendor` absents de l'hôte, cf. 21.1).

### Completion Notes List

- **T0** ✅ Recon + décisions consignées ci-dessus.
- **T1** ✅ Garde-fou structurel : `ThrowingLdapConnection` + branche e2e dans `LdapRecordServiceProvider::boot()` (la vraie connexion n'est jamais enregistrée en e2e). Canal C : `FakeSambaToolRunner` n'exécute aucun binaire.
- **T2** ✅ Fake annuaire (`FakeAdDirectory`) + capture (`FakeAdRecorder`, GUID déterministes SHA-1) + `FakeSambaToolRunner`. Bindés uniquement en e2e.
- **T3** ✅ Journal `e2e_ad_writes` (modèle + migration e2e-only) + endpoint `GET /e2e/ad-writes` gated.
- **T4** ✅ Auth fake : `AdCredentialValidator` + `AdDirectory` extraits et bindés fake en e2e ; 3 sous-chemins couverts.
- **T5** ✅ Chaîne async : `AdMachineManager` → fake samba-tool ; `AdSyncService` move/create doublés au niveau service.
- **T6** ✅ Conditionnement strict : tout binding fake sous `if ($this->app->environment('e2e'))` ; bindings réels par défaut partout (test host de non-régression).
- **T7** ✅ Tests host : `tests/Unit/E2e/FakeAdGuardTest` (garde-fou lève + GUID stable, sans I/O) et `tests/Feature/E2e/FakeAdCaptureTest` (capture sans process, auth sans bind, non-régression bindings réels en testing).
- **T8** ✅ Doc append-only `docs/qa/domains/e2e-infra.md` §4 + `docs/qa/e2e-setup.md` (rebuild template) + `.env.e2e.example`.
- **T9** ✅ Revue syntaxe ; `phpunit.xml` non modifié. Suite PHPUnit à rejouer VM (henri).

### Écarts vs story

- **`AdDirectory` introduit en plus d'`AdCredentialValidator`** : la story (DP-AUTH) mentionnait surtout l'interface de validation du mdp + « une fake connexion LdapRecord (ou résolution Postgres) pour `findByLogin` ». J'ai choisi la **résolution Postgres via une 2e interface `AdDirectory`** (plutôt qu'une fake connexion LdapRecord) : plus simple, sans dépendre des internes LdapRecord (vendor absent → non vérifiable), et route proprement les 2 call-sites d'auth. Conforme à l'intention (sous-chemins a+c doublés).
- **Emplacement contrats** : `app/Contracts/Ad/` (créé), comme suggéré par la story (« ex. `app/Contracts/Ad/` nouveau »).
- **Migration `e2e_ad_writes` e2e-only** (`up()` no-op hors e2e) : option « migration conditionnée » de DP-LOG, pour ne PAS polluer le schéma dev/prod/testing. Conséquence : les tests host créent la table à la main (SQLite).
- **`AuthenticationService` garde un wrapper `attemptBind`** qui délègue au validateur (au lieu de supprimer la méthode) : minimise les changements de call-sites internes et préserve la sémantique du « hack pwdlastset=0 ».
- **Modif d'un test existant** : `AuthenticationServicePasswordChangedAtTest` construit `AuthenticationService` à la main → 3e argument (mock `AdCredentialValidator`) ajouté. Nécessaire et non-régressif.

### Reste à faire par henri (hors scope agent)

- **Rejouer la suite PHPUnit sur la VM** (php/vendor absents de l'hôte → validation locale = revue syntaxe seule).
- **Reconstruire la template e2e** (`APP_ENV=e2e php artisan e2e:build-template`) pour matérialiser la nouvelle table `e2e_ad_writes` (migration e2e-only) — sinon capture/endpoint KO « table absente ».
- **Renseigner `E2E_FAKE_AD_PASSWORD`** dans `.env.e2e` (credential des users seedés pour l'auth fake — cf. `.env.e2e.example`). Les **users seedés** eux-mêmes arrivent en **21.3**.
- Branche `playwrite` non syncée → déployer le code sur l'instance e2e avant tout test réel.

### File List

**Créés (13)** :
- `app/Contracts/Ad/AdCredentialValidator.php`
- `app/Contracts/Ad/AdDirectory.php`
- `app/Services/Auth/RealAdCredentialValidator.php`
- `app/Ldap/Real/RealAdDirectory.php`
- `app/Ldap/Fakes/FakeAdRecorder.php`
- `app/Ldap/Fakes/FakeAdDirectory.php`
- `app/Ldap/Fakes/FakeE2eAdCredentialValidator.php`
- `app/Ldap/Fakes/FakeSambaToolRunner.php`
- `app/Ldap/Fakes/ThrowingLdapConnection.php`
- `app/Models/E2e/AdWriteLog.php`
- `database/migrations/2026_06_05_120000_create_e2e_ad_writes_table.php`
- `app/Http/Controllers/E2e/AdWriteLogController.php`
- `config/e2e.php`
- `tests/Unit/E2e/FakeAdGuardTest.php`
- `tests/Feature/E2e/FakeAdCaptureTest.php`

**Modifiés (8)** :
- `app/Services/AuthenticationService.php` (injection `AdCredentialValidator`, extraction du bind, suppression `attemptBind`/`buildLdapUrl` privés)
- `app/Repositories/UserRepository.php` (résolution d'auth via `AdDirectory` injectée, repli statique)
- `app/Providers/AppServiceProvider.php` (bind réels par défaut + swap fakes gated e2e)
- `app/Providers/LdapRecordServiceProvider.php` (connexion piégée e2e)
- `app/Services/AdSync/AdSyncService.php` (double e2e `moveMachineToSalle`/`createWorkstationGroup` + helper `e2eCaptureWrite`)
- `routes/web.php` (route e2e `/e2e/ad-writes` gated, import `App` facade)
- `.env.e2e.example` (clé `E2E_FAKE_AD_PASSWORD` + note fake AD)
- `docs/qa/e2e-setup.md` (note rebuild template + clé .env)
- `docs/qa/domains/e2e-infra.md` (Section 4 — Fake AD/Samba, append-only)
- `tests/Feature/Services/AuthenticationServicePasswordChangedAtTest.php` (3e arg constructeur)

> Note : `phpunit.xml` **NON modifié** (AC9).

## Change Log

| Date       | Auteur                       | Changement |
|------------|------------------------------|------------|
| 2026-06-04 | claude-opus-4-8[1m] create-story | Création de la story 21.2 (fake AD/Samba e2e) : contexte, inventaire exhaustif des 3 canaux AD/Samba, scope strict, 9 ACs, T0-T9, points de décision DP-AUTH/DP-LOG/DP-SCOPE avec recommandations. Status → ready-for-dev. |
| 2026-06-05 | claude-opus-4-8[1m] dev-story | Implémentation complète (T0-T9). DP-AUTH=A (interfaces `AdCredentialValidator`+`AdDirectory`, fakes e2e), DP-LOG=1 (table `e2e_ad_writes` e2e-only + endpoint `GET /e2e/ad-writes` gated), DP-SCOPE=A+B+C-core. Garde-fou AC3 = `ThrowingLdapConnection` (connexion LdapRecord piégée en e2e). Auth doublée sur 3 sous-chemins. Canal C = `FakeSambaToolRunner` (capture sans exécuter) ; `AdSyncService` move/create doublés au niveau service. 13 fichiers créés + 8 modifiés. Bindings réels inchangés hors e2e ; `phpunit.xml` inchangé. Tests host SQLite (garde-fou + GUID stable + capture sans process + auth sans bind + non-régression). php/vendor absents hôte → PHPUnit à rejouer VM (henri). Status → review. |

## Recommandation Modèle Dev

**opus.**

Justification : story d'infrastructure de sécurité à surface élevée. Le cœur est un **garde-fou structurel** (AC3 : le vrai `samba-ad-dc` doit être **inatteignable** en e2e) dont une fuite = un test qui crée un vrai compte/une vraie machine dans l'AD de prod — invariant qui exige un raisonnement rigoureux. S'y ajoutent : un **inventaire multi-canal** (LdapRecord, `ldap_bind` brut, `samba-tool`) avec des frontières poreuses (`AdSyncService` qui descend de A à B), une **doublure d'auth** non triviale (3 sous-chemins : résolution, validation mdp `private`, revérif par requête) nécessitant probablement d'extraire un point d'injection, un **piège de contexte fort** (`testing` ≠ `e2e`, branche `playwrite` non syncée, interdiction VM/SSH, `php`/`vendor` absents → pas de tests locaux), et plusieurs points de décision structurants à arbitrer finement. Logique critique de sécurité + recon transverse + refactor d'injection ciblé → opus.

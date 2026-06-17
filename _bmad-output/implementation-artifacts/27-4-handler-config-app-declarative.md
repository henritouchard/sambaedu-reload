# Story 27.4 : Handler config d'app déclarative — policies.json Firefox/Thunderbird

Status: review

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

> **ℹ️ NUMÉROTATION & CADRAGE — lire en premier.** Vraie story 27.4 du slot canonique
> *« Handler config d'app déclarative »* (`epics-agent-desired-state.md` L738-754). Quatrième TYPE de ressource
> porté au canal agent, dans la lignée 27.1 (raccourcis), 27.2 (lecteurs/imprimantes), 27.3 (registre) et
> 27.3bis (associations) — même pattern figé **« 1 StateProvider + 1 handler Go + identifiant de type figé
> `app_config` + golden file »** (Epic 27, L681). **Contrairement** à 27.3/27.3bis (premiers types SANS table
> métier), `app_config` a **DÉJÀ une table métier** (`app_customizations`, story 4.8) et son service de
> résolution (`AppCustomizationService`) — on est dans le cas **27.1/27.2 (lecture des règles existantes)**, PAS
> dans le cas registre (création de table). **NE PAS créer de nouvelle table de policies.**
>
> **🔧 RECADRAGE PÉRIMÈTRE — 2026-06-17 (Henri, après comparatif legacy SE4).** Le périmètre est **resserré au
> seul équivalent legacy réel** : **`policies.json` Firefox/Thunderbird**. Sont **RETIRÉS de 27.4** : (1) toute
> policy **Chrome/Edge** (le legacy n'en gère AUCUNE ; ce serait du net-new sans référence) → **un seul
> mécanisme handler : écrire `policies.json`**, plus de mécanisme « registre policies » ; (2) la **redirection
> de profil navigateur** (sujet **roaming** serveur dans le legacy, pas une policy client) → renvoyée au domaine
> roaming/`WorkstationEnvironment` (26.x / story de suivi) ; (3) la **dépendance 26.1** (plus aucun usage en
> 27.4). Voir `## Comparatif legacy SE4` et `## Décisions de design`.

## Story

En tant qu'**admin d'établissement**,
je veux **que la configuration des navigateurs configurables par policies natives (Firefox, Thunderbird) suive
l'état cible du parc et de l'utilisateur, appliquée et maintenue par l'agent**,
afin que **les politiques d'application soient gérées par le canal agent (successeur GPO) — via le mécanisme
enterprise NATIF de chaque app (`policies.json`), sans scripts bricolés** (idée #14 du brainstorming).

## Contexte & intention

**Place dans l'Epic 27.** `app_config` est la 4ᵉ ressource du parcours « du simple au dur ». Elle livre, comme
les précédentes : **1 `AppConfigStateProvider` serveur (lecture seule) + 1 handler agent Go `app_config` +
identifiant de type figé (déjà gravé contrat §7) + golden file**. **Zéro retrofit legacy** : on construit À
CÔTÉ, le canal legacy meurt en bloc en 27.6.

**Ce que remplace ce handler.** Aujourd'hui SE5 résout les policies par scope (`AppCustomizationService`,
story 4.8) et les **exporte sur le filesystem** (`exportToFs` → `/etc/sambaedu/applications/{kind}/*.json`)
pour que le canal legacy (GPO/scripts d'install WPKG) les pousse sur le poste. Ce handler en est le
**successeur natif** : l'état cible (policies résolues serveur) est **réimposé par l'agent** via le **mécanisme
enterprise natif** de Firefox/Thunderbird — le fichier **`policies.json`** posé au chemin natif de l'install.
Le serveur calcule le « QUOI » (le payload de policies concret), l'agent fait le « OÙ/COMMENT » (chemin natif
par app, écriture de fichier atomique, idempotence, drift STRICT).

**Le socle métier déjà livré (NE PAS réinventer — c'est le `wallpapers` de cette story).**
- **Table** `app_customizations` (story 4.8) : `app_kind` (enum `AppKind` : `firefox`|`thunderbird`),
  morph `customizable_type`/`customizable_id` (scopes `WorkstationGroup` / `UserGroup` / `User` + défaut
  étab global NULL/NULL), `policies_json` (array casté), `is_default`.
- **Résolution hiérarchique** : `AppCustomizationService::resolvePoliciesForMachine(?WorkstationGroup, ?User,
  AppKind, $os)` applique les 6 niveaux (template → auto proxy/DNS/popup → défaut étab → WG → UserGroups → User,
  merge profond `mergeOverrides`, dernier match gagne).
- **Adapters par app** : `FirefoxPolicyAdapter` / `ThunderbirdPolicyAdapter` (`getTemplate`/`applyAuto`/
  `mergeOverrides`/`exportToFs`/`validatePolicies`) — **port fidèle du legacy** (auto-injection proxy/popup/DNS).
  `AppKind::cases()` = **`Firefox`, `Thunderbird`** UNIQUEMENT (pas de Chrome/Edge — voir comparatif legacy).
- **UI** : `parc-settings/app-customizations/index.blade.php` (Livewire SFC, gate `app.customize`) édite déjà
  les policies par scope. **Le geste d'authoring existe** — cette story ne le réécrit PAS, n'ajoute AUCUNE UI.

**Ce que cette story livre :**
- **Serveur** : `AppConfigStateProvider` (lecture seule de `app_customizations` via
  `AppCustomizationService::resolvePoliciesForMachine`), type figé `app_config` (contrat §7, NFR12 — DÉJÀ
  gravé), **`semantics = aggregate` par identité `app_kind`** (un item par app, policies résolues concrètes,
  dédup par contenu au compilateur), **`scope = machine`** (correctif post-review 2026-06-17 #1 — `policies.json`
  machine-wide écrit par le service SYSTEM, résolution PAR PARC niveaux 1-4 avec `$user = null` ; le par-user de
  Firefox = le profil = Mécanisme B/roaming, hors 27.4), payload v1 owné ici.
- **Agent Go** : un handler `app_config` (`agent/shared/handler_app_config.go` logique pure +
  `agent/windows/handler_app_config_windows.go`), enregistré dans le moteur — convergence level-triggered,
  écriture **du seul mécanisme enterprise natif `policies.json`** (fichier atomique au chemin natif de l'app),
  idempotent, drift STRICT, isolation par item.
- **Golden file** : item `app_config` ajouté à `tests/Fixtures/Agent/state.v1.json` (+ `report.v1.json`) →
  `FROZEN_STATE_HASH` bumpé **sciemment**, **croisé PHP↔Go** (NFR13).
- **UI** : **AUCUNE nouvelle UI** (l'édition policies 4.8 existe ; aucune donnée de redirection à porter —
  sujet hors-scope).

**Ce que cette story N'EST PAS :**
- **Une nouvelle table de policies.** `app_customizations` existe (4.8). On la **LIT**, on ne la double pas
  (sinon violation D1 par redondance + double source de vérité). C'est la différence majeure avec 27.3/27.3bis.
- **Une réécriture de `AppCustomizationService`.** Le service est **lu/réutilisé** pour résoudre les policies ;
  il n'est pas modifié pour le canal agent (comme `CupsPrinterService`/`ShareService` en 27.2).
  `resolvePoliciesForMachine` est **PG + config-pur** (audit NFR7 ci-dessous) → réutilisable tel quel.
- **De la config Chrome/Edge** ni de **redirection de profil navigateur**. **RETIRÉ DU PÉRIMÈTRE** (recadrage
  2026-06-17, voir `## Comparatif legacy SE4`). Le legacy ne gère AUCUNE policy Chrome/Edge ; la redirection de
  profil est un sujet **roaming** serveur (renvoyé au domaine roaming/`WorkstationEnvironment` — 26.x / story de
  suivi). Pas de `mechanism = registre`, pas de champ `profile_location`, pas d'usage de
  `WorkstationEnvironmentResolver` dans 27.4.
- **L'installation des apps** (Firefox/Thunderbird doivent être présents pour que la policy ait un effet) →
  27.5 (applications/WPKG). Couplage documenté en **limite connue**.
- **Le décommissionnement du canal legacy** (export FS `/etc/sambaedu/applications/`, GPO, scripts) → 27.6.
  Zéro retrofit legacy ici.
- **Le mode strict/default** : RETIRÉ en bloc par 27.8 (drift STRICT inconditionnel, item contrat = 4 clés,
  plus de `drifted_allowed`). **NE PAS réintroduire de toggle strict/default** dans 27.4.

**Zéro prod (mémoire `zero_prod_publish_is_test`).** Aucune donnée à préserver. Pas de migration de policies à
back-fill (la table 4.8 est déjà peuplée par l'UI existante). **A priori AUCUNE migration** (table 4.8 existe,
aucun champ neuf après recadrage).

## Comparatif legacy SE4 (cadrage du périmètre — 2026-06-17)

> Cette section justifie le périmètre resserré. Elle s'appuie sur le legacy SE4 sous
> `/home/htouchard/code/irundo/codebase/sambaedu/` : `includes/firefox.inc.php`, `gpo/firefox_out.php`,
> `conf_params.php` (~L559-573), `gpo/del_roam.php`.

> **🔧 Mécanisme A vs Mécanisme B (précision post-review 2026-06-17, #1).** Le legacy traite Firefox via DEUX
> mécanismes distincts qui **coexistent**, à ne pas confondre :
> - **Mécanisme A — config (`policies.json`)** : marque-pages, page d'accueil, extensions/plugins, proxy. Écrit
>   dans `%ProgramFiles%\Mozilla Firefox\distribution\policies.json` = **machine-wide, contexte SYSTEM/admin,
>   PAR-PARC** (jamais par-user). **C'est le périmètre de 27.4** (scope `machine`).
> - **Mécanisme B — profil user** : jonctions/redirection du dossier profil vers le home (sujet **roaming**,
>   lourd). **HORS 27.4** (story roaming de suivi). C'est ICI que vit le « par-user » de Firefox — PAS dans
>   `policies.json`.

**Legacy Firefox/Thunderbird = `policies.json` natif (Mécanisme A) construit serveur, servi en HTTP, tiré par le client.**
- Le serveur construit un `policies.json` à partir d'un **template** (`getTemplate`, calque
  `/usr/share/sambaedu/applications/<app>/default.json`) + **auto-injection** des réglages système
  (proxy/popup/DNS via `applyAuto`, lus de `conf_params.php`) + **overrides locaux** (calque
  `/etc/sambaedu/applications/<app>/*.json`) matchés par **tags `list_*`** et mis en cache **APCu** (clé
  `apps.<id>`). Le poste tire le fichier en **HTTP** via `gpo/firefox_out.php?id&os`.
- → SE5 4.8 l'a **fidèlement porté** : `FirefoxPolicyAdapter`/`ThunderbirdPolicyAdapter`
  (`getTemplate`/`applyAuto` proxy/popup/DNS/`mergeOverrides`/`exportToFs`) + un ciblage **plus riche**
  (défaut étab → `WorkstationGroup` → `UserGroup` → `User` vs les tags `list_*` plats du legacy).

**Legacy Chrome/Edge = AUCUNE policy.** Le legacy ne pose **aucune clé de registre**
`HKLM\SOFTWARE\Policies\Google\Chrome` ni `…\Microsoft\Edge`, **aucun `policies.json`** Chrome. Le seul
traitement Chrome legacy est la **redirection de profil** vers le `/home` réseau (drapeau/groupe AD
`chrome_server_profiles` + exclusions `ExcludeProfileDirs` ; `gpo/del_roam.php` = **nettoyage de profil
itinérant**). C'est du **roaming serveur**, **pas une policy client**.

**Conclusion de cadrage.** Le vrai équivalent legacy de « config d'app déclarative » est **Firefox/Thunderbird
`policies.json`** — et rien d'autre. **Chrome/Edge sort de 27.4** (policies = net-new sans référence legacy ;
redirection = roaming). **La redirection de profil sort de 27.4** et est renvoyée au **domaine
roaming/`WorkstationEnvironment` (26.x / story de suivi)** — c'est un déplacement de répertoire de profil, pas
une policy applicative que l'agent pose dans un `policies.json`.

## ⚠️ Pièges & tensions découverts à l'analyse (lire AVANT de coder)

> Les forks de conception sont **TRANCHÉS** (voir `## Décisions de design`). Le dev applique les décisions
> sans les re-trancher (sauf blocage technique avéré en T0, qu'il remonte).

### A) Le socle 4.8 EXISTE — on lit, on ne recrée pas

1. **🔴 `app_config` a DÉJÀ sa table métier (`app_customizations`) — c'est un provider de LECTURE, PAS un
   créateur de table.** Contrairement à 27.3 (`registry_settings` créée) et 27.3bis (`file_associations`
   créée), `app_config` est dans le cas **27.1/27.2** : projeter en lecture seule des règles SQL existantes.
   **Créer une 2ᵉ table de policies = doublon de source de vérité + dette.** Le provider lit
   `app_customizations` via `AppCustomizationService::resolvePoliciesForMachine` (résolution hiérarchique
   serveur). [Source: `app/Models/AppCustomization.php` ; `app/Services/AppCustomization/AppCustomizationService.php`.]

2. **La liste des apps = `AppKind::cases()` = `Firefox`, `Thunderbird` (un point, final).** Chrome/Edge n'ont
   **pas** d'`AppKind` ni d'adapter, et **n'en auront pas en 27.4** (recadrage : le legacy n'en gère aucune
   policy). Ajouter une app `policies.json` plus tard (OpenBoard si jamais) = un `AppKind` + un adapter serveur,
   sans toucher le handler agent (qui ne connaît que « poser un `policies.json` au chemin natif »).
   [Source: `app/Enums/AppKind.php`.]

### B) Le grain du payload & la sémantique — la principale subtilité serveur

3. **Sémantique : `aggregate` par identité `app_kind`.** Un poste reçoit la config de **plusieurs apps**
   (Firefox ET Thunderbird) → l'union par app. Mais **pour une app donnée**, il n'y a **qu'un** jeu de policies
   effectif (la résolution hiérarchique 4.8 a déjà fusionné les 6 niveaux en UN `policies_json`). Donc :
   **`aggregate`** (collection d'items, un par app) où chaque item est « la config résolue de CETTE app ».
   Ce n'est PAS un `exclusive` (plusieurs apps coexistent) ni un `aggregate` à fusionner côté agent (la fusion
   6-niveaux est faite SERVEUR par `resolvePoliciesForMachine`). ⚠️ **Subtilité** : si deux mailles produisent
   une config Firefox, ce n'est PAS au compilateur de les départager — **`AppCustomizationService` a DÉJÀ
   résolu** la hiérarchie. Le provider émet **un candidat par `app_kind`** déjà résolu ; le compilateur ne fait
   que **dédoublonner par contenu**.

4. **Le payload porte les policies RÉSOLUES, pas un pointeur de scope.** Iso l'invariant 27.3 (« jamais d'id de
   catalogue au payload ») : le payload `app_config` porte **le contenu concret** que l'agent écrit
   (`{app_kind, policies}` ou équivalent), **jamais** un `customization_id` ni un scope. Le scope/la hiérarchie
   4.8 est un **détail serveur** qui se **compile** en policies concrètes. **Pas de float** (contrat §4 ; les
   policies JSON ne contiennent que strings/ints/bools/null/objets/listes — vérifier qu'aucune valeur de policy
   n'est un float, ex. un timeout décimal → string).

5. **🟢 NFR7 — AUDITÉ : `resolvePoliciesForMachine` est PG + config-pur, réutilisable tel quel.** Voir la
   section `## Audit NFR7` ci-dessous. Le provider desired-state appelle `resolvePoliciesForMachine()` directement.
   Garde-fou de revue inchangé : **grep `ldap|apcu|samba-tool|Cache::` sur `AppConfigStateProvider` doit rester
   VIDE** (critère Keycloak, reconduit de 27.1/27.2/27.3/27.3bis).

6. **`TargetContext` n'a pas d'`AppKind` ni d'OS — résoudre depuis le contexte.** `resolvePoliciesForMachine`
   attend `?WorkstationGroup, ?User, AppKind, $os`. Le `TargetContext` expose `physicalGroupIds`,
   `logicalGroupIds`, `userGroupIds`, `user`, `workstationGroupIds()`. ⚠️ Le service 4.8 prend **UN**
   `WorkstationGroup` (sa salle), pas une liste de mailles — il y a une **impédance** entre le modèle
   « précédence 6-niveaux à un WG » de 4.8 et le modèle « mailles multiples → compilateur » de l'Epic 27.
   **Le provider doit décider** comment instancier la résolution depuis les ids de mailles du contexte (ex.
   itérer par WG des mailles, ou résoudre par scope sans passer par le WG unique). **À INSTRUIRE en dev** (le
   grain du provider est différent de l'API 4.8). L'OS : l'agent est Windows-cible → `$os = 'windows'`.

### C) App butée = limite connue assumée (pas de bricolage agent)

7. **App qui écrit localement SANS option enterprise → limite connue (« match nul » du brainstorming).** Si une
   app ne propose AUCUN mécanisme natif (pas de `policies.json`) pour un réglage donné, **l'agent N'INVENTE
   RIEN** (pas de patch de fichier de config user bricolé, pas de hook). Le réglage est **documenté comme non
   géré** (limite connue, AC epic). Invariant : **un handler n'écrit que via un mécanisme enterprise documenté
   de l'app.** Aucune chorégraphie dispersée (c'est tout l'enjeu du successeur GPO vs le legacy à scripts).

### D) Tensions communes (réutiliser 27.1/27.2/27.3 — zéro modif moteur)

8. **Machine d'états §5 + isolation par item + `AggregateHash` = RÉUTILISÉS, jamais réécrits.** Un verdict par
   TYPE ; une app Firefox en `error` (chemin verrouillé) n'empêche pas Thunderbird ni les autres types
   (isolation `engine.go::RunPass`). Drift STRICT (27.8) : policy réelle ≠ cible → `drift` + réapplication.
   **Ne pas réimplémenter §5.**

9. **Le handler agent est Windows, la logique pure est testable hôte** (pattern 24.6/27.1/27.2/27.3). Logique
   pure (`agent/shared/handler_app_config.go`) : set cible des configs d'app, décision test/apply, marqueur de
   périmètre géré (écrire/comparer un `policies.json` SambaEdu sans toucher une config posée par l'utilisateur
   hors périmètre). Spécifique Windows (`handler_app_config_windows.go`) : **écrire `policies.json` au chemin
   natif de l'install** (Firefox `…\Mozilla Firefox\distribution\policies.json`, Thunderbird au chemin
   équivalent — à instruire), **écriture fichier atomique**. Stub `!windows` (no-op). **Un seul mécanisme**
   (plus de branche registre). **Vérifier l'interop existante avant d'ajouter une dépendance** (go.mod inchangé
   — l'écriture de fichier atomique est déjà disponible, calque `handler_shortcuts`).

10. **Golden bouge LÉGITIMEMENT (nouveau type au contrat).** Ajouter un item `app_config` au
    `tests/Fixtures/Agent/state.v1.json` ⇒ `FROZEN_STATE_HASH` (PHP `ContractV1Test`) **et** `frozenStateHash`
    (Go `hasher_test.go`) **bumpés à la MÊME valeur** (test croisé NFR13). **Valeur courante du tree =
    `77fb548ac9b1f0604afce2a0c7d0316379391ef2e182a95a005b979f3fa5e3bd`** (PHP `ContractV1Test::FROZEN_STATE_HASH`
    L86 + Go `hasher_test.go` L53 — **re-vérifier au début du dev**, d'autres stories en review peuvent l'avoir
    bougée). Le bump est attendu, ce n'est PAS une régression. Item à **4 clés** (`type, semantics, payload,
    hash` — `mode` retiré par 27.8). **NE PAS** réintroduire `mode`.

11. **VM migrations / Go hôte / worktree (iso 27.3).** **AUCUNE migration** (table 4.8 existe, aucun champ neuf
    après recadrage). Go = hôte (`~/go-toolchain/go/bin`, package main = `agent/windows`) ; PHPUnit /vm ;
    **jamais** d'interaction VM depuis ce worktree. `routes/api.php` : aucune route attendue (provider consommé
    par la compilation d'état existante) — si exception, insérer APRÈS le groupe 16.12 (mémoire
    `api_routes_arch_test_window_trap`).

## Audit NFR7 — DÉJÀ FAIT (T0 = re-confirmation)

> **`AppCustomizationService::resolvePoliciesForMachine` est PG + config-pur. La tâche T0 ne fait que
> re-confirmer (grep), pas auditer de zéro.**

Constats (lecture du code 2026-06-17) :
- **`systemConfig($os)`** lit `config('app-customizations.system_config')` + `['os' => $os]` — **config-pur**,
  pas le cache `app_context` ni APCu. [Source: `AppCustomizationService::systemConfig`, L266-272.]
- **Niveaux 3-6** = requêtes Eloquent **`AppCustomization::query()` directes** (défaut étab, WG, UserGroups
  `whereIn`, User) — **PG direct**, aucun `Cache::`/`remember`. [Source: `resolvePoliciesForMachine`, L44-104.]
- **Niveau 5 (UserGroups)** = `$user->userGroups()->pluck('user_groups.id')` — relation **`BelongsToMany`
  Postgres** (pas d'AD/LdapRecord live). [Source: `User::userGroups()`.]
- **Niveaux 1-2 (template/auto)** = fichiers FS statiques + transformation in-memory des adapters
  (`getTemplate`/`applyAuto`) — **aucun cache, aucun AD**. Grep `Cache::|apcu|Ldap|samba-tool` sur le service
  et les adapters Firefox/Thunderbird = **VIDE**.
- Le cache **APCu `CacheAppContextRepository`** (`apps.$id`) sert l'**AUTRE** canal (legacy-port
  `ApplicationScriptsAssembler` / `LinuxOutController`), **PAS** `resolvePoliciesForMachine`.

⇒ **Réutilisation sûre.** T0 = re-confirmer par grep que (a) le provider n'introduit aucun `Cache::`/APCu/AD,
(b) le chemin `resolvePoliciesForMachine` reste PG-pur dans le tree au moment du dev.

## Décisions de design — ARBITRÉES PAR HENRI (2026-06-17) — DÉFINITIVES

> Forks tranchés à la validation, **après comparatif legacy SE4**. Le dev applique ces décisions SANS les
> re-trancher (sauf blocage technique avéré en T0, qu'il remonte).

1. **FF/TB `policies.json` ONLY — UN SEUL MÉCANISME.** Le handler agent écrit **uniquement** un `policies.json`
   au chemin natif de l'app. **Pas de Chrome/Edge, pas de mécanisme « registre policies »** (le legacy n'en
   gère aucune — comparatif). Ajouter une app `policies.json` future = data serveur, zéro release agent.
2. **Sémantique `aggregate` par `app_kind`** (piège n° 3). Le provider émet un candidat par app avec ses
   policies **déjà résolues** (hiérarchie 4.8 faite serveur) ; le compilateur dédoublonne par contenu.
3. **Source de résolution = `AppCustomizationService::resolvePoliciesForMachine($wg, $user, $kind, $os)`**,
   réutilisé tel quel (audit NFR7 fait — PG + config-pur). Le provider l'instancie depuis les mailles du
   `TargetContext` (instruire l'impédance « WG unique 4.8 » vs « mailles Epic 27 », piège n° 6). Zéro APCu/AD.
4. **Scope `machine`** (correctif post-review 2026-06-17 #1 — décision Henri après comparatif legacy Firefox
   complet). Le legacy traite Firefox via DEUX mécanismes distincts qui coexistent :
   - **Mécanisme A — config** = `policies.json` (marque-pages, page d'accueil, extensions/plugins, proxy), écrit
     dans `%ProgramFiles%\Mozilla Firefox\distribution\policies.json` = **machine-wide, contexte SYSTEM/admin,
     PAR-PARC** (jamais par-user). **C'est le périmètre de 27.4.**
   - **Mécanisme B — profil user** = jonctions/redirection du dossier profil vers le home (roaming). **HORS
     27.4** (story roaming de suivi).

   Un `policies.json` est **machine-wide**, posé sous Program Files (ACL admin-write) : un compagnon aux droits
   user prendrait **ACCESS_DENIED à chaque logon**. La portée est donc **`machine`** — le **service SYSTEM**
   écrit le fichier (iso le handler `registry` HKLM, 27.3). Le par-user de Firefox = le **profil** (Mécanisme B),
   PAS `policies.json` : on n'a **PAS besoin des niveaux user** (5-6) dans la résolution.
   `resolvePoliciesForMachine($wg, null, $kind, $os)` résout les **niveaux 1-4** (template + auto + défaut étab +
   WG). _(Le framing initial « scope session combine parc + user » était FAUX : c'est deux mécanismes
   coexistants — la config policies.json est par-parc, le par-user passe par le profil.)_

   ⚠️ **Limite connue (review #2)** : un poste appartenant à PLUSIEURS parcs logiques avec des policies
   différentes → seul le WG gagnant (précédence `logique > physique`, puis plus petit id) est résolu, les autres
   parcs logiques sont silencieusement ignorés (`policies.json` machine-wide ne porte qu'une config par install).
   Statu quo assumé, documenté dans le docblock du provider + `state-providers.md`.
5. **Redirection de profil navigateur + roaming = HORS 27.4.** Sujet **roaming** serveur (déplacement de
   répertoire de profil, pas une policy `policies.json`). Renvoyé au **domaine roaming/`WorkstationEnvironment`
   (26.x / story de suivi)**. Pas de champ `profile_location` au payload, pas d'usage de
   `WorkstationEnvironmentResolver`, **pas de dépendance 26.1**. `clean_profiles`/`RoamingProfileService`
   restent hors-scope (cohérent : tout le sujet roaming sort de 27.4).
6. **PAS de mode strict/default** (27.8 — drift STRICT inconditionnel). Le provider n'a pas de `mode()` (retiré
   de l'interface), l'item a 4 clés.

## Acceptance Criteria

### AC1 — Type `app_config` servi : provider lecture seule + identifiant figé + golden (FR21, FR2)

**Given** le type `app_config` (identifiant DÉJÀ figé contrat §7, NFR12), `semantics` = `aggregate` par
`app_kind` (décision n° 2), `scope` = `machine` (décision n° 4 — correctif post-review #1 ; `policies.json`
machine-wide écrit par SYSTEM, résolution PAR PARC niveaux 1-4, `$user = null`)
**When** `AppConfigStateProvider` est enregistré dans `AgentServiceProvider` et le `StateCompiler` compile
**Then** son type est servi **sans aucune modification du compilateur** (AC1 pattern 23.4) ; le provider lit
**en lecture seule** les policies existantes (`app_customizations` via
`AppCustomizationService::resolvePoliciesForMachine`) — **aucun write métier, aucun appel
AD/LdapRecord/APCu/Cache** (critère Keycloak, grep `ldap|apcu|samba-tool|Cache::` VIDE en review)
**And** le payload v1 (décision n° 1) porte les **policies résolues CONCRÈTES** (jamais un `customization_id`
ni un scope), **sans float** ; le golden `tests/Fixtures/Agent/state.v1.json` est mis à jour SCIEMMENT (item
`app_config` ajouté, **4 clés** `type/semantics/payload/hash` — `mode` retiré par 27.8), avec **bump
documenté** du hash figé `ContractV1Test::FROZEN_STATE_HASH` + son jumeau Go (`hasher_test.go::frozenStateHash`)
à la **MÊME valeur** (valeur courante du tree `77fb548a…` — re-vérifier d'abord).

### AC2 — Convergence via mécanisme enterprise natif `policies.json` + idempotence (FR21, idée #14)

**Given** des policies d'app résolues (Firefox/Thunderbird)
**When** l'agent converge
**Then** les policies sont appliquées par le **mécanisme enterprise NATIF `policies.json`** posé en **fichier
atomique** au chemin natif de l'install de l'app — **jamais un script bricolé ni un patch de fichier de config
user** (piège n° 7)
**And** `apply` est **idempotent** (deux passes sur état stable = `compliant`, zéro écriture) ; la policy d'une
app est posée à l'octet près = la cible résolue serveur.

### AC3 — App butée sans option enterprise = limite connue (« match nul » assumé) (AC epic)

**Given** une application qui écrit localement un réglage **sans aucun mécanisme enterprise natif** (pas de
`policies.json` exploitable)
**When** ce réglage est demandé
**Then** l'agent **ne force RIEN et n'invente RIEN** (pas de patch de config user bricolé, pas de hook) — le
réglage est **documenté comme limite connue** (`docs/agent/state-providers.md` + QA), conforme au « match nul »
assumé du brainstorming
**And** l'invariant « un handler n'écrit que via un mécanisme enterprise documenté » est respecté (zéro
chorégraphie dispersée).

### AC4 — Isolation des erreurs : chemin verrouillé/app absente → `error`, les autres continuent (FR18)

**Given** un échec d'application pour une app (chemin `policies.json` verrouillé, app non installée, **ou
`policies.json` étranger hors-périmètre sur une app cible** — review #7)
**When** le **moteur SYSTEM** (portée machine) exécute `apply`
**Then** le statut `error` + **detail exploitable** sont rapportés pour CET item/app, **les autres apps et
types continuent** (isolation `engine.go::RunPass`, réutilisée — jamais réimplémentée), retry au prochain cycle
(level-triggered)
**And** un échec `app_config` n'empêche NI les autres apps NI les autres types
(shortcuts/wallpaper/registry/associations/printers/drives).

### AC5 — Level-triggered : une policy retirée des règles est dé-appliquée au passage suivant (FR21, FR18)

**Given** une config d'app posée par l'agent, puis sa règle retirée côté serveur (policy désassignée du scope)
**When** l'agent converge au passage suivant
**Then** la config gérée est **dé-appliquée** (`policies.json` SambaEdu retiré/vidé) — convergence, **pas
accumulation** ; drift STRICT (réel ≠ cible → réimposé)
**And** une config posée **par l'utilisateur hors périmètre SambaEdu** n'est **JAMAIS** supprimée ni écrasée
(marqueur de périmètre géré, iso 27.1 n° 5 / 27.2 n° 8) — mais si elle occupe le chemin d'une app cible, le
conflit est rapporté **`error`** (review #7 : « policies.json hors-périmètre présent, policy agent non
appliquée »), jamais `compliant` trompeur.

### AC6 — Handler agent Go + golden + reporting par item (FR21, FR18, NFR13)

**Given** l'état cible contenant des items `app_config`
**When** la boucle du compagnon exécute `test` puis `apply` si écart
**Then** un handler `app_config` (`agent/shared/handler_app_config.go` logique pure testable hôte +
`agent/windows/handler_app_config_windows.go` écriture `policies.json`) est enregistré dans le moteur du
compagnon (`companion_windows.go`) ; isolation par item ; exécution dans l'ordre du payload serveur
**And** les statuts sont rapportés (`compliant|drift|error` — **3 statuts**, plus de `drifted_allowed` après
27.8) dans `POST /report` conforme au golden `report.v1.json` (hash d'agrégat conventionnel `AggregateHash`,
ordre serveur)
**And** convention de hash d'agrégat = concat des hashes opaques (jamais de recalcul depuis la sérialisation
agent — piège réutilisé de 24.6/27.1) ; stub `!windows` (no-op) pour le handler spécifique.

### AC7 — Tests : PHPUnit serveur + go test agent, baselines intactes (NFR13)

**Then** côté **Laravel** : `tests/Unit/Services/Agent/AppConfigStateProviderTest.php` (mailles, un item par
`app_kind`, payload = policies résolues concrètes, **jamais d'id de scope/customization**, **lecture seule,
zéro AD/APCu/Cache** — NFR7) ; test compilateur (dédup aggregate par contenu réutilisée) ; `ContractV1Test`
(golden cohérent, hash bumpé croisé, item 4 clés) ; non-régression `--filter Agent` sur `/vm` (baseline relevée
au début du dev)
**And** côté **agent Go** : `agent/shared/handler_app_config_test.go` (set cible, test/apply idempotent,
suppression level-triggered, marqueur de périmètre géré, app butée no-op, item `error` isolé, machine d'états §5
STRICT table-driven, mécanisme `policies.json`) ; `go test ./...`, `go vet` (linux + `GOOS=windows`),
cross-compile verts sur l'hôte ; spécifique Windows validé cross-compile + lab humain
**And** golden files cohérents serveur (PHPUnit) ET agent (`go test`) — tests croisés (NFR13).

### AC8 — Documentation + QA (append-only)

**Then** `docs/agent/state-providers.md` : section `app_config` (payload v1, apps livrées = Firefox/Thunderbird,
mécanisme natif `policies.json`, **limite connue « app butée »**, isolation des erreurs) ; `agent/README.md` :
handler `app_config` ; `docs/agent/contract-v1.md` §7 : payload `app_config` documenté (le type est déjà
réservé §7 — ajouter la sous-section payload, **sans `mode`**)
**And** `docs/qa/domains/agent.md` enrichi **append-only** (nouvelle section `## Story 27.4` sans renuméroter) :
config navigateur appliquée par parc+user via `policies.json` natif, suppression level-triggered, drift STRICT
réimpose, app butée = non géré documenté, isolation chemin verrouillé ; ligne 27.4 dans `docs/qa/README.md`
**And** restent **INTOUCHÉS** : tout le canal legacy (export FS `/etc/sambaedu/applications/`, GPO, scripts),
`AppCustomizationService` (lu/réutilisé, jamais modifié pour le canal agent), les adapters 4.8 (lus), le PHP
serveur agent figé hors provider/registry, `contract-v1.md §7` (type déjà figé), `agent/shared/hasher.go` /
`engine.go::ResolveItemStatus` (réutilisés) ; note : ce handler est le successeur natif du canal export-FS des
policies (legacy intouché, meurt en 27.6).

## Tasks / Subtasks

- [x] **T0 — Re-confirmer l'audit NFR7 du socle 4.8 (déjà fait)** (toutes AC) — *décisions n° 1-6*
  - [x] **Audit NFR7 (re-confirmation)** : grep `Cache::|apcu|Ldap|samba-tool` sur le chemin
        `resolvePoliciesForMachine()` et ses dépendances — **DÉJÀ confirmé PG + config-pur** (voir
        `## Audit NFR7`). Re-vérifier dans le tree au moment du dev (rien n'a régressé). **Garde-fou central de
        revue.** ✅ Confirmé : les hits `Cache::|apcu` sont TOUS dans `CacheAppContextRepository.php` /
        `CacheAppContextWriter.php` / `Contracts/*` (AUTRE canal legacy-port). `AppCustomizationService.php` +
        adapters Firefox/Thunderbird = VIDE.
  - [x] Confirmer l'invariant « policies concrètes au payload, jamais d'id de scope/customization » (iso 27.3).
        ✅ Payload = `{app_kind, policies}` (policies résolues concrètes), aucun `customization_id`/scope.
  - [x] Vérifier qu'aucune valeur de policy n'est un **float** (normaliser → string si besoin, contrat §4).
        ✅ `AppConfigStateProvider::normalizePolicies()` convertit tout float en string (récursif).

- [x] **T1 — `AppConfigStateProvider` (serveur, lecture seule)** (AC1, AC2)
  - [x] `app/Services/Agent/Providers/AppConfigStateProvider.php` (`final`, `declare(strict_types=1)`, calqué
        sur `WallpaperStateProvider` (lecture de table métier) ou `OverlayStateProvider` (aggregate/session)).
        `type()` → `AppCustomization::TYPE_APP_CONFIG` (`'app_config'`, constante figée ajoutée au modèle iso
        `Wallpaper::TYPE_WALLPAPER`), `semantics()` → `Aggregate`, `scope()` → `Session`. **PAS de `mode()`**.
  - [x] `itemsFor(TargetContext $ctx)` : pour chaque `AppKind::cases()`, résout les policies via
        `AppCustomizationService::resolvePoliciesForMachine($wg, $user, $kind, 'windows')` (PG-pur). **Impédance
        instruite** : le provider collapse l'axe WG en feedant le WG **gagnant en précédence** (`logique >
        physique`, aligné `StateCompiler::specificity()`) → un candidat par app ; payload = policies résolues
        concrètes `{app_kind, policies}` ; zéro précédence/tri (D2 = compilateur).
  - [x] **NFR7** : aucun `Cache::`/APCu/AD dans le provider (grep code VIDE). **Pas de float** (normalizePolicies).
  - [x] Enregistrer dans `AgentServiceProvider::register()` (une ligne dans le tableau du `StateCompiler`).

- [x] **T2 — Handler agent Go `app_config`** (AC2, AC3, AC4, AC5, AC6)
  - [x] `agent/shared/handler_app_config.go` : logique PURE (set cible des configs d'app, décision test/apply,
        marqueur de périmètre géré `_sambaedu_managed`, **un seul mécanisme `policies.json`**), testable hôte.
        Aggregate : `Test` = chaque app cible a son `policies.json` géré conforme ET aucun orphelin géré
        subsiste ; `Apply` = (ré)écrire manquants/dérivés + retirer les gérés sortis des règles (idempotent,
        level-triggered, drift STRICT). Item `error` isolé (chemin verrouillé/app absente) — effort maximal.
        **App butée / `policies.json` hors périmètre = jamais touché** (AC3/AC5), jamais de bricolage.
  - [x] `agent/windows/handler_app_config_windows.go` : **écriture `policies.json`** (fichier atomique tmp+rename
        au chemin natif `%ProgramFiles%\Mozilla Firefox|Thunderbird\distribution\policies.json`). **go.mod
        inchangé** (pas de nouvelle dépendance). NB : le package `agent/windows` ne compile que `main_stub.go`
        sur Linux (tous les autres fichiers = `_windows.go` implicite) → pas de stub par-handler nécessaire (iso
        convention existante shortcuts/registry/printers) ; la logique pure shared est OS-agnostique et testée hôte.
  - [x] Enregistrer `"app_config"` dans la map `Handlers` du **`MachineEngine` SYSTEM** (`main_windows.go`) —
        portée **machine** (correctif post-review #1 ; initialement câblé au compagnon/session, déplacé après le
        comparatif legacy — `policies.json` machine-wide admin-write).
  - [x] Réutiliser `engine.go::ResolveItemStatus` (§5 STRICT) + `AggregateHash` — **non réimplémenté**.

- [x] **T3 — Golden files (sciemment) + bump hash figé croisé** (AC1, AC6)
  - [x] Valeur courante `FROZEN_STATE_HASH` re-vérifiée = `77fb548a…` (PHP L86 + Go L53, tests verts avant bump).
        Item `app_config` ajouté à `state.v1.json` (portée **`machine`** après correctif post-review #1 ;
        initialement `session`), 4 clés, aggregate, payload `{app_kind, policies}` concret sans float.
        `report.v1.json` INCHANGÉ (les 3 statuts y sont déjà illustrés). Hashes recalculés via le **StateHasher Go
        réel** (croisé PHP par les tests existants) : item `app_config` = `cb347596…636ef8c2` (inchangé), état =
        **`6f0ff33e…dff377fb`** (post-review machine). **Bumpés à la MÊME valeur** PHP + Go ; compteurs d'items
        ajustés (8→9 ; machine 1→2, session 7→6) dans `ContractV1Test`, `hasher_test.go`, `contract_test.go`.
        Bump documenté (évolution mineure §9 + correctif post-review) dans les commentaires.

- [x] **T4 — UI : AUCUNE nouvelle UI** (—)
  - [x] L'édition des policies existe (4.8, `app-customizations`) — **non modifiée**. Redirection de profil
        hors-scope (roaming). **no-op confirmé** (aucun fichier UI touché).

- [x] **T5 — Tests** (AC7)
  - [x] PHPUnit : `AppConfigStateProviderTest` (un item par `app_kind`, payload concret jamais d'id de scope,
        merge parc+user, `logique > physique`, machine-only, broadcast sans WG, pas de float, lecture seule).
        `ContractV1Test` bumpé (hash + item 4 clés + 9 items). **À VALIDER /vm par Henri** (vendor absent du
        worktree — voir Dev Agent Record). La dédup aggregate par contenu est couverte par `StateCompilerTest`
        existant (le provider utilise le chemin aggregate standard, zéro modif compilateur).
  - [x] Go : `handler_app_config_test.go` (set cible, idempotence, mécanisme policies.json par app, drift STRICT,
        suppression level-triggered, marqueur de périmètre — hors-scope jamais touché, app butée/app_kind inconnu
        error isolé, item error isolé, §5 STRICT table-driven via le moteur, enveloppe invalide). `go test ./...`,
        `go vet` (linux+windows), cross-compile **VERTS sur l'hôte**.

- [x] **T6 — Documentation + QA** (AC8)
  - [x] `docs/agent/state-providers.md` (section `app_config` + mécanisme natif `policies.json` + limites
        connues) ; `docs/agent/contract-v1.md` §7.3 (payload `app_config`, sans `mode`) ; `agent/README.md`
        (handler `app_config`) ; `docs/qa/domains/agent.md` `## Story 27.4` append-only (7 scénarios + checklist) ;
        ligne 27.4 dans `docs/qa/README.md`.

- [x] **T7 — Validation finale** (AC1, AC7)
  - [x] `php -l` sur tous les fichiers PHP (OK) ; grep critère Keycloak (`ldap|apcu|samba-tool|Cache::`) sur
        `AppConfigStateProvider` en CODE → **VIDE** (seuls les commentaires citent l'audit) ; « zéro retrofit
        legacy » : aucun fichier du canal legacy export-FS touché ; `AppCustomizationService`/adapters LUS, non
        modifiés (seul `AppCustomization.php` gagne la const `TYPE_APP_CONFIG`).
  - [x] `go test ./...` + `go vet` (linux+windows) + cross-compile **VERTS** sur l'hôte. **Aucune migration**.
  - [ ] **Actions /vm (PAS auto, ACTION HENRI)** : rejouer la suite PHPUnit `--filter Agent` (vendor absent du
        worktree → PHPUnit non lancé en local ; commandes exactes dans le Dev Agent Record).
  - [ ] **Validation lab (poste Windows) : ACTION HUMAINE (Henri)** — policies Firefox/Thunderbird appliquées
        via `policies.json`, policy modifiée à la main **réimposée** (drift STRICT), policy retirée des règles
        **dé-appliquée** (level-triggered), app butée = réglage non posé sans erreur bloquante, isolation
        (chemin verrouillé → `error`, le reste converge), `policies.json` hors SambaEdu **jamais écrasé**.

## Dev Notes

### Périmètre — livré / hors-scope

| Livré (27.4) | Hors-scope (story) |
|---|---|
| `AppConfigStateProvider` (lecture seule de `app_customizations` via `resolvePoliciesForMachine` PG-pur) | Décommissionnement canal legacy export-FS policies / GPO → **27.6** |
| Handler agent Go `app_config` (Windows, **`policies.json` UNIQUEMENT**, level-triggered, isolation) | Modification de `AppCustomizationService` / adapters 4.8 (lus seulement) |
| Golden files mis à jour + bump hash croisé documenté (PHP + Go) | Création d'une table de policies (la 4.8 existe — interdit, doublon) |
| Tests PHPUnit + go test + QA append-only | Installation des apps (Firefox/Thunderbird présents) → **27.5** (couplage = limite connue) |
| Limite connue « app butée » documentée | **Chrome/Edge** (aucune policy legacy — net-new sans réf) ; **redirection de profil navigateur** (roaming → 26.x / story de suivi) |
| | Mode strict/default (RETIRÉ en 27.8 — ne pas réintroduire) ; ciblage par CN AD (`ad_*`) exclu NFR7 ; `clean_profiles`/`RoamingProfileService` (roaming) |

### 🔴 Le socle 4.8 — ce qu'on LIT (ne PAS réinventer, ne PAS modifier)

[Source: `app/Models/AppCustomization.php` ; `app/Services/AppCustomization/AppCustomizationService.php` ;
`app/Services/AppCustomization/Contracts/AppPolicyAdapter.php` ; `app/Enums/AppKind.php` ;
`app/Services/AppCustomization/Firefox/FirefoxPolicyAdapter.php` ;
`app/Services/AppCustomization/Thunderbird/ThunderbirdPolicyAdapter.php` ;
`resources/views/pages/parc-settings/app-customizations/index.blade.php`]

- **Table** `app_customizations` : `app_kind` (`AppKind` enum), morph `customizable` (WG/UserGroup/User + défaut
  NULL/NULL `is_default`), `policies_json` (array). **C'est la table métier de `app_config`.** Migration
  `2026_04_21_100000_create_app_customizations_table.php` (déjà en prod).
- **`AppCustomizationService::resolvePoliciesForMachine(?WorkstationGroup $wg, ?User $user, AppKind $kind,
  string $os): array`** : applique les 6 niveaux (template → auto proxy/popup/DNS → défaut étab → WG →
  UserGroups → User, merge profond `mergeOverrides`) et retourne les policies fusionnées. ⚠️ Prend **UN** WG —
  impédance avec les mailles Epic 27 (piège n° 6). **PG + config-pur** (audit NFR7 fait) : `AppCustomization::query()`
  directs ; `systemConfig()` = `config()` ; `userGroups()` = `BelongsToMany` PG ; le cache APCu vit dans
  `CacheAppContextRepository` (AUTRE chemin, legacy-port).
- **`AppKind::cases()`** = `Firefox`, `Thunderbird` (extensible : un case + un adapter + un SFC). **Pas de
  Chrome/Edge** (le legacy n'en gère aucune policy — comparatif).
- **Délivrance legacy** : `exportToFs()` écrit `/etc/sambaedu/applications/{kind}/{key}.json` (consommé par
  GPO/scripts). **Ce handler natif le remplace.** Zéro retrofit : ne pas câbler l'export-FS, il meurt en 27.6.

### Le pattern Epic 27 — ce qu'on imite À L'IDENTIQUE (ne PAS réinventer ; zéro modif moteur)

[Source: `app/Services/Agent/Contracts/StateProvider.php` (interface `type/semantics/scope/itemsFor`, **plus de
`mode()` post-27.8**) ; `app/Services/Agent/StateCandidate.php` (readonly : `maille, payload, updatedAt,
sourceId` — **plus de `mode`**) ; `app/Services/Agent/TargetContext.php`
(`physicalGroupIds/logicalGroupIds/userGroupIds/workstationGroupIds()/user`, résolu une fois) ;
`app/Services/Agent/StateCompiler.php` (dédup aggregate par contenu) ;
`app/Providers/AgentServiceProvider.php` (tableau des providers) ; `app/Enums/StateScope.php`
(`machine|session|machine_user`) ; `app/Enums/ResourceSemantics.php` (`aggregate|exclusive`).]

- **Discipline D2** : le provider rend des candidats **bruts par maille**, **aucune** précédence/tri/dédup —
  la précédence vit UNIQUEMENT dans `StateCompiler`. ⚠️ NUANCE `app_config` : la hiérarchie 6-niveaux 4.8 est
  faite par `resolvePoliciesForMachine` (SERVEUR, dans le service métier), PAS par le compilateur — le provider
  émet **un candidat par app déjà résolu**, le compilateur ne fait que dédoublonner par contenu (aggregate).
  Ce n'est pas une violation de D2 : la résolution est métier (comme `WallpaperResolver`), pas de la précédence
  de maille.
- **Modèles de provider à copier** : `OverlayStateProvider` (aggregate/session, étiquetage par maille) ;
  `WallpaperStateProvider` (lecture de table métier, payload résolu, exclusive/session).
- **Tokens** `<se4fs>`/`<user>` substitués localement par l'agent (jamais de secret en payload) — iso 27.1 n° 3.
  (Note : après recadrage, le payload `policies.json` ne porte normalement aucun token sensible.)
- **PG-pur** : `TargetContext` Postgres-only ; jamais d'AD/APCu/Cache dans un provider (NFR7).

### Le socle agent Go 24.6/27.x — ce qu'on consomme

[Source: `agent/shared/engine.go` (`RunPass`, `ResolveItemStatus` §5 STRICT post-27.8, `AggregateHash`,
isolation par item) ; `agent/shared/handler_shortcuts.go` + `agent/windows/handler_shortcuts_windows.go`
(modèle handler **fichier** : marqueur de périmètre géré, level-triggered, écriture fichier atomique) ;
`agent/windows/companion_windows.go` (map `Handlers`, moteur compagnon).]

- `Engine.RunPass` + `ResolveItemStatus` (machine d'états §5 — **STRICT inconditionnel** depuis 27.8, signature
  sans `mode`/`lastAppliedHash`) + `AggregateHash` (concat hashes opaques, ordre serveur) — **réutilisés,
  jamais réécrits**. L'isolation par item (AC4) EST le comportement de `RunPass`.
- `handler_shortcuts` est le **modèle** pour l'écriture de **fichier** (`policies.json`) + marqueur de périmètre
  géré. **Plus de référence au `handler_registry`** (la branche registre est retirée du périmètre).
- Enregistrement (correctif post-review #1) : map `type → Handler` dans le **`MachineEngine` SYSTEM**
  (`main_windows.go`, iso le handler `registry` HKLM machine) — PAS le compagnon. `policies.json` est
  machine-wide (admin-write) → seul SYSTEM l'écrit (un compagnon user prendrait ACCESS_DENIED).
- Conventions : suffixe `_windows.go` + stub `!windows`, écriture atomique, applied-state per-user, `go test`
  hôte, interop native préférée au shell-out, `go.mod` inchangé.

### Contrat & golden

[Source: `docs/agent/contract-v1.md` §3.2 (item 4 clés post-27.8 : `type/semantics/payload/hash`), §7
(identifiants figés — `app_config` DÉJÀ réservé), §8 (type absent ≠ payload vide), §9 ;
`tests/Fixtures/Agent/state.v1.json` ; `report.v1.json` ; `tests/Unit/Services/Agent/ContractV1Test.php` (L86) ;
`agent/shared/hasher_test.go` (L53)]

- `app_config` **DÉJÀ figé** au §7 : ne PAS créer d'entrée §7, ne PAS renommer. Ajouter UNIQUEMENT la
  **sous-section payload** (§7.x), **sans `mode`**.
- L'item du contrat a **4 clés** depuis 27.8 (`mode` retiré). **NE PAS** réintroduire `mode`/`drifted_allowed`.
- `aggregate` = union (l'agent applique l'union des apps). Payload owné par CETTE story (§3.2) → évolution
  **mineure** (item ajouté), forward-compatible. Le golden est **illustratif** mais sa frontière (hash) est
  figée → bump SCIEMMENT documenté, croisé PHP (`FROZEN_STATE_HASH`) + Go (`frozenStateHash`) à la MÊME valeur
  (NFR13). **Valeur COURANTE du tree = `77fb548ac9b1f0604afce2a0c7d0316379391ef2e182a95a005b979f3fa5e3bd`** —
  re-vérifier au début du dev (plusieurs stories review l'ont bougée).
- §8 : type absent ≠ payload vide. Un poste sans règle `app_config` → type ABSENT (l'agent ne touche pas).

### Enums & contexte (réutilisés)

[Source: `app/Enums/StateScope.php` (Session/Machine/MachineUser) ; `ResourceSemantics.php`
(Aggregate/Exclusive) ; `app/Services/Agent/TargetContext.php` ; `app/Enums/AppKind.php`]

- `StateScope::Session` (décision n° 4) ; `ResourceSemantics::Aggregate` (décision n° 2).
- `TargetContext` : `physicalGroupIds`, `logicalGroupIds`, `userGroupIds`, `workstationGroupIds()`, `user`
  (nullable machine-only). **Consommer, jamais re-requêter.**
- `AppKind::cases()` = `Firefox`, `Thunderbird` (liste des apps configurables — pas de Chrome/Edge).

### Project Structure Notes

- Provider → `app/Services/Agent/Providers/AppConfigStateProvider.php` ; registry →
  `AgentServiceProvider::register()` (insérer dans le tableau du `StateCompiler`).
- Constante de type figée → sur un modèle (ex. `AppCustomization::TYPE_APP_CONFIG = 'app_config'`) ou une const
  dédiée, iso `Wallpaper::TYPE_WALLPAPER` / `RegistrySetting::TYPE_REGISTRY`.
- Agent → `agent/shared/handler_app_config.go` (+ test) ; `agent/windows/handler_app_config_windows.go`
  (+ stub) ; enregistrement `companion_windows.go`.
- Golden → `tests/Fixtures/Agent/state.v1.json` (+ `report.v1.json`) ; hash figé `ContractV1Test` +
  `hasher_test.go`.
- UI → **AUCUNE nouvelle UI** (policies = 4.8 existant ; pas de redirection à porter — hors-scope).
- Doc → `docs/agent/state-providers.md`, `docs/agent/contract-v1.md` (§7.x payload), `agent/README.md`,
  `docs/qa/domains/agent.md`, `docs/qa/README.md`.

### Environnement de dev — règles VM / worktree

- Code à la **RACINE** (`app/`, `agent/`, … — plus de sous-dossier `laravel/`) ; édité sur l'hôte, sync inotify
  auto, **jamais de sync manuelle**.
- **Go = hôte uniquement** (`~/go-toolchain/go/bin/go`, package main = `agent/windows`). PHPUnit sur `/vm`.
- **AUCUNE migration** (table 4.8 existe, aucun champ neuf). Pas de `config:cache`/`route:cache` attendu.
- **SQLite n'applique pas les varchar** : viser `/vm` pour la non-régression finale.
- **Jamais** d'interaction VM depuis ce worktree git.

### References

- [Source: `_bmad-output/planning-artifacts/epics-agent-desired-state.md` L738-754] — Story 27.4, AC d'origine ;
  FR21 ; idée #14 (mécanismes enterprise natifs) ; « match nul » assumé.
- [Source: `_bmad-output/planning-artifacts/epics-agent-desired-state.md` L681] — pattern Epic 27 (provider +
  handler + id figé + golden), du simple au dur, extinction en bloc 27.6, ZÉRO retrofit legacy.
- [Source: `_bmad-output/planning-artifacts/architecture-agent-desired-state.md` L250-272] — D1 (projection
  StateProviders, pas de table générique ; type avec table métier = on la lit), D2 (précédence au compilateur).
- [Source: legacy SE4 `includes/firefox.inc.php` ; `gpo/firefox_out.php` ; `conf_params.php` ~L559-573 ;
  `gpo/del_roam.php`] — comparatif : FF/TB `policies.json` servi HTTP (template + auto + overrides `list_*` +
  APCu `apps.<id>`) ; Chrome = redirection de profil (roaming) sans policy ; Edge inexistant.
- [Source: `_bmad-output/implementation-artifacts/27-1-handler-raccourcis-convergence-bureau.md`] — pattern
  complet (provider + handler Go + id figé + golden), marqueur de périmètre géré, tokens, bump hash croisé.
- [Source: `_bmad-output/implementation-artifacts/27-2-handlers-lecteurs-imprimantes.md`] — structure de story
  handler aboutie (lecture de service métier non modifié), isolation des erreurs, golden bumpé.
- [Source: `_bmad-output/implementation-artifacts/27-3-handler-registre-catalogue.md` ;
  `27-3bis-handler-associations-userchoice.md`] — invariant « payload concret jamais un id de catalogue/scope »,
  bump golden croisé.
- [Source: `_bmad-output/implementation-artifacts/27-8-retrait-mode-strict-default-drift-policy.md`] — drift
  STRICT inconditionnel : item contrat 4 clés, plus de `mode`/`drifted_allowed`, interface `StateProvider` sans
  `mode()`, 3 statuts au report. **NE PAS réintroduire le mode.**
- [Source: `app/Models/AppCustomization.php` ; `app/Services/AppCustomization/AppCustomizationService.php` ;
  `Contracts/AppPolicyAdapter.php` ; `app/Enums/AppKind.php` ; `Firefox/FirefoxPolicyAdapter.php` ;
  `Thunderbird/ThunderbirdPolicyAdapter.php`] — table métier + service de résolution + adapters (lus, non
  modifiés).
- [Source: `app/Services/Agent/Contracts/StateProvider.php` ; `StateCandidate.php` ; `TargetContext.php` ;
  `StateCompiler.php` ; `app/Providers/AgentServiceProvider.php`] — pattern provider + compilateur + registry.
- [Source: `agent/shared/engine.go` ; `handler_shortcuts.go` ; `agent/windows/companion_windows.go`] — moteur
  §5 STRICT, modèle handler (fichier), enregistrement.
- [Source: `docs/agent/contract-v1.md` §3.2/§7 (`app_config` réservé)/§8/§9 ;
  `tests/Fixtures/Agent/state.v1.json` ; `report.v1.json` ; `ContractV1Test.php` (L86) ; `hasher_test.go`
  (L53)] — contrat + golden + hash croisé figé (`77fb548a…`).
- [Source: mémoires `project_applications_vs_wpkg_imperative_declarative`,
  `project_wallpaper_library_native_overlay_direction`, `project_nomade_local_fr29_closed`,
  `project_drift_policy_strict_only`, `project_agent_desired_state_direction`].

## Dépendances

| Story | Rôle pour 27.4 | Statut (sprint-status.yaml) | Bloquant ? |
|-------|----------------|------------------------------|------------|
| **23.4** — StateCompiler / StateProvider / TargetContext | Pattern provider + compilateur + contexte que 27.4 étend (zéro modif compilateur) | `done` | Non (consommé) |
| **23.5** — contrat v1 + endpoint get-state/etag | `app_config` déjà figé §7 ; endpoint de compilation d'état existant | `done` | Non (consommé) |
| **27.8** — drift STRICT (retrait du mode) | Contrat 4 clés / 3 statuts ; interface `StateProvider` sans `mode()` ; STRICT inconditionnel | `done` | **Prérequis fort** — le contrat/agent sont post-27.8 ; le provider n'a PAS de `mode()`, l'item a 4 clés, **ne pas réintroduire le mode**. |
| **24.6** — agent Go compagnon + handlers + moteur §5 | Moteur de convergence, `AggregateHash`, isolation par item, enregistrement handlers, écriture fichier atomique | `done` | Non (consommé) |
| **4.8** — `app_customizations` + `AppCustomizationService` + adapters | **Table métier + résolution de policies LUES par le provider** (le `wallpapers` de cette story) | `done` (en prod) | Non (lu/réutilisé, jamais modifié) |
| **27.1** — handler raccourcis + pattern | Fournit le PATTERN complet (provider + handler Go fichier + golden), le marqueur de périmètre géré, le bump hash croisé | `review` | **Prérequis fort** (pattern consommé). En review (Henri teste les lots) : dev autorisé avec rebase si correctifs. |
| **27.2** — drives/printers | Modèle de story handler le plus abouti (lecture de service métier non modifié, isolation des erreurs, golden bumpé) | `review` | Non (pattern consommé) |
| **27.3 / 27.3bis** — registre / associations | Invariant « payload concret jamais un id de catalogue », bump golden croisé | `review` | Non (pattern consommé ; relever la valeur courante du golden — recouvrement) |
| **27.5** — applications/WPKG | Installation des apps (Firefox/Thunderbird présents pour que la policy ait un effet) | `backlog` | Non (découplé ; couplage = limite connue documentée) |

> **Spin-off (hors 27.4)** : **redirection/roaming du profil Firefox (Mécanisme B) + Chrome/Edge** → renvoyé au
> **domaine roaming / `WorkstationEnvironment` (26.x / story de suivi)**. Précision post-review #1 : le
> « par-user » de Firefox n'est PAS dans `policies.json` (Mécanisme A, machine-wide par-parc, scope `machine` =
> 27.4) mais dans le **profil** (Mécanisme B = jonctions/redirection du dossier profil vers le home, roaming
> serveur). La redirection de profil navigateur (Chrome/Edge inclus) et tout le sujet roaming sortent de 27.4 —
> c'est un déplacement de répertoire de profil, pas une policy `policies.json`. **La dépendance 26.1 est RETIRÉE
> de 27.4** (plus aucun usage).
>
> **Recouvrement à surveiller** : le golden/hash (`state.v1.json`, `FROZEN_STATE_HASH` PHP `77fb548a…` +
> `frozenStateHash` Go) bougent au fil de l'Epic 27 (27.1/27.2/27.3/27.3bis en review). **Relever la valeur
> courante du tree AVANT tout bump** et rebaser si une story review touche le golden/le compilateur.

## Questions pour Henri — RÉSOLUES (2026-06-17, validation de story + comparatif legacy SE4)

1. **Sémantique du type** → ✅ **`aggregate` par `app_kind`** (un candidat par app, policies résolues serveur,
   dédup par contenu au compilateur).
2. **Source de résolution** → ✅ **réutiliser `AppCustomizationService::resolvePoliciesForMachine($wg, $user,
   $kind, $os)` tel quel** (audit NFR7 **DÉJÀ FAIT** : PG + config-pur — `AppCustomization::query()` directs,
   `systemConfig()` = `config()`, `userGroups()` = `BelongsToMany` PG ; le cache APCu `CacheAppContextRepository`
   sert l'AUTRE canal). T0 = simple re-confirmation par grep.
3. **Scope** → ✅ **`machine`** (correctif post-review 2026-06-17 #1 — révise la réponse initiale `session`). Le
   comparatif legacy Firefox complet montre DEUX mécanismes : Mécanisme A = `policies.json` (machine-wide,
   admin-write, **par-parc**, écrit par SYSTEM) = 27.4 ; Mécanisme B = profil user (roaming) = hors 27.4. Un
   `policies.json` sous Program Files ne peut PAS être écrit par un compagnon user (ACCESS_DENIED) ni porter du
   par-user. La portée est donc **`machine`** (service SYSTEM), résolution **par parc** niveaux 1-4
   (`$user = null`). Le par-user de Firefox = le profil (Mécanisme B), pas `policies.json`. _(Le framing initial
   « session combine parc + user » était FAUX pour la LIVRAISON d'un fichier machine non-writable par l'user.)_
4. **Périmètre des apps & recadrage Chrome/Edge/redirection** → ✅ **FF/TB `policies.json` ONLY**. Après
   comparatif legacy SE4 (2026-06-17), Henri a tranché : (a) **Chrome/Edge RETIRÉS** — le legacy ne gère AUCUNE
   policy Chrome/Edge ; ce serait du net-new sans référence → **un seul mécanisme handler `policies.json`** (plus
   de mécanisme registre) ; (b) **redirection de profil navigateur RETIRÉE** — sujet **roaming** serveur (pas une
   policy client) → renvoyée au domaine roaming/`WorkstationEnvironment` (26.x / story de suivi) → **plus de
   champ `profile_location`, plus d'usage de `WorkstationEnvironmentResolver`, dépendance 26.1 supprimée** ;
   (c) `clean_profiles`/`RoamingProfileService` restent hors-scope (cohérent avec la sortie du sujet roaming).

## Recommandation Modèle Dev

**Recommandation : `opus`** (Henri a tranché).

Justification : périmètre **resserré** (un seul mécanisme `policies.json`, plus de Chrome/Edge ni de redirection
de profil) et **service de résolution déjà audité PG-pur** (réutilisable tel quel) — mais la story reste
**non triviale** et justifie `opus` : (a) l'**intégration au socle métier 4.8** (`AppCustomizationService`,
hiérarchie 6-niveaux, adapters) doit être lue **sans la casser**, et l'**impédance** entre le modèle « WG
unique » de 4.8 et le modèle « mailles → compilateur » de l'Epic 27 doit être instruite proprement ; (b) la
**discipline NFR7** (provider PG-pur, grep critère Keycloak vide) reste le garde-fou central ; (c) l'**évolution
sciemment du golden file** (frontière de contrat figée, bump du hash croisé PHP↔Go `77fb548a…`, tests croisés,
relever la valeur courante d'un tree mouvant) ; (d) l'invariant « policies concrètes au payload jamais un id de
scope » + « app butée = limite connue, zéro bricolage ». Le risque majeur — fuite APCu dans un provider devant
rester PG-pur, ou doublonner la table 4.8 — exige le raisonnement le plus rigoureux. NB : les stories agent
desired-state d'Epic 27 ont toutes été développées par `opus` récemment (Fable indisponible). `opus`.

## Dev Agent Record

### Agent Model Used

`claude-opus-4-8[1m]` (subagent BMAD dev-story).

### Debug Log References

- **T0 — Re-confirmation NFR7 (grep).** `grep -rniE "Cache::|apcu|Ldap|samba-tool|remember\(" app/Services/AppCustomization/`
  → tous les hits dans `CacheAppContextRepository.php` / `CacheAppContextWriter.php` / `Contracts/AppContext*.php`
  (l'AUTRE canal, legacy-port `applications.inc.php`). **`AppCustomizationService.php` (contenant
  `resolvePoliciesForMachine`) + `FirefoxPolicyAdapter` + `ThunderbirdPolicyAdapter` = ZÉRO hit.** ⇒ PG +
  config-pur **confirmé**, **réutilisé tel quel** (décision n° 3). Le provider l'appelle directement, aucun
  wrapper. Grep critère Keycloak sur `AppConfigStateProvider` (hors commentaires) = VIDE.
- **Golden — valeur courante du tree re-vérifiée AVANT bump** : `77fb548ac9b1f0604afce2a0c7d0316379391ef2e182a95a005b979f3fa5e3bd`
  (PHP `ContractV1Test::FROZEN_STATE_HASH` L86 + Go `hasher_test.go::frozenStateHash` L53 ; `go test ./shared/
  -run TestHashStateGoldenMatchesFrozenHash` vert avant modification). Inchangée.
- **Hashes recalculés** via le `StateHasher` Go RÉEL (cross-validé PHP par les tests existants
  `TestHashItemCrossValidatedAgainstPhp` + `TestHashStateGoldenMatchesFrozenHash`) sur le golden modifié :
  - item `app_config` = `cb347596d413a14e52078fd76d180acaadfd399cb398c9cc53b8bbe2636ef8c2`
  - état complet (9 items) = `a7a72fec96fe0b4757a03ee259712a2cac06cdcdbf84ca3e11cd55dfc387efe9`

### Completion Notes List

**Mécanisme implémenté** : UN SEUL — écrire un `policies.json` enterprise natif au chemin d'install de l'app
(Firefox `%ProgramFiles%\Mozilla Firefox\distribution\policies.json`, Thunderbird au chemin équivalent), en
**écriture atomique** (tmp suffixé PID + rename). **Apps livrées** : Firefox + Thunderbird (`AppKind::cases()`).
Pas de Chrome/Edge, pas de mécanisme registre, pas de redirection de profil (recadrage 2026-06-17 appliqué sans
re-trancher).

**Sémantique / scope** : `aggregate` PAR `app_kind` (un item par app, policies fusionnées côté serveur),
`scope = machine` (correctif post-review #1 — voir « Correctifs post-review 2026-06-17 » : `policies.json`
machine-wide écrit par SYSTEM, résolution PAR PARC niveaux 1-4 `$user = null`). _(Implémentation initiale =
`session` ; corrigée après comparatif legacy Firefox — le par-user passe par le profil/Mécanisme B, pas
`policies.json`.)_ Item de contrat à **4 clés** (post-27.8, pas de `mode`).

**Impédance « WG unique 4.8 » vs « mailles Epic 27 » (piège n° 6) — décision de dev instruite.**
`resolvePoliciesForMachine` prend UN `WorkstationGroup` ; le `TargetContext` expose des LISTES de mailles. Le
provider **collapse l'axe WG** en feedant le WG **gagnant en précédence** du poste (`logique > physique`, aligné
sur `StateCompiler::specificity()` / inversion globale story 27.3) → garantit **un candidat par app** (sinon
itérer tous les WG produirait plusieurs items Firefox, violant « un item par app »). Le résultat porte la config
**par parc** (niveaux 1-4) ; les niveaux user (5-6) ne sont PAS résolus en portée machine (correctif #1).
**Limite connue (#2)** : un 2ᵉ parc logique est silencieusement ignoré (`policies.json` machine-wide = une config
par install). Documenté en docblock du provider + dans `state-providers.md`.

**Marqueur de périmètre** : clé d'extension racine `_sambaedu_managed: true` dans le `policies.json` écrit
(inerte côté Firefox/Thunderbird qui ignorent les clés inconnues). `Inspect` la relit pour distinguer un fichier
GÉRÉ d'un fichier posé hors SambaEdu — ce dernier n'est **jamais écrasé ni supprimé** (AC5). La comparaison
d'idempotence (`Matches`) exclut le marqueur (re-canonicalisation des deux côtés avec `UseNumber` pour que les
nombres restent `json.Number`, iso `ParseState`).

**App butée (AC3)** : invariant « un handler n'écrit que via un mécanisme enterprise documenté » — l'agent ne
bricole RIEN. Toutes les apps gérées (`knownAppKinds` = firefox, thunderbird) ont un `policies.json` → pas d'app
butée active, mais l'invariant est posé et documenté (limite connue).

**Isolation (AC4)** : effort maximal interne (toutes les apps tentées, première erreur remontée à la fin) ; le
moteur `engine.go::RunPass` isole par type (un échec `app_config` ne touche pas les autres types). Vérifié par
`TestAppConfigEngineErrorDoesNotKillOtherTypes` (registry converge malgré app_config en error).

**Résultats des tests :**

- **Go (LOCAL, hôte `~/go-toolchain/go/bin/go` 1.26.4)** — commandes exactes :
  - `cd agent && go test ./...` → **PASS** (`ok sambaedu/agent/shared 2.5s` ; `agent/windows` = no test files).
  - `go vet ./...` (linux) → **0**. `CGO_ENABLED=0 GOOS=windows GOARCH=amd64 go vet ./...` → **0**.
  - `CGO_ENABLED=0 GOOS=windows GOARCH=amd64 go build ./...` → **0** (cross-compile vert).
  - `go test ./shared/ -run AppConfig -v` → **14 tests app_config PASS** (set cible, idempotence, mécanisme
    policies.json/app, drift STRICT, level-triggered, marqueur hors-périmètre ×2, app_kind inconnu error isolé,
    chemin verrouillé error isolé, fichier illisible error, enveloppe invalide ×4, policies absent accepté, §5
    STRICT table-driven ×3, isolation inter-types).
  - `gofmt -l` sur les 3 fichiers Go nouveaux/modifiés → **clean**.
  - Golden cross-tests après bump (`go test ./shared/`) → **PASS** (`TestHashStateGoldenMatchesFrozenHash`,
    `TestHashItemGoldenItemsMatchTheirHashFields` à 9 items, `TestParseStateGoldenFile` à session=7).
- **PHPUnit (NON LANCÉ EN LOCAL — `vendor/` absent du worktree, VM interdite depuis un worktree git).**
  `php -l` OK sur tous les fichiers PHP (provider, modèle, AgentServiceProvider, ContractV1Test, le nouveau test).
  **ACTION HENRI à valider sur `/vm`** (ou après merge) :
  - `ssh -i ~/.ssh/id_se4fs_vm root@192.168.122.50 'cd /var/www/sambaedu-reload && php artisan test --filter Agent'`
    (couvre `AppConfigStateProviderTest` + `ContractV1Test` bumpé + non-régression des autres providers).
  - Spécifiquement : `... php artisan test tests/Unit/Services/Agent/AppConfigStateProviderTest.php` et
    `... php artisan test tests/Unit/Services/Agent/ContractV1Test.php`.

**Golden hash AVANT/APRÈS (croisé PHP ↔ Go, MÊME valeur) — INITIAL puis POST-REVIEW :**

| | AVANT (tree) | APRÈS impl. (session) | APRÈS review (machine) |
|---|---|---|---|
| `FROZEN_STATE_HASH` (PHP `ContractV1Test`) | `77fb548a…f3fa5e3bd` | `a7a72fec…c387efe9` | **`6f0ff33e…dff377fb`** |
| `frozenStateHash` (Go `hasher_test.go`) | `77fb548a…f3fa5e3bd` | `a7a72fec…c387efe9` | **`6f0ff33e…dff377fb`** |
| item `app_config` | — | `cb347596…636ef8c2` | `cb347596…636ef8c2` (inchangé : le contenu de l'item ne change pas, seule sa portée) |
| nb items golden | 8 | 9 | 9 |
| portée de l'item `app_config` | — | `session` | **`machine`** |
| nb items portée machine / session | 1 / 6 | 1 / 7 | **2 / 6** |

> Le re-bump POST-REVIEW (#1) provient **uniquement** du déplacement de portée `session` → `machine` de l'item
> `app_config` (le hash d'état incorpore l'ordre des portées). Le **hash de l'item lui-même est inchangé**
> (`cb347596…`, son contenu `{app_kind, policies}` ne bouge pas). Nouvelle valeur recalculée via le `StateHasher`
> Go RÉEL (cross-validée PHP par les tests existants), bumpée à l'IDENTIQUE PHP + Go (NFR13).

**Déviations vs décisions figées : AUCUNE.** Toutes les décisions de design (n° 1-6, mises à jour post-review)
ont été appliquées (FF/TB policies.json only, aggregate par app_kind, réutilisation `resolvePoliciesForMachine`,
**scope machine** — correctif #1 —, redirection/Chrome/Edge hors-scope, pas de mode). Aucune migration (table 4.8
existe). Aucune nouvelle UI.

**Points à valider humainement :**
1. **PHPUnit `--filter Agent` sur `/vm`** (vendor absent du worktree) — voir commandes ci-dessus.
2. **Validation lab (poste Windows)** — scénarios `docs/qa/domains/agent.md` § « Story 27.4 » (27.4.1 → 27.4.7).
   ⚠️ Le handler agent doit être dans un binaire **publié POSTÉRIEUR à ce commit** (mémoire
   `agent_handler_not_in_published_binary`) : un réglage `app_config` sans effet poste = binaire déployé antérieur.
3. **Chemin natif Thunderbird** : supposé `%ProgramFiles%\Mozilla Thunderbird\distribution\policies.json` (calque
   Firefox, mécanisme enterprise policy Mozilla documenté). À confirmer en lab si l'install Thunderbird du parc
   utilise un autre dossier (le sous-dossier est centralisé dans `appInstallSubdir`, un seul point à ajuster).
4. **Chemin natif Thunderbird & droits SYSTEM** : `policies.json` écrit par le **service SYSTEM** (portée
   machine) sous Program Files (admin-write OK pour SYSTEM). Si le dossier d'install est absent (app non
   installée), l'écriture échoue → `error` isolé (limite connue documentée, couplage 27.5).

### Correctifs post-review 2026-06-17

> Après code review (`_bmad-output/codeReviews/27-4.md`, 8 problèmes + 3 décisions Henri) et **décision Henri
> majeure scope → machine** (comparatif legacy Firefox complet : Mécanisme A config `policies.json` machine-wide
> par-parc vs Mécanisme B profil user roaming, hors 27.4). Tous les changements ci-dessous sont des correctifs de
> l'implémentation EXISTANTE (pas un re-dev).

**🔴 #1 — Bascule scope SESSION → MACHINE (défaut de conception bloquant).** `policies.json` est machine-wide
sous `%ProgramFiles%\…\distribution\` (admin-write) : un compagnon aux droits user prenait ACCESS_DENIED à chaque
logon → inopérant en prod.
- **Provider** : `scope()` → `StateScope::Machine` ; `resolvePoliciesForMachine($wg, **null**, $kind, 'windows')`
  (niveaux 1-4, plus de niveaux user 5-6 — le par-user de Firefox = le profil/Mécanisme B). `latestUpdatedAt()`
  simplifié (défaut étab + WG seulement, plus de requête User/UserGroup → résout aussi #4 : les FQCN
  `User`/`UserGroup` disparaissent). Collapse WG par précédence (`logique > physique`) conservé.
- **Handler Go** : RETIRÉ de la map `Handlers` du compagnon (`companion_windows.go`), AJOUTÉ au `MachineEngine`
  SYSTEM (`main_windows.go`, iso le handler `registry` HKLM machine). La logique d'écriture `policies.json` est
  inchangée (le chemin Program Files était déjà correct ; c'est SYSTEM qui écrit désormais).
- **Golden** : item `app_config` déplacé `session` → `machine` dans `state.v1.json` ; `FROZEN_STATE_HASH` PHP +
  `frozenStateHash` Go re-bumpés à la MÊME nouvelle valeur **`6f0ff33e8ea114d28f67094042bea656a68d6cfdafa01ee6ad9f9537dff377fb`**
  (recalculée via le StateHasher Go réel). Compteurs de portée ajustés : machine 1→2, session 7→6
  (`contract_test.go`) ; le hash de l'item lui-même (`cb347596…`) est INCHANGÉ.

**🟠 #2 — Tiebreak multi-parcs logiques (test + doc, statu quo assumé).** Un 2ᵉ parc logique est silencieusement
ignoré (`policies.json` machine-wide ne porte qu'une config par install). Test PHP
`two_logical_parcs_tiebreak_smallest_id_wins_second_ignored` + documenté (docblock provider + `state-providers.md`).

**🟠 #3 — Parité de canonicalisation (UNIFIÉE).** Les deux canonicalizers (forme cible `shared` + forme relue
`windows`) étaient deux implémentations distinctes → risque de réécriture en boucle sur des `json.Number`.
**Unifiés** : une SEULE fonction exportée `shared.CanonicalJSON(any)` appelée des DEUX côtés (les duplicatas
`encodeSortedJSON`/`sortJSONKeys` côté windows supprimés). Test de parité
`TestAppConfigCanonicalParityOnJSONNumber` (entiers/négatifs/grand entier 2^53+1/listes décodés `UseNumber`) :
**les deux formes concordent à l'octet près** (PASS), pas de notation scientifique, grand entier préservé.

**🟡 #4 — FQCN inline `User`/`UserGroup`.** Réglé par la suppression de la logique user (#1) — ces FQCN ont
disparu. `Carbon` passé en `use` (plus de FQCN inline résiduel).

**🟡 #5 — `kindRank()`.** Rangs pré-calculés UNE fois en propriété statique `$kindRanks` (plus de
`cases()`+`sort()` par appel).

**🟡 #6 — `latestUpdatedAt()` testée.** Test PHP `candidate_updated_at_is_non_null_when_a_rule_exists_else_null`
(null si aucune règle, non-null dès qu'une règle de parc/défaut étab existe), après simplification (défaut étab +
WG seulement).

**🟠 #7 — Conflit hors-périmètre → `error` (jamais `compliant` trompeur).** Un `policies.json` étranger (sans
marqueur) sur une app CIBLE n'est **JAMAIS écrasé** (non-ingérence préservée), mais le statut passe de
`compliant` (trompeur) à **`error`** avec détail « policies.json hors-périmètre présent, policy agent non
appliquée » (helper `foreignPolicyConflictError`, appelé dans `Test` ET `Apply`). Tests Go mis à jour
(`TestAppConfigForeignFileOnTargetIsErrorNeverTouched`, `TestAppConfigForeignFileIsolatesOtherApps`). Note :
Henri pourra plus tard décider une « prise de possession » SYSTEM ; défaut = signaler sans écraser.

**🟡 #8 — `Write()` Go : `.tmp` résiduel.** `defer os.Remove(tmp)` annulé après Rename réussi (flag `renamed`) →
plus de `.tmp` laissé si `os.WriteFile`/`Rename` échoue à mi-course.

**Résultats des tests (post-correctifs) :**
- **Go (hôte `~/go-toolchain/go/bin`)** : `go test ./...` → **PASS** (`ok sambaedu/agent/shared` ; `agent/windows`
  = no test files). `go vet ./...` (linux ET `GOOS=windows`) → **0**. `CGO_ENABLED=0 GOOS=windows go build ./...`
  → **0** (cross-compile vert). `gofmt -l` (fichiers modifiés) → **clean**. Tests `app_config` (set cible,
  idempotence, drift STRICT, level-triggered, marqueur hors-périmètre → error ×2, parité json.Number, app_kind
  inconnu error isolé, chemin verrouillé error isolé, §5 STRICT table-driven, isolation inter-types) → **PASS**.
  Cross-tests golden après re-bump (`TestHashStateGoldenMatchesFrozenHash`, `TestHashItemGoldenItemsMatchTheirHashFields`
  à 9 items, `TestParseStateGoldenFile` à machine=2/session=6) → **PASS**.
- **PHPUnit (NON LANCÉ — `vendor/` absent du worktree).** `php -l` OK sur tous les fichiers PHP. **ACTION HENRI à
  valider sur `/vm`** : `php artisan test --filter Agent` (couvre `AppConfigStateProviderTest` mis à jour : scope
  machine, par-parc, niveaux user ignorés, tiebreak 2 parcs, latestUpdatedAt + `ContractV1Test` re-bumpé +
  non-régression des autres providers).

### File List

**Créés :**
- `app/Services/Agent/Providers/AppConfigStateProvider.php` — provider serveur (aggregate/**machine** post-review,
  lecture seule PG-pure de `app_customizations` via `resolvePoliciesForMachine($wg, null, …)` niveaux 1-4,
  payload `{app_kind, policies}` concret, collapse WG logique>physique, tiebreak multi-parcs documenté,
  `kindRank` pré-calculé, normalisation float→string).
- `agent/shared/handler_app_config.go` — handler Go logique pure (aggregate, level-triggered, drift STRICT,
  marqueur de périmètre + conflit hors-périmètre `error` #7, app butée no-op, canonicalisation déterministe
  UNIFIÉE `CanonicalJSON` #3).
- `agent/shared/handler_app_config_test.go` — tests Go hôte (+ parité json.Number #3, conflit hors-périmètre
  error #7).
- `agent/windows/handler_app_config_windows.go` — câblage Windows (chemin natif d'install, écriture atomique
  `policies.json` + cleanup `.tmp` #8 + marqueur, Inspect/Matches/Write/Remove ; canonicalisation déléguée à
  `shared.CanonicalJSON` #3).
- `tests/Unit/Services/Agent/AppConfigStateProviderTest.php` — tests PHPUnit du provider (scope machine, par-parc,
  niveaux user ignorés, tiebreak 2 parcs #2, latestUpdatedAt #6 — à valider /vm).

**Modifiés :**
- `app/Models/AppCustomization.php` — ajout de la const figée `TYPE_APP_CONFIG = 'app_config'`.
- `app/Providers/AgentServiceProvider.php` — enregistrement de `AppConfigStateProvider` dans le tableau du
  `StateCompiler` (+ import).
- `agent/windows/companion_windows.go` — handler `"app_config"` RETIRÉ de la map `Handlers` du compagnon
  (correctif #1 : commentaire de renvoi vers le MachineEngine SYSTEM).
- `agent/windows/main_windows.go` — handler `"app_config"` AJOUTÉ au `MachineEngine` SYSTEM (correctif #1, iso le
  handler `registry` HKLM machine).
- `tests/Fixtures/Agent/state.v1.json` — item `app_config` déplacé `session` → `machine` (correctif #1) ; hash de
  l'item inchangé.
- `tests/Unit/Services/Agent/ContractV1Test.php` — `FROZEN_STATE_HASH` re-bumpé `a7a72fec…`→`6f0ff33e…` (+
  commentaire post-review §9).
- `agent/shared/hasher_test.go` — `frozenStateHash` re-bumpé à la même valeur (+ commentaire post-review).
- `agent/shared/contract_test.go` — compteurs de portée ajustés : machine 1→2, session 7→6
  (`TestParseStateGoldenFile`).
- `docs/agent/contract-v1.md` — §7.3 payload `app_config` (scope machine, Mécanisme A/B, conflit `error`).
- `docs/agent/state-providers.md` — section `app_config` (scope machine, Mécanisme A/B, tiebreak, conflit error,
  canonicalisation unifiée).
- `agent/README.md` — bullet handler `app_config` (MachineEngine SYSTEM, conflit error, canonicalisation unifiée).
- `docs/qa/domains/agent.md` — section `## Story 27.4` (scénarios mis à jour : par-parc/SYSTEM, conflit error).
- `docs/qa/README.md` — clause 27.4 dans le résumé du domaine agent (scope machine, correctifs review).

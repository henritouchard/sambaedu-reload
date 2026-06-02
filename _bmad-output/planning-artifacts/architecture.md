---
stepsCompleted: [1, 2, 3, 4, 5, 6, 7, 8]
lastStep: 8
status: 'complete'
completedAt: '2026-03-18'
lastEditedAt: '2026-06-01'
lastEditReason: 'Ajout section Authentification Fédérée — Phase 2 IdP externe (Epic 20) — déplacée depuis worktree guacamole'
inputDocuments: [_bmad-output/planning-artifacts/prd.md, _bmad-output/planning-artifacts/epics.md]
workflowType: 'architecture'
project_name: 'codebase'
user_name: 'henri'
date: '2026-03-17'
---

# Architecture Decision Document

_Ce document se construit collaborativement étape par étape. Les sections sont ajoutées au fil des décisions architecturales prises ensemble._

---

## Cartographie des Zones d'Incertitude

> **Note préliminaire :** Les inconnues listées ci-dessous ne reflètent pas un flou sur l'architecture ou l'interface à construire — Henri a des idées claires sur ces aspects. Elles reflètent le fait que certaines fonctionnalités existent dans le legacy SambaEdu mais n'ont pas encore été analysées en détail. Ce sont des **risques d'investigation**, gérables via le pattern `Services/Legacy/` : le contrat de service est défini en architecture, l'implémentation est remplie après analyse du legacy.

### Fonctionnalités Maîtrisées (déjà implémentées ou design clair)

- FR1 — Création utilisateur sans jargon AD (opérationnel)
- FR7, FR10, FR12 — Inventaire machines, cron parc, AppProfile (opérationnel ou design clair)
- FR17-18 — Gestion imprimantes CUPS
- FR33-35, FR37 — irundoo : liens UAI, itinérants, GPEI dispatch

### Zones d'Investigation Legacy (existent dans le legacy, non encore analysées)

| Zone | FRs | Priorité |
|---|---|---|
| Déploiement Windows — GPOs, WPKG, scripts démarrage, profils NT | FR23-26 | 🔴🔴 Critique |
| Système de fichiers — quotas XFS, ACLs POSIX héritées | FR13-15 | 🔴 Haute |
| Provisioning home dir + droits à la création | FR2 | 🔴 Haute |
| DNS | FR21 | 🔴 Haute |
| Sessions cloud Windows | — | ⚠️ Nature même inconnue |
| WOL / extinction / reboot (implémentation réseau) | FR8-9 | 🟡 Moyenne |
| DHCP réservations + baux | FR20, FR22 | 🟡 Moyenne |
| Pilotes Windows imprimantes | FR19 | 🟡 Moyenne |

### Fonctionnalités Nouvelles (pas de legacy à analyser)

- FR37 — GPEI dispatch multi-UAI (architecture à concevoir from scratch)

### Note sur FR38 (Synchronisation AD)

Le libellé PRD est imprécis. LDAP est le protocole de communication avec l'AD, pas un système séparé. **Décision clarifiée :**

- **Source de vérité : PostgreSQL** pour toutes les entités, y compris Users et UserGroups
- **Exception Users/UserGroups :** ces entités sont synchronisées avec l'AD local (qui se sync avec l'AD central Windows). L'app lit et interagit toujours via PostgreSQL — l'AD est maintenu en sync par-dessous, transparent pour l'application
- **Write flow Users/UserGroups :** SER écrit dans PostgreSQL + LdapRecord (AD local) → sync AD local ↔ AD central (infrastructure)
- **Write flow autres entités :** SER écrit dans PostgreSQL uniquement
- **Read flow :** toujours PostgreSQL — jamais de lecture AD directe sauf auth/sync
- **irundoo :** n'accède jamais directement aux AD locaux — toutes les opérations passent par l'API SER

---

## Analyse du Contexte Projet

### Vue d'Ensemble des Exigences

**Exigences Fonctionnelles — 35 FRs réparties en 9 domaines :**

| Domaine | FRs | Produit |
|---|---|---|
| Gestion utilisateurs | FR1-6 | SER |
| Machines & Parcs | FR7-12 | SER |
| Système de fichiers | FR13-16 | SER |
| Impression | FR17-19 | SER |
| Réseau (DHCP/DNS) | FR20-22 | SER |
| Déploiement Windows | FR23-26 | SER |
| Délégations & Permissions | FR27-29 | SER |
| Gestion établissements & itinérants | FR33-35 | irundoo |
| Imports & intégrations académiques | FR36-39 | SER + irundoo |

> **Note :** la supervision multi-instances (ex-FR30/31/32/32b) est hors scope SER — responsabilité du controlHub (irundoo).

**Exigences Non-Fonctionnelles — 18 NFRs :**

- Performance (NFR1-3) : latence < 2s opérations courantes, feedback immédiat actions longues, zéro requête LDAP non indexée
- Sécurité (NFR4-7) : 3 failles connues du legacy à corriger (mots de passe en clair, SSL/TLS CAS, bypass admin), moindre privilège Spatie
- Fiabilité (NFR8-12) : local-first sans internet, rollback Proxmox < 5 min, SER standalone sans irundoo, migrations idempotentes
- Maintenabilité (NFR13-16) : code typé, Services/Legacy/ commentés, tests avant bêta, onboarding développeur autonome
- Intégration (NFR17-18) : structure OU standardisée avec détection explicite des déviations, appels système encapsulés dans Services

### Modèle de Données — Source de Vérité

```
Source de vérité : PostgreSQL (toutes entités)

Users/UserGroups (sync Windows) :
  Write : SER → PostgreSQL + LdapRecord (AD local) ↔ AD central (sync infra)
  Read  : SER → PostgreSQL (toujours)

Autres entités :
  Write : SER → PostgreSQL
  Read  : SER → PostgreSQL

Lecture LDAP spécifique (auth, sync initiale) : LdapRecord dans les Services uniquement
API   : irundoo → API SER → PostgreSQL (jamais AD direct)
```

PostgreSQL est la source de vérité et le point de lecture unique pour l'application. L'AD local est maintenu en sync pour Windows, transparent pour l'app. irundoo n'accède jamais directement aux AD locaux.

### Contraintes Techniques & Dépendances

- **Local-first** : pas d'internet garanti — pas de CDN, assets locales, pas de services externes
- **Infrastructure hétérogène** : chaque collège a son propre Proxmox/AD — SER ne peut pas supposer de standardisation matérielle
- **Brownfield** : legacy SambaEdu existant — migration par parité fonctionnelle, pas de greenfield
- **Stack fixée** : Laravel + Livewire 4 + DaisyUI + PostgreSQL (SER et irundoo)
- **API SER** : surface d'intégration de première classe dès le MVP — irundoo en dépend pour toutes les opérations cross-établissements

### Préoccupations Transversales

| Préoccupation | Impact | Composants concernés |
|---|---|---|
| Auth/Authz (Spatie) | SER + irundoo | Tous les domaines |
| SSO Keycloak (Phase 2) | Architecture préparée dès MVP | Auth, API, navigation inter-instances |
| ControlHubTasks (async) | Toutes les actions longues | Parc machines, scripts, cron |
| Services/Legacy/ (dette assumée) | Uniquement le code legacy réutilisé directement | GPOs, WPKG, profils NT |
| Audit logging RGPD | Données personnelles élèves/enseignants | Utilisateurs, FS, droits |
| Isolation SER standalone | Résilience sans irundoo | Toute communication irundoo → SER |
| API SER | Intégration irundoo | Surface d'intégration prioritaire |

### Échelle & Complexité

- **Complexité : Haute**
- **Domaine primaire :** administration IT scolaire (web app locale + SaaS B2B multi-tenant + intégrations système profondes)
- **Intégrations système :** AD/LDAP, CUPS, DHCP, DNS, XFS quotas, ACLs POSIX, WOL, WPKG, GPOs, scripts sudo
- **Composants architecturaux estimés :** ~15 services distincts (SER) + ~8 services irundoo

---

## Fondations Techniques

### Stack Technique (décision préexistante — brownfield)

| Composant | Choix | Scope |
|---|---|---|
| Backend | Laravel | SER + irundoo |
| Frontend réactif | Livewire 4 SFC | SER + irundoo |
| UI | DaisyUI (Tailwind) | SER + irundoo |
| Base de données | PostgreSQL | SER + irundoo |
| ORM AD/LDAP | LdapRecord | SER |
| Permissions | Spatie | SER + irundoo |

### Convention d'Organisation des Vues (norme maison)

Routing standard Laravel (`web.php`). Les fichiers de vues suivent une convention arborescente dans `resources/views/pages/` :

- Une route = un dossier portant le nom de la route (`app/users` → `pages/users/`)
- Point d'entrée de chaque route : `index.blade.php`
- Composants spécifiques à la route : sous-dossier `_partials/`
- Sous-routes : sous-dossiers du même nom (`app/users/new` → `pages/users/new/index.blade.php`)
- Segments dynamiques : dossiers entre crochets (`pages/users/[login]/index.blade.php`)
- Livewire SFC pour les parties réactives, Blade pur pour les partials sans réactivité

**Note :** Cette convention est une norme maison, pas une feature Livewire 4 ou un package tiers.

---

## Décisions Architecturales Core

### Data Architecture

| Décision | Choix | Rationale |
|---|---|---|
| Migrations | Une migration par feature finale | Squash des migrations de dev avant merge — éviter la prolifération de fichiers |
| Cache | Redis | Supérieur pour les requêtes LDAP fréquentes (in-memory) ; ouvre la voie aux queues Redis si besoin |
| Sync AD→PostgreSQL | Géré par SER | Pattern hybride : import initial (`/sync-from-ad`), check par page, cron — en place, à compléter |

**Rôle de PostgreSQL :** cache de lecture performant. L'AD local reste la source de vérité pour les utilisateurs et groupes (MVP).

### Auth & Sécurité

| Décision | Choix | Rationale |
|---|---|---|
| Auth MVP | Laravel + LDAP existant (`sambaedu.auth`) | Déjà en place et fonctionnel — pas touché |
| Keycloak SSO | Sprint dédié Phase 2 | Critique — hors scope MVP, ne pas préparer de shims prématurés |
| Auth fédérée externe (techniciens flotte) | JWT signé `RS256` + `FederatedIdpAuthGuard` (Epic 20) | IdP externe pluggable via `AuthGuardInterface` (matérialise la Phase 2 anticipée) — voir section dédiée « Authentification Fédérée » |
| API irundoo↔SER | Sanctum tokens | Déjà en place |

### API & Communication

| Décision | Choix | Rationale |
|---|---|---|
| Format API | REST JSON | Standard, cohérent avec l'existant |
| Versioning | `/api/v1/` dans les routes | Permet de faire évoluer l'API sans casser les clients existants |
| Queue driver | Database (PostgreSQL) | Suffisant pour le MVP — Redis si besoin de perf |

### Coexistence Legacy — Stratégie Catchall

La route catchall Laravel intercepte tout appel sans route Livewire correspondante et le redirige vers les scripts PHP legacy.

**Problème actuel :** le path `base_path('../' . $path)` était calé sur l'ancienne structure imbriquée (SER = sous-dossier de sambaedu). La séparation des répertoires a cassé cette résolution.

**Solution :**

- `SAMBAEDU_LEGACY_PATH` dans `.env` → `config('sambaedu.legacy_path')` — chemin configurable vers le répertoire legacy
- Le catchall utilise ce config au lieu du `base_path('../')` hardcodé
- Redirection legacy restaurée et fonctionnelle comme filet de sécurité

**Logging des appels catchall :**

- Channel Laravel dédié : `legacylog`
- Stockage : table `legacy_catchall_logs` (PostgreSQL) — droppable proprement une fois la migration complète
- Données loggées : timestamp, method, path, IP, query string, referer
- **Dashboard temps réel** : composant Livewire dans l'admin SER — affichage des appels non gérés pour cartographier progressivement les routes à implémenter

**Stratégie de migration :** toute route Livewire déclarée dans `web.php` court-circuite automatiquement le catchall. La migration est progressive, route par route, guidée par les logs.

### Cloisonnement Legacy (Epic 1bis)

> **Contexte :** La réécriture native module par module est trop lente face au volume legacy (wpkg 50 fichiers, iPXE 72 fichiers, imprimantes 11 fichiers…). Le cloisonnement permet de livrer les modules legacy fonctionnels aux utilisateurs via l'interface Laravel pendant que la réécriture native avance. Les epics de réécriture (3-12) restent au backlog — le cloisonnement apporte du confort, pas un report.

#### Vue d'ensemble du mécanisme

Les modules PHP legacy sont intégrés dans un sous-dossier `legacy/` de SER. Leurs accès données (LDAP, MySQL) sont redirigés vers la couche Laravel (Eloquent/PostgreSQL) via des shims. Le catchall existant (story 1.2) sert de point d'entrée : il proxy les requêtes vers les modules legacy qui les traitent dans leur contexte d'origine, mais avec les données Laravel.

```
Requête HTTP (route non Livewire)
    ↓ catchall (LegacyCatchallController)
    ↓ legacy/bootstrap.php (init session Laravel + autoload)
    ↓ legacy/modules/[module]/index.php
    ↓ appels LDAP → ldap.inc.php shim → Eloquent models (PostgreSQL)
    ↓ appels mysqli_* → shim SQL → Eloquent models (PostgreSQL)
    ↓ réponse HTML
```

#### Structure `legacy/`

```
legacy/
├── bootstrap.php              # Init session Laravel, autoload, error handler
├── config.inc.php             # Pont vers config('sambaedu.*') Laravel
├── ldap.inc.php               # Shim ~20 fonctions LDAP → Eloquent
├── modules/
│   ├── display/               # Tier 1 (0 LDAP, 0 exec)
│   ├── oauth2/                # Tier 1
│   ├── sso/                   # Tier 1
│   ├── cas/                   # Tier 1
│   ├── api/                   # Tier 1
│   ├── user/                  # Tier 1
│   ├── dossier_echange/       # Tier 1
│   ├── wpkg/                  # Tier 2 (SQL legacy + exec limités)
│   ├── annu2/                 # Tier 2
│   ├── parcs2/                # Tier 2
│   ├── partages/              # Tier 2
│   ├── acls/                  # Tier 2
│   ├── ipxe/                  # Tier 2
│   ├── printers/              # Tier 3 (shim LDAP complet + exec système)
│   ├── dhcp/                  # Tier 3
│   ├── bbb/                   # Tier 3
│   ├── gpo/                   # Tier 3
│   ├── central/               # Tier 3
│   └── infos/                 # Tier 3
```

**Tiering :**

| Tier | Critère | Modules | Risque |
|---|---|---|---|
| Tier 1 | 0 appels LDAP, 0 exec système | display, oauth2, sso, cas, api, user, dossier_echange | Quasi nul |
| Tier 2 | SQL legacy (`mysqli_*`) et/ou exec limités | wpkg, annu2, parcs2, partages, acls, ipxe | Moyen — dépend du shim SQL |
| Tier 3 | Shim LDAP complet + exec système (`lpadmin`, `samba-tool`, `df`…) | printers, dhcp, bbb, gpo, central, infos | Plus élevé — couverture LDAP critique |

#### Bootstrap & Ponts de Configuration

**`bootstrap.php` :**
- Initialise la session Laravel (accès à `app()`, `config()`, `auth()`)
- Configure l'autoload pour que les modules legacy trouvent leurs dépendances
- Branche le error handler global (capture des erreurs PHP legacy)
- Point d'entrée unique — tous les modules passent par là

**`config.inc.php` :**
- Pont entre les variables de config legacy (`$_SESSION`, constantes PHP) et `config('sambaedu.*')` Laravel
- Les modules legacy continuent à lire leurs variables habituelles — le pont les alimente depuis la config Laravel

#### Shims

**Shim LDAP (`ldap.inc.php`) :**
- ~20 fonctions wrapper qui interceptent les appels LDAP des modules legacy
- Chaque fonction redirige vers le modèle Eloquent correspondant (PostgreSQL)
- Tests PHPUnit sur données réelles pour chaque fonction shim — couverture critique
- Fonction non shimmée appelée → erreur explicite loggée identifiant la fonction manquante (pas de crash silencieux)

**Shim SQL (MySQL→Eloquent) :**
- Remplacement des appels `mysqli_*` par des appels Eloquent dans les modules concernés
- S'appuie sur les modèles Laravel existants (`Application`, `Depot`, `Workstation`…)
- Principalement concentré sur `wpkg_libsql.php`
- Appel SQL non couvert par un modèle Eloquent → erreur explicite loggée

**Règle commune :** les shims ne simulent pas un serveur LDAP ou MySQL — ils traduisent les appels en requêtes Eloquent. Si une traduction n'existe pas, l'erreur est remontée explicitement via le error logger.

#### Error Logger Unifié

Handler global qui capture toutes les erreurs du système :
- **Erreurs PHP legacy** (notices, warnings, fatals des modules `legacy/`)
- **Exceptions Laravel** (via le Handler existant)

**Stockage :** table PostgreSQL dédiée — datetime + message (sans stack trace), droppable une fois le cloisonnement stabilisé.

**Dashboard :** module dans la page admin SER — affichage temps réel des erreurs pour faciliter le dev et le debug des shims. Complémentaire à GlitchTip (prévu à terme pour le monitoring production), pas nécessairement conservé.

**Règle :** le error logger est un outil de dev, pas un système de monitoring production. Il est disponible dès le début de l'epic pour accompagner l'intégration progressive des modules.

### Authentification Fédérée — Phase 2 IdP externe (Epic 20)

> **Ajout 2026-05-29.** Cette section matérialise concrètement la « Phase 2 » d'auth externe que l'architecture avait déjà réservée (cf. *Auth & Sécurité* — « Keycloak SSO Phase 2 » — et *Décision ajoutée — Interface AuthGuard*). Elle ne crée pas de pilier d'auth : elle remplit l'emplacement `AuthGuardInterface` déjà dessiné en Story 1.4.

**Besoin (Epic 20) :** authentifier un acteur humain **externe à l'AD d'un établissement** (technicien gérant plusieurs collèges) et l'autoriser selon un rôle, sans qu'il existe jamais dans l'AD local (cible : 1 AD = 1 collège).

**Principe directeur — domain-neutral :** SER gagne un concept de **fournisseur d'identité externe de confiance** (IdP fédéré, configurable). controlHub en est *une* instance ; le code SER n'a **aucune notion de « central »** (principe fondateur PRD). Cohérent avec la décision Epic 19 (« le central ne porte plus de secret par étab »).

#### Décisions tranchées (chat archi 2026-05-29)

| # | Décision | Choix | Rationale |
|---|---|---|---|
| ① | Preuve d'identité | **Redirect JWT signé** (OIDC-léger) | controlHub redirige le navigateur vers SE5 avec un JWT signé ; SE5 vérifie via la **clé publique** de l'émetteur. Pattern déjà maîtrisé (token JSON signé porté-navigateur d'Epic 19 ; OIDC anticipé). **Zéro secret partagé par étab** — controlHub signe avec sa privée, SE5 ne détient que la publique. Technologie standard. |
| ② | Positionnement du guard | **`FederatedIdpAuthGuard` distinct & générique** | Nouvelle implémentation de `AuthGuardInterface`, **coexistant** avec `SambaEduAuthGuard` (LDAP, reste le défaut) et un futur `KeycloakAuthGuard`. SSO Keycloak établissement (humains internes) et fédération flotte (externes hors-AD) sont deux besoins distincts — ne pas les fusionner. Réutilise l'abstraction, pas le pilier. |
| ③ | Session & révocation | **Session SE5 standard + JWT d'entrée à TTL court** | Le JWT d'entrée expire vite (≈5-15 min) ; une fois la session ouverte, elle vit comme une session SE5 normale. Révocation **passive** (à expiration). Iso-existant, rien à construire. Révocation active côté SE5 (push controlHub) = évolution si le terrain l'exige. |

#### Contrat d'autorisation — rôle, pas permissions

Le JWT transporte un **nom de rôle** (l'intention), **jamais une liste de permissions** (le mécanisme). SE5 mappe `rôle-externe → rôle Spatie local` via une table configurable, puis réutilise les Policies/Gates Spatie existants (Epic 7).

- **Pourquoi :** un contrat « permissions » fuit le catalogue interne (`SambaPermission`, 21 entrées) dans l'échange inter-systèmes et impose à l'IdP un catalogue **par version d'instance** — ingérable sur une flotte hétérogène. Le contrat « rôle » absorbe l'évolution des permissions du côté qui en détient le sens (SE5), sans redéploiement de l'IdP.
- **Garde-fou :** un rôle asséré inconnu de l'instance → **refus explicite** (jamais de fallback vers un rôle privilégié).

#### Composants

```
app/Auth/FederatedIdpAuthGuard.php          ← nouvelle implémentation de AuthGuardInterface (vérif JWT, mapping rôle)
app/Models/ExternalIdentity.php             ← identité externe persistante hors-AD (soft-delete), distincte de LdapUser
config/federated_auth.php                   ← émetteur(s) de confiance (clé publique, issuer), table rôle-externe → rôle Spatie
```

- **Identité externe persistante (Story 20.2)** : enregistrement local durable (id externe stable, login, nom, email), **jamais écrit dans l'AD**, **jamais hard-delete** (soft-delete). Reconnexions = même enregistrement (clé = id externe). L'identité persiste indépendamment de l'état d'accès → audit/RGPD.
- **Audit dénormalisé (Story 20.4)** : les actions externes sont journalisées avec login + id externe + nom + rôle actif **copiés** dans le log (pas une simple FK), pour rester lisibles après soft-delete. Origine externe distinguée de l'AD locale.

#### Flux d'authentification fédérée

```
1. Technicien authentifié sur controlHub demande l'accès à l'instance SE5 d'un collège.
2. controlHub forge un JWT { sub: id externe, login, name, email, role, iss, exp(court) }
   signé avec sa clé privée → redirige le navigateur vers l'endpoint de fédération SE5.
3. SE5 (FederatedIdpAuthGuard) :
   a. vérifie signature (clé publique de l'iss configuré), exp, iss → sinon 401, aucune session.
   b. upsert ExternalIdentity (clé = sub) — hors-AD, soft-delete.
   c. mappe role → rôle Spatie local (table config) ; rôle inconnu → 403 explicite.
   d. ouvre une session SE5 standard. Auth LDAP/AD inchangée (iso-legacy).
4. Le technicien administre l'instance selon son rôle (Policies/Gates Spatie existants).
   Chaque action → log d'audit dénormalisé.
```

#### Sécurité du jeton JWT (durcissement IR 2026-05-29)

> Exigences non négociables sur le code d'auth — à intégrer comme AC de Story 20.1. Issues des constats H1/M1/M4 du rapport de readiness Epic 20.

**Vérification de signature (H1) :**

- **Algorithme pinné = `RS256`** (asymétrique : controlHub signe avec sa privée, SE5 vérifie avec la publique). EdDSA/Ed25519 acceptable si la lib le supporte. *Boring tech, ubiquitaire en PHP.*
- **Rejet explicite de `alg:none`** et de **tout algorithme symétrique** (`HS256`…) : la lib doit n'accepter QUE l'algo attendu, pour fermer la faille classique de confusion d'algorithme (un attaquant signant en HS256 avec la clé publique comme secret). Ne jamais déduire l'algo du header du jeton.
- **Claims validés systématiquement** : `iss` (= émetteur configuré), **`aud` (= identifiant de CETTE instance SE5)**, `exp`, `nbf`, `iat`. Tout claim manquant ou non conforme → **401, aucune session**.
- **Le claim `aud` lie le jeton à une instance précise** — un JWT forgé pour le collège A ne peut être rejoué sur le collège B (protection clé sur une flotte).

**Distribution de la clé publique (M1) :**

- **MVP : clé publique statique en config** (`config/federated_auth.php`, par `iss`). Pas de dépendance réseau à la connexion — *boring, robuste*.
- **Rotation** : support de **plusieurs clés par `kid`** (clé identifiée dans le header JWT) pour permettre une rotation sans coupure (ancienne + nouvelle valides pendant le recouvrement).
- **Évolution** : endpoint **JWKS** côté controlHub (récupération + cache des clés) — uniquement si la rotation manuelle par config devient un point de friction terrain. YAGNI au départ.

**Anti-rejeu & horloge (M4) :**

- **`jti` obligatoire** + cache courte durée (TTL = TTL du jeton) : un `jti` déjà vu est refusé → un jeton d'entrée n'est consommable **qu'une fois**.
- **Tolérance d'horloge** : ±60 s sur `exp`/`nbf`/`iat` (le TTL court rend le clock-skew sensible).

#### Frontières

- **Hors scope SER** : la gestion côté controlHub des techniciens et de leurs rôles (côté irundoo) ; le périmètre multi-instance (quelles instances un JWT autorise = décision controlHub) ; l'auth machine/poste (reste iso-legacy AD+SMB, acteur distinct).
- **Inchangé** : `LdapUserProvider`, `SambaEduAuthGuard`, l'auth AD du MVP. Le guard fédéré s'active sur l'endpoint de fédération uniquement ; LDAP reste le défaut.
- **Stories** : 20.1 (guard), 20.2 (identité persistante), 20.3 (mapping rôle), 20.4 (audit dénormalisé), 20.5 (doc contrat — *après* implémentation).

---

## Patterns d'Implémentation & Règles de Cohérence

### Format de Réponse API

Format uniforme dans tout le codebase (existant — à respecter) :

```json
{ "success": true, "message": "...", "[clé_métier]": "..." }
{ "success": false, "message": "...", "errors": {...} }
{ "success": false, "message": "...", "error": "...", "reason": "..." }
```

**Règles :**
- Pas de wrapper `data:` — les clés métier sont directement à la racine
- `success` (bool) toujours présent
- `message` (string) toujours présent
- `errors` pour les erreurs de validation (422), `error` pour les exceptions (500)
- HTTP status codes standards : 200, 404, 409, 422, 500

### Couche Services — Responsabilités

```
Livewire SFC
    ↓ appelle
Services/
    ↓ lecture        ↓ écriture / lecture LDAP spécifique
Eloquent Models    LdapRecord Models
(PostgreSQL)       (AD local)
```

**Règles absolues :**
- **Jamais** d'appel Eloquent ou LdapRecord directement dans un composant Livewire
- **Jamais** d'appel `exec()` / `shell_exec()` / appel système hors d'un Service
- **Services** = unique point d'orchestration entre les couches data

**Couche data — transition en cours :**
- **Lecture** : Eloquent models (PostgreSQL) — migration model-based en cours, repositories SQL gardés temporairement comme rollback
- **Écriture** : LdapRecord dans les Services — toujours via un Service dédié
- **Lecture LDAP spécifique** (sync, login, vérification) : LdapRecord dans les Services

**Repositories :** conservés pendant la transition. Nouveau code privilégie les Eloquent models. Si un repository pose problème lors de la migration, rollback facile.

### Pattern ControlHub Tasks (async)

Flux standard pour toute action longue (WOL batch, scripts Windows, cron parc...) :

```
irundoo → POST /api/v1/controlhub/[type]
    → SER valide + crée ControlHubTask en DB (status: received)
    → dispatch Job Laravel (immediate ou scheduled_at)
    → retourne {success: true, task_id, status}

Job exécute
    → marque task en cours (status: running)
    → exécute l'opération
    → marque terminée (status: completed|failed) + résultat en DB
    → callback irundoo (POST) avec statut final

Idempotence : si task_id déjà connu → retourne l'état existant sans re-dispatch
```

**Chaque type de tâche** a son propre Controller API + Job — pas de controller générique fourre-tout.

### Gestion des Erreurs

**Front (utilisateur) :** `WithToasts` — notifications Livewire pour les actions utilisateur. Jamais de `alert()` ou d'autres mécanismes.

**Back (monitoring) :** GlitchTip self-hosted sur l'infra irundoo.
- Intégration via `sentry/sentry-laravel` (SDK compatible)
- Chaque instance SER remonte ses erreurs vers GlitchTip central
- Groupement automatique, stack traces, analytics de base

**Règle :** exceptions métier catchées dans les Services → loggées + relancées ou transformées en réponse structurée. Les composants Livewire catchent et affichent via WithToasts.

### Nommage Base de Données

- Tables : snake_case, pluriel (`legacy_catchall_logs`, `control_hub_tasks`)
- Colonnes : snake_case (`created_at`, `task_id`, `scheduled_at`)
- Clés étrangères : `[table_singulier]_id` (`user_id`, `workstation_id`)
- Convention Laravel standard partout — aucune déviation

### Nommage & Structure Code

- **Namespaces** : PSR-4, reflètent l'arborescence (`App\Services\Users\UserService`)
- **Services** : un service par domaine métier (`UserService`, `WorkstationService`, `PrinterService`...)
- **Jobs** : nommés par action (`ExecuteGreetmeJob`, `WakeOnLanJob`, `SyncUsersJob`)
- **API Controllers** : un controller par type de tâche ou ressource, dans `Api/v1/[Domaine]/` — organisation à harmoniser par domaine (en cours)
- **Enums** : `app/Enums/` séparé de `app/Dto/` — Enums = construct langage PHP 8.1 (valeurs typées fixes), Dto = objets de transport de données structurées

---

## Structure du Projet & Frontières

### Arborescence SER (`sambaedu-reload/`)

```
app/
├── Components/Traits/        # WithToasts, traits Livewire réutilisables
├── Config/                   # Bridge config legacy SambaEdu (≠ config/ Laravel)
│                             # LegacyConfigBridge, SambaEduConfig, LdapConfig...
├── Console/Commands/         # Artisan commands (sync, cron, maintenance)
├── Constants/                # Constantes LDAP, routes, messages d'erreur
├── Dto/                      # Value objects & résultats de service
│                             # ⚠️ Unifie l'ancien Types/ — migration progressive
├── Enums/                    # PHP 8.1 Enums (statuts, rôles, permissions Samba)
├── Exceptions/               # Handler global → reporte vers GlitchTip
├── Facades/                  # SEConfig, SE4Utility
├── Http/
│   ├── Controllers/
│   │   ├── Api/v1/           # ⚠️ Organisation à harmoniser par domaine
│   │   │   └── [Domaine]/    # Cible : un controller par ressource/action
│   │   ├── Admin/
│   │   └── [AuthController, ChangePasswordController...]
│   └── Middleware/           # sambaedu.auth, sambaedu.admin, password.change
├── Jobs/
│   ├── AdSync/               # Jobs de synchronisation AD → PostgreSQL
│   └── [Action]Job.php       # Un Job par action async ControlHub
├── Models/                   # Eloquent (PostgreSQL) — source de lecture primaire
├── Observers/                # Hooks modèles (cascade, invalidation cache)
├── Policies/                 # Spatie + gates
├── Providers/
├── Repositories/             # ⚠️ Temporaire — migration vers Models Eloquent en cours
└── Services/
legacy/                           # Cloisonnement Epic 1bis
├── bootstrap.php              # Init session Laravel + autoload + error handler
├── config.inc.php             # Pont config legacy → config('sambaedu.*')
├── ldap.inc.php               # Shim ~20 fonctions LDAP → Eloquent
└── modules/                   # Modules PHP legacy par Tier (1/2/3)
    ├── display/, oauth2/...   # Tier 1 (0 LDAP, 0 exec)
    ├── wpkg/, annu2/...       # Tier 2 (SQL legacy + exec limités)
    └── printers/, dhcp/...    # Tier 3 (shim LDAP complet + exec système)
    ├── AdSync/               # Sync AD → PostgreSQL (lecture LDAP spécifique)
    ├── AppProfile/           # Profils applicatifs ↔ postes/groupes
    ├── AppStore/             # Catalogue d'applications
    ├── ControlHub/           # Client API irundoo + Data/ (DTOs ControlHub)
    ├── Filesystem/           # À créer — HomeDirService, XfsQuotaService, AclService
    ├── Legacy/               # Code legacy réutilisé directement (dette assumée, commentée)
    ├── Network/              # À créer — DhcpService, DnsService
    ├── Parc/                 # WorkstationGroupService, RemoteAccessService
    ├── Print/                # À créer — CupsPrinterService, PrintDriverService
    ├── SE4/                  # PowerShellRemoteService (scripts Windows via PS)
    ├── Windows/              # À créer — WpkgService, GpoService,
    │                         #   NtProfileService (profils itinérants Windows),
    │                         #   WindowsScriptService
    └── [Domaine]Service.php  # Services racine : UserService, QuotaService, RightsService...

config/                       # Config Laravel standard (database, queue, mail...)
                              # ≠ app/Config/ qui est le bridge legacy SambaEdu

resources/views/
├── components/               # Composants réutilisables (atomic design)
│   ├── atoms/                # Éléments UI élémentaires (input, button, tooltip...)
│   ├── molecules/            # Composants composés (pagination, modal, smart-select...)
│   ├── organisms/            # Composants complexes (navbar, sidebar, data-table...)
│   ├── icons/
│   └── layouts/
├── layouts/                  # Layouts globaux (app, auth, admin-sidebar)
├── auth/
└── pages/                   # Convention maison — une route = un dossier
    ├── dashboard/
    ├── users/                # FR1, FR2, FR5, FR6
    │   ├── [login]/
    │   ├── groups/
    │   └── new/
    ├── parc/                 # FR7-10
    │   ├── groups/[id]/
    │   └── machines/[id]/
    ├── parc-settings/        # FR11-12 (AppProfiles)
    ├── shortcuts/
    ├── printers/             # À créer — FR17-19 (CUPS + pilotes Windows)
    ├── network/              # À créer — FR20-22
    │   ├── dhcp/
    │   └── dns/
    ├── filesystem/           # À créer — FR13-16 (quotas XFS, ACLs, home dirs)
    ├── windows-deploy/       # À créer — FR23-26 (WPKG, GPO, scripts, profils NT)
    ├── rights-management/    # FR27-29
    ├── sync-from-ad/
    ├── control-hub/
    ├── admin/
    │   └── legacy-monitor/   # À créer — Dashboard catchall temps réel
    └── workers/              # Monitoring jobs async
```

### Frontières d'Intégration

**SER ↔ irundoo :**
- irundoo → `POST /api/v1/[domaine]/` → SER (Sanctum tokens)
- SER → callback irundoo (POST) à la completion des tâches async
- irundoo n'accède jamais directement à l'AD local ni au système de fichiers SER

**SER ↔ Système local :**
- AD local : LdapRecord (écriture + lecture auth/sync)
- PostgreSQL : Eloquent (lecture)
- Système (quotas XFS, ACLs, CUPS, WOL, WPKG) : via Services — jamais d'appels directs hors Services

**SER ↔ Legacy :**
- Catchall route → `legacy/bootstrap.php` → modules PHP legacy (cloisonnement Epic 1bis)
- Shims LDAP→Eloquent et MySQL→Eloquent dans `legacy/` (couche de traduction données)
- `Services/Legacy/` → code legacy réutilisé directement (dette documentée)
- `legacy_catchall_logs` table → dashboard `/admin/legacy-monitor`
- Error logger unifié (legacy + Laravel) → table DB + module admin dashboard

**SER ↔ GlitchTip :**
- SDK `sentry/sentry-laravel` → remonte exceptions vers GlitchTip central (irundoo infra)

### Mapping FRs → Structure

| Domaine | FRs | Services | Pages |
|---|---|---|---|
| Utilisateurs | FR1-2, FR5-6 | UserService, UserGroupService | pages/users/ |
| Machines & Parcs | FR7-10 | WorkstationService, Parc/ | pages/parc/ |
| AppProfiles | FR11-12 | AppProfile/ | pages/parc-settings/ |
| Système de fichiers | FR13-16 | Filesystem/ (à créer) | pages/filesystem/ |
| Impression | FR17-19 | Print/ (à créer) | pages/printers/ |
| Réseau | FR20-22 | Network/ (à créer) | pages/network/ |
| Déploiement Windows | FR23-26 | Windows/ (à créer) | pages/windows-deploy/ |
| Délégations | FR27-29 | RightsService, PermissionService | pages/rights-management/ |
| ControlHub async | transversal | ControlHub/, Jobs/ | pages/workers/, pages/control-hub/ |
| Legacy monitoring | transversal | — | pages/admin/legacy-monitor/ |

---

## Validation de l'Architecture

### Décision ajoutée — Interface AuthGuard

Pour faciliter la migration vers Keycloak en Phase 2 sans modifier les routes, une interface d'authentification est introduite dès le MVP :

```
app/Contracts/Auth/AuthGuardInterface.php   ← interface
app/Auth/SambaEduAuthGuard.php              ← implémentation MVP
app/Auth/KeycloakAuthGuard.php              ← Phase 2 (swap via config)
```

Le middleware `sambaedu.auth` délègue à l'implémentation active. Migration Keycloak = changer une ligne de config, zéro modification de routes.

### Stratégie de Tests

| Type | Outil | Quand | Périmètre |
|---|---|---|---|
| Tests unitaires | PHPUnit | Obligatoire avant merge | Chaque Service |
| Tests E2E | Playwright (ou Dusk) | Obligatoire avant merge | Chaque nouvelle page/feature |
| Tests de comparaison legacy↔SER | À définir | Sprint dédié avant prod | Parité fonctionnelle |
| Tests manuels | — | Continus | Pilotés par Henri |

**Règle :** toute nouvelle feature = tests unitaires + E2E livrés dans la même PR.

### Résultats de Validation

**Cohérence ✅** — Toutes les décisions sont compatibles entre elles. Stack, patterns, frontières et flux de données sont cohérents.

**Couverture FRs ✅** — Toutes les FRs ont une localisation architecturale. Les zones rouges (FR13-16, FR17-19, FR20-22, FR23-26) ont leurs Services et Pages définis — l'implémentation est bloquée sur l'investigation legacy, pas sur l'architecture.

**Couverture NFRs ✅**
- Performance : Redis + PostgreSQL read layer + jobs async
- Sécurité : Spatie + AuthGuard interface + failles legacy en backlog
- Fiabilité : local-first, SER standalone, migrations idempotentes
- Maintenabilité : patterns documentés, Services/Legacy/ commentés, GlitchTip, tests obligatoires

**Gaps assumés :**
- Surface API SER↔irundoo : minimale au départ, croît avec les stories
- Sessions cloud Windows : nature inconnue — investigation à planifier
- Tests de parité legacy↔SER : sprint dédié avant prod

### Checklist de Complétude

- [x] Contexte projet et zones d'incertitude cartographiés
- [x] Stack technique documentée
- [x] Source de vérité et flux de données définis
- [x] Patterns d'implémentation (Services, API, Livewire, async)
- [x] Convention de nommage et structure des fichiers
- [x] Format API uniforme documenté
- [x] Stratégie de coexistence legacy (catchall + logging + migration progressive)
- [x] Cloisonnement legacy Epic 1bis (bootstrap, shims LDAP/SQL, structure legacy/, error logger)
- [x] Interface AuthGuard préparée pour Keycloak Phase 2
- [x] Stratégie de tests définie
- [x] Monitoring erreurs (GlitchTip) et legacy (dashboard custom)
- [x] Mapping FRs → Services → Pages

**Statut : PRÊT POUR L'IMPLÉMENTATION**


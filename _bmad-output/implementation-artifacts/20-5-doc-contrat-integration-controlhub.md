# Story 20.5 : Contrat d'intégration controlHub — document de fédération (doc)

Status: review

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

> **Cinquième et dernière story d'Epic 20** « Authentification fédérée d'utilisateurs externes ». Les Stories **20.1 / 20.2 / 20.3 / 20.4** (toutes en `review`) ont livré le code complet de la fédération côté SE5 : vérification du JWT + ouverture de session (20.1), cycle de vie de l'`ExternalIdentity` + rétention RGPD (20.2), résolution **directe** du rôle asséré (20.3, pivot — plus de table de mapping), audit dénormalisé des actions externes (20.4). **20.5 ne livre AUCUN code applicatif** : elle produit **UN DOCUMENT** — le *contrat d'intégration* destiné à un développeur controlHub (irundoo), décrivant **précisément le contrat RÉELLEMENT IMPLÉMENTÉ** (et non l'épure théorique de l'epic, qui a divergé sur plusieurs points).
>
> **Principe directeur — `feedback_doc_follows_code`** : le contrat se dérive du code livré, **jamais l'inverse**. La rédaction se fait MAINTENANT car les 4 stories sources sont livrées (`review` = code en place). C'est exactement pour cette raison que l'epic a planifié 20.5 *après* 20.1-20.4.
>
> **Principe fondateur — DOMAIN-NEUTRAL CAPITAL** : le code SE5 ne porte **aucune** notion de « central » / « controlHub » (l'`issuer` est une string opaque, identifiée par `config('federated_auth.expected_iss')`). Le document 20.5 est un **GUIDE D'INTÉGRATION** *pour* un développeur controlHub, mais il ne doit **JAMAIS** demander d'introduire un couplage controlHub dans le code SER. controlHub est *une* instance d'IdP externe de confiance, pas un concept câblé dans SE5. **Cette story ne modifie PAS le code pour y introduire « controlHub ».**

---

## Scope strict & frontières

### IN-SCOPE (ce que la story livre)

1. **Un fichier de documentation** dans `_bmad-output/planning-artifacts/` (nom retenu : **`handoff-federated-login-controlhub.md`** — convention « handoff » du projet, cf. mémoire `feedback_doc_follows_code`). C'est le **seul livrable**.
2. Le document décrit le **contrat de JWT RÉELLEMENT IMPLÉMENTÉ** (lu dans le code 20.1, pas dans l'epic). Doit couvrir **exhaustivement les claims réels** attendus par `FederatedJwtVerifier` :
   - `sub` (identifiant externe stable = clé de l'`ExternalIdentity`),
   - `login` (login d'affichage — **requis non-vide** dans le verifier),
   - `name`, `email` (profil d'affichage — synchronisés à chaque login, claim vide → valeur préservée — 20.2 D-3),
   - **`role`** (NOM de rôle asséré = le contrat ; jamais une liste de permissions),
   - `iss` (émetteur de confiance = `expected_iss`, comparé en `hash_equals`),
   - **`aud`** (= identifiant de CETTE instance SE5 = `expected_aud`, fallback `sambaedu.se4fs_name` ; anti-rejeu inter-instance ; supporte string OU array RFC 7519),
   - **`tier`** (= `federated-user` par défaut, défense en profondeur : un JWT « workstation » ne doit pas ouvrir une session humaine — **claim réel absent de l'épure de l'epic**, à documenter),
   - `exp`, `nbf`, `iat` (validés par la lib + `leeway` ±60 s),
   - `jti` (anti-rejeu à usage unique, requis non-vide),
   - `kid` (dans le **claim payload** ET utilisé pour le lookup de clé — **le verifier exige le claim `kid` dans le payload**, en plus du header),
   - signature **RS256** (clé publique PEM par `kid` côté SE5).
3. Le document décrit l'**endpoint de fédération réel** : **`POST /auth/federated/callback`** (route nommée `auth.federated.callback`, déclarée avant le catchall). **Transport = POST binding strict** (champ de formulaire `token`, façon SAML POST binding) ; **PAS** de query string `?token=` (fuite logs/historique/`Referer`), **PAS** de fallback `Authorization: Bearer` (retiré en review 20.1 #4). Token absent → **400**. Endpoint exempté de CSRF (`auth/federated/*`) car POST cross-site auto-soumis par l'IdP — la preuve est le JWT signé + anti-rejeu `jti`. Le rendu du formulaire auto-soumis est **côté controlHub** (hors SER).
4. Le document décrit le **mécanisme de confiance réel** : clé(s) **publique(s) PEM par `kid`** déclarée(s) dans `config('federated_auth.jwt.keys')` (map `kid → ['public' => path]`), support **multi-`kid`** pour rotation sans coupure. **AUCUN secret partagé par établissement** : SE5 ne détient que la clé publique, controlHub signe avec sa privée. `expected_iss` / `expected_aud` / `expected_tier` en config. Garde-fou : refus des clés `tests/fixtures/*` hors env testing/local. La clé PEM doit être lisible par `www-admin` (uid 599). Une clé publique **par instance** (décision Q-4 de 20.1) = décision de provisioning côté controlHub.
5. Le document décrit la **résolution de rôle RÉELLE (PIVOT 20.3)** — **CRITIQUE, divergence majeure vs l'epic** :
   - Il n'y a **PAS** de table de correspondance `role_map` (supprimée en 20.3). L'epic 20.3 (et l'AC d'epic de 20.5 qui parle de « table de mapping de rôle ») est **périmé** sur ce point — le document doit refléter le **lookup DIRECT**.
   - Le nom asséré (claim `role`) est cherché **directement** parmi les rôles **Spatie EXISTANTS** de l'instance (table `roles`, guard `web`), après **normalisation** (`trim` + `strtolower`, insensible à la casse).
   - Existe → ce rôle (nom canonique) est appliqué (`syncRoles`) ; **absent → 403 `role_unknown`, aucune session, AUCUNE création de rôle** à la volée.
   - **Modèle ouvert assumé (20.3 D-5)** : tout rôle existant est demandable (`super-admin` inclus s'il existe) ; **aucune liste blanche locale**. La sécurité repose sur la confiance dans l'IdP (JWT signé/anti-rejeu) + l'existence du rôle + l'invariant « inconnu → 403 ».
   - Le document doit dire à l'intégrateur controlHub : **asserter un `role` qui existe déjà dans l'instance cible** (rôles seedés `SambaRole`, ou rôles créés par controlHub dans l'instance).
6. Le document décrit les **erreurs normalisées RÉELLES** (codes du catalogue `FederatedJwtErrorCodes` + statuts HTTP réels) :
   - `federated.jwt.missing` / token POST absent → **400**,
   - `federated.jwt.malformed` → **401**,
   - `federated.jwt.signature_invalid` (couvre `alg:none`, confusion HS256/clé-publique, `kid` inconnu, **aucune clé configurée**) → **401**,
   - `federated.jwt.expired` → **401**, `federated.jwt.not_yet_valid` (`nbf` futur) → **401**,
   - `federated.jwt.iss_mismatch` (émetteur non configuré/inconnu) → **401**,
   - `federated.jwt.aud_mismatch` (`aud` présent mais ≠ instance) → **401**,
   - `federated.jwt.wrong_tier` → **401**,
   - `federated.jwt.missing_claim` (claim requis absent, y compris `aud` réellement absent) → **401**,
   - `federated.jwt.replayed` (même `jti` rejoué / course concurrente) → **401**,
   - `federated.role_unknown` (rôle valide mais inexistant en base) → **403**.
   - Préciser la convention : pas de message technique brut exposé au client ; le détail va dans le channel `federated-auth` (sans PII).
7. Le document décrit les **exigences d'audit** (20.4) côté lecture/exploitation :
   - audit **dénormalisé** : `actor_login` / `actor_external_sub` / `actor_name` / `actor_role` **copiés** au moment de l'action dans `external_action_audit_logs` (jamais une simple FK) → reste lisible après soft-delete ET anonymisation de l'identité,
   - périmètre audité : **mutations** (POST/PUT/PATCH/DELETE) **+ GET sensibles** (allowlist `audit.sensitive_get_routes`),
   - **LIMITE CONNUE à documenter** : l'audit HTTP **ne capture PAS** le canal Livewire (`livewire/update`), qui porte le gros des mutations admin natives (projet Livewire-first). Cette limite est **tracée** (mémoire `project_audit_http_misses_livewire`) et adressée par la **Story 20.6 (backlog)**. Le document doit l'énoncer honnêtement comme une limite observée.
8. Le document décrit le **cycle de vie de l'identité** (20.2) côté implications pour l'intégrateur :
   - **identité persistante ≠ accès permanent** : l'identité (le « qui ») est durable et **jamais hard-delete** ; l'accès est piloté par l'IdP à la connexion + `is_active` côté SE5,
   - réutilisation à la reconnexion (clé = `sub`), sync profil (claim présent écrase / vide préserve ; **jamais** le rôle ni `is_active` via le profil),
   - **rétention RGPD** : PII conservée `pii_ttl_days` (défaut **365 j** après `last_login_at`), puis **anonymisation** (`name`/`email`/`login` vidés, `external_sub` → `anon:<hmac>`), **jamais hard-delete** ; toggle `anonymize_enabled` (défaut **false**),
   - **anti-résurrection (20.2 D-4)** : une identité anonymisée qui se reconnecte (même `sub`, JWT valide) → **403** `identity_anonymized` — la purge RGPD n'est pas contournable par reconnexion ; réactivation = action admin,
   - une identité **révoquée** (`is_active=false`) ou **soft-deletée** → **403** au fresh login (pas de réactivation silencieuse).
9. Le document affirme explicitement le **principe domain-neutral** : SE5 reste générique (IdP configurable par `kid`/`iss`), aucune notion de « central »/« controlHub » dans le code ; le document est un guide d'intégration et **ne demande pas** d'introduire ce couplage. Le contrat = **un nom de rôle**, pas des permissions (mémoire `project_controlhub_federated_login`).
10. Le document inclut une **vérification d'écart epic ↔ code** : signaler les points où l'épure de l'epic (et l'AC d'epic de 20.5) diverge du code réel — notamment (a) **suppression de la table de mapping** (pivot 20.3), (b) **claim `tier`** non prévu par l'epic, (c) **POST binding strict** (pas de Bearer), (d) **limite Livewire** de l'audit. **Note de divergence**, pas de modification de code.

### HORS-SCOPE (ne pas faire)

- ❌ **Tout code applicatif SE5** : aucune modification de `app/`, `config/`, `routes/`, `database/`, `tests/`. *(Exception ultra-marginale : si la lecture du code pour rédiger le contrat révèle un écart de **commentaire/typo** trompeur — le SIGNALER dans le Dev Agent Record, NE PAS le corriger sans validation. Ne jamais toucher la logique.)*
- ❌ **Toute logique côté controlHub** (irundoo) : forge du JWT, gestion des techniciens externes et de leurs rôles, choix d'instance, rendu du formulaire POST auto-soumis. Le document *décrit* ce que controlHub doit produire, il ne l'implémente pas.
- ❌ **L'auth machine/poste** (reste iso-legacy AD+SMB — acteur distinct, mémoire `feedback_auth_iso_legacy`).
- ❌ Toute **réintroduction** d'une notion « central »/« controlHub » dans le code SER (anti-pattern domain-neutral).
- ❌ Le **endpoint JWKS** (clé publique statique en config au MVP).
- ❌ La **rétention/purge du journal d'audit lui-même** (`external_action_audit_logs`) — hors-scope MVP (20.4 Q-4).
- ❌ L'**audit du canal Livewire** = Story 20.6 (backlog). 20.5 le **documente comme limite**, ne le résout pas.
- ❌ Toute **modification des stories 20.1-20.4** (au-delà d'une note de divergence éventuelle si nécessaire — privilégier la consignation dans le doc 20.5 et le Dev Agent Record).

---

## Mode de livraison & contraintes opérationnelles

- **Livrable** = un fichier Markdown dans `_bmad-output/planning-artifacts/` (Repo A parent — mémoire `project_two_repos_topology`). **Pas de fichier de code** (Repo B).
- **Aucune commande VM** : la doc se dérive de la **lecture du code livré** (déjà en place). Aucun test, aucun `php artisan`, aucun SSH `/vm`.
- **Source de vérité = le CODE livré**, pas l'epic. Quand l'epic et le code divergent → **le code gagne**, et la divergence est notée (IN-SCOPE #10). Lire intégralement : `FederatedJwtVerifier`, `FederatedUserClaims`, `FederatedLoginController`, `FederatedRoleMapper`, `FederatedJwtErrorCodes`, `InvalidFederatedJwtException`, `config/federated_auth.php`, `routes/web.php` (route + ordre catchall), `config/logging.php` (channel), `ExternalIdentity` / `ExternalActionAuditLog` / `AuditExternalAction`, et le runbook QA `docs/qa/domains/federated-login.md`.
- **NE PAS committer** (l'orchestrateur s'en charge).

---

## Decisions (tranchées par SM — ne pas re-débattre, confirmer via Questions)

### D-1 — Le livrable est UN DOCUMENT, dans `_bmad-output/planning-artifacts/`
Nom : `handoff-federated-login-controlhub.md`. Conforme à l'AC d'epic (« un fichier est créé dans `_bmad-output/planning-artifacts/` p.ex. `handoff-federated-login-controlhub.md` ») et à la convention « handoff » du projet (`feedback_doc_follows_code`). Aucun code.

### D-2 — Source de vérité = le code RÉEL, jamais l'epic
L'epic 20.5 a été esquissé *avant* l'implémentation et **a divergé** (table de mapping supprimée en 20.3 ; claim `tier` ajouté ; POST binding strict sans Bearer ; limite Livewire de l'audit). Le contrat doit refléter le code livré et **lister les divergences** (IN-SCOPE #10). On ne « corrige » pas le code pour qu'il colle à l'epic.

### D-3 — Domain-neutral préservé : le doc ne demande aucun couplage controlHub dans le code
Le document est un guide *pour* controlHub, mais SE5 reste générique (IdP identifié par `expected_iss`/`kid`). Le doc doit explicitement rappeler ce principe et ne **jamais** suggérer d'ajouter un littéral « controlHub »/« central » dans le code SER. Vérifier qu'aucune recommandation du doc ne viole ce principe.

### D-4 — Contrat = nom de rôle, jamais permissions
Mémoire `project_controlhub_federated_login` : le contrat transporte un **nom de rôle** (l'intention). Le doc ne décrit jamais un échange de permissions. Conséquence du pivot 20.3 : le rôle asséré doit **exister** dans l'instance (seedé ou créé par controlHub).

### D-5 — Honnêteté sur les limites observées
L'AC d'epic exige « rédigé après l'implémentation et reflète ses limites observées ». Le doc documente franchement : (a) la **limite Livewire** de l'audit HTTP (→ 20.6), (b) le **modèle de rôle ouvert** (super-admin demandable s'il existe), (c) la **PII assumée** dans le journal d'audit (rétention propre non tranchée), (d) l'**anti-résurrection** RGPD. Pas d'enjolivement.

### D-6 — Lot Epic 20 testé ENSEMBLE par Henri en VM ; la doc peut être rédigée maintenant
Les 4 stories sources sont en `review` (code livré, pas encore mergé/done) ; Henri teste le **lot Epic 20 ensemble** en VM (smoke bout-en-bout : vrai JWT IdP → session). La doc **dérive du code livré** et peut donc être rédigée dès maintenant (`feedback_doc_follows_code`). Si un test VM révèle un écart, le doc sera ajusté avant que l'epic passe `done` (le doc suit le code).

---

## Story

As a **développeur controlHub (irundoo)**,
I want **un document décrivant précisément le contrat de fédération réellement implémenté par SE5 (claims JWT, endpoint, mécanisme de confiance, résolution de rôle directe, erreurs normalisées, audit, cycle de vie d'identité)**,
so that **je puisse intégrer la fédération d'un technicien externe vers une instance SE5 sans dupliquer la logique d'auth SER, sans partager de secret par établissement, et sans introduire de couplage « central » dans le code SE5**.

## Acceptance Criteria

1. **Given** les Stories 20.1-20.4 livrées (`review`), **When** la story est terminée, **Then** un fichier **`_bmad-output/planning-artifacts/handoff-federated-login-controlhub.md`** existe et décrit le contrat **réellement implémenté** (dérivé du code, pas de l'epic).
2. **Given** le document, **Then** il liste **exhaustivement les claims JWT réels** attendus par `FederatedJwtVerifier` : `sub`, `login`, `name`, `email`, `role`, `iss`, `aud`, **`tier`**, `exp`, `nbf`, `iat`, `jti`, `kid`, signature **RS256** — avec, pour chacun, son rôle, sa criticité et le comportement en cas d'absence/non-conformité.
3. **Given** le document, **Then** il décrit l'**endpoint réel** **`POST /auth/federated/callback`** (route `auth.federated.callback`), le **POST binding strict** (champ `token`, pas de query string, pas de `Authorization: Bearer`, token absent → 400), l'exemption CSRF et le fait que le rendu du formulaire auto-soumis est **côté controlHub**.
4. **Given** le document, **Then** il décrit le **mécanisme de confiance réel** : clé(s) publique(s) **PEM par `kid`** en config (`federated_auth.jwt.keys`), `expected_iss` / `expected_aud` / `expected_tier`, support multi-`kid` (rotation), **AUCUN secret par établissement**, clé publique par instance (provisioning controlHub), garde-fou fixtures de test.
5. **Given** le document, **Then** il décrit la **résolution de rôle RÉELLE (pivot 20.3)** : **lookup direct** du nom asséré (normalisé `trim`+`strtolower`, insensible casse) parmi les rôles Spatie **existants**, **AUCUNE table `role_map`**, **AUCUNE création de rôle**, absent → **403 `role_unknown`** sans session, **modèle ouvert** (super-admin demandable s'il existe). Le document **signale explicitement** que l'épure de l'epic (table de mapping) est périmée.
6. **Given** le document, **Then** il documente les **erreurs normalisées réelles** avec leurs **codes** (`FederatedJwtErrorCodes`) et **statuts HTTP** : token absent → 400 ; `role_unknown` → 403 ; signature invalide (dont `alg:none`, confusion d'algo, `kid` inconnu, aucune clé) → 401 ; jeton expiré / `nbf` futur → 401 ; `iss`/`aud`/`tier` mismatch → 401 ; claim manquant → 401 ; `jti` rejoué → 401.
7. **Given** le document, **Then** il documente les **exigences d'audit** (identité **dénormalisée** copiée au moment de l'action, lisible après soft-delete/anonymisation ; périmètre mutations + GET sensibles) **et la LIMITE connue** : l'audit HTTP **ne capture pas** `livewire/update` (canal Livewire), tracé pour **Story 20.6 (backlog)**.
8. **Given** le document, **Then** il documente le **cycle de vie d'identité** (20.2) : identité persistante ≠ accès permanent ; jamais hard-delete ; rétention PII bornée (TTL 365 j) puis **anonymisation** (`external_sub`→`anon:<hmac>`, toggle OFF par défaut) ; **anti-résurrection 403** ; révoquée/soft-deletée → 403.
9. **Given** le document, **Then** il affirme le **principe domain-neutral** (IdP configurable par `kid`/`iss`, zéro « central » codé) et le **contrat = nom de rôle (pas permissions)** ; **And** il ne contient **aucune recommandation** d'introduire un couplage controlHub dans le code SER.
10. **Given** le document, **Then** il comporte une **section « Écarts epic ↔ code »** listant au minimum : (a) suppression table de mapping (pivot 20.3), (b) claim `tier`, (c) POST binding strict sans Bearer, (d) limite Livewire de l'audit — **rédigé après l'implémentation, reflétant ses limites observées**.
11. **Given** la story, **Then** **aucun fichier de code applicatif** (`app/`, `config/`, `routes/`, `database/`, `tests/`) n'est créé ni modifié (livrable = doc seul). Tout écart de commentaire/typo repéré est **signalé** (Dev Agent Record), pas corrigé.

## Tasks / Subtasks

- [x] **T0** — Lecture exhaustive du code livré (no write) : `FederatedJwtVerifier` (claims réels + ordre de validation + codes), `FederatedUserClaims` (DTO + `toLoggableArray`), `FederatedLoginController` (extraction token, flux, 403 role_unknown, consommation jti en dernier, bridge session), `FederatedRoleMapper` (lookup direct, normalisation), `FederatedJwtErrorCodes` + `InvalidFederatedJwtException` (codes ↔ statuts HTTP), `config/federated_auth.php` (jwt/keys/expected_*/replay/safety/retention/audit), `routes/web.php` (route + ordre catchall + CSRF), `config/logging.php` (channel `federated-auth`), `ExternalIdentity` (4 états/anonymisation), `ExternalActionAuditLog` + `AuditExternalAction` (dénormalisation + périmètre + limite Livewire), runbook QA `docs/qa/domains/federated-login.md`. Noter chaque écart epic↔code. (AC: 2-10,11)
- [x] **T1** — Rédiger l'en-tête + le contexte du document : objet (guide d'intégration controlHub), principe domain-neutral, contrat = rôle (pas permissions), avertissement « source = code réel, pas epic ». (AC: 1,9)
- [x] **T2** — Section « Contrat JWT » : tableau exhaustif des claims réels (`sub`/`login`/`name`/`email`/`role`/`iss`/`aud`/`tier`/`exp`/`nbf`/`iat`/`jti`/`kid` + RS256), rôle/criticité/comportement-si-absent ; exemple de payload JWT (valeurs fictives, domain-neutral). (AC: 2)
- [x] **T3** — Section « Endpoint & transport » : `POST /auth/federated/callback`, POST binding strict (`token`), pas de query/Bearer, 400 si absent, CSRF except, formulaire auto-soumis côté controlHub. (AC: 3)
- [x] **T4** — Section « Mécanisme de confiance » : clés publiques PEM par `kid`, `expected_iss`/`expected_aud`/`expected_tier`, rotation multi-kid, zéro secret par étab, clé par instance, garde-fou fixtures, lisibilité `www-admin`. (AC: 4)
- [x] **T5** — Section « Résolution de rôle (pivot 20.3) » : lookup direct, normalisation, pas de role_map, pas de création, 403 si absent, modèle ouvert ; **encart de divergence** vs epic (table de mapping périmée). (AC: 5,10)
- [x] **T6** — Section « Erreurs normalisées » : tableau code (`FederatedJwtErrorCodes`) ↔ statut HTTP ↔ cause ↔ ce que controlHub doit corriger. (AC: 6)
- [x] **T7** — Section « Audit & limites » : audit dénormalisé, périmètre mutations+GET sensibles, **limite Livewire → 20.6** ; PII assumée dans le journal. (AC: 7,10)
- [x] **T8** — Section « Cycle de vie d'identité & RGPD » : persistance ≠ accès, jamais hard-delete, rétention TTL 365 j, anonymisation `anon:<hmac>` (toggle OFF défaut), anti-résurrection 403, révoquée/soft-deletée → 403. (AC: 8)
- [x] **T9** — Section « Écarts epic ↔ code » : (a) table de mapping supprimée, (b) `tier`, (c) POST binding strict sans Bearer, (d) limite Livewire ; + note « doc rédigée après implémentation, reflète les limites observées ». (AC: 10)
- [x] **T10** — Relecture finale : vérifier qu'aucune section ne recommande un couplage controlHub dans le code SER (AC9), que tous les codes/statuts/claims correspondent **mot pour mot** au code, et qu'aucun fichier de code n'a été touché (AC11). Mettre à jour `sprint-status.yaml` (20-5 → la transition de statut est gérée par le workflow / l'orchestrateur). (AC: 9,11)

## Dev Notes

- **Le contrat doit refléter le CODE, pas l'epic.** Plusieurs divergences critiques (voir « Écarts » T9). En particulier la **table `role_map` n'existe plus** (supprimée en 20.3) : ne **jamais** documenter de table de mapping — c'est un **lookup direct**.
- **Claims réels (lus dans `FederatedJwtVerifier::verify()`)** : requis non-vides = `sub`, `jti`, `kid`, `iss`, `tier`, `role`, `login` (+ `exp` ≠ 0, + `aud` présent). `name`/`email` tolérés vides (profil). `kid` est exigé **dans le payload** (claim) en plus de servir au lookup de clé — détail réel à documenter fidèlement.
- **`aud`** : supporte string OU array (RFC 7519) ; fallback `expected_aud` vide → `sambaedu.se4fs_name`. Comparaisons `iss`/`aud`/`tier` en `hash_equals`.
- **Codes ↔ statuts** (source : `InvalidFederatedJwtException` + controller) : la majorité des rejets JWT = **401** ; `role_unknown` = **403** ; token POST absent = **400** (levé par `FederatedLoginController::extractToken`, code `Missing federated token`). `replayed` = 401 (controller, après consommation `jti` en dernier dans la transaction).
- **Endpoint** : `POST /auth/federated/callback` (`routes/web.php:1000`), nommé `auth.federated.callback`, avant le catchall ; pas de Bearer (retiré review 20.1 #4) ; CSRF except `auth/federated/*`.
- **Confiance** : `config/federated_auth.php` → `jwt.keys` (map kid→public PEM), `jwt.algorithm='RS256'`, `expected_iss`/`expected_aud`/`expected_tier='federated-user'`, `safety.forbid_test_keys_in_production`. Pas de clé privée côté SE5.
- **Rôle (20.3)** : `FederatedRoleMapper::resolve()` = `Role::where('guard_name','web')->whereRaw('LOWER(name)=?',[$normalized])->value('name')` ; `null` → 403 `role_unknown` ; aucune création (`firstOrCreate` retiré).
- **Audit (20.4)** : `ExternalActionAuditLog` dénormalisé (`actor_login`/`actor_external_sub`/`actor_name`/`actor_role` copiés) ; middleware **terminable** `AuditExternalAction` sur sessions fédérées ; périmètre mutations + `audit.sensitive_get_routes`. **LIMITE : `livewire/update` non audité** (mémoire `project_audit_http_misses_livewire`) → 20.6.
- **Identité/RGPD (20.2)** : 4 états (active/désactivée/soft-deletée/anonymisée) ; anonymisation `external_sub`→`anon:<hmac>` (P-4 : HMAC clé dédiée `retention.hash_key`, pas sha256 nu — **vérifier le terme exact dans le doc**, le code 20.2 mentionne `anon:<sha256>` dans certains commentaires mais le code applique un **HMAC** depuis le correctif P-4 ; documenter `anon:<hmac>` et signaler l'incohérence de commentaire en Dev Agent Record si confirmée) ; toggle `anonymize_enabled=false` défaut ; anti-résurrection 403.
- **Domain-neutral** : `issuer` opaque, aucun littéral controlHub dans le code ; le doc respecte ce principe (AC9).
- **Pas de code** : c'est une story de pure documentation. Le seul artefact écrit est le fichier `.md` dans `_bmad-output/planning-artifacts/`.

### Project Structure Notes

- Livrable unique : `_bmad-output/planning-artifacts/handoff-federated-login-controlhub.md`.
- Aucun fichier sous `app/`, `config/`, `routes/`, `database/`, `tests/`, `resources/`.
- Cohérent avec la convention « handoff » + `feedback_doc_follows_code` (le contrat suit le code).

### References

- [Source: _bmad-output/planning-artifacts/epics.md#Story 20.5 : Contrat d'intégration controlHub (doc)] (AC d'epic — **partiellement périmé** : « table de mapping de rôle » supersédée par le pivot 20.3)
- [Source: _bmad-output/planning-artifacts/epics.md#Epic 20 : Authentification fédérée] (domain-neutral, contrat=rôle, séparation identité/accès)
- [Source: _bmad-output/planning-artifacts/architecture.md#Authentification Fédérée — Phase 2 IdP externe (Epic 20)] (flux, sécurité JWT, frontières)
- [Source: _bmad-output/implementation-artifacts/20-1-login-federe-jwt-controlhub.md] (claims réels, POST binding, codes, channel — note de supersession role_map)
- [Source: _bmad-output/implementation-artifacts/20-2-identite-externe-persistante.md] (cycle de vie, rétention RGPD, anonymisation, anti-résurrection)
- [Source: _bmad-output/implementation-artifacts/20-3-mapping-role-externe-spatie.md] (PIVOT : lookup direct, suppression role_map, modèle ouvert)
- [Source: _bmad-output/implementation-artifacts/20-4-audit-denormalise-actions-externes.md] (audit dénormalisé, périmètre, limite Livewire → 20.6)
- [Source: app/Auth/Federated/Jwt/FederatedJwtVerifier.php] (claims réels + ordre de validation + codes de rejet)
- [Source: app/Auth/Federated/Jwt/FederatedUserClaims.php] (DTO claims + jeu loggable sans PII)
- [Source: app/Auth/Federated/Http/FederatedLoginController.php] (extraction token POST, 403 role_unknown, jti consommé en dernier)
- [Source: app/Auth/Federated/FederatedRoleMapper.php] (lookup direct table roles, normalisation, null→403)
- [Source: app/Auth/Federated/Support/FederatedJwtErrorCodes.php] (catalogue de codes)
- [Source: app/Auth/Federated/Jwt/Exceptions/InvalidFederatedJwtException.php] (codes ↔ statuts HTTP 401/403)
- [Source: config/federated_auth.php] (jwt/keys/expected_*/replay/safety/retention/audit)
- [Source: routes/web.php:1000] (route POST /auth/federated/callback, avant catchall)
- [Source: config/logging.php:201] (channel federated-auth, replace_placeholders=false)
- [Source: app/Models/ExternalActionAuditLog.php] (audit dénormalisé, scopes, record())
- [Source: app/Http/Middleware/Auth/AuditExternalAction.php] (middleware terminable, périmètre, limite Livewire)
- [Source: app/Models/ExternalIdentity.php] (4 états, anonymisation)
- [Source: docs/qa/domains/federated-login.md] (runbook QA des 4 stories — scénarios stables, incidents)

## Dépendances

| Story | Statut | Nature de la dépendance |
|-------|--------|--------------------------|
| 20-1-login-federe-jwt-controlhub | `review` | Source du contrat : claims JWT, endpoint POST, codes d'erreur, channel, mécanisme de confiance. |
| 20-2-identite-externe-persistante | `review` | Source : cycle de vie d'identité, rétention RGPD, anonymisation, anti-résurrection. |
| 20-3-mapping-role-externe-spatie | `review` | Source : résolution **directe** du rôle (pivot — supersède la table de mapping de l'epic). |
| 20-4-audit-denormalise-actions-externes | `review` | Source : audit dénormalisé + **limite Livewire** (→ 20.6). |

> **Note** : les 4 dépendances sont en `review` (code livré, pas encore `done`). Le **lot Epic 20 est testé ensemble par Henri en VM** (smoke bout-en-bout). La doc 20.5 **peut être rédigée maintenant** car elle dérive du **code livré** (`feedback_doc_follows_code`) ; si un test VM révèle un écart, le doc sera ajusté avant clôture de l'epic. Story de suivi liée : **20-6** (audit Livewire, `backlog`).

## Questions pour Henri

- **Q-1 (terme d'anonymisation)** : le code 20.2 applique un **HMAC** (clé dédiée `retention.hash_key`, correctif P-4) mais certains commentaires/docs parlent encore de `anon:<sha256>`. Le contrat documentera **`anon:<hmac>`** (comportement réel). Confirmer qu'on garde le terme « HMAC » et qu'on signale (sans corriger) les commentaires `sha256` résiduels.
- **Q-2 (publication du contrat)** : le document vit dans `_bmad-output/planning-artifacts/`. Faut-il aussi prévoir une **copie/référence côté irundoo** (controlHub) ? *(Proposition SM : non au MVP — le handoff dans `_bmad-output/` suffit ; la diffusion vers irundoo est une décision d'orchestration hors story.)*

## Recommandation Modèle Dev

**Modèle recommandé : `opus`.**

Justification : bien qu'il s'agisse d'une story de **pure documentation** (aucun code, aucun test), le contrat décrit du **code de sécurité d'authentification** où une **erreur de contrat est dangereuse** (un claim oublié, un statut HTTP faux, ou — surtout — documenter la défunte table `role_map` au lieu du lookup direct ferait écrire un contrat **faux** que controlHub implémenterait de travers). La rédaction exige une **lecture rigoureuse et croisée de 4 stories + ~12 fichiers de code sécu**, avec plusieurs **divergences epic↔code subtiles** (pivot 20.3, claim `tier`, POST binding, HMAC vs sha256, limite Livewire) à repérer et formuler sans erreur. Cette exigence de fidélité au code dépasse le confort de sonnet et reste cohérente avec le choix `opus` des 4 stories sources.

## Dev Agent Record

### Agent Model Used

claude-opus-4-8[1m] (dev-cycle, rédaction directe par l'orchestrateur sur choix Henri — pas de subagent dev).

### Debug Log References

Aucun (story de pure documentation, aucun test/commande exécutés). Vérification des affirmations par lecture + `grep` du code source uniquement.

### Completion Notes List

- **Livrable unique créé** : `_bmad-output/planning-artifacts/handoff-federated-login-controlhub.md` (11 sections + checklist d'intégration + références code). Aucun fichier de code applicatif touché (AC11 respecté).
- **Chaque affirmation vérifiée contre le code réel** (pas l'epic) : `FederatedJwtVerifier`, `FederatedLoginController`, `FederatedRoleMapper`, `FederatedJwtErrorCodes`, `InvalidFederatedJwtException`, `config/federated_auth.php`, `AuditExternalAction`, `ExternalIdentityLifecycleService`, `ExternalIdentity`, `routes/web.php`.
- **Nuance Q-1 tranchée et documentée (§10)** : le code applique `hash_hmac('sha256', $sub, retention.hash_key)` → valeur réelle `anon:<hmac-sha256>` (HMAC salé, P-4). Plusieurs commentaires/docblocks et la clé `retention.retention_note` disent encore `anon:<sha256>` (imprécis). Le contrat documente le RÉEL (`anon:<hmac-sha256>`) et **signale** l'imprécision de commentaire **sans la corriger** (hors scope doc).
- **Écart de code repéré (non corrigé, AC11)** : le catalogue `FederatedJwtErrorCodes::JWT_MISSING` (`federated.jwt.missing`) existe mais le chemin réel « token POST absent » lève un `HttpException(400, 'Missing federated token')` brut (sans ce code). De même, `replayed` (401) et `role_unknown` (403) sont levés en `HttpException` brut par le controller, pas via `InvalidFederatedJwtException`. Documenté fidèlement en §6 (notes ¹²³).
- **Écarts epic↔code consignés (§10)** : (a) suppression table `role_map` (pivot 20.3), (b) claim `tier` ajouté, (c) POST binding strict sans Bearer, (d) limite Livewire de l'audit.
- **Domain-neutral vérifié (AC9)** : le document est un guide *pour* controlHub et ne recommande aucun couplage controlHub dans le code SE5 (§9 explicite).
- **Pas de runbook QA dédié** (story sans comportement testable). Référence vers le runbook existant `docs/qa/domains/federated-login.md` incluse en §Références du contrat.
- **POST-REVIEW — dérogation AC11 autorisée par Henri (Q-2)** : sur instruction explicite (« corrige de suite »), 4 fichiers de code ont été touchés en **commentaires uniquement** (alignement `anon:<sha256>` → `anon:<hmac-sha256>`, aucune logique modifiée, `php -l` 0 erreur) : `config/federated_auth.php`, `app/Auth/Federated/ExternalIdentityLifecycleService.php`, `app/Models/ExternalIdentity.php`, `app/Console/Commands/FederatedPurgeIdentitiesCommand.php`. Dérogation assumée à l'AC11 (« aucun fichier de code applicatif touché ») : la correction reste cosmétique (commentaires) et résout la dette signalée en §10 du contrat.
- **POST-REVIEW — Q-1 tranchée par Henri** : approvisionnement de la clé publique controlHub via **handshake** + **endpoint de renouvellement dédié** (à implémenter, hors scope) ; la config statique mono-`kid` du MVP est transitoire. Contrat §4 mis à jour pour refléter cette direction cible.

### File List

- `_bmad-output/planning-artifacts/handoff-federated-login-controlhub.md` *(créé — livrable unique)*
- `_bmad-output/implementation-artifacts/20-5-doc-contrat-integration-controlhub.md` *(mis à jour — status, tâches, dev record)*
- `_bmad-output/implementation-artifacts/sprint-status.yaml` *(mis à jour — 20-5 → review)*
- `_bmad-output/codeReviews/20-5.md` *(créé — doc de review)*
- `_bmad-output/backlog.html` *(mis à jour — 20-5 → review)*
- **Post-review (commentaires only, dérogation AC11 autorisée Henri Q-2)** : `config/federated_auth.php`, `app/Auth/Federated/ExternalIdentityLifecycleService.php`, `app/Models/ExternalIdentity.php`, `app/Console/Commands/FederatedPurgeIdentitiesCommand.php`

## Change Log

- 2026-06-03 — Story 20.5 DEV TERMINÉ (claude-opus-4-8[1m], rédaction directe orchestrateur dev-cycle) → review. Livrable unique créé : `_bmad-output/planning-artifacts/handoff-federated-login-controlhub.md` (11 sections + checklist + références code). 11 AC couverts ; **zéro fichier de code applicatif touché** (AC11). Contrat dérivé du CODE RÉEL des stories 20.1-20.4 (vérif par lecture/grep). Écarts epic↔code consignés (§10) : suppression `role_map` (pivot 20.3), claim `tier`, POST binding strict sans Bearer, limite Livewire de l'audit. Q-1 documentée : anonymisation réelle = `anon:<hmac-sha256>` (HMAC salé P-4) ; commentaires/`retention_note` résiduels `anon:<sha256>` imprécis, **signalés non corrigés**. Écart de code signalé non corrigé : `JWT_MISSING`/`replayed`/`role_unknown` levés en `HttpException` brut par le controller (pas via le catalogue/exception) — documenté fidèlement en §6. Recommandation review : **sonnet** (modèle opposé du dev opus).
- 2026-06-03 — Story 20.5 CRÉÉE par SM/architecte (claude-opus-4-8[1m], branche `sso`) → backlog → ready-for-dev. Livrable = **document** `handoff-federated-login-controlhub.md` (contrat d'intégration controlHub dérivé du code RÉEL des stories 20.1-20.4). 11 AC, T0-T10, 6 décisions D-1..D-6, 2 questions Q-1/Q-2. Points cadrés : claims JWT réels (dont `tier`), POST binding strict (pas de Bearer), confiance par clé publique/`kid` (zéro secret par étab), **résolution directe du rôle (pivot 20.3, plus de `role_map`)**, erreurs normalisées (codes+statuts réels), audit dénormalisé + **limite Livewire (→20.6)**, cycle de vie/RGPD (anonymisation, anti-résurrection), **domain-neutral préservé** (le doc ne couple jamais le code à controlHub), section « écarts epic↔code ». HORS-SCOPE : tout code SE5, logique controlHub, auth machine. Dépendances 20-1/20-2/20-3/20-4 en `review` (lot testé ensemble en VM ; doc dérive du code livré — `feedback_doc_follows_code`). Recommandation dev : **opus**.

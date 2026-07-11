# Story 43.3 : Serveur — cadence de propagation pilotée (`ttl_seconds` dynamique)

Status: review

<!-- Source d'autorité : _bmad-output/planning-artifacts/epics-application-immediate.md
     (Epic 43, Story 43.3 — Epic 43 ne figure PAS dans epics.md). Consommateur cible :
     Epic 41 (mode examen, _bmad-output/planning-artifacts/epics-mode-examen.md) — NON
     développé à ce jour (aucune story 41-x dans implementation-artifacts) : le critère
     « bascule sensible » est conçu BRANCHABLE sans dépendre d'une donnée 41.x inexistante.
     Toutes les lignes de code citées ont été vérifiées sur la branche ultradev/43-3
     (2026-07-11). -->

## Story

En tant qu'**administrateur SE5**,
je veux **que les postes concernés par une bascule sensible (mode examen en tête) resserrent
automatiquement leur cadence de check-in**,
afin de **borner la latence de propagation des réglages (flag/déflag dans la journée, retour à la
normale) sans toucher à l'agent ni ouvrir un canal push**.

## Contexte & intention

Le transport agent est 100 % polling avec un TTL **global constant** : 3600 s
(`config/agent.php:40`, consommé par `StateCompiler::compile()` à
`app/Services/Agent/StateCompiler.php:74`). Un poste allumé avec session ouverte peut donc mettre
1 h à *recevoir* une bascule. Or le levier existe **déjà côté agent, livré et publié** : le service
honore le `ttl_seconds` de chaque enveloppe `/state` (`Agent.EffectiveInterval`,
`agent/shared/loop.go:584-599`, clamp `MinServerIntervalSeconds=60` /
`MaxServerIntervalSeconds=86400` définis `loop.go:210-211`, amorçage depuis le cache
`primeServerTtlFromCache` `loop.go:572-580`). Le levier de cadence est donc **serveur-only** (FR-A4).

**Ce que la story livre** :

1. **TTL par contexte** : `StateCompiler::compile()` calcule `ttl_seconds` depuis le
   `TargetContext` via un résolveur dédié — TTL court (défaut 90 s) pour un poste en « bascule
   sensible », défaut global sinon.
2. **Critère V1 de la bascule sensible** (tranché ici, §Décisions D1) : existence d'un
   `capability_assignments` avec `value` non-null dont la capacité (slug) figure dans
   `config('agent.ttl_sensitive_capabilities')`, sur une maille du contexte. Défaut =
   `['restrict_run']` : la capacité n'existe pas encore (41.2) → aucune ligne → comportement
   strictement inchangé aujourd'hui, et 41.3 se branche **sans une ligne de code** ici.
3. **ETag/hash insensibles au TTL** : `ttl_seconds` entre AUJOURD'HUI dans le hash d'état
   (`StateHasher::VOLATILE_STATE_KEYS = ['generated_at']` seul,
   `app/Services/Agent/StateHasher.php:31`) — la story le corrige (PHP **et** miroir Go de
   contrat, cf. piège n° 2).
4. **Abaissement du défaut global = documenté, PAS décidé** : aucune modification du défaut 3600 s
   en code ; recommandation (600 s) + procédure opérateur + mesure de charge + effets de bord
   documentés (§AC5).
5. **Limites honnêtes documentées** : le TTL court n'atteint l'agent qu'à son **prochain** 200 ;
   le défaut global borne le pire cas ; le canal wake serveur→agent est hors-scope.

**Zéro comportement agent modifié, zéro publication de release** : le mécanisme agent est livré
depuis la 2.2.0 (`agent/shared/version.go:28`).

## ⚠️ Pièges & tensions (lire AVANT de coder)

1. **Piège n° 1 — la livraison du TTL passe UNIQUEMENT par un 200.** L'agent note le TTL au parse
   d'une enveloppe (`noteServerTtl`, `loop.go:561-565`) ; un 304 ne re-livre rien. En excluant
   `ttl_seconds` du hash (AC3), un changement de TTL **seul** (sans changement d'items) ne franchit
   pas le cache 304. C'est ASSUMÉ et cohérent avec le cas d'usage : la bascule examen 41.3
   crée/supprime des assignments qui changent les items machine (`internet_access=off` → item
   `firewall`) → l'ETag machine change → 200 → TTL court livré dans la même réponse. Pour un
   changement de TTL sans changement d'état (ex. abaissement du défaut global), le remède existe
   déjà : « forcer la synchro » (Story 24.7, `agent_sync_requested_at` → bypass du 304 dans
   `StateController::show()`, `app/Http/Controllers/Api/V1/Agent/StateController.php:74-82`) —
   l'enveloppe est re-livrée avec le TTL frais. **Documenter, ne rien coder de plus.**

2. **Piège n° 2 — le hash d'état a un MIROIR Go de contrat + DEUX constantes gelées jumelles.**
   `agent/shared/hasher.go:47` porte `volatileStateKeys = []string{"generated_at"}` et
   `agent/shared/hasher_test.go:33` le hash gelé `frozenStateHash`, dupliqué À L'IDENTIQUE depuis
   `tests/Unit/Services/Agent/ContractV1Test.php:217` (`FROZEN_STATE_HASH`, test croisé NFR13 sur
   le golden partagé `tests/Fixtures/Agent/state.v1.json`). Exclure `ttl_seconds` côté PHP seul
   ferait diverger les deux miroirs de contrat. **Aligner les deux** : ajouter `"ttl_seconds"` à
   `volatileStateKeys` (Go) et à `VOLATILE_STATE_KEYS` (PHP), recalculer le hash gelé et bumper
   **les deux constantes à l'identique** (« Re-bumpé SCIEMMENT par la Story 43.3 », patron des
   bumps 27.x dans l'en-tête de `hasher_test.go`). `HashState` Go n'a **AUCUN appelant runtime**
   (vérifié : seul le test l'appelle ; l'agent stocke l'ETag verbatim et ne recalcule jamais le
   hash d'état) → aucun changement de comportement agent, **aucune publication requise**. Pas de
   bump `version.go` : la règle « éditer l'agent → bump » protège contre « réglage sans effet =
   binaire antérieur », impossible ici (fonction sans appelant runtime) ; un bump créerait une
   version fantôme jamais publiée. Justifier ce choix dans le commit/Dev Agent Record.

3. **Piège n° 3 — le seuil de présence est couplé au TTL global : `2 × agent.ttl_seconds`.**
   `Workstation::isAgentSilent()` (`app/Models/Workstation.php:451`), `agentPresence()`
   (`Workstation.php:468`) et `WorkstationGroupRepository` (`:229`, `:286`, `:340`) dérivent
   « muet »/« online » de `2 × config('agent.ttl_seconds')`. Abaisser le défaut global (env)
   resserre CES seuils immédiatement, alors que les agents n'adoptent la nouvelle cadence qu'à
   leur prochain 200 (piège n° 1) → faux « silencieux » massifs pendant la transition. Le TTL
   **par contexte** de cette story n'y touche pas (un poste qui polle PLUS souvent que le seuil
   reste « online ») — mais la procédure d'abaissement du défaut DOIT documenter ce couplage et
   le remède (synchro forcée du parc). NE PAS refactorer les seuils de présence (hors scope).

4. **Piège n° 4 — ne pas inclure `internet_access` dans la liste des capacités sensibles.**
   L'exemption enseignante (FR-E4, epic 41) est un assignment **permanent** `internet_access=on`
   sur le groupe logique du poste prof : slug dans la liste ⇒ TTL court PERMANENT pour ces postes
   (poll 90 s à vie, à tort). Le critère V1 ne lit pas la valeur métier ; la liste ne doit contenir
   que des capacités dont les assignments sont **transitoires par construction** (posés au flag,
   purgés au déflag — `restrict_run` selon 41.3). Documenter ce piège dans le commentaire de la
   clé config.

5. **Piège n° 5 — déterminisme du compilateur.** Le TTL calculé dépend du contexte mais doit
   rester **stable entre deux compilations du même état** (docblock `StateCompiler`, exigence
   ETag). Le résolveur ne lit que des données persistées (assignments + config) — jamais
   d'horloge, jamais d'aléa. Pas de branche temporelle (« récemment modifié ») : rejetée en D1
   précisément pour ça.

6. **Piège n° 6 — `StateCompiler` est « lecture + calcul pur », mais ses PROVIDERS lisent déjà
   Postgres.** Le résolveur fait UNE requête `capability_assignments` (patron exact :
   `AbstractCapabilityStateProvider::resolveOverrides()`,
   `app/Services/Agent/Providers/AbstractCapabilityStateProvider.php:432-476` — morphs FQCN, pas
   d'alias). Early-return sans requête si la liste config est vide ou si aucune capacité des slugs
   listés n'existe. AUCUNE écriture (le compilateur n'écrit RIEN, pas même `agent_*`).

7. **Piège n° 7 — tests unitaires `StateCompilerTest` sans DB.** `StateCompilerTest` (Unit)
   construit le compilateur à la main ; le nouveau paramètre constructeur casse ces
   instanciations. Injecter le résolveur partout, et dans les tests Unit un **fake/stub** du
   résolveur (pas de requête SQL en Unit). Les cas SQL du critère vivent en Feature (sqlite,
   `RefreshDatabase`) — attention piège connu : sqlite n'applique pas les varchar
   (`project_sqlite_tests_no_varchar_enforcement`), sans impact ici.

8. **Piège n° 8 — collision de golden avec 43.2 (parallèle).** 43.2 bumpe AUSSI les golden files
   (hint `refresh` dans les payloads → hash d'items ET d'état changent). Conflit attendu sur
   `state.v1.json`/constantes gelées au merge : se résout en RECALCULANT le hash gelé sur le
   golden fusionné (les deux tests croisés PHP/Go le vérifient). Le signaler dans le Dev Agent
   Record si 43.2 est déjà passée.

## Décisions de design (tranchées au create-story)

- **D1 — Critère TTL court V1 = liste config de capacités sensibles, PAS le flag examen 41.3, PAS
  de notion générique « bascule en attente ».** L'epic laissait le choix ; 41.3 n'existe pas (ni
  modèle profil, ni flag salle) — en dépendre bloquerait la story. Une notion générique « bascule
  en attente » (fenêtre temporelle post-changement d'assignment) casserait le déterminisme (piège
  n° 5) et sur-concevrait (`feedback_no_overengineered_choices` : règle dérivable → énoncer,
  avancer). Le retenu : **un point d'extension unique** (`AgentTtlResolver::ttlSeconds()`) + une
  source V1 dérivable en une phrase — « un override réel d'une capacité déclarée sensible touche
  ce contexte » — branchement 41.3 = zéro code (slug `restrict_run` déjà dans le défaut config).
  Ni nouvelle table, ni nouvelle colonne, ni env pour la liste (array PHP en config).
- **D2 — `value` non-null exigé** : une ligne `capability_assignments.value = null` = « repli sur
  le défaut diffusé » (migration `2026_06_18_100200`, commentaire colonne) — pas une bascule. Pas
  d'interprétation de la valeur au-delà (pas de sémantique par capacité en V1).
- **D3 — Mailles du critère = miroir de l'applicabilité des capacités** : poste + chaîne physique
  ÉTENDUE aux ancêtres (`array_keys($ctx->physicalGroupDepths)`) ∪ parcs logiques directs +
  user + groupes user (iso `resolveOverrides()`). Un `restrict_run` posé sur un parent de salles
  s'applique par hérédité → le TTL court doit suivre. Le contexte machine-only (user null) voit
  les assignments de la salle → l'enveloppe MACHINE (celle qui cadence le service, piège n° 1)
  porte bien le TTL court.
- **D4 — TTL court = `config('agent.ttl_sensitive_seconds')`, défaut 90 s** (fenêtre epic
  60-120 s), plancher serveur `max(60, …)` (iso patron planchers `config/agent.php` ; l'agent
  clampe de toute façon à 60 s — pas de dé-clamp possible côté serveur).
- **D5 — Défaut global INCHANGÉ en code (3600 s)** : l'abaissement (recommandation 600 s) est une
  action opérateur documentée (`AGENT_STATE_TTL_SECONDS` en env + `config:cache` + chown, cf.
  `project_vm_config_cache_not_synced`) avec plan de mesure — décision réversible, jamais gravée.
- **D6 — Correction du hash des DEUX côtés du contrat** (piège n° 2), sans bump de version agent.

## Acceptance Criteria

### AC1 — TTL par contexte dans l'enveloppe (FR-A4)

- Nouveau service `App\Services\Agent\AgentTtlResolver` : méthode publique unique
  `ttlSeconds(TargetContext $ctx): int`.
  - Retourne `max(60, (int) config('agent.ttl_sensitive_seconds'))` si le contexte est en
    « bascule sensible » (AC2), sinon `max(1, (int) (config('agent.ttl_seconds') ?? 3600))`
    (plancher et fallback iso ligne actuelle `StateCompiler.php:74` — le défaut de `config()` ne
    couvre pas une clé présente mais null, garder le `??`).
- `StateCompiler` reçoit le résolveur au constructeur (wiring dans `AgentServiceProvider`, là où
  le compilateur est déjà assemblé) ; `compile()` remplace la constante ligne 74 par
  `$this->ttlResolver->ttlSeconds($ctx)`. AUCUN autre changement dans `StateCompiler` (D2
  précédence intouchée, `specificity()` intouché).
- `config/agent.php` gagne deux clés commentées (sous `ttl_seconds`, ligne ~40) :
  - `ttl_sensitive_seconds` : `max(60, (int) env('AGENT_STATE_TTL_SENSITIVE_SECONDS', 90))` ;
  - `ttl_sensitive_capabilities` : `['restrict_run']` (array PHP, PAS d'env), commentaire
    expliquant le critère, le branchement 41.3 et le piège n° 4 (capacités transitoires
    uniquement, jamais `internet_access`).

### AC2 — Critère « bascule sensible » V1 (D1-D3)

- Le contexte est « en bascule sensible » ssi il existe AU MOINS une ligne
  `capability_assignments` telle que :
  - `capability_id` ∈ capacités dont `slug` ∈ `config('agent.ttl_sensitive_capabilities')` ;
  - `value` NON null (D2) ;
  - `(assignable_type, assignable_id)` matche une maille du contexte (D3) : `Workstation::class` +
    id du poste, `WorkstationGroup::class` + (chaîne physique étendue ∪ parcs logiques directs),
    `UserGroup::class` + groupes user, `User::class` + user — patron requête
    `AbstractCapabilityStateProvider::resolveOverrides()` (morphs FQCN).
- Early-return `false` SANS requête si la liste config est vide ; **deux requêtes** sinon (correction
  post-review #3 : pas « une requête EXISTS » — un `pluck('id')` key→id sur `capabilities` PUIS un
  `EXISTS` sur `capability_assignments`, pas de fetch de lignes sur ce second appel), exécutées
  aussi sur le chemin 304 (le compilateur tourne avant la comparaison d'ETag). Slug inconnu en
  config (capacité pas encore seedée — cas nominal tant que 41.2 n'est pas livrée) = zéro match,
  zéro erreur, zéro log d'erreur.
- Comportement AUJOURD'HUI strictement inchangé : défaut `['restrict_run']` + capacité inexistante
  ⇒ tous les contextes restent au TTL global (prouvé par la non-régression AC4/AC6).

### AC3 — ETag/hash insensibles au TTL (correction contrat, D6)

- `StateHasher::VOLATILE_STATE_KEYS` (`app/Services/Agent/StateHasher.php:31`) devient
  `['generated_at', 'ttl_seconds']` — docblocks de la constante et de `hashState()` mis à jour.
- Miroir Go : `volatileStateKeys` (`agent/shared/hasher.go:47`) aligné à l'identique, commentaire
  mis à jour. AUCUN autre fichier Go touché, AUCUN bump `version.go` (piège n° 2 — justification
  au Dev Agent Record).
- Constantes gelées recalculées et bumpées À L'IDENTIQUE des deux côtés :
  `ContractV1Test::FROZEN_STATE_HASH` (`tests/Unit/Services/Agent/ContractV1Test.php:217`) et
  `frozenStateHash` (`agent/shared/hasher_test.go:33`), chacune avec sa mention « Re-bumpé
  SCIEMMENT par la Story 43.3 (ttl_seconds volatil, §9) » iso patron des bumps 27.x. Le fixture
  `tests/Fixtures/Agent/state.v1.json` lui-même ne change PAS (le champ reste dans l'enveloppe,
  il est seulement exclu du hash).
- Doc contrat `docs/agent/contract-v1.md` : ligne `ttl_seconds` du tableau d'enveloppe (:53)
  marquée « champ volatil exclu du hash » iso `generated_at` (:52) ; §hashState (:125-127) cite
  les DEUX champs volatils.
- Test Go `TestHashStateExcludesVolatileGeneratedAt` : ajouter le cas jumeau pour `ttl_seconds`
  (muter/supprimer la clé → hash gelé inchangé), même patron.

### AC4 — Tests serveur (PHPUnit HÔTE, sqlite — jamais la VM pour la suite)

- **Unit `AgentTtlResolverTest`** (Feature si RefreshDatabase nécessaire — le critère est SQL,
  donc Feature) : TTL court si assignment sensible sur poste / salle directe / ANCÊTRE physique /
  parc logique / user / groupe user ; TTL défaut si `value` null, si slug hors liste, si liste
  vide (et alors AUCUNE requête — assertable via `DB::enableQueryLog()` ou compteur), si aucune
  capacité du slug n'existe.
- **Unit `StateCompilerTest`** : adapter les instanciations au nouveau constructeur avec un stub
  du résolveur (piège n° 7) ; cas TTL court injecté → `ttl_seconds` de l'enveloppe = valeur du
  stub ; non-régression ligne 78 (`assertSame(3600, …)`) via stub par défaut.
- **Unit `StateHasherTest`** : deux états identiques aux `ttl_seconds` près → `hashState()`
  IDENTIQUE (jumeau du test `generated_at`).
- **Feature `StateEndpointTest`** :
  - poste dont la salle porte un assignment sensible (seed capacité de test + assignment) →
    `ttl_seconds` court dans l'enveloppe 200, en contexte machine-only ET `?user=` ;
  - **ETag insensible au TTL** : même état d'items, TTL basculé (assignment sensible SANS
    projection compilable, ou config list mutée entre deux appels) → MÊME ETag, et un
    `If-None-Match` du premier appel obtient 304 au second ;
  - non-régression enveloppe : clés `['schema','generated_at','ttl_seconds','debug',…scopes]`
    inchangées (:146), durcissement `null → 3600` (:363-366) intact ;
  - plancher : `agent.ttl_sensitive_seconds` config à 10 → enveloppe sert 60.
- **Go** : `go test ./agent/shared/` vert (miroir + gelés) — exécuter sur l'HÔTE
  (`~/go-toolchain/go/bin` hors PATH, cf. `project_host_go_toolchain_path`).

### AC5 — Documentation : abaissement du défaut global + limites honnêtes

- `docs/agent/state-endpoint.md` (§ cadence) complété :
  - le TTL est désormais calculé PAR CONTEXTE (critère, clés config, valeur courte) ;
  - **limite 1** : le TTL court n'atteint l'agent qu'à son PROCHAIN check-in — la PREMIÈRE bascule
    reste bornée par l'ancien TTL ; le TTL court sert les bascules SUIVANTES et le retour à la
    normale ; c'est le défaut global qui borne le pire cas ;
  - **limite 2** : un changement de TTL sans changement d'items ne franchit pas le 304 (hash
    insensible au TTL) — livraison au prochain 200 naturel ou via « forcer la synchro » (24.7) ;
  - **le vrai temps réel (canal wake serveur→agent) est HORS-SCOPE**, à ouvrir seulement si le
    besoin résiduel le justifie ;
  - **procédure d'abaissement du défaut global** (action opérateur, AUCUN changement de code) :
    recommandation cible 600 s via `AGENT_STATE_TTL_SECONDS` ; AVANT de trancher, mesurer la
    charge réelle — les GET conditionnels 304 sont quasi gratuits, mais chaque cycle embarque
    aussi le `POST /report` (écritures check-in/ingestion : ×6 à 600 s) ; effets de bord à
    connaître : seuils de présence `2 × ttl` resserrés immédiatement (piège n° 3) + adoption
    paresseuse par les agents (piège n° 1) → recommander la synchro forcée du parc après le
    changement ; `config:cache` + chown sur la VM (`project_vm_config_cache_not_synced`).
- Commentaire de `config/agent.php::ttl_seconds` (ligne ~36-40) : pointer vers cette section
  (« défaut global — voir state-endpoint.md § cadence avant de l'abaisser »).

### AC6 — Zéro régression transverse

- Zéro comportement agent modifié : aucun fichier `agent/**` touché hormis
  `hasher.go`/`hasher_test.go` (miroir de contrat, AC3) ; `loop.go`, `engine.go`, `companion.go`,
  `version.go` INTOUCHÉS ; aucune publication de release requise.
- La suite `tests/Unit/Services/Agent/` + `tests/Feature/Api/V1/Agent/` passe (attention aux runs
  massifs VM : `project_vm_phpunit_bulk_run_false_failures` — valider sur l'hôte, par filtres).
- `perSessionReportedTypes()`, providers, précédence, dédup : intouchés (le diff `StateCompiler`
  se limite au constructeur + la ligne 74).

## Tasks / Subtasks

- [x] Task 1 — Config (AC1)
  - [x] `config/agent.php` : `ttl_sensitive_seconds` (plancher 60, env
        `AGENT_STATE_TTL_SENSITIVE_SECONDS`, défaut 90) + `ttl_sensitive_capabilities`
        (`['restrict_run']`, array PHP sans env) avec commentaires (critère, branchement 41.3,
        piège n° 4, renvoi doc).
- [x] Task 2 — `AgentTtlResolver` (AC1, AC2)
  - [x] Service `app/Services/Agent/AgentTtlResolver.php` : `ttlSeconds(TargetContext): int`,
        critère EXISTS deux-requêtes (pluck key→id + EXISTS, patron `resolveOverrides()`), early-return liste vide,
        `value` non-null, mailles D3, docblock complet (critère, D1, pièges n° 4/5).
  - [x] Wiring `AgentServiceProvider` + constructeur `StateCompiler` + remplacement ligne 74.
- [x] Task 3 — Hash/ETag insensibles au TTL (AC3)
  - [x] PHP : `VOLATILE_STATE_KEYS` + docblocks ; `FROZEN_STATE_HASH` recalculé/commenté.
  - [x] Go : `volatileStateKeys` + `frozenStateHash` (identique au PHP) + cas de test
        `ttl_seconds` jumeau ; `go test ./agent/shared/` vert sur l'hôte.
  - [x] `docs/agent/contract-v1.md` : tableau enveloppe (:53) + §hashState (:125-127).
- [x] Task 4 — Tests (AC4)
  - [x] Feature `AgentTtlResolverTest` (toutes mailles, value null, liste vide sans requête,
        slug inexistant).
  - [x] `StateCompilerTest` : stub résolveur + non-régressions.
  - [x] `StateHasherTest` : insensibilité `ttl_seconds`.
  - [x] `StateEndpointTest` : TTL court machine-only + `?user=`, ETag stable/304 à TTL variable,
        plancher 60, non-régressions enveloppe.
- [x] Task 5 — Documentation (AC5)
  - [x] `docs/agent/state-endpoint.md` § cadence : TTL par contexte + 2 limites + hors-scope wake
        + procédure d'abaissement du défaut (mesure, présence 2×ttl, synchro forcée, config:cache).
- [x] Task 6 — Vérifications finales (AC6)
  - [x] Diff `agent/**` limité à `hasher.go`/`hasher_test.go` ; justification « pas de bump » au
        Dev Agent Record.
  - [x] Suites agent PHP ciblées + `go test ./agent/shared/` vertes sur l'hôte.

## Dev Notes

### Fichiers à toucher (exhaustif prévu)

| Fichier | Nature |
|---|---|
| `app/Services/Agent/AgentTtlResolver.php` | NOUVEAU — résolveur TTL |
| `app/Services/Agent/StateCompiler.php` | constructeur + ligne 74 uniquement |
| `app/Providers/AgentServiceProvider.php` | wiring du résolveur |
| `config/agent.php` | 2 clés + commentaire renvoi doc sur `ttl_seconds` |
| `app/Services/Agent/StateHasher.php` | `VOLATILE_STATE_KEYS` + docblocks |
| `agent/shared/hasher.go` | `volatileStateKeys` (miroir contrat, SEUL fichier source Go) |
| `agent/shared/hasher_test.go` | `frozenStateHash` + cas `ttl_seconds` |
| `tests/Unit/Services/Agent/ContractV1Test.php` | `FROZEN_STATE_HASH` |
| `tests/Unit/Services/Agent/StateHasherTest.php` | test insensibilité |
| `tests/Unit/Services/Agent/StateCompilerTest.php` | stub résolveur |
| `tests/Feature/Api/V1/Agent/StateEndpointTest.php` | cas TTL/ETag |
| `tests/Feature/Services/Agent/AgentTtlResolverTest.php` | NOUVEAU |
| `docs/agent/contract-v1.md` | champ volatil |
| `docs/agent/state-endpoint.md` | § cadence |

### Patterns existants à imiter

- **Requête assignments par mailles** : `AbstractCapabilityStateProvider::resolveOverrides()`
  (`app/Services/Agent/Providers/AbstractCapabilityStateProvider.php:432-476`) — morphs FQCN
  (`WorkstationGroup::class` etc.), union chaîne physique étendue + parcs logiques directs, gardes
  listes vides.
- **Planchers config** : patron `max(1, (int) env(…))` de `config/agent.php` (ici `max(60, …)`
  pour la clé sensible).
- **Bump de hash gelé** : en-têtes commentés de `hasher_test.go:11-32` et
  `ContractV1Test.php` — chaque bump est daté/justifié « SCIEMMENT ».
- **Docblocks denses justifiant les invariants** : style `StateCompiler`/`StateHasher` (le POURQUOI
  dans le code, cf. stories 36.x).

### Ce qu'il ne faut PAS faire

- PAS de nouvelle table/colonne/migration (le critère vit dans config + données existantes).
- PAS de champ TTL dans les ITEMS ni dans le rapport agent — champ d'enveloppe uniquement.
- PAS de refactor des seuils de présence `2 × ttl` (piège n° 3) ni du throttle `throttle:60,1`
  de la route state (60 req/min par poste absorbe largement un poll à 60 s).
- PAS de logique temporelle dans le résolveur (déterminisme, piège n° 5).
- PAS de cache du verdict du résolveur (deux requêtes minuscules et indexées — pluck key→id +
  EXISTS — par compilation sont bon marché ; un cache APCu serait faux multi-process et
  violerait `project_apcu_cache_no_lock`). Corollaire (correction post-review #3) : PAS non plus
  de mémoïsation statique du mapping key→id — écartée en review (risque de staleness des statics
  entre requêtes dans un worker FPM après reseed des capacités > gain, tables minuscules
  indexées ; cf. Dev Agent Record).
- PAS de « synthèse » d'un canal push/wake — hors-scope explicite.

### Project Structure Notes

- Racine du repo = projet Laravel (`project_root_is_laravel`) ; services agent sous
  `app/Services/Agent/` ; aucun fichier sous `laravel/*` (obsolète).
- Tests : PHPUnit sur l'HÔTE (php 8.4 + sqlite), jamais la VM pour la suite
  (`project_phpunit_test_env_host_vs_vm`) ; Go via `~/go-toolchain/go/bin/go`.
- Story développée en worktree `ultradev/43-3` : ne PAS interagir avec la VM/lab ; pas de
  `git stash` ; pas de `git add -A`.

### References

- [Source: _bmad-output/planning-artifacts/epics-application-immediate.md#Story 43.3] — intention,
  AC-skeleton, FR-A4, NFR-A4/A5, limites à documenter.
- [Source: _bmad-output/planning-artifacts/epics-mode-examen.md#Story 41.3] — consommateur : flag
  examen = créer/retirer des assignments sur le parc physique (d'où le critère D1 par assignments).
- [Source: docs/agent/contract-v1.md#§3-§4] — enveloppe, canonicalisation, champs volatils.
- [Source: docs/agent/state-endpoint.md] — ETag/304, forcer la synchro (24.7).
- Code vérifié : `StateCompiler.php:74`, `config/agent.php:40`, `StateHasher.php:31`,
  `StateController.php:74-84`, `loop.go:210-211/561-599`, `hasher.go:47`, `hasher_test.go:33`,
  `ContractV1Test.php:217`, `Workstation.php:451/468`,
  `WorkstationGroupRepository.php:229/286/340`,
  `AbstractCapabilityStateProvider.php:432-476`,
  migration `2026_06_18_100200_create_capability_assignments_table.php`.

## Dépendances

- **Amont : AUCUNE.** Parallélisable avec 43.1 (agent) et 43.2 (hint refresh serveur) — seul point
  de contact avec 43.2 : les constantes de hash gelées/golden (piège n° 8, conflit de merge
  attendu et trivialement résoluble en recalculant).
- **Aval :** consommée par 41.3/41.4 (flag examen → le slug `restrict_run` est déjà dans le défaut
  config : branchement zéro-code ; badge « salle en examen » réactif côté UI).
- **Rollout :** aucun ordre imposé, aucune publication agent, aucune migration — un
  `config:cache` (+ chown) suffit sur la VM après merge.

## Recommandation Modèle Dev

**sonnet** — confirme la pressentie epic. Périmètre étroit et entièrement balisé : un service à une
méthode calqué sur un patron existant, deux clés config, une constante de hash des deux côtés d'un
contrat déjà outillé en tests croisés, et une couche de tests principalement mécanique. Les deux
seuls points délicats (miroir Go sans bump, ETag insensible au TTL) sont tranchés et documentés
ligne à ligne ci-dessus — aucune exploration ni arbitrage résiduel ne justifie opus.

## Dev Agent Record

### Agent Model Used

Claude Sonnet 5 (claude-sonnet-5), suivant le workflow BMAD dev-story.

### Debug Log References

Aucun (pas d'échec bloquant). Environnement HÔTE : `bootstrap/cache/` était
absent du worktree (répertoire gitignored, jamais suivi) → créé localement
pour que PHPUnit puisse démarrer l'application ; sans rapport avec la story,
non versionné.

### Completion Notes List

- **AC1/AC2/D1-D5** — `App\Services\Agent\AgentTtlResolver::ttlSeconds()` implémenté
  exactement selon le patron `AbstractCapabilityStateProvider::resolveOverrides()` :
  deux requêtes (`pluck('id')` key→id sur `capabilities` PUIS `EXISTS` sur
  `capability_assignments`, correction post-review #3 — pas « une requête
  EXISTS » comme écrit initialement), early-return sans AUCUNE requête si la
  liste config est vide OU si aucune capacité des clés listées n'existe
  (`Capability::whereIn('key', …)` vide). Mailles D3 = poste, chaîne physique
  étendue aux ancêtres ∪ parcs logiques directs, groupes user, user. `value`
  non-null exigé (D2, `whereNotNull`). `StateCompiler` reçoit le résolveur en
  3ᵉ paramètre du constructeur ; wiring dans `AgentServiceProvider` (singleton
  stateless).
- **Piège n° 4** (ne jamais lister `internet_access`) documenté dans le
  commentaire de `config/agent.php::ttl_sensitive_capabilities` ET dans le
  docblock du service.
- **AC3/D6 — correction du hash des DEUX côtés** : `StateHasher::VOLATILE_STATE_KEYS`
  devient `['generated_at', 'ttl_seconds']` ; miroir Go
  `agent/shared/hasher.go::volatileStateKeys` aligné à l'identique. Le nouveau
  hash gelé du golden `state.v1.json` (INCHANGÉ lui-même) a été **recalculé
  avec le `StateHasher` PHP réel** (script one-off exécuté sur l'hôte, pas
  committé) :
  `fc8a5324db242927b502bd4861d72bb526d6e652e4fb3501fd84e41af698738b` →
  `b1eb0560eec1c59a6908967f0c3e402dd79528591891ffddc33d90f2d0c8a3d7`, vérifié
  stable en mutant/supprimant `ttl_seconds` ET `generated_at` (même hash dans
  les deux cas). Bumpé À L'IDENTIQUE dans `ContractV1Test::FROZEN_STATE_HASH`
  et `hasher_test.go::frozenStateHash`. Ajout du cas de test Go jumeau
  `TestHashStateExcludesVolatileTtlSeconds` (mute puis supprime la clé →
  hash gelé inchangé), miroir de `TestHashStateExcludesVolatileGeneratedAt`.
- **AUCUN bump de `agent/shared/version.go`** — justification (piège n° 2,
  D6) : `HashState` Go n'a AUCUN appelant runtime dans l'agent (grep confirmé :
  seuls les tests `hasher_test.go` l'invoquent). L'agent stocke l'ETag/le hash
  d'item **verbatim** tels que servis par le serveur et ne recalcule jamais un
  hash d'état côté client — éditer `hasher.go` ne change donc AUCUN
  comportement runtime de l'agent déployé. La règle « éditer l'agent → bump »
  (`feedback_agent_edit_bump_version`) protège contre « un réglage sans effet
  parce que le binaire publié est antérieur » — scénario structurellement
  impossible ici puisque la fonction modifiée n'est exercée que par la suite
  de tests. Un bump aurait créé une version fantôme jamais publiée. `go build
  ./...` et `go test ./shared/...` verts confirment l'absence de régression
  de compilation/comportement.
- **Gap non documenté par la story, corrigé** : le nouveau 3ᵉ paramètre
  obligatoire du constructeur `StateCompiler` casse la compilation de **15
  fichiers de tests supplémentaires** (hors `StateCompilerTest`, non listés
  dans le File List de la story) qui instancient `new StateCompiler(...)`
  directement — `CapabilityRegistryCompilationTest`,
  `CapabilityRegistryListCompilationTest`, `CapabilityFirewallCompilationTest`,
  `CapabilityFsAclCompilationTest`, `CapabilityPrivilegeCompilationTest`,
  `CapabilityLegacyCleanupCompilationTest`, `FolderAccessRulesCompilationTest`,
  `AssociationsStateProviderTest`, `CapabilitiesSchemaAndSeedTest`,
  `CapabilityPhysicalInheritanceTest`, `PermissiveOverrideResolutionTest`,
  `UpstreamContractResolutionTest`, `ContractSeveranceTest`,
  `UpstreamLockedDriftStrictTest`, `UpstreamInstallOrderTest`. Corrigés en
  passant `new AgentTtlResolver()` (14 fichiers, tous avec `RefreshDatabase`,
  comportement inchangé — aucun assignment `restrict_run` n'existe dans ces
  contextes, early-return systématique). **Exception** :
  `UpstreamInstallOrderTest` utilise `WpkgSchemaBootstrapper` (shim de schéma
  partiel Story 15.2, SANS `RefreshDatabase`, table `capabilities` absente) —
  un vrai `AgentTtlResolver` y aurait levé « no such table » ; corrigé avec un
  **stub** (`createMock(AgentTtlResolver::class)`) au lieu d'une instance réelle.
  `AgentTtlResolver` a délibérément été laissé **non-`final`** (iso
  `CupsPrinterService`) pour permettre ce stubbing via `createMock()` dans
  `StateCompilerTest` (piège n° 7 — aucune requête SQL en Unit).
- **AC4** : `AgentTtlResolverTest` (Feature, `RefreshDatabase`) créé — 16 tests
  couvrant les 6 mailles D3 (poste, salle directe, ancêtre physique, parc
  logique, user, groupe user), `value` null (D2), slug hors liste, liste
  config vide (zéro requête `capability_assignments` prouvé par
  `DB::enableQueryLog()`), capacité absente (zéro requête, cas nominal
  41.2 non livrée), planchers 60 s / 1 s, déterminisme (piège n° 5).
  `StateCompilerTest` : helper `compiler()` étendu avec un stub par défaut
  (3600, non-régression ligne 78/79) + 1 test dédié TTL court injecté.
  `StateHasherTest` : test jumeau `hash_state_excludes_volatile_ttl_seconds`.
  `StateEndpointTest` : 3 tests ajoutés — TTL court sur la salle (machine-only
  ET `?user=`), ETag stable + 304 malgré la bascule sensible, plancher 60 via
  `AGENT_STATE_TTL_SENSITIVE_SECONDS`/config.
- **Piège n° 8 (collision 43.2)** : au moment du développement, aucune trace
  de la story 43.2 dans l'historique/les fichiers du worktree (golden
  `state.v1.json` inchangé, aucun hint `refresh` présent) — pas de conflit
  rencontré. À surveiller au merge si 43.2 est développée en parallèle sur
  une autre branche (le hash gelé devra être recalculé sur le golden fusionné).

### Corrections post-review

Quatre corrections appliquées après la review adversariale (sévérités 🟡/🟠, review non
consignée en fichier séparé — reportées directement ici) :

- **#1 🟠 (documentation, contrat imposé à 41.3)** — Le critère TTL court (`whereNotNull('value')`,
  D2) suppose que le flag examen (future 41.3) est POSÉ en créant l'assignment et PURGÉ en le
  SUPPRIMANT (DELETE) au déflag. Si 41.3 écrivait au déflag une valeur `off` non-null (convention
  UI « off = vraie valeur », `project_capability_value_map_symmetric_rule`), les postes
  resteraient au TTL court (90 s) à vie — la ligne resterait non-null en base. Documenté de façon
  CONTRAIGNANTE à 3 endroits : (1) commentaire de `config/agent.php::ttl_sensitive_capabilities` ;
  (2) `_bmad-output/planning-artifacts/epics-application-immediate.md` § Notes de coordination,
  point Epic 41 ; (3) ici. Aucun code produit (le comportement actuel de `AgentTtlResolver` est
  déjà conforme à D2 — c'est une contrainte imposée au FUTUR consommateur 41.3, pas un bug de la
  43.3).
- **#2 🟡 (docblock Go en retard)** — `agent/shared/hasher.go::HashState` (docblock ~212-214)
  disait encore « `generated_at` est exclu » alors que `volatileStateKeys` porte déjà
  `{generated_at, ttl_seconds}` depuis l'implémentation initiale de la 43.3 (le commentaire de la
  variable et le docblock PHP `StateHasher` étaient, eux, déjà à jour). Aligné sur les deux clés.
  Zéro impact runtime (fonction sans appelant, cf. Completion Notes ci-dessus) ; `go test
  ./shared/...` revérifié vert.
- **#3 (PAS de code, écarté)** — La mémoïsation statique du mapping `key→id` (`Capability::whereIn`)
  proposée en review pour économiser la première des deux requêtes est ÉCARTÉE : un `static`
  PHP-FPM survit entre requêtes dans le même worker, donc un reseed/renommage de capacité (rare
  mais possible — admin, migration de données) laisserait un mapping périmé jusqu'au restart du
  pool, silencieusement (aucun signal d'erreur, juste un mauvais TTL servi). Le gain visé
  (éviter un `pluck('id')` sur une table `capabilities` — quelques dizaines de lignes, indexée sur
  `key`) est marginal face à ce risque de staleness. Corollaire : la formulation « une requête
  EXISTS » du texte de la story était de toute façon inexacte — corrigée en « deux requêtes »
  (AC2, Tasks, Dev Notes, Completion Notes ci-dessus) : un `pluck('id')` key→id PUIS un `EXISTS`,
  les deux exécutées à CHAQUE compilation, y compris sur le chemin qui aboutit à un 304 (le
  compilateur — et donc le résolveur — tourne avant la comparaison d'ETag dans
  `StateController::show()`).
- **#4 🟡 (test négatif manquant)** — `StateEndpointTest` n'avait que le test positif (salle
  sensible → TTL court) sans son jumeau négatif. Ajouté
  `null_value_assignment_on_the_room_does_not_trigger_the_short_ttl` : même mise en place
  (assignment `restrict_run` sur la salle) mais `value = null` (unmanaged, D2) → l'endpoint sert
  `ttl_seconds = 3600` (défaut global), en machine-only ET `?user=`, calqué sur le style du test
  positif `sensitive_switch_on_the_room_yields_the_short_ttl_machine_only_and_with_user`.

### File List

**Nouveaux**
- `app/Services/Agent/AgentTtlResolver.php`
- `tests/Feature/Services/Agent/AgentTtlResolverTest.php`

**Modifiés — code**
- `app/Services/Agent/StateCompiler.php`
- `app/Providers/AgentServiceProvider.php`
- `config/agent.php`
- `app/Services/Agent/StateHasher.php`
- `agent/shared/hasher.go`
- `agent/shared/hasher_test.go`

**Modifiés — doc**
- `docs/agent/contract-v1.md`
- `docs/agent/state-endpoint.md`
- `docs/qa/domains/agent.md` (runbook QA, append-only)
- `_bmad-output/planning-artifacts/epics-application-immediate.md` (correction post-review #1 —
  contrainte 41.3 dans les Notes de coordination)

**Modifiés — tests (story)**
- `tests/Unit/Services/Agent/ContractV1Test.php`
- `tests/Unit/Services/Agent/StateHasherTest.php`
- `tests/Unit/Services/Agent/StateCompilerTest.php`
- `tests/Feature/Api/V1/Agent/StateEndpointTest.php`

**Modifiés — tests (gap constructeur, hors périmètre initial de la story,
cf. Completion Notes)**
- `tests/Unit/Services/Agent/CapabilityRegistryCompilationTest.php`
- `tests/Unit/Services/Agent/CapabilityRegistryListCompilationTest.php`
- `tests/Unit/Services/Agent/CapabilityFirewallCompilationTest.php`
- `tests/Unit/Services/Agent/CapabilityFsAclCompilationTest.php`
- `tests/Unit/Services/Agent/CapabilityPrivilegeCompilationTest.php`
- `tests/Unit/Services/Agent/CapabilityLegacyCleanupCompilationTest.php`
- `tests/Unit/Services/Agent/FolderAccessRulesCompilationTest.php`
- `tests/Unit/Services/Agent/AssociationsStateProviderTest.php`
- `tests/Feature/Migrations/CapabilitiesSchemaAndSeedTest.php`
- `tests/Feature/Agent/CapabilityPhysicalInheritanceTest.php`
- `tests/Feature/ControlHub/PermissiveOverrideResolutionTest.php`
- `tests/Feature/ControlHub/UpstreamContractResolutionTest.php`
- `tests/Feature/ControlHub/ContractSeveranceTest.php`
- `tests/Feature/ControlHub/UpstreamLockedDriftStrictTest.php`
- `tests/Feature/ControlHub/UpstreamInstallOrderTest.php`

**Sprint tracking**
- `_bmad-output/implementation-artifacts/sprint-status.yaml` (ligne 43-3)
- `_bmad-output/implementation-artifacts/43-3-ttl-seconds-dynamique.md` (ce fichier)

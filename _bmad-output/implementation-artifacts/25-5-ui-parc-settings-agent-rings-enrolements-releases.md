# Story 25.5: UI parc-settings/agent — rings, enrôlements en attente, releases

Status: review

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

En tant que **admin d'établissement (et mainteneur en lab)**,
je veux **piloter les rings de déploiement, les demandes d'enrôlement et les releases depuis une page dédiée**,
afin **de gérer la plomberie de la flotte sans toucher ni CLI ni GPO**.

## Contexte & intention

**Cinquième et dernière story de l'Epic 25** (« Gestion de flotte — distribution canari, bootstrap GPO, porte des postes migrés »). Elle pose **l'UI d'administration de la flotte** qui rend les machineries des stories 25.1–25.4 pilotables sans CLI ni `artisan`. C'est de la **plomberie de surface** : tous les moteurs existent, 25.5 leur donne une console.

Trois surfaces sur **une seule page** `parc-settings/agent/` (Livewire SFC, route `parc-settings.agent` déjà câblée, `can:computer.install`) :

1. **Releases & rings** — voir les releases publiées (25.1), la version ciblée par ring (= par WorkstationGroup), **promouvoir** une release sur un ring (`target()`) et **re-cibler** un ring en rollback (`target()` sur la stable précédente), **promouvoir/rollback la stable par défaut** (`promote()`). Toutes ces opérations passent par `ReleaseCreationService` (seul écrivain, logs `agent.release.*`) — aucune réinvention.
2. **Enrôlements en attente** — la surface d'approbation porte 2 **existe déjà** (`_partials/enrollment-requests.blade.php`, livrée en 25.3). 25.5 l'**étend** : approuver une demande **« inconnue »** (poste non rapproché) en **sélectionnant une cible** — c'est l'extension explicitement renvoyée à cette story par 25.3 (`enrollment-requests.blade.php:82,254`). `EnrollmentService::approveManually()` accepte **déjà** un `?Workstation $target` (signature en place, ligne 349) : zéro changement de service.
3. **Progression du déploiement** — les versions rapportées par les agents, **groupées par ring**, pour voir la canari avancer (1 poste → 1 salle → parc).

**Le verrou de données à lever** : la version de l'agent (`agent_version`) arrive dans **chaque rapport** (contrat 23.1, `contract.go:125,158`), est **validée** (`ReportRequest.php:51`)… puis **jetée** — elle n'est **persistée nulle part** côté serveur (ni colonne `workstations`, ni `agent_resource_states`, ni ailleurs). La surface « progression » est donc impossible sans une **greffe de persistance**. Décision actée (cf. infra) : **colonne `agent_reported_version` sur `workstations`**, écrite au fil du report.

**Avec 25.5, l'Epic 25 est complète** : distribution canari (25.1), auto-update (25.2), porte 2 (25.3), deux chemins d'install (25.4), console de pilotage (25.5).

## Décisions de cadrage actées avec Henri (2026-06-13)

> Tranchées avant rédaction. **À challenger en review, pas à re-trancher en dev.**

1. **Persistance de la version rapportée = colonne sur `workstations` (option A).** Migration `agent_reported_version` (varchar 32, nullable) + `agent_reported_version_at` (timestamp, nullable), écrite dans le **chemin de report** (`ReportController::store()`, après ingestion, hors transaction D3 — iso le `syncRequests->fulfill()` et le `agent_last_checkin_at` du middleware : une écriture `agent_*` idempotente, indépendante du stockage des items). Écartées : (B) une ligne `agent_resource_states` de type `agent_version` (détourne la sémantique desired-state compliant/drift/hash) ; (C) lire `AgentReportHistory` (opt-in `config('agent.report_history')`, off par défaut, purgeable → donnée absente). La colonne reste **dans la frontière `agent_*`** (`workstations` héberge déjà `agent_token_hash`, `agent_last_checkin_at`, etc.). [Décision Henri 2026-06-13]
2. **Pas de modèle d'ordre des rings — la promotion reste du jugement humain.** Il n'existe AUCUN ordonnancement « ring suivant » en base (un ring = un `WorkstationGroup`, sans rang). L'AC parle de « 1 poste → 1 salle → parc » : c'est une **intention opérateur**, pas une mécanique. L'UI laisse l'admin **choisir** le ring (groupe) ET la version à cibler — les critères de promotion restent du jugement humain (architecture : gap « souhaitable » n° 5). Aucune logique d'auto-promotion. [Source: epics-agent-desired-state.md:618]

## ⚠️ Pièges connus (lire avant de coder)

1. **`ReleaseCreationService` est le SEUL écrivain de `agent_releases` / `agent_release_rings` — ne JAMAIS écrire ces tables depuis le composant Livewire.** L'UI appelle `target()` / `promote()` ; elle ne fait pas d'`updateOrCreate` ni de `save()` sur les modèles release/ring. C'est ce service qui émet les logs `agent.release.*` et tient l'invariant « au plus une stable » (swap transactionnel + verrou advisory PG). [Source: app/Services/Agent/Releases/ReleaseCreationService.php:18-46]
2. **Deux opérations distinctes, deux logs distincts — ne pas les confondre.** `target(version, WorkstationGroup)` = cibler UN ring sur une version → log **`agent.release.targeted`** ; c'est CE que fait « promouvoir sur un ring » et « rollback d'un ring ». `promote(version)` = déplacer le **pointeur stable global** (défaut parc) → log **`agent.release.promoted`** ; c'est le rollback/avance de la stable par défaut. L'AC 25.5 mentionne `agent.release.promoted` pour « je la promeus au ring suivant » — dans le modèle 25.1 réellement implémenté, **une promotion de ring est un `target()` (`agent.release.targeted`)**, et `promote()` est réservé à la stable par défaut. Exposer les deux, sans les mélanger. [Source: ReleaseCreationService.php:161-216]
3. **`target()` lève `ReleaseOperationException::unknownVersion` sur version inconnue ; `promote()` aussi.** Le composant doit catcher et faire un `toastError` lisible — jamais laisser remonter une 500. La version ciblée vient d'une liste fermée (les releases existantes), donc le cas est défensif, mais le Gate `/livewire/update` est adressable. [Source: ReleaseCreationService.php:164-165,196-197 ; ReleaseOperationException]
4. **`agent_version` est aujourd'hui SILENCIEUSEMENT JETÉ — la greffe de persistance est le cœur back de la story.** `ReportRequest::rules()` valide `agent_version` (required, max 32) mais `ReportIngestService::ingest()` ne le lit pas et n'écrit AUCUNE colonne `workstations` (il dit explicitement « Aucune colonne workstations n'est écrite »). La nouvelle écriture va dans **`ReportController::store()`**, après `$this->ingest->ingest(...)`, à côté de `$this->syncRequests->fulfill($workstation)` — pas dans le service d'ingestion (qui est volontairement read-only sur `workstations`). [Source: app/Http/Controllers/Api/V1/Agent/ReportController.php:57-76 ; app/Services/Agent/Reporting/ReportIngestService.php:78-86]
5. **La version vient de `$report['agent_version']` (validé), pas du payload brut.** Dans `store()`, `$report = $request->validated()` contient `agent_version`. Ne pas re-parser `$request->json()`. La colonne fait 32 (= la borne `max:32` de la règle) — cohérent. [Source: ReportController.php:60 ; ReportRequest.php:51]
6. **L'approbation d'une demande « inconnue » exige une cible — `approveManually` la prend DÉJÀ.** `approveManually(AgentEnrollmentRequest $request, ?int $resolvedBy, ?Workstation $target = null)` : le 3ᵉ argument override le rapprochement. Pour une demande sans `matched_workstation_id`, l'UI fait choisir un `Workstation` puis appelle `approveManually($req, auth()->id(), $target)`. Ne PAS rouvrir/modifier le service. Garde d'invariant à respecter : le `redeem()` étape 2 (25.3) exige une cible pour émettre le token — c'est pourquoi 25.3 désactivait le bouton « inconnu » en attendant cette sélection. [Source: app/Services/Agent/Enrollment/EnrollmentService.php:349 ; enrollment-requests.blade.php:79-87,252-256]
7. **Le token ne transite JAMAIS par l'UI — approuver = ARMER.** `approveManually` met la demande `pending → approved` ; le token naît au prochain `redeem()` du poste (cycle 23.2/25.3). Le composant ne manipule, n'affiche, ne logge aucun token/hash. [Source: enrollment-requests.blade.php:63-91]
8. **`Gate::authorize('computer.install')` sur CHAQUE méthode mutante du composant.** Le middleware `can:computer.install` protège la *page*, mais chaque action Livewire est adressable via `/livewire/update` : double protection iso-pattern projet (arbitrage review 25.3 #4). Les méthodes de lecture (`#[Computed]`) n'en ont pas besoin, l'accès page suffit. [Source: enrollment-requests.blade.php:68-70,145,158]
9. **WithToasts pour TOUS les retours d'action (AC).** `toastSuccess` / `toastError` du trait `App\Components\Traits\WithToasts` — jamais de `session()->flash` ni d'`alert()`. [Source: app/Components/Traits/WithToasts.php:34-50 ; CLAUDE.md « notifications utilisateurs »]
10. **Modale réutilisable `x-molecules.modal` (+ `x-molecules.modal.section`) pour toute confirmation lourde ; `wire:confirm` pour les un-clic légers.** Le pattern existant : `wire:confirm` sur « Approuver », modale `x-molecules.modal` pour « Rejeter » (preuves affichées). Le choix de cible (demande inconnue) et le rollback d'un ring justifient une modale (sélection/confirmation). [Source: enrollment-requests.blade.php:247-305 ; CLAUDE.md « modale »]
11. **SFC Livewire = convention `pages/` filesystem-router.** Page racine `resources/views/pages/parc-settings/agent/index.blade.php` (déjà là, n'intègre QUE le partial enrôlements aujourd'hui — y ajouter les partials releases + progression). Partials réactifs dans `_partials/` comme composants Livewire SFC (`return new class extends Component`). [Source: CLAUDE.md « Arborescence et routing » ; index.blade.php:20-26]
12. **`agent_reported_version` HORS `$fillable` — écriture explicite (`forceFill` ou affectation directe + `save`).** Comme toutes les colonnes `agent_*` de `workstations`, jamais mass-assignée. [Source: app/Models/Workstation.php (colonnes agent_* hors fillable)]
13. **Progression par ring = jointure lecture seule `agent_release_rings` × `workstation_group_workstation` × `workstations`.** Un ring = `WorkstationGroup` (`is_physical` true OU false — salle physique ou parc logique, indistinct). Les postes d'un ring : `$group->workstations()` (belongsToMany via `workstation_group_workstation`). La version rapportée : `workstations.agent_reported_version`. Comparer à la version **ciblée** par le ring (`ring->release->version`) pour montrer l'écart de convergence. Aucune écriture. [Source: app/Models/WorkstationGroup.php:158-166 ; app/Models/AgentReleaseRing.php:36-43]
14. **Golden files figés intouchés.** state/report/release-manifest/contract-v1/FROZEN_STATE_HASH ne bougent pas : 25.5 n'altère NI le contrat de report (la colonne ne change pas le payload), NI le manifest. Si un test golden casse, c'est une régression — pas un golden à régénérer. [Source: tests/Fixtures/Agent/ ; stories 25.1-25.4]
15. **Pagination Livewire standard.** `use WithPagination;` + `{{ $this->items->links() }}` ; le composant pagination projet (`x-molecules.pagination`) est auto-câblé. Les releases sont peu nombreuses (pas de pagination nécessaire) ; la progression peut paginer si beaucoup de postes. [Source: enrollment-requests.blade.php:47,275]
16. **Migration neuve = `php artisan migrate` sur la VM (action Henri).** Deux colonnes ajoutées à `workstations`. Pas de clé `config` neuve → pas de `config:cache`. La route `parc-settings.agent` existe déjà → pas de `route:cache`. [Source: mémoire `project_vm_config_cache_not_synced`]

## Décisions de design prises ici (à challenger en review, pas à re-trancher en dev)

1. **Découpage en 3 partials Livewire SFC sous `_partials/`**, intégrés par `index.blade.php` :
   - `_partials/enrollment-requests.blade.php` — **existant (25.3)**, étendu : sélection de cible pour les demandes « inconnues » (modale `x-molecules.modal` listant des `Workstation` candidates → `approveManually($req, id, $target)`). Le reste (campagne, rejet, un-clic rapproché) inchangé.
   - `_partials/releases-rings.blade.php` — **neuf** : table des releases (version, hash court, stable ?), table des rings (groupe, version ciblée, récence `updated_at`), actions « Cibler un ring sur une version » (modale : choix groupe + version → `target()`), « Rollback ce ring » (re-`target()` sur la stable), « Définir la stable par défaut » / « Rollback stable » (`promote()`).
   - `_partials/deployment-progress.blade.php` — **neuf** : par ring, version ciblée vs versions rapportées (compte de postes par version, à jour / en retard / jamais vu), `agent_reported_version_at` pour la fraîcheur.
2. **Persistance version (Tâche back)** : migration + écriture dans `ReportController::store()`. Aucune modif de `ReportIngestService` (volontairement read-only sur `workstations`), aucune modif du contrat agent (la version était déjà envoyée). `Workstation` reçoit un accessor de confort si utile (ex. `agent_reported_version` exposé en lecture pour l'UI).
3. **Lecture progression = un service de requête léger** (ex. `App\Services\Agent\Releases\DeploymentProgressService` ou un `#[Computed]` du partial) qui agrège `rings × workstations`. Lecture seule, zéro AD, aucune écriture. Le dev tranche service dédié vs computed selon la complexité — pas de sur-ingénierie.
4. **Aucune route API neuve, aucune commande artisan neuve.** Les commandes `agent:release:{create,promote,target}` (25.1) restent l'interface CLI ; l'UI est une seconde façade sur le **même** service. La seule route touchée = web `parc-settings.agent` (déjà là).
5. **Sélection de cible (demande inconnue)** : l'UI propose les `Workstation` **non enrôlées** comme cibles plausibles (recherche par nom/hostname, liste bornée). On peut s'appuyer sur `EnrollmentMatchService` pour suggérer un candidat, mais le choix final reste **explicitement humain** (l'anti-usurpation 25.3 interdit l'auto-rapprochement d'un inconnu). Pas d'auto-sélection silencieuse.
6. **Une seule release stable, des rings optionnels** : un ring sans ciblage retombe sur la stable (résolution `ReleaseManifestService::manifestFor`). L'UI montre clairement « ce ring suit la stable par défaut » vs « ce ring est ciblé sur vX ».

## Acceptance Criteria

### AC1 — La page parc-settings/agent présente les trois surfaces (FR26)

**Given** la page `parc-settings/agent/` (convention `pages/`, Livewire SFC, `can:computer.install`)
**When** un admin (ou mainteneur en lab) la consulte
**Then** elle affiche : (a) les **releases** publiées et la **version ciblée par ring** (= par WorkstationGroup, physique ou logique) ; (b) les **enrôlements en attente** (surface d'approbation porte 2 de 25.3, intégrée) ; (c) l'**état de progression du déploiement** = les **versions rapportées par les agents**, groupées par ring (à jour / en retard vs la version ciblée)
**And** aucune écriture n'est faite à la lecture ; la frontière `agent_*` est respectée (zéro LdapRecord/Kerberos/samba-tool) ; les accès passent par les modèles/services existants.

### AC2 — Promotion d'une release sur un ring (canari → salle → parc)

**Given** une release canari validée par l'admin sur son ring
**When** il la **promeut au ring suivant** depuis l'UI (choix du groupe cible + de la version — le « ring suivant » est son jugement, pas une mécanique automatique)
**Then** l'opération passe par `ReleaseCreationService::target($version, $group)` ; le **manifest sert cette version au nouveau ring au prochain check-in** des postes du groupe ; l'action est **loggée `agent.release.targeted`** (et `agent.release.promoted` pour une promotion de la **stable par défaut**)
**And** l'UI confirme via WithToasts ; aucune écriture directe sur `agent_releases`/`agent_release_rings` (seul `ReleaseCreationService` écrit).

### AC3 — Rollback d'un déploiement raté

**Given** un déploiement qui se passe mal sur un ring
**When** l'admin **re-cible le ring sur la version stable précédente** (ou rollback la stable par défaut)
**Then** le manifest fait foi : `target()` (ring) ou `promote()` (stable globale) repointe la version, et **les agents reconvergent** au prochain check-in
**And** WithToasts pour le retour d'action ; les critères de promotion/rollback restent du **jugement humain** (architecture : gap « souhaitable » n° 5 — aucune auto-promotion).

### AC4 — La version rapportée par l'agent est persistée et visible (greffe report)

**Given** un agent qui poste son rapport (`agent_version` présent dans chaque rapport, contrat 23.1)
**When** le serveur ingère le rapport (`ReportController::store()`)
**Then** la version est écrite dans `workstations.agent_reported_version` (+ `agent_reported_version_at`), **hors** de la transaction d'ingestion D3 (iso `agent_last_checkin_at` / `syncRequests->fulfill`), sans toucher au contrat de report ni aux golden files
**And** la surface « progression » lit cette colonne pour montrer l'avancée du déploiement par ring ; `ReportIngestService` reste read-only sur `workstations` (la greffe est dans le controller).

### AC5 — Approbation d'un poste « inconnu » par sélection de cible (extension 25.3)

**Given** une demande d'enrôlement `pending` **sans rapprochement** (poste inconnu en DB — bouton « Approuver » désactivé en 25.3)
**When** l'admin **sélectionne une cible** (un `Workstation` connu, non enrôlé) et approuve
**Then** `EnrollmentService::approveManually($request, auth()->id(), $target)` arme la demande sur cette cible (3ᵉ argument déjà supporté) ; le token naîtra au prochain `redeem()` du poste — il ne transite **jamais** par l'UI
**And** l'anti-usurpation n'est pas débrayé : la cible est un **choix humain explicite** (jamais d'auto-rapprochement d'un inconnu) ; `Gate::authorize('computer.install')` sur l'action ; WithToasts.

### AC6 — Frontière, autorisation, observabilité, golden files

**Given** toutes les actions de la page
**When** elles s'exécutent
**Then** chaque méthode mutante des composants vérifie `Gate::authorize('computer.install')` (page protégée par `can:computer.install` ET action protégée — adressabilité `/livewire/update`) ; les logs restent ceux des services (`agent.release.*`, `agent.enroll.*`), **jamais** de token/hash en clair
**And** aucune écriture hors `agent_*` ; aucune route API neuve, aucune commande artisan neuve ; golden files figés intouchés (state/report/release-manifest/contract-v1) ; le contrat de report est inchangé (la colonne ne modifie pas le payload).

## Tasks / Subtasks

- [x] **Tâche 1 — Persistance de la version rapportée** (AC4) [Source: pièges 4,5,12,14]
  - [x] Migration `add_agent_reported_version_to_workstations` : `agent_reported_version` (varchar 32, nullable) + `agent_reported_version_at` (timestamp, nullable), idempotente (`Schema::hasColumn`), `down()` symétrique.
  - [x] `ReportController::store()` : après `$this->ingest->ingest(...)`, écrire `agent_reported_version` + `agent_reported_version_at` depuis `$report['agent_version']` (validé), via `forceFill(...)->save()` (hors `$fillable`, hors transaction D3 — iso `syncRequests->fulfill`). NE PAS modifier `ReportIngestService` (read-only `workstations`).
  - [x] `Workstation` : colonnes en `$casts` (`agent_reported_version_at` → datetime) ; pas dans `$fillable`.
- [x] **Tâche 2 — Partial releases & rings** (AC2, AC3) [Source: pièges 1,2,3,10,11]
  - [x] `_partials/releases-rings.blade.php` (SFC) : `#[Computed]` releases (`AgentRelease` + flag stable), rings (`AgentReleaseRing` avec `release`, `workstationGroup`, `updated_at`).
  - [x] Action « Cibler un ring » (modale `x-molecules.modal` : choix `WorkstationGroup` + `version`) → `ReleaseCreationService::target()` ; catch `ReleaseOperationException` → `toastError` ; `toastSuccess` sinon ; `unset` des computed.
  - [x] Action « Rollback ce ring » (re-`target()` sur la stable) et « Définir/rollback la stable par défaut » → `promote()`.
  - [x] `Gate::authorize('computer.install')` sur chaque action mutante ; WithToasts ; jamais d'écriture directe modèle.
- [x] **Tâche 3 — Partial progression du déploiement** (AC1, AC4) [Source: pièges 13,15]
  - [x] `_partials/deployment-progress.blade.php` (SFC) : par ring, version ciblée vs comptes de postes par `agent_reported_version` (à jour / en retard / jamais vu), fraîcheur `agent_reported_version_at`.
  - [x] Agrégation lecture seule `rings × workstation_group_workstation × workstations` (`#[Computed]` agrégateur — complexité modérée, pas de service dédié). Zéro AD, zéro écriture.
  - [x] Pagination non nécessaire (agrégation par comptage, nombre de rings borné).
- [x] **Tâche 4 — Extension approbation « inconnu » (cible)** (AC5) [Source: pièges 6,7,8]
  - [x] `_partials/enrollment-requests.blade.php` : modale de **sélection de cible** pour une demande sans `matched_workstation_id` (liste/recherche de `Workstation` non enrôlées ; choix humain final, pas d'auto-sélection) → `approveManually($req, auth()->id(), $target)`.
  - [x] Activer le bouton « Approuver » des demandes inconnues (« Approuver… ») en l'orientant vers la modale de cible.
  - [x] Reste de la surface (campagne, rejet, un-clic rapproché) inchangé ; `Gate::authorize` ; WithToasts.
- [x] **Tâche 5 — Intégration page** (AC1) [Source: piège 11]
  - [x] `index.blade.php` : intègre les 3 partials dans `<x-organisms.page>`, titre/desc « Agent — Flotte ».
- [x] **Tâche 6 — Tests** (AC1-AC6)
  - [x] Back : `ReportedVersionPersistenceTest` — `agent_reported_version` persistée (greffe `store()`), idempotence/fraîcheur, items vides persistent quand même, hors `$fillable`, golden de report verbatim.
  - [x] Livewire `ReleasesRingsSurfaceTest` : cibler un ring (`target`), rollback (re-`target` stable), promote stable (invariant single-stable), version inconnue → `toastError` pas 500, permission denied.
  - [x] Livewire `DeploymentProgressSurfaceTest` : agrégation par ring (à jour/en retard/jamais vu), empty state.
  - [x] Livewire `EnrollmentRequestsSurfaceTest` (étendu) : approbation d'un inconnu avec cible (`approveManually` reçoit `$target`), pas d'auto-sélection sans cible, candidats excluent les enrôlés, permission denied.
  - [x] Non-régression : `EnrollmentRequestsSurfaceTest`, `AgentReleaseCommandsTest`, `Release*ServiceTest`, `ReportEndpointTest`, `ReportIngestServiceTest` verts.
- [x] **Tâche 7 — Docs** (AC6)
  - [x] `docs/qa/domains/agent.md` : Section 12 (scénarios 12.1-12.6 : cibler ring, promote/rollback stable, rollback ring, progression, approbation inconnu, frontière/golden) — append-only, existant non renuméroté.
  - [x] `docs/agent/release-distribution.md` : addendum « UI de pilotage des rings » (table action UI → service → log, renvoi CLI 25.1, greffe progression).

## Dev Notes

### Modèle recommandé

**opus** — story multi-surface : greffe back sur le **chemin chaud de report** (persistance version, à faire sans toucher au contrat ni aux golden files), 3 partials Livewire (dont l'agrégation de progression par ring) + extension d'un partial existant sensible (approbation/anti-usurpation). Moins critique en sécurité que 25.3/25.4 mais la greffe report + l'agrégation cross-tables + le respect strict de « `ReleaseCreationService` seul écrivain » appellent opus. **sonnet** plausible si la greffe report est jugée triviale — au jugement de l'orchestrateur.

### Architecture & contraintes (résumé exécutable)

- **Réutilisation maximale, zéro réinvention** : `ReleaseCreationService::{target,promote}` (seul écrivain releases/rings, logs `agent.release.*`), `EnrollmentService::approveManually` (3ᵉ arg `$target` déjà là), `ReleaseManifestService` (résolution ring→stable, inchangée), `WithToasts`, `x-molecules.modal`, `WithPagination`, `WorkstationGroup::workstations()`. Le code neuf = **3 partials + 1 migration + 1 greffe controller + 1 service de lecture** ; aucun moteur neuf.
- **`ReleaseCreationService` = unique porte d'écriture** des tables release/ring (piège 1). L'UI est une **2ᵉ façade** sur le service, à côté des commandes artisan 25.1.
- **Frontière `agent_*` + zéro AD** (NFR7) : la page lit `agent_releases`, `agent_release_rings`, `workstations`, `workstation_groups` ; elle n'appelle aucun LdapRecord/Kerberos/samba-tool. `grep` de revue : zéro `ldap|kerberos|samba-tool` dans le code neuf.
- **Le token ne touche jamais l'UI** (piège 7) : approuver = armer, le token naît au `redeem()`.
- **Persistance version = greffe controller, pas service** (piège 4) : `ReportIngestService` reste volontairement read-only sur `workstations`.

### Project Structure Notes

- **Racine = projet Laravel** (plus de `laravel/`). Pages : `resources/views/pages/parc-settings/agent/` (`index.blade.php` + `_partials/`). Services : `app/Services/Agent/Releases/`, `app/Services/Agent/Enrollment/`. [Source: mémoire `project_root_is_laravel` ; CLAUDE.md routing]
- **Route web** : `parc-settings.agent` déjà déclarée (`routes/web.php:180`, `can:computer.install`). Pas de route neuve.
- **Migration** : `database/migrations/` (deux colonnes `workstations`). Pas de clé `config` neuve.

### VM / exécution (mémoires projet)

- **Migration neuve** → `php artisan migrate` sur la VM (action Henri). Pas de `config:cache` (aucune clé config), pas de `route:cache` (route existante). [Source: mémoire `project_vm_config_cache_not_synced`]
- Tests serveur : `php artisan test --filter Agent` (+ `--filter Livewire` selon nommage) ; pas de Go dans cette story (100 % serveur/UI).
- **Smoke e2e (action manuelle Henri)** : ouvrir la page, cibler un ring, voir la progression se peupler après un check-in agent — sur VM/poste de lab. Runbook agent.md Section 12.
- ⚠️ Une release `2.1.2` (cert TEST) est publiée stable sur la VM (laissée par 25.1/25.2/25.4) — utile à la démo UI.

### Project Structure Notes (alignement)

- Partials réactifs = SFC Livewire dans `_partials/` (convention projet). La page n'embarque pas de logique métier : elle compose les partials. [Source: CLAUDE.md « Arborescence et routing »]
- Conflit potentiel : la page 25.3 s'intitule « Agent — Enrôlements ». 25.5 la généralise (« Agent — Flotte » ou équivalent) — variance assumée, c'était le plan 25.3 (« elle intégrera le partial livré ci-dessous dans sa page complète », `index.blade.php:11-12`).

### References

- [Source: epics-agent-desired-state.md:600-618] — Story 25.5 AC (3 surfaces, promotion ring, rollback, jugement humain).
- [Source: epics-agent-desired-state.md:146 (FR26), :618 (gap souhaitable n° 5)]
- [Source: app/Services/Agent/Releases/ReleaseCreationService.php:64,161,193] — `create`/`promote`/`target` (seul écrivain, logs `agent.release.created|promoted|targeted`).
- [Source: app/Services/Agent/Releases/ReleaseManifestService.php:44,78] — `manifestFor`/`stableManifest` (résolution ring→stable, inchangée).
- [Source: app/Models/AgentRelease.php ; app/Models/AgentReleaseRing.php:36-43] — modèles release/ring (`is_stable`, `rings()`, `release()`, `workstationGroup()`, `updated_at` = récence).
- [Source: app/Services/Agent/Enrollment/EnrollmentService.php:349] — `approveManually($request, ?$resolvedBy, ?Workstation $target)`.
- [Source: resources/views/pages/parc-settings/agent/_partials/enrollment-requests.blade.php:79-91,247-256] — surface 25.3 + hook explicite « sélection de cible 25.5 ».
- [Source: resources/views/pages/parc-settings/agent/index.blade.php:9-13] — page squelette 25.3, à compléter en 25.5.
- [Source: app/Http/Controllers/Api/V1/Agent/ReportController.php:57-76] — point de greffe persistance version (après `ingest`, hors transaction).
- [Source: app/Http/Requests/Api/V1/Agent/ReportRequest.php:51] — `agent_version` validé (required, max 32) puis jeté.
- [Source: app/Services/Agent/Reporting/ReportIngestService.php:78-86] — ingestion read-only `workstations` (NE PAS y greffer).
- [Source: app/Models/WorkstationGroup.php:158-166 ; app/Models/Workstation.php:396-401] — `workstations()` (pivot `workstation_group_workstation`), `agentResourceStates()`.
- [Source: agent/shared/contract.go:125,158] — `agent_version` émis dans chaque rapport.
- [Source: app/Components/Traits/WithToasts.php:34-50] — `toastSuccess`/`toastError`.
- [Source: app/Console/Commands/AgentRelease{Create,Promote,Target}Command.php] — façade CLA existante (l'UI est la 2ᵉ façade, même service).
- [Source: routes/web.php:180] — route `parc-settings.agent` (`can:computer.install`).
- Mémoires : `project_root_is_laravel`, `project_vm_config_cache_not_synced`, `feedback_auth_iso_legacy`, `project_no_legacy_transition_state`.

## Dev Agent Record

### Agent Model Used

opus (claude-opus-4-8[1m])

### Debug Log References

- `php artisan migrate --force` (VM) : `2026_06_13_140000_add_agent_reported_version_to_workstations` DONE (23.30ms).
- `php artisan test --filter Agent` (VM) : **338 passed (1265 assertions)**, 43.43s.
- Tests dédiés (VM) : `ReportedVersionPersistenceTest` + `ReleasesRingsSurfaceTest` + `DeploymentProgressSurfaceTest` + `EnrollmentRequestsSurfaceTest` = **24 passed**.
- Non-régression golden/services (VM) : `ReportEndpointTest`, `AgentReleaseCommandsTest`, `ReleaseManifestServiceTest`, `ReleaseCreationServiceTest`, `ReportIngestServiceTest` = **72 passed (262 assertions)**.

### Completion Notes List

- **Greffe back (AC4)** : version rapportée persistée dans `ReportController::store()` via une méthode privée `persistReportedVersion()` appelée APRÈS `ingest()` et `syncRequests->fulfill()` (hors transaction D3). `ReportIngestService` NON modifié (read-only sur `workstations` préservé). Colonnes hors `$fillable`, `forceFill` explicite, troncature défensive à 32 (`Str::limit(..., 32, '')`). Contrat de report inchangé (golden `report.v1.json` passe verbatim).
- **Releases & rings (AC2/AC3)** : partial `releases-rings.blade.php` — 2ᵉ façade sur `ReleaseCreationService` (seul écrivain). « Cibler/Re-cibler » → `target()` ; « Rollback » d'un ring → re-`target()` sur la stable ; « Définir stable » → `promote()`. Distinction stricte des deux logs (`targeted` vs `promoted`). `ReleaseOperationException` catchée → `toastError`, jamais 500. `Gate::authorize('computer.install')` sur chaque action mutante.
- **Progression (AC1/AC4)** : partial `deployment-progress.blade.php` — `#[Computed]` agrégateur lecture seule (`rings × workstation_group_workstation × workstations`), comptes à jour/en retard/jamais vu + fraîcheur. Choix `#[Computed]` plutôt que service dédié (complexité modérée, pas de sur-ingénierie, comme autorisé par la décision de design n° 3).
- **Approbation inconnu (AC5)** : `enrollment-requests.blade.php` étendu — bouton « Approuver… » actif sur les demandes inconnues, ouvre une modale `x-molecules.modal` de sélection de cible (recherche par nom/MAC, postes NON enrôlés uniquement). `approveManually($req, auth()->id(), $target)` (3ᵉ arg déjà en place, service NON modifié). Aucune auto-sélection (anti-usurpation). Token jamais manipulé/affiché.
- **Intégration page (AC1)** : `index.blade.php` compose les 3 partials, titre « Agent — Flotte ». Ordre d'affichage : releases & rings → progression → enrôlements (le pilotage en tête, la donnée d'observation au milieu, l'action récurrente en bas).
- **Frontière (AC6)** : aucune écriture hors `agent_*` ; aucune route API ni commande artisan neuve ; golden files intouchés ; zéro AD (aucun LdapRecord/Kerberos/samba-tool dans le code neuf).

### File List

**Créés**
- `database/migrations/2026_06_13_140000_add_agent_reported_version_to_workstations.php`
- `resources/views/pages/parc-settings/agent/_partials/releases-rings.blade.php`
- `resources/views/pages/parc-settings/agent/_partials/deployment-progress.blade.php`
- `tests/Feature/Api/V1/Agent/ReportedVersionPersistenceTest.php`
- `tests/Feature/Livewire/Agent/ReleasesRingsSurfaceTest.php`
- `tests/Feature/Livewire/Agent/DeploymentProgressSurfaceTest.php`

**Modifiés**
- `app/Models/Workstation.php` (cast `agent_reported_version_at` + doc-block des deux propriétés ; hors `$fillable`)
- `app/Http/Controllers/Api/V1/Agent/ReportController.php` (greffe `persistReportedVersion()`)
- `resources/views/pages/parc-settings/agent/index.blade.php` (composition des 3 partials, titre « Agent — Flotte »)
- `resources/views/pages/parc-settings/agent/_partials/enrollment-requests.blade.php` (modale + sélection de cible pour demande inconnue, bouton activé)
- `tests/Feature/Livewire/Agent/EnrollmentRequestsSurfaceTest.php` (4 tests ajoutés — extension cible inconnu)
- `docs/qa/domains/agent.md` (Section 12, append-only)
- `docs/agent/release-distribution.md` (addendum « UI de pilotage des rings »)
- `_bmad-output/implementation-artifacts/sprint-status.yaml` (statut 25.5 → review)

## Change Log

| Date | Version | Description | Author |
|------|---------|-------------|--------|
| 2026-06-13 | 0.1 | Création story 25.5 (SM/orchestrateur, Henri). Dernière story Epic 25 : UI parc-settings/agent (3 surfaces — releases/rings, enrôlements, progression). Fork tranché : persistance version rapportée = colonne `workstations` (greffe `ReportController`). Extension 25.3 = approbation « inconnu » par sélection de cible (`approveManually` 3ᵉ arg déjà là). 6 AC, 7 tâches. Reco modèle : opus. | henri |
| 2026-06-13 | 1.0 | Implémentation complète (DEV opus). Migration `agent_reported_version` (+`_at`) + greffe `ReportController::store()` (hors transaction D3, `ReportIngestService` intact). 2 partials neufs (`releases-rings`, `deployment-progress`) + extension `enrollment-requests` (sélection de cible inconnu). `index.blade.php` → « Agent — Flotte » (3 surfaces). 24 tests neufs/étendus + non-régression verte (338 tests `--filter Agent`). Docs : agent.md Section 12 (append-only), release-distribution.md addendum UI rings. Migration appliquée sur la VM. Status → review. | opus |

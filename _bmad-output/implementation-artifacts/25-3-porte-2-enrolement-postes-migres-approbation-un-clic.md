# Story 25.3: Porte 2 — enrôlement des postes migrés avec approbation un-clic

Status: review

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

En tant qu'**admin d'établissement**,
je veux **approuver d'un clic les postes migrés qui demandent à rejoindre le système**,
afin **d'enrôler l'existant sans réinstallation et sans usurpation possible**.

## Contexte & intention

Troisième story de l'**Epic 25** (« Gestion de flotte — distribution canari, bootstrap GPO, porte des postes migrés »). Elle livre la **porte 2** de l'enrôlement (FR16, gap architecture n° 3), complément de la porte 1 (story 23.3, `done`) :

- **Porte 1 (iPXE, 23.3)** : poste neuf installé par la chaîne iPXE — l'admin est déjà authentifié au menu, un **ticket one-time** émis à la génération de l'unattend.xml est échangé contre le token au premier logon. Aucune action manuelle.
- **Porte 2 (cette story)** : poste **migré** (existant, joint au domaine, agent posé par la GPO-dispatcher 25.4) — **sans ticket**. Il **demande** à rejoindre, l'admin **approuve d'un clic** dans l'UI, le token naît (cycle 23.2). Pas de réinstallation.

Le point d'ancrage existe déjà et a été conçu pour ça : aujourd'hui, une demande d'enrôlement sans ticket valide retombe sur `EnrollmentService::redeem()` → `reject()` → **403 `AGENT_ENROLL_NOT_ALLOWED`** (indistinct, sans oracle). La doc le documente noir sur blanc : *« C'est le futur point d'accueil de la porte 2 (25.3) »* ([Source: docs/agent/enrollment.md:17-19, :102, :124-125, :157]). Cette story transforme ce 403 sec en **création d'une demande d'enrôlement en attente**, visible et approuvable dans l'UI — **sans toucher au flux porte 1** (un ticket valide enrôle toujours directement).

Valeur autonome immédiate : un poste migré sur lequel la GPO a posé l'agent (25.4) apparaît dans la liste des demandes ; l'admin clique « Approuver », le poste reçoit son token au prochain check-in et commence à converger. Une **campagne** de migration peut activer l'auto-approbation bornée — mais l'anti-usurpation ne se débraye **jamais**.

## ⚠️ Pièges connus (lire avant de coder)

1. **NE PAS recréer un endpoint d'enrôlement.** La porte 2 réutilise **le même** `POST /v1/agent/enrollment` (`agent.v1.enrollment`, `AgentEnrollController::store`, middleware `['local.request', 'auth.v1.secure-headers', 'throttle:10,1']`, **PAS** `agent.token` : le poste migré n'a pas encore de token). La logique vit dans `EnrollmentService` : c'est la **branche d'échec de `redeem()`** (`reject()`) qu'on enrichit. Ne créez **aucune** nouvelle route d'enrôlement, ne touchez **pas** au flux ticket valide (porte 1) ni au canal JWT legacy `agent.v1.enroll` (`routes/api.php` bloc l.190-212 — intouché, frontière architecture). [Source: routes/api.php:296-298 ; app/Services/Agent/Enrollment/EnrollmentService.php:148-160 ; docs/agent/enrollment.md:18]
2. **Faisceau de preuves — aucune preuve suffisante seule (gap 3).** L'identité présentée = `hostname` + `mac` + `uuid` (SMBIOS). L'**uuid SMBIOS s'est montré peu fiable** (champ SQL vide côté iPXE — mémoire `project_ipxe_param_use_smbios_vars`). Règle figée : **MAC = ancre fiable**, hostname = corroborant, uuid = corroborant faible. L'auto-approbation n'est possible **que** si le faisceau concorde avec un poste **déjà connu en DB** (cf. piège n° 3). Une demande où aucune preuve ne porte (toutes vides) reste créable mais n'est **jamais** auto-approuvable. [Source: architecture-agent-desired-state.md:735-739 ; epics-agent-desired-state.md:564]
3. **Anti-usurpation jamais débrayé — même en campagne.** Seules les demandes dont le faisceau **concorde avec un poste connu** (importé AD/legacy : MAC du poste connue ET hostname cohérent) sont auto-approuvables. Toute **divergence** (MAC connue mais hostname différent, plusieurs postes candidats, poste inconnu en DB) reste en **approbation manuelle**, même mode campagne actif. C'est l'invariant de sécurité non négociable — le test le verrouille. [Source: epics-agent-desired-state.md:572-574]
4. **Pas d'auto-approbation si le poste connu est déjà enrôlé.** Si le faisceau matche un poste qui a déjà un `agent_token_hash` → ce n'est pas un poste migré qui rejoint, c'est un **conflit** (clone potentiel, ou ré-enrôlement) → **jamais** auto-approuvé, et la demande doit être distinguée (manuelle, signalée). Aligné sur la sémantique 409 existante (`conflict` = poste déjà enrôlé) de `reject()`. [Source: app/Services/Agent/Enrollment/EnrollmentService.php:150-159]
5. **Idempotence de la demande.** Un poste migré sans token **rejoue** son `POST /enrollment` à chaque check-in (cadence agent). Une demande en attente ne doit PAS se dupliquer : `updateOrCreate` sur une clé stable (le faisceau de preuves, p.ex. MAC normalisée + hostname) → on rafraîchit `last_seen_at`, on ne crée pas N lignes. Le poste reste **403** tant qu'il n'est pas approuvé (« check-in léger en attente » — il revient, il patiente). [Source: epics-agent-desired-state.md:565]
6. **MAC normalisée des deux côtés.** Toujours `MacAddressNormalizer::normalize()` (`app/Ipxe/Support/MacAddressNormalizer.php` — accepte `aa:bb:cc:dd:ee:ff` / `AA-BB-CC-DD-EE-FF` / `aabbccddeeff`, retourne canonique lowercase `:` ou null) pour comparer la MAC présentée à `workstations.mac` (canonique lowercase via mutateur). L'agent Windows émettra des tirets ipconfig — un `strtolower` naïf quarantainerait/raterait le rapprochement (piège vécu review 23.2 P1). [Source: app/Ipxe/Support/MacAddressNormalizer.php:38-59 ; app/Models/Workstation.php:111-114]
7. **Naissance du token = `TokenRotationService::issueFor()` UNIQUEMENT.** À l'approbation (un-clic OU auto), le token naît via `TokenRotationService::issueFor(Workstation): string` (efface grâce + quarantaine, log `agent.token.issued`, retourne le clair). **Ne réinventez pas** la génération de token. Le poste connu rapproché EST le `Workstation` cible ; pas de création de poste si déjà en DB. [Source: app/Services/Agent/Enrollment/TokenRotationService.php:46-59]
8. **Token NON renvoyé au poste à l'instant de l'approbation.** L'admin approuve dans l'UI (canal web) ; le poste, lui, est sur le canal agent et **rejouera** son `POST /enrollment` à son prochain check-in. Deux possibilités à trancher en design (décision n° 4) — la plus simple et la plus sûre : l'approbation **arme** la demande, et c'est le **prochain `redeem()` du poste** (faisceau re-présenté) qui matérialise `issueFor()` et renvoie le token 200. Le token ne transite jamais par l'UI. [Source: docs/agent/enrollment.md:35-39 ; epics-agent-desired-state.md:569]
9. **Mode campagne = config + borne temporelle, désactivable.** Le mode auto-approbation est un réglage admin **borné dans le temps OU désactivable**. Stockage = `system_settings` (table de réglages existante) plutôt qu'une clé `.env` figée — l'admin l'active/désactive depuis l'UI sans déploiement. Toute lecture `system_settings` en test exige le setup (cf. piège tests n° 12). [Source: epics-agent-desired-state.md:571]
10. **UI = conventions CLAUDE.md.** Livewire SFC sous `resources/views/pages/`, **modale réutilisable** `<x-molecules.modal>` (déclenchée par `#[On('open-…-modal')]` + `public bool $isOpen`), trait `App\Components\Traits\WithToasts` (`toastSuccess`/`toastError`). L'UI complète parc-settings/agent (rings + releases + enrôlements) est la story **25.5** : ici on livre **la surface d'approbation** (liste des demandes + actions approuver/rejeter), réutilisable/intégrable par 25.5. [Source: resources/views/components/molecules/modal/index.blade.php ; app/Components/Traits/WithToasts.php ; CLAUDE.md]
11. **Frontière `agent_*` + zéro AD.** La table neuve porte le préfixe `agent_` (`agent_enrollment_requests`). Le rapprochement **LIT** `workstations` (MAC/hostname/uuid/token), n'écrit que la table neuve + l'appel `issueFor()` (qui écrit les colonnes `agent_*` du poste). **Aucun** appel LdapRecord/Kerberos/samba-tool (critère Keycloak NFR7, grep en review). [Source: architecture-agent-desired-state.md:594-599 ; story 23.2 AC7]
12. **VM : migration + config + tests** (mémoires projet) : migration neuve → `/vm` `php artisan migrate` ; si l'auto-approbation lit `system_settings`/`config/agent.php`, `config:cache` + chown www-admin. `system_settings` doit être présente dans le setup des feature tests qui la lisent (sinon échec iso `WpkgReportApiTest` pré-existant). Commande de tests : `php artisan test --filter Agent` (décision Henri, jamais la suite complète).
13. **Throttle déjà en place sur l'endpoint** (`throttle:10,1`) : un poste qui rejoue ne floode pas. Ne pas re-câbler de throttle ; ne pas créer d'oracle de présence dans la réponse (la réponse à une demande en attente reste un **403 indistinct**, le poste ne doit rien apprendre de l'état de sa demande — cf. décision n° 5).

## Décisions de design prises ici (à challenger en review, pas à re-trancher en dev)

1. **Table neuve `agent_enrollment_requests`** (pas de colonnes sur `workstations` : la demande précède le rapprochement, elle peut viser un poste inconnu). Colonnes : `id` ; `mac` string(17) nullable (normalisée) ; `hostname` string(255) nullable ; `uuid` string(64) nullable ; `matched_workstation_id` FK `workstations` nullable (`nullOnDelete`) — le poste connu rapproché, null si inconnu ; `status` string(16) (`pending` | `approved` | `rejected`) ; `auto_approved` boolean default false ; `last_seen_at` timestamp (récence — le poste rejoue) ; `resolved_at` timestamp nullable ; `resolved_by` nullable (id admin, pour l'audit de l'approbation manuelle) ; timestamps. Index sur `status`, contrainte d'unicité métier portée **en code** (`updateOrCreate` sur la clé du faisceau — piège SQLite varchar/unique partiel, story 25.1 piège n° 9).
2. **Clé d'idempotence du faisceau** = `mac` normalisée si présente, sinon `hostname` (lowercase), sinon — si tout est vide — une demande non rapprochable est tout de même tracée mais jamais dédupliquée sur du vide. `updateOrCreate(['mac' => …] | ['hostname' => …], [...])` rafraîchit `last_seen_at`. Une demande `approved`/`rejected` n'est **pas** ré-ouverte par un nouveau `redeem()` du même faisceau tant qu'elle n'est pas re-armée (sinon un poste rejeté en boucle re-créerait une demande) — sauf après consommation (cf. décision n° 4).
3. **Rapprochement (`EnrollmentMatchService`, neuf)** : à partir du faisceau, résout un **unique** `Workstation` candidat selon la règle figée — (a) MAC normalisée → `where('mac', …)` ; si exactement 1 résultat ET hostname cohérent (ou hostname absent côté demande) → candidat ; (b) sinon, **pas de candidat unique fiable** → `matched_workstation_id = null`, demande **manuelle**. Le service expose `match(array $identity): ?Workstation` + `isConcordant(Workstation, array $identity): bool` (faisceau concordant = MAC connue ET hostname cohérent ET poste **non enrôlé**). Lecture seule `workstations`, zéro AD.
4. **Approbation = armer la demande, le token naît au prochain `redeem()` du poste** (piège n° 8). L'action UI « Approuver » passe `status = approved`, fixe `matched_workstation_id` (si pas déjà résolu, l'admin a choisi le poste cible dans la modale OU le service l'a rapproché). Le **prochain** `POST /enrollment` du poste (faisceau re-présenté) : `redeem()` voit une demande `approved` concordante → `issueFor()` → 200 `{success, token}` → la demande passe `consumed`/supprimée (statut terminal). Avantage : le token ne transite **jamais** par l'UI, le poste reste maître de récupérer son token sur **son** canal authentifié-par-le-réseau (`local.request`). *(Alternative : émettre un ticket porte-1 à l'approbation et laisser le poste l'échanger — plus de pièces mobiles ; la voie « demande armée » est retenue pour sa simplicité. À valider en review.)*
5. **Réponse au poste = 403 indistinct tant que non approuvé**, exactement comme aujourd'hui (`AGENT_ENROLL_NOT_ALLOWED`). La création/rafraîchissement de la demande est un **effet de bord serveur** invisible du poste (pas d'oracle : un poste ne doit pas savoir s'il est « en attente » vs « refusé » vs « inconnu »). À l'approbation consommée → 200 + token. Conflit (poste connu déjà enrôlé) → 409 inchangé. [Source: app/Http/Controllers/Api/V1/Agent/EnrollController.php:60-72]
6. **Mode campagne = réglage `system_settings`** (`agent_enroll_campaign_until` timestamp nullable, OU booléen `agent_enroll_campaign_enabled` + `until`) : actif si `until` dans le futur. Auto-approbation **uniquement** si campagne active **ET** faisceau concordant avec un poste connu non enrôlé (décision n° 3) **ET** un seul candidat. Sinon manuel. La borne temporelle se vérifie à chaque `redeem()` (pas de tâche planifiée requise pour la sécurité — un dépassement de borne = retour au manuel par construction).
7. **Logs distincts** (channel `agent`, contexte `workstation_id` quand rapproché, jamais de token/hash) : `agent.enroll.requested` (création/refresh d'une demande pending), `agent.enroll.auto_approved` (campagne + concordance), `agent.enroll.approved` (un-clic admin, contexte `resolved_by`), `agent.enroll.rejected` **réutilisé/étendu** (rejet admin — distinguer du rejet technique existant par la `reason`, p.ex. `manual_reject`). L'action `agent.enroll.requested` est **nouvelle** ; les autres complètent la nomenclature `agent.enroll.*` déjà en place. [Source: app/Services/Agent/Enrollment/EnrollmentService.php:74-217 ; epics-agent-desired-state.md:573,578]
8. **Surface UI = composant d'approbation autonome** (`resources/views/pages/parc-settings/agent/_partials/enrollment-requests.blade.php` ou équivalent), Livewire SFC : liste paginée des demandes `pending` (faisceau affiché + rapprochement DB le cas échéant), boutons « Approuver » (un-clic, `wire:confirm` ou modale de confirmation) et « Rejeter » (modale réutilisable avec affichage des preuves pour décision éclairée — AC4), `WithToasts`. Le squelette de page `parc-settings/agent/index.blade.php` peut être créé minimal ici, mais **rings/releases/progression = 25.5** (ne pas empiéter). [Source: epics-agent-desired-state.md:608-610 ; CLAUDE.md]

## Acceptance Criteria

### AC1 — Demande d'enrôlement créée, poste en attente (FR16, gap 3)

**Given** un agent posé sur un poste migré, **sans token**
**When** il appelle `POST /v1/agent/enrollment` (porte 2 : ticket absent ou invalide) avec ses preuves d'identité — `hostname` + `mac` + `uuid` SMBIOS, **aucune preuve n'étant suffisante seule** (gap 3 : l'uuid SMBIOS s'est déjà montré peu fiable — MAC = ancre, hostname/uuid corroborants ; le faisceau retenu est documenté en `docs/agent/enrollment.md`)
**Then** une **demande en attente** (`agent_enrollment_requests`, `status = pending`) est créée et visible dans l'UI, avec le **rapprochement** au poste connu en DB le cas échéant (`matched_workstation_id`)
**And** le poste reste **403** (`AGENT_ENROLL_NOT_ALLOWED`, indistinct — pas d'oracle) et **rejoue son check-in « légèrement en attendant »** : un re-`POST` du même faisceau **rafraîchit** la demande (`last_seen_at`) sans la dupliquer (idempotence — décision n° 2)
**And** log `agent.enroll.requested` (channel `agent`, faisceau résumé, `matched_workstation_id` si rapproché, **jamais** de token/hash).

### AC2 — Approbation un-clic → le token naît (FR16, cycle 23.2)

**Given** la demande visible dans l'UI (preuves affichées, rapprochement DB le cas échéant)
**When** l'admin **approuve d'un clic**
**Then** la demande passe `status = approved` (avec `resolved_by`, `resolved_at`) et le **token naît** via `TokenRotationService::issueFor()` au **prochain check-in du poste** (le poste re-présente son faisceau → `redeem()` voit la demande approuvée concordante → 200 `{success, token}` → demande consommée) — le token ne transite **jamais** par l'UI (décision n° 4)
**And** le poste, une fois le token reçu, commence à converger (canal `/state` 23.5, hors-scope ici)
**And** log `agent.enroll.approved` (channel `agent`, `workstation_id`, `resolved_by`) ; toast de confirmation (`WithToasts`).

### AC3 — Mode campagne : auto-approbation bornée, anti-usurpation jamais débrayé (FR16)

**Given** une campagne de passage à SE5 (mode « approbation automatique » activé par l'admin, **borné dans le temps** ou **désactivable** — réglage `system_settings`, décision n° 6)
**When** une demande d'enrôlement arrive dont les preuves **concordent avec un poste déjà connu en DB** (importé AD/legacy : MAC connue **ET** hostname cohérent **ET** poste **non enrôlé**, candidat **unique**)
**Then** elle est **approuvée automatiquement**, le token naît sans clic (au prochain `redeem()`, idem AC2), et l'approbation auto est loggée **distinctement** `agent.enroll.auto_approved` (`auto_approved = true` en base)
**And** **toute** demande dont les preuves **divergent** du poste connu (MAC connue mais hostname différent, plusieurs candidats, poste **inconnu** en DB, ou poste connu **déjà enrôlé** = conflit) reste en approbation **manuelle**, **même en mode campagne** — l'anti-usurpation **ne se débraye jamais** (invariant verrouillé par test — piège n° 3)
**And** campagne expirée (borne dépassée) → retour au manuel par construction (vérifié à chaque `redeem()`, pas de tâche planifiée requise).

### AC4 — Rejet d'une demande douteuse (FR16)

**Given** une demande douteuse (preuves incohérentes avec le poste connu, poste inconnu, ou conflit)
**When** l'admin la **rejette** (depuis l'UI — modale réutilisable affichant les preuves pour une décision éclairée)
**Then** la demande passe `status = rejected`, le poste **reste hors système** (403 indistinct au prochain `redeem()`, **aucun** token émis) et le rejet est **loggé** (`agent.enroll.rejected`, `reason = manual_reject`, distinct du rejet technique porte 1)
**And** un re-`POST` du poste rejeté ne ré-ouvre **pas** automatiquement la demande (décision n° 2 — anti-bruit ; l'admin garde la main pour re-armer).

### AC5 — UI Livewire d'approbation (conventions CLAUDE.md)

**Given** la surface d'approbation des enrôlements (Livewire SFC sous `resources/views/pages/parc-settings/agent/`, intégrable par 25.5)
**When** l'admin la consulte
**Then** elle liste les demandes `pending` (faisceau `hostname`/`mac`/`uuid` affiché + rapprochement DB le cas échéant + badge `auto`/`manuel`/`conflit`), avec actions **« Approuver »** (un-clic) et **« Rejeter »** (via la **modale réutilisable** `<x-molecules.modal>`, preuves affichées)
**And** les retours d'action utilisent le trait `WithToasts` (`toastSuccess`/`toastError`) ; aucun composant modale ad hoc (réutilise `<x-molecules.modal>`).

### AC6 — Frontières, sécurité & observabilité (NFR7, FR27)

**Then** **aucune** nouvelle route d'enrôlement (réutilise `agent.v1.enrollment` — piège n° 1) ; canal JWT legacy `agent.v1.enroll` et flux **porte 1** (ticket valide) **intouchés** (un ticket valide enrôle toujours directement, vérifié par test de non-régression)
**And** **aucune** écriture hors `agent_*` (la table neuve `agent_enrollment_requests` + `issueFor()` sur les colonnes `agent_*` du poste ; **lecture seule** sur `workstations` pour le rapprochement)
**And** **aucun** appel AD/LdapRecord/Kerberos/samba-tool (critère Keycloak NFR7 — grep en review : `app/Services/Agent`, contrôleur, composant Livewire → vide)
**And** logs channel `agent`, actions `agent.enroll.{requested,approved,auto_approved,rejected}`, contexte `workstation_id` quand applicable, **jamais** de token/hash/preuve sensible en clair excédant le faisceau ; réponse au poste **sans oracle** (403 indistinct).

### AC7 — Tests

**Then** `tests/Feature/Api/V1/Agent/EnrollmentGate2Test.php` (conventions `EnrollmentControllerTest`/`StateEndpointTest` : `Workstation::factory()`, `WorkstationGroup::factory()`, mock channel `agent`) couvre :
- porte 2 sans ticket, poste inconnu → demande `pending` créée + **403 indistinct** + log `requested` ;
- re-`POST` même faisceau → **pas de doublon**, `last_seen_at` rafraîchi (idempotence) ;
- poste connu non enrôlé, faisceau concordant, **campagne ON** → demande `auto_approved` + prochain `redeem()` → **200 token** + log `auto_approved` ;
- même cas **campagne OFF** → reste `pending` manuel (403) ;
- faisceau divergent (hostname différent / poste inconnu / multi-candidats) **campagne ON** → reste **manuel** (jamais auto — invariant) ;
- poste connu **déjà enrôlé** → **409 conflit** inchangé, jamais auto ni demande pending d'enrôlement ;
- campagne **expirée** → manuel ;
- **non-régression porte 1** : ticket valide → 200 token direct, **aucune** demande créée ;
- approbation manuelle (service) → prochain `redeem()` → 200 token + log `approved` ;
- rejet manuel → re-`POST` → 403, aucun token, pas de ré-ouverture auto.

**And** `tests/Unit/Services/Agent/EnrollmentMatchServiceTest.php` : MAC normalisée (tirets/colons/nu), candidat unique vs multi-candidats, hostname cohérent/divergent, poste enrôlé exclu de la concordance, uuid jamais suffisant seul.

**And** un test de la surface UI Livewire (Livewire::test) : liste pending, action approuver (toast + statut), action rejeter (modale → statut + toast).

**And** `php artisan test --filter Agent` intégralement vert sur `/vm` — baseline constatée au premier run, **zéro régression**, golden files figés (`state.v1.json`, `report.v1.json`, `release-manifest.v1.json`, `FROZEN_STATE_HASH`) **intouchés**. **UNIQUEMENT ce filtre** (décision Henri).

### AC8 — Transversal : doc, VM

**Then** `docs/agent/enrollment.md` **complété** (section porte 2 : flux, faisceau retenu + justification gap 3, mode campagne + bornage, anti-usurpation, codes 200/403/409, logs `agent.enroll.*`) — la section porte 1 et `contract-v1.md` **figés/inchangés** ; les renvois « 25.3 à venir » (l.17-19, :102, :124-125, :157) **résolus**
**And** `docs/qa/domains/agent.md` : section NEUVE append-only (scénarios numérotés : poste migré sans ticket → demande pending visible UI ; approbation un-clic → token au check-in ; campagne ON poste connu → auto ; campagne ON poste divergent → manuel ; rejet → poste hors système)
**And** opérations VM tracées : `php artisan migrate` (table neuve) + (si lecture config/réglage neuf) `config:cache` + chown www-admin (`bootstrap/cache/`) ; smoke : `curl` `POST /v1/agent/enrollment` sans ticket avec faisceau d'un poste connu → 403 + demande visible en base ; toggle campagne + re-`POST` → auto-approbation tracée.

## Tasks / Subtasks

- [x] **T1 — Migration `create_agent_enrollment_requests_table`** (AC1, décision n° 1)
  - [x] `database/migrations/2026_06_13_<hhmmss>_create_agent_enrollment_requests_table.php` (guards `Schema::hasTable`, docblock + `down()` iso `2026_06_12_120000_create_agent_release_tables.php`) : `agent_enrollment_requests` — `mac` string(17) nullable, `hostname` string(255) nullable, `uuid` string(64) nullable, `matched_workstation_id` FK `workstations` `nullable()->nullOnDelete()`, `status` string(16) default `pending` + index, `auto_approved` boolean default false, `last_seen_at` timestamp, `resolved_at` timestamp nullable, `resolved_by` unsignedBigInteger nullable, timestamps. Index `status`. Pas de contrainte unique partielle (parité SQLite — unicité métier en code, piège n° 5 / 25.1 piège n° 9).
- [x] **T2 — Modèle `App\Models\AgentEnrollmentRequest`** (AC1)
  - [x] `$fillable` explicite, casts `auto_approved => bool` / `last_seen_at`/`resolved_at` datetime, relation `matchedWorkstation()`, scope `pending()`. Docblock iso `AgentRelease`/`AgentResourceState`.
- [x] **T3 — `App\Services\Agent\Enrollment\EnrollmentMatchService` (neuf)** (AC1, AC3, décision n° 3)
  - [x] `match(array $identity): ?Workstation` — MAC normalisée (`MacAddressNormalizer`) → lookup `where('mac', …)` ; candidat unique exigé ; uuid/hostname jamais suffisants seuls.
  - [x] `isConcordant(Workstation $w, array $identity): bool` — MAC connue ET hostname cohérent (`strcasecmp` ou hostname absent côté demande) ET poste **non enrôlé** (`! $w->isAgentEnrolled()`). Lecture seule `workstations`, zéro AD. `declare(strict_types=1)`, classe pure injectable.
- [x] **T4 — `EnrollmentService` : branche porte 2** (AC1, AC2, AC3, AC4, décisions n° 2/4/5/6/7)
  - [x] Étendre `reject()` (ou extraire une méthode `handleGate2()`) : avant de retourner `notAllowed`, **enregistrer/rafraîchir** la demande (`updateOrCreate` sur la clé du faisceau — décision n° 2), rapprocher via `EnrollmentMatchService`, log `agent.enroll.requested`.
  - [x] Auto-approbation : si campagne active (réglage `system_settings`/`config` — décision n° 6) **ET** `isConcordant()` **ET** candidat unique → `status = approved`, `auto_approved = true`, log `agent.enroll.auto_approved`. Sinon `pending`. **Jamais** auto si conflit/divergence/inconnu (invariant — piège n° 3/4).
  - [x] `redeem()` côté ticket **inchangé** (porte 1) ; au **début** de la branche d'échec, court-circuit : si une demande **`approved` concordante** existe pour ce faisceau → `issueFor()` → `EnrollmentResult::enrolled($token)` + consommation de la demande (statut terminal) + log (réutilise `agent.enroll.enrolled` ou `approved`). 200 token.
  - [x] Méthodes service d'action UI : `approveManually(AgentEnrollmentRequest, ?int $resolvedBy, ?Workstation $target): void` (log `approved`) et `rejectManually(AgentEnrollmentRequest, ?int $resolvedBy): void` (log `rejected` `reason=manual_reject`).
- [x] **T5 — Mode campagne : réglage + lecture** (AC3, décision n° 6)
  - [x] Réglage `agent_enroll_campaign_until` (et/ou `enabled`) dans `system_settings` (pattern existant) + helper de lecture (`isCampaignActive(): bool` — `until` futur). Si une clé `config/agent.php` est ajoutée (défaut OFF), documenter `config:cache` (piège n° 12).
- [x] **T6 — UI : surface d'approbation Livewire SFC** (AC5, décision n° 8)
  - [x] Composant Livewire SFC (sous `resources/views/pages/parc-settings/agent/_partials/` ; squelette `parc-settings/agent/index.blade.php` minimal autorisé, **sans** rings/releases — 25.5) : liste paginée `pending` (faisceau + rapprochement + badge auto/manuel/conflit), bouton « Approuver » (un-clic, appelle `approveManually`), bouton « Rejeter » → **modale réutilisable** `<x-molecules.modal>` (preuves affichées + confirmation → `rejectManually`). Trait `WithToasts` (`toastSuccess`/`toastError`). Pattern Livewire SFC iso `resources/views/pages/parc-settings/index.blade.php`.
- [x] **T7 — Documentation** (AC8)
  - [x] `docs/agent/enrollment.md` : section porte 2 (flux, faisceau + gap 3, campagne + bornage, anti-usurpation, codes, logs) ; résoudre les renvois « 25.3 à venir » ; **ne pas toucher** la section porte 1 ni `contract-v1.md`.
  - [x] `docs/qa/domains/agent.md` : section porte 2 append-only, scénarios numérotés stables.
- [x] **T8 — Tests** (AC7)
  - [x] Feature `EnrollmentGate2Test.php` (matrice AC7) + Unit `EnrollmentMatchServiceTest.php` + test Livewire de la surface d'approbation. `system_settings` dans le setup des tests campagne (piège n° 12).
- [x] **T9 — Vérifications finales + VM** (AC6, AC7, AC8)
  - [x] `php -l` sur tous les fichiers ; grep critère Keycloak (`ldap|kerberos|samba-tool|apcu`) sur le nouveau code → vide ; grep writes hors `agent_*` → vide.
  - [x] `/vm` (`ssh -i ~/.ssh/id_se4fs_vm root@192.168.122.50`, `/var/www/sambaedu-reload`) : `php artisan migrate` (+ `config:cache` + chown www-admin si clé config neuve) ; `php artisan test --filter Agent` (baseline, **jamais** la suite complète) ; smoke : `curl POST /v1/agent/enrollment` sans ticket (faisceau d'un poste connu) → 403 + demande en base ; activer campagne + re-`POST` → auto-approbation tracée + prochain `redeem()` → 200 token.

## Dev Notes

### Périmètre — livré / hors-scope

| Livré (25.3) | Hors-scope (story) |
|---|---|
| Table `agent_enrollment_requests` + modèle | UI complète `parc-settings/agent/` (rings, releases, progression) → **25.5** |
| `EnrollmentMatchService` (faisceau, concordance, anti-usurpation) | GPO-dispatcher figée qui pose l'agent sur les postes migrés → **25.4** |
| Branche porte 2 dans `EnrollmentService` (demande pending, auto/manuel, consommation à l'approbation) | Auto-update agent (25.2, `review`), distribution releases (25.1, `done`) |
| Mode campagne (réglage borné/désactivable) | Création/import des postes AD/legacy (déjà en DB — préexistant, lu en seul) |
| Surface UI Livewire d'approbation (liste + approuver/rejeter, modale réutilisable) | Levée de quarantaine outillée (runbook — non requis ici) |
| Logs `agent.enroll.{requested,approved,auto_approved,rejected}` + doc + QA | Côté agent Go : présentation du faisceau, gestion 403/200 au check-in → **Epic 24** (déjà émet uuid/mac/hostname) |

### Patterns existants à imiter (NE PAS réinventer)

- **Endpoint & service d'enrôlement (porte 1)** : `app/Http/Controllers/Api/V1/Agent/EnrollController.php`, `app/Http/Requests/Api/V1/Agent/EnrollmentRequest.php` (ticket `nullable` — déjà prévu pour porte 2), `app/Services/Agent/Enrollment/EnrollmentService.php` (`redeem()`/`reject()`/`resolveByIdentity()`/`warnIfIdentityMismatch()`), `app/Services/Agent/Enrollment/EnrollmentResult.php` (résultat typé). **C'est le squelette exact à étendre.**
- **Naissance du token** : `app/Services/Agent/Enrollment/TokenRotationService.php:46-59` (`issueFor`). [piège n° 7]
- **Normalisation MAC** : `app/Ipxe/Support/MacAddressNormalizer.php:38-59` ; `Workstation::setMacAttribute` (`app/Models/Workstation.php:111-114`) — colonne `mac` canonique lowercase `:`.
- **Modèle/migration `agent_*`** : `app/Models/AgentRelease.php`, `database/migrations/2026_06_12_120000_create_agent_release_tables.php` (guards, FK cascade, `down()`).
- **Service + binding** : `app/Providers/AgentServiceProvider.php` (singletons — y enregistrer `EnrollmentMatchService` si besoin d'injection).
- **Feature tests canal** : `tests/Feature/Api/V1/Agent/StateEndpointTest.php`, `ReleaseEndpointTest.php`, et le test d'enrôlement porte 1 existant — helper privé, `Workstation::factory()`, mock channel `agent` (debug→error).
- **UI** : `resources/views/components/molecules/modal/index.blade.php` (modale réutilisable, `#[On('open-…-modal')]` + `public bool $isOpen`), `app/Components/Traits/WithToasts.php` (`toastSuccess`/`toastError`), `resources/views/pages/parc-settings/index.blade.php` (pattern Livewire SFC : `new class extends Component` inline + traits + template Blade), card « Agent » page machine `resources/views/pages/parc/machines/[id]/index.blade.php` (révocation `wire:confirm` + `TokenRotationService` method-injection — modèle d'action agent côté UI).
- **`system_settings`** : table de réglages existante (pattern de lecture/écriture à reprendre pour le mode campagne — chercher l'usage existant ; setup test requis).

### Architecture — conventions figées applicables (NON négociables)

[Source: architecture-agent-desired-state.md#Enrôlement deux portes (l.300-302) ; #Gaps (l.735-739) ; #Naming Patterns ; #Architectural Boundaries (l.594-599)]

- **Deux portes** : iPXE (token déposé, admin authentifié) **vs** poste migré (agent posé par bootstrap GPO, sans token, **approbation un-clic UI**). La porte 2 réutilise `POST /api/v1/agent/enroll` (ici l'URI réelle `/v1/agent/enrollment`, le nom legacy `/enroll` étant pris — frontière intacte).
- **Gap 3 (prioritaire dans le périmètre sécurité)** : faisceau `hostname + MAC + uuid SMBIOS`, **aucune preuve suffisante seule** ; uuid SMBIOS écarté comme preuve unique (peu fiable). Documenter le faisceau retenu.
- Nommage : modèle `AgentEnrollmentRequest`, services `App\Services\Agent\Enrollment\{EnrollmentService, EnrollmentMatchService}`, table `agent_enrollment_requests`, channel `agent`, logs `agent.enroll.*`, config `config/agent.php`, fixtures `tests/Fixtures/Agent/` si besoin.
- Frontières : canal agent n'écrit QUE dans `agent_*` ; **lecture seule** `workstations` pour le rapprochement ; **aucune** écriture/lecture AD (critère Keycloak NFR7) ; endpoint jamais hors `/api/v1/agent/*` ; **aucune** nouvelle route (réutilise l'existante).
- Codes canal : 200 / 403 / 409 (+ 429 throttle) ; réponse au poste **sans oracle**.

### Mémoires projet applicables

- `project_agent_token_file_path_contract`, `project_agent_runtime_go` — l'agent (Go) émet déjà `uuid`/`mac`/`hostname` (Epic 24) ; la story serveur ne touche pas l'agent.
- `project_ipxe_param_use_smbios_vars` — **uuid SMBIOS peu fiable / champ SQL vide** : justification directe de « aucune preuve suffisante seule » et du choix MAC = ancre.
- `feedback_auth_iso_legacy` — auth machine iso-legacy : **pas** de Bearer/secret per-host à l'enrôlement initial ; le token naît à l'approbation (cycle 23.2). Cohérent : la porte 2 n'introduit aucun secret pré-partagé.
- `project_vm_migrations_not_auto_applied` — migration neuve = `migrate:status` puis `migrate` sur `/vm`.
- `project_vm_config_cache_not_synced` — toute clé `config/*.php` neuve → `config:cache` + chown www-admin sinon `null` en HTTP.
- `project_php_fpm_user_www_admin` — fichiers lus par PHP chown www-admin (uid 599).
- `project_sqlite_tests_no_varchar_enforcement` — domaines fermés (`status`) validés en code, pas par la colonne.
- `feedback_epic23_model_fable5` — réflexe « contrat = petit modèle » : ici contredit par la composante sécurité (cf. Recommandation Modèle Dev).

### Project Structure Notes

- **Racine = projet Laravel** (`app/`, `config/`, `tests/`, `routes/` à la racine) ; code édité sur l'hôte, exécuté sur la VM `/vm` ; sync inotify auto (jamais de sync manuel ; si non-synchro → notifier Henri et attendre) ; inotify ne propage pas les deletes.
- Story avec **migration + (possible) clé config neuve + UI Livewire** → VM : `php artisan migrate` (+ `config:cache` + chown www-admin si config) ; pas de `route:cache` requis (aucune route neuve — piège n° 1).
- Jamais de VM/SSH depuis un worktree git (mémoire projet).

### Testing standards

- PHPUnit, exécution de référence sur `/vm` ; SQLite `:memory:` en feature (`RefreshDatabase`). Domaines fermés (`status`) validés en code (varchar non appliqué en SQLite).
- **Commande prescrite : `php artisan test --filter Agent` UNIQUEMENT** (décision Henri) — constater la baseline au premier run (post-25.2), zéro régression.
- Tests campagne : `system_settings` présente dans le setup (sinon échec iso `WpkgReportApiTest` pré-existant).
- Golden files figés (`state.v1.json`, `report.v1.json`, `release-manifest.v1.json`, `FROZEN_STATE_HASH`) **intouchés** ; `contract-v1.md` figé.
- Tests Livewire : `Livewire::test()` sur le composant d'approbation (liste, approuver, rejeter).

### Intelligence stories précédentes

- **23.2 (done)** : `TokenRotationService` (`issueFor` = naissance du token) ; middleware = autorité 401/403/check-in/rotation ; jamais de secret en log ; normalisation MAC via `MacAddressNormalizer` (review P1). MAC = ancre fiable, hostname = corroborant (uuid écarté).
- **23.3 (done)** : porte 1 livrée — `EnrollmentService`/`EnrollController`/`EnrollmentRequest`/`EnrollmentResult` ; `redeem()` résout **par hash du ticket** (uuid/mac ne servent qu'au log et au choix 409/403) ; **`reject()` documente déjà la porte 2** comme son futur point d'accueil ; ticket `nullable` exprès. La doc `enrollment.md` annonce la porte 2 (renvois à résoudre). Endpoint `/v1/agent/enrollment` `local.request` (LAN), pas `agent.token`.
- **25.1 (done)** : conventions migration/modèle/service/binding `agent_*` ; frontière `agent_*` ; 404/oracle ; golden additive ; filtre Agent only.
- **25.2 (review)** : 100 % agent Go (auto-update) — n'impacte pas cette story serveur ; l'agent émet le faisceau au check-in.

### References

- [Source: _bmad-output/planning-artifacts/epics-agent-desired-state.md:555-578] — Story 25.3, ACs Given/When/Then source, FR16.
- [Source: _bmad-output/planning-artifacts/architecture-agent-desired-state.md:300-302 (deux portes) ; :735-739 (gap 3, faisceau, uuid peu fiable) ; :594-599 (frontières)] — décisions d'architecture.
- [Source: _bmad-output/planning-artifacts/product-brief-agent-desired-state-2026-06-11.md] — intention métier (enrôler l'existant sans réinstallation).
- [Source: app/Services/Agent/Enrollment/EnrollmentService.php:97-160] — `redeem()`/`reject()`/`resolveByIdentity()` — point d'extension porte 2.
- [Source: app/Http/Controllers/Api/V1/Agent/EnrollController.php:42-73 ; app/Http/Requests/Api/V1/Agent/EnrollmentRequest.php:30-38 ; app/Services/Agent/Enrollment/EnrollmentResult.php] — controller/request/result (ticket nullable, 409/403).
- [Source: app/Services/Agent/Enrollment/TokenRotationService.php:46-59] — `issueFor()` (naissance token).
- [Source: app/Ipxe/Support/MacAddressNormalizer.php:38-59 ; app/Models/Workstation.php:111-114, :201-208, :380-389] — normalisation MAC, relation groups, helpers `isAgentEnrolled`/`isAgentQuarantined`.
- [Source: routes/api.php:190-212 (legacy JWT intouché), :286-298 (bloc agent desired-state)] — réutiliser `agent.v1.enrollment`, aucune route neuve.
- [Source: docs/agent/enrollment.md:11-19, :71-75, :102, :124-125, :157] — porte 2 annoncée, renvois à résoudre, 403 indistinct.
- [Source: resources/views/components/molecules/modal/index.blade.php ; app/Components/Traits/WithToasts.php ; resources/views/pages/parc-settings/index.blade.php] — modale réutilisable, trait toasts, pattern Livewire SFC.
- [Source: database/migrations/2026_06_12_120000_create_agent_release_tables.php ; app/Models/AgentRelease.php ; app/Providers/AgentServiceProvider.php] — conventions migration/modèle/binding `agent_*`.
- [Source: docs/qa/domains/agent.md] — domaine QA existant, sections append-only.

## Dépendances

| Dépendance | Statut (sprint-status.yaml, 2026-06-13) | Nature |
|---|---|---|
| **Story 23.2** — cycle de vie du token agent (`issueFor`, middleware, channel `agent`) | **`done`** | **Bloquante satisfaite** — `TokenRotationService::issueFor()` est l'API de naissance du token à l'approbation. |
| **Story 23.3** — enrôlement porte 1 (`EnrollmentService`/`EnrollController`, endpoint `agent.v1.enrollment`) | **`done`** (epic-23 `done`) | **Bloquante satisfaite** — la porte 2 étend la branche d'échec de `redeem()` ; squelette + doc + renvois 25.3 en place. |
| Story 25.1 — releases serveur (conventions `agent_*`, frontières) | `done` | Contexte — conventions de migration/modèle/service/test réutilisées. |
| Story 25.2 — auto-update agent | `review` | Contexte — agent Go (émet le faisceau au check-in) ; n'impacte pas cette story serveur. |
| Story 25.4 — GPO-dispatcher (pose l'agent sur les postes migrés) | `backlog` | **Aval** — fournit l'agent aux postes migrés qui déclencheront la porte 2 ; non bloquant (la porte 2 fonctionne dès qu'un agent appelle l'endpoint). |
| Story 25.5 — UI parc-settings/agent complète | `backlog` | **Aval** — intégrera la surface d'approbation livrée ici dans la page rings/releases/progression. |

## Recommandation Modèle Dev

**opus** — story à forte composante **sécurité** au cœur du périmètre : faisceau de preuves avec « aucune preuve suffisante seule » (gap 3), **anti-usurpation jamais débrayé même en campagne** (invariant à raisonner finement : concordance MAC + hostname + poste non enrôlé + candidat unique, et tous les cas de divergence/conflit/inconnu qui doivent retomber en manuel), **absence d'oracle** dans la réponse au poste, idempotence de la demande sous rejeu, et la mécanique délicate « approuver = armer, le token naît au prochain `redeem()` » (le token ne transite jamais par l'UI). À cela s'ajoute du **backend** (table neuve, deux services, branche d'enrôlement à greffer sans casser la porte 1, mode campagne borné) **et** du **frontend** (UI Livewire d'approbation un-clic + modale réutilisable + toasts). Le couple « logique de sécurité subtile + greffe sur un flux existant sans régression » est exactement le profil où le réflexe « petit modèle » est à éviter (mémoire `feedback_epic23_model_fable5`). `sonnet` serait défendable pour la seule plomberie CRUD + UI, mais le raisonnement anti-usurpation et le sans-oracle justifient `opus`.

## File List

### Créés
- `database/migrations/2026_06_13_120429_create_agent_enrollment_requests_table.php` — table `agent_enrollment_requests` (faisceau nullable, FK `nullOnDelete`, `status` domaine fermé indexé, `auto_approved`, `last_seen_at`, `resolved_at/by`).
- `app/Models/AgentEnrollmentRequest.php` — modèle (constantes statut, casts, relation `matchedWorkstation()`, scope `pending()`).
- `app/Services/Agent/Enrollment/EnrollmentMatchService.php` — rapprochement faisceau→poste (`match()` candidat unique par MAC, `isConcordant()` invariant anti-usurpation), lecture seule `workstations`, zéro AD.
- `app/Services/Agent/Enrollment/EnrollmentCampaign.php` — mode campagne via `system_settings` (`isActive()`/`until()`/`enableUntil()`/`disable()`), borne temporelle fail-safe.
- `resources/views/pages/parc-settings/agent/index.blade.php` — page squelette (Livewire SFC) hébergeant la surface d'approbation (rings/releases = 25.5, non livrés).
- `resources/views/pages/parc-settings/agent/_partials/enrollment-requests.blade.php` — surface Livewire SFC : liste pending, approuver un-clic, rejeter via modale réutilisable, bandeau campagne, `WithToasts`. **(post-review)** `Gate::authorize('computer.install')` sur chaque action (#4), bouton « Approuver » désactivé sans rapprochement (#3), cap campagne 365j (#7).
- `tests/Unit/Services/Agent/EnrollmentMatchServiceTest.php` — 9 tests (MAC normalisée, candidat unique vs multi, hostname cohérent/divergent, enrôlé exclu, uuid jamais seul).
- `tests/Feature/Api/V1/Agent/EnrollmentGate2Test.php` — 15 tests (matrice AC7 complète + non-régression porte 1 + sans-oracle log ; +2 post-review : clone MAC partagée → 409 #M2, uuid seul d'un enrôlé → 403 sans oracle #M3).
- `tests/Feature/Livewire/Agent/EnrollmentRequestsSurfaceTest.php` — 6 tests (liste pending, approuver, **approuver refusé sans rapprochement**, rejeter modale, toggle campagne, **403 sans `computer.install`**) — `actingAs` admin (post-review #3/#4/#5).

### Modifiés
- `app/Services/Agent/Enrollment/EnrollmentService.php` — injection `EnrollmentMatchService` + `EnrollmentCampaign` ; `reject()` → `handleGate2()` (conflit 409 MAC-only / demande approuvée concordante → claim atomique + token / création-refresh demande + auto-approbation campagne) ; nouvelles méthodes `approveManually()` / `rejectManually()` (gardées `pending`) + helpers `recordRequest()`/`findRequestByIdentity()`/`idempotencyKey()`. Flux ticket succès (porte 1) inchangé. **(post-review)** claim atomique étape 2 (#1), gardes de statut (#2), log `stale_approval` (#M4), conflit 409 fondé sur la **seule MAC** via `exists()` enrôlé + `resolveByIdentity()` supprimée (#M2/#M3).
- `app/Providers/AgentServiceProvider.php` — bindings singletons `EnrollmentMatchService` et `EnrollmentCampaign` + injection dans `EnrollmentService`.
- `routes/web.php` — route `parc-settings/agent` (`Route::livewire`, gate `can:computer.install`). Aucune route API neuve (porte 2 réutilise `agent.v1.enrollment`).
- `tests/Unit/Services/Agent/EnrollmentServiceTest.php` — résolution de `EnrollmentService` via le container (constructeur a gagné des dépendances). **(post-review #M3)** le test uuid-seul→conflit devient uuid-seul→**non**-conflit (sans-oracle).
- `tests/Feature/Api/V1/Agent/EnrollmentEndpointTest.php` — **(post-review #M3)** conflit porte 1 réaligné sur la MAC (le 409 par uuid-seul n'existe plus).
- `docs/agent/enrollment.md` — section §9 « Porte 2 » (flux, faisceau gap 3, idempotence, campagne, codes 200/403/409, logs) ; renvois « 25.3 à venir » résolus (§1, §4, §5, §8). Section porte 1 et `contract-v1.md` inchangés.
- `docs/qa/domains/agent.md` — Section 10 (scénarios 10.1→10.10, dont 10.9/10.10 post-review) + entrée Post-correctifs review 25.3 #3/#4 + checklist append-only.
- `docs/qa/README.md` — ligne domaine agent étendue à 25.3.

## Dev Agent Record

### Modèle
opus (`claude-opus-4-8[1m]`).

### Décisions techniques notables
- **Faisceau de preuves (gap 3)** : `EnrollmentMatchService::match()` ne rapproche **que** par MAC normalisée (`MacAddressNormalizer`, tirets/colons/nu) et exige un **candidat unique** (`limit(2)` puis `count() === 1`) — uuid/hostname seuls ne résolvent jamais. La concordance (`isConcordant()`) cumule MAC connue **ET** hostname cohérent/absent **ET** poste non enrôlé : c'est l'invariant anti-usurpation, verrouillé par tests unitaires + feature.
- **Greffe sans régression porte 1** : les deux `reject()` de `redeem()` deviennent `handleGate2()`. Ordre figé : (1) conflit poste enrôlé → 409 sans demande pending ; (2) demande `approved` concordante re-présentée → `issueFor()` + consommation → 200 ; (3) sinon création/refresh pending + auto-approbation éventuelle. Le ticket valide ne passe jamais par `handleGate2` → porte 1 strictement intacte (test `valid_ticket_still_enrolls_directly_without_any_request`).
- **Token jamais dans l'UI (décision n° 4)** : `approveManually()` se contente d'armer la demande (`status=approved`) ; le token naît au **prochain `redeem()`** du poste sur son canal `local.request`. La consommation supprime la ligne (statut terminal).
- **Idempotence (décision n° 2)** : `updateOrCreate` sur la clé du faisceau (MAC, à défaut hostname lowercase) ; un faisceau vide est tracé mais non dédupliquable. Une demande `rejected` n'est pas ré-ouverte par un re-POST (anti-bruit) — seul `last_seen_at` est rafraîchi.
- **Mode campagne** : réglage `system_settings` (`agent_enroll_campaign_until` ISO-8601) plutôt que `.env` → activable/désactivable depuis l'UI sans `config:cache`. Borne dépassée = retour au manuel par construction (vérifié à chaque redeem, fail-safe sur parse corrompu).
- **Sans-oracle préservé** : la réponse reste 403 indistinct (création de demande = effet de bord serveur invisible) ; 409 inchangé pour le conflit. Test dédié vérifie qu'aucun token/hash ne fuit dans les logs porte 2.
- **Frontière `agent_*` + zéro AD** : grep `ldap|kerberos|samba-tool|apcu` sur le nouveau code → vide ; `EnrollmentMatchService` est read-only sur `workstations` ; seules écritures = `agent_enrollment_requests` + `issueFor()` (colonnes `agent_*`).

### Gates
- `php -l` : OK sur tous les fichiers créés/modifiés.
- Tests (hôte, vendor présent) : `--filter Enrollment` → **150 passed (523 assertions)** après corrections post-review + arbitrages M2/M3. Détail : `EnrollmentMatchServiceTest` 9/9, `EnrollmentGate2Test` 15/15, `EnrollmentRequestsSurfaceTest` 6/6, `EnrollmentServiceTest` + `EnrollmentEndpointTest` (porte 1, conflit réaligné MAC) verts.
- `--filter Agent` complet : **257 passed, 45 failed**. Les 45 échecs sont **pré-existants et indépendants** (baseline identique avant toute modif) : `WorkstationGroupObserver`/AdSync tentent un `ldap_search` sur l'hôte sans serveur LDAP (`Can't contact LDAP server`). Aucun échec sur les fichiers de cette story. La VM (avec config AD ou observers neutralisés) est la cible d'exécution de référence d'Henri.
- Grep Keycloak NFR7 / writes hors `agent_*` : vide.

### Golden files
Aucun touché (`state.v1.json`, `report.v1.json`, `release-manifest.v1.json`, `FROZEN_STATE_HASH`, `contract-v1.md` intacts) — la porte 2 n'a pas de contrat JSON v1 (transport uniquement).

### Écarts / points d'attention pour la review
- **UI surface autonome + bandeau campagne** : le partial inclut un toggle campagne (activer/désactiver) en plus de la liste — utile pour AC3 et la démo, mais 25.5 pourra le déplacer/réorganiser. Le squelette `parc-settings/agent/index.blade.php` est volontairement minimal (pas de rings/releases — 25.5).
- **`approveManually($target)`** accepte un `?Workstation` pour fixer la cible d'une demande non rapprochée (poste inconnu/ambigu) ; l'UI livrée n'expose pas encore ce choix (approuve sur le rapprochement existant) — extension naturelle pour 25.5 si besoin d'approuver un poste inconnu en le liant à une fiche.
- **VM (action manuelle Henri, NON exécutée ici)** : `php artisan migrate` (table neuve) ; pas de clé `config/*.php` neuve (campagne via `system_settings`) donc **pas de `config:cache` requis** ; smoke `curl POST /v1/agent/enrollment` sans ticket (faisceau poste connu) → 403 + demande en base ; toggle campagne UI + re-POST → auto-approbation tracée. Migration NON jouée automatiquement (mémoire `project_vm_migrations_not_auto_applied`).

### Corrections post-review (2026-06-13)

Review sonnet (8 findings) + second avis opus (3 manqués) → doc `_bmad-output/codeReviews/25-3.md`. **7 corrigés automatiquement** :
- **#1 (🟠)** claim atomique avant `issueFor()` en porte 2 étape 2 (DELETE conditionnel sur `approved`, miroir porte 1) — ferme le TOCTOU, le perdant retombe en 403 sans oracle. `consumeRequest()` inlinée puis retirée.
- **#2 (🟡)** gardes `status === pending` dans `approveManually()`/`rejectManually()` — idempotence, pas de ré-armement silencieux d'une demande résolue (AC4).
- **#3 (🟠, relevé par opus)** bouton « Approuver » désactivé pour une demande sans rapprochement + garde service → fini l'impasse 403-éternel/demande-invisible.
- **#4 (🟠, relevé par opus)** `Gate::authorize('computer.install')` sur `approve`/`openReject`/`confirmReject`/`enableCampaign`/`disableCampaign` (double protection iso-pattern projet).
- **#5 (🟡)** tests Livewire `actingAs` admin + assertion `resolved_by` + test négatif 403 sans permission.
- **#7 (🟡)** cap campagne 365 jours (UI + serveur).
- **#M4 (🟡, relevé par opus)** log `agent.enroll.stale_approval` (warning) quand une approbation ne se matérialise pas (observabilité).

**Écartés** : #6 (cosmétique, opus 1/3) ; #8 (solution index-unique-PG **rejetée** : contredit la décision n°1 / parité SQLite de la story).

**Arbitrages Henri (2026-06-13) — tranchés** :
- **#M2 + #M3 ✅ corrigés** : le conflit 409 se fonde désormais sur la **seule MAC** (ancre) via `Workstation::where('mac',$mac)->whereNotNull('agent_token_hash')->exists()`. Plus d'oracle uuid (#M3, AC6), et un clone enrôlé sous MAC partagée est toujours détecté (#M2, `exists()` vs `.first()`). `resolveByIdentity()` supprimée. **Changement de contrat propagé à la porte 1** (le 409 par uuid-seul disparaît) : `enrollment.md` §4/§9 + tests porte 1 (`EnrollmentEndpointTest`, `EnrollmentServiceTest`) alignés sur la MAC ; 2 tests neufs gate-2 (`enrolled_clone_sharing_mac_still_returns_409`, `uuid_only_of_enrolled_workstation_does_not_leak_409_oracle`).
- **#M1 ✅ accepté tel quel** : aucune modif. `match()` ne résout que par MAC, le rapprochement ne mute pas via hostname ; risque résiduel (MAC spoofée) dans le modèle de menace iso-legacy accepté (`feedback_auth_iso_legacy`).

## Change Log

| Date | Auteur | Changement |
|---|---|---|
| 2026-06-13 | DEV opus | Implémentation porte 2 : table+modèle `agent_enrollment_requests`, `EnrollmentMatchService` (faisceau/concordance/anti-usurpation), `EnrollmentCampaign` (réglage borné), branche `handleGate2` dans `EnrollmentService` (demande pending, auto/manuel, consommation à l'approbation), surface UI Livewire (liste + approuver/rejeter + modale réutilisable + campagne), doc §9 enrollment.md + QA Section 10. 26 tests neufs verts, zéro régression porte 1. Status → review. |
| 2026-06-13 | Review sonnet + 2e avis opus | 8 findings + 3 manqués (opus). 7 corrigés auto (#1 claim atomique, #2 gardes statut, #3 approuver sans rapprochement, #4 Gate::authorize, #5 tests authz, #7 cap campagne, #M4 log stale_approval) ; #6/#8 écartés ; #M1/#M2/#M3 en arbitrage Henri. `--filter Enrollment` 148 passed. Doc `codeReviews/25-3.md`. |
| 2026-06-13 | Arbitrages Henri | #M2+#M3 corrigés : conflit 409 **MAC-only** (`exists()` enrôlé) → plus d'oracle uuid, clone MAC partagée détecté ; `resolveByIdentity()` supprimée ; contrat propagé porte 1 (`enrollment.md` + tests porte 1/porte 2 réalignés, 2 tests gate-2 neufs). #M1 accepté tel quel. `--filter Enrollment` **150 passed (523 assertions)**. Review doc → done. |

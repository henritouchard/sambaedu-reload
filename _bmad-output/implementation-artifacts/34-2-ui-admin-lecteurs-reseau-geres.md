# Story 34.2 : UI admin (refnum) des lecteurs réseau gérés

Status: review

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

En tant qu'**administrateur d'établissement (refnum)**,
je veux **une UI Livewire pour créer/éditer un répertoire réseau nommé, choisir son audience (utilisateur / groupe d'utilisateurs / parc WorkstationGroup) avec un accès RO/RW, fixer ou laisser auto la lettre de lecteur, et déclencher son provisioning**,
afin que **je puisse exploiter sans `tinker` la fondation backend livrée en 34.1, avec un filet de validation prédictive qui m'évite les deux pièges connus (parc = visibilité-seule sans accès réel ; collision de lettre silencieuse) AVANT que l'erreur n'atteigne les postes**.

## Contexte & intention

**DEUXIÈME story de l'Epic 34 « Lecteurs réseau gérés ».** Cet Epic n'a PAS de narratif `epics.md` — il vit dans `backlog.data.js` + ses fichiers de story (iso Epics 28-33). La 34.1 a livré la FONDATION BACKEND (modèle `NetworkShare` + pivot polymorphe `network_share_assignables`, `NetworkShareService::provision()`, extension `DrivesStateProvider`) — sans aucune surface UI : la création/assignation se faisait par migration + factory + `tinker`. **34.2 = la couche UI admin + validation prédictive PAR-DESSUS ce socle, INCHANGÉ.**

**Ce que cette story livre :**
- **Page liste** des répertoires réseau (`/app/shares`) : recherche, pagination, état vide, scope refnum (un refnum ne voit/gère que SON périmètre), bouton « Nouveau répertoire » gardé par policy.
- **Création/édition** d'un `NetworkShare` : `name`, `directory_name` (segment FS sûr, validé au FORMAT — finding 34.1 M4), `label` optionnel, `letter` (explicite ou auto). Réutilise la modale réutilisable `x-molecules.modal` + `WithToasts`.
- **Assignation par maille** : attacher/détacher des cibles `User` / `UserGroup` / `WorkstationGroup` au pivot `network_share_assignables`, chacune portant `access = ro|rw`. Pivot SQL pur (PAS de CN AD — 34.1 a délibérément tout fait en FK SQL, contrairement à `shortcut_assignables` qui mixe pivot + colonnes `ad_*`).
- **Déclenchement du provisioning** : appel `NetworkShareService::provision($share)` (FS + ACL POSIX) avec retour toast. La visibilité du lecteur sur les postes suit ensuite le flux désir-d'état naturel (l'agent lit `network_shares` au check-in via `DrivesStateProvider`) — l'UI ne « pousse » rien vers l'agent.
- **Validation prédictive** (le cœur de valeur ajoutée 34.2, calquée sur le pattern 30.5) : AVANT écriture/provision, le serveur PRÉDIT et signale (1) une assignation `WorkstationGroup`-seule = visibilité sans accès réel, (2) une lettre explicite réservée (`K/H/I/L/A-D`), (3) une collision de lettre entre deux répertoires distincts visant la même audience (le cas délibérément laissé non géré en 34.1, piège #3).

**Pourquoi maintenant.** La 34.1 a posé le « comment » (FS/ACL/projection). 34.2 pose le « qui pilote » : l'autonomie refnum sans `tinker`, avec les garde-fous UX qui transforment les invariants backend (WG = montage-seul, lettres réservées, collisions) en messages actionnables.

**Ce que cette story N'EST PAS :**
- **Pas de templates de répertoire** (échanges direction / profs / élèves / user↔user / groupes) = recettes d'assignation+ACL préconfigurées = **Story 34.3**. NE PAS les implémenter ici (on livre la création « à la main » d'un répertoire + ses assignations brutes).
- **Pas de commande de resync / réconciliation FS / archivage deux-temps** = **Story 34.x** (`shares:resync-network` calquée `SharesResyncClassCommand`).
- **Pas de refonte de la fondation 34.1** : `NetworkShare`, `NetworkShareAssignable`, `NetworkShareService`, `DrivesStateProvider` sont RÉUTILISÉS tels quels. Seules modifications backend tolérées : (a) exposer `RESERVED_LETTERS` (finding 34.1 #4) ; (b) éventuellement une contrainte de format `directory_name` (finding M4). AUCUNE modif du payload `drives`, du `StateCompiler`, du golden, de l'`agent/**` (pas de bump version agent).
- **Pas de gestion `smb.conf` / `[partages]`** : export SMB = infra serveur hors git (`[PROD]`, déjà cadré en 34.1). L'UI ne crée pas le partage Samba.
- **Pas d'accès AD/LdapRecord** : le pivot est SQL pur (FR7/NFR7, critère Keycloak). Les pickers listent des `User`/`UserGroup`/`WorkstationGroup` SQL.

## ⚠️ Pièges & tensions découverts à l'analyse (lire AVANT de coder)

1. **Piège UX #1 — `WorkstationGroup` = montage-seul = le plus risqué.** Une assignation `WorkstationGroup` rend la lettre VISIBLE sur les postes du parc (via la maille WG du `DrivesStateProvider`) mais ne contribue AUCUNE ACL POSIX (`NetworkShareService::buildAcls` ignore les WG — POSIX ne sait pas exprimer « les users de la machine X »). Sans garde, le refnum croit donner l'accès par le parc → **lecteur visible mais « accès refusé »** → ticket support garanti. **L'UI/validation prédictive DOIT AVERTIR explicitement** : « une assignation parc ne donne que la visibilité de la lettre ; l'accès réel exige un grant utilisateur ou groupe d'utilisateurs ». Si un répertoire a UNIQUEMENT des assignations WG (zéro grant `User`/`UserGroup`), la validation prédictive le signale (warning non bloquant, pas un refus — le montage-seul reste un usage légitime, ex. visibilité d'un répertoire dont l'ACL viendra d'un groupe ajouté plus tard).

2. **Piège UX #2 — lettre auto-assignée = relative au set de session (instable).** En 34.1, l'auto-assignation (`DrivesStateProvider::resolveLetters`, pool `M..Z`, exclut `A,B,C,D,H,I,K,L`) attribue « la 1ère libre dans le MÊME set applicable » → le même répertoire peut tomber sur `M:` pour un user et `N:` pour un autre (review 34.1 M2). **Direction 34.2 : pousser une lettre STABLE par répertoire via l'UI** — proposer/encourager une lettre EXPLICITE à la création (la prochaine lettre sûre libre proposée par défaut), pour que `letter` soit renseigné en DB et donc identique pour tous. La validation prédictive attrape les collisions de lettre explicite. **Tension à arbitrer (Henri, cf. décisions ouvertes)** : 34.2 se contente-t-il d'« encourager l'explicite » côté UI (laissant l'algo provider auto inchangé, la stabilité globale restant 34.x), ou impose-t-il `letter` non-null à la création ? NE PAS modifier l'algorithme d'auto-assignation du provider dans 34.2 sans arbitrage (changer `resolveLetters` = risque golden/contrat).

3. **Collision de lettre = le cas que 34.1 a laissé non géré (piège #3 de 34.1), à fermer ICI.** Le type `drives` est `aggregate` : la dédup du `StateCompiler` collapse les payloads `{letter, unc, label}` IDENTIQUES. Deux répertoires DISTINCTS portant la même lettre pour une même audience produisent deux payloads différents réclamant la même lettre → comportement indéfini côté agent. C'est une **erreur d'authoring** que 34.1 a explicitement délégué à « la validation prédictive d'une story UI ultérieure » = **34.2**. À détecter AVANT écriture (calqué sur `UpstreamLockCollisionDetector` 30.5 : service de pure lecture qui PRÉDIT). NE PAS implémenter une exclusivité-par-lettre côté compilateur/provider (la spécificité par maille ne doit jamais fuiter — D2, `StateCompiler::specificity()` SEUL).

4. **Lettre explicite réservée déjà partiellement gardée en backend, mais silencieusement.** La review 34.1 (#1) a corrigé `resolveLetters` pour qu'une lettre explicite ∈ `RESERVED_LETTERS` (`K/H/I/L/A-D`) soit IGNORÉE + bascule auto (protège le home K: / classes H:). MAIS c'est un garde-fou runtime silencieux (log `agent` warning) — l'admin ne le voit pas. **34.2 doit le rendre VISIBLE à la saisie** : refuser/avertir une lettre réservée dans le formulaire. Ça nécessite d'**exposer `RESERVED_LETTERS`** (aujourd'hui `private const` sur `DrivesStateProvider` — finding 34.1 #4 différé à 34.2). Foyer canonique de cette constante = décision ouverte (cf. ci-dessous).

5. **`directory_name` : unicité en DB OK, format validé seulement au provisioning.** La migration 34.1 pose `unique(directory_name)` mais aucune contrainte de FORMAT en DB/modèle ; `NetworkShareService::isValidDirectoryName` (`^[A-Za-z0-9_-][A-Za-z0-9_.-]*$`) ne s'applique qu'à `provision()` — un `directory_name` malformé peut donc être PERSISTÉ puis échouer silencieusement au provisioning (finding 34.1 M4). **34.2 valide le format au FORMULAIRE** (règle Laravel `regex:` miroir de `isValidDirectoryName`). Une contrainte CHECK en DB est optionnelle (Postgres-only ; mémoire `sqlite_tests_no_varchar_enforcement` : invisible en tests SQLite → ne pas s'y fier comme garde testable).

6. **Pivot SQL pur, PAS de pickers AD.** 34.1 a tout fait en pivot polymorphe SQL (`User`/`UserGroup`/`WorkstationGroup` sont des modèles SQL réels) — contrairement à `shortcut-assignment-modal` qui mixe pivot WG/Workstation + colonnes JSON `ad_users`/`ad_user_groups` (CN AD). **NE PAS copier la partie AD du modal raccourcis.** Les pickers User/UserGroup listent des lignes SQL (`User::query()`, `UserGroup::query()`), pas des CN LDAP. Zéro `LdapRecord`.

7. **Scope refnum : le périmètre délégué scope les `WorkstationGroup`, pas (clairement) les `User`/`UserGroup`.** Le mécanisme de périmètre existant (`WorkstationGroupService::resolveAuthorizedGroupIds(?User)` + `Delegation` scopes + `WorkstationGroupPolicy` Gate) restreint les PARCS visibles. Pour les pickers `User`/`UserGroup`, il n'existe pas (grep) de colonne/scope établissement SQL homogène (la résolution d'établissement vit côté LDAP — `EstablishmentMatcher` — que 34.1 a banni du chemin SQL). **C'est une zone grise** : comment scoper les pickers user/groupe au périmètre du refnum ? Décision ouverte (cf. ci-dessous). Au minimum, la PAGE et le déclenchement sont gardés par `SharePolicy` (Gate), et la liste des répertoires peut être scopée. NE PAS inventer un scope AD dans le chemin SQL.

8. **Provision idempotent + fail-soft, déjà prouvé en 34.1.** L'UI réutilise `provision()` tel quel (retour `bool`, `Log::error` préfixé, `Cache::store('file')->lock()`, audit `quota_audit_logs`). Mapper le `bool` en toast succès/erreur. Une assignation modifiée → re-provision (les ACL sont recalculées : `setfacl -b` wipe puis batch). NE PAS dupliquer la logique de provisioning dans le composant Livewire.

## Décisions de design

> Les décisions « TRANCHÉES » sont appliquées telles quelles par le dev. Les **QUESTIONS OUVERTES** touchent l'UX/architecture et sont laissées à l'arbitrage d'Henri — le dev NE les tranche PAS seul ; il implémente l'option par défaut indiquée et signale l'alternative dans le Dev Agent Record si l'arbitrage n'est pas rendu avant le dev.

### TRANCHÉES (cadrage de la story)

1. **Scope = UI admin + validation prédictive.** PAS de templates (34.3), PAS de resync (34.x). Réutilisation stricte de la fondation 34.1.
2. **Pattern UI = SFC Volt** (`new #[Title(...)] class extends Component` en tête du `.blade.php`), routing filesystem-based sous `resources/views/pages/shares/`, route déclarée explicitement dans `routes/web.php` (`Route::livewire(...)` avec middleware `can:`), **modale réutilisable** `x-molecules.modal`, **`WithToasts`** pour les notifications. Iso page Raccourcis.
3. **Pivot SQL pur** : pickers `User`/`UserGroup`/`WorkstationGroup` SQL, zéro CN AD, zéro LdapRecord.
4. **Validation prédictive = service de PURE LECTURE** calqué sur `UpstreamLockCollisionDetector` (30.5) : prédit, n'écrit rien, retourne des avertissements/erreurs structurés mappés en toasts. Trois règles : (a) WG-montage-seul (warning non bloquant), (b) lettre réservée (erreur bloquante à la saisie), (c) collision de lettre entre répertoires distincts pour une audience commune (erreur bloquante).
5. **Findings 34.1 adressés** : #4 (exposer `RESERVED_LETTERS`), M4 (format `directory_name` au formulaire), M1/M5 (collision de lettre + WG-montage-seul via la validation prédictive).

### ARBITRAGES RENDUS — Henri (2026-06-30, via orchestrateur dev-cycle)

> Ces décisions REMPLACENT les défauts proposés dans « QUESTIONS OUVERTES » ci-dessous. Le dev les applique telles quelles.

- **Q1 (RÉSOLU) → Provisioning SYNCHRONE.** Appel `NetworkShareService::provision($share)` dans l'action Livewire, mappage `bool`→toast. Pas de job.
- **Q2 (RÉSOLU) → Encourager l'explicite.** Le formulaire pré-remplit la prochaine lettre sûre libre (modifiable, peut être vidée → retombe sur l'auto provider). Algo provider `resolveLetters` INCHANGÉ. Stabilité globale auto reste 34.x.
- **Q3 (RÉSOLU) → Accès gardé par policy `admin + refnum` ; pickers NON scopés par établissement en v1.** La feature (page liste, création/édition, assignation, provisioning, suppression) n'est ouverte qu'aux rôles administrateurs et au Référent Numérique (détail des permissions en Q5). Les pickers `User`/`UserGroup`/`WorkstationGroup` ne sont PAS filtrés par établissement en v1 (pas de scope établissement SQL homogène — 34.1 a banni l'AD du chemin SQL) : **dette documentée** dans le code + le runbook QA + une note de la story. NE PAS inventer un scope AD dans le chemin SQL.
- **Q4 (RÉSOLU) → `RESERVED_LETTERS` en `public const` sur `DrivesStateProvider`** (consommée par le validateur 34.2). Pas de nouvelle abstraction (mémoire `no_overengineered_choices`).
- **Q5 (RÉSOLU) → `NetworkSharePolicy` DÉDIÉE + permissions dédiées `networkshare.*`.** NE PAS réutiliser `share.view`/`share.manage` : (a) `ReferentNumerique` n'a aujourd'hui AUCUNE permission `share.*` (il serait exclu), et (b) `share.manage` gouverne aussi les partages de CLASSE → réutiliser sur-octroierait le refnum aux partages de classe. Créer donc :
  - 2 permissions `SambaPermission` : `networkshare.view` + `networkshare.manage` (suivre l'enum `SambaPermission` + le seeding `PermissionSeeder`/`SambaRole::permissions()`).
  - Les ACCORDER dans `app/Enums/SambaRole.php` à : `ReferentNumerique` (view+manage), `ShareAdmin` (view+manage), `UserAdmin` (view+manage), `SuperAdmin` (auto via `SambaPermission::cases()`). **Pas** `Prof`/`Eleve`/`EleveAdmin`/`Technicien`/`ComputerAdmin`.
  - `app/Policies/NetworkSharePolicy.php` (gates `viewAny-networkshare`/`view-networkshare` = `networkshare.view` ; `manage-networkshare` = `networkshare.manage`), calquée sur `SharePolicy` (traits `RegistersGates`/`ChecksPermissions`). Route + actions gardées (`middleware can:` + `$this->authorize`/`@can`).
  - Signaler à Henri qu'un (re)seed des permissions/rôles (`PermissionSeeder`) sera nécessaire sur la VM pour matérialiser les nouvelles permissions.

### QUESTIONS OUVERTES — arbitrage Henri (UX/architecture) [RÉSOLUES ci-dessus — conservées pour traçabilité]

- **Q1 — Déclenchement du provisioning : synchrone (toast immédiat) ou via job ?** L'opération est légère et déjà `fail-soft`/`lock`/idempotente (34.1) ; le pattern partages de CLASSE (`ShareService`) provisionne de façon synchrone. **Défaut proposé : synchrone** (appel `provision()` dans l'action Livewire, mappage `bool`→toast). Alternative : dispatch d'un job (utile seulement si beaucoup d'ACL — pertinent plutôt en 34.3 templates). *Le dev implémente le synchrone sauf arbitrage contraire.*
- **Q2 — Stabilité de la lettre (cf. piège #2) : « encourager l'explicite » ou « imposer l'explicite » à la création ?** **Défaut proposé : encourager** — le formulaire pré-remplit la prochaine lettre sûre libre comme valeur par défaut (modifiable, peut être vidée → retombe sur l'auto provider). On NE modifie PAS l'algorithme provider (stabilité globale auto = 34.x). Alternative : rendre `letter` obligatoire (champ requis). *À arbitrer car ça change l'ergonomie et le sens du « auto ».*
- **Q3 — Périmètre des pickers user/groupe (cf. piège #7).** Comment scoper les `User`/`UserGroup` proposés au refnum ? **Défaut proposé : page + actions gardées par `SharePolicy` (Gate), liste des répertoires non filtrée par établissement en v1 (déploiements mono-établissement), pickers user/groupe non scopés** — avec une note de dette explicite. Alternative : dériver un périmètre SQL (si une colonne établissement existe sur `User`/`UserGroup`) ou réutiliser `resolveAuthorizedGroupIds` pour les WG seulement. *Zone grise réelle : 34.1 a banni l'AD du chemin SQL et il n'y a pas de scope établissement SQL homogène sur les users.*
- **Q4 — Foyer canonique de `RESERVED_LETTERS` (finding 34.1 #4).** **Défaut proposé : passer la `private const RESERVED_LETTERS` de `DrivesStateProvider` en `public const`** et la consommer depuis le validateur 34.2 (minimal, pas de nouvelle abstraction — mémoire `no_overengineered_choices`). Alternative : extraire dans une config (`config/filesystem.php`) ou un enum partagé si un 3ᵉ consommateur émerge. *Le dev applique le défaut sauf arbitrage.*
- **Q5 — Abilities de policy : réutiliser `share.view`/`share.manage` (`SharePolicy`) ou dédier `networkshare.*` ?** `SharePolicy` (`share.view`, `share.manage`) existe et la 34.1 la citait comme « pertinente en 34.2 ». **Défaut proposé : réutiliser `share.view` (liste/consultation) + `share.manage` (créer/éditer/assigner/provisionner)** — `SharePolicy::manage` accepte déjà un 2ᵉ argument optionnel (signature `manage(?Authenticatable, ?UserGroup)`), à étendre/contourner pour les network shares. Alternative : créer une `NetworkSharePolicy` + permissions Spatie dédiées (plus propre sémantiquement, plus de surface). *À arbitrer : sémantique vs friction.*

## Acceptance Criteria

### AC1 — Page liste des répertoires réseau (route + scope + policy)

**Given** un refnum disposant de la permission `share.view`
**When** il navigue vers `/app/shares` (route `Route::livewire('/shares', 'pages::shares.index')->name('shares')`, middleware `can:share.view` ou équivalent)
**Then** une page SFC liste les `NetworkShare` (colonnes : nom, `directory_name`, lettre effective, nb d'assignations par maille, accès dominant) avec recherche (`#[Url]`), pagination (`x-molecules.pagination`) et état vide, via `x-organisms.page` + `x-organisms.data-table` (iso page Raccourcis)
**And** le bouton « Nouveau répertoire » n'apparaît que sous `@can('manage-share')` (ou ability arbitrée Q5)
**And** l'accès est refusé (403 / toast) sans la permission ; la liste respecte le périmètre du refnum selon l'arbitrage Q3 (défaut : non filtrée, page gardée par Gate).

### AC2 — Création d'un répertoire (formulaire validé, findings M4/#4 adressés)

**Given** un refnum avec `share.manage`
**When** il crée un répertoire via la modale réutilisable `x-molecules.modal` (champs : `name` requis, `directory_name` requis, `label` optionnel, `letter` optionnelle)
**Then** `directory_name` est validé au FORMAT (`regex:/^[A-Za-z0-9_-][A-Za-z0-9_.-]*$/` miroir de `NetworkShareService::isValidDirectoryName`) ET en UNICITÉ (`unique:network_shares,directory_name`) — finding M4
**And** si une `letter` explicite est saisie, elle est rejetée au formulaire si elle ∈ lettres réservées (`K/H/I/L/A-D`, consommées depuis `RESERVED_LETTERS` exposée — finding #4) avec un message clair (« lettre réservée par le système »)
**And** à la soumission valide, une ligne `NetworkShare` est créée (`created_by_user_id` = refnum courant) puis `NetworkShareService::provision()` est appelé (Q1 défaut synchrone), le résultat `bool` mappé en toast succès/erreur via `WithToasts`
**And** la lettre par défaut proposée au formulaire est la prochaine lettre sûre libre (Q2 défaut « encourager l'explicite »).

### AC3 — Édition + assignation par maille (pivot SQL, RO/RW)

**Given** un répertoire existant
**When** le refnum ouvre l'édition (page `/app/shares/{id}` ou modale) et gère les assignations
**Then** il peut attacher/détacher des cibles `User`, `UserGroup`, `WorkstationGroup` (pickers SQL, zéro CN AD) au pivot `network_share_assignables`, chaque assignation portant `access = ro|rw` (défaut `ro`)
**And** l'unicité du pivot est respectée (`unique(network_share_id, assignable_id, assignable_type)` — pas de doublon ; un changement d'`access` met à jour la ligne existante)
**And** seuls les 3 types de `NetworkShare::ALLOWED_ASSIGNABLE_TYPES` sont acceptés (validé applicativement)
**And** toute modification d'assignation déclenche une re-provision (`provision()` recalcule les ACL : WG = aucune ACL, user/group = `rx`/`rwx`) avec toast
**And** l'édition de `name`/`label`/`letter` est possible (mêmes validations qu'AC2).

### AC4 — Validation prédictive : WG-montage-seul + collision de lettre (pièges #1/#3, findings M1/M5)

**Given** un répertoire et ses assignations en cours d'édition
**When** le refnum sauvegarde/provisionne
**Then** un service de PURE LECTURE (calqué `UpstreamLockCollisionDetector` 30.5, ZÉRO écriture, ZÉRO candidat émis) prédit et signale :
  - **(a) WG-montage-seul** : si le répertoire a au moins une assignation `WorkstationGroup` ET aucun grant `User`/`UserGroup`, un **warning non bloquant** (« assignation parc = visibilité seule ; l'accès réel exige un grant utilisateur/groupe ») via `toastWarning`
  - **(b) collision de lettre** : si deux répertoires DISTINCTS visent la MÊME lettre pour une audience qui se recouvre (au moins une maille commune résolvant les deux), une **erreur bloquante** (refus + `toastError` détaillant les deux répertoires et la lettre) AVANT écriture/provision — le cas piège #3 de 34.1
  - **(c) lettre réservée** : déjà attrapé au formulaire (AC2) ; le détecteur le re-confirme defense-in-depth
**And** le détecteur n'introduit AUCUNE précédence ni modification du `StateCompiler`/`DrivesStateProvider` (D2 confiné) ; il LIT `network_shares` + pivot (Postgres) et `RESERVED_LETTERS`
**And** un répertoire WG-seul reste CRÉABLE (warning, pas refus) ; une collision de lettre est REFUSÉE (erreur).

### AC5 — Suppression d'un répertoire / retrait d'assignation

**Given** un répertoire existant
**When** le refnum supprime le répertoire (ou retire une assignation)
**Then** la suppression du `NetworkShare` cascade le pivot (`onDelete cascade` 34.1) ; un retrait d'assignation supprime la ligne pivot et re-provisionne (ACL recalculées)
**And** l'action est gardée par policy (`share.manage`/Q5), confirmée (`wire:confirm`), et notifiée par toast
**And** (note) la story NE supprime PAS le répertoire FS sous `/var/sambaedu/Partages` (suppression FS / archivage deux-temps = 34.x) — documenté comme limitation ; seules les lignes SQL + ACL sont gérées ici. *(Si une suppression FS minimale est souhaitée, c'est un point d'arbitrage — sinon laisser au 34.x.)*

### AC6 — Tests (HÔTE php8.4 + sqlite, filtres ciblés)

**Then** tests Livewire de la page (création valide/invalide, format `directory_name`, lettre réservée refusée, assignation par maille, RO/RW, toasts) via `Livewire::test(...)`
**And** tests unitaires du **détecteur de validation prédictive** : WG-montage-seul (warning), collision de lettre (erreur), pas de faux positif (répertoires à lettres distinctes / audiences disjointes), lettre réservée
**And** test que `RESERVED_LETTERS` exposée == l'ensemble consommé par le provider (non-régression de la source unique)
**And** **non-régression GARANTIE** : `--filter DrivesStateProvider`, `--filter ContractV1` (golden `state.v1.json` + `FROZEN_STATE_HASH` PHP/Go **INCHANGÉS** — l'UI ne touche pas le payload), `--filter NetworkShare`, `--filter Agent` verts ; baseline relevée AVANT (mémoire `vm_phpunit_bulk_run_false_failures` : filtres ciblés, jamais run massif VM) ; sur l'HÔTE (mémoire `phpunit_test_env_host_vs_vm`).

### AC7 — Documentation + backlog (append-only)

**Then** la doc QA `docs/qa/domains/filesystem.md` est enrichie (Story 34.2, scénarios UI + validation prédictive) ; `docs/agent/state-providers.md` reste cohérente (note que la lettre stable est encouragée par l'UI)
**And** `_bmad-output/backlog.data.js` : story `34-2` ajoutée dans l'Epic 34 (status suivi via `sprint-status.yaml`) ; les fichiers backlog committés ensemble (mémoire `backlog_split_multifile`)
**And** restent **INTOUCHÉS** : `DrivesStateProvider` payload/algorithme auto (hors exposition `RESERVED_LETTERS`), `NetworkShareService::provision` (réutilisé tel quel), `StateCompiler`, golden `state.v1.json`, `FROZEN_STATE_HASH` PHP/Go, `agent/**` (pas de bump version agent), `contract-v1.md §7`, canal legacy partages, `ShareService`.

## Tasks / Subtasks

- [x] **T1 — Exposer `RESERVED_LETTERS` + format `directory_name`** (AC2, AC4 ; findings #4/M4)
  - [x] `DrivesStateProvider::RESERVED_LETTERS` : `private const` → `public const` (Q4) ; aucun autre changement du provider (payload/algo intacts). `--filter DrivesStateProvider` (22) + `--filter ContractV1` (5/104) verts, golden inchangé.
  - [x] Source unique de format : `NetworkShareService::DIRECTORY_NAME_PATTERN` (const publique) consommée à la fois par `isValidDirectoryName()` ET par la règle `regex:` du formulaire (finding M4). Pas de CHECK DB (Postgres-only, non testable SQLite — mémoire `sqlite_tests_no_varchar_enforcement`).

- [x] **T2 — Routing + page liste** (AC1)
  - [x] `resources/views/pages/shares/index.blade.php` (SFC Volt `new #[Title] class extends Component { use WithToasts; ... }`) : liste paginée, recherche `#[Url]`, état vide, `x-organisms.page` + `x-organisms.data-table` + `x-molecules.pagination`.
  - [x] Routes dans `routes/web.php` (groupe `app`) : `/shares` + `/shares/{id}`, middleware `can:networkshare.view` (Q5). Création par modale sur l'index (pas de page `/new` dédiée).
  - [x] Scope Q3 (défaut) : page gardée par Gate `networkshare.view`, liste non filtrée par établissement. Dette pickers documentée (code + QA + Dev Notes).

- [x] **T3 — Création/édition + modale réutilisable** (AC2, AC3)
  - [x] Modale de création `x-molecules.modal` + `x-molecules.modal.section` (sur l'index, `wire:model="isCreateOpen"`) : `name`, `directory_name` (format+unique), `label`, `letter` (pré-rempli prochaine lettre sûre libre, refus si réservée).
  - [x] Page d'édition `/app/shares/{id}` : édition des champs (mêmes validations + collision) + section assignations.
  - [x] Action de création : insert `NetworkShare` (`created_by_user_id`) → validation prédictive (T5) → `provision()` synchrone (Q1) → toast.

- [x] **T4 — Assignation par maille (pivot SQL, RO/RW)** (AC3)
  - [x] Section d'assignation sur `/app/shares/{id}` : pickers SQL `User`/`UserGroup`/`WorkstationGroup` (PAS de CN AD) ; attacher `access=ro|rw`, changer l'accès, détacher ; upsert via `updateOrCreate` (unicité du pivot respectée).
  - [x] Type validé ∈ `NetworkShare::ALLOWED_ASSIGNABLE_TYPES` (`NetworkShareValidator::isAllowedAssignableType`). WG = `access` grisé (montage-seul).
  - [x] Re-provision (`provision()`) après toute mutation d'assignation.

- [x] **T5 — Validation prédictive (service pure lecture)** (AC4 ; pièges #1/#3, findings M1/M5)
  - [x] `app/Services/Filesystem/NetworkShareValidator.php` (pure lecture, calqué `UpstreamLockCollisionDetector`) : `warnings()`, `letterCollisions()`, `assertNoLetterCollision()`, `isReservedLetter()`, `suggestNextFreeLetter()`. Consomme `DrivesStateProvider::RESERVED_LETTERS` + pivot (Postgres).
  - [x] (a) WG-montage-seul → warning ; (b) collision de lettre (répertoires distincts, lettre explicite identique, audience recouvrante) → `NetworkShareLetterCollisionException` (msg FR toast, iso `UpstreamLockCollisionException::fromCollisions`) ; (c) lettre réservée → erreur.
  - [x] Invocation Livewire : `try { assertNoLetterCollision } catch { toastError }` + `toastWarning` pour les warnings non bloquants.

- [x] **T6 — Suppression + retrait** (AC5)
  - [x] Suppression `NetworkShare` (cascade pivot), `wire:confirm`, policy, redirect + flash toast. Retrait d'assignation + re-provision. FS NON supprimé (documenté, 34.x).

- [x] **T7 — Tests + doc + backlog** (AC6, AC7)
  - [x] Tests Livewire (index + détail) + Unit validateur + source-unique `RESERVED_LETTERS` + policy. Baselines AVANT/APRÈS ciblées (DrivesStateProvider/ContractV1/NetworkShare/Agent), HÔTE — golden inchangé.
  - [x] `docs/qa/domains/filesystem.md` (Story 34.2, scénarios 34.2-1..9), `_bmad-output/backlog.data.js` + `sprint-status.yaml` (34-2 → review).
  - [x] Golden `state.v1.json` / `FROZEN_STATE_HASH` / `agent/**` / `contract-v1.md §7` / `StateCompiler` / `ShareService` INTOUCHÉS (git status vérifié).

## Dev Notes

### Patterns à RÉUTILISER (chemins réels — ne pas réinventer)

- **Page CRUD + assignation par maille (gabarit canonique)** : `resources/views/pages/shortcuts/` — `index.blade.php` (liste, filtres `#[Url]`, pagination, bulk), `new/index.blade.php`, `[id]/index.blade.php`, `[id]/_partials/assigned-groups.blade.php` (monte la modale d'assignation). **ATTENTION** : le modal raccourcis mixe pivot WG/Workstation + CN AD (`ad_users`/`ad_user_groups`) — NE PAS reprendre la partie AD ; 34.2 est pivot SQL pur.
- **SFC Volt** : déclaration `new #[Title('…')] class extends Component { use WithToasts; #[Url] public string $search=''; ... }` en tête du `.blade.php` ; composant réutilisable monté via `<livewire:organisms.xxx />` avec `new class extends Component { public bool $isOpen=false; #[On('open-xxx-modal')] ... }`. Pas de dossier `resources/views/livewire/`.
- **Modale réutilisable** : `resources/views/components/molecules/modal/index.blade.php` (+ `section.blade.php`). Props : `title, subtitle, icon, size, height, closeMethod (def. 'close'), noScroll` ; slots `titleIcon, titleComplement, headerAction, header, footer, footerNote`. Usage : `<x-molecules.modal wire:model="isOpen" title="…"> <x-molecules.modal.section title="…">…</x-molecules.modal.section> <x-slot:footer>…</x-slot:footer> </x-molecules.modal>`. Ouverture distante : `dispatch('open-xxx-modal')` + `#[On('open-xxx-modal')]`. Exemple : `resources/views/pages/users/_partials/delegation-modal.blade.php`.
- **Notifications** : `app/Components/Traits/WithToasts.php` — `toast(status,title,message)`, `toastSuccess/Error/Warning/Info(message, title?)`, `toastAccessDenied()`, `toastSuccessWithActions(...)`. **Survie à un redirect** : `session()->flash('toast', [...])` (cf. `pages/parc/groups/new/index.blade.php`).
- **Routing filesystem-based** : routes déclarées EXPLICITEMENT dans `routes/web.php` (`Route::livewire('/shortcuts', 'pages::shortcuts.index')->name('shortcuts')`, l. ~138-140, groupe préfixé `app`) — il faut AJOUTER les routes `shares`.
- **Validation prédictive (gabarit 30.5)** : `app/Services/ControlHub/Resolution/UpstreamLockCollisionDetector.php` (pure lecture, court-circuit `hasLockedLabelItems()`, `collisionsFromFinalState(...)`), DTO `.../UpstreamLockCollision.php`, exception `app/Exceptions/ControlHub/UpstreamLockCollisionException.php` (`fromCollisions()`), invocation `WorkstationGroupService` (~L1088-1127), catch→toast dans `pages/parc/groups/{new,[id],[id]/edit}/index.blade.php`.
- **Scope périmètre / délégation refnum** : `app/Services/Parc/WorkstationGroupService.php::resolveAuthorizedGroupIds(?User)` (null=pas de filtre ; sinon `Delegation::forUser()->forPermission('computer.view')->negative()->active()` + `PermissionService::getAuthorizedWorkstationGroups()`), scopes `app/Models/Delegation.php` (`forUser/forWorkstationGroup/forPermission/positive/negative/active`), Gate `app/Policies/WorkstationGroupPolicy.php` (`Gate::allows('update-workstationGroup',$group)`), helper page `scopedUser()` (cf. `pages/parc/index.blade.php`). **Scope ÉTABLISSEMENT SQL homogène pour User/UserGroup : INEXISTANT** (la résolution établissement vit côté LDAP `app/Services/Ldap/EstablishmentMatcher.php`, banni du chemin SQL 34.1) → cf. Q3.
- **Policy partages** : `app/Policies/SharePolicy.php` — abilities `share.view` (viewAny/view), `share.refresh`, `share.manage` (signature `manage(?Authenticatable, ?UserGroup)`). Réutilisables (Q5).

### Fondation 34.1 à consommer (NE PAS modifier, sauf `RESERVED_LETTERS`)

- `app/Models/NetworkShare.php` : `assignments()` (hasMany pivot), `users()/userGroups()/workstationGroups()` (morphedByMany `withPivot('access')`), `effectiveLabel()`, `ALLOWED_ASSIGNABLE_TYPES`, `TYPE_DRIVES`.
- `app/Models/NetworkShareAssignable.php` : `ACCESS_RO`/`ACCESS_RW`, `isWritable()`, `assignable()` morphTo.
- `app/Services/Filesystem/NetworkShareService.php` : `provision($share, ?performedBy): bool` (idempotent, fail-soft, lock `Cache::store('file')`, audit `quota_audit_logs`), `getStatus($share)`, `isValidDirectoryName()`, `buildAcls()` (WG ignoré). **Réutiliser `isValidDirectoryName` comme source de vérité du format** (la règle de form regex doit la refléter 1:1).
- `app/Services/Agent/Providers/DrivesStateProvider.php` : `RESERVED_LETTERS = ['A','B','C','D','H','I','K','L']`, `LETTER_POOL = M..Z`, `resolveLetters()` (lettre explicite réservée → ignorée+auto ; pool épuisé → omis+warn). **Exposer la const, ne PAS toucher le reste.**
- `config/filesystem.php` : `shares_root` (= `/var/sambaedu/Partages`).
- Migrations 34.1 : `network_shares` (`unique(directory_name)`), `network_share_assignables` (`morphs`, `unique(network_share_id, assignable_id, assignable_type)`, FK cascade).

### Contraintes d'environnement (mémoires)

- **Tests sur l'HÔTE** (php8.4 + pdo_sqlite ; VM sans pdo_sqlite) — `phpunit_test_env_host_vs_vm`. **Filtres ciblés, jamais run massif VM** — `vm_phpunit_bulk_run_false_failures`.
- **Worktree** : ne PAS interagir avec la VM/serveurs depuis ce worktree — `feedback_worktree_no_vm_sync` ; le code est sync via inotify (ne pas sync manuellement).
- **PHP-FPM = www-admin** : `provision()` pose déjà `chown www-admin` — `php_fpm_user_www_admin`.
- **`Cache::lock()` + APCu** : `provision()` utilise déjà `Cache::store('file')->lock()` — `apcu_cache_no_lock`. Si un nouveau lock est nécessaire, même règle.
- **Racine projet = Laravel** (`artisan`/`app/` à la racine, pas `laravel/*`) — `root_is_laravel`.
- **Livewire** : JAMAIS d'action nommée `upload` (réservée, casse `TempUploadedFile`) — `livewire_reserved_upload_method`. (Pas d'upload ici, mais garde-fou.)
- **`routes/api.php`** : sans objet (UI web) ; les nouvelles routes vont dans `routes/web.php` groupe `app`.
- **Migrations VM pas auto-jouées** : sans objet en worktree (pas de VM) ; si migration additive Q-CHECK ajoutée, `migrate:status` avant tout e2e côté VM (hors worktree) — `vm_migrations_not_auto_applied`.

### [PROD] — Infra serveur (hors git, rappel 34.1)

- Le RO/RW réel dépend d'un `smb.conf` `[partages]` → `/var/sambaedu/Partages` que SE5 NE gère PAS (hors git, iso `[users]`/`[classes]`). L'ACL POSIX n'est honorée côté Windows que si l'export est bien configuré (héritage ACL, `vfs_acl_xattr`). **Cadrage** : on ne « se passe » PAS de SMB (transport conservé, UNC `\\<se4fs>\partages\<directory_name>\`) — on remplace le mapping GPO/AD par l'agent natif. L'UI 34.2 ne touche pas `smb.conf`.
- Sudoers déjà couvert (34.1) : `setfacl/getfacl/mkdir/mv/chown/chgrp` whitelistés par binaire.

### Project Structure Notes

- Pages : `resources/views/pages/shares/index.blade.php`, `shares/new/index.blade.php` (si dédiée), `shares/[id]/index.blade.php` + `shares/[id]/_partials/`.
- Composant d'assignation : `resources/views/components/organisms/share-assignment-modal.blade.php` (SFC réutilisable, iso `shortcut-assignment-modal`).
- Service de validation : `app/Services/Filesystem/NetworkShareValidator.php` (ou `app/Services/Drives/...`), exception `app/Exceptions/Filesystem/NetworkShareLetterCollisionException.php`.
- Routes : `routes/web.php` (groupe `app`, à côté des routes `shortcuts`).
- Tests : `tests/Feature/Livewire/Shares/*` (Livewire), `tests/Unit/Services/Filesystem/NetworkShareValidatorTest.php`.

### References

- [Source: _bmad-output/implementation-artifacts/34-1-fondations-lecteurs-reseau-geres.md] — fondation backend (modèle, service, provider), modèle d'accès 2 axes, pièges #3 (collision) / #4 (lettres réservées), section « Decompose » cadrant 34.2/34.3/34.x.
- [Source: _bmad-output/codeReviews/34-1.md] — findings différés à 34.2 : #4 (exposer `RESERVED_LETTERS`), M4 (format `directory_name`), M1 (collision 2-répertoires lettre explicite → validation prédictive), M5 (WG-montage-seul → surfacer en UI), M2 (stabilité lettre auto → 34.x).
- [Source: app/Services/Agent/Providers/DrivesStateProvider.php] — `RESERVED_LETTERS`, `LETTER_POOL`, `resolveLetters` (garde lettre réservée + pool épuisé).
- [Source: app/Services/Filesystem/NetworkShareService.php] — `provision`, `isValidDirectoryName`, `buildAcls` (WG ignoré), `getStatus`.
- [Source: app/Models/NetworkShare.php + NetworkShareAssignable.php] — relations morphedByMany withPivot('access'), `ALLOWED_ASSIGNABLE_TYPES`, `isWritable`.
- [Source: resources/views/pages/shortcuts/] — gabarit page CRUD + assignation par maille (hors partie AD).
- [Source: resources/views/components/molecules/modal/index.blade.php] — modale réutilisable, signature + déclenchement.
- [Source: app/Components/Traits/WithToasts.php] — méthodes de toast.
- [Source: app/Services/ControlHub/Resolution/UpstreamLockCollisionDetector.php + UpstreamLockCollisionException.php] — gabarit validation prédictive pure lecture + catch→toast (story 30.5).
- [Source: app/Services/Parc/WorkstationGroupService.php::resolveAuthorizedGroupIds + app/Models/Delegation.php + app/Policies/WorkstationGroupPolicy.php] — scope périmètre délégué (WG).
- [Source: app/Policies/SharePolicy.php] — abilities `share.view`/`share.manage`.
- [Source: memory/project_network_shares_342_design_traps.md] — WG=montage-seul + lettre stable (les 2 pièges 34.2).
- [Source: memory/project_native_drive_management_direction.md] — direction native, golden figé, lettres K/H/I/L.

## Dev Agent Record

### Agent Model Used

Opus 4.8 (claude-opus-4-8[1m]).

### Debug Log References

Baselines HÔTE (php8.4.5 + pdo_sqlite, filtres ciblés) :

| Filtre | AVANT | APRÈS |
|---|---|---|
| `--filter ContractV1` | 5 passed (104) | 5 passed (104) — golden **inchangé** |
| `--filter DrivesStateProvider` | 22 passed (53) | 22 passed (53) — inchangé |
| `--filter NetworkShare` | 21 passed (56) | 37 passed (126) — +16 (validateur) |
| `--filter Agent` | 540 passed, 22 skipped (1868) | 540 passed, 22 skipped (1868) — inchangé |

Tests net-new 34.2 (31) : `NetworkShareValidatorTest` 12, `NetworkSharePolicyTest` 4,
`SharesIndexTest` 7, `ShareDetailTest` 8 — tous verts.
Non-régression permissions/rôles (enum touché) : `PermissionSeeder|SambaPermission|RoleManagement|RightsServiceSpatie|RightsMigration|FederatedRoleMapper|RightsDrawerSpatie|PermissionServiceUnion|SharePolicyTest`
= 81 passed (238). `RoutesProtection` = 28 passed.

### Completion Notes List

- **Q1-Q5 appliqués tels quels** (arbitrages Henri 2026-06-30) : provisioning
  synchrone ; lettre explicite encouragée (pré-remplissage prochaine libre) ;
  accès gardé `networkshare.*`, pickers non scopés par établissement (dette
  documentée) ; `RESERVED_LETTERS` `public const` ; **policy + permissions
  dédiées** `networkshare.view`/`networkshare.manage` accordées à
  `ReferentNumerique` + `ShareAdmin` + `UserAdmin` (+ `SuperAdmin` auto).
- **Permissions SE5-natives** : `networkshare.*` mappées sur le bit représentatif
  `SE_SHARE_REFRESH` UNIQUEMENT pour le `match` exhaustif de `legacyRight()`, et
  marquées `isSecondaryBitPermission()` → JAMAIS sur-attribuées par un import
  bitmask (octroi explicite par rôle). Test prouve : refnum a `networkshare.manage`
  mais PAS `share.manage`/`share.view` (séparation respectée).
- **(re)seed VM requis** : exécuter `php artisan db:seed --class=PermissionSeeder`
  sur la VM pour matérialiser les 2 nouvelles permissions et les rattacher aux
  rôles seedés. Tant que non joué, `/app/shares` renvoie 403 même pour un refnum
  (signalé dans le runbook QA).
- **Validation prédictive** (`NetworkShareValidator`, pure lecture) : WG-montage-seul
  = warning non bloquant ; collision de lettre explicite + audience recouvrante =
  `NetworkShareLetterCollisionException` bloquante (refus AVANT écriture, lettre non
  persistée) ; lettre réservée = erreur de formulaire. Le détecteur N'ÉCRIT RIEN,
  n'émet aucun candidat, ne touche ni `StateCompiler` ni le provider.
- **Garde-fous critiques vérifiés** : golden `state.v1.json` + `FROZEN_STATE_HASH`
  (PHP `ContractV1Test`) **inchangés** ; `agent/**` intouché (pas de bump version
  agent) ; `StateCompiler`, `contract-v1.md §7`, `ShareService`,
  `NetworkShareService::provision/buildAcls` non modifiés ; zéro AD/LdapRecord/APCu
  (pickers = `User`/`UserGroup`/`WorkstationGroup` SQL). Seules modifs provider/service :
  `RESERVED_LETTERS` `private`→`public` et ajout const `DIRECTORY_NAME_PATTERN`
  (refactor `isValidDirectoryName` sans changement de comportement).
- **Écarts/ambiguïtés tranchés** : (1) création via modale sur l'index (pas de page
  `/shares/new` dédiée) — AC2 exige la modale, route `/new` rendue inutile ;
  (2) collision de lettre définie sur les lettres EXPLICITES + recouvrement
  d'audience (≥ 1 cible commune toutes mailles) — les lettres auto (null) sont
  non prédictibles donc exclues (conforme M1/piège #3) ; (3) recherches SQL en
  `LOWER(...) LIKE ?` pour rester cross-DB (SQLite hôte / Postgres prod), pas de
  `ILIKE`.

### File List

**Créés :**
- `app/Policies/NetworkSharePolicy.php`
- `app/Services/Filesystem/NetworkShareValidator.php`
- `app/Exceptions/Filesystem/NetworkShareLetterCollisionException.php`
- `resources/views/pages/shares/index.blade.php`
- `resources/views/pages/shares/[id]/index.blade.php`
- `tests/Unit/Services/Filesystem/NetworkShareValidatorTest.php`
- `tests/Feature/Policies/NetworkSharePolicyTest.php`
- `tests/Feature/Livewire/Shares/SharesIndexTest.php`
- `tests/Feature/Livewire/Shares/ShareDetailTest.php`

**Modifiés :**
- `app/Services/Agent/Providers/DrivesStateProvider.php` (`RESERVED_LETTERS` `private`→`public const` — seule modif)
- `app/Services/Filesystem/NetworkShareService.php` (ajout `public const DIRECTORY_NAME_PATTERN` + `isValidDirectoryName` la référence ; `provision`/`buildAcls` inchangés)
- `app/Enums/SambaPermission.php` (cases `NetworkShareView`/`NetworkShareManage` + label/category/legacyRight/isSecondaryBitPermission)
- `app/Enums/SambaRole.php` (octroi `networkshare.*` à `ReferentNumerique`/`ShareAdmin`/`UserAdmin`)
- `app/Providers/AuthServiceProvider.php` (enregistrement `NetworkSharePolicy::registerGates()`)
- `routes/web.php` (routes `/shares` + `/shares/{id}`, `can:networkshare.view`)
- `docs/qa/domains/filesystem.md` (section Story 34.2, scénarios 34.2-1..9, dette)
- `_bmad-output/implementation-artifacts/sprint-status.yaml` (34-2 → review)
- `_bmad-output/backlog.data.js` (34-2 → review)

### Change Log

- 2026-06-30 — Implémentation Story 34.2 (UI admin lecteurs réseau gérés +
  validation prédictive), status `ready-for-dev` → `review`. DEV Opus 4.8.

## Recommandation Modèle Dev

**Reco : `opus`.**

À première vue, 34.2 ressemble à de l'UI/CRUD Livewire « sonnet-friendly » (page liste, modale, formulaire, toasts — tous des patterns établis et copiables depuis la page Raccourcis). Mais le pour/contre penche vers **opus** pour trois raisons de fond :

1. **La valeur ajoutée réelle n'est pas le CRUD, c'est la validation prédictive** — un service de raisonnement (collision de lettre entre répertoires à audiences recouvrantes ; WG-montage-seul vs grant réel ; lettre réservée). C'est le cas que 34.1 a DÉLIBÉRÉMENT laissé ouvert, calqué sur la mécanique non triviale de 30.5 (`UpstreamLockCollisionDetector`). Mal fait, ça produit des faux positifs qui paralysent l'admin ou des faux négatifs qui laissent passer le bug exact qu'on veut prévenir.
2. **Le contrat agent figé est un champ de mines adjacent** : exposer `RESERVED_LETTERS` et toucher au voisinage du `DrivesStateProvider` sans casser le golden/`FROZEN_STATE_HASH`/payload exige le jugement « ce qu'il ne faut PAS toucher » — précisément ce qui a fait recommander opus en 34.1.
3. **Plusieurs décisions de design ouvertes** (Q1-Q5 : sync/job, stabilité de lettre, scope refnum en zone grise SQL/AD, foyer de constante, sémantique de policy) demandent un dev capable de tenir l'option par défaut tout en signalant proprement les alternatives, sans sur-concevoir (mémoire `no_overengineered_choices`).

Le CRUD pur serait sonnet ; la validation prédictive + la préservation du contrat figé + les arbitrages ouverts font basculer en **opus**.

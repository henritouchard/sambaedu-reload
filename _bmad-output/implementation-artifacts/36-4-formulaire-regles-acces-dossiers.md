# Story 36.4 : Règles d'accès aux dossiers — la fonctionnalité à formulaire (D8)

Status: review

<!-- Source d'autorité : _bmad-output/planning-artifacts/epics-mecanismes-hors-registre.md
     (Story 36.4 + D8 + garde-fous d'epic — Epic 36 ne figure PAS dans epics.md).
     Décisions Henri actées : _bmad-output/ultradev/36-questions.md (Q1 jetons en dur,
     Q2 racines protégées telles quelles) — la validation d'authoring de 36.4 RÉUTILISE
     celle de 36.1. Mécanisme réutilisé : 36-1-mecanisme-fs-acl.md (LIVRÉ, mergé sur
     epic-36) + codeReviews/36-1.md (leçon #2b : guard à appeler EXPLICITEMENT).
     Patron structurel : Epic 34 (34-1/34-2/34-3 — lecteurs réseau gérés). -->

## Story

En tant que **référent numérique**,
je veux **créer moi-même une règle « interdire/autoriser CE dossier à CE groupe » via un formulaire**,
afin de **couvrir les besoins locaux imprévus sans attendre une capacité catalogue**.

## Contexte & intention

**Seconde surface d'authoring du mécanisme `fs_acl` (D8).** La 36.1 a payé le mécanisme
UNE fois (contrat additif `fs_acl` 6 clés, provider capacités, `FsAclAuthoringGuard`,
handler Go chirurgical + store « dernier appliqué », agent 2.6.0). La 36.4 ouvre la
surface INSTANCIABLE : le refnum crée des règles illimitées (« interdire CE dossier à
CE groupe ») via un formulaire 100 % métier. Règle de partage D8 : intention figée →
capacité (catalogue) ; objet instancié par l'admin → feature avec formulaire (ICI).

**Ce que la story livre** — le calque EXACT de l'Epic 34 (lecteurs réseau : le canal
`drives` est déjà bi-alimenté — logique figée K:/H: + table `network_shares` créée en
UI), transposé au canal `fs_acl` :

1. **Table `folder_access_rules`** (`{path, user_group_id FK, ace_type, rights,
   applies_to, label, is_active, created_by_user_id}`) + **pivot polymorphe
   `folder_access_rule_assignables`** (calque `network_share_assignables` — v1 :
   `WorkstationGroup` seul autorisé, extensible SANS migration).
2. **Service `FolderAccessRuleService`** (create/update/toggle/delete) qui appelle
   **EXPLICITEMENT** `FsAclAuthoringGuard::violations()` (leçon review 36.1 #2b : l'observer
   `CapabilityProjectionObserver` ne couvre QUE `capability_projections` — les règles
   vivent dans une table dédiée, AUCUN filet automatique) + **audit append-only** de
   chaque create/update/delete (`FolderAccessRuleAuditLog`, patron `CapabilityOverrideAuditLog::log()`).
3. **Provider `FolderAccessRulesStateProvider`** (portée Machine, type `fs_acl`, lecture
   Postgres pure) : chaque règle active assignée à un parc du poste émet un item `fs_acl`
   6 clés à la maille du parc — **`exclusiveKey` PARTAGÉE** `{path|trustee|ace_type}`
   avec le provider capacités (identité DÉLÉGUÉE, jamais dupliquée). **Bi-alimentation
   dans UN SEUL provider compilé** (décision D1 ci-dessous — condition structurelle de
   l'arbitrage règle↔capacité par le compilateur).
4. **UI dédiée** `/app/folder-rules` (filesystem-router + SFC Volt + modale réutilisable
   + `WithToasts`) : formulaire n'exposant QUE des champs MÉTIER (chemin, groupe = VRAI
   picker SQL, sens, niveau, portée, parcs cibles), confirmation d'implications sur un
   `deny` (patron warning capacités), avertissement non bloquant quand la règle recouvre
   une capacité catalogue active.
5. **Délégation** : permissions dédiées `folderrule.view`/`folderrule.manage` + contrôle
   PAR PARC via `PermissionService::canOnWorkstationGroup()` (anti-piège « Gate global
   non scopé », mémoire `project_delegation_enforcement_wpkg_gap` ; patron
   `WorkstationGroupPolicy::customize` 29.6).

**Ce que la story NE fait PAS** : AUCUNE nouvelle notion côté agent/contrat (l'agent ne
voit que des items `fs_acl` — il ignore qui les a produits) ; zéro modification de
`agent/**`, du contrat, du golden, des hashes figés, de `StateCompiler`, de
`FsAclAuthoringGuard`, de `FsAclCapabilityProvider` (composé TEL QUEL), zéro bump de
version agent. Pas de ciblage par utilisateur (piège #10 de 36.1 : « quel utilisateur est
bridé » = le trustee DANS le payload ; « quels postes » = les parcs assignés).

## ⚠️ Pièges & tensions (lire AVANT de coder)

1. **Piège #1 — le compilateur arbitre PAR PROVIDER : deux providers `fs_acl` = collision
   NON arbitrée.** `StateCompiler::compileProvider()` appelle `itemsFor()` puis
   `selectExclusive()` provider par provider, et fusionne les items par portée SANS
   arbitrage croisé. Si `FolderAccessRulesStateProvider` était enregistré COMME UNE LIGNE
   DE PLUS à côté de `FsAclCapabilityProvider`, une collision règle↔capacité sur la même
   identité `{path|trustee|ace_type}` produirait DEUX items de même identité dans le
   state (le dédoublonnage du handler Go trancherait par ordre trié — déterministe mais
   aveugle à la maille), et l'AC d'epic « collision arbitrée par le compilateur
   (maille/récence) » serait FAUSSE. **La condition structurelle de l'AC est que les deux
   flux de candidats passent par UNE SEULE sélection exclusive** → décision D1 :
   composition (bi-alimentation), UN SEUL provider `fs_acl` au registre du compilateur.
   `StateCompiler` reste INTOUCHÉ (garde-fou D2 d'epic).

2. **Piège #2 — l'observer serveur NE COUVRE PAS les règles (leçon review 36.1 #2b).**
   `CapabilityProjectionObserver` (saving, `mechanism==='fs_acl'`) ne fire que sur
   `capability_projections`. Les règles de 36.4 vivent dans `folder_access_rules` : SANS
   appel explicite, la décision Q2 de Henri serait inopérante sur cette surface (le
   catalogue serait protégé, le formulaire pas). **Le service appelle
   `FsAclAuthoringGuard::violations()` à CHAQUE create/update** (adaptation règle →
   forme projection, cf. D4) et REFUSE si non vide (messages FR du guard remontés tels
   quels). Le refus agent (corrections 36.1 #2a : racines protégées + SID système +
   noms courts 8.3 côté Go) reste le filet ultime — défense en profondeur, garde-fou
   d'epic « serveur ET agent ».

3. **Piège #3 — type absent du state = handler jamais invoqué (36.1 piège #3) : le
   retrait propre passe par un « off réel ».** Si plus AUCUN item `fs_acl` n'atteint un
   poste, la réconciliation du store ne tourne pas et l'ACE gérée SURVIVRAIT. D'où
   (décision D3) : **désactiver une règle (`is_active=false`) n'éteint pas son émission —
   elle émet ses items avec `ensure: 'absent'`** (retrait honnête, symétrique — même
   doctrine que le `off` réel du seed 36.1 et l'invariant projet « un off proposé fait
   une VRAIE action »). **La suppression d'une règle ACTIVE est REFUSÉE** (message FR :
   « Désactivez d'abord la règle — le retrait des ACE au parc passe par la
   désactivation ») ; une règle inactive est supprimable. Écart de PRÉCISION assumé vs la
   lettre de l'epic (« désactiver ou supprimer une règle retire ses items ») — motivé
   par le piège #3, à consigner au Dev Agent Record. Fenêtres d'orphelin RESTANTES
   (documentées, pas sur-conçues — iso 36.1) : poste qui QUITTE le parc porteur ;
   suppression du groupe cible (FK cascade) ; suppression d'une règle inactive avant
   convergence du dernier poste hors-ligne.

4. **Piège #4 — trustee : le nom SQL n'est PAS forcément le nom AD.** Le payload
   `trustee` doit être un nom que la LSA du poste joint résout
   (`sAMAccountName`/nom de groupe AD). Or les groupes SQL sont FOLDÉS au nom nu
   (mémoire `project_usergroup_sql_fold_bare_name` : `user_groups.name = '3A'` pour un
   `ad_dn = 'CN=Classe_3A,…'`). **Émettre `name` verbatim casserait la résolution pour
   les classes.** Dérivation prescrite (D9) : dernier segment `CN=` de `ad_dn` quand
   renseigné, sinon `name` (fallback verbatim). Un groupe SANS `ad_dn` déclenche un
   AVERTISSEMENT à la création (« groupe sans correspondance AD connue — la règle
   pourrait ne pas s'appliquer ») ; à l'exécution l'agent tombe en erreur d'ITEM tracée
   si irrésoluble (36.1 piège #7 — visible au reporting, jamais silencieux). Vérif e2e
   lab obligatoire (iso piège #15 « Domain Users »).

5. **Piège #5 — golden/hashes : la composition doit être BYTE-IDENTIQUE sans règles.**
   Le remplacement de la ligne `FsAclCapabilityProvider` par le provider composé dans
   `AgentServiceProvider` ne doit RIEN changer au wire format quand `folder_access_rules`
   est vide : mêmes items, même ordre, même `FROZEN_STATE_HASH`
   (`6a41357d8a1ef725afc48c63cba67d5f097ea9844daa101e9303a333edff94a8` — 36.1, PHP ⇄ Go).
   `ContractV1Test` + `CapabilityFsAclProviderTest`/`CompilationTest` doivent rester
   verts SANS modification d'attendus. Zéro item = type absent (contrat §8) inchangé.

6. **Piège #6 — sourceId dans le pool composé.** `resolveExclusiveWinner()` départage en
   dernier recours par `sourceId` desc. Les candidats capacités portent
   `sourceId = capability.id` ; les candidats règles doivent rester INJECTIFS dans le
   même pool → `sourceId = 1_000_000 + pivot.id` (offset documenté, iso discipline
   `DrivesStateProvider` « 2 + pivot_id »). La récence réelle (`updatedAt` non null des
   deux côtés) départage AVANT le tiebreak dans tous les cas réalistes.

7. **Piège #7 — portée Machine : ne PAS copier l'early-return de DrivesStateProvider.**
   `DrivesStateProvider` (session) retourne vide si `user === null`. Ici c'est
   l'INVERSE : le service SYSTEM fetch SANS `?user` (`TargetContext::for($ws, null)`) et
   les règles doivent sortir. Le provider n'utilise QUE `workstationGroupIds()` /
   `physicalGroupIds` / `physicalGroupDepths` du contexte. Un test machine-only le
   prouve ; un override User/UserGroup est structurellement sans effet (piège #10 36.1).

8. **Piège #8 — maille du parc : physique AVEC profondeur, logique sans.** Iso capacités :
   un candidat `PhysicalGroup` porte `depth` (via `ctx->physicalGroupDepths[$wgId]`) pour
   que l'héritage salle-enfant-bat-parent s'arbitre correctement ; `LogicalGroup` (rang
   plus spécifique que physique, D-Q3 27.3) n'en porte pas. Étiquetage par les listes du
   contexte (iso `DrivesStateProvider::mailleFor`) — étiquetage, JAMAIS de précédence
   dans le provider (D2).

9. **Piège #9 — permission scopée : le Gate global ne suffit PAS (mémoire
   `project_delegation_enforcement_wpkg_gap`).** Une permission vérifiée uniquement en
   Gate global laisse un délégué agir HORS de son périmètre. Patron correct = 29.6
   (`WorkstationGroupPolicy::customize`) : exclusion scopée d'abord, droit global
   ensuite, délégation positive par parc enfin — via
   `PermissionService::canOnWorkstationGroup($user, 'folderrule.manage', $wg)`. La page
   est gardée par le Gate `folderrule.view` ; CHAQUE attach/detach de parc est vérifié
   PAR PARC ; la liste des parcs proposés est restreinte aux parcs autorisés.

10. **Piège #10 — deny ⇒ warning non vide : le guard l'exige aussi pour les règles.**
    `FsAclAuthoringGuard` refuse toute entrée `deny` dont la « capacité » n'a pas de
    `warning` non vide. Pour une règle, l'implication est portée par la CONFIRMATION UI
    (checkbox patron `warningAcknowledged` de `capabilities-tab`). Adaptation D4 : le
    service passe TOUJOURS au guard la constante FR d'implications (le texte affiché
    dans l'encart de confirmation) comme `warning` — l'acquittement est contrôlé PAR
    L'UI (bloquant côté formulaire), le guard valide le RESTE (racines, principals,
    enums, 8.3).

11. **Piège #11 — pickers : SQL pur, pas d'AD, pas de scope inventé.** Iso 34.2 (Q3
    arbitré par Henri) : les pickers sont des modèles SQL (`UserGroup::query()`,
    `WorkstationGroup`), ZÉRO LdapRecord/CN AD dans le chemin SQL. « Scopé
    établissement » = les `user_groups` de l'instance (une instance SE5 = un
    établissement) — on n'invente PAS de filtre établissement SQL (inexistant,
    dette 34.2 documentée) ; le scope RÉEL porte sur les PARCS (délégation, piège #9).

12. **Piège #12 — 36.2 (firewall) en aval : fichier partagé `AgentServiceProvider`.**
    36.2 ajoutera sa ligne provider dans la MÊME liste. Confiner la modification 36.4 au
    bloc fs_acl existant (remplacement d'UNE ligne + commentaire), ne toucher AUCUN
    autre bloc — le merge d'epic gérera la zone. Ne toucher aucun fichier propre à
    36.2 (contrat, version.go, golden : cette story n'a RIEN à y faire).

13. **Piège #13 — Livewire : jamais d'action nommée `upload`** (mémoire
    `project_livewire_reserved_upload_method`) — garde-fou générique (pas d'upload ici).
    UX formulaires : labels AU-DESSUS des inputs, hints en TOOLTIP (pas de hint
    inutile), champs obligatoires ÉTOILÉS (mémoire
    `feedback_form_label_above_input_tooltip_hints`).

## Décisions de design (tranchées — cadrage epic + exploration code)

1. **D1 — Bi-alimentation via UN SEUL provider `fs_acl` compilé (calque exact
   `DrivesStateProvider`).** `FsAclCapabilityProvider` est `final` → COMPOSITION :
   `FolderAccessRulesStateProvider implements StateProvider, KeyedExclusiveProvider`
   reçoit `FsAclCapabilityProvider` au constructeur ; `itemsFor($ctx)` =
   `$this->capabilities->itemsFor($ctx)` ∪ candidats-règles ; `type()`/`semantics()`/
   `scope()` et **`exclusiveKey()` DÉLÉGUÉS** au provider capacités (identité
   `{path|trustee|ace_type}` définie à UN endroit). Dans `AgentServiceProvider`, la
   ligne `FsAclCapabilityProvider::class` est REMPLACÉE par
   `FolderAccessRulesStateProvider::class` (commentaire 36.4 : bi-alimentation D8,
   condition de l'arbitrage compilateur). `UpstreamAwareProvider::wrap` enrobe le
   composite comme les autres (marqueur `KeyedExclusiveProvider` relayé). Résultat : la
   collision règle↔capacité sur identité ÉGALE est arbitrée par `selectExclusive()`
   maille/récence EXISTANT (zéro ligne dans `StateCompiler`), et les identités
   distinctes coexistent (cumul, doctrine 36.1 piège #2).
2. **D2 — Schéma.** `folder_access_rules` : `path` (string), `user_group_id`
   (FK `user_groups` **cascadeOnDelete** — un groupe supprimé emporte ses règles ;
   fenêtre d'orphelin documentée piège #3), `ace_type` (`allow|deny`), `rights`
   (`list_folder|read|write|modify`), `applies_to`
   (`folder_only|folder_subfolders_files` — `subfolders_files_only` NON exposé v1),
   `label` (libellé admin, requis), `is_active` (bool, défaut true),
   `created_by_user_id` (FK nullable `nullOnDelete`), timestamps. Domaines validés
   APPLICATIVEMENT (constantes du guard réutilisées — SQLite n'applique pas les
   varchar/checks, mémoire `project_sqlite_tests_no_varchar_enforcement`).
   `folder_access_rule_assignables` : calque byte-près de `network_share_assignables`
   (FK cascade, `morphs('assignable')`, `unique(rule, assignable_id, assignable_type)`)
   SANS colonne `access` ; `FolderAccessRule::ALLOWED_ASSIGNABLE_TYPES =
   [WorkstationGroup::class]` (v1 — extensible sans migration, validé applicativement).
3. **D3 — Cycle de vie / retrait propre** (piège #3) : `is_active=true` ⇒ items
   `ensure:'present'` ; `is_active=false` ⇒ MÊMES items `ensure:'absent'` (off réel) ;
   suppression refusée si active (message FR), autorisée si inactive ; la suppression
   retire les lignes (cascade pivot) — plus rien n'est émis.
4. **D4 — Guard EXPLICITE dans le service.** `FolderAccessRuleService` construit la
   forme projection attendue par `violations()` :
   `[['capability' => "règle « {$label} »", 'warning' => self::DENY_WARNING,
   'spec' => ['aces' => [[path, trustee (dérivé D9), ace_type, rights, applies_to,
   'ensure' => 'present']]]]]` et REFUSE (ValidationException, messages FR du guard) si
   non vide — à CHAQUE create ET update. `DENY_WARNING` = constante FR d'implications
   (affichée aussi dans l'encart de confirmation UI). Le guard N'EST PAS modifié
   (docblock 36.1 : « réutilisé TEL QUEL par le formulaire 36.4 »).
5. **D5 — Avertissement de recouvrement capacité.** `FolderAccessRuleValidator`
   (service de PURE LECTURE, calque `NetworkShareValidator` 34.2) :
   `capabilityOverlaps(path, trustee, aceType): list<string>` — compare l'identité
   normalisée (minuscules, normalisation chemin du guard) aux entrées `aces[]` des
   projections `windows/fs_acl` des capacités ACTIVES : trustee littéral verbatim ; map
   valeur-capacité → CHAQUE valeur ; jeton `@…` → map STATIQUE
   `AudienceTokens::TOKENS` (pas de requête d'existence — c'est un avertissement, pas
   une émission). Match ⇒ **warning NON bloquant** (`toastWarning` nommant la capacité :
   « cette règle recouvre la capacité « … » — en cas de conflit, la maille la plus
   spécifique/la plus récente gagne »). Une collision règle↔règle sur la même identité
   n'est PAS un warning (elle est arbitrée par le compilateur, même provider — D1).
6. **D6 — Permissions & policy** (patron 34.2 Q5 EXACT) : cases `SambaPermission`
   `FolderRuleView = 'folderrule.view'` / `FolderRuleManage = 'folderrule.manage'`
   (label FR, `category`, `legacyRight` = bit représentatif iso `networkshare.*`,
   `isSecondaryBitPermission() = true` — JAMAIS sur-attribuées par import bitmask) ;
   octroi dans `SambaRole` : `ReferentNumerique` + `ComputerAdmin` (view+manage),
   `SuperAdmin` auto via `cases()` — PAS Prof/Eleve/Technicien.
   `FolderAccessRulePolicy` (traits `RegistersGates`/`ChecksPermissions`, gates
   `viewAny-folderrule`/`manage-folderrule`), enregistrée dans `AuthServiceProvider`.
   Scope par parc : piège #9 (`canOnWorkstationGroup`). **(Re)seed VM requis**
   (`PermissionSeeder`) — à signaler au Dev Agent Record (tant que non joué :
   403 même pour un refnum).
7. **D7 — Audit.** `folder_access_rule_audit_logs` + modèle `FolderAccessRuleAuditLog`
   APPEND-ONLY (save() lève sur update, fabrique statique `log()` appelée DANS la
   transaction de la mutation — calque `CapabilityOverrideAuditLog`) : `action`
   (`create|update|delete` — activation/désactivation et mutations d'assignation =
   `update`), `actor_user_id`/`actor_login`, `rule_id` (nullable `nullOnDelete`),
   `rule_label`, `old_state`/`new_state` (snapshots JSON : champs + ids de parcs).
8. **D8 — UI.** Routes `routes/web.php` groupe `app` (sous le bloc `shares`) :
   `/folder-rules` + `/folder-rules/{id}`, middleware `can:folderrule.view`. Pages
   `resources/views/pages/folder-rules/index.blade.php` (liste : label, chemin, groupe,
   sens, niveau, portée, nb parcs, badge actif/inactif ; recherche `#[Url]`, pagination,
   état vide ; bouton « Nouvelle règle » sous `@can('manage-folderrule')` ; modale de
   création `x-molecules.modal`) et `folder-rules/[id]/index.blade.php` (édition +
   activation/désactivation + assignation parcs + suppression `wire:confirm`). SFC Volt
   (`new #[Title] class extends Component { use WithToasts; … }`), UX piège #13.
   Champs métier UNIQUEMENT : chemin* (texte, regex miroir guard `^[A-Za-z]:\\`,
   tooltip « chemin Windows absolu, ex. D:\Ressources »), groupe* (picker SQL
   searchable `user_groups`), sens* (« Interdire »=deny / « Autoriser »=allow),
   niveau* (« Parcourir »=list_folder / « Lire »=read / « Écrire »=write /
   « Modifier »=modify), portée* (« Ce dossier seul »=folder_only / « Dossier et
   contenu »=folder_subfolders_files), libellé*, parcs cibles (sur la page d'édition).
   `deny` ⇒ encart `alert-warning` (texte `DENY_WARNING`) + checkbox d'acquittement
   OBLIGATOIRE (patron `capabilities-tab` `warningAcknowledged`). Violations guard et
   overlaps mappés en messages FR (erreurs de champ / `toastError` / `toastWarning`).
9. **D9 — Dérivation du trustee** (piège #4) : méthode dédiée (sur le modèle
   `FolderAccessRule` ou helper du provider — UN seul foyer, consommé par le provider ET
   le service/validator) : dernier segment `CN=` de `user_groups.ad_dn` si présent,
   sinon `name` verbatim. Résolution à l'ÉMISSION (jointure — un rename de groupe suit).
   Zéro SID en SQL (D5 36.1 : résolution LSA côté poste).
10. **D10 — updatedAt des candidats-règles** : `max(rule.updated_at, pivot.updated_at)`
    (la récence arbitre honnêtement face à un override de capacité de même identité —
    modifier une règle OU son assignation la « rafraîchit »). Déterministe entre deux
    compilations (valeurs DB stables).

## Acceptance Criteria

### AC1 — Modèle : table + pivot par parc (patron Epic 34)

**Given** la nouvelle migration (une seule : table + pivot)
**When** elle est jouée
**Then** `folder_access_rules` porte `{path, user_group_id (FK user_groups
cascadeOnDelete — VRAI picker de groupe SQL, PAS un jeton), ace_type, rights,
applies_to, label, is_active (défaut true), created_by_user_id (nullable nullOnDelete),
timestamps}` — domaines validés applicativement via les constantes de
`FsAclAuthoringGuard` (D2)
**And** `folder_access_rule_assignables` est le calque de `network_share_assignables`
(FK cascade, `morphs('assignable')`, contrainte unique par (règle, cible)), SANS colonne
`access` ; le modèle `FolderAccessRule` expose `ALLOWED_ASSIGNABLE_TYPES =
[WorkstationGroup::class]` (v1, extensible sans migration) et les relations
`assignments()` / `workstationGroups()` (morphedByMany) + `userGroup()` (belongsTo)
**And** aucune nouvelle notion côté agent/contrat : la règle se projette en items
`fs_acl` IDENTIQUES à ceux d'une capacité (D8) — `StateContract`, golden, hashes,
`agent/**` INTOUCHÉS.

### AC2 — Service : guard EXPLICITE + audit + cycle de vie sûr

**Given** `FolderAccessRuleService` (create / update / setActive / delete / syncParcs)
**When** une règle est créée ou modifiée
**Then** `FsAclAuthoringGuard::violations()` est appelé EXPLICITEMENT (adaptation D4 —
l'observer `CapabilityProjectionObserver` ne couvre PAS cette table, leçon review 36.1
#2b) et toute violation REFUSE la mutation avec les messages FR du guard (racines
protégées × héritage Q2, principals système en deny, noms courts 8.3, enums hors
domaine, chemin non absolu) — le combo interdit (`deny` descendant sur `C:\Windows`)
est PROUVÉ refusé par test au niveau SERVICE
**And** chaque create/update/delete (y compris activation/désactivation et mutation
d'assignations = update) écrit une ligne `FolderAccessRuleAuditLog` append-only DANS la
transaction (action, acteur, label, snapshots old/new incluant les ids de parcs — D7)
**And** la suppression d'une règle ACTIVE est REFUSÉE avec message FR (« Désactivez
d'abord… » — D3/piège #3) ; une règle inactive est supprimable (cascade pivot)
**And** le service ne touche JAMAIS le FS ni l'AD (Postgres pur) et n'écrit aucun SID.

### AC3 — Provider : bi-alimentation, exclusiveKey partagée, arbitrage compilateur

**Given** `FolderAccessRulesStateProvider` (D1 : compose `FsAclCapabilityProvider`,
type/semantics/scope/`exclusiveKey()` DÉLÉGUÉS ; remplace sa ligne dans
`AgentServiceProvider` — UN SEUL provider `fs_acl` compilé)
**When** l'état d'un poste est compilé (`TargetContext::for($ws, null)`, service SYSTEM)
**Then** chaque règle assignée à un parc du poste émet UN item 6 clés
`{path, trustee, ace_type, rights, applies_to, ensure}` à la maille du parc
(`PhysicalGroup` avec `depth` du contexte / `LogicalGroup` — piège #8), `trustee` dérivé
D9 (CN de `ad_dn`, fallback `name`), `ensure = 'present'` si `is_active` sinon
`'absent'` (off réel, D3), `updatedAt` D10, `sourceId = 1_000_000 + pivot.id` (piège #6)
**And** l'`exclusiveKey` est PARTAGÉE `{path|trustee|ace_type}` (délégation — un test
prouve l'égalité des clés entre un item règle et un item capacité de même identité)
**And** une collision règle↔capacité sur la même identité est arbitrée par le
compilateur EXISTANT via la sélection exclusive unique : un test de compilation
(StateCompiler INTOUCHÉ) prouve — (a) règle de parc bat le défaut Broadcast d'une
capacité de même identité ; (b) sur maille égale, la récence tranche ; (c) deux
identités DISTINCTES (trustees différents) coexistent (cumul, doctrine 36.1 piège #2)
**And** sans aucune règle en base, la sortie compilée est BYTE-IDENTIQUE à l'existant
(piège #5) : `ContractV1Test` (golden + `FROZEN_STATE_HASH`),
`CapabilityFsAclProviderTest`, `CapabilityFsAclCompilationTest` verts SANS modification
d'attendus
**And** le provider est Postgres pur (zéro AD/LdapRecord/APCu — critère Keycloak), ne
retourne PAS vide en machine-only (piège #7), et un ciblage User/UserGroup du pivot est
impossible par construction (types validés) — prouvé par tests.

### AC4 — UI : page dédiée, formulaire 100 % métier, confirmation deny, overlap

**Given** un refnum disposant de `folderrule.view`
**When** il navigue vers `/app/folder-rules`
**Then** la page SFC (filesystem-router, `x-organisms.page` + data-table + pagination,
recherche `#[Url]`, état vide) liste les règles (label, chemin, groupe, sens, niveau,
portée, nb parcs, badge actif/inactif) ; « Nouvelle règle » sous
`@can('manage-folderrule')` ; 403 sans permission
**When** il crée/édite une règle (modale réutilisable `x-molecules.modal`, puis page
`/app/folder-rules/{id}` pour l'édition + assignation parcs)
**Then** le formulaire n'expose QUE des champs MÉTIER (D8 : chemin validé format
Windows absolu miroir du guard, groupe = VRAI picker SQL `user_groups` searchable, sens
Interdire/Autoriser, niveau Parcourir/Lire/Écrire/Modifier, portée Ce dossier seul /
Dossier et contenu, libellé, parcs cibles) — AUCUN enum technique, AUCUN masque, AUCUN
SDDL visible ; UX : labels au-dessus, hints en tooltip, obligatoires étoilés (piège #13)
**And** une règle `deny` exige l'acquittement d'un encart d'implications (constante
`DENY_WARNING`, patron warning capacités : checkbox + erreur de validation si non
cochée) ; les violations du guard remontent en messages FR explicites
**And** quand l'identité de la règle recouvre une capacité catalogue ACTIVE, un
`toastWarning` NON bloquant nomme la capacité (D5) — la création reste possible
**And** un groupe sans `ad_dn` déclenche l'avertissement D9/piège #4 (non bloquant)
**And** toasts via `WithToasts` sur toutes les mutations ; suppression `wire:confirm` ;
suppression d'une règle active → `toastError` avec le message D3.

### AC5 — Délégation scopée + audit consultable

**Given** les permissions dédiées `folderrule.view`/`folderrule.manage` (D6 : enum +
rôles + `isSecondaryBitPermission`, policy `FolderAccessRulePolicy` enregistrée)
**When** un délégué n'ayant la délégation `folderrule.manage` QUE sur le parc A tente
d'assigner/désassigner le parc B
**Then** l'opération est REFUSÉE (contrôle PAR PARC via
`PermissionService::canOnWorkstationGroup` — piège #9, anti-piège Gate global non
scopé) et le picker de parcs ne propose que les parcs autorisés ; l'admin global
(permission Spatie globale) voit tout
**And** la route est gardée `can:folderrule.view`, les mutations par
`manage-folderrule` (+ contrôle par parc pour les assignations)
**And** chaque create/update/delete est audité (AC2) — vérifié par test (lignes d'audit
avec acteur + snapshots).

### AC6 — Tests (HÔTE php8.4 + sqlite, filtres ciblés) + non-régression

**Then** tests net-new : migration/schéma + pivot (unicité, cascade), service (guard
explicite : Q2/système/8.3 refusés au niveau service ; audit ; refus suppression
active ; off réel), provider (émission/maille/depth/absent/trustee D9/sourceId/
machine-only/PG-pur/exclusiveKey partagée), compilation (arbitrage règle↔capacité DANS
LES DEUX SENS + coexistence + byte-identité sans règles), validator (overlap capacité :
littéral, map, jeton `@…` ; zéro faux positif sur identité différente), policy +
délégation scopée, Livewire (index + détail : création valide/invalide, deny non acquitté
refusé, 403, parcs scopés, suppression)
**And** non-régression GARANTIE (attendus INCHANGÉS) : `--filter ContractV1`
(golden + `FROZEN_STATE_HASH`), `--filter "CapabilityFsAclProviderTest|CapabilityFsAclCompilationTest|CapabilityFsAclSeedTest"`,
`--filter StateCompiler`, `--filter "PermissionSeeder|SambaPermission|RoutesProtection"`
(enum permissions touché — baseline iso 34.2)
**And** tout sur l'HÔTE (mémoire `phpunit_test_env_host_vs_vm`), JAMAIS de run massif
(mémoire `vm_phpunit_bulk_run_false_failures`) ; AUCUN test Go (agent intouché).

### AC7 — Docs + notes d'exploitation + e2e lab (manuel, hors périmètre dev)

**Then** `docs/agent/state-providers.md` § `fs_acl` : note bi-alimentation D8 (capacités
+ règles, un provider compilé, arbitrage) ; `docs/qa/domains/agent.md` : section
« Story 36.4 » APPEND-ONLY (scénarios : règle deny sur dossier arbitraire, off réel,
overlap capacité, délégation scopée, protocole e2e lab)
**And** le Dev Agent Record consigne : (a) **(re)seed `PermissionSeeder` à jouer sur
/vm** (sinon 403) + **migration à rejouer sur /vm** (`migrate:status` d'abord — mémoire
`vm_migrations_not_auto_applied`) + **route:cache VM** après ajout de routes réelles
(mémoire `project_route_cache_vm_ephemeral_test_routes`) ; (b) AUCUNE publication agent
(2.6.0 de 36.1 suffit — mais la story RAPPELLE que sans release 2.6.0 publiée les items
`fs_acl` sont ignorés EN SILENCE par un binaire ≤ 2.5.0) ; (c) l'écart D3 (« off réel »,
suppression en deux temps) ; (d) protocole e2e lab MANUEL : règle créée EN UI sur un
dossier arbitraire (ex. `D:\Ressources`) pour un groupe réel (classe) → sur poste joint
au domaine, l'Explorateur REFUSE l'ouverture au membre du groupe ET l'accès reste intact
pour les autres ; désactivation (puis suppression) → accès restauré ; vérifier au
passage la résolution du trustee dérivé (piège #4/D9).

## Tasks / Subtasks

- [x] **Task 1 — Migration + modèles (AC1)**
  - [x] 1.1 `database/migrations/2026_07_04_120000_create_folder_access_rules_tables.php` :
        table + pivot (D2, calque `2026_06_29_120000/120100` — doctrine en tête :
        D8, off réel piège #3, cascade user_group documentée).
  - [x] 1.2 `app/Models/FolderAccessRule.php` : fillable, casts (`is_active` bool),
        `ALLOWED_ASSIGNABLE_TYPES`, relations (`userGroup()`, `assignments()`,
        `workstationGroups()` morphedByMany), dérivation trustee D9 (`trusteeName()`
        — CN de `ad_dn` du groupe, fallback `name`), docblock (identité partagée,
        pas de ciblage user).
  - [x] 1.3 `app/Models/FolderAccessRuleAssignable.php` (calque
        `NetworkShareAssignable` sans `access`) + factories des deux modèles.
- [x] **Task 2 — Service + audit + validator (AC2, D4/D5/D7)**
  - [x] 2.1 `app/Models/FolderAccessRuleAuditLog.php` + migration
        `folder_access_rule_audit_logs` (append-only, fabrique `log()` — calque
        `CapabilityOverrideAuditLog`).
  - [x] 2.2 `app/Services/Agent/FolderAccessRuleService.php` : create/update/
        setActive/delete/syncParcs — transaction, appel EXPLICITE
        `FsAclAuthoringGuard::violations()` (adaptation D4 + constante
        `DENY_WARNING` FR), audit à chaque mutation, refus suppression active (D3),
        validation `ALLOWED_ASSIGNABLE_TYPES` + contrôle par parc (piège #9, reçoit
        l'acteur).
  - [x] 2.3 `app/Services/Agent/FolderAccessRuleValidator.php` (pure lecture, calque
        `NetworkShareValidator`) : `capabilityOverlaps()` (D5 — projections fs_acl
        actives, trustee littéral/map/jeton via `AudienceTokens::TOKENS` statique),
        `missingAdDn()` (avertissement D9).
  - [x] 2.4 Tests : `tests/Feature/Migrations/FolderAccessRulesSchemaTest.php`,
        `tests/Unit/Services/Agent/FolderAccessRuleServiceTest.php` (guard/audit/
        cycle de vie), `tests/Unit/Services/Agent/FolderAccessRuleValidatorTest.php`.
- [x] **Task 3 — Provider composé + wiring (AC3, D1)**
  - [x] 3.1 `app/Services/Agent/Providers/FolderAccessRulesStateProvider.php` :
        composition (constructeur `FsAclCapabilityProvider`), délégation
        type/semantics/scope/exclusiveKey, `itemsFor()` = union, lecture pivot
        Postgres pure restreinte à `workstationGroupIds()` (jointure rules +
        user_groups), maille/depth piège #8, ensure D3, updatedAt D10, sourceId
        piège #6, docblock complet (bi-alimentation D8, pourquoi UN provider —
        piège #1).
  - [x] 3.2 `app/Providers/AgentServiceProvider.php` : REMPLACER la ligne
        `FsAclCapabilityProvider::class` par le composite (commentaire 36.4) —
        AUCUN autre changement (piège #12).
  - [x] 3.3 Tests : `tests/Unit/Services/Agent/FolderAccessRulesProviderTest.php`
        (émission/absent/maille/depth/trustee/machine-only/PG-pur/clé partagée/
        byte-identité sans règles) +
        `tests/Unit/Services/Agent/FolderAccessRulesCompilationTest.php`
        (arbitrage règle↔capacité les deux sens, coexistence, StateCompiler intouché).
- [x] **Task 4 — Permissions + policy + délégation (AC5, D6)**
  - [x] 4.1 `app/Enums/SambaPermission.php` : cases `FolderRuleView`/`FolderRuleManage`
        (label/category/legacyRight/isSecondaryBitPermission — patron `networkshare.*`).
  - [x] 4.2 `app/Enums/SambaRole.php` : octroi refnum + ComputerAdmin.
  - [x] 4.3 `app/Policies/FolderAccessRulePolicy.php` (gates `viewAny-folderrule`/
        `manage-folderrule`) + enregistrement `AuthServiceProvider`.
  - [x] 4.4 Contrôle PAR PARC dans le service (2.2) via
        `PermissionService::canOnWorkstationGroup` + restriction du picker de parcs.
  - [x] 4.5 Tests : `tests/Feature/Policies/FolderAccessRulePolicyTest.php` (dont le
        scénario délégué parc-A-seulement refusé sur parc B).
- [x] **Task 5 — UI (AC4, D8)**
  - [x] 5.1 `routes/web.php` (groupe `app`, sous le bloc shares) : `/folder-rules` +
        `/folder-rules/{id}`, `can:folderrule.view`.
  - [x] 5.2 `resources/views/pages/folder-rules/index.blade.php` : liste + recherche +
        pagination + état vide + modale de création (mapping mots métier → enums,
        encart deny + acquittement, catch violations → erreurs FR, overlap →
        toastWarning, avertissement ad_dn manquant).
  - [x] 5.3 `resources/views/pages/folder-rules/[id]/index.blade.php` : édition (mêmes
        validations), toggle actif/inactif (toast expliquant l'effet au prochain
        cycle), assignation de parcs (picker restreint aux parcs autorisés),
        suppression `wire:confirm` (refus si active).
  - [x] 5.4 Tests Livewire : `tests/Feature/Livewire/FolderRules/FolderRulesIndexTest.php`
        + `FolderRuleDetailTest.php`.
- [x] **Task 6 — Docs + finalisation (AC6, AC7)**
  - [x] 6.1 Baselines HÔTE ciblées AVANT/APRÈS (ContractV1, CapabilityFsAcl*,
        StateCompiler, PermissionSeeder/SambaPermission/RoutesProtection) —
        attendus INCHANGÉS ; suites net-new vertes.
  - [x] 6.2 `docs/agent/state-providers.md` (note bi-alimentation) +
        `docs/qa/domains/agent.md` (§ 36.4 append-only, protocole e2e lab).
  - [x] 6.3 Dev Agent Record : reseed permissions + migrations /vm + route:cache,
        aucune publication agent, écart D3, note e2e lab, vérif git status
        (sanctuaire intact : agent/**, golden, StateCompiler, guard, provider 36.1).

## Dev Notes

### Fichiers à toucher (exhaustif prévu)

| Fichier | Nature |
|---|---|
| `database/migrations/2026_07_04_120000_create_folder_access_rules_tables.php` | NOUVEAU — table + pivot |
| `database/migrations/2026_07_04_120100_create_folder_access_rule_audit_logs_table.php` | NOUVEAU — audit |
| `app/Models/FolderAccessRule.php` | NOUVEAU |
| `app/Models/FolderAccessRuleAssignable.php` | NOUVEAU |
| `app/Models/FolderAccessRuleAuditLog.php` | NOUVEAU |
| `database/factories/FolderAccessRule*Factory.php` | NOUVEAU |
| `app/Services/Agent/FolderAccessRuleService.php` | NOUVEAU — guard EXPLICITE + audit |
| `app/Services/Agent/FolderAccessRuleValidator.php` | NOUVEAU — overlap capacité (pure lecture) |
| `app/Services/Agent/Providers/FolderAccessRulesStateProvider.php` | NOUVEAU — composite D1 |
| `app/Providers/AgentServiceProvider.php` | 1 ligne REMPLACÉE (bloc fs_acl) — ⚠️ partagé avec 36.2 |
| `app/Enums/SambaPermission.php` | +2 cases `folderrule.*` |
| `app/Enums/SambaRole.php` | octrois refnum/ComputerAdmin |
| `app/Policies/FolderAccessRulePolicy.php` | NOUVEAU |
| `app/Providers/AuthServiceProvider.php` | +1 enregistrement policy |
| `routes/web.php` | +2 routes `/folder-rules` |
| `resources/views/pages/folder-rules/index.blade.php` | NOUVEAU — SFC Volt |
| `resources/views/pages/folder-rules/[id]/index.blade.php` | NOUVEAU — SFC Volt |
| `tests/Feature/Migrations/FolderAccessRulesSchemaTest.php` | NOUVEAU |
| `tests/Unit/Services/Agent/FolderAccessRuleServiceTest.php` | NOUVEAU |
| `tests/Unit/Services/Agent/FolderAccessRuleValidatorTest.php` | NOUVEAU |
| `tests/Unit/Services/Agent/FolderAccessRulesProviderTest.php` | NOUVEAU |
| `tests/Unit/Services/Agent/FolderAccessRulesCompilationTest.php` | NOUVEAU |
| `tests/Feature/Policies/FolderAccessRulePolicyTest.php` | NOUVEAU |
| `tests/Feature/Livewire/FolderRules/FolderRulesIndexTest.php` | NOUVEAU |
| `tests/Feature/Livewire/FolderRules/FolderRuleDetailTest.php` | NOUVEAU |
| `docs/agent/state-providers.md` | note bi-alimentation § fs_acl |
| `docs/qa/domains/agent.md` | § Story 36.4 append-only |

**NE PAS TOUCHER** : `app/Services/Agent/StateCompiler.php` / `StateHasher.php` /
`StateCandidate.php` / `TargetContext.php` (D2 — l'arbitrage vient de la composition,
pas d'une modif compilateur), `app/Services/Agent/Providers/FsAclCapabilityProvider.php`
+ `FsAclAuthoringGuard.php` + `AudienceTokens.php` (RÉUTILISÉS TELS QUELS — le guard le
dit dans son docblock), `app/Observers/CapabilityProjectionObserver.php`,
`agent/**` (AUCUN fichier — pas de bump `version.go`, la 2.6.0 de 36.1 porte déjà le
handler), `tests/Fixtures/Agent/state.v1.json` + `ContractV1Test::FROZEN_STATE_HASH` +
`agent/shared/hasher_test.go` (golden INCHANGÉS — piège #5), `docs/agent/contract-v1.md`
(le contrat ne change pas), seed 36.1
(`2026_07_04_100000_seed_capability_program_files_browse_denied.php`) et ses tests
(`CapabilityFsAclSeedTest.php` — seulement EXÉCUTÉS en non-régression),
`DrivesStateProvider`/`NetworkShare*` (patron LU, jamais modifié), tout fichier des
stories 36.2/36.3, `sprint-status.yaml` (hors sa propre ligne), `backlog.*`
(orchestrateur).

### Patterns existants à imiter (chemins réels)

- **Bi-alimentation d'un canal** : `app/Services/Agent/Providers/DrivesStateProvider.php`
  (jeu fixe + `network_shares` dans UN provider ; `mailleFor()` ; sourceId offset ;
  lecture pivot restreinte aux ids du contexte). 36.4 = même doctrine, mais par
  COMPOSITION (le provider capacités est `final`).
- **Candidats capacités** : `AbstractCapabilityStateProvider::itemsFor()` (Broadcast
  `sourceId = capability.id`, overrides avec `depth` pour PhysicalGroup) — c'est le flux
  avec lequel les candidats-règles seront arbitrés.
- **Délégation d'exclusiveKey sans réinvention** : `UpstreamLockCollisionDetector`
  (30.5) délègue `exclusiveKey()` aux providers — même discipline.
- **Table + pivot + CRUD + policy + validation prédictive** : Story 34.2
  (`34-2-ui-admin-lecteurs-reseau-geres.md` — Dev Notes exhaustives : SFC Volt, modale
  `x-molecules.modal` + `section`, `WithToasts` + flash sur redirect, routes web.php
  groupe `app`, `NetworkSharePolicy` + permissions dédiées + `isSecondaryBitPermission`,
  `NetworkShareValidator` pure lecture + exception → toast). 34.3
  (`DirectoryTemplateService::materialize`) pour le style service transactionnel.
- **Permission scopée par parc** : `app/Policies/WorkstationGroupPolicy.php::customize`
  + `app/Services/PermissionService.php::canOnWorkstationGroup` (l.368 — négative
  scopée > droit global > positive scopée) ; consommé par
  `resources/views/pages/parc/groups/_partials/capabilities-tab.blade.php`
  (`guardCustomize`, l.514+).
- **Confirmation d'implications (warning)** : `capabilities-tab.blade.php` — propriété
  `warningAcknowledged`, `addError` si non cochée (l.302), encart `alert-warning` +
  checkbox (l.761+).
- **Audit append-only** : `app/Models/CapabilityOverrideAuditLog.php` (save() qui lève,
  fabrique `log()` en transaction, scopes de lecture).
- **Guard 36.1** : `app/Services/Agent/Providers/FsAclAuthoringGuard.php` — signature
  `violations(list<array{capability, warning, spec}>): list<string>` ; constantes
  publiques `ACE_TYPES`/`RIGHTS`/`APPLIES_TO`/`PROTECTED_ROOTS`/`SYSTEM_TRUSTEES` à
  consommer pour les validations de formulaire (source unique des domaines).

### Rappels transverses (garde-fous epic + mémoires)

- Zéro AD/LdapRecord/APCu dans provider/service/validator (critère Keycloak) ; drift
  STRICT ; zéro float (payloads 6 strings).
- Validation d'authoring serveur ET refus agent : le refus agent (36.1 #2a) existe déjà
  dans le handler publié 2.6.0 — 36.4 n'ajoute RIEN côté agent, elle branche le volet
  serveur pour SA table.
- Tests sur l'HÔTE (php8.4 + sqlite), filtres ciblés uniquement ; worktree : ne JAMAIS
  interagir avec la VM (`feedback_worktree_no_vm_sync`) ; vendor en `cp -al`, jamais de
  symlink (`project_ultradev_worktree_vendor_trap`).
- Recherches SQL cross-DB `LOWER(...) LIKE ?` (pas d'ILIKE — précédent 34.2).
- Aucune entrée sidebar n'existe pour `/app/shares` (précédent 34.2) — iso : pas
  d'entrée de navigation exigée ici ; si le dev en ajoute une, suivre le composant
  sidebar existant et le noter (optionnel, non bloquant).

### Project Structure Notes

- Backend : service + validator sous `app/Services/Agent/` (le domaine est l'état-cible
  agent, pas le filesystem serveur — contrairement à 34.x) ; provider sous
  `app/Services/Agent/Providers/`.
- UI : `resources/views/pages/folder-rules/` (index + `[id]`), partials éventuels sous
  `_partials/`. Pas de dossier `resources/views/livewire/`.
- Routes : `routes/web.php` groupe `app` (JAMAIS `routes/api.php`).

### References

- [Source: _bmad-output/planning-artifacts/epics-mecanismes-hors-registre.md#Story-36.4 +
  #D8 + #Garde-fous-d'epic] — autorité de cadrage
- [Source: _bmad-output/ultradev/36-questions.md — Q1/Q2 (validation 36.1 réutilisée)]
- [Source: _bmad-output/implementation-artifacts/36-1-mecanisme-fs-acl.md — pièges #2/#3/
  #7/#10/#13/#15, D2/D5/D6, store « dernier appliqué »] +
  [_bmad-output/codeReviews/36-1.md — findings #2a/#2b (guard explicite), #3 (8.3),
  #4 (principals alignés)]
- [Source: _bmad-output/implementation-artifacts/34-1/34-2/34-3-*.md — patron table +
  pivot + CRUD + policy dédiée + validation prédictive + arbitrages Q1-Q5]
- [Source: app/Services/Agent/StateCompiler.php — compileProvider/selectExclusive/
  resolveExclusiveWinner (fondement du piège #1)]
- [Source: app/Services/Agent/Providers/{FsAclCapabilityProvider, FsAclAuthoringGuard,
  AudienceTokens, DrivesStateProvider, AbstractCapabilityStateProvider}.php]
- [Source: app/Services/PermissionService.php::canOnWorkstationGroup +
  app/Policies/WorkstationGroupPolicy.php::customize — délégation scopée]
- [Source: app/Models/CapabilityOverrideAuditLog.php — patron audit append-only]
- Mémoires projet : `project_delegation_enforcement_wpkg_gap`,
  `project_capability_value_map_symmetric_rule`,
  `feedback_form_label_above_input_tooltip_hints`,
  `project_usergroup_sql_fold_bare_name`, `feedback_no_overengineered_choices`,
  `project_state_precedence_logical_over_physical`,
  `project_sqlite_tests_no_varchar_enforcement`.

## Dépendances

- **En amont — 36.1 (DONE, mergée sur epic-36, base de ce worktree)** : le mécanisme
  complet (type de contrat `fs_acl`, handler Go 2.6.0 + store « dernier appliqué » +
  refus agent Q2/SID/8.3, `FsAclCapabilityProvider` + `exclusiveKey`
  `{path|trustee|ace_type}`, `FsAclAuthoringGuard` réutilisé TEL QUEL,
  `AudienceTokens`). 36.4 n'ajoute RIEN au contrat ni à l'agent.
- **Indépendante de 36.2 (firewall), SAUF un point de friction** :
  `app/Providers/AgentServiceProvider.php` — 36.4 REMPLACE la ligne
  `FsAclCapabilityProvider` (composition D1) dans la MÊME liste de providers où 36.2
  AJOUTE sa ligne `firewall`. Conflit de merge trivial mais CERTAIN si les deux stories
  vivent en parallèle → à résoudre au merge d'epic (garder les deux modifications).
  Aucun autre fichier partagé (36.4 ne touche ni contrat, ni golden, ni version.go).
- **36.3 (lot registre)** : déjà mergée sur epic-36 — aucun fichier commun.
- **En aval** : le futur mécanisme `firewall` (36.2) et tout mécanisme suivant pourront
  répliquer le patron « feature à formulaire » de cette story (D8 : le mécanisme se paie
  une fois et sert les deux surfaces).
- **Exploitation (hors dev, à signaler)** : migrations + `PermissionSeeder` à rejouer
  sur /vm ; release agent 2.6.0 (36.1) publiée = prérequis d'EFFET au parc.

## Recommandation Modèle Dev

**opus** — prescription explicite de l'epic (« opus pour 36.3 (seed) et 36.4 (UI/CRUD,
patron 34.2) ») confirmée par l'exploration : le gros du diff est du CRUD/UI Livewire
sur patrons établis (34.2 est un calque quasi ligne-à-ligne), mais trois points exigent
du jugement : (1) la composition D1 du provider (comprendre POURQUOI un seul provider
`fs_acl` compilé, préserver la byte-identité golden sans règles) ; (2) le branchement
EXPLICITE du guard + l'adaptation deny⇒warning (leçon review 36.1 #2b — l'erreur
exacte que la review précédente a attrapée) ; (3) la délégation scopée par parc
(anti-piège Gate global). Pas de Go, pas de contrat, pas de golden à bumper → fable
serait surdimensionné ; sonnet risquerait de « simplifier » la composition en seconde
ligne de provider (piège #1) ou d'oublier le guard.

## Dev Agent Record

### Agent Model Used

claude-opus-4-8[1m] (prescription epic + reco story : opus).

### Debug Log References

- Audit `delete` : la FK `rule_id` (`nullOnDelete`) échouait quand la ligne
  d'audit était écrite APRÈS `$rule->delete()` (FK violation). Corrigé : trace
  AVANT la suppression (rule_id valide à l'INSERT, cascade `nullOnDelete` ensuite
  ; `rule_label` dénormalisé préserve la traçabilité). Test ajusté (query par
  `rule_label` + action).
- `x-organisms.data-table` exige l'attribut `colgroup` (non optionnel) — ajouté
  au tableau de la page liste (8 colonnes).

### Completion Notes List

**Décisions appliquées telles que tranchées (D1–D10).**

- **D1 (composition provider)** : `FolderAccessRulesStateProvider implements
  StateProvider, KeyedExclusiveProvider` COMPOSE `FsAclCapabilityProvider`
  (`final`) ; `type/semantics/scope/exclusiveKey` DÉLÉGUÉS ; `itemsFor()` =
  `capabilities->itemsFor() ∪ ruleCandidates()`. La ligne
  `FsAclCapabilityProvider::class` d'`AgentServiceProvider` est REMPLACÉE par le
  composite (UN SEUL provider `fs_acl` compilé). PREUVE compilation :
  `FolderAccessRulesCompilationTest` — (a) règle de parc bat le broadcast
  capacité (identité égale), (b) sur maille égale la récence tranche, (c)
  identités distinctes coexistent, (d) sans règle byte-identité au provider
  capacités nu (via `bareCompiler`).
- **D3 (off réel + suppression en deux temps)** : `is_active=false` ⇒ items
  `ensure:'absent'` (pas d'extinction d'émission) ; `delete()` REFUSE une règle
  active (RuntimeException, message FR). Écart de PRÉCISION assumé vs la lettre de
  l'epic (« désactiver OU supprimer retire les items ») — motivé piège #3.
- **D9 (trustee dérivé)** : `FolderAccessRule::deriveTrustee($adDn, $name)` — CN
  de `ad_dn` (regex `(?:^|,)\s*CN=([^,]+)`, leftmost RDN = CN propre du groupe,
  ex. `CN=Classe_3A,…` → `Classe_3A`), fallback `name` verbatim. Foyer UNIQUE,
  consommé par le provider (jointure à l'émission), le service (guard) et le
  validator (overlap). Un groupe sans `ad_dn` → avertissement non bloquant
  (`missingAdDn`).
- **Guard EXPLICITE (D4)** : `FolderAccessRuleService::assertGuard()` construit la
  forme projection (trustee D9, `warning = DENY_WARNING` non vide, `ensure:present`)
  et REFUSE via `FsAclAuthoringException` si `violations()` non vide. Appelé à
  create ET update. Testé au niveau SERVICE (combo interdit `deny` descendant sur
  `C:\Windows`, deny sur groupe « Administrators », chemin non absolu, 8.3 → tous
  refusés ; aucune ligne persistée au refus).
- **Délégation scopée (piège #9)** : permissions dédiées `folderrule.view`/
  `folderrule.manage` (enum + rôles refnum/ComputerAdmin + superadmin auto,
  `isSecondaryBitPermission`), policy `FolderAccessRulePolicy` enregistrée. Le
  contrôle PAR PARC (`PermissionService::canOnWorkstationGroup`) vit dans le
  service (`attach/detachParc`) ET restreint le picker de parcs de la page détail.
  PREUVE : `FolderAccessRulePolicyTest::a_delegate_scoped_to_parc_a_cannot_assign_parc_b`.
- **Golden / FROZEN_STATE_HASH INCHANGÉ** : `ContractV1Test` (dont « state hash is
  frozen regression guard ») + `CapabilityFsAcl{Provider,Compilation,Seed}Test` +
  `StateHasherTest` + `StateCompiler*` verts SANS modification d'attendus. Zéro
  fichier `agent/**`, contrat, golden, `StateCompiler`, `FsAclAuthoringGuard`,
  `FsAclCapabilityProvider`, `AudienceTokens` touché.

**Exploitation (hors dev — à jouer sur /vm).**

1. **Migrations** à rejouer (`migrate:status` d'abord — mémoire
   `vm_migrations_not_auto_applied`) : `folder_access_rules`,
   `folder_access_rule_assignables`, `folder_access_rule_audit_logs`.
2. **Reseed `PermissionSeeder`** (sinon **403 même pour un refnum** — les perms
   `folderrule.*` n'existent pas).
3. **`route:cache`** après ajout des routes réelles (mémoire
   `route_cache_vm_ephemeral_test_routes`).
4. **AUCUNE publication agent** (2.6.0 de 36.1 suffit) — mais sans release 2.6.0
   publiée, les items `fs_acl` sont ignorés EN SILENCE par un binaire ≤ 2.5.0.
5. **e2e lab MANUEL** : cf. `docs/qa/domains/agent.md` § « Story 36.4 » (règle UI
   sur `D:\Ressources` pour une classe → Explorer refuse au membre, intact pour
   les autres ; désactivation restaure ; vérifier le trustee dérivé D9).

**Friction merge attendue** : `app/Providers/AgentServiceProvider.php` — 36.4
REMPLACE la ligne `fs_acl` par la composition, là où 36.2 (firewall) AJOUTE sa
ligne. Conflit trivial (garder les deux). Aucun autre fichier partagé.

**Sanctuaire vérifié intact** : `git diff` ne touche NI `agent/**`, NI
`tests/Fixtures/Agent/*.v1.json`, NI `StateCompiler/StateHasher/StateCandidate/
TargetContext`, NI `FsAclCapabilityProvider/FsAclAuthoringGuard/AudienceTokens`,
NI l'observer, NI le seed 36.1.

**Tests HÔTE (php 8.4 + sqlite, filtres ciblés) — TOUS VERTS.**

- Net-new : `FolderAccessRulesSchemaTest` (6), `FolderAccessRuleServiceTest` (11),
  `FolderAccessRuleValidatorTest` (7), `FolderAccessRulesProviderTest` (10),
  `FolderAccessRulesCompilationTest` (4), `FolderAccessRulePolicyTest` (4),
  `FolderRulesIndexTest` (7), `FolderRuleDetailTest` (5).
- Non-régression (attendus INCHANGÉS) : `ContractV1Test` (5, dont FROZEN_STATE_HASH),
  `CapabilityFsAcl{Provider,Compilation,Seed}Test` + `StateHasherTest` (54 total
  sur le filtre), `StateCompiler*` (29), `PermissionSeeder|SambaPermission|
  RoutesProtection` (42).

### Corrections review (post-review — 5 fixes)

Statut inchangé : `review`. Toutes les corrections confinées aux fichiers 36.4
(sanctuaire vérifié INTACT : `agent/**`, `StateCompiler/StateHasher/StateCandidate/
TargetContext`, `FsAclCapabilityProvider/FsAclAuthoringGuard/AudienceTokens`,
`state.v1.json` + `FROZEN_STATE_HASH`, observer, seed 36.1 — non touchés).

- **#1 [🔴] Délégation scopée ATTEIGNABLE de bout en bout (patron 7.1).** La
  policy vérifiait UNIQUEMENT le droit GLOBAL `folderrule.*` et les routes/actions
  posaient des gates GLOBAUX → un délégué scopé parc prenait 403 AVANT que
  `canOnWorkstationGroup` ne s'exécute. Réplique 7.1 :
  - `FolderAccessRulePolicy::viewAny()` = global OU `getAuthorizedWorkstationGroups
    ($user,'folderrule.manage')` non vide ; `view($rule)`/`manage($rule)` = global
    OU `canOnWorkstationGroup` sur AU MOINS UN parc assigné à la règle (helpers
    `hasAnyScopedParc`/`canOnAnyAssignedParc`, injectant `PermissionService`).
  - Routes `folder-rules` : middleware `can:folderrule.view` → **`can:viewAny-folderrule`**
    (gate policy-backed, comme `/app/parc` avec `can:viewAny-workstationGroup`), pour
    laisser entrer le délégué scopé avant le scoping.
  - `mount()` liste = `Gate::allows('viewAny-folderrule')` ; `mount()` détail charge
    la règle PUIS `Gate::allows('view-folderrule', $rule)`.
  - Liste (`loadRules`) : `scopedUser()` restreint les règles affichées à celles
    dont un parc est délégué à l'acteur (comme la page parc filtre par `scopedUser`).
  - `attachParc()/detachParc()` (détail) : suppression du gate GLOBAL — le contrôle
    PAR PARC est délégué au service (`canOnWorkstationGroup`, lève sinon → toast).
    `editRule/saveRule/toggleActive/deleteRule` → `Gate::allows('manage-folderrule',
    $rule)` (ressource). Création (sans règle) reste réservée au droit global.
  - PREUVE : `FolderAccessRulePolicyTest::a_delegate_scoped_to_a_parc_reaches_viewAny
    _and_manages_its_rule` (viewAny OK, view/manage règle parc A OK, parc B refusé,
    manage global refusé).

- **#2 [🟠] Non-régression AC6.** `RoutesProtectionTest` :
  `protectedRoutesProvider` + `folder-rules` (listing/show, 403 sans permission) ;
  `scopedParcRoutesProvider` + `/app/folder-rules` avec `folderrule.manage` (délégué
  scopé parc seul atteint la page). Verts.

- **#3 [🟡] `actor === null` = bypass silencieux.** Séparation contexte serveur /
  UI : nouvelles méthodes `attachParcAsSystem()/detachParcAsSystem()` (seeds/CLI,
  aucun contrôle d'acteur) ; les `attachParc()/detachParc()` UI **REFUSENT** un
  acteur `null` (`assertActorCanManageParc` lève — garde le cas guard fédéré
  renvoyant un `Authenticatable` non-`User`). Tests seeds/CLI routés vers les
  méthodes système + test `attaching_a_parc_from_ui_with_a_null_actor_is_refused`.

- **#4 [🟡] `deriveTrustee` cassait sur virgule échappée.** Aucun helper DN CN dans
  `app/` (`LdapDnHelper` n'en expose pas ; introduire LdapRecord dans le modèle
  consommé par le provider PG-pur = tension critère Keycloak) → regex DURCIE :
  `CN=((?:[^,\\]|\\.)*)` + dé-échappement `\<char>`. Test
  `FolderAccessRuleTest::it_keeps_an_escaped_comma_inside_the_cn`
  (`CN=Salle B\, annexe,OU=Groups` → `Salle B, annexe`).

- **#5 [🟡] Bouton detachParc via Gate global.** Page détail : `assignedParcs()`
  calcule `can_manage` PAR PARC (`canOnWorkstationGroup`) ; le bouton de retrait
  est conditionné `@if ($parc['can_manage'])` (plus `@can` global) ; les `@can`
  toggle/edit/delete/add-parc passent la règle en ressource (`@can('manage-folderrule',
  $rule)`) — cohérent avec le filtrage `parcCandidates`.

**Résultats tests HÔTE (php 8.4 + sqlite) :** filtre `FolderAccessRule|FolderRule|
FolderRules|RoutesProtection|FolderAccessRulePolicy` = **96 passed** ; `PermissionSeeder|
SambaPermission` = **10 passed** ; golden `ContractV1Test|CapabilityFsAcl|StateHasher`
= **54 passed** (`FROZEN_STATE_HASH` INCHANGÉ) ; `StateCompiler` = **29 passed**.

### File List

**Nouveaux fichiers**

- `database/migrations/2026_07_04_120000_create_folder_access_rules_tables.php`
- `database/migrations/2026_07_04_120100_create_folder_access_rule_audit_logs_table.php`
- `app/Models/FolderAccessRule.php`
- `app/Models/FolderAccessRuleAssignable.php`
- `app/Models/FolderAccessRuleAuditLog.php`
- `database/factories/FolderAccessRuleFactory.php`
- `app/Services/Agent/FolderAccessRuleService.php`
- `app/Services/Agent/FolderAccessRuleValidator.php`
- `app/Services/Agent/Providers/FolderAccessRulesStateProvider.php`
- `app/Policies/FolderAccessRulePolicy.php`
- `resources/views/pages/folder-rules/index.blade.php`
- `resources/views/pages/folder-rules/[id]/index.blade.php`
- `tests/Feature/Migrations/FolderAccessRulesSchemaTest.php`
- `tests/Unit/Services/Agent/FolderAccessRuleServiceTest.php`
- `tests/Unit/Services/Agent/FolderAccessRuleValidatorTest.php`
- `tests/Unit/Services/Agent/FolderAccessRulesProviderTest.php`
- `tests/Unit/Services/Agent/FolderAccessRulesCompilationTest.php`
- `tests/Feature/Policies/FolderAccessRulePolicyTest.php`
- `tests/Feature/Livewire/FolderRules/FolderRulesIndexTest.php`
- `tests/Feature/Livewire/FolderRules/FolderRuleDetailTest.php`

**Fichiers modifiés**

- `app/Providers/AgentServiceProvider.php` (1 ligne fs_acl REMPLACÉE par le
  composite — ⚠️ partagé avec 36.2)
- `app/Enums/SambaPermission.php` (+2 cases `folderrule.*` : legacyRight, label,
  category, categoryLabel, isSecondaryBitPermission)
- `app/Enums/SambaRole.php` (octrois ReferentNumerique + ComputerAdmin)
- `app/Providers/AuthServiceProvider.php` (+1 enregistrement policy)
- `routes/web.php` (+2 routes `/folder-rules[/{id}]`, `can:folderrule.view`)
- `docs/agent/state-providers.md` (note bi-alimentation § fs_acl)
- `docs/qa/domains/agent.md` (§ Story 36.4 APPEND-ONLY)
- `_bmad-output/implementation-artifacts/sprint-status.yaml` (ligne 36.4 → review)

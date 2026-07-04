# Story 36.1 : Mécanisme `fs_acl` — ACE NTFS gérées sur le poste

Status: review

<!-- Source d'autorité : _bmad-output/planning-artifacts/epics-mecanismes-hors-registre.md
     (Epic 36 ne figure PAS dans epics.md). Décisions Henri actées :
     _bmad-output/ultradev/36-questions.md (Q1 = jetons en dur v1, Q2 = liste racines telle quelle). -->

> **📌 Rappel 35.6 (gate FERMÉ — décision Henri 2026-07-03, NE PAS OUVRIR).** Cette story
> livre la résolution de SID côté agent (LSA, `FsAclOps.LookupSid`) et les jetons
> d'audience — exactement la plomberie dont le mécanisme `privilege` (Story 35.6,
> SeDeny*/RDP élèves) aurait besoin : son coût marginal s'effondre après 36.1. **On le
> NOTE, on n'ouvre RIEN** : la 35.6 ne se rouvre que si le besoin terrain « RDP interdit
> aux élèves mais autorisé aux profs sur le MÊME parc » se confirme, et un futur mécanisme
> `localgroup` la rendrait caduque. Aucune ligne de cette story ne concerne 35.6.

## Story

En tant que **référent numérique**,
je veux **restreindre (ou accorder) l'accès à un dossier du poste pour un type d'utilisateur**,
afin de **masquer Program Files aux élèves — sans casser le lancement des applications**.

## Contexte & intention

Premier mécanisme HORS-REGISTRE de la bibliothèque de capacités (doctrine Epic 36 :
« mécanisme = code payé une fois, capacité = donnée »). La demande fondatrice — « interdire
l'Explorateur sur Program Files à un type d'utilisateur » — se projette sur UNE ACE NTFS
`deny list_folder folder_only` : l'Explorateur ne peut plus ÉNUMÉRER le dossier, mais le
traverse/execute reste intact → les raccourcis vers des exe sous Program Files se lancent
toujours. C'est la variante SÛRE ; la variante dangereuse (deny à héritage descendant sur
une racine système) doit être **inexprimable** (D3 + Q2).

**Ce que la story livre** — la chaîne complète d'un nouveau type de contrat, patron exact
des stories 35.1/35.2/35.3 :

1. **Contrat v1** : type `fs_acl` additif (`semantics: exclusive`, portée **Machine**),
   payload 6 clés `{path, trustee, ace_type, rights, applies_to, ensure}` — enums fermés
   de mots métier, AUCUN masque brut ni SDDL (D3). Golden + doc §7 bumpés (hashes jumeaux).
2. **Provider serveur** : `FsAclCapabilityProvider` (scope Machine, mécanisme `fs_acl`),
   `exclusiveKey() = {path|trustee|ace_type}` normalisé, résolution des **jetons
   d'audience** `@eleves|@profs|@personnels` (Q1 : enum FERMÉ en dur, résolution
   conventionnelle vers `user_groups`, AUCUNE UI d'admin), `StateCompiler` intouché (D2).
3. **Validation d'authoring** : service pur `FsAclAuthoringGuard` (réutilisé tel quel par
   le formulaire 36.4) — deny sur principal système REFUSÉ, deny à héritage descendant sur
   racine protégée REFUSÉ (Q2), deny ⇒ `warning` capacité non vide.
4. **Handler Go `fs_acl`** (service SYSTEM uniquement) : test/apply CHIRURGICAL
   (`SetEntriesInAcl` + `SetNamedSecurityInfo` DACL-only — la DACL n'est JAMAIS réécrite,
   owner/SACL/ACE héritées/ACE tierces JAMAIS touchés, D4), résolution `DOMAIN\name → SID`
   via LSA sur le poste joint (D5, zéro SID en SQL), **store « dernier état appliqué » par
   item** (aucune ACE orpheline au changement de valeur), refus agent en défense en
   profondeur.
5. **Capacité de preuve** : seed `program_files_browse_denied` (2 ACE
   `deny list_folder folder_only` sur Program Files + Program Files (x86)).
6. Bump `agent/shared/version.go` **2.5.0 → 2.6.0** + note de publication.

**Pourquoi maintenant** : l'Epic 35 est livré (agent 2.5.0) ; 36.1 étrenne le store
« dernier appliqué » par item et les jetons d'audience que 36.2 (firewall) et 36.4
(formulaire) réutiliseront. 36.1 et 36.3 sont développées en PARALLÈLE (worktrees) ;
36.2 passe APRÈS 36.1 (fichiers partagés : contrat, version.go, golden).

## ⚠️ Pièges & tensions (lire AVANT de coder)

1. **Piège #1 — nouveau TYPE : le binaire antérieur IGNORE EN SILENCE (contrat §8).** Un
   agent ≤ 2.5.0 qui reçoit `fs_acl` n'émet AUCUN statut (type sans handler = log DEBUG).
   Symptôme : « réglage sans effet, zéro erreur ». Contrairement à HKU (35.3), il n'y a
   PAS d'effet de bord sur les autres types — l'ordre publication/migration n'est pas
   critique, mais la release 2.6.0 DOIT être publiée manuellement (update.sh ne publie
   jamais seul) sinon la capacité est inerte au parc.

2. **Piège #2 — StateCompiler INTOUCHÉ (D2), et la précédence ne bite QUE sur identité
   ÉGALE.** `exclusiveKey() = {path|trustee|ace_type}` : la maille la plus spécifique
   gagne CETTE ACE ; deux capacités gérant des ACE distinctes sur le même chemin
   coexistent (voulu, AC epic). **Conséquence assumée à documenter** : deux mailles dont
   les valeurs résolvent des TRUSTEES DIFFÉRENTS (`eleves` en broadcast, `tous` sur un
   parc) produisent des identités DISTINCTES → les DEUX ACE convergent (cumul, pas
   remplacement). Pour un deny c'est un sur-masquage bénin ; le catalogue est curaté et le
   retrait passe par le store (piège #4). La précédence par maille se PROUVE là où les
   identités collident : broadcast `off` (`ensure:absent`, trustee résolu `Eleves`) battu
   par un override de parc `eleves` (`present`, MÊME identité). Zéro ligne dans
   `app/Services/Agent/StateCompiler.php`.

3. **Piège #3 — type ABSENT du state = handler JAMAIS invoqué (engine.go itère les types
   présents).** Si l'état ne porte AUCUN item `fs_acl`, la réconciliation du store ne
   tourne pas : une ACE gérée survivrait. DEUX fenêtres d'orphelin existent donc :
   (a) valeur effective `unmanaged` (sentinelle → rien n'est émis) après avoir été armée ;
   (b) poste qui QUITTE le parc porteur de l'override. Mitigation v1 (documentée, pas
   sur-conçue) : le seed expose un **`off` réel** (« ACE retirées », items
   `ensure:absent`) — le retrait PROPRE passe par `off`, jamais par `unmanaged` ; la doc
   contrat + le commentaire de seed le disent en toutes lettres. NE PAS « corriger » en
   synthétisant des items côté serveur ni en changeant engine.go.

4. **Piège #4 — le store « dernier appliqué » est la SEULE mémoire des ACE posées.** Une
   ACE NTFS ne porte aucun marqueur de propriété (contrairement au groupe de règles
   pare-feu de 36.2) : quand la valeur change (`eleves` → `tous`), l'ancien item disparaît
   du state (identité différente) et RIEN dans la DACL ne dit que l'ACE `Eleves` est à
   nous. Le handler persiste donc, par identité d'item, l'ACE exactement posée
   `{path, trustee, sid, ace_type, mask, flags}` dans un fichier JSON dédié
   (`Store.FsAclStatePath()`, écriture atomique `WriteFileAtomic`, ProgramData racine déjà
   ACL SYSTEM). Réconciliation à CHAQUE Test/Apply : toute entrée du store dont l'identité
   n'est PLUS dans l'état désiré ⇒ non conforme ⇒ Apply retire l'ACE enregistrée PUIS la
   nouvelle est posée — aucune ACE orpheline. Store corrompu ⇒ warning + repart vide (iso
   `ReadAppliedState`), les ACE désirées re-convergent, les orphelines d'avant-corruption
   sont perdues (assumé, documenté).

5. **Piège #5 — chirurgie DACL : merge, jamais de réécriture.** `Apply` ajoute via
   `GetNamedSecurityInfo(DACL)` → `windows.ACLFromEntries([1 EXPLICIT_ACCESS], oldDacl)`
   (= SetEntriesInAcl : merge + ordre canonique deny-first géré par Windows) →
   `SetNamedSecurityInfo(DACL_SECURITY_INFORMATION seul, SANS PROTECTED_DACL_SECURITY_INFORMATION)`
   — owner, group, SACL et héritage JAMAIS touchés. Le retrait reconstruit la DACL MOINS
   l'ACE exactement égale (itération `windows.GetAce` + `DeleteAce` à l'index — `DeleteAce`
   n'est pas wrappé par x/sys : lazy proc advapi32, iso pattern existant). Une ACE déjà
   absente au retrait ⇒ succès idempotent.

6. **Piège #6 — masques SPÉCIFIQUES uniquement, jamais GENERIC_*.** Les droits génériques
   sont mappés par le noyau à l'écriture → la relecture ne serait plus byte-égale et le
   Test dériverait en boucle. Table de traduction (D3, constantes dans `shared`, doc en
   tête) : `list_folder` → `FILE_LIST_DIRECTORY` (0x1) SEUL — c'est CE qui garantit
   « masquer sans casser » (traverse/execute/read intacts) ; `read` → `FILE_GENERIC_READ`
   (composite de bits spécifiques) ; `write` → `FILE_GENERIC_WRITE` ; `modify` →
   READ|WRITE|EXECUTE|DELETE (le « Modification » de l'onglet Sécurité).
   `applies_to` → flags d'héritage : `folder_only` = 0 ; `folder_subfolders_files` =
   `CONTAINER_INHERIT|OBJECT_INHERIT` ; `subfolders_files_only` = `…|INHERIT_ONLY`.

7. **Piège #7 — résolution SID : LSA du poste, JAMAIS en SQL (D5).** `LookupSid` =
   `windows.LookupSID("", name)` (LookupAccountName — LSA locale du poste joint : noms de
   domaine ET well-known résolus). Mémo PAR PASSE uniquement (jamais entre cycles).
   Trustee irrésoluble ⇒ erreur d'ITEM (les autres items convergent, première erreur
   remontée à la fin — iso effort maximal registry). Le serveur n'écrit AUCUN SID ; le
   provider émet des NOMS (`Eleves`, `Domain Users`), lecture Postgres pure (NFR7).

8. **Piège #8 — refus agent = défense en profondeur, INDÉPENDANT du serveur.** Après
   résolution LSA, un `deny` dont le SID est well-known système ⇒ erreur d'item : `S-1-1-0`
   (Everyone), `S-1-5-11` (Authenticated Users), `S-1-5-18/19/20` (SYSTEM/LocalService/
   NetworkService), préfixe `S-1-5-32-` (BUILTIN, dont 544 Administrators), préfixe
   `S-1-5-80-` (comptes de service, dont TrustedInstaller). Chemin INEXISTANT ⇒ erreur
   d'item (JAMAIS de création de dossier). Jamais d'application partielle SILENCIEUSE :
   toute erreur d'item remonte (type `error` au rapport, grain 27.8).

9. **Piège #9 — jetons d'audience : Q1 = TOUT EN DUR, résolution conventionnelle.** Enum
   fermé dans le code : `@eleves → 'Eleves'`, `@profs → 'Profs'`,
   `@personnels → 'Administratifs'` (constantes existantes
   `App\Constants\Ldap\MainGroups` — groupes principaux GLOBAUX du domaine, cf.
   `LdapDnHelper::groups(global)` ; en SQL : `user_groups.type = 'role'`, migration
   `2026_03_31_100000`). Résolution à l'expansion : le jeton se traduit en nom
   conventionnel PUIS on vérifie que le groupe EXISTE dans `user_groups` (une requête
   mémoïsée par instance/requête HTTP) ; jeton inconnu OU groupe absent en SQL ⇒ **clé non
   émise + log warning** — JAMAIS de payload avec jeton brut. AUCUNE table d'audiences,
   AUCUN écran d'admin, AUCUN mapping configurable : le groupe arbitraire est le
   formulaire 36.4 (picker SQL), pas un jeton.

10. **Piège #10 — pas de ciblage par utilisateur : STRUCTUREL, pas un interdit à coder
    (iso 35.3 piège #4).** Provider scope Machine → le service SYSTEM fetch sans `?user`
    (`TargetContext::for($ws, null)`, `userGroupIds = []`) : un override UserGroup/User
    d'une capacité fs_acl est SANS EFFET. « Quel utilisateur est bridé » = le `trustee`
    DANS le payload (D7) ; « quels postes » = assignments parc/salle/poste/broadcast.
    À PROUVER par un test de compilation machine-only et à DOCUMENTER (guard + contrat +
    seed) — pas de garde-fou runtime (`feedback_no_overengineered_choices`).

11. **Piège #11 — golden = hashes figés JUMEAUX + comptages.** +1 item `fs_acl` en portée
    machine ⇒ recalculer le hash d'item (`StateHasher::hashItem`),
    `ContractV1Test::FROZEN_STATE_HASH` (PHP) **ET** `frozenStateHash`
    (`agent/shared/hasher_test.go`, MÊME valeur), justification écrite dans les chaînes de
    commentaires des DEUX fichiers (règle 23.1). Comptages : `contract_test.go` machine
    5→6 et types 11→12 ; `hasher_test.go` 13→14 items. `report.v1.json` INCHANGÉ avec
    justification (les items de rapport `{type,status,hash[,detail]}` ne portent pas de
    payload ; le type entre à l'ingestion par la constante additive
    `Rule::in(StateContract::RESOURCE_TYPES)`).

12. **Piège #12 — 36.3 tourne EN PARALLÈLE : ne pas partager ses fichiers de test.** 36.3
    (lot registre) ajoute sa migration + ses tests dans
    `tests/Feature/Migrations/CapabilitiesSchemaAndSeedTest.php`. Pour éviter le conflit
    de merge, les tests de seed + invariants fs_acl vivent dans un NOUVEAU fichier
    `tests/Feature/Migrations/CapabilityFsAclSeedTest.php`. Ne toucher NI
    `CapabilitiesSchemaAndSeedTest.php`, NI `CapabilitySpecCollisionGuard.php` (guard
    REGISTRE — le guard fs_acl est un service séparé), NI aucun fichier des stories
    36.2/36.3/36.4.

13. **Piège #13 — payload 6 clés CONSTANTES, `ensure` TOUJOURS explicite.** Contrairement
    à `registry` (35.1, `ensure` optionnel pour la byte-identité des payloads
    préexistants), `fs_acl` naît avec `ensure ∈ present|absent` TOUJOURS émis : type neuf,
    aucune identité historique à préserver, et un item `absent` garde `rights`/`applies_to`
    (ils décrivent l'ACE exacte à retirer). Forme UNIQUE = parse Go trivial et store
    complet. Zéro float (6 strings), pas de fuite d'id de capacité (invariant 27.12).

14. **Piège #14 — l'expand fs_acl n'a PAS de ruche.** `AbstractCapabilityStateProvider`
    déclare `hive()` abstraite et son `expand()` est registry-specific : le provider
    fs_acl SURCHARGE `expand()` intégralement (iso `AbstractRegistryListCapabilityProvider`)
    et implémente `hive(): ''` avec docblock « non applicable — jamais consommé (expand
    surchargé, handlesHive jamais appelé) ». Réutilise `resolveKeyValue()`/`UNMANAGED`/
    `SPEC_ENSURE` hérités. Les providers registry/registry_list restent BYTE-IDENTIQUES
    (tests existants verts sans modification d'attendus).

15. **Piège #15 — trustee littéral ≠ jeton : pas de vérification SQL.** Un trustee qui ne
    commence pas par `@` (ex. `Domain Users` pour la valeur `tous` du seed) part VERBATIM
    au payload — c'est l'agent qui le résout via LSA (échec ⇒ erreur d'item, visible).
    `Domain Users` est le nom Samba AD par défaut (provisioning anglophone) — **à VÉRIFIER
    sur le DC lab avant seed** (discipline « pas de clé recopiée de mémoire », iso 36.3).

## Décisions de design (tranchées — cadrage epic + décisions Henri + exploration code)

1. **D1 — contrat additif** : `'fs_acl'` s'AJOUTE à `StateContract::RESOURCE_TYPES` (PHP)
   et `ResourceTypes` (Go) ; `semantics: exclusive` ; portée Machine ; bump mineur
   documenté §9. Agents antérieurs : type ignoré silencieusement (§8) — bump + release.
2. **D2 — StateCompiler intouché** : `exclusiveKey() = strtolower(path)|strtolower(trustee)|strtolower(ace_type)`
   (3 segments) sur le provider — la maille la plus spécifique gagne CETTE ACE.
3. **D3 — enums fermés au contrat** : `ace_type ∈ allow|deny` ;
   `rights ∈ list_folder|read|write|modify` ; `applies_to ∈ folder_only|folder_subfolders_files|subfolders_files_only` ;
   `ensure ∈ present|absent` (toujours émis, piège #13) ; `path` = chemin Windows absolu.
   La traduction en masques/flags vit dans le HANDLER (code testé une fois).
4. **D4 — propriété chirurgicale** : l'agent possède SES ACE explicites identifiées (store
   « dernier appliqué ») — jamais la DACL, jamais l'owner/SACL, jamais les ACE héritées ou
   tierces.
5. **D5 — SID côté agent** (LSA `LookupAccountName` via `windows.LookupSID`) ; le serveur
   n'émet que des noms ; provider Postgres pur.
6. **D6/Q1 — jetons d'audience en dur** : `['@eleves' => 'Eleves', '@profs' => 'Profs',
   '@personnels' => 'Administratifs']` dans un petit service pur dédié
   `AudienceTokens` (map publique — 36.2+ pourront le réutiliser) ; existence vérifiée
   dans `user_groups` ; irrésoluble ⇒ non émis + warning. Zéro UI.
7. **Q2 — racines protégées TELLES QUELLES** (match EXACT, normalisé casse + backslash
   final) : `C:\`, `C:\Windows`, `C:\Program Files`, `C:\Program Files (x86)`,
   `C:\ProgramData`. REFUS d'authoring d'un `deny` à héritage descendant
   (`folder_subfolders_files`, `subfolders_files_only`) sur ces chemins ; `deny
   list_folder folder_only` y reste AUTORISÉ (masquer sans casser).
8. **Spec `fs_acl`** : `spec = { "aces": [ {path, trustee, ace_type, rights, applies_to,
   ensure?}, … ] }`. `trustee` et `ensure` sont chacun littéral OU map valeur-capacité
   (résolus via `resolveKeyValue()` hérité ; clé de map absente ⇒ sentinelle UNMANAGED ⇒
   entrée non émise) ; `ensure` défaut `present` ; le marqueur `$ensure` de 35.1 n'existe
   PAS en fs_acl (l'idiome de retrait EST `ensure: 'absent'`, première classe) — forme
   assoc inattendue ⇒ non émis défensif.
9. **Seed `program_files_browse_denied`** — enum opt-in à QUATRE valeurs (écart assumé vs
   l'enum à trois de l'epic, motivé par le piège #3 + l'invariant projet « un off proposé
   fait une VRAIE action ») : `unmanaged` « Non géré » (défaut, sentinelle),
   `off` « Parcours autorisé (ACE retirées) » (items `ensure:absent`),
   `eleves` « Masqué aux élèves » (`@eleves`), `tous` « Masqué à tous (utilisateurs du
   domaine) » (littéral `Domain Users`). `warning` NON VIDE (capacité porteuse de deny).
10. **Store agent** : `Store.FsAclStatePath()` = `<root>\fsacl-state.json`, map
    `identité (path|trustee|ace_type minuscules) → {path, trustee, sid, ace_type, mask, flags}`,
    écrit par `WriteFileAtomic` après chaque Apply effectif. Machine-only (pas de
    per-user : le type n'existe pas côté compagnon).
11. **Guard d'authoring = service pur SÉPARÉ** `FsAclAuthoringGuard` (violations nommées
    en sortie, messages FR) — PAS une extension de `CapabilitySpecCollisionGuard`
    (registre ; et 36.3 est en parallèle). Constantes publiques `PROTECTED_ROOTS`,
    `SYSTEM_TRUSTEES`, enums — le formulaire 36.4 le consommera tel quel. Exécuté par un
    invariant de test sur les données réellement seedées (authoring catalogue-first).
12. **Wiring agent** : handler `fs_acl` dans la map `Handlers` du SERVICE SYSTEM
    (`main_windows.go`) UNIQUEMENT — jamais dans `companion_windows.go`.

## Acceptance Criteria

### AC1 — Contrat v1 : type `fs_acl` publié (D1)

**Given** le contrat `se5.desired-state/v1`
**When** le type `fs_acl` est publié
**Then** `StateContract::RESOURCE_TYPES` (PHP) et `ResourceTypes` (`agent/shared/contract.go`)
gagnent `'fs_acl'` (ajout additif — `ReportRequest` et l'ingestion 24.1 l'acceptent sans
autre changement)
**And** le payload est EXACTEMENT 6 clés `{path, trustee, ace_type, rights, applies_to,
ensure}` : `ace_type ∈ allow|deny`, `rights ∈ list_folder|read|write|modify`,
`applies_to ∈ folder_only|folder_subfolders_files|subfolders_files_only`,
`ensure ∈ present|absent` (TOUJOURS émis, piège #13) — enums fermés de mots métier, AUCUN
masque brut ni SDDL (D3), zéro float, jamais d'id de capacité (27.12)
**And** `docs/agent/contract-v1.md` est mis à jour : §7 (liste des identifiants), nouvelle
sous-section **§7.7 Payload `fs_acl`** (tableau 6 clés + exemple + sémantique complète :
portée Machine, propriété chirurgicale D4, store dernier-appliqué, table de traduction
rights/applies_to indicative, refus agent piège #8, fenêtres d'orphelin piège #3 et
retrait propre par `off`, pas de ciblage par utilisateur piège #10), §9 (évolution :
nouveau type = mineur ; agent antérieur = type ignoré EN SILENCE, publication requise)
**And** le golden `state.v1.json` gagne UN item `fs_acl` en portée machine
(`{path: "C:\\Program Files", trustee: "Eleves", ace_type: "deny", rights: "list_folder",
applies_to: "folder_only", ensure: "present"}`) avec justification écrite dans
`ContractV1Test.php` ET `hasher_test.go` ; `FROZEN_STATE_HASH` (PHP) = `frozenStateHash`
(Go) recalculés à l'identique via le `StateHasher` RÉEL ; comptages ajustés
(`contract_test.go` machine 5→6, types 11→12 ; `hasher_test.go` 13→14) ;
`report.v1.json` INCHANGÉ avec justification écrite (le rapport ne porte pas de payload)
**And** le champ `ensure` et le champ `trustee` entrent dans la canonicalisation
naturellement : un test PHP + un test Go prouvent que deux items ne différant que par
`ensure` (ou par `trustee`) ont des hashes distincts (AUCUNE modification de
`StateHasher.php` ni `hasher.go`).

### AC2 — Provider serveur : expansion, jetons d'audience, compilateur intouché (D2, D6/Q1)

**Given** une capacité active portant une projection `windows/fs_acl`
**When** `FsAclCapabilityProvider` (scope Machine, `mechanism() = 'fs_acl'`, nouvelle
constante `CapabilityProjection::MECHANISM_FS_ACL`) l'expanse
**Then** chaque entrée `aces[]` de la `spec` produit AU PLUS un item 6 clés : `trustee` et
`ensure` résolus par `resolveKeyValue()` (littéral OU map valeur-capacité ; clé de map
absente ⇒ UNMANAGED ⇒ entrée non émise ; forme assoc inattendue ⇒ non émise défensif,
jamais d'exception au render) ; enums hors domaine ⇒ entrée non émise (défensif — le guard
refuse déjà en amont)
**And** un `trustee` commençant par `@` est résolu par le service `AudienceTokens` : map
EN DUR `@eleves→Eleves`, `@profs→Profs`, `@personnels→Administratifs` + vérification
d'EXISTENCE dans `user_groups` (une requête mémoïsée par requête) ; jeton inconnu OU
groupe absent ⇒ **entrée non émise + log warning** (jamais de payload avec jeton brut) ;
un trustee littéral part verbatim (piège #15) — AUCUNE UI, AUCUNE table d'audiences (Q1)
**And** `exclusiveKey(payload) = {path|trustee|ace_type}` minuscules (3 segments) ; un
test de compilation (StateCompiler INTOUCHÉ) prouve : (a) précédence broadcast/parc sur
identité ÉGALE (broadcast `off` → `ensure:absent` trustee `Eleves` battu par override de
parc `eleves` → `present`, et l'inverse) ; (b) deux ACE d'identités distinctes (même
`path`, trustees différents) COEXISTENT — comportement documenté (piège #2) ; (c) deux
capacités sur le même chemin avec trustees différents coexistent
**And** un test de compilation machine-only (`TargetContext::for($ws, null)`) prouve qu'un
override UserGroup n'atteint JAMAIS un item fs_acl (piège #10)
**And** le provider est câblé dans `AgentServiceProvider` (1 ligne, enrobage
`UpstreamAwareProvider::wrap` iso autres providers, marqueur `KeyedExclusiveProvider`
relayé) et reste Postgres pur (zéro AD/LdapRecord/APCu) ; les providers
registry/registry_list restent byte-identiques (tests existants verts sans modification).

### AC3 — Validation d'authoring : `FsAclAuthoringGuard` (Q2 + principals système)

**Given** l'ensemble des projections `windows/fs_acl` du catalogue
**When** `FsAclAuthoringGuard` (service PUR : projections en entrée, violations nommées en
sortie, messages FR explicites) les valide
**Then** sont REFUSÉS :
- un `ace_type: deny` dont le trustee (littéral, insensible à la casse, avec ou sans
  préfixe domaine) est un principal système : SYSTEM, Administrators/Administrateurs,
  TrustedInstaller, LocalService/NetworkService, Everyone/Tout le monde,
  Authenticated Users/Utilisateurs authentifiés (liste = constante publique
  `SYSTEM_TRUSTEES`) — les jetons `@…` ne sont jamais système (ils résolvent des groupes
  métier) ;
- un `deny` avec héritage descendant (`applies_to ∈ folder_subfolders_files |
  subfolders_files_only`) sur une racine protégée (`C:\`, `C:\Windows`,
  `C:\Program Files`, `C:\Program Files (x86)`, `C:\ProgramData` — match EXACT normalisé,
  constante publique `PROTECTED_ROOTS`, liste Q2 TELLE QUELLE) — `deny list_folder
  folder_only` y reste AUTORISÉ (prouvé par test) ;
- enums hors domaine (`ace_type`/`rights`/`applies_to`/`ensure`), `path` non absolu
  (lecteur `X:\…`), trustee vide, jeton d'audience inconnu (hors map `AudienceTokens`)
**And** toute capacité dont la projection porte AU MOINS une entrée `deny` exige un
`warning` non vide (violation sinon)
**And** l'enforcement est exécuté par un invariant de test sur les données réellement
seedées (dans `CapabilityFsAclSeedTest.php`, piège #12) ; le service est conçu pour être
réutilisé TEL QUEL par le formulaire 36.4 (docblock le dit).

### AC4 — Handler Go `fs_acl` : convergence chirurgicale + store (D4, D5)

**Given** le handler Go `fs_acl` (nouveau `agent/shared/handler_fs_acl.go`, instancié par
le SERVICE SYSTEM seul — `main_windows.go` ; JAMAIS le compagnon), ops injectées
`FsAclOps { LookupSid(name) (sid, err) ; ListExplicitAces(path) ([]ExplicitAce, err) ;
AddAce(path, ace) err ; RemoveAce(path, ace) err }` (impl Windows dans un nouveau
`agent/windows/handler_fs_acl_windows.go` — piège #5 ; fake en mémoire pour les tests hôte)
**When** il converge les items `fs_acl`
**Then** `Test` = pour chaque item `present` : la DACL du chemin contient une ACE
**EXPLICITE** exactement égale (SID résolu, type allow/deny, masque traduit, flags
traduits — piège #6) ; pour chaque item `absent` : aucune ACE exactement égale ; ET aucune
entrée ORPHELINE au store (identité hors état désiré avec ACE encore présente — piège #4)
⇒ conforme ssi TOUT est vrai
**And** `Apply` (effort maximal par item, première erreur remontée à la fin, idempotent —
2 passes stables = zéro op) : (1) retire d'abord les ACE des entrées orphelines du store
puis les purge du store ; (2) pour un item `present` dont l'ACE manque : si le store porte
une ACE DIFFÉRENTE pour la même identité (rights/applies_to changés), la retire d'abord ;
pose l'ACE par merge chirurgical (piège #5) ; enregistre au store ; (3) pour un item
`absent` : retire l'ACE exactement égale si présente (déjà absente = succès idempotent) et
purge l'entrée du store — la DACL n'est JAMAIS réécrite, owner/SACL/ACE héritées/ACE
tierces JAMAIS touchés (D4)
**And** refus agent (défense en profondeur, piège #8) : `deny` dont le SID résolu est
well-known système ⇒ erreur d'item ; chemin inexistant ⇒ erreur d'item (jamais de création
de dossier) ; trustee irrésoluble via LSA ⇒ erreur d'item — les AUTRES items convergent,
l'erreur remonte TOUJOURS (type `error`, jamais d'application partielle silencieuse) ;
payload statiquement invalide (clé manquante, enum inconnu) ⇒ enveloppe invalide ⇒
`{status: error}` pour le type (iso registry)
**And** la policy STRICT est démontrée À TRAVERS le moteur (`engine.go` INTOUCHÉ, iso
`TestRegistryAbsentThroughEngineStrictRedrift`) : ACE gérée supprimée à la main ⇒ `drift`
+ re-pose ; changement de valeur (trustee A → B) ⇒ `drift`, ancienne ACE retirée via le
store PUIS nouvelle posée ; état stable ⇒ `compliant`, zéro op
**And** le store est relu/écrit atomiquement à chaque passe (`Store.FsAclStatePath()`,
`WriteFileAtomic`) ; corrompu ⇒ warning + repart vide sans crash ; le SID est mémoïsé PAR
PASSE seulement (piège #7).

### AC5 — Capacité de preuve : seed `program_files_browse_denied`

**Given** la nouvelle migration `database/migrations/2026_07_04_100000_seed_capability_program_files_browse_denied.php`
(pattern iso lot CD95/35.2 : `updateOrInsert` par `key` puis par
`(capability_id, os, mechanism)`, idempotente, garde `hasTable`, `down()` par suppression
de la `key`)
**When** elle est jouée
**Then** `program_files_browse_denied` naît : enum opt-in
(`default_value = 'unmanaged'`, options `[unmanaged: 'Non géré', off: 'Parcours autorisé
(ACE retirées)', eleves: 'Masqué aux élèves', tous: 'Masqué à tous (utilisateurs du
domaine)']` — convention « sujet + état », « Non géré » réservé à la sentinelle),
`warning` NON VIDE (deny : Explorateur masqué, les applications se lancent toujours ; le
retrait propre passe par « Parcours autorisé », PAS par « Non géré » — piège #3)
**And** sa projection `windows/fs_acl` porte, PAR chemin (`C:\Program Files` et
`C:\Program Files (x86)`), DEUX entrées `deny list_folder folder_only` :
- trustee `{'eleves': '@eleves', 'off': '@eleves'}` avec `ensure {'eleves': 'present',
  'off': 'absent'}` ;
- trustee `{'tous': 'Domain Users', 'off': 'Domain Users'}` avec `ensure
  {'tous': 'present', 'off': 'absent'}`
(soit 4 entrées ; `unmanaged` absent de toutes les maps = sentinelle ; `off` émet les
items `absent` des DEUX trustees — retrait honnête quel que soit l'armement antérieur ;
le commentaire de tête documente : fenêtres d'orphelin piège #3, `Domain Users` vérifié
sur le DC lab piège #15, pas de ciblage user piège #10, décision d'écart « off ajouté »)
**And** des tests d'intégration provider sur données réelles (dans
`CapabilityFsAclSeedTest.php`) prouvent : `eleves` ⇒ 2 items deny trustee `Eleves`
(`present`) ; `tous` ⇒ 2 items trustee `Domain Users` ; `off` ⇒ 4 items `ensure:absent` ;
`unmanaged` ⇒ rien ; groupe `Eleves` ABSENT de `user_groups` ⇒ entrées `@eleves` non
émises + warning loggé ; l'invariant `FsAclAuthoringGuard` passe sur le catalogue seedé
(et le combo interdit Q2 fabriqué est bien refusé)
**And** la note e2e lab (exécution MANUELLE par l'opérateur, poste joint au domaine — hors
périmètre du dev) est écrite au Dev Agent Record : un élève ne peut plus OUVRIR
`C:\Program Files` dans l'Explorateur, ET une application installée sous Program Files
(raccourci vers l'exe) se lance toujours — pour l'élève COMME pour un prof ; retour `off`
⇒ ACE retirées, parcours restauré.

### AC6 — Version agent + note de publication

**Given** l'agent modifié
**Then** `agent/shared/version.go` est bumpé (**2.5.0 → 2.6.0**) avec entrée de changelog
(style 2.3.0/2.4.0/2.5.0 : mécanisme `fs_acl`, chirurgie DACL, store dernier-appliqué,
LSA LookupSid, refus défense en profondeur)
**And** la note de fin de story rappelle : un binaire ≤ 2.5.0 IGNORE le type `fs_acl` EN
SILENCE (§8 — aucun statut, aucune erreur) → release **2.6.0 à publier MANUELLEMENT**
(update.sh ne publie jamais seul) ; la migration de seed est **à rejouer sur /vm**
(`php artisan migrate`, jamais auto-appliquée) — l'ordre publication/migration n'est pas
critique ici (pas d'effet de bord inter-types, contrairement à HKU 35.3), mais sans
publication la capacité est inerte.

## Tasks / Subtasks

- [x] **Task 1 — Contrat & golden files (AC1)** *(commencer ici : fige le wire format)*
  - [x] 1.1 `app/Services/Agent/StateContract.php` : `'fs_acl'` dans `RESOURCE_TYPES`
        (commentaire Story 36.1, iso entrée 35.2) ; `agent/shared/contract.go` :
        `"fs_acl"` dans `ResourceTypes`.
  - [x] 1.2 `docs/agent/contract-v1.md` : §7 liste + **§7.7 Payload `fs_acl`** (tableau
        6 clés, exemple, sémantique D4/store/refus agent/fenêtres d'orphelin/retrait par
        `off`/pas de ciblage user, table indicative rights→masques) + §9 (nouveau type =
        mineur, silence binaire antérieur).
  - [x] 1.3 `tests/Fixtures/Agent/state.v1.json` : +1 item machine
        `{"type":"fs_acl","semantics":"exclusive","payload":{"path":"C:\\Program Files","trustee":"Eleves","ace_type":"deny","rights":"list_folder","applies_to":"folder_only","ensure":"present"},"hash":"<recalculé via StateHasher::hashItem>"}`.
  - [x] 1.4 `ContractV1Test.php` : `FROZEN_STATE_HASH` recalculé + justification 36.1 dans
        la chaîne de commentaires (règle 23.1). `agent/shared/hasher_test.go` :
        `frozenStateHash` = MÊME valeur + justification + comptage 13→14.
        `agent/shared/contract_test.go` : machine 5→6, types 11→12. `loop_test.go` :
        vérifier (normalement rien).
  - [x] 1.5 Tests hash : PHP (`StateHasherTest`) et Go (`hasher_test.go`) — deux items
        fs_acl ne différant que par `ensure` (et par `trustee`) ⇒ hashes distincts ;
        hashers INTOUCHÉS. Justification écrite `report.v1.json` INCHANGÉ.
- [x] **Task 2 — Provider PHP + jetons + guard (AC2, AC3)**
  - [x] 2.1 `app/Models/CapabilityProjection.php` : `public const MECHANISM_FS_ACL = 'fs_acl';`
        (+ docblock portée Machine).
  - [x] 2.2 NOUVEAU `app/Services/Agent/Providers/AudienceTokens.php` : map publique
        `TOKENS = ['@eleves' => 'Eleves', '@profs' => 'Profs', '@personnels' => 'Administratifs']`
        (réutilise `MainGroups::*`), `resolve(string $trustee): ?string` (non-jeton ⇒
        verbatim ; jeton connu ⇒ nom conventionnel SI existant dans `user_groups`, requête
        mémoïsée par instance ; sinon null), docblock Q1 (enum fermé v1, zéro UI, groupe
        arbitraire = 36.4).
  - [x] 2.3 NOUVEAU `app/Services/Agent/Providers/FsAclCapabilityProvider.php` (extends
        `AbstractCapabilityStateProvider`) : `scope() = Machine`, `mechanism() =
        MECHANISM_FS_ACL`, `hive() = ''` (piège #14), `exclusiveKey()` 3 segments,
        `expand()` surchargé (spec `aces[]`, résolution trustee/ensure via
        `resolveKeyValue()`, jetons via `AudienceTokens` + warning si irrésoluble, enums
        bornés défensifs, payload EXACTEMENT 6 clés strings).
  - [x] 2.4 `app/Providers/AgentServiceProvider.php` : +1 ligne provider (commentaire
        36.1, enrobage `UpstreamAwareProvider::wrap` iso autres).
  - [x] 2.5 NOUVEAU `app/Services/Agent/Providers/FsAclAuthoringGuard.php` (service pur —
        AC3) : constantes publiques `PROTECTED_ROOTS` (Q2 telle quelle), `SYSTEM_TRUSTEES`,
        enums ; normalisation path (casse, backslash final) ; violations FR nommant
        capacité + chemin + trustee ; règle « deny ⇒ warning non vide » ; docblock
        (réutilisation 36.4, pas de ciblage user piège #10, différence avec le guard
        registre).
  - [x] 2.6 NOUVEAU `tests/Unit/Services/Agent/CapabilityFsAclProviderTest.php` :
        (a) expansion 6 clés (strings, pas de fuite d'id) ; (b) map trustee/ensure +
        sentinelle UNMANAGED + assoc inattendue non émise ; (c) jeton résolu (user_groups
        seedé en test) ; (d) jeton inconnu / groupe absent ⇒ non émis + warning ;
        (e) littéral verbatim ; (f) enums hors domaine non émis ; (g) `exclusiveKey` 3
        segments minuscules ; (h) provider Postgres pur (grep AD/APCu iso existant).
  - [x] 2.7 NOUVEAU `tests/Unit/Services/Agent/CapabilityFsAclCompilationTest.php`
        (StateCompiler INTOUCHÉ) : précédence sur identité égale (broadcast off/absent vs
        parc eleves/present, DANS LES DEUX SENS) ; coexistence identités distinctes
        (trustees différents — comportement documenté piège #2) ; deux capacités même
        chemin ; compile machine-only (override UserGroup sans effet, piège #10).
  - [x] 2.8 Tests unitaires du guard (dans `CapabilityFsAclProviderTest` ou fichier dédié) :
        deny principal système refusé (avec/sans préfixe domaine, casse) ; deny descendant
        sur CHAQUE racine protégée refusé ; `deny list_folder folder_only` sur Program
        Files AUTORISÉ ; racine non protégée + descendant autorisé ; deny sans warning
        refusé ; jeton inconnu refusé.
- [x] **Task 3 — Handler Go + store + impl Windows (AC4, AC6)**
  - [x] 3.1 `agent/shared/files.go` : `FsAclStatePath()` sur `Store` (+ const fichier,
        doc : store dernier-appliqué fs_acl, machine-only, dogme 23.1).
  - [x] 3.2 NOUVEAU `agent/shared/handler_fs_acl.go` : types `ExplicitAce {SID, AceType,
        Mask, Flags}` + `FsAclOps` (4 ops, doc de sémantique/idempotence par op) ;
        constantes masques/flags (piège #6, doc table D3) ; parse strict payload 6 clés
        (enums bornés, `error` enveloppe sinon) ; dédoublonnage par identité (dernière
        occurrence, ordre trié — iso `desiredSpecs`) ; refus deny well-known (piège #8,
        liste de préfixes/SIDs documentée) ; Test/Apply selon AC4 (orphelins du store,
        remplacement d'ACE même identité, effort maximal, idempotence) ; lecture/écriture
        du store JSON (corrompu ⇒ warning + vide) ; mémo SID par passe ; doc de tête
        (D4 : le handler possède SES ACE identifiées par le store, jamais la DACL).
  - [x] 3.3 NOUVEAU `agent/windows/handler_fs_acl_windows.go` : impl `FsAclOps` —
        `LookupSid` = `windows.LookupSID("", name)` → `sid.String()` ; `ListExplicitAces`
        = `GetNamedSecurityInfo(DACL)` + itération `GetAce` en NE gardant que les ACE
        NON héritées (flag `INHERITED_ACE` exclu), allow+deny ; `AddAce` =
        `ACLFromEntries` (merge) + `SetNamedSecurityInfo(DACL seul, sans PROTECTED)` ;
        `RemoveAce` = reconstruction moins l'ACE exactement égale (`DeleteAce` par lazy
        proc advapi32), absente ⇒ nil ; chemin inexistant ⇒ erreur typée (jamais de
        création).
  - [x] 3.4 `agent/windows/main_windows.go` : entrée `"fs_acl"` dans la map `Handlers` du
        MachineEngine (commentaire 36.1 : chirurgie DACL, store, SYSTEM seul) —
        `companion_windows.go` INTOUCHÉ.
  - [x] 3.5 NOUVEAU `agent/shared/handler_fs_acl_test.go` (fake `FsAclOps` en mémoire) :
        (a) pose d'ACE + relecture conforme + 2e Apply zéro op ; (b) ACE supprimée à la
        main ⇒ re-drift STRICT À TRAVERS le moteur ; (c) changement de trustee ⇒ ancienne
        ACE retirée via store PUIS nouvelle posée (aucune orpheline) ; (d) changement de
        rights même identité ⇒ remplacement propre ; (e) `ensure:absent` retire, déjà
        absente = compliant idempotent ; (f) orphelin de store réconcilié ; (g) store
        corrompu ⇒ warning + repart vide ; (h) deny SID système ⇒ erreur d'item ISOLÉE
        (les autres convergent, type error) ; (i) chemin inexistant ⇒ erreur d'item ;
        (j) trustee irrésoluble ⇒ erreur d'item ; (k) payload invalide ⇒ error type ;
        (l) ACE tierces/héritées jamais touchées (le fake l'atteste) ; (m) mémo SID par
        passe (compteur du fake).
  - [x] 3.6 `agent/shared/version.go` : bump **2.6.0** + entrée changelog.
- [x] **Task 4 — Seed de preuve (AC5)**
  - [x] 4.1 NOUVELLE migration `2026_07_04_100000_seed_capability_program_files_browse_denied.php`
        (pattern 35.2 exact ; commentaires de tête : 4 entrées de spec, off réel + écart
        assumé vs enum epic, fenêtres d'orphelin, Domain Users à vérifier lab, pas de
        ciblage user).
  - [x] 4.2 NOUVEAU `tests/Feature/Migrations/CapabilityFsAclSeedTest.php` (piège #12 —
        fichier DÉDIÉ, ne pas toucher `CapabilitiesSchemaAndSeedTest.php`) : seed
        (options/défaut/warning/projection 4 entrées), idempotence/réversibilité,
        intégration provider sur données réelles (eleves/tous/off/unmanaged + groupe
        absent ⇒ warning), invariant `FsAclAuthoringGuard` sur le catalogue seedé + combo
        Q2 fabriqué refusé.
- [x] **Task 5 — Validation finale + docs**
  - [x] 5.1 Tests HÔTE ciblés (php8.4 + sqlite, JAMAIS de run massif) :
        `ContractV1Test|StateHasherTest`,
        `CapabilityFsAclProviderTest|CapabilityFsAclCompilationTest`,
        `CapabilityFsAclSeedTest`, non-régression
        `CapabilityRegistryProviderTest|CapabilityRegistryListProviderTest|CapabilityRegistryCompilationTest`.
  - [x] 5.2 Tests Go (`~/go-toolchain/go/bin/go`, hors PATH) : `cd agent && go test ./...` ;
        `GOOS=windows go build ./...` ; `go vet ./...` (linux ET GOOS=windows).
  - [x] 5.3 `docs/agent/state-providers.md` : section `fs_acl` (mécanisme, exclusiveKey,
        store, jetons d'audience, limites piège #2/#3). `docs/qa/domains/agent.md` :
        section « Story 36.1 » append-only (scénarios : masquage élève + lancement appli
        OK, changement d'audience sans orpheline, off, binaire antérieur silencieux,
        e2e lab manuel poste joint domaine).
  - [x] 5.4 Dev Agent Record : (a) justification golden (item ajouté, hashes jumeaux) ;
        (b) ⚠️ release **2.6.0 à publier manuellement** + migration **à rejouer sur /vm** ;
        (c) note e2e lab MANUEL (AC5 — poste joint domaine, deux faces : masquage +
        lancement) ; (d) rappel 35.6 : gate fermé, plomberie SID livrée, RIEN ouvert.

## Dev Notes

### Fichiers à toucher (exhaustif prévu)

| Fichier | Nature |
|---|---|
| `app/Services/Agent/StateContract.php` | `RESOURCE_TYPES` += `'fs_acl'` |
| `agent/shared/contract.go` | `ResourceTypes` += `"fs_acl"` |
| `docs/agent/contract-v1.md` | §7 liste + §7.7 payload fs_acl + §9 |
| `docs/agent/state-providers.md` | section `fs_acl` |
| `docs/qa/domains/agent.md` | section 36.1 append-only |
| `tests/Fixtures/Agent/state.v1.json` | +1 item fs_acl (machine) |
| `tests/Unit/Services/Agent/ContractV1Test.php` | `FROZEN_STATE_HASH` + justification |
| `tests/Unit/Services/Agent/StateHasherTest.php` | tests hash ensure/trustee fs_acl |
| `agent/shared/hasher_test.go` | `frozenStateHash` jumeau + 13→14 + tests |
| `agent/shared/contract_test.go` | machine 5→6, types 11→12 |
| `app/Models/CapabilityProjection.php` | const `MECHANISM_FS_ACL` |
| `app/Services/Agent/Providers/AudienceTokens.php` | NOUVEAU — jetons Q1 en dur |
| `app/Services/Agent/Providers/FsAclCapabilityProvider.php` | NOUVEAU — provider Machine |
| `app/Services/Agent/Providers/FsAclAuthoringGuard.php` | NOUVEAU — guard Q2 + principals |
| `app/Providers/AgentServiceProvider.php` | +1 provider au StateCompiler |
| `tests/Unit/Services/Agent/CapabilityFsAclProviderTest.php` | NOUVEAU |
| `tests/Unit/Services/Agent/CapabilityFsAclCompilationTest.php` | NOUVEAU |
| `agent/shared/files.go` | `Store.FsAclStatePath()` |
| `agent/shared/handler_fs_acl.go` | NOUVEAU — handler + FsAclOps + store |
| `agent/shared/handler_fs_acl_test.go` | NOUVEAU — fake + 13 tests |
| `agent/windows/handler_fs_acl_windows.go` | NOUVEAU — impl Win32 (LSA, DACL) |
| `agent/windows/main_windows.go` | +1 entrée handler (SYSTEM) |
| `agent/shared/version.go` | bump 2.6.0 + changelog |
| `database/migrations/2026_07_04_100000_seed_capability_program_files_browse_denied.php` | NOUVEAU seed |
| `tests/Feature/Migrations/CapabilityFsAclSeedTest.php` | NOUVEAU (fichier dédié, piège #12) |

**NE PAS TOUCHER** : `app/Services/Agent/StateCompiler.php` (D2), `StateHasher.php` +
`agent/shared/hasher.go` (canonicalisation générique — seulement des tests),
`agent/shared/engine.go` (grain par type + STRICT figés), `agent/windows/companion_windows.go`
(fs_acl = machine-only), `AbstractCapabilityStateProvider.php` (aucune modification
nécessaire : `expand()`/`resolveKeyValue()`/`UNMANAGED` déjà `protected` depuis 35.2 ;
`hive()` implémentée `''` dans la classe FILLE), `CapabilitySpecCollisionGuard.php` +
`CapabilitiesSchemaAndSeedTest.php` (guard/tests REGISTRE — 36.3 en parallèle y écrit,
piège #12), providers/handlers registry et registry_list (byte-identiques),
`RegistryUpstreamAdapter`/`UpstreamLockCollisionDetector` (canal amont registry-only, hors
scope), `tests/Fixtures/Agent/report.v1.json` (inchangé justifié), seeds antérieurs,
`sprint-status.yaml` (hors sa propre ligne) / `backlog.*` / `routes/web.php`
(orchestrateur), tout fichier des stories 36.2/36.3/36.4.

### Patterns existants à imiter

- **Chaîne de justification golden** : commentaires numérotés `ContractV1Test.php` et
  `hasher_test.go` (dernière entrée 35.2) — ajouter l'entrée 36.1 dans le MÊME style,
  hash jumeau vérifié croisé, recalcul via le `StateHasher` RÉEL (script scratchpad hôte).
- **Provider à expand surchargé** : `AbstractRegistryListCapabilityProvider` (mécanisme
  paramétré, `resolveKeyValue()` hérité, défensif sans exception au render) — le provider
  fs_acl suit EXACTEMENT cette discipline, en classe concrète unique (une portée, pas de
  variante ruche).
- **Handler Go + ops injectées + fake** : `handler_registry.go`/`handler_registry_list.go`
  (parse strict, dédoublonnage par identité, effort maximal, `firstErr`, tests à travers
  le moteur `TestRegistryAbsentThroughEngineStrictRedrift`) ; le fake `FsAclOps` est un
  NOUVEAU fake en mémoire (le `fakeRegistryOps` est registre — ne pas le détourner).
- **Store JSON atomique** : `ReadAppliedState`/`WriteAppliedState` +
  `WriteFileAtomic` (`sessionstore.go`) — même style (corrompu ⇒ warning + vide, jamais de
  crash) ; chemin via `Store` (`files.go`), ACL héritée de la racine SYSTEM.
- **Guard = service pur + invariant sur données seedées** : `CapabilitySpecCollisionGuard`
  (violations nommées, constantes publiques, exécution par test Feature) — le guard fs_acl
  duplique le PATTERN dans un service séparé.
- **Seed** : `2026_07_03_110000_seed_capabilities_registry_list_lot.php` (doctrine en
  tête, `updateOrInsert` double niveau, idempotence + réversibilité testées).
- **Wiring compilateur** : `AgentServiceProvider` — « ajouter un type = ajouter UNE
  ligne », commentaire de story attenant.

### Rappels transverses (garde-fous epic)

- Contrat **additif uniquement** (D1) ; golden AVEC justification (règle 23.1) ; hashes
  figés JUMEAUX PHP⇄Go.
- **Drift policy STRICT** (27.8) — verdict PAR TYPE ; **zéro float** (payload 6 strings) ;
  **zéro AD/LdapRecord/APCu** dans les providers (critère Keycloak) — la résolution SID
  est côté POSTE (LSA), le serveur ne manipule que des noms.
- Validation d'authoring serveur **ET** refus agent (défense en profondeur) — le serveur
  peut avoir tort, l'agent ne casse jamais le poste.
- Tests PHP sur l'**HÔTE** (php8.4 + sqlite), **filtres ciblés uniquement** (un run massif
  VM produit de faux échecs) ; tests Go via `~/go-toolchain/go/bin/go` (hors PATH).
- Migration **à rejouer sur /vm** = à SIGNALER (Dev Agent Record), jamais exécutée par le
  dev ; toute modif `agent/**` ⇒ bump `agent/shared/version.go` ; **update.sh ne publie
  jamais seul** — binaire antérieur = type ignoré EN SILENCE.
- e2e lab (poste joint domaine, résolution SID/groupes impossible à simuler ailleurs) =
  MANUEL opérateur, hors périmètre du dev — consigner le protocole au runbook QA.

### Project Structure Notes

- Serveur : tout vit sous `app/Services/Agent/Providers/` (provider + jetons + guard) ;
  aucune UI dans cette story (la capacité apparaît automatiquement dans les surfaces
  capacités existantes — options data-driven ; le formulaire « règles d'accès » = 36.4).
- Agent : logique de convergence + store dans `agent/shared/` (testée hôte via fake) ;
  `agent/windows/` n'apporte que l'impl `FsAclOps` (~150 lignes Win32) + 1 ligne de wiring.
- `machine_user` et Session ne sont pas concernés (portée Machine unique, D7).

### References

- [Source: _bmad-output/planning-artifacts/epics-mecanismes-hors-registre.md#Story-36.1 +
  #Décisions-structurantes (D1–D8) + #Garde-fous-d'epic + #Overview] — autorité de cadrage
- [Source: _bmad-output/ultradev/36-questions.md — Q1 (jetons en dur v1 minimal),
  Q2 (liste racines protégées telle quelle)] — décisions Henri IMPÉRATIVES
- [Source: _bmad-output/implementation-artifacts/35-1-verbe-ensure-present-absent-registry.md,
  35-2-type-registry-list-listes-indexees.md, 35-3-ruche-hku-ecriture-system.md] — patron
  contrat additif / provider / handler / golden / bump / guard
- [Source: docs/agent/contract-v1.md §4 (canonicalisation, zéro float), §7 (identifiants
  figés), §8 (type absent = non géré — silence binaire antérieur), §9 (règle d'évolution)]
- [Source: app/Services/Agent/Providers/AbstractCapabilityStateProvider.php (expand/
  resolveKeyValue/UNMANAGED protected, broadcast + overrides par maille) +
  AbstractRegistryListCapabilityProvider.php (expand surchargé) +
  CapabilitySpecCollisionGuard.php (pattern guard) + app/Providers/AgentServiceProvider.php]
- [Source: app/Constants/Ldap/MainGroups.php (Eleves/Profs/Administratifs) +
  database/migrations/2026_03_31_100000_update_user_group_types_role_function.php
  (type 'role') + app/Config/LdapDnHelper.php (groupes principaux GLOBAUX)]
- [Source: agent/shared/engine.go (types présents seulement — fondement piège #3),
  agent/shared/files.go (Store), agent/shared/sessionstore.go (WriteFileAtomic,
  Read/WriteAppliedState), agent/shared/handler_registry_list.go (réconciliation par
  conteneur), agent/windows/main_windows.go (map Handlers SYSTEM)]
- Mémoires projet : `project_capability_mechanisms_direction`,
  `project_capability_value_map_symmetric_rule` (off réel), `project_drift_policy_strict_only`,
  `project_agent_handler_not_in_published_binary`, `project_agent_runtime_go`,
  `feedback_agent_edit_bump_version`, `feedback_no_overengineered_choices`,
  `project_state_precedence_logical_over_physical`.

## Dépendances

- **En amont (intra-epic) : AUCUNE.** 36.1 ne dépend que de l'existant livré : Epic 35
  mergé sur main (provider abstrait généralisé 35.2 — `mechanism()` + visibilités
  `protected` ; agent 2.5.0), contrat 23.1, capability-first 27.12, STRICT 27.8.
- **En parallèle : 36.3** (lot registre, worktree séparé) — ne toucher AUCUN de ses
  fichiers ; les tests de seed fs_acl vivent dans un fichier DÉDIÉ
  (`CapabilityFsAclSeedTest.php`) pour éviter le conflit de merge sur
  `CapabilitiesSchemaAndSeedTest.php` (piège #12).
- **En aval** :
  - **36.2 (firewall) passe APRÈS 36.1** (fichiers partagés : `StateContract.php`,
    `contract.go`, `version.go`, golden + hashes jumeaux, `contract-v1.md`) — elle
    réutilise aussi le patron ops/fake et peut réutiliser `AudienceTokens` si besoin ;
  - **36.4 (formulaire règles d'accès) DÉPEND de 36.1** : le mécanisme (contrat + handler
    + store), `FsAclAuthoringGuard` (réutilisé TEL QUEL, messages FR) et l'`exclusiveKey`
    partagée `{path|trustee|ace_type}` sont livrés ici ;
  - **35.6 (privilege)** : gate FERMÉ — 36.1 livre la plomberie LSA (`LookupSid`) qu'elle
    réutiliserait, mais RIEN n'est ouvert (note d'en-tête).

## Recommandation Modèle Dev

**fable** — prescription explicite de l'epic (garde-fous : « fable pour 36.1 et 36.2 »,
agent Go + design sécurité) et mémoire `feedback_epic23_model_fable5`. Profil-type
confirmé par l'exploration : nouveau type de contrat cross-language (RESOURCE_TYPES ⇄
handler Go ⇄ golden hashes jumeaux), sémantique de convergence délicate (chirurgie DACL
merge/retrait exact, store dernier-appliqué avec réconciliation d'orphelins, identités à
trustee variable) et surface sécurité réelle (refus deny système en double rideau, racines
protégées Q2). Aucune raison de dévier.

## Dev Agent Record

### Agent Model Used

Opus 4.8 (1M context) — dev-story (ultradev vague 1, worktree `ultradev/36-1`).

### Debug Log References

- Golden : hash d'item + hash d'état calculés via le `StateHasher` PHP RÉEL
  (`php -r` sur l'hôte, worktree). `FROZEN_STATE_HASH` (PHP) = `frozenStateHash`
  (Go) = `6a41357d8a1ef725afc48c63cba67d5f097ea9844daa101e9303a333edff94a8` ;
  hash de l'item fs_acl golden = `a8f1c92bd6e067a7f5c817047552b6d1dec1e1ba8fb29e4e0677aa45ab7df0e9`
  (figé dans les tests jumeaux PHP↔Go). Parité prouvée par
  `TestHashStateGoldenMatchesFrozenHash` (Go) + `state_hash_is_frozen_regression_guard` (PHP).
- Tests HÔTE ciblés :
  - PHP (php 8.4, sqlite :memory:) : `ContractV1Test|StateHasherTest` = 16 passed ;
    `CapabilityFsAclProviderTest|CapabilityFsAclCompilationTest|CapabilityFsAclSeedTest`
    = 36 passed ; non-régression
    `CapabilityRegistryProviderTest|CapabilityRegistryListProviderTest|CapabilityRegistryCompilationTest`
    = 50 passed (byte-identiques, aucun attendu modifié).
  - Go (`~/go-toolchain/go/bin/go`) : `go test ./...` = ok (shared + provision) ;
    `GOOS=windows go build ./...` OK ; `GOOS=windows go vet ./...` OK ; `go vet ./...` (linux) OK.
- Note d'environnement worktree : `.env` copié depuis le repo principal +
  `bootstrap/cache/` créé (artisan ne bootait pas sinon — APCu absent de l'hôte,
  phpunit.xml force `CACHE_DRIVER=array`). Ni l'un ni l'autre commité.

### Completion Notes List

- **Contrat additif `fs_acl`** ajouté jumeau (`StateContract::RESOURCE_TYPES` +
  `contract.go ResourceTypes`), doc §7 liste + §7.7 + §9. Golden +1 item machine,
  comptages ajustés (`contract_test.go` machine 5→6, types 11→12 ; `hasher_test.go`
  13→14). `report.v1.json` INCHANGÉ (les items de rapport ne portent pas de payload).
- **Provider** `FsAclCapabilityProvider` (scope Machine, `expand()` surchargé,
  `hive()=''` piège #14), service `AudienceTokens` (Q1 en dur + existence
  `user_groups`), guard pur `FsAclAuthoringGuard` (Q2 + `SYSTEM_TRUSTEES` +
  deny⇒warning). Câblé dans `AgentServiceProvider` (1 ligne, wrap `UpstreamAwareProvider`).
  `StateCompiler` INTOUCHÉ (D2) — prouvé par `CapabilityFsAclCompilationTest`.
- **Handler Go** `FsAclHandler` (shared, testé hôte via fake) : Test/Apply, store
  « dernier appliqué » (`Store.FsAclStatePath()`), réconciliation d'orphelins,
  remplacement d'ACE même identité, effort maximal `firstErr`, refus défense en
  profondeur (SID système / chemin inexistant / trustee irrésoluble), mémo SID par
  passe. Impl Win32 `handler_fs_acl_windows.go` : LSA `LookupSID`,
  `GetNamedSecurityInfo`+`ACLFromEntries`+`SetNamedSecurityInfo` (DACL-only, SANS
  PROTECTED), `GetAce`/`DeleteAce` en lazy proc advapi32. `main_windows.go` : entrée
  `fs_acl` dans la map `Handlers` du service SYSTEM (companion INTOUCHÉ).
- **Seed** `program_files_browse_denied` : enum opt-in `unmanaged/off/eleves/tous`
  (écart assumé « off réel » vs enum epic, motivé par le piège #3), 4 entrées de
  spec (2 chemins × 2 trustees). `off` émet 4 items `absent` (retrait honnête).
- **⚠️ Release 2.6.0 à PUBLIER MANUELLEMENT** (`update.sh` ne publie jamais seul) —
  un binaire ≤ 2.5.0 IGNORE le type `fs_acl` EN SILENCE (contrat §8). **Migration
  de seed à REJOUER sur /vm** (`php artisan migrate`, jamais auto-appliquée ;
  `migrate:status` d'abord). L'ordre publication/migration n'est PAS critique ici
  (pas d'effet de bord inter-types, contrairement à HKU 35.3), mais sans
  publication la capacité est inerte.
- **⚠️ `Domain Users`** (valeur `tous`) est le nom Samba AD par défaut — trustee
  littéral résolu par LSA côté poste : **à VÉRIFIER sur le DC lab AVANT armement**
  de la valeur `tous` (piège #15 ; le worktree ne touche pas le lab).
- **e2e lab MANUEL** (poste joint au domaine, résolution SID/groupes impossible à
  simuler ailleurs) : LES DEUX FACES — (1) un ÉLÈVE ne peut plus OUVRIR/énumérer
  `C:\Program Files` ni `C:\Program Files (x86)` dans l'Explorateur ; (2) une
  application installée sous Program Files se lance TOUJOURS (raccourci → exe),
  pour l'élève COMME pour un prof ; retour `off` ⇒ ACE retirées, parcours
  restauré. Protocole complet au runbook QA (`docs/qa/domains/agent.md`, §36.1).
- **Rappel 35.6 (gate FERMÉ — NE PAS OUVRIR)** : cette story livre la plomberie
  SID (`FsAclOps.LookupSid` via LSA) et les jetons d'audience que le mécanisme
  `privilege` réutiliserait — RIEN n'est ouvert.

### File List

**Contrat & golden**
- `app/Services/Agent/StateContract.php` — `RESOURCE_TYPES += 'fs_acl'`
- `agent/shared/contract.go` — `ResourceTypes += "fs_acl"`
- `docs/agent/contract-v1.md` — §7 liste + §7.7 payload fs_acl + §9
- `tests/Fixtures/Agent/state.v1.json` — +1 item fs_acl (machine)
- `tests/Unit/Services/Agent/ContractV1Test.php` — `FROZEN_STATE_HASH` + justification
- `tests/Unit/Services/Agent/StateHasherTest.php` — tests hash ensure/trustee fs_acl
- `agent/shared/hasher_test.go` — `frozenStateHash` jumeau + 13→14 + tests fs_acl
- `agent/shared/contract_test.go` — machine 5→6, types 11→12

**Provider serveur**
- `app/Models/CapabilityProjection.php` — const `MECHANISM_FS_ACL`
- `app/Services/Agent/Providers/AudienceTokens.php` — NOUVEAU (jetons Q1 en dur)
- `app/Services/Agent/Providers/FsAclCapabilityProvider.php` — NOUVEAU (provider Machine)
- `app/Services/Agent/Providers/FsAclAuthoringGuard.php` — NOUVEAU (guard Q2 + principals)
- `app/Providers/AgentServiceProvider.php` — +1 provider au StateCompiler
- `tests/Unit/Services/Agent/CapabilityFsAclProviderTest.php` — NOUVEAU (provider + guard)
- `tests/Unit/Services/Agent/CapabilityFsAclCompilationTest.php` — NOUVEAU (StateCompiler intouché)

**Handler agent**
- `agent/shared/files.go` — `Store.FsAclStatePath()`
- `agent/shared/handler_fs_acl.go` — NOUVEAU (handler + FsAclOps + store)
- `agent/shared/handler_fs_acl_test.go` — NOUVEAU (fake + scénarios a–m)
- `agent/windows/handler_fs_acl_windows.go` — NOUVEAU (impl Win32 LSA + DACL)
- `agent/windows/main_windows.go` — +1 entrée handler (SYSTEM)
- `agent/shared/version.go` — bump 2.6.0 + changelog

**Seed & QA**
- `database/migrations/2026_07_04_100000_seed_capability_program_files_browse_denied.php` — NOUVEAU seed
- `tests/Feature/Migrations/CapabilityFsAclSeedTest.php` — NOUVEAU (fichier dédié, piège #12)
- `docs/agent/state-providers.md` — section `fs_acl`
- `docs/qa/domains/agent.md` — section « Story 36.1 » (append-only)

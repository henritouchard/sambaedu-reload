# Story 35.6 : Mécanisme `privilege` — droits LSA `SeDeny*`

Status: review

<!-- Source d'autorité (double) :
     - _bmad-output/planning-artifacts/epics-capacites-v2.md#Story-35.6 (scope du mécanisme
       privilege + tableau couverture GPO CD95 Blocages_eleves → rdp_denied_for_group) ;
     - _bmad-output/planning-artifacts/epics-mecanismes-hors-registre.md (doctrine mécanismes,
       décisions D1–D8, garde-fous d'epic — le mécanisme `privilege` y est nommé comme
       « même patron, à cadrer quand le besoin arrive », note 35.6 ↔ 36.1 l.133-141).
     Ni l'Epic 35 ni l'Epic 36 ne figurent dans epics.md. -->

> **📌 RÉOUVERTURE (décision Henri 2026-07-04).** Cette story était **GATED** (gate D6 fermé,
> `_bmad-output/ultradev/35-questions.md` Q1, réponse A du 2026-07-03). Henri la **rouvre
> explicitement** parce que **(a)** le besoin terrain « RDP interdit aux élèves mais autorisé
> aux profs sur le MÊME parc » est CONFIRMÉ — le per-parc `remote_desktop_enabled=off` est
> machine-wide et ne distingue pas élèves/profs sur un même poste ; **(b)** l'**Epic 36 est
> LIVRÉ** (fs_acl 2.6.0 + firewall 2.7.0) : il a payé TOUTE la plomberie réutilisable
> (résolution SID côté agent via LSA `windows.LookupSID`, jetons d'audience `AudienceTokens`,
> patron provider / authoring-guard / handler / golden jumeaux / bump+publication). Le coût
> marginal du mécanisme `privilege` s'est **effondré**. **Cette story RÉUTILISE cette
> plomberie, elle ne la refait PAS.**

## Story

En tant que **référent numérique**,
je veux **refuser un droit de logon Windows à un groupe (ex. RDP pour les élèves)**,
afin de **couvrir la dernière brique de la GPO « Blocages élèves » — sans interdire le RDP
aux profs sur le même parc**.

## Contexte & intention

Dernière brique non couverte de la GPO CD95 **Blocages_eleves** (cf. tableau couverture,
`epics-capacites-v2.md` fin de fichier) : `registry_editing_disabled` (livré), `blocked_executables`
(35.2/35.4, dont mstsc) et **`rdp_denied_for_group`** (cette story). Le besoin — « les élèves
ne peuvent pas ouvrir de session RDP, mais les profs oui, sur le MÊME parc » — n'est atteignable
NI par `remote_desktop_enabled=off` (per-parc, machine-wide : coupe RDP pour TOUT le monde) NI
par un blocage d'exe (bloque le client `mstsc.exe` local, pas l'ouverture de session RDP
ENTRANTE). La réponse Windows canonique est un **droit de logon refusé** : le privilège LSA
`SeDenyRemoteInteractiveLogonRight` accordé au **groupe des élèves** — Windows refuse alors
l'ouverture de session Bureau à distance à tout membre du groupe, en laissant les autres
(profs) passer.

**Ce que la story livre** — la chaîne complète d'un nouveau mécanisme, patron EXACT des
stories 36.1 (`fs_acl`) / 36.2 (`firewall`) :

1. **Contrat v1** : type `privilege` additif (`semantics: exclusive`, portée **Machine**),
   payload 2 clés `{privilege, accounts}` — `privilege` = un nom de droit `SeDeny*` (enum
   FERMÉ), `accounts` = liste de noms `DOMAIN\name` (jamais de SID, D5). Golden + doc §7 bumpés
   (hashes jumeaux PHP↔Go).
2. **Provider serveur** : `PrivilegeCapabilityProvider` (scope Machine, mécanisme `privilege`),
   `exclusiveKey() = <nom du privilège>` (1 segment, minuscule), réutilise **`AudienceTokens`**
   (36.1) pour résoudre les `@eleves|@profs|@personnels` en noms de groupe réels à l'expansion
   (D6), `StateCompiler` intouché (D2).
3. **Validation d'authoring** : service pur `PrivilegeAuthoringGuard` — **seuls les `SeDeny*`
   sont acceptés** (enum fermé), tout droit *grant* REFUSÉ (une convergence exclusive
   « possède la liste ENTIÈRE » sur un grant peut VERROUILLER la machine), warning non vide
   exigé. Refus SERVEUR **et** refus AGENT (défense en profondeur).
4. **Handler Go `privilege`** (service SYSTEM seul) : réconciliation de CONTENEUR (le privilège
   EST le conteneur, iso `firewall`/`registry_list` — **PAS de store dernier-appliqué**, la
   liste des titulaires est ÉNUMÉRABLE via LSA) : possède la liste ENTIÈRE du privilège
   (accorde les manquants, révoque les surnuméraires). Résolution `DOMAIN\name → SID` via LSA
   (réutilise `windows.LookupSID`, D5). Compte irrésoluble ⇒ item `error` avec détail, jamais
   d'application partielle silencieuse.
5. **Capacité de preuve** : seed `rdp_denied_for_group` (privilège
   `SeDenyRemoteInteractiveLogonRight`, comptes via jeton `@eleves`).
6. Bump `agent/shared/version.go` **2.7.0 → 2.8.0** + note de publication.

**Pourquoi maintenant** : Epic 36 mergé sur main (agent 2.7.0, mais **2.6.0 ET 2.7.0 pas
encore publiées** — cf. `version.go` l.178) ; 36.1 a livré `AudienceTokens` + `windows.LookupSID`
et 36.2 le patron de réconciliation de conteneur SANS store (`Grouping`) — les DEUX briques que
`privilege` recompose. Aucun autre mécanisme (`localgroup`) n'a été cadré entre-temps qui la
rendrait caduque (la constante `CapabilityProjection::MECHANISM_LOCALGROUP` existe mais aucun
provider/handler ne l'implémente — hors scope).

## ⚠️ Pièges & tensions (lire AVANT de coder)

1. **Piège #1 — nouveau TYPE : le binaire antérieur IGNORE EN SILENCE (contrat §8).** Un
   agent ≤ 2.7.0 qui reçoit `privilege` n'émet AUCUN statut (type sans handler = log DEBUG).
   Symptôme : « RDP toujours ouvert aux élèves, zéro erreur ». Aucun effet de bord sur les
   autres types (iso fs_acl/firewall), mais la release **2.8.0 DOIT être publiée MANUELLEMENT**
   (update.sh ne publie jamais seul) sinon la capacité est inerte. Note : la 2.6.0 (fs_acl) et
   la 2.7.0 (firewall) n'étant pas encore publiées, publier la **2.8.0 livre les TROIS
   mécanismes d'un coup** — à mentionner explicitement au Dev Agent Record.

2. **Piège #2 — `privilege` = mécanisme CONTENEUR SANS store (iso `firewall`, PAS `fs_acl`).**
   Un privilège LSA porte une liste de titulaires **ÉNUMÉRABLE** (`LsaEnumerateAccountsWithUserRight`)
   — contrairement à une ACE NTFS (pas de marqueur de propriété → 36.1 avait besoin d'un store
   `fsacl-state.json`). Le handler possède donc la liste ENTIÈRE du privilège et la réconcilie
   à chaque cycle (accorde les manquants, révoque les surnuméraires), **exactement comme le
   groupe de règles `SambaEdu-Agent` de firewall** — mais **AUCUN store, AUCUN marqueur**. NE
   PAS copier le store de `fs_acl` ici : c'est du sur-poids et une seconde source de vérité
   (`feedback_no_overengineered_choices`).

3. **Piège #3 — « posséder la liste ENTIÈRE » = tension de sûreté RÉELLE, désamorcée par le
   SeDeny*-only.** Réconcilier tout le conteneur signifie **révoquer** tout titulaire hors état
   désiré — y compris un compte qu'un admin aurait accordé à la main. C'est SÛR **uniquement**
   parce que les privilèges `SeDeny*` sont **vides par défaut** sous Windows (aucun titulaire
   légitime préexistant à écraser) ET parce que l'authoring interdit les droits *grant* : un
   « owns-entire-list » sur `SeInteractiveLogonRight` ou `SeRemoteInteractiveLogonRight` (grant)
   révoquerait le droit de session à tout le monde → **machine verrouillée, injoignable**.
   La restriction SeDeny*-only n'est donc PAS cosmétique : c'est ce qui rend la variante
   catastrophique **inexprimable** (D3). À documenter en toutes lettres (guard + contrat + seed).

4. **Piège #4 — `exclusiveKey = <privilège>` : la maille gagnante prend TOUTE la liste (NON
   cumulatif).** Contrairement à `fs_acl` (`{path|trustee|ace_type}`, ACE cumulables), l'identité
   est le SEUL nom du privilège : la maille la plus spécifique gagne la LISTE DE COMPTES ENTIÈRE,
   elle ne s'ajoute PAS aux mailles moins spécifiques. C'est VOULU et c'est ce qui répond au
   besoin : le ciblage « qui est refusé » vit DANS la liste `accounts` (mettre `@eleves` seul →
   les profs, absents de la liste, gardent le RDP), pas dans un ciblage par utilisateur. Un test
   de compilation le prouve (précédence broadcast/parc sur identité ÉGALE = même privilège).

5. **Piège #5 — effet au LOGON SUIVANT, jamais de session tuée.** Les droits de logon `SeDeny*`
   sont évalués par Windows **à l'ouverture de session**. Accorder `SeDenyRemoteInteractiveLogonRight`
   à un élève déjà connecté en RDP ne coupe PAS sa session en cours ; la PROCHAINE tentative RDP
   est refusée. Symétriquement, retirer le droit (valeur `off`) rétablit le RDP au logon
   suivant, sans reboot. À documenter (seed + QA e2e) — ce n'est pas un bug, c'est la sémantique
   Windows (parent : `project_registry_apply_effect_next_logon`).

6. **Piège #6 — fenêtre d'orphelin `unmanaged`, désamorcée par un `off` RÉEL (iso fs_acl #3 /
   firewall).** `engine.go` n'invoque le handler QUE si l'état porte au moins un item `privilege`
   (il itère les types présents). Une capacité armée puis remise à `unmanaged` (sentinelle → rien
   émis) laisserait le privilège peuplé (orphelin). Mitigation v1 (documentée, PAS sur-conçue) :
   le seed expose un **`off` réel** — valeur qui émet l'item avec `accounts: []` → le handler
   VIDE le privilège (révoque tous les titulaires) → RDP rétabli. Le retrait propre passe par
   `off`, JAMAIS par `unmanaged`. NE PAS « corriger » en synthétisant des items serveur ni en
   touchant `engine.go`.

7. **Piège #7 — résolution SID : LSA du poste, JAMAIS en SQL (D5, réutilise 36.1).** Le serveur
   émet des NOMS (`Eleves`, `SE4\Eleves`, `Domain Users`) ; l'agent résout `name → SID` via
   `windows.LookupSID("", name)` — **exactement l'op `fsAclOps.LookupSid`** (36.1,
   `agent/windows/handler_fs_acl_windows.go` l.63-73). Mémo PAR PASSE. Compte irrésoluble ⇒
   erreur d'ITEM (les autres comptes de la liste sont tout de même réconciliés ? — NON : voir
   piège #8). Provider Postgres pur (zéro AD/LdapRecord/APCu, NFR7).

8. **Piège #8 — un compte irrésoluble ⇒ item `error`, PAS d'application partielle silencieuse
   (scope epic).** Si UN compte de la liste désirée ne résout pas en SID, le handler NE doit PAS
   accorder « ce qu'il peut » et laisser un trou (un élève non refusé) : l'item entier passe
   `error` avec détail (le compte fautif), la réconciliation du privilège n'est PAS appliquée
   silencieusement à moitié. C'est le même principe que `fs_acl` (trustee irrésoluble ⇒ erreur
   d'item). L'erreur remonte TOUJOURS (grain 27.8, type `error` au rapport).

9. **Piège #9 — refus agent SeDeny*-only = défense en profondeur, INDÉPENDANT du serveur.**
   Après parse, un `privilege` HORS de l'allowlist `SeDeny*` ⇒ erreur d'item, JAMAIS appliqué —
   miroir Go de la constante PHP `PrivilegeAuthoringGuard::ALLOWED_PRIVILEGES` (le serveur peut
   avoir tort, l'agent ne verrouille jamais la machine). Payload statiquement invalide (clé
   manquante, privilège vide) ⇒ enveloppe invalide ⇒ `{status: error}` pour le type (iso
   registry/fs_acl).

10. **Piège #10 — jetons d'audience : RÉUTILISER `AudienceTokens` (36.1), NE PAS le réécrire.**
    `App\Services\Agent\Providers\AudienceTokens` existe déjà (map publique `@eleves→Eleves`,
    `@profs→Profs`, `@personnels→Administratifs`, vérif d'existence `user_groups` mémoïsée). Le
    provider `privilege` l'INJECTE tel quel. Un compte de `spec` commençant par `@` passe par
    `resolve()` ; jeton irrésoluble (groupe absent) ⇒ **item non émis + warning** (jamais de
    payload avec jeton brut). Un compte littéral (`Domain Users`) part verbatim — l'agent le
    résout via LSA (piège #7).

11. **Piège #11 — pas de ciblage par utilisateur : STRUCTUREL (iso fs_acl #10 / firewall).**
    Provider scope Machine → le service SYSTEM fetch sans `?user` (`TargetContext::for($ws, null)`,
    `userGroupIds = []`) : un override UserGroup/User d'une capacité `privilege` est SANS EFFET.
    « Qui est refusé » = la liste `accounts` DANS le payload ; « quels postes » = assignments
    parc/salle/poste/broadcast. À PROUVER par un test de compilation machine-only, à DOCUMENTER
    (guard + contrat + seed) — pas de garde-fou runtime.

12. **Piège #12 — golden = hashes figés JUMEAUX + comptages (baseline POST-firewall).** +1 item
    `privilege` en portée machine ⇒ recalculer le hash d'item (`StateHasher::hashItem`),
    `ContractV1Test::FROZEN_STATE_HASH` (PHP, actuel `76f6d9ac…`) **ET** `frozenStateHash`
    (`agent/shared/hasher_test.go`, MÊME valeur), justification écrite dans les DEUX fichiers
    (règle 23.1). Comptages ACTUELS à incrémenter de 1 : `contract_test.go` machine **7→8** et
    `ResourceTypes` **13→14** (l.259-260) ; `hasher_test.go` : nombre d'items **actuel+1** (lire
    la valeur en place, ne pas deviner — le golden porte les items fs_acl + firewall déjà).
    `report.v1.json` INCHANGÉ avec justification (les items de rapport ne portent pas de payload ;
    le type entre à l'ingestion par la constante additive `Rule::in(StateContract::RESOURCE_TYPES)`).

13. **Piège #13 — payload 2 clés, `accounts` liste de STRINGS, zéro float, zéro id de capacité.**
    `{privilege: string, accounts: list<string>}`. Liste vide = `off` (privilège vidé). Ordre
    des comptes NON signifiant pour la sémantique mais **canonicalisé** (trié) pour la byte-identité
    du hash et la comparaison anti-drift (iso `firewall` `sameAddressSet`/`normalizeAddresses`).
    Jamais de fuite d'id de capacité au payload (invariant 27.12).

## Décisions de design (tranchées — cadrage epic + réutilisation Epic 36 + exploration code)

1. **D1 — contrat additif** : `'privilege'` s'AJOUTE à `StateContract::RESOURCE_TYPES` (PHP,
   après `'firewall'`) et `ResourceTypes` (`agent/shared/contract.go`) ; `semantics: exclusive` ;
   portée Machine ; bump mineur documenté §9. Agents antérieurs : type ignoré (§8) — bump + release.
2. **D2 — StateCompiler intouché** : `exclusiveKey() = strtolower(privilege)` (1 segment) sur le
   provider — la maille la plus spécifique gagne la liste ENTIÈRE (piège #4). Zéro ligne dans
   `app/Services/Agent/StateCompiler.php`.
3. **D3 — enum fermé au contrat + SeDeny*-only** : `privilege ∈` allowlist fermée des **5 droits
   de logon `SeDeny*`** — `SeDenyInteractiveLogonRight`, `SeDenyNetworkLogonRight`,
   `SeDenyBatchLogonRight`, `SeDenyServiceLogonRight`, `SeDenyRemoteInteractiveLogonRight`. Tout
   autre nom (grant, ou SeDeny inconnu) REFUSÉ à l'authoring ET à l'agent (piège #3/#9). `accounts`
   = noms Windows (`DOMAIN\name` ou nom nu résolu localement). AUCUNE syntaxe technique (pas de
   LUID, pas de SID) dans la `spec`.
4. **D4 — propriété du conteneur SANS marqueur ni store** (écart assumé vs `fs_acl`) : le privilège
   `SeDeny*` étant vide par défaut, l'agent possède sa liste de titulaires ENTIÈRE et la réconcilie
   directement (iso `firewall` `Grouping`, mais le « conteneur » est le privilège lui-même — pas
   de nom de groupe à porter). Réconciliation à chaque cycle : titulaires désirés présents, tout
   titulaire surnuméraire RÉVOQUÉ.
5. **D5 — SID côté agent** (LSA `windows.LookupSID`, réutilise `fsAclOps.LookupSid`) ; le serveur
   n'émet que des noms ; provider Postgres pur.
6. **D6/Q1 — jetons d'audience réutilisés** : `AudienceTokens` (36.1) INJECTÉ dans le provider
   (map `@eleves|@profs|@personnels`, existence `user_groups`, irrésoluble ⇒ non émis + warning).
   Zéro nouvelle UI, zéro nouvelle table (le groupe arbitraire relèverait d'un futur formulaire,
   pas d'un jeton — Q1 36.1).
7. **Spec `privilege`** : `spec = { "privilege": "SeDeny…", "accounts": <liste OU map valeur-capacité> }`.
   `accounts` est résolu via `resolveKeyValue()` hérité : soit une liste littérale, soit une map
   `{capValue: [comptes]}` (clé absente ⇒ sentinelle UNMANAGED ⇒ item non émis). Chaque compte de
   la liste résolue passe par `AudienceTokens::resolve()` (jeton → nom, littéral → verbatim) ; un
   jeton irrésoluble dans la liste ⇒ **item entier non émis + warning** (jamais une liste partielle
   qui sous-refuserait, piège #8/#10). `accounts: []` (valeur `off`) est ÉMIS (privilège vidé).
8. **Seed `rdp_denied_for_group`** — enum opt-in à TROIS valeurs (patron « off réel » 36.1) :
   `unmanaged` « Non géré » (défaut, sentinelle), `eleves` « RDP refusé aux élèves »
   (`accounts: ['@eleves']`), `off` « RDP autorisé (droit retiré) » (`accounts: []` → privilège
   vidé). `privilege = 'SeDenyRemoteInteractiveLogonRight'`. `warning` NON VIDE (capacité de
   refus : effet au logon suivant, ne coupe pas les sessions en cours ; possède la liste entière
   du droit).
9. **Guard d'authoring = service pur SÉPARÉ** `PrivilegeAuthoringGuard` (violations nommées,
   messages FR) — constante publique `ALLOWED_PRIVILEGES` (les 5 SeDeny*), règle « grant refusé »,
   règle « deny ⇒ warning non vide », `accounts` non vide OU explicitement `off` (une liste
   vide EST légitime = off ; le guard vérifie la cohérence privilège/warning, pas la non-vacuité).
   Exécuté par un invariant de test sur les données seedées + par un observer Eloquent (iso
   `CapabilityProjectionObserver` de 36.1 — étendre l'observer EXISTANT pour couvrir
   `mechanism === 'privilege'`, ou en créer un jumeau ; décision de conception laissée au dev,
   documentée).
10. **Wiring agent** : handler `privilege` dans la map `Handlers` du SERVICE SYSTEM
    (`agent/windows/main_windows.go`) UNIQUEMENT — jamais `companion_windows.go`.

## Acceptance Criteria

### AC1 — Contrat v1 : type `privilege` publié (D1)

**Given** le contrat `se5.desired-state/v1`
**When** le type `privilege` est publié
**Then** `StateContract::RESOURCE_TYPES` (PHP, après `'firewall'`) et `ResourceTypes`
(`agent/shared/contract.go`) gagnent `'privilege'` (ajout ADDITIF — `ReportRequest` et
l'ingestion 24.1 l'acceptent sans autre changement)
**And** le payload est EXACTEMENT 2 clés `{privilege, accounts}` : `privilege` = un nom de droit
`SeDeny*` (enum fermé D3), `accounts` = `list<string>` de noms Windows (jamais de SID, jamais de
LUID — D5), triée pour la byte-identité (piège #13) ; zéro float, jamais d'id de capacité (27.12)
**And** `docs/agent/contract-v1.md` est mis à jour : §7 (liste des identifiants), nouvelle
sous-section **§7.9 Payload `privilege`** (tableau 2 clés + exemple + sémantique complète : portée
Machine, conteneur SANS store D4, SeDeny*-only et pourquoi (piège #3), effet au logon suivant
piège #5, refus agent piège #9, fenêtre d'orphelin piège #6 et retrait propre par `off`, pas de
ciblage par utilisateur piège #11), §9 (évolution : nouveau type = mineur ; agent antérieur =
type ignoré EN SILENCE, publication requise)
**And** le golden `state.v1.json` gagne UN item `privilege` en portée machine
(`{privilege: "SeDenyRemoteInteractiveLogonRight", accounts: ["Eleves"]}`) avec justification
écrite dans `ContractV1Test.php` ET `hasher_test.go` ; `FROZEN_STATE_HASH` (PHP) = `frozenStateHash`
(Go) recalculés à l'identique via le `StateHasher` RÉEL ; comptages ajustés (`contract_test.go`
machine 7→8, `ResourceTypes` 13→14 ; `hasher_test.go` items actuel+1) ; `report.v1.json` INCHANGÉ
avec justification écrite
**And** un test PHP + un test Go prouvent que deux items `privilege` ne différant que par
`accounts` (ou par `privilege`) ont des hashes distincts (AUCUNE modification de `StateHasher.php`
ni `hasher.go`).

### AC2 — Provider serveur : expansion, jetons réutilisés, compilateur intouché (D2, D6)

**Given** une capacité active portant une projection `windows/privilege`
**When** `PrivilegeCapabilityProvider` (scope Machine, `mechanism() = 'privilege'`, nouvelle
constante `CapabilityProjection::MECHANISM_PRIVILEGE`) l'expanse
**Then** la `spec` `{privilege, accounts}` produit AU PLUS un item 2 clés : `accounts` résolu par
`resolveKeyValue()` (liste littérale OU map valeur-capacité ; clé de map absente ⇒ UNMANAGED ⇒
item non émis ; forme inattendue ⇒ non émis défensif, jamais d'exception au render) ; un `privilege`
hors enum SeDeny* ⇒ item non émis (défensif — le guard refuse déjà en amont)
**And** chaque compte de la liste résolue passe par `AudienceTokens` (INJECTÉ, réutilisé de 36.1) :
jeton `@…` → nom conventionnel SI le groupe existe dans `user_groups` ; jeton irrésoluble (inconnu
OU groupe absent) ⇒ **item entier non émis + log warning** (jamais une liste partielle qui
sous-refuserait — piège #8/#10) ; compte littéral → verbatim ; `accounts: []` (off) ⇒ item ÉMIS
avec liste vide
**And** `exclusiveKey(payload) = strtolower(privilege)` (1 segment) ; un test de compilation
(StateCompiler INTOUCHÉ) prouve la précédence broadcast/parc sur identité ÉGALE (broadcast
`accounts:[Eleves]` battu par un override de parc `accounts:[]` → privilège vidé, et l'inverse)
**And** un test de compilation machine-only (`TargetContext::for($ws, null)`) prouve qu'un override
UserGroup n'atteint JAMAIS un item `privilege` (piège #11)
**And** le provider est câblé dans `AgentServiceProvider` (1 ligne, enrobage
`UpstreamAwareProvider::wrap` iso autres providers, marqueur `KeyedExclusiveProvider` relayé) et
reste Postgres pur (zéro AD/LdapRecord/APCu) ; les providers registry/registry_list/fs_acl/firewall
restent byte-identiques (tests existants verts sans modification d'attendus).

### AC3 — Validation d'authoring : `PrivilegeAuthoringGuard` (SeDeny*-only + warning)

**Given** l'ensemble des projections `windows/privilege` du catalogue
**When** `PrivilegeAuthoringGuard` (service PUR : projections en entrée, violations nommées en
sortie, messages FR explicites) les valide
**Then** sont REFUSÉS :
- un `privilege` HORS de la constante publique `ALLOWED_PRIVILEGES` (les 5 SeDeny* — tout droit
  *grant* type `SeInteractiveLogonRight`/`SeRemoteInteractiveLogonRight`/… REFUSÉ avec message
  expliquant le risque de verrouillage machine, piège #3) ;
- un `privilege` vide ou absent ;
- un jeton d'audience inconnu (hors map `AudienceTokens::TOKENS`) dans `accounts`
**And** toute capacité portant une projection `privilege` (mécanisme de refus par nature) exige un
`warning` non vide (violation sinon)
**And** une liste `accounts` vide est LÉGITIME (= `off`, privilège vidé) — le guard ne l'interdit
PAS
**And** l'enforcement est câblé côté serveur : un observer Eloquent (`saving`, UNIQUEMENT
`mechanism === 'privilege'`) refuse l'INSERT/UPDATE d'une projection fautive (patron
`CapabilityProjectionObserver` de 36.1 — étendre l'existant ou jumeau, gaté hors env `testing` de
la même façon, le seed Query Builder passe) ; l'invariant de test sur les données seedées passe ;
le service est conçu pour réutilisation future (docblock).

### AC4 — Handler Go `privilege` : réconciliation de conteneur (D4, D5)

**Given** le handler Go `privilege` (nouveau `agent/shared/handler_privilege.go`, instancié par
le SERVICE SYSTEM seul — `agent/windows/main_windows.go` ; JAMAIS le compagnon), ops injectées
`PrivilegeOps { LookupSid(name) (sid, err) ; AccountsWithPrivilege(priv) ([]sid, err) ;
GrantPrivilege(sid, priv) err ; RevokePrivilege(sid, priv) err }` (impl Windows dans un nouveau
`agent/windows/handler_privilege_windows.go` ; fake en mémoire pour les tests hôte)
**When** il converge les items `privilege`
**Then** `Test` = pour chaque privilège désiré : l'ensemble des SID titulaires (via
`AccountsWithPrivilege`) est EXACTEMENT égal à l'ensemble des SID désirés (résolus par `LookupSid`)
⇒ `compliant`, sinon `drift` (titulaire manquant OU surnuméraire)
**And** `Apply` (effort maximal, idempotent — 2 passes stables = zéro op) : accorde
(`GrantPrivilege`) chaque SID désiré manquant, révoque (`RevokePrivilege`) chaque SID titulaire
hors état désiré — le handler possède la liste ENTIÈRE du privilège (D4) ; un `accounts: []` vide
le privilège (révoque tous les titulaires)
**And** refus agent (défense en profondeur, piège #9) : un `privilege` HORS allowlist SeDeny*
(constante Go miroir de `ALLOWED_PRIVILEGES`) ⇒ erreur d'item, JAMAIS appliqué ; un compte
irrésoluble via LSA ⇒ erreur d'item avec détail, la réconciliation de CE privilège n'est PAS
appliquée partiellement (piège #8) — les AUTRES privilèges (autres items) convergent, l'erreur
remonte TOUJOURS (type `error`) ; payload statiquement invalide ⇒ enveloppe invalide ⇒
`{status: error}` pour le type
**And** la policy STRICT est démontrée À TRAVERS le moteur (`engine.go` INTOUCHÉ, iso
`TestRegistryAbsentThroughEngineStrictRedrift`) : titulaire retiré à la main ⇒ `drift` + ré-accord ;
titulaire ajouté à la main (surnuméraire) ⇒ `drift` + révocation ; changement de liste (`[Eleves]`
→ `[]`) ⇒ `drift`, privilège vidé ; état stable ⇒ `compliant`, zéro op
**And** le SID est mémoïsé PAR PASSE seulement (piège #7) ; aucun store sur disque (piège #2).

### AC5 — Capacité de preuve : seed `rdp_denied_for_group`

**Given** la nouvelle migration `database/migrations/2026_07_04_140000_seed_capability_rdp_denied_for_group.php`
(pattern iso 36.1/36.2 : `updateOrInsert` par `key` puis par `(capability_id, os, mechanism)`,
idempotente, garde `hasTable`, `down()` par suppression de la `key`)
**When** elle est jouée
**Then** `rdp_denied_for_group` naît : enum opt-in (`default_value = 'unmanaged'`, options
`[unmanaged: 'Non géré', eleves: 'RDP refusé aux élèves', off: 'RDP autorisé (droit retiré)']` —
convention « sujet + état », « Non géré » réservé à la sentinelle), `warning` NON VIDE (refus de
logon RDP : effet au logon suivant, ne coupe pas les sessions en cours ; le retrait passe par
« RDP autorisé », PAS par « Non géré » — piège #6)
**And** sa projection `windows/privilege` porte `privilege = 'SeDenyRemoteInteractiveLogonRight'`
et `accounts` par map de valeur : `{'eleves': ['@eleves'], 'off': []}` (`unmanaged` absent de la
map = sentinelle ; le commentaire de tête documente : effet logon suivant, off réel, pas de
ciblage user, jeton `@eleves` résolu à l'expansion)
**And** des tests d'intégration provider sur données réelles (dans un fichier DÉDIÉ
`CapabilityPrivilegeSeedTest.php`) prouvent : `eleves` ⇒ 1 item `{SeDenyRemoteInteractiveLogonRight,
[Eleves]}` ; `off` ⇒ 1 item `{…, []}` (privilège vidé) ; `unmanaged` ⇒ rien ; groupe `Eleves`
ABSENT de `user_groups` ⇒ item `@eleves` non émis + warning loggé ; l'invariant
`PrivilegeAuthoringGuard` passe sur le catalogue seedé (et un combo grant fabriqué est bien refusé)
**And** la note e2e lab (exécution MANUELLE par l'opérateur, poste joint au domaine — hors
périmètre du dev) est écrite au Dev Agent Record : un membre du groupe élèves se voit REFUSER
l'ouverture de session RDP (mstsc depuis un autre poste → « The connection was denied because the
user account is not authorized… »), tandis qu'un PROF (hors liste) ouvre sa session RDP
normalement, sur le MÊME poste ; retour `off` ⇒ droit retiré, RDP rétabli pour les élèves au logon
suivant.

### AC6 — Version agent + note de publication

**Given** l'agent modifié
**Then** `agent/shared/version.go` est bumpé (**2.7.0 → 2.8.0**) avec entrée de changelog (style
2.6.0/2.7.0 : mécanisme `privilege`, conteneur SANS store, réconciliation SeDeny* possède la liste
entière, LSA LookupSid réutilisé, refus SeDeny*-only en double rideau, effet logon suivant)
**And** la note de fin de story rappelle : un binaire ≤ 2.7.0 IGNORE le type `privilege` EN
SILENCE (§8 — aucun statut, aucune erreur) → release **2.8.0 à publier MANUELLEMENT** (update.sh
ne publie jamais seul) ; **les 2.6.0/2.7.0 n'étant pas encore publiées, la 2.8.0 livre fs_acl +
firewall + privilege d'un coup** (piège #1) ; la migration de seed est **à rejouer sur /vm**
(`php artisan migrate`, jamais auto-appliquée) — l'ordre publication/migration n'est pas critique
ici (pas d'effet de bord inter-types), mais sans publication la capacité est inerte.

## Tasks / Subtasks

- [x] **Task 1 — Contrat & golden files (AC1)** *(commencer ici : fige le wire format)*
  - [x] 1.1 `app/Services/Agent/StateContract.php` : `'privilege'` dans `RESOURCE_TYPES` après
        `'firewall'` (commentaire Story 35.6, iso entrées 35.2/36.1/36.2) ; `agent/shared/contract.go` :
        `"privilege"` dans `ResourceTypes`.
  - [x] 1.2 `docs/agent/contract-v1.md` : §7 liste + **§7.9 Payload `privilege`** (tableau 2 clés,
        exemple, sémantique D4/SeDeny*-only/effet logon/refus agent/fenêtre d'orphelin/off/pas de
        ciblage user) + §9 (nouveau type = mineur, silence binaire antérieur).
  - [x] 1.3 `tests/Fixtures/Agent/state.v1.json` : +1 item machine
        `{"type":"privilege","semantics":"exclusive","payload":{"privilege":"SeDenyRemoteInteractiveLogonRight","accounts":["Eleves"]},"hash":"<recalculé via StateHasher::hashItem>"}`.
  - [x] 1.4 `ContractV1Test.php` : `FROZEN_STATE_HASH` recalculé + justification 35.6 dans la
        chaîne de commentaires (règle 23.1). `agent/shared/hasher_test.go` : `frozenStateHash` =
        MÊME valeur + justification + comptage items actuel+1. `agent/shared/contract_test.go` :
        machine 7→8, `ResourceTypes` 13→14 (l.259-260). `loop_test.go` : vérifier (normalement rien).
  - [x] 1.5 Tests hash : PHP (`StateHasherTest`) et Go (`hasher_test.go`) — deux items `privilege`
        ne différant que par `accounts` (et par `privilege`) ⇒ hashes distincts ; hashers
        INTOUCHÉS. Justification écrite `report.v1.json` INCHANGÉ.
- [x] **Task 2 — Provider PHP + guard (AC2, AC3)**
  - [x] 2.1 `app/Models/CapabilityProjection.php` : `public const MECHANISM_PRIVILEGE = 'privilege';`
        (+ docblock portée Machine, conteneur SANS store, SeDeny*-only).
  - [x] 2.2 NOUVEAU `app/Services/Agent/Providers/PrivilegeCapabilityProvider.php` (extends
        `AbstractCapabilityStateProvider`) : `scope() = Machine`, `mechanism() = MECHANISM_PRIVILEGE`,
        `hive() = ''` (non applicable, docblock iso `FsAclCapabilityProvider`/`FirewallCapabilityProvider`),
        `exclusiveKey()` 1 segment (privilège minuscule), `expand()` surchargé (spec
        `{privilege, accounts}`, `accounts` via `resolveKeyValue()`, chaque compte via
        `AudienceTokens` INJECTÉ + item non émis si jeton irrésoluble + warning, `privilege` borné
        défensif, payload EXACTEMENT 2 clés, `accounts` trié).
  - [x] 2.3 `app/Providers/AgentServiceProvider.php` : +1 ligne provider (commentaire 35.6,
        enrobage `UpstreamAwareProvider::wrap` iso autres).
  - [x] 2.4 NOUVEAU `app/Services/Agent/Providers/PrivilegeAuthoringGuard.php` (service pur — AC3) :
        constante publique `ALLOWED_PRIVILEGES` (les 5 SeDeny*), règles (grant refusé + risque
        verrouillage, privilège vide, jeton inconnu, deny ⇒ warning non vide), messages FR nommant
        capacité + privilège ; docblock (réutilisation, allowlist = autorité, différence avec le
        guard fs_acl/firewall).
  - [x] 2.5 Câbler l'enforcement serveur : étendre `CapabilityProjectionObserver` (36.1) pour
        couvrir `mechanism === 'privilege'` (appel `PrivilegeAuthoringGuard`, exception FR, gaté
        hors env `testing` de la même façon) — OU observer jumeau si plus propre ; documenter le
        choix.
  - [x] 2.6 NOUVEAU `tests/Unit/Services/Agent/CapabilityPrivilegeProviderTest.php` :
        (a) expansion 2 clés (strings, accounts trié, pas de fuite d'id) ; (b) map accounts +
        sentinelle UNMANAGED + forme inattendue non émise ; (c) jeton résolu (user_groups seedé) ;
        (d) jeton inconnu / groupe absent ⇒ item non émis + warning ; (e) littéral verbatim ;
        (f) `accounts: []` (off) ⇒ item émis liste vide ; (g) privilège hors SeDeny* non émis ;
        (h) `exclusiveKey` 1 segment minuscule ; (i) provider Postgres pur (grep AD/APCu).
  - [x] 2.7 NOUVEAU `tests/Unit/Services/Agent/CapabilityPrivilegeCompilationTest.php` (StateCompiler
        INTOUCHÉ) : précédence sur identité égale (broadcast `[Eleves]` vs parc `[]`, DANS LES DEUX
        SENS) ; compile machine-only (override UserGroup sans effet, piège #11).
  - [x] 2.8 Tests unitaires du guard + observer (dans `CapabilityPrivilegeProviderTest` ou fichier
        dédié) : grant refusé (avec message risque) ; chaque SeDeny* accepté ; privilège vide
        refusé ; deny sans warning refusé ; jeton inconnu refusé ; `accounts: []` accepté ; observer
        refuse l'écriture d'une projection grant + laisse passer le seed Query Builder.
- [x] **Task 3 — Handler Go + impl Windows (AC4, AC6)**
  - [x] 3.1 NOUVEAU `agent/shared/handler_privilege.go` : type `PrivilegeOps` (4 ops, doc de
        sémantique/idempotence) ; constante Go `privilegeAllowlist` (les 5 SeDeny*, miroir PHP) ;
        parse strict payload 2 clés (privilège borné, `accounts` list<string>, `error` enveloppe
        sinon) ; dédoublonnage par privilège (dernière occurrence, ordre trié — iso `desiredSpecs`) ;
        refus privilège hors allowlist (piège #9) ; Test/Apply selon AC4 (ensembles de SID désirés
        vs titulaires, grant/revoke, effort maximal, idempotence) ; compte irrésoluble ⇒ erreur
        d'item SANS application partielle (piège #8) ; mémo SID par passe ; doc de tête (D4 : le
        privilège EST le conteneur, aucun store — écart assumé vs fs_acl).
  - [x] 3.2 NOUVEAU `agent/windows/handler_privilege_windows.go` : impl `PrivilegeOps` —
        `LookupSid` = `windows.LookupSID("", name)` (RÉUTILISE le pattern `fsAclOps.LookupSid`,
        36.1) ; `AccountsWithPrivilege`/`GrantPrivilege`/`RevokePrivilege` via LSA Policy
        (`LsaOpenPolicy` + `LsaEnumerateAccountsWithUserRight` / `LsaAddAccountRights` /
        `LsaRemoveAccountRights` — x/sys/windows expose une partie ; lazy proc advapi32 pour le
        reste, iso pattern `GetAce`/`DeleteAce` de 36.1) ; privilège inconnu de LSA
        (`STATUS_NO_SUCH_PRIVILEGE`) ou liste vide (`STATUS_OBJECT_NAME_NOT_FOUND` sur
        enumerate = aucun titulaire) traités proprement (aucun titulaire ⇒ liste vide, pas une
        erreur).
  - [x] 3.3 `agent/windows/main_windows.go` : entrée `"privilege"` dans la map `Handlers` du
        MachineEngine (commentaire 35.6 : LSA, conteneur, SYSTEM seul) — `companion_windows.go`
        INTOUCHÉ.
  - [x] 3.4 NOUVEAU `agent/shared/handler_privilege_test.go` (fake `PrivilegeOps` en mémoire) :
        (a) accord des titulaires manquants + relecture conforme + 2e Apply zéro op ; (b) titulaire
        retiré à la main ⇒ ré-accord STRICT À TRAVERS le moteur ; (c) titulaire surnuméraire ⇒
        révocation ; (d) `accounts: []` ⇒ privilège vidé ; (e) privilège hors SeDeny* ⇒ erreur
        d'item ISOLÉE (les autres convergent, type error) ; (f) compte irrésoluble ⇒ erreur d'item
        SANS accord partiel des autres comptes du MÊME privilège (piège #8) ; (g) payload invalide
        ⇒ error type ; (h) mémo SID par passe (compteur du fake) ; (i) aucun store écrit (le fake
        l'atteste).
  - [x] 3.5 `agent/shared/version.go` : bump **2.8.0** + entrée changelog + note « 2.6.0/2.7.0 pas
        encore publiées → 2.8.0 livre les trois mécanismes ».
- [x] **Task 4 — Seed de preuve (AC5)**
  - [x] 4.1 NOUVELLE migration `2026_07_04_140000_seed_capability_rdp_denied_for_group.php`
        (pattern 36.1 exact ; timestamp à ajuster si collision avec une migration parallèle ;
        commentaires de tête : privilège SeDenyRemoteInteractiveLogonRight, off réel, effet logon
        suivant, pas de ciblage user, jeton @eleves).
  - [x] 4.2 NOUVEAU `tests/Feature/Migrations/CapabilityPrivilegeSeedTest.php` (fichier DÉDIÉ, iso
        piège #12 de 36.1 — ne pas toucher `CapabilitiesSchemaAndSeedTest.php` ni
        `CapabilityFsAclSeedTest.php`) : seed (options/défaut/warning/projection), idempotence/
        réversibilité, intégration provider sur données réelles (eleves/off/unmanaged + groupe
        absent ⇒ warning), invariant `PrivilegeAuthoringGuard` sur le catalogue seedé + combo grant
        fabriqué refusé.
- [x] **Task 5 — Validation finale + docs**
  - [x] 5.1 Tests HÔTE ciblés (php8.4 + sqlite, JAMAIS de run massif) :
        `ContractV1Test|StateHasherTest`,
        `CapabilityPrivilegeProviderTest|CapabilityPrivilegeCompilationTest`,
        `CapabilityPrivilegeSeedTest`, non-régression
        `CapabilityFsAclProviderTest|CapabilityFirewall*|CapabilityRegistry*`.
  - [x] 5.2 Tests Go (`~/go-toolchain/go/bin/go`, hors PATH) : `cd agent && go test ./...` ;
        `GOOS=windows go build ./...` ; `go vet ./...` (linux ET GOOS=windows).
  - [x] 5.3 `docs/agent/state-providers.md` : section `privilege` (mécanisme, exclusiveKey,
        conteneur sans store, SeDeny*-only, jetons d'audience, limites piège #4/#6). `docs/qa/domains/agent.md` :
        section « Story 35.6 » append-only (scénarios : RDP refusé élève + autorisé prof même
        poste, off rétablit, effet logon suivant, binaire antérieur silencieux, e2e lab manuel
        poste joint domaine).
  - [x] 5.4 Dev Agent Record : (a) justification golden (item ajouté, hashes jumeaux) ; (b) ⚠️
        release **2.8.0 à publier manuellement** (livre fs_acl+firewall+privilege) + migration **à
        rejouer sur /vm** ; (c) note e2e lab MANUEL (AC5 — poste joint domaine, RDP élève refusé /
        prof OK) ; (d) rappel réouverture : gate D6 rouvert 2026-07-04 (Henri), plomberie 36.1
        réutilisée.

## Dev Notes

### Fichiers à toucher (exhaustif prévu)

| Fichier | Nature |
|---|---|
| `app/Services/Agent/StateContract.php` | `RESOURCE_TYPES` += `'privilege'` |
| `agent/shared/contract.go` | `ResourceTypes` += `"privilege"` |
| `docs/agent/contract-v1.md` | §7 liste + §7.9 payload privilege + §9 |
| `docs/agent/state-providers.md` | section `privilege` |
| `docs/qa/domains/agent.md` | section 35.6 append-only |
| `tests/Fixtures/Agent/state.v1.json` | +1 item privilege (machine) |
| `tests/Unit/Services/Agent/ContractV1Test.php` | `FROZEN_STATE_HASH` + justification |
| `tests/Unit/Services/Agent/StateHasherTest.php` | tests hash accounts/privilege |
| `agent/shared/hasher_test.go` | `frozenStateHash` jumeau + items+1 + tests |
| `agent/shared/contract_test.go` | machine 7→8, `ResourceTypes` 13→14 |
| `app/Models/CapabilityProjection.php` | const `MECHANISM_PRIVILEGE` |
| `app/Services/Agent/Providers/PrivilegeCapabilityProvider.php` | NOUVEAU — provider Machine |
| `app/Services/Agent/Providers/PrivilegeAuthoringGuard.php` | NOUVEAU — guard SeDeny*-only |
| `app/Observers/CapabilityProjectionObserver.php` (36.1) | étendu `mechanism==='privilege'` (ou jumeau) |
| `app/Providers/AgentServiceProvider.php` | +1 provider au StateCompiler |
| `tests/Unit/Services/Agent/CapabilityPrivilegeProviderTest.php` | NOUVEAU |
| `tests/Unit/Services/Agent/CapabilityPrivilegeCompilationTest.php` | NOUVEAU |
| `agent/shared/handler_privilege.go` | NOUVEAU — handler + PrivilegeOps (sans store) |
| `agent/shared/handler_privilege_test.go` | NOUVEAU — fake + scénarios a–i |
| `agent/windows/handler_privilege_windows.go` | NOUVEAU — impl Win32 (LSA policy + LookupSID) |
| `agent/windows/main_windows.go` | +1 entrée handler (SYSTEM) |
| `agent/shared/version.go` | bump 2.8.0 + changelog |
| `database/migrations/2026_07_04_140000_seed_capability_rdp_denied_for_group.php` | NOUVEAU seed |
| `tests/Feature/Migrations/CapabilityPrivilegeSeedTest.php` | NOUVEAU (fichier dédié) |

**NE PAS TOUCHER** : `app/Services/Agent/StateCompiler.php` (D2), `StateHasher.php` +
`agent/shared/hasher.go` (canonicalisation générique — seulement des tests),
`agent/shared/engine.go` (grain par type + STRICT figés), `agent/windows/companion_windows.go`
(privilege = machine-only), `AbstractCapabilityStateProvider.php` (rien à modifier :
`expand()`/`resolveKeyValue()`/`UNMANAGED` déjà `protected`, `hive()` implémentée `''` dans la
FILLE), `AudienceTokens.php` (RÉUTILISÉ tel quel — ne pas le réécrire, piège #10), providers/
handlers registry/registry_list/fs_acl/firewall (byte-identiques), `report.v1.json` (inchangé
justifié), seeds antérieurs, `CapabilityFsAclSeedTest.php`/`CapabilitiesSchemaAndSeedTest.php`
(tests d'autres mécanismes), `sprint-status.yaml` (hors sa propre ligne) / `backlog.*` /
`routes/web.php` (orchestrateur).

### Patterns existants à imiter

- **Chaîne de justification golden** : commentaires numérotés `ContractV1Test.php` et
  `hasher_test.go` (dernière entrée = firewall 36.2) — ajouter l'entrée 35.6 dans le MÊME style,
  hash jumeau vérifié croisé, recalcul via le `StateHasher` RÉEL (script scratchpad hôte).
- **Provider à expand surchargé, portée Machine, `hive()=''`** : `FsAclCapabilityProvider` et
  `FirewallCapabilityProvider` (36.1/36.2) — le provider privilege suit EXACTEMENT cette discipline.
- **Réconciliation de CONTENEUR sans store** : `handler_firewall.go` (possède le groupe
  `SambaEdu-Agent` en entier — désirées présentes, surnuméraires supprimées) et
  `handler_registry_list.go` (clé-conteneur) — le handler privilege applique le même schéma au
  privilège comme conteneur. NE PAS copier le store de `handler_fs_acl.go` (piège #2).
- **Ops injectées + fake + tests à travers le moteur** : `handler_fs_acl.go`/`handler_firewall.go`
  (parse strict, dédoublonnage par identité, effort maximal `firstErr`,
  `TestRegistryAbsentThroughEngineStrictRedrift`) ; le fake `PrivilegeOps` est un NOUVEAU fake en
  mémoire.
- **Résolution SID LSA** : `agent/windows/handler_fs_acl_windows.go` l.63-73 (`fsAclOps.LookupSid`
  = `windows.LookupSID("", name)`) — RÉUTILISER le même appel pour privilege.
- **Guard = service pur + invariant + observer** : `FsAclAuthoringGuard` +
  `CapabilityProjectionObserver` (36.1) — dupliquer le PATTERN (allowlist SeDeny* au lieu des
  racines protégées).
- **Jetons d'audience** : `app/Services/Agent/Providers/AudienceTokens.php` (36.1) — INJECTÉ,
  réutilisé tel quel.
- **Seed** : `database/migrations/2026_07_04_100000_seed_capability_program_files_browse_denied.php`
  (36.1 : doctrine en tête, `updateOrInsert` double niveau, idempotence + réversibilité testées,
  « off réel »).
- **Wiring compilateur** : `AgentServiceProvider` — « ajouter un type = ajouter UNE ligne ».

### Rappels transverses (garde-fous epic)

- Contrat **additif uniquement** (D1) ; golden AVEC justification (règle 23.1) ; hashes figés
  JUMEAUX PHP⇄Go.
- **Drift policy STRICT** (27.8) — verdict PAR TYPE ; **zéro float** (payload 2 clés :
  string + list<string>) ; **zéro AD/LdapRecord/APCu** dans les providers (critère Keycloak) — la
  résolution SID est côté POSTE (LSA), le serveur ne manipule que des noms.
- Validation d'authoring serveur **ET** refus agent (défense en profondeur) — la restriction
  SeDeny*-only vit des DEUX côtés (piège #3/#9) : le serveur peut avoir tort, l'agent ne verrouille
  jamais la machine.
- Tests PHP sur l'**HÔTE** (php8.4 + sqlite), **filtres ciblés uniquement** (un run massif VM
  produit de faux échecs) ; tests Go via `~/go-toolchain/go/bin/go` (hors PATH).
- Migration **à rejouer sur /vm** = à SIGNALER (Dev Agent Record), jamais exécutée par le dev ;
  toute modif `agent/**` ⇒ bump `agent/shared/version.go` ; **update.sh ne publie jamais seul** —
  binaire antérieur = type ignoré EN SILENCE.
- e2e lab (poste joint domaine, résolution SID/groupes + logon RDP impossibles à simuler ailleurs)
  = MANUEL opérateur, hors périmètre du dev — consigner le protocole au runbook QA.

### Project Structure Notes

- Serveur : tout vit sous `app/Services/Agent/Providers/` (provider + guard) + un observer ; aucune
  UI dans cette story (la capacité apparaît automatiquement dans les surfaces capacités existantes —
  parc-defaults + section groupes ; options data-driven).
- Agent : logique de convergence dans `agent/shared/` (testée hôte via fake) ; `agent/windows/`
  n'apporte que l'impl `PrivilegeOps` (LSA policy + LookupSID) + 1 ligne de wiring.
- `machine_user` et Session ne sont pas concernés (portée Machine unique, D7 de l'epic 36 par
  analogie).

### References

- [Source: _bmad-output/planning-artifacts/epics-capacites-v2.md#Story-35.6 + tableau couverture
  GPO CD95 (Blocages_eleves → rdp_denied_for_group)] — scope du mécanisme privilege
- [Source: _bmad-output/planning-artifacts/epics-mecanismes-hors-registre.md#Décisions-structurantes
  (D1–D8) + #Garde-fous-d'epic + note 35.6↔36.1 l.133-141] — doctrine mécanismes réutilisée
- [Source: _bmad-output/ultradev/35-questions.md#Q1] — gate D6 (fermé le 2026-07-03, ROUVERT le
  2026-07-04 par Henri : besoin terrain confirmé + Epic 36 livré)
- [Source: _bmad-output/implementation-artifacts/36-1-mecanisme-fs-acl.md,
  36-2-mecanisme-firewall.md] — patron EXACT (contrat additif / provider / guard / handler / golden
  / bump / observer)
- [Source: docs/agent/contract-v1.md §4 (canonicalisation, zéro float), §7 (identifiants figés),
  §7.7/§7.8 (fs_acl/firewall — modèle de la §7.9), §8 (type absent = non géré, silence binaire
  antérieur), §9 (règle d'évolution)]
- [Source: app/Services/Agent/Providers/AudienceTokens.php (RÉUTILISÉ) +
  FsAclCapabilityProvider.php + FirewallCapabilityProvider.php (expand surchargé, scope Machine,
  hive '') + FsAclAuthoringGuard.php (pattern guard) + app/Observers/CapabilityProjectionObserver.php
  (36.1) + app/Providers/AgentServiceProvider.php]
- [Source: agent/shared/handler_firewall.go (réconciliation de conteneur SANS store — modèle),
  handler_registry_list.go (clé-conteneur), agent/windows/handler_fs_acl_windows.go l.63-73
  (`windows.LookupSID` — RÉUTILISÉ), agent/windows/main_windows.go (map Handlers SYSTEM),
  agent/shared/engine.go (types présents seulement — fondement piège #6)]
- [Source: agent/shared/version.go l.133-180 (changelog 2.6.0/2.7.0 — style + note « 2.6/2.7 pas
  publiées »)]
- Mémoires projet : `project_capability_mechanisms_direction`, `project_capability_value_map_symmetric_rule`
  (off réel), `project_drift_policy_strict_only`, `project_agent_handler_not_in_published_binary`,
  `project_agent_runtime_go`, `feedback_agent_edit_bump_version`, `feedback_no_overengineered_choices`,
  `project_registry_apply_effect_next_logon` (effet logon suivant),
  `project_state_precedence_logical_over_physical`, `feedback_epic23_model_fable5`.

## Dépendances

- **36.1 (`fs_acl`) — DONE (mergée sur main), REQUISE (fournit la plomberie) :** `AudienceTokens`
  (jetons d'audience), `windows.LookupSID` (résolution SID côté agent, réutilisée verbatim),
  `CapabilityProjectionObserver` (patron d'enforcement serveur à étendre), patron
  provider/guard/handler/golden. **Pas de blocage** — tout est livré et stable.
- **36.2 (`firewall`) — DONE, REQUISE comme MODÈLE :** la réconciliation de conteneur SANS store
  (`Grouping`/`SambaEdu-Agent`) est le patron EXACT du handler privilege (le privilège = conteneur).
  Baseline golden POST-firewall (agent 2.7.0, machine=7, `ResourceTypes`=13, hash `76f6d9ac…`).
- **Epic 35 (capacités v2) — DONE :** verbe `ensure`, `AbstractCapabilityStateProvider` généralisé
  (`mechanism()` + visibilités `protected`), override par UserGroup (35.4, hors portée ici car
  Machine-only), STRICT 27.8, capability-first 27.12.
- **En aval : AUCUN.** `privilege` clôt la couverture GPO CD95 Blocages_eleves. Un futur mécanisme
  `localgroup` (constante `MECHANISM_LOCALGROUP` déjà réservée, aucun code) serait indépendant.
- **Publication couplée (non bloquante mais À SIGNALER) :** les releases 2.6.0 (fs_acl) et 2.7.0
  (firewall) n'ont pas encore été publiées ; publier la **2.8.0** livre les TROIS mécanismes d'un
  coup (piège #1).

## Recommandation Modèle Dev

**fable** — prescription cohérente avec la doctrine mécanismes de l'Epic 36 (garde-fou : « fable
pour 36.1 et 36.2 », agent Go + design sécurité — `feedback_epic23_model_fable5`) et profil IDENTIQUE
à ces deux stories livrées avec succès par fable : nouveau type de contrat cross-language
(`RESOURCE_TYPES` ⇄ handler Go ⇄ golden hashes jumeaux), handler de convergence (réconciliation de
conteneur, ensembles de SID, effort maximal) et **surface de sécurité RÉELLE — la plus tranchante
de l'epic** : une convergence exclusive « possède la liste entière » sur un mauvais privilège peut
VERROUILLER la machine (piège #3), d'où le double rideau SeDeny*-only serveur+agent. La criticité
sécurité argumenterait aussi **opus** ; mais la surface est du Go agent + contrat + design
defense-in-depth déjà éprouvé par fable sur 36.1/36.2 (mêmes garde-fous, mêmes pièges), et la
cohérence de modèle sur les trois stories du même mécanisme prime → **fable**, avec revue
adversariale opus (modèle opposé) OBLIGATOIRE vu la criticité verrouillage-machine.

## Dev Agent Record

### Agent Model Used

claude-fable-5 (dev-story, 2026-07-05) — conforme à la recommandation de la story
(cohérence de modèle avec 36.1/36.2, `feedback_epic23_model_fable5`). Revue
adversariale opus OBLIGATOIRE en aval (criticité verrouillage-machine).

### Debug Log References

- Recalcul golden : script scratchpad hôte (`hash_privilege.php`, StateHasher RÉEL
  via vendor/autoload) — item privilege
  `{SeDenyRemoteInteractiveLogonRight, [Eleves]}` →
  `047048d1b6374caaf5fbbc3e53a94c1ea05a9e6719d607a1ffba42c2a34a6b9a` ;
  hash d'état `76f6d9ac…fb7d9b` (baseline post-firewall) →
  `e87fed1610a0c206065fb19cca444223725d8c6660b9c4b09f44a672f2d43fbd`
  (vérifié CROISÉ : PHP `ContractV1Test` + Go `hasher_test.go`, même valeur).
- `go vet` GOOS=windows a refusé un `uintptr` intermédiaire sur le buffer
  `LsaEnumerateAccountsWithUserRight` → corrigé en pointeur TYPÉ
  `*lsaEnumerationInformation` (pattern `getAce` de 36.1).

### Completion Notes List

1. **Justification golden (règle 23.1).** Type AJOUTÉ (additif D1) = évolution
   MINEURE §9 : golden `state.v1.json` +1 item `privilege` en portée machine
   (payload EXACTEMENT 2 clés, accounts triés), justification écrite dans
   `ContractV1Test.php` ET `hasher_test.go` (même chaîne de commentaires que
   35.1→36.2) ; comptages ajustés (`contract_test.go` machine 7→8,
   `ResourceTypes` 13→14 ; `hasher_test.go` items 15→16). `report.v1.json`
   INCHANGÉ (justifié : les items de rapport ne portent aucun payload ; le type
   entre à l'ingestion par la constante additive `Rule::in(RESOURCE_TYPES)`).
   `StateHasher.php`/`hasher.go`/`StateCompiler.php`/`engine.go` INTOUCHÉS.
2. **⚠️ ACTIONS MANUELLES RESTANTES (opérateur, hors périmètre dev)** :
   (a) **publier MANUELLEMENT la release agent 2.8.0** (update.sh ne publie
   jamais seul) — un binaire ≤ 2.7.0 IGNORE le type `privilege` EN SILENCE
   (§8 : « RDP toujours ouvert aux élèves, zéro erreur ») ; les 2.6.0 (fs_acl)
   et 2.7.0 (firewall) n'ayant JAMAIS été publiées, **la 2.8.0 livre les TROIS
   mécanismes d'un coup** ; (b) **rejouer la migration seed sur /vm**
   (`php artisan migrate` — jamais auto-appliquée,
   `2026_07_04_140000_seed_capability_rdp_denied_for_group`). L'ordre
   publication/migration n'est pas critique (pas d'effet de bord inter-types),
   mais sans publication la capacité est inerte.
3. **Note e2e lab MANUEL (AC5 — poste joint domaine, hors périmètre dev).**
   Protocole complet au runbook `docs/qa/domains/agent.md` § Story 35.6 :
   armer `rdp_denied_for_group=eleves` sur le parc du poste → un MEMBRE du
   groupe élèves se voit REFUSER l'ouverture de session RDP (mstsc depuis un
   autre poste : « The connection was denied because the user account is not
   authorized… »), tandis qu'un PROF (hors liste) ouvre sa session RDP
   normalement, sur le MÊME poste ; retour `off` ⇒ droit retiré, RDP rétabli
   pour les élèves au LOGON SUIVANT (piège #5 — aucune session en cours
   coupée) ; titulaire ajouté à la main dans secpol.msc révoqué au cycle
   suivant (conteneur possédé en entier, D4).
4. **Rappel réouverture** : gate D6 rouvert le 2026-07-04 par Henri (besoin
   terrain confirmé + Epic 36 livré). La plomberie 36.1 est RÉUTILISÉE telle
   quelle (`AudienceTokens` injecté, `windows.LookupSID` même appel,
   `CapabilityProjectionObserver` ÉTENDU — pas de jumeau : un seul point de
   dispatch par modèle, décision documentée au docblock de l'observer) ; le
   handler copie le patron conteneur-sans-store de 36.2 (`firewall`).
5. **Décisions/écarts** : AUCUN écart vs story. Détails d'implémentation :
   le refus allowlist agent est une erreur d'ITEM (pas une enveloppe
   invalide) — un grant reçu n'empêche pas les autres privilèges de converger
   (AC4, testé) ; comparaison de noms de privilège insensible à la casse des
   deux côtés (identité `strtolower`, dédoublonnage Go idem) ; les accounts
   sont dédupliqués + triés au provider (byte-identité, piège #13) ;
   l'impl LSA Windows ouvre/ferme la policy PAR OP (moindre privilège
   POLICY_VIEW_LOCAL_INFORMATION|CREATE_ACCOUNT|LOOKUP_NAMES, jamais
   POLICY_ALL_ACCESS) ; `STATUS_NO_MORE_ENTRIES`/`STATUS_OBJECT_NAME_NOT_FOUND`
   à l'énumération = liste vide (les SeDeny* sont vides par défaut),
   `STATUS_OBJECT_NAME_NOT_FOUND` au retrait = idempotent.
6. **Tests (tous HÔTE, filtres ciblés — jamais de run massif)** :
   - PHP : `ContractV1Test|StateHasherTest` 18 passed ;
     `CapabilityPrivilegeProviderTest|CapabilityPrivilegeCompilationTest`
     25 passed ; `CapabilityPrivilegeSeedTest` 9 passed ; non-régression
     `CapabilityFsAcl*|CapabilityFirewall*|CapabilityRegistry*|
     CapabilityProjectionObserverTest` 139 passed ;
     `StateCompiler|ReportIngest` 51 passed ;
     `StateEndpointTest|AgentSkeletonE2eTest|CapabilitiesSchemaAndSeedTest`
     71 passed (compile via le container avec le provider câblé).
   - Go (`~/go-toolchain/go/bin/go`) : `go test ./...` OK (dont 10 tests
     `TestPrivilege*` scénarios a–i) ; `go vet ./...` OK linux ET
     GOOS=windows ; `GOOS=windows go build ./...` OK.

### File List

Modifiés :
- `app/Services/Agent/StateContract.php` — `RESOURCE_TYPES` += `'privilege'` (additif)
- `agent/shared/contract.go` — `ResourceTypes` += `"privilege"` (additif)
- `tests/Fixtures/Agent/state.v1.json` — +1 item privilege (machine)
- `tests/Unit/Services/Agent/ContractV1Test.php` — `FROZEN_STATE_HASH` recalculé + justification 35.6
- `tests/Unit/Services/Agent/StateHasherTest.php` — test jumeau hash privilege (accounts/privilege/off)
- `agent/shared/hasher_test.go` — `frozenStateHash` jumeau + items 15→16 + test hash privilege
- `agent/shared/contract_test.go` — machine 7→8, `ResourceTypes` 13→14
- `app/Models/CapabilityProjection.php` — const `MECHANISM_PRIVILEGE` + docblock
- `app/Observers/CapabilityProjectionObserver.php` — dispatch `privilege` → guard (étendu, pas de jumeau)
- `app/Providers/AgentServiceProvider.php` — +1 ligne provider (enrobage `UpstreamAwareProvider::wrap`)
- `app/Providers/AppServiceProvider.php` — commentaire d'enregistrement observer (mention 35.6)
- `agent/windows/main_windows.go` — +1 entrée handler `privilege` (MachineEngine SYSTEM ; companion INTOUCHÉ)
- `agent/shared/version.go` — bump 2.7.0 → 2.8.0 + changelog + note publication (livre les 3 mécanismes)
- `docs/agent/contract-v1.md` — §7 liste + §7.9 payload privilege + §9 (exemple 35.6)
- `docs/agent/state-providers.md` — section `privilege`
- `docs/qa/domains/agent.md` — section Story 35.6 (append-only, scénarios 35.6.1–35.6.6 + checklist)

Créés :
- `app/Services/Agent/Providers/PrivilegeCapabilityProvider.php` — provider Machine (expand surchargé)
- `app/Services/Agent/Providers/PrivilegeAuthoringGuard.php` — guard SeDeny*-only (ALLOWED_PRIVILEGES)
- `app/Exceptions/PrivilegeAuthoringException.php` — exception d'authoring (jumeau fs_acl/firewall)
- `agent/shared/handler_privilege.go` — handler conteneur SANS store + PrivilegeOps + allowlist miroir
- `agent/shared/handler_privilege_test.go` — fake PrivilegeOps + scénarios a–i
- `agent/windows/handler_privilege_windows.go` — impl LSA (LsaOpenPolicy/Enumerate/Add/Remove + LookupSID)
- `database/migrations/2026_07_04_140000_seed_capability_rdp_denied_for_group.php` — seed de preuve
- `tests/Unit/Services/Agent/CapabilityPrivilegeProviderTest.php` — provider + guard + observer
- `tests/Unit/Services/Agent/CapabilityPrivilegeCompilationTest.php` — compilation (StateCompiler intouché)
- `tests/Feature/Migrations/CapabilityPrivilegeSeedTest.php` — seed (fichier DÉDIÉ)

### Change Log

- 2026-07-05 — Story 35.6 implémentée intégralement (dev fable) : type `privilege`
  additif au contrat v1 (§7.9, golden bumpé hashes jumeaux `e87fed16…`),
  provider + guard SeDeny*-only + observer étendu côté serveur, handler Go
  conteneur-sans-store + impl LSA Windows côté agent (2.8.0), seed
  `rdp_denied_for_group`, tests PHP/Go ciblés verts, docs contrat/providers/QA.
  Statut → review. Restent : publication manuelle release 2.8.0 (livre
  fs_acl+firewall+privilege) + migration seed à rejouer sur /vm.
- 2026-07-05 — Review opus : APPROUVÉ AVEC RÉSERVES (5 problèmes). Corrections
  appliquées (cf. `_bmad-output/codeReviews/35-6.md`) : #1 (🟠, décision Henri)
  **borne de portée des `accounts`** — denylist des principals à large portée
  (`Everyone`/`Authenticated Users`/`Domain Users`/`Users`/`Administrators`/
  `SYSTEM`/`Interactive`, SID/RID well-known) côté serveur (`PrivilegeAuthoringGuard::
  BROAD_PRINCIPALS`/`BROAD_SIDS`) ET côté agent (`isBroadPrincipalSid` sur SID
  résolu → erreur d'item sans application partielle) + 3 tests ; #2 warning seed
  (écrasement secpol visible) ; #3 `LsaFreeMemory` après check nil ; #4 log
  symétrique privilège hors allowlist ; #5 commentaire `OBJECT_ATTRIBUTES`.
  Post-corrections : PHP 54 passed / 288 assertions, Go complet + vet/build
  windows OK. AC3 désormais pleinement satisfait. Verdict final : APPROUVÉ.

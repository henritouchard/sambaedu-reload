# Story 36.2 : Mécanisme `firewall` — règles pare-feu possédées par groupe

Status: review

<!-- Source d'autorité : _bmad-output/planning-artifacts/epics-mecanismes-hors-registre.md
     (Epic 36 ne figure PAS dans epics.md). Décisions Henri actées :
     _bmad-output/ultradev/36-questions.md (Q3 = block sur lan|any REFUSÉ serveur ET agent,
     échappatoire = explicit hors RFC1918 ; Q4 = ciblage parc/salle suffit, per-user hors scope v1).
     PATRON DIRECT = Story 36.1 (fs_acl) LIVRÉE + sa review _bmad-output/codeReviews/36-1.md
     — les leçons de review sont INTÉGRÉES dès la conception (validation câblée au runtime,
     intersection mathématique pas textuelle, alignement serveur↔agent, description ≤ 255). -->

## Story

En tant que **référent numérique**,
je veux **couper l'accès Internet d'un parc de postes (salle d'examen) en gardant le réseau local**,
afin de **contrôler la connectivité sans toucher au câblage ni au DHCP**.

## Contexte & intention

Deuxième mécanisme HORS-REGISTRE de la bibliothèque de capacités (doctrine Epic 36 :
« mécanisme = code payé une fois, capacité = donnée »). Jumeau structurel de la 36.1
(`fs_acl`, LIVRÉE sur cette branche d'intégration) : contrat additif + provider serveur +
handler Go test/apply + défense en profondeur serveur ET agent + golden hashes jumeaux +
bump version + seed de preuve. La demande fondatrice — « couper Internet » — se projette
sur UNE règle pare-feu Windows `block out internet any` dans un groupe de règles possédé
par l'agent (`SambaEdu-Agent`).

**Différence STRUCTURELLE clé vs fs_acl (D4)** : une ACE NTFS ne porte aucun marqueur de
propriété (d'où le store « dernier appliqué » de 36.1) ; une règle pare-feu, SI. Le champ
`Grouping` de la règle EST le marqueur : l'agent possède le conteneur `SambaEdu-Agent` en
entier et le réconcilie (iso `registry_list`) — **AUCUN store fsacl-like n'est nécessaire
ici** (ne pas en créer un). Tout le reste du pare-feu (règles Windows, applis tierces,
admin local, politique par défaut, état du service) est invisible et intouchable.

**Ce que la story livre** :

1. **Contrat v1** : type `firewall` additif (`semantics: exclusive`, portée **Machine**),
   payload `{rule_id, direction, action, remote_scope, protocol, ensure}` (+
   `remote_addresses` ssi `explicit`, + `ports` ssi tcp|udp) — enums fermés de mots métier,
   AUCUNE syntaxe netsh/SDDL (D3). Golden + doc §7.8 bumpés (hashes jumeaux).
2. **Provider serveur** : `FirewallCapabilityProvider` (scope Machine, mécanisme
   `firewall`), `exclusiveKey() = rule_id` normalisé, `StateCompiler` intouché (D2).
3. **Validation d'authoring Q3** : service pur `FirewallAuthoringGuard` + **câblage
   runtime IMMÉDIAT** dans `CapabilityProjectionObserver` (leçon review 36.1 #2b — pas de
   guard « testé mais inopérant ») : `action: block` couvrant le LAN (RFC1918) ou tout
   (`/0`) est REFUSÉ ; échappatoire assumée = `remote_scope: explicit` avec adresses hors
   plages privées (intersection MATHÉMATIQUE, leçon #3).
4. **Handler Go `firewall`** (service SYSTEM uniquement) : réconciliation PAR CONTENEUR du
   groupe `SambaEdu-Agent` (désirées présentes+conformes, toute règle du groupe hors désir
   SUPPRIMÉE), traduction `internet` → plages inverses-RFC1918 FIGÉE dans le code (testée
   une fois), refus agent en défense en profondeur (mêmes critères Q3, dans `Test` ET
   `Apply` — leçon #2a). Impl Windows en **COM natif vtable sans dépendance**
   (INetFwPolicy2, iso pattern `handler_shortcuts_windows.go`) — netsh NE SAIT PAS poser
   `Grouping`.
5. **Capacité de preuve** : seed `internet_access` (« Accès Internet », enum
   `unmanaged`/`on` « Autorisé »/`off` « Coupé — réseau local seulement », opt-in,
   warning proxys).
6. Bump `agent/shared/version.go` **2.6.0 → 2.7.0** + note de publication.

**Pourquoi maintenant** : 36.1 et 36.3 sont mergées sur `ultradev/epic-36` — les fichiers
partagés (contrat, `version.go`, golden, `contract-v1.md`, observer) sont libres. 36.2
réutilise le patron ops/fake, la discipline golden et les leçons de la review 36.1.

## ⚠️ Pièges & tensions (lire AVANT de coder)

1. **Piège #1 — nouveau TYPE : le binaire antérieur IGNORE EN SILENCE (contrat §8/§9).**
   Un agent ≤ 2.6.0 qui reçoit `firewall` n'émet AUCUN statut. Symptôme : « salle coupée
   sans effet, zéro erreur ». La release **2.7.0 est à publier MANUELLEMENT** (update.sh ne
   publie jamais seul). NB : la 2.6.0 (fs_acl) n'a pas encore été publiée — la publication
   2.7.0 couvre les DEUX mécanismes d'un coup (à rappeler au Dev Agent Record).

2. **Piège #2 — la précédence par maille EXIGE que `on` émette un item RÉEL (écart assumé
   vs la lettre de l'epic « sans verbe ensure »).** Le compilateur arbitre par identité
   (`exclusiveKey`) entre items émis PAR MAILLE : une valeur qui n'émet RIEN ne peut
   JAMAIS battre une maille plus large qui émet quelque chose. Si `on` n'émettait rien, un
   broadcast `off` (item block) ne serait PAS annulé par un override de parc `on` — la
   salle resterait coupée. C'est l'invariant projet « maps symétriques : un off proposé
   écrit une VRAIE valeur » (`project_capability_value_map_symmetric_rule`) + la raison
   d'être du champ **`ensure ∈ present|absent`, TOUJOURS émis** (iso fs_acl piège #13 —
   type neuf, forme unique, parse Go trivial). `on` émet le MÊME `rule_id` avec
   `ensure: absent` → même identité → la précédence broadcast/parc marche dans les deux
   sens, et le groupe finit VIDE (l'AC epic « on ⇒ groupe vide » est satisfaite À LA
   LETTRE — sans règle allow inerte qui interagirait avec une politique par défaut
   restrictive). L'esprit de l'epic (« off symétrique et gratuit par conteneur ») reste
   vrai : un CHANGEMENT de `rule_id` ou de contenu n'a jamais besoin d'ensure, la
   réconciliation de groupe suffit ; `ensure:absent` est le keep-alive contre le piège #3
   ET le porteur de précédence. Justifier l'écart par écrit (seed + doc contrat).

3. **Piège #3 — type ABSENT du state = handler JAMAIS invoqué (engine.go itère les types
   présents).** Si l'état ne porte AUCUN item `firewall`, la réconciliation du groupe ne
   tourne pas : la règle block SURVIVRAIT. Fenêtres d'orphelin (iso 36.1) : (a) bascule
   directe `off` → `unmanaged` ; (b) poste qui QUITTE le parc porteur de l'override.
   **Conséquence terrain GRAVE ici** (contrairement à l'ACE bénigne de fs_acl) : la salle
   reste SANS INTERNET. Mitigations v1 (documentées, pas sur-conçues) : le retrait propre
   passe par `on` (« Autorisé »), JAMAIS par `unmanaged` — le `warning` de la capacité ET
   la doc contrat le disent en toutes lettres ; remède manuel trivial : les règles du
   groupe `SambaEdu-Agent` sont VISIBLES dans wf.msc et supprimables par un admin (le
   marqueur de propriété est DANS l'objet, pas dans un store opaque). NE PAS « corriger »
   en synthétisant des items serveur ni en touchant engine.go.

4. **Piège #4 — byte-echo Windows : écrire ce que le service pare-feu RELIT (miroir du
   piège GENERIC_* de 36.1).** Le service pare-feu NORMALISE certaines formes à
   l'écriture : un CIDR IPv4 dans `RemoteAddresses` est relu en forme `adresse/masque`
   pointé (`192.0.2.0/24` → `192.0.2.0/255.255.255.0`), les plages `a-b` sont relues
   telles quelles, les préfixes IPv6 restent en `/n`. Un `Test` en comparaison textuelle
   naïve dériverait en boucle (drift permanent, re-Apply à chaque cycle). Règles : la
   traduction `internet` émet des **PLAGES `a-b` pour IPv4** et un **préfixe pour IPv6**
   (formes stables à l'écho) ; la comparaison passe par une **normalisation canonique dans
   le code** (parse des deux côtés, comparaison d'ensembles d'intervalles — pas de match
   de chaîne brute) ; l'e2e lab DOIT vérifier « 2 cycles stables = compliant, zéro op »
   pour attraper toute forme d'écho imprévue.

5. **Piège #5 — le GROUPE est le marqueur de propriété : PAS de store (anti-piège #4 de
   36.1, inversé).** Ne pas créer de `firewall-state.json` : `Grouping = "SambaEdu-Agent"`
   identifie NOS règles dans l'objet Windows lui-même. Réconciliation à chaque passe :
   énumérer les règles DU GROUPE seulement ; désirées (`ensure:present`) présentes et
   conformes ; toute règle du groupe hors désir SUPPRIMÉE (y compris une règle qu'un
   tiers aurait étiquetée de notre groupe — assumé et documenté : le groupe nous
   appartient EN ENTIER, D4). Les règles HORS groupe ne sont JAMAIS énumérées comme
   nôtres ni touchées. Nom de règle dérivé, unique et stable :
   `SambaEdu-Agent: <rule_id>` (la suppression COM se fait par nom).

6. **Piège #6 — impl Windows : COM natif vtable, ZÉRO dépendance ; netsh est une impasse.**
   `netsh advfirewall firewall add rule` ne peut PAS poser `Grouping` (le paramètre
   `group` de netsh ne sert qu'à activer des groupes prédéfinis) — la propriété par groupe
   serait imposssible. `go-ole` = nouvelle dépendance, refusée (go.mod = x/sys + x/text
   seuls ; précédent maison : `handler_shortcuts_windows.go` fait du COM IShellLink en
   vtables syscall pures). Impl : `CoCreateInstance(HNetCfg.FwPolicy2)` → `INetFwPolicy2`
   → `get_Rules` → `INetFwRules` (Add / Remove(name) / get__NewEnum → `IEnumVARIANT` pour
   l'énumération) → `INetFwRule` (put/get Name, Grouping, Direction, Action, Protocol,
   LocalPorts, RemotePorts, RemoteAddresses, Enabled, Profiles). **GUIDs et ORDRE des
   vtables à VÉRIFIER dans les en-têtes SDK (`netfw.h`/`icftypes.h`) — pas de recopie de
   mémoire** (discipline 36.3). Quirk API connu : `put_Protocol` AVANT
   `put_LocalPorts`/`put_RemotePorts` (l'ordre inverse échoue). BSTR via oleaut32
   (`SysAllocString`/`SysFreeString`). `Profiles = ALL`, `Enabled = true` sur toute règle
   posée. Mutation d'une règle non conforme = **Remove + Add** (recréation — pas de
   mutation in-place, plus simple et atomique à l'échelle d'une règle).

7. **Piège #7 — Q3 : intersection MATHÉMATIQUE, jamais textuelle (leçon review 36.1 #3,
   transposée).** Le contournement 8.3 de fs_acl a son jumeau ici : `192.160.0.0/12`
   recouvre 192.168/16 sans jamais l'écrire ; `0.0.0.0/0` ou `::/0` couvrent tout. Le
   refus `block` sur portée couvrant le LAN se calcule par CHEVAUCHEMENT d'intervalles
   entre chaque adresse/CIDR de `remote_addresses` et les plages protégées — côté serveur
   (guard) ET côté agent (Go), avec la MÊME liste de plages protégées, constantes miroir
   documentées (leçon #4 : critères alignés, autorité finale = agent). Plages protégées :
   RFC1918 (10/8, 172.16/12, 192.168/16), loopback (127/8, ::1), link-local (169.254/16,
   fe80::/10), ULA (fc00::/7), et tout préfixe `/0`. `remote_scope: internet` est SÛRE par
   construction (les plages émises excluent tout ça) — c'est le point de Q3.

8. **Piège #8 — traduction `internet` = code FIGÉ + test golden-style ; IPv6 INCLUS.**
   La définition d'« Internet » vit dans le handler (une fonction pure, une constante
   testée avec la chaîne EXACTE attendue), jamais dans la donnée (D3). Sans le volet IPv6,
   « couper Internet » serait contourné trivialement sur un réseau dual-stack. Table
   normative (v1) — `RemoteAddresses` de la règle `internet` :
   - IPv4 (plages `a-b`, complément de {0/8, 10/8, 127/8, 169.254/16, 172.16/12,
     192.168/16, ≥ 224.0.0.0}) :
     `1.0.0.0-9.255.255.255`, `11.0.0.0-126.255.255.255`, `128.0.0.0-169.253.255.255`,
     `169.255.0.0-172.15.255.255`, `172.32.0.0-192.167.255.255`,
     `192.169.0.0-223.255.255.255` ;
   - IPv6 : `2000::/3` (unicast global — fe80::/10, fc00::/7 et ::1 restent joignables).
   Le test Go fige la chaîne complète (ordre inclus) ; toute évolution future = décision
   écrite, pas un drift silencieux.

9. **Piège #9 — golden = hashes figés JUMEAUX + comptages.** +1 item `firewall` en portée
   machine ⇒ recalculer via le `StateHasher` RÉEL : `ContractV1Test::FROZEN_STATE_HASH`
   (PHP) **ET** `frozenStateHash` (`agent/shared/hasher_test.go`, MÊME valeur),
   justification écrite dans les chaînes de commentaires des DEUX fichiers (règle 23.1).
   Comptages : `contract_test.go` machine 6→7 et types **12→13** ; `hasher_test.go`
   **14→15** items. `report.v1.json` INCHANGÉ avec justification (les items de rapport ne
   portent pas de payload ; le type entre à l'ingestion par la constante additive).
   `loop_test.go` : vérifier (normalement rien).

10. **Piège #10 — `rule_id` est une identité GLOBALE inter-capacités.** Deux capacités qui
    émettent le même `rule_id` COLLISIONNENT au compilateur (la plus spécifique gagne LA
    règle) : c'est la sémantique voulue quand c'est délibéré, un sabotage silencieux quand
    c'est accidentel. Convention de nommage : slug `^[a-z0-9][a-z0-9_-]{0,63}$`, préfixé
    par l'intention (`internet-block`). Invariant de TEST sur le catalogue seedé : deux
    projections firewall de capacités DIFFÉRENTES ne partagent aucun `rule_id` (le guard
    valide une projection isolée — l'unicité inter-capacités est un invariant de données,
    testé, pas un blocage runtime).

11. **Piège #11 — politique par défaut + état du service JAMAIS touchés : STRUCTUREL.**
    `FirewallOps` n'expose AUCUNE op sur la politique par défaut, les profils actifs ou le
    service MpsSvc — l'interdit est inexprimable, pas juste interdit (iso « pas de garde
    runtime sur-conçue », `feedback_no_overengineered_choices`). Ne pas « améliorer » en
    activant le pare-feu s'il est éteint : un pare-feu désactivé ⇒ la règle est posée mais
    inopérante — c'est VISIBLE à l'e2e et c'est un choix d'admin local, pas le nôtre.

12. **Piège #12 — description ≤ 255 (leçon review 36.1 / seed 35.x).**
    `capabilities.description` et `label` sont des `varchar(255)` PG : un dépassement
    passe en SQLite de test et explose en 22001 sur /vm
    (`project_sqlite_tests_no_varchar_enforcement`). `warning` est un TEXT (vérifié
    schéma) — pas de limite dure, rester concis quand même. Écrire le commentaire
    « ≤ 255 » dans la migration (iso seed `photo_viewer_restored`).

13. **Piège #13 — seed via Query Builder = observer PAS déclenché (note review 36.1).**
    `updateOrInsert` n'émet pas d'événement Eloquent : la validité du seed est prouvée par
    l'invariant de test sur les données réellement seedées (`FirewallAuthoringGuard` sur
    le catalogue), PAS par l'observer. L'observer, lui, protège Eloquent (UI/36.4/futurs
    écrans) — Feature test dédié.

14. **Piège #14 — l'expand firewall n'a PAS de ruche.** Le provider SURCHARGE `expand()`
    intégralement et implémente `hive(): ''` (docblock « non applicable — jamais
    consommé ») — iso `FsAclCapabilityProvider` (36.1 piège #14). Réutilise
    `resolveKeyValue()`/`UNMANAGED` hérités. Les providers registry/registry_list/fs_acl
    restent BYTE-IDENTIQUES (tests existants verts sans modification d'attendus).

15. **Piège #15 — pas de ciblage par utilisateur : STRUCTUREL (Q4/D7, iso 36.1 piège
    #10).** Provider scope Machine → le service SYSTEM fetch sans `?user` : un override
    UserGroup/User d'une capacité firewall est SANS EFFET. Le pare-feu par-utilisateur
    N'EXISTE PAS en v1 (limitation Windows assumée — Henri Q4) : « couper Internet » se
    cible par parc/salle. À PROUVER par un test de compilation machine-only et à
    DOCUMENTER (contrat + seed) — pas de garde-fou runtime.

## Décisions de design (tranchées — cadrage epic + décisions Henri + exploration code)

1. **D1 — contrat additif** : `'firewall'` s'AJOUTE à `StateContract::RESOURCE_TYPES`
   (PHP) et `ResourceTypes` (Go) ; `semantics: exclusive` ; portée Machine ; §9 mis à
   jour. La constante `CapabilityProjection::MECHANISM_FIREWALL` EXISTE déjà (stub
   « slice B ») — enrichir son docblock, ne pas la recréer.
2. **D2 — StateCompiler intouché** : `exclusiveKey() = strtolower(rule_id)` (1 segment)
   sur le provider.
3. **D3 — enums fermés au contrat** : `direction ∈ in|out` ; `action ∈ allow|block` ;
   `remote_scope ∈ internet|explicit` ; `protocol ∈ any|tcp|udp` ;
   `ensure ∈ present|absent` (TOUJOURS émis, piège #2). Champs conditionnels :
   `remote_addresses` (tableau de strings, chacune = IP littérale OU CIDR `addr/n` —
   IPv4 ou IPv6, AUCUN mot-clé Windows type `LocalSubnet`, AUCUNE plage `a-b` en
   authoring) présent SSI `remote_scope: explicit` et non vide ; `ports` (tableau de
   strings `"N"` ou `"N-M"`, 1-65535, N ≤ M) présent SSI `protocol ∈ tcp|udp` ET des
   ports sont ciblés — sémantique : ports DISTANTS pour `out`, LOCAUX pour `in`
   (documenté §7.8). `protocol: any` ⇒ `ports` INTERDIT. Zéro float (tout est string ou
   tableau de strings), jamais d'id de capacité (27.12). La traduction en objets
   INetFwRule vit dans le HANDLER.
4. **D4 — propriété par conteneur** : groupe `SambaEdu-Agent` (constante partagée
   `FirewallRuleGroup`, `agent/shared`), nom de règle `SambaEdu-Agent: <rule_id>`.
   L'agent possède le GROUPE en entier ; pas de store (piège #5).
5. **D5/Q3 — refus `block` couvrant le LAN, serveur ET agent** : plages protégées =
   RFC1918 + loopback + link-local + ULA + `/0` (piège #7), intersection calculée.
   `remote_scope: internet` toujours acceptée pour `block` (sûre par construction).
   Échappatoire = `explicit` avec adresses/CIDR publics uniquement.
6. **D6 — traduction `internet` figée dans le code** : table du piège #8, IPv4 plages
   inverses-RFC1918 + IPv6 `2000::/3`, test Go avec chaîne exacte.
7. **Q4 — parc/salle uniquement** : aucun per-user, structurel (piège #15).
8. **Spec `firewall`** : `spec = { "rules": [ {rule_id, direction, action, remote_scope,
   protocol, ports?, remote_addresses?, ensure?}, … ] }`. SEUL `ensure` est résoluble par
   map valeur-capacité (littéral OU map via `resolveKeyValue()` hérité ; clé de map
   absente ⇒ sentinelle UNMANAGED ⇒ entrée non émise ; forme assoc inattendue sur un
   autre champ ⇒ entrée non émise défensif). `ensure` défaut `present`. (fs_acl rendait
   aussi `trustee` mappable ; firewall n'a pas d'équivalent — v1 minimal, extension
   possible plus tard sans casse.)
9. **Seed `internet_access`** — enum opt-in à TROIS valeurs (l'enum epic telle quelle —
   contrairement à fs_acl, PAS besoin d'une 4e valeur : `on` EST déjà l'action réelle
   symétrique) : `unmanaged` « Non géré » (défaut, sentinelle), `on` « Autorisé » (item
   `ensure:absent` → groupe vidé), `off` « Coupé — réseau local seulement » (item
   `ensure:present` → règle `block out internet any`). `warning` NON VIDE : proxys
   d'établissement (un proxy LAN re-donne Internet — à couper via une règle `explicit`
   dédiée si présent), retrait propre par « Autorisé » JAMAIS par « Non géré »
   (piège #3), check-in agent/serveur SE5/partages préservés (le LAN reste ouvert).
10. **Guard d'authoring = service pur SÉPARÉ** `FirewallAuthoringGuard` (violations
    nommées, messages FR, constantes publiques `PROTECTED_RANGES`, enums, regex
    `RULE_ID`) — même API que `FsAclAuthoringGuard` (entrée
    `[{capability, warning, spec}]`), **câblé au runtime dès cette story** :
    `CapabilityProjectionObserver::saving()` gagne un dispatch par mécanisme
    (`fs_acl` → guard fs_acl inchangé ; `firewall` → nouveau guard, nouvelle
    `FirewallAuthoringException`). Leçon review 36.1 #2b appliquée D'ENTRÉE.
11. **Wiring agent** : handler `firewall` dans la map `Handlers` du SERVICE SYSTEM
    (`main_windows.go`) UNIQUEMENT — jamais dans `companion_windows.go`.
12. **Règles du guard** (au-delà de Q3) : enums hors domaine refusés ; `rule_id` hors
    slug refusé ; `remote_scope: explicit` sans `remote_addresses` (ou vide, ou entrée
    non parsable) refusé ; `remote_addresses` présent avec `remote_scope: internet`
    refusé (forme unique) ; `ports` avec `protocol: any` refusé ; port hors 1-65535 ou
    borne inversée refusé ; toute projection portant AU MOINS une règle `action: block`
    exige un `warning` capacité non vide (miroir de la règle deny⇒warning de 36.1).

## Acceptance Criteria

### AC1 — Contrat v1 : type `firewall` publié (D1)

**Given** le contrat `se5.desired-state/v1`
**When** le type `firewall` est publié
**Then** `StateContract::RESOURCE_TYPES` (PHP) et `ResourceTypes` (`agent/shared/contract.go`)
gagnent `'firewall'` (ajout additif — `ReportRequest` et l'ingestion 24.1 l'acceptent sans
autre changement)
**And** le payload est `{rule_id, direction, action, remote_scope, protocol, ensure}` +
`remote_addresses` ssi `explicit` + `ports` ssi tcp|udp ciblés (D3) : enums fermés de mots
métier, AUCUNE syntaxe netsh/SDDL, zéro float, jamais d'id de capacité (27.12), `ensure`
TOUJOURS émis (piège #2)
**And** `docs/agent/contract-v1.md` est mis à jour : §7 (liste des identifiants), nouvelle
sous-section **§7.8 Payload `firewall`** (tableau des clés + exemple + sémantique
complète : portée Machine, propriété PAR GROUPE `SambaEdu-Agent` D4 — le Grouping est le
marqueur, pas de store —, réconciliation par conteneur, traduction `internet` par le
handler avec table indicative, refus agent Q3, fenêtres d'orphelin piège #3 et retrait
propre par `on`, écart assumé `ensure` avec justification précédence/maps symétriques,
politique par défaut et service jamais touchés, pas de ciblage par utilisateur Q4), §9
(nouveau type = mineur ; agent antérieur = type ignoré EN SILENCE, publication requise)
**And** le golden `state.v1.json` gagne UN item `firewall` en portée machine
(`{rule_id: "internet-block", direction: "out", action: "block", remote_scope: "internet",
protocol: "any", ensure: "present"}` — 6 clés, l'item de la capacité de preuve) avec
justification écrite dans `ContractV1Test.php` ET `hasher_test.go` ;
`FROZEN_STATE_HASH` (PHP) = `frozenStateHash` (Go) recalculés à l'identique via le
`StateHasher` RÉEL ; comptages ajustés (`contract_test.go` machine 6→7, types 12→13 ;
`hasher_test.go` 14→15) ; `report.v1.json` INCHANGÉ avec justification écrite
**And** la canonicalisation est prouvée SANS toucher les hashers : un test PHP + un test
Go montrent que deux items ne différant que par `ensure` (ou par `rule_id`) ont des hashes
distincts, et qu'un item AVEC `ports`/`remote_addresses` hashe différemment du même item
sans (les clés optionnelles entrent naturellement au canon).

### AC2 — Provider serveur : expansion, compilateur intouché (D2)

**Given** une capacité active portant une projection `windows/firewall`
**When** `FirewallCapabilityProvider` (scope Machine, `mechanism() =
CapabilityProjection::MECHANISM_FIREWALL` — constante EXISTANTE, docblock enrichi) l'expanse
**Then** chaque entrée `rules[]` de la `spec` produit AU PLUS un item : `ensure` résolu
par `resolveKeyValue()` (littéral OU map valeur-capacité ; clé de map absente ⇒ UNMANAGED
⇒ entrée non émise ; forme assoc inattendue ⇒ non émise défensif, jamais d'exception au
render) ; enums hors domaine, `rule_id` hors slug, formes conditionnelles incohérentes
(`explicit` sans adresses, `internet` avec adresses, `ports` avec `any`) ⇒ entrée non
émise (défensif — le guard refuse déjà en amont) ; payload émis = exactement les clés de
D3 (6 clés + conditionnelles), tout en strings
**And** `exclusiveKey(payload) = rule_id` minuscule (1 segment) ; un test de compilation
(StateCompiler INTOUCHÉ) prouve : (a) précédence broadcast/parc sur identité ÉGALE
(broadcast `off` → `ensure:present` battu par override de parc `on` → `ensure:absent` sur
le MÊME `rule_id`, et l'inverse) ; (b) deux règles de `rule_id` distincts COEXISTENT
(cumul dans le groupe) ; (c) deux capacités émettant le MÊME `rule_id` collisionnent
(la plus spécifique gagne — comportement documenté piège #10)
**And** un test de compilation machine-only (`TargetContext::for($ws, null)`) prouve qu'un
override UserGroup n'atteint JAMAIS un item firewall (piège #15/Q4)
**And** le provider est câblé dans `AgentServiceProvider` (1 ligne, enrobage
`UpstreamAwareProvider::wrap` iso autres providers) et reste Postgres pur (zéro
AD/LdapRecord/APCu) ; les providers registry/registry_list/fs_acl restent byte-identiques
(tests existants verts sans modification).

### AC3 — Validation d'authoring Q3 : guard + observer CÂBLÉ AU RUNTIME

**Given** l'ensemble des projections `windows/firewall`
**When** `FirewallAuthoringGuard` (service PUR, même API que `FsAclAuthoringGuard` :
`violations([{capability, warning, spec}])`, messages FR nommant capacité + rule_id)
les valide
**Then** sont REFUSÉS :
- `action: block` avec `remote_scope: explicit` dont AU MOINS une entrée de
  `remote_addresses` CHEVAUCHE une plage protégée (RFC1918, 127/8, ::1, 169.254/16,
  fe80::/10, fc00::/7) ou est un préfixe `/0` — chevauchement calculé par INTERSECTION
  d'intervalles (piège #7 : `192.160.0.0/12`, `0.0.0.0/0`, `::/0` sont refusés SANS
  qu'aucune plage privée ne soit écrite littéralement) ; `block` + `internet` reste
  AUTORISÉ (prouvé par test — c'est l'usage nominal Q3)
- enums hors domaine, `rule_id` hors slug `^[a-z0-9][a-z0-9_-]{0,63}$`, `explicit` sans
  `remote_addresses`/vide/non parsable (mot-clé Windows, plage `a-b`, chaîne arbitraire),
  `internet` AVEC `remote_addresses`, `ports` avec `protocol: any`, port hors 1-65535 ou
  plage inversée
**And** toute projection portant AU MOINS une règle `block` exige un `warning` capacité
non vide (violation sinon)
**And** **le guard est câblé au runtime DANS CETTE STORY** (leçon review 36.1 #2b — pas de
guard orphelin) : `CapabilityProjectionObserver::saving()` dispatche par mécanisme —
`firewall` ⇒ guard firewall, violation ⇒ nouvelle `FirewallAuthoringException` (message FR,
INSERT/UPDATE annulé) ; le comportement `fs_acl` existant est INCHANGÉ (tests 36.1 verts) ;
les autres mécanismes restent ignorés ; Feature test étendu
(`tests/Feature/Observers/CapabilityProjectionObserverTest.php`) : block+RFC1918 refusé et
NON écrit, block+`0.0.0.0/0` refusé, projection `internet` valide passe, cas fs_acl
existants inchangés
**And** l'enforcement catalogue est exécuté par un invariant de test sur les données
réellement seedées (piège #13 : le seed Query Builder ne déclenche pas l'observer) +
invariant d'unicité des `rule_id` inter-capacités (piège #10) ; docblock : réutilisable
par de futurs formulaires, autorité finale = agent.

### AC4 — Handler Go `firewall` : réconciliation par groupe + traduction + refus (D4-D6)

**Given** le handler Go `firewall` (nouveau `agent/shared/handler_firewall.go`, instancié
par le SERVICE SYSTEM seul — `main_windows.go` ; JAMAIS le compagnon), ops injectées
`FirewallOps { ListGroupRules(group) ([]FwRule, error) ; AddRule(FwRule) error ;
RemoveRule(name string) error }` (impl Windows dans un nouveau
`agent/windows/handler_firewall_windows.go` en COM natif vtable — piège #6 ; fake en
mémoire pour les tests hôte, portant AUSSI des règles hors groupe pour prouver qu'elles ne
sont jamais touchées) — l'interface n'expose AUCUNE op de politique par défaut/service
(piège #11, structurel)
**When** il converge les items `firewall`
**Then** parse strict du payload (enums bornés, cohérence conditionnelle
`explicit`/`ports`, `error` enveloppe pour le type sinon — iso registry), dédoublonnage
par `rule_id` (dernière occurrence, ordre trié — iso `desiredSpecs`), traduction
`remote_scope: internet` → `RemoteAddresses` = table FIGÉE du piège #8 (fonction pure +
test avec la chaîne EXACTE, IPv4 plages + IPv6 `2000::/3`)
**And** `Test` = énumère les règles du groupe `SambaEdu-Agent` SEULEMENT ; conforme ssi :
chaque item `present` a sa règle (`SambaEdu-Agent: <rule_id>`) avec direction, action,
protocol, ports, adresses ÉQUIVALENTS (comparaison par normalisation canonique, piège #4 —
jamais de match de chaîne brute sur les adresses), Enabled, Grouping exacts ; AUCUNE règle
du groupe hors état désiré présent (couvre les items `absent` ET les règles étrangères au
state) ; zéro item `present` + règles du groupe restantes ⇒ `drift`
**And** `Apply` (effort maximal par règle, première erreur remontée à la fin, idempotent —
2 passes stables = zéro op) : (1) supprime toute règle du groupe hors désir (strays +
cibles des items `absent`) ; (2) pour chaque item `present` : règle absente ⇒ Add ; règle
non conforme ⇒ Remove PUIS Add (recréation, piège #6) ; conforme ⇒ zéro op — un état
désiré effectif VIDE (que des `absent`) VIDE le groupe ; règles hors groupe, politique par
défaut, état du service JAMAIS touchés (D4, prouvé par le fake)
**And** refus agent (défense en profondeur Q3, piège #7, dans `Test` ET `Apply` — leçon
review 36.1 #2a) : item `present` `action: block` dont la portée effective couvre une
plage protégée (via `explicit` chevauchant RFC1918/loopback/link-local/ULA ou `/0`) ⇒
erreur d'item ISOLÉE, jamais posée — constantes Go MIROIR du guard PHP, mêmes plages ;
adresse non parsable ⇒ erreur d'item ; les AUTRES items convergent, l'erreur remonte
TOUJOURS (type `error`, jamais d'application partielle silencieuse)
**And** la policy STRICT est démontrée À TRAVERS le moteur (`engine.go` INTOUCHÉ, iso
`TestRegistryAbsentThroughEngineStrictRedrift`) : règle gérée supprimée à la main ⇒
`drift` + re-pose ; règle étrangère injectée dans le groupe ⇒ `drift` + suppression ;
bascule `present` → `absent` (même `rule_id`) ⇒ règle retirée, groupe vide, `compliant` ;
état stable ⇒ `compliant`, zéro op.

### AC5 — Capacité de preuve : seed `internet_access`

**Given** la nouvelle migration
`database/migrations/2026_07_04_120000_seed_capability_internet_access.php` (pattern iso
36.1/lot CD95 : `updateOrInsert` par `key` puis par `(capability_id, os, mechanism)`,
idempotente, garde `hasTable`, `down()` par suppression de la `key`)
**When** elle est jouée
**Then** `internet_access` naît : label « Accès Internet », enum opt-in
(`default_value = 'unmanaged'`, options `[unmanaged: 'Non géré', on: 'Autorisé',
off: 'Coupé — réseau local seulement']` — l'enum epic TELLE QUELLE, convention
« sujet + état »), `description` ≤ 255 (piège #12, commentaire dans la migration),
`warning` NON VIDE (proxys d'établissement : un proxy LAN re-donne Internet — à couper via
règle `explicit` dédiée si présent ; retrait propre par « Autorisé », JAMAIS par « Non
géré » — piège #3 ; le LAN reste ouvert : check-in agent, serveur SE5 et partages
préservés)
**And** sa projection `windows/firewall` porte UNE règle :
`{rule_id: 'internet-block', direction: 'out', action: 'block', remote_scope: 'internet',
protocol: 'any', ensure: {'off': 'present', 'on': 'absent'}}` (`unmanaged` absent de la
map = sentinelle ; le commentaire de tête documente : écart `ensure` assumé avec la
justification précédence/maps symétriques piège #2, fenêtres d'orphelin piège #3 et
conséquence terrain, groupe visible wf.msc comme remède manuel, pas de ciblage user Q4)
**And** des tests d'intégration provider sur données réelles (dans un NOUVEAU
`tests/Feature/Migrations/CapabilityFirewallSeedTest.php` — fichier dédié, ne pas toucher
`CapabilitiesSchemaAndSeedTest.php` ni `CapabilityFsAclSeedTest.php`) prouvent :
`off` ⇒ 1 item `{internet-block, out, block, internet, any, present}` ; `on` ⇒ 1 item
`ensure:absent` (MÊME identité) ; `unmanaged` ⇒ rien ; idempotence + réversibilité de la
migration ; `description`/`label` ≤ 255 ; l'invariant `FirewallAuthoringGuard` passe sur
le catalogue seedé ET un combo interdit Q3 fabriqué (`block` `explicit` `192.168.0.0/16`,
puis `0.0.0.0/0`, puis `192.160.0.0/12`) est refusé ; unicité des `rule_id`
inter-capacités (piège #10)
**And** la note e2e lab (exécution MANUELLE par l'opérateur — hors périmètre du dev) est
écrite au Dev Agent Record : poste armé `off` → ping ET HTTP externes KO (IPv4, et IPv6 si
le lab en a), check-in agent OK, UI SE5 OK, partages SMB OK, DNS local OK ; wf.msc montre
la règle `SambaEdu-Agent: internet-block` (groupe `SambaEdu-Agent`) ; retour `on` →
Internet restauré SANS reboot au cycle suivant, groupe vide ; **2 cycles stables ⇒
`compliant`, zéro op** (attrape toute normalisation d'écho imprévue, piège #4).

### AC6 — Version agent + note de publication

**Given** l'agent modifié
**Then** `agent/shared/version.go` est bumpé (**2.6.0 → 2.7.0**) avec entrée de changelog
(style 2.6.0 : mécanisme `firewall`, propriété par groupe, traduction inverse-RFC1918,
refus Q3 en défense en profondeur, COM natif)
**And** la note de fin de story rappelle : un binaire ≤ 2.6.0 IGNORE le type `firewall` EN
SILENCE (§8/§9) → release **2.7.0 à publier MANUELLEMENT** (update.sh ne publie jamais
seul) ; la 2.6.0 (fs_acl) n'ayant pas encore été publiée, la publication 2.7.0 livre les
DEUX mécanismes ; la migration de seed est **à rejouer sur /vm** (`php artisan migrate`,
`migrate:status` d'abord, jamais auto-appliquée) — sans publication la capacité est inerte.

## Tasks / Subtasks

- [x] **Task 1 — Contrat & golden files (AC1)** *(commencer ici : fige le wire format)*
  - [x] 1.1 `app/Services/Agent/StateContract.php` : `'firewall'` dans `RESOURCE_TYPES`
        (commentaire Story 36.2, iso entrée 36.1) ; `agent/shared/contract.go` :
        `"firewall"` dans `ResourceTypes`.
  - [x] 1.2 `docs/agent/contract-v1.md` : §7 liste + **§7.8 Payload `firewall`** (tableau
        clés + conditionnelles, exemple, sémantique D4 groupe/pas de store, table
        indicative traduction internet, refus Q3, fenêtres d'orphelin + retrait par `on`,
        écart `ensure` justifié, politique par défaut/service intouchés, pas de ciblage
        user) + §9 (nouveau type = mineur, silence binaire antérieur).
  - [x] 1.3 `tests/Fixtures/Agent/state.v1.json` : +1 item machine
        `{"type":"firewall","semantics":"exclusive","payload":{"rule_id":"internet-block","direction":"out","action":"block","remote_scope":"internet","protocol":"any","ensure":"present"},"hash":"<recalculé via StateHasher::hashItem>"}`.
  - [x] 1.4 `ContractV1Test.php` : `FROZEN_STATE_HASH` recalculé + justification 36.2 dans
        la chaîne de commentaires (règle 23.1). `agent/shared/hasher_test.go` :
        `frozenStateHash` = MÊME valeur + justification + comptage 14→15.
        `agent/shared/contract_test.go` : machine 6→7, types 12→13. `loop_test.go` :
        vérifier (normalement rien). Justification écrite `report.v1.json` INCHANGÉ.
  - [x] 1.5 Tests hash : PHP (`StateHasherTest`) et Go (`hasher_test.go`) — deux items
        firewall ne différant que par `ensure` (et par `rule_id`) ⇒ hashes distincts ;
        item avec `ports`/`remote_addresses` ⇒ hash distinct du même sans ; hashers
        INTOUCHÉS.
- [x] **Task 2 — Provider PHP + guard + observer (AC2, AC3)**
  - [x] 2.1 `app/Models/CapabilityProjection.php` : enrichir le docblock de
        `MECHANISM_FIREWALL` (constante EXISTANTE — contrat §7.8, portée Machine, groupe
        SambaEdu-Agent, pas de store).
  - [x] 2.2 NOUVEAU `app/Services/Agent/Providers/FirewallCapabilityProvider.php`
        (extends `AbstractCapabilityStateProvider`) : `scope() = Machine`,
        `mechanism() = MECHANISM_FIREWALL`, `hive() = ''` (piège #14), `exclusiveKey()`
        1 segment, `expand()` surchargé (spec `rules[]`, `ensure` via `resolveKeyValue()`,
        enums + cohérence conditionnelle défensives, payload strings/tableaux de strings).
  - [x] 2.3 `app/Providers/AgentServiceProvider.php` : +1 ligne provider (commentaire
        36.2, enrobage `UpstreamAwareProvider::wrap` iso autres).
  - [x] 2.4 NOUVEAU `app/Services/Agent/Providers/FirewallAuthoringGuard.php` (service
        pur — AC3) : constantes publiques `PROTECTED_RANGES` (RFC1918 + loopback +
        link-local + ULA, IPv4 ET IPv6), enums, regex `RULE_ID` ; parsing IP/CIDR +
        INTERSECTION d'intervalles (piège #7 — helper privé testable) ; règles D12 ;
        violations FR nommant capacité + rule_id ; docblock (autorité finale = agent,
        alignement des plages avec le Go, leçons review 36.1 #3/#4).
  - [x] 2.5 NOUVELLE `app/Exceptions/FirewallAuthoringException.php` (iso
        `FsAclAuthoringException`) ; `app/Observers/CapabilityProjectionObserver.php` :
        dispatch par mécanisme (fs_acl inchangé, firewall → nouveau guard) — docblock mis
        à jour (le mécanisme firewall n'est PLUS « non concerné »).
  - [x] 2.6 NOUVEAU `tests/Unit/Services/Agent/CapabilityFirewallProviderTest.php` :
        (a) expansion 6 clés + conditionnelles (strings, pas de fuite d'id) ; (b) map
        `ensure` + sentinelle UNMANAGED + assoc inattendue non émise ; (c) enums hors
        domaine / incohérences conditionnelles non émis ; (d) `exclusiveKey` = rule_id
        minuscule ; (e) provider Postgres pur (grep AD/APCu iso existant). + tests
        unitaires du guard : Q3 (RFC1918 littéral, CIDR englobant `192.160.0.0/12`,
        `0.0.0.0/0`, `::/0`, `fc00::/7` refusés ; `block internet` AUTORISÉ ; `explicit`
        publics `8.8.8.8`+`203.0.113.0/24` AUTORISÉ), mot-clé/plage `a-b`/non-parsable
        refusés, `ports`+`any` refusé, bornes de ports, slug, block sans warning refusé.
  - [x] 2.7 NOUVEAU `tests/Unit/Services/Agent/CapabilityFirewallCompilationTest.php`
        (StateCompiler INTOUCHÉ) : précédence sur identité égale (broadcast `off`/present
        vs parc `on`/absent, DANS LES DEUX SENS) ; coexistence rule_ids distincts ;
        collision inter-capacités même rule_id (la plus spécifique gagne, piège #10) ;
        compile machine-only (override UserGroup sans effet, piège #15).
  - [x] 2.8 `tests/Feature/Observers/CapabilityProjectionObserverTest.php` : cas firewall
        (block+RFC1918 refusé + non écrit, block+`/0` refusé, projection `internet`
        valide passe) ; cas fs_acl existants INCHANGÉS.
- [x] **Task 3 — Handler Go + impl Windows (AC4, AC6)**
  - [x] 3.1 NOUVEAU `agent/shared/handler_firewall.go` : constante `FirewallRuleGroup =
        "SambaEdu-Agent"` + dérivation du nom `SambaEdu-Agent: <rule_id>` ; type `FwRule`
        + interface `FirewallOps` (3 ops SEULEMENT — piège #11, doc de sémantique/
        idempotence par op) ; parse strict payload (enums bornés, cohérence
        conditionnelle, `error` enveloppe sinon) ; dédoublonnage par rule_id ; fonction
        pure `internetRemoteAddresses()` (table piège #8 figée + doc) ; plages protégées
        MIROIR du guard PHP + intersection (refus Q3 dans Test ET Apply) ; normalisation
        canonique des adresses pour la comparaison (piège #4) ; Test/Apply selon AC4
        (réconciliation par groupe, strays supprimés, recréation Remove+Add, effort
        maximal `firstErr`, idempotence) ; doc de tête (D4 : le Grouping est le marqueur,
        pas de store — anti-36.1).
  - [x] 3.2 NOUVEAU `agent/windows/handler_firewall_windows.go` : impl `FirewallOps` en
        COM natif vtable (piège #6, iso `handler_shortcuts_windows.go`) —
        `CoCreateInstance(HNetCfg.FwPolicy2)` → `INetFwPolicy2.get_Rules` →
        énumération `get__NewEnum`/`IEnumVARIANT` filtrée sur `Grouping` ; `Add` (ordre
        `put_Protocol` AVANT les ports, `Profiles=ALL`, `Enabled=true`, `Grouping`,
        `Name`) ; `Remove(name)` ; BSTR oleaut32 ; GUIDs/vtables VÉRIFIÉS sur `netfw.h`
        (commentaire de provenance — pas de recopie de mémoire).
  - [x] 3.3 `agent/windows/main_windows.go` : entrée `"firewall"` dans la map `Handlers`
        du MachineEngine (commentaire 36.2 : propriété par groupe, SYSTEM seul) —
        `companion_windows.go` INTOUCHÉ.
  - [x] 3.4 NOUVEAU `agent/shared/handler_firewall_test.go` (fake `FirewallOps` en
        mémoire, avec règles HORS groupe témoins) : (a) pose + relecture conforme + 2e
        Apply zéro op ; (b) règle gérée supprimée à la main ⇒ re-drift STRICT À TRAVERS
        le moteur ; (c) règle étrangère injectée dans le groupe ⇒ supprimée ; (d) bascule
        present→absent même rule_id ⇒ groupe vidé, compliant ; (e) désir effectif vide
        (que des absent) ⇒ groupe vidé ; (f) règle non conforme (action/ports/adresses
        modifiés) ⇒ Remove+Add ; (g) règles hors groupe JAMAIS touchées (le fake
        l'atteste) ; (h) traduction internet = chaîne EXACTE figée (IPv4 plages + IPv6) ;
        (i) refus Q3 : block explicit RFC1918 / CIDR englobant / `/0` ⇒ erreur d'item
        ISOLÉE (les autres convergent, type error), dans Test ET Apply ; (j) adresse non
        parsable ⇒ erreur d'item ; (k) payload invalide (`ports`+`any`, enum inconnu) ⇒
        error type ; (l) normalisation : forme d'écho `adresse/masque` vs CIDR ⇒
        compliant (pas de drift-loop, piège #4) ; (m) dédoublonnage rule_id.
  - [x] 3.5 `agent/shared/version.go` : bump **2.7.0** + entrée changelog.
- [x] **Task 4 — Seed de preuve (AC5)**
  - [x] 4.1 NOUVELLE migration `2026_07_04_120000_seed_capability_internet_access.php`
        (pattern 36.1 exact ; commentaires de tête : écart `ensure` justifié piège #2,
        fenêtres d'orphelin + gravité terrain piège #3, remède wf.msc, proxys, pas de
        ciblage user Q4, description ≤ 255 piège #12).
  - [x] 4.2 NOUVEAU `tests/Feature/Migrations/CapabilityFirewallSeedTest.php` (fichier
        DÉDIÉ) : seed (options/défaut/warning/description ≤ 255/projection 1 règle),
        idempotence/réversibilité, intégration provider sur données réelles
        (off/on/unmanaged), invariant `FirewallAuthoringGuard` sur le catalogue seedé +
        combos Q3 fabriqués refusés + unicité rule_id inter-capacités.
- [x] **Task 5 — Validation finale + docs**
  - [x] 5.1 Tests HÔTE ciblés (php8.4 + sqlite, JAMAIS de run massif) :
        `ContractV1Test|StateHasherTest`,
        `CapabilityFirewallProviderTest|CapabilityFirewallCompilationTest`,
        `CapabilityFirewallSeedTest`, `CapabilityProjectionObserverTest`, non-régression
        `CapabilityFsAclProviderTest|CapabilityFsAclCompilationTest|CapabilityFsAclSeedTest`
        + `CapabilityRegistryProviderTest|CapabilityRegistryListProviderTest`.
  - [x] 5.2 Tests Go (`~/go-toolchain/go/bin/go`, hors PATH) : `cd agent && go test ./...` ;
        `GOOS=windows go build ./...` ; `go vet ./...` (linux ET GOOS=windows).
  - [x] 5.3 `docs/agent/state-providers.md` : section `firewall` (mécanisme, exclusiveKey,
        groupe = marqueur, traduction internet, limites pièges #3/#10).
        `docs/qa/domains/agent.md` : section « Story 36.2 » append-only (scénarios :
        coupure + LAN préservé, retour on sans reboot, 2 cycles stables, règle étrangère
        au groupe purgée, binaire antérieur silencieux, e2e lab manuel).
  - [x] 5.4 Dev Agent Record : (a) justification golden (item ajouté, hashes jumeaux) ;
        (b) ⚠️ release **2.7.0 à publier manuellement** (couvre AUSSI la 2.6.0 fs_acl
        jamais publiée) + migration **à rejouer sur /vm** ; (c) protocole e2e lab MANUEL
        (AC5 — ping/HTTP externes KO, check-in + SE5 + partages + DNS local OK, wf.msc,
        retour `on` sans reboot, 2 cycles stables) ; (d) note IPv6 lab (si le lab n'a pas
        d'IPv6, le volet IPv6 de la traduction reste validé par le test Go seul).

## Dev Notes

### Fichiers à toucher (exhaustif prévu)

| Fichier | Nature |
|---|---|
| `app/Services/Agent/StateContract.php` | `RESOURCE_TYPES` += `'firewall'` |
| `agent/shared/contract.go` | `ResourceTypes` += `"firewall"` |
| `docs/agent/contract-v1.md` | §7 liste + §7.8 payload firewall + §9 |
| `docs/agent/state-providers.md` | section `firewall` |
| `docs/qa/domains/agent.md` | section 36.2 append-only |
| `tests/Fixtures/Agent/state.v1.json` | +1 item firewall (machine) |
| `tests/Unit/Services/Agent/ContractV1Test.php` | `FROZEN_STATE_HASH` + justification |
| `tests/Unit/Services/Agent/StateHasherTest.php` | tests hash ensure/rule_id/optionnels |
| `agent/shared/hasher_test.go` | `frozenStateHash` jumeau + 14→15 + tests |
| `agent/shared/contract_test.go` | machine 6→7, types 12→13 |
| `app/Models/CapabilityProjection.php` | docblock `MECHANISM_FIREWALL` (constante existante) |
| `app/Services/Agent/Providers/FirewallCapabilityProvider.php` | NOUVEAU — provider Machine |
| `app/Services/Agent/Providers/FirewallAuthoringGuard.php` | NOUVEAU — guard Q3 |
| `app/Exceptions/FirewallAuthoringException.php` | NOUVEAU — iso FsAclAuthoringException |
| `app/Observers/CapabilityProjectionObserver.php` | dispatch par mécanisme (+ firewall) |
| `app/Providers/AgentServiceProvider.php` | +1 provider au StateCompiler |
| `tests/Unit/Services/Agent/CapabilityFirewallProviderTest.php` | NOUVEAU (provider + guard) |
| `tests/Unit/Services/Agent/CapabilityFirewallCompilationTest.php` | NOUVEAU |
| `tests/Feature/Observers/CapabilityProjectionObserverTest.php` | + cas firewall |
| `agent/shared/handler_firewall.go` | NOUVEAU — handler + FirewallOps + traduction |
| `agent/shared/handler_firewall_test.go` | NOUVEAU — fake + scénarios a–m |
| `agent/windows/handler_firewall_windows.go` | NOUVEAU — impl COM natif INetFwPolicy2 |
| `agent/windows/main_windows.go` | +1 entrée handler (SYSTEM) |
| `agent/shared/version.go` | bump 2.7.0 + changelog |
| `database/migrations/2026_07_04_120000_seed_capability_internet_access.php` | NOUVEAU seed |
| `tests/Feature/Migrations/CapabilityFirewallSeedTest.php` | NOUVEAU (fichier dédié) |

**NE PAS TOUCHER** : `app/Services/Agent/StateCompiler.php` (D2), `StateHasher.php` +
`agent/shared/hasher.go` (canonicalisation générique — seulement des tests),
`agent/shared/engine.go` (grain par type + STRICT figés),
`agent/windows/companion_windows.go` (firewall = machine-only),
`AbstractCapabilityStateProvider.php`, `agent/go.mod` (ZÉRO dépendance ajoutée — piège
#6), TOUT le périmètre fs_acl livré par 36.1 (`FsAclCapabilityProvider`,
`FsAclAuthoringGuard`, `AudienceTokens`, `handler_fs_acl*.go`, `files.go`, seed 36.1,
tests fs_acl — l'observer est le SEUL fichier 36.1 modifié, en dispatch additif),
`CapabilitySpecCollisionGuard.php` + `CapabilitiesSchemaAndSeedTest.php` (périmètre
registre/36.3), `tests/Fixtures/Agent/report.v1.json` (inchangé justifié), seeds
antérieurs, `sprint-status.yaml` (hors sa propre ligne) / `backlog.*` / `routes/web.php`
(orchestrateur), tout fichier de la story 36.4.

### Patterns existants à imiter

- **Chaîne de justification golden** : commentaires numérotés `ContractV1Test.php` et
  `hasher_test.go` (dernière entrée 36.1) — ajouter l'entrée 36.2 dans le MÊME style,
  hash jumeau vérifié croisé, recalcul via le `StateHasher` RÉEL (script scratchpad hôte,
  cf. Debug Log 36.1).
- **Provider à expand surchargé** : `FsAclCapabilityProvider` (36.1) — le jumeau direct
  (scope Machine, `hive()=''`, `resolveKeyValue()`, défensif sans exception au render).
- **Réconciliation par conteneur** : `handler_registry_list.go` (D3 « l'agent possède la
  clé-conteneur » — ici le conteneur est le GROUPE de règles ; purge des étrangers,
  jamais les voisins hors domaine possédé, effort maximal, `firstErr`).
- **Handler Go + ops injectées + fake** : `handler_fs_acl.go`/`handler_fs_acl_test.go`
  (parse strict, dédoublonnage, refus défense en profondeur en Test ET Apply — version
  POST-review, tests à travers le moteur).
- **COM natif vtable** : `agent/windows/handler_shortcuts_windows.go` (GUIDs, lazy procs
  ole32/oleaut32, `syscall.SyscallN` sur vtables, release en defer) — transposer à
  INetFwPolicy2/INetFwRules/INetFwRule/IEnumVARIANT.
- **Guard + observer** : `FsAclAuthoringGuard` + `CapabilityProjectionObserver` +
  `FsAclAuthoringException` (version post-review #2b) — même API, dispatch additif.
- **Seed** : `2026_07_04_100000_seed_capability_program_files_browse_denied.php`
  (doctrine en tête, `updateOrInsert` double niveau, idempotence + réversibilité, maps
  valeur-capacité).
- **Wiring compilateur** : `AgentServiceProvider` — « ajouter un type = ajouter UNE
  ligne », commentaire de story attenant.

### Rappels transverses (garde-fous epic)

- Contrat **additif uniquement** (D1) ; golden AVEC justification (règle 23.1) ; hashes
  figés JUMEAUX PHP⇄Go.
- **Drift policy STRICT** (27.8) — verdict PAR TYPE ; **zéro float** (strings et tableaux
  de strings) ; **zéro AD/LdapRecord/APCu** dans les providers (critère Keycloak).
- Validation d'authoring serveur **ET** refus agent (défense en profondeur, Q3) — CÂBLÉE
  au runtime des deux côtés dès cette story (leçon review 36.1 : un guard testé mais sans
  appelant runtime = décision Henri inopérante).
- Tests PHP sur l'**HÔTE** (php8.4 + sqlite), **filtres ciblés uniquement** ; tests Go via
  `~/go-toolchain/go/bin/go` (hors PATH).
- Migration **à rejouer sur /vm** = à SIGNALER (Dev Agent Record), jamais exécutée par le
  dev ; toute modif `agent/**` ⇒ bump `agent/shared/version.go` ; **update.sh ne publie
  jamais seul** — binaire antérieur = type ignoré EN SILENCE.
- e2e lab = MANUEL opérateur, hors périmètre du dev — protocole consigné au runbook QA
  (`docs/qa/domains/agent.md`).

### Project Structure Notes

- Serveur : tout vit sous `app/Services/Agent/Providers/` (provider + guard) + l'observer
  existant ; AUCUNE UI dans cette story (la capacité apparaît automatiquement dans les
  surfaces existantes — options data-driven).
- Agent : logique de convergence + traduction + refus dans `agent/shared/` (testée hôte
  via fake) ; `agent/windows/` n'apporte que l'impl `FirewallOps` COM (~300-400 lignes
  Win32) + 1 ligne de wiring.
- `machine_user` et Session ne sont pas concernés (portée Machine unique, D7/Q4).

### References

- [Source: _bmad-output/planning-artifacts/epics-mecanismes-hors-registre.md#Story-36.2 +
  #Décisions-structurantes (D1–D8) + #Garde-fous-d'epic + #Overview] — autorité de cadrage
- [Source: _bmad-output/ultradev/36-questions.md — Q3 (block lan|any refusé serveur ET
  agent, échappatoire explicit hors RFC1918), Q4 (parc/salle suffit, per-user hors
  scope v1)] — décisions Henri IMPÉRATIVES
- [Source: _bmad-output/implementation-artifacts/36-1-mecanisme-fs-acl.md +
  _bmad-output/codeReviews/36-1.md] — PATRON DIRECT + leçons intégrées (#1 store vs
  recalcul → sans objet ici (pas de store), #2a/#2b validation câblée runtime, #3
  intersection pas match textuel, #4 alignement serveur↔agent, #5 persistance → sans
  objet)
- [Source: docs/agent/contract-v1.md §4 (canonicalisation, zéro float), §7 (identifiants
  figés, §7.7 = dernier), §8 (type absent = non géré), §9 (règle d'évolution)]
- [Source: app/Services/Agent/Providers/FsAclCapabilityProvider.php +
  FsAclAuthoringGuard.php + app/Observers/CapabilityProjectionObserver.php +
  app/Exceptions/FsAclAuthoringException.php + app/Providers/AgentServiceProvider.php]
- [Source: agent/shared/handler_registry_list.go (réconciliation par conteneur) +
  handler_fs_acl.go (refus défense en profondeur Test+Apply, patron ops/fake) +
  agent/windows/handler_shortcuts_windows.go (COM natif vtable zéro dépendance) +
  agent/windows/main_windows.go (map Handlers SYSTEM) + agent/go.mod (x/sys + x/text
  seuls)]
- Mémoires projet : `project_capability_mechanisms_direction`,
  `project_capability_value_map_symmetric_rule` (fondement du piège #2),
  `project_drift_policy_strict_only`, `project_agent_handler_not_in_published_binary`,
  `project_agent_runtime_go`, `feedback_agent_edit_bump_version`,
  `feedback_no_overengineered_choices`, `project_sqlite_tests_no_varchar_enforcement`
  (piège #12), `project_state_precedence_logical_over_physical`.

## Dépendances

- **En amont (intra-epic) : 36.1 (fs_acl) et 36.3 (lot registre) — SATISFAITES** (mergées
  sur la branche d'intégration `ultradev/epic-36`). 36.2 réutilise leurs fichiers
  partagés : contrat (`StateContract.php`/`contract.go`), golden + hashes jumeaux,
  `contract-v1.md`, `version.go` (2.6.0 → 2.7.0), et ÉTEND `CapabilityProjectionObserver`
  (dispatch par mécanisme). Hors intra-epic : Epic 35 livré, contrat 23.1,
  capability-first 27.12, STRICT 27.8.
- **En parallèle éventuel : 36.4 (formulaire règles d'accès, dépend de 36.1 seulement).**
  Si elle est lancée en parallèle, UN SEUL point de contact : `AgentServiceProvider`
  (chacune ajoute sa ligne de provider — conflit de merge trivial, à résoudre en gardant
  les deux). 36.2 ne touche AUCUN autre fichier du périmètre 36.4 (UI, table
  `folder_access_rules`, `FolderAccessRulesStateProvider`).
- **En aval : AUCUNE story de l'epic ne dépend de 36.2.** Le mécanisme `firewall` servira
  de futures capacités catalogue (proxy à couper via `explicit`, etc.) en pure donnée.
- **Publication** : la release agent **2.7.0 embarque fs_acl (2.6.0, jamais publiée) ET
  firewall** — une seule publication manuelle couvre l'epic côté binaire ; migrations de
  seed 36.1 + 36.2 + 36.3 à rejouer sur /vm au moment de l'intégration.

## Recommandation Modèle Dev

**fable** — prescription explicite de l'epic (garde-fous : « fable pour 36.1 et 36.2 »,
agent Go + design sécurité) et mémoire `feedback_epic23_model_fable5`. Profil confirmé :
nouveau type de contrat cross-language (golden hashes jumeaux PHP⇄Go), impl COM natif
vtable sans dépendance (INetFwPolicy2 — le morceau le plus délicat, GUIDs/vtables à
vérifier sur SDK), sémantique de convergence par conteneur avec normalisation d'écho
(anti drift-loop), et surface sécurité réelle (Q3 en double rideau, intersection
d'intervalles IPv4/IPv6 — se tromper = couper une salle de son serveur). Aucune raison de
dévier.

## Dev Agent Record

### Agent Model Used

Opus 4.8 (1M context) — dev BMAD (ultradev vague séquentielle, branche
d'intégration `ultradev/epic-36`).

### Debug Log References

- Calcul des hashes golden via le `StateHasher` PHP RÉEL (script scratchpad
  `scratch_hashfw.php`, bootstrap Laravel du repo) : `hashItem` de l'item
  firewall puis `hashState` après réécriture de l'item hash dans le golden.
  Résultats : item `4851bc92aaf16cd71a5e0d595a0f7cad3e0fa77faba420adeed18044cf19afdc`,
  état `76f6d9acb82b4d16c13cb87d5a10b0066e4da3f9b376750b9ad19b8cd8fb7d9b`.
  Parité PHP↔Go PROUVÉE : `hasher_test.go::TestHashStateGoldenMatchesFrozenHash`
  et `TestHashItemFirewallCanonicalization` verts avec les MÊMES valeurs.

### Completion Notes List

- **Contrat additif jumeau.** `firewall` ajouté à `StateContract::RESOURCE_TYPES`
  (PHP) et `ResourceTypes` (Go). Golden `state.v1.json` +1 item firewall machine
  (6 clés). `FROZEN_STATE_HASH` (PHP) = `frozenStateHash` (Go) recalculés à
  l'identique via le `StateHasher` réel. Comptages ajustés : `contract_test.go`
  machine 6→7, types 12→13 ; `hasher_test.go` 14→15. `report.v1.json` INCHANGÉ
  (les items de rapport `{type, status, hash[, detail]}` ne portent aucun
  payload ; le type entre à l'ingestion via `ReportRequest`/`Rule::in`).
- **Provider + guard + observer.** `FirewallCapabilityProvider`
  (`exclusiveKey() = rule_id`, `hive()=''`, `expand()` surchargé, `StateCompiler`
  intouché) enregistré dans `AgentServiceProvider` (+1 ligne). `FirewallAuthoringGuard`
  (Q3, intersection MATHÉMATIQUE d'intervalles IPv4/IPv6 via `inet_pton` + masques)
  + `FirewallAuthoringException`. `CapabilityProjectionObserver` étendu en
  **dispatch par mécanisme** (`fs_acl` → guard fs_acl inchangé ; `firewall` →
  guard firewall) — les tests fs_acl 36.1 restent verts (non-régression).
- **Handler Go.** `handler_firewall.go` : réconciliation PAR CONTENEUR du groupe
  `SambaEdu-Agent` (désirées présentes+conformes ; toute règle du groupe hors
  désir SUPPRIMÉE ; groupe vide = « off » symétrique) ; règles hors groupe/
  politique par défaut/service JAMAIS touchés (`FirewallOps` 3 ops seulement) ;
  `internetRemoteAddresses()` FIGÉE (IPv4 `a-b` inverses-RFC1918 + IPv6
  `2000::/3`, chaîne testée exacte) ; normalisation canonique d'intervalles
  (anti drift-loop d'écho Windows CIDR↔masque pointé) ; refus Q3 défense en
  profondeur dans `Test` ET `Apply` (plages `firewallProtectedRanges` MIROIR du
  guard PHP, via `netip.Prefix.Overlaps`). Impl Windows `handler_firewall_windows.go`
  en **COM natif vtable INetFwPolicy2** (ZÉRO dépendance — patron
  `handler_shortcuts_windows.go` ; GUIDs/ordre vtables des en-têtes netfw.h ;
  `put_Protocol` AVANT les ports ; BSTR oleaut32). Câblé SYSTEM dans
  `main_windows.go` (jamais le compagnon).
- **Seed.** `internet_access` (enum `unmanaged`/`on`/`off`, opt-in, description
  ≤ 255, warning proxys + retrait par `on`). `off` → `block out internet any`
  (present) ; `on`/retrait → même `rule_id` en `ensure:absent` (groupe vidé).
- **Version.** `agent/shared/version.go` 2.6.0 → **2.7.0** + note de publication.

**⚠️ À FAIRE À L'INTÉGRATION (hors périmètre dev) :**
- **Publier MANUELLEMENT la release 2.7.0** (update.sh ne publie jamais seul).
  Un binaire ≤ 2.6.0 IGNORE le type `firewall` EN SILENCE (§8). La 2.6.0
  (fs_acl) n'ayant PAS ÉTÉ PUBLIÉE, la 2.7.0 livre les DEUX mécanismes d'un coup.
- **Rejouer la migration de seed sur /vm** (`php artisan migrate`,
  `migrate:status` d'abord — jamais auto-appliquée). Sans publication, la
  capacité est inerte.
- **Protocole e2e lab MANUEL** (opérateur) : poste armé `off` → `ping`/HTTP
  externes KO (IPv4, et IPv6 si le lab en a) ; check-in agent + UI SE5 + partages
  SMB + DNS local OK ; `wf.msc` montre `SambaEdu-Agent: internet-block` ; retour
  `on` → Internet restauré SANS reboot, groupe vide ; **2 cycles stables ⇒
  `compliant`, zéro op** (attrape toute normalisation d'écho imprévue). Consigné
  `docs/qa/domains/agent.md` §36.2.
- **Note IPv6 lab** : si le lab n'a pas d'IPv6, le volet IPv6 de la traduction
  reste validé par le test Go seul (chaîne exacte figée).

### Tests

- **PHP HÔTE** (php8.4 + sqlite) : `--filter='ContractV1Test|StateHasherTest|
  CapabilityFirewall|Firewall|CapabilityProjectionObserver|CapabilityFsAcl'` →
  **97 passed (407 assertions)** ; non-régression registry
  (`CapabilityRegistryProviderTest|CapabilityRegistryListProviderTest|
  CapabilityRegistryCompilationTest`) → **50 passed**.
- **Go** (`~/go-toolchain/go/bin/go`) : `cd agent && go test -count=1 ./...` →
  `ok shared`, `ok provision` ; `GOOS=windows go build ./...` OK ;
  `go vet ./...` (linux) + `GOOS=windows go vet ./...` → clean (0 warning).

### File List

**Contrat & golden :**
- `app/Services/Agent/StateContract.php` (RESOURCE_TYPES += `firewall`)
- `agent/shared/contract.go` (ResourceTypes += `firewall`)
- `docs/agent/contract-v1.md` (§7 liste + §7.8 payload firewall + §9)
- `tests/Fixtures/Agent/state.v1.json` (+1 item firewall machine)
- `tests/Unit/Services/Agent/ContractV1Test.php` (FROZEN_STATE_HASH + justif.)
- `tests/Unit/Services/Agent/StateHasherTest.php` (test hash firewall)
- `agent/shared/hasher_test.go` (frozenStateHash jumeau + 14→15 + test firewall)
- `agent/shared/contract_test.go` (machine 6→7, types 12→13)

**Provider serveur, guard, observer :**
- `app/Models/CapabilityProjection.php` (docblock `MECHANISM_FIREWALL` enrichi)
- `app/Services/Agent/Providers/FirewallCapabilityProvider.php` (NOUVEAU)
- `app/Services/Agent/Providers/FirewallAuthoringGuard.php` (NOUVEAU)
- `app/Exceptions/FirewallAuthoringException.php` (NOUVEAU)
- `app/Observers/CapabilityProjectionObserver.php` (dispatch par mécanisme)
- `app/Providers/AgentServiceProvider.php` (+1 provider — contact 36.4)
- `tests/Unit/Services/Agent/CapabilityFirewallProviderTest.php` (NOUVEAU)
- `tests/Unit/Services/Agent/CapabilityFirewallCompilationTest.php` (NOUVEAU)
- `tests/Feature/Observers/CapabilityProjectionObserverTest.php` (+ cas firewall)

**Handler agent :**
- `agent/shared/handler_firewall.go` (NOUVEAU — handler + FirewallOps + traduction)
- `agent/shared/handler_firewall_test.go` (NOUVEAU — fake + scénarios a–m)
- `agent/windows/handler_firewall_windows.go` (NOUVEAU — COM natif INetFwPolicy2)
- `agent/windows/main_windows.go` (+1 entrée handler, SYSTEM)
- `agent/shared/version.go` (bump 2.7.0 + changelog)

**Seed :**
- `database/migrations/2026_07_04_120000_seed_capability_internet_access.php` (NOUVEAU)
- `tests/Feature/Migrations/CapabilityFirewallSeedTest.php` (NOUVEAU)

**Docs :**
- `docs/agent/state-providers.md` (section `firewall`)
- `docs/qa/domains/agent.md` (section 36.2, append-only)

**Story & suivi :**
- `_bmad-output/implementation-artifacts/36-2-mecanisme-firewall.md` (cette story)
- `_bmad-output/implementation-artifacts/sprint-status.yaml` (ligne 36-2 → review)

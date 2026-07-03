# Bibliothèque de capacités — mécanismes hors-registre - Epic 36 Breakdown

Date : 2026-07-02
Source : direction produit « bibliothèque de capacités » (session 2026-07-02) — intention
Henri : une bibliothèque de capacités Windows la plus complète et flexible possible,
la moins technique possible pour l'utilisateur. Prolonge l'Epic 35 (famille `registry`).
Mémoire projet : `project_capability_mechanisms_direction`.

## Overview — la doctrine

La demande métier est variée (« bloquer l'accès à Program Files », « couper Internet »,
« masquer les pins de la barre latérale », …) mais elle se projette sur un **vocabulaire
fermé et petit de mécanismes Windows** : registre, listes registre, ACL NTFS, pare-feu,
services, Appx, groupes locaux, privilèges LSA. Huit familles couvrent ~95 % de ce qu'une
GPO d'établissement a jamais fait.

**Décision de direction (contre l'alternative scripts).** La bibliothèque ne sera PAS une
collection de scripts exécutés conditionnellement par l'agent : un script n'a pas de
`test` (drift indétectable, policy STRICT inapplicable), pas de « off » fiable, et pousser
du code arbitraire en SYSTEM sur la flotte est structurellement un C2. C'est la leçon
WPKG (impératif) → natif (déclaratif), déjà payée. À la place :

- **chaque mécanisme = un investissement unique** (un handler Go générique test/apply/
  report + un provider serveur + un ajout additif au contrat), payé une fois ;
- **chaque capacité = de la donnée** (seed : label FR, options, warning, `spec`) —
  illimitées et gratuites une fois le mécanisme livré ;
- **l'agent ne connaît jamais les capacités** (invariant 27.12 : la clé ne fuit pas au
  payload) — il reste un petit exécutant à handlers typés, testés une fois, stables ;
- le vocabulaire de mécanismes est **gouverné** : on n'ajoute un mécanisme que pour une
  vraie famille de besoins. Une éventuelle soupape `command` (couple test/apply
  contractuel façon DSC) n'est PAS ouverte : elle deviendrait le chemin de moindre effort
  et dégraderait la bibliothèque en tas de scripts. Elle ne se rediscute qu'avec trois
  vrais cas orphelins.

Cet epic livre les **deux premiers mécanismes hors-registre** — `fs_acl` (ACL NTFS du
poste) et `firewall` (pare-feu Windows) — plus un lot de capacités registre pures qui ne
demandent aucun moteur. Couverture des quatre exemples fondateurs :

| Exemple Henri | Réponse | Story |
|---|---|---|
| Désactiver le Windows Store | `windows_store_disabled` | déjà livré (27.x) |
| Masquer les pins de la barre latérale | capacités registre pures | 36.3 (lot, zéro moteur) |
| Interdire l'Explorateur sur Program Files à un type d'utilisateur | mécanisme `fs_acl` | 36.1 |
| Couper Internet | mécanisme `firewall` | 36.2 |

Mécanismes suivants identifiés, HORS epic (même patron, à cadrer quand le besoin arrive) :
`service` (désactiver télémétrie/Xbox/…), `appx` (déprovisionner les apps Store),
`localgroup` (admins locaux, Remote Desktop Users), `privilege` (cadré 35.6, gated).

## Décisions structurantes

- **D1 — Contrat additif uniquement** (iso Epic 35) : chaque type est un ajout, golden
  files bumpés avec justification, agents antérieurs non cassés (type inconnu = non géré).
- **D2 — `StateCompiler` intouché** : les sémantiques d'identité vivent dans
  `exclusiveKey()` des nouveaux providers.
- **D3 — Jamais de syntaxe technique dans la `spec`** : pas de masques d'accès bruts, pas
  de SDDL, pas de syntaxe netsh. Des **enums fermés** de mots métier (`rights:
  list_folder`, `remote_scope: internet`) que le handler traduit. C'est ce qui garde
  l'authoring accessible et rend la variante dangereuse *inexprimable*.
- **D4 — Propriété chirurgicale, jamais de possession de conteneur partagé** : l'agent ne
  possède ni une DACL (partagée avec Windows/TrustedInstaller/installeurs) ni la politique
  pare-feu globale. Il possède SES objets : une ACE explicite identifiée (via le store
  « dernier état appliqué » de l'agent, dogme 23.1), les règles de SON groupe de règles
  pare-feu (`SambaEdu-Agent`). Tout le reste est invisible et intouchable.
- **D5 — Résolution des identités côté agent** : `DOMAIN\name → SID` via LSA
  (`LsaLookupNames`) sur le poste joint au domaine. Zéro SID en SQL, serveur en lecture
  Postgres pure (NFR7). (Iso D4 de l'Epic 35.)
- **D6 — Jetons d'audience fermés, résolus à l'expansion** : la `spec` référence
  `@eleves | @profs | @personnels` ; le provider les résout en nom de groupe réel de
  l'établissement (donnée SQL du `TargetContext`) au moment de l'expansion. C'est une
  substitution CONTRÔLÉE et fermée — pas le `%VAR%` générique du legacy (piège n°7 du
  lot ISO, délibérément exclu).
- **D7 — Portée Machine pour les deux mécanismes** : une ACL NTFS et une règle pare-feu
  sont des états du poste. Le ciblage « quels postes » = assignments habituels (parc /
  broadcast) ; le ciblage « quel utilisateur est bridé » = le trustee DANS le payload
  (fs_acl). Le pare-feu par-utilisateur n'existe pas en v1 (limitation Windows assumée —
  « couper Internet » se cible par parc/salle, pas par groupe d'utilisateurs).
- **D8 — Deux surfaces d'authoring au-dessus d'UN mécanisme** (décision Henri 2026-07-02).
  Le mécanisme `fs_acl` sert deux consommateurs distincts :
  1. **capacité catalogue** — intention FIGÉE curatée en amont, états finis
     (« Program Files masqué aux élèves » : toggle/enum seedé) ;
  2. **fonctionnalité à formulaire** — le refnum CRÉE des instances illimitées
     (« interdire CE dossier à CE groupe ») : table + CRUD + assignations par maille +
     StateProvider dédié, patron exact de l'Epic 34 (lecteurs réseau : le canal `drives`
     est déjà bi-alimenté — logique figée K:/H: + table `network_shares` créée en UI).
  Règle de partage : intention figée → capacité ; objet instancié par l'admin → feature
  avec formulaire. L'agent ne voit que des items `fs_acl` — il ignore qui les a produits ;
  le mécanisme (contrat + handler + garde-fous) se paie UNE fois et sert les deux.

## Garde-fous d'epic (toutes stories)

- Stories agent Go : bump `agent/shared/version.go` + rappel publication manuelle
  (update.sh ne publie pas ; piège « handler absent du binaire publié »).
- Golden files : changements additifs justifiés (règle d'évolution 23.1).
- Zéro AD/LdapRecord/APCu dans les providers (critère Keycloak) ; drift STRICT ; zéro float.
- Validation d'authoring serveur ET refus agent (défense en profondeur) sur les deux
  mécanismes — le serveur peut avoir tort, l'agent ne doit jamais casser le poste.
- Tests HÔTE (php8.4 + sqlite) filtres ciblés ; e2e sur poste lab JOINT AU DOMAINE
  (résolution SID/groupes impossible à simuler ailleurs).
- Reco dev : **fable** pour 36.1 et 36.2 (agent Go + design sécurité), opus pour 36.3
  (seed) et 36.4 (UI/CRUD, patron 34.2).

## Questions ouvertes Henri (avant dev)

- **Q1 (36.1)** — vocabulaire des jetons d'audience côté CAPACITÉS : `@eleves/@profs/
  @personnels` suffisent (les trois jetons fixes) — le cas « groupe arbitraire » est
  servi par le formulaire 36.4 (vrai picker SQL), pas par un jeton. À confirmer.
- **Q2 (36.1)** — liste des combos interdits (racines protégées × héritage) : la
  proposition D-36.1 est-elle trop stricte ou pas assez ? Défaut proposé dans la story.
- **Q3 (36.2)** — le refus en bloc de `action:block` sur `remote_scope: lan|any` est-il
  acceptable (l'échappatoire = adresses explicites hors-LAN) ? Défaut : oui, refusé.
- **Q4 (36.2)** — « couper Internet » par parc/salle (pas par groupe d'utilisateurs) est-il
  suffisant pour les usages visés (salle d'examen) ? Défaut : oui, per-user hors scope.

## Epic List

### Epic 36 : Bibliothèque de capacités — mécanismes `fs_acl` et `firewall`

Payer les deux premiers mécanismes hors-registre et prouver la doctrine « mécanisme =
code payé une fois, capacité = donnée » sur les exemples fondateurs — puis ouvrir la
seconde surface d'authoring (D8) : les règles d'accès aux dossiers créées en formulaire.
Séquencement : 36.3 (zéro moteur) livrable immédiatement ; 36.1 et 36.2 indépendantes
entre elles ; 36.4 dépend de 36.1 (le mécanisme) ; ordre conseillé 36.1 puis 36.2
(36.1 étrenne le store « dernier appliqué » et les jetons d'audience que d'autres
mécanismes réutiliseront).

---

## Epic 36 : Bibliothèque de capacités — mécanismes `fs_acl` et `firewall`

### Story 36.1 : Mécanisme `fs_acl` — ACE NTFS gérées sur le poste

> **📌 Rappel 35.6 (décision Henri 2026-07-03, gate D6 fermé — cf.
> `_bmad-output/ultradev/35-questions.md` Q1)** : cette story livre la résolution
> de SID côté agent (LSA, jetons d'audience) — c'est exactement la plomberie dont
> le mécanisme `privilege` (Story 35.6, SeDeny*/RDP élèves) a besoin. **Au moment
> de créer/développer la 36.1, réévaluer l'ouverture de la 35.6** : son coût
> marginal s'effondre une fois `fs_acl` livré. L'ouvrir SEULEMENT si le besoin
> terrain « RDP interdit aux élèves mais autorisé aux profs sur le MÊME parc »
> s'est confirmé (le per-parc `remote_desktop_enabled=off` couvre le reste) ; si
> un mécanisme `localgroup` est cadré entre-temps, il rend la 35.6 caduque.

En tant que référent numérique,
je veux restreindre (ou accorder) l'accès à un dossier du poste pour un type d'utilisateur,
afin de masquer Program Files aux élèves — sans casser le lancement des applications.

**Acceptance Criteria:**

**Given** le contrat v1
**When** le type `fs_acl` est publié (ajout additif à `StateContract::RESOURCE_TYPES`,
`semantics: exclusive`, portée Machine)
**Then** le payload est `{path, trustee, ace_type, rights, applies_to, ensure}` avec
`ace_type ∈ allow|deny`, `rights ∈ list_folder|read|write|modify` (enum fermé, traduit en
masques par le handler — AUCUN masque brut ni SDDL au contrat, D3),
`applies_to ∈ folder_only|folder_subfolders_files|subfolders_files_only`,
`ensure ∈ present|absent`
**And** golden files state/report mis à jour, champ `ensure` dans la canonicalisation.

**Given** le provider serveur (`mechanism: fs_acl`, portée Machine)
**When** une projection est expansée
**Then** `exclusiveKey()` = `{path | trustee | ace_type}` normalisé — la maille la plus
spécifique gagne CETTE ACE ; deux capacités gérant des ACE distinctes sur le même chemin
coexistent
**And** un jeton d'audience (`@eleves`, `@profs`, `@personnels`) dans `trustee` est résolu
en nom de groupe réel de l'établissement à l'expansion (D6, donnée SQL du contexte — le
mapping jeton → groupe est validé en story, cf. Q1) ; jeton irrésoluble ⇒ clé non émise +
log warning (jamais de payload avec jeton brut).

**Given** la validation d'authoring (création/édition de la projection)
**Then** sont REFUSÉS avec message explicite :
- trustee `deny` sur un principal système (SYSTEM, Administrators, TrustedInstaller,
  comptes de service, Everyone/Authenticated Users) ;
- `deny` avec héritage descendant (`folder_subfolders_files`, `subfolders_files_only`)
  sur les racines protégées (`C:\`, `C:\Windows`, `C:\Program Files`,
  `C:\Program Files (x86)`, `C:\ProgramData`) — la variante qui casse les applis est
  INEXPRIMABLE (cf. Q2)
**And** toute capacité portant une projection `deny` exige un `warning` non vide.

**Given** le handler Go `fs_acl` (service SYSTEM)
**When** il converge un item
**Then** `test` = la DACL du chemin contient une ACE EXPLICITE exactement égale
(SID résolu, type, masque, flags) → `compliant`, sinon `drift` ;
`apply` = ajout/retrait CHIRURGICAL de l'ACE (`SetNamedSecurityInfo`) — la DACL n'est
JAMAIS réécrite, owner/SACL/ACE héritées/ACE tierces JAMAIS touchés (D4)
**And** le handler persiste l'ACE appliquée dans le store « dernier état appliqué » par
item : quand la valeur de capacité change (ex. audience élèves → tous), l'ancienne ACE
enregistrée est retirée PUIS la nouvelle posée — aucune ACE orpheline
**And** `ensure: absent` retire l'ACE gérée si présente, `compliant` si absente
**And** refus agent (défense en profondeur, indépendant du serveur) : `deny` dont le SID
résolu est un well-known système ⇒ item `error` ; chemin inexistant ⇒ `error` (jamais de
création de dossier) ; trustee irrésoluble via LSA ⇒ `error` — jamais d'application
partielle silencieuse.

**Given** la capacité de preuve `program_files_browse_denied`
**When** elle est seedée (enum : `unmanaged` / `eleves` / `tous`, opt-in, warning)
**Then** sa projection porte 2 ACE `deny list_folder folder_only` (Program Files +
Program Files (x86)) avec trustee par map de valeur (`'eleves' => '@eleves'`, …)
**And** l'e2e lab prouve les DEUX faces : un élève ne peut plus OUVRIR Program Files
dans l'Explorateur, ET une application installée (raccourci vers un exe sous Program
Files) se lance toujours — pour l'élève comme pour un prof.

**Given** l'agent modifié
**Then** bump `agent/shared/version.go` + note de publication.

### Story 36.2 : Mécanisme `firewall` — règles pare-feu possédées par groupe

En tant que référent numérique,
je veux couper l'accès Internet d'un parc de postes (salle d'examen) en gardant le réseau local,
afin de contrôler la connectivité sans toucher au câblage ni au DHCP.

**Acceptance Criteria:**

**Given** le contrat v1
**When** le type `firewall` est publié (ajout additif, `semantics: exclusive`, portée
Machine)
**Then** le payload est `{rule_id, direction, action, remote_scope, protocol, ports?}` avec
`direction ∈ in|out`, `action ∈ allow|block`,
`remote_scope ∈ internet|explicit` (+ `remote_addresses` si `explicit`),
`protocol ∈ any|tcp|udp` — enums fermés, AUCUNE syntaxe netsh/SDDL (D3)
**And** `remote_scope: internet` est traduit par le HANDLER en plages inverses-RFC1918
(la définition d'« Internet » vit dans le code testé une fois, pas dans la donnée)
**And** `exclusiveKey()` = `rule_id`.

**Given** la propriété par groupe de règles (D4)
**When** l'agent converge le type `firewall`
**Then** toutes les règles qu'il crée portent le groupe `SambaEdu-Agent`, et il possède
CE groupe en entier : règles désirées présentes et conformes, toute règle du groupe hors
état désiré SUPPRIMÉE (réconciliation par conteneur, iso registry_list)
**And** un état désiré VIDE vide le groupe — le « off » (rendre Internet) est symétrique
et gratuit, sans verbe `ensure`
**And** les règles hors groupe (Windows, applis tierces, admin local) ne sont JAMAIS
touchées ; la politique par défaut du pare-feu et l'état du service ne sont JAMAIS modifiés.

**Given** la validation d'authoring
**Then** `action: block` avec une portée couvrant le LAN (RFC1918) ou `any` est REFUSÉ
(cf. Q3) — couper le LAN couperait l'agent de son serveur, poste injoignable en permanence ;
l'échappatoire assumée est `explicit` avec des adresses hors plages privées
**And** refus agent en défense en profondeur sur le même critère.

**Given** la capacité de preuve `internet_access`
**When** elle est seedée (« Accès Internet », enum : `unmanaged` / `on` (Autorisé) /
`off` (Coupé — réseau local seulement), opt-in, warning sur les proxys d'établissement :
un proxy LAN re-donne Internet — à couper via règle `explicit` dédiée si présent)
**Then** `off` produit la règle `block out internet any` dans le groupe géré ; `on` et le
retrait d'override produisent un groupe vide (règle retirée au cycle suivant)
**And** l'e2e lab prouve : poste coupé → ping/HTTP externes KO, check-in agent + accès
serveur SE5 + partages OK ; retour `on` → Internet restauré sans reboot.

**Given** l'agent modifié
**Then** bump version + note de publication (nouveau handler = binaire antérieur inerte).

### Story 36.3 : Lot bibliothèque n°2 — capacités registre pures (zéro moteur)

En tant que référent numérique,
je veux masquer les éléments superflus de l'Explorateur (pins de la barre latérale, Accès rapide…),
afin d'épurer les postes pédagogiques — sans attendre aucun nouveau mécanisme.

**Acceptance Criteria:**

**Given** le mécanisme `registry` ACTUEL
**When** la migration de seed (pattern exact CD95/ISO, idempotente) est jouée
**Then** naissent au minimum :
- `explorer_sidebar_pins_hidden` : masque les dossiers épinglés du volet de navigation
  (Bureau, Documents, Images, … — clés CLSID `System.IsPinnedToNameSpaceTree`, patron
  `onedrive_hidden`), toggle opt-in, portée Session ;
- `quick_access_hidden` : masque Accès rapide / réduit le volet au strict Ce PC
  (`HubMode` + politiques Explorer associées), toggle opt-in ;
- candidats du même lot si le décodage des clés le confirme (galerie, historique
  fichiers récents dans Accès rapide) — chaque capacité respecte la convention
  « sujet + état » et les maps symétriques
**And** chaque clé est vérifiée sur poste lab AVANT seed (pas de clé recopiée de mémoire) ;
toute clé sous `HKCU\Software\Policies` est portée en HKLM ou reportée à la ruche HKU
(35.3) avec note — jamais émise vers le companion.

**Given** la preuve de doctrine
**Then** la story ne touche NI l'agent NI le contrat NI les providers — diff = une
migration + tests de seed. C'est le témoin du coût marginal d'une capacité une fois le
mécanisme payé.

### Story 36.4 : Règles d'accès aux dossiers — la fonctionnalité à formulaire (D8)

En tant que référent numérique,
je veux créer moi-même une règle « interdire/autoriser CE dossier à CE groupe » via un formulaire,
afin de couvrir les besoins locaux imprévus sans attendre une capacité catalogue.

**Acceptance Criteria:**

**Given** le modèle (patron Epic 34 : table + pivot + service + provider)
**When** la migration est jouée
**Then** la table `folder_access_rules` porte `{path, user_group_id (FK — VRAI picker de
groupe SQL, pas un jeton), ace_type, rights, applies_to, label, is_active}` + un pivot
d'assignation par parc (`WorkstationGroup`, extensible sans migration)
**And** aucune nouvelle notion côté agent/contrat : la règle se projette en items
`fs_acl` IDENTIQUES à ceux d'une capacité (D8).

**Given** l'UI (page dédiée, convention filesystem-router + SFC Volt + modale réutilisable
+ WithToasts)
**When** le refnum crée une règle
**Then** le formulaire expose UNIQUEMENT des champs métier : chemin (texte validé —
format chemin Windows absolu, lecteur C:/D:), groupe (picker SQL scopé établissement),
sens (Interdire / Autoriser), niveau (Parcourir / Lire / Écrire / Modifier — mots métier
mappés sur l'enum `rights`), portée (Ce dossier seul / Dossier et contenu), parcs cibles
**And** la même validation d'authoring que 36.1 s'applique au formulaire (racines
protégées × héritage, principals système interdits en deny — messages FR explicites) ;
une règle `deny` affiche une confirmation d'implications (patron warning des capacités)
**And** UX conforme aux conventions formulaires (labels au-dessus, hints en tooltip,
obligatoires étoilés).

**Given** le provider dédié `FolderAccessRulesStateProvider` (portée Machine, type
`fs_acl`, lecture Postgres pure)
**When** l'état d'un poste est compilé
**Then** chaque règle active assignée à un parc du poste émet ses items `fs_acl` à la
maille du parc, `exclusiveKey` PARTAGÉE avec le provider capacités (même identité
`{path|trustee|ace_type}`) — une collision règle↔capacité sur la même identité est
arbitrée par le compilateur existant (maille/récence), et la validation du formulaire
AVERTIT à la création quand la règle recouvre une capacité catalogue active
**And** désactiver ou supprimer une règle retire ses items → l'ACE gérée est déposée au
cycle suivant (retrait propre via le store « dernier appliqué » de 36.1).

**Given** la délégation
**Then** le geste est gaté par une permission dédiée scopée à l'établissement (anti-piège
Gate global non scopé) et chaque création/modification/suppression est auditée.

**Given** l'e2e lab
**Then** une règle créée en UI sur un dossier arbitraire (ex. `D:\Ressources`) pour un
groupe réel converge sur le poste (Explorer refuse l'ouverture au membre du groupe,
l'accès reste intact pour les autres), puis sa suppression restaure l'accès.

---

## Couverture finale (exemples fondateurs → capacités)

| Intention | Réponse | Mécanisme | Story |
|---|---|---|---|
| Désactiver le Windows Store | capacité `windows_store_disabled` | registry | livré |
| Masquer les pins de la barre latérale | capacités `explorer_sidebar_pins_hidden`, `quick_access_hidden` | registry | 36.3 |
| Bloquer l'Explorateur sur Program Files (élèves) | capacité `program_files_browse_denied` (cas curaté) | fs_acl | 36.1 |
| Interdire/autoriser un dossier ARBITRAIRE à un groupe | **formulaire** Règles d'accès aux dossiers (D8) | fs_acl | 36.4 |
| Couper Internet (salle/parc) | capacité `internet_access` | firewall | 36.2 |

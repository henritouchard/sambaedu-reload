# Story 27.3bis : Handler associations de fichiers — le vice UserChoice confiné

Status: ready-for-dev

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

> **ℹ️ NUMÉROTATION — lire en premier.** Story **sœur de 27.3** : le slot canonique 27.3
> (`epics-agent-desired-state.md` L719-733) couvrait *« registre & associations »* ; il a été **scindé**
> (registre = 27.3, associations = **27.3bis**). Convention `bis` iso **27.1bis**. Henri la fait **dans la
> foulée de 27.3**. Cette story porte UNIQUEMENT le type `associations` (le hash **UserChoice**, « le vice »).

## ✅ DÉCISIONS HENRI — TRANCHÉES (2026-06-16)

> 1. **Story séparée du registre** (27.3 = `registry`). Profil de risque distinct : ici le morceau dur est le
>    **hash anti-tamper UserChoice**, pas le schéma/UI.
> 2. **Drift STRICT inconditionnel** (27.8) : `compliant | drift | error`.
> 3. **Le hash UserChoice est calculé 100 % côté AGENT** (comme le legacy). Le **payload contrat ne porte que
>    `{identifier, progid, type}`** concrets — **jamais** le hash (impossible à pré-calculer serveur : il
>    dépend du SID de l'utilisateur, d'un timestamp et d'un GUID « user experience » lus sur le poste).

## Story

En tant qu'**admin d'établissement**,
je veux **définir par parc les associations d'extensions/protocoles par défaut (ex. `.pdf` → Acrobat,
`http` → Firefox), appliquées et maintenues par l'agent**,
afin que **le programme par défaut soit un item d'état comme les autres** — la complexité Windows (le hash
anti-falsification **UserChoice**) étant **confinée dans un seul handler Go testable**, écrit une fois, au lieu
des ~200 lignes de PowerShell dispersées du legacy (`SFTA.ps1`).

## Contexte & intention

**Le « vice » UserChoice (lucidité #13 du brainstorming).** Windows protège l'association par défaut d'une
extension/d'un protocole par un **hash anti-tamper** : on ne peut pas seulement écrire
`HKCU\…\FileExts\.pdf\UserChoice\ProgId`, il faut aussi écrire un `Hash` **dérivé** (MD5 en UTF-16LE de
`extension + SID + ProgId + timestamp + experienceGUID`, puis deux passes de dérivation cryptographique avec des
constantes). Sans le bon hash, Windows **invalide** l'association et la réinitialise. Le legacy fait ce calcul
côté poste dans `SFTA.ps1` (`Get-Hash`, ~lignes 565-711). **Cette story réimplémente ce calcul en Go (~50 lignes
testables), confiné dans le handler `associations`** — c'est le risque technique principal (portage fidèle,
sinon l'association ne « prend » jamais).

**Place dans l'Epic 27.** Sœur de 27.3, même pattern figé « 1 StateProvider + 1 handler Go + id figé + golden ».
Diffère de 27.3 par : (a) le handler n'est **pas générique** mais porte une **logique propre** (le hash) ; (b)
la portée est intrinsèquement **per-user** (HKCU) ; (c) une **résolution serveur existe déjà** (port 16.3c).

**Successeur du canal legacy.** Le legacy sert le delta d'associations via `/gpo/associations_out.php` (porté en
16.3c : `AssociationsResolver` + `AssociationsOutController`, source = fichiers `packages.xml` + `associations.json`
+ `default.xml`, intersectés avec les apps WPKG installées) ; le poste applique via `associations.ps1` →
`SFTA.ps1`. Ce handler **remplace nativement le volet poste** (calcul UserChoice + écriture registre) par
l'agent. **Zéro retrofit legacy** : `associations_out.php`/16.3c restent intouchés (le canal meurt en 27.6).

**Ce que cette story livre :**
- **Provider serveur** `AssociationsStateProvider` (`type='associations'`, `semantics=Exclusive` par
  identifiant, `scope` per-user) — émet des items concrets `{identifier, progid, type}` par maille.
- **Agent Go** : handler `associations` **avec logique propre** = calcul UserChoice + écriture HKCU (compagnon),
  idempotent, `error` si échec.
- **Contrat/golden** : payload `associations` spécifié ; item ajouté au golden → `FROZEN_STATE_HASH` bumpé
  **croisé PHP↔Go** (NFR13).
- **UI** : section « Associations par défaut » par parc (sous `parc-settings/`).

**Ce que cette story N'EST PAS :** le registre (→ 27.3) ; l'installation des apps (le « default » suppose l'app
installée — couplage WPKG géré en 27.5, voir piège n°5) ; le décommissionnement legacy (→ 27.6) ; un changement
de la machine d'états §5 (STRICT réutilisé).

## ⚠️ Pièges & tensions (lire AVANT de coder)

1. **🔴 LE HASH USERCHOICE — fidélité absolue.** Le handler doit reproduire EXACTEMENT l'algorithme de
   `SFTA.ps1::Get-Hash` : `baseInfo = ("{ext}{sid}{progid}{userDateTime}{userExperience}").ToLower()` →
   **MD5 sur l'encodage UTF-16LE** de `baseInfo + "\x00\x00"` → deux passes de dérivation avec les constantes
   (`0x69FB0000`, `0x13DB0000`, …) → 16 octets → **Base64**. Écrire `Hash` + `ProgId` sous
   `HKCU\Software\Microsoft\Windows\CurrentVersion\Explorer\FileExts\<ext>\UserChoice` (et
   `…\UrlAssociations\<proto>\UserChoice` pour les protocoles). **Une seule constante fausse = hash rejeté par
   Windows.** Reproduire à l'octet près, **tests vectoriels** contre des valeurs connues. [Source legacy:
   `/home/htouchard/code/irundo/se4/sources/var/sambaedu/unattended/install/os/SambaEdu/SFTA.ps1` — `Get-Hash`
   ~565-711, `Set-FTA` 267-750, `Get-UserExperience` 511-531, `Get-UserSid` 534-549, `Get-HexDateTime` 553-563.]

2. **🔴 Le hash est calculé AGENT-side, JAMAIS serveur.** Ses entrées (SID de session, `userDateTime`,
   `userExperience` GUID lu de `shell32.dll`) ne sont connues que sur le poste. Le **payload ne porte que
   `{identifier, progid, type}`** — comme le legacy sert `{ext: {ProgId, type}}`. **Aucun hash/SID dans le
   contrat.** (Vérifié AC1/AC6.)

3. **Portée per-user (HKCU) → compagnon, scope session/machine_user.** UserChoice vit sous `HKCU` ; l'écriture
   se fait par le **compagnon** (droits de l'utilisateur de session), pas le service SYSTEM. `scope()` =
   `session` (ou `machine_user` — voir Question n°3). **Supprimer l'ancienne clé UserChoice avant réécriture**
   (le legacy fait `Remove-UserChoiceKey`/`RegDeleteKey` avant `Set`, sinon ACL hérité bloque).

4. **Sémantique `exclusive` par identifiant.** Une extension/un protocole = **un** programme par défaut. Le
   compilateur départage **par identité d'item = l'`identifier`** (l'extension `.pdf` ou le protocole `http`) :
   maille la plus spécifique gagne par identifiant, identifiants distincts s'accumulent. **Même subtilité
   `selectExclusive()` qu'en 27.3** (exclusivité **par identité d'item**, pas « 1 item / type »). Si 27.3 a
   déjà étendu `selectExclusive()` → réutiliser ; sinon le faire ici sans régresser wallpaper/printers. **Forte
   synergie avec 27.3 — voir Dépendances.**

5. **Couplage « app installée ».** Un défaut `.pdf → Acrobat` n'a de sens que si Acrobat est installé (le legacy
   **intersecte** avec les apps WPKG installées, `associations_out.php`). En desired-state, l'installation est
   gérée en **27.5**. Pour cette story : décider si le provider **filtre** par apps installées (réutiliser
   `WorkstationPackagesResolver`) ou émet l'item tel quel et **documente** que l'app doit être installée
   (l'agent rapporte `error` si le ProgId n'existe pas). **Défaut proposé : émettre + `error` gracieux si ProgId
   absent**, filtrage WPKG en évolution (évite de coupler 27.3bis à 27.5). Voir Question n°2.

6. **Idempotence & STRICT.** `Test` : lire `UserChoice\ProgId` réel (et valider le hash si possible) vs cible ;
   `Apply` : (re)poser ProgId+Hash. Valeur réelle ≠ cible → `drift` + réapplication. Échec (ProgId inconnu,
   clé verrouillée) → `error` + `detail`, **isolation par item**. [Source: `agent/shared/engine.go`.]

7. **Golden bouge légitimement (nouveau type).** Item `associations` ajouté au `state.v1.json` ⇒
   `FROZEN_STATE_HASH` (PHP `ContractV1Test`) **et** `frozenStateHash` (Go `hasher_test.go`) bumpés **à la même
   valeur** (relever la valeur courante d'abord ; 27.3 l'aura probablement déjà bougée — **rebaser/relever**).
   Attendu, pas une régression.

8. **NFR7 / VM / Go hôte** (iso 27.3) : provider zéro AD/APCu/LdapRecord (grep vide ; ⚠️ le port 16.3c
   `AssociationsResolver` LIT le contexte APCu — **ne pas réutiliser sa dépendance APCu** dans le provider
   desired-state ; le provider lit Postgres/`TargetContext`). Migrations PAS auto-jouées (`migrate:status` →
   `migrate --force` /vm). Go = hôte (`~/go-toolchain`), PHPUnit /vm, jamais de VM depuis un worktree.

## Acceptance Criteria

### AC1 — Source des associations par maille ; payload concret sans hash (FR21)

**Given** le type `associations` (per-user)
**When** la résolution serveur produit ses candidats
**Then** chaque association cible est un item concret **`{identifier, progid, type}`** (`type` ∈ `file`|`protocol`,
`identifier` = extension `.pdf` ou protocole `http`), **sans hash ni SID** (calculés agent-side, piège n°2)
**And** la source de vérité est tranchée (Question n°1) : **table dédiée catalogue** `file_associations` + pivot
(recommandé, iso 27.3) **OU** réutilisation de `AssociationsResolver` (16.3c) enveloppée dans le provider
(sans sa dépendance APCu/web). Le périmètre des AC ci-dessous suppose l'option **table dédiée**.

### AC2 — Schéma (si table dédiée) : catalogue + pivot, idempotent réversible (FR26)

**Given** l'option table dédiée
**When** les migrations sont jouées
**Then** `file_associations` (`identifier`, `type` file|protocol, `progid`, libellé, actif) + pivot
`file_association_assignables` (`morphs('assignable')`, UNIQUE) sont créés, **calqués `shortcut_assignables`**,
idempotents (`Schema::hasTable`), `down()` symétrique, `->comment()` daté 27.3bis (D1 : table dédiée, jamais
polymorphe générique).

### AC3 — Provider : exclusive par identifiant, per-user, zéro AD (FR21, NFR7)

**Given** des associations assignées à plusieurs mailles
**When** `AssociationsStateProvider::itemsFor(TargetContext)` produit ses candidats
**Then** `type()='associations'`, `semantics()=Exclusive`, `scope()` per-user ; candidats **bruts par maille**
(D2) ; payload `{identifier, progid, type}` ; lecture seule Postgres ; **grep `ldap|apcu|samba-tool` vide**
(NFR7 — ne pas traîner la dépendance APCu de 16.3c)
**And** un identifiant non assigné → **aucun item** (type/clé absent = non géré).

### AC4 — Compilateur : exclusive par identité d'identifiant (FR5)

**Given** la même extension assignée sur 2 mailles avec des ProgId différents
**When** le `StateCompiler` compile
**Then** la maille la plus spécifique gagne **pour cet identifiant** ; les identifiants distincts s'accumulent ;
réutilise/étend `selectExclusive()` **par identité d'item** (synergie 27.3) **sans régresser** wallpaper/printers ;
machine d'états §5 + STRICT inchangés.

### AC5 — 🔴 Agent Go : handler `associations` + hash UserChoice fidèle (FR21)

**Given** une liste d'items `associations`
**When** l'agent converge (compagnon, HKCU)
**Then** `agent/shared/handler_associations.go` (pur testable hôte) calcule le **hash UserChoice à l'octet près**
(MD5 UTF-16LE + dérivation à constantes, Base64), supprime l'ancienne clé, écrit `ProgId`+`Hash` sous
`FileExts\<ext>\UserChoice` (fichiers) / `UrlAssociations\<proto>\UserChoice` (protocoles) via
`agent/windows/handler_associations_windows.go` (ops `golang.org/x/sys/windows/registry` + SID/experience/temps),
câblé **compagnon** dans `companion_windows.go`
**And** `Apply` **idempotent** (état stable = zéro réécriture), `Test` compare le ProgId réel ; échec (ProgId
inconnu, clé verrouillée) → `error`+`detail`, **isolation par item**
**And** **tests vectoriels** du hash (entrées connues → hash attendu) prouvent la fidélité.

### AC6 — Contrat & golden : item `associations`, hash bumpé croisé (NFR13)

**Then** `docs/agent/contract-v1.md` §7 documente le payload `associations` `{identifier, progid, type}` (jamais
de hash/SID) ; item ajouté à `state.v1.json` ; `FROZEN_STATE_HASH` (PHP) **et** `frozenStateHash` (Go) bumpés
**croisé** (relever la valeur courante du tree d'abord), tests croisés verts.

### AC7 — UI : « Associations par défaut » par parc (FR26, FR19)

**Given** l'admin ouvre les réglages d'un parc
**When** il accède à la section « Associations par défaut » (`parc-settings/`, calque `overlay-messages`)
**Then** il choisit le programme par défaut par extension/protocole **pour ce parc**, persisté sur le **pivot** ;
Livewire SFC, `WithToasts`, modale réutilisable, Gate iso-parc-settings.

### AC8 — Tests (NFR13)

**Then** Laravel : `AssociationsStateProviderTest` (item concret par maille, sans hash/SID, exclusive, zéro AD) ;
`StateCompilerTest` (exclusive par identifiant + non-régression) ; feature UI ; `ContractV1Test` (hash bumpé).
**And** Go : `handler_associations_test.go` (**vecteurs de hash UserChoice**, set cible, idempotence, drift,
error isolé, fichier vs protocole) ; `go test ./...` + `vet` (linux+windows) + cross-compile + `hasher_test.go`
croisé — verts.

### AC9 — Documentation + QA (append-only)

**Then** `state-providers.md` (section associations) ; `contract-v1.md` §7 ; `docs/qa/domains/agent.md`
`## Story 27.3bis` append-only (association appliquée par parc, hash UserChoice réimposé au drift, per-user au
logon, ProgId absent → error) ; ligne 27.3bis `docs/qa/README.md` ; note successeur du volet poste
`associations.ps1`/`SFTA.ps1` (legacy intouché, meurt en 27.6).

## Tasks / Subtasks

- [ ] **T0 — Trancher la source de vérité** (Question n°1, AC1) : table dédiée `file_associations` (recommandé)
      vs envelopper `AssociationsResolver` 16.3c. + couplage app installée (Question n°2) + scope (Question n°3).
- [ ] **T1 — Migrations** (AC2, si table) : `file_associations` + `file_association_assignables` calqués
      `shortcut_assignables`, idempotents, `down()` symétrique, comment daté 27.3bis. (+ seeder set initial ?)
- [ ] **T2 — Modèle** : `App\Models\FileAssociation` + const `TYPE_ASSOCIATIONS='associations'` + relation pivot.
- [ ] **T3 — Provider** (AC3, AC4) : `AssociationsStateProvider` (Exclusive, per-user, candidats bruts D2,
      payload `{identifier, progid, type}`, zéro APCu/AD), enregistré dans `AgentServiceProvider` ; exclusive par
      identifiant au compilateur (réutiliser l'extension faite en 27.3 si dispo).
- [ ] **T4 — 🔴 Agent Go : handler + hash UserChoice** (AC5) : `handler_associations.go` (logique pure + calcul
      hash testable hôte) + `handler_associations_windows.go` (registre HKCU, SID/experience/temps) + câblage
      **compagnon** ; `handler_associations_test.go` avec **vecteurs de hash**.
- [ ] **T5 — Contrat + golden** (AC6) : payload §7 ; item `state.v1.json` ; bump `FROZEN_STATE_HASH`/`frozenStateHash`
      croisé (relever la valeur courante).
- [ ] **T6 — UI** (AC7) : `parc-settings/file-associations/` Livewire SFC + persistance pivot + nav.
- [ ] **T7 — Tests** (AC8) : PHPUnit (provider/compilateur/UI/contrat) + Go (vecteurs hash + handler) verts.
- [ ] **T8 — Doc + QA** (AC9) append-only.
- [ ] **T9 — Validation finale** : `php -l` ; grep NFR7 vide ; grep zéro retrofit legacy ; `go test`/vet/cross
      verts ; **/vm** `migrate:status` → `migrate --force` ; **validation lab Windows (ACTION HUMAINE Henri)** :
      `.pdf → Acrobat` appliqué au logon, association changée à la main **réimposée** (drift STRICT), ProgId
      inexistant → `error`, parcs différents → défauts différents par poste.

## Dev Notes

### Le hash UserChoice — référence d'implémentation (cœur de la story)

[Source legacy: `se4/sources/var/sambaedu/unattended/install/os/SambaEdu/SFTA.ps1`]
- `Get-Hash` (~565-711) : `baseInfo` lowercased → `MD5(UTF16LE(baseInfo + "\x00\x00"))` → 2 passes à constantes
  (`0x69FB0000`, `0x13DB0000`, …) → 16 octets → Base64.
- `baseInfo = "{Extension}{userSid}{ProgId}{userDateTime}{userExperience}"`.
- `Get-UserExperience` (511-531) : GUID `{D18B6DD5-6124-4341-9318-804003BAFA0B}` extrait de `shell32.dll`.
- `Get-UserSid` (534-549) ; `Get-HexDateTime` (553-563) : FileTime little-endian courant.
- `Set-FTA` (267-750) : `Remove-UserChoiceKey` (186-195, `RegDeleteKey` natif) **avant** écriture
  `FileExts\<ext>\UserChoice` (`Hash`,`ProgId`) ; protocoles via `UrlAssociations\<proto>\UserChoice` (471-509) ;
  `ApplicationAssociationToasts` (296-346) optionnel.
- **Cibler des tests vectoriels** : un triplet (ext, sid, progid, dateTime figé, experience figé) → hash Base64
  attendu (calculé une fois avec SFTA.ps1/un vecteur connu) pour verrouiller la fidélité du portage Go.

### Source de vérité — décision (Question n°1)

[Source: `app/Gpo/Services/AssociationsResolver.php` (port 16.3c, source fichiers `packages.xml`+`associations.json`
+`default.xml`, intersection WPKG, **dépend d'APCu/contexte web**) ; `app/Http/Controllers/Gpo/AssociationsOutController.php`.]
- **Option A (recommandée, iso 27.3)** : **table catalogue dédiée** `file_associations` + pivot par parc. L'admin
  choisit le défaut par extension dans l'UI. Desired-state natif, **zéro dépendance APCu/web/legacy** dans le
  provider. D1 (table dédiée admise pour un type sans table métier).
- **Option B** : envelopper `AssociationsResolver` (réutilise la résolution fichiers existante). **Tire la
  dépendance APCu/WPKG/legacy** dans un canal desired-state qu'on veut propre (NFR7) → déconseillé.
- **Le périmètre des AC suppose l'Option A.**

### Pattern Epic 27 réutilisé (cf. 27.3 Dev Notes — ne pas réinventer)

Interface `StateProvider`, `StateCandidate`, `TargetContext`, `StateCompiler` (D2 précédence au compilateur),
`AgentServiceProvider` (tableau providers), enums `StateScope`/`ResourceSemantics`. Agent : interface
`Handler{Test,Apply}` (`engine.go`), struct handler pur + ops injectées, câblage `companion_windows.go` (ici
**compagnon**, HKCU). Pivot calqué `database/migrations/2026_02_09_173400_create_shortcut_assignables_table.php`.
UI calquée `resources/views/pages/parc-settings/overlay-messages/`. Contrat : `associations` **déjà réservé**
(`docs/agent/contract-v1.md` §7).

### Dépendances

| Story | Rôle | Statut | Bloquant ? |
|-------|------|--------|------------|
| **27.3 — handler registre** | **Forte synergie** : même extension `selectExclusive()` par identité d'item ; même pattern catalogue+pivot+UI parc. Faire 27.3bis **après** 27.3 pour réutiliser. | ready-for-dev | Non, mais **enchaîner après** (réutilisation maximale) |
| 27.8 — drift STRICT | Contrat 4 clés / 3 statuts ; STRICT | review | Prérequis fort (rebase si correctifs) |
| 27.5 — applications/WPKG | Couplage « app installée » (défaut suppose app présente) | backlog | Non (découplé ; error gracieux) |
| 16.3c — port `associations_out` | Source fichiers existante (Option B) ; legacy intouché (Option A) | done | Non |
| 23.4/24.6/23.1 — compilateur/engine/contrat/golden | Infra réutilisée | done | Non |

> **Relever le golden/hash courant** avant bump (27.3 + 27.x l'auront bougé) ; rebaser si `selectExclusive`
> change en 27.3.

### References

- [Source: `_bmad-output/planning-artifacts/epics-agent-desired-state.md` L719-733] — `associations` (UserChoice
  confiné, ~50 lignes testables, lucidité #13).
- [Source: legacy `se4/.../SambaEdu/SFTA.ps1` (`Get-Hash`/`Set-FTA`/`Remove-UserChoiceKey`), `associations.ps1`].
- [Source: `app/Gpo/Services/AssociationsResolver.php`, `app/Http/Controllers/Gpo/AssociationsOutController.php`].
- [Source: `docs/agent/contract-v1.md` §6/§7/§8 ; `app/Enums/StateScope.php`, `ResourceSemantics.php`].
- [Source: `agent/shared/engine.go`, `agent/windows/companion_windows.go` (map compagnon)].
- [Source: `database/migrations/2026_02_09_173400_create_shortcut_assignables_table.php` ; `parc-settings/overlay-messages/`].
- [Source: mémoires `project_registry_catalog_first_generic_underneath`, `project_drift_policy_strict_only`,
  `project_migrated_poste_missing_client_helpers` (associations côté poste), `project_agent_desired_state_direction`].

## Questions pour Henri

1. **Source de vérité** : table catalogue dédiée `file_associations` (recommandé, propre, iso 27.3) **vs**
   envelopper `AssociationsResolver` 16.3c (réutilise la résolution fichiers mais tire APCu/WPKG/legacy). Défaut :
   **table dédiée**.
2. **Couplage app installée** : le provider **filtre-t-il** par apps WPKG installées (réutiliser
   `WorkstationPackagesResolver`) ou émet-il l'item et laisse l'agent rapporter `error` si le ProgId est absent ?
   Défaut : **émettre + error gracieux** (découple de 27.5).
3. **Scope** : `session` (réécrit à chaque logon) ou `machine_user` (persistant par poste×user) ? UserChoice est
   per-user HKCU. Défaut proposé : **session** (appliqué au logon par le compagnon, iso volet legacy
   `associations.ps1`).

## Recommandation Modèle Dev

**Recommandation : `opus`.**

Justification : le **portage du hash UserChoice** est un morceau **cryptographique à fidélité absolue** (MD5
UTF-16LE + dérivation à constantes ; une erreur = hash silencieusement rejeté par Windows, association non
appliquée — bug difficile à diagnostiquer). S'y ajoutent l'**exclusivité par identifiant** au compilateur, un
**type au contrat** avec **bump de hash croisé PHP↔Go**, une **décision de source de vérité** structurante, et la
**suppression-avant-réécriture** de la clé UserChoice (ACL). Risque élevé concentré sur l'agent Go. `opus`.

## Dev Agent Record

### Agent Model Used

### Debug Log References

### Completion Notes List

### File List

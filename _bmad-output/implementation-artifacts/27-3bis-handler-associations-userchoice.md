# Story 27.3bis : Handler associations de fichiers — le vice UserChoice confiné

Status: review

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
>
> **— Questions T0 tranchées par Henri (2026-06-16, étape validation dev-cycle) :**
> 4. **Source = table dédiée `file_associations`** (Option A). **MAIS exigence ajoutée** : prévoir un **seeder de
>    population** (`FileAssociationSeeder`, branché dans le flux de seed prod iso `ShortcutSeeder`, et/ou exposé
>    via le mécanisme `sync-from-ad`/refresh) qui **reproduit l'existant legacy** — source = `default.xml` +
>    `associations.json` lus par `AssociationsResolver` (constantes `DEFAULT_XML_PATH`,
>    `ASSOCIATIONS_SYSTEM_JSON_PATH` sous `/usr/share/sambaedu/applications/associations/`). But : à la bascule,
>    les défauts d'associations actuels sont déjà présents en base, pas de régression fonctionnelle.
> 5. **Couplage app installée = ÉMETTRE, PAS de filtre WPKG** (donc PAS l'Option B). **Mode d'échec affiné** :
>    quand le **ProgId cible n'est PAS installé/enregistré** sur le poste, l'agent **ne force RIEN et ne touche
>    PAS** la clé UserChoice existante → **le choix de l'utilisateur est préservé** (pas d'écrasement, pas de
>    suppression-avant-réécriture sur un ProgId absent). Surfacé comme `error` **non fatal** (isolation par item,
>    `detail` = « ProgId X non enregistré, choix utilisateur conservé »). On NE rejoue PAS en boucle agressive un
>    défaut inapplicable. Le contrat reste à 3 statuts (`compliant|drift|error`).
> 6. **Scope = `session`** : appliqué/réimposé au logon par le **compagnon** (HKCU), iso volet legacy
>    `associations.ps1`. (PAS `machine_user`.)
>
> **— Extension tranchée par Henri (2026-06-17, après review) :**
> 7. **Catalogue tagué `native` vs `wpkg:<package>` + validation prédictive serveur par parc.** Chaque entrée
>    de catalogue porte une **source** : `native` (built-in Windows, toujours présent → toujours applicable) ou
>    `wpkg` avec le **`wpkg_package`** d'origine (ProgId fourni par un paquet déployé). Le SEEDER peuple : les
>    built-ins depuis `default.xml` (tag `native`) ET les associations fournies par les paquets WPKG via
>    `PackagesXmlAssociationsReader::read()` (`packageId → identifier → {ProgId, type}`, tag `wpkg:<packageId>`).
>    L'**UI** d'assignation calcule, par parc, un statut PRÉDICTIF : `native` → OK ; `wpkg` & paquet déployé sur
>    le parc → OK ; `wpkg` & paquet NON déployé → **error affiché AVANT déploiement** (« Firefox n'est pas
>    déployé sur ce parc → cette association échouera ici »). Remplace le warning générique N1 par un message
>    EXACT. **L'agent (`ProgIDRegistered`) reste le dernier rempart** (poste divergent).
>    - **Garde-fou NFR7** : le croisement WPKG vit dans l'**UI/assignation** (Livewire), **JAMAIS** dans
>      `AssociationsStateProvider` (qui reste PG-pur, sans APCu/legacy). Le provider **continue d'émettre**
>      (D-Henri n°3 « émettre sans filtrer WPKG » PRÉSERVÉ). Source du déploiement par parc = requête group-level
>      Eloquent (appProfiles.applications + applications directes du `WorkstationGroup`), PAS le cache APCu de
>      `WorkstationPackagesResolver`.
>    - **Invariant contrat** : `source`/`wpkg_package` sont SERVEUR-only → le payload reste `{identifier, progid,
>      type}` **INCHANGÉ**. ⚠️ **NE PAS toucher** au golden `state.v1.json`, au `FROZEN_STATE_HASH`, ni au handler
>      Go : l'agent ne connaît jamais la notion native/wpkg.
>    - **Clé de jointure CRITIQUE à vérifier** : `<package id>` de `packages.xml` (clé du reader) DOIT matcher
>      `Application::$app_id` (sortie du resolver / pivot parc) — sinon la validation est silencieusement fausse.

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
**And** `Apply` **idempotent** (état stable = zéro réécriture), `Test` compare le ProgId réel ; clé verrouillée →
`error`+`detail`, **isolation par item**
**And** (D-Henri n°5) **ProgId cible NON installé/enregistré** sur le poste → l'agent **ne supprime pas et ne
réécrit pas** la clé UserChoice existante (**choix utilisateur préservé**, pas de clobber) ; statut `error` **non
fatal** avec `detail` explicite (« ProgId X non enregistré, choix utilisateur conservé »), **pas de réécriture en
boucle** d'un défaut inapplicable
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

### AC10 — Catalogue tagué `native` vs `wpkg` + seed natives + WPKG (D-Henri n°7)

**Given** l'extension WPKG-aware
**When** les migrations + le seeder s'exécutent
**Then** `file_associations` gagne `source` (`native`|`wpkg`) + `wpkg_package` (nullable, le `<package id>` d'origine
pour `wpkg`) — colonnes AJOUTÉES à la migration `create` existante (Pending sur VM, pas d'alter séparé) ;
`FileAssociation` gagne `SOURCE_NATIVE`/`SOURCE_WPKG` + `isNative()`
**And** le seeder peuple les built-ins depuis `default.xml` (tag `native`, `wpkg_package=null`) ET les associations
fournies par les paquets WPKG via `PackagesXmlAssociationsReader::read()` (tag `wpkg`, `wpkg_package=<packageId>`) ;
hôte/CI sans `default.xml`/`packages.xml` → baseline figée taggée (Firefox=`wpkg`, txt/jpg=`native`)
**And** le payload contrat reste `{identifier, progid, type}` **INCHANGÉ** (golden/hash/agent NON touchés).

### AC11 — Validation prédictive par parc dans l'UI (D-Henri n°7, NFR7)

**Given** l'admin assigne/consulte les associations d'un parc
**When** l'UI calcule le statut par association
**Then** `native` → applicable ; `wpkg` & `wpkg_package` déployé sur le parc → applicable ; `wpkg` & paquet NON
déployé → **`indisponible`** affiché (warning + tooltip nommant le paquet : « <pkg> non déployé sur ce parc →
échouera ici ») ; le **toast** à l'activation est EXACT (avertit si indisponible, succès sinon)
**And** la source du déploiement par parc = requête **group-level Eloquent** (appProfiles.applications +
applications directes du `WorkstationGroup` + déps transitives), **PG-pur, sans le cache APCu** de
`WorkstationPackagesResolver`
**And** `AssociationsStateProvider` reste **INCHANGÉ** (PG-pur, émet toujours, D-Henri n°3) — `grep ldap|apcu|wpkg`
sur le provider reste VIDE ; la clé de jointure `packages.xml <package id>` ⇄ `Application::$app_id` est VÉRIFIÉE.

## Tasks / Subtasks

- [x] **T0 — Trancher la source de vérité** (Question n°1, AC1) : table dédiée `file_associations` (recommandé)
      vs envelopper `AssociationsResolver` 16.3c. + couplage app installée (Question n°2) + scope (Question n°3).
- [x] **T1 — Migrations + seeder reproduction legacy** (AC2, D-Henri n°4) : `file_associations` +
      `file_association_assignables` calqués `shortcut_assignables`, idempotents, `down()` symétrique, comment
      daté 27.3bis. **+ `FileAssociationSeeder`** (catalogue + assignations défaut) **reproduisant l'existant
      legacy** — source = `default.xml`/`associations.json` (constantes `AssociationsResolver`), branché dans le
      flux de seed prod (iso `ShortcutSeeder` dans `DatabaseSeeder`) et/ou exposable via `sync-from-ad`/refresh.
- [x] **T2 — Modèle** : `App\Models\FileAssociation` + const `TYPE_ASSOCIATIONS='associations'` + relation pivot.
- [x] **T3 — Provider** (AC3, AC4) : `AssociationsStateProvider` (Exclusive, per-user, candidats bruts D2,
      payload `{identifier, progid, type}`, zéro APCu/AD), enregistré dans `AgentServiceProvider` ; exclusive par
      identifiant au compilateur (réutiliser l'extension faite en 27.3 si dispo).
- [x] **T4 — 🔴 Agent Go : handler + hash UserChoice** (AC5) : `handler_associations.go` (logique pure + calcul
      hash testable hôte) + `handler_associations_windows.go` (registre HKCU, SID/experience/temps) + câblage
      **compagnon** ; `handler_associations_test.go` avec **vecteurs de hash**.
- [x] **T5 — Contrat + golden** (AC6) : payload §7 ; item `state.v1.json` ; bump `FROZEN_STATE_HASH`/`frozenStateHash`
      croisé (relever la valeur courante).
- [x] **T6 — UI** (AC7) : `parc-settings/file-associations/` Livewire SFC + persistance pivot + nav.
- [x] **T7 — Tests** (AC8) : PHPUnit (provider/compilateur/UI/contrat) + Go (vecteurs hash + handler) verts.
- [x] **T8 — Doc + QA** (AC9) append-only.
- [ ] **T9 — Validation finale** : `php -l` ; grep NFR7 vide ; grep zéro retrofit legacy ; `go test`/vet/cross
      verts ; **/vm** `migrate:status` → `migrate --force` ; **validation lab Windows (ACTION HUMAINE Henri)** :
      `.pdf → Acrobat` appliqué au logon, association changée à la main **réimposée** (drift STRICT), ProgId
      inexistant → `error`, parcs différents → défauts différents par poste.
- [x] **T10 — Schéma `source`/`wpkg_package` + seed natives+WPKG** (AC10) : colonnes ajoutées à la migration
      `create` existante ; consts modèle ; seeder revu (natives `default.xml` + WPKG `PackagesXmlAssociationsReader`,
      baseline taggée en repli). **Golden/hash/agent intouchés.**
- [x] **T11 — Validation prédictive UI par parc** (AC11) : statut `native`/`wpkg-déployé`/`indisponible` calculé
      group-level Eloquent (PG-pur), badge+tooltip+toast EXACTS, remplace le warning générique N1. Provider
      INCHANGÉ. Vérifier la jointure `packages.xml id ⇄ Application::app_id`. Tests : seeder (tags), UI
      (indisponible vs applicable vs native), provider (NFR7 toujours vide, émet toujours).

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

## Questions pour Henri — ✅ TRANCHÉES (2026-06-16)

1. **Source de vérité** → ✅ **Table dédiée `file_associations`** + **seeder de population reproduisant
   l'existant legacy** (`FileAssociationSeeder` via flux seed prod / `sync-from-ad`, source `default.xml` +
   `associations.json`). Cf. DÉCISION HENRI n°4.
2. **Couplage app installée** → ✅ **Émettre, PAS de filtre WPKG** ; si ProgId absent → **ne pas forcer, préserver
   le choix utilisateur** (`error` non fatal, pas d'écrasement). Cf. DÉCISION HENRI n°5.
3. **Scope** → ✅ **`session`** (compagnon, au logon). Cf. DÉCISION HENRI n°6.

## Recommandation Modèle Dev

**Recommandation : `opus`.**

Justification : le **portage du hash UserChoice** est un morceau **cryptographique à fidélité absolue** (MD5
UTF-16LE + dérivation à constantes ; une erreur = hash silencieusement rejeté par Windows, association non
appliquée — bug difficile à diagnostiquer). S'y ajoutent l'**exclusivité par identifiant** au compilateur, un
**type au contrat** avec **bump de hash croisé PHP↔Go**, une **décision de source de vérité** structurante, et la
**suppression-avant-réécriture** de la clé UserChoice (ACL). Risque élevé concentré sur l'agent Go. `opus`.

## Dev Agent Record

### Agent Model Used

`claude-opus-4-8[1m]`

### Debug Log References

- **Hash UserChoice — fidélité vérifiée par double transcription indépendante.** Le portage Go de
  `SFTA.ps1::Get-Hash` a été cross-validé contre un portage **indépendant** en Python (arithmétique exacte
  masquée 32 bits, transcrit séparément de la source `SFTA.ps1` — pas une copie du Go). Trois triplets figés
  (`.pdf`/Acrobat, `http`/FirefoxURL, `.html`/FirefoxHTML) produisent les mêmes hashes des deux côtés :
  `h5ZFaFkHaDU=`, `9RbFZtAB87g=`, `zWoSzvx4Irg=`. Ces vecteurs sont baquetés dans `handler_associations_test.go`.
  Subtilité maîtrisée : l'overflow `int64` des produits de constantes (~1e19) wrappe en 2's complement, sans
  effet sur les 32 bits de poids faible que `convertInt32` conserve → identique au masquage exact Python.
- **Bump de hash croisé** : item `associations` ajouté au golden → relevé de la valeur courante puis recalcul.
  Nouveau `FROZEN_STATE_HASH` = `77fb548ac9b1f0604afce2a0c7d0316379391ef2e182a95a005b979f3fa5e3bd`, posé À
  L'IDENTIQUE en PHP (`ContractV1Test`) et Go (`hasher_test.go::frozenStateHash`). Hash per-item du nouvel item
  = `5d4bb78870ddadb6f1eb1126ba9958ca35e4198bd69a6db954719493b358468b` (calculé via `shared.HashItem`).
  Compteurs ajustés : session 5→6 (`contract_test.go`), total 7→8 (`hasher_test.go`).
- **Vérifs Go (hôte `~/go-toolchain`)** : `go test ./...` VERT, `go test ./shared/ -race` VERT (37 s),
  `go vet ./...` + `GOOS=windows go vet ./windows` PROPRES, `GOOS=windows GOARCH=amd64 go build ./windows`
  OK (binaire temporaire supprimé).
- **Vérifs PHP (hôte)** : `php -l` PROPRE sur les 14 fichiers PHP/blade créés/modifiés. PHPUnit exécuté SUR
  L'HÔTE (SQLite mémoire) : `AssociationsStateProviderTest` (13), `ContractV1Test` (6), `FileAssociationsPageTest`
  (5) — **24/24 VERTS**. Les 2 erreurs de `StateCompilerTest` (`target_context_resolves…`, `full_compile…`)
  sont **PRÉEXISTANTES** (reproduites sur HEAD `4fa9b40` avec mes changements stashés) : l'observer
  `WorkstationGroupObserver` dispatche un job AD-sync qui échoue faute d'AD sur l'hôte — artefact d'environnement,
  pas une régression. Elles passent sur la VM.
- **NFR7** : `AssociationsStateProvider` code-only (docblocks retirés) ZÉRO `LdapRecord|samba-tool|Cache::|apcu_|
  AssociationsResolver`. Légende dans 2 docblocks (mentions de l'interdit) seulement, retirées par le test.
- **Zéro retrofit legacy** : `app/Gpo/*` et `app/Http/Controllers/Gpo/*` INTOUCHÉS (`git status` vide).

### Completion Notes List

- **T0 (tranché par Henri)** : source = table dédiée `file_associations` + pivot `file_association_assignables`
  (calque `shortcut_assignables`/`registry_setting_assignables`). Couplage app = ÉMETTRE (pas de filtre WPKG),
  ProgId absent → préserver le choix utilisateur. Scope = `session` (compagnon, HKCU).
- **Hash UserChoice (cœur de risque)** : logique PURE dans `agent/shared/handler_associations.go`
  (`UserChoiceHash` + `getHash`/`getShiftRight`/`getLong`/`convertInt32`, ~portage 1:1 de `Get-Hash`), ops
  Windows dans `agent/windows/handler_associations_windows.go` (SID via token de processus, FileTime hex
  secondes-à-zéro iso `Get-HexDateTime`, GUID `{D18B6DD5-…}` extrait de `shell32.dll` avec repli hardcodé,
  écriture HKCU avec **suppression-avant-réécriture** de la clé UserChoice via `registry.DeleteKey` =
  `RegDeleteKeyW` natif comme le legacy). Câblé COMPAGNON dans `companion_windows.go`.
- **Mode ProgId-absent (D-Henri n°5)** : `ProgIDRegistered()` interroge `HKCR\<ProgId>` (fusion HKLM+HKCU des
  classes). Si absent → le handler NE supprime/réécrit PAS la clé existante (`deleteSeen` reste faux dans le
  fake, choix utilisateur intact), remonte une erreur NON fatale au `detail` explicite, sans réécriture en
  boucle (testé sur 3 passes). Isolation par item : un ProgId absent n'empêche pas les autres associations de
  converger (effort maximal, première erreur remontée à la fin).
- **Seeder de reproduction (D-Henri n°4)** : DEUX mécanismes — (1) migration de seed
  `…_140200_seed_file_associations_catalog.php` (baseline figée idempotente, jouée sur VM au `migrate`) ;
  (2) `FileAssociationSeeder` classe (câblé dans `DatabaseSeeder` iso `ShortcutSeeder`, idempotent/rejouable)
  qui **parse `default.xml` legacy** quand il est lisible (VM/prod, via `AssociationsResolver::DEFAULT_XML_PATH`)
  et retombe sur la baseline figée sinon (hôte/CI). Le seeder attache les défauts à TOUS les parcs actifs
  (reproduction de la portée legacy « all »). ⚠️ Sur l'hôte (worktree) `default.xml` est ABSENT → seule la
  baseline est testée ; **le parse `default.xml` réel reste à VALIDER sur VM**.
- **Provider** : `AssociationsStateProvider` (UN seul, `scope=Session`) implémente `KeyedExclusiveProvider`
  avec `exclusiveKey()=identifier` → exclusivité PAR IDENTIFIANT au `StateCompiler` SANS aucune modification du
  compilateur (mécanisme 27.3 réutilisé). Payload concret `{identifier, progid, type}`, jamais d'id de catalogue
  ni de hash/SID. Lecture Postgres pure.
- **UI** : SFC Livewire `parc-settings/file-associations/index.blade.php` (calque `registry-settings`),
  `WithToasts`, geste par parc (toggle = attach/detach pivot, `syncWithoutDetaching` idempotent), gate
  `app.customize`, route nommée `app.parc-settings.file-associations`.

- **T10 — Catalogue tagué `native`/`wpkg` (D-Henri n°7).** Colonnes `source` (`native`|`wpkg`, défaut
  `native`) + `wpkg_package` (nullable) AJOUTÉES à la migration `create` EXISTANTE `…_140000` (Pending sur VM,
  pas d'alter séparé). Modèle : consts `SOURCE_NATIVE`/`SOURCE_WPKG`, `$fillable` étendu, helper `isNative()`.
  `catalogKey(identifier, progid)` INCHANGÉ (l'identité reste la paire ; `source`/`wpkg_package` sont des
  attributs). Seed-migration `…_140200` + baseline figée du seeder TAGUÉES de façon cohérente : Firefox
  (`.html/.htm/http/https → Firefox*`) = `wpkg` paquet `firefox` ; `.jpg → WindowsPhotoViewer` = `native` ;
  AJOUT `.txt → txtfile` = `native` (cas de Henri). Le `FileAssociationSeeder` peuple DEUX sources : (1) natives
  via le parse `default.xml` legacy (tag `native`/`wpkg_package=null`) ; (2) WPKG via
  `PackagesXmlAssociationsReader::read()` (`packageId → identifier → {ProgId, type}` → tag `wpkg`/
  `wpkg_package=<packageId>`). **Préférence native** : `mergeCatalogs()` insère d'abord les `wpkg` puis les
  `native` écrasent une paire identique → un built-in bat un paquet. Repli baseline figée si ni `default.xml`
  ni `packages.xml` lisibles (hôte/CI). **Choix du reader legacy `App\Gpo` dans le SEEDER** : acceptable (geste
  d'admin ponctuel, pas le chemin critique desired-state) ; `App\Services\AppStore\PackagesXmlService` ÉCARTÉ
  car il ÉCRIT `packages.xml` (`regenerate()`) mais n'expose PAS les associations par paquet — aucune source
  non-legacy équivalente. Le PROVIDER reste PG-pur (ne lit jamais le reader).

- **T11 — Validation prédictive UI par parc (D-Henri n°7).** Le computed `associations()` ajoute un champ
  `availability ∈ applicable|unavailable` : `native` → applicable ; `wpkg` & `wpkg_package` ∈ paquets déployés
  du parc → applicable ; `wpkg` & paquet non déployé → unavailable. Calcul des paquets déployés =
  `deployedPackagesForParc(int)` : requête **group-level Eloquent PG-pure** sur le `WorkstationGroup`
  (`appProfiles.applications.app_id` + `applications.app_id` directes + déps transitives BFS via query builder
  sur `application_dependencies`), **SANS `Cache::`/APCu** (inspiré de
  `WorkstationPackagesResolver::computePackages()` branche « via les parcs », mais ciblé sur UN groupe et sans
  cache). UI : le warning générique N1 (icône+tooltip sur lignes assignées) REMPLACÉ par un signal EXACT —
  badge « indisponible » rouge + icône warning `text-error` + tooltip nommant le paquet (« `<pkg>` n'est pas
  déployé sur ce parc → cette association échouera ici… ») UNIQUEMENT sur les `unavailable`. Toast EXACT à
  l'activation : `toastWarning` nommant le paquet manquant si `unavailable`, sinon `toastSuccess` simple
  (« Association activée pour le parc. »).

- **Jointure `<package id>` ⇄ `Application::$app_id` (VÉRIFIÉE).** La clé du reader `PackagesXmlAssociationsReader`
  est l'attribut `<package id>` des `<package>` de `packages.xml`. Ce fichier est généré par
  `App\Services\AppStore\PackagesXmlService::regenerate()`, qui itère `Application::installed()` et émet le
  `$app->xml` de chaque app — dont la RACINE est `<package id="…">` valant `app_id` (convention confirmée par les
  fixtures : `tests/Fixtures/Gpo/packages-xml-sample.xml` `<package id="firefox">`, `ApplicationXmlReaderTest`
  `app('firefox', '<package id="firefox">…')`, `PackagesXmlServiceTest`). Le `WorkstationPackagesResolver`
  collecte précisément `$app->app_id`. Donc comparer `FileAssociation::$wpkg_package` (= `packageId` du reader,
  seedé) à l'ensemble des `app_id` déployés du parc est correct. **Risque résiduel** : un paquet dont le
  `<package id>` de son XML diverge de son `app_id` en base romprait la jointure silencieusement — non observé
  dans les fixtures, à confirmer sur les vrais `packages.xml`/apps de la VM (cf. « reste à valider »).

- **Invariants préservés (preuves).** `AssociationsStateProvider` INCHANGÉ (diff vide ; grep NFR7
  `ldap|apcu|wpkg|samba-tool|Cache::|PackagesXml` code-only VIDE ; émet toujours indépendamment du déploiement).
  Payload contrat `{identifier, progid, type}` INCHANGÉ : `git diff --stat` sur `agent/`, `tests/Fixtures/Agent/`,
  `tests/Unit/Services/Agent/ContractV1Test.php`, `agent/shared/hasher_test.go` = **VIDE** (md5 identiques au
  HEAD avant extension) ; `go test ./...` `(cached)` = preuve que l'agent n'a pas bougé. L'agent ne connaît
  jamais native/wpkg ; il reste le dernier rempart via `ProgIDRegistered`.

### File List

**Nouveaux fichiers :**
- `database/migrations/2026_06_16_140000_create_file_associations_table.php`
- `database/migrations/2026_06_16_140100_create_file_association_assignables_table.php`
- `database/migrations/2026_06_16_140200_seed_file_associations_catalog.php`
- `app/Models/FileAssociation.php`
- `database/factories/FileAssociationFactory.php`
- `app/Services/Agent/Providers/AssociationsStateProvider.php`
- `database/seeders/FileAssociationSeeder.php`
- `resources/views/pages/parc-settings/file-associations/index.blade.php`
- `agent/shared/handler_associations.go`
- `agent/shared/handler_associations_test.go`
- `agent/windows/handler_associations_windows.go`
- `tests/Unit/Services/Agent/AssociationsStateProviderTest.php`
- `tests/Feature/Livewire/ParcSettings/FileAssociationsPageTest.php`
- `tests/Unit/Seeders/FileAssociationSeederTest.php` *(extension T10/T11 : tags, idempotence, préférence native)*

**Fichiers modifiés :**
- `app/Providers/AgentServiceProvider.php` (enregistrement `AssociationsStateProvider`)
- `database/seeders/DatabaseSeeder.php` (câblage `FileAssociationSeeder`)
- `routes/web.php` (route `file-associations`)
- `agent/windows/companion_windows.go` (câblage handler `associations`)
- `tests/Fixtures/Agent/state.v1.json` (item `associations` ajouté + hash per-item)
- `agent/shared/hasher_test.go` (`frozenStateHash` bumpé + compteur 7→8)
- `agent/shared/contract_test.go` (compteur session 5→6)
- `tests/Unit/Services/Agent/ContractV1Test.php` (`FROZEN_STATE_HASH` bumpé)
- `docs/agent/contract-v1.md` (§7.2 payload `associations` + note `source`/`wpkg_package` serveur-only — T10/T11)
- `docs/agent/state-providers.md` (section `associations` + hash UserChoice + bullet validation prédictive — T11)
- `docs/qa/domains/agent.md` (section `## Story 27.3bis`, append-only ; scénario 27.3bis.6bis prédictif + checklist 27.3bis.7 EXACTE)
- `docs/qa/README.md` (ligne domaine agent enrichie 27.3bis + extension WPKG-aware)
- `_bmad-output/implementation-artifacts/sprint-status.yaml` (ligne 27-3bis → review, commentaire enrichi WPKG-aware)

**Fichiers modifiés par l'extension WPKG-aware (T10/T11, 2026-06-17) :**
- `database/migrations/2026_06_16_140000_create_file_associations_table.php` (colonnes `source` + `wpkg_package`)
- `database/migrations/2026_06_16_140200_seed_file_associations_catalog.php` (baseline taguée native/wpkg + `.txt → txtfile`)
- `app/Models/FileAssociation.php` (consts `SOURCE_NATIVE`/`SOURCE_WPKG`, `$fillable`, helper `isNative()`)
- `database/seeders/FileAssociationSeeder.php` (peuplement natives `default.xml` + WPKG `PackagesXmlAssociationsReader`, `mergeCatalogs()` préférence native)
- `database/factories/FileAssociationFactory.php` (états `native()`/`wpkg()`, défaut `source=native`)
- `resources/views/pages/parc-settings/file-associations/index.blade.php` (champ `availability`, calcul group-level PG-pur `deployedPackagesForParc()`, warning EXACT + toast EXACT remplaçant le N1 générique)
- `tests/Feature/Livewire/ParcSettings/FileAssociationsPageTest.php` (6 tests AC11 : native applicable, wpkg indisponible vs déployé, via app profile, toast exact warning/succès)

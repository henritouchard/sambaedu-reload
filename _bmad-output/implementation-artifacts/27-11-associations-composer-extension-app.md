# Story 27.11 : Composer d'associations par défaut — extension libre + app par nom

Status: review

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

> **ℹ️ NUMÉROTATION — lire en premier.** Slot canonique 27.10 **déjà occupé**
> (`27-10-prechargement-identite-machine-overlay`). Cette story prend le prochain entier libre : **27.11**
> (27.4/27.5/27.6 restent réservés à install apps / extinction legacy). Elle est le **v2 de l'UI d'associations
> livrée en 27.3bis** : on passe des **toggles d'un catalogue figé** à un **composer** où l'admin saisit une
> extension/protocole et choisit l'application **par son nom**. Le canal agent (provider/compilateur/handler/hash)
> de 27.3bis est **réutilisé tel quel** — c'est avant tout une story **UI + service serveur**.

## ✅ DÉCISIONS HENRI — TRANCHÉES (2026-06-17)

> Issues du cadrage + du spike `Applications\<exe>` (2026-06-17). **NE PAS re-trancher.**
>
> 1. **Mapping extension→app : RICHE + GÉNÉRIQUE EN FALLBACK.** Pour une paire (extension `X`, app `A`) :
>    ProgId **riche** si `A` en déclare un *pour `X`* (depuis `packages.xml`), **sinon** ProgId **générique**
>    `Applications\<exe de A>` (« lance cet exe avec le fichier », ce que Windows crée via « Ouvrir avec »).
> 2. **Dropdown d'apps = WPKG/installées + natives Win32 curées.** Source 1 = table `Application` (a déjà `name`
>    + `icon_url`). Source 2 = **petite table native curée** (Bloc-notes/`txtfile`, Paint, WordPad, Visionneuse
>    photos…). **EXCLUS** : apps UWP modernes (ProgId `AppX…` ingérables) ; saisie ProgId/exe brut « expert ».
> 3. **UI = REFONTE de l'onglet associations en composer.** Dans la page `WorkstationGroup`
>    (`/app/parc/groups/{id}?tab=associations`, gate `app.customize`, granularité **par parc** inchangée) : un
>    bloc « ajouter une association » (saisie extension/protocole + dropdown app à icône) **au-dessus** de la
>    **liste des associations du parc** ; les défauts legacy seedés (27.3bis) deviennent des **lignes éditables /
>    désactivables**.
> 4. **Validation prédictive CONSERVÉE** là où calculable : app WPKG choisie → check « paquet déployé sur le
>    parc » (réutilise la logique group-level Eloquent de 27.3bis) → badge **« indisponible »** + tooltip nommant
>    le paquet ; native → toujours applicable ; générique → best-effort.
>
> **— Acquis du spike `Applications\<exe>` (2026-06-17, code lu : `agent/shared/handler_associations.go`,
> `agent/windows/handler_associations_windows.go`) :**
> 5. **L'agent gère DÉJÀ n'importe quel ProgId, `Applications\<exe>` inclus** — le hash est calculé sur la chaîne
>    ProgId **verbatim** (`handler_associations.go:258`, comme Windows lui-même), l'écriture UserChoice est
>    générique (`handler_associations_windows.go:106`). **Le chemin hash/écriture NE CHANGE PAS.**
> 6. **Seul garde-fou en travers = D-Henri n°5** (`ProgIDRegistered`, `handler_associations.go:85`) : avant
>    d'écrire, l'agent vérifie `HKCR\<ProgId>` ; absent → s'abstient (choix préservé, `error` non fatal). Donc si
>    `HKCR\Applications\<exe>\shell\open\command` existe → **marche tel quel, ZÉRO code**. Sinon → l'asso ne
>    s'applique pas tant que la clé n'existe pas.
> 7. **Le fix reste PER-USER — PAS de scope machine.** `HKCR` = vue fusionnée `HKLM` + **`HKCU\Software\Classes`**.
>    Le **compagnon** (déjà en droits user) peut écrire
>    `HKCU\Software\Classes\Applications\<exe>\shell\open\command = "<chemin exe>" "%1"` **avant** d'imposer
>    UserChoice → satisfait `ProgIDRegistered` au passage suivant. Aucune escalade HKLM/admin.
> 8. **Dépendance donnée = le chemin de l'exe.** `Application` n'a **pas** de champ exe runtime (a `name`,
>    `app_id`, `icon_url`, `installer_filename`… mais pas l'exe). → ajouter une colonne `executable` (nullable) à
>    `applications`, et stocker l'exe dans la table native curée.

## Story

En tant qu'**admin d'établissement**,
je veux **saisir librement une extension (ou un protocole) et lui choisir une application par son nom dans un
dropdown**, plutôt que de cocher des paires figées d'un catalogue,
afin de **maîtriser l'association par défaut d'un parc sans connaître la notion opaque de « ProgId »** — le
serveur traduisant mon choix (app) en cible technique (ProgId riche, sinon générique `Applications\<exe>`) que
l'agent applique déjà.

## Contexte & intention

**D'où l'on part (27.3bis).** L'onglet associations affiche un **catalogue figé** d'entrées prédéterminées
(`.pdf → AcroExch.Document.DC`, `http → FirefoxURL`…) qu'on **active/désactive par toggle**. C'est volontairement
*catalogue-first* (reproduire l'existant legacy, zéro régression) et ça masque deux pièges réels — un ProgId
n'est pas saisissable de tête, et une saisie libre ne serait pas validable côté serveur. Mais l'admin ne peut
**pas** composer une association (`mon extension → mon app`).

**Ce que le v2 apporte.** Une **saisie d'extension/protocole** + un **dropdown d'apps par nom**. Le maillon neuf
est un **service `resolver` serveur** qui traduit *(extension, app)* → *(progid, source, wpkg_package)* et **upsert
une ligne `file_associations`** attachée au parc — exactement la structure que 27.3bis alimente déjà.

**Insight d'architecture (peu invasif).** Le pipeline aval — `AssociationsStateProvider` → `StateCompiler` →
handler Go — émet/consomme déjà des items **génériques** `{identifier, progid, type}`, et l'agent écrit
UserChoice+Hash pour **n'importe quelle** paire (spike, D-Henri n°5). Donc si le composer produit les **mêmes
lignes `file_associations`** (avec le bon `progid`/`source`/`wpkg_package`), **rien en aval ne change**. Les
colonnes existantes absorbent le générique → **pas de migration de `file_associations`**. La seule donnée neuve
est le **chemin exe** (D-Henri n°8), nécessaire **uniquement** pour l'auto-enregistrement du fallback générique.

**Ce que cette story livre :**
- **Service resolver** PG-pur (`AssociationResolver`) : *(extension X, app A)* → *(progid, source, wpkg_package)*.
- **Donnée** : colonne `applications.executable` + **table native curée** (catalogue ProgId/nom/exe, seedée).
- **UI composer** : refonte de l'onglet associations (saisie + dropdown à icône + liste éditable + prédictif).
- **Agent Go** : **un seul** ajout — l'auto-enregistrement **per-user** de `Applications\<exe>` par le compagnon
  avant d'imposer UserChoice (D-Henri n°7), + un **vecteur de test** hash pour un ProgId contenant `\`.

**Ce que cette story N'EST PAS :** l'installation des apps (→ 27.5, mais la donnée `executable` peut être captée
ici) ; l'extinction du canal legacy (→ 27.6) ; la saisie ProgId/exe brut « expert » (hors-scope, D-Henri n°2) ;
les apps UWP modernes ; la granularité poste/utilisateur individuel (reste par parc) ; un changement de la machine
d'états §5 ou du hash UserChoice (réutilisés de 27.3bis).

## ⚠️ Pièges & tensions (lire AVANT de coder)

1. **🔴 AC1 EST UN GATE EMPIRIQUE (action humaine Henri) qui peut réduire le scope.** Deux points ne sont
   confirmables que sur un vrai poste : **(1)** un `UserChoice` pointant vers
   `HKCU\Software\Classes\Applications\<exe>` est-il **honoré par le shell** *sans* que l'app déclare l'extension
   dans `SupportedTypes` ? **(2)** `getHash` produit-il un hash valide pour un ProgId contenant `\`
   (`Applications\<exe>`) ? Si **(1) échoue → repli « riche + natives curées »** : on retire le fallback générique
   (et la donnée `executable` + l'auto-enregistrement agent deviennent inutiles), on **perd seulement**
   l'extension custom → app arbitraire (ex. `.clclcc`). **Ne pas bâtir le générique avant le feu vert AC1.**

2. **Un ProgId est par (app × type de contenu), pas par app.** Firefox déclare `FirefoxHTML` pour `.html` et
   `FirefoxURL` pour `http`, et **rien** pour `.txt`. Le resolver ne doit donc PAS supposer « 1 app = 1 ProgId » :
   pour une extension donnée, n'utiliser le ProgId riche **que** s'il est déclaré *pour cette extension* dans
   `packages.xml` ; sinon basculer générique. La relation app↔ProgId riche est **déclarée** (lue du paquet),
   **jamais dérivée** de la chaîne.

3. **`HKCR\Applications\<exe>` n'est PAS garanti par l'OS.** Certains installeurs l'enregistrent, Windows le crée
   via « Ouvrir avec », mais ce n'est pas universel (spike). D'où l'auto-enregistrement **per-user** par le
   compagnon (D-Henri n°7). ⚠️ Affiner `ProgIDRegistered` **pour le cas générique** : pour un ProgId
   `Applications\<exe>`, vérifier le sous-clé **`shell\open\command`** (pas seulement la présence du nœud), sinon
   on croit l'asso applicable alors qu'elle ouvrira « comment voulez-vous ouvrir… ».

4. **L'auto-enregistrement a besoin du chemin exe COMPLET** (`"C:\…\app.exe" "%1"`). Source = colonne
   `applications.executable` (WPKG) / table native curée (built-ins). Si le chemin est absent pour une app
   sélectionnée et qu'aucun ProgId riche n'existe → l'UI doit l'**empêcher / signaler** (pas de générique sans
   exe). Le `%1` est obligatoire (sinon le fichier n'est pas passé en argument).

5. **NE PAS toucher au golden / `FROZEN_STATE_HASH` sauf preuve du contraire.** Le payload reste
   `{identifier, progid, type}` (le `progid` est juste parfois `Applications\<exe>`). `AssociationsStateProvider`,
   `StateCompiler`, `state.v1.json` et le hash croisé PHP↔Go sont **a priori INCHANGÉS** — le **prouver** par
   `git diff --stat` vide sur `agent/`, `tests/Fixtures/Agent/`, `ContractV1Test.php`, `hasher_test.go`. Si un
   diff apparaît, c'est un signal d'erreur de conception, pas un bump à faire à la légère.

6. **NFR7 — le resolver et l'UI restent PG-purs côté chemin critique.** Le croisement WPKG (prédictif) vit dans
   l'UI/assignation comme en 27.3bis (group-level Eloquent, **sans** APCu de `WorkstationPackagesResolver`). Le
   resolver **peut** lire `PackagesXmlAssociationsReader` (geste d'**administration**, pas le chemin desired-state,
   iso le `FileAssociationSeeder` de 27.3bis) — mais `AssociationsStateProvider` reste interdit d'APCu/legacy.

7. **Slug `txtfile` & co. sont des ProgId « riches » natifs.** Une app native curée porte un ProgId canonique
   (`txtfile`, `mspaint`…) → `source=native`, **toujours applicable** (aucune dépendance de paquet, pas besoin de
   générique). Ne PAS confondre « native curée » (ProgId connu, présent) et « générique » (fabrication
   `Applications\<exe>`).

8. **Convergence / drift STRICT inchangés (27.8).** Une association retirée côté serveur **disparaît** → l'agent
   cesse de la gérer (le choix appliqué reste, D-Henri n°5). Pas de nouveau mode de drift. Migrations PAS
   auto-jouées (`migrate:status` → `migrate --force` /vm). Go = hôte (`~/go-toolchain`), PHPUnit /vm, jamais de VM
   depuis un worktree.

## Acceptance Criteria

### AC1 — 🔴 GATE empirique poste + vecteur de test hash générique (BLOQUANT, action humaine)

**Given** le fallback générique `Applications\<exe>`
**When** Henri valide sur un poste de test (procédure en Dev Notes)
**Then** est confirmé que **(1)** un `UserChoice` vers `HKCU\Software\Classes\Applications\<exe>` est honoré par
le shell sans `SupportedTypes` déclarant l'extension ; **(2)** `getHash` produit un hash valide pour un ProgId
contenant `\`
**And** un **vecteur de test** `Applications\<exe>` est ajouté à `handler_associations_test.go` (fidélité)
**And** si (1) échoue → la story bascule en **repli « riche + natives curées »** (AC4/AC5/AC6 ajustés : pas de
générique, `executable` + auto-enregistrement retirés) — décision tracée.

### AC2 — Donnée : `applications.executable` + table native curée seedée (FR26)

**Given** le besoin de l'exe pour le générique et d'un référentiel d'apps natives
**When** les migrations + seeders s'exécutent
**Then** `applications` gagne une colonne **`executable`** (nullable, chemin/nom de l'exe runtime), migration
idempotente `down()` symétrique, comment daté 27.11
**And** une **table native curée** (`native_applications` ou équivalent — D1 table dédiée admise) liste
`{label, progid, executable, assoc_types supportés}` pour les built-ins Win32 (Bloc-notes/`txtfile`, Paint,
WordPad, Visionneuse photos), seedée idempotente, **UWP exclues**
**And** l'exe WPKG est capté à l'import/édition d'app **ou** dérivé de `packages.xml` (best-effort, documenté).

### AC3 — Service resolver PG-pur : (extension, app) → (progid, source, wpkg_package) (FR21, NFR7)

**Given** une extension/protocole `X` et une app `A` (WPKG ou native curée)
**When** `AssociationResolver::resolve(X, A)` est appelé
**Then** il retourne `{progid, source, wpkg_package}` selon : **A native curée** → ProgId canonique,
`source=native`, `wpkg_package=null` ; **A WPKG ayant déclaré un handler pour `X`** (packages.xml) → ProgId riche,
`source=wpkg`, `wpkg_package=A.app_id` ; **sinon (générique)** → `progid=Applications\<exe de A>`, `source=wpkg`
+ `wpkg_package=A.app_id` si A est WPKG (sinon `native`)
**And** le service est **testable hôte**, PG-pur (`grep ldap|apcu` vide), lecture `packages.xml` admise (geste
admin, hors chemin desired-state)
**And** il produit une **ligne `file_associations`** (upsert par `catalogKey(identifier, progid)`, iso 27.3bis)
attachée au parc — **payload aval inchangé**.

### AC4 — UI composer : saisie extension + dropdown app par nom (FR26, FR19)

**Given** l'admin ouvre l'onglet associations d'un parc (`?tab=associations`, gate `app.customize`)
**When** la vue est refondue
**Then** un bloc « ajouter une association » propose une **saisie d'extension/protocole** (validée : `.xxx` ou
protocole) + un **dropdown d'apps par nom à icône** (WPKG via `Application.name`/`icon_url` + natives curées) ;
valider crée la ligne via `AssociationResolver` et l'attache au parc
**And** la **liste des associations du parc** affiche les entrées (défauts legacy seedés inclus) comme lignes
**éditables/désactivables** (désactiver = détacher = cesser de gérer, iso 27.3bis)
**And** la saisie d'une (extension, app) **sans ProgId riche ET sans exe** est **empêchée/signalée** (piège n°4)
**And** l'UI reste **per-parc** (page WorkstationGroup), `WithToasts`, Livewire SFC.

### AC5 — Validation prédictive étendue aux entrées custom (D-Henri n°7 27.3bis, NFR7)

**Given** une association composée pointant un paquet WPKG
**When** l'UI calcule le statut sur le parc courant
**Then** `native` → applicable ; `wpkg` & paquet déployé sur le parc → applicable ; `wpkg` & paquet NON déployé →
**« indisponible »** (badge + tooltip nommant le paquet + toast EXACT) ; **générique** → best-effort
**And** la source du déploiement par parc = la requête **group-level Eloquent** existante (27.3bis), PG-pure,
**sans** le cache APCu de `WorkstationPackagesResolver`.

### AC6 — Agent Go : auto-enregistrement per-user `Applications\<exe>` (FR21) — *conditionné à AC1(1)*

**Given** une association générique `Applications\<exe>` dont la clé n'est pas enregistrée sur le poste
**When** le **compagnon** converge (avant d'imposer UserChoice)
**Then** il écrit `HKCU\Software\Classes\Applications\<exe>\shell\open\command = "<chemin exe>" "%1"` (per-user,
**aucune** écriture HKLM/admin), de sorte que `ProgIDRegistered` (raffiné pour vérifier `shell\open\command` sur
le cas générique) le voie au passage suivant
**And** le hash UserChoice et `WriteUserChoice` sont **réutilisés tels quels** (zéro régression 27.3bis)
**And** chemin exe absent → comportement D-Henri n°5 préservé (s'abstient, `error` non fatal, choix utilisateur
conservé). **Cet AC tombe si AC1(1) échoue (repli).**

### AC7 — Invariants : provider / compilateur / contrat / golden INCHANGÉS (NFR13)

**Given** la nature « UI + resolver » de la story
**When** on mesure l'impact aval
**Then** `git diff --stat` est **vide** sur `agent/shared/handler_associations.go` (hors vecteur de test AC1),
`AssociationsStateProvider`, `StateCompiler`, `tests/Fixtures/Agent/state.v1.json`, `ContractV1Test.php`,
`hasher_test.go` — le payload reste `{identifier, progid, type}`
**And** si un diff golden apparaît, il est **justifié explicitement** ou traité comme un bug de conception.

### AC8 — Tests (NFR13)

**Then** Laravel : `AssociationResolverTest` (riche / générique / native ; jointure packages.xml ⇄ `app_id` ;
PG-pur) ; `FileAssociationsPageTest` étendu (composer : saisie + dropdown + création + édition + désactivation +
prédictif custom + garde-fou exe manquant)
**And** Go : `handler_associations_test.go` +1 vecteur `Applications\<exe>` (AC1) + test de l'auto-enregistrement
per-user (Test/Apply idempotent, abstention si exe absent) — **conditionné à AC1(1)**
**And** `grep` NFR7 vide sur le resolver/provider ; cross-compile + vet linux/windows verts.

### AC9 — Documentation + QA (append-only)

**Then** `docs/agent/contract-v1.md` (note : `progid` peut être `Applications\<exe>`, payload inchangé) ;
`state-providers.md` (section associations : composer + resolver riche/générique) ; `docs/qa/domains/agent.md`
section 27.11 (scénarios lab : composer une asso WPKG riche ; composer une asso générique custom ; prédictif
indisponible ; garde-fou exe manquant ; les 2 confirmations empiriques AC1).

## Tasks / Subtasks

- [x] **T1 — 🔴 GATE AC1 (action humaine Henri)** : validé 2026-06-18 sur poste Windows réel — (1) UserChoice vers `Applications\vlc.exe` honoré sans SupportedTypes (double-clic `.clclcc` après reboot ✅) ; (2) hash valide pour ProgId avec `\` (`Gk3UMH/Rm+A=` via SFTA.ps1 ✅). Scope complet débloqué. Vecteur de test `Applications\<exe>` à ajouter en T6.
- [x] **T2 — Donnée** (AC2) : migration `applications.executable` (nullable, idempotente, `down` symétrique) ;
      table **native curée** (`native_applications`) + modèle/factory + seeder idempotent (Win32 built-ins, UWP
      exclues) ; captation exe WPKG via la colonne `executable` (import/édition), documentée.
- [x] **T3 — Service resolver** (AC3) : `AssociationResolver::resolve(X, A)` PG-pur testable hôte ; upsert
      `file_associations` iso `catalogKey` (`compose()`) ; lecture `PackagesXmlAssociationsReader` admise (geste admin).
- [x] **T4 — UI composer** (AC4, AC5) : refonte de
      `resources/views/pages/parc/groups/_partials/associations-tab.blade.php` — bloc d'ajout (saisie extension +
      dropdown app à icône), liste éditable/désactivable, prédictif étendu, garde-fou exe manquant, `WithToasts`.
- [x] **T5 — Agent Go : auto-enregistrement per-user** (AC6) — *AC1(1) validé* : écriture
      `HKCU\Software\Classes\Applications\<exe>\shell\open\command` avant UserChoice (`RegisterApplicationProgID`,
      chemin exe résolu sur le poste via App Paths/PATH) ; raffiné `ProgIDRegistered` (vérif `shell\open\command`
      sur le cas générique) ; hash/écriture réutilisés ; câblage compagnon (interface Ops + impl Windows).
- [x] **T6 — Tests** (AC8) : `AssociationResolverTest` (9) ; `FileAssociationsPageTest` étendu (14) ;
      `NativeApplicationSeederTest` (2) ; `Story2711MigrationsTest` (1) ; Go `handler_associations_test.go`
      (vecteur générique `Applications\<exe>` + auto-enregistrement per-user) ; NFR7 grep vide.
- [x] **T7 — Preuve d'invariance** (AC7) : `git diff --stat` VIDE sur provider/compilateur/golden/contrat/hasher
      (cf. Completion Notes) ; agent touché seulement pour le raffinement `ProgIDRegistered`/auto-enregistrement
      (AC6) + vecteur de test (AC1).
- [x] **T8 — Doc + QA** (AC9) append-only : `docs/agent/contract-v1.md` (note `Applications\<exe>`),
      `docs/agent/state-providers.md` (composer + resolver), `docs/qa/domains/agent.md` (section 27.11).
- [~] **T9 — Validation finale** : `php -l` OK ; grep NFR7 vide ; `go test`/vet/cross **verts** ; migrations
      idempotentes vérifiées **en SQLite** (`Story2711MigrationsTest`). **RESTE ACTIONS HUMAINES HENRI** (non
      cochées) : **/vm** `migrate:status` → `migrate --force` (colonne `executable` + table `native_applications`) ;
      **validation lab Windows** : composer une asso WPKG riche appliquée au logon, composer une asso générique
      custom (`.clclcc` → app via `Applications\<exe>`), prédictif « indisponible », garde-fou exe manquant.

## Dev Notes

### Résultats du spike `Applications\<exe>` (2026-06-17) — ne pas refaire

[Code lu : `agent/shared/handler_associations.go`, `agent/windows/handler_associations_windows.go`.]
- Le hash UserChoice est calculé sur la **chaîne ProgId verbatim** (`UserChoiceHash`/`getHash`,
  `handler_associations.go:258`) — `Applications\firefox.exe` passe sans modification, comme Windows lui-même via
  « Ouvrir avec ». `WriteUserChoice` (`handler_associations_windows.go:106`) est générique. **Rien à changer ici.**
- `ProgIDRegistered` (`handler_associations.go:85`) ouvre `HKCR\<ProgId>` — `HKCR` = **vue fusionnée**
  `HKLM\Software\Classes` + `HKCU\Software\Classes` (commentaire ligne 82-83). C'est ce qui permet
  l'auto-enregistrement **per-user**.
- **Procédure de confirmation empirique (AC1, ~5 min/poste de test)** :
  1. choisir un exe non lié à `.clclcc`, ex. `notepad.exe` ;
  2. vérifier l'absence de `HKCR\Applications\notepad.exe\shell\open\command` ;
  3. écrire `HKCU\Software\Classes\Applications\notepad.exe\shell\open\command = "%SystemRoot%\system32\notepad.exe" "%1"` ;
  4. poser `UserChoice` `.clclcc → Applications\notepad.exe` (hash via l'outil legacy `SFTA.ps1` ou l'agent) ;
  5. double-cliquer un `.clclcc` → doit ouvrir Notepad, **sans réinitialisation au reboot**.

### Algorithme du resolver (cœur serveur de la story)

Pour `(extension X, app A)` :
1. **A native curée** → ProgId canonique (`txtfile`…), `source=native`, `wpkg_package=null` (toujours applicable).
2. **A = WPKG ayant déclaré un handler pour X** (`PackagesXmlAssociationsReader::read()` :
   `packageId → identifier → {ProgId, type}`) → ProgId riche déclaré, `source=wpkg`, `wpkg_package=A.app_id`.
3. **Sinon (fallback générique)** → `progid = Applications\<exe de A>` ; `source=wpkg` + `wpkg_package=A.app_id`
   si A est WPKG (check prédictif pertinent), `native` si A est curée.

Le résultat est **upserté** comme ligne `file_associations` (clé `catalogKey(identifier, progid)`, iso 27.3bis)
puis attaché au parc via le pivot `file_association_assignables`. **Les colonnes existantes
(`progid`/`source`/`wpkg_package`) suffisent** → pas de migration de `file_associations`.

### Pattern réutilisé (27.3bis — ne pas réinventer)

`FileAssociation` (`catalogKey`, `isNative`, pivot polymorphe), `AssociationsStateProvider` (Session, Exclusive
par identifiant, payload `{identifier, progid, type}`), `StateCompiler::selectExclusive`, handler Go
`Test/Apply`, validation prédictive group-level Eloquent. UI = on **refond** l'onglet existant
`pages/parc/groups/_partials/associations-tab.blade.php` (pas une nouvelle page). `PackagesXmlAssociationsReader`
+ `FileAssociationSeeder` montrent déjà la lecture packages.xml côté admin.

### Dépendances

| Story | Rôle | Statut | Bloquant ? |
|-------|------|--------|------------|
| **27.3bis — handler associations** | **Base directe** : provider/handler/hash/seeder/UI/onglet réutilisés et étendus. | review | Prérequis fort (rebase si correctifs review) |
| 27.5 — applications/WPKG | Captation de l'exe (`applications.executable`) ; le générique suppose l'app installée | backlog | Non (découplé ; donnée captée ici, agent reste dernier rempart) |
| 27.8 — drift STRICT | Contrat 4 clés / 3 statuts ; STRICT inconditionnel | done | Non (réutilisé tel quel) |
| 23.4/24.6/23.1 — compilateur/engine/contrat/golden | Infra réutilisée, **inchangée** | done | Non |

> **Invariant à prouver, pas à présumer** : provider/compilateur/golden/hash/agent **intouchés** (hors AC1/AC6).

### References

- [Source: `resources/views/pages/parc/groups/_partials/associations-tab.blade.php` (onglet à refondre)].
- [Source: `app/Models/FileAssociation.php` (`catalogKey`, `isNative`, `source`/`wpkg_package`),
  `app/Models/Application.php` (`name`/`app_id`/`icon_url`, **pas d'exe** → AC2)].
- [Source: `app/Services/Agent/Providers/AssociationsStateProvider.php`, `app/Services/Agent/StateCompiler.php`
  (INCHANGÉS)].
- [Source: `agent/shared/handler_associations.go` (`getHash`:258, `ProgIDRegistered`:85),
  `agent/windows/handler_associations_windows.go` (`WriteUserChoice`:106)].
- [Source: `app/Gpo/Services/PackagesXmlAssociationsReader.php`, `database/seeders/FileAssociationSeeder.php`].
- [Source: mémoires `project_associations_v2_extension_app_composer` (cadrage + spike),
  `project_associations_native_vs_wpkg_predictive_validation`, `project_registry_catalog_first_generic_underneath`,
  `project_drift_policy_strict_only`, `project_handler_agent_not_in_published_binary`].

## Questions pour Henri — ✅ TRANCHÉES (2026-06-17)

1. **Liberté du mapping** → ✅ **Riche + générique en fallback** (D-Henri n°1).
2. **Apps du dropdown** → ✅ **WPKG/installées + natives Win32 curées** ; UWP & saisie expert exclues (D-Henri n°2).
3. **Onglet** → ✅ **Refonte en composer** (D-Henri n°3).
4. **Validation prédictive** → ✅ **conservée là où calculable** (D-Henri n°4).
5. **Reste ouvert (gate AC1)** : les **2 confirmations empiriques poste** — feu vert avant de coder le générique.

## Recommandation Modèle Dev

**Recommandation : `fable`** (consigne projet stories agent desired-state — `feedback_epic23_model_fable5`).

Justification : story **cadrée**, à dominante **UI + service serveur**, qui **reproduit des patterns iso-existants
déjà lus** (provider/handler/hash/seeder/UI de 27.3bis, validation prédictive group-level). **Le cœur de risque
(hash UserChoice) est DÉJÀ livré et figé** — ici on ne fait qu'ajouter un vecteur de test et un auto-enregistrement
per-user trivial. Les invariants délicats (golden/contrat/agent intouchés) sont **balisés mécaniquement** (AC7,
`git diff --stat` vide). Le gate empirique (AC1) est une **action humaine**, pas une difficulté d'implémentation.
**Review `opus` en second avis** sur : fidélité du vecteur générique, justesse du resolver riche/générique, et
preuve d'invariance aval.

## Dev Agent Record

### Agent Model Used

`claude-opus-4-8[1m]` (Opus 4.8, 1M context). Fable recommandé par la story mais
indisponible — fallback opus assumé (iso 27.9/27.10).

### Debug Log References

- Tests hôte sur **SQLite :memory: + cache `array`** (phpunit.xml). `vendor/` réinstallé
  (`composer install --ignore-platform-req=ext-apcu --ignore-platform-req=ext-imagick`)
  + `mkdir -p bootstrap/cache`. `package:discover` échoue sur l'hôte (ext-apcu absent)
  mais sans impact tests (CACHE_DRIVER=array).
- Go : module `agent/`, toolchain `~/go-toolchain/go/bin/go`. `go test ./shared/` vert,
  `go vet ./shared/...` + `GOOS=windows go vet ./windows/...` propres, `GOOS=windows go
  build ./windows` OK.
- Vecteur de hash générique `.clclcc → Applications\vlc.exe` calculé via `UserChoiceHash`
  sur les inputs figés du test → `5q6eG+3TpdI=` (verrouille la fidélité du portage Go
  pour le cas `\` ; l'acceptation Windows-native est déléguée au lab, déjà validée AC1).

### Completion Notes List

**Périmètre livré (T2→T8).** Le gate AC1 (T1) étant validé empiriquement par Henri
(2026-06-18), le SCOPE COMPLET a été implémenté (fallback générique `Applications\<exe>`,
colonne `executable`, table native curée, auto-enregistrement agent AC6) — pas le repli.

- **T2 (AC2)** : migration `applications.executable` (nullable, idempotente `hasColumn`,
  `down()` symétrique, comment daté 27.11) ; table dédiée `native_applications`
  (`{key, label, progid, executable, assoc_types(json), icon_url}`) + modèle
  `NativeApplication` (`supportsIdentifier()`) + factory + `NativeApplicationSeeder`
  idempotent (`updateOrCreate` par `key` ; Bloc-notes/`txtfile`, Paint/`Paint.Picture`,
  WordPad, Visionneuse de photos ; **UWP exclues**) câblé dans `DatabaseSeeder` AVANT
  `FileAssociationSeeder`. Captation exe WPKG = colonne `executable` (alimentée à
  l'import/édition).
- **T3 (AC3)** : `AssociationResolver::resolve(X, A)` PG-pur → `ResolvedAssociation
  {progid, source, wpkgPackage, generic}`. Native déclarant X → ProgId canonique
  (`source=native`) ; native ne déclarant pas X → générique (piège n°2) ; WPKG déclarant
  un handler pour X (via `PackagesXmlAssociationsReader`) → ProgId riche
  (`source=wpkg`, `wpkg_package=app_id`) ; sinon générique `Applications\<exe>`. Garde-fou
  n°4 : générique sans exe → `InvalidArgumentException`. `compose()` upsert
  `file_associations` via `catalogKey(identifier, progid)` + attache au parc
  (`syncWithoutDetaching`). **Pas de migration de `file_associations`.**
- **T4 (AC4/AC5)** : refonte de l'onglet en COMPOSER (saisie extension/protocole validée
  regex + dropdown app par nom à icône WPKG+natives ; `compose()`/`disable()` ; liste
  n'affichant que les associations attachées au parc, éditables/désactivables ; prédictif
  group-level Eloquent PG-pur SANS APCu ; garde-fou exe → toast d'erreur ; `WithToasts`).
  Type déduit de la saisie (`.` → file, sinon protocol).
- **T5 (AC6)** : interface `AssociationsOps` étendue de `RegisterApplicationProgID(exe)` ;
  `Apply()` tente l'auto-enregistrement per-user POUR LE CAS GÉNÉRIQUE avant UserChoice
  (échec/exe introuvable → abstention D-Henri n°5) ; impl Windows écrit
  `HKCU\Software\Classes\Applications\<exe>\shell\open\command = "<chemin>" "%1"` (chemin
  résolu via App Paths HKCU/HKLM puis PATH — **jamais reçu du serveur**, invariant) ;
  `ProgIDRegistered` raffiné POUR LE CAS GÉNÉRIQUE (vérif `shell\open\command` non vide).
  `getHash`/`WriteUserChoice`/`SessionInputs` **réutilisés tels quels**.
- **T6 (AC8)** : `AssociationResolverTest` (9), `FileAssociationsPageTest` (14 — réécrit
  V2 composer), `NativeApplicationSeederTest` (2), `Story2711MigrationsTest` (1) ; Go
  `handler_associations_test.go` (+vecteur `Applications\vlc.exe` AC1 + 3 tests
  auto-enregistrement : auto-register→apply idempotent, abstention si exe absent, ProgId
  riche n'auto-registre pas). **Résultats** : PHPUnit 55 tests / 238 assertions VERTS
  (mes tests + invariance provider/contrat/seeder/reader) ; `go test ./shared/` VERT ;
  `go vet` linux+windows VERT ; cross-compile Windows VERT.
- **T7 (AC7) — PREUVE D'INVARIANCE** : `git diff --stat` **VIDE** sur :
  `app/Services/Agent/Providers/AssociationsStateProvider.php`,
  `app/Services/Agent/StateCompiler.php`, `tests/Fixtures/Agent/state.v1.json`,
  `tests/Unit/Services/Agent/ContractV1Test.php`, `agent/shared/hasher_test.go`. Sortie :

  ```
  $ git diff --stat -- AssociationsStateProvider.php StateCompiler.php state.v1.json ContractV1Test.php hasher_test.go
  (aucune ligne — diff vide)
  ```

  Le seul code agent touché (autorisé AC1/AC6) :
  ```
  agent/shared/handler_associations.go          |  71 +++++++++++++-
  agent/shared/handler_associations_test.go     | 136 ++++++++++++++++++++++++--
  agent/windows/handler_associations_windows.go | 104 ++++++++++++++++++++
  ```
  Le payload reste `{identifier, progid, type}` ; golden Go `frozenStateHash` et PHP
  `FROZEN_STATE_HASH` intouchés (ContractV1Test + tests golden Go verts).
- **NFR7** : `grep -rinE 'apcu_|LdapRecord|use .*Ldap|samba-tool'` VIDE sur
  `AssociationResolver`/`ResolvedAssociation`/`AssociationsStateProvider` (seules des
  mentions documentaires « Aucun… APCu »). `php -l` OK sur tous les fichiers PHP touchés.
- **Choix de conception non explicitement couvert** : le chemin COMPLET de l'exe n'est
  PAS dans le payload (invariant AC7) ; l'agent le résout SUR LE POSTE via `App Paths`
  (HKCU puis HKLM) puis le PATH (`exec.LookPath`). Cohérent avec D-Henri n°5 (exe
  introuvable → abstention). À valider en lab (résolution App Paths/PATH du `<exe>`).
- **Note suite Unit complète** : `vendor/bin/phpunit --testsuite Unit` montre des
  errors/failures PRÉEXISTANTS et hors-périmètre (LDAP non joignable sur l'hôte —
  `ldap_search(): Can't contact LDAP server` ; AgentToolService ext-zip ; factories
  LDAP-dépendantes). Confirmé via `git stash` : `LdapConnectionTest` échoue à
  l'identique sur HEAD avant mes modifs. Aucun de mes tests n'échoue.

**ACTIONS HUMAINES RESTANTES (Henri) — T9 non cochées :**
1. **/vm** : `php artisan migrate:status` → `php artisan migrate --force` pour appliquer
   `applications.executable` + `native_applications` sur PostgreSQL (les migrations ne
   sont jouées qu'en SQLite côté dev ; +`config:cache`/`chown www-admin` si besoin).
   Seeder native : `php artisan db:seed --class=NativeApplicationSeeder` (idempotent).
2. **Validation lab Windows** (poste réel) : (a) composer une asso WPKG **riche**
   (`.html → FirefoxHTML`) appliquée au logon ; (b) composer une asso **générique custom**
   (`.clclcc → Applications\<exe>`) → auto-enregistrement per-user + double-clic ouvre
   l'app ; (c) prédictif **« indisponible »** (paquet WPKG non déployé) ; (d) garde-fou
   **exe manquant** (composition refusée). Check-list QA : `docs/qa/domains/agent.md`
   section « Story 27.11 ».

### File List

**Migrations / seeders / factory :**
- `database/migrations/2026_06_18_120000_add_executable_to_applications.php` (créé)
- `database/migrations/2026_06_18_120100_create_native_applications_table.php` (créé)
- `database/seeders/NativeApplicationSeeder.php` (créé)
- `database/seeders/DatabaseSeeder.php` (modifié — câble `NativeApplicationSeeder`)
- `database/factories/NativeApplicationFactory.php` (créé)

**Modèles :**
- `app/Models/NativeApplication.php` (créé)
- `app/Models/Application.php` (modifié — `executable` au `$fillable` + docblock)

**Service resolver :**
- `app/Services/Agent/Resolvers/AssociationResolver.php` (créé)
- `app/Services/Agent/Resolvers/ResolvedAssociation.php` (créé)

**UI / Livewire :**
- `resources/views/pages/parc/groups/_partials/associations-tab.blade.php` (refonte V2 composer)

**Agent Go :**
- `agent/shared/handler_associations.go` (modifié — interface `RegisterApplicationProgID`,
  helpers `isGenericApplication`/`applicationExe`, auto-enregistrement dans `Apply`)
- `agent/windows/handler_associations_windows.go` (modifié — `RegisterApplicationProgID`,
  `resolveExecutablePath`, raffinement `ProgIDRegistered`/`progIDCommandRegistered`)
- `agent/shared/handler_associations_test.go` (modifié — vecteur AC1 + tests AC6)

**Tests PHP :**
- `tests/Unit/Services/Agent/Resolvers/AssociationResolverTest.php` (créé)
- `tests/Feature/Livewire/ParcSettings/FileAssociationsPageTest.php` (réécrit V2 composer)
- `tests/Unit/Seeders/NativeApplicationSeederTest.php` (créé)
- `tests/Unit/Migrations/Story2711MigrationsTest.php` (créé)

**Documentation / QA :**
- `docs/agent/contract-v1.md` (modifié — note `progid` peut être `Applications\<exe>`)
- `docs/agent/state-providers.md` (modifié — composer + resolver riche/générique + AC6)
- `docs/qa/domains/agent.md` (modifié — section append-only « Story 27.11 »)

**Suivi sprint / story :**
- `_bmad-output/implementation-artifacts/sprint-status.yaml` (entrée `27-11-…` = review)
- `_bmad-output/implementation-artifacts/27-11-associations-composer-extension-app.md` (cette story)

## Change Log

- **2026-06-18 — DEV (claude-opus-4-8[1m])** : implémentation T2→T8 du composer
  d'associations V2. Donnée (`applications.executable` + table `native_applications`
  curée), service `AssociationResolver` PG-pur (riche/générique/native), refonte UI
  composer (saisie + dropdown app par nom + liste éditable/désactivable + prédictif +
  garde-fou exe), agent Go (auto-enregistrement per-user `Applications\<exe>` + raffinement
  `ProgIDRegistered` cas générique), tests PHP+Go, doc contrat/providers/QA. Preuve
  d'invariance AC7 (diff aval vide). Status ready-for-dev → review. Reste actions humaines
  Henri (T9 : /vm migrate --force + validation lab Windows).

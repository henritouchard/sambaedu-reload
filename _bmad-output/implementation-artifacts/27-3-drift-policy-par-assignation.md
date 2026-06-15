# Story 27.3 : Drift policy par assignation — le mode strict/default suit la cible, pas la règle

Status: review

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

> **⚠️ RENUMÉROTATION — lire en premier.** Dans `epics-agent-desired-state.md`, le slot « 27.3 » décrit
> *« Handlers registre & associations »*. **Cette story RÉAFFECTE le numéro 27-3** à un sujet différent
> (déplacement du `mode` desired-state de la règle vers l'assignation), à la demande d'Henri. Les handlers
> registre/associations restent à planifier sous un autre numéro. La présente story ne dépend PAS du sujet
> registre/associations ; elle **révise une décision de 27.1** (voir « Contexte »).

## ✅ DÉCISIONS HENRI — TRANCHÉES (2026-06-16)

> Les 3 questions ouvertes de cette story sont tranchées. **Procéder sans re-demander.**
> 1. **Option A** (recommandée) : déplacer le `mode` `règle → pivot` **uniquement pour `shortcuts`** ;
>    `wallpapers`/`overlays` gardent le mode sur leur table (déjà « par cible »), seul le toggle UI se
>    rapproche du geste d'assignation. **PAS d'Option B** (pas de pivots wallpaper/overlay).
> 2. **Toggle au lot** : un seul toggle pour toutes les cibles confirmées dans la modale d'assignation
>    raccourcis (le pivot supporte un mode par lien, mais l'ergonomie pose un mode au lot ; affinage par
>    cible ultérieur via la liste des assignations si besoin terrain).
> 3. **Pas de back-fill** : zéro prod → assignations existantes `mode = null` → défaut `strict` résolu
>    provider. OK confirmé.

## Story

En tant qu'**admin d'établissement**,
je veux **choisir, au moment où j'assigne une règle (raccourci / fond d'écran / overlay) à un poste ou un
parc, si l'utilisateur a le droit de la modifier (mode `default`) ou non (mode `strict`)**,
afin que **la politique de dérive (drift) soit décidée PAR CIBLE — un même raccourci peut être verrouillé
sur un parc et modifiable sur un autre — au lieu d'un mode global figé sur la règle**.

## Contexte & intention

**Cette story RÉVISE une décision de la Story 27.1.** En 27.1, Henri avait tranché (DÉCISION HENRI n° 2,
`27-1-handler-raccourcis-convergence-bureau.md` ~L160-168) que le mode `strict|default` serait **par RÈGLE** :
colonne `mode VARCHAR(16) nullable` ajoutée aux **3 tables de règle** `shortcuts`, `wallpapers`,
`overlay_signals` (migrations `2026_06_15_1000xx`), lue par les 3 providers, agrégée par type au compilateur,
toggle UI sur les 3 formulaires d'édition de règle.

**Henri révise ce choix.** Le besoin métier réel : autoriser/interdire le drift utilisateur **DIFFÉREMMENT
selon la cible d'assignation**. Le même raccourci « Pronote » peut être `strict` (verrouillé) sur le parc
« Salle informatique » et `default` (modifiable) sur le parc « Salles de cours ». Un `mode` posé sur la
règle ne sait pas faire cette distinction : il s'applique à toutes les cibles de la règle.

**Ce que cette story livre :**
- **Schéma** : le `mode` **quitte** les 3 tables de règle et **rejoint** la table/relation d'**assignation**
  de chaque type. Concrètement, le mode devient un attribut **du lien (règle ↔ cible)**, pas de la règle.
- **Providers serveur** : les 3 providers (`Shortcuts`/`Wallpaper`/`Overlay`StateProvider) lisent désormais
  le mode **depuis la jointure d'assignation** (par candidat/maille), au lieu de la table de règle. Défaut
  `strict` (ou défaut du type) conservé si l'assignation ne déclare pas de mode (`null`).
- **Compilateur** : `StateCandidate` porte toujours `?StateMode $mode` (inchangé) ; `StateCompiler::aggregateMode()`
  agrège **fonctionnellement à l'identique** (tous `default` → type `default`, sinon `strict` = posture sûre).
  Seule la PROVENANCE du mode change (assignation, pas règle).
- **UI** : le toggle « autoriser l'utilisateur à modifier ce paramètre » est exposé **à l'ASSIGNATION**
  (par maille), et **retiré** des formulaires d'édition de la règle.
- **Golden / contrat agent INTACTS** : le payload agent v1 NE porte PAS le mode (il est résolu au
  compilateur). Le golden `state.v1.json`/`report.v1.json` et le hash figé `ContractV1Test`/`hasher_test.go`
  ne doivent PAS bouger — c'est un OBJECTIF de la story.

**Ce que cette story N'EST PAS :**
- Une nouvelle exposition du mode au payload agent (le contrat reste « mode résolu serveur, jamais émis »).
- Le décommissionnement du canal legacy (27.6) — ZÉRO retrofit legacy, comme 27.1/27.2.
- Un changement de la sémantique de drift (`drifted_allowed` etc.) — la machine d'états §5 (`engine.go`)
  et l'agrégation par type sont réutilisées telles quelles.
- Les handlers registre/associations (slot epics d'origine du 27.3, hors-scope ici).

**Zéro prod (mémoire `zero_prod_publish_is_test`)** : il n'y a AUCUNE donnée de production à préserver. Les
3 colonnes `mode` de règle peuvent être **droppées proprement** (down symétrique), sans phase de
double-écriture ni migration de données.

## ⚠️ Piège STRUCTUREL n° 1 — les 3 types n'ont PAS la même structure d'assignation (lire AVANT de coder)

> **C'est la tension centrale de la story. Le titre « déplacer `mode` vers la table pivot » présuppose une
> table pivot pour les 3 types. Or UN SEUL type en a une.** Vérifié par exploration du codebase.

| Type | Où vit l'assignation aujourd'hui | « Pivot » ? | Conséquence pour le `mode` |
|------|----------------------------------|-------------|----------------------------|
| **shortcuts** | Pivot polymorphe **`shortcut_assignables`** (`shortcut_id`, `assignable_id`, `assignable_type` ∈ WorkstationGroup/Workstation/UserGroup/User ; UNIQUE `shortcut_assignable_unique`) | **OUI** (vraie table pivot, N cibles par règle) | Ajouter colonne `mode` sur **`shortcut_assignables`** = le déplacement « par assignation » canonique. |
| **wallpapers** | Colonnes **`owner_type`/`owner_id` SUR la table `wallpapers`** (`nullableMorphs('owner')`, UNIQUE `(type, owner_type, owner_id)`) — **1 wallpaper = 1 cible** (la règle EST l'assignation) | **NON** (pas de pivot ; relation `owner()` MorphTo) | « Par assignation » ≡ « par ligne `wallpapers` » : le `mode` **reste sur `wallpapers`** mais sa sémantique devient « mode de CETTE assignation ». **Aucune colonne à déplacer** — il y est déjà. |
| **overlays** | Colonnes **`workstation_uuid`/`workstation_group_id`/`user_login` SUR `overlay_signals`** — **1 signal = 1 cible** (le signal EST l'assignation) | **NON** (pas de pivot ; ciblage colonne directe) | Idem wallpapers : le `mode` **reste sur `overlay_signals`** = déjà « par assignation » de fait. **Aucune colonne à déplacer.** |

[Source: app/Models/Shortcut.php:152-175 (relations morph), database/migrations/2026_02_09_173400_create_shortcut_assignables_table.php:24-30]
[Source: app/Models/Wallpaper.php:59-62 (owner MorphTo), database/migrations/2026_04_20_100000_create_wallpapers_table.php:18,23]
[Source: app/Models/OverlaySignal.php:39-41, database/migrations/2026_06_09_130000_create_overlay_signals_table.php]

### Lecture métier de ce piège

- **Pour `wallpapers` et `overlays`, la règle EST déjà l'assignation.** Un `Wallpaper` cible un seul owner ;
  un `OverlaySignal` cible un seul triplet (poste/groupe/user). Le `mode` posé sur leur ligne est DÉJÀ
  « par assignation » au sens d'Henri — il n'y a structurellement **qu'une** assignation par règle. **Donc :
  on ne déplace rien pour ces 2 types ; on déplace seulement la position du toggle UI** (du formulaire
  d'édition vers le geste d'assignation/picker) et on documente que le `mode` est « par cible ».
- **Seul `shortcuts`** a une vraie relation N-à-M (une règle, plusieurs cibles, mode potentiellement
  différent par cible). C'est le SEUL type où le déplacement de colonne `règle → pivot` a un effet
  fonctionnel : le `mode` passe de `shortcuts.mode` à `shortcut_assignables.mode`.

### Décision par défaut proposée (à confirmer Henri — Question n° 1)

> **Option A (recommandée — minimale & honnête)** : ne dropper et déplacer la colonne QUE pour `shortcuts`
> (`shortcuts.mode` → `shortcut_assignables.mode`). Pour `wallpapers`/`overlays`, **conserver** `mode` sur
> leur table (= déjà par assignation) et se contenter de **déplacer le toggle UI** vers le geste
> d'assignation + clarifier le libellé (« autoriser l'utilisateur à modifier sur cette cible »). Aucune
> colonne droppée pour ces 2 types.
>
> **Option B (uniformité de schéma forcée)** : créer des tables pivot `wallpaper_assignables` /
> `overlay_assignables` pour aligner les 3 sur le modèle `shortcut_assignables`, et y déplacer le `mode`.
> **Lourd** : refonte du modèle de ciblage wallpaper (owner direct → pivot) et overlay (colonnes → pivot),
> migration de l'UI picker/formulaire vers une UI d'assignation multi-cibles, impact provider majeur. Cela
> dépasse « drift policy » et touche le modèle de ciblage — hors intention métier d'Henri.
>
> **Le périmètre d'AC ci-dessous est rédigé pour l'Option A.** Si Henri tranche Option B, replanifier (la
> story change de nature : refonte ciblage, pas drift policy).

## ⚠️ Autres pièges & tensions (lire avant de coder)

1. **Le golden et le contrat agent NE doivent PAS bouger.** Le payload v1 (state.v1.json) n'a JAMAIS porté
   le `mode` (résolu au compilateur, cf. `docs/agent/contract-v1.md`). Cette story ne touche PAS le payload,
   donc `FROZEN_STATE_HASH` (PHP `ContractV1Test`) et `frozenStateHash` (Go `hasher_test.go`) restent
   **identiques**. Un bump de hash dans cette story = signal d'une régression (le mode aurait fuité au
   payload). **Vérification explicite en AC6.**

2. **L'agrégation au compilateur reste fonctionnellement inchangée.** `StateCompiler::aggregateMode()`
   (`app/Services/Agent/StateCompiler.php:171-182`) scanne les candidats SÉLECTIONNÉS (post-D2) et rend
   `Strict` dès qu'un candidat est strict, `Default` si tous default. La logique ne change PAS : seul le
   `StateCandidate::$mode` change de SOURCE (lu depuis l'assignation, plus depuis la règle). Le candidat
   continue de porter `?StateMode $mode` (`StateCandidate.php:41`). **Ne pas réécrire l'agrégation.**

3. **Provider shortcuts : le `mode` se lit par maille (par ligne de pivot).** Aujourd'hui le provider
   sélectionne `shortcuts.mode` (`ShortcutsStateProvider.php:129`) — une valeur par règle. Demain il doit
   sélectionner `shortcut_assignables.mode` — une valeur **par lien (règle, cible)**. Comme la requête fait
   déjà 4 sous-requêtes morph par `assignable_type` (`ShortcutsStateProvider.php:105-122`), le mode de
   l'assignation correspondante doit être projeté dans chaque branche. ⚠️ Attention : une même règle ciblant
   plusieurs mailles produit plusieurs candidats — chacun porte le mode de SON assignation. La dédup
   aggregate au compilateur (par contenu de payload, PAS par mode — `selectAggregate()` ~L206-225) garde le
   premier par `sourceId` asc : si deux assignations du même raccourci ont des modes différents,
   `aggregateMode()` voit néanmoins les DEUX candidats (l'agrégation se fait sur les candidats sélectionnés)
   → posture sûre `strict` si l'un des deux est strict. **Vérifier ce comportement en test** (mix strict+default
   sur le même raccourci via 2 mailles → type strict).

4. **Défaut `strict` résolu côté provider, jamais de DEFAULT SQL.** La colonne `mode` reste **nullable**,
   `null` = « non déclaré » → le compilateur retombe sur `StateProvider::mode()` (défaut du type :
   shortcuts=Strict, wallpaper=Default, overlay=Strict). Reproduire EXACTEMENT le pattern des migrations
   `2026_06_15_1000xx` : `Schema::hasColumn`, `string('mode', 16)->nullable()->comment(...)`, `down()`
   symétrique. **Pas de `->default()`.**

5. **Migrations idempotentes & datées (mémoire `idempotency`).** Le sens du flux :
   - **DROP** `mode` de `shortcuts` (Option A) — `Schema::hasColumn` avant `dropColumn`, down symétrique
     qui RE-CRÉE la colonne nullable (pour réversibilité).
   - **ADD** `mode` sur `shortcut_assignables` — `Schema::hasColumn` avant add, down qui drop.
   - **wallpapers / overlay_signals** (Option A) : **NE PAS toucher** les colonnes (le mode y reste). Si
     Henri choisit néanmoins de les retirer aussi (uniformité), prévoir 2 migrations drop symétriques —
     mais alors où vit leur mode ? (cf. Question n° 1 / Option B).

6. **VM migrations PAS auto-jouées (mémoire `vm_migrations_not_auto_applied`).** Le dev-cycle migre en
   SQLite only. La story DOIT lister l'action e2e `/vm` : `migrate:status` → `php artisan migrate --force` →
   (pas de `config:cache`/`route:cache` attendu, aucun `config/*.php` ni route ajoutés ; les mentionner
   seulement SI un fichier de config/route change, ce qui n'est pas prévu) → chown `www-admin` non requis
   pour une simple migration de schéma.

7. **Tests SQLite n'appliquent pas varchar/contraintes PG (mémoire `sqlite_tests_no_varchar_enforcement`).**
   La couverture critique est **fonctionnelle** : un test provider qui prouve que le mode est lu depuis
   l'assignation (et plus depuis la règle), défaut quand null, et l'agrégation strict/default sur un mix.
   Ne pas compter sur SQLite pour valider le `VARCHAR(16)`.

8. **routes/api.php — a priori aucune route ajoutée.** Si (contre toute attente) une route est ajoutée,
   l'insérer **APRÈS** le groupe 16.12 (mémoire `api_routes_arch_test_window_trap`). Non attendu ici.

9. **inotify ne propage pas les deletes (mémoire `inotify_no_delete_sync`).** Cette story ne supprime aucun
   fichier (elle édite migrations/models/providers/UI). Sans objet.

10. **NFR7 — zéro AD/APCu/LdapRecord dans les providers.** Reconduit de 27.1 : grep `ldap|apcu|samba-tool`
    sur les 3 providers doit rester vide. La lecture du mode passe par les relations Postgres (pivot/owner/colonne).

## Acceptance Criteria

### AC1 — Le `mode` quitte la règle pour l'assignation (shortcuts) ; schéma idempotent + réversible (FR26)

**Given** la décision révisée d'Henri (drift policy PAR ASSIGNATION, plus par règle)
**When** les migrations sont jouées
**Then** la colonne `mode VARCHAR(16) nullable` est **ajoutée sur `shortcut_assignables`** (défaut résolu
côté provider, **pas de DEFAULT SQL**) et **retirée de `shortcuts`** ; les migrations sont idempotentes
(`Schema::hasColumn`), `down()` symétrique, `->comment()` daté story 27.3, style calqué sur
`2026_06_15_100000_add_mode_to_shortcuts.php`
**And** pour **`wallpapers`** et **`overlay_signals`** (structure « règle = assignation », piège n° 1), le
`mode` **reste** sur leur table — aucune colonne droppée (Option A) — et c'est documenté comme « mode par
cible » (Question Henri n° 1 ; si Option B tranchée → replanifier).

### AC2 — Modèles nettoyés (shortcuts) ; pivot porteur du mode (FR26)

**Given** le déplacement schéma
**When** on inspecte les modèles
**Then** `Shortcut` ne porte plus `mode` dans `$fillable`/`$casts`/`@property` (il vit sur le pivot) ; le
`mode` du lien est accessible via `pivot->mode` (cast `StateMode` sur la relation morph, `withPivot('mode')`)
**And** `Wallpaper` et `OverlaySignal` conservent `mode` (Option A — déjà par assignation), inchangés.

### AC3 — Les providers lisent le mode depuis l'assignation, défaut conservé (FR21, NFR7)

**Given** une règle assignée à plusieurs mailles avec des modes potentiellement différents
**When** `ShortcutsStateProvider::itemsFor()` produit ses candidats
**Then** chaque `StateCandidate` porte le `mode` de **SON assignation** (`shortcut_assignables.mode`), plus
`shortcuts.mode` ; `null` → défaut du type via `StateProvider::mode()` (Strict)
**And** `WallpaperStateProvider`/`OverlayStateProvider` lisent leur `mode` depuis leur table (inchangé,
Option A) — leur défaut est préservé (wallpaper=Default, overlay=Strict)
**And** grep `ldap|apcu|samba-tool` sur les 3 providers → **vide** (NFR7).

### AC4 — Agrégation au compilateur inchangée fonctionnellement (FR5)

**Given** un type aggregate dont les candidats mixent `strict` et `default`
**When** le `StateCompiler` compile
**Then** `aggregateMode()` rend `strict` dès qu'un candidat retenu est strict, `default` si tous sont default
(posture sûre **inchangée**) ; `StateCandidate` continue de porter `?StateMode $mode`
**And** la machine d'états §5 (`engine.go::ResolveItemStatus`) et la sémantique `drifted_allowed` sont
**réutilisées telles quelles** (zéro modification agent).

### AC5 — UI : le toggle vit à l'ASSIGNATION, retiré de l'édition de règle (FR26, FR19)

**Given** l'admin assigne un raccourci à un poste/parc/groupe via la modale d'assignation
**When** il sélectionne les cibles
**Then** un toggle « autoriser l'utilisateur à modifier ce paramètre » (= `default`) / « verrouillé »
(= `strict`) est exposé **dans le geste d'assignation** (modale `shortcut-assignment-modal`, Livewire SFC,
`WithToasts`, Gate cohérent avec l'attach/detach existant) et **persisté sur le lien** (`pivot.mode` via
`syncWithoutDetaching([id => ['mode' => …]])`)
**And** le toggle est **retiré** du formulaire d'édition de la règle raccourci
(`shortcuts/[id]/_partials/shortcut-form.blade.php` + propriété/validation/save dans
`shortcuts/[id]/index.blade.php`)
**And** pour wallpaper/overlay (Option A) : le toggle est rapproché du geste d'assignation (picker wallpaper /
formulaire de création overlay = déjà le geste d'assignation de fait), libellé clarifié « par cible » ; pas
de régression de persistance.

### AC6 — Contrat agent & golden files NON impactés (NFR13)

**Given** que le `mode` n'a jamais été émis au payload agent (résolu serveur)
**When** les tests croisés tournent
**Then** `tests/Fixtures/Agent/state.v1.json` et `report.v1.json` sont **byte-identiques** (aucun diff) ;
`ContractV1Test::FROZEN_STATE_HASH` (PHP) et `frozenStateHash` (Go `hasher_test.go`) restent **inchangés**
**And** aucun fichier sous `agent/` n'est modifié (le déplacement est purement serveur+schéma+UI).

### AC7 — Tests : providers (mode depuis assignation, défaut, mix) + UI/feature + non-régression (NFR13)

**Then** côté **Laravel** :
- `ShortcutsStateProviderTest` : (a) mode lu depuis `shortcut_assignables.mode` par maille ; (b) défaut
  `strict` quand `null` ; (c) une règle sur 2 mailles avec modes différents → 2 candidats portant chacun
  leur mode ; (d) lecture seule, zéro AD
- test compilateur : mix strict+default sur le même type → `strict` agrégé (posture sûre inchangée)
- test UI/feature : l'assignation persiste `pivot.mode` (toggle modale) ; le formulaire de règle ne porte
  plus le champ `mode` (régression de retrait vérifiée)
- non-régression `--filter Agent` sur `/vm` (baseline à relever au début du dev — `ContractV1Test` doit
  rester vert SANS bump de hash)

**And** côté **agent Go** : `go test ./...` + `go vet` (linux + `GOOS=windows`) + cross-compile **verts et
INCHANGÉS** (aucun fichier `agent/` modifié) — preuve que le contrat est intact.

### AC8 — Documentation + QA (append-only)

**Then** `docs/agent/state-providers.md` : la section `shortcuts` (et la note mode des sections
wallpaper/overlay) reflète « mode PAR ASSIGNATION » (plus « par règle ») ; clarifier la provenance du mode
**And** `docs/qa/domains/agent.md` enrichi **append-only** (nouvelle section `## Story 27.3` sans
renuméroter) : toggle drift par cible, mode différent par parc, défaut strict, golden intact ; ligne 27.3
dans `docs/qa/README.md`
**And** la décision révisée est tracée (le `mode` est désormais par assignation ; 27.1 portait le mode par
règle — note de révision).

## Tasks / Subtasks

- [x] **T0 — Option A + toggle au lot + pas de back-fill : TRANCHÉ par Henri (2026-06-16)** (piège n° 1)
  - [x] **Option A** confirmée : déplacer le mode pour `shortcuts` seulement ; `wallpapers`/`overlays`
        gardent le mode sur leur table (déjà par assignation), seul le toggle UI bouge. **Pas d'Option B.**
  - [x] **Toggle au lot** dans la modale d'assignation shortcuts.
  - [x] **Pas de back-fill** : `mode = null` post-migration → défaut `strict` provider.

- [x] **T1 — Migrations : déplacer `mode` (shortcuts règle → pivot)** (AC1) — *Option A*
  - [x] `database/migrations/2026_06_16_HHMMSS_add_mode_to_shortcut_assignables.php` : colonne
        `string('mode', 16)->nullable()->comment('Mode d'application PAR ASSIGNATION (Story 27.3) — strict/default ; null = non déclaré, défaut strict résolu côté provider')`,
        `Schema::hasColumn('shortcut_assignables','mode')` en garde, `down()` qui `dropColumn`.
  - [x] `database/migrations/2026_06_16_HHMMSS_drop_mode_from_shortcuts.php` : `dropColumn('mode')` gardé
        par `Schema::hasColumn`, `down()` qui RE-CRÉE `string('mode',16)->nullable()` (réversibilité).
  - [x] **NE PAS** toucher `wallpapers.mode` ni `overlay_signals.mode` (Option A).
  - [x] Style calqué EXACTEMENT sur `2026_06_15_100000_add_mode_to_shortcuts.php` (varchar nullable,
        `Schema::hasColumn`, comment daté, compat SQLite).

- [x] **T2 — Modèles** (AC2)
  - [x] `app/Models/Shortcut.php` : retirer `'mode'` de `$fillable` (L89), `'mode' => StateMode::class` de
        `$casts` (L107), le `@property mode` (L45). Sur les relations `workstationGroups()` (L152-161) et
        `workstations()` (L166-175), ajouter `->withPivot('mode')` (+ `->using(...)` ou cast pivot
        `StateMode` si un modèle pivot dédié est introduit — challenger : `withPivot` + accès `pivot->mode`
        suffit, cast manuel si besoin). Étendre aux branches UserGroup/User que le provider lit en SQL.
  - [x] `app/Models/Wallpaper.php` / `app/Models/OverlaySignal.php` : **inchangés** (Option A — mode déjà
        par assignation). Vérifier que rien d'autre ne casse.

- [x] **T3 — Provider shortcuts : lire le mode depuis l'assignation** (AC3) — *cœur serveur*
  - [x] `app/Services/Agent/Providers/ShortcutsStateProvider.php` : remplacer la sélection `shortcuts.mode`
        (L129) par `shortcut_assignables.mode` ; projeter le mode de l'assignation correspondante dans
        chacune des 4 sous-requêtes morph (L105-122). Le `StateCandidate::$mode` reçoit le mode du LIEN.
  - [x] Conserver `mode()` (défaut du type = `StateMode::Strict`, L73-78) ; null d'assignation → défaut.
  - [x] Vérifier qu'une règle multi-mailles produit bien un candidat PAR assignation, chacun avec son mode.
  - [x] `WallpaperStateProvider` / `OverlayStateProvider` : **inchangés** (Option A), mais relire pour
        confirmer la non-régression (ils lisent déjà leur `mode` de table = par assignation).

- [x] **T4 — Compilateur : vérifier l'agrégation inchangée** (AC4)
  - [x] Confirmer que `StateCompiler::aggregateMode()` (L171-182) et `selectAggregate()` (L206-225) n'ont
        **pas besoin de modification** : ils opèrent déjà sur `StateCandidate::$mode` sans connaître sa
        source. Aucune réécriture. Ajouter/ajuster un test pour le mix strict+default depuis 2 assignations.

- [x] **T5 — UI : toggle à l'assignation, retiré de l'édition de règle** (AC5)
  - [x] `resources/views/components/organisms/shortcut-assignment-modal.blade.php` : ajouter, dans le geste
        d'assignation (par cible sélectionnée ou un mode appliqué au lot confirmé), le toggle « autoriser
        l'utilisateur à modifier » (= `default`) / « verrouillé » (= `strict`). Dispatcher le mode avec
        l'événement `shortcut-assignments-confirmed`.
  - [x] `resources/views/pages/shortcuts/[id]/index.blade.php` : dans `onAssignmentsConfirmed()` (L237-282),
        persister le mode sur le pivot — `syncWithoutDetaching([$id => ['mode' => $mode]])` pour
        workstationGroups/workstations (+ branches UserGroup/User selon le câblage SQL). **Retirer** la
        propriété `$mode` (L44), le load (L87), la validation `in:strict,default` (L119), le save (L133).
  - [x] `resources/views/pages/shortcuts/[id]/_partials/shortcut-form.blade.php` : **retirer** le select
        mode (L113-130).
  - [x] Wallpaper (`wallpaper-card.blade.php` `setMode` L218-231, toggle L309-334) et overlay
        (`overlay-messages/index.blade.php` mode L33/L79/L91-97) : rapprocher du geste d'assignation,
        clarifier le libellé « par cible » ; conserver la persistance (Option A = mode sur leur table).
        Si l'UI actuelle est déjà « au moment de l'assignation » (picker wallpaper, création overlay),
        se limiter au libellé.
  - [x] Gate/permission iso-existant ; `WithToasts` ; modale réutilisable (CLAUDE.md projet).

- [x] **T6 — Tests** (AC7)
  - [x] PHPUnit : `ShortcutsStateProviderTest` (mode depuis pivot, défaut null→strict, multi-mailles modes
        différents, lecture seule zéro AD) ; `StateCompilerTest` (mix strict+default → strict agrégé) ;
        feature UI assignation (`pivot.mode` persisté) + retrait du champ mode du form règle.
  - [x] **Vérifier `ContractV1Test` reste vert SANS bump de hash** (AC6 — preuve contrat intact).
  - [x] Go : `go test ./...` + `go vet` (linux+windows) + cross-compile — **inchangés, verts** (aucun
        fichier `agent/` touché).

- [x] **T7 — Golden / contrat : prouver l'absence d'impact** (AC6)
  - [x] `git diff tests/Fixtures/Agent/state.v1.json report.v1.json` → **vide**.
  - [x] `FROZEN_STATE_HASH` / `frozenStateHash` → **inchangés**. Si l'un bouge : régression (mode a fuité
        au payload) — corriger avant de finaliser.

- [x] **T8 — Documentation + QA** (AC8)
  - [x] `docs/agent/state-providers.md` : mode « par assignation » (section shortcuts + note wallpaper/overlay).
  - [x] `docs/qa/domains/agent.md` append-only `## Story 27.3` ; ligne 27.3 `docs/qa/README.md`. Note de
        révision (27.1 portait le mode par règle → 27.3 par assignation).

- [x] **T9 — Validation finale** (AC6, AC7)
  - [x] `php -l` sur les PHP touchés ; grep NFR7 (`ldap|apcu|samba-tool`) sur les 3 providers → vide ;
        grep « zéro retrofit legacy » (aucun fichier du canal legacy raccourcis dans le diff).
  - [x] `go test ./...`/vet/cross-compile verts (et inchangés). `--filter Agent` /vm sans régression,
        `ContractV1Test` vert sans bump.
  - [x] **Actions /vm (PAS auto)** : `migrate:status` → `php artisan migrate --force` (2 migrations :
        add pivot + drop règle, Option A). Pas de `config:cache`/`route:cache` (aucun config/route ajouté).
  - [ ] **Validation lab (poste Windows) — ACTION HUMAINE (Henri)** : un même raccourci `strict` sur un
        parc et `default` sur un autre ; en `default` une suppression par un prof n'est pas recréée
        (`drifted_allowed`) ; en `strict` elle est recréée.

## Dev Notes

### Périmètre — livré / hors-scope (Option A)

| Livré (27.3) | Hors-scope (story) |
|---|---|
| `mode` déplacé `shortcuts` → `shortcut_assignables` (migrations idempotentes + down réversible) | Pivots `wallpaper_assignables`/`overlay_assignables` (Option B — refonte ciblage, replanifier) |
| Provider shortcuts lit le mode depuis l'assignation (par maille) | Décommissionnement canal legacy → 27.6 |
| Toggle UI déplacé vers le geste d'assignation (shortcuts) + retiré du form règle | Handlers registre/associations (slot epics d'origine du 27.3) |
| Libellé/position UI clarifiés pour wallpaper/overlay (mode déjà par cible) | Toute modification du payload agent / contrat / golden (objectif = INTACT) |
| Compilateur vérifié inchangé ; contrat agent prouvé intact | Changement de sémantique drift / machine d'états §5 (réutilisée) |
| Tests provider + UI + non-régression ; QA append-only | Ciblage par CN AD (`ad_users`/`ad_user_groups`) — exclu NFR7 (iso 27.1) |

### Ce qu'on RÉVISE de 27.1 (ne pas re-trancher la sémantique, juste la provenance)

[Source: _bmad-output/implementation-artifacts/27-1-handler-raccourcis-convergence-bureau.md L160-168, L524]

- 27.1 a posé `mode` sur les 3 tables de règle + cast `StateMode` + `StateCandidate::$mode` + agrégation
  par type + toggle UI sur les 3 formulaires. **C'est l'infra qu'on réutilise.**
- 27.3 change UNIQUEMENT : (a) la TABLE qui porte le mode pour shortcuts (règle → pivot), (b) la POSITION
  du toggle UI (édition de règle → geste d'assignation). **L'agrégation, le contrat, le golden, la machine
  d'états restent.**

### Le pattern 23.4 / 27.1 — ce qu'on consomme (ne PAS réinventer)

[Source: app/Services/Agent/StateCandidate.php:29-42 ; Contracts/StateProvider.php:38-59 ; StateCompiler.php:171-182,206-225 ; TargetContext.php:34-68 ; Enums/StateMode.php:20-24 ; Enums/StateMaille.php:19-26]

- `StateCandidate` (readonly) porte déjà `?StateMode $mode` — **ne pas changer la signature**, juste
  changer ce qu'on y injecte (mode de l'assignation).
- `StateProvider::mode()` = défaut du type (Strict/Default/Strict) — conservé.
- `StateCompiler::aggregateMode()` agrège sur les candidats sélectionnés (post-D2) ; `selectAggregate()`
  dédup par contenu de payload (pas par mode). **Aucune réécriture nécessaire.**
- `TargetContext` : `physicalGroupIds`/`logicalGroupIds`/`userGroupIds`/`workstationGroupIds()`/`user` — les
  providers consomment ces ids, ne re-requêtent jamais les appartenances.

### Structures d'assignation (rappel piège n° 1)

[Source: database/migrations/2026_02_09_173400_create_shortcut_assignables_table.php ; app/Models/Shortcut.php:152-175]
[Source: database/migrations/2026_04_20_100000_create_wallpapers_table.php:18,23 ; app/Models/Wallpaper.php:59-62]
[Source: database/migrations/2026_06_09_130000_create_overlay_signals_table.php ; app/Models/OverlaySignal.php:39-41]

- **shortcut_assignables** : vraie table pivot (`shortcut_id`, `assignable_id`, `assignable_type`, UNIQUE).
  C'est ICI que `mode` doit aller (par lien règle↔cible). Pas de modèle pivot Eloquent dédié aujourd'hui ;
  `withPivot('mode')` + accès `pivot->mode` (cast manuel `StateMode::from($pivot->mode)` si besoin, ou
  modèle pivot avec `$casts`).
- **wallpapers** : `owner_type`/`owner_id` sur la table, UNIQUE `(type, owner)` → 1 cible/règle. Mode déjà
  par assignation.
- **overlay_signals** : `workstation_uuid`/`workstation_group_id`/`user_login` sur la table → 1 cible/signal.
  Mode déjà par assignation.

### Migrations existantes à inverser (référence de style)

[Source: database/migrations/2026_06_15_100000_add_mode_to_shortcuts.php (+ 100100_wallpapers, 100200_overlay_signals)]

- Pattern à reproduire : `Schema::hasColumn` en garde, `string('mode',16)->nullable()->comment(...)`,
  `down()` symétrique. La migration `drop_mode_from_shortcuts` est la SYMÉTRIE de `add_mode_to_shortcuts`.
- **Mémoire `vm_migrations_not_auto_applied`** : `migrate:status` puis `migrate --force` sur /vm avant e2e.

### UI d'assignation — où vit le geste

[Source: resources/views/components/organisms/shortcut-assignment-modal.blade.php ; resources/views/pages/shortcuts/[id]/index.blade.php:237-282 ; _partials/assigned-groups.blade.php]

- **Shortcuts** : modale Livewire event-driven (`open-shortcut-assignment-modal` →
  `shortcut-assignments-confirmed`) ; persistance `syncWithoutDetaching()` dans `onAssignmentsConfirmed()`.
  C'est LE point d'insertion du toggle + du `pivot.mode`.
- **Wallpaper** : picker 1-à-1 (`wallpaper-library-picker.blade.php`) + carte (`wallpaper-card.blade.php`
  `setMode`) — le geste d'assignation = le choix d'asset pour un owner. Mode déjà sur la ligne.
- **Overlay** : création = assignation atomique (`overlay-messages/index.blade.php` `save()`). Mode déjà
  sur le signal.
- **Réutilisables** : trait `app/Components/Traits/WithToasts.php` ; modale
  `resources/views/components/molecules/modal/index.blade.php`.

### Environnement de dev — règles VM

- Code à la RACINE (app/, agent/, …) ; édité sur l'hôte, sync inotify auto, **jamais de sync manuelle**.
- **Go = hôte uniquement** (`~/go-toolchain/go/bin/go`, package main = `agent/windows`). PHPUnit sur /vm.
- Cette story **ne touche pas `agent/`** : `go test` doit rester vert et inchangé (preuve contrat intact).
- Migrations → **à jouer sur la VM** (`migrate:status` avant e2e). Jamais d'interaction VM depuis un worktree.

### Dépendances

| Story | Rôle pour 27.3 | Statut (sprint-status.yaml) | Bloquant ? |
|-------|----------------|------------------------------|------------|
| 27.1 — handler raccourcis + mode par règle | Porte le modèle ACTUEL (colonnes mode sur 3 tables, `StateCandidate::$mode`, agrégation, toggle UI). 27.3 le révise (règle → assignation). | `review` | **Prérequis fort** — en `review` ; dev autorisé avec **rebase si correctifs 27.1** (les fichiers touchés se recouvrent : migrations mode, provider shortcuts, StateCompiler, UI shortcuts). |
| 27.2 — handlers drives/printers | Voisin Epic 27 ; n'a PAS introduit de toggle mode (drives/printers). Pas d'impact direct. | `review` | Non. |
| 23.4 — StateCompiler/StateProvider/TargetContext | Pattern provider+compilateur+contexte réutilisé. | `done` | Non (consommé). |
| 24.6 — agent Go moteur §5 / AggregateHash | Machine d'états drift réutilisée telle quelle (zéro modif agent). | `done` | Non (consommé). |
| 23.1 — contrat v1 + golden + StateHasher | Golden/hash à PRÉSERVER (objectif). | `done` | Non. |

> **Recouvrement avec 27.1 (review)** : 27.3 réécrit la position du mode posée par 27.1. Si 27.1 reçoit des
> correctifs post-review qui touchent `ShortcutsStateProvider`/`StateCompiler`/migrations mode/UI shortcuts,
> **rebaser** avant de finaliser 27.3.

### References

- [Source: _bmad-output/planning-artifacts/epics-agent-desired-state.md#Epic 27 + Story 27.1 L698-699] — FR26 toggle strict/défaut « par règle » (révisé ici en « par assignation ») ; FR5 (mode ∈ strict|default dès v1, résolu serveur).
- [Source: _bmad-output/implementation-artifacts/27-1-handler-raccourcis-convergence-bureau.md L160-168, L524] — décision Henri « mode par règle » (RÉVISÉE) ; infra mode+cast+candidate+agrégation+UI posée.
- [Source: _bmad-output/implementation-artifacts/27-2-handlers-lecteurs-imprimantes.md] — voisin Epic 27, pas de toggle mode drives/printers.
- [Source: app/Services/Agent/StateCandidate.php:29-42] — `?StateMode $mode` (signature conservée).
- [Source: app/Services/Agent/StateCompiler.php:171-182,206-225] — `aggregateMode()` + `selectAggregate()` (inchangés).
- [Source: app/Services/Agent/Providers/ShortcutsStateProvider.php:73-78,105-129] — `mode()` défaut + jointure pivot + sélection `shortcuts.mode` (à pointer vers `shortcut_assignables.mode`).
- [Source: app/Models/Shortcut.php:45,89,107,115,152-175] — `mode` fillable/cast/property (à retirer) ; relations morph pivot (à `withPivot('mode')`).
- [Source: app/Models/Wallpaper.php:29,51,56,59-62 ; app/Models/OverlaySignal.php:27,43,48,39-41] — mode + ciblage direct (Option A : inchangés).
- [Source: database/migrations/2026_06_15_100000_add_mode_to_shortcuts.php (+100100,+100200)] — style migration à reproduire/inverser.
- [Source: database/migrations/2026_02_09_173400_create_shortcut_assignables_table.php:24-30] — pivot cible (ajout colonne mode).
- [Source: resources/views/components/organisms/shortcut-assignment-modal.blade.php ; resources/views/pages/shortcuts/[id]/index.blade.php:237-282] — point d'insertion du toggle + persistance pivot.
- [Source: resources/views/pages/shortcuts/[id]/_partials/shortcut-form.blade.php:113-130] — toggle mode du form règle (à retirer).
- [Source: app/Components/Traits/WithToasts.php ; resources/views/components/molecules/modal/index.blade.php] — toasts + modale réutilisables.
- [Source: docs/agent/state-providers.md ; docs/agent/contract-v1.md] — mode résolu serveur jamais émis ; section providers à mettre à jour.

## Questions pour Henri

1. **Option A vs B (piège structurel n° 1) — décision de design majeure.** Seul `shortcuts` a une vraie
   table pivot. Pour `wallpapers`/`overlays`, la règle EST déjà l'assignation (mode déjà « par cible »).
   - **Option A (recommandée)** : déplacer le mode `règle → pivot` UNIQUEMENT pour `shortcuts` ; conserver
     le mode sur la table pour wallpaper/overlay (déplacer seulement le toggle UI). Minimal, honnête.
   - **Option B** : créer des pivots `wallpaper_assignables`/`overlay_assignables` pour uniformiser — lourd
     (refonte du modèle de ciblage), dépasse « drift policy ». Si choisi → replanifier la story.
   **Le périmètre des AC est rédigé pour l'Option A.**

2. **Toggle au lot ou par cible (shortcuts) ?** Dans la modale d'assignation, le mode s'applique-t-il au
   LOT de cibles confirmées (un seul toggle pour la sélection) ou doit-on pouvoir poser un mode différent
   par cible dans le même geste ? (Le pivot supporte un mode par lien dans les deux cas ; c'est l'ergonomie
   UI qui diffère.) Par défaut : **un toggle au lot** (plus simple), affiné si besoin terrain.

3. **Mode des assignations existantes (zéro prod).** Comme il n'y a pas de prod, les assignations
   `shortcut_assignables` existantes auront `mode = null` après migration → défaut `strict` résolu provider.
   Confirmé OK (pas de back-fill `default`) ?

## Recommandation Modèle Dev

**Recommandation : `opus`.**

Justification : story **multi-fichiers et structurante malgré son apparence de simple déplacement de
colonne**. Elle touche (a) le schéma sur 3 domaines avec une **asymétrie structurelle non triviale** (un
seul des trois types a une vraie table pivot — piège n° 1 à arbitrer sans sur-ingénierie), (b) la **lecture
du mode par maille** dans le provider shortcuts (projeter `pivot.mode` dans 4 branches morph), (c) une
**logique d'agrégation** à vérifier inchangée (risque de régression silencieuse strict/default), (d) un
**objectif de non-impact contrat/golden** (un bump de hash = régression à détecter), et (e) une **migration
d'UI** du toggle vers le geste d'assignation avec persistance pivot. Le risque majeur — casser
l'agrégation du mode ou faire fuiter le mode au payload (golden) tout en gardant le contrat agent figé —
exige un raisonnement rigoureux. `opus`.

## Dev Agent Record

### Agent Model Used

claude-opus-4-8[1m] (DEV BMAD, Opus 4.8 1M).

### Implementation Plan / Completion Notes

Option A appliquée à la lettre (décisions Henri 2026-06-16). Le `mode` strict|default
passe de la RÈGLE à l'ASSIGNATION pour `shortcuts` UNIQUEMENT ; `wallpapers` et
`overlay_signals` gardent le mode sur leur table (déjà « par cible »), seul le toggle
UI / libellé bouge.

- **T1 — Migrations (2)** : `add_mode_to_shortcut_assignables` (varchar(16) nullable,
  `Schema::hasColumn` en garde, comment daté 27.3, PAS de default SQL) + symétrique
  `drop_mode_from_shortcuts` (down RE-CRÉE la colonne 27.1 pour réversibilité). Style
  calqué sur `2026_06_15_100000_add_mode_to_shortcuts.php`. Datées `2026_06_16_1101xx`
  pour s'ordonner après la migration 27.7 `2026_06_16_100000` déjà présente dans le
  tree. `wallpapers`/`overlay_signals` NON touchées.
- **T2 — Modèle `Shortcut`** : `'mode'` retiré de `$fillable`/`$casts`, `@property mode`
  retiré, import `StateMode` retiré (plus utilisé). `->withPivot('mode')` ajouté sur
  `workstationGroups()` et `workstations()` (les 2 seules relations morph du modèle ;
  UserGroup/User ne sont pas des relations Eloquent ici — le provider les lit en raw
  join, et l'UI les route via `ad_users`/`ad_user_groups`). Pas de modèle pivot dédié
  (cast manuel suffit, plus simple). `Wallpaper`/`OverlaySignal` inchangés.
- **T3 — `ShortcutsStateProvider`** : la sélection lit désormais
  `shortcut_assignables.mode as assignment_mode` (aliasé pour ne pas heurter une
  colonne modèle), casté manuellement via `StateMode::tryFrom()` (le cast a quitté le
  modèle). Une règle multi-mailles produit donc un candidat par assignation, chacun
  portant le mode de SON lien. `mode()` (défaut Strict) conservé ; null → défaut.
  Wallpaper/Overlay providers relus, inchangés (lisent `wallpapers.mode` / `signal->mode`).
- **T4 — `StateCompiler`** : confirmé inchangé. `aggregateMode()`/`selectAggregate()`
  opèrent sur `StateCandidate::$mode` sans connaître sa source. `StateCompilerTest`
  (24 cas, dont mix strict+default → strict) reste vert sans modification.
- **T5 — UI** : toggle « autoriser l'utilisateur à modifier ce paramètre » AU LOT dans
  `shortcut-assignment-modal` (propriété `$mode`, reset dans `resetSelections`,
  dispatché dans `confirm()`). Persistance pivot via
  `syncWithoutDetaching([$id => ['mode' => $mode]])` dans `onAssignmentsConfirmed()`
  (param `$mode` ajouté, validé `strict|default` défaut `strict`). Retirés du form
  règle : propriété `$mode`, load, validation `in:strict,default`, save, et le `<select>`
  mode de `shortcut-form.blade.php`. Wallpaper (`wallpaper-card`) et overlay
  (`overlay-messages`) : libellé clarifié « par cible » (mode déjà au geste
  d'assignation sur leur ligne — aucune régression de persistance).
- **T6/T7 — Tests** : `ShortcutsStateProviderTest` — `mode_is_read_per_rule_from_the_table`
  réécrit en `mode_is_read_per_assignment_from_the_pivot` (mode sur le lien) +
  nouveau `same_rule_on_two_mailles_carries_each_assignment_mode` (cœur 27.3) ;
  helper `assign()` accepte un `?StateMode`. `StateModeCastTest` — le cas shortcut
  devient `shortcut_no_longer_casts_mode_on_the_model` (le cast a quitté le modèle) ;
  wallpaper/overlay inchangés. `ShortcutModeTogglePageTest` réécrit :
  `rule_edit_form_no_longer_exposes_a_mode_property` (retrait régression) +
  `assignment_persists_mode_on_the_pivot`. **Golden + hash prouvés intacts** : aucun
  fichier sous `agent/` ni `tests/Fixtures/Agent/` dans le changeset 27.3 ;
  `ContractV1Test::state_hash_is_frozen_regression_guard` VERT (le mode n'a jamais
  fui au payload). NOTE : `state.v1.json` + `FROZEN_STATE_HASH`/`frozenStateHash` ont
  changé dans le tree, mais c'est le fait de la story 27.7 en cours (payload
  `icon_asset`/`icon_checksum`), PAS de 27.3 — `report.v1.json` byte-identique.
- **T8 — Docs** : `state-providers.md` (note « mode PAR ASSIGNATION » dans la section
  shortcuts + section « provenance du mode par type » réécrite avec note de révision
  27.1→27.3) ; `docs/qa/domains/agent.md` Section 16 append-only (4 scénarios) ; ligne
  27.3 dans `docs/qa/README.md`.
- **T9** : `php -l` OK sur tous les PHP touchés ; NFR7 grep `ldap|apcu|samba-tool` sur
  les 3 providers = uniquement des COMMENTAIRES (aucune occurrence en code, iso
  27.1/27.2) ; aucun fichier du canal legacy raccourci touché ; `go test ./...` +
  `go vet` (linux+windows) + cross-compile VERTS et inchangés (aucun fichier `agent/`
  touché par 27.3).

### Résultats des tests

- **PHPUnit (VM, SQLite)** : `ShortcutsStateProviderTest` 12/12 ; `StateModeCastTest`
  3/3 ; `StateCompilerTest` 24/24 ; `ContractV1Test` 5/5 (frozen hash guard vert sans
  bump) ; `ShortcutModeTogglePageTest` 2/2. Suite `--filter Agent` : 398 passed,
  7 failed — TOUS pré-existants & hors 27.3 : `GoAgentTest` (Go non installé sur VM,
  host-only) + 6 `ToolEndpointTest` (story 27.1bis, route `agent.v1.tools.download`
  pas encore `route:cache`/`config:cache` sur la VM). Aucune régression imputable à 27.3.
- **Go (hôte)** : `go test ./...` ok (`shared` vert) ; `go vet` linux + `GOOS=windows`
  clean ; cross-compile `GOOS=windows` 10.6 Mo OK. Inchangés (aucun fichier `agent/`
  modifié par 27.3).

### Actions /vm jouées

`migrate:status` (2 migrations 27.3 Pending) → `php artisan migrate --force` (add pivot
mode + drop règle mode jouées ; la migration 27.7 `add_icon_asset` co-Pending a aussi
été jouée). Pas de `config:cache`/`route:cache` (aucun config/route 27.3).
`composer install` a dû être relancé sur la VM pour restaurer les dev-deps (phpunit
absent en install prod) avant de pouvoir lancer la suite.

### File List

Migrations (créées) :
- `database/migrations/2026_06_16_110000_add_mode_to_shortcut_assignables.php`
- `database/migrations/2026_06_16_110100_drop_mode_from_shortcuts.php`

Code serveur (modifiés) :
- `app/Models/Shortcut.php`
- `app/Services/Agent/Providers/ShortcutsStateProvider.php`
- `app/Services/Agent/StateCandidate.php` (docblock provenance du mode ; `@param` corrigé en review)
- `app/Services/Agent/StateCompiler.php` **(correction post-review 🔴)** : agrégation du mode sur
  l'ensemble PRÉ-dedup pour la sémantique aggregate (sinon le dedup de payload masquait une assignation
  stricte → strict-wins cassé, cœur de 27.3). Exclusif inchangé. Cf. `_bmad-output/codeReviews/27-3.md` #1.

UI (modifiés) :
- `resources/views/components/organisms/shortcut-assignment-modal.blade.php`
- `resources/views/pages/shortcuts/[id]/index.blade.php`
- `resources/views/pages/shortcuts/[id]/_partials/shortcut-form.blade.php`
- `resources/views/components/molecules/wallpaper-card.blade.php` (libellé « par cible »)
- `resources/views/pages/parc-settings/overlay-messages/index.blade.php` (libellé « par cible »)

Tests (modifiés) :
- `tests/Unit/Services/Agent/ShortcutsStateProviderTest.php` (+ `mode_is_read_per_assignment_for_usergroup_and_user_mailles`, review #3)
- `tests/Unit/Services/Agent/StateCompilerTest.php` **(review #1)** : `strict_assignment_wins_even_when_dedup_drops_its_candidate`
- `tests/Unit/Models/StateModeCastTest.php`
- `tests/Feature/Livewire/ShortcutModeTogglePageTest.php`

Documentation (modifiés) :
- `docs/agent/state-providers.md`
- `docs/qa/domains/agent.md`
- `docs/qa/README.md`

NON modifiés (preuve contrat intact) : tout `agent/`, `tests/Fixtures/Agent/state.v1.json`
(modifié par 27.7, pas 27.3), `report.v1.json` (byte-identique),
`app/Models/Wallpaper.php`, `app/Models/OverlaySignal.php`,
`WallpaperStateProvider.php`, `OverlayStateProvider.php`.
(`StateCompiler.php` initialement « non modifié » mais corrigé en review — cf. #1.)

### Change Log

- 2026-06-16 — Story 27.3 développée (DEV opus). `mode` shortcuts déplacé règle→pivot
  (Option A) ; provider lit `shortcut_assignables.mode` par maille ; toggle UI au geste
  d'assignation (au lot) retiré du form règle ; wallpaper/overlay = libellé « par
  cible ». Agrégation compilateur & contrat/golden inchangés (mode jamais émis au
  payload). Status ready-for-dev → review.
- 2026-06-16 — Review (sonnet) + 2e avis (opus). 🔴 Bug d'agrégation corrigé : le dedup
  de payload masquait une assignation stricte → `StateCompiler::compileProvider` agrège
  désormais le mode PRÉ-dedup (aggregate) ; `@param` `StateCandidate` corrigé ; 2 tests
  ajoutés (régression strict-wins + mailles UserGroup/User). Suites 27.3 = 51/51 vert.
  Limitations connues (mode type-wide hérité 27.1 ; mode write-once via UI) → questions
  Henri dans `_bmad-output/codeReviews/27-3.md`. Status review → to-validate.

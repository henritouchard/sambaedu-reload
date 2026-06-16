# Story 27.3 : Handler registre — catalogue de réglages par parc

Status: ready-for-dev

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

> **ℹ️ NUMÉROTATION — lire en premier.** Ceci est la VRAIE story 27.3 du slot canonique
> *« Handlers registre & associations de fichiers »* (`epics-agent-desired-state.md` L719-733). Le slot avait
> été réaffecté à tort à une story drift-policy, **annulée** depuis (superseded par 27.8 ; voir
> `27-3-drift-policy-par-assignation.md` Status: cancelled). Le slot est rendu au registre.
> **SCISSION** : cette story ne livre QUE le type `registry`. Les **associations / UserChoice** partent dans
> une story séparée **27.3bis** (à faire dans la foulée — NE PAS les inclure ici).

## ✅ DÉCISIONS HENRI — TRANCHÉES (2026-06-16)

> Cadrage tranché avec Henri avant rédaction. **Procéder sans re-demander** sur ces points :
> 1. **Catalogue, pas éditeur de clés brutes** (v1). L'admin d'établissement (non-expert) active/configure un
>    **ensemble prédéterminé** de réglages registre par parc, dans une section `parc-settings/`. PAS de
>    saisie de chemin de registre à la main.
> 2. **Couture générique** : architecturer pour brancher les clés brutes PLUS TARD **sans coût**, mais ne PAS
>    les implémenter. Invariant dur (AC central) : l'**item contrat `registry` porte une clé/valeur CONCRÈTE
>    générique** `{hive, path, name, type, value}`, **jamais** un id de réglage du catalogue. Le catalogue est
>    un **détail serveur** qui se **compile** en items concrets dans le provider.
> 3. **Agent générique** : UN handler `registry` idempotent qui écrit/vérifie/réapplique n'importe quelle
>    valeur concrète. Écrit une fois ; ajouter un réglage au catalogue plus tard = **data Laravel, zéro
>    release agent**.
> 4. **Sémantique = `exclusive`** par identité de clé `{hive, path, name}` : la maille la plus spécifique
>    gagne par clé ; les clés distinctes s'accumulent. (`aggregate` impossible : une clé = une seule valeur.)
> 5. **Drift STRICT inconditionnel** (27.8) : statuts `compliant | drift | error`, jamais de tolérance.

## Story

En tant que **mainteneur SambaEdu / admin d'établissement**,
je veux **activer par parc un ensemble prédéterminé de réglages de registre Windows (catalogue), appliqués et
maintenus par l'agent**,
afin de **piloter les réglages système des postes par le canal agent (successeur GPO Registry.pol), sans
écrire de chorégraphie dispersée ni exposer l'édition de registre brute à des non-experts** — chaque réglage
étant un comportement connu, testé une fois, idempotent.

## Contexte & intention

**Place dans l'Epic 27.** Troisième type de ressource porté au canal agent, dans la lignée 27.1 (raccourcis)
et 27.2 (lecteurs/imprimantes), selon le pattern figé **« 1 StateProvider + 1 handler Go + identifiant de type
figé + golden file »**. C'est le **premier type SANS table métier existante** (raccourcis/imprimantes/wallpaper
en avaient déjà une) : il faut **créer la table catalogue + son UI**.

**Ce que remplace ce handler.** Le legacy poussait des valeurs de registre via les **`Registry.pol`** des GPO
(format binaire décodé par `includes/gpo.inc.php` côté serveur, appliqué par les CSE Windows). Ce handler en
est le **successeur natif** : config-as-data servie par SE5, réimposée par l'agent (direction successeur GPO).

**Le modèle métier (décision Henri) : catalogue, pas éditeur brut.**
- Une section **« Réglages registre »** par parc (sous `parc-settings/`, à côté de `overlay-messages`,
  `wallpapers`, `app-customizations`) liste un **ensemble fixe de réglages** qu'on a implémentés (ex. afficher
  les extensions de fichiers, désactiver Cortana, page de démarrage, proxy…). L'admin **active/configure** ces
  réglages par parc — il ne tape jamais un chemin `HKLM\…`.
- Chaque réglage du catalogue **se compile** en un ou plusieurs items de registre **concrets**
  `{hive, path, name, type, value}` côté provider.

**La couture qui rend les clés brutes triviales plus tard (invariant de conception).** Le **contrat agent ne
connaît QUE des clés concrètes génériques**. Le catalogue est un détail **serveur**. Conséquence : ajouter
plus tard un **éditeur de clés brutes** (gaté par une permission Spatie « expert ») = **brancher une 2ᵉ source
d'autoring** qui produit les **mêmes** items concrets → **zéro changement** d'agent, de contrat ou de provider.
**Ne JAMAIS laisser la notion de « catalogue » / « id de réglage » fuiter jusqu'au payload ou à l'agent.**

**Ce que cette story livre :**
- **Schéma (D1)** : table catalogue dédiée `registry_settings` + pivot d'assignation `registry_setting_assignables`
  (calqué `shortcut_assignables`). **Jamais** de table polymorphe générique de règles.
- **Provider serveur** : `RegistryStateProvider` (`type='registry'`, `semantics=Exclusive`, `scope` selon la
  portée du réglage) — lecture seule Postgres, **candidats bruts par maille** (discipline D2), compilation du
  catalogue en items concrets.
- **Agent Go** : un handler `registry` **générique** idempotent (`Test`/`Apply`), HKLM via service SYSTEM /
  HKCU via compagnon.
- **UI** : section « Réglages registre » dans `parc-settings/`, toggles/valeurs activables par parc.
- **Contrat/golden** : payload `registry` spécifié ; **item `registry` ajouté au golden** → `FROZEN_STATE_HASH`
  bumpé **sciemment**, **croisé PHP↔Go** (NFR13).

**Ce que cette story N'EST PAS :**
- Les **associations / UserChoice** → **story 27.3bis** (le « vice » à logique Go propre ; hors-scope ici).
- Un **éditeur de clés brutes** → v2 (on architecture POUR, on ne l'implémente pas).
- Le **décommissionnement du canal legacy** Registry.pol/GPO → 27.6 (zéro retrofit legacy ici).
- Un changement de la **machine d'états §5** (`engine.go`) ou de la sémantique de drift (STRICT, réutilisée).

**Zéro prod (mémoire `zero_prod_publish_is_test`)** : aucune donnée à préserver. Migrations neuves, `down()`
symétrique, pas de back-fill.

## ⚠️ Pièges & tensions (lire AVANT de coder)

1. **🔴 INVARIANT CENTRAL — le catalogue ne fuit JAMAIS au contrat.** L'item `registry` du payload porte
   `{hive, path, name, type, value}` **concrets**, **jamais** un `setting_id` / `setting_key` de catalogue.
   C'est CE qui garde l'option « clés brutes » ouverte à coût nul. Un `setting_id` dans le payload = régression
   d'architecture. **Vérifié en AC1 + AC6.**

2. **Premier type sans table métier — D1 impose une table DÉDIÉE.** Créer `registry_settings` (catalogue) +
   pivot, **jamais** une table polymorphe générique de règles (`desired_state_rules`). [Source:
   `architecture-agent-desired-state.md` L250-260 — « un type sans table métier recevra sa table dédiée au
   moment de son handler ».]

3. **Sémantique `exclusive` par identité de clé.** Une clé de registre = **une** valeur. Le compilateur doit
   départager par **identité `{hive, path, name}`** : maille la plus spécifique gagne (poste > WG physique >
   WG logique > broadcast), clés **différentes** s'accumulent. ⚠️ `StateCompiler::selectExclusive()` existant
   départage par spécificité de maille — vérifier qu'il **clé bien par identité d'item** (pas « un seul item du
   type pour tout le poste », ce qui écraserait des clés distinctes). Si l'exclusive actuel est « 1 item / type »
   (cas wallpaper), il faut une **exclusivité par identité de clé** ⇒ **lire `StateCompiler` avant de coder** et
   adapter/étendre si besoin (sans casser wallpaper/printers `is_default`). **C'est la principale subtilité
   serveur de la story.** [Source: `app/Services/Agent/StateCompiler.php` — `compileProvider()` + `selectExclusive()`.]

4. **Portée par réglage (machine vs user) → scope de l'item.** Un réglage du catalogue déclare sa ruche :
   `HKLM` (portée **machine**, appliquée par le service SYSTEM) ou `HKCU` (portée **session/utilisateur**,
   appliquée par le compagnon). Le provider doit émettre l'item dans la **bonne portée d'enveloppe**
   (`machine` vs `session`/`machine_user`) selon la ruche. ⚠️ Un même réglage est mono-ruche ; ne pas mélanger.
   Le `scope()` du provider est unique par provider — si on doit émettre dans 2 portées, soit deux sous-listes,
   soit décision de cadrage (voir Question Henri n°2). **Défaut proposé : le réglage porte sa ruche, le
   provider range l'item dans la portée correspondante.**

5. **« Désactiver un réglage » = cesser de le gérer (item absent), PAS reset à une valeur par défaut (v1).**
   Dans le modèle desired-state, un type/clé **absent** de la liste = « le serveur ne gère pas » → l'agent **ne
   touche pas** (contrat §8). Donc désassigner un réglage d'un parc = l'item disparaît = la clé garde sa
   dernière valeur (pas de retour à la valeur Windows d'origine). C'est cohérent et simple, mais à **documenter
   comme limite connue** (un « reset à une valeur OFF explicite » est une évolution possible — voir Question
   Henri n°1). **Ne pas inventer de reset implicite.**

6. **Idempotence & level-triggered (réutiliser engine §5).** `Apply()` rejouable sans effet cumulatif ;
   `Test()` lit le réel et compare. Drift STRICT : valeur réelle ≠ cible → `drift` + réapplication. Échec
   d'écriture (clé protégée, ruche absente) → `error` + `detail` exploitable, **isolation par item** (les
   autres clés/types continuent). [Source: `agent/shared/engine.go` interface `Handler{Test,Apply}` + `RunPass`.]

7. **Golden bouge LÉGITIMEMENT (nouveau type au contrat).** Ajouter un item `registry` au
   `tests/Fixtures/Agent/state.v1.json` ⇒ `FROZEN_STATE_HASH` (PHP `ContractV1Test`) **et** `frozenStateHash`
   (Go `hasher_test.go`) **bumpés à la MÊME valeur** (test croisé NFR13). **Relever la valeur courante du tree
   d'abord** (27.7/27.8/27.1bis l'ont bougée). `report.v1.json` : ajouter/non un item registry selon l'exemple
   choisi. **Le bump est attendu** ; ce n'est PAS une régression (≠ 27.3 drift-policy où le hash devait rester
   figé).

8. **NFR7 — zéro AD/APCu/LdapRecord/samba-tool dans le provider.** Reconduit de 27.1/27.2 : grep
   `ldap|apcu|samba-tool` sur `RegistryStateProvider` doit rester **vide** (occurrences = commentaires seulement).
   Le ciblage passe par les relations Postgres (pivot + `TargetContext`), jamais l'AD.

9. **VM migrations PAS auto-jouées (mémoire `vm_migrations_not_auto_applied`).** Le dev-cycle migre en SQLite
   only. Lister l'action `/vm` : `migrate:status` → `php artisan migrate --force`. Pas de `config:cache`/`route:cache`
   attendu (aucun `config/*.php` ni route ajoutés). Si un seeder du catalogue est ajouté, le lancer aussi sur /vm.

10. **Tests SQLite n'appliquent pas varchar/contraintes PG (mémoire `sqlite_tests_no_varchar_enforcement`).**
    Couverture critique = **fonctionnelle** : provider émet le bon item concret par maille, exclusive par clé,
    portée correcte selon la ruche, lecture seule zéro AD. Ne pas compter sur SQLite pour le `VARCHAR`/enum.

11. **routes/api.php — a priori aucune route ajoutée.** Le provider est consommé par la compilation d'état
    existante (endpoint desired-state déjà en place). Si (contre toute attente) une route est ajoutée,
    l'insérer **APRÈS** le groupe 16.12 (mémoire `api_routes_arch_test_window_trap`). Non attendu.

12. **Go = hôte uniquement** (`~/go-toolchain/go/bin`, package main = `agent/windows`) ; PHPUnit sur `/vm` ;
    **jamais** d'interaction VM depuis un worktree (mémoire `feedback_worktree_no_vm_sync`).

## Acceptance Criteria

### AC1 — Schéma catalogue + pivot (D1) ; idempotent + réversible (FR21, FR26)

**Given** le type `registry` — premier type sans table métier existante
**When** les migrations sont jouées
**Then** une table catalogue **`registry_settings`** dédiée est créée (clé technique unique du réglage, libellé
affichable, `hive` ∈ {HKLM, HKCU}, `path`, `name`, `type` ∈ REG_* (`REG_SZ`/`REG_DWORD`/`REG_EXPAND_SZ`/
`REG_MULTI_SZ`…), `value` cible, portée dérivée de la ruche, actif/visible) **et** un pivot d'assignation
**`registry_setting_assignables`** (`morphs('assignable')` → Workstation/WorkstationGroup/UserGroup/User,
UNIQUE `(registry_setting_id, assignable_id, assignable_type)`), **calqué sur `shortcut_assignables`**
**And** migrations idempotentes (`Schema::hasColumn`/`hasTable`), `down()` symétrique, `->comment()` daté 27.3,
**jamais** de table polymorphe générique de règles (D1)
**And** **aucun** `setting_id`/`setting_key` n'apparaît dans le payload contrat (invariant piège n°1).

### AC2 — Modèle catalogue + constante de type figée (NFR12)

**Given** le schéma
**When** on inspecte les modèles
**Then** `App\Models\RegistrySetting` (catalogue) expose les colonnes en `$fillable`/`$casts` (enum `hive`/`type`
si modélisés), la relation d'assignation polymorphe (`morphedByMany` ou pivot lu en SQL par le provider), et
une **constante de type figée** `RegistrySetting::TYPE_REGISTRY = 'registry'` (snake_case, jamais renommée).

### AC3 — Provider : catalogue → items concrets par maille, exclusive par clé (FR21, NFR7)

**Given** des réglages du catalogue assignés à plusieurs mailles d'un poste
**When** `RegistryStateProvider::itemsFor(TargetContext)` produit ses candidats
**Then** il lit **en seule lecture Postgres** (pivot + `TargetContext` ids ; **jamais** l'AD/APCu — NFR7 grep
vide), émet des **`StateCandidate` BRUTS étiquetés par maille** (discipline D2 : **aucune** précédence/tri/dédup
dans le provider) dont le `payload` est l'item **concret** `{hive, path, name, type, value}` (jamais un id de
catalogue)
**And** `type()='registry'`, `semantics()=Exclusive`, `scope()` cohérent avec la ruche (HKLM→machine,
HKCU→session/machine_user)
**And** un réglage non assigné à aucune maille du poste **n'émet aucun item** (type/clé absent = non géré, piège n°5).

### AC4 — Compilateur : exclusive PAR IDENTITÉ DE CLÉ (FR5)

**Given** deux mailles assignant la **même clé** `{hive, path, name}` avec des valeurs différentes
**When** le `StateCompiler` compile
**Then** la maille **la plus spécifique gagne POUR CETTE CLÉ** (poste > WG physique > WG logique > broadcast),
et les **clés distinctes s'accumulent** toutes
**And** la machine d'états §5 (`engine.go`) et la sémantique de drift **STRICT** sont **réutilisées telles
quelles** (zéro modification agent) ; si `selectExclusive()` actuel exclut « 1 item par type » (cas wallpaper),
il est **étendu pour exclure par identité d'item** sans régresser wallpaper/printers (test de non-régression).

### AC5 — Agent Go : handler `registry` générique, idempotent, par portée (FR21)

**Given** une liste d'items `registry` concrets
**When** l'agent converge
**Then** un **unique** handler `registry` (`agent/shared/handler_registry.go`, pur testable hôte + ops injectées,
impl Windows `agent/windows/handler_registry_windows.go`, câblé dans `companion_windows.go`) **écrit/vérifie/
réapplique** chaque valeur : **HKLM par le service SYSTEM**, **HKCU par le compagnon** ; `Apply` **idempotent**
(2 passes sur état stable = zéro écriture), `Test` compare le réel
**And** échec d'écriture (clé protégée / ruche absente) → statut **`error` + `detail`** exploitable, **isolation
par item** (les autres clés/types continuent — engine §5 réutilisé)
**And** le handler est **agnostique du catalogue** : il ne connaît que `{hive, path, name, type, value}` (ajouter
un réglage = data serveur, **zéro** modification Go).

### AC6 — Contrat & golden : item `registry` ajouté, hash bumpé croisé (NFR13)

**Given** l'ajout du type `registry` au contrat v1
**When** les tests croisés tournent
**Then** `docs/agent/contract-v1.md` §7 documente le payload `registry` `{hive, path, name, type, value}` (zéro
float ; pas de confusion `{}` vs `[]`) ; un item `registry` est ajouté à `tests/Fixtures/Agent/state.v1.json`
**And** `ContractV1Test::FROZEN_STATE_HASH` (PHP) **et** `frozenStateHash` (Go `hasher_test.go`) sont **bumpés
SCIEMMENT à la même valeur** (relever d'abord la valeur courante du tree), tests croisés **verts** = preuve
**And** **aucun `setting_id`/`setting_key`** dans le payload (invariant piège n°1).

### AC7 — UI : section « Réglages registre » par parc (FR26, FR19)

**Given** l'admin ouvre les réglages d'un parc
**When** il accède à la section « Réglages registre » (`resources/views/pages/parc-settings/`, à côté de
`overlay-messages`/`wallpapers`)
**Then** il voit la **liste du catalogue** et peut **activer/configurer** chaque réglage **pour ce parc**
(toggle pour un réglage booléen, champ valeur pour un réglage paramétrable), persisté sur le **pivot**
`registry_setting_assignables` (`syncWithoutDetaching`/attach/detach), via **Livewire SFC**, **trait
`WithToasts`**, **modale réutilisable** si besoin, **Gate/permission** cohérente avec les autres réglages parc
(iso `parc-settings`)
**And** la sémantique « désactiver = cesser de gérer (item absent) » est claire dans le libellé (piège n°5).

### AC8 — Tests : provider + compilateur + UI + agent + non-régression (NFR13)

**Then** côté **Laravel** :
- `RegistryStateProviderTest` : (a) catalogue assigné → item **concret** `{hive,path,name,type,value}` par
  maille ; (b) **jamais** d'id de catalogue dans le payload ; (c) réglage non assigné → aucun item ; (d) HKLM→
  portée machine, HKCU→portée session ; (e) **lecture seule, zéro AD** (NFR7)
- `StateCompilerTest` : **exclusive par identité de clé** — même clé sur 2 mailles → la plus spécifique gagne ;
  clés distinctes → toutes présentes ; **non-régression wallpaper/printers `is_default`**
- test UI/feature : l'activation persiste l'assignation pivot ; la désactivation la retire
- `ContractV1Test` : golden cohérent, hash **bumpé** (croisé)

**And** côté **agent Go** : `handler_registry_test.go` (set cible, idempotence, drift→réapplication, error
isolé + isolation inter-items, HKLM/HKCU) ; `go test ./...` + `go vet` (linux + `GOOS=windows`) + cross-compile
**verts** ; `hasher_test.go` croisé vert.

### AC9 — Documentation + QA (append-only)

**Then** `docs/agent/state-providers.md` : nouvelle section `registry` (catalogue → items concrets, exclusive
par clé, portée par ruche, invariant « pas d'id de catalogue au payload »)
**And** `docs/agent/contract-v1.md` §7 : payload `registry` documenté
**And** `docs/qa/domains/agent.md` enrichi **append-only** (nouvelle section `## Story 27.3` sans renuméroter :
réglage appliqué par parc, exclusive par clé entre parcs, HKLM via SYSTEM / HKCU via compagnon, drift STRICT
réapplique, désactiver = cesser de gérer) ; ligne 27.3 dans `docs/qa/README.md`
**And** note : ce handler est le successeur natif du canal Registry.pol/GPO (legacy intouché, meurt en 27.6).

## Tasks / Subtasks

- [ ] **T0 — Cadrage figé + 2 questions résiduelles** (pièges n°1, n°5)
  - [ ] Confirmer l'invariant « zéro id de catalogue au payload » comme garde-fou de revue.
  - [ ] Geler le **set initial du catalogue** (Question Henri n°1) et la **sémantique de désactivation**
        (cesser de gérer vs reset OFF). Défaut appliqué si pas de retour : v1 = cesser de gérer + un petit set
        de réglages sûrs (voir Dev Notes).

- [ ] **T1 — Migrations : catalogue + pivot** (AC1)
  - [ ] `database/migrations/2026_06_16_HHMMSS_create_registry_settings_table.php` : `key` unique, `label`,
        `hive`, `path`, `name`, `type`, `value` (texte ; `REG_MULTI_SZ`/`REG_DWORD` sérialisés — documenter),
        flags `is_active`/visibilité, `->comment()` daté 27.3, `Schema::hasTable` en garde, `down()` drop.
  - [ ] `database/migrations/2026_06_16_HHMMSS_create_registry_setting_assignables_table.php` : `foreignId
        ('registry_setting_id')->constrained()->cascadeOnDelete()`, `morphs('assignable')`, `unique(...)`,
        **calqué EXACTEMENT** sur `2026_02_09_173400_create_shortcut_assignables_table.php`.
  - [ ] (Optionnel) seeder/migration de données du set initial du catalogue (idempotent) — OU laisser vide et
        peupler via l'UI ; décision T0.

- [ ] **T2 — Modèle catalogue** (AC2)
  - [ ] `app/Models/RegistrySetting.php` : `$fillable`/`$casts`, const `TYPE_REGISTRY='registry'`, relation
        d'assignation polymorphe, accessors éventuels (enum `hive`/`type`).

- [ ] **T3 — Provider** (AC3, AC4) — *cœur serveur*
  - [ ] `app/Services/Agent/Providers/RegistryStateProvider.php` implements `StateProvider` : `type()`,
        `semantics()=Exclusive`, `scope()`, `itemsFor(TargetContext)` — lecture pivot par maille, **compile**
        chaque réglage en item concret `{hive,path,name,type,value}`, candidats BRUTS (D2).
  - [ ] Enregistrer dans `app/Providers/AgentServiceProvider.php` (tableau des providers de `StateCompiler`).
  - [ ] **Adapter `StateCompiler::selectExclusive()`** si nécessaire pour **exclure par identité de clé**
        `{hive,path,name}` (et non « 1 item / type »), **sans régresser** wallpaper/printers — relire d'abord.

- [ ] **T4 — Agent Go : handler générique** (AC5)
  - [ ] `agent/shared/handler_registry.go` : struct `RegistryHandler{Ops, Log}` + `Test`/`Apply` purs,
        interface `RegistryOps` injectée (testable hôte avec fake).
  - [ ] `agent/windows/handler_registry_windows.go` : impl `registryOps` Windows native (`golang.org/x/sys/
        windows/registry` — vérifier go.mod, sinon proposer ; lecture/écriture HKLM/HKCU, types REG_*).
  - [ ] Câblage 1 ligne dans `agent/windows/companion_windows.go` map `Handlers["registry"]` (SYSTEM pour
        HKLM, compagnon pour HKCU — respecter le découpage des deux moteurs existants).
  - [ ] `agent/shared/handler_registry_test.go` : set cible, idempotence, drift, error isolé, HKLM/HKCU.

- [ ] **T5 — Contrat + golden** (AC6)
  - [ ] `docs/agent/contract-v1.md` §7 : payload `registry`.
  - [ ] `tests/Fixtures/Agent/state.v1.json` : item `registry` (+ `report.v1.json` si exemple voulu).
  - [ ] Relever `FROZEN_STATE_HASH`/`frozenStateHash` courants, **bumper** aux nouvelles valeurs croisées
        (calcul via hasher réel /vm ou hôte Go), `ContractV1Test` + `hasher_test.go` verts.

- [ ] **T6 — UI** (AC7)
  - [ ] `resources/views/pages/parc-settings/registry-settings/index.blade.php` (+ `_partials/`) : Livewire SFC,
        liste catalogue + toggle/valeur par parc, persistance pivot (`syncWithoutDetaching`/attach/detach),
        `WithToasts`, modale réutilisable au besoin, Gate iso-parc-settings.
  - [ ] Greffer l'entrée de section dans la navigation `parc-settings/` (calquer `overlay-messages`).

- [ ] **T7 — Tests** (AC8)
  - [ ] PHPUnit : `RegistryStateProviderTest`, `StateCompilerTest` (exclusive par clé + non-régression),
        feature UI assignation, `ContractV1Test` (hash bumpé).
  - [ ] Go : `go test ./...` + `go vet` (linux+windows) + cross-compile + `hasher_test.go` croisé — verts.

- [ ] **T8 — Documentation + QA** (AC9)
  - [ ] `state-providers.md` section registry ; `contract-v1.md` §7 ; `docs/qa/domains/agent.md` `## Story 27.3`
        append-only ; ligne 27.3 `docs/qa/README.md`.

- [ ] **T9 — Validation finale** (AC6, AC8)
  - [ ] `php -l` sur les PHP touchés ; grep NFR7 (`ldap|apcu|samba-tool`) sur le provider → vide ; grep « zéro
        retrofit legacy » (aucun fichier du canal Registry.pol/GPO dans le diff).
  - [ ] `go test ./...`/vet/cross-compile verts. `--filter Agent` /vm sans régression (hors préexistants connus).
  - [ ] **Actions /vm (PAS auto)** : `migrate:status` → `php artisan migrate --force` (2 migrations + seeder si
        retenu). Pas de `config:cache`/`route:cache` (aucun config/route ajouté).
  - [ ] **Validation lab (poste Windows) — ACTION HUMAINE (Henri)** : un réglage HKLM activé sur un parc est
        appliqué (SYSTEM) ; un réglage HKCU appliqué au logon (compagnon) ; une valeur modifiée à la main est
        **réimposée** (drift STRICT) ; un réglage `strict` sur un parc et une autre valeur sur un autre parc →
        chaque poste reçoit la valeur de SA maille la plus spécifique.

## Dev Notes

### Périmètre — livré / hors-scope

| Livré (27.3) | Hors-scope |
|---|---|
| Type `registry` : catalogue `registry_settings` + pivot + UI parc | **Associations / UserChoice** → **27.3bis** |
| `RegistryStateProvider` (catalogue → items concrets, exclusive par clé) | **Éditeur de clés brutes** → v2 (architecturé pour, non livré) |
| Handler Go `registry` **générique** (HKLM SYSTEM / HKCU compagnon) | Décommissionnement canal legacy Registry.pol/GPO → 27.6 |
| Contrat §7 + golden + hash croisé bumpé | Modification machine d'états §5 / sémantique drift (STRICT réutilisé) |
| Tests provider+compilateur+UI+Go ; QA append-only | Ciblage par CN AD (`ad_users`/`ad_user_groups`) — exclu NFR7 (iso 27.1/27.2) |

### Le pattern Epic 27 — ce qu'on consomme (ne PAS réinventer ; zéro modif moteur)

[Source: `app/Services/Agent/Contracts/StateProvider.php:32-50` (interface `type/semantics/scope/itemsFor`) ;
`app/Services/Agent/StateCandidate.php` (readonly : `maille, payload, updatedAt, sourceId`) ;
`app/Services/Agent/TargetContext.php:26-56` (`physicalGroupIds/logicalGroupIds/userGroupIds/workstation/user`,
résolu une fois) ; `app/Services/Agent/StateCompiler.php` (`compileProvider`/`selectExclusive` — précédence D2
AU COMPILATEUR seul) ; `app/Providers/AgentServiceProvider.php` (tableau des providers) ;
`app/Enums/StateScope.php` (`machine|session|machine_user`) ; `app/Enums/ResourceSemantics.php`
(`aggregate|exclusive`).]

- **Discipline D2** : le provider rend des candidats **bruts par maille**, **aucune** précédence/tri/dédup — la
  précédence vit UNIQUEMENT dans `StateCompiler`. Violation = rejet en revue.
- **`scope()` par provider** : un provider = une portée. Si HKLM et HKCU doivent cohabiter, voir Question n°2.

### Agent Go — structure handler (iso 27.1/27.2)

[Source: `agent/shared/engine.go` interface `Handler{Test(items)(bool,error); Apply(items)error}` + `RunPass`
(groupement par type, dispatch, `ResolveItemStatus`, isolation par item, statuts `compliant|drift|error`) ;
`agent/shared/handler_shortcuts.go` / `handler_printers.go` / `handler_drives.go` (struct `Ops`+`Log`, logique
pure OS-agnostique, ops injectées testées hôte) ; `agent/windows/companion_windows.go` (map `Handlers`, deux
moteurs SYSTEM/compagnon).]

- HKLM → **service SYSTEM** ; HKCU → **compagnon** (droits user, ruche de l'utilisateur de session). Respecter
  la séparation des deux maps existantes (overlay/printers/drives/shortcuts sont déjà rangés ainsi).
- `golang.org/x/sys/windows/registry` couvre lecture/écriture/types REG_*. **Vérifier go.mod** ; si absent,
  proposer l'ajout (dépendance std-ish x/sys, déjà présente pour 25.2/WinVerifyTrust — confirmer).

### Schéma — structures de référence

[Source: `database/migrations/2026_02_09_173400_create_shortcut_assignables_table.php:24-30` — `id` +
`foreignId(...)->constrained()->cascadeOnDelete()` + `morphs('assignable')` + `unique([... ], '..._unique')`.]

- `registry_setting_assignables` = **calque exact** de `shortcut_assignables` (morph
  Workstation/WorkstationGroup/UserGroup/User, UNIQUE triplet). Le ciblage **parc** = assignable
  `WorkstationGroup` ; postes/groupes user supportés par le même pivot (cohérent avec les autres types).
- `value` : stocker en texte ; pour `REG_DWORD` (entier) et `REG_MULTI_SZ` (liste), **documenter la
  sérialisation** (ex. DWORD en décimal, MULTI_SZ en JSON array) — le provider produit la valeur typée au
  payload (zéro float — contrat §4).

### UI — où vit le geste

[Source: `resources/views/pages/parc-settings/` (`overlay-messages`, `wallpapers`, `app-customizations`,
`profiles`, `index.blade.php`, `_partials/`).]

- Nouvelle section `parc-settings/registry-settings/` **calquée sur `overlay-messages`** (Livewire SFC,
  `WithToasts`, modale réutilisable `components/molecules/modal`). Gate iso autres réglages parc.
- Geste = activer/configurer un réglage **du catalogue** pour le parc courant → persistance **pivot**.

### Set initial du catalogue (proposition de défaut — Question Henri n°1)

Réglages **sûrs et fréquents** en milieu scolaire (à valider/compléter par Henri). Tous mono-ruche, valeur cible
explicite. À titre indicatif :
- Afficher les extensions de fichiers (HKCU `…\Explorer\Advanced` `HideFileExt`=`DWORD 0`).
- Afficher les fichiers cachés (HKCU `…\Advanced` `Hidden`=`DWORD 1`) — optionnel.
- Désactiver l'écran de verrouillage publicitaire / suggestions (HKLM/HKCU policy connue).
- Page de démarrage / proxy navigateur si pertinent (cf. legacy `change_proxy_file` gpo.inc.php).

> Démarrer avec **2-3 réglages** suffit pour livrer et tester le mécanisme ; le catalogue grossit ensuite **par
> data** (zéro release agent — c'est le payoff de la couture générique).

### Successeur GPO — direction

[Source: `includes/gpo.inc.php` legacy (`read_pol/write_pol/change_pol_key`, format binaire Registry.pol) ;
mémoires `project_agent_desired_state_direction`, `project_gpo_dispatcher_static_anchor`,
`project_registry_catalog_first_generic_underneath`.]

- Le legacy poussait le registre via les `Registry.pol` des GPO. Ce handler le remplace **nativement** (config
  servie par SE5, réimposée par l'agent). **Zéro retrofit legacy** : ne pas câbler `gpo.inc.php` ; le canal
  meurt en 27.6.

### Environnement de dev — règles VM

- Code à la RACINE (`app/`, `agent/`, …) ; édité sur l'hôte, sync inotify auto, **jamais de sync manuelle**.
- **Go = hôte uniquement** (`~/go-toolchain/go/bin`, package main `agent/windows`). PHPUnit sur `/vm`.
- Migrations → **à jouer sur la VM** (`migrate:status` avant e2e). **Jamais** d'interaction VM depuis un worktree.

### Dépendances

| Story | Rôle pour 27.3 | Statut (sprint-status) | Bloquant ? |
|-------|----------------|------------------------|------------|
| 27.1 — handler raccourcis | Pattern provider+handler+golden ; pivot `shortcut_assignables` (calque schéma) | `review` | Non (pattern consommé ; pas de recouvrement de fichiers fort) |
| 27.2 — drives/printers | Pattern multi-type + exclusive sous-item (`is_default`) à ne pas régresser au compilateur | `review` | Non (consommé ; attention `selectExclusive`) |
| 27.8 — drift STRICT | Statuts `compliant|drift|error` ; item 4 clés ; STRICT inconditionnel | `review` | **Prérequis fort** (le contrat/agent sont post-27.8 ; rebase si correctifs) |
| 23.4 / 24.6 / 23.1 — compilateur/contexte/engine §5/golden/hash | Infra réutilisée | `done` | Non (consommé) |
| 27.3bis — associations/UserChoice | Story sœur, faite après | (à créer) | Non (indépendante ; même slot de série) |

> **Recouvrement à surveiller** : `StateCompiler` (exclusive) et le golden/hash bougent au fil de l'Epic 27 —
> **relever la valeur courante du tree** avant tout bump, et **rebaser** si 27.8/27.2 reçoivent des correctifs
> touchant `selectExclusive`/`state.v1.json`.

### References

- [Source: `_bmad-output/planning-artifacts/epics-agent-desired-state.md` L719-733] — Story 27.3 canonique
  (registre & associations) ; ici **scindée** : registre seul, associations → 27.3bis.
- [Source: `_bmad-output/planning-artifacts/architecture-agent-desired-state.md` L250-260 (D1 table dédiée,
  jamais polymorphe), L262-272 (D2 précédence au compilateur)].
- [Source: `docs/agent/contract-v1.md` §6 (statuts 27.8), §7 (identifiants figés — `registry` réservé), §8
  (type absent ≠ liste vide)].
- [Source: `app/Services/Agent/Contracts/StateProvider.php`, `StateCandidate.php`, `TargetContext.php`,
  `StateCompiler.php`, `Providers/ShortcutsStateProvider.php` (modèle de provider), `AgentServiceProvider.php`].
- [Source: `app/Enums/StateScope.php`, `app/Enums/ResourceSemantics.php`, `app/Enums/AgentResourceStatus.php`].
- [Source: `agent/shared/engine.go`, `agent/shared/handler_shortcuts.go`, `agent/windows/companion_windows.go`].
- [Source: `database/migrations/2026_02_09_173400_create_shortcut_assignables_table.php`] — calque pivot.
- [Source: `tests/Fixtures/Agent/state.v1.json`, `ContractV1Test`, `agent/shared/hasher_test.go`] — golden+hash.
- [Source: `resources/views/pages/parc-settings/overlay-messages/`] — calque UI section parc.
- [Source: `app/Components/Traits/WithToasts.php`, `resources/views/components/molecules/modal/index.blade.php`].
- [Source: mémoires `project_registry_catalog_first_generic_underneath`, `project_drift_policy_strict_only`,
  `project_agent_desired_state_direction`, `project_gpo_dispatcher_static_anchor`].

## Questions pour Henri

1. **Set initial du catalogue + sémantique de « désactiver ».** (a) Quels réglages registre ship-on en v1
   (proposition de défaut en Dev Notes : extensions de fichiers, écran de verrouillage, proxy…) ? (b)
   « Désactiver un réglage pour un parc » = **cesser de le gérer** (item absent, la clé garde sa dernière
   valeur — défaut recommandé, simple, desired-state pur) **ou** **réimposer une valeur OFF explicite** (le
   catalogue porte alors une 2ᵉ valeur « désactivé ») ? Défaut appliqué : **cesser de gérer**, OFF explicite
   reporté.

2. **Cohabitation HKLM + HKCU dans un seul provider.** Un `StateProvider` déclare **une** portée (`scope()`).
   Si le catalogue mélange des réglages HKLM (machine) et HKCU (user), faut-il **un seul provider** qui range
   chaque item dans la bonne portée d'enveloppe (nécessite que le compilateur accepte qu'un provider émette sur
   2 portées) **ou deux providers** `RegistryMachineStateProvider`/`RegistryUserStateProvider` (plus simple
   vis-à-vis de `scope()`, deux entrées au tableau) ? Défaut proposé : **deux providers** (un par ruche/portée)
   — plus propre avec le contrat actuel ; à confirmer.

3. **Ciblage : parc uniquement ou pivot complet ?** Le pivot calque `shortcut_assignables` (Workstation/
   WorkstationGroup/UserGroup/User). En v1, n'exposer le geste UI que **par parc** (`WorkstationGroup`), ou
   ouvrir aussi poste/groupe user comme les raccourcis ? Défaut : **par parc** à l'UI, pivot complet en schéma
   (extensible sans migration).

## Recommandation Modèle Dev

**Recommandation : `opus`.**

Justification : story **structurante multi-fichiers** malgré l'apparence « énième handler ». Elle (a) **crée un
schéma neuf** (premier type sans table métier — catalogue + pivot, D1), (b) introduit une **couture
d'architecture subtile** à ne pas rater — le catalogue ne doit **jamais** fuiter jusqu'au contrat/agent (c'est
l'invariant qui rend les clés brutes gratuites plus tard), (c) demande une **exclusivité par identité de clé**
au compilateur, possiblement une **extension de `selectExclusive()` sans régresser** wallpaper/printers, (d)
ajoute un **type au contrat** avec **bump de hash croisé PHP↔Go**, (e) un **handler Go générique** réparti
SYSTEM/compagnon (HKLM/HKCU) idempotent, et (f) une **UI parc** neuve. Le risque majeur — laisser fuiter un
`setting_id` au payload, ou casser l'exclusive existant — exige un raisonnement rigoureux. `opus`.

## Dev Agent Record

### Agent Model Used

### Debug Log References

### Completion Notes List

### File List

# Story 27.3 : Handler registre — catalogue de réglages par parc

Status: review

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

## ✅ DÉCISIONS HENRI — VALIDATION DEV-CYCLE (2026-06-16) — À APPLIQUER SANS RE-DEMANDER

> Tranchées à la validation de la story (Q1/Q2/Q3 + ciblage). **Procéder sans re-demander.**
>
> **D-Q1 — Set initial du catalogue (3 réglages, choisis parmi les GPO SambaEdu, tous vérifiables sans infra).**
> Ship-on v1 (seeder/migration de données idempotent) :
> | Libellé UI | GPO d'origine | hive | path | name | type | value |
> |---|---|---|---|---|---|---|
> | Afficher les extensions de fichiers | `optimisations`/`Bureau` | `HKCU` | `Software\Microsoft\Windows\CurrentVersion\Explorer\Advanced` | `HideFileExt` | `REG_DWORD` | `0` |
> | Afficher les fichiers cachés | `optimisations`/`Bureau` | `HKCU` | `Software\Microsoft\Windows\CurrentVersion\Explorer\Advanced` | `Hidden` | `REG_DWORD` | `1` |
> | Désactiver l'UAC | `desactivation uac` | `HKLM` | `SOFTWARE\Microsoft\Windows\CurrentVersion\Policies\System` | `EnableLUA` | `REG_DWORD` | `0` |
>
> Deux HKCU (compagnon, effet Explorer immédiat) + un HKLM (SYSTEM) → couvre les **deux portées** pour valider
> les deux providers ET les deux moteurs Go. **« Désactiver un réglage » = cesser de le gérer** (item absent ;
> la clé garde sa dernière valeur ; PAS de reset OFF explicite — piège n°5). Le catalogue grossit ensuite par
> **data** (zéro release agent). NB : le Bureau à distance (RDP) a été écarté du set initial (non testable
> facilement par Henri).
>
> **D-Q2 — DEUX providers serveur, UN handler Go.** Un `StateProvider` déclare UNE portée (`scope()`) et le
> compilateur route tous ses items vers ce seul casier. Donc : `RegistryMachineStateProvider` (HKLM →
> `scope()=Machine`) **et** `RegistryUserStateProvider` (HKCU → `scope()=Session`), deux entrées au tableau
> `AgentServiceProvider`, **zéro modif du routage compilateur**. Côté agent : **UN SEUL** handler `registry`
> générique (HKLM par le service SYSTEM, HKCU par le compagnon) — la séparation est purement serveur. Les deux
> providers partagent la même logique de lecture (trait/classe de base ou helper commun) ; seule la ruche/portée
> diffère. Le catalogue `registry_settings` reste UNE table : chaque provider filtre par `hive`.
>
> **D-Q3 — 🔴 INVERSION GLOBALE de la précédence physique/logique (`logique > physique`).** Décision Henri :
> le **parc LOGIQUE prime sur la salle PHYSIQUE** car le parc logique est une **sélection délibérée de postes**
> (transverse aux salles) → plus spécifique. Cet ordre s'applique **GLOBALEMENT** (pas seulement au registre).
> Périmètre étendu de 27.3 (puisqu'elle touche déjà `selectExclusive`) :
> - `StateCompiler::specificity()` : **échanger** les rangs `PhysicalGroup` ↔ `LogicalGroup` →
>   `user(0) > user_group(1) > workstation(2) > logical_group(3) > physical_group(4) > broadcast(5)`.
> - Mettre à jour les **docblocks** de `StateCompiler` ET `StateMaille` (la chaîne de spécificité y est écrite à
>   l'envers : `…physical_group > logical_group…` → `…logical_group > physical_group…`).
> - **Corriger les tests dépendants de 27.1/27.2** qui assument « physique > logique » — notamment la
>   **résolution de l'imprimante par défaut** (27.2 : « WG physique > logique » → devient « logique > physique »)
>   et tout test wallpaper/exclusive concerné. Ce n'est PAS de la non-régression : le comportement CHANGE
>   sciemment. Relire `PrintersStateProviderTest`/`StateCompilerTest`/wallpaper et ajuster les attentes.
> - **PAS de bump golden/hash** : la précédence n'est PAS encodée dans `state.v1.json` (fixtures hand-authored) ;
>   elle n'affecte que la sélection au runtime (tests provider/compilateur). Vérifier que `FROZEN_STATE_HASH`
>   reste inchangé par CE volet (le bump du hash vient UNIQUEMENT de l'ajout de l'item `registry`, AC6).
>
> **D-ciblage — UI par parc, pivot complet en schéma.** Le geste UI v1 n'expose que le ciblage **par parc**
> (`WorkstationGroup`, physique ET logique). Le pivot `registry_setting_assignables` reste complet (morph
> Workstation/WorkstationGroup/UserGroup/User) → extensible vers poste/groupe-user **sans migration**.

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
   départager par **identité `{hive, path, name}`** : maille la plus spécifique gagne (poste > WG **logique** >
   WG **physique** > broadcast — précédence inversée D-Q3), clés **différentes** s'accumulent. ⚠️ `StateCompiler::selectExclusive()` existant
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
**Then** la maille **la plus spécifique gagne POUR CETTE CLÉ**, selon la précédence **mise à jour D-Q3** :
`poste > WG **logique** > WG **physique** > broadcast` (⚠️ logique > physique — inversion globale, voir D-Q3),
et les **clés distinctes s'accumulent** toutes
**And** la machine d'états §5 (`engine.go`) et la sémantique de drift **STRICT** sont **réutilisées telles
quelles** (zéro modification agent) ; si `selectExclusive()` actuel exclut « 1 item par type » (cas wallpaper),
il est **étendu pour exclure par identité d'item** sans casser le fonctionnement de wallpaper/printers (mais en
intégrant l'inversion de précédence D-Q3 : leurs tests de résolution physique/logique sont **mis à jour**, pas
« non-régressés »).

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
- `StateCompilerTest` : **exclusive par identité de clé** — même clé sur 2 mailles → la plus spécifique gagne
  (**WG logique bat WG physique**, D-Q3) ; clés distinctes → toutes présentes ; **précédence physique/logique
  inversée** propagée aux tests wallpaper/printers `is_default` (attentes MISES À JOUR, pas non-régressées)
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

- [x] **T0 — Cadrage figé + 2 questions résiduelles** (pièges n°1, n°5)
  - [x] Confirmer l'invariant « zéro id de catalogue au payload » comme garde-fou de revue.
  - [x] Geler le **set initial du catalogue** (D-Q1, 3 réglages) et la **sémantique de désactivation**
        (cesser de gérer ; appliqué via seeder + handler qui n'efface jamais une clé absente de la cible).

- [x] **T1 — Migrations : catalogue + pivot** (AC1)
  - [x] `2026_06_16_130000_create_registry_settings_table.php` : `key` unique, `label`, `description`,
        `hive`(16), `path`, `name`, `type`(16), `value`(text), `is_active`, `->comment()` daté 27.3,
        `Schema::hasTable` en garde, `down()` drop. Sérialisation documentée (DWORD décimal, MULTI_SZ JSON).
  - [x] `2026_06_16_130100_create_registry_setting_assignables_table.php` : `foreignId(...)
        ->constrained()->cascadeOnDelete()`, `morphs('assignable')`, `unique(...)`, **calqué** shortcuts.
  - [x] `2026_06_16_130200_seed_registry_settings_catalog.php` : seeder de données IDEMPOTENT (updateOrInsert
        par `key`) du set D-Q1 (HideFileExt, Hidden, EnableLUA) ; `down()` supprime les 3 clés.

- [x] **T2 — Modèle catalogue** (AC2)
  - [x] `app/Models/RegistrySetting.php` : `$fillable`/`$casts`, const `TYPE_REGISTRY='registry'`,
        `HIVE_MACHINE`/`HIVE_USER`, 4 relations `morphedByMany`, `isMachineHive()`. + `RegistrySettingFactory`.

- [x] **T3 — Provider** (AC3, AC4) — *cœur serveur*
  - [x] `AbstractRegistryStateProvider` (logique commune) + `RegistryMachineStateProvider` (HKLM/Machine) +
        `RegistryUserStateProvider` (HKCU/Session) implements `StateProvider`+`KeyedExclusiveProvider` :
        lecture pivot par maille, **compile** chaque réglage en item concret `{hive,path,name,type,value}`,
        candidats BRUTS (D2), `exclusiveKey()={hive,path,name}`.
  - [x] Enregistrés (les DEUX) dans `AgentServiceProvider`.
  - [x] **Étendu `StateCompiler::selectExclusive()`** : marqueur `KeyedExclusiveProvider` → groupement par
        identité de clé, vainqueur résolu par groupe (`resolveExclusiveWinner`), sans régresser wallpaper.
  - [x] **D-Q3** : `specificity()` inversé `logique > physique` + docblocks StateCompiler/StateMaille +
        défaut `printers` aligné (logique gagne) + tests 27.1/27.2 corrigés (pas non-régressés).

- [x] **T4 — Agent Go : handler générique** (AC5)
  - [x] `agent/shared/handler_registry.go` : `RegistryHandler{Ops, Log}` + `Test`/`Apply` purs, interface
        `RegistryOps` injectée, `RegistryValue` typé (DWORD/QWORD/SZ/EXPAND_SZ/MULTI_SZ), NFC, effort maximal.
  - [x] `agent/windows/handler_registry_windows.go` : `registryOps` native (`golang.org/x/sys/windows/registry`
        DÉJÀ dans go.mod) — `rootKey` HKLM/HKCU, CreateKey, lecture/écriture REG_*.
  - [x] Câblage : map compagnon (`registry` HKCU) **+** nouveau `MachineEngine` du service SYSTEM (`registry`
        HKLM) — le service SYSTEM gagne un moteur de convergence machine (premier type machine du canal).
  - [x] `agent/shared/handler_registry_test.go` : set cible, idempotence (2 passes 0 écriture), drift, error
        isolé + isolation inter-clés, HKLM/HKCU, MULTI_SZ, payloads invalides, §5 via moteur, MergeReportItemsByType.

- [x] **T5 — Contrat + golden** (AC6)
  - [x] `docs/agent/contract-v1.md` §7.1 : payload `registry` documenté (5 champs, invariant central, portées).
  - [x] `tests/Fixtures/Agent/state.v1.json` : item `registry` ajouté (portée session, HKCU/HideFileExt).
  - [x] `FROZEN_STATE_HASH`/`frozenStateHash` bumpés croisés `1599cc48…`→`2b49f008…` (item `92730f99…`),
        calculés via le hasher Go réel hôte ; `ContractV1Test` PHP + `hasher_test.go` Go verts (preuve NFR13).

- [x] **T6 — UI** (AC7)
  - [x] `resources/views/pages/parc-settings/registry-settings/index.blade.php` : Livewire SFC, sélecteur de
        parc + toggles par réglage du catalogue, persistance pivot (`syncWithoutDetaching`/`detach`),
        `WithToasts`, Gate `app.customize`. Route nommée `app.parc-settings.registry-settings` (iso overlay).

- [x] **T7 — Tests** (AC8)
  - [x] PHPUnit : `RegistryStateProviderTest` (15), `StateCompilerTest` (keyed exclusive ×4 + chaîne inversée),
        `PrintersStateProviderTest` (logique gagne), `RegistrySettingsPageTest` (5), `ContractV1Test` (hash).
  - [x] Go : `go test ./...` + `go vet` (linux+windows) + cross-compile (10.8 Mo) + `hasher_test.go` croisé — verts.

- [x] **T8 — Documentation + QA** (AC9)
  - [x] `state-providers.md` (section registry + inversion D-Q3) ; `contract-v1.md` §7.1 ; `docs/qa/domains/
        agent.md` `## Story 27.3` (5 scénarios + checklist, append-only) ; ligne 27.3 enrichie `docs/qa/README.md`.

- [x] **T9 — Validation finale** (AC6, AC8)
  - [x] `php -l` sur tous les PHP touchés OK ; grep NFR7 (`ldap|apcu|samba-tool`) sur les providers → vide
        (commentaire toléré) ; grep zéro retrofit legacy (gpo.inc.php/Registry.pol) → aucun fichier.
  - [x] `go test ./...`/vet/cross-compile verts ; PHP registry-related verts hôte (vendor réinstallé). Les
        erreurs `--filter Agent` restantes sont PRÉ-EXISTANTES (ldap_search host + AgentToolService mime/zip).
  - [ ] **Actions /vm (PAS auto, ACTION HENRI)** : `migrate:status` → `php artisan migrate --force`
        (3 migrations : 2 tables + seeder catalogue) ; `php artisan route:cache` + chown www-admin (1 route
        AJOUTÉE : `app.parc-settings.registry-settings`) ; rejouer la suite PHPUnit `--filter Agent`.
  - [ ] **Validation lab (poste Windows) — ACTION HUMAINE (Henri)** : un réglage HKLM activé sur un parc est
        appliqué (SYSTEM) ; un réglage HKCU appliqué au logon (compagnon) ; une valeur modifiée à la main est
        **réimposée** (drift STRICT) ; même clé sur 2 parcs → valeur de la maille la plus spécifique
        (logique > physique).

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

## Questions pour Henri — ✅ TOUTES TRANCHÉES (2026-06-16)

> Réponses figées en tête de story (bloc « DÉCISIONS HENRI — VALIDATION DEV-CYCLE »). Rappel :

1. **✅ RÉSOLU (D-Q1).** Set initial = 3 réglages choisis parmi les GPO SambaEdu (`HideFileExt` HKCU,
   `Hidden` HKCU, `EnableLUA` HKLM). « Désactiver » = **cesser de gérer** (OFF explicite reporté). Bureau à
   distance écarté (non testable facilement). Voir tableau D-Q1.

2. **✅ RÉSOLU (D-Q2).** **Deux providers** (`RegistryMachineStateProvider` HKLM/machine +
   `RegistryUserStateProvider` HKCU/session), **un seul** handler Go générique. Aucune modif du routage
   compilateur.

3. **✅ RÉSOLU (D-ciblage).** UI **par parc** (`WorkstationGroup`), pivot complet en schéma (extensible sans
   migration).

4. **✅ NOUVEAU (D-Q3).** **Inversion globale `logique > physique`** dans `StateCompiler::specificity()` +
   docblocks + correction des tests 27.1/27.2 dépendants. Voir D-Q3.

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

opus (claude-opus-4-8[1m]), effort xhigh.

### Debug Log References

- Go : `go test ./...` + `go vet ./...` + `GOOS=windows go vet ./windows/...` + cross-compile
  `CGO_ENABLED=0 GOOS=windows GOARCH=amd64 go build ./windows` — tous VERTS (binaire 10 797 568 octets).
- PHP : vendor réinstallé sur l'hôte (`composer install --ignore-platform-req=ext-apcu
  --ignore-platform-req=ext-imagick`) + `bootstrap/cache` créé. `ContractV1Test` 5/5, `RegistryStateProviderTest`
  15/15, `RegistrySettingsPageTest` 5/5, compiler+printers nouveaux/modifiés 7/7 — VERTS.
- Hash calculé via le hasher Go RÉEL hôte (test jetable supprimé après) : item registry
  `92730f99ed3e64f81e99c955e64bfb37da8fcc765aa1eb44373c9c4e4af686b5`, état complet
  `2b49f008c6a006de797426e0d65c6e50dd3c7691b611dbeeff086e1f2af3c1ac`.

### Completion Notes List

- **D-Q1** : seeder de données idempotent (`updateOrInsert` par `key`) des 3 réglages exacts (HideFileExt HKCU=0,
  Hidden HKCU=1, EnableLUA HKLM=0). « Désactiver = cesser de gérer » : le handler Go n'efface JAMAIS une clé
  absente de la cible (pas de reset OFF) ; l'UI fait un `detach` du pivot.
- **D-Q2** : DEUX providers serveur (`RegistryMachineStateProvider` HKLM/Machine, `RegistryUserStateProvider`
  HKCU/Session) partageant `AbstractRegistryStateProvider` ; UNE table `registry_settings`, filtre par `hive`.
  UN handler Go générique `registry` (`{hive,path,name,type,value}` seulement). Câblé HKCU dans la map du
  COMPAGNON, HKLM dans un nouveau `MachineEngine` du service SYSTEM.
- **🔴 Couture machine-scope (découverte d'archi)** : le canal agent n'avait JUSQU'ICI aucun handler de portée
  `machine` (wallpaper=session, shortcuts=machine_user, overlay=SYSTEM hors-Engine). Le compagnon IGNORE
  explicitement la portée machine (NFR5). `registry` HKLM est le PREMIER type machine → j'ai ajouté au service
  SYSTEM un `MachineEngine` + `Agent.convergeMachine()` (lit le cache, ItemsFromScope(machine), RunPass,
  applied-state machine sous ProgramData). Best-effort, nil-safe (inerte en test/console). Items de rapport
  drainés au POST /report du cycle.
- **🔴 Unicité de type au rapport (§6)** : les DEUX portées émettent le type `registry` (HKLM via le service,
  HKCU via le drop compagnon) → risque de deux items `registry` dans un même rapport (l'ingestion serveur
  `updateOrCreate` sur `(workstation, type)` en écraserait un). Ajout de `MergeReportItemsByType` (fusion par
  type, pire statut gagne : error>drift>compliant) appliqué juste avant `BuildReport`. No-op sur les types déjà
  uniques.
- **D-Q3** : `StateCompiler::specificity()` inversé `logique > physique` (rangs 3↔4) + docblocks StateCompiler
  ET StateMaille. Défaut `printers` aligné côté provider (`resolveDefaultCupsName` : logique rang 0). Tests
  CORRIGÉS sciemment : `StateCompilerTest::exclusive_specificity_full_chain` (chaîne réordonnée),
  `PrintersStateProviderTest::default_physical_wins_over_logical` → `default_logical_wins_over_physical`.
  Wallpaper provider test inchangé (n'asserte que le tagging, pas la précédence). Hash golden NON impacté par
  D-Q3 (la précédence n'est pas dans les fixtures hand-authored).
- **Exclusive par identité de clé** : marqueur `App\Services\Agent\Contracts\KeyedExclusiveProvider`
  (`exclusiveKey(payload)`). `selectExclusive()` groupe par cette clé et arbitre chaque groupe via
  `resolveExclusiveWinner` (extrait de l'ancien code). Wallpaper (sans marqueur) garde « un seul item gagnant
  pour le type » — non-régression prouvée par test.
- **Invariant central** : aucun `setting_id`/`key`/`label` de catalogue dans le payload — vérifié par test
  (`array_keys($payload) === ['hive','path','name','type','value']`).
- **D-ciblage** : pivot complet (morph WG/Workstation/UserGroup/User) ; UI v1 par parc (WorkstationGroup,
  physique ET logique).
- **AC6** : golden +1 item `registry` (portée session, garde `machine: []` pour ne pas casser le test AC1 du
  contrat) ; `FROZEN_STATE_HASH` PHP + `frozenStateHash` Go bumpés à la MÊME valeur ; tests croisés verts =
  preuve NFR13. Item-count `hasher_test.go` 5→6, `contract_test.go` session 4→5.
- **NFR7** : grep `ldap|apcu|samba-tool` sur les 3 providers = vide (seul docblock toléré). Ciblage = Postgres.
- **Zéro retrofit legacy** : aucun `gpo.inc.php`/`Registry.pol` câblé.

### File List

**Créés :**
- `app/Models/RegistrySetting.php`
- `app/Services/Agent/Contracts/KeyedExclusiveProvider.php`
- `app/Services/Agent/Providers/AbstractRegistryStateProvider.php`
- `app/Services/Agent/Providers/RegistryMachineStateProvider.php`
- `app/Services/Agent/Providers/RegistryUserStateProvider.php`
- `database/factories/RegistrySettingFactory.php`
- `database/migrations/2026_06_16_130000_create_registry_settings_table.php`
- `database/migrations/2026_06_16_130100_create_registry_setting_assignables_table.php`
- `database/migrations/2026_06_16_130200_seed_registry_settings_catalog.php`
- `resources/views/pages/parc-settings/registry-settings/index.blade.php`
- `agent/shared/handler_registry.go`
- `agent/shared/handler_registry_test.go`
- `agent/windows/handler_registry_windows.go`
- `tests/Unit/Services/Agent/RegistryStateProviderTest.php`
- `tests/Feature/Livewire/ParcSettings/RegistrySettingsPageTest.php`

**Modifiés :**
- `app/Enums/StateMaille.php` (docblock D-Q3)
- `app/Providers/AgentServiceProvider.php` (2 providers registry enregistrés)
- `app/Services/Agent/StateCompiler.php` (specificity inversée D-Q3, selectExclusive keyed)
- `app/Services/Agent/Providers/PrintersStateProvider.php` (défaut logique>physique D-Q3)
- `agent/shared/loop.go` (MachineEngine + convergeMachine + MergeReportItemsByType au report)
- `agent/shared/dropcollect.go` (MergeReportItemsByType + statusSeverity)
- `agent/shared/contract_test.go` (session 4→5)
- `agent/shared/hasher_test.go` (frozenStateHash bump + item-count 5→6)
- `agent/windows/companion_windows.go` (handler registry HKCU)
- `agent/windows/main_windows.go` (MachineEngine HKLM)
- `tests/Fixtures/Agent/state.v1.json` (item registry)
- `tests/Unit/Services/Agent/ContractV1Test.php` (FROZEN_STATE_HASH bump)
- `tests/Unit/Services/Agent/StateCompilerTest.php` (keyed exclusive + chaîne inversée)
- `tests/Unit/Services/Agent/PrintersStateProviderTest.php` (logique gagne)
- `routes/web.php` (route registry-settings)
- `docs/agent/contract-v1.md` (§7.1 payload registry)
- `docs/agent/state-providers.md` (section registry + D-Q3)
- `docs/qa/domains/agent.md` (Story 27.3, append-only)
- `docs/qa/README.md` (ligne 27.3 agent)

### Change Log

- 2026-06-16 — Story 27.3 implémentée (T0-T9). Type `registry` au canal agent : catalogue par parc, 2 providers
  serveur / 1 handler Go générique, exclusive par clé, inversion globale logique>physique (D-Q3), couture
  machine-scope (1er type machine : MachineEngine SYSTEM), fusion par type au rapport, golden + hash croisé
  bumpés. Status → review.

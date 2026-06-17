# Story 27.3ter : Registre — valeur par défaut diffusée + override de valeur par parc

Status: review

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

> **ℹ️ NUMÉROTATION.** Troisième story de la série **registre** (`27.3` catalogue + `27.3bis` associations).
> Elle **fait évoluer** le modèle livré par 27.3 (review) : on passe d'un catalogue « activer / cesser de gérer »
> à un catalogue **avec valeur par défaut diffusée à tous les postes** + **override de valeur par parc**.
> **Aucune nouvelle ressource au contrat**, **aucune modification de l'agent Go** : c'est une évolution
> **serveur (provider + schéma) + UI**. Repose sur 27.3 et 27.8 (drift STRICT). Suppose 27.3 mergée/stable.

> **🔴 WORKTREE EN RETARD — état de référence = `main`.** Au moment de la rédaction, le worktree
> `worktree-handlerRegisters` est **5 commits derrière `main`**. Les commits manquants portent l'**état réel
> de l'UI** sur lequel cette story se branche : `e995fb1` (UI registre/associations migrées en **onglets de la
> page WorkstationGroup**, page globale `parc-settings/registry-settings` **supprimée**), `e34902f` (création/
> édition de groupe en **modale réutilisable**), `b7d8fa9` (SHChangeNotify HKCU), `05f2f42`, `9131c36` (27.3 →
> done). **Le développement DOIT se faire contre `main` à jour** : avant tout dev-cycle, **mettre ce worktree
> à niveau sur main** (décision Henri — ne PAS rebaser/merger sans accord). Les chemins de fichiers de cette
> story réfèrent l'état `main`, pas le checkout actuel du worktree.

## ✅ DÉCISIONS HENRI — TRANCHÉES (2026-06-17)

> Cadrage tranché avec Henri en amont de la rédaction (échange de conception). **Procéder sans re-demander**
> sur ces points :
>
> **D1 — Diffusion par défaut = Broadcast, PAS de matérialisation.** Chaque réglage actif du catalogue est
> **émis à TOUTES les machines** via la maille `Broadcast` (rang 5), portant sa **valeur par défaut** (colonne
> `registry_settings.value`). **Rien n'est matérialisé** à la création d'un parc : la création d'un
> `WorkstationGroup` ne touche **aucune** ligne de pivot, l'observer **n'est pas modifié**. Un réglage ajouté au
> catalogue plus tard atteint **tous** les parcs gratuitement (payoff de la couture générique 27.3). Rejeté :
> copier les réglages dans le pivot à la création (backfill des parcs existants + drift catalogue/copies).
>
> **D2 — Le pivot porte un OVERRIDE de valeur.** Une ligne `registry_setting_assignables` ne signifie plus
> « activer la gestion » mais **« ce parc dévie ce réglage vers CETTE valeur »**. Nouvelle colonne `value`
> (nullable, texte, même sérialisation que le catalogue). La précédence existante (`logique > physique >
> broadcast`, D-Q3 de 27.3) fait que **l'override gagne** sur le défaut Broadcast pour cette clé.
>
> **D3 — PAS d'opt-out, PAS de tombstone.** « Tout est valeur. » Il n'existe **aucun** moyen de « cesser de
> gérer » une clé pour un parc. Raison tranchée : « ne plus gérer » ne remettrait pas la valeur d'origine, ça
> **figerait le poste sur sa dernière valeur subie** (état indéterminé — le piège n° 5 de 27.3, désormais
> assumé comme un défaut à corriger, pas une feature). Conséquence directe et **voulue** : **« Retirer » un
> override = revenir à la valeur par défaut** (l'agent **re-converge** le poste vers le défaut Broadcast au
> cycle suivant), PAS « laisser tel quel ». C'est l'inverse exact du `detach` de 27.3.
>
> **D4 — Contrat & agent INCHANGÉS.** Le payload reste `{hive, path, name, type, value}` (5 clés), l'item du
> contrat reste à 4 clés (`type, semantics, payload, hash` — 27.8). Le handler Go `registry` est **déjà**
> générique : il écrit n'importe quelle valeur concrète. **Zéro release agent, zéro `state.v1.json`/hash a
> priori** (à vérifier — voir piège n° 6).
>
> **D5 — DEUX surfaces UI distinctes (override de parc ≠ réglage du défaut).** ⚠️ **État de référence = `main`**
> (voir bloc « WORKTREE EN RETARD » ci-dessous) : sur main, l'UI registre est **déjà** un onglet de la page du
> groupe et la page globale parc-settings **a déjà été supprimée** (commit `e995fb1`). Cette story **évolue**
> l'existant.
> - **Onglet « Registre » DANS la page du parc — ÉVOLUER L'EXISTANT.** Le composant
>   `resources/views/pages/parc/groups/_partials/registry-tab.blade.php` (Livewire SFC scopé `$groupId`, monté
>   par `<livewire:pages::parc.groups._partials.registry-tab :group-id="$group->id">` dans
>   `parc/groups/[id]/index.blade.php`, onglet `registry` déjà câblé dans `setTab()`/tablist) **existe déjà**
>   (toggle catalogue). Le faire passer à : **ne lister QUE les overrides du parc** (valeurs personnalisées) +
>   « ajouter / éditer / retirer », contrôle de saisie **adapté au type** (toggle/sélecteur si `options`,
>   nombre pour DWORD, texte pour SZ, liste pour MULTI_SZ), et **« retirer » = revenir au défaut**. Conforme à
>   `feedback_per_group_property_belongs_on_group_pages`.
> - **Page « réglages serveur » d'édition du DÉFAUT — À CRÉER.** `/admin/settings/registry` (sous
>   `pages/admin/settings/` à côté de `agent`/`gpo`/`profils-itinerants`, route nommée à aligner sur les
>   sœurs) : l'admin **fixe la valeur par défaut** de chaque réglage du catalogue (`registry_settings.value`,
>   le défaut **diffusé partout**) + `options` — même contrôle adapté au type. C'est « fixer le défaut » (la
>   seed rendue éditable). Édite les défauts du catalogue **existant** ; **ne crée pas** de clé brute arbitraire
>   (= éditeur de clés brutes, v2, hors-scope).
> - **La page globale `parc-settings/registry-settings` est DÉJÀ supprimée sur main** (rien à faire).

## ✅ DÉCISIONS HENRI — TRANCHÉES (2026-06-17, suite)

> **D6 — Posture par défaut SÛRE (ex-Q-UAC, RÉSOLU).** La diffusion par défaut (D1) force une posture sur chaque
> poste ⇒ les valeurs seedées doivent être la **posture sûre**, pas « la valeur qu'on voudrait sur un labo » :
> - `EnableLUA` (désactivation UAC) : défaut **`1`** (UAC **activé**). Diffuser `0` partout = trou de sécurité
>   flotte-large **+** casse menu Démarrer/Paramètres sur Win10/11 (UWP/AppContainer) **+** redémarrage requis.
>   « Désactiver l'UAC » devient un **override de parc** délibéré (salles de test/applis legacy → `0`).
> - `HideFileExt` défaut **`0`** (afficher les extensions partout — inoffensif). `Hidden` défaut **`1`**
>   (afficher les fichiers cachés — acceptable flotte-large, choix admin).
> - Le drapeau `apply_by_default` (échappatoire « ne pas diffuser ») **n'est PAS retenu** : tout est diffusé,
>   tout est valeur (D3). Le défaut restant éditable côté serveur (AC4b), aucune impasse.
>
> **D7 — Warning au déclenchement (idée Henri).** Nouvelle colonne catalogue **`warning`** (texte nullable) :
> message d'implications affiché **au moment où l'admin déclenche le réglage** — à l'**ajout/édition d'un
> override** (onglet parc, AC4a) **et** à l'**édition du défaut** (réglages serveur, AC4b). Présenté en encart
> de **confirmation explicite avant persistance** (la modale d'ajout/édition montre le `warning` ; l'admin
> confirme en connaissance de cause). Seedé pour les réglages sensibles — `EnableLUA` : « Désactive l'UAC :
> trou de sécurité (tout processus admin s'exécute élevé sans invite), casse le menu Démarrer / Paramètres sur
> Windows 10/11, nécessite un redémarrage. » `warning = null` ⇒ pas d'encart (réglages inoffensifs).

## ✅ DÉCISIONS HENRI — TRANCHÉES (2026-06-17, en review)

> **D8 — « Geler » un réglage (`overrides_locked`), PAS « désactiver ».** Issu de la review : le toggle
> `is_active` exposé côté serveur était un **faux ami** — désactiver coupe la diffusion, donc fige chaque poste
> sur sa dernière valeur subie (stranding), et le réalignement réel des postes dépend de la **convergence**
> (cycle agent ~1 h, postes éteints) qu'on ne sait pas garantir ici. Décisions :
> - **Le toggle `is_active` est RETIRÉ de l'UI serveur** (on n'expose pas un décommissionnement non sûr).
> - **Nouveau flag `registry_settings.overrides_locked`** (booléen, défaut false) = **« geler »** : verrouille
>   l'ajout de **NOUVEAUX** overrides (retiré de `addableSettings` + garde-fou `openAdd`), **sans rien cesser de
>   gérer**. La **diffusion est INCHANGÉE** (provider intouché : défaut Broadcast + overrides existants toujours
>   émis) → **aucun stranding**. Les parcs qui dévient déjà **gardent** leur override (listé, éditable,
>   retirable). UI serveur : toggle **« Gelé / Ouvert »**.
> - Ça **résout le finding #4** : un override sur réglage gelé n'est pas un orphelin, c'est une déviation
>   grandfatherée toujours gérée → `overrides()` les liste (on ne filtre pas).
> - **Le vrai décommissionnement** (cesser de gérer une clé) reste **hors-scope** → **story de suivi** :
>   mécanisme **générique desired-state** gaté sur la **convergence observée** (canal reporting) + **politique
>   postes injoignables** (timeout/forçage). Réutilisable au-delà du registre.

## Story

En tant que **mainteneur SambaEdu / admin d'établissement**,
je veux que **chaque réglage de registre du catalogue porte une valeur par défaut appliquée à tous les postes,
et qu'on puisse en changer la valeur parc par parc**,
afin de **piloter la posture registre de toute la flotte par défaut (état déterministe, jamais de clé « non
gérée » figée sur une valeur subie) tout en autorisant des déviations ciblées** — sans jamais exposer l'édition
de registre brute ni toucher l'agent.

## Contexte & intention

**D'où on part (27.3, en review).** Le catalogue `registry_settings` existe déjà avec
`{key, label, description, hive, path, name, type, value, is_active}`. Le pivot
`registry_setting_assignables` (morph WG/Workstation/UserGroup/User) existe. Deux providers serveur
(`RegistryMachineStateProvider` HKLM/machine, `RegistryUserStateProvider` HKCU/session) compilent le catalogue
en items concrets `{hive, path, name, type, value}` ; un handler Go `registry` générique les applique. **Mais**
le modèle 27.3 est binaire : un réglage est **géré** (avec la valeur figée du catalogue) **uniquement** s'il
est assigné à un parc (pivot), sinon **non géré du tout**. L'UI ne fait qu'**activer/désactiver** la gestion.

**Ce que cette story change (3 couches, additives) :**
1. **Catalogue** : la colonne `value` devient **la valeur par défaut** (sémantique, pas de migration de colonne).
   Ajout d'un `options` (JSON nullable) pour piloter le contrôle UI des réglages à choix (libellés lisibles).
2. **Pivot** : ajout d'une colonne `value` (texte nullable) = **override de valeur par parc**.
3. **Provider** : émet désormais un candidat **Broadcast** (valeur par défaut) pour **chaque réglage actif** de
   sa ruche, **plus** les candidats par maille portant la **valeur d'override** du pivot. La sélection
   exclusive par clé (existante, inchangée) fait que l'override gagne sur le défaut.
4. **UI** : la page par parc liste **les overrides seulement** + ajouter/éditer/retirer, contrôle **adapté au
   type**. « Retirer » = revenir au défaut.

**Pourquoi c'est bon marché.** Toute la fondation 27.3 est réutilisée : la valeur typée concrète, la
compilation catalogue→payload, l'exclusive par clé `{hive, path, name}`, la précédence `logique > physique >
broadcast`, le handler Go générique. La couture « catalog-first, générique dessous » paie ici exactement comme
prévu : **le contrat et l'agent ne bougent pas** (mémoire `project_registry_catalog_first_generic_underneath`).

**Successeur GPO — direction.** Reconduit de 27.3 : ce handler remplace nativement le canal `Registry.pol`/GPO.
La diffusion d'une posture par défaut + déviations ciblées est précisément ce que faisaient les GPO Registry
(une GPO « par défaut » + des GPO surchargeant par OU/parc). **Zéro retrofit legacy** (mémoires
`project_agent_desired_state_direction`, `project_gpo_dispatcher_static_anchor`).

**Ce que cette story N'EST PAS :**
- Un **éditeur de clés brutes** → v2 (la couture reste ouverte, on ne l'implémente pas).
- Un **opt-out / tombstone** par parc → exclu **par décision** (D3).
- Une modification de l'**agent Go** / du **contrat** / de la **machine d'états §5** / de la **sémantique de
  drift** (STRICT, réutilisée).
- Le **décommissionnement du canal legacy** Registry.pol/GPO → 27.6.

**Zéro prod (mémoire `project_zero_prod_publish_is_test`)** : aucune donnée à préserver. Migration neuve, `down()`
symétrique, pas de back-fill. Les éventuelles assignations 27.3 existantes (pivot sans `value`) deviennent des
overrides à `value=null` ⇒ retombent sur le défaut catalogue = **no-op** (sémantiquement cohérent ; voir AC1).

## ⚠️ Pièges & tensions (lire AVANT de coder)

1. **🔴 INVARIANT CENTRAL reconduit — le catalogue ne fuit JAMAIS au payload.** L'item `registry` porte
   `{hive, path, name, type, value}` **concrets**, **jamais** un `setting_id`/`setting_key`. La nouvelle valeur
   émise est soit l'override du pivot, soit le défaut catalogue — **toujours une valeur concrète**. Un id de
   catalogue dans le payload = régression d'architecture. **Vérifié en AC3.**

2. **🔴 La diffusion Broadcast est un CHANGEMENT DE COMPORTEMENT, pas un ajout neutre.** En 27.3, un réglage
   non assigné **n'émettait rien** (non géré). Désormais il émet un item **Broadcast** sur **toutes** les
   machines. Conséquence : `RegistryStateProviderTest` change de sens (un réglage sans override émet quand même
   un item) ; et la **posture des valeurs seedées s'applique à toute la flotte** (voir D6 : défaut sûr +
   warning D7). Ne pas traiter ça comme une non-régression : le comportement CHANGE sciemment.

3. **« Retirer un override » = revenir au défaut, PAS cesser de gérer (D3).** Le `detach` du pivot ne doit plus
   être présenté/codé comme « cesse de gérer la clé » (sémantique 27.3, **fausse** désormais) : comme le défaut
   Broadcast reste émis, retirer l'override **re-converge** le poste vers la valeur par défaut. **Toute la copy
   UI/toasts/alerte de 27.3 affirmant « la valeur déjà appliquée reste en place » est FAUSSE et doit changer.**

4. **Override émis à la BONNE maille + la précédence existante suffit.** Un override sur un `WorkstationGroup`
   physique → maille `PhysicalGroup` (rang 4) ; logique → `LogicalGroup` (rang 3) ; les deux battent `Broadcast`
   (rang 5). Si un poste est dans une salle (override A) **et** un parc logique (override B) pour la même clé →
   **logique gagne** (D-Q3, déjà en place). **Aucune nouvelle logique de précédence** : on réutilise
   `StateCompiler::selectExclusive()` + `KeyedExclusiveProvider::exclusiveKey()` tels quels.

5. **Broadcast candidate = un candidat SANS assignable.** Le provider doit construire un `StateCandidate` à la
   maille `StateMaille::Broadcast` pour chaque réglage actif de la ruche, `sourceId` = id du réglage, `payload`
   = défaut catalogue. ⚠️ Le `mailleFor()` actuel **throw** sur un `assignable_type` inconnu : la branche
   Broadcast ne passe PAS par `mailleFor()` (pas d'assignable) — séparer la construction « défaut Broadcast »
   de la construction « override par maille ». Vérifier que `StateCandidate` accepte un candidat Broadcast
   (maille seule, pas d'id d'assignable nécessaire). **C'est la principale subtilité serveur de la story.**

6. **Golden/hash : a priori PAS de bump — à VÉRIFIER, pas à supposer.** Le **schéma de payload est identique**
   (5 clés, mêmes types) ⇒ `docs/agent/contract-v1.md` et `ContractV1Test::FROZEN_STATE_HASH` / Go
   `frozenStateHash` ne devraient **pas** bouger. **MAIS** vérifier comment le golden est produit :
   - si `state.v1.json` est **hand-authored statique** (cas 27.3) et la suite ne fait que le **hasher** → **aucun
     changement** (ne PAS toucher le hash).
   - si un test **compile l'état depuis un catalogue seedé** → la diffusion Broadcast **ajoute** des items au
     scénario → fixture + hash **bumpés croisés PHP↔Go** (NFR13). **Relever d'abord la valeur courante du tree.**
   Conclure explicitement dans le Dev Agent Record. **Ne pas bumper « pour faire comme 27.3 ».**

7. **Validation de l'override saisi (NOUVEAU).** En 27.3 les valeurs étaient seedées (jamais saisies). Ici
   l'admin **saisit** une valeur d'override → **valider côté serveur** contre le `type` du réglage : DWORD/QWORD
   = entier (borné), SZ/EXPAND_SZ = chaîne, MULTI_SZ = liste de chaînes (sérialisée comme le catalogue, JSON
   array), et si `options` présent → valeur ∈ valeurs autorisées. Rejet propre (erreur Livewire, pas
   d'exception au render). **Couverture critique** (SQLite n'applique pas les contraintes — mémoire
   `project_sqlite_tests_no_varchar_enforcement`).

8. **NFR7 — zéro AD/APCu/LdapRecord/samba-tool dans le provider.** Reconduit. Le candidat Broadcast se lit en
   **pur Postgres** (catalogue uniquement, aucun ciblage). Le grep `ldap|apcu|samba-tool` sur les providers
   reste **vide** (commentaires tolérés).

9. **Discipline D2.** Le provider rend des candidats **bruts** (Broadcast + par maille). **Aucune** précédence/
   dédup/tri dans le provider — la sélection vit dans `StateCompiler` SEUL. Ne PAS « optimiser » en filtrant
   l'override contre le défaut dans le provider.

10. **VM migrations PAS auto-jouées (mémoire `project_vm_migrations_not_auto_applied`).** Le dev-cycle migre en
    SQLite only. Lister l'action `/vm` : `migrate:status` → `php artisan migrate --force` (dont la migration de
    données D6/D7). **`route:cache` REQUIS** (1 route AJOUTÉE `/admin/settings/registry` — mémoire
    `project_route_cache_vm_ephemeral_test_routes`) + chown www-admin. Pas de `config:cache` (aucun
    `config/*.php`).

11. **Go = hôte uniquement**, **jamais** d'interaction VM depuis un worktree (mémoires
    `project_host_go_toolchain_path`, `feedback_worktree_no_vm_sync`). **Mais a priori aucun code Go ne change**
    (D4) — si une vérif Go est faite, c'est seulement `go test ./...`/`go vet` de **non-régression** (le handler
    générique gère déjà override et défaut indistinctement).

## Acceptance Criteria

### AC1 — Schéma : override de valeur au pivot + métadonnée d'éditeur au catalogue (FR21, FR26)

**Given** le modèle 27.3 en place
**When** les migrations sont jouées
**Then** `registry_setting_assignables` reçoit une colonne **`value`** (texte, **nullable**, même sérialisation
que `registry_settings.value` — DWORD/QWORD décimal, MULTI_SZ JSON array, SZ/EXPAND_SZ littéral), `->comment()`
daté 27.3ter ; **et** `registry_settings` reçoit **`options`** (JSON/texte nullable, choix d'un réglage à valeur
fermée `[{value, label}]` ; `null` = saisie libre selon le `type`) **et** **`warning`** (texte nullable, message
d'implications affiché au déclenchement — D7 ; `null` = pas d'encart)
**And** migrations idempotentes (`Schema::hasColumn` en garde), `down()` symétrique
**And** une ligne de pivot **sans `value` (null)** ⇒ l'item émis **retombe sur le défaut catalogue** (override
inerte) — aucune erreur, sémantique « pas de déviation » (couvre les assignations 27.3 résiduelles).

### AC2 — Provider : défaut Broadcast pour TOUTE clé active + override par maille (FR21, FR5, NFR7)

**Given** le catalogue (réglages actifs) et des overrides de parc
**When** `Registry{Machine,User}StateProvider::itemsFor(TargetContext)` produit ses candidats
**Then** il émet, **en seule lecture Postgres** (NFR7 grep vide) :
- **un candidat `Broadcast`** par **réglage actif de sa ruche**, `payload.value` = **défaut catalogue**
  (`registry_settings.value`), `maille = StateMaille::Broadcast`, `sourceId` = id du réglage ;
- **un candidat par maille** pour chaque **assignation applicable au contexte** (pivot × `TargetContext`),
  `payload.value` = **override** (`registry_setting_assignables.value`) **avec repli sur le défaut catalogue si
  null**, maille étiquetée par l'assignable (Workstation/UserGroup/User/Physical/Logical)
**And** tous les payloads restent **concrets** `{hive, path, name, type, value}` — **jamais** d'id de catalogue
(piège n° 1)
**And** candidats **bruts** (D2) : aucune précédence/dédup dans le provider
**And** `type()='registry'`, `semantics()=Exclusive`, `scope()` cohérent avec la ruche (HKLM→machine,
HKCU→session).

### AC3 — Compilateur : l'override bat le défaut, par identité de clé (FR5, réutilisé)

**Given** une clé `{hive, path, name}` avec un défaut Broadcast et un override de parc (maille spécifique)
**When** le `StateCompiler` compile
**Then** **l'override gagne** (maille `logique`/`physique`/poste/… > `Broadcast`), via la précédence
**existante** (D-Q3, `logique > physique`) et `exclusiveKey()` **inchangés** ; un réglage **sans** override pour
aucune maille du poste → **le défaut Broadcast est émis** (la clé est gérée à sa valeur par défaut)
**And** **aucune** modification de `StateCompiler::selectExclusive()` / `specificity()` / de l'agent (zéro
nouvelle logique de précédence).

### AC4a — Onglet « Registre » de la page du parc : overrides seulement (FR26, FR19) — ÉVOLUER L'EXISTANT

**Given** l'admin ouvre la page d'un parc et l'onglet « Registre » (composant Livewire **existant**
`resources/views/pages/parc/groups/_partials/registry-tab.blade.php`, scopé `$groupId`, monté par
`parc/groups/[id]/index.blade.php` ; Gate `app.customize`, `WithToasts`)
**When** il consulte/édite les réglages du parc
**Then** l'onglet (refondu) **ne liste QUE les overrides** du parc (lignes de pivot avec `value`) : libellé +
**valeur d'override formatée** (lisible selon le type / `options`) + **éditer** + **retirer** — fini le tableau
« catalogue + toggle » de 27.3
**And** un bouton **« Ajouter un réglage »** (modale réutilisable) ouvre le catalogue (réglages **sans** override
pour ce parc, avec leur **valeur par défaut affichée**) ; choisir un réglage + saisir une valeur via un
**contrôle adapté au type** : sélecteur/toggle si `options`, **nombre** pour DWORD/QWORD, **texte** pour
SZ/EXPAND_SZ, **liste** pour MULTI_SZ
**And** la valeur saisie est **validée serveur** contre le `type` (et `options` si présent) avant persistance sur
`registry_setting_assignables.value`
**And** si le réglage porte un **`warning`** (D7), la modale d'ajout/édition l'affiche en encart et **exige une
confirmation explicite** avant de persister
**And** **« Retirer »** = `detach` de l'override = **revenir à la valeur par défaut** : la copy le dit
explicitement (« revient à la valeur par défaut, l'agent réapplique le défaut au cycle suivant ») — **toute la
copy 27.3 « désactiver = cesser de gérer / la valeur reste en place » est SUPPRIMÉE** (piège n° 3)
**And** une mention discrète indique que **« les réglages non listés appliquent leur valeur par défaut »**.

### AC4b — Page « réglages serveur » : édition des valeurs par défaut du catalogue (FR26) — À CRÉER

**Given** l'admin ouvre les **réglages serveur** registre (`/admin/settings/registry`, nouvelle page Livewire
SFC sous `pages/admin/settings/registry/`, route nommée alignée sur les sœurs `agent`/`gpo`, Gate admin
cohérente avec les autres `/admin/settings/*`)
**When** il édite un réglage du catalogue
**Then** il peut **fixer la valeur par défaut** (`registry_settings.value`) — le défaut **diffusé à toute la
flotte** — via le **même contrôle adapté au type** (et éditer `options`/`is_active`), avec **validation serveur**
identique
**And** la page édite les défauts du **catalogue existant** ; elle **ne crée pas** de clé brute arbitraire
(éditeur de clés brutes = v2, hors-scope)
**And** si le réglage porte un **`warning`** (D7), il est affiché avec **confirmation explicite** avant
d'enregistrer le défaut
**And** un libellé indique clairement que **modifier un défaut impacte tous les parcs sans override** sur cette
clé.

### AC5 — Création de parc : aucune matérialisation (D1)

**Given** un `WorkstationGroup` nouvellement créé
**When** l'observer `WorkstationGroupObserver::created()` s'exécute
**Then** **aucune** ligne de `registry_setting_assignables` n'est créée pour ce parc (zéro matérialisation) ;
le parc **hérite automatiquement** de tous les défauts Broadcast — vérifié par test (un poste d'un parc neuf
reçoit les défauts du catalogue sans aucun pivot) ; **l'observer n'est PAS modifié** par cette story.

### AC6 — Contrat & agent inchangés (D4, NFR13)

**Given** l'évolution serveur
**When** on inspecte le contrat et l'agent
**Then** `docs/agent/contract-v1.md` §7.1 (payload `registry`) est **inchangé sur la structure** (5 clés) — au
plus une note précisant « valeur = override de parc sinon défaut catalogue » côté serveur ; **aucune**
modification de `agent/shared/handler_registry.go` / `agent/windows/*` / `engine.go`
**And** le statut du golden/hash est **explicitement conclu** (piège n° 6) : soit inchangé (fixture statique),
soit bumpé croisé PHP↔Go avec preuve — **jamais bumpé sans justification**.

### AC7 — Tests : provider + compilateur + UI + non-régression (NFR13)

**Then** côté **Laravel** :
- `RegistryStateProviderTest` (MIS À JOUR) : (a) réglage actif **sans override** → **candidat Broadcast** au
  défaut catalogue (NOUVEAU sens) ; (b) réglage avec override de parc → candidat maille à la **valeur
  d'override** ; (c) override `value=null` → repli défaut ; (d) **jamais** d'id de catalogue au payload ;
  (e) HKLM→machine / HKCU→session ; (f) lecture seule, zéro AD (NFR7)
- `StateCompilerTest` : **override (maille spécifique) bat défaut (Broadcast)** pour la même clé ; override
  logique bat override physique (D-Q3, réutilisé) ; clés distinctes s'accumulent
- **onglet parc** (test du composant `registry-tab`, MIS À JOUR de l'actuel `RegistrySettingsPageTest`/équiv.) :
  ajouter un override persiste `pivot.value` ; éditer change la valeur ; **retirer** `detach` (revient au
  défaut) ; **validation** rejette une valeur incohérente avec le `type`/`options` ; un réglage avec `warning`
  **exige confirmation** avant persistance ; n'affiche que les overrides
- **page réglages serveur** (nouveau test) : éditer un défaut persiste `registry_settings.value` ; validation ;
  `warning` exige confirmation ; Gate admin (403 sans droit)
- test schéma : colonnes `pivot.value` + `catalogue.options` + `catalogue.warning` présentes, nullable
- `ContractV1Test` : conforme à la conclusion AC6 (hash inchangé OU bumpé croisé prouvé)

**And** côté **agent Go** : **non-régression seule** — `go test ./...` + `go vet` (linux + `GOOS=windows`) +
cross-compile **verts** (le handler générique gère déjà override/défaut indistinctement ; aucun fichier Go
modifié attendu).

### AC8 — Documentation + QA (append-only)

**Then** `docs/agent/state-providers.md` (section `registry`) : décrire le **modèle valeur par défaut diffusée
(Broadcast) + override par parc**, « retirer = revenir au défaut », l'invariant « pas d'id de catalogue au
payload » reconduit
**And** `docs/qa/domains/agent.md` enrichi **append-only** (`## Story 27.3ter` sans renuméroter : défaut
appliqué partout, override de valeur par parc, override bat défaut, retirer override → re-convergence au défaut,
posture UAC sûre par défaut) ; ligne 27.3ter dans `docs/qa/README.md`
**And** note de migration sémantique : « activer/cesser de gérer » (27.3) → « valeur par défaut + override »
(27.3ter) ; le canal legacy reste intouché (meurt en 27.6).

## Tasks / Subtasks

- [x] **T0 — Cadrage figé** (D1-D7) — toutes décisions tranchées (D6 posture sûre, D7 warning). Rien à
      re-demander ; procéder.

- [x] **T1 — Migrations** (AC1, D6, D7)
  - [x] `2026_06_17_090000_add_value_to_registry_setting_assignables.php` : `value` texte **nullable** après
        `assignable_type`, `->comment()` 27.3ter, `Schema::hasColumn` en garde, `down()` drop.
  - [x] `2026_06_17_090100_add_options_and_warning_to_registry_settings.php` : `options` (JSON nullable) +
        `warning` (texte nullable), gardes `Schema::hasColumn` + `down()`.
  - [x] `2026_06_17_090200_seed_registry_defaults_options_and_warnings.php` — migration de données
        **idempotente** (`where('key', …)->update`) : **D6** défaut `EnableLUA`→`1` (posture sûre) ; **D7**
        `warning` d'`EnableLUA` ; **`options`** (HideFileExt/Hidden → Afficher/Masquer ; EnableLUA →
        Activé/Désactivé). `down()` réversible (EnableLUA→0, vide options/warning).

- [x] **T2 — Modèle** (AC1)
  - [x] `RegistrySetting` : `options` (`$casts` array) + `warning` en `$fillable`. Helpers UI
        `hasOptions()`/`allowedOptionValues()`/`optionLabel()`/`hasWarning()`, sans logique de précédence.
  - [x] Pivot : `withPivot('value')` sur `workstationGroups()`.

- [x] **T3 — Provider** (AC2, AC3) — *cœur serveur*
  - [x] `AbstractRegistryStateProvider::itemsFor()` : émission **Broadcast** (un candidat par réglage actif de la
        ruche, défaut catalogue, **sans** `mailleFor`) **+** candidats par maille payload = **override**
        (`pivot.value` aliasé `override_value` ?? défaut). DEUX requêtes séparées (Broadcast / overrides).
  - [x] `payloadFor()` : 2ᵉ paramètre `?string $override` (repli défaut). Invariant 5 clés conservé.
  - [x] **Non touché** : `StateCompiler`/`exclusiveKey()`/`specificity()`. Override bat Broadcast vérifié par
        `StateCompilerTest`.

- [x] **T4a — UI onglet parc** (AC4a) — *évoluer l'existant (main)*
  - [x] `registry-tab.blade.php` refondu : **liste des overrides du parc** (pivot.value non-null) + **modale
        réutilisable « ajouter »** (catalogue restant, défaut affiché) + **éditer** + **retirer** (= revenir au
        défaut). Contrôle adapté au type (options→select ; DWORD/QWORD→number ; SZ/EXPAND_SZ→text ;
        MULTI_SZ→liste). **Validation serveur** (type + options). Encart `warning` + confirmation explicite (D7).
        Copy 27.3 « cesser de gérer » SUPPRIMÉE. `WithToasts`. Onglet/tablist NON re-câblé.

- [x] **T4b — UI page réglages serveur** (AC4b) — *à créer*
  - [x] `resources/views/pages/admin/settings/registry/index.blade.php` (Livewire SFC) : édite le **défaut**
        (`registry_settings.value`) + toggle `is_active`, contrôle adapté au type + validation serveur + warning.
        Route `/admin/settings/registry` (nommée `admin.settings.registry`, dans le groupe `/admin/settings/*`,
        gate `can:server.admin`). Carte de menu dans la section « Agent / Flotte » du landing `/admin/settings`.

- [x] **T5 — Tests** (AC7)
  - [x] `RegistryStateProviderTest` (réécrit : Broadcast sans override + override + repli null + jamais d'id
        catalogue + HKLM/HKCU + NFR7) ; `StateCompilerTest` (+3 : override bat Broadcast, défaut émis sans
        override, logique bat physique sur override) ; `RegistrySettingsPageTest` (réécrit : CRUD override +
        validation + warning confirmé + n'affiche que les overrides) ; `AdminSettingsRegistryPageTest` (nouveau :
        édition défaut + validation + warning + Gate admin 403) ; `RegistryDefaultOverrideMigrationTest`
        (nouveau : schéma nullable + D6/D7/options + down symétrique). `ContractV1Test` INCHANGÉ (vert).
  - [x] Go : `go test ./shared/...` vert (golden hash intact PHP↔Go). Aucun fichier Go modifié.

- [x] **T6 — Contrat/golden : CONCLURE** (AC6) — voir « Conclusion golden/hash » dans le Dev Agent Record.
  - [x] Conclusion : **golden/hash INCHANGÉ** (fixture statique). `contract-v1.md` §7.1 enrichi d'une note
        serveur (« valeur = override sinon défaut »), structure 5 clés inchangée. Aucun bump.

- [x] **T7 — Documentation + QA** (AC8)
  - [x] `state-providers.md` (modèle défaut Broadcast + override) ; `docs/qa/domains/agent.md` `## Story 27.3ter`
        (append-only) ; ligne 27.3ter dans `docs/qa/README.md`.

- [x] **T8 — Validation finale**
  - [x] `php -l` sur tous les PHP touchés (OK) ; grep NFR7 sur les providers → uniquement commentaires (test
        `provider_source_has_no_ad_apcu_samba_dependency` vert) ; grep retrofit legacy → aucun.
  - [x] Go `go test ./shared/...` vert ; PHPUnit registry-related verts hôte (42 + 25 verts ; 2 erreurs
        `StateCompilerTest` PRÉEXISTANTES = LDAP injoignable sur l'hôte, non liées à 27.3ter — voir Notes).
  - [ ] **Actions /vm (PAS auto, ACTION HENRI)** — voir « Actions /vm » dans le Dev Agent Record.
  - [ ] **Validation lab (poste Windows) — ACTION HUMAINE (Henri)** — voir « Validation lab » dans le Dev Agent
        Record.

## Dev Notes

### Périmètre — livré / hors-scope

| Livré (27.3ter) | Hors-scope |
|---|---|
| Valeur par défaut **diffusée** (Broadcast) à tous les postes | **Éditeur de clés brutes** → v2 (couture ouverte, non livré) |
| **Override de valeur par parc** (pivot `value`) | **Opt-out / tombstone** par parc → exclu par décision (D3) |
| UI : overrides seulement + contrôle adapté au type | Modification **agent Go** / **contrat** / **engine §5** / **drift** (réutilisés) |
| Validation serveur de la valeur d'override | Décommissionnement canal legacy Registry.pol/GPO → 27.6 |
| Posture par défaut sûre (Q-UAC) ; QA append-only | Ciblage par CN AD — exclu NFR7 (iso 27.1/27.2/27.3) |

### Le modèle, en une image

```
Catalogue (registry_settings.value = DÉFAUT)  ──émis à────►  maille Broadcast (rang 5)
Pivot (registry_setting_assignables.value = OVERRIDE) ─émis►  maille Logical(3) / Physical(4) / Workstation(2) / …
                                                              │
StateCompiler.selectExclusive() par exclusiveKey {hive,path,name} :  maille la + spécifique GAGNE
   → override présent ? il gagne.   sinon → défaut Broadcast.   « retirer override » ⇒ retombe sur défaut.
```

### Provider — la seule subtilité (piège n° 5)

[Source: `app/Services/Agent/Providers/AbstractRegistryStateProvider.php:95-144` (`itemsFor`),
`:155-164` (`payloadFor`), `:205-225` (`mailleFor` — **throw** sur type inconnu : la branche Broadcast ne doit
PAS y passer) ; `app/Services/Agent/StateCandidate.php` (readonly : `maille, payload, updatedAt, sourceId`) ;
`app/Enums/StateMaille.php:28` (`Broadcast`).]

- L'INNER JOIN actuel sur le pivot ne ramène **que** les réglages assignés. Ajouter une **2ᵉ source** : tous
  les réglages **actifs de la ruche** → candidats Broadcast (défaut). Les deux sources alimentent la même
  `Collection<StateCandidate>` brute (D2).
- Pour les candidats par maille, le `payload.value` devient `pivot.value ?? défaut` (sélectionner
  `registry_setting_assignables.value` dans le `get([...])`).
- `StateCompiler` est **inchangé** : il voit plus de candidats, l'exclusive par clé fait le reste.

### Schéma — référence

[Source: `database/migrations/2026_06_16_130100_create_registry_setting_assignables_table.php` (pivot calque
shortcuts, `morphs`, `unique`) ; `2026_06_16_130000_create_registry_settings_table.php` (catalogue).]

- `registry_setting_assignables.value` : **texte nullable** (cohérent avec `registry_settings.value`). Null =
  pas de déviation (repli défaut). La sérialisation est **identique** au catalogue (le provider applique le
  même `typedValue()`).
- `registry_settings.options` : JSON nullable `[{ "value": "0", "label": "Afficher" }, …]`. Présent ⇒ l'UI rend
  un sélecteur/toggle ; absent ⇒ contrôle inféré du `type`. **N'affecte pas le payload** (détail UI/validation).
- `registry_settings.warning` : texte nullable (D7). Présent ⇒ encart de confirmation au déclenchement (onglet
  parc + réglages serveur) ; absent ⇒ aucun encart. **N'affecte pas le payload** (détail UI).

### UI — DEUX surfaces (état de référence = `main`)

[Source `main` : `resources/views/pages/parc/groups/_partials/registry-tab.blade.php` (onglet **existant**,
Livewire SFC scopé `$groupId`, table catalogue/toggle — **à refondre en overrides**), monté par
`resources/views/pages/parc/groups/[id]/index.blade.php` (onglet `registry` câblé `setTab()`/tablist, **ne PAS
re-câbler**) ; zone réglages serveur `resources/views/pages/admin/settings/{agent,gpo,profils-itinerants}` +
`routes/web.php` groupe `/admin/settings/*` (modèle pour la **nouvelle** page `/admin/settings/registry`) ;
`app/Components/Traits/WithToasts.php` ; `resources/views/components/molecules/modal/index.blade.php`.]

- **Onglet parc** (`registry-tab`) : **refondre** le contenu (overrides seulement + ajouter/éditer/retirer +
  contrôle adapté au type + copy revue). L'onglet/tablist est **déjà câblé sur main**.
- **Page réglages serveur** (`/admin/settings/registry`) : **à créer**, calquée sur une sœur `admin/settings`.
- ⚠️ La copy de l'onglet `main` (alerte + toasts : « Désactiver un réglage = cesser de le gérer / la valeur déjà
  appliquée reste en place ») est **FAUSSE** désormais (piège n° 3). La supprimer : « retirer = revenir à la
  valeur par défaut, l'agent réapplique le défaut ».
- ⚠️ La page globale `parc-settings/registry-settings` a été **supprimée sur main** (commit `e995fb1`) — ne pas
  la chercher ni la recréer.

### Successeur GPO — direction

- Posture par défaut + déviations ciblées = exactement le pattern « GPO par défaut + surcharges par OU/parc ».
  Successeur natif (config servie par SE5, réimposée par l'agent). **Zéro retrofit** `gpo.inc.php`/`Registry.pol`.

### Environnement de dev — règles VM

- Code à la RACINE ; édité sur l'hôte, sync inotify auto, **jamais de sync manuelle**.
- **Go = hôte** (`~/go-toolchain/go/bin`) — mais aucun code Go ne change (non-régression seule). PHPUnit sur `/vm`.
- Migrations → **à jouer sur la VM** (`migrate:status` avant e2e). **Jamais** d'interaction VM depuis un worktree.

### Dépendances

| Story | Rôle pour 27.3ter | Statut | Bloquant ? |
|-------|-------------------|--------|------------|
| 27.3 — catalogue registre | Base directe (catalogue, pivot, 2 providers, handler Go, exclusive par clé, D-Q3) | review | **Prérequis fort** (on étend son code) |
| 27.8 — drift STRICT | Statuts `compliant\|drift\|error` ; item 4 clés ; STRICT inconditionnel | review/done | Prérequis (réutilisé) |
| 27.3bis — associations/UserChoice | Sœur de série, pattern parc-settings/file-associations (validation prédictive) | review | Non (indépendante) |

> **Recouvrement à surveiller** : `AbstractRegistryStateProvider`, `RegistryStateProviderTest` et l'onglet
> `parc/groups/_partials/registry-tab.blade.php` (+ son test) sont **modifiés** — partir de **`main` à jour**
> (worktree en retard, voir bloc en tête), rebaser si 27.3 reçoit des correctifs post-review.

### References

- [Source: `_bmad-output/implementation-artifacts/27-3-handler-registre-catalogue.md`] — story de base (modèle,
  invariants, D-Q1/Q2/Q3, sérialisation).
- [Source: `app/Services/Agent/Providers/AbstractRegistryStateProvider.php`,
  `RegistryMachineStateProvider.php`, `RegistryUserStateProvider.php`] — providers à étendre.
- [Source: `app/Services/Agent/StateCompiler.php` (`selectExclusive`/`specificity` — **inchangés**),
  `Contracts/KeyedExclusiveProvider.php`, `StateCandidate.php`, `TargetContext.php`, `app/Enums/StateMaille.php`].
- [Source: `database/migrations/2026_06_16_130000_*` + `…130100_*` (catalogue + pivot)].
- [Source `main` : `resources/views/pages/parc/groups/_partials/registry-tab.blade.php` (onglet existant à
  refondre), `resources/views/pages/parc/groups/[id]/index.blade.php` (tablist `registry`),
  `resources/views/pages/admin/settings/*` + groupe `/admin/settings/*` de `routes/web.php` (modèle pour
  `/admin/settings/registry`)].
- [Source: `agent/shared/handler_registry.go` (générique — **inchangé**), `tests/Fixtures/Agent/state.v1.json`,
  `tests/Unit/Services/Agent/ContractV1Test.php`, `agent/shared/hasher_test.go` (golden/hash — à conclure)].
- [Source: mémoires `project_registry_catalog_first_generic_underneath`, `project_state_precedence_logical_over_physical`,
  `project_drift_policy_strict_only`, `project_agent_desired_state_direction`, `project_zero_prod_publish_is_test`,
  `feedback_per_group_property_belongs_on_group_pages`].

## Questions pour Henri — ✅ TOUTES TRANCHÉES (2026-06-17)

1. **✅ RÉSOLU (D6).** Posture par défaut sûre : `EnableLUA`→`1` (UAC activé), désactivation = override de parc ;
   `HideFileExt`→`0`, `Hidden`→`1`. Drapeau `apply_by_default` **non retenu**.
2. **✅ NOUVEAU (D7).** Warning au déclenchement (colonne `registry_settings.warning`), affiché en confirmation
   à l'ajout/édition d'override (onglet parc) et à l'édition du défaut (réglages serveur) ; seedé pour `EnableLUA`.

## Recommandation Modèle Dev

**Recommandation : `opus`.**

Justification : story **structurante côté serveur** malgré l'absence de nouveau type/agent. Elle (a) change la
**sémantique de diffusion** (Broadcast pour toute clé active — changement de comportement à raisonner, pas un
ajout neutre), (b) introduit l'**override de valeur** au pivot avec repli, (c) exige une **réécriture d'UI** avec
contrôle adapté au type **et validation serveur** d'entrées utilisateur (nouveau), (d) demande une **conclusion
rigoureuse sur le golden/hash** (ne pas bumper à tort), et (e) porte une **décision de posture de sécurité**
(Q-UAC) aux conséquences flotte-larges. Risque majeur : laisser fuiter un id de catalogue, casser l'exclusive,
ou bumper le hash sans raison. NB : ce **n'est pas** une story contrat/agent — le réflexe « contrat → petit
modèle » (mémoire `feedback_epic23_model_fable5`) ne s'applique pas ; c'est du raisonnement serveur d'altitude.
`opus`.

## Dev Agent Record

### Agent Model Used

`opus` (claude-opus-4-8[1m]).

### Completion Notes List

**Conclusion golden/hash (T6, piège n° 6) — INCHANGÉ, aucun bump, zéro Go.**
La fixture `tests/Fixtures/Agent/state.v1.json` est **hand-authored statique** : `ContractV1Test` la **hashe**
seulement (`hashState($state)`), il ne **compile JAMAIS** l'état depuis un catalogue seedé. La diffusion
Broadcast vit dans `AbstractRegistryStateProvider::itemsFor()`, qui **n'est pas exercé** par le contrat test. La
structure du payload `registry` est identique (5 clés `{hive,path,name,type,value}`). Donc :
`ContractV1Test::FROZEN_STATE_HASH` (PHP) et `frozenStateHash` (Go) **NE BOUGENT PAS** — vérifié : `ContractV1Test`
reste vert sans modification, `go test ./shared/...` vert (golden hash intact PHP↔Go). **Aucun fichier Go
modifié** (le handler `registry` était déjà générique). `contract-v1.md` §7.1 reçoit une note serveur (« valeur =
override de parc sinon défaut catalogue ») sans changer la structure.

**Cœur serveur (T3).** `itemsFor()` fait désormais DEUX requêtes Postgres : (1) tous les réglages actifs de la
ruche → candidat **Broadcast** (`maille = StateMaille::Broadcast`, payload = défaut catalogue, `sourceId` = id du
réglage, **sans** passer par `mailleFor()` qui throw sur type inconnu — piège n° 5) ; (2) le pivot × contexte →
candidat par maille avec `payload.value = override_value (alias) ?? défaut`. Candidats BRUTS (D2). `StateCompiler`
/`exclusiveKey()`/`specificity()` INTOUCHÉS : la précédence existante (logique > physique > broadcast) fait que
l'override bat le défaut pour la clé.

**UI (T4a/T4b).** Onglet parc refondu : liste UNIQUEMENT les overrides (lignes pivot `value` non-null) + modale
réutilisable ajouter/éditer + retirer (= revenir au défaut, copy 27.3 « cesser de gérer » supprimée). Contrôle
adapté au type (select si `options`, number DWORD/QWORD, text SZ/EXPAND_SZ, liste MULTI_SZ) + validation serveur
(type + options bornées) + encart `warning` exigeant confirmation (D7). Page serveur `/admin/settings/registry`
(gate `server.admin`) édite le défaut catalogue (même contrôle/validation/warning) + toggle `is_active` ; carte
de menu dans la section « Agent / Flotte » du landing.

**Tests (T5).** 42 tests registry-related verts (provider 16, onglet 9, page serveur 6, migration 6, contrat 5) +
25 StateCompilerTest verts (dont 3 nouveaux 27.3ter). ⚠️ **2 erreurs PRÉEXISTANTES** dans `StateCompilerTest`
(`target_context_resolves_memberships_from_postgres_relations`, `full_compile_with_real_providers_…`) : ces tests
créent `WorkstationGroup::factory()` SANS `disableSync()` → l'observer dispatche `WorkstationGroupAdSyncJob` →
`ldap_search()` échoue (LDAP injoignable sur l'hôte de dev). **Vérifié pré-existant** : en stashant ma version du
fichier et en relançant l'original, les MÊMES 2 erreurs apparaissent → non liées à 27.3ter. Elles passent sur la
VM (AD joignable). Migrations rejouées sur SQLite fraîche : 6 migrations registry OK, EnableLUA défaut=1,
warning/options seedés, pivot.value présent.

**Décisions/écarts vs la story.** Aucun écart. La diffusion Broadcast utilise une 2ᵉ requête Postgres plutôt que
de réutiliser l'INNER JOIN (un LEFT JOIN aurait dupliqué la logique de repli) ; D2 préservé (candidats bruts,
zéro précédence dans le provider). Le toggle `is_active` (dés)active la diffusion d'un réglage — exposé en bonus
cohérent sur la page serveur (AC4b mentionne `is_active`).

**Questions ouvertes.** Aucune (D1-D7 tranchées). Le toggle `is_active` côté serveur retire un réglage de la
diffusion sans `down`-migration ; conforme à « tout est valeur, pas de tombstone » (un réglage inactif n'émet
plus, ni Broadcast ni override — vérifié par `inactive_setting_emits_nothing`).

### Actions /vm (ACTION HENRI — PAS exécutées depuis le worktree)

1. `php artisan migrate:status` (les 3 migrations 27.3ter doivent être **Pending**).
2. `php artisan migrate --force` — joue les 3 migrations 27.3ter (dont la migration de **données** D6/D7/options
   `2026_06_17_090200_*`, idempotente).
3. `php artisan route:cache` **+** `chown www-admin` sur `bootstrap/cache/routes-*.php` (1 route AJOUTÉE :
   `/admin/settings/registry` — mémoire `project_route_cache_vm_ephemeral_test_routes`).
4. **PAS** de `config:cache` (aucun `config/*.php` touché).
5. Rejouer sur la VM : `--filter Agent` + `--filter Registry` (les 2 erreurs LDAP de `StateCompilerTest` doivent
   alors **passer**, AD étant joignable).

### Validation lab (poste Windows — ACTION HUMAINE Henri)

- Un poste **sans override** applique la **valeur par défaut** (Broadcast).
- Un parc avec **override** applique la valeur déviée.
- **Retirer** l'override → le poste **revient au défaut** au cycle suivant (re-convergence — PAS de valeur figée).
- Même clé sur **salle physique + parc logique** → **le parc logique gagne** (D-Q3).
- `EnableLUA` défaut = **1** (UAC activé) partout ; « désactiver l'UAC » = override de parc + warning confirmé.

### File List

**Créés :**
- `database/migrations/2026_06_17_090000_add_value_to_registry_setting_assignables.php`
- `database/migrations/2026_06_17_090100_add_options_and_warning_to_registry_settings.php`
- `database/migrations/2026_06_17_090200_seed_registry_defaults_options_and_warnings.php`
- `database/migrations/2026_06_17_090300_add_overrides_locked_to_registry_settings.php` (D8 — gel)
- `resources/views/pages/admin/settings/registry/index.blade.php`
- `tests/Feature/Livewire/Admin/AdminSettingsRegistryPageTest.php`
- `tests/Feature/Migrations/RegistryDefaultOverrideMigrationTest.php`

**Modifiés :**
- `app/Services/Agent/Providers/AbstractRegistryStateProvider.php` (Broadcast + override, `payloadFor` override)
- `app/Models/RegistrySetting.php` (`options`/`warning` fillable+cast, `withPivot('value')`, helpers UI)
- `database/factories/RegistrySettingFactory.php` (`options`/`warning` + states `withOptions`/`withWarning`)
- `resources/views/pages/parc/groups/_partials/registry-tab.blade.php` (refonte overrides + modale)
- `resources/views/pages/admin/settings/index.blade.php` (carte de menu Registre)
- `routes/web.php` (route `admin.settings.registry`)
- `tests/Unit/Services/Agent/RegistryStateProviderTest.php` (réécrit pour le modèle Broadcast/override)
- `tests/Unit/Services/Agent/StateCompilerTest.php` (+3 tests 27.3ter)
- `tests/Feature/Livewire/ParcSettings/RegistrySettingsPageTest.php` (réécrit pour CRUD override)
- `docs/agent/contract-v1.md` (note serveur §7.1)
- `docs/agent/state-providers.md` (section registry : défaut Broadcast + override)
- `docs/qa/domains/agent.md` (`## Story 27.3ter`, append-only)
- `docs/qa/README.md` (clause 27.3ter de l'entrée agent + liste des stories)
- `_bmad-output/implementation-artifacts/sprint-status.yaml` (statut → review)

### Change Log

- 2026-06-17 — Story 27.3ter **rédigée puis réalignée sur `main`** (worktree rebasé). Évolution du registre
  27.3 : valeur par défaut diffusée (Broadcast) + override de valeur par parc ; UI = onglet parc EXISTANT
  refondu (overrides seulement) + NOUVELLE page réglages serveur `/admin/settings/registry` (édition du
  défaut). Décisions tranchées : D6 posture UAC sûre (`EnableLUA`→`1`, désactivation = override) ; D7 warning
  au déclenchement (`registry_settings.warning`). Contrat & agent inchangés. **Status: ready-for-dev.**
- 2026-06-17 — **DÉVELOPPÉE** par DEV `opus` (claude-opus-4-8[1m]), ready-for-dev → review. T1-T8 livrées.
  **Schéma** : 3 migrations 27.3ter (pivot `value` nullable ; catalogue `options` JSON + `warning` texte ;
  migration de données idempotente D6 `EnableLUA`→1 + D7 warning + `options` Afficher/Masquer/Activé/Désactivé) —
  jouées en SQLite, OK. **Provider (cœur)** : `AbstractRegistryStateProvider::itemsFor()` émet un candidat
  Broadcast par réglage actif de la ruche (défaut catalogue, sans `mailleFor`) + candidats par maille
  (override `pivot.value` ?? défaut, alias `override_value`) ; `payloadFor($row, ?string $override)` garde les 5
  clés ; `StateCompiler`/`exclusiveKey`/`specificity` INTOUCHÉS. **UI** : onglet parc refondu (overrides seulement
  + modale ajouter/éditer/retirer + contrôle adapté au type + validation serveur + confirmation warning, copy
  « cesser de gérer » supprimée) ; nouvelle page `/admin/settings/registry` (gate `server.admin`, édition du
  défaut + toggle `is_active` + carte de menu). **Contrat/golden CONCLU = INCHANGÉ** : `state.v1.json`
  hand-authored, jamais compilé d'un seed → la diffusion Broadcast ne le touche pas ; `FROZEN_STATE_HASH`
  PHP+Go NON bumpés (`ContractV1Test` vert sans modif, `go test ./shared/...` vert), ZÉRO fichier Go modifié.
  **Tests** : 42 registry verts + 25 StateCompiler verts (3 nouveaux 27.3ter) ; 2 erreurs `StateCompilerTest`
  PRÉEXISTANTES (LDAP injoignable sur l'hôte, vérifié par stash). **Docs/QA** : `state-providers.md`,
  `contract-v1.md` §7.1, `docs/qa/domains/agent.md` (## 27.3ter append-only), `docs/qa/README.md`. Aucun écart vs
  la story. **Status: review.**
- 2026-06-17 — **REVIEW** (sonnet) + 2e avis (opus) + **correctifs**. 6 findings sonnet + 1 opus (N1 overflow
  QWORD) — tous 🟠/🟡, aucun bloquant. Corrigés : #1 (try/catch mort), #2 (N+1 `overrides()` → `whereIn`), #5
  (tests SZ vide ×2 surfaces), #6 (`withPivot` ×3 relations), **N1** (bornage DWORD/QWORD par comparaison de
  chaîne, rejet de l'overflow, ×2 surfaces + 2 tests). Doc review : `_bmad-output/codeReviews/27-3ter.md`.
- 2026-06-17 — **D8 (gel) suite review** : toggle `is_active` **retiré** de l'UI serveur (faux ami =
  décommissionnement non sûr) ; nouveau flag `overrides_locked` (migration `090300`) = « geler » (verrouille les
  NOUVEAUX overrides, diffusion inchangée → pas de stranding) ; UI serveur toggle « Gelé/Ouvert » (`toggleLock`) ;
  `addableSettings()` exclut les gelés + garde-fou `openAdd`. Résout #4. Décommissionnement réel = **story de
  suivi** (gaté convergence). **Tests : 48/48 verts.** **Status: review.**

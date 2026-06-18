# Story 27.12 : Config en capacités — registre repensé (capability-first), fondation + projection registre

Status: review

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

> **ℹ️ NUMÉROTATION.** Réécriture du modèle livré par la série registre (`27.3` catalogue → `27.3ter` défaut
> diffusé + override). On passe d'un **catalogue de réglages de registre** à une **gestion de CAPACITÉS/options
> données aux postes** : la clé de registre devient un **détail de mécanisme caché** (« option 2 »,
> capability-first). **Le `StateCompiler`, le format de contrat, le handler Go `registry` et leurs tests restent
> INCHANGÉS** : le rewrite est borné à l'**authoring + compilation**. Worktree à jour avec `main` (0/0).
>
> **Dépendances de suite (ordre imposé) :** `27.13` (capacité **non-registre** firewall « blocage Internet
> examen », bout-en-bout → ajoute `firewall` au contrat figé + handler Go) et `27.14` (admin local via
> `localgroup`, modèle de compilation session/membership) reposent SUR ce modèle. Elles ne peuvent pas précéder
> 27.12.

## ✅ DÉCISIONS HENRI — TRANCHÉES (échange de conception 2026-06-17 / 2026-06-18)

> Tranché en amont (longue session de conception). **Procéder sans re-demander.**
>
> **D1 — Option 2 (rewrite capability-first), PAS un vernis.** On ne « coiffe » pas `registry_settings` : la
> **capacité devient la table centrale**, le registre devient **une projection parmi d'autres**. Raison : le MVP
> embarque un nombre de capacités, dont des **bundles** (MAJ Windows = ~34 clés) et bientôt du non-registre →
> le modèle natif évite une migration douloureuse. Mémoire `project_config_capabilities_model`.
>
> **D2 — 3 couches.** **Capacité** (intention métier OS-agnostique, ce que l'admin voit) → **Projection**
> (mécanisme par OS : `registry|firewall|localgroup|…`, caché) → **Item de contrat** (concret, mécanisme-typé,
> ce que l'agent reçoit déjà). La traduction intention→mécanisme vit **côté serveur** (projection), JAMAIS dans
> l'agent (l'agent reste bête sur l'intention, compétent sur la réalisation du mécanisme).
>
> **D3 — Contrat & agent INCHANGÉS (registre déjà publié).** `registry` est déjà dans
> `StateContract::RESOURCE_TYPES` (liste FERMÉE/figée NFR12). Cette story **n'ajoute aucun type** → zéro
> changement contrat, zéro changement `agent/shared/handler_registry.go` + `agent/windows/*`, zéro release agent.
> Le payload reste `{hive, path, name, type, value}` (5 clés) ; l'item reste 4 clés (`type, semantics, payload,
> hash` — 27.8). ⚠️ Tout NOUVEAU mécanisme (firewall, localgroup) = ajout additif à la liste figée + handler Go
> + ingestion rapports (24.1) + golden croisé → **hors 27.12** (= 27.13/27.14).
>
> **D4 — Override au niveau de la VALEUR de capacité.** `capability_assignments` porte un override de **valeur de
> capacité** (« ce parc applique telle valeur pour cette capacité »), PAS un override par clé de registre. Modèle
> **Broadcast (défaut diffusé) + override par maille**, repris tel quel de 27.3ter, mais remonté d'un cran. La
> précédence (`logique > physique > broadcast`, D-Q3) reste celle du `StateCompiler`. « Retirer » un override =
> revenir au défaut (re-convergence), PAS « cesser de gérer ».
>
> **D5 — Interpréteur de `spec` (le cœur du modèle).** Une projection registre porte `spec = { "keys": [ {hive,
> path, name, type, value}, … ] }`. Le champ `value` de chaque clé est :
> - un **littéral** (scalaire ou liste) → la donnée est **toujours** émise quand la capacité s'applique ;
> - une **map** valeur-capacité → donnée (objet assoc, ex. `{"on": 0, "off": 1}`) → on cherche la valeur
>   effective de la capacité ; **clé de map absente ⇒ la clé de registre n'est PAS émise** (= cesser de gérer,
>   piège n°5 ; ex. bundle « géré seulement si on » = `{"on": 0}`).
> - **Disambiguïsation map vs littéral-MULTI_SZ** : `array_is_list($value)` → littéral (MULTI_SZ) ; objet assoc →
>   map. Puis **coercition par `type`** (DWORD/QWORD→int, MULTI_SZ→liste de chaînes, SZ/EXPAND_SZ→chaîne) pour le
>   contrat §4.1 (zéro float).
>
> **D6 — Décomposer les grab-bags legacy, pas « 1 GPO = 1 capacité ».** Ex. `se4_optimisations` → **deux**
> capacités honnêtes : « Bureau à distance (RDP) » + « Fichiers hors connexion désactivés ».
>
> **D7 — Retirer l'ancien modèle.** `registry_settings` + `registry_setting_assignables` + les 2 providers
> `Registry{Machine,User}StateProvider` + l'onglet `registry-tab` + la page `/admin/settings/registry` sont
> **superseded**. Migration de données : les 3 réglages 27.3ter (`show_file_extensions`, `show_hidden_files`,
> `disable_uac`/EnableLUA) deviennent des capacités. Zéro prod (`project_zero_prod_publish_is_test`) → on peut
> **dropper** l'ancien après bascule.
>
> **D8 — OS.** `capabilities.applies_to_os` (déclaration UI) + `capability_projections.os` (filtre de
> compilation). MVP = **windows uniquement** (0 projection Linux). Le modèle permet d'ajouter Linux plus tard
> (canal HTTP ou futur `handler_X_linux.go`) **sans reshape**. Mémoire `project_linux_no_gpo_http_scripts`.
>
> **D9 — Arbitrages MVP :** **RDP = capacité registre statique** (`fDenyTSConnections`, dans le lot). **Admin
> local** (localgroup) = **hors 27.12** → 27.14 (toggle simple + modèle membership session-scoped). **Firewall
> examen** = **hors 27.12** → 27.13 (preuve non-registre bout-en-bout).

## Story

En tant que **mainteneur SambaEdu / admin d'établissement**,
je veux **gérer des CAPACITÉS/options données aux postes** (« Afficher les extensions », « MAJ Windows gérées »,
« Bureau à distance »…) **sans manipuler de clés de registre**,
afin de **piloter la posture des postes en vocabulaire métier OS-agnostique**, la mécanique registre étant un
détail d'implémentation caché — réutilisable demain pour d'autres mécanismes (pare-feu, membership) sans changer
ni le contrat ni l'agent.

## Contexte & intention

**D'où on part (27.3ter, en review/done).** `registry_settings` (catalogue) + `registry_setting_assignables`
(override de valeur par parc) + `AbstractRegistryStateProvider` (Broadcast + override par maille, exclusive par
clé `{hive,path,name}`) + handler Go `registry` générique. Le modèle expose **la clé de registre** à l'admin
(hive/path/name/type) — aspect technique exclusif Windows.

**Ce que cette story change.** On **remonte l'abstraction** : l'admin manipule une **capacité** (intention) ;
la **projection** décrit comment elle se matérialise (mécanisme registre, ici) ; le compilateur **expanse**
capacité → items de contrat concrets. Le registre devient une projection — demain firewall/localgroup/file
seront d'autres projections **sans toucher le compilateur ni l'agent**.

**Fondation DÉJÀ POSÉE dans le worktree (untracked, à intégrer/valider par le dev) :**
- `database/migrations/2026_06_18_100000_create_capabilities_table.php`
- `database/migrations/2026_06_18_100100_create_capability_projections_table.php`
- `database/migrations/2026_06_18_100200_create_capability_assignments_table.php`
- `app/Models/Capability.php`, `app/Models/CapabilityProjection.php`

> Le dev **part de ces fichiers** (les relire, ajuster si besoin), puis écrit providers + seed + UI + tests +
> bascule. Ils encodent déjà D1/D2/D4/D5/D8 (cf. docblocks).

**Pourquoi c'est bon marché.** Toute la machinerie de compilation est réutilisée : `StateCandidate`,
`StateCompiler::selectExclusive()` par `exclusiveKey()`, la précédence `logique > physique > broadcast`, le
routage scope (HKLM→Machine, HKCU→Session), le handler Go générique. **Le contrat et l'agent ne bougent pas**
(D3). C'est exactement le payoff de la couture « générique dessous » de 27.3
(`project_registry_catalog_first_generic_underneath`).

**Ce que cette story N'EST PAS :**
- L'**ajout d'un mécanisme non-registre** (firewall/localgroup) → 27.13 / 27.14 (touchent le contrat figé + agent).
- Un **éditeur de clés brutes** → v2 (la couture reste ouverte ; la projection peut porter une clé arbitraire,
  mais l'UI v1 n'expose que des capacités du catalogue).
- Le **verbe `delete`** (`**del.`) ni la **substitution de variables** (`%SE4FS%`) → capacités legacy qui en
  dépendent (telemetry-off via `**del.AllowTelemetry`, imprimantes Point-and-Print) **exclues du lot MVP**
  (piège n°6 / n°7).
- Le **décommissionnement du canal legacy** → reste géré ailleurs (kill-switch existant).

## ⚠️ Pièges & tensions (lire AVANT de coder)

1. **🔴 INVARIANT CENTRAL — ni `id`/`key` de capacité, ni de projection, ne fuit au payload.** L'item `registry`
   reste `{hive, path, name, type, value}` concrets. C'est ce qui garde « éditeur de clés brutes » gratuit ET
   garantit que l'agent ne change pas. **Vérifié en AC.**

2. **🔴 Contrat figé — `registry` seulement.** `StateContract::RESOURCE_TYPES` est FERMÉE (NFR12). Cette story
   **n'y touche pas** (registry déjà publié). Tout `type` non listé serait rejeté par l'ingestion rapports
   (24.1, 422) et par l'agent. → AUCUN mécanisme non-registre ici.

3. **Bundle = une capacité → PLUSIEURS `StateCandidate`.** Une capacité dont la projection a N clés produit N
   candidats (un par clé), tous au même `sourceId` (= `capability.id`) et même `updatedAt`. `exclusiveKey()`
   reste `{hive,path,name}` → le compilateur arbitre **par clé**. Deux capacités définissant la **même** clé →
   collision arbitrée par la récence (tiebreak `id` desc) ; signalé `agent.state.conflict`. C'est un cas réel à
   tester, pas à interdire.

4. **D2 — candidats BRUTS.** Le provider émet Broadcast + overrides bruts, **sans** précédence/tri/dédup.
   `StateCompiler` INTOUCHÉ (il voit plus de candidats, l'exclusive par clé fait le reste). Le grep
   `ldap|apcu|samba-tool` sur les providers reste vide (NFR7).

5. **Map vs littéral (D5).** L'interpréteur doit distinguer une **map** `{"on":0,"off":1}` (objet assoc) d'un
   **littéral MULTI_SZ** `["a","b"]` (liste) via `array_is_list()`. Une clé de map **absente** pour la valeur
   effective ⇒ **clé non émise** (cesser de gérer). Bien couvrir : toggle « 2 états gérés » (`{on,off}`), toggle
   « on-only » (`{on}` → off n'émet rien), littéral toujours-émis, MULTI_SZ. Coercition finale par `type` (zéro
   float).

6. **Verbe `delete` NON supporté.** Le handler `registry` n'efface pas (piège n°5 de 27.3). Les réglages legacy
   en `**del.` (ex. `se4_Bureau`/`**del.AllowTelemetry`, certains quotas) **ne sont PAS portables** tels quels →
   **exclus du lot**. Documenter ; le verbe `delete` est un futur (mémoire `project_legacy_gpo_registry_inventory`).

7. **Substitution de variables NON supportée.** `%SE4FS%`, `###_DOMAIN_###` (imprimantes/redirections) → hors
   lot MVP. `%USERNAME%` passe nativement (REG_EXPAND_SZ).

8. **Bascule = retrait propre de l'ancien (D7).** Migrer les 3 capacités 27.3ter, **basculer
   `AgentServiceProvider`** sur les nouveaux providers, **retirer** anciens providers + onglet `registry-tab` +
   page `/admin/settings/registry` + tests registry obsolètes, **dropper** `registry_settings` +
   `registry_setting_assignables` (zéro prod). Ne pas laisser deux sources de vérité (double émission de la même
   clé → conflit).

9. **Golden/hash — a priori PAS de bump (vérifier, ne pas supposer).** Le payload `registry` est **identique**
   (5 clés). Si `state.v1.json` est hand-authored statique (cas 27.3ter) → `FROZEN_STATE_HASH` PHP+Go
   **inchangés**. Conclure explicitement dans le Dev Agent Record. **Ne pas bumper sans preuve.**

10. **Validation serveur des valeurs saisies.** L'admin saisit une **valeur de capacité** (toggle on/off, enum,
    scalaire). Valider contre `value_type`/`options` (SQLite n'applique pas les contraintes —
    `project_sqlite_tests_no_varchar_enforcement`). La **donnée registre** vient de la `spec` (seed), pas de
    l'admin → pas de saisie de hive/path.

11. **VM (`project_vm_migrations_not_auto_applied`, `project_route_cache_vm_ephemeral_test_routes`).** Lister
    les actions `/vm` : `migrate:status` → `migrate --force` (création tables + seed + drop ancien) ;
    `route:cache` + `chown www-admin` (routes UI capacités modifiées) ; pas de `config:cache`. **Jamais** depuis
    le worktree.

12. **Go = non-régression seule.** Aucun fichier Go modifié attendu (handler `registry` déjà générique). Si
    vérif : `go test ./shared/...` + `GOOS=windows` cross-compile verts.

## Lot iso MVP (capacités à seeder — mécanisme `registry`, os `windows`)

> Source autoritaire des valeurs = inventaire GPO décodé (`project_legacy_gpo_registry_inventory` ; templates
> `/usr/share/sambaedu/gpo/sambaedu-gpo/se4_*/{Machine,User}/Registry.pol`, format **JSON**, lisibles /vm).
> Toutes `value_type=toggle` sauf indication ; `applies_to_os=["windows"]`.

| key | label | défaut | projection registry (clé → value-map) |
|---|---|---|---|
| `show_file_extensions` | Afficher les extensions de fichiers | `on` | HKCU `…\Explorer\Advanced` `HideFileExt` DWORD `{on:0, off:1}` |
| `show_hidden_files` | Afficher les fichiers cachés | `on` | HKCU `…\Explorer\Advanced` `Hidden` DWORD `{on:1, off:0}` |
| `uac_enabled` | Contrôle de compte (UAC) activé | `on` (**warning**) | HKLM `…\Policies\System` `EnableLUA` DWORD `{on:1, off:0}` |
| `windows_consumer_features_off` | Désactiver les fonctionnalités grand public | `on` | HKLM `…\CloudContent` `DisableWindowsConsumerFeatures`+`DisableSoftLanding` DWORD `{on:1}` (on-only) |
| `windows_updates_managed` | Mises à jour Windows gérées | `on` | **bundle** des clés `…\WindowsUpdate[\AU]` (≈34, transcrire depuis `se4_windows-update-ON`) `{on:<littéral>}` (on-only) |
| `offline_files_disabled` | Fichiers hors connexion désactivés | `on` | HKLM `…\NetCache` `NoCacheViewer`/`NoConfigCache`/`NoMakeAvailableOffline`/`Enabled` DWORD `{on:1}` |
| `remote_desktop_enabled` | Bureau à distance (RDP) | `on` | HKLM `…\Terminal Services` `fDenyTSConnections` DWORD `{on:0, off:1}` |
| `windows_copilot_off` | Désactiver Windows Copilot | `on` | HKCU `…\Policies\Windows\WindowsCopilot` `TurnOffWindowsCopilot` DWORD `{on:1}` |
| `onedrive_hidden` | Masquer OneDrive de l'Explorateur | `on` | HKCR `CLSID\{018D5C66-…}` `System.IsPinnedToNameSpaceTree` DWORD `{on:0}` |

> `windows_telemetry_off` (legacy `**del.AllowTelemetry`) et `printers_point_and_print` (substitution `%SE4FS%`)
> **EXCLUS** (pièges n°6/n°7). `windows_widgets_off` optionnel si une clé policy fiable existe (sinon différer).
> ⚠️ HKCR : vérifier le routage scope (machine vs session) du handler — par défaut traiter comme HKLM/machine
> si non géré ; sinon différer `onedrive_hidden`.

## Acceptance Criteria

### AC1 — Schéma : capabilities / projections / assignments
**Given** la fondation (3 migrations) **When** jouées **Then** existent : `capabilities`
(`key` unique, `label`, `description`, `category`, `value_type`, `options` json, `default_value`, `warning`,
`applies_to_os` json, `is_active`, `overrides_locked`), `capability_projections` (`capability_id` fk cascade,
`os`, `mechanism`, `spec` json, unique `(capability_id, os, mechanism)`), `capability_assignments`
(`capability_id` fk cascade, morph `assignable`, `value` text nullable, unique `(capability_id, assignable_id,
assignable_type)`) **And** migrations idempotentes (`Schema::hasTable`), `down()` symétrique.

### AC2 — Modèles
**Then** `Capability` (casts `options`/`applies_to_os` array, `is_active`/`overrides_locked` bool ; relations
`projections()` hasMany + `workstationGroups()/workstations()/userGroups()/users()` morphedByMany via
`capability_assignments` `withPivot('value')` ; helpers `appliesToOs()`, `isToggle/isEnum`, `hasOptions`,
`allowedOptionValues`, `optionLabel`, `hasWarning`) et `CapabilityProjection` (cast `spec` array, `belongsTo
Capability`, constantes mécanismes).

### AC3 — Provider capacités registre (cœur) — D2, D4, D5
**Given** des capacités actives avec projection `(os=windows, mechanism=registry)` + des overrides
**When** `RegistryMachineCapabilityProvider` (HKLM/Machine) et `RegistryUserCapabilityProvider` (HKCU/Session) —
sur base `AbstractCapabilityStateProvider` — produisent leurs candidats
**Then**, en **lecture Postgres pure** (NFR7) : pour chaque capacité applicable, **(a)** un lot de candidats
**Broadcast** = valeur effective `default_value` → `spec.keys` **filtrées par la ruche du provider** → 1
`StateCandidate(maille=Broadcast, payload={hive,path,name,type,value}, sourceId=capability.id)` par clé émise ;
**(b)** pour chaque assignation applicable au `TargetContext`, valeur effective = `assignment.value ?? default_value`
→ mêmes clés → candidats à la maille de l'assignable
**And** la résolution de `value` par clé suit D5 (map/littéral + `array_is_list` + coercition par `type`) ; une
clé de map absente n'émet **rien**
**And** payloads concrets (jamais d'id/key) ; candidats **bruts** (zéro précédence) ; `type()='registry'`,
`semantics()=Exclusive`, `KeyedExclusiveProvider::exclusiveKey()={hive|path|name}`, `scope()` HKLM→Machine /
HKCU→Session.

### AC4 — Compilateur INCHANGÉ : override bat défaut, par clé
**Given** une clé avec défaut Broadcast + override de parc **When** `StateCompiler` compile **Then** l'override
gagne (précédence existante, `exclusiveKey` inchangé) ; sans override → défaut Broadcast émis ; clés distinctes
s'accumulent **And** **aucune** modif de `StateCompiler::selectExclusive()/specificity()` ni de l'agent.

### AC5 — Seed : migration des 3 existants + lot iso
**Then** une migration de données (idempotente, `updateOrInsert` par `key`) crée les capacités du **lot iso**
(tableau ci-dessus) + leurs projections registry, en **migrant** `show_file_extensions`/`show_hidden_files`/
`uac_enabled` depuis les valeurs 27.3ter (EnableLUA défaut `on`=1, warning conservé) **And** `down()` réversible.

### AC6 — UI capacités (remplace l'onglet registre + la page serveur)
**Given** l'admin sur la page d'un parc, onglet **« Options/Capacités »** (Gate `app.customize`, `WithToasts`,
modale réutilisable) **When** il édite **Then** l'onglet liste **les overrides du parc seulement** (capacité +
valeur d'override lisible + éditer + retirer=revenir au défaut), bouton « Ajouter » (capacités sans override,
défaut affiché), **contrôle adapté au `value_type`** (toggle / select si `options` / champ scalaire), **validation
serveur** (value_type/options), encart **`warning`** + confirmation explicite (capacités sensibles, ex. UAC)
**And** une page **réglages serveur** `/admin/settings/capabilities` (Gate `server.admin`, calquée sur les sœurs
`agent`/`gpo`) édite le **défaut diffusé** (`default_value`) + `overrides_locked` (gel), même contrôle/validation/
warning **And** mention « les capacités non listées appliquent leur valeur par défaut »
**And** l'**ancien** onglet `registry-tab` + page `/admin/settings/registry` sont **retirés**.

### AC7 — Bascule & retrait de l'ancien modèle (D7)
**Then** `AgentServiceProvider` enregistre les **nouveaux** providers capacités registre **à la place** de
`Registry{Machine,User}StateProvider` (retirés) **And** `registry_settings` + `registry_setting_assignables` +
`RegistrySetting` + l'UI registre + les tests registry obsolètes sont **supprimés** (drop des tables, zéro prod)
**And** **aucune double émission** d'une même clé (une seule source de vérité).

### AC8 — Contrat & agent inchangés + golden conclu
**Then** `docs/agent/contract-v1.md` §7.1 (payload `registry`) **inchangé sur la structure** (5 clés) ; **aucune**
modif `agent/shared/handler_registry.go`/`agent/windows/*`/`engine.go` ; statut golden/hash **explicitement
conclu** (inchangé attendu — fixture statique) ; `go test ./shared/...` + cross-compile `GOOS=windows` verts.

### AC9 — Tests
**Then** Laravel : `CapabilityRegistryProviderTest` (Broadcast sans override ; override ; repli `value=null` ;
**map on/off** ; **on-only** ; **littéral** ; **MULTI_SZ** ; jamais d'id au payload ; HKLM/HKCU ; bundle = N
candidats ; NFR7) ; `StateCompilerTest` (override bat défaut ; logique bat physique ; clés s'accumulent ;
**collision 2 capacités même clé** → récence) ; tests UI onglet parc + page serveur (CRUD override/défaut +
validation + warning confirmé + n'affiche que les overrides + Gate) ; test schéma (3 tables, nullable, unique) ;
test seed (lot iso + 3 migrés + EnableLUA défaut on) ; `ContractV1Test` vert sans modif. Go : non-régression
verte.

### AC10 — Docs + QA (append-only)
**Then** `docs/agent/state-providers.md` (modèle capacités → projection → item ; interpréteur de `spec` ;
invariant « pas d'id au payload ») ; `docs/qa/domains/agent.md` `## Story 27.12` (append-only) ; ligne dans
`docs/qa/README.md` ; note : le registre n'est plus exposé, c'est une projection.

## Tasks / Subtasks

- [x] **T1 — Fondation** (AC1, AC2) — 5 fichiers untracked relus/validés (migrations + modèles, encodent
      D1/D2/D4/D5/D8). Ajusté : `CapabilityProjection` porte désormais les constantes `HIVE_MACHINE`/`HIVE_USER`
      (foyer canonique, l'ancien `RegistrySetting` étant supprimé). Factories `Capability`/`CapabilityProjection`
      ajoutées. Tests schéma (`CapabilitiesSchemaAndSeedTest`).
- [x] **T2 — Provider** (AC3, AC4) — `AbstractCapabilityStateProvider` (Broadcast + override D4 + interpréteur de
      `spec` D5 map/littéral + `array_is_list` + coercition par type) + `RegistryMachineCapabilityProvider`/
      `RegistryUserCapabilityProvider` (ruche, scope). `StateCompiler` INTOUCHÉ (git diff vide).
- [x] **T3 — Seed** (AC5) — migration de données `2026_06_18_100300_seed_capabilities_iso_lot` : lot iso (9
      capacités) + migration des 3 existants (EnableLUA défaut on + warning). Bundle WindowsUpdate transcrit
      **PARTIELLEMENT** (source VM inaccessible — voir Dev Agent Record). `**del.`/substitution exclus.
- [x] **T4 — UI** (AC6) — onglet « Options / Capacités » (parc) + page `/admin/settings/capabilities` ; onglet/page
      registre retirés ; lien settings index + route + onglet de la page parc basculés.
- [x] **T5 — Bascule & retrait** (AC7) — `AgentServiceProvider` bascule sur les nouveaux providers ; anciens
      providers/modèle/factory/UI/tests registry supprimés ; migration de DROP `2026_06_18_100400` des tables
      `registry_*` (idempotente, `down()` recrée).
- [x] **T6 — Contrat/golden** (AC8) — conclu INCHANGÉ (preuve : `ContractV1Test` vert sans modif,
      `FROZEN_STATE_HASH` intact, `git status` aucun fichier Go/golden/contrat touché). Go non-régression verte.
- [x] **T7 — Tests** (AC9) — 5 fichiers PHP (46 tests / 220 assertions verts en isolation, post-review) + Go non-régression.
- [x] **T8 — Docs/QA** (AC10) — `docs/agent/state-providers.md` (section registry réécrite capability-first) ;
      `docs/qa/domains/agent.md` `## Story 27.12` (append) ; `docs/qa/README.md` (ligne agent).
- [x] **T9 — Validation** — `php -l` clean sur tous les fichiers ; grep NFR7 vide (hors commentaire) ; payload
      `{hive,path,name,type,value}` seul ; PHPUnit verts. **Actions /vm listées** dans le Dev Agent Record (PAS
      exécutées depuis le worktree).

## Dev Notes

### References
- [Source: `_bmad-output/implementation-artifacts/27-3ter-registre-defaut-diffuse-override-parc.md`] — modèle
  Broadcast+override, exclusive par clé, posture sûre/warning (réutilisés, remontés au niveau capacité).
- [Source: `app/Services/Agent/Providers/AbstractRegistryStateProvider.php`] — patron exact à porter sur
  capacités (Broadcast + override + `mailleFor` + `typedValue`).
- [Source: `app/Services/Agent/StateCompiler.php` (INCHANGÉ), `Contracts/StateProvider.php`,
  `Contracts/KeyedExclusiveProvider.php`, `StateCandidate.php`, `TargetContext.php`, `StateContract.php`
  (RESOURCE_TYPES figée), `app/Enums/{StateMaille,StateScope,ResourceSemantics}.php`].
- [Source: `app/Providers/AgentServiceProvider.php`] — enregistrement des providers (basculer).
- [Source `main` : `resources/views/pages/parc/groups/_partials/registry-tab.blade.php` (à remplacer),
  `pages/admin/settings/{agent,gpo}` (modèle page serveur), `WithToasts`, modale réutilisable].
- [Source: fondation déjà écrite — `app/Models/Capability.php`, `CapabilityProjection.php`, 3 migrations
  `2026_06_18_1000*`].
- [Source: mémoires `project_config_capabilities_model`, `project_legacy_gpo_registry_inventory`,
  `project_registry_catalog_first_generic_underneath`, `project_state_precedence_logical_over_physical`,
  `project_zero_prod_publish_is_test`, `feedback_per_group_property_belongs_on_group_pages`,
  `project_vm_migrations_not_auto_applied`].

### Périmètre — livré / hors-scope
| Livré (27.12) | Hors-scope |
|---|---|
| Modèle capacités (3 tables + 2 modèles) | Mécanisme **firewall** (examen) → **27.13** (contrat + Go) |
| Provider(s) capacités registre + interpréteur `spec` | Mécanisme **localgroup** (admin local) → **27.14** |
| Seed lot iso + migration des 3 existants | Verbe `delete` (`**del.`) + substitution `%SE4FS%` |
| UI capacités (onglet parc + page serveur) | Éditeur de clés brutes → v2 |
| Bascule + retrait de l'ancien registre | Projection **Linux** (canal HTTP) → futur, sans reshape |

## Recommandation Modèle Dev
**`opus`.** Rewrite structurant côté serveur (modèle central, interpréteur de `spec`, bascule sans double
émission, retrait propre de l'ancien, UI avec validation). Risque majeur = fuite d'id au payload, casser
l'exclusive, double source de vérité, bump de hash injustifié. Pas une story contrat/agent (le réflexe
« contrat → petit modèle » ne s'applique pas).

## Dev Agent Record

**Modèle dev :** `opus` (claude-opus-4-8[1m]). **Date :** 2026-06-18. Worktree, tests LOCAL (SQLite + vendor présent), zéro interaction VM.

### Décisions d'implémentation
- **Fondation (T1).** Les 5 fichiers untracked sont corrects et encodent D1/D2/D4/D5/D8. **Ajustement** : comme l'ancien
  modèle `RegistrySetting` (qui portait `HIVE_MACHINE`/`HIVE_USER`) est supprimé, j'ai déplacé ces deux constantes sur
  `CapabilityProjection` (foyer canonique du mécanisme registry). Les nouveaux providers les consomment via
  `CapabilityProjection::HIVE_*`. `type()` du provider = `CapabilityProjection::MECHANISM_REGISTRY` (= `registry`).
- **Interpréteur de `spec` (D5).** `expand()` filtre les `spec.keys[]` par la ruche du provider, puis `resolveKeyValue()` :
  `is_array && array_is_list` ⇒ littéral MULTI_SZ (toujours émis) ; `is_array && !array_is_list` ⇒ MAP valeur-capacité
  (clé absente ⇒ sentinelle `UNMANAGED` ⇒ clé NON émise) ; scalaire ⇒ littéral. Puis `typedValue()` coerce par `type`
  (DWORD/QWORD→int, MULTI_SZ→liste de chaînes, SZ/EXPAND_SZ→chaîne). **Zéro float.** Accepte une donnée déjà typée
  (map JSON `{"on":0}` donne un int) comme une donnée texte (littéral de seed).
- **Broadcast + override (D4).** Provider émet, par capacité applicable : (1) lot Broadcast pour `default_value`
  (sourceId = capability.id, sans `mailleFor`) ; (2) lot par maille par assignation, valeur effective =
  `assignment.value ?? default_value`. Candidats **BRUTS**. La précédence existante du `StateCompiler` (logique > physique
  > broadcast, exclusive par clé `{hive,path,name}`) fait que l'override bat le défaut — compilateur INTOUCHÉ.
- **Routage HKCR (piège « onedrive_hidden »).** Le handler Go `registry` ne route que HKLM (SYSTEM) / HKCU (compagnon).
  La clé HKCR `CLSID\{018D5C66-…}` est **transcrite au seed en `hive=HKCU, path=Software\Classes\CLSID\…`** (vue
  per-user de HKCR = HKLM+HKCU\Software\Classes), émise en portée session par `RegistryUserCapabilityProvider`. Pas de
  différé : la capacité `onedrive_hidden` est seedée et fonctionnelle via la branche per-user.
- **Bascule propre (D7/AC7).** Une seule source de vérité : les anciens providers/modèle/factory/UI/tests registry sont
  supprimés, l'`AgentServiceProvider` n'enregistre que les nouveaux providers, et une migration de DROP additive retire
  les tables `registry_*`. Pas de double émission de clé.

### Golden / contrat — CONCLUSION : INCHANGÉ (avec preuve)
- **Aucun fichier Go modifié** : `git status` ne liste aucun `agent/**.go` (vérifié explicitement).
- **Aucun golden/contrat touché** : ni `tests/Fixtures/Agent/state.v1.json`/`report.v1.json`, ni `StateContract`
  (RESOURCE_TYPES figée — `registry` déjà publié), ni `StateCompiler`, ni `StateHasher`.
- **`ContractV1Test` : 5/5 verts SANS modification** (le `FROZEN_STATE_HASH` PHP `283f391d…` est intact — le golden est
  une fixture statique hand-authored, le payload `registry` reste 5 clés identiques).
- **Go : `go test ./shared/...` OK** (inclut le test croisé du hash figé Go — donc le golden Go est inchangé) +
  cross-compile `GOOS=windows go build ./windows` OK.
- **Conclusion : pas de bump.** Le payload `registry` est identique 5 clés ; le rewrite est borné à l'authoring +
  compilation côté serveur. Bumper aurait été injustifié.

### Bundle WindowsUpdate (`windows_updates_managed`) — À COMPLÉTER par Henri
- La source autoritaire `/usr/share/sambaedu/gpo/sambaedu-gpo/se4_windows-update-ON/Machine/Registry.pol` est **sur la
  VM, inaccessible depuis le worktree**, et **aucune copie locale n'existe** dans le repo (recherche thorough : pas de
  `Registry.pol` JSON dans `resources/`, `docs/`, `database/`, `tests/Fixtures/`, `_bmad-output/`).
- **Clés transcrites (transcription PARTIELLE, on-only `{on:…}`)** dans la `spec` de la projection : `NoAutoUpdate=0`,
  `AUOptions=4`, `ScheduledInstallDay=0`, `ScheduledInstallTime=3`, `NoAutoRebootWithLoggedOnUsers=1` (sous
  `…\WindowsUpdate\AU`) + `ElevateNonAdmins=0` (sous `…\WindowsUpdate`). Ce sont des clés Windows Update / AU canoniques
  sûres — **6 clés sur ≈34**.
- **À COMPLÉTER** : les ≈28 clés restantes du bundle `se4_windows-update-ON` (WSUS `WUServer`/`WUStatusServer`,
  `UseWUServer`, fenêtres de maintenance, etc.) doivent être ajoutées par Henri depuis la source VM (le seed est
  idempotent `updateOrInsert` → simple rejouer après complétion de la `spec`). Cf. scénario QA 27.12.8.

### Exclus du lot (documenté, pièges n°6/n°7)
- `windows_telemetry_off` (verbe legacy `**del.AllowTelemetry` — le handler `registry` n'efface pas) : EXCLU.
- `printers_point_and_print` (substitution `%SE4FS%` non supportée) : EXCLU.

### Résultats de tests (LOCAL host, SQLite + vendor worktree)
> ⚠️ **Correction post-review (2026-06-18).** Les compteurs initialement écrits ici (« 47 tests / 278
> assertions ») étaient erronés — finding #5 de la review. Compteurs RÉELS recomptés en exécutant :
> **42 tests / 179 assertions** avant corrections ; **46 tests / 220 assertions** après l'ajout des 4
> tests de corrections (#1/#7 garde serveur ×2 ; #2 symétrie + WU managed-only ×2). Détail ci-dessous à jour.
- **5 fichiers 27.12 en isolation : 46 tests / 220 assertions — TOUS VERTS.**
  - `CapabilityRegistryProviderTest` (16) : Broadcast sans override ; override ; repli value=null ; map on/off ;
    on-only (clé non émise) ; littéral ; MULTI_SZ ; jamais d'id au payload ; HKLM/HKCU ; bundle = N candidats ;
    multi-maille ; exclusiveKey casse ; NFR7.
  - `CapabilityRegistryCompilationTest` (4) : bout-en-bout via le vrai `StateCompiler` — override bat défaut ; logique
    bat physique ; clés s'accumulent ; collision 2 capacités même clé → récence.
  - `CapabilitiesSchemaAndSeedTest` (11) : 3 tables + colonnes + unique + nullable ; tables registry droppées ; lot iso
    seedé ; UAC défaut on + warning ; 3 migrés ; exclus non seedés ; **+ symétrie on/off (décision #2) ; WU managed-only**.
  - `CapabilitiesTabTest` (9) : Gate app.customize (403) ; n'affiche que les overrides ; ajout/validation/warning/
    retrait ; capacité gelée non addable ; **+ garde serveur #1/#7 (gelée via wire:call direct refusée ; inactive refusée)**.
  - `AdminSettingsCapabilitiesPageTest` (6) : Gate server.admin (403) ; édition défaut/validation/warning ; gel ;
    catalogue complet.
- **`ContractV1Test` : 5/5 verts sans modif** (AC8).
- **Go : `go test ./shared/...` OK ; `GOOS=windows go build ./windows` OK** ; aucun fichier Go modifié.
- **NFR7** : `grep -nE 'ldap|apcu|samba-tool'` sur les 3 providers ⇒ une seule occurrence, dans un **commentaire**
  (docblock) ; le test `provider_source_has_no_ad_apcu_samba_dependency` (qui strippe les commentaires) est vert.
- **Échecs PRÉ-EXISTANTS (hors 27.12), confirmés sur HEAD propre via `git stash`** : la suite Agent complète lancée en
  un seul run a une cascade « cannot start a transaction within a transaction » (≈224 erreurs) due à
  `WorkstationGroupAdSyncJob` qui tente un `ldap_search` sur un hôte sans LDAP → transaction non rollback →
  cascade. Identique sur HEAD propre (250 tests, 224 erreurs). `AppConfigStateProviderTest` a 1 échec
  (`ExtensionSettings`) AUSSI pré-existant (git diff vide sur le service/test). Convention hôte = lancer les tests **par
  fichier** ; mes 5 fichiers passent ainsi 46/46.

### Corrections post-review (2026-06-18)
> Cf. `_bmad-output/codeReviews/27-12.md` (review sonnet + 2e avis opus). Corrigé automatiquement :
- **#1+#7 (🟠)** — `saveOverride()` ne contrôlait `overrides_locked`/`is_active` que côté front ;
  `editingCapabilityId`/`isEditing` étant publics, un client pouvait écrire un override sur une
  capacité gelée/inactive via `$wire.set` + `$wire.call`. Garde serveur ajouté : recharge filtrée
  `is_active=true` + refus d'un NOUVEL override sur capacité gelée, dérivé de l'**existence en base**
  (pas du flag client). 2 tests de non-régression.
- **#6 (🟡)** — docblock `create_capability_projections_table.php` aligné sur les entiers `{"on":0,"off":1}`.
- **#8 (🟡, trouvé opus)** — note `state-providers.md` : `value:[]` (liste vide) = MULTI_SZ vide ÉMIS,
  pas « cesser de gérer » (`array_is_list([])===true`).
- **#5 (🟡)** — compteurs de tests corrigés (ci-dessus).
- **#2 (🟡) — TRANCHÉ Henri (2026-06-18)** : « si on peut mettre on, on doit pouvoir mettre off ». Les 4
  capacités dont un « off » a un sens registre deviennent **symétriques** (`off` réécrit la valeur qui
  réactive : Consumer Features/SoftLanding→0, NetCache No*→0 & Enabled→1, Copilot→0, OneDrive→1).
  `windows_updates_managed` reste la **vraie exception on-only** (off bundle = verbe `delete`, hors MVP)
  → option « off » trompeuse retirée (`options=[on]` « Géré »). 2 tests de seed ajoutés.
- **Non corrigés (non-bugs)** : #3 (`orWhere` iso-legacy, copié de l'ancien provider) ; #4 (`null→0`
  défensif, aucun seed concerné).

### Actions /vm pour Henri (PAS exécutées — worktree)
1. `cd /var/www/sambaedu-reload && php artisan migrate:status` puis `php artisan migrate --force` :
   crée `capabilities`/`capability_projections`/`capability_assignments`, seede le lot iso, **droppe**
   `registry_settings` + `registry_setting_assignables`.
2. `php artisan route:cache` + `chown www-admin:www-admin bootstrap/cache/routes-v7.php`
   (route `/admin/settings/registry` → `/admin/settings/capabilities` ; onglet parc renommé). **Pas de `config:cache`.**
3. **Fantômes inotify** (`project_inotify_no_delete_sync`) : les fichiers supprimés (anciens providers, modèle
   `RegistrySetting`, factory, vues registry, tests registry) restent peut-être sur la VM après sync. À nettoyer
   manuellement côté VM si besoin (demander avant) :
   `app/Models/RegistrySetting.php`, `app/Services/Agent/Providers/{AbstractRegistry,RegistryMachine,RegistryUser}StateProvider.php`,
   `database/factories/RegistrySettingFactory.php`, `resources/views/pages/admin/settings/registry/`,
   `resources/views/pages/parc/groups/_partials/registry-tab.blade.php`, les 4 tests registry supprimés.
4. **Compléter le bundle WindowsUpdate** depuis la source VM (cf. section dédiée ci-dessus).

## File List

### Créés
- `app/Services/Agent/Providers/AbstractCapabilityStateProvider.php`
- `app/Services/Agent/Providers/RegistryMachineCapabilityProvider.php`
- `app/Services/Agent/Providers/RegistryUserCapabilityProvider.php`
- `database/factories/CapabilityFactory.php`
- `database/factories/CapabilityProjectionFactory.php`
- `database/migrations/2026_06_18_100300_seed_capabilities_iso_lot.php`
- `database/migrations/2026_06_18_100400_drop_registry_settings_tables.php`
- `resources/views/pages/admin/settings/capabilities/index.blade.php`
- `resources/views/pages/parc/groups/_partials/capabilities-tab.blade.php`
- `tests/Unit/Services/Agent/CapabilityRegistryProviderTest.php`
- `tests/Unit/Services/Agent/CapabilityRegistryCompilationTest.php`
- `tests/Feature/Migrations/CapabilitiesSchemaAndSeedTest.php`
- `tests/Feature/Livewire/Parc/CapabilitiesTabTest.php`
- `tests/Feature/Livewire/Admin/AdminSettingsCapabilitiesPageTest.php`
- _(fondation untracked, conservée telle quelle + ajustée)_ : `app/Models/Capability.php`,
  `app/Models/CapabilityProjection.php`, `database/migrations/2026_06_18_100000_create_capabilities_table.php`,
  `…_100100_create_capability_projections_table.php`, `…_100200_create_capability_assignments_table.php`.

### Modifiés
- `app/Providers/AgentServiceProvider.php` (bascule providers registry → capability-first)
- `app/Models/Capability.php` (docblock `RegistrySetting` ; constantes inchangées)
- `app/Models/CapabilityProjection.php` (ajout constantes `HIVE_MACHINE`/`HIVE_USER`)
- `app/Models/AppCustomization.php` / `app/Models/FileAssociation.php` (docblock : `RegistrySetting::TYPE_REGISTRY`
  → `CapabilityProjection::MECHANISM_REGISTRY`)
- `routes/web.php` (`/admin/settings/registry` → `/admin/settings/capabilities`)
- `resources/views/pages/admin/settings/index.blade.php` (carte « Registre » → « Capacités »)
- `resources/views/pages/parc/groups/[id]/index.blade.php` (onglet « Réglages registre » → « Options / Capacités »)
- `docs/agent/state-providers.md` (section `registry` réécrite capability-first + interpréteur de `spec`)
- `docs/qa/domains/agent.md` (append `## Story 27.12` + checklist)
- `docs/qa/README.md` (ligne domaine agent + 27.12)
- `_bmad-output/implementation-artifacts/sprint-status.yaml` (entrée 27-12 → review)

### Supprimés
- `app/Models/RegistrySetting.php`
- `app/Services/Agent/Providers/AbstractRegistryStateProvider.php`
- `app/Services/Agent/Providers/RegistryMachineStateProvider.php`
- `app/Services/Agent/Providers/RegistryUserStateProvider.php`
- `database/factories/RegistrySettingFactory.php`
- `resources/views/pages/admin/settings/registry/index.blade.php` (+ dossier vidé)
- `resources/views/pages/parc/groups/_partials/registry-tab.blade.php`
- `tests/Unit/Services/Agent/RegistryStateProviderTest.php`
- `tests/Feature/Livewire/Admin/AdminSettingsRegistryPageTest.php`
- `tests/Feature/Livewire/ParcSettings/RegistrySettingsPageTest.php`
- `tests/Feature/Migrations/RegistryDefaultOverrideMigrationTest.php`

> ⚠️ Les migrations historiques `2026_06_16_1300*` / `2026_06_17_0900*` (création/seed registry) sont **conservées**
> (jamais réécriture d'historique) ; la table est retirée par la migration de DROP additive `…_100400`.

## Change Log
- 2026-06-18 — Story **DÉVELOPPÉE** (DEV opus claude-opus-4-8[1m]), ready-for-dev → review. T1-T9 livrées. Modèle
  capability-first : `AbstractCapabilityStateProvider` (interpréteur de `spec` D5 map/littéral + coercition par type,
  Broadcast + override D4) + 2 providers HKLM/HKCU ; seed lot iso 9 capacités (3 migrés + EnableLUA défaut on +
  warning ; bundle WindowsUpdate PARTIEL — source VM inaccessible) ; UI onglet parc « Options/Capacités » + page
  serveur `/admin/settings/capabilities` ; bascule `AgentServiceProvider` + retrait complet de l'ancien registre
  (modèle/providers/factory/UI/tests + migration DROP). **CONTRAT + agent + golden INCHANGÉS** (ContractV1 vert sans
  modif, `FROZEN_STATE_HASH` intact, zéro fichier Go modifié, `go test ./shared/...` + cross-compile windows verts).
  46 tests / 220 assertions verts (isolation, post-review). NFR7 vide. Invariant central vérifié (payload 5 clés). Actions /vm
  listées (migrate + route:cache + chown + complétion bundle WindowsUpdate + cleanup fantômes inotify). **Status: review.**
  CODE REVIEW (sonnet + 2e avis opus, `codeReviews/27-12.md`, statut done) : 1 finding 🟠 corrigé (#1/#7 garde
  serveur `saveOverride` + 2 tests), 3 🟡 doc/cosmétique corrigés (#5/#6/#8), 2 non-bugs laissés (#3/#4), 1
  décision design TRANCHÉE Henri (#2 : capacités rendues symétriques on/off, WU seul on-only honnête).
- 2026-06-18 — Story **rédigée** (orchestrateur, à partir d'une longue session de conception avec Henri).
  Capability-first (option 2), registre = projection, contrat/agent inchangés. Fondation (3 migrations + 2
  modèles) déjà posée dans le worktree. Firewall e2e (non-registre) = 27.13 (dépend de 27.12) ; admin local =
  27.14. **Status: ready-for-dev.**

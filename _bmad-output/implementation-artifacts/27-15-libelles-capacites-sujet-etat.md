# Story 27.15 : Libellés de capacités — convention « sujet + état » (lever l'ambiguïté libellé × statut)

Status: ready-for-dev

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

> **ℹ️ NUMÉROTATION & PORTÉE.** Suivi direct de **27.12** (« config en capacités — capability-first », qui a semé
> le LOT ISO). Story **purement AUTHORING / AFFICHAGE** : on ne corrige QUE la couche de texte vue par l'admin
> (`capabilities.label` + `capabilities.options[].label`). **Zéro changement de comportement** : `default_value`,
> les `value` des options (`'on'`/`'off'`), et **toutes** les projections (`capability_projections.spec`, maps
> registre `{on:1,off:0}`, hives) restent **identiques**. Donc **zéro impact** contrat / agent / release. Aucune
> nouvelle release agent. `value_type` reste `toggle` partout.

## Contexte & problème

La page **`/admin/settings/capabilities`** (`resources/views/pages/admin/settings/capabilities/index.blade.php`)
et l'**onglet « Capacités » du parc** (`resources/views/pages/parc/groups/_partials/capabilities-tab.blade.php`)
affichent chaque capacité par **deux chaînes indépendantes** :

1. le **libellé** = `capabilities.label` (ex. `"Désactiver le Microsoft Store"`) ;
2. le **statut** = `Capability::optionLabel(default_value)` (ex. `"Activé"`), résolu depuis
   `capabilities.options` (`[{value, label}]`).

Quand le libellé est tourné en **verbe d'action négatif** (« Désactiver X » / « X désactivés ») et collé à un
statut **générique** (« Activé »/« Désactivé »), **deux lectures opposées** deviennent grammaticalement valides :

- *« la **désactivation** du Store est **active** »* → Store **bloqué** ;
- *« le **Store** est **activé** »* → Store **accessible**.

Ces deux assertions sont **contraires**. Ambiguïté signalée par Henri (2026-06-23).

**Preuve que la racine est le mélange de deux grammaires** : les capacités qui suivent DÉJÀ la bonne convention
ne posent aucun problème —
- `Bureau à distance (RDP)` (sujet neutre) → `Activé` ✔ limpide ;
- `Afficher les extensions de fichiers` → options `Afficher`/`Masquer` (verbe d'état spécifique) ✔ limpide.

Seules les capacités en **« Désactiver X » / « X désactivés »** + statut **générique** `Activé/Désactivé`
produisent la contradiction : `windows_store_disabled`, `windows_copilot_off`, `windows_consumer_features_off`,
`offline_files_disabled` (et, à un degré moindre, redondance de `uac_enabled` « (UAC) activé » + « Activé »,
ambiguïté de `onedrive_hidden` « Masquer… » + « Activé »).

## ✅ Décision Henri — tranchée (2026-06-23)

> **Convention « SUJET + ÉTAT ».** Le **libellé nomme le SUJET neutre** (jamais un verbe d'action) ; le **statut
> affiche la VALEUR choisie** via `optionLabel()`. Lecture unique : *« le sujet EST dans cet état »*.

**Pourquoi cette convention et pas une autre** (analysé et écarté) — c'est la **seule qui généralise au-delà du
binaire**. `Capability::value_type` peut valoir `toggle` | `enum` | `scalar` (cf. `app/Models/Capability.php:44-48`,
`isEnum()`, `hasOptions()`, `allowedOptionValues()`). Or :

| Convention candidate | sur un `enum` à N crans | sur un `scalar` (`15 min`) | Verdict |
|---|---|---|---|
| **Sujet + valeur choisie** (RETENUE) | `Niveau UAC : Moyen` ✔ | `Délai de verrouillage : 15 min` ✔ | généralise |
| Sujet + verbe binaire `Autorisé/Bloqué` | « Autorisé » d'un cran sur 3 ? ✘ | aucun sens ✘ | binaire-only |
| Verbe d'action + `Appliqué/Non appliqué` | une « règle appliquée » ≠ choix de cran ✘ | aucun sens ✘ | binaire-only |

Les libellés d'**options** restent **libres par capacité** : générique `Activé/Désactivé`, ou métier
`Affiché/Masqué`, `Géré`, ou (futur) les crans d'un `enum`. Le sujet neutre est la règle ; le couple de libellés
est au choix du rédacteur de la capacité.

> **Note de design (hors implémentation) :** la convention est conçue pour accueillir `enum`/`scalar` sans
> reshape. Cette story n'implémente AUCUN `enum`/`scalar` — toutes les capacités restent des `toggle`. On
> harmonise seulement le texte des `toggle` existants.

## Story

As **administrateur SE5** (rôle `server.admin`) qui configure les capacités Windows du parc,
I want que **chaque capacité se lise « Sujet : État » sans ambiguïté** (le libellé nomme le sujet, le statut décrit
son état effectif),
so that **je n'aie plus à deviner si « activé » qualifie le sujet ou la désactivation** — la lecture est unique et
ne peut pas signifier une chose et son contraire.

## Acceptance Criteria

1. **AC1 — Plus aucun verbe d'action en libellé.** Aucune des 10 capacités du LOT ISO n'a un `label` formulé en
   verbe d'action (« Désactiver… », « Masquer… », « Afficher… », « …désactivés »). Chaque `label` nomme un **sujet
   neutre**. Chaque ligne des deux écrans se lit **« Sujet : État »** sans lecture contradictoire possible.
2. **AC2 — INVARIANT « zéro comportement » (le cœur).** Le `git diff` de la story ne modifie QUE des `label` et des
   `options[].label` (texte FR affiché). Sont **prouvablement identiques** (diff vide sur ces champs) :
   `capabilities.default_value`, les `options[].value` (`'on'`/`'off'`), et **toute** la table
   `capability_projections` (`spec`, `keys`, `hive`, `path`, `name`, `type`, maps `{on:…, off:…}`). **Aucune
   migration de projection n'est éditée** — en particulier `2026_06_19_100000_move_copilot_capability_to_hklm.php`
   reste intacte.
3. **AC3 — Fresh == Migrated (idempotence).** Une **nouvelle migration idempotente** met à jour `label` + `options`
   des 10 clés pour les environnements **déjà migrés** (VM, bases de test déjà semées qui ne rejouent pas les
   seeders). Les **seeders existants** (`100300`, `100500`) sont édités aux **mêmes** valeurs. Résultat : `migrate`
   sur une base neuve **et** sur une base existante aboutissent à un état **identique** (mêmes label/options).
   La migration de relabel est **rejouable** sans erreur ni double effet.
4. **AC4 — Les deux écrans affichent les nouveaux libellés.** `/admin/settings/capabilities` ET l'onglet capacités
   du parc reflètent les nouveaux `label`/statuts (les deux passent par `Capability::optionLabel()`, donc aucune
   logique d'affichage à modifier — seules les données changent).
5. **AC5 — Cohérence sujet↔état (test).** Un test vérifie, pour chaque capacité du LOT, que `optionLabel(value)`
   décrit bien **l'état du sujet** (pas l'action) : pour les capacités à sémantique négative, la valeur `on`
   (policy active) rend bien un état « désactivé/masqué » du sujet, et `off` un état « activé/affiché ». Et que
   les maps registre (`spec`) n'ont **pas** bougé.
6. **AC6 — Non-régression de compilation prouvée.** Les tests de **compilation/projection** restent **VERTS sans
   être modifiés** : `tests/Unit/Services/Agent/CapabilityRegistryProviderTest.php`,
   `tests/Unit/Services/Agent/CapabilityRegistryCompilationTest.php`,
   `tests/Feature/Agent/CapabilityPhysicalInheritanceTest.php`. Le fait qu'ils passent **inchangés** EST la preuve
   de l'invariant AC2 (ils n'assertent jamais de `label` — cf. `CapabilityRegistryProviderTest.php:268`
   `assertArrayNotHasKey('label', …)`).

## Tableau AVANT → APRÈS (spec exacte des 10 capacités)

> Sources : `database/migrations/2026_06_18_100300_seed_capabilities_iso_lot.php` (caps 1–9) et
> `database/migrations/2026_06_18_100500_seed_capability_windows_store_disabled.php` (cap 10).
> Colonne « Map registre » = **INCHANGÉE**, rappel seulement. `default_value` = `on` partout (inchangé).
> L'**ordre** des options est cosmétique (`optionLabel()` itère par `value`, `Capability.php:181-190`) : on liste
> l'état « positif/activé/affiché » en premier pour un menu naturel — non-fonctionnel, l'invariant ne le teste pas.

| # | key | label AVANT | **label APRÈS** | options APRÈS (`value → label`) | défaut affiché | Map registre (inchangée) |
|---|---|---|---|---|---|---|
| 1 | `show_file_extensions` | Afficher les extensions de fichiers | **Extensions de fichiers** | `on→Affichées`, `off→Masquées` | Affichées | HideFileExt `on:0,off:1` |
| 2 | `show_hidden_files` | Afficher les fichiers cachés | **Fichiers cachés** | `on→Affichés`, `off→Masqués` | Affichés | Hidden `on:1,off:0` |
| 3 | `uac_enabled` | Contrôle de compte (UAC) activé | **Contrôle de compte d'utilisateur (UAC)** | `on→Activé`, `off→Désactivé` *(inchangé)* | Activé | EnableLUA `on:1,off:0` (+ warning conservé) |
| 4 | `windows_consumer_features_off` | Désactiver les fonctionnalités grand public | **Fonctionnalités grand public** | `off→Activées`, `on→Désactivées` | Désactivées | DisableWindowsConsumerFeatures / DisableSoftLanding `on:1,off:0` |
| 5 | `windows_updates_managed` | Mises à jour Windows gérées | **Mises à jour Windows** | `on→Gérées` *(on-only conservé)* | Gérées | bundle WU (on-only) inchangé |
| 6 | `offline_files_disabled` | Fichiers hors connexion désactivés | **Fichiers hors connexion** | `off→Activés`, `on→Désactivés` | Désactivés | NetCache (4 clés) inchangé |
| 7 | `remote_desktop_enabled` | Bureau à distance (RDP) | **Bureau à distance (RDP)** *(INCHANGÉ — exemple de référence)* | `on→Activé`, `off→Désactivé` *(inchangé)* | Activé | fDenyTSConnections `on:0,off:1` |
| 8 | `windows_copilot_off` | Désactiver Windows Copilot | **Windows Copilot** | `off→Activé`, `on→Désactivé` | Désactivé | TurnOffWindowsCopilot `on:1,off:0` **(projection HKLM du fix `2026_06_19_100000` — NON touchée)** |
| 9 | `onedrive_hidden` | Masquer OneDrive de l'Explorateur | **OneDrive dans l'Explorateur** | `off→Affiché`, `on→Masqué` | Masqué | System.IsPinnedToNameSpaceTree `on:0,off:1` |
| 10 | `windows_store_disabled` | Désactiver le Microsoft Store | **Microsoft Store** | `off→Activé`, `on→Désactivé` | Désactivé | RemoveWindowsStore `on:1,off:0` |

**Lecture de contrôle après refonte** (toutes sans ambiguïté) :
`Microsoft Store : Désactivé` · `Windows Copilot : Désactivé` · `Fonctionnalités grand public : Désactivées` ·
`Fichiers hors connexion : Désactivés` · `OneDrive dans l'Explorateur : Masqué` · `Bureau à distance (RDP) : Activé` ·
`Extensions de fichiers : Affichées` · `Fichiers cachés : Affichés` · `Contrôle de compte d'utilisateur (UAC) : Activé` ·
`Mises à jour Windows : Gérées`.

## Tasks / Subtasks

- [ ] **T1 — Éditer les seeders existants (installs neuves)** (AC: 1, 3)
  - [ ] `database/migrations/2026_06_18_100300_seed_capabilities_iso_lot.php` : mettre à jour le `label` et le
        `options` des 9 capacités selon le tableau. **Ne PAS toucher** `default_value`, ni les `value` des
        options, ni les `keys`/projections du même fichier.
  - [ ] Les constantes partagées divergent désormais : garder `$optionsOnOff` (`on→Activé`/`off→Désactivé`) pour
        **uac_enabled** et **remote_desktop_enabled** uniquement ; définir des tableaux **dédiés** pour les autres
        (`Affichées/Masquées`, `Affichés/Masqués`, `Activées/Désactivées` flippé, `Activés/Désactivés` flippé,
        `Affiché/Masqué`, `Gérées`). `$optionsAfficherMasquer` (`Afficher/Masquer`) n'est plus partagé tel quel
        (accord en genre/nombre par capacité).
  - [ ] `database/migrations/2026_06_18_100500_seed_capability_windows_store_disabled.php` : `label` →
        `"Microsoft Store"`, `options` → `off→Activé`, `on→Désactivé`. Projection `RemoveWindowsStore` **intacte**.
- [ ] **T2 — Nouvelle migration de relabel idempotente (bases déjà migrées)** (AC: 3)
  - [ ] Créer `database/migrations/2026_06_23_xxxxxx_relabel_capabilities_subject_state.php`.
  - [ ] `up()` : pour chacune des **10** clés, `DB::table('capabilities')->where('key', …)->update(['label' => …,
        'options' => json_encode([...], JSON_UNESCAPED_UNICODE), 'updated_at' => now()])`. Guard
        `Schema::hasTable('capabilities')`. **Aucune** écriture sur `default_value`, `value`, ou
        `capability_projections`.
  - [ ] Valeurs **strictement identiques** à celles posées en T1 (fresh == migrated). Rejouable sans effet de bord.
  - [ ] `down()` : facultatif / no-op documenté (zéro prod, `project_zero_prod_publish_is_test`) — ou restaurer les
        anciens libellés si trivial. Ne jamais toucher les projections en `down()`.
- [ ] **T3 — Vérification défensive (ordre & littéraux)** (AC: 2, 4)
  - [ ] Confirmer (grep) qu'aucun code ne dépend de l'ordre des options (`->options[0]`) ni de chaînes de libellé
        en dur — **déjà vérifié** : seuls 2 appels `optionLabel()` (admin index + parc tab), tous order-independent ;
        aucun `options[0]` ; aucun littéral de label en logique (un seul commentaire d'exemple, voir T5).
  - [ ] Confirmer que `default_display`/`override_display` des deux blades sortent bien les nouveaux libellés sans
        modification de blade (rendu piloté par les données).
- [ ] **T4 — Tests** (AC: 5, 6)
  - [ ] Mettre à jour les **assertions de libellé/option** dans les tests qui les vérifient :
        `tests/Feature/Migrations/CapabilitiesSchemaAndSeedTest.php`,
        `tests/Feature/Livewire/Admin/AdminSettingsCapabilitiesPageTest.php`,
        `tests/Feature/Livewire/Parc/CapabilitiesTabTest.php` (uniquement les chaînes de **texte affiché** ; ne pas
        relâcher les assertions de projection/hive/map qui doivent rester).
  - [ ] Ajouter un test « cohérence sujet↔état » (AC5) : pour chaque clé du LOT, asserter le couple
        `(default_value, optionLabel(default_value))` attendu **et** que la `spec` de projection est inchangée
        (mêmes `name`/`value` map).
  - [ ] **NE PAS modifier** `CapabilityRegistryProviderTest`, `CapabilityRegistryCompilationTest`,
        `CapabilityPhysicalInheritanceTest` : ils doivent passer **inchangés** (preuve AC6/AC2). Si l'un casse, c'est
        que l'invariant a été violé → corriger l'implémentation, pas le test.
- [ ] **T5 — Cohérence éditoriale (optionnel, non bloquant)**
  - [ ] `resources/views/pages/parc/groups/_partials/capabilities-tab.blade.php:15` : le docblock cite
        « Afficher les extensions » comme exemple — l'aligner sur le nouveau libellé (« Extensions de fichiers »)
        pour cohérence. Purement cosmétique (commentaire).
- [ ] **T6 — Exécution & preuve**
  - [ ] `php artisan migrate` sur base de test (SQLite hôte) — cf. `project_phpunit_test_env_host_vs_vm`.
  - [ ] Suite ciblée verte : les 6 fichiers de test capacités (cf. T4). Lancer par **filtres ciblés**, pas en bloc
        massif (`project_vm_phpunit_bulk_run_false_failures`).
  - [ ] `git diff` de revue : prouver que seuls `label`/`options[].label` changent (AC2).

## Dev Notes

### Modèle de données concerné (et ce qu'on ne touche PAS)
- `app/Models/Capability.php` — **non modifié**. `optionLabel(string $value)` (`:181-190`) résout `value → label`
  par **itération** (order-independent) ; repli sur la valeur brute pour un `scalar` sans options. `value_type`
  reste `toggle`.
- `app/Models/CapabilityProjection.php` + table `capability_projections` — **non modifiés** (c'est l'invariant).
- Le **payload du contrat** n'émet jamais le `label` ni la `key` (invariant central 27.12) → un changement de
  `label` est par construction invisible côté agent.

### Carte des migrations capacités (toucher / ne pas toucher)
| Migration | Rôle | Action 27.15 |
|---|---|---|
| `…100000_create_capabilities_table` | schéma | — |
| `…100100_create_capability_projections_table` | schéma | — |
| `…100200_create_capability_assignments_table` | schéma | — |
| `…100300_seed_capabilities_iso_lot` | seed 9 caps (label+options+**projections**) | **éditer label/options seulement** |
| `…100400_drop_registry_settings_tables` | drop ancien modèle | — |
| `…100500_seed_capability_windows_store_disabled` | seed Store (label+options+**projection**) | **éditer label/options seulement** |
| `2026_06_19_100000_move_copilot_capability_to_hklm` | **fix projection Copilot HKCU→HKLM** | **NE PAS TOUCHER** |
| `2026_06_23_xxxxxx_relabel_capabilities_subject_state` | **NOUVEAU** relabel idempotent | **créer (T2)** |

⚠️ **Piège Copilot** : le seeder `100300` contient encore la projection Copilot en **HKCU** ; la projection
**live** est en **HKLM** car écrasée par `2026_06_19_100000` (cf. `project_hkcu_policies_not_writable_by_companion`
et l'assertion `CapabilitiesSchemaAndSeedTest.php:215`). La story de relabel **n'écrit aucune projection**, donc
ce point est neutre — mais il illustre POURQUOI on ne ré-écrit jamais les `keys` dans le relabel : on clobberait
un fix postérieur.

### Pourquoi l'« inversion » des libellés est sûre
Pour les capacités à sémantique négative (`windows_store_disabled`, `windows_copilot_off`,
`windows_consumer_features_off`, `offline_files_disabled`), on échange **quel `value` porte quel libellé** pour que
le statut décrive le **sujet** : `on` (policy active = sujet bloqué) ↦ « Désactivé », `off` ↦ « Activé ». La
**map registre** (`{on:1, off:0}`) ne bouge pas : `on` reste « policy émise », seul le **mot affiché** change.
`default_value` reste `on` ⇒ l'affichage par défaut passe de « Activé » (ambigu) à « Désactivé » (= le Store est
désactivé par défaut sur le parc), ce qui est **la même réalité**, désormais correctement nommée.

### Environnement de test & exécution
- Tests sur **HÔTE** (php 8.4 + pdo_sqlite + vendor) — `project_phpunit_test_env_host_vs_vm`,
  `project_root_is_laravel` (artisan à la racine).
- VM : les migrations ne sont **pas auto-jouées** (`project_vm_migrations_not_auto_applied`) → `migrate:status`
  puis `migrate` côté VM pour l'e2e ; la nouvelle migration de relabel est précisément ce qui porte le correctif
  sur la base VM déjà semée. **Worktree : pas d'action VM** (`feedback_worktree_no_vm_sync`) — l'e2e VM se fait
  depuis `main` après merge.
- Lancer les tests par **filtres ciblés** (`project_vm_phpunit_bulk_run_false_failures`).

### Points UI (rappel conventions projet)
- Composant page = Livewire SFC `admin/settings/capabilities/index.blade.php` ; onglet parc = partial blade
  `parc/groups/_partials/capabilities-tab.blade.php`. Aucune nouvelle réactivité requise (données seules).
- Gate `can:server.admin` inchangé.

### Reco dev
**opus.** Changement de données à faible surface mais l'**invariant « ne pas toucher la projection »** est subtil,
et l'**inversion `value↔label`** sur 5 capacités (4 flips + onedrive) exige de la rigueur : une erreur silencieuse
inverserait le comportement réel sans casser l'affichage. La preuve par les tests de compilation inchangés (AC6)
est le garde-fou.

### Project Structure Notes
- Aligné sur l'arborescence existante (migrations sous `database/migrations`, vues sous `resources/views/pages/**`).
- Aucun nouveau fichier hors la migration de relabel. Aucun nouveau type de contrat, aucune route.

### References
- [Source: database/migrations/2026_06_18_100300_seed_capabilities_iso_lot.php] — seed des 9 caps (labels/options/projections actuels).
- [Source: database/migrations/2026_06_18_100500_seed_capability_windows_store_disabled.php] — seed Store.
- [Source: database/migrations/2026_06_19_100000_move_copilot_capability_to_hklm.php] — fix projection (NE PAS TOUCHER).
- [Source: app/Models/Capability.php#optionLabel] — résolution value→label, order-independent (:181-190) ; `value_type` (:44-48).
- [Source: resources/views/pages/admin/settings/capabilities/index.blade.php:63] — `default_display = optionLabel(default_value)`.
- [Source: resources/views/pages/parc/groups/_partials/capabilities-tab.blade.php:100-101,134] — mêmes appels `optionLabel()`.
- [Source: tests/Unit/Services/Agent/CapabilityRegistryProviderTest.php:268] — `assertArrayNotHasKey('label', …)` (le label ne fuite pas au payload).
- [Source: _bmad-output/implementation-artifacts/27-12-config-capacites-registre-capability-first.md] — modèle capability-first (D1–D9), invariant contrat/agent figé.
- Mémoires : `project_config_capabilities_model`, `project_capability_value_map_symmetric_rule`,
  `project_hkcu_policies_not_writable_by_companion`, `project_registry_default_broadcast_override`,
  `project_vm_migrations_not_auto_applied`, `project_phpunit_test_env_host_vs_vm`,
  `project_vm_phpunit_bulk_run_false_failures`, `project_zero_prod_publish_is_test`.

## Dev Agent Record

### Agent Model Used

_(à remplir par le dev agent)_

### Debug Log References

### Completion Notes List

### File List

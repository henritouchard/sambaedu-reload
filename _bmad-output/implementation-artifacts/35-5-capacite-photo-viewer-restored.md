# Story 35.5 : Capacité `photo_viewer_restored` — seed sans évolution moteur

Status: ready-for-dev

<!-- Source d'autorité : _bmad-output/planning-artifacts/epics-capacites-v2.md#Story-35.5 (Epic 35 ne figure PAS dans epics.md). -->
<!-- Valeurs iso-GPO vérifiées sur la SOURCE : ../GPO_spécialesCD95/Ajustement_Photo/{B1E4CA63-2196-40A7-A7AF-50B0FFE099BD}/DomainSysvol/GPO/Machine/Preferences/Registry/Registry.xml -->

## Story

En tant que **référent numérique**,
je veux **restaurer la visionneuse de photos Windows sur les postes**,
afin de **remplacer la GPO CD95 « Ajustement_Photo » sans attendre le domaine associations**.

## Contexte & intention

La GPO CD95 « Ajustement_Photo » porte 6 réglages : `InitialKeyboardIndicators` HKCU (livré, `numlock_on_logon`), `InitialKeyboardIndicators` HKU\.DEFAULT (→ 35.3), `CheckForUpdates` OnlyOffice (livré, `onlyoffice_auto_update_off`), `DontDisplayLastUserName` (livré, `hide_last_username`) — et **4 clés HKCR de réenregistrement de la visionneuse de photos Windows**, la dernière brique de cette GPO, objet de cette story.

Le palier A avait exclu ces 4 clés du lot avec le commentaire « relève du modèle associations » — une exclusion **sémantique**. L'Epic 35 la requalifie : le RÉENREGISTREMENT de la visionneuse (rendre l'app existante et invocable) est un toggle registre pur, distinct du CHOIX de l'app par extension (UserChoice = composer d'associations 27.11). La story est donc **100 % donnée** : une migration de seed, zéro évolution moteur, zéro UI (les pages capacités existantes affichent la donnée).

**Valeurs vérifiées à la source** (pas de valeurs « de mémoire ») : le `Registry.xml` de la GPO d'origine donne les 4 clés exactes, dont **deux détails que le cadrage ne portait pas** :

1. la commande `print` utilise **`ImageView_Fullscreen`** (PAS `ImageView_PrintTo`) — quirk de la GPO CD95, préservé (iso-GPO prime sur le « correct » Windows) ;
2. les deux `DropTarget\Clsid` sont **distincts** : open = `{FFE2A43C-56B9-4bf5-9A79-CC6D4285608A}`, print = `{60fd46de-f830-4894-a628-6fa81bc0190d}`.

## ⚠️ Découverte de cadrage (à lire AVANT de coder) — valeur PAR DÉFAUT de clé

L'affirmation de l'epic « l'exclusion du palier A était sémantique, pas technique » est **fausse pour 2 des 4 clés**. Les deux clés `…\shell\open\command` et `…\shell\print\command` écrivent la **valeur PAR DÉFAUT de la clé** (`name=""` dans le `Registry.xml` source, `status="(par défaut)"`) — c'est ce que lit le shell Windows, il n'existe aucune valeur nommée alternative. Or :

- côté **serveur**, tout passe : le provider émet `name: ''` sans broncher (`(string) ($key['name'] ?? '')`), `exclusiveKey()` reste unique par `path`, `StateHasher` hash normalement ;
- côté **agent**, `parseRegistrySpec` (`agent/shared/handler_registry.go:321`) rejette `name == ""` comme enveloppe invalide (conflation « champ absent » ≡ « chaîne vide ») → item `{status: error}`, pour l'écriture COMME pour la suppression (la garde est AVANT la branche `ensure`) ;
- la couche Ops Windows, elle, supporterait nativement `""` (les API Go `registry` : `GetValue("")`/`SetStringValue("")`/`DeleteValue("")` = valeur par défaut de la clé).

**Conséquence** : avec l'agent actuel, une capacité ARMÉE écrirait les 2 `Clsid` mais pas les 2 `command` → nœud `Applications\photoviewer.dll` à moitié enregistré (apparaît dans « Ouvrir avec » sans commande fonctionnelle) — PIRE que rien. La contrainte de story « zéro évolution moteur » étant non négociable, la story tranche un **gate d'honnêteté** (D3 ci-dessous) : la capacité est seedée COMPLÈTE et FIDÈLE (les 4 clés, `name: ''` compris — c'est le contrat cible) mais **`is_active = false`** — invisible de l'onglet capacités des parcs, grisée dans les réglages, ignorée par le provider (`where('is_active', true)`). L'ACTIVATION est gated par une micro-évolution agent hors story (accepter `name: ""` = valeur par défaut — ~3 lignes de parse + doc contrat + bump + note de publication ; candidat naturel : 35.2 ou 35.3 qui touchent déjà `handler_registry.go`, sinon micro-story 35.5bis). Ce séquençage seed-d'abord est identique au pattern « palier A seed / palier B moteur » de l'epic.

## Décisions de design (tranchées)

1. **Fidélité iso-GPO stricte.** Les 4 valeurs sont celles du `Registry.xml` source, à l'octet près : les DEUX commandes = `ImageView_Fullscreen` (quirk print préservé — le but est de remplacer la GPO à l'identique, pas de la corriger), les 2 Clsid distincts.
2. **Routage HKCR → `HKCU\Software\Classes`** (iso `onedrive_hidden`, seed ISO) : HKCR = vue fusionnée HKLM+HKCU`\Software\Classes` ; la branche per-user est écrite par le compagnon de session → **portée Session** (provider `RegistryUserCapabilityProvider`), aucun droit admin requis. Nuance assumée par l'epic : la GPO d'origine écrivait HKCR côté MACHINE (machine-wide) ; la capacité applique par session convergée — iso-intention (chaque session gérée voit la visionneuse), l'overlay per-user prime sur la vue machine.
3. **Gate d'honnêteté `is_active = false`** (cf. Découverte de cadrage) : mécanique EXISTANTE (provider + onglets UI filtrent déjà `is_active`), zéro moteur, une migration d'une ligne suffira à activer quand l'agent saura écrire la valeur par défaut. La `description` de la capacité énonce le gate (pour l'admin qui la voit grisée dans les réglages parc-defaults).
4. **« off » = vraie action via 35.1 (LIVRÉE)** : chaque clé porte `'off' => ['$ensure' => 'absent']` (marqueur littéral dupliqué dans la migration, iso retrofit `2026_07_03_100000` — les migrations ne référencent pas le code applicatif ; les constantes publiques `AbstractCapabilityStateProvider::SPEC_ENSURE`/`ENSURE_ABSENT` restent la référence pour les TESTS). Trois régimes : on = réenregistrer, off = désenregistrer (suppression des 4 valeurs), unmanaged = rien d'émis.
5. **Convention libellés « sujet + état »** : label = sujet neutre (« Visionneuse de photos Windows »), le statut est porté par les valeurs. « Non géré » RÉSERVÉ à la sentinelle `unmanaged`.
6. **Limite de périmètre (AC epic, à documenter partout)** : la capacité RÉENREGISTRE la visionneuse (iso-GPO CD95, qui ne touchait pas UserChoice) ; le choix effectif de l'app par extension relève du composer d'associations existant (27.11) — HORS story. Corollaire : la visionneuse reste EXCLUE du catalogue `NativeApplicationSeeder` (exe `rundll32.exe` générique non fonctionnel — décision de curation 2026-06-18, inchangée).

## Spécification de la donnée seedée

**Capacité** (`capabilities`) :

| Champ | Valeur |
|---|---|
| `key` | `photo_viewer_restored` |
| `label` | `Visionneuse de photos Windows` |
| `description` | Réenregistre la visionneuse de photos Windows (commandes open/print + DropTarget) pour la session — iso-GPO CD95 « Ajustement_Photo ». Ne choisit PAS l'application par extension (voir Associations). **Inactive tant que l'agent ne sait pas écrire la valeur par défaut d'une clé.** (formulation libre, ces 3 idées présentes) |
| `category` | `Bureau` |
| `value_type` | `toggle` |
| `options` | `unmanaged` → « Non géré », `on` → « Restaurée (réenregistrée) », `off` → « Désenregistrée (clés supprimées) » |
| `default_value` | `unmanaged` (opt-in — rien n'est émis en broadcast) |
| `warning` | `null` |
| `applies_to_os` | `["windows"]` |
| `is_active` | **`false`** (gate D3) |
| `overrides_locked` | `false` |

**Projection** (`capability_projections`, `os=windows`, `mechanism=registry`) — `spec.keys`, les 4 clés, TOUTES `hive: HKCU` :

| # | `path` | `name` | `type` | `value` map |
|---|---|---|---|---|
| 1 | `Software\Classes\Applications\photoviewer.dll\shell\open\command` | `''` (valeur PAR DÉFAUT) | `REG_EXPAND_SZ` | `on` → `%SystemRoot%\System32\rundll32.exe "%ProgramFiles%\Windows Photo Viewer\PhotoViewer.dll", ImageView_Fullscreen %1` ; `off` → `{"$ensure": "absent"}` |
| 2 | `Software\Classes\Applications\photoviewer.dll\shell\print\command` | `''` (valeur PAR DÉFAUT) | `REG_EXPAND_SZ` | `on` → MÊME commande (`ImageView_Fullscreen`, quirk GPO préservé) ; `off` → marqueur |
| 3 | `Software\Classes\Applications\photoviewer.dll\shell\open\DropTarget` | `Clsid` | `REG_SZ` | `on` → `{FFE2A43C-56B9-4bf5-9A79-CC6D4285608A}` ; `off` → marqueur |
| 4 | `Software\Classes\Applications\photoviewer.dll\shell\print\DropTarget` | `Clsid` | `REG_SZ` | `on` → `{60fd46de-f830-4894-a628-6fa81bc0190d}` ; `off` → marqueur |

En PHP, la commande s'écrit : `'%SystemRoot%\\System32\\rundll32.exe "%ProgramFiles%\\Windows Photo Viewer\\PhotoViewer.dll", ImageView_Fullscreen %1'` (guillemets doubles LITTÉRAUX dans la chaîne, backslashes échappés). Encodage iso seeds : `JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES`. `name` explicitement `''` pour les clés 1-2 (ne pas omettre le champ : la `spec` est le contrat d'authoring).

## Acceptance Criteria

### AC1 — Seed : capacité + projection iso-GPO (migration NOUVELLE, idempotente)

**Given** le mécanisme `registry` ACTUEL
**When** la nouvelle migration `2026_07_03_130000_seed_capability_photo_viewer_restored.php` est jouée
**Then** la capacité `photo_viewer_restored` existe conformément au tableau « Spécification de la donnée seedée » (toggle, opt-in `unmanaged`, portée Session via routage HKCU, catégorie Bureau)
**And** sa projection `windows`/`registry` porte EXACTEMENT les 4 clés du tableau (paths, `name` — dont `''` sur les 2 `command` —, types, valeurs `on` à l'octet près, Clsid open ≠ Clsid print)
**And** la migration suit le patron palier A (`updateOrInsert` par `key` puis par `(capability_id, os, mechanism)`, garde `Schema::hasTable`, rejouable sans effet de bord) avec un `down()` supprimant la capacité par `key` (FK cascade → projection/overrides)
**And** le docblock de la migration documente : le routage HKCR→HKCU, le quirk `ImageView_Fullscreen` sur print (iso-GPO), la limite de périmètre (réenregistrement ≠ UserChoice/27.11), et la raison du gate `is_active=false` (valeur par défaut de clé non supportée par le parse agent actuel).

### AC2 — « off » = vraie action (marqueur 35.1) + invariant

**Given** la projection seedée
**Then** CHAQUE clé de la `spec` porte `'off' => ['$ensure' => 'absent']` (l'agent — une fois la capacité activable — supprimera les 4 valeurs : désenregistrement, Windows reprend son état)
**And** `photo_viewer_restored` est AJOUTÉE à la liste `$withOff` du test `on_off_capabilities_emit_a_real_value_for_off` (invariant « un off proposé fait une vraie action »)
**And** le libellé de `off` n'est PAS « Non géré » (réservé à la sentinelle UNMANAGED de l'option `unmanaged`).

### AC3 — Gate d'honnêteté : capacité seedée INACTIVE, rien n'est émis

**Given** l'agent actuel qui rejette `name == ""` (`parseRegistrySpec`)
**Then** la capacité est seedée `is_active = false` — un test l'affirme AVEC le motif en message d'assertion
**And** un test prouve qu'ARMÉE (override de parc `on`) mais inactive, `RegistryUserCapabilityProvider` n'émet AUCUN item pour cette capacité (le filtre `is_active` du provider est le gate)
**And** en broadcast (défaut `unmanaged`), rien n'est émis non plus — conséquence : golden files `tests/Fixtures/Agent/*` STRICTEMENT inchangés, `FROZEN_STATE_HASH`/`frozenStateHash` intacts.

### AC4 — Chaîne provider prouvée sur données réelles (post-gate)

**Given** la capacité temporairement ACTIVÉE dans le test (simulation du flip post-gate — c'est la preuve que la DONNÉE est correcte de bout en bout, pattern `llmnr_disabled_off_emits_ensure_absent_items_via_the_real_provider`)
**When** un override de parc `on` est posé et `RegistryUserCapabilityProvider::itemsFor()` est appelé
**Then** 4 items d'ÉCRITURE 5 clés sont émis, tous `hive: HKCU` : 2 items `name === ''` / `type REG_EXPAND_SZ` / commande exacte du tableau, 2 items `name === 'Clsid'` / `REG_SZ` / les 2 GUID distincts — sans fuite d'id de capacité
**And** avec un override `off`, 4 items de SUPPRESSION 4 clés `{hive, path, name, ensure: 'absent'}` sont émis (mêmes identités de clé)
**And** `RegistryMachineCapabilityProvider` n'émet RIEN pour cette capacité (aucune clé HKLM)
**And** aucun fichier de `app/Services/Agent/**` ni `agent/**` n'est modifié (zéro évolution moteur, pas de bump `version.go`).

### AC5 — Limite de périmètre documentée

**Given** la story et le seed
**Then** la limite est documentée (docblock migration + présente story) : la capacité RÉENREGISTRE la visionneuse, iso-GPO CD95 qui ne touchait pas UserChoice ; le choix effectif de l'application par extension = composer d'associations 27.11, HORS story ; la visionneuse reste exclue du catalogue `NativeApplicationSeeder` (curation inchangée).

## Tasks / Subtasks

- [ ] **Task 1 — Migration de seed (AC1, AC2, AC3, AC5)**
  - [ ] 1.1 Créer `database/migrations/2026_07_03_130000_seed_capability_photo_viewer_restored.php` (timestamp à ajuster si une story parallèle de l'epic a pris le créneau — les noms de fichiers diffèrent, seul l'ordre importe peu ici) : patron EXACT du palier A `2026_07_02_100000_seed_capabilities_gpo_cd95_lot.php` (up : `updateOrInsert` capacité par `key` + projection par `(capability_id, os, mechanism)` ; down : delete par `key` ; garde `hasTable` ; `JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES`).
  - [ ] 1.2 Donnée conforme au tableau « Spécification » : options `[unmanaged, on, off]` avec libellés convention sujet+état, `default_value = 'unmanaged'`, **`is_active => false`**, marqueur `'off' => ['$ensure' => 'absent']` en littéral sur les 4 clés (iso retrofit 35.1).
  - [ ] 1.3 Docblock : routage HKCR→HKCU (iso commentaire `onedrive_hidden`), quirk print `ImageView_Fullscreen`, les 2 Clsid distincts sourcés du `Registry.xml` GPO, limite de périmètre 27.11, motif du gate (valeur par défaut `name:''` rejetée par `parseRegistrySpec` — activation via migration d'une ligne quand l'agent la supportera).
- [ ] **Task 2 — Tests seed (AC1, AC2, AC3)** — dans `tests/Feature/Migrations/CapabilitiesSchemaAndSeedTest.php`, section « Story 35.5 » (append, style des tests retrofit 35.1)
  - [ ] 2.1 `photo_viewer_restored_is_seeded_iso_gpo_cd95_with_four_hkcr_keys_routed_hkcu` : champs de la capacité + les 4 clés EXACTES (assertSame sur paths/names/types/valeurs `on`, y compris `'' === $key['name']` des 2 command et l'inégalité des 2 Clsid).
  - [ ] 2.2 Ajouter `photo_viewer_restored` à `$withOff` dans `on_off_capabilities_emit_a_real_value_for_off` (2.2 suffit pour le marqueur — le test générique vérifie déjà valeur réelle OU marqueur sur chaque clé).
  - [ ] 2.3 `photo_viewer_restored_is_gated_inactive_until_agent_supports_default_value_names` : `is_active === false` (message = motif), et provider User n'émet RIEN même armé `on` par override de parc (pattern du test provider retrofit : `WorkstationGroupObserver::disableSync()`, factories, `TargetContext::for($ws, null)`).
  - [ ] 2.4 Idempotence/réversibilité : `up()` rejoué = snapshot identique (options + spec + is_active) ; `down()` supprime capacité ET projection ; `up()` re-seed à l'identique (pattern `retrofit_migration_is_idempotent_and_reversible`, en version seed).
- [ ] **Task 3 — Test chaîne provider post-gate (AC4)**
  - [ ] 3.1 `photo_viewer_restored_emits_session_items_via_the_real_provider_once_activated` : activer la capacité DANS le test (`update(['is_active' => true])` — simulation du flip), override parc `on` → 4 items 5 clés HKCU exacts ; override `off` → 4 items 4 clés `ensure:absent` ; `RegistryMachineCapabilityProvider` → 0 item. Utiliser les constantes `AbstractCapabilityStateProvider::SPEC_ENSURE`/`ENSURE_ABSENT` côté assertions si utile.
- [ ] **Task 4 — Validation finale**
  - [ ] 4.1 Tests HÔTE ciblés (php8.4 + sqlite, JAMAIS de run massif) : `php artisan test --filter='CapabilitiesSchemaAndSeedTest'` puis `--filter='CapabilityRegistryProviderTest|CapabilityRegistryCompilationTest|ContractV1Test'` (non-régression : la nouvelle donnée ne perturbe ni providers ni golden).
  - [ ] 4.2 Vérifier `git status` : AUCUN fichier `app/Services/Agent/**`, `agent/**`, `tests/Fixtures/Agent/**` modifié (AC4).
  - [ ] 4.3 Signaler en Dev Agent Record : migration **à rejouer sur /vm** (`php artisan migrate` — jamais auto-appliquée) ; AUCUNE release agent à publier (zéro modif agent).

## Dev Notes

### Fichiers à toucher (exhaustif prévu)

| Fichier | Nature |
|---|---|
| `database/migrations/2026_07_03_130000_seed_capability_photo_viewer_restored.php` | NOUVEAU — seed capacité + projection (gate inactive) |
| `tests/Feature/Migrations/CapabilitiesSchemaAndSeedTest.php` | modifié — section 35.5 (4 tests) + `$withOff` |

**NE PAS TOUCHER** : `app/Services/Agent/**` (providers, StateCompiler, StateHasher — zéro évolution moteur), `agent/**` (pas de bump `version.go`, pas de release), `tests/Fixtures/Agent/*` + `FROZEN_STATE_HASH`/`frozenStateHash` (golden intacts — rien n'est émis par défaut), seeds/migrations existants (`2026_07_02_100000`, `2026_07_03_100000` — nouvelle migration, on ne réécrit pas l'histoire), `NativeApplicationSeeder`/`FileAssociationSeeder`/domaine associations (limite de périmètre), `sprint-status.yaml` / `backlog.data.js` / `backlog.html` / `routes/web.php` (orchestrateur), stories 35.2/35.4 (créées en parallèle).

### Patterns existants à imiter

- **Seed capacités** : `2026_07_02_100000_seed_capabilities_gpo_cd95_lot.php` (structure `$lot`, updateOrInsert, down par keys) — la story n'ajoute qu'UNE entrée, garder la même forme.
- **Routage HKCR** : commentaire `onedrive_hidden` du seed ISO `2026_06_18_100300` (l.212-215) — reprendre la formulation.
- **Marqueur `$ensure` en migration** : retrofit `2026_07_03_100000` (littéral dupliqué + renvoi `{@see AbstractCapabilityStateProvider::SPEC_ENSURE}` en docblock).
- **Tests seed + provider sur données réelles** : `retrofitted_on_only_capabilities_expose_a_real_off_by_deletion`, `retrofit_migration_is_idempotent_and_reversible`, `llmnr_disabled_off_emits_ensure_absent_items_via_the_real_provider` (CapabilitiesSchemaAndSeedTest) — mêmes idiomes (require migration, snapshot closure, disableSync observer, TargetContext).

### Pièges

1. **`name: ''` est VOULU** sur les 2 clés `command` (valeur par défaut de la clé, iso `Registry.xml` GPO). Ne pas « corriger » en `(Default)`/`@`/omission — c'est la donnée du contrat cible. Le provider l'émet tel quel ; c'est l'AGENT actuel qui ne sait pas l'appliquer, d'où le gate `is_active=false` (voir Découverte de cadrage). Ne PAS toucher `parseRegistrySpec` pour autant : hors story.
2. **Ne pas inverser le quirk print** : `ImageView_Fullscreen` sur les DEUX commandes (fidélité GPO), même si la registration Windows « canonique » utilise `ImageView_PrintTo` pour print.
3. **Deux Clsid DISTINCTS** (open `{FFE2A43C-…}` / print `{60fd46de-…}`) — le cadrage amont n'en citait qu'un ; la source GPO fait foi.
4. **Toggle à 3 options** (`unmanaged`/`on`/`off`) : déjà supporté (le palier A a des toggles `[unmanaged, on]` et le retrofit des `[on, off]`) — pas de changement de modèle.
5. **Idempotence du seed vs futur flip** : `updateOrInsert` réécrit `is_active=false` à CHAQUE rejeu — c'est le comportement palier A (dernier seed fait foi) ; la future migration d'activation devra être POSTÉRIEURE chronologiquement (les fresh installs jouent seed puis flip). Rien à faire ici, juste ne pas s'en étonner en test.
6. **Tests HÔTE uniquement, filtres ciblés** (php8.4 + sqlite) ; un run massif VM produit de faux échecs. Migration à rejouer sur /vm = à SIGNALER, pas à exécuter.
7. **Casse** : garder `Software\Classes\…` (iso `onedrive_hidden`), backslashes doublés en PHP, guillemets doubles littéraux dans la commande.

### Project Structure Notes

- Aucun fichier PHP applicatif : la story vit dans `database/migrations/` + `tests/Feature/Migrations/`.
- Aucune UI : les onglets capacités existants (parc-defaults `registry-tab`, groupes `capabilities-tab`) affichent la donnée ; l'inactive apparaît grisée dans parc-defaults (opacity-50) et absente des onglets d'armement — comportement existant, aucun test UI requis.
- Entrée QA runbook (`docs/qa/domains/agent.md`) DIFFÉRÉE à la story d'activation (rien d'armable à scénariser tant que le gate est posé).

### References

- [Source: _bmad-output/planning-artifacts/epics-capacites-v2.md#Story-35.5 + #Overview + #Garde-fous-d'epic] — autorité de cadrage
- [Source: ../GPO_spécialesCD95/Ajustement_Photo/{B1E4CA63-2196-40A7-A7AF-50B0FFE099BD}/DomainSysvol/GPO/Machine/Preferences/Registry/Registry.xml] — valeurs iso-GPO (autorité des 4 clés)
- [Source: _bmad-output/implementation-artifacts/35-1-verbe-ensure-present-absent-registry.md] — marqueur `'off' => {'$ensure': 'absent'}`, constantes `SPEC_ENSURE`/`ENSURE_ABSENT`, patron retrofit
- [Source: database/migrations/2026_07_02_100000_seed_capabilities_gpo_cd95_lot.php] — patron de seed palier A (structure, idempotence, opt-in unmanaged)
- [Source: database/migrations/2026_06_18_100300_seed_capabilities_iso_lot.php#onedrive_hidden] — précédent de routage HKCR→HKCU\Software\Classes
- [Source: app/Services/Agent/Providers/AbstractCapabilityStateProvider.php — expand()/UNMANAGED/SPEC_ENSURE + filtre is_active l.147]
- [Source: agent/shared/handler_registry.go#parseRegistrySpec l.312-353 — garde `name == ""` (motif du gate) ; REG_EXPAND_SZ supporté l.368]
- [Source: tests/Feature/Migrations/CapabilitiesSchemaAndSeedTest.php — invariant on/off + patterns de tests 35.1]
- [Source: database/seeders/NativeApplicationSeeder.php l.37-51 — exclusion curation visionneuse (limite de périmètre)]
- [Source: _bmad-output/implementation-artifacts/27-11-associations-composer-extension-app.md — UserChoice/composer, HORS story]

## Dépendances

- **35.1 — DONE (mergée sur main), REQUISE** : le « off » utilise le marqueur `'off' => {'$ensure': 'absent'}` livré par 35.1 (constantes publiques `SPEC_ENSURE`/`ENSURE_ABSENT` sur `AbstractCapabilityStateProvider`, patron du retrofit `2026_07_03_100000`).
- **Indépendante de 35.2 / 35.3 / 35.4** (créées/développées en parallèle — ne toucher à aucun de leurs fichiers ; seul risque de friction : le créneau de timestamp de migration et la section append de `CapabilitiesSchemaAndSeedTest`, à rebaser trivialement).
- **En aval (gate d'activation, HORS story)** : le flip `is_active=true` est conditionné à la micro-évolution agent « `name: ""` = valeur par défaut de la clé » (~3 lignes dans `parseRegistrySpec` + clarification contrat §7.1 + bump + note de publication). Candidat de portage : 35.2 ou 35.3 (touchent déjà `handler_registry.go`), sinon micro-story 35.5bis — **décision d'orchestration à arbitrer avec Henri** (cf. Découverte de cadrage).

## Recommandation Modèle Dev

**opus** — prescription de l'epic confirmée : la story est du seed + tests purs (aucun moteur, aucun Go), mais elle exige une fidélité de donnée à l'octet (commande EXPAND_SZ, 2 Clsid, `name:''`) et le respect strict du gate — opus plutôt que sonnet pour ne pas « corriger » les quirks iso-GPO. Si l'arbitrage étend le scope à la micro-évolution agent (`parseRegistrySpec`), la story bascule en **fable** (garde-fou epic : fable pour tout ce qui touche l'agent Go).

## Dev Agent Record

### Agent Model Used

### Debug Log References

### Completion Notes List

### File List

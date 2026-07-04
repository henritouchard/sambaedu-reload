# Story 36.3 : Lot bibliothèque n°2 — capacités registre pures Explorateur (zéro moteur)

Status: review

<!-- Source d'autorité : _bmad-output/planning-artifacts/epics-mecanismes-hors-registre.md#Story-36.3 + #Overview (doctrine) + #Garde-fous-d'epic (Epic 36 ne figure PAS dans epics.md). -->
<!-- Décisions Henri : _bmad-output/ultradev/36-questions.md (Q1-Q4 — aucune ne porte sur 36.3, story sans question ouverte). -->
<!-- ⚠️ GARDE-FOU CENTRAL : les clés registre de ce lot sont issues du DÉCODAGE DOCUMENTAIRE, PAS d'une vérification sur poste — chaque clé est marquée « À VÉRIFIER SUR POSTE LAB AVANT SEED » et le protocole de vérification est un GATE de review bloquant avant migrate /vm. Ne JAMAIS prétendre les avoir vérifiées. -->

## Story

En tant que **référent numérique**,
je veux **masquer les éléments superflus de l'Explorateur (pins de la barre latérale, Accès rapide…)**,
afin d'**épurer les postes pédagogiques — sans attendre aucun nouveau mécanisme**.

## Contexte & intention

L'Epic 36 paie deux nouveaux mécanismes (`fs_acl` 36.1, `firewall` 36.2). Cette story est le **témoin de doctrine** : elle prouve que, le mécanisme `registry` étant payé (27.12 + Epic 35), une capacité supplémentaire est **de la donnée pure** — coût marginal ≈ une migration de seed + tests. Elle couvre l'exemple fondateur d'Henri « masquer les pins de la barre latérale » (cf. tableau de couverture de l'epic).

**La story ne touche NI l'agent, NI le contrat, NI les providers, NI le StateCompiler.** Diff attendu = UNE migration de seed (patron exact CD95/ISO, idempotente) + tests de seed. Aucun bump `agent/shared/version.go`, aucune release, aucune UI (les onglets capacités existants affichent la donnée).

Le lot seed **4 capacités registre pures**, toutes **opt-in** (`default_value = 'unmanaged'`, sentinelle hors map ⇒ **rien n'est émis en broadcast** — golden files et `FROZEN_STATE_HASH` strictement intacts) :

| # | `key` | Intention | Statut epic |
|---|---|---|---|
| 1 | `explorer_sidebar_pins_hidden` | masquer les dossiers utilisateur épinglés du volet de navigation (Bureau, Documents, Images, …) | minimum requis |
| 2 | `quick_access_hidden` | masquer Accès rapide / réduire le volet au strict Ce PC | minimum requis |
| 3 | `explorer_gallery_hidden` | masquer la Galerie (Windows 11) du volet | candidat confirmé au lot |
| 4 | `quick_access_history_hidden` | couper l'historique de l'Accès rapide (fichiers récents + dossiers fréquents) | candidat confirmé au lot |

## ⚠️ Garde-fou de véracité des clés (à lire AVANT de coder)

L'AC d'epic exige : « chaque clé est vérifiée sur poste lab AVANT seed (pas de clé recopiée de mémoire) ». Le dev n'a **pas d'accès à un poste Windows lab** (SSH = serveurs seulement). Résolution — cohérente avec « zéro prod : publier = tester » (la migration n'atteint /vm qu'après validation) :

1. Le dev seed les clés du tableau « Spécification » ci-dessous, qui sont des **candidates issues du décodage documentaire** (patron `onedrive_hidden` + tweaks Windows documentés) — le dev ne les modifie pas, ne les « corrige » pas, n'en invente pas.
2. Le dev marque EXPLICITEMENT dans le Dev Agent Record : **« clés NON vérifiées sur poste — protocole lab à dérouler avant migrate /vm »**.
3. Le **Protocole de vérification lab** (section dédiée) est le gate de review : Henri (ou l'e2e lab) le déroule ; toute clé qui échoue est **retirée de la migration avant merge** (jamais de clé « au cas où ») ; si toutes les clés d'une capacité tombent, la capacité sort du lot et l'écart est consigné (les capacités 3 et 4 sont des CANDIDATS conditionnels par construction d'epic).
4. Interdiction absolue de « prétendre vérifié » : ni le docblock de migration ni les tests ne doivent affirmer une vérification poste qui n'a pas eu lieu. Le docblock dit « candidates décodage documentaire, gate lab § story 36.3 ».

## Décisions de design (tranchées)

1. **Zéro moteur, zéro `$ensure`** : toutes les maps sont **symétriques à valeurs réelles** (si l'UI propose « off », off écrit une vraie valeur qui restaure le comportement Windows par défaut — invariant 27.12). Aucun marqueur `{"$ensure": "absent"}` nécessaire dans ce lot.
2. **Tout opt-in** (`default_value = 'unmanaged'`, options `[unmanaged, on, off]`) : l'épuration du volet est un choix pédagogique par parc, pas un défaut de flotte. Conséquence structurante : **rien en broadcast ⇒ golden intacts** (c'est testé).
3. **Portée = la ruche décodée, pas le cadrage.** L'epic annonce « portée Session » pour `explorer_sidebar_pins_hidden` (extrapolation du patron `onedrive_hidden`) ; le décodage documentaire pointe `ThisPCPolicy` sous **HKLM** (`FolderDescriptions\…\PropertyBag`) — les 6 dossiers utilisateur du volet n'ont pas de CLSID per-user documenté, contrairement à OneDrive/Accueil/Galerie. La capacité 1 est donc seedée **portée Machine (HKLM)** ; l'écart vs l'epic est assumé et consigné (Dev Agent Record + docblock). Si le protocole lab révèle une variante per-user fonctionnelle, la correction se fait AVANT merge.
4. **Ruches mixtes dans une même projection = supporté** (précédent `numlock_on_logon` HKCU+HKU) : `quick_access_hidden` porte 1 clé HKLM (HubMode — provider Machine/SYSTEM) + 2 clés HKCU (LaunchTo + CLSID Accueil — provider Session/compagnon). Chaque provider filtre par `hive`, rien à coder.
5. **Routage HKCR → `HKCU\Software\Classes`** (iso `onedrive_hidden`, seed ISO l.212-215) pour les clés CLSID (Accueil Win11, Galerie Win11) : vue per-user écrite par le compagnon de session, portée Session, `System.IsPinnedToNameSpaceTree` REG_DWORD `{on: 0 (masqué), off: 1 (affiché)}`.
6. **Le CLSID OneDrive `{018D5C66-4533-4307-9B53-224DE2ED1FE6}` est INTERDIT dans ce lot** : il appartient à `onedrive_hidden` (seed ISO). Deux capacités écrivant la même clé `{hive|path|name}` seraient arbitrées silencieusement par le compilateur (même `exclusiveKey`) — résultat imprévisible. Un test structurel d'anti-collision le verrouille (voir AC2), pour ce lot ET les suivants.
7. **Convention libellés « sujet + état »** : label = sujet NEUTRE (« Accès rapide (volet de navigation) »), le statut est porté par les libellés de valeurs (« Masqué » / « Affiché ») ; « Non géré » RÉSERVÉ à la sentinelle `unmanaged`.
8. **Effet différé documenté, pas de warning** : les clés HKCU appliquées par le compagnon prennent effet au **logon suivant** / redémarrage d'Explorer (mémoire projet « Registre — effet HKCU logon suivant ») ; mention brève dans les `description` (≤ 255 chars, varchar PG !), `warning = null` (aucune capacité du lot n'est destructive).
9. **Ni Wow6432Node, ni `ShowCloudFilesInQuickAccess`, ni « Objets 3D » en v1** : extensions décodables au protocole lab, HORS seed initial (pas de clé non vérifiable « au cas où »). Consignées en pièges/notes.

## Spécification de la donnée seedée

Champs communs aux 4 capacités : `category = 'Bureau'`, `value_type = 'toggle'`, `options = [unmanaged → « Non géré », on → « Masqué(e)(s) », off → « Affiché(e)(s) »]` (accords précis ci-dessous), `default_value = 'unmanaged'`, `warning = null`, `applies_to_os = ["windows"]`, `is_active = true`, `overrides_locked = false`. Encodage iso seeds : `JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES`. **Chaque clé ci-dessous = À VÉRIFIER SUR POSTE LAB AVANT migrate /vm.**

### 1. `explorer_sidebar_pins_hidden` — portée Machine (HKLM, décision D3)

- `label` : `Dossiers épinglés du volet de navigation`
- `description` (idées à porter, formulation libre ≤255) : masque les dossiers utilisateur (Bureau, Documents, Images, Musique, Vidéos, Téléchargements) de « Ce PC » et du volet de navigation de l'Explorateur ; réglage machine, effet au redémarrage d'Explorer/session suivante.
- `options` : `unmanaged` → « Non géré », `on` → « Masqués », `off` → « Affichés »

Projection `windows`/`registry` — 6 clés, toutes `hive: HKLM`, `name: ThisPCPolicy`, `type: REG_SZ`, `value: ['on' => 'Hide', 'off' => 'Show']` (map symétrique — `Show` est la valeur Windows par défaut), `path` = `SOFTWARE\Microsoft\Windows\CurrentVersion\Explorer\FolderDescriptions\{GUID}\PropertyBag` avec :

| Dossier | `{GUID}` (candidat décodage documentaire — À VÉRIFIER LAB) |
|---|---|
| Documents | `{f42ee2d3-909f-4907-8871-4c22fc0bf756}` |
| Images | `{0ddd015d-b06c-45d5-8c4c-f59713854639}` |
| Musique | `{a0c69a99-21c8-4671-8703-7934162fcf1d}` |
| Vidéos | `{35286a68-3c57-41a1-bbb1-0eae73d76c95}` |
| Téléchargements | `{7d83ee9b-2244-4e70-b1f5-5393042af1e4}` |
| Bureau | `{B4BFCC3A-DB2C-424C-B029-7FE99A87C641}` |

### 2. `quick_access_hidden` — portées mixtes (HKLM + HKCU, décision D4)

- `label` : `Accès rapide (volet de navigation)`
- `description` (idées) : masque « Accès rapide » (Windows 10) et « Accueil » (Windows 11) du volet, et ouvre l'Explorateur sur « Ce PC » ; effet à la session suivante.
- `options` : `unmanaged` → « Non géré », `on` → « Masqué (volet réduit à Ce PC) », `off` → « Affiché »

Projection `windows`/`registry` — 3 clés (À VÉRIFIER LAB) :

| # | `hive` | `path` | `name` | `type` | `value` map |
|---|---|---|---|---|---|
| 1 | `HKLM` | `SOFTWARE\Microsoft\Windows\CurrentVersion\Explorer` | `HubMode` | `REG_DWORD` | `on` → `1` (Accès rapide masqué) ; `off` → `0` (affiché — comportement par défaut) |
| 2 | `HKCU` | `Software\Microsoft\Windows\CurrentVersion\Explorer\Advanced` | `LaunchTo` | `REG_DWORD` | `on` → `1` (ouvrir sur Ce PC) ; `off` → `2` (ouvrir sur Accès rapide — défaut Windows) |
| 3 | `HKCU` | `Software\Classes\CLSID\{f874310e-b6b7-47dc-bc84-b9e6b38f5903}` | `System.IsPinnedToNameSpaceTree` | `REG_DWORD` | `on` → `0` (Accueil Win11 masqué) ; `off` → `1` (affiché) |

(Clé 3 = face Windows 11 de l'intention — HubMode ne gouverne pas « Accueil » ; routage HKCR→HKCU iso `onedrive_hidden`. Le tree `Explorer\Advanced` est user-writable standard — mêmes path/droits que `HideFileExt`/`Hidden` du lot ISO, PAS un tree `HKCU\Software\Policies`.)

### 3. `explorer_gallery_hidden` — portée Session (HKCU), candidat

- `label` : `Galerie (volet de navigation, Windows 11)`
- `description` (idées) : masque l'entrée « Galerie » du volet de navigation (Windows 11 ; sans effet sur Windows 10) ; effet à la session suivante.
- `options` : `unmanaged` → « Non géré », `on` → « Masquée », `off` → « Affichée »

Projection `windows`/`registry` — 1 clé (À VÉRIFIER LAB) : `hive: HKCU`, `path: Software\Classes\CLSID\{e88865ea-0e1c-4e20-9aa6-edcd0212c87c}`, `name: System.IsPinnedToNameSpaceTree`, `type: REG_DWORD`, `value: ['on' => 0, 'off' => 1]` (patron exact `onedrive_hidden`).

### 4. `quick_access_history_hidden` — portée Session (HKCU), candidat

- `label` : `Historique de l'Accès rapide (récents et fréquents)`
- `description` (idées) : n'affiche plus les fichiers récemment utilisés ni les dossiers fréquents dans l'Accès rapide de l'Explorateur ; effet à la session suivante.
- `options` : `unmanaged` → « Non géré », `on` → « Masqué », `off` → « Affiché »

Projection `windows`/`registry` — 2 clés (À VÉRIFIER LAB), toutes `hive: HKCU`, `path: Software\Microsoft\Windows\CurrentVersion\Explorer`, `type: REG_DWORD` :

| `name` | `value` map |
|---|---|
| `ShowRecent` | `on` → `0` (récents masqués) ; `off` → `1` (affichés — défaut Windows) |
| `ShowFrequent` | `on` → `0` (fréquents masqués) ; `off` → `1` (affichés — défaut) |

(Tree `CurrentVersion\Explorer` user-writable standard, PAS `Software\Policies` — conforme au garde-fou epic : rien de ce lot n'est sous `HKCU\Software\Policies`, rien n'est à porter en HKLM/HKU pour cause de droits compagnon.)

## Acceptance Criteria

### AC1 — Seed : 4 capacités + projections (migration NOUVELLE, idempotente, patron CD95/ISO)

**Given** le mécanisme `registry` ACTUEL (aucune évolution)
**When** la nouvelle migration `2026_07_04_100000_seed_capabilities_explorer_lot.php` est jouée
**Then** les 4 capacités du tableau « Spécification » existent conformes (keys, labels, options avec libellés exacts, `default_value = 'unmanaged'`, catégorie `Bureau`, `applies_to_os = ["windows"]`, `is_active = true`)
**And** chaque projection `windows`/`registry` porte EXACTEMENT les clés spécifiées (hives, paths, GUID, names, types, maps) — aucune clé ajoutée, aucune « corrigée »
**And** la migration suit le patron EXACT du lot CD95 (`updateOrInsert` par `key` puis par `(capability_id, os, mechanism)`, garde `Schema::hasTable`, rejouable sans effet de bord, `down()` par `whereIn('key', […])->delete()` — FK cascade → projections/overrides)
**And** le docblock documente : la doctrine (témoin « capacité = donnée, coût marginal ≈ 0 »), le statut « candidates décodage documentaire — gate lab story 36.3 » (SANS prétendre une vérification poste), le routage HKCR→HKCU (clés CLSID), l'écart de portée de la capacité 1 (HKLM vs « Session » du cadrage, décision D3), l'exclusion du CLSID OneDrive (possédé par `onedrive_hidden`).

### AC2 — Invariants de donnée : maps symétriques, off honnête, anti-collision

**Given** les projections seedées
**Then** CHAQUE clé porte une map `{on, off}` à **valeurs réelles** (jamais de no-op, jamais de `$ensure` dans ce lot) ; les 4 keys sont AJOUTÉES à `$withOff` du test `on_off_capabilities_emit_a_real_value_for_off`
**And** aucun libellé d'option n'usurpe « Non géré » (réservé à la sentinelle `unmanaged`)
**And** un NOUVEAU test structurel d'anti-collision prouve que les identités normalisées `{hive|path|name}` (lowercase, iso `AbstractCapabilityStateProvider::exclusiveKey`) des clés du lot sont uniques ENTRE ELLES et DISJOINTES de toutes les autres projections `registry`/`registry_list` seedées du catalogue — en particulier le CLSID OneDrive de `onedrive_hidden` n'apparaît pas dans le lot
**And** le garde-fou d'authoring existant reste vert sur le catalogue étendu (`no_container_is_targeted_by_both_registry_scalar_and_registry_list` passe sans modification)
**And** le test structurel `all_seeded_capability_strings_fit_their_postgres_varchar_columns` passe (labels/descriptions/catégories ≤ 255 — piège SQLite/PG 22001).

### AC3 — Preuve de doctrine : zéro moteur, golden intacts

**Given** la story livrée
**Then** `git status` ne montre AUCUNE modification sous `app/Services/Agent/**`, `agent/**`, `tests/Fixtures/Agent/**`, `routes/**` — diff = 1 migration (nouvelle) + tests de seed
**And** tout étant opt-in `unmanaged`, RIEN n'est émis en broadcast : golden files et `FROZEN_STATE_HASH`/`frozenStateHash` STRICTEMENT inchangés (preuve : `ContractV1Test` vert, non modifié)
**And** aucun bump `agent/shared/version.go`, aucune note de publication (l'agent publié ≥ 2.5.0 sait déjà appliquer ces clés — scalaires `REG_DWORD`/`REG_SZ` à `name` non vide, HKLM/HKCU).

### AC4 — Chaîne provider prouvée sur données réelles (pattern 35.x)

**Given** un poste + parc logique de test (factories, observers `disableSync`)
**When** un override de parc `on` est posé sur `quick_access_hidden`
**Then** `RegistryMachineCapabilityProvider` émet 1 item HKLM (`HubMode`, value `1`) et `RegistryUserCapabilityProvider` émet 2 items HKCU (`LaunchTo` value `1` + `System.IsPinnedToNameSpaceTree` du CLSID Accueil value `0`) — payload 5 clés `{hive, path, name, type, value}`, sans fuite d'id de capacité
**And** avec l'override `off`, les MÊMES identités de clés portent les valeurs réelles `0` / `2` / `1` (maps symétriques prouvées au provider)
**And** sans aucun override (défaut `unmanaged`), AUCUN item n'est émis pour les 4 capacités par les deux providers
**And** un override `on` sur `explorer_gallery_hidden` n'émet RIEN côté Machine (aucune clé HKLM) et 1 item côté Session — vérification du filtre par ruche.

### AC5 — Gate lab explicite (véracité des clés)

**Given** le garde-fou de véracité
**Then** la section « Protocole de vérification lab » de cette story est complète et actionnable (commandes `reg add`/`reg query` + vérification comportementale des DEUX faces on/off par capacité)
**And** le Dev Agent Record déclare explicitement : « clés candidates décodage documentaire, NON vérifiées sur poste par le dev — protocole lab = gate bloquant avant migrate /vm ; toute clé invalidée sera retirée de la migration avant merge »
**And** la migration n'est PAS jouée sur /vm par le dev (jamais auto-appliquée ; action humaine post-gate).

## Tasks / Subtasks

- [x] **Task 1 — Migration de seed (AC1, AC2)**
  - [x] 1.1 Créer `database/migrations/2026_07_04_100000_seed_capabilities_explorer_lot.php` (timestamp à décaler si la story parallèle 36.1 a pris le créneau — seul le nom doit être unique) : patron EXACT `2026_07_02_100000_seed_capabilities_gpo_cd95_lot.php` (structure `$lot`, up `updateOrInsert` capacité par `key` + projection par `(capability_id, os, mechanism)`, down `whereIn('key')->delete()`, garde `hasTable`, `JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES`).
  - [x] 1.2 Donnée STRICTEMENT conforme aux 4 tableaux « Spécification » (GUID à l'octet près, casse des paths iso-précédents : `SOFTWARE\…` en HKLM, `Software\…` en HKCU, backslashes doublés en PHP). Options à 3 valeurs `[unmanaged, on, off]` avec les libellés exacts.
  - [x] 1.3 Docblock conforme AC1 (doctrine, statut candidates/gate lab, routage HKCR, écart portée capacité 1, interdit CLSID OneDrive).
- [x] **Task 2 — Tests de seed (AC1, AC2)** — dans `tests/Feature/Migrations/CapabilitiesSchemaAndSeedTest.php`, section « Story 36.3 » (append-only, ne pas toucher les sections 35.x)
  - [x] 2.1 `explorer_lot_is_seeded_with_expected_capabilities_and_keys` : pour chaque capacité — champs (`default_value = 'unmanaged'`, `allowedOptionValues() === ['unmanaged','on','off']`, libellés via `optionLabel()`, catégorie, `applies_to_os`) + clés EXACTES (assertSame hive/path/name/type/maps ; 6 GUID de la capacité 1, les 3 clés mixtes de la 2, CLSID Galerie, ShowRecent/ShowFrequent).
  - [x] 2.2 Ajouter les 4 keys à `$withOff` dans `on_off_capabilities_emit_a_real_value_for_off` (le test générique vérifie déjà « off = vraie valeur »).
  - [x] 2.3 `explorer_lot_migration_is_idempotent_and_reversible` : pattern `registry_list_lot_migration_is_idempotent_and_reversible` (require migration, snapshot options+specs des 4 keys, up() rejoué = identique, down() = catalogue vidé des 4, up() = re-seed identique).
  - [x] 2.4 `explorer_lot_keys_do_not_collide_with_any_seeded_registry_key` (NOUVEAU test structurel, AC2) : construire l'identité normalisée `strtolower("{hive}|{path}|{name}")` de chaque clé des 4 nouvelles projections ; asserter unicité interne AU lot ET intersection VIDE avec les identités de TOUTES les autres projections seedées (`registry` : chaque `spec.keys[*]` → `hive|path|name` ; `registry_list` : clé-conteneur → `hive|path|`) — réutiliser `seededWindowsProjections()` comme source. Assertion dédiée : le CLSID OneDrive `{018d5c66-…}` n'apparaît dans AUCUNE clé du lot.
- [x] **Task 3 — Test chaîne provider sur données réelles (AC4)**
  - [x] 3.1 `quick_access_hidden_emits_split_machine_and_session_items_via_the_real_providers` : pattern `numlock_on_logon_emits_hku_machine_and_hkcu_session_items_via_the_real_providers` (disableSync, factories poste+parc logique, `TargetContext::for($ws, null)`) — override `on` ⇒ Machine 1 item HubMode=1 / User 2 items (LaunchTo=1, CLSID Accueil=0) ; override `off` ⇒ valeurs 0/2/1 ; payloads 5 clés sans fuite d'id.
  - [x] 3.2 Dans le même test : défaut `unmanaged` (avant tout override) ⇒ 0 item pour les 4 capacités des deux providers ; `explorer_gallery_hidden` armée `on` ⇒ Machine 0 item / User 1 item.
- [x] **Task 4 — Validation finale (AC3, AC5)**
  - [x] 4.1 Tests HÔTE ciblés UNIQUEMENT (php8.4 + sqlite, jamais de run massif) : `CapabilitiesSchemaAndSeedTest` puis `CapabilityRegistryProviderTest|CapabilityRegistryCompilationTest|ContractV1Test` (non-régression + golden figés). Worktree : `APP_BASE_PATH="$PWD" php -d variables_order=EGPCS vendor/bin/phpunit --configuration phpunit.xml --filter='…'` (piège autoload symlink `vendor/`, cf. Dev Agent Record 35.5).
  - [x] 4.2 `git status` : SEULS la migration (nouvelle) et `CapabilitiesSchemaAndSeedTest.php` sont touchés — aucun fichier `app/Services/Agent/**`, `agent/**`, `tests/Fixtures/Agent/**`, `routes/**`.
  - [x] 4.3 Dev Agent Record : déclaration AC5 (clés non vérifiées poste, gate lab bloquant) + « migration à rejouer sur /vm APRÈS validation lab (action humaine) » + « AUCUNE release agent ».

## Protocole de vérification lab (GATE de review — à dérouler sur poste lab Windows joint, PAS par le dev)

Pour CHAQUE capacité : poser les clés « on », **fermer/rouvrir la session** (clés HKCU) ou redémarrer Explorer (`taskkill /f /im explorer.exe & start explorer.exe`), vérifier l'effet ; poser les valeurs « off », re-vérifier le retour au comportement par défaut. Les deux faces doivent être prouvées (maps symétriques).

| Capacité | Pose « on » (exemples) | Vérification comportementale |
|---|---|---|
| 1 `explorer_sidebar_pins_hidden` | `reg add "HKLM\SOFTWARE\Microsoft\Windows\CurrentVersion\Explorer\FolderDescriptions\{f42ee2d3-909f-4907-8871-4c22fc0bf756}\PropertyBag" /v ThisPCPolicy /t REG_SZ /d Hide /f` (×6 GUID) | Documents (puis chacun des 6) disparaît de « Ce PC » ET du volet ; `off` (`/d Show`) les réaffiche. Vérifier AUSSI que chaque GUID candidat correspond bien au dossier annoncé (`reg query …\{GUID} /v Name` ou contenu du PropertyBag). |
| 2 `quick_access_hidden` | `reg add "HKLM\SOFTWARE\Microsoft\Windows\CurrentVersion\Explorer" /v HubMode /t REG_DWORD /d 1 /f` + `reg add "HKCU\Software\Microsoft\Windows\CurrentVersion\Explorer\Advanced" /v LaunchTo /t REG_DWORD /d 1 /f` + `reg add "HKCU\Software\Classes\CLSID\{f874310e-b6b7-47dc-bc84-b9e6b38f5903}" /v System.IsPinnedToNameSpaceTree /t REG_DWORD /d 0 /f` | Win10 : « Accès rapide » absent du volet ; Win11 : « Accueil » absent ; l'Explorateur s'ouvre sur « Ce PC ». `off` (0 / 2 / 1) restaure. Vérifier notamment que `HubMode=0` (off) restaure bien (et non la seule suppression de la valeur). |
| 3 `explorer_gallery_hidden` | `reg add "HKCU\Software\Classes\CLSID\{e88865ea-0e1c-4e20-9aa6-edcd0212c87c}" /v System.IsPinnedToNameSpaceTree /t REG_DWORD /d 0 /f` | Win11 : « Galerie » absente du volet ; `off` (1) la réaffiche ; Win10 : aucun effet (assumé, description). |
| 4 `quick_access_history_hidden` | `reg add "HKCU\Software\Microsoft\Windows\CurrentVersion\Explorer" /v ShowRecent /t REG_DWORD /d 0 /f` + idem `ShowFrequent` | Accès rapide/Accueil ne liste plus fichiers récents ni dossiers fréquents ; `off` (1/1) restaure. |

Issues possibles à consigner : GUID/valeur divergente selon build Windows (corriger la migration avant merge) ; besoin Wow6432Node pour les dialogues 32 bits (extension future, pas v1) ; `ShowCloudFilesInQuickAccess` (Win11, extension future).

## Dev Notes

### Fichiers à toucher (exhaustif prévu)

| Fichier | Nature |
|---|---|
| `database/migrations/2026_07_04_100000_seed_capabilities_explorer_lot.php` | NOUVEAU — seed des 4 capacités + projections |
| `tests/Feature/Migrations/CapabilitiesSchemaAndSeedTest.php` | modifié — section 36.3 (4 tests) + `$withOff` (+4 keys) |

**NE PAS TOUCHER** : `app/Services/Agent/**` (providers, StateCompiler, StateHasher, guards — zéro moteur), `agent/**` (pas de bump, pas de release), `tests/Fixtures/Agent/**` + `FROZEN_STATE_HASH`/`frozenStateHash`, seeds/migrations existants (nouvelle migration, on ne réécrit pas l'histoire), `routes/**`, UI (les onglets capacités existants affichent la donnée), `sprint-status.yaml`/`backlog.data.js` (orchestrateur), et les fichiers des stories parallèles 36.1/36.2/36.4 (worktrees séparés — seules frictions prévisibles au merge : créneau de timestamp de migration et section append de `CapabilitiesSchemaAndSeedTest`, rebase trivial).

### Patterns existants à imiter (le dev n'invente RIEN)

- **Seed multi-capacités** : `2026_07_02_100000_seed_capabilities_gpo_cd95_lot.php` — structure `$lot`, `updateOrInsert`, options factorisées, down par keys. C'est LE patron (l'epic dit « pattern exact CD95/ISO »).
- **Clé CLSID `System.IsPinnedToNameSpaceTree`** : `2026_06_18_100300_seed_capabilities_iso_lot.php` l.202-217 (`onedrive_hidden`) — reprendre la formulation du commentaire de routage HKCR→HKCU et la map `{on: 0, off: 1}`.
- **Projection à ruches mixtes** : `numlock_on_logon` (HKCU+HKU) — précédent prouvant qu'une même `spec.keys` sert deux providers ; ici HKLM+HKCU.
- **Tests seed/idempotence/provider réels** : `CapabilitiesSchemaAndSeedTest.php` — `pix_extension_forced_is_seeded_…`, `registry_list_lot_migration_is_idempotent_and_reversible`, `numlock_on_logon_emits_hku_machine_and_hkcu_session_items_via_the_real_providers` (mêmes idiomes : `require database_path(…)`, snapshot closure, `disableSync`, `TargetContext::for`), helper `seededWindowsProjections()` réutilisable pour l'anti-collision.

### Pièges

1. **Ne rien « corriger »** : GUID, valeurs et casse des tableaux « Spécification » sont la donnée de la story — toute divergence découverte se traite via le gate lab, pas par initiative du dev.
2. **Ne pas dupliquer le CLSID OneDrive** `{018D5C66-…}` (possédé par `onedrive_hidden`) — l'anti-collision (Task 2.4) doit échouer si quelqu'un l'ajoute un jour.
3. **`HKCU\Software\Policies` interdit au compagnon** (leçon Copilot) : AUCUNE clé du lot n'y vit — si une vérification lab suggérait d'y basculer une clé, c'est un portage HKLM/HKU à arbitrer (35.3), JAMAIS une émission compagnon.
4. **Casse iso-précédents** : `SOFTWARE\…` (HKLM) vs `Software\…` (HKCU), backslashes doublés en PHP ; l'identité `exclusiveKey` est insensible à la casse mais les tests assertSame ne le sont pas.
5. **Toggle à 3 options** `[unmanaged, on, off]` : déjà supporté (précédent `blocked_executables`), aucun changement de modèle.
6. **Descriptions ≤ 255 chars** (varchar PG, invisible en SQLite — test structurel `all_seeded_capability_strings_fit_their_postgres_varchar_columns` le verrouille, piège PG 22001).
7. **Tests HÔTE uniquement, filtres ciblés** ; migration JAMAIS jouée sur /vm par le dev (gate lab d'abord).
8. **Worktree** : `vendor/` symlinké → `APP_BASE_PATH="$PWD" php -d variables_order=EGPCS vendor/bin/phpunit …` sinon le migrateur lit les migrations du repo principal (faux échecs — Dev Agent Record 35.5).

### Project Structure Notes

- La story vit dans `database/migrations/` + `tests/Feature/Migrations/` exclusivement.
- Aucune UI : parc-defaults (`registry-tab`) et onglets d'armement affichent les capacités actives automatiquement ; les 4 étant `unmanaged` par défaut, rien ne change au poste tant qu'un override n'est pas posé.
- Entrée QA runbook (`docs/qa/domains/agent.md`) : une courte section « Story 36.3 » (armement d'un override de parc + vérification poste) peut être ajoutée en append-only APRÈS validation lab — optionnelle au dev, obligatoire avant clôture d'epic.

### References

- [Source: _bmad-output/planning-artifacts/epics-mecanismes-hors-registre.md#Story-36.3 + #Overview + #Garde-fous-d'epic] — autorité de cadrage (AC epic, doctrine, candidats galerie/historique)
- [Source: _bmad-output/ultradev/36-questions.md] — décisions Henri Q1-Q4 (aucune ne contraint 36.3)
- [Source: database/migrations/2026_07_02_100000_seed_capabilities_gpo_cd95_lot.php] — patron de seed (structure, idempotence, opt-in unmanaged, options factorisées)
- [Source: database/migrations/2026_06_18_100300_seed_capabilities_iso_lot.php#onedrive_hidden l.202-217] — patron CLSID `System.IsPinnedToNameSpaceTree` + routage HKCR→HKCU
- [Source: database/migrations/2026_07_03_110000_seed_capabilities_registry_list_lot.php] — patron toggle 3 options + docblock de lot
- [Source: tests/Feature/Migrations/CapabilitiesSchemaAndSeedTest.php] — invariant on/off (`$withOff`), varchar structurel, idempotence, tests providers réels, `seededWindowsProjections()`
- [Source: app/Services/Agent/Providers/AbstractCapabilityStateProvider.php — hive()/handlesHive()/exclusiveKey() l.123-157, sentinelle UNMANAGED]
- [Source: app/Services/Agent/Providers/RegistryMachineCapabilityProvider.php + RegistryUserCapabilityProvider.php] — split HKLM(+HKU)/Machine vs HKCU/Session
- [Source: app/Models/Capability.php + app/Models/CapabilityProjection.php] — schéma/casts/`allowedOptionValues()`/`optionLabel()`
- [Source: _bmad-output/implementation-artifacts/35-5-capacite-photo-viewer-restored.md] — précédent « seed sans moteur » + piège worktree/autoload
- Mémoire projet : « Capacités — maps symétriques on/off », « Capacités — libellé sujet+état », « Registre — effet HKCU logon suivant », « HKCU\Policies non écrivable par le companion », « Tests SQLite — varchar non appliqué », « Pas de choix sur-conçu »

## Dépendances

- **Aucune dépendance intra-epic** : 36.3 est la story « zéro moteur » de la vague 1, développable en parallèle de 36.1 et indépendante de 36.2/36.4. Ne toucher à AUCUN fichier de ces stories (worktrees séparés) ; frictions de merge prévisibles = créneau de timestamp de migration + section append de `CapabilitiesSchemaAndSeedTest` (rebase trivial).
- **Requiert (déjà LIVRÉ sur main)** : le mécanisme `registry` capability-first (27.12) + Epic 35 (agent 2.5.0 publié) — les clés du lot sont des scalaires `REG_DWORD`/`REG_SZ` à `name` non vide sur HKLM/HKCU : l'agent publié les applique sans modification. Aucun `$ensure` (35.1) ni `registry_list` (35.2) ni HKU (35.3) n'est utilisé.
- **En aval (HORS story, actions humaines)** : (1) gate lab — dérouler le « Protocole de vérification lab » et corriger/élaguer la migration avant merge ; (2) `php artisan migrate` sur /vm APRÈS le gate (jamais auto-appliquée) ; (3) armement métier = overrides par parc via l'UI existante (aucun geste de cette story).

## Recommandation Modèle Dev

**opus** — prescription explicite de l'epic (« opus pour 36.3 (seed) ») confirmée à la création : seed + tests purs, zéro Go, zéro moteur ; l'exigence est la fidélité de donnée (GUID à l'octet, maps symétriques, libellés exacts) et la discipline de NE PAS « corriger » les clés candidates ni déborder du diff attendu.

## Dev Agent Record

### Agent Model Used

Claude Opus 4.8 (prescription epic — seed + tests purs, fidélité de donnée).

### Debug Log References

- Worktree `ultradev/36-3` sans `bootstrap/cache/` initial (dossier gitignoré, jamais créé après le checkout) → `Exception: The .../bootstrap/cache directory must be present and writable` sur TOUS les tests. Fix : `mkdir -p bootstrap/cache && chmod 775 bootstrap/cache` (aucun fichier versionné concerné, `.gitignore:11` couvre déjà `/bootstrap/cache/`).
- Commande hôte utilisée (piège autoload symlink `vendor/`, cf. Dev Agent Record 35.5) : `APP_BASE_PATH="$PWD" php -d variables_order=EGPCS vendor/bin/phpunit --configuration phpunit.xml --filter='…'`.

### Completion Notes List

- **AC5 — déclaration explicite (garde-fou de véracité)** : les clés registre des 4 capacités de ce lot sont des **candidates issues du décodage documentaire** (patron `onedrive_hidden` + tweaks Windows documentés), **NON vérifiées sur poste par le dev** (pas d'accès à un poste Windows lab — SSH = serveurs seulement). Le « Protocole de vérification lab » de cette story est un **GATE DE REVIEW BLOQUANT** à dérouler par Henri (ou l'e2e lab) **AVANT** `php artisan migrate` sur /vm ; toute clé invalidée doit être retirée de la migration avant merge. La migration **n'a PAS été jouée sur /vm** par le dev (action humaine post-gate).
- **Écart de portée assumé (D3)** : `explorer_sidebar_pins_hidden` est seedée portée **Machine (HKLM)**, PAS « Session » comme l'annonçait le cadrage de l'epic — le décodage documentaire pointe `ThisPCPolicy` sous HKLM (`FolderDescriptions\{GUID}\PropertyBag`), les 6 dossiers utilisateur du volet n'ayant pas de CLSID per-user documenté (contrairement à OneDrive/Accueil/Galerie). Consigné au docblock de la migration + à la section « Décisions de design » de la story. Si le protocole lab révèle une variante per-user fonctionnelle, la correction est à faire AVANT merge (pas par ce dev, hors accès lab).
- **AUCUNE release agent** : zéro modification de `agent/**`, aucun bump `agent/shared/version.go` — l'agent publié ≥ 2.5.0 sait déjà appliquer ces clés (scalaires `REG_DWORD`/`REG_SZ` à `name` non vide, HKLM/HKCU, sans `$ensure` dans ce lot).
- **Zéro moteur confirmé par `git status`** : diff = 1 migration nouvelle + `CapabilitiesSchemaAndSeedTest.php` (section 36.3 append-only) + les artefacts d'orchestration (story, sprint-status, doc QA). Aucun fichier `app/Services/Agent/**`, `agent/**`, `tests/Fixtures/Agent/**`, `routes/**` touché.
- **Tests** : `CapabilitiesSchemaAndSeedTest` 41/41 passed (822 assertions, dont les 5 nouveaux tests 36.3 + `$withOff` étendu). Non-régression + golden : `ContractV1Test`+`CapabilityRegistryProviderTest`+`CapabilityRegistryCompilationTest` 44/44 passed (278 assertions) — `FROZEN_STATE_HASH`/`frozenStateHash` et golden files strictement inchangés (les 4 capacités sont `unmanaged`, rien n'est émis en broadcast).
- **Anti-collision (AC2)** : le test structurel `explorer_lot_keys_do_not_collide_with_any_seeded_registry_key` prouve l'unicité interne des identités `{hive|path|name}` du lot ET leur disjonction avec TOUT le catalogue déjà seedé (registry + registry_list), avec une assertion dédiée sur l'absence du CLSID OneDrive `{018D5C66-4533-4307-9B53-224DE2ED1FE6}`.

### File List

- `database/migrations/2026_07_04_100000_seed_capabilities_explorer_lot.php` (nouveau)
- `tests/Feature/Migrations/CapabilitiesSchemaAndSeedTest.php` (modifié — 4 keys ajoutées à `$withOff` + section « Story 36.3 » : 4 nouveaux tests)
- `_bmad-output/implementation-artifacts/sprint-status.yaml` (modifié — ligne 36-3 → review)
- `docs/qa/domains/agent.md` (modifié — append-only, section « Story 36.3 »)

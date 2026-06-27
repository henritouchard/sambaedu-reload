# QA — Domaine : Contrat amont controlHub

Runbook de validation pour le modèle de persistance du contrat amont reçu depuis controlHub (Epic 28).

Le contrat amont modélise la politique imposée par l'autorité amont (items verrouillés/permissifs/absents, catalogue applicatif, labels, groupes imposés, état du lien). Ce runbook couvre la couche de persistance (Story 28.1) et s'enrichira au fil des stories suivantes (28.2 ingestion, 28.3 StateCompiler, Epic 30 labels, Epic 31 catalogue).

---

## Pré-requis communs

- VM accessible : `ssh -i ~/.ssh/id_se4fs_vm root@192.168.122.50`
- Migrations jouées : `cd /var/www/sambaedu-reload && php artisan migrate`
- Aucune donnée de contrat pré-chargée (tables vides après migration fraîche = comportement attendu NFR3).
- Tests HÔTE (php8.4 + pdo_sqlite) : depuis le repo local, **jamais depuis la VM** (sans pdo_sqlite).

---

## Section 1 — Migration create/rollback (Story 28.1)

### Scénario 1.1 — La migration crée proprement les 5 tables

**Pré-requis** : migration non encore jouée.

```bash
# Hôte
php artisan migrate --path=database/migrations/2026_06_26_100000_create_controlhub_contract_tables.php
```

**Attendu** :
- 5 tables créées : `controlhub_contracts`, `controlhub_contract_items`, `controlhub_contract_labels`, `controlhub_contract_imposed_groups`, `controlhub_contract_catalog_apps`.
- Aucune erreur SQL.

### Scénario 1.2 — Le rollback supprime proprement les 5 tables (ordre FK respecté)

```bash
# Hôte
php artisan migrate:rollback --step=1
```

**Attendu** :
- Les 5 tables sont supprimées dans l'ordre inverse (enfants avant parent).
- Aucune erreur de contrainte FK.
- Re-migration possible sans erreur.

### Scénario 1.3 — La garde idempotente empêche la re-création

```bash
# Jouer la migration deux fois consécutives
php artisan migrate
php artisan migrate  # doit être no-op
```

**Attendu** : aucune erreur ; la 2e passe est silencieuse (guard `hasTable`).

---

## Section 2 — Garde-fou R3 : vocabulaire sans « central » (Story 28.1)

### Scénario 2.1 — Introspection : aucun nom de table ne contient « central »

```bash
# Via tinker ou psql
php artisan tinker --execute "echo implode(\"\n\", array_filter(array_map(fn(\$t) => \$t, DB::select(\"SELECT tablename FROM pg_tables WHERE schemaname='public' AND tablename LIKE '%controlhub%'\") ?? []), fn(\$r) => str_contains(\$r->tablename, 'central') ? \$r->tablename : null));"
```

**Attendu** : sortie vide (aucune table controlhub ne contient « central »).

### Scénario 2.2 — Tests unitaires R3 passent

```bash
# Hôte
php artisan test --filter "r3_no"
```

**Attendu** : 2 tests verts (`r3_no_table_name_contains_central`, `r3_no_column_name_contains_central`).

---

## Section 3 — Comportement sans contrat (NFR3 — standalone préservé) (Story 28.1)

### Scénario 3.1 — Les 5 tables sont vides après migration fraîche

```bash
# VM ou hôte — vérification via artisan
php artisan tinker --execute "echo App\Models\ControlHubContract::count();"
```

**Attendu** : `0` — aucune ligne créée par le seeder ou un chemin implicite.

### Scénario 3.2 — Le comportement SE5 est strictement inchangé sans contrat

**Procédure** : dérouler le runbook standard (parc, agent, wpkg) en s'assurant qu'aucune erreur liée aux tables `controlhub_contract_*` ne survient.

**Attendu** : 0 erreur dans les logs Laravel liée à ces tables ; le fonctionnement SE5 normal est préservé.

---

## Section 4 — Contraintes d'unicité sur les clés naturelles (NFR4) (Story 28.1)

### Scénario 4.1 — Doublon sur item imposé (cible label) lève une erreur

```bash
php artisan tinker
```

```php
use App\Models\ControlHubContract;
use App\Models\ControlHubContractItem;
use App\Enums\{ControlHubEnforcementState, ControlHubContractTarget};

$c = ControlHubContract::create(['link_state' => 'active']);
$data = ['controlhub_contract_id' => $c->id, 'type' => 'capabilities', 'key' => 'cap_x',
         'value' => 'on', 'enforcement_state' => ControlHubEnforcementState::Locked,
         'target_type' => ControlHubContractTarget::Label, 'target_label' => 'salle-info'];
ControlHubContractItem::create($data);
ControlHubContractItem::create($data); // doit lever une exception
```

**Attendu** : `Illuminate\Database\QueryException` (unique constraint violation).

### Scénario 4.1bis — Doublon sur item ciblant l'INSTANCE lève une erreur (NFR4, cas dominant)

> Post-correctif review 28.1 (#1) : `target_label` est `NOT NULL DEFAULT ''`. La chaîne vide `''` représente la cible instance. Sans ce correctif, deux items instance identiques (`target_label = NULL`) **ne** collisionnaient **pas** (NULL≠NULL en PG/SQLite) → trou d'idempotence sur le cas le plus courant.

```php
$c = ControlHubContract::create(['link_state' => 'active']);
$data = ['controlhub_contract_id' => $c->id, 'type' => 'capabilities', 'key' => 'cap_x',
    'enforcement_state' => 'locked', 'target_type' => 'instance', 'target_label' => ''];
App\Models\ControlHubContractItem::create($data);
App\Models\ControlHubContractItem::create($data); // exception attendue
```

### Scénario 4.2 — Doublon sur label lève une erreur

```php
$c = ControlHubContract::create(['link_state' => 'active']);
$data = ['controlhub_contract_id' => $c->id, 'name' => 'salle-info', 'mode' => 'free'];
App\Models\ControlHubContractLabel::create($data);
App\Models\ControlHubContractLabel::create($data); // exception attendue
```

### Scénario 4.3 — Doublon sur groupe imposé lève une erreur

```php
$c = ControlHubContract::create(['link_state' => 'active']);
$data = ['controlhub_contract_id' => $c->id, 'name' => 'parc-terminales'];
App\Models\ControlHubContractImposedGroup::create($data);
App\Models\ControlHubContractImposedGroup::create($data); // exception attendue
```

### Scénario 4.4 — Doublon sur app catalogue lève une erreur

```php
$c = ControlHubContract::create(['link_state' => 'active']);
$data = ['controlhub_contract_id' => $c->id, 'app_key' => 'firefox'];
App\Models\ControlHubContractCatalogApp::create($data);
App\Models\ControlHubContractCatalogApp::create($data); // exception attendue
```

---

## Checklist rapide Story 28.1

- [ ] Migration crée les 5 tables sans erreur (Scénario 1.1)
- [ ] Rollback supprime proprement les 5 tables (Scénario 1.2)
- [ ] Garde idempotente : 2e migration = no-op (Scénario 1.3)
- [ ] Aucun nom de table/colonne ne contient « central » (Scénario 2.1 + 2.2)
- [ ] Tables vides après migration fraîche — NFR3 standalone (Scénario 3.1)
- [ ] SE5 fonctionnel sans contrat reçu (Scénario 3.2)
- [ ] Contrainte unicité item/label/groupe/app, **dont item ciblant l'instance** (Scénarios 4.1–4.4 + 4.1bis)
- [ ] `php artisan test --filter ControlHubContract` → 38/38 verts

---

## Post-correctifs & non-régressions

| Incident | Sévérité | Story | Scénario de couverture |
|----------|----------|-------|------------------------|
| #1 — clé naturelle item ne protège pas le cas `instance` (target_label NULL ⇒ NULL≠NULL, trou NFR4 sur le cas dominant) | 🔴 | 28.1 | Scénario 4.1bis ; correctif = `target_label NOT NULL DEFAULT ''`. **Re-vérifié en 28.2 (Scénario 5.4)** : la réception répétée d'un contrat à items `instance` (target_label null/absent/'') ne crée PAS de doublons et reste un no-op (idempotence réelle, pas seulement la contrainte). |

---

## Section 5 — Ingestion idempotente du contrat amont (Story 28.2)

> Couche d'ingestion : `App\Services\ControlHub\ControlHubContractIngestionService::ingest(array $payload): ContractIngestionResult`.
> C'est le **seul** écrivain des tables `controlhub_contract_*` (NFR3). Une réception identique est un **no-op** (NFR4). Validation/normalisation à l'entrée, passage du lien à `active`, singleton « ≤ 1 contrat actif ».
>
> Format de payload accepté (introduit unilatéralement par SE5) :
> `['items' => [...], 'labels' => [...], 'imposed_groups' => [...], 'catalog_apps' => [...]]`.
> Items : `{type, key, value?, enforcement_state, target_type, target_label?}`.
> (SE5 ↔ une seule autorité amont : aucune référence d'émetteur n'est stockée — cf. migration 28.1.)

**Validation automatisée (HÔTE) — préalable à tout test manuel :**

```bash
# Hôte (php8.4 + pdo_sqlite)
vendor/bin/phpunit --filter ControlHubContractIngestionTest   # 10/10 attendus
vendor/bin/phpunit --filter ControlHubContract                # 48/48 (28.1 + 28.2)
```

### Scénario 5.1 — Première réception : persistance + lien actif

```php
$svc = new App\Services\ControlHub\ControlHubContractIngestionService();
$r = $svc->ingest([
  'items' => [['type'=>'capabilities','key'=>'cap_x','value'=>'on','enforcement_state'=>'locked','target_type'=>'instance']],
  'labels' => [['name'=>'salle-info','mode'=>'reserved']],
  'imposed_groups' => [['name'=>'parc-term','label_name'=>'salle-info']],
  'catalog_apps' => [['app_key'=>'firefox','display_name'=>'Firefox']],
]);
```

**Attendu** : `$r->contractCreated === true`, `$r->mutated === true` ; 1 `controlhub_contracts` (`link_state=active`, `received_at` non null) ; items/labels/imposed_groups/catalog_apps reflètent **exactement** le payload.

### Scénario 5.2 — Réception identique = no-op (aucune écriture, aucun event)

**Procédure** : ré-injecter le **même** payload qu'en 5.1.

**Attendu** : `$r->mutated === false` ; aucun comptage de ligne ne change ; `received_at` et `updated_at` du contrat **inchangés** ; **aucun** `App\Events\ControlHubContractChanged` émis (vérifiable via `Event::fake()` en test).

### Scénario 5.3 — Réception modifiée : upsert + prune + event 1×

**Procédure** : ré-injecter en modifiant la valeur d'un item, en ajoutant un item/label, et en retirant un label + une app.

**Attendu** : `$r->mutated === true` ; les présents sont upsertés (aucune `QueryException` d'unicité), les disparus supprimés (prune) ; `ControlHubContractChanged` émis **exactement une fois** ; compteurs `$r->items['created']/['updated']/['deleted']` cohérents.

### Scénario 5.4 — Normalisation `null → ''` de target_label + idempotence (HANDOFF 28.1 #1)

**Procédure** : injecter 3 items `target_type=instance` dont `target_label` est tantôt `null`, tantôt absent, tantôt `''`. Ré-injecter en permutant ces 3 représentations.

**Attendu** : tous persistés avec `target_label = ''` (jamais `null`) ; la ré-réception est un **no-op** (`mutated=false`, 3 items stables, aucun event). **Test révélateur** : échouerait si la normalisation manquait.

### Scénario 5.5 — Singleton « au plus un contrat actif »

**Procédure** : injecter un payload, puis ré-injecter un payload au **contenu modifié** (items différents).

**Attendu** : `ControlHubContract::where('link_state','active')->count() === 1` et `controlhub_contracts` ne contient qu'une ligne ; la 2e réception **réutilise** le contrat actif (`$r->contractCreated === false`, `$r->mutated === true`), elle n'en crée pas un second. Le singleton est tenu par `link_state` (modèle mono-autorité, aucune référence d'émetteur).

### Scénario 5.6 — Payload hors domaine rejeté + rollback total (HANDOFF 28.1 #3)

**Procédure** : injecter un payload avec `enforcement_state='bogus'` (puis variantes `target_type='bogus'`, `mode='bogus'`, `target_type=label` sans `target_label`, `target_type=instance` avec `target_label` non vide).

**Attendu** : `App\Exceptions\ControlHub\InvalidUpstreamContractException` levée ; **aucune** écriture (base strictement inchangée ; si un contrat préexistait, son `updated_at` reste inchangé) — il n'existe aucun `CHECK` DB, la validation applicative est l'unique garde-fou.

### Scénario 5.7 — R3 : aucun identifiant « central »

```bash
grep -riE "class .*central|function .*central|->central|\\\$central" \
  app/Services/ControlHub/ControlHubContractIngestionService.php \
  app/Services/ControlHub/Data/ContractIngestionResult.php \
  app/Events/ControlHubContractChanged.php \
  app/Exceptions/ControlHub/InvalidUpstreamContractException.php
```

**Attendu** : sortie vide (aucun identifiant livré ne contient « central »). Couvert par `test_r3_no_central_identifier`.

---

## Checklist rapide Story 28.2

- [ ] `vendor/bin/phpunit --filter ControlHubContractIngestionTest` → 10/10 verts
- [ ] `vendor/bin/phpunit --filter ControlHubContract` → 48/48 verts (non-régression 28.1)
- [ ] 1re réception persiste le contrat + lien `active` + `received_at` (Scénario 5.1)
- [ ] Réception identique = no-op, aucun event (Scénario 5.2)
- [ ] Réception modifiée = upsert + prune + event 1× (Scénario 5.3)
- [ ] target_label normalisé `null→''` + idempotence (Scénario 5.4)
- [ ] Singleton ≤ 1 contrat actif (Scénario 5.5)
- [ ] Payload invalide rejeté + rollback total (Scénario 5.6)
- [ ] Aucun identifiant « central » (Scénario 5.7)

---

## Section 6 — Mapping refnum d'un label → WorkstationGroup (Story 30.2)

> Le refnum rattache un label **libre** (`free`) du contrat amont **actif** à un parc (`WorkstationGroup`), ou crée un parc le portant. Garanties : **au plus 1 label par groupe**, labels **réservés** non attribuables, labels **inconnus** (hors contrat actif) refusés. Lien WG↔label = colonne string nullable `workstation_groups.controlhub_label` (rattachement **par nom**, pas de FK dure — cf. 28.1 `imposed_groups.label_name`). Service : `App\Services\ControlHub\WorkstationGroupLabelService` (`assignLabel`/`detachLabel`) ; refus = `App\Exceptions\ControlHub\LabelAssignmentException`. Résolution du contrat actif : `ControlHubContract::active()` (singleton `link_state=active`, iso 28.2). UI : sélecteur « Label de contrat amont » dans les pages d'**édition** et de **création** de parc ; Gate `update-workstationGroup` (édition) / `create-workstationGroup` (création).
>
> ⚠️ HORS scope 30.2 (autres stories) : garantie d'existence des groupes imposés à label réservé (30.3), résolution `StateCompiler` d'un item ciblant `label:<nom>` (30.4), validation prédictive de collision (30.5).
## Section 6 — Résolution AMONT > local dans StateCompiler (Story 28.3)

> Branchement du **tier de précédence amont** dans le moteur de résolution
> `App\Services\Agent\StateCompiler`. C'est la 1re story qui **lit** le contrat
> persisté pour influencer le compilé. La précédence amont > local vit
> **uniquement** dans `StateCompiler::specificity()` (nouvelle maille
> `StateMaille::Upstream`, rang -1, plus spécifique que tout le local) ; les items
> du contrat actif sont injectés comme **candidats bruts** étiquetés `Upstream`
> par un **décorateur** (`UpstreamAwareProvider`) qui enrobe chaque provider, via
> une **source partagée** (`UpstreamContractSource`) qui lit le contrat actif
> (`link_state = active`, singleton 28.2).
>
> **Bornage 28.3** : injecte les items `target_type = instance`, états `locked` +
> `permissive` (priment **tous deux** sur le local à ce stade) ; **exclut**
> `absent` ; **ignore proprement** `target_type = label` (Epic 30). Bridge de
> payload **minimal type-agnostique** démontré sur `registry` (exclusive par clé)
> et `shortcuts` (aggregate) — expansion complète + schéma figé déférés **Epic 33**.
> Conventions de `key` : `registry` = `"hive|path|name[|REG_TYPE]"`, `shortcuts` =
> nom du raccourci (`value` = cible).
>
> **HORS scope** (couture documentée) : enforcement / refus d'édition + relaxation
> permissive (override WG bat `permissive`) + drift STRICT + lisibilité refnum =
> **Epic 29** ; ciblage label → `WorkstationGroup` = **Epic 30** ; bornage install
> = **Epic 31** ; release des verrous `severed` = **Epic 32** ; schéma versionné +
> représentation canonique du payload = **Epic 33**.

**Validation automatisée (HÔTE) — préalable à tout test manuel :**

```bash
# Hôte (php8.4 + pdo_sqlite)
vendor/bin/phpunit --filter WorkstationGroupLabel   # 15/15 attendus
vendor/bin/phpunit --filter ControlHubContract      # non-régression 28.x
```

### Scénario 6.1 — Migration : colonne `controlhub_label` présente

```bash
php artisan migrate --path=database/migrations/2026_06_27_100000_add_controlhub_label_to_workstation_groups.php
```

**Attendu** : colonne `controlhub_label` (string, **nullable**, indexée `wg_controlhub_label_idx`) ajoutée sur `workstation_groups` ; `migrate:rollback --step=1` la retire proprement (index puis colonne) ; re-migration sans erreur. Aucun nom de colonne/index/commentaire ne contient « central » (R3).

### Scénario 6.2 — Assignation d'un label libre à un parc existant

**Pré-requis** : un contrat amont actif portant un label `salle-info` en mode `free` (seedé via factories ou reçu via l'ingestion 28.2) ; un parc sans label.

**Procédure** : ouvrir `/app/parc/groups/{id}/edit`, sélectionner « salle-info » dans « Label de contrat amont », enregistrer.

**Attendu** : `workstation_groups.controlhub_label = 'salle-info'` ; toast de succès ; le parc apparaît dans `WorkstationGroup::carryingControlHubLabel('salle-info')`.

### Scénario 6.3 — Création d'un parc portant un label libre

**Procédure** : ouvrir `/app/parc/groups/new`, renseigner le nom, choisir un label libre, créer.

**Attendu** : le parc est créé avec `controlhub_label` = nom du label (chemin de création parc existant réutilisé).

### Scénario 6.4 — Invariant « 1 label max » + idempotence

**Procédure** : sur un parc portant déjà le label `A`, tenter d'assigner un label `B` différent.

**Attendu** : refus (`LabelAssignmentException`, message « porte déjà le label… ») ; le parc conserve `A`, aucune écriture. Ré-assigner le **même** label `A` est un **no-op** sans erreur (idempotent).

### Scénario 6.5 — Label réservé non attribuable

**Procédure** : avec un label `direction` en mode `reserved`, tenter de l'assigner à un parc (ou d'en créer un le portant).

**Attendu** : refus explicite (message « réservé à l'autorité amont, non attribuable ») ; la base reste inchangée. Dans l'UI, un label réservé n'est **jamais** proposé dans le sélecteur ; s'il est déjà porté (cas 30.3), il s'affiche **désactivé** en lecture seule.

### Scénario 6.6 — Label inconnu (hors contrat actif) refusé

**Procédure** : tenter d'assigner un nom de label qui n'existe pas parmi les labels du contrat actif.

**Attendu** : refus (`LabelAssignmentException`, message « inconnu… ») ; aucune écriture. (Ordre des refus : inconnu **avant** réservé.)

### Scénario 6.7 — Détachement

**Procédure** : sur un parc portant un label libre, sélectionner « Aucun » puis enregistrer.

**Attendu** : `controlhub_label` repasse à `null`, sans erreur.

### Scénario 6.8 — Autorisation (Gate scopé)

**Procédure** : avec un utilisateur **sans** droit de modification sur le parc cible, tenter d'assigner/détacher un label.

**Attendu** : l'action est refusée par le Gate `update-workstationGroup` **avant** toute écriture (403) ; le parc est inchangé.

### Scénario 6.9 — Standalone préservé (NFR3) + R3

**Pré-requis** : aucune installation avec contrat amont actif (`ControlHubContract::active()` → null).

**Attendu** : la section « Label de contrat amont » est **masquée** dans les pages d'édition/création ; aucun label proposé ; comportement parc **strictement inchangé** (aucune contrainte ajoutée). Introspection R3 : aucun identifiant livré (colonne `controlhub_label`, classe `WorkstationGroupLabelService`, exception `LabelAssignmentException`, leurs méthodes/propriétés) ne contient « central ».

```bash
grep -riE "central" \
  app/Services/ControlHub/WorkstationGroupLabelService.php \
  app/Exceptions/ControlHub/LabelAssignmentException.php \
  database/migrations/2026_06_27_100000_add_controlhub_label_to_workstation_groups.php
```

**Attendu** : sortie vide.

---

## Checklist rapide Story 30.2

- [ ] `vendor/bin/phpunit --filter WorkstationGroupLabel` → 19/19 verts
- [ ] `vendor/bin/phpunit --filter ControlHubContract` → non-régression 28.x verte
- [ ] Migration : colonne `controlhub_label` nullable indexée + rollback propre (Scénario 6.1)
- [ ] Assignation d'un label libre (existant + création) (Scénarios 6.2, 6.3)
- [ ] Invariant « 1 max » + idempotence du même label (Scénario 6.4)
- [ ] Label réservé refusé + affiché disabled (Scénario 6.5)
- [ ] Label inconnu refusé (Scénario 6.6)
- [ ] Détachement → null (Scénario 6.7)
- [ ] Gate `update-workstationGroup` refuse hors périmètre (Scénario 6.8)
- [ ] Standalone : section masquée, comportement inchangé + R3 sans « central » (Scénario 6.9)
- [ ] Post-correctifs review : édition d'un parc à label réservé/dangling sans fausse erreur (6.10) + refus de détachement réservé (6.11)

## Post-correctifs & non-régressions — Story 30.2 (review 2026-06-28)

| Incident | Sévérité | Origine | Couvert par |
|----------|----------|---------|-------------|
| #1 Fausse erreur de toast en éditant un parc portant un label réservé/dangling (autres champs committés mais erreur affichée) | 🔴 | Manqué par les tests unitaires initiaux (tous partaient d'un groupe SANS label) ; détectable en manuel sur un parc déjà labellisé | Scénario 6.10 + tests `editing_a_group_holding_a_reserved_label_*` / `_dangling_*` |
| #2 Détachement d'un label réservé via requête Livewire forgée | 🔴 | Garde-fou UI seulement (select disabled) — propriété publique forgeable côté client | Scénario 6.11 + test `detaching_a_reserved_label_is_refused_by_service` |

> Angle de test nouveau révélé : **les transitions d'état** (un parc qui porte DÉJÀ un
> label, label devenu réservé ou supprimé par la réconciliation 28.2) ne sont pas
> couvertes par les scénarios « assignation initiale ». Toujours tester l'édition d'un
> parc **pré-labellisé**, pas seulement l'assignation à partir de zéro.

### Scénario 6.10 — Éditer un parc portant un label réservé/dangling sans fausse erreur

**Pré-requis** : contrat actif avec label réservé `direction` ; un parc portant déjà `controlhub_label = 'direction'`.

1. Ouvrir la page d'édition du parc → le sélecteur affiche `direction` **en lecture seule** (disabled).
2. Modifier UNIQUEMENT le nom du parc, sauvegarder.
3. **Attendu** : toast de **succès** + redirection vers la fiche du parc ; le nom est changé ; `controlhub_label` reste `direction`. **Aucun** toast d'erreur « réservé ».
4. Variante *dangling* : le label porté n'existe plus dans le contrat actif → même résultat (édition possible, label inchangé).

### Scénario 6.11 — Refus de détachement d'un label réservé (garde-fou serveur)

**Pré-requis** : contrat actif avec label réservé `direction` ; parc portant `direction`.

1. Tenter `WorkstationGroupLabelService::detachLabel($group)` (équivalent d'une requête forgée `controlhubLabel=''`).
2. **Attendu** : `LabelAssignmentException` (« réservé ») ; `controlhub_label` du parc **inchangé** (`direction`). Le refus est tenu CÔTÉ SERVICE, pas seulement par l'UI.

## Section 7 — Garantie d'existence des groupes imposés (Story 30.3)

Réconciliation « désir d'état » des `WorkstationGroup` exigés par le contrat amont :
créer si absent (avec label réservé + verrou), confirmer sans doublon si existant,
lever le verrou des groupes non-imposés — **sans aucune migration** (colonnes `locked`,
`managed_by_control_hub`, `controlhub_label` déjà présentes). Service
`ImposedWorkstationGroupReconciler`, listener `ReconcileImposedWorkstationGroups` sur
`ControlHubContractChanged`, commande `controlhub:reconcile-imposed-groups`. Verrou de
suppression = **réutilisation** du mécanisme `locked` (aucun nouveau code de refus).

> **Pré-requis (HÔTE)** : php8.4 + pdo_sqlite, `RefreshDatabase`, `Queue::fake()` pour
> neutraliser/asserter `WorkstationGroupAdSyncJob`. Seeder via factories 28.1
> (`ControlHubContractFactory`, `ControlHubContractImposedGroupFactory->withLabel()`,
> `ControlHubContractLabelFactory->reserved()`) + `WorkstationGroupFactory`. **JAMAIS la VM.**

### Scénario 7.1 — Création d'un groupe imposé absent (chemin parc → AD)

**Pré-requis** : contrat actif imposant `bureau_direction` (label réservé `direction`) ; aucun `WorkstationGroup` de ce nom.

1. Exécuter `ImposedWorkstationGroupReconciler::reconcile()` (ou la commande artisan).
2. **Attendu** : un `WorkstationGroup` `bureau_direction` est créé via le chemin parc existant (`WorkstationGroupService::createGroup`), avec `is_physical = false`, `is_active = true`, `managed_by_control_hub = true`, `locked = control_hub`, `controlhub_label = direction`.
3. Le `WorkstationGroupAdSyncJob` est dispatché (création réelle du parc en `OU=Parcs` côté VM). Un groupe imposé **sans** label → `controlhub_label` reste `null`.

### Scénario 7.2 — Confirmation idempotente d'un groupe existant (sans doublon)

**Pré-requis** : contrat actif imposant `bureau_direction` (label `direction`) ; un `WorkstationGroup` `bureau_direction` existe déjà (sans label, non verrouillé).

1. `reconcile()`.
2. **Attendu** : aucun doublon (`count('bureau_direction') === 1`) ; `controlhub_label` posé à `direction`, `managed_by_control_hub = true`, `locked = control_hub`. L'écriture est **ciblée** (pas via `updateGroup()` qui throw sur `isLocked()`), uniquement si un champ change.
3. **Variante adopt ROOT** : si le groupe pré-existant porte `locked = root`, le verrou `root` est **préservé** (jamais écrasé par `control_hub`), `managed_by_control_hub` passe quand même à `true`.

### Scénario 7.3 — Idempotence sur exécutions répétées (NFR4)

**Pré-requis** : groupes imposés déjà réconciliés.

1. `reconcile()` une seconde fois (mêmes données).
2. **Attendu** : no-op fonctionnel — `created = 0`, `confirmed = 0`, `released = 0`, aucun doublon, aucune exception, aucun `save()` (donc aucun réveil parasite de l'observer `updated()`).

### Scénario 7.4 — Verrou de suppression sous contrat (refnum bloqué)

**Pré-requis** : groupe imposé `bureau_direction` réconcilié (`locked = control_hub`).

1. `WorkstationGroupService::deleteGroup($group->id)` (ou action UI « Supprimer »).
2. **Attendu** : `\RuntimeException` (mécanisme `isLocked()` existant) ; le groupe **persiste** en base. L'UI affiche le bouton « Supprimer » désactivé (cadenas) + le badge « Imposé par le contrat amont — non supprimable » sur la fiche du groupe.

### Scénario 7.5 — Levée du verrou d'un groupe non-imposé (sans suppression)

**Pré-requis** : contrat actif n'imposant **plus** `ancien_parc` (qui porte `managed_by_control_hub = true`, `locked = control_hub`, `controlhub_label = ancien-label`).

1. `reconcile()`.
2. **Attendu** : `ancien_parc` **n'est PAS supprimé** ; `locked` repasse à `null` (uniquement car il valait `control_hub`), `managed_by_control_hub = false`. Le `controlhub_label` devenu « dangling » est laissé tel quel (sans effet — cf. 30.4). La rupture totale du lien (suppression/déverrouillage de masse) relève d'Epic 32, **hors 30.3**.
3. **Variante root** : un groupe non-imposé portant `locked = root` n'est **pas** déverrouillé (seul `managed_by_control_hub` est levé).

### Scénario 7.6 — Déclenchement automatique via listener (AC5)

**Pré-requis** : aucune VM, ingestion réelle 28.2.

1. `ControlHubContractIngestionService::ingest(['imposed_groups' => [['name' => 'parc-terminales', 'label_name' => 'salle-info']], 'labels' => [['name' => 'salle-info', 'mode' => 'reserved']]])`.
2. **Attendu** : l'événement `ControlHubContractChanged` (émis après commit, sur mutation) déclenche le listener `ReconcileImposedWorkstationGroups` → le `WorkstationGroup` `parc-terminales` existe ensuite (`managed_by_control_hub`, `locked = control_hub`, label `salle-info`). **L'ingestion 28.2 n'est pas modifiée.** Une ingestion sans `imposed_groups` → réconciliation no-op (aucun groupe créé).

### Scénario 7.7 — Commande artisan (ops/recovery)

1. `php artisan controlhub:reconcile-imposed-groups` **avec** contrat actif → groupes créés/confirmés, compteurs affichés, exit 0.
2. **Sans** contrat actif → message « Aucun contrat amont actif … », exit 0, **rien d'écrit** (NFR3).

### Scénario 7.8 — Standalone (NFR3) + R3

1. Sans contrat amont actif (`ControlHubContract::active() === null`), `reconcile()` est un **no-op total** : aucun `WorkstationGroup` créé/modifié, aucun verrou posé, `Queue::assertNothingPushed()` côté AD-sync de ce service. Un contrat `severed` n'est **pas** actif → même no-op.
2. **R3** : introspection (reflection) des classes livrées (`ImposedWorkstationGroupReconciler`, `ImposedGroupReconciliationResult`, listener, commande) → **aucun** identifiant ne contient « central » (`control_hub`/`ControlHub` conformes).

## Checklist rapide Story 30.3

- [ ] `vendor/bin/phpunit --filter Imposed` → 20/20 verts
- [ ] `vendor/bin/phpunit --filter ReconcileImposedGroups` → 4/4 verts
- [ ] Non-régression `--filter ControlHubContract` (48) + `--filter WorkstationGroupLabel` (19) verts
- [ ] Création d'un groupe imposé absent via le chemin parc + dispatch AD (Scénario 7.1)
- [ ] Confirmation idempotente sans doublon + adopt ROOT non écrasé (Scénario 7.2)
- [ ] Idempotence 2 passes = no-op (Scénario 7.3)
- [ ] `deleteGroup()` refuse + groupe persiste + badge UI (Scénario 7.4)
- [ ] Levée du verrou d'un non-imposé sans suppression, root préservé (Scénario 7.5)
- [ ] Listener déclenché par l'ingestion réelle (Scénario 7.6)
- [ ] Commande artisan avec/sans contrat (Scénario 7.7)
- [ ] Standalone no-op total (NFR3) + R3 sans « central » (Scénario 7.8)
- [ ] **Aucune migration ajoutée** ; aucun chemin parc existant modifié hors badge UI lecture seule
php artisan test --filter UpstreamContractResolution   # 11/11 attendus
php artisan test --filter StateCompiler                # nouveaux verts (2 échecs sync AD PRÉEXISTANTS, hors 28.3)
php artisan test --filter ControlHubContract           # 48/48 (non-régression 28.1 + 28.2)
```

### Scénario 6.1 — L'amont prime sur le local pour la même clé (registry)

**Procédure** : contrat actif avec un item `registry` `locked` ciblant l'instance
(`key = "HKCU|Software\Foo|Bar|REG_DWORD"`, `value = 1`) + un réglage **local** sur
la **même** clé `{hive,path,name}` (valeur `0`).

**Attendu** : le compilé porte **une seule** valeur pour cette clé = **1** (valeur
amont). L'item amont gagne parce qu'il porte la maille `Upstream` (rang -1, plus
spécifique que toute maille locale) — arbitrage par `specificity()` **seul**.

### Scénario 6.2 — Empilement : le local sans équivalent amont survit

**Procédure** : (a) **aggregate** (`shortcuts`) — contrat avec un raccourci amont
+ raccourcis locaux distincts ; (b) **exclusive par clé** (`registry`) — l'amont
impose la clé `Beta`, le local porte la clé `Alpha` (non imposée).

**Attendu** : (a) l'item amont **s'ajoute** à l'union, les raccourcis locaux
**restent** (3 items) ; (b) la clé `Alpha` conserve sa valeur **locale** gagnante,
la clé `Beta` amont est présente — un item amont **n'efface jamais** un item local
distinct.

### Scénario 6.3 — Standalone byte-identique sans contrat (NFR3 — test révélateur)

**Procédure** : aucun contrat actif. Comparer le compilé des providers **décorés**
au compilé des **mêmes providers non décorés** (items par portée + `hashState()`).
Mesurer le nombre de requêtes pendant la compilation décorée (`DB::enableQueryLog`).

**Attendu** : sortie **strictement identique** (mêmes items, même ordre, même
hash). **Au plus 1** requête « contrat actif ? » (sur `controlhub_contracts`,
renvoie `null`), **0** requête sur `controlhub_contract_items` (court-circuit), et
**pas** de N+1 par provider (résolution partagée/mémoïsée). Échouerait si le
décorateur introduisait le moindre écart.

### Scénario 6.4 — Déterminisme du hash avec contrat actif (NFR4 / ETag 23.5)

**Procédure** : contrat actif + item amont ; compiler à deux instants différents
(`travel()`).

**Attendu** : `hashState()` **identique** (seul `generated_at` volatil) ;
l'injection amont est stable (`sourceId` = id de l'item contrat, ordre `id` asc,
jamais l'ordre SQL).

### Scénario 6.5 — `absent` non injecté ; `locked` + `permissive` priment (AC #6)

**Procédure** : (a) item amont `absent` sur une clé que le local règle → le local
**survit** (l'amont `absent` n'est pas injecté, ne prime sur rien) ; (b) items
`permissive` et `locked` sur des clés que le local règle → **les deux** valeurs
amont gagnent.

**Attendu** : (a) la valeur **locale** figure au compilé ; (b) `permissive` se
comporte **comme `locked`** vis-à-vis du local (la **relaxation permissive** est
**Epic 29** — couture documentée, non implémentée).

### Scénario 6.6 — Bornage : label ignoré (Epic 30), severed inerte (Epic 32)

**Procédure** : (a) item amont `target_type = label` sur une clé que le local
règle ; (b) contrat `severed` (non actif) avec un item.

**Attendu** : (a) l'item `label` est **ignoré proprement** (le local survit, aucun
plantage) ; (b) un contrat `severed` n'injecte **aucun** candidat (seul l'actif est
lu) — le local survit.

### Scénario 6.7 — D2 ne fuit pas + R3

**Procédure** :

```bash
# D2 : le SEUL changement dans StateCompiler est le rang Upstream dans specificity()
git diff app/Services/Agent/StateCompiler.php   # uniquement le case Upstream => -1 + PHPDoc

# R3 : aucun identifiant livré ne contient « central »
grep -rin "central" app/Services/ControlHub/Resolution/   # uniquement des commentaires garde-fou « aucun central »
```

**Attendu** : `specificity()` est le seul `match(StateMaille)` modifié (le
décorateur/la source n'arbitrent rien) ; aucun **identifiant** (classe, méthode,
propriété, case d'enum) ne contient « central » — couvert par
`r3_no_central_identifier` (le test scanne les identifiants, pas les commentaires).

---

## Checklist rapide Story 28.3

- [ ] `php artisan test --filter UpstreamContractResolution` → 11/11 verts
- [ ] `php artisan test --filter StateCompiler` → nouveaux verts (`specificity_covers_all_mailles`, `keyed_exclusive_marker_preserved_through_decorator`) ; 2 échecs sync AD PRÉEXISTANTS hors 28.3
- [ ] `php artisan test --filter ControlHubContract` → 48/48 verts (non-régression 28.1 + 28.2)
- [ ] Amont prime sur local même clé (Scénario 6.1)
- [ ] Empilement local sans équivalent amont — aggregate + exclusive par clé (Scénario 6.2)
- [ ] Standalone byte-identique + ≤ 1 requête, court-circuit (Scénario 6.3)
- [ ] Déterminisme du hash avec contrat actif (Scénario 6.4)
- [ ] `absent` non injecté, `locked`+`permissive` priment (Scénario 6.5)
- [ ] Label ignoré + severed inerte (Scénario 6.6)
- [ ] D2 confiné à `specificity()` + aucun identifiant « central » (Scénario 6.7)

---

## Section 7 — Refus de modification d'un item verrouillé amont (Story 29.2, 2026-06-27)

> **Modèle** : 28.3 fait DÉJÀ gagner un item amont `locked` au compilé, mais rien
> n'empêchait le refnum d'éditer sa config locale (simplement « défaite en
> silence » au cycle suivant). 29.2 transforme ce « défait en silence » en REFUS
> explicite à l'écriture (service `UpstreamLockResolver` + Gate `modify-capability`
> + message), sur les DEUX surfaces capacité (override par parc + défaut instance).
> Verrou = `type=registry` + `enforcement_state=locked` + `target_type=instance`
> UNIQUEMENT. `permissive` (29.3/FR4) et `absent` restent éditables.

**Pré-requis** : un contrat amont `active` (Section 5) avec au moins un item
`registry`/`locked`/`instance` dont la clé `hive|path|name` correspond à une clé de
projection d'une capacité du catalogue (ex. `EnableLUA` / UAC). Un user `refnum`
avec `app.customize` (override parc) et un admin `server.admin`+`app.customize`
(défaut instance).

### Scénario 7.1 — Override de parc d'une capacité verrouillée → refus + message (CRITIQUE)

**Procédure** : page d'un parc → onglet « Options / Capacités ». La capacité
verrouillée amont affiche le badge « Verrouillé par contrat amont » et n'expose ni
« Éditer » ni « Retirer » (mention « Imposé par contrat amont ») ; elle n'apparaît
PAS dans la liste « Ajouter une capacité ». Forcer côté serveur (rejeu Livewire /
`$wire.set('editingCapabilityId', …)` puis `saveOverride`).

**Attendu** : un toast explicite « Cette capacité est verrouillée par un contrat
amont et ne peut pas être modifiée localement. » ; AUCUNE ligne écrite dans
`capability_assignments` pour ce parc/cette capacité (refus serveur, pas masquage
seul).

### Scénario 7.2 — Retrait d'un override existant d'une capacité verrouillée → refus

**Procédure** : un override préexiste sur le parc pour une capacité ensuite
verrouillée amont. Tenter `removeOverride`.

**Attendu** : refus (même message) ; l'override n'est PAS supprimé (le refnum ne
« touche » pas un item verrouillé ; le retrait serait de toute façon inerte, l'amont
gagne au compilé).

### Scénario 7.3 — Édition du défaut diffusé d'une capacité verrouillée → refus (CRITIQUE)

**Procédure** : `/admin/settings/parc-defaults` → onglet « Registre / capacités ».
La capacité verrouillée amont affiche le badge, le bouton « Éditer le défaut » est
masqué (« Imposé par contrat amont ») et le toggle « Geler » est désactivé. Forcer
côté serveur (`saveDefault` et `toggleLock` par rejeu).

**Attendu** : refus serveur + message ; `capabilities.default_value` inchangé et
`overrides_locked` inchangé. Le gel LOCAL 27.12 ne peut pas servir de contournement
du verrou amont.

### Scénario 7.4 — Capacité permissive → édition AUTORISÉE (ne pas sur-bloquer)

**Procédure** : item amont `permissive` (et non `locked`) sur la clé d'une capacité.
Éditer un override de parc ET le défaut.

**Attendu** : les deux éditions sont AUTORISÉES (un item permissif n'est PAS un
verrou — sa surcharge relève de la Story 29.3 / FR4). Aucun badge « verrouillé ».

### Scénario 7.5 — Standalone (aucun contrat actif) → rien ne change (NFR3)

**Procédure** : aucun contrat amont `active` (ou contrat `severed`). Dérouler les
gestes capacité normaux (override parc, défaut instance).

**Attendu** : comportement STRICTEMENT identique à 27.12/27.17 — aucune capacité
n'est verrouillée, aucun badge. Au rendu des onglets, AU PLUS 1 requête
`controlhub_contracts` (« contrat actif ? ») et ZÉRO requête
`controlhub_contract_items` (court-circuit) :

```bash
grep -rin "central" app/Services/ControlHub/UpstreamLockResolver.php app/Policies/CapabilityPolicy.php
# → uniquement les commentaires garde-fou « aucun central »
```

### Scénario 7.6 — Ciblage par label différé (Epic 30)

**Procédure** : item amont `locked` mais `target_type = label`.

**Attendu** : l'item est ignoré proprement (29.2 = `instance` only) ; la capacité
reste éditable. Le verrou par label/parc viendra en Epic 30.

---

## Checklist rapide Story 29.2

- [ ] `php artisan test --filter "UpstreamLockResolver|CapabilityPolicy|CapabilitiesTabUpstreamLock|ParcDefaultsUpstreamLock"` → 22/22 verts
- [ ] `php artisan test --filter "CapabilitiesTab|AdminSettingsParcDefaults|ControlHubContract"` → non-régression verte (0 régression)
- [ ] Override de parc d'une capacité verrouillée refusé + message, aucune écriture (Scénario 7.1)
- [ ] Retrait d'override verrouillé refusé (Scénario 7.2)
- [ ] Défaut diffusé + (dé)gel d'une capacité verrouillée refusés (Scénario 7.3)
- [ ] Capacité permissive éditable (pas de sur-blocage — Scénario 7.4)
- [ ] Standalone byte-identique + court-circuit ≤ 1 requête, 0 items (Scénario 7.5)
- [ ] Label différé Epic 30 (Scénario 7.6)
- [ ] R3 : aucun identifiant « central » dans `UpstreamLockResolver`/`CapabilityPolicy`

---

## Section 8 — Relaxation permissive : l'override par workstationGroup MORD au compilé (Story 29.3, 2026-06-27)

> **Modèle** : pendant INVERSE de 29.2. 29.2 transforme le « défait en silence »
> d'un item `locked` en REFUS d'écriture. 29.3 transforme le « override écrit mais
> SANS effet » d'un item `permissive` en override qui MORD réellement à l'état
> compilé. Le geste d'écriture existait déjà (27.12) et 29.2 le laisse déjà passer
> pour `permissive` (le gate ne refuse QUE `locked`) ; le trou était la
> **résolution** — un `permissive` était injecté à la maille `Upstream` (rang -1)
> EXACTEMENT comme `locked`, donc il battait l'override local.
>
> **Le correctif** : `UpstreamContractSource::ensureResolved()` injecte désormais
> une maille DIVERGENTE selon l'`enforcement_state` de l'item :
> `locked → StateMaille::Upstream` (rang -1, INBATTABLE, inchangé) ;
> `permissive → StateMaille::UpstreamPermissive` (rang **6**, le MOINS spécifique
> de toute la chaîne, **sous `Broadcast`**). **Règle métier (décision Henri
> 2026-06-27)** : un `permissive` est un **plancher** ; **toute** maille locale le
> surcharge — défaut diffusé (`Broadcast`), groupe logique, groupe physique, poste,
> user — et il ne s'applique qu'en l'**absence totale** de candidat local. PAS de
> nuance « permissif bat le défaut diffusé ». `locked` n'est JAMAIS relaxé.
>
> La maille interne n'est **jamais sérialisée** : le contrat agent
> `se5.desired-state/v1`, le golden et le `FROZEN_STATE_HASH` restent **INTACTS**
> (un échec golden serait un bug de fuite de maille). Scope `instance` uniquement
> (label → Epic 30). Type `registry` (exclusive par clé).

**Pré-requis** : un contrat amont `active` (Section 5) avec un item
`registry`/`permissive`/`instance` dont la clé `hive|path|name` correspond à une
projection d'une capacité (ex. `show_hidden_files`). Un parc G (workstationGroup
logique) avec un poste membre, et un parc H avec un autre poste.

**Validation automatisée (HÔTE) — préalable à tout test manuel :**

```bash
# Hôte (php8.4 + pdo_sqlite)
php artisan test --filter PermissiveOverrideResolution            # 6/6 attendus
php artisan test --filter CapabilitiesTabPermissiveOverride        # 3/3 attendus
php artisan test --filter "UpstreamContractResolution|StateCompiler"  # non-régression (test 28.3 renommé)
php artisan test --filter ContractV1                               # golden INTACT
```

### Scénario 8.1 — L'override de parc d'une capacité permissive MORD au compilé (CRITIQUE)

**Procédure** : item amont `permissive` sur la clé d'une capacité C. Sur le parc G,
poser un override de C (onglet « Options / Capacités » — autorisé, pas de badge
verrou). Compiler l'état d'un poste de G.

**Attendu** : l'état effectif du poste reflète la **valeur de l'override du
refnum**, PAS la valeur amont permissive (le candidat de la maille de G — rang 3/4
— bat le plancher `UpstreamPermissive` rang 6). L'override n'est plus une « promesse
creuse ».

### Scénario 8.2 — Sans aucun candidat local, le permissif s'applique comme baseline

**Procédure** : item amont `permissive` sur une clé pour laquelle AUCUN candidat
local n'existe (ni override, ni poste, ni **défaut diffusé** pour cette clé).

**Attendu** : la valeur amont `permissive` s'applique (baseline). Le plancher n'est
servi qu'en l'absence TOTALE de candidat local.

### Scénario 8.3 — Le défaut diffusé (Broadcast) surcharge le permissif

**Procédure** : item amont `permissive` sur la clé d'une capacité qui émet un
**défaut diffusé** (Broadcast) pour cette clé, SANS override de parc.

**Attendu** : c'est la **valeur du défaut diffusé** qui s'applique, pas la valeur
amont permissive (le permissif est le rang le MOINS spécifique — décision Henri :
pas de nuance « permissif bat Broadcast »).

### Scénario 8.4 — Un item LOCKED n'est JAMAIS relaxé (non-régression 28.3/29.2)

**Procédure** : item amont `locked` sur la clé de C + un override de parc (cas où
il aurait malgré tout été écrit, ou un défaut local existe).

**Attendu** : la valeur amont `locked` **prime** (maille `Upstream` rang -1,
inchangé). La relaxation 29.3 ne touche QUE `permissive`. Et à l'écriture : un
override de parc sur une capacité `locked` reste **refusé** (badge + message,
aucune écriture — Section 7).

### Scénario 8.5 — Scope : l'override de G ne fuit pas vers un poste de H

**Procédure** : item amont `permissive` sur C ; override de C posé sur le parc G
(et PAS sur H). Compiler un poste de G, puis un poste de H.

**Attendu** : le poste de **G** porte la valeur de l'override ; le poste de **H**
ne porte PAS cet override (il retombe sur son défaut diffusé / sa propre
résolution). Le ciblage « ce groupe uniquement » est inhérent au provider de
capacités. **Note** : conformément à la décision Henri, si C émet un défaut diffusé,
le poste de H affiche ce défaut (le permissif est sous le Broadcast) — l'essentiel
est que l'override de G **ne fuit pas**.

### Scénario 8.6 — Retrait d'un override permissif → retour à la baseline (FR4)

**Procédure** : un override de parc existe sur une capacité `permissive`. Le retirer
(« Retirer » dans l'onglet).

**Attendu** : le retrait est **autorisé** (le refnum reprend la valeur amont/défaut
comme baseline — marge d'adaptation FR4) ; la ligne `capability_assignments` est
supprimée. (Contraste avec `locked` où le retrait est refusé — Section 7.2.)

### Scénario 8.7 — Standalone (aucun contrat actif) → rien ne change (NFR3)

**Procédure** : aucun contrat amont `active`. Dérouler la résolution desired-state.

**Attendu** : compilé STRICTEMENT identique au standalone 27.12/28.3 (aucun candidat
`UpstreamPermissive` injecté) ; au plus 1 requête `controlhub_contracts`, ZÉRO
requête `controlhub_contract_items` (court-circuit). Golden inchangé.

```bash
grep -rin "central" app/Enums/StateMaille.php \
  app/Services/ControlHub/Resolution/UpstreamContractSource.php \
  app/Services/Agent/StateCompiler.php
# → uniquement les commentaires garde-fou « JAMAIS central »
```

---

## Checklist rapide Story 29.3

- [ ] `php artisan test --filter "PermissiveOverride|CapabilitiesTabPermissiveOverride"` → 9/9 verts
- [ ] `php artisan test --filter "UpstreamContractResolution|StateCompiler|ControlHubContract|ContractV1"` → non-régression verte (0 régression ; test 28.3 renommé `locked_wins_over_local_but_permissive_is_overridden_by_local`)
- [ ] Override de parc d'une capacité permissive MORD au compilé (Scénario 8.1)
- [ ] Permissif seul → baseline amont (Scénario 8.2)
- [ ] Défaut diffusé surcharge le permissif (Scénario 8.3)
- [ ] `locked` jamais relaxé + override `locked` refusé (Scénario 8.4)
- [ ] Override de G ne fuit pas vers H (Scénario 8.5)
- [ ] Retrait d'override permissif autorisé (Scénario 8.6)
- [ ] Standalone byte-identique + court-circuit ≤ 1 requête, 0 items (Scénario 8.7)
- [ ] Golden / `FROZEN_STATE_HASH` / `ContractV1Test` INTACTS (maille interne jamais sérialisée)
- [ ] R3 : aucun identifiant « central » dans les fichiers touchés

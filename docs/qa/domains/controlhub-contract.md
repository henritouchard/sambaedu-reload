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

---

## Section 9 — Lisibilité tri-état du statut amont (Story 29.4, 2026-06-27)

> **Modèle** : 29.4 est une story de LISIBILITÉ UI pure — elle n'ajoute aucune
> mécanique d'enforcement (29.2 = verrou, 29.3 = relaxation permissive, tous deux
> livrés). Elle EXPOSE en read-only le statut amont d'une capacité et rend visible
> un **tri-état** : imposé-verrouillé / imposé-modifiable / local, sur les DEUX
> surfaces de configuration des capacités (`capabilities-tab` parc +
> `parc-defaults` registre).
>
> **Libellés (décision 2026-06-27)** : centrés sur l'ACTION possible —
> - badge « **Verrouillé** » 🔒 (tooltip : « Amont — non modifiable. »),
> - badge « **Modifiable** » ✏️ (tooltip : « Proposé par l'amont mais modifiable :
>   votre réglage local prévaut. »),
> - badge « **Local** » 📍 — tooltip différencié par surface :
>   - `capabilities-tab` (override parc) : « Réglage propre à ce parc/groupe. »
>   - `parc-defaults` (défaut diffusé flotte) : « **Défaut diffusé — aucune contrainte amont.** »
>     (correction #1 post-review : « parc/groupe » était faux pour une surface Broadcast).
>
> **Garde-fou libellé permissif** : le badge « Modifiable » dit la RELAXABILITÉ
> (votre local prévaut), JAMAIS « la valeur amont s'applique » (faux pour une
> capacité à défaut diffusé, car `Broadcast=5` bat `UpstreamPermissive=6`).
>
> **NFR3 préservé** : sans contrat actif (standalone ou contrat `severed`),
> **AUCUN badge** n'est rendu (y compris « Local ») ; l'UI est byte-identique à
> 27.12/27.17 — zéro badge, aucune requête `controlhub_contract_items`.
> Le tri-état complet (Verrouillé / Modifiable / Local) n'est visible que lorsqu'un
> contrat amont est actif. Le bucketing locked+permissive est en ≤ 1 requête `items`.
> (Correction #3 post-review : gating sur `hasActiveContract()`.)

**Pré-requis** : voir Section 7 (contrat actif avec items `registry`/`instance`).
Un user `refnum` avec `app.customize` (override parc) et un admin
`server.admin`+`app.customize` (défaut instance).

**Validation automatisée (HÔTE) — préalable à tout test manuel :**

```bash
# Hôte (php8.4 + pdo_sqlite)
# Corrections post-review : +hasActiveContract, #3 gating, #6 severed, #7 query count
php artisan test --filter "UpstreamLockResolver"             # 23/23 attendus (11 nouveaux 29.4)
php artisan test --filter "CapabilitiesTabStatusBadge"       # 9/9 attendus (post-review : +severed +query_count, local→avec contrat)
php artisan test --filter "ParcDefaultsStatusBadge"          # 8/8 attendus (post-review : +severed +query_count, local→avec contrat)
php artisan test --filter "CapabilitiesTabUpstreamLock|ParcDefaultsUpstreamLock|PermissiveOverride"  # non-régression 29.2/29.3
```

### Scénario 9.1 — Capacité VERROUILLÉE amont : badge « Verrouillé » + contrôles désactivés (CRITIQUE)

**Procédure** : contrat actif avec un item `registry`/`locked`/`instance` (ex. `EnableLUA`).
(a) Page d'un parc → onglet « Options / Capacités ».
(b) `/admin/settings/parc-defaults` → onglet « Registre / capacités ».

**Attendu** :
- Badge « Verrouillé » 🔒 affiché (tooltip « Amont — non modifiable. »).
- `data-testid="upstream-locked-{id}"` présent.
- Contrôles d'écriture masqués / texte « Imposé par contrat amont » (non-régression 29.2).
- PAS de badge « Modifiable » ni « Local » pour cette capacité.
- Un seul badge par capacité.

### Scénario 9.2 — Capacité PERMISSIVE amont : badge « Modifiable » + contrôles actifs (CRITIQUE)

**Procédure** : contrat actif avec un item `registry`/`permissive`/`instance` (ex. `show_hidden_files`).
(a) Page d'un parc → onglet « Options / Capacités » (override existant ET dans le picker d'ajout).
(b) `/admin/settings/parc-defaults` → onglet « Registre / capacités ».

**Attendu** :
- Badge « Modifiable » ✏️ affiché (tooltip « Proposé par l'amont mais modifiable : votre réglage local prévaut. »).
- `data-testid="upstream-permissive-{id}"` présent.
- Boutons « Éditer » / « Retirer » (surface A) et « Éditer le défaut » (surface B) **actifs** (un permissif n'est pas un verrou).
- Note explicative : « Votre override s'applique à ce parc » (surface A) / « Votre réglage local s'applique » (surface B).
- Dans le picker d'ajout (surface A) : badge « Modifiable » visible sur la capacité permissive (`data-testid="picker-permissive-{id}"`).
- PAS de badge « Verrouillé » ni « Local » pour cette capacité.

### Scénario 9.3 — Capacité sans contrainte amont : badge « Local » (contrat actif requis)

**Procédure** : contrat actif présent mais aucun item amont pour cette capacité.

> **Correction #3 post-review** : le badge « Local » n'est visible que si un contrat
> amont est actif (`hasActiveContract() = true`). Sans contrat, voir Scénario 9.5
> (aucun badge, UI byte-identique). Avec contrat actif sans item pour la capacité :
> le tri-état s'affiche et la capacité porte « Local ».

**Attendu** :
- Badge « Local » 📍 affiché.
  - Tooltip (surface A — `capabilities-tab` parc) : « Réglage propre à ce parc/groupe. »
  - Tooltip (surface B — `parc-defaults` flotte) : « Défaut diffusé — aucune contrainte amont. »
    (**correction #1** post-review : tooltip différencié, la surface B édite un défaut Broadcast).
- `data-testid="upstream-local-{id}"` présent.
- Contrôles d'écriture normalement actifs.
- PAS de badge « Verrouillé » ni « Modifiable ».

### Scénario 9.4 — Précédence verrouillé > modifiable (un seul badge par capacité, AC #4)

**Procédure** : capacité avec DEUX clés de projection — l'une verrouillée amont, l'autre permissive.

**Attendu** :
- UN seul badge : « Verrouillé » (le verrou prime, AC #4).
- PAS de badge « Modifiable » ni « Local ».
- Les contrôles d'écriture sont masqués (le verrou s'applique à l'ensemble de la capacité).

### Scénario 9.5 — Standalone (aucun contrat actif) → UI byte-identique, zéro badge (NFR3)

**Procédure** : aucun contrat amont `active` (standalone pur OU contrat `severed`).

> **Correction #3 post-review** : en standalone, **AUCUN badge** n'est rendu — y compris
> « Local ». L'UI est byte-identique à 27.12/27.17 (zéro badge). Le tri-état n'apparaît
> que si un contrat actif est présent. Correction également validée pour le cas `severed`
> (lien coupé = plus de contrat actif effectif = même traitement que standalone).

**Attendu** :
- AUCUN badge « Verrouillé », « Modifiable » ni « Local » (NFR3 — zéro badge).
- Contrôles d'écriture normalement actifs.
- Au rendu des onglets : ≤ 1 requête `controlhub_contracts`, ZÉRO requête `controlhub_contract_items`
  (court-circuit ; prouvé par test Feature #7 — `DB::getQueryLog()` autour du rendu Livewire).
- UI **strictement byte-identique** à 27.12/27.17 (aucun badge ajouté).

### Scénario 9.6 — R3 : aucun identifiant/libellé « central »

```bash
grep -rin "central" \
  app/Services/ControlHub/UpstreamLockResolver.php \
  resources/views/pages/parc/groups/_partials/capabilities-tab.blade.php \
  resources/views/pages/admin/settings/parc-defaults/_partials/registry-tab.blade.php
# → uniquement les commentaires garde-fou (zéro identifiant/libellé)
```

---

## Checklist rapide Story 29.4

- [ ] `php artisan test --filter "UpstreamLockResolver"` → 23/23 verts (11 nouveaux 29.4)
- [ ] `php artisan test --filter "CapabilitiesTabStatusBadge"` → 9/9 verts (post-review)
- [ ] `php artisan test --filter "ParcDefaultsStatusBadge"` → 8/8 verts (post-review)
- [ ] `php artisan test --filter "CapabilitiesTabUpstreamLock|ParcDefaultsUpstreamLock|PermissiveOverride"` → non-régression verte (0 régression)
- [ ] Badge « Verrouillé » + contrôles masqués sur les deux surfaces (Scénario 9.1)
- [ ] Badge « Modifiable » + contrôles actifs + note FR8 sur les deux surfaces (Scénario 9.2)
- [ ] Badge « Local » sur capacité sans contrainte **avec contrat actif** (Scénario 9.3)
- [ ] Tooltip Local = « Réglage propre à ce parc/groupe. » (surface A) / « Défaut diffusé — aucune contrainte amont. » (surface B — correction #1)
- [ ] Précédence : locked > permissive → un seul badge « Verrouillé » (Scénario 9.4)
- [ ] Standalone/severed → **zéro badge** (y compris pas de « Local ») + court-circuit NFR3 (Scénario 9.5 — correction #3)
- [ ] R3 : aucun identifiant/libellé « central » (Scénario 9.6)

---

## Section 10 — Drift STRICT prouvé + audit append-only des overrides (Story 29.5, 2026-06-27)

> **Modèle** : 29.5 est une story à DEUX moitiés de nature opposée.
> **Moitié NFR2 (drift STRICT) = PREUVE, pas construction.** Un item verrouillé
> amont est **DÉJÀ** soumis au drift STRICT inconditionnel par construction (livré
> en 27.8 : item de desired-state à **4 clés** `{type, semantics, payload, hash}`
> sans marqueur de mode, plus de `drifted_allowed`). L'item `locked` est injecté à
> la maille `StateMaille::Upstream` (rang -1, inbattable) → il gagne au compilé et
> entre dans le pipeline de réapplication STRICT côté agent Go (`provision.Reconcile`
> réapplique sur toute divergence de hash). 29.5 **prouve** cette chaîne par un test
> SE5 ; elle n'ajoute **aucun** marqueur/toggle de drift (en ajouter régresserait
> 27.8 et casserait l'invariant « item à 4 clés »).
> **Moitié NFR5 (audit) = la VRAIE livraison.** `saveOverride()`/`removeOverride()`
> (`capabilities-tab`) écrivaient `capability_assignments` SANS trace. 29.5 ajoute
> une table append-only `capability_override_audit_logs` + le modèle
> `CapabilityOverrideAuditLog::log()` (patron MAISON `QuotaAuditLog`/
> `DelegationHistory` — Spatie activitylog ABSENT, middleware fédéré ne couvre pas
> le refnum AD-local). La trace est écrite DANS LA MÊME transaction que la mutation
> (atomicité acte ↔ trace), en distinguant l'override d'un item imposé-**permissif**
> (`upstream_status = permissive`) d'un override purement **local**
> (`upstream_status = local`).
>
> **Hors scope (NE PAS tester ici)** : audit de la pose de verrou (décision amont,
> Epic 28), audit de la rupture de lien `severed` (Epic 32, FR7), audit de la
> surface `parc-defaults` (défaut diffusé ≠ override par `workstationGroup`),
> ciblage par label (Epic 30).

**Pré-requis** : voir Section 7 (contrat actif avec items `registry`/`instance`).
Un user `refnum` avec `app.customize` (override parc). L'audit est **silencieux**
(aucun toast nouveau) — il se vérifie en base (`capability_override_audit_logs`).

**Validation automatisée (HÔTE) — préalable à tout test manuel :**

```bash
# Hôte (php8.4 + pdo_sqlite)
php artisan test --filter "UpstreamLockedDriftStrict"        # 1/1 — preuve drift NFR2 (item locked → 4 clés, sans marqueur)
php artisan test --filter "CapabilityOverrideAuditLog"       # 3/3 — modèle (log/append-only/nullOnDelete)
php artisan test --filter "CapabilitiesOverrideAudit"        # 7/7 — create/update/delete, permissive vs local, standalone+NFR3, atomicité
# Non-régression (0 régression attendue) :
php artisan test --filter "StateCompiler|UpstreamContractResolution|CapabilitiesTab|ControlHubContract|PermissiveOverride|UpstreamLockResolver|ParcDefaults|ContractV1|CapabilityPolicy"
```

### Scénario 10.1 — Preuve drift : un item `locked` amont gagne au compilé et est réappliqué STRICT (CRITIQUE)

**Procédure** : contrat actif avec un item `registry`/`locked`/`instance` (ex. `EnableLUA`)
matchant une projection de capacité ; un réglage local existe sur la même clé avec
une valeur DIVERGENTE (dérive simulée). Compiler le desired-state d'un poste cible.

**Attendu** :
- L'item compilé porte la **valeur amont** (la maille `Upstream` gagne — inbattable).
- L'item compilé expose **exactement les 4 clés** `type`, `semantics`, `payload`, `hash`.
- **AUCUN** marqueur `mode` / `drift` / `drift_policy` (le STRICT est implicite, 27.8).
- Côté agent (comportement, non rejoué ici) : `provision.Reconcile` réapplique l'item
  sur toute divergence de hash — cf. couverture STRICT Go 27.8 (`agent/shared/handler_*_test.go`).
  Un item compilé étant **source-agnostique**, cette couverture vaut pour l'item verrouillé.

### Scénario 10.2 — Override permissif posé → 1 ligne d'audit `permissive`

**Procédure** : contrat actif avec un item `registry`/`permissive`/`instance` matchant
une capacité. Page d'un parc → onglet « Options / Capacités » → ajouter/éditer un
override sur cette capacité (le geste est AUTORISÉ — un permissif n'est pas un verrou).

**Attendu** (table `capability_override_audit_logs`) :
- **Un et un seul** événement, `action = create` (ou `update` si override préexistant).
- `actor_user_id` + `actor_login` = le refnum ; `capability_id` + `capability_label` ;
  `assignable_type = WorkstationGroup`, `assignable_id` = parc, `scope_label` = nom du parc.
- `old_value` (valeur précédente, null si création) / `new_value` (valeur saisie).
- `upstream_status = 'permissive'` ; `created_at` posé.

### Scénario 10.3 — Override local (sans contrainte amont) → audit `local`

**Procédure** : poser un override par parc sur une capacité **sans** contrainte amont
(standalone OU contrat actif sans item pour cette capacité).

**Attendu** :
- Ligne d'audit `action = create` (ou `update`), `upstream_status = 'local'`.
- Mêmes champs acteur/item/périmètre/old-new/horodatage.

### Scénario 10.4 — Retrait d'un override → audit `delete`

**Procédure** : retirer un override existant (bouton « Retirer ») sur une capacité
non verrouillée.

**Attendu** :
- Ligne d'audit `action = delete`, `old_value` = ancienne valeur, `new_value = null`,
  `upstream_status` résolu (`permissive` ou `local`).
- L'override est bien supprimé de `capability_assignments` (retour au défaut).

### Scénario 10.5 — Standalone : override audité `local`, court-circuit NFR3 préservé

**Procédure** : aucun contrat amont actif. Poser un override par parc.

**Attendu** :
- Ligne d'audit `action = create`, `upstream_status = 'local'`.
- **ZÉRO** requête `controlhub_contract_items` lors du `saveOverride` (court-circuit
  NFR3 — prouvé par test Feature via `DB::getQueryLog()`).
- Aucun badge/contrainte amont affiché (UI byte-identique à 27.12).

### Scénario 10.6 — Atomicité acte ↔ trace (échec d'audit → override NON persisté)

**Procédure** (automatisé) : simuler l'échec de l'écriture d'audit (table absente) →
le `saveOverride` lève dans la transaction.

**Attendu** :
- L'override **n'est pas** confirmé dans `capability_assignments` (rollback complet) :
  jamais d'acte sans trace, jamais de trace sans acte.

### Scénario 10.7 — Append-only + survie aux suppressions

**Procédure** (automatisé) : tenter un UPDATE d'une ligne d'audit ; supprimer
l'utilisateur et la capacité référencés.

**Attendu** :
- UPDATE → `LogicException` (table append-only).
- Après suppression : `actor_user_id` / `capability_id` mis à `null` (FK `nullOnDelete`),
  mais `actor_login` / `capability_label` / `scope_label` **intacts** (dénormalisation).

### Scénario 10.8 — R3 : aucun identifiant/libellé « central »

```bash
grep -rin "central" \
  app/Models/CapabilityOverrideAuditLog.php \
  database/migrations/2026_06_27_120000_create_capability_override_audit_logs_table.php \
  resources/views/pages/parc/groups/_partials/capabilities-tab.blade.php
# → uniquement les commentaires garde-fou (zéro identifiant/libellé)
```

---

## Checklist rapide Story 29.5

- [ ] `php artisan test --filter "UpstreamLockedDriftStrict"` → 1/1 vert (preuve drift, item 4 clés sans marqueur)
- [ ] `php artisan test --filter "CapabilityOverrideAuditLog"` → 3/3 verts (log/append-only/nullOnDelete)
- [ ] `php artisan test --filter "CapabilitiesOverrideAudit"` → 7/7 verts (create/update/delete, permissive vs local, standalone+NFR3, atomicité)
- [ ] Non-régression `StateCompiler|UpstreamContractResolution|CapabilitiesTab|ControlHubContract|PermissiveOverride|UpstreamLockResolver|ParcDefaults|ContractV1|CapabilityPolicy` → 0 régression
- [ ] Drift : item `locked` → desired-state 4 clés, valeur amont, AUCUN marqueur `mode`/`drift` (Scénario 10.1)
- [ ] Override permissif → audit `permissive` (Scénario 10.2) ; override local → audit `local` (Scénario 10.3)
- [ ] Retrait → audit `delete` `new_value=null` (Scénario 10.4)
- [ ] Standalone → audit `local` + 0 requête items (Scénario 10.5)
- [ ] Atomicité : échec d'audit → override non persisté (Scénario 10.6)
- [ ] Append-only (UPDATE→LogicException) + FKs nullOnDelete + dénormalisés intacts (Scénario 10.7)
- [ ] R3 : aucun identifiant/libellé « central » (Scénario 10.8)
- [ ] Golden / `FROZEN_STATE_HASH` / `ContractV1` INCHANGÉS ; `StateCompiler` item 4 clés intact
- [ ] ⚠️ VM : migration `capability_override_audit_logs` non auto-jouée → `php artisan migrate` avant e2e base

---

## Section 11 — Réception des labels + groupes imposés (preuve FR9) et durcissement `label_name` (Story 30.1, 2026-06-27)

> **Story à DEUX moitiés (patron 29.5).**
> **Moitié FR9 = PREUVE, pas construction.** Les labels (nom + `mode` libre/réservé) et les groupes imposés (nom + `label_name`) sont **DÉJÀ** reçus et persistés par 28.1 (schéma `controlhub_contract_labels` + `controlhub_contract_imposed_groups` + enum `ControlHubLabelMode`) + 28.2 (`ControlHubContractIngestionService`). 30.1 **prouve** cette chaîne par `UpstreamLabelsImposedGroupsReceptionTest` et **ne crée AUCUNE** table/modèle/enum/migration.
> **Moitié durcissement = seule livraison.** Un groupe imposé dont le `label_name` (non-nul) ne référence **aucun** label déclaré dans le même contrat est désormais **refusé** à la réception (`InvalidUpstreamContractException` levée **avant** la transaction → rollback total).
>
> ⚠️ **Aucune migration** en 30.1 → rien à jouer en VM. Back-end pur (aucune UI/route).

### Scénario 11.1 — Réception : labels (libre/réservé) + groupes imposés persistés et requêtables (CRITIQUE)

**But** : prouver que le vocabulaire de ciblage amont (labels + groupes imposés) est reçu, persisté, et que `mode` est correctement casté.

**Pré-requis** : aucun contrat actif (`controlhub_contracts` vide).

**Étapes** :
1. Ingérer un contrat avec deux labels (`salle-info` = `reserved`, `nomade` = `free`) et deux groupes imposés (`parc-terminales` → `salle-info` ; `parc-libre` sans `label_name`).
2. Relire les labels **via le modèle** `ControlHubContractLabel` et les groupes via `ControlHubContractImposedGroup`.

**Attendu** :
- `controlhub_contract_labels` contient 2 lignes ; `salle-info`.`mode` === `ControlHubLabelMode::Reserved` (réservé), `nomade`.`mode` === `ControlHubLabelMode::Free` (libre) — **mode casté**, pas une chaîne brute.
- `controlhub_contract_imposed_groups` contient 2 lignes ; `parc-terminales`.`label_name` === `salle-info` ; `parc-libre`.`label_name` === `null`.

```bash
php artisan test --filter "UpstreamLabelsImposedGroupsReception" # HÔTE php8.4+sqlite
```

### Scénario 11.2 — Re-réception identique → no-op (idempotence NFR4)

**But** : une 2ᵉ réception du même vocabulaire n'écrit rien et n'émet aucun événement.

**Étapes** :
1. Ingérer le contrat du Scénario 11.1, puis l'ingérer **à l'identique**.

**Attendu** :
- 2ᵉ réception : `result.mutated === false`, aucun `ControlHubContractChanged` dispatché.
- Compteurs `result.labels` / `result.imposedGroups` `{created:0, updated:0, deleted:0}` ; comptes de lignes inchangés.

### Scénario 11.3 — Vocabulaire modifié → upsert + prune avec compteurs exacts

**But** : un contrat ultérieur réconcilie labels et groupes imposés sur leurs clés naturelles.

**Étapes** :
1. Après le Scénario 11.1, ré-ingérer avec : `salle-info` passé en `free` (UPDATE), `labo` ajouté (CREATE) + `nomade` retiré (PRUNE) ; `parc-terminales` → `labo` (UPDATE) + `parc-secondes` → `labo` (CREATE).

**Attendu** :
- `result.labels` = `{created:1, updated:1, deleted:1}` ; `result.imposedGroups` = `{created:1, updated:1, deleted:0}`.
- `nomade` absent ; `salle-info`.`mode` === `Free`.

### Scénario 11.4 — Durcissement : `label_name` cohérent → ingestion réussit (AC#4)

**But** : un groupe imposé désignant un label **déclaré** (en mode `free` OU `reserved`) est accepté.

**Étapes** :
1. Ingérer un contrat avec label `salle-info`/`reserved` (ou `nomade`/`free`) + groupe imposé `parc-terminales` → ce label.

**Attendu** : ingestion réussie ; groupe persisté avec son `label_name`. (Le durcissement **n'exige pas** le mode `reserved`.)

### Scénario 11.5 — Durcissement : `label_name` orphelin → ingestion REFUSÉE, rien n'est persisté (CRITIQUE, AC#5)

**But** : un groupe imposé désignant un label **non déclaré** dans le même contrat est rejeté **avant** toute écriture.

**Étapes** :
1. Ingérer un contrat avec label `salle-info` + groupe imposé `parc-terminales` → `label_name = introuvable` (jamais déclaré).

**Attendu** :
- `InvalidUpstreamContractException` levée ; message = `Contrat amont invalide — imposed_groups.label_name (parc-terminales) : label associé « introuvable » non déclaré dans le contrat`.
- **Rollback total** : `controlhub_contracts`, `controlhub_contract_labels`, `controlhub_contract_imposed_groups` restent à 0 (aucune écriture partielle).
- Si un contrat valide préexistait : son état est **strictement intact** (l'ancien `label_name` cohérent demeure).

### Scénario 11.6 — Durcissement : `label_name` nul/absent/'' → légitime (AC#6)

**But** : un groupe imposé **sans** label associé n'est jamais contraint.

**Étapes** :
1. Ingérer des groupes imposés avec `label_name` = `null`, `''`, et absent.

**Attendu** : ingestion réussie ; les trois groupes persistent avec `label_name === null` (jamais `''`).

### Scénario 11.7 — Garde-fou inerte : standalone / sans groupes imposés (NFR3, AC#7)

**But** : sans groupe imposé (ou payload vide), le cross-check ne se déclenche jamais.

**Attendu** : aucune erreur ; comportement strictement inchangé (tables vides ou labels seuls persistés).

### Scénario 11.8 — R3 : aucun identifiant/libellé « central »

```bash
grep -rin "central" \
  app/Services/ControlHub/ControlHubContractIngestionService.php \
  tests/Feature/ControlHub/UpstreamLabelsImposedGroupsReceptionTest.php
# → uniquement les commentaires garde-fou (zéro identifiant/libellé/message)
```

---

## Checklist rapide Story 30.1

- [ ] `php artisan test --filter "UpstreamLabelsImposedGroupsReception"` → 10/10 verts (preuve FR9 + durcissement)
- [ ] Réception : labels `mode` casté `ControlHubLabelMode` (libre/réservé) + groupes imposés `label_name` associé/null (Scénario 11.1)
- [ ] Re-réception identique → no-op `mutated=false`, aucun event (Scénario 11.2)
- [ ] Vocabulaire modifié → upsert/prune, compteurs `result.labels`/`result.imposedGroups` exacts (Scénario 11.3)
- [ ] `label_name` cohérent (free OU reserved) → succès (Scénario 11.4)
- [ ] `label_name` orphelin → `InvalidUpstreamContractException` + rollback total (Scénario 11.5)
- [ ] `label_name` nul/''/absent → succès, `label_name = null` (Scénario 11.6)
- [ ] Standalone / sans groupes imposés → garde-fou inerte (Scénario 11.7)
- [ ] R3 : aucun identifiant/libellé/message « central » (Scénario 11.8)
- [ ] Non-régression `ControlHubContractIngestion|ControlHubContract|UpstreamContractResolution|ContractV1` → 0 régression
- [ ] AUCUNE migration/modèle/enum créé ; golden / `FROZEN_STATE_HASH` / `ContractV1` INCHANGÉS ; `StateCompiler` intact
- [ ] ✅ VM : aucune migration en 30.1 → rien à jouer

---

## Section 12 — Résolution d'un item ciblant un label (Story 30.4, 2026-06-28)

> **Modèle** : dernier maillon de la propagation par label (FR12). 28.3 chargeait
> uniquement les items `target_type = instance` et **ignorait proprement** les items
> `target_type = label` (couture Epic 30). 30.4 **lève** ce filtre dans
> `App\Services\ControlHub\Resolution\UpstreamContractSource` : un item amont ciblant
> `label:<nom>` s'applique désormais à **tout poste** membre d'un `WorkstationGroup`
> portant `controlhub_label = <nom>` (mapping 30.2, par NOM, sans FK dure).
>
> **Point d'extension (ZÉRO changement du moteur)** : toute l'expansion vit dans la
> **source** amont — `ensureResolved()` charge `whereIn(target_type, [instance, label])`
> et pré-groupe les items label par `target_label` dans `groupedByLabel[label][type|scope]` ;
> `candidatesFor(type, scope, $ctx)` réunit `instance ∪ (labels portés par le poste)` ;
> le helper `labelsCarriedBy($ctx)` lit `WorkstationGroup.controlhub_label` via
> `TargetContext::workstationGroupIds()`, **mémoïsé par poste** (anti-N+1). Le
> `TargetContext` est relayé par `UpstreamAwareProvider::itemsFor()` (1 ligne). **AUCUNE**
> ligne ajoutée à `StateCompiler`/`StateMaille`/`specificity()`/`AgentServiceProvider`
> (D2 ne fuit pas) ; la maille dérive **uniquement** de `enforcement_state`
> (`locked → Upstream` rang -1, `permissive → UpstreamPermissive` rang 6).
>
> **Règle verrou/permissif SANS spécificité inter-parcs (FR12)** : deux items de même
> état (ex. 2 verrous via 2 parcs) sont au **même rang** ⇒ aucun arbitrage par parc
> (ni logique>physique) — le tiebreak intra-maille (`updated_at` desc / `sourceId` desc)
> tranche. **Collision** (2 verrous contradictoires même clé) : 30.4 NE résout PAS ;
> elle réutilise le warning `agent.state.conflict` existant + tiebreak déterministe (pas
> d'état vide). La **prévention prédictive** à l'assignation relève de la **Story 30.5**.

**Validation automatisée (HÔTE) — préalable à tout test manuel :**

```bash
# Hôte (php8.4 + pdo_sqlite) — l'hôte n'a pas APCu
CACHE_DRIVER=array vendor/bin/phpunit --filter UpstreamContractResolution   # 18/18 attendus
CACHE_DRIVER=array vendor/bin/phpunit --filter 'StateCompiler|ControlHubContract|Upstream|Imposed|WorkstationGroupLabel'  # non-régression verte
```

### Scénario 12.1 — Un item `label:<nom>` s'applique à un poste portant le label (AC #1)

**Procédure** : contrat actif avec un item `registry`/`locked` ciblant `target_type = label`,
`target_label = salle-info` (`key = HKCU|P|Foo|REG_DWORD`, `value = 1`) ; un `WorkstationGroup`
portant `controlhub_label = salle-info` auquel le poste appartient (salle ou parc) ; un
réglage **local** sur la même clé (valeur `0`).

**Attendu** : le compilé du poste porte **une seule** valeur = **1** (valeur amont) — l'item
label gagne EXACTEMENT comme un item `instance` (maille `Upstream` rang -1, via `specificity()`).

### Scénario 12.2 — Un poste NE portant PAS le label n'est pas touché (AC #2)

**Procédure** : même item `label:salle-info`, mais le poste n'appartient à **aucun** groupe
portant `controlhub_label = salle-info` (il porte un autre label, ou aucun).

**Attendu** : l'item amont **n'est pas injecté** ; le réglage **local** gagnant subsiste —
compilé strictement comme si l'item label n'existait pas pour ce poste.

### Scénario 12.3 — Cumul deux parcs/labels même propriété : règle verrou/permissif (AC #3)

**Procédure** : poste membre de deux groupes portant deux labels `<A>` et `<B>` ; deux items
amont sur la **même** `exclusiveKey()`, l'un via `label:<A>` l'autre via `label:<B>`.

**Attendu** :
- les deux items **permissifs** (`UpstreamPermissive` rang 6) → **toute** maille locale gagne
  (plancher battu) ;
- un item **verrouillé** (`Upstream` rang -1) → l'amont gagne sur le local ;
- entre deux candidats amont de **même état** (même maille) : **aucune** spécificité
  inter-parcs — l'arbitrage retombe sur le tiebreak intra-maille (`updated_at`/`sourceId`),
  **jamais** sur un ordre logique>physique ni un classement des parcs.

### Scénario 12.4 — Collision insoluble : warning + tiebreak déterministe (AC #4)

**Procédure** : deux items amont **`locked`** ciblant des labels portés par le **même** poste,
imposant des valeurs **contradictoires** sur la **même** `exclusiveKey()`.

**Attendu** : les deux candidats sont à la **même** maille `Upstream` (rang -1) ⇒
`resolveExclusiveWinner` détecte « tied-at-top » et **émet `agent.state.conflict`** (channel
`agent`, `maille = upstream`, `rule_ids = [<id item A>, <id item B>]`), puis applique le
**tiebreak déterministe** (`updated_at`/`sourceId`) — un état **non vide** et **déterministe**
est servi (NFR4). 30.4 NE résout PAS la collision et n'introduit **aucune** résolution
silencieuse/arbitraire (la prévention prédictive = **Story 30.5**).

### Scénario 12.5 — Standalone & inertie sans label : byte-identique + zéro requête (AC #5/#6)

**Procédure** : (a) aucun contrat actif ; (b) contrat actif **sans aucun** item
`target_type = label`. Dans les deux cas, compiler l'état d'un poste — y compris un poste
**membre d'un parc porteur de label**.

**Attendu** : compilé **byte-identique** au comportement 28.3 (mêmes items, même ordre, même
`hashState()`) ; **aucune** requête `workstation_groups` pour la résolution des labels portés
(court-circuit `groupedByLabel === []`) — vérifié par `DB::enableQueryLog()`. Avec item label
présent, l'injection est **déterministe** (`travel()` → même hash).

### Scénario 12.6 — D2 confiné & R3 (AC #7)

```bash
# (a) D2 : aucune ligne ajoutée au moteur
git diff app/Services/Agent/StateCompiler.php app/Enums/StateMaille.php   # vide pour 30.4

# (b) R3 : aucun identifiant « central »
grep -rin "central" app/Services/ControlHub/Resolution/   # uniquement commentaires garde-fou
```

**Attendu** : toute l'expansion vit dans `UpstreamContractSource` (+ relais `$ctx` par
`UpstreamAwareProvider`) ; aucun **identifiant** (classe, méthode, propriété, message, test)
ne contient « central » — couvert par `r3_no_central_identifier` (scan reflection + littéraux).

---

## Checklist rapide Story 30.4

- [ ] `CACHE_DRIVER=array vendor/bin/phpunit --filter UpstreamContractResolution` → 18/18 verts
- [ ] Non-régression `--filter 'StateCompiler|ControlHubContract|Upstream|Imposed|WorkstationGroupLabel'` → verte (182)
- [ ] Item `label:<nom>` appliqué au poste portant le label (Scénario 12.1)
- [ ] Poste sans le label → non touché (Scénario 12.2)
- [ ] Cumul 2 parcs/labels : verrou>local, permissif<local, sans spécificité inter-parcs (Scénario 12.3)
- [ ] Collision 2 verrous → warning `agent.state.conflict` + tiebreak déterministe (Scénario 12.4)
- [ ] Standalone & sans-item-label byte-identique + zéro requête WG + déterminisme (Scénario 12.5)
- [ ] D2 confiné (zéro ligne `StateCompiler`/`StateMaille`) + R3 sans « central » (Scénario 12.6)
- [ ] Test 28.3 `label_targeted_item_is_ignored` RÉÉCRIT (poste sans le label → non appliqué) + AC #1 ajouté

---

## Section 13 — Validation prédictive à l'assignation (Story 30.5, 2026-06-28)

**But.** Dernier maillon de l'Epic 30 (FR13) : intercepter, **à l'assignation d'un
label** ou **au rattachement d'un poste à un parc labellisé**, une collision
**insoluble** — deux items amont VERROUILLÉS (`locked`) imposant des valeurs
CONTRADICTOIRES sur la MÊME propriété exclusive (`exclusiveKey`) d'un même poste —
et **refuser** l'opération AVANT toute écriture, avec un message explicite
(propriété, périmètre, valeurs). C'est la **PRÉVENTION** proactive ; le filet
runtime observable (warning `agent.state.conflict`) reste 30.4.

> **Frontière 30.4 ↔ 30.5.** 30.4 OBSERVE au runtime (tiebreak déterministe, pas
> d'état vide). 30.5 PRÉVIENT à l'assignation. Les deux coexistent : 30.5 ferme la
> porte d'entrée, 30.4 reste le filet pour les collisions résiduelles (contrat mis
> à jour APRÈS coup, anomalies). Aucune ligne ajoutée à `StateCompiler` /
> `StateMaille` / la décoration des providers (D2 confiné, AC #5b).

**Architecture (réutilisation stricte).** `UpstreamContractSource::lockedLabelCandidates()`
expose, filtrés à `StateMaille::Upstream` (locked SEULEMENT), les candidats label
déjà construits par 30.4 (`$groupedByLabel`) — court-circuit `[]` si aucun item
label ou pas de contrat (NFR3). `UpstreamLockCollisionDetector` (pur lecture) keye
ces candidats via l'`exclusiveKey()` des `KeyedExclusiveProvider` EXISTANTS
(`registry` ; `shortcuts` aggregate exclu) et détecte ≥ 2 valeurs `locked`
distinctes dont **au moins une provient d'un label gagné** (filtre AC #8). Refus
via `UpstreamLockCollisionException` (message FR toast). Surfaces gardées :
`WorkstationGroupLabelService::assignLabel` (après la matrice 30.2, avant écriture)
et les points d'AJOUT d'appartenance de `WorkstationGroupService`
(`addMachineToGroup` / `setMachineGroups` / `setGroupMachines` /
`bulkAddMachinesToGroup` / `assignMachineToPhysicalRoom`) via un helper unique —
JAMAIS les retraits.

### Scénario 13.1 — Assignation d'un label introduisant une collision : REFUS (AC #1)

**Préparation** : contrat amont actif ; deux items `registry` `locked` même clé
`HKCU\…\Foo` valeurs ≠ (label `parc-a` → 1, label `parc-b` → 2) ; un parc `G_A`
`controlhub_label = parc-a` et un parc `G_B` sans label ; un poste membre de `G_A`
ET `G_B`.

**Action** : assigner le label `parc-b` à `G_B` (page parc → édition du groupe).

**Attendu** : refus en toast nommant la propriété (`hkcu|…|foo`), les **deux**
sources amont (`#id`) et les **deux** valeurs (1 / 2) ; **aucune** écriture — la
colonne `controlhub_label` de `G_B` reste `null`. Pas de redirection.

### Scénario 13.2 — Pas de collision / valeurs concordantes : transparent (AC #2)

Clés DISJOINTES, OU même clé même valeur (1 des deux côtés) ⇒ l'assignation
**réussit exactement comme en 30.2** (colonne écrite, toast de succès). Des valeurs
**identiques** ne constituent PAS une collision (rien à trancher).

### Scénario 13.3 — Permissif / absent jamais bloquant (AC #3)

Un item `permissive` (d'un côté) sur la propriété partagée — ou `locked` d'un côté
et `permissive`/`absent` de l'autre — ⇒ **aucun** blocage. Un permissif est un
**plancher** surchargeable (filtré dès `lockedLabelCandidates()`) ; un `absent`
n'impose rien. Seul `locked`/`locked` contradictoire bloque.

### Scénario 13.4 — Rattachement d'un poste à un parc labellisé : REFUS (AC #4)

Poste portant déjà `parc-a` (locked X=1) ; parc `G_B` `controlhub_label = parc-b`
(locked X=2). Rattacher le poste à `G_B` (`addMachineToGroup` / `bulkAdd` /
sélection batch) ⇒ refus, **aucune** ligne `workstation_group_workstation` ajoutée.
Un rattachement à un parc **non labellisé** (ou label sans collision) **réussit
inchangé** (hot-path parc préservé).

### Scénario 13.5 — Standalone & court-circuit NFR3 (AC #6)

(a) Sans contrat actif, (b) contrat actif SANS aucun item label `locked` ⇒ le
détecteur **court-circuite** (`hasLockedLabelItems() === false`) AVANT tout
eager-load de population : **zéro** requête parc/pivot imputable à la garde
(`DB::enableQueryLog()`), assignation 30.2 et rattachement parc **byte-équivalents**.

```bash
# Court-circuit prouvé par comptage de requêtes (test révélateur)
CACHE_DRIVER=array vendor/bin/phpunit --filter 'detector_short_circuits'
```

### Scénario 13.6 — Déterminisme du rapport (AC #7)

Collision touchant plusieurs postes ⇒ message/DTO **identiques** sur deux
exécutions (et via `travel()`), périmètre énuméré de façon stable (clés / labels /
`sourceId` / postes triés).

### Scénario 13.7 — Collision pré-existante non aggravée : NON bloquée (AC #8)

Un poste cumulant DÉJÀ deux labels `locked` contradictoires (collision pré-existante,
ressort de 30.4) ⇒ une assignation **orthogonale** (label sans item `locked` sur la
clé en conflit) **n'est PAS bloquée** : la garde ne refuse que les collisions
**introduites** par l'opération (au moins un côté provient du label ajouté). Pas de
faux refus paralysant.

### Scénario 13.8 — D2 confiné & R3 (AC #5)

```bash
# (a) D2 : aucune ligne ajoutée au moteur ni à la décoration des providers
git diff app/Services/Agent/StateCompiler.php app/Enums/StateMaille.php   # vide pour 30.5
# AgentServiceProvider : SEUL le binding du détecteur est ajouté (array_map wrap intact)

# (b) R3 : aucun identifiant/message « central » dans les fichiers livrés
grep -rin "central" app/Services/ControlHub/Resolution/UpstreamLockCollision*.php \
  app/Exceptions/ControlHub/UpstreamLockCollisionException.php   # commentaires garde-fou only
```

**Attendu** : la prévention vit dans les services d'ASSIGNATION ; le moteur n'est
pas touché ; aucun identifiant/littéral « central » (couvert par
`r3_no_central_identifier` + `d2_engine_files_do_not_reference_30_5_collision_logic`).

---

## Checklist rapide Story 30.5

- [ ] `CACHE_DRIVER=array vendor/bin/phpunit --filter UpstreamLockCollision` → 16/16 verts
- [ ] Non-régression `--filter 'UpstreamContractResolution|WorkstationGroupLabel|StateCompiler|ControlHubContract|Imposed'` → verte (143)
- [ ] Suite parc `--filter 'WorkstationGroup|Parc|Machine'` → verte (536, 1 skip pré-existant)
- [ ] Assignation introduisant une collision → refus + DB inchangée (Scénario 13.1)
- [ ] Pas de collision / valeurs égales → succès 30.2 (Scénario 13.2)
- [ ] Permissif / absent jamais bloquant (Scénario 13.3)
- [ ] Rattachement à un parc labellisé collisionnant → refus + pivot inchangé (Scénario 13.4)
- [ ] Standalone / sans item label locked → court-circuit + zéro requête parc (Scénario 13.5)
- [ ] Déterminisme du rapport `travel()` (Scénario 13.6)
- [ ] Collision pré-existante non aggravée → non bloquée (Scénario 13.7)
- [ ] D2 confiné (zéro ligne `StateCompiler`/`StateMaille`/décoration) + R3 (Scénario 13.8)

---

## Section 14 — Bornage de l'install au catalogue amont (Story 31.1, 2026-06-28)

Le canal d'install refnum (ajout d'apps WPKG aux parcs/postes/profils) est
**conservé mais filtré** au catalogue applicatif faisant autorité du contrat amont
actif (`controlhub_contract_catalog_apps`, livré en 28.1). Deux couches symétriques
à 29.1 :

- **Consultation** — `Application::scopeInUpstreamCatalog` retire de toutes les
  listes proposées (page parc, page machine, bulk catégorie, sélecteur de profil)
  les apps dont `app_id` n'est pas dans le catalogue.
- **Enforcement** — `AppProfileService::assertApplicationsInUpstreamCatalog`
  (defense-in-depth, filet contre un payload Livewire forgé) refuse l'écriture
  pivot avec `ApplicationNotInUpstreamCatalogException` (toast « hors catalogue
  amont »).

Source unique : `UpstreamCatalogResolver` (mémoïsé, court-circuit NFR3 via
`ControlHubContract::active()` — zéro requête `controlhub_contract_catalog_apps`
sans contrat actif). Match sur `app_key == applications.app_id` (string, D2).
Borner = filtrer l'**ajout** seulement (D4 : retrait et inventaire local libres).

### Scénario 14.1 — Contrat actif, catalogue {firefox}

Pré-requis : un contrat amont `active` dont le catalogue contient `firefox` ; deux
apps locales `firefox` (en catalogue) et `chrome` (hors).

1. Page parc → onglet WPKG → « Ajouter une application » : **seul `firefox`** est
   proposé (`chrome` absent de la liste). Idem page machine et bulk catégorie.
2. Forcer (payload Livewire) l'ajout de `chrome` à un parc → **toast « hors
   catalogue amont »**, aucune ligne `application_workstation_group` écrite.
3. Ajouter `firefox` → succès, pivot écrit (canal d'install pleinement utilisable).

### Scénario 14.2 — Standalone (aucun contrat actif)

Aucun `ControlHubContract` : **toutes** les apps locales proposées et installables,
comportement byte-identique au pré-31.1 (NFR3). Court-circuit prouvé par comptage
de requêtes (aucune requête sur `controlhub_contract_catalog_apps`).

### Scénario 14.3 — Catalogue vide (D1)

Contrat `active` SANS aucune `catalogApps` ⇒ `isBounded() === false` ⇒ pas de
bornage (le refnum n'est pas verrouillé hors de toutes ses apps). Identique au
standalone.

### Scénario 14.4 — Appelant non-web / rupture du lien

- Console / agent / seeder (`Auth::check() === false`) : garde catalogue inerte
  (no-op), non-régression (AC #6).
- Lien rompu (`link_state = severed`) ⇒ `active()` null ⇒ bornage **levé
  automatiquement** (release à la rupture = Story 32.1, hors scope ici).

```bash
# Hôte (php8.4 + pdo_sqlite)
CACHE_DRIVER=array vendor/bin/phpunit --filter UpstreamCatalogBoundary   # 15/15
# Non-régression canal d'install + domaine ControlHub
CACHE_DRIVER=array vendor/bin/phpunit --filter 'AppProfile|Wpkg|ControlHubContract|UpstreamCatalog'  # 416/416

# R3 : aucun identifiant/message « central » (uniquement commentaires garde-fou)
grep -rin central app/Services/ControlHub/UpstreamCatalogResolver.php \
  app/Exceptions/ControlHub/ApplicationNotInUpstreamCatalogException.php
```

**Attendu** : le bornage vit dans le résolveur + le scope + le garde service ;
sans contrat actif, comportement strictement inchangé (NFR3) ; aucun identifiant
« central » (couvert par les en-têtes garde-fou R3).

### Scénario 14.5 — Canaux d'install additionnels bornés (post-correctifs review, 2026-06-28)

La review a révélé des canaux d'install que le bornage initial ne couvrait pas. Tous
corrigés ; à dérouler avec un contrat actif catalogue `{firefox}` + apps `firefox`/`chrome` :

1. **Composition de profil** (page `parc-settings/profiles`, onglet Applications) :
   « Ajouter des applications » ne propose que `firefox` ; forcer `chrome` → toast
   « hors catalogue amont » (et non « Erreur lors de l'ajout » opaque), aucun pivot écrit.
2. **Clone de configuration** (page parc → cloner un parc source contenant `chrome`
   vers une cible) → toast « hors catalogue amont », la cible ne reçoit pas `chrome`.
3. **Défaut diffusé fleet-wide** (`admin/settings/parc-defaults` → onglet Applications) :
   la recherche d'ajout ne propose que `firefox` ; tenter « Appliquer par défaut » sur
   `chrome` (payload forcé) → toast d'erreur, `is_parc_default` reste `false`.
4. **Retrait toujours permis (D4)** : retirer un défaut `chrome` posé avant le contrat
   → réussit (le retrait n'est jamais borné). Idem retrait d'apps parc/poste/profil.

```bash
CACHE_DRIVER=array vendor/bin/phpunit --filter ParcDefaultsCatalogBoundary   # 4/4
CACHE_DRIVER=array vendor/bin/phpunit --filter 'AppProfile|Wpkg|ControlHubContract|UpstreamCatalog|ParcDefaults'  # 451/451
```

## Checklist rapide Story 31.1

- [ ] `CACHE_DRIVER=array vendor/bin/phpunit --filter UpstreamCatalogBoundary` → 15/15 verts
- [ ] Non-régression `--filter 'AppProfile|Wpkg|ControlHubContract|UpstreamCatalog'` → verte (416)
- [ ] Suite parc Livewire `tests/Feature/Livewire/Parc` → verte (aucune régression d'affichage)
- [ ] Contrat actif catalogue {A} : seule A proposée ; tenter B = toast « hors catalogue amont » + pivot inchangé (Scénario 14.1)
- [ ] Standalone : tout proposé + installable + zéro requête catalogue (Scénario 14.2)
- [ ] Catalogue vide = pas de bornage (Scénario 14.3)
- [ ] Appelant non authentifié non bloqué + severed = bornage levé (Scénario 14.4)
- [ ] Canaux additionnels bornés : profil, clone, défaut diffusé ; retrait toujours permis (Scénario 14.5)
- [ ] R3 : aucun identifiant/message « central » dans les fichiers livrés

## Section 15 — Déclenchement d'install en désir d'état (Story 31.2, 2026-06-28)

L'autorité amont peut **ORDONNER l'install d'une app** sur une cible : un
`controlhub_contract_items` `type='applications'`, `key=<app_id>`,
`enforcement_state` non-`absent`, `target_type=instance|label` (le schéma 28.1 et
l'ingestion 28.2 l'acceptent **déjà**, sans whitelist de `type`). 31.2 **projette**
cet ordre dans l'ensemble `applications` désiré que l'agent récupère au check-in
(`GET /api/v1/agent/state`, portée `machine`).

- **Pont** : `UpstreamContractSource::orderedApplicationAppIds($ctx)` (accesseur
  lecture seule, court-circuit NFR3) renvoie `instance ∪ labels portés` ;
  `ApplicationsStateProvider` UNIONNE ces `app_id` à son ensemble cible **avant
  hydratation** ⇒ payload `{app_id, name}` **identique** quelle que soit la source.
- **Idempotence** : une app aussi résolue localement collapse en **UN** item (dédup
  aggregate du compilateur) — la présence dans l'ensemble pilote WPKG `<check>`, ce
  n'est **pas** un acte impératif (pas de réinstallation). Le **retrait** existe déjà
  par omission (`profiles.xml` synchronisé par WPKG) : 31.2 ne pose aucun canal
  `<remove>`/`absent`.
- **Gap connu (D4)** : un ordre visant un `app_id` **absent** de l'inventaire local
  `applications` est **skip+warn** (journalisé, non livré) — comblé par la **Story
  31.3** (approvisionnement auto depuis le dépôt SambaEdu).

> Pré-requis VM : un contrat amont `active`, un poste enrôlé `PC1`, son token agent.
> `APPID=firefox` doit exister comme `Application` locale (sinon → 31.3).

### Scénario 15.1 — Ordre cible `instance` (toute la flotte)

1. Poser un item `applications`/`locked`/`instance` `key=firefox` dans le contrat actif.
2. `curl -s -H "Authorization: Bearer <token>" https://<se5>/api/v1/agent/state | jq '.machine[] | select(.type=="applications") | .payload.app_id'`
   ⇒ `firefox` **figure** dans `machine.applications` (item `{app_id, name}`), pour
   **n'importe quel** poste enrôlé.
3. Vérifier le payload : exactement `{app_id, name}` (4 clés d'item `type/semantics/payload/hash` — contrat agent figé, aucun champ ajouté).

### Scénario 15.2 — Ordre cible `label` (porté vs non porté)

Pré-requis : un parc `salle-info` portant `controlhub_label = salle-info` (mapping
30.2) ; `PC1` membre de ce parc, `PC2` membre d'un autre parc.

1. Poser un item `applications`/`locked`/`label` `key=vlc`, `target_label=salle-info`.
2. `state` de **PC1** ⇒ `vlc` présent dans `machine.applications`.
3. `state` de **PC2** (ne porte pas le label) ⇒ `vlc` **absent**.

### Scénario 15.3 — App déjà affectée localement (dédup / idempotence)

1. Affecter `firefox` au parc de `PC1` **ET** poser l'ordre amont `instance` `firefox`.
2. `curl … | jq '[.machine[] | select(.payload.app_id=="firefox")] | length'` ⇒ **`1`**
   (un seul item malgré les deux sources).
3. Deux check-ins consécutifs (sans changement) renvoient le **même** ETag/hash ⇒
   l'agent ne redéclenche **aucune** réinstallation (réconciliation level-triggered).

### Scénario 15.4 — Standalone (aucun contrat actif)

Aucun `ControlHubContract` ⇒ l'ensemble `applications` est **byte-identique** au
pré-31.2 (seules les sources locales). Court-circuit NFR3 prouvé par query log :
**zéro** requête `controlhub_contract_items`. Idem contrat actif **sans** item
`applications` (que du `registry`) : `orderedApplicationAppIds()` court-circuite
(`[]`), **zéro** requête « labels portés » (`controlhub_label`).

### Scénario 15.5 — Rupture du lien (Story 32.1, hors scope)

`link_state = severed` ⇒ `ControlHubContract::active()` null ⇒
`orderedApplicationAppIds()` renvoie `[]` ⇒ ordres d'install **levés
automatiquement** (release à la rupture validée en 32.1).

```bash
# Hôte (php8.4 + pdo_sqlite)
CACHE_DRIVER=array php artisan test tests/Feature/ControlHub/UpstreamInstallOrderTest.php   # 11/11
# Non-régression desired-state + domaine ControlHub + contrat figé
CACHE_DRIVER=array php artisan test --filter 'Agent|Application|UpstreamContract|UpstreamInstallOrder|ControlHubContract|ContractV1|StateCompiler'  # 720/720 (22 skip env)

# R3 : aucun identifiant/message « central » (uniquement commentaires garde-fou)
grep -rin central app/Services/ControlHub/Resolution/UpstreamContractSource.php \
  app/Services/Agent/Providers/ApplicationsStateProvider.php
```

**Attendu** : l'ordre d'install figure dans l'`applications` désiré du poste ciblé ;
une app déjà présente = un seul item, hash stable (pas de réinstall) ; sans contrat
actif, ensemble inchangé + zéro requête items ; contrat agent figé intact
(`FROZEN_STATE_HASH` inchangé) ; aucun identifiant « central ».

## Checklist rapide Story 31.2

- [ ] `CACHE_DRIVER=array php artisan test tests/Feature/ControlHub/UpstreamInstallOrderTest.php` → 11/11 verts
- [ ] Non-régression `--filter 'Agent|Application|UpstreamContract|UpstreamInstallOrder|ControlHubContract|ContractV1|StateCompiler'` → verte (720, 22 skip env)
- [ ] Ordre `instance` : l'app figure dans `machine.applications` de tout poste enrôlé (Scénario 15.1)
- [ ] Ordre `label` : présent pour le poste portant le label, absent sinon (Scénario 15.2)
- [ ] App locale + ordonnée amont = un seul item + hash stable sur 2 check-ins (Scénario 15.3)
- [ ] Standalone / contrat sans item applications : ensemble inchangé + zéro requête items/labels (Scénario 15.4)
- [ ] Contrat agent figé : `ContractV1Test` vert, `FROZEN_STATE_HASH` inchangé, golden non modifié
- [ ] R3 : aucun identifiant/message « central » dans les fichiers livrés

## Section 16 — Approvisionnement auto d'une app ordonnée (Story 31.3, 2026-06-29)

Comble le **gap D4** de la Story 31.2 : un ORDRE d'install amont visant un `app_id`
**absent** de l'inventaire local `applications` était `skip+warn`. 31.3 **matérialise
automatiquement** la ligne `Application` depuis la **référence de source** que le
catalogue amont porte désormais — l'ordre est alors **pleinement honoré**.

- **Source par-app (« Option B », D1)** : `controlhub_contract_catalog_apps` porte
  deux colonnes **nullables** `source_xml_url` + `source_xml_sha` (recette WPKG du
  dépôt SambaEdu, référencée par l'autorité **enrôlée**). Migration **additive** ;
  clé naturelle `(controlhub_contract_id, app_key)` **inchangée** (idempotence 28.2).
- **Matérialisation seule** : `AppStoreService::materializeFromSource($appId, $source)`
  pose une `Application` (status **`Available`**, `xml_url`/`xml_sha`) via `firstOrCreate`
  — **SANS** install serveur (jamais `installApplication()`), **SANS**
  `Depot`/`DepotApplication`/`DepotSyncService`. La pose réelle sur le poste = agent + WPKG.
- **Provisionneur** (patron 30.3) : listener `ProvisionOrderedApplications` sur
  `ControlHubContractChanged` (à côté de `ReconcileImposedWorkstationGroups`) **+**
  commande `controlhub:provision-ordered-apps` re-jouable. Pour chaque ordre
  `type='applications'` non-`absent` dont l'`app_id` manque : résout la source →
  matérialise. **Court-circuit NFR3** sans contrat actif (no-op total).
- **Résilience (AC6)** : ordre sans entrée catalogue / sans `source_xml_url` ⇒ **log +
  skip** (`agent.applications.provision_skipped`), exception isolée par app — l'ingestion
  28.2 et les autres matérialisations ne sont **jamais** cassées.
- **Idempotence (AC3)** : une `Application` locale préexistante (même `app_id`) n'est
  **jamais** écrasée (`firstOrCreate` no-op) ; réconciliation rejouable sans doublon.

> Pré-requis VM : un contrat amont `active`, un poste enrôlé `PC1`, son token agent.
> `APPID=firefox` ne doit **pas** exister en `Application` locale (sinon AC3 = no-op).

### Scénario 16.1 — Matérialisation depuis la source (AC1) puis honneur (AC2)

1. Dans le contrat actif, poser (a) un ordre `applications`/`locked`/`instance`
   `key=firefox` ET (b) une entrée catalogue `app_key=firefox` avec
   `source_xml_url=https://depot…/firefox.xml` + `source_xml_sha=…`.
2. Vérifier l'absence locale : `php artisan tinker --execute "echo \App\Models\Application::where('app_id','firefox')->exists();"` ⇒ vide.
3. Déclencher : ré-ingérer le contrat (le listener s'exécute) **ou**
   `php artisan controlhub:provision-ordered-apps`.
4. ⇒ une `Application{app_id:firefox}` existe (status `Available`, `xml_url` peuplé).
5. `curl -s -H "Authorization: Bearer <token>" https://<se5>/api/v1/agent/state | jq '.machine[] | select(.type=="applications") | .payload.app_id'`
   ⇒ `firefox` **figure** désormais dans `machine.applications` (plus de skip+warn).

### Scénario 16.2 — Ordre sans source = dégradé gracieux (AC6)

1. Poser un ordre `applications` `key=ghost` **sans** entrée catalogue (ou catalogue
   **sans** `source_xml_url`).
2. `php artisan controlhub:provision-ordered-apps` ⇒ pas d'exception ; compteur
   `Ignorées : 1` ; log `agent.applications.provision_skipped`.
3. `ghost` reste **non** matérialisé ; les autres ordres du lot **sont** matérialisés.

### Scénario 16.3 — Idempotence / non-écrasement (AC3)

1. `firefox` existe localement (`status=installed`, métadonnées custom).
2. `php artisan controlhub:provision-ordered-apps` ⇒ `Déjà présentes : 1`,
   `Matérialisées : 0` ; la ligne `firefox` est **inchangée** (status/métadonnées).
3. Rejouer la commande ⇒ aucun doublon, aucun effet.

### Scénario 16.4 — Standalone (AC4)

Aucun `ControlHubContract` actif ⇒ `controlhub:provision-ordered-apps` affiche
« Aucun contrat amont actif », exit 0, **rien écrit**. `DepotSyncService` **jamais**
appelé (Option B : aucune synchro de dépôt déclenchée par ce mécanisme).

```bash
# Hôte (php8.4 + pdo_sqlite)
CACHE_DRIVER=array php artisan test tests/Feature/ControlHub/UpstreamOrderProvisioningTest.php   # 14/14
# AC5 (ingestion source idempotente) — dans la suite d'ingestion 28.2
CACHE_DRIVER=array php artisan test tests/Feature/ControlHub/ControlHubContractIngestionTest.php # 14/14
# Non-régression desired-state + AppStore + dépôt + domaine ControlHub + contrat figé
CACHE_DRIVER=array php artisan test --filter 'AppStore|Depot|ControlHubContract|Provision|Agent|ContractV1|StateCompiler'  # 674/674 (22 skip env)

# R3 : aucun identifiant/message « central » (uniquement commentaires garde-fou)
grep -rin central app/Services/ControlHub/OrderedApplicationProvisioner.php \
  app/Listeners/ProvisionOrderedApplications.php \
  app/Console/Commands/ProvisionOrderedApplications.php
```

**Attendu** : l'app ordonnée absente est matérialisée (status `Available`) depuis sa
source ; l'ordre figure ensuite dans le désiré du poste ; une app locale préexistante
n'est jamais écrasée ; ordre sans source = log sans crash ; sans contrat actif, rien
écrit et `DepotSyncService` jamais appelé ; contrat agent figé intact ; aucun « central ».

## Checklist rapide Story 31.3

- [ ] `CACHE_DRIVER=array php artisan test tests/Feature/ControlHub/UpstreamOrderProvisioningTest.php` → 17/17 verts (14 + 3 post-review)
- [ ] AC5 ingestion source : `ControlHubContractIngestionTest` → vert (persistance + no-op + sans-source accepté + ''→null)
- [ ] Non-régression `--filter 'AppStore|Depot|ControlHubContract|Provision|ContractV1|StateCompiler|ApplicationsState'` → verte (195)
- [ ] AC1/AC2 : app ordonnée absente matérialisée (status Available) puis présente dans `machine.applications` (Scénario 16.1)
- [ ] AC6 : ordre sans source = skip + log, sans crash, autres apps quand même matérialisées (Scénario 16.2)
- [ ] AC3 : `Application` locale préexistante jamais écrasée, réconciliation rejouable sans doublon (Scénario 16.3)
- [ ] AC4 : standalone = rien écrit, `DepotSyncService` jamais appelé (Scénario 16.4)
- [ ] Contrat agent figé : `ContractV1Test` vert, `FROZEN_STATE_HASH` inchangé, golden non modifié
- [ ] R3 : aucun identifiant/message « central » dans les fichiers livrés
- [ ] Post-correctifs review : unicité PG de l'app matérialisée prouvée (16.5)

## Post-correctifs & non-régressions — Story 31.3 (review 2026-06-29)

| Incident / angle | Origine | Couverture |
|---|---|---|
| Doublon concurrent d'`app_id` matérialisé (PG : NULL distincts dans `unique(depot_id, app_id)` avec `depot_id=null`) | Review #A (manqué sonnet, relevé opus) | Index unique partiel `applications_materialized_app_id_unique` + Scénario 16.5 |
| Résilience par-app non testée (exception pendant matérialisation) | Review #2 🔴 | Tests `an_app_that_throws…` + `artisan_command_returns_failure…` |
| `DepotSyncService` jamais appelé en chemin réel | Review #1 | Spy ajouté au test de matérialisation effective |

### Scénario 16.5 — Unicité PG d'une app matérialisée (anti-doublon concurrent)

> ⚠️ **À jouer sur PostgreSQL réel** (le piège est invisible en SQLite ET non couvert par un test unitaire : il dépend de la sémantique NULL des index PG + de la concurrence).

**Pré-requis** : instance avec contrat amont actif portant un ordre `applications` (`app_idX`) + entrée catalogue avec `source_xml_url`, `app_idX` absent de `applications`.

1. **Migration appliquée** : `php artisan migrate` → vérifier l'index `applications_materialized_app_id_unique` :
   `psql -c "\d applications"` ⇒ présence de `... UNIQUE INDEX ... (app_id) WHERE (depot_id IS NULL)`.
2. **Matérialisation simple** : `php artisan controlhub:provision-ordered-apps` ⇒ 1 ligne `applications` pour `app_idX` (`depot_id` NULL, status `available`).
3. **Tentative de doublon manuel** (preuve du garde-fou) :
   `psql -c "INSERT INTO applications (app_id, name, status, created_at, updated_at) VALUES ('app_idX','dup','available',now(),now());"`
   ⇒ **erreur attendue** `duplicate key value violates unique constraint "applications_materialized_app_id_unique"`. (Sans le garde-fou, l'insert passait → 2 lignes.)
4. **Idempotence préservée** : rejouer `controlhub:provision-ordered-apps` ⇒ aucune nouvelle ligne, aucune erreur (le provisionneur court-circuite via `exists()` avant l'insert).
5. **App adossée à un dépôt non contrainte** : une `Application` avec `depot_id` renseigné (flux AppStore classique) reste régie par `unique(depot_id, app_id)` — l'index partiel ne s'y applique pas (vérifier qu'un install AppStore normal fonctionne toujours).

## Section 17 — Release des verrous à la rupture du lien (Story 32.1, 2026-06-30)

**Ouvre l'Epic 32 (« Cycle de vie du lien & release »).** À réception d'un **signal
explicite de rupture** du lien de management, SE5 passe le contrat amont actif en
`link_state = severed` de façon **tracée** et **idempotente**. Cela **lève
AUTOMATIQUEMENT** tous les verrous et le bornage catalogue (le refnum retrouve un droit
de modification plein), tout en **conservant** les ajouts locaux ET la **valeur courante
effective** des items qui étaient imposés (FR7 + NFR5).

> **« Preuve + construction ».** La **levée** des verrous / catalogue est ACQUISE
> GRATUITEMENT : dès `severed`, `ControlHubContract::active()` → `null` et TOUS les
> consommateurs court-circuitent (`UpstreamContractSource`, `UpstreamCatalogResolver`,
> `UpstreamLockResolver`, `CapabilityPolicy`, tier `StateMaille::Upstream`). 32.1 ne
> RE-CONSTRUIT AUCUN déverrouillage ; elle construit la **réception du signal** (severed
> n'était posé nulle part), la **conservation de valeur** (matérialisation), l'**audit
> NFR5** et l'émission de `ControlHubContractChanged`.

- **Canaux du signal (Q4)** : commande artisan `controlhub:sever-link` (`--actor`,
  `--reason`) **ET** endpoint authentifié `POST /api/v1/controlhub/sever-link`
  (`controlhub.auth`). Les DEUX partagent le service UNIQUE
  `ControlHubContractSeveranceService`.
- **Idempotence stricte** : un signal en **standalone** (aucun contrat actif) OU sur un
  contrat **déjà `severed`** est un **no-op TOTAL** — aucune matérialisation, aucun
  audit, aucun event, aucune écriture.
- **Conservation de l'état effectif COMPLET, déverrouillé (M1 — correction de cadrage
  2026-06-30)** : à la rupture « l'état du parc reste identique, en retirant les
  verrous » (Henri). On FIGE LOCALEMENT, AVANT de poser `severed`, l'état effectif des
  canaux **réellement imposés** :
  - **Capacités via le VRAI canal `registry`** (PAS le pseudo-canal `capabilities`, sans
    adaptateur amont = canal mort, ancien bug M1) : pour chaque capacité **verrouillée**
    amont (détection par identité de clé registre, iso `UpstreamLockResolver`), on
    **recouvre** la valeur de capacité imposée en INVERSANT la projection
    (`CapabilityProjection.spec`, sens valeur-registre → valeur-capacité, à partir de la
    valeur connue de l'item). **Portée selon la cible (correctif #7, décision Henri
    2026-06-30)** :
      - `target_type = instance` → on FIGE la valeur dans le **DÉFAUT D'INSTANCE**
        `capabilities.default_value` (patron `saveDefault()` des parc-defaults). UNE
        écriture, couvre UNIFORMÉMENT tous les postes (même hors de tout parc), éditable
        sur la **page des défauts**. PAS d'override par parc (l'ancienne portée « tous
        les parcs actifs incl. salles physiques » était over-wide). Idempotent.
      - `target_type = label` → override `capability_assignments` (patron 29.5,
        `insertOrIgnore`) sur chaque parc portant le `controlhub_label`. Inchangé.
    Désormais **local-libre** (éditable / supprimable). Les overrides locaux par parc
    **plus spécifiques** restent intacts et **priment** sur le défaut d'instance
    (`effective = assignment.value ?? default_value`) — AC3. Un `permissive` n'est PAS
    matérialisé (plancher déjà battu par le défaut local).
  - **Apps ordonnées amont → conservées selon la cible (correctif #7)** :
      - `target_type = instance` → on pose `Application.is_parc_default = true` (DÉFAUT
        D'INSTANCE app, couche Broadcast 27.17 : l'app est appliquée par défaut à TOUS
        les postes via `ApplicationsStateProvider`, même hors parc). PAS d'affectation
        par groupe.
      - `target_type = label` → **affectation locale** par parc porteur (pivot
        `application_workstation_group`, via `AppProfileService::addApplicationsToWorkstationGroup`).
    Pour qu'un poste qui ne recevait l'app QUE via l'ordre amont la **CONSERVE**. Les
    lignes `Application` restaient déjà conservées (AC3) ; ici on conserve aussi l'**affectation**.
  - **Preuve = PARITÉ D'ÉTAT** : `StateCompiler::compile()` d'un poste du parc émet la
    MÊME sortie (clé registre imposée / app présente) **avant et après** la rupture.
- **Trace d'origine des apps (Q2)** : `materializeFromSource` pose désormais
  `managed_by_control_hub = true` ; à la rupture le flag est **CONSERVÉ** (marqueur
  d'origine historique, l'enforcement venant de `active()` déjà neutralisé). Tout
  libellé front-facing dérivé est en **français** (« Origine : controlHub »), jamais le
  nom brut de colonne.
- **Audit NFR5 (Q5)** : table dédiée append-only `controlhub_link_audit_logs` (patron
  `CapabilityOverrideAuditLog`) — une ligne par transition `active → severed`
  (`origin`, `actor_label`, `controlhub_contract_id`, `summary` = `items_lifted`
  [locked+permissive uniquement, l'`absent` exclu — correctif #2] / `apps_preserved` /
  `values_materialized` / `applications_assigned` [clé alignée sur `toArray()`,
  correctif #9]). Écrite DANS la transaction de la rupture.

> Pré-requis VM : un contrat amont `active` portant des items `locked` + `permissive`,
> un catalogue applicatif (bornage), un poste enrôlé `PC1` + token agent, au moins un
> override `capability_assignments` local et une app matérialisée
> (`managed_by_control_hub`).

### Scénario 17.1 — Rupture → verrous + bornage levés (AC1/AC2)

1. AVANT : `curl -s -H "Authorization: Bearer <token>" https://<se5>/api/v1/agent/state`
   ⇒ les items imposés (`Upstream`) figurent ; le refnum NE peut PAS réinstaller hors
   catalogue ; une capacité verrouillée est non éditable (badge « Verrouillé »).
2. Déclencher la rupture : `php artisan controlhub:sever-link --actor=refnum01 --reason="fin de contrat"`
   **ou** `curl -X POST -H "Authorization: Bearer <clé controlHub>" https://<se5>/api/v1/controlhub/sever-link`.
3. ⇒ `link_state = severed` (`php artisan tinker --execute "echo \App\Models\ControlHubContract::query()->value('link_state');"` ⇒ `severed`).
4. APRÈS : `GET /api/v1/agent/state` ⇒ **plus aucun** item `Upstream` ; le refnum
   réinstalle **hors catalogue** (bornage tombé) ; la capacité redevient **éditable**
   (plus de badge « Verrouillé »).

### Scénario 17.2 — PARITÉ D'ÉTAT : registry locked + app ordonnée conservés (AC3/AC4)

**Preuve par parité** : la sortie compilée (`GET /api/v1/agent/state` d'un poste enrôlé,
ou `StateCompiler::compile()`) doit être **identique avant/après** la rupture pour les
canaux concernés — seuls les verrous tombent.

1. AVANT la rupture, sur un poste `PC1` d'un parc cible, noter dans `GET .../agent/state` :
   (a) la **valeur registre imposée** d'une capacité **verrouillée `registry`** (ex. clé
   `HKLM\Software\Se5\Kiosk = 1`, alors que le défaut local serait `0`) ; (b) la présence
   d'une **app ordonnée amont** (ex. `firefox`) que `PC1` ne reçoit QUE via l'ordre amont
   (aucune affectation locale). Noter aussi un override **local** distinct préexistant.
2. Rompre le lien (Scénario 17.1).
3. ⇒ **Capacité (cible `instance`)** : `capabilities.default_value` porte désormais la
   valeur de capacité **recouvrée** (ex. `on`, inversée depuis la valeur registre `1`),
   visible/éditable sur la **page des défauts**. `GET .../agent/state` réémet **toujours**
   `HKLM\Software\Se5\Kiosk = 1` (parité) — y compris pour un poste **hors de tout parc**.
   (Cible `label` : la valeur est dans `capability_assignments` des parcs porteurs.) La
   capacité est désormais éditable.
4. ⇒ **App (cible `instance`)** : `Application.is_parc_default = true` (défaut d'instance
   Broadcast) ; `GET .../agent/state` contient **toujours** `firefox` (parité), même ordre
   amont levé. (Cible `label` : affectation dans `application_workstation_group` des parcs
   porteurs.)
5. ⇒ l'override **local préexistant** par parc est **inchangé** (jamais écrasé) et **prime**
   sur le défaut d'instance : un poste de ce parc garde sa valeur locale.
6. ⇒ les `Application` `managed_by_control_hub` survivent : `php artisan tinker --execute
   "echo \App\Models\Application::where('managed_by_control_hub',true)->count();"` ⇒ inchangé.

### Scénario 17.3 — Idempotence du re-signal (AC1/AC6)

1. Rejouer `php artisan controlhub:sever-link` (ou re-POST l'endpoint).
2. ⇒ message « Aucun contrat amont actif — rupture ignorée » (commande) / `severed:false`
   (endpoint), **rien écrit**.
3. ⇒ `controlhub_link_audit_logs` contient **toujours une seule** ligne pour la
   transition (aucun nouvel audit, aucun nouvel event).

### Scénario 17.4 — Audit consigné (AC6)

`php artisan tinker --execute "print_r(\App\Models\ControlHubLinkAuditLog::latest('id')->first()->toArray());"`
⇒ une ligne `from_state=active`, `to_state=severed`, `origin` (`command`|`api`),
`actor_label`, `controlhub_contract_id`, `summary` (`items_lifted` / `apps_preserved` /
`values_materialized` / `applications_assigned`).

### Scénario 17.5 — Standalone strictement no-op (AC5/NFR3)

Sur une instance **sans contrat actif** : `php artisan controlhub:sever-link` ⇒ « Aucun
contrat amont actif », exit 0, **rien écrit** ; `GET /api/v1/agent/state` **byte-identique**
(golden `state.v1.json` / `FROZEN_STATE_HASH` PHP & Go inchangés). Le contrat agent figé
n'est **pas** touché.

```bash
# Hôte (php8.4 + pdo_sqlite)
CACHE_DRIVER=array php artisan test tests/Feature/ControlHub/ContractSeveranceTest.php          # 15/15
CACHE_DRIVER=array php artisan test tests/Feature/ControlHub/ContractSeveranceChannelsTest.php  # 4/4
# Levée prouvée par construction (chokepoint active() → null) + non-régression domaine
CACHE_DRIVER=array php artisan test --filter 'ControlHubContract|UpstreamCatalog|UpstreamContract|UpstreamLock|Capability|Provision|ContractV1|StateCompiler'
# Contrat agent figé : ContractV1Test vert, FROZEN_STATE_HASH inchangé, golden non modifié

# R3 : aucun identifiant/message « central » (uniquement commentaires garde-fou)
grep -rin central app/Services/ControlHub/ControlHubContractSeveranceService.php \
  app/Models/ControlHubLinkAuditLog.php \
  app/Console/Commands/SeverControlHubLink.php \
  app/Http/Controllers/Api/v1/ControlHub/LinkSeveranceController.php
```

**Attendu** : la rupture passe le contrat à `severed`, lève verrous + bornage + refus de
modif (via `active()` → null) ; conserve les supports locaux et matérialise la valeur
effective ; trace **une** ligne d'audit ; re-signal et standalone = no-op total ; contrat
agent figé intact ; aucun « central ».

## Checklist rapide Story 32.1

- [ ] `CACHE_DRIVER=array php artisan test tests/Feature/ControlHub/ContractSeveranceTest.php` → 13/13 verts
- [ ] `CACHE_DRIVER=array php artisan test tests/Feature/ControlHub/ContractSeveranceChannelsTest.php` → 4/4 verts (commande + endpoint)
- [ ] AC1/AC2 : rupture → `severed` + verrous/bornage/refus levés (Scénario 17.1) ; `ControlHubContractChanged` émis
- [ ] AC3 : override local + app `managed_by_control_hub` conservés (Scénario 17.2)
- [ ] AC4 : PARITÉ d'état — clé registre imposée + app ordonnée identiques avant/après rupture (Scénario 17.2)
- [ ] AC5/NFR3 : standalone = no-op total, golden/`FROZEN_STATE_HASH` inchangés (Scénario 17.5)
- [ ] AC6 : 1 ligne d'audit par transition, 0 sur re-signal (Scénarios 17.3/17.4)
- [ ] Idempotence : re-signal sur contrat déjà `severed` = no-op (Scénario 17.3)
- [ ] Contrat agent figé : `ContractV1Test` vert, `FROZEN_STATE_HASH` inchangé, golden + `agent/**` non modifiés
- [ ] R3 : aucun identifiant/message « central » dans les fichiers livrés
- [ ] Migration additive `controlhub_link_audit_logs` (`migrate:status` avant e2e VM)

---

## Section 18 — Indisponibilité amont et trace du lien (Story 32.2, 2026-06-30)

**Clôt l'Epic 32 (« Cycle de vie du lien & release »).** Distingue **panne transitoire**
(absence de MAJ reçue = silence) de **rupture explicite** (signal `severed`, Story 32.1) —
le PRD §5.3 exige que **l'indisponibilité seule ne libère JAMAIS les verrous**. Prouve aussi
que la **couverture NFR5 du cycle de vie est complète par construction** (une seule
transition d'état = `active → severed`, déjà tracée par 32.1).

> **Story PREUVE DOMINANTE (Q1=A).** La non-libération et la couverture NFR5 sont toutes
> deux ACQUISES GRATUITEMENT — aucune construction. Livrables : tests de preuve
> (`UpstreamUnavailabilityTest`) + Section 18 QA. Aucune nouvelle migration, aucune nouvelle
> colonne, aucun nouvel état d'enum.

> **⚠️ ANTI-PATTERN CENTRAL (le seul vrai risque de cette story).** Ne **JAMAIS** coupler
> `ControlHubContract::active()` à `received_at` / TTL / staleness. Le faire libérerait les
> verrous sur une simple panne, violant le PRD §5.3. Les tests de la Section 18 sont des
> **GARDE-FOUS ANTI-RÉGRESSION** : si ce couplage était introduit un jour, ils échoueraient.

### Prérequis / contexte

- Story 32.1 déployée (`controlhub_link_audit_logs` migrée, `ControlHubContractSeveranceService` actif).
- Pas de nouvelle migration pour 32.2 (Q1=A — aucun état persisté de fraîcheur).

### Concepts clés (à avoir en tête)

| Concept | Description |
|---------|-------------|
| **Panne / indisponibilité** | Absence de MAJ reçue du contrat amont. Se traduit par un `received_at` qui vieillit. **Non matérialisée** : aucune écriture, aucun état SQL, aucun audit. |
| **Rupture explicite** | Signal `severed` reçu via commande ou endpoint (32.1). La SEULE chose qui libère les verrous. |
| **`active()`** | Filtre `link_state = active` UNIQUEMENT. Ignore `received_at`. Un contrat reste actif jusqu'à la rupture explicite, jamais jusqu'à un TTL. |
| **NFR5** | L'enum `{Active, Severed}` n'a que 2 états → 1 seule transition → 32.1 la trace → couverture complète. |

### Scénario 18.1 — Panne amont (silence) : verrous maintenus, aucun audit

**But** : prouver qu'une interruption de la communication controlHub (réseau coupé, serveur
arrêté, etc.) ne libère PAS les verrous (le dernier contrat reste en vigueur).

**Prérequis** : une instance SE5 avec un contrat amont **actif** (items `locked`), un parc
borné au catalogue, une capacité verrouillée.

**Procédure** (en l'absence de controlHub émetteur branché, utiliser `artisan tinker`) :

1. Vérifier l'état avant la panne :
   ```bash
   php artisan tinker --execute "
     \$c = \App\Models\ControlHubContract::active();
     echo 'link_state=' . \$c->link_state->value . ' received_at=' . \$c->received_at;
   "
   # Attendu : link_state=active, received_at = date récente
   ```

2. Simuler la panne : ne rien faire pendant N heures/jours. **Aucune** commande
   `controlhub:sever-link` n'est lancée.

3. Vérifier l'état après le silence :
   ```bash
   php artisan tinker --execute "
     \$c = \App\Models\ControlHubContract::active();
     echo 'link_state=' . (\$c ? \$c->link_state->value : 'null') . ' received_at=' . (\$c ? \$c->received_at : 'N/A');
   "
   # Attendu : link_state=active (INCHANGÉ), received_at = même date qu'avant
   ```

4. Vérifier que les verrous sont **toujours en vigueur** :
   ```bash
   php artisan tinker --execute "print_r(\App\Models\ControlHubLinkAuditLog::count());"
   # Attendu : 0 (aucun audit produit pendant la panne)
   ```

5. Vérifier que `GET /api/v1/agent/state` retourne toujours le même état compilé
   (capacités verrouillées = valeur imposée toujours servie). **Le contrat agent est figé.**

**Attendu** : verrous maintenus, `active()` non-null, bornage catalogue actif, 0 ligne d'audit.

### Scénario 18.2 — Comparaison panne vs rupture explicite

**But** : prouver que seule la rupture explicite (32.1) libère, pas la panne.

**Procédure** :

1. Avec un contrat actif, simuler une longue panne (attendre ou utiliser `tinker`).
2. Constater que rien n'a changé (voir Scénario 18.1).
3. **Lancer la rupture** :
   ```bash
   php artisan controlhub:sever-link --actor="maintenance-2026-06-30"
   ```
4. Vérifier :
   ```bash
   php artisan tinker --execute "
     echo 'active=' . (\App\Models\ControlHubContract::active() ? 'oui' : 'null') . PHP_EOL;
     echo 'audit count=' . \App\Models\ControlHubLinkAuditLog::count() . PHP_EOL;
   "
   # Attendu : active=null, audit count=1
   ```

**Attendu** : SEULE la rupture explicite produit l'état `severed` + 1 audit. La panne
précédente n'a produit aucune trace.

### Scénario 18.3 — Reprise après silence (NFR4)

**But** : prouver qu'une reprise de communication après une longue panne n'a pas d'effet
de bord (pas de traitement spécial « sortie d'indisponibilité »).

**Procédure** (depuis controlHub réel ou `artisan tinker` qui simule une ingestion) :

1. Simuler une reprise avec le **même payload** qu'avant la panne :
   - `ControlHubContractIngestionService::ingest($payload)` → doit retourner `mutated=false`
   - `received_at` **inchangé** (no-op NFR4)
   - Aucun `ControlHubContractChanged` dispatché

2. Simuler une reprise avec un **payload différent** (contrat mis à jour) :
   - `ingest($newPayload)` → `mutated=true`
   - `received_at` rafraîchi
   - `ControlHubContractChanged` dispatché 1×

**Attendu** : reprise transparente sans effet de bord. Pas d'état « indisponible » à quitter.

### Scénario 18.4 — Enum des états du lien (NFR5 couverture)

**But** : prouver que la couverture NFR5 du cycle de vie est complète.

```bash
php artisan tinker --execute "
  \$cases = \App\Enums\ControlHubLinkState::cases();
  foreach (\$cases as \$c) { echo \$c->name . '=' . \$c->value . PHP_EOL; }
"
# Attendu :
# Active=active
# Severed=severed
# (exactement 2 cas — aucun état 'stale'/'unavailable')
```

**Analyse** : l'enum n'a que 2 états → 1 seule transition possible (`active → severed`) →
32.1 la trace → NFR5 satisfait par construction. L'indisponibilité n'est PAS une transition
d'état du lien → jamais auditée → pas d'audit manquant.

### Vérifications rapides HÔTE (php8.4 + pdo_sqlite)

```bash
# Suite PREUVE 32.2 (13 tests, 53 assertions)
CACHE_DRIVER=array php artisan test tests/Feature/ControlHub/UpstreamUnavailabilityTest.php

# Non-régression domaine (inclut 32.1, 32.2, catalog, lock, policy…)
CACHE_DRIVER=array php artisan test --filter \
  'ControlHubContract|ContractSeverance|UpstreamCatalog|UpstreamContract|UpstreamLock|CapabilityPolicy|UpstreamUnavailability'

# Contrat agent figé : golden / FROZEN_STATE_HASH / ContractV1 intacts
CACHE_DRIVER=array php artisan test --filter 'ContractV1|StateCompiler|Capability'

# R3 : aucun « central » dans les fichiers livrés par 32.2
grep -rin central \
  tests/Feature/ControlHub/UpstreamUnavailabilityTest.php \
  docs/qa/domains/controlhub-contract.md
```

## Checklist rapide Story 32.2 (clôt l'Epic 32)

- [ ] `CACHE_DRIVER=array php artisan test tests/Feature/ControlHub/UpstreamUnavailabilityTest.php` → 13/13 verts (53 assertions)
- [ ] AC1 : `active()` non-null avec `received_at` nul/ancien/très ancien (Scénario 18.1) — GARDE-FOU anti-couplage received_at
- [ ] AC2(a) : panne = 0 écriture + 0 audit + 0 event + `received_at` inchangé (Scénario 18.1)
- [ ] AC2(b) : seule la rupture explicite 32.1 libère verrous+bornage (Scénario 18.2)
- [ ] AC3/Q1=A : aucune API de fraîcheur introduite ; `active()` indépendant de TTL/staleness
- [ ] AC4/NFR5 : enum 2 états → 1 transition → 32.1 trace → couverture complète (Scénario 18.4)
- [ ] AC5/NFR4 : reprise identique = no-op ; reprise différente = refresh + event (Scénario 18.3)
- [ ] AC6/NFR3 : standalone unchanged (0 write, 0 audit, 0 query items, bornage false)
- [ ] Contrat agent figé : `ContractV1Test` vert, `FROZEN_STATE_HASH` / golden / `agent/**` non modifiés
- [ ] R3 : aucun identifiant/message « central » dans les fichiers livrés par 32.2
- [ ] Non-régression 32.1 : `ContractSeveranceTest` + `ContractSeveranceChannelsTest` toujours verts
- [ ] VM/lab (différé) : e2e signal real controlHub → panne simulée → vérif verrous maintenus → rupture → libération ; `migrate:status` avant e2e VM

## Section 19 — Schéma d'échange versionné (Story 33.1, 2026-06-30)

**Ouvre l'Epic 33 (« Contrat de données d'intégration controlHub ↔ SE5 »).** Le payload du
contrat amont déclare désormais une **version de schéma d'échange** (`schema_version`, chaîne
semver, racine du payload). L'ingestion **négocie** la version
(`ControlHubContractSchema::negotiate()`), l'**enregistre** sur le contrat actif (colonne
`controlhub_contracts.schema_version`) et l'expose dans le DTO de résultat
(`ContractIngestionResult::$schemaVersion`). Le format est figé dans l'**artefact partagé**
`_bmad-output/planning-artifacts/schema-echange-controlhub-se5.md` (source unique, R2).

> **Partie heureuse uniquement.** Un payload **conforme** (version supportée) ou **sans version**
> (défaut = version courante, rétro-compat 28.2) est **accepté**. Le **rejet gracieux** d'une
> version incompatible est la **Story 33.2** (seam `negotiate()` posé, chemin de rejet non livré).
> Le versionnement est **serveur-only**, invisible de l'agent (à ne pas confondre avec `ContractV1`
> du contrat agent).

- **Version courante** : `1.0` (`ControlHubContractSchema::CURRENT_VERSION`). Politique 33.1 :
  égalité stricte ; compat sur le MAJOR documentée mais ouverte à 33.2.
- **NFR4 (cœur du risque)** : enregistrer la version NE transforme PAS une réception identique en
  mutation. Même version + même contenu = no-op total (aucune écriture, `schema_version`/
  `received_at` inchangés, aucun event). Changement de version supportée = mutation (event 1×).
- **NFR3** : sans contrat reçu, comportement SE5 inchangé. L'ingestion reste le seul écrivain.
- **NFR7** : migration **additive** (`schema_version` nullable), portable PG + SQLite, **aucun**
  `CHECK` SQL (domaine de version validé en PHP).

### Scénario 19.1 — Tests HÔTE (php8.4 + sqlite, hors VM)

```bash
# Suite 33.1 (8 tests) — conforme/absent/no-op/changement de version/R3
CACHE_DRIVER=array DB_CONNECTION=sqlite vendor/bin/phpunit --filter ControlHubContractSchemaVersion

# Non-régression : ingestion 28.x + contrat agent figé
CACHE_DRIVER=array DB_CONNECTION=sqlite vendor/bin/phpunit \
  --filter 'ControlHubContractIngestion|ControlHubContract|ContractV1|StateCompiler'

# R3 : aucun identifiant « central » dans les fichiers livrés par 33.1
grep -rin central \
  app/Services/ControlHub/ControlHubContractSchema.php \
  database/migrations/2026_06_30_120000_add_schema_version_to_controlhub_contracts.php
```

### Scénario 19.2 — VM (différé, hors dev-cycle) : migration + lecture de la version

> ⚠️ Le dev-cycle migre **SQLite uniquement**. La colonne `schema_version` n'est **pas** présumée
> présente côté VM tant que `migrate` n'a pas été joué. [mémoire `vm_migrations_not_auto_applied`]

```bash
ssh -i ~/.ssh/id_se4fs_vm root@192.168.122.50
cd /var/www/sambaedu-reload

# 1. La migration additive apparaît et s'applique
php artisan migrate:status | grep add_schema_version_to_controlhub_contracts
php artisan migrate            # ajoute controlhub_contracts.schema_version (nullable)

# 2. Un payload AVEC version → version lisible en base
php artisan tinker --execute="
  app(\App\Services\ControlHub\ControlHubContractIngestionService::class)
    ->ingest(['schema_version' => '1.0', 'items' => [], 'labels' => [], 'imposed_groups' => [], 'catalog_apps' => []]);
  echo \App\Models\ControlHubContract::active()->schema_version;  // attendu : 1.0
"

# 3. Un payload SANS version → défaut = version courante enregistrée
php artisan tinker --execute="
  app(\App\Services\ControlHub\ControlHubContractIngestionService::class)
    ->ingest(['items' => [], 'labels' => [], 'imposed_groups' => [], 'catalog_apps' => []]);
  echo \App\Models\ControlHubContract::active()->schema_version;  // attendu : 1.0 (CURRENT_VERSION)
"
```

## Checklist rapide Story 33.1 (ouvre l'Epic 33)

- [ ] `CACHE_DRIVER=array DB_CONNECTION=sqlite vendor/bin/phpunit --filter ControlHubContractSchemaVersion` → 8/8 verts
- [ ] AC1/2 : version conforme `1.0` → acceptée, lisible sur le modèle ET le DTO (Scénario 19.1)
- [ ] AC3 : payload sans version → accepté, version enregistrée = `CURRENT_VERSION` ; tests 28.2 verts
- [ ] AC4/NFR4 : réception identique (même version) = no-op (mutated=false, timestamps + version inchangés, aucun event)
- [ ] AC5 : changement de version supportée = mutation (event 1×) — conditionnel ≥ 2 versions ; sinon couvert par construction
- [ ] AC6/R2 : artefact partagé `schema-echange-controlhub-se5.md` créé + ref croisée prd §9 ; handoff controlHub §7 à pointer (autre BMAD)
- [ ] AC7d/R3 : aucun identifiant/colonne/message livré ne contient « central »
- [ ] Contrat agent figé : `ContractV1` / `StateCompiler` / golden / `FROZEN_STATE_HASH` / `agent/**` non touchés
- [ ] VM (différé) : `migrate` ajoute `schema_version` + payload avec/sans version accepté + version lisible en base (Scénario 19.2)

## Section 20 — Rejet gracieux d'une version incompatible (Story 33.2, 2026-06-30)

**Clôt l'Epic 33.** La négociation de version devient **stricte** : un payload du contrat amont
déclarant une `schema_version` **non supportée** (chaîne non vide ∉ `SUPPORTED_VERSIONS`, à ce jour
`1.0` seul) est **rejeté à l'ingestion**. Le repli tolérant de 33.1 (version inconnue → repli sur
la version courante + `warning`) est **remplacé** par une exception **dédiée**
`App\Exceptions\ControlHub\UnsupportedSchemaVersionException`, levée par
`ControlHubContractSchema::negotiate()` et **propagée telle quelle** par
`ControlHubContractIngestionService::ingest()`.

> **Seule la branche « version DÉCLARÉE non supportée » bascule au rejet.** Le chemin heureux 33.1
> est **strictement inchangé** : version **absente** (`null`/`''`) → défaut `CURRENT_VERSION`
> (rétro-compat 28.2) ; version **supportée** → acceptée. Une version absente n'est JAMAIS un motif
> de rejet — c'est le piège de régression n°1 (basculer une réception légitime en rejet).

- **Validation PURE pré-transaction** : `negotiate()` est appelée AVANT `DB::transaction()` ⇒ la
  levée garantit **zéro écriture** (aucun contrat/enfant créé/modifié/supprimé), **aucun** event
  `ControlHubContractChanged`, état d'un contrat pré-existant **strictement inchangé** (rollback
  total trivial — même patron qu'`InvalidUpstreamContractException`).
- **Type DÉDIÉ (AC #5)** : `UnsupportedSchemaVersionException` (rejet de **VERSION**) est **distinct**
  d'`InvalidUpstreamContractException` (rejet de **CONTENU** : enum/cohérence/intégrité). Aucune
  des deux n'étend l'autre. Un appelant futur peut différencier les deux causes.
- **Trace (AC #3)** : message « reçue ‹X› vs supportées ‹…› » + log structuré `warning`
  `{declared, supported}`. **Aucune** persistance d'audit (un rejet ne mute pas l'état).
- **Politique de compat (Q1)** : **égalité stricte** (`1.0` seul) ; la compat MAJOR (`1.x`) est
  **différée** tant qu'une seule version existe (anti sur-engineering).
- **Service-only (Q4)** : `ingest()` n'est appelé par **aucun** contrôleur HTTP → aucune route,
  aucun mapping `4xx` ajouté. Le jour où un transport est câblé, il traduira l'exception.
- **AUCUNE migration** (rejet = logique pure) ; **contrat agent figé intact** (versionnement
  d'échange serveur-only ; `StateCompiler`/`ContractV1`/golden/`FROZEN_STATE_HASH`/`agent/**` non
  touchés ; pas de bump `agent/shared/version.go`).

### Scénario 20.1 — Tests HÔTE (php8.4 + sqlite, hors VM)

```bash
# Suite 33.2 (8 tests) — rejet + zéro écriture + état inchangé + trace + type distinct + R3
CACHE_DRIVER=array DB_CONNECTION=sqlite vendor/bin/phpunit --filter UnsupportedSchemaVersionRejection

# Non-régression OBLIGATOIRE (piège central : aucune réception légitime ne doit basculer en rejet)
CACHE_DRIVER=array DB_CONNECTION=sqlite vendor/bin/phpunit \
  --filter 'ControlHubContractSchemaVersion|ControlHubContractIngestion|ControlHubContract|ContractV1|StateCompiler'

# R3 : aucun identifiant « central » dans les fichiers livrés par 33.2
grep -rin central \
  app/Exceptions/ControlHub/UnsupportedSchemaVersionException.php \
  app/Services/ControlHub/ControlHubContractSchema.php
```

### Scénario 20.2 — VM (différé, hors dev-cycle) : comportement de rejet

> ⚠️ **Aucune migration** dans cette story : rien à `migrate`. Seul le **comportement de rejet** est
> à confirmer. La colonne `schema_version` (33.1) est présumée déjà migrée. [mémoire `vm_migrations_not_auto_applied`]

```bash
ssh -i ~/.ssh/id_se4fs_vm root@192.168.122.50
cd /var/www/sambaedu-reload

# 1. Un payload portant une version NON supportée → exception (rien écrit)
php artisan tinker --execute="
  try {
    app(\App\Services\ControlHub\ControlHubContractIngestionService::class)
      ->ingest(['schema_version' => '2.0', 'items' => [], 'labels' => [], 'imposed_groups' => [], 'catalog_apps' => []]);
    echo 'KO — aucune exception';
  } catch (\App\Exceptions\ControlHub\UnsupportedSchemaVersionException \$e) {
    echo 'OK rejet : ' . \$e->getMessage();  // « reçue « 2.0 » ; supportées : 1.0 »
  }
"

# 2. Vérifier que la base n'a PAS bougé (greenfield : aucun contrat) / l'état pré-existant est intact
php artisan tinker --execute="echo \App\Models\ControlHubContract::count();"

# 3. Non-régression chemin heureux : une version supportée reste acceptée
php artisan tinker --execute="
  app(\App\Services\ControlHub\ControlHubContractIngestionService::class)
    ->ingest(['schema_version' => '1.0', 'items' => [], 'labels' => [], 'imposed_groups' => [], 'catalog_apps' => []]);
  echo \App\Models\ControlHubContract::active()->schema_version;  // attendu : 1.0
"
```

## Checklist rapide Story 33.2 (clôt l'Epic 33)

- [ ] `CACHE_DRIVER=array DB_CONNECTION=sqlite vendor/bin/phpunit --filter UnsupportedSchemaVersionRejection` → 8/8 verts
- [ ] AC1 : version déclarée non supportée (`2.0`) → `UnsupportedSchemaVersionException` (pas de DTO)
- [ ] AC1/2 : rejet n'écrit RIEN (comptes des 5 tables inchangés, aucun event `ControlHubContractChanged`)
- [ ] AC2 : contrat pré-existant strictement inchangé (`schema_version`/`received_at`/`link_state`/agrégats)
- [ ] AC3 : message « reçue vs supportées » + log structuré `warning` `{declared, supported}`
- [ ] AC4 : chemin heureux 33.1 intact (absente → courante, supportée → acceptée) ; non-régression 33.1/28.2 verte
- [ ] AC5 : `UnsupportedSchemaVersionException` distincte d'`InvalidUpstreamContractException` (ni l'une sous-type de l'autre)
- [ ] AC6/Q1 : égalité stricte (`1.0` seul), compat MAJOR différée ; artefact `schema-echange-controlhub-se5.md` MAJ (rejet livré)
- [ ] AC7c/R3 : aucun identifiant/message livré ne contient « central »
- [ ] AC7d/e : contrat agent figé intact (`ContractV1`/`StateCompiler`/golden/`FROZEN_STATE_HASH`/`agent/**`) ; aucune migration ; aucune route
- [ ] VM (différé) : payload `2.0` → exception + base inchangée ; payload `1.0` accepté (Scénario 20.2)

## Section 21 — Exposition HTTP de l'ingestion, canal ① du lien managé (Story 39.1, 2026-07-04)

**Ouvre l'Epic 39 (alignement de la couture controlHub ↔ SE5).** L'ingestion idempotente
`ControlHubContractIngestionService::ingest()` (Epics 28/33) existait et était testée **sans être
jamais atteignable par HTTP** (gap OPEN-5, bloquant pour tout le reste de l'Epic 39). Cette story
**câble** — n'ajoute **aucune** logique métier neuve : `POST /api/v1/controlhub/contract`
(middleware `controlhub.auth`, nom de route `controlhub.contract.ingest`), contrôleur invocable
**mince** `App\Http\Controllers\Api\v1\ControlHub\ContractIngestionController` (patron
`LinkSeveranceController` 32.1), placé en **fin de fichier** `routes/api.php` (après le groupe
16.12 — fenêtre 1500 chars `ScriptsOsNamespaceTest`).

- **200 nominal** : résumé **complet** `['success' => true] + ContractIngestionResult::toArray()`
  (`contract_created`, `mutated`, `contract_id`, `schema_version`, compteurs `{created,updated,
  deleted}` par agrégat `items`/`labels`/`imposed_groups`/`catalog_apps`).
- **No-op idempotent (NFR-A2)** : 2ᵉ POST identique → `mutated=false`, compteurs à zéro,
  `ControlHubContractChanged` dispatché **une seule fois** au total.
- **422** : `UnsupportedSchemaVersionException` → `error=unsupported_schema_version` ;
  `InvalidUpstreamContractException` → `error=invalid_upstream_contract`. Les deux sont levées en
  validation **pure**, avant la transaction ⇒ **zéro écriture**, état pré-existant inchangé. Toute
  autre exception n'est **pas** interceptée (500 standard).
- **403 (auth avant corps, NFR-A3)** : réponse **existante**, inchangée, du middleware
  `controlhub.auth` (`{"success":false,"error":"Forbidden","message":...}`) — jamais 401. Le
  middleware ne lit que le header `Authorization` ; le contrôleur (seul lecteur du body) n'est
  atteint qu'après authentification réussie. Le token n'apparaît dans **aucun** log.
- **Branche morte `master_api_key` RETIRÉE** de `ControlHubAuth::handle()` (clé absente de
  `config/controlHub.php`, jamais définie ailleurs — code mort par construction). Après retrait,
  `controlhub_key_type` vaut invariablement `'instance'` ; `LinkSeveranceController` (sever-link,
  32.1) continue de fonctionner sans changement (non-régression `ContractSeveranceChannelsTest`
  vérifiée verte — le middleware partagé a changé).
- **Aucun rate-limit dédié** (symétrie avec le sever-link, anti sur-engineering) ; **aucune**
  migration ; **contrat agent figé intact** (`StateCompiler`/`ContractV1`/golden/
  `FROZEN_STATE_HASH`/`agent/**` non touchés, zéro bump `agent/shared/version.go`).

### Scénario 21.1 — Tests HÔTE (php8.4 + sqlite, hors VM)

```bash
# Suite 39.1 (6 tests) — 200 nominal, no-op, 422 version, 422 contenu, 403×2
php artisan test --filter=ContractIngestionEndpointTest

# Non-régression du middleware partagé `controlhub.auth` (sever-link, canal jumeau)
php artisan test --filter=ContractSeveranceChannelsTest

# Non-régression de l'ingestion sous-jacente (28.2/33.1/33.2 — service non réécrit)
php artisan test --filter=ControlHubContractIngestionTest
php artisan test --filter=ControlHubContractSchemaVersionTest
php artisan test --filter=UnsupportedSchemaVersionRejectionTest

# R3 : aucun identifiant « central » dans les fichiers livrés par 39.1
grep -rin central \
  app/Http/Controllers/Api/v1/ControlHub/ContractIngestionController.php \
  app/Http/Middleware/ControlHubAuth.php
```

### Scénario 21.2 — VM (curl authentifié) : 200 / 422 / 403

> ⚠️ **Aucune migration** dans cette story. La route n'est visible sur la VM qu'**après**
> `php artisan route:cache` + `chown www-admin` (cache de routes non synchronisé par inotify —
> mémoire `route_cache_vm_ephemeral_test_routes`). Clé d'instance : `SE4FS_INSTANCE_API_KEY`
> (`.env` de la VM, cf. `config('controlHub.se4fs.instance_api_key')`).

```bash
ssh -i ~/.ssh/id_se4fs_vm root@192.168.122.50
cd /var/www/sambaedu-reload

# 0. S'assurer que la route est bien enregistrée après un sync
php artisan route:cache && chown www-admin bootstrap/cache/routes-v7.php
php artisan route:list --name=controlhub.contract.ingest

KEY=$(php artisan tinker --execute="echo config('controlHub.se4fs.instance_api_key');")

# 1. 200 nominal — payload conforme se5-contract/v1 (4 agrégats)
curl -s -X POST http://127.0.0.1/api/v1/controlhub/contract \
  -H "Authorization: Bearer ${KEY}" -H "Content-Type: application/json" \
  -d '{
        "schema_version": "1.0",
        "items": [{"type":"capabilities","key":"cap_show_ext","value":"on","enforcement_state":"locked","target_type":"instance"}],
        "labels": [], "imposed_groups": [], "catalog_apps": []
      }' | jq
# Attendu : {"success":true,"contract_created":true,"mutated":true,"schema_version":"1.0", ...}

# 2. No-op — rejouer EXACTEMENT le même payload → mutated=false, compteurs à zéro
curl -s -X POST http://127.0.0.1/api/v1/controlhub/contract \
  -H "Authorization: Bearer ${KEY}" -H "Content-Type: application/json" \
  -d '{"schema_version":"1.0","items":[{"type":"capabilities","key":"cap_show_ext","value":"on","enforcement_state":"locked","target_type":"instance"}],"labels":[],"imposed_groups":[],"catalog_apps":[]}' | jq

# 3. 422 — version de schéma non supportée
curl -s -o /dev/null -w '%{http_code}\n' -X POST http://127.0.0.1/api/v1/controlhub/contract \
  -H "Authorization: Bearer ${KEY}" -H "Content-Type: application/json" \
  -d '{"schema_version":"99.0","items":[],"labels":[],"imposed_groups":[],"catalog_apps":[]}'
# Attendu : 422

# 4. 422 — contenu hors domaine (enforcement_state invalide)
curl -s -o /dev/null -w '%{http_code}\n' -X POST http://127.0.0.1/api/v1/controlhub/contract \
  -H "Authorization: Bearer ${KEY}" -H "Content-Type: application/json" \
  -d '{"items":[{"type":"capabilities","key":"cap_x","value":"on","enforcement_state":"bogus","target_type":"instance"}],"labels":[],"imposed_groups":[],"catalog_apps":[]}'
# Attendu : 422

# 5. 403 — sans header Authorization
curl -s -o /dev/null -w '%{http_code}\n' -X POST http://127.0.0.1/api/v1/controlhub/contract \
  -H "Content-Type: application/json" -d '{}'
# Attendu : 403

# 6. 403 — Bearer invalide (≥16 car., différent de la clé d'instance)
curl -s -o /dev/null -w '%{http_code}\n' -X POST http://127.0.0.1/api/v1/controlhub/contract \
  -H "Authorization: Bearer not-the-instance-key-999999" -H "Content-Type: application/json" -d '{}'
# Attendu : 403

# 7. Vérifier qu'aucun log n'expose le token (recherche du Bearer/clé en clair)
grep -i "Bearer ${KEY}" storage/logs/laravel.log || echo "OK — token absent des logs"
```

## Checklist rapide Story 39.1

- [ ] `php artisan test --filter=ContractIngestionEndpointTest` → 6/6 verts
- [ ] `php artisan test --filter=ContractSeveranceChannelsTest` → vert (middleware partagé non régressé)
- [ ] AC1 : route `controlhub.contract.ingest` déclarée après le groupe 16.12 (fenêtre 1500 chars préservée — `ScriptsOsNamespaceTest` vert)
- [ ] AC2 : contrôleur mince, zéro validation/normalisation/transaction locale, délègue à `ingest()`
- [ ] AC3 : 200 = résumé complet (`contract_created`/`mutated`/`contract_id`/`schema_version` + 4 compteurs)
- [ ] AC4/NFR-A2 : 2ᵉ POST identique → `mutated=false`, compteurs à zéro, event dispatché 1× au total
- [ ] AC5 : 422 `unsupported_schema_version` / `invalid_upstream_contract`, zéro écriture dans les deux cas
- [ ] AC6/NFR-A3 : 403 (jamais 401) sans header et avec Bearer invalide ; token absent des logs
- [ ] AC7 : branche `master_api_key` retirée de `ControlHubAuth` ; `controlhub_key_type` toujours `'instance'`
- [ ] AC8/NFR-A4/A5 : `StateCompiler`/`ContractV1`/golden/`FROZEN_STATE_HASH`/`agent/**` non touchés ; R3 vérifié
- [ ] VM (Scénario 21.2, différé) : `route:cache` rejoué après sync, 200/422×2/403×2 confirmés en curl réel

## Section 22 — Émetteur de conformité, canal ③ du lien managé (Story 39.2, 2026-07-04)

Premier ÉMETTEUR SE5 → autorité amont du lien managé : la commande `controlhub:report-compliance`
construit et POST un rapport de conformité **état-intégral** (`se5-contract-compliance/v1`) décrivant
l'état d'application de chaque item du contrat amont. 100 % SORTANT (aucune route HTTP entrante nouvelle).
Le rapport est émis vers `POST {base_url}/api/sambaedu/contract-compliance/{instance_id}` avec le Bearer
`api_token` du handshake.

### Prérequis

- Un contrat amont ACTIF ingéré (Section 21) : `ControlHubContract::active()` non null.
- Une connexion amont valide en BDD (`controlhub_connection` : `is_active=1`, non expirée, `api_token` présent).
- `config('controlHub.compliance.enabled')` = true (défaut). Intervalle : `controlHub.compliance.interval` (défaut 15 min).

### Scénario 22.1 — Émission manuelle et observation côté amont (mock)

```bash
# Sur la VM. Forcer une émission immédiate : purger le watermark de cadence puis lancer la commande.
php artisan tinker --execute="Cache::forget('controlHub_compliance_last_run');"
php artisan controlhub:report-compliance
# Attendu (contrat actif + connexion valide) : "ControlHub Compliance : job d'émission dispatché"
# Puis le worker laravel-queue-general traite ControlHubReportComplianceJob (POST réel).

# Journal : l'émission logue endpoint + nb d'items + http_status, JAMAIS le token.
grep "rapport de conformité émis" storage/logs/laravel.log | tail -1

# Court-circuits (aucune émission) :
#  - sans contrat actif       → "ignoré : aucun contrat amont actif"
#  - sans connexion valide     → "ignoré : aucune connexion amont valide"
#  - intervalle non écoulé      → "ignoré : intervalle non écoulé"
```

### Scénario 22.2 — Test unilatéral direct de l'endpoint amont (curl, sans passer par le job)

Le canal est testable unilatéralement contre l'amont (mock/central exposant l'endpoint). Reconstituer
l'enveloppe à la main pour valider le contrat de l'endpoint récepteur :

```bash
INSTANCE="<instance-id>"          # config('controlHub.se4fs.instance_id')
TOKEN="<api_token de l'instance>" # controlhub_connection.api_token (déchiffré)
BASE="https://<amont>"

curl -s -o /dev/null -w '%{http_code}\n' -X POST "$BASE/api/sambaedu/contract-compliance/$INSTANCE" \
  -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" -H "Accept: application/json" \
  -d '{
    "schema_version": "1.0",
    "instance_id": "'"$INSTANCE"'",
    "link_state": "active",
    "contract_received_at": "2026-07-03T08:15:00Z",
    "reported_at": "2026-07-03T10:30:00Z",
    "items": [
      { "type": "applications", "key": "firefox", "target_type": "label",
        "target_label": "compta", "status": "applied", "detail": null,
        "observed_at": "2026-07-03T10:29:12Z" }
    ]
  }'
# Attendu : 200 { success, data: { created/updated/deleted } } | 200 { ignored:true } (rapport périmé)
```

### Scénario 22.3 — Non-fuite du token dans les logs

```bash
# Après une émission, vérifier qu'aucun log n'expose le Bearer/api_token en clair.
grep -i "$TOKEN" storage/logs/laravel.log && echo "FUITE — à corriger" || echo "OK — token absent des logs"
```

> ⚠ **Point de RATIFICATION R2 (mismatch `registry`/`capabilities`)** : SE5 émet le `type` tel que
> stocké (`'registry'` pour les capacités — seul canal réellement câblé), alors que l'enum
> `ContractItemType` documenté côté amont attend `'capabilities'` (`'registry'` absent du domaine). Un
> amont qui valide STRICTEMENT son enum **rejettera en 422** un item `type='registry'`. Ceci est un
> point à trancher avec le BMAD controlHub AVANT tout e2e réel — PAS un bug de cette story (mirror
> strict volontaire). E2E réel contre un amont émetteur : différé (famille OPEN).

## Checklist rapide Story 39.2

- [ ] `php artisan test --filter=ControlHubComplianceReport` → 16/16 verts
- [ ] Non-régression `UpstreamLockResolver` : `--filter="PermissiveOverrideResolution|UpstreamLock|UpstreamContractResolution"` → vert
- [ ] AC1 : enveloppe conforme (5 clés top-level + 7 clés/item, `target_label` jamais null)
- [ ] AC2/NFR-A1 : aucune émission sans contrat actif / connexion valide / token (ApiClient `shouldNotReceive`)
- [ ] AC3 : items `absent` exclus ; `items:[]` émis comme rapport valide
- [ ] AC4 : `locked`→applied ; `permissive`+registry+instance+override→overridden (detail non vide) ; sinon applied
- [ ] AC4/NFR-A2 : `reported_at` monotone entre deux rapports
- [ ] AC5 : POST via `ControlHubApiClient::callEndpoint()` (Bearer `getToken()`), token absent des logs
- [ ] AC6 : `config/controlHub.php` — ajouts additifs (`endpoints.contract_compliance` + bloc `compliance`), zéro clé existante touchée
- [ ] AC7 : service+job(`tries=3`)+command(`controlhub:report-compliance`) ; entrée Kernel `everyMinute` ; `ControlHubHeartbeatJob` NON reproduit
- [ ] AC8/NFR-A4/A5 : `StateCompiler`/golden/`FROZEN_STATE_HASH`/`agent/**` non touchés, zéro bump version ; R3 vérifié
- [ ] R2 : mismatch `registry`/`capabilities` signalé au BMAD controlHub (ratification), pas de remap silencieux
- [ ] VM (différé) : `Cache::forget` + `controlhub:report-compliance` observés ; endpoint amont curl 200/ignored
---

## Section 23 — Canal ④ : ingestion + pull des binaires amont (Story 39.4, 2026-07-04)

> **Portée.** SE5 ingère `items[].delivery_mode` + `items[].artifact{url,checksum,filename,size}`
> (significatif pour `type ∈ {wallpapers, agent_tools}`) et `catalog_apps[].executable{...}`
> (**persistance seule**, pull différé). Pour un `artifact` complet dont l'asset est absent
> localement, un job asynchrone tire le binaire depuis l'URL signée, **vérifie le sha256 serveur**,
> puis matérialise dans le foyer local. **Idempotence par checksum, jamais par URL.**

### 22.1 Scénarios HÔTE (php8.4 + sqlite)

```bash
# Nouveaux tests 39.4 (ciblés)
php artisan test --filter=UpstreamArtifactIngestion   # persistance additive, no-op, AC5 URL/checksum
php artisan test --filter=ArtifactPullService         # sha256 OK/KO, précédence locale, ré-pull no-op

# Non-régression (invariants 28.2/33/39.1 + golden)
php artisan test --filter=ControlHubContractIngestion
php artisan test --filter=ContractV1Test              # FROZEN_STATE_HASH inchangé
```

### 22.2 Pull sha256 OK (bout en bout, VM après sync — indicatif)

1. Émettre un contrat portant un item `wallpapers` avec `artifact.{url,checksum}` pour un
   `checksum` **absent** de `wallpaper_assets`.
2. Attendre le worker de queue (le job `PullContractArtifactJob` est dispatché **après commit**).
3. Vérifier : un fichier `<checksum>.jpg` apparaît sous `config('wallpapers.library_path')`, une
   ligne `wallpaper_assets` existe pour ce `checksum`, l'item porte `pull_status=downloaded`.
   Pour un `agent_tools` : une ligne `agent_tools` (par `key`) est créée **désactivée** (`enabled=0`),
   `sha256` = checksum vérifié, fichier `sambaedu-tool-<key>-<checksum>.<ext>` sous `agent.tools_path`.

### 22.3 Pull sha256 KO (rejet d'intégrité)

1. Émettre un contrat dont l'`artifact.checksum` **ne correspond pas** au contenu réellement servi
   par l'URL.
2. Vérifier : **aucune** ligne `wallpaper_assets`/`agent_tools` créée, **aucun** fichier matérialisé,
   fichier temporaire supprimé, item en `pull_status=error` + `pull_error` renseigné.
3. NFR-A3 : l'URL signée (secret de signature) n'apparaît **pas** dans `pull_error` ni les logs.

### 22.4 Précédence locale (le pull comble l'absence, ne remplace jamais)

1. Pré-charger un `WallpaperAsset` pour un `checksum` donné (ou un `AgentTool` pour une `key`).
2. Émettre un contrat imposant le même binaire (même checksum / même clé).
3. Vérifier : **aucun appel HTTP** (rien tiré), l'asset local est **conservé intact** (pas de
   remplacement), aucun job de pull dispatché depuis l'ingestion. Comportement à **0 binaire amont
   strictement inchangé**.

### 22.5 Piège d'idempotence (AC5 — le garde-fou critique)

1. Émettre deux fois le même contrat dont **seule l'`artifact.url` diffère** (URL signée régénérée),
   `checksum`/`filename`/`size` identiques.
2. Vérifier : 2ᵉ réception → `mutated=false`, aucun `ControlHubContractChanged`, **aucun nouveau job
   de pull**. (L'URL n'est pas une colonne ⇒ ne peut pas polluer `wasChanged()`.)

### Checklist rapide Story 39.4

- [ ] `php artisan test --filter=UpstreamArtifactIngestion` → vert
- [ ] `php artisan test --filter=ArtifactPullService` → vert
- [ ] AC4 : `delivery_mode`/`artifact.*` persistés ; payload sans artefact byte-identique à l'existant
- [ ] AC5 : ré-ingestion URL≠ / checksum= → `mutated=false`, aucun job (garde-fou)
- [ ] AC6 : `delivery_mode` inconnu accepté, non arbitré
- [ ] AC7 : `catalog_apps.executable` persisté SANS pull (aucun job pour catalog_apps)
- [ ] AC8 : dispatch conditionnel à la précédence locale (absent→pending+job ; présent→aucun job)
- [ ] AC9 : sha256 OK→matérialisé/downloaded ; KO→error, aucune écriture, tmp supprimé ; filename dérivé serveur
- [ ] AC10/NFR-A4 : `StateCompiler`/`ContractV1`/golden/`FROZEN_STATE_HASH`/`agent/**` non touchés ; pas de bump `version.go`
- [ ] VM (différé) : 2 migrations additives à jouer (`migrate`) + `route:cache`/`config:cache` + chown www-admin après sync

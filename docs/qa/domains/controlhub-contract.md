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
| #1 — clé naturelle item ne protège pas le cas `instance` (target_label NULL ⇒ NULL≠NULL, trou NFR4 sur le cas dominant) | 🔴 | 28.1 | Scénario 4.1bis ; correctif = `target_label NOT NULL DEFAULT ''`. **À re-vérifier en 28.2** : la réception répétée d'un contrat à items `instance` ne crée PAS de doublons (idempotence réelle, pas seulement la contrainte). |

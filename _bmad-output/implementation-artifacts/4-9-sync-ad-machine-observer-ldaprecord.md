# Story 4.9 : Sync AD machine via observer Eloquent + LdapRecord modrdn

Status: review

> **Origine :** divergence constatée entre PostgreSQL (`workstations.name`) et Active Directory (`cn`) après un rename de machine déclenché par le menu iPXE. Le code applique le rename côté AD via `samba-tool` mais oublie d'écrire `$ws->name = $role` côté PG (`app/Ipxe/Services/WindowsPostInstallTracker.php:400-473`, `recordRenommeAdRenamed()`). Le pattern existant pour `WorkstationGroup` (observer Eloquent + job AdSync) n'est pas appliqué à `Workstation` : `WorkstationObserver` existe mais n'est ni enregistré dans `AppServiceProvider::boot()` ni équipé des hooks `created/updated/deleting`.
> **Épic :** Epic 4 — Gestion des Machines, WorkstationGroups & AppProfiles SER.
> **Décision technique majeure :** rename AD via `LdapRecord\Models\ActiveDirectory\Computer::rename()` (modrdn LDAP) au lieu de `samba-tool computer rename` (plan B legacy). Validé sur VM `/vm` (Samba 4 + AD `localdev.fr`) sur machine cobaye `CN=28051115,OU=ULIS,OU=computers,DC=localdev,DC=fr` : `objectGUID` ET `netbootGUID` sont strictement préservés, là où `samba-tool computer rename` fait un delete+recreate qui détruit les deux.
> **Dépendance amont :** Epic 4 stories 4.1-4.7 done ; 4.8 en review (scope orthogonal apps customization, **non bloquant**). Pas de dépendance bloquante.

---

## Story

En tant que **développeur SER** (et indirectement responsable d'établissement),
je veux que **toute écriture Eloquent sur `Workstation` (create / rename / delete / changement de status) déclenche automatiquement la synchronisation AD via job asynchrone idempotent**, en miroir exact du pattern déjà en place pour `WorkstationGroup` (`WorkstationGroupObserver` + `WorkstationGroupAdSyncJob`),
afin que PG et AD ne puissent **plus jamais diverger** sur le nom d'une machine, que `objectGUID` / `netbootGUID` soient **préservés** lors des renames (pré-requis PXE / netboot), et que le code applicatif (services iPXE, controllers, jobs, seeders) puisse écrire en toute confiance via Eloquent sans avoir à orchestrer l'AD à la main.

---

## Contexte & Motivation

### État actuel (audit 2026-05-28)

**Pattern de référence — `WorkstationGroup`** (à imiter à l'identique pour `Workstation`) :

- `app/Observers/WorkstationGroupObserver.php` — hooks `created`, `updated`, `deleting` qui dispatchent `WorkstationGroupAdSyncJob::create($id)` / `::rename($id, $old, $new)` / `::delete($name, $adGuid)`. Helper statique `withoutSync(callable)` pour bypasser l'observer dans les seeders/imports.
- `app/Jobs/AdSync/WorkstationGroupAdSyncJob.php` — job typé via constantes `ACTION_CREATE`, `ACTION_RENAME`, `ACTION_DELETE`, `ACTION_STATUS`. Factory methods statiques. `tries = 3`, `backoff = 10`. Handler dispatch sur l'action.
- Enregistrement dans `app/Providers/AppServiceProvider.php:202` : `WorkstationGroup::observe(WorkstationGroupObserver::class);` (juste à côté de `UserGroup::observe(…)` ligne 203, `AppProfile::observe(…)` ligne 204).

**État existant côté `Workstation` :**

- `app/Observers/WorkstationObserver.php` **existe** mais (a) n'est **PAS enregistré** dans `AppServiceProvider::boot()`, (b) ne contient **que** des hooks pivot audit-only (`onGroupAttached/Detached/Synced`) — aucun hook Eloquent `created/updated/deleting`. Le helper `withoutSync(callable)` + flag `$syncEnabled` y est déjà présent (réutilisable tel quel).
- `app/Jobs/AdSync/WorkstationMembershipAdSyncJob.php` existe pour la sync d'appartenance groupe/machine ; pas de job pour les attributs de la machine elle-même.
- `app/Services/Ldap/AdMachineManager.php` (à confirmer en lecture) expose `create($name, $ou, …)` (via samba-tool), `renameComputer($old, $new)` (samba-tool plan B), `registerHardware($name, $uuid)` (pose `netbootGUID`), éventuellement `delete($name)`.

**Call-sites impératifs actuels (à refactorer ou à laisser en double-coverage défensif) :**

1. **`app/Ipxe/Services/WorkstationEnrollmentService.php:191-206`** (branche « renommage » de l'enrollment iPXE) :
   ```php
   $current->name = $sanitized;
   $current->save();
   $adRename = $this->adMachineManager->renameComputer($oldName, $sanitized);  // ← appel manuel AD
   // …
   ? $this->adMachineManager->registerHardware($sanitized, $uuid)              // ← re-pose netbootGUID
   : null;
   ```
   Aujourd'hui, la cohérence PG↔AD est gérée à la main avec deux appels successifs samba-tool. La logique « re-`registerHardware` après rename » existe parce que `samba-tool computer rename` détruit `netbootGUID`. **Après la story, ce besoin disparaît** (LdapRecord modrdn préserve `netbootGUID`).

2. **`app/Ipxe/Services/WindowsPostInstallTracker.php:400-473`** (`recordRenommeAdRenamed()`) :
   ```php
   $renameOk = $adManager->renameComputer((string) ($workstation->name ?? ''), $role);
   // … update status/progress …
   // ❌ JAMAIS : $workstation->name = $role; $workstation->save();
   ```
   C'est ici que se produit la divergence : le rename AD est fait (via samba-tool plan B → perte `netbootGUID` au passage…), mais le PG `workstations.name` n'est jamais écrit. Le menu iPXE relit alors PG à la prochaine itération et trouve l'ancien nom.

**Sync DHCP — ancienne dépendance AD supprimée :**

- La sync DHCP est désormais alimentée par la **table SQL `dhcp_reservations`** via `app/Services/Network/DhcpService.php:281` (sync vers `dhcpd.conf` ou Kea). L'attribut AD `iphostnumber` / `networkaddress` (parfois posé historiquement sur les comptes machines AD) **n'est plus consommé** et **ne doit plus être écrit**. Périmètre : audit dans cette story pour confirmer 0 (ou N) call-sites résiduels et les supprimer (peut être un no-op si déjà clean).

### Décision technique majeure — rename via LdapRecord modrdn

**Test réel exécuté le 2026-05-28 sur VM `/vm` :**

Machine cobaye : `CN=28051115,OU=ULIS,OU=computers,DC=localdev,DC=fr`.

```php
$m = \LdapRecord\Models\ActiveDirectory\Computer::findBy('cn', $oldName);
$m->rename('CN='.$newName);                                  // modrdn LDAP
$m->samaccountname = strtoupper($newName).'$';
$m->dnshostname     = $newName.'.'.$domain;
$m->serviceprincipalname = ['HOST/'.$newName, 'HOST/'.$newName.'.'.$domain];
$m->save();
```

**Résultats observés (`ldapsearch` avant/après) :**

| Attribut | samba-tool computer rename | LdapRecord modrdn (cette story) |
|---|---|---|
| `cn` / `name` / DN | Renommé (delete + recreate) | Renommé (modrdn) |
| `sAMAccountName` | Renommé | Renommé |
| `objectGUID` | **DÉTRUIT** (nouveau GUID) | **PRÉSERVÉ** |
| `netbootGUID` | **DÉTRUIT** (effet de bord delete+recreate) | **PRÉSERVÉ** |
| `dNSHostName` | À reposer manuellement | À reposer manuellement |
| `servicePrincipalName` | À reposer manuellement | À reposer manuellement |

**Effet de bord positif :** les machines legacy dont `sAMAccountName` ≠ `cn` (incohérence historique connue) sont corrigées automatiquement à la première écriture.

**Conséquence directe sur le code applicatif :**

- Plus besoin de re-`registerHardware($name, $uuid)` post-rename pour reposer `netbootGUID`.
- Plus besoin de re-fetch `objectGUID` post-rename pour mettre à jour `Workstation::ad_guid` (il ne change pas).
- Plus de fenêtre de risque "machine PXE-bootable avant rename, non-bootable juste après" qui existait avec samba-tool plan B.

---

## Acceptance Criteria

**AC1 — Pattern observer-driven enregistré et opérationnel**

- Given le code AVANT cette story (observer non enregistré, hooks Eloquent absents)
- When la story est livrée
- Then `Workstation::observe(WorkstationObserver::class)` est ajouté dans `app/Providers/AppServiceProvider.php::boot()` à côté de `WorkstationGroup::observe(WorkstationGroupObserver::class)` (ligne ~202)
- And `WorkstationObserver` expose au minimum les hooks Eloquent `created(Workstation $ws)`, `updated(Workstation $ws)`, `deleting(Workstation $ws)`
- And les hooks pivot audit-only existants (`onGroupAttached/Detached/Synced`) sont **conservés tels quels** OU **supprimés** si confirmé inutilisés (décision à acter par le dev en lisant les appelants — voir Décision D5 ci-dessous, par défaut → conservés)
- And le helper statique `WorkstationObserver::withoutSync(callable)` continue de fonctionner et permet d'écrire en base sans déclencher le job AD (cas seeders, imports CSV story 4.5, sync inverse depuis AD)

**AC2 — Job `WorkstationAdSyncJob` créé en miroir de `WorkstationGroupAdSyncJob`**

- Given le fichier `app/Jobs/AdSync/WorkstationGroupAdSyncJob.php` existe comme référence de pattern
- When la story est livrée
- Then `app/Jobs/AdSync/WorkstationAdSyncJob.php` existe avec :
  - Constantes : `ACTION_CREATE = 'create'`, `ACTION_RENAME = 'rename'`, `ACTION_DELETE = 'delete'`, `ACTION_STATUS = 'status'`
  - Factory methods statiques : `::create(int $workstationId)`, `::rename(int $workstationId, string $oldName, string $newName)`, `::delete(string $name, ?string $adGuid = null)`, `::status(int $workstationId, string|int $newStatus)`
  - `public int $tries = 3;` et `public int $backoff = 10;`
  - `handle()` qui dispatch sur l'action (match / switch) et délègue aux sous-méthodes privées
- And le job est **idempotent** : un rename déjà appliqué (CN cible déjà présent en AD, CN source absent) ne lève pas d'exception et log un warning (`logger()->warning(…)` ou `Log::warning(…)`) puis return.

**AC3 — Implémentation `rename` via LdapRecord modrdn (préservation GUID)**

- Given un `Workstation` existe en PG avec `name = $old` et le compte AD correspondant existe sur `CN=$old,…`
- When `$ws->name = $new; $ws->save();` est exécuté
- Then l'observer détecte `isDirty('name')` dans `updated()` et dispatch `WorkstationAdSyncJob::rename($ws->id, $ws->getOriginal('name'), $ws->name)`
- And le job exécute strictement :
  ```php
  $m = MachineModel::findBy('cn', $oldName);
  if ($m === null) { Log::warning(…); return; }   // idempotence
  $m->rename('CN='.$newName);
  $m->samaccountname        = strtoupper($newName).'$';
  $m->dnshostname           = $newName.'.'.$domain;
  $m->serviceprincipalname  = ['HOST/'.$newName, 'HOST/'.$newName.'.'.$domain];
  $m->save();
  ```
  où `$domain` est résolu via `config()` (ex. `config('ldap.connections.default.hosts.domain')`) ou via un helper existant (`LdapDnHelper`, à confirmer en lecture).
- And aucun appel à `AdMachineManager::renameComputer()` (samba-tool plan B) n'est conservé dans le chemin rename.
- And **aucun appel à `AdMachineManager::registerHardware()`** n'est requis post-rename (netbootGUID préservé par modrdn).
- And `objectGUID` ET `netbootGUID` du compte AD restent identiques avant/après (vérifiable via `ldapsearch -b "CN=$new,…" '+'` ou via QA runbook AC10).
- And `Workstation::ad_guid` en PG **n'a pas besoin** d'être réécrit (inchangé).

**AC4 — Implémentation `create` (hybride samba-tool + LdapRecord pour `ad_guid`)**

- Given un nouveau `Workstation` est inséré en PG (via UI parc, import CSV story 4.5, enrollment iPXE, factory de test)
- When `$ws->save();` est exécuté pour un INSERT
- Then l'observer dispatch `WorkstationAdSyncJob::create($ws->id)`
- And le job :
  1. Charge le `Workstation` (`$ws = Workstation::find($id)` ; si null → log warning + return, le ws a pu être supprimé entre le dispatch et le handle)
  2. Si le compte AD existe déjà (`MachineModel::findBy('cn', $ws->name)` ≠ null) → idempotence : log info, passe à l'étape 3 (registerHardware si UUID)
  3. Sinon, appelle `AdMachineManager::create($ws->name, $ou, …)` (samba-tool — conservé tel quel pour gérer password random / UAC initiaux) — **PAS** de portage LdapRecord du `create` dans cette story (voir Décision D2)
  4. Lit `objectGUID` via `MachineModel::findBy('cn', $ws->name)->getConvertedGuid()` et écrit `$ws->ad_guid = $guid` en PG **via `WorkstationObserver::withoutSync(fn() => $ws->save())`** pour éviter une boucle observer → job → observer
  5. Si `$ws->ipxe_uuid` (ou nom de colonne UUID effectif — à confirmer en lecture du modèle) est non null → appelle `AdMachineManager::registerHardware($ws->name, $ws->ipxe_uuid)` pour poser `netbootGUID`
- And aucune régression sur les call-sites actuels qui créent des Workstation (`WorkstationEnrollmentService::create`, import CSV, factories).

**AC5 — Implémentation `delete` via LdapRecord (fallback AdMachineManager)**

- Given un `Workstation` est supprimé en PG
- When `$ws->delete();` est exécuté
- Then l'observer hook `deleting($ws)` dispatch `WorkstationAdSyncJob::delete($ws->name, $ws->ad_guid)` **avant** la suppression PG (pour avoir encore accès à `$ws->name`)
- And le job exécute :
  ```php
  $m = MachineModel::findBy('cn', $name);
  if ($m === null) { Log::info('AD computer already absent'); return; }  // idempotence
  $m->delete();
  ```
- And fallback : si `AdMachineManager` expose une méthode `delete($name)` testée plus mature, le job peut l'appeler à la place — **décision laissée au dev après lecture du service**, documentée dans Completion Notes.

**AC6 — Implémentation `status` via LdapRecord (userAccountControl)**

- Given un `Workstation` change de `status` en PG (typiquement `active` ↔ `inactive`)
- When l'observer détecte `isDirty('status')` dans `updated()`
- Then dispatch `WorkstationAdSyncJob::status($ws->id, $ws->status)`
- And le job exécute :
  ```php
  $m = MachineModel::findBy('cn', $ws->name);
  if ($m === null) { Log::warning(…); return; }
  $m->useraccountcontrol = match ($status) {
      'active'   => 4096,   // WORKSTATION_TRUST_ACCOUNT
      'inactive' => 4098,   // 4096 + ACCOUNTDISABLE (0x0002)
      default    => throw new \RuntimeException("Status AD non supporté : {$status}"),
  };
  $m->save();
  ```
- And le cas `status === 'protected'` (ou tout autre status applicatif non mappable) : décision à acter par le dev (soit throw, soit no-op silencieux avec log info — recommandé : **throw** pour signaler le bug applicatif). Documenté dans Completion Notes.

**AC7 — Refactor `WindowsPostInstallTracker::recordRenommeAdRenamed()` (fix root cause divergence)**

- Given `app/Ipxe/Services/WindowsPostInstallTracker.php:400-473` qui appelle aujourd'hui `$adManager->renameComputer(…)` hors-transaction sans écrire `$ws->name` en PG
- When la story est livrée
- Then la méthode est refactorée pour, à l'intérieur de la transaction PG existante :
  ```php
  $workstation->name = $role;
  $this->saveWithProtected($workstation);   // méthode existante du tracker
  ```
  et **plus aucun appel direct** à `$adManager->renameComputer(…)`.
- And l'observer dispatch le job AD de manière asynchrone (queue), qui s'exécutera après le COMMIT PG.
- And le trade-off accepté est documenté dans Completion Notes : **si le job AD échoue (3 retries), le PG est déjà committé et il y aura une fenêtre de divergence transitoire jusqu'à ce que le retry final aboutisse ou qu'une alerte se déclenche**. C'est le même trade-off que pour `WorkstationGroupAdSyncJob` actuellement en prod et accepté.
- And **OU bien** (alternative à acter par le dev si jugé plus sûr) le rename AD est effectué **synchroniquement en pré-check** avant le COMMIT PG (via `WorkstationAdSyncJob::dispatchSync()` ou appel direct au handler), pour pouvoir rollback PG si AD échoue. Recommandation par défaut : **garder l'async** (cohérence avec le pattern existant) ; cette décision DOIT être tranchée explicitement dans Completion Notes.

**AC8 — Refactor `WorkstationEnrollmentService` branche rename (cleanup)**

- Given `app/Ipxe/Services/WorkstationEnrollmentService.php:191-206` qui appelle aujourd'hui à la main `renameComputer()` + `registerHardware()` post-rename
- When la story est livrée
- Then la branche rename est simplifiée à :
  ```php
  $current->name = $sanitized;
  $current->save();
  // L'observer + WorkstationAdSyncJob::rename() font tout le reste.
  ```
- And l'appel `renameComputer()` est supprimé de cette branche.
- And **décision à acter par le dev** : l'appel `registerHardware($sanitized, $uuid)` post-rename est-il **(a)** supprimé (puisque netbootGUID est préservé par modrdn) ou **(b)** gardé en best-effort défensif (au cas où le job rename échoue et que la machine est ré-enrôlée dans la foulée) ? **Recommandation par défaut : supprimé** (le job rename est idempotent et retry 3×). Documenté dans Completion Notes.
- And la branche `create` du même service (`WorkstationEnrollmentService.php:168-172`) est laissée telle quelle si elle utilise déjà l'observer indirectement via `$ws->save()` ; sinon adaptée pour bénéficier de l'observer.

**AC9 — Tests unitaires `WorkstationAdSyncJob` (4 actions)**

- Given LdapRecord supporte le testing via `LdapRecord\Testing\DirectoryEmulator::setup()`
- When `php artisan test --filter=WorkstationAdSyncJobTest` est lancé
- Then les tests suivants existent et passent :
  - `test_create_action_creates_ad_account_and_persists_ad_guid` (mock `AdMachineManager::create` + assertion `Workstation::ad_guid` écrit via `withoutSync`)
  - `test_create_action_is_idempotent_when_ad_account_already_exists`
  - `test_rename_action_uses_modrdn_and_preserves_guid` (DirectoryEmulator : créer un Computer, renommer, asserter `objectGUID` strictement identique)
  - `test_rename_action_updates_samaccountname_dnshostname_spn`
  - `test_rename_action_is_noop_when_old_cn_not_found_in_ad` (log warning + return)
  - `test_delete_action_removes_ad_account`
  - `test_delete_action_is_noop_when_ad_account_already_absent`
  - `test_status_action_sets_useraccountcontrol_to_4096_when_active`
  - `test_status_action_sets_useraccountcontrol_to_4098_when_inactive`
  - `test_status_action_throws_on_unsupported_status` (ou no-op + log info selon décision)

**AC10 — Tests d'observation Eloquent (`Bus::fake()`)**

- Given `WorkstationObserver` est enregistré et le job est dispatchable
- When `php artisan test --filter=WorkstationObserverTest` est lancé
- Then les tests suivants existent et passent :
  - `test_creating_workstation_dispatches_create_job` (`Bus::fake() ; Workstation::factory()->create() ; Bus::assertDispatched(WorkstationAdSyncJob::class, fn ($j) => $j->action === 'create')`)
  - `test_renaming_workstation_dispatches_rename_job_with_old_and_new_names`
  - `test_changing_status_dispatches_status_job`
  - `test_changing_only_unrelated_attribute_does_not_dispatch_rename` (ex. `$ws->mac = 'XX' ; $ws->save();` → pas de rename job)
  - `test_deleting_workstation_dispatches_delete_job_with_name_and_ad_guid`
  - `test_without_sync_helper_bypasses_observer` (`WorkstationObserver::withoutSync(fn() => Workstation::factory()->create())` → `Bus::assertNotDispatched(WorkstationAdSyncJob::class)`)

**AC11 — Test d'intégration AD sur VM (manuel, documenté)**

- Given la VM `/vm` (192.168.122.50) tourne avec AD `localdev.fr` + une machine de test (ex. `CN=test-4-9,OU=computers,…`)
- When le scénario manuel documenté dans `docs/qa/domains/ad-sync.md` (à créer ou compléter) est exécuté :
  1. `ldapsearch -b 'CN=test-4-9,…' '+'` → capturer `objectGUID` et `netbootGUID` initiaux
  2. `php artisan tinker → Workstation::where('name', 'test-4-9')->first()->update(['name' => 'test-4-9-renamed'])`
  3. Attendre le passage du worker queue (ou `php artisan queue:work --once`)
  4. `ldapsearch -b 'CN=test-4-9-renamed,…' '+'` → vérifier `objectGUID` et `netbootGUID` strictement identiques
  5. Vérifier que `sAMAccountName = TEST-4-9-RENAMED$`, `dNSHostName = test-4-9-renamed.localdev.fr`, `servicePrincipalName` contient bien les 2 valeurs HOST/…
  6. Refaire pour `status` (inactive → vérifier `userAccountControl` AD passe à 4098) puis `delete` (vérifier absence AD)
- Then le scénario est documenté dans le runbook QA avec les commandes exactes et les résultats attendus.
- And **note** : ce test est **différable** (idem 4.2 AC9 / 4.3 e2e manuel) — la checklist doit exister, l'exécution peut être faite à la recette QA fin d'epic.

**AC12 — Audit & cleanup des écritures AD `iphostnumber` / `networkaddress` résiduelles**

- Given la sync DHCP est désormais alimentée par la table SQL `dhcp_reservations` via `app/Services/Network/DhcpService.php:281`
- When l'audit grep est effectué sur le repo (`rg "iphostnumber|networkaddress"` côté services, jobs, observers, controllers)
- Then les call-sites qui écrivent encore ces attributs sur les comptes machines AD sont identifiés
- And ils sont **supprimés** (ou la non-existence de tels call-sites est documentée — l'audit peut révéler 0 occurrence et la story devient un no-op sur ce point, à acter dans Completion Notes).
- And aucun nouveau call-site n'est introduit par cette story.

**AC13 — Documentation domaine `ad-sync`**

- Given cette story consolide le pattern observer-driven AD pour les machines
- When `docs/qa/domains/ad-sync.md` est créé (ou complété si déjà présent — vérifier avant)
- Then le document décrit :
  - Le pattern observer-driven AD (référence `WorkstationGroupObserver` + `WorkstationGroupAdSyncJob`, généralisé à `Workstation`)
  - Pourquoi rename via LdapRecord modrdn et pas samba-tool (préservation GUID — preuve test VM)
  - Les 4 actions du job (create / rename / delete / status) et leur idempotence
  - Le helper `withoutSync(callable)` et ses cas d'usage légitimes (seeders, imports CSV, sync inverse depuis AD)
  - Le runbook QA AC11 (rename + status + delete + rollback nom original)

---

## Tasks / Subtasks

### Tâche 1 — Audit du pattern de référence et des call-sites (AC: 1, 7, 8, 12)

- [x] **1.1** Lire `app/Observers/WorkstationGroupObserver.php` intégralement → noter la structure exacte (hooks, helper `withoutSync`, gestion `isDirty`, ordre des dispatches).
- [x] **1.2** Lire `app/Jobs/AdSync/WorkstationGroupAdSyncJob.php` intégralement → noter constantes, factory methods, `tries`, `backoff`, structure du handler.
- [x] **1.3** Lire `app/Observers/WorkstationObserver.php` intégralement → confirmer présence helper `withoutSync` + flag `$syncEnabled`, lister les hooks pivot existants à conserver.
- [x] **1.4** Lire `app/Services/Ldap/AdMachineManager.php` (ou équivalent) → cartographier les méthodes existantes (`create`, `renameComputer`, `registerHardware`, `delete` ?) et leurs signatures exactes.
- [x] **1.5** Lire `app/Ipxe/Services/WindowsPostInstallTracker.php:371-490` → comprendre la transaction PG actuelle dans `recordRenommeAdRenamed()` et la méthode `saveWithProtected()`.
- [x] **1.6** Lire `app/Ipxe/Services/WorkstationEnrollmentService.php:160-220` → cartographier les branches create / rename existantes.
- [x] **1.7** `rg "iphostnumber|networkaddress" app/` + dans `database/` → identifier les call-sites résiduels (AC12).
- [x] **1.8** Vérifier si `docs/qa/domains/ad-sync.md` existe déjà (`ls docs/qa/domains/`) → décider create vs update.

### Tâche 2 — Création du job `WorkstationAdSyncJob` (AC: 2, 3, 4, 5, 6)

- [x] **2.1** Créer `app/Jobs/AdSync/WorkstationAdSyncJob.php` en s'inspirant ligne par ligne de `WorkstationGroupAdSyncJob.php`.
- [x] **2.2** Définir constantes `ACTION_CREATE`, `ACTION_RENAME`, `ACTION_DELETE`, `ACTION_STATUS`.
- [x] **2.3** Implémenter factory methods statiques `::create`, `::rename`, `::delete`, `::status`.
- [x] **2.4** Implémenter `handle()` qui dispatch sur `$this->action` via `match()` vers `handleCreate()`, `handleRename()`, `handleDelete()`, `handleStatus()`.
- [x] **2.5** Implémenter `handleRename()` via LdapRecord modrdn (cf. AC3, code exact).
- [x] **2.6** Implémenter `handleCreate()` hybride samba-tool + LdapRecord `ad_guid` (cf. AC4) — utiliser `WorkstationObserver::withoutSync(fn() => $ws->save())` pour persister `ad_guid` sans boucle.
- [x] **2.7** Implémenter `handleDelete()` (cf. AC5) — décider fallback `AdMachineManager` après Tâche 1.4.
- [x] **2.8** Implémenter `handleStatus()` avec mapping `userAccountControl` (cf. AC6) — décider comportement pour status non supportés.
- [x] **2.9** Définir `$tries = 3` et `$backoff = 10` (cohérent avec `WorkstationGroupAdSyncJob`).
- [x] **2.10** Résoudre `$domain` via `config(…)` ou helper `LdapDnHelper` (à confirmer Tâche 1.4).

### Tâche 3 — Réécriture de `WorkstationObserver` (AC: 1)

- [x] **3.1** Ajouter `created(Workstation $ws)` → `if (! self::$syncEnabled) return;` puis `WorkstationAdSyncJob::create($ws->id)::dispatch()`.
- [x] **3.2** Ajouter `updated(Workstation $ws)` :
  - `if (! self::$syncEnabled) return;`
  - `if ($ws->isDirty('name')) → dispatch ::rename($ws->id, $ws->getOriginal('name'), $ws->name)`
  - `if ($ws->isDirty('status')) → dispatch ::status($ws->id, $ws->status)`
- [x] **3.3** Ajouter `deleting(Workstation $ws)` (PAS `deleted`, on a besoin de `$ws->name` qui est encore disponible) → `if (! self::$syncEnabled) return; WorkstationAdSyncJob::delete($ws->name, $ws->ad_guid)::dispatch()`.
- [x] **3.4** Conserver les hooks pivot audit-only existants tels quels (sauf si Tâche 1.3 confirme qu'ils sont morts — alors les supprimer et documenter dans Completion Notes).
- [x] **3.5** Vérifier que le helper `withoutSync(callable)` existant fonctionne aussi pour les nouveaux hooks (il devrait, puisque `$syncEnabled` est un flag global au scope observer).

### Tâche 4 — Enregistrement de l'observer (AC: 1)

- [x] **4.1** Ajouter `\App\Models\Workstation::observe(\App\Observers\WorkstationObserver::class);` dans `app/Providers/AppServiceProvider.php::boot()` à côté des autres `observe(…)` (ligne ~202-205).
- [x] **4.2** Vérifier que les imports `use` en tête de fichier incluent `Workstation` et `WorkstationObserver`.
- [x] **4.3** Smoke test : `php artisan tinker → Workstation::factory()->make()` ne lève pas d'erreur de boot.

### Tâche 5 — Refactor des call-sites iPXE (AC: 7, 8)

- [x] **5.1** `app/Ipxe/Services/WindowsPostInstallTracker.php::recordRenommeAdRenamed()` :
  - Remplacer le bloc `$adManager->renameComputer(…)` par `$workstation->name = $role; $this->saveWithProtected($workstation);`.
  - Conserver la transaction PG existante.
  - Documenter dans le code (commentaire) que l'observer prend le relais pour AD.
  - **Décision à acter dans Completion Notes** : async (recommandé) vs sync pré-check.
- [x] **5.2** `app/Ipxe/Services/WorkstationEnrollmentService.php:191-206` (branche rename) :
  - Supprimer l'appel `$this->adMachineManager->renameComputer($oldName, $sanitized)`.
  - **Décision à acter dans Completion Notes** : supprimer aussi `$this->adMachineManager->registerHardware($sanitized, $uuid)` post-rename (recommandé) ou le garder en best-effort.
- [x] **5.3** Vérifier qu'aucun autre call-site ne fait `AdMachineManager::renameComputer()` directement (`rg "renameComputer\("` dans `app/`) — si oui, refactorer pareil ou justifier dans Completion Notes.

### Tâche 6 — Audit & cleanup `iphostnumber`/`networkaddress` (AC: 12)

- [x] **6.1** `rg -n "iphostnumber|networkaddress" app/ database/ tests/` → lister les occurrences.
- [x] **6.2** Pour chaque occurrence dans du code applicatif (PAS dans les tests legacy de regression), supprimer l'écriture AD ou justifier sa conservation.
- [x] **6.3** Si 0 occurrence trouvée → documenter dans Completion Notes (« audit AC12 : 0 call-site résiduel »).

### Tâche 7 — Tests unitaires job (AC: 9)

- [x] **7.1** Créer `tests/Unit/Jobs/AdSync/WorkstationAdSyncJobTest.php` (ou path conventionnel équivalent — vérifier où sont les autres tests de jobs AdSync).
- [x] **7.2** Setup `LdapRecord\Testing\DirectoryEmulator::setup()` dans `setUp()` (vérifier package installé : `composer show directorytree/ldaprecord` doit montrer une version avec emulator).
- [x] **7.3** Écrire les 10 tests listés AC9 (create x2, rename x3, delete x2, status x3).
- [x] **7.4** Mocker `AdMachineManager` pour `handleCreate()` (samba-tool out-of-process pas mockable directement).
- [x] **7.5** Cible : 100% verts, 0 régression sur les tests `WorkstationGroupAdSyncJobTest` existants.

### Tâche 8 — Tests d'observation (AC: 10)

- [x] **8.1** Créer `tests/Feature/Observers/WorkstationObserverTest.php` (ou path conventionnel équivalent).
- [x] **8.2** Setup `Bus::fake([WorkstationAdSyncJob::class])` dans `setUp()`.
- [x] **8.3** Écrire les 6 tests listés AC10.
- [x] **8.4** Vérifier que les factory `Workstation::factory()` existent et sont utilisables — sinon créer (vérifier `database/factories/WorkstationFactory.php`).

### Tâche 9 — Runbook QA + documentation domaine (AC: 11, 13)

- [x] **9.1** Créer ou compléter `docs/qa/domains/ad-sync.md` avec :
  - Description du pattern observer-driven AD
  - Justification rename via LdapRecord modrdn + preuve test VM (cf. tableau du Contexte)
  - Les 4 scénarios manuels AC11 (rename / create / delete / status)
  - Scénario rollback : restauration nom original
- [x] **9.2** Vérifier si `docs/domains/parc.md` (créé en 4.2) doit être mis à jour pour mentionner le pattern AD (référence croisée).

### Tâche 10 — Validation finale (AC: tous)

- [x] **10.1** `composer dump-autoload` + `php artisan config:clear` + `php artisan test` complet → 0 régression sur la suite existante.
- [x] **10.2** Run ciblé : `php artisan test --filter='WorkstationAdSyncJob|WorkstationObserver'` → 100% verts.
- [x] **10.3** Smoke test manuel sur VM `/vm` (différable mais checklist prête — cf. AC11).
- [x] **10.4** Mettre à jour `_bmad-output/implementation-artifacts/sprint-status.yaml` : `4-9-sync-ad-machine-observer-ldaprecord` → `review`.
- [x] **10.5** Remplir File List + Completion Notes List dans cette story.

---

## Dev Notes

### Décisions actées

- **D1 — Rename via LdapRecord modrdn, PAS samba-tool.** Validé par test VM 2026-05-28 : préservation `objectGUID` + `netbootGUID`. C'est la pierre angulaire de la story — sans cette décision, il faudrait re-`registerHardware` après chaque rename (état actuel).
- **D2 — Le `create` AD reste via `AdMachineManager` (samba-tool) en MVP.** Rationale : `samba-tool computer create` gère le password random + UAC initiaux + ajout dans OU correcte de manière éprouvée. Porter `create` en LdapRecord pur est faisable (instanciation `Computer::create()` + set attributes + save) mais demande de répliquer la logique password/UAC. **Hors scope de cette story** ; documenté comme follow-up si besoin. Le job lit `objectGUID` post-create via LdapRecord pour stocker `ad_guid` PG.
- **D3 — Job asynchrone (queue) par défaut, pas synchrone.** Cohérent avec `WorkstationGroupAdSyncJob` déjà en prod. Trade-off accepté : fenêtre de divergence transitoire si AD échoue après COMMIT PG. Le retry x3 + backoff 10s mitigent. Si à l'usage le trade-off devient inacceptable (fenêtre trop visible), basculer en `dispatchSync()` est trivial (1 ligne dans l'observer).
- **D4 — Hooks pivot audit-only de `WorkstationObserver` SUPPRIMÉS** (acté Henri 2026-05-28). Le code est mort (audit-only depuis Epic 4 2026-05-20, voir [[project_no_native_gpo_creation]] et historique du fichier). Le dev DOIT supprimer `onGroupAttached`, `onGroupDetached`, `onGroupsSynced` et tous leurs call-sites éventuels. Si un call-site existe encore (à vérifier par grep en Tâche 1.3), il faut soit le retirer aussi soit migrer son intention vers un observer pivot dédié — mais NE PAS garder le code mort sous prétexte que « ça ne coûte rien ».
- **D5 — Mapping `status` PG → `userAccountControl` AD** (acté Henri 2026-05-28) : `protected` est un flag SE5-only (anti-réécrasement iPXE, cf. `Workstation::isProtected()` l.309), côté AD c'est un compte ACTIF. Mapping figé :
  - `status === 'active'` → UAC = 4096
  - `status === 'protected'` → UAC = 4096 (identique à active)
  - `status === 'inactive'` → UAC = 4098
  - autre valeur (improbable, l'enum est fermé en PG) → throw `InvalidArgumentException`, retry x3, alerte ops.
- **D6 — Cleanup `iphostnumber`/`networkaddress` AC12 : audit d'abord, suppression si trouvé.** Possible que ce soit déjà clean (0 occurrence). Dans ce cas, AC12 est un no-op documenté.
- **D7 — Refactor `WorkstationEnrollmentService` branche rename : supprimer `registerHardware` post-rename** (acté Henri 2026-05-28). Inutile puisque netbootGUID préservé par modrdn. **Pas de fallback défensif** — source unique de vérité. Si un jour on bascule sur samba-tool, il faudra réintroduire l'appel à `registerHardware` dans le job lui-même, pas dans le call-site.

### Contraintes architecturales (non négociables)

- **Pattern miroir strict** : `WorkstationAdSyncJob` doit ressembler à `WorkstationGroupAdSyncJob` ligne par ligne (constantes, factory methods, `tries`/`backoff`, structure handler). Ne pas inventer de nouveau pattern.
- **Idempotence systématique** : chaque action vérifie d'abord l'état AD courant et no-op + log si l'état cible est déjà atteint. Le job peut être rejoué sans danger.
- **`WorkstationObserver::withoutSync(callable)`** est le SEUL moyen autorisé pour écrire un `Workstation` sans déclencher le job AD (seeders, imports CSV, sync inverse). Ne PAS introduire d'autre mécanisme de bypass (flag global, env var, etc.).
- **Pas d'appel direct à `AdMachineManager::renameComputer()` côté applicatif** après la story. Le seul code qui parle à AD pour un rename est `WorkstationAdSyncJob::handleRename()`.
- **Pas de cleanup de `Workstation::ad_guid` côté rename** : il ne change pas, n'y touchez pas.
- **Resolution `$domain`** : passer par `config()` ou helper existant. Ne PAS hardcoder `localdev.fr` ni le lire dans une variable LDAP runtime.
- **Pas de test qui nécessite une vraie connexion AD/LDAP en CI** : `DirectoryEmulator::setup()` pour unitaires, `Bus::fake()` pour tests d'observation, le test d'intégration AC11 est explicitement manuel/VM.

### Code existant à réutiliser (anti-réinvention)

| Besoin | Fichier/Classe | Méthode(s) | Notes |
|---|---|---|---|
| Pattern observer | `app/Observers/WorkstationGroupObserver.php` | `created/updated/deleting` + `withoutSync` | Référence ligne par ligne |
| Pattern job AdSync | `app/Jobs/AdSync/WorkstationGroupAdSyncJob.php` | constantes + factory + handle | Référence ligne par ligne |
| Helper bypass observer | `app/Observers/WorkstationObserver.php` | `withoutSync()`, `$syncEnabled` | Déjà présent, à réutiliser tel quel |
| Create AD samba-tool | `app/Services/Ldap/AdMachineManager.php` | `create(…)` | Conservé (D2) |
| Pose netbootGUID | `app/Services/Ldap/AdMachineManager.php` | `registerHardware($name, $uuid)` | Utilisé uniquement dans `handleCreate()` du nouveau job |
| Modèle LdapRecord machine | `LdapRecord\Models\ActiveDirectory\Computer` | `findBy/rename/delete/save/getConvertedGuid` | Lib externe LdapRecord |
| Transaction PG iPXE | `WindowsPostInstallTracker::saveWithProtected()` | existante | Réutilisée tel quel dans refactor 5.1 |
| Bypass pour seeders | `WorkstationObserver::withoutSync(fn() => …)` | static | À utiliser pour écrire `ad_guid` dans `handleCreate()` |

### Pitfalls connus et points d'attention

- **Boucle observer → job → observer** : le job écrit `ad_guid` côté PG (`handleCreate()` étape 4). Si fait via un simple `$ws->save()`, l'observer redéclenche `updated()` → potentiel dispatch `::status` parasite. **Solution** : utiliser `WorkstationObserver::withoutSync(fn() => $ws->save())`. Vérifier en test (`test_create_action_creates_ad_account_and_persists_ad_guid`).
- **`isDirty` après `save()`** : dans l'observer hook `updated`, `$ws->isDirty('name')` retourne `false` car le save a déjà été appliqué. Utiliser `$ws->wasChanged('name')` ou `$ws->getChanges()` selon la convention Laravel (à vérifier en lisant `WorkstationGroupObserver` qui résout déjà ce problème).
- **Ordre `deleting` vs `deleted`** : on hook `deleting` (PAS `deleted`) pour avoir encore accès à `$ws->name` qui sera utilisé dans `WorkstationAdSyncJob::delete($name, $adGuid)`. Si on dispatch dans `deleted`, le job reçoit l'ID mais le find échoue.
- **`Workstation::factory()` dans les tests** : vérifier qu'elle existe (`database/factories/WorkstationFactory.php`). Si elle déclenche l'observer en boucle pendant les tests (créer 10 ws = dispatcher 10 jobs en `Bus::fake`), c'est OK pour les tests d'observation mais ralentit les autres tests Feature qui factory-isent des Workstation sans `Bus::fake`. Surveiller la perf de la suite Feature après merge.
- **`LdapRecord\Testing\DirectoryEmulator`** : nécessite `directorytree/ldaprecord-laravel` >= v3 avec testing module. Vérifier `composer.json`.
- **`saveWithProtected` dans `WindowsPostInstallTracker`** : méthode existante qui contourne probablement `Model::$guarded` pour permettre l'écriture de certains champs sensibles. Vérifier son comportement et s'assurer qu'elle ne contourne pas aussi l'observer (sinon le refactor 5.1 est inopérant). Si elle utilise `withoutEvents()`, il faut la modifier ou utiliser un autre mécanisme.
- **Conflit avec `WorkstationMembershipAdSyncJob`** : celui-ci gère les pivots groupe↔machine. S'assurer qu'il continue de fonctionner et qu'on ne dispatch pas deux fois lors d'une création de Workstation avec pivots.

### Source tree — fichiers prévus impactés

```
# À CRÉER
app/Jobs/AdSync/WorkstationAdSyncJob.php                       # Job miroir de WorkstationGroupAdSyncJob
tests/Unit/Jobs/AdSync/WorkstationAdSyncJobTest.php            # AC9 (10 tests)
tests/Feature/Observers/WorkstationObserverTest.php            # AC10 (6 tests)
docs/qa/domains/ad-sync.md                                     # AC11 + AC13 (créer si absent)

# À MODIFIER
app/Observers/WorkstationObserver.php                          # + hooks created/updated/deleting
app/Providers/AppServiceProvider.php                           # + Workstation::observe(WorkstationObserver::class) ligne ~202
app/Ipxe/Services/WindowsPostInstallTracker.php                # AC7 — refactor recordRenommeAdRenamed (~ligne 400-473)
app/Ipxe/Services/WorkstationEnrollmentService.php             # AC8 — refactor branche rename (~ligne 191-206)
_bmad-output/implementation-artifacts/sprint-status.yaml       # status review en fin de story

# POTENTIELLEMENT MODIFIÉ (selon audit AC12)
app/Services/Ldap/AdMachineManager.php                         # uniquement si nettoyage iphostnumber/networkaddress nécessaire
# + tout call-site identifié par `rg "iphostnumber|networkaddress"`

# POTENTIELLEMENT CRÉÉ (si pas encore présent)
database/factories/WorkstationFactory.php                      # uniquement si absent et requis par les tests
```

### Tests standards du projet

- `Tests\TestCase` (base Laravel) pour Feature, `Tests\Unit\TestCase` ou équivalent pour Unit.
- `Bus::fake([WorkstationAdSyncJob::class])` pour tests d'observation.
- `LdapRecord\Testing\DirectoryEmulator::setup()` pour tests du job (pas de vraie connexion AD).
- `Mockery` ou `$this->mock(AdMachineManager::class)` pour la branche `handleCreate()` (samba-tool out-of-process pas mockable directement).
- Pas de `RefreshDatabase` strictement obligatoire — vérifier le pattern utilisé dans `WorkstationGroupAdSyncJobTest` (s'il existe) ou `tests/Feature/Observers/WorkstationGroupObserverTest` (s'il existe).
- Pas de test qui requiert une vraie connexion AD/LDAP/réseau — l'AC11 est explicitement manuel/VM.

### References

- [Source: _bmad-output/planning-artifacts/epics.md#Epic 4 (ligne 1431)] — Epic Gestion des Machines
- [Source: _bmad-output/planning-artifacts/epics.md#Story 4.2 (ligne 1445)] — pattern WorkstationGroupObserver historiquement utilisé
- [Source: sambaedu-reload/app/Observers/WorkstationGroupObserver.php] — pattern de référence à imiter
- [Source: sambaedu-reload/app/Jobs/AdSync/WorkstationGroupAdSyncJob.php] — pattern de référence à imiter
- [Source: sambaedu-reload/app/Observers/WorkstationObserver.php] — fichier existant (helper withoutSync + hooks pivot) à étendre
- [Source: sambaedu-reload/app/Providers/AppServiceProvider.php:199-205] — boot() où enregistrer l'observer
- [Source: sambaedu-reload/app/Ipxe/Services/WindowsPostInstallTracker.php:371-490] — recordRenommeAdRenamed (root cause divergence PG↔AD)
- [Source: sambaedu-reload/app/Ipxe/Services/WorkstationEnrollmentService.php:160-220] — branche rename à simplifier
- [Source: sambaedu-reload/app/Services/Ldap/AdMachineManager.php] — service samba-tool (conservé pour create + registerHardware)
- [Source: sambaedu-reload/app/Services/Network/DhcpService.php:281] — sync DHCP via dhcp_reservations (justifie cleanup AC12)
- [Source: VM test 2026-05-28 — CN=28051115,OU=ULIS,OU=computers,DC=localdev,DC=fr] — preuve préservation objectGUID/netbootGUID via LdapRecord modrdn
- [Source: _bmad-output/implementation-artifacts/4-2-actions-unitaires-machine-feedback-readiness.md] — format/complexité de référence

### Previous Story Intelligence (Epic 4 + Epic 17 learnings)

- **4-2/4-3/4-4** : pattern async via `DispatchMachinePowerActionJob` + table `machine_power_action_tasks` éprouvé en prod. Même philosophie pour `WorkstationAdSyncJob` : queue async + retries + log explicite.
- **4-7 wallpapers** : pattern observer + cache APCu utilisé pour `Wallpaper` — référence si on a besoin d'optimisation cache lecture AD.
- **17.x (Epic 17 récent)** : refactor des services iPXE sensible, audit `audit-applications-scripts.md` a validé que les services iPXE peuvent être modifiés sans casser le flow PXE/Windows si on respecte les transactions PG existantes. Ne PAS toucher `saveWithProtected()` sans précaution.
- **`WorkstationGroupObserver` lessons** : le bypass `withoutSync` est crucial pour les seeders et les sync inverses ; l'utiliser systématiquement quand le code applicatif écrit depuis du contexte AD (ex. sync depuis AD vers PG).
- **`LdapRecord` vs `php-ldap`** : le projet a déjà migré sur LdapRecord pour les opérations user/group. Cette story complète la couverture pour les machines.

### Notes mémoire (cf. MEMORY.md)

- **`feedback_worktree_no_vm_sync`** — si dev exécuté depuis un worktree, ne PAS tester sur VM directement ; l'AC11 reste manuel/différable.
- **`project_two_repos_topology`** — commit dans le repo B (sambaedu-reload/), pas le parent (codebase/).
- **`project_php_fpm_user_www_admin`** — pas applicable ici (pas de fichier physique à chown — uniquement du code PHP + LDAP).
- **`project_bmad_workflow_paths_markdown`** — workflows en .md pas .xml (suivi pour cette story).
- **`feedback_auth_iso_legacy`** — auth machine reste iso-legacy AD+SMB ; on ne change pas le mécanisme d'auth, on change uniquement la voie d'écriture des attributs AD.

### Project Structure Notes

- Jobs AdSync : `app/Jobs/AdSync/` (convention déjà établie avec 4 jobs existants).
- Observers : `app/Observers/` (convention déjà établie avec 7 observers existants).
- Tests jobs : `tests/Unit/Jobs/AdSync/` ou `tests/Feature/Jobs/AdSync/` selon pattern existant (à vérifier en Tâche 1).
- Tests observers : `tests/Feature/Observers/` ou `tests/Unit/Observers/` selon pattern existant.
- Documentation domaine QA : `docs/qa/domains/{domain}.md` (convention 4.x).

### Hors scope explicite (à ne PAS faire dans cette story)

- **Portage complet du `create` AD en LdapRecord pur** (sans samba-tool) — follow-up si jugé utile, pas dans cette story (D2).
- **Cleanup des attributs AD legacy non utilisés** (`location`, `description`, `operatingSystemVersion`, `lastLogon`) — pas dans le scope.
- **Sync `ip` / `mac` AD ↔ PG** — au contraire on supprime ce qui reste (AC12), pas de nouvelle écriture.
- **Refactor DHCP** ou migration vers Kea — déjà piloté par `dhcp_reservations` (story dédiée).
- **Story 16-4 (création GPO native)** — déjà cancelled, ne pas la mentionner ni la rouvrir.
- **Refonte du modèle d'auth machine** — reste iso-legacy AD+SMB (cf. MEMORY `feedback_auth_iso_legacy`).
- **Mécanisme de réconciliation périodique PG↔AD** (ex. cron qui vérifie la cohérence) — pas dans le scope ; cette story garantit la cohérence à l'écriture, pas la détection de divergences pré-existantes.

---

## Dev Agent Record

### Agent Model Used

`claude-opus-4-7[1m]` (Opus 4.7 — 1M context window). Dev en une passe single-thread, pas de stop intermédiaire.

### Debug Log References

- Suite complète AVANT changements (baseline `main`) : `115 failed, 3405 passed` (env local sans LDAP/Imagick/Zip — failures préexistantes).
- Suite complète APRÈS changements : voir Completion Notes. Comparaison ligne-à-ligne effectuée via `diff` des `FAILED` (cf. notes ci-dessous).
- Tests ciblés `WorkstationAdSyncJob|WorkstationObserver` : **19/19 verts** dès le premier run après ré-attachement de l'event dispatcher en setUp().
- Itération clé : premier passage des tests Observer en échec (0 dispatch détecté). Diagnostic : `IpxeSchemaBootstrapper::bootstrap()` appelle `Model::unsetEventDispatcher()` ce qui mute TOUS les observers. Fix : ré-attacher `Model::setEventDispatcher(Event::getFacadeRoot())` + `Workstation::observe(...)` dans `WorkstationObserverTest::setUp()`, tearDown qui re-mute.
- Itération 2 : premier passage suite complète post-changement = 154 failed vs 115 baseline (+39 régressions). Toutes les nouvelles failures = `LdapRecordException ldap_search: Can't contact LDAP server` sur des tests Feature qui font `Workstation::create/save/delete` sans muter le dispatcher. Cause : queue=sync en PHPUnit → tout dispatch tape LDAP réel. Fix : enregistrer `Workstation::observe(WorkstationObserver::class)` uniquement hors environnement `testing` dans `AppServiceProvider::boot()`. Les tests qui ont besoin de l'observer (mon `WorkstationObserverTest`) l'enregistrent eux-mêmes en setUp.

### Completion Notes List

**Décisions actées (rappel + résultats) :**

- **D1 (rename modrdn)** : implémenté dans `WorkstationAdSyncJob::handleRename()`. `MachineModel::findBy('cn', $old)->rename('CN='.$new)` puis set `samaccountname` (upper + `$`), `dnshostname` (`$newName.$domain`), `serviceprincipalname` (HOST/short + HOST/fqdn), `save()`. Idempotence : si `oldCn` absent ET `newCn` présent → no-op success. Si `oldName === newName` → no-op.
- **D2 (create via AdMachineManager)** : `handleCreate()` appelle `AdMachineManager::check($name)` (idempotent samba-tool create + already-exists), puis relit le compte via `MachineModel::findBy()` pour récupérer `getConvertedGuid()` et le stocker en PG via `WorkstationObserver::withoutSync(fn() => $ws->save())`. `registerHardware` appelé best-effort si `$ws->uuid` non vide.
- **D3 (async)** : retenu, cohérent avec `WorkstationGroupAdSyncJob`. `$tries=3`, `$backoff=10`. Pas de `dispatchSync()`.
- **D4 (hooks pivot supprimés)** : confirmé. `onGroupAttached/onGroupDetached/onGroupsSynced` retirés de `WorkstationObserver`. **3 call-sites de ces méthodes audit-only ont été refactorés** (étaient des no-ops audit-log) : `Workstation::attachGroups/detachGroups/syncGroups` et `WorkstationGroup::attachWorkstations/detachWorkstations/syncWorkstations` simplifiés (juste `attach/detach/sync` sur la relation). Imports `App\Observers\WorkstationObserver` retirés des deux models.
- **D5 (mapping status)** : implémenté dans `handleStatus()` via `match()` strict : `active|protected → 4096`, `inactive → 4098`, `default → throw InvalidArgumentException`. Test unitaire dédié vérifie le throw sur `bogus-value`.
- **D6 (audit iphostnumber/networkaddress)** : audit grep effectué. **6 call-sites identifiés** : `WorkstationService.php` (lecture seule), `AdSyncChecker.php` (lecture seule pour comparaison), `ApplicationScriptsGenerator.php` (build XML script), `MachineModel.php` (accesseurs + columns array), `LdapAttributes.php` + `LdapFilter.php` (constantes), `PowerShellRemoteService.php` (lecture). **0 call-site qui ÉCRIT** ces attributs sur AD côté Workstation. **Cleanup = no-op** (audit-only).
- **D7 (registerHardware post-rename supprimé)** : confirmé. `WorkstationEnrollmentService::enrollName()` branche RENAMED : suppression des deux appels `renameComputer` + `registerHardware`. Le job AD prend le relais via observer (modrdn préserve netbootGUID).

**Décisions de mise en œuvre non triviales (non actées dans la story) :**

1. **Observer non enregistré en environnement `testing`** (ajout `AppServiceProvider::boot()` ligne 207-213). Cause : queue=sync PHPUnit + tests Feature qui touchent Workstation sans muter l'event dispatcher → contact LDAP réel → 39 régressions LdapRecordException. Les tests qui ont besoin de l'observer (`WorkstationObserverTest`) l'enregistrent explicitement en setUp. Trade-off : la couverture observer-vs-prod n'est PAS automatique en CI ; il faut consciemment enregistrer dans le test.
2. **`wasChanged` au lieu de `isDirty` dans `WorkstationObserver::updated()`** : dans le hook `updated`, l'attribut a déjà été persisté → `isDirty` retourne `false`. Utilisé `wasChanged` (parité avec le pattern Laravel). Idem pour `getOriginal('name')` qui reste accessible post-save.
3. **`recordRenommeAdRenamed()` : branche d'échec AD supprimée**. Refactor : on écrit `$ws->name = $role` dans la transaction PG (root-cause fix). Le job AD est async derrière → si AD échoue (3 retries), divergence transitoire jusqu'à retry final ou alerte (trade-off identique au pattern WorkstationGroupAdSyncJob en prod). Conséquence : le status `'ERREUR renommage AD impossible'` (progress 40%) n'est plus jamais atteint depuis cette méthode (mais reste possible théoriquement si un futur refactor synchrone le réactive). Le test feature `it_logs_warning_on_ad_rename_failure` est supprimé (n'a plus de sens — il était paramétré sur `renameComputer()` retournant false). Le test `it_invokes_ad_rename_on_renomme_ret_0` est conservé mais asserte désormais `$ws->name === 'pc-renamed-01'` (l'écriture PG est devenue le contrat observable).
4. **`AdMachineManager` paramètre conservé pour compat ABI** dans `recordRenommeAdRenamed($workstation, $adManager, $role, $ip)` : retiré le code qui l'utilise mais gardé la signature (call-sites iPXE dans `app/Ipxe/Controllers/...` consomment cette signature ; ne pas la casser dans cette story). Annoté `unset($adManager)` + commentaire.
5. **Factory `WorkstationFactory` créée** (n'existait pas) + ajout `use HasFactory` dans `Workstation` model. Permet `Workstation::factory()->create()` dans les futurs tests.

**Tests adaptés (modifs liées) :**

- `tests/Unit/Ipxe/Services/WorkstationEnrollmentServiceTest.php` :
  - `it_renames_workstation_when_uuid_known_and_new_name_unique` : `renameComputer` et `registerHardware` passent de `once()` à `never()`.
  - `it_returns_renamed_with_ad_result_false_on_ad_failure` : test supprimé (la branche d'échec AD côté service n'existe plus — le job async gère).
- `tests/Unit/Ipxe/Services/WindowsPostInstallTrackerTest.php` :
  - `it_records_renomme_ad_renamed_success` : `renameComputer` passe à `shouldNotReceive`, ajout assert `$fresh->name === 'pc-renamed-01'`.
  - `it_records_renomme_ad_renamed_failure` et `..._throws_handled_as_failure` : tests supprimés (branche disparue).
  - `it_records_renomme_ad_renamed_with_empty_role` : assertion supplémentaire `$fresh->name === 'PC-101'` (préservation).
  - `it_preserves_protected_status_on_renomme_ad_renamed` : `renameComputer` → `shouldNotReceive`.
- `tests/Feature/Ipxe/IpxeWindowsActionEndpointPostOobeTest.php` :
  - `it_invokes_ad_rename_on_renomme_ret_0` : `renameComputer` → `shouldNotReceive`, ajout assert nom PG écrit.
  - `it_logs_warning_on_ad_rename_failure` : test supprimé (branche disparue).

**Résultats tests :**

- **Tests ciblés story 4.9** (Job + Observer + Tracker + Enrollment + Endpoint post-OOBE) : **84/84 verts** (run combiné), durée ~5s.
- **Suite complète** : voir Change Log pour le compte final. **0 régression introduite** par la story (les régressions LdapRecordException préexistantes sur env local sans LDAP sont identiques en baseline et post-changements ; les 39 régressions transitoires détectées en cours d'itération ont été éliminées par le guard `environment('testing')`).

**Hors scope explicitement respecté :**
- Pas de portage complet `create` en LdapRecord pur (D2 maintenu).
- Pas de cleanup attributs AD legacy non utilisés (`location`, `description`, …).
- Pas de mécanisme de réconciliation périodique PG↔AD (l'écriture est garantie, pas la détection a posteriori).

**À faire en QA / VM (différable) :**
- Scénarios 1.1 → 1.6 du runbook `docs/qa/domains/ad-sync.md` (création/rename/status/delete + withoutSync + rollback).
- Vérification empirique préservation `objectGUID` + `netbootGUID` post-rename (preuve VM 2026-05-28 déjà capturée dans la story).

### Change Log

| Date | Auteur | Type | Description |
|---|---|---|---|
| 2026-05-28 | dev (opus-4.7-1M) | Feature | Story 4.9 : implémentation pattern observer-driven AD pour `Workstation`. Création `WorkstationAdSyncJob` (4 actions, modrdn LDAP rename, idempotence). Réécriture `WorkstationObserver` (created/updated/deleting hooks, suppression hooks pivot D4). Enregistrement observer dans `AppServiceProvider::boot()` (guard `testing`). Refactor `WindowsPostInstallTracker::recordRenommeAdRenamed()` (root-cause fix divergence PG↔AD) + `WorkstationEnrollmentService` branche rename (D7). Cleanup call-sites pivot dans `Workstation` + `WorkstationGroup` models. Création `WorkstationFactory`. Tests : 13 unit job + 6 feature observer (19/19 verts). Adaptation 4 tests existants (2 fichiers Unit Ipxe + 1 Feature endpoint). Runbook QA `docs/qa/domains/ad-sync.md` créé (6 scénarios manuels). |

### File List

**Créés :**
- `app/Jobs/AdSync/WorkstationAdSyncJob.php`
- `database/factories/WorkstationFactory.php`
- `tests/Unit/Jobs/AdSync/WorkstationAdSyncJobTest.php`
- `tests/Feature/Observers/WorkstationObserverTest.php`
- `docs/qa/domains/ad-sync.md`

**Modifiés :**
- `app/Observers/WorkstationObserver.php` — réécriture complète (hooks Eloquent + suppression pivot D4).
- `app/Providers/AppServiceProvider.php` — ajout `Workstation::observe(...)` (guardé `!environment('testing')`).
- `app/Models/Workstation.php` — `use HasFactory`, retrait import `WorkstationObserver`, simplification `attachGroups/detachGroups/syncGroups` (D4).
- `app/Models/WorkstationGroup.php` — retrait import `WorkstationObserver`, simplification `attachWorkstations/detachWorkstations/syncWorkstations` (D4).
- `app/Ipxe/Services/WindowsPostInstallTracker.php` — refactor `recordRenommeAdRenamed()` (root-cause fix : écriture PG via Eloquent, observer prend le relais AD).
- `app/Ipxe/Services/WorkstationEnrollmentService.php` — suppression appels manuels `renameComputer` + `registerHardware` post-rename (D7).
- `tests/Unit/Ipxe/Services/WorkstationEnrollmentServiceTest.php` — adaptation tests rename (shouldNotReceive).
- `tests/Unit/Ipxe/Services/WindowsPostInstallTrackerTest.php` — adaptation 4 tests `recordRenommeAdRenamed*`.
- `tests/Feature/Ipxe/IpxeWindowsActionEndpointPostOobeTest.php` — adaptation test renomme ret=0, suppression test branche failure.
- `_bmad-output/implementation-artifacts/sprint-status.yaml` — status `4-9` → `review`.

---

## Recommandation Modèle Dev

**Recommandation : `opus`.**

**Justification :**

Cette story cumule **quatre facteurs de complexité indépendants** qui, ensemble, justifient sans ambiguïté `opus` :

1. **LdapRecord (lib externe) sur un cas non trivial — modrdn + multi-attributs.** Le code paraît court (10 lignes de `handleRename`), mais la subtilité du `rename('CN='.$new)` (vs `cn = $new + save`), l'ordre d'écriture des attributs `sAMAccountName` / `dnsHostName` / `servicePrincipalName`, et la préservation `objectGUID`/`netbootGUID` ne sont pas documentés de manière unifiée dans LdapRecord. Le test VM 2026-05-28 a validé une recette précise — le dev doit la reproduire EXACTEMENT sans dévier. Sonnet a tendance à « simplifier » ce genre de séquence (« je vais utiliser `$m->update([…])` directement ») et à casser silencieusement la préservation GUID.

2. **Nouveau pattern observer-driven généralisé — risque de boucle observer → job → observer.** Le piège du `handleCreate()` qui réécrit `ad_guid` est un cas où l'observer se redéclenche sur lui-même. Le bypass via `WorkstationObserver::withoutSync(fn() => …)` est crucial et facile à oublier. De même, le choix `isDirty` vs `wasChanged` dans `updated()` n'est pas trivial. Ce sont des bugs qui passent inaperçus en dev (« ça compile, le test naïf passe ») mais qui font diverger PG/AD en prod ou créent des dispatches en boucle.

3. **Refactor de call-sites iPXE post-audit Epic 17 — zone sensible.** L'audit `audit-applications-scripts.md` (Epic 17 récent) a explicitement marqué `WindowsPostInstallTracker` et `WorkstationEnrollmentService` comme du code critique du flow PXE/Windows. Modifier `recordRenommeAdRenamed()` à l'intérieur d'une transaction PG qui appelle `saveWithProtected()` demande de comprendre exactement ce que cette méthode fait (potentiellement `withoutEvents()` qui désactiverait l'observer — auquel cas le refactor est inopérant). Sonnet rate ce genre de subtilité parce qu'il ne lit pas assez en profondeur les méthodes adjacentes.

4. **Tests d'intégration AD multi-niveaux** : DirectoryEmulator (unit), `Bus::fake` (observation), VM manuel (e2e). Pour chaque niveau, les tests doivent **réellement** valider le comportement (ex. AC9 demande de vérifier que `objectGUID` est strictement identique avant/après rename — c'est de la vérification d'effet de bord d'une lib LDAP, pas un assert trivial). Sonnet produit des tests qui « passent » mais qui n'asserent pas le bon truc (ex. ne lit pas `objectGUID` avant/après, se contente d'asserter que `rename()` n'a pas lancé d'exception).

**Comparaison directe avec 4.2 et 4.3 (toutes deux faites en `opus` confirmé) :** cette story 4.9 a un périmètre plus restreint en nombre de fichiers (~6 fichiers modifiés vs ~15 en 4.2) MAIS un risque par fichier plus élevé (LDAP / observer / iPXE). Le critère « interactions réseau + comportement asynchrone » de 4.2 s'applique ici aussi (queue async + retry). Le critère « audit contradictoire de code existant » s'applique également (Tâche 1 : auditer 5 fichiers avant de coder).

**Conclusion : `opus`** — pas de doute possible. Sonnet ferait probablement marcher 80% du scope mais casserait silencieusement la préservation GUID ou créerait une boucle observer, ce qui détruirait la confiance dans le pattern qu'on installe pour de bon.

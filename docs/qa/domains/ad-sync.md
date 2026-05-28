# QA — Synchronisation AD

Runbook QA pour la **synchronisation Active Directory** des objets PG (Workstation, WorkstationGroup, UserGroup, AppProfile, Shortcut) via le pattern observer-driven.

## Pré-requis communs

- VM accessible : `ssh -i ~/.ssh/id_se4fs_vm root@192.168.122.50`
- AD `localdev.fr` opérationnel (samba-tool + ldapsearch fonctionnels sur la VM)
- Worker queue actif : `php artisan queue:work --tries=3` (ou exécution `--once` ponctuelle)
- Une machine de test pré-existante en AD ET en PG (ex. `CN=test-4-9,OU=computers,DC=localdev,DC=fr` + `Workstation::where('name','test-4-9')`)

---

## Section 1 — Workstation (Story 4.9)

Le pattern : toute écriture Eloquent sur `Workstation` déclenche `WorkstationObserver` → dispatch async `WorkstationAdSyncJob` qui exécute l'action AD correspondante.

**Pourquoi rename via LdapRecord modrdn (et pas samba-tool computer rename)** :

| Attribut | `samba-tool computer rename` | `LdapRecord modrdn` (Story 4.9) |
|---|---|---|
| `cn` / `name` / DN | Renommé (delete + recreate) | Renommé (modrdn pur) |
| `sAMAccountName` | Renommé | Renommé manuellement |
| `objectGUID` | **DÉTRUIT** (nouveau GUID) | **PRÉSERVÉ** |
| `netbootGUID` | **DÉTRUIT** | **PRÉSERVÉ** |
| `dNSHostName` | À reposer manuellement | À reposer manuellement |
| `servicePrincipalName` | À reposer | À reposer |

Validation expérimentale : VM `/vm` 2026-05-28 sur `CN=28051115,OU=ULIS,OU=computers,DC=localdev,DC=fr`. Avant/après le modrdn : `objectGUID` et `netbootGUID` strictement identiques (vérification `ldapsearch -b ... '+'`).

### Scénario 1.1 — Rename Workstation préserve objectGUID + netbootGUID

**Pré-condition :** une `Workstation` PG existe avec `name = 'test-4-9'`, un compte AD `CN=test-4-9,...` existe également avec un `netbootGUID` posé (via `AdMachineManager::registerHardware`).

1. **Capture initiale** :
   ```bash
   ssh /vm 'ldapsearch -Y EXTERNAL -H ldapi:// -b "CN=test-4-9,OU=computers,DC=localdev,DC=fr" "+" objectGUID netbootGUID sAMAccountName dNSHostName' | tee /tmp/before.ldif
   ```
2. **Déclencher le rename** depuis Laravel :
   ```bash
   ssh /vm 'cd /var/www/sambaedu-reload && php artisan tinker --execute="
     \App\Models\Workstation::where(\"name\",\"test-4-9\")->first()->update([\"name\" => \"test-4-9-renamed\"]);
   "'
   ```
3. **Exécuter la queue** (job async) :
   ```bash
   ssh /vm 'cd /var/www/sambaedu-reload && php artisan queue:work --once'
   ```
4. **Capture finale** :
   ```bash
   ssh /vm 'ldapsearch -Y EXTERNAL -H ldapi:// -b "CN=test-4-9-renamed,OU=computers,DC=localdev,DC=fr" "+" objectGUID netbootGUID sAMAccountName dNSHostName' | tee /tmp/after.ldif
   ```
5. **Asserts** :
   - `objectGUID` strictement identique avant/après (`diff <(grep objectGUID /tmp/before.ldif) <(grep objectGUID /tmp/after.ldif)` → vide).
   - `netbootGUID` strictement identique avant/après.
   - `sAMAccountName == TEST-4-9-RENAMED$`.
   - `dNSHostName == test-4-9-renamed.localdev.fr`.
   - `servicePrincipalName` contient `HOST/test-4-9-renamed` ET `HOST/test-4-9-renamed.localdev.fr`.
   - `Workstation::where('name', 'test-4-9-renamed')->exists() === true` côté PG.

### Scénario 1.2 — Changement de status PG → userAccountControl AD

**Mapping (D5) :**
- `status = 'active'` ou `'protected'` → `userAccountControl = 4096`.
- `status = 'inactive'` → `userAccountControl = 4098` (4096 + ACCOUNTDISABLE).
- Autre valeur → throw `InvalidArgumentException` (alerte ops, retry x3).

1. Depuis tinker : `Workstation::where(...)->update(['status' => 'inactive'])`.
2. `php artisan queue:work --once`.
3. `ldapsearch -b "CN=test-4-9-renamed,..." userAccountControl` → doit afficher `4098`.
4. Repasser à `active` → `4096`.

### Scénario 1.3 — Suppression Workstation supprime le compte AD

1. `Workstation::where('name', 'test-4-9-renamed')->first()->delete();`
2. `php artisan queue:work --once`.
3. `ldapsearch -b "CN=test-4-9-renamed,..."` → doit renvoyer `No such object` (compte absent).

### Scénario 1.4 — Création Workstation pose ad_guid PG

1. `Workstation::create(['name' => 'pc-fresh-1', 'uuid' => '...', 'mac' => '...', 'status' => 'active'])`.
2. `php artisan queue:work --once`.
3. `Workstation::where('name', 'pc-fresh-1')->first()->ad_guid` → non null (GUID lu via `MachineModel::getConvertedGuid()`).
4. `ldapsearch -b "CN=pc-fresh-1,..." objectGUID` → doit matcher `ad_guid` PG (modulo format).
5. Si `uuid` fourni → `netbootGUID` doit être posé (`AdMachineManager::registerHardware`).

### Scénario 1.5 — withoutSync ne déclenche pas le job AD

Cas légitime : seeders, imports CSV, sync inverse depuis AD.

```php
\App\Observers\WorkstationObserver::withoutSync(function () {
    \App\Models\Workstation::create([
        'name' => 'pc-seed-1',
        'uuid' => '...',
        'mac' => '...',
        'status' => 'active',
    ]);
});
```

Vérifier dans `storage/logs/laravel.log` qu'aucun message `[WorkstationAdSyncJob]` n'apparaît pour ce poste. La queue (`jobs` table) ne doit pas non plus contenir de job pour ce ws.

### Scénario 1.6 — Rollback nom original (recette inverse)

Après scénario 1.1, restaurer l'état initial pour pouvoir rejouer la batterie :
```php
\App\Models\Workstation::where('name', 'test-4-9-renamed')->update(['name' => 'test-4-9']);
```
+ `queue:work --once`.

### Post-correctifs & non-régressions

- **Story 4.9** : root-cause fix de la divergence PG↔AD dans le flow iPXE Windows post-install (`WindowsPostInstallTracker::recordRenommeAdRenamed`). Avant : rename AD via samba-tool MAIS pas d'écriture `$ws->name` en PG → AD avance, PG reste sur l'ancien nom. Après : `$ws->name = $role` dans la transaction PG, observer dispatche le job AD async (modrdn).
- **Décision D7** : suppression de `registerHardware` post-rename dans `WorkstationEnrollmentService` — modrdn préserve `netbootGUID`, plus besoin de le reposer.

### Checklist rapide

- [ ] 1.1 — Rename : `objectGUID` et `netbootGUID` préservés strictement
- [ ] 1.2 — Status inactive → UAC 4098, active → UAC 4096
- [ ] 1.3 — Delete → compte AD absent
- [ ] 1.4 — Create → ad_guid posé en PG
- [ ] 1.5 — withoutSync ne dispatche aucun job
- [ ] 1.6 — Rollback nom original OK

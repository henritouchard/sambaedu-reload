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

> Smoke automatisable : voir Story 3.1 § "Smoke test à exécuter quand VM up"
> dans `_bmad-output/implementation-artifacts/3-1-ipxe-service-core.md`.

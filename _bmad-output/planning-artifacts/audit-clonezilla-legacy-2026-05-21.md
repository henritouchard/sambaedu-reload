# Audit Clonezilla / Maintenance / Rescue legacy — Story 3.7

> Livrable T0 (pre-flight) de la Story 3.7 « Clonage et Maintenance ».
> Recensement exhaustif des fichiers PHP legacy concernés par clonezilla,
> sysrescuecd, gparted, hdt, memtest, double-boot, et le mode maintenance
> iPXE — préparation du portage natif SE5 (Epic 3 — last story).

**Date d'exécution** : 2026-05-21
**Auteur** : claude-opus-4-7[1m] (Story 3.7, Phase T0 — création SM)
**HEAD host** : `726e1ff Feat - 3.6 handle iso windows`
**Worktree** : `ipxe` (`/home/htouchard/code/irundo/codebase/ipxe`)
**Statut** : livrable

---

## 1. Méthodologie

Scan exhaustif de `sambaedu/ipxe/` + `legacy/modules/ipxe/` :

```bash
ls sambaedu/ipxe/{clonezilla,clonezilla_menu,clonage,maintenance,sysrescuecd,gparted,hdt,memtest86plus,double,enleveparc}.php
ls sambaedu/ipxe/{clonezilla,sysrescuecd}/
ls sambaedu/ipxe/actions/{clonezilla_live,clz_*,rescuecd}.php
```

Croisement avec : `routes/web.php` (routes natives Epic 3 déjà posées),
`config/sambaedu.php:42-56` (`blocked_legacy_routes`),
`app/Ipxe/Enums/IpxeAdminAction.php` (whitelist enum déjà étendue à 19 cases),
`resources/views/ipxe/{menu,actions}/*.blade.php` (templates iPXE 3.1-3.5).

---

## 2. Inventaire fichiers legacy concernés

### 2.1 Menus iPXE haut niveau (entry points firmware LAN)

| # | Fichier legacy | LOC | Fonction | Statut SE5 |
|---|---|---|---|---|
| L1 | `sambaedu/ipxe/maintenance.php` | 63 | Menu iPXE « Maintenance pour {ip} » avec items `rescuecd`, `winpe`, `clonezilla_live` + retour admin + shell + boot disk | **Portée 3.2** (endpoint natif `/ipxe/maintenance` + template `ipxe.menu.maintenance.blade.php`) — items actuels : `rescuecd`, `winpe`, `factory_reset`. **Manquant 3.7** : item `clonezilla` (vers sous-menu) + items `gparted`/`hdt`/`memtest` (déjà dans admin.php legacy ? non, voir L11). |
| L2 | `sambaedu/ipxe/clonezilla_menu.php` | 80 | Sous-menu iPXE Clonezilla avec 7 items : `clonezilla_prevert` (restauration partimg), `live32`/`live64` (livecd), `sav_locale{32,64}` (sauvegarde sda1→sda2), `rest_locale{32,64}` (restauration sda2→sda1) | **Non porté** — entry point à porter en 3.7 (endpoint natif `/ipxe/clonezilla-menu` + template `ipxe.menu.clonezilla.blade.php`). |
| L3 | `sambaedu/ipxe/clonage.php` | 111 | Menu interactif de clonage multicast en direct (rôle modele/clone) — appelle `fetch_action()` legacy, set_action() avec rôle, lance `rescuecd` ou `default` script via chain `action.php` | **HORS-SCOPE 3.7** — workflow stateful multi-poste UDP-multicast = story Phase 3 (cf. décision D3 ci-dessous). |
| L4 | `sambaedu/ipxe/clonezilla.php` | 7 | Boot direct clonezilla minimal — kernel + initrd + boot | **Portage trivial 3.7** — équivalent `ipxe.actions.clonezilla_live` (existe déjà partiellement, voir L13). |

### 2.2 Sous-actions iPXE servies sous `actions/`

| # | Fichier legacy | LOC | Fonction | Statut SE5 |
|---|---|---|---|---|
| L5 | `sambaedu/ipxe/actions/clonezilla_live.php` | 7 | Boot Clonezilla Live CD manuel — kernel `bin/clonezilla/vmlinuz` + initrd + `imgargs` interactif | **Manquant 3.7** — template `ipxe.actions.clonezilla_live.blade.php` à créer (enum case `Clonezilla` ou `ClonezillaLive`). |
| L6 | `sambaedu/ipxe/actions/clz_sav_sda1_sur_sda2.php` | 9 | Boot Clonezilla en mode automatique « sauvegarde locale sda1 → sda2 » (`ocs-sr -q2 -j2 -z1 ...saveparts savesda1 sda1`) + autorun via `clonezilla/autorun.php` | **Manquant 3.7** — template `ipxe.actions.clonezilla_save_sda1_sda2.blade.php` (enum case `ClonezillaSaveSda1Sda2`). |
| L7 | `sambaedu/ipxe/actions/clz_rest_sda2_sur_sda1.php` | 9 | Boot Clonezilla en mode automatique « restauration sda2 → sda1 » (`ocs-sr -e1 auto -e2 -r -j2 ... restoreparts savesda1 sda1`) + autorun | **Quasi-double** de `factory_reset.blade.php` (3.2) : même cmdline, même cible. **Décision 3.7** : `factory_reset` (existant) est le portage natif iso de `clz_rest_sda2_sur_sda1` → pas de double. |
| L8 | `sambaedu/ipxe/actions/rescuecd.php` | 10 | Boot SystemRescueCD avec autorun vers `sysrescuecd/autorun.php` (mode workflow stateful pour clonage UDP) | **Quasi-portée 3.2** dans `ipxe.actions.rescuecd.blade.php` — MAIS l'autorun stateful (workflow multi-étapes) n'est PAS porté. **Décision D3** : 3.7 NE porte PAS l'autorun workflow (= scope Phase 3 dédié multicast). |

### 2.3 Actions outils diagnostic (gparted, hdt, memtest)

| # | Fichier legacy | LOC | Fonction | Statut SE5 |
|---|---|---|---|---|
| L9  | `sambaedu/ipxe/gparted.php` | 7 | Boot direct GParted Live | **Manquant 3.7** — template `ipxe.actions.gparted.blade.php` (enum case `Gparted`). |
| L10 | `sambaedu/ipxe/hdt.php` | 6 | Boot Hardware Detection Tool (diagnostic matériel) | **Manquant 3.7** — template `ipxe.actions.hdt.blade.php` (enum case `Hdt`). |
| L11 | `sambaedu/ipxe/memtest86plus.php` | 6 | Boot Memtest86+ (test mémoire) | **Manquant 3.7** — template `ipxe.actions.memtest86plus.blade.php` (enum case `Memtest86plus`). |

### 2.4 Workflow stateful sysrescuecd / clonezilla (HORS-SCOPE 3.7)

| # | Fichier legacy | LOC | Fonction | Statut SE5 |
|---|---|---|---|---|
| L12 | `sambaedu/ipxe/sysrescuecd/action.php` | 471 | Génération script bash dynamique pour clonage UDP-multicast multi-postes (rôles modele/clone, étapes init-modele → send-partitions → send-sfdisk → send-mbr → emit-partitions → change-hostname → verify-hostname → init-clone → receive-mbr → receive-sfdisk → receive-partitions, partclone + udp-sender/udp-receiver, gestion ports dynamiques par `id` clone-group) | **HORS-SCOPE 3.7** — décision D3 (volume + complexité + faible usage terrain → story Phase 3 dédiée). |
| L13 | `sambaedu/ipxe/sysrescuecd/autorun.php` | 56 | Bash autorun loop (curl action.php → eval → ret → loop) | **HORS-SCOPE 3.7** — D3. |
| L14 | `sambaedu/ipxe/sysrescuecd/progress.php` | 36 | Reporting progression UDP clonage | **HORS-SCOPE 3.7** — D3. |
| L15 | `sambaedu/ipxe/clonezilla/action.php` | 367 | Variante alternative du workflow stateful (idem L12 mais base clonezilla-livecd vs sysrescuecd) | **HORS-SCOPE 3.7** — D3. |
| L16 | `sambaedu/ipxe/clonezilla/autorun.php` | 55 | Bash autorun loop pour workflow clonezilla stateful | **HORS-SCOPE 3.7** — D3. |

### 2.5 Items menu admin déjà gérés ailleurs (référence pour cohérence)

| # | Fichier legacy | LOC | Fonction | Statut SE5 |
|---|---|---|---|---|
| L17 | `sambaedu/ipxe/double.php` | 38 | Toggle dual-boot Linux/Windows (`add_dual_boot`/`remove_dual_boot` legacy) | **HORS-SCOPE 3.7** — fonctionnalité admin enrollment, hors périmètre clonage/maintenance/rescue. Si terrain demande → story Phase 3 dédiée. |
| L18 | `sambaedu/ipxe/enleveparc.php` | 76 | Retrait poste d'un parc | **Portée 3.3** (endpoint `/ipxe/enrollment/parc-remove`). |

### 2.6 Item legacy menu admin pointant vers clonage (référence)

Le menu admin legacy (`sambaedu/ipxe/admin.php:104`) expose :
```php
$ipxe .= "item --key c clonage  (c) Clonage en direct des postes \n";
```
**Décision 3.7** : l'item `(c) clonage` du menu admin natif (`admin.blade.php`) n'est **PAS** ajouté en 3.7 (workflow stateful = D3 = Phase 3). Le sous-menu maintenance natif gagnera un item `(c) clonezilla` qui pointe vers le sous-menu clonezilla natif (port de L2).

---

## 3. Synthèse : ce qui est à porter en 3.7

### 3.1 À porter natif 3.7

| Type | Élément | Cible SE5 |
|---|---|---|
| Endpoint LAN | `/ipxe/clonezilla-menu` | `IpxeClonezillaMenuController` + template `ipxe.menu.clonezilla.blade.php` |
| Template action | `clonezilla_live` | `ipxe.actions.clonezilla_live.blade.php` (boot livecd manuel) |
| Template action | `clonezilla_save_sda1_sda2` | `ipxe.actions.clonezilla_save_sda1_sda2.blade.php` (sauvegarde locale auto) |
| Template action | `clonezilla_restore_sda2_sda1` | **REDONDANT avec `factory_reset` existant** — alias enum (1 case enum supplémentaire pointant vers le MÊME template `factory_reset` OU template dédié avec sémantique non-destructive). Décision D2 = case `ClonezillaRestoreSda2Sda1` mais template séparé `clonezilla_restore_sda2_sda1.blade.php` (sémantique distincte : factory_reset = destructive, clz_rest = restauration normale opérateur). |
| Template action | `gparted` | `ipxe.actions.gparted.blade.php` |
| Template action | `hdt` | `ipxe.actions.hdt.blade.php` |
| Template action | `memtest86plus` | `ipxe.actions.memtest86plus.blade.php` |
| Enum case | `IpxeAdminAction::Clonezilla{Live,SaveSda1Sda2,RestoreSda2Sda1}`, `Gparted`, `Hdt`, `Memtest86plus` | +6 cases enum (whitelist sécurité critique D9 iso 3.2) |
| Item menu existant | `admin.blade.php` → ajouter `(c) clonezilla` (vers sous-menu) + `(t) tools` (gparted/hdt/memtest sous-menu OU items inline) | Modification mineure `ipxe.menu.admin.blade.php` |
| Item menu existant | `maintenance.blade.php` → ajouter item `(c) clonezilla` (vers sous-menu clonezilla — parité legacy maintenance.php:34) + items `(g) gparted` + `(h) hdt` + `(t) memtest` | Modification mineure `ipxe.menu.maintenance.blade.php` |
| Sous-menu nouveau | `ipxe.menu.clonezilla.blade.php` | 4 items : `clonezilla_live`, `clonezilla_save_sda1_sda2`, `clonezilla_restore_sda2_sda1` (non destructif), retour maintenance, exit |
| Cleanup catchall | `blocked_legacy_routes` += entries pour `^ipxe/clonezilla\.php$`, `^ipxe/clonezilla_menu\.php$`, `^ipxe/maintenance\.php$`, `^ipxe/gparted\.php$`, `^ipxe/hdt\.php$`, `^ipxe/memtest86plus\.php$`, `^ipxe/actions/(clonezilla_live\|clz_sav_sda1_sur_sda2\|clz_rest_sda2_sur_sda1\|rescuecd\|gparted\|hdt\|memtest86plus)\.php$`, `^ipxe/Win10/win_iso\.php$` (cleanup différé fin Epic 3 = 3.7) | Config-only, pas de code |
| Cleanup catchall | Idem côté `direct_legacy_routes` : retirer `^/ipxe/` une fois 3.7 livré ? **NON** — laisser le fallback pour les assets statiques (`png/`, `bin/`, `Win10/sources/`, `diconf/`, etc.). | Pas de modif |

### 3.2 Hors-scope 3.7 (différé Phase 3 = nouvelle story dédiée)

| Type | Élément | Justification |
|---|---|---|
| Workflow stateful | `sysrescuecd/{action,autorun,progress}.php` (562 LOC cumulé) | Workflow UDP-multicast multi-poste avec gestion d'état modele→clones par groupe-id, ports dynamiques, partclone, gestion sfdisk/mbr/partitions. Complexité élevée + faible usage terrain (clonage de masse rare en école). **Story Phase 3 dédiée** : `4-X-clonage-udp-multicast-multi-poste`. |
| Workflow stateful | `clonezilla/{action,autorun}.php` (422 LOC) | Idem L12-L13. |
| Item menu clonage | `clonage.php` (111 LOC) | Entry point de l'option `(c) clonage en direct` admin.php legacy. Dépend du workflow stateful (D3). |
| Toggle dual-boot | `double.php` (38 LOC) | Hors périmètre clonage/maintenance/rescue. |

---

## 4. Comportement attendu (parité legacy)

### 4.1 Menu maintenance étendu (port 3.7 sur base 3.2)

L'admin tape `(m)` dans `/ipxe/admin` → arrive sur `/ipxe/maintenance`. Items existants (3.2) :
- `(c) rescuecd` (déjà fonctionnel)
- `(w) winpe` (déjà fonctionnel)
- `(f) factory_reset` (déjà fonctionnel)
- `(s) shell`, `(r) retour`, `(x) exit`

**Nouveau 3.7** :
- `(z) clonezilla` → chain vers `/ipxe/clonezilla-menu` (nouveau sous-menu)
- `(g) gparted` → chain vers `/ipxe/action/gparted`
- `(h) hdt` → chain vers `/ipxe/action/hdt`
- `(t) memtest` → chain vers `/ipxe/action/memtest86plus`

(touches `(z)` `(g)` `(h)` `(t)` — choisies pour ne pas conflicter avec `(c)` `(w)` `(f)` `(s)` `(r)` `(x)`).

### 4.2 Sous-menu clonezilla nouveau (port 3.7 de L2)

L'admin tape `(z) clonezilla` dans `/ipxe/maintenance` → arrive sur `/ipxe/clonezilla-menu`. Items :
- `(l) clonezilla_live` → boot Clonezilla LiveCD manuel (`/ipxe/action/clonezilla_live`)
- `(s) save` → sauvegarde sda1 → sda2 auto (`/ipxe/action/clonezilla_save_sda1_sda2`)
- `(r) restore` → restauration sda2 → sda1 non destructive (`/ipxe/action/clonezilla_restore_sda2_sda1`)
- `(b) back` → retour `/ipxe/maintenance`
- `(x) exit` → boot disque dur

> **Note importante** : le legacy `clonezilla_menu.php` expose 7 items (32-bits + 64-bits + restauration partimg `clonezilla_prevert`). En 2026, le 32-bits Clonezilla n'a quasiment plus d'usage (postes legacy x86 30+ ans). **Décision D2 = SE5 fusionne 32+64 en variante 64-bits seule** (cohérent UEFI x86_64 + alignement parc moderne). Si terrain remonte un besoin 32-bits sur poste très ancien → story dédiée.

### 4.3 Actions outils diagnostic (port 3.7 trivial de L9-L11)

`/ipxe/action/gparted` → boot GParted Live (kernel + initrd minimal).
`/ipxe/action/hdt` → boot HDT (Hardware Detection Tool).
`/ipxe/action/memtest86plus` → boot Memtest86+.

Pas de paramètres dynamiques, pas d'autorun, pas de session_ipxe — boot statique pur (template Blade ~5 lignes chacun).

---

## 5. Pré-requis VM (à valider par Henri en T0.5)

| Pré-requis | Path attendu côté VM | Si absent |
|---|---|---|
| Binaire Clonezilla vmlinuz | `/var/www/html/bin/clonezilla/vmlinuz` (ou path config `ipxe.actions.os_url`) | Boot Clonezilla impossible — Henri à provisioner (paquet `sambaedu` côté VM) |
| Binaire Clonezilla initrd | `/var/www/html/bin/clonezilla/initrd.img` | idem |
| Binaire Clonezilla filesystem | `/var/www/html/bin/clonezilla/filesystem.squashfs` | idem |
| Binaire GParted vmlinuz | `/var/www/html/bin/gparted/vmlinuz` (à confirmer chemin réel iso-legacy) | Boot GParted impossible |
| Binaire HDT | `/var/www/html/bin/hdt/hdt.img0` (à confirmer chemin réel iso-legacy) | Boot HDT impossible |
| Binaire Memtest | `/var/www/html/bin/memtest86+/memtest86+.bin` (à confirmer chemin réel) | Boot Memtest impossible |
| Partition sda2 pour sauvegarde locale | postes terrain partitionnés sda1=système / sda2=backup partimg | Mode `save/restore local` ne fonctionnera pas → message d'erreur Clonezilla |

**Vérification T0.5 Henri** : lister `ls /var/www/html/bin/{clonezilla,gparted,hdt,memtest86+}/` côté VM pour confirmer chemins exacts AVANT lancement dev. Mettre à jour `config/ipxe.php` section `actions.tools_paths` (D8) si chemins divergent.

---

## 6. Conclusions

- **À porter natif 3.7** : 4 nouveaux templates `actions/*.blade.php` + 1 nouveau template menu `clonezilla.blade.php` + 2 templates menus modifiés (admin + maintenance) + 6 cases enum + 1 nouveau controller `IpxeClonezillaMenuController` + 1 nouvelle route `/ipxe/clonezilla-menu` + extension `IpxeMenuKind` (+2 cases) + extension `config/ipxe.php` section `clonezilla` + section `tools` (gparted/hdt/memtest paths) + 6 entrées `blocked_legacy_routes` (cleanup catchall fin Epic 3).
- **HORS-SCOPE 3.7** : workflows stateful UDP-multicast (sysrescuecd + clonezilla) = ~1000 LOC cumulé = story Phase 3 dédiée.
- **Charge cadrée** : 2-3 jours dev (volume modéré, peu de complexité, pattern 3.2 reproductible).
- **Modèle recommandé** : `sonnet` (refonte iPXE templates + cleanup catchall, complexité modérée — pas de sécurité critique nouvelle vs 3.5/3.6).

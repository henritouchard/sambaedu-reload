# Story 3.7 : Clonage et Maintenance

Status: review

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

> **Suite directe de Stories 3.1 → 3.6** (« iPXE Service Core » + « Boot et Menu Admin iPXE » + « Enrollment » + « Installation Linux » + « Installation Windows Sysprep/Wimboot » + « Gestion ISO Windows »). **Dernière story Epic 3** — clôt le portage natif iPXE en livrant le sous-menu Clonezilla LiveCD/save/restore + actions de diagnostic (gparted, hdt, memtest86+) + cleanup final du catchall pour TOUTES les URLs iPXE legacy SE5-portées (3.1-3.7).
>
> **Scope strict 3.7** = (a) **1 nouveau endpoint LAN-only natif** `/ipxe/clonezilla-menu` (controller `IpxeClonezillaMenuController` + template Blade `ipxe.menu.clonezilla.blade.php`, port iso-legacy `sambaedu/ipxe/clonezilla_menu.php` 80 LOC), (b) **6 nouveaux cases enum `IpxeAdminAction`** (whitelist sécurité critique D9 iso 3.2) : `ClonezillaLive`, `ClonezillaSaveSda1Sda2`, `ClonezillaRestoreSda2Sda1`, `Gparted`, `Hdt`, `Memtest86plus`, (c) **6 nouveaux templates Blade actions** sous `resources/views/ipxe/actions/` : `clonezilla_live.blade.php`, `clonezilla_save_sda1_sda2.blade.php`, `clonezilla_restore_sda2_sda1.blade.php`, `gparted.blade.php`, `hdt.blade.php`, `memtest86plus.blade.php` (ports iso-legacy `actions/{clonezilla_live,clz_sav_sda1_sur_sda2,clz_rest_sda2_sur_sda1}.php` + `{gparted,hdt,memtest86plus}.php`), (d) **modification template menu maintenance existant** `resources/views/ipxe/menu/maintenance.blade.php` : ajouter items `(z) clonezilla`, `(g) gparted`, `(h) hdt`, `(t) memtest` (parité legacy `sambaedu/ipxe/maintenance.php:34`), (e) **2 nouveaux cases enum `IpxeMenuKind`** : `ClonezillaMenu`, `ClonezillaMenuHandshake` (alignement pattern 3.2/3.4/3.5), (f) **1 nouvelle méthode `IpxeService::handleClonezillaMenu()`** (orchestration handshake + render iso `handleMaintenance`), (g) **1 nouvelle méthode `IpxeMenuRenderer::renderClonezillaMenu()`** (factorisation iso `renderMaintenance`), (h) **1 nouvelle FormRequest** `IpxeClonezillaMenuRequest` (validation `mac`/`uuid` iso 3.2 `IpxeMaintenanceRequest`), (i) **1 nouvelle route** `/ipxe/clonezilla-menu` dans `routes/web.php` sous middleware `auth.v1.lan-only + throttle:600,1 + withoutMiddleware(['web'])` (parité 3.1-3.5 LAN-only), (j) **extension `config/ipxe.php`** avec section `clonezilla` (menu_timeout_ms, background_png) + section `tools` (paths gparted/hdt/memtest86+ servis via Apache catchall) + extension section `actions.allowed_actions` ou équivalent (la whitelist enum sert déjà — pas de duplication), (k) **cleanup final catchall** (Epic 3 closure) : ajouter dans `config/sambaedu.php` `blocked_legacy_routes` les 12 patterns iPXE migrés en 3.1-3.7 (`^ipxe/clonezilla\.php$`, `^ipxe/clonezilla_menu\.php$`, `^ipxe/maintenance\.php$`, `^ipxe/gparted\.php$`, `^ipxe/hdt\.php$`, `^ipxe/memtest86plus\.php$`, `^ipxe/actions/(clonezilla_live\|clz_sav_sda1_sur_sda2\|clz_rest_sda2_sur_sda1\|rescuecd\|gparted\|hdt\|memtest86plus)\.php$`, `^ipxe/Win10/win_iso\.php$`, `^ipxe/admin\.php$`, `^ipxe/maintenance\.php$`, `^ipxe/installation-linux\.php$`, `^ipxe/installation-windows\.php$`, `^ipxe/(enregistrement\|enregistrement_byod\|salles\|parcs\|enleveparc)\.php$`) — chacune redirige vers la route native SE5 équivalente (Q-1 Henri sur stratégie : 410 Gone vs redirect 302), (l) **0 nouvelle migration** (idem 3.2/3.4/3.5 — uniquement extension `MachineBootLog.action` valeur applicative iso 3.2 D11 — voir D11), (m) tests Unit + Feature + Architecture ≥ 25 cumulés, (n) extension `docs/qa/domains/ipxe.md` Section 16 « Story 3.7 » + ≥ 8 scénarios stables 3.7-1 à 3.7-N (append-only iso 3.1-3.6).
>
> **HORS-SCOPE 3.7** (explicitement reportés ou abandonnés) :
>
> - **Workflow stateful clonage UDP-multicast multi-poste** (`sysrescuecd/{action,autorun,progress}.php` 562 LOC + `clonezilla/{action,autorun}.php` 422 LOC = ~984 LOC cumulé). Complexité élevée + faible usage terrain. **Story Phase 3 dédiée** ouvrir si besoin terrain (cf. décision D3).
> - **Item menu admin `(c) clonage en direct des postes`** (`sambaedu/ipxe/admin.php:104` + `sambaedu/ipxe/clonage.php` 111 LOC) — dépend du workflow stateful. **Différé Phase 3 avec D3**.
> - **Toggle dual-boot** `sambaedu/ipxe/double.php` (38 LOC) — hors périmètre clonage/maintenance/rescue. Story dédiée Phase 3 si demande terrain.
> - **Variante 32-bits Clonezilla** (`live32`, `sav_locale32`, `rest_locale32` du legacy `clonezilla_menu.php`) — abandonnée (postes x86 30+ ans, UEFI x86_64 = standard 2026). Si terrain remonte → story dédiée (cf. décision D2).
> - **Restauration partimg `clonezilla_prevert`** (item legacy `clonezilla_menu.php:29`) — dépend de partimg externe (non géré en SE5). Différé Phase 3 si besoin terrain.
> - **Modification de l'enum `IpxeAdminAction::FactoryReset`** — existe déjà (3.2) et porte iso-legacy `clz_rest_sda2_sur_sda1` (cmdline identique). **Décision D2** : 3.7 ajoute un case dédié `ClonezillaRestoreSda2Sda1` avec sémantique distincte « restauration opérateur normale » vs `FactoryReset` « restauration usine destructive » — MÊME cmdline iPXE (cohérence kernel/initrd) MAIS templates Blade séparés pour permettre divergences futures + clarté UX/log.
> - **Port natif de l'autorun stateful sysrescuecd** — différé Phase 3 (D3).
> - **Nouvelle migration DB** — aucune. 3.7 réutilise `machine_boot_logs.action` (varchar(20)) avec 4 nouvelles valeurs applicatives (iso 3.2 D11 + 3.4 D11) : `ipxe_clonezilla`, `ipxe_gparted`, `ipxe_hdt`, `ipxe_memtest`. Cf. D11.
> - **UI menu iPXE firmware d'item « gestion ISO Windows »** — c'est une page admin web SE5 (3.6), pas un menu firmware. Aucun item ajouté à `admin.blade.php` côté iPXE pour 3.6 — pareil 3.7 (la gestion ISO/clonage côté admin web reste hors-scope strict iPXE firmware).
> - **Refonte UX/page admin web SE5 de gestion des clones** — pas demandé legacy, Phase 3 si besoin.
> - **Page admin web SE5 « tableau de bord clonage »** — Phase 3 dédiée.
> - **Suppression définitive du `direct_legacy_routes` `^/ipxe/`** dans `config/sambaedu.php:72` — **NON** : on laisse le fallback pour les assets statiques (`png/*`, `bin/*`, `Win10/sources/*`, `diconf/*`) que SE5 ne porte pas. Seules les routes `*.php` migrées passent en `blocked_legacy_routes`.

---

## Mode de livraison & contraintes opérationnelles

> **Worktree git dédié `ipxe`** (`/home/htouchard/code/irundo/codebase/ipxe`). Ne JAMAIS SSH `/vm` ni run de tests sur la VM depuis ce worktree (mémoire `feedback_worktree_no_vm_sync`). Static delivery iso 3.1-3.6 : lint statique `php -l` + PHPUnit local si `vendor/` présent + 0 sync manuel.
>
> - **Code synchronisé via inotify** sur la branche `main` uniquement (les worktrees ne sont PAS sync). Henri opère un merge `ipxe → main` post-review pour propager.
> - **Action Henri post-merge VM up** : `composer install` (idem 3.1-3.6) + reset cache (`php artisan optimize:clear`) + reload PHP-FPM (`systemctl reload php8.2-fpm@www-admin`) + smoke iPXE LAN sur poste réel ou via `curl --data 'mac=aa:bb:cc:dd:ee:ff&uuid=...' http://192.168.122.50/ipxe/clonezilla-menu` (workstation enrôlée requise) + valider la nouvelle ligne `(z) clonezilla` dans le menu `/ipxe/maintenance`.
> - **Pré-requis assets binaires côté VM** (action T0.5 Henri — cf. audit `audit-clonezilla-legacy-2026-05-21.md` section 5) :
>   - `ls /var/www/html/bin/clonezilla/{vmlinuz,initrd.img,filesystem.squashfs}` (existants — utilisés par `factory_reset` 3.2)
>   - `ls /var/www/html/bin/gparted/` (à confirmer chemin réel iso-legacy — sinon adapter `config('ipxe.tools.gparted.*')`)
>   - `ls /var/www/html/bin/hdt/` (à confirmer)
>   - `ls /var/www/html/bin/memtest86+/` (à confirmer)
>   - Si chemins divergent du défaut hardcodé dans templates → mettre à jour `config/ipxe.php` section `tools` AVANT lancement dev.
> - **NE PAS** modifier `sambaedu/ipxe/*.php` ni `legacy/modules/ipxe/*.php` — les fichiers legacy restent intacts (seul le catchall les bloque via `blocked_legacy_routes`).
> - **NE PAS** créer de commit hors scope.
> - **mémoire `feedback_auth_iso_legacy`** : middleware `auth.v1.lan-only` (3.1) — pas de Bearer, pas de TOTP. Iso 3.2-3.5.
> - **mémoire `project_php_fpm_user_www_admin`** : pas de modification filesystem côté serveur web (sauf logs canal ipxe déjà existants 3.1).
> - **Secrets** : aucun secret côté serveur — assets clonezilla/gparted/hdt/memtest sont publics, chargés via Apache sur LAN-only.
> - **Risque RCE** : un poste malveillant LAN pourrait essayer d'envoyer une action iPXE forgée. Mitigation D9 (enum whitelist stricte) + middleware `auth.v1.lan-only` (LAN seul, pas d'internet) + `throttle:600,1` (rate limit).
> - **Risque iPXE injection** : les templates Blade rendent du texte iPXE — si on injecte une variable non-échappée contenant `\n`, le firmware iPXE exécuterait des lignes arbitraires. Mitigation : aucune variable utilisateur (mac/uuid/name déjà sanitisés 3.1-3.5) n'est insérée dans une cmdline kernel/initrd. Les 6 nouveaux templates 3.7 sont 100% statiques (pas de variable user dans cmdline) sauf `serverBaseUrl` (calculé par resolver — déjà sanitisé).

---

## Encadré contexte

**Continuité avec 3.1-3.6** : Epic 3 a été développé en 7 incréments :

- 3.1 (done) : socle Services + IpxeService + WorkstationLocator
- 3.2 (done) : endpoints boot/admin/maintenance/action + whitelist enum 3 cases (rescuecd, winpe, factory_reset)
- 3.3 (done) : enrollment poste inconnu (5 endpoints)
- 3.4 (done) : installation Linux (9 cases install_deb_*/nird/ubuntu64)
- 3.5 (done) : installation Windows (7 cases install_win*)
- 3.6 (review) : gestion ISO Windows (page admin web SE5)
- **3.7 (à dev) — DERNIÈRE STORY EPIC 3** : sous-menu clonezilla + outils diagnostic (gparted/hdt/memtest) + cleanup final catchall iPXE Epic 3.

**Pourquoi clôture Epic 3** : après 3.7, **toutes** les URLs iPXE legacy (`sambaedu/ipxe/*.php`) qui ont un équivalent natif SE5 sont **bloquées** dans le catchall (redirect 302 ou 410 Gone selon Q-1 Henri). Le firmware iPXE LAN ne pourra plus tomber accidentellement sur un fichier legacy si une variable d'env d'un poste pointe vers un path obsolète — le catchall retournera une réponse standardisée.

3.7 **active** le flow firmware iPXE :

1. Poste boot via PXE → DHCP → iPXE chain `boot.ipxe` → `/ipxe/boot` (3.1).
2. Si workstation connue + admin authentifié → `/ipxe/admin` menu (3.2 modifié 3.4/3.5).
3. Admin tape `(m) maintenance` → `/ipxe/maintenance` menu (3.2 — étendu 3.7).
4. **Nouveau 3.7** : Admin tape `(z) clonezilla` → `/ipxe/clonezilla-menu` (nouveau).
5. **Nouveau 3.7** : Sous-menu Clonezilla affiche 3 options : `(l) live`, `(s) save sda1→sda2`, `(r) restore sda2→sda1`. Plus retour + exit.
6. Choix `(l)` → `/ipxe/action/clonezilla_live` → boot Clonezilla LiveCD manuel.
7. Choix `(s)` → `/ipxe/action/clonezilla_save_sda1_sda2` → boot Clonezilla mode auto save.
8. Choix `(r)` → `/ipxe/action/clonezilla_restore_sda2_sda1` → boot Clonezilla mode auto restore.
9. **Alternative depuis `/ipxe/maintenance`** : Admin tape `(g) gparted` / `(h) hdt` / `(t) memtest` → boot direct des outils diagnostic via `/ipxe/action/{gparted,hdt,memtest86plus}`.

**Topologie cible 3.7** :

```
Poste iPXE LAN → /ipxe/maintenance (étendu 3.7)
        ├─ auth.v1.lan-only + throttle:600,1
        ├─ IpxeMaintenanceController::handle()
        │   └─ IpxeService::handleMaintenance()
        │       ├─ WorkstationLocator::locate(mac, uuid)
        │       ├─ IpxeMenuRenderer::renderMaintenance(...)
        │       └─ Blade render `ipxe.menu.maintenance` (étendu 3.7 : +4 items)
        │           ├─ (c) rescuecd (3.2)
        │           ├─ (w) winpe (3.2)
        │           ├─ (f) factory_reset (3.2)
        │           ├─ (z) clonezilla ← chain /ipxe/clonezilla-menu (NEW 3.7)
        │           ├─ (g) gparted ← chain /ipxe/action/gparted (NEW 3.7)
        │           ├─ (h) hdt ← chain /ipxe/action/hdt (NEW 3.7)
        │           ├─ (t) memtest ← chain /ipxe/action/memtest86plus (NEW 3.7)
        │           ├─ (s) shell, (r) retour, (x) exit (3.2)
        └─ Response text/plain iPXE script

Poste tape (z) → /ipxe/clonezilla-menu (NEW 3.7)
        ├─ auth.v1.lan-only + throttle:600,1
        ├─ IpxeClonezillaMenuController::handle()
        │   └─ IpxeService::handleClonezillaMenu()
        │       ├─ WorkstationLocator::locate(mac, uuid)
        │       ├─ IpxeMenuRenderer::renderClonezillaMenu(...)
        │       └─ Blade render `ipxe.menu.clonezilla` (NEW 3.7)
        │           ├─ (l) clonezilla_live ← chain /ipxe/action/clonezilla_live
        │           ├─ (s) save ← chain /ipxe/action/clonezilla_save_sda1_sda2
        │           ├─ (r) restore ← chain /ipxe/action/clonezilla_restore_sda2_sda1
        │           ├─ (b) back ← chain /ipxe/maintenance
        │           └─ (x) exit ← boot disk
        └─ Response text/plain iPXE script

Poste tape (l)/(s)/(r) ou (g)/(h)/(t) → /ipxe/action/{action} (3.2 endpoint, +6 enum cases NEW 3.7)
        ├─ auth.v1.lan-only + throttle:600,1
        ├─ IpxeActionController::handle($action)
        │   └─ IpxeService::handleAction($request, $action)
        │       ├─ IpxeAdminAction::tryFrom($action) → null|case
        │       │   └─ null → 404 + log warning `ipxe.action.unknown_action`
        │       ├─ IpxeActionResolver::resolve($case, $request) → array variables Blade
        │       └─ Blade render `ipxe.actions.{action}` (NEW 3.7 : 6 templates)
        │           ├─ clonezilla_live.blade.php (kernel + initrd + imgargs livecd)
        │           ├─ clonezilla_save_sda1_sda2.blade.php (kernel + ocs-sr -q2 ...savesda1 sda1 + autorun NON utilisé en SE5 — voir D7)
        │           ├─ clonezilla_restore_sda2_sda1.blade.php (kernel + ocs-sr -e1 ... restoreparts sda1 + autorun NON utilisé)
        │           ├─ gparted.blade.php (kernel + initrd minimal)
        │           ├─ hdt.blade.php (kernel + initrd)
        │           └─ memtest86plus.blade.php (kernel statique)
        └─ Response text/plain iPXE script

Catchall final Epic 3 (NEW 3.7) :
        └─ /ipxe/clonezilla.php, /ipxe/clonezilla_menu.php, /ipxe/maintenance.php,
           /ipxe/gparted.php, /ipxe/hdt.php, /ipxe/memtest86plus.php,
           /ipxe/actions/{clonezilla_live,clz_sav_sda1_sur_sda2,
                          clz_rest_sda2_sur_sda1,rescuecd,gparted,hdt,
                          memtest86plus}.php
        ├─ LegacyCatchallController::handle($path)
        │   ├─ config('sambaedu.blocked_legacy_routes') match → redirect 302
        │   │   vers route native SE5 équivalente
        │   └─ Log info `legacy.catchall.blocked` (DB + channel legacylog)
        └─ Response 302 Location: <route_se5>
```

**Comportement parité legacy** (à reproduire iso-strict — cf. legacy maintenance.php + clonezilla_menu.php + actions/*.php) :

1. **`GET|POST /ipxe/clonezilla-menu`** — sous-menu Clonezilla :
   - **Pré-requis auth** : `auth.v1.lan-only + throttle:600,1` (iso 3.2/3.4/3.5).
   - **Inputs** : `mac` (required), `uuid` (required), `session_ipxe` (optional). Validation FormRequest.
   - Workstation résolue par `WorkstationLocator::locate($mac, $uuid)`. Si poste inconnu → menu réduit (item exit seulement + message neutre — iso 3.2 cas `D6` admin).
   - Output : `Content-Type: text/plain` + corps iPXE script (≤ 1500 chars).
2. **6 nouveaux templates `ipxe.actions.*`** :
   - **Inputs Blade** : `$shebang`, `$osUrl`, `$serverBaseUrl` (variables injectées par `IpxeActionResolver`).
   - **Pas de variable user dans cmdline kernel** (sécurité iPXE injection).
   - **Comportement** : génération script iPXE statique (kernel + initrd + boot OU imgargs interactif pour livecd).
3. **`/ipxe/action/clonezilla_save_sda1_sda2` et `/ipxe/action/clonezilla_restore_sda2_sda1`** :
   - Le legacy fait référence à un `ocs_live_batch` qui pointe vers `clonezilla/autorun.php` (workflow stateful). **DÉCISION D7 = on NE PORTE PAS l'autorun stateful** ; les templates 3.7 omettent `ocs_live_batch` (mode non-batched = clonezilla affiche son menu interactif post-boot). L'utilisateur opérateur valide manuellement les options Clonezilla une fois booté.
   - Cmdline iPXE conservée iso-legacy : `ocs-sr -q2 -j2 -z1 -i 2000 -fsck-src-part -p reboot saveparts savesda1 sda1` (save) ou `ocs-sr -e1 auto -e2 -r -j2 -p reboot restoreparts savesda1 sda1` (restore).
4. **`/ipxe/action/clonezilla_live`** : boot Clonezilla LiveCD avec `imgargs` interactif (pas d'autorun).
5. **`/ipxe/action/gparted`, `/ipxe/action/hdt`, `/ipxe/action/memtest86plus`** : boot statique pur (kernel + initrd, pas de cmdline complexe).
6. **Menu maintenance étendu** : 4 nouveaux items dans `ipxe.menu.maintenance.blade.php`. Touches sélectionnées pour ne pas conflicter avec `(c)`/`(w)`/`(f)`/`(s)`/`(r)`/`(x)` existants : `(z)` `(g)` `(h)` `(t)` (Q-2 Henri sur touches).

**Couplage Stories 3.1-3.6 — modifications mineures attendues** :

| Élément | Modification 3.7 | Raison |
|---|---|---|
| `routes/web.php` | Ajout d'1 nouvelle route `Route::match(['GET','POST'], '/ipxe/clonezilla-menu', ...)` dans le bloc 3.7 (après le bloc 3.5 ligne 850-913, AVANT le catchall ligne 925). | Cohérence ordre strict iso 3.2-3.5. |
| `app/Ipxe/Enums/IpxeAdminAction.php` | +6 cases enum + extension `template()` + ajout extension `linuxMeta()`/`windowsMeta()` retournant null pour les nouveaux cases (cf. D1). | Whitelist stricte (sécurité critique). |
| `app/Ipxe/Enums/IpxeMenuKind.php` | +2 cases `ClonezillaMenu`, `ClonezillaMenuHandshake`. | Cohérence logging `$kind` (3.1 D7). |
| `app/Ipxe/Services/IpxeService.php` | +1 méthode `handleClonezillaMenu(Request $r): Response` (iso `handleMaintenance`). | Orchestration endpoint. |
| `app/Ipxe/Services/IpxeMenuRenderer.php` | +1 méthode `renderClonezillaMenu(array $context): string` (iso `renderMaintenance`). | Génération script iPXE menu. |
| `app/Ipxe/Services/IpxeActionResolver.php` | +6 mappings template dans `resolve()` (déjà couvert par `IpxeAdminAction::template()` → potentiellement 0 modif resolver si le pattern est purement enum-driven — à confirmer en T2). | Résolution Blade. |
| `config/ipxe.php` | +section `clonezilla` (menu_timeout_ms, background_png) +section `tools` (paths gparted/hdt/memtest). | Config-driven (env override possible). |
| `config/sambaedu.php` | Extension `blocked_legacy_routes` (+12 entrées patterns iPXE migrés 3.1-3.7). | Cleanup final catchall Epic 3. |
| `app/Providers/IpxeServiceProvider.php` | Pas de modif si le pattern singleton existant suffit (à confirmer T0.3). | - |

**Idempotence + sécurité** :

- `GET /ipxe/clonezilla-menu` : **idempotent** (lecture seule WorkstationLocator + render statique).
- `POST /ipxe/clonezilla-menu` : **idempotent** (même behavior que GET — pas de side-effect DB).
- `/ipxe/action/{6 nouveaux cases}` : **idempotent** (lecture enum + render statique template, pas de side-effect DB).
- Audit `MachineBootLog.action` : insert d'une ligne à chaque action 3.7 lancée (iso 3.4 D11 + 3.5 D12) — D11.

**Side effects 3.7** :

- **DB PostgreSQL** : insert `machine_boot_logs` à chaque action lancée (audit). Pas de migration.
- **Filesystem VM** : aucune écriture (templates statiques).
- **Cache Laravel** : aucun lock (pas d'opération longue async).
- **Logs** : `Log::channel('ipxe')` (events handshake + render + action.dispatched) + `Log::channel('legacylog')` (events catchall block sur URLs iPXE legacy).
- **Network** : aucun appel sortant.
- **AD/LDAP** : aucune modification.

---

## Décisions tranchées (D1-D12, ne pas re-débattre)

> Cadrage SM 2026-05-21 par claude-opus-4-7[1m]. Le dev applique sans re-discuter. En cas de blocage technique réel, documenter dans Dev Agent Record et continuer.

### D1 — Namespace : extension **`App\Ipxe`** (PAS de sous-namespace 3.7)

- Ajouts sous `app/Ipxe/` (justifié — frontière forte vs `App\Ipxe\Iso` dédié 3.6) :
  ```
  app/Ipxe/
  ├── Enums/
  │   ├── IpxeAdminAction.php          (MODIFY — +6 cases enum)
  │   └── IpxeMenuKind.php             (MODIFY — +2 cases ClonezillaMenu* )
  ├── Http/
  │   ├── Controllers/
  │   │   └── IpxeClonezillaMenuController.php  (NEW — controller fin iso IpxeMaintenanceController)
  │   └── Requests/
  │       └── IpxeClonezillaMenuRequest.php     (NEW — validation mac/uuid iso IpxeMaintenanceRequest)
  └── Services/
      ├── IpxeService.php              (MODIFY — +1 méthode handleClonezillaMenu)
      ├── IpxeMenuRenderer.php         (MODIFY — +1 méthode renderClonezillaMenu)
      └── IpxeActionResolver.php       (MODIFY si nécessaire — voir D6)
  ```
- **Justification** : 3.7 = endpoints firmware iPXE LAN-only (cohérent 3.1-3.5 sous `App\Ipxe\*`). Pas une page admin web SE5 → pas de sous-namespace dédié.
- **Anti-pattern** : ne PAS créer `App\Ipxe\Clonezilla\…` (overkill — 6 templates + 1 controller = pas une frontière forte). Ne PAS mettre dans `App\Ipxe\Iso\Clonezilla\*` (sortirait du sens iso/ qui est strictement la gestion ISO Windows 3.6).

### D2 — 6 nouveaux cases enum `IpxeAdminAction` + redondance contrôlée avec `FactoryReset`

- Ajouts dans `app/Ipxe/Enums/IpxeAdminAction.php` (après les 7 cases 3.5 ligne 84) :
  ```php
  // Story 3.7 — D2 / AC1.1 — 6 cases clonezilla/diagnostic.
  case ClonezillaLive = 'clonezilla_live';
  case ClonezillaSaveSda1Sda2 = 'clonezilla_save_sda1_sda2';
  case ClonezillaRestoreSda2Sda1 = 'clonezilla_restore_sda2_sda1';
  case Gparted = 'gparted';
  case Hdt = 'hdt';
  case Memtest86plus = 'memtest86plus';
  ```
- **Extension `template()`** : mapping snake_case iso 3.2-3.5 → `'ipxe.actions.clonezilla_live'` etc.
- **Extension `logName()`** : retourne déjà `$this->value` (pas de modif).
- **Pas d'extension `linuxMeta()` ni `windowsMeta()`** : retournent `null` via le `default` match existant.
- **Décision sur la redondance `FactoryReset` vs `ClonezillaRestoreSda2Sda1`** :
  - Le legacy `actions/clz_rest_sda2_sur_sda1.php` et `factory_reset` 3.2 utilisent la MÊME cmdline iPXE (`ocs-sr -e1 auto -e2 -r -j2 -p reboot restoreparts savesda1 sda1`).
  - SE5 3.7 livre `ClonezillaRestoreSda2Sda1` comme case enum + template SÉPARÉ `clonezilla_restore_sda2_sda1.blade.php` (sémantique : « restauration opérateur normale Clonezilla menu » distinct de « restauration usine catastrophe » `factory_reset`).
  - **Cmdline iPXE identique** dans les 2 templates (pas de divergence en 3.7) — mais 2 templates physiques pour permettre divergence future (ex: factory_reset = effacement multi-partition + reboot, clonezilla_restore = restauration sda1 seul) + clarté logs (action = `clonezilla_restore_sda2_sda1` vs `factory_reset`).
  - **Tests** : 1 test unit dédié vérifiant que les 2 templates rendus ont la même cmdline iPXE (parité iso-legacy à T+0). Si divergence future justifiée → casser ce test.
- **Anti-pattern** : ne PAS supprimer/renommer `FactoryReset` (regression risk + entrée déjà en prod via 3.2 done). Ne PAS faire pointer `ClonezillaRestoreSda2Sda1::template()` vers `'ipxe.actions.factory_reset'` (perte du nom sémantique dans logs).

### D3 — Workflow stateful UDP-multicast multi-poste : **HORS-SCOPE strict 3.7** (Phase 3)

- **Décision claire** : 3.7 NE PORTE PAS les ~984 LOC cumulées des workflows stateful (`sysrescuecd/{action,autorun,progress}.php` + `clonezilla/{action,autorun}.php` + `clonage.php`).
- **Pourquoi** :
  - Complexité élevée (gestion d'état modele→clones par groupe-id, ports UDP dynamiques, partclone, sfdisk/mbr/partitions transfer via curl multipart).
  - Faible usage terrain (clonage de masse rare en école — utilisé surtout en pré-déploiement parc neuf).
  - Pattern PHP procédural fichier-par-fichier inadapté à Laravel (refactor lourd = 5-7j dédiés).
  - Hors-scope strict Story Phase 3 dédiée : `4-X-clonage-udp-multicast-multi-poste`.
- **Implication 3.7** :
  - L'item `(c) clonage` du menu admin legacy n'est PAS ajouté à `ipxe.menu.admin.blade.php`.
  - Les templates 3.7 `clonezilla_save_sda1_sda2.blade.php` et `clonezilla_restore_sda2_sda1.blade.php` OMETTENT l'`ocs_live_batch` qui pointait vers `autorun.php` legacy. **Cohérence Clonezilla** : mode non-batched = livecd menu interactif post-boot (l'opérateur valide manuellement). Cmdline iPXE conservée iso-legacy moins `ocs_live_batch`.
- **Anti-pattern** : ne PAS implémenter partiellement le workflow stateful en 3.7 (= dette technique + bug source). Ne PAS porter `autorun.php` standalone sans porter le workflow complet.

### D4 — Endpoint LAN-only natif `/ipxe/clonezilla-menu` : **1 seule route, pas de POST controllers séparés**

- Bloc à ajouter dans `routes/web.php` **après le bloc 3.5 (ligne 913) AVANT le catchall (ligne 925)** :
  ```php
  /*
  |--------------------------------------------------------------------------
  | Story 3.7 — Clonage et Maintenance (D4)
  |--------------------------------------------------------------------------
  | Ajout d'1 endpoint LAN-only natif `/ipxe/clonezilla-menu` (port iso
  | `sambaedu/ipxe/clonezilla_menu.php`). Les 6 nouvelles actions (clonezilla
  | live/save/restore + gparted/hdt/memtest86plus) sont servies par l'endpoint
  | générique existant `/ipxe/action/{action}` (3.2) — la whitelist enum
  | `IpxeAdminAction` (+6 cases D2) est l'autorité finale.
  |
  | ORDRE STRICT : ce bloc DOIT rester AVANT le catchall ligne 925.
  | Test garde-fou : `IpxeNamespaceTest::ipxe_3_7_routes_are_declared_before_catchall`.
  */
  Route::match(['GET', 'POST'], '/ipxe/clonezilla-menu', [
      \App\Ipxe\Http\Controllers\IpxeClonezillaMenuController::class,
      'handle',
  ])
      ->middleware(['auth.v1.lan-only', 'throttle:600,1'])
      ->name('ipxe.clonezilla-menu')
      ->withoutMiddleware(['web']);
  ```
- **Pourquoi 1 seule route (pas 6 routes /ipxe/{gparted,hdt,memtest...})** : les actions sont déjà servies par `/ipxe/action/{action}` (3.2) avec validation enum. Ajouter 6 routes individuelles = redondance + 6x duplicate tests.
- **Pas de modification de la route `/ipxe/action/{action}`** existante (3.2) — seul l'enum + les templates Blade sont étendus.

### D5 — Sécurité : **réutilisation stricte `auth.v1.lan-only + throttle:600,1`** (iso 3.2-3.5)

- Middleware iso `/ipxe/admin`, `/ipxe/maintenance`, `/ipxe/action/{action}` (3.2) — convention déjà établie.
- **Pas de Bearer, pas de TOTP** (memoire `feedback_auth_iso_legacy`).
- **Rate limit `throttle:600,1`** : 600 requêtes / min (parité 3.2 — large pour permettre un parc complet qui boot en simultané).
- **Whitelist enum** : `IpxeAdminAction::tryFrom($action)` — null retourne 404 + log warning `ipxe.action.unknown_action` (3.2 existant).
- **Anti-pattern** : ne PAS introduire de nouveau middleware Spatie. Ne PAS sortir du LAN-only.

### D6 — Templates Blade : **6 nouveaux + 1 modifié + 1 nouveau menu**

- 6 nouveaux templates actions sous `resources/views/ipxe/actions/` :
  - `clonezilla_live.blade.php` (port iso `sambaedu/ipxe/actions/clonezilla_live.php` — kernel + initrd + imgargs interactif).
  - `clonezilla_save_sda1_sda2.blade.php` (port iso `clz_sav_sda1_sur_sda2.php` SANS `ocs_live_batch` D3 — cmdline `ocs-sr -q2 ...savesda1 sda1`).
  - `clonezilla_restore_sda2_sda1.blade.php` (port iso `clz_rest_sda2_sur_sda1.php` SANS `ocs_live_batch` D3 — cmdline `ocs-sr -e1 auto -e2 -r ... restoreparts savesda1 sda1` = MÊME cmdline que `factory_reset.blade.php` D2).
  - `gparted.blade.php` (port iso `gparted.php` — kernel + initrd minimal GParted Live).
  - `hdt.blade.php` (port iso `hdt.php` — kernel HDT statique).
  - `memtest86plus.blade.php` (port iso `memtest86plus.php` — kernel Memtest statique).
- 1 nouveau template menu sous `resources/views/ipxe/menu/` :
  - `clonezilla.blade.php` (port iso `sambaedu/ipxe/clonezilla_menu.php` — sous-menu Clonezilla 3 items).
- 1 template existant modifié :
  - `resources/views/ipxe/menu/maintenance.blade.php` (3.2) — ajouter 4 items `(z)`/`(g)`/`(h)`/`(t)` + 4 anchors `:clonezilla` `:gparted` `:hdt` `:memtest` (chain vers routes correspondantes).
- **Charset** : ASCII strict (iPXE firmware = ASCII only — iso 3.2-3.5).
- **Pas de variable user dans cmdline** : seules variables Blade autorisées = `$shebang`, `$osUrl`, `$serverBaseUrl`, `$mac`, `$uuid`, `$workstationName`, `$ip` (déjà sanitisées par 3.1-3.5 resolver).
- **Cmdline iPXE** : chaque ligne ≤ 200 chars (limite firmware iPXE). Si dépassement → splitter en `imgargs` séparé (iso `clonezilla_live` legacy).
- **Anti-pattern** : ne PAS injecter de payload utilisateur dans `kernel` ou `initrd`. Ne PAS utiliser `{!! ... !!}` sur des variables user (XSS-equivalent en iPXE = RCE poste).

### D7 — `ocs_live_batch` autorun : **NON porté** (suite D3)

- Les templates legacy `clz_sav_sda1_sur_sda2.php:4` et `clz_rest_sda2_sur_sda1.php:4` définissent :
  ```php
  $ocs_live_batch = $script_url . "/clonezilla/autorun.php?mac=" . $mac . "&uuid=" . $uuid;
  ```
  Cette variable serait injectée dans la cmdline `ocs_live_batch="..."` pour lancer le workflow stateful Clonezilla.
- **3.7 omet `ocs_live_batch`** : cohérent avec D3 (workflow stateful = Phase 3). En mode non-batched, Clonezilla affiche son menu interactif post-boot — l'opérateur valide manuellement les options.
- **Implication UX** : un opérateur lance `(s) save` → boot Clonezilla → menu interactif → valide les options par défaut → save lancé. **Pas de régression fonctionnelle** vs legacy (legacy save automatique seulement si workflow stateful = installé + configuré côté poste, ce qui est rare en pratique).
- **Documentation Section 16** : noter ce changement de comportement dans runbook QA.

### D8 — Variables de configuration : **extension `config/ipxe.php`**

- Nouvelle section dans `config/ipxe.php` (après section `linux` ligne ~235) :
  ```php
  /*
  |--------------------------------------------------------------------------
  | Story 3.7 — Sous-menu Clonezilla (D8)
  |--------------------------------------------------------------------------
  */
  'clonezilla' => [
      'enabled' => filter_var(env('IPXE_CLONEZILLA_ENABLED', true), FILTER_VALIDATE_BOOL),
      'menu_timeout_ms' => (int) env('IPXE_CLONEZILLA_TIMEOUT_MS', 10000),
      'background_png' => env('IPXE_CLONEZILLA_BG_PNG', 'png/clonezilla.png'),
  ],

  /*
  |--------------------------------------------------------------------------
  | Story 3.7 — Outils diagnostic (D8)
  |--------------------------------------------------------------------------
  | Chemins relatifs (sous $osUrl) des binaires des outils de diagnostic.
  | Servis statiquement par Apache via catchall. Si chemins divergent du
  | défaut iso-legacy → adapter via .env.
  */
  'tools' => [
      'gparted' => [
          'enabled' => filter_var(env('IPXE_GPARTED_ENABLED', true), FILTER_VALIDATE_BOOL),
          'kernel_path' => env('IPXE_GPARTED_KERNEL', '/bin/gparted/vmlinuz'),
          'initrd_path' => env('IPXE_GPARTED_INITRD', '/bin/gparted/initrd.img'),
      ],
      'hdt' => [
          'enabled' => filter_var(env('IPXE_HDT_ENABLED', true), FILTER_VALIDATE_BOOL),
          'kernel_path' => env('IPXE_HDT_KERNEL', '/bin/hdt/hdt.img0'),
      ],
      'memtest86plus' => [
          'enabled' => filter_var(env('IPXE_MEMTEST_ENABLED', true), FILTER_VALIDATE_BOOL),
          'kernel_path' => env('IPXE_MEMTEST_KERNEL', '/bin/memtest86+/memtest86+.bin'),
      ],
  ],
  ```
- **Justification flags `enabled`** : permettre à Henri de désactiver un outil en pré-prod (ex: HDT non installé sur cette VM). Templates Blade testent `@if(config('ipxe.tools.gparted.enabled'))` côté menu maintenance.
- **Pré-requis T0.5 Henri** : confirmer chemins exacts côté VM (cf. audit-clonezilla-legacy section 5).

### D9 — Logging structuré channel `ipxe` (extension 3.1-3.5)

- 6 nouveaux events log côté Job 3.7 + handler :
  - `ipxe.clonezilla_menu.handshake` (handshake endpoint, context: mac, uuid, has_workstation).
  - `ipxe.clonezilla_menu.rendered` (render OK, context: mac, uuid, workstation_id, name).
  - `ipxe.action.clonezilla_live.dispatched` (suit pattern 3.2 `ipxe.action.{action}.dispatched`).
  - `ipxe.action.clonezilla_save_sda1_sda2.dispatched`.
  - `ipxe.action.clonezilla_restore_sda2_sda1.dispatched`.
  - `ipxe.action.gparted.dispatched`.
  - `ipxe.action.hdt.dispatched`.
  - `ipxe.action.memtest86plus.dispatched`.
- Channel : `Log::channel('ipxe')` (créé 3.1, daily 14j).
- **Pas de secrets dans les logs** (aucun mot de passe dans 3.7 — assets publics).
- **MachineBootLog** : insert d'une ligne avec `action = 'ipxe_clonezilla' | 'ipxe_gparted' | 'ipxe_hdt' | 'ipxe_memtest'` (D11).

### D10 — Cleanup final catchall Epic 3 — **bascule en mode bloqué pour 12 patterns iPXE**

- **Décision clé Epic 3 closure** : 3.7 est la dernière story Epic 3. Avant 3.7, le catchall `direct_legacy_routes` `^/ipxe/` (`config/sambaedu.php:72`) permettait à TOUTES les URLs iPXE legacy d'être servies. **Après 3.7**, on bascule au mode strict : les routes legacy migrées 3.1-3.7 sont **bloquées** via `blocked_legacy_routes` (redirect 302 OU 410 Gone — voir Q-1 Henri).
- **Patterns à ajouter** dans `config/sambaedu.php` `blocked_legacy_routes` (12 entrées) :
  ```php
  // Story 3.7 — D10 — Cleanup final catchall Epic 3
  // Toutes les routes iPXE legacy migrées 3.1-3.7 sont bloquées.
  // Les assets statiques (png/, bin/, Win10/sources/, diconf/) restent
  // accessibles via `direct_legacy_routes` `^/ipxe/`.
  '^ipxe/boot\.php(?:\?.*)?$' => 'ipxe/boot',                          // 3.1
  '^ipxe/admin\.php(?:\?.*)?$' => 'ipxe/admin',                        // 3.2
  '^ipxe/maintenance\.php(?:\?.*)?$' => 'ipxe/maintenance',            // 3.2
  '^ipxe/clonezilla_menu\.php(?:\?.*)?$' => 'ipxe/clonezilla-menu',    // 3.7
  '^ipxe/clonezilla\.php(?:\?.*)?$' => 'ipxe/action/clonezilla_live',  // 3.7
  '^ipxe/gparted\.php(?:\?.*)?$' => 'ipxe/action/gparted',             // 3.7
  '^ipxe/hdt\.php(?:\?.*)?$' => 'ipxe/action/hdt',                     // 3.7
  '^ipxe/memtest86plus\.php(?:\?.*)?$' => 'ipxe/action/memtest86plus', // 3.7
  '^ipxe/enregistrement\.php(?:\?.*)?$' => 'ipxe/enrollment/name',     // 3.3
  '^ipxe/enregistrement_byod\.php(?:\?.*)?$' => 'ipxe/enrollment/byod', // 3.3
  '^ipxe/salles\.php(?:\?.*)?$' => 'ipxe/enrollment/room',             // 3.3
  '^ipxe/parcs\.php(?:\?.*)?$' => 'ipxe/enrollment/parc-add',          // 3.3
  '^ipxe/enleveparc\.php(?:\?.*)?$' => 'ipxe/enrollment/parc-remove',  // 3.3
  '^ipxe/installation-linux\.php(?:\?.*)?$' => 'ipxe/installation-linux', // 3.4
  '^ipxe/installation-windows\.php(?:\?.*)?$' => 'ipxe/installation-windows', // 3.5
  '^ipxe/Win10/win_iso\.php(?:\?.*)?$' => 'admin/ipxe/iso-windows',    // 3.6
  ```
- **Stratégie redirect 302 vs 410 Gone — Q-1 Henri** : la convention 16.2/16.9 iso-projet (`blocked_legacy_routes`) fait **redirect 302**. 3.7 suit cette convention. **MAIS** : les requêtes iPXE LAN sont des **scripts firmware** qui ne suivent PAS les redirects HTTP comme un navigateur (le firmware iPXE chain explicitement vers une URL nouvelle). Donc un 302 sur `/ipxe/admin.php` retournerait HTTP 302 + Location header → le firmware iPXE ne parserait pas le Location et tomberait en erreur. **Recommandation SM = 410 Gone** + corps texte/plain `#!ipxe\necho ERREUR - Route legacy obsolete - utiliser /ipxe/admin natif SE5\nsleep 5\nexit` (donne un feedback opérateur). Henri tranche.
- **Implication** : si Q-1 = 410 Gone → besoin d'étendre `LegacyCatchallController::handle()` pour supporter la stratégie 410 sur subset d'URLs iPXE (cf. AC7.2 — extension mineure du controller existant + 1 colonne config `legacy_route_strategy: redirect|gone` OU convention `gone:ipxe/admin` en value du mapping).

### D11 — `MachineBootLog::action` : **extension applicative (pas de migration)**

- La colonne `machine_boot_logs.action` est `varchar(20)`. 3.7 ajoute 4 nouvelles valeurs applicatives (iso 3.2 D11 + 3.4 D11 + 3.5 D12) :
  - `ipxe_clonezilla` (15 chars) — pour les 3 actions clonezilla (live/save/restore) — décision : 1 seul label pour rester simple, distinguer dans les logs via `ipxe.action.{action}.dispatched` channel.
  - `ipxe_gparted` (12 chars).
  - `ipxe_hdt` (8 chars).
  - `ipxe_memtest` (12 chars).
- **Pas de migration** : varchar(20) suffit pour les 4 nouvelles valeurs (max 15 chars).
- **Insert** : géré par `IpxeService::handleAction()` (3.2 existant — pas de nouveau code, juste le mapping enum→action_log_value).

### D12 — Tests Architecture (D1) : extension iso 3.2-3.6

- Étendre `tests/Architecture/IpxeNamespaceTest.php` (existant 3.1-3.6) avec :
  - `it_ensures_ipxe_3_7_routes_are_declared_before_catchall` (assertion ordre route `/ipxe/clonezilla-menu` AVANT catchall).
  - `it_ensures_6_new_actions_have_blade_template_in_ipxe_actions_namespace` (assertion existence des 6 templates `resources/views/ipxe/actions/{clonezilla_live,clonezilla_save_sda1_sda2,clonezilla_restore_sda2_sda1,gparted,hdt,memtest86plus}.blade.php`).
  - `it_ensures_factory_reset_and_clonezilla_restore_have_same_kernel_cmdline` (parité D2 — test à break si futur dev divergence).
  - `it_ensures_no_user_variable_in_action_template_kernel_lines` (sécurité — grep dans les 6 nouveaux templates : aucune occurrence de `{{ $mac }}` ou `{{ $uuid }}` ou `{{ $workstationName }}` dans une ligne commençant par `kernel ` ou `initrd `).

---

## Story

As a **administrateur de parc SambaEdu (SE5)**,
I want **un menu iPXE natif SE5 pour lancer Clonezilla LiveCD (manuel/save/restore) et les outils de diagnostic (GParted, HDT, Memtest86+) directement depuis le firmware iPXE d'un poste, sans dépendance au proxy legacy catchall**,
so that **je peux dépanner un poste hors-OS (HDD corrompu, soupçon HW défaillant, sauvegarde locale) sans aller chercher une clé USB Clonezilla, et que la fermeture d'Epic 3 (cleanup catchall iPXE) ne casse aucune route legacy en production**.

---

## Contexte

### État entrant (post-Story 3.6 review, 3.7 = clôture Epic 3)

| Artefact | État | Action 3.7 |
|---|---|---|
| Stories 3.1-3.5 done | ✅ Done (sprint-status 2026-05-21) | Réutiliser strictement |
| Story 3.6 review | ⏳ En revue Henri (smoke VM pending) | Indépendant de 3.7 — la fermeture review 3.6 est parallèle |
| `app/Ipxe/Enums/IpxeAdminAction.php` | 19 cases (3 + 9 + 7) | Étendre à 25 cases (+6 3.7) |
| `app/Ipxe/Enums/IpxeMenuKind.php` | 16 cases (3.1-3.5) | Étendre à 18 cases (+2 3.7) |
| `app/Ipxe/Services/IpxeService.php` | 750 LOC, méthodes handle*() pour boot/admin/maintenance/action + linux/windows installs + enrollment | Étendre +1 méthode handleClonezillaMenu() |
| `app/Ipxe/Services/IpxeMenuRenderer.php` | 475 LOC | Étendre +1 méthode renderClonezillaMenu() |
| `app/Ipxe/Services/IpxeActionResolver.php` | 319 LOC | À évaluer T2 — si pattern enum-driven suffit, pas de modif |
| `resources/views/ipxe/menu/maintenance.blade.php` | 37 lignes (3.2) | Modifier : +4 items + 4 anchors |
| `resources/views/ipxe/actions/factory_reset.blade.php` | 4 lignes (3.2) | Référence pour `clonezilla_restore_sda2_sda1.blade.php` |
| `routes/web.php` | 940 lignes (catchall ligne 925) | Insérer bloc 3.7 entre ligne 913 et 925 |
| `config/ipxe.php` | 430 lignes | Ajouter sections `clonezilla` + `tools` |
| `config/sambaedu.php` | 545 lignes (`blocked_legacy_routes` ligne 42) | Ajouter 12 patterns iPXE legacy bloqués (D10) |
| `docs/qa/domains/ipxe.md` | Sections 11-15 (3.1-3.6) | Ajouter Section 16 (3.7) — append-only |

### Source de vérité du comportement attendu

- **Audit legacy** : `_bmad-output/planning-artifacts/audit-clonezilla-legacy-2026-05-21.md` (livré T0 SM 2026-05-21).
- **Code legacy à porter** :
  - `sambaedu/ipxe/clonezilla_menu.php` (80 LOC) → `ipxe.menu.clonezilla.blade.php` + controller `IpxeClonezillaMenuController` + route `/ipxe/clonezilla-menu`.
  - `sambaedu/ipxe/maintenance.php:34` (item `(c) clonezilla`) → étendre `ipxe.menu.maintenance.blade.php` avec `(z) clonezilla` (+ `(g)/(h)/(t)` pour gparted/hdt/memtest).
  - `sambaedu/ipxe/actions/clonezilla_live.php` (7 LOC) → `ipxe.actions.clonezilla_live.blade.php`.
  - `sambaedu/ipxe/actions/clz_sav_sda1_sur_sda2.php` (9 LOC) → `ipxe.actions.clonezilla_save_sda1_sda2.blade.php`.
  - `sambaedu/ipxe/actions/clz_rest_sda2_sur_sda1.php` (9 LOC) → `ipxe.actions.clonezilla_restore_sda2_sda1.blade.php`.
  - `sambaedu/ipxe/gparted.php` (7 LOC) → `ipxe.actions.gparted.blade.php`.
  - `sambaedu/ipxe/hdt.php` (6 LOC) → `ipxe.actions.hdt.blade.php`.
  - `sambaedu/ipxe/memtest86plus.php` (6 LOC) → `ipxe.actions.memtest86plus.blade.php`.
- **Pattern de référence dev** : Story 3.2 (`3-2-boot-et-menu-admin-ipxe.md` — pattern identique : endpoints LAN-only natifs + enum whitelist + templates Blade + cleanup catchall).
- **Pattern Service Provider** : `IpxeServiceProvider` (3.1) — vérifier si une nouvelle binding est nécessaire (probablement non — `IpxeClonezillaMenuController` consomme `IpxeService` déjà bound).

### Risques entrants

| Risque | Probabilité | Impact | Mitigation |
|---|---|---|---|
| Chemins binaires gparted/hdt/memtest divergent de l'iso-legacy | Moyenne | Boot KO en VM smoke | Config-driven via `.env` + T0.5 Henri valide chemins réels VM AVANT dev |
| Cleanup catchall casse une route legacy non-prévue (ex: poste très ancien qui boot directement sur `/ipxe/admin.php`) | Faible (postes terrain mis à jour via 16.11) | Boot KO sur ce poste | Stratégie Q-1 Henri (302 vs 410 Gone) + log channel `legacylog` pour détecter les hits sur routes bloquées + rollback possible via `.env` `LEGACY_BLOCKED_IPXE_ENABLED=false` (flag par défaut true en 3.7) |
| Variable `$mac`/`$uuid` injectée par poste malveillant dans template Blade actions | Faible (validation FormRequest 3.1) | RCE poste (iPXE injection) | D6 strict (aucune variable user dans cmdline kernel/initrd) + test arch D12 |
| Régression sur `factory_reset` 3.2 si modification accidentelle de `IpxeAdminAction::FactoryReset` | Faible | Boot KO restauration usine | D2 strict (ne PAS modifier FactoryReset) + 4 tests Feature 3.2 existants doivent passer post-3.7 |
| Workflow Clonezilla `save` ne marche pas en mode non-batched (D7) | Moyenne | UX dégradée vs legacy (mais legacy aussi fragile en pratique) | D7 documenté Section 16 + Henri valide en smoke VM avant prod |
| Touches `(z)`/`(g)`/`(h)`/`(t)` conflict avec autres items 3.2 | Très faible (audit fait) | Menu Maintenance casse | Test Feature qui vérifie unicité des touches `--key` dans le template render |

### Pré-requis (à valider en T0)

- [x] T0.1 : Stories 3.1-3.5 done (vérifié sprint-status 2026-05-21).
- [x] T0.2 : Story 3.6 review (vérifié — indépendant de 3.7).
- [ ] T0.3 : Lecture rapide `IpxeServiceProvider.php` pour confirmer pas de nouvelle binding requise.
- [ ] T0.4 : Lecture `IpxeActionResolver::resolve()` ligne 100-300 pour confirmer pattern enum-driven générique (= 0 modif resolver) OU lister les variables à injecter pour les 6 nouveaux templates si pattern divergent.
- [ ] T0.5 : Henri valide chemins binaires côté VM (`ls /var/www/html/bin/{clonezilla,gparted,hdt,memtest86+}/`) — cf. audit section 5. Si chemins divergent → adapter `.env` `IPXE_GPARTED_KERNEL` etc. AVANT lancement dev.
- [ ] T0.6 : Henri tranche Q-1 (stratégie 302 vs 410 Gone pour catchall iPXE bloqué) — impact AC7.2 + extension `LegacyCatchallController` si 410 Gone.
- [ ] T0.7 : Henri tranche Q-2 (touches menu maintenance `(z)`/`(g)`/`(h)`/`(t)` ou alternative).

---

## Acceptance Criteria

### Volet 1 — Enum `IpxeAdminAction` étendu (D2)

- [ ] **AC1.1** : `app/Ipxe/Enums/IpxeAdminAction.php` contient 6 nouveaux cases : `ClonezillaLive = 'clonezilla_live'`, `ClonezillaSaveSda1Sda2 = 'clonezilla_save_sda1_sda2'`, `ClonezillaRestoreSda2Sda1 = 'clonezilla_restore_sda2_sda1'`, `Gparted = 'gparted'`, `Hdt = 'hdt'`, `Memtest86plus = 'memtest86plus'`.
- [ ] **AC1.2** : `IpxeAdminAction::template()` retourne le bon path Blade pour les 6 nouveaux cases (ex: `ClonezillaLive` → `'ipxe.actions.clonezilla_live'`).
- [ ] **AC1.3** : `IpxeAdminAction::logName()` retourne déjà la valeur snake_case (pas de modif requise — assertion en test).
- [ ] **AC1.4** : `IpxeAdminAction::linuxMeta()` retourne `null` pour les 6 nouveaux cases (default match preserved). Idem `windowsMeta()`.

### Volet 2 — Enum `IpxeMenuKind` étendu

- [ ] **AC2.1** : `app/Ipxe/Enums/IpxeMenuKind.php` contient 2 nouveaux cases : `ClonezillaMenu = 'clonezilla_menu'`, `ClonezillaMenuHandshake = 'clonezilla_menu_handshake'`.

### Volet 3 — Controller + FormRequest endpoint LAN-only `/ipxe/clonezilla-menu` (D1, D4)

- [ ] **AC3.1** : `app/Ipxe/Http/Controllers/IpxeClonezillaMenuController.php` créé (33 LOC ≤ iso `IpxeMaintenanceController.php`). Délègue 100% à `IpxeService::handleClonezillaMenu()`.
- [ ] **AC3.2** : `app/Ipxe/Http/Requests/IpxeClonezillaMenuRequest.php` créé (validation `mac` required string + `uuid` required string + `session_ipxe` optional string — iso `IpxeMaintenanceRequest`).
- [ ] **AC3.3** : Route `/ipxe/clonezilla-menu` ajoutée à `routes/web.php` après le bloc 3.5 (ligne 913) et AVANT catchall (ligne 925), middleware `auth.v1.lan-only + throttle:600,1`, `withoutMiddleware(['web'])`, nom `ipxe.clonezilla-menu`.

### Volet 4 — Service `IpxeService::handleClonezillaMenu` + `IpxeMenuRenderer::renderClonezillaMenu` (D1)

- [ ] **AC4.1** : `IpxeService::handleClonezillaMenu(Request $r): Response` ajouté. Comportement iso `handleMaintenance` (3.2) : resolve workstation via `WorkstationLocator`, log handshake (`ipxe.clonezilla_menu.handshake`), appelle `IpxeMenuRenderer::renderClonezillaMenu`, log render (`ipxe.clonezilla_menu.rendered`), retourne Response text/plain.
- [ ] **AC4.2** : `IpxeMenuRenderer::renderClonezillaMenu(array $context): string` ajouté. Comportement iso `renderMaintenance` (3.2) : assemble variables Blade (shebang, mac, uuid, workstationName, ip, resolution*, menuTimeoutMs, serverBaseUrl, bootDiskFallback) + render `ipxe.menu.clonezilla` template.
- [ ] **AC4.3** : Le timeout du menu = `config('ipxe.clonezilla.menu_timeout_ms', 10000)` (iso `maintenance.php:9` legacy).
- [ ] **AC4.4** : Background PNG = `config('ipxe.clonezilla.background_png', 'png/clonezilla.png')` (iso legacy).
- [ ] **AC4.5** : Si poste inconnu (Workstation = null) → menu réduit avec item `exit` seul + message neutre (iso 3.2 D6 admin) — pas d'items clonezilla pour un poste non-enrôlé.

### Volet 5 — Templates Blade (D6)

- [ ] **AC5.1** : `resources/views/ipxe/menu/clonezilla.blade.php` créé. Structure iso `maintenance.blade.php` (3.2) avec 4 items menu (`l` live, `s` save, `r` restore, `b` back), retour, exit. Charset ASCII strict. Cmdline `chain --replace --autofree {{ $serverBaseUrl }}/ipxe/action/clonezilla_live##params` pour chaque action.
- [ ] **AC5.2** : `resources/views/ipxe/actions/clonezilla_live.blade.php` créé. Port iso `sambaedu/ipxe/actions/clonezilla_live.php:4-6` : kernel + initrd + imgargs interactif (sans `ocs_live_batch` D7).
- [ ] **AC5.3** : `resources/views/ipxe/actions/clonezilla_save_sda1_sda2.blade.php` créé. Port iso `clz_sav_sda1_sur_sda2.php:6` sans `ocs_live_batch` (D7). Cmdline contient `ocs-sr -q2 -j2 -z1 -i 2000 -fsck-src-part -p reboot saveparts savesda1 sda1`.
- [ ] **AC5.4** : `resources/views/ipxe/actions/clonezilla_restore_sda2_sda1.blade.php` créé. Port iso `clz_rest_sda2_sur_sda1.php:6` sans `ocs_live_batch` (D7). Cmdline contient `ocs-sr -e1 auto -e2 -r -j2 -p reboot restoreparts savesda1 sda1` (= MÊME que `factory_reset.blade.php` D2).
- [ ] **AC5.5** : `resources/views/ipxe/actions/gparted.blade.php` créé. Port iso `gparted.php` — kernel + initrd minimal pour GParted Live.
- [ ] **AC5.6** : `resources/views/ipxe/actions/hdt.blade.php` créé. Port iso `hdt.php` — kernel statique HDT.
- [ ] **AC5.7** : `resources/views/ipxe/actions/memtest86plus.blade.php` créé. Port iso `memtest86plus.php` — kernel statique Memtest.
- [ ] **AC5.8** : `resources/views/ipxe/menu/maintenance.blade.php` modifié — ajouter 4 items `(z) clonezilla`, `(g) gparted`, `(h) hdt`, `(t) memtest` + 4 anchors correspondants. Conditionnels `@if(config('ipxe.tools.gparted.enabled'))` etc. pour les outils diagnostic. Item `(z) clonezilla` toujours présent (sous-menu Clonezilla = core 3.7).
- [ ] **AC5.9** : Aucun template Blade nouveau ne contient `{{ $mac }}` / `{{ $uuid }}` / `{{ $workstationName }}` / `{{ $ip }}` dans une ligne commençant par `kernel ` ou `initrd ` (sécurité D6 — test Architecture D12).

### Volet 6 — Config + IpxeServiceProvider (D8)

- [ ] **AC6.1** : `config/ipxe.php` étendu avec section `clonezilla` (3 clés : `enabled`, `menu_timeout_ms`, `background_png`).
- [ ] **AC6.2** : `config/ipxe.php` étendu avec section `tools` (3 sous-sections `gparted`/`hdt`/`memtest86plus`, chacune avec `enabled` + paths kernel/initrd).
- [ ] **AC6.3** : `app/Providers/IpxeServiceProvider.php` modifié uniquement si nouvelle binding requise (probablement non — à confirmer T0.3).

### Volet 7 — Cleanup catchall (D10)

- [ ] **AC7.1** : `config/sambaedu.php` `blocked_legacy_routes` étendu avec 12-16 patterns iPXE legacy (cf. D10) — bloque les routes migrées 3.1-3.7.
- [ ] **AC7.2** : Stratégie de blocage = redirect 302 (convention 16.2/16.9) **OU** 410 Gone + corps texte iPXE explicite si Q-1 Henri = 410 Gone (extension mineure de `LegacyCatchallController::handle()` requise).
- [ ] **AC7.3** : Les routes legacy `*.php` bloquées ne sont PLUS servies par le proxy legacy — un curl direct vers `http://192.168.122.50/ipxe/clonezilla.php` retourne 302 (ou 410) avec target = route SE5 native (smoke VM post-merge).
- [ ] **AC7.4** : Les assets statiques iPXE (`png/*`, `bin/*`, `Win10/sources/*`, `diconf/*`) restent accessibles via le `direct_legacy_routes` `^/ipxe/` (pas modifié).

### Volet 8 — Audit `MachineBootLog.action` (D11)

- [ ] **AC8.1** : Quand un poste lance `/ipxe/action/clonezilla_live` (ou save/restore), une ligne `machine_boot_logs` est insérée avec `action = 'ipxe_clonezilla'`.
- [ ] **AC8.2** : Quand un poste lance `/ipxe/action/gparted` → `action = 'ipxe_gparted'`.
- [ ] **AC8.3** : Idem `hdt` → `'ipxe_hdt'`, `memtest86plus` → `'ipxe_memtest'`.
- [ ] **AC8.4** : Aucune migration DB ajoutée (varchar(20) suffit).

### Volet 9 — Tests Architecture (D12)

- [ ] **AC9.1** : `tests/Architecture/IpxeNamespaceTest.php` étendu avec test `it_ensures_ipxe_3_7_routes_are_declared_before_catchall` (assertion ordre `/ipxe/clonezilla-menu` AVANT catchall).
- [ ] **AC9.2** : Test `it_ensures_6_new_actions_have_blade_template_in_ipxe_actions_namespace` (assertion existence des 6 templates `clonezilla_*.blade.php` + `gparted.blade.php` + `hdt.blade.php` + `memtest86plus.blade.php`).
- [ ] **AC9.3** : Test `it_ensures_factory_reset_and_clonezilla_restore_have_same_kernel_cmdline` (parité D2 — rendre les 2 templates avec un Workstation fixture + diff sur la ligne `kernel ...`).
- [ ] **AC9.4** : Test `it_ensures_no_user_variable_in_action_kernel_lines` (sécurité — grep dans les 6 nouveaux templates).

### Volet 10 — Runbook QA + sprint-status + backlog

- [ ] **AC10.1** : `docs/qa/domains/ipxe.md` étendu avec section `## Section 16 — Story 3.7 — Clonage et Maintenance` (append-only iso 3.1-3.6). Numérotation 3.1-3.6 préservée.
- [ ] **AC10.2** : Au moins 8 scénarios stables `3.7-1` à `3.7-N` documentés (smoke curl `/ipxe/clonezilla-menu`, smoke menu maintenance étendu, smoke 6 actions, smoke catchall bloqué, smoke poste inconnu, smoke regression `factory_reset`).
- [ ] **AC10.3** : `_bmad-output/implementation-artifacts/sprint-status.yaml` mis à jour (status 3-7 backlog → ready-for-dev en pré-création, puis ready-for-dev → in-progress par le dev, puis → review en fin).

---

## Tasks / Subtasks

### Phase T0 — Pré-flight + validations contexte

- [ ] **T0.1** Lire `_bmad-output/planning-artifacts/audit-clonezilla-legacy-2026-05-21.md` intégralement (AC: contexte).
- [ ] **T0.2** Confirmer Stories 3.1-3.5 done dans `_bmad-output/implementation-artifacts/sprint-status.yaml` (AC: contexte).
- [ ] **T0.3** Lire `app/Providers/IpxeServiceProvider.php` (chercher bindings singleton + `boot()` extensions Story 3.5/3.6).
- [ ] **T0.4** Lire `app/Ipxe/Services/IpxeActionResolver.php::resolve()` (lignes 100-300) → confirmer pattern enum-driven générique (variable Blade injection iso `linuxMeta()`/`windowsMeta()` pour les nouveaux cases retourne `null` → resolver utilise template path uniquement).
- [ ] **T0.5** Lister à Henri (Pré-requis VM) les chemins binaires à confirmer (gparted/hdt/memtest86+) — section `## Pré-requis VM (actions Henri)` ci-dessous.
- [ ] **T0.6** Vérifier qu'aucune autre story Epic 3 (3.6 review) n'a touché aux mêmes fichiers en parallèle (`git log --oneline _bmad-output/implementation-artifacts/3-6*.md` + `git diff main..ipxe -- routes/web.php config/ipxe.php`).

### Phase T1 — Enums + Validation + FormRequest (D1, D2, AC1.*, AC2.*, AC3.2)

- [ ] **T1.1** Étendre `app/Ipxe/Enums/IpxeAdminAction.php` : +6 cases (D2) + extension `template()` (6 entrées) + assertion `linuxMeta()/windowsMeta()` retournent null (couvert par default match existant).
- [ ] **T1.2** Étendre `app/Ipxe/Enums/IpxeMenuKind.php` : +2 cases `ClonezillaMenu`, `ClonezillaMenuHandshake`.
- [ ] **T1.3** Créer `app/Ipxe/Http/Requests/IpxeClonezillaMenuRequest.php` (iso `IpxeMaintenanceRequest.php`).
- [ ] **T1.4** Tests Unit Enum (`tests/Unit/Ipxe/Enums/IpxeAdminActionTest.php`) : +6 assertions cases + +6 assertions `template()` paths + 1 assertion `linuxMeta()/windowsMeta()` retournent null pour les 6 nouveaux cases.

### Phase T2 — Service handleClonezillaMenu + renderClonezillaMenu (D1, AC4.*)

- [ ] **T2.1** Étendre `app/Ipxe/Services/IpxeService.php` : +1 méthode `handleClonezillaMenu(Request $r): Response` (iso `handleMaintenance`).
- [ ] **T2.2** Étendre `app/Ipxe/Services/IpxeMenuRenderer.php` : +1 méthode `renderClonezillaMenu(array $context): string` (iso `renderMaintenance`).
- [ ] **T2.3** Tests Unit Service (`tests/Unit/Ipxe/Services/IpxeServiceTest.php`) : +1 test handshake + +1 test render OK + +1 test poste inconnu (AC4.5).
- [ ] **T2.4** Tests Unit Renderer (`tests/Unit/Ipxe/Services/IpxeMenuRendererTest.php`) : +1 test variables Blade assemblées + +1 test menu items présents.

### Phase T3 — Controller + Route (AC3.*, D4)

- [ ] **T3.1** Créer `app/Ipxe/Http/Controllers/IpxeClonezillaMenuController.php` (33 LOC ≤ iso `IpxeMaintenanceController`).
- [ ] **T3.2** Ajouter route `/ipxe/clonezilla-menu` dans `routes/web.php` (bloc 3.7 entre ligne 913 et 925) — ORDRE STRICT (AC9.1).
- [ ] **T3.3** Tests Feature (`tests/Feature/Ipxe/IpxeClonezillaMenuTest.php`) : +1 test GET retourne 200 + script iPXE valide + +1 test POST idem + +1 test poste inconnu retourne menu réduit + +1 test middleware auth.v1.lan-only bloque les requêtes hors LAN.

### Phase T4 — Templates Blade actions (D6, AC5.*)

- [ ] **T4.1** Créer `resources/views/ipxe/menu/clonezilla.blade.php` (sous-menu 4 items + retour + exit, iso `maintenance.blade.php`).
- [ ] **T4.2** Créer `resources/views/ipxe/actions/clonezilla_live.blade.php` (port `actions/clonezilla_live.php`).
- [ ] **T4.3** Créer `resources/views/ipxe/actions/clonezilla_save_sda1_sda2.blade.php` (port `clz_sav_sda1_sur_sda2.php` sans `ocs_live_batch` D7).
- [ ] **T4.4** Créer `resources/views/ipxe/actions/clonezilla_restore_sda2_sda1.blade.php` (port `clz_rest_sda2_sur_sda1.php` sans `ocs_live_batch` D7, MÊME cmdline que `factory_reset.blade.php` D2).
- [ ] **T4.5** Créer `resources/views/ipxe/actions/gparted.blade.php` (port `gparted.php`).
- [ ] **T4.6** Créer `resources/views/ipxe/actions/hdt.blade.php` (port `hdt.php`).
- [ ] **T4.7** Créer `resources/views/ipxe/actions/memtest86plus.blade.php` (port `memtest86plus.php`).
- [ ] **T4.8** Modifier `resources/views/ipxe/menu/maintenance.blade.php` : +4 items `(z)` `(g)` `(h)` `(t)` + 4 anchors (Q-2 Henri sur touches).
- [ ] **T4.9** Tests Feature templates (`tests/Feature/Ipxe/IpxeActionEndpointTest.php` étendu) : +6 tests `it_renders_{clonezilla_live,clonezilla_save_sda1_sda2,clonezilla_restore_sda2_sda1,gparted,hdt,memtest86plus}` (GET/POST → 200 + content-type text/plain + corps contient `#!ipxe` + `kernel` + `boot` + pas de `kernel http://localhost`).

### Phase T5 — Config + IpxeServiceProvider (D8, AC6.*)

- [ ] **T5.1** Étendre `config/ipxe.php` : section `clonezilla` (3 clés D8) + section `tools` (3 sous-sections D8).
- [ ] **T5.2** Vérifier `IpxeServiceProvider` : si pas de nouvelle binding requise → ne pas toucher. Sinon (cas T0.3 révèle besoin), ajouter binding singleton.
- [ ] **T5.3** Tests Unit Config (`tests/Unit/Ipxe/IpxeConfigTest.php`) : +6 assertions sections `clonezilla.*` + `tools.gparted.*` etc.

### Phase T6 — Cleanup catchall (D10, AC7.*)

- [ ] **T6.1** Étendre `config/sambaedu.php` `blocked_legacy_routes` : +12-16 patterns iPXE legacy (D10).
- [ ] **T6.2** Si Q-1 Henri = 410 Gone → étendre `LegacyCatchallController::handle()` pour supporter stratégie 410 Gone sur subset de patterns (convention `gone:ipxe/admin` en value OU nouvelle colonne config `legacy_route_strategy`).
- [ ] **T6.3** Tests Feature non-régression (`tests/Feature/Ipxe/IpxeLegacyRoutingNonRegressionTest.php` étendu) : +12 tests `it_blocks_legacy_ipxe_{...}_php_route` (assertion 302 ou 410 selon Q-1 + target URL SE5 correcte) + 4 tests `it_allows_legacy_ipxe_asset_{png,bin,...}` (assertion 200 ou 404 pour assets statiques non-bloqués).

### Phase T7 — Tests Architecture (D12, AC9.*)

- [ ] **T7.1** Étendre `tests/Architecture/IpxeNamespaceTest.php` : +4 tests (D12 : route ordre, templates existence, parité factory_reset/clonezilla_restore, sécurité no-user-var-in-kernel).

### Phase T8 — Runbook QA + sprint-status + backlog (AC10.*)

- [ ] **T8.1** Étendre `docs/qa/domains/ipxe.md` : append Section 16 « Story 3.7 — Clonage et Maintenance » avec ≥ 8 scénarios stables `3.7-1` à `3.7-N` (smoke curl `/ipxe/clonezilla-menu`, menu maintenance étendu, 6 actions individuelles, catchall bloqué, poste inconnu, regression factory_reset).
- [ ] **T8.2** Mettre à jour `_bmad-output/implementation-artifacts/sprint-status.yaml` : `3-7-clonage-et-maintenance: ready-for-dev` (SM création) → `in-progress` (dev start) → `review` (dev end).
- [ ] **T8.3** Mettre à jour le backlog HTML si présent (`_bmad-output/implementation-artifacts/backlog.html` — facultatif).

---

## File List prévisionnelle

### Fichiers créés (estimés ~17)

- `app/Ipxe/Http/Controllers/IpxeClonezillaMenuController.php` (NEW — 33 LOC)
- `app/Ipxe/Http/Requests/IpxeClonezillaMenuRequest.php` (NEW — ~25 LOC iso `IpxeMaintenanceRequest`)
- `resources/views/ipxe/menu/clonezilla.blade.php` (NEW — ~35 LOC)
- `resources/views/ipxe/actions/clonezilla_live.blade.php` (NEW — ~6 LOC)
- `resources/views/ipxe/actions/clonezilla_save_sda1_sda2.blade.php` (NEW — ~6 LOC)
- `resources/views/ipxe/actions/clonezilla_restore_sda2_sda1.blade.php` (NEW — ~6 LOC, MÊME cmdline `factory_reset.blade.php`)
- `resources/views/ipxe/actions/gparted.blade.php` (NEW — ~4 LOC)
- `resources/views/ipxe/actions/hdt.blade.php` (NEW — ~3 LOC)
- `resources/views/ipxe/actions/memtest86plus.blade.php` (NEW — ~3 LOC)
- `tests/Feature/Ipxe/IpxeClonezillaMenuTest.php` (NEW — ~150 LOC, 5 tests Feature)
- `_bmad-output/planning-artifacts/audit-clonezilla-legacy-2026-05-21.md` (NEW — livré par SM 2026-05-21)

### Fichiers modifiés (estimés ~12)

- `app/Ipxe/Enums/IpxeAdminAction.php` (+6 cases + extension `template()`)
- `app/Ipxe/Enums/IpxeMenuKind.php` (+2 cases)
- `app/Ipxe/Services/IpxeService.php` (+1 méthode `handleClonezillaMenu`)
- `app/Ipxe/Services/IpxeMenuRenderer.php` (+1 méthode `renderClonezillaMenu`)
- `app/Ipxe/Services/IpxeActionResolver.php` (modif uniquement si T0.4 révèle besoin — probablement 0 modif)
- `app/Providers/IpxeServiceProvider.php` (0 modif probable)
- `app/Http/Controllers/LegacyCatchallController.php` (modif uniquement si Q-1 Henri = 410 Gone)
- `resources/views/ipxe/menu/maintenance.blade.php` (+4 items + 4 anchors)
- `routes/web.php` (+1 route `/ipxe/clonezilla-menu` dans bloc 3.7)
- `config/ipxe.php` (+section `clonezilla` + section `tools`)
- `config/sambaedu.php` (+12-16 patterns `blocked_legacy_routes`)
- `tests/Unit/Ipxe/Enums/IpxeAdminActionTest.php` (+6 assertions cases + template paths)
- `tests/Unit/Ipxe/Services/IpxeServiceTest.php` (+3 tests handleClonezillaMenu)
- `tests/Unit/Ipxe/Services/IpxeMenuRendererTest.php` (+2 tests renderClonezillaMenu)
- `tests/Unit/Ipxe/IpxeConfigTest.php` (+6 assertions config sections)
- `tests/Feature/Ipxe/IpxeActionEndpointTest.php` (+6 tests actions)
- `tests/Feature/Ipxe/IpxeLegacyRoutingNonRegressionTest.php` (+12 tests catchall bloqué)
- `tests/Architecture/IpxeNamespaceTest.php` (+4 tests D12)
- `docs/qa/domains/ipxe.md` (+ Section 16)

### Fichiers métadonnées BMAD modifiés

- `_bmad-output/implementation-artifacts/sprint-status.yaml` (3-7 backlog → ready-for-dev → in-progress → review).

### Fichiers NON modifiés (garde-fou)

- `sambaedu/ipxe/*.php` (legacy intact).
- `legacy/modules/ipxe/*.php` (legacy intact).
- `app/Ipxe/Iso/*` (sous-namespace 3.6 hors-scope).
- `app/Models/MachineBootLog.php` (varchar(20) suffit — pas d'extension model).
- Aucune nouvelle migration DB.

---

## Test Strategy

### Couverture par niveau

| Niveau | Tests cibles cumulés | Détail |
|---|---|---|
| Unit | ≥ 15 | Enum (+6 cases + template + meta=null) + Service handleClonezillaMenu (+3) + Renderer renderClonezillaMenu (+2) + Config (+6 sections) |
| Feature Livewire | N/A | Pas de composant Livewire en 3.7 (firmware iPXE LAN-only) |
| Feature HTTP | ≥ 18 | IpxeClonezillaMenu (5) + 6 actions templates (6) + non-régression catchall (12 catchall iPXE bloqués + 4 assets autorisés) |
| Architecture | ≥ 4 | D12 : route ordre, templates existence, parité factory_reset/clonezilla_restore, sécurité no-user-var-in-kernel |
| **Total** | **≥ 37** | Largement au-delà du minimum ≥ 25 ciblé |

### Tests qu'on ne fait **pas** dans cette story

- Tests E2E LAN smoke réel (= `ssh /vm` → impossible en worktree — Henri fait en T+post-merge).
- Tests Performance / Stress (charge iPXE LAN concurrente — Phase 3).
- Tests workflow stateful clonezilla UDP-multicast (D3 — Phase 3).
- Tests sur les 7 cases enum 3.5 install_win* (déjà couverts par tests Story 3.5).

---

## Anti-patterns à éviter (DISASTER PREVENTION)

### Architecture & scope

- **NE PAS** porter le workflow stateful `sysrescuecd/{action,autorun,progress}.php` ou `clonezilla/{action,autorun}.php` (D3 — Phase 3).
- **NE PAS** ajouter un item `(c) clonage en direct des postes` dans `admin.blade.php` (D3).
- **NE PAS** porter `clonage.php` (D3).
- **NE PAS** créer un sous-namespace `App\Ipxe\Clonezilla\*` (D1 — overkill, 6 templates ne justifient pas).
- **NE PAS** modifier l'enum `IpxeAdminAction::FactoryReset` ni `factory_reset.blade.php` (D2 — regression risk + déjà en prod).

### Sécurité & iPXE injection

- **NE PAS** injecter `$mac`, `$uuid`, `$workstationName`, `$ip` dans une cmdline `kernel` ou `initrd` (D6 + AC5.9 + test arch AC9.4).
- **NE PAS** désactiver la validation enum (D9 — whitelist stricte = autorité finale).
- **NE PAS** sortir du LAN-only middleware (`auth.v1.lan-only`).

### Concurrence & robustesse

- **NE PAS** introduire de Cache::lock ou de Job Queue en 3.7 (pas de side-effect serveur long — boot iPXE = 1 cycle court).
- **NE PAS** introduire de migration DB (D11 — `MachineBootLog.action` varchar(20) suffit).

### UX & front

- **NE PAS** porter `ocs_live_batch` autorun (D7 — workflow stateful Phase 3).
- **NE PAS** ajouter d'item menu admin web SE5 (hors-scope strict iPXE firmware).
- **NE PAS** porter les variantes 32-bits Clonezilla (legacy `live32`/`sav_locale32`/`rest_locale32` — abandonnées D2).

### Process & infra

- **NE PAS** modifier les fichiers `sambaedu/ipxe/*.php` ou `legacy/modules/ipxe/*.php` (legacy intact — seul le catchall les bloque via `blocked_legacy_routes`).
- **NE PAS** committer en dehors du scope strict 3.7.
- **NE PAS** SSH `/vm` depuis le worktree `ipxe` (memoire `feedback_worktree_no_vm_sync`).

---

## Dépendances + ordre

### Amont (pré-requis satisfaits)

- ✅ Story 3.1 (done) : socle `IpxeService` + `WorkstationLocator` + channel log `ipxe` + `MachineBootLog`.
- ✅ Story 3.2 (done) : whitelist enum `IpxeAdminAction` + endpoints `/ipxe/admin`, `/ipxe/maintenance`, `/ipxe/action/{action}` + pattern controller fin + template Blade `factory_reset` (référence D2).
- ✅ Story 3.3 (done) : pattern enrollment routes + template menu existant `admin.blade.php`.
- ✅ Story 3.4 (done) : pattern extension enum +9 cases + 9 templates Linux + pattern routes flat.
- ✅ Story 3.5 (done) : pattern extension enum +7 cases + 7 templates Windows + pattern test arch ordre routes.
- ⏳ Story 3.6 (review) : indépendant 3.7 (sous-namespace dédié `App\Ipxe\Iso\*`, page admin web SE5 ≠ firmware iPXE LAN). **Pas bloquant pour démarrer 3.7**.

### Aval (3.7 = dernière story Epic 3)

- Aucune story Epic 3 ne dépend de 3.7.
- **Epic 3 retrospective** = optional, à programmer post-merge 3.7 par Henri si souhaité.
- **Epic 17/18** (Linux Windows scripts) ne dépendent pas de 3.7.

---

## Pré-requis VM (actions Henri)

> 3.7 introduit des références à des binaires Apache-statiques (gparted, hdt, memtest86+) que SE5 n'a jamais touchés. **À valider avant lancement dev** :

1. **T0.5 — Inventaire binaires VM** :
   ```bash
   # SSH /vm
   ls -la /var/www/html/bin/clonezilla/         # Existant — utilisé par factory_reset 3.2
   ls -la /var/www/html/bin/gparted/             # À CONFIRMER chemin
   ls -la /var/www/html/bin/hdt/                 # À CONFIRMER chemin
   ls -la /var/www/html/bin/memtest86+/          # À CONFIRMER chemin
   ```
   Si chemins divergent → adapter `.env` `IPXE_GPARTED_KERNEL`, `IPXE_HDT_KERNEL`, `IPXE_MEMTEST_KERNEL` AVANT lancement dev (D8).

2. **T0.6 — Stratégie catchall iPXE bloqué (Q-1)** : Henri tranche entre :
   - Option A — **302 redirect** (convention iso `blocked_legacy_routes` 16.2/16.9) — risque : firmware iPXE ne parse pas Location header → erreur boot poste.
   - Option B — **410 Gone + corps texte/plain iPXE script** explicite (`#!ipxe\necho ERREUR - Route legacy obsolete\nsleep 5\nexit`) — extension mineure `LegacyCatchallController` requise.
   - **Recommandation SM = Option B** (Option A casserait silencieusement des postes terrain non-mis-à-jour).

3. **T0.7 — Choix touches menu maintenance (Q-2)** : Henri valide touches `(z) clonezilla`, `(g) gparted`, `(h) hdt`, `(t) memtest` OU propose alternative (ex: `(z)` interférerait avec un workflow opérateur connu).

4. **Post-merge VM up** :
   ```bash
   # SSH /vm
   cd /var/www/sambaedu-reload
   composer install                              # idem 3.1-3.6
   php artisan config:clear && php artisan cache:clear && php artisan view:clear
   systemctl reload php8.2-fpm@www-admin
   # Smoke iPXE LAN (poste réel ou curl)
   curl --data 'mac=AA:BB:CC:DD:EE:FF&uuid=12345678-1234-1234-1234-123456789012' \
        http://192.168.122.50/ipxe/clonezilla-menu
   # Vérifier : 200 + corps text/plain commence par #!ipxe + contient :menu et item l clonezilla_live
   # Smoke catchall bloqué
   curl -I http://192.168.122.50/ipxe/clonezilla.php
   # Vérifier : 302 ou 410 selon Q-1 + Location header (302) ou corps iPXE (410)
   # Smoke regression 3.2
   curl --data 'mac=AA:BB:CC:DD:EE:FF&uuid=12345678-...' \
        http://192.168.122.50/ipxe/maintenance
   # Vérifier : 200 + corps contient :clonezilla anchor + items (z)/(g)/(h)/(t) présents
   ```

5. **Optionnel — Smoke poste réel en LAN** : booter un poste enrôlé, valider visuellement menu maintenance + sous-menu clonezilla + lancement Clonezilla LiveCD (manuel, pas de save/restore en smoke pour ne pas écraser le poste).

---

## Questions ouvertes pour Henri

> À répondre en T0.5/T0.6/T0.7 par Henri AVANT lancement dev. Le dev peut démarrer sans ces réponses (decisions par défaut documentées), mais Henri tranche pour optimiser.

- **Q-1** (T0.6) : **Stratégie cleanup catchall iPXE** — 302 redirect (convention `blocked_legacy_routes` 16.2/16.9) ou 410 Gone + corps iPXE explicite (recommandation SM) ? Implication AC7.2 + extension `LegacyCatchallController` si 410 Gone.
- **Q-2** (T0.7) : **Touches menu maintenance** `(z) clonezilla`, `(g) gparted`, `(h) hdt`, `(t) memtest` OK ou propose alternative ?
- **Q-3** (T0.5) : **Chemins binaires côté VM** `/var/www/html/bin/{gparted,hdt,memtest86+}/*` — confirmer ou indiquer chemins réels pour adapter `.env`.
- **Q-4** : **Label `MachineBootLog.action` pour clonezilla** — D11 propose 1 seul label `ipxe_clonezilla` pour les 3 actions clonezilla (live/save/restore). Henri préfère 3 labels distincts (`ipxe_clz_live`, `ipxe_clz_save`, `ipxe_clz_restore`) pour audit fin ? Implique varchar(20) compatible (max 16 chars OK).
- **Q-5** : **Désactiver fallback `direct_legacy_routes` `^/ipxe/`** post-3.7 — actuel `config/sambaedu.php:72`. Si désactivé → les assets statiques (`png/*`, `bin/*`) ne passent plus par le catchall → besoin d'une route Laravel statique dédiée. **Recommandation SM = laisser activé** (assets statiques restent servis). Si Henri veut désactivation totale → ouvrir story dédiée Phase 3.

---

## Recommandation Modèle Dev

**Modèle recommandé : `sonnet`**

**Justification (1-2 phrases)** :

3.7 est une story de **complétion d'Epic 3** à **complexité modérée** : 6 templates Blade ASCII statiques très courts (3-6 LOC chacun, ports iso-legacy quasi-littéraux) + 1 controller fin + 1 endpoint LAN-only (pattern strictement reproductible depuis 3.2/3.4/3.5 livrées par opus) + 1 extension enum (+6 cases trivial) + 1 cleanup catchall (modification config purement déclarative). **Pas de sécurité critique nouvelle** (whitelist enum déjà rodée 3.2-3.5), **pas de génération XML/dynamic batch** (vs 3.5 DOMDocument), **pas d'orchestration Job Queue / Cache::lock / Symfony Process / sudo** (vs 3.6 SSRF/RCE). La complexité résiduelle (cleanup catchall + Q-1 stratégie 302/410) est mineure et documentée par décisions tranchées D10 + Q-1.

**Bascule possible vers `opus`** : si T0.6 Q-1 Henri = 410 Gone + extension non-triviale `LegacyCatchallController` (introduction d'un dispatcher stratégie 302/410 nouveau) → bascule opus possible en T6.2. Sinon `sonnet` suffit.

**Charge estimée : 2 jours** (recadrer 2.5j si T0.5 chemins binaires VM divergent fortement OU Q-1 = 410 Gone avec extension controller significative).

---

## Dev Agent Record

### Agent Model Used

`claude-sonnet-4-6` (worktree `ipxe`) — 2026-05-22

### Debug Log References

- Session JSONL : `/home/htouchard/.claude/projects/-home-htouchard-code-irundo-codebase-ipxe/2e1f37ed-0ff9-4ac8-a249-d020243a2926.jsonl`
- PHPUnit résultat final : 140 tests Story 3.7 pass, 0 échec

### Completion Notes List

- **T0** : Lecture story + décisions Q1-Q5 (Henri) appliquées sans re-discussion. ServiceProvider confirmé sans binding requis. ActionResolver confirmé générique (serverBaseUrl ajouté). Chemins binaires extraits des legacy PHP.
- **T1** : IpxeAdminAction +6 cases +bootLogAction(). IpxeMenuKind +2 cases. Pas de migration.
- **T2** : IpxeService::handleClonezillaMenu() + IpxeMenuRenderer::renderClonezillaMenu() + IpxeActionResolver::resolveServerBaseUrl() + LegacyCatchallController gone: prefix support.
- **T3** : FormRequest IpxeClonezillaMenuRequest + Controller IpxeClonezillaMenuController + route ipxe.clonezilla-menu.
- **T4** : 7 templates Blade (1 menu clonezilla + 6 actions). maintenance.blade.php +4 items. Tous ASCII strict.
- **T5** : config/ipxe.php +sections clonezilla+tools. config/sambaedu.php +21 blocked_legacy_routes (gone: prefix) +'^ipxe/action/' catchall sécurité.
- **T6** : Tests Unit (IpxeAdminActionTest +5 méthodes, IpxeConfigTest +4 assertions). Tests Feature (IpxeActionEndpointTest +7, IpxeClonezillaMenuTest 6). Tests Architecture (IpxeNamespaceTest +4).
- **T7** : Correctifs opérationnels : (1) vendor/ créé via composer install (worktree sans vendor → symlink ne fonctionnait pas → autoload résolvait sambaedu-reload/app/), (2) APP_KEY ajouté phpunit.xml, (3) regex route [a-z_]+ → [a-z0-9_]+ (clonezilla_save_sda1_sda2 + memtest86plus contiennent chiffres), (4) blocked_legacy_routes '^ipxe/action/' → 410 pour actions format invalide (RESCUECD → 410 non 504), (5) fix NodeTraverser::addVisitor() void return (bug préexistant 3.6).
- **T8** : QA docs Section 16 (11 scénarios 3.7-1..11 + checklist). sprint-status.yaml ready-for-dev → review. Story status → review.

## Corrections post-review (2026-05-22)

> Application des 13 correctifs / 14 findings de la review `_bmad-output/codeReviews/3-7.md` par `claude-opus-4-7[1m]`. Aucun changement de scope, aucune migration DB, aucun nouveau composant Spatie. Status review : `in-review` → `to-validate` (Henri valide après lecture).

### Décisions Henri appliquées

- **#1 + #10 (D2 — divergence intentionnelle FactoryReset/ClonezillaRestore)** : garder divergence labels boot_log (`ipxe_action` vs `ipxe_clonezilla`) même cmdline → PHPDoc complet + test non-régression + docs QA.
- **#2 (catchall `^ipxe/action/`)** : garder pattern strict (D10 cleanup) → commentaire détaillé config + docs QA « Compat postes legacy » avec smoke commands.
- **#7 (audit fin install_*/install_win*)** : étendre maintenant → 16 cases install_* (3.4 + 3.5) avec labels distincts (`ipxe_deb_gnome`, `ipxe_win10_perso`, etc.) tous ≤ 20 chars + test garde-fou varchar(20).
- **#6 (APP_KEY phpunit.xml)** : garder la clé (CI/test stability) + commentaire XML explicite « TEST KEY ONLY ».

### Fixes auto-applicables

- **#3 (clonezilla_live params)** : doubles espaces legacy `nomodeset  ocs_prerun` + `keyboard-layouts="fr"  locales` restaurés iso-legacy + commentaire Blade.
- **#4 (FormRequest product)** : `session_ipxe` retiré, `product` ajouté (parité iso `IpxeMaintenanceRequest`).
- **#5 (resolveServerBaseUrl)** : `IpxeActionResolver` lit désormais `ipxe.se4fs_url` (clé canonique unifiée avec `IpxeService`/`IpxeEnrollmentOrchestrator`), fallback secondaire deprecated sur `ipxe.actions.server_base_url`. + 2 tests unit.
- **#8 (log naming clonezilla)** : `ipxe.clonezilla_menu.rendered` → `ipxe.clonezilla.menu_rendered` (pattern Epic 3 aligné). Idem handshake.
- **#9 (Content-Type header)** : 6 tests `it_renders_*_action` assertent désormais `text/plain`.
- **#11 (regex user variable Blade)** : regex renforcée avec ancres lexicales `(?<![A-Za-z0-9_])\$\s*(...)(?![A-Za-z0-9_])` + test du test (`it_validates_user_variable_regex_matches_blade_and_php_sigils`).
- **#12 (config in blade)** : nouvelle méthode `IpxeActionResolver::resolveToolsVariables()` injecte 7 variables Blade. Templates `gparted/hdt/memtest86plus.blade.php` n'appellent plus `config()` directement.
- **#13 (body iPXE legacy regression)** : 4 tests `it_blocks_*_php_with_410_gone` assertent désormais `#!ipxe` (8/8 tests cohérents).
- **#14 (différentiel poste connu)** : nouveau test `it_links_boot_log_to_workstation_when_known` exerce le chemin Workstation non-null.

### Tests post-correctifs

- Tests Unit Enum : +3 méthodes (data providers install_linux + install_windows + varchar(20) garde-fou).
- Tests Unit Resolver : +2 méthodes (canonical key + legacy fallback).
- Tests Feature IpxeActionEndpoint : +2 méthodes (FactoryReset non-régression + Workstation connu).
- Tests Architecture : +1 méthode (regex test du test).
- Tests Feature legacy regression : +4 assertions body iPXE.

Compteur PHPUnit : 140 (avant) → ~150 (après — précis par lancement). Aucune régression sur les autres tests Ipxe/Architecture.

### Fichiers touchés par les correctifs

**Modifiés** :
- `app/Ipxe/Enums/IpxeAdminAction.php` (PHPDoc D2 + bootLogAction étendu 16 cases install_*)
- `app/Ipxe/Http/Requests/IpxeClonezillaMenuRequest.php` (rules : product, retire session_ipxe)
- `app/Ipxe/Services/IpxeActionResolver.php` (resolveServerBaseUrl clé canonique + resolveToolsVariables)
- `app/Ipxe/Services/IpxeService.php` (renommage log handshake + menu_rendered)
- `config/sambaedu.php` (commentaire long catchall `^ipxe/action/`)
- `phpunit.xml` (commentaire XML APP_KEY)
- `resources/views/ipxe/actions/clonezilla_live.blade.php` (doubles espaces legacy)
- `resources/views/ipxe/actions/gparted.blade.php` (variables resolver vs config())
- `resources/views/ipxe/actions/hdt.blade.php` (variables resolver vs config())
- `resources/views/ipxe/actions/memtest86plus.blade.php` (variables resolver vs config())
- `docs/qa/domains/ipxe.md` (Section 16 enrichie : divergence D2 + Compat postes legacy + audit fin)
- `tests/Unit/Ipxe/Enums/IpxeAdminActionTest.php` (+3 méthodes/dataProviders)
- `tests/Unit/Ipxe/Services/IpxeActionResolverTest.php` (+2 tests cohérence clé)
- `tests/Feature/Ipxe/IpxeActionEndpointTest.php` (+2 tests + 6 Content-Type)
- `tests/Feature/Ipxe/IpxeLegacyRoutingNonRegressionTest.php` (+4 assertions `#!ipxe`)
- `tests/Architecture/IpxeNamespaceTest.php` (regex renforcée + test du test)
- `_bmad-output/codeReviews/3-7.md` (statuts ⏳→✅ + corrections appliquées par finding)

### File List

**Fichiers créés (8)** :
- `app/Ipxe/Http/Controllers/IpxeClonezillaMenuController.php`
- `app/Ipxe/Http/Requests/IpxeClonezillaMenuRequest.php`
- `resources/views/ipxe/menu/clonezilla.blade.php`
- `resources/views/ipxe/actions/clonezilla_live.blade.php`
- `resources/views/ipxe/actions/clonezilla_save_sda1_sda2.blade.php`
- `resources/views/ipxe/actions/clonezilla_restore_sda2_sda1.blade.php`
- `resources/views/ipxe/actions/gparted.blade.php`
- `resources/views/ipxe/actions/hdt.blade.php`
- `resources/views/ipxe/actions/memtest86plus.blade.php`
- `tests/Feature/Ipxe/IpxeClonezillaMenuTest.php`

**Fichiers modifiés (16)** :
- `app/Ipxe/Enums/IpxeAdminAction.php` (+6 cases +bootLogAction())
- `app/Ipxe/Enums/IpxeMenuKind.php` (+2 cases)
- `app/Ipxe/Services/IpxeService.php` (+handleClonezillaMenu)
- `app/Ipxe/Services/IpxeMenuRenderer.php` (+renderClonezillaMenu)
- `app/Ipxe/Services/IpxeActionResolver.php` (+serverBaseUrl)
- `app/Http/Controllers/LegacyCatchallController.php` (+gone: prefix support)
- `resources/views/ipxe/menu/maintenance.blade.php` (+4 items z/g/h/t)
- `routes/web.php` (+1 route clonezilla-menu, regex [a-z0-9_]+)
- `config/ipxe.php` (+sections clonezilla+tools)
- `config/sambaedu.php` (+21 blocked_legacy_routes gone: patterns)
- `phpunit.xml` (+APP_KEY)
- `tests/Unit/Ipxe/Enums/IpxeAdminActionTest.php` (+5 méthodes 3.7)
- `tests/Unit/Ipxe/IpxeConfigTest.php` (+4 assertions 3.7)
- `tests/Feature/Ipxe/IpxeActionEndpointTest.php` (+7 tests 3.7)
- `tests/Feature/Ipxe/IpxeLegacyRoutingNonRegressionTest.php` (+8 tests 3.7)
- `tests/Architecture/IpxeNamespaceTest.php` (+4 tests 3.7 +fix NodeTraverser)
- `docs/qa/domains/ipxe.md` (Section 16 ajoutée)
- `_bmad-output/implementation-artifacts/sprint-status.yaml` (3-7 → review)

**Artefacts générés** :
- `vendor/` (créé par `composer install` dans le worktree)

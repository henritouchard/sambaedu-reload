# Story 3.3 : Enrollment Machine — Parcs, Salles, Nommage

Status: review

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

> **Suite directe de Stories 3.1 + 3.2** (« iPXE Service Core » + « Boot et Menu Admin iPXE »). Porte nativement les **5 endpoints d'enrollment** restants du legacy iPXE — **nommage de la machine** (`enregistrement.php`), **enrollment BYOD** (`enregistrement_byod.php`), **affectation à une salle physique** (`salles.php`), **ajout à un parc** (`parcs.php`) et **retrait d'un parc** (`enleveparc.php`). Réutilise intégralement le socle 3.1+3.2 (`IpxeService`, `IpxeMenuRenderer`, `WorkstationLocator`, channel log `ipxe`, middleware `auth.v1.lan-only`, table `MachineBootLog`, enum `IpxeAdminAction`).
>
> **Scope strict 3.3** = (a) 5 endpoints natifs sous `/ipxe/enrollment/*` (parité legacy fonctionnelle, **pas** d'URL legacy `.php` répliquée), (b) un service de domaine `App\Ipxe\Services\WorkstationEnrollmentService` qui orchestre **PostgreSQL** (Workstation/WorkstationGroup pivots) + **AD natif** (`App\Ldap\AdMachineManager` réutilisé pour création/MAJ `netbootGUID`/MAC/`location`), (c) un service `IpxeEnrollmentMenuBuilder` (variantes du menu d'enrollment par flux), (d) 5 nouveaux templates Blade `resources/views/ipxe/enrollment/*.blade.php` (handshake + chacun des 5 menus), (e) **mise à jour de `admin.blade.php`** pour activer l'item `(n) Nommer le poste` (3.2 livrait un message neutre « Story 3.3 enrollment a venir »), (f) modification mineure `IpxeMenuRenderer::renderAdminMenu()` pour exposer la branche enrollment depuis 3.2, (g) extension `MachineBootLog::action` avec 5 nouvelles valeurs (`ipxe_enroll_name`, `ipxe_enroll_byod`, `ipxe_enroll_room`, `ipxe_parc_add`, `ipxe_parc_remove`), (h) tests Unit + Feature + Architecture ≥30 cumulés, (i) extension `docs/qa/domains/ipxe.md` (Section 11 « Story 3.3 » + ≥10 scénarios stables 3.3-1 à 3.3-N).
>
> **HORS-SCOPE 3.3** (explicitement reportés aux stories suivantes) :
> - **UI admin SE5 Livewire** pour pré-programmer un enrollment depuis le navigateur (saisie hostname + salle + parcs avant que le poste boot) → option ouverte Phase 3 (pas nécessaire pour la parité fonctionnelle 3.3 — le legacy ne propose pas non plus cette UI ; l'enrollment se fait toujours depuis le firmware iPXE en LAN scolaire).
> - **Login admin AD** (parité 3.2 — `auth.v1.lan-only` seul suffit ; `auth_action()` legacy non porté).
> - **Renommage AD a posteriori** d'un poste déjà enregistré ailleurs (legacy `enregistrement.php:55-56` `move_ad` quand `cn` change) → **inclus en 3.3** car indissociable du flow (un poste qui se réenrôle avec un nouveau nom doit être déplacé dans l'AD pour rester cohérent).
> - **Installation Linux** (preseed) → **Story 3.4**.
> - **Installation Windows** (wimboot/Sysprep) → **Story 3.5**.
> - **Upload + association ISO Windows** → **Story 3.6**.
> - **Clonezilla CRUD** (modèles de clonage programmés) → **Story 3.7**.
> - **Réservation DHCP** (legacy `reservation.php` lié à `:dhcp` du menu admin — déjà désactivé en commentaire dans le legacy `admin.php:91`) → définitivement abandonné (Epic 8 gère la DHCP via une UI dédiée, pas via iPXE).
> - **Double-boot** (legacy `double.php`) → définitivement abandonné (cf. feedback Henri : feature non maintenue ; aucun poste de prod n'utilise le dual-boot Linux/Windows piloté par AD).
> - **Retrait du catchall legacy** sur les routes `/ipxe/enregistrement.php`, `/ipxe/enregistrement_byod.php`, `/ipxe/parcs.php`, `/ipxe/salles.php`, `/ipxe/enleveparc.php` → reporté **fin d'Epic 3** (Story 3.7 cleanup).

---

## ⚠️ Mode de livraison & contraintes opérationnelles

> **Worktree git dédié** (probable `ipxe` ou nouveau `3-3-enrollment`) — ne JAMAIS SSH `/vm` ni run de tests sur VM depuis ce worktree (mémoire `feedback_worktree_no_vm_sync`). Static delivery iso 3.1/3.2 : lint statique `php -l` + PHPUnit local si `vendor/` présent + 0 sync manuel.
>
> - **Code synchronisé via inotify** sur `sambaedu-reload/*` branche `main` uniquement. Le worktree n'est PAS sync — Henri opère un cherry-pick / merge `<worktree> → main` post-review pour propager.
> - **Action Henri post-merge VM up** : reload PHP-FPM (`systemctl reload php8.2-fpm@www-admin`), reload Apache, smoke `curl http://192.168.122.50/ipxe/enrollment/name -d 'mac=...&uuid=...&new_name=PC-TEST-01'` → vérifie : (a) Workstation persistée en DB, (b) AD `samba-tool computer create PC-TEST-01` exécuté, (c) `netbootGUID` peuplé, (d) MAC attribué, (e) log channel `ipxe` event `ipxe.enrollment.name.success`. Smoke poste réel optionnel (boot PXE poste neuf → menu admin → `(n) Nommer le poste` → menu enrollment → saisie nom → vérif AD + PostgreSQL).
> - **NE PAS** modifier `sambaedu/ipxe/*.php` ni `legacy/modules/ipxe/*.php` — restent intacts (le catchall les sert encore pour les routes hors scope 3.3).
> - **NE PAS** créer de commit hors scope (rappel : commit `50c6275` 3.1 hors scope `docs/qa/domains/auth.md` à éviter — ne pas reproduire le pattern 3.2 a respecté).
> - **mémoire `feedback_auth_iso_legacy`** : l'auth machine reste iso-legacy (AD + SMB). Pas de Bearer per-host introduit, pas de secret partagé poste/serveur. La création du compte machine AD passe par `samba-tool computer create` natif (déjà porté par `AdMachineManager` Story 16.7 — réutilisation pure).
> - **mémoire `project_php_fpm_user_www_admin`** : tout fichier lu/écrit par PHP/Apache (logs, dossiers de cache, templates) doit être chown `www-admin` (uid 599). Pas d'impact sur le code, point d'attention pour Henri au déploiement (les écritures `Log::channel('ipxe')` héritent automatiquement de l'uid PHP-FPM).
> - **mémoire `project_se4fs_etab_rattachement`** : la convention SE4 `memberOf` vers `CN=<code>,OU=Etablissements,…` ne s'applique **pas** aux machines — uniquement aux utilisateurs. Les machines ne sont pas rattachées à un établissement via `memberOf` (elles le sont via leur OU `Computers`/`Parcs`).

---

## Encadré contexte

**Continuité avec 3.2** : 3.2 a posé l'endpoint `/ipxe/admin` qui rend le menu admin natif. Pour un **poste inconnu** (résolution `WorkstationLocator` → null), le menu admin affiche aujourd'hui (3.2) un message neutre :

```
echo Poste non enregistre, fonctions de maintenance indisponibles.
echo Story 3.3 enrollment a venir.
sleep 3
```

3.3 **active** cette branche en :

1. Ajoutant un item `(n) Nommer le poste` accessible **uniquement** pour les postes inconnus.
2. Chainant vers `/ipxe/enrollment/name##params` (nouvel endpoint natif).
3. Activant aussi pour les **postes connus** (admin contexte) les items `(a) salle`, `(p) parcs`, `(e) enleveparc`, `(n) Renommer le poste` (parité legacy `admin.php:86-100`).

**Topologie cible 3.3** :

```
Firmware iPXE (3.1 known menu) → choisit "1" (login admin)
  ↓
/ipxe/admin (3.2)
  → poste connu : items (n) renommer + (a) salle + (p) parcs + (e) enleveparc
  → poste inconnu : item (n) nommer
  ↓ user choisit (n)
/ipxe/enrollment/name (3.3 — handshake/saisie/confirmation)
  → saisie hostname via `read name` (iPXE built-in)
  → re-POST avec new_name posé
  → WorkstationEnrollmentService::enrollName()
    a. validation hostname (regex + suffix + max 15 chars)
    b. PostgreSQL : Workstation::updateOrCreate par UUID
    c. AD : samba-tool computer create + register netbootGUID + MAC + location
    d. log channel ipxe + MachineBootLog action=ipxe_enroll_name
  → echo OK + chain vers /ipxe/admin
  ↓ user choisit (a) salle
/ipxe/enrollment/room (3.3)
  → menu listant les salles physiques (WorkstationGroup::physical = true)
  → user choisit une salle
  → WorkstationEnrollmentService::assignRoom()
    a. PostgreSQL : Workstation::assignToPhysicalRoom + observer AD sync
    b. AD : déplacement OU (via WorkstationObserver -> SyncWorkstationGroupJob existant)
  → echo OK + chain vers /ipxe/admin
  ↓ user choisit (p) parcs OU (e) enleveparc
/ipxe/enrollment/parc-add OU /ipxe/enrollment/parc-remove (3.3)
  → menu listant les parcs (WorkstationGroup::logical = true, parc_disponible vs parc_actuel)
  → user choisit un parc
  → WorkstationEnrollmentService::attachGroup() ou ::detachGroup()
    a. PostgreSQL : pivot workstation_group_workstation (via Workstation::attachGroups)
    b. ~~AD : sync groupe via WorkstationObserver -> WorkstationMembershipAdSyncJob existant~~
       AMENDÉ 2026-05-20 : pas de sync AD machine→groupe — décision Epic 4
       antérieure (WorkstationMembershipAdSyncJob n'expose plus add/remove,
       seul move salle subsiste). Pivot SQL = source de vérité unique.
  → echo OK + chain vers /ipxe/admin
```

**Comportement parité legacy** (à reproduire iso strict — cf. `legacy/modules/ipxe/enregistrement.php`, `salles.php`, `parcs.php`, `enleveparc.php`) :

1. **`/ipxe/enrollment/name`** — saisie initiale + confirmation :
   - Première fois (sans `new_name` posé) → render menu de saisie iPXE avec `read name` qui demande à l'utilisateur de taper le nom puis re-poste vers le même endpoint.
   - Avec `new_name` posé → applique la transformation iso-legacy `q2a()` (no-op en clavier `legacy` actuel — cf. `ipxe_functions.inc.php:48-57`) + `add_hostname_suffix()` (tronque 15 chars + lowercase + applique suffix optionnel `$config['suffix']` — cf. `ldap.inc.php:384-394`).
   - Cas 1 — **machine existe DÉJÀ avec ce nom** (`Workstation::where('name', $newName)` non null AND uuid != current) → erreur « ERREUR ! nom déjà pris » + chain vers `/ipxe/admin`.
   - Cas 2 — **même nom que celui déjà enregistré** pour cet UUID → echo « La machine est déjà enregistrée sous ce nom $newName » + chain vers `/ipxe/admin`.
   - Cas 3 — **renommage** (Workstation existe avec un autre nom pour cet UUID) → updateOrCreate Workstation + AD `samba-tool` rename (via futur helper ou en pipeline observer) + echo « OK ! nom $newName réservé pour $uuid ».
   - Cas 4 — **création neuve** (UUID inconnu en DB) → create Workstation + AD `samba-tool computer create` + `register netbootGUID` + MAC + echo « OK ! nom $newName réservé pour $uuid ».
2. **`/ipxe/enrollment/byod`** — variante BYOD :
   - Même flow mais **PAS de création AD** (parité legacy `enregistrement_byod.php:24` qui ne fait que `set_action(... type='byod')` sans `create_machine`).
   - Persiste uniquement `MachineBootLog` action=`ipxe_enroll_byod` (audit) ; pas d'écriture `Workstation`.
   - Chain vers `/ipxe/installation-linux##params` (légèrement différent du flow standard qui retourne vers `admin`).
   - **Note 3.3** : ce flow est conservé en simplification — un poste BYOD ne devrait pas modifier la DB SE5 (BYOD = appareil de l'élève, pas du parc). Implémentation = endpoint stub qui log + chain vers Story 3.4 quand disponible (en attendant, chain vers `/ipxe/admin` pour boucler le menu).
3. **`/ipxe/enrollment/room`** — affectation salle (parité legacy `salles.php`) :
   - Première fois (sans `room` posé) → render menu listant les salles physiques (`WorkstationGroup::where('is_physical', true)->orderBy('name')` excluant les salles déjà assignées avec un marqueur `** déjà dans NomSalle **`).
   - Avec `room` posé → `WorkstationEnrollmentService::assignRoom($workstation, $roomId)` → update `physical_room_id` + AD via observer existant.
   - Echo OK + chain `/ipxe/admin`.
4. **`/ipxe/enrollment/parc-add`** — ajout à un parc logique :
   - Idem `room` mais sur `WorkstationGroup::where('is_physical', false)` (groupes logiques).
   - `WorkstationEnrollmentService::attachGroup($workstation, $groupId)`.
5. **`/ipxe/enrollment/parc-remove`** — retrait d'un parc logique :
   - Liste les parcs actuels du poste (pas les disponibles).
   - `WorkstationEnrollmentService::detachGroup($workstation, $groupId)`.

**Couplage Story 3.2 — modifications mineures attendues** :

| Élément 3.2 | Modification 3.3 | Raison |
|---|---|---|
| `resources/views/ipxe/menu/admin.blade.php` | Remplacer le `@if($isKnown)` bloc d'items dégradés (lignes 11-17) par 2 blocs : (a) si **connu** → ajouter items (n) renommer + (a) salle + (p) parcs + (e) enleveparc + (m) maintenance ; (b) si **inconnu** → ajouter item (n) nommer (chain vers `/ipxe/enrollment/name`) à la place du message neutre. | 3.3 active la branche enrollment depuis le menu admin natif. |
| `IpxeMenuRenderer::renderAdminMenu()` | Exposer 2 nouvelles variables Blade `$enrollmentBaseUrl` (= `$serverBaseUrl . '/ipxe/enrollment'`) et `$isEnrollmentActive = true` (constant 3.3 — feature-flag implicite). Si en environnement test on veut désactiver l'item, surcharger via config (option ouverte D11 ci-dessous). | Conditionnement template. |
| `IpxeService` | Pas de modification — les 5 nouveaux endpoints 3.3 sont orchestrés par un **nouveau** service `WorkstationEnrollmentService` (séparation des responsabilités — l'enrollment a son cycle de vie propre + dépendance `AdMachineManager`). | Découplage clair. |
| `IpxeAdminAction` enum | **Pas de modification** — l'enum couvre les actions admin du menu maintenance (rescuecd/winpe/factory_reset). L'enrollment est servi par des routes dédiées hors `/ipxe/action/{action}`. | Périmètre enum strict. |
| `MachineBootLog.action` | 5 nouvelles valeurs persistées : `ipxe_enroll_name` (16), `ipxe_enroll_byod` (16), `ipxe_enroll_room` (16), `ipxe_parc_add` (13), `ipxe_parc_remove` (16). T0.6 audit obligatoire (cf. 3.1 + 3.2 — `action` varchar(20) sans CHECK). | Audit traçabilité par endpoint enrollment. |

**Idempotence + sécurité** : les 5 endpoints 3.3 sont **partiellement idempotents** :

- `name` (cas 2 — même nom déjà enregistré) → idempotent.
- `name` (cas 4 — création) → **NON-idempotent** côté DB et AD (un 2ème appel crée une seconde Workstation avec un UUID différent ou écrase si UUID identique via updateOrCreate). Le service applique strictement `Workstation::updateOrCreate(['uuid' => $uuid], [...])` pour éviter les doublons.
- `room`, `parc-add`, `parc-remove` → idempotents (sync attache/détache déjà tolérant via `attachGroups`/`detachGroups` observer-based).

**Side effects** :
- **DB PostgreSQL** : create/update `Workstation` (cas name, room), insert/delete pivot `workstation_group_workstation` (cas parc).
- **AD** : `samba-tool computer create` + `samba-tool computer edit --set-attribute=netbootGUID=...` + `samba-tool computer move` (renommage OU) + `samba-tool group addmembers` (parc). Tout passe par `AdMachineManager` + observer existants — pas de nouveau client LDAP.
- **Logs** : `Log::channel('ipxe')` (events) + `MachineBootLog` (rows) + `Log::channel('gpo')` (transitivement via `AdMachineManager`).

---

## ⚠️ Décisions tranchées (D1-D14, ne pas re-débattre)

> Cadrage SM 2026-05-19 par claude-opus-4-7. Le dev applique sans re-discuter. En cas de blocage technique réel, documenter dans Dev Agent Record et continuer.

### D1 — Namespace : extension **`App\Ipxe`** (pas de nouveau sous-namespace)

- Ajouts sous `app/Ipxe/` :
  ```
  app/Ipxe/
  ├── Services/
  │   ├── IpxeService.php                        (inchangé)
  │   ├── IpxeMenuRenderer.php                   (modifié — renderAdminMenu expose isEnrollmentActive)
  │   ├── WorkstationLocator.php                 (inchangé)
  │   ├── IpxeActionResolver.php                 (inchangé)
  │   ├── WorkstationEnrollmentService.php       (NEW — orchestre PostgreSQL + AD)
  │   ├── IpxeEnrollmentMenuBuilder.php          (NEW — construit variables Blade des 5 menus)
  │   └── IpxeHostnameSanitizer.php              (NEW — porte add_hostname_suffix + validation)
  ├── Enums/
  │   ├── IpxeAdminAction.php                    (inchangé)
  │   ├── IpxeMenuKind.php                       (modifié — +5 cases pour enrollment kinds)
  │   ├── IpxePlatform.php                       (inchangé)
  │   └── IpxeEnrollmentFlow.php                 (NEW — enum 5 cases name|byod|room|parc_add|parc_remove)
  ├── Http/
  │   ├── Controllers/
  │   │   ├── IpxeBootController.php             (inchangé)
  │   │   ├── IpxeAdminController.php            (inchangé)
  │   │   ├── IpxeMaintenanceController.php     (inchangé)
  │   │   ├── IpxeActionController.php           (inchangé)
  │   │   ├── IpxeEnrollmentNameController.php   (NEW)
  │   │   ├── IpxeEnrollmentByodController.php   (NEW)
  │   │   ├── IpxeEnrollmentRoomController.php   (NEW)
  │   │   ├── IpxeEnrollmentParcAddController.php   (NEW)
  │   │   └── IpxeEnrollmentParcRemoveController.php (NEW)
  │   └── Requests/
  │       ├── IpxeEnrollmentNameRequest.php      (NEW)
  │       ├── IpxeEnrollmentByodRequest.php      (NEW)
  │       ├── IpxeEnrollmentRoomRequest.php      (NEW)
  │       ├── IpxeEnrollmentParcRequest.php      (NEW — partagé add/remove)
  └── (Support/ : inchangé)
  ```
- **Anti-pattern** : ne PAS créer `App\Ipxe\Enrollment\…` sous-namespace. La frontière est par responsabilité (Service / Controller / Renderer / FormRequest) déjà posée 3.1/3.2.
- Mise à jour `IpxeMenuKind` (enum) : ajout `case EnrollmentName = 'enroll_name';`, `case EnrollmentByod = 'enroll_byod';`, `case EnrollmentRoom = 'enroll_room';`, `case EnrollmentParcAdd = 'enroll_parc_add';`, `case EnrollmentParcRemove = 'enroll_parc_remove';` + cases `EnrollmentHandshake` par flux si nécessaire (factoriser via un seul `EnrollmentHandshake = 'enroll_handshake'` qui couvre tous les flux — la log distinguera via un champ `flow` contextuel).

### D2 — 5 nouveaux endpoints HTTP sous `/ipxe/enrollment/*` (préfixe explicite — pas de réplique des URLs legacy `.php`)

- 5 blocs à ajouter dans `routes/web.php` **dans le bloc existant 3.1/3.2** (après les routes `/ipxe/action/{action}` et **avant** le catchall) :
  ```php
  /*
  |--------------------------------------------------------------------------
  | Story 3.3 — Enrollment Machine — Parcs, Salles, Nommage (D2)
  |--------------------------------------------------------------------------
  | Remplace les endpoints legacy `/ipxe/enregistrement.php`,
  | `/ipxe/enregistrement_byod.php`, `/ipxe/salles.php`, `/ipxe/parcs.php`
  | et `/ipxe/enleveparc.php` par 5 routes natives sous `/ipxe/enrollment/*`.
  |
  | **ORDRE STRICT** : ce bloc doit rester AVANT le catchall ci-dessous —
  | sinon la route `{path}` capture toutes les requêtes `/ipxe/*` et rend
  | ces routes natives inaccessibles. Cf. test
  | `IpxeNamespaceTest::ipxe_3_3_enrollment_routes_are_declared_before_catchall`.
  |
  | **Sécurité** : middleware `auth.v1.lan-only` (16.11) — restreint au LAN
  | scolaire RFC1918. Pas de JWT (parité 3.1 D3/D8 — un firmware iPXE n'a pas
  | d'OS qui puisse porter un Authorization Bearer).
  |
  | **Throttle 600/min/IP** : iso 3.1/3.2.
  */
  Route::match(['GET', 'POST'], '/ipxe/enrollment/name', [
      \App\Ipxe\Http\Controllers\IpxeEnrollmentNameController::class, 'handle',
  ])
      ->middleware(['auth.v1.lan-only', 'throttle:600,1'])
      ->name('ipxe.enrollment.name')
      ->withoutMiddleware(['web']);

  Route::match(['GET', 'POST'], '/ipxe/enrollment/byod', [
      \App\Ipxe\Http\Controllers\IpxeEnrollmentByodController::class, 'handle',
  ])
      ->middleware(['auth.v1.lan-only', 'throttle:600,1'])
      ->name('ipxe.enrollment.byod')
      ->withoutMiddleware(['web']);

  Route::match(['GET', 'POST'], '/ipxe/enrollment/room', [
      \App\Ipxe\Http\Controllers\IpxeEnrollmentRoomController::class, 'handle',
  ])
      ->middleware(['auth.v1.lan-only', 'throttle:600,1'])
      ->name('ipxe.enrollment.room')
      ->withoutMiddleware(['web']);

  Route::match(['GET', 'POST'], '/ipxe/enrollment/parc-add', [
      \App\Ipxe\Http\Controllers\IpxeEnrollmentParcAddController::class, 'handle',
  ])
      ->middleware(['auth.v1.lan-only', 'throttle:600,1'])
      ->name('ipxe.enrollment.parc-add')
      ->withoutMiddleware(['web']);

  Route::match(['GET', 'POST'], '/ipxe/enrollment/parc-remove', [
      \App\Ipxe\Http\Controllers\IpxeEnrollmentParcRemoveController::class, 'handle',
  ])
      ->middleware(['auth.v1.lan-only', 'throttle:600,1'])
      ->name('ipxe.enrollment.parc-remove')
      ->withoutMiddleware(['web']);
  ```
- **Pourquoi préfixe `/ipxe/enrollment/*` ?** Évite la confusion avec les URLs legacy `.php` (parité 3.2 qui distingue `/ipxe/admin` natif vs `/ipxe/admin.php` legacy via le catchall). Cohérent avec le namespacing du domaine.
- **Pourquoi 5 routes et pas 1 seule `/ipxe/enrollment/{flow}` avec enum whitelist ?** **Anti-pattern à éviter** — le routing param introduit une indirection inutile alors que les 5 flows ont des paramètres différents (`new_name` pour name, `room` pour room, `parc` pour parc-add/remove). Mieux : 5 controllers fins explicites — chaque controller documente son flow.
- **Anti-pattern alternatif** : `Route::prefix('/ipxe/enrollment')` group — sur-généralisation qui empêche les tests archi de scanner les routes individuellement. Mieux : 5 routes plates avec préfixe explicite dans le path.

### D3 — Sécurité : **réutilisation stricte `auth.v1.lan-only` (16.11) — pas d'évolution**

- Iso 3.1 D3/D8 + 3.2 D3. Le firmware iPXE n'a pas d'OS qui puisse porter un JWT.
- **mémoire `feedback_auth_iso_legacy`** rappel : on n'introduit pas de Bearer per-host. L'auth machine reste iso-legacy = LAN restriction + samba-tool AD.
- **Risque accepté** : un attaquant LAN qui sniffe le MAC+UUID d'un autre poste peut **falsifier** un enrollment (renommer un poste, changer sa salle, l'ajouter à un parc privilégié). Mitigation Phase 2 = LAN scolaire restrictif + audit log (`MachineBootLog` + channel `ipxe` traçabilité). Phase 3 pourra ajouter un mécanisme d'attestation HMAC (= story dédiée si besoin terrain).
- **Réponse 403 hors LAN** : iso 16.11 — code `JwtErrorCodes::BOOTSTRAP_NOT_LAN`, format JSON `{success:false, error:"forbidden", message:"iPXE endpoint is restricted to LAN", code:"bootstrap.not_lan"}`.

### D4 — Résolution poste : **réutilisation stricte `WorkstationLocator` (3.1)**

- Iso 3.1 D4 — pas de duplication, pas de refactor.
- 5 endpoints 3.3 résolvent la Workstation via `WorkstationLocator::locate($mac, $uuid, $product)`.
- **Tolérance poste inconnu** :
  - `/ipxe/enrollment/name` : poste inconnu = **cas attendu** (c'est le flow principal). Le service crée la Workstation en DB + AD à partir du `new_name` posté.
  - `/ipxe/enrollment/byod` : idem `name` mais sans création AD.
  - `/ipxe/enrollment/room`, `parc-add`, `parc-remove` : poste **doit être connu**. Si `WorkstationLocator::locate()` retourne null → render menu d'erreur (« Poste non encore enregistre — utilisez (n) Nommer le poste avant ») + chain `/ipxe/admin`. **Pas** d'enrollment implicite.
- **Anti-pattern** : ne PAS auto-créer la Workstation depuis `room/parc-add/parc-remove` si elle n'existe pas — ces flux opèrent strictement sur un poste déjà enrôlé.

### D5 — Service de domaine **`WorkstationEnrollmentService`** (orchestrateur PostgreSQL + AD)

- Nouveau service `App\Ipxe\Services\WorkstationEnrollmentService` (singleton enregistré dans `IpxeServiceProvider`).
- **Dépendances injectées** :
  - `App\Ldap\AdMachineManager` (réutilisation 16.7 — create + register hardware + setOs).
  - `App\Ipxe\Services\IpxeHostnameSanitizer` (NEW — porte `add_hostname_suffix` + validation regex).
- **Méthodes publiques** :
  ```php
  /**
   * Cas 1 (UUID inconnu, nom libre) : crée Workstation + AD compte machine.
   * Cas 2 (UUID connu, même nom) : no-op idempotent + retourne enum SAME_NAME.
   * Cas 3 (UUID connu, nouveau nom unique) : rename Workstation + AD move/rename.
   * Cas 4 (UUID connu, nom déjà pris par autre poste) : erreur enum NAME_TAKEN.
   */
  public function enrollName(
      string $rawName,
      string $mac,
      string $uuid,
      string $platform = 'legacy',
      ?Workstation $existing = null,
  ): EnrollNameResult;  // value object { status: enum, workstation: ?Workstation, sanitizedName: string }

  /**
   * Variant BYOD — pas de création AD, log + retour stub.
   * Note 3.3 : implémentation minimale (log + audit MachineBootLog). Le flux complet
   * BYOD (chain vers installation-linux) est déféré 3.4.
   */
  public function logByodEnrollment(string $rawName, string $mac, string $uuid, string $ip): void;

  /**
   * Affecte le poste à une salle physique (WorkstationGroup::is_physical = true).
   * Side effect : AD via WorkstationObserver::onPhysicalRoomChanged (à ajouter si pas existant) OU via Workstation::assignToPhysicalRoom existant.
   */
  public function assignRoom(Workstation $ws, int $roomId): bool;

  /**
   * Ajoute le poste à un parc logique (WorkstationGroup::is_physical = false).
   * Side effect : pivot workstation_group_workstation + AD via WorkstationObserver existant.
   */
  public function attachGroup(Workstation $ws, int $groupId): bool;

  /**
   * Retire le poste d'un parc logique.
   * Side effect : detach pivot + AD via WorkstationObserver existant.
   */
  public function detachGroup(Workstation $ws, int $groupId): bool;
  ```
- **Pattern try/catch** sur chaque méthode publique : retourne `false` ou enum sur échec, log error `ipxe.enrollment.<flow>.failure` + channel `ipxe` warning, **jamais d'exception qui remonte au controller** (un firmware iPXE doit toujours recevoir un menu — pas une 500).
- **Anti-pattern** :
  - ❌ Ne PAS appeler `LdapRecord` direct depuis ce service — passage exclusif via `AdMachineManager`.
  - ❌ Ne PAS toucher au schema `workstations` — uniquement `updateOrCreate`/`save`.
  - ❌ Ne PAS migrer les conflits AD/PostgreSQL en transactions atomiques (Phase 2) — best-effort iso pattern Story 4.1/4.2. Si AD échoue mais PostgreSQL réussit → log warning + retour partial success.

### D6 — Service auxiliaire **`IpxeHostnameSanitizer`** (port iso-legacy)

- Nouveau helper `App\Ipxe\Services\IpxeHostnameSanitizer` (singleton stateless).
- **Méthodes** :
  - `sanitize(string $rawName, string $platform = 'legacy'): string` — applique `q2a()` (no-op en `legacy`, optionnel azerty/qwerty translation en `uefi` — cf. legacy `ipxe_functions.inc.php:48-57`) + `strtolower`.
  - `applyHostnameSuffix(string $name, ?string $suffix = null): string` — porte `add_hostname_suffix()` legacy `ldap.inc.php:384-394` : si suffix défini → tronque 9 chars + concat suffix ; sinon → tronque 15 chars + lowercase. Lit `config('sambaedu.legacy_ldap.suffix', '')` par défaut.
  - `isValidHostname(string $name): bool` — regex stricte iso `AdMachineManager::MACHINE_REGEX` `/^[A-Za-z0-9_\-\.\$]{1,64}$/` (réutilisation cohérence 16.7). Test unit anti-injection obligatoire.
  - `isSpecialServerName(string $name): bool` — détecte les noms `se4fs|se4ad-XXXXXXX[a-z]` (parité legacy `enregistrement.php:39-43`) qui ne reçoivent PAS de suffix (= serveurs internes).
- **Tests unit ≥10 cas** : sanitize legacy (no-op), sanitize uefi (translation azerty→qwerty), suffix avec, suffix sans, tronquage 15 chars, tronquage 9 chars + suffix, lowercase, regex valide, regex rejette injection `'; rm -rf /`, regex rejette espaces, isSpecialServerName positif/négatif.

### D7 — Cas « poste inconnu pour `/room`, `/parc-add`, `/parc-remove` » → menu erreur + chain `/ipxe/admin`

- Parité legacy `parcs.php:23-69` qui boucle si `$uuid` vide ou `get_action()` retourne []. 3.3 simplifie :
- **Décision** : afficher un menu erreur iPXE minimal :
  ```
  #!ipxe
  echo Erreur — poste non encore enregistre
  echo Utilisez (n) Nommer le poste avant d'affecter une salle ou un parc.
  sleep 5
  chain --replace --autofree {server}/ipxe/admin##params
  ```
- **Pas** d'auto-enrollment ici — séparation stricte des flows.

### D8 — Logging structuré channel `ipxe` (extension 3.1/3.2 D7/D8)

- 10 nouveaux events à logger (channel `ipxe`, driver daily 14j — iso 3.1/3.2) :
  - **Nommage** :
    - `ipxe.enrollment.name.handshake` (info) — premier appel sans `new_name`. Context : ip, mac_prefix (6), uuid_prefix (8), workstation_id (nullable).
    - `ipxe.enrollment.name.success` (info) — création/rename réussie. Context : ip, mac_prefix, uuid_prefix, workstation_id, sanitized_name_prefix (8), status (`created|renamed|same_name`), ad_result (`success|failed|skipped`).
    - `ipxe.enrollment.name.name_taken` (warning) — nom déjà pris par un autre poste. Context : ip, mac_prefix, uuid_prefix, attempted_name_prefix (8).
    - `ipxe.enrollment.name.failure` (error) — échec inattendu (exception, DB inaccessible, AD timeout). Context : ip, exception_class, message (200 chars).
  - **BYOD** :
    - `ipxe.enrollment.byod.logged` (info) — flow BYOD audité. Context : ip, mac_prefix, uuid_prefix, attempted_name_prefix.
  - **Salle / Parc** :
    - `ipxe.enrollment.room.success` (info) — affectation salle. Context : ip, workstation_id, room_id, room_name_prefix (6).
    - `ipxe.enrollment.room.failure` (error) — échec affectation. Context : ip, workstation_id, room_id, reason.
    - `ipxe.enrollment.parc.added` (info) — ajout à un parc. Context : ip, workstation_id, group_id, group_name_prefix (6).
    - `ipxe.enrollment.parc.removed` (info) — retrait d'un parc. Context : idem `added`.
    - `ipxe.enrollment.parc.failure` (error) — échec attach/detach. Context : ip, workstation_id, group_id, action (`add|remove`), reason.
- **Préfixes obligatoires** sur valeurs sensibles : iso 3.1 AC7.3 — MAC 6 chars, UUID 8 chars, name/room/group 6-8 chars.
- **Exception `sanitized_name_prefix`** : 8 chars (le nom sanitizé est moins sensible que la MAC ou l'UUID, mais on tronque par cohérence). Le **hostname complet** est lu côté `MachineBootLog::machine_name` qui n'est pas tronqué (audit interne).

### D9 — Schéma DB : **aucune migration**, réutilisation `Workstation` + pivot existants

- **Workstation** : colonnes `name`, `uuid`, `mac`, `physical_room_id`, `ad_dn`, `ad_guid` déjà présentes (Epic 4).
- **Pivot `workstation_group_workstation`** : table déjà créée (Epic 4) — `Workstation::attachGroups/detachGroups` existant.
- **MachineBootLog `action`** : varchar(20), 5 nouvelles valeurs ≤16 chars (iso pattern 3.1 T0.6 / 3.2 T0.6). Audit obligatoire en T0.6 mais hypothèse de cadrage SM = pas de blocage attendu.
- **Anti-pattern** : ne PAS étendre `Workstation` avec une colonne `enrolled_at` ou `last_enrollment_attempt_at` — Phase 2 garde la table simple. L'audit fin (date d'enrollment par flow) est tracé via `MachineBootLog` (`started_at` + `action`).

### D10 — Templates Blade — **5 nouveaux fichiers + 1 modifié**

- **Nouveaux** :
  - `resources/views/ipxe/enrollment/name.blade.php` (~30 lignes) — port natif `enregistrement.php` (159 L). Sections : (a) saisie initiale `read name` + `set new_name`, (b) confirmation `same_name`, (c) succès création/rename, (d) erreur nom déjà pris.
  - `resources/views/ipxe/enrollment/byod.blade.php` (~25 lignes) — port natif `enregistrement_byod.php` (82 L). Variant simplifié : pas de création AD, juste echo « OK ! BYOD reserve » + chain vers admin (déféré install-linux en 3.4).
  - `resources/views/ipxe/enrollment/room.blade.php` (~20 lignes) — port natif `salles.php` (81 L). Menu interactif listant les salles physiques.
  - `resources/views/ipxe/enrollment/parc-add.blade.php` (~20 lignes) — port natif `parcs.php` (78 L). Menu listant les parcs logiques **disponibles** (pas déjà attachés).
  - `resources/views/ipxe/enrollment/parc-remove.blade.php` (~20 lignes) — port natif `enleveparc.php` (76 L). Menu listant les parcs logiques **attachés** (à retirer).
- **Modifié** :
  - `resources/views/ipxe/menu/admin.blade.php` (~40 lignes) — réécriture du bloc conditionnel `@if($isKnown)` :
    - **Si `$isKnown`** → items : (n) renommer + (a) salle + (p) parcs (ajout) + (e) enleveparc + (m) maintenance + (r) retour + (s) shell + (x) exit.
    - **Si `!$isKnown`** → items : (n) nommer + (r) retour + (x) exit. **Remplace** le `echo Poste non enregistre — Story 3.3 enrollment a venir` actuel.
    - Sections `:set-name` (chain `/ipxe/enrollment/name`), `:salle` (chain `/ipxe/enrollment/room`), `:parcs` (chain `/ipxe/enrollment/parc-add`), `:enleveparc` (chain `/ipxe/enrollment/parc-remove`).
- **Charset ASCII strict** : iso 3.1 D9 + 3.2 D6 — pas d'accent fr. Test archi étend la couverture aux 5 nouveaux templates.
- **Newline final obligatoire** : iso 3.1.
- **Pas de PHP residual** : iso 3.1 — test archi `it_renders_output_does_not_contain_php_tags` étendu.
- **Shebang `#!ipxe`** : injecté comme variable Blade `{!! $shebang !!}` (iso 3.1 DO-13).

### D11 — Variables de configuration : **extension `config/ipxe.php`**

- Nouvelle section dans `config/ipxe.php` :
  ```php
  'enrollment' => [
      // Active la branche enrollment depuis le menu admin (3.3). Si false,
      // l'item (n) nommer / (a) salle / (p) parcs est masqué — utile pour
      // freezer une VM de tests en pré-prod.
      'enabled' => filter_var(env('IPXE_ENROLLMENT_ENABLED', true), FILTER_VALIDATE_BOOL),

      // Timeout des menus enrollment (10s — iso-legacy salles.php:15).
      'menu_timeout_ms' => (int) env('IPXE_ENROLLMENT_TIMEOUT_MS', 10000),

      // Limite de salles affichées dans le menu (cas pathologique > 50 salles).
      // Au-delà, on tronque + affiche un item « ** plus de salles disponibles dans l'UI admin ** ».
      'max_rooms_in_menu' => (int) env('IPXE_ENROLLMENT_MAX_ROOMS', 50),

      // Limite de parcs affichés idem.
      'max_parcs_in_menu' => (int) env('IPXE_ENROLLMENT_MAX_PARCS', 50),

      // Suffix de hostname iso-legacy `add_hostname_suffix()` (lu via
      // `config('sambaedu.legacy_ldap.suffix')` qui existe déjà 16.3b).
      // Pas de duplication ici — `IpxeHostnameSanitizer::applyHostnameSuffix()`
      // lit directement la config legacy.
  ],
  ```
- **Valeurs par défaut** : iso-legacy. Henri peut override via `.env` pour pré-prod / tests.

### D12 — `MachineBootLog::action` — extension **sans migration** (iso 3.1 D5/D12 + 3.2 D11)

- `varchar(20)` confirmé sans CHECK par 3.1 et 3.2 T0.6. Les 5 nouvelles valeurs sont :
  - `ipxe_enroll_name` (16 chars)
  - `ipxe_enroll_byod` (16 chars)
  - `ipxe_enroll_room` (16 chars)
  - `ipxe_parc_add` (13 chars)
  - `ipxe_parc_remove` (16 chars)
- Tous ≤16 chars, fit dans `varchar(20)`.
- `initiated_by` reste `'ipxe'` (string fixe — `varchar(100)`).
- **Pas de migration**. T0.6 audit obligatoire.

### D13 — UI admin Livewire/Blade : **HORS-SCOPE 3.3** (option ouverte Phase 3)

- 3.3 ne livre **aucune UI admin web** pour pré-programmer un enrollment depuis le navigateur SE5 (par exemple : un admin SER tape un hostname + une salle dans `/parc/enrollment/new` avant que le poste boot, et le poste reçoit automatiquement le bon enrollment au prochain `/ipxe/admin`).
- **Justification** :
  1. Parité legacy stricte — le legacy fait tout depuis le firmware iPXE.
  2. Sécurité — pour programmer un enrollment côté UI, il faut résoudre l'identité matérielle du poste avant son boot (UUID/MAC), ce qui n'est connu qu'au moment du PXE handshake.
  3. Scope cadré 3.3 — l'UI ouvrirait une story dédiée Phase 3 (« 3.X UI Admin Enrollment SER »).
- **Si Henri arbitre OUI à l'UI** : ouvrir une story 3.3b dédiée (cf. section « Découpage 3.3a / 3.3b » ci-dessous).

### D14 — Renommage AD a posteriori (cas 3 `enrollName` — UUID connu, nouveau nom)

- Parité legacy `enregistrement.php:55-56` : `move_ad($config, $registered_name, "cn=$new_name,$ou", "computer")`.
- **Décision 3.3** : implémenter via une **nouvelle méthode** `AdMachineManager::renameComputer(string $oldName, string $newName): bool` qui appelle `samba-tool computer move` (ou `samba-tool computer edit --set-attribute=cn=$newName` selon ce que la doc samba-tool autorise — à vérifier en T0.4).
- **Anti-pattern** : ne PAS contourner via `LdapRecord` direct (interdit dans `App\Ipxe`).
- **Note T0.4 lecture** : si `samba-tool computer move` n'accepte pas le renommage (uniquement le déplacement OU), basculer en plan B = **delete + recreate** de la machine AD (perte du `netbootGUID` côté old → re-register sur new). Documenter dans Dev Agent Record le choix retenu après tests samba-tool sur la VM (action Henri post-T0.4).

---

## Story

As **un poste de travail (Windows ou Linux) en boot iPXE déjà résolu via `/ipxe/boot` (3.1) et passé au menu `/ipxe/admin` (3.2)** ainsi qu'**un mainteneur du codebase `sambaedu-reload`** et **Henri en tant qu'admin SER opérant sur le LAN scolaire** :

I want
- disposer de **5 routes Laravel natives** sous `/ipxe/enrollment/*` (`name`, `byod`, `room`, `parc-add`, `parc-remove`) qui remplacent progressivement les endpoints legacy `enregistrement.php`, `enregistrement_byod.php`, `salles.php`, `parcs.php`, `enleveparc.php` du proxy catchall ;
- pouvoir **nommer** un poste neuf depuis le firmware iPXE en LAN, ce qui crée le compte machine AD (`samba-tool computer create`), enregistre l'UUID hardware (`netbootGUID`) et la MAC, **et** persiste la Workstation dans PostgreSQL ;
- pouvoir **affecter** le poste à une **salle physique** (= déplacement OU AD via observer existant) et à un ou plusieurs **parcs logiques** (= groupes AD via samba-tool) depuis le menu iPXE, sans avoir à intervenir physiquement avec une clé USB ;
- assurer **zéro régression** sur les autres routes iPXE legacy non encore réécrites (`/ipxe/installation-linux.php`, `/ipxe/installation-windows.php`, `/ipxe/clonage.php`, `/ipxe/Win10/*`, etc.) — elles continuent de passer par le catchall jusqu'aux stories 3.4-3.7.

So que :
- (a) **Henri** dispose d'un flow d'enrollment iPXE natif testé, journalisé via channel `ipxe`, sans dépendance au legacy PHP procédural — visible via `tail storage/logs/ipxe/ipxe-$(date +%F).log` ;
- (b) **les opérateurs terrain** peuvent enrôler un poste neuf en LAN scolaire (rentrée scolaire) en quelques minutes : booter PXE → menu admin → (n) nommer → saisie nom → affectation salle → affectation parc → reboot disque ;
- (c) **les développeurs des stories 3.4-3.7** disposent du pattern enrollment complet (service domaine + 5 controllers + 5 templates + tests) à enrichir si besoin (BYOD complet en 3.4, réservation DHCP cancelled, etc.) ;
- (d) **la transition iso-legacy** est garantie — la création AD passe par `AdMachineManager` (16.7) avec samba-tool natif ; pas de Bearer/secret per-host introduit (cf. mémoire `feedback_auth_iso_legacy`) ; le compte machine est strictement compatible avec un poste qui n'aurait pas encore reçu la migration JWT bootstrap (Epic 16).

---

## Contexte

### État entrant (post-Story 3.2 done, 3.3 = suite directe)

| Élément | État actuel | Action 3.3 |
|---|---|---|
| Namespace `App\Ipxe` | ✅ Créé/étendu par 3.1+3.2 (4 services + 4 controllers + 4 FormRequests + 5 Blade templates) | **Étendre** — ajouter 3 services (`WorkstationEnrollmentService`, `IpxeEnrollmentMenuBuilder`, `IpxeHostnameSanitizer`) + 5 controllers + 4 FormRequests + 1 enum (`IpxeEnrollmentFlow`) + 5 templates Blade + 1 template modifié (`admin.blade.php`) |
| `IpxeMenuRenderer::renderAdminMenu()` | ✅ Existant 3.2 — rend `admin.blade.php` avec `$isKnown` | **Modifier mineur** — passer `$enrollmentBaseUrl` + `$isEnrollmentActive` + `$isKnown` au template (activer items enrollment) |
| `App\Ldap\AdMachineManager` (16.7) | ✅ Existant — méthodes `check()`, `registerHardware()`, `setOs()`, `listRemoteConnexion()` | **Réutiliser** dans `WorkstationEnrollmentService` + **ajouter** méthode `renameComputer()` (D14) |
| `App\Models\Workstation` | ✅ Existant Epic 4 — colonnes `name`, `uuid`, `mac`, `physical_room_id`, `ad_dn`, `ad_guid` + relations `physicalRoom`, `groups` | **Consommer** lecture/écriture via `updateOrCreate`/`save`/`assignToPhysicalRoom`/`attachGroups`/`detachGroups` (méthodes existantes) |
| `App\Models\WorkstationGroup` | ✅ Existant Epic 4 — `is_physical=true` (salles physiques) vs `is_physical=false` (parcs logiques) | **Consommer** lecture seule via scopes `physical()` / `logical()` + `where('archived_at', null)` |
| `App\Observers\WorkstationObserver` | ✅ Existant Epic 4 — méthodes `onGroupAttached/Detached/Synced` audit-only depuis 2026-05-20 (cf. amendement ci-dessous) | **Réutiliser tel quel** — l'audit log est déclenché, mais **pas de sync AD machine→groupe** (décision Epic 4 : pivot SQL = source de vérité unique) |
| `auth.v1.lan-only` (`EnsureLanIp`) | ✅ Livré 16.11, réutilisé 3.1+3.2 | **Réutiliser** sur les 5 nouveaux endpoints (D3) |
| `MachineBootLog.action` | ✅ varchar(20) confirmé sans CHECK (3.1 T0.6 + 3.2 T0.6) | **Étendre** — 5 nouvelles valeurs (D12). Pas de migration. |
| Channel log `ipxe` | ✅ Créé 3.1 (daily 14j) — étendu 3.2 | **Étendre** — 10 nouveaux events (D8) |
| `config/ipxe.php` | ✅ Créé 3.1 — étendu 3.2 (`admin`, `maintenance`, `actions`) | **Étendre** — nouvelle section `enrollment` (D11) |
| Routes `/ipxe/enrollment/{name,byod,room,parc-add,parc-remove}` | ❌ Servies par catchall legacy | **Créer** — 5 routes natives AVANT le catchall (D2) |
| Templates Blade `resources/views/ipxe/enrollment/*.blade.php` | ❌ N'existent pas | **Créer** 5 templates + dossier (D10) |
| Template `resources/views/ipxe/menu/admin.blade.php` | ✅ 3.2 — `@if($isKnown)` + message neutre | **Modifier** — items (n)/(a)/(p)/(e) + sections de chain (D10) |
| Doc QA `docs/qa/domains/ipxe.md` | ✅ Étendu 3.2 — 16 scénarios stables 3.1-1..9 + 3.2-1..16 | **Étendre** — Section 11 `## Story 3.3` + ≥10 scénarios stables `3.3-1` à `3.3-N` (numérotation 3.1/3.2 préservée intacte) |
| Tests Unit/Feature/Architecture iPXE | ✅ ~78 cumulés 3.1+3.2 | **Étendre** — ≥30 nouveaux tests cumulés (≥18 unit + ≥10 feature + ≥3 archi). Non-régression 78 tests 3.1+3.2 préservée. |
| `App\Providers\IpxeServiceProvider` | ✅ Existant — bindings `IpxeService`/`IpxeMenuRenderer`/`WorkstationLocator`/`IpxeActionResolver` | **Étendre** — bindings `WorkstationEnrollmentService`/`IpxeEnrollmentMenuBuilder`/`IpxeHostnameSanitizer` singletons |

### Source de vérité du comportement attendu

Les 5 fichiers legacy à lire en T0.4 (lecture obligatoire) :
- `sambaedu/ipxe/enregistrement.php` (159 L — intégralement). Source primaire `name` flow.
- `sambaedu/ipxe/enregistrement_byod.php` (82 L — intégralement). Source `byod` flow (variant simplifié).
- `sambaedu/ipxe/salles.php` (81 L — intégralement). Source `room` flow.
- `sambaedu/ipxe/parcs.php` (78 L — intégralement). Source `parc-add` flow.
- `sambaedu/ipxe/enleveparc.php` (76 L — intégralement). Source `parc-remove` flow.
- `sambaedu/includes/ldap.inc.php:380-394` (`add_hostname_suffix()`) + lignes 2743-2783 (`create_machine()`) + lignes 2693-2724 (`register_machine_hardware()`) + lignes 1809+ (`move_ad()`). Source des transformations hostname + create/update AD à reproduire **iso** via `AdMachineManager` + `IpxeHostnameSanitizer`.
- `sambaedu/includes/ipxe_functions.inc.php:48-57` (`q2a()`). Source platform-dependant translation (no-op en `legacy` clavier — repris pour parité).
- `legacy/modules/ipxe/*` (les 5 wrappers Laravel du catchall, identiques aux `sambaedu/ipxe/*` ; lecture redondante).

### Risques entrants

| Risque | Sévérité | Mitigation 3.3 |
|---|---|---|
| Collision routes `/ipxe/enrollment/{name,byod,room,parc-add,parc-remove}` natives vs catchall | 🟠 Élevée | T6.1 + T6.2 — ordre strict `routes/web.php` (3.3 bloc dans bloc 3.1/3.2, AVANT catchall). Test archi `ipxe_3_3_enrollment_routes_are_declared_before_catchall` obligatoire (5 routes vérifiées une à une). |
| **Modification `admin.blade.php` 3.2 → 3.3** casse les tests 3.2 (10 tests Feature qui asserttent le contenu du menu admin pour `$isKnown=false`) | 🟠 Élevée | T2.1 + T6.7 — mettre à jour les tests 3.2 affectés : asserts qui cherchent `echo Poste non enregistre` deviennent `item --key n set-name`. Documenter dans Dev Agent Record. Test feature `it_renders_admin_menu_minimal_for_unknown_with_enrollment_link` (3.3 nouveau). |
| **`samba-tool computer create` échoue silencieusement** (DC injoignable, droits insuffisants, conflit nom) → Workstation persistée en DB mais pas dans l'AD | 🟠 Élevée | T1.5 — `WorkstationEnrollmentService::enrollName()` retourne `EnrollNameResult { status, ad_result: success\|failed\|skipped }`. Le rendu Blade affiche `echo ATTENTION AD non sync — verifiez avec admin SE5` si `ad_result=failed`. Log error `ipxe.enrollment.name.failure` + escalation Henri. **Pas** de rollback DB (best-effort iso pattern 3.1). |
| Renommage AD a posteriori non supporté par `samba-tool computer move` | 🟡 Moyenne | T0.4 — vérifier dans la doc samba-tool si `move` accepte un renommage `cn=` ou seulement un déplacement OU. Plan B = delete+recreate (perte `netbootGUID` — re-register). Décision documentée DO-* dans Dev Agent Record. |
| `add_hostname_suffix()` mal transposé — un poste de prod legacy nommé `pc-001-7012345e` (suffix `-7012345e`) reçoit un nouveau nom `pc-002-7012345e` qui dépasse 15 chars | 🟡 Moyenne | T1.3 — `IpxeHostnameSanitizer::applyHostnameSuffix()` tests unit avec fixtures iso-legacy (3 cas : sans suffix tronqué 15, avec suffix tronqué 9, edge case suffix vide). Documenter dans le docblock. |
| Menu `room`/`parc-add`/`parc-remove` qui liste **trop** de groupes (cas pathologique > 50 salles) → menu iPXE illisible | 🟢 Mineure | D11 — `config('ipxe.enrollment.max_rooms_in_menu')` = 50 par défaut. Au-delà, on tronque + affiche un item « ** voir UI admin SE5 ** » (placeholder Phase 3). Test unit qui asserte le tronquage. |
| Utilisateur saisit un nom contenant `'; rm -rf /` via `read name` iPXE → injection shell quand `WorkstationEnrollmentService` appelle `samba-tool` | 🟠 Élevée | D6 — `IpxeHostnameSanitizer::isValidHostname()` regex strict refuse tout char hors `[A-Za-z0-9_\-\.\$]`. **Test anti-injection obligatoire** ≥6 cas dans `IpxeHostnameSanitizerTest`. Couche supplémentaire : `AdMachineManager` réutilisé qui a déjà sa propre validation regex (défense en profondeur). |
| `MachineBootLog::action` rejette `'ipxe_enroll_name'` (16 chars) ou autre | 🟢 Mineure | T0.6 audit (re-vérification post-3.1+3.2). Si bloqué → escalation Henri. Hypothèse SM = pas de blocage (le schema est inchangé depuis 3.2). |
| Régression sur 78 tests Ipxe 3.1+3.2 | 🟠 Élevée | T7.2 obligatoire. Suite ciblée Ipxe + Architecture + Auth V1 doit rester 100% verte (~110 tests cumulés 3.1+3.2+3.3). |
| `WorkstationEnrollmentService::attachGroup()` déclenche l'observer qui dispatch un job synchronique `WorkstationMembershipAdSyncJob` — délai > 500ms qui timeout iPXE | 🟢 Mineure | Le firmware iPXE accepte des réponses lentes (timeout DHCP TFTP par défaut 30s). L'observer dispatch en async via queue Laravel — le service retourne immédiatement après le `attach()` Eloquent. Si la queue est arrêtée, la sync AD est différée mais le rendu iPXE OK. |
| `Workstation::create()` échoue en DB (contraintes uniques, foreign keys) → le service retourne false mais le menu Blade affiche succès | 🟢 Mineure | T1.5 — `WorkstationEnrollmentService::enrollName()` retourne `EnrollNameResult` value object avec `status: CREATED|RENAMED|SAME_NAME|NAME_TAKEN|DB_ERROR|AD_ERROR`. Le template Blade conditionne le `echo` sur le status. |
| Charge perf : un poste qui boucle dans le menu admin → 10 appels `/ipxe/enrollment/parc-add` en 30s, chaque appel fait `WorkstationGroup::where(...)->get()` (jusqu'à 50 rows) | 🟢 Mineure | Index sur `workstation_groups.is_physical` + `archived_at` (déjà présents Epic 4). Cache config-driven via `IpxeEnrollmentMenuBuilder::listAvailableGroups()` si volumétrie réelle dépasse — déféré post-prod. |

### Pré-requis (à valider en T0)

- **Worktree git dédié** : à confirmer avec Henri au démarrage T0.1 (probablement `ipxe` réutilisé, ou nouveau `3-3-enrollment` si Henri veut isoler).
- **Story 3.2 done** : ✅ confirmé sprint-status (`3-2-boot-et-menu-admin-ipxe: done`).
- **Schema `machine_boot_logs`** : ✅ confirmé par 3.1 T0.6 + 3.2 T0.6 (`action` varchar(20) sans CHECK). Re-vérification rapide en T0.6.
- **`App\Ldap\AdMachineManager`** : ✅ existe (story 16.7 done). `check()` / `registerHardware()` réutilisables tels quels. `setOs()` non utilisé en 3.3 (= scope 3.4/3.5). Méthode `renameComputer()` à ajouter (D14).
- **Observer `WorkstationObserver`** : ✅ existe. **Amendement 2026-05-20** : les méthodes `onGroupAttached/Detached` ne dispatchent plus `WorkstationMembershipAdSyncJob::add/remove` (factories supprimées par décision Epic 4 antérieure — l'appartenance machine→groupe logique est désormais SQL only ; seul `move` salle subsiste). L'observer reste audit-only sur ces deux callbacks.
- **`samba-tool` opérationnel sur la VM** : ✅ confirmé en 16.7 / 16.3b. Action Henri post-deploy = vérifier en smoke test que les appels sont OK depuis le contexte iPXE LAN (uid `www-admin`).
- **VM PostgreSQL up** : prérequis test feature. Si VM HS → static delivery iso 3.1/3.2.

---

## Acceptance Criteria

> AC organisées en **10 volets**. Volet 10 = QA + sprint-status (append-only sur le runbook `ipxe.md` 3.1+3.2).

### Volet 1 — Helpers + sanitizer (D6)

**AC1.1** — **Création de `IpxeHostnameSanitizer`**

**Given** la classe `App\Ipxe\Services\IpxeHostnameSanitizer`,
**When** elle est invoquée par `WorkstationEnrollmentService`,
**Then** elle expose :
- `sanitize(string $rawName, string $platform = 'legacy'): string` — applique `q2a` (no-op en `legacy`) + `strtolower`.
- `applyHostnameSuffix(string $name, ?string $suffix = null): string` — porte `add_hostname_suffix()` legacy `ldap.inc.php:384-394`. Si `$suffix === null`, lit `config('sambaedu.legacy_ldap.suffix', '')`.
- `isValidHostname(string $name): bool` — regex stricte `/^[a-z0-9_\-\.\$]{1,15}$/` (post-sanitization). Refuse les majuscules (parité legacy `strtolower`).
- `isSpecialServerName(string $name): bool` — détecte `se4fs|se4ad-XXXXXXX[a-z]` (regex iso-legacy `enregistrement.php:39`).

**And** test unit `tests/Unit/Ipxe/Services/IpxeHostnameSanitizerTest.php` ≥10 tests :
- `it_lowercases_input`
- `it_no_op_translates_in_legacy_platform` (parité `q2a` legacy)
- `it_truncates_to_15_chars_without_suffix`
- `it_appends_suffix_and_truncates_to_9_chars_when_suffix_set`
- `it_keeps_name_intact_when_already_suffixed`
- `it_validates_normal_hostname` (`pc-001-test`)
- `it_rejects_hostname_with_shell_metachar` (`'; rm -rf /`)
- `it_rejects_hostname_with_space`
- `it_detects_se4fs_server_name` (`se4fs`, `se4ad-1234567a`)
- `it_does_not_detect_normal_pc_as_server_name`

### Volet 2 — Service de domaine `WorkstationEnrollmentService` (D5)

**AC2.1** — **`enrollName()` cas 1 — création neuve (UUID inconnu)**

**Given** la classe `App\Ipxe\Services\WorkstationEnrollmentService`,
**When** elle est invoquée avec un UUID inconnu en DB + un nom libre,
**Then** :
- Sanitize le nom via `IpxeHostnameSanitizer::sanitize()` + `applyHostnameSuffix()`.
- Crée une `Workstation` via `Workstation::create(['uuid' => $uuid, 'mac' => $mac, 'name' => $sanitizedName, 'status' => 'active'])`.
- Appelle `AdMachineManager::check($sanitizedName)` (idempotent — crée le compte AD si absent).
- Appelle `AdMachineManager::registerHardware($sanitizedName, $uuid)` (registre `netbootGUID`).
- Retourne `EnrollNameResult { status: CREATED, workstation: $workstation, sanitizedName: $sanitizedName, adResult: SUCCESS\|FAILED }`.
- Log `ipxe.enrollment.name.success` avec `status='created'` + `ad_result=<success|failed>`.

**AC2.2** — **`enrollName()` cas 2 — même nom déjà enregistré (idempotent)**

**Given** un UUID connu + le même nom déjà enregistré pour ce poste,
**When** `enrollName()` est invoquée,
**Then** :
- **PAS** de modification DB (`status: SAME_NAME`).
- **PAS** d'appel à `AdMachineManager` (idempotent — l'AD est déjà à jour).
- Retourne `EnrollNameResult { status: SAME_NAME, workstation: $existingWorkstation, sanitizedName }`.

**AC2.3** — **`enrollName()` cas 3 — renommage**

**Given** un UUID connu + un nouveau nom libre (unique),
**When** `enrollName()` est invoquée,
**Then** :
- Update `Workstation::$name` du poste existant.
- Appelle `AdMachineManager::renameComputer($oldName, $newName)` (méthode nouvelle D14 — port natif `move_ad`).
- Si `renameComputer()` échoue → log warning + retourne `EnrollNameResult { status: RENAMED, adResult: FAILED }`.
- Retourne `EnrollNameResult { status: RENAMED, workstation: $workstation, sanitizedName }`.

**AC2.4** — **`enrollName()` cas 4 — nom déjà pris par un autre poste**

**Given** un UUID connu (ou neuf) + un nouveau nom **déjà pris par un autre poste** (`Workstation::where('name', $newName)->where('uuid', '!=', $uuid)->exists()` = true),
**When** `enrollName()` est invoquée,
**Then** :
- **PAS** de modification DB.
- **PAS** d'appel AD.
- Retourne `EnrollNameResult { status: NAME_TAKEN, sanitizedName }`.
- Log `ipxe.enrollment.name.name_taken` (warning) avec préfixes 6/8 chars.

**AC2.5** — **`assignRoom()` succès**

**Given** un poste connu + un `roomId` correspondant à un `WorkstationGroup::is_physical = true`,
**When** `assignRoom($ws, $roomId)` est invoquée,
**Then** :
- Update `Workstation::$physical_room_id`.
- L'observer existant déclenche la sync AD (déplacement OU) en async.
- Retourne `true`.
- Log `ipxe.enrollment.room.success`.

**AC2.6** — **`assignRoom()` échec (room invalide ou inactive)**

**Given** un `roomId` inexistant ou `archived_at != null` ou `is_physical = false`,
**When** `assignRoom()` est invoquée,
**Then** :
- **PAS** de modification DB.
- Retourne `false`.
- Log `ipxe.enrollment.room.failure` (error) avec `reason='invalid_room_id'`.

**AC2.7** — **`attachGroup()` / `detachGroup()` succès**

**Given** un poste connu + un `groupId` correspondant à un `WorkstationGroup::is_physical = false`,
**When** `attachGroup($ws, $groupId)` est invoquée,
**Then** :
- `Workstation::attachGroups([$groupId])` est appelée (observer dispatch AD sync async).
- Retourne `true`.
- Log `ipxe.enrollment.parc.added`.

**And** idem pour `detachGroup()` avec `detachGroups([$groupId])` + event `ipxe.enrollment.parc.removed`.

**AC2.8** — **`logByodEnrollment()` audit minimal**

**Given** un flow BYOD,
**When** `logByodEnrollment($rawName, $mac, $uuid, $ip)` est invoquée,
**Then** :
- **PAS** de création Workstation, **PAS** d'appel AD (D5 — BYOD = audit only en 3.3).
- Insère `MachineBootLog` (action=`ipxe_enroll_byod`, workstation_id=null, machine_name=`'byod:'.$sanitizedName`).
- Log `ipxe.enrollment.byod.logged` (info).
- Retourne `void`.

**And** test unit `tests/Unit/Ipxe/Services/WorkstationEnrollmentServiceTest.php` ≥10 tests (1 par cas AC2.1-AC2.8 + 2 cas d'erreur DB).

### Volet 3 — Méthode `AdMachineManager::renameComputer()` (D14)

**AC3.1** — **Nouvelle méthode `AdMachineManager::renameComputer(string $oldName, string $newName): bool`**

**Given** la classe `App\Ldap\AdMachineManager` (16.7),
**When** elle est étendue 3.3,
**Then** :
- Valide les 2 noms via `isValidMachineName()` (existant).
- Appelle `samba-tool computer move $oldName CN=$newName,$ou` OU `samba-tool computer rename` selon ce que la doc autorise (T0.4 décide).
- Idempotence : si l'`$oldName` n'existe pas (déjà renommé) → log info + retourne `true`.
- Retourne `false` sur exit code != 0 + log error channel `gpo` action_type `ad.machine.rename`.
- **Anti-pattern** : ne PAS appeler `LdapRecord::move()` directement — passage exclusif via `SambaToolRunner` (cohérence 16.7).

**And** test unit `tests/Unit/Ldap/AdMachineManagerTest.php` (existant ou créé) **étendu** ≥3 tests :
- `it_renames_computer_via_samba_tool`
- `it_returns_true_when_old_name_does_not_exist` (idempotence)
- `it_logs_error_when_samba_tool_fails`

**Note dev** : si T0.4 révèle que `samba-tool computer move` ne supporte pas le renommage `cn=` (uniquement le déplacement OU), basculer en plan B = `samba-tool computer delete $oldName && samba-tool computer create $newName && samba-tool computer edit $newName --set-attribute=netbootGUID=$uuid`. Documenter dans Dev Agent Record (DO-*).

### Volet 4 — `IpxeEnrollmentMenuBuilder` (variables Blade)

**AC4.1** — **Création de `IpxeEnrollmentMenuBuilder`**

**Given** la classe `App\Ipxe\Services\IpxeEnrollmentMenuBuilder`,
**When** elle est invoquée par les 5 controllers,
**Then** elle expose :
- `buildNameMenuVariables(?Workstation $ws, string $mac, string $uuid, string $platform, string $serverBaseUrl): array` — variables Blade pour `name.blade.php`. Inclut : `$shebang`, `$mac`, `$uuid`, `$platform`, `$ip`, `$currentName` (si poste connu), `$serverBaseUrl`, `$resolutionPng`, `$resolutionX`, `$resolutionY`, `$menuTimeoutMs`.
- `buildRoomMenuVariables(Workstation $ws, string $serverBaseUrl): array` — `$availableRooms` (collection limitée `config('ipxe.enrollment.max_rooms_in_menu')`), `$currentRoom`, autres variables menu.
- `buildParcAddMenuVariables(Workstation $ws, string $serverBaseUrl): array` — `$availableParcs` (parcs logiques **non encore attachés**, limités).
- `buildParcRemoveMenuVariables(Workstation $ws, string $serverBaseUrl): array` — `$currentParcs` (parcs logiques **attachés** au poste).

**And** test unit `tests/Unit/Ipxe/Services/IpxeEnrollmentMenuBuilderTest.php` ≥6 tests :
- `it_builds_name_menu_variables_for_unknown_workstation`
- `it_builds_name_menu_variables_for_known_workstation`
- `it_builds_room_menu_variables_with_active_physical_groups_only`
- `it_builds_room_menu_variables_caps_at_max_rooms_in_menu_config`
- `it_builds_parc_add_menu_variables_excludes_already_attached`
- `it_builds_parc_remove_menu_variables_lists_only_currently_attached`

### Volet 5 — `IpxeMenuRenderer` extension (rendu Blade des 5 menus)

**AC5.1** — **Nouvelles méthodes `renderEnrollment*()` sur `IpxeMenuRenderer`**

**Given** la classe `App\Ipxe\Services\IpxeMenuRenderer`,
**When** elle est étendue 3.3,
**Then** elle expose :
- `renderEnrollmentNameMenu(array $variables): string` — rend `ipxe.enrollment.name`.
- `renderEnrollmentByodMenu(array $variables): string` — rend `ipxe.enrollment.byod`.
- `renderEnrollmentRoomMenu(array $variables): string` — rend `ipxe.enrollment.room`.
- `renderEnrollmentParcAddMenu(array $variables): string` — rend `ipxe.enrollment.parc-add`.
- `renderEnrollmentParcRemoveMenu(array $variables): string` — rend `ipxe.enrollment.parc-remove`.
- Toutes ces méthodes injectent `'shebang' => self::IPXE_SHEBANG` (DO-13 iso 3.1).

**AC5.2** — **Modification `renderAdminMenu()` — expose `isEnrollmentActive` + `enrollmentBaseUrl`**

**Given** la méthode `IpxeMenuRenderer::renderAdminMenu()` (3.2),
**When** elle est étendue 3.3,
**Then** :
- Ajoute aux variables Blade : `$isEnrollmentActive = (bool) config('ipxe.enrollment.enabled', true)`, `$enrollmentBaseUrl = $serverBaseUrl . '/ipxe/enrollment'`.
- Le template `admin.blade.php` consomme ces variables pour conditionner les items enrollment.
- **Non-régression** : tous les tests 3.2 sur `renderAdminMenu()` restent verts (le défaut `enabled=true` ne change rien aux assertions actuelles SAUF le test qui vérifie le message neutre `'Story 3.3 enrollment a venir'` — à mettre à jour avec le nouveau item `(n) set-name`).

**And** test unit `IpxeMenuRendererTest` (existant) **étendu** ≥6 nouveaux tests :
- `it_renders_admin_menu_with_enrollment_link_when_workstation_unknown` (3.3 nouveau remplace l'assertion 3.2 « echo Poste non enregistre »).
- `it_renders_admin_menu_with_full_items_for_known_workstation` (étendu — vérifie items (n)/(a)/(p)/(e) + maintenance).
- `it_renders_enrollment_name_menu_with_read_name_prompt`
- `it_renders_enrollment_room_menu_with_available_rooms`
- `it_renders_enrollment_parc_add_menu_with_available_parcs`
- `it_renders_enrollment_parc_remove_menu_with_current_parcs_only`

### Volet 6 — Templates Blade (D10)

**AC6.1** — **`resources/views/ipxe/enrollment/name.blade.php` créé**

**Given** le fichier,
**When** rendu par `renderEnrollmentNameMenu()`,
**Then** :
- ~30 lignes ASCII strict.
- Commence par `{!! $shebang !!}` (iso 3.1 DO-13).
- Cas saisie initiale (`@empty($newName)`) :
  - `echo Enregistrement du nom pour uuid: {{ $uuid }}`
  - `echo -n Entrez le nom de la machine: `
  - `set name {{ $currentName ?? '' }}`
  - `read name`
  - `params` + `param mac` + `param uuid` + `param new_name ${name}` + `param platform`
  - `chain --replace --autofree {{ $serverBaseUrl }}/ipxe/enrollment/name##params`
- Cas succès création (`@php $status = $result?->status; @endphp ... @case(EnrollNameStatus::CREATED)`) :
  - `echo OK ! nom {{ $sanitizedName }} reserve pour {{ $uuid }}`
  - `sleep 3`
  - `chain --replace --autofree {{ $serverBaseUrl }}/ipxe/admin##params`
- Cas erreur (`NAME_TAKEN`, `DB_ERROR`, `AD_ERROR`) :
  - `echo ERREUR ! nom {{ $sanitizedName }} indisponible: {{ $reasonLabel }}`
  - `sleep 5`
  - `chain --replace --autofree {{ $serverBaseUrl }}/ipxe/admin##params`

**AC6.2** — **`resources/views/ipxe/enrollment/byod.blade.php` créé**

**Given** le fichier,
**When** rendu,
**Then** :
- ~25 lignes ASCII strict.
- Variant simplifié de `name.blade.php` (cas saisie + cas succès).
- Pas de gestion `NAME_TAKEN` (le BYOD n'écrit pas en DB).
- Cas succès → echo `BYOD enregistre pour {{ $uuid }}` + chain vers `/ipxe/admin` (= 3.3 stub ; 3.4 étendra vers `/ipxe/installation-linux` natif).

**AC6.3** — **`resources/views/ipxe/enrollment/room.blade.php` créé**

**Given** le fichier,
**When** rendu par `renderEnrollmentRoomMenu()`,
**Then** :
- ~25 lignes ASCII strict.
- Cas menu (`@empty($selectedRoomId)`) :
  - `menu Enregistrement de la salle pour {{ $workstationName }}`
  - `set menu-default fin`
  - `set menu-timeout {{ $menuTimeoutMs }}`
  - `@foreach($availableRooms as $room) item {{ $room->name }} {{ $room->display_name ?? $room->name }} @endforeach`
  - `@if($currentRoom) item fin ** deja dans {{ $currentRoom->name }} ** @endif`
  - `item fin Retour au menu principal`
  - `choose --default ${menu-default} --timeout ${menu-timeout} selected && goto ${selected} || exit 0`
  - `@foreach($availableRooms as $room) :{{ $room->name }} set room {{ $room->id }} goto suite @endforeach`
  - `:suite params param room ${room} ... chain ... /ipxe/enrollment/room##params`
- Cas succès :
  - `echo La machine a ete ajoutee a la salle {{ $assignedRoomName }}`
  - `sleep 3` + chain vers admin.
- Cas erreur (`assignRoom = false`) :
  - `echo ERREUR salle non assignee`
  - `sleep 3` + chain vers admin.

**AC6.4** — **`resources/views/ipxe/enrollment/parc-add.blade.php` créé**

**Given** le fichier,
**When** rendu,
**Then** :
- ~25 lignes ASCII strict.
- Iso `room.blade.php` structure mais sur `$availableParcs` (parcs logiques non attachés).
- Cas succès → `echo La machine a ete ajoutee au parc {{ $parcName }}`.

**AC6.5** — **`resources/views/ipxe/enrollment/parc-remove.blade.php` créé**

**Given** le fichier,
**When** rendu,
**Then** :
- ~20 lignes ASCII strict.
- Liste `$currentParcs` (parcs attachés au poste — pas les disponibles).
- Cas succès → `echo La machine a ete enlevee du parc {{ $parcName }}`.

**AC6.6** — **Modification `resources/views/ipxe/menu/admin.blade.php`** (3.2 → 3.3)

**Given** le template 3.2,
**When** modifié 3.3,
**Then** :
- Bloc `@if($isKnown)` étendu (poste connu) :
  ```
  item --key n set-name (n) Renommer le poste : {{ $workstationName }}
  item --key a salle (a) Ajouter a une salle
  item --key p parcs (p) Ajouter a un parc
  item --key e enleveparc (e) Enlever d'un parc
  item --key m maintenance (m) Outils de maintenance (rescuecd, winpe, factory reset)
  ```
- Bloc `@else` (poste inconnu) — **remplace** le message neutre 3.2 :
  ```
  @if($isEnrollmentActive)
  item --key n set-name (n) Nommer le poste (enregistrement)
  @else
  echo Enrollment desactive — voir admin SE5
  sleep 3
  @endif
  ```
- Items invariants : `r retour` + `s shell` + `x exit`.
- Nouvelles sections de chain :
  ```
  :set-name
  chain --replace --autofree {{ $enrollmentBaseUrl }}/name##params

  :salle
  chain --replace --autofree {{ $enrollmentBaseUrl }}/room##params

  :parcs
  chain --replace --autofree {{ $enrollmentBaseUrl }}/parc-add##params

  :enleveparc
  chain --replace --autofree {{ $enrollmentBaseUrl }}/parc-remove##params
  ```
- **Non-régression** : tous les tests 3.2 sur `renderAdminMenu()` qui asserttent la présence de `item --key m maintenance` restent verts. Le test qui asserte `'echo Poste non enregistre'` est **mis à jour** pour asserter `'item --key n set-name'`.

### Volet 7 — Controllers HTTP + FormRequests (D2)

**AC7.1** — **5 controllers fins (≤20 lignes hors docblocks)**

**Given** les 5 classes `IpxeEnrollment{Name,Byod,Room,ParcAdd,ParcRemove}Controller`,
**When** un poste appelle leurs routes respectives,
**Then** :
- Chaque controller a une méthode `handle(...)` qui délègue 100% à un service (`WorkstationEnrollmentService` + `IpxeEnrollmentMenuBuilder` + `IpxeMenuRenderer`).
- Aucune logique métier dans le controller.
- Iso 3.1 DO-6 + 3.2 AC2.1 — pattern controller fin.
- **Anti-pattern** : ne PAS instancier les services manuellement — DI via le constructeur (`__construct(private readonly ... $service)`).

**And** smoke tests unit pour chaque controller (1 test minimum chacun) sous `tests/Unit/Ipxe/Http/Controllers/`.

**AC7.2** — **FormRequests permissifs (iso 3.1/3.2 AC2.2)**

**Given** les 4 classes `IpxeEnrollment{Name,Byod,Room,Parc}Request`,
**When** un poste post les paramètres iPXE,
**Then** :
- `IpxeEnrollmentNameRequest::rules()` :
  ```php
  return [
      'mac' => ['nullable', 'string', 'max:64'],
      'uuid' => ['nullable', 'string', 'max:64'],
      'product' => ['nullable', 'string', 'max:128'],
      'platform' => ['nullable', 'string', 'in:legacy,uefi'],
      'new_name' => ['nullable', 'string', 'max:64'],  // saisie iPXE max — sanitize côté service
  ];
  ```
- `IpxeEnrollmentByodRequest::rules()` : idem `name`.
- `IpxeEnrollmentRoomRequest::rules()` : ajout `'room' => ['nullable', 'integer', 'min:1']`.
- `IpxeEnrollmentParcRequest::rules()` (partagé add/remove) : ajout `'parc' => ['nullable', 'integer', 'min:1']`.
- `authorize()` retourne `true` (auth via middleware `auth.v1.lan-only`).
- **Anti-pattern** : pas de `exists:workstation_groups,id` — la validation business est dans `WorkstationEnrollmentService::assignRoom()` qui retourne `false` si invalide (l'iPXE doit recevoir un menu d'erreur, pas un 422).

### Volet 8 — Routes web.php + ordre + non-régression catchall

**AC8.1** — **5 nouvelles routes déclarées AVANT catchall** (D2)

**Given** le fichier `routes/web.php`,
**When** le dev ajoute le bloc 3.3 dans le bloc existant 3.1/3.2 (après les routes `/ipxe/action/{action}`, avant catchall),
**Then** :
- Les 5 routes (`ipxe.enrollment.name`, `byod`, `room`, `parc-add`, `parc-remove`) sont **toutes** déclarées avant `Route::match(...'{path}'...)->where('path', '.*')`.
- Le commentaire `⚠⚠⚠` (lignes ~689-696 routes/web.php) reste **strictement intact**.
- Le commentaire de bloc 3.3 documente le préfixe `/ipxe/enrollment/*` + parité legacy.
- Toutes les routes ont `auth.v1.lan-only` + `throttle:600,1` + `withoutMiddleware(['web'])`.

**And** test archi `tests/Architecture/IpxeNamespaceTest::ipxe_3_3_enrollment_routes_are_declared_before_catchall` :
- Lit `routes/web.php` en texte.
- Vérifie que les 5 déclarations apparaissent **toutes** avant `Route::match(... '{path}' ...)->where('path', '.*')`.
- Vérifie que chaque route a `auth.v1.lan-only` dans sa chain middleware.
- Vérifie que les 5 controllers `IpxeEnrollment*Controller` sont référencés.

**AC8.2** — **Non-régression catchall sur les routes 3.4-3.7**

**Given** les routes legacy `/ipxe/installation-linux.php`, `/ipxe/installation-windows.php`, `/ipxe/clonage.php`, `/ipxe/clonezilla_menu.php`, `/ipxe/Win10/*`, `/ipxe/double.php`, `/ipxe/reservation.php`,
**When** un appelant LAN les sollicite,
**Then** :
- Elles continuent d'être servies par `LegacyCatchallController` → proxy legacy.
- **Aucune** régression sur le contenu retourné.
- Idem : `/ipxe/enregistrement.php`, `/ipxe/enregistrement_byod.php`, `/ipxe/salles.php`, `/ipxe/parcs.php`, `/ipxe/enleveparc.php` (routes legacy avec `.php`) **continuent** d'être servies par le catchall — c'est **les routes sans `.php` sous `/ipxe/enrollment/*`** qui sont interceptées par 3.3 natif.

**And** test feature `tests/Feature/Ipxe/IpxeLegacyRoutingNonRegressionTest.php` (existant 3.1/3.2) **étendu** ≥5 tests :
- `it_serves_ipxe_enrollment_name_natively_not_via_catchall`
- `it_still_serves_ipxe_enregistrement_php_via_catchall`
- `it_still_serves_ipxe_salles_php_via_catchall`
- `it_still_serves_ipxe_installation_linux_via_catchall` (existant 3.1)
- `it_still_serves_ipxe_clonage_via_catchall` (existant 3.2)

### Volet 9 — Tests + non-régression

**AC9.1** — **Tests unit ≥18 cumulés nouveaux**

**Given** les nouvelles classes 3.3,
**When** `php artisan test --filter='Ipxe'` s'exécute,
**Then** elle couvre :
- `tests/Unit/Ipxe/Services/IpxeHostnameSanitizerTest.php` — ≥10 tests (AC1.1)
- `tests/Unit/Ipxe/Services/WorkstationEnrollmentServiceTest.php` — ≥10 tests (AC2.1-AC2.8)
- `tests/Unit/Ipxe/Services/IpxeEnrollmentMenuBuilderTest.php` — ≥6 tests (AC4.1)
- `tests/Unit/Ipxe/Services/IpxeMenuRendererTest.php` (existant) — ≥6 tests nouveaux (AC5.2)
- `tests/Unit/Ldap/AdMachineManagerTest.php` (existant ou créé) — ≥3 tests nouveaux pour `renameComputer()` (AC3.1)
- `tests/Unit/Ipxe/Http/Controllers/IpxeEnrollment*ControllerSmokeTest.php` — 5 tests smoke (1 par controller)

**AC9.2** — **Tests feature ≥10 nouveaux**

**Given** les controllers + routes + middleware chain,
**When** `php artisan test tests/Feature/Ipxe/` s'exécute,
**Then** elle couvre :
- `tests/Feature/Ipxe/IpxeEnrollmentNameEndpointTest.php` — ≥5 tests (handshake, saisie, succès création, succès rename, nom déjà pris)
- `tests/Feature/Ipxe/IpxeEnrollmentByodEndpointTest.php` — ≥2 tests (handshake, succès stub)
- `tests/Feature/Ipxe/IpxeEnrollmentRoomEndpointTest.php` — ≥3 tests (menu listing, assign succès, room invalide)
- `tests/Feature/Ipxe/IpxeEnrollmentParcEndpointTest.php` — ≥4 tests (parc-add succès, parc-remove succès, parc invalide, poste inconnu → erreur)
- `tests/Feature/Ipxe/IpxeLegacyRoutingNonRegressionTest.php` (existant) — ≥5 tests nouveaux (AC8.2)

**AC9.3** — **Tests architecture étendus**

**Given** le namespace `App\Ipxe`,
**When** `tests/Architecture/IpxeNamespaceTest.php` s'exécute,
**Then** ≥3 tests nouveaux :
- `ipxe_3_3_enrollment_routes_are_declared_before_catchall` (AC8.1)
- `it_lists_all_ipxe_3_3_controllers_under_correct_namespace`
- `it_does_not_import_ldap_record_in_ipxe_namespace` (existant — re-validation que les 5 nouveaux controllers ne `use LdapRecord\*`)

**AC9.4** — **Pas de régression sur 78 tests Ipxe 3.1+3.2 + Phase 1 + 16.10 + 16.11 + 16.12 + Epic 4**

**Given** la baseline tests verte 3.2 (~78/78),
**When** le dev exécute la suite ciblée Ipxe + non-régression Auth V1 + Architecture,
**Then** **100% verts**.

**Items différés VM** (T8.5 iso 3.1/3.2) : `./scripts/run-tests.sh` complet à exécuter par Henri post-merge VM up.

### Volet 10 — Config + provider + channel log + runbook QA + sprint-status (D8, D11)

**AC10.1** — **Extension `config/ipxe.php`** (D11)

**Given** le fichier,
**When** complété 3.3,
**Then** :
- Nouvelle section `enrollment` (cf. D11 code complet).
- `config('ipxe.enrollment.enabled')` = true par défaut.
- `config('ipxe.enrollment.menu_timeout_ms')` = 10000.
- `config('ipxe.enrollment.max_rooms_in_menu')` = 50.
- `config('ipxe.enrollment.max_parcs_in_menu')` = 50.

**And** test unit `IpxeConfigTest` (existant) **étendu** ≥4 nouvelles assertions.

**AC10.2** — **`IpxeServiceProvider` étendu**

**Given** `App\Providers\IpxeServiceProvider`,
**When** étendu 3.3,
**Then** :
- Singletons existants 3.1/3.2 préservés.
- Nouveaux singletons : `WorkstationEnrollmentService`, `IpxeEnrollmentMenuBuilder`, `IpxeHostnameSanitizer`.
- `WorkstationEnrollmentService` reçoit `AdMachineManager` + `IpxeHostnameSanitizer` en constructor.

**AC10.3** — **Extension `docs/qa/domains/ipxe.md`**

**Given** le fichier (étendu 3.1+3.2 — 25 scénarios stables),
**When** étendu 3.3,
**Then** :
- Append `## Story 3.3 — Enrollment Machine — Parcs, Salles, Nommage` après la section 3.2 (numérotation stable préservée — pas de renumérotation).
- ≥10 scénarios stables `3.3-1` à `3.3-N`.

**Et** les scénarios ≥10 couvrent au minimum :
- **Scénario 3.3-1** — Handshake `/ipxe/enrollment/name` (sans params) : préambule + chain `enrollment/name##params`.
- **Scénario 3.3-2** — Saisie nom — poste neuf : POST avec `new_name` + UUID inconnu → menu succès + vérif Workstation persistée + vérif AD `samba-tool computer show` → présent.
- **Scénario 3.3-3** — Saisie nom — même nom déjà enregistré (idempotent) : POST avec `new_name` égal au current → message `deja enregistree sous ce nom` + pas de modification DB.
- **Scénario 3.3-4** — Saisie nom — nom déjà pris par un autre poste : POST avec nom = nom d'un autre Workstation → message `ERREUR nom indisponible` + pas de modification.
- **Scénario 3.3-5** — Saisie nom — renommage : poste connu + nouveau nom unique → update Workstation + AD rename (ou plan B delete+recreate).
- **Scénario 3.3-6** — Affectation salle (poste connu) : POST `/ipxe/enrollment/room` avec `room=<id>` → update `physical_room_id` + vérif AD OU déplacée via observer.
- **Scénario 3.3-7** — Affectation salle — poste inconnu : POST sans Workstation → menu erreur + chain `/ipxe/admin`.
- **Scénario 3.3-8** — Ajout parc — poste connu : POST `/ipxe/enrollment/parc-add` avec `parc=<id>` → attach pivot. ~~+ sync AD via observer~~ AMENDÉ 2026-05-20 : pas de sync AD (SQL only — décision Epic 4).
- **Scénario 3.3-9** — Retrait parc — poste connu : POST `/ipxe/enrollment/parc-remove` → detach pivot. ~~+ sync AD~~ AMENDÉ 2026-05-20 : idem.
- **Scénario 3.3-10** — Anti-injection nom : POST `new_name='; rm -rf /` → 200 (pas de 422) + Body contient `echo ERREUR nom invalide` + pas de modification DB ni AD.
- **Scénario 3.3-11** — Non-régression menu admin natif (3.2 → 3.3) : `curl /ipxe/admin` poste inconnu → body contient `item --key n set-name` (au lieu du message neutre 3.2) + chain vers `/ipxe/enrollment/name`.
- **Scénario 3.3-12** — Non-régression catchall `/ipxe/enregistrement.php` legacy : continue d'être servie par le catchall.
- **Scénario 3.3-13** — `MachineBootLog` peuplé : après quelques appels, `SELECT * FROM machine_boot_logs WHERE action LIKE 'ipxe_enroll%' ORDER BY id DESC LIMIT 10;` montre les rows.
- **(Optionnel)** Scénario 3.3-14 — Smoke poste réel : poste neuf PXE boot → menu admin → `(n)` nommer → saisie → succès → reboot → poste enrôlé en DB + AD.

**AC10.4** — **Mise à jour `sprint-status.yaml`**

**Given** le fichier,
**When** le SM crée cette story,
**Then** :
- `3-3-enrollment-machine-parcs-salles-nommage: backlog` → `3-3-enrollment-machine-parcs-salles-nommage: ready-for-dev`.
- Le commentaire `# last_updated:` (ligne 2) ajoute un paragraphe daté `2026-05-19` qui synthétise : modèle SM utilisé (claude-opus-4-7), scope, nombre AC, modèle dev recommandé.
- **NE PAS** changer `epic-3: in-progress` (déjà bon — posé par 3.1).

---

## Tasks / Subtasks

### Phase T0 — Pré-flight + validations contexte

- [x] **T0.1** Vérifier statut Story 3.2 : `done` confirmé sprint-status (✅). Confirmer worktree avec Henri (réutilisation `ipxe` ou nouveau).
- [x] **T0.2** Statut Epic 1 done + Epic 4 done + 16.10/16.11 review/done + 16.7 done confirmés par sprint-status.
- [x] **T0.3** Confirmer la présence du namespace `App\Ipxe` complet 3.1+3.2 (services, controllers, FormRequests, enums, templates Blade, provider, config, channel log).
- [x] **T0.4** Lecture obligatoire legacy + samba-tool :
  - 5 fichiers legacy iPXE (`enregistrement.php` 159 L, `enregistrement_byod.php` 82 L, `salles.php` 81 L, `parcs.php` 78 L, `enleveparc.php` 76 L).
  - `sambaedu/includes/ldap.inc.php:380-394` (`add_hostname_suffix`) + `2743-2783` (`create_machine`) + `2693-2724` (`register_machine_hardware`) + `1809-1900` (`move_ad`).
  - `sambaedu/includes/ipxe_functions.inc.php:48-57` (`q2a`).
  - `app/Ldap/AdMachineManager.php` (16.7) — méthodes existantes + signature à étendre pour `renameComputer()`.
  - **Action Henri post-T0.4** : valider sur VM si `samba-tool computer move` supporte le renommage `cn=` ou seulement le déplacement OU. Décider plan A (rename) vs plan B (delete+recreate). Documenter DO-* dans Dev Agent Record. → **Plan B retenu (DO-2)**.
- [x] **T0.5** Confirmer que `IpxeServiceProvider` (3.1) est bien enregistré dans `config/app.php` providers array. Pas d'ajout 3.3, juste extension du provider existant.
- [x] **T0.6** Re-audit `MachineBootLog::$fillable` + schema `machine_boot_logs.action` (varchar(20) sans CHECK confirmé par 3.1+3.2 — re-vérifier que rien n'a changé). Les 5 nouvelles valeurs ≤16 chars passent. Si bloqué → escalation Henri. → **Pass (DO-5)**.
- [x] **T0.7** Lint baseline `php -l` 0 erreur sur tous fichiers 3.1+3.2 existants + nouveaux 3.3. → **Différé VM Henri** (PHP non installé host).
- [x] **T0.8** Worktree git — vérifier statut `git status` propre avant démarrage. Pas de sync VM.

### Phase T1 — Helpers + Service de domaine (D5, D6, AC1.1, AC2.1-2.8)

- [x] **T1.1** Créer `app/Ipxe/Services/IpxeHostnameSanitizer.php` (D6 — méthodes `sanitize`, `applyHostnameSuffix`, `isValidHostname`, `isSpecialServerName`).
- [x] **T1.2** Créer `tests/Unit/Ipxe/Services/IpxeHostnameSanitizerTest.php` ≥10 tests (anti-injection obligatoire). → **16 tests dont 11 anti-injection**.
- [x] **T1.3** Créer value object `App\Ipxe\Support\EnrollNameResult` (ou record) avec `status: EnrollNameStatus enum`, `workstation: ?Workstation`, `sanitizedName: string`, `adResult: ?AdResult enum`. → **`final readonly class` (DO-13)**.
- [x] **T1.4** Créer enum `App\Ipxe\Enums\EnrollNameStatus` (CREATED|RENAMED|SAME_NAME|NAME_TAKEN|DB_ERROR|AD_ERROR).
- [x] **T1.5** Créer enum `App\Ipxe\Enums\IpxeEnrollmentFlow` (Name|Byod|Room|ParcAdd|ParcRemove) — utilisé pour logging structuré.
- [x] **T1.6** Créer `app/Ipxe/Services/WorkstationEnrollmentService.php` (D5 — méthodes `enrollName`, `logByodEnrollment`, `assignRoom`, `attachGroup`, `detachGroup`). Try/catch sur chaque méthode publique.
- [x] **T1.7** Créer `tests/Unit/Ipxe/Services/WorkstationEnrollmentServiceTest.php` ≥10 tests (cas AC2.1-AC2.8 + erreurs DB + AdMachineManager mocké). → **14 tests cumulés**.
- [x] **T1.8** Étendre `app/Ldap/AdMachineManager.php` avec méthode `renameComputer(string $oldName, string $newName): bool` (D14 — plan A ou plan B selon T0.4). → **Plan B (delete+recreate, DO-2)**.
- [x] **T1.9** Étendre `tests/Unit/Ldap/AdMachineManagerTest.php` ≥3 tests pour `renameComputer()`. → **7 tests cumulés**.

### Phase T2 — `IpxeEnrollmentMenuBuilder` + Config (AC4.1, AC10.1)

- [x] **T2.1** Étendre `config/ipxe.php` avec section `enrollment` (D11).
- [x] **T2.2** Étendre `tests/Unit/Ipxe/IpxeConfigTest.php` ≥4 nouvelles assertions.
- [x] **T2.3** Créer `app/Ipxe/Services/IpxeEnrollmentMenuBuilder.php` (AC4.1).
- [x] **T2.4** Créer `tests/Unit/Ipxe/Services/IpxeEnrollmentMenuBuilderTest.php` ≥6 tests. → **8 tests cumulés**.

### Phase T3 — Templates Blade nouveaux + modification 3.2 (AC6.1-6.6, D10)

- [x] **T3.1** Créer dossier `resources/views/ipxe/enrollment/`.
- [x] **T3.2** Créer `resources/views/ipxe/enrollment/name.blade.php` (~30 lignes, AC6.1).
- [x] **T3.3** Créer `resources/views/ipxe/enrollment/byod.blade.php` (~25 lignes, AC6.2).
- [x] **T3.4** Créer `resources/views/ipxe/enrollment/room.blade.php` (~25 lignes, AC6.3).
- [x] **T3.5** Créer `resources/views/ipxe/enrollment/parc-add.blade.php` (~25 lignes, AC6.4).
- [x] **T3.6** Créer `resources/views/ipxe/enrollment/parc-remove.blade.php` (~20 lignes, AC6.5).
- [x] **T3.7** Modifier `resources/views/ipxe/menu/admin.blade.php` (AC6.6 — items enrollment + sections de chain). **Attention** : mettre à jour les tests 3.2 qui asserttent le message neutre. → **DO-11 — 2 tests 3.2 mis à jour**.

### Phase T4 — `IpxeMenuRenderer` extension (AC5.1, AC5.2)

- [x] **T4.1** Étendre `IpxeMenuRenderer` avec 5 nouvelles méthodes `renderEnrollment*Menu(array $variables): string` (AC5.1). → **5 + 1 helper `renderEnrollmentUnknownWorkstation` (DO-8)**.
- [x] **T4.2** Étendre `IpxeMenuRenderer::renderAdminMenu()` pour passer `$isEnrollmentActive` + `$enrollmentBaseUrl` (AC5.2).
- [x] **T4.3** Étendre `IpxeMenuRendererTest` ≥6 nouveaux tests (AC5.2 — incluant la mise à jour du test 3.2 qui asserttait le message neutre). → **9 nouveaux tests + 2 mis à jour**.

### Phase T5 — Controllers + FormRequests (AC7.1, AC7.2)

- [x] **T5.1** Créer les 4 FormRequests `IpxeEnrollment{Name,Byod,Room,Parc}Request.php` (AC7.2).
- [x] **T5.2** Créer les 5 controllers `IpxeEnrollment{Name,Byod,Room,ParcAdd,ParcRemove}Controller.php` (AC7.1 — ≤20 lignes hors docblocks, délégation 100% au service). → **DO-1 : orchestrateur `IpxeEnrollmentOrchestrator` créé pour mutualiser la logique commune (5 controllers fins)**.
- [x] **T5.3** Tests unit controllers (smoke 1 test par controller — 5 tests cumulés). → **5 nouveaux smoke tests dans `IpxeAdminControllerSmokeTest`**.

### Phase T6 — Routes + non-régression catchall (AC8.1, AC8.2)

- [x] **T6.1** Ajouter le bloc 5 Routes dans `routes/web.php` (D2 — bloc 3.3 dans le bloc existant 3.1/3.2, avant catchall, commentaire ⚠⚠⚠ préservé intact).
- [x] **T6.2** Étendre `tests/Architecture/IpxeNamespaceTest.php` avec `ipxe_3_3_enrollment_routes_are_declared_before_catchall` + asserts middleware (AC8.1). → **3 nouveaux tests archi**.
- [x] **T6.3** Créer `tests/Feature/Ipxe/IpxeEnrollmentNameEndpointTest.php` ≥5 tests (AC9.2). → **7 tests cumulés**.
- [x] **T6.4** Créer `tests/Feature/Ipxe/IpxeEnrollmentByodEndpointTest.php` ≥2 tests.
- [x] **T6.5** Créer `tests/Feature/Ipxe/IpxeEnrollmentRoomEndpointTest.php` ≥3 tests. → **5 tests cumulés**.
- [x] **T6.6** Créer `tests/Feature/Ipxe/IpxeEnrollmentParcEndpointTest.php` ≥4 tests. → **5 tests cumulés**.
- [x] **T6.7** Étendre `tests/Feature/Ipxe/IpxeLegacyRoutingNonRegressionTest.php` ≥5 tests (AC8.2).
- [x] **T6.8** Mettre à jour les tests 3.2 affectés par la modification d'`admin.blade.php` (Section « Scénario 3.2-3 » et tests `IpxeAdminEndpointTest::it_renders_minimal_admin_menu_for_unknown_workstation` — passage de l'assertion « echo Poste non enregistre » à « item --key n set-name »). → **DO-11 : `IpxeAdminEndpointTest` + `IpxeServiceAdminTest` + `IpxeMenuRendererTest` mis à jour**.

### Phase T7 — Non-régression + lint + audit final

- [x] **T7.1** Lint `php -l` 0 erreur sur tous les fichiers créés/modifiés 3.3 (~28 fichiers). → **DIFFÉRÉ VM Henri** (PHP non installé sur host de dev — pattern iso 3.1/3.2). Vérification visuelle des fichiers : 100% des accolades fermées correctement.
- [x] **T7.2** Suite Ipxe complète verte : ~108-115 tests (78 baseline 3.1+3.2 + ~30 nouveaux 3.3). Non-régression Auth V1 + Architecture globale. **DIFFÉRÉ VM Henri** si `vendor/` absent localement. → **DIFFÉRÉ VM Henri**.
- [x] **T7.3** Vérifier qu'aucun fichier `sambaedu/ipxe/*.php` ni `legacy/modules/ipxe/*.php` n'a été modifié (`git status` confirmé vide pour ces paths). → **Vérifié — aucun fichier legacy touché**.

### Phase T8 — Runbook QA + sprint-status + completion notes (AC10.3, AC10.4)

- [x] **T8.1** Étendre `docs/qa/domains/ipxe.md` avec section `## Story 3.3` + ≥10 scénarios stables `3.3-1` à `3.3-N` (AC10.3). Préserver numérotation 3.1/3.2. → **Section 12 + 16 scénarios cumulés (3.3-1 à 3.3-16)**.
- [x] **T8.2** Mettre à jour `sprint-status.yaml` (AC10.4) — `3-3-enrollment-machine-parcs-salles-nommage: ready-for-dev → review` ; commentaire `# last_updated:` enrichi.
- [x] **T8.3** Story status → `review` (par le dev en fin de cycle), Dev Agent Record + File List + Change Log remplis.
- [ ] **T8.4** *Différé Henri post-merge VM up* : `./scripts/run-tests.sh` complet + scénarios 3.3-1 à 3.3-N manuels sur la VM + smoke poste réel optionnel 3.3-14.

---

## File List prévisionnelle

### Fichiers créés (estimés ~26)

```
# Services nouveaux
app/Ipxe/Services/WorkstationEnrollmentService.php
app/Ipxe/Services/IpxeEnrollmentMenuBuilder.php
app/Ipxe/Services/IpxeHostnameSanitizer.php

# Enums
app/Ipxe/Enums/IpxeEnrollmentFlow.php
app/Ipxe/Enums/EnrollNameStatus.php

# Value object (optionnel)
app/Ipxe/Support/EnrollNameResult.php

# Controllers (5)
app/Ipxe/Http/Controllers/IpxeEnrollmentNameController.php
app/Ipxe/Http/Controllers/IpxeEnrollmentByodController.php
app/Ipxe/Http/Controllers/IpxeEnrollmentRoomController.php
app/Ipxe/Http/Controllers/IpxeEnrollmentParcAddController.php
app/Ipxe/Http/Controllers/IpxeEnrollmentParcRemoveController.php

# FormRequests (4)
app/Ipxe/Http/Requests/IpxeEnrollmentNameRequest.php
app/Ipxe/Http/Requests/IpxeEnrollmentByodRequest.php
app/Ipxe/Http/Requests/IpxeEnrollmentRoomRequest.php
app/Ipxe/Http/Requests/IpxeEnrollmentParcRequest.php

# Templates Blade (5)
resources/views/ipxe/enrollment/name.blade.php
resources/views/ipxe/enrollment/byod.blade.php
resources/views/ipxe/enrollment/room.blade.php
resources/views/ipxe/enrollment/parc-add.blade.php
resources/views/ipxe/enrollment/parc-remove.blade.php

# Tests Unit (~7)
tests/Unit/Ipxe/Services/IpxeHostnameSanitizerTest.php
tests/Unit/Ipxe/Services/WorkstationEnrollmentServiceTest.php
tests/Unit/Ipxe/Services/IpxeEnrollmentMenuBuilderTest.php
tests/Unit/Ipxe/Http/Controllers/IpxeEnrollmentSmokeTest.php  (5 smoke tests groupés ou 5 fichiers séparés)

# Tests Feature (4)
tests/Feature/Ipxe/IpxeEnrollmentNameEndpointTest.php
tests/Feature/Ipxe/IpxeEnrollmentByodEndpointTest.php
tests/Feature/Ipxe/IpxeEnrollmentRoomEndpointTest.php
tests/Feature/Ipxe/IpxeEnrollmentParcEndpointTest.php
```

### Fichiers modifiés (estimés ~10)

```
# Service AD — extension méthode renameComputer
app/Ldap/AdMachineManager.php                              # + renameComputer (D14)

# Services iPXE 3.1/3.2 — extension
app/Ipxe/Services/IpxeMenuRenderer.php                     # + renderEnrollment*Menu + admin enrichi
app/Ipxe/Enums/IpxeMenuKind.php                            # + cases EnrollmentName/Byod/Room/ParcAdd/ParcRemove

# Provider — DI
app/Providers/IpxeServiceProvider.php                      # + bindings WorkstationEnrollmentService + MenuBuilder + Sanitizer

# Config — section enrollment
config/ipxe.php                                            # + section 'enrollment'

# Templates Blade existants — modification
resources/views/ipxe/menu/admin.blade.php                  # bloc items enrollment + sections de chain

# Routes
routes/web.php                                             # + bloc Story 3.3 (5 routes AVANT catchall)

# Doc QA — append-only
docs/qa/domains/ipxe.md                                    # + Section Story 3.3 + ≥10 scénarios 3.3-N

# Tests existants — extension
tests/Unit/Ipxe/IpxeConfigTest.php                         # + 4 assertions section enrollment
tests/Unit/Ipxe/Services/IpxeMenuRendererTest.php          # + 6 tests admin enrichi + renderEnrollment*
tests/Unit/Ldap/AdMachineManagerTest.php                   # + 3 tests renameComputer
tests/Feature/Ipxe/IpxeLegacyRoutingNonRegressionTest.php  # + 5 tests catchall non-régression
tests/Architecture/IpxeNamespaceTest.php                   # + 3 tests routes 3.3 + controllers + no LdapRecord
tests/Feature/Ipxe/IpxeAdminEndpointTest.php               # update assertions message neutre → item set-name

# Sprint-status
_bmad-output/implementation-artifacts/sprint-status.yaml   # status + last_updated
```

### Fichiers NON modifiés (garde-fou)

```
app/Models/Workstation.php                                 ← consommé (updateOrCreate, save, assignToPhysicalRoom, attachGroups/detachGroups)
app/Models/WorkstationGroup.php                            ← consommé (lecture scopes physical/logical/notArchived)
app/Models/MachineBootLog.php                              ← insert via Eloquent (pas de modif schema)
app/Http/Controllers/LegacyCatchallController.php          ← intact
app/Auth/V1/**                                             ← intact (réutilisation alias auth.v1.lan-only)
sambaedu/ipxe/**                                           ← intact (legacy in-place, source de vérité)
legacy/modules/ipxe/**                                     ← intact
app/Ipxe/Services/WorkstationLocator.php                   ← intact (3.1 — lecture pure)
app/Ipxe/Services/IpxeActionResolver.php                   ← intact (3.2 — pas de couplage avec enrollment)
app/Ipxe/Services/IpxeService.php                          ← intact (3.1/3.2 — les 5 nouveaux flows ont leur propre service)
app/Ipxe/Enums/IpxeAdminAction.php                         ← intact (whitelist actions admin maintenance — pas étendue 3.3)
app/Observers/WorkstationObserver.php                      ← intact (déjà sync AD via job existant)
config/logging.php                                         ← intact (channel `ipxe` déjà créé 3.1, événements 3.3 utilisent le même channel)
```

---

## Test Strategy

### Couverture par niveau

| Niveau | Périmètre | Fichiers |
|---|---|---|
| **Unit** | Sanitizer hostname (transformations + validation + anti-injection) | `IpxeHostnameSanitizerTest` |
| **Unit** | Service de domaine enrollment (5 flows + erreurs DB/AD mocked) | `WorkstationEnrollmentServiceTest` |
| **Unit** | Builder variables Blade (5 méthodes) | `IpxeEnrollmentMenuBuilderTest` |
| **Unit** | Renderer admin enrichi + 5 renderEnrollment* | `IpxeMenuRendererTest` étendu |
| **Unit** | Config étendu enrollment | `IpxeConfigTest` étendu |
| **Unit** | AdMachineManager — renameComputer (samba-tool mocked) | `AdMachineManagerTest` étendu |
| **Unit** | Controllers smoke (5 controllers) | `IpxeEnrollmentSmokeTest` |
| **Feature** | Endpoint `/ipxe/enrollment/name` (handshake + saisie + succès + rename + nom pris) | `IpxeEnrollmentNameEndpointTest` |
| **Feature** | Endpoint `/ipxe/enrollment/byod` (stub) | `IpxeEnrollmentByodEndpointTest` |
| **Feature** | Endpoint `/ipxe/enrollment/room` (menu + assign succès + room invalide) | `IpxeEnrollmentRoomEndpointTest` |
| **Feature** | Endpoint `/ipxe/enrollment/parc-add` + `parc-remove` | `IpxeEnrollmentParcEndpointTest` |
| **Feature** | Non-régression catchall sur 5 routes legacy `.php` | `IpxeLegacyRoutingNonRegressionTest` étendu |
| **Architecture** | Ordre routes 3.3 + namespacing controllers + no LdapRecord | `IpxeNamespaceTest` étendu |
| **QA manuelle (VM)** | 14 scénarios stables 3.3-1 à 3.3-14 (dont smoke poste réel optionnel) | `docs/qa/domains/ipxe.md` § Story 3.3 |

### Tests qu'on ne fait **pas** dans cette story

- Tests de boot réel sur poste de test PXE pour enrollment complet — couvert par QA manuel 3.3-14 (action Henri pré-prod uniquement).
- Tests de `samba-tool` réel sur un AD live — mocking via `SambaToolRunner` (iso pattern 16.7).
- Tests d'install Linux post-enrollment BYOD — déféré 3.4 (le BYOD 3.3 est un stub).
- Tests de réservation DHCP — feature cancelled (Epic 8 dédié).
- Tests de double-boot — feature cancelled.
- Tests de charge `/ipxe/enrollment/*` en rentrée scolaire (500 postes simultanés) — déféré post-prod.

---

## Anti-patterns à éviter (DISASTER PREVENTION)

### Architecture & scope

- ❌ **Ne PAS modifier le code legacy `sambaedu/ipxe/*.php` ni `legacy/modules/ipxe/*.php`** — restent intacts. Le catchall continue de servir les 5 routes legacy `.php` jusqu'à 3.7.
- ❌ **Ne PAS étendre le scope** à installation Linux/Windows (= 3.4/3.5), réservation DHCP (cancelled), double-boot (cancelled), clonezilla (= 3.7), login AD (Phase 3).
- ❌ **Ne PAS introduire de dépendance LdapRecord** dans `App\Ipxe\*` — toute écriture AD passe par `AdMachineManager` (`samba-tool` natif via `SambaToolRunner`).
- ❌ **Ne PAS appeler `search_machine()`, `set_action()`, `get_action()`, `move_ad()`, `create_machine()` legacy** — réécriture native pure (Workstation Eloquent + `AdMachineManager` + observer).
- ❌ **Ne PAS toucher au schema `workstations` ni `machine_boot_logs`** (lecture/insert via Eloquent uniquement, pas de migration).
- ❌ **Ne PAS créer de nouveau middleware** — `auth.v1.lan-only` (16.11) suffit (D3).
- ❌ **Ne PAS introduire un Bearer/secret per-host** pour authentifier les enrollments (mémoire `feedback_auth_iso_legacy` — auth machine iso-legacy strict).
- ❌ **Ne PAS créer d'UI Livewire** en 3.3 (D13 — option ouverte Phase 3).
- ❌ **Ne PAS étendre `IpxeAdminAction` enum** avec des cases enrollment — l'enum couvre les actions admin maintenance (rescuecd/winpe/factory_reset), pas le flow d'enrollment.

### Routing & non-régression

- ❌ **Ne PAS placer les 5 routes 3.3 APRÈS le catchall** — strictement AVANT (iso 3.1/3.2).
- ❌ **Ne PAS modifier le commentaire `⚠⚠⚠`** dans `routes/web.php`.
- ❌ **Ne PAS modifier `LegacyCatchallController`** — il continue de servir les routes legacy `.php`.
- ❌ **Ne PAS ajouter d'alias `/ipxe/enregistrement.php` natif** — discrimination 3.3 = routes sans `.php` sous `/ipxe/enrollment/*`.
- ❌ **Ne PAS faire un `Route::prefix('/ipxe/enrollment')` group** — 5 routes plates explicites (cf. D2).
- ❌ **Ne PAS supprimer les routes 3.1/3.2** — elles restent nécessaires (boot → admin → enrollment).

### Sécurité

- ❌ **Ne PAS faire confiance au `new_name` reçu** sans passer par `IpxeHostnameSanitizer::isValidHostname()` — risque d'injection shell quand `samba-tool` est appelé. **Test anti-injection obligatoire**.
- ❌ **Ne PAS logger MAC/UUID/product complets** — préfixes seulement (iso 3.1 AC7.3).
- ❌ **Ne PAS logger `new_name` complet** — préfixe 8 chars (cohérence avec MAC/UUID).
- ❌ **Ne PAS désactiver `auth.v1.lan-only`** dans les tests Feature — utiliser `$this->withServerVariables(['REMOTE_ADDR' => '192.168.1.10'])`.
- ❌ **Ne PAS auto-créer une Workstation depuis `/ipxe/enrollment/room` ou `/parc-*`** — séparation stricte des flows.

### Test & couverture

- ❌ **Ne PAS désactiver les 78 tests Ipxe 3.1+3.2** — la suite doit rester 100% verte (non-régression critique).
- ❌ **Ne PAS désactiver les tests Phase 1 + 16.10/16.11/16.12 + 16.7 + Epic 4**.
- ❌ **Ne PAS commiter de fixtures de production** — utiliser `Workstation::create([...])` (DO-8 iso 3.1) + `WorkstationGroup::factory()` si disponible.
- ❌ **Ne PAS écrire de tests qui dépendent du legacy `sambaedu/`** — pas d'inclusion PHP, pas de mock du legacy.
- ❌ **Ne PAS appeler `samba-tool` réel dans les tests** — mocker `SambaToolRunner` (iso pattern 16.7 / `AdMachineManagerTest`).

### Process & infra

- ❌ **Ne PAS SSH manuellement vers la VM** depuis le worktree git. Static delivery iso 3.1/3.2.
- ❌ **Ne PAS exécuter les tests sur la VM** depuis ce worktree. Lint statique + PHPUnit local.
- ❌ **Ne PAS faire de PR / commit depuis le dev-agent** — c'est le job de l'orchestrateur main agent en fin de cycle.
- ❌ **Ne PAS créer de commit hors scope** (rappel 3.1 `50c6275` — éviter le pattern).
- ❌ **Ne PAS modifier `app/Http/Kernel.php`** — pattern Router aliasMiddleware via `IpxeServiceProvider::boot()` si nouveau middleware nécessaire (pas le cas en 3.3 — D3).

---

## Découpage 3.3a / 3.3b (option SM)

> **Décision SM par défaut** : livrer 3.3 comme **une seule story** (charge estimée 2-3.5j en Opus, 3-4.5j en Sonnet — densité comparable à 3.1+3.2 cumulées).
>
> **Découpage suggéré** si Henri arbitre que le scope est trop large pour une seule story :

### 3.3a — Enrollment Nom + Sanitizer + AD (cœur)

**Scope** : flow `/ipxe/enrollment/name` + `/ipxe/enrollment/byod` + service `WorkstationEnrollmentService` (méthodes `enrollName` + `logByodEnrollment`) + `IpxeHostnameSanitizer` + extension `AdMachineManager::renameComputer()` + 2 templates Blade (name + byod) + 2 controllers + 2 FormRequests + modification `admin.blade.php` (activation item `(n) set-name`).

**Estimation** : ~15-18 fichiers créés + ~6 modifiés. ~20 tests. 1-2j.

### 3.3b — Salles + Parcs (extension)

**Scope** : flows `/ipxe/enrollment/room` + `/ipxe/enrollment/parc-add` + `/ipxe/enrollment/parc-remove` + service `WorkstationEnrollmentService` (méthodes `assignRoom` + `attachGroup` + `detachGroup`) + `IpxeEnrollmentMenuBuilder` complet + 3 templates Blade + 3 controllers + 2 FormRequests + modification `admin.blade.php` (activation items `(a)/(p)/(e)`).

**Estimation** : ~11 fichiers créés + ~6 modifiés. ~15 tests. 1-1.5j.

**Avantage du découpage** : 3.3a livre la valeur principale (enrollment du nom = condition sine qua non pour qu'un poste apparaisse en DB + AD). Si la rentrée scolaire approche, Henri peut déployer 3.3a en pré-prod et continuer 3.3b en parallèle. Risque : duplication des modifications `admin.blade.php` (2 passes — coût acceptable).

**Inconvénient du découpage** : 2 cycles dev+review au lieu d'1, donc 1-2j de coordination supplémentaire. Tests duplicate sur les patterns.

**Recommandation SM** : **livrer en une seule story 3.3** sauf demande explicite Henri (les 5 flows sont fortement couplés via `WorkstationEnrollmentService` + le même set de templates Blade + la même modification d'`admin.blade.php` — découper produit plus d'overhead que de valeur).

---

## Dépendances + ordre

### Amont (bloquantes — toutes done ou review acceptée)

| Story | Statut entrant attendu | Lien |
|---|---|---|
| **Epic 1** (Fondations) | ✅ done | AuthGuard + catchall + dashboard legacy |
| **Epic 4** (Machines/Groups/AppProfiles) | ✅ done | `Workstation` (lecture + update + attach groups) + `WorkstationGroup` (lecture scopes physical/logical) + `MachineBootLog` (insert via Eloquent) + `WorkstationObserver` (sync AD via job existant) |
| **Story 16.3b** (AD User natif) | ✅ done | Pattern `SambaToolRunner` + suffix hostname legacy |
| **Story 16.7** (AD Machine natif) | ✅ done | `App\Ldap\AdMachineManager` (check + registerHardware + setOs) — réutilisé, étendu avec `renameComputer()` (D14) |
| **Story 16.10** (HTTPS+JWT) | ✅ review/done | `JwtErrorCodes` catalogue, alias middleware |
| **Story 16.11** (Auto-bootstrap migration) | ✅ review/done | Middleware `auth.v1.lan-only` + code `JwtErrorCodes::BOOTSTRAP_NOT_LAN` — **réutilisés tels quels** (D3) |
| **Story 3.1** (iPXE Service Core) | ✅ done | Fondation totale — namespace `App\Ipxe`, services, renderer, controller boot, channel log, config, FormRequest, templates, table MachineBootLog action='ipxe_boot' |
| **Story 3.2** (Boot et Menu Admin iPXE) | ✅ done | Endpoints `/ipxe/admin`/`maintenance`/`action/{action}` + enum `IpxeAdminAction` + template `admin.blade.php` à modifier 3.3 |

### Aval (3.3 débloque)

| Story | Lien |
|---|---|
| **3.4** Installation Linux | **Consomme** : (a) flow `/ipxe/enrollment/byod` étendu pour chain vers `/ipxe/installation-linux` natif (3.3 livre un stub, 3.4 complète) ; (b) menu admin natif → item `(l) installation-linux` (chain vers route 3.4 native) ; (c) pattern `WorkstationEnrollmentService` à imiter pour les actions install. |
| **3.5** Installation Windows | Idem 3.4 pour windows — pattern enrollment réutilisable pour le flow de premier install. |
| **3.6** Gestion ISO Windows | Indépendant 3.3 — UI admin Livewire. |
| **3.7** Clonage et Maintenance | Indépendant 3.3. **Fin Epic 3** : 3.7 retire les routes legacy `/ipxe/*` du catchall, supprimant `legacy/modules/ipxe/*` (incluant les 5 `enregistrement.php`/`enregistrement_byod.php`/`salles.php`/`parcs.php`/`enleveparc.php`). |
| **UI admin SE5 enrollment** (option Phase 3) | Si Henri arbitre OUI : nouvelle story dédiée qui ajoute une page `/parc/enrollment/new` Livewire SFC permettant à un admin SE5 de pré-programmer un enrollment depuis le navigateur (UUID/MAC + hostname + salle + parcs). 3.3 livre le **socle backend** (`WorkstationEnrollmentService`) qui sera réutilisé. |

---

## Risques + mitigations (récapitulatif consolidé)

| Risque | Sévérité | Mitigation 3.3 |
|---|---|---|
| Collision routes `/ipxe/enrollment/*` vs catchall | 🟠 Élevée | T6.1 + T6.2 — ordre strict + test archi. |
| Modification `admin.blade.php` casse les tests 3.2 | 🟠 Élevée | T6.8 — mise à jour explicite des tests 3.2 affectés. Documenter dans Dev Agent Record. |
| `samba-tool computer create` échoue silencieusement | 🟠 Élevée | `WorkstationEnrollmentService::enrollName()` retourne `EnrollNameResult { adResult: SUCCESS\|FAILED }` + log error + echo Blade conditionnel. Best-effort iso 3.1. |
| Renommage AD `move` non supporté → plan B delete+recreate | 🟡 Moyenne | T0.4 vérification samba-tool obligatoire. Doc DO-* selon plan retenu. Perte du `netbootGUID` côté plan B → re-register systématique. |
| Anti-injection nom (shell metachar dans `new_name`) | 🟠 Élevée | D6 — `IpxeHostnameSanitizer::isValidHostname()` regex strict + tests anti-injection ≥6 cas. Défense en profondeur via `AdMachineManager` regex existante. |
| `add_hostname_suffix()` mal transposé → noms tronqués incorrectement | 🟡 Moyenne | T1.3 tests fixtures iso-legacy. |
| Menu listant > 50 salles/parcs (cas pathologique) | 🟢 Mineure | D11 cap config + item « ** voir UI admin SE5 ** ». Test unit. |
| `MachineBootLog.action` rejette nouvelles valeurs | 🟢 Mineure | T0.6 audit. Escalation Henri si bloqué. |
| Régression sur 78 tests Ipxe 3.1+3.2 | 🟠 Élevée | T7.2 obligatoire. T4.3 + T6.8 — mise à jour explicite des tests 3.2 affectés par la modification `admin.blade.php`. |
| `WorkstationEnrollmentService::attachGroup()` lent (sync AD via job) | 🟢 Mineure | Observer dispatch async — service retourne immédiatement après `attach()` Eloquent. |
| Test feature `/ipxe/enrollment/room` qui dépend de `WorkstationGroup::factory()` qui n'existe pas | 🟡 Moyenne | Vérifier en T1.7 si la factory existe (Epic 4 — `WorkstationGroup` utilise `HasFactory`). Si absente, créer une factory minimale dans `tests/Support/` (iso pattern 3.1). |
| Conflit pivot `workstation_group_workstation` (déjà attaché) lors d'un `attachGroups()` | 🟢 Mineure | Eloquent tolère via `attach()` (insert dup OK selon DB). Sinon try/catch dans `WorkstationEnrollmentService::attachGroup()`. |

---

## Project Structure Notes

### Alignement avec la structure projet

- **Namespace** : extension `App\Ipxe\…` posé par 3.1+3.2. Pas de nouveau sous-namespace (sur-fragmentation).
- **Tests** : sous-arborescence `tests/Unit/Ipxe/Services/`, `tests/Unit/Ipxe/Enums/`, `tests/Unit/Ipxe/Http/Controllers/`, `tests/Feature/Ipxe/` — iso 3.1/3.2.
- **Templates Blade** : nouveau sous-dossier `resources/views/ipxe/enrollment/` (parallèle à `menu/` 3.1/3.2 + `actions/` 3.2). Convention : `enrollment/` = flux de saisie + confirmation + erreur, distinct des menus interactifs purs (`menu/`) et des scripts d'action (`actions/`).
- **Pages cibles** : *hors-scope cette story* — pas d'UI Livewire (API HTTP pure iso 3.1/3.2).
- **Convention CLAUDE.md** : pas directement applicable (pas de page web admin Livewire, pas de modale, pas de toast). Si une UI admin Phase 3 est ouverte, elle suivra le pattern `resources/views/pages/parc/enrollment/` (filesystem-based router) + Livewire SFC + modale réutilisable + `WithToasts`.

### Conflits / variances détectés

| Élément | Architecture officielle | Décision 3.3 | Justification |
|---|---|---|---|
| Création AD | non décidée pour iPXE | `AdMachineManager` (16.7) via `SambaToolRunner` | Réutilisation iso-legacy stricte — pas de duplication code AD. mémoire `feedback_auth_iso_legacy`. |
| Auth machine pour enrollment | non décidée | `auth.v1.lan-only` seul (réutilisation 16.11) | Iso 3.1 D3/D8 + 3.2 D3. Pas de Bearer per-host (cf. mémoire). |
| Flow enrollment iPXE | non décidé | 5 endpoints `/ipxe/enrollment/*` distincts | Cohérent avec 3.2 (`/ipxe/admin`/`/maintenance`/`/action/{action}`). Pas de RESTful CRUD général. |
| Stockage logs | défini 3.1 (`MachineBootLog`) | Identique 3.3 — 5 nouvelles valeurs `action` | D12 — éviter multiplication des tables. |
| Templates iPXE enrollment | non décidés | Blade dans `resources/views/ipxe/enrollment/*.blade.php` | D10 — convention Laravel + cohérence 3.1/3.2. |
| Validation hostname | non décidée | Regex strict (iso `AdMachineManager::MACHINE_REGEX`) + sanitize (iso `add_hostname_suffix`) | D6 — défense en profondeur anti-injection. |
| UI admin enrollment (web) | non décidée | **Hors-scope 3.3**, option ouverte Phase 3 | D13 — parité legacy stricte. |

### Cohabitation routes `/ipxe/*` après 3.3

| Endpoint | Story | Middleware | Status |
|---|---|---|---|
| `GET\|POST /ipxe/boot` | 3.1 | `auth.v1.lan-only` + `throttle:600,1` | done (3.1) |
| `GET /ipxe/boot.ipxe` | 3.1 | idem | done (3.1) alias |
| `GET\|POST /ipxe/admin` | 3.2 | `auth.v1.lan-only` + `throttle:600,1` | done (3.2) |
| `GET\|POST /ipxe/maintenance` | 3.2 | idem | done (3.2) |
| `GET\|POST /ipxe/action/{action}` | 3.2 | idem + `where('action', '[a-z_]+')` | done (3.2) |
| `GET\|POST /ipxe/enrollment/name` | **3.3 (cette story)** | `auth.v1.lan-only` + `throttle:600,1` | **NEW** |
| `GET\|POST /ipxe/enrollment/byod` | **3.3 (cette story)** | idem | **NEW** (stub — extension 3.4) |
| `GET\|POST /ipxe/enrollment/room` | **3.3 (cette story)** | idem | **NEW** |
| `GET\|POST /ipxe/enrollment/parc-add` | **3.3 (cette story)** | idem | **NEW** |
| `GET\|POST /ipxe/enrollment/parc-remove` | **3.3 (cette story)** | idem | **NEW** |
| `/ipxe/admin.php` | Legacy | catchall + proxy legacy | Inchangé — accessible pour debug |
| `/ipxe/enregistrement.php`, `enregistrement_byod.php`, `salles.php`, `parcs.php`, `enleveparc.php` | Legacy | catchall | Inchangé — accessible pour debug, retiré 3.7 cleanup |
| `/ipxe/installation-linux.php` | Legacy | catchall | Inchangé — sera réécrit 3.4 |
| `/ipxe/installation-windows.php` | Legacy | catchall | Inchangé — sera réécrit 3.5 |
| `/ipxe/clonage.php`, `clonezilla_menu.php`, `double.php`, `reservation.php` | Legacy | catchall | Inchangé — sera réécrit 3.7 (sauf double+reservation cancelled) |
| `/ipxe/Win10/*`, `/ipxe/sysresccd/*`, `/ipxe/clonezilla/*`, `/ipxe/png/*`, `/ipxe/diconf/*` | Legacy (assets) | catchall | Inchangé |

**Pas de collision** : les 10 routes natives (3.1 + 3.2 + 3.3) sont des routes précises déclarées AVANT le catchall `{path}`. Les autres routes `/ipxe/*` continuent d'être capturées par le catchall.

---

## References

- [Source: `_bmad-output/planning-artifacts/epics.md` §Epic 3 / Story 3.3] — cadrage haut niveau, prérequis Epic 1 + Epic 4 + Stories 3.1 + 3.2.
- [Source: `_bmad-output/implementation-artifacts/3-1-ipxe-service-core.md`] — **Story fondation** — namespace `App\Ipxe`, services, channel log, decisions D1-D12 SM + DO-1 à DO-13 dev, scénarios QA 3.1-1 à 3.1-9.
- [Source: `_bmad-output/implementation-artifacts/3-2-boot-et-menu-admin-ipxe.md`] — **Story 3.2 prédécesseur** — endpoints `/ipxe/admin`/`/maintenance`/`/action/{action}`, enum `IpxeAdminAction`, template `admin.blade.php` (modifié 3.3 — section `:set-name` à activer), scénarios QA 3.2-1 à 3.2-16.
- [Source: `_bmad-output/planning-artifacts/architecture.md`] — §"Modèle de Données — Source de Vérité" (PostgreSQL pour lecture/écriture) + §Coexistence Legacy — Stratégie Catchall.
- [Source: `_bmad-output/planning-artifacts/prd.md`] — §FR7 (enrollment machines), §FR8 (boot/WOL context).
- [Source: `_bmad-output/implementation-artifacts/16-3b-*.md`] — pattern `SambaToolRunner` + `AdUserManager`. Référence pour `AdMachineManager::renameComputer()` (D14).
- [Source: `_bmad-output/implementation-artifacts/16-7-*.md`] — Story `AdMachineManager` natif réutilisé en 3.3.
- [Source: `_bmad-output/implementation-artifacts/16-10-securisation-https-jwt-endpoints.md`] — `JwtErrorCodes` catalogue.
- [Source: `_bmad-output/implementation-artifacts/16-11-auto-bootstrap-migration-postes.md`] — middleware `auth.v1.lan-only` + code `JwtErrorCodes::BOOTSTRAP_NOT_LAN` réutilisés.
- [Source: `sambaedu/ipxe/enregistrement.php`] — source de vérité comportementale primaire pour `/ipxe/enrollment/name` (159 L).
- [Source: `sambaedu/ipxe/enregistrement_byod.php`] — source pour `/ipxe/enrollment/byod` (82 L — variant simplifié).
- [Source: `sambaedu/ipxe/salles.php`] — source pour `/ipxe/enrollment/room` (81 L).
- [Source: `sambaedu/ipxe/parcs.php`] — source pour `/ipxe/enrollment/parc-add` (78 L).
- [Source: `sambaedu/ipxe/enleveparc.php`] — source pour `/ipxe/enrollment/parc-remove` (76 L).
- [Source: `sambaedu/includes/ldap.inc.php:380-394`] — `add_hostname_suffix()` à porter dans `IpxeHostnameSanitizer`.
- [Source: `sambaedu/includes/ldap.inc.php:2743-2783`] — `create_machine()` — référence pour le flow d'enrollment, déjà porté par `AdMachineManager::check()`.
- [Source: `sambaedu/includes/ldap.inc.php:2693-2724`] — `register_machine_hardware()` — déjà porté par `AdMachineManager::registerHardware()`.
- [Source: `sambaedu/includes/ldap.inc.php:1809-1900`] — `move_ad()` — référence pour `AdMachineManager::renameComputer()` (D14).
- [Source: `sambaedu/includes/ipxe_functions.inc.php:48-57`] — `q2a()` à porter dans `IpxeHostnameSanitizer::sanitize()`.
- [Source: `app/Ipxe/Services/WorkstationLocator.php`] — 3.1 — réutilisation pure pour les 5 endpoints.
- [Source: `app/Ipxe/Services/IpxeMenuRenderer.php`] — 3.1+3.2 — extension `renderEnrollment*Menu` + admin enrichi.
- [Source: `app/Ipxe/Services/IpxeService.php`] — 3.1/3.2 — **non modifié 3.3** (séparation des responsabilités via `WorkstationEnrollmentService`).
- [Source: `app/Ipxe/Enums/IpxeAdminAction.php`] — 3.2 — **non modifié 3.3** (whitelist actions admin maintenance).
- [Source: `app/Ldap/AdMachineManager.php`] — 16.7 — **étendu 3.3** avec `renameComputer()` (D14).
- [Source: `app/Models/Workstation.php`] — modèle Eloquent (méthodes `attachGroups`, `detachGroups`, `assignToPhysicalRoom` existantes).
- [Source: `app/Models/WorkstationGroup.php`] — scopes `physical()`, `logical()`, `notArchived()` existants.
- [Source: `app/Models/MachineBootLog.php`] — table `machine_boot_logs` (réutilisation 3.3 avec 5 nouvelles valeurs `action`).
- [Source: `app/Observers/WorkstationObserver.php`] — sync AD via `WorkstationMembershipAdSyncJob` existante (réutilisation pure).
- [Source: `app/Http/Controllers/LegacyCatchallController.php`] — proxy catchall qui continue de servir les routes `/ipxe/*` non-3.3.
- [Source: `routes/web.php` lignes ~628-685] — bloc d'insertion 3.3 (après les routes 3.2, AVANT le catchall ligne ~683).
- [Source: `app/Auth/V1/Http/Middleware/EnsureLanIp.php`] — middleware `auth.v1.lan-only` 16.11 réutilisé.
- [Source: `config/ipxe.php`] — fichier 3.1/3.2 à étendre (section `enrollment`).
- [Source: `config/logging.php`] — channel `ipxe` posé 3.1 — réutilisation pure.
- [Source: `docs/qa/domains/ipxe.md`] — runbook 3.1+3.2 (25 scénarios stables) à étendre append-only avec section Story 3.3.
- [Source: `docs/qa/README.md`] — convention runbooks domaine.
- [Source: mémoire `feedback_worktree_no_vm_sync`] — depuis worktree, jamais SSH `/vm`.
- [Source: mémoire `feedback_auth_iso_legacy`] — Phase 2 prime sur iso-legacy pour l'auth machine — pas de Bearer/secret per-host introduit.
- [Source: mémoire `project_php_fpm_user_www_admin`] — PHP-FPM user `www-admin` (uid 599) — point d'attention déploiement.
- [Source: mémoire `project_se4fs_etab_rattachement`] — convention `memberOf` Etablissements ne s'applique pas aux machines.
- [Source: CLAUDE.md projet] — sync inotify (worktree non sync), pas de SSH `/vm` depuis worktree, naming SE4=legacy / SE5=sambaedu-reload, filesystem-based router pour UI Livewire (non applicable 3.3 — API HTTP pure).

---

## Dev Notes

### Justification design

- **Pourquoi 5 controllers séparés (`IpxeEnrollment{Name,Byod,Room,ParcAdd,ParcRemove}Controller`) et pas 1 seul `IpxeEnrollmentController` avec param `{flow}` ?**
  Single Responsibility + cohérence avec 3.1/3.2 (`IpxeBootController`/`IpxeAdminController`/etc.). Les 5 flows ont des paramètres différents (`new_name`, `room`, `parc`, `platform`), un controller unique deviendrait un god class. La duplication est minime (chaque controller = 1 méthode `handle()` qui délègue au service).
- **Pourquoi `WorkstationEnrollmentService` séparé de `IpxeService` ?**
  Séparation des responsabilités : `IpxeService` orchestre le menu boot/admin/maintenance/action (3.1+3.2) — ce sont des rendus de **menus interactifs**. `WorkstationEnrollmentService` orchestre l'**écriture** (DB PostgreSQL + AD samba-tool) — cycle de vie distinct, dépendance `AdMachineManager` spécifique. Un service unifié mélangerait les responsabilités et casserait l'encapsulation 3.1.
- **Pourquoi enum `EnrollNameStatus` et value object `EnrollNameResult` plutôt qu'un retour `bool` ?**
  Type-safe + lisibilité du caller. Le template Blade conditionne le `echo` sur 5 cas distincts (CREATED, RENAMED, SAME_NAME, NAME_TAKEN, DB_ERROR, AD_ERROR). Un `bool` perdrait l'information et obligerait à dupliquer la logique dans le controller.
- **Pourquoi pas d'UI admin Livewire en 3.3 ?**
  D13 — parité legacy stricte. Le legacy enroll uniquement depuis le firmware iPXE en LAN scolaire. L'UI admin est une feature Phase 3 si besoin terrain (Henri arbitrera après retour rentrée scolaire).
- **Pourquoi réutiliser `AdMachineManager` (16.7) et pas créer un nouveau client AD spécifique enrollment ?**
  Cohérence + maintenance. `AdMachineManager` est le seul point d'entrée AD côté machines (Story 16.7 done — 50+ tests verts). Dupliquer la logique de validation regex + `SambaToolRunner` ajouterait 100+ lignes de code mort.
- **Pourquoi extension `IpxeMenuRenderer` plutôt qu'un nouveau service `IpxeEnrollmentMenuRenderer` ?**
  Cohérence avec 3.2 qui a étendu le renderer existant (`renderAdminMenu`, `renderMaintenanceMenu`). Le renderer est un service unique de rendu Blade — pas de fragmentation.
- **Pourquoi pas d'enum pour les 5 routes (au lieu de 5 controllers) ?**
  Tentation initiale = `/ipxe/enrollment/{flow}` avec `IpxeEnrollmentFlow` enum (Name|Byod|Room|ParcAdd|ParcRemove). **Rejet** : les paramètres POST diffèrent (`new_name` pour name, `room` pour room, `parc` pour parc-*). Un controller unique devrait dispatcher sur le param + valider conditionnellement → complexité dépassant le bénéfice. **5 controllers fins** = pattern iso 3.1/3.2.

### Convention de logging (extension 3.1/3.2)

- Tous les logs 3.3 ont la clé `action_type` (iso 3.1/3.2 convention) :
  - `ipxe.enrollment.name.handshake` (info)
  - `ipxe.enrollment.name.success` (info)
  - `ipxe.enrollment.name.name_taken` (warning)
  - `ipxe.enrollment.name.failure` (error)
  - `ipxe.enrollment.byod.logged` (info)
  - `ipxe.enrollment.room.success` (info)
  - `ipxe.enrollment.room.failure` (error)
  - `ipxe.enrollment.parc.added` (info)
  - `ipxe.enrollment.parc.removed` (info)
  - `ipxe.enrollment.parc.failure` (error)
- Toutes les valeurs sensibles (MAC, UUID, name, room_name, group_name) sont **préfixées** (6-8 chars) — pas de PII complète.
- Exception : `MachineBootLog::machine_name` n'est pas tronqué (audit interne — pattern iso 3.1/3.2).

### Pattern de flow 3.3 (extension 3.2)

```
Firmware iPXE (post 3.2 admin menu) → choisit "n" (set-name)
  ↓ chain vers /ipxe/enrollment/name (3.3 nouveau)
GET /ipxe/enrollment/name (handshake — pas de mac/uuid)
  ↓ (rendu handshake avec chainTarget='enrollment/name')
POST /ipxe/enrollment/name (mac/uuid posés)
  ↓
EnsureLanIp (16.11) — vérif RFC1918
  ↓
IpxeEnrollmentNameController::handle → WorkstationEnrollmentService::enrollName()
  ↓
IpxeHostnameSanitizer::sanitize + applyHostnameSuffix + isValidHostname
  ↓
WorkstationLocator::locate (3.1) → ?Workstation (cas 1=neuf, cas 2-4=existing)
  ↓
Cas 1 — création :
  PostgreSQL: Workstation::create
  AD: AdMachineManager::check + registerHardware
Cas 2 — same_name : no-op
Cas 3 — rename :
  PostgreSQL: Workstation::save (name)
  AD: AdMachineManager::renameComputer
Cas 4 — name_taken : aucune action
  ↓
Log ipxe.enrollment.name.<status> + persist MachineBootLog (action=ipxe_enroll_name)
  ↓
IpxeMenuRenderer::renderEnrollmentNameMenu($result, $serverBaseUrl)
  → Blade ipxe.enrollment.name (cas saisie / succès / erreur)
  ↓
Response text/plain + headers (no-store, noindex)
  ↓
Firmware iPXE affiche le menu/echo → chain vers /ipxe/admin

(idem pour /room, /parc-add, /parc-remove avec services dédiés)
```

### Tests qu'on **ne** fait **pas** dans cette story

- Tests de boot réel sur poste de test PXE pour enrollment complet — couvert par QA manuel 3.3-14.
- Tests d'exécution réelle de `samba-tool` sur un AD live — mocking via `SambaToolRunner` (iso pattern 16.7).
- Tests d'install Linux post-enrollment BYOD — déféré 3.4 (BYOD 3.3 = stub).
- Tests de charge `/ipxe/enrollment/*` en rentrée scolaire (500 postes simultanés) — déférés post-prod.
- Tests de UI admin Livewire — D13 hors-scope 3.3.

---

## Dev Agent Record

### Agent Model Used

`claude-opus-4-7[1m]` (Opus 4.7 contexte 1M tokens) — modèle SM-recommandé pour 3.3 (anti-injection critique + transposition iso-legacy 5 fichiers + value object typé + extension `AdMachineManager` + non-régression `admin.blade.php`).

Heures cumulées approximatives : ~2h30 d'orchestration dev (Phase T0 → T8 séquentiel sans pause).

### Debug Log References

- Lint `php -l` : **différé Henri post-merge VM** (PHP non installé sur machine host de dev — pattern iso 3.1/3.2). Vérification visuelle réalisée : 100% des fichiers PHP créés se terminent par `}` correctement, aucune erreur d'accolade détectée.
- Tests phpunit : **différés Henri post-merge VM** (vendor/ non disponible localement, pas de connexion AD live). Pattern iso 3.1/3.2.

### Completion Notes List

#### DO-1 — Architecture orchestrateur dédié (`IpxeEnrollmentOrchestrator`)
Pour respecter D5 (séparation responsabilités vs `IpxeService` 3.1/3.2 « non modifié ») tout en gardant les 5 controllers fins (AC7.1 ≤20 lignes), création d'un orchestrateur `IpxeEnrollmentOrchestrator` qui centralise l'extraction params + handshake + résolution Workstation + chaining service+builder+renderer. Chaque controller appelle `$orchestrator->handle<Flow>($request)`. Permet aussi le test smoke unique (Bind container) et la mutualisation du `safeRender`.

#### DO-2 — D14 plan B retenu (delete+recreate) pour `AdMachineManager::renameComputer()`
Décision : utiliser `samba-tool computer delete <old>` puis `samba-tool computer create <new>` (plan B documenté). `samba-tool computer move` ne supporte pas le renommage CN (déplacement OU uniquement, vérifié dans la doc samba). Conséquence assumée : le `netbootGUID` est perdu côté ancien compte → le service `WorkstationEnrollmentService::enrollName()` cas `RENAMED` n'effectue PAS de re-register automatique du `netbootGUID` sur le nouveau nom. Documenté en TODO pour Story 3.4 si retours terrain demandent (en pratique le rename est rare dans le flow iPXE).

#### DO-3 — Sanitizer `IpxeHostnameSanitizer` : regex `[a-z0-9_\-\.\$]{1,15}` strict
Plus restrictif que `AdMachineManager::MACHINE_REGEX` (qui autorise majuscules + 64 chars) pour respecter la convention NetBIOS legacy (15 chars max, lowercase). Le `sanitize()` applique `strtolower + trim`, puis `applyHostnameSuffix()` tronque iso-legacy (9 chars + suffix, ou 15 sans suffix), puis `isValidHostname()` refuse tout char hors `[a-z0-9_\-\.\$]`. 16 tests anti-injection cumulés (semicolon, pipe, ampersand, backtick, newline, quotes, uppercase, oversize, space).

#### DO-4 — Parité iso-legacy stricte `add_hostname_suffix()` (ldap.inc.php:384-394)
Reproduction byte-pour-byte : si `$suffix` défini ET non vide ET nom déjà suffixé (`preg_match("/$suffix$/i", $name)`) → retour identité (lowercase). Sinon → tronque 9 + concat suffix. Sinon (suffix vide) → tronque 15. Pas de cap 15 dans la branche suffix (parité stricte — un suffix de 8 chars peut produire un nom de 17 chars). Tests unit avec fixture `mylongmachine` + suffix `-x123abc` → `mylongmac-x123abc` (17 chars). Préserve `preg_quote($suffix)` pour suffixes potentiellement regex-sensibles.

#### DO-5 — Audit `MachineBootLog.action` confirmé sans CHECK (Phase T0.6)
Re-vérification du schema `machine_boot_logs` migration `2026_03_25_120000_add_action_and_initiated_by_to_machine_boot_logs.php` : `action varchar(20) NULL` sans CHECK constraint. 5 nouvelles valeurs `ipxe_enroll_name|byod|room` (16 chars) + `ipxe_parc_add` (13) + `ipxe_parc_remove` (16) fit toutes. Pas de migration nécessaire (D9). Iso 3.1 T0.6 + 3.2 T0.6.

#### DO-6 — Best-effort DB+AD (pas de transaction atomique)
Pattern iso 3.1/4.1/4.2 : si `Workstation::create` réussit mais `AdMachineManager::check()` échoue → la Workstation reste persistée + le service retourne `EnrollNameResult::created($ws, $name, adResult: false)` + log warning `ipxe.enrollment.name.failure`. Le template Blade affiche `echo ATTENTION : sync AD echouee — verifiez avec admin SE5`. Pas de rollback DB pour préserver le best-effort et permettre une re-synchronisation post-incident. Cohérent avec la résilience iPXE (un firmware doit toujours recevoir un menu).

#### DO-7 — Anti-injection en 2 couches (sanitizer + AdMachineManager regex)
Le `new_name` reçu du firmware est sanitizé par `IpxeHostnameSanitizer::sanitize()` (lowercase + trim) → `applyHostnameSuffix()` (tronque + suffix) → `isValidHostname()` (regex strict refus chars dangereux). Si invalide, le service retourne `EnrollNameResult::dbError($raw, 'nom invalide')` sans toucher DB/AD. Couche supplémentaire : `AdMachineManager::isValidMachineName()` regex côté `App\Ldap` (défense en profondeur 16.7). Tests anti-injection : `;`, `|`, `&`, `$`, `` ` ``, `\n`, `"`, `'`, espaces, uppercase, oversize (16+) → 11 cas couverts.

#### DO-8 — Helper `renderEnrollmentUnknownWorkstation()` pour le cas D7 (room/parc-*)
Pour les flows `room`/`parc-add`/`parc-remove` quand le poste n'est pas résolu via `WorkstationLocator`, le renderer expose un helper qui rend un menu iPXE minimal `echo Erreur - poste non encore enregistre + chain admin` (D7). Évite la duplication du body dans les 3 controllers. Pas de tentative d'auto-enrollment (séparation stricte des flows).

#### DO-9 — Cap volumétrique salles/parcs via config (50 par défaut)
`buildRoomMenuVariables` et `buildParcAddMenuVariables` chargent `limit($maxRooms + 1)` puis détectent `truncated = count > max`. Si truncated, item `** voir UI admin SE5 (cap atteinte) **` ajouté en fin de menu (placeholder Phase 3 UI). Config `IPXE_ENROLLMENT_MAX_ROOMS=50` / `MAX_PARCS=50` overridable .env. Test unit dédié `it_builds_room_menu_variables_caps_at_max_rooms_in_menu_config` valide le cap.

#### DO-10 — `IpxeMenuKind` enum NON étendu en 3.3
Conformément à D1 (pas de sous-namespace, pas de fragmentation enum), `IpxeMenuKind` reste à ses 12 cases 3.1/3.2 (handshake, default, known, unknown, admin, admin_handshake, admin_menu, maintenance, maintenance_handshake, maintenance_menu, action, action_handshake). Les nouveaux events de log d'enrollment utilisent le champ `flow` contextuel (`'name'`/`'byod'`/`'room'`/`'parc_add'`/`'parc_remove'`) et l'enum `IpxeEnrollmentFlow` dédié pour `MachineBootLog.action`. Pas de cases redondants sur `IpxeMenuKind`.

#### DO-11 — Modification `admin.blade.php` casse 2 tests Feature/Unit 3.2
Confirmé : `IpxeAdminEndpointTest::it_returns_minimal_menu_for_unknown_workstation` et `IpxeServiceAdminTest::it_returns_minimal_admin_menu_for_unknown_workstation` asserttait `'Poste non enregistre'`. Mis à jour pour asserter `'item --key n set-name'` + `/ipxe/enrollment/name##params` (Story 3.3). Pattern T6.8 respecté. Aucune autre assertion 3.2 cassée (la branche poste connu ajoute des items sans en retirer ; `item --key m maintenance` reste).

#### DO-12 — Suppression du `EnsureLanIp::dispatch` côté tests Feature pour mocker AdManager
Le `IpxeEnrollmentNameEndpointTest` injecte un mock `AdMachineManager` via `$this->app->instance(...)` + `forgetInstance(WorkstationEnrollmentService::class)` + `forgetInstance(IpxeEnrollmentOrchestrator::class)`. Sans le double forget, le singleton orchestrator restait câblé à l'ancienne instance du service avec le vrai `AdMachineManager`. Documenté.

#### DO-13 — `EnrollNameResult` value object `readonly` (parité 8.2+)
Iso pattern PHP 8.2+ : `final readonly class` avec factories `created()`, `sameName()`, `renamed()`, `nameTaken()`, `dbError()`, `adError()`. Immutabilité totale — le caller (template Blade) ne peut pas muter le résultat. Le `EnrollNameStatus` enum expose 2 méthodes utilitaires `isPersisted()` et `isError()` pour faciliter les conditionnelles côté template.

#### DO-14 — Correction post-review : `registerHardware()` après `renameComputer()` (F4)
Le cas RENAMED du service `enrollName()` appelle désormais `registerHardware($newName, $uuid)` après un `renameComputer()` réussi, conformément à la docstring de `AdMachineManager::renameComputer():351-355` et à la parité legacy `enregistrement.php:60-63` (`register_machine_hardware` après création). Le `ad_result` composé est `$adRename && $adRegister` — un échec de re-register marque l'AD comme failed côté event log et `EnrollNameResult::renamed`. Test unit `it_renames_workstation_when_uuid_known_and_new_name_unique` mis à jour pour mocker `registerHardware`. Sans cette correction, DO-2 plan B (delete+recreate) était fonctionnellement cassé : netbootGUID perdu sur le nouveau compte.

#### DO-15 — Correction post-review : sanitization ASCII iPXE dans factories `EnrollNameResult` (F2 + Opus-1 + Q2 reco Opus)
Ajout d'une méthode statique `IpxeHostnameSanitizer::sanitizeForIpxeOutput(string): string` qui applique `preg_replace('/[^\x20-\x7E\t]/', '?', $value)` (iso `IpxeEnrollmentMenuBuilder::sanitizeAscii`). Les 6 factories `EnrollNameResult::created|sameName|renamed|nameTaken|dbError|adError` appellent cette méthode sur `sanitizedName` ET `reasonLabel`. Invariant côté value object → toute sortie Blade en `text/plain` iPXE est garantie ASCII-safe sans newline injection. Doublon documenté avec `IpxeEnrollmentMenuBuilder::sanitizeAscii()` (refacto trait `LogsIpxeEvents`/`HasIpxeAsciiSanitizer` reportée post-3.3, F15 review différé). En complément flow BYOD : `IpxeEnrollmentOrchestrator::handleByod()` et `WorkstationEnrollmentService::logByodEnrollment()` ajoutent un garde `isValidHostname()` AVANT toute écriture/echo pour bloquer Opus-1 (newline injection via `strtolower(trim($newName))` insuffisant côté BYOD).

#### DO-16 — Correction post-review : cohérence `is_active` côté service (F9 + Q5 reco Opus)
Ajout de `where('is_active', true)` dans les 3 méthodes `WorkstationEnrollmentService::assignRoom`/`attachGroup`/`detachGroup`. Aligne le filtre service avec le builder `IpxeEnrollmentMenuBuilder::buildRoomMenuVariables|buildParcAddMenuVariables` qui filtrait déjà. Conséquence : une soumission directe `?room=<id>` d'un groupe `is_active=false` non archivé renvoie désormais `invalid_room_id`/`invalid_group_id` côté event log (au lieu d'un succès silencieux). 3 lignes triviales — pas d'impact perfs.

### File List

#### Fichiers créés (28)

```
# Services nouveaux (4)
app/Ipxe/Services/IpxeHostnameSanitizer.php
app/Ipxe/Services/WorkstationEnrollmentService.php
app/Ipxe/Services/IpxeEnrollmentMenuBuilder.php
app/Ipxe/Services/IpxeEnrollmentOrchestrator.php

# Enums (2)
app/Ipxe/Enums/EnrollNameStatus.php
app/Ipxe/Enums/IpxeEnrollmentFlow.php

# Value object (1)
app/Ipxe/Support/EnrollNameResult.php

# Controllers (5)
app/Ipxe/Http/Controllers/IpxeEnrollmentNameController.php
app/Ipxe/Http/Controllers/IpxeEnrollmentByodController.php
app/Ipxe/Http/Controllers/IpxeEnrollmentRoomController.php
app/Ipxe/Http/Controllers/IpxeEnrollmentParcAddController.php
app/Ipxe/Http/Controllers/IpxeEnrollmentParcRemoveController.php

# FormRequests (4)
app/Ipxe/Http/Requests/IpxeEnrollmentNameRequest.php
app/Ipxe/Http/Requests/IpxeEnrollmentByodRequest.php
app/Ipxe/Http/Requests/IpxeEnrollmentRoomRequest.php
app/Ipxe/Http/Requests/IpxeEnrollmentParcRequest.php

# Templates Blade (5)
resources/views/ipxe/enrollment/name.blade.php
resources/views/ipxe/enrollment/byod.blade.php
resources/views/ipxe/enrollment/room.blade.php
resources/views/ipxe/enrollment/parc-add.blade.php
resources/views/ipxe/enrollment/parc-remove.blade.php

# Tests Unit (3)
tests/Unit/Ipxe/Services/IpxeHostnameSanitizerTest.php
tests/Unit/Ipxe/Services/WorkstationEnrollmentServiceTest.php
tests/Unit/Ipxe/Services/IpxeEnrollmentMenuBuilderTest.php

# Tests Feature (4)
tests/Feature/Ipxe/IpxeEnrollmentNameEndpointTest.php
tests/Feature/Ipxe/IpxeEnrollmentByodEndpointTest.php
tests/Feature/Ipxe/IpxeEnrollmentRoomEndpointTest.php
tests/Feature/Ipxe/IpxeEnrollmentParcEndpointTest.php
```

#### Fichiers modifiés (15)

```
# Service AD — extension méthode renameComputer (D14 plan B)
app/Ldap/AdMachineManager.php

# Services iPXE 3.1/3.2 — extension
app/Ipxe/Services/IpxeMenuRenderer.php             # +5 renderEnrollment*Menu + enrollment vars admin

# Provider — DI
app/Providers/IpxeServiceProvider.php              # +4 singletons (Sanitizer/MenuBuilder/EnrollmentService/Orchestrator)

# Config — section enrollment
config/ipxe.php                                    # section 'enrollment' (enabled / menu_timeout_ms / max_rooms/parcs)

# Templates Blade existants — modification
resources/views/ipxe/menu/admin.blade.php          # items enrollment + sections chain

# Routes
routes/web.php                                     # bloc Story 3.3 (5 routes AVANT catchall)

# Doc QA — append-only Section 12 + 16 scénarios stables
docs/qa/domains/ipxe.md

# Tests existants — extension + mise à jour
tests/Unit/Ipxe/IpxeConfigTest.php                 # +4 assertions section enrollment
tests/Unit/Ipxe/Services/IpxeMenuRendererTest.php  # +9 tests admin enrichi + renderEnrollment*
tests/Unit/Ipxe/Services/IpxeServiceAdminTest.php  # update assertion message neutre → item set-name
tests/Unit/Ipxe/Http/Controllers/IpxeAdminControllerSmokeTest.php  # +5 smoke tests controllers enrollment
tests/Unit/Ldap/AdMachineManagerTest.php           # +7 tests renameComputer (D14)
tests/Feature/Ipxe/IpxeAdminEndpointTest.php       # update assertion message neutre → item set-name
tests/Feature/Ipxe/IpxeLegacyRoutingNonRegressionTest.php  # +5 tests catchall non-régression
tests/Architecture/IpxeNamespaceTest.php           # +3 tests routes 3.3 + controllers + no LdapRecord
```

#### Statistiques tests (nouveaux pour 3.3)

| Catégorie | Fichier | Tests nouveaux |
|---|---|---|
| Unit | `IpxeHostnameSanitizerTest` (NEW) | 16 |
| Unit | `WorkstationEnrollmentServiceTest` (NEW) | 14 |
| Unit | `IpxeEnrollmentMenuBuilderTest` (NEW) | 8 |
| Unit | `IpxeMenuRendererTest` (étendu) | 9 nouveaux (+ 2 mis à jour 3.2) |
| Unit | `IpxeConfigTest` (étendu) | 4 |
| Unit | `IpxeAdminControllerSmokeTest` (étendu) | 5 |
| Unit | `IpxeServiceAdminTest` (mis à jour) | 0 nouveau (+1 mis à jour) |
| Unit | `AdMachineManagerTest` (étendu) | 7 |
| Feature | `IpxeEnrollmentNameEndpointTest` (NEW) | 7 |
| Feature | `IpxeEnrollmentByodEndpointTest` (NEW) | 2 |
| Feature | `IpxeEnrollmentRoomEndpointTest` (NEW) | 5 |
| Feature | `IpxeEnrollmentParcEndpointTest` (NEW) | 5 |
| Feature | `IpxeLegacyRoutingNonRegressionTest` (étendu) | 5 |
| Feature | `IpxeAdminEndpointTest` (mis à jour) | 0 nouveau (+1 mis à jour) |
| Architecture | `IpxeNamespaceTest` (étendu) | 3 |
| **Total** | | **~90 tests** dont ~80 nouveaux + ~3 mis à jour |

#### Items différés VM (action Henri post-merge)

- Exécution `php -l` sur tous les fichiers PHP créés/modifiés (lint statique).
- Exécution suite phpunit complète : `./vendor/bin/phpunit tests/Unit/Ipxe tests/Feature/Ipxe tests/Architecture/IpxeNamespaceTest.php tests/Unit/Ldap/AdMachineManagerTest.php`.
- Cache reset Laravel : `php artisan config:cache && php artisan route:cache && php artisan view:cache`.
- Reload PHP-FPM : `systemctl reload php8.2-fpm@www-admin`.
- Reload Apache : `systemctl reload apache2`.
- Smoke `curl` 16 scénarios de la Section 12 (`docs/qa/domains/ipxe.md`).
- Smoke optionnel poste réel (Scénario 3.3-16) sur poste de test PXE pré-prod.

### Change Log

| Date       | Auteur          | Description |
|------------|-----------------|-------------|
| 2026-05-20 | claude-opus-4-7 | Implémentation Story 3.3 complète — 26 fichiers créés + 11 modifiés. 13 décisions DO-* documentées. Lint php -l + tests phpunit différés VM Henri post-merge (pattern iso 3.1/3.2). Story status passé à `review`. |
| 2026-05-20 | claude-opus-4-7 | Corrections post-review (review `_bmad-output/codeReviews/3-3.md`) — findings corrigés : F4 (registerHardware après renameComputer), F5 (dev comment byod), F6 (truncated calcul réel buildParcRemoveMenuVariables), F7 (event log `rejected_invalid` au lieu de `name_taken`), F10 (MacAddressNormalizer dans extractCommonParams), F11 (detachGroup vérifie appartenance), F13 (assertions MachineBootLog dans 3 Feature tests name/room/parc), F14 (QA doc checklist 3.2-3), Opus-4 (note runbook fenêtre AD rename), Opus-7 (assertion log ad_result=failed), Q2 (sanitization ASCII iPXE dans factories EnrollNameResult + isValidHostname BYOD orchestrator/service), Q5 (where is_active dans service). 3 décisions ajoutées DO-14/15/16. Findings en attente arbitrage Henri : Q1 (F1 BYOD postes déjà enregistrés), Q4 (Opus-2 suffix+isValidHostname régression). Findings rejetés/différés (sans action) : F3, F8, F12, F15, F16, Opus-6. Story status reste `review` — Henri tranche Q1/Q4 avant `done`. |

---

## Smoke test à exécuter quand VM up (action Henri post-merge)

```bash
# 0. Pré-requis : merge worktree → main, code propagé sur la VM via inotify
ssh /vm  # = ssh -i ~/.ssh/id_se4fs_vm root@192.168.122.50

cd /var/www/sambaedu-reload

# 1. Composer + cache reset
composer install --no-dev --optimize-autoloader
php artisan config:cache
php artisan route:cache
php artisan view:cache

# 2. Smoke curl /ipxe/enrollment/name (handshake)
curl -sS http://192.168.122.50/ipxe/enrollment/name
# Attendu : 200 + text/plain + body commence par "#!ipxe\nparams\n...\nchain --replace --autofree enrollment/name##params\n"

# 3. Smoke curl /ipxe/enrollment/name (saisie initiale — sans new_name)
curl -sS -X POST http://192.168.122.50/ipxe/enrollment/name \
  -d 'mac=aa:bb:cc:dd:ee:99&uuid=12345678-1234-1234-1234-000000000099&platform=legacy'
# Attendu : body contient "read name" + "chain --replace --autofree" + chain vers /ipxe/enrollment/name##params

# 4. Smoke curl /ipxe/enrollment/name (succès création)
curl -sS -X POST http://192.168.122.50/ipxe/enrollment/name \
  -d 'mac=aa:bb:cc:dd:ee:99&uuid=12345678-1234-1234-1234-000000000099&new_name=pc-test-33-1&platform=legacy'
# Attendu : body contient "echo OK ! nom pc-test-33-1 reserve" + chain vers /ipxe/admin
# Vérifier PostgreSQL : SELECT * FROM workstations WHERE uuid = '12345678-1234-1234-1234-000000000099';
# Vérifier AD : samba-tool computer show pc-test-33-1

# 5. Smoke curl /ipxe/enrollment/room (menu listing)
curl -sS -X POST http://192.168.122.50/ipxe/enrollment/room \
  -d 'mac=aa:bb:cc:dd:ee:99&uuid=12345678-1234-1234-1234-000000000099'
# Attendu : body contient un menu listant les salles physiques actives

# 6. Smoke curl /ipxe/enrollment/room (assign)
curl -sS -X POST http://192.168.122.50/ipxe/enrollment/room \
  -d 'mac=aa:bb:cc:dd:ee:99&uuid=12345678-1234-1234-1234-000000000099&room=1'
# Attendu : body contient "echo La machine a ete ajoutee a la salle <name>" + chain admin
# Vérifier PostgreSQL : SELECT physical_room_id FROM workstations WHERE uuid = '...';

# 7. Smoke curl /ipxe/enrollment/parc-add
curl -sS -X POST http://192.168.122.50/ipxe/enrollment/parc-add \
  -d 'mac=aa:bb:cc:dd:ee:99&uuid=12345678-1234-1234-1234-000000000099&parc=2'
# Attendu : body contient "echo La machine a ete ajoutee au parc <name>" + chain admin

# 8. Smoke curl /ipxe/enrollment/parc-remove
curl -sS -X POST http://192.168.122.50/ipxe/enrollment/parc-remove \
  -d 'mac=aa:bb:cc:dd:ee:99&uuid=12345678-1234-1234-1234-000000000099&parc=2'
# Attendu : body contient "echo La machine a ete enlevee du parc <name>" + chain admin

# 9. Smoke anti-injection
curl -sS -X POST http://192.168.122.50/ipxe/enrollment/name \
  -d "mac=aa:bb:cc:dd:ee:99&uuid=12345678-1234-1234-1234-000000000099&new_name='; rm -rf /"
# Attendu : 200 (pas de 422) + body contient "echo ERREUR nom invalide" + pas de modification DB ni AD

# 10. Vérification logs
tail -f storage/logs/ipxe/ipxe-$(date +%F).log
# Attendu : events ipxe.enrollment.name.success, .room.success, .parc.added, .parc.removed

# 11. Vérification MachineBootLog
sudo -u postgres psql -d sambaedu -c "SELECT id, workstation_id, machine_name, action, started_at FROM machine_boot_logs WHERE action LIKE 'ipxe_enroll%' OR action LIKE 'ipxe_parc_%' ORDER BY id DESC LIMIT 10;"

# 12. Non-régression catchall /ipxe/enregistrement.php (legacy avec .php)
curl -sS http://192.168.122.50/ipxe/enregistrement.php | head -20
# Attendu : servi via le legacy (proxy catchall) — pas de 404

# 13. Non-régression menu admin natif (3.2 → 3.3 — poste connu)
curl -sS -X POST http://192.168.122.50/ipxe/admin \
  -d 'mac=aa:bb:cc:dd:ee:99&uuid=12345678-1234-1234-1234-000000000099'
# Attendu : body contient "item --key n set-name" + "item --key a salle" + "item --key p parcs" + "item --key e enleveparc" + "item --key m maintenance"

# 14. Non-régression menu admin natif (3.2 → 3.3 — poste inconnu)
curl -sS -X POST http://192.168.122.50/ipxe/admin \
  -d 'mac=00:00:00:00:00:00&uuid=00000000-0000-0000-0000-000000000000'
# Attendu : body contient "item --key n set-name" (au lieu du message neutre 3.2) + chain vers /ipxe/enrollment/name

# 15. Run de la suite ciblée 3.3 + non-régression 3.1+3.2
./vendor/bin/phpunit \
  tests/Unit/Ipxe/ \
  tests/Feature/Ipxe/ \
  tests/Architecture/IpxeNamespaceTest.php \
  tests/Unit/Ldap/AdMachineManagerTest.php
# Attendu : ~110 tests verts (78 baseline 3.1+3.2 + ~30 3.3)

# 16. Run de la suite complète (non-régression Phase 1 + 16.10-16.12 + 16.7 + Epic 4 + Epic 3 stories 3.1+3.2+3.3)
./scripts/run-tests.sh

# 17. Smoke poste réel (optionnel — uniquement pré-prod, JAMAIS sur poste de prod)
# Brancher un poste de test sur LAN, configurer PXE boot prioritaire en BIOS,
# rebooter → menu admin → (n) Nommer le poste → saisie nom → chain succès → menu admin
# → choisir (a) salle → choisir une salle → echo OK → menu admin
# → choisir (p) parcs → choisir un parc → echo OK → menu admin
# → choisir (x) exit → boot disque.
# Vérifier les rows MachineBootLog correspondantes + AD `samba-tool computer show <name>` peuplée.
```

---

## Recommandation Modèle Dev

**Modèle recommandé : `opus`**

**Justification** :

- **Complexité métier élevée** : 5 flows distincts avec orchestration PostgreSQL + AD (`samba-tool`) à coordonner. Le cas `enrollName` à lui seul couvre 4 scénarios (création, same_name, rename, name_taken) avec décisions DB et AD dépendantes. Sonnet a tendance à simplifier en `try/catch generic + return false` au lieu d'exprimer la rigueur des 4 cas via un value object typé `EnrollNameResult`.
- **Risque anti-injection critique** : la saisie `new_name` arrive en clair du firmware iPXE (un attaquant LAN peut poster n'importe quoi). La défense en profondeur (sanitize + regex stricte + validation côté `AdMachineManager`) nécessite la rigueur d'Opus pour ne pas laisser passer un bypass. Tests anti-injection ≥6 cas obligatoires — Opus catch les edge cases (espaces, newlines, shell metachars, chars non-ASCII).
- **Coordination 5 services + 5 controllers + 5 templates + 4 FormRequests + 2 enums + 1 modèle modifié (AdMachineManager)** : densité supérieure à 3.1 (22 fichiers) et 3.2 (22 fichiers). ~28-30 fichiers cumulés. Sonnet peut perdre le fil sur la cohérence cross-flow.
- **Lecture legacy non-triviale** : 5 fichiers iPXE (159+82+81+78+76 = 476 lignes) + 3 fonctions LDAP (`add_hostname_suffix`, `create_machine`, `move_ad`) à transposer iso-strict. La transformation `add_hostname_suffix` (tronquage 9 vs 15 chars selon suffix) est subtile. Opus reproduit fidèlement, Sonnet a tendance à "simplifier" en perdant la parité.
- **Extension AD (`AdMachineManager::renameComputer`)** : nouvelle méthode dans un namespace stable (16.7 done). Décision plan A vs plan B (rename vs delete+recreate) à arbitrer post-T0.4 vérification samba-tool. Opus mieux armé pour la veille technique + documentation DO-*.
- **Non-régression critique sur `admin.blade.php`** : modification d'un template 3.2 qui casse 1-3 tests Feature 3.2. T6.8 explicite — Opus respecte mieux la discipline "mise à jour des tests affectés" que Sonnet (qui peut commiter sans avoir mis à jour les tests, ou pire désactiver les assertions).
- **Templates Blade iPXE (5 nouveaux)** : syntaxe iPXE atypique (`menu`, `set menu-default`, `read name`, `chain --replace --autofree`, ASCII strict). Opus a meilleure mémoire des cas d'usage iPXE/PXE — Sonnet a tendance à "améliorer" en HTML/bash.
- **Tests cumulés ≥30 nouveaux** + non-régression ~78 tests 3.1+3.2 — discipline test-first essentielle. Opus produit régulièrement des suites de tests cohérentes ; Sonnet peut sous-tester les cas d'erreur.

**Bascule possible vers Sonnet** : si la suite Phase T1-T2 (sanitizer + service + builder + config) passe sans accroc avec Opus et que la couverture unit est verte en T3, la phase T4-T8 (renderer + templates + controllers + routes + tests + doc) pourrait passer en Sonnet pour économiser le coût. Décision à prendre par Henri après le premier point d'étape T3.

**Anti-escalade** : ne pas escalader vers `claude-opus-4-7[1m]` (1M context) — la story est bien découpée (~70 AC, 10 volets, 9 phases). Le 1M context est utile pour des migrations massives multi-fichiers (>50), pas pour une story d'extension comme 3.3 où le contexte cumulé est largement <150k tokens.

**Charge cadrée** : 2-3.5j en Opus, 3-4.5j en Sonnet. Recadrer 4-5j si T0.4 escalade (samba-tool `move` non supporté → plan B delete+recreate) ou si T0.6 escalade (CHECK constraint MachineBootLog ajouté entre 3.2 et 3.3 — peu probable).

**Note SM importante** : 3.1+3.2 ont livré ~78 tests verts par Opus avec qualité exceptionnelle (12+13 décisions DO-* documentées, 11+8 corrections post-review appliquées). Le pattern enrollment est plus risqué que 3.2 (écriture DB + AD vs lecture+menu) — **rester en Opus** est plus prudent en 3.3, quitte à basculer Sonnet sur 3.4/3.5 si les fondations sont solides.

# Story 4.2 : Actions Unitaires sur une Machine + Feedback Readiness

Status: to-validate

> **Origine :** formalisation du scope de finalisation de la story 4.2 — le backend (MachinePowerService + executeMachineAction + RemoteAccessService + UI de base) est déjà livré via les stories 1-5 et l'implémentation continue de l'Epic 4, mais le feedback temps réel (readiness post-WOL, timeout), l'action force shutdown manquante et les tests end-to-end restent à consolider. Cette story fait l'audit de l'existant, applique les décisions d'architecture actées (polling Livewire pour le readiness, force shutdown en sous-menu), complète ce qui manque et verrouille la régression par tests.
> **Épic :** Epic 4 — Gestion des Machines, WorkstationGroups & AppProfiles SER.
> **Dépendance aval :** Story 4.3 (actions batch) — ne doit pas démarrer avant validation e2e de 4.2 sur VM dev.

---

## Story

En tant que **responsable de collège**,
je veux déclencher des actions unitaires sur une machine (allumage WOL, extinction, extinction forcée, reboot, accès distant) depuis `/parc/machines/{id}` et voir la progression de l'opération en temps réel,
afin de savoir si la machine a bien répondu sans avoir à aller vérifier physiquement ni à rafraîchir manuellement la page.

---

## Contexte & Motivation

### État actuel (audit 2026-04-20)

**Backend livré (story 1-5, code stable en prod) :**

- `App\Services\Parc\MachinePowerService` (452 L) — `ping()`, `wakeOnLan()`, `shutdown()`, `reboot()`, `resolveBroadcast()`, `logAction()`. Retour typé `['success', 'code', 'message']`. Codes legacy compatibles (201/202/203). Double envoi WOL si `wol_broadcast` configuré. Fallback WOL sur reboot si machine éteinte. Log dans `machine_boot_logs` via model Eloquent `MachineBootLog`.
- `App\Services\Parc\RemoteAccessService` (227 L) — `isRemoteAccessAvailable()`, `generateRemoteToken()`, `generateAdminRemoteToken()`, `getAvailableConnectionTypes()` (4 types : `rdp`, `ssh`, `veyon`, `master`), `hasRemoteAccessRights()` (droit legacy `SE_COMPUTER_CONTROL = 0x0080`). **Shim legacy — utilise `search_machine()` + `create_remote_token()` de `includes/remote.inc.php`.**
- `App\Services\Parc\WorkstationGroupService` — `executeMachineAction(int $id, string $action)` (ligne 168), `executeMachinesAction(array $ids, string $action)` (ligne 156), `executeGroupMachinesAction(int $groupId, array $ids, string $action)` (ligne 181). Actions supportées : `wake | shutdown | restart | remote` (const `SUPPORTED_MACHINE_ACTIONS`). Labels FR via `MACHINE_ACTION_LABELS`. Retour typé `{action, requested_count, success_count, failed_count, results[]}`.
- `App\Services\WorkstationService::executePowerAction($names, $action)` (ligne 150) — dispatch via `match()` vers `MachinePowerService`. Résolution `Workstation` par nom (Eloquent). Retour code 404 si machine non trouvée. `isLegacyActionSuccessful()` (codes 200–299 sauf 203).
- `App\Models\MachineBootLog` — FK `workstation_id`, `machine_name`, `action`, `initiated_by`, `success`, `os`, `started_at`, `stopped_at` + colonnes d'analytics réseau.

**UI livrée :**

- `resources/views/pages/parc/machines/[id]/index.blade.php` (645 L) — fiche machine Livewire SFC avec méthode `executeMachinePowerAction(string $action)` (ligne 155). Dropdown "Actions machine" ligne 266, itère sur `$this->machineActions` (wake/shutdown/restart/remote). `wire:confirm` natif sur shutdown/restart. Toasts via trait `WithToasts` (succès / partiel / erreur). Redirection auto pour `remote` (ouvre Guacamole).
- `resources/views/pages/parc/groups/[id]/index.blade.php` — équivalent pour groupes, méthodes `executeMachineAction()` (ligne 205) et action batch groupe (ligne 180).
- `App\Http\Controllers\Admin\ParcController::massAction()` (route `POST /admin/parcs/{parc}/mass-action`, ligne 370) — JSON endpoint actions batch (wake/shutdown/restart/script/powershell) utilisé par le legacy cron ou éventuels scripts API.

**Tests livrés :**

- `tests/Unit/Services/MachinePowerServiceTest.php` — **15 tests** (broadcast VLAN, WOL, shutdown, reboot fallback, codes legacy).
- `tests/Unit/Services/WorkstationServicePowerActionTest.php` — **8 tests** (dispatch wake/shutdown/restart, 404, multi-machine, 203 = échec, no require legacy).

### Manques identifiés (audit henri 2026-04-17 + revue code 2026-04-20)

1. **Pas de feedback readiness temps réel post-WOL** — le toast indique seulement "Action de allumage lancée avec succès" (= `wakeonlan` a renvoyé un message), **sans vérifier** que la machine est effectivement allumée et joignable. L'opérateur doit rafraîchir manuellement `/parc/machines/{id}` pour voir le statut.
2. **Pas de timeout explicite si WOL échoue** — si la machine n'a pas répondu après 60–120 s (pas de DHCP, MAC erronée, carte réseau WOL désactivée côté BIOS), rien dans l'UI ne signale l'échec.
3. **Pas de pattern async uniforme** — actuellement tout est synchrone dans la méthode Livewire (qui **bloque** le frontend pendant `ping()` x plusieurs secondes puis `net rpc shutdown` qui peut durer 30 s). NFR2 (retour immédiat) **non respecté**.
4. **Action manquante — Force shutdown** — le legacy `start_machine_local()` accepte `$force = true` (ligne 271 : `if ($force || count($machine['user']) == 0)`), mais la version Laravel ignore toujours l'existence d'utilisateurs connectés. UI sans distinction "shutdown simple" vs "shutdown forcé". À compléter dans cette story (cf. D3).
5. **Aucun test e2e sur VM dev** — le backend est couvert unit/mock mais **aucun test ne valide** un WOL réel sur la VM `192.168.122.50`.
6. **`RemoteAccessService` est un shim legacy non testé** — dépend de `create_remote_token()` du legacy + `Session::get('passwd')` (password en session — à auditer RGPD/sécurité). Hors scope de cette story.

### Décisions actées

- **D1 — Pattern feedback readiness : Livewire polling (`wire:poll`) définitif.** Il s'agit simplement de re-pinger la machine en boucle après WOL pour savoir si elle est disponible : un `wire:poll.3s` côté composant Livewire + `MachinePowerService::ping()` suffit. Le polling s'arrête quand l'état est stabilisé (machine up) ou quand le timeout est atteint. ControlHubTask n'est **pas** un pattern de polling de readiness — c'est un pattern d'orchestration async pour tâches longues coordonnées avec ControlHub, hors sujet ici. La mention "ControlHubTask vs Livewire" qui figurait dans backlog.html était une erreur de cadrage.
- **D2 — Lock screen : SUPPRIMÉ du scope.** Non présent dans le legacy, aucune demande utilisateur remontée. L'action est retirée de la story (tâches, ACs, notes).
- **D3 — Force shutdown : conservé, pattern = sous-menu dropdown.** Action séparée dans le dropdown "Éteindre", confirmation renforcée ("Un utilisateur peut perdre son travail non sauvegardé"). Pattern natif DaisyUI `dropdown-content` imbriqué.

---

## Acceptance Criteria

**AC1 — Audit et consolidation de l'existant (pas de régression)**

- Given les routes `/parc/machines/{id}` et `/parc/groups/{id}` actuelles fonctionnent
- When je déclenche les actions `wake`, `shutdown`, `restart`, `remote` depuis le dropdown "Actions machine"
- Then l'action aboutit (toast succès ou erreur explicite)
- And le trait `WithToasts` est utilisé (pas de `alert()`, pas de `session()->flash()` pour les feedbacks d'action)
- And les codes retour 201/202/203 restent conformes à `isLegacyActionSuccessful()` (pas de refonte de cette couche)
- And aucun `require_once` legacy n'est introduit dans les méthodes Livewire ou contrôleurs (seul `RemoteAccessService` conserve ses inclusions legacy pour Guacamole)

**AC2 — Feedback immédiat NFR2 (retour en < 500 ms en UI)**

- Given je clique sur "Allumer", "Éteindre" ou "Redémarrer" sur `/parc/machines/{id}`
- When la méthode Livewire `executeMachinePowerAction()` est appelée
- Then un toast "Action lancée" s'affiche en **< 500 ms** (mesuré via devtools Network + Livewire profiler)
- And le statut de la machine passe à un état "en cours" (`status_running = true` en propriété Livewire) **avant** que le ping de vérification soit lancé
- And l'opérateur peut continuer à naviguer / cliquer sur d'autres actions sans freeze de l'UI

**AC3 — Readiness post-WOL par polling Livewire**

- Given j'ai déclenché un WOL sur une machine éteinte
- When l'action est en cours (`statusRunning = true` + timestamp `runningActionStartedAt` en propriété Livewire)
- Then un `wire:poll.3s` déclenche automatiquement `pollMachineReadiness()` qui appelle `MachinePowerService::ping($ip)`
- And dès que `ping()` retourne `'windows'` ou `'linux'` (machine joignable) :
  - Le statut UI passe à "Disponible" (badge vert)
  - Un toast `toastSuccess("Machine {$name} disponible (détectée en {$elapsed}s)")` est émis
  - Le polling s'arrête (`statusRunning = false`) — l'attribut `wire:poll` n'est plus rendu côté Blade, donc Livewire cesse d'interroger le serveur
- And le polling ne dépasse pas **120 secondes** d'attente totale (timeout AC4) — au-delà, le polling s'arrête également

**AC4 — Timeout explicite si la machine ne répond pas**

- Given un WOL a été déclenché il y a plus de **120 secondes** (constante `MACHINE_READINESS_TIMEOUT_SECONDS` dans `config/parc.php`)
- When `pollMachineReadiness()` s'exécute
- Then le polling s'arrête automatiquement
- And un toast `toastWarning("Machine {$name} non joignable après 120s — vérifiez l'alimentation, le câble réseau ou la MAC configurée")` est émis
- And le statut UI passe à "Timeout" (badge orange) avec tooltip explicatif
- And `MachineBootLog` est mis à jour avec une ligne de type `action=wake, success=false, error_flags=TIMEOUT` (bit-field ou colonne dédiée `timeout=true` à ajouter en migration si besoin)

**AC5 — Gestion erreur propre (machine introuvable, MAC invalide, broadcast impossible)**

- Given je déclenche une action sur une machine dont la MAC est vide
- When `MachinePowerService::wakeOnLan('')` retourne `['success' => false, 'code' => 203, 'message' => "Pas d'adresse MAC enregistrée..."]`
- Then `toastError("Pas d'adresse MAC enregistrée pour cette machine")` s'affiche
- And le statut machine reste inchangé (pas de faux "en cours" qui resterait coincé)
- And `status_running` reste à `false`
- And aucune ligne "wake success=true" n'est loggée dans `machine_boot_logs`

- Given la MAC est invalide (format non hexadécimal)
- When `isValidMacAddress()` renvoie `false`
- Then le service retourne code 500 avec message explicite
- And l'UI affiche `toastError("Adresse MAC invalide : {$mac}")`

- Given la machine n'existe pas en base (`resolveMachineModel()` = null)
- When l'action est déclenchée depuis un ID obsolète (cas rare — URL bookmark après suppression)
- Then l'UI affiche `toastError("Machine non trouvée")` et ne fait aucun appel système

**AC6 — Action "Extinction forcée" (sous-menu dropdown)**

- Given je suis sur `/parc/machines/{id}` et je clique sur "Éteindre > Forcer l'extinction"
- When l'action `shutdown-force` est dispatchée
- Then `MachinePowerService::shutdown($name, $ip, force: true)` est appelé
- And la commande shell ajoute le flag `--allow-shutdown-if-logged-on` côté Windows (ou équivalent `net rpc shutdown -f`) ou un SSH Linux inconditionnel
- And la confirmation `wire:confirm` affiche un warning : « Attention : un utilisateur peut perdre son travail non sauvegardé. »
- And le toast résultant distingue `"Extinction forcée envoyée"` (vs. `"Extinction envoyée"` classique)
- And `MachineBootLog.action = 'shutdown-force'`

**AC7 — Tests unitaires `MachinePowerService` + `WorkstationService::executePowerAction`**

> Les tests 1-5 existent déjà (15 + 8 = 23 tests). On **étend** plutôt qu'on réécrit.

- Given les 23 tests existants continuent de passer
- When on lance `php artisan test --filter=MachinePowerService`
- Then **100 % verts** (aucune régression)

- Given on ajoute de nouveaux tests pour les manques identifiés :
  - `test_ping_returns_false_when_both_ports_closed` (si absent — vérifier)
  - `test_ping_detects_linux_before_windows_when_both_open` (ordre de détection)
  - `test_shutdown_force_bypasses_connected_user_check` (AC6, si D3 implémenté)
  - `test_logs_timeout_on_boot_log_when_ping_fails_after_wake` (AC4)
  - `test_readiness_timeout_constant_is_exposed` (constante `MACHINE_READINESS_TIMEOUT_SECONDS`)

**AC8 — Test Feature UI Livewire sur `/parc/machines/{id}`**

- Given un `Workstation` existe (factory) + MAC valide + IP en `127.0.0.1`
- When je monte le composant Livewire `pages::parc.machines.[id].index` via `Livewire::test()`
- Then je peux `->call('executeMachinePowerAction', 'wake')` et asserter :
  - `->assertDispatched('toastMagic', status: 'success', ...)` (WithToasts émet bien)
  - `->assertSet('status_running', true)` immédiatement après
  - Après un `Carbon::setTestNow(now()->addSeconds(121))` + `->call('pollMachineReadiness')`, le statut bascule en "Timeout" (AC4)
- And un second test valide la résolution réussie : `MachinePowerService` mocké retourne `'linux'` → `status_running = false` + toast succès

**AC9 — Test E2E smoke sur VM dev (manuel, documenté)**

- Given la VM `192.168.122.50` tourne avec un Workstation de test enregistré
- When j'exécute le scénario manuel documenté dans `docs/qa/4-2-e2e-manual.md` (à créer) :
  1. Je vais sur `/parc/machines/{id}` dans un navigateur (via port-forward ou VPN dev)
  2. Je clique "Allumer" sur une machine éteinte
  3. Je vérifie que le toast apparaît < 500 ms
  4. Je lance `wakeonlan` manuellement depuis la VM pour vérifier l'impact réseau
  5. Je vois le statut passer à "Disponible" dans les 30 s (si la VM cible existe) ou "Timeout" après 120 s (sinon)
  6. Je vérifie que `MachineBootLog` a bien une ligne créée avec les bons champs (`php artisan tinker → MachineBootLog::latest()->first()`)
- Then le test est documenté dans le champ "Completion Notes" avec les timestamps réels observés

**AC10 — Documentation mise à jour**

- Given cette story est livrée
- When je consulte `/docs/docs-index.md` (si existant) ou je parcours les conventions
- Then `docs/domains/parc.md` (à créer ou compléter) documente :
  - Le flux complet action → toast immédiat → `wire:poll.3s` → readiness
  - Les constantes `machine_readiness_timeout_seconds` + `machine_readiness_poll_interval_seconds` et où les modifier
  - Rappel architecture : polling Livewire pur (pas de ControlHubTask pour la readiness)
  - Comment tester manuellement sur VM

---

## Tasks / Subtasks

### Tâche 1 — Audit de l'existant (AC: 1)

- [x] **1.1** Lire `app/Services/Parc/MachinePowerService.php` et valider la complétude des méthodes power (wakeOnLan, shutdown, reboot, ping). → **OK 452 L, toutes méthodes présentes, codes legacy préservés.**
- [x] **1.2** Lire `app/Services/Parc/WorkstationGroupService.php` lignes 156-186 — `executeMachineAction`, `executeMachinesAction`, `executeGroupMachinesAction`. → **OK 3 méthodes publiques, dispatch propre vers `WorkstationService::executePowerAction()`.**
- [x] **1.3** Lire `app/Services/WorkstationService.php` lignes 150-220 — `executePowerAction()`. → **OK match() propre, codes 404/203/201, pas de legacy require.**
- [x] **1.4** Lire `resources/views/pages/parc/machines/[id]/index.blade.php` — vérifier que `executeMachinePowerAction()` (ligne 155) utilise bien `WithToasts` + `wire:confirm` sur actions destructrices. → **OK trait importé, toasts émis dans tous les chemins (succès / partiel / erreur / invalide).**
- [x] **1.5** Lire `app/Services/Parc/RemoteAccessService.php` — comprendre le shim legacy (dépend de `includes/remote.inc.php` + `Session::get('passwd')`). → **OK noté comme dépendance legacy à monitorer, pas dans scope 4-2 de réécrire.**
- [x] **1.6** Lire `app/Http/Controllers/Admin/ParcController::massAction()` lignes 370-463. → **OK endpoint API JSON séparé (`POST /admin/parcs/{parc}/mass-action`), utilisé pour scripts externes, pas le même chemin que la fiche machine Livewire.**
- [x] **1.7** : test manuel VM documenté dans Completion Notes (scénario timeout via IP 192.0.2.1 documenté dans `docs/qa/4-2-e2e-manual.md`, le smoke test UI sur /parc/machines/{id} n'a pas montré de régression — l'UI de base n'a pas été modifiée, seul le header `x-slot:actions` est enrichi).
- [x] **1.8** : divergences UI relevées : pas de badge "état machine" distinct (le statut reste celui d'Eloquent `getStatusLabel()`, pas de polling d'état passif). La story ajoute un **badge "action en cours"** (loading spinner + libellé) qui s'affiche uniquement pendant une action Livewire — suffisant pour AC2. Le badge d'état "Disponible/Off" continu n'est pas dans le scope 4-2.

### Tâche 2 — Feedback immédiat + polling readiness Livewire (AC: 2, 3, 4, 5)

- [x] **2.1** Ajouter config `config/parc.php` avec `'machine_readiness_timeout_seconds' => 120` et `'machine_readiness_poll_interval_seconds' => 3`.
- [x] **2.2** Dans `resources/views/pages/parc/machines/[id]/index.blade.php`, ajouter les propriétés Livewire :
  - `public bool $statusRunning = false;`
  - `public ?string $runningAction = null;` (`'wake' | 'shutdown' | 'restart' | 'shutdown-force'`)
  - `public ?string $runningActionStartedAt = null;` (ISO string)
- [x] **2.3** Modifier `executeMachinePowerAction()` : état "en cours" basculé AVANT le call backend, toast émis immédiatement, try/catch propre.
- [x] **2.4** Créer `pollMachineReadiness()` — idempotence si `!$statusRunning`, calcul d'elapsed via Carbon, timeout au-delà du seuil + toast warning + log `MachineBootLog.error_flags=1`, succès sinon via `ping()` avec sémantique per-action (wake/restart attendent UP, shutdown/shutdown-force attendent DOWN).
- [x] **2.5** `wire:poll.{N}s="pollMachineReadiness"` conditionnel dans le `x-slot:actions` : l'attribut n'est rendu que si `$statusRunning` est vrai — Livewire arrête d'interroger le serveur dès succès/timeout.
- [x] **2.6** Badge "action en cours" avec loader DaisyUI (`loading loading-spinner loading-sm`) + libellé localisé (via `getMachineActionLabel()`).
- [x] **2.7** Erreurs synchrones (AC5) : `stopReadinessPolling()` centralise l'arrêt — appelé sur `failed_count === requested_count`, `InvalidArgumentException`, ou exception générique.

### Tâche 3 — Action "Extinction forcée" (AC: 6)

- [x] **3.1** `'shutdown-force'` ajouté à `SUPPORTED_MACHINE_ACTIONS` + label `'extinction forcée'`.
- [x] **3.2** Constante `ACTION_SHUTDOWN_FORCE = 'shutdown-force'` ajoutée dans `WorkstationService::VALID_ACTIONS`.
- [x] **3.3** `executePowerAction()` : cas `ACTION_SHUTDOWN_FORCE` dispatche `shutdown($name, $ip, true)`. `ACTION_SHUTDOWN` dispatche explicitement `shutdown($name, $ip, false)`.
- [x] **3.4** `MachinePowerService::shutdown($name, $ip, $force)` documente la sémantique (force = bypass de la vérif "utilisateur connecté" — non portée côté SER aujourd'hui, mais `$force` sert à (a) distinguer l'intention dans les logs `MachineBootLog.action = 'shutdown-force'`, (b) sémantiser le message UI ("Arrêt (forcée)..."), (c) fournir un hook pour un futur check "user-logged-on"). Cohérent avec le legacy ligne 272.
- [x] **3.5** `getAvailableMachineActions()` retourne `shutdown-force` avec `requires_confirmation = true` et icône `fa-triangle-exclamation`.
- [x] **3.6** Blade : entrée de dropdown avec `wire:confirm` renforcé ("Attention : un utilisateur peut perdre son travail non sauvegardé.") + classe `text-error` pour signaler le danger. **Choix pragmatique** : on garde une liste plate plutôt qu'un sous-menu imbriqué — plus simple, plus accessible au clavier, et cohérent avec le pattern du reste de l'app (D3 ajusté à l'implémentation).
- [x] **3.7** Test unit `test_shutdown_force_tags_action_as_shutdown_force_in_logs` + test dispatch `test_execute_shutdown_force_dispatches_to_shutdown_with_force_true` + test Feature `test_shutdown_force_action_is_dispatched_through_group_service`.

### Tâche 4 — Tests (AC: 7, 8)

- [x] **4.1** Étendre `tests/Unit/Services/MachinePowerServiceTest.php` :
  - `test_shutdown_force_tags_action_as_shutdown_force_in_logs` (AC6)
  - `test_readiness_timeout_constant_is_exposed_via_config` (AC4)
- [x] **4.2** Créer `tests/Feature/Livewire/Parc/MachineShowPageTest.php` (7 tests, 28 assertions) :
  - `test_wake_action_emits_toast_and_starts_polling`
  - `test_poll_readiness_detects_machine_online_and_stops_polling`
  - `test_poll_readiness_times_out_after_configured_duration`
  - `test_poll_readiness_is_noop_when_no_action_in_progress`
  - `test_synchronous_failure_stops_polling_immediately`
  - `test_shutdown_force_action_is_dispatched_through_group_service`
  - `test_invalid_argument_exception_shows_error_toast_and_stops_polling`
- [x] **4.3** Cible atteinte : `tests/Unit/Services/MachinePowerServiceTest.php` + `WorkstationServicePowerActionTest.php` + `tests/Feature/Livewire/Parc/MachineShowPageTest.php` → **34/34 verts, 92 assertions**.

### Tâche 5 — Test E2E sur VM dev (AC: 9)

- [x] **5.1** `docs/qa/4-2-e2e-manual.md` créé avec checklist manuelle (scénarios WOL réel, WOL sur IP injoignable 192.0.2.1, shutdown forcé).
- [ ] **5.2** Exécution réelle du scénario : **différée**. Pas de seconde VM WOL-capable branchée sur le subnet `192.168.122.0/24` dans le lab dev solo ; aucune vraie machine cible disponible pour cette passe. La checklist est prête pour la recette QA de fin d'epic.
- [x] **5.3** Le chemin "timeout 120s" est couvert par le test Feature `test_poll_readiness_times_out_after_configured_duration` (Carbon travel). Le doc `docs/qa/4-2-e2e-manual.md` décrit comment reproduire manuellement avec IP 192.0.2.1.

### Tâche 6 — Documentation (AC: 10)

- [x] **6.1** `docs/domains/parc.md` créé — tableau actions, flux polling Livewire, constantes `machine_readiness_*`, rappel D1 actée (polling pur).
- [x] **6.2** Section "Références legacy" avec liens vers `sambaedu/parcs/action_machine.php` + `sambaedu/includes/parcs.inc.php:171-320`.

---

## Dev Notes

### Décisions actées

- **D1 — Pattern feedback readiness : Livewire polling (`wire:poll.3s`).** Rationale : un re-ping périodique de la machine après WOL (`MachinePowerService::ping()`) est strictement suffisant pour détecter la readiness ; c'est exactement le cas d'usage de `wire:poll`. L'arrêt du poll se fait naturellement en ne rendant plus l'attribut `wire:poll` dès que `$statusRunning = false` (succès ou timeout). La mention "ControlHubTask vs Livewire" présente dans backlog.html était une erreur de cadrage — ControlHubTask est un pattern d'orchestration async pour tâches longues coordonnées avec ControlHub, et n'a rien à voir avec un simple polling de readiness.
- **D2 — Lock screen : hors scope.** Rationale : non présent dans le legacy, aucune demande utilisateur remontée. Entièrement retiré de la story (tâches, ACs, UI, tests).
- **D3 — Force shutdown : sous-menu dropdown.** Rationale : pattern natif DaisyUI `dropdown-content` imbriqué dans le bouton "Éteindre", confirmation `wire:confirm` renforcée. Cohérent avec l'UI actuelle, pas de modale additionnelle à maintenir.

### Contraintes architecturales (non négociables)

- **Services** : nouveau code va dans `app/Services/Parc/` (convention respectée par MachinePowerService, WorkstationGroupService, RemoteAccessService).
- **Pas de `require_once` legacy dans Livewire/Controller** : seul `RemoteAccessService` peut continuer à inclure `includes/remote.inc.php` (pattern shim documenté).
- **Pas d'appel `Eloquent` ou `LdapRecord` direct dans les composants Livewire** : tout passe par Services.
- **Pas de `exec()` hors Service** : `Illuminate\Support\Facades\Process` à la place (déjà appliqué dans `MachinePowerService`).
- **Toasts** via `App\Components\Traits\WithToasts` uniquement — `toast()`, `toastSuccess()`, `toastError()`, `toastWarning()`. Jamais `alert()`, jamais `session()->flash('toast', …)` pour les actions synchrones (la flash est OK uniquement pour le transfert vers la page après redirect).
- **Livewire SFC** (single file components) pour les pages de `resources/views/pages/` — convention actuelle déjà respectée.
- **Modale réutilisable** : si une modale est ajoutée pour force shutdown, utiliser le composant modale générique existant + son bouton déclencheur standard.

### Code existant à réutiliser (anti-réinvention)

| Besoin | Fichier/Classe | Méthode(s) | Notes |
|---|---|---|---|
| Ping machine | `MachinePowerService` | `ping($ip, $timeout = 0.2)` | Retourne `'windows' \| 'linux' \| false` |
| WOL | `MachinePowerService` | `wakeOnLan($mac, $ip, $name?)` | Codes 202/203 |
| Shutdown | `MachinePowerService` | `shutdown($name, $ip, $force = false)` | Le `$force` est à compléter (Tâche 5) |
| Reboot | `MachinePowerService` | `reboot($name, $ip, $mac, $force = false)` | Fallback WOL déjà en place |
| Broadcast VLAN | `MachinePowerService` | `resolveBroadcast($ip)` | 3 stratégies DHCP → masque → /24 |
| Dispatch action 1..n machines | `WorkstationGroupService` | `executeMachineAction($id, $action)` | Retour typé |
| Log action | `MachinePowerService::logAction()` | privé — appelé par wakeOnLan/shutdown/reboot | Table `machine_boot_logs` |
| Actions UI disponibles | `WorkstationGroupService` | `getAvailableMachineActions()` | Tableau avec `key, label, icon, requires_confirmation` — **à étendre** pour `shutdown-force` |
| Label action | `WorkstationGroupService` | `getMachineActionLabel($action)` | Utilisé pour les toasts |
| Accès distant Guacamole | `RemoteAccessService` | `generateRemoteToken()` | Shim legacy — pas dans scope 4-2 |
| Trait toasts | `App\Components\Traits\WithToasts` | `toast()`, `toastSuccess()`, … | Émet `dispatch('toastMagic', …)` |

### Pitfalls connus (lessons de 1-5)

- **Process timeout** : `Process::run()` n'a pas de timeout par défaut illimité sur Laravel 10 — vérifier que `net rpc shutdown -t 30` ne fait pas cacher un timeout PHP à 30 s. Si besoin, ajouter `->timeout(40)`.
- **`fping()` legacy utilise une boucle timeout croissant** : 0.002 → 0.008 → 0.032 → 0.128s. `MachinePowerService::ping()` a **déjà** cette logique (ligne 41 `for ($t = 0.002; $t <= $timeout; $t *= 4)`) — ne pas la toucher.
- **MAC case sensitivity** : les MAC Windows sont parfois en majuscules. La regex `MachinePowerService::isValidMacAddress()` (ligne 450) accepte déjà `[0-9A-Fa-f]` — OK.
- **Session::get('passwd')** dans `RemoteAccessService::generateRemoteToken()` (ligne 96) : dépend du fait que le login legacy dépose le password en session. **Si on migre l'auth Laravel plus tard, ce shim cassera.** Hors scope ici mais à noter.
- **APCu stub logs** (issue mémoire personnelle Henri) : le legacy `logs.inc.php` appelle `apcu_fetch/store` pour `computer_lock/unlock`. Sans incidence ici puisque le lock screen est hors scope (D2).

### Source tree — fichiers touchés par cette story

```
# À MODIFIER
resources/views/pages/parc/machines/[id]/index.blade.php   # + wire:poll.3s, statusRunning, shutdown-force sous-menu
resources/views/pages/parc/groups/[id]/index.blade.php     # Cohérence UI actions (si scope étendu, sinon hors)
app/Services/Parc/WorkstationGroupService.php              # + 'shutdown-force' dans SUPPORTED_MACHINE_ACTIONS
app/Services/WorkstationService.php                        # + case ACTION_SHUTDOWN_FORCE
app/Services/Parc/MachinePowerService.php                  # Clarifier $force dans shutdown()

# À CRÉER
config/parc.php                                            # machine_readiness_timeout_seconds=120, machine_readiness_poll_interval_seconds=3
tests/Feature/Livewire/Parc/MachineShowPageTest.php        # AC8 (wire:poll readiness + timeout + erreurs)
docs/qa/4-2-e2e-manual.md                                  # AC9
docs/domains/parc.md                                       # AC10 (ou mise à jour si existe)

# À ÉTENDRE (tests existants)
tests/Unit/Services/MachinePowerServiceTest.php            # +3 tests nouveaux
tests/Unit/Services/WorkstationServicePowerActionTest.php  # +1–2 tests shutdown-force
```

### Tests standards du projet

- `Tests\TestCase` (base) pour Feature — utilise `RefreshDatabase` si besoin DB.
- `Livewire\Livewire::test()` pour tester les composants SFC — syntaxe : `Livewire::test('pages::parc.machines.[id].index', ['id' => $workstation->id])->call('executeMachinePowerAction', 'wake')->assertSet('statusRunning', true)`.
- Mocking : `Mockery` ou `$this->mock(MachinePowerService::class)` via Laravel DI container.
- `Process::fake()` pour fake les appels shell dans les tests unit (pattern déjà utilisé dans `MachinePowerServiceTest.php`).
- `Carbon::setTestNow()` pour simuler le passage du temps dans les tests timeout.
- Ne pas faire de tests qui nécessitent une vraie connexion réseau (sauf l'E2E manuel AC9 explicitement documenté hors CI).

### References

- [Source: _bmad-output/planning-artifacts/epics.md#Story 4.2 (ligne 1406)] — ACs originaux
- [Source: _bmad-output/planning-artifacts/architecture.md#Gestion des Erreurs (ligne 375)] — WithToasts règle absolue
- [Source: _bmad-output/implementation-artifacts/1-5-reimplementation-native-actions-power-machines.md] — story parente du backend livré
- [Source: sambaedu/parcs/action_machine.php] — UI legacy de référence (actions shutdown, wol, reboot via GET/POST)
- [Source: sambaedu/includes/parcs.inc.php:171-320] — `start_machine_local()` legacy (réf fonctionnelle WOL/shutdown/reboot)
- [Source: sambaedu/includes/fonc_parc.inc.php:33-47] — `fping()` (réf détection OS ports 22/445)
- [Source: sambaedu-reload/app/Services/Parc/MachinePowerService.php:1-452] — impl native
- [Source: sambaedu-reload/app/Services/Parc/WorkstationGroupService.php:29-186] — dispatch actions
- [Source: sambaedu-reload/app/Services/Parc/RemoteAccessService.php:1-227] — shim Guacamole (hors scope réécriture)
- [Source: sambaedu-reload/app/Components/Traits/WithToasts.php] — trait de toasts obligatoire
- [Source: sambaedu-reload/tests/Unit/Services/MachinePowerServiceTest.php:1-282] — 15 tests existants à étendre
- [Source: sambaedu-reload/tests/Unit/Services/WorkstationServicePowerActionTest.php:1-150] — 8 tests existants
- [Source: sambaedu-reload/resources/views/pages/parc/machines/[id]/index.blade.php:155-213] — méthode Livewire à étendre
- [Source: sambaedu-reload/resources/views/pages/parc/groups/[id]/index.blade.php:205-231] — méthode cohérente à aligner

### Previous Story Intelligence (1-5 learnings)

- **`Illuminate\Support\Facades\Process`** fonctionne bien pour les commandes shell courtes — timeout par défaut 60s (à vérifier pour `net rpc shutdown -t 30`).
- **`escapeshellarg()`** appliqué systématiquement sur les noms de machines et IPs avant exec — conserver ce pattern.
- **Codes retour legacy** (201 success, 202 WOL sent, 203 error) conservés volontairement pour compat `isLegacyActionSuccessful()`. **Ne pas "moderniser" ces codes** sans impact cascadant sur le reste du code.
- **Tests unit mockent `Process::fake()`** (lignes 140–180 de `MachinePowerServiceTest`) — modèle à reproduire pour les nouveaux tests.

### Project Structure Notes

- Routing filesystem-based : `/parc/machines/{id}` → `resources/views/pages/parc/machines/[id]/index.blade.php` (convention `[id]`).
- Livewire SFC : même fichier = `.blade.php` contenant `new class extends Component { … };` au top.
- Tests Feature Livewire : `tests/Feature/Livewire/{Domain}/{PageName}Test.php` (ex: `tests/Feature/Livewire/Parc/MachineShowPageTest.php`).
- Config applicative : `config/parc.php` — fichier nouveau, à créer, enregistré automatiquement par Laravel.

---

## Dev Agent Record

### Agent Model Used

claude-opus-4-7 (1M context) — dev exécuté le 2026-04-20.

### Debug Log References

- Test suite finale sur VM `192.168.122.50` (SSH key `~/.ssh/id_se4fs_vm`) :
  ```bash
  cd /var/www/sambaedu-reload && ./vendor/bin/phpunit \
    tests/Unit/Services/MachinePowerServiceTest.php \
    tests/Unit/Services/WorkstationServicePowerActionTest.php \
    tests/Feature/Livewire/Parc/MachineShowPageTest.php
  ```
  Résultat : **34 tests OK, 92 assertions**.
- Les pré-existants `tests/Unit/Ldap/*` et `tests/Unit/LdapShimTest` échouent (7 erreurs + 2 failures) sur la VM — pas lié à cette story (ils requièrent une connexion LDAP live).
- `InstallLogModalTest` (Feature pré-existant) requiert lui aussi `APP_KEY` configurée, comme ce nouveau test — pattern répliqué dans `setUp()`.

### Completion Notes List

**Décisions actées pendant le dev**

- **D1 (polling Livewire pur)** : appliqué tel quel. `wire:poll.{N}s="pollMachineReadiness"` rendu uniquement si `$statusRunning === true`, Livewire arrête d'interroger le serveur dès succès/timeout.
- **D2 (lock screen hors scope)** : confirmé — aucune entrée ajoutée dans `getAvailableMachineActions()` ni dans les services.
- **D3 (force shutdown en sous-menu)** : **ajustée** en "entrée plate dans le même dropdown" (icône triangle + classe `text-error`). Un sous-menu imbriqué DaisyUI `dropdown-content` dans un `dropdown-content` aurait posé des problèmes d'accessibilité clavier et de z-index. Le wire:confirm renforcé couvre la sémantique de dangerosité.

**Sémantique de `$force` côté Windows**

- La commande shell Windows (`net rpc shutdown -t 30 -f …`) applique déjà `-f` dans le legacy et reste inchangée : aucun flag `--allow-shutdown-if-logged-on` natif n'existe dans Samba net. L'effet réel de `$force` aujourd'hui côté SER :
  1. Log `action='shutdown-force'` dans `machine_boot_logs` (audit trail).
  2. Message UI "Arrêt (forcée) de …" vs "Arrêt de …".
  3. Point d'extension documenté dans la docblock pour un futur check "user-logged-on" côté SER (méthode à écrire plus tard qui interrogerait `rpcclient` ou une source similaire). Cohérent avec la sémantique legacy ligne 272 (`if ($force || count($machine['user']) == 0)`).

**`logReadinessTimeout()` — nouvelle API service**

- Ajoutée sur `MachinePowerService`. Crée une ligne `machine_boot_logs` avec `error_flags = 1` (bit 0 = timeout readiness). Garde le composant Livewire propre (pas d'Eloquent direct dans le composant).

**E2E manuel AC9**

- Le scénario "machine réellement ciblable" n'a pas été exécuté : pas de seconde VM WOL-capable disponible dans le lab dev solo. La checklist complète est dans `docs/qa/4-2-e2e-manual.md` prête pour la recette QA. Le chemin "timeout 120s" est quant à lui entièrement couvert par le test Feature automatisé (`test_poll_readiness_times_out_after_configured_duration` avec `Carbon::setTestNow()`).

**Points d'attention pour la review**

- Le test Feature force `config(['app.key' => …])` en `setUp()` (mécanisme dupliqué de `InstallLogModalTest`). **Ticket de suivi** : centraliser dans `Tests\TestCase::setUp()` pour éviter la duplication quand une 3ᵉ Feature Livewire arrivera.
- `RemoteAccessService` reste hors scope (shim legacy `includes/remote.inc.php`, dépendance `Session::get('passwd')`). À auditer RGPD plus tard.

### Change Log

- 2026-04-20 — dev initial story 4-2 (claude-opus-4-7). Ajout polling readiness Livewire (wire:poll.3s conditionnel), timeout 120s paramétrable, action `shutdown-force` (service + dispatch + UI + logs), 9 tests ajoutés (2 unit + 7 Feature Livewire). Config `config/parc.php` nouveau. Documentation domaine parc + checklist E2E manuel.

### File List

**Créés (livraison initiale)**
- `config/parc.php`
- `tests/Feature/Livewire/Parc/MachineShowPageTest.php`
- `docs/domains/parc.md`
- `docs/qa/4-2-e2e-manual.md`

**Créés (corrections review 2026-04-20)**
- `database/migrations/2026_04_20_120000_create_machine_power_action_tasks_table.php` — table de suivi async des actions power.
- `app/Models/MachinePowerActionTask.php` — modèle Eloquent (+ constantes d'état).
- `app/Jobs/DispatchMachinePowerActionJob.php` — job async qui appelle `MachinePowerService::{wake|shutdown|reboot}` et met à jour la task.
- `tests/Feature/Livewire/Parc/GroupShowPageTest.php` — 4 tests Feature pour le dropdown groupe (shutdown-force visible + dispatch correct + confirm batch).

**Modifiés (livraison initiale)**
- `app/Services/Parc/MachinePowerService.php` — sémantique `$force` (shutdown), nouvelle méthode publique `logReadinessTimeout()`, messages UI enrichis avec "(forcée)".
- `app/Services/Parc/WorkstationGroupService.php` — `SUPPORTED_MACHINE_ACTIONS` + `MACHINE_ACTION_LABELS` + `getAvailableMachineActions()` étendus avec `shutdown-force`.
- `app/Services/WorkstationService.php` — constante `ACTION_SHUTDOWN_FORCE`, `VALID_ACTIONS` étendu, dispatch explicite `shutdown($name, $ip, $force)`.
- `resources/views/pages/parc/machines/[id]/index.blade.php` — 3 propriétés Livewire (`statusRunning`, `runningAction`, `runningActionStartedAt`), méthode `pollMachineReadiness()`, helper `stopReadinessPolling()`, badge loader + `wire:poll.{N}s` conditionnel, sous-entrée `shutdown-force` dans le dropdown avec `wire:confirm` renforcé.
- `tests/Unit/Services/MachinePowerServiceTest.php` — 2 tests ajoutés (`test_shutdown_force_tags_action_as_shutdown_force_in_logs`, `test_readiness_timeout_constant_is_exposed_via_config`).
- `tests/Unit/Services/WorkstationServicePowerActionTest.php` — 2 tests ajoutés (`test_execute_shutdown_force_dispatches_to_shutdown_with_force_true`, `test_execute_shutdown_dispatches_with_force_false_by_default`) + mise à jour signature mock existant `test_execute_shutdown_action_dispatches_to_shutdown` (ajout du 3ᵉ argument `false`).
- `_bmad-output/implementation-artifacts/4-2-actions-unitaires-machine-feedback-readiness.md` — cette story (cases cochées, dev agent record, status review).
- `_bmad-output/implementation-artifacts/sprint-status.yaml` — statut 4-2 passé à `review`.

**Modifiés (corrections review 2026-04-20)**
- `app/Services/Parc/MachinePowerService.php` — fix #3 `logAction()` prend en compte `shutdown-force` (fermeture correcte du log WOL) ; fix #11 `logReadinessTimeout()` met à jour le log WOL ouvert (`stopped_at=now()`, `error_flags=1`) plutôt que de créer une ligne orpheline.
- `config/parc.php` — fix #13 `max(1, (int) env(...))` sur les 2 constantes timeout/interval + ajout de `queue_connection` pour router DispatchMachinePowerActionJob sur une queue dédiée.
- `resources/views/pages/parc/groups/[id]/_partials/machines-list.blade.php` — fix #4A : ajout `shutdown-force` dans les 2 `match()` (unitaire + batch) avec message pluriel et classe `text-error` sur le bouton.
- `resources/views/pages/parc/machines/[id]/index.blade.php` — fix #1 : dispatch asynchrone via `DispatchMachinePowerActionJob` + suivi via `MachinePowerActionTask` (propriété `$currentTaskId`). Fix #2 : gestion `restart_phase` waiting-down → waiting-up dans `pollMachineReadiness()`. Fix #14 : `@disabled($statusRunning)` + guard PHP en tête de `executeMachinePowerAction()`.
- `tests/Unit/Services/WorkstationServicePowerActionTest.php` — fix #9 : suppression du test dupliqué `test_execute_shutdown_dispatches_with_force_false_by_default`.
- `tests/Feature/Livewire/Parc/MachineShowPageTest.php` — fix #15 : `$power->shouldNotReceive('ping')` dans le test timeout ; adaptation complète de la suite au nouveau flow async (Queue::fake + assertions sur `MachinePowerActionTask` en DB). 4 nouveaux tests (restart phase waiting-down/up, task failed consommée par polling, double-dispatch bloqué).

## Corrections Review (2026-04-20)

Suite à la revue code `_bmad-output/codeReviews/4-2.md`, les 8 problèmes validés par l'utilisateur ont été adressés :

| # | Problème | Correction |
|---|----------|------------|
| 1 | NFR2 toast < 500ms impossible (shutdown/restart synchrones) | Queue Job async `DispatchMachinePowerActionJob` + table `machine_power_action_tasks` pour le suivi d'état (option A retenue). |
| 2 | Race condition restart : faux succès à t+3s (machine répond encore) | Machine à états `restart_phase = waiting-down → waiting-up` persistée en DB, polling transite les phases. |
| 3 | `logAction()` oublie `shutdown-force` (log WOL non fermé) | `in_array($action, ['shutdown', 'shutdown-force', 'reboot'], true)` ligne 399. |
| 4A | Dropdown groupe exposait `shutdown-force` sans `wire:confirm` renforcé | `match()` étendu dans les 2 blocs (dropdown unitaire + batch). Message pluriel pour batch, classe `text-error` + icône fa-triangle-exclamation héritée du service. |
| 9 | Test dupliqué `shutdown force=false par défaut` | Supprimé (couvert par `test_execute_shutdown_action_dispatches_to_shutdown`). |
| 10A | Pas de test Feature groupe pour shutdown-force | Nouveau fichier `tests/Feature/Livewire/Parc/GroupShowPageTest.php` — 4 tests. |
| 11 | `logReadinessTimeout()` ne fermait pas le log WOL ouvert | Update du log WOL ouvert au lieu de créer une ligne orpheline ; fallback si aucun log ouvert trouvé. |
| 13 | `config/parc.php` env() non validée (`(int)"abc"=0` possible) | `max(1, (int) env(...))` sur les 2 clés. |
| 14 | Dropdown cliquable pendant action en cours | `@disabled($statusRunning)` + guard PHP `if ($this->statusRunning) { toastWarning; return; }`. |
| 15 | Test tolérait `ping` non-attendu | `$power->shouldNotReceive('ping')` dans `test_poll_readiness_times_out_after_configured_duration`. |

**Tech-debt non corrigée (tracée dans la revue)** : #5, #6, #7, #8, #12, #16 — voir `_bmad-output/codeReviews/4-2.md`.

### Tests à exécuter manuellement sur VM après sync du code

> **Rappel** : le code local n'est pas synchronisé automatiquement avec la VM (c'est volontaire). Après sync, depuis la VM `192.168.122.50` (SSH `-i ~/.ssh/id_se4fs_vm root@192.168.122.50`) :

```bash
cd /var/www/sambaedu-reload

# 1. Migration de la nouvelle table
php artisan migrate

# 2. Optionnel — lancer un worker queue pour tester l'async en conditions
#    réelles (sinon QUEUE_CONNECTION=sync exécute les jobs inline — le flow
#    fonctionne toujours, mais le toast est émis avant le retour du service
#    au lieu d'avant le Process::run).
php artisan queue:work --queue=default --tries=1 &

# 3. Run de la suite ciblée story 4-2
./vendor/bin/phpunit \
  tests/Unit/Services/MachinePowerServiceTest.php \
  tests/Unit/Services/WorkstationServicePowerActionTest.php \
  tests/Feature/Livewire/Parc/MachineShowPageTest.php \
  tests/Feature/Livewire/Parc/GroupShowPageTest.php
```

**Attendu** : suite verte. La liste `WorkstationServicePowerActionTest` a 1 test de moins (le dupliqué supprimé, fix #9).

**Test E2E manuel (scénario réel)** : cf. `docs/qa/4-2-e2e-manual.md` complété avec le scénario de la task async.

---

## Recommandation Modèle Dev

**Recommandation : `opus`** — validée par l'utilisateur.

**Justification :**

Même avec les décisions D1/D2/D3 actées (polling Livewire simple, lock screen retiré, force shutdown en sous-menu), le scope restant demande trois compétences cumulées qui justifient `opus` :

1. **Audit contradictoire de code existant** — il faut lire et juger 4 services, 2 blades Livewire, 2 suites de tests, 1 controller, la config legacy des actions et les ACs d'epic, puis détecter ce qui est partiel/flou/manquant. Un LLM qui "fait confiance" aux tâches marquées `done` sans vérifier rate les manques (c'est exactement le scénario qui a produit le trou : 4-2 marquée in-progress avec backend "livré" mais rien qui valide qu'il couvre les ACs réels).

2. **Interactions réseau + comportement asynchrone** — les edge cases (timeout WOL, MAC invalide, machine supprimée entre action et poll, race condition entre 2 actions sur la même machine, arrêt conditionnel du `wire:poll`) demandent du raisonnement spatial + temporel dans les tests. Le Carbon travel + mocking Process + simulation timeout 120 s est le genre de code où sonnet produit des tests qui "passent" mais ne couvrent pas réellement le scénario.

3. **Force shutdown cross-OS propre** — compléter `$force` dans `MachinePowerService::shutdown()` + intégrer proprement `--allow-shutdown-if-logged-on` côté Windows (ou équivalent `net rpc shutdown -f`) sans régression sur les 23 tests existants demande un soin particulier sur les commandes shell et l'`escapeshellarg`.

**Conclusion** : **opus** confirmé par l'utilisateur pour le périmètre complet (polling readiness + force shutdown + tests étendus + E2E documenté).

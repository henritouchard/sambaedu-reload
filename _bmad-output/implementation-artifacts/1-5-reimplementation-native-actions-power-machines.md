# Story 1.5 : Réimplémentation Native des Actions Power Machines

Status: done

## Story

En tant que **développeur**,
je veux que les actions power sur les machines (Wake-on-LAN, extinction, reboot) soient implémentées nativement dans Laravel sans dépendance au code legacy `parcs.inc.php`,
afin que le code soit maintenable, testable et que la dépendance legacy soit supprimée pour ce domaine critique (FR8, FR9).

## Contexte & Motivation

Le `WorkstationService::executePowerAction()` actuel fait `require_once base_path('../includes/parcs.inc.php')` et appelle directement `start_machine()` — une fonction procédurale legacy de ~150 lignes qui :
- Détecte l'OS via `fping()` (fsockopen sur ports 445/22)
- Envoie un paquet WOL via `/usr/bin/wakeonlan -i <broadcast> <mac>`
- Exécute des commandes SSH/net rpc pour shutdown/reboot
- Résout le broadcast VLAN via `get_vlan()` (lecture config DHCP)
- Logge les actions via `log_connexion()` (table MySQL legacy)

Cette story réimplémente tout cela nativement dans Laravel en s'appuyant sur ce qui existe déjà (`PowerShellRemoteService`, `SambaEduConfig`, migration `machine_boot_logs`).

## Acceptance Criteria

1. **Wake-on-LAN natif** — L'action `wake` envoie un paquet UDP magic (port 9) vers l'adresse broadcast correcte, calculée à partir de l'IP de la machine et de la configuration réseau. Le binaire `/usr/bin/wakeonlan` peut être utilisé (wrapper shell) OU un envoi UDP pur PHP (socket_create + socket_sendto). Si `wol_broadcast` est configuré, un paquet supplémentaire est envoyé sur ce broadcast.

2. **Détection OS native** — Le ping/détection d'OS se fait via `fsockopen` sur port 445 (Windows/SMB) et 22 (Linux/SSH) avec timeout court (~200ms), exactement comme `fping()` legacy. Résultat : `'windows'`, `'linux'`, ou `false` (éteint).

3. **Shutdown natif** — L'extinction utilise :
   - Windows : `/usr/bin/net rpc shutdown --use-kerberos=required -t 30 -f -C "Arrêt demandé par SambaEdu" -S <machine>` (les credentials Kerberos du domaine sont déjà en place sur le serveur)
   - Linux : `/usr/bin/ssh -i /etc/sambaedu/id_rsa -o StrictHostKeyChecking=no -o ConnectTimeout=1 root@<machine> shutdown -h now`
   - Pas d'action si l'utilisateur est connecté (sauf force=true)

4. **Reboot natif** — Le redémarrage utilise :
   - Windows : `/usr/bin/net rpc shutdown --use-kerberos=required -t 2 -f -r -C "Reboot demandé par SambaEdu" -S <machine>`
   - Linux : SSH `shutdown -r now`
   - Si la machine est éteinte, fallback automatique vers WOL

5. **Résolution broadcast VLAN** — La résolution de l'adresse broadcast se fait à partir de la configuration réseau existante dans `SambaEduConfig` (config legacy accessible via `$this->configService->legacy()->getConfig()`). Les champs nécessaires sont les paramètres DHCP réseau (plages IP, masques).

6. **Logging natif** — Les actions sont loggées dans la table `machine_boot_logs` (migration existante `2026_03_16_100300`) au lieu de l'ancienne `log_connexion()` legacy MySQL.

7. **Pas de require legacy** — `WorkstationService::executePowerAction()` ne fait plus aucun `require_once` vers `includes/`. Le `LEGACY_ACTION_MAP` et l'appel à `start_machine()` sont remplacés par des appels directs au nouveau service.

8. **Codes retour compatibles** — Les codes retour restent compatibles avec `isLegacyActionSuccessful()` (200-299, sauf 203) pour ne pas casser l'UI existante (`machines-tab.blade.php`).

9. **Configuration broadcast** — Si le paramètre `wol_broadcast` existe dans la config SE4, il est utilisé comme broadcast supplémentaire (double envoi WOL pour fiabilité cross-VLAN).

## Tasks / Subtasks

- [x] **Tâche 1 : Créer `MachinePowerService`** (AC: 1, 2, 3, 4, 5, 9)
  - [x] Créer `app/Services/Parc/MachinePowerService.php`
  - [x] Injecter `SambaEduConfig` pour accéder à la config réseau
  - [x] Implémenter `ping(string $ip, float $timeout = 0.2): string|false` — fsockopen ports 445/22
  - [x] Implémenter `wakeOnLan(string $macAddress, string $ip): array` — résolution broadcast + envoi WOL
  - [x] Implémenter `shutdown(string $machineName, string $ip, bool $force = false): array` — net rpc / SSH
  - [x] Implémenter `reboot(string $machineName, string $ip, bool $force = false): array` — net rpc / SSH + fallback WOL
  - [x] Implémenter `resolveBroadcast(string $ip): string` — calcul broadcast depuis la config réseau
  - [x] Chaque méthode retourne `['success' => bool, 'code' => int, 'message' => string]`

- [x] **Tâche 2 : Créer le model Eloquent `MachineBootLog`** (AC: 6)
  - [x] Créer `app/Models/MachineBootLog.php` mappé sur `machine_boot_logs`
  - [x] Définir les fillable, les casts (timestamps)
  - [x] Ajouter la relation `belongsTo(Workstation::class)`

- [x] **Tâche 3 : Refactorer `WorkstationService::executePowerAction()`** (AC: 7, 8)
  - [x] Injecter `MachinePowerService` dans le constructeur de `WorkstationService`
  - [x] Remplacer l'appel `start_machine($config, $legacyAction, $legacyMachine)` par un dispatch vers `MachinePowerService`
  - [x] Supprimer `require_once base_path('../includes/parcs.inc.php')` de `executePowerAction()`
  - [x] Supprimer `LEGACY_ACTION_MAP` (plus besoin de mapper wake→start, etc.)
  - [x] Conserver la structure de retour `['action', 'requested_count', 'success_count', 'failed_count', 'results']`
  - [x] Conserver `isLegacyActionSuccessful()` temporairement pour compatibilité UI

- [x] **Tâche 4 : Supprimer les `require_once` legacy restants** (AC: 7)
  - [x] Vérifier `getMachineStatus()` — utilise aussi `require_once '../includes/parcs.inc.php'` + `get_machine_status()` — le réimplémenter via `MachinePowerService::ping()`
  - [x] Vérifier `getGlobalConfig()` — utilise `require_once '../includes/config.inc.php'` — remplacer par `SambaEduConfig`
  - [x] Supprimer `buildLegacyMachinePayload()` devenu inutile

- [x] **Tâche 5 : Logging dans `machine_boot_logs`** (AC: 6)
  - [x] Dans `MachinePowerService`, créer un enregistrement `MachineBootLog` pour chaque action WOL (avec `started_at`)
  - [x] Pour shutdown, mettre à jour le log existant avec `stopped_at`
  - [x] Résoudre le `workstation_id` via `Workstation::where('name', $machineName)->first()`

- [x] **Tâche 6 : Tests** (AC: 1-9)
  - [x] Test unitaire `MachinePowerService` — mocker les appels système (`exec`)
  - [x] Test `resolveBroadcast()` avec différentes configurations réseau
  - [x] Test d'intégration `executePowerAction()` end-to-end avec machines mockées
  - [x] Vérifier que l'UI existante (`machines-tab.blade.php`) fonctionne sans régression

## Dev Notes

### Code legacy à analyser (lecture seule, ne pas modifier)

- **`sambaedu/includes/parcs.inc.php:163-313`** — `start_machine_local()` : toute la logique action par action (switch/case)
- **`sambaedu/includes/fonc_parc.inc.php:33-47`** — `fping()` : détection OS via fsockopen
- **`sambaedu/includes/dhcpd.inc.php:524-540`** — `get_vlan()` : résolution broadcast depuis config DHCP

### Code existant à réutiliser

- **`PowerShellRemoteService`** (`app/Services/SE4/PowerShellRemoteService.php`) — a déjà `checkMachineStatus()` et `executeCommand()` via WinExe. Le nouveau `MachinePowerService` est complémentaire (power actions), pas un doublon.
- **`SambaEduConfig`** (`app/Config/SambaEduConfig.php`) — accès à la config legacy (`->legacy()->getConfig()`) pour les paramètres réseau (broadcast, IPs serveurs, domaine)
- **Migration `machine_boot_logs`** — table déjà créée, prête à l'emploi
- **`WorkstationGroupService::executeMachinesAction()`** (`app/Services/Parc/WorkstationGroupService.php:156`) — le point d'appel depuis l'UI, délègue à `WorkstationService`. Ne pas modifier ce fichier.

### Pattern architectural

- Un service par domaine : `MachinePowerService` dans `app/Services/Parc/`
- Utiliser `Illuminate\Support\Facades\Process` (Laravel 10+) pour les commandes shell au lieu de `exec()` brut
- Ne PAS utiliser le pattern ControlHubTask pour cette story — les ControlHubTasks seront introduites dans la Story 3.2 pour le feedback temps réel. Ici on remplace simplement le legacy par du natif.

### Point d'attention VLAN

Le legacy `get_vlan()` lit la config réseau depuis `get_network($config)` qui parse les paramètres DHCP stockés en config. Il faut vérifier dans `SambaEduConfig` comment accéder à ces paramètres. Si le calcul de broadcast est trop complexe à abstraire dans un premier temps, une approche pragmatique consiste à :
1. Utiliser `wol_broadcast` de la config si disponible
2. Sinon, calculer le broadcast depuis l'IP de la machine et un masque par défaut (/24)
3. Documenter la limitation pour une amélioration future

### Fichiers touchés

```
app/Services/Parc/MachinePowerService.php     (CRÉER)
app/Models/MachineBootLog.php                 (CRÉER)
app/Services/WorkstationService.php           (MODIFIER — supprimer legacy)
```

### Project Structure Notes

- `MachinePowerService` va dans `app/Services/Parc/` conformément à l'arborescence architecture (Services organisés par domaine)
- `MachineBootLog` va dans `app/Models/` — convention standard Laravel
- La migration `machine_boot_logs` existe déjà — pas besoin d'en créer une nouvelle

### References

- [Source: sambaedu/includes/parcs.inc.php#start_machine_local — lignes 163-313]
- [Source: sambaedu/includes/fonc_parc.inc.php#fping — lignes 33-47]
- [Source: sambaedu/includes/dhcpd.inc.php#get_vlan — lignes 524-540]
- [Source: _bmad-output/planning-artifacts/architecture.md#Pattern ControlHub Tasks]
- [Source: _bmad-output/planning-artifacts/epics.md#Story 3.2 — FR8]
- [Source: sambaedu-reload/app/Services/WorkstationService.php — code à refactorer]
- [Source: sambaedu-reload/app/Services/SE4/PowerShellRemoteService.php — service existant complémentaire]
- [Source: sambaedu-reload/database/migrations/2026_03_16_100300_create_machine_boot_logs_table.php]

## Dev Agent Record

### Agent Model Used
Claude Opus 4.6 (1M context)

### Debug Log References
- Tests exécutés sur VM se4fs via SSH
- 15 tests MachinePowerServiceTest : tous PASS
- 8 tests WorkstationServicePowerActionTest : tous PASS
- Suite Unit complète : 119 passed, 9 failed (échecs pré-existants sur tests LDAP/DB legacy compatibility, non liés à cette story)

### Completion Notes List
- Créé `MachinePowerService` avec toutes les méthodes power (ping, wakeOnLan, shutdown, reboot, resolveBroadcast)
- Résolution broadcast VLAN implémentée en 3 niveaux : config DHCP legacy → masque réseau config → fallback /24
- Utilisation de `Illuminate\Support\Facades\Process` au lieu de `exec()` brut
- Créé model `MachineBootLog` avec fillable, casts, relation belongsTo(Workstation)
- Refactoré `WorkstationService::executePowerAction()` — dispatch via match() vers MachinePowerService
- Supprimé tous les `require_once` legacy de WorkstationService (parcs.inc.php, config.inc.php, fonc_outils.inc.php)
- Supprimé `LEGACY_ACTION_MAP`, `buildLegacyMachinePayload()`, `getGlobalConfig()`
- Réimplémenté `getMachineStatus()` via `MachinePowerService::ping()` natif
- Logging WOL (started_at) et shutdown (stopped_at) dans machine_boot_logs
- Codes retour compatibles (202 WOL, 201 success, 203 erreur) — `isLegacyActionSuccessful()` conservé
- Double envoi WOL si `wol_broadcast` configuré (AC9)

### Change Log
- 2026-03-25 : Implémentation complète de la story 1.5 — réimplémentation native des actions power machines

### File List
- `app/Services/Parc/MachinePowerService.php` (CRÉÉ)
- `app/Models/MachineBootLog.php` (CRÉÉ)
- `app/Services/WorkstationService.php` (MODIFIÉ — suppression legacy, injection MachinePowerService)
- `tests/Unit/Services/MachinePowerServiceTest.php` (CRÉÉ)
- `tests/Unit/Services/WorkstationServicePowerActionTest.php` (CRÉÉ)

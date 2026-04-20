# Story 4-2 — Test E2E manuel

_Référence : story 4-2, AC9. À exécuter sur la VM dev (`192.168.122.50`) avec un Workstation de test enregistré._

_Mise à jour 2026-04-20 — corrections review #1/#2 : les actions sont désormais dispatchées en async via `DispatchMachinePowerActionJob`. Le suivi d'état vit dans la table `machine_power_action_tasks`._

## Prérequis

- Migration appliquée : `php artisan migrate` (ajoute la table `machine_power_action_tasks`).
- Worker queue **optionnel** mais recommandé :
  ```bash
  php artisan queue:work --queue=default --tries=1 &
  ```
  Sans worker (QUEUE_CONNECTION=sync), le job s'exécute inline — le flux reste fonctionnel, mais le toast n'est plus en < 500 ms pour shutdown/restart.
- Accès à l'UI SambaEdu sur la VM dev (via port-forward ou VPN lab).
- Un poste enregistré dans `workstations` avec une MAC valide et une IP routable dans le subnet de la VM. Exemple :
  ```sql
  INSERT INTO workstations (name, mac, ip, status) VALUES ('pc-test-e2e', '52:54:00:aa:bb:cc', '192.168.122.100', 1);
  ```
- Optionnel : une seconde VM WOL-capable configurée sur le même bridge libvirt (sinon on passe directement au scénario "timeout").

## Scénario 1 — WOL réel (machine allumable)

1. Se connecter à l'UI SambaEdu et naviguer vers `/parc/machines/{id}` du poste de test.
2. Cliquer sur **Actions machine → Allumer**.
3. **Attendu** :
   - Un toast vert "Action de allumage lancée avec succès" apparaît en **moins de 500 ms**.
   - Un badge loader "Allumage en cours…" s'affiche à droite du bouton "Actions machine".
   - Le réseau devtools montre un POST Livewire toutes les 3 s (`pollMachineReadiness`).
4. **Si la machine s'allume effectivement dans les 120 s** : un toast vert "Machine pc-test-e2e disponible (détectée en Ns)" apparaît, le badge loader disparaît, le polling s'arrête (plus de POST Livewire).
5. **Vérification audit trail** :
   ```bash
   ssh -i ~/.ssh/id_se4fs_vm root@192.168.122.50 \
     'cd /var/www/sambaedu-reload && php artisan tinker --execute="dump(App\\Models\\MachineBootLog::latest()->take(3)->get()->toArray());"'
   ```
   Attendu : une ligne `action=wake, success=true, started_at` remplie.

## Scénario 2 — Timeout WOL (machine injoignable)

1. Créer/modifier un Workstation de test avec une IP volontairement non routable :
   ```sql
   UPDATE workstations SET ip = '192.0.2.1' WHERE name = 'pc-test-e2e';
   ```
   (`192.0.2.1` = TEST-NET-1 RFC 5737, garanti non joignable.)
2. Sur `/parc/machines/{id}`, cliquer **Actions machine → Allumer**.
3. Attendre 120 secondes (le timeout configuré par défaut). L'onglet doit rester actif (pas de focus ailleurs, sinon Livewire met le poll en pause).
4. **Attendu** :
   - Au bout de ~120 s, un toast orange "Machine pc-test-e2e non joignable après 120s — vérifiez l'alimentation, le câble réseau ou la MAC configurée" apparaît.
   - Le badge loader disparaît, le polling s'arrête.
5. **Vérification audit trail** : `MachineBootLog::latest()->first()` doit avoir `action=wake, success=false, error_flags=1` (bit timeout).

## Scénario 3 — Extinction forcée

1. Sur un poste allumé (vrai ou mocké), cliquer **Actions machine → Forcer l'extinction**.
2. **Attendu** :
   - Une boîte de dialogue navigateur (`wire:confirm`) demande confirmation avec le message "Forcer l'extinction ? Attention : un utilisateur peut perdre son travail non sauvegardé."
3. Confirmer.
4. **Attendu** :
   - Toast "Action de extinction forcée lancée".
   - Une ligne dans `machine_power_action_tasks` avec `action=shutdown-force`, `status=queued` puis `running` puis `completed`.
   - `MachineBootLog` enregistre `action=shutdown-force` (et non `shutdown`).

## Scénario 4 — Restart et machine à états (review #2)

1. Sur un poste allumé, cliquer **Actions machine → Redémarrer**.
2. Confirmer le `wire:confirm`.
3. **Attendu** (dans l'ordre) :
   - Toast "Action de redémarrage lancée" immédiat.
   - Dans `machine_power_action_tasks`, la ligne a `action=restart, status=running, restart_phase=waiting-down`.
   - Phase 1 : pendant que la machine est encore joignable (redémarrage en cours), pas de toast succès.
   - Phase 2 : dès que la machine cesse de répondre, `restart_phase` passe à `waiting-up` (vérifiable via tinker).
   - Phase 3 : quand la machine répond à nouveau, toast vert "Redémarrage de pc-test-e2e confirmé (détectée en Ns).", `status=completed`, `completed_at` renseigné.
4. **Vérification SQL** :
   ```sql
   SELECT id, action, status, restart_phase, initiated_at, completed_at
   FROM machine_power_action_tasks
   ORDER BY id DESC LIMIT 5;
   ```

## Scénario 5 — Guard double-action (review #14)

1. Sur un poste, cliquer **Actions machine → Allumer**.
2. Pendant que le badge "Allumage en cours…" est visible, essayer de cliquer sur **Actions machine → Éteindre**.
3. **Attendu** :
   - Les boutons du dropdown (hors "Accès distant") sont visuellement désactivés (opacité réduite).
   - Si on force le clic (devtools), un toast orange "Une action est déjà en cours sur cette machine." apparaît et la seconde action n'est pas dispatchée.

## Limitations connues

- **Pas de test automatisé E2E** : la couverture automatisée s'arrête aux tests Feature Livewire (`tests/Feature/Livewire/Parc/MachineShowPageTest.php`). Les tests de bout-en-bout WOL réseau réel sortent du cadre CI (nécessiteraient un lab réseau dédié).
- **Polling mis en pause par Livewire si onglet inactif** : `wire:poll` s'arrête quand le document est caché (`document.hidden`). Si l'opérateur change d'onglet pendant le polling, l'horodatage `runningActionStartedAt` reste figé côté serveur mais le ping repart au retour — le timeout est donc calculé correctement au prochain poll.

# Story 4-3 — Test E2E manuel (actions batch WorkstationGroup)

_Référence : story 4-3, AC10. À exécuter sur la VM dev (`192.168.122.50`) sur un `WorkstationGroup` de test contenant plusieurs machines._

_Mise à jour 2026-04-21 — pipeline async par machine via `DispatchMachinePowerActionJob` + polling Livewire unique sur la vue groupe. Le flux reprend le pattern de la vue machine 4-2 mais avec N tasks simultanées._

## Prérequis

- Migration appliquée (déjà en place depuis 4-2) : `machine_power_action_tasks`.
- Worker queue **obligatoire** pour respecter NFR2 (< 500 ms) sur le batch :
  ```bash
  systemctl status laravel-queue-general.service
  # Si arrêté :
  systemctl enable --now laravel-queue-general
  ```
- Accès à l'UI SambaEdu sur la VM dev.
- Un user ayant la permission `computer.control` (rôles `ComputerAdmin` ou `SuperAdmin`).
- Un `WorkstationGroup` contenant **≥ 3 machines**, dont **au moins une injoignable** pour forcer un scénario d'échec. Exemple SQL :
  ```sql
  -- Créer un groupe test
  INSERT INTO workstation_groups (name, is_physical, is_active, created_at, updated_at)
  VALUES ('lab-e2e-4-3', true, true, NOW(), NOW());

  -- Créer 3 workstations : 2 existantes + 1 IP volontairement injoignable
  INSERT INTO workstations (name, os, ip, mac, status, created_at, updated_at) VALUES
    ('pc-e2e-online', 'Windows 10', '192.168.122.100', '52:54:00:aa:bb:01', 1, NOW(), NOW()),
    ('pc-e2e-offline', 'Windows 10', '192.168.122.101', '52:54:00:aa:bb:02', 1, NOW(), NOW()),
    ('pc-e2e-timeout', 'Windows 10', '192.0.2.1', '52:54:00:aa:bb:03', 1, NOW(), NOW());

  -- Attacher au groupe (pivot)
  INSERT INTO workstation_group_workstation (workstation_id, workstation_group_id, physical)
  SELECT w.id, g.id, true
  FROM workstations w, workstation_groups g
  WHERE w.name IN ('pc-e2e-online', 'pc-e2e-offline', 'pc-e2e-timeout')
    AND g.name = 'lab-e2e-4-3';
  ```
  _`192.0.2.1` appartient à TEST-NET-1 (RFC 5737) et est garanti non routable._

## Scénario 1 — Batch wake avec un mix succès/échec

1. Aller sur `/parc/groups/{id}` du groupe `lab-e2e-4-3`.
2. Cocher "Tout sélectionner" (les 3 machines).
3. Cliquer **Actions machine → Allumer** dans la barre flottante.
4. **Attendu (dispatch < 500 ms)** :
   - Toast vert "Action de allumage lancée sur 3 machine(s)" apparaît en **< 500 ms** (mesurer dans le Network tab des devtools, la réponse Livewire `executeSelectedGroupMachinesAction` doit terminer en < 500 ms).
   - Badge info "Allumage en cours (0/3)" apparaît à côté des boutons d'actions globales.
   - Le dropdown "Actions machine" batch devient grisé (`opacity-50`) tant que le batch est en cours.
   - 3 lignes créées dans `machine_power_action_tasks` :
     ```sql
     SELECT id, workstation_id, action, status FROM machine_power_action_tasks ORDER BY id DESC LIMIT 3;
     ```
5. **Polling live** :
   - Une requête POST Livewire toutes les 3 s (`pollGroupReadiness`).
   - Les badges de la colonne "Action" de chaque ligne passent de "En file" → "En cours" → "OK" / "Échec" en direct.
   - Les lignes sont surlignées (`bg-info/5` active, `bg-success/10` completed, `bg-error/10` failed).
6. **Résumé de fin de batch** :
   - Au-dessus du tableau, un encart "Résumé du batch : allumage" s'affiche.
   - Compteurs live `X succès / Y échecs / Z en cours`.
   - Si `pc-e2e-timeout` est en échec, elle apparaît dans la liste "Machines en échec" avec l'error_message (`Readiness timeout (120s)` ou équivalent).
7. **Timeout** (après 120 s) :
   - Toast orange "Batch terminé avec timeout sur N machine(s) après 120s".
   - `machine_boot_logs.error_flags=1` pour les machines timeoutées :
     ```sql
     SELECT machine_name, action, success, error_flags FROM machine_boot_logs ORDER BY id DESC LIMIT 5;
     ```
8. Cliquer **Effacer** dans l'encart résumé → l'encart disparaît, les rows `machine_power_action_tasks` restent en DB pour l'audit.

## Scénario 2 — Idempotence (D4) : relance pendant batch en cours

1. Répéter le scénario 1 jusqu'au dispatch (les 3 tasks sont `queued/running`).
2. Sans attendre la fin, re-cocher les machines et cliquer **Actions machine → Allumer** à nouveau.
3. **Attendu** :
   - Le bouton "Actions machine" batch reste grisé (`@disabled($batchRunning)`).
   - Si on force le click (devtools), le composant retourne un toast orange "Un batch est déjà en cours sur ce groupe."
   - Aucune nouvelle ligne créée dans `machine_power_action_tasks`.
4. **Attendre la fin du batch** (120s max).
5. Relancer **Actions machine → Allumer** sur les mêmes machines.
6. **Attendu** (AC7) : comme les tasks précédentes sont terminales, le nouveau dispatch est autorisé. Si certaines machines ont encore une task en `running` (worker bloqué par ex.), elles seront skippées avec toast warning `"Action de allumage lancée sur X machine(s) — Y déjà en cours, ignorée(s)."`

## Scénario 3 — Action unitaire depuis la vue groupe

1. Sur `/parc/groups/{id}`, dans le tableau, cliquer le menu `⋮` d'une ligne machine.
2. Cliquer **Éteindre**.
3. **Attendu** :
   - Toast "Action de extinction lancée sur la machine".
   - La ligne de cette machine reçoit un badge "En file / En cours" dans la colonne Action.
   - `machine_power_action_tasks` a 1 nouvelle ligne `action=shutdown, status=queued`.
4. Pendant que la task est active, re-cliquer ⋮ → **Redémarrer** sur la MÊME machine.
5. **Attendu** : toast orange "Une action est déjà en cours sur cette machine." (guard AC7).

## Scénario 4 — Restart batch et machine à états (parité 4-2)

1. Sélectionner 2 machines joignables, cliquer **Actions machine → Redémarrer**.
2. Confirmer le `wire:confirm`.
3. **Attendu** :
   - Toast "Action de redémarrage lancée sur 2 machine(s)".
   - Chaque task créée avec `restart_phase='waiting-down'` :
     ```sql
     SELECT id, action, restart_phase, status FROM machine_power_action_tasks WHERE action='restart' ORDER BY id DESC LIMIT 2;
     ```
   - Phase 1 : pendant que les machines sont encore joignables → pas de toast succès, badge "En cours".
   - Phase 2 : dès qu'une machine cesse de répondre → `restart_phase` passe à `waiting-up`.
   - Phase 3 : quand la machine répond à nouveau → task `completed`, badge "OK", encart résumé incrémenté.

## Scénario 5 — Action `remote` non exposée en batch (AC6)

1. Sur `/parc/groups/{id}`, sélectionner 2 machines.
2. Ouvrir le dropdown "Actions machine" de la barre flottante batch.
3. **Attendu** : les 4 actions suivantes sont présentes : `Allumer`, `Éteindre`, `Forcer l'extinction`, `Redémarrer`. L'entrée **`Accès distant` est absente** (AC6).
4. Ouvrir le dropdown unitaire d'une ligne (menu `⋮`).
5. **Attendu** : les 5 actions sont présentes, incluant `Accès distant` (flux synchrone conservé).

## Scénario 6 — Permissions (AC11)

1. Se connecter avec un user SANS la permission `computer.control` (rôle `Technicien` ou standard).
2. Aller sur `/parc/groups/{id}`.
3. **Attendu** :
   - Les dropdowns d'actions (unitaires ET batch) ne présentent AUCUNE entrée power (`@can('computer.control')` masque le bloc, seul "Retirer du groupe" reste visible dans le dropdown unitaire).
4. Tenter de forger un appel Livewire via devtools Console :
   ```javascript
   Livewire.find('<component-id>').call('executeSelectedGroupMachinesAction', 'shutdown')
   ```
5. **Attendu** : toast "Accès refusé — Vous n'avez pas les droits pour effectuer cette action". Aucune task créée.

## Vérification audit trail

```sql
-- Historique des batches (tasks groupées par initiated_by + timestamp)
SELECT
    initiated_by,
    DATE_TRUNC('minute', initiated_at) AS batch_ts,
    action,
    COUNT(*) AS machines,
    SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS ok,
    SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS ko
FROM machine_power_action_tasks
GROUP BY initiated_by, DATE_TRUNC('minute', initiated_at), action
ORDER BY batch_ts DESC
LIMIT 10;
```

## Limitations connues

- **Polling mis en pause par Livewire si onglet inactif** (même limitation qu'en 4-2) : `wire:poll` s'arrête quand `document.hidden=true`. Le timeout est recalculé au retour dans l'onglet. Tradeoff accepté — le worker queue continue à traiter les jobs en arrière-plan ; seul l'affichage frontend se fige.
- **Rafraîchissement de page en cours de batch** : `$currentBatchTaskIds` étant stocké côté composant, un F5 perd la corrélation UI (mais pas l'audit trail DB — les tasks continuent leur cycle). L'opérateur peut consulter l'état des machines individuellement via `/parc/machines/{id}` (vue 4-2).
- **Worker queue arrêté** : les tasks resteront en status `queued`. Le polling les timeoutera après 120 s (AC5), status passe à `failed` avec `error_message='Readiness timeout (120s)'`, toast warning global.
- **`ParcController::massAction`** (endpoint JSON `/admin/parcs/{parc}/mass-action`) reste synchrone (scripts legacy / API compat). **Ne PAS utiliser depuis l'UI** — l'UI moderne passe par le composant Livewire async.

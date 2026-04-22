# Story 4-4 — Test E2E manuel VM

_Dernière mise à jour : 2026-04-22 (implémentation initiale)._

Checklist de validation manuelle sur la VM `192.168.122.50` après sync code.

## Prérequis

- Code synchronisé sur la VM (sync auto — ne pas rsync manuellement).
- Au moins 2 machines testables dans un groupe (salle physique réelle ou test).
- Permission Spatie `computer.control` côté utilisateur de test.

## 1. Appliquer les migrations

```bash
ssh -i ~/.ssh/id_se4fs_vm root@192.168.122.50
cd /var/www/sambaedu-reload
php artisan migrate
php artisan config:cache
```

Vérifier que les 3 migrations 4-4 sont appliquées :

```bash
php artisan migrate:status | grep -i '2026_04_22'
```

Doit lister :
- `2026_04_22_100000_drop_workstation_scheduled_actions_table` (migrates)
- `2026_04_22_100001_create_workstation_group_schedules_table`
- `2026_04_22_100002_create_workstation_group_schedule_runs_table`

## 2. Vérifier le scheduler

```bash
php artisan schedule:list
```

Doit afficher 2 nouvelles entrées :
- `* * * * * php artisan parc:execute-group-schedules ............... Next Due: 1 minute`
- `0 0 * * * php artisan parc:prune-group-schedule-runs ............. Next Due: X hours`

## 3. Création d'un schedule récurrent

1. Se connecter sur `https://<VM>/parc/groups/{id}` (groupe de test).
2. Faire défiler jusqu'au panneau « Programmations ».
3. Cliquer « Ajouter une programmation ».
4. Onglet « Récurrent » (par défaut).
5. Choisir Action : « Allumage ».
6. Cocher les jours (ex. Lun-Ven).
7. Saisir une heure `now() + 2 min` (ex. 10:32 si on est à 10:30).
8. Timezone `Europe/Paris`.
9. Enregistrer.
10. **Attendre 2 minutes.**
11. Vérifier dans la table `machine_power_action_tasks` (`php artisan tinker → MachinePowerActionTask::latest()->limit(5)->get()`) que N tasks ont été créées avec `initiated_by='schedule:<id>'`.
12. Vérifier `workstation_group_schedule_runs` → 1 row, summary correct.

## 4. Désactiver → pas de re-fire

1. Toggle « Activé » → OFF.
2. Attendre le créneau suivant (ex. lendemain même heure OU `php artisan tinker → (new \App\Services\Parc\WorkstationGroupScheduleService(app(...))) ->executeDue(Carbon::parse('2026-...')); `).
3. Aucune nouvelle task créée.

## 5. Collision avec une action manuelle (AC8)

1. Créer un schedule `wake` à `now() + 3 min`.
2. Lancer manuellement `wake` sur une des machines 20 s avant l'heure du cron.
3. Au tick cron, le run enregistre `skipped_count: 1` pour cette machine.

## 6. Idempotence tick (AC3)

Simuler 2 ticks dans la même minute via artisan :

```bash
php artisan parc:execute-group-schedules
php artisan parc:execute-group-schedules
```

Vérifier dans `workstation_group_schedule_runs` : 1 seul run (pas 2).

## 7. Bascule heure été/hiver (AC11)

Test automatisé via `Carbon::setTestNow()` — couvert par `test_execute_due_respects_timezone_of_schedule`. En manuel : modifier temporairement APP_TIMEZONE ou attendre la bascule suivante (octobre).

## 8. One-shot (AC18–AC22 — v0.2 D7)

1. Créer un schedule one-shot : toggler « Date unique », `run_at = now() + 3 min`, action `wake`.
2. Vérifier badge « Date unique » dans la liste.
3. Attendre 3 minutes.
4. **Observations attendues** :
   - N tasks créées avec `initiated_by='schedule:<id>'`.
   - `workstation_group_schedules.enabled = false`.
   - `workstation_group_schedules.completed_at` = timestamp du run.
   - Badge passe à « Terminé ».
   - Le bouton « Modifier » disparaît, remplacé par « Dupliquer » + « Supprimer ».
5. Attendre le tick suivant (+4 min) → **aucune nouvelle task**, pas de nouveau run.

## 9. Duplication d'un one-shot terminé

1. Sur un one-shot terminé, cliquer « Dupliquer ».
2. Une nouvelle ligne apparaît avec `run_at` placeholder (now+1h).
3. Ouvrir la modale d'édition de la nouvelle ligne.
4. Ajuster `run_at` à la future date voulue.
5. Enregistrer → badge « Date unique » + enabled=true.

## 10. Catch-up one-shot post-downtime

1. Couper le service `cron` pendant 5 minutes.
2. Créer un one-shot à `now() + 2 min` via artisan tinker (on ne peut pas le créer avec un run_at passé via l'UI).
3. Attendre le dépassement.
4. Redémarrer le cron.
5. Au prochain tick, le one-shot est rattrapé — `summary.drift_seconds` consigne le retard.

## Migration hors legacy

L'ancienne table MySQL `actions` du legacy `sambaedu` n'est **pas** migrée automatiquement. Les opérateurs qui avaient des crons legacy doivent les recréer manuellement dans l'UI `/parc/groups/{id}` — les schémas sont trop différents (`day` VARCHAR unitaire vs `days_of_week` ARRAY SMALLINT[], pas de timezone, pas de one-shot).

## Rollback prod

Si besoin :

```bash
php artisan migrate:rollback --step=3  # drop runs + schedules + re-create legacy orpheline
```

Les runs d'historique seront perdus (pas de sauvegarde automatique). Exporter avant si nécessaire :

```bash
php artisan tinker
\App\Models\WorkstationGroupScheduleRun::all()->toJson() | json_pp > /tmp/runs-backup.json
```

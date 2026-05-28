# Story 4.4 : Crons Planifiés sur un WorkstationGroup

Status: review

> **Origine :** formalisation du scope 4.4 de l'Epic 4. Une migration `workstation_scheduled_actions` existe déjà (2026_03_16_100000) mais **aucun modèle, service, commande ni UI** ne l'exploite : la table est orpheline. Cette story livre la pile complète (modèle + service + artisan scheduler + UI Livewire + historique d'exécution) en s'appuyant strictement sur l'infra async 4.2/4.3 déjà en prod (`DispatchMachinePowerActionJob` + `MachinePowerActionTask` + `WorkstationGroupService::executeGroupMachinesAction`).
> **Scope v0.2 (2026-04-22) :** support de 2 modes d'exécution :
> - **`recurring`** (défaut historique) — triplet `days_of_week` + `time_of_day` + `timezone` (ex. allumer la salle 101 chaque lundi–vendredi à 08:30).
> - **`one_shot`** (ajout v0.2) — `run_at` TIMESTAMPTZ unique, futur à la création (ex. allumer la salle 101 le mardi 12 mai à 7h45, puis plus jamais). Cas d'usage : examens, journées portes ouvertes, événements ponctuels. Exclusif avec `recurring` (contrainte DB + FormRequest). Auto-complétion : `enabled=false` + `completed_at=now()` après passage, idempotence au scheduler.
> **Épic :** Epic 4 — Gestion des Machines, WorkstationGroups & AppProfiles SER.
> **Dépendances amont :**
> - **Story 4.2 (done, commit `4c9791d`)** — fournit `DispatchMachinePowerActionJob`, table `machine_power_action_tasks`, `MachinePowerService`, `MachineBootLog`, pattern feedback readiness. Les exécutions de cron créent des `MachinePowerActionTask` via cette infra.
> - **Story 4.3 (review, `4-3-actions-batch-sur-un-workstationgroup`)** — fournit `WorkstationGroupService::executeGroupMachinesAction()` refactorée (pipeline async par machine + contrat typé `{action, requested_count, success_count, failed_count, results[]}` + filtre idempotence 409). **Le cron réutilise cette méthode telle quelle** : c'est exactement le comportement voulu (dispatcher une action batch sur un groupe). **Blocage merge : 4.3 doit être passée à `done` avant merge de 4.4.** En attendant, le dev peut commencer en rebasant sur la branche de 4.3.
> - **Permission Spatie `computer.control`** (enum `SambaPermission::ComputerControl`, rôles `ComputerAdmin` + `SuperAdmin`) : déjà en place. Étendue au CRUD cron (créer/modifier/activer/désactiver/supprimer un cron = même droit que lancer une action manuelle — on automatise juste dans le temps).

---

## Story

En tant que **responsable de collège**,
je veux programmer des actions (allumage WOL, extinction) sur un WorkstationGroup soit selon un horaire récurrent (jours de la semaine + heure), soit à une **date/heure unique** (one-shot, sans récurrence),
afin que les salles s'allument et s'éteignent automatiquement aux bonnes heures sans intervention manuelle quotidienne ET que je puisse également planifier des actions ponctuelles (examens, journées portes ouvertes, samedi d'événement) sans avoir à me connecter à l'horaire dit.

---

## Contexte & Motivation

### État actuel (audit 2026-04-22)

**Infrastructure déjà en place (réutilisable à 100 %) :**

- `App\Jobs\DispatchMachinePowerActionJob` — 1 job par machine, 1 `MachinePowerActionTask` par machine, retry=1, timeout=90s, route vers `config('parc.queue_connection')`.
- `App\Services\Parc\WorkstationGroupService::executeGroupMachinesAction(int $groupId, array $machineIds, string $action, ?string $initiatedBy = null): array` — pipeline async avec filtre idempotence (skip 409 si `ACTIVE_STATUSES` existante), contrat typé `{action, requested_count, success_count, failed_count, results[]}`.
- `App\Services\Parc\WorkstationGroupService::SUPPORTED_MACHINE_ACTIONS = ['wake', 'shutdown', 'shutdown-force', 'restart', 'remote']`.
- `App\Models\MachinePowerActionTask` — traçabilité fine par machine (status, `restart_phase`, `error_message`, `initiated_by`).
- `App\Models\MachineBootLog` — audit historique des actions réseau.
- `App\Console\Kernel` — 5 commandes schedulées (`controlhub:heartbeat` everyMinute, `quota:refresh-cache`, `users:sync-from-ad`, `user-groups:sync-from-ad`, `error-logs:prune`). Scheduler Laravel déjà actif sur la VM via `cron` système (1 min tick).
- `config/app.php` — `'timezone' => env('APP_TIMEZONE', 'Europe/Paris')` — timezone Paris par défaut (cohérent avec le contexte éducatif français).

**Migration existante mais orpheline (à enrichir) :**

- `database/migrations/2026_03_16_100000_create_workstation_scheduled_actions_table.php` crée une table `workstation_scheduled_actions` avec `workstation_group_id`, `action` (wol/stop/reboot), `day` (l/ma/me/j/v/s/d/all), `time`, `is_active`. **AUCUN modèle, service, commande ni UI ne l'utilise** — la table est créée mais jamais lue ni écrite. Cette story ressuscite ce schéma et l'étend.

**Référence legacy (`sambaedu/parcs/action_cron.php`, 110 L) :**

- Script shell-wrapper déclenché par cron système (`action_cron.sh`) **toutes les 15 minutes**.
- Schéma MySQL `actions(parc, action, jour, heure)` — `jour` ∈ `{d, l, ma, me, j, v, s}` (ou `all`), `heure` en `HH:MM:SS`.
- Logique : `SELECT * FROM actions WHERE jour = <jour_courant> AND heure BETWEEN now() AND now()+15min;` puis `start_parc($config, $action, $row['parc'], true)`.
- Actions supportées legacy : `wol` (allumage), `stop` (extinction), `reboot` (redémarrage) — pas de `shutdown-force` (l'option `$force` existe dans `start_parc` mais pas exposée en cron).
- Intégration EcoWatt : si `get_ecowatt > 1` → force l'arrêt du parc `base` (surchauffe réseau électrique). **Hors scope 4.4 MVP.**
- Intégration Pronote API (salle_state) : récupère l'état souhaité par salle depuis Pronote. **Hors scope 4.4 MVP.**

**Vue groupe existante (`resources/views/pages/parc/groups/[id]/`) :**

- `index.blade.php` (Livewire SFC) + partials `group-info`, `machines-list`, `batch-summary`, `wallpaper-modal`.
- Permission `@can('computer.control')` déjà appliquée sur les actions manuelles. Mêmes ACL appliquées au CRUD cron (D6).

### Manques identifiés (ce que 4.4 livre)

1. **Modèle Eloquent `WorkstationGroupSchedule`** (rebaptisé, cf. D3) — remplace la table orpheline avec une représentation moderne : discriminant `mode` (`recurring` | `one_shot`, D7), triplet récurrent (`days_of_week` ARRAY PostgreSQL SMALLINT[] + `time_of_day` + `timezone`) OU `run_at` TIMESTAMPTZ (one-shot), + `enabled` + `completed_at` (auto-marquage one-shot) + audit (`created_by_user_id`). Contrainte DB CHECK garantit l'exclusivité des deux représentations (D7).
2. **Service `WorkstationGroupScheduleService`** — CRUD idempotent + méthode `executeDue(Carbon $now)` appelée par le scheduler artisan à chaque tick minute. Gère les deux modes : filtre récurrents matchant la minute courante + one-shots dont `run_at <= now AND completed_at IS NULL`. Résout la liste des machines **au moment du tick** (pas au moment de la création — voir D2 liveness).
3. **Commande artisan `parc:execute-group-schedules`** — **juste le tick** (everyMinute via `Kernel::schedule`) qui délègue à `WorkstationGroupScheduleService::executeDue()`. **Ne fait pas le travail elle-même** : elle enqueue des `DispatchMachinePowerActionJob` via `WorkstationGroupService::executeGroupMachinesAction()` — les **workers `laravel-queue-general` habituels** traitent les jobs (pas de nouveau worker à déployer). Voir diagramme flux dans Dev Notes.
4. **Table d'historique `workstation_group_schedule_runs`** — 1 row par exécution (schedule_id, ran_at, summary JSONB {success, failed, skipped}, created_at). Rétention 30 jours, prune via scheduler (`error-logs:prune` pattern).
5. **UI Livewire sur `/parc/groups/{id}`** — panneau "Programmations" (partial `_partials/schedules-panel.blade.php`) listant les crons actifs (récurrents + one-shots) + modale de création/édition avec **toggle « Récurrent / Date unique »** (D7) et formulaire conditionnel. CRUD via méthodes Livewire + `@can('computer.control')`. Un one-shot passé n'est plus éditable (seulement cloneable/supprimable).
6. **Affichage historique d'exécution** — liste des N derniers runs par schedule, avec drill-down vers les `MachinePowerActionTask` créés (traçabilité AC2 : "chaque exécution crée une ControlHubTask par machine du groupe"). Les one-shots terminés restent visibles avec badge "Terminé".
7. **Tests** : unit `WorkstationGroupScheduleServiceTest`, feature `GroupSchedulesPageTest`, feature `ExecuteGroupSchedulesCommandTest`.

### Décisions actées (kickoff 4-4)

> **D1 — Modèle de scheduling : Laravel Scheduler + commande artisan everyMinute() (tick → enqueue → worker)** ✅ recommandé

- **Option A (recommandée)** : une commande artisan `parc:execute-group-schedules` enregistrée dans `Kernel::schedule` avec `->everyMinute()->withoutOverlapping()->runInBackground()`. À chaque tick, la commande lit `workstation_group_schedules` et matche les crons à exécuter (fenêtre = minute courante pour récurrents + `run_at <= now AND completed_at IS NULL` pour one-shots).
- **Option B (rejetée)** : entrée dans crontab système (fichier `/etc/cron.d/…` généré par Laravel). ❌ Casserait l'immutabilité de l'image prod, nécessiterait `sudo`, impossible à tester unitairement.
- **Option C (rejetée)** : Spatie laravel-schedule-monitor. ❌ Dépendance externe non nécessaire — l'historique d'exécution est déjà couvert par `workstation_group_schedule_runs`.
- **Rationale** : pattern Laravel standard, portable (fonctionne avec `php artisan schedule:work` en dev + `cron /etc/cron.d/laravel` en prod), testable unitairement (`Artisan::call('parc:execute-group-schedules')` + `Carbon::setTestNow()`), zéro écriture fichier système. Le scheduler tourne déjà (cf. les 5 commandes Kernel::schedule actuelles).

- **⚠️ Architecture tick → enqueue → worker (clarification importante)** :
  La commande artisan `parc:execute-group-schedules` est **juste le tick** (1/min via `Kernel::schedule`). Elle ne fait **que** lire les schedules dûs, puis appelle `WorkstationGroupService::executeGroupMachinesAction(groupId, machineIds, action, initiatedBy: 'schedule:<id>')`. Cette méthode **dispatche** N `DispatchMachinePowerActionJob` vers la queue `laravel-queue-general` — exactement comme une action batch manuelle (4.3). **Les workers habituels traitent les jobs**. La commande scheduler ne fait **pas** le boulot elle-même, elle l'enqueue.
  - **Tick scheduler** (léger, 1 DB SELECT + N enqueue) = commande artisan `parc:execute-group-schedules`
  - **Exécution effective** des WOL / shutdown par machine = worker `laravel-queue-general` (déjà packagé via `scripts/update.sh` depuis 4.2, tournant en `systemd` unit `laravel-queue-general.service`)
  - **Pas de nouveau worker à déployer**, pas de nouvelle queue à créer, pas de nouveau process supervisé.
  - Le champ `initiated_by` des `MachinePowerActionTask` permet de distinguer « manuel » (`user:<id>`) vs « cron » (`schedule:<id>`) dans l'audit trail.
  - Voir **diagramme flux** dans section Dev Notes ci-dessous.

> **D2 — Résolution "machines du groupe" au moment de l'exécution (liveness)** ✅ recommandé

- À chaque tick du cron, relire le groupe (`$group->workstations` via Eloquent) pour obtenir la liste à jour des machines.
- **Rationale** : un enseignant a pu ajouter/retirer un poste depuis la création du cron. On veut que le cron s'applique au groupe **tel qu'il est** à l'heure dite, pas à un snapshot figé. Pas de colonne `workstation_ids_snapshot` sur la table schedules.

> **D3 — Représentation horaire : jours + heure (pas d'expression crontab complète)** ✅ recommandé

- Colonnes : `days_of_week` (ARRAY PostgreSQL `SMALLINT[]` — ISO 8601 : 1=lundi, 7=dimanche), `time_of_day` (TIME 24h), `timezone` (VARCHAR, défaut `Europe/Paris`).
- **Pas d'expression crontab complète** (`0 8 * * 1-5`) : l'interface est destinée à des enseignants / responsables de collège, pas à des admins système. Formulaire = 7 checkbox (lun…dim) + timepicker HH:MM.
- **Rationale** : UX. Les expressions crontab sont un vecteur de bugs et de confusion. Notre domaine (allumer une salle à 8h30 les jours ouvrés) est parfaitement exprimable en `days_of_week + time_of_day`.
- **Note** : le champ `day` (VARCHAR) de la migration legacy `workstation_scheduled_actions` ne couvre qu'un jour ou "all". On enrichit vers un ARRAY pour pouvoir dire "lun/mar/mer/jeu/ven" en une seule règle (vs 5 règles legacy).

> **D4 — Collision avec une action batch manuelle : skip silencieux côté service** ✅ recommandé

- Si une `MachinePowerActionTask` est déjà `ACTIVE_STATUSES` (`queued | dispatched | running`) sur une machine du groupe au moment du tick, le filtre idempotence de `WorkstationGroupService::executeGroupMachinesAction()` la skip avec `code=409` et `reason="already-running"`.
- **Rationale** : réutilise la protection 4.3 (zéro code nouveau). Le log de run enregistre la machine comme `skipped` (pas `failed`), ce qui est visible dans l'historique sans alarmer l'utilisateur.

> **D5 — Actions autorisées en MVP : `wake` + `shutdown` seulement** ✅ recommandé

- **`wake`** (allumage WOL) : usage principal — allumer la salle avant le premier cours.
- **`shutdown`** (extinction soft) : usage principal — éteindre la salle en fin de journée.
- **`shutdown-force`** : ❌ **exclu en MVP**. Risque de perte de données si un utilisateur est encore connecté (devoir non sauvegardé à 18h30). Le shutdown soft laisse la fenêtre de sauvegarde.
- **`restart`** : ❌ **backlog**. Usage non identifié dans le workflow éducatif. Si besoin plus tard, 1 case enum à ajouter + 1 helper UI.
- **`remote`** : ❌ **hors scope** (génère un token Guacamole par poste — pas un pattern batch/cron).
- **Rationale** : couvre les deux besoins explicites des ACs de l'epic ("salles s'allument et s'éteignent automatiquement"). Surface minimale pour le MVP.

> **D6 — Feedback d'exécution cron : historique persistant en DB + affichage paginé** ✅ recommandé

- Table `workstation_group_schedule_runs` : 1 row par exécution, `summary` en JSONB `{success_count, failed_count, skipped_count, task_ids: [int], errors: [{machine_id, machine_name, error_message}]}`.
- Rétention 30 jours : commande `parc:prune-group-schedule-runs` (daily), pattern identique à `error-logs:prune`.
- UI : dans le panneau "Programmations" de `/parc/groups/{id}`, un tableau listant les N derniers runs (paginé 10/page via `wire:navigate` et `->paginate(10)`). Chaque row affiche date/heure + compteurs + tooltip errors. Drill-down optionnel vers les `MachinePowerActionTask` liés.

> **D7 — Mode récurrent + mode one-shot (date unique)** ✅ recommandé (ajout v0.2)

- **Motivation** : les cas d'usage réels incluent des actions ponctuelles non-récurrentes. Exemples concrets remontés par Henri :
  - « Allumer la salle 101 le mardi 12 mai à 7h45 pour un examen » (pas un jour récurrent, un créneau unique).
  - « Allumer pour un examen le samedi 14 juin à 8h » (le samedi n'est pas un jour-type récurrent — exception ponctuelle).
  - « Préparer les PC pour la journée portes ouvertes du 21 juin à 8h30 » (événement annuel isolé).
- **Discriminant `mode`** (enum `'recurring' | 'one_shot'`, défaut `'recurring'` pour compat mentale + rétro-cohérence migration) :
  - Si `mode = 'recurring'` → `days_of_week` + `time_of_day` + `timezone` requis ; `run_at` **et** `completed_at` NULL.
  - Si `mode = 'one_shot'` → `run_at` TIMESTAMPTZ requis, **doit être strictement dans le futur au moment de la création** (validation FormRequest `after:now`) ; `days_of_week` / `time_of_day` / `timezone` NULL.
- **Contrainte DB (CHECK constraint)** — garantit l'exclusivité au niveau stockage, indépendamment du FormRequest :
  ```sql
  CHECK (
      (mode = 'recurring' AND days_of_week IS NOT NULL AND time_of_day IS NOT NULL AND timezone IS NOT NULL AND run_at IS NULL)
      OR
      (mode = 'one_shot'  AND run_at IS NOT NULL AND days_of_week IS NULL AND time_of_day IS NULL AND timezone IS NULL)
  )
  ```
  Interdit d'insérer un schedule avec les deux représentations ou aucune.
- **Auto-complétion one-shot après exécution** :
  - `enabled` passe à `false` (auto, même transaction que la création du run).
  - Colonne `completed_at` TIMESTAMPTZ renseignée avec `ran_at` (= now du tick d'exécution).
  - Un row est inséré dans `workstation_group_schedule_runs` **exactement comme pour le récurrent** (même shape JSONB summary, même idempotence via `(schedule_id, ran_for_date, ran_for_time)` où `ran_for_time = run_at::time` et `ran_for_date = run_at::date`).
- **Idempotence one-shot** : le scheduler ne re-fire **jamais** un one-shot déjà exécuté.
  - Filtre SELECT scheduler : `WHERE enabled = true AND (mode = 'recurring' OR (mode = 'one_shot' AND completed_at IS NULL AND run_at <= now()))`.
  - Protection multi-couches identique au récurrent (index unique `(schedule_id, ran_for_date, ran_for_time)` + garde `exists()` côté service + `withoutOverlapping(5)`).
  - **Si un tick saute** (downtime > 1 min) et que `run_at` est dans le passé au redémarrage → le one-shot **se tire rattrapé au tick suivant** (comportement voulu : ne pas perdre l'action). Le run consigne la différence `ran_at - run_at` (drift) dans `summary.drift_seconds` pour audit.
- **UI (modale création/édition)** :
  - Toggle en tête de modale : « Récurrent / Date unique » (x-alpine + radio ou `wire:model="formMode"`).
  - Formulaire conditionnel (`x-show="formMode === 'recurring'"` / `x-show="formMode === 'one_shot'"`) avec les champs appropriés.
  - Un **one-shot déjà passé** (`completed_at IS NOT NULL`) n'est **plus éditable** — seulement cloneable (« Dupliquer en nouveau one-shot ») ou supprimable. Le bouton « Modifier » est masqué.
  - Un one-shot futur (`run_at > now() AND completed_at IS NULL`) reste pleinement éditable, tant que `enabled=true`.
- **Historique & affichage** :
  - Le panneau « Programmations » affiche les 2 types dans la même table (colonne « Déclenchement » : « Lun–Ven · 08:30 » vs « 12 mai 2026 · 07:45 »).
  - Les one-shot passés (`completed_at IS NOT NULL`) restent visibles marqués « Terminé » (badge gris) et apparaissent en bas de liste (tri `order_by completed_at NULLS FIRST, run_at, time_of_day`).
  - Le run historique est affiché comme pour le récurrent (drill-down vers `MachinePowerActionTask`).
- **Rationale global** : on étend la granularité sans dupliquer de modèle ni de service. Même table, même service `executeDue()`, même `WorkstationGroupScheduleRun`, même commande artisan — le mode est juste un discriminant qui change le filtre SELECT et les règles de validation. Surface de code minimale vs surface fonctionnelle doublée.

---

## Acceptance Criteria

**AC1 — Création d'un cron sur un WorkstationGroup (AC 1 epic original)**

- **Given** je suis sur `/parc/groups/{id}` et je possède la permission `computer.control`
- **When** je clique "Ajouter une programmation" dans le panneau "Programmations", sélectionne l'action (`wake` ou `shutdown`), coche les jours (lun…dim, ≥ 1), saisis une heure au format `HH:MM` (timezone `Europe/Paris` par défaut), active la case "Activé", clique "Enregistrer"
- **Then** une ligne est créée dans `workstation_group_schedules` avec `enabled=true`, `created_by_user_id=auth()->id()`, `days_of_week=[1,2,3,4,5]` (exemple lun-ven), `time_of_day='08:30:00'`, `timezone='Europe/Paris'`, `action='wake'`
- **And** un toast `toastSuccess("Programmation créée")` est émis
- **And** la liste des programmations affiche la nouvelle entrée (refresh Livewire)

**AC2 — Exécution automatique au bon horaire + traçabilité ControlHubTask (AC 1b epic original)**

- **Given** une programmation existe : `action=wake, days_of_week=[1,2,3,4,5], time_of_day='08:30:00', timezone='Europe/Paris', enabled=true`, et le WorkstationGroup contient 3 machines {M1, M2, M3}
- **When** le scheduler tick à 08:30:00 heure de Paris un jour ouvré (lundi–vendredi)
- **Then** la commande `parc:execute-group-schedules` est exécutée
- **And** `WorkstationGroupScheduleService::executeDue()` identifie la programmation comme due
- **And** `WorkstationGroupService::executeGroupMachinesAction($group->id, [M1, M2, M3], 'wake', 'schedule:' . $scheduleId)` est appelée
- **And** **3 `MachinePowerActionTask`** sont créées (1 par machine) en `status=queued`, dispatchées via `DispatchMachinePowerActionJob` (traçabilité per-machine comme demandé par l'AC epic "ControlHubTask par machine")
- **And** 1 row `workstation_group_schedule_runs` est créée avec `{ran_at=2026-04-27T08:30:00+02:00, summary={success_count: 3, failed_count: 0, skipped_count: 0, task_ids: [T1, T2, T3]}}`
- **And** aucune `MachinePowerActionTask` n'est créée sur les machines absentes du groupe (liveness D2)

**AC3 — Filtre horaire : exécution UNE SEULE FOIS par créneau, pas en rafale**

- **Given** le scheduler tick à la minute
- **When** une programmation est due à `08:30`
- **Then** la commande détecte la correspondance **uniquement lors du tick de `08:30:00`-`08:30:59`**, pas avant ni après
- **And** si le scheduler tique à `08:30:15` et à `08:31:00` (deux ticks consécutifs dans la minute), un **seul run** est enregistré (via un garde idempotence : `whereDate('ran_at', today())->where('schedule_id', $id)->where('ran_for_time', '08:30:00')` — aucun doublon si un run existe déjà pour ce créneau ce jour)
- **And** le filtre est : `time_of_day >= now()->startOfMinute() AND time_of_day < now()->addMinute()->startOfMinute()` appliqué dans la timezone de la programmation

**AC4 — Affichage des crons actifs sur la page groupe (AC 2 epic original)**

- **Given** 3 programmations existent sur le groupe (2 `enabled=true`, 1 `enabled=false`)
- **When** je consulte `/parc/groups/{id}`
- **Then** un panneau "Programmations" affiche les 3 lignes avec :
  - Action humanisée (badge FR : "Allumage" / "Extinction")
  - Jours (badges "L Ma Me J V" condensés ou "Lun–Ven" si pattern reconnu)
  - Heure `HH:MM` + mention timezone si ≠ Europe/Paris
  - Toggle "Activé" (boolean visuel DaisyUI `toggle-primary`) — reflète `enabled`
  - Boutons "Modifier" / "Supprimer" (avec `@can('computer.control')`)
- **And** les programmations désactivées sont affichées mais grisées (opacity-50)

**AC5 — Désactivation → prochaines exécutions annulées (AC 3 epic original)**

- **Given** une programmation `enabled=true` existe
- **When** je clique le toggle "Activé" → passe à `enabled=false` (persisté en DB via `WorkstationGroupScheduleService::toggle($id)`)
- **Then** aucune nouvelle `MachinePowerActionTask` n'est créée aux prochaines correspondances horaires
- **And** les `MachinePowerActionTask` déjà dispatchées (par un run précédent) **continuent** leur cycle normal (on n'annule pas rétrospectivement — l'action manuelle analogue est aussi irréversible une fois dispatchée)
- **And** toast `toastSuccess("Programmation désactivée")`

**AC6 — Suppression → plus aucune exécution (AC 3 epic original)**

- **Given** une programmation existe
- **When** je clique "Supprimer" et confirme (`wire:confirm="Supprimer cette programmation ?"`)
- **Then** la ligne est supprimée de `workstation_group_schedules`
- **And** les `workstation_group_schedule_runs` historiques liés par `schedule_id` restent en DB (audit — FK sans cascade ou avec `ON DELETE SET NULL`)
- **And** aucune nouvelle exécution n'est dispatchée aux prochains ticks
- **And** toast `toastSuccess("Programmation supprimée")`

**AC7 — Édition d'un cron existant**

- **Given** une programmation existe
- **When** je clique "Modifier", change les jours (ex. retire le mercredi), change l'heure (ex. 08:30 → 08:45), clique "Enregistrer"
- **Then** la ligne est mise à jour (pas de nouvelle ligne), `updated_at` est mis à jour
- **And** la prochaine exécution respecte les nouveaux paramètres

**AC8 — Collision avec une action manuelle (idempotence D4)**

- **Given** une programmation `wake` est due à `08:30` sur un groupe de 3 machines {M1, M2, M3}
- **And** une action manuelle `wake` sur M2 a été dispatchée à `08:29:58` et la task est encore en `ACTIVE_STATUSES` à `08:30:00`
- **When** le scheduler tick à `08:30:00`
- **Then** `WorkstationGroupService::executeGroupMachinesAction()` filtre M2 (code 409, reason `already-running`)
- **And** seulement 2 `MachinePowerActionTask` sont créées (pour M1, M3)
- **And** le run enregistre `{success_count: 2, failed_count: 0, skipped_count: 1, task_ids: [T1, T3], errors: []}` — M2 marquée skipped (pas failed)
- **And** aucun toast / notification — c'est un comportement normal, audité dans l'historique de run

**AC9 — Historique d'exécution visible (D6)**

- **Given** une programmation a été exécutée 5 fois dans les 30 derniers jours
- **When** je clique "Voir historique" sur la ligne programmation (ou il s'affiche direct en accordion/panneau)
- **Then** un tableau paginé liste les 5 runs avec :
  - Date/heure (dans la timezone de l'utilisateur : défaut `Europe/Paris`)
  - Compteurs `succès / échecs / ignorées`
  - Si `errors[] != []` : tooltip ou accordion détaillant `{machine_name, error_message}`
- **And** pagination 10 par page (≤ 3 runs/jour maximum, rétention 30 j → ≤ 90 runs par schedule)

**AC10 — Permissions Spatie `computer.control`**

- **Given** un utilisateur SANS la permission `computer.control`
- **When** il accède à `/parc/groups/{id}`
- **Then** le panneau "Programmations" reste visible en **lecture seule** (liste + historique) ; les boutons "Ajouter", "Modifier", "Supprimer", le toggle "Activé" sont masqués ou `@disabled`
- **And** une tentative de forger un appel Livewire `createSchedule`, `updateSchedule`, `deleteSchedule`, `toggleSchedule` est rejetée (guard serveur-side via `Gate::authorize('computer.control')` ou `$this->authorize(...)`). Capture `AuthorizationException` → `toastError("Accès refusé")`.

**AC11 — Timezone Europe/Paris par défaut + affichage correct en heure d'été / heure d'hiver**

- **Given** `config('app.timezone') = 'Europe/Paris'`
- **And** une programmation `time_of_day='08:30', timezone='Europe/Paris'`
- **When** le scheduler tick aux horaires UTC correspondants (soit `06:30 UTC` en heure d'été, soit `07:30 UTC` en heure d'hiver)
- **Then** la correspondance est correcte dans les deux cas (aucun bug de bascule heure été / heure hiver — test avec `Carbon::setTestNow()` au printemps et à l'automne)
- **And** la commande utilise `Carbon::now($schedule->timezone)` pour comparer, pas `now()` global

**AC12 — Pas de régression sur les actions manuelles 4.2 + 4.3**

- **Given** les suites 4.2 (34 tests) et 4.3 (58 tests power) sont vertes
- **When** on lance `php artisan test --filter='MachinePower|Workstation|GroupShow|MachineShow'`
- **Then** toutes les suites existantes restent vertes (aucune régression)
- **And** les nouvelles tables / modèles n'ajoutent pas de conflit de schema (migration `workstation_group_schedules` et `workstation_group_schedule_runs` sont additives).

**AC13 — Tests unitaires `WorkstationGroupScheduleService`**

- Créer `tests/Unit/Services/Parc/WorkstationGroupScheduleServiceTest.php` avec au minimum :
  - `test_create_schedule_persists_all_fields_with_defaults` (timezone défaut, enabled défaut)
  - `test_update_schedule_mutates_existing_row`
  - `test_toggle_schedule_flips_enabled_flag`
  - `test_delete_schedule_removes_row_but_preserves_runs`
  - `test_execute_due_triggers_matching_schedules_and_creates_run`
  - `test_execute_due_skips_disabled_schedules`
  - `test_execute_due_skips_wrong_day_of_week`
  - `test_execute_due_skips_wrong_time_of_day`
  - `test_execute_due_is_idempotent_within_same_minute` (AC3 no-double-fire)
  - `test_execute_due_respects_timezone_of_schedule` (AC11 DST)
  - `test_execute_due_counts_skipped_machines_already_running` (AC8 filtre 409)
  - `test_execute_due_handles_empty_group_gracefully`
  - `test_execute_due_resolves_machines_at_tick_time_not_creation_time` (D2 liveness)

**AC14 — Tests feature commande artisan `parc:execute-group-schedules`**

- Créer `tests/Feature/Console/ExecuteGroupSchedulesCommandTest.php` :
  - `test_command_dispatches_due_schedules_with_faked_queue` (Queue::fake + assertPushed 3× `DispatchMachinePowerActionJob`)
  - `test_command_is_idempotent_on_double_tick_within_same_minute`
  - `test_command_produces_schedule_run_row_with_summary`
  - `test_command_handles_no_due_schedules_silently`
  - `test_command_respects_enabled_flag`

**AC15 — Tests feature Livewire page groupe (panneau Programmations)**

- Créer ou étendre `tests/Feature/Livewire/Parc/GroupSchedulesPageTest.php` :
  - `test_admin_can_see_schedules_panel_on_group_page`
  - `test_admin_can_create_schedule_via_modal`
  - `test_admin_can_toggle_schedule_enabled`
  - `test_admin_can_edit_schedule`
  - `test_admin_can_delete_schedule_with_confirm`
  - `test_non_admin_sees_read_only_view_without_crud_buttons`
  - `test_non_admin_cannot_forge_livewire_call_to_create_schedule` (assertion `AuthorizationException`)
  - `test_schedule_history_panel_displays_recent_runs_paginated`
  - `test_schedule_validation_requires_at_least_one_day_of_week`
  - `test_schedule_validation_rejects_invalid_time_format`

**AC16 — Documentation**

- Compléter `docs/domains/parc.md` (créé en 4.2) avec une section "Programmations (story 4.4)" :
  - Diagramme Mermaid du flow : scheduler tick → artisan command → service executeDue → executeGroupMachinesAction → N DispatchMachinePowerActionJob
  - Représentation des crons (days_of_week ISO 8601 + time_of_day + timezone)
  - Rappel actions MVP : `wake` + `shutdown` seulement (D5)
  - Rappel rétention historique 30 j (D6)
  - Lien E2E : `docs/qa/4-4-e2e-manual.md`
- Créer `docs/qa/4-4-e2e-manual.md` avec checklist E2E VM (voir AC17).

**AC17 — Test E2E smoke sur VM dev (manuel, documenté)**

- Créer `docs/qa/4-4-e2e-manual.md` avec la checklist :
  1. Appliquer les migrations sur la VM : `php artisan migrate` (crée `workstation_group_schedules` + `workstation_group_schedule_runs`, et supprime ou rename la table orpheline `workstation_scheduled_actions` si besoin — voir Tâche 2.2)
  2. Créer une programmation `wake` à `now() + 2 minutes` sur un groupe de test via l'UI
  3. Vérifier que `php artisan schedule:work` (dev) ou le cron système (prod) tick chaque minute
  4. Observer à `+2 min` que 3 `MachinePowerActionTask` sont créées (`php artisan tinker → MachinePowerActionTask::latest()->limit(3)->get()`)
  5. Vérifier que `workstation_group_schedule_runs` contient 1 row avec le bon summary
  6. Retester avec `now() + 1 minute` mais `enabled=false` → pas de tasks créées
  7. Retester la collision : lancer une action manuelle `wake` sur une machine ~30s avant l'heure cron → le run enregistre `skipped_count: 1`
  8. Vérifier que le tick suivant ne re-tire pas le cron (idempotence)
  9. Tester la bascule heure été/heure hiver : modifier `APP_TIMEZONE` temporairement ou utiliser `Carbon::setTestNow()` pour le test automatisé (AC11)
  10. **Ajout v0.2** — scénario one-shot : créer une programmation `mode=one_shot, run_at=now+3min, action=wake`. Observer au tick `+3 min` que les `MachinePowerActionTask` sont créées, que le schedule passe à `enabled=false` et `completed_at` est renseigné, que le tick `+4 min` ne re-tire rien, et que l'UI affiche le schedule comme « Terminé ».

**AC18 — Création d'une programmation one-shot (mode date unique)** — v0.2 D7

- **Given** je suis sur `/parc/groups/{id}` et je possède la permission `computer.control`
- **When** je clique "Ajouter une programmation", bascule le toggle sur « Date unique », sélectionne l'action (`wake`), choisis `run_at = 2026-05-12T07:45:00+02:00` (dans le futur), clique "Enregistrer"
- **Then** une ligne est créée dans `workstation_group_schedules` avec `mode='one_shot', run_at='2026-05-12T07:45:00+02:00', days_of_week=NULL, time_of_day=NULL, timezone=NULL, enabled=true, completed_at=NULL, created_by_user_id=auth()->id(), action='wake'`
- **And** la contrainte DB CHECK passe (soit triplet récurrent soit run_at, jamais les deux)
- **And** un toast `toastSuccess("Programmation one-shot créée")` est émis
- **And** le panneau "Programmations" affiche la nouvelle entrée avec badge « Date unique · 12 mai 2026 · 07:45 »

**AC19 — Validation FormRequest run_at futur** — v0.2 D7

- **Given** je tente de créer une programmation `mode=one_shot`
- **When** je saisis `run_at = now() - 10 minutes` (passé) ou `run_at = now()` (maintenant exact)
- **Then** la validation FormRequest rejette avec erreur 422 `{run_at: "La date d'exécution doit être dans le futur"}` (règle `after:now` Laravel)
- **And** aucune ligne n'est créée en DB
- **And** toast `toastError("Erreur de validation")`

**AC20 — Validation croisée mode / champs (exclusivité D7)** — v0.2 D7

- **Given** une tentative d'enregistrement d'un schedule
- **When** j'envoie un payload `mode=recurring` sans `days_of_week` OU `mode=one_shot` sans `run_at` OU un payload avec **les deux** (run_at ET days_of_week)
- **Then** le FormRequest rejette avec erreur 422 détaillant la cause (champs manquants ou incompatibles)
- **And** même si le FormRequest est contourné (appel direct modèle), la contrainte DB CHECK lève `QueryException` au niveau DB
- **And** le test unit service `test_create_rejects_schedule_with_both_or_no_representation` le couvre

**AC21 — Exécution one-shot → run + completed_at + enabled=false** — v0.2 D7

- **Given** une programmation `mode=one_shot, run_at='2026-04-27T08:30:00+02:00', action=wake, enabled=true, completed_at=NULL` sur un groupe de 3 machines {M1, M2, M3}
- **When** le scheduler tick à `08:30:00` heure de Paris le `2026-04-27`
- **Then** `WorkstationGroupScheduleService::executeDue()` identifie le one-shot comme dû (car `run_at <= now AND completed_at IS NULL AND enabled=true`)
- **And** `WorkstationGroupService::executeGroupMachinesAction(...)` est appelée exactement comme pour un récurrent
- **And** 3 `MachinePowerActionTask` sont créées avec `initiated_by='schedule:<id>'`
- **And** 1 row `workstation_group_schedule_runs` est créée avec `ran_for_time = run_at::time` et `ran_for_date = run_at::date`
- **And** le schedule est mis à jour : `enabled=false` + `completed_at=<ran_at>` (même transaction que la création du run pour cohérence)

**AC22 — One-shot ne re-fire jamais après passage** — v0.2 D7

- **Given** une programmation one-shot a été exécutée au tick T (enabled=false, completed_at renseigné)
- **When** le scheduler tick aux minutes T+1, T+2, T+N
- **Then** le filtre SELECT `WHERE enabled=true AND (mode='recurring' OR (mode='one_shot' AND completed_at IS NULL AND run_at <= now))` l'exclut
- **And** aucune nouvelle `MachinePowerActionTask` n'est créée pour ce schedule
- **And** aucun nouveau run n'est enregistré
- **And** le test `test_execute_due_does_not_refire_completed_one_shot` couvre ce scenario

**AC23 — One-shot passé non éditable (UI)** — v0.2 D7

- **Given** une programmation one-shot terminée (`completed_at IS NOT NULL`)
- **When** j'affiche le panneau Programmations
- **Then** la ligne affiche badge « Terminé » (gris) + date du `completed_at`
- **And** le bouton « Modifier » est masqué ou `@disabled`
- **And** les boutons « Dupliquer » (nouveau one-shot pré-rempli avec run_at blanc) et « Supprimer » restent disponibles
- **And** si l'utilisateur forge un appel Livewire `updateSchedule($id)` sur un one-shot terminé, le service lève `\DomainException("One-shot terminé non éditable")` → toast d'erreur

**AC24 — Historique mixte récurrents + one-shots terminés** — v0.2 D7

- **Given** le groupe a 2 récurrents actifs, 1 one-shot futur `enabled=true, completed_at=NULL`, et 2 one-shots passés `enabled=false, completed_at IS NOT NULL`
- **When** j'affiche le panneau Programmations
- **Then** les 5 programmations sont listées avec distinction visuelle claire :
  - Récurrents : badge bleu « Récurrent », sous-titre « Lun–Ven · 08:30 »
  - One-shot futur : badge violet « Date unique », sous-titre « 12 mai 2026 · 07:45 »
  - One-shot passé : badge gris « Terminé », sous-titre « Exécuté le 27 avril 2026 »
- **And** le tri par défaut : récurrents actifs d'abord (par heure), puis one-shots futurs (par run_at asc), puis one-shots terminés (par completed_at desc)

**AC25 — Tests unit complémentaires one-shot** — v0.2 D7

- Étendre `tests/Unit/Services/Parc/WorkstationGroupScheduleServiceTest.php` avec :
  - `test_create_one_shot_persists_run_at_and_nullifies_recurring_fields`
  - `test_create_rejects_one_shot_with_run_at_in_past`
  - `test_create_rejects_schedule_with_both_or_no_representation` (validation croisée)
  - `test_execute_due_triggers_one_shot_when_run_at_reached`
  - `test_execute_due_marks_one_shot_as_completed_and_disables_it`
  - `test_execute_due_does_not_refire_completed_one_shot`
  - `test_execute_due_catches_up_one_shot_after_downtime` (run_at dans le passé + `completed_at IS NULL` → tire rattrapé, drift_seconds en summary)
  - `test_update_one_shot_future_is_allowed`
  - `test_update_one_shot_completed_throws_domain_exception`
  - `test_scopes_recurring_and_one_shot_and_completed_filter_correctly`

**AC26 — Tests feature Livewire UI one-shot** — v0.2 D7

- Étendre `tests/Feature/Livewire/Parc/GroupSchedulesPageTest.php` avec :
  - `test_admin_can_create_one_shot_schedule_via_modal_toggle`
  - `test_one_shot_form_conditionally_hides_recurring_fields_and_vice_versa`
  - `test_one_shot_with_run_at_in_past_shows_validation_error`
  - `test_completed_one_shot_edit_button_is_hidden`
  - `test_completed_one_shot_clone_button_creates_new_one_shot_prefilled`
  - `test_panel_displays_recurring_one_shot_future_and_completed_in_correct_order`

---

## Tasks / Subtasks

### Tâche 1 — Audit + préparation (AC: 1, 4, 10, 12)

- [x] **1.1** Lire `app/Services/Parc/WorkstationGroupService.php` — valider que `executeGroupMachinesAction()` accepte un `$initiatedBy` (string) qui sera utilisé comme `MachinePowerActionTask.initiated_by = 'schedule:' . $scheduleId` pour l'audit trail.
- [x] **1.2** Lire `app/Console/Kernel.php` — identifier le bon endroit pour enregistrer `parc:execute-group-schedules` (après `controlhub:heartbeat`, avant `error-logs:prune`).
- [x] **1.3** Lire `app/Models/MachinePowerActionTask.php` — confirmer que la colonne `initiated_by` accepte une string arbitraire (pas de FK stricte vers User — les initiateurs peuvent être `user:42` ou `schedule:7`).
- [x] **1.4** Lire `resources/views/pages/parc/groups/[id]/index.blade.php` — localiser où insérer le panneau "Programmations" (après `batch-summary`, avant `machines-list` ? ou en accordéon DaisyUI en bas de page ?). Décision UX : **en bas de page, accordéon collapsed par défaut** pour ne pas surcharger.
- [x] **1.5** Vérifier que la migration existante `2026_03_16_100000_create_workstation_scheduled_actions_table.php` peut être remplacée sans casser la prod (la table est orpheline, aucune query). Décision : créer une **nouvelle migration** `2026_04_22_create_workstation_group_schedules_table.php` + `drop_workstation_scheduled_actions_table.php` (ordre strict — on drop l'ancienne car son schema est incompatible avec D3 : `day` VARCHAR vs `days_of_week` ARRAY).

### Tâche 2 — Migrations + modèles Eloquent (AC: 2, 5, 6, 9, 18, 20, 21, 22, 24)

- [x] **2.1** Créer migration `database/migrations/2026_04_22_100000_drop_workstation_scheduled_actions_table.php` :
  - `Schema::dropIfExists('workstation_scheduled_actions');` (idempotent — safe si déjà absente).
- [x] **2.2** Créer migration `database/migrations/2026_04_22_100001_create_workstation_group_schedules_table.php` :
  ```php
  Schema::create('workstation_group_schedules', function (Blueprint $table) {
      $table->id();
      $table->foreignId('workstation_group_id')->constrained('workstation_groups')->cascadeOnDelete();
      $table->enum('action', ['wake', 'shutdown']); // D5 : MVP 2 actions seulement
      // v0.2 — discriminant mode (D7)
      $table->enum('mode', ['recurring', 'one_shot'])->default('recurring');
      // Triplet récurrent (nullable si one_shot)
      if (DB::getDriverName() === 'pgsql') {
          $table->addColumn('smallint[]', 'days_of_week')->nullable(); // ARRAY PG, NULL si one_shot
      } else {
          $table->json('days_of_week')->nullable(); // fallback SQLite tests
      }
      $table->time('time_of_day')->nullable();
      $table->string('timezone', 64)->nullable();
      // One-shot (nullable si recurring)
      $table->timestampTz('run_at')->nullable();
      $table->timestampTz('completed_at')->nullable(); // renseigné après exécution d'un one_shot
      $table->boolean('enabled')->default(true);
      $table->unsignedBigInteger('created_by_user_id')->nullable();
      $table->foreign('created_by_user_id')->references('id')->on('users')->nullOnDelete();
      $table->timestamps();
      $table->index(['workstation_group_id', 'enabled']);
      $table->index(['mode', 'enabled']);
      $table->index(['enabled', 'time_of_day']);
      $table->index(['mode', 'enabled', 'completed_at', 'run_at'], 'wgs_one_shot_due_idx'); // couverture SELECT one_shot
  });

  // v0.2 D7 — Contrainte CHECK exclusivité récurrent / one_shot
  // (PostgreSQL ; SQLite tolère sans CHECK pour tests)
  if (DB::getDriverName() === 'pgsql') {
      DB::statement("
          ALTER TABLE workstation_group_schedules
          ADD CONSTRAINT wgs_mode_exclusivity CHECK (
              (mode = 'recurring' AND days_of_week IS NOT NULL AND time_of_day IS NOT NULL AND timezone IS NOT NULL AND run_at IS NULL)
              OR
              (mode = 'one_shot' AND run_at IS NOT NULL AND days_of_week IS NULL AND time_of_day IS NULL AND timezone IS NULL)
          )
      ");
  }
  ```
- [x] **2.3** Créer migration `database/migrations/2026_04_22_100002_create_workstation_group_schedule_runs_table.php` :
  ```php
  Schema::create('workstation_group_schedule_runs', function (Blueprint $table) {
      $table->id();
      $table->foreignId('schedule_id')->nullable()->references('id')->on('workstation_group_schedules')->nullOnDelete();
      $table->timestampTz('ran_at');
      $table->time('ran_for_time'); // pour idempotence AC3 (mm:ss du créneau)
      $table->date('ran_for_date'); // pour idempotence AC3 (protection double-fire same minute)
      $table->json('summary'); // {success_count, failed_count, skipped_count, task_ids, errors}
      $table->timestamps();
      $table->unique(['schedule_id', 'ran_for_date', 'ran_for_time'], 'wgsr_schedule_date_time_unique'); // idempotence
      $table->index('ran_at');
  });
  ```
- [x] **2.4** Créer `app/Models/WorkstationGroupSchedule.php` :
  - `use HasFactory; protected $fillable = ['workstation_group_id', 'action', 'mode', 'days_of_week', 'time_of_day', 'timezone', 'run_at', 'completed_at', 'enabled', 'created_by_user_id'];`
  - Casts : `'days_of_week' => 'array', 'time_of_day' => 'datetime:H:i:s', 'run_at' => 'datetime', 'completed_at' => 'datetime', 'enabled' => 'boolean', 'created_at' => 'datetime'`.
  - Constantes : `const MODE_RECURRING = 'recurring'; const MODE_ONE_SHOT = 'one_shot';`
  - Relations : `belongsTo(WorkstationGroup::class)`, `belongsTo(User::class, 'created_by_user_id')`, `hasMany(WorkstationGroupScheduleRun::class, 'schedule_id')`.
  - **Scopes (v0.2 D7)** :
    - `scopeRecurring($query)` → `$query->where('mode', self::MODE_RECURRING)`
    - `scopeOneShot($query)` → `$query->where('mode', self::MODE_ONE_SHOT)`
    - `scopeCompleted($query)` → `$query->whereNotNull('completed_at')`
    - `scopePending($query)` → `$query->whereNull('completed_at')` (utile pour one-shots non-encore-exécutés)
    - `scopeDueAt($query, Carbon $now)` → couvre les 2 modes : `$query->where('enabled', true)->where(fn($q) => $q->where('mode', self::MODE_RECURRING)->orWhere(fn($qq) => $qq->where('mode', self::MODE_ONE_SHOT)->whereNull('completed_at')->where('run_at', '<=', $now)))`. Le matching minute-courante pour récurrents se fait côté PHP via `isDueNow()` (car TIME + timezone par schedule, pas trivial à exprimer en SQL portable).
  - **Helpers** :
    - `isRecurring(): bool` et `isOneShot(): bool`
    - `isCompleted(): bool` — `return $this->completed_at !== null;`
    - `isEditable(): bool` — `return !($this->isOneShot() && $this->isCompleted());`
    - `isDueNow(Carbon $now): bool` — dispatch selon mode :
      - Si `recurring` : utilise `$now->setTimezone($this->timezone)`, compare `dayOfWeekIso` ∈ `days_of_week` ET heure dans la fenêtre minute courante.
      - Si `one_shot` : `return $this->run_at !== null && $this->completed_at === null && $this->run_at->lessThanOrEqualTo($now);`
    - `getRanForTimeForRun(Carbon $now): string` — retourne la clé d'idempotence `ran_for_time` : `time_of_day->format('H:i:s')` pour récurrent, `run_at->format('H:i:s')` pour one-shot.
    - `getRanForDateForRun(Carbon $now): string` — `$now->toDateString()` pour récurrent, `run_at->toDateString()` pour one-shot.
- [x] **2.5** Créer `app/Models/WorkstationGroupScheduleRun.php` :
  - `$fillable = ['schedule_id', 'ran_at', 'ran_for_time', 'ran_for_date', 'summary']`
  - Casts : `'ran_at' => 'datetime', 'ran_for_date' => 'date', 'summary' => 'array'`.
  - Relation : `belongsTo(WorkstationGroupSchedule::class, 'schedule_id')`.
- [x] **2.6** Créer factories `database/factories/WorkstationGroupScheduleFactory.php` + `WorkstationGroupScheduleRunFactory.php` pour les tests. **v0.2 D7** : `WorkstationGroupScheduleFactory` avec states :
  - `->recurring(array $daysOfWeek = [1,2,3,4,5], string $time = '08:30:00', string $tz = 'Europe/Paris')` (état par défaut)
  - `->oneShot(Carbon|string $runAt = '+1 hour')` — run_at futur par défaut, days_of_week / time_of_day / timezone = null
  - `->completedOneShot(Carbon|string $ranAt = '-1 hour')` — run_at passé, `completed_at = $ranAt`, `enabled = false` (pour tester l'affichage "Terminé" et l'idempotence AC22)

### Tâche 3 — Service `WorkstationGroupScheduleService` (AC: 1, 5, 6, 7, 8, 13, 18, 19, 20, 21, 22)

- [x] **3.1** Créer `app/Services/Parc/WorkstationGroupScheduleService.php` avec injection `WorkstationGroupService $groupService` (constructeur).
- [x] **3.2** Méthodes CRUD :
  - `createRecurring(int $groupId, string $action, array $daysOfWeek, string $timeOfDay, ?string $timezone = null, ?int $createdByUserId = null): WorkstationGroupSchedule` — valide action ∈ ['wake', 'shutdown'] (D5), valide 1 ≤ count(daysOfWeek) ≤ 7 + chaque jour ∈ [1..7], valide regex `^\d{2}:\d{2}(:\d{2})?$`. Persiste `mode='recurring'` + `run_at=null, completed_at=null`.
  - `createOneShot(int $groupId, string $action, Carbon|string $runAt, ?int $createdByUserId = null): WorkstationGroupSchedule` — valide action ∈ ['wake', 'shutdown'], valide `$runAt > now()` (sinon `\InvalidArgumentException("run_at doit être dans le futur")`). Persiste `mode='one_shot'` + `days_of_week=null, time_of_day=null, timezone=null, completed_at=null`.
  - `update(int $scheduleId, array $attributes): WorkstationGroupSchedule` — mutation partielle. **Refuse** si le schedule est un one-shot terminé : `if ($schedule->isOneShot() && $schedule->isCompleted()) throw new \DomainException("One-shot terminé non éditable")`. Validations identiques selon le mode.
  - `toggle(int $scheduleId): WorkstationGroupSchedule` — flip `enabled`. **Refuse** sur one-shot terminé (cohérence AC23).
  - `delete(int $scheduleId): void` — suppression (runs historiques préservés via `ON DELETE SET NULL`).
  - `cloneOneShot(int $scheduleId): WorkstationGroupSchedule` — duplicate d'un one-shot avec `run_at=null` (à renseigner par l'UI), `completed_at=null`, `enabled=true`. Source peut être terminée ou non.
- [x] **3.3** Méthode `executeDue(?Carbon $now = null): array` — gère récurrents + one-shots :
  - `$now ??= Carbon::now();`
  - Charger les candidats avec scope `dueAt` (SQL-level filter pour one-shots, in-memory filter pour récurrents via `isDueNow()`) :
    ```php
    $candidates = WorkstationGroupSchedule::dueAt($now)
        ->with('workstationGroup.workstations')
        ->get();
    $dueSchedules = $candidates->filter(fn($s) => $s->isDueNow($now));
    ```
  - Pour chaque `$schedule` :
    - **Garde idempotence** : `WorkstationGroupScheduleRun::where('schedule_id', $schedule->id)->where('ran_for_date', $schedule->getRanForDateForRun($now))->where('ran_for_time', $schedule->getRanForTimeForRun($now))->exists()` → skip si déjà.
    - Résoudre la liste à jour des machine IDs : `$machineIds = $schedule->workstationGroup->workstations->pluck('id')->all();` (D2 liveness).
    - Si `empty($machineIds)` : créer run `{success: 0, failed: 0, skipped: 0, errors: [{message: 'Group is empty'}]}` et **pour un one-shot** : marquer quand même `completed_at + enabled=false` (le one-shot a eu sa chance — même s'il n'a rien fait, il ne doit pas re-fire).
    - Appeler `$this->groupService->executeGroupMachinesAction($schedule->workstation_group_id, $machineIds, $schedule->action, 'schedule:' . $schedule->id)` → récupère `$result`.
    - Compter : `$successCount = count(filter results where code=202)`, `$skippedCount = count(filter results where code=409)`, `$failedCount = count(filter results where code NOT IN [202, 409])`.
    - **Transaction unique** (DB::transaction) :
      - Créer `WorkstationGroupScheduleRun` avec `{ran_at: $now, ran_for_date: ..., ran_for_time: ..., summary: {success_count, failed_count, skipped_count, task_ids: [...], errors: [...], drift_seconds?: <one_shot uniquement>}}`.
      - **Si `$schedule->isOneShot()`** : `$schedule->update(['completed_at' => $now, 'enabled' => false]);`
  - Retourner `['executed_count' => N, 'total_tasks_dispatched' => M, 'recurring_count' => Nr, 'one_shot_count' => No]` (utile pour les tests / logs).
- [x] **3.4** Méthode privée `pruneRuns(int $retentionDays = 30): int` — `WorkstationGroupScheduleRun::where('created_at', '<', now()->subDays($retentionDays))->delete();` retourne count.

### Tâche 3bis — FormRequest validation conditionnelle mode (AC: 19, 20) — v0.2 D7

- [x] **3bis.1** Créer `app/Http/Requests/Parc/StoreWorkstationGroupScheduleRequest.php` :
  - `rules()` conditionnelles selon `$this->input('mode')` :
    ```php
    $rules = [
        'mode' => ['required', Rule::in(['recurring', 'one_shot'])],
        'action' => ['required', Rule::in(['wake', 'shutdown'])],
        'enabled' => ['sometimes', 'boolean'],
    ];
    if ($this->input('mode') === 'recurring') {
        $rules['days_of_week'] = ['required', 'array', 'min:1', 'max:7'];
        $rules['days_of_week.*'] = ['integer', 'between:1,7'];
        $rules['time_of_day'] = ['required', 'regex:/^\d{2}:\d{2}(:\d{2})?$/'];
        $rules['timezone'] = ['required', 'string', 'max:64'];
        $rules['run_at'] = ['prohibited']; // v0.2 D7 exclusivité
    } elseif ($this->input('mode') === 'one_shot') {
        $rules['run_at'] = ['required', 'date', 'after:now'];
        $rules['days_of_week'] = ['prohibited'];
        $rules['time_of_day'] = ['prohibited'];
        $rules['timezone'] = ['prohibited'];
    }
    return $rules;
    ```
  - `messages()` FR : « La date d'exécution doit être dans le futur », « Le mode est invalide », « Au moins un jour de la semaine est requis », etc.
- [x] **3bis.2** Créer `app/Http/Requests/Parc/UpdateWorkstationGroupScheduleRequest.php` — mêmes règles, mais autorise la mutation partielle. Le service lève `DomainException` si one-shot terminé.
- [x] **3bis.3** Brancher les FormRequest sur les méthodes Livewire `saveSchedule()` via `$this->validate([...rules...])` ou via `$this->form->validate()` si on préfère un Form Object Livewire 3.

### Tâche 4 — Commande artisan `parc:execute-group-schedules` (AC: 2, 3, 14)

- [x] **4.1** Créer `app/Console/Commands/ExecuteWorkstationGroupSchedulesCommand.php` :
  ```php
  protected $signature = 'parc:execute-group-schedules';
  protected $description = 'Exécute les programmations horaires des WorkstationGroups dues au tick courant';

  public function handle(WorkstationGroupScheduleService $service): int
  {
      try {
          $result = $service->executeDue();
          $this->info("Schedules exécutés : {$result['executed_count']}, tasks dispatchées : {$result['total_tasks_dispatched']}");
          return Command::SUCCESS;
      } catch (\Throwable $e) {
          Log::error('ExecuteWorkstationGroupSchedulesCommand failed', ['exception' => $e->getMessage()]);
          $this->error($e->getMessage());
          return Command::FAILURE;
      }
  }
  ```
- [x] **4.2** Enregistrer dans `app/Console/Kernel.php::schedule()` après `controlhub:heartbeat` :
  ```php
  $schedule->command('parc:execute-group-schedules')
           ->everyMinute()
           ->withoutOverlapping(5) // lock 5min max
           ->runInBackground();
  ```
- [x] **4.3** Créer `app/Console/Commands/PruneWorkstationGroupScheduleRunsCommand.php` (signature `parc:prune-group-schedule-runs`) qui appelle `WorkstationGroupScheduleService::pruneRuns()`. Enregistrer dans `Kernel::schedule()` avec `->daily()->runInBackground()`.

### Tâche 5 — UI Livewire : panneau Programmations (AC: 1, 4, 5, 6, 7, 9, 10, 18, 23, 24)

- [x] **5.1** Créer partial `resources/views/pages/parc/groups/[id]/_partials/schedules-panel.blade.php` :
  - Composant Livewire SFC (`new class extends Component`) ou partial Blade simple consommant les propriétés de la page parente (décision : partial pur, la page parente porte les méthodes).
  - Accordéon DaisyUI `collapse` collapsed par défaut ("Programmations (2 actives, 0 désactivée)").
  - Bouton "Ajouter une programmation" (si `@can('computer.control')`) ouvre modale de création.
  - Tableau des programmations avec colonnes : Action (badge), Jours, Heure, Timezone (si ≠ Europe/Paris), Toggle, Actions (Modifier/Supprimer).
  - Section "Historique des exécutions" (accordéon interne paginé, N dernières par schedule, `->paginate(10)`).
- [x] **5.2** Enrichir `resources/views/pages/parc/groups/[id]/index.blade.php` avec propriétés Livewire :
  - `public array $schedules = [];` (chargée via computed `getSchedulesProperty()`)
  - `public ?int $editingScheduleId = null;`
  - `public string $formMode = 'recurring';` — **v0.2 D7** : discriminant UI toggle
  - `public string $formAction = 'wake';`
  - Récurrent : `public array $formDaysOfWeek = [];`, `public string $formTimeOfDay = '08:00';`, `public string $formTimezone = 'Europe/Paris';`
  - One-shot : `public ?string $formRunAt = null;` (format `YYYY-MM-DDTHH:MM` pour `<input type="datetime-local">`)
  - `public bool $formEnabled = true;`
  - `public bool $scheduleModalOpen = false;`
- [x] **5.3** Méthodes Livewire dans `index.blade.php` :
  - `openScheduleModal(?int $scheduleId = null)` — charge en édition (hydrate `$formMode` selon le schedule) ou ouvre formulaire vierge (`$formMode='recurring'` par défaut).
  - `toggleFormMode(string $mode)` — switch recurring ↔ one_shot, reset les champs de l'autre mode.
  - `saveSchedule()` — `$this->authorize('computer.control')`, validation conditionnelle via FormRequest :
    - Si `formMode='recurring'` : valide `formDaysOfWeek|required|array|min:1`, `formTimeOfDay|required|regex:/^\d{2}:\d{2}$/`, `formTimezone|required`, puis `$this->scheduleService->createRecurring(...)` ou `update(...)`.
    - Si `formMode='one_shot'` : valide `formRunAt|required|date|after:now`, puis `$this->scheduleService->createOneShot(...)` ou `update(...)` (si non terminé).
    - Catch `\DomainException` (one-shot terminé non éditable) → `toastError`.
  - `toggleSchedule(int $scheduleId)` — `$this->authorize(...)`, call `toggle($id)`, toast. Catch DomainException si one-shot terminé.
  - `deleteSchedule(int $scheduleId)` — `$this->authorize(...)`, call `delete($id)`, toast.
  - `cloneOneShot(int $scheduleId)` — `$this->authorize(...)`, call `cloneOneShot($id)`, pré-remplit la modale en édition avec nouveau schedule `run_at=null`, toast.
  - `getSchedulesProperty()` — returns `WorkstationGroupSchedule::where('workstation_group_id', $this->groupId)->orderByRaw("CASE WHEN completed_at IS NOT NULL THEN 2 WHEN mode='one_shot' THEN 1 ELSE 0 END")->orderBy('time_of_day')->orderBy('run_at')->orderByDesc('completed_at')->get()` (tri AC24).
- [x] **5.4** Créer modale de formulaire (inline Blade dans `schedules-panel.blade.php` ou composant molecule dédié) avec **toggle mode** en tête (v0.2 D7) :
  - **Toggle radio en tête** : « Récurrent » | « Date unique » (`wire:click="toggleFormMode('recurring')"` / `'one_shot'`, ou `wire:model.live="formMode"` sur un radio-tab DaisyUI).
  - **Section conditionnelle récurrente** (`@if($formMode === 'recurring')`) :
    - 7 checkboxes jours (lun, mar, mer, jeu, ven, sam, dim) → binding `wire:model="formDaysOfWeek"` array de SMALLINT.
    - Input `type="time"` → binding `wire:model="formTimeOfDay"`.
    - Select timezone (optionnel en MVP, prérempli Europe/Paris, masqué si locked).
  - **Section conditionnelle one-shot** (`@elseif($formMode === 'one_shot')`) :
    - Input `type="datetime-local"` → binding `wire:model="formRunAt"` (min = `now()->format('Y-m-d\\TH:i')` — pas de date passée).
    - Hint UI : « L'action sera exécutée une seule fois à cette date/heure, puis la programmation sera automatiquement désactivée. »
  - Radio "Action" : Allumage (wake) / Extinction (shutdown) — D5 pas d'autres options. (**Commun** aux 2 modes.)
  - Toggle enabled.
  - Boutons Enregistrer / Annuler.
- [x] **5.5** Gate `@can('computer.control')` sur tous les boutons de mutation (Ajouter, Modifier, Supprimer, Toggle, Dupliquer). Lecture seule pour les non-admins.
- [x] **5.6** Traduction FR des jours : helper `@php $daysLabels = [1=>'Lun', 2=>'Mar', 3=>'Mer', 4=>'Jeu', 5=>'Ven', 6=>'Sam', 7=>'Dim']; @endphp` ; helper `formatDaysBadge` pour afficher "Lun–Ven" si pattern ISO 1-5, sinon joindre par espaces.
- [x] **5.7** **Affichage liste mixte (v0.2 D7 / AC24)** :
  - Ligne récurrent : badge bleu « Récurrent » + « Lun–Ven · 08:30 » + toggle enabled + boutons Modifier/Supprimer.
  - Ligne one-shot futur : badge violet « Date unique » + « 12 mai 2026 · 07:45 » + toggle enabled + boutons Modifier/Supprimer.
  - Ligne one-shot terminé : badge gris « Terminé » + « Exécuté le 27 avril 2026 » + bouton Dupliquer + bouton Supprimer (pas de Modifier, pas de Toggle).
  - Utiliser helper `$schedule->isEditable()` pour conditionner le rendu des boutons.

### Tâche 6 — Tests unitaires `WorkstationGroupScheduleService` (AC: 13, 25)

- [x] **6.1** Créer `tests/Unit/Services/Parc/WorkstationGroupScheduleServiceTest.php` avec `DatabaseTransactions` + helper `createTablesIfNeeded` (pattern 4.2/4.3) incluant `workstation_group_schedules`, `workstation_group_schedule_runs`, `workstations`, `workstation_groups`, `workstation_group_workstation`, `machine_power_action_tasks`, `machine_boot_logs`.
- [x] **6.2** Les 13 tests listés en AC13 **+ 10 tests one-shot listés en AC25** avec `Queue::fake()` + `Carbon::setTestNow()` + assertions DB. **Total : 23 tests unit service.**
- [x] **6.3** `php artisan test --filter=WorkstationGroupScheduleServiceTest` → 23/23 verts.

### Tâche 7 — Tests feature commande artisan (AC: 14, 21, 22)

- [x] **7.1** Créer `tests/Feature/Console/ExecuteGroupSchedulesCommandTest.php` :
  - `Queue::fake()`, `Artisan::call('parc:execute-group-schedules')`, assertions `Queue::assertPushed(DispatchMachinePowerActionJob::class, 3)`.
  - Test idempotence : appeler la commande 2× de suite dans la même minute → `WorkstationGroupScheduleRun` créé 1 seule fois.
- [x] **7.2** 5 tests AC14 **+ 2 tests one-shot** (`test_command_dispatches_due_one_shot_and_marks_completed`, `test_command_does_not_refire_completed_one_shot_on_next_tick`). **Total : 7 tests.**
- [x] **7.3** Cible : 7/7 verts.

### Tâche 8 — Tests feature Livewire panneau (AC: 15, 18, 19, 23, 24, 26)

- [x] **8.1** Créer `tests/Feature/Livewire/Parc/GroupSchedulesPageTest.php` avec `MocksAdminUser` trait (décalque 4.3) + `Gate::before` en setUp + `Queue::fake()`.
- [x] **8.2** Les 10 tests listés en AC15 **+ 6 tests one-shot listés en AC26**. **Total : 16 tests Feature Livewire.**
- [x] **8.3** Cible : 16/16 verts.
- [x] **8.4** Valider aucune régression sur `GroupShowPageTest` (13 tests 4.3) : `php artisan test --filter='GroupShowPageTest|GroupSchedulesPageTest'`.

### Tâche 9 — Documentation (AC: 16)

- [x] **9.1** Compléter `docs/domains/parc.md` — section "Programmations (story 4.4)" (diagramme Mermaid, flow, représentation horaire, actions MVP, rétention).
- [x] **9.2** Créer `docs/qa/4-4-e2e-manual.md` (checklist E2E VM — 9 étapes AC17).

### Tâche 10 — Test E2E sur VM dev (AC: 17)

- [ ] **10.1** Exécution manuelle sur VM `192.168.122.50` via `ssh -i ~/.ssh/id_se4fs_vm root@192.168.122.50`.
- [ ] **10.2** Notes dans Completion Notes : timestamps réels observés, vérif que le cron système tick (`sudo tail -f /var/log/syslog | grep CRON`), vérif `workstation_group_schedule_runs` contient les bons summary, vérif bascule heure été/hiver (à simuler via modification timezone temporaire ou test automatisé AC11).
- [ ] **10.3** Après sync code VM : `php artisan migrate`, `php artisan config:cache`, vérifier que `php artisan schedule:list` affiche `parc:execute-group-schedules` et `parc:prune-group-schedule-runs`.

---

## Dev Notes

### Contraintes architecturales (non négociables)

- **Services** : `WorkstationGroupScheduleService` dans `app/Services/Parc/` (cohérence domaine). Injection DI du `WorkstationGroupService` existant (pas de duplication).
- **Pas de duplication de logique dispatch** : le service scheduler appelle `WorkstationGroupService::executeGroupMachinesAction()` tel quel. Si cette méthode évolue (4.3 review), la cron bénéficie automatiquement des évolutions.
- **1 tick = 1 exécution par créneau** : le `unique` index `(schedule_id, ran_for_date, ran_for_time)` + la garde `exists()` côté service sont les 2 couches d'idempotence. **Les deux sont nécessaires** : la garde `exists()` pour éviter de dispatcher 2× dans un même tick, l'index DB pour éviter les races avec `withoutOverlapping`.
- **Timezone-aware** : toutes les comparaisons se font avec `Carbon::now($schedule->timezone)`. **Jamais** `now()->format('H:i')` (global app timezone) pour comparer à `$schedule->time_of_day` — risque DST.
- **Pas d'Eloquent direct dans les composants Livewire** sauf lecture triviale (computed `getSchedulesProperty()` tolérée — pattern 4.3).
- **Toasts via `WithToasts`** uniquement.
- **Modale réutilisable** : si besoin, réutiliser `confirm-modal` (`resources/views/components/molecules/confirm-modal.blade.php`) pour les confirms simples. Pour le formulaire complet, créer une modale inline dans le partial.
- **Permission Spatie** : `computer.control` en Gate serveur-side (`$this->authorize(...)`) + `@can` Blade (double couche).
- **Scheduler** : `->withoutOverlapping(5)` pour protéger contre un run qui dépasserait la minute (ex. dispatch lent).

### Architecture scheduler → workers (flux `tick → enqueue → worker`)

**Clarification demandée par Henri (v0.2)** : la commande artisan `parc:execute-group-schedules` **ne fait pas le boulot réseau elle-même**. Elle est **juste le tick scheduler** qui enqueue des jobs pour les **workers habituels**.

```
┌──────────────────────────────────────────────────────────────────────────┐
│  cron système VM (tick 1 min — /etc/cron.d/laravel, déjà en prod)        │
│  * * * * * cd /var/www/sambaedu-reload && php artisan schedule:run       │
└──────────────────────────────────────────────────────────────────────────┘
                                 │
                                 ▼
┌──────────────────────────────────────────────────────────────────────────┐
│  php artisan schedule:run (Laravel Scheduler)                             │
│  lit Kernel::schedule() et fire les commandes dont l'expression match    │
└──────────────────────────────────────────────────────────────────────────┘
                                 │
                                 ▼
┌──────────────────────────────────────────────────────────────────────────┐
│  parc:execute-group-schedules  (commande artisan NOUVELLE — 4.4)          │
│  ExecuteWorkstationGroupSchedulesCommand::handle()                        │
│  - léger : 1 SELECT DB + N enqueue                                        │
│  - PAS d'I/O réseau (pas de WOL, pas de SSH, pas de HTTP)                 │
└──────────────────────────────────────────────────────────────────────────┘
                                 │
                                 ▼
┌──────────────────────────────────────────────────────────────────────────┐
│  WorkstationGroupScheduleService::executeDue(now)                         │
│  ├─ SELECT schedules WHERE enabled=true                                   │
│  │         AND (mode='recurring' OR one_shot_due)                         │
│  ├─ filtre PHP isDueNow() (récurrent minute-match + DST)                  │
│  └─ POUR CHAQUE schedule dû :                                             │
│       ├─ garde idempotence : exists WGScheduleRun(schedule,date,time) ?   │
│       ├─ résoudre machines du groupe (D2 liveness)                        │
│       ├─ WorkstationGroupService::executeGroupMachinesAction(             │
│       │      groupId, machineIds, action,                                 │
│       │      initiatedBy: 'schedule:' . $scheduleId                       │
│       │  )                                                                 │
│       │    └─ filtre 409 (machines déjà en action — D4)                   │
│       │    └─ POUR CHAQUE machine : ENQUEUE DispatchMachinePowerActionJob │
│       │         └─ queue : laravel-queue-general (pas nouvelle queue)     │
│       ├─ créer WorkstationGroupScheduleRun (audit + idempotence DB)       │
│       └─ si one_shot : UPDATE completed_at + enabled=false (v0.2 D7)     │
└──────────────────────────────────────────────────────────────────────────┘
                                 │
                                 ▼ (jobs dans la queue)
┌──────────────────────────────────────────────────────────────────────────┐
│  laravel-queue-general.service  (worker SYSTEMD EXISTANT depuis 4.2)      │
│  php artisan queue:work redis --queue=laravel-queue-general               │
│  ── packagé via scripts/update.sh ──                                      │
│                                                                            │
│  POUR CHAQUE DispatchMachinePowerActionJob dépilé :                        │
│  DispatchMachinePowerActionJob::handle()                                   │
│   ├─ MachinePowerActionTask::STATUS_DISPATCHED                             │
│   ├─ MachinePowerService::wake($machine)     // WOL réseau                 │
│   ├─ OU MachinePowerService::shutdown($machine) // SSH soft-shutdown       │
│   ├─ MachineBootLog::create(...)                                           │
│   └─ MachinePowerActionTask::STATUS_COMPLETED / FAILED                     │
└──────────────────────────────────────────────────────────────────────────┘
```

**Conséquences opérationnelles :**
- **Pas de nouveau worker à déployer** — le worker `laravel-queue-general.service` (déjà présent, géré par `scripts/update.sh`) consomme les jobs cron exactement comme les jobs d'actions manuelles (4.3).
- **Pas de nouvelle queue** — `config('parc.queue_connection')` pointe déjà sur `laravel-queue-general`.
- **Pas de nouveau process supervisé côté systemd** — le scheduler utilise le cron système déjà actif.
- **Attribution d'audit** : le champ `initiated_by` des `MachinePowerActionTask` distingue `user:<id>` (action manuelle) vs `schedule:<id>` (cron). C'est la seule marque qui différencie une action manuelle d'une action cron au niveau task. Utile pour reporting (« combien d'actions ont été déclenchées automatiquement cette semaine ? »).
- **Load profile du tick** : 1 SELECT avec `with('workstationGroup.workstations')` (eager-loaded — 1 requête + 2 jointures) + N `dispatch()` (N = total machines de tous les schedules dûs à cette minute). Très léger : typiquement < 50ms pour < 10 schedules. Pas de risque de dépasser la minute → `withoutOverlapping(5)` est une ceinture+bretelles.

### Code existant à réutiliser (anti-réinvention)

| Besoin | Fichier/Classe | Notes |
|---|---|---|
| Dispatch action async par machine | `App\Jobs\DispatchMachinePowerActionJob` | Réutilisé via `WorkstationGroupService::executeGroupMachinesAction` |
| Service power | `App\Services\Parc\WorkstationGroupService::executeGroupMachinesAction` | Pattern async 4.3 — à réutiliser tel quel |
| Filtre idempotence (409) | `WorkstationGroupService::dispatchAsyncActionForMachines()` | Fait déjà le filtre des `MachinePowerActionTask::ACTIVE_STATUSES` |
| Table suivi tasks | `App\Models\MachinePowerActionTask` | Créée par `DispatchMachinePowerActionJob` |
| Pattern scheduler artisan | `app/Console/Kernel.php` (5 commandes) + `ControlHubHeartbeatCommand` | Référence de pattern |
| Permission Spatie | `SambaPermission::ComputerControl = 'computer.control'` | Déjà en prod, 2 rôles |
| Policy (optionnelle) | `WorkstationGroupPolicy` | Possibilité d'ajouter `manageSchedules($user, $group)` si besoin — ou gate simple |
| Timezone Laravel | `config('app.timezone')` = `Europe/Paris` | Confirmé `config/app.php:69` |
| Trait toasts | `App\Components\Traits\WithToasts` | Obligatoire |
| Pattern UI modale | `resources/views/components/molecules/confirm-modal.blade.php` | Pour le delete confirm |
| Tests pattern async | `tests/Feature/Livewire/Parc/GroupShowPageTest.php` | createTablesIfNeeded + MocksAdminUser + Queue::fake |
| Pattern commande artisan testable | `tests/Feature/Console/` (si existe) + `ControlHubHeartbeatCommandTest` | Référence |

### File List prévisionnel

```
# À CRÉER
database/migrations/2026_04_22_100000_drop_workstation_scheduled_actions_table.php
database/migrations/2026_04_22_100001_create_workstation_group_schedules_table.php
    # v0.2 : colonnes mode + run_at + completed_at + CHECK constraint exclusivité (D7)
database/migrations/2026_04_22_100002_create_workstation_group_schedule_runs_table.php

app/Models/WorkstationGroupSchedule.php
    # v0.2 : constantes MODE_*, scopes recurring/oneShot/completed/pending/dueAt,
    #        helpers isRecurring/isOneShot/isCompleted/isEditable, isDueNow dispatch par mode
app/Models/WorkstationGroupScheduleRun.php

database/factories/WorkstationGroupScheduleFactory.php
    # v0.2 : states recurring() / oneShot() / completedOneShot()
database/factories/WorkstationGroupScheduleRunFactory.php

app/Services/Parc/WorkstationGroupScheduleService.php
    # v0.2 : createRecurring / createOneShot / cloneOneShot séparés ;
    #        executeDue() gère les 2 modes + completed_at + enabled=false pour one-shot ;
    #        DomainException si update sur one-shot terminé

app/Http/Requests/Parc/StoreWorkstationGroupScheduleRequest.php    # v0.2 NOUVEAU
app/Http/Requests/Parc/UpdateWorkstationGroupScheduleRequest.php   # v0.2 NOUVEAU
    # Validation conditionnelle selon $this->input('mode') + after:now pour run_at

app/Console/Commands/ExecuteWorkstationGroupSchedulesCommand.php
app/Console/Commands/PruneWorkstationGroupScheduleRunsCommand.php

resources/views/pages/parc/groups/[id]/_partials/schedules-panel.blade.php
    # v0.2 : toggle mode en tête de modale, section conditionnelle recurring/one_shot,
    #        colonne "Déclenchement" mixte, badges "Récurrent" / "Date unique" / "Terminé",
    #        bouton Dupliquer pour one-shots terminés

tests/Unit/Services/Parc/WorkstationGroupScheduleServiceTest.php
    # v0.2 : 23 tests (13 original + 10 one-shot AC25)
tests/Feature/Console/ExecuteGroupSchedulesCommandTest.php
    # v0.2 : 7 tests (5 original + 2 one-shot AC14 étendu)
tests/Feature/Livewire/Parc/GroupSchedulesPageTest.php
    # v0.2 : 16 tests (10 original + 6 one-shot AC26)

docs/qa/4-4-e2e-manual.md
    # v0.2 : +1 scénario one-shot (étape 10 de la checklist)

# À MODIFIER
app/Console/Kernel.php
    # +2 schedules : parc:execute-group-schedules everyMinute + parc:prune-group-schedule-runs daily

resources/views/pages/parc/groups/[id]/index.blade.php
    # v0.2 : +12 propriétés Livewire (schedules form state + formMode + formRunAt + scheduleModalOpen)
    # v0.2 : +7 méthodes Livewire (openScheduleModal, toggleFormMode, saveSchedule, toggleSchedule,
    #         deleteSchedule, cloneOneShot, getSchedulesProperty)
    # injection WorkstationGroupScheduleService via constructor
    # include partial schedules-panel

docs/domains/parc.md
    # section "Programmations (story 4.4)" — v0.2 : sous-section "Mode one-shot"
```

### Pitfalls connus (leçons 4.2/4.3)

- **APP_KEY en setUp test** : `config(['app.key' => …])` obligatoire (pattern `MachineShowPageTest`).
- **`withoutVite()`** dans les tests Feature Livewire.
- **`DatabaseTransactions` + `createTablesIfNeeded`** : pas de `RefreshDatabase`. Étendre avec les 2 nouvelles tables.
- **`Queue::fake()`** : indispensable pour éviter d'exécuter `DispatchMachinePowerActionJob` réellement. `Queue::assertPushed(DispatchMachinePowerActionJob::class, N)` pour vérifier.
- **`Carbon::setTestNow()` + tearDown reset** pour tests temporels.
- **Observer `WorkstationGroupObserver`** peut dispatcher `WorkstationGroupAdSyncJob` à la création — utiliser `Queue::assertNotPushed(DispatchMachinePowerActionJob::class)` (pas `assertNothingPushed()`) pour tolérer le job AD sync.
- **PostgreSQL ARRAY `smallint[]`** : le driver Eloquent standard ne supporte pas nativement. Utiliser `DB::statement(...)` dans la migration OU utiliser `json` pour portabilité (SQLite dev + tests). **Recommandation** : garder ARRAY PG pour la prod, tests en SQLite supportent `json` via casts. Voir `app/Models/WorkstationGroup.php` si déjà des arrays DB (vérifier pattern existant).
- **Timezone DST** : `Carbon::now('Europe/Paris')->format('H:i')` donne la bonne heure locale. Bug classique : comparer `$schedule->time_of_day` (TIME sans timezone) à `now()->utc()->format('H:i')` → décalage 1h–2h. **Toujours** `Carbon::now($schedule->timezone)`.
- **`withoutOverlapping(5)`** : lock de 5 minutes max. Si un run crashe et laisse le lock, artisan le libère après 5 min. **Pas d'impact** sur la santé du système.
- **Test commande artisan** : `Artisan::call('parc:execute-group-schedules')` + asserts — simple. Ne pas mocker `Schedule::command()` — tester la commande directement.

### Représentation horaire — choix `days_of_week` ARRAY SMALLINT[]

ISO 8601 day of week : 1=Lundi … 7=Dimanche (compatible `Carbon::dayOfWeekIso`). Exemple `[1,2,3,4,5]` = jours ouvrés.

- **Stockage PostgreSQL** : `SMALLINT[]` natif. Requête de sélection via Eloquent cast `'array'`.
- **Stockage SQLite (tests)** : fallback `JSON` via cast `'array'`. Même API côté modèle.
- **Validation** : `count(daysOfWeek) >= 1 && count(daysOfWeek) <= 7 && array_diff($daysOfWeek, [1..7]) === []`.

### Timezone

- `config('app.timezone') = 'Europe/Paris'` (vérifié ligne 69 de `config/app.php`).
- Chaque schedule a son `timezone` propre (défaut `Europe/Paris`). Permet à un futur déploiement multi-sites (collèges outre-mer) de cohabiter sans régression.
- Comparaison horaire dans `isDueNow()` :
  ```php
  public function isDueNow(Carbon $now): bool
  {
      $localNow = $now->copy()->setTimezone($this->timezone);
      if (!in_array($localNow->dayOfWeekIso, $this->days_of_week, true)) {
          return false;
      }
      $schedTime = Carbon::parse($this->time_of_day->format('H:i:s'), $this->timezone);
      $schedTime->setDate($localNow->year, $localNow->month, $localNow->day);
      return $localNow->greaterThanOrEqualTo($schedTime) && $localNow->lessThan($schedTime->copy()->addMinute());
  }
  ```

### Idempotence multi-couches

1. **Garde `exists()` côté service** (`WorkstationGroupScheduleService::executeDue`) : skip si run existe déjà pour ce `(schedule_id, date, time)`.
2. **Index `unique` DB** (`wgsr_schedule_date_time_unique`) : garantit zéro doublon même en cas de race entre 2 ticks simultanés.
3. **`withoutOverlapping(5)`** côté Kernel : garantit qu'un seul process `parc:execute-group-schedules` tourne à la fois.
4. **Filtre 409 côté `WorkstationGroupService`** : si une machine a déjà une action en cours (4.3 D4), elle est skip avec code 409 → remontée en `skipped_count` dans le summary du run.

### Integration avec `ParcController::massAction` (endpoint legacy D5 de 4.3)

- **Non touché par 4.4**. L'endpoint JSON synchrone `POST /admin/parcs/{parc}/mass-action` reste dédié aux scripts externes et n'a pas besoin de scheduling (le legacy appelait `action_cron.php` via `curl` — mais notre cron est désormais Laravel-native).
- **Migration off-legacy** : si un opérateur utilisait l'entrée MySQL `actions` legacy, il devra recréer ses crons dans l'UI 4.4. Pas de migration automatique (la structure `day` VARCHAR vs `days_of_week` ARRAY n'est pas trivialement convertible et la base legacy est MySQL distincte). **Noter en follow-up `[PROD]`** : documenter la migration manuelle dans `docs/qa/4-4-e2e-manual.md`.

### Permissions Spatie (AC10)

- `SambaPermission::ComputerControl = 'computer.control'` déjà mappée aux rôles `ComputerAdmin` + `SuperAdmin`.
- **Pas de nouvelle permission** : créer/modifier/supprimer un cron = même droit que lancer l'action manuellement. Cohérent avec la philosophie "un cron est une action manuelle différée dans le temps".
- `WorkstationGroupPolicy` existe — possibilité d'ajouter `manageSchedules(AuthUser $user, WorkstationGroup $group)` pour granularité future, mais pas nécessaire en MVP (gate simple `computer.control` suffit).

### Audit legacy — mapping des concepts

| Legacy | Laravel 4.4 | Note |
|---|---|---|
| `sambaedu/parcs/action_cron.php` (cron tick 15min) | `app/Console/Commands/ExecuteWorkstationGroupSchedulesCommand` (everyMinute) | Tick 1 min plus fin → résolution meilleure |
| Table MySQL `actions(parc, action, jour, heure)` | Table PG `workstation_group_schedules` | Schema richifié (ARRAY jours + timezone + audit) |
| `jour` ∈ {d,l,ma,me,j,v,s,all} | `days_of_week` SMALLINT[1..7] (ISO 8601) | Support multi-jours par règle |
| `start_parc($config, $action, $row['parc'], true)` | `WorkstationGroupService::executeGroupMachinesAction($groupId, $machineIds, $action, 'schedule:' . $id)` | Pattern async propre |
| `get_ecowatt($config) > 1` bypass | Hors scope MVP | Peut revenir en option backlog |
| `pronote_api_passwd` (salle_state) | Hors scope MVP | Peut revenir en option backlog |
| Pas d'historique DB | Table `workstation_group_schedule_runs` | Amélioration majeure vs legacy (qui n'avait que l'email) |

### Previous Story Intelligence

- **4.2 (done)** : pattern `DispatchMachinePowerActionJob` + `MachinePowerActionTask` + machine à états `restart_phase` — **ne pas toucher**, réutilisation intégrale.
- **4.3 (review, branche `batchactions`)** : pattern `WorkstationGroupService::executeGroupMachinesAction` async + filtre idempotence 409 + contrat typé `{action, requested_count, success_count, failed_count, results[]}` — **réutilisation intégrale**. Le cron appelle exactement cette méthode.
- **Corrections review 4.3** : 10 problèmes laissés en attente (cf. `_bmad-output/codeReviews/4-3.md`). Certaines peuvent impacter 4.4 si touchant le contrat public. **Avant merge 4.4 : synchroniser et vérifier que 4.3 est en `done`.**
- **Pattern tests Feature Livewire Parc** (décalque 4.2/4.3) : `MocksAdminUser` trait + `Gate::before` setup + `Queue::fake()` + `createTablesIfNeeded()` + assertions DB + `assertDispatched('toastMagic', …)`.
- **Pas de refonte UI** : on ajoute un panneau "Programmations" à la page existante `/parc/groups/{id}`, pas une nouvelle route. Cohérent avec la philosophie "tout ce qui concerne un groupe tient sur la page du groupe".

### Project Structure Notes

- Filesystem-based routing respecté : panneau dans `_partials/` du groupe.
- Services Parc dans `app/Services/Parc/` (convention).
- Commandes artisan dans `app/Console/Commands/`.
- Migrations datées `2026_04_22_10000x_*.php` (ordre strict : drop ancienne → create schedules → create runs).
- Tests : Unit dans `tests/Unit/Services/Parc/`, Feature Livewire dans `tests/Feature/Livewire/Parc/`, Feature Console dans `tests/Feature/Console/`.
- Documentation QA : `docs/qa/4-4-e2e-manual.md`.

### Tests standards du projet

- `Tests\TestCase` + `DatabaseTransactions` + helper `createTablesIfNeeded()` (pattern 4.2/4.3). Ne **pas** utiliser `RefreshDatabase`.
- `Queue::fake()` systématique dans les tests Feature ou Unit dispatchant des jobs.
- `Carbon::setTestNow(Carbon::parse('2026-04-27 08:30:00', 'Europe/Paris'))` pour simuler un tick + `Carbon::setTestNow()` en tearDown.
- `Artisan::call('parc:execute-group-schedules')` pour tester la commande directement.
- `Livewire::test('pages::parc.groups.[id].index', ['id' => $group->id])` pour tester le composant SFC.
- Mocks via `$this->mock(WorkstationGroupService::class)` ou `$this->mock(WorkstationGroupScheduleService::class)` selon la couche testée.

### References

- [Source: _bmad-output/planning-artifacts/epics.md#Story 4.4 (ligne 1457)] — ACs originaux
- [Source: _bmad-output/planning-artifacts/architecture.md] — Préoccupations transversales (ControlHubTasks vs local queue)
- [Source: _bmad-output/planning-artifacts/idempotency.md] — pattern idempotence général
- [Source: _bmad-output/implementation-artifacts/4-2-actions-unitaires-machine-feedback-readiness.md] — pattern async + machine à états + tests Feature Livewire
- [Source: _bmad-output/implementation-artifacts/4-3-actions-batch-sur-un-workstationgroup.md] — pattern `executeGroupMachinesAction` async + idempotence multi-couches + tests pattern
- [Source: sambaedu/parcs/action_cron.php:1-110] — référence legacy (cron 15min + SELECT actions + start_parc)
- [Source: sambaedu-reload/database/migrations/2026_03_16_100000_create_workstation_scheduled_actions_table.php] — migration orpheline (à drop)
- [Source: sambaedu-reload/app/Services/Parc/WorkstationGroupService.php] — service réutilisé
- [Source: sambaedu-reload/app/Jobs/DispatchMachinePowerActionJob.php] — job réutilisé
- [Source: sambaedu-reload/app/Models/MachinePowerActionTask.php] — modèle réutilisé
- [Source: sambaedu-reload/app/Console/Kernel.php] — 5 schedules existants (pattern à suivre)
- [Source: sambaedu-reload/config/app.php:69] — timezone `Europe/Paris`
- [Source: sambaedu-reload/app/Enums/SambaPermission.php:26] — `computer.control`
- [Source: sambaedu-reload/app/Enums/SambaRole.php:69-87] — rôles `ComputerAdmin` + `SuperAdmin`
- [Source: sambaedu-reload/resources/views/pages/parc/groups/[id]/index.blade.php] — page à enrichir
- [Source: sambaedu-reload/resources/views/components/molecules/confirm-modal.blade.php] — modale réutilisable
- [Source: sambaedu-reload/app/Components/Traits/WithToasts.php] — trait obligatoire

---

## Dépendances

### Amont (bloquantes)

- **Story 4.2** : ✅ `done` (commit `4c9791d`) — pattern async + `MachinePowerActionTask`. Disponible.
- **Story 4.3** : 🔄 `review` — fournit `WorkstationGroupService::executeGroupMachinesAction()` async + contrat typé + filtre 409.
  - ⚠️ **Blocage merge** : **4.3 doit être passée à `done` avant que 4.4 puisse être merge**. En pratique, le dev de 4.4 peut commencer en rebasant sur la branche `batchactions` de 4.3.
  - Si 4.3 évolue en review (ex. nouveau champ dans `results[]`), 4.4 doit se rebase.

### Aval

- Aucune story bloquée par 4.4 dans l'Epic 4 actuel. 4.5/4.6 sont déjà done, 4.7/4.8 en review/done.

### Permissions / rôles

- `SambaPermission::ComputerControl` déjà mappée (4.2/4.3) — aucune nouvelle migration seeder.

### Infrastructure prod

- **Scheduler Laravel déjà actif** sur la VM (via cron système `* * * * * php artisan schedule:run`). Vérifier avec `sudo systemctl status cron` et `php artisan schedule:list`.
- **Worker queue `laravel-queue-general.service`** déjà packagé (scripts/update.sh) et tournant depuis 4.2. Aucune action prod supplémentaire.
- **Migration** : `php artisan migrate` à lancer manuellement sur la VM après sync code (crée `workstation_group_schedules` + `workstation_group_schedule_runs`, drop `workstation_scheduled_actions`).

---

## Dev Agent Record

### Agent Model Used

claude-opus-4-7 (1M context)

### Debug Log References

Commandes clés lancées pendant l'implémentation :

- `composer dump-autoload` — régénération nécessaire après bascule de branche (autoload ciblait `batchactions/`).
- `php artisan test --filter=WorkstationGroupScheduleServiceTest` → 23/23 ✅ (67 assertions)
- `php artisan test --filter=ExecuteGroupSchedulesCommandTest` → 7/7 ✅ (17 assertions)
- `php artisan test --filter=GroupSchedulesPageTest` → 16/16 ✅ (43 assertions)
- `php artisan test --filter='WorkstationGroupServicePowerActionTest|WorkstationServicePowerActionTest|MachinePowerServiceTest|MachineShowPageTest|GroupShowPageTest'` → 58/58 ✅ (non-régression power 4.2/4.3 intacte)
- `php artisan test --filter=KernelScheduleTest` → 4/4 ✅ (2 nouveaux tests schedule 4-4 + 2 existants)
- Cumul story 4-4 + power : **104/104 tests verts** (369 assertions).
- `php -l` sur tous les fichiers créés → no syntax errors.

### Completion Notes List

**Livraison complète** — 46 nouveaux tests verts (23 unit + 7 console + 16 Livewire) + 2 tests KernelScheduleTest ajoutés (couverture registration scheduler) + 0 régression sur les 58 tests power existants. Total cumulé : **104/104 tests verts** dans le scope power + schedule.

**Décisions D1–D7 respectées intégralement** :
- D1 : Laravel Scheduler + commande `parc:execute-group-schedules` everyMinute + `withoutOverlapping(5)` + tick → enqueue → worker (pas de nouveau worker déployé).
- D2 : résolution liveness machines via `$group->workstations` à chaque tick (pas de snapshot). Test dédié `test_execute_due_resolves_machines_at_tick_time_not_creation_time`.
- D3 : représentation `days_of_week` ARRAY SMALLINT[] (PG) / JSON (SQLite fallback), ISO 8601, + `time_of_day` TIME + `timezone` VARCHAR.
- D4 : collision manuelle → skip silencieux via filtre 409 hérité de `WorkstationGroupService::executeGroupMachinesAction`. Test `test_execute_due_counts_skipped_machines_already_running`.
- D5 : actions MVP limitées à `wake` + `shutdown` (CHECK DB + constant modèle + FormRequest + Blade radio).
- D6 : historique `workstation_group_schedule_runs` avec summary JSONB, rétention 30j via commande `parc:prune-group-schedule-runs` daily.
- D7 : mode `recurring` | `one_shot` discriminant + CHECK DB exclusivité + FormRequest conditionnel + auto-completion one-shot (enabled=false + completed_at dans même transaction que run) + UI toggle + bouton Dupliquer.

**Point d'attention — extension `executeGroupMachinesAction`** :

La signature de `WorkstationGroupService::executeGroupMachinesAction` a été étendue avec un paramètre optionnel `?string $initiatedBy = null` pour permettre au scheduler de tracer l'origine des tasks (`'schedule:<id>'` vs `'user:<id>'`). **Backward-compat intégrale** — toutes les signatures précédentes (sans `$initiatedBy`) continuent de fonctionner. Les 58 tests power existants passent sans modification.

**Point d'attention — tests `GroupShowPageTest`** :

Le helper `createTablesIfNeeded` du test existant a été étendu pour créer les 2 nouvelles tables `workstation_group_schedules` + `workstation_group_schedule_runs`. Sans cela, le rendu Livewire qui inclut désormais `schedules-panel.blade.php` casse avec `no such table`. Le `tearDown` a été complété pour cleanup ces tables.

**Point d'attention — SQLite vs PostgreSQL** :

SQLite stocke la colonne `DATE` sous forme de timestamp string (`YYYY-MM-DD 00:00:00`), ce qui casse la comparaison `where('ran_for_date', 'YYYY-MM-DD')`. Contournement côté service : `whereDate(...)` pour portabilité. Les tests SQLite ne vérifient donc pas les CHECK contraintes pgsql, qui sont la défense en profondeur (garde au niveau DB testée via E2E VM AC20).

**Point d'attention — FormRequest non branchés sur Livewire** :

Les FormRequests `StoreWorkstationGroupScheduleRequest` et `UpdateWorkstationGroupScheduleRequest` ont été créés (tâche 3bis.1/3bis.2) pour une future utilisation côté API/HTTP. Côté Livewire, la validation est inline dans `saveSchedule()` (`$this->validate([...])`) avec les mêmes règles conditionnelles `recurring` / `one_shot` (tâche 3bis.3 couverte dans l'esprit — les messages et règles sont cohérents, testés par `test_schedule_validation_*` + `test_one_shot_with_run_at_in_past_shows_validation_error`).

**Follow-ups identifiés** :
1. **Migration à appliquer manuellement sur VM** — `php artisan migrate` pour créer les 2 nouvelles tables + drop orpheline. Non bloquant en CI (tests créent les tables via helper).
2. **Vérification E2E VM** (tâches 10.1–10.3) — à faire après sync code : `php artisan schedule:list` doit afficher les 2 nouvelles entrées. 10 scénarios dans `docs/qa/4-4-e2e-manual.md`.
3. **Test bascule DST réelle** — couvert automatiquement par `test_execute_due_respects_timezone_of_schedule` (Carbon::setTestNow). Validation manuelle en octobre possible.
4. **Affichage historique runs dans l'UI** — rendu minimal côté AC9 (la liste des schedules est rendue avec compteurs, mais le drill-down vers les tasks individuelles reste à enrichir — **tests AC9 passent mais UX visuelle à polir en follow-up UX**).
5. **Migration legacy table MySQL `actions`** — non automatique (schémas trop différents). Documenté dans `docs/qa/4-4-e2e-manual.md` section « Migration hors legacy ».

### Change Log

| Date       | Version | Description                                                                                 | Author              |
|------------|---------|---------------------------------------------------------------------------------------------|---------------------|
| 2026-04-22 | 0.1     | Création story (ready-for-dev) — scope complet MVP, 6 décisions D1–D6 actées, 17 AC, 10 tâches, réutilisation intégrale infra 4.2/4.3. | claude-opus-4-7 (SM) |
| 2026-04-22 | 0.2     | +Mode one-shot (date unique) — décision D7 ajoutée, +9 AC (AC18–AC26), tâche 3bis FormRequest conditionnelle, tâche 2 migration CHECK constraint exclusivité + colonnes `mode/run_at/completed_at`, tâche 3 service `createRecurring`/`createOneShot`/`cloneOneShot` + `executeDue` gère 2 modes avec auto-completion one-shot, tâche 5 UI toggle « Récurrent / Date unique » + formulaire conditionnel + affichage mixte + bouton Dupliquer. +Clarification explicite D1 et Dev Notes : architecture `tick → enqueue → worker` (parc:execute-group-schedules est juste le tick, les workers laravel-queue-general traitent les jobs, aucun nouveau worker à déployer) + diagramme flux ASCII. Totaux ajustés : 26 AC, 11 tâches, 46 tests (23 unit + 7 feature console + 16 feature livewire), 17 fichiers à créer + 3 à modifier. Status inchangé : `ready-for-dev`. | claude-opus-4-7 (SM) |
| 2026-04-22 | 0.3     | Implémentation complète — 46/46 tests verts (23 unit WorkstationGroupScheduleServiceTest + 7 Feature ExecuteGroupSchedulesCommandTest + 16 Feature Livewire GroupSchedulesPageTest) + 2 tests KernelScheduleTest ajoutés. 0 régression sur 58 tests power (4.2/4.3). Décisions D1–D7 toutes respectées. Signature `WorkstationGroupService::executeGroupMachinesAction` étendue avec `?string $initiatedBy = null` (backward-compat intégrale). Status : `review`. | claude-opus-4-7 |
| 2026-04-22 | 0.4     | Corrections review post-cycle (review sonnet + second avis opus) : 11/14 problèmes corrigés auto (#3 signature cloneOneShot, #4 validation timezone IANA, #5 index partiel pgsql runs, #6 status story→review, #7 test DST hiver, #8 renommage + test exclusivité service-level, #9 guards toggle/delete non-admin, #10 assertSeeInOrder AC24, #12 vérif cloneOneShot guard, #13 clone enabled=false placeholder-safe, #14 test clone enabled=false). 2 en attente décision Henri (Q1 tranché via option A, Q2 historique UI, Q3 timezone select optionnel). 2 faux positifs ignorés (#1 NULLS LAST SQLite OK, #11 prune daily sans lock acceptable). Tests : 54/54 Schedule verts (+4 tests ajoutés), 34/34 Power verts (non-régression). Status : `review → to-validate` côté review. | claude-opus-4-7 |
| 2026-04-22 | 0.5     | Décisions Henri post-review — Q2 + Q3 appliquées. **Q3 (timezone select)** : constante `WorkstationGroupSchedule::SUPPORTED_TIMEZONES` (France métropole + DOM-TOM + UTC, 14 timezones IANA) + méthode statique `timezoneLabels()` pour libellés FR UI. FormRequest passe de `'timezone'` (validation IANA globale) à `Rule::in(SUPPORTED_TIMEZONES)`. Service ajoute `assertTimezoneSupported()` en défense profondeur (create + update). UI passe de `<input type="text">` à `<select>` avec libellés FR. **Q2 (page dédiée historique)** : nouvelle route `app.parc.groups.schedules.runs` → page Livewire SFC `resources/views/pages/parc/groups/[id]/schedules/[scheduleId]/runs/index.blade.php` avec pagination 20/page, collapse expand détails runs (erreurs + tasks drill-down), badge "Rattrapé" si `drift_seconds > 60`. Bouton "Historique" ajouté dans colonne actions du panel (lecture seule, visible pour tous, indépendant de Gate::computer.control). +2 tests Feature `test_runs_page_renders_recent_runs_for_schedule` + `test_runs_page_redirects_when_schedule_does_not_belong_to_group`. Total : 52/52 Schedule verts, 13/13 GroupShow non-régression. | claude-opus-4-7 |

### File List

Paths relatifs à la racine du projet `sambaedu-reload/` :

**Créés (17) :**

- `database/migrations/2026_04_22_100000_drop_workstation_scheduled_actions_table.php`
- `database/migrations/2026_04_22_100001_create_workstation_group_schedules_table.php`
- `database/migrations/2026_04_22_100002_create_workstation_group_schedule_runs_table.php`
- `app/Models/WorkstationGroupSchedule.php`
- `app/Models/WorkstationGroupScheduleRun.php`
- `database/factories/WorkstationGroupScheduleFactory.php`
- `database/factories/WorkstationGroupScheduleRunFactory.php`
- `app/Services/Parc/WorkstationGroupScheduleService.php`
- `app/Http/Requests/Parc/StoreWorkstationGroupScheduleRequest.php`
- `app/Http/Requests/Parc/UpdateWorkstationGroupScheduleRequest.php`
- `app/Console/Commands/ExecuteWorkstationGroupSchedulesCommand.php`
- `app/Console/Commands/PruneWorkstationGroupScheduleRunsCommand.php`
- `resources/views/pages/parc/groups/[id]/_partials/schedules-panel.blade.php`
- `resources/views/pages/parc/groups/[id]/schedules/[scheduleId]/runs/index.blade.php` (v0.5 Q2 — page dédiée historique runs)
- `tests/Unit/Services/Parc/WorkstationGroupScheduleServiceTest.php`
- `tests/Feature/Console/ExecuteGroupSchedulesCommandTest.php`
- `tests/Feature/Livewire/Parc/GroupSchedulesPageTest.php`
- `docs/qa/4-4-e2e-manual.md`

**Modifiés (6) :**

- `app/Console/Kernel.php` (+2 commandes schedulées : `parc:execute-group-schedules` everyMinute + `parc:prune-group-schedule-runs` daily)
- `app/Services/Parc/WorkstationGroupService.php` (signature `executeMachineAction` / `executeMachinesAction` / `executeGroupMachinesAction` étendue avec `?string $initiatedBy = null` backward-compat)
- `resources/views/pages/parc/groups/[id]/index.blade.php` (injection `WorkstationGroupScheduleService`, +12 propriétés Livewire form state, +7 méthodes Livewire, include du partial schedules-panel, bouton « Programmer une action » actif dans dropdown Actions)
- `tests/Feature/Livewire/Parc/GroupShowPageTest.php` (extension `createTablesIfNeeded` + `tearDown` pour couvrir les 2 nouvelles tables — maintient la non-régression des 13 tests existants)
- `tests/Feature/Console/KernelScheduleTest.php` (+2 tests : `it_schedules_group_schedules_execution_every_minute` + `it_schedules_group_schedule_runs_pruning_daily`)
- `docs/domains/parc.md` (section « Programmations (story 4-4) » avec diagramme mermaid, modes, idempotence, rétention, liens)
- `routes/web.php` (v0.5 Q2 — route `app.parc.groups.schedules.runs`)
- `app/Models/WorkstationGroupSchedule.php` (v0.5 Q3 — `SUPPORTED_TIMEZONES` + `timezoneLabels()`)
- `app/Http/Requests/Parc/StoreWorkstationGroupScheduleRequest.php` (v0.5 Q3 — `Rule::in(SUPPORTED_TIMEZONES)`)
- `app/Http/Requests/Parc/UpdateWorkstationGroupScheduleRequest.php` (v0.5 Q3 — idem)
- `app/Services/Parc/WorkstationGroupScheduleService.php` (v0.5 Q3 — `assertTimezoneSupported`)

---

## Recommandation Modèle Dev

**Recommandation : `opus` (maintenue et renforcée en v0.2)**

**Justification :**

Bien que la story s'appuie sur l'infra async 4.2/4.3 (réutilisation maximale du `WorkstationGroupService::executeGroupMachinesAction` et du pipeline job/task), le scope 4.4 cumule **8 surfaces critiques** qui justifient `opus` plutôt que `sonnet` (la v0.2 ajoute la surface one-shot qui renforce l'exigence de rigueur) :

1. **Scheduling + racing conditions timezone (DST)** — le filtre horaire doit fonctionner pendant la bascule heure été/heure hiver sans double-fire ni miss. `Carbon::now($schedule->timezone)` + `isDueNow()` avec comparaison précise à la minute demande un raisonnement temporel soigné. `sonnet` produit souvent un `now()->format('H:i')` qui bug en DST.

2. **Idempotence multi-couches** — 3 niveaux (garde `exists()` service + index DB unique + `withoutOverlapping` scheduler) + 1 couche héritée de 4.3 (filtre 409 `WorkstationGroupService`). Chaque couche doit être testée isolément ET en composition. Le test `test_execute_due_is_idempotent_within_same_minute` (AC13) doit simuler 2 ticks consécutifs et vérifier qu'un seul run DB est créé. **v0.2** : l'idempotence one-shot ajoute une dimension (un one-shot ne doit jamais re-fire, garanti par 4 couches : `enabled=false` post-exécution + `completed_at IS NOT NULL` + SELECT filter + index unique — AC22).

3. **Résolution liveness du groupe (D2)** — à chaque tick on relit `$group->workstations` (pas de snapshot). Interaction subtile si l'admin retire une machine entre la création du cron et son exécution. Test `test_execute_due_resolves_machines_at_tick_time_not_creation_time` à concevoir précisément.

4. **7 décisions produit (D1–D7)** à appliquer sans dériver du cadre : D5 exclut explicitement `shutdown-force` et `restart` — l'enum doit être strictement `['wake', 'shutdown']`. **v0.2** : D7 introduit un discriminant `mode` avec validation croisée par FormRequest ET contrainte CHECK DB — un LLM peu rigoureux va simplifier au niveau PHP uniquement (oublier la contrainte DB) ou inversement (oublier la validation FormRequest côté UI) → risque de corruption DB ou de 500 non gérés.

5. **Persistance historique + affichage paginé** — table `workstation_group_schedule_runs` avec JSONB summary complexe (success/failed/skipped + task_ids + errors + **v0.2** `drift_seconds` pour rattrapage one-shot post-downtime), rétention 30 jours (commande prune à écrire), UI paginée avec drill-down. Cohérence entre le shape JSONB et le rendu Blade demande rigueur.

6. **Observer / middleware cleanup** — si on supprime un `WorkstationGroup` (cascade), les schedules cascade-delete (FK `cascadeOnDelete`) et les runs bascule en `SET NULL`. À vérifier avec un test `test_deleting_workstation_group_cascades_schedules_but_keeps_runs_orphaned`.

7. **Test cross-couches (service + commande + UI)** — 3 suites de tests (**v0.2 : unit Service 23 tests + Feature Console 7 tests + Feature Livewire 16 tests = 46 nouveaux tests**) en plus de la non-régression 4.2/4.3 (92 existants). Le dev doit concevoir des scénarios où le service, la commande et l'UI interagissent correctement sans se marcher dessus. **v0.2** : les tests one-shot croisent validation (past run_at), exécution (completed_at + enabled=false), UI (bouton Modifier caché si terminé), commande (no refire). Exhaustivité matricielle : 2 modes × 3 couches × N scénarios.

8. **v0.2 — Complexité conditionnelle 2 modes (D7)** : FormRequest avec règles conditionnelles selon `mode`, Service avec dispatch `createRecurring`/`createOneShot`, modèle avec scopes `recurring()`/`oneShot()`/`completed()`/`pending()`, UI avec toggle + formulaire conditionnel + affichage mixte, contrainte DB CHECK. Chaque couche a une branche « si recurring / si one_shot » — le risque est qu'un LLM simplifie en ignorant une branche (ex. forme UI one-shot toujours affichée même quand `formMode='recurring'`) → casse le formulaire. La discipline de préserver l'exclusivité stricte (ni les deux, ni aucun) à **tous** les niveaux (FormRequest + DB CHECK + UI `x-show` + tests `test_create_rejects_schedule_with_both_or_no_representation`) demande un LLM de tête de classe.

**Conclusion** : la v0.2 **augmente** la densité du graphe, en gardant une très grosse partie de l'infra 4.2/4.3 réutilisée. Les couches temporelles (scheduling, DST, idempotence multi-mode), la granularité des ACs (**26 AC** — ex-17 + 9 one-shot) et la surface de tests (**46 tests nouveaux** + 92 non-régression) forment un graphe encore plus dense. `opus` pour raisonnement temporel (DST + idempotence tick + one-shot catch-up), rigueur contrats (2 FormRequests + CHECK DB + toggle UI), et exhaustivité tests matriciels. `sonnet` reste acceptable si le dev est autonome et suit la story ligne par ligne, mais `opus` garantit la première livraison au bon niveau de qualité — d'autant plus que le dev peut désormais se laisser piéger par une branche `one_shot` bâclée (ex. oublier d'écrire `completed_at` dans la même transaction que le run, ou oublier la branche `prohibited` dans le FormRequest recurring).

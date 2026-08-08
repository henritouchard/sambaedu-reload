# Architecture des Tasks ControlHub

Ce document décrit l'architecture et le flux d'exécution des tâches ordonnées par le **ControlHub** et exécutées localement sur les instances **SEFS**.

---

## 1. Vue d'ensemble

Le système de Tasks permet au **ControlHub** (plateforme centrale) d'ordonner l'exécution de tâches sur les instances SEFS connectées. C'est un mécanisme de **commande à distance asynchrone** avec suivi d'état et callback de résultat.

### Acteurs

| Acteur | Rôle |
|--------|------|
| **ControlHub** | Plateforme centrale qui ordonne les tâches |
| **SEFS** | Instance locale qui reçoit et exécute les tâches |
| **Utilisateur** | Déclenche une action depuis l'interface ControlHub |

---

## 2. Schéma du flux complet

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                              FLUX D'EXÉCUTION                               │
└─────────────────────────────────────────────────────────────────────────────┘

    CONTROL HUB                                   SEFS (Instance locale)
    ═══════════════════                           ═══════════════════════

    ┌─────────────────┐
    │ 1. Utilisateur  │
    │    déclenche    │
    │    une action   │
    └────────┬────────┘
             │
             ▼
    ┌─────────────────┐         POST /api/v1/tasks/greetme
    │ 2. ControlHub   │ ─────────────────────────────────────► ┌─────────────────┐
    │    envoie la    │         {task_id, task_name,           │ 3. TaskController│
    │    tâche        │          task_type, payload}           │    reçoit       │
    └─────────────────┘                                        └────────┬────────┘
                                                                        │
                                                                        ▼
                                                               ┌─────────────────┐
                                                               │ 4. Création     │
                                                               │ ControlHubTask │
                                                               │    en BDD       │
                                                               │    (statut received)   │
                                                               └────────┬────────┘
                                                                        │
             ◄──────────────────────────────────────────────────────────┤
    ┌─────────────────┐         {success: true,                         │
    │ 5. ControlHub   │          task_id, status}                       │
    │    confirme     │                                                 │
    │    réception    │                                                 ▼
    └─────────────────┘                                        ┌─────────────────┐
                                                               │ 6. Dispatch     │
                                                               │    du Job       │
                                                               │    (queued)     │
                                                               └────────┬────────┘
                                                                        │
                                                                        ▼
                                                               ┌─────────────────┐
                                                               │ 7. Exécution    │
                                                               │    du Job       │
                                                               │    (in_progress)│
                                                               └────────┬────────┘
                                                                        │
                                                                        ▼
                                                               ┌─────────────────┐
                                                               │ 8. Résultat     │
                                                               │    (success/    │
                                                               │     failed)     │
                                                               └────────┬────────┘
                                                                        │
             POST /api/sambaedu/task-result/{instance_id}               │
    ┌─────────────────┐ ◄───────────────────────────────────────────────┘
    │ 9. ControlHub   │         {task_id, status,
    │    reçoit le    │          result, completed_at}
    │    callback     │
    └─────────────────┘

```

---

## 3. Composants de l'architecture

### 3.1 Route API (réception de la tâche)

**Fichier** : `routes/api.php`

```php
Route::prefix('tasks')->name('tasks.')->middleware('controlhub.auth')->group(function () {
    Route::post('/greetme', [TaskController::class, 'greetme'])->name('greetme');
});
```

- **URL** : `POST /api/v1/tasks/greetme`
- **Middleware** : `controlhub.auth` (authentification par token ControlHub)
- **Sécurité** : Seul le ControlHub peut appeler cette route

---

### 3.2 Contrôleur (TaskController)

**Fichier** : `app/Http/Controllers/Api/v1/Tasks/TaskController.php`

Le contrôleur gère :
1. **Validation** des données entrantes
2. **Idempotence** (évite les doublons si le ControlHub renvoie la même tâche)
3. **Création** de l'enregistrement `ControlHubTask` en base
4. **Dispatch** du Job Laravel pour exécution asynchrone

```php
public function greetme(Request $request): JsonResponse
{
    // 1. Validation
    $validated = $request->validate([
        'task_id' => 'required|uuid',
        'task_name' => 'required|string|max:255',
        'task_type' => 'required|string|in:greetme',
        'payload' => 'nullable|array',
        'scheduled_at' => 'nullable|date',
    ]);

    // 2. Idempotence - vérifier si la tâche existe déjà
    $existingTask = ControlHubTask::where('controlhub_task_id', $validated['task_id'])->first();
    if ($existingTask) {
        return response()->json([
            'success' => true,
            'message' => 'Task already received',
            'task_id' => $existingTask->id,
            'status' => $existingTask->status,
        ]);
    }

    // 3. Création en base
    $task = ControlHubTask::create([
        'controlhub_task_id' => $validated['task_id'],
        'name' => $validated['task_name'],
        'type' => $validated['task_type'],
        'payload' => $validated['payload'] ?? [],
        'status' => ControlHubTask::STATUS_RECEIVED,
        'scheduled_at' => $validated['scheduled_at'] ?? null,
    ]);

    // 4. Dispatch du Job (avec délai si scheduled_at)
    $task->markAsQueued();
    ExecuteGreetmeJob::dispatch($task);

    return response()->json([
        'success' => true,
        'message' => 'Task received and queued',
        'task_id' => $task->id,
        'status' => $task->status,
    ]);
}
```

---

### 3.3 Modèle (ControlHubTask)

**Fichier** : `app/Models/ControlHubTask.php`

Le modèle représente une tâche en base de données avec :
- **Statuts** : `received` → `queued` → `in_progress` → `success`/`failed`
- **Tracking du callback** : `callback_sent`, `callback_sent_at`
- **Méthodes utilitaires** : `markAsQueued()`, `markAsInProgress()`, `markAsSuccess()`, etc.

#### Statuts possibles

| Statut | Description |
|--------|-------------|
| `received` | Tâche reçue du ControlHub |
| `queued` | En file d'attente Laravel |
| `in_progress` | En cours d'exécution |
| `success` | Terminée avec succès |
| `failed` | Échec de l'exécution |

#### Structure de la table

```sql
CREATE TABLE controlhub_tasks (
    id UUID PRIMARY KEY,
    controlhub_task_id UUID,      -- ID côté ControlHub (pour callback)
    name VARCHAR(255),            -- Nom de la tâche
    type VARCHAR(255),            -- Type (greetme, etc.)
    payload JSON,                 -- Données de la tâche
    status ENUM(...),             -- Statut actuel
    result JSON,                  -- Résultat de l'exécution
    error_message TEXT,           -- Message d'erreur si échec
    scheduled_at TIMESTAMP,       -- Exécution planifiée
    started_at TIMESTAMP,         -- Début d'exécution
    completed_at TIMESTAMP,       -- Fin d'exécution
    callback_sent BOOLEAN,        -- Callback envoyé au ControlHub ?
    callback_sent_at TIMESTAMP,   -- Quand le callback a réussi
    callback_response JSON,       -- Réponse du ControlHub au callback
    callback_error TEXT,          -- Erreur du callback si échec
    created_at TIMESTAMP,
    updated_at TIMESTAMP
);
```

---

### 3.4 Job Laravel (ExecuteGreetmeJob)

**Fichier** : `app/Jobs/ExecuteGreetmeJob.php`

Le Job est responsable de :
1. **Exécuter** la logique métier de la tâche
2. **Mettre à jour** le statut en base
3. **Envoyer le callback** au ControlHub avec le résultat

```php
class ExecuteGreetmeJob implements ShouldQueue
{
    public int $tries = 3;      // Nombre de tentatives max
    public int $timeout = 240;  // Timeout en secondes

    public function __construct(public ControlHubTask $task) {}

    public function handle(ControlHubService $controlHub): void
    {
        // 1. Marquer comme en cours
        $this->task->markAsInProgress();

        try {
            // 2. Exécuter la logique métier
            // (ici : délai aléatoire + message de salutation)
            $delay = rand(10, 20);
            sleep($delay);

            $result = [
                'message' => 'Bonjour grand maître',
                'delay_seconds' => $delay,
                'executed_at' => now()->toISOString(),
            ];

            // 3. Marquer comme succès
            $this->task->markAsSuccess($result);

            // 4. Envoyer le callback au ControlHub
            $this->sendCallback($controlHub, 'success', $result);

        } catch (\Exception $e) {
            $this->task->markAsFailed($e->getMessage());
            $this->sendCallback($controlHub, 'failed', null, $e->getMessage());
        }
    }
}
```

---

### 3.5 Callback vers le ControlHub

Après exécution, SEFS envoie le résultat au ControlHub via l'API :

**Endpoint ControlHub** : `POST /api/sambaedu/task-result/{instance_id}`

```php
private function sendCallback(
    ControlHubService $controlHub,
    string $status,
    ?array $result,
    ?string $error = null
): void {
    $endpoint = "/api/sambaedu/task-result/{$instanceId}";

    $payload = [
        'task_id' => $this->task->controlhub_task_id,  // ID ControlHub
        'status' => $status,                        // 'success' ou 'failed'
        'result' => $result,                        // Données du résultat
        'error' => $error,                          // Message d'erreur si échec
        'completed_at' => now()->toISOString(),
    ];

    $response = $controlHub->callControlHubApi($endpoint, $payload, 'POST');

    if ($response['success']) {
        $this->task->markCallbackSent($response);
    } else {
        $this->task->markCallbackFailed($response['message'], $response);
    }
}
```

---

## 4. Sécurité

### 4.1 Authentification des requêtes ControlHub

Le middleware `controlhub.auth` vérifie que :
1. La requête provient bien du ControlHub
2. Le token d'authentification est valide
3. L'instance SEFS est bien enregistrée

### 4.2 Idempotence

Si le ControlHub renvoie une tâche déjà reçue (même `task_id`), le contrôleur retourne simplement le statut actuel sans créer de doublon.

### 4.3 Retry automatique

- **Job** : 3 tentatives max avec backoff exponentiel
- **Callback** : Retry possible via le champ `callback_sent` (tâches avec `callback_sent = false` peuvent être retraitées)

---

## 5. Exemple concret : Task "greetme"

### Requête ControlHub → SEFS

```http
POST /api/v1/tasks/greetme HTTP/1.1
Host: sefs.etablissement.fr
Authorization: Bearer <controlhub_token>
Content-Type: application/json

{
    "task_id": "550e8400-e29b-41d4-a716-446655440000",
    "task_name": "Test de salutation",
    "task_type": "greetme",
    "payload": {
        "custom_message": "Hello World"
    },
    "scheduled_at": null
}
```

### Réponse SEFS → ControlHub (immédiate)

```json
{
    "success": true,
    "message": "Task received and queued",
    "task_id": "a1b2c3d4-e5f6-7890-abcd-ef1234567890",
    "status": "queued"
}
```

### Callback SEFS → ControlHub (après exécution)

```http
POST /api/sambaedu/task-result/sefs_fd0cc59d HTTP/1.1
Host: controlhub.example.com
Authorization: Bearer <controlhub_api_token>
Content-Type: application/json

{
    "task_id": "550e8400-e29b-41d4-a716-446655440000",
    "status": "success",
    "result": {
        "message": "Bonjour grand maître",
        "delay_seconds": 15,
        "executed_at": "2026-01-10T14:30:45.000000Z",
        "instance_id": "sefs_fd0cc59d"
    },
    "error": null,
    "completed_at": "2026-01-10T14:30:45.000000Z"
}
```

---

## 6. Ajouter une nouvelle Task

Grâce à la classe abstraite `BaseControlHubJob`, créer une nouvelle tâche est très simple.

### 6.1 Créer le Job (héritant de BaseControlHubJob)

Il suffit d'hériter de `BaseControlHubJob` et d'implémenter la méthode `execute()` :

```php
// app/Jobs/ExecuteShutdownMachinesJob.php
<?php

namespace App\Jobs;

use Illuminate\Support\Facades\Log;

class ExecuteShutdownMachinesJob extends BaseControlHubJob
{
    /**
     * Exécute la logique métier spécifique.
     * 
     * @return array Le résultat (sera enrichi automatiquement)
     */
    protected function execute(): array
    {
        $machines = $this->task->payload['machines'] ?? [];
        $results = [];

        foreach ($machines as $machine) {
            // Logique d'extinction...
            $results[$machine] = 'shutdown_initiated';
        }

        return ['machines' => $results];
    }
}
```

**C'est tout !** La classe `BaseControlHubJob` gère automatiquement :
- ✅ Mise à jour du statut en BDD (`in_progress` → `success`/`failed`)
- ✅ Envoi du callback au ControlHub
- ✅ Gestion des erreurs et logging
- ✅ Enrichissement du résultat avec métadonnées (`executed_at`, `instance_id`)

### 6.2 Personnalisation optionnelle

Vous pouvez surcharger certaines propriétés/méthodes si nécessaire :

```php
class ExecuteLongTaskJob extends BaseControlHubJob
{
    // Augmenter le timeout (défaut: 240s)
    public int $timeout = 600;
    
    // Augmenter les tentatives (défaut: 3)
    public int $tries = 5;
    
    // Personnaliser le nom dans les logs
    protected function getJobName(): string
    {
        return 'LongTask';
    }
    
    protected function execute(): array
    {
        // Logique métier...
        return ['status' => 'done'];
    }
}
```

### 6.3 Ajouter la route

```php
// routes/api.php
Route::prefix('tasks')->name('tasks.')->middleware('controlhub.auth')->group(function () {
    Route::post('/greetme', [TaskController::class, 'greetme'])->name('greetme');
    Route::post('/shutdown-machines', [TaskController::class, 'shutdownMachines'])->name('shutdown-machines');
});
```

### 6.4 Ajouter la méthode au contrôleur

```php
// app/Http/Controllers/Api/v1/Tasks/TaskController.php
public function shutdownMachines(Request $request): JsonResponse
{
    $validated = $request->validate([
        'task_id' => 'required|uuid',
        'task_name' => 'required|string|max:255',
        'task_type' => 'required|string|in:shutdown_machines',
        'payload' => 'required|array',
        'payload.machines' => 'required|array|min:1',
    ]);

    // ... même logique que greetme
    // Dispatcher ExecuteShutdownMachinesJob au lieu de ExecuteGreetmeJob
}
```

---

## 7. Monitoring et debugging

### 7.1 Logs

Les logs sont écrits à chaque étape :
- Réception de la tâche
- Dispatch du Job
- Début d'exécution
- Fin d'exécution (succès/échec)
- Envoi du callback

```bash
tail -f storage/logs/laravel.log | grep -i "task"
```

### 7.2 Vérifier les tâches en base

```sql
-- Tâches en cours
SELECT * FROM controlhub_tasks WHERE status IN ('received', 'queued', 'in_progress');

-- Tâches en attente de callback
SELECT * FROM controlhub_tasks WHERE callback_sent = false AND status IN ('success', 'failed');

-- Historique des tâches
SELECT * FROM controlhub_tasks ORDER BY created_at DESC LIMIT 20;
```

### 7.3 Retry manuel d'un callback

```php
// Via Tinker
$task = ControlHubTask::find('uuid-de-la-tache');
$controlHub = app(ControlHubService::class);

// Renvoyer le callback
$response = $controlHub->callControlHubApi(
    "/api/sambaedu/task-result/{$instanceId}",
    [
        'task_id' => $task->controlhub_task_id,
        'status' => $task->status,
        'result' => $task->result,
        'completed_at' => $task->completed_at->toISOString(),
    ],
    'POST'
);
```

---

## 8. Résumé

| Composant | Fichier | Rôle |
|-----------|---------|------|
| **Route** | `routes/api.php` | Point d'entrée API |
| **Contrôleur** | `TaskController.php` | Validation, création, dispatch |
| **Modèle** | `ControlHubTask.php` | Persistance et statuts |
| **Job de base** | `BaseControlHubJob.php` | Classe abstraite avec logique commune |
| **Job concret** | `ExecuteGreetmeJob.php` | Implémentation spécifique (hérite de BaseControlHubJob) |
| **Service** | `ControlHubService.php` | Communication avec le ControlHub |
| **Middleware** | `controlhub.auth` | Authentification |

Le système garantit :
- ✅ **Asynchronisme** : L'exécution ne bloque pas le ControlHub
- ✅ **Traçabilité** : Chaque étape est enregistrée en base
- ✅ **Fiabilité** : Retry automatique et idempotence
- ✅ **Sécurité** : Authentification par token

# API ControlHub — Gestion des WorkstationGroups

## Authentification

Toutes les requêtes doivent inclure le header d'authentification ControlHub :

```
Authorization: Bearer <controlhub_api_token>
```

Le middleware `controlhub.auth` valide ce token.

---

## Base URL

```
POST https://<instance_se4fs>/api/v1/workstation-groups/{action}
```

---

## Concepts clés

### Verrouillage (`locked`)

Les groupes créés via cette API sont **automatiquement verrouillés** avec la raison `control_hub`. Cela signifie :

- Ils ne peuvent **pas** être modifiés ou supprimés via l'interface locale SE4FS
- Seul le ControlHub peut les modifier/supprimer via cette API
- Le champ `managed_by_control_hub` est également mis à `true`

### Types de groupes

| `is_physical` | Type | Description |
|---|---|---|
| `true` | Physique | Correspond à une OU dans `OU=Computers` (salle/lieu). GPO et WPKG s'appliquent. |
| `false` | Logique | Regroupe des workstations de n'importe quel groupe physique pour AppProfiles (WPKG). |

### Flux asynchrone

Toutes les opérations sont **asynchrones** :

1. Le ControlHub envoie la requête → reçoit un `task_id` immédiatement
2. SE4FS exécute la tâche en arrière-plan (création SQL + sync AD)
3. SE4FS envoie un **callback** au ControlHub avec le résultat via `POST /api/sambaedu/task-result/{instance_id}`

### Idempotence

Chaque requête contient un `task_id` (UUID). Si une tâche avec le même `task_id` existe déjà, la requête retourne le statut existant sans créer de doublon.

---

## 1. Créer un WorkstationGroup

### `POST /api/v1/workstation-groups/create`

Crée un nouveau groupe de machines verrouillé `control_hub`.

#### Request body

```json
{
    "task_id": "550e8400-e29b-41d4-a716-446655440000",
    "task_name": "Création salle informatique B12",
    "task_type": "create_workstation_group",
    "payload": {
        "name": "salle-b12",
        "is_physical": true,
        "display_name": "Salle informatique B12",
        "description": "Salle informatique du bâtiment B, 2ème étage",
        "app_profile_name": "salle-b12",
        "parent_name": "computers"
    },
    "scheduled_at": null
}
```

#### Champs payload

| Champ | Type | Requis | Description |
|---|---|---|---|
| `name` | string | **oui** | Identifiant unique du groupe (slug, pas d'espaces). Doit être unique. |
| `is_physical` | boolean | **oui** | `true` = salle physique (OU), `false` = groupe logique (CN) |
| `display_name` | string | non | Nom d'affichage lisible |
| `description` | string | non | Description du groupe |
| `app_profile_name` | string | non | Nom du profil applicatif WPKG à créer/lier automatiquement |
| `parent_name` | string | non | Nom du groupe parent (pour la hiérarchie). Ex: `"computers"` pour la racine. |

#### Champs racine

| Champ | Type | Requis | Description |
|---|---|---|---|
| `task_id` | UUID | **oui** | Identifiant unique de la tâche (idempotence) |
| `task_name` | string | **oui** | Nom lisible de la tâche |
| `task_type` | string | **oui** | Doit être `"create_workstation_group"` |
| `payload` | object | **oui** | Données du groupe à créer |
| `scheduled_at` | datetime | non | Date d'exécution planifiée (ISO 8601). `null` = immédiat. |

#### Réponses

**201 — Tâche acceptée :**
```json
{
    "success": true,
    "message": "Task received and queued",
    "task_id": "9f3a2b1c-...",
    "status": "queued"
}
```

**409 — Nom déjà existant :**
```json
{
    "success": false,
    "message": "Un groupe avec ce nom existe déjà",
    "error": "Le groupe 'salle-b12' existe déjà (id: 42)"
}
```

**422 — Validation échouée :**
```json
{
    "success": false,
    "message": "Validation failed",
    "errors": {
        "payload.name": ["The payload.name field is required."]
    }
}
```

**422 — Groupe parent non trouvé :**
```json
{
    "success": false,
    "message": "Groupe parent non trouvé",
    "error": "Le groupe parent 'batiment-c' n'existe pas"
}
```

#### Callback de succès

```json
{
    "task_id": "550e8400-e29b-41d4-a716-446655440000",
    "status": "success",
    "result": {
        "group_id": 42,
        "group_name": "salle-b12",
        "is_physical": true,
        "locked": "control_hub",
        "managed_by_control_hub": true,
        "ad_synced": false,
        "message": "Groupe de machines créé avec succès",
        "executed_at": "2026-02-06T12:00:00.000000Z",
        "instance_id": "se4fs_abc123",
        "job_name": "CreateWorkstationGroupJob"
    },
    "error": null,
    "completed_at": "2026-02-06T12:00:01.000000Z"
}
```

> **Note** : `ad_synced` sera `false` dans le callback immédiat car la sync AD est un job séparé déclenché par l'Observer. Le groupe sera synchronisé avec l'AD peu après.

---

## 2. Modifier un WorkstationGroup

### `POST /api/v1/workstation-groups/update`

Modifie un groupe existant. **Seuls les groupes verrouillés `control_hub` peuvent être modifiés via cette API.**

#### Request body

```json
{
    "task_id": "660e8400-e29b-41d4-a716-446655440001",
    "task_name": "Renommage salle B12 en B12-info",
    "task_type": "update_workstation_group",
    "payload": {
        "name": "salle-b12",
        "new_name": "salle-b12-info",
        "display_name": "Salle informatique B12 (info)",
        "description": "Salle informatique principale",
        "app_profile_name": "salle-b12-info",
        "parent_name": "batiment-b",
        "is_active": true
    },
    "scheduled_at": null
}
```

#### Champs payload

| Champ | Type | Requis | Description |
|---|---|---|---|
| `name` | string | **oui** | Nom actuel du groupe à modifier (identifiant de recherche) |
| `new_name` | string | non | Nouveau nom (renommage). Doit être unique. |
| `display_name` | string | non | Nouveau nom d'affichage |
| `description` | string | non | Nouvelle description |
| `app_profile_name` | string | non | Nouveau nom de profil applicatif |
| `parent_name` | string | non | Nouveau groupe parent (déplacement) |
| `is_active` | boolean | non | Activer/désactiver le groupe |

> Seuls les champs présents dans le payload sont modifiés. Les champs absents restent inchangés.

#### Réponses

**200 — Tâche acceptée :**
```json
{
    "success": true,
    "message": "Task received and queued",
    "task_id": "9f3a2b1c-...",
    "status": "queued"
}
```

**403 — Groupe non géré par ControlHub :**
```json
{
    "success": false,
    "message": "Modification refusée",
    "error": "Le groupe 'salle-a1' n'est pas géré par le ControlHub. Seuls les groupes créés par le ControlHub peuvent être modifiés via cette API."
}
```

**404 — Groupe non trouvé :**
```json
{
    "success": false,
    "message": "Groupe non trouvé",
    "error": "Le groupe 'salle-inexistante' n'existe pas"
}
```

**409 — Nouveau nom déjà utilisé :**
```json
{
    "success": false,
    "message": "Nom déjà utilisé",
    "error": "Un groupe avec le nom 'salle-a1' existe déjà"
}
```

#### Callback de succès

```json
{
    "task_id": "660e8400-e29b-41d4-a716-446655440001",
    "status": "success",
    "result": {
        "group_id": 42,
        "group_name": "salle-b12-info",
        "updated": true,
        "updated_fields": ["name", "display_name", "description", "app_profile_name"],
        "message": "Groupe de machines mis à jour avec succès",
        "executed_at": "2026-02-06T12:05:00.000000Z",
        "instance_id": "se4fs_abc123",
        "job_name": "UpdateWorkstationGroupJob"
    },
    "error": null,
    "completed_at": "2026-02-06T12:05:01.000000Z"
}
```

---

## 3. Supprimer un WorkstationGroup

### `POST /api/v1/workstation-groups/delete`

Supprime un groupe existant. **Seuls les groupes verrouillés `control_hub` peuvent être supprimés via cette API.**

La suppression :
- Détache toutes les workstations du groupe
- Réattache les groupes enfants au parent du groupe supprimé
- Supprime l'OU/CN correspondant dans l'AD
- **Ne supprime pas** l'AppProfile associé (il peut être réutilisé)

#### Request body

```json
{
    "task_id": "770e8400-e29b-41d4-a716-446655440002",
    "task_name": "Suppression salle B12",
    "task_type": "delete_workstation_group",
    "payload": {
        "name": "salle-b12"
    }
}
```

#### Champs payload

| Champ | Type | Requis | Description |
|---|---|---|---|
| `name` | string | **oui** | Nom du groupe à supprimer |

#### Réponses

**200 — Tâche acceptée :**
```json
{
    "success": true,
    "message": "Task received and queued",
    "task_id": "9f3a2b1c-...",
    "status": "queued"
}
```

**403 — Groupe non géré par ControlHub :**
```json
{
    "success": false,
    "message": "Suppression refusée",
    "error": "Le groupe 'salle-a1' n'est pas géré par le ControlHub. Seuls les groupes créés par le ControlHub peuvent être supprimés via cette API."
}
```

**404 — Groupe non trouvé :**
```json
{
    "success": false,
    "message": "Groupe non trouvé",
    "error": "Le groupe 'salle-inexistante' n'existe pas"
}
```

#### Callback de succès

```json
{
    "task_id": "770e8400-e29b-41d4-a716-446655440002",
    "status": "success",
    "result": {
        "deleted": true,
        "group_id": 42,
        "group_name": "salle-b12",
        "ad_guid": "a1b2c3d4-...",
        "is_physical": true,
        "message": "Groupe de machines supprimé avec succès",
        "executed_at": "2026-02-06T12:10:00.000000Z",
        "instance_id": "se4fs_abc123",
        "job_name": "DeleteWorkstationGroupJob"
    },
    "error": null,
    "completed_at": "2026-02-06T12:10:01.000000Z"
}
```

---

## 4. Annuler une tâche

### `POST /api/v1/tasks/cancel`

Annule une tâche si elle n'a pas encore débuté (statut `received` ou `queued`).

```json
{
    "task_id": "550e8400-e29b-41d4-a716-446655440000"
}
```

**Réponse succès :**
```json
{
    "success": true,
    "message": "Task canceled successfully",
    "task_id": "9f3a2b1c-...",
    "status": "canceled"
}
```

**409 — Tâche déjà en cours :**
```json
{
    "success": false,
    "message": "Task cannot be canceled",
    "reason": "Task is already in_progress"
}
```

---

## Callback d'erreur

En cas d'échec d'exécution du job, le callback envoyé au ControlHub a cette structure :

```json
{
    "task_id": "550e8400-e29b-41d4-a716-446655440000",
    "status": "failed",
    "result": null,
    "error": "Un groupe avec le nom 'salle-b12' existe déjà (id: 42)",
    "completed_at": "2026-02-06T12:00:01.000000Z"
}
```

---

## Cycle de vie d'une tâche

```
received → queued → in_progress → success | failed
                                    ↓
                              callback envoyé
```

Statuts possibles :
- `received` : Tâche reçue, pas encore en file d'attente
- `queued` : En file d'attente, en attente d'exécution
- `in_progress` : En cours d'exécution
- `success` : Terminée avec succès
- `failed` : Échec (voir `error` dans le callback)
- `canceled` : Annulée avant exécution

---

## Effets de bord automatiques

### Lors de la création
1. **SQL** : Le groupe est créé avec `locked = 'control_hub'` et `managed_by_control_hub = true`
2. **AppProfile** : Si `app_profile_name` est fourni, un AppProfile WPKG est automatiquement créé et lié
3. **AD Sync** : Un job de synchronisation AD est dispatché pour créer l'OU/CN dans Active Directory

### Lors de la modification
1. **Renommage** : Si `new_name` est fourni, l'OU/CN est renommé dans l'AD et l'AppProfile associé est renommé
2. **Déplacement** : Si `parent_name` change, l'OU est déplacée dans l'AD
3. **AppProfile** : Si `app_profile_name` change, un nouveau profil est créé ou l'existant est renommé

### Lors de la suppression
1. **Workstations** : Toutes les machines sont détachées du groupe (elles ne sont pas supprimées)
2. **Enfants** : Les groupes enfants sont rattachés au parent du groupe supprimé
3. **AD** : L'OU/CN est supprimé de l'Active Directory
4. **AppProfile** : L'AppProfile associé est **conservé** (peut être réutilisé)

---

## Comparaison avec les Shortcuts

| Aspect | Shortcuts | WorkstationGroups |
|---|---|---|
| Marqueur de propriété | `global: true` dans le JSON | `locked: 'control_hub'` + `managed_by_control_hub: true` |
| Vérification ownership | `isControlHubShortcut()` | `locked === 'control_hub'` |
| Sync externe | Aucune | Active Directory (automatique via Observer) |
| Effet de bord | Aucun | AppProfile WPKG créé/renommé automatiquement |
| Annulation | Via `tasks/cancel` | Via `tasks/cancel` |
| Callback | Oui (BaseControlHubJob) | Oui (BaseControlHubJob) |

---
---

# Plan de développement — ControlHub : Gestion des WorkstationGroups et AppProfiles

## Vision

L'admin ControlHub maintient un **modèle de parc informatique** centralisé composé de :
- **WorkstationGroups** : groupes hiérarchiques (physiques ou logiques) représentant des salles, bâtiments, ou regroupements fonctionnels
- **AppProfiles** : profils applicatifs WPKG (ensembles d'applications) assignables aux groupes

Ce modèle est ensuite **dispatché** sur les instances SE4FS. Chaque entité créée par le ControlHub est verrouillée (`locked = 'control_hub'`) et ne peut être modifiée localement.

> **Périmètre** : Le ControlHub gère la **structure** (groupes + profils applicatifs), pas les **machines** (Workstations). Les machines sont découvertes et gérées localement par chaque instance SE4FS.

---

## Architecture côté ControlHub

### Modèle de données ControlHub (BDD centrale)

```
┌─────────────────────────┐       ┌──────────────────────────┐
│  ch_workstation_groups   │       │    ch_app_profiles       │
├─────────────────────────┤       ├──────────────────────────┤
│ id (UUID)               │       │ id (UUID)                │
│ name (unique)           │       │ name (unique)            │
│ is_physical (bool)      │       │ display_name             │
│ display_name            │       │ description              │
│ description             │       │ is_active (bool)         │
│ parent_id (self-ref)    │  N:N  │ application_ids[] (JSON) │
│ app_profile_ids[]       │◄─────►│ created_at / updated_at  │
│ created_at / updated_at │       └──────────────────────────┘
└─────────────────────────┘
         │
         │ dispatché vers
         ▼
┌─────────────────────────────────────────┐
│  ch_dispatch_targets                     │
├─────────────────────────────────────────┤
│ id                                       │
│ workstation_group_id / app_profile_id    │
│ instance_id (FK → instances)             │
│ controlhub_task_id (UUID)                │
│ status (pending/dispatched/success/fail) │
│ dispatched_at / completed_at             │
└─────────────────────────────────────────┘
```

### Pages à implémenter côté ControlHub

#### 1. Gestion des WorkstationGroups

| Page | Route | Description |
|---|---|---|
| **Liste / Arborescence** | `/workstation-groups` | Vue arborescente des groupes avec drag & drop pour réorganiser la hiérarchie. Filtres : physique/logique, recherche par nom. |
| **Création** | `/workstation-groups/create` | Formulaire : name, is_physical, display_name, description, parent (select arborescent), app_profile (select multiple). |
| **Détail / Édition** | `/workstation-groups/{id}` | Détail du groupe avec : infos, profils liés, instances cibles, statut de dispatch. Édition inline. |
| **Dispatch** | `/workstation-groups/{id}/dispatch` | Sélection des instances cibles + lancement du dispatch. Suivi du statut par instance. |
| **Dispatch en masse** | `/workstation-groups/dispatch` | Sélection de plusieurs groupes + instances cibles. Dispatch batch. |

#### 2. Gestion des AppProfiles

| Page | Route | Description |
|---|---|---|
| **Liste** | `/app-profiles` | Liste paginée des profils avec compteurs (nb applications, nb groupes liés). Filtres : actif/inactif, recherche. |
| **Création** | `/app-profiles/create` | Formulaire : name, display_name, description. Sélection d'applications depuis le catalogue. |
| **Détail / Édition** | `/app-profiles/{id}` | Détail avec : applications liées (ajout/retrait), groupes utilisant ce profil, instances cibles. |
| **Dispatch** | `/app-profiles/{id}/dispatch` | Dispatch du profil vers les instances sélectionnées. |

#### 3. Vue d'ensemble

| Page | Route | Description |
|---|---|---|
| **Dashboard Parc** | `/parc/dashboard` | Vue synthétique : nb groupes, nb profils, nb instances, statuts de dispatch. |
| **Matrice Groupes × Instances** | `/parc/matrix` | Tableau croisé montrant quels groupes sont dispatchés sur quelles instances, avec statut. |

---

## Développement côté SE4FS (instance)

### Phase 1 — Migration BDD : `locked` et `managed_by_control_hub` sur AppProfile ✅ À FAIRE

Le modèle `AppProfile` n'a pas encore les champs `locked` et `managed_by_control_hub`. Il faut les ajouter pour la cohérence avec `WorkstationGroup`.

**Migration à créer** : `add_locked_and_managed_by_control_hub_to_app_profiles_table`

```php
Schema::table('app_profiles', function (Blueprint $table) {
    $table->string('locked')->nullable()
        ->after('is_active')
        ->comment('Si non-null, empêche modification/suppression. Contient la raison du verrouillage.');
    $table->boolean('managed_by_control_hub')->default(false)
        ->after('locked');
});
```

**Modifications du modèle `AppProfile`** :
- Ajouter `locked` et `managed_by_control_hub` dans `$fillable`
- Ajouter les casts : `'locked' => 'string'`, `'managed_by_control_hub' => 'boolean'`
- Ajouter les méthodes `isLocked()`, `getLockReason()`, `lock()`, `unlock()` (même pattern que `WorkstationGroup`)

### Phase 2 — API AppProfile pour ControlHub ✅ À FAIRE

Créer le contrôleur et les jobs pour la gestion des AppProfiles via API, en miroir de `WorkstationGroupController`.

**Fichiers à créer** :

```
app/Http/Controllers/Api/v1/ControlHub/AppProfileController.php
app/Jobs/CreateAppProfileJob.php
app/Jobs/UpdateAppProfileJob.php
app/Jobs/DeleteAppProfileJob.php
```

**Routes à ajouter** dans `routes/api.php` :

```php
Route::prefix('app-profiles')->name('app-profile.')->group(function () {
    Route::post('/create', [AppProfileController::class, 'createAppProfile'])->name('create');
    Route::post('/update', [AppProfileController::class, 'updateAppProfile'])->name('update');
    Route::post('/delete', [AppProfileController::class, 'deleteAppProfile'])->name('delete');
});
```

**Endpoints** :

#### `POST /api/v1/app-profiles/create`

```json
{
    "task_id": "uuid",
    "task_name": "Création profil bureautique",
    "task_type": "create_app_profile",
    "payload": {
        "name": "profil-bureautique",
        "description": "LibreOffice + Firefox + VLC",
        "application_names": ["libreoffice", "firefox", "vlc"],
        "workstation_group_names": ["salle-b12"]
    }
}
```

Le job `CreateAppProfileJob` doit :
1. Créer l'AppProfile avec `locked = 'control_hub'` et `managed_by_control_hub = true`
2. Lier les applications par nom (résolution `application_names` → `application_ids`)
3. Lier les groupes par nom (résolution `workstation_group_names` → `workstation_group_ids`)
4. L'Observer gère la sync AD automatiquement

#### `POST /api/v1/app-profiles/update`

```json
{
    "task_id": "uuid",
    "task_name": "Mise à jour profil bureautique",
    "task_type": "update_app_profile",
    "payload": {
        "name": "profil-bureautique",
        "new_name": "profil-bureautique-v2",
        "description": "LibreOffice + Firefox + VLC + Thunderbird",
        "application_names": ["libreoffice", "firefox", "vlc", "thunderbird"],
        "workstation_group_names": ["salle-b12", "salle-c03"]
    }
}
```

> **Note** : `application_names` et `workstation_group_names` font un **sync** (remplacement complet), pas un ajout incrémental. Si le champ est absent du payload, la relation n'est pas modifiée.

#### `POST /api/v1/app-profiles/delete`

```json
{
    "task_id": "uuid",
    "task_name": "Suppression profil bureautique",
    "task_type": "delete_app_profile",
    "payload": {
        "name": "profil-bureautique"
    }
}
```

### Phase 3 — API de lecture (inventaire) ✅ À FAIRE

Le ControlHub a besoin de connaître l'état actuel d'une instance avant de dispatcher. Endpoints de lecture (GET) à ajouter :

```
GET /api/v1/workstation-groups          → Liste tous les groupes (arborescence)
GET /api/v1/workstation-groups/{name}   → Détail d'un groupe
GET /api/v1/app-profiles                → Liste tous les profils
GET /api/v1/app-profiles/{name}         → Détail d'un profil avec applications liées
GET /api/v1/applications                → Liste des applications disponibles (catalogue WPKG)
```

Ces endpoints permettent au ControlHub de :
- Vérifier si un groupe/profil existe déjà avant dispatch
- Afficher l'état de synchronisation par instance
- Résoudre les noms d'applications disponibles

### Phase 4 — Batch ordonné ✅ IMPLÉMENTÉ

Endpoint pour dispatcher une hiérarchie complète en une seule requête.
Les opérations sont exécutées **séquentiellement dans l'ordre fourni** au sein d'un seul job,
garantissant que les parents existent avant les enfants.

```
POST /api/v1/workstation-groups/bulk-create
```

#### Requête

```json
{
    "task_id": "550e8400-e29b-41d4-a716-446655440000",
    "task_name": "Dispatch hiérarchie bâtiment A",
    "task_type": "batch",
    "payload": {
        "stop_on_error": true,
        "operations": [
            {
                "type": "create_workstation_group",
                "data": {
                    "name": "batiment-a",
                    "is_physical": true,
                    "display_name": "Bâtiment A"
                }
            },
            {
                "type": "create_workstation_group",
                "data": {
                    "name": "etage-1",
                    "is_physical": true,
                    "display_name": "Étage 1",
                    "parent_name": "batiment-a"
                }
            },
            {
                "type": "create_workstation_group",
                "data": {
                    "name": "salle-101",
                    "is_physical": true,
                    "display_name": "Salle 101",
                    "parent_name": "etage-1",
                    "app_profile_name": "profil-bureautique"
                }
            },
            {
                "type": "create_workstation_group",
                "data": {
                    "name": "salle-102",
                    "is_physical": true,
                    "display_name": "Salle 102",
                    "parent_name": "etage-1",
                    "app_profile_name": "profil-labo-sciences"
                }
            }
        ]
    }
}
```

#### Types d'opérations supportés

| Type | Description | Champs `data` requis |
|---|---|---|
| `create_workstation_group` | Crée un groupe verrouillé `control_hub` | `name`, `is_physical` |
| `update_workstation_group` | Met à jour un groupe ControlHub | `name` |
| `delete_workstation_group` | Supprime un groupe ControlHub | `name` |

Chaque opération dans `data` accepte les mêmes champs que l'endpoint individuel correspondant (voir sections précédentes).

#### Option `stop_on_error`

| Valeur | Comportement |
|---|---|
| `true` (défaut) | Si une opération échoue, les suivantes sont **ignorées** (status `skipped`) |
| `false` | Toutes les opérations sont tentées même en cas d'erreur |

#### Réponse (acceptation)

```json
{
    "success": true,
    "message": "Batch task received and queued",
    "task_id": "uuid-local",
    "status": "queued",
    "operations_count": 4
}
```

#### Callback (résultat)

Le callback envoyé au ControlHub contient le détail de chaque opération :

```json
{
    "task_id": "550e8400-e29b-41d4-a716-446655440000",
    "status": "success",
    "result": {
        "operations": [
            {
                "index": 0,
                "type": "create_workstation_group",
                "status": "success",
                "result": {
                    "group_id": 1,
                    "group_name": "batiment-a",
                    "is_physical": true,
                    "locked": "control_hub",
                    "managed_by_control_hub": true
                }
            },
            {
                "index": 1,
                "type": "create_workstation_group",
                "status": "success",
                "result": { "group_id": 2, "group_name": "etage-1", "..." : "..." }
            },
            {
                "index": 2,
                "type": "create_workstation_group",
                "status": "success",
                "result": { "group_id": 3, "group_name": "salle-101", "..." : "..." }
            },
            {
                "index": 3,
                "type": "create_workstation_group",
                "status": "success",
                "result": { "group_id": 4, "group_name": "salle-102", "..." : "..." }
            }
        ],
        "summary": {
            "total": 4,
            "success": 4,
            "failed": 0,
            "skipped": 0
        },
        "message": "Batch exécuté avec succès : 4 opération(s)"
    }
}
```

#### Callback en cas d'erreur partielle (avec `stop_on_error: true`)

```json
{
    "task_id": "550e8400-e29b-41d4-a716-446655440000",
    "status": "failed",
    "error": "Batch partiellement échoué : 2 réussi(s), 1 échoué(s), 1 ignoré(s)",
    "result": {
        "operations": [
            { "index": 0, "type": "create_workstation_group", "status": "success", "result": { "..." : "..." } },
            { "index": 1, "type": "create_workstation_group", "status": "success", "result": { "..." : "..." } },
            { "index": 2, "type": "create_workstation_group", "status": "failed", "error": "Le groupe parent 'inexistant' n'existe pas" },
            { "index": 3, "type": "create_workstation_group", "status": "skipped", "message": "Ignorée suite à une erreur précédente" }
        ],
        "summary": { "total": 4, "success": 2, "failed": 1, "skipped": 1 }
    }
}
```

#### Limites

- **Maximum 100 opérations** par batch
- **Timeout** : 10 minutes (vs 4 minutes pour un job individuel)
- **Pas de rollback** : les opérations réussies sont conservées même en cas d'erreur
- **Un seul callback** pour tout le batch

#### Cas d'usage typique : dispatch d'une hiérarchie complète

```
1. POST /api/v1/workstation-groups/bulk-create  →  Crée toute l'arborescence en une requête
2. Attendre le callback  →  Un seul callback avec le résultat de toutes les opérations
3. Vérifier le summary  →  Si tout est success, la hiérarchie est en place
```

---

## Récapitulatif des migrations SE4FS

| Migration | Table | Champs | Statut |
|---|---|---|---|
| `add_locked_to_workstation_groups_table` | `workstation_groups` | `locked` | ✅ Existante |
| (dans unified_schema) | `workstation_groups` | `managed_by_control_hub` | ✅ Existante |
| **À créer** | `app_profiles` | `locked`, `managed_by_control_hub` | ❌ À faire |

## Récapitulatif des fichiers SE4FS

### Existants ✅

| Fichier | Rôle |
|---|---|
| `app/Http/Controllers/Api/v1/ControlHub/WorkstationGroupController.php` | API CRUD WorkstationGroup |
| `app/Jobs/CreateWorkstationGroupJob.php` | Création avec lock `control_hub` |
| `app/Jobs/UpdateWorkstationGroupJob.php` | Mise à jour (bypass lock car propriétaire) |
| `app/Jobs/DeleteWorkstationGroupJob.php` | Suppression (unlock + delete) |
| `app/Models/WorkstationGroup.php` | Modèle avec `locked`, `managed_by_control_hub` |
| `app/Observers/WorkstationGroupObserver.php` | Sync AD auto + AppProfile auto |
| `app/Services/Parc/WorkstationGroupService.php` | Logique métier groupes |
| `app/Jobs/BulkCreateWorkstationGroupsJob.php` | Création en masse d'un arbre de WorkstationGroups |

### À créer ❌

| Fichier | Rôle |
|---|---|
| `app/Http/Controllers/Api/v1/ControlHub/AppProfileController.php` | API CRUD AppProfile |
| `app/Jobs/CreateAppProfileJob.php` | Création avec lock `control_hub` |
| `app/Jobs/UpdateAppProfileJob.php` | Mise à jour (bypass lock) |
| `app/Jobs/DeleteAppProfileJob.php` | Suppression (unlock + delete) |
| `database/migrations/xxxx_add_locked_to_app_profiles.php` | Migration `locked` + `managed_by_control_hub` |
| `app/Http/Controllers/Api/v1/ControlHub/InventoryController.php` | Endpoints GET de lecture |

### À modifier ✏️

| Fichier | Modification |
|---|---|
| `app/Models/AppProfile.php` | Ajouter `locked`, `managed_by_control_hub` dans `$fillable` et `$casts`. Ajouter méthodes `isLocked()`, `getLockReason()`. |
| `app/Services/AppProfile/AppProfileService.php` | Ajouter vérification `isLocked()` dans `updateProfile()` et `deleteProfile()` pour empêcher la modification locale des profils ControlHub. |
| `routes/api.php` | Ajouter les routes `app-profiles/*` et les routes GET d'inventaire. |

---

## Flux de dispatch complet

```
┌──────────────┐                              ┌──────────────┐
│  ControlHub  │                              │   SE4FS #1   │
│  (central)   │                              │  (instance)  │
├──────────────┤                              ├──────────────┤
│              │  POST /workstation-groups/    │              │
│  1. Admin    │  create                      │  2. Reçoit   │
│  crée un     │ ──────────────────────────►  │  la tâche    │
│  groupe      │                              │              │
│              │                              │  3. Job crée │
│              │                              │  le groupe   │
│              │                              │  locked=     │
│              │                              │  control_hub │
│              │                              │              │
│              │  POST /app-profiles/create   │  4. Observer │
│  5. Admin    │ ──────────────────────────►  │  sync AD     │
│  crée un     │                              │              │
│  profil      │                              │  6. Job crée │
│              │                              │  le profil   │
│              │  Callback task-result        │  locked=     │
│  7. Reçoit   │ ◄──────────────────────────  │  control_hub │
│  les résul-  │                              │              │
│  tats        │                              │              │
└──────────────┘                              └──────────────┘
         │
         │  Même dispatch vers
         ▼
┌──────────────┐
│   SE4FS #2   │
│  (instance)  │
└──────────────┘
```

---

## Ordre d'implémentation recommandé

### Côté SE4FS (cette application)

1. **Migration** : Ajouter `locked` + `managed_by_control_hub` à `app_profiles`
2. **Modèle** : Mettre à jour `AppProfile.php`
3. **Service** : Ajouter les gardes `isLocked()` dans `AppProfileService`
4. **API AppProfile** : Contrôleur + Jobs (create/update/delete)
5. **API Inventaire** : Endpoints GET pour lecture
6. **API Batch** : Endpoint batch (optionnel)

### Côté ControlHub (application externe)

1. **BDD** : Créer les tables `ch_workstation_groups`, `ch_app_profiles`, `ch_dispatch_targets`
2. **Pages CRUD** : Groupes (arborescence) + Profils (liste)
3. **Dispatch** : Logique d'envoi vers les instances SE4FS via les API documentées ci-dessus
4. **Suivi** : Réception des callbacks + affichage des statuts par instance
5. **Batch** : Dispatch multi-entités / multi-instances

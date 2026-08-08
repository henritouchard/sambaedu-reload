# API SE4FS — WorkstationGroups pour ControlHub

> **Document d'instructions pour l'intégration côté ControlHub.**
> Décrit les endpoints, formats d'entrée/sortie et règles métier.

## Authentification

Toutes les requêtes doivent inclure le header :

```
Authorization: Bearer <SE4FS_INSTANCE_API_KEY>
```

Sans ce header → **403 Forbidden**.

---

## Endpoints

| Méthode | URL | Description |
|---------|-----|-------------|
| POST | `/api/v1/workstation-groups/batch` | Créer/mettre à jour un arbre de groupes |
| POST | `/api/v1/workstation-groups/create` | Créer un seul groupe (legacy) |
| POST | `/api/v1/workstation-groups/update` | Mettre à jour un seul groupe (legacy) |
| POST | `/api/v1/workstation-groups/delete` | Supprimer un groupe |

> **Recommandé** : utiliser `/batch` pour toutes les créations/mises à jour.
> Les endpoints `/create` et `/update` restent disponibles mais `/batch` les remplace.

---

## 1. BATCH — Créer/mettre à jour un arbre de groupes

### `POST /api/v1/workstation-groups/batch`

C'est l'endpoint principal. Il reçoit un arbre de groupes avec `children` récursifs et applique une logique d'**upsert** :

- **`controlhub_id` absent ou `null`** → le groupe est **créé** (avec `locked=control_hub`, `managed_by_control_hub=true`)
- **`controlhub_id` présent** → le groupe existant est **mis à jour** (lookup par `controlhub_id`, seuls les groupes ControlHub sont modifiables)

Le `controlhub_id` est un **UUID universel généré par le ControlHub**, identique sur toutes les instances SE4. Il remplace l'`id` local (auto-incrémenté) qui est propre à chaque instance.

L'arbre est parcouru en **profondeur** (depth-first) : chaque parent est traité avant ses enfants. Le `parent_id` des enfants est automatiquement résolu.

### Input

```json
{
    "task_id": "95846da5-01aa-400e-8ccc-448d0eaba5bb",
    "task_name": "Parc : groupeindependant",
    "task_type": "batch_workstation_group",
    "payload": {
        "groups": [
            {
                "controlhub_id": "a1b2c3d4-e5f6-7890-abcd-ef1234567890",
                "name": "indepgroup",
                "is_physical": true,
                "display_name": "groupeindependant",
                "description": "Description optionnelle",
                "app_profile_name": null,
                "parent_id": null,
                "children": []
            }
        ]
    },
    "scheduled_at": null
}
```

### Champs de la requête

| Champ | Type | Requis | Description |
|-------|------|--------|-------------|
| `task_id` | UUID (string) | ✅ | Identifiant unique de la tâche côté ControlHub. Sert à l'idempotence. |
| `task_name` | string (max 255) | ✅ | Nom lisible de la tâche |
| `task_type` | string | ✅ | **Doit être exactement `"batch_workstation_group"`** |
| `payload.groups` | array | ✅ | Tableau de groupes racines (min 1, max 100) |
| `scheduled_at` | datetime \| null | ❌ | Planification différée (null = immédiat) |

### Champs de chaque groupe dans `groups[]`

| Champ | Type | Requis | Description |
|-------|------|--------|-------------|
| `controlhub_id` | UUID \| null | ❌ | UUID universel généré par le ControlHub. `null` = création, valeur = mise à jour (lookup par ce champ). Identique sur toutes les instances. |
| `name` | string (max 255) | ✅ | Identifiant unique du groupe (slug). Doit être unique dans l'instance. |
| `is_physical` | boolean | ✅ | `true` = salle physique (OU dans AD/Computers), `false` = groupe logique (CN dans AD/Parcs) |
| `display_name` | string \| null | ❌ | Nom d'affichage lisible |
| `description` | string \| null | ❌ | Description du groupe |
| `app_profile_name` | string \| null | ❌ | Nom du profil applicatif (WPKG) à associer |
| `parent_id` | int \| null | ❌ | ID local du groupe parent existant dans l'instance. `null` = racine. **Ignoré pour les enfants dans `children[]`** (résolu automatiquement). |
| `children` | array | ❌ | Sous-groupes récursifs (même structure). Défaut : `[]` |

### Output — Succès (200)

```json
{
    "success": true,
    "message": "Batch task received and queued",
    "task_id": "a1b2c3d4-...",
    "status": "queued",
    "total_groups": 3
}
```

| Champ | Type | Description |
|-------|------|-------------|
| `success` | boolean | `true` si la tâche a été acceptée |
| `task_id` | UUID | ID interne de la tâche dans l'instance SE4 |
| `status` | string | Statut initial : `"queued"` |
| `total_groups` | int | Nombre total de groupes dans l'arbre (récursif) |

### Output — Idempotence (200)

Si le même `task_id` est renvoyé :

```json
{
    "success": true,
    "message": "Task already received",
    "task_id": "a1b2c3d4-...",
    "status": "queued"
}
```

### Output — Erreur de validation (422)

```json
{
    "success": false,
    "message": "Validation failed",
    "errors": {
        "task_type": ["The selected task type is invalid."]
    }
}
```

### Output — Erreur de validation des groupes (422)

```json
{
    "success": false,
    "message": "Validation failed for batch groups",
    "errors": [
        "groups[0]: le champ 'name' est requis",
        "groups[1].children[0]: aucun groupe avec controlhub_id=xxx n'existe"
    ]
}
```

---

## Callback (résultat asynchrone)

Le job s'exécute en arrière-plan. Le résultat est renvoyé via callback au ControlHub.

### Callback — Succès

```json
{
    "task_id": "95846da5-01aa-400e-8ccc-448d0eaba5bb",
    "status": "success",
    "result": {
        "groups": [
            {
                "name": "indepgroup",
                "action": "created",
                "status": "success",
                "controlhub_id": "a1b2c3d4-e5f6-7890-abcd-ef1234567890",
                "group_id": 42,
                "group_name": "indepgroup"
            },
            {
                "name": "salle-101",
                "action": "created",
                "status": "success",
                "controlhub_id": "b2c3d4e5-f6a7-8901-bcde-f12345678901",
                "group_id": 43,
                "group_name": "salle-101"
            }
        ],
        "summary": {
            "total": 2,
            "success": 2,
            "failed": 0
        },
        "message": "Batch exécuté avec succès : 2 groupe(s) traité(s)"
    }
}
```

### Callback — Erreur partielle

```json
{
    "task_id": "95846da5-01aa-400e-8ccc-448d0eaba5bb",
    "status": "failed",
    "error_message": "Batch partiellement échoué : 1 réussi(s), 1 échoué(s)",
    "result": {
        "groups": [
            {
                "name": "indepgroup",
                "action": "created",
                "status": "success",
                "controlhub_id": "a1b2c3d4-e5f6-7890-abcd-ef1234567890",
                "group_id": 42,
                "group_name": "indepgroup"
            },
            {
                "name": "doublon",
                "action": "create",
                "status": "failed",
                "error": "Un groupe avec le nom 'doublon' existe déjà (id: 42)"
            }
        ],
        "summary": {
            "total": 2,
            "success": 1,
            "failed": 1
        }
    }
}
```

### Champs du callback par groupe

| Champ | Type | Description |
|-------|------|-------------|
| `name` | string | Nom du groupe traité |
| `action` | string | `"created"` ou `"updated"` |
| `status` | string | `"success"` ou `"failed"` |
| `controlhub_id` | UUID | UUID universel du groupe (uniquement si success) |
| `group_id` | int | ID local du groupe dans l'instance SE4 (uniquement si success) |
| `group_name` | string | Nom final du groupe (uniquement si success) |
| `error` | string | Message d'erreur (uniquement si failed) |

---

## 2. DELETE — Supprimer un groupe

### `POST /api/v1/workstation-groups/delete`

Supprime un groupe **uniquement s'il est géré par le ControlHub** (`locked=control_hub`).

### Input

```json
{
    "task_id": "d4e5f6a7-...",
    "task_name": "Suppression groupe salle-101",
    "task_type": "delete_workstation_group",
    "payload": {
        "name": "salle-101"
    }
}
```

### Output — Succès (200)

```json
{
    "success": true,
    "message": "Task received and queued",
    "task_id": "b1c2d3e4-...",
    "status": "queued"
}
```

### Output — Groupe non trouvé (404)

```json
{
    "success": false,
    "message": "Groupe non trouvé",
    "error": "Le groupe 'salle-101' n'existe pas"
}
```

### Output — Groupe non géré par ControlHub (403)

```json
{
    "success": false,
    "message": "Suppression refusée",
    "error": "Le groupe 'salle-101' n'est pas géré par le ControlHub. Seuls les groupes créés par le ControlHub peuvent être supprimés via cette API."
}
```

---

## Exemples d'utilisation

### Exemple 1 : Créer un groupe simple

```json
POST /api/v1/workstation-groups/batch
{
    "task_id": "11111111-1111-1111-1111-111111111111",
    "task_name": "Parc : Salle Info 1",
    "task_type": "batch_workstation_group",
    "payload": {
        "groups": [
            {
                "name": "salle-info-1",
                "is_physical": true,
                "display_name": "Salle Info 1"
            }
        ]
    }
}
```

### Exemple 2 : Créer un groupe avec des enfants (hiérarchie)

```json
POST /api/v1/workstation-groups/batch
{
    "task_id": "22222222-2222-2222-2222-222222222222",
    "task_name": "Parc : Bâtiment A complet",
    "task_type": "batch_workstation_group",
    "payload": {
        "groups": [
            {
                "name": "batiment-a",
                "is_physical": true,
                "display_name": "Bâtiment A",
                "children": [
                    {
                        "name": "salle-a1",
                        "is_physical": true,
                        "display_name": "Salle A1"
                    },
                    {
                        "name": "salle-a2",
                        "is_physical": true,
                        "display_name": "Salle A2",
                        "children": [
                            {
                                "name": "salle-a2-labo",
                                "is_physical": true,
                                "display_name": "Labo A2"
                            }
                        ]
                    }
                ]
            }
        ]
    }
}
```

### Exemple 3 : Mettre à jour un groupe existant (upsert)

Le groupe avec `controlhub_id` existe déjà dans l'instance. On met à jour son `display_name` et on lui ajoute un enfant.

```json
POST /api/v1/workstation-groups/batch
{
    "task_id": "33333333-3333-3333-3333-333333333333",
    "task_name": "Mise à jour Bâtiment A",
    "task_type": "batch_workstation_group",
    "payload": {
        "groups": [
            {
                "controlhub_id": "550e8400-e29b-41d4-a716-446655440000",
                "name": "batiment-a",
                "is_physical": true,
                "display_name": "Bâtiment A (rénové)",
                "children": [
                    {
                        "controlhub_id": "6ba7b810-9dad-11d1-80b4-00c04fd430c8",
                        "name": "salle-a3",
                        "is_physical": true,
                        "display_name": "Salle A3 (nouvelle)"
                    }
                ]
            }
        ]
    }
}
```

### Exemple 4 : Rattacher à un groupe existant via parent_id

Le groupe parent existe déjà dans l'instance (id local = 10). On crée un enfant rattaché à ce parent.

```json
POST /api/v1/workstation-groups/batch
{
    "task_id": "44444444-4444-4444-4444-444444444444",
    "task_name": "Ajout salle sous groupe existant",
    "task_type": "batch_workstation_group",
    "payload": {
        "groups": [
            {
                "controlhub_id": "c3d4e5f6-a7b8-9012-cdef-123456789012",
                "name": "salle-orpheline",
                "is_physical": true,
                "display_name": "Salle Orpheline",
                "parent_id": 10
            }
        ]
    }
}
```

### Exemple 5 : Supprimer un groupe

```json
POST /api/v1/workstation-groups/delete
{
    "task_id": "55555555-5555-5555-5555-555555555555",
    "task_name": "Suppression Salle A1",
    "task_type": "delete_workstation_group",
    "payload": {
        "name": "salle-a1"
    }
}
```

---

## Règles métier importantes

1. **Verrouillage** : Tous les groupes créés via cette API sont automatiquement verrouillés avec `locked=control_hub`. Ils ne peuvent être modifiés/supprimés que via cette API.

2. **Upsert via `controlhub_id`** : Si `controlhub_id` est fourni, le groupe est recherché par ce champ et mis à jour. Si `controlhub_id` est absent ou `null`, le groupe est créé. Seuls les groupes avec `locked=control_hub` peuvent être mis à jour. Le `controlhub_id` est un UUID universel identique sur toutes les instances SE4.

3. **Idempotence** : Renvoyer le même `task_id` retourne 200 avec `"Task already received"` sans créer de doublon.

4. **Noms uniques** : Le champ `name` doit être unique dans l'instance. Tenter de créer un groupe avec un nom existant provoque une erreur.

5. **Children récursifs** : Le `parent_id` des enfants dans `children[]` est automatiquement résolu. Ne pas fournir `parent_id` pour les enfants — il sera ignoré au profit du parent résolu par la récursion.

6. **Traitement asynchrone** : La réponse 200 signifie que la tâche est **acceptée et mise en queue**. Le résultat réel est envoyé via callback.

7. **Suppression séparée** : La suppression utilise un endpoint dédié (`/delete`), pas le batch.

8. **`controlhub_id` universel** : Le `controlhub_id` est généré par le ControlHub et partagé entre toutes les instances SE4. L'`id` local (auto-incrémenté) est propre à chaque instance et ne doit pas être utilisé pour référencer un groupe entre instances. Le même mécanisme est prévu pour les `shortcuts` et `app_profiles`.

---

## Statuts possibles d'une tâche

| Statut | Description |
|--------|-------------|
| `received` | Tâche reçue par l'instance |
| `queued` | Tâche mise en queue pour exécution |
| `in_progress` | Tâche en cours d'exécution |
| `success` | Tâche terminée avec succès |
| `failed` | Tâche échouée (voir `error_message` et `result`) |
| `canceled` | Tâche annulée |

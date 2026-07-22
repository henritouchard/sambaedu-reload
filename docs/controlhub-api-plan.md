# Plan des APIs SE4FS pour le ControlHub

## Authentification

Toutes les requêtes utilisent le header :

```
Authorization: Bearer {se4fs_api_token}
```

Token obtenu lors du handshake initial.

---

## 1. Snapshot (inventaire léger)

Deux modes disponibles :

### Mode synchrone : `GET /api/v1/snapshot`

Retourne immédiatement l'état de toutes les entités ControlHub sur l'instance.

**Réponse :**

```json
{
  "instance_id": "se4fs_xxx",
  "snapshot_at": "2026-02-11T12:55:00Z",
  "snapshot_hash": "a1b2c3d4e5f6...",
  "hashes": {
    "app_profiles": "f1e2d3c4b5a6...",
    "shortcuts": "1a2b3c4d5e6f...",
    "workstation_groups": "6f5e4d3c2b1a..."
  },
  "shortcuts": {
    "uuid-shortcut-1": "2026-02-10T18:00:00Z",
    "uuid-shortcut-2": "2026-02-11T09:30:00Z"
  },
  "app_profiles": {
    "uuid-profile-1": "2026-02-10T18:00:00Z"
  },
  "workstation_groups": {
    "uuid-group-1": "2026-02-11T12:00:00Z",
    "uuid-group-2": "2026-02-10T18:00:00Z"
  }
}
```

### Mode asynchrone : `POST /api/v1/snapshot`

Dispatch un job qui calcule le snapshot et envoie le résultat via callback
au ControlHub (endpoint `/api/sambaedu/task-result/{instance_id}`).

**Requête :**

```json
{
  "task_id": "uuid-task",
  "task_name": "Snapshot instance XYZ",
  "task_type": "snapshot"
}
```

**Réponse immédiate (202-like) :**

```json
{
  "success": true,
  "message": "Snapshot task received and queued",
  "task_id": 42,
  "status": "queued"
}
```

**Callback envoyé au ControlHub :** même structure que la réponse synchrone,
enrichie des métadonnées standard (`executed_at`, `instance_id`, `job_name`).

### Logique de comparaison côté ControlHub

| Cas | Signification | Action |
|-----|---------------|--------|
| Timestamps identiques | Entité à jour | Rien |
| Timestamps différents | Entité divergente | CRUD update ou inspection GET |
| `controlhub_id` absent côté instance | Entité manquante | CRUD create |
| `controlhub_id` absent côté ControlHub | Entité orpheline | CRUD delete |

---

## 2. GET entités (inspection détaillée)

Permet d'inspecter le détail d'une entité divergente détectée via le snapshot.
Les relations retournées incluent uniquement les entités ControlHub (ayant un `controlhub_id`).

### `GET /api/v1/shortcuts/{controlhub_id}`

**Réponse :**

```json
{
  "success": true,
  "data": {
    "controlhub_id": "uuid-shortcut-1",
    "controlhub_version": "2026-02-10T18:00:00Z",
    "name": "LibreOffice Writer",
    "owner": "Profs,Eleves",
    "place": "desktop",
    "windows_link": "C:\\Program Files\\LibreOffice\\program\\swriter.exe",
    "windows_args": "",
    "windows_path": "",
    "windows_icon": "shortcuts/icons/libreoffice-writer.png",
    "linux_link": "/usr/bin/libreoffice",
    "linux_args": "--writer",
    "linux_path": "",
    "linux_icon": "shortcuts/icons/libreoffice-writer_linux.png",
    "linux_startupwmclass": "libreoffice-writer",
    "workstation_groups": [
      { "controlhub_id": "uuid-group-1", "name": "salle-101" }
    ]
  }
}
```

### `GET /api/v1/workstation-groups/{controlhub_id}`

**Réponse :**

```json
{
  "success": true,
  "data": {
    "controlhub_id": "uuid-group-1",
    "controlhub_version": "2026-02-11T12:00:00Z",
    "name": "salle-101",
    "display_name": "Salle 101",
    "description": "Salle informatique 101",
    "is_physical": true,
    "is_active": true,
    "parent_controlhub_id": "uuid-group-parent",
    "app_profile_name": "profil-bureautique",
    "shortcuts": [
      { "controlhub_id": "uuid-shortcut-1", "name": "LibreOffice Writer" },
      { "controlhub_id": "uuid-shortcut-2", "name": "Firefox" }
    ],
    "app_profiles": [
      { "controlhub_id": "uuid-profile-1", "name": "profil-bureautique" }
    ]
  }
}
```

### `GET /api/v1/app-profiles/{controlhub_id}`

> Un profil applicatif SE5 ne porte que `name` et `description` : les colonnes
> `display_name` et `is_active` ont été supprimées. `is_active` ne conditionnait
> aucun déploiement (le resolver ne filtre que `archived_at`) — un profil qui ne
> doit plus rien produire se supprime. Ces deux clés envoyées par le ControlHub
> dans un payload entrant sont simplement ignorées (aucune erreur de validation).

**Réponse :**

```json
{
  "success": true,
  "data": {
    "controlhub_id": "uuid-profile-1",
    "controlhub_version": "2026-02-10T18:00:00Z",
    "name": "profil-bureautique",
    "description": "Applications bureautiques de base",
    "applications": [
      { "app_id": "libreoffice", "name": "LibreOffice" },
      { "app_id": "firefox-esr", "name": "Firefox ESR" }
    ],
    "workstation_groups": [
      { "controlhub_id": "uuid-group-1", "name": "salle-101" }
    ]
  }
}
```

### Erreur 404

```json
{
  "success": false,
  "message": "Entité non trouvée",
  "controlhub_id": "uuid-inexistant"
}
```

---

## 3. CRUD unitaire (opérations ponctuelles)

Endpoints existants, conservés. Tous les payloads doivent désormais inclure `controlhub_version`.

**Réponse synchrone commune :**

```json
{
  "success": true,
  "message": "Task received and queued",
  "task_id": "local-uuid",
  "status": "queued"
}
```

**Callback asynchrone** : l'instance envoie le résultat vers `POST /api/sambaedu/task-result/{instance_id}` sur le ControlHub.

### Shortcuts

#### `POST /api/v1/shortcuts/create`

```json
{
  "task_id": "uuid-task",
  "task_name": "create_shortcut",
  "task_type": "create_shortcut",
  "payload": {
    "controlhub_id": "uuid-shortcut-1",
    "controlhub_version": "2026-02-11T13:00:00Z",
    "name": "LibreOffice Writer",
    "owner": "Profs,Eleves",
    "place": "desktop",
    "windows": {
      "link": "C:\\Program Files\\LibreOffice\\program\\swriter.exe",
      "args": "",
      "path": "",
      "icon": { "data": "base64...", "mime": "image/png" }
    },
    "linux": {
      "link": "/usr/bin/libreoffice",
      "args": "--writer",
      "path": "",
      "startupwmclass": "libreoffice-writer",
      "icon": { "data": "base64...", "mime": "image/png" }
    },
    "workstation_groups": [
      { "controlhub_id": "uuid-group-1" }
    ]
  }
}
```

#### `POST /api/v1/shortcuts/update`

```json
{
  "task_id": "uuid-task",
  "task_name": "update_shortcut",
  "task_type": "update_shortcut",
  "payload": {
    "controlhub_id": "uuid-shortcut-1",
    "controlhub_version": "2026-02-11T14:00:00Z",
    "name": "LibreOffice Writer 2",
    "workstation_groups": [
      { "controlhub_id": "uuid-group-1" },
      { "controlhub_id": "uuid-group-2" }
    ]
  }
}
```

#### `POST /api/v1/shortcuts/delete`

```json
{
  "task_id": "uuid-task",
  "task_name": "delete_shortcut",
  "task_type": "delete_shortcut",
  "payload": {
    "controlhub_id": "uuid-shortcut-1",
    "name": "LibreOffice Writer"
  }
}
```

### Workstation Groups

#### `POST /api/v1/workstation-groups/create`

```json
{
  "task_id": "uuid-task",
  "task_name": "create_workstation_group",
  "task_type": "create_workstation_group",
  "payload": {
    "name": "salle-101",
    "controlhub_version": "2026-02-11T13:00:00Z",
    "is_physical": true,
    "display_name": "Salle 101",
    "description": "Salle informatique 101",
    "parent_name": "batiment-a",
    "shortcuts": ["uuid-shortcut-1", "uuid-shortcut-2"],
    "app_profiles": ["uuid-profile-1"]
  }
}
```

#### `POST /api/v1/workstation-groups/update`

```json
{
  "task_id": "uuid-task",
  "task_name": "update_workstation_group",
  "task_type": "update_workstation_group",
  "payload": {
    "name": "salle-101",
    "controlhub_version": "2026-02-11T14:00:00Z",
    "new_name": "salle-101-renamed",
    "display_name": "Salle 101 (renommée)",
    "shortcuts": ["uuid-shortcut-1"],
    "app_profiles": ["uuid-profile-1", "uuid-profile-2"]
  }
}
```

#### `POST /api/v1/workstation-groups/delete`

```json
{
  "task_id": "uuid-task",
  "task_name": "delete_workstation_group",
  "task_type": "delete_workstation_group",
  "payload": {
    "name": "salle-101"
  }
}
```

---

## 4. Sync Manifest (convergence complète)

### `POST /api/v1/sync-manifest`

Envoie l'état souhaité complet. L'instance converge en 3 passes :

1. **Pass 1 — Upsert entités** : Créer/mettre à jour shortcuts, app_profiles, workstation_groups SANS relations
2. **Pass 2 — Résolution relations** : shortcuts↔groups, groups↔appProfiles, groups↔parent, appProfiles↔applications (soft)
3. **Pass 3 — Nettoyage** : Supprimer les entités ControlHub absentes du manifeste

**Payload :**

```json
{
  "task_id": "uuid-task-manifest",
  "task_name": "sync_manifest",
  "task_type": "sync_manifest",
  "manifest_version": "2026-02-11T13:00:00Z",
  "payload": {
    "shortcuts": [
      {
        "controlhub_id": "uuid-shortcut-1",
        "controlhub_version": "2026-02-11T13:00:00Z",
        "name": "LibreOffice Writer",
        "owner": "Profs,Eleves",
        "place": "desktop",
        "windows": {
          "link": "C:\\Program Files\\LibreOffice\\program\\swriter.exe",
          "icon": { "data": "base64...", "mime": "image/png" }
        },
        "linux": {
          "link": "/usr/bin/libreoffice",
          "args": "--writer",
          "startupwmclass": "libreoffice-writer"
        }
      }
    ],
    "app_profiles": [
      {
        "controlhub_id": "uuid-profile-1",
        "controlhub_version": "2026-02-11T13:00:00Z",
        "name": "profil-bureautique",
        "description": "Applications bureautiques de base",
        "applications": [
          { "app_id": "libreoffice" },
          { "app_id": "firefox-esr" }
        ]
      }
    ],
    "workstation_groups": [
      {
        "controlhub_id": "uuid-group-parent",
        "controlhub_version": "2026-02-11T13:00:00Z",
        "name": "batiment-a",
        "display_name": "Bâtiment A",
        "is_physical": true,
        "parent_controlhub_id": null,
        "shortcuts": [],
        "app_profiles": []
      },
      {
        "controlhub_id": "uuid-group-1",
        "controlhub_version": "2026-02-11T13:00:00Z",
        "name": "salle-101",
        "display_name": "Salle 101",
        "is_physical": true,
        "parent_controlhub_id": "uuid-group-parent",
        "shortcuts": [
          { "controlhub_id": "uuid-shortcut-1" }
        ],
        "app_profiles": [
          { "controlhub_id": "uuid-profile-1" }
        ]
      }
    ]
  }
}
```

**Réponse synchrone :**

```json
{
  "success": true,
  "message": "Task received and queued",
  "task_id": "local-uuid",
  "status": "queued"
}
```

**Callback asynchrone** (envoyé par l'instance vers `POST /api/sambaedu/task-result/{instance_id}`) :

```json
{
  "task_id": "uuid-task-manifest",
  "status": "success",
  "result": {
    "manifest_version": "2026-02-11T13:00:00Z",
    "pass1_entities": {
      "shortcuts":          { "created": 1, "updated": 0, "unchanged": 0 },
      "app_profiles":       { "created": 1, "updated": 0, "unchanged": 0 },
      "workstation_groups": { "created": 2, "updated": 0, "unchanged": 0 }
    },
    "pass2_relations": {
      "shortcuts_to_groups":          { "attached": 1, "detached": 0 },
      "groups_to_app_profiles":       { "attached": 1, "detached": 0 },
      "groups_parent_resolved":       1,
      "app_profiles_to_applications": { "resolved": 2, "missing": 0 }
    },
    "pass3_cleanup": {
      "shortcuts_deleted": 0,
      "app_profiles_deleted": 0,
      "workstation_groups_deleted": 0
    },
    "warnings": [],
    "executed_at": "2026-02-11T13:00:05Z"
  },
  "error": null,
  "completed_at": "2026-02-11T13:00:05Z"
}
```

---

## 5. Résumé des endpoints

| Méthode | Endpoint | Usage |
|---------|----------|-------|
| `GET` | `/api/v1/snapshot` | Inventaire léger (map controlhub_id → timestamp) |
| `GET` | `/api/v1/shortcuts/{controlhub_id}` | Détail shortcut + relations |
| `GET` | `/api/v1/workstation-groups/{controlhub_id}` | Détail groupe + relations |
| `GET` | `/api/v1/app-profiles/{controlhub_id}` | Détail profil + relations |
| `POST` | `/api/v1/shortcuts/create` | Créer un shortcut |
| `POST` | `/api/v1/shortcuts/update` | Modifier un shortcut |
| `POST` | `/api/v1/shortcuts/delete` | Supprimer un shortcut |
| `POST` | `/api/v1/workstation-groups/create` | Créer un groupe |
| `POST` | `/api/v1/workstation-groups/update` | Modifier un groupe |
| `POST` | `/api/v1/workstation-groups/delete` | Supprimer un groupe |
| `POST` | `/api/v1/sync-manifest` | Convergence complète |

---

## 6. Flux recommandé côté ControlHub

```
1. GET /api/v1/snapshot
   → Comparer snapshot_hash

2. Hash global identique → instance 100% à jour, rien à faire

3. Hash global différent → comparer hashes par type
   → Identifier le(s) type(s) divergent(s)

4. Hash type différent → parcourir la map du type concerné
   → Identifier les entités divergentes par controlhub_version

5. Selon le résultat :
   a) Quelques divergences :
      → GET /api/v1/{type}/{controlhub_id} pour inspecter
      → POST /api/v1/{type}/update pour corriger
   b) Beaucoup de divergences / instance neuve / reconnexion :
      → POST /api/v1/sync-manifest (convergence complète)
```

---

## 7. Contrat `controlhub_version`

- Le ControlHub **bumpe** `controlhub_version` à chaque mutation d'une entité (champs **ou** relations)
- L'instance stocke ce timestamp tel quel et le retourne dans le snapshot
- C'est la **seule source de vérité** pour la comparaison de versions
- Format : ISO 8601 (`YYYY-MM-DDTHH:MM:SSZ`)

## 8. Résolution des relations

### Relations soft (pas d'erreur bloquante)

- Si un `controlhub_id` référencé dans une relation n'existe pas sur l'instance → **warning** dans le callback, relation ignorée
- Si un `app_id` d'application n'existe pas sur l'instance → **warning**, application ignorée
- Si un `parent_controlhub_id` référence un groupe absent → **warning**, `parent_id` mis à null

### Idempotence

- Renvoyer le même payload CRUD ou sync-manifest = même résultat
- Le `task_id` (UUID) identifie chaque envoi. Si le même `task_id` est renvoyé, l'instance retourne le statut existant sans ré-exécuter

---

## 9. Hash déterministe pour comparaison rapide

Pour éviter de parcourir toutes les maps du snapshot quand l'instance est déjà à jour, un **hash déterministe** est calculé des deux côtés (ControlHub et instance SE4FS) avec le même algorithme.

### Algorithme

1. Pour chaque type d'entité (`app_profiles`, `shortcuts`, `workstation_groups` — ordre alphabétique) :
   - Collecter les paires `controlhub_id → controlhub_version`
   - **Trier par `controlhub_id`** (ordre alphabétique)
   - Concaténer : `{type}:{controlhub_id}:{timestamp}` séparés par `|`
   - Hasher (SHA-256) → **hash par type**
2. Concaténer les 3 hashs par type (dans l'ordre alphabétique des types) séparés par `|`
3. Hasher (SHA-256) → **hash global** (`snapshot_hash`)

### Exemple

```
// Entrées triées
app_profiles:uuid-profile-1:2026-02-10T18:00:00Z
shortcuts:uuid-shortcut-1:2026-02-10T18:00:00Z|shortcuts:uuid-shortcut-2:2026-02-11T09:30:00Z
workstation_groups:uuid-group-1:2026-02-11T12:00:00Z|workstation_groups:uuid-group-2:2026-02-10T18:00:00Z

// Hash par type
hashes.app_profiles       = sha256("app_profiles:uuid-profile-1:2026-02-10T18:00:00Z")
hashes.shortcuts          = sha256("shortcuts:uuid-shortcut-1:....|shortcuts:uuid-shortcut-2:....")
hashes.workstation_groups = sha256("workstation_groups:uuid-group-1:....|workstation_groups:uuid-group-2:....")

// Hash global
snapshot_hash = sha256(hashes.app_profiles | hashes.shortcuts | hashes.workstation_groups)
```

### Contraintes

- **Algorithme identique** des deux côtés (même tri, même séparateur `|`, même format timestamp)
- **Timestamps normalisés** : toujours `YYYY-MM-DDTHH:MM:SSZ` (pas de microsecondes, toujours UTC)
- **Tri par `controlhub_id`** : déterministe et indépendant de l'ordre d'insertion en DB
